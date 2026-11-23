<?php
/**
 * GitHub repository client.
 */

defined( 'ABSPATH' ) || exit;

final class PIN_Site_Importer_GitHub_Client {
    private string $token;

    public function __construct( string $token = '' ) {
        $this->token = trim( $token );
    }

    /**
     * Parse a public GitHub repository URL or owner/repository string.
     *
     * @return array{owner:string,repo:string}|WP_Error
     */
    public function parse_repository( string $input ) {
        $input = trim( $input );

        if ( preg_match( '~^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?$~', $input, $matches ) ) {
            return array(
                'owner' => $matches[1],
                'repo'  => $matches[2],
            );
        }

        $parts = wp_parse_url( $input );

        if (
            ! is_array( $parts )
            || empty( $parts['host'] )
            || 'github.com' !== strtolower( $parts['host'] )
            || empty( $parts['path'] )
        ) {
            return new WP_Error(
                'pin_invalid_github_url',
                __( 'Enter a valid GitHub repository URL such as https://github.com/owner/repository.', 'pin-site-importer' )
            );
        }

        $path_segments = array_values(
            array_filter(
                explode( '/', trim( $parts['path'], '/' ) ),
                static fn( $segment ) => '' !== $segment
            )
        );

        if ( count( $path_segments ) < 2 ) {
            return new WP_Error(
                'pin_invalid_github_path',
                __( 'The GitHub URL must contain an owner and repository name.', 'pin-site-importer' )
            );
        }

        $owner = sanitize_key( $path_segments[0] );
        $repo  = preg_replace( '/\.git$/i', '', sanitize_text_field( $path_segments[1] ) );

        if ( '' === $owner || '' === $repo || ! preg_match( '/^[A-Za-z0-9_.-]+$/', $repo ) ) {
            return new WP_Error(
                'pin_invalid_github_repository',
                __( 'The GitHub owner or repository name is invalid.', 'pin-site-importer' )
            );
        }

        return array(
            'owner' => $owner,
            'repo'  => $repo,
        );
    }

    /**
     * Read repository metadata and the selected/default branch.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function inspect_repository( string $input, string $requested_branch = '' ) {
        $repository = $this->parse_repository( $input );

        if ( is_wp_error( $repository ) ) {
            return $repository;
        }

        $cache_key = '';

        if ( ! $this->is_authenticated() ) {
            $cache_key = 'pin_site_import_repo_' . md5(
                strtolower( $repository['owner'] . '/' . $repository['repo'] )
                . '|'
                . trim( $requested_branch )
            );
            $cached = get_transient( $cache_key );

            if ( is_array( $cached ) && ! empty( $cached['tree'] ) ) {
                return $cached;
            }
        }

        $metadata = $this->api_get(
            sprintf(
                'https://api.github.com/repos/%s/%s',
                rawurlencode( $repository['owner'] ),
                rawurlencode( $repository['repo'] )
            )
        );

        if ( is_wp_error( $metadata ) ) {
            return $metadata;
        }

        if ( ! empty( $metadata['private'] ) && '' === $this->token ) {
            return new WP_Error(
                'pin_private_repo_token_required',
                __( 'This repository is private. Supply a GitHub token that can read it.', 'pin-site-importer' )
            );
        }

        $branch = '' !== trim( $requested_branch )
            ? sanitize_text_field( trim( $requested_branch ) )
            : (string) ( $metadata['default_branch'] ?? 'main' );

        $tree = $this->read_tree(
            $repository['owner'],
            $repository['repo'],
            $branch
        );

        if ( is_wp_error( $tree ) ) {
            return $tree;
        }

        $technology   = $this->detect_technology( $tree );
        $html_files   = $this->filter_paths( $tree, array( 'html', 'htm' ) );
        $static_roots = $this->detect_static_roots( $html_files );

        $result = array(
            'owner'                  => $repository['owner'],
            'repo'                   => $repository['repo'],
            'full_name'              => $repository['owner'] . '/' . $repository['repo'],
            'branch'                 => $branch,
            'html_files'             => $html_files,
            'css_files'              => $this->filter_paths( $tree, array( 'css' ) ),
            'technology'             => $technology,
            'static_roots'           => $static_roots,
            'recommended_source_dir' => $this->recommend_static_root( $html_files, $static_roots ),
            'tree'                   => $tree,
            'description'            => sanitize_text_field( (string) ( $metadata['description'] ?? '' ) ),
        );

        if ( '' !== $cache_key ) {
            set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        }

        return $result;
    }

    /**
     * Read a repository file through GitHub's Contents API raw media type.
     * This works for public repositories and token-authorized private repos.
     *
     * @return string|WP_Error
     */
    public function get_file( string $owner, string $repo, string $branch, string $path, int $max_bytes = 2097152 ) {
        // Public file bodies do not need GitHub's rate-limited Contents API.
        // Fetch them from raw.githubusercontent.com so a large site import does
        // not consume one core API request per HTML/CSS/JS/image/font file.
        if ( ! $this->is_authenticated() ) {
            $raw = $this->get_public_raw_file(
                $owner,
                $repo,
                $branch,
                $path,
                $max_bytes
            );

            if ( ! is_wp_error( $raw ) ) {
                return $raw;
            }

            // A missing raw path may benefit from GitHub's API error detail,
            // but rate-limit / transport failures should not be converted into
            // more unauthenticated API requests.
            if ( 'pin_github_raw_not_found' !== $raw->get_error_code() ) {
                return $raw;
            }
        }

        $segments = array_map( 'rawurlencode', explode( '/', ltrim( $path, '/' ) ) );
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
            rawurlencode( $owner ),
            rawurlencode( $repo ),
            implode( '/', $segments ),
            rawurlencode( $branch )
        );

