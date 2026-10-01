(()=>{'use strict';
const root=document.querySelector('[data-hs-device-claim]');if(!root)return;
const form=root.querySelector('form'),code=root.querySelector('input'),button=root.querySelector('button'),status=root.querySelector('[role=status]');
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