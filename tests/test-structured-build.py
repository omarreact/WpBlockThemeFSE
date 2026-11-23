#!/usr/bin/env python3
from __future__ import annotations
import json, pathlib, subprocess, sys, tempfile, xml.etree.ElementTree as ET, zipfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
GENERATOR=ROOT/"scripts"/"build-structured-site.py"
def write(path:pathlib.Path,content:str)->None:
    path.parent.mkdir(parents=True,exist_ok=True);path.write_text(content,encoding="utf-8")
with tempfile.TemporaryDirectory(prefix="pin-structured-test-") as temp:
    base=pathlib.Path(temp);source=base/"source";output=base/"output";source.mkdir()
    write(source/"site-manifest.json",json.dumps({
        "site":{"name":"Structured Build Fixture","sourceUrl":"https://example.com","repository":"https://github.com/example/site"},
        "header":{"html":"<header><a href='/'><img src='/assets/logo.svg' alt='Fixture'></a></header>"},
        "footer":{"html":"<footer><p>Fixture Footer</p></footer>"},
        "pages":[
            {"path":"/","file":"index.html","title":"Home","status":"publish","order":0},
            {"path":"/about/","file":"about/index.html","title":"About","status":"publish","order":1}
        ]},indent=2))
    write(source/"design-tokens.json",json.dumps({
        "colors":{"primary":"#00b98e","text":"#111827"},
        "typography":{"body":"Inter, system-ui, sans-serif"},
        "layout":{"contentWidth":"1200px","wideWidth":"1540px"}},indent=2))
    write(source/"index.html","""<!doctype html><html><head><link rel='stylesheet' href='assets/site.css'><style>.critical{display:block}</style></head><body><header>source header</header><main><section class='hero'><h1>Hello</h1><p>Welcome <strong>home</strong>.</p><img src='/assets/logo.svg' alt='Logo'><a class='button' href='/about/'>About</a></section></main><footer>source footer</footer><script src='assets/site.js'></script></body></html>""")
    write(source/"about/index.html","<!doctype html><html><body><main><h1>About</h1><p>About page.</p></main></body></html>")
    write(source/"assets/site.css","body{background:url('logo.svg')}\n")
    write(source/"assets/site.js","console.log('fixture');\n")
    write(source/"assets/logo.svg","<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 10'><circle cx='5' cy='5' r='4'/></svg>\n")
    completed=subprocess.run([sys.executable,str(GENERATOR),"--source-root",str(source),"--out-dir",str(output)],check=True,text=True,capture_output=True)
    result=json.loads(completed.stdout);assert result["pages"]==2
    theme_slug=result["theme_slug"];theme_dir=output/theme_slug
    assert (theme_dir/"style.css").is_file()
    assert (theme_dir/"theme.json").is_file()
    assert (theme_dir/"parts/header.html").is_file()
    assert (theme_dir/"source/assets/logo.svg").is_file()
    assert (theme_dir/"assets/generated-inline.css").is_file()
    items=ET.parse(output/"website.xml").getroot().findall("./channel/item");assert len(items)==2
    wxr=(output/"website.xml").read_text(encoding="utf-8")
    assert "<!-- wp:heading" in wxr and "<!-- wp:image" in wxr
    assert f"/wp-content/themes/{theme_slug}/source/assets/logo.svg" in wxr
    assert "pin-site-importer/pin-site-importer" not in wxr
    zip_file=output/f"{theme_slug}.zip"
    with zipfile.ZipFile(zip_file) as archive:
        names=set(archive.namelist())
        assert f"{theme_slug}/style.css" in names
        assert f"{theme_slug}/source/assets/site.css" in names
        assert f"{theme_slug}/source/assets/site.js" in names
    first_zip=zip_file.read_bytes()
    first_wxr=(output/"website.xml").read_bytes()
    completed_again=subprocess.run([sys.executable,str(GENERATOR),"--source-root",str(source),"--out-dir",str(output)],check=True,text=True,capture_output=True)
    result_again=json.loads(completed_again.stdout)
    assert result_again["pages"]==2
    assert first_zip==zip_file.read_bytes(), "Theme ZIP changed across identical builds"
    assert first_wxr==(output/"website.xml").read_bytes(), "WXR changed across identical builds"
print("PASS: structured build generator emits byte-stable FSE theme + WXR")
