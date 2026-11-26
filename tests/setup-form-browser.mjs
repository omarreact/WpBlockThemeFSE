import {chromium} from 'playwright';
import assert from 'node:assert/strict';
const b=await chromium.launch({headless:true});
try{
 const page=await b.newPage();
 await page.goto('http://localhost:8888/wp-login.php');await page.locator('#user_login').fill('admin');await page.locator('#user_pass').fill('password');
 await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
 await page.goto('http://localhost:8888/wp-admin/admin.php?page=pin-local-setup');
 const form=page.locator('#pin-local-import');await form.waitFor();
 const posts=[];page.on('request',req=>{if(req.method()==='POST'&&req.url().includes('page=pin-local-setup'))posts.push(req.resourceType())});
 await form.locator('button').click();
 await page.getByRole('heading',{name:'Website ready',exact:true}).waitFor({timeout:180000});
 assert.equal(await page.locator('#pin-import-count').innerText(),'52 / 52');
 assert.equal(posts.length,13);assert(posts.every(t=>t==='document'),'Expected normal document POST requests');
 console.log('PASS: actual setup form imported/matched 52 pages in 13 authenticated normal page POSTs without fetch/JSON.');
}finally{await b.close();}
