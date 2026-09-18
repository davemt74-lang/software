
const $=id=>document.getElementById(id);
const ui={};
[
'connectionState','connectControls','pendingControls','shareWorkspace','deviceName','connectBtn','checkConnectionBtn','settingsBtn',
'thisPageTab','followingTab','liveTab','alertsTab','thisPageView','followingView','liveView','alertsView','refreshCaptureBtn','pageTitle','pageHost','sourceMeta','sourceStatus',
'followCurrentSourceBtn','openSourcePageBtn','selectedText','selectionCount','captureSummary','captureScreenshotBtn','screenshotPreview',
'screenshotImage','screenshotMeta','removeScreenshotBtn','captureMediaBtn','mediaDetectedText','mediaPreview','mediaPreviewTitle','mediaStart',
'mediaEnd','mediaClipHint','removeMediaBtn','recordCommentaryBtn','commentaryStatus','commentaryPreview','commentaryAudio','commentaryMeta',
'removeCommentaryBtn','visibilitySelect','visibilityTeamField','visibilityTeamSelect','destinationSelect','shareNote','shareBtn','successCard',
'shareResultText','shareMediaResult','askAgentBtn','saveKnowledgeBtn','createTaskBtn','openSourceBtn','openMessagesBtn','thisPageFeed',
'thisPageEmpty','thisPageCount','loadMoreThisPageBtn','followingFeed','followingEmpty','loadMoreFollowingBtn','refreshFollowingBtn',
'researchDialog','closeResearchDialogBtn','researchPlacements','researchProjectSelect','researchProjectNote','researchProjectTags','researchInboxBtn','researchAddBtn','newResearchProjectTitle','createResearchProjectBtn','openResearchHubBtn',
'refreshLiveBtn','liveSourceTitle','liveSourceHost','liveRoomTitle','liveScope','liveTeamField','liveTeamSelect','liveAllowCloak','liveEnterCloaked','startLiveRoomBtn','liveDirectory','liveRoomCount','liveRoomsEmpty','liveRoomsList','liveRoomPanel','joinedLiveTitle','joinedLiveMeta','openLiveRoomBtn','liveCloakBtn','leaveLiveRoomBtn','endLiveRoomBtn','liveParticipants','liveMessages','liveMessageInput','sendLiveMessageBtn',
'refreshAlertsBtn','alertsSourceTitle','alertsSourceHost','sourceChangeBadge','fileClaimBtn','notificationSettingsBtn','sourceHistoryEmpty','sourceHistoryList','sourceClaimsEmpty','sourceClaimsList','alertUnreadCount','notificationsEmpty','notificationsList','claimDialog','closeClaimDialogBtn','claimStatement','claimRationale','claimVisibility','claimTeamField','claimTeamSelect','claimSubmitBtn','notice'
].forEach(id=>ui[id]=$(id));

const CLIP_MAX_SECONDS = 90;
const COMMENTARY_MAX_BYTES = 16 * 1024 * 1024;
const MAX_CLIP=CLIP_MAX_SECONDS,MAX_COMMENTARY=COMMENTARY_MAX_BYTES;
let state=null,capture=null,destinations=null,lastShare=null,currentSource=null;
let screenshotCapture=null,mediaReference=null,commentaryCapture=null,recorder=null,stream=null,recordTimer=null,recordStarted=0,pollTimer=null,pageTimer=null;
let thisCursor='',followingCursor='',thisBusy=false,followingBusy=false,activeView='this_page';
let researchShareId='',researchContextData=null;
let liveRoomsData=[],liveRoom=null,liveCursor=0,livePollTimer=null,liveHeartbeatTimer=null,liveBusy=false;
let trustBusy=false,trustObservation=null,trustNotifications=null,trustClaims=[];

