<?php
/** Run after a generated theme is active and its WXR is imported. */
defined( 'ABSPATH' ) || exit;

function pin_build_assert( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( 'Structured Build regression: ' . $message );
    }
}

pin_build_assert( function_exists( 'pin_structured_config' ), 'Generated theme runtime not active' );
$config = pin_structured_config();
$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_pin_structured_theme', 'meta_value' => $config['slug'] ) );
pin_build_assert( count( $pages ) === count( $config['pages'] ), 'WXR page count does not match build' );
wp_set_current_user( 1 );
$home_id = 0;
foreach ( $pages as $page ) {
    $route = (string) get_post_meta( $page->ID, '_pin_structured_route', true );
    if ( '/' === $route ) {
        $home_id = (int) $page->ID;
    }
    pin_build_assert( '' !== trim( $page->post_content ), 'Empty imported page: ' . $route );
    $response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/pages/' . $page->ID ) );
    pin_build_assert( ! $response->is_error(), 'REST page failed: ' . $route );
}
pin_build_assert( $home_id > 0, 'Home page identity missing' );

// Test markers against both standard hosts and customized WP_CONTENT_URL.
$actual_source = trailingslashit( get_theme_file_uri( '/source' ) );
$sample = '<!-- wp:html --><img src="' . $config['asset_prefix'] . 'assets/logo.svg"><a href="/__pin_route__/?from=qa#target">Home</a><!-- /wp:html -->';
$rendered = do_blocks( $sample );
pin_build_assert( str_contains( $rendered, $actual_source . 'assets/logo.svg' ), 'Portable theme URL not resolved' );
pin_build_assert( ! str_contains( $rendered, '/__pin_route__/' ), 'Route marker leaked into frontend' );
pin_build_assert( str_contains( $rendered, ( str_contains( get_permalink( $home_id ), '?' ) ? '&' : '?' ) . 'from=qa#target' ), 'Query and hash not preserved with selected permalink mode' );

$request = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $home_id );
$request->set_param( 'context', 'edit' );
$data = rest_do_request( $request )->get_data();
pin_build_assert( isset( $data['content']['raw'] ), 'Editor REST raw content missing' );
pin_build_assert( ! str_contains( $data['content']['raw'], '/__pin_route__/' ), 'Route marker leaked into editor raw content' );
if ( $config['asset_prefix'] !== $actual_source ) {
    pin_build_assert( ! str_contains( $data['content']['raw'], $config['asset_prefix'] ), 'Standard theme path leaked on custom content installation' );
}

$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $home_id ) );
$expected = pin_structured_bundle();
do_action( 'wp_enqueue_scripts' );
foreach ( $expected['css'] as $entry ) {
    $url = pin_structured_urls( $entry['url'] );
    $handle = 'pin-source-' . md5( $url . $entry['media'] );
    pin_build_assert( wp_style_is( $handle, 'enqueued' ), 'Source stylesheet not enqueued: ' . $url );
}
foreach ( $expected['js'] as $entry ) {
    $url = pin_structured_urls( $entry['url'] );
    $handle = 'pin-source-' . md5( $url . ( $entry['module'] ? ':module' : '' ) );
    pin_build_assert( wp_script_is( $handle, 'enqueued' ), 'Source script missing: ' . $url );
    if ( $entry['module'] ) {
        $tag = apply_filters( 'script_loader_tag', '<script src="' . esc_url( $url ) . '"></script>', $handle );
        pin_build_assert( str_contains( $tag, 'type="module"' ), 'Module became classic script' );
    }
}
$source_classes = $config['pages']['/']['document']['body']['class'] ?? '';
foreach ( preg_split( '/\s+/', $source_classes, -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
    pin_build_assert( in_array( sanitize_html_class( $class ), get_body_class(), true ), 'Source body class lost' );
}

require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'post' );
do_action( 'enqueue_block_assets' );
foreach ( $config['pages'] as $bundle ) {
    foreach ( $bundle['css'] as $entry ) {
        $url = pin_structured_urls( $entry['url'] );
        pin_build_assert( wp_style_is( 'pin-source-' . md5( $url . $entry['media'] ), 'enqueued' ), 'Source CSS missing from editor assets' );
    }
}
fwrite( STDOUT, 'PASS: ' . count( $pages ) . " generated pages, portable frontend/editor URLs, source styles/scripts and body classes\n" );
