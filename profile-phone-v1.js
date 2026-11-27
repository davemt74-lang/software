(() => {
  'use strict';
  const cfg=window.STONEFELLOW_PROFILE_AGENT,shell=document.getElementById('profileAgentShell');
  if(!cfg?.phoneEndpoint||!shell||shell.querySelector('[data-profile-phone]'))return;
  let endpoint;try{endpoint=new URL(cfg.phoneEndpoint,location.href);if(endpoint.origin!==location.origin)return;}catch(_){return;}
  const host=document.createElement('div');host.className='profile-phone';host.dataset.profilePhone='';host.hidden=true;
  const link=document.createElement('a');link.className='profile-phone-link';link.textContent='Call by phone';link.setAttribute('aria-label','Call '+String(cfg.ownerName||'the profile owner')+' by phone');
  const notice=document.createElement('span');notice.className='profile-phone-note';notice.setAttribute('role','status');
  host.append(link,notice);shell.querySelector('.profile-agent-sections')?.after(host);
  let pending=null,generation=0,approved='',alive=true,checking=false;
  async function read(){
    pending?.abort();const controller=new AbortController(),epoch=++generation;pending=controller;
    const timer=setTimeout(()=>controller.abort(),10000),url=new URL(endpoint);url.searchParams.set('action','public');url.searchParams.set('username',cfg.username);
    try{
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
      const data=await response.json();if(!alive||epoch!==generation)return null;
      if(!response.ok||data?.ok!==true||data.contract!=='vp3.profile-phone.v1')throw new Error('Phone availability could not be checked.');
      if(data.enabled!==true){approved='';link.removeAttribute('href');host.hidden=true;return null;}
      if(typeof data.number!=='string'||!/^\+[1-9][0-9]{7,14}$/.test(data.number)||data.href!=='tel:'+data.number)throw new Error('Phone availability could not be checked.');
      approved=data.href;link.href=approved;host.hidden=false;notice.textContent='Opens your phone app. Browser audio and video are in Chat options.';return approved;
    }catch(_){if(alive&&epoch===generation){approved='';link.removeAttribute('href');if(!host.hidden)notice.textContent='Calling could not be checked. Open the chat again to retry.';}return null;}
    finally{clearTimeout(timer);if(pending===controller)pending=null;}
  }
  link.addEventListener('click',async event=>{
    event.preventDefault();if(checking||!alive)return;checking=true;link.setAttribute('aria-disabled','true');notice.textContent='Checking the current phone line…';
    const was=approved;
    try{const current=await read();if(current&&current===was&&alive){location.href=current;notice.textContent='Continue in your phone app to place the call.';}else if(current)notice.textContent='The phone line changed. Select Call by phone again.';}
    finally{checking=false;link.removeAttribute('aria-disabled');}
  });
  window.addEventListener('vp3:profile-agent-open',()=>{if(!checking)void read();});
  window.addEventListener('focus',()=>{if(!shell.hidden&&!checking)void read();});
  window.addEventListener('pagehide',()=>{alive=false;generation++;pending?.abort();approved='';link.removeAttribute('href');});
  window.addEventListener('pageshow',event=>{if(event.persisted){alive=true;if(!shell.hidden)void read();}});
  if(!shell.hidden)void read();
})();
