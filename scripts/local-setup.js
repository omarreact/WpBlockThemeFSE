(()=>{'use strict';
 const form=document.getElementById('pin-local-import'), progress=document.getElementById('pin-local-progress');
 if(!form||!progress)return;
 form.addEventListener('submit',async event=>{
  event.preventDefault();
  const data=new FormData(form);data.set('pin_json','1');
  const controls=Array.from(form.querySelectorAll('button,input[type=checkbox]'));
  controls.forEach(c=>c.disabled=true);
  progress.textContent='Preparing local import…';
  try{
   let last=-1;
   for(let n=0;n<80;n++){
    const response=await fetch(form.action,{method:'POST',body:data,credentials:'same-origin',cache:'no-store'});
    const result=await response.json();
    if(!response.ok||!result.success)throw Error(result.data?.message||'Import request failed');
    const {cursor,total,done}=result.data;
    progress.textContent='Imported / matched '+cursor+' of '+total+' pages';
    if(done){location.reload();return;}
    if(cursor<=last)throw Error('Import stopped advancing. Resume after reviewing the setup report.');
    last=cursor;
   }
   throw Error('Import batch limit reached. Resume from the setup page.');
  }catch(error){progress.textContent=error.message+' — existing progress is preserved.';controls.forEach(c=>c.disabled=false);}
 });
})();