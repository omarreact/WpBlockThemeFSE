# PIN Structured Build Mode

Build a standalone WordPress FSE theme ZIP and native WXR 1.2 from a structured
repository. The generated site does not require PIN Site Importer or runtime
GitHub access. Referenced external/CDN styles and scripts still require their
original hosts.

## Build

```sh
python scripts/build-structured-site.py --source-root /path/to/source --out-dir build/my-site
```

Optional flags: `--wxr-path`, `--zip-path`, `--theme-slug`.
Python 3.10+ is required. The generator uses only the Python standard library.

The source root must contain `site-manifest.json` or `manifest.site.json`.
Optional content files: `content.json`, `site-content.json`.
Optional token files: `design-tokens.json`, `tokens.json`, `theme-tokens.json`.

Each page must provide a `file`, inline `html`, text `content`, or `sections`.
Sections support headings/titles, text, images, buttons, item grids and HTML.
Manifest page records override matching content records. Page paths identify
pages; source file paths resolve relative assets and links. Bangla/Unicode page
paths and filenames are supported; WordPress slugs use encoded UTF-8.

Header/footer can be file references or inline HTML. Supply their **complete
source markup**, including wrappers, classes and data attributes used by source
CSS and JavaScript. The builder reports a missing header wrapper for review.
It does not infer arbitrary wrappers from JavaScript application code.

Nested routes need explicit parent page records. `parent` may name a route;
`home` refers to `/`. Missing parents, duplicate routes, colliding WordPress
slugs, cycles and more than 250 pages produce errors instead of truncated or
flattened imports.

## Outputs

- A generated theme directory and installable theme ZIP.
- `website.xml`: pages, hierarchy, ordering, publish/draft state and source/SEO metadata.
- Theme-owned `source/` CSS, JavaScript, images, fonts and other allowed assets.
- `pin-build.json`: per-page assets and source document attributes.
- `build-report.json`: page/asset counts, native/HTML block counts and warnings.

ZIP and WXR are byte-stable across identical builds. Builds are staged before
replacing prior outputs; failed builds preserve the previous artifacts.
Symlinked inputs, path escapes and assets above the 100 MiB budget are rejected.
Development tests and tooling are not copied into the generated theme.

## Fidelity and editing

Simple paragraphs, headings, semantic groups and plain images become native
Gutenberg blocks. Main/section/article tags, classes and anchors are preserved.
Markup that requires unsupported attributes or widget structure remains editable
Custom HTML. Forms, SVG widgets, responsive pictures and complex lists are not
silently flattened into a different design. Custom HTML is editable as markup;
it is not a visual control for every source property.

Styles and scripts retain their source order and page assignment. External
styles, inline CSS, media queries, classic/module scripts, defer/async flags and
inline JavaScript are preserved. Executable inline scripts are extracted into
theme-owned files; event-handler HTML attributes and executable URL schemes are
removed. Only build trusted source repositories. Review extracted scripts that
depend on `document.currentScript`, inline execution timing or dynamic URLs.
Scripts that generate URLs/content at runtime may require source-specific work;
the builder does not rewrite arbitrary JavaScript application logic.

Local asset query strings/fragments, CSS `url()`/imports, responsive image URLs,
posters and downloads are resolved and copied. Referenced missing files are
reported. CSS asset paths are rewritten relative to copied CSS, so custom
`WP_CONTENT_URL`, HTTPS, subdirectory and alternate content-path installations
work. Frontend block rendering and editor REST content resolve generated theme
markers through WordPress theme APIs.

Internal links use imported page metadata and WordPress permalinks. Nested
relative links and links to the declared source origin keep their query/hash.
Source body classes and supported HTML data/class/ID attributes survive on the
frontend. Source CSS is loaded in the block editor; source website scripts do
not execute inside the editor. A source website's own CSS or scripts can still
conflict with WordPress or need adaptation on its target host.

Archive/search/blog templates use a Query Loop; 404 uses a dedicated not-found
view. Theme activation does not modify homepage or permalink settings.

## Install on staging

1. Install and activate the generated theme ZIP on a fresh staging WordPress.
2. Use Tools > Import > WordPress to import `website.xml` with an administrator.
3. Select the imported Home page as the static front page.
4. Choose the desired permalink structure.
5. Review `build-report.json` and resolve missing source files/wrapper warnings.
6. Verify desktop/mobile design, menus, media, forms, dynamic scripts and editor
   save/reload behavior before switching a production site.

Dynamic application backends, authentication, server APIs and framework build
steps are not converted. A static export must already exist. Theme-owned images
are usable in imported content; the build path does not create Media Library
attachment records. Re-importing WXR follows WordPress Importer's behavior;
back up edited content before repeating an import.

## Validation

```sh
python tests/test-structured-build.py
python scripts/verify-structured-build.py build/my-site --expected-pages 52 --expected-theme pin-site-meta-grey-label
```

CI builds a pinned real Meta Grey Label source revision, imports its 52 pages
into WordPress, runs `tests/wp-structured-build.php`, and validates generated
page/template/part markup against WordPress's actual Gutenberg save functions.
The JS check uses locked test-only dependencies under `tests/gutenberg/`.

Runtime URL/editor behavior follows WordPress's
[editor asset guidance](https://developer.wordpress.org/block-editor/how-to-guides/enqueueing-assets-in-the-editor/)
and [block rendering filters](https://developer.wordpress.org/reference/hooks/render_block_data/).

Pages with `"template": "standalone"` preserve their complete source header/footer
and use the Independent source page template. Shared template-part assets are
excluded on those pages, so alternate homepages keep their own composition.

## Opt-in full-width source layout

A manifest may specify `"sourceLayout": {"edgeToEdge":true,"wrapperClass":"page-wrapper","dark":true}`. This keeps original structural wrappers using a dynamic source-layout block with editable InnerBlocks; source classes, safe data attributes and CSS stay intact. Complex widgets remain Custom HTML, not flattened into approximate core blocks. The generated theme includes a local setup dashboard, opens after activation and imports bundled pages in batches of four; existing unrelated pages are never overwritten. Legacy migration is opt-in and requires an explicit sourceLayout.legacyPage ownership contract (ownerKey, ownerValue, slugKey). The one-click dashboard processes sequential four-page requests with capability/nonce checks; conflicts preserve progress. No project-specific metadata is embedded in the universal builder. No GitHub access is needed after installation. The ordinary neutral builder path is unchanged. Real browser visual comparison is still required before claiming pixel parity.
