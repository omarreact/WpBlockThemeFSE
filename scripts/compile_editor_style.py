"""Compile source styles for the documented WordPress add_editor_style API.

The editor's canvas is not the public HTML document: core owns its body, IDs,
and React selection wrappers. Normalize only selectors; retain declarations,
cascade order, font URLs and media queries. No runtime DOM surgery.
"""
import json, pathlib, posixpath, re
from urllib.parse import urlsplit
import tinycss2

GROUPS={"media","supports","container","layer","document","starting-style"}
DARK=re.compile(r'\[data-bs-theme\s*=\s*(?:"dark"|\'dark\'|dark)\s*\]')
LIGHT=re.compile(r'\[data-bs-theme\s*=\s*(?:"light"|\'light\'|light)\s*\]')
THEME=re.compile(r'\[data-mgl-theme\s*=\s*(?:"theme-2"|\'theme-2\'|theme-2)\s*\]')
INNER=":has(main[data-mgl2-layout])"

def selector_list(tokens):
    group=[]
    for token in tokens:
        if token.type=="literal" and token.value==",":
            if group: yield group
            group=[]
        else: group.append(token)
    if group: yield group

def identifiers(tokens):
    # Replace only ID selectors, never hashes inside strings or declarations.
    for t in tokens:
        if t.type=="hash" and t.is_identifier:
            val=t.value.replace("\\","\\\\").replace('"','\\"')
            yield ':is('+t.serialize()+',[data-pin-source-id="'+val+'"])'
        elif t.type=="function":
            yield t.name+"("+"".join(identifiers(t.arguments))+")"
        else: yield t.serialize()

def normalize(tokens):
    s="".join(identifiers(tokens)).strip()
    if not s or LIGHT.search(s): return None
    # Remove document-only mode switches; the standalone theme is dark.
    s=re.sub(r'(?<![\w.-])html(?=\s|[.#:\[]|$)',"body",s)
    s=s.replace(":root","body")
    s=DARK.sub("",s);s=THEME.sub("",s)
    s=s.replace(".home2-shared-header","")
    s=s.replace(".design2-inner",INNER)
    s=re.sub(r"\bbody\s+body\b","body",s)
    s=re.sub(r"\bbody\s+body\b","body",s)
    # A bare [data-bs-theme=dark] rule becomes the canvas root.
    s=s.strip() or "body"
    # Styles targeted at frontend chrome state must not activate editor chrome.
    if any(k in s for k in ("mgl-settings-open","mgl-nav-open","fancybox-active","fancybox-iosfix","admin-bar","pin-editor-canvas")): return None
    s=s.replace("body.pin-source-edge","body")
    # WordPress's transformStyles recognizes body and scopes all remaining selectors.
    return s

def rewrite_urls(text, source, config):
    prefix=config["asset_prefix"]
    def sub(m):
        ref=m[2].strip()
        if not ref or ref.startswith(("data:","#","//","https:","http:")): return m[0]
        if ref.startswith(prefix): dest="source/"+ref[len(prefix):]
        elif ref.startswith("__PIN_THEME__/"): dest=ref[len("__PIN_THEME__/"):]
        elif ref.startswith("/"): return m[0]
        else: dest=posixpath.normpath(posixpath.join(posixpath.dirname(source),ref))
        return 'url("'+dest+'")'
    return re.sub(r"url\(\s*(['\"]?)([^)'\" \t\r\n]+)\1\s*\)",sub,text,flags=re.I)

def transform(rules):
    out=[]
    for rule in rules:
        if rule.type=="qualified-rule":
            sels=[s for group in selector_list(rule.prelude) if (s:=normalize(group))]
            if sels: out.append(",".join(sels)+"{"+tinycss2.serialize(rule.content)+"}")
        elif rule.type=="at-rule":
            name=rule.lower_at_keyword
            if name=="charset": continue
            if name=="import": raise ValueError("Editor CSS imports must be resolved at build time")
            if rule.content is not None and name in GROUPS:
                nested=transform(tinycss2.parse_rule_list(rule.content,skip_comments=False,skip_whitespace=True))
                if nested: out.append("@"+rule.at_keyword+tinycss2.serialize(rule.prelude)+"{"+nested+"}")
            else: out.append(rule.serialize())
        elif rule.type=="comment": out.append(rule.serialize())
        elif rule.type=="error": raise ValueError("Invalid source CSS: "+rule.message)
    return "\n".join(out)

def compile_editor(theme):
    config=json.loads((theme/"pin-build.json").read_text())
    entries=config["pages"]["/"]["css"]+config["shared"]["css"]
    paths=[]; chunks=[]
    for entry in entries:
        url=entry["url"]
        if url.startswith(config["asset_prefix"]): path="source/"+url[len(config["asset_prefix"]):]
        elif url.startswith("__PIN_THEME__/"): path=url[len("__PIN_THEME__/"):]
        else: raise ValueError("Unbundled editor stylesheet: "+url)
        if path not in paths: paths.append(path)
    paths.extend(["source-layout.css","release.css"])
    for path in paths:
        raw=(theme/path).read_text(encoding="utf-8")
        css=transform(tinycss2.parse_stylesheet(raw,skip_comments=False,skip_whitespace=True))
        chunks.append(rewrite_urls(css,path,config))
    # Core's add_editor_style scopes these rules to the editing canvas.
    extra=(pathlib.Path(__file__).parent/"release"/"forexy-editor.css").read_text()
    (theme/"editor-style.css").write_text("/* Generated from the same source CSS. WordPress scopes the canvas. */\n"+"\n".join(chunks)+"\n"+extra,encoding="utf-8")
