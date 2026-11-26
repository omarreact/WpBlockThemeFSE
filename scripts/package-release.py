#!/usr/bin/env python3
"""Optimize PNGs without changing pixels/filenames; package a verified screenshot."""
import argparse,io,json,pathlib,zipfile
from PIL import Image
def optimize(theme):
    report={"png_optimized":0,"bytes_saved":0}
    for p in sorted((theme/"source").rglob("*.png")):
        original=p.read_bytes()
        with Image.open(io.BytesIO(original)) as im:
            if getattr(im,"n_frames",1)!=1: continue
            info=im.info
            kwargs={k:info[k] for k in ("icc_profile","dpi","transparency") if k in info}
            dest=io.BytesIO()
            try: im.save(dest,format="PNG",optimize=True,**kwargs)
            except (ValueError,TypeError,OSError): continue
            new=dest.getvalue()
            if len(new)>=len(original): continue
            with Image.open(io.BytesIO(new)) as check:
                if check.size!=im.size or check.convert("RGBA").tobytes()!=im.convert("RGBA").tobytes(): continue
            p.write_bytes(new)
            report["png_optimized"]+=1;report["bytes_saved"]+=len(original)-len(new)
    (theme/"optimization-report.json").write_text(json.dumps(report,indent=2)+"\n")
    print(json.dumps(report))
def package(theme,output):
    with Image.open(theme/"screenshot.png") as im:
        assert im.size==(1200,900),"Expected actual 1200x900 screenshot"
    with zipfile.ZipFile(output,"w",compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for f in sorted(theme.rglob("*")):
            if f.is_file():
                info=zipfile.ZipInfo(theme.name+"/"+f.relative_to(theme).as_posix(),date_time=(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.create_system=3;info.external_attr=0o100644<<16
                z.writestr(info,f.read_bytes(),compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    with zipfile.ZipFile(output) as z: assert z.testzip() is None
if __name__=="__main__":
    p=argparse.ArgumentParser();p.add_argument("theme",type=pathlib.Path);p.add_argument("--optimize",action="store_true");p.add_argument("--package",type=pathlib.Path);a=p.parse_args()
    if a.optimize: optimize(a.theme)
    if a.package: package(a.theme,a.package)
