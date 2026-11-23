#!/usr/bin/env python3
"""Build a deterministic FSE theme + native WXR from PIN structured sources."""
from __future__ import annotations
import argparse, html, json, pathlib, re, shutil, sys, zipfile
from html.parser import HTMLParser
from xml.sax.saxutils import escape as xml_escape

MAX_PAGES=250
ASSET_DIRS=("assets","css","js","images","media","fonts","static")
ASSET_EXT={".css",".js",".mjs",".json",".map",".svg",".png",".jpg",".jpeg",".gif",".webp",".avif",".ico",".woff",".woff2",".ttf",".otf",".eot",".mp4",".webm",".mp3",".wav"}
VOID={"area","base","br","col","embed","hr","img","input","link","meta","param","source","track","wbr"}

def jread(p:pathlib.Path)->dict:
    v=json.loads(p.read_text(encoding="utf-8"))
    if not isinstance(v,dict): raise ValueError(f"Expected object JSON: {p}")
    return v

def first(root:pathlib.Path,names):
    return next((root/n for n in names if (root/n).is_file()),None)

def slugify(v:str)->str:
    return re.sub(r"[^a-z0-9]+","-",v.strip().lower()).strip("-") or "site"

def safe_rel(v:str)->str:
    v=re.sub(r"[?#].*$","",v.replace("\\","/").strip()).lstrip("/")
    parts=[]
    for p in v.split("/"):
        if not p or p==".": continue
        if p=="..":
            if not parts: raise ValueError(f"Unsafe path: {v}")
            parts.pop(); continue
        if not re.fullmatch(r"[A-Za-z0-9._@+~=-]+",p): raise ValueError(f"Unsafe path segment: {p}")
        parts.append(p)
    return "/".join(parts)

def route_norm(v:str)->str:
    v=(v or "/").strip()
    if not v.startswith("/"): v="/"+v
    if v!="/" and not v.endswith("/"): v+="/"
    return v

def route_slug(v:str)->str:
    if route_norm(v)=="/": return "home"
    return slugify(route_norm(v).strip("/").split("/")[-1])

def route_parent(v:str)->str:
    bits=[x for x in route_norm(v).strip("/").split("/") if x]
    return "/"+"/".join(bits[:-1])+"/" if len(bits)>1 else ""

class Node:
    def __init__(self,tag=None,attrs=None,text=""):
        self.tag=tag; self.attrs=dict(attrs or []); self.text=text; self.children=[]

class Tree(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True); self.root=Node("document"); self.stack=[self.root]
    def handle_starttag(self,tag,attrs):
        n=Node(tag.lower(),attrs); self.stack[-1].children.append(n)
        if tag.lower() not in VOID:self.stack.append(n)
    def handle_startendtag(self,tag,attrs): self.handle_starttag(tag,attrs); self.handle_endtag(tag)
    def handle_endtag(self,tag):
        tag=tag.lower()
        for i in range(len(self.stack)-1,0,-1):
            if self.stack[i].tag==tag: del self.stack[i:]; break
    def handle_data(self,data):
        if data:self.stack[-1].children.append(Node(text=data))

