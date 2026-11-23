<?php
/**
 * HTML to Gutenberg core-block converter.
 */

defined( 'ABSPATH' ) || exit;

final class PIN_Site_Importer_HTML_To_Blocks {
    private PIN_Site_Importer_GitHub_Client $github;
    /** @var array<string,int> */
    private array $page_map;
    private string $owner;
    private string $repo;
    private string $branch;
    private string $source_path;
    private string $source_root;
    /** @var array<string,string> */
    private array $inline_css_rules = array();

    /**
     * @param array<string,int> $page_map
     */
    public function __construct(
        PIN_Site_Importer_GitHub_Client $github,
        array $page_map,
        string $owner,
        string $repo,
        string $branch,
        string $source_path,
        string $source_root = ''
    ) {
        $this->github      = $github;
        $this->page_map    = $page_map;
        $this->owner       = $owner;
        $this->repo        = $repo;
        $this->branch      = $branch;
        $this->source_path = $source_path;
        $this->source_root = trim( str_replace( '\\', '/', $source_root ), '/' );
    }

    /**
     * @return array{title:string,content:string,header_content:string,footer_content:string,images:array<string,array{url:string,path:string}>,stylesheets:string[],scripts:array<int,array<string,string>>,inline_css:string}|WP_Error
     */
    public function convert( string $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error(
                'pin_dom_extension_missing',
                __( 'The PHP DOM extension is required for HTML conversion.', 'pin-site-importer' )
            );
        }

