import {chromium} from 'playwright';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true});
const results=[];
const routes=[['home',''],['about-us','about-us/'],['mt5-grey-label','mt5-grey-label/']];
fs.mkdirSync('build/forexy-parity',{recursive:true});
try{
 for(const [slug,route] of routes)for(const width of [390,1440]){
  const src=await browser.newPage({viewport:{width,height:900},reducedMotion:'reduce'});
  const wp=await browser.newPage({viewport:{width,height:900},reducedMotion:'reduce'});
  const errors=[];wp.on('pageerror',e=>errors.push(e.message));
  await Promise.all([src.goto('http://127.0.0.1:4174/theme-2/?theme=dark'+(slug==='home'?'':'&page='+slug)),wp.goto('http://127.0.0.1:8888/'+route)]);
  await Promise.all([src.locator('main#main-content').waitFor(),wp.locator('main#main-content').waitFor()]);
  for(const p of [src,wp]){await p.evaluate(async()=>{await document.fonts.ready;await Promise.all(Array.from(document.images).filter(x=>x.complete).map(x=>x.decode().catch(()=>{})));});}
  const measure=p=>p.evaluate(()=>{const m=document.querySelector('main#main-content'),r=m.getBoundingClientRect();return {x:r.x,width:r.width,top:r.top,sections:[...m.querySelectorAll(':scope > section')].map(x=>x.className),header:!!document.querySelector('.mgl2-header'),footer:!!document.querySelector('.main-footer'),horizontalOverflow:document.documentElement.scrollWidth-document.documentElement.clientWidth}});
  const a=await measure(src),b=await measure(wp);
  const accessibility=await wp.evaluate(()=>{
    const visible=e=>e.getClientRects().length>0&&getComputedStyle(e).visibility!=='hidden';
    const name=e=>(e.getAttribute('aria-label')||e.getAttribute('title')||e.textContent||'').trim()||[...e.querySelectorAll('img[alt]')].map(x=>x.alt).join(' ').trim();
    const ids=[...document.querySelectorAll('[id]')].map(e=>e.id);
    return {imagesWithoutAlt:[...document.images].filter(e=>!e.hasAttribute('alt')).map(e=>e.getAttribute('src')),unnamedVisibleButtons:[...document.querySelectorAll('button')].filter(e=>visible(e)&&!name(e)).map(e=>e.className),unnamedVisibleLinks:[...document.querySelectorAll('a[href]')].filter(e=>visible(e)&&!name(e)).map(e=>e.getAttribute('href')),duplicateIds:[...new Set(ids.filter((id,i)=>ids.indexOf(id)!==i))]};
  });
  results.push({slug,width,source:a,wordpress:b,errors,accessibility});
  assert.equal(b.header,true,'Missing source header');assert.equal(b.footer,true,'Missing source footer');
  assert.deepEqual(b.sections,a.sections,'Section classes/order differ '+slug);
  assert(Math.abs(a.width-b.width)<=2,'Main width differs '+slug+' '+width);
  assert(b.horizontalOverflow<=2,'WordPress horizontal overflow '+slug+' '+width);
  assert(!errors.length,'WordPress JS errors '+errors.join('; '));
  await src.screenshot({path:'build/forexy-parity/'+slug+'-'+width+'-source.png',fullPage:true,animations:'disabled'});
  await wp.screenshot({path:'build/forexy-parity/'+slug+'-'+width+'-wordpress.png',fullPage:true,animations:'disabled'});
  await src.close();await wp.close();
 }
}finally{fs.writeFileSync('build/forexy-parity/geometry.json',JSON.stringify(results,null,2));await browser.close();}
console.log('PASS: representative source/WordPress geometry, section order and JS checks.');
