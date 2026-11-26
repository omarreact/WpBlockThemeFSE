import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const browser=await chromium.launch({headless:true});
const root='http://localhost:8888';
const evidence=[];
try{
 const page=await browser.newPage({viewport:{width:1200,height:900},reducedMotion:'reduce'});
 await page.goto(root+'/');
 await page.locator('main#main-content').waitFor();
 await page.evaluate(async()=>{await document.fonts.ready;await Promise.all([...document.images].filter(x=>x.complete).map(x=>x.decode().catch(()=>{})));});
 await page.screenshot({path:'build/forexy/meta-grey-label/screenshot.png',animations:'disabled'});
 for(const width of [360,390,768,1024,1180,1440,1920]){
  await page.setViewportSize({width,height:900});
  for(const [path,selector] of [['/pin-release-blog/','.pin-single'],['/insights/','.pin-blog-list'],['/?s=PIN','.pin-archive'],['/pin-nonexistent-404/','.pin-not-found'],['/contact/','.pin-contact']]){
   const res=await page.goto(root+path,{waitUntil:'domcontentloaded'});await page.locator(selector).first().waitFor();
   assert.equal(res.status(),path.includes('nonexistent')?404:200,path);
   assert(await page.locator('.mgl2-header').count(),path+' missing header');
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth);
   assert(overflow<=2,path+' width '+width+' overflow '+overflow);
   if(path==='/pin-release-blog/')assert(await page.getByText('Native blog fixture body.').count(), 'Blog body absent');
   if(path==='/insights/')assert(await page.getByText('PIN release blog fixture',{exact:true}).count(),'Dynamic article absent');
   if(path==='/contact/')assert(await page.locator('form input[name="action"][value="pin_release_enquiry"]').count(),'Native form absent');
   evidence.push({path,width,status:res.status(),overflow});
  }
 }
 await page.goto(root+'/wp-login.php');await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill('password');await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
 await page.goto(root+'/wp-admin/admin.php?page=pin-local-setup');await page.locator('.pin-setup-card').first().waitFor();assert(await page.getByText('52 / 52',{exact:true}).count());
 await page.screenshot({path:'build/forexy-parity/setup-dashboard.png',fullPage:true});
 console.log('PASS: native posts, dynamic Insights, search, 404, contact, setup dashboard and 7 viewport sizes.');
}finally{fs.writeFileSync('build/forexy-parity/release-qa.json',JSON.stringify(evidence,null,2));await browser.close();}