function msg(type,data){
  return new Promise((resolve,reject)=>chrome.runtime.sendMessage(Object.assign({type:type},data||{}),r=>{
    if(chrome.runtime.lastError)return reject(new Error(chrome.runtime.lastError.message));
    if(!r||!r.ok){const e=new Error((r&&r.error)||'Browser Companion request failed.');e.code=(r&&r.code)||'';return reject(e);}
    resolve(r.value);
  }));
}
const message=msg;
function note(text,kind){ui.notice.textContent=text;ui.notice.className='notice '+(kind||'');ui.notice.hidden=false;clearTimeout(note.t);note.t=setTimeout(()=>ui.notice.hidden=true,4500);}
async function fail(e){note(e.message,'error');if(e.code==='reconnect_required')await refreshState().catch(()=>{});}
function caps(){return new Set(Array.isArray(state&&state.capabilities)?state.capabilities:[]);}
function capabilityForAction(action){return action==='save_knowledge'?'knowledge.write':action==='create_task'?'task.propose':action==='ask_agent'?'agent.message':'';}
function dropCapability(cap){if(!state||!cap)return;state.capabilities=(state.capabilities||[]).filter(x=>x!==cap);renderCaps();}
function http(v){try{const u=new URL(String(v||''));return /^https?:$/.test(u.protocol)?u.href:'';}catch(e){return '';}}
function host(v){try{return new URL(v).hostname;}catch(e){return '';}}
function sec(v){v=Math.max(0,Math.floor(Number(v||0)));return Math.floor(v/60)+':'+String(v%60).padStart(2,'0');}
function date(v){if(!v)return '';let s=String(v);if(!s.includes('T'))s=s.replace(' ','T')+'Z';const d=new Date(s);return isNaN(d)?'':d.toLocaleString([],{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});}
function busy(btn,on,label){if(on){btn.dataset.old=btn.textContent;btn.disabled=true;if(label)btn.textContent=label;}else{if(btn.dataset.old)btn.textContent=btn.dataset.old;delete btn.dataset.old;renderCaps();}}
function destination(){const p=String(ui.destinationSelect.value||'').split(':'),id=Number(p[1]||0);return ['team_general','conversation'].includes(p[0])&&id>0?{kind:p[0],id:id}:null;}
function hasCapture(){return !!((capture&&String(capture.selected_text||'').trim())||(screenshotCapture&&screenshotCapture.data_url)||(mediaReference&&mediaReference.kind)||(commentaryCapture&&commentaryCapture.data_url));}
function summary(){const a=[];if(capture&&String(capture.selected_text||'').trim())a.push('Text');if(screenshotCapture)a.push('Screenshot');if(mediaReference)a.push('Media');if(commentaryCapture)a.push('Voice');ui.captureSummary.textContent=a.length?a.join(' + '):'Add capture';}
function renderCaps(){
  const c=caps(),shared=!!(lastShare&&lastShare.browser_share&&lastShare.chat_message),teamOk=ui.visibilitySelect.value!=='team'||Number(ui.visibilityTeamSelect.value)>0;
  ui.askAgentBtn.disabled=!shared||!c.has('agent.message');ui.saveKnowledgeBtn.disabled=!shared||!c.has('knowledge.write');ui.createTaskBtn.disabled=!shared||!c.has('task.propose');
  ui.openSourceBtn.disabled=!shared||!http(lastShare&&lastShare.source_url);ui.openMessagesBtn.disabled=!shared;
  ui.captureScreenshotBtn.disabled=!(capture&&capture.available);
  ui.shareBtn.disabled=!(state&&state.connected&&c.has('team.share.create')&&capture&&capture.available&&hasCapture()&&destination()&&teamOk&&!(recorder&&recorder.state==='recording'));
  const liveTeamOk=ui.liveScope.value!=='team'||Number(ui.liveTeamSelect.value)>0;
  ui.startLiveRoomBtn.disabled=!(state&&state.connected&&c.has('team.share.create')&&capture&&capture.available&&liveTeamOk);
  ui.sendLiveMessageBtn.disabled=!(liveRoom&&liveRoom.joined&&liveRoom.status==='active'&&c.has('team.share.create'));
  ui.fileClaimBtn.disabled=!(state&&state.connected&&c.has('team.share.create')&&currentSource&&currentSource.id);
  ui.claimSubmitBtn.disabled=!(state&&state.connected&&c.has('team.share.create')&&currentSource&&currentSource.id&&String(ui.claimStatement.value||'').trim()&&(ui.claimVisibility.value!=='team'||Number(ui.claimTeamSelect.value)>0));
  summary();
}
function clearRich(){screenshotCapture=mediaReference=commentaryCapture=null;ui.screenshotPreview.hidden=ui.mediaPreview.hidden=ui.commentaryPreview.hidden=true;ui.screenshotImage.removeAttribute('src');ui.commentaryAudio.removeAttribute('src');renderCaps();}
function renderCapture(x){
  const prev=capture&&capture.source_url;capture=x||{available:false};if(prev&&capture.source_url&&prev!==capture.source_url)clearRich();
  ui.pageTitle.textContent=capture.title||(capture.available?'Untitled page':'No shareable page');ui.pageHost.textContent=host(capture.source_url)||capture.reason||'';
  ui.liveSourceTitle.textContent=capture.title||(capture.available?'Untitled page':'No shareable page');ui.liveSourceHost.textContent=host(capture.source_url)||capture.reason||'';
  ui.alertsSourceTitle.textContent=capture.title||(capture.available?'Untitled page':'No shareable page');ui.alertsSourceHost.textContent=host(capture.source_url)||capture.reason||'';
  ui.selectedText.value=capture.selected_text||'';ui.selectionCount.textContent=new TextEncoder().encode(ui.selectedText.value).length.toLocaleString()+' / 32,768 bytes';
  const m=capture.media;ui.captureMediaBtn.hidden=!(m&&m.kind&&http(m.source_media_url));if(!ui.captureMediaBtn.hidden)ui.mediaDetectedText.textContent=(m.kind==='youtube_clip'?'YouTube':m.source_media_kind==='audio'?'Audio':'Video')+' at '+sec(m.current_time)+(m.duration?' of '+sec(m.duration):'');
  renderCaps();
}
function addOptions(label,rows){
  if(!Array.isArray(rows)||!rows.length)return;const g=document.createElement('optgroup');g.label=label;
  rows.forEach(r=>{if(!r||!r.kind||!Number(r.id))return;const o=new Option(String(r.name||'Conversation'),r.kind+':'+Number(r.id));g.append(o);});if(g.children.length)ui.destinationSelect.append(g);
}
function renderDestinations(p){
  destinations=(p&&p.destinations)||{recent:[],teams:[],conversations:[]};ui.destinationSelect.replaceChildren(new Option('Choose a team or conversation',''));
  addOptions('Recent',destinations.recent);addOptions('Teams',destinations.teams);addOptions('Conversations',destinations.conversations);
  ui.visibilityTeamSelect.replaceChildren(new Option('Choose team…',''));ui.liveTeamSelect.replaceChildren(new Option('Choose team…',''));ui.claimTeamSelect.replaceChildren(new Option('Choose team…',''));
  (destinations.teams||[]).forEach(r=>{if(r.kind==='team_general'&&Number(r.id)){const label=String(r.name||'Team').replace(/ · General$/,'');ui.visibilityTeamSelect.append(new Option(label,String(Number(r.id))));ui.liveTeamSelect.append(new Option(label,String(Number(r.id))));ui.claimTeamSelect.append(new Option(label,String(Number(r.id))));}});renderCaps();
}
function renderConnection(x){
  state=x;ui.connectControls.hidden=ui.pendingControls.hidden=ui.shareWorkspace.hidden=true;
  if(x.connected){ui.connectionState.textContent='Connected as '+((x.user&&x.user.display_name)||'VP3 user');ui.shareWorkspace.hidden=false;}
  else if(x.pending_connection){ui.connectionState.textContent='Waiting for VP3 approval';ui.pendingControls.hidden=false;}
  else{ui.connectionState.textContent='Not connected';ui.connectControls.hidden=false;}renderCaps();
}
function renderLast(x){
  lastShare=x||null;const ok=!!(x&&x.browser_share&&x.browser_share.id&&x.chat_message&&x.chat_message.conversation_id);ui.successCard.hidden=!ok;if(!ok)return renderCaps();
  ui.shareResultText.textContent='Annotation #'+String(x.browser_share.id).slice(0,8)+' was published.'+(x.idempotent_replay?' Existing share restored safely.':'');
  const n=Array.isArray(x.media)?x.media.length:0,errs=Array.isArray(x.media_errors)?x.media_errors:[];ui.shareMediaResult.hidden=!(n||errs.length);
  if(!ui.shareMediaResult.hidden){ui.shareMediaResult.dataset.error=errs.length?'1':'0';ui.shareMediaResult.textContent=errs.length?n+' attachments saved. '+errs.join(' '):n+' rich attachment'+(n===1?'':'s')+' saved.';}renderCaps();
}
async function loadDestinations(){const p=await msg('destinations');if(state&&Array.isArray(p&&p.capabilities))state.capabilities=p.capabilities;renderDestinations(p);}
function sourceAction(action,payload){return msg('source_action',{action:action,payload:payload||{}});}
function researchAction(action,payload){return msg('research_action',{action:action,payload:payload||{}});}
function renderResearchContext(context){
  researchContextData=context||null;
  const placements=Array.isArray(context&&context.placements)?context.placements:[];
  ui.researchPlacements.replaceChildren();
  if(placements.length){
    ui.researchPlacements.hidden=false;
    placements.forEach(p=>ui.researchPlacements.append(el('div','research-placement','Already in '+String(p.project_title||'Research project'))));
  }else ui.researchPlacements.hidden=true;
  const assigned=new Set(placements.map(p=>String(p.project_id||'')));
  ui.researchProjectSelect.replaceChildren(new Option('Choose project…',''));
  const projects=Array.isArray(context&&context.projects)?context.projects:[];
  projects.forEach(p=>{
    const scope=Number(p.team_id||0)>0?' · Team':' · Personal';const option=new Option(String(p.title||'Research project')+scope+(assigned.has(String(p.id))?' · already added':''),String(p.id||''));
    if(assigned.has(String(p.id)))option.disabled=true;
    ui.researchProjectSelect.append(option);
  });
  const canWrite=caps().has('knowledge.write');
  ui.researchProjectSelect.disabled=!canWrite||projects.length===0;
  ui.researchAddBtn.disabled=!canWrite||!ui.researchProjectSelect.value;
  ui.researchInboxBtn.disabled=!canWrite||Boolean(context&&context.in_inbox);
  ui.researchInboxBtn.textContent=context&&context.in_inbox?'Already in Inbox':'Save to Inbox';
  ui.createResearchProjectBtn.disabled=!canWrite;
}
async function openResearchDialog(browserShareId){
  researchShareId=String(browserShareId||'');researchContextData=null;
  if(!researchShareId)return;
  ui.researchProjectNote.value='';ui.researchProjectTags.value='';ui.newResearchProjectTitle.value='';
  ui.researchProjectSelect.replaceChildren(new Option('Loading projects…',''));ui.researchProjectSelect.disabled=true;
  ui.researchPlacements.hidden=true;ui.researchAddBtn.disabled=true;ui.researchInboxBtn.disabled=true;
  if(!ui.researchDialog.open)ui.researchDialog.showModal();
  try{renderResearchContext(await msg('research_context',{browser_share_id:researchShareId}));}
  catch(e){ui.researchDialog.close();await fail(e);}
}

