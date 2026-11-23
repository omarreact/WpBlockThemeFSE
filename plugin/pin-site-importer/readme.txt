=== PIN Site Importer ===
Contributors: pincodeit
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPL-2.0-or-later

GitHub/static-HTML to native WordPress Gutenberg importer bundled with
PIN Universal FSE.

== Features ==

* GitHub repository URL, optional branch and request-only token support.
* Public and token-authorized private repository access.
* Optional static build folder such as out/ or dist/.
* Technology detection and bounded static HTML discovery.
* HTML to editable Gutenberg core-block conversion.
* Source header/footer extraction to database-backed FSE Template Parts.
* Protection against overwriting user-managed header/footer customizations.
* Source folder hierarchy to WordPress parent/child page hierarchy.
* Internal imported-page link rewriting.
* Authenticated repository image import into Media Library.
* Local CSS, embedded critical CSS and safe inline-style preservation.
* External/CDN stylesheet and web-font preservation.
* Build-root-aware CSS asset rewriting and static asset/chunk/font mirroring.
* Local classic and module JavaScript mirroring/loading.
* Optional external/CDN JavaScript loading for trusted imports.
* Imported styling injected into the Gutenberg/Site Editor content canvas.
* Imported WordPress Navigation generation.
* Preservation of navigation links extracted from source headers.
* Optional imported root index.html front-page assignment.
* Re-import updates existing imported pages using source metadata.
* Import result warnings and recent import history.

== Frameworks ==

The importer consumes rendered/static HTML. Static sites work directly.
Next.js, React, Vite, Angular and similar projects must contain an existing
static build/export output folder. Dynamic application source by itself is not
treated as a rendered website.

== Installation ==

PIN Universal FSE bundles this plugin and installs/activates it after theme
activation. A version-aware theme bootstrap also synchronizes a newer bundled
plugin version after theme updates. If filesystem permissions block the copy,
the theme shows an administrator retry action.

== Security ==

Import actions require administrator capabilities and nonces. GitHub access
tokens are used only for the current request and are not stored. Server-side
remote requests use WordPress safe HTTP APIs. Import sizes and asset paths are
bounded and validated.

== Validation ==

The repository CI includes PHP/JSON/package checks and an actual WordPress
wp-env lifecycle smoke test covering theme activation, companion activation,
Gutenberg conversion, page hierarchy, FSE template parts and navigation
behavior. CI also imports a real fixture from this GitHub repository through the
same remote GitHub API pipeline and verifies CSS, JavaScript, static assets,
editor-canvas styles and internal-link rewriting.


== Structured sources ==

Auto mode prefers site-manifest.json/content.json with optional design-tokens.json,
then WordPress WXR/XML exports, then rendered HTML. WXR imports posts/pages,
hierarchy, public custom fields, categories and tags. JSON pages are normalized
through the same Gutenberg/FSE pipeline used by HTML imports.
