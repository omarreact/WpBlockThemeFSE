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
        $source_dir     = trim( str_replace( chr( 92 ), '/', sanitize_text_field( (string) ( $args['source_dir'] ?? '' ) ) ), '/' );
        $source_format  = sanitize_key( (string) ( $args['source_format'] ?? 'auto' ) );
        if ( ! in_array( $source_format, array( 'auto', 'html', 'json', 'wxr' ), true ) ) {
            $source_format = 'auto';
        }
        $post_status    = 'publish' === ( $args['post_status'] ?? '' ) ? 'publish' : 'draft';
        $import_media   = ! empty( $args['import_media'] );
        $import_css     = ! empty( $args['import_css'] );
        $import_scripts = ! empty( $args['import_scripts'] );
        $mirror_assets  = ! empty( $args['mirror_assets'] );
        $import_external_scripts = ! empty( $args['import_external_scripts'] );
        $set_home       = ! empty( $args['set_home'] );
        $create_navigation = ! empty( $args['create_navigation'] );
        $import_template_parts = ! empty( $args['import_template_parts'] );
        $apply_global_requested = ! empty( $args['apply_global'] );
        $apply_global = $apply_global_requested && 'publish' === $post_status;
        $github         = new PIN_Site_Importer_GitHub_Client( $token );
        $repository     = $github->inspect_repository( $repo_url, $branch );

        if ( is_wp_error( $repository ) ) {
            return $repository;
        }

        $structured_source = new PIN_Site_Importer_Structured_Source( $github, $repository );
        $structured_json   = null;

        if ( 'html' !== $source_format ) {
            if ( in_array( $source_format, array( 'auto', 'json' ), true ) ) {
                $structured_json = $structured_source->load_json( $source_dir );

                if ( is_wp_error( $structured_json ) ) {
                    return $structured_json;
                }

                if ( null === $structured_json && 'json' === $source_format ) {
                    return new WP_Error(
                        'pin_json_source_missing',
                        __( 'JSON source mode was selected but no site-manifest.json or content.json was found.', 'pin-site-importer' )
                    );
                }
            }

            if ( null === $structured_json && in_array( $source_format, array( 'auto', 'wxr' ), true ) ) {
                $wxr_result = $structured_source->import_wxr( $source_dir, $post_status );

                if ( is_wp_error( $wxr_result ) ) {
                    return $wxr_result;
                }

                if ( is_array( $wxr_result ) ) {
                    $this->store_history( $wxr_result );
                    return $wxr_result;
                }

                if ( 'wxr' === $source_format ) {
                    return new WP_Error(
                        'pin_wxr_source_missing',
                        __( 'WXR/XML source mode was selected but no WordPress export XML file was found.', 'pin-site-importer' )
                    );
                }
            }
        }

        $auto_source_dir = false;

        if ( null === $structured_json && '' === $source_dir && ! empty( $repository['recommended_source_dir'] ) ) {
            $source_dir      = (string) $repository['recommended_source_dir'];
            $auto_source_dir = true;
        }

        $assets = new PIN_Site_Importer_Asset_Manager( $github, $repository );

        if ( '' !== $source_dir && false !== strpos( $source_dir, '..' ) ) {
            return new WP_Error(
                'pin_invalid_source_directory',
                __( 'The source directory cannot contain parent-directory segments.', 'pin-site-importer' )
            );
        }

        if ( is_array( $structured_json ) ) {
            $repository['html_files'] = array_keys( (array) $structured_json['pages'] );
            $repository['technology'] = 'Structured JSON';
        } elseif ( '' !== $source_dir ) {
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

        $preferred_template_source = $this->find_home_path( $html_files, $source_dir );

        if ( '' === $preferred_template_source ) {
            $preferred_template_source = (string) $html_files[0];
        }

        $page_map = $this->create_page_placeholders(
            $repository['full_name'],
            $repository['branch'],
            $html_files,
            $post_status,
            $source_dir
        );

        if ( is_wp_error( $page_map ) ) {
            return $page_map;
        }

        if ( is_array( $structured_json ) ) {
            $this->apply_structured_page_metadata( $page_map, (array) $structured_json['pages'] );
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
            'scripts_mirrored' => 0,
            'assets_mirrored'  => 0,
            'warnings'         => array(),
            'page_ids'         => array(),
            'navigation_id'    => 0,
            'template_parts'   => array(),
            'global_applied'   => $apply_global,
            'source_mode'      => is_array( $structured_json ) ? 'json' : 'html',
            'auto_source_dir'  => $auto_source_dir,
            'static_roots'     => (array) ( $repository['static_roots'] ?? array() ),
        );

        if ( $auto_source_dir ) {
            $result['warnings'][] = sprintf(
                /* translators: %s: detected static build directory. */
                __( 'Automatically selected the detected static build folder: %s.', 'pin-site-importer' ),
                $source_dir
            );
        }

        if ( $apply_global_requested && ! $apply_global ) {
            $result['warnings'][] = __(
                'Global header/footer/navigation/homepage changes were not applied because imported pages are drafts. Publish the import and explicitly enable global application to change the live site.',
                'pin-site-importer'
            );
        }

        if ( $mirror_assets ) {
            $asset_mirror = $assets->mirror_tree_assets(
                (array) $repository['tree'],
                $source_dir
            );
            $result['assets_mirrored'] = (int) $asset_mirror['count'];

            if ( ! empty( $asset_mirror['warnings'] ) ) {
                $result['warnings'] = array_merge(
                    $result['warnings'],
                    (array) $asset_mirror['warnings']
                );
            }
        }

        if ( count( $repository['html_files'] ) > self::MAX_PAGES ) {
            $result['warnings'][] = sprintf(
                /* translators: %d: page cap. */
                __( 'The repository contains more than %d HTML pages; this run imported only the first batch.', 'pin-site-importer' ),
                self::MAX_PAGES
            );
        }

        $template_part_payload = array(
            'header'           => '',
            'footer'           => '',
            'css'              => '',
            'external_css'     => array(),
            'scripts'          => array(),
            'external_scripts' => array(),
        );

        foreach ( $html_files as $path ) {
            $post_id = $page_map[ $path ] ?? 0;

            if ( ! $post_id ) {
                ++$result['pages_failed'];
                continue;
            }

            if ( is_array( $structured_json ) && isset( $structured_json['pages'][ $path ]['html'] ) ) {
                $html = (string) $structured_json['pages'][ $path ]['html'];
            } else {
                $html = $github->get_file(
                    $repository['owner'],
                    $repository['repo'],
                    $repository['branch'],
                    $path
                );
            }

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
                $path,
                $source_dir
            );

            $converted = $converter->convert( $html );

            if ( is_wp_error( $converted ) ) {
                ++$result['pages_failed'];
                $result['warnings'][] = $converted->get_error_message();
                continue;
            }

            $content        = $converted['content'];
            $header_content = (string) ( $converted['header_content'] ?? '' );
            $footer_content = (string) ( $converted['footer_content'] ?? '' );

            if ( $import_media && ! empty( $converted['images'] ) ) {
                $media_result = $this->sideload_images( $post_id, $content, $converted['images'], $github, $repository );
                $content      = $media_result['content'];
                $result['media_imported'] += $media_result['count'];
                $result['warnings']        = array_merge( $result['warnings'], $media_result['warnings'] );

                if ( '' !== $header_content ) {
                    $header_media = $this->sideload_images( $post_id, $header_content, $converted['images'], $github, $repository );
                    $header_content = $header_media['content'];
                    $result['media_imported'] += $header_media['count'];
                    $result['warnings'] = array_merge( $result['warnings'], $header_media['warnings'] );
                }

                if ( '' !== $footer_content ) {
                    $footer_media = $this->sideload_images( $post_id, $footer_content, $converted['images'], $github, $repository );
                    $footer_content = $footer_media['content'];
                    $result['media_imported'] += $footer_media['count'];
                    $result['warnings'] = array_merge( $result['warnings'], $footer_media['warnings'] );
                }
            }

            $structured_page = is_array( $structured_json ) && isset( $structured_json['pages'][ $path ] )
                ? (array) $structured_json['pages'][ $path ]
                : array();

            $title = ! empty( $structured_page['title'] )
                ? sanitize_text_field( (string) $structured_page['title'] )
                : ( '' !== $converted['title']
                    ? $converted['title']
                    : $this->fallback_title( $path, $source_dir ) );

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

            $page_css = '';

            if ( $import_css ) {
                $page_css    = trim( (string) ( $converted['inline_css'] ?? '' ) );

                if ( is_array( $structured_json ) && ! empty( $structured_json['global_css'] ) ) {
                    $page_css = trim( (string) $structured_json['global_css'] . "
" . $page_css );
                }
                $embedded_css = trim( (string) ( $converted['embedded_css'] ?? '' ) );

                if ( '' !== $embedded_css ) {
                    $embedded_css = $this->rewrite_css_urls(
                        $embedded_css,
                        $github,
                        $repository,
                        $path,
                        $assets,
                        $source_dir
                    );
                    $page_css = trim( $page_css . "\n" . $embedded_css );
                }

                if ( ! empty( $converted['stylesheets'] ) ) {
                    $css = $this->collect_css(
                        $github,
                        $repository,
                        $path,
                        $converted['stylesheets'],
                        $assets,
                        $source_dir
                    );

                    if ( is_wp_error( $css ) ) {
                        $result['warnings'][] = $css->get_error_message();
                    } else {
                        $result['css_files'] += $css['count'];

                        if ( '' !== $css['content'] ) {
                            $page_css = trim( $page_css . "\n" . $css['content'] );
                        }
                    }
                }

                if ( '' !== $page_css ) {
                    update_post_meta( $post_id, '_pin_import_css', $page_css );
                } else {
                    delete_post_meta( $post_id, '_pin_import_css' );
                }
            } else {
                delete_post_meta( $post_id, '_pin_import_css' );
            }

            if ( $import_css && ! empty( $converted['external_stylesheets'] ) ) {
                $external_css = array_values(
                    array_filter(
                        array_map(
                            static fn( $url ) => esc_url_raw( (string) $url, array( 'http', 'https' ) ),
                            (array) $converted['external_stylesheets']
                        )
                    )
                );
                update_post_meta( $post_id, '_pin_import_external_css', $external_css );
            } else {
                delete_post_meta( $post_id, '_pin_import_external_css' );
                $external_css = array();
            }

            $page_scripts          = array();
            $page_external_scripts = array();

            if ( $import_scripts && ! empty( $converted['scripts'] ) ) {
                $scripts = $this->mirror_scripts( $assets, $converted['scripts'] );

                if ( ! empty( $scripts['items'] ) ) {
                    $page_scripts = $scripts['items'];
                    update_post_meta( $post_id, '_pin_import_scripts', $page_scripts );
                    $result['scripts_mirrored'] += count( $page_scripts );
                } else {
                    delete_post_meta( $post_id, '_pin_import_scripts' );
                }

                if ( ! empty( $scripts['warnings'] ) ) {
                    $result['warnings'] = array_merge( $result['warnings'], $scripts['warnings'] );
                }
            } elseif ( ! $import_scripts ) {
                delete_post_meta( $post_id, '_pin_import_scripts' );
            }

            if ( $import_external_scripts && ! empty( $converted['external_scripts'] ) ) {
                $external_scripts = array();

                foreach ( array_slice( (array) $converted['external_scripts'], 0, 16 ) as $script ) {
                    if ( ! is_array( $script ) || empty( $script['url'] ) ) {
                        continue;
                    }

                    $url = esc_url_raw( (string) $script['url'], array( 'http', 'https' ) );

                    if ( '' === $url ) {
                        continue;
                    }

                    $external_scripts[] = array(
                        'url'      => $url,
                        'type'     => 'module' === ( $script['type'] ?? '' ) ? 'module' : 'classic',
                        'strategy' => in_array( ( $script['strategy'] ?? '' ), array( 'async', 'defer' ), true )
                            ? (string) $script['strategy']
                            : '',
                    );
                }

                $page_external_scripts = $external_scripts;
                update_post_meta( $post_id, '_pin_import_external_scripts', $page_external_scripts );
            } else {
                delete_post_meta( $post_id, '_pin_import_external_scripts' );
            }

            if ( $path === $preferred_template_source ) {
                $template_part_payload['header'] = $header_content;
                $template_part_payload['footer'] = $footer_content;
                $template_part_payload['css']              = $page_css;
                $template_part_payload['external_css']     = $external_css;
                $template_part_payload['scripts']          = $page_scripts;
                $template_part_payload['external_scripts'] = $page_external_scripts;
            }

            update_post_meta( $post_id, '_pin_import_source_repo', $repository['full_name'] );
            update_post_meta( $post_id, '_pin_import_source_branch', $repository['branch'] );
            update_post_meta( $post_id, '_pin_import_source_path', $path );
            update_post_meta( $post_id, '_pin_imported_at', gmdate( 'c' ) );

            if ( ! empty( $structured_page['seo'] ) && is_array( $structured_page['seo'] ) ) {
                if ( ! empty( $structured_page['seo']['title'] ) ) {
                    update_post_meta( $post_id, '_pin_import_seo_title', sanitize_text_field( (string) $structured_page['seo']['title'] ) );
                }
                if ( ! empty( $structured_page['seo']['description'] ) ) {
                    update_post_meta( $post_id, '_pin_import_seo_description', sanitize_textarea_field( (string) $structured_page['seo']['description'] ) );
                }
            }

            ++$result['pages_imported'];
            $result['page_ids'][] = $post_id;
        }

        if ( $apply_global && (int) $result['pages_failed'] > 0 ) {
            $apply_global = false;
            $result['global_applied'] = false;
            $result['warnings'][] = __(
                'Global application was cancelled because one or more pages failed to import. Review the warnings and re-import successfully before changing the live global layout.',
                'pin-site-importer'
            );
        }

        if ( $set_home ) {
            if ( ! $apply_global ) {
                $result['warnings'][] = __(
                    'The WordPress homepage was not changed because global application was not enabled for a fully successful published import.',
                    'pin-site-importer'
                );
            } else {
                $home_path = $this->find_home_path( $html_files, $source_dir );

                if ( $home_path && isset( $page_map[ $home_path ] ) ) {
                    $home_id      = (int) $page_map[ $home_path ];
                    $old_home_url = get_permalink( $home_id );

                    update_option( 'show_on_front', 'page' );
                    update_option( 'page_on_front', $home_id );

                    $new_home_url = get_permalink( $home_id );
                    $result['home_page_id'] = $home_id;

                    if ( $old_home_url && $new_home_url && $old_home_url !== $new_home_url ) {
                        foreach ( (array) $result['page_ids'] as $page_id ) {
                            $page_id = (int) $page_id;
                            $post    = get_post( $page_id );

                            if ( ! $post || false === strpos( (string) $post->post_content, $old_home_url ) ) {
                                continue;
                            }

                            wp_update_post(
                                array(
                                    'ID'           => $page_id,
                                    'post_content' => str_replace(
                                        $old_home_url,
                                        $new_home_url,
                                        (string) $post->post_content
                                    ),
                                )
                            );
                        }

                        foreach ( array( 'header', 'footer' ) as $area ) {
                            $template_part_payload[ $area ] = str_replace(
                                $old_home_url,
                                $new_home_url,
                                (string) $template_part_payload[ $area ]
                            );
                        }
                    }
                }
            }
        }

        if ( $import_template_parts ) {
            foreach ( array( 'header', 'footer' ) as $area ) {
                $part_content = trim( (string) ( $template_part_payload[ $area ] ?? '' ) );

                if ( '' === $part_content ) {
                    continue;
                }

                $part_id = $this->upsert_template_part(
                    $area,
                    $part_content,
                    $repository['full_name'],
                    $repository['branch'],
                    (string) $preferred_template_source,
                    $apply_global
                );

                if ( is_wp_error( $part_id ) ) {
                    $result['warnings'][] = $part_id->get_error_message();
                    continue;
                }

                if ( '' !== (string) $template_part_payload['css'] ) {
                    update_post_meta( $part_id, '_pin_import_css', (string) $template_part_payload['css'] );
                }

                if ( ! empty( $template_part_payload['external_css'] ) ) {
                    update_post_meta( $part_id, '_pin_import_external_css', (array) $template_part_payload['external_css'] );
                }

                if ( 'header' === $area ) {
                    if ( ! empty( $template_part_payload['scripts'] ) ) {
                        update_post_meta( $part_id, '_pin_import_scripts', (array) $template_part_payload['scripts'] );
                    } else {
                        delete_post_meta( $part_id, '_pin_import_scripts' );
                    }

                    if ( ! empty( $template_part_payload['external_scripts'] ) ) {
                        update_post_meta( $part_id, '_pin_import_external_scripts', (array) $template_part_payload['external_scripts'] );
                    } else {
                        delete_post_meta( $part_id, '_pin_import_external_scripts' );
                    }
                }

                $result['template_parts'][ $area ] = (int) $part_id;
            }
        }

        if ( $create_navigation ) {
            $navigation_id = $this->create_navigation(
                $page_map,
                $repository['full_name'],
                $apply_global
            );

            if ( is_wp_error( $navigation_id ) ) {
                $result['warnings'][] = $navigation_id->get_error_message();
            } else {
                $result['navigation_id'] = (int) $navigation_id;
            }
        }

        $this->store_history( $result );

        return $result;
    }

    /**
     * @param array<string,int>   $page_map
     * @param array<string,mixed> $pages
     */
    private function apply_structured_page_metadata( array $page_map, array $pages ): void {
        $slug_map = array();

        foreach ( $pages as $path => $page ) {
            if ( ! isset( $page_map[ $path ] ) || ! is_array( $page ) ) {
                continue;
            }

            $post_id = (int) $page_map[ $path ];
            $slug    = sanitize_title( (string) ( $page['slug'] ?? '' ) );

            wp_update_post(
                array(
                    'ID'         => $post_id,
                    'post_name'  => ( '' !== $slug && 'home' !== $slug ) ? $slug : get_post_field( 'post_name', $post_id ),
                    'menu_order' => (int) ( $page['order'] ?? 0 ),
                )
            );

            if ( '' !== $slug ) {
                $slug_map[ $slug ] = $post_id;
            }
        }

        foreach ( $pages as $path => $page ) {
            if ( ! isset( $page_map[ $path ] ) || ! is_array( $page ) ) {
                continue;
            }

            $parent = sanitize_title( (string) ( $page['parent'] ?? '' ) );

            if ( '' !== $parent && isset( $slug_map[ $parent ] ) ) {
                wp_update_post(
                    array(
                        'ID'          => (int) $page_map[ $path ],
                        'post_parent' => (int) $slug_map[ $parent ],
                    )
                );
            }
        }
    }

    /**
     * @param string[] $paths
     * @return array<string,int>|WP_Error
     */
    private function create_page_placeholders( string $repo, string $branch, array $paths, string $post_status, string $source_dir = '' ) {
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
                    'post_title'   => $this->fallback_title( $path, $source_dir ),
                    'post_name'    => $this->path_to_slug( $path, $source_dir ),
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

        foreach ( $paths as $path ) {
            $post_id = $map[ $path ] ?? 0;

            if ( ! $post_id ) {
                continue;
            }

            $parent_path = $this->find_parent_source_page( $path, $paths, $source_dir );
            $parent_id   = $parent_path && isset( $map[ $parent_path ] )
                ? (int) $map[ $parent_path ]
                : 0;

            $relative = $this->relative_source_path( $path, $source_dir );
            $basename = pathinfo( $relative, PATHINFO_FILENAME );
            $slug     = $this->path_to_slug( $path, $source_dir );

            if ( $parent_id && 'index' !== strtolower( $basename ) ) {
                $slug = sanitize_title( $basename );
            }

            wp_update_post(
                array(
                    'ID'          => $post_id,
                    'post_parent' => $parent_id,
                    'post_name'   => $slug,
                )
            );
        }

        return $map;
    }

    /**
     * Import repository images into the WordPress Media Library using the
     * authenticated GitHub client. This works for public and private repos.
     *
     * @param array<string,array{url:string,path:string}> $images
     * @param array<string,mixed>                          $repository
     * @return array{content:string,count:int,warnings:string[]}
     */
    private function sideload_images(
        int $post_id,
        string $content,
        array $images,
        PIN_Site_Importer_GitHub_Client $github,
        array $repository
    ): array {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $count    = 0;
        $warnings = array();

        foreach ( $images as $original => $descriptor ) {
            if ( ! is_array( $descriptor ) ) {
                continue;
            }

            $remote_url = esc_url_raw( (string) ( $descriptor['url'] ?? '' ) );
            $repo_path  = (string) ( $descriptor['path'] ?? '' );

            if ( '' === $remote_url ) {
                continue;
            }

            // External images stay remote. Repository-local images are imported
            // through the authenticated Contents API below.
            if ( '' === $repo_path ) {
                continue;
            }

            $asset_key = (string) $repository['full_name']
                . '@'
                . (string) $repository['branch']
                . ':'
                . $repo_path;

            $existing = get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_key'       => '_pin_import_repo_asset',
                    'meta_value'     => $asset_key,
                )
            );

            if ( $existing ) {
                $attachment_url = wp_get_attachment_url( (int) $existing[0] );
            } else {
                $binary = $github->get_file(
                    (string) $repository['owner'],
                    (string) $repository['repo'],
                    (string) $repository['branch'],
                    $repo_path,
                    10485760
                );

                if ( is_wp_error( $binary ) ) {
                    $warnings[] = sprintf(
                        /* translators: 1: repository path, 2: error message. */
                        __( 'Image import failed for %1$s: %2$s', 'pin-site-importer' ),
                        $repo_path,
                        $binary->get_error_message()
                    );
                    continue;
                }

                $filename = sanitize_file_name( basename( $repo_path ) );
                $tmp      = wp_tempnam( $filename );

                if ( ! $tmp ) {
                    $warnings[] = sprintf(
                        /* translators: %s: repository path. */
                        __( 'Could not create a temporary file for %s.', 'pin-site-importer' ),
                        $repo_path
                    );
                    continue;
                }

                $written = file_put_contents( $tmp, $binary, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

                if ( false === $written || $written !== strlen( $binary ) ) {
                    @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    $warnings[] = sprintf(
                        /* translators: %s: repository path. */
                        __( 'Could not write temporary image data for %s.', 'pin-site-importer' ),
                        $repo_path
                    );
                    continue;
                }

                $attachment_id = media_handle_sideload(
                    array(
                        'name'     => $filename,
                        'tmp_name' => $tmp,
                    ),
                    $post_id
                );

                if ( is_wp_error( $attachment_id ) ) {
                    @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    $warnings[] = sprintf(
                        /* translators: 1: repository path, 2: error message. */
                        __( 'Media Library import failed for %1$s: %2$s', 'pin-site-importer' ),
                        $repo_path,
                        $attachment_id->get_error_message()
                    );
                    continue;
                }

                update_post_meta( (int) $attachment_id, '_pin_import_repo_asset', $asset_key );
                update_post_meta( (int) $attachment_id, '_source_url', $remote_url );
                $attachment_url = wp_get_attachment_url( (int) $attachment_id );
                ++$count;
            }

            if ( $attachment_url ) {
                $content = str_replace(
                    array(
                        esc_url( $remote_url ),
                        $remote_url,
                        esc_attr( $original ),
                        $original,
                    ),
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
        array $stylesheets,
        PIN_Site_Importer_Asset_Manager $assets,
        string $source_root = ''
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
                $css_path,
                $assets,
                $source_root
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
        string $css_path,
        PIN_Site_Importer_Asset_Manager $assets,
        string $source_root = ''
    ): string {
        return (string) preg_replace_callback(
            '~url\(\s*([\'\"]?)(?!data:|https?:|//|#)([^)\'\"]+)\1\s*\)~i',
            static function ( array $matches ) use ( $github, $repository, $css_path, $assets, $source_root ): string {
                $reference = trim( $matches[2] );

                if ( str_starts_with( $reference, '/' ) && '' !== trim( $source_root, '/' ) ) {
                    $asset_path = trailingslashit( trim( $source_root, '/' ) ) . ltrim( $reference, '/' );
                } else {
                    $asset_path = $github->resolve_path( $css_path, $reference );
                }

                $mirrored = $assets->mirror( $asset_path, 4194304 );

                if ( ! is_wp_error( $mirrored ) ) {
                    return 'url("' . esc_url_raw( $mirrored ) . '")';
                }

                $raw_url = $github->raw_url(
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
     * Create a database-backed FSE template part for the active theme.
     * Existing user-created parts are never overwritten unless they were
     * previously created by this importer for the same repository.
     *
     * @return int|WP_Error
     */
    private function upsert_template_part(
        string $area,
        string $content,
        string $repository,
        string $branch,
        string $source_path,
        bool $activate_global = false
    ) {
        if ( ! post_type_exists( 'wp_template_part' ) ) {
            return new WP_Error(
                'pin_template_part_post_type_missing',
                __( 'WordPress template-part storage is unavailable.', 'pin-site-importer' )
            );
        }

        $theme = get_stylesheet();
        $part_slug = $activate_global
            ? $area
            : 'pin-imported-' . $area . '-' . substr( md5( $repository ), 0, 12 );

        $existing = get_posts(
            array(
                'post_type'      => 'wp_template_part',
                'post_status'    => array( 'publish', 'draft', 'auto-draft' ),
                'posts_per_page' => 1,
                'name'           => $part_slug,
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'wp_theme',
                        'field'    => 'name',
                        'terms'    => $theme,
                    ),
                ),
            )
        );

        $postarr = array(
            'post_type'    => 'wp_template_part',
            'post_status'  => 'publish',
            'post_name'    => $part_slug,
            'post_title'   => 'header' === $area
                ? ( $activate_global
                    ? __( 'Imported Site Header', 'pin-site-importer' )
                    : __( 'Imported Site Header (Preview)', 'pin-site-importer' ) )
                : ( $activate_global
                    ? __( 'Imported Site Footer', 'pin-site-importer' )
                    : __( 'Imported Site Footer (Preview)', 'pin-site-importer' ) ),
            'post_content' => $content,
        );

        if ( $existing ) {
            $existing_id = (int) $existing[0]->ID;
            $owner_repo  = (string) get_post_meta( $existing_id, '_pin_import_source_repo', true );

            if ( '' !== $owner_repo && $repository === $owner_repo ) {
                $postarr['ID'] = $existing_id;
            } else {
                return new WP_Error(
                    'pin_template_part_user_override',
                    sprintf(
                        /* translators: %s: template part area. */
                        __( 'The existing %s template part appears to be user-managed, so the importer left it unchanged.', 'pin-site-importer' ),
                        $area
                    )
                );
            }
        }

        $post_id = wp_insert_post( $postarr, true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $theme_term = wp_set_object_terms( $post_id, $theme, 'wp_theme', false );

        if ( is_wp_error( $theme_term ) ) {
            return $theme_term;
        }

        $area_term = wp_set_object_terms( $post_id, $area, 'wp_template_part_area', false );

        if ( is_wp_error( $area_term ) ) {
            return $area_term;
        }

        update_post_meta( $post_id, '_pin_import_source_repo', $repository );
        update_post_meta( $post_id, '_pin_import_source_branch', $branch );
        update_post_meta( $post_id, '_pin_import_source_path', $source_path );

        return (int) $post_id;
    }

    /**
     * Build or refresh a WordPress Navigation entity from imported pages.
     *
     * @param array<string,int> $page_map
     * @return int|WP_Error
     */
    private function create_navigation(
        array $page_map,
        string $repository,
        bool $activate_global = false
    ) {
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

        if ( $activate_global ) {
            update_option( 'pin_site_importer_navigation_id', (int) $navigation_id, false );
        }

        return (int) $navigation_id;
    }

    /**
     * Mirror local JavaScript files referenced by an imported page.
     *
     * @param array<int,array<string,string>> $scripts
     * @return array{items:array<int,array<string,string>>,warnings:string[]}
     */
    private function mirror_scripts(
        PIN_Site_Importer_Asset_Manager $assets,
        array $scripts
    ): array {
        $items    = array();
        $warnings = array();

        foreach ( array_slice( $scripts, 0, 24 ) as $script ) {
            $path = (string) ( $script['path'] ?? '' );

            if ( '' === $path ) {
                continue;
            }

            $url = $assets->mirror( $path, 4194304 );

            if ( is_wp_error( $url ) ) {
                $warnings[] = sprintf(
                    /* translators: 1: script path, 2: error message. */
                    __( 'Script mirror failed for %1$s: %2$s', 'pin-site-importer' ),
                    $path,
                    $url->get_error_message()
                );
                continue;
            }

            $items[] = array(
                'url'      => $url,
                'type'     => 'module' === ( $script['type'] ?? '' ) ? 'module' : 'classic',
                'strategy' => in_array( ( $script['strategy'] ?? '' ), array( 'async', 'defer' ), true )
                    ? (string) $script['strategy']
                    : '',
            );
        }

        return array(
            'items'    => $items,
            'warnings' => $warnings,
        );
    }

    /**
     * Find the closest imported folder index page to use as a WordPress parent.
     *
     * @param string[] $paths
     */
    private function find_parent_source_page(
        string $path,
        array $paths,
        string $source_dir = ''
    ): string {
        $relative = $this->relative_source_path( $path, $source_dir );
        $directory = dirname( $relative );
        $filename  = strtolower( pathinfo( $relative, PATHINFO_BASENAME ) );

        if ( in_array( $filename, array( 'index.html', 'index.htm' ), true ) ) {
            $directory = dirname( $directory );
        }

        while ( '.' !== $directory && '' !== $directory && '/' !== $directory ) {
            $prefix = '' !== $source_dir ? trailingslashit( $source_dir ) : '';

            foreach ( array( 'index.html', 'index.htm' ) as $index_file ) {
                $candidate = $prefix . trailingslashit( $directory ) . $index_file;

                if ( $candidate !== $path && in_array( $candidate, $paths, true ) ) {
                    return $candidate;
                }
            }

            $next = dirname( $directory );

            if ( $next === $directory ) {
                break;
            }

            $directory = $next;
        }

        return '';
    }

    private function relative_source_path( string $path, string $source_dir = '' ): string {
        if ( '' !== $source_dir ) {
            $prefix = trailingslashit( $source_dir );

            if ( str_starts_with( $path, $prefix ) ) {
                return substr( $path, strlen( $prefix ) );
            }
        }

        return ltrim( $path, '/' );
    }

    private function fallback_title( string $path, string $source_dir = '' ): string {
        $path = $this->relative_source_path( $path, $source_dir );
        $basename = pathinfo( $path, PATHINFO_FILENAME );

        if ( 'index' === strtolower( $basename ) ) {
            $directory = basename( dirname( $path ) );
            $basename  = '.' === $directory ? 'Home' : $directory;
        }

        return ucwords( str_replace( array( '-', '_' ), ' ', $basename ) );
    }

    private function path_to_slug( string $path, string $source_dir = '' ): string {
        $path = $this->relative_source_path( $path, $source_dir );
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
    private function find_home_path( array $paths, string $source_dir = '' ): string {
        $prefix = '' !== $source_dir ? trailingslashit( $source_dir ) : '';

        foreach ( array( $prefix . 'index.html', $prefix . 'index.htm' ) as $candidate ) {
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
                'scripts'     => $result['scripts_mirrored'],
                'assets'      => $result['assets_mirrored'],
            )
        );

        update_option( 'pin_site_importer_history', array_slice( $history, 0, 10 ), false );
    }
}
