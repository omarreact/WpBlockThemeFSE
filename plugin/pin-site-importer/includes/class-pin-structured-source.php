<?php
/**
 * Structured JSON / WordPress WXR source support.
 */

defined( 'ABSPATH' ) || exit;

final class PIN_Site_Importer_Structured_Source {
    private const MAX_JSON_BYTES = 4194304;
    private const MAX_XML_BYTES  = 8388608;
    private const MAX_ITEMS      = 250;

    private PIN_Site_Importer_GitHub_Client $github;
    /** @var array<string,mixed> */
    private array $repository;

    /**
     * @param array<string,mixed> $repository
     */
    public function __construct( PIN_Site_Importer_GitHub_Client $github, array $repository ) {
        $this->github     = $github;
        $this->repository = $repository;
    }

    /**
     * @return array{manifest:string,content:string,tokens:string,wxr:string}
     */
    public function detect( string $source_dir = '' ): array {
        $prefix = '' !== trim( $source_dir, '/' ) ? trailingslashit( trim( $source_dir, '/' ) ) : '';
        $files  = array(
            'manifest' => '',
            'content'  => '',
            'tokens'   => '',
            'wxr'      => '',
        );

        foreach ( (array) ( $this->repository['tree'] ?? array() ) as $item ) {
            if ( 'blob' !== ( $item['type'] ?? '' ) || empty( $item['path'] ) ) {
                continue;
            }

            $path = (string) $item['path'];

            if ( '' !== $prefix && ! str_starts_with( $path, $prefix ) ) {
                continue;
            }

            $base = strtolower( basename( $path ) );

            if ( '' === $files['manifest'] && in_array( $base, array( 'site-manifest.json', 'manifest.site.json' ), true ) ) {
                $files['manifest'] = $path;
            }

            if ( '' === $files['content'] && in_array( $base, array( 'content.json', 'site-content.json' ), true ) ) {
                $files['content'] = $path;
            }

            if ( '' === $files['tokens'] && in_array( $base, array( 'design-tokens.json', 'tokens.json', 'theme-tokens.json' ), true ) ) {
                $files['tokens'] = $path;
            }

            if (
                '' === $files['wxr']
                && 'xml' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) )
                && preg_match( '/(?:wordpress|wxr|export)/i', $base )
            ) {
                $files['wxr'] = $path;
            }
        }

        return $files;
    }

    /**
     * @return array<string,mixed>|WP_Error|null
     */
    public function load_json( string $source_dir = '' ) {
        $files = $this->detect( $source_dir );

        if ( '' === $files['manifest'] && '' === $files['content'] ) {
            return null;
        }

        $manifest = array();
        $content  = array();
        $tokens   = array();

        if ( '' !== $files['manifest'] ) {
            $manifest = $this->read_json( $files['manifest'] );
            if ( is_wp_error( $manifest ) ) {
                return $manifest;
            }
        }

        if ( '' !== $files['content'] ) {
            $content = $this->read_json( $files['content'] );
            if ( is_wp_error( $content ) ) {
                return $content;
            }
        }

        if ( '' !== $files['tokens'] ) {
            $tokens = $this->read_json( $files['tokens'] );
            if ( is_wp_error( $tokens ) ) {
                return $tokens;
            }
        }

        $pages        = array();
        $source_pages = array();

        if ( is_array( $content['pages'] ?? null ) ) {
            $source_pages = $content['pages'];
        } elseif ( is_array( $manifest['pages'] ?? null ) ) {
            $source_pages = $manifest['pages'];
        }

        foreach ( array_slice( $source_pages, 0, self::MAX_ITEMS ) as $index => $page ) {
            if ( ! is_array( $page ) ) {
                continue;
            }

            $requested_path = (string) ( $page['path'] ?? $page['slug'] ?? '' );
            $slug           = sanitize_title( trim( $requested_path, '/' ) );

            if ( '' === $slug || '/' === $requested_path ) {
                $slug = 'home';
            }

            $path = (string) ( $page['file'] ?? '' );

            if ( '' === $path ) {
                $path = '__json__/' . ( 'home' === $slug ? 'index' : $slug ) . '.html';
            }

            $title = sanitize_text_field(
                (string) ( $page['title'] ?? $page['name'] ?? ucwords( str_replace( '-', ' ', $slug ) ) )
            );
            $html = '';

            if ( isset( $page['html'] ) && is_string( $page['html'] ) ) {
                $html = wp_kses_post( $page['html'] );
            } elseif ( isset( $page['content'] ) && is_string( $page['content'] ) ) {
                $html = wp_kses_post( wpautop( $page['content'] ) );
            } elseif ( is_array( $page['sections'] ?? null ) ) {
                $html = $this->sections_to_html( $page['sections'] );
            } elseif ( '' !== (string) ( $page['file'] ?? '' ) ) {
                $raw = $this->github->get_file(
                    (string) $this->repository['owner'],
                    (string) $this->repository['repo'],
                    (string) $this->repository['branch'],
                    (string) $page['file'],
                    2097152
                );

                if ( is_wp_error( $raw ) ) {
                    return $raw;
                }

                $html = $raw;
            }

            $pages[ $path ] = array(
                'title'       => $title,
                'slug'        => $slug,
                'parent'      => sanitize_title( (string) ( $page['parent'] ?? '' ) ),
                'status'      => 'publish' === ( $page['status'] ?? '' ) ? 'publish' : 'draft',
                'html'        => $this->wrap_document( $title, $html ),
                'seo'         => is_array( $page['seo'] ?? null ) ? $page['seo'] : array(),
                'source_type' => 'json',
                'order'       => (int) ( $page['order'] ?? $index ),
            );
        }

        if ( empty( $pages ) ) {
            return new WP_Error(
                'pin_structured_json_no_pages',
                __( 'Structured JSON was detected but it did not contain any importable pages.', 'pin-site-importer' )
            );
        }

        return array(
            'mode'       => 'json',
            'files'      => $files,
            'pages'      => $pages,
            'global_css' => $this->tokens_to_css( $tokens ),
            'site'       => is_array( $manifest['site'] ?? null ) ? $manifest['site'] : array(),
            'navigation'  => is_array( $manifest['navigation'] ?? null ) ? $manifest['navigation'] : array(),
            'header_html' => $this->resolve_fragment( $manifest['header'] ?? null ),
            'footer_html' => $this->resolve_fragment( $manifest['footer'] ?? null ),
        );
    }

    /**
     * @return array<string,mixed>|WP_Error|null
     */
    public function import_wxr( string $source_dir = '', string $post_status = 'draft', bool $import_media = false ) {
        $files = $this->detect( $source_dir );

        if ( '' === $files['wxr'] ) {
            return null;
        }

        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error(
                'pin_wxr_dom_missing',
                __( 'The PHP DOM extension is required for WXR import.', 'pin-site-importer' )
            );
        }

        $xml = $this->github->get_file(
            (string) $this->repository['owner'],
            (string) $this->repository['repo'],
            (string) $this->repository['branch'],
            $files['wxr'],
            self::MAX_XML_BYTES
        );

        if ( is_wp_error( $xml ) ) {
            return $xml;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded   = $document->loadXML( $xml, LIBXML_NONET | LIBXML_NOBLANKS );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            return new WP_Error(
                'pin_wxr_invalid_xml',
                __( 'The detected WXR/XML file could not be parsed safely.', 'pin-site-importer' )
            );
        }

        $xpath = new DOMXPath( $document );
        $xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );

        $id_map     = array();
        $parent_map = array();
        $imported   = 0;
        $skipped    = 0;
        $warnings   = array();
        $new_ids        = array();
        $media_imported  = 0;
        $comments_imported = 0;

        $items = $xpath->query( '//channel/item' );

        if ( ! $items ) {
            return new WP_Error(
                'pin_wxr_no_items',
                __( 'The WXR file does not contain importable items.', 'pin-site-importer' )
            );
        }

        $count = 0;

        foreach ( $items as $item ) {
            if ( ++$count > self::MAX_ITEMS ) {
                $warnings[] = sprintf(
                    __( 'WXR import stopped at the safety limit of %d items.', 'pin-site-importer' ),
                    self::MAX_ITEMS
                );
                break;
            }

            $type = $this->xpath_text( $xpath, 'wp:post_type', $item );
            $old_id = absint( $this->xpath_text( $xpath, 'wp:post_id', $item ) );

            if ( 'attachment' === $type ) {
                if ( ! $import_media ) {
                    ++$skipped;
                    continue;
                }

                $attachment_url = esc_url_raw(
                    $this->xpath_text( $xpath, 'wp:attachment_url', $item, false ),
                    array( 'http', 'https' )
                );

                if ( '' === $attachment_url || ! wp_http_validate_url( $attachment_url ) ) {
                    $warnings[] = sprintf(
                        /* translators: %d: original attachment ID. */
                        __( 'Skipped WXR attachment %d because its source URL is not safe or valid.', 'pin-site-importer' ),
                        $old_id
                    );
                    ++$skipped;
                    continue;
                }

                $attachment_id = $this->sideload_wxr_attachment(
                    $attachment_url,
                    sanitize_text_field( $this->xpath_text( $xpath, 'title', $item ) )
                );

                if ( is_wp_error( $attachment_id ) ) {
                    $warnings[] = $attachment_id->get_error_message();
                    ++$skipped;
                    continue;
                }

                $attachment_id = (int) $attachment_id;
                $id_map[ $old_id ]             = $attachment_id;
                $parent_map[ $attachment_id ]   = absint( $this->xpath_text( $xpath, 'wp:post_parent', $item ) );
                $new_ids[]                      = $attachment_id;
                ++$media_imported;
                ++$imported;
                continue;
            }

            if ( ! in_array( $type, array( 'post', 'page' ), true ) ) {
                if ( post_type_exists( $type ) && 'attachment' !== $type ) {
                    // Registered custom post types can be preserved safely.
                } else {
                    ++$skipped;
                    continue;
                }
            }
            $slug          = sanitize_title( $this->xpath_text( $xpath, 'wp:post_name', $item ) );
            $title         = sanitize_text_field( $this->xpath_text( $xpath, 'title', $item ) );
            $body          = $this->xpath_text( $xpath, 'content:encoded', $item, false );
            $excerpt       = $this->xpath_text( $xpath, 'excerpt:encoded', $item, false );
            $source_status = sanitize_key( $this->xpath_text( $xpath, 'wp:status', $item ) );
            $status        = 'publish' === $post_status && 'publish' === $source_status ? 'publish' : 'draft';

            $existing = get_posts(
                array(
                    'post_type'      => $type,
                    'post_status'    => 'any',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        'relation' => 'AND',
                        array( 'key' => '_pin_import_source_repo', 'value' => (string) $this->repository['full_name'] ),
                        array( 'key' => '_pin_import_source_branch', 'value' => (string) $this->repository['branch'] ),
                        array( 'key' => '_pin_import_wxr_id', 'value' => (string) $old_id ),
                    ),
                )
            );

            $postarr = array(
                'post_type'      => $type,
                'post_status'    => $status,
                'post_title'     => $title,
                'post_name'      => $slug,
                'post_excerpt'   => wp_kses_post( $excerpt ),
                'post_content'   => $this->normalize_wxr_content( $body ),
                'menu_order'     => (int) $this->xpath_text( $xpath, 'wp:menu_order', $item ),
                'comment_status' => in_array( $this->xpath_text( $xpath, 'wp:comment_status', $item ), array( 'open', 'closed' ), true )
                    ? $this->xpath_text( $xpath, 'wp:comment_status', $item )
                    : 'closed',
                'ping_status'    => in_array( $this->xpath_text( $xpath, 'wp:ping_status', $item ), array( 'open', 'closed' ), true )
                    ? $this->xpath_text( $xpath, 'wp:ping_status', $item )
                    : 'closed',
            );

            $post_date = sanitize_text_field( $this->xpath_text( $xpath, 'wp:post_date', $item ) );
            if ( '' !== $post_date && false !== strtotime( $post_date ) ) {
                $postarr['post_date'] = $post_date;
            }

            if ( $existing ) {
                $postarr['ID'] = (int) $existing[0];
                $new_id = wp_update_post( $postarr, true );
            } else {
                $new_id = wp_insert_post( $postarr, true );
            }

            if ( is_wp_error( $new_id ) ) {
                $warnings[] = $new_id->get_error_message();
                continue;
            }

            $new_id = (int) $new_id;
            $id_map[ $old_id ]     = $new_id;
            $parent_map[ $new_id ] = absint( $this->xpath_text( $xpath, 'wp:post_parent', $item ) );
            $new_ids[]             = $new_id;

            update_post_meta( $new_id, '_pin_import_source_repo', (string) $this->repository['full_name'] );
            update_post_meta( $new_id, '_pin_import_source_branch', (string) $this->repository['branch'] );
            update_post_meta( $new_id, '_pin_import_wxr_id', $old_id );
            update_post_meta( $new_id, '_pin_import_source_path', $files['wxr'] . '#post-' . $old_id );

            foreach ( $xpath->query( './*[local-name()="postmeta"]', $item ) ?: array() as $meta_node ) {
                $key   = $this->xpath_text( $xpath, 'wp:meta_key', $meta_node );
                $value = $this->xpath_text( $xpath, 'wp:meta_value', $meta_node, false );

                if ( '' === $key || str_starts_with( $key, '_' ) ) {
                    continue;
                }

                update_post_meta( $new_id, sanitize_key( $key ), $value );
            }

            foreach ( $xpath->query( 'category', $item ) ?: array() as $term_node ) {
                if ( ! $term_node instanceof DOMElement ) {
                    continue;
                }

                $domain = sanitize_key( $term_node->getAttribute( 'domain' ) );
                $name   = sanitize_text_field( (string) $term_node->textContent );

                if ( '' === $name ) {
                    continue;
                }

                if ( 'category' === $domain ) {
                    $term = term_exists( $name, 'category' );

                    if ( ! $term ) {
                        $term = wp_insert_term( $name, 'category' );
                    }

                    if ( ! is_wp_error( $term ) ) {
                        $term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
                        wp_set_post_categories( $new_id, array( $term_id ), true );
                    }
                } elseif ( 'post_tag' === $domain ) {
                    wp_set_post_tags( $new_id, array( $name ), true );
                }
            }

            $comments_imported += $this->import_wxr_comments( $xpath, $item, $new_id, $warnings );

            ++$imported;
        }

        foreach ( $parent_map as $new_id => $old_parent ) {
            if ( $old_parent && isset( $id_map[ $old_parent ] ) ) {
                wp_update_post(
                    array(
                        'ID'          => $new_id,
                        'post_parent' => (int) $id_map[ $old_parent ],
                    )
                );
            }
        }

        return array(
            'source_mode'      => 'wxr',
            'repository'       => (string) $this->repository['full_name'],
            'branch'           => (string) $this->repository['branch'],
            'source_file'      => $files['wxr'],
            'pages_discovered' => $imported + $skipped,
            'pages_imported'   => $imported,
            'pages_failed'     => 0,
            'media_imported'   => $media_imported,
            'comments_imported'=> $comments_imported,
            'assets_mirrored'  => 0,
            'scripts_mirrored' => 0,
            'warnings'         => $warnings,
            'page_ids'         => $new_ids,
            'items_skipped'    => $skipped,
            'technology'       => 'WordPress WXR',
            'global_applied'   => false,
        );
    }

    /**
     * @param string[] $warnings
     */
    private function import_wxr_comments(
        DOMXPath $xpath,
        DOMNode $item,
        int $post_id,
        array &$warnings
    ): int {
        $comment_nodes = $xpath->query( './*[local-name()="comment"]', $item );

        if ( ! $comment_nodes ) {
            return 0;
        }

        $id_map     = array();
        $parent_map = array();
        $count      = 0;

        foreach ( $comment_nodes as $comment_node ) {
            $old_id = absint( $this->xpath_text( $xpath, 'wp:comment_id', $comment_node ) );
            $approved_raw = $this->xpath_text( $xpath, 'wp:comment_approved', $comment_node );
            $approved = in_array( $approved_raw, array( '0', '1', 'spam', 'trash' ), true )
                ? $approved_raw
                : '0';

            $commentdata = array(
                'comment_post_ID'      => $post_id,
                'comment_author'       => sanitize_text_field( $this->xpath_text( $xpath, 'wp:comment_author', $comment_node ) ),
                'comment_author_email' => sanitize_email( $this->xpath_text( $xpath, 'wp:comment_author_email', $comment_node ) ),
                'comment_author_url'   => esc_url_raw( $this->xpath_text( $xpath, 'wp:comment_author_url', $comment_node, false ) ),
                'comment_author_IP'    => sanitize_text_field( $this->xpath_text( $xpath, 'wp:comment_author_IP', $comment_node ) ),
                'comment_content'      => wp_kses_post( $this->xpath_text( $xpath, 'wp:comment_content', $comment_node, false ) ),
                'comment_approved'     => $approved,
                'comment_type'         => sanitize_key( $this->xpath_text( $xpath, 'wp:comment_type', $comment_node ) ),
                'comment_parent'       => 0,
                'user_id'              => 0,
            );

            $comment_date = sanitize_text_field( $this->xpath_text( $xpath, 'wp:comment_date', $comment_node ) );
            if ( '' !== $comment_date && false !== strtotime( $comment_date ) ) {
                $commentdata['comment_date'] = $comment_date;
            }

            $existing_comments = get_comments(
                array(
                    'post_id'    => $post_id,
                    'number'     => 1,
                    'status'     => 'all',
                    'meta_key'   => '_pin_import_wxr_comment_id',
                    'meta_value' => (string) $old_id,
                )
            );

            if ( $existing_comments ) {
                $new_id = (int) $existing_comments[0]->comment_ID;
                $commentdata['comment_ID'] = $new_id;
                wp_update_comment( wp_filter_comment( $commentdata ) );
            } else {
                $new_id = wp_insert_comment( wp_filter_comment( $commentdata ) );
            }

            if ( ! $new_id ) {
                $warnings[] = __( 'A WXR comment could not be imported.', 'pin-site-importer' );
                continue;
            }

            $new_id = (int) $new_id;
            update_comment_meta( $new_id, '_pin_import_wxr_comment_id', $old_id );
            $id_map[ $old_id ]     = $new_id;
            $parent_map[ $new_id ] = absint( $this->xpath_text( $xpath, 'wp:comment_parent', $comment_node ) );

            foreach ( $xpath->query( './*[local-name()="commentmeta"]', $comment_node ) ?: array() as $meta_node ) {
                $key   = sanitize_key( $this->xpath_text( $xpath, 'wp:meta_key', $meta_node ) );
                $value = $this->xpath_text( $xpath, 'wp:meta_value', $meta_node, false );

                if ( '' !== $key && ! str_starts_with( $key, '_' ) ) {
                    update_comment_meta( $new_id, $key, $value );
                }
            }

            ++$count;
        }

        foreach ( $parent_map as $new_id => $old_parent ) {
            if ( $old_parent && isset( $id_map[ $old_parent ] ) ) {
                wp_update_comment(
                    array(
                        'comment_ID'     => $new_id,
                        'comment_parent' => (int) $id_map[ $old_parent ],
                    )
                );
            }
        }

        return $count;
    }

    /**
     * @return int|WP_Error
     */
    private function sideload_wxr_attachment( string $url, string $title ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $existing = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_source_url',
                'meta_value'     => $url,
            )
        );

        if ( $existing ) {
            return (int) $existing[0];
        }

        $tmp = download_url( $url, 20 );

        if ( is_wp_error( $tmp ) ) {
            return new WP_Error(
                'pin_wxr_attachment_download_failed',
                sprintf(
                    /* translators: 1: attachment URL, 2: error message. */
                    __( 'Could not download WXR attachment %1$s: %2$s', 'pin-site-importer' ),
                    $url,
                    $tmp->get_error_message()
                )
            );
        }

        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $name = sanitize_file_name( basename( $path ) );

        if ( '' === $name ) {
            $name = 'imported-media';
        }

        $file = array(
            'name'     => $name,
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload( $file, 0, $title );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return $attachment_id;
        }

        update_post_meta( (int) $attachment_id, '_source_url', esc_url_raw( $url ) );

        return (int) $attachment_id;
    }

    /**
     * @param mixed $fragment
     */
    private function resolve_fragment( $fragment ): string {
        if ( is_string( $fragment ) ) {
            $value = trim( $fragment );

            if ( '' === $value ) {
                return '';
            }

            if ( str_starts_with( $value, '<' ) ) {
                return wp_kses_post( $value );
            }

            $raw = $this->github->get_file(
                (string) $this->repository['owner'],
                (string) $this->repository['repo'],
                (string) $this->repository['branch'],
                ltrim( $value, '/' ),
                1048576
            );

            return is_wp_error( $raw ) ? '' : $raw;
        }

        if ( is_array( $fragment ) ) {
            if ( isset( $fragment['html'] ) && is_string( $fragment['html'] ) ) {
                return wp_kses_post( $fragment['html'] );
            }

            if ( isset( $fragment['file'] ) && is_string( $fragment['file'] ) ) {
                return $this->resolve_fragment( $fragment['file'] );
            }
        }

        return '';
    }

    /** @return array<string,mixed>|WP_Error */
    private function read_json( string $path ) {
        $raw = $this->github->get_file(
            (string) $this->repository['owner'],
            (string) $this->repository['repo'],
            (string) $this->repository['branch'],
            $path,
            self::MAX_JSON_BYTES
        );

        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        $decoded = json_decode( $raw, true );

        if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error(
                'pin_structured_json_invalid',
                sprintf( __( 'Could not parse structured JSON file %s.', 'pin-site-importer' ), $path )
            );
        }

        return $decoded;
    }

    /** @param array<int,mixed> $sections */
    private function sections_to_html( array $sections ): string {
        $html = '';

        foreach ( array_slice( $sections, 0, 200 ) as $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }

            $type  = sanitize_key( (string) ( $section['type'] ?? 'section' ) );
            $class = 'pin-import-' . sanitize_html_class( $type );
            $body  = '';

            if ( ! empty( $section['heading'] ) ) {
                $body .= '<h2>' . esc_html( (string) $section['heading'] ) . '</h2>';
            }

            if ( ! empty( $section['title'] ) && empty( $section['heading'] ) ) {
                $body .= '<h2>' . esc_html( (string) $section['title'] ) . '</h2>';
            }

            if ( ! empty( $section['text'] ) ) {
                $body .= wpautop( wp_kses_post( (string) $section['text'] ) );
            }

            if ( ! empty( $section['image'] ) ) {
                $body .= '<figure><img src="' . esc_url( (string) $section['image'] ) . '" alt="'
                    . esc_attr( (string) ( $section['alt'] ?? '' ) ) . '"></figure>';
            }

            if ( is_array( $section['buttons'] ?? null ) ) {
                foreach ( $section['buttons'] as $button ) {
                    if ( ! is_array( $button ) ) {
                        continue;
                    }

                    $body .= '<a class="button" href="' . esc_url( (string) ( $button['url'] ?? '#' ) ) . '">'
                        . esc_html( (string) ( $button['label'] ?? 'Learn more' ) ) . '</a>';
                }
            }

            if ( is_array( $section['items'] ?? null ) ) {
                $body .= '<div class="pin-items">';

                foreach ( array_slice( $section['items'], 0, 50 ) as $item ) {
                    if ( is_string( $item ) ) {
                        $body .= '<div><p>' . esc_html( $item ) . '</p></div>';
                    } elseif ( is_array( $item ) ) {
                        $body .= '<div>';

                        if ( ! empty( $item['title'] ) ) {
                            $body .= '<h3>' . esc_html( (string) $item['title'] ) . '</h3>';
                        }

                        if ( ! empty( $item['text'] ) ) {
                            $body .= wpautop( wp_kses_post( (string) $item['text'] ) );
                        }

                        if ( ! empty( $item['image'] ) ) {
                            $body .= '<img src="' . esc_url( (string) $item['image'] ) . '" alt="'
                                . esc_attr( (string) ( $item['alt'] ?? '' ) ) . '">';
                        }

                        $body .= '</div>';
                    }
                }

                $body .= '</div>';
            }

            if ( ! empty( $section['html'] ) ) {
                $body .= wp_kses_post( (string) $section['html'] );
            }

            $html .= '<section class="' . esc_attr( $class ) . '">' . $body . '</section>';
        }

        return $html;
    }

    /** @param array<string,mixed> $tokens */
    private function tokens_to_css( array $tokens ): string {
        if ( empty( $tokens ) ) {
            return '';
        }

        $vars = array();

        $walk = static function ( $value, string $prefix = '' ) use ( &$walk, &$vars ): void {
            if ( ! is_array( $value ) ) {
                if ( is_scalar( $value ) && '' !== $prefix ) {
                    $name = '--pin-' . sanitize_key( str_replace( '_', '-', $prefix ) );
                    $vars[ $name ] = sanitize_text_field( (string) $value );
                }
                return;
            }

            foreach ( $value as $key => $child ) {
                $walk( $child, '' === $prefix ? (string) $key : $prefix . '-' . $key );
            }
        };

        $walk( $tokens );

        if ( empty( $vars ) ) {
            return '';
        }

        $css = ":root{\n";

        foreach ( array_slice( $vars, 0, 200, true ) as $name => $value ) {
            if ( preg_match( '/[{};]/', $value ) ) {
                continue;
            }

            $css .= '  ' . $name . ':' . $value . ";\n";
        }

        return $css . '}';
    }

    private function wrap_document( string $title, string $html ): string {
        return '<!doctype html><html><head><meta charset="utf-8"><title>'
            . esc_html( $title ) . '</title></head><body>' . $html . '</body></html>';
    }

    private function normalize_wxr_content( string $content ): string {
        $content = trim( $content );

        if ( '' === $content ) {
            return '';
        }

        if ( str_contains( $content, '<!-- wp:' ) ) {
            return $content;
        }

        return '<!-- wp:html -->' . wp_kses_post( $content ) . '<!-- /wp:html -->';
    }

    private function xpath_text(
        DOMXPath $xpath,
        string $query,
        DOMNode $context,
        bool $sanitize = true
    ): string {
        if ( str_starts_with( $query, 'wp:' ) ) {
            $local = substr( $query, 3 );
            $query = './*[local-name()="' . preg_replace( '/[^A-Za-z0-9_-]/', '', $local ) . '"]';
        } elseif ( 'excerpt:encoded' === $query ) {
            $query = './*[local-name()="encoded" and namespace-uri()!="http://purl.org/rss/1.0/modules/content/"]';
        }

        $node = $xpath->query( $query, $context )->item( 0 );

        if ( ! $node ) {
            return '';
        }

        $value = (string) $node->textContent;

        return $sanitize ? sanitize_text_field( $value ) : $value;
    }
}