function liveAction(action,payload){return msg('live_action',{action:action,payload:payload||{}});}
function liveRoomRow(r){
  const row=el('div','live-room-row',''),head=el('div','live-room-row-head',''),left=el('div','','');
  left.append(el('div','live-room-title',r.title||'Live Room'),el('div','live-room-meta',(r.scope==='team'?'Team':'Public')+' · '+Number(r.participant_count||0)+' present'+(r.allow_cloak?' · Cloak available':'')));head.append(left);
  const status=el('span','pill',r.joined?'Joined':'Live');head.append(status);row.append(head);
  const actions=el('div','live-room-row-actions',''),open=act(r.joined?'Open':'Join','live_room_select');open.dataset.roomId=String(r.id||'');actions.append(open);
  const page=act('Open page','live_room_page');page.dataset.roomUrl=String(r.url||'');actions.append(page);row.append(actions);return row;
}
function appendLiveMessage(m){
  if(!m||!m.id||ui.liveMessages.querySelector('[data-live-message-id="'+CSS.escape(String(m.id))+'"]'))return;
  const box=el('div','live-message'+(m.sender&&m.sender.is_self?' self':''),'');box.dataset.liveMessageId=String(m.id);
  const head=el('div','live-message-head','');head.append(el('span','live-message-name',m.sender&&m.sender.name||'Participant'));
  if(m.sender&&m.sender.cloaked)head.append(el('span','','Cloaked'));head.append(el('span','',date(m.created_at)));box.append(head);
  if(m.body)box.append(el('div','live-message-body',m.body));
  if(!(m.sender&&m.sender.is_self)){const report=act('Report','live_report');report.dataset.messageId=String(m.id||'');head.append(report);}
  if(m.annotation){const a=el('div','live-message-annotation','');a.append(el('strong','',m.annotation.source_identity&&m.annotation.source_identity.title||'Shared annotation'));if(m.annotation.selection)a.append(el('div','',m.annotation.selection));box.append(a);}
  ui.liveMessages.append(box);ui.liveMessages.scrollTop=ui.liveMessages.scrollHeight;
}
function renderJoinedLiveRoom(r,resetMessages=false){
  liveRoom=r||null;ui.liveRoomPanel.hidden=!liveRoom;
  if(!liveRoom)return renderCaps();
  ui.joinedLiveTitle.textContent=liveRoom.title||'Live Room';ui.joinedLiveMeta.textContent=(liveRoom.scope==='team'?'Team':'Public')+' · '+Number(liveRoom.participant_count||0)+' present';
  ui.liveCloakBtn.hidden=!liveRoom.joined||!liveRoom.allow_cloak||liveRoom.status!=='active';ui.liveCloakBtn.textContent=liveRoom.cloak_mode?'Leave Cloak':'Cloak Mode';
  ui.leaveLiveRoomBtn.hidden=!liveRoom.joined;ui.endLiveRoomBtn.hidden=!liveRoom.is_owner||liveRoom.status!=='active';
  ui.liveParticipants.replaceChildren();(liveRoom.participants||[]).forEach(p=>{const chip=el('span','live-person-chip'+(p.cloaked?' cloaked':''),p.name+(p.is_self?' · You':''));ui.liveParticipants.append(chip);});
  if(resetMessages){ui.liveMessages.replaceChildren();liveCursor=0;}renderCaps();
}
async function loadLiveRooms(){
  if(!state||!state.connected||!capture||!capture.available||liveBusy)return;liveBusy=true;
  try{
    const payload=await msg('live_rooms',{capture:capture});liveRoomsData=Array.isArray(payload&&payload.rooms)?payload.rooms:[];
    ui.liveRoomsList.replaceChildren();liveRoomsData.forEach(r=>ui.liveRoomsList.append(liveRoomRow(r)));ui.liveRoomsEmpty.hidden=liveRoomsData.length>0;ui.liveRoomCount.textContent=liveRoomsData.length?String(liveRoomsData.length):'';
    const joined=liveRoomsData.find(r=>r.joined);
    if(joined&&(!liveRoom||liveRoom.id!==joined.id)){renderJoinedLiveRoom(joined,true);startLiveTimers();await pollLiveRoom();}
    else if(liveRoom){const fresh=liveRoomsData.find(r=>r.id===liveRoom.id);if(fresh)renderJoinedLiveRoom({...liveRoom,...fresh},false);}
  }finally{liveBusy=false;}
}
async function pollLiveRoom(){
  if(!liveRoom||!liveRoom.id||activeView!=='live')return;
  try{const p=await msg('live_poll',{room:liveRoom.id,after:liveCursor});renderJoinedLiveRoom(p.room,false);(p.messages||[]).forEach(appendLiveMessage);liveCursor=Math.max(liveCursor,Number(p.cursor||0));}catch(e){}
}
async function heartbeatLiveRoom(){
  if(!liveRoom||!liveRoom.joined||liveRoom.status!=='active')return;
  try{await liveAction('heartbeat',{room:liveRoom.id});}catch(e){}
}
function startLiveTimers(){
  clearInterval(livePollTimer);clearInterval(liveHeartbeatTimer);
  livePollTimer=setInterval(pollLiveRoom,2000);liveHeartbeatTimer=setInterval(heartbeatLiveRoom,15000);heartbeatLiveRoom();
}
async function selectLiveRoom(roomId){
  const found=liveRoomsData.find(r=>String(r.id)===String(roomId));if(!found)return;
  if(!found.joined){
    const joined=await liveAction('join',{room:found.id,cloak_mode:Boolean(ui.liveEnterCloaked.checked)});
    renderJoinedLiveRoom(joined.room,true);note('Joined Live Room.','success');
  }else renderJoinedLiveRoom(found,true);
  startLiveTimers();await pollLiveRoom();await loadLiveRooms();
}

