PIN Universal FSE
Version: 0.3.2
Source: https://github.com/omarreact/WpBlockThemeFSE
WordPress 6.6+ | PHP 8.1+

PIN Universal FSE is a content-neutral WordPress Full Site Editing block theme
packaged in the same repository and distribution as the PIN Site Importer
companion plugin.

INSTALL
1. Download this repository as a ZIP.
2. WordPress: Appearance > Themes > Add New > Upload Theme.
3. Install and activate PIN Universal FSE.
4. On the first eligible administrator request, the theme installs or updates
   the bundled PIN Site Importer under wp-content/plugins and activates it.
5. If hosting permissions block the filesystem write, an administrator notice
   provides a nonce-protected retry action.
6. Open Tools > PIN Site Importer.

THEME RESPONSIBILITIES
- Native Full Site Editing templates and template parts.
- Gutenberg-editable header, footer, navigation and page content.
- theme.json global typography, colors, spacing, dimensions and design tools.
- Content-neutral presentation with no project-specific website branding.

COMPANION PLUGIN RESPONSIBILITIES
- GitHub repository inspection through WordPress safe HTTP APIs.
- Auto source selection: structured JSON first, WordPress WXR/XML second, rendered HTML fallback.
- site-manifest.json/content.json/design-tokens.json ingestion with structured page metadata, SEO hints and CSS design-token variables.
- WordPress WXR import for posts/pages, parent relationships, public custom fields, categories and tags.
- Public repositories plus optional per-request GitHub token for private repos.
- Public repository file bodies are fetched from raw.githubusercontent.com instead of the rate-limited Contents API; repository metadata/tree discovery is cached briefly and uses only a few API requests.
  Tokens are never stored.
- Optional branch and static build-folder selection, e.g. out/ or dist/.
- Automatic detection/selection of a clear common static output root when the
  repository has no root-level HTML (out/, dist/, build/, public/, site/, docs/).
- Technology detection and bounded static HTML discovery.
- HTML to native Gutenberg core-block conversion for headings, paragraphs,
  images/galleries, lists, quotes, tables, buttons, details, audio/video,
  responsive column-like groups, structural groups and navigation.
- Source header/footer extraction into database-backed editable FSE Template
  Parts instead of duplicating them inside every page.
- Existing user-managed FSE header/footer protection: only importer-owned parts
  for the same repository are updated on re-import.
- Source folder hierarchy preservation as WordPress parent/child pages.
- Imported-page upsert keyed by repository + branch + source path.
- Internal link rewriting to imported WordPress page permalinks.
- Authenticated repository image import into the WordPress Media Library,
  including private-repository assets.
- Local CSS, embedded critical CSS, safe inline-style preservation and
  CDN/external stylesheet loading.
- Root-relative CSS asset rewriting against the selected static build folder.
- CSS font/image assets plus safe static build chunks/fonts/assets mirrored into
  WordPress uploads while preserving repository-relative paths.
- Local classic/module JavaScript mirroring and frontend loading.
- Optional external/CDN JavaScript loading for explicitly trusted repositories.
- Imported CSS and external styles injected into the Gutenberg/Site Editor
  content canvas so editing more closely matches the imported frontend.
- WordPress Navigation generation from imported pages while preserving
  source-provided Navigation blocks.
- Optional root index.html assignment as the WordPress front page.
- Import history, warnings and explicit error reporting.
- Version-aware bundled plugin synchronization when the theme is updated.

FRAMEWORK BEHAVIOR
The importer consumes rendered/static HTML. Plain static sites can be imported
directly. Next.js, React, Vite, Angular and similar repositories must already
contain a static build/export folder such as out/ or dist/. Dynamic server
application source is not falsely treated as a rendered website.

This distinction is deliberate: converting arbitrary framework source code into
a working WordPress application would require rebuilding application-specific
backend/API/authentication behavior, which is different from importing a
rendered website into editable WordPress blocks.

SECURITY / SAFETY
- Import actions require administrator capability checks and WordPress nonces.
- User-controlled server-side URLs use safe WordPress HTTP requests.
- GitHub tokens are request-only and are not persisted.
- Repository tree/file sizes, page counts, CSS and mirrored assets are bounded.
- Asset paths are normalized and parent-directory traversal is rejected.
- Existing imported pages are updated instead of blindly duplicated.
- Existing user-managed FSE template parts are not overwritten.
- The companion remains a normal WordPress plugin after installation.

VALIDATION
GitHub Actions currently performs:
- PHP syntax validation for every PHP file.
- JSON validation.
- Required package/file checks.
- Theme/plugin version consistency checks.
- Real WordPress lifecycle smoke testing with wp-env.
- Theme activation.
- Bundled plugin copy/install/activation.
- Importer class loading.
- HTML to Gutenberg conversion.
- Inline style, local CSS, external CSS and JavaScript reference extraction.
- GitHub URL/path parsing.
- WordPress parent/child page hierarchy.
- Database-backed FSE template-part creation and taxonomy assignment.
- Imported navigation injection and source-navigation preservation.
- Installable pin-universal-fse.zip packaging with the companion plugin bundled
  and development-only tests excluded.
- Structured JSON and WXR/XML source-path smoke coverage.
- Real GitHub fixture ingestion into WordPress, including pages, hierarchy,
  CSS/JavaScript/static assets, FSE template parts and navigation.
- Gutenberg editor-canvas style injection.
- Embedded critical CSS and root-relative asset URL rewriting.

A hosted staging test is still required before a production release because
real hosting filesystem permissions, PHP extensions, outbound GitHub access,
Media Library limits and server resource limits vary by provider.

CURRENT DEVELOPMENT
Development is in PR #1 on branch feat/bundled-companion-plugin. main is not
modified by this work until the PR is explicitly approved and merged.

The validation workflow also produces an installable pin-universal-fse.zip
artifact after both static validation and WordPress integration tests pass.

No production site or domain is changed by this repository alone.

0.3.2: Public structured repositories with an explicit branch can import site-manifest.json/content.json directly from GitHub raw content without repository metadata/tree API discovery.
