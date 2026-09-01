<?php
/**
 * Shared bounded crawler for proactively discovering translatable content.
 *
 * Product adapters may add metadata keys through the documented filters, but
 * request validation, scheduling, locking, and queue safety live here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GML_Translation_Content_Crawler {
	const CRON_HOOK              = 'gml_crawl_content';
	const INCREMENTAL_CRON_HOOK  = 'gml_discover_changed_content';
	const DIRTY_POSTS_OPTION     = 'gml_translation_dirty_posts';
	const BATCH_SIZE             = 5;
	const INCREMENTAL_BATCH_SIZE = 2;
	const MAX_DIRTY_POSTS        = 200;
	const LOCK_OPTION            = 'gml_translation_crawl_lock';

	public function __construct() {
		add_action( static::CRON_HOOK, [ $this, 'crawl_batch' ] );
		add_action( static::INCREMENTAL_CRON_HOOK, [ $this, 'discover_changed_content' ] );
		add_filter( 'cron_schedules', [ static::class, 'add_schedule' ] );
		add_action( 'wp_loaded', [ $this, 'maybe_resume_crawl' ] );
		add_action( 'wp_loaded', [ $this, 'maybe_schedule_incremental' ] );
		add_action( 'save_post', [ $this, 'remember_changed_post' ], 30, 3 );
	}

	public static function request_token() {
		return hash_hmac( 'sha256', home_url( '/' ), wp_salt( 'nonce' ) );
	}

	public static function is_internal_request() {
		$provided = isset( $_SERVER['HTTP_X_GML_CRAWL'] )
			? (string) wp_unslash( $_SERVER['HTTP_X_GML_CRAWL'] )
			: '';
		return $provided !== '' && hash_equals( static::request_token(), $provided );
	}

	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules['gml_every_two_minutes'] ) ) {
			$schedules['gml_every_two_minutes'] = [
				'interval' => 120,
				'display'  => apply_filters( 'gml_translation_crawl_schedule_label', 'GML Translation: Every 2 Minutes' ),
			];
		}
		return $schedules;
	}

	public function maybe_resume_crawl() {
		if ( ! is_admin() && ! wp_doing_cron() ) return;
		if ( ! get_option( 'gml_crawl_running', false ) ) {
			return;
		}
		if ( static::safety_paused() ) {
			static::unschedule_crawl();
			return;
		}
		if ( ! static::ai_translation_available() ) {
			static::stop_crawl();
			return;
		}
		if ( ! wp_next_scheduled( static::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'gml_every_two_minutes', static::CRON_HOOK );
		}
	}

	/**
	 * Remember a changed public page and discover only that page after the save
	 * request has finished. This never calls AI or resumes a paused worker.
	 */
	public function remember_changed_post( $post_id, $post = null, $update = false ) {
		unset( $update );
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		if ( ! $post instanceof WP_Post ) $post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' || $post->post_type === 'attachment' ) return;
		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || empty( $post_type->public ) ) return;
		if ( ! class_exists( 'GML_Translation_State' ) || ! GML_Translation_State::multilingual_enabled() ) return;

		$dirty = static::dirty_posts();
		$dirty[ $post_id ] = 0;
		if ( count( $dirty ) > static::MAX_DIRTY_POSTS ) {
			$dirty = array_slice( $dirty, -static::MAX_DIRTY_POSTS, null, true );
		}
		update_option( static::DIRTY_POSTS_OPTION, $dirty, false );
		if ( class_exists( 'GML_Translation_Readiness' ) ) GML_Translation_Readiness::clear_cache();
		$this->schedule_incremental( 15 );
	}

	public function maybe_schedule_incremental() {
		if ( ! is_admin() && ! wp_doing_cron() ) return;
		if ( ! static::dirty_posts() ) return;
		$this->schedule_incremental( 15 );
	}

	public function discover_changed_content() {
		$dirty = static::dirty_posts();
		if ( ! $dirty || ! static::ai_translation_available() || static::safety_paused() ) return;
		if ( ! static::acquire_lock() ) {
			$this->schedule_incremental( 60 );
			return;
		}

		try {
			$languages   = (array) get_option( 'gml_languages', [] );
			$source_lang = static::normalize_language( get_option( 'gml_source_lang', 'en' ) );
			$targets     = [];
			foreach ( $languages as $language ) {
				if ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $language ) ) continue;
				$target = static::normalize_language( $language['code'] ?? '' );
				if ( $target !== '' && $target !== $source_lang && ( ! array_key_exists( 'enabled', $language ) || ! empty( $language['enabled'] ) ) ) {
					$targets[] = $target;
				}
			}
			if ( ! $targets ) {
				delete_option( static::DIRTY_POSTS_OPTION );
				return;
			}

			$parser     = new GML_HTML_Parser();
			$translator = new GML_Translator();
			$post_ids   = array_slice( array_keys( $dirty ), 0, static::INCREMENTAL_BATCH_SIZE );
			foreach ( $post_ids as $post_id ) {
				$post_id = (int) $post_id;
				try {
					$post = get_post( $post_id );
					if ( $post instanceof WP_Post && $post->post_status === 'publish' ) {
						$html = $this->fetch_rendered_html( $post );
						if ( empty( $html ) ) $html = $this->build_post_html( $post );
						if ( $html !== '' ) {
							$parsed = $parser->parse( $html );
							foreach ( $targets as $target ) $translator->discover( $parsed, $target );
						}
					}
					unset( $dirty[ $post_id ] );
				} catch ( \Throwable $e ) {
					$dirty[ $post_id ] = (int) ( $dirty[ $post_id ] ?? 0 ) + 1;
					if ( $dirty[ $post_id ] >= 3 ) unset( $dirty[ $post_id ] );
					static::log_event( 'incremental_post_failed', $post_id );
				}
			}

			if ( $dirty ) update_option( static::DIRTY_POSTS_OPTION, $dirty, false );
			else delete_option( static::DIRTY_POSTS_OPTION );
			update_option( 'gml_translation_incremental_last_activity', time(), false );
			if ( class_exists( 'GML_Translation_Readiness' ) ) GML_Translation_Readiness::clear_cache();
		} finally {
			static::release_lock();
		}

		if ( static::dirty_posts() ) $this->schedule_incremental( 60 );
	}

	private function schedule_incremental( $delay ) {
		if ( ! static::ai_translation_available() || static::safety_paused() ) return false;
		if ( wp_next_scheduled( static::INCREMENTAL_CRON_HOOK ) ) return true;
		$result = wp_schedule_single_event( time() + max( 5, (int) $delay ), static::INCREMENTAL_CRON_HOOK, [], true );
		return $result && ! is_wp_error( $result );
	}

	private static function dirty_posts() {
		$raw   = (array) get_option( static::DIRTY_POSTS_OPTION, [] );
		$dirty = [];
		foreach ( $raw as $post_id => $attempts ) {
			$post_id = (int) $post_id;
			if ( $post_id > 0 ) $dirty[ $post_id ] = max( 0, min( 3, (int) $attempts ) );
		}
		return $dirty;
	}

	public static function start_crawl() {
		if ( ! static::ai_translation_available() ) {
			return new WP_Error( 'gml_ai_unavailable', 'Enable multilingual output and configure AI Translation before starting a crawl.' );
		}
		if ( class_exists( 'GML_Queue_Processor' ) && ( GML_Queue_Processor::circuit_is_open() || GML_Queue_Processor::maybe_open_for_existing_failures() ) ) {
			return new WP_Error( 'gml_safety_pause', 'Translation is safety-paused. Test the saved AI connection and retry one limited language sample first.' );
		}

		$total = static::count_crawlable_content();
		// Paused workers have no instance, so register the schedule on this entrypoint.
		add_filter( 'cron_schedules', [ static::class, 'add_schedule' ] );
		$new_crawl_time = null;
		if ( ! wp_next_scheduled( static::CRON_HOOK ) ) {
			$new_crawl_time = time() + 5;
			$scheduled = wp_schedule_event( $new_crawl_time, 'gml_every_two_minutes', static::CRON_HOOK, [], true );
			if ( ! $scheduled || is_wp_error( $scheduled ) ) {
				return new WP_Error( 'gml_crawl_schedule_failed', 'WordPress could not schedule the content crawl. The translation queue remains unchanged.' );
			}
		}
		delete_option( 'gml_crawl_offset' );
		delete_option( 'gml_crawl_completed' );
		update_option( 'gml_crawl_total', $total, false );
		update_option( 'gml_crawl_running', true, false );
		return true;
	}

	public static function stop_crawl( $completed = false ) {
		update_option( 'gml_crawl_running', false, false );
		update_option( 'gml_crawl_completed', $completed, false );
		static::unschedule_crawl();
	}

	public static function get_status() {
		$running = (bool) get_option( 'gml_crawl_running', false );
		$offset  = max( 0, (int) get_option( 'gml_crawl_offset', 0 ) );
		$total   = max( 0, (int) get_option( 'gml_crawl_total', 0 ) );
		$next = wp_next_scheduled( static::CRON_HOOK );
		if ( ! $running ) $state = get_option( 'gml_crawl_completed', false ) ? 'completed' : 'stopped';
		elseif ( ! static::ai_translation_available() || GML_Queue_Processor::circuit_is_open() ) $state = 'blocked';
		elseif ( (int) get_option( static::LOCK_OPTION, 0 ) > time() ) $state = 'scanning';
		elseif ( ! $next ) $state = 'not_scheduled';
		elseif ( $next < time() - 120 ) $state = 'overdue';
		else $state = 'scheduled';
		return [
			'running'   => $running,
			'state'     => $state,
			'last_activity' => (int) get_option( 'gml_crawl_last_activity', 0 ),
			'processed' => min( $offset, $total ),
			'total'     => $total,
			'percent'   => $total > 0 ? min( 100, round( $offset / $total * 100 ) ) : 0,
		];
	}

	public static function count_crawlable_content() {
		$post_types = get_post_types( [ 'public' => true ], 'names' );
		unset( $post_types['attachment'] );
		$count = 0;
		foreach ( $post_types as $post_type ) {
			$counts = wp_count_posts( $post_type );
			$count += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		return $count;
	}

	public function crawl_batch() {
		if ( ! get_option( 'gml_crawl_running', false ) ) {
			return;
		}
		if ( static::safety_paused() ) {
			static::unschedule_crawl();
			return;
		}
		if ( ! static::ai_translation_available() ) {
			static::stop_crawl();
			return;
		}
		if ( ! static::acquire_lock() ) {
			return;
		}

		try {
			$this->process_crawl_batch();
		} catch ( \Throwable $e ) {
			static::log_event( 'batch_failed' );
		} finally {
			static::release_lock();
		}
	}

	protected function process_crawl_batch() {
		$languages   = (array) get_option( 'gml_languages', [] );
		$source_lang = static::normalize_language( get_option( 'gml_source_lang', 'en' ) );
		if ( empty( $languages ) || $source_lang === '' ) {
			static::stop_crawl();
			return;
		}

		$offset     = max( 0, (int) get_option( 'gml_crawl_offset', 0 ) );
		$post_types = get_post_types( [ 'public' => true ], 'names' );
		unset( $post_types['attachment'] );
		$posts = get_posts( [
			'post_type'      => array_values( $post_types ),
			'post_status'    => 'publish',
			'posts_per_page' => static::BATCH_SIZE,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] );

		if ( empty( $posts ) ) {
			static::stop_crawl( true );
			return;
		}

		$parser     = new GML_HTML_Parser();
		$translator = new GML_Translator();
		$processed = 0;
		foreach ( $posts as $post ) {
			if ( ! get_option( 'gml_crawl_running', false ) || ! static::ai_translation_available() || static::safety_paused() ) break;
			$processed++;
			try {
				$html = $this->fetch_rendered_html( $post );
				if ( empty( $html ) ) {
					$html = $this->build_post_html( $post );
				}
				if ( empty( $html ) ) {
					continue;
				}

				$parsed = $parser->parse( $html );
				foreach ( $languages as $language ) {
					if ( class_exists( 'GML_Language_Utils' ) && GML_Language_Utils::is_external_language( $language ) ) continue;
					$target = static::normalize_language( $language['code'] ?? '' );
					if (
						$target === '' ||
						$target === $source_lang ||
						empty( $language['enabled'] ) && array_key_exists( 'enabled', $language )
					) {
						continue;
					}
					$translator->discover( $parsed, $target );
				}
			} catch ( \Throwable $e ) {
				static::log_event( 'post_failed', isset( $post->ID ) ? (int) $post->ID : 0 );
			}
		}

		if ( $processed > 0 ) {
			update_option( 'gml_crawl_offset', $offset + $processed, false );
			update_option( 'gml_crawl_last_activity', time(), false );
			if ( $offset + $processed >= (int) get_option( 'gml_crawl_total', 0 ) ) static::stop_crawl( true );
		}
	}

	protected function fetch_rendered_html( $post ) {
		$url = get_permalink( $post );
		if ( ! $url || ! static::is_exact_site_url( $url ) ) {
			return null;
		}

		$url = add_query_arg( 'gml_crawl', '1', $url );
		$response = wp_safe_remote_get( $url, [
			'timeout'             => 15,
			'redirection'         => 0,
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => 524288,
			'user-agent'          => 'GML-Content-Crawler/1.0',
			'cookies'             => [],
			'headers'             => [
				'Accept'      => 'text/html',
				'X-GML-Crawl' => static::request_token(),
			],
		] );

		if ( is_wp_error( $response ) ) {
			static::log_event( 'fetch_failed', isset( $post->ID ) ? (int) $post->ID : 0 );
			return null;
		}
		if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( $content_type !== '' && strpos( $content_type, 'text/html' ) === false && strpos( $content_type, 'application/xhtml+xml' ) === false ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) < 100 || strlen( $body ) > 524288 ) {
			return null;
		}
		if ( stripos( $body, '<html' ) === false && stripos( $body, '<!doctype' ) === false ) {
			return null;
		}
		return $body;
	}

	protected function build_post_html( $post ) {
		$parts = [];
		if ( ! empty( $post->post_title ) ) {
			$parts[] = '<h1>' . esc_html( $post->post_title ) . '</h1>';
		}
		if ( ! empty( $post->post_excerpt ) ) {
			$parts[] = '<p>' . esc_html( $post->post_excerpt ) . '</p>';
		}
		if ( ! empty( $post->post_content ) ) {
			$parts[] = wpautop( strip_shortcodes( $post->post_content ) );
		}

		$title_keys = (array) apply_filters(
			'gml_translation_crawler_seo_title_meta_keys',
			[ '_yoast_wpseo_title', 'rank_math_title' ],
			$post
		);
		$desc_keys = (array) apply_filters(
			'gml_translation_crawler_seo_description_meta_keys',
			[ '_yoast_wpseo_metadesc', 'rank_math_description' ],
			$post
		);
		$seo_title = $this->first_post_meta( $post->ID, $title_keys );
		$seo_desc  = $this->first_post_meta( $post->ID, $desc_keys );
		if ( $seo_title !== '' ) {
			$parts[] = '<meta name="title" content="' . esc_attr( $seo_title ) . '">';
		}
		if ( $seo_desc !== '' ) {
			$parts[] = '<meta name="description" content="' . esc_attr( $seo_desc ) . '">';
		}

		if ( ( $post->post_type ?? '' ) === 'product' ) {
			$attributes = get_post_meta( $post->ID, '_product_attributes', true );
			if ( is_array( $attributes ) ) {
				foreach ( $attributes as $attribute ) {
					if ( ! empty( $attribute['name'] ) && ! empty( $attribute['value'] ) ) {
						$parts[] = '<span>' . esc_html( $attribute['name'] ) . '</span>';
						$parts[] = '<span>' . esc_html( $attribute['value'] ) . '</span>';
					}
				}
			}
		}

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post->ID, $taxonomy );
			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$parts[] = '<span>' . esc_html( $term->name ) . '</span>';
				if ( ! empty( $term->description ) ) {
					$parts[] = '<p>' . esc_html( $term->description ) . '</p>';
				}
			}
		}

		return empty( $parts ) ? '' : '<html><body>' . implode( "\n", $parts ) . '</body></html>';
	}

	protected function first_post_meta( $post_id, array $keys ) {
		foreach ( array_slice( array_values( array_unique( array_map( 'sanitize_key', $keys ) ) ), 0, 10 ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( is_scalar( $value ) && trim( (string) $value ) !== '' ) {
				return (string) $value;
			}
		}
		return '';
	}

	protected static function ai_translation_available() {
		return class_exists( 'GML_Translation_State' )
			&& GML_Translation_State::multilingual_enabled()
			&& GML_Translation_State::ai_available();
	}

	protected static function safety_paused() {
		return class_exists( 'GML_Queue_Processor' )
			&& ( GML_Queue_Processor::circuit_is_open() || GML_Queue_Processor::maybe_open_for_existing_failures() );
	}

	protected static function is_exact_site_url( $url ) {
		if ( class_exists( 'GML_URL_Helper' ) ) {
			return GML_URL_Helper::internal_absolute_path( $url ) !== null;
		}

		$home  = wp_parse_url( home_url( '/' ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $home ) || ! is_array( $parts ) ) {
			return false;
		}
		$default_port = static function( $scheme ) {
			return strtolower( (string) $scheme ) === 'https' ? 443 : 80;
		};
		$home_scheme = strtolower( (string) ( $home['scheme'] ?? '' ) );
		$url_scheme  = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$home_port   = isset( $home['port'] ) ? (int) $home['port'] : $default_port( $home_scheme );
		$url_port    = isset( $parts['port'] ) ? (int) $parts['port'] : $default_port( $url_scheme );
		$home_path   = rtrim( (string) ( $home['path'] ?? '' ), '/' );
		$url_path    = (string) ( $parts['path'] ?? '/' );
		return $home_scheme !== ''
			&& $home_scheme === $url_scheme
			&& strtolower( (string) ( $home['host'] ?? '' ) ) === strtolower( (string) ( $parts['host'] ?? '' ) )
			&& $home_port === $url_port
			&& ( $home_path === '' || $home_path === '/' || $url_path === $home_path || strpos( $url_path, $home_path . '/' ) === 0 );
	}

	protected static function normalize_language( $language ) {
		if ( class_exists( 'GML_Language_Utils' ) ) {
			return GML_Language_Utils::normalize_code( $language );
		}
		return sanitize_key( str_replace( '_', '-', strtolower( (string) $language ) ) );
	}

	protected static function acquire_lock() {
		$now     = time();
		$expires = (int) get_option( static::LOCK_OPTION, 0 );
		if ( $expires > $now ) {
			return false;
		}
		if ( $expires > 0 ) {
			delete_option( static::LOCK_OPTION );
		}
		return add_option( static::LOCK_OPTION, $now + 90, '', false );
	}

	protected static function release_lock() {
		delete_option( static::LOCK_OPTION );
	}

	protected static function unschedule_crawl() {
		wp_clear_scheduled_hook( static::CRON_HOOK );
	}

	protected static function log_event( $code, $post_id = 0 ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		error_log( sprintf( 'GML translation crawler [%s]%s', sanitize_key( $code ), $post_id > 0 ? ' post #' . (int) $post_id : '' ) );
	}
}
