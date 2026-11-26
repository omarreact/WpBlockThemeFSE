<?php
/** Portable runtime for a standalone PIN Structured Build theme. No importer required. */
defined( 'ABSPATH' ) || exit;

function pin_structured_config(): array {
    static $config;
    if ( null === $config ) {
        $config = json_decode( (string) file_get_contents( __DIR__ . '/pin-build.json' ), true ) ?: array();
    }
    return $config;
}

function pin_structured_route_map(): array {
    static $routes;
    if ( null !== $routes ) {
        return $routes;
    }
    $routes = array();
    $pages = get_posts( array(
        'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ),
        'numberposts' => -1, 'meta_key' => '_pin_structured_theme',
        'meta_value' => pin_structured_config()['slug'],
    ) );
    foreach ( $pages as $page ) {
        $route = (string) get_post_meta( $page->ID, '_pin_structured_route', true );
        if ( $route ) {
            $routes[ '/__pin_route__/' . implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $route, '/' ) ) ) ) ] = get_permalink( $page );
        }
    }
    return $routes;
}

/** Resolve only generated markers; ordinary user-created URLs are left alone. */
function pin_structured_urls( $value ) {
    if ( is_array( $value ) ) {
        return array_map( 'pin_structured_urls', $value );
    }
    if ( ! is_string( $value ) ) {
        return $value;
    }
    foreach ( pin_structured_config()['release']['previousThemeSlugs'] ?? array() as $legacy ) {
        $old = content_url( '/themes/' . $legacy . '/source/' );
        $value = str_replace( $old, trailingslashit( get_theme_file_uri( '/source' ) ), $value );
    }
    $value = str_replace(
        array_merge( array( pin_structured_config()['asset_prefix'], '__PIN_THEME__/' ), array_map( static function ( $slug ) { return '/wp-content/themes/' . $slug . '/source/'; }, pin_structured_config()['release']['previousThemeSlugs'] ?? array() ) ),
        array_merge( array( trailingslashit( get_theme_file_uri( '/source' ) ), trailingslashit( get_theme_file_uri() ) ), array_fill( 0, count( pin_structured_config()['release']['previousThemeSlugs'] ?? array() ), trailingslashit( get_theme_file_uri( '/source' ) ) ) ),
        $value
    );
    if ( false === strpos( $value, '/__pin_route__/' ) ) {
        return $value;
    }
    return preg_replace_callback(
        '~(/__pin_route__/[A-Za-z0-9_./%+\~-]*)(\?[^\s"\'<>#]*)?~',
        static function ( array $match ): string {
            $url = pin_structured_route_map()[ $match[1] ] ?? home_url( '/' . substr( $match[1], strlen( '/__pin_route__/' ) ) );
            if ( ! empty( $match[2] ) ) {
                $url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . substr( $match[2], 1 );
            }
            return $url;
        },
        $value
    );
}

add_filter( 'render_block_data', static function ( array $block ): array {
    foreach ( array( 'attrs', 'innerHTML', 'innerContent' ) as $key ) {
        if ( isset( $block[ $key ] ) ) {
            $block[ $key ] = pin_structured_urls( $block[ $key ] );
        }
    }
    return $block;
} );

// Resolve the same asset/link markers in raw editor content, including file parts.
foreach ( array( 'page', 'wp_template', 'wp_template_part' ) as $type ) {
    add_filter( 'rest_prepare_' . $type, static function ( $response ) {
        if ( ! is_wp_error( $response ) && isset( $response->data['content'] ) ) {
            $response->data['content'] = pin_structured_urls( $response->data['content'] );
        }
        return $response;
    } );
}

function pin_structured_bundle(): array {
    $config = pin_structured_config();
    $route = (string) get_post_meta( get_queried_object_id(), '_pin_structured_route', true );
    $page = $config['pages'][ $route ] ?? ( ! empty( $config['release'] ) ? ( $config['pages']['/'] ?? array( 'css' => array(), 'js' => array() ) ) : array( 'css' => array(), 'js' => array() ) );
    $shared = $config['shared'];
    if ( ! empty( $page['standalone'] ) ) {
        return $page;
    }
    return array(
        'css' => array_merge( $page['css'], $shared['css'] ),
        'js' => array_merge( $shared['header_js'] ?? $shared['js'], $page['js'], $shared['footer_js'] ?? array() ),
    );
}

