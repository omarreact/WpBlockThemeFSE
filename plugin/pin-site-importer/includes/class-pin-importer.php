<?php
/**
 * Import orchestration.
 */

defined( 'ABSPATH' ) || exit;

final class PIN_Site_Importer_Importer {
    private const MAX_PAGES = 75;
    private const MAX_CSS_BYTES_PER_PAGE = 524288;

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|WP_Error
     */
    public function run( array $args ) {
        $repo_url       = esc_url_raw( (string) ( $args['repo_url'] ?? '' ) );
        $branch         = sanitize_text_field( (string) ( $args['branch'] ?? '' ) );
        $token          = sanitize_text_field( (string) ( $args['token'] ?? '' ) );
        $source_dir     = trim( str_replace( '\\\\', '/', sanitize_text_field( (string) ( $args['source_dir'] ?? '' ) ) ), '/' );
        $post_status    = 'publish' === ( $args['post_status'] ?? '' ) ? 'publish' : 'draft';
        $import_media   = ! empty( $args['import_media'] );
        $import_css     = ! empty( $args['import_css'] );
        $set_home       = ! empty( $args['set_home'] );
        $create_navigation = ! empty( $args['create_navigation'] );
        $github         = new PIN_Site_Importer_GitHub_Client( $token );
        $repository     = $github->inspect_repository( $repo_url, $branch );

        if ( is_wp_error( $repository ) ) {
            return $repository;
        }

        if ( '' !== $source_dir ) {
            if ( false !== strpos( $source_dir, '..' ) ) {
                return new WP_Error(
                    'pin_invalid_source_directory',
                    __( 'The source directory cannot contain parent-directory segments.', 'pin-site-importer' )
                );
            }

            $prefix = trailingslashit( $source_dir );
            $repository['html_files'] = array_values(
                array_filter(
                    $repository['html_files'],
                    static fn( $path ) => str_starts_with( $path, $prefix )
                )
            );
        }

        $html_files = array_slice( $repository['html_files'], 0, self::MAX_PAGES );

        if ( empty( $html_files ) ) {
            return new WP_Error(
                'pin_no_html_files',
                sprintf(
                    /* translators: %s: detected technology. */
                    __( 'No importable .html files were found. Detected project type: %s. Export/build the project to static HTML first.', 'pin-site-importer' ),
                    $repository['technology']
                )
            );
        }

        $page_map = $this->create_page_placeholders(
            $repository['full_name'],
            $repository['branch'],
            $html_files,
            $post_status
        );

        if ( is_wp_error( $page_map ) ) {
            return $page_map;
        }

        $result = array(
            'repository'       => $repository['full_name'],
            'branch'           => $repository['branch'],
            'technology'       => $repository['technology'],
            'source_dir'       => $source_dir,
            'pages_discovered' => count( $repository['html_files'] ),
            'pages_imported'   => 0,
            'pages_failed'     => 0,
            'media_imported'   => 0,
            'css_files'        => 0,
            'warnings'         => array(),
            'page_ids'         => array(),
            'navigation_id'    => 0,
        );

        if ( count( $repository['html_files'] ) > self::MAX_PAGES ) {
            $result['warnings'][] = sprintf(
                /* translators: %d: page cap. */
                __( 'The repository contains more than %d HTML pages; this run imported only the first batch.', 'pin-site-importer' ),
                self::MAX_PAGES
            );
        }

        foreach ( $html_files as $path ) {
            $post_id = $page_map[ $path ] ?? 0;

            if ( ! $post_id ) {
                ++$result['pages_failed'];
                continue;
            }

            $html = $github->get_file(
                $repository['owner'],
                $repository['repo'],
                $repository['branch'],
                $path,
                $source_dir
            );

            if ( is_wp_error( $html ) ) {
                ++$result['pages_failed'];
                $result['warnings'][] = $html->get_error_message();
                continue;
            }

            $converter = new PIN_Site_Importer_HTML_To_Blocks(
                $github,
                $page_map,
                $repository['owner'],
                $repository['repo'],
                $repository['branch'],
                $path
            );

            $converted = $converter->convert( $html );

            if ( is_wp_error( $converted ) ) {
                ++$result['pages_failed'];
                $result['warnings'][] = $converted->get_error_message();
                continue;
            }

            $content = $converted['content'];

            if ( $import_media && ! empty( $converted['images'] ) ) {
                $media_result = $this->sideload_images( $post_id, $content, $converted['images'] );
                $content      = $media_result['content'];
                $result['media_imported'] += $media_result['count'];
                $result['warnings']        = array_merge( $result['warnings'], $media_result['warnings'] );
            }

            $title = '' !== $converted['title']
                ? $converted['title']
                : $this->fallback_title( $path );

            $updated = wp_update_post(
                array(
                    'ID'           => $post_id,
                    'post_title'   => $title,
                    'post_content' => $content,
                    'post_status'  => $post_status,
                ),
                true
            );

            if ( is_wp_error( $updated ) ) {
                ++$result['pages_failed'];
                $result['warnings'][] = $updated->get_error_message();
                continue;
            }

            if ( $import_css && ! empty( $converted['stylesheets'] ) ) {
                $css = $this->collect_css(
                    $github,
                    $repository,
                    $path,
                    $converted['stylesheets']
                );

                if ( is_wp_error( $css ) ) {
                    $result['warnings'][] = $css->get_error_message();
                } elseif ( '' !== $css['content'] ) {
                    update_post_meta( $post_id, '_pin_import_css', $css['content'] );
                    $result['css_files'] += $css['count'];
                }
            }

            update_post_meta( $post_id, '_pin_import_source_repo', $repository['full_name'] );
            update_post_meta( $post_id, '_pin_import_source_branch', $repository['branch'] );
            update_post_meta( $post_id, '_pin_import_source_path', $path );
            update_post_meta( $post_id, '_pin_imported_at', gmdate( 'c' ) );

            ++$result['pages_imported'];
            $result['page_ids'][] = $post_id;
        }

        if ( $create_navigation ) {
            $navigation_id = $this->create_navigation( $page_map, $repository['full_name'] );

            if ( is_wp_error( $navigation_id ) ) {
                $result['warnings'][] = $navigation_id->get_error_message();
            } else {
                $result['navigation_id'] = (int) $navigation_id;
            }
        }

        if ( $set_home ) {
            $home_path = $this->find_home_path( $html_files );

            if ( $home_path && isset( $page_map[ $home_path ] ) ) {
                update_option( 'show_on_front', 'page' );
                update_option( 'page_on_front', (int) $page_map[ $home_path ] );
                $result['home_page_id'] = (int) $page_map[ $home_path ];
            }
        }

        $this->store_history( $result );

        return $result;
    }

