(function(wp){
 'use strict';
 const el=wp.element.createElement, E=wp.blockEditor, C=wp.components, F=wp.element.Fragment;
 const layouts=['div','main','section','article','aside','header','footer','nav','ul','ol','li','a'];
 const elements=['img','a','span','button','p','h1','h2','h3','h4','h5','h6','strong','em','small'];
 const metadata=window.pinSourceBlockMetadata||[];
 const change=(props,key,value)=>props.setAttributes({sourceAttrs:{...(props.attributes.sourceAttrs||{}),[key]:value}});
 function controls(props,isLeaf){
  const a=props.attributes, r=a.sourceAttrs||{};
  const fields=[el(C.TextControl,{key:'class',label:'Source CSS classes',value:r.class||'',onChange:v=>change(props,'class',v)}),el(C.TextControl,{key:'id',label:'HTML anchor',value:r.id||'',onChange:v=>change(props,'id',v)})];
  if(a.tagName==='a')fields.push(el(C.TextControl,{key:'href',label:'Link URL',value:r.href||'',onChange:v=>change(props,'href',v)}));
  if(isLeaf&&a.tagName==='img'){
   fields.push(el(C.TextControl,{key:'src',label:'Image URL',value:r.src||'',onChange:v=>change(props,'src',v)}),
     el(C.TextControl,{key:'alt',label:'Alternative text',value:r.alt||'',onChange:v=>change(props,'alt',v)}),
     el(E.MediaUploadCheck,{key:'media'},el(E.MediaUpload,{allowedTypes:['image'],onSelect:m=>{const n={...r,src:m.url,alt:m.alt||''};delete n.srcset;delete n.sizes;props.setAttributes({sourceAttrs:n});},render:({open})=>el(C.Button,{variant:'secondary',onClick:open},'Replace image')})));
  }
  if(isLeaf&&a.tagName!=='img')fields.push(el(C.TextareaControl,{key:'text',label:'Text content',value:a.content||'',onChange:v=>props.setAttributes({content:v})}));
  return el(E.InspectorControls,null,el(C.PanelBody,{title:'Source element',initialOpen:true},...fields));
 }
 function Layout(props){
  const a=props.attributes,r=a.sourceAttrs||{},tag=layouts.includes(a.tagName)?a.tagName:'div';
  const blockProps=E.useBlockProps({className:r.class||''});
  const extra={};Object.keys(r).filter(k=>k==='id'||k==='role'||k.startsWith('data-')||k.startsWith('aria-')).forEach(k=>extra[k]=r[k]);
  const inner=E.useInnerBlocksProps({...extra,...blockProps},{templateLock:false});
  // Never make editor links navigate or activate website actions.
  return el(F,null,controls(props,false),el(tag==='a'?'div':tag,inner));
 }
 function Leaf(props){
  const a=props.attributes,r=a.sourceAttrs||{},tag=elements.includes(a.tagName)?a.tagName:'span';
  const bp=E.useBlockProps({className:r.class||''});
  let view;
  if(tag==='img')view=el('div',bp,el('img',{src:r.src||undefined,alt:r.alt||'',style:{maxWidth:'100%',height:'auto'}}));
  else view=el(tag==='a'||tag==='button'?'span':tag,bp,a.content||'');
  return el(F,null,controls(props,true),view);
 }
 metadata.forEach(m=>{
  const layout=m.name==='pin/source-layout';
  wp.blocks.registerBlockType(m,{edit:layout?Layout:Leaf,save:layout?()=>el(E.InnerBlocks.Content):()=>null});
 });
})(window.wp);
