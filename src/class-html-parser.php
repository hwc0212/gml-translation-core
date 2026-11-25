<?php
/**
 * GML HTML Parser — regex-based text extraction, DOMDocument for node location only.
 *
 * Strategy: use DOMDocument to FIND translatable text, but do NOT use saveHTML()
 * to rebuild the page (it truncates on some libxml versions).
 * Instead we return a replacement map and apply it to the original HTML string.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-translation-text.php';
require_once __DIR__ . '/class-breadcrumb-jsonld.php';

class GML_HTML_Parser {

    private $skip_tags = [ 'script', 'style', 'code', 'pre', 'svg', 'noscript', 'iframe' ];
    private $text_attrs = [ 'alt', 'placeholder', 'aria-label', 'aria-description' ];
    private $seo_meta_names = [
        'title', 'description', 'keywords',
        'og:title', 'og:description', 'og:site_name',
        'twitter:title', 'twitter:description',
    ];
    private $protected_terms = [];

    public function __construct() {
        $this->protected_terms = get_option( 'gml_protected_terms', [
            'GML', 'WordPress', 'WooCommerce', 'Gemini',
        ] );
    }

    /**
     * Parse HTML — returns [ 'html' => original_html, 'nodes' => [...] ]
     * We use DOMDocument only to walk the tree; we never call saveHTML().
     */
    public function parse( $html ) {
        libxml_use_internal_errors( true );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $encoded = mb_encode_numericentity( $html, [ 0x80, 0x10FFFF, 0, 0x1FFFFF ], 'UTF-8' );
        $dom->loadHTML( $encoded, LIBXML_HTML_NODEFDTD | LIBXML_COMPACT );
        libxml_clear_errors();

        $nodes = [];
        if ( $dom->documentElement ) {
            $this->walk( $dom->documentElement, $nodes );
        }

        return [ 'html' => $html, 'nodes' => $nodes ];
    }

    /**
     * Apply translations to the original HTML string.
     * $parsed['replacements'] = [ original_text => translated_text ]
     *
     * IMPORTANT: Before replacing visible text, we precisely translate the
     * allowlisted human-readable attributes and then tokenise every complete
     * HTML tag. URLs, code, CSS, SVG, attributes, and tag names are therefore
     * invisible to the broad text replacement pass.
     *
     * This is the fix for: /ru/product/portable-childrens-Имя-hanging-buckle/
     * and for broken image src / srcset after translation.
     */
    public function rebuild( $parsed ) {
        $html         = $parsed['html'];
        $replacements = $parsed['replacements'] ?? [];

        if ( empty( $replacements ) ) {
            return $html;
        }

        $tokens = [];
        $tokenise = static function( $markup ) use ( &$tokens ) {
            $token = '<!--GMLURL_' . count( $tokens ) . '_' . md5( $markup ) . '-->';
            $tokens[ $token ] = $markup;
            return $token;
        };
        $html = $this->tokenize_markup( $html, $replacements, $tokenise );

        $safe_replacements = [];
        foreach ( $replacements as $original => $translated ) {
            if ( $original === $translated ) continue;

            // Remove unambiguous Markdown wrappers without altering dimensions.
            $translated = GML_Translation_Text::clean_markdown_wrappers( $translated );
            // Translation records represent text nodes, never markup. Strip
            // any legacy/manual HTML and encode the final value before it is
            // inserted back into the raw document.
            $translated = html_entity_decode( GML_Translation_Text::plain_text( trim( $translated ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            // ── Preserve leading/trailing decorative symbols ──────────────────
            // Gemini often strips decorative Unicode symbols (✔, ✓, ★, ●, ▶, →,
            // ✦, ♦, etc.) that appear at the start or end of text nodes.
            // If the original has them but the translation doesn't, we re-attach
            // them so the visual presentation stays intact.
            $leading  = '';
            $trailing = '';
            if ( preg_match( '/^(\s*[\x{2000}-\x{27FF}\x{2900}-\x{2BFF}\x{FE00}-\x{FEFF}\x{1F300}-\x{1F9FF}]+\s*)/u', $original, $lm ) ) {
                $leading = $lm[1];
                // Check if translation already starts with the same symbols
                if ( mb_strpos( $translated, trim( $leading ) ) !== 0 ) {
                    $translated = $leading . $translated;
                }
            }
            if ( preg_match( '/(\s*[\x{2000}-\x{27FF}\x{2900}-\x{2BFF}\x{FE00}-\x{FEFF}\x{1F300}-\x{1F9FF}]+\s*)$/u', $original, $tm ) ) {
                $trailing = $tm[1];
                $trimmed_trailing = trim( $trailing );
                if ( mb_strrpos( $translated, $trimmed_trailing ) !== mb_strlen( $translated ) - mb_strlen( $trimmed_trailing ) ) {
                    $translated = $translated . $trailing;
                }
            }

            $safe_translated = htmlspecialchars( $translated, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            $safe_replacements[ $original ] = $safe_translated;
        }

        // All markup is tokenized above. Match a whole decoded text node once,
        // just as walk() does, without serializing or reformatting the DOM.
        // This handles mixed named/numeric entities and prevents replacements
        // from cascading through a previously translated value.
        $parts = preg_split( '/(<!--GMLURL_[0-9]+_[a-f0-9]{32}-->)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        foreach ( $parts as &$part ) {
            if ( isset( $tokens[$part] ) ) continue;
            $decoded = html_entity_decode( $part, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $key = trim( $decoded );
            if ( ! array_key_exists( $key, $safe_replacements ) ) continue;
            $leading = strlen( $part ) - strlen( ltrim( $part ) );
            $trailing = strlen( $part ) - strlen( rtrim( $part ) );
            $part = substr( $part, 0, $leading ) . $safe_replacements[$key]
                . ( $trailing ? substr( $part, -$trailing ) : '' );
        }
        unset( $part );
        $html = implode( '', $parts );

        // ── Step 2b: precise title replacement ────────────────────────────────
        return strtr( $html, $tokens );
    }

    /**
     * Translate the small allowlist of human-readable HTML attributes before
     * all markup is tokenised. Everything else inside a tag remains byte-for-
     * byte unchanged.
     */
    private function tokenize_markup( $html, array $replacements, callable $tokenise ) {
        $stack = [];
        $context = [ 'text_excluded' => false, 'tag_excluded' => false ];
        $void_tags = array_merge( $this->skip_tags, [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr', 'textarea', 'title' ] );
        $selectors = get_option( 'gml_exclude_selectors', [] );
        if ( class_exists( 'GML_Exclusion_Rules' ) ) {
            $selectors = array_merge( $selectors, ( new GML_Exclusion_Rules() )->get_excluded_selectors() );
        }
        return $this->map_html_tags( $html, function( $tag ) use ( $replacements, &$stack, $void_tags, $selectors, &$context ) {
            if ( ! preg_match( '~^<(/?)([a-zA-Z][a-zA-Z0-9:-]*)\b~', $tag, $name ) ) return $tag;
            $name_text = strtolower( $name[2] );
            if ( $name[1] === '/' ) {
                for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
                    if ( $stack[$i][0] === $name_text ) { $stack = array_slice( $stack, 0, $i ); break; }
                }
                $context['text_excluded'] = $stack && $stack[count($stack) - 1][1];
                $context['tag_excluded'] = false;
                return $tag;
            }
            // Consume whole attributes so data-value or quoted JSON cannot be
            // mistaken for a control's actual value/type attribute.
            $pattern = '~(\s+)([^\s=/>]+)(\s*=\s*)(?:(["\'])(.*?)\4|([^\s>]+))~s';
            preg_match_all( $pattern, $tag, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL );
            $attributes = [];
            foreach ( $matches as $attribute ) {
                $key = strtolower( $attribute[2] );
                if ( ! array_key_exists( $key, $attributes ) ) {
                    $attributes[$key] = html_entity_decode( $attribute[4] !== null ? $attribute[5] : $attribute[6], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                }
            }
            $classes = preg_split( '/\s+/', $attributes['class'] ?? '' );
            $excluded = ( $stack && $stack[count($stack) - 1][1] )
                || strtolower( $attributes['translate'] ?? '' ) === 'no'
                || in_array( 'notranslate', $classes, true );
            foreach ( $selectors as $selector ) {
                $selector = trim( $selector );
                if ( $selector === '' ) continue;
                if ( $selector[0] === '.' && in_array( substr( $selector, 1 ), $classes, true ) ) $excluded = true;
                if ( $selector[0] === '#' && ( $attributes['id'] ?? '' ) === substr( $selector, 1 ) ) $excluded = true;
            }
            if ( ! in_array( $name_text, $void_tags, true ) ) $stack[] = [ $name_text, $excluded ];
            $context['text_excluded'] = $stack && $stack[count($stack) - 1][1];
            $context['tag_excluded'] = $excluded;
            if ( $excluded || in_array( $name_text, $this->skip_tags, true ) ) return $tag;
            $allowed = $this->text_attrs;
            if ( $name_text === 'meta' ) {
                $meta_name = strtolower( ( $attributes['name'] ?? '' ) ?: ( $attributes['property'] ?? '' ) );
                if ( in_array( $meta_name, $this->seo_meta_names, true ) ) $allowed[] = 'content';
            }
            if ( $name_text === 'input' && $this->input_has_ui_value( $attributes['type'] ?? '', $attributes['name'] ?? '' ) ) {
                $allowed[] = 'value';
            }
            return preg_replace_callback( $pattern, function( $attribute ) use ( $replacements, $allowed ) {
                if ( ! in_array( strtolower( $attribute[2] ), $allowed, true ) ) return $attribute[0];
                $raw = $attribute[4] !== null ? $attribute[5] : $attribute[6];
                $translated = $this->translated_attribute_value( $raw, $replacements );
                if ( $translated === $raw ) return $attribute[0];
                $quote = $attribute[4] !== null ? $attribute[4] : '"';
                return $attribute[1] . $attribute[2] . $attribute[3] . $quote . $translated . $quote;
            }, $tag, -1, $count, PREG_UNMATCHED_AS_NULL );
        }, $tokenise, $replacements, $context );
    }

    private function input_has_ui_value( $type, $name ) {
        $type = strtolower( $type );
        // Named submit values can be successful controls used for routing.
        return in_array( $type, [ 'button', 'reset' ], true )
            || ( $type === 'submit' && $name === '' );
    }

    /**
     * Transform complete HTML tags while respecting quoted > characters used
     * by page-builder JSON and inline configuration attributes.
     */
    private function map_html_tags( $html, callable $callback, callable $tokenise, array $replacements, array &$context ) {
        $html   = (string) $html;
        $length = strlen( $html );
        $offset = 0;
        $result = '';

        while ( $offset < $length ) {
            $start = strpos( $html, '<', $offset );
            if ( $start === false ) {
                $text = substr( $html, $offset );
                $result .= $context['text_excluded'] && $text !== '' ? $tokenise( $text ) : $text;
                break;
            }
            $text = substr( $html, $offset, $start - $offset );
            $result .= $context['text_excluded'] && $text !== '' ? $tokenise( $text ) : $text;

            if ( substr( $html, $start, 4 ) === '<!--' ) {
                $close = strpos( $html, '-->', $start + 4 );
                if ( $close === false ) { $result .= $tokenise( substr( $html, $start ) ); break; }
                $offset = $close + 3;
                $result .= $tokenise( substr( $html, $start, $offset - $start ) );
                continue;
            }

            // A comparison sign before a number or whitespace is visible text,
            // not an HTML tag. Keep it in the same replacement segment.
            if ( ! preg_match( '~^<(?:/?[a-zA-Z]|!|\\?)~', substr( $html, $start, 3 ) ) ) {
                $result .= $context['text_excluded'] ? $tokenise('<') : '<';
                $offset = $start + 1;
                continue;
            }

            $quote = '';
            $end   = $start + 1;
            for ( ; $end < $length; $end++ ) {
                $char = $html[ $end ];
                if ( $quote !== '' ) {
                    // HTML attributes do not use backslash quote escaping.
                    if ( $char === $quote ) {
                        $quote = '';
                    }
                    continue;
                }
                if ( $char === '"' || $char === "'" ) {
                    $quote = $char;
                    continue;
                }
                if ( $char === '>' ) {
                    break;
                }
            }

            if ( $end >= $length ) {
                $result .= $tokenise( substr( $html, $start ) );
                break;
            }

            $tag = substr( $html, $start, $end - $start + 1 );
            $mapped_tag = call_user_func( $callback, $tag );
            $offset = $end + 1;
            if ( preg_match( '~^<(textarea|script|style|code|pre|svg|noscript|iframe|title)(?=[\s/>])~i', $tag, $raw ) ) {
                $name = strtolower( $raw[1] );
                if ( $name === 'svg' && preg_match( '~/\s*>$~', $tag ) ) {
                    $result .= $tokenise( $tag );
                    continue;
                }
                if ( $name === 'svg' ) {
                    $raw_end = self::svg_end( $html, $offset );
                    $result .= $tokenise( $tag . substr( $html, $offset, $raw_end - $offset ) );
                    $offset = $raw_end;
                    continue;
                }
                $raw_end = $length;
                if ( preg_match( '~</' . $name . '\s*>~i', $html, $close, PREG_OFFSET_CAPTURE, $offset ) ) {
                    $close_start = $close[0][1];
                    $raw_end = $close_start + strlen( $close[0][0] );
                    $contents = substr( $html, $offset, $close_start - $offset );
                    if ( $name === 'title' && ! $context['tag_excluded'] ) {
                        $contents = $this->translated_attribute_value( $contents, $replacements, true );
                        $mapped_tag = $tag;
                    }
                    if ( $name === 'script' && ! $context['tag_excluded'] && GML_Breadcrumb_JSONLD::is_script_tag( $tag ) ) {
                        $contents = GML_Breadcrumb_JSONLD::map( $contents, static function( $name ) use ( $replacements ) {
                            $key = GML_Breadcrumb_JSONLD::text( $name );
                            if ( ! isset($replacements[$key]) || trim((string)$replacements[$key]) === '' ) return $name;
                            return GML_Breadcrumb_JSONLD::text( GML_Translation_Text::clean_markdown_wrappers( (string)$replacements[$key] ) );
                        } );
                    }
                    $block = $mapped_tag . $contents . $close[0][0];
                } else {
                    $block = $mapped_tag . substr( $html, $offset );
                }
                $result .= $tokenise( $block );
                $offset = $raw_end;
                continue;
            }
            $result .= $tokenise( $mapped_tag );
        }

        return $result;
    }

    /** HTML permits a literal less-than sign when it does not start markup. */
    public static function is_markup_start( $html, $offset ) {
        return (bool) preg_match( '~^<(?:/?[a-zA-Z]|!|\\?)~', substr( $html, $offset, 3 ) );
    }

    /** SVG may nest, and may contain text that looks like a closing tag. */
    public static function svg_end( $html, $offset ) {
        $pattern = '~<!--.*?-->|<!\[CDATA\[.*?\]\]>|<(/?)([a-z][a-z0-9:-]*)(?=[\s/>])(?:[^<>"\']++|"[^"]*"|\'[^\']*\')*>~is';
        $depth = 1;
        while ( preg_match( $pattern, $html, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
            $offset = $match[0][1] + strlen( $match[0][0] );
            $name = strtolower( $match[2][0] ?? '' );
            if ( $name === 'svg' ) {
                if ( $match[1][0] === '/' ) {
                    if ( --$depth === 0 ) return $offset;
                } elseif ( ! preg_match( '~/\s*>$~', $match[0][0] ) ) {
                    $depth++;
                }
            } elseif ( in_array( $name, [ 'script', 'style' ], true ) && $match[1][0] !== '/'
                && ! preg_match( '~/\s*>$~', $match[0][0] )
                && preg_match( '~</' . $name . '\s*>~i', $html, $end, PREG_OFFSET_CAPTURE, $offset ) ) {
                $offset = $end[0][1] + strlen( $end[0][0] );
            }
        }
        return strlen( $html );
    }

    private function translated_attribute_value( $raw, array $replacements, $title = false ) {
        $decoded = html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $key = array_key_exists( $raw, $replacements )
            ? $raw
            : ( array_key_exists( trim( $decoded ), $replacements ) ? trim( $decoded ) : null );
        if ( $key === null || trim( (string) $replacements[ $key ] ) === '' ) {
            return $raw;
        }

        $translated = GML_Translation_Text::plain_text( (string) $replacements[ $key ] );
        $translated = GML_Translation_Text::clean_markdown_wrappers( $translated );
        $translated = html_entity_decode( trim( $translated ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( $title ) {
            if ( $replacements[ $key ] === $key ) return $raw;
            $translated = trim( preg_replace( '/\s*[-–—]?\s*Description\s*:\s*.+$/si', '', $translated ) );
        }
        $leading = substr( $decoded, 0, strlen( $decoded ) - strlen( ltrim( $decoded ) ) );
        $trailing = substr( $decoded, strlen( rtrim( $decoded ) ) );
        return htmlspecialchars( $leading . $translated . $trailing, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    public function verify_brand_protection( $original, $translated ) {
        $orig_lower  = mb_strtolower( $original );
        $trans_lower = mb_strtolower( $translated );
        foreach ( $this->protected_terms as $term ) {
            $term_lower = mb_strtolower( $term );
            if ( mb_strpos( $orig_lower, $term_lower ) === false ) continue;
            if ( mb_strpos( $trans_lower, $term_lower ) === false ) {
                error_log( 'GML: A protected brand term is missing from a translation result.' );
                return false;
            }
        }
        return true;
    }

    private function walk( DOMNode $node, array &$nodes ) {
        if ( $node->nodeType === XML_ELEMENT_NODE ) {
            $tag = strtolower( $node->nodeName );
            if ( $tag === 'script' && ! $this->is_excluded( $node )
                && GML_Breadcrumb_JSONLD::is_script_tag( '<script type="' . htmlspecialchars($node->getAttribute('type'), ENT_QUOTES, 'UTF-8') . '">' ) ) {
                foreach ( GML_Breadcrumb_JSONLD::texts( $node->textContent ) as $text ) {
                    if ( ! $this->skip_text($text) ) $nodes[] = ['type'=>'schema_text','text'=>$text,'hash'=>md5($text),'context_type'=>'text'];
                }
                return;
            }
            if ( in_array( $tag, $this->skip_tags, true ) ) return;
            if ( $this->is_excluded( $node ) ) return;
            if ( $tag === 'meta' ) { $this->extract_meta( $node, $nodes ); return; }
            // Translate <title> as SEO meta (affects browser tab + search snippet)
            if ( $tag === 'title' ) { $this->extract_title( $node, $nodes ); return; }

            $attrs = $this->text_attrs;
            if ( $tag === 'input' && $this->input_has_ui_value( $node->getAttribute( 'type' ), $node->getAttribute( 'name' ) ) ) {
                $attrs[] = 'value';
            }
            foreach ( $attrs as $attr ) {
                if ( $node->hasAttribute( $attr ) ) {
                    $val = trim( $node->getAttribute( $attr ) );
                    if ( $val !== '' && ! $this->skip_text( $val ) ) {
                        $nodes[] = [ 'type' => 'attribute', 'attr' => $attr, 'text' => $val, 'hash' => md5( $val ), 'context_type' => 'attribute' ];
                    }
                }
            }
            if ( $tag === 'textarea' ) return;
        }

        if ( $node->nodeType === XML_TEXT_NODE ) {
            $text = trim( $node->nodeValue );
            if ( $text !== '' && ! $this->skip_text( $text ) ) {
                $nodes[] = [ 'type' => 'text', 'text' => $text, 'hash' => md5( $text ), 'context_type' => 'text' ];
            }
            return;
        }

        if ( $node->hasChildNodes() ) {
            foreach ( iterator_to_array( $node->childNodes ) as $child ) {
                $this->walk( $child, $nodes );
            }
        }
    }

    private function extract_meta( DOMNode $node, array &$nodes ) {
        $key = $node->getAttribute( 'name' ) ?: $node->getAttribute( 'property' );
        if ( ! in_array( $key, $this->seo_meta_names, true ) ) return;
        $content = trim( $node->getAttribute( 'content' ) );
        if ( $content === '' || $this->skip_text( $content ) ) return;
        // Title-type metas use seo_title context to batch separately from descriptions
        $title_metas = [ 'title', 'og:title', 'twitter:title' ];
        $ctx = in_array( $key, $title_metas, true ) ? 'seo_title' : 'seo_meta';
        $nodes[] = [ 'type' => 'attribute', 'attr' => 'content', 'text' => $content, 'hash' => md5( $content ), 'context_type' => $ctx ];
    }

    /**
     * Extract <title> text as SEO title so it gets translated with the SEO prompt
     * and appears correctly in browser tabs and search engine snippets.
     *
     * Uses context_type 'seo_title' (distinct from 'seo_meta' used by description)
     * so that title and description are NEVER batched together in the same API call.
     * Gemini sometimes merges adjacent batch items when they are semantically related
     * (e.g. title + description of the same page), producing corrupted translations.
     */
    private function extract_title( DOMNode $node, array &$nodes ) {
        $text = trim( $node->textContent );
        if ( $text === '' || $this->skip_text( $text ) ) return;
        $nodes[] = [ 'type' => 'text', 'text' => $text, 'hash' => md5( $text ), 'context_type' => 'seo_title' ];
    }

    private function is_excluded( DOMNode $node ) {
        if ( $node->hasAttribute( 'translate' ) && strtolower( $node->getAttribute( 'translate' ) ) === 'no' ) return true;
        if ( $node->hasAttribute( 'class' ) ) {
            if ( in_array( 'notranslate', preg_split( '/\s+/', $node->getAttribute( 'class' ) ), true ) ) return true;
        }
        // Check default exclude selectors
        foreach ( get_option( 'gml_exclude_selectors', [] ) as $sel ) {
            if ( $this->matches_simple_selector( $node, $sel ) ) return true;
        }
        // Check exclusion rules (CSS selector type)
        if ( class_exists( 'GML_Exclusion_Rules' ) ) {
            $exclusion = new GML_Exclusion_Rules();
            foreach ( $exclusion->get_excluded_selectors() as $sel ) {
                if ( $this->matches_simple_selector( $node, $sel ) ) return true;
            }
        }
        return false;
    }

    private function matches_simple_selector( DOMNode $node, $selector ) {
        $selector = trim( $selector );
        if ( $selector === '' ) return false;
        if ( $selector[0] === '.' ) {
            return $node->hasAttribute( 'class' ) && in_array( substr( $selector, 1 ), preg_split( '/\s+/', $node->getAttribute( 'class' ) ), true );
        }
        if ( $selector[0] === '#' ) {
            return $node->hasAttribute( 'id' ) && $node->getAttribute( 'id' ) === substr( $selector, 1 );
        }
        return false;
    }

    private function skip_text( $text ) {
        if ( GML_Translation_Text::is_technical_only( $text ) ) return true;
        if ( is_numeric( $text ) ) return true;
        if ( mb_strlen( $text ) < 2 ) return true;
        if ( filter_var( $text, FILTER_VALIDATE_URL ) ) return true;
        if ( filter_var( $text, FILTER_VALIDATE_EMAIL ) ) return true;
        // Pure numbers, phone-like strings, prices ($29.99), percentages (100%)
        if ( preg_match( '/^[\d\s\-\+\(\)\.]+$/', $text ) ) return true;
        // Price patterns: $29.99, €100, ¥500, £9.99
        if ( preg_match( '/^[\$€£¥₩₹][\d,\.]+$/', $text ) ) return true;
        // Percentage: 99%, 100.5%
        if ( preg_match( '/^\d+(\.\d+)?%$/', $text ) ) return true;
        // CSS color codes: #fff, #FF0000
        if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $text ) ) return true;
        // CSS/unit values: 16px, 1.5em, 100vh
        if ( preg_match( '/^\d+(\.\d+)?(px|em|rem|vh|vw|pt|cm|mm|%)$/', $text ) ) return true;
        $lower = strtolower( trim( $text ) );
        foreach ( $this->protected_terms as $term ) {
            if ( strtolower( $term ) === $lower ) return true;
        }
        return false;
    }
}
