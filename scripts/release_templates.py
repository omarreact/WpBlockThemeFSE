"""Optional release templates for source-preserving FSE themes."""
import json, pathlib, re, shutil

def comment(name, attrs=None):
    return '<!-- wp:'+name+(' '+json.dumps(attrs,separators=(',',':')) if attrs else '')+' /-->'

def layout(tag, cls, body):
    attrs=json.dumps({"tagName":tag,"sourceAttrs":{"class":cls}},separators=(',',':'))
    return '<!-- wp:pin/source-layout '+attrs+' -->'+body+'<!-- /wp:pin/source-layout -->'

def heading(text, level=2):
    return '<!-- wp:heading {"level":'+str(level)+'} --><h'+str(level)+' class="wp-block-heading">'+text+'</h'+str(level)+'><!-- /wp:heading -->'

def paragraph(text):
    return '<!-- wp:paragraph --><p>'+text+'</p><!-- /wp:paragraph -->'

def posts(inherit=False, related=False, limit=9):
    attrs={"queryId":22 if related else 21,"query":{"perPage":limit,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","inherit":inherit}}
    if related: attrs["namespace"]="pin/related"
    cards=comment("post-featured-image",{"isLink":True,"aspectRatio":"16/9"})+comment("post-date")+comment("post-title",{"isLink":True,"level":3})+comment("post-excerpt",{"moreText":"Read article"})
    content='<!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->'+layout("article","pin-post-card",cards)+'<!-- /wp:post-template -->'
    if not related:
        content+='<!-- wp:query-pagination -->'+comment("query-pagination-previous")+comment("query-pagination-numbers")+comment("query-pagination-next")+'<!-- /wp:query-pagination -->'
    content+='<!-- wp:query-no-results -->'+paragraph("No articles found.")+'<!-- /wp:query-no-results -->'
    return '<!-- wp:query '+json.dumps(attrs,separators=(',',':'))+' --><div class="wp-block-query">'+content+'</div><!-- /wp:query -->'

def install_release(theme, profile, name, wrapper):
    theme=pathlib.Path(theme)
    files=pathlib.Path(__file__).parent/"release"
    for fn in ("release-runtime.php","release.css","setup.css"):
        shutil.copyfile(files/fn,theme/fn)
    if profile.get("qualityProfile") == "forexy":
        shutil.copyfile(files/"forexy-quality.php",theme/"forexy-quality.php")
        with (theme/"release.css").open("a",encoding="utf-8") as handle:
            handle.write((files/"forexy-quality.css").read_text(encoding="utf-8"))
        runtime=theme/"source"/"theme-2"/"runtime.js"
        js=runtime.read_text(encoding="utf-8")
        marker="    enquiryForm.addEventListener('submit', function (event) {\n        event.preventDefault();"
        if js.count(marker)!=1: raise ValueError("Forexy contact runtime contract changed")
        js=js.replace(marker,(files/"forexy-contact-submit.js").read_text(encoding="utf-8").rstrip(),1)
        runtime.write_text(js,encoding="utf-8")
    header=comment("template-part",{"slug":"header"})
    footer=comment("template-part",{"slug":"footer"})
    def chrome(main):
        return header+layout("div",wrapper or "pin-page-wrapper",main+footer)
    title=comment("post-title",{"level":1})
    meta=layout("div","pin-post-meta",comment("post-author-name")+comment("post-date")+comment("post-terms",{"term":"category"}))
    sidebar=layout("aside","pin-blog-sidebar",heading("Search articles")+comment("search",{"label":"Search articles","showLabel":False,"placeholder":"Search…","buttonText":"Search"})+heading("Categories")+comment("categories")+heading("Recent posts")+comment("latest-posts",{"postsToShow":5,"displayPostDate":True}))
    (theme/"parts"/"sidebar.html").write_text(sidebar,encoding="utf-8")
    content=layout("article","pin-blog-article",comment("post-featured-image",{"aspectRatio":"16/9"})+comment("post-content",{"layout":{"type":"default"}})+layout("div","pin-post-tags",comment("post-terms",{"term":"post_tag","prefix":"Tags: " }))+layout("div","pin-post-navigation",comment("post-navigation-link",{"type":"previous","showTitle":True})+comment("post-navigation-link",{"type":"next","showTitle":True}))+'<!-- wp:comments --><div class="wp-block-comments">'+comment("comments-title")+'<!-- wp:comment-template -->'+comment("comment-author-name")+comment("comment-date")+comment("comment-content")+comment("comment-reply-link")+'<!-- /wp:comment-template -->'+comment("comments-pagination")+comment("post-comments-form")+'</div><!-- /wp:comments -->')
    single=layout("main","pin-editorial pin-single",layout("div","pin-blog-banner",title+meta)+layout("div","pin-blog-grid",content+comment("template-part",{"slug":"sidebar"}))+layout("section","pin-related",heading("Related articles")+posts(related=True,limit=3)))
    (theme/"templates"/"single.html").write_text(chrome(single),encoding="utf-8")
    for key in ("home","index","archive","category","tag","author","search"):
        label=heading("Brokerage insights",1) if key in ("home","index") else comment("query-title",{"type":"search" if key=="search" else "archive"})
        search=comment("search",{"label":"Search articles","placeholder":"Search articles…","buttonText":"Search","showLabel":False})
        main=layout("main","pin-editorial pin-archive",layout("div","pin-blog-banner",label+search)+layout("section","pin-blog-list",posts(inherit=True)))
        (theme/"templates"/(key+".html")).write_text(chrome(main),encoding="utf-8")
    insight=profile.get("blogIndex")
    if insight:
        if not re.fullmatch(r"[a-z0-9-]+",insight): raise ValueError("Invalid blog route")
        (theme/"templates"/("page-"+insight+".html")).write_text(chrome(comment("post-content",{"layout":{"type":"default"}})+layout("section","pin-editorial pin-blog-list",heading("Latest articles")+posts())),encoding="utf-8")
    contact=profile.get("contactPage")
    if contact:
        if not re.fullmatch(r"[a-z0-9-]+",contact): raise ValueError("Invalid contact route")
        form='<!-- wp:shortcode -->[pin_release_contact]<!-- /wp:shortcode -->'
        (theme/"templates"/("page-"+contact+".html")).write_text(chrome(comment("post-content",{"layout":{"type":"default"}})+layout("section","pin-editorial pin-contact",form)),encoding="utf-8")
    notfound=layout("main","pin-editorial pin-not-found",heading("Page not found",1)+paragraph("The page you requested may have moved. Search our website or return to the homepage.")+comment("search",{"label":"Search","buttonText":"Search"})+comment("home-link",{"label":"Return home"}))
    # core/home-link is intended inside navigation. Use a source link instead.
    notfound=notfound.replace(comment("home-link",{"label":"Return home"}),'<!-- wp:pin/source-element {"tagName":"a","sourceAttrs":{"href":"/__pin_route__/","class":"pin-back-home"},"content":"Return home"} /-->')
    (theme/"templates"/"404.html").write_text(chrome(notfound),encoding="utf-8")
    tj=json.loads((theme/"theme.json").read_text())
    tj.setdefault("templateParts",[]).append({"name":"sidebar","title":"Blog sidebar","area":"uncategorized"})
    (theme/"theme.json").write_text(json.dumps(tj,indent=2)+"\n",encoding="utf-8")
    (theme/"README.txt").write_text(name+"\nVersion "+profile["version"]+"\n\nWordPress 6.6+, PHP 8.1+.\nInstall on staging first. Back up database, uploads, and existing theme customizations. Activate, open Website setup, and import. For an upgrade select the explicitly marked migration option; compatible pages retain their edited content. Prior theme-specific Site Editor templates are not copied automatically; review and export them before switching. Existing editorial pages remain intact; publish new articles as Posts. The native Insights query lists posts. Contact delivery requires a verified recipient and working host mail transport. Test before enabling. WordPress core provides sitemaps; SEO plugins take precedence over theme metadata.\n\nLocal assets are retained at original filenames. Source code and media rights must be verified before redistribution; no new third-party licenses are granted by this package.\n",encoding="utf-8")