function pin_structured_enqueue_css( array $entries ): void {
    $seen = array();
    $previous = array();
    foreach ( $entries as $entry ) {
        $url = pin_structured_urls( $entry['url'] );
        $handle = 'pin-source-' . md5( $url . $entry['media'] );
        if ( isset( $seen[ $handle ] ) ) {
            continue;
        }
        $seen[ $handle ] = true;
        wp_enqueue_style( $handle, $url, $previous, wp_get_theme()->get( 'Version' ), $entry['media'] );
        $previous = array( $handle );
    }
}

add_action( 'after_setup_theme', static function (): void {
    foreach ( array( 'editor-styles', 'title-tag', 'block-template-parts', 'align-wide', 'post-thumbnails' ) as $support ) {
        add_theme_support( $support );
    }
} );

add_action( 'wp_enqueue_scripts', static function (): void {
    $bundle = pin_structured_bundle();
    pin_structured_enqueue_css( $bundle['css'] );
    $seen = array();
    $previous = array();
    foreach ( $bundle['js'] as $entry ) {
        $url = pin_structured_urls( $entry['url'] );
        $handle = 'pin-source-' . md5( $url . ( $entry['module'] ? ':module' : '' ) );
        if ( isset( $seen[ $handle ] ) ) {
            continue;
        }
        $seen[ $handle ] = true;
        // Keep one ordered queue for mixed classic/module scripts. Native script
        // modules use a separate queue and can change cross-type execution order.
        $args = array( 'in_footer' => ! $entry['head'] );
        if ( $entry['async'] ) {
            $args['strategy'] = 'async';
        } elseif ( $entry['defer'] ) {
            $args['strategy'] = 'defer';
        }
        wp_enqueue_script( $handle, $url, $previous, wp_get_theme()->get( 'Version' ), $args );
        if ( $entry['module'] ) {
            wp_script_add_data( $handle, 'pin_module', true );
        }
        $previous = array( $handle );
    }
}, 40 );

add_filter( 'script_loader_tag', static function ( string $tag, string $handle ): string {
    if ( wp_scripts()->get_data( $handle, 'pin_module' ) ) {
        $processor = new WP_HTML_Tag_Processor( $tag );
        if ( $processor->next_tag( 'script' ) ) {
            $processor->set_attribute( 'type', 'module' );
            return $processor->get_updated_html();
        }
    }
    return $tag;
}, 10, 2 );

add_action( 'enqueue_block_assets', static function (): void {
    if ( ! is_admin() ) {
        return;
    }
    // The Site Editor can preview any page/part. Load source CSS in its canvas,
    // without running source website scripts inside the administrator's editor.
    $config = pin_structured_config();
    $entries = $config['shared']['css'];
    foreach ( $config['pages'] as $page ) {
        $entries = array_merge( $entries, $page['css'] );
    }
    pin_structured_enqueue_css( $entries );
} );

// No global options or imported content are changed on theme activation.

add_filter( 'body_class', static function ( array $classes ): array {
    $config = pin_structured_config();
    $route = (string) get_post_meta( get_queried_object_id(), '_pin_structured_route', true );
    $source = $config['pages'][ $route ]['document']['body']['class'] ?? '';
    foreach ( preg_split( '/\s+/', $source, -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
        $classes[] = sanitize_html_class( $class );
    }
    return array_values( array_unique( $classes ) );
} );

add_filter( 'language_attributes', static function ( string $attributes ): string {
    $config = pin_structured_config();
    $route = (string) get_post_meta( get_queried_object_id(), '_pin_structured_route', true );
    $source = $config['pages'][ $route ]['document']['html'] ?? ( ! empty( $config['release'] ) ? ( $config['pages']['/']['document']['html'] ?? array() ) : array() );
    foreach ( $source as $name => $value ) {
        if ( preg_match( '/^(?:data-[a-z0-9_-]+|class|id)$/', $name ) && is_string( $value ) ) {
            $attributes .= ' ' . $name . '="' . esc_attr( $value ) . '"';
        }
    }
    return $attributes;
} );

// Source-layout extension is generated only for explicit opt-in manifests.
if ( ! empty( pin_structured_config()['source_layout']['edgeToEdge'] ) ) {
    require_once __DIR__ . '/source-layout.php';
    require_once __DIR__ . '/local-setup.php';
}

if ( ! empty( pin_structured_config()['release'] ) ) { require_once __DIR__ . '/release-runtime.php'; }