function trustAction(action,payload){return msg('trust_action',{action:action,payload:payload||{}});}
function alertItem(title,body,meta,unread){
  const box=el('div','alert-item'+(unread?' unread':''),''),head=el('div','alert-item-head','');
  head.append(el('div','alert-item-title',title),el('div','alert-item-meta',meta||''));box.append(head);
  if(body)box.append(el('div','alert-item-body',body));return box;
}
async function observeCurrentSource(){
  if(!capture||!capture.available||!/^[a-f0-9]{64}$/i.test(String(capture.page_text_sha256||'')))return null;
  const p=await msg('trust_observe',{capture:capture});trustObservation=p||null;
  if(p&&p.source&&p.source.id){currentSource={...(currentSource||{}),...p.source};sourceHead({source:currentSource});}
  return p;
}
function renderSourceTrust(history,claims){
  const changes=Array.isArray(history&&history.changes)?history.changes:[];
  ui.sourceHistoryList.replaceChildren();
  changes.forEach(ch=>{const from=ch.from&&ch.from.hash_short||'unknown',to=ch.to&&ch.to.hash_short||'unknown',box=alertItem('Source changed',ch.summary||'Observed source fingerprint changed.',date(ch.observed_at),false);box.append(el('div','version-arrow',from+' → '+to));const actions=el('div','alert-item-actions','');const compare=act('Compare','source_compare');compare.dataset.url=String(ch.compare_url||'');actions.append(compare);box.append(actions);ui.sourceHistoryList.append(box);});
  ui.sourceHistoryEmpty.hidden=changes.length>0;
  const newest=changes[0];ui.sourceChangeBadge.textContent=newest?'Changed · '+date(newest.observed_at):'No observed change';ui.sourceChangeBadge.classList.toggle('changed',Boolean(newest));
  trustClaims=Array.isArray(claims)?claims:[];ui.sourceClaimsList.replaceChildren();
  trustClaims.forEach(cl=>{const box=alertItem(cl.statement||'Claim','',date(cl.created_at),false),meta=el('div','claim-status',String(cl.status||'open').replace(/_/g,' ')),actions=el('div','alert-item-actions','');box.append(meta);const open=act('Open','claim_open');open.dataset.url=String(cl.url||'');actions.append(open);const report=act('Report','claim_report');report.dataset.claimId=String(cl.id||'');actions.append(report);box.append(actions);ui.sourceClaimsList.append(box);});
  ui.sourceClaimsEmpty.hidden=trustClaims.length>0;
}
function renderTrustNotifications(payload){
  trustNotifications=payload||null;const wrap=(payload&&payload.notifications)||payload||{},items=Array.isArray(wrap.items)?wrap.items:[];
  ui.notificationsList.replaceChildren();items.forEach(n=>{const box=alertItem(n.title||'Notification',n.body||'',date(n.created_at),!n.read),actions=el('div','alert-item-actions','');const open=act(n.read?'Open':'Open · mark read','notification_open');open.dataset.notificationId=String(n.id||'');open.dataset.url=String(n.target_url||'');actions.append(open);box.append(actions);ui.notificationsList.append(box);});ui.notificationsEmpty.hidden=items.length>0;const unread=Number(wrap.unread||0);ui.alertUnreadCount.textContent=unread?unread+' unread':'';ui.alertUnreadCount.hidden=!unread;
}
async function loadAlerts(){
  if(!state||!state.connected||trustBusy)return;trustBusy=true;
  try{
    if(capture&&capture.available)await observeCurrentSource().catch(()=>null);
    const notifications=await msg('trust_notifications',{limit:50});renderTrustNotifications(notifications);
    if(currentSource&&currentSource.id){
      const [history,claims]=await Promise.all([msg('trust_history',{source_id:currentSource.id,limit:25}),msg('trust_claims',{source_id:currentSource.id})]);
      renderSourceTrust(history,claims&&claims.claims||[]);
    }else renderSourceTrust({changes:[]},[]);
  }finally{trustBusy=false;renderCaps();}
}

