# Remaining-work implementation and validation — 23 November 2026

Status: **PARTIAL — implementation verified locally; publishing, new GitHub CI
and hosted visual/interaction QA remain pending.**

Repository: `omarreact/WpBlockThemeFSE`.
Base: PR #5, `feat/structured-build-mode-v1`, commit
`e28c780d9ede3b70299b2b4bedf9c953c7a396e8`.
Local branch: `fix/complete-structured-import`.
No remote push, merge, deployment or production WordPress changes were made.

## Completed

- Portable generated-theme asset URLs in frontend rendering and editor REST data.
- Source-relative page links, imported-page permalinks, queries and fragments.
- Structured `sections` conversion instead of empty generated pages.
- Ordered per-page CSS/JS and shared template-part assets; module/defer/async handling.
- Inline CSS/JS preservation, external stylesheets and source document classes.
- CSS dependencies/root URL rewriting, responsive images, posters and downloads.
- Semantic containers/anchors/classes and conservative HTML fallbacks for rich widgets.
- Real Query Loop and 404 templates.
- Bangla/Unicode page paths and source filenames.
- Explicit errors for page limits, duplicate/colliding routes, invalid hierarchy and source path/symlink escapes.
- Deterministic ZIP/WXR, transactional output replacement and build warnings report.
- Pinned external fixture source and expanded WordPress/Gutenberg CI validation.
- Existing runtime smoke check updated to use the configured uploads URL.

## Verified locally

| Check | Result |
|---|---|
| Builder regression tests | 13 passed |
| PHP syntax | Passed |
| JSON/Python syntax and diff whitespace | Passed |
| Universal theme + companion-plugin ZIP contents | Passed |
| WordPress installation / universal theme activation | Passed |
| Bundled companion installation/activation | Passed |
| Existing runtime importer smoke | Passed with local GitHub HTTP fixtures |
| Real-source build | 52 pages, 83 assets |
| Native WordPress WXR import | All 52 pages imported |
| Custom content path | Frontend/editor URLs resolve under `/custom-content/` |
| Plain and Post name permalinks | Link/query/hash checks passed |
| Generated CSS/JS/editor assets and source body class | Passed |
| Gutenberg core save compatibility | 9,301 page/template/part blocks; 0 invalid |

The isolated runtime used PHP 8.4.27 and WordPress 7.2-RC2-65742 with SQLite. This is a development core checkout;
target-host/stable-core staging validation remains required.
GitHub HTTP reads were replaced with local repository fixtures for the runtime
importer smoke because DNS/URL validation prevented direct PHP reads in this
environment. This validates importer logic, not current hosting connectivity.
The last pre-existing GitHub workflow was #157, successful on the PR #5 base;
it does not validate this new local commit.

Real source revision:
`omarreact/meta-gray-label@e34ac92505549348ac4e73009d6a0805a736da53`.

## Release gates still open

- Remote push/PR approval and a successful new GitHub Actions run.
- Desktop/mobile screenshots and actual menu/form/script/editor interactions on hosted staging. Local browser launches and connected/cloud browser access to the isolated HTTP server were unavailable. DOM/save validation does not establish visual parity.
- Resolve upstream missing `assets/media/images/banner/home5/bg.svg`.
- Review upstream header fragment: it omits the outer `<header>` wrapper that source CSS/scripts may expect.
- Review extracted inline scripts and dynamically generated URLs. Arbitrary source JavaScript behavior cannot be proven by conversion/build success.

Custom HTML fallback blocks remain markup-editable; they do not expose every
widget property as a native visual Gutenberg control. Dynamic application
backends are outside static website conversion.
