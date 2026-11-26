import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const browser=await chromium.launch({headless:true});
const out='build/forexy-parity/component-audit';fs.mkdirSync(out,{recursive:true});
const records=[], failures=[];
const check=(ok,msg)=>{if(!ok)failures.push(msg)};
const root='http://localhost:8888';
try{
 const page=await browser.newPage({reducedMotion:'reduce'});
 for(const width of [360,390,768,1024,1180,1440,1920]){
  await page.setViewportSize({width,height:900});
  await page.goto(root+'/',{waitUntil:'load'});
  await page.locator('main#main-content').waitFor();
  await page.evaluate(async()=>{await document.fonts.ready;});
  const about=await page.locator('.about-section').evaluate(e=>{
   const r=e.getBoundingClientRect(),c=e.querySelector('.contents_box')?.getBoundingClientRect(),t=e.querySelector('.title_box')?.getBoundingClientRect();
   return {height:r.height,contentBottom:c?.bottom??r.top,contentTop:t?.top??r.top,sectionBottom:r.bottom,sectionTop:r.top,decorative:[...e.querySelectorAll('.about-shadow-1,.about-shadow-2')].filter(x=>getComputedStyle(x).display!=='none').length};
  });
  check(about.decorative===0,'Decorative nodes remain visible '+width);
  check(about.sectionBottom-about.contentBottom<=200,'Excessive About trailing space '+width+' '+(about.sectionBottom-about.contentBottom));
  const layout=await page.evaluate(()=>{
   const bar=document.querySelector('.mgl2-topbar'),nav=document.querySelector('.mgl-primary-nav'),toggle=document.querySelector('.mgl-menu-toggle');
   return {utilityVisible:bar&&getComputedStyle(bar).display!=='none',navInert:nav?.inert,menuWidth:toggle?.getBoundingClientRect().width,menuHeight:toggle?.getBoundingClientRect().height,missingNames:[...document.querySelectorAll('a.mgl-nav-link[href],a.play-now-two[href],a.mgl-header-action[href]')].filter(a=>a.getClientRects().length&&!a.textContent.trim()&&!a.getAttribute('aria-label')&&!a.querySelector('img[alt]:not([alt=""])')).map(a=>a.href)};
  });
  check(!layout.missingNames.length,'Unnamed navigation links '+width);
  if(width<1200){
   check(!layout.utilityVisible,'Mobile utility bar visible '+width);check(layout.navInert,'Closed mobile nav not inert '+width);
   check(layout.menuWidth>=44&&layout.menuHeight>=44,'Mobile menu target too small '+width);
   const toggle=page.locator('.mgl-menu-toggle');await toggle.click();
   check(await toggle.getAttribute('aria-expanded')==='true','Mobile menu not expanded '+width);
   check(!(await page.locator('.mgl-primary-nav').evaluate(e=>e.inert)),'Open navigation inert '+width);
   await page.keyboard.press('Escape');
   check(await toggle.getAttribute('aria-expanded')==='false','Escape failed '+width);
   check(await toggle.evaluate(e=>document.activeElement===e),'Focus not restored '+width);
  }
  // Trigger lazy images by scrolling through the page, then wait for their
  // network/decode outcome. A hidden/inactive slide is checked when active.
  await page.evaluate(async()=>{
   for(const e of document.querySelectorAll('main img')){
    if(!e.getClientRects().length)continue;
    e.scrollIntoView({block:'center',behavior:'instant'});
    await new Promise(r=>setTimeout(r,20));
   }
  });
  const images=await page.evaluate(async()=>{
   const candidates=[...document.querySelectorAll('main img')].filter(e=>e.getClientRects().length&&e.getAttribute('src')&&!e.closest('[inert]'));
   await Promise.all(candidates.map(e=>Promise.race([e.decode().catch(()=>{}),new Promise(r=>setTimeout(r,4000))])));
   return {count:candidates.length,failed:candidates.filter(e=>!e.complete||!e.naturalWidth).map(e=>e.currentSrc||e.src),noDimensions:candidates.filter(e=>/\.(?:png|jpe?g)(?:[?#]|$)/i.test(e.currentSrc||e.src)&&(!e.getAttribute('width')||!e.getAttribute('height'))).map(e=>e.currentSrc||e.src)};
  });
  check(!images.failed.length,'Unloaded visible images '+width+' '+images.failed.join(','));
  check(!images.noDimensions.length,'Missing raster intrinsic dimensions '+width+' '+images.noDimensions.join(','));
  await page.goto(root+'/contact/',{waitUntil:'load'});
  const form=await page.locator('#mgl-enquiry-form').evaluate(f=>[...f.querySelectorAll('label input, label textarea,label select')].filter(e=>e.name!=='website').map(e=>({name:e.name,label:e.closest('label')?.innerText.trim(),height:e.getBoundingClientRect().height,visible:getComputedStyle(e.closest('label')).visibility==='visible'})));
  check(form.every(f=>f.label&&f.visible&&f.height>=44),'Missing visible label or target size '+width);
  await page.goto(root+'/about-us/',{waitUntil:'load'});
  const crumbs=await page.locator('.mgl-inner-breadcrumb').first().evaluate(e=>({tag:e.tagName,name:e.getAttribute('aria-label'),current:e.querySelector('[aria-current="page"]')?.textContent,size:parseFloat(getComputedStyle(e).fontSize)}));
  check(crumbs.tag==='NAV'&&crumbs.name==='Breadcrumb'&&crumbs.current&&crumbs.size>=14,'Breadcrumb contract '+width);
  records.push({width,about,layout,images,form,crumbs});
 }
}finally{fs.writeFileSync(out+'/results.json',JSON.stringify({records,failures},null,2));await browser.close();}
assert.equal(failures.length,0,failures.join('\n'));
console.log('PASS: seven-width About spacing, visible media decode, intrinsic dimensions, mobile disclosure, labels and breadcrumb checks.');
