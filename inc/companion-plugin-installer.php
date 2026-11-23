<?php
/**
 * Bundled companion plugin installer for PIN Universal FSE.
 *
 * The theme keeps presentation concerns in the theme while the importer remains
 * a real WordPress plugin. On first admin load after theme activation we copy
 * the bundled plugin into wp-content/plugins and activate it.
 */

defined( 'ABSPATH' ) || exit;

const PIN_UNIVERSAL_FSE_COMPANION_PLUGIN = 'pin-site-importer/pin-site-importer.php';
const PIN_UNIVERSAL_FSE_COMPANION_PENDING = 'pin_universal_fse_companion_install_pending';
const PIN_UNIVERSAL_FSE_COMPANION_ERROR   = 'pin_universal_fse_companion_install_error';

/**
 * Mark the companion plugin for installation after the theme is activated.
 */
function pin_universal_fse_queue_companion_install(): void {
    update_option( PIN_UNIVERSAL_FSE_COMPANION_PENDING, '1', false );
}
add_action( 'after_switch_theme', 'pin_universal_fse_queue_companion_install' );

/**
 * Return the bundled plugin source directory inside the theme.
 */
function pin_universal_fse_companion_source_dir(): string {
    return trailingslashit( get_template_directory() ) . 'plugin/pin-site-importer';
}

/**
 * Return the installed plugin destination directory.
 */
function pin_universal_fse_companion_target_dir(): string {
    return trailingslashit( WP_PLUGIN_DIR ) . 'pin-site-importer';
}

/**
 * Copy a trusted directory tree without following symlinks.
 *
 * @return true|WP_Error
 */
