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

    /**
     * @param array<string,int> $page_map
     */
    public function __construct(
        PIN_Site_Importer_GitHub_Client $github,
        array $page_map,
        string $owner,
        string $repo,
        string $branch,
        string $source_path
    ) {
        $this->github      = $github;
        $this->page_map    = $page_map;
        $this->owner       = $owner;
        $this->repo        = $repo;
        $this->branch      = $branch;
        $this->source_path = $source_path;
    }

    /**
     * @return array{title:string,content:string,images:array<string,string>,stylesheets:string[]}|WP_Error
     */
    public function convert( string $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error(
                'pin_dom_extension_missing',
                __( 'The PHP DOM extension is required for HTML conversion.', 'pin-site-importer' )
            );
        }

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

        $content = '';
        $images  = array();

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
                $images[ $source ] = $resolved;
            }
        }

        if ( $body ) {
            foreach ( $body->childNodes as $child ) {
                $content .= $this->node_to_block( $child, $images );
            }
        }

        return array(
            'title'       => $title,
            'content'     => trim( $content ),
            'images'      => $images,
            'stylesheets' => $this->extract_stylesheets( $xpath ),
        );
    }

    /**
     * @param array<string,string> $images
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

        if ( in_array( $tag, array( 'section', 'div', 'main', 'article', 'header', 'footer', 'nav', 'aside' ), true ) ) {
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

        $images[ $src ] = $resolved;

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

        $path = $this->github->resolve_path( $this->source_path, $href );

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

            $path = $this->github->resolve_path( $this->source_path, $href );

            if ( 'css' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
                $stylesheets[] = $path;
            }
        }

        return array_values( array_unique( $stylesheets ) );
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

        $path = $this->github->resolve_path( $this->source_path, $src );

        return $this->github->raw_url(
            $this->owner,
            $this->repo,
            $this->branch,
            $path
        );
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
