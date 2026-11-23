<?php
/**
 * Plugin Name: PIN Site Importer
 * Plugin URI: https://github.com/omarreact/WpBlockThemeFSE
 * Description: Companion importer foundation for PIN Universal FSE. Import and conversion logic lives here so site functionality remains independent from the active theme.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: PINCODE IT
 * License: GPL-2.0-or-later
 * Text Domain: pin-site-importer
 */

defined( 'ABSPATH' ) || exit;

define( 'PIN_SITE_IMPORTER_VERSION', '0.1.0' );
define( 'PIN_SITE_IMPORTER_FILE', __FILE__ );
define( 'PIN_SITE_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Register the importer administration screen.
 */
function pin_site_importer_admin_menu(): void {
    add_management_page(
        __( 'PIN Site Importer', 'pin-site-importer' ),
        __( 'PIN Site Importer', 'pin-site-importer' ),
        'manage_options',
        'pin-site-importer',
        'pin_site_importer_render_admin_page'
    );
}
add_action( 'admin_menu', 'pin_site_importer_admin_menu' );

/**
 * Render the importer foundation status screen.
 */
function pin_site_importer_render_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'PIN Site Importer', 'pin-site-importer' ); ?></h1>
        <p>
            <?php esc_html_e(
                'The companion plugin is installed and active. GitHub/HTML analysis, Gutenberg conversion, media import, page creation, and navigation import will be implemented in this plugin rather than in the theme.',
                'pin-site-importer'
            ); ?>
        </p>

        <table class="widefat striped" style="max-width:900px">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Plugin status', 'pin-site-importer' ); ?></th>
                    <td><?php esc_html_e( 'Active', 'pin-site-importer' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Version', 'pin-site-importer' ); ?></th>
                    <td><?php echo esc_html( PIN_SITE_IMPORTER_VERSION ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Theme integration', 'pin-site-importer' ); ?></th>
                    <td><?php esc_html_e( 'Bundled with PIN Universal FSE and installed as a normal WordPress plugin.', 'pin-site-importer' ); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}