        $this->inline_css_rules = array();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors( true );

        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            return new WP_Error(
                'pin_html_parse_failed',
                sprintf(
                    /* translators: %s: source path. */
                    __( 'Could not parse HTML from %s.', 'pin-site-importer' ),
                    $this->source_path
                )
            );
        }

        $xpath = new DOMXPath( $document );
        $title = $this->extract_title( $xpath );
        $body  = $xpath->query( '//body' )->item( 0 );

        if ( ! $body ) {
            $body = $document->documentElement;
        }

        $content        = '';
        $header_content = '';
        $footer_content = '';
        $images         = array();

        foreach ( $xpath->query( '//img[@src]' ) as $image_node ) {
            if ( ! $image_node instanceof DOMElement ) {
                continue;
            }

            $source = trim( $image_node->getAttribute( 'src' ) );

            if ( '' === $source ) {
                continue;
            }

            $resolved = $this->resolve_asset_url( $source );

            if ( '' !== $resolved ) {
                $images[ $source ] = array(
                    'url'  => $resolved,
                    'path' => preg_match( '~^https?://~i', $source ) ? '' : $this->resolve_site_path( $source ),
                );
            }
        }

        $header_node = $this->find_template_part_node( $xpath, 'header' );
        $footer_node = $this->find_template_part_node( $xpath, 'footer' );

        if ( $header_node instanceof DOMElement ) {
            $header_content = $this->node_to_block( $header_node, $images );

            if ( $header_node->parentNode ) {
                $header_node->parentNode->removeChild( $header_node );
            }
        }

        if ( $footer_node instanceof DOMElement ) {
            $footer_content = $this->node_to_block( $footer_node, $images );

            if ( $footer_node->parentNode ) {
                $footer_node->parentNode->removeChild( $footer_node );
            }
        }

        if ( $body ) {
            foreach ( $body->childNodes as $child ) {
                $content .= $this->node_to_block( $child, $images );
            }
        }

        return array(
            'title'          => $title,
            'content'        => trim( $content ),
            'header_content' => trim( $header_content ),
            'footer_content' => trim( $footer_content ),
            'images'      => $images,
            'stylesheets' => $this->extract_stylesheets( $xpath ),
            'scripts'     => $this->extract_scripts( $xpath ),
            'inline_css'  => implode( "\n", array_values( $this->inline_css_rules ) ),
        );
    }

    /**
     * @param array<string,array{url:string,path:string}> $images
     */
    private function node_to_block( DOMNode $node, array &$images ): string {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            $text = trim( preg_replace( '/\s+/', ' ', (string) $node->nodeValue ) );

            if ( '' === $text ) {
                return '';
            }

            return $this->paragraph_block( esc_html( $text ) );
        }

        if ( XML_ELEMENT_NODE !== $node->nodeType || ! $node instanceof DOMElement ) {
            return '';
        }

        $tag = strtolower( $node->tagName );

        if ( in_array( $tag, array( 'script', 'style', 'noscript', 'meta', 'link', 'head' ), true ) ) {
            return '';
        }

        if ( preg_match( '/^h([1-6])$/', $tag, $matches ) ) {
            $level = (int) $matches[1];
            $html  = $this->safe_inner_html( $node );
            $class = $this->class_attribute( $node );
            $attrs = array( 'level' => $level );

            if ( '' !== $class ) {
                $attrs['className'] = $class;
            }

            return '<!-- wp:heading ' . wp_json_encode( $attrs ) . ' -->'
                . '<h' . $level . $this->html_class_attribute( $class ) . '>' . $html . '</h' . $level . '>'
                . '<!-- /wp:heading -->';
        }

        if ( 'p' === $tag ) {
            return $this->paragraph_block( $this->safe_inner_html( $node ), $this->class_attribute( $node ) );
        }

        if ( 'img' === $tag ) {
            return $this->image_block( $node, $images );
        }

        if ( 'figure' === $tag ) {
            $image = $node->getElementsByTagName( 'img' )->item( 0 );

            if ( $image instanceof DOMElement ) {
                return $this->image_block( $image, $images );
            }
        }

        if ( 'ul' === $tag || 'ol' === $tag ) {
            $ordered = 'ol' === $tag;
            $class   = $this->class_attribute( $node );
            $attrs   = $ordered ? array( 'ordered' => true ) : array();

            if ( '' !== $class ) {
                $attrs['className'] = $class;
            }

            $inner = '';

            foreach ( $node->childNodes as $child ) {
                $inner .= $node->ownerDocument->saveHTML( $child );
            }

            $list_class = 'wp-block-list' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' );

            return '<!-- wp:list' . ( $attrs ? ' ' . wp_json_encode( $attrs ) : '' ) . ' -->'
                . '<' . $tag . ' class="' . $list_class . '">' . wp_kses_post( $inner ) . '</' . $tag . '>'
                . '<!-- /wp:list -->';
        }

        if ( 'blockquote' === $tag ) {
            return '<!-- wp:quote -->'
                . '<blockquote class="wp-block-quote">' . $this->safe_inner_html( $node ) . '</blockquote>'
                . '<!-- /wp:quote -->';
        }

        if ( 'hr' === $tag ) {
            return '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->';
        }

        if ( 'pre' === $tag || 'code' === $tag ) {
            $code = esc_html( (string) $node->textContent );

            return '<!-- wp:code --><pre class="wp-block-code"><code>' . $code . '</code></pre><!-- /wp:code -->';
        }

        if ( 'table' === $tag ) {
            $table = wp_kses_post( $node->ownerDocument->saveHTML( $node ) );

            return '<!-- wp:table --><figure class="wp-block-table">' . $table . '</figure><!-- /wp:table -->';
        }

        if ( 'a' === $tag && $this->is_standalone_button( $node ) ) {
            $href  = $this->rewrite_href( $node->getAttribute( 'href' ) );
            $label = trim( wp_strip_all_tags( (string) $node->textContent ) );

            if ( '' === $label ) {
                return '';
            }

            return '<!-- wp:buttons --><div class="wp-block-buttons">'
                . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="'
                . esc_url( $href ) . '">' . esc_html( $label ) . '</a></div><!-- /wp:button -->'
                . '</div><!-- /wp:buttons -->';
        }

        if ( 'nav' === $tag ) {
            $links = '';
            $seen  = array();

            foreach ( $node->getElementsByTagName( 'a' ) as $anchor ) {
                if ( ! $anchor instanceof DOMElement ) {
                    continue;
                }

                $label = trim( preg_replace( '/\s+/', ' ', (string) $anchor->textContent ) );
                $href  = trim( $anchor->getAttribute( 'href' ) );

                if ( '' === $label || '' === $href ) {
                    continue;
                }

                $href = $this->rewrite_href( $href );
                $key  = $label . '|' . $href;

                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }

                $seen[ $key ] = true;
                $attrs = array(
                    'label'          => sanitize_text_field( $label ),
                    'url'            => $href,
                    'kind'           => 'custom',
                    'isTopLevelLink' => true,
                );

                if ( '_blank' === strtolower( $anchor->getAttribute( 'target' ) ) ) {
                    $attrs['opensInNewTab'] = true;
                }

                $links .= '<!-- wp:navigation-link ' . wp_json_encode( $attrs ) . ' /-->';
            }

            if ( '' === $links ) {
                return '';
            }

            $attrs = array(
                'overlayMenu' => 'mobile',
            );
            $class = $this->class_attribute( $node );

            if ( '' !== $class ) {
                $attrs['className'] = $class;
            }

            return '<!-- wp:navigation ' . wp_json_encode( $attrs ) . ' -->'
                . $links
                . '<!-- /wp:navigation -->';
        }

        if ( 'a' === $tag ) {
            $href = $this->rewrite_href( $node->getAttribute( 'href' ) );
            $inner = $this->safe_inner_html( $node );

            if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
                return '';
            }

            $target = '_blank' === strtolower( $node->getAttribute( 'target' ) )
                ? ' target="_blank" rel="noopener"'
                : '';

            return $this->paragraph_block(
                '<a href="' . esc_url( $href ) . '"' . $target . '>' . $inner . '</a>',
                $this->class_attribute( $node )
            );
        }

        if ( in_array( $tag, array( 'section', 'div', 'main', 'article', 'header', 'footer', 'aside' ), true ) ) {
            $inner = '';

            foreach ( $node->childNodes as $child ) {
                $inner .= $this->node_to_block( $child, $images );
            }

            if ( '' === trim( $inner ) ) {
                return '';
            }

            $class = $this->class_attribute( $node );
            $attrs = array(
                'layout' => array( 'type' => 'default' ),
            );

            if ( '' !== $class ) {
                $attrs['className'] = $class;
            }

            return '<!-- wp:group ' . wp_json_encode( $attrs ) . ' -->'
                . '<div class="wp-block-group' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '">'
                . $inner
                . '</div><!-- /wp:group -->';
        }

        if ( in_array( $tag, array( 'form', 'input', 'select', 'textarea', 'button' ), true ) ) {
            $raw = wp_kses(
                $node->ownerDocument->saveHTML( $node ),
                array(
                    'form'     => array( 'action' => true, 'method' => true, 'class' => true ),
                    'label'    => array( 'for' => true, 'class' => true ),
                    'input'    => array( 'type' => true, 'name' => true, 'value' => true, 'placeholder' => true, 'required' => true, 'class' => true ),
                    'select'   => array( 'name' => true, 'required' => true, 'class' => true ),
                    'option'   => array( 'value' => true, 'selected' => true ),
                    'textarea' => array( 'name' => true, 'placeholder' => true, 'required' => true, 'class' => true ),
                    'button'   => array( 'type' => true, 'class' => true ),
                )
            );

            return '<!-- wp:html -->' . $raw . '<!-- /wp:html -->';
        }

        $inner = '';

        foreach ( $node->childNodes as $child ) {
            $inner .= $this->node_to_block( $child, $images );
        }

        if ( '' !== trim( $inner ) ) {
            return $inner;
        }

        $text = trim( (string) $node->textContent );

        return '' !== $text ? $this->paragraph_block( esc_html( $text ) ) : '';
    }

    /**
     * @param array<string,string> $images
     */
    private function image_block( DOMElement $node, array &$images ): string {
        $src = trim( $node->getAttribute( 'src' ) );

        if ( '' === $src ) {
            return '';
        }

        $resolved = $this->resolve_asset_url( $src );
        $alt      = sanitize_text_field( $node->getAttribute( 'alt' ) );
        $class    = $this->class_attribute( $node );

        $images[ $src ] = array(
            'url'  => $resolved,
            'path' => preg_match( '~^https?://~i', $src ) ? '' : $this->resolve_site_path( $src ),
        );

        $attrs = array(
            'sizeSlug'        => 'full',
            'linkDestination' => 'none',
        );

        if ( '' !== $class ) {
            $attrs['className'] = $class;
        }

        return '<!-- wp:image ' . wp_json_encode( $attrs ) . ' -->'
            . '<figure class="wp-block-image size-full' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '">'
            . '<img src="' . esc_url( $resolved ) . '" alt="' . esc_attr( $alt ) . '"/>'
            . '</figure><!-- /wp:image -->';
    }

    private function paragraph_block( string $inner_html, string $class = '' ): string {
        if ( '' === trim( wp_strip_all_tags( $inner_html ) ) && false === stripos( $inner_html, '<img' ) ) {
            return '';
        }

        $inner_html = $this->rewrite_links_in_html( $inner_html );
        $attrs      = array();

        if ( '' !== $class ) {
            $attrs['className'] = $class;
        }

        return '<!-- wp:paragraph' . ( $attrs ? ' ' . wp_json_encode( $attrs ) : '' ) . ' -->'
            . '<p' . $this->html_class_attribute( $class ) . '>' . $inner_html . '</p>'
            . '<!-- /wp:paragraph -->';
    }

    private function safe_inner_html( DOMElement $node ): string {
        $html = '';

        foreach ( $node->childNodes as $child ) {
            $html .= $node->ownerDocument->saveHTML( $child );
        }

        return $this->rewrite_links_in_html( wp_kses_post( $html ) );
    }

    private function rewrite_links_in_html( string $html ): string {
        if ( false === stripos( $html, '<a' ) ) {
            return $html;
        }

        $fragment = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $fragment->loadHTML(
            '<?xml encoding="utf-8" ?><div id="pin-fragment">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        $xpath = new DOMXPath( $fragment );
        $root  = $xpath->query( '//*[@id="pin-fragment"]' )->item( 0 );

        if ( ! $root instanceof DOMElement ) {
            return $html;
        }

        foreach ( $root->getElementsByTagName( 'a' ) as $anchor ) {
            $anchor->setAttribute(
                'href',
                $this->rewrite_href( $anchor->getAttribute( 'href' ) )
            );
        }

        $output = '';

        foreach ( $root->childNodes as $child ) {
            $output .= $fragment->saveHTML( $child );
        }

        return wp_kses_post( $output );
    }

    private function rewrite_href( string $href ): string {
        $href = trim( $href );

        if (
            '' === $href
            || str_starts_with( $href, '#' )
            || preg_match( '~^(?:mailto:|tel:|sms:|javascript:)~i', $href )
        ) {
            return $href;
        }

        if ( preg_match( '~^https?://~i', $href ) ) {
            return esc_url_raw( $href );
        }

        $anchor = '';
        $query  = '';

        if ( false !== strpos( $href, '#' ) ) {
            list( $href, $anchor_part ) = explode( '#', $href, 2 );
            $anchor = '#' . sanitize_title( $anchor_part );
        }

        if ( false !== strpos( $href, '?' ) ) {
            list( $href, $query_part ) = explode( '?', $href, 2 );
            $query = '?' . sanitize_text_field( $query_part );
        }

        $path = $this->resolve_site_path( $href );

        if ( isset( $this->page_map[ $path ] ) ) {
            return get_permalink( $this->page_map[ $path ] ) . $query . $anchor;
        }

        if ( '' === pathinfo( $path, PATHINFO_EXTENSION ) ) {
            foreach ( array( trailingslashit( $path ) . 'index.html', $path . '.html' ) as $candidate ) {
                if ( isset( $this->page_map[ $candidate ] ) ) {
                    return get_permalink( $this->page_map[ $candidate ] ) . $query . $anchor;
                }
            }
        }

        return $href . $query . $anchor;
    }

    /**
     * @return string[]
     */
    private function extract_stylesheets( DOMXPath $xpath ): array {
        $stylesheets = array();

        foreach ( $xpath->query( '//link[@rel="stylesheet"][@href]' ) as $link ) {
            if ( ! $link instanceof DOMElement ) {
                continue;
            }

            $href = trim( $link->getAttribute( 'href' ) );

            if ( '' === $href || preg_match( '~^https?://~i', $href ) ) {
                continue;
            }

            $path = $this->resolve_site_path( $href );

            if ( 'css' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
                $stylesheets[] = $path;
            }
        }

        return array_values( array_unique( $stylesheets ) );
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function extract_scripts( DOMXPath $xpath ): array {
        $scripts = array();

        foreach ( $xpath->query( '//script[@src]' ) as $script ) {
            if ( ! $script instanceof DOMElement ) {
                continue;
            }

            $src = trim( $script->getAttribute( 'src' ) );

            if ( '' === $src || preg_match( '~^https?://~i', $src ) || str_starts_with( $src, '//' ) ) {
                continue;
            }

            $path = $this->resolve_site_path( $src );
            $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

            if ( ! in_array( $ext, array( 'js', 'mjs' ), true ) ) {
                continue;
            }

            $type = strtolower( trim( $script->getAttribute( 'type' ) ) );
            $strategy = '';

            if ( $script->hasAttribute( 'async' ) ) {
                $strategy = 'async';
            } elseif ( $script->hasAttribute( 'defer' ) ) {
                $strategy = 'defer';
            }

            $scripts[] = array(
                'path'     => $path,
                'type'     => 'module' === $type ? 'module' : 'classic',
                'strategy' => $strategy,
            );
        }

        $unique = array();

        foreach ( $scripts as $script ) {
            $key = $script['path'] . '|' . $script['type'] . '|' . $script['strategy'];
            $unique[ $key ] = $script;
        }

        return array_values( $unique );
    }

    private function find_template_part_node( DOMXPath $xpath, string $tag ): ?DOMElement {
        if ( ! in_array( $tag, array( 'header', 'footer' ), true ) ) {
            return null;
        }

        $query = sprintf(
            '//body//%1$s[not(ancestor::main) and not(ancestor::article)]',
            $tag
        );

        $node = $xpath->query( $query )->item( 0 );

        return $node instanceof DOMElement ? $node : null;
    }

    private function extract_title( DOMXPath $xpath ): string {
        $title_node = $xpath->query( '//title' )->item( 0 );

        if ( $title_node ) {
            $title = sanitize_text_field( trim( (string) $title_node->textContent ) );

            if ( '' !== $title ) {
                return $title;
            }
        }

        $heading = $xpath->query( '//h1' )->item( 0 );

        if ( $heading ) {
            $title = sanitize_text_field( trim( (string) $heading->textContent ) );

            if ( '' !== $title ) {
                return $title;
            }
        }

        return '';
    }

    private function resolve_asset_url( string $src ): string {
        if ( preg_match( '~^https?://~i', $src ) ) {
            return esc_url_raw( $src );
        }

        if ( str_starts_with( $src, 'data:' ) ) {
            return '';
        }

        $path = $this->resolve_site_path( $src );

        return $this->github->raw_url(
            $this->owner,
            $this->repo,
            $this->branch,
            $path
        );
    }

    private function resolve_site_path( string $reference ): string {
        $clean = preg_replace( '/[?#].*$/', '', trim( $reference ) );

        if ( str_starts_with( $clean, '/' ) ) {
            $relative = ltrim( $clean, '/' );

            if ( '' !== $this->source_root ) {
                return trailingslashit( $this->source_root ) . $relative;
            }

            return $relative;
        }

        return $this->github->resolve_path( $this->source_path, $clean );
    }

    private function class_attribute( DOMElement $node ): string {
        $raw = preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) );
        $out = array();

        foreach ( $raw ?: array() as $class ) {
            $class = sanitize_html_class( $class );

            if ( '' !== $class && ! str_starts_with( $class, 'wp-' ) ) {
                $out[] = $class;
            }
        }

        $style = trim( $node->getAttribute( 'style' ) );

        if ( '' !== $style ) {
            $safe_style = safecss_filter_attr( $style );

            if ( '' !== $safe_style ) {
                $generated = 'pin-inline-' . substr( md5( $safe_style ), 0, 10 );
                $out[] = $generated;
                $this->inline_css_rules[ $generated ] = '.' . $generated . '{' . $safe_style . '}';
            }
        }

        return implode( ' ', array_values( array_unique( $out ) ) );
    }

    private function html_class_attribute( string $class ): string {
        return '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '';
    }

    private function is_standalone_button( DOMElement $node ): bool {
        $class = strtolower( $node->getAttribute( 'class' ) );
        $role  = strtolower( $node->getAttribute( 'role' ) );

        return 'button' === $role
            || false !== strpos( $class, 'btn' )
            || false !== strpos( $class, 'button' )
            || false !== strpos( $class, 'cta' );
    }
}
