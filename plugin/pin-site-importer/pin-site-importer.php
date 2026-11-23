<?php
/**
 * Plugin Name: PIN Site Importer
 * Plugin URI: https://github.com/omarreact/WpBlockThemeFSE
 * Description: GitHub/JSON/WXR/static-HTML to WordPress Gutenberg importer bundled with PIN Universal FSE.
 * Version: 0.4.1
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: PINCODE IT
 * License: GPL-2.0-or-later
 * Text Domain: pin-site-importer
 */

defined( 'ABSPATH' ) || exit;

define( 'PIN_SITE_IMPORTER_VERSION', '0.4.1' );
define( 'PIN_SITE_IMPORTER_FILE', __FILE__ );
define( 'PIN_SITE_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );

$pin_site_importer_files = array(
    'includes/class-pin-github-client.php',
    'includes/class-pin-asset-manager.php',
    'includes/class-pin-html-to-blocks.php',
    'includes/class-pin-structured-source.php',
    'includes/class-pin-importer.php',
    'includes/class-pin-admin.php',
);

foreach ( $pin_site_importer_files as $pin_site_importer_file ) {
    $pin_site_importer_path = PIN_SITE_IMPORTER_DIR . $pin_site_importer_file;

    if ( ! file_exists( $pin_site_importer_path ) ) {
        add_action(
            'admin_notices',
            static function () use ( $pin_site_importer_file ): void {
                if ( current_user_can( 'manage_options' ) ) {
                    echo '<div class="notice notice-error"><p>'
                        . esc_html(
                            sprintf(
                                /* translators: %s: missing plugin file. */
                                __( 'PIN Site Importer is incomplete. Missing file: %s', 'pin-site-importer' ),
                                $pin_site_importer_file
                            )
                        )
                        . '</p></div>';
                }
            }
        );
        return;
    }

    require_once $pin_site_importer_path;
}

PIN_Site_Importer_Admin::register();
