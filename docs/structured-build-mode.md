# PIN Structured Build Mode v1

Structured Build Mode is the deterministic build-time path for repositories that
publish a PIN structured source contract. It complements, rather than replaces,
the PIN Site Importer runtime fallback.

## Inputs

The source root must contain site-manifest.json or manifest.site.json. It may
also contain content.json/site-content.json and design-tokens.json (or supported
token aliases). Manifest pages can point at existing HTML files or provide
inline structured content. Header and footer may be supplied as inline HTML or
file references.

## Outputs

The builder emits a standalone generated WordPress FSE theme directory, an
installable theme ZIP, and native WordPress WXR 1.2 containing site pages as
Gutenberg content.

Source CSS, JavaScript, images, fonts and other safe static assets are copied
under source/ in the generated theme. Page asset references are rewritten
directly to that theme-owned path, so WordPress does not need to crawl GitHub or
re-localize those assets at import time.

## Usage

    python scripts/build-structured-site.py --source-root /path/to/source --out-dir build/my-site

Optional flags are --wxr-path, --zip-path and --theme-slug.

For Meta Grey Label the default outputs are:

    build/my-site/pin-site-meta-grey-label.zip
    build/my-site/website.xml

## WordPress staging workflow

1. Install and activate the generated theme ZIP on a fresh staging WordPress.
2. Use Tools > Import > WordPress to import website.xml.
3. Select the imported Home page as the static front page.
4. Save Post name permalinks.
5. Verify desktop/mobile layout, navigation, media, scripts, forms and editor
   behavior before any production switch.

## Conversion policy

Simple semantic HTML becomes editable core Gutenberg blocks. Complex widgets
that cannot be represented safely without losing fidelity remain core/html
blocks in v1. Source header/footer fragments are preserved inside editable FSE
template parts using core/html.

Generated artifacts are disposable build outputs; the source repository remains
canonical.

## Safety and limits

- Paths are normalized and parent traversal is rejected.
- Page count is bounded at 250.
- Only allow-listed static asset extensions are copied.
- No GitHub API or network access is required by WordPress after generation.
- Dynamic application backends, authentication, APIs and server-only framework
  behavior are not converted.

## Current v1 limitation

Generated asset URLs use the standard public WordPress theme path
/wp-content/themes/<theme-slug>/source/. Installations with a customized
WP_CONTENT_URL require a later portability layer or post-build URL mapping.
