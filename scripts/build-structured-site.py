#!/usr/bin/env python3
"""Build a deterministic, portable FSE theme and native WXR from structured sources."""
from __future__ import annotations

import argparse
import hashlib
import html
import json
import pathlib
import posixpath
import re
import shutil
import sys
import tempfile
import unicodedata
import zipfile
from html.parser import HTMLParser
from urllib.parse import quote, unquote, urlsplit, urlunsplit
from xml.sax.saxutils import escape as xml_escape

MAX_PAGES = 250
MAX_ASSET_BYTES = 100 * 1024 * 1024
ASSET_DIRS = ("assets", "css", "js", "images", "media", "fonts", "static", "_next")
ASSET_EXT = {".css", ".js", ".mjs", ".json", ".map", ".svg", ".png", ".jpg", ".jpeg", ".gif", ".webp", ".avif", ".ico", ".woff", ".woff2", ".ttf", ".otf", ".eot", ".mp4", ".webm", ".mp3", ".wav", ".pdf", ".vtt"}
VOID = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "param", "source", "track", "wbr"}
URL_ATTRS = {"src", "poster", "data-src", "data-poster"}
ROUTE_PREFIX = "/__pin_route__/"


def jread(path):
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise ValueError(f"Expected object JSON: {path}")
    return value


def first(root, names):
    return next((root / name for name in names if (root / name).is_file()), None)


def slugify(value):
    return re.sub(r"[^a-z0-9]+", "-", value.strip().lower()).strip("-") or "site"


def safe_rel(value):
    value = urlsplit(value.replace("\\", "/").strip()).path.lstrip("/")
    parts = []
    for part in value.split("/"):
        if not part or part == ".":
            continue
        if part == "..":
            if not parts:
                raise ValueError(f"Unsafe path: {value}")
            parts.pop()
            continue
        if not all(char in "._@+~=-" or unicodedata.category(char)[0] in "LNM" for char in part):
            raise ValueError(f"Unsafe path segment: {part}")
        parts.append(part)
    return "/".join(parts)


def route_norm(value):
    value = "/" + value.strip().strip("/") if value.strip().strip("/") else "/"
    if "?" in value or "#" in value or ".." in value.split("/") or "//" in value:
        raise ValueError(f"Invalid page route: {value}")
    value = re.sub(r"(?:index)?\.html?$", "", value)
    return value if value.endswith("/") else value + "/"


def route_slug(value):
    if route_norm(value) == "/":
        return "home"
    leaf = unquote(route_norm(value).strip("/").split("/")[-1]).lower()
    leaf = "".join(char if char in "-_" or unicodedata.category(char)[0] in "LNM" else "-" for char in leaf)
    return quote(re.sub(r"-+", "-", leaf).strip("-"), safe="-_") or "page"


def route_parent(value):
    bits = route_norm(value).strip("/").split("/")
    return "/" + "/".join(bits[:-1]) + "/" if len(bits) > 1 else ""


def cdata(value):
    return "<![CDATA[" + str(value).replace("]]>", "]]]]><![CDATA[>") + "]]>"


def block_markup(name, body, attrs=None):
    # Gutenberg escapes these characters so HTML comments cannot terminate early.
    encoded = json.dumps(attrs, ensure_ascii=False, separators=(",", ":")) if attrs else ""
    for char, escaped in (("<", "\\u003c"), (">", "\\u003e"), ("&", "\\u0026")):
        encoded = encoded.replace(char, escaped)
    encoded = encoded.replace("--", "\\u002d\\u002d")
    return f'<!-- wp:{name}{" " + encoded if encoded else ""} -->{body}<!-- /wp:{name} -->'


class Node:
    def __init__(self, tag=None, attrs=None, text=""):
        self.tag, self.attrs, self.text, self.children = tag, dict(attrs or []), text, []


