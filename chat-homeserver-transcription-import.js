/* Import only explicitly shared HomeServer transcript text into Cloud sessions. */
(()=>{
'use strict';
const options=window.STONEFELLOW_HS_TRANSCRIPTION_IMPORT||{};
const endpoint=String(options.endpoint||'/api/homeserver-transcription-import-v1.php');
let overlay=null,busy=false;
async function request(id){
 const method=id?'POST':'GET';
 const r=await fetch(endpoint,{
  method,credentials:'same-origin',cache:'no-store',
  headers:{Accept:'application/json',...(id?{'Content-Type':'application/json'}:{})},
  ...(id?{body:JSON.stringify({session_id:id,csrf_token:String(options.csrf||'')})}:{})
 });
 const data=await r.json().catch(()=>({ok:false}));
 if(!r.ok||!data.ok)throw new Error(data.error||'HomeServer import unavailable.');
 return data;
}
function ensure(){
 const canvas=document.getElementById('chatTranscriptionCanvas');
 if(!canvas)return false;
 const header=canvas.querySelector('header');
 if(!header||header.querySelector('[data-import-hs-transcript]'))return true;
 const button=document.createElement('button');
 button.type='button';button.dataset.importHsTranscript='1';
 button.className='hs-transcript-cloud-import';
 button.textContent='Import HomeServer';button.title='Import a transcript explicitly shared from your paired HomeServer';
 const close=header.querySelector('[data-transcription-close]');
 header.insertBefore(button,close||null);
 button.addEventListener('click',()=>open().catch(showError));
 return true;
}
function showError(error){
 const el=document.getElementById('hsCloudImportStatus');
 if(el)el.textContent=String(error?.message||error||'HomeServer import failed.');
}
function shell(){
 if(overlay)return overlay;
 overlay=document.createElement('aside');
 overlay.className='hs-cloud-import-dialog';overlay.id='hsCloudImport';
 overlay.hidden=true;overlay.setAttribute('aria-label','Import HomeServer transcription');
 const h=document.createElement('div');h.className='hs-cloud-import-head';
 const label=document.createElement('h3');label.textContent='HomeServer transcriptions';
 const close=document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','Close');close.addEventListener('click',()=>{overlay.hidden=true;});
 h.append(label,close);
 const note=document.createElement('p');
 note.textContent='Only completed transcriptions explicitly shared on your paired HomeServer are listed. Import creates an independent private Cloud copy; no raw recordings transfer.';
 const state=document.createElement('p');state.id='hsCloudImportStatus';state.setAttribute('role','status');state.setAttribute('aria-live','polite');
 const list=document.createElement('div');list.id='hsCloudImportList';
 overlay.append(h,note,state,list);
 document.body.appendChild(overlay);
 return overlay;
}
async function open(){
 const panel=shell();panel.hidden=false;
 const state=document.getElementById('hsCloudImportStatus');
 const list=document.getElementById('hsCloudImportList');
 state.textContent='Checking paired HomeServer permissions…';list.replaceChildren();
 const data=await request();
 const items=data.sessions||[];
 if(!items.length){state.textContent='No HomeServer transcriptions have been shared for Cloud import.';return;}
 state.textContent='Choose a shared transcription to import.';
 items.forEach(item=>{
  const row=document.createElement('div');row.className='hs-cloud-import-row';
  const title=document.createElement('span');
  title.textContent=String(item.title||'HomeServer transcription')+' · '+Number(item.segment_count||0)+' segments';
  const btn=document.createElement('button');btn.type='button';btn.textContent='Import';
  btn.addEventListener('click',async()=>{
   if(busy)return;
   busy=true;btn.disabled=true;
   state.textContent='Importing consented transcript text…';
   try{
    const result=await request(item.id);
    const sessionId=Number(result.cloud_session_id||0);
    state.textContent=result.already_imported?'Already in your Cloud Transcriptions.':'Imported successfully.';
    if(sessionId>0){
      overlay.hidden=true;
      await window.STONEFELLOW_TRANSCRIPTION_CANVAS?.open?.({sessionId});
    }
   }catch(e){showError(e);}
   finally{busy=false;btn.disabled=false;}
  });
  row.append(title,btn);list.appendChild(row);
 });
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{if(!ensure())new MutationObserver((_,obs)=>{if(ensure())obs.disconnect();}).observe(document.body,{childList:true,subtree:true});},{once:true});
else if(!ensure())new MutationObserver((_,obs)=>{if(ensure())obs.disconnect();}).observe(document.body,{childList:true,subtree:true});
})();
