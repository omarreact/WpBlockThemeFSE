<?php
/**
 * WordPress admin UI and import action.
 */

defined( 'ABSPATH' ) || exit;

final class PIN_Site_Importer_Admin {
    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_pin_site_importer_run', array( __CLASS__, 'handle_import' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_global_imported_assets' ), 40 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_imported_css' ), 50 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_imported_scripts' ), 60 );
        add_filter( 'block_editor_settings_all', array( __CLASS__, 'filter_editor_settings' ), 20, 2 );
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
                        <th scope="row"><label for="pin-source-format"><?php esc_html_e( 'Source format', 'pin-site-importer' ); ?></label></th>
                        <td>
                            <select id="pin-source-format" name="source_format">
                                <option value="auto"><?php esc_html_e( 'Auto: JSON → WXR/XML → HTML', 'pin-site-importer' ); ?></option>
                                <option value="json"><?php esc_html_e( 'Structured JSON', 'pin-site-importer' ); ?></option>
                                <option value="wxr"><?php esc_html_e( 'WordPress WXR/XML', 'pin-site-importer' ); ?></option>
                                <option value="html"><?php esc_html_e( 'Rendered HTML only', 'pin-site-importer' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Auto mode prefers site-manifest.json/content.json, then WordPress export XML, then rendered HTML.', 'pin-site-importer' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pin-token"><?php esc_html_e( 'GitHub token (optional)', 'pin-site-importer' ); ?></label></th>
                        <td>
                            <input id="pin-token" name="token" type="password" class="regular-text" autocomplete="off">
                            <p class="description"><?php esc_html_e( 'Used only for this request and never saved. Public file bodies use GitHub raw content without consuming core API quota; add a token if this server IP is rate-limited for repository metadata/tree discovery.', 'pin-site-importer' ); ?></p>
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
                            <label><input type="checkbox" name="import_scripts" value="1" checked> <?php esc_html_e( 'Mirror and load repository JavaScript referenced by imported pages', 'pin-site-importer' ); ?></label><br>
                            <label><input type="checkbox" name="mirror_assets" value="1" checked> <?php esc_html_e( 'Mirror safe static build assets/chunks/fonts into WordPress uploads', 'pin-site-importer' ); ?></label><br>
                            <label><input type="checkbox" name="import_external_scripts" value="1"> <?php esc_html_e( 'Load external/CDN JavaScript (trusted repositories only)', 'pin-site-importer' ); ?></label><br>
                            <label><input type="checkbox" name="set_home" value="1"> <?php esc_html_e( 'Set root index.html as the WordPress homepage when applying globally', 'pin-site-importer' ); ?></label><br>
                            <label><input type="checkbox" name="create_navigation" value="1" checked> <?php esc_html_e( 'Create/update WordPress Navigation from imported pages', 'pin-site-importer' ); ?></label><br>
                            <label><input type="checkbox" name="import_template_parts" value="1" checked> <?php esc_html_e( 'Import source header/footer as editable FSE template parts', 'pin-site-importer' ); ?></label><br>
                            <label><input type="checkbox" name="apply_global" value="1"> <strong><?php esc_html_e( 'Apply imported header/footer/navigation/homepage to the active site', 'pin-site-importer' ); ?></strong></label>
                            <p class="description"><?php esc_html_e( 'Safety gate: this only takes effect when Imported page status is Publish. Leave unchecked while reviewing a draft import.', 'pin-site-importer' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Import GitHub Website', 'pin-site-importer' ) ); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Supported in this phase', 'pin-site-importer' ); ?></h2>
            <p><?php esc_html_e( 'Structured JSON, WordPress WXR/XML, static HTML repositories and static-export output from other frameworks. Dynamic Next.js/React/Angular source repositories must first produce HTML output; the importer detects those projects and will not pretend unbuilt source code is a finished website.', 'pin-site-importer' ); ?></p>

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
        $source_format = isset( $_POST['source_format'] ) ? sanitize_key( wp_unslash( $_POST['source_format'] ) ) : 'auto';

        $importer = new PIN_Site_Importer_Importer();
        $result   = $importer->run(
            array(
                'repo_url'     => $repo_url,
                'branch'       => $branch,
                'token'        => $token,
                'source_dir'   => $source_dir,
                'source_format'=> $source_format,
                'post_status'  => isset( $_POST['post_status'] ) ? sanitize_key( wp_unslash( $_POST['post_status'] ) ) : 'draft',
                'import_media' => ! empty( $_POST['import_media'] ),
                'import_css'   => ! empty( $_POST['import_css'] ),
                'import_scripts' => ! empty( $_POST['import_scripts'] ),
                'mirror_assets' => ! empty( $_POST['mirror_assets'] ),
                'import_external_scripts' => ! empty( $_POST['import_external_scripts'] ),
                'set_home'     => ! empty( $_POST['set_home'] ),
                'create_navigation' => ! empty( $_POST['create_navigation'] ),
                'import_template_parts' => ! empty( $_POST['import_template_parts'] ),
                'apply_global' => ! empty( $_POST['apply_global'] ),
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

        if ( ! empty( $parsed_block['innerBlocks'] ) ) {
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
        self::enqueue_external_styles_for_post( $post_id, 'front' );

        $css = (string) get_post_meta( $post_id, '_pin_import_css', true );

        if ( '' === $css ) {
            return;
        }

        wp_register_style( 'pin-site-importer-page', false, array(), PIN_SITE_IMPORTER_VERSION );
        wp_enqueue_style( 'pin-site-importer-page' );
        wp_add_inline_style( 'pin-site-importer-page', $css );
    }

    public static function enqueue_global_imported_assets(): void {
        $post_id = self::find_imported_global_template_part();

        if ( ! $post_id ) {
            return;
        }

        self::enqueue_external_styles_for_post( $post_id, 'global' );

        $css = trim( (string) get_post_meta( $post_id, '_pin_import_css', true ) );

        if ( '' !== $css ) {
            wp_register_style(
                'pin-site-importer-global',
                false,
                array(),
                PIN_SITE_IMPORTER_VERSION
            );
            wp_enqueue_style( 'pin-site-importer-global' );
            wp_add_inline_style( 'pin-site-importer-global', $css );
        }

        self::enqueue_scripts_for_post( $post_id );
    }

    public static function enqueue_imported_scripts(): void {
        if ( ! is_singular( 'page' ) ) {
            return;
        }

        self::enqueue_scripts_for_post( get_queried_object_id() );
    }

    private static function enqueue_scripts_for_post( int $post_id ): void {
        $scripts = get_post_meta( $post_id, '_pin_import_scripts', true );

        if ( ! is_array( $scripts ) ) {
            $scripts = array();
        }

        foreach ( $scripts as $script ) {
            if ( ! is_array( $script ) || empty( $script['url'] ) ) {
                continue;
            }

            $url      = esc_url_raw( (string) $script['url'] );
            $type     = (string) ( $script['type'] ?? 'classic' );
            $strategy = (string) ( $script['strategy'] ?? '' );

            if ( '' === $url ) {
                continue;
            }

            $handle = 'pin-imported-script-' . substr( md5( $type . '|' . $url ), 0, 12 );

            if ( 'module' === $type && function_exists( 'wp_enqueue_script_module' ) ) {
                wp_enqueue_script_module( $handle, $url, array(), null );
                continue;
            }

            $args = array( 'in_footer' => true );

            if ( in_array( $strategy, array( 'async', 'defer' ), true ) ) {
                $args['strategy'] = $strategy;
            }

            wp_enqueue_script( $handle, $url, array(), null, $args );
        }

        $external_scripts = get_post_meta( $post_id, '_pin_import_external_scripts', true );

        if ( ! is_array( $external_scripts ) ) {
            return;
        }

        foreach ( array_slice( $external_scripts, 0, 16 ) as $script ) {
            if ( ! is_array( $script ) || empty( $script['url'] ) ) {
                continue;
            }

            $url = esc_url_raw( (string) $script['url'], array( 'http', 'https' ) );

            if ( '' === $url ) {
                continue;
            }

            $type     = (string) ( $script['type'] ?? 'classic' );
            $strategy = (string) ( $script['strategy'] ?? '' );
            $handle   = 'pin-imported-script-' . substr( md5( $type . '|' . $url ), 0, 12 );

            if ( 'module' === $type && function_exists( 'wp_enqueue_script_module' ) ) {
                wp_enqueue_script_module( $handle, $url, array(), null );
                continue;
            }

            $args = array( 'in_footer' => true );

            if ( in_array( $strategy, array( 'async', 'defer' ), true ) ) {
                $args['strategy'] = $strategy;
            }

            wp_enqueue_script( $handle, $url, array(), null, $args );
        }
    }

    /**
     * Inject imported page/template-part CSS into the actual block-editor
     * content iframe. This works for the Post Editor and Site Editor contexts.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public static function filter_editor_settings( array $settings, WP_Block_Editor_Context $context ): array {
        if ( empty( $context->post ) || ! $context->post instanceof WP_Post ) {
            return $settings;
        }

        $post_id = (int) $context->post->ID;
        $css     = trim( (string) get_post_meta( $post_id, '_pin_import_css', true ) );
        $imports = array();
        $external_styles = get_post_meta( $post_id, '_pin_import_external_css', true );

        if ( is_array( $external_styles ) ) {
            foreach ( array_slice( $external_styles, 0, 16 ) as $url ) {
                $url = esc_url_raw( (string) $url, array( 'http', 'https' ) );

                if ( '' === $url ) {
                    continue;
                }

                $url = str_replace(
                    array( '"', "'" ),
                    array( '%22', '%27' ),
                    $url
                );
                $imports[] = '@import url("' . $url . '");';
            }
        }

        $editor_css = trim( implode( "\n", $imports ) . "\n" . $css );

        if ( '' === $editor_css ) {
            return $settings;
        }

        if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
            $settings['styles'] = array();
        }

        $settings['styles'][] = array(
            'css' => $editor_css,
        );

        return $settings;
    }

    private static function enqueue_external_styles_for_post( int $post_id, string $context ): void {
        $styles = get_post_meta( $post_id, '_pin_import_external_css', true );

        if ( ! is_array( $styles ) ) {
            return;
        }

        foreach ( array_slice( $styles, 0, 16 ) as $index => $url ) {
            $url = esc_url_raw( (string) $url, array( 'http', 'https' ) );

            if ( '' === $url ) {
                continue;
            }

            wp_enqueue_style(
                'pin-imported-style-' . substr( md5( $url ), 0, 12 ),
                $url,
                array(),
                null
            );
        }
    }

    private static function find_imported_global_template_part(): int {
        if ( ! post_type_exists( 'wp_template_part' ) ) {
            return 0;
        }

        foreach ( array( 'header', 'footer' ) as $area ) {
            $posts = get_posts(
                array(
                    'post_type'      => 'wp_template_part',
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'name'           => $area,
                    'meta_query'     => array(
                        array(
                            'key'     => '_pin_import_source_repo',
                            'compare' => 'EXISTS',
                        ),
                    ),
                    'tax_query'      => array(
                        array(
                            'taxonomy' => 'wp_theme',
                            'field'    => 'name',
                            'terms'    => get_stylesheet(),
                        ),
                    ),
                )
            );

            if ( $posts ) {
                return (int) $posts[0];
            }
        }

        return 0;
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
                        /* translators: 1: imported page count, 2: failed page count, 3: media count, 4: script count, 5: static asset count. */
                        __( '%1$d pages imported, %2$d failed, %3$d media files added; %4$d scripts and %5$d static assets mirrored.', 'pin-site-importer' ),
                        (int) ( $data['pages_imported'] ?? 0 ),
                        (int) ( $data['pages_failed'] ?? 0 ),
                        (int) ( $data['media_imported'] ?? 0 ),
                        (int) ( $data['scripts_mirrored'] ?? 0 ),
                        (int) ( $data['assets_mirrored'] ?? 0 )
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