function pin_universal_fse_copy_directory( string $source, string $destination ) {
    $source_real = realpath( $source );

    if ( false === $source_real || ! is_dir( $source_real ) ) {
        return new WP_Error(
            'pin_companion_source_missing',
            __( 'The bundled PIN Site Importer source could not be found.', 'pin-universal-fse' )
        );
    }

    if ( file_exists( $destination ) && ! is_dir( $destination ) ) {
        return new WP_Error(
            'pin_companion_destination_invalid',
            __( 'The PIN Site Importer destination is not a directory.', 'pin-universal-fse' )
        );
    }

    if ( ! file_exists( $destination ) && ! wp_mkdir_p( $destination ) ) {
        return new WP_Error(
            'pin_companion_destination_create_failed',
            __( 'WordPress could not create the PIN Site Importer plugin directory.', 'pin-universal-fse' )
        );
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $source_real,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ( $iterator as $item ) {
        if ( $item->isLink() ) {
            continue;
        }

        $relative = ltrim( str_replace( $source_real, '', $item->getPathname() ), DIRECTORY_SEPARATOR );
        $target   = trailingslashit( $destination ) . str_replace( DIRECTORY_SEPARATOR, '/', $relative );

        if ( $item->isDir() ) {
            if ( ! file_exists( $target ) && ! wp_mkdir_p( $target ) ) {
                return new WP_Error(
                    'pin_companion_directory_copy_failed',
                    sprintf(
                        /* translators: %s: target directory. */
                        __( 'Could not create plugin directory: %s', 'pin-universal-fse' ),
                        $target
                    )
                );
            }

            continue;
        }

        $target_parent = dirname( $target );

        if ( ! file_exists( $target_parent ) && ! wp_mkdir_p( $target_parent ) ) {
            return new WP_Error(
                'pin_companion_parent_create_failed',
                sprintf(
                    /* translators: %s: target directory. */
                    __( 'Could not create plugin directory: %s', 'pin-universal-fse' ),
                    $target_parent
                )
            );
        }

        if ( ! copy( $item->getPathname(), $target ) ) {
            return new WP_Error(
                'pin_companion_file_copy_failed',
                sprintf(
                    /* translators: %s: file path. */
                    __( 'Could not install plugin file: %s', 'pin-universal-fse' ),
                    $relative
                )
            );
        }
    }

    return true;
}

/**
 * Install and activate the bundled companion plugin.
 *
 * Existing installations are never deleted. If the plugin already exists,
 * only activation is attempted.
 *
 * @return true|WP_Error
 */
function pin_universal_fse_install_companion() {
    if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
        return new WP_Error(
            'pin_companion_capability',
            __( 'You do not have permission to install or activate plugins.', 'pin-universal-fse' )
        );
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $plugin_file = PIN_UNIVERSAL_FSE_COMPANION_PLUGIN;
    $target_file = trailingslashit( WP_PLUGIN_DIR ) . $plugin_file;
    $source_file = trailingslashit( pin_universal_fse_companion_source_dir() ) . 'pin-site-importer.php';
    $target_dir  = pin_universal_fse_companion_target_dir();
    $should_copy = ! file_exists( $target_file );

    if ( file_exists( $target_file ) && file_exists( $source_file ) ) {
        $source_data = get_plugin_data( $source_file, false, false );
        $target_data = get_plugin_data( $target_file, false, false );
        $source_version = (string) ( $source_data['Version'] ?? '0.0.0' );
        $target_version = (string) ( $target_data['Version'] ?? '0.0.0' );

        if ( version_compare( $target_version, $source_version, '<' ) ) {
            $should_copy = true;
        }
    }

    if ( $should_copy ) {
        if ( file_exists( $target_dir ) && ! is_dir( $target_dir ) ) {
            return new WP_Error(
                'pin_companion_partial_install',
                __(
                    'The PIN Site Importer destination exists but is not a directory.',
                    'pin-universal-fse'
                )
            );
        }

        $copied = pin_universal_fse_copy_directory(
            pin_universal_fse_companion_source_dir(),
            $target_dir
        );

        if ( is_wp_error( $copied ) ) {
            return $copied;
        }

        // The plugin directory changed during this request. Refresh WordPress'
        // plugin discovery cache before validating/activating the new copy.
        wp_clean_plugins_cache( true );
    }

    if ( ! is_plugin_active( $plugin_file ) ) {
        $activated = activate_plugin( $plugin_file );

        if ( is_wp_error( $activated ) ) {
            return $activated;
        }
    }

    delete_option( PIN_UNIVERSAL_FSE_COMPANION_PENDING );
    delete_option( PIN_UNIVERSAL_FSE_COMPANION_ERROR );

    return true;
}

/**
 * Queue a companion sync when the bundled plugin is newer than the installed
 * copy. This keeps theme and plugin releases aligned while preserving the
 * plugin as a normal WordPress plugin.
 */
function pin_universal_fse_maybe_queue_companion_sync(): void {
    if ( ! current_user_can( 'install_plugins' ) ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $source_file = trailingslashit( pin_universal_fse_companion_source_dir() ) . 'pin-site-importer.php';
    $target_file = trailingslashit( WP_PLUGIN_DIR ) . PIN_UNIVERSAL_FSE_COMPANION_PLUGIN;

    if ( ! file_exists( $source_file ) ) {
        return;
    }

    if ( ! file_exists( $target_file ) ) {
        update_option( PIN_UNIVERSAL_FSE_COMPANION_PENDING, '1', false );
        return;
    }

    $source_data = get_plugin_data( $source_file, false, false );
    $target_data = get_plugin_data( $target_file, false, false );
    $source_version = (string) ( $source_data['Version'] ?? '0.0.0' );
    $target_version = (string) ( $target_data['Version'] ?? '0.0.0' );

    if ( version_compare( $target_version, $source_version, '<' ) ) {
        update_option( PIN_UNIVERSAL_FSE_COMPANION_PENDING, '1', false );
    }
}
add_action( 'admin_init', 'pin_universal_fse_maybe_queue_companion_sync', 5 );

/**
 * Attempt automatic install on the first admin request after activation.
 */
function pin_universal_fse_maybe_install_companion(): void {
    if ( '1' !== get_option( PIN_UNIVERSAL_FSE_COMPANION_PENDING ) ) {
        return;
    }

    if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    $result = pin_universal_fse_install_companion();

    if ( is_wp_error( $result ) ) {
        update_option(
            PIN_UNIVERSAL_FSE_COMPANION_ERROR,
            sanitize_text_field( $result->get_error_message() ),
            false
        );
    }
}
add_action( 'admin_init', 'pin_universal_fse_maybe_install_companion', 20 );

/**
 * Manual retry endpoint for hosts that block the automatic filesystem write.
 */
function pin_universal_fse_retry_companion_install(): void {
    if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
        wp_die( esc_html__( 'You do not have permission to install plugins.', 'pin-universal-fse' ) );
    }

    check_admin_referer( 'pin_universal_fse_install_companion' );

    update_option( PIN_UNIVERSAL_FSE_COMPANION_PENDING, '1', false );

    $result = pin_universal_fse_install_companion();

    if ( is_wp_error( $result ) ) {
        update_option(
            PIN_UNIVERSAL_FSE_COMPANION_ERROR,
            sanitize_text_field( $result->get_error_message() ),
            false
        );
    }

    wp_safe_redirect( admin_url( 'themes.php' ) );
    exit;
}
add_action( 'admin_post_pin_universal_fse_install_companion', 'pin_universal_fse_retry_companion_install' );

/**
 * Surface installation failures instead of silently leaving the site partial.
 */
function pin_universal_fse_companion_admin_notice(): void {
    $error = get_option( PIN_UNIVERSAL_FSE_COMPANION_ERROR );

    if ( ! $error || ! current_user_can( 'install_plugins' ) ) {
        return;
    }

    $retry_url = wp_nonce_url(
        admin_url( 'admin-post.php?action=pin_universal_fse_install_companion' ),
        'pin_universal_fse_install_companion'
    );
    ?>
    <div class="notice notice-error">
        <p>
            <strong><?php esc_html_e( 'PIN Universal FSE:', 'pin-universal-fse' ); ?></strong>
            <?php echo esc_html( $error ); ?>
        </p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url( $retry_url ); ?>">
                <?php esc_html_e( 'Retry PIN Site Importer installation', 'pin-universal-fse' ); ?>
            </a>
        </p>
    </div>
    <?php
}
add_action( 'admin_notices', 'pin_universal_fse_companion_admin_notice' );