class Builder:
    def __init__(self,src:pathlib.Path,out:pathlib.Path,theme_slug:str|None=None):
        self.src=src.resolve(); self.out=out.resolve()
        mp=first(self.src,["site-manifest.json","manifest.site.json"])
        if not mp: raise ValueError("Missing site-manifest.json or manifest.site.json")
        self.manifest=jread(mp)
        cp=first(self.src,["content.json","site-content.json"]); tp=first(self.src,["design-tokens.json","tokens.json","theme-tokens.json"])
        self.content=jread(cp) if cp else {}; self.tokens=jread(tp) if tp else {}
        site=self.manifest.get("site") if isinstance(self.manifest.get("site"),dict) else {}
        self.name=str(site.get("name") or "PIN Structured Site")
        self.source_url=str(site.get("sourceUrl") or site.get("url") or "")
        self.slug=slugify(theme_slug or f"pin-site-{self.name}")
        self.theme=self.out/self.slug; self.prefix=f"/wp-content/themes/{self.slug}/source/"
        self.pages=self._pages(); self.css=[]; self.js=[]; self.inline=[]; self.route_ids={}
    def _pages(self):
        a=self.manifest.get("pages") if isinstance(self.manifest.get("pages"),list) else []
        b=self.content.get("pages") if isinstance(self.content.get("pages"),list) else []
        cm={route_norm(str(p.get("path") or p.get("slug") or "/")):p for p in b if isinstance(p,dict)}
        source=a or b; out=[]
        for i,p in enumerate(source[:MAX_PAGES]):
            if not isinstance(p,dict):continue
            r=route_norm(str(p.get("path") or p.get("slug") or "/")); m=dict(cm.get(r,{})); m.update(p); m["path"]=r; m.setdefault("order",i); out.append(m)
        if not out: raise ValueError("Structured manifest contains no pages")
        return out
    def abs_asset(self,ref:str,base:str="")->str:
        if not ref or ref.startswith(("data:","#","mailto:","tel:","javascript:")): return ref
        if re.match(r"^https?://",ref) or ref.startswith("//"): return ref
        raw=re.sub(r"[?#].*$","",ref)
        if raw.startswith("/"): rel=safe_rel(raw)
        else: rel=safe_rel(str(pathlib.PurePosixPath(base).parent/pathlib.PurePosixPath(raw))) if base else safe_rel(raw)
        return self.prefix+rel
    def link(self,ref:str)->str:
        if not ref:return ref
        if ref.startswith(("#","mailto:","tel:","sms:","javascript:")) or re.match(r"^https?://",ref):return ref
        anchor=""; q=""
        if "#" in ref:ref,anchor=ref.split("#",1);anchor="#"+anchor
        if "?" in ref:ref,q=ref.split("?",1);q="?"+q
        p=ref.replace("\\","/")
        if p.endswith("index.html"): p=p[:-10]
        elif p.endswith(".html"): p=p[:-5]+"/"
        if not p.startswith("/"): p="/"+p
        p=re.sub(r"/+","/",p)
        return p+q+anchor
    def serialize(self,n:Node,base:str)->str:
        if n.tag is None:return html.escape(n.text,quote=False)
        attrs=[]
        for k,v in n.attrs.items():
            if v is None:attrs.append(k);continue
            if k in {"src","poster"}:v=self.abs_asset(v,base)
            elif k=="href":v=self.link(v) if not re.match(r"^https?://",v) else v
            attrs.append(f'{k}="{html.escape(v,quote=True)}"')
        inner="".join(self.serialize(c,base) for c in n.children)
        start="<"+n.tag+(" "+" ".join(attrs) if attrs else "")+">"
        return start if n.tag in VOID else start+inner+f"</{n.tag}>"
    def text(self,n:Node)->str:
        if n.tag is None:return n.text
        return "".join(self.text(c) for c in n.children)
    def block(self,n:Node,base:str)->str:
        if n.tag is None:
            t=n.text.strip(); return f'<!-- wp:paragraph --><p>{html.escape(t)}</p><!-- /wp:paragraph -->' if t else ""
        tag=n.tag; cls=n.attrs.get("class","")
        if tag in {"script","style","noscript","template"}:return ""
        if re.fullmatch(r"h[1-6]",tag):
            lvl=int(tag[1]); body="".join(self.serialize(c,base) for c in n.children).strip(); attrs={"level":lvl}
            if cls:attrs["className"]=cls
            class_attr=f' class="{html.escape(cls,quote=True)}"' if cls else ""
            return f'<!-- wp:heading {json.dumps(attrs,separators=(",",":"))} --><{tag}{class_attr}>{body}</{tag}><!-- /wp:heading -->'
        if tag=="p":
            body="".join(self.serialize(c,base) for c in n.children).strip()
            if not body:return ""
            attrs=f' {json.dumps({"className":cls},separators=(",",":"))}' if cls else ""
            class_attr=f' class="{html.escape(cls,quote=True)}"' if cls else ""
            return f'<!-- wp:paragraph{attrs} --><p{class_attr}>{body}</p><!-- /wp:paragraph -->'
        if tag=="img":
            src=self.abs_asset(n.attrs.get("src",""),base); alt=html.escape(n.attrs.get("alt",""),quote=True)
            if not src:return ""
            return f'<!-- wp:image {{"sizeSlug":"full","linkDestination":"none"}} --><figure class="wp-block-image size-full"><img src="{html.escape(src,quote=True)}" alt="{alt}"/></figure><!-- /wp:image -->'
        if tag in {"ul","ol"}:
            raw=self.serialize(n,base); return f'<!-- wp:list {{"ordered":{str(tag=="ol").lower()}}} -->{raw}<!-- /wp:list -->'
        if tag=="blockquote":return f'<!-- wp:quote -->{self.serialize(n,base)}<!-- /wp:quote -->'
        if tag=="hr":return '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->'
        if tag=="a" and any(x in cls.lower() for x in ("btn","button","cta")):
            href=self.link(n.attrs.get("href","#")); label=html.escape(self.text(n).strip())
            return f'<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{html.escape(href,quote=True)}">{label}</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
        if tag in {"section","div","main","article","aside"}:
            inner="".join(self.block(c,base) for c in n.children)
            if not inner:return ""
            attrs={"layout":{"type":"default"}}
            if cls:attrs["className"]=cls
            c=f"wp-block-group {html.escape(cls,quote=True)}".strip()
            return f'<!-- wp:group {json.dumps(attrs,separators=(",",":"))} --><div class="{c}">{inner}</div><!-- /wp:group -->'
        if tag in {"form","table","iframe","video","audio","canvas","svg","details"}:
            return '<!-- wp:html -->'+self.serialize(n,base)+'<!-- /wp:html -->'
        inner="".join(self.block(c,base) for c in n.children)
        return inner if inner else ('<!-- wp:html -->'+self.serialize(n,base)+'<!-- /wp:html -->')
    def parse_doc(self,raw:str,base:str)->str:
        t=Tree();t.feed(raw)
        def find(n,tag):
            if n.tag==tag:return n
            for c in n.children:
                r=find(c,tag)
                if r:return r
        body=find(t.root,"main") or find(t.root,"body") or t.root
        for n in self.walk(t.root):
            if n.tag=="link" and "stylesheet" in n.attrs.get("rel","").lower():
                h=n.attrs.get("href","")
                if h and not re.match(r"^(?:https?:)?//",h):
                    self.css.append(safe_rel(str(pathlib.PurePosixPath(base).parent/pathlib.PurePosixPath(re.sub(r"[?#].*$","",h)))))
            elif n.tag=="script" and n.attrs.get("src"):
                s=n.attrs["src"]
                if not re.match(r"^(?:https?:)?//",s):
                    self.js.append(safe_rel(str(pathlib.PurePosixPath(base).parent/pathlib.PurePosixPath(re.sub(r"[?#].*$","",s)))))
            elif n.tag=="style" and self.text(n).strip():
                self.inline.append(self.text(n).strip())
        return "".join(self.block(c,base) for c in body.children if c.tag not in {"header","footer"})
    def walk(self,n):
        yield n
        for c in n.children: yield from self.walk(c)
    def page_html(self,p):
        f=str(p.get("file") or "")
        if f:
            rel=safe_rel(f); path=self.src/rel
            if not path.is_file():raise ValueError(f"Page file missing: {rel}")
            return path.read_text(encoding="utf-8",errors="replace"),rel
        if isinstance(p.get("html"),str):return p["html"],f"__inline__/{route_slug(p['path'])}.html"
        if isinstance(p.get("content"),str):return f"<main><p>{html.escape(p['content'])}</p></main>",f"__inline__/{route_slug(p['path'])}.html"
        return "<main></main>",f"__inline__/{route_slug(p['path'])}.html"
    def fragment(self,key):
        x=self.manifest.get(key)
        if isinstance(x,str): val=x
        elif isinstance(x,dict):
            if isinstance(x.get("html"),str):return x["html"],f"__inline__/{key}.html"
            val=str(x.get("file") or "")
        else:return "",f"__inline__/{key}.html"
        if not val:return "",f"__inline__/{key}.html"
        rel=safe_rel(val); p=self.src/rel
        return (p.read_text(encoding="utf-8",errors="replace") if p.is_file() else ""),rel
    def fragment_part(self,key):
        raw,base=self.fragment(key)
        if not raw:return ""
        t=Tree();t.feed(raw); rendered="".join(self.serialize(c,base) for c in t.root.children)
        return '<!-- wp:html -->'+rendered+'<!-- /wp:html -->\n'
    def copy_assets(self):
        dest=self.theme/"source"
        for d in ASSET_DIRS:
            root=self.src/d
            if not root.is_dir():continue
            for p in root.rglob("*"):
                if p.is_file() and p.suffix.lower() in ASSET_EXT:
                    rel=p.relative_to(self.src); target=dest/rel;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,target)
        for ref in set(self.css+self.js):
            p=self.src/ref
            if p.is_file() and p.suffix.lower() in ASSET_EXT:
                target=dest/ref;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,target)
    def style_css(self):
        return f'''/*
Theme Name: {self.name}
Description: Generated by PIN Structured Build Mode. Source remains canonical.
Version: 0.1.0
Requires at least: 6.6
Requires PHP: 8.1
Text Domain: {self.slug}
*/
'''
    def theme_json(self):
        colors=self.tokens.get("colors") if isinstance(self.tokens.get("colors"),dict) else {}
        palette=[{"slug":slugify(k),"name":k,"color":v} for k,v in colors.items() if isinstance(v,str) and re.fullmatch(r"#[0-9a-fA-F]{3,8}",v)]
        typ=self.tokens.get("typography") if isinstance(self.tokens.get("typography"),dict) else {}; lay=self.tokens.get("layout") if isinstance(self.tokens.get("layout"),dict) else {}
        body=typ.get("body") or typ.get("familyBody") or "system-ui,sans-serif"; content=lay.get("contentWidth") or "1200px"; wide=lay.get("wideWidth") or lay.get("wide") or "1540px"
        return {"$schema":"https://schemas.wp.org/trunk/theme.json","version":3,"settings":{"appearanceTools":True,"layout":{"contentSize":content,"wideSize":wide},"color":{"palette":palette},"typography":{"fontFamilies":[{"slug":"body","name":"Body","fontFamily":body}]}},"styles":{"typography":{"fontFamily":"var(--wp--preset--font-family--body)"}},"templateParts":[{"name":"header","title":"Site Header","area":"header"},{"name":"footer","title":"Site Footer","area":"footer"}]}
    def functions(self):
        css=sorted(set(x for x in self.css if (self.src/x).is_file())); js=sorted(set(x for x in self.js if (self.src/x).is_file()))
        css_php="\n".join(f"    wp_enqueue_style('pin-source-css-{i}', get_theme_file_uri('/source/{x}'), array(), filemtime(get_theme_file_path('/source/{x}')));" for i,x in enumerate(css))
        js_php="\n".join(f"    wp_enqueue_script('pin-source-js-{i}', get_theme_file_uri('/source/{x}'), array(), filemtime(get_theme_file_path('/source/{x}')), true);" for i,x in enumerate(js))
        inline="    wp_enqueue_style('pin-generated-inline', get_theme_file_uri('/assets/generated-inline.css'), array(), filemtime(get_theme_file_path('/assets/generated-inline.css')));" if self.inline else ""
        return f'''<?php
defined('ABSPATH') || exit;
add_action('after_setup_theme', function(){{add_theme_support('wp-block-styles');add_theme_support('editor-styles');add_theme_support('title-tag');add_theme_support('block-template-parts');add_theme_support('align-wide');}});
add_action('wp_enqueue_scripts', function(){{
{css_php}
{inline}
{js_php}
}});
'''
    def templates(self):
        h='<!-- wp:template-part {"slug":"header","tagName":"header"} /-->\n';m='<!-- wp:group {"tagName":"main","layout":{"type":"default"}} --><main class="wp-block-group"><!-- wp:post-content {"layout":{"type":"default"}} /--></main><!-- /wp:group -->\n';f='<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->\n'
        return {x:h+m+f for x in ("index","singular","page","front-page","home","archive","search","404")}
    def make_wxr(self,pages):
        wp="http://wordpress.org/export/1.2/";ct="http://purl.org/rss/1.0/modules/content/";ex="http://wordpress.org/export/1.2/excerpt/";dc="http://purl.org/dc/elements/1.1/"
        lines=['<?xml version="1.0" encoding="UTF-8"?>',f'<rss version="2.0" xmlns:wp="{wp}" xmlns:content="{ct}" xmlns:excerpt="{ex}" xmlns:dc="{dc}"><channel>',f'<title>{xml_escape(self.name)}</title>',f'<link>{xml_escape(self.source_url or "https://example.invalid")}</link>','<description>PIN Structured Build Mode deterministic WordPress export</description>','<wp:wxr_version>1.2</wp:wxr_version>']
        for p in pages:
            rid=p["path"]; pid=self.route_ids[rid]; parent=self.route_ids.get(p.get("parent_route") or "",0); body=p["blocks"].replace("]]>","]]]]><![CDATA[>"); title=str(p.get("title") or route_slug(rid).replace("-"," ").title()); status="publish" if str(p.get("status") or "publish")=="publish" else "draft"; seo=p.get("seo") if isinstance(p.get("seo"),dict) else {}
            lines += ['<item>',f'<title>{xml_escape(title)}</title>','<dc:creator>admin</dc:creator>',f'<wp:post_id>{pid}</wp:post_id>',f'<wp:post_name>{xml_escape(route_slug(rid))}</wp:post_name>',f'<wp:post_parent>{parent}</wp:post_parent>','<wp:post_type>page</wp:post_type>',f'<wp:status>{status}</wp:status>',f'<wp:menu_order>{int(p.get("order") or 0)}</wp:menu_order>',f'<content:encoded><![CDATA[{body}]]></content:encoded>']
            for k,mk in (("title","_pin_import_seo_title"),("description","_pin_import_seo_description")):
                if seo.get(k): lines += ['<wp:postmeta>',f'<wp:meta_key>{mk}</wp:meta_key>',f'<wp:meta_value><![CDATA[{str(seo[k]).replace("]]>","]]]]><![CDATA[>")}]]></wp:meta_value>','</wp:postmeta>']
            lines += ['<wp:postmeta><wp:meta_key>_pin_structured_source_path</wp:meta_key><wp:meta_value><![CDATA['+str(p.get("file") or rid)+']]></wp:meta_value></wp:postmeta>','</item>']
        lines += ['</channel></rss>','']; return "\n".join(lines)
    def build(self,wxr:pathlib.Path,zip_path:pathlib.Path):
        if self.theme.exists():shutil.rmtree(self.theme)
        self.theme.mkdir(parents=True); generated=[]
        for i,p in enumerate(self.pages):self.route_ids[p["path"]]=1000+i
        for p in self.pages:
            raw,base=self.page_html(p); blocks=self.parse_doc(raw,base); q=dict(p);q["file"]=base;q["blocks"]=blocks;q["parent_route"]=route_norm(str(p.get("parent"))) if p.get("parent") else route_parent(p["path"]);generated.append(q)
        self.copy_assets();(self.theme/"parts").mkdir();(self.theme/"templates").mkdir();(self.theme/"assets").mkdir(exist_ok=True)
        (self.theme/"style.css").write_text(self.style_css(),encoding="utf-8");(self.theme/"theme.json").write_text(json.dumps(self.theme_json(),indent=2)+"\n",encoding="utf-8");(self.theme/"functions.php").write_text(self.functions(),encoding="utf-8")
        (self.theme/"parts"/"header.html").write_text(self.fragment_part("header"),encoding="utf-8");(self.theme/"parts"/"footer.html").write_text(self.fragment_part("footer"),encoding="utf-8")
        for n,c in self.templates().items():(self.theme/"templates"/f"{n}.html").write_text(c,encoding="utf-8")
        if self.inline:(self.theme/"assets"/"generated-inline.css").write_text("\n\n".join(dict.fromkeys(self.inline))+"\n",encoding="utf-8")
        wxr.parent.mkdir(parents=True,exist_ok=True);wxr.write_text(self.make_wxr(generated),encoding="utf-8")
        zip_path.parent.mkdir(parents=True,exist_ok=True)
        with zipfile.ZipFile(zip_path,"w",zipfile.ZIP_DEFLATED) as z:
            for p in sorted(self.theme.rglob("*")):
                if p.is_file():z.write(p,pathlib.PurePosixPath(self.slug)/p.relative_to(self.theme))
        return {"site":self.name,"theme_slug":self.slug,"pages":len(generated),"assets":sum(1 for p in (self.theme/"source").rglob("*") if p.is_file()) if (self.theme/"source").exists() else 0,"stylesheets":len(set(self.css)),"scripts":len(set(self.js)),"theme_dir":str(self.theme),"wxr":str(wxr),"zip":str(zip_path)}

def main():
    ap=argparse.ArgumentParser();ap.add_argument("--source-root",required=True);ap.add_argument("--out-dir",default="build/structured-site");ap.add_argument("--wxr-path",default="");ap.add_argument("--zip-path",default="");ap.add_argument("--theme-slug",default="");a=ap.parse_args();src=pathlib.Path(a.source_root);out=pathlib.Path(a.out_dir)
    if not src.is_dir():print(f"Source root does not exist: {src}",file=sys.stderr);return 2
    try:
        b=Builder(src,out,a.theme_slug or None);wxr=pathlib.Path(a.wxr_path) if a.wxr_path else out/"website.xml";zp=pathlib.Path(a.zip_path) if a.zip_path else out/f"{b.slug}.zip";print(json.dumps(b.build(wxr,zp),indent=2));return 0
    except Exception as e:print(f"PIN structured build failed: {e}",file=sys.stderr);return 1
if __name__=="__main__":raise SystemExit(main())
