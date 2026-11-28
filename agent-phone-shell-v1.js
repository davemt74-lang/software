(() => {
  'use strict';
  const cfg=window.VP3_AGENT_PHONE_SHELL_V1;if(!cfg||window.VP3AgentPhoneShellV1)return;
  const endpoint=new URL(cfg.endpoint,location.href);if(endpoint.origin!==location.origin||endpoint.username||endpoint.password)return;
  let disposed=false,inFlight=null,lastCall='',update=null;const seen=new Set();
  const card=document.createElement('section');card.className='vp3-agent-phone-card';card.hidden=true;card.setAttribute('role','region');card.setAttribute('aria-label','Personal phone activity');card.setAttribute('aria-live','polite');document.body.append(card);
  const parentOwnsActivity=()=>{try{return parent!==window&&parent.location.origin===location.origin&&parent.VP3AgentPhoneShellV1?.ownerId===Number(cfg.owner_id)&&parent.VP3AgentPhoneShellV1?.activityEnabled===true;}catch{return false;}};
  const ownerCurrent=()=>!disposed&&!parentOwnsActivity()&&Number(cfg.owner_id)>0&&(!window.VP3_ORB_CONFIG||Number(window.VP3_ORB_CONFIG.ownerKey)===Number(cfg.owner_id))&&!window.VP3ParticipantContinuity?.selectedContext?.();
  const foreground=()=>ownerCurrent()&&!document.hidden&&document.hasFocus()&&navigator.onLine!==false;
  const voiceReady=(async()=>{
    if(!cfg.admin_surface||!cfg.voice_enabled)return;
    const load=src=>new Promise((resolve,reject)=>{const url=new URL(src,location.href);if(url.origin!==location.origin)return reject(Error('Voice asset unavailable'));const script=document.createElement('script');script.src=url.href;script.onload=resolve;script.onerror=reject;document.head.append(script);});
    if(!window.StonefellowPremiumVoiceV122)await load(cfg.voice_script);
    if(!window.STONEFELLOW_NOTIFICATION_CENTER){
      // Reuse the existing announcement queue only: orbOnly creates no header,
      // public chatbar, attention poller, or Admin Agent conversation.
      window.STONEFELLOW_NOTIFICATION_DRAWER={endpoint:cfg.notifications_endpoint,csrf:cfg.csrf,orbOnly:true,userId:Number(cfg.owner_id),agentId:0,
        workingAttentionBinding:cfg.binding,agentVoiceEnabled:cfg.voice_enabled,agentEndpoint:cfg.chat_endpoint};
      await load(cfg.drawer_script);
    }
  })().catch(()=>{});
  const make=(tag,value)=>{const n=document.createElement(tag);if(value)n.textContent=value;return n;};
  const position=()=>{if(card.hidden)return;const anchor=document.querySelector('.vp3-orb-ball,[data-phone-workspace-toggle]'),r=anchor?.getBoundingClientRect();
    const w=card.getBoundingClientRect().width,h=card.getBoundingClientRect().height;card.style.left=Math.max(12,Math.min(innerWidth-w-12,r?.left??innerWidth-w-12))+'px';
    card.style.top=Math.max(12,Math.min(innerHeight-h-12,r&&r.top>h+24?r.top-h-12:(r?.bottom??0)+12))+'px';};
  function action(host,label,fn,key){const b=make('button',label);b.type='button';if(key)b.dataset[key]='';b.addEventListener('click',fn);host.append(b);return b;}
  function render(){
    const call=window.VP3PhoneWorkspaceV1?.attention?.();card.replaceChildren();
    if(!ownerCurrent()||!call&&!update){card.hidden=true;return;}card.hidden=false;
    const actions=make('div');actions.className='vp3-agent-phone-actions';
    if(call&&call.owner_id===Number(cfg.owner_id)){
      card.append(make('h3',call.incoming?'Incoming phone call':'Phone call'),make('p',String(call.caller||'Unknown caller').slice(0,250)));
      if(call.incoming){action(actions,'Answer',()=>void window.VP3PhoneWorkspaceV1?.acceptCall(call.call_id),'answer').disabled=call.busy;
        action(actions,'Decline',()=>window.VP3PhoneWorkspaceV1?.declineCall(call.call_id),'decline').disabled=call.busy;
      }else action(actions,'End call',()=>window.VP3PhoneWorkspaceV1?.endCall(call.call_id),'decline').disabled=call.busy;
      action(actions,'Open Phone',()=>window.VP3PhoneWorkspaceV1?.open());
      if(call.call_id!==lastCall){lastCall=call.call_id;window.VP3AgentOrb?.bubble?.('Incoming phone activity from '+String(call.caller||'a caller').slice(0,150));}
    }else if(update){
      card.append(make('h3','Connected phone update'),make('p',update.speech));const link=make('a','Open Chat');link.href=update.url;actions.append(link);
      action(actions,'Dismiss',()=>{update=null;render();});
    }else{card.hidden=true;return;}
    card.append(actions);position();
  }
  function restoreSeen(){try{const rows=JSON.parse(localStorage.getItem('vp3:phone-activity:'+cfg.owner_id)||'[]');if(Array.isArray(rows))for(const id of rows)if(Number.isSafeInteger(id)&&id>0)seen.add(id);}catch(_){}}
  function present(row){
    if(!foreground()||!Number.isSafeInteger(row?.id)||row.id<1||!Number.isSafeInteger(row.message_id)||row.message_id<1||typeof row.speech!=='string'||row.speech.length>1000)return;
    const url=new URL(row.url,location.href),expected=new URL('notifications.php',new URL(endpoint.href.replace(/api\/agent-phone-shell-v1\.php(?:\?.*)?$/,'')));
    if(url.origin!==location.origin||url.username||url.password||url.pathname!==expected.pathname||url.searchParams.get('open')!==String(row.id))return;
    restoreSeen();if(seen.has(row.id))return;seen.add(row.id);try{localStorage.setItem('vp3:phone-activity:'+cfg.owner_id,JSON.stringify([...seen].slice(-150)));}catch(_){}
    update={...row,url:url.href};window.VP3AgentOrb?.bubble?.(row.speech);render();
    window.dispatchEvent(new CustomEvent('vp3:personal-phone-activity',{detail:{owner_id:Number(cfg.owner_id),message_id:row.message_id,conversation_id:row.conversation_id,agent_id:row.agent_id,speech:row.speech,url:url.href}}));
    void voiceReady.then(()=>window.STONEFELLOW_NOTIFICATION_CENTER?.announceWhen?.(row.speech,()=>foreground()&&!window.VP3PhoneWorkspaceV1?.attention?.(),{autoReply:false}));
  }
  async function poll(){
    if(cfg.enabled!==true||!foreground()||inFlight)return;
    const controller=new AbortController();inFlight=controller;const timer=setTimeout(()=>controller.abort(),15000);
    try{const response=await fetch(endpoint.href,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,
      headers:{'Content-Type':'application/json','X-VP3-Working-Attention':cfg.binding},body:JSON.stringify({action:'activity',csrf_token:cfg.csrf})});
      if(response.status===401||response.status===403){window.VP3PhoneWorkspaceV1?.dispose?.();dispose();return;}const data=await response.json();
      if(!response.ok||data.ok!==true||data.owner_user_id!==Number(cfg.owner_id)||!foreground()||!Array.isArray(data.updates))return;
      for(const row of data.updates.slice(0,20))present(row);
    }catch(_){}finally{clearTimeout(timer);if(inFlight===controller)inFlight=null;}
  }
  function addPhoneButtons(){if(!window.VP3_PHONE_WORKSPACE_V1)return;
    const hosts=[...document.querySelectorAll('.admin-page-actions,.admin-mobile-bar,.site-header .header-right')];
    if(!cfg.admin_surface&&!hosts.length&&!document.querySelector('[data-phone-workspace-toggle]'))hosts.push(document.body);
    for(const host of hosts){
      if(host.querySelector('[data-phone-workspace-toggle]'))continue;const b=make('button');b.type='button';b.className='vp3-personal-phone-button';b.dataset.phoneWorkspaceToggle='';b.setAttribute('aria-label','Phone');b.setAttribute('aria-expanded','false');b.setAttribute('aria-controls','vp3PhoneWorkspace');
      b.innerHTML='<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 3H4a1 1 0 0 0-1 1c0 9.4 7.6 17 17 17a1 1 0 0 0 1-1v-3l-5-2-2 2a13 13 0 0 1-7-7l2-2-2-5Z"/></svg>';host.prepend(b);
      if(host===document.body){b.classList.add('vp3-personal-phone-launcher');b.dataset.adminSurface=String(cfg.admin_surface===true);}
    }
  }
  function wake(){render();void poll();}function dispose(){disposed=true;inFlight?.abort();clearInterval(timer);update=null;card.remove();}
  const timer=setInterval(poll,10000);addPhoneButtons();window.addEventListener('vp3:phone-workspace-ready',()=>{addPhoneButtons();render();});
  window.addEventListener('vp3:phone-attention-changed',render);window.addEventListener('vp3:participant-selection-changed',wake);
  window.addEventListener('focus',wake);window.addEventListener('online',wake);window.addEventListener('pageshow',wake);document.addEventListener('visibilitychange',wake);
  window.addEventListener('resize',position);window.addEventListener('pagehide',dispose,{once:true});window.addEventListener('stonefellow:session-expired',dispose);window.addEventListener('stonefellow:logout',dispose);
  window.VP3AgentPhoneShellV1=Object.freeze({ownerId:Number(cfg.owner_id),activityEnabled:cfg.enabled===true,refresh:poll,dispose});wake();
})();
