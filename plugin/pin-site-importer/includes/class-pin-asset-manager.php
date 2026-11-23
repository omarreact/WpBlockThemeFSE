<?php
/**
 * Mirrors safe static repository assets into WordPress uploads.
 */

defined( 'ABSPATH' ) || exit;

final class PIN_Site_Importer_Asset_Manager {
    private const MAX_TOTAL_BYTES = 26214400; // 25 MiB per import run.

    private PIN_Site_Importer_GitHub_Client $github;
    /** @var array<string,mixed> */
    private array $repository;
    private string $base_dir;
    private string $base_url;
    private int $bytes_written = 0;

    /** @var string[] */
    private array $allowed_extensions = array(
        'css',
        'js',
        'mjs',
        'json',
        'map',
        'svg',
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'avif',
        'ico',
        'woff',
        'woff2',
        'ttf',
        'otf',
        'eot',
        'mp4',
        'webm',
        'mp3',
        'wav',
    );

    /**
     * @param array<string,mixed> $repository
     */
    public function __construct(
        PIN_Site_Importer_GitHub_Client $github,
        array $repository
    ) {
        $this->github     = $github;
        $this->repository = $repository;

        $uploads = wp_upload_dir();
        $key     = sanitize_key(
            substr(
                md5(
                    (string) $repository['full_name']
                    . '@'
                    . (string) $repository['branch']
                ),
                0,
                16
            )
        );

        $this->base_dir = trailingslashit( $uploads['basedir'] ) . 'pin-site-importer/' . $key;
        $this->base_url = trailingslashit( $uploads['baseurl'] ) . 'pin-site-importer/' . $key;
    }

    /**
     * Mirror a repository file and return its local public URL.
     *
     * @return string|WP_Error
     */
    public function mirror( string $path, int $max_bytes = 2097152 ) {
        $path = $this->normalize_path( $path );

        if ( is_wp_error( $path ) ) {
            return $path;
        }

        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

        if ( ! in_array( $extension, $this->allowed_extensions, true ) ) {
            return new WP_Error(
                'pin_asset_extension_blocked',
                sprintf(
                    /* translators: %s: file extension. */
                    __( 'Asset type .%s is not allowed for mirroring.', 'pin-site-importer' ),
                    $extension
                )
            );
        }

        $target = trailingslashit( $this->base_dir ) . $path;
        $url    = trailingslashit( $this->base_url ) . $this->url_path( $path );

        if ( file_exists( $target ) && is_file( $target ) ) {
            return esc_url_raw( $url );
        }

        $content = $this->github->get_file(
            (string) $this->repository['owner'],
            (string) $this->repository['repo'],
            (string) $this->repository['branch'],
            $path,
            $max_bytes
        );

        if ( is_wp_error( $content ) ) {
            return $content;
        }

        $size = strlen( $content );

        if ( $this->bytes_written + $size > self::MAX_TOTAL_BYTES ) {
            return new WP_Error(
                'pin_asset_total_limit',
                __( 'The static asset safety limit for this import run has been reached.', 'pin-site-importer' )
            );
        }

        $directory = dirname( $target );

        if ( ! file_exists( $directory ) && ! wp_mkdir_p( $directory ) ) {
            return new WP_Error(
                'pin_asset_directory_failed',
                sprintf(
                    /* translators: %s: directory path. */
                    __( 'Could not create asset directory: %s', 'pin-site-importer' ),
                    $directory
                )
            );
        }

        $written = file_put_contents( $target, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        if ( false === $written || $written !== $size ) {
            return new WP_Error(
                'pin_asset_write_failed',
                sprintf(
                    /* translators: %s: repository path. */
                    __( 'Could not mirror repository asset: %s', 'pin-site-importer' ),
                    $path
                )
            );
        }

        $this->bytes_written += $size;

        return esc_url_raw( $url );
    }

    /**
     * @return string|WP_Error
     */
    private function normalize_path( string $path ) {
        $path = preg_replace( '/[?#].*$/', '', trim( str_replace( '\\', '/', $path ) ) );
        $path = ltrim( (string) $path, '/' );

        if ( '' === $path || str_contains( $path, '../' ) || str_starts_with( $path, '..' ) ) {
            return new WP_Error(
                'pin_asset_path_invalid',
                __( 'The repository asset path is invalid.', 'pin-site-importer' )
            );
        }

        $parts = array();

        foreach ( explode( '/', $path ) as $part ) {
            if ( '' === $part || '.' === $part ) {
                continue;
            }

            if ( '..' === $part ) {
                return new WP_Error(
                    'pin_asset_path_traversal',
                    __( 'Parent-directory traversal is not allowed for repository assets.', 'pin-site-importer' )
                );
            }

            $parts[] = sanitize_file_name( $part );
        }

        if ( ! $parts || in_array( '', $parts, true ) ) {
            return new WP_Error(
                'pin_asset_path_empty',
                __( 'The repository asset path could not be normalized.', 'pin-site-importer' )
            );
        }

        return implode( '/', $parts );
    }

    private function url_path( string $path ): string {
        return implode(
            '/',
            array_map(
                'rawurlencode',
                explode( '/', $path )
            )
        );
    }
}
