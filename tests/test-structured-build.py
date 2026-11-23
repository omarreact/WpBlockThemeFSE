#!/usr/bin/env python3
"""Regression tests for data loss, portability, fidelity and hostile source paths."""
from __future__ import annotations
import importlib.util
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("pin_builder", ROOT / "scripts/build-structured-site.py")
BUILD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILD)
CONTENT = "{http://purl.org/rss/1.0/modules/content/}encoded"
WP = "{http://wordpress.org/export/1.2/}"

class StructuredBuildTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="pin-structured-test-")
        self.addCleanup(self.temp.cleanup)
        self.base = pathlib.Path(self.temp.name)
        self.source, self.output = self.base / "source", self.base / "output"
        self.source.mkdir()
        self.manifest = {"site": {"name": "Fixture"}, "pages": [{"path": "/", "file": "index.html", "title": "Home"}, {"path": "/about/", "file": "about/index.html"}, {"path": "/about/team/", "file": "about/team/index.html"}]}
        self.write("index.html", '<!doctype html><html data-bs-theme="dark"><head><link rel="stylesheet" href="/assets/z-base.css"><link rel="stylesheet" href="assets/a-override.css"></head><body class="source-home"><main id="main-content" class="hero"><h1>Home</h1><p>Hello <strong>world</strong>.</p><img src="assets/logo.svg" alt="Logo"><a href="about/index.html?from=home#team">Team</a></main><script src="assets/z-library.js"></script><script type="module" src="assets/a-app.mjs"></script></body></html>')
        self.write("about/index.html", '<main><p><a href="team/index.html">Team</a><a href="../index.html">Home</a></p></main>')
        self.write("about/team/index.html", '<main><p><a href="../index.html">About</a></p></main>')
        for file, text in {"assets/z-base.css": 'body{background:url("/media/bg.svg?v=2#icon")}', "assets/a-override.css": '@import "../css/extra.css"; h1{color:red}', "css/extra.css": 'p{color:blue}', "assets/logo.svg": '<svg viewBox="0 0 10 10"/>', "media/bg.svg": '<svg/>', "assets/z-library.js": 'window.library = true;', "assets/a-app.mjs": 'import "./chunk.mjs";', "assets/chunk.mjs": 'export default true;'}.items():
            self.write(file, text)

    def write(self, relative, text):
        path = self.source / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")

    def build(self):
        self.write("site-manifest.json", json.dumps(self.manifest))
        builder = BUILD.Builder(self.source, self.output)
        wxr, archive = self.output / "website.xml", self.output / f"{builder.slug}.zip"
        result = builder.build(wxr, archive)
        items = ET.parse(wxr).getroot().findall("./channel/item")
        return builder, result, items, wxr, archive

    def test_byte_stability_and_native_markup(self):
        b, result, items, wxr, archive = self.build()
        self.assertEqual(3, result["pages"])
        text = items[0].findtext(CONTENT)
        self.assertIn('"tagName":"main"', text)
        self.assertIn('id="main-content"', text)
        self.assertIn('<h1 class="wp-block-heading">', text)
        self.assertIn('<!-- wp:image ', text)
        self.assertIn('<!-- wp:paragraph', text)
        before = wxr.read_bytes(), archive.read_bytes()
        self.build()
        self.assertEqual(before, (wxr.read_bytes(), archive.read_bytes()))
        with zipfile.ZipFile(archive) as z:
            self.assertIn(f"{b.slug}/pin-build.json", z.namelist())
            self.assertNotIn(f"{b.slug}/scripts/build-structured-site.py", z.namelist())

    def test_relative_links_hierarchy_and_queries(self):
        _, _, items, _, _ = self.build()
        self.assertIn('/__pin_route__/about/?from=home#team', items[0].findtext(CONTENT))
        self.assertIn('/__pin_route__/about/team/', items[1].findtext(CONTENT))
        self.assertIn('/__pin_route__/"', items[1].findtext(CONTENT))
        self.assertEqual(items[1].findtext(WP + "post_id"), items[2].findtext(WP + "post_parent"))

    def test_absolute_source_links_stay_on_imported_site(self):
        self.manifest["site"]["sourceUrl"] = "https://source.example/project/"
        self.write("index.html", '<p><a href="https://source.example/project/about/index.html?from=source#details">About</a><a href="https://external.example/about/">External</a></p>')
        _, _, items, _, _ = self.build()
        text = items[0].findtext(CONTENT)
        self.assertIn('/__pin_route__/about/?from=source#details', text)
        self.assertIn('https://external.example/about/', text)

    def test_bangla_paths_and_page_slugs_are_preserved(self):
        self.manifest["pages"] = [{"path": "/", "file": "index.html"}, {"path": "/পরিচিতি/", "file": "পরিচিতি/index.html", "title": "পরিচিতি"}]
        self.write("পরিচিতি/index.html", '<h1>পরিচিতি</h1>')
        self.write("index.html", '<p><a href="পরিচিতি/index.html">পরিচিতি</a></p>')
        _, _, items, _, _ = self.build()
        self.assertIn('/__pin_route__/%E0', items[0].findtext(CONTENT))
        self.assertTrue(items[1].findtext(WP + "post_name").startswith('%E0'))
        self.assertIn('পরিচিতি', items[1].findtext(CONTENT))

    def test_css_dependencies_and_script_order_are_page_specific(self):
        b, _, _, _, _ = self.build()
        config = json.loads((b.theme / "pin-build.json").read_text())
        home = config["pages"]["/"]
        self.assertTrue(home["css"][0]["url"].endswith("assets/z-base.css"))
        self.assertTrue(home["css"][1]["url"].endswith("assets/a-override.css"))
        self.assertTrue(home["js"][0]["url"].endswith("assets/z-library.js"))
        self.assertTrue(home["js"][1]["module"])
        self.assertEqual([], config["pages"]["/about/"]["js"])
        self.assertEqual("source-home", home["document"]["body"]["class"])
        css = (b.theme / "source/assets/z-base.css").read_text()
        self.assertIn('../media/bg.svg?v=2#icon', css)
        self.assertTrue((b.theme / "source/css/extra.css").is_file())

    def test_structured_sections_never_become_empty_pages(self):
        builder = BUILD.Builder(ROOT / "tests/fixtures/structured-site", self.output)
        builder.build(self.output / "website.xml", self.output / "theme.zip")
        content = (self.output / "website.xml").read_text()
        for text in ("Structured Import", "Imported from content.json", "First item", "This page verifies structured JSON conversion."):
            self.assertIn(text, content)

    def test_complex_attributes_and_responsive_images_survive(self):
        self.write("index.html", '<main id="content" data-layout="grid" style="background:url(assets/logo.svg)"><h2 id="title">Title</h2><details open><summary>Details</summary><p>Text</p></details><picture><source srcset="assets/logo.svg 1x, assets/logo.svg?v=2 2x"><img src="assets/logo.svg" loading="lazy" width="40" height="40"></picture><a class="cta" href="//external.example/path">External</a></main>')
        b, _, items, _, _ = self.build()
        content = items[0].findtext(CONTENT)
        for text in ('data-layout="grid"', 'id="content"', '<details open>', 'loading="lazy"', 'width="40"', 'height="40"', '//external.example/path', 'logo.svg?v=2 2x'):
            self.assertIn(text, content)
        self.assertIn(b.prefix + "assets/logo.svg", content)

    def test_missing_asset_is_reported(self):
        self.write("index.html", '<main><img src="assets/missing.png"></main>')
        _, result, _, _, _ = self.build()
        self.assertIn("Missing source asset: assets/missing.png", result["warnings"])

    def test_source_cannot_inject_gutenberg_or_executable_html(self):
        self.write("index.html", '<main><!-- wp:html --><p onclick="alert(1)">Safe</p><a href="java\nscript:alert(1)">Link</a><!-- /wp:html --></main>')
        _, _, items, _, _ = self.build()
        text = items[0].findtext(CONTENT)
        self.assertNotIn("onclick", text)
        self.assertNotIn("script:alert", text)

    def test_invalid_manifests_fail_without_silent_data_loss(self):
        for pages, message in (([{"path": "/", "file": "index.html"}] * 251, "Page limit"), ([{"path": "/", "file": "index.html"}] * 2, "Duplicate"), ([{"path": "/nested/child/", "file": "index.html"}], "Missing parent"), ([{"path": "/", "file": "index.html", "parent": "/about/"}, {"path": "/about/", "file": "about/index.html", "parent": "home"}], "Cyclic")):
            self.manifest["pages"] = pages
            with self.assertRaisesRegex(ValueError, message):
                self.build()

    def test_symlinks_and_path_traversal_are_rejected(self):
        secret = self.base / "outside.png"
        secret.write_text("PRIVATE")
        (self.source / "assets/link.png").symlink_to(secret)
        with self.assertRaisesRegex(ValueError, "Symlinked|escapes root"):
            self.build()
        (self.source / "assets/link.png").unlink()
        self.manifest["pages"][0]["file"] = "../secret.html"
        with self.assertRaisesRegex(ValueError, "Unsafe path"):
            self.build()

    def test_failed_build_preserves_previous_artifacts(self):
        builder, _, _, wxr, archive = self.build()
        previous = wxr.read_bytes(), archive.read_bytes(), (builder.theme / "functions.php").read_bytes()
        outside = self.base / "secret.png"
        outside.write_text("PRIVATE")
        (self.source / "assets/unsafe.png").symlink_to(outside)
        with self.assertRaisesRegex(ValueError, "Symlinked|escapes root"):
            self.build()
        self.assertEqual(previous, (wxr.read_bytes(), archive.read_bytes(), (builder.theme / "functions.php").read_bytes()))

    def test_cdata_terminator_cannot_break_wxr(self):
        self.manifest["pages"][0].update(file="", html='<p>Content</p>', seo={"title": "Title ]]> & </title>"})
        _, _, items, _, _ = self.build()
        values = [m.findtext(WP + "meta_value") for m in items[0].findall(WP + "postmeta")]
        self.assertIn("Title ]]> & </title>", values)

if __name__ == "__main__":
    unittest.main(verbosity=2)
