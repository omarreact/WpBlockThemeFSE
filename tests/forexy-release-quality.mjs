import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const root='http://localhost:8888', widths=[360,390,768,1024,1180,1440,1920];
const routes=JSON.parse(fs.readFileSync('build/forexy/meta-grey-label/pin-local-pages.json'));
const out='build/forexy-parity/full-ui';fs.mkdirSync(out,{recursive:true});
const browser=await chromium.launch({headless:true});
const records=[],failures=[],cardStates=[];
const rgb=s=>{const m=s.match(/[\d.]+/g);return m?.slice(0,3).map(Number)};
const lum=c=>c.map(x=>{x/=255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4}).reduce((a,x,i)=>a+x*[.2126,.7152,.0722][i],0);
const contrast=(a,b)=>(Math.max(lum(a),lum(b))+.05)/(Math.min(lum(a),lum(b))+.05);
function require(ok,message){if(!ok)failures.push(message);}
try{
 const p=await browser.newPage({viewport:{width:1440,height:900},reducedMotion:'reduce'});
 let errors=[];p.on('pageerror',e=>errors.push(String(e)));p.on('response',r=>{if(r.status()>=400&&r.url().startsWith(root+'/wp-content/themes/'))errors.push('Asset '+r.status()+' '+r.url());});
 for(const route of routes){
  for(const width of widths){
   errors=[];await p.setViewportSize({width,height:900});
   const url=root+route.route;
   const res=await p.goto(url,{waitUntil:'load',timeout:45000});
   await p.locator('main#main-content').waitFor();
   await p.evaluate(async()=>{await document.fonts.ready;});
   const info=await p.evaluate(()=>{
    const main=document.querySelector('main#main-content'),hero=main.querySelector('[data-mgl2-block="banner"]'),header=document.querySelector('.mgl2-header');
    const visible=e=>e.getClientRects().length>0&&getComputedStyle(e).visibility==='visible'&&getComputedStyle(e).display!=='none';
    const all=[...main.querySelectorAll('a,button,input,select,textarea,h1,h2,h3,p,li,span')].filter(visible);
    const color=s=>{const m=s.match(/[\d.]+/g);return m?.length>=3?m.map(Number):null};
    const lum=c=>c.slice(0,3).map(x=>{x/=255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4}).reduce((a,x,i)=>a+x*[.2126,.7152,.0722][i],0);
    const candidates=[];
    for(const e of all){
     const text=[...e.childNodes].filter(x=>x.nodeType===3).map(x=>x.textContent.trim()).join(' ');
     if(!text)continue;
     const s=getComputedStyle(e),fg=color(s.color);if(!fg||fg[3]!==undefined&&fg[3]!==1)continue;
     let bg=null,skip=false;
     for(let a=e;a;a=a.parentElement){const q=getComputedStyle(a),b=color(q.backgroundColor);if(q.backgroundImage!=='none'||Number(q.opacity)<1||q.mixBlendMode!=='normal'){skip=true;break;}if(b&&b[3]===undefined||b&&b[3]===1){bg=b;break;}if(b&&b[3]>0){skip=true;break;}}
     if(skip||!bg)continue;
     const ratio=(Math.max(lum(fg),lum(bg))+.05)/(Math.min(lum(fg),lum(bg))+.05);
     const min=parseFloat(s.fontSize)>=24||parseFloat(s.fontSize)>=18.66&&Number(s.fontWeight)>=700?3:4.5;
     if(ratio<min)candidates.push({tag:e.tagName,text:text.slice(0,90),ratio:Math.round(ratio*100)/100,minimum:min});
    }
    const headings=[...main.querySelectorAll('h1,h2,h3,h4,h5,h6')];let prior=0;const headingErrors=[];for(const h of headings){const level=Number(h.tagName.slice(1));if(!h.textContent.trim()||prior&&level>prior+1)headingErrors.push(h.tagName+' '+h.textContent.trim().slice(0,60));prior=level;}
    return {headingErrors,h1:main.querySelectorAll('h1').length,overflow:document.documentElement.scrollWidth-document.documentElement.clientWidth,gap:hero?hero.getBoundingClientRect().top-header.getBoundingClientRect().bottom:null,missingImages:[...main.querySelectorAll('img')].filter(e=>e.complete&&e.naturalWidth===0&&e.getAttribute('src')).map(e=>e.getAttribute('src')),candidates};
   });
   records.push({route:route.route,width,status:res.status(),...info,errors:[...errors]});
   require(res.status()===200,url+' HTTP '+res.status());require(info.h1===1,url+' H1 '+info.h1);require(!info.headingErrors.length,url+' heading hierarchy '+info.headingErrors.join('; '));require(info.overflow<=2,url+' overflow at '+width);
   if(route.route!=='/')require(info.gap!==null&&Math.abs(info.gap)<=2,url+' gap '+info.gap+' at '+width);
   require(!info.missingImages.length,url+' broken images at '+width);
   require(!errors.length,url+' runtime or local asset error '+errors.join('; '));
   // Solid-color estimates are review evidence, not a WCAG certificate.
   // Capture representative screenshots of every page, not just the home page.
   if(width===390||width===1440)await p.screenshot({path:out+'/'+route.slug+'-'+width+'.jpg',type:'jpeg',quality:72,fullPage:true,animations:'disabled'});
   if(route.route==='/'){
    const cards=p.locator('.trading-block_one-inner');require(await cards.count()===3,'Expected three brokerage cards');
    for(let i=0;i<await cards.count();i++){
     const c=cards.nth(i);await c.scrollIntoViewIfNeeded();await p.mouse.move(1,1);await p.evaluate(()=>document.activeElement.blur());
     for(const state of ['normal','hover','focus']){
      if(state==='hover')await c.locator('.lower-box').hover();
      if(state==='focus'){await p.mouse.move(1,1);await c.locator('.lower-box a').focus();}
      const m=await c.evaluate(e=>({bg:getComputedStyle(e.querySelector('.lower-box')).backgroundColor,fg:getComputedStyle(e.querySelector('li')).color,icon:getComputedStyle(e.querySelector('li span')).color,hover:e.matches(':hover'),focus:e.matches(':focus-within'),overlay:getComputedStyle(e.querySelector('.lower-box'),'::before').content}));
      const t=contrast(rgb(m.fg),rgb(m.bg)),icon=contrast(rgb(m.icon),rgb(m.bg));
      cardStates.push({width,card:i,state,textRatio:t,iconRatio:icon,...m});
      require(state==='hover'?m.hover:state==='focus'?m.focus:!m.hover&&!m.focus,'Card state not reached '+width+' '+i+' '+state);
      require(t>=4.5&&icon>=3&&m.overlay==='none','Card contrast '+width+' '+i+' '+state);
     }
    }
    for(const a of await p.getByRole('link',{name:'Discuss Your Setup',exact:true}).all())require(new URL(await a.getAttribute('href'),root).pathname==='/contact/','Wrong homepage CTA');
    require(await p.locator('.play-now-two[aria-label]').count()>0,'Missing platform link accessible name');
   }
  }
 }
}finally{
 fs.writeFileSync(out+'/results.json',JSON.stringify({records,cardStates,failures,scope:'Real WordPress at seven widths. Solid-color contrast candidates require visual review; image/gradient and all interactive states are not certified.'},null,2));
 await browser.close();
}
assert.equal(failures.length,0,failures.slice(0,40).join('\n'));
console.log('PASS: '+records.length+' real WordPress page/viewport checks; '+cardStates.length+' card-state contrast checks; 104 screenshot captures.');
