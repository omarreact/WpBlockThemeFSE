import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const origin='http://localhost:8888',out='build/forexy-parity/editor-parity';
fs.mkdirSync(out,{recursive:true});
const browser=await chromium.launch({headless:true});
const evidence=[];
try{
 const page=await browser.newPage({viewport:{width:1600,height:1000},reducedMotion:'reduce'});
 await page.goto(origin+'/wp-login.php');
 await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill('password');
 await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
 for(const route of ['/','/about-us/','/mt5-grey-label/','/contact/','/insights/']){
  await page.goto(origin+route,{waitUntil:'load'});
  const edit=page.locator('#wp-admin-bar-edit a');await edit.waitFor();
  const editUrl=await edit.getAttribute('href');
  const live=await page.locator('main#main-content').evaluate(main=>{
   const h=main.querySelector('h1'),s=getComputedStyle(h);
   return {text:h.textContent.trim(),color:s.color,font:s.fontFamily,headingSize:s.fontSize,background:getComputedStyle(document.body).backgroundColor,layout:main.getAttribute('data-mgl2-layout')};
  });
  await page.goto(editUrl);
  await page.waitForFunction(()=>wp.data.select('core/block-editor')?.getBlocks()?.some(b=>b.name==='pin/source-layout'),{},{timeout:60000});
  const frame=await (async()=>{for(let i=0;i<100;i++){const f=page.frames().find(f=>f!==page.mainFrame()&&f.url()!=='about:blank'&&f.name()==='editor-canvas');if(f)return f;const g=page.frames().find(f=>f!==page.mainFrame()&&f.name()==='editor-canvas');if(g)return g;await page.waitForTimeout(200);}throw Error('Editor canvas not found');})();
  try {
   await frame.waitForFunction(()=>document.body.classList.contains('pin-editor-canvas')&&!!document.querySelector('main#main-content'),{},{timeout:60000});
  } catch(error) {
   const diagnostics=await frame.evaluate(()=>({body:document.body?.className,html:document.documentElement.outerHTML.slice(0,500),main:[...document.querySelectorAll('main')].map(e=>({id:e.id,class:e.className})),wrappers:[...document.querySelectorAll('.editor-styles-wrapper,.is-root-container')].map(e=>({tag:e.tagName,class:e.className})),sourceBlocks:document.querySelectorAll('.wp-block-pin-source-layout').length,styles:[...document.styleSheets].map(s=>s.href).filter(Boolean)})).catch(e=>({error:String(e)}));
   evidence.push({route,error:String(error),diagnostics,profile:await page.evaluate(()=>window.pinSourceCanvasProfile||null)});
   await page.screenshot({path:out+'/failed-editor.png',animations:'disabled'}).catch(()=>{});
   throw error;
  }
  await frame.evaluate(async()=>{await document.fonts.ready;});
  const editor=await frame.evaluate(()=>{
   const main=document.querySelector('main#main-content'),h=main.querySelector('h1'),s=getComputedStyle(h),r=main.getBoundingClientRect();
   const imageBlocks=[...main.querySelectorAll('.wp-block-pin-source-element')].filter(e=>e.tagName==='IMG'||e.querySelector('img'));
   return {text:h.textContent.trim(),color:s.color,font:s.fontFamily,headingSize:s.fontSize,theme:document.documentElement.dataset.bsTheme,body:document.body.className,layout:main.getAttribute('data-mgl2-layout'),width:r.width,canvas:document.documentElement.clientWidth,images:imageBlocks.length,wrappedImages:imageBlocks.filter(e=>e.tagName!=='IMG').length,sourceLinks:[...main.querySelectorAll('a.wp-block-pin-source-element,a.wp-block-pin-source-layout')].length,visible:getComputedStyle(h).visibility,opacity:s.opacity,wrapper:!!main.parentElement.closest('.page-wrapper')};
  });
  evidence.push({route,live,editor});
  assert.equal(editor.theme,'dark',route+' editor theme context');assert.equal(editor.layout,live.layout,route+' source attributes');
  assert.equal(editor.text,live.text,route+' primary heading');assert.equal(editor.color,live.color,route+' heading foreground');assert.equal(editor.font,live.font,route+' font family');
  assert(editor.wrapper,route+' page wrapper missing');assert(editor.width>=editor.canvas*.88,route+' editor constrained width '+JSON.stringify(editor));
  assert.equal(editor.wrappedImages,0,route+' extra image wrappers');assert.equal(editor.visible,'visible',route+' hidden heading');assert.equal(editor.opacity,'1',route+' hidden heading opacity');
  await page.screenshot({path:out+'/'+(route==='/'?'home':route.split('/')[1])+'-page-editor.png',animations:'disabled'});
 }
 // The site editor must also apply document context to an editable template.
 await page.goto(origin+'/wp-admin/site-editor.php?postType=wp_template&postId=meta-grey-label%2F%2Ffront-page&canvas=edit');
 await page.waitForFunction(()=>[...document.querySelectorAll('iframe')].some(f=>{try{return f.contentDocument?.body?.classList.contains('pin-editor-canvas')}catch{return false}}),{},{timeout:90000});
 const sf=page.frames().find(f=>f.name()==='editor-canvas');
 assert(sf,'Site Editor iframe not available');
 const site=await sf.evaluate(()=>({theme:document.documentElement.dataset.bsTheme,header:!!document.querySelector('.mgl2-header'),wrapper:!!document.querySelector('.page-wrapper')}));
 assert(site.theme==='dark'&&site.header&&site.wrapper,'Site Editor source context incomplete '+JSON.stringify(site));
 evidence.push({route:'site-editor/front-page',site});await page.screenshot({path:out+'/site-editor.png',animations:'disabled'});
 console.log('PASS: real Gutenberg Page Editor source tag, theme, heading, width and image parity on 5 routes; Site Editor front-page context.');
}finally{fs.writeFileSync(out+'/results.json',JSON.stringify(evidence,null,2));await browser.close();}