function setView(v){
  activeView=v;const page=v==='this_page',following=v==='following',live=v==='live',alerts=v==='alerts';
  ui.thisPageView.hidden=!page;ui.followingView.hidden=!following;ui.liveView.hidden=!live;ui.alertsView.hidden=!alerts;
  ui.thisPageTab.classList.toggle('active',page);ui.followingTab.classList.toggle('active',following);ui.liveTab.classList.toggle('active',live);ui.alertsTab.classList.toggle('active',alerts);
  ui.thisPageTab.setAttribute('aria-selected',String(page));ui.followingTab.setAttribute('aria-selected',String(following));ui.liveTab.setAttribute('aria-selected',String(live));ui.alertsTab.setAttribute('aria-selected',String(alerts));
  if(following)loadFollowing(true).catch(fail);if(live)loadLiveRooms().then(pollLiveRoom).catch(fail);if(alerts)loadAlerts().catch(fail);
}
function sourceHead(feed){currentSource=(feed&&feed.source)||null;ui.sourceMeta.hidden=!(capture&&capture.available);ui.sourceStatus.textContent=currentSource&&currentSource.id?'Recognized source':'New source';ui.followCurrentSourceBtn.textContent=currentSource&&currentSource.following?'Unfollow source':'Follow source';ui.openSourcePageBtn.hidden=!(currentSource&&currentSource.page_url);renderCaps();}
function el(tag,cls,text){const x=document.createElement(tag);if(cls)x.className=cls;x.textContent=String(text||'');return x;}
function act(label,a,on){const b=document.createElement('button');b.type='button';b.textContent=label;b.dataset.action=a;if(on)b.classList.add('active');return b;}
function card(item){
  const c=el('article','feed-card','');c.dataset.shareId=item.id||'';c.dataset.unread=item.interactions&&item.interactions.unread?'1':'0';c._item=item;
  const top=el('div','feed-author',''),left=el('div','','');left.append(el('div','author-name',(item.sender&&item.sender.name)||'VP3 user'),el('div','feed-time',date((item.publication&&item.publication.published_at)||item.created_at)));top.append(left);
  if(item.sender&&Number(item.sender.id)!==Number(state&&state.user&&state.user.id)){const f=act(item.interactions&&item.interactions.following_user?'Following':'Follow','follow_user',item.interactions&&item.interactions.following_user);f.dataset.userId=String(item.sender.id);top.append(f);}c.append(top);
  c.append(el('div','feed-source-title',(item.source_identity&&item.source_identity.title)||(item.source&&item.source.title)||(item.source_identity&&item.source_identity.domain)||'Source'),el('div','feed-domain',(item.source_identity&&item.source_identity.domain)||(item.source&&item.source.domain)||''));
  if(item.selection)c.append(el('div','feed-quote',item.selection));if(item.note)c.append(el('div','feed-note',item.note));
  const badges=el('div','feed-badges',''),vis=String((item.publication&&item.publication.visibility)||'legacy');badges.append(el('span','pill',vis==='legacy'?'Shared':vis.charAt(0).toUpperCase()+vis.slice(1)));
  if(item.source_version&&item.source_version.badge)badges.append(el('span','pill'+(item.source_version.changed?' changed':''),item.source_version.badge));if(item.interactions&&item.interactions.unread)badges.append(el('span','pill unread','Unread'));c.append(badges);
  if(Array.isArray(item.media)&&item.media.length){const media=el('div','feed-media','');item.media.forEach(a=>{if(a.kind==='screenshot'&&a.content_url){const im=document.createElement('img');im.alt='Annotation screenshot';im.dataset.mediaPath=a.content_url;media.append(im);}else if(a.kind==='commentary_audio'&&a.content_url){const au=document.createElement('audio');au.controls=true;au.dataset.mediaPath=a.content_url;media.append(au);}else if(['youtube_clip','audio_reference','video_reference'].includes(a.kind))media.append(el('div','media-ref',String(a.kind).replace(/_/g,' ')+' · '+sec(a.metadata&&a.metadata.start_seconds)+'–'+sec(a.metadata&&a.metadata.end_seconds)));});c.append(media);}
  const actions=el('div','feed-actions','');actions.append(act(item.interactions&&item.interactions.saved?'Saved':'Save','save',item.interactions&&item.interactions.saved),act(item.interactions&&item.interactions.in_research?'Research Inbox':'Research','research',item.interactions&&item.interactions.in_research),act('Knowledge','knowledge'),act('Ask VP3','ask'),act('Share with Team','share_team'),act('Live','live'),act(item.source_identity&&item.source_identity.following?'Following source':'Follow source','follow_source',item.source_identity&&item.source_identity.following),act('Report','report'),act('Open context','context'));if(item.interactions&&item.interactions.unread)actions.append(act('Mark read','read'));c.append(actions);
  const comments=el('div','comments','');(item.comments||[]).forEach(cm=>{const box=el('div','comment'+(cm.parent_id?' reply':''),''),h=el('div','comment-head','');h.append(el('span','comment-author',(cm.user&&cm.user.name)||'VP3 user'),el('span','comment-time',date(cm.created_at)));const r=act('Reply','reply');r.dataset.commentId=cm.id||'';const report=act('Report','report_comment');report.dataset.commentId=cm.id||'';h.append(r,report);box.append(h,el('div','comment-body',cm.body||''));comments.append(box);});
  const compose=el('div','comment-compose',''),input=document.createElement('input');input.type='text';input.maxLength=4000;input.placeholder='Comment…';input.dataset.parentId='';compose.append(input,act('Send','comment'));comments.append(compose);c.append(comments);return c;
}
async function hydrate(root){for(const x of root.querySelectorAll('[data-media-path]:not([data-loaded])')){x.dataset.loaded='1';try{x.src=await msg('media_data',{path:x.dataset.mediaPath});}catch(e){x.replaceWith(el('div','media-ref','Media preview unavailable'));}}}
async function loadThis(reset){
  if(!state||!state.connected||!capture||!capture.available||thisBusy)return;thisBusy=true;
  try{const feed=await msg('this_page',{capture:capture,cursor:reset?'':thisCursor});if(reset){ui.thisPageFeed.replaceChildren();thisCursor='';}sourceHead(feed);if(reset&&currentSource&&currentSource.id&&/^[a-f0-9]{64}$/i.test(String(capture.page_text_sha256||'')))await observeCurrentSource().catch(()=>null);(feed.items||[]).forEach(i=>ui.thisPageFeed.append(card(i)));thisCursor=feed.next_cursor||'';ui.loadMoreThisPageBtn.hidden=!feed.has_more;ui.thisPageEmpty.hidden=ui.thisPageFeed.children.length>0;ui.thisPageCount.textContent=ui.thisPageFeed.children.length?String(ui.thisPageFeed.children.length):'';hydrate(ui.thisPageFeed);}finally{thisBusy=false;}
}
async function loadFollowing(reset){
  if(!state||!state.connected||followingBusy)return;followingBusy=true;
  try{const feed=await msg('following',{cursor:reset?'':followingCursor});if(reset){ui.followingFeed.replaceChildren();followingCursor='';}(feed.items||[]).forEach(i=>ui.followingFeed.append(card(i)));followingCursor=feed.next_cursor||'';ui.loadMoreFollowingBtn.hidden=!feed.has_more;ui.followingEmpty.hidden=ui.followingFeed.children.length>0;hydrate(ui.followingFeed);}finally{followingBusy=false;}
}
async function refreshCapture(withFeed){const x=await msg('capture');renderCapture(x);if(withFeed!==false)await loadThis(true);}
async function refreshState(){
  const x=await msg('state');renderConnection(x);if(x.connected){renderLast(x.last_share);if(x.pending_capture&&x.pending_capture.available&&x.pending_capture.selected_text){renderCapture(x.pending_capture);await message('clear_pending_capture').catch(()=>{});}else await refreshCapture(false);await loadDestinations();await loadThis(true);}else if(x.pending_connection)startPoll();
}
function startPoll(){clearInterval(pollTimer);pollTimer=setInterval(async()=>{try{const r=await msg('poll_connect');if(r.status==='approved'&&r.session){clearInterval(pollTimer);note('Browser connected to VP3.','success');await refreshState();}else if(['denied','expired'].includes(r.status)||r.reconnect_required){clearInterval(pollTimer);await refreshState();}}catch(e){clearInterval(pollTimer);fail(e);}},2000);}
function clip(changed){if(!mediaReference)return;const d=Math.max(0,Number(mediaReference.metadata.duration_seconds||0));let s=Math.max(0,Number(ui.mediaStart.value||0)),e=Math.max(s,Number(ui.mediaEnd.value||s));if(d){s=Math.min(s,d);e=Math.min(e,d);}if(e-s>MAX_CLIP){if(changed==='start')s=Math.max(0,e-MAX_CLIP);else e=s+MAX_CLIP;}mediaReference.metadata.start_seconds=Number(s.toFixed(3));mediaReference.metadata.end_seconds=Number(e.toFixed(3));ui.mediaStart.value=s;ui.mediaEnd.value=e;ui.mediaClipHint.textContent='Clip '+sec(s)+'–'+sec(e)+' · '+(e-s).toFixed(1)+'s · source timestamps only · maximum 90s.';}
async function record(){
  stream=await navigator.mediaDevices.getUserMedia({ audio: true });const types=['audio/webm;codecs=opus','audio/ogg;codecs=opus','audio/webm'],type=types.find(t=>MediaRecorder.isTypeSupported(t))||'',parts=[];recorder=new MediaRecorder(stream,type?{mimeType:type}:undefined);recordStarted=Date.now();recorder.ondataavailable=e=>{if(e.data&&e.data.size)parts.push(e.data);};
  recorder.onstop=async()=>{clearTimeout(recordTimer);stream&&stream.getTracks().forEach(t=>t.stop());stream=null;const duration=Math.min(MAX_CLIP,(Date.now()-recordStarted)/1000),blob=new Blob(parts,{type:recorder.mimeType||'audio/webm'});recorder=null;ui.recordCommentaryBtn.textContent='Voice commentary';ui.commentaryStatus.textContent='Add an audio note, up to 90 seconds';if(!blob.size)return;if(blob.size>MAX_COMMENTARY){note('Voice commentary is too large.','error');return;}const data=await new Promise((resolve,reject)=>{const r=new FileReader();r.onload=()=>resolve(String(r.result||''));r.onerror=reject;r.readAsDataURL(blob);});commentaryCapture={data_url:data,mime_type:blob.type||'audio/webm',metadata:{duration_seconds:Number(duration.toFixed(3))}};ui.commentaryAudio.src=data;ui.commentaryMeta.textContent=duration.toFixed(1)+' seconds';ui.commentaryPreview.hidden=false;renderCaps();};recorder.start(500);ui.recordCommentaryBtn.textContent='Stop recording';ui.commentaryStatus.textContent='Recording…';recordTimer=setTimeout(()=>{if(recorder&&recorder.state==='recording')recorder.stop();},MAX_CLIP*1000);renderCaps();
}
function absolute(path){if(/^https?:\/\//i.test(String(path||'')))return path;return String(state&&state.base_url||'').replace(/\/+$/,'')+'/'+String(path||'').replace(/^\/+/,'');}
async function feedClick(e){
  const b=e.target.closest('button[data-action]');if(!b)return;const c=b.closest('.feed-card');if(!c)return;const id=c.dataset.shareId,a=b.dataset.action,i=c._item||{};
  try{
    if(a==='reply'){const input=c.querySelector('.comment-compose input');input.dataset.parentId=b.dataset.commentId||'';input.placeholder='Reply…';input.focus();return;}
    if(a==='report_comment'){const reason=prompt('Why are you reporting this comment?');if(!reason)return;await trustAction('report_create',{target_type:'comment',target_id:b.dataset.commentId||'',reason:reason,detail:''});return note('Report submitted.','success');}
    if(a==='comment'){const input=c.querySelector('.comment-compose input'),body=input.value.trim();if(!body)return;await sourceAction('comment',{browser_share_id:id,body:body,parent_id:input.dataset.parentId||''});return reload();}
    if(a==='save'){await sourceAction('save',{browser_share_id:id,enabled:!b.classList.contains('active')});return reload();}
    if(a==='research'){await openResearchDialog(id);return;}
    if(a==='follow_source'){await sourceAction('follow_source',{source_id:i.source_identity&&i.source_identity.id||'',follow:!b.classList.contains('active')});return reload();}
    if(a==='follow_user'){await sourceAction('follow_user',{user_id:Number(b.dataset.userId||0),follow:!b.classList.contains('active')});return reload();}
    if(a==='read'){await sourceAction('read',{browser_share_id:id});return reload();}
    if(a==='share_team'){const d=destination();if(!d)return note('Choose a Team or conversation under Deliver to first.','error');await sourceAction('share_team',{browser_share_id:id,destination:d});return note('Annotation shared to VP3 Messages.','success');}
    if(a==='live'){if(!liveRoom||!liveRoom.joined){setView('live');return note('Join or start a Live Room on this source first.','error');}await liveAction('send',{room:liveRoom.id,body:'',browser_share_id:id});await pollLiveRoom();return note('Annotation shared to Live Room.','success');}
    if(a==='knowledge'){await msg('share_action',{action:'save_knowledge',browser_share_id:id});return note('Saved to Knowledge.','success');}
    if(a==='ask'){const r=await msg('share_action',{action:'ask_agent',browser_share_id:id});if(r&&r.handoff_url)await msg('open_url',{url:r.handoff_url});return;}
    if(a==='report'){const reason=prompt('Why are you reporting this annotation?');if(!reason)return;await trustAction('report_create',{target_type:'annotation',target_id:id,reason:reason,detail:''});return note('Report submitted.','success');}
    if(a==='context'&&i.annotation_url)await msg('open_url',{url:absolute(i.annotation_url)});
  }catch(err){fail(err);}
}
function reload(){return activeView==='following'?loadFollowing(true):activeView==='live'?loadLiveRooms():activeView==='alerts'?loadAlerts():loadThis(true);}

ui.connectBtn.onclick=async()=>{busy(ui.connectBtn,true,'Opening VP3…');try{await msg('connect',{device_name:ui.deviceName.value.trim()||'Chrome Browser'});await refreshState();startPoll();}catch(e){fail(e);}finally{busy(ui.connectBtn,false);}};
ui.checkConnectionBtn.onclick=()=>msg('poll_connect').then(refreshState).catch(fail);ui.settingsBtn.onclick=()=>chrome.runtime.openOptionsPage();
ui.thisPageTab.onclick=()=>setView('this_page');ui.followingTab.onclick=()=>setView('following');ui.liveTab.onclick=()=>setView('live');ui.alertsTab.onclick=()=>setView('alerts');ui.refreshCaptureBtn.onclick=()=>refreshCapture(true).catch(fail);ui.refreshFollowingBtn.onclick=()=>loadFollowing(true).catch(fail);ui.refreshLiveBtn.onclick=()=>loadLiveRooms().catch(fail);ui.refreshAlertsBtn.onclick=()=>loadAlerts().catch(fail);
ui.visibilitySelect.onchange=()=>{ui.visibilityTeamField.hidden=ui.visibilitySelect.value!=='team';renderCaps();};ui.visibilityTeamSelect.onchange=renderCaps;ui.destinationSelect.onchange=renderCaps;
ui.liveScope.onchange=()=>{ui.liveTeamField.hidden=ui.liveScope.value!=='team';renderCaps();};ui.liveTeamSelect.onchange=renderCaps;
ui.claimVisibility.onchange=()=>{ui.claimTeamField.hidden=ui.claimVisibility.value!=='team';renderCaps();};ui.claimTeamSelect.onchange=renderCaps;ui.claimStatement.oninput=renderCaps;
ui.liveAllowCloak.onchange=()=>{ui.liveEnterCloaked.disabled=!ui.liveAllowCloak.checked;if(!ui.liveAllowCloak.checked)ui.liveEnterCloaked.checked=false;};
ui.captureScreenshotBtn.onclick=async()=>{busy(ui.captureScreenshotBtn,true,'Select region on page…');try{const r=await message('capture_region');if(r.cancelled)return;screenshotCapture=r;ui.screenshotImage.src=r.data_url;ui.screenshotMeta.textContent=Number(r.metadata&&r.metadata.width||0)+' × '+Number(r.metadata&&r.metadata.height||0)+' px';ui.screenshotPreview.hidden=false;}catch(e){fail(e);}finally{busy(ui.captureScreenshotBtn,false);renderCaps();}};
ui.removeScreenshotBtn.onclick=()=>{screenshotCapture=null;ui.screenshotPreview.hidden=true;renderCaps();};
ui.captureMediaBtn.onclick=()=>{const m=capture&&capture.media;if(!m||!http(m.source_media_url))return;const s=Math.max(0,Number(m.current_time||0)),d=Math.max(0,Number(m.duration||0)),e=d?Math.min(d,s+30):s+30;mediaReference={kind:m.kind,metadata:{source_media_url:m.source_media_url,source_media_title:String(m.source_media_title||capture.title||'').slice(0,512),source_media_kind:m.source_media_kind||'',duration_seconds:d,start_seconds:s,end_seconds:e}};ui.mediaPreviewTitle.textContent=m.kind==='youtube_clip'?'YouTube moment':m.source_media_kind==='audio'?'Audio moment':'Video moment';ui.mediaStart.value=s;ui.mediaEnd.value=e;ui.mediaPreview.hidden=false;clip('end');renderCaps();};
ui.mediaStart.onchange=()=>clip('start');ui.mediaEnd.onchange=()=>clip('end');ui.removeMediaBtn.onclick=()=>{mediaReference=null;ui.mediaPreview.hidden=true;renderCaps();};
ui.recordCommentaryBtn.onclick=()=>{if(recorder&&recorder.state==='recording')recorder.stop();else record().catch(fail);};ui.removeCommentaryBtn.onclick=()=>{commentaryCapture=null;ui.commentaryPreview.hidden=true;ui.commentaryAudio.pause();ui.commentaryAudio.removeAttribute('src');renderCaps();};
ui.shareBtn.onclick=async()=>{const d=destination();if(!d)return note('Choose where to deliver this annotation.','error');if(ui.visibilitySelect.value==='team'&&!Number(ui.visibilityTeamSelect.value))return note('Choose a Team for visibility.','error');busy(ui.shareBtn,true,'Publishing…');try{const r=await msg('share',{capture:capture,destination:d,visibility:ui.visibilitySelect.value,visibility_team_id:Number(ui.visibilityTeamSelect.value||0),note:ui.shareNote.value,rich_media:{screenshot:screenshotCapture,media_reference:mediaReference,commentary:commentaryCapture},idempotency_key:crypto.randomUUID()});r.source_url=capture.source_url;renderLast(r);ui.shareNote.value='';clearRich();note('Annotation published.','success');await loadThis(true);}catch(e){fail(e);}finally{busy(ui.shareBtn,false);}};
async function lastAction(a,label){if(!lastShare||!lastShare.browser_share)return;try{const r=await msg('share_action',{action:a,browser_share_id:lastShare.browser_share.id});if(a==='ask_agent'&&r.handoff_url)await msg('open_url',{url:r.handoff_url});else note(label,'success');}catch(e){if(e.code==='capability_denied')dropCapability(capabilityForAction(a));fail(e);}}
ui.askAgentBtn.onclick=()=>lastAction('ask_agent','Opened in VP3.');ui.saveKnowledgeBtn.onclick=()=>lastAction('save_knowledge','Saved to Knowledge.');ui.createTaskBtn.onclick=()=>lastAction('create_task','Task created.');
ui.openSourceBtn.onclick=()=>{const u=http(lastShare&&lastShare.source_url);if(u)msg('open_url',{url:u});};ui.openMessagesBtn.onclick=()=>{if(lastShare&&lastShare.chat_message)msg('open_url',{url:absolute('/messages.php?conversation_id='+Number(lastShare.chat_message.conversation_id))});};
ui.followCurrentSourceBtn.onclick=async()=>{if(!capture)return;try{const follow=!(currentSource&&currentSource.following),r=await sourceAction('follow_source',{source_id:currentSource&&currentSource.id||'',url:capture.source_url,canonical_url:capture.canonical_url,title:capture.title,follow:follow});currentSource=r.source;sourceHead({source:r.source});note(follow?'Source followed.':'Source unfollowed.','success');}catch(e){fail(e);}};
ui.openSourcePageBtn.onclick=()=>{if(currentSource&&currentSource.page_url)msg('open_url',{url:absolute(currentSource.page_url)});};
ui.notificationSettingsBtn.onclick=()=>msg('open_url',{url:absolute('/notification-settings.php')});
ui.fileClaimBtn.onclick=()=>{if(!currentSource||!currentSource.id)return note('Observe or follow this source first.','error');ui.claimStatement.value='';ui.claimRationale.value='';ui.claimVisibility.value='public';ui.claimTeamField.hidden=true;ui.claimDialog.showModal();renderCaps();};
ui.claimSubmitBtn.onclick=async()=>{const statement=String(ui.claimStatement.value||'').trim();if(!statement||!currentSource||!currentSource.id)return;busy(ui.claimSubmitBtn,true,'Filing…');try{const r=await trustAction('claim_create',{source_id:currentSource.id,statement:statement,rationale:ui.claimRationale.value,visibility:ui.claimVisibility.value,team_id:Number(ui.claimTeamSelect.value||0)});ui.claimDialog.close();note('Claim filed.','success');if(r&&r.claim&&r.claim.url)await msg('open_url',{url:absolute(r.claim.url)});await loadAlerts();}catch(e){await fail(e);}finally{busy(ui.claimSubmitBtn,false);}};
ui.notificationsList.onclick=async e=>{const b=e.target.closest('button[data-action="notification_open"]');if(!b)return;try{await trustAction('notification_read',{notification_id:Number(b.dataset.notificationId||0)});if(b.dataset.url)await msg('open_url',{url:absolute(b.dataset.url)});await loadAlerts();}catch(err){await fail(err);}};
ui.sourceHistoryList.onclick=async e=>{const b=e.target.closest('button[data-action="source_compare"]');if(b&&b.dataset.url)await msg('open_url',{url:absolute(b.dataset.url)});};
ui.sourceClaimsList.onclick=async e=>{const b=e.target.closest('button[data-action]');if(!b)return;try{if(b.dataset.action==='claim_open'&&b.dataset.url)await msg('open_url',{url:absolute(b.dataset.url)});if(b.dataset.action==='claim_report'){const reason=prompt('Why are you reporting this claim?');if(!reason)return;await trustAction('report_create',{target_type:'claim',target_id:b.dataset.claimId,reason:reason,detail:''});note('Report submitted.','success');}}catch(err){await fail(err);}};
ui.researchProjectSelect.onchange=()=>{ui.researchAddBtn.disabled=!caps().has('knowledge.write')||!ui.researchProjectSelect.value;};
ui.researchInboxBtn.onclick=async()=>{if(!researchShareId)return;busy(ui.researchInboxBtn,true,'Saving…');try{await sourceAction('research',{browser_share_id:researchShareId,enabled:true});ui.researchDialog.close();note('Saved to Research Inbox.','success');await reload();}catch(e){await fail(e);}finally{busy(ui.researchInboxBtn,false);}};
ui.researchAddBtn.onclick=async()=>{const projectId=String(ui.researchProjectSelect.value||'');if(!researchShareId||!projectId)return;busy(ui.researchAddBtn,true,'Adding…');try{await researchAction('assign',{project_id:projectId,browser_share_id:researchShareId,note:ui.researchProjectNote.value,tags:ui.researchProjectTags.value});ui.researchDialog.close();note('Added to Research project.','success');await reload();}catch(e){await fail(e);}finally{busy(ui.researchAddBtn,false);}};
ui.createResearchProjectBtn.onclick=async()=>{const title=String(ui.newResearchProjectTitle.value||'').trim();if(!researchShareId||!title)return note('Enter a project title.','error');busy(ui.createResearchProjectBtn,true,'Creating…');try{const created=await researchAction('create_project',{title:title,description:''});const projectId=String(created&&created.project&&created.project.id||'');if(!projectId)throw new Error('Research project could not be created.');await researchAction('assign',{project_id:projectId,browser_share_id:researchShareId,note:ui.researchProjectNote.value,tags:ui.researchProjectTags.value});ui.researchDialog.close();note('Project created and annotation added.','success');await reload();}catch(e){await fail(e);}finally{busy(ui.createResearchProjectBtn,false);}};
ui.openResearchHubBtn.onclick=()=>{const url=researchContextData&&researchContextData.research_url;if(url)msg('open_url',{url:absolute(url)});};
ui.startLiveRoomBtn.onclick=async()=>{if(!capture||!capture.available)return;busy(ui.startLiveRoomBtn,true,'Starting…');try{const r=await liveAction('create',{title:ui.liveRoomTitle.value,scope:ui.liveScope.value,team_id:Number(ui.liveTeamSelect.value||0),allow_cloak:Boolean(ui.liveAllowCloak.checked),cloak_mode:Boolean(ui.liveEnterCloaked.checked),url:capture.source_url,canonical_url:capture.canonical_url,source_title:capture.title});ui.liveRoomTitle.value='';renderJoinedLiveRoom(r.room,true);startLiveTimers();await loadLiveRooms();await pollLiveRoom();note('Live Room started.','success');}catch(e){await fail(e);}finally{busy(ui.startLiveRoomBtn,false);}};
ui.liveRoomsList.onclick=async e=>{const b=e.target.closest('button[data-action]');if(!b)return;try{if(b.dataset.action==='live_room_select')await selectLiveRoom(b.dataset.roomId);if(b.dataset.action==='live_room_page'&&b.dataset.roomUrl)await msg('open_url',{url:absolute(b.dataset.roomUrl)});}catch(err){await fail(err);}};
ui.liveCloakBtn.onclick=async()=>{if(!liveRoom)return;try{await liveAction('cloak',{room:liveRoom.id,enabled:!liveRoom.cloak_mode});await pollLiveRoom();await loadLiveRooms();note(liveRoom.cloak_mode?'Cloak Mode active.':'Cloak Mode off.','success');}catch(e){await fail(e);}};
ui.leaveLiveRoomBtn.onclick=async()=>{if(!liveRoom)return;try{await liveAction('leave',{room:liveRoom.id});clearInterval(livePollTimer);clearInterval(liveHeartbeatTimer);liveRoom=null;liveCursor=0;ui.liveRoomPanel.hidden=true;await loadLiveRooms();note('Left Live Room.','success');}catch(e){await fail(e);}};
ui.endLiveRoomBtn.onclick=async()=>{if(!liveRoom||!confirm('End this Live Room?'))return;try{const r=await liveAction('end',{room:liveRoom.id});renderJoinedLiveRoom(r.room,false);clearInterval(liveHeartbeatTimer);await loadLiveRooms();note('Live Room ended.','success');}catch(e){await fail(e);}};
ui.openLiveRoomBtn.onclick=()=>{if(liveRoom&&liveRoom.url)msg('open_url',{url:absolute(liveRoom.url)});};
ui.sendLiveMessageBtn.onclick=async()=>{const body=String(ui.liveMessageInput.value||'').trim();if(!liveRoom||!body)return;busy(ui.sendLiveMessageBtn,true,'Sending…');try{const r=await liveAction('send',{room:liveRoom.id,body:body});ui.liveMessageInput.value='';appendLiveMessage(r.message);liveCursor=Math.max(liveCursor,Number(r.message&&r.message.cursor||0));}catch(e){await fail(e);}finally{busy(ui.sendLiveMessageBtn,false);}};
ui.liveMessageInput.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();ui.sendLiveMessageBtn.click();}};
ui.liveMessages.onclick=async e=>{const b=e.target.closest('button[data-action="live_report"]');if(!b)return;const reason=prompt('Why are you reporting this Live message?');if(!reason)return;try{await trustAction('report_create',{target_type:'live_message',target_id:b.dataset.messageId,reason:reason,detail:''});note('Report submitted.','success');}catch(err){await fail(err);}};
ui.thisPageFeed.onclick=feedClick;ui.followingFeed.onclick=feedClick;ui.loadMoreThisPageBtn.onclick=()=>loadThis(false).catch(fail);ui.loadMoreFollowingBtn.onclick=()=>loadFollowing(false).catch(fail);
const io=new IntersectionObserver(es=>es.forEach(e=>{if(!e.isIntersecting||e.target.hidden)return;if(e.target===ui.loadMoreThisPageBtn)loadThis(false).catch(fail);if(e.target===ui.loadMoreFollowingBtn)loadFollowing(false).catch(fail);}),{rootMargin:'120px'});io.observe(ui.loadMoreThisPageBtn);io.observe(ui.loadMoreFollowingBtn);
function pageWatch(){clearInterval(pageTimer);let u=capture&&capture.source_url||'';pageTimer=setInterval(async()=>{if(!state||!state.connected||!['this_page','live','alerts'].includes(activeView))return;try{const x=await msg('tab_identity');if(x.source_url&&x.source_url!==u){u=x.source_url;await refreshCapture(activeView==='this_page');if(activeView==='live'){liveRoom=null;liveCursor=0;ui.liveRoomPanel.hidden=true;await loadLiveRooms();}if(activeView==='alerts')await loadAlerts();}}catch(e){}},2000);}
window.onbeforeunload=()=>{clearInterval(pageTimer);clearInterval(pollTimer);clearInterval(livePollTimer);clearInterval(liveHeartbeatTimer);stream&&stream.getTracks().forEach(t=>t.stop());};
refreshState().then(pageWatch).catch(fail);
