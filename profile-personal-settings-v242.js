(() => {
'use strict';
const cfg=window.PROFILE_AGENT_PORTAL;
const form=document.getElementById('paPersonalProfileDetails');
if(!cfg?.endpoint||!cfg?.csrf||!form)return;
const notice=document.getElementById('profileAgentNotice');
const setNotice=(text,error=false)=>{if(!notice)return;notice.textContent=text;notice.className=`profile-agent-notice${error?' error':''}`;};
form.addEventListener('submit',async event=>{
  event.preventDefault();
  const button=form.querySelector('button[type=submit]');if(button)button.disabled=true;
  const fd=new FormData(form);const payload={action:'save_profile',csrf_token:cfg.csrf};
  for(const [key,value] of fd.entries())payload[key]=value;
  // Preserve the canonical profile fields that live in the primary Profile form.
  // The API wrapper treats omitted fields as unchanged for the v242 extension.
  payload.extended_only=1;
  try{
    const response=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok)throw new Error(data?.error||'Profile details could not be saved.');
    setNotice('Profile details saved.');
  }catch(error){setNotice(error.message||'Profile details could not be saved.',true);}
  finally{if(button)button.disabled=false;}
});
})();
