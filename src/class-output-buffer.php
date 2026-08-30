<?php
/**
 * GML Output Buffer — frontend HTML interception & translation
 *
 * Strategy:
 *  - Only intercepts frontend HTML responses (not admin, AJAX, REST, feeds).
 *  - Detects target language from URL prefix (/ru/, /en/, …) then cookie.
 *  - Passes the full HTML through GML_HTML_Parser → GML_Translator.
 *  - Does NOT change WordPress locale (backend language packs are irrelevant).
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Translation_Output_Buffer {

    protected $enabled     = false;
    protected $source_lang = '';
    protected $target_lang = '';
    protected $buffer_level = 0;

    public function __construct() {
        add_action( 'template_redirect', [ $this, 'start_buffer' ], 1 );
        add_action( 'shutdown',          [ $this, 'end_buffer'   ], 999 );
    }

    // ── Buffer lifecycle ──────────────────────────────────────────────────────

    public function start_buffer() {
        if ( $this->should_skip() ) {
            return;
        }

        $this->source_lang = get_option( 'gml_source_lang', 'en' );
        if ( class_exists( 'GML_Language_Utils' ) ) {
            $this->source_lang = GML_Language_Utils::normalize_code( $this->source_lang ) ?: 'en';
        }
        $this->target_lang = $this->detect_target_language();

        // Nothing to do if we're already on the source language
        if ( $this->target_lang === $this->source_lang ) {
            return;
        }

        $this->enabled = true;
        ob_start( [ $this, 'process_buffer' ] );
        $this->buffer_level = ob_get_level();
    }

    public function end_buffer() {
        // Only close the buffer this class opened. Another plugin may have
        // placed its own buffer above ours after template_redirect.
        if ( $this->enabled && $this->buffer_level > 0 && ob_get_level() === $this->buffer_level ) {
            ob_end_flush();
            $this->enabled = false;
            $this->buffer_level = 0;
        }
    }

    // ── Buffer callback ───────────────────────────────────────────────────────

    public function process_buffer( $html ) {
            if ( ! $this->is_html( $html ) ) {
                return $html;
            }

            // ── Page-level HTML cache ────────────────────────────────────────────
            // For non-logged-in visitors, cache the fully translated HTML output
            // keyed by URL + language. This skips the entire parse → translate →
            // rebuild pipeline for repeat visits to the same page.
            //
            // Logged-in users always get fresh output (admin bar, user-specific
            // content like "Hello, [name]", WooCommerce cart count, etc.).
            //
            // Cache is stored as a WordPress transient (auto-expires after 1 hour).
            // Invalidated when new translations are saved (queue processor).
            $use_page_cache = $this->can_use_page_cache();
            $page_cache_key = '';

            if ( $use_page_cache ) {
                $page_cache_key = GML_Page_Cache::key( $this->target_lang );
                $cached_html    = get_transient( $page_cache_key );
                if ( $cached_html !== false ) {
                    return $cached_html;
                }
            }

            // ── Safety: skip translation if resources are tight ──────────────────
            // Prevents fatal errors on very large pages (e.g. WooCommerce shop with
            // 200+ products) that could cause a blank page for the visitor.
            $html_len = strlen( $html );

            // 1. Skip pages larger than 1 MB — DOMDocument + mb_encode_numericentity
            //    on a 1 MB+ string can spike memory usage by 4-6×.
            if ( $html_len > 1048576 ) {
                return $this->protect_incomplete_translation( $html );
            }

            // 2. Skip if less than 16 MB of memory headroom remains.
            $memory_limit = $this->get_memory_limit_bytes();
            if ( $memory_limit > 0 ) {
                $headroom = $memory_limit - memory_get_usage( true );
                // Need roughly 6× the HTML size for DOMDocument + tokenisation + rebuild
                $needed = $html_len * 6;
                if ( $headroom < max( $needed, 16 * 1024 * 1024 ) ) {
                    return $this->protect_incomplete_translation( $html );
                }
            }

            try {
                // Protect elements that must never be translated.
                // We inject translate="no" before extract_no_translate_blocks() runs,
                // so the entire block gets lifted out and is never seen by str_replace.
                //
                // #wpadminbar — WordPress admin toolbar shown to logged-in users on
                //               the frontend. Contains WP UI strings, not page content.
                $html = preg_replace(
                    '/(<div\s[^>]*id=["\']wpadminbar["\'])/i',
                    '$1 translate="no"',
                    $html
                );

                // CSS-hidden elements — elements with inline display:none or
                // visibility:hidden are not visible to the user and should not
                // be translated. More importantly, if str_replace changes their
                // content, it can break JS that relies on the original text or
                // cause the element to become visible (e.g. Oxygen Builder
                // lightbox close buttons, hidden overlays, off-screen menus).
                $html = preg_replace(
                    '/(<[a-z][a-z0-9]*\b[^>]*\bstyle\s*=\s*["\'][^"\']*(?:display\s*:\s*none|visibility\s*:\s*hidden)[^"\']*["\'][^>]*)(>)/i',
                    '$1 translate="no"$2',
                    $html
                );

                // Extract translate="no" blocks before parsing so str_replace
                // in rebuild() never touches them (DOM exclusion alone is not enough
                // because rebuild() operates on the raw HTML string).
                [ $html_clean, $placeholders ] = $this->extract_no_translate_blocks( $html );

                $parser     = new GML_HTML_Parser();
                $parsed     = $parser->parse( $html_clean );
                $translated = ( new GML_Translator() )->translate( $parsed, $this->target_lang );
                $is_index_ready = $this->translation_is_index_ready( $translated );
                if ( class_exists( 'GML_Queue_Processor' ) ) {
                    $is_index_ready = $is_index_ready && GML_Queue_Processor::language_is_index_ready( $this->target_lang );
                }
                $result     = $parser->rebuild( $translated );

                // ── Server-side link rewriting ───────────────────────────────
                // Rewrite internal href/action URLs to include the language
                // prefix BEFORE restoring translate="no" blocks. This way the
                // language switcher (which has translate="no") is still hidden
                // inside placeholder tokens and its links won't be rewritten.
                $result = $this->rewrite_internal_links( $result );

                // Restore extracted blocks (language switcher, admin bar, etc.)
                if ( ! empty( $placeholders ) ) {
                    $result = str_replace(
                        array_keys( $placeholders ),
                        array_values( $placeholders ),
                        $result
                    );
                }

                if ( ! $is_index_ready ) {
                    $result = $this->protect_incomplete_translation( $result );
                }

                // ── Store in page-level cache ────────────────────────────────
                // Cache the translated HTML for non-logged-in visitors.
                // Incomplete pages may be cached, but they carry noindex and no
                // hreflang. A generation bump invalidates them whenever content
                // or translation state changes, including Redis-backed caches.
                if ( $use_page_cache && $page_cache_key && $this->html_is_cache_safe( $result ) ) {
                    set_transient( $page_cache_key, $result, HOUR_IN_SECONDS );
                }

                return $result;
            } catch ( \Throwable $e ) {
                // Catch both Exception and Error (e.g. TypeError, ValueError in PHP 8)
                $message = class_exists( 'GML_AI_HTTP_Transport' )
                    ? GML_AI_HTTP_Transport::redact( $e->getMessage() )
                    : sanitize_text_field( $e->getMessage() );
                error_log( 'GML Output Buffer processing failed: ' . $message );
                return $this->protect_incomplete_translation( $html );
            }
        }

        /**
         * Require all SEO strings and at least 95% of page strings to have a
         * translation before the language page can be indexed.
         */
        protected function translation_is_index_ready( array $translated ) {
            $nodes        = $translated['nodes'] ?? [];
            $replacements = $translated['replacements'] ?? [];
            $unique       = [];
            $critical     = [];

            foreach ( $nodes as $node ) {
                $text = (string) ( $node['text'] ?? '' );
                $hash = (string) ( $node['hash'] ?? md5( $text ) );
                if ( $text === '' ) {
                    continue;
                }
                $unique[ $hash ] = $text;
                if ( in_array( $node['context_type'] ?? '', [ 'seo_title', 'seo_meta' ], true ) ) {
                    $critical[ $hash ] = $text;
                }
            }

            if ( empty( $unique ) ) {
                return false;
            }

            $translated_count = 0;
            foreach ( $unique as $text ) {
                if ( array_key_exists( $text, $replacements ) && trim( (string) $replacements[ $text ] ) !== '' ) {
                    $translated_count++;
                }
            }
            foreach ( $critical as $text ) {
                if ( ! array_key_exists( $text, $replacements ) || trim( (string) $replacements[ $text ] ) === '' ) {
                    return false;
                }
            }

            return ( $translated_count / count( $unique ) ) >= 0.95;
        }

        /**
         * Keep incomplete language pages accessible for review while preventing
         * search engines from indexing an English or partially translated copy.
         */
        protected function protect_incomplete_translation( $html ) {
            $html = preg_replace(
                '#\s*<link\b(?=[^>]*\brel=["\']alternate["\'])(?=[^>]*\bhreflang=["\'][^"\']+["\'])[^>]*>#i',
                '',
                (string) $html
            );
            if ( stripos( $html, 'data-gml-translation-status="incomplete"' ) !== false ) {
                return $html;
            }

            // Emit one unambiguous robots directive. Search engines generally
            // choose the most restrictive duplicate value, but a single tag is
            // easier to audit and cannot conflict with an earlier "index" tag.
            $html = preg_replace(
                '#\s*<meta\b(?=[^>]*\bname=["\']robots["\'])[^>]*>#i',
                '',
                $html
            );

            $tag = '<meta name="robots" content="noindex, follow" data-gml-translation-status="incomplete">' . "\n";
            if ( stripos( $html, '</head>' ) !== false ) {
                return preg_replace( '#</head>#i', $tag . '</head>', $html, 1 );
            }
            return $tag . $html;
        }

        /**
         * Shared translated HTML is only safe for anonymous, cacheable GET
         * requests that carry no commerce/session identity or attribution data.
         */
        protected function can_use_page_cache() {
            if ( is_user_logged_in() || strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) !== 'GET' ) {
                return false;
            }
            if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
                return false;
            }
            if ( GML_Page_Cache::has_tracking_parameters( $_SERVER['REQUEST_URI'] ?? '/' ) ) {
                return false;
            }

            foreach ( array_keys( (array) $_COOKIE ) as $cookie_name ) {
                $cookie_name = strtolower( (string) $cookie_name );
                foreach ( [ 'wordpress_logged_in_', 'wp-postpass_', 'comment_author_', 'wp_woocommerce_session_', 'woocommerce_', 'edd_cart', 'edd_saved_cart', 'phpsessid' ] as $prefix ) {
                    if ( strpos( $cookie_name, $prefix ) === 0 ) {
                        return false;
                    }
                }
            }

            foreach ( [ 'is_cart', 'is_checkout', 'is_account_page' ] as $conditional ) {
                if ( function_exists( $conditional ) && call_user_func( $conditional ) ) {
                    return false;
                }
            }
            if ( function_exists( 'post_password_required' ) && post_password_required() ) {
                return false;
            }
            return true;
        }

        /**
         * Do not persist responses containing request-bound security tokens or
         * headers that explicitly prohibit shared caching.
         */
        protected function html_is_cache_safe( $html ) {
            if ( function_exists( 'headers_list' ) ) {
                foreach ( headers_list() as $header ) {
                    if ( preg_match( '/^set-cookie\s*:/i', $header ) || preg_match( '/^cache-control\s*:.*(?:private|no-store|no-cache)/i', $header ) ) {
                        return false;
                    }
                }
            }

            return ! preg_match(
                '/(?:\b_wpnonce\b|\bwp_nonce\b|\bcsrf\b|\bnonce\s*=|g-recaptcha|h-captcha|cf-turnstile)/i',
                (string) $html
            );
        }

        /**
         * Parse php.ini memory_limit into bytes.
         */
        protected function get_memory_limit_bytes() {
            $limit = ini_get( 'memory_limit' );
            if ( $limit === '-1' || $limit === '' || $limit === false ) {
                return 0; // unlimited or unknown
            }
            $limit = trim( $limit );
            $last  = strtolower( substr( $limit, -1 ) );
            $val   = (int) $limit;
            switch ( $last ) {
                case 'g': $val *= 1024;
                case 'm': $val *= 1024;
                case 'k': $val *= 1024;
            }
            return $val;
        }


    /**
     * Extract all elements with translate="no" from the HTML string,
     * replacing each with a unique placeholder token.
     *
     * Uses a depth-counter approach to handle nested tags correctly.
     * Also safely skips <script> and <style> blocks whose content may
     * contain '>' characters that would confuse a naive tag parser.
     *
     * Returns [ $html_with_placeholders, [ placeholder => original_html ] ]
     */
    protected function extract_no_translate_blocks( $html ) {
        $placeholders = [];
        $counter      = 0;
        $result       = '';
        $pos          = 0;
        $len          = strlen( $html );

        // Tags whose raw content must be copied verbatim (may contain '<' and '>')
        $raw_tags = [ 'script', 'style', 'noscript', 'textarea' ];

        while ( $pos < $len ) {
            // Find next '<'
            $lt = strpos( $html, '<', $pos );
            if ( $lt === false ) {
                $result .= substr( $html, $pos );
                break;
            }

            // Copy everything before '<'
            if ( $lt > $pos ) {
                $result .= substr( $html, $pos, $lt - $pos );
            }

            if ( substr( $html, $lt, 4 ) === '<!--' ) {
                $end = strpos( $html, '-->', $lt + 4 );
                if ( $end === false ) {
                    $result .= substr( $html, $lt );
                    break;
                }
                $result .= substr( $html, $lt, $end - $lt + 3 );
                $pos = $end + 3;
                continue;
            }

            // Find the end of this tag
            $gt = $this->find_tag_end( $html, $lt );
            if ( $gt === false ) {
                // Malformed — copy rest as-is
                $result .= substr( $html, $lt );
                break;
            }

            $tag_str = substr( $html, $lt, $gt - $lt + 1 );

            if ( ! preg_match( '/^<\/?([a-zA-Z][a-zA-Z0-9]*)/', $tag_str, $nm ) ) {
                $result .= $tag_str;
                $pos = $gt + 1;
                continue;
            }

            $tag_name_raw = $nm[1];
            $tag_name     = strtolower( $tag_name_raw );
            $is_closing   = ( $tag_str[1] === '/' );
            $is_self_close = ( substr( rtrim( $tag_str ), -2 ) === '/>' );

            // ── Raw content tags (script/style/etc.) — copy verbatim ──────────
            if ( ! $is_closing && in_array( $tag_name, $raw_tags, true ) ) {
                // Use regex to find the real closing tag (not one inside a JS string like "</script>")
                // The real closing tag is: </ + tagname + optional whitespace + >
                $close_pattern = '#</' . preg_quote( $tag_name, '#' ) . '\s*>#i';
                if ( ! preg_match( $close_pattern, $html, $close_m, PREG_OFFSET_CAPTURE, $gt + 1 ) ) {
                    // No closing tag — copy to end
                    $result .= substr( $html, $lt );
                    break;
                }
                $close_end = $close_m[0][1] + strlen( $close_m[0][0] ) - 1;
                $result .= substr( $html, $lt, $close_end - $lt + 1 );
                $pos = $close_end + 1;
                continue;
            }

            // ── Closing or self-closing tags — copy as-is ────────────────────
            if ( $is_closing || $is_self_close ) {
                $result .= $tag_str;
                $pos = $gt + 1;
                continue;
            }

            // ── Check for translate="no" ──────────────────────────────────────
            if ( ! preg_match( '/\btranslate\s*=\s*["\']no["\']/i', $tag_str ) ) {
                $result .= $tag_str;
                $pos = $gt + 1;
                continue;
            }

            // ── Found translate="no" — extract full block with depth counter ──
            $block_start = $lt;
            $depth       = 1;
            $j           = $gt + 1;

            while ( $j < $len && $depth > 0 ) {
                $next_lt = strpos( $html, '<', $j );
                if ( $next_lt === false ) break;

                $next_gt = $this->find_tag_end( $html, $next_lt );
                if ( $next_gt === false ) break;

                $next_tag = substr( $html, $next_lt, $next_gt - $next_lt + 1 );

                // Skip comments inside the block
                if ( substr( $next_tag, 0, 4 ) === '<!--' ) {
                    $end = strpos( $html, '-->', $next_lt );
                    $j   = $end !== false ? $end + 3 : $next_gt + 1;
                    continue;
                }

                if ( ! preg_match( '/^<\/?([a-zA-Z][a-zA-Z0-9]*)/', $next_tag, $nm2 ) ) {
                    $j = $next_gt + 1;
                    continue;
                }

                $inner_name = strtolower( $nm2[1] );

                // Skip raw-content tags inside the block (their content may contain '>')
                if ( ! ( $next_tag[1] === '/' ) && in_array( $inner_name, $raw_tags, true ) ) {
                    $inner_close_pattern = '#</' . preg_quote( $inner_name, '#' ) . '\s*>#i';
                    if ( ! preg_match( $inner_close_pattern, $html, $inner_m, PREG_OFFSET_CAPTURE, $next_gt + 1 ) ) {
                        $j = $len;
                        break;
                    }
                    $j = $inner_m[0][1] + strlen( $inner_m[0][0] );
                    continue;
                }

                if ( $inner_name === $tag_name ) {
                    if ( $next_tag[1] === '/' ) {
                        $depth--;
                    } elseif ( substr( rtrim( $next_tag ), -2 ) !== '/>' ) {
                        $depth++;
                    }
                }
                $j = $next_gt + 1;
            }

            $block = substr( $html, $block_start, $j - $block_start );
            $token = '<!--GML_NOTRANSLATE_' . $counter . '_' . md5( $block ) . '-->';
            $placeholders[ $token ] = $block;
            $counter++;
            $result .= $token;
            $pos = $j;
        }

        return [ $result, $placeholders ];
    }

    // ── Server-side link rewriting ───────────────────────────────────────────

    /**
     * Rewrite internal links in the final HTML to include the language prefix.
     *
     * WordPress generates all menu links, pagination links, breadcrumbs, etc.
     * pointing to the source language URL (e.g. /about/, /shop/page/2/).
     * Previously we relied on client-side JS (rewriteLinks) to add the prefix,
     * but this caused intermittent redirects to the source language when:
     *   - The user clicked a link before DOMContentLoaded fired
     *   - JS was delayed by slow network or large page
     *   - JS failed due to a conflict with another script
     *
     * By rewriting links server-side, the browser receives HTML with correct
     * URLs already in place. The JS rewriter remains as a safety net for
     * dynamically injected content (AJAX, mega-menus, etc.).
     *
     * Strategy:
     *   - Match href="..." and action="..." attributes
     *   - Only rewrite internal URLs (same origin, no admin/login paths)
     *   - Skip URLs that already have a language prefix
     *   - Skip links inside .gml-language-switcher (already correct)
     *   - Skip non-HTTP schemes (mailto:, tel:, javascript:, #)
     */
    protected function rewrite_internal_links( $html ) {
        $home_url    = home_url();
        $home_origin = rtrim( $home_url, '/' );
        $prefix      = '/' . $this->target_lang . '/';

        // Build language code pattern for detecting existing prefixes.
        $all_langs = class_exists( 'GML_Language_Utils' )
            ? GML_Language_Utils::configured_codes( true, false )
            : array_unique( array_merge( [ get_option( 'gml_source_lang', 'en' ) ], array_column( get_option( 'gml_languages', [] ), 'code' ) ) );
        $lang_pattern = class_exists( 'GML_Language_Utils' )
            ? GML_Language_Utils::language_pattern( $all_langs )
            : implode( '|', array_map( 'preg_quote', array_unique( $all_langs ) ) );

        // NOTE: The language switcher (class="gml-language-switcher" translate="no")
        // has already been extracted by extract_no_translate_blocks() before this
        // method runs, so its links are safely hidden inside placeholder tokens
        // and will NOT be rewritten here.

        return $this->map_html_tags(
            $html,
            function( $tag ) use ( $home_origin, $prefix, $lang_pattern ) {
                if ( preg_match( '/^<a\b/i', $tag ) ) {
                    return $this->rewrite_tag_url_attribute( $tag, 'a', 'href', $home_origin, $prefix, $lang_pattern );
                }
                if ( preg_match( '/^<form\b/i', $tag ) ) {
                    return $this->rewrite_tag_url_attribute( $tag, 'form', 'action', $home_origin, $prefix, $lang_pattern );
                }
                return $tag;
            }
        );
    }

    /**
     * Rewrite one URL value; return the original when it is not a safe public
     * link inside this WordPress installation.
     */
    protected function rewrite_single_url( $url, $home_origin, $prefix, $lang_pattern ) {
        // Skip empty, anchors, non-http schemes
        if ( $url === '' || $url[0] === '#' ) {
            return $url;
        }
        if ( preg_match( '/^(mailto:|tel:|javascript:|data:)/i', $url ) ) {
            return $url;
        }

        $path = null;

        $is_absolute = preg_match( '#^https?://#i', $url ) || strpos( $url, '//' ) === 0;
        if ( $is_absolute ) {
            if ( class_exists( 'GML_URL_Helper' ) ) {
                $path = GML_URL_Helper::internal_absolute_path( $url );
            } else {
                $url_parts  = wp_parse_url( $url );
                $home_parts = wp_parse_url( $home_origin );
                $path = is_array( $url_parts ) && is_array( $home_parts )
                    && strtolower( (string) ( $url_parts['host'] ?? '' ) ) === strtolower( (string) ( $home_parts['host'] ?? '' ) )
                    ? ( $url_parts['path'] ?? '/' )
                    : null;
            }
            if ( $path === null ) {
                return $url; // external or outside this WordPress installation
            }
        } elseif ( $url[0] === '/' ) {
            $path = $url;
        } else {
            return $url; // relative — skip
        }

        if ( $path[0] !== '/' ) {
            $path = '/' . $path;
        }

        // Work with a path relative to home_url(). This prevents root-relative
        // links on subdirectory installs becoming /lang/subdirectory/... .
        if ( class_exists( 'GML_URL_Helper' ) ) {
            $path = GML_URL_Helper::strip_home_path( $path );
        }

        // Skip WordPress system paths
        if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-json|wp-cron|wp-content|wp-includes)(/|$|\?)#i', $path ) ) {
            return $url;
        }

        // Skip if already has a language prefix
        if ( preg_match( '#^/(' . $lang_pattern . ')(/|$|\?)#', $path ) ) {
            return $url;
        }

        // Skip WooCommerce AJAX / WordPress admin-ajax
        if ( preg_match( '#[?&](wc-ajax|action)=#i', $url ) ) {
            return $url;
        }

        // Skip feed URLs
        if ( preg_match( '#^/feed(/|$)#i', $path ) || preg_match( '#/feed/?$#i', $path ) ) {
            return $url;
        }

        $new_path = $prefix . ltrim( $path, '/' );

        if ( $is_absolute ) {
            $new_url = home_url( $new_path );
        } else {
            $new_url = class_exists( 'GML_URL_Helper' )
                ? GML_URL_Helper::to_root_relative_path( $new_path )
                : $new_path;
        }

        return $new_url;
    }

    /**
     * Scan real attributes outside quoted builder data and replace one URL.
     */
    protected function rewrite_tag_url_attribute( $tag, $tag_name, $attribute_name, $home_origin, $prefix, $lang_pattern ) {
        $length = strlen( $tag );
        $cursor = 1 + strlen( $tag_name );

        while ( $cursor < $length ) {
            while ( $cursor < $length && ctype_space( $tag[ $cursor ] ) ) {
                $cursor++;
            }
            if ( $cursor >= $length || $tag[ $cursor ] === '>' || $tag[ $cursor ] === '/' ) {
                break;
            }

            $name_start = $cursor;
            while ( $cursor < $length && ! ctype_space( $tag[ $cursor ] ) && ! in_array( $tag[ $cursor ], [ '=', '/', '>' ], true ) ) {
                $cursor++;
            }
            $name = substr( $tag, $name_start, $cursor - $name_start );
            while ( $cursor < $length && ctype_space( $tag[ $cursor ] ) ) {
                $cursor++;
            }
            if ( $cursor >= $length || $tag[ $cursor ] !== '=' ) {
                continue;
            }
            $cursor++;
            while ( $cursor < $length && ctype_space( $tag[ $cursor ] ) ) {
                $cursor++;
            }
            if ( $cursor >= $length ) {
                break;
            }

            $quote = in_array( $tag[ $cursor ], [ '"', "'" ], true ) ? $tag[ $cursor ] : '';
            if ( $quote !== '' ) {
                $value_start = ++$cursor;
                while ( $cursor < $length && $tag[ $cursor ] !== $quote ) {
                    $cursor++;
                }
            } else {
                $value_start = $cursor;
                while ( $cursor < $length && ! ctype_space( $tag[ $cursor ] ) && $tag[ $cursor ] !== '>' ) {
                    $cursor++;
                }
            }
            $value_end = $cursor;

            if ( strcasecmp( $name, $attribute_name ) === 0 ) {
                $url = substr( $tag, $value_start, $value_end - $value_start );
                $new_url = $this->rewrite_single_url( $url, $home_origin, $prefix, $lang_pattern );
                return $new_url === $url
                    ? $tag
                    : substr_replace( $tag, $new_url, $value_start, $value_end - $value_start );
            }

            if ( $quote !== '' && $cursor < $length ) {
                $cursor++;
            }
        }

        return $tag;
    }

    /**
     * Map complete HTML tags without treating a quoted > as a tag boundary.
     * Raw script/style/SVG blocks and comments remain byte-for-byte unchanged.
     */
    protected function map_html_tags( $html, callable $callback ) {
        $html     = (string) $html;
        $length   = strlen( $html );
        $offset   = 0;
        $result   = '';
        $raw_tags = [ 'script', 'style', 'noscript', 'textarea', 'svg', 'iframe' ];

        while ( $offset < $length ) {
            $start = strpos( $html, '<', $offset );
            if ( $start === false ) {
                $result .= substr( $html, $offset );
                break;
            }
            $result .= substr( $html, $offset, $start - $offset );

            if ( substr( $html, $start, 4 ) === '<!--' ) {
                $comment_end = strpos( $html, '-->', $start + 4 );
                if ( $comment_end === false ) {
                    $result .= substr( $html, $start );
                    break;
                }
                $result .= substr( $html, $start, $comment_end - $start + 3 );
                $offset = $comment_end + 3;
                continue;
            }

            $end = $this->find_tag_end( $html, $start );
            if ( $end === false ) {
                $result .= substr( $html, $start );
                break;
            }
            $tag = substr( $html, $start, $end - $start + 1 );

            if ( preg_match( '/^<([a-zA-Z][a-zA-Z0-9]*)\b/', $tag, $match ) && in_array( strtolower( $match[1] ), $raw_tags, true ) ) {
                $close_pattern = '#</' . preg_quote( $match[1], '#' ) . '\s*>#i';
                if ( preg_match( $close_pattern, $html, $close, PREG_OFFSET_CAPTURE, $end + 1 ) ) {
                    $raw_end = $close[0][1] + strlen( $close[0][0] );
                    $result .= substr( $html, $start, $raw_end - $start );
                    $offset = $raw_end;
                    continue;
                }
            }

            $result .= call_user_func( $callback, $tag );
            $offset = $end + 1;
        }

        return $result;
    }

    protected function find_tag_end( $html, $start ) {
        $length = strlen( $html );
        $quote  = '';
        for ( $cursor = $start + 1; $cursor < $length; $cursor++ ) {
            $char = $html[ $cursor ];
            if ( $quote !== '' ) {
                if ( $char === $quote && $html[ $cursor - 1 ] !== '\\' ) {
                    $quote = '';
                }
                continue;
            }
            if ( $char === '"' || $char === "'" ) {
                $quote = $char;
            } elseif ( $char === '>' ) {
                return $cursor;
            }
        }
        return false;
    }

    // ── Language detection ────────────────────────────────────────────────────

    /**
     * Detect the language the visitor wants.
     *
     * ONLY the URL prefix triggers translation (/ru/, /en/, …).
     * We deliberately ignore cookies and Accept-Language headers here —
     * a visitor on /about/ always sees the source language regardless of
     * what language they previously browsed. This matches how Weglot works:
     * the URL is the single source of truth for which language is served.
     *
     * The cookie is still written by the SEO router (so the language switcher
     * can highlight the active language), but it must NOT cause translation
     * on non-prefixed URLs.
     */
    protected function detect_target_language() {
        return $this->get_url_language() ?? $this->source_lang;
    }

    /**
     * Extract language code from REQUEST_URI, e.g. /ru/about/ → 'ru'.
     * Returns null if the URL has no recognised language prefix.
     */
    protected function get_url_language() {
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        $path = strtok( $uri, '?' );
        if ( class_exists( 'GML_Language_Utils' ) ) {
            return GML_Language_Utils::detect_prefix_from_path( $path, true ) ?: null;
        }
        if ( preg_match( '#^/([a-z]{2})(/|$)#', $path, $m ) && $this->is_enabled_language( $m[1] ) ) {
            return $m[1];
        }
        return null;
    }

    protected function is_enabled_language( $lang ) {
        $configured = get_option( 'gml_languages', [] );
        foreach ( $configured as $l ) {
            if ( ( $l['enabled'] ?? true ) && $l['code'] === $lang ) {
                return true;
            }
        }
        return false;
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    protected function should_skip() {
        // Admin pages
        if ( is_admin() ) {
            return true;
        }
        // AJAX
        if ( wp_doing_ajax() ) {
            return true;
        }
        // REST API
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }
        // Internal crawler request — the public query flag alone is never
        // trusted; a site-secret request header must also be present.
        if (
            isset( $_GET['gml_crawl'] ) &&
            $_GET['gml_crawl'] === '1' &&
            class_exists( 'GML_Content_Crawler' ) &&
            GML_Content_Crawler::is_internal_request()
        ) {
            return true;
        }
        // Login / register
        $pagenow = $GLOBALS['pagenow'] ?? '';
        if ( in_array( $pagenow, [ 'wp-login.php', 'wp-register.php' ], true ) ) {
            return true;
        }
        // Translation not yet started by admin
        if ( class_exists( 'GML_Translation_State' ) && ! GML_Translation_State::multilingual_enabled() ) {
            return true;
        }
        // Page builder editor modes — never translate inside live editors
        if ( $this->is_page_builder_editor() ) {
            return true;
        }
        // Non-HTML response (feeds, JSON, etc.)
        if ( ! $this->is_html_response() ) {
            return true;
        }
        // Exclusion rules — check if this URL is excluded from translation
        if ( class_exists( 'GML_Exclusion_Rules' ) ) {
            $exclusion = new GML_Exclusion_Rules();
            if ( $exclusion->is_page_excluded() ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Detect whether the current request is a page builder live editor session.
     * Translation must be disabled in all editor contexts to avoid corrupting
     * the builder's preview iframe or AJAX responses.
     *
     * Covers: Elementor, Beaver Builder, Divi, Bricks, WPBakery, Oxygen, Breakdance.
     */
    protected function is_page_builder_editor() {
        // Elementor: sets a query var when in editor preview
        if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) {
            return true;
        }
        // Elementor editor action (used in AJAX calls from the editor)
        if ( isset( $_GET['action'] ) && strpos( $_GET['action'], 'elementor' ) !== false ) {
            return true;
        }
        // Beaver Builder: ?fl_builder in URL
        if ( isset( $_GET['fl_builder'] ) ) {
            return true;
        }
        // Divi Visual Builder: ?et_fb=1 or ?et_pb_preview
        if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_pb_preview'] ) ) {
            return true;
        }
        // WPBakery (Visual Composer): ?vc_action or ?vc_editable
        if ( isset( $_GET['vc_action'] ) || isset( $_GET['vc_editable'] ) ) {
            return true;
        }
        // Bricks Builder: ?bricks=run
        if ( isset( $_GET['bricks'] ) ) {
            return true;
        }
        // Oxygen Builder: ?ct_builder
        if ( isset( $_GET['ct_builder'] ) ) {
            return true;
        }
        // Breakdance Builder: ?breakdance=builder
        if ( isset( $_GET['breakdance'] ) ) {
            return true;
        }
        // Generic: any request with ?builder or ?preview=true from known builders
        if ( isset( $_GET['builder'] ) ) {
            return true;
        }
        return false;
    }

    protected function is_html_response() {
        foreach ( headers_list() as $header ) {
            if ( stripos( $header, 'Content-Type:' ) === 0 ) {
                return stripos( $header, 'text/html' ) !== false;
            }
        }
        return true; // assume HTML if no Content-Type header yet
    }

    protected function is_html( $content ) {
        return stripos( $content, '<html' ) !== false
            || stripos( $content, '<!DOCTYPE' ) !== false;
    }
}
