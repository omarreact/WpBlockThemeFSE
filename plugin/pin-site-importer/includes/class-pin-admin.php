<?php
/**
 * WordPress admin UI and import action.
 */

defined( 'ABSPATH' ) || exit;

final class PIN_Site_Importer_Admin {
    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_pin_site_importer_run', array( __CLASS__, 'handle_import' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_imported_css' ), 50 );
        add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_css' ), 50 );
        add_filter( 'render_block_data', array( __CLASS__, 'inject_navigation_ref' ), 10, 2 );
    }

    public static function menu(): void {
        add_management_page(
            __( 'PIN Site Importer', 'pin-site-importer' ),
            __( 'PIN Site Importer', 'pin-site-importer' ),
            'manage_options',
            'pin-site-importer',
            array( __CLASS__, 'render' )
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $result  = self::consume_result();
        $history = get_option( 'pin_site_importer_history', array() );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'PIN Site Importer', 'pin-site-importer' ); ?></h1>
            <p>
                <?php esc_html_e(
                    'Import a GitHub-hosted static website into native WordPress pages. The importer creates Gutenberg core blocks, rewrites internal page links, can sideload repository images, and can preserve referenced CSS per imported page.',
                    'pin-site-importer'
                ); ?>
            </p>

            <?php self::render_result( $result ); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:960px">
                <input type="hidden" name="action" value="pin_site_importer_run">
                <?php wp_nonce_field( 'pin_site_importer_run' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="pin-repo-url"><?php esc_html_e( 'GitHub repository', 'pin-site-importer' ); ?></label></th>
                        <td>
                            <input id="pin-repo-url" name="repo_url" type="url" class="regular-text code" required placeholder="https://github.com/owner/repository">
                            <p class="description"><?php esc_html_e( 'Public repository URL. Private repositories require a temporary access token below.', 'pin-site-importer' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pin-branch"><?php esc_html_e( 'Branch', 'pin-site-importer' ); ?></label></th>
                        <td>
                            <input id="pin-branch" name="branch" type="text" class="regular-text" placeholder="Default branch">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pin-source-dir"><?php esc_html_e( 'Static build folder (optional)', 'pin-site-importer' ); ?></label></th>
                        <td>
                            <input id="pin-source-dir" name="source_dir" type="text" class="regular-text code" placeholder="out or dist">
                            <p class="description"><?php esc_html_e( 'Useful for Next.js/Vite/static-export repositories. Leave empty to scan all HTML files.', 'pin-site-importer' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pin-token"><?php esc_html_e( 'GitHub token (optional)', 'pin-site-importer' ); ?></label></th>
                        <td>
                            <input id="pin-token" name="token" type="password" class="regular-text" autocomplete="off">
                            <p class="description"><?php esc_html_e( 'Used only for this request and never saved by the plugin.', 'pin-site-importer' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Imported page status', 'pin-site-importer' ); ?></th>
                        <td>
                            <select name="post_status">
                                <option value="draft"><?php esc_html_e( 'Draft (recommended)', 'pin-site-importer' ); ?></option>
                                <option value="publish"><?php esc_html_e( 'Publish immediately', 'pin-site-importer' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Options', 'pin-site-importer' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="import_media" value="1" checked> <?php esc_html_e( 'Import repository images into Media Library', 'pin-site-importer' ); ?></label><br>
                            <label><input type="checkbox" name="import_css" value="1" checked> <?php esc_html_e( 'Preserve referenced repository CSS on imported pages', 'pin-site-importer' ); ?></label><br>
                            <label><input type="checkbox" name="set_home" value="1"> <?php esc_html_e( 'Set root index.html as the WordPress homepage', 'pin-site-importer' ); ?></label><br>
                            <label><input type="checkbox" name="create_navigation" value="1" checked> <?php esc_html_e( 'Create/update WordPress Navigation from imported pages', 'pin-site-importer' ); ?></label>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Import GitHub Website', 'pin-site-importer' ) ); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Supported in this phase', 'pin-site-importer' ); ?></h2>
            <p><?php esc_html_e( 'Static HTML repositories and static-export output from other frameworks. Dynamic Next.js/React/Angular source repositories must first produce HTML output; the importer detects those projects and will not pretend unbuilt source code is a finished website.', 'pin-site-importer' ); ?></p>

            <?php if ( is_array( $history ) && $history ) : ?>
                <h2><?php esc_html_e( 'Recent imports', 'pin-site-importer' ); ?></h2>
                <table class="widefat striped" style="max-width:960px">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Repository', 'pin-site-importer' ); ?></th>
                            <th><?php esc_html_e( 'Branch', 'pin-site-importer' ); ?></th>
                            <th><?php esc_html_e( 'Technology', 'pin-site-importer' ); ?></th>
                            <th><?php esc_html_e( 'Pages', 'pin-site-importer' ); ?></th>
                            <th><?php esc_html_e( 'Media', 'pin-site-importer' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( array_slice( $history, 0, 10 ) as $item ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $item['repository'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $item['branch'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $item['technology'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $item['imported'] ?? 0 ) ); ?> / <?php echo esc_html( (string) ( $item['failed'] ?? 0 ) ); ?> failed</td>
                            <td><?php echo esc_html( (string) ( $item['media'] ?? 0 ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to import websites.', 'pin-site-importer' ) );
        }

        check_admin_referer( 'pin_site_importer_run' );

        $repo_url = isset( $_POST['repo_url'] ) ? esc_url_raw( wp_unslash( $_POST['repo_url'] ) ) : '';
        $branch   = isset( $_POST['branch'] ) ? sanitize_text_field( wp_unslash( $_POST['branch'] ) ) : '';
        $token    = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $source_dir = isset( $_POST['source_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['source_dir'] ) ) : '';

        $importer = new PIN_Site_Importer_Importer();
        $result   = $importer->run(
            array(
                'repo_url'     => $repo_url,
                'branch'       => $branch,
                'token'        => $token,
                'source_dir'   => $source_dir,
                'post_status'  => isset( $_POST['post_status'] ) ? sanitize_key( wp_unslash( $_POST['post_status'] ) ) : 'draft',
                'import_media' => ! empty( $_POST['import_media'] ),
                'import_css'   => ! empty( $_POST['import_css'] ),
                'set_home'     => ! empty( $_POST['set_home'] ),
                'create_navigation' => ! empty( $_POST['create_navigation'] ),
            )
        );

        $key = 'pin_site_importer_result_' . get_current_user_id();

        if ( is_wp_error( $result ) ) {
            set_transient(
                $key,
                array(
                    'success' => false,
                    'message' => $result->get_error_message(),
                ),
                300
            );
        } else {
            set_transient(
                $key,
                array(
                    'success' => true,
                    'data'    => $result,
                ),
                300
            );
        }

        wp_safe_redirect( admin_url( 'tools.php?page=pin-site-importer' ) );
        exit;
    }

    /**
     * Use the most recently imported Navigation in the theme header when the
     * Navigation block does not already reference a user-selected menu.
     *
     * @param array<string,mixed> $parsed_block
     * @return array<string,mixed>
     */
    public static function inject_navigation_ref( array $parsed_block, array $source_block ): array {
        if ( 'core/navigation' !== ( $parsed_block['blockName'] ?? '' ) ) {
            return $parsed_block;
        }

        if ( ! empty( $parsed_block['attrs']['ref'] ) ) {
            return $parsed_block;
        }

        $theme = wp_get_theme();

        if ( 'pin-universal-fse' !== $theme->get( 'TextDomain' ) ) {
            return $parsed_block;
        }

        $navigation_id = absint( get_option( 'pin_site_importer_navigation_id' ) );

        if ( ! $navigation_id || 'wp_navigation' !== get_post_type( $navigation_id ) ) {
            return $parsed_block;
        }

        if ( ! isset( $parsed_block['attrs'] ) || ! is_array( $parsed_block['attrs'] ) ) {
            $parsed_block['attrs'] = array();
        }

        $parsed_block['attrs']['ref'] = $navigation_id;

        return $parsed_block;
    }

    public static function enqueue_imported_css(): void {
        if ( ! is_singular( 'page' ) ) {
            return;
        }

        $post_id = get_queried_object_id();
        $css     = (string) get_post_meta( $post_id, '_pin_import_css', true );

        if ( '' === $css ) {
            return;
        }

        wp_register_style( 'pin-site-importer-page', false, array(), PIN_SITE_IMPORTER_VERSION );
        wp_enqueue_style( 'pin-site-importer-page' );
        wp_add_inline_style( 'pin-site-importer-page', $css );
    }

    public static function enqueue_editor_css(): void {
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

        if ( ! $post_id ) {
            return;
        }

        $css = (string) get_post_meta( $post_id, '_pin_import_css', true );

        if ( '' === $css ) {
            return;
        }

        wp_register_style( 'pin-site-importer-editor', false, array(), PIN_SITE_IMPORTER_VERSION );
        wp_enqueue_style( 'pin-site-importer-editor' );
        wp_add_inline_style( 'pin-site-importer-editor', $css );
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function consume_result(): ?array {
        $key    = 'pin_site_importer_result_' . get_current_user_id();
        $result = get_transient( $key );

        if ( false === $result ) {
            return null;
        }

        delete_transient( $key );

        return is_array( $result ) ? $result : null;
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private static function render_result( ?array $result ): void {
        if ( ! $result ) {
            return;
        }

        if ( empty( $result['success'] ) ) {
            ?>
            <div class="notice notice-error"><p><?php echo esc_html( (string) ( $result['message'] ?? __( 'Import failed.', 'pin-site-importer' ) ) ); ?></p></div>
            <?php
            return;
        }

        $data = is_array( $result['data'] ?? null ) ? $result['data'] : array();
        ?>
        <div class="notice notice-success">
            <p>
                <strong><?php esc_html_e( 'Import completed.', 'pin-site-importer' ); ?></strong>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: imported page count, 2: failed page count, 3: media count. */
                        __( '%1$d pages imported, %2$d failed, %3$d media files added.', 'pin-site-importer' ),
                        (int) ( $data['pages_imported'] ?? 0 ),
                        (int) ( $data['pages_failed'] ?? 0 ),
                        (int) ( $data['media_imported'] ?? 0 )
                    )
                );
                ?>
            </p>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: repository, 2: branch, 3: technology. */
                        __( 'Source: %1$s · Branch: %2$s · Detected: %3$s', 'pin-site-importer' ),
                        (string) ( $data['repository'] ?? '' ),
                        (string) ( $data['branch'] ?? '' ),
                        (string) ( $data['technology'] ?? '' )
                    )
                );
                ?>
            </p>
        </div>
        <?php

        if ( ! empty( $data['warnings'] ) && is_array( $data['warnings'] ) ) {
            ?>
            <div class="notice notice-warning">
                <p><strong><?php esc_html_e( 'Import warnings:', 'pin-site-importer' ); ?></strong></p>
                <ul>
                    <?php foreach ( array_slice( $data['warnings'], 0, 20 ) as $warning ) : ?>
                        <li><?php echo esc_html( (string) $warning ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
        }
    }
}
