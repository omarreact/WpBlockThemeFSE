<?php
/**
 * PIN Universal FSE — content-neutral WordPress theme setup.
 * Importers belong in standalone plugins, never this theme.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', static function (): void {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'custom-logo' );
    add_theme_support( 'editor-styles' );
    add_editor_style( 'style.css' );
} );

add_action( 'wp_enqueue_scripts', static function (): void {
    wp_enqueue_style(
        'pin-universal-fse',
        get_stylesheet_uri(),
        array(),
        wp_get_theme()->get( 'Version' )
    );
} );
