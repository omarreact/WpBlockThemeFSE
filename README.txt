PIN Universal FSE
Version: 0.2.0
Source: https://github.com/omarreact/WpBlockThemeFSE
WordPress 6.6+ | PHP 8.1+

PIN Universal FSE is a WordPress Full Site Editing block theme packaged in the
same repository and distribution as the PIN Site Importer companion plugin.

INSTALL
1. Download this repository as a ZIP.
2. WordPress: Appearance > Themes > Add New > Upload Theme.
3. Install and activate PIN Universal FSE.
4. On the first eligible administrator request, the theme installs/updates the
   bundled PIN Site Importer under wp-content/plugins and activates it.
5. If hosting permissions block the filesystem write, an administrator notice
   provides a nonce-protected retry action.
6. Open Tools > PIN Site Importer.

THEME RESPONSIBILITIES
- Native Full Site Editing templates and template parts.
- Gutenberg-editable header, footer, navigation and page content.
- theme.json global typography, colors, spacing, dimensions and design tools.
- Content-neutral presentation; no Meta Grey Label/project-specific branding.

COMPANION PLUGIN RESPONSIBILITIES
- GitHub repository inspection using WordPress safe HTTP APIs.
- Public repository import and optional per-request GitHub token for private
  repository access. Tokens are not persisted.
- Optional branch selection.
- Optional static build folder selection, e.g. out/ or dist/.
- Static HTML page discovery with a bounded per-run safety limit.
- HTML to native Gutenberg core-block conversion.
- Imported-page upsert keyed by repository + branch + source path.
- Internal relative-link rewriting to imported WordPress page permalinks.
- Repository image sideload into the WordPress Media Library.
- Referenced repository CSS collection and per-page loading.
- CSS relative asset URL rewriting to raw GitHub assets.
- WordPress wp_navigation generation from imported pages.
- Automatic use of the imported navigation by an unconfigured PIN Universal
  FSE Navigation block.
- Optional root index.html assignment as the WordPress front page.
- Import history and warning/error reporting.

FRAMEWORK BEHAVIOR
The importer can consume static HTML output from any stack. For Next.js, React,
Vite, Angular or similar source repositories, select an existing static output
folder such as out/ or dist/. Dynamic application source is not falsely treated
as a rendered website; it must be exported/built to HTML first.

SECURITY / SAFETY
- Import actions require manage_options and WordPress nonces.
- User-controlled requests use safe WordPress HTTP functions.
- Repository tree/file sizes and pages per import are bounded.
- Automatic image sideloading is limited to GitHub raw/content hosts.
- Existing imported pages are updated instead of blindly duplicated.
- The plugin remains a normal WordPress plugin after installation.

CURRENT VALIDATION
GitHub Actions validates PHP syntax, JSON validity and required theme/plugin
package files. Real WordPress staging validation is still required before
production merge to verify filesystem permissions, plugin activation, Site
Editor block validity, media sideloading and import behavior on an actual host.

No production site or domain is changed by this repository alone.
