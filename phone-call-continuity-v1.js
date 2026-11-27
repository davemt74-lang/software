(() => {
  'use strict';
  const cfg=window.VP3_PHONE_WORKSPACE_V1;
  if(!cfg||!['owner','view'].includes(cfg.continuity_role)||window.VP3PhoneContinuityV1)return;
  const owner=Number(cfg.owner_id),binding=cfg.attention_binding,role=cfg.continuity_role;
  const positive=n=>Number.isSafeInteger(n)&&n>0,integer=n=>Number.isSafeInteger(n)&&n>=0;
  const id=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
  const uid=()=>{try{return crypto.randomUUID().replaceAll('-','');}catch{return Array.from({length:32},()=>Math.floor(Math.random()*16).toString(16)).join('');}};
  const scope=value=>value&&integer(value.agent_id)&&integer(value.conversation_id)?{agent_id:value.agent_id,conversation_id:value.conversation_id}:null;
  let target;try{target=new URL(cfg.continuity_url,location.href);}catch{return;}
  if(!positive(owner)||typeof binding!=='string'||!/^[a-f0-9]{64}$/.test(binding)||target.origin!==location.origin||target.username||target.password||target.hash||
    target.pathname!==new URL('phone-call-window-v1.php',new URL(cfg.urlbase||'/',location.origin)).pathname||target.searchParams.get('owner')!==String(owner))return;
  let delegated=false;try{delegated=parent!==window&&parent.location.origin===location.origin&&parent.VP3PhoneWorkspaceV1?.ownerId===owner;}catch{}
  const supported=!delegated&&isSecureContext&&typeof BroadcastChannel==='function'&&typeof navigator.locks?.request==='function'&&typeof crypto.subtle?.digest==='function';
  let mode=supported?role:'unsupported',channel=null,key='',windowName='',runtime=null,closed=false,releaseLock=null,ownerHeld=false;
  let remote=null,lastSeen=0,sequence=0,receivedSequence=-1,error='',timer=0,callWindow=null,pendingReview=null,hostId=uid(),epoch=0;
  const clientId=uid(),pending=new Map(),receipts=new Map();
  const status=document.getElementById('phoneCallWindowStatus');
  let settle;const ready=new Promise(resolve=>{settle=resolve;});
  function message(value){error=String(value||'').slice(0,240);if(status)status.textContent=error;changed();}
  function envelope(type,values={}){return {protocol:'vp3-phone-window-v1',owner,type,...values};}
  function post(type,values={}){try{channel?.postMessage(envelope(type,values));}catch{}}
  function recent(){try{return remote!==null&&Date.now()-lastSeen<15000&&Number(localStorage.getItem('vp3-phone-window-epoch:'+owner+':'+key))===remote.epoch;}catch{return false;}}
  function snapshot(){return {mode,error,reachable:recent(),registered:recent()&&remote.registered===true,muted:recent()&&remote.muted===true,
    attention:recent()?remote.attention:null,everConnected:remote!==null};}
  function changed(){window.dispatchEvent(new CustomEvent('vp3:phone-continuity-changed',{detail:snapshot()}));}
  function validAttention(a){return a===null||a&&a.owner_id===owner&&id(a.call_id)&&typeof a.caller==='string'&&a.caller.length<=180&&
    typeof a.incoming==='boolean'&&typeof a.busy==='boolean'&&['ringing','connecting','active'].includes(a.phase)&&positive(a.conversation_id)&&integer(a.agent_id);}
  function validState(s){return s&&s.owner_id===owner&&typeof s.authorized==='boolean'&&typeof s.registered==='boolean'&&typeof s.muted==='boolean'&&integer(s.generation)&&integer(s.call_epoch)&&integer(s.number_id)&&
    scope(s.scope)!==null&&validAttention(s.attention);}
  function emit(){
    if(role!=='owner'||!ownerHeld||closed||!runtime)return;
    const s=runtime.continuitySnapshot();if(!validState(s))return;
    post('state',{host_id:hostId,epoch,at:Date.now(),sequence:++sequence,state:s});
    if(status)status.textContent=s.authorized?(s.attention?'Call stays in this window while you browse VP3.':'Keep this window open. Enable calls below to receive calls while browsing.'):'Phone access changed. Reopen Phone from VP3.';
  }
  function expected(s){return {generation:s.generation,call_epoch:s.call_epoch,number_id:s.number_id,call_id:s.attention?.call_id||null};}
  function matches(s,e){return e&&s.authorized&&e.generation===s.generation&&e.call_epoch===s.call_epoch&&e.number_id===s.number_id&&e.call_id===(s.attention?.call_id||null);}
  function reply(request,ok,note=''){post('reply',{host_id:hostId,client_id:request.client_id,request_id:request.request_id,ok,error:note});}
  async function command(data){
    if(role!=='owner'||!ownerHeld||!runtime||data.host_id!==hostId||!id(data.client_id)||!id(data.request_id)||
      !Number.isSafeInteger(data.at)||data.at>Date.now()+1000||Date.now()-data.at>5000||
      !['answer','decline','hangup','mute','disable','review','show'].includes(data.action))return;
    const receiptKey=data.client_id+data.request_id;
    if(receipts.has(receiptKey)){const old=await receipts.get(receiptKey);reply(data,old.ok,old.error);return;}
    const work=(async()=>{
      const s=runtime.continuitySnapshot();
      if(!validState(s)||!matches(s,data.expected))return {ok:false,error:'The call changed. Check the current Phone window.'};
      try{
        if(data.action==='show'){runtime.open();return {ok:true};}
        if(data.action==='review'){
          if(s.attention)return {ok:false,error:'Finish the current call before reviewing another.'};
          return {ok:await runtime.continuityReview(data.payload)===true,error:''};
        }
        if(data.action==='mute'&&typeof data.muted!=='boolean')return {ok:false,error:'The call control could not be verified.'};
        return {ok:await runtime.continuityControl(data.action,s.attention?.call_id||null,data.muted)===true,error:''};
      }catch{return {ok:false,error:'The control could not be confirmed. Open the Phone window.'};}
    })();
    receipts.set(receiptKey,work);if(receipts.size>128)receipts.delete(receipts.keys().next().value);
    const result=await work;reply(data,result.ok,result.error);emit();
  }
  function receive(event){
    const d=event.data;if(!d||d.protocol!=='vp3-phone-window-v1'||d.owner!==owner||closed)return;
    if(role==='owner'){
      if(d.type==='probe')emit();
      if(d.type==='command')void command(d);
      if(d.type==='session_end'&&ownerHeld){runtime?.dispose();emit();shutdown();}
      return;
    }
    if(d.type==='state'&&id(d.host_id)&&integer(d.sequence)&&validState(d.state)){
      let currentEpoch=0;try{currentEpoch=Number(localStorage.getItem('vp3-phone-window-epoch:'+owner+':'+key));}catch{return;}
      if(d.epoch!==currentEpoch||!positive(d.epoch)||!Number.isSafeInteger(d.at)||d.at>Date.now()+1000||Date.now()-d.at>5000)return;
      if(remote&&remote.host_id!==d.host_id&&recent())return;
      if(remote?.host_id===d.host_id&&d.sequence<=receivedSequence)return;
      if(remote?.host_id!==d.host_id)receivedSequence=-1;
      const changedOwner=remote?.host_id!==d.host_id;
      remote={...d.state,host_id:d.host_id,epoch:d.epoch};receivedSequence=d.sequence;lastSeen=Date.now();if(changedOwner)error='';changed();
      if(!remote.authorized){remote=null;message('Phone access changed. Reopen Phone from VP3.');return;}
      if(pendingReview){const review=pendingReview;pendingReview=null;if(review.until>Date.now())void request('review',review.payload);}
    }
    if(d.type==='reply'&&d.client_id===clientId&&remote?.host_id===d.host_id&&id(d.request_id)){
      const waiter=pending.get(d.request_id);if(!waiter)return;pending.delete(d.request_id);clearTimeout(waiter.timer);
      if(!d.ok)message(typeof d.error==='string'&&d.error?d.error:'The control could not be confirmed. Open the Phone window.');
      waiter.resolve(d.ok===true);
    }
    if(d.type==='closed'&&d.host_id===remote?.host_id){remote=null;lastSeen=0;message('The calling window closed. Open it again to enable calls.');}
  }
  function activation(){return navigator.userActivation?.isActive===true;}
  function focusWindow(value){
    if(mode==='unsupported'){message('This browser cannot keep a persistent calling window. Use an up-to-date browser.');return false;}
    if(mode!=='view'||!key||!activation()){message(key?'Use a Phone button to open the calling window.':'Phone is still loading. Try again.');return false;}
    const requested=scope(value)||{agent_id:0,conversation_id:0};
    try{
      callWindow=window.open('',windowName,'popup,width=540,height=820,resizable=yes,scrollbars=yes');
      if(!callWindow){message('Your browser blocked the calling window. Allow pop-ups for this site, then try again.');return false;}
      const current=callWindow.location.href;
      if(current==='about:blank'){
        const url=new URL(target);url.searchParams.set('agent_id',requested.agent_id);url.searchParams.set('conversation_id',requested.conversation_id);
        callWindow.location.replace(url.href);
      }else if(callWindow.location.origin!==location.origin||callWindow.location.pathname!==target.pathname){message('The calling window changed. Close it and reopen Phone from VP3.');return false;}
      callWindow.focus();post('probe');return true;
    }catch{message('The calling window could not be reached. Reopen Phone from VP3.');return false;}
  }
  function request(action,payload=null,muted){
    if(!recent()||!remote.authorized){message('Call status is unconfirmed. Open the Phone window.');return Promise.resolve(false);}
    const requestId=uid(),state=remote;
    error='';changed();
    return new Promise(resolve=>{
      const timeout=setTimeout(()=>{pending.delete(requestId);message('The control could not be confirmed. Open the Phone window.');resolve(false);post('probe');},5000);
      pending.set(requestId,{resolve,timer:timeout});post('command',{host_id:state.host_id,client_id:clientId,request_id:requestId,action,at:Date.now(),expected:expected(state),payload,...(typeof muted==='boolean'?{muted}:{})});
    });
  }
  function control(action,callId,muted){
    if(!activation()||!['answer','decline','hangup','mute','disable'].includes(action))return Promise.resolve(false);
    if(action!=='disable'&&(!id(callId)||remote?.attention?.call_id!==callId)){message('This call changed. Check the current Phone window.');return Promise.resolve(false);}
    return request(action,null,muted);
  }
  function review(payload){
    if(!payload||!scope(payload.scope)||!focusWindow(payload.scope))return false;
    if(recent())void request('review',payload);
    else pendingReview={payload,until:Date.now()+60000};
    return true;
  }
  function shutdown(){
    if(closed)return;if(role==='owner'&&ownerHeld)post('closed',{host_id:hostId});closed=true;clearInterval(timer);
    for(const p of pending.values()){clearTimeout(p.timer);p.resolve(false);}pending.clear();pendingReview=null;
    channel?.close();channel=null;releaseLock?.();releaseLock=null;ownerHeld=false;
  }
  function revoke(){post('session_end');if(role==='owner')runtime?.dispose();shutdown();remote=null;lastSeen=0;changed();}
  function attach(value){runtime=value;if(role==='owner'){runtime.open();emit();}}
  window.VP3PhoneContinuityV1=Object.freeze({ready,isView:()=>role==='view'&&!delegated,snapshot,focus:focusWindow,review,control,attach,revoke,detach:shutdown,publish:emit});
  if(!supported){message(delegated?'':'This browser cannot keep a persistent calling window. Use an up-to-date browser.');settle(false);return;}
  if(role==='owner'&&top!==window){settle(false);return;}
  void (async()=>{
    try{
      key=[...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(binding+'\n'+target.pathname)))].map(n=>n.toString(16).padStart(2,'0')).join('');
      windowName='vp3-phone-'+owner+'-'+key.slice(0,24);
      channel=new BroadcastChannel('vp3-phone-window:'+owner+':'+key);channel.onmessage=receive;
      if(role==='owner'){
        void navigator.locks.request('vp3-phone-window:'+owner+':'+key,{ifAvailable:true},async lock=>{
          if(!lock){message('Phone is already open in another window. Return to that window to keep the call connected.');settle(false);return;}
          const epochKey='vp3-phone-window-epoch:'+owner+':'+key;
          try{epoch=Number(localStorage.getItem(epochKey)||0)+1;if(!positive(epoch))throw Error();localStorage.setItem(epochKey,String(epoch));if(Number(localStorage.getItem(epochKey))!==epoch)throw Error();}
          catch{message('Phone could not reserve its calling window. Check browser storage and reopen it.');settle(false);return;}
          window.name=windowName;ownerHeld=true;settle(true);await new Promise(resolve=>{releaseLock=resolve;});
        }).catch(()=>{message('Phone could not reserve its calling window. Reopen it from VP3.');settle(false);});
        timer=setInterval(emit,2500);
      }else{
        settle(true);post('probe');timer=setInterval(()=>{post('probe');if(remote&&!recent())changed();},5000);
      }
      window.addEventListener('vp3:phone-attention-changed',emit);
      document.addEventListener('visibilitychange',()=>{if(!document.hidden){post('probe');emit();}});
      window.addEventListener('focus',()=>{post('probe');emit();});
      window.addEventListener('pagehide',shutdown,{once:true});
      window.addEventListener('stonefellow:session-expired',revoke);window.addEventListener('stonefellow:logout',revoke);
      changed();
    }catch{mode='unsupported';message('Persistent calling could not start. Reload VP3 or check browser storage.');settle(false);}
  })();
})();