    /**
     * @param string[] $paths
     * @return array<string,int>|WP_Error
     */
    private function create_page_placeholders( string $repo, string $branch, array $paths, string $post_status ) {
        $map = array();

        foreach ( $paths as $path ) {
            $existing = get_posts(
                array(
                    'post_type'      => 'page',
                    'post_status'    => 'any',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        'relation' => 'AND',
                        array(
                            'key'   => '_pin_import_source_repo',
                            'value' => $repo,
                        ),
                        array(
                            'key'   => '_pin_import_source_branch',
                            'value' => $branch,
                        ),
                        array(
                            'key'   => '_pin_import_source_path',
                            'value' => $path,
                        ),
                    ),
                )
            );

            if ( $existing ) {
                $map[ $path ] = (int) $existing[0];
                continue;
            }

            $post_id = wp_insert_post(
                array(
                    'post_type'    => 'page',
                    'post_status'  => 'draft',
                    'post_title'   => $this->fallback_title( $path ),
                    'post_name'    => $this->path_to_slug( $path ),
                    'post_content' => '',
                ),
                true
            );

            if ( is_wp_error( $post_id ) ) {
                return $post_id;
            }

            update_post_meta( $post_id, '_pin_import_source_repo', $repo );
            update_post_meta( $post_id, '_pin_import_source_branch', $branch );
            update_post_meta( $post_id, '_pin_import_source_path', $path );

            if ( 'publish' === $post_status ) {
                wp_update_post(
                    array(
                        'ID'          => $post_id,
                        'post_status' => 'draft',
                    )
                );
            }

            $map[ $path ] = (int) $post_id;
        }