        $headers = $this->headers();
        $headers['Accept'] = 'application/vnd.github.raw+json';

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 20,
                'redirection'         => 3,
                'limit_response_size' => $max_bytes + 1,
                'headers'             => $headers,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( 200 !== $status ) {
            $json = json_decode( $body, true );
            $message = is_array( $json ) && ! empty( $json['message'] )
                ? sanitize_text_field( (string) $json['message'] )
                : __( 'GitHub file request failed.', 'pin-site-importer' );

            return new WP_Error(
                'pin_github_file_http_error',
                sprintf(
                    /* translators: 1: file path, 2: HTTP status, 3: message. */
                    __( 'GitHub returned HTTP %2$d while reading %1$s: %3$s', 'pin-site-importer' ),
                    $path,
                    $status,
                    $message
                )
            );
        }

        if ( strlen( $body ) > $max_bytes ) {
            return new WP_Error(
                'pin_github_file_too_large',
                sprintf(
                    /* translators: %s: file path. */
                    __( 'The file %s is larger than the importer safety limit.', 'pin-site-importer' ),
                    $path
                )
            );
        }

        return $body;
    }

    /**
     * Read a public repository file without consuming GitHub core API quota.
     *
     * @return string|WP_Error
     */
    public function get_public_raw_file(
        string $owner,
        string $repo,
        string $branch,
        string $path,
        int $max_bytes
    ) {
        $url = $this->raw_url( $owner, $repo, $branch, $path );

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 20,
                'redirection'         => 3,
                'limit_response_size' => $max_bytes + 1,
                'headers'             => array(
                    'User-Agent' => 'PIN-Site-Importer/' . PIN_SITE_IMPORTER_VERSION,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'pin_github_raw_transport_error',
                $response->get_error_message()
            );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( 404 === $status ) {
            return new WP_Error(
                'pin_github_raw_not_found',
                sprintf(
                    /* translators: %s: repository file path. */
                    __( 'Repository file not found: %s', 'pin-site-importer' ),
                    $path
                )
            );
        }

        if ( 200 !== $status ) {
            return new WP_Error(
                'pin_github_raw_http_error',
                sprintf(
                    /* translators: 1: file path, 2: HTTP status. */
                    __( 'GitHub raw file request returned HTTP %2$d while reading %1$s.', 'pin-site-importer' ),
                    $path,
                    $status
                )
            );
        }

        if ( strlen( $body ) > $max_bytes ) {
            return new WP_Error(
                'pin_github_file_too_large',
                sprintf(
                    /* translators: %s: file path. */
                    __( 'The file %s is larger than the importer safety limit.', 'pin-site-importer' ),
                    $path
                )
            );
        }

        return $body;
    }

    public function is_authenticated(): bool {
        return '' !== $this->token;
    }

    /**
     * Build a raw GitHub URL for a repository file.
     */
    public function raw_url( string $owner, string $repo, string $branch, string $path ): string {
        $segments = array_map( 'rawurlencode', explode( '/', ltrim( $path, '/' ) ) );

        return sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/%s',
            rawurlencode( $owner ),
            rawurlencode( $repo ),
            rawurlencode( $branch ),
            implode( '/', $segments )
        );
    }

    /**
     * Normalize a repository-relative path.
     */
    public function resolve_path( string $base_file, string $relative ): string {
        $relative = preg_replace( '/[?#].*$/', '', trim( $relative ) );

        if ( '' === $relative ) {
            return $base_file;
        }

        if ( str_starts_with( $relative, '/' ) ) {
            $candidate = ltrim( $relative, '/' );
        } else {
            $base_dir  = dirname( $base_file );
            $candidate = ( '.' === $base_dir ? '' : trailingslashit( $base_dir ) ) . $relative;
        }

        $parts = array();

        foreach ( explode( '/', str_replace( '\\', '/', $candidate ) ) as $part ) {
            if ( '' === $part || '.' === $part ) {
                continue;
            }

            if ( '..' === $part ) {
                array_pop( $parts );
                continue;
            }

            $parts[] = $part;
        }

        return implode( '/', $parts );
    }

    /**
     * @return array<int,array<string,mixed>>|WP_Error
     */
    private function read_tree( string $owner, string $repo, string $branch ) {
        $branch_data = $this->api_get(
            sprintf(
                'https://api.github.com/repos/%s/%s/branches/%s',
                rawurlencode( $owner ),
                rawurlencode( $repo ),
                rawurlencode( $branch )
            )
        );

        if ( is_wp_error( $branch_data ) ) {
            return $branch_data;
        }

        $tree_sha = (string) ( $branch_data['commit']['commit']['tree']['sha'] ?? '' );

        if ( '' === $tree_sha ) {
            return new WP_Error(
                'pin_github_tree_missing',
                __( 'GitHub did not return a repository tree for the selected branch.', 'pin-site-importer' )
            );
        }

        $tree_data = $this->api_get(
            sprintf(
                'https://api.github.com/repos/%s/%s/git/trees/%s?recursive=1',
                rawurlencode( $owner ),
                rawurlencode( $repo ),
                rawurlencode( $tree_sha )
            )
        );

        if ( is_wp_error( $tree_data ) ) {
            return $tree_data;
        }

        if ( ! empty( $tree_data['truncated'] ) ) {
            return new WP_Error(
                'pin_github_tree_truncated',
                __( 'The GitHub repository tree is too large for the safe recursive API response. Import a smaller/exported site repository.', 'pin-site-importer' )
            );
        }

        return is_array( $tree_data['tree'] ?? null ) ? $tree_data['tree'] : array();
    }

    /**
     * @param array<int,array<string,mixed>> $tree
     * @param string[]                       $extensions
     * @return string[]
     */
    private function filter_paths( array $tree, array $extensions ): array {
        $paths = array();

        foreach ( $tree as $item ) {
            if ( 'blob' !== ( $item['type'] ?? '' ) || empty( $item['path'] ) ) {
                continue;
            }

            $extension = strtolower( pathinfo( (string) $item['path'], PATHINFO_EXTENSION ) );

            if ( in_array( $extension, $extensions, true ) ) {
                $paths[] = (string) $item['path'];
            }
        }

        sort( $paths );

        return $paths;
    }

    /**
     * Detect common static-export/build roots that actually contain HTML.
     *
     * @param string[] $html_files
     * @return string[]
     */
    public function detect_static_roots( array $html_files ): array {
        $candidates = array( 'out', 'dist', 'build', 'public', 'site', 'docs' );
        $roots      = array();

        foreach ( $candidates as $candidate ) {
            $prefix = trailingslashit( $candidate );

            foreach ( $html_files as $path ) {
                if ( str_starts_with( (string) $path, $prefix ) ) {
                    $roots[] = $candidate;
                    break;
                }
            }
        }

        return array_values( array_unique( $roots ) );
    }

    /**
     * Recommend a build root only when no root-level HTML exists and one common
     * static-output directory is clearly dominant. This avoids importing docs
     * or examples accidentally when the repository already contains a site at
     * its root.
     *
     * @param string[] $html_files
     * @param string[] $static_roots
     */
    public function recommend_static_root( array $html_files, array $static_roots ): string {
        foreach ( $html_files as $path ) {
            if ( false === strpos( (string) $path, '/' ) ) {
                return '';
            }
        }

        if ( 1 === count( $static_roots ) ) {
            return (string) $static_roots[0];
        }

        $priority = array( 'out', 'dist', 'build' );

        foreach ( $priority as $candidate ) {
            if ( ! in_array( $candidate, $static_roots, true ) ) {
                continue;
            }

            $count = 0;

            foreach ( $html_files as $path ) {
                if ( str_starts_with( (string) $path, trailingslashit( $candidate ) ) ) {
                    ++$count;
                }
            }

            if ( $count >= max( 1, (int) ceil( count( $html_files ) * 0.75 ) ) ) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $tree
     */
    private function detect_technology( array $tree ): string {
        $paths = array_map(
            static fn( $item ) => strtolower( (string) ( $item['path'] ?? '' ) ),
            $tree
        );

        if ( in_array( 'next.config.js', $paths, true ) || in_array( 'next.config.mjs', $paths, true ) || in_array( 'next.config.ts', $paths, true ) ) {
            return 'Next.js';
        }

        if ( in_array( 'angular.json', $paths, true ) ) {
            return 'Angular';
        }

        if (
            in_array( 'vite.config.js', $paths, true )
            || in_array( 'vite.config.ts', $paths, true )
            || in_array( 'vite.config.mjs', $paths, true )
        ) {
            return 'Vite/React or Vite';
        }

        if ( in_array( 'package.json', $paths, true ) ) {
            return 'JavaScript application';
        }

        return 'Static HTML';
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function api_get( string $url ) {
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 20,
                'redirection'         => 3,
                'limit_response_size' => 4194304,
                'headers'             => $this->headers(),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );
        $json   = json_decode( $body, true );

        if ( $status < 200 || $status >= 300 || ! is_array( $json ) ) {
            $message = is_array( $json ) && ! empty( $json['message'] )
                ? sanitize_text_field( (string) $json['message'] )
                : __( 'GitHub API request failed.', 'pin-site-importer' );

            $remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
            $reset     = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );

            if (
                in_array( $status, array( 403, 429 ), true )
                && ( '0' === (string) $remaining || false !== stripos( $message, 'rate limit' ) )
            ) {
                $reset_text = '';

                if ( is_numeric( $reset ) && (int) $reset > time() ) {
                    $reset_text = sprintf(
                        /* translators: %s: UTC reset time. */
                        __( ' GitHub reports the limit resets around %s UTC.', 'pin-site-importer' ),
                        gmdate( 'H:i:s', (int) $reset )
                    );
                }

                return new WP_Error(
                    'pin_github_rate_limited',
                    __(
                        'GitHub API metadata access is rate-limited for this server IP. Public file downloads no longer use the API, but repository metadata/tree discovery still needs a few API requests. Retry with a GitHub token for an immediate higher quota.',
                        'pin-site-importer'
                    ) . $reset_text
                );
            }

            return new WP_Error(
                'pin_github_api_error',
                sprintf(
                    /* translators: 1: error message, 2: HTTP status code. */
                    __( '%1$s (HTTP %2$d)', 'pin-site-importer' ),
                    $message,
                    $status
                )
            );
        }

        return $json;
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array {
        $headers = array(
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'PIN-Site-Importer/' . PIN_SITE_IMPORTER_VERSION,
        );

        if ( '' !== $this->token ) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        return $headers;
    }
}
