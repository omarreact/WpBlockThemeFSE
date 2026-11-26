(function(wp){
 'use strict';
 const el=wp.element.createElement, E=wp.blockEditor, C=wp.components, F=wp.element.Fragment;
 const layouts=['div','main','section','article','aside','header','footer','nav','ul','ol','li','a'];
 const elements=['img','a','span','button','p','h1','h2','h3','h4','h5','h6','strong','em','small','i','b'];
 const metadata=window.pinSourceBlockMetadata||[];
 const change=(props,key,value)=>props.setAttributes({sourceAttrs:{...(props.attributes.sourceAttrs||{}),[key]:value}});
 function replaceImage(props,url,alt){
  const next={...(props.attributes.sourceAttrs||{}),src:url};
  if(typeof alt==='string')next.alt=alt;
  ['srcset','sizes','data-src','data-srcset','data-dark-src','data-light-src','data-lazy-src','data-original'].forEach(k=>delete next[k]);
  props.setAttributes({sourceAttrs:next});
 }
 function sourceStyle(raw){
  const css=document.createElement('div').style, out={};
  css.cssText=raw||'';
  for(let i=0;i<css.length;i++){
   const key=css.item(i), prop=key.startsWith('--')?key:key.replace(/-([a-z])/g,(_,c)=>c.toUpperCase()).replace(/^Webkit/,'Webkit');
   out[prop]=css.getPropertyValue(key);
  }
  return out;
 }
 // Restore source document context only inside the Gutenberg content iframe.
 // Do not run the website's runtime (menus, animations, submissions) in admin.
 const canvasProfiles=window.pinSourceCanvasProfile;
 const observed=new WeakMap();
 function syncCanvas(doc){
  if(!canvasProfiles||doc===window.document||!doc.body)return;
  const wrapper=doc.body.matches('.editor-styles-wrapper')?doc.body:doc.querySelector('.editor-styles-wrapper');
  if(!wrapper)return;
  const main=wrapper.querySelector('main#main-content,main[data-mgl2-layout]');
  const profile=(main?.hasAttribute('data-mgl2-layout')?canvasProfiles.inner:canvasProfiles.home)||{};
  const sourceClasses=new Set(String(profile.body?.class||'').split(/\s+/).filter(Boolean));
  const knownClasses=new Set([...String(canvasProfiles.home?.body?.class||'').split(/\s+/),...String(canvasProfiles.inner?.body?.class||'').split(/\s+/)].filter(Boolean));
  knownClasses.forEach(c=>doc.body.classList.toggle(c,sourceClasses.has(c)));
  doc.body.classList.add('pin-editor-canvas','pin-source-edge');
  Object.entries(profile.html||{}).forEach(([k,v])=>{if(k.startsWith('data-')&&typeof v==='string')doc.documentElement.setAttribute(k,v);});
  // Page editor has no theme template wrapper; Site Editor already has one.
  // Only mark the canvas's own root when a source main actually needs it.
  wrapper.querySelectorAll('[data-pin-editor-page-wrapper]').forEach(e=>{
   if(!main||!e.contains(main)||main.parentElement?.closest('.page-wrapper:not([data-pin-editor-page-wrapper])')){
    e.classList.remove('page-wrapper');e.removeAttribute('data-pin-editor-page-wrapper');
   }
  });
  if(main&&!main.parentElement?.closest('.page-wrapper')){
   const root=main.closest('.is-root-container')||wrapper;
   if(root!==main&&!root.classList.contains('page-wrapper')){
    root.classList.add('page-wrapper');root.setAttribute('data-pin-editor-page-wrapper','1');
   }
  }
 }
 function attachCanvas(node){
  const doc=node?.nodeType===9?node:node?.ownerDocument;
  if(!doc||doc===window.document||!canvasProfiles)return;
  syncCanvas(doc);
  if(observed.has(doc))return;
  let pending=false;
  const schedule=()=>{if(pending)return;pending=true;(doc.defaultView||window).requestAnimationFrame(()=>{pending=false;syncCanvas(doc);});};
  const observer=new (doc.defaultView.MutationObserver)(schedule);
  observer.observe(doc.body,{childList:true,subtree:true});
  observed.set(doc,observer);
 }
 // React's block refs may be mounted before the iframe document is ready.
 // Listen for editor-canvas creation/navigation as a second, idempotent path.
 if(canvasProfiles){
  const attachedFrames=new WeakSet();
  let scheduled=false;
  function scan(){
   scheduled=false;
   document.querySelectorAll('iframe[name="editor-canvas"]').forEach(frame=>{
    const connect=()=>{try{if(frame.contentDocument)attachCanvas(frame.contentDocument);}catch(_){/* Ignore inaccessible frames. */}};
    if(!attachedFrames.has(frame)){attachedFrames.add(frame);frame.addEventListener('load',connect);}
    connect();
   });
  }
  const schedule=()=>{if(!scheduled){scheduled=true;window.requestAnimationFrame(scan);}};
  const start=()=>{scan();new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true});};
  if(document.body)start();else document.addEventListener('DOMContentLoaded',start,{once:true});
 }
 function sourceProps(raw,tag){
  const out={};
  const names={tabindex:'tabIndex',srcset:'srcSet',fetchpriority:'fetchPriority'};
  Object.keys(raw).forEach(k=>{
   if(/^(?:id|role|title|lang|dir|aria-[a-z0-9_-]+|data-[a-z0-9_-]+)$/.test(k))out[k]=raw[k];
   else if(tag==='img'&&/^(?:src|srcset|sizes|alt|width|height|loading|decoding|fetchpriority)$/.test(k))out[names[k]||k]=raw[k];
   else if(tag==='a'&&/^(?:href|target|rel)$/.test(k))out[k]=raw[k];
   else if(k==='hidden')out.hidden=true;
  });
  // Never let an editable link or button navigate or submit from the canvas.
  if(tag==='a'||tag==='button'){
   out.onClick=e=>e.preventDefault();
   if(tag==='button')out.type='button';
  }
  return out;
 }
 function controls(props,isLeaf){
  const a=props.attributes, r=a.sourceAttrs||{};
  const fields=[el(C.TextControl,{key:'class',label:'Source CSS classes',value:r.class||'',onChange:v=>change(props,'class',v)}),el(C.TextControl,{key:'id',label:'HTML anchor',value:r.id||'',onChange:v=>change(props,'id',v)})];
  if(a.tagName==='a')fields.push(el(C.TextControl,{key:'href',label:'Link URL',value:r.href||'',onChange:v=>change(props,'href',v)}));
  if(isLeaf&&a.tagName==='img'){
   fields.push(el(C.TextControl,{key:'src',label:'Image URL',value:r.src||'',onChange:v=>replaceImage(props,v)}),
     el(C.TextControl,{key:'alt',label:'Alternative text',value:r.alt||'',onChange:v=>change(props,'alt',v)}),
     el(E.MediaUploadCheck,{key:'media'},el(E.MediaUpload,{allowedTypes:['image'],onSelect:m=>replaceImage(props,m.url,m.alt||''),render:({open})=>el(C.Button,{variant:'secondary',onClick:open},'Replace image')})));
  }
  if(isLeaf&&a.tagName!=='img')fields.push(el(C.TextareaControl,{key:'text',label:'Text content',value:a.content||'',onChange:v=>props.setAttributes({content:v})}));
  return el(E.InspectorControls,null,el(C.PanelBody,{title:'Source element',initialOpen:true},...fields));
 }
 function Layout(props){
  const a=props.attributes,r=a.sourceAttrs||{},tag=layouts.includes(a.tagName)?a.tagName:'div';
  const ref=wp.element.useRef(null);
  const blockProps=E.useBlockProps({className:r.class||'',ref});
  wp.element.useLayoutEffect(()=>{if(tag==='main'||tag==='header'||tag==='footer'||(r.class||'').split(/\s+/).includes('page-wrapper'))attachCanvas(ref.current);},[tag,r.class,r.id]);
  const inner=E.useInnerBlocksProps({...sourceProps(r,tag),...blockProps,style:{...sourceStyle(r.style),...blockProps.style}},{templateLock:false});
  if(tag==='a')inner.onClick=e=>e.preventDefault();
  return el(F,null,controls(props,false),el(tag,inner));
 }
 function Leaf(props){
  const a=props.attributes,r=a.sourceAttrs||{},tag=elements.includes(a.tagName)?a.tagName:'span';
  const ref=wp.element.useRef(null);
  const bp=E.useBlockProps({className:r.class||'',ref});
  wp.element.useLayoutEffect(()=>{attachCanvas(ref.current);},[]);
  const attrs={...sourceProps(r,tag),...bp,style:{...sourceStyle(r.style),...bp.style}};
  if(tag==='a'||tag==='button')attrs.onClick=e=>e.preventDefault();
  if(tag==='img'&&attrs.alt===undefined)attrs.alt='';
  // Preserve the actual source tag, dimensions and CSS class on the editable
  // block itself. Extra divs/spans change flex/grid, child selectors and icons.
  const view=tag==='img'?el(tag,attrs):el(tag,attrs,a.content||(tag==='i'?' ':''));
  return el(F,null,controls(props,true),view);
 }
 metadata.forEach(m=>{
  const layout=m.name==='pin/source-layout';
  wp.blocks.registerBlockType(m,{edit:layout?Layout:Leaf,save:layout?()=>el(E.InnerBlocks.Content):()=>null});
 });
})(window.wp);
