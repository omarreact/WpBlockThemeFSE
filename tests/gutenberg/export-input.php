<?php
/** Export WordPress' actual dependency graph and imported content for JS validation. */
defined( 'ABSPATH' ) || exit;
$scripts = wp_scripts();
$scripts->all_deps( array( 'wp-block-library' ) );
$dependencies = array();
foreach ( $scripts->to_do as $handle ) {
    if ( $scripts->registered[ $handle ]->src ) {
        $dependencies[] = array( 'handle' => $handle, 'src' => $scripts->registered[ $handle ]->src );
    }
}
$posts = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_pin_structured_theme', 'meta_value' => pin_structured_config()['slug'] ) );
$pages = array();
foreach ( $posts as $post ) {
    $pages[] = array( 'route' => $post->post_name, 'content' => $post->post_content );
}
$parts = array();
foreach ( glob( get_stylesheet_directory() . '/parts/*.html' ) as $path ) {
    $parts[] = array( 'route' => basename( $path ), 'content' => file_get_contents( $path ) );
}
$templates = array();
foreach ( glob( get_stylesheet_directory() . '/templates/*.html' ) as $path ) {
    $templates[] = array( 'route' => basename( $path ), 'content' => file_get_contents( $path ) );
}
file_put_contents( get_theme_root() . '/pin-universal-fse/build/gutenberg-input.json', wp_json_encode( array( 'dependencies' => $dependencies, 'pages' => $pages, 'parts' => $parts, 'templates' => $templates ) ) );
