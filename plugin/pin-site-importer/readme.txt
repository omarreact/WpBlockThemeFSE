=== PIN Site Importer ===
Contributors: pincodeit
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later

Companion plugin for PIN Universal FSE.

== Description ==

This plugin owns import/conversion functionality so that imported site content and
migration tools do not depend on the active WordPress theme.

The initial 0.1.0 package provides the companion-plugin installation foundation.
The GitHub/HTML to Gutenberg conversion engine will be implemented here in later
development phases.

== Installation ==

Normally no separate upload is required. PIN Universal FSE bundles this plugin and
attempts to install and activate it on the first administrator request after the
theme is activated.

If the host blocks automatic filesystem writes, the theme displays an administrator
notice with a retry action.
