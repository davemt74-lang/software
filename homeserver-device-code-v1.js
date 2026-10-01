(()=>{'use strict';
const root=document.querySelector('[data-hs-device-claim]');if(!root)return;
const form=root.querySelector('form'),code=root.querySelector('input'),button=root.querySelector('button'),status=root.querySelector('[role=status]');
// The Agent passes only the temporary public code via a URL fragment.
// The verifier and any account credential never enter browser URLs or Cloud UI.
const deepLink=new URLSearchParams(window.location.hash.slice(1)).get('hs_code');
if(deepLink!==null){
 const clean=deepLink.toUpperCase().replace(/[^A-Z0-9]/g,'');
 history.replaceState(null,'',location.pathname+location.search);
 if(/^[A-HJ-NP-Z2-9]{12}$/.test(clean)){
  code.value=clean.replace(/(.{4})/g,'$1-').replace(/-$/,'');
  status.textContent='Your HomeServer code is ready. Approve this connection to continue setup in Agent Chat.';
  root.scrollIntoView({behavior:'auto',block:'center'});
 } else status.textContent='That connection link has an invalid code. Ask your HomeServer Agent for a new link.';
}
form.addEventListener('submit',async event=>{
 event.preventDefault();const value=code.value.toUpperCase().replace(/[^A-Z0-9]/g,'');
 if(!/^[A-HJ-NP-Z2-9]{12}$/.test(value)){status.textContent='Enter the 12-character code shown in your HomeServer Agent Chat.';return;}
 button.disabled=true;status.textContent='Authorizing this HomeServer…';
 try{
 const response=await fetch(root.dataset.api,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'claim',code:value,csrf_token:root.dataset.csrf})});
 const body=await response.json();
 if(!response.ok||!body.ok)throw new Error(body.error||'Could not authorize the pairing code.');
 status.textContent='Authorized. Return to the HomeServer Agent Chat canvas; it will finish connecting automatically.';
 code.value='';form.hidden=true;
 }catch(error){status.textContent=error.message;button.disabled=false;}
});
})();