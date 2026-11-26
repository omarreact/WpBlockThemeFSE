<?php
defined('ABSPATH') || exit;

/** Preserve source markup; opt-in native styles use the block support API. */
function pin_source_open_tag( $tag, $attrs ) {
    $raw = (array) ( $attrs['sourceAttrs'] ?? array() );
    $native = false;
    foreach ( array( 'style', 'align', 'backgroundColor', 'textColor', 'gradient', 'fontSize', 'fontFamily', 'className' ) as $key ) {
        if ( ! empty( $attrs[ $key ] ) ) { $native = true; break; }
    }
    $out = '<' . $tag;
    if ( $native ) {
        $extra = array();
        if ( isset( $raw['class'] ) ) { $extra['class'] = (string) $raw['class']; }
        if ( isset( $raw['style'] ) ) { $extra['style'] = safecss_filter_attr( (string) $raw['style'] ); }
        $out .= ' ' . get_block_wrapper_attributes( $extra );
        unset( $raw['class'], $raw['style'] );
    }
    foreach ( $raw as $key => $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^(?:class|id|role|title|lang|dir|tabindex|hidden|style|aria-[a-z0-9_-]+|data-[a-z0-9_-]+|href|src|srcset|sizes|alt|width|height|loading|decoding|fetchpriority|target|rel|type|disabled)$/D', $key ) ) { continue; }
        if ( 'style' === $key ) { $value = safecss_filter_attr( $value ); }
        if ( in_array( $key, array( 'href', 'src', 'data-src', 'data-dark-src', 'data-light-src' ), true ) ) {
            $out .= ' ' . $key . '="' . esc_url( $value ) . '"';
            continue;
        }
        $out .= ' ' . $key . '="' . esc_attr( $value ) . '"';
    }
    return $out . '>';
}
function pin_source_layout_render( $attrs, $content ) {
    $tag = $attrs['tagName'] ?? 'div';
    if ( ! in_array( $tag, array( 'div', 'main', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'ul', 'ol', 'li', 'a' ), true ) ) { $tag = 'div'; }
    return pin_source_open_tag( $tag, $attrs ) . $content . '</' . $tag . '>';
}
function pin_source_element_render( $attrs ) {
    $tag = $attrs['tagName'] ?? 'span';
    if ( ! in_array( $tag, array( 'img', 'a', 'span', 'button', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'small', 'i', 'b' ), true ) ) { $tag = 'span'; }
    $out = pin_source_open_tag( $tag, $attrs );
    return 'img' === $tag ? $out : $out . esc_html( (string) ( $attrs['content'] ?? '' ) ) . '</' . $tag . '>';
}
add_action( 'init', static function () {
    $root = get_template_directory();
    wp_register_script( 'pin-source-blocks-editor', get_theme_file_uri( '/source-layout.js' ), array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ), pin_asset_version( get_theme_file_uri( '/source-layout.js' ) ), true );
    $metadata = array();
    foreach ( array( 'source-layout' => 'pin_source_layout_render', 'source-element' => 'pin_source_element_render' ) as $name => $render ) {
        $dir = $root . '/blocks/' . $name;
        $metadata[] = json_decode( (string) file_get_contents( $dir . '/block.json' ), true );
        register_block_type( $dir, array( 'render_callback' => $render ) );
    }
    wp_add_inline_script( 'pin-source-blocks-editor', 'window.pinSourceBlockMetadata=' . wp_json_encode( $metadata, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );

} );
add_filter( 'body_class', static function ( $classes ) { $classes[] = 'pin-source-edge'; return $classes; } );
add_action( 'wp_enqueue_scripts', static function () { wp_enqueue_style( 'pin-source-edge', get_theme_file_uri( '/source-layout.css' ), array(), pin_asset_version( get_theme_file_uri( '/source-layout.css' ) ) ); }, 100 );
add_action( 'enqueue_block_assets', static function () { if ( is_admin() && ! is_file( get_theme_file_path( '/editor-style.css' ) ) ) { wp_enqueue_style( 'pin-source-edge-editor', get_theme_file_uri( '/source-layout.css' ), array(), pin_asset_version( get_theme_file_uri( '/source-layout.css' ) ) ); } }, 100 );
