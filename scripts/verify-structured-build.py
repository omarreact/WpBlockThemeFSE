#!/usr/bin/env python3
from __future__ import annotations
import argparse, pathlib, sys, xml.etree.ElementTree as ET, zipfile
def main()->int:
    ap=argparse.ArgumentParser();ap.add_argument("out_dir");ap.add_argument("--expected-pages",type=int,required=True);ap.add_argument("--expected-theme",required=True);a=ap.parse_args()
    out=pathlib.Path(a.out_dir);wxr=out/"website.xml";theme=out/a.expected_theme;archive=out/f"{a.expected_theme}.zip"
    if not wxr.is_file() or not theme.is_dir() or not archive.is_file():raise AssertionError("Structured Build artifact is incomplete")
    items=ET.parse(wxr).getroot().findall("./channel/item");assert len(items)==a.expected_pages,f"expected {a.expected_pages} pages, got {len(items)}"
    text=wxr.read_text(encoding="utf-8");assert "<!-- wp:" in text;assert "pin-site-importer/" not in text
    assert f"/wp-content/themes/{a.expected_theme}/source/assets/" in text
    for p in ("style.css","theme.json","functions.php","parts/header.html","parts/footer.html","templates/index.html"):assert (theme/p).is_file(),p
    with zipfile.ZipFile(archive) as z:assert f"{a.expected_theme}/style.css" in set(z.namelist())
    print(f"PASS: {a.expected_theme} contains {len(items)} WXR pages with theme-owned assets");return 0
if __name__=="__main__":raise SystemExit(main())
