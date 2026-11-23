<?php
/**
 * WordPress integration smoke test, executed by wp-cli inside wp-env.
 */

defined( 'ABSPATH' ) || exit;

function pin_smoke_assert( bool $condition, string $message ): void {
    if ( $condition ) {
        return;
    }

    fwrite( STDERR, "SMOKE TEST FAILED: {$message}\n" );
    exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

pin_smoke_assert(
    'pin-universal-fse' === get_stylesheet(),
    'PIN Universal FSE is not the active theme.'
);

pin_smoke_assert(
    is_plugin_active( 'pin-site-importer/pin-site-importer.php' ),
    'Bundled PIN Site Importer was not activated.'
);

pin_smoke_assert(
    class_exists( 'PIN_Site_Importer_GitHub_Client' )
        && class_exists( 'PIN_Site_Importer_Asset_Manager' )
        && class_exists( 'PIN_Site_Importer_HTML_To_Blocks' )
        && class_exists( 'PIN_Site_Importer_Importer' )
        && class_exists( 'PIN_Site_Importer_Admin' ),
    'Importer classes are not available.'
);

$client = new PIN_Site_Importer_GitHub_Client();
$converter = new PIN_Site_Importer_HTML_To_Blocks(
    $client,
    array(),
    'example',
    'site',
    'main',
    'index.html',
    ''
);

$result = $converter->convert(
    '<!doctype html><html><head><title>Smoke Page</title>'
    . '<link rel="stylesheet" href="assets/site.css">'
    . '<link rel="stylesheet" href="https://cdn.example.com/theme.css">'
    . '<script src="assets/site.js" defer></script></head><body>'
    . '<header class="source-header"><nav><a href="index.html">Home</a><a href="about/index.html">About</a></nav></header>'
    . '<main class="site-main" style="padding:20px;color:#111">'
    . '<h1>Hello WordPress</h1><p>Editable paragraph.</p>'
    . '<ul><li>One</li><li>Two</li></ul></main>'
    . '<footer class="source-footer"><p>Footer content</p></footer>'
    . '</body></html>'
);

pin_smoke_assert( ! is_wp_error( $result ), 'HTML converter returned WP_Error.' );
pin_smoke_assert( 'Smoke Page' === $result['title'], 'HTML title was not extracted.' );
pin_smoke_assert( str_contains( $result['content'], '<!-- wp:heading' ), 'Heading block was not generated.' );
pin_smoke_assert( str_contains( $result['content'], '<!-- wp:paragraph' ), 'Paragraph block was not generated.' );
pin_smoke_assert( str_contains( $result['content'], '<!-- wp:list' ), 'List block was not generated.' );
pin_smoke_assert( ! str_contains( $result['content'], 'source-header' ), 'Source header leaked into page content.' );
pin_smoke_assert( ! str_contains( $result['content'], 'source-footer' ), 'Source footer leaked into page content.' );
pin_smoke_assert( str_contains( $result['header_content'], '<!-- wp:navigation ' ), 'Source navigation was not converted in header template part.' );
pin_smoke_assert( str_contains( $result['footer_content'], 'Footer content' ), 'Source footer was not extracted.' );
pin_smoke_assert( ! empty( $result['inline_css'] ), 'Safe inline styles were not preserved.' );
pin_smoke_assert( in_array( 'assets/site.css', $result['stylesheets'], true ), 'Stylesheet reference was not extracted.' );
pin_smoke_assert( in_array( 'https://cdn.example.com/theme.css', $result['external_stylesheets'], true ), 'External stylesheet was not preserved.' );
pin_smoke_assert(
    ! empty( $result['scripts'][0]['path'] ) && 'assets/site.js' === $result['scripts'][0]['path'],
    'JavaScript reference was not extracted.'
);
pin_smoke_assert(
    'assets/image.png' === $client->resolve_path( 'index.html', 'assets/image.png' ),
    'Repository path resolution failed.'
);
$parsed_repo = $client->parse_repository( 'https://github.com/example/site' );
pin_smoke_assert(
    ! is_wp_error( $parsed_repo )
        && 'example' === $parsed_repo['owner']
        && 'site' === $parsed_repo['repo'],
    'GitHub repository URL parsing failed.'
);

$parsed = parse_blocks( $result['content'] );
pin_smoke_assert( ! empty( $parsed ), 'Generated Gutenberg content could not be parsed.' );

foreach ( $parsed as $block ) {
    if ( empty( $block['blockName'] ) ) {
        continue;
    }

    pin_smoke_assert(
        str_starts_with( (string) $block['blockName'], 'core/' ),
        'Generated content contains an unexpected non-core block.'
    );
}

// Exercise WordPress persistence paths used by the importer without requiring
// network access.
$importer = new PIN_Site_Importer_Importer();
$page_method = new ReflectionMethod( $importer, 'create_page_placeholders' );
$page_method->setAccessible( true );

$page_map = $page_method->invoke(
    $importer,
    'example/site',
    'main',
    array(
        'fixture/index.html',
        'fixture/about/index.html',
        'fixture/about/team.html',
    ),
    'draft',
    'fixture'
);

pin_smoke_assert( ! is_wp_error( $page_map ), 'Page placeholder creation failed.' );
pin_smoke_assert( isset( $page_map['fixture/about/index.html'], $page_map['fixture/about/team.html'] ), 'Hierarchy fixture pages were not created.' );
pin_smoke_assert(
    (int) get_post_field( 'post_parent', $page_map['fixture/about/team.html'] )
        === (int) $page_map['fixture/about/index.html'],
    'Source folder hierarchy was not preserved as WordPress page hierarchy.'
);
pin_smoke_assert(
    'team' === (string) get_post_field( 'post_name', $page_map['fixture/about/team.html'] ),
    'Nested child page slug was not normalized.'
);

$part_method = new ReflectionMethod( $importer, 'upsert_template_part' );
$part_method->setAccessible( true );
$part_id = $part_method->invoke(
    $importer,
    'header',
    $result['header_content'],
    'example/site',
    'main',
    'fixture/index.html'
);

pin_smoke_assert( ! is_wp_error( $part_id ), 'FSE header template part creation failed.' );
$theme_terms = get_the_terms( $part_id, 'wp_theme' );
$area_terms  = get_the_terms( $part_id, 'wp_template_part_area' );
pin_smoke_assert(
    is_array( $theme_terms ) && 'pin-universal-fse' === $theme_terms[0]->name,
    'Imported template part was not assigned to the active theme.'
);
pin_smoke_assert(
    is_array( $area_terms ) && 'header' === $area_terms[0]->name,
    'Imported template part was not assigned to the header area.'
);

$navigation_id = wp_insert_post(
    array(
        'post_type'    => 'wp_navigation',
        'post_status'  => 'publish',
        'post_title'   => 'Smoke Navigation',
        'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
    )
);
update_option( 'pin_site_importer_navigation_id', (int) $navigation_id );

$empty_navigation = array(
    'blockName'   => 'core/navigation',
    'attrs'       => array(),
    'innerBlocks' => array(),
);
$injected = PIN_Site_Importer_Admin::inject_navigation_ref( $empty_navigation, $empty_navigation );
pin_smoke_assert(
    (int) ( $injected['attrs']['ref'] ?? 0 ) === (int) $navigation_id,
    'Imported navigation was not injected into an empty theme Navigation block.'
);

$source_navigation = array(
    'blockName'   => 'core/navigation',
    'attrs'       => array(),
    'innerBlocks' => array(
        array(
            'blockName' => 'core/navigation-link',
            'attrs'     => array( 'label' => 'Source' ),
        ),
    ),
);
$preserved = PIN_Site_Importer_Admin::inject_navigation_ref( $source_navigation, $source_navigation );
pin_smoke_assert(
    empty( $preserved['attrs']['ref'] ),
    'Source-provided Navigation links were incorrectly overridden.'
);

foreach ( $page_map as $page_id ) {
    wp_delete_post( (int) $page_id, true );
}
wp_delete_post( (int) $part_id, true );
wp_delete_post( (int) $navigation_id, true );
delete_option( 'pin_site_importer_navigation_id' );

fwrite( STDOUT, "PIN Universal FSE WordPress smoke test passed.\n" );
