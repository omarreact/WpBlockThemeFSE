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
    . '<script src="assets/site.js" defer></script></head><body>'
    . '<main class="site-main" style="padding:20px;color:#111">'
    . '<h1>Hello WordPress</h1><p>Editable paragraph.</p>'
    . '<ul><li>One</li><li>Two</li></ul></main></body></html>'
);

pin_smoke_assert( ! is_wp_error( $result ), 'HTML converter returned WP_Error.' );
pin_smoke_assert( 'Smoke Page' === $result['title'], 'HTML title was not extracted.' );
pin_smoke_assert( str_contains( $result['content'], '<!-- wp:heading' ), 'Heading block was not generated.' );
pin_smoke_assert( str_contains( $result['content'], '<!-- wp:paragraph' ), 'Paragraph block was not generated.' );
pin_smoke_assert( str_contains( $result['content'], '<!-- wp:list' ), 'List block was not generated.' );
pin_smoke_assert( ! empty( $result['inline_css'] ), 'Safe inline styles were not preserved.' );
pin_smoke_assert( in_array( 'assets/site.css', $result['stylesheets'], true ), 'Stylesheet reference was not extracted.' );
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

fwrite( STDOUT, "PIN Universal FSE WordPress smoke test passed.\n" );