        return $map;
    }

    /**
     * @param array<string,string> $images
     * @return array{content:string,count:int,warnings:string[]}
     */
    private function sideload_images( int $post_id, string $content, array $images ): array {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $count    = 0;
        $warnings = array();

        foreach ( $images as $original => $remote_url ) {
            if ( '' === $remote_url ) {
                continue;
            }

            $host = strtolower( (string) wp_parse_url( $remote_url, PHP_URL_HOST ) );

            if ( ! in_array( $host, array( 'raw.githubusercontent.com', 'githubusercontent.com' ), true ) ) {
                continue;
            }

            $existing = get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_key'       => '_source_url',
                    'meta_value'     => $remote_url,
                )
            );

            if ( $existing ) {
                $attachment_url = wp_get_attachment_url( (int) $existing[0] );
            } else {
                $attachment_id = media_sideload_image( $remote_url, $post_id, null, 'id' );

                if ( is_wp_error( $attachment_id ) ) {
                    $warnings[] = sprintf(
                        /* translators: 1: image URL, 2: error message. */
                        __( 'Image import failed for %1$s: %2$s', 'pin-site-importer' ),
                        $remote_url,
                        $attachment_id->get_error_message()
                    );
                    continue;
                }

                $attachment_url = wp_get_attachment_url( (int) $attachment_id );
                ++$count;
            }

            if ( $attachment_url ) {
                $content = str_replace(
                    array( esc_url( $remote_url ), $remote_url ),
                    esc_url( $attachment_url ),
                    $content
                );
            }
        }

        return array(
            'content'  => $content,
            'count'    => $count,
            'warnings' => $warnings,
        );
    }

    /**
     * @param array<string,mixed> $repository
     * @param string[]            $stylesheets
     * @return array{content:string,count:int}|WP_Error
     */
    private function collect_css(
        PIN_Site_Importer_GitHub_Client $github,
        array $repository,
        string $page_path,
        array $stylesheets
    ) {
        $content = '';
        $count   = 0;

        foreach ( array_slice( $stylesheets, 0, 12 ) as $css_path ) {
            $css = $github->get_file(
                $repository['owner'],
                $repository['repo'],
                $repository['branch'],
                $css_path,
                262144
            );

            if ( is_wp_error( $css ) ) {
                return $css;
            }

            $css = $this->rewrite_css_urls(
                $css,
                $github,
                $repository,
                $css_path
            );

            if ( strlen( $content ) + strlen( $css ) > self::MAX_CSS_BYTES_PER_PAGE ) {
                break;
            }

            $content .= "\n/* " . sanitize_text_field( $css_path ) . " */\n" . $css;
            ++$count;
        }

        return array(
            'content' => trim( $content ),
            'count'   => $count,
        );
    }

    /**
     * @param array<string,mixed> $repository
     */
    private function rewrite_css_urls(
        string $css,
        PIN_Site_Importer_GitHub_Client $github,
        array $repository,
        string $css_path
    ): string {
        return (string) preg_replace_callback(
            '~url\(\s*([\'\"]?)(?!data:|https?:|//|#)([^)\'\"]+)\1\s*\)~i',
            static function ( array $matches ) use ( $github, $repository, $css_path ): string {
                $asset_path = $github->resolve_path( $css_path, trim( $matches[2] ) );
                $raw_url    = $github->raw_url(
                    $repository['owner'],
                    $repository['repo'],
                    $repository['branch'],
                    $asset_path
                );

                return 'url("' . esc_url_raw( $raw_url ) . '")';
            },
            $css
        );
    }

    /**
     * Build or refresh a WordPress Navigation entity from imported pages.
     *
     * @param array<string,int> $page_map
     * @return int|WP_Error
     */
    private function create_navigation( array $page_map, string $repository ) {
        if ( ! post_type_exists( 'wp_navigation' ) ) {
            return new WP_Error(
                'pin_navigation_post_type_missing',
                __( 'WordPress Navigation storage is not available on this installation.', 'pin-site-importer' )
            );
        }

        $content = '';

        foreach ( $page_map as $path => $post_id ) {
            $post = get_post( $post_id );

            if ( ! $post || 'trash' === $post->post_status ) {
                continue;
            }

            $label = get_the_title( $post_id );

            if ( '' === trim( $label ) ) {
                $label = $this->fallback_title( $path );
            }

            $attrs = array(
                'label'          => $label,
                'type'           => 'page',
                'id'             => (int) $post_id,
                'url'            => get_permalink( $post_id ),
                'kind'           => 'post-type',
                'isTopLevelLink' => true,
            );

            $content .= '<!-- wp:navigation-link ' . wp_json_encode( $attrs ) . ' /-->' . "\n";
        }

        if ( '' === trim( $content ) ) {
            return new WP_Error(
                'pin_navigation_empty',
                __( 'No imported pages were available for navigation creation.', 'pin-site-importer' )
            );
        }

        $slug = 'pin-imported-' . substr( md5( $repository ), 0, 12 );
        $existing = get_page_by_path( $slug, OBJECT, 'wp_navigation' );

        $postarr = array(
            'post_type'    => 'wp_navigation',
            'post_status'  => 'publish',
            'post_title'   => sprintf(
                /* translators: %s: repository full name. */
                __( 'Imported Navigation — %s', 'pin-site-importer' ),
                $repository
            ),
            'post_name'    => $slug,
            'post_content' => trim( $content ),
        );

        if ( $existing ) {
            $postarr['ID'] = (int) $existing->ID;
        }

        $navigation_id = wp_insert_post( $postarr, true );

        if ( is_wp_error( $navigation_id ) ) {
            return $navigation_id;
        }

        update_post_meta( $navigation_id, '_pin_import_source_repo', $repository );
        update_option( 'pin_site_importer_navigation_id', (int) $navigation_id, false );

        return (int) $navigation_id;
    }

    private function fallback_title( string $path ): string {
        $basename = pathinfo( $path, PATHINFO_FILENAME );

        if ( 'index' === strtolower( $basename ) ) {
            $directory = basename( dirname( $path ) );
            $basename  = '.' === $directory ? 'Home' : $directory;
        }

        return ucwords( str_replace( array( '-', '_' ), ' ', $basename ) );
    }

    private function path_to_slug( string $path ): string {
        $path = preg_replace( '/\.(?:html?|HTML?)$/', '', $path );
        $path = preg_replace( '~/index$~i', '', $path );
        $path = trim( (string) $path, '/' );

        if ( '' === $path || 'index' === strtolower( $path ) ) {
            return 'home';
        }

        return sanitize_title( str_replace( '/', '-', $path ) );
    }

    /**
     * @param string[] $paths
     */
    private function find_home_path( array $paths ): string {
        foreach ( array( 'index.html', 'index.htm' ) as $candidate ) {
            if ( in_array( $candidate, $paths, true ) ) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $result
     */
    private function store_history( array $result ): void {
        $history = get_option( 'pin_site_importer_history', array() );

        if ( ! is_array( $history ) ) {
            $history = array();
        }

        array_unshift(
            $history,
            array(
                'time'        => gmdate( 'c' ),
                'repository'  => $result['repository'],
                'branch'      => $result['branch'],
                'technology'  => $result['technology'],
                'imported'    => $result['pages_imported'],
                'failed'      => $result['pages_failed'],
                'media'       => $result['media_imported'],
                'css_files'   => $result['css_files'],
            )
        );

        update_option( 'pin_site_importer_history', array_slice( $history, 0, 10 ), false );
    }
}