class Tree(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.root = Node("document")
        self.stack = [self.root]

    def handle_starttag(self, tag, attrs):
        node = Node(tag, attrs)
        self.stack[-1].children.append(node)
        if tag not in VOID:
            self.stack.append(node)

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        self.handle_endtag(tag)

    def handle_endtag(self, tag):
        for index in range(len(self.stack) - 1, 0, -1):
            if self.stack[index].tag == tag:
                del self.stack[index:]
                break

    def handle_data(self, data):
        if data:
            self.stack[-1].children.append(Node(text=data))

    def handle_comment(self, data):
        # Preserve ordinary comments, but never allow source text to inject blocks.
        if not re.match(r"\s*/?wp:", data):
            self.stack[-1].children.append(Node("comment", text=data))


class Builder:
    def __init__(self, src, out, theme_slug=None):
        self.src, self.out = src.resolve(), out.resolve()
        if self.out == self.src or self.src.is_relative_to(self.out):
            raise ValueError("Output must not contain the source directory")
        manifest = first(self.src, ["site-manifest.json", "manifest.site.json"])
        if not manifest:
            raise ValueError("Missing site-manifest.json or manifest.site.json")
        self.manifest = jread(self.source_path(manifest.relative_to(self.src).as_posix()))
        content = first(self.src, ["content.json", "site-content.json"])
        tokens = first(self.src, ["design-tokens.json", "tokens.json", "theme-tokens.json"])
        self.content = jread(self.source_path(content.name)) if content else {}
        self.tokens = jread(self.source_path(tokens.name)) if tokens else {}
        site = self.manifest.get("site", {})
        self.name = str(site.get("name") or "PIN Structured Site").replace("*/", "").replace("\r", " ").replace("\n", " ")
        self.source_url = str(site.get("sourceUrl") or site.get("url") or "")
        self.slug = slugify(theme_slug or f"pin-site-{self.name}")
        self.theme = self.out / self.slug
        if self.theme.is_relative_to(self.src) and self.theme.relative_to(self.src).parts[0] in ASSET_DIRS:
            raise ValueError("Output cannot be inside a source asset directory")
        self.prefix = f"/wp-content/themes/{self.slug}/source/"
        self.pages = self._pages()
        self.routes = {p["path"] for p in self.pages}
        self.file_routes = {safe_rel(str(p["file"])): p["path"] for p in self.pages if p.get("file")}
        self.assets, self.route_ids, self.page_assets = set(), {}, {}
        self.warnings, self.counts = [], {"native_blocks": 0, "html_blocks": 0}
        self.generated = {}
        self.current = None
        self.document_attrs = {}

    def source_path(self, ref):
        path = self.src / safe_rel(ref)
        if not path.resolve().is_relative_to(self.src):
            raise ValueError(f"Source path escapes root: {ref}")
        if path.is_symlink() or any(p.is_symlink() for p in path.parents if p != self.src and p.is_relative_to(self.src)):
            raise ValueError(f"Symlinked source path is not supported: {ref}")
        return path

    def _pages(self):
        manifest_pages, content_pages = self.manifest.get("pages", []), self.content.get("pages", [])
        if not isinstance(manifest_pages, list) or not isinstance(content_pages, list):
            raise ValueError("pages must be an array")
        content_map = {route_norm(str(p.get("path") or p.get("slug") or "/")): p for p in content_pages if isinstance(p, dict)}
        source = manifest_pages or content_pages
        if len(source) > MAX_PAGES:
            raise ValueError(f"Page limit exceeded ({MAX_PAGES}); no pages were silently omitted")
        pages, routes = [], set()
        for index, page in enumerate(source):
            if not isinstance(page, dict):
                raise ValueError("Each page must be an object")
            route = route_norm(str(page.get("path") or page.get("slug") or "/"))
            if route in routes:
                raise ValueError(f"Duplicate page route: {route}")
            routes.add(route)
            merged = {**content_map.get(route, {}), **page, "path": route}
            merged.setdefault("order", index)
            pages.append(merged)
        if not pages:
            raise ValueError("Structured manifest contains no pages")
        # Never flatten a nested path silently: WordPress needs a real parent page.
        for page in pages:
            parent = page.get("parent")
            parent = "/" if parent == "home" else route_norm(str(parent)) if parent else route_parent(page["path"])
            if parent and parent not in routes:
                raise ValueError(f"Missing parent page {parent} for {page['path']}")
            page["parent_route"] = parent
        parents = {p["path"]: p["parent_route"] for p in pages}
        slugs = set()
        for page in pages:
            seen, node = set(), page["path"]
            while node:
                if node in seen:
                    raise ValueError(f"Cyclic page hierarchy: {page['path']}")
                seen.add(node)
                node = parents[node]
            key = (page["parent_route"], route_slug(page["path"]))
            if key in slugs:
                raise ValueError(f"Colliding WordPress page slug: {page['path']}")
            slugs.add(key)
        return pages

    def local_ref(self, ref, base=""):
        parsed = urlsplit(ref.strip())
        if parsed.scheme or parsed.netloc or not parsed.path:
            return None
        raw = unquote(parsed.path)
        rel = safe_rel(raw if raw.startswith("/") else str(pathlib.PurePosixPath(base).parent / raw))
        return rel, ("?" + parsed.query if parsed.query else "") + ("#" + parsed.fragment if parsed.fragment else "")

    def asset_ref(self, ref, base=""):
        local = self.local_ref(ref, base)
        if local is None:
            return ref
        rel, suffix = local
        path = self.source_path(rel)
        if path.suffix.lower() not in ASSET_EXT:
            raise ValueError(f"Unsupported referenced asset: {rel}")
        if not path.is_file():
            self.warnings.append(f"Missing source asset: {rel}")
        else:
            self.assets.add(rel)
        return self.prefix + rel + suffix

    abs_asset = asset_ref

    def link(self, ref, base=""):
        if not ref or ref.startswith(("#", "?")):
            return ref
        parsed = urlsplit(ref)
        if parsed.scheme or parsed.netloc:
            origin = urlsplit(self.source_url)
            if not origin.netloc or parsed.netloc.lower() != origin.netloc.lower() or parsed.scheme not in {"", "http", "https"}:
                return ref
            source_path = parsed.path
            root_path = origin.path.rstrip("/")
            if root_path and source_path.startswith(root_path + "/"):
                source_path = source_path[len(root_path):]
            ref = urlunsplit(("", "", source_path, parsed.query, parsed.fragment))
        local = self.local_ref(ref, base)
        rel, suffix = local
        if pathlib.PurePosixPath(rel).suffix.lower() in ASSET_EXT:
            return self.asset_ref(ref, base)
        route = self.file_routes.get(rel) or route_norm("/" + rel)
        return (ROUTE_PREFIX + quote(route.lstrip("/"), safe="/") if route in self.routes else "/" + rel) + suffix

    def css_urls(self, text, base, target=None):
        def replace(ref):
            local = self.local_ref(ref, base)
            if local is None:
                return ref
            rel, suffix = local
            self.asset_ref(ref, base)
            return (posixpath.relpath("source/" + rel, posixpath.dirname(target)) if target else self.prefix + rel) + suffix
        text = re.sub(r"url\(\s*(['\"]?)([^)'\"]+)\1\s*\)", lambda m: 'url("' + replace(m[2].strip()) + '")', text, flags=re.I)
        return re.sub(r"(@import\s+)(['\"])([^'\"]+)\2", lambda m: m[1] + m[2] + replace(m[3]) + m[2], text, flags=re.I)

    def serialize(self, node, base):
        if node.tag is None:
            return html.escape(node.text, quote=False)
        if node.tag == "comment":
            return "<!--" + node.text + "-->"
        if node.tag in {"script", "base", "object", "embed"}:
            return ""
        if node.tag == "style":
            return "<style>" + self.css_urls(self.text(node), base) + "</style>"
        attrs = []
        for key, value in node.attrs.items():
            if key.startswith("on") or key == "srcdoc" or (value and re.match(r"\s*(?:javascript|vbscript):", re.sub(r"[\x00-\x20]", "", value), re.I)):
                self.warnings.append("Removed executable HTML attribute; use referenced JavaScript for interactions")
                continue
            if value is not None:
                if key in URL_ATTRS:
                    value = self.asset_ref(value, base)
                elif key in {"href", "action", "formaction"}:
                    value = self.link(value, base)
                elif key in {"srcset", "data-srcset"} and not value.lstrip().startswith("data:"):
                    value = ", ".join(self.asset_ref(parts[0], base) + (" " + parts[1] if len(parts) > 1 else "") for candidate in value.split(",") if (parts := candidate.strip().split(maxsplit=1)))
                elif key == "style":
                    value = self.css_urls(value, base)
            attrs.append(key if value is None else f'{key}="{html.escape(value, quote=True)}"')
        start = "<" + node.tag + (" " + " ".join(attrs) if attrs else "") + ">"
        inner = "".join(self.serialize(child, base) for child in node.children)
        return start if node.tag in VOID else start + inner + f"</{node.tag}>"

    def text(self, node):
        return node.text if node.tag is None else "".join(self.text(c) for c in node.children)

    def native(self, name, body, attrs=None):
        self.counts["native_blocks"] += 1
        return block_markup(name, body, attrs)

    def fallback(self, node, base):
        self.counts["html_blocks"] += 1
        return block_markup("html", self.serialize(node, base))

    def block(self, node, base):
        tag = node.tag
        if tag is None:
            return self.native("paragraph", "<p>" + html.escape(node.text.strip()) + "</p>") if node.text.strip() else ""
        if tag in {"script", "style", "template", "comment"}:
            return ""
        if tag == "img" and not (set(node.attrs) - {"src", "alt"}):
            node.attrs.setdefault("alt", "")
            image = self.serialize(node, base).removesuffix(">") + "/>"
            return self.native("image", '<figure class="wp-block-image size-full">' + image + '</figure>', {"sizeSlug": "full", "linkDestination": "none"})
        # Native blocks are used only when their save format preserves source data.
        # Rich attributes/widgets stay intact as Custom HTML instead of being flattened.
        if set(node.attrs) - {"class", "id"}:
            return self.fallback(node, base)
        attrs = {}
        if node.attrs.get("class"):
            attrs["className"] = node.attrs["class"]
        if node.attrs.get("id"):
            attrs["anchor"] = node.attrs["id"]
        if re.fullmatch(r"h[1-6]", tag):
            attrs["level"] = int(tag[1])
            saved = Node(tag, {**node.attrs, "class": " ".join(dict.fromkeys(("wp-block-heading " + node.attrs.get("class", "")).split()))}.items())
            saved.children = node.children
            return self.native("heading", self.serialize(saved, base), attrs)
        if tag == "p":
            return self.native("paragraph", self.serialize(node, base), attrs)
        if tag in {"section", "div", "main", "article", "aside"}:
            attrs["layout"] = {"type": "default"}
            if tag != "div":
                attrs["tagName"] = tag
            saved = Node(tag, {**node.attrs, "class": " ".join(dict.fromkeys(("wp-block-group " + node.attrs.get("class", "")).split()))}.items())
            body = "".join(self.block(child, base) for child in node.children)
            opening = self.serialize(saved, base).split("></" + tag + ">", 1)[0] + ">"
            return self.native("group", opening + body + f"</{tag}>", attrs)
        if tag == "hr" and not node.attrs:
            return self.native("separator", '<hr class="wp-block-separator has-alpha-channel-opacity"/>')
        return self.fallback(node, base)

    def walk(self, node):
        yield node
        for child in node.children:
            yield from self.walk(child)

    def collect(self, root, base):
        bundle = self.current
        for node in self.walk(root):
            if node.tag == "link" and "stylesheet" in node.attrs.get("rel", "").lower():
                ref = node.attrs.get("href", "")
                if ref:
                    url = self.asset_ref(ref, base)
                    entry = {"url": url, "media": node.attrs.get("media", "all")}
                    if entry not in bundle["css"]:
                        bundle["css"].append(entry)
            elif node.tag == "script":
                kind = node.attrs.get("type", "").lower()
                if kind not in {"", "module", "text/javascript", "application/javascript"}:
                    continue
                ref = node.attrs.get("src")
                if ref:
                    url = self.asset_ref(ref, base)
                elif self.text(node).strip():
                    code = self.text(node).strip()
                    rel = "assets/inline-" + hashlib.sha256(code.encode()).hexdigest()[:16] + (".mjs" if kind == "module" else ".js")
                    self.generated[rel] = code + "\n"
                    url = "__PIN_THEME__/" + rel
                    self.warnings.append("Inline JavaScript extracted; review document.currentScript and relative URL assumptions")
                else:
                    continue
                entry = {"url": url, "module": kind == "module", "head": "head" in self.ancestors(root, node), "defer": "defer" in node.attrs, "async": "async" in node.attrs}
                if entry not in bundle["js"]:
                    bundle["js"].append(entry)
            elif node.tag == "style" and self.text(node).strip():
                css = self.css_urls(self.text(node).strip(), base, "assets/generated.css")
                rel = "assets/inline-" + hashlib.sha256(css.encode()).hexdigest()[:16] + ".css"
                self.generated[rel] = css + "\n"
                entry = {"url": "__PIN_THEME__/" + rel, "media": node.attrs.get("media", "all")}
                if entry not in bundle["css"]:
                    bundle["css"].append(entry)

    def ancestors(self, root, target, parents=()):
        if root is target:
            return parents
        for child in root.children:
            found = self.ancestors(child, target, parents + (root.tag,))
            if found:
                return found
        return ()

    def parse_doc(self, raw, base, preserve_chrome=False):
        tree = Tree()
        tree.feed(raw)
        self.collect(tree.root, base)
        doc = next((n for n in self.walk(tree.root) if n.tag == "html"), None)
        body_node = next((n for n in self.walk(tree.root) if n.tag == "body"), None)
        self.document_attrs[base] = {"html": doc.attrs if doc else {}, "body": body_node.attrs if body_node else {}}
        body = next((n for n in self.walk(tree.root) if n.tag == "body"), tree.root)
        # Keep <main> and its attributes; they are commonly CSS and script targets.
        return "".join(self.block(child, base) for child in body.children if child.tag != "head" and (preserve_chrome or child.tag not in {"header", "footer"}))

    def sections_html(self, sections):
        if not isinstance(sections, list):
            raise ValueError("Page sections must be an array")
        result = []
        for section in sections:
            if not isinstance(section, dict):
                raise ValueError("Section must be an object")
            body = ""
            title = section.get("heading") or section.get("title")
            if title:
                body += "<h2>" + html.escape(str(title)) + "</h2>"
            if section.get("text"):
                body += "<p>" + html.escape(str(section["text"])) + "</p>"
            if section.get("image"):
                body += '<img src="' + html.escape(str(section["image"]), quote=True) + '" alt="' + html.escape(str(section.get("alt", "")), quote=True) + '">'
            for button in section.get("buttons", []):
                body += '<a class="button" href="' + html.escape(str(button.get("url", "#")), quote=True) + '">' + html.escape(str(button.get("label", "Learn more"))) + '</a>'
            if section.get("items"):
                body += '<div class="pin-items">'
                for item in section["items"]:
                    item = {"text": item} if isinstance(item, str) else item
                    body += "<div>" + self.sections_html([item]) + "</div>"
                body += "</div>"
            body += str(section.get("html", ""))
            result.append('<section class="pin-import-' + slugify(str(section.get("type", "section"))) + '">' + body + '</section>')
        return "".join(result)

    def page_html(self, page):
        if page.get("file"):
            rel = safe_rel(str(page["file"]))
            path = self.source_path(rel)
            if not path.is_file():
                raise ValueError(f"Page file missing: {rel}")
            return path.read_text(encoding="utf-8", errors="replace"), rel
        # Inline content is relative to the logical page, not a fabricated __inline__ folder.
        base = page["path"].lstrip("/") + "index.html"
        if isinstance(page.get("html"), str):
            return page["html"], base
        if isinstance(page.get("sections"), list):
            return self.sections_html(page["sections"]), base
        if isinstance(page.get("content"), str):
            return "<p>" + html.escape(page["content"]) + "</p>", base
        raise ValueError(f"Page has no file, html, sections or content: {page['path']}")

    def fragment_part(self, key):
        value = self.manifest.get(key)
        if not value:
            return ""
        if isinstance(value, dict) and "html" in value:
            raw, base = value["html"], "index.html"
        else:
            base = safe_rel(value if isinstance(value, str) else value.get("file", ""))
            path = self.source_path(base)
            if not path.is_file():
                raise ValueError(f"Template part file missing: {base}")
            raw = path.read_text(encoding="utf-8", errors="replace")
        tree = Tree()
        tree.feed(raw)
        self.collect(tree.root, base)
        if key == "header" and not any(n.tag == "header" for n in self.walk(tree.root)):
            self.warnings.append("Header fragment has no header wrapper; review source CSS and JavaScript selectors")
        return block_markup("html", "".join(self.serialize(c, base) for c in tree.root.children)) + "\n"

    def copy_assets(self):
        for directory in ASSET_DIRS:
            root = self.src / directory
            if root.is_symlink():
                raise ValueError(f"Symlinked asset directory: {directory}")
            if root.is_dir():
                for path in sorted(root.rglob("*")):
                    if path.is_symlink():
                        raise ValueError(f"Symlinked asset: {path}")
                    if path.is_file() and path.suffix.lower() in ASSET_EXT:
                        self.assets.add(path.relative_to(self.src).as_posix())
        processed, total = set(), 0
        while self.assets - processed:
            rel = sorted(self.assets - processed)[0]
            processed.add(rel)
            path = self.source_path(rel)
            total += path.stat().st_size
            if total > MAX_ASSET_BYTES:
                raise ValueError("Static asset budget exceeded (100 MiB)")
            target = self.theme / "source" / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            if path.suffix.lower() == ".css":
                text = self.css_urls(path.read_text(encoding="utf-8", errors="replace"), rel, "source/" + rel)
                target.write_text(text, encoding="utf-8")
            else:
                shutil.copyfile(path, target)
        for rel, content in sorted(self.generated.items()):
            path = self.theme / rel
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content, encoding="utf-8")

    def theme_json(self):
        colors, typ, layout = (self.tokens.get(key, {}) for key in ("colors", "typography", "layout"))
        palette = [{"slug": slugify(k), "name": k, "color": v} for k, v in colors.items() if isinstance(v, str) and re.fullmatch(r"#[0-9a-fA-F]{3,8}|rgba?\([0-9., %]+\)", v)]
        family = typ.get("body") or typ.get("fontFamily") or typ.get("familyBody") or "system-ui,sans-serif"
        return {"$schema": "https://schemas.wp.org/wp/6.6/theme.json", "version": 3, "settings": {"appearanceTools": True, "layout": {"contentSize": layout.get("contentWidth") or layout.get("content") or "1200px", "wideSize": layout.get("wideWidth") or layout.get("wide") or "1540px"}, "color": {"palette": palette}, "typography": {"fontFamilies": [{"slug": "body", "name": "Body", "fontFamily": family}]}}, "styles": {"typography": {"fontFamily": "var(--wp--preset--font-family--body)"}}, "templateParts": [{"name": "header", "title": "Site Header", "area": "header"}, {"name": "footer", "title": "Site Footer", "area": "footer"}]}

    def templates(self):
        header = '<!-- wp:template-part {"slug":"header"} /-->\n'
        footer = '<!-- wp:template-part {"slug":"footer"} /-->\n'
        singular = header + '<!-- wp:post-content {"layout":{"type":"default"}} /-->\n' + footer
        loop = '<!-- wp:query {"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title {"isLink":true} /--><!-- wp:post-excerpt /--><!-- /wp:post-template --><!-- wp:query-pagination --><!-- wp:query-pagination-previous /--><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination --><!-- wp:query-no-results --><!-- wp:paragraph --><p>No results found.</p><!-- /wp:paragraph --><!-- /wp:query-no-results --></div><!-- /wp:query -->'
        templates = {key: singular for key in ("singular", "page", "front-page")}
        templates.update({key: header + loop + footer for key in ("index", "home", "archive", "search")})
        templates["404"] = header + '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Page not found</h1><!-- /wp:heading --><!-- wp:search {"label":"Search","buttonText":"Search"} /-->' + footer
        if any(page.get("template") == "standalone" for page in self.pages):
            templates["pin-standalone"] = '<!-- wp:post-content {"layout":{"type":"default"}} /-->\n'
        return templates

    def make_wxr(self, pages):
        lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>', f'<title>{xml_escape(self.name)}</title>', f'<link>{xml_escape(self.source_url or "https://example.invalid")}</link>', '<description>PIN Structured Build Mode WordPress export</description>', '<wp:wxr_version>1.2</wp:wxr_version>']
        for page in pages:
            route = page["path"]
            status = "publish" if page.get("status", "publish") == "publish" else "draft"
            lines += ['<item>', '<title>' + xml_escape(str(page.get("title") or route_slug(route).title())) + '</title>', '<dc:creator>admin</dc:creator>', f'<wp:post_id>{self.route_ids[route]}</wp:post_id>', '<wp:post_name>' + xml_escape(route_slug(route)) + '</wp:post_name>', f'<wp:post_parent>{self.route_ids.get(page["parent_route"], 0)}</wp:post_parent>', '<wp:post_type>page</wp:post_type>', f'<wp:status>{status}</wp:status>', f'<wp:menu_order>{int(page["order"])}</wp:menu_order>', '<content:encoded>' + cdata(page["blocks"]) + '</content:encoded>']
            meta = {"_pin_structured_route": route, "_pin_structured_theme": self.slug, "_pin_structured_source_path": str(page.get("file") or route)}
            if page.get("template") == "standalone":
                meta["_wp_page_template"] = "pin-standalone"
            for key in ("title", "description"):
                if isinstance(page.get("seo"), dict) and page["seo"].get(key):
                    meta["_pin_import_seo_" + key] = page["seo"][key]
            for key, value in meta.items():
                lines += ['<wp:postmeta><wp:meta_key>' + key + '</wp:meta_key><wp:meta_value>' + cdata(value) + '</wp:meta_value></wp:postmeta>']
            lines += ['</item>']
        return "\n".join(lines + ['</channel></rss>', ''])

    def _build(self, wxr, zip_path):
        if self.theme.is_symlink():
            raise ValueError("Output theme cannot be a symlink")
        # Validate page input files before replacing generated output.
        sources = [(page, *self.page_html(page)) for page in self.pages]
        if self.theme.exists():
            shutil.rmtree(self.theme)
        self.theme.mkdir(parents=True)
        self.route_ids = {p["path"]: 1000 + i for i, p in enumerate(self.pages)}
        generated = []
        for page, raw, base in sources:
            self.current = {"css": [], "js": []}
            blocks = self.parse_doc(raw, base, page.get("template") == "standalone")
            self.page_assets[page["path"]] = {**self.current, "document": self.document_attrs.get(base, {}), "standalone": page.get("template") == "standalone"}
            generated.append({**page, "blocks": blocks})
        parts, bundles = {}, {}
        for key in ("header", "footer"):
            self.current = {"css": [], "js": []}
            parts[key] = self.fragment_part(key)
            bundles[key] = self.current
        shared = {
            "css": bundles["header"]["css"] + bundles["footer"]["css"],
            "js": bundles["header"]["js"] + bundles["footer"]["js"],
            "header_js": bundles["header"]["js"], "footer_js": bundles["footer"]["js"],
        }
        self.copy_assets()
        for directory in ("parts", "templates"):
            (self.theme / directory).mkdir(exist_ok=True)
        (self.theme / "style.css").write_text(f"/*\nTheme Name: {self.name}\nDescription: Generated by PIN Structured Build Mode.\nVersion: 0.2.0\nRequires at least: 6.6\nRequires PHP: 8.1\nText Domain: {self.slug}\n*/\n", encoding="utf-8")
        theme_settings = self.theme_json()
        if any(page.get("template") == "standalone" for page in self.pages):
            theme_settings["customTemplates"] = [{"name": "pin-standalone", "title": "Independent source page", "postTypes": ["page"]}]
        (self.theme / "theme.json").write_text(json.dumps(theme_settings, indent=2) + "\n", encoding="utf-8")
        shutil.copyfile(pathlib.Path(__file__).with_name("structured-theme-runtime.php"), self.theme / "functions.php")
        config = {"slug": self.slug, "asset_prefix": self.prefix, "pages": self.page_assets, "shared": shared}
        (self.theme / "pin-build.json").write_text(json.dumps(config, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        for key, content in parts.items():
            (self.theme / "parts" / f"{key}.html").write_text(content, encoding="utf-8")
        for key, content in self.templates().items():
            (self.theme / "templates" / f"{key}.html").write_text(content, encoding="utf-8")
        wxr.parent.mkdir(parents=True, exist_ok=True)
        wxr.write_text(self.make_wxr(generated), encoding="utf-8")
        report = {"site": self.name, "theme_slug": self.slug, "pages": len(generated), "assets": len(self.assets), **self.counts, "warnings": sorted(set(self.warnings)), "source_contract": "PIN structured v1"}
        (self.theme / "build-report.json").write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        zip_path.parent.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for path in sorted(self.theme.rglob("*")):
                if path.is_file():
                    info = zipfile.ZipInfo((pathlib.PurePosixPath(self.slug) / path.relative_to(self.theme)).as_posix(), date_time=(1980, 1, 1, 0, 0, 0))
                    info.compress_type, info.create_system, info.external_attr = zipfile.ZIP_DEFLATED, 3, 0o100644 << 16
                    archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        return {**report, "theme_dir": str(self.theme), "wxr": str(wxr), "zip": str(zip_path)}


    def build(self, wxr, zip_path):
        """Stage a complete build before replacing any previously generated artifact."""
        original_theme = self.theme
        wxr, zip_path = wxr.resolve(), zip_path.resolve()
        targets = (original_theme, wxr, zip_path)
        if len(set(targets)) != 3 or wxr.is_relative_to(original_theme) or zip_path.is_relative_to(original_theme):
            raise ValueError("Theme directory, WXR and ZIP must be separate output paths")
        for target in (wxr, zip_path):
            if target.is_relative_to(self.src) and not target.is_relative_to(self.out):
                raise ValueError("Build output cannot overwrite source files")
        if original_theme.is_symlink():
            raise ValueError("Output theme cannot be a symlink")
        self.out.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix=".pin-build-", dir=self.out) as temporary:
            stage = pathlib.Path(temporary)
            self.theme = stage / self.slug
            try:
                result = self._build(stage / "website.xml", stage / "theme.zip")
                staged = (self.theme, stage / "website.xml", stage / "theme.zip")
                installed, backups = [], []
                try:
                    for index, (source, target) in enumerate(zip(staged, targets)):
                        target.parent.mkdir(parents=True, exist_ok=True)
                        backup = stage / f"previous-{index}"
                        if target.exists():
                            shutil.move(str(target), str(backup))
                            backups.append((backup, target))
                        shutil.move(str(source), str(target))
                        installed.append(target)
                except Exception:
                    for target in reversed(installed):
                        shutil.rmtree(target) if target.is_dir() else target.unlink()
                    for backup, target in reversed(backups):
                        shutil.move(str(backup), str(target))
                    raise
                return {**result, "theme_dir": str(original_theme), "wxr": str(wxr), "zip": str(zip_path)}
            finally:
                self.theme = original_theme


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-root", required=True)
    parser.add_argument("--out-dir", default="build/structured-site")
    parser.add_argument("--wxr-path", default="")
    parser.add_argument("--zip-path", default="")
    parser.add_argument("--theme-slug", default="")
    args = parser.parse_args()
    try:
        builder = Builder(pathlib.Path(args.source_root), pathlib.Path(args.out_dir), args.theme_slug or None)
        result = builder.build(pathlib.Path(args.wxr_path) if args.wxr_path else builder.out / "website.xml", pathlib.Path(args.zip_path) if args.zip_path else builder.out / f"{builder.slug}.zip")
        print(json.dumps(result, indent=2, ensure_ascii=False))
        return 0
    except Exception as error:
        print(f"PIN structured build failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
