PIN Universal FSE
Version: 0.2.0
Source: https://github.com/omarreact/WpBlockThemeFSE
WordPress 6.6+ | PHP 8.1+

A content-neutral WordPress Full Site Editing (FSE) block theme packaged in the
same repository as its PIN Site Importer companion plugin.

Install
1. Download the repository ZIP from GitHub.
2. In WordPress, go to Appearance > Themes > Add New > Upload Theme.
3. Upload the ZIP, install, and activate the theme.
4. On the first administrator request after activation, the theme attempts to
   copy the bundled PIN Site Importer into wp-content/plugins and activate it.
5. If the host blocks that filesystem write, WordPress shows an administrator
   notice with a retry action instead of silently failing.
6. Edit the site under Appearance > Editor.
7. Open Tools > PIN Site Importer to verify the companion plugin is active.

Architecture
- Theme: templates, template parts, patterns, global styles, and presentation.
- Companion plugin: GitHub/HTML import, conversion, media, pages, navigation,
  and other migration behavior.
- Both live in this repository and ship together with the theme package.
- The companion remains a normal WordPress plugin after installation, so its
  functionality is not tied to keeping this theme active.

Current companion-plugin phase
- Bundled plugin source: implemented.
- Theme activation install/activate bootstrap: implemented.
- Capability checks, nonce-protected retry, and visible failure handling:
  implemented.
- GitHub repository crawler/importer: not implemented yet.
- HTML/CSS to native Gutenberg block conversion: not implemented yet.
- Media, page, and navigation migration engines: not implemented yet.

FSE scope
- Editable Gutenberg header, footer, templates, and pattern.
- Standard WordPress Site Editor and global design settings.
- No Meta Grey Label content, branding, or site-specific CSS.
- Gutenberg is a block canvas, not an arbitrary free-positioning design tool.

Important WordPress behavior
WordPress core does not install an unrelated plugin as part of the theme-upload
step itself. This package therefore performs the companion installation on the
first eligible administrator request after theme activation. On normal writable
WordPress hosting this produces the intended single theme-install workflow.

Staging
Automated repository validation checks PHP syntax, JSON validity, and required
package files. A real WordPress staging installation is still required before
production release to validate filesystem permissions, plugin activation, and
Site Editor behavior.

No production WordPress site, domain, or other repository is changed merely by
publishing this source.
