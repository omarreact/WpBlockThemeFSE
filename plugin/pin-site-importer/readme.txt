=== PIN Site Importer ===
Contributors: pincodeit
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later

GitHub/static HTML to native WordPress Gutenberg importer bundled with
PIN Universal FSE.

== Features ==

* GitHub repository URL, optional branch and temporary token support.
* Optional static build folder such as out/ or dist/.
* Static HTML page discovery.
* HTML to Gutenberg core-block conversion.
* Internal imported-page link rewriting.
* Repository image sideload into Media Library.
* Referenced CSS preservation per imported page.
* Imported WordPress Navigation generation.
* Optional imported index.html front-page assignment.
* Re-import updates existing imported pages using source metadata.
* Import result warnings and recent import history.

== Frameworks ==

The importer consumes rendered/static HTML. Static sites work directly.
Next.js/React/Vite/Angular projects must contain a static build/export output
folder; dynamic application source by itself is not a rendered website.

== Installation ==

PIN Universal FSE bundles this plugin and installs/activates it after theme
activation. If filesystem permissions block the automatic copy, the theme shows
an administrator retry action.

== Security ==

Import actions require administrator capabilities and nonces. GitHub access
tokens are used only for the active request and are not stored. Remote requests
use WordPress safe HTTP APIs.
