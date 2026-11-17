/* Participant recognition stays local; TV Watching separately opts into screen crops. */
(() => {
  'use strict';
  async function boot(){
    const adapter=window.VP3_ORB_ADAPTER;if(!adapter||window.VP3AgentEyes||new URLSearchParams(location.search).get('orb_embed')==='1')return;
    const core=window.VP3EyesCore.create(),session=crypto.randomUUID();
    let generation=0,stream=null,engine=null,timer=0,profiles=[],observations=[],currentRows=[],starting=false,enrolling=false,pendingEnrollment=false,pending=[],flushing=false,profileVersion=0,profileSignature='',introduction=null,savingIntroduction=false,recognitionOffer=null,refreshing=false,stablePhoto=null;const photoRetry=new Map();
    let presenceSequence=0,presenceBinding=null,presenceCaptureBinding=null,pendingCaptureBinding=null,captureId='',presenceObservedAt='',presenceTransition='unknown',presenceTransitionAt='',presenceObjectLabels=[],presenceObjectDetection=false;
    let greetingRevision=0,previousDiagnostics='',latestProfileLoad=null;
    const arrivedTracks=new Set(),savedVisits=new Map();
    const panel=document.createElement('section');panel.className='vp3-eyes-panel';panel.hidden=true;panel.setAttribute('aria-label','Agent Eyes');
    panel.innerHTML='<header><strong>Agent Eyes</strong><button type="button" data-eyes-close aria-label="Close Agent Eyes">×</button></header><div class="vp3-eyes-content"><p data-eyes-status role="status">Camera is off.</p><div class="vp3-eyes-actions"><button type="button" data-eyes-start>Start camera</button><button type="button" data-eyes-stop disabled>Stop camera</button></div><div class="vp3-eyes-camera"><video muted playsinline></video><div class="vp3-eyes-boxes"></div></div><p data-eyes-people>No participants in view.</p><label><input type="checkbox" data-eyes-pause-hidden checked> Pause camera when this page is hidden</label><label><input type="checkbox" data-eyes-greetings checked> Greet arrivals automatically</label><details><summary>Introduce / recognize a participant</summary><p>A tracking ID is temporary. A local visual match is provisional and grants no account access.</p><label>Existing participant<select data-eyes-profile aria-label="Existing participant"></select></label><label>Or introduce someone<input type="text" data-eyes-name maxlength="120" placeholder="Participant name"></label><label><input type="checkbox" data-eyes-consent> This participant agrees to save local visual samples for recognition.</label><label><input type="checkbox" data-eyes-photo-refresh checked> Refresh saved photos weekly while this participant is clearly recognized</label><div class="vp3-eyes-actions"><button type="button" data-eyes-enroll>Take and save three photos</button><button type="button" data-eyes-forget>Forget local samples</button></div></details><details><summary>Recent Agent Eyes activity</summary><ol data-eyes-history></ol></details><p>Participant frames and visual samples stay in this browser. TV Watching has separate controls for cropped-screen sharing and account history.</p></div>';
    document.body.append(panel);const q=s=>panel.querySelector(s),video=q('video'),status=q('[data-eyes-status]');
    const speakerStatus=document.createElement('p');speakerStatus.dataset.eyesSpeaker='';speakerStatus.setAttribute('role','status');q('.vp3-eyes-camera').after(speakerStatus);
    const legend=document.createElement('p');legend.textContent='Blue: tracked participant. Green: recent voice match (up to 3 seconds).';speakerStatus.after(legend);
    let speakerTimer=0;const boxNodes=new Map();
    function paintSpeaker(){
      clearTimeout(speakerTimer);const speaker=window.VP3ParticipantContinuity?.activeSpeaker?.(),track=window.VP3EyesCore.speakerTrack(currentRows,speaker);
      for(const row of currentRows){const node=boxNodes.get(row.track_id);if(!node)continue;const active=row.track_id===track;node.classList.toggle('vp3-eyes-speaker',active);node.dataset.speaker=String(active);node.querySelector('span').textContent=(row.display_name?`${row.display_name} · provisional`:`Participant ${row.track_id.slice(-5)}`)+(active?' · voice match':'');}
      const row=currentRows.find(r=>r.track_id===track);speakerStatus.textContent=row?`Recent speaker: ${row.display_name} · provisional.`:'No current speaker match.';
      if(track)speakerTimer=setTimeout(paintSpeaker,Math.max(1,speaker.expires_at-Date.now()));
    }
    window.addEventListener('vp3:active-speaker',paintSpeaker);
    const movement=document.createElement('div');movement.innerHTML='<label><input type="checkbox" data-eyes-follow checked> Follow participant movement</label><label><input type="checkbox" data-eyes-mirror checked> Mirror camera movement</label>';q('[data-eyes-greetings]').closest('label').after(movement);
    for(const key of ['follow','mirror']){const input=q(`[data-eyes-${key}]`),pref=`vp3:eyes:${key}:${adapter.ownerKey}`;try{input.checked=localStorage.getItem(pref)!=='off';}catch(_){}input.addEventListener('change',()=>{try{localStorage.setItem(pref,input.checked?'on':'off');}catch(_){}draw(currentRows);});}
    const pauseHidden=q('[data-eyes-pause-hidden]'),pauseKey=`vp3:eyes:pause-hidden:${adapter.ownerKey}`;
    try{pauseHidden.checked=localStorage.getItem(pauseKey)!=='off';}catch(_){}
    const cameraAllowed=()=>adapter.cameraAllowed({allowHidden:!pauseHidden.checked});
    pauseHidden.addEventListener('change',()=>{try{localStorage.setItem(pauseKey,pauseHidden.checked?'on':'off');}catch(_){}if(pauseHidden.checked&&document.hidden)void stop('Camera stopped while this page is hidden.');});
    const say=text=>{status.textContent=String(text);};
    const history=q('[data-eyes-history]');
    const portraits=document.createElement('div');portraits.className='vp3-eyes-portraits';portraits.setAttribute('aria-label','Participant photo gallery');q('[data-eyes-enroll]').closest('.vp3-eyes-actions').after(portraits);
    const enrollmentStatus=document.createElement('p');enrollmentStatus.dataset.eyesEnrollmentStatus='';enrollmentStatus.setAttribute('role','status');portraits.after(enrollmentStatus);const photoStatus=document.createElement('p');photoStatus.dataset.eyesPhotoStatus='';photoStatus.setAttribute('role','status');portraits.before(photoStatus);const enrollmentSay=text=>{enrollmentStatus.textContent=String(text);say(text);};
    const sampleStatus=document.createElement('p');sampleStatus.dataset.eyesSampleStatus='';q('[data-eyes-profile]').closest('label').after(sampleStatus);
    const localSamples=profile=>profile?.recognitionEnabled!==false&&(profile?.embeddings?.length||0)>=3;
    const photoEvent=(state,message)=>window.dispatchEvent(new CustomEvent('vp3:participant-photo-state',{detail:{state,message}}));
    function showPortraits(rows=[],preview=false){portraits.replaceChildren();const saved=window.VP3EyesCore.cleanPortraits(rows);for(const [i,row] of saved.entries()){const figure=document.createElement('figure'),img=document.createElement('img'),caption=document.createElement('figcaption');img.src=row.image;img.alt=`${preview?'Captured':'Saved'} participant photo ${i+1}`;img.width=80;img.height=80;caption.textContent=new Date(row.captured_at).toLocaleString();figure.append(img,caption);portraits.append(figure);}photoStatus.textContent=preview?`${saved.length} of 3 photos captured · Not saved yet` :saved.length?`${saved.length} saved photo${saved.length===1?'':'s'} · Last updated ${new Date(saved.at(-1).captured_at).toLocaleString()}`:'No saved photos on this device.';}
    function savedPortraits(){const p=profiles.find(p=>String(p.id)===q('[data-eyes-profile]').value);showPortraits(p?.portraits||[]);q('[data-eyes-photo-refresh]').checked=p?p.portraitRefreshEnabled===true:true;showSampleStatus();}
    function showSampleStatus(){
      const profile=profiles.find(p=>String(p.id)===q('[data-eyes-profile]').value);
      let message='No visual recognition samples available in this browser.';
      if(profile?.recognitionEnabled===false)message='Visual recognition consent is off.';
      else if(localSamples(profile))message='This browser has visual recognition samples for this profile.';
      else if(profile)message='This saved profile has no usable visual recognition samples in this browser.';
      else if(profiles.some(localSamples))message='Choose a participant to check their local recognition samples.';
      if(sampleStatus.textContent!==message)sampleStatus.textContent=message;
    }
    function portrait(face,source=video){const widthPx=source.videoWidth||source.width,heightPx=source.videoHeight||source.height;const [x,y,w,h]=face.box||[];if(!widthPx||!heightPx||![x,y,w,h].every(Number.isFinite)||w<64||h<64||x<0||y<0||x+w>widthPx||y+h>heightPx)throw Error('Wait for a clear camera frame before saving samples.');const left=Math.max(0,x-w*.2),top=Math.max(0,y-h*.2),width=Math.min(widthPx-left,w*1.4),height=Math.min(heightPx-top,h*1.4);const canvas=document.createElement('canvas');canvas.width=canvas.height=160;canvas.getContext('2d').drawImage(source,left,top,width,height,0,0,160,160);const image=canvas.toDataURL('image/jpeg',.72);if(image.length>65536)throw Error('Visual portrait exceeded its local size limit.');return {image,captured_at:new Date().toISOString()};}

    const greetingKey=`vp3:eyes:greetings:${adapter.ownerKey}`;
    try{q('[data-eyes-greetings]').checked=localStorage.getItem(greetingKey)!=='off';}catch(_){}
    if(adapter.isMainChat?.())q('[data-eyes-greetings]').closest('label').lastChild.textContent=' Let the Agent consider greeting arrivals';
    const contextUse=document.createElement('p');contextUse.textContent='While the camera is on in the active Chat, the Agent may use provisional arrivals, participant counts and already available local object labels as temporary context. Camera frames and recognition samples stay in this browser.';q('[data-eyes-greetings]').closest('label').after(contextUse);
    const greetingAllowed=()=>q('[data-eyes-greetings]').checked;
    q('[data-eyes-greetings]').addEventListener('change',()=>{greetingRevision++;try{localStorage.setItem(greetingKey,greetingAllowed()?'on':'off');}catch(_){}draw(currentRows);});
    for(const selector of ['[data-eyes-profile]','[data-eyes-name]'])q(selector).addEventListener('input',()=>{q('[data-eyes-consent]').checked=false;pendingEnrollment=false;recognitionOffer=null;window.VP3AgentOrb?.setIntroductionMode?.('');savedPortraits();});
    q('[data-eyes-photo-refresh]').addEventListener('change',async()=>{const id=q('[data-eyes-profile]').value,p=profiles.find(p=>String(p.id)===id),enabled=q('[data-eyes-photo-refresh]').checked;if(!p?.embeddings?.length)return;try{await adapter.setEyesPhotoRefresh({id,enabled,isCurrent:()=>q('[data-eyes-profile]').value===id});await loadProfiles();enrollmentSay(enabled?'Weekly photo refresh enabled for this participant.':'Automatic photo refresh disabled.');}catch(error){savedPortraits();enrollmentSay(String(error.message||'Photo preference could not be saved.'));}});
    function cameraIndicator(active){const root=document.querySelector('.vp3-orb-root');if(root)root.dataset.eyesActive=String(active);const button=document.querySelector('[data-orb-eyes]');if(button){button.textContent=active?'Eyes on':'Eyes';button.setAttribute('aria-pressed',String(active));}}

    const chatRuntime=()=>window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
    function chatIdentity(){
      const live=window.STONEFELLOW_CHAT,owner=Number(live?.userId),agent=Number(live?.agentId||0);
      return Number.isSafeInteger(owner)&&owner>0&&owner===Number(adapter.ownerKey)&&Number.isSafeInteger(agent)&&agent>=0&&Number(chatRuntime()?.agentId?.())===agent
        ?{owner_user_id:owner,agent_id:agent}:null;
    }
    const sameIdentity=(a,b)=>!!a&&!!b&&a.owner_user_id===b.owner_user_id&&a.agent_id===b.agent_id;
    const sameChat=(a,b)=>sameIdentity(a,b)&&a.conversation_id===b.conversation_id;
    function readyConversation(){
      const chat=chatRuntime(),id=Number(chat?.conversationId?.());
      return chat?.isReady?.()===true&&chat?.isRestoring?.()===false&&Number.isSafeInteger(id)&&id>0?id:0;
    }
    function settleInitialCapture(){
      if(!pendingCaptureBinding||pendingCaptureBinding.conversation_id||chatRuntime()?.isInitialRestorePending?.()===true)return;
      const conversationId=readyConversation();
      if(!sameIdentity(chatIdentity(),pendingCaptureBinding))return void stop('Camera stopped because the Chat context changed.');
      pendingCaptureBinding=conversationId?{...pendingCaptureBinding,conversation_id:conversationId}:null;
      publishDiagnostics();
    }
    function bindRestoredChat(context){
      if(!sameChat(pendingCaptureBinding,context))return;
      presenceCaptureBinding=context;pendingCaptureBinding=null;
      // A restored Chat consumes fresh detections. Local-only tracks and events
      // are never replayed into the restored conversation.
      core.clear();arrivedTracks.clear();savedVisits.clear();
      observations=[];currentRows=[];stablePhoto=null;
      introduction=null;recognitionOffer=null;window.VP3AgentOrb?.setIntroductionMode?.('');
      presenceObservedAt='';presenceTransition='unknown';presenceTransitionAt='';presenceObjectLabels=[];presenceObjectDetection=false;
    }
    function matchedTrack(track){return currentRows.find(row=>row.track_id===track&&row.identity_source==='local_visual_similarity'&&row.participant_id);}
    function diagnostics(){
      let state=stream?'uncertain':'off';
      if(starting)state='starting';
      else if(cameraSourceActive()){
        const known=currentRows.filter(row=>row.identity_source==='local_visual_similarity'&&row.participant_id);
        if(known.some(row=>savedVisits.has(row.track_id)))state='history_saved';
        else if(known.some(row=>arrivedTracks.has(row.track_id)))state='arrival_detected';
        else if(known.length)state='local_match';
        else if(!profiles.some(localSamples))state='no_samples';
      }
      const binding=presenceBinding||presenceCaptureBinding||pendingCaptureBinding||chatIdentity();
      return {state,chat_linked:!!presenceBinding,waiting_for_chat:!!pendingCaptureBinding,owner_user_id:binding?.owner_user_id||0,agent_id:binding?.agent_id??0,conversation_id:binding?.conversation_id||0,capture_generation:generation};
    }
    function publishDiagnostics(){
      const next=JSON.stringify(diagnostics());if(next===previousDiagnostics)return;previousDiagnostics=next;
      window.dispatchEvent(new Event('vp3:eyes-diagnostics-changed'));
    }
    function acceptVisit(body,visit,revision){
      if(!visit||visit.status!=='active'||!stream||!cameraSourceActive()||body.capture_id!==captureId||visit.capture_id!==captureId
        ||!sameChat(presenceCaptureBinding,adapter.liveSensoryContext?.())||visit.conversation_id!==presenceCaptureBinding.conversation_id
        ||visit.agent_namespace!==(presenceCaptureBinding.agent_id?`agent:${presenceCaptureBinding.agent_id}`:'system')||visit.track_id!==body.track_id
        ||!/^eyes-visit:[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(visit.ref||''))return;
      const row=matchedTrack(body.track_id);
      if(!row||String(row.participant_id)!==String(body.participant_id)||String(row.participant_id)!==String(visit.participant_id))return;
      savedVisits.set(body.track_id,{ref:visit.ref,participant_id:row.participant_id,greeting_revision:revision,greetings_allowed:visit.greetings_allowed===true});
      draw(currentRows);
    }
    async function flush(){
      if(flushing||!pending.length)return;flushing=true;
      try{
        while(pending.length){
          const entry=pending[0],{body,revision}=entry;
          const observedAt=window.VP3LiveContext?.serverObservationTime?.(body.observed_at)||body.observed_at;
          const receipt=await adapter.recordEyes({...body,observed_at:observedAt});
          if(pending[0]===entry)pending.shift();
          acceptVisit(body,receipt?.visit,revision);
        }
      }
      catch(_){say('Camera status is local. Brain history could not be saved; it will retry.');}
      finally{flushing=false;}
    }
    function log(event,observedAt=new Date().toISOString(),captureEnded=false){
      if(!event)return;
      if(event.event_type==='arrival')arrivedTracks.add(event.track_id);
      if(event.event_type==='departure'){arrivedTracks.delete(event.track_id);savedVisits.delete(event.track_id);}
      if(event.event_type==='identity_changed'&&String(savedVisits.get(event.track_id)?.participant_id)!==String(event.participant_id))savedVisits.delete(event.track_id);
      if(['arrival','departure'].includes(event.event_type)){presenceTransition=event.event_type;presenceTransitionAt=observedAt;}
      const conversationId=readyConversation(),mainChat=adapter.isMainChat?.()===true;
      const sameCapture=!mainChat||sameIdentity(chatIdentity(),presenceCaptureBinding)&&conversationId===presenceCaptureBinding.conversation_id;
      if(sameCapture){
        const body={...event,session_id:session,capture_id:captureId,observed_at:observedAt,greetings_allowed:greetingAllowed()&&sameChat(presenceCaptureBinding,adapter.liveSensoryContext?.()),...(captureEnded?{capture_ended:true}:{})};
        if(conversationId>0)body.conversation_id=conversationId;
        pending.push({body,revision:greetingRevision});
      }
      if(pending.length>100){pending.shift();say('Some earlier events could not be saved.');}
      const item=document.createElement('li');item.textContent=`${new Date().toLocaleTimeString()} · ${event.event_type.replaceAll('_',' ')} · ${event.display_name||'Unidentified participant'}`;history.prepend(item);while(history.children.length>40)history.lastChild.remove();void flush();
    }
    function loadProfiles(){
      const token=++profileVersion;
      latestProfileLoad=adapter.loadEyesProfiles().then(rows=>{
        if(token!==profileVersion)return latestProfileLoad;
        profiles=rows;
        const signature=JSON.stringify(rows.map(p=>[String(p.id),p.name]));
        if(signature!==profileSignature){
          profileSignature=signature;const select=q('[data-eyes-profile]'),selected=select.value;
          select.replaceChildren(new Option('Choose participant',''),...profiles.map(profile=>new Option(profile.name,String(profile.id))));
          select.value=selected;
        }
        showSampleStatus();
      });
      return latestProfileLoad;
    }
    function clearPresence(){
      if(!presenceBinding)return;
      const context=presenceBinding;presenceBinding=null;presenceObservedAt='';
      window.dispatchEvent(new CustomEvent('vp3:participant-presence',{detail:{active:false,context_allowed:false,rows:[],context,observed_at:new Date().toISOString(),sequence:++presenceSequence,greetings_allowed:greetingAllowed(),last_event:'unknown'}}));
      publishDiagnostics();
    }
    const cameraSourceActive=()=>!!stream&&(stream.getVideoTracks?.()||stream.getTracks?.()||[])
      .some(track=>track.readyState==='live'&&track.enabled!==false&&track.muted!==true);
    function draw(rows,observedAt=''){
      if(observedAt)presenceObservedAt=observedAt;
      const candidate=adapter.liveSensoryContext?.()||null,mainChat=adapter.isMainChat?.()===true;
      const context=!mainChat||sameChat(presenceCaptureBinding,candidate)?candidate:null;
      if(presenceBinding&&(!sameChat(presenceBinding,context)||!cameraSourceActive()))clearPresence();
      const active=!!presenceObservedAt&&cameraSourceActive()&&(!mainChat||!!context);
      if(context&&active)presenceBinding=context;
      const visibleRows=active?rows.map(({track_id,participant_id,display_name,identity_source})=>{
        const visit=savedVisits.get(track_id),row={track_id,participant_id,display_name,identity_source};
        if(visit&&greetingAllowed()&&visit.greetings_allowed&&visit.greeting_revision===greetingRevision&&identity_source==='local_visual_similarity'&&participant_id===visit.participant_id)row.visit_ref=visit.ref;
        return row;
      }):[];
      window.dispatchEvent(new CustomEvent('vp3:participant-presence',{detail:{active,context_allowed:active&&!!context,rows:visibleRows,context,observed_at:presenceObservedAt,sequence:++presenceSequence,capture_generation:generation,greetings_allowed:greetingAllowed(),last_event:presenceTransition,last_event_at:presenceTransitionAt,object_detection_active:active&&presenceObjectDetection,object_labels:active?presenceObjectLabels:[]}}));
      const mirrored=q('[data-eyes-mirror]').checked;video.style.transform=mirrored?'scaleX(-1)':'';
      window.VP3AgentOrb?.follow?.(q('[data-eyes-follow]').checked?rows:[],{mirrored});
      for(const [id,node] of boxNodes)if(!rows.some(r=>r.track_id===id)){node.remove();boxNodes.delete(id);}
      for(const row of rows){let b=boxNodes.get(row.track_id);if(!b){b=document.createElement('div');b.className='vp3-eyes-box';b.dataset.trackId=row.track_id;b.append(document.createElement('span'));boxNodes.set(row.track_id,b);q('.vp3-eyes-boxes').append(b);}const [x,y,w,h]=row.box;b.style.cssText=`left:${(mirrored?1-x-w:x)*100}%;top:${y*100}%;width:${w*100}%;height:${h*100}%`;b.dataset.tracking=row.tracking_state||'tracked';}
      paintSpeaker();
      q('[data-eyes-people]').textContent=rows.length?rows.map(r=>r.display_name?`${r.display_name} (provisional)`:`Participant ${r.track_id.slice(-5)} · unidentified`).join(' · '):'No participants in view.';
      publishDiagnostics();
    }
    async function hear(text,context={}){
      if(window.VP3TVWatching?.hear(text,context))return true;
      if(window.VP3ParticipantContinuity?.hear(text))return true;
      if(recognitionOffer&&stream&&!enrolling&&!savingIntroduction&&currentRows.length===1&&currentRows[0].track_id===recognitionOffer.track&&Date.now()<recognitionOffer.expires){
        const answer=window.VP3EyesCore.visualReply(text);
        if(answer==='decline'){recognitionOffer=null;window.VP3AgentOrb?.setIntroductionMode?.('');say('Your name is saved. No visual samples were saved.');return true;}
        if(recognitionOffer.prompted&&answer==='accept'){q('[data-eyes-profile]').value=recognitionOffer.id;q('[data-eyes-photo-refresh]').checked=true;q('[data-eyes-consent]').checked=true;recognitionOffer=null;window.VP3AgentOrb?.setIntroductionMode?.('');await enroll();return true;}
      }
      if(!stream||enrolling||savingIntroduction||!introduction||Date.now()>introduction.expires||currentRows.length!==1||currentRows[0].track_id!==introduction.track)return false;
      const name=window.VP3EyesCore.introductionName(text,true);if(!name)return false;
      const token=generation,track=introduction.track;const current=()=>token===generation&&!!stream&&currentRows.length===1&&currentRows[0].track_id===track;
      savingIntroduction=true;
      try{
        const profile=await adapter.introduceEyes({name,isCurrent:current});if(!current())return false;
        await loadProfiles();if(!current())return false;
        const event=core.introduce(track,profile);if(!event)return false;
        introduction=null;currentRows=currentRows.map(row=>({...row,participant_id:profile.id,display_name:profile.name,identity_source:'self_introduction'}));draw(currentRows);log(event);
        q('[data-eyes-profile]').value=String(profile.id);q('[data-eyes-name]').value='';q('[data-eyes-consent]').checked=false;
        recognitionOffer={id:String(profile.id),track,expires:Date.now()+90000,prompted:true,spoken:false};window.VP3AgentOrb?.setIntroductionMode?.('visual-consent');
        const message=`Thanks, ${profile.name}. May I take three photos for your local Agent Eyes gallery and refresh them weekly while I recognize you? Say “yes” or “remember me visually”, or “no”.`;
        recognitionOffer.message=message;
        window.VP3AgentOrb?.bubble(message);say(message);return true;
      }catch(error){if(current())say(String(error.message||'Your name could not be saved. Please try again.'));return false;}
      finally{savingIntroduction=false;}
    }
    async function stop(reason='Camera is off.'){
      void window.VP3TVWatching?.stop();
      window.dispatchEvent(new Event('vp3:agent-eyes-stopped'));
      introduction=null;recognitionOffer=null;window.VP3AgentOrb?.setIntroductionMode?.('');
      generation++;starting=false;pendingEnrollment=false;stablePhoto=null;cameraIndicator(false);q('[data-eyes-consent]').checked=false;clearTimeout(timer);timer=0;
      if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;}video.srcObject=null;observations=[];currentRows=[];presenceObjectLabels=[];presenceObjectDetection=false;
      const endedAt=new Date().toISOString();core.clear().forEach(event=>log(event,endedAt,true));
      presenceCaptureBinding=null;pendingCaptureBinding=null;captureId='';arrivedTracks.clear();savedVisits.clear();draw([]);savedPortraits();say(reason);q('[data-eyes-start]').disabled=false;q('[data-eyes-stop]').disabled=true;
    }
    let detection=Promise.resolve();
    function detectFrame(snapshot=false){const next=detection.catch(()=>{}).then(()=>detectOneFrame(snapshot));detection=next;return next;}
    async function detectOneFrame(snapshot=false){
      let source=video;
      if(snapshot){source=document.createElement('canvas');source.width=video.videoWidth;source.height=video.videoHeight;if(!source.width||!source.height)throw Error('Wait for a clear camera frame.');source.getContext('2d').drawImage(video,0,0);}
      const tv=window.VP3TVWatching,enabled=tv?.needsDetection()===true;let result;
      try{result=await engine.detect(source,{object:{enabled}});}catch(error){if(!enabled)throw error;tv.detectorFailed();result=await engine.detect(source,{object:{enabled:false}});}
      return snapshot?{...result,portraitSource:source}:result;
    }
    function roomFaces(result){const tv=window.VP3TVWatching,boxes=tv?.screenBoxes(result.object,video.videoWidth,video.videoHeight)||[];return (result.face||[]).filter(face=>!tv?.faceOnScreen(face,boxes,video.videoWidth,video.videoHeight));}
    async function scan(token){
      if(token!==generation||!stream)return;
      let failureMessage='Camera privacy settings could not be checked.';
      try{
        if(!cameraSourceActive()){clearPresence();draw(currentRows);timer=setTimeout(()=>void scan(token),900);return;}
        settleInitialCapture();if(token!==generation||!stream)return;
        const liveContext=adapter.liveSensoryContext?.(),expectedChat=presenceCaptureBinding||pendingCaptureBinding;
        if(expectedChat&&(!sameIdentity(chatIdentity(),expectedChat)||(liveContext&&expectedChat.conversation_id&&!sameChat(liveContext,expectedChat))))return void stop('Camera stopped because the Chat context changed.');
        if(!await cameraAllowed())return void stop('Camera stopped by privacy or session settings.');
        if(token!==generation||!stream)return;
        if(enrolling||refreshing){timer=setTimeout(()=>void scan(token),900);return;}
        bindRestoredChat(liveContext&&sameChat(liveContext,adapter.liveSensoryContext?.())?liveContext:null);
        failureMessage='The local face detector could not process the camera frame.';
        const observedAt=new Date().toISOString(),result=await detectFrame();if(token!==generation||!stream)return;if(enrolling||refreshing){timer=setTimeout(()=>void scan(token),900);return;}
        // Check the current roster after inference before using any local samples.
        failureMessage='Participant profiles or local samples could not be checked.';
        await loadProfiles();if(token!==generation||!stream)return;
        failureMessage='Camera privacy settings could not be checked.';
        if(!await cameraAllowed())return void stop('Camera stopped by privacy settings.');
        if(token!==generation||!stream)return;
        if(!cameraSourceActive()){clearPresence();timer=setTimeout(()=>void scan(token),900);return;}
        failureMessage='Participant observations could not be processed.';
        observations=roomFaces(result).slice(0,8).map(f=>({box:[f.box[0]/video.videoWidth,f.box[1]/video.videoHeight,f.box[2]/video.videoWidth,f.box[3]/video.videoHeight],embedding:f.embedding?Array.from(f.embedding):null}));
        presenceObjectDetection=window.VP3TVWatching?.needsDetection()===true;
        presenceObjectLabels=presenceObjectDetection?[...new Set((Array.isArray(result.object)?result.object:[])
          .filter(item=>typeof item?.label==='string'&&/^[a-z][a-z0-9 _-]{0,63}$/i.test(item.label)&&Number.isFinite(item.score)&&item.score>=.5)
          .map(item=>item.label.toLowerCase()))].sort().slice(0,8):[];
        window.VP3TVWatching?.observe(result.object,video,()=>token===generation&&!!stream&&!document.hidden);
        const update=core.update(observations,profiles);currentRows=update.rows;
        for(const row of currentRows){const visit=savedVisits.get(row.track_id);if(visit&&(row.identity_source!=='local_visual_similarity'||row.participant_id!==visit.participant_id))savedVisits.delete(row.track_id);}
        update.events.forEach(event=>log(event,observedAt));draw(update.rows,observedAt);if(pendingEnrollment&&q('[data-eyes-consent]').checked&&currentRows.length===1)void enroll();
        if(adapter.isMainChat?.()===true&&!introduction&&currentRows.length===1&&!currentRows[0].participant_id
          &&update.events.some(event=>event.event_type==='arrival'&&event.track_id===currentRows[0].track_id)){
          // An explicit self-introduction can still use the consented local flow;
          // being eligible does not speak, create a profile or grant recognition.
          introduction={track:currentRows[0].track_id,expires:Date.now()+90000};
        }
        if(introduction&&(currentRows.length!==1||currentRows[0].track_id!==introduction.track||currentRows[0].participant_id)){introduction=null;window.VP3AgentOrb?.setIntroductionMode?.('');}
        if(recognitionOffer&&(Date.now()>recognitionOffer.expires||currentRows.length!==1||currentRows[0].track_id!==recognitionOffer.track)){recognitionOffer=null;window.VP3AgentOrb?.setIntroductionMode?.('');}
        if(recognitionOffer&&!recognitionOffer.spoken&&!document.hidden&&!adapter.busy()){
          const offer=recognitionOffer;offer.spoken=true;window.VP3AgentOrb?.setIntroductionMode?.('visual-consent');window.VP3AgentOrb?.bubble(offer.message);
          void Promise.resolve(adapter.greet(offer.message,()=>recognitionOffer===offer&&token===generation&&!!stream)).catch(()=>{});
        }
        if(!enrolling&&!savingIntroduction&&!recognitionOffer)void refreshPhoto(token,result);
        // Owner Chat's one model-driven engagement loop decides if/how to greet.
        // Standalone surfaces retain their existing local introduction flow.
        const greeting=adapter.isMainChat?.()!==true&&q('[data-eyes-greetings]').checked&&!document.hidden&&!enrolling&&!adapter.busy()?core.greeting():null;
        if(greeting){
          if(!greeting.participant_id){introduction={track:greeting.track_id,expires:Date.now()+90000};window.VP3AgentOrb?.setIntroductionMode?.('name');}
          // No approval click: show immediately and speak through the existing voice owner.
          window.VP3AgentOrb?.bubble(greeting.text);log(core.acknowledge(greeting.track_id));
          void Promise.resolve(adapter.greet(greeting.text,()=>token===generation&&!!stream&&q('[data-eyes-greetings]').checked)).catch(()=>{});
        }
        let chatStatus='';
        if(adapter.isMainChat?.()===true){
          if(presenceBinding)chatStatus=' Chat linked.';
          else if(pendingCaptureBinding)chatStatus=' Waiting for the saved Chat before sharing a fresh arrival.';
          else if(presenceCaptureBinding)chatStatus=' Chat currently out of focus.';
          else chatStatus=' Local only. Start the camera in a ready, saved Chat to share arrivals.';
        }
        say(`Camera active · ${update.rows.length} participant${update.rows.length===1?'':'s'} in view.`
          +chatStatus);
        void flush();
      }catch(_){if(token===generation)return void stop(`Agent Eyes stopped. ${failureMessage}`);}
      if(token===generation&&stream)timer=setTimeout(()=>void scan(token),900);
    }
    async function refreshPhoto(token,result){
      const row=currentRows.length===1?currentRows[0]:null,p=profiles.find(p=>String(p.id)===row?.participant_id),face=roomFaces(result)[0];
      if(!row||row.identity_source!=='local_visual_similarity'||roomFaces(result).length!==1||document.hidden||!window.VP3EyesCore.portraitDue(p)||!window.VP3EyesCore.refreshMatch(Array.from(face?.embedding||[]),profiles,p.id)){stablePhoto=null;return;}
      if(stablePhoto?.track!==row.track_id||stablePhoto?.id!==p.id){stablePhoto={track:row.track_id,id:p.id,since:Date.now()};return;}
      if(refreshing||Date.now()-stablePhoto.since<3000||Date.now()-(photoRetry.get(p.id)||0)<60000)return;
      refreshing=true;photoRetry.set(p.id,Date.now());photoEvent('capturing','Capturing a weekly photo refresh…');
      let terminal=false;const finish=(state,message)=>{terminal=true;photoEvent(state,message);};
      const current=()=>token===generation&&!!stream&&!document.hidden&&!enrolling&&currentRows.length===1&&currentRows[0].track_id===row.track_id&&currentRows[0].participant_id===p.id&&currentRows[0].identity_source==='local_visual_similarity';
      try{
        if(!await cameraAllowed()||!current())return;
        const fresh=await detectFrame(true),faces=roomFaces(fresh);if(!current()||faces.length!==1||!window.VP3EyesCore.refreshMatch(Array.from(faces[0].embedding||[]),profiles,p.id))return;
        const picture=portrait(faces[0],fresh.portraitSource);const saved=await adapter.refreshEyesPortrait({id:p.id,portrait:picture,isCurrent:current});if(!current())return;
        if(saved?.saved){finish('saved','A refreshed photo was saved to the gallery on this device.');await loadProfiles();if(!current())return;if(!q('[data-eyes-profile]').value)q('[data-eyes-profile]').value=p.id;if(q('[data-eyes-profile]').value===p.id)savedPortraits();enrollmentSay(`Updated ${p.name}’s saved photo. Next automatic refresh is in a week.`);}else finish('deferred','Photo refresh deferred. No new photo was saved.');
      }catch(error){if(current()){finish('failed',String(error.message||'Photo could not be saved. Will retry later.'));enrollmentSay(String(error.message||'Photo could not be saved. Will retry later.'));}}
      finally{refreshing=false;if(!terminal&&!document.hidden)finish('deferred','Photo refresh stopped before confirmation. Check the gallery before retrying.');}
    }
    async function start(){
      if(starting||stream)return;if(document.hidden)return;
      starting=true;const token=++generation;q('[data-eyes-start]').disabled=true;q('[data-eyes-stop]').disabled=false;
      captureId=crypto.randomUUID();presenceCaptureBinding=adapter.liveSensoryContext?.()||null;
      pendingCaptureBinding=!presenceCaptureBinding&&adapter.isMainChat?.()===true&&chatRuntime()?.isInitialRestorePending?.()===true?chatIdentity():null;
      presenceObservedAt='';presenceTransition='unknown';presenceTransitionAt='';publishDiagnostics();
      let acquired=null;
      try{
        if(!await cameraAllowed())throw Error('Camera is unavailable while privacy is enabled or another camera activity is running.');
        say('Loading local camera models…');
        if(!engine){
          const module=await import('https://cdn.jsdelivr.net/npm/@vladmandic/human@3.3.6/dist/human.esm.js');if(token!==generation)return;
          const Human=module.Human||module.default;
          engine=new Human({backend:'webgl',debug:false,modelBasePath:'https://cdn.jsdelivr.net/npm/@vladmandic/human@3.3.6/models/',face:{enabled:true,detector:{enabled:true,maxDetected:8,minConfidence:.65},mesh:{enabled:true},description:{enabled:true},iris:{enabled:false},emotion:{enabled:false},antispoof:{enabled:false},liveness:{enabled:false}},body:{enabled:false},hand:{enabled:false},object:{enabled:false},gesture:{enabled:false},segmentation:{enabled:false}});
          await engine.load();
        }
        if(token!==generation)return;
        if(!await cameraAllowed())throw Error('Camera privacy or session changed.');
        acquired=await navigator.mediaDevices.getUserMedia({video:{width:{ideal:640},height:{ideal:480},facingMode:{ideal:'user'}},audio:false});
        if(token!==generation){acquired.getTracks().forEach(t=>t.stop());return;}
        stream=acquired;acquired=null;cameraIndicator(true);for(const track of stream.getTracks()){
          track.addEventListener('ended',()=>{if(token===generation)void stop('Camera disconnected.');},{once:true});
          track.addEventListener('mute',()=>{if(token===generation)clearPresence();});
        }video.srcObject=stream;await video.play();if(token!==generation)return;starting=false;
        window.dispatchEvent(new Event('vp3:agent-eyes-started'));
        await scan(token);
      }catch(error){if(acquired)acquired.getTracks().forEach(t=>t.stop());if(token===generation)await stop(String(error.message||'Camera could not start.'));}
    }
    async function enroll(){
      if(enrolling||refreshing)return;pendingEnrollment=false;if(!stream||currentRows.length!==1||observations.length!==1)return enrollmentSay('Start the camera with exactly one participant in view.');
      if(!q('[data-eyes-consent]').checked)return enrollmentSay('The participant must agree to local recognition samples.');
      let id=q('[data-eyes-profile]').value;const name=q('[data-eyes-name]').value.trim();if(!id&&!name&&currentRows[0]?.participant_id){id=String(currentRows[0].participant_id);q('[data-eyes-profile]').value=id;}if(!id&&!name)return enrollmentSay('Choose a participant or enter their name.');
      enrolling=true;const token=generation,track=currentRows[0].track_id,reference=observations[0].embedding,samples=[],pictures=[];photoEvent('capturing','Capturing photo 1 of 3…');enrollmentSay('Capturing visual sample 1 of 3…');q('[data-eyes-enroll]').disabled=true;
      try{
        for(let i=0;i<3;i++){
          if(i)await new Promise(resolve=>setTimeout(resolve,700));
          if(token!==generation||!stream||!q('[data-eyes-consent]').checked||!await cameraAllowed())throw Error('Enrollment stopped.');
          const result=await detectFrame(true);
          if(token!==generation||!stream||!q('[data-eyes-consent]').checked||roomFaces(result).length!==1||currentRows[0]?.track_id!==track||currentRows.length!==1)throw Error('Keep the same participant alone in view.');
          const embedding=Array.from(roomFaces(result)[0].embedding||[]);if(window.VP3EyesCore.similarity(embedding,reference)<.8)throw Error('Keep the same participant alone in view.');if(window.VP3EyesCore.similarity(embedding,embedding)<.99)throw Error('Visual sample unavailable.');if(samples.length&&window.VP3EyesCore.similarity(embedding,samples[0])<.8)throw Error('Keep the same participant alone in view.');samples.push(embedding);pictures.push(portrait(roomFaces(result)[0],result.portraitSource));showPortraits(pictures,true);enrollmentSay(`Visual sample ${samples.length} of 3 captured…`);photoEvent('capturing',`Photo ${samples.length} of 3 captured. Not saved yet.`);
        }
        if(token!==generation||!q('[data-eyes-consent]').checked)throw Error('Enrollment stopped.');
        photoEvent('saving','Three photos captured. Saving to the local gallery…');
        const saved=await adapter.enrollEyes({id,name,embeddings:samples,portraits:pictures,portraitRefreshEnabled:q('[data-eyes-photo-refresh]').checked,isCurrent:()=>token===generation&&!!stream&&q('[data-eyes-consent]').checked&&currentRows.length===1&&currentRows[0].track_id===track});if(saved?.saved===false)throw Error('Photos were not saved. Please try again.');await loadProfiles();if(token!==generation||!stream)return;if(saved?.id)q('[data-eyes-profile]').value=String(saved.id);savedPortraits();q('[data-eyes-consent]').checked=false;q('[data-eyes-name]').value='';const message='Three photos saved to the Agent Eyes gallery on this device.';enrollmentSay(message);photoEvent('saved',message);window.VP3AgentOrb?.bubble(message);
      }catch(error){savedPortraits();const message=String(error.message||'Photos could not be saved.');enrollmentSay(message);photoEvent('failed',message);window.VP3AgentOrb?.bubble(message);}finally{enrolling=false;q('[data-eyes-enroll]').disabled=false;}
    }
    async function requestEnrollment(startCamera=false){if(!q('[data-eyes-consent]').checked)return enrollmentSay('The participant must agree to local recognition samples.');pendingEnrollment=true;if(!stream&&startCamera&&!starting)await start();if(stream&&currentRows.length===1&&!enrolling&&!refreshing)await enroll();else if(!enrolling){const message='Authorized. Waiting for the camera and one clear participant; choose a participant or enter a name.';enrollmentSay(message);photoEvent('waiting',message);}}
    q('[data-eyes-consent]').addEventListener('change',()=>{pendingEnrollment=q('[data-eyes-consent]').checked;if(pendingEnrollment)void requestEnrollment();});
    q('[data-eyes-profile]').addEventListener('change',savedPortraits);
    q('[data-eyes-start]').addEventListener('click',()=>void start());q('[data-eyes-stop]').addEventListener('click',()=>void stop());q('[data-eyes-close]').addEventListener('click',()=>{panel.hidden=true;});q('[data-eyes-enroll]').addEventListener('click',()=>void requestEnrollment(true));
    q('[data-eyes-forget]').addEventListener('click',async()=>{const id=q('[data-eyes-profile]').value;if(!id)return say('Choose a participant.');q('[data-eyes-consent]').checked=false;pendingEnrollment=false;recognitionOffer=null;window.VP3AgentOrb?.setIntroductionMode?.('');try{await adapter.forgetEyes(id);window.dispatchEvent(new CustomEvent('vp3:participant-forgotten',{detail:{id,name:profiles.find(p=>String(p.id)===id)?.name||''}}));await loadProfiles();savedPortraits();const endedAt=new Date().toISOString();core.clear().forEach(event=>log(event,endedAt,true));currentRows=[];observations=[];introduction=null;recognitionOffer=null;window.VP3AgentOrb?.setIntroductionMode?.('');draw([]);window.VP3VoiceGuard?.clear();say('Local visual samples removed.');}catch(_){say('Local samples could not be removed.');}});
    const hide=()=>{if(document.hidden&&pauseHidden.checked)void stop('Camera stopped while this page is hidden.');};document.addEventListener('visibilitychange',hide);
    const clearHidden=()=>{if(document.hidden)clearPresence();};document.addEventListener('visibilitychange',clearHidden);
    window.addEventListener('blur',clearPresence);
    window.addEventListener('vp3:chat-context-ready',settleInitialCapture);
    window.addEventListener('stonefellow:chat-context-changing',event=>{
      clearPresence();if(adapter.isMainChat?.()!==true||(!stream&&!starting))return;
      if(event.detail?.initialRestore===true&&chatRuntime()?.isInitialRestorePending?.()===true&&sameIdentity(chatIdentity(),pendingCaptureBinding)&&!pendingCaptureBinding.conversation_id)return;
      void stop('Camera stopped because the Chat context changed.');
    });
    const retry=setInterval(()=>void flush(),5000);
    window.addEventListener('pagehide',()=>{void stop();clearInterval(retry);clearTimeout(speakerTimer);window.removeEventListener('vp3:active-speaker',paintSpeaker);document.removeEventListener('visibilitychange',hide);},{once:true});
    function rejectCurrent(){introduction=null;recognitionOffer=null;window.VP3AgentOrb?.setIntroductionMode?.('');if(!stream||currentRows.length!==1)return;const track=currentRows[0].track_id;const event=core.reject(track);currentRows=currentRows.map(row=>({...row,participant_id:'',display_name:'',identity_source:'unidentified'}));log(event);draw(currentRows);introduction={track,expires:Date.now()+90000};window.VP3AgentOrb?.setIntroductionMode?.('name');window.VP3AgentOrb?.bubble('Thanks for correcting me. What is your name?');}
    async function refreshProfiles(){await loadProfiles();currentRows=currentRows.map(row=>{const p=profiles.find(p=>String(p.id)===String(row.participant_id));if(p&&(row.identity_source!=='local_visual_similarity'||localSamples(p)))return {...row,display_name:p.name};savedVisits.delete(row.track_id);return {...row,participant_id:'',display_name:'',identity_source:'unidentified'};});draw(currentRows);}
    function acceptsPhotoReply(text){return !!recognitionOffer&&recognitionOffer.prompted&&!!stream&&!enrolling&&!savingIntroduction&&Date.now()<recognitionOffer.expires&&currentRows.length===1&&currentRows[0].track_id===recognitionOffer.track&&!!window.VP3EyesCore.visualReply(text);}
    window.VP3AgentEyes={acceptsPhotoReply,start,rejectCurrent,refreshProfiles,diagnostics,toggle:()=>{panel.hidden=!panel.hidden;if(!panel.hidden)void loadProfiles().then(savedPortraits).catch(()=>say('Participant list unavailable.'));},stop,hear,expectingIntroduction:()=>!!stream&&!!introduction&&Date.now()<introduction.expires&&currentRows.length===1&&currentRows[0].track_id===introduction.track,isActive:()=>!!stream};
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>void boot(),{once:true});else void boot();
})();
