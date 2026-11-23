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
    . '<style>.smoke-critical{display:block}</style>'
    . '<script src="assets/site.js" defer></script>'
    . '<script src="https://cdn.example.com/library.js" async></script></head><body>'
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
pin_smoke_assert(
    str_contains( (string) $result['embedded_css'], '.smoke-critical' ),
    'Embedded critical CSS was not extracted.'
);
pin_smoke_assert( in_array( 'assets/site.css', $result['stylesheets'], true ), 'Stylesheet reference was not extracted.' );
pin_smoke_assert( in_array( 'https://cdn.example.com/theme.css', $result['external_stylesheets'], true ), 'External stylesheet was not preserved.' );
pin_smoke_assert(
    ! empty( $result['scripts'][0]['path'] ) && 'assets/site.js' === $result['scripts'][0]['path'],
    'JavaScript reference was not extracted.'
);
pin_smoke_assert(
    ! empty( $result['external_scripts'][0]['url'] )
        && 'https://cdn.example.com/library.js' === $result['external_scripts'][0]['url'],
    'External JavaScript dependency was not detected.'
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

$static_roots = $client->detect_static_roots(
    array(
        'dist/index.html',
        'dist/about/index.html',
        'README.html',
    )
);
pin_smoke_assert(
    in_array( 'dist', $static_roots, true ),
    'Static build root detection failed.'
);
pin_smoke_assert(
    '' === $client->recommend_static_root(
        array( 'index.html', 'dist/index.html' ),
        array( 'dist' )
    ),
    'Root-level HTML should prevent automatic build-folder selection.'
);
pin_smoke_assert(
    'out' === $client->recommend_static_root(
        array( 'out/index.html', 'out/about/index.html' ),
        array( 'out' )
    ),
    'Clear static build folder was not recommended automatically.'
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

$replace_method = new ReflectionMethod( $importer, 'replace_asset_references' );
$replace_method->setAccessible( true );
$remote_asset   = 'https://raw.githubusercontent.com/example/site/main/assets/icon.svg';
$original_asset = '/assets/icon.svg';
$local_asset    = 'https://example.test/wp-content/uploads/pin-site-importer/abc123/assets/icon.svg';
$replaced_assets = $replace_method->invoke(
    $importer,
    '<img src="' . $remote_asset . '"><img src="' . $original_asset . '">',
    $original_asset,
    $remote_asset,
    $local_asset
);
pin_smoke_assert(
    2 === substr_count( $replaced_assets, $local_asset ),
    'Asset replacement did not rewrite both source references exactly once.'
);
pin_smoke_assert(
    ! str_contains( $replaced_assets, 'abc123https://' )
        && ! str_contains( $replaced_assets, $local_asset . $local_asset ),
    'Asset replacement recursively rewrote a newly localized URL.'
);

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
pin_smoke_assert(
    str_starts_with( (string) get_post_field( 'post_name', $part_id ), 'pin-imported-header-' ),
    'Preview template part incorrectly replaced the live header slug.'
);

delete_option( 'pin_site_importer_navigation_id' );
$nav_method = new ReflectionMethod( $importer, 'create_navigation' );
$nav_method->setAccessible( true );
$preview_navigation_id = $nav_method->invoke(
    $importer,
    $page_map,
    'example/site-preview',
    false
);
pin_smoke_assert( ! is_wp_error( $preview_navigation_id ), 'Preview Navigation creation failed.' );
pin_smoke_assert(
    0 === absint( get_option( 'pin_site_importer_navigation_id' ) ),
    'Preview Navigation incorrectly became the active live Navigation.'
);
wp_delete_post( (int) $preview_navigation_id, true );

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

// Real GitHub -> WordPress end-to-end import using the repository fixture.
$source_repo   = (string) get_option( 'pin_smoke_source_repo', '' );
$source_branch = (string) get_option( 'pin_smoke_source_branch', '' );
$source_token  = (string) get_option( 'pin_smoke_github_token', '' );
$previous_show_on_front = get_option( 'show_on_front' );
$previous_page_on_front = get_option( 'page_on_front' );

pin_smoke_assert( '' !== $source_repo && '' !== $source_branch, 'End-to-end fixture source was not configured.' );

// Regression: public file bodies must work without a GitHub token so large
// imports do not consume one core API request per page/asset.
$public_repo = ( new PIN_Site_Importer_GitHub_Client() )->parse_repository( $source_repo );
pin_smoke_assert( ! is_wp_error( $public_repo ), 'Public raw-file fixture repository could not be parsed.' );
$public_raw = ( new PIN_Site_Importer_GitHub_Client() )->get_file(
    (string) $public_repo['owner'],
    (string) $public_repo['repo'],
    $source_branch,
    'tests/fixtures/static-site/index.html',
    1048576
);
pin_smoke_assert(
    ! is_wp_error( $public_raw ) && str_contains( $public_raw, 'Fixture Home' ),
    is_wp_error( $public_raw )
        ? 'Tokenless public raw-file read failed: ' . $public_raw->get_error_message()
        : 'Tokenless public raw-file read returned unexpected content.'
);

$e2e = $importer->run(
    array(
        'repo_url'                => $source_repo,
        'branch'                  => $source_branch,
        'token'                   => $source_token,
        'source_dir'              => 'tests/fixtures/static-site',
        'post_status'             => 'publish',
        'import_media'            => true,
        'import_css'              => true,
        'import_scripts'          => true,
        'mirror_assets'           => true,
        'import_external_scripts' => false,
        'set_home'                => true,
        'create_navigation'       => true,
        'import_template_parts'   => true,
        'apply_global'            => true,
    )
);

pin_smoke_assert(
    ! is_wp_error( $e2e ),
    is_wp_error( $e2e ) ? 'End-to-end fixture import failed: ' . $e2e->get_error_message() : 'End-to-end fixture import failed.'
);
pin_smoke_assert( 3 === (int) $e2e['pages_imported'], 'Fixture did not import exactly three pages.' );
pin_smoke_assert( 0 === (int) $e2e['pages_failed'], 'Fixture import reported failed pages.' );
pin_smoke_assert( (int) $e2e['assets_mirrored'] >= 3, 'Fixture static assets were not mirrored.' );
pin_smoke_assert( (int) $e2e['media_imported'] >= 1, 'Fixture repository image was not imported into Media Library.' );
pin_smoke_assert(
    empty(
        array_filter(
            (array) $e2e['warnings'],
            static fn( $warning ) => str_contains( (string) $warning, 'Media Library import failed' )
        )
    ),
    'Safe media fallback still emitted a Media Library failure warning.'
);
pin_smoke_assert(
    ! empty( $e2e['template_parts']['header'] ) && ! empty( $e2e['template_parts']['footer'] ),
    'Fixture header/footer were not persisted as FSE template parts.'
);
pin_smoke_assert( ! empty( $e2e['navigation_id'] ), 'Fixture WordPress Navigation was not created.' );

$about_ids = get_posts(
    array(
        'post_type'      => 'page',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_pin_import_source_path',
        'meta_value'     => 'tests/fixtures/static-site/about/index.html',
    )
);
$team_ids = get_posts(
    array(
        'post_type'      => 'page',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_pin_import_source_path',
        'meta_value'     => 'tests/fixtures/static-site/about/team.html',
    )
);
$home_ids = get_posts(
    array(
        'post_type'      => 'page',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_pin_import_source_path',
        'meta_value'     => 'tests/fixtures/static-site/index.html',
    )
);

pin_smoke_assert( ! empty( $about_ids ) && ! empty( $team_ids ) && ! empty( $home_ids ), 'Imported fixture pages could not be queried by source metadata.' );
$about_id = (int) $about_ids[0];
$team_id  = (int) $team_ids[0];
$home_id  = (int) $home_ids[0];

pin_smoke_assert( ! empty( $e2e['global_applied'] ), 'Published fixture was not marked as globally applied.' );
pin_smoke_assert(
    (int) get_option( 'page_on_front' ) === $home_id
        && 'page' === get_option( 'show_on_front' ),
    'Globally applied fixture did not set the imported root page as WordPress homepage.'
);

pin_smoke_assert(
    (int) get_post_field( 'post_parent', $team_id ) === $about_id,
    'End-to-end import did not preserve nested page hierarchy.'
);
$home_content = (string) get_post_field( 'post_content', $home_id );
pin_smoke_assert(
    str_contains( $home_content, get_permalink( $about_id ) ),
    'End-to-end import did not rewrite an internal page link.'
);
pin_smoke_assert(
    str_contains( $home_content, (string) wp_upload_dir()['baseurl'] . '/' )
        && ! str_contains( $home_content, 'raw.githubusercontent.com' ),
    'End-to-end media import did not replace the repository image with a local Media Library URL.'
);
pin_smoke_assert(
    str_contains( $home_content, 'pin-site-importer' )
        && str_contains( $home_content, 'icon.svg' ),
    'Repository SVG image was not rewritten to a safe static mirrored URL.'
);
$home_css = (string) get_post_meta( $home_id, '_pin_import_css', true );
pin_smoke_assert(
    '' !== $home_css,
    'End-to-end import did not preserve page CSS.'
);
pin_smoke_assert(
    str_contains( $home_css, '.fixture-critical' ),
    'End-to-end import did not preserve embedded critical CSS.'
);
pin_smoke_assert(
    str_contains( $home_css, '.fixture-large-tail' ),
    'Referenced CSS above the previous 256 KiB fetch ceiling was not preserved.'
);
pin_smoke_assert(
    str_contains( $home_css, 'pin-site-importer' )
        && ! str_contains( $home_css, 'url("/assets/bg.svg")' ),
    'Root-relative embedded CSS asset URL was not rewritten to mirrored WordPress uploads.'
);
pin_smoke_assert(
    is_array( get_post_meta( $home_id, '_pin_import_scripts', true ) )
        && ! empty( get_post_meta( $home_id, '_pin_import_scripts', true ) ),
    'End-to-end import did not preserve local JavaScript.'
);
$external_css = get_post_meta( $home_id, '_pin_import_external_css', true );
pin_smoke_assert(
    is_array( $external_css )
        && in_array( 'https://cdn.example.com/fixture.css', $external_css, true ),
    'End-to-end import did not preserve external stylesheet metadata.'
);

$header_part_id = (int) $e2e['template_parts']['header'];
pin_smoke_assert(
    'header' === (string) get_post_field( 'post_name', $header_part_id ),
    'Explicit global application did not activate the imported header slug.'
);
$active_header_content = (string) get_post_field( 'post_content', $header_part_id );
pin_smoke_assert(
    str_contains( $active_header_content, home_url( '/' ) ),
    'Imported active header did not reconcile its Home link to the WordPress front-page URL.'
);
$global_scripts = get_post_meta( $header_part_id, '_pin_import_scripts', true );
pin_smoke_assert(
    is_array( $global_scripts ) && ! empty( $global_scripts ),
    'Preferred source JavaScript was not persisted as a global FSE header dependency.'
);
PIN_Site_Importer_Admin::enqueue_global_imported_assets();
pin_smoke_assert(
    wp_style_is( 'pin-site-importer-global', 'enqueued' ),
    'Imported global FSE CSS was not enqueued for frontend routes.'
);

$editor_settings = PIN_Site_Importer_Admin::filter_editor_settings(
    array( 'styles' => array() ),
    new WP_Block_Editor_Context(
        array(
            'name' => 'core/edit-post',
            'post' => get_post( $home_id ),
        )
    )
);
$editor_css = '';

foreach ( (array) ( $editor_settings['styles'] ?? array() ) as $style_entry ) {
    if ( is_array( $style_entry ) && isset( $style_entry['css'] ) ) {
        $editor_css .= "\n" . (string) $style_entry['css'];
    }
}

pin_smoke_assert(
    str_contains( $editor_css, '.fixture-main' )
        && str_contains( $editor_css, 'https://cdn.example.com/fixture.css' ),
    'Imported styles were not injected into the block editor canvas settings.'
);

foreach ( (array) $e2e['page_ids'] as $page_id ) {
    wp_delete_post( (int) $page_id, true );
}
foreach ( (array) $e2e['template_parts'] as $template_part_id ) {
    wp_delete_post( (int) $template_part_id, true );
}
if ( ! empty( $e2e['navigation_id'] ) ) {
    wp_delete_post( (int) $e2e['navigation_id'], true );
}
delete_option( 'pin_site_importer_navigation_id' );
update_option( 'show_on_front', $previous_show_on_front );
update_option( 'page_on_front', $previous_page_on_front );
// Structured JSON source import.
$structured = $importer->run(
    array(
        'repo_url'          => $source_repo,
        'branch'            => $source_branch,
        'source_dir'        => 'tests/fixtures/structured-site',
        'source_format'     => 'json',
        'post_status'       => 'draft',
        'import_media'      => false,
        'import_css'        => true,
        'import_scripts'    => false,
        'mirror_assets'     => false,
        'set_home'          => false,
        'create_navigation' => false,
        'import_template_parts' => true,
        'apply_global'      => false,
    )
);
pin_smoke_assert( ! is_wp_error( $structured ), is_wp_error( $structured ) ? 'API-free structured JSON import failed: ' . $structured->get_error_message() : 'Structured JSON import failed.' );
pin_smoke_assert( 'json' === ( $structured['source_mode'] ?? '' ), 'Structured JSON mode was not selected.' );
pin_smoke_assert( 'Structured JSON' === ( $structured['technology'] ?? '' ), 'API-free structured import did not bypass repository technology discovery.' );
pin_smoke_assert( 3 === (int) ( $structured['pages_imported'] ?? 0 ), 'Structured JSON pages were not imported.' );
pin_smoke_assert( ! empty( $structured['template_parts']['header'] ) && ! empty( $structured['template_parts']['footer'] ), 'Structured header/footer were not imported as FSE template parts.' );
$structured_home = get_posts(
    array(
        'post_type'      => 'page',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_pin_import_source_path',
        'meta_value'     => '__json__/index.html',
    )
);
pin_smoke_assert( ! empty( $structured_home ), 'Structured JSON home page was not persisted.' );
pin_smoke_assert(
    str_contains( (string) get_post_meta( (int) $structured_home[0], '_pin_import_css', true ), '--pin-colors-primary' ),
    'Design tokens were not converted to CSS variables.'
);
pin_smoke_assert(
    'Structured Fixture Home' === (string) get_post_meta( (int) $structured_home[0], '_pin_import_seo_title', true ),
    'Structured SEO metadata was not persisted.'
);
$alternate = get_page_by_path( 'alternate', OBJECT, 'page' );
pin_smoke_assert( $alternate instanceof WP_Post, 'Independent structured page is missing.' );
pin_smoke_assert( 'pin-standalone' === get_post_meta( $alternate->ID, '_wp_page_template', true ), 'Independent page template missing.' );
pin_smoke_assert( str_contains( $alternate->post_content, 'alternate-header' ) && str_contains( $alternate->post_content, 'alternate-footer' ), 'Independent page chrome was removed.' );
pin_smoke_assert( 'alternate-body' === get_post_meta( $alternate->ID, '_pin_import_body_class', true ), 'Source body class was lost.' );
pin_smoke_assert( 'dark' === ( get_post_meta( $alternate->ID, '_pin_import_html_attributes', true )['data-source-theme'] ?? '' ), 'Source HTML data attributes were lost.' );
foreach ( (array) $structured['page_ids'] as $structured_page_id ) {
    wp_delete_post( (int) $structured_page_id, true );
}
foreach ( (array) ( $structured['template_parts'] ?? array() ) as $structured_part_id ) {
    wp_delete_post( (int) $structured_part_id, true );
}

// Explicit WordPress WXR/XML import.
$wxr_result = $importer->run(
    array(
        'repo_url'      => $source_repo,
        'branch'        => $source_branch,
        'token'         => $source_token,
        'source_dir'    => 'tests/fixtures/wxr-site',
        'source_format' => 'wxr',
        'post_status'   => 'publish',
    )
);
pin_smoke_assert( ! is_wp_error( $wxr_result ), 'WXR import failed.' );
pin_smoke_assert( 'wxr' === ( $wxr_result['source_mode'] ?? '' ), 'WXR mode was not selected.' );
pin_smoke_assert( 3 === (int) ( $wxr_result['pages_imported'] ?? 0 ), 'WXR items were not imported.' );
pin_smoke_assert( 1 === (int) ( $wxr_result['comments_imported'] ?? 0 ), 'WXR comments were not imported.' );
$wxr_parent_ids = get_posts(
    array(
        'post_type'      => 'page',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_pin_import_wxr_id',
        'meta_value'     => '101',
    )
);
$wxr_child_ids = get_posts(
    array(
        'post_type'      => 'page',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_pin_import_wxr_id',
        'meta_value'     => '102',
    )
);
$wxr_post_ids = get_posts(
    array(
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_pin_import_wxr_id',
        'meta_value'     => '103',
    )
);
$wxr_parent = ! empty( $wxr_parent_ids ) ? get_post( (int) $wxr_parent_ids[0] ) : null;
$wxr_child  = ! empty( $wxr_child_ids ) ? get_post( (int) $wxr_child_ids[0] ) : null;
$wxr_post   = ! empty( $wxr_post_ids ) ? get_post( (int) $wxr_post_ids[0] ) : null;
pin_smoke_assert( $wxr_parent && $wxr_child && $wxr_post, 'WXR posts/pages were not created.' );
pin_smoke_assert( (int) $wxr_child->post_parent === (int) $wxr_parent->ID, 'WXR page hierarchy was not restored.' );
pin_smoke_assert( 'fixture-value' === (string) get_post_meta( $wxr_parent->ID, 'fixture_key', true ), 'WXR public post meta was not imported.' );
$fixture_comments = get_comments( array( 'post_id' => $wxr_parent->ID, 'status' => 'all' ) );
pin_smoke_assert( 1 === count( $fixture_comments ), 'WXR comment was not attached to the imported page.' );
foreach ( (array) $wxr_result['page_ids'] as $wxr_post_id ) {
    wp_delete_post( (int) $wxr_post_id, true );
}

delete_option( 'pin_smoke_source_repo' );
delete_option( 'pin_smoke_source_branch' );
delete_option( 'pin_smoke_github_token' );

fwrite( STDOUT, "PIN Universal FSE WordPress smoke test passed.\n" );
