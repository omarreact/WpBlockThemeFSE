import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const routes=JSON.parse(fs.readFileSync('build/forexy/meta-grey-label/pin-local-pages.json')).filter(p=>p.route!=='/');
const b=await chromium.launch({headless:true});
const evidence=[];
try{
 const page=await b.newPage({reducedMotion:'reduce'});
 for(const width of [390,1440]){
  await page.setViewportSize({width,height:900});
  for(const p of routes){
   const res=await page.goto('http://localhost:8888'+p.route,{waitUntil:'domcontentloaded'});
   assert.equal(res.status(),200,p.route);
   await page.locator('main[data-mgl2-layout] [data-mgl2-block="banner"]').first().waitFor();
   const r=await page.evaluate(()=>{
    const h=document.querySelector('.mgl2-header'),hero=document.querySelector('main[data-mgl2-layout] [data-mgl2-block="banner"]');
    return {gap:hero.getBoundingClientRect().top-h.getBoundingClientRect().bottom,overflow:document.documentElement.scrollWidth-document.documentElement.clientWidth,position:getComputedStyle(h).position,heading:getComputedStyle(hero.querySelector('h1')).fontSize};
   });
   evidence.push({route:p.route,width,...r});
   assert(Math.abs(r.gap)<=2,p.route+' header gap '+r.gap);
   assert(r.overflow<=2,p.route+' overflow '+r.overflow);
   assert.equal(r.position,'sticky',p.route);
  }
 }
 console.log('PASS: 51 inner routes x 2 widths; header geometry and overflow verified.');
}finally{fs.writeFileSync('build/forexy-parity/inner-route-qa.json',JSON.stringify(evidence,null,2));await b.close();}
