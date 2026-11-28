(() => {
  'use strict';
  const cfg=window.PROFILE_AGENT_PORTAL,root=document.getElementById('profilePhoneSettings');
  if(!cfg?.phoneEndpoint||!root)return;
  let model=null,requestId=0,pending=null,disposed=false;
  const node=(tag,text)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;return el;};
  async function request(payload){
    const epoch=++requestId;pending?.abort();const controller=new AbortController();pending=controller;
    const timer=setTimeout(()=>controller.abort(),12000);
    try{
      const endpoint=new URL(cfg.phoneEndpoint,location.href);if(endpoint.origin!==location.origin)throw new Error('Calling settings are unavailable.');
      const response=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',signal:controller.signal,
        headers:{'X-VP3-Profile-Owner':String(cfg.ownerUserId),...(payload?{'Content-Type':'application/json'}:{})},
        ...(payload?{method:'POST',body:JSON.stringify({...payload,csrf_token:cfg.csrf})}:{})});
      const value=await response.json();
      if(disposed||epoch!==requestId)return null;
      if([401,403].includes(response.status)){model=null;root.replaceChildren(node('p','Your account changed. Reload Profile settings.'));disposed=true;return null;}
      if(!response.ok||value?.ok!==true)throw new Error(value?.error||'Calling settings could not be loaded.');
      if(value.contract!=='vp3.profile-phone.v1'||value.owner_user_id!==Number(cfg.ownerUserId)||!Array.isArray(value.numbers))throw new Error('Calling settings could not be confirmed.');
      return value;
    }catch(error){if(error.name==='AbortError')throw new Error(payload?'Saving could not be confirmed. Reload calling settings before retrying.':'Calling settings timed out. Retry below.');throw error;}
    finally{clearTimeout(timer);if(pending===controller)pending=null;}
  }
  function render(){
    root.replaceChildren();const card=node('div');card.className='profile-agent-card';card.append(node('h2','Profile phone calls'),node('p','Show an assigned Twilio number in your Profile chat. Calls use that line’s existing Phone routing, voicemail and recording settings.'));
    const status=node('p',model?.reason||'');status.setAttribute('role','status');
    if(!model?.ready){card.append(status);const retry=node('button','Reload calling settings');retry.type='button';retry.addEventListener('click',()=>void load());card.append(retry);root.append(card);return;}
    const form=node('form');form.className='profile-phone-settings';
    const enabled=node('input');enabled.type='checkbox';enabled.checked=model.enabled===true;
    const label=node('label');label.append(enabled,document.createTextNode(' Show Call by phone in my Profile chat'));
    const lineLabel=node('label','Public Twilio line'),select=node('select');select.setAttribute('aria-label','Public Twilio line');
    const empty=node('option','Choose an assigned phone line');empty.value='0';select.append(empty);
    for(const row of model.numbers){const option=node('option',row.number+' · '+row.agent_name);option.value=String(row.id);select.append(option);}
    select.value=String(model.number_id);lineLabel.append(select);
    const save=node('button','Save calling settings');save.type='submit';save.className='primary';
    const reload=node('button','Reload');reload.type='button';reload.addEventListener('click',()=>void load());
    const actions=node('div');actions.className='profile-agent-form-actions';actions.append(save,reload);
    form.append(label,lineLabel,node('p','The selected number is public when enabled. Your saved personal callback number stays private.'),status,actions);
    form.addEventListener('submit',async event=>{event.preventDefault();if(save.disabled||disposed)return;
      if(enabled.checked&&!Number(select.value)){status.textContent='Choose an assigned Twilio phone line.';return;}
      const payload={enabled:enabled.checked,number_id:Number(select.value),revision:model.revision};save.disabled=true;reload.disabled=true;enabled.disabled=true;select.disabled=true;status.textContent='Saving calling settings…';
      try{const value=await request(payload);if(value){model=value;render();root.querySelector('[role="status"]').textContent='Calling settings saved.';}}
      catch(error){if(!disposed){status.textContent=error.message;reload.disabled=false;}}
      // A lost save acknowledgement leaves the exact draft visible but requires
      // an explicit read of the saved revision before another mutation.
    });
    card.append(form);root.append(card);
  }
  async function load(){if(disposed)return;root.replaceChildren(node('p','Loading Profile calling…'));
    try{const value=await request();if(value){model=value;render();}}
    catch(error){if(!disposed){model=null;render();root.querySelector('[role="status"]').textContent=error.message;}}}
  window.addEventListener('pagehide',()=>{disposed=true;requestId++;pending?.abort();});
  window.addEventListener('pageshow',event=>{if(event.persisted){disposed=false;void load();}});
  void load();
})();
