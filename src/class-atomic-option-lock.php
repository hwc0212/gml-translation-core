<?php
/**
 * Database-backed lease lock with atomic stale takeover and owner fencing.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GML_Atomic_Option_Lock {
	const MIN_TTL = 5;
	const MAX_TTL = 3600;

	/**
	 * Acquire a new lease, or atomically replace the exact stale value read.
	 *
	 * @return string Owner token, or an empty string when another owner is active.
	 */
	public static function acquire( $option, $ttl ) {
		global $wpdb;

		$option = static::valid_option_name( $option );
		if ( $option === '' ) {
			return '';
		}

		$ttl    = static::bounded_ttl( $ttl );
		$token  = static::new_token();
		$value  = static::serialize_record( $token, time() + $ttl );
		$insert = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option,
				$value
			)
		);
		if ( $insert === 1 ) {
			static::invalidate_option_cache( $option );
			return $token;
		}

		$raw      = static::read_raw( $option );
		$existing = static::decode_record( $raw );
		if ( $raw === null || (int) $existing['expires'] > time() ) {
			return '';
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				 SET option_value = %s, autoload = 'no'
				 WHERE option_name = %s AND BINARY option_value = BINARY %s",
				$value,
				$option,
				$raw
			)
		);
		if ( $updated === 1 ) {
			static::invalidate_option_cache( $option );
			return $token;
		}

		return '';
	}

	/** Renew only a live lease still owned by the supplied token. */
	public static function refresh( $option, $token, $ttl ) {
		global $wpdb;

		$option = static::valid_option_name( $option );
		$token  = (string) $token;
		if ( $option === '' || $token === '' ) {
			return false;
		}

		$raw      = static::read_raw( $option );
		$existing = static::decode_record( $raw );
		if (
			$raw === null
			|| $existing['token'] === ''
			|| ! hash_equals( $existing['token'], $token )
			|| (int) $existing['expires'] <= time()
		) {
			return false;
		}

		$value = static::serialize_record( $token, time() + static::bounded_ttl( $ttl ) );
		if ( hash_equals( $raw, $value ) ) {
			return true;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				 SET option_value = %s, autoload = 'no'
				 WHERE option_name = %s AND BINARY option_value = BINARY %s",
				$value,
				$option,
				$raw
			)
		);
		if ( $updated === 1 ) {
			static::invalidate_option_cache( $option );
			return true;
		}
		return false;
	}

	/** Return whether the supplied token owns a non-expired lease. */
	public static function is_owner( $option, $token ) {
		$record = static::get( $option );
		$token  = (string) $token;
		return $token !== ''
			&& $record['token'] !== ''
			&& hash_equals( $record['token'], $token )
			&& (int) $record['expires'] > time();
	}

	/** Delete only the exact row value currently owned by the supplied token. */
	public static function release( $option, $token ) {
		global $wpdb;

		$option = static::valid_option_name( $option );
		$token  = (string) $token;
		if ( $option === '' || $token === '' ) {
			return false;
		}

		$raw      = static::read_raw( $option );
		$existing = static::decode_record( $raw );
		if ( $raw === null || $existing['token'] === '' || ! hash_equals( $existing['token'], $token ) ) {
			return false;
		}

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s",
				$option,
				$raw
			)
		);
		if ( $deleted === 1 ) {
			static::invalidate_option_cache( $option );
			return true;
		}
		return false;
	}

	/** Read the authoritative database value, bypassing stale object caches. */
	public static function get( $option ) {
		$option = static::valid_option_name( $option );
		return $option === ''
			? [ 'token' => '', 'expires' => 0, 'legacy' => false ]
			: static::decode_record( static::read_raw( $option ) );
	}

	public static function is_active( $option ) {
		$record = static::get( $option );
		return (int) $record['expires'] > time();
	}

	private static function read_raw( $option ) {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option )
		);
		return $value === null ? null : (string) $value;
	}

	private static function decode_record( $raw ) {
		$record = [ 'token' => '', 'expires' => 0, 'legacy' => false ];
		if ( $raw === null ) {
			return $record;
		}

		$value = static::unserialize_record( $raw );
		if ( is_array( $value ) ) {
			$record['token']   = isset( $value['token'] ) ? (string) $value['token'] : '';
			$record['expires'] = isset( $value['expires'] ) ? (int) $value['expires'] : 0;
			$record['legacy']  = empty( $value['version'] );
			return $record;
		}
		if ( is_numeric( $value ) ) {
			$record['expires'] = (int) $value;
			$record['legacy']  = true;
		}
		return $record;
	}

	private static function serialize_record( $token, $expires ) {
		return serialize( [
			'version' => 1,
			'token'   => (string) $token,
			'expires' => (int) $expires,
		] );
	}

	/**
	 * Decode only the array format written by this lock implementation.
	 *
	 * Legacy crawler locks are plain integer timestamps and are intentionally
	 * returned unchanged for the numeric compatibility path in decode_record().
	 */
	private static function unserialize_record( $raw ) {
		if ( ! is_string( $raw ) || strpos( $raw, 'a:' ) !== 0 ) {
			return $raw;
		}

		$value = @unserialize( $raw, [ 'allowed_classes' => false ] );
		return is_array( $value ) ? $value : $raw;
	}

	private static function new_token() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $exception ) {
			return uniqid( 'gml-', true );
		}
	}

	private static function bounded_ttl( $ttl ) {
		return max( static::MIN_TTL, min( static::MAX_TTL, (int) $ttl ) );
	}

	private static function valid_option_name( $option ) {
		$option = (string) $option;
		return $option !== '' && strlen( $option ) <= 191 && preg_match( '/^[A-Za-z0-9_.:-]+$/', $option )
			? $option
			: '';
	}

	private static function invalidate_option_cache( $option ) {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
