(()=>{'use strict';
const boot=window.VP3Meeting;if(!boot)return;
const $=(s,r=document)=>r.querySelector(s);const $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
const lobby=$('#meetingLobby'),joinBtn=$('#meetingJoin'),errorEl=$('#meetingRoomError'),grid=$('#participantGrid'),panel=$('#meetingAgentPanel');
const participantCount=$('#meetingParticipantCount');let room=null;let connected=false;let joining=false,joinGeneration=0,captureTicket=null;let ending=false;let transcriptTimer=null;let lastTranscriptId=0;let processingStatus=null;
let reconnecting=false,pollGeneration=0,pollController=null,mediaQueue=Promise.resolve(),mediaBusy=false;
const requests=new Set(),attachedTracks=new Map();
const identityKey=(v)=>encodeURIComponent(String(v||''));

async function post(endpoint,extra={},signal=null){
 const controller=new AbortController();requests.add(controller);
 const cancel=()=>controller.abort();signal?.addEventListener('abort',cancel,{once:true});if(signal?.aborted)cancel();
 let timer,abortHandler;
 const aborted=new Promise((_,reject)=>{abortHandler=()=>reject(new Error('Meeting request was cancelled.'));controller.signal.addEventListener('abort',abortHandler,{once:true});if(controller.signal.aborted)abortHandler();});
 const deadline=new Promise((_,reject)=>{timer=setTimeout(()=>{reject(new Error('Meeting request timed out. Please try again.'));controller.abort();},15000);});
 try{
  const body=new URLSearchParams({meeting:boot.meeting||'',invite:boot.invite||'',csrf_token:boot.csrf||'',...extra});
  const response=(async()=>{const res=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:body.toString(),credentials:'same-origin',cache:'no-store',signal:controller.signal});const data=await res.json();if(!res.ok||!data.ok){const error=new Error(data.error||'Meeting request failed.');error.status=res.status;throw error;}return data;})();
  return await Promise.race([response,deadline,aborted]);
 }finally{clearTimeout(timer);requests.delete(controller);signal?.removeEventListener('abort',cancel);controller.signal.removeEventListener('abort',abortHandler);}
}
function cancelRequests(){for(const controller of requests)controller.abort();}
function setError(message=''){if(errorEl)errorEl.textContent=message;}
function setTranscriptMessage(message=''){const el=$('#meetingTranscriptBridgeState');if(el&&message)el.textContent=message;}
function handleAgentDispatch(dispatch){if(!dispatch||typeof dispatch!=='object')return;const reason=String(dispatch.reason||'');if(reason==='homeserver_dispatch_failed'){processingStatus={...(processingStatus||{}),route:'blocked',status:'required_unavailable',reason_code:'homeserver_dispatch_failed',ready:false};setTranscriptMessage('Private processing required · HomeServer meeting processing unavailable');panel?.classList.add('open');return;}if(reason==='homeserver_created'||reason==='homeserver_already_running'){setTranscriptMessage('HomeServer meeting processing connected');return;}if(reason==='created'||reason==='already_dispatched'){setTranscriptMessage('Live transcription worker connected');return;}if(reason==='homeserver_private_processing_required'){setTranscriptMessage('Private processing policy active · cloud transcription off');return;}if(reason==='agent_off'||reason==='transcription_off'){setTranscriptMessage('Meeting Agent transcription is off');return;}if(reason==='agent_worker_not_configured'){setTranscriptMessage('Video connected · transcription worker setup required');return;}if(reason==='livekit_not_configured'||reason==='dispatch_failed'||dispatch.ok===false){setTranscriptMessage('Video connected · transcription worker unavailable');panel?.classList.add('open');}}
function handleProcessingStatus(status){if(!status||typeof status!=='object')return;const state=String(status.status||''),route=String(status.route||'');if(state==='required_unavailable'){setTranscriptMessage('Private processing required · HomeServer meeting processing unavailable');panel?.classList.add('open');return;}if(state==='private_required'){setTranscriptMessage('Private processing required · organizer resolves HomeServer readiness');return;}if(state==='policy_pending'){setTranscriptMessage('Transcription route pending organizer policy');return;}if(state==='closed'){setTranscriptMessage('Meeting processing is closed');return;}if(state==='ready'&&route==='homeserver'){setTranscriptMessage('HomeServer meeting processing ready');}}
function initials(name){return String(name||'?').trim().split(/\s+/).slice(0,2).map(v=>v.charAt(0).toUpperCase()).join('')||'?';}
function cardId(identity,screen=false){return 'vp3-participant-'+identityKey(identity)+(screen?'-screen':'-camera');}
function ensureCard(identity,name,isLocal=false,screen=false){let card=document.getElementById(cardId(identity,screen));if(card)return card;card=document.createElement('div');card.className='video-participant-card';card.id=cardId(identity,screen);card.dataset.identity=identity;card.innerHTML='<div class="video-participant-placeholder"></div><div class="video-participant-label"></div>';card.querySelector('.video-participant-placeholder').textContent=initials(name);card.querySelector('.video-participant-label').textContent=(name||'Participant')+(isLocal?' (You)':'')+(screen?' — Screen':'');grid.appendChild(card);return card;}
function removeCard(identity,screen=false){const card=document.getElementById(cardId(identity,screen));if(card)card.remove();updateCount();}
function mediaHost(card){return card;}
function attachTrack(track,participant,isLocal=false){if(!track||!participant||attachedTracks.has(track))return;const LK=window.LivekitClient;const screen=track.source===LK.Track.Source.ScreenShare||track.source===LK.Track.Source.ScreenShareAudio;const card=ensureCard(participant.identity,participant.name||participant.identity,isLocal,screen);if(track.kind===LK.Track.Kind.Video){const el=track.attach();el.autoplay=true;el.playsInline=true;mediaHost(card).insertBefore(el,card.firstChild);card.classList.add('has-video');attachedTracks.set(track,{participant});}else if(track.kind===LK.Track.Kind.Audio&&!isLocal){const el=track.attach();el.autoplay=true;mediaHost(card).appendChild(el);attachedTracks.set(track,{participant});}updateCount();}
function detachTrack(track,participant){if(!track||!participant)return;const LK=window.LivekitClient;const screen=track.source===LK.Track.Source.ScreenShare||track.source===LK.Track.Source.ScreenShareAudio;track.detach().forEach(el=>el.remove());attachedTracks.delete(track);const card=document.getElementById(cardId(participant.identity,screen));if(card&&!card.querySelector('video'))card.classList.remove('has-video');if(screen&&card&&!card.querySelector('video,audio'))removeCard(participant.identity,true);}
function renderParticipant(participant,isLocal=false){const card=ensureCard(participant.identity,participant.name||participant.identity,isLocal,false);card.querySelector('.video-participant-label').textContent=(participant.name||participant.identity)+(isLocal?' (You)':'');participant.trackPublications&&participant.trackPublications.forEach(pub=>{if(pub.track)attachTrack(pub.track,participant,isLocal);});updateCount();}
function updateCount(){if(!room)return;if(participantCount)participantCount.textContent=String(room.numParticipants||1)+' participant'+((room.numParticipants||1)===1?'':'s');}
function activeSpeakers(speakers){$$('.video-participant-card').forEach(c=>c.classList.remove('is-speaking'));(speakers||[]).forEach(p=>{const card=document.getElementById(cardId(p.identity,false));if(card)card.classList.add('is-speaking');});}
function setupRoomEvents(){
 const target=room;const on=(event,handler)=>{if(event)target.on(event,(...args)=>{if(room===target)handler(...args);});};const LK=window.LivekitClient;
 on(LK.RoomEvent.ParticipantConnected,p=>{renderParticipant(p,false);updateCount();});
 on(LK.RoomEvent.ParticipantDisconnected,p=>{for(const [track,info] of attachedTracks)if(info.participant.identity===p.identity)detachTrack(track,p);removeCard(p.identity,false);removeCard(p.identity,true);updateCount();});
 on(LK.RoomEvent.TrackSubscribed,(track,_pub,p)=>attachTrack(track,p,false));on(LK.RoomEvent.TrackUnsubscribed,(track,_pub,p)=>detachTrack(track,p));on(LK.RoomEvent.ActiveSpeakersChanged,activeSpeakers);
 on(LK.RoomEvent.LocalTrackPublished,(pub,p)=>{if(pub.track)attachTrack(pub.track,p,true);});on(LK.RoomEvent.LocalTrackUnpublished,(pub,p)=>{if(pub.track)detachTrack(pub.track,p);});
 on(LK.RoomEvent.Reconnecting,()=>{reconnecting=true;stopTranscriptPolling();setError('Connection interrupted. Reconnecting…');});
 on(LK.RoomEvent.SignalReconnecting,()=>{reconnecting=true;stopTranscriptPolling();setError('Connection interrupted. Reconnecting…');});
 on(LK.RoomEvent.Reconnected,()=>{reconnecting=false;setError('');renderParticipant(target.localParticipant,true);target.remoteParticipants.forEach(p=>renderParticipant(p,false));updateControlStates();updateCount();if(!joining&&!ending)startTranscriptPolling();});
 on(LK.RoomEvent.Disconnected,reason=>{const ended=Number.isInteger(reason)&&reason===LK.DisconnectReason?.ROOM_DELETED;void finishLocal(ended?'Meeting ended.':'Connection lost. You can rejoin the meeting.',!ended).then(()=>{if(ended)window.dispatchEvent(new CustomEvent('vp3:meeting-ended',{detail:{reviewUrl:boot.reviewUrl||''}}));});});
}
async function populateDevices(){const target=room,generation=joinGeneration;if(!target)return;for(const [kind,id] of [['audioinput','meetingMicDevice'],['videoinput','meetingCameraDevice'],['audiooutput','meetingSpeakerDevice']]){const select=document.getElementById(id);if(!select)continue;try{const devices=await window.LivekitClient.Room.getLocalDevices(kind,false);if(room!==target||generation!==joinGeneration)return;select.innerHTML='';devices.forEach(d=>{const o=document.createElement('option');o.value=d.deviceId;o.textContent=d.label||kind;select.appendChild(o);});const active=target.getActiveDevice(kind);if(active)select.value=active;}catch(_){if(room===target)select.closest('.meeting-device-row')?.classList.add('unavailable');}}}
function transcriptTime(ms){const seconds=Math.max(0,Math.floor(Number(ms||0)/1000));const m=Math.floor(seconds/60),s=seconds%60;return String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');}
function renderTranscriptSegment(segment){const list=$('#meetingTranscriptList');if(!list||!segment)return;list.querySelector('.meeting-agent-empty')?.remove();const row=document.createElement('article');row.className='meeting-transcript-row';row.dataset.segmentId=String(segment.id||'');const meta=document.createElement('div');meta.className='meeting-transcript-meta';const speaker=document.createElement('strong');speaker.textContent=String(segment.speaker_name||'Participant');const stamp=document.createElement('span');stamp.textContent=transcriptTime(segment.start_ms);meta.append(speaker,stamp);const text=document.createElement('p');text.textContent=String(segment.transcript_text||'');row.append(meta,text);list.appendChild(row);list.scrollTop=list.scrollHeight;}
function updateTranscriptState(state){const el=$('#meetingTranscriptBridgeState');if(!el||!state)return;if(processingStatus&&['required_unavailable','private_required','policy_pending','closed'].includes(String(processingStatus.status||''))){handleProcessingStatus(processingStatus);return;}if(Number(state.session_id||0)>0)el.textContent='Connected to VP3 Transcription #'+Number(state.session_id);else if(Number(state.segment_count||0)>0)el.textContent='Live transcript connected';else if(state.ready)el.textContent='Transcript bridge ready';else el.textContent='Transcript bridge needs database upgrade';}
async function pollTranscript(generation=pollGeneration){
 const target=room;const current=()=>connected&&!reconnecting&&!ending&&room===target&&generation===pollGeneration;
 if(!current())return;
 const controller=new AbortController();pollController=controller;
 try{
  const transcript=boot.transcriptionEnabled&&boot.transcriptEndpoint;
  const data=transcript?await post(boot.transcriptEndpoint,{after:String(lastTranscriptId)},controller.signal):await post(boot.presenceEndpoint,{action:'status'},controller.signal);
  if(!current())return;
  let added=0;const segments=(Array.isArray(data.segments)?data.segments:[]).slice().sort((a,b)=>Number(a.id)-Number(b.id));
  for(const segment of segments){const id=Number(segment.id);if(Number.isSafeInteger(id)&&id>lastTranscriptId){renderTranscriptSegment(segment);lastTranscriptId=id;added++;}}
  updateTranscriptState(data.state);if(added>0)window.dispatchEvent(new CustomEvent('vp3:meeting-transcript-updated',{detail:{lastTranscriptId,added}}));
  if(['cancelled','ended','processed'].includes(String(data.meeting_status||data.status||''))){await finishLocal('Meeting ended.');window.dispatchEvent(new CustomEvent('vp3:meeting-ended',{detail:{reviewUrl:boot.reviewUrl||''}}));return;}
 }catch(error){
  if(current()&&[401,403].includes(error.status)){await finishLocal('Meeting access expired. Reopen your invitation.');return;}
 }finally{if(pollController===controller)pollController=null;}
 if(current())transcriptTimer=setTimeout(()=>pollTranscript(generation),2500);
}
function startTranscriptPolling(){stopTranscriptPolling();if(connected&&!ending&&!reconnecting&&boot.presenceEndpoint)void pollTranscript(pollGeneration);}
function stopTranscriptPolling(){++pollGeneration;if(transcriptTimer){clearTimeout(transcriptTimer);transcriptTimer=null;}pollController?.abort();pollController=null;}
function stopLocalTracks(target){target?.localParticipant?.trackPublications?.forEach(pub=>{try{pub.track?.stop();}catch(_){}});}
async function closeLocalRoom(target){
 stopLocalTracks(target);let timer;
 if(target){try{await Promise.race([Promise.resolve(target.disconnect()).catch(()=>{}),new Promise(resolve=>{timer=setTimeout(resolve,5000);})]);}catch(_){}finally{clearTimeout(timer);}}
 stopLocalTracks(target);
}
async function finishLocal(message,rejoin=false){
 ++joinGeneration;joining=false;ending=true;connected=false;reconnecting=false;stopTranscriptPolling();cancelRequests();
 const target=room;room=null;captureTicket?.release();captureTicket=null;
 for(const [track,info] of attachedTracks){try{detachTrack(track,info.participant);}catch(_){}}attachedTracks.clear();
 const closing=closeLocalRoom(target);showEnded(message,rejoin);if(joinBtn)joinBtn.disabled=false;window.dispatchEvent(new CustomEvent('vp3:meeting-left',{detail:{reason:message}}));
 if(rejoin&&boot.presenceEndpoint)void post(boot.presenceEndpoint,{action:'leave'}).catch(()=>{});
 await closing;
}
async function join(){
 if(connected||joining)return;
 setError('');const generation=++joinGeneration;joining=true;ending=false;joinBtn.disabled=true;
 const lease=window.StonefellowVoiceLeaseV122;let target=null,ticket=null;
 const current=()=>generation===joinGeneration&&(!ticket||ticket.isCurrent());
 try{
  if(lease){ticket=lease.acquireCapture('meeting');if(!ticket&&window.confirm('Another tab is using voice capture. Switch capture to this meeting?'))ticket=lease.acquireCapture('meeting',{takeover:true});if(!ticket)throw new Error('Another surface is using voice capture. Stop it there first.');captureTicket=ticket;}
  if(!window.LivekitClient)throw new Error('The meeting media client did not load.');
  const displayName=String($('#meetingDisplayName')?.value||boot.participantName||'Guest').trim();
  const auth=await post(boot.tokenEndpoint,{display_name:displayName});if(!current())return;
  processingStatus=auth?.meeting?.processing_status&&typeof auth.meeting.processing_status==='object'?auth.meeting.processing_status:null;
  const LK=window.LivekitClient;target=new LK.Room({adaptiveStream:true,dynacast:true,disconnectOnPageLeave:true});room=target;setupRoomEvents();
  await target.connect(auth.server_url,auth.participant_token,{autoSubscribe:true});if(!current())return;
  connected=true;renderParticipant(target.localParticipant,true);target.remoteParticipants.forEach(p=>renderParticipant(p,false));
  const mic=$('#joinMic')?.checked!==false,cam=$('#joinCamera')?.checked!==false;
  await target.localParticipant.setMicrophoneEnabled(mic);if(!current())return;
  await target.localParticipant.setCameraEnabled(cam);if(!current())return;
  await target.startAudio().catch(()=>{});if(!current())return;
  const presence=await post(boot.presenceEndpoint,{action:'join'});if(!current())return;
  if(['ended','processed','cancelled'].includes(String(presence.status||'')))throw new Error('This meeting is closed.');
  if(presence?.processing_status&&typeof presence.processing_status==='object')processingStatus=presence.processing_status;
  handleAgentDispatch(presence.agent_dispatch);handleProcessingStatus(processingStatus);lobby?.classList.add('hidden');updateControlStates();updateCount();populateDevices();startTranscriptPolling();
 }catch(err){
  if(generation===joinGeneration){setError(err.message||'Could not join this meeting.');connected=false;processingStatus=null;stopTranscriptPolling();if(room===target)room=null;for(const [track,info] of attachedTracks){try{detachTrack(track,info.participant);}catch(_){}}attachedTracks.clear();grid?.replaceChildren();if(target)void post(boot.presenceEndpoint,{action:'leave'}).catch(()=>{});}
  await closeLocalRoom(target);ticket?.release();if(room===target)room=null;if(captureTicket===ticket)captureTicket=null;
 }finally{
  if(!current()){await closeLocalRoom(target);ticket?.release();if(room===target)room=null;if(captureTicket===ticket)captureTicket=null;}
  if(generation===joinGeneration){joining=false;joinBtn.disabled=false;}
 }
}
function updateControlStates(){if(!room)return;const p=room.localParticipant;$('#meetingMic')?.classList.toggle('off',!p.isMicrophoneEnabled);$('#meetingCamera')?.classList.toggle('off',!p.isCameraEnabled);$('#meetingShare')?.classList.toggle('off',!p.isScreenShareEnabled);if($('#meetingMic'))$('#meetingMic').textContent=p.isMicrophoneEnabled?'Mic on':'Mic off';if($('#meetingCamera'))$('#meetingCamera').textContent=p.isCameraEnabled?'Camera on':'Camera off';if($('#meetingShare'))$('#meetingShare').textContent=p.isScreenShareEnabled?'Stop share':'Share';}
async function changeLocalMedia(change){
 const target=room,generation=joinGeneration;if(!target||!connected||joining||reconnecting||ending)return;
 const run=async()=>{
  if(room!==target||generation!==joinGeneration||ending||reconnecting)return;
  try{await change(target);if(room===target&&generation===joinGeneration)updateControlStates();}
  finally{if(room!==target||generation!==joinGeneration)await closeLocalRoom(target);}
 };const task=mediaBusy?mediaQueue.then(run):run();mediaBusy=true;const tail=task.catch(()=>{}).finally(()=>{if(mediaQueue===tail)mediaBusy=false;});mediaQueue=tail;return task;
}
async function toggleMic(){return changeLocalMedia(target=>target.localParticipant.setMicrophoneEnabled(!target.localParticipant.isMicrophoneEnabled));}
async function toggleCamera(){return changeLocalMedia(target=>target.localParticipant.setCameraEnabled(!target.localParticipant.isCameraEnabled));}
async function toggleShare(){try{await changeLocalMedia(target=>target.localParticipant.setScreenShareEnabled(!target.localParticipant.isScreenShareEnabled));}catch(err){setError(err.message||'Screen sharing could not start.');}}
async function leave(){
 const closing=finishLocal('You left the meeting.');
 try{await post(boot.presenceEndpoint,{action:'leave'});}catch(_){}await closing;
}
async function endMeeting(){if(!boot.isOrganizer)return leave();if(ending||!confirm('End this meeting for everyone?'))return;const generation=joinGeneration,target=room;ending=true;stopTranscriptPolling();try{const ended=await post(boot.presenceEndpoint,{action:'end'});if(room!==target||generation!==joinGeneration)return;if(ended?.intelligence_review_url)boot.reviewUrl=String(ended.intelligence_review_url);}catch(err){if(room===target&&generation===joinGeneration){ending=false;setError(err.message);startTranscriptPolling();}return;}await finishLocal('Meeting ended.');window.dispatchEvent(new CustomEvent('vp3:meeting-ended',{detail:{reviewUrl:boot.reviewUrl||''}}));}
function showEnded(message,rejoin=false){const stage=$('.video-meeting-stage');if(stage){const href=boot.isOrganizer&&boot.reviewUrl?boot.reviewUrl:boot.meetingsUrl;const label=boot.isOrganizer&&boot.reviewUrl?'Review meeting intelligence':'Back to Meetings';stage.innerHTML='<div class="meeting-ended-card"><span class="meeting-lobby-kicker">VP3 Meeting</span><h1>'+escapeHtml(message)+'</h1><p>The meeting record stays connected to Calendar, Agent context and follow-up.</p>'+(rejoin?'<button class="meeting-join-button" id="meetingRejoin" type="button">Rejoin meeting</button>':'')+'<a class="meeting-join-button" href="'+escapeAttr(href)+'">'+escapeHtml(label)+'</a></div>';if(rejoin)$('#meetingRejoin')?.addEventListener('click',()=>window.location.reload());}panel?.classList.remove('open');}
function escapeHtml(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}function escapeAttr(s){return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');}
function setupTabs(){$$('.meeting-agent-tab').forEach(btn=>btn.addEventListener('click',()=>{$$('.meeting-agent-tab').forEach(b=>b.classList.remove('active'));$$('.meeting-agent-pane').forEach(p=>p.classList.remove('active'));btn.classList.add('active');document.getElementById('meetingPane-'+btn.dataset.pane)?.classList.add('active');}));}
function leaveBeacon(){if(!connected||!boot.presenceEndpoint)return;stopTranscriptPolling();const body=new URLSearchParams({meeting:boot.meeting||'',invite:boot.invite||'',csrf_token:boot.csrf||'',action:'leave'});try{navigator.sendBeacon?.(boot.presenceEndpoint,new Blob([body.toString()],{type:'application/x-www-form-urlencoded;charset=UTF-8'}));}catch(_){/* unload delivery is best effort; local teardown must still run */}}
joinBtn?.addEventListener('click',join);$('#meetingMic')?.addEventListener('click',()=>toggleMic().catch(e=>setError(e.message)));$('#meetingCamera')?.addEventListener('click',()=>toggleCamera().catch(e=>setError(e.message)));$('#meetingShare')?.addEventListener('click',toggleShare);$('#meetingLeave')?.addEventListener('click',leave);$('#meetingEnd')?.addEventListener('click',endMeeting);$('#meetingAgentToggle')?.addEventListener('click',()=>panel?.classList.toggle('open'));$('#meetingDevices')?.addEventListener('click',()=>$('#meetingDevicePicker')?.toggleAttribute('hidden'));[['meetingMicDevice','audioinput'],['meetingCameraDevice','videoinput'],['meetingSpeakerDevice','audiooutput']].forEach(([id,kind])=>document.getElementById(id)?.addEventListener('change',e=>changeLocalMedia(target=>target.switchActiveDevice(kind,e.target.value)).catch(err=>setError(err.message))));setupTabs();window.addEventListener('beforeunload',leaveBeacon);window.addEventListener('stonefellow:voice-lease-lost',()=>{if(connected||joining)void leave();});window.addEventListener('pagehide',()=>{leaveBeacon();void finishLocal('You left the meeting.');});
})();
