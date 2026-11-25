<?php
/** Allowlisted BreadcrumbList strings; preserve every other JSON byte. */
if ( ! defined( 'ABSPATH' ) ) exit;

class GML_Breadcrumb_JSONLD {
    const MAX_BYTES = 262144;

    public static function is_script_tag( $tag ) {
        $attributes = [];
        preg_match_all( '~\s+([^\s=/>]+)\s*=\s*(?:(["\'])(.*?)\2|([^\s>]+))~s', $tag, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL );
        foreach ( $matches as $match ) {
            $name = strtolower( $match[1] );
            if ( ! isset( $attributes[$name] ) ) $attributes[$name] = html_entity_decode( $match[2] !== null ? $match[3] : $match[4], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }
        $selectors = get_option('gml_exclude_selectors',[]);
        if ( class_exists('GML_Exclusion_Rules') ) $selectors = array_merge($selectors,(new GML_Exclusion_Rules())->get_excluded_selectors());
        $classes = preg_split('/\\s+/',$attributes['class'] ?? '');
        foreach ( $selectors as $selector ) {
            $selector = trim($selector);
            if ( $selector === '' ) continue;
            if ( $selector[0] === '#' && ($attributes['id'] ?? '') === substr($selector,1) ) return false;
            if ( $selector[0] === '.' && in_array(substr($selector,1),$classes,true) ) return false;
        }
        return strtolower( trim( $attributes['type'] ?? '' ) ) === 'application/ld+json'
            && strtolower( $attributes['translate'] ?? '' ) !== 'no'
            && ! in_array( 'notranslate', preg_split( '/\s+/', $attributes['class'] ?? '' ), true );
    }

    public static function text( $name ) {
        for ( $i = 0; $i < 2; $i++ ) $name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( GML_Translation_Text::plain_text( $name ) );
    }

    public static function texts( $json ) {
        $texts = [];
        self::map( $json, static function( $name ) use ( &$texts ) {
            $text = self::text( $name );
            if ( $text !== '' ) $texts[$text] = true;
            return $name;
        } );
        return array_keys( $texts );
    }

    /** No whole-document re-encoding: only selected string literal spans change. */
    public static function map( $json, callable $name_mapper = null, callable $url_mapper = null ) {
        if ( strlen( $json ) > self::MAX_BYTES ) return $json;
        $tree = json_decode( $json, false, 64 );
        if ( json_last_error() !== JSON_ERROR_NONE ) return $json;
        $paths = [];
        self::paths( $tree, [], $paths );
        if ( ! $paths || count( $paths ) > 1024 ) return $json;
        $offset = 0;
        $strings = [];
        try {
            self::scan( $json, $offset, [], $paths, $strings );
        } catch ( UnexpectedValueException $e ) {
            // Duplicate object keys are ambiguous; leave the complete script intact.
            return $json;
        }
        $edits = [];
        foreach ( $strings as $string ) {
            $mapper = $string[2] === 'name' ? $name_mapper : $url_mapper;
            if ( ! $mapper ) continue;
            $mapped = call_user_func($mapper,$string[3]);
            if ( is_string($mapped) && $mapped !== $string[3] ) {
                $encoded = json_encode($mapped,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if ( is_string($encoded) ) $edits[] = [$string[0],$string[1],$encoded];
            }
        }
        foreach ( array_reverse( $edits ) as $edit ) $json = substr_replace( $json, $edit[2], $edit[0], $edit[1] );
        return $json;
    }

    private static function paths( $node, array $path, array &$paths, $schema_context = false ) {
        if ( is_object( $node ) ) {
            if ( isset($node->{'@context'}) ) {
                $schema_context = false;
                foreach ( (array)$node->{'@context'} as $context ) {
                    if ( is_string($context) && preg_match('~^https?://schema\\.org/?$~i',$context) ) $schema_context = true;
                }
            }
            $types = isset( $node->{'@type'} ) ? (array) $node->{'@type'} : [];
            if ( $schema_context && in_array( 'BreadcrumbList', $types, true ) && isset( $node->itemListElement ) && is_array( $node->itemListElement ) && count( $node->itemListElement ) <= 128 ) {
                foreach ( $node->itemListElement as $index => $item ) {
                    if ( ! is_object( $item ) ) continue;
                    $base = array_merge( $path, [ 'itemListElement', $index ] );
                    if ( isset( $item->name ) && is_string( $item->name ) ) $paths[json_encode(array_merge($base,['name']))] = 'name';
                    if ( isset( $item->item ) && is_string( $item->item ) ) {
                        $paths[json_encode(array_merge($base,['item']))] = 'url';
                    } elseif ( isset( $item->item ) && is_object( $item->item ) ) {
                        foreach ( [ 'name', '@id', 'id', 'url' ] as $key ) {
                            if ( isset( $item->item->$key ) && is_string( $item->item->$key ) ) $paths[json_encode(array_merge($base,['item',$key]))] = $key === 'name' ? 'name' : 'url';
                        }
                    }
                }
            }
            foreach ( get_object_vars( $node ) as $key => $value ) self::paths( $value, array_merge($path,[(string)$key]), $paths, $schema_context );
        } elseif ( is_array( $node ) ) {
            foreach ( $node as $key => $value ) self::paths( $value, array_merge($path,[$key]), $paths, $schema_context );
        }
    }

    private static function whitespace( $json, &$offset ) {
        $offset += strspn( $json, " \r\n\t", $offset );
    }

    private static function string_token( $json, &$offset ) {
        $start = $offset++;
        while ( $offset < strlen($json) ) {
            if ( $json[$offset] === '\\' ) { $offset += 2; continue; }
            if ( $json[$offset++] === '"' ) break;
        }
        return substr( $json, $start, $offset - $start );
    }

    /** A strict JSON decode above bounds depth and validates token syntax. */
    private static function scan( $json, &$offset, array $path, array $paths, array &$strings ) {
        self::whitespace( $json, $offset );
        $char = $json[$offset];
        if ( $char === '{' ) {
            $offset++;
            self::whitespace( $json, $offset );
            $seen = [];
            while ( $json[$offset] !== '}' ) {
                $key = json_decode( self::string_token( $json, $offset ) );
                if ( isset($seen[$key]) ) throw new UnexpectedValueException('duplicate JSON key');
                $seen[$key] = true;
                self::whitespace($json,$offset);
                $offset++; // colon
                self::scan($json,$offset,array_merge($path,[$key]),$paths,$strings);
                self::whitespace($json,$offset);
                if ( $json[$offset] !== ',' ) break;
                $offset++;
                self::whitespace($json,$offset);
            }
            $offset++;
        } elseif ( $char === '[' ) {
            $offset++;
            self::whitespace($json,$offset);
            $index = 0;
            while ( $json[$offset] !== ']' ) {
                self::scan($json,$offset,array_merge($path,[$index++]),$paths,$strings);
                self::whitespace($json,$offset);
                if ( $json[$offset] !== ',' ) break;
                $offset++;
                self::whitespace($json,$offset);
            }
            $offset++;
        } elseif ( $char === '"' ) {
            $start = $offset;
            $token = self::string_token($json,$offset);
            $kind = $paths[json_encode($path)] ?? '';
            if ( $kind !== '' ) $strings[] = [$start,$offset-$start,$kind,json_decode($token)];
        } else {
            $offset += strcspn($json,",]} \r\n\t",$offset);
        }
    }
}
