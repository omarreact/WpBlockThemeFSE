import {chromium} from 'playwright';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true});
try{
 const page=await browser.newPage({viewport:{width:1440,height:900}});
 const origin='http://localhost:8888';
 await page.goto(origin+'/wp-login.php');
 await page.locator('#user_login').fill('admin');
 await page.locator('#user_pass').fill('password');
 await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
 await page.goto(origin+'/pin-editor-fixture/');
 const edit=page.locator('#wp-admin-bar-edit a');
 await edit.waitFor();
 await page.goto(await edit.getAttribute('href'));
 await page.waitForFunction(()=>window.wp?.data?.select('core/block-editor')?.getBlocks()?.some(b=>b.name==='pin/source-layout'),{},{timeout:60000});
 const state=await page.evaluate(()=>{
  const select=wp.data.select('core/block-editor'),root=select.getBlocks().find(b=>b.name==='pin/source-layout'),leaf=root.innerBlocks.find(b=>b.name==='pin/source-element');
  return {registered:!!wp.blocks.getBlockType('pin/source-element'),clientId:leaf.clientId,valid:leaf.isValid,content:leaf.attributes.content};
 });
 assert(state.registered&&state.valid&&state.content==='Original editor fixture');
 await page.evaluate(id=>wp.data.dispatch('core/block-editor').updateBlockAttributes(id,{content:'Edited in Gutenberg fixture'}),state.clientId);
 await page.evaluate(()=>wp.data.dispatch('core/editor').savePost());
 await page.waitForFunction(()=>!wp.data.select('core/editor').isSavingPost()&&!wp.data.select('core/editor').isEditedPostDirty(),{},{timeout:60000});
 await page.reload();
 await page.waitForFunction(()=>wp.data.select('core/block-editor')?.getBlocks()?.some(b=>b.innerBlocks?.some(c=>c.attributes.content==='Edited in Gutenberg fixture')),{},{timeout:60000});
 await page.goto(origin+'/pin-editor-fixture/');
 assert.equal(await page.locator('.fixture-text').innerText(),'Edited in Gutenberg fixture');
 console.log('PASS: real Gutenberg editor loads, edits, saves, reloads and renders source-element content.');
}finally{await browser.close();}
