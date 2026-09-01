<?php
/**
 * GML Installer — Database setup and default options
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Installer {

    const DB_VERSION = '2.5.2';
    const ERROR_OPTION = 'gml_translation_db_error';

    public static function register_hooks() {
        add_action( 'admin_init', [ __CLASS__, 'maybe_upgrade' ] );
        add_action( 'admin_notices', [ __CLASS__, 'upgrade_notice' ] );
    }

    public static function maybe_upgrade() {
        if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        if ( ! version_compare( get_option( 'gml_db_version', '0' ), self::DB_VERSION, '<' ) ) {
            return true;
        }
        $error = (array) get_option( self::ERROR_OPTION, [] );
        if ( (int) ( $error['retry_after'] ?? 0 ) > time() ) {
            return false;
        }
        return self::activate();
    }

    public static function upgrade_notice() {
        if ( ! current_user_can( 'manage_options' ) || ! get_option( self::ERROR_OPTION ) ) return;
        echo '<div class="notice notice-error"><p>' . esc_html__( 'GML translation database setup did not complete. Existing translation data has been kept. Check database availability before retrying from the admin area.', 'gml-translate' ) . '</p></div>';
    }

    public static function lock_name() {
        global $wpdb;
        return 'gml-install-' . md5( DB_NAME . ':' . $wpdb->prefix );
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public static function activate() {
        global $wpdb;
        $lock = self::lock_name();
        if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) !== 1 ) {
            return new WP_Error( 'gml_install_busy', 'Translation database setup is already running or unavailable.' );
        }
        $old_timeout = null;
        try {
            $old_timeout = (int) $wpdb->get_var( 'SELECT @@SESSION.lock_wait_timeout' );
            self::execute( 'SET SESSION lock_wait_timeout = 2' );
            self::create_tables();
            self::set_default_options();
            self::disable_large_option_autoload();
            self::create_cache_directory();
            self::maybe_import_weglot_config();
            update_option( 'gml_db_version', self::DB_VERSION, false );
            if ( get_option( 'gml_db_version' ) !== self::DB_VERSION ) {
                throw new RuntimeException( 'version_write_failed' );
            }
            delete_option( self::ERROR_OPTION );
            return true;
        } catch ( Throwable $error ) {
            update_option( self::ERROR_OPTION, [ 'retry_after' => time() + 60 ], false );
            return new WP_Error( 'gml_install_failed', 'Translation database setup failed. Existing data has been kept.' );
        } finally {
            if ( $old_timeout !== null ) {
                $wpdb->query( 'SET SESSION lock_wait_timeout = ' . max( 1, $old_timeout ) );
            }
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    private static function execute( $sql ) {
        global $wpdb;
        if ( $wpdb->query( $sql ) === false ) throw new RuntimeException( 'database_setup_failed' );
    }

    private static function create_if_missing( $table, $sql ) {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $wpdb->last_error !== '' ) throw new RuntimeException( 'table_check_failed' );
        // Existing schemas are supported without ALTER, deduplication, or data deletion.
        if ( $exists !== $table ) self::execute( $sql );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'gml_process_queue' );
        wp_clear_scheduled_hook( 'gml_crawl_content' );
        // NOTE: We intentionally keep gml_crawl_running and gml_crawl_total
        // in the database. WordPress calls deactivate → activate during plugin
        // updates, and clearing these options would silently abort an in-progress
        // crawl. The Content Crawler's maybe_resume_crawl() will re-schedule
        // the cron event on the next page load after reactivation.
        flush_rewrite_rules();
    }

    // ── Tables ────────────────────────────────────────────────────────────────

    private static function create_tables() {
        global $wpdb;
        $cc = $wpdb->get_charset_collate();

        // Translation memory — global hash index
        $t = $wpdb->prefix . 'gml_index';
        self::create_if_missing( $t, "CREATE TABLE IF NOT EXISTS $t (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_hash CHAR(32) NOT NULL,
            source_text TEXT NOT NULL,
            source_lang VARCHAR(10) NOT NULL,
            target_lang VARCHAR(10) NOT NULL,
            translated_text TEXT NOT NULL,
            context_type VARCHAR(20) DEFAULT 'text',
            status ENUM('auto','manual','pending') DEFAULT 'auto',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY hash_lang (source_hash, source_lang, target_lang),
            KEY idx_status (status),
            KEY idx_context (context_type)
        ) $cc;" );

        // Async translation queue
        $t = $wpdb->prefix . 'gml_queue';
        self::create_if_missing( $t, "CREATE TABLE IF NOT EXISTS $t (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_hash CHAR(32) NOT NULL,
            source_text TEXT NOT NULL,
            source_lang VARCHAR(10) NOT NULL,
            target_lang VARCHAR(10) NOT NULL,
            context_type VARCHAR(20) DEFAULT 'text',
            priority INT DEFAULT 5,
            status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
            attempts INT DEFAULT 0,
            error_message TEXT,
            created_at DATETIME NOT NULL,
            processed_at DATETIME,
            PRIMARY KEY  (id),
            UNIQUE KEY queue_hash_lang (source_hash, source_lang, target_lang),
            KEY idx_status_priority (status, priority),
            KEY idx_hash (source_hash)
        ) $cc;" );

    }

    // ── Default options ───────────────────────────────────────────────────────

    private static function set_default_options() {
        // Auto-detect source language from WordPress locale
        $wp_locale   = get_locale();
        $source_lang = substr( $wp_locale, 0, 2 ) ?: 'en';

        $legacy_enabled = (bool) get_option( 'gml_translation_enabled', false );
        $defaults = [
            'gml_source_lang'        => $source_lang,
            'gml_languages'          => [],
            'gml_url_structure'      => 'subdirectory',
            'gml_tone'               => 'professional and friendly',
            'gml_protected_terms'    => [ 'GML', 'WordPress', 'WooCommerce', 'Gemini' ],
            'gml_exclude_selectors'  => [ '.notranslate', '[translate="no"]' ],
            'gml_switcher_is_dropdown' => true,
            'gml_switcher_show_flags'  => true,
            'gml_switcher_flag_type'   => 'rectangle',
            'gml_switcher_show_names'  => true,
            'gml_switcher_use_fullname' => true,
            'gml_switcher_appearance'  => 'inherit',
            'gml_switcher_panel_alignment' => 'auto',
            'gml_switcher_position'    => 'none',
            'gml_translation_enabled'  => false,
            'gml_multilingual_enabled' => $legacy_enabled,
            'gml_ai_translation_enabled' => $legacy_enabled,
            'gml_translation_paused'   => false,
            'gml_auto_detect_language' => false,
            'gml_exclusion_rules'      => [],
            'gml_glossary_rules'       => [],
        ];

        $non_autoload = [
            'gml_protected_terms',
            'gml_exclude_selectors',
            'gml_exclusion_rules',
            'gml_glossary_rules',
        ];
        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value, '', ! in_array( $key, $non_autoload, true ) );
            }
        }
    }

    /** Keep potentially large rule arrays out of every WordPress request. */
    private static function disable_large_option_autoload() {
        global $wpdb;

        $options = [
            'gml_protected_terms',
            'gml_exclude_selectors',
            'gml_exclusion_rules',
            'gml_glossary_rules',
        ];
        if ( function_exists( 'wp_set_option_autoload_values' ) ) {
            wp_set_option_autoload_values( array_fill_keys( $options, false ) );
            return;
        }

        // WordPress 6.0-6.5 does not expose the bulk autoload API. The option
        // names are fixed constants, values are untouched, and caches are
        // invalidated after the one-time versioned migration.
        $placeholders = implode( ', ', array_fill( 0, count( $options ), '%s' ) );
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name IN ({$placeholders})",
            $options
        );
        self::execute( $sql );
        wp_cache_delete( 'alloptions', 'options' );
        foreach ( $options as $option ) {
            wp_cache_delete( $option, 'options' );
        }
    }

    // ── Cache directory ───────────────────────────────────────────────────────

    private static function create_cache_directory() {
        $upload_dir = wp_upload_dir();
        $cache_dir  = $upload_dir['basedir'] . '/gml-cache';

        if ( ! file_exists( $cache_dir ) ) {
            wp_mkdir_p( $cache_dir );
            file_put_contents( $cache_dir . '/.htaccess', 'Deny from all' );
            file_put_contents( $cache_dir . '/index.php', '<?php // Silence is golden' );
        }
    }

    // ── Weglot config import ──────────────────────────────────────────────────

    /**
     * Auto-import language configuration from Weglot if present.
     * Only imports when GML has no destination languages configured yet.
     */
    private static function maybe_import_weglot_config() {
        // Skip if GML already has languages configured
        $existing = get_option( 'gml_languages', [] );
        if ( ! empty( $existing ) ) {
            return;
        }

        $weglot_config = self::get_weglot_config();
        if ( ! $weglot_config ) {
            return;
        }

        $source_lang  = $weglot_config['source'];
        $dest_langs   = $weglot_config['destinations'];

        if ( empty( $dest_langs ) ) {
            return;
        }

        // Import source language
        update_option( 'gml_source_lang', $source_lang );

        // Build GML language entries from available languages list
        $available = self::get_available_languages_static();
        $languages = [];

        foreach ( $dest_langs as $code ) {
            if ( $code === $source_lang ) {
                continue;
            }
            $info = $available[ $code ] ?? null;
            if ( ! $info ) {
                continue;
            }
            $languages[] = [
                'code'        => $code,
                'name'        => $info['name'],
                'native_name' => $info['native'],
                'flag'        => $info['flag'],
                'country'     => $info['country'],
                'url_prefix'  => '/' . $code . '/',
                'site_mode'   => 'local',
                'enabled'     => true,
            ];
        }

        if ( ! empty( $languages ) ) {
            update_option( 'gml_languages', $languages );
            // Store notice flag for admin display
            update_option( 'gml_weglot_imported', count( $languages ) );
        }
    }

    /**
     * Extract Weglot's language configuration from wp_options.
     *
     * Weglot stores settings in multiple places:
     * 1. API/CDN cache: transient 'weglot_cache_cdn' (has language_from + languages)
     * 2. Local DB: option 'weglot-translate-v3' (may have partial data)
     * 3. Legacy v2: option 'weglot-translate' (has api_key + languages)
     *
     * @return array|false  ['source' => 'en', 'destinations' => ['ru','de',...]] or false
     */
    private static function get_weglot_config() {
        $source = null;
        $destinations = [];

        // Strategy 1: CDN cache transient (most complete, has API data)
        $cdn_cache = get_transient( 'weglot_cache_cdn' );
        if ( is_array( $cdn_cache ) ) {
            if ( ! empty( $cdn_cache['language_from'] ) ) {
                $source = $cdn_cache['language_from'];
            }
            if ( ! empty( $cdn_cache['languages'] ) && is_array( $cdn_cache['languages'] ) ) {
                foreach ( $cdn_cache['languages'] as $lang ) {
                    if ( ! empty( $lang['language_to'] ) && ( ! isset( $lang['enabled'] ) || $lang['enabled'] ) ) {
                        $destinations[] = $lang['language_to'];
                    }
                }
            }
            // CDN cache may also have destination_language (after Morphism mapping)
            if ( empty( $destinations ) && ! empty( $cdn_cache['destination_language'] ) && is_array( $cdn_cache['destination_language'] ) ) {
                foreach ( $cdn_cache['destination_language'] as $lang ) {
                    if ( ! empty( $lang['language_to'] ) ) {
                        $destinations[] = $lang['language_to'];
                    }
                }
            }
        }

        // Strategy 2: Local DB v3 option
        if ( empty( $destinations ) ) {
            $v3 = get_option( 'weglot-translate-v3', [] );
            if ( is_array( $v3 ) ) {
                if ( ! empty( $v3['language_from'] ) && ! $source ) {
                    $source = $v3['language_from'];
                }
                if ( ! empty( $v3['languages'] ) && is_array( $v3['languages'] ) ) {
                    foreach ( $v3['languages'] as $lang ) {
                        if ( ! empty( $lang['language_to'] ) && ( ! isset( $lang['enabled'] ) || $lang['enabled'] ) ) {
                            $destinations[] = $lang['language_to'];
                        }
                    }
                }
            }
        }

        // Strategy 3: Legacy v2 option
        if ( empty( $destinations ) ) {
            $v2 = get_option( 'weglot-translate', [] );
            if ( is_array( $v2 ) ) {
                if ( ! empty( $v2['language_from'] ) && ! $source ) {
                    $source = $v2['language_from'];
                }
                if ( ! empty( $v2['languages'] ) && is_array( $v2['languages'] ) ) {
                    foreach ( $v2['languages'] as $lang ) {
                        if ( is_array( $lang ) && ! empty( $lang['language_to'] ) ) {
                            if ( ! isset( $lang['enabled'] ) || $lang['enabled'] ) {
                                $destinations[] = $lang['language_to'];
                            }
                        } elseif ( is_string( $lang ) ) {
                            // v2 might store as simple array of codes
                            $destinations[] = $lang;
                        }
                    }
                }
            }
        }

        if ( empty( $destinations ) ) {
            return false;
        }

        // Default source to 'en' if not found (Weglot's default)
        if ( ! $source ) {
            $source = 'en';
        }

        return [
            'source'       => $source,
            'destinations' => array_unique( $destinations ),
        ];
    }

    /**
     * Static version of available languages for use during activation.
     * Mirrors GML_Admin_Settings::get_available_languages().
     */
    private static function get_available_languages_static() {
        return [
            'en' => ['name'=>'English',      'native'=>'English',         'flag'=>'🇺🇸','country'=>'us'],
            'zh' => ['name'=>'Chinese',      'native'=>'中文',             'flag'=>'🇨🇳','country'=>'cn'],
            'es' => ['name'=>'Spanish',      'native'=>'Español',         'flag'=>'🇪🇸','country'=>'es'],
            'fr' => ['name'=>'French',       'native'=>'Français',        'flag'=>'🇫🇷','country'=>'fr'],
            'de' => ['name'=>'German',       'native'=>'Deutsch',         'flag'=>'🇩🇪','country'=>'de'],
            'ja' => ['name'=>'Japanese',     'native'=>'日本語',           'flag'=>'🇯🇵','country'=>'jp'],
            'ko' => ['name'=>'Korean',       'native'=>'한국어',           'flag'=>'🇰🇷','country'=>'kr'],
            'pt' => ['name'=>'Portuguese',   'native'=>'Português',       'flag'=>'🇵🇹','country'=>'pt'],
            'ru' => ['name'=>'Russian',      'native'=>'Русский',         'flag'=>'🇷🇺','country'=>'ru'],
            'ar' => ['name'=>'Arabic',       'native'=>'العربية',         'flag'=>'🇸🇦','country'=>'sa'],
            'hi' => ['name'=>'Hindi',        'native'=>'हिन्दी',           'flag'=>'🇮🇳','country'=>'in'],
            'it' => ['name'=>'Italian',      'native'=>'Italiano',        'flag'=>'🇮🇹','country'=>'it'],
            'nl' => ['name'=>'Dutch',        'native'=>'Nederlands',      'flag'=>'🇳🇱','country'=>'nl'],
            'pl' => ['name'=>'Polish',       'native'=>'Polski',          'flag'=>'🇵🇱','country'=>'pl'],
            'tr' => ['name'=>'Turkish',      'native'=>'Türkçe',          'flag'=>'🇹🇷','country'=>'tr'],
            'vi' => ['name'=>'Vietnamese',   'native'=>'Tiếng Việt',      'flag'=>'🇻🇳','country'=>'vn'],
            'th' => ['name'=>'Thai',         'native'=>'ไทย',             'flag'=>'🇹🇭','country'=>'th'],
            'id' => ['name'=>'Indonesian',   'native'=>'Bahasa Indonesia','flag'=>'🇮🇩','country'=>'id'],
            'ms' => ['name'=>'Malay',        'native'=>'Bahasa Melayu',   'flag'=>'🇲🇾','country'=>'my'],
            'tl' => ['name'=>'Filipino',     'native'=>'Filipino',        'flag'=>'🇵🇭','country'=>'ph'],
            'sv' => ['name'=>'Swedish',      'native'=>'Svenska',         'flag'=>'🇸🇪','country'=>'se'],
            'da' => ['name'=>'Danish',       'native'=>'Dansk',           'flag'=>'🇩🇰','country'=>'dk'],
            'nb' => ['name'=>'Norwegian',    'native'=>'Norsk',           'flag'=>'🇳🇴','country'=>'no'],
            'fi' => ['name'=>'Finnish',      'native'=>'Suomi',           'flag'=>'🇫🇮','country'=>'fi'],
            'cs' => ['name'=>'Czech',        'native'=>'Čeština',         'flag'=>'🇨🇿','country'=>'cz'],
            'sk' => ['name'=>'Slovak',       'native'=>'Slovenčina',      'flag'=>'🇸🇰','country'=>'sk'],
            'hu' => ['name'=>'Hungarian',    'native'=>'Magyar',          'flag'=>'🇭🇺','country'=>'hu'],
            'ro' => ['name'=>'Romanian',     'native'=>'Română',          'flag'=>'🇷🇴','country'=>'ro'],
            'bg' => ['name'=>'Bulgarian',    'native'=>'Български',       'flag'=>'🇧🇬','country'=>'bg'],
            'hr' => ['name'=>'Croatian',     'native'=>'Hrvatski',        'flag'=>'🇭🇷','country'=>'hr'],
            'sr' => ['name'=>'Serbian',      'native'=>'Српски',          'flag'=>'🇷🇸','country'=>'rs'],
            'sl' => ['name'=>'Slovenian',    'native'=>'Slovenščina',     'flag'=>'🇸🇮','country'=>'si'],
            'uk' => ['name'=>'Ukrainian',    'native'=>'Українська',      'flag'=>'🇺🇦','country'=>'ua'],
            'el' => ['name'=>'Greek',        'native'=>'Ελληνικά',        'flag'=>'🇬🇷','country'=>'gr'],
            'he' => ['name'=>'Hebrew',       'native'=>'עברית',           'flag'=>'🇮🇱','country'=>'il'],
            'lt' => ['name'=>'Lithuanian',   'native'=>'Lietuvių',        'flag'=>'🇱🇹','country'=>'lt'],
            'lv' => ['name'=>'Latvian',      'native'=>'Latviešu',        'flag'=>'🇱🇻','country'=>'lv'],
            'et' => ['name'=>'Estonian',      'native'=>'Eesti',           'flag'=>'🇪🇪','country'=>'ee'],
            'ca' => ['name'=>'Catalan',      'native'=>'Català',          'flag'=>'🇪🇸','country'=>'es'],
            'fa' => ['name'=>'Persian',      'native'=>'فارسی',           'flag'=>'🇮🇷','country'=>'ir'],
            'ur' => ['name'=>'Urdu',         'native'=>'اردو',            'flag'=>'🇵🇰','country'=>'pk'],
            'bn' => ['name'=>'Bengali',      'native'=>'বাংলা',            'flag'=>'🇧🇩','country'=>'bd'],
            'ta' => ['name'=>'Tamil',        'native'=>'தமிழ்',            'flag'=>'🇮🇳','country'=>'in'],
            'te' => ['name'=>'Telugu',       'native'=>'తెలుగు',           'flag'=>'🇮🇳','country'=>'in'],
            'sw' => ['name'=>'Swahili',      'native'=>'Kiswahili',       'flag'=>'🇰🇪','country'=>'ke'],
            'af' => ['name'=>'Afrikaans',    'native'=>'Afrikaans',       'flag'=>'🇿🇦','country'=>'za'],
            'ka' => ['name'=>'Georgian',     'native'=>'ქართული',         'flag'=>'🇬🇪','country'=>'ge'],
            'hy' => ['name'=>'Armenian',     'native'=>'Հայերեն',         'flag'=>'🇦🇲','country'=>'am'],
            'az' => ['name'=>'Azerbaijani',  'native'=>'Azərbaycan',      'flag'=>'🇦🇿','country'=>'az'],
            'kk' => ['name'=>'Kazakh',       'native'=>'Қазақ',           'flag'=>'🇰🇿','country'=>'kz'],
            'uz' => ['name'=>'Uzbek',        'native'=>'Oʻzbek',          'flag'=>'🇺🇿','country'=>'uz'],
            'mn' => ['name'=>'Mongolian',    'native'=>'Монгол',          'flag'=>'🇲🇳','country'=>'mn'],
            'km' => ['name'=>'Khmer',        'native'=>'ខ្មែរ',             'flag'=>'🇰🇭','country'=>'kh'],
            'my' => ['name'=>'Myanmar',      'native'=>'မြန်မာ',           'flag'=>'🇲🇲','country'=>'mm'],
            'lo' => ['name'=>'Lao',          'native'=>'ລາວ',             'flag'=>'🇱🇦','country'=>'la'],
            'ne' => ['name'=>'Nepali',       'native'=>'नेपाली',           'flag'=>'🇳🇵','country'=>'np'],
        ];
    }
}
