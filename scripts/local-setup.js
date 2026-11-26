(()=>{'use strict';
 const form=document.getElementById('pin-local-import'), progress=document.getElementById('pin-local-progress');
 if(!form||!progress)return;
 let submitting=false;
 form.addEventListener('submit',event=>{
  if(submitting){event.preventDefault();return;}
  submitting=true;
  progress.textContent='Importing next batch… The page will reload when it is saved.';
  form.querySelectorAll('button').forEach(button=>button.disabled=true);
  // Do not disable migration input: the normal browser POST needs its value.
 });
 // Continuation is only emitted by the server after a successful batch, with
 // a user-bound nonce and saved cursor. No AJAX or JSON parsing is required.
 if(form.dataset.pinContinue==='1'){
  progress.textContent='Batch saved. Continuing import…';
  window.setTimeout(()=>{if(!submitting)form.requestSubmit();},500);
 }
})();
