(() => {
  'use strict';
  if(window.VP3ProfileCallsPa3c)return;
  const CONTRACT='vp3.profile-call.v1';
  const STATES=new Set(['offered','preparing','ready','active','ending','ended','declined','cancelled','expired','failed']);
  const TERMINAL=new Set(['ended','declined','cancelled','expired','failed']);
  const SDK_URL='https://cdn.jsdelivr.net/npm/livekit-client@2.22.3/dist/livekit-client.umd.min.js';
  const positive=n=>Number.isSafeInteger(n)&&n>0,nonnegative=n=>Number.isSafeInteger(n)&&n>=0;
  const token=s=>typeof s==='string'&&/^[a-f0-9]{32}$/.test(s);
  const time=()=>performance.now();
  const random=()=>{const a=new Uint8Array(16);crypto.getRandomValues(a);return Array.from(a,n=>n.toString(16).padStart(2,'0')).join('');};
  const node=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
  const callFlags=['can_accept','can_decline','can_cancel','can_end','can_join','can_return_to_agent','cleanup_pending','dispatch_due'];
  function projection(raw,role){
    if(!raw||raw.contract!==CONTRACT||raw.role!==role||!['call_id','revision','conversation_id'].every(k=>positive(raw[k]))||!STATES.has(raw.state)||!['audio','video'].includes(raw.mode)||!['owner','visitor'].includes(raw.offered_by))return null;
    if(!callFlags.every(k=>typeof raw[k]==='boolean')||!['server_now_ms','offer_expires_at_ms','authority_valid_until_ms'].every(k=>nonnegative(raw[k])))return null;
    if(typeof raw.reason!=='string'||raw.reason.length>100)return null;
    for(const key of ['connection_verified','owner_connected','visitor_connected'])if(raw[key]!==undefined&&typeof raw[key]!=='boolean')return null;
    return {...raw,connection_verified:raw.connection_verified===true,owner_connected:raw.owner_connected===true,visitor_connected:raw.visitor_connected===true};
  }
  function messageFor(error){
    const code=String(error?.code||'');
    if(code==='PROFILE_CALL_IN_USE')return 'This call is already open in another tab. Keep using that tab.';
    if(code==='PROFILE_SESSION_CHANGED')return 'Your visitor session changed. Refresh the profile before calling.';
    if(code==='PROFILE_CALL_PENDING'||code==='PROFILE_CALL_WORK_PENDING')return 'The call service is finishing another request. Check the status, then try Join again.';
    if(code==='PROFILE_CALL_CHANGED'||code==='PROFILE_CALL_CONFLICT')return 'This call changed. Its current status is being checked. You can try Join again when it is ready.';
    if(/CONFIGURATION|NOT_CONFIGURED|UNAVAILABLE|ROOM_UNSAFE/.test(code))return 'Live calls are unavailable. You can keep messaging.';
    if(error?.name==='NotAllowedError'||error?.name==='PermissionDeniedError')return 'Microphone or camera permission was denied. You can keep messaging.';
    if(error?.name==='NotFoundError'||error?.name==='NotReadableError'||error?.name==='OverconstrainedError')return 'The selected microphone or camera is unavailable. You can keep messaging.';
    return 'The call result could not be confirmed. You can keep messaging while its status is checked.';
  }
  async function bounded(work,ms=12000){
    let timer;const deadline=new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Call request timed out.')),ms);});
    try{return await Promise.race([work,deadline]);}finally{clearTimeout(timer);}
  }
  let sdkPromise=null;
  function loadSdk(){
    if(window.LivekitClient?.Room)return Promise.resolve(window.LivekitClient);
    if(sdkPromise)return sdkPromise;
    sdkPromise=bounded(new Promise((resolve,reject)=>{const script=node('script');script.src=SDK_URL;script.crossOrigin='anonymous';script.onload=()=>window.LivekitClient?.Room?resolve(window.LivekitClient):reject(new Error('Media client unavailable.'));script.onerror=()=>reject(new Error('Media client unavailable.'));document.head.appendChild(script);})).catch(error=>{sdkPromise=null;throw error;});
    return sdkPromise;
  }
  let activeMedia=null,mediaGeneration=0;
  const clientInstance=random();
  class MediaSession {
    constructor(surface,record,consent){this.surface=surface;this.record=record;this.snapshot=surface.capture();this.consent=consent;this.generation=++mediaGeneration;this.room=null;this.ticket=null;this.closed=false;this.phase='preparing';this.deadline=0;this.auth=null;this.tracks=new Set();this.attached=new Map();this.timer=0;this.heartbeatBusy=false;this.lastHeartbeat=time();record.joinRequests??={};this.joinRequest=record.joinRequests[String(consent.camera)]||(record.joinRequests[String(consent.camera)]=random());this.mediaBusy=false;this.ownedMedia=false;}
    current(){return !this.closed&&activeMedia===this&&this.generation===mediaGeneration&&this.surface.current(this.snapshot)&&(!this.deadline||time()<this.deadline);}
    controls(){this.record.surface.render(this.record);}
    authority(call,sentAt){
      const remaining=call.authority_valid_until_ms-call.server_now_ms,deadline=sentAt+remaining;
      // Network/provider time consumes the lease. Receiving a delayed reply
      // must never add that delay back onto the server's authority deadline.
      if(remaining<=0||remaining>30000||!Number.isFinite(sentAt)||sentAt>time()||deadline<=time())throw new Error('Call authority expired.');
      this.deadline=deadline;this.lastHeartbeat=time();
    }
    async start(takeover=false){
      if(!this.current()||this.startBusy)return;
      this.startBusy=true;let target=null;
      this.phase='preparing';this.controls();
      try{
        if(!this.auth){
          const sentAt=time();
          const result=await this.surface.invoke('join',this.record,{request_id:this.joinRequest,client_instance_id:clientInstance,microphone:true,camera:this.consent.camera});
          if(!this.current())return;
          const call=projection(result.call,this.surface.role);
          if(!call||call.call_id!==this.record.call.call_id||call.conversation_id!==this.record.call.conversation_id||result.call_id!==call.call_id||result.revision!==call.revision||result.conversation_id!==call.conversation_id||result.request_id!==this.joinRequest||result.client_instance_id!==clientInstance||result.role!==this.surface.role||result.microphone!==true||result.camera!==this.consent.camera||!token(result.join_intent_id))throw new Error('Call join identity was not confirmed.');
          if(typeof result.participant_token!=='string'||result.participant_token.length<20||result.participant_token.length>16384||typeof result.participant_identity!=='string'||typeof result.peer_identity!=='string'||result.participant_identity===result.peer_identity||!positive(result.expires_at_ms))throw new Error('Call credentials were not confirmed.');
          const url=new URL(result.server_url);if(url.protocol!=='wss:'||url.username||url.password||!/(^|\.)livekit\.cloud$/i.test(url.hostname)||result.expires_at_ms<=call.server_now_ms||result.expires_at_ms>call.server_now_ms+60000)throw new Error('Call service unavailable.');
          this.auth={server_url:url.toString(),participant_token:result.participant_token,participant_identity:result.participant_identity,peer_identity:result.peer_identity,expires_at_ms:result.expires_at_ms};
          this.authority(call,sentAt);this.surface.apply(call);this.timer=setInterval(()=>this.tick(),500);
        }
        const lease=window.StonefellowVoiceLeaseV122;
        if(!lease?.acquireCapture)throw new Error('Microphone ownership is unavailable.');
        if(takeover){this.yieldingCapture=true;try{lease.yield();}finally{this.yieldingCapture=false;}}
        if(!this.current())return;
        const ticket=lease.acquireCapture('profile-call:'+this.record.call.call_id,{takeover});
        if(!this.current()){ticket?.release();return;}
        this.ticket=ticket;
        if(!this.ticket){this.phase='capture_busy';this.controls();return;}
        this.ownedMedia=true;
        const sdk=await loadSdk();if(!this.current()||!this.ticket.isCurrent())return this.close('Call context changed.');
        target=new sdk.Room({adaptiveStream:true,dynacast:true,disconnectOnPageLeave:true});this.room=target;this.bindRoom(target,sdk);
        this.phase='connecting';this.controls();
        const connecting=Promise.resolve(target.connect(this.auth.server_url,this.auth.participant_token,{autoSubscribe:true}));
        void connecting.then(()=>{if(!this.current()||this.room!==target)void this.closeTarget(target);},()=>{if(!this.current()||this.room!==target)void this.closeTarget(target);});
        await bounded(connecting,15000);
        if(!this.current()||this.room!==target)return this.closeTarget(target);
        if(target.localParticipant?.identity&&target.localParticipant.identity!==this.auth.participant_identity)throw new Error('Call participant identity changed.');
        this.attachParticipant(target.localParticipant,true);
        target.remoteParticipants?.forEach(participant=>this.attachParticipant(participant,false));
        if(!this.current())return this.closeTarget(target);
        await target.localParticipant.setMicrophoneEnabled(true);this.ownLocal(target);
        if(!this.current()||this.room!==target)return this.closeTarget(target);
        if(this.consent.camera){await target.localParticipant.setCameraEnabled(true);this.ownLocal(target);if(!this.current()||this.room!==target)return this.closeTarget(target);}
        try{await target.startAudio();}catch(_){this.record.needsSound=true;}
        if(!this.current()||this.room!==target)return this.closeTarget(target);
        this.phase='connected';this.controls();void this.heartbeat();
      }catch(error){if(!this.closed){
        this.record.notice.textContent=messageFor(error);
        // A provider lease conflict or another tab's participant binding does
        // not give this attempted session authority to hang up their call.
        const owned=this.ownedMedia;
        await this.close(owned?'Your microphone and camera are off. Call cleanup is being checked.':'');
        if(owned)void this.surface.endAfterMedia(this.record);else void this.surface.refresh();
      }}
      finally{
        this.startBusy=false;
        // A permission promise can reject after disconnect while still leaving
        // a newly-created publication behind. Always clean this exact Room.
        if(target&&(!this.current()||this.room!==target))await this.closeTarget(target);
      }
    }
    ownLocal(target){target?.localParticipant?.trackPublications?.forEach(pub=>{if(!pub.track)return;this.tracks.add(pub.track);const stream=pub.track.mediaStream;if(stream?.getTracks)this.ticket?.ownStream(stream);});}
    bindRoom(target,sdk){
      const on=(event,handler)=>{if(event)target.on(event,(...args)=>{if(this.current()&&this.room===target)handler(...args);});};
      on(sdk.RoomEvent.TrackSubscribed,(track,_pub,p)=>this.attach(track,p,false));
      on(sdk.RoomEvent.TrackUnsubscribed,track=>this.detach(track));
      if(sdk.RoomEvent.LocalTrackPublished)target.on(sdk.RoomEvent.LocalTrackPublished,(pub,p)=>{
        if(!pub.track)return;this.tracks.add(pub.track);
        if(!this.current()||this.room!==target){try{pub.track.stop();}catch(_){}return;}
        this.ownLocal(target);this.attach(pub.track,p,true);
      });
      on(sdk.RoomEvent.LocalTrackUnpublished,pub=>{if(pub.track)this.detach(pub.track);});
      on(sdk.RoomEvent.ParticipantConnected,p=>{this.attachParticipant(p,false);this.controls();});
      on(sdk.RoomEvent.ParticipantDisconnected,p=>{for(const [track,entry] of this.attached)if(entry.identity===p.identity)this.detach(track);this.controls();});
      on(sdk.RoomEvent.Reconnecting,()=>{this.phase='reconnecting';this.controls();});
      on(sdk.RoomEvent.SignalReconnecting,()=>{this.phase='reconnecting';this.controls();});
      on(sdk.RoomEvent.Reconnected,()=>{this.phase='connected';this.controls();void this.heartbeat();});
      on(sdk.RoomEvent.Disconnected,()=>{void this.close('Call connection ended. Your microphone and camera are off.');void this.surface.refresh();});
      on(sdk.RoomEvent.AudioPlaybackStatusChanged,()=>{if(target.canPlaybackAudio===false){this.record.needsSound=true;this.controls();}});
    }
    attachParticipant(participant,local){if(!participant)return;if(!local&&participant.identity!==this.auth.peer_identity){void this.close('Call participant verification changed. Your microphone and camera are off.');void this.surface.endAfterMedia(this.record);return;}participant.trackPublications?.forEach(pub=>{if(pub.track)this.attach(pub.track,participant,local);});}
    attach(track,participant,local){
      if(!this.current()||!track||!participant||this.attached.has(track))return;
      if(participant.identity!==(local?this.auth.participant_identity:this.auth.peer_identity)){void this.close('Call participant verification changed.');void this.surface.endAfterMedia(this.record);return;}
      if((track.source&&/screen/i.test(String(track.source)))||(track.kind==='video'&&this.record.call.mode!=='video')){void this.close('Unexpected media was stopped.');void this.surface.endAfterMedia(this.record);return;}
      if(track.kind==='audio'&&local)return;
      if(!['audio','video'].includes(track.kind))return;
      const media=track.attach();media.autoplay=true;media.playsInline=true;if(local)media.muted=true;
      media.setAttribute('aria-label',local?'Your camera':track.kind==='audio'?'Live participant audio':'Live participant video');
      const host=local?this.record.localMedia:this.record.remoteMedia;host.appendChild(media);this.attached.set(track,{media,identity:participant.identity});
      if(track.kind==='audio')Promise.resolve(media.play?.()).catch(()=>{if(this.current()){this.record.needsSound=true;this.controls();}});
    }
    detach(track){try{for(const element of track.detach?.()||[])element.remove();}catch(_){}const entry=this.attached.get(track);entry?.media.remove();this.attached.delete(track);}
    async change(kind){
      if(!this.current()||this.phase!=='connected'||this.mediaBusy||!this.room)return;
      const target=this.room;this.mediaBusy=true;this.controls();
      try{if(kind==='camera'){if(this.record.call.mode!=='video'||!this.consent.camera)return;await target.localParticipant.setCameraEnabled(!target.localParticipant.isCameraEnabled);}else await target.localParticipant.setMicrophoneEnabled(!target.localParticipant.isMicrophoneEnabled);this.ownLocal(target);}
      catch(error){if(this.current())this.record.notice.textContent=messageFor(error);}
      finally{this.mediaBusy=false;if(!this.current()||this.room!==target)await this.closeTarget(target);this.controls();}
    }
    async sound(){try{if(this.current()&&this.room){await this.room.startAudio();this.record.needsSound=false;this.controls();}}catch(_){this.record.notice.textContent='Tap Enable sound again, or check your browser audio settings.';}}
    async heartbeat(){
      if(!this.current()||this.heartbeatBusy||!this.auth)return;
      this.heartbeatBusy=true;this.lastHeartbeat=time();
      const sentAt=time();
      try{const result=await this.surface.invoke('heartbeat',this.record,{client_instance_id:clientInstance});if(!this.current())return;const call=projection(result.call,this.surface.role);if(!call||call.call_id!==this.record.call.call_id||call.conversation_id!==this.record.call.conversation_id)throw new Error('Call authority changed.');this.authority(call,sentAt);this.surface.apply(call);}
      catch(error){if([401,403,419].includes(error.status)||error.code==='PROFILE_SESSION_CHANGED'){void this.close('Call access changed. Your microphone and camera are off.');}else if(error.status===409)void this.surface.refresh();}
      finally{this.heartbeatBusy=false;}
    }
    tick(){if(this.closed)return;if(!this.surface.current(this.snapshot)||(!this.ticket?.isCurrent()&&this.phase!=='capture_busy')||(this.deadline&&time()>=this.deadline)){void this.close('Call authorization expired. Your microphone and camera are off.');if(this.ownedMedia)void this.surface.endAfterMedia(this.record);return;}if(time()-this.lastHeartbeat>=10000)void this.heartbeat();}
    observe(call){if(this.closed)return;if(TERMINAL.has(call.state)||call.state==='ending'||/authority|configuration|disabled|unsafe/.test(call.reason)){void this.close(call.cleanup_pending?'Your microphone and camera are off. Service cleanup is pending.':'Call ended. Your microphone and camera are off.');}}
    async closeTarget(target){
      const stop=()=>{target?.localParticipant?.trackPublications?.forEach(pub=>{try{pub.track?.stop();}catch(_){}});for(const track of this.tracks){try{track.stop();}catch(_){}}};stop();
      if(target)try{await bounded(Promise.resolve(target.disconnect()),3000);}catch(_){}finally{stop();}
    }
    async close(message='Your microphone and camera are off.'){
      if(this.closed)return;this.closed=true;clearInterval(this.timer);this.timer=0;this.phase='closed';this.record.localMessage=message;
      const target=this.room;this.room=null;this.auth=null;
      for(const track of [...this.attached.keys()])this.detach(track);
      const work=this.closeTarget(target);this.ticket?.release();this.ticket=null;
      if(activeMedia===this)activeMedia=null;this.record.media=null;this.controls();await work;
    }
  }
  class Surface {
    constructor(options){this.options=options;this.role=options.role;this.records=new Map();this.epoch=0;this.lastContext='';this.alive=true;this.busy=false;this.queued=false;this.offerBusy=false;this.offerAttempt=null;this.canOffer=false;this.scopeControls=new Set();this.pollTimer=0;this.lastDispatch=new Map();this.pendingMutations=0;this.mutationGeneration=0;}
    capture(){const value=this.options.context();return {key:String(value.key),binding:{...value.binding},epoch:this.epoch};}
    sameContext(snapshot){return this.alive&&snapshot.epoch===this.epoch&&String(this.options.context().key)===snapshot.key;}
    current(snapshot){const current=this.options.context();return this.alive&&snapshot.epoch===this.epoch&&String(current.key)===snapshot.key&&current.active!==false;}
    async request(operation,payload={},method='POST'){return bounded(this.options.request(this.role+'_'+operation,payload,method));}
    binding(record){return {...record.snapshot.binding,conversation_id:record.call.conversation_id,call_id:record.call.call_id,revision:record.call.revision,...(this.role==='owner'?{delivery_id:record.binding.delivery_id}:{} )};}
    async invoke(operation,record,extra={}){return this.request(operation,{...this.binding(record),...extra});}
    status(record){const call=record.call,media=record.media;if(media){if(media.phase==='capture_busy')return 'Another voice surface is using the microphone. Switch capture here only when you are ready.';if(media.phase==='preparing')return 'Preparing your call connection…';if(media.phase==='connecting')return 'Connecting your microphone…';if(media.phase==='reconnecting')return 'Connection interrupted. Reconnecting while authorization remains current…';if(media.phase==='connected'){const peer=media.room?.remoteParticipants;return peer&&[...peer.values()].some(p=>p.identity===media.auth?.peer_identity)?'Live call · you are speaking directly to the other person.':'Connected · waiting for the other person to join.';}}
      if(call.cleanup_pending||call.state==='ending')return 'Your microphone and camera are off. Service cleanup is pending.';
      if(TERMINAL.has(call.state))return ({ended:'Call ended.',declined:'Call declined.',cancelled:'Call cancelled.',expired:'Call request expired.',failed:'The call could not be connected. You can keep messaging.'})[call.state];
      if(record.localMessage)return record.localMessage;
      return call.state==='offered'?(call.offered_by===this.role?'Waiting for the other person to accept.':'Incoming '+call.mode+' call request.'):(call.state==='preparing'?'Preparing the call service…':'Call accepted. Join when you are ready; your microphone and camera are off.');
    }
    render(record){
      if(!record.node.isConnected)return;
      const call=record.call,media=record.media,live=this.current(record.snapshot),pending=record.pending||Boolean(media&&['preparing','connecting'].includes(media.phase));
      record.status.textContent=this.status(record);record.node.dataset.callState=call.state;
      for(const name of ['accept','decline','cancel','end','return_to_agent']){const button=record.buttons[name];button.hidden=!call['can_'+name];button.disabled=!live||record.pending||(pending&&!['end','return_to_agent'].includes(name))||!call['can_'+name];}
      record.consent.hidden=!call.can_join||Boolean(media)||Boolean(record.localMessage);
      record.join.disabled=!live||pending||!call.can_join||!record.mic.checked||Boolean(activeMedia&&activeMedia!==media);
      record.mic.disabled=record.camera.disabled=pending;
      record.cameraLabel.hidden=call.mode!=='video';
      record.switchButton.hidden=media?.phase!=='capture_busy';record.switchButton.disabled=pending;
      record.mediaControls.hidden=!media||media.phase!=='connected';
      record.mute.disabled=record.cameraToggle.disabled=!live||!media||media.mediaBusy;
      record.mute.textContent=media?.room?.localParticipant?.isMicrophoneEnabled?'Mute microphone':'Unmute microphone';
      record.cameraToggle.hidden=call.mode!=='video'||media?.consent.camera!==true;record.cameraToggle.textContent=media?.room?.localParticipant?.isCameraEnabled?'Turn camera off':'Turn camera on';
      record.sound.hidden=!record.needsSound;record.sound.disabled=!media;
    }
    create(call,host,binding){
      const card=node('section','profile-call-card');card.dataset.profileCallId=String(call.call_id);
      const title=this.role==='owner'?(call.visitor_label||'Profile visitor'):(this.options.peerLabel||'Profile owner');
      const heading=node('h3','','Live '+call.mode+' call · '+title),status=node('p','profile-call-status'),notice=node('p','profile-call-notice');status.setAttribute('role','status');notice.setAttribute('role','status');
      const actions=node('div','profile-call-actions'),buttons={};for(const [name,label] of [['accept','Accept call'],['decline','Decline'],['cancel','Cancel request'],['end','End call'],['return_to_agent','Return to Profile Agent']]){const button=node('button','',label);button.type='button';button.dataset.callAction=name;buttons[name]=button;actions.appendChild(button);}
      const consent=node('div','profile-call-consent'),micLabel=node('label'),mic=node('input');mic.type='checkbox';micLabel.append(mic,node('span','','I agree to turn on my microphone for this live call.'));
      const cameraLabel=node('label'),camera=node('input');camera.type='checkbox';cameraLabel.append(camera,node('span','','Also turn on my camera (optional).'));
      const join=node('button','','Join call');join.type='button';join.dataset.callAction='join';consent.append(node('p','','You will speak directly to the other person. This call does not use the Profile Agent voice, recording or transcription.'),micLabel,cameraLabel,join);
      const stage=node('div','profile-call-stage'),remoteMedia=node('div','profile-call-remote'),localMedia=node('div','profile-call-local');stage.append(remoteMedia,localMedia);
      const mediaControls=node('div','profile-call-actions'),mute=node('button','','Mute microphone'),cameraToggle=node('button','','Turn camera on'),sound=node('button','','Enable sound'),switchButton=node('button','','Switch microphone to this call');
      for(const button of [mute,cameraToggle,sound,switchButton])button.type='button';mediaControls.append(mute,cameraToggle);sound.hidden=switchButton.hidden=true;card.append(heading,status,actions,consent,switchButton,stage,mediaControls,sound,notice);host.appendChild(card);
      const record={surface:this,call,node:card,binding,snapshot:this.capture(),status,notice,buttons,consent,mic,camera,cameraLabel,join,stage,remoteMedia,localMedia,mediaControls,mute,cameraToggle,sound,switchButton,pending:false,attempt:null,media:null,needsSound:false,localMessage:''};
      for(const action of ['accept','decline','cancel','end','return_to_agent'])buttons[action].addEventListener('click',()=>void this.action(record,action));
      mic.addEventListener('change',()=>this.render(record));camera.addEventListener('change',()=>this.render(record));
      join.addEventListener('click',()=>void this.join(record));switchButton.addEventListener('click',()=>{if(record.media?.phase==='capture_busy')void record.media.start(true);});
      mute.addEventListener('click',()=>void record.media?.change('microphone'));cameraToggle.addEventListener('click',()=>void record.media?.change('camera'));sound.addEventListener('click',()=>void record.media?.sound());
      this.records.set(call.call_id,record);this.render(record);return record;
    }
    apply(raw,host=null,binding=null){
      const call=projection(raw,this.role);if(!call)return null;
      let record=this.records.get(call.call_id);if(record&&!record.node.isConnected){record.media?.close('Call view closed.');this.records.delete(call.call_id);record=null;}
      if(record){if(call.revision<record.call.revision||call.conversation_id!==record.call.conversation_id||call.mode!==record.call.mode)return record;record.call=call;if(binding)record.binding=binding;record.snapshot=this.capture();record.node.hidden=false;record.media?.observe(call);this.render(record);return record;}
      if(!host)return null;return this.create(call,host,binding||{});
    }
    async action(record,operation,closing=false){
      if(record.pending||!(this.current(record.snapshot)||(closing&&this.sameContext(record.snapshot)))||!record.call['can_'+operation])return;
      const snapshot=this.capture(),latest=this.binding(record),key=JSON.stringify([operation,{...latest,revision:0}]);
      if(!record.attempt||record.attempt.key!==key)record.attempt={key,request_id:random(),payload:latest};const attempt=record.attempt,payload=attempt.payload;
      record.pending=true;this.pendingMutations++;this.mutationGeneration++;record.notice.textContent='';this.render(record);
      if(operation==='end'||operation==='return_to_agent'){record.localMessage='Your microphone and camera are off. Call cleanup is being checked.';void record.media?.close(record.localMessage);}
      try{const result=await this.request(operation,{...payload,request_id:attempt.request_id});if(!this.current(snapshot)||record.attempt!==attempt)return;const call=projection(result.call,this.role);if(!call||result.call_id!==payload.call_id||result.conversation_id!==payload.conversation_id||result.request_id!==attempt.request_id)throw new Error('Call action identity changed.');record.attempt=null;this.apply(call);if(operation==='return_to_agent'&&!call.can_return_to_agent){record.notice.textContent='The Profile Agent can respond to your next message.';this.options.returnedToAgent?.();}}
      catch(error){if(this.current(snapshot)){record.notice.textContent=messageFor(error);if([401,403,404,409,419,422].includes(error.status))record.attempt=null;}}
      finally{record.pending=false;this.pendingMutations--;this.render(record);if(this.current(snapshot))void this.refresh();}
    }
    async endAfterMedia(record,closing=false){
      const notice=record.notice.textContent,snapshot=this.capture();
      if(record.call.can_end)await this.action(record,'end',closing);
      if(notice&&this.current(snapshot)&&!record.notice.textContent)record.notice.textContent=notice;
    }
    async join(record){
      if(record.pending||!this.current(record.snapshot)||!record.call.can_join||!record.mic.checked||record.media||activeMedia)return;
      record.notice.textContent='';const session=new MediaSession(this,record,{camera:record.call.mode==='video'&&record.camera.checked});record.media=session;activeMedia=session;await session.start();
    }
    async offer(mode,controls){
      if(this.offerBusy||!controls.allowed||!['audio','video'].includes(mode)||this.options.allowOffer?.()===false)return;
      const context=controls.context(),snapshot=this.capture();if(!context)return;
      this.offerBusy=true;this.mutationGeneration++;controls.notice.textContent='';const key=JSON.stringify([mode,context]);if(!this.offerAttempt||this.offerAttempt.key!==key)this.offerAttempt={key,request_id:random()};
      const attempt=this.offerAttempt;controls.buttons.forEach(button=>button.disabled=true);
      try{const result=await this.request('offer',{...context,mode,request_id:attempt.request_id});if(!this.current(snapshot))return;const call=projection(result.call,this.role);if(!call||result.request_id!==attempt.request_id||result.call_id!==call.call_id||result.conversation_id!==call.conversation_id)throw new Error('Call request identity changed.');if(this.role==='visitor')this.options.adopt?.(result);this.offerAttempt=null;await this.refresh();}
      catch(error){if(this.current(snapshot))controls.notice.textContent=messageFor(error);}
      finally{this.offerBusy=false;controls.buttons.forEach(button=>button.disabled=!controls.allowed);}
    }
    offers(host,context){
      const row=node('div','profile-call-offer-actions'),notice=node('p','profile-call-notice'),buttons=[];const controls={row,notice,buttons,context,allowed:false,last:0};
      for(const [mode,label] of [['audio','Request audio call'],['video','Request video call']]){const button=node('button','',label);button.type='button';button.disabled=true;button.dataset.callOffer=mode;button.addEventListener('click',()=>void this.offer(mode,controls));row.appendChild(button);buttons.push(button);}row.appendChild(notice);host.appendChild(row);this.scopeControls.add(controls);return controls;
    }
    async refreshOffers(controls){const binding=controls.context();if(!binding||!controls.row.isConnected)return;const snapshot=this.capture();try{const data=await this.request('state',binding,'GET');if(!this.current(snapshot)||!controls.row.isConnected)return;if(data.contract!==CONTRACT||typeof data.can_offer!=='boolean')throw new Error('Call capability unavailable.');controls.allowed=data.can_offer;controls.last=time();controls.notice.textContent=data.can_offer?'':'Live calls are unavailable right now. You can keep messaging.';}catch(_){controls.allowed=false;controls.notice.textContent='Live calls are unavailable. You can keep messaging.';}controls.buttons.forEach(button=>button.disabled=!controls.allowed||this.offerBusy);}
    async dispatch(record){
      if(!record.call.dispatch_due||!this.current(record.snapshot))return;
      const prior=this.lastDispatch.get(record.call.call_id);if(prior!==undefined&&time()-prior<10000)return;
      this.lastDispatch.set(record.call.call_id,time());const snapshot=this.capture();
      if(!record.dispatchAttempt||record.dispatchAttempt.revision!==record.call.revision)record.dispatchAttempt={revision:record.call.revision,request_id:random()};
      const attempt=record.dispatchAttempt;
      try{const result=await this.invoke('dispatch',record,{request_id:attempt.request_id});if(this.current(snapshot)&&result.call_id===record.call.call_id&&result.conversation_id===record.call.conversation_id&&result.request_id===attempt.request_id){this.apply(result.call);if(record.dispatchAttempt===attempt)record.dispatchAttempt=null;}}catch(_){/* Canonical status and authority leases remain authoritative. */}
    }
    async refresh(){
      if(!this.alive)return;if(this.busy){this.queued=true;return;}
      const context=this.options.context();if(context.active===false)return;
      if(this.role==='owner'&&!positive(context.binding.chat_conversation_id))return;
      this.busy=true;this.queued=false;const snapshot=this.capture(),mutations=this.mutationGeneration;
      try{
        const result=await this.request('state',snapshot.binding,'GET');if(!this.current(snapshot)||mutations!==this.mutationGeneration||this.pendingMutations)return;
        if(result.contract!==CONTRACT||!Array.isArray(result.calls)||result.calls.length>20||typeof result.can_offer!=='boolean')throw new Error('Call status unavailable.');
        this.canOffer=result.can_offer;
        if(this.role==='visitor'&&this.visitorOffers){this.visitorOffers.allowed=result.can_offer;this.visitorOffers.buttons.forEach(button=>button.disabled=!result.can_offer||this.offerBusy);this.visitorOffers.notice.textContent=result.can_offer?'':'Live calls are unavailable right now. You can keep messaging.';}
        for(const raw of result.calls){const call=projection(raw,this.role);if(!call)continue;let record=this.records.get(call.call_id);
          if(this.role==='owner'){
            if(!record?.node.isConnected){const delivered=await this.request('delivery',{...snapshot.binding,conversation_id:call.conversation_id,call_id:call.call_id,revision:call.revision});if(!this.current(snapshot))return;const next=projection(delivered.call,this.role);if(!next||next.call_id!==call.call_id||delivered.chat_conversation_id!==snapshot.binding.chat_conversation_id||delivered.chat_agent_id!==snapshot.binding.chat_agent_id||!positive(delivered.chat_message_id)||!token(delivered.delivery_id))continue;const element=this.options.mount(delivered);if(!element)continue;const host=element.querySelector('.message-body')||element;const text=element.querySelector('.message-text');if(text)text.hidden=true;record=this.apply(next,host,{delivery_id:delivered.delivery_id,chat_message_id:delivered.chat_message_id});}
            else record=this.apply(call);
          }else record=this.apply(call,this.options.host);
          if(record){record.snapshot=this.capture();this.render(record);void this.dispatch(record);}
        }
        for(const control of this.scopeControls)if(this.role==='owner'&&time()-control.last>30000)void this.refreshOffers(control);
      }catch(error){if(this.current(snapshot)){if([401,403,419].includes(error.status)||error.code==='PROFILE_SESSION_CHANGED')this.invalidate('Call access changed.');if(this.visitorOffers){this.visitorOffers.allowed=false;this.visitorOffers.buttons.forEach(button=>button.disabled=true);this.visitorOffers.notice.textContent='Live calls are unavailable. You can keep messaging.';}}}
      finally{this.busy=false;if(this.queued&&this.alive){this.queued=false;setTimeout(()=>void this.refresh(),0);}}
    }
    invalidate(reason='Call context changed.'){
      if(activeMedia?.surface===this){const session=activeMedia,record=session.record;void session.close(reason+' Your microphone and camera are off.');if(session.ownedMedia)void this.endAfterMedia(record,true);}
      this.epoch++;for(const record of this.records.values()){record.node.hidden=true;this.render(record);}for(const control of this.scopeControls){control.allowed=false;control.buttons.forEach(button=>button.disabled=true);}
    }
    start(){this.pollTimer=setInterval(()=>void this.refresh(),3000);void this.refresh();return this;}
    stop(reason='Call view closed.'){this.invalidate(reason);this.alive=false;clearInterval(this.pollTimer);}
  }
  let ownerSurface=null;
  function mountVisitor(options){const surface=new Surface({...options,role:'visitor'});surface.visitorOffers=surface.offers(options.host,()=>({...options.context().binding}));return surface.start();}
  function ownerContext(){const c=window.STONEFELLOW_CHAT_CONTINUITY||window.STONEFELLOW_CHAT_CONTINUITY_V87||{},cid=Number(c.conversationId?.()||0),aid=Number(c.agentId?.()||0);return {key:cid+':'+aid,binding:{chat_conversation_id:cid,chat_agent_id:aid},active:c.isReady?.()!==false&&!c.isRestoring?.()&&document.getElementById('chatThread')?.dataset.view==='chat'};}
  function mountOwner(config){
    const request=async(action,payload,method)=>{const url=new URL(config.endpoint,location.href),options={method,credentials:'same-origin',cache:'no-store'};if(method==='GET'){Object.entries({action,...payload}).forEach(([key,value])=>url.searchParams.set(key,String(value)));}else{options.headers={'Content-Type':'application/json'};options.body=JSON.stringify({action,csrf_token:config.csrf,...payload});}const response=await fetch(url,options),data=await response.json().catch(()=>null);if(!response.ok||!data?.ok){const error=new Error('Call request unavailable.');error.status=response.status;error.code=data?.code||'';throw error;}return data;};
    ownerSurface=new Surface({role:'owner',context:ownerContext,request,mount:delivery=>(window.STONEFELLOW_CHAT_CONTINUITY||{}).mountProfileCall?.(delivery)}).start();return ownerSurface;
  }
  window.VP3ProfileCallsPa3c={mountVisitor,attachOwnerThread(host,options){if(!ownerSurface)return;const control=ownerSurface.offers(host,()=>options.binding());void ownerSurface.refreshOffers(control);},refresh:()=>ownerSurface?.refresh(),invalidateVisitor(surface,reason){surface?.invalidate(reason);}};
  window.addEventListener('stonefellow:chat-context-changing',()=>ownerSurface?.invalidate('Chat context changed.'));
  window.addEventListener('vp3:chat-context-ready',()=>void ownerSurface?.refresh());
  window.addEventListener('vp3:profile-call-message',()=>void ownerSurface?.refresh());
  window.addEventListener('vp3:chat-canvas-view-changed',()=>{if(ownerSurface&&ownerContext().active===false)ownerSurface.invalidate('Chat view changed.');else void ownerSurface?.refresh();});
  window.addEventListener('stonefellow:voice-lease-lost',()=>{if(activeMedia&&!activeMedia.yieldingCapture){const session=activeMedia;void session.close('Microphone ownership changed. Your call media is off.');if(session.ownedMedia)void session.surface.endAfterMedia(session.record);}});
  window.addEventListener('pagehide',()=>{if(activeMedia){const session=activeMedia;void session.close('You left the call.');if(session.ownedMedia)void session.surface.endAfterMedia(session.record);}ownerSurface?.stop();});
  if(window.VP3_PROFILE_CALLS_PA3C?.role==='owner')mountOwner(window.VP3_PROFILE_CALLS_PA3C);
})();
