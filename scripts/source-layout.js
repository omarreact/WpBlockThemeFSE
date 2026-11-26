(function(wp){
 const el=wp.element.createElement, editor=wp.blockEditor;
 wp.blocks.registerBlockType('pin/source-layout',{
  apiVersion:3,title:'Source layout',category:'design',icon:'screenoptions',
  attributes:{tagName:{type:'string',default:'div'},sourceAttrs:{type:'object',default:{}}},
  supports:{html:false,align:['wide','full'],color:{background:true,text:true,gradients:true},spacing:{padding:true,margin:true},typography:{fontSize:true,lineHeight:true,__experimentalFontFamily:true,__experimentalFontWeight:true},__experimentalBorder:{color:true,radius:true,style:true,width:true},dimensions:{minHeight:true},shadow:true},
  edit:function(props){
   const a=props.attributes, raw=a.sourceAttrs||{}, tag=['div','main','section','article','aside','header','footer'].includes(a.tagName)?a.tagName:'div';
   const blockProps=editor.useBlockProps({className:raw.class||''});
   const extra={};Object.keys(raw).filter(k=>k==='id'||k==='role'||k.startsWith('data-')||k.startsWith('aria-')).forEach(k=>extra[k]=raw[k]);
   const inner=editor.useInnerBlocksProps({...extra,...blockProps},{templateLock:false});
   return el(tag,inner);
  },
  save:function(){return el(editor.InnerBlocks.Content);}
 });
})(window.wp);