(() => {
  'use strict';
  const cfg=window.STONEFELLOW_PROFILE_AGENT,shell=document.getElementById('profileAgentShell');
  if(!cfg?.phoneEndpoint||!shell||shell.querySelector('[data-profile-phone]'))return;
  let endpoint;
  try{endpoint=new URL(cfg.phoneEndpoint,location.href);if(endpoint.origin!==location.origin)return;}catch(_){return;}
  const CONTRACT='vp3.profile-browser-phone.v1';
  const states=new Set(['prepared','dialing','ringing','answered','completed','busy','no_answer','failed','canceled','expired']);
  const terminals=new Set(['completed','busy','no_answer','failed','canceled','expired']);
  const owner=String(cfg.ownerName||'the profile owner');
  const host=document.createElement('div');host.className='profile-phone';host.dataset.profilePhone='';host.hidden=true;
  const actions=document.createElement('div');actions.className='profile-phone-actions';
  const start=document.createElement('button');start.type='button';start.className='profile-phone-browser';start.dataset.profilePhoneStart='';start.textContent='Call in browser';start.setAttribute('aria-label','Call '+owner+' in browser');start.hidden=true;
  const link=document.createElement('a');link.className='profile-phone-link';link.textContent='Call by phone';link.setAttribute('aria-label','Call '+owner+' by phone');
  const notice=document.createElement('span');notice.className='profile-phone-note';notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');
  const controls=document.createElement('div');controls.className='profile-phone-controls';controls.hidden=true;
  const mute=document.createElement('button');mute.type='button';mute.className='profile-phone-mute';mute.textContent='Mute';mute.setAttribute('aria-pressed','false');
  const end=document.createElement('button');end.type='button';end.className='profile-phone-end';end.textContent='Hang up';
  const recheck=document.createElement('button');recheck.type='button';recheck.className='profile-phone-recheck';recheck.textContent='Check call status';recheck.hidden=true;
  actions.append(start,link);controls.append(mute,end,recheck);host.append(actions,notice,controls);
  const sections=shell.querySelector('.profile-agent-sections');if(sections)sections.after(host);else shell.prepend(host);
  let pending=null,availabilityEpoch=0,approved='',alive=true,checking=false,browserEnabled=false,browserReason='',adapter=null;
  let operation=0,clickEpoch=0,entry=null,sdkPromise=null,tickTimer=0,lastBusy=null,nativeBusy=false,otherPhoneBusy=false,lastNotice='';
  const now=()=>performance.now();
  const positive=value=>Number.isSafeInteger(value)&&value>0;
  const id=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
  const requestId=()=>{const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);return Array.from(bytes,value=>value.toString(16).padStart(2,'0')).join('');};
  function context(){return adapter?.context?.()||{key:'',id:0,active:false,ready:false};}
  function same(value){const current=context();return alive&&entry===value&&!value.closed&&current.active&&current.key===value.key&&value.operation===operation;}
  function signal(){const busy=Boolean(entry&&!entry.localStopped);if(busy===lastBusy)return;lastBusy=busy;
    for(const name of ['vp3:profile-call-state','vp3:phone-call-state'])window.dispatchEvent(new CustomEvent(name,{detail:{busy,source:'profile-phone-v1'}}));
  }
  function terminalText(call){return ({completed:'Call ended.',busy:owner+' is busy. You can send a message in Chat.',no_answer:'There was no answer. You can send a message in Chat.',failed:'The phone connection failed. You can try again.',canceled:'Call canceled.',expired:'The call request expired. You can try again.'})[call?.state]||'Call ended.';}
  function statusText(value){
    if(value.message)return value.message;
    if(value.localStopped)return 'Your microphone is off. Checking that the call ended…';
    if(value.phase==='preparing')return 'Preparing your call…';
    if(value.phase==='loading')return 'Loading browser calling…';
    if(value.phase==='permission')return 'Allow microphone access to start your call.';
    if(value.phase==='reconnecting')return 'Connection interrupted. Reconnecting while call access is current…';
    const call=value.info;
    if(value.accepted){
      if(call?.route_kind==='owner'&&call.answered)return 'Connected to '+owner+'.';
      if(call?.route_kind==='agent')return 'Connected to '+owner+'’s phone Agent.';
      if(call?.route_kind==='voicemail')return 'Connected to voicemail.';
      return 'Phone service connected. Waiting for '+owner+'…';
    }
    if(call?.answered&&call.route_kind==='owner')return owner+' answered. Connecting your audio…';
    if(value.phase==='ringing'||call?.state==='ringing')return 'Ringing '+owner+'…';
    return 'Connecting your call…';
  }
  function render(){
    const current=context(),busy=Boolean(entry);
    host.hidden=!approved&&!browserEnabled&&!busy&&!lastNotice;
    start.hidden=!browserEnabled&&!busy;
    start.disabled=busy||checking||!browserEnabled||!current.active||!current.ready||nativeBusy||otherPhoneBusy;
    link.setAttribute('aria-disabled',String(busy||checking||!approved));
    if(approved&&!busy&&!checking)link.href=approved;else link.removeAttribute('href');
    controls.hidden=!busy;
    mute.hidden=!busy||entry.localStopped;mute.disabled=!entry?.call||!entry?.hasMicrophone;
    mute.textContent=entry?.muted?'Unmute':'Mute';mute.setAttribute('aria-pressed',String(Boolean(entry?.muted)));
    end.hidden=!busy||entry.localStopped;
    recheck.hidden=!busy||!entry.localStopped;recheck.disabled=Boolean(entry?.pollBusy||entry?.endBusy);
    host.dataset.callState=entry?.localStopped?'ending':entry?.phase||'idle';
    let text=entry?statusText(entry):lastNotice;
    if(!text){
      if(nativeBusy||otherPhoneBusy)text='Finish your current call before calling here.';
      else if(browserEnabled)text='Use your microphone here, or open your phone app.';
      else text=browserReason||'Opens your phone app. Browser audio and video are in Chat options.';
    }
    if(notice.textContent!==text)notice.textContent=text;
    signal();
  }
  async function read(){
    pending?.abort();const controller=new AbortController(),epoch=++availabilityEpoch;pending=controller;
    let timer;const url=new URL(endpoint);url.searchParams.set('action','public');url.searchParams.set('username',cfg.username);
    try{
      const response=await Promise.race([(async()=>{const result=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal});return {result,data:await result.json()};})(),new Promise((_,reject)=>{timer=setTimeout(()=>{controller.abort();reject(new Error('Phone availability timed out.'));},10000);})]);
      if(!alive||epoch!==availabilityEpoch)return null;
      const {result,data}=response;
      if(!result.ok||data?.ok!==true||data.contract!=='vp3.profile-phone.v1')throw new Error('Phone availability could not be checked.');
      if(data.enabled!==true){approved='';browserEnabled=false;browserReason='';lastNotice='';if(entry)invalidate('Calling is no longer available.');render();return null;}
      if(typeof data.number!=='string'||!/^\+[1-9][0-9]{7,14}$/.test(data.number)||data.href!=='tel:'+data.number)throw new Error('Phone availability could not be checked.');
      approved=data.href;
      browserEnabled=Boolean(cfg.browserPhoneEndpoint&&data.browser?.contract===CONTRACT&&data.browser.enabled===true);
      browserReason=data.browser?.contract===CONTRACT&&typeof data.browser.reason==='string'?data.browser.reason.slice(0,300):'';
      lastNotice='';
      if(entry&&(!browserEnabled||entry.approved!==approved))invalidate('The approved phone connection changed. Your microphone is off.');
      render();return approved;
    }catch(_){
      if(alive&&epoch===availabilityEpoch){approved='';browserEnabled=false;if(!host.hidden||entry)lastNotice='Calling could not be checked. Open the chat again to retry.';render();}
      return null;
    }finally{clearTimeout(timer);if(pending===controller)pending=null;}
  }
  function sdkUrl(){
    const expected=new URL('../assets/twilio-voice-2.18.5.min.js',endpoint),url=new URL(cfg.phoneSdkUrl||expected.href,location.href);
    if(url.origin!==location.origin||url.href!==expected.href||url.username||url.password)throw new Error('The browser calling library is unavailable. Reload this profile.');
    return url.href;
  }
  function loadSdk(){
    const url=sdkUrl();
    if(window.Twilio?.Device)return Promise.resolve(window.Twilio);
    if(sdkPromise)return sdkPromise;
    sdkPromise=new Promise((resolve,reject)=>{
      const script=document.createElement('script');script.src=url;script.async=true;script.crossOrigin='anonymous';
      if(cfg.phoneSdkIntegrity){script.integrity=String(cfg.phoneSdkIntegrity);script.referrerPolicy='no-referrer';}
      let settled=false;
      const finish=(error)=>{if(settled)return;settled=true;clearTimeout(timer);script.onload=script.onerror=null;if(error){script.remove();reject(error);}else resolve(window.Twilio);};
      const timer=setTimeout(()=>finish(new Error('Browser calling took too long to load. Try again.')),15000);
      script.onload=()=>finish(window.Twilio?.Device?null:new Error('Browser calling could not be loaded.'));
      script.onerror=()=>finish(new Error('Browser calling could not be loaded. Check your connection and try again.'));
      document.head.appendChild(script);
    }).catch(error=>{sdkPromise=null;throw error;});
    return sdkPromise;
  }
  function projection(data,value,started,{prepare=false}={}){
    if(data?.contract!==CONTRACT||!Number.isSafeInteger(data.conversation_id)||data.conversation_id<0||!positive(data.server_now_ms)||!positive(data.valid_until_ms))throw new Error('The call status could not be verified.');
    const span=data.valid_until_ms-data.server_now_ms;
    if(span<1||span>30000||span-(now()-started)<=0)throw new Error('Call authorization expired. Your microphone is off.');
    if(value.cid>0&&data.conversation_id!==value.cid)throw new Error('The visitor conversation changed.');
    const call=data.call;
    if(call!==null){
      if(!call||!id(call.id)||!states.has(call.state)||typeof call.terminal!=='boolean'||call.terminal!==terminals.has(call.state)||typeof call.answered!=='boolean'||!Number.isSafeInteger(call.duration_seconds)||call.duration_seconds<0||!['pending','owner','agent','voicemail'].includes(call.route_kind)||(call.end_requested!==undefined&&typeof call.end_requested!=='boolean')||!positive(data.conversation_id)||(value.info&&call.id!==value.info.id))throw new Error('The call status could not be verified.');
    }
    if(prepare){
      if(!call||call.terminal||call.end_requested||typeof data.token!=='string'||data.token.length<20||data.token.length>32768||!positive(data.expires_at)||data.expires_at*1000<=data.server_now_ms+1000||! /^[a-f0-9]{64}$/.test(data.connect_params?.profile_call_intent||''))throw new Error('The call could not be authorized.');
    }
    value.deadline=now()+span-(now()-started);value.info=call;return data;
  }
  function stopCall(call){
    if(!call)return;
    for(const track of call.getLocalStream?.()?.getTracks?.()||[])try{track.stop();}catch(_){}
    try{call.disconnect();}catch(_){}
  }
  function stopLocal(value){
    if(value.localStopped)return;
    value.localStopped=true;value.accepted=false;
    // Clear identity before disconnect events can re-enter the terminal handler.
    stopCall(value.call);value.ticket?.release();value.ticket=null;
    try{value.device?.disconnectAll();}catch(_){}
    try{value.device?.destroy();}catch(_){}
    value.device=null;value.hasMicrophone=false;render();
  }
  function releasePrepare(value){if(!value?.preparing)return;value.preparing=false;adapter.endPrepare();}
  function adopt(value,data){
    if(data.conversation_id>0&&(value.cid===0||value.preparing)){adapter.adopt(data.conversation_id);value.cid=data.conversation_id;value.key=context().key;}
    if(value.cid>0)releasePrepare(value);
  }
  function finish(value,message){
    if(entry!==value)return;
    stopLocal(value);releasePrepare(value);value.closed=true;entry=null;operation++;clearInterval(tickTimer);tickTimer=0;
    lastNotice=message||terminalText(value.info);render();
  }
  function callPayload(value){return {conversation_id:value.cid,client_request_id:value.requestId,...(value.info?{call_id:value.info.id}:{})};}
  async function endRemote(value){
    if(value.endBusy||!adapter)return;value.endBusy=true;render();
    const started=now();
    try{
      const data=await adapter.request('end',callPayload(value),'POST');
      if(!same(value))return;
      projection(data,value,started);
      adopt(value,data);
      if(data.call===null||data.call.terminal){finish(value,value.finalMessage||terminalText(data.call));return;}
      value.message=value.finalMessage?'Your microphone is off. '+value.finalMessage:'Your microphone is off. Checking that the call ended…';
    }catch(error){
      if(same(value)&&!error.stale)value.message='Your microphone is off. The call ending could not be confirmed. Check call status before trying again.';
    }finally{value.endBusy=false;if(entry===value)render();}
  }
  function endCall(message='Call ended.'){
    const value=entry;if(!value)return;
    value.finalMessage=message;stopLocal(value);void endRemote(value);
  }
  function invalidate(message='The visitor session changed. Your microphone is off.'){
    const value=entry;
    if(value){stopLocal(value);void endRemote(value);releasePrepare(value);value.closed=true;entry=null;operation++;clearInterval(tickTimer);tickTimer=0;}
    if(value)lastNotice=message;render();
  }
  async function pollCall(value){
    if(!same(value)||value.pollBusy||value.endBusy)return;
    value.pollBusy=true;value.lastPoll=now();const started=now();render();
    try{
      const data=await adapter.request('state',callPayload(value),'GET');
      if(!same(value))return;
      projection(data,value,started);
      adopt(value,data);
      if(data.call===null){
        if(value.localStopped){finish(value,value.finalMessage||'Call canceled.');return;}
        throw new Error('The call is no longer available. Your microphone is off.');
      }
      if(data.call.terminal){finish(value,value.finalMessage||terminalText(data.call));return;}
      if(data.call.end_requested){stopLocal(value);value.message='Your microphone is off. The call is ending…';}
      else if(value.localStopped){value.message='Your microphone is off. Checking that the call ended…';void endRemote(value);}
      else{value.message='';if(data.call.state==='ringing'&&!value.accepted)value.phase='ringing';}
    }catch(error){
      if(same(value)&&!error.stale){
        if([403,404,409,419,422].includes(error.status)){endCall('Calling is no longer available for this visitor session.');}
        else if(value.localStopped)value.message='Your microphone is off. Call status is unavailable. Check again before trying another call.';
        else value.message='Checking your call connection…';
      }
    }finally{value.pollBusy=false;if(entry===value)render();}
  }
  function ownStream(value){
    const stream=value.call?.getLocalStream?.();
    if(!stream)return;
    if(!same(value)||value.localStopped||!value.ticket?.isCurrent()){for(const track of stream.getTracks?.()||[])try{track.stop();}catch(_){}return;}
    value.ticket.ownStream(stream);
    const live=(stream.getTracks?.()||[]).some(track=>track.readyState!=='ended');
    if(live&&!value.hasMicrophone){value.hasMicrophone=true;if(value.phase==='permission')value.phase='connecting';render();}
  }
  function errorText(error){
    if(['NotAllowedError','PermissionDeniedError'].includes(error?.name)||[31208,31401].includes(Number(error?.code)))return 'Microphone access was not allowed. Enable it in your browser before trying again.';
    if(['NotFoundError','DevicesNotFoundError'].includes(error?.name))return 'No microphone was found. Connect one before trying again.';
    return 'The phone connection failed. Your microphone is off. You can try again.';
  }
  function bindCall(value,call){
    value.call=call;
    const current=()=>same(value)&&!value.localStopped&&value.ticket?.isCurrent();
    call.on('accept',()=>{if(!current()){stopCall(call);return;}value.accepted=true;value.phase='connected';value.message='';ownStream(value);render();void pollCall(value);});
    call.on('ringing',()=>{if(current()){value.phase='ringing';value.message='';ownStream(value);render();}});
    call.on('mute',muted=>{if(current()){value.muted=Boolean(muted);render();}});
    call.on('reconnecting',()=>{if(current()){value.phase='reconnecting';render();}});
    call.on('reconnected',()=>{if(current()){value.phase=value.accepted?'connected':'connecting';value.message='';render();}});
    for(const event of ['disconnect','cancel','reject','error'])call.on(event,error=>{
      if(!current()){stopCallTracks(call);return;}
      // Let the verified terminal outcome explain busy/no-answer rather than
      // replacing it with a generic SDK disconnect message.
      endCall(event==='error'?errorText(error):'');
    });
    if(call.status?.()==='open'){value.accepted=true;value.phase='connected';}
    ownStream(value);render();
  }
  function stopCallTracks(call){for(const track of call?.getLocalStream?.()?.getTracks?.()||[])try{track.stop();}catch(_){};}
  function tick(){
    const value=entry;if(!value)return;
    if(!same(value)){invalidate('The visitor session changed. Your microphone is off.');return;}
    if(!value.localStopped){
      if(value.ticket&&!value.ticket.isCurrent()){endCall('Microphone ownership changed. Your microphone is off.');return;}
      if(value.deadline&&now()>=value.deadline){endCall('Call access could not be renewed. Your microphone is off.');return;}
      ownStream(value);
    }
    if(value.info&&now()-value.lastPoll>=10000)void pollCall(value);
  }
  async function startCall(){
    if(entry||checking||!alive||!browserEnabled||!adapter)return;
    const click=++clickEpoch;checking=true;lastNotice='Checking the current phone line…';render();
    const previous=approved;
    try{
      const approvedNow=await read();
      if(!alive||!approvedNow||!browserEnabled)return;
      if(previous!==approvedNow){lastNotice='The phone line changed. Select Call in browser again.';return;}
      const current=context();
      if(!current.active||!current.ready||nativeBusy||otherPhoneBusy||!adapter.beginPrepare()){lastNotice='Finish the current chat action or call, then try again.';return;}
      let value;
      try{
        const clientRequestId=requestId(),ticket=window.StonefellowVoiceLeaseV122?.acquireCapture?.('profile-phone');
        if(!ticket)throw new Error('Another browser surface is using the microphone. Stop it there before calling.');
        value={operation:++operation,key:current.key,cid:current.id,approved,requestId:clientRequestId,info:null,phase:'preparing',preparing:true,ticket,call:null,device:null,closed:false,localStopped:false,accepted:false,hasMicrophone:false,muted:false,deadline:0,lastPoll:now(),pollBusy:false,endBusy:false,message:'',finalMessage:''};
        entry=value;checking=false;lastNotice='';render();clearInterval(tickTimer);tickTimer=setInterval(tick,250);
        const started=now(),data=await adapter.request('prepare',{client_request_id:value.requestId},'POST');
        if(!same(value)||value.localStopped){void endRemote(value);return;}
        projection(data,value,started,{prepare:true});
        adopt(value,data);
        value.phase='loading';render();
        const sdk=await loadSdk();
        if(!same(value)||value.localStopped)return;
        const device=new sdk.Device(data.token,{logLevel:0,allowIncomingWhileBusy:false,closeProtection:true});value.device=device;
        device.on('incoming',call=>{try{call.reject();}catch(_){stopCall(call);}});
        device.on('error',error=>{if(same(value)&&!value.localStopped)endCall(errorText(error));});
        value.phase='permission';render();
        const call=await device.connect({params:{profile_call_intent:data.connect_params.profile_call_intent},rtcConstraints:{audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true}}});
        if(!same(value)||value.localStopped){stopCall(call);return;}
        bindCall(value,call);void pollCall(value);
      }catch(error){
        if(value&&entry===value&&!value.closed){endCall(error?.message==='Another browser surface is using the microphone. Stop it there before calling.'?error.message:error?.status?error.message:error?.name==='NotAllowedError'?errorText(error):'The call could not be started. Your microphone is off. Check call status before trying again.');}
        else if(!value)lastNotice=error.message;
      }finally{
        // A lost zero-conversation admission must first recover its exact
        // canonical ID (or confirm no admission) before another text can create
        // a competing thread. Status/end recovery releases this small gate.
        if(!value)adapter.endPrepare();else if(value.cid>0||value.closed)releasePrepare(value);
      }
    }finally{if(click===clickEpoch)checking=false;render();}
  }
  start.addEventListener('click',event=>{if(event.isTrusted)void startCall();});
  end.addEventListener('click',()=>endCall('Call ended.'));
  recheck.addEventListener('click',()=>{if(entry)void pollCall(entry);});
  mute.addEventListener('click',()=>{
    const value=entry;if(!value||!same(value)||value.localStopped||!value.call||!value.hasMicrophone)return;
    try{value.call.mute(!value.muted);value.muted=typeof value.call.isMuted==='function'?Boolean(value.call.isMuted()):!value.muted;render();}catch(_){value.message='Mute could not be changed. Hang up if you need to stop your microphone.';render();}
  });
  link.addEventListener('click',async event=>{
    event.preventDefault();if(!event.isTrusted||checking||entry||!alive)return;const click=++clickEpoch;checking=true;lastNotice='Checking the current phone line…';render();
    const was=approved;
    try{const current=await read();if(current&&current===was&&alive){location.href=current;lastNotice='Continue in your phone app to place the call.';}else if(current)lastNotice='The phone line changed. Select Call by phone again.';}
    finally{if(click===clickEpoch)checking=false;render();}
  });
  window.VP3ProfilePhoneV1={bindVisitor(options){if(adapter)return null;adapter=options;render();return {refresh:read,invalidate,stop:invalidate,isBusy:()=>Boolean(entry)};}};
  window.addEventListener('vp3:profile-call-state',event=>{if(event.detail?.source==='profile-phone-v1')return;nativeBusy=event.detail?.busy===true;if(nativeBusy&&entry)endCall('Your browser phone call stopped for the direct call.');render();});
  window.addEventListener('vp3:phone-call-state',event=>{if(event.detail?.source==='profile-phone-v1')return;otherPhoneBusy=event.detail?.busy===true;if(otherPhoneBusy&&entry)endCall('Your browser phone call stopped for the other phone call.');render();});
  window.addEventListener('stonefellow:voice-lease-lost',()=>{if(entry)endCall('Microphone ownership changed. Your microphone is off.');});
  window.addEventListener('vp3:profile-agent-visibility',event=>{if(event.detail?.open){if(!checking)void read();}else invalidate('You closed the call view. Your microphone is off.');});
  window.addEventListener('vp3:profile-agent-open',()=>{if(!checking)void read();});
  window.addEventListener('focus',()=>{if(!shell.hidden&&!checking){void read();if(entry)void pollCall(entry);}});
  window.addEventListener('pagehide',()=>{invalidate('You left the profile. Your microphone is off.');alive=false;availabilityEpoch++;pending?.abort();approved='';link.removeAttribute('href');});
  window.addEventListener('pageshow',event=>{if(event.persisted){alive=true;if(!shell.hidden)void read();}});
  if(!shell.hidden)void read();
})();
