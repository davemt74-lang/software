
const $=id=>document.getElementById(id);
const ui={};
[
'connectionState','connectControls','shareWorkspace','connectBtn','settingsBtn','connectedAccount','disconnectedAccount','accountAvatar','accountName','accountMeta','accountTeams','openVp3Btn','refreshAccountBtn','accountOptionsBtn','accessNotice','quickActionsCard','composerCard','agentWorkspaceName','agentWorkspaceStatus','agentRefreshBtn','agentConversationSelect','agentNewChatBtn','agentOpenFullBtn','agentUsePageContext','agentContextLabel','agentMessages','agentEmpty','agentMessageInput','agentSendBtn',
'nowTab','agentTab','thisPageTab','followingTab','liveTab','alertsTab','searchTab','nowView','agentView','thisPageView','followingView','liveView','alertsView','searchView','refreshNowBtn','openAgentChatBtn','restoreNowBtn','nowStatus','nowAttentionCount','nowItemCount','nowContextualCount','nowContextStrip','nowContextTitle','nowContextMeta','toggleNowContextBtn','nowContextPanel','nowRelationshipSummary','nowRelationshipList','nowContextActions','nowEmpty','nowFeed','refreshCaptureBtn','pageTitle','pageHost','sourceMeta','sourceStatus',
'followCurrentSourceBtn','openSourcePageBtn','quickAskBtn','quickSummarizeBtn','quickCompareBtn','quickResearchBtn','quickKnowledgeBtn','quickTaskBtn','quickTeamBtn','quickAnnotateBtn','selectedText','selectionCount','captureSummary','captureScreenshotBtn','screenshotPreview',
'screenshotImage','screenshotMeta','removeScreenshotBtn','captureMediaBtn','mediaDetectedText','mediaPreview','mediaPreviewTitle','mediaStart',
'mediaEnd','mediaClipHint','removeMediaBtn','recordCommentaryBtn','commentaryStatus','commentaryPreview','commentaryAudio','commentaryMeta',
'removeCommentaryBtn','visibilitySelect','visibilityTeamField','visibilityTeamSelect','destinationSelect','shareNote','shareBtn','successCard',
'shareResultText','shareMediaResult','askAgentBtn','saveKnowledgeBtn','createTaskBtn','openSourceBtn','openMessagesBtn','thisPageFeed',
'thisPageEmpty','thisPageCount','loadMoreThisPageBtn','followingFeed','followingEmpty','loadMoreFollowingBtn','refreshFollowingBtn',
'researchDialog','closeResearchDialogBtn','researchPlacements','researchProjectSelect','researchProjectNote','researchProjectTags','researchInboxBtn','researchAddBtn','newResearchProjectTitle','createResearchProjectBtn','openResearchHubBtn',
'refreshLiveBtn','liveSourceTitle','liveSourceHost','liveRoomTitle','liveScope','liveTeamField','liveTeamSelect','liveAllowCloak','liveEnterCloaked','startLiveRoomBtn','liveDirectory','liveRoomCount','liveRoomsEmpty','liveRoomsList','liveRoomPanel','joinedLiveTitle','joinedLiveMeta','openLiveRoomBtn','liveCloakBtn','leaveLiveRoomBtn','endLiveRoomBtn','liveParticipants','liveMessages','liveMessageInput','sendLiveMessageBtn',
'refreshAlertsBtn','alertsSourceTitle','alertsSourceHost','sourceChangeBadge','fileClaimBtn','notificationSettingsBtn','sourceHistoryEmpty','sourceHistoryList','sourceClaimsEmpty','sourceClaimsList','alertUnreadCount','notificationsEmpty','notificationsList',
'openSearchPageBtn','searchInput','searchSubmitBtn','searchType','searchVisibility','searchChanged','searchTeam','searchThisSource','searchContextText','saveSearchBtn','refreshDiscoveryBtn','searchResultHeading','searchResultCount','searchResultsEmpty','searchResultsList','savedSearchesEmpty','savedSearchesList','recentSearchesEmpty','recentSearchesList','clearRecentSearchesBtn','claimDialog','closeClaimDialogBtn','claimStatement','claimRationale','claimVisibility','claimTeamField','claimTeamSelect','claimSubmitBtn','notice'
].forEach(id=>ui[id]=$(id));

const CLIP_MAX_SECONDS = 90;
const COMMENTARY_MAX_BYTES = 16 * 1024 * 1024;
const MAX_CLIP=CLIP_MAX_SECONDS,MAX_COMMENTARY=COMMENTARY_MAX_BYTES;
let state=null,capture=null,destinations=null,lastShare=null,currentSource=null;
let screenshotCapture=null,mediaReference=null,commentaryCapture=null,recorder=null,stream=null,recordTimer=null,recordStarted=0,pageTimer=null;
let thisCursor='',followingCursor='',thisBusy=false,followingBusy=false,activeView='now';
let cognitiveBusy=false,cognitiveData=null,cognitiveTimer=null;
let agentConversationId=0,agentLastMessageId=0,agentPollTimer=null,agentBusy=false,agentConversations=[];
let nowContextIgnored=false,contextAgentPayload=null,contextRelationships=null,contextSuggestions=[];
let researchShareId='',researchContextData=null;
let liveRoomsData=[],liveRoom=null,liveCursor=0,livePollTimer=null,liveHeartbeatTimer=null,liveBusy=false;
let trustBusy=false,trustObservation=null,trustNotifications=null,trustClaims=[],claimShareId='';
let searchBusy=false,searchData=null,searchSavedData=[],searchRecentData=[];

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
function dropCapability(cap){if(!state||!cap)return;state.capabilities=(state.capabilities||[]).filter(x=>x!==cap);renderConnection(state);}
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
  const canRead=c.has('team.chat.read'),canShare=c.has('team.share.create');
  ui.nowTab.disabled=!(state&&state.connected&&c.has('agent.message'));
  ui.agentTab.disabled=!(state&&state.connected&&c.has('agent.message'));
  ui.agentSendBtn.disabled=!(state&&state.connected&&c.has('agent.message')&&!agentBusy&&String(ui.agentMessageInput.value||'').trim());
  ui.thisPageTab.disabled=!(state&&state.connected&&(canRead||canShare));
  [ui.followingTab,ui.liveTab,ui.alertsTab,ui.searchTab].forEach(tab=>{tab.disabled=!(state&&state.connected&&canRead);});
  if(ui.quickActionsCard)ui.quickActionsCard.hidden=!(state&&state.connected&&capture&&capture.available);
  if(ui.composerCard)ui.composerCard.hidden=!(state&&state.connected&&canShare);
  const quickPageOk=!!(state&&state.connected&&capture&&capture.available);
  ui.quickAskBtn.disabled=!quickPageOk||!c.has('agent.message');
  ui.quickSummarizeBtn.disabled=!quickPageOk||!c.has('agent.message');
  ui.quickCompareBtn.disabled=!quickPageOk||!c.has('agent.message');
  ui.quickResearchBtn.disabled=!quickPageOk||!c.has('agent.message')||!c.has('knowledge.write');
  ui.quickKnowledgeBtn.disabled=!quickPageOk||!c.has('agent.message')||!c.has('knowledge.write');
  ui.quickTaskBtn.disabled=!quickPageOk||!c.has('agent.message')||!c.has('task.propose');
  ui.quickTeamBtn.disabled=!quickPageOk||!c.has('team.share.create');
  ui.quickAnnotateBtn.disabled=!quickPageOk||!c.has('team.share.create');
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
  ui.searchContextText.textContent=capture&&capture.available?'Context: '+(host(capture.source_url)||'current page'):'No current page context.';
  if(ui.agentContextLabel)ui.agentContextLabel.textContent=capture&&capture.available
    ?'Temporary context: '+(capture.title||host(capture.source_url)||'current page')
    :'No current page context.';
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
  ui.visibilityTeamSelect.replaceChildren(new Option('Choose team…',''));ui.liveTeamSelect.replaceChildren(new Option('Choose team…',''));ui.claimTeamSelect.replaceChildren(new Option('Choose team…',''));ui.searchTeam.replaceChildren(new Option('Any Team',''));
  (destinations.teams||[]).forEach(r=>{if(r.kind==='team_general'&&Number(r.id)){const label=String(r.name||'Team').replace(/ · General$/,'');ui.visibilityTeamSelect.append(new Option(label,String(Number(r.id))));ui.liveTeamSelect.append(new Option(label,String(Number(r.id))));ui.claimTeamSelect.append(new Option(label,String(Number(r.id))));ui.searchTeam.append(new Option(label,String(Number(r.id))));}});renderAccountTeams();renderCaps();
}
function initials(name){
  const parts=String(name||'VP').trim().split(/\s+/).filter(Boolean);
  if(!parts.length)return 'VP';
  return (parts.length===1?parts[0].slice(0,2):parts[0][0]+parts[parts.length-1][0]).toUpperCase();
}
function roleLabel(role){
  const value=String(role||'').trim();
  if(!value)return 'VP3 account';
  return value.replace(/[_-]+/g,' ').replace(/\b\w/g,m=>m.toUpperCase());
}
function hasReadAccess(){
  const c=caps();
  return c.has('team.chat.read')||c.has('team.share.create')||c.has('agent.message');
}
function renderAccountTeams(){
  if(!state||!state.connected){ui.accountTeams.textContent='';return;}
  const teams=destinations&&Array.isArray(destinations.teams)?destinations.teams:[];
  const names=[...new Set(teams.filter(r=>r&&r.kind==='team_general').map(r=>String(r.name||'Team').replace(/ · General$/,'')).filter(Boolean))];
  ui.accountTeams.textContent=names.length?(names.length===1?'Team: '+names[0]:'Teams: '+names.slice(0,3).join(', ')+(names.length>3?' +'+(names.length-3):'')):'Personal VP3 access';
}
function renderConnection(x){
  state=x;ui.connectControls.hidden=ui.shareWorkspace.hidden=true;
  const connected=Boolean(x&&x.connected);
  ui.connectedAccount.hidden=!connected;
  ui.disconnectedAccount.hidden=connected;
  ui.accessNotice.hidden=true;
  if(connected){
    const name=(x.user&&x.user.display_name)||'VP3 user';
    ui.accountAvatar.textContent=initials(name);
    ui.accountName.textContent=name;
    ui.accountMeta.textContent=roleLabel(x.user&&x.user.role);
    ui.connectionState.textContent='Connected as '+name;
    const readable=hasReadAccess();
    ui.shareWorkspace.hidden=!readable;
    ui.accessNotice.hidden=readable;
    renderAccountTeams();
  }else{
    ui.connectionState.textContent='Not connected';
    ui.connectControls.hidden=false;
    ui.accountTeams.textContent='';
  }
  renderCaps();
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
  ui.notificationsList.replaceChildren();items.forEach(n=>{const box=alertItem(n.title||'Notification',n.body||'',date(n.created_at),!n.read),actions=el('div','alert-item-actions','');const open=act(n.read?'Open':'Open · mark read','notification_open');open.dataset.notificationId=String(n.id||'');open.dataset.url=String(n.target_url||'');actions.append(open);const dismiss=act('Dismiss','notification_dismiss');dismiss.dataset.notificationId=String(n.id||'');actions.append(dismiss);box.append(actions);ui.notificationsList.append(box);});ui.notificationsEmpty.hidden=items.length>0;const unread=Number(wrap.unread||0);ui.alertUnreadCount.textContent=unread?unread+' unread':'';ui.alertUnreadCount.hidden=!unread;
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


function searchParams(extra){
  const p={q:String(ui.searchInput.value||'').trim(),type:ui.searchType.value||'',visibility:ui.searchVisibility.value||'',changed:ui.searchChanged.value||'',team_id:ui.searchTeam.value||'',context_source_id:currentSource&&currentSource.id||'',context_domain:host(capture&&capture.source_url||''),context_only:ui.searchThisSource.checked?'1':''};
  return Object.assign(p,extra||{});
}
function searchResultCard(item){
  const box=el('article','search-result',''),head=el('div','search-result-head',''),left=el('div','','');
  left.append(el('div','search-result-type',String(item.type||'result').replace(/_/g,' ')),el('div','search-result-title',item.title||'VP3 result'));
  head.append(left,el('div','search-result-score','Ranked'));box.append(head);
  if(item.snippet)box.append(el('div','search-result-snippet',item.snippet));
  const meta=el('div','search-result-meta','');if(item.domain)meta.append(el('span','pill',item.domain));if(item.visibility)meta.append(el('span','pill',item.visibility));if(item.status)meta.append(el('span','pill',String(item.status).replace(/_/g,' ')));if(item.source_changed)meta.append(el('span','pill changed','Source changed'));if(meta.children.length)box.append(meta);
  const actions=el('div','search-result-actions',''),open=act('Open','search_open');open.dataset.url=String(item.url||'');actions.append(open);
  if(item.type==='annotation'){const save=act('Save','search_save');save.dataset.id=item.id||'';const research=act('Research','search_research');research.dataset.id=item.id||'';const claim=act('File claim','search_claim');claim.dataset.sourceId=item.source_id||'';claim.dataset.annotationId=item.id||'';actions.append(save,research,claim);}
  if(item.type==='source'){const follow=act('Follow','search_follow');follow.dataset.sourceId=item.id||'';const claim=act('File claim','search_claim');claim.dataset.sourceId=item.id||'';actions.append(follow,claim);}
  if(item.type==='live'&&item.actions&&item.actions.join_live){const join=act('Join','search_join_live');join.dataset.roomId=item.id||'';join.dataset.url=String(item.url||'');actions.append(join);}
  box.append(actions);return box;
}
function renderSearchItems(items,heading){
  const rows=Array.isArray(items)?items:[];ui.searchResultsList.replaceChildren();rows.forEach(i=>ui.searchResultsList.append(searchResultCard(i)));ui.searchResultsEmpty.hidden=rows.length>0;ui.searchResultCount.textContent=rows.length?String(rows.length):'';ui.searchResultHeading.textContent=heading||'Results';
}
function renderSearchHistory(){
  ui.savedSearchesList.replaceChildren();searchSavedData.forEach(s=>{const row=el('div','search-history-item',''),copy=el('div','search-history-copy','');copy.dataset.action='saved_run';copy.dataset.id=s.id||'';copy.append(el('strong','',s.name||'Saved search'),el('span','',s.query||''));const del=act('Remove','saved_delete');del.dataset.id=s.id||'';row.append(copy,del);ui.savedSearchesList.append(row);});ui.savedSearchesEmpty.hidden=searchSavedData.length>0;
  ui.recentSearchesList.replaceChildren();searchRecentData.forEach((s,i)=>{const row=el('div','search-history-item',''),copy=el('div','search-history-copy','');copy.dataset.action='recent_run';copy.dataset.index=String(i);copy.append(el('strong','',s.query||''),el('span','',(s.result_count||0)+' results · '+date(s.last_used_at)));row.append(copy);ui.recentSearchesList.append(row);});ui.recentSearchesEmpty.hidden=searchRecentData.length>0;ui.clearRecentSearchesBtn.hidden=!searchRecentData.length;
}
async function loadSearchHistory(){
  const [saved,recent]=await Promise.all([msg('search_saved'),msg('search_recent')]);searchSavedData=Array.isArray(saved&&saved.saved)?saved.saved:[];searchRecentData=Array.isArray(recent&&recent.recent)?recent.recent:[];renderSearchHistory();
}
async function runSearch(){
  if(searchBusy||!state||!state.connected)return;searchBusy=true;busy(ui.searchSubmitBtn,true,'Searching…');
  try{const p=await msg('search_query',{params:searchParams({limit:50})});searchData=p&&p.search||null;renderSearchItems(searchData&&searchData.items||[],'Search results');await loadSearchHistory();}finally{searchBusy=false;busy(ui.searchSubmitBtn,false);}
}
async function loadDiscovery(){
  if(searchBusy||!state||!state.connected)return;searchBusy=true;
  try{const p=await msg('search_discover',{params:searchParams()});const d=p&&p.discovery||{},items=[];(d.context||[]).forEach(x=>items.push(x));(d.trending||[]).forEach(x=>{if(!items.some(y=>y.type===x.type&&y.id===x.id))items.push(x);});(d.active||[]).forEach(x=>{if(!items.some(y=>y.type===x.type&&y.id===x.id))items.push(x);});renderSearchItems(items.slice(0,50),currentSource&&currentSource.id?'Related & active':'Discovery');await loadSearchHistory();}finally{searchBusy=false;}
}

function cognitiveTypeLabel(v){return String(v||'item').replace(/[_-]+/g,' ').replace(/\b\w/g,m=>m.toUpperCase());}
function cognitiveAction(action,payload){return msg('cognitive_action',{action:action,payload:payload||{}});}
function cognitiveCard(section,item){
  const cardData=item&&item.card||{},box=el('article','now-card','');box.dataset.attention=item&&item.attention?'1':'0';box.dataset.itemKey=String(item&&item.key||'');box.dataset.fingerprint=String(item&&item.fingerprint||'');box._item=item;
  const head=el('div','now-card-head',''),copy=el('div','','');copy.append(el('div','now-card-type',cognitiveTypeLabel(cardData.card_type||item.source||'Agent item')),el('div','now-card-title',cardData.title||'VP3 item'));head.append(copy);
  if(cardData.status)head.append(el('span','now-card-status',cognitiveTypeLabel(cardData.status)));box.append(head);
  if(item.context_score>0){const contextual=el('div','now-badges','');contextual.append(el('span','pill contextual','Current page'));box.append(contextual);}
  if(item.reason)box.append(el('div','now-reason',item.reason));
  if(cardData.summary)box.append(el('p','now-summary-text',cardData.summary));
  if(Array.isArray(cardData.badges)&&cardData.badges.length){const row=el('div','now-badges','');cardData.badges.forEach(v=>row.append(el('span','pill',v)));box.append(row);}
  if(Array.isArray(cardData.facts)&&cardData.facts.length){const grid=el('div','now-facts','');cardData.facts.forEach(f=>{const x=el('div','now-fact','');x.append(el('small','',f.label||''),el('strong','',f.value||''));grid.append(x);});box.append(grid);}
  if(Array.isArray(cardData.sections)&&cardData.sections.length&&cardData.display_mode!=='compact'){const wrap=el('div','now-card-sections','');cardData.sections.forEach(part=>{const x=el('section','now-card-section','');if(part.label)x.append(el('small','',part.label));if(part.text)x.append(el('p','',part.text));if(Array.isArray(part.items)&&part.items.length){const ul=document.createElement('ul');part.items.forEach(v=>ul.append(el('li','',v)));x.append(ul);}if(x.children.length)wrap.append(x);});if(wrap.children.length)box.append(wrap);}
  const actions=el('div','now-card-actions','');
  if(cardData.card_type==='proactive_plan'&&item.plan_status==='proposed'){const a=act('Accept plan','cognitive_plan_accept');a.classList.add('primary-inline');actions.append(a,act('Dismiss','cognitive_plan_dismiss'));}
  else if(cardData.card_type==='proactive_plan'&&item.plan_status==='accepted'){const a=act('Accepted for review','cognitive_noop');a.disabled=true;actions.append(a);}
  (cardData.actions||[]).forEach(a=>{if(a.type==='open_url'){const b=act(a.label||'Open','cognitive_open');b.dataset.url=String(a.url||'');actions.append(b);}else if(a.type==='agent_review'){const b=act(a.label||'Review in Agent','cognitive_agent_review');if(a.requires_approval)b.dataset.requiresApproval='1';actions.append(b);}});
  actions.append(act('Why?','cognitive_explain'),act('Hide','cognitive_hide'));box.append(actions);
  const explanation=el('div','now-explanation','');explanation.hidden=true;box.append(explanation);
  if(cardData.card_type==='proactive_plan')box.append(el('div','now-plan-note','Plan actions remain proposal/review only. Tool execution still requires the normal VP3 approval path.'));
  if(cardData.timestamp)box.append(el('time','now-card-time',date(cardData.timestamp)||cardData.timestamp));
  return box;
}
function renderNow(feed){
  cognitiveData=feed&&typeof feed==='object'?feed:null;ui.nowFeed.replaceChildren();
  let attention=0,total=0;
  (cognitiveData&&Array.isArray(cognitiveData.sections)?cognitiveData.sections:[]).forEach(section=>{const items=Array.isArray(section.items)?section.items:[];if(!items.length)return;total+=items.length;if(section.id==='attention')attention+=items.length;const wrap=el('section','now-section',''),head=el('div','now-section-head',''),copy=el('div','now-section-copy','');copy.append(el('strong','',section.label||'Updates'));if(section.description)copy.append(el('span','',section.description));head.append(copy,el('span','now-section-count',String(items.length)));wrap.append(head);const list=el('div','now-feed','');items.forEach(item=>list.append(cognitiveCard(section,item)));wrap.append(list);ui.nowFeed.append(wrap);});
  ui.nowAttentionCount.textContent=attention+' attention';ui.nowItemCount.textContent=total+' current';ui.nowEmpty.hidden=total>0;
  const contextual=Math.max(0,Number(cognitiveData&&cognitiveData.contextual_item_count||0));ui.nowContextualCount.hidden=contextual<1;ui.nowContextualCount.textContent=contextual+' page-related';
  const hidden=Number(cognitiveData&&cognitiveData.hidden_count||0);ui.restoreNowBtn.hidden=hidden<1;ui.restoreNowBtn.textContent=hidden>0?'Show hidden ('+hidden+')':'Show hidden';
  ui.nowStatus.textContent=total?(attention?attention+' item'+(attention===1?'':'s')+' need attention · '+(date(cognitiveData.generated_at)||'updated now'):'Current · '+(date(cognitiveData.generated_at)||'updated now')):(hidden?'All current items are hidden.':'Nothing needs the Agent canvas right now.');
}
function clearNowContextUi(){
  contextAgentPayload=null;contextRelationships=null;contextSuggestions=[];
  ui.nowContextStrip.hidden=true;ui.nowContextPanel.hidden=true;ui.nowRelationshipSummary.replaceChildren();ui.nowRelationshipList.replaceChildren();ui.nowContextActions.replaceChildren();ui.nowContextualCount.hidden=true;
  ui.openAgentChatBtn.textContent='Open Agent Chat';
}
function relationshipRows(rel){
  const rows=[];for(const key of ['calendar','research','knowledge','team_conversations','profiles','contacts']){for(const row of (Array.isArray(rel&&rel[key])?rel[key]:[]).slice(0,3)){if(row&&row.title)rows.push({...row,_group:key});}}
  return rows.slice(0,8);
}
function renderContextualNow(payload){
  const feed=payload&&payload.feed||null,context=feed&&feed.context||null,rel=payload&&payload.relationships||{};
  contextAgentPayload=payload&&payload.agent_payload||null;contextRelationships=rel;contextSuggestions=Array.isArray(payload&&payload.suggestions)?payload.suggestions:[];
  if(!context||context.ignored){clearNowContextUi();return;}
  ui.nowContextStrip.hidden=false;ui.nowContextPanel.hidden=false;ui.nowContextTitle.textContent=context.title||context.domain||'Current page';
  ui.nowContextMeta.textContent=(context.domain||host(context.url)||'')+(context.selected?' · highlighted text attached':'')+' · temporary';
  ui.toggleNowContextBtn.textContent='Ignore page';ui.openAgentChatBtn.textContent='Ask Agent about this page';
  const summary=ui.nowRelationshipSummary;summary.replaceChildren();
  const counts=[
    ['Annotations',Number(rel.annotation_count||0)],
    ['Team',Array.isArray(rel.team_conversations)?rel.team_conversations.length:0],
    ['Research',Array.isArray(rel.research)?rel.research.length:0],
    ['Knowledge',Array.isArray(rel.knowledge)?rel.knowledge.length:0],
    ['Meetings',Array.isArray(rel.calendar)?rel.calendar.length:0],
    ['Profiles',Array.isArray(rel.profiles)?rel.profiles.length:0],
    ['Contacts',Array.isArray(rel.contacts)?rel.contacts.length:0]
  ].filter(x=>x[1]>0);
  if(counts.length)counts.forEach(([label,count])=>summary.append(el('span','pill',count+' '+label.toLowerCase())));
  else summary.append(el('span','muted','No existing VP3 relationships found yet.'));
  ui.nowRelationshipList.replaceChildren();
  (Array.isArray(rel.insights)?rel.insights:[]).slice(0,4).forEach(text=>ui.nowRelationshipList.append(el('div','now-context-insight',text)));
  relationshipRows(rel).forEach(row=>{const box=el('div','now-relationship-row',''),copy=el('div','','');copy.append(el('small','',String(row.type||row._group||'related').replace(/_/g,' ')),el('strong','',row.title||'Related item'));if(row.detail)copy.append(el('span','',row.detail));box.append(copy);if(row.url){const b=act('Open','context_open_relation');b.dataset.url=String(row.url);box.append(b);}ui.nowRelationshipList.append(box);});
  ui.nowContextActions.replaceChildren();
  contextSuggestions.forEach((suggestion,index)=>{const b=act(suggestion.label||'Review','context_suggestion');b.dataset.index=String(index);if(index===0)b.classList.add('primary-inline');ui.nowContextActions.append(b);});
  const src=rel&&rel.source;if(src&&src.id)currentSource={...(currentSource||{}),...src};
}
async function openContextAgent(prompt){
  if(!contextAgentPayload)return msg('open_url',{url:absolute('/chat.php')});
  const handoff=await msg('context_handoff',{payload:contextAgentPayload,prompt:String(prompt||'')});
  if(handoff&&handoff.url)await msg('open_url',{url:handoff.url});
}
async function contextSuggestionClick(e){
  const open=e.target.closest('button[data-action="context_open_relation"]');
  if(open&&open.dataset.url){await msg('open_url',{url:absolute(open.dataset.url)});return;}
  const button=e.target.closest('button[data-action="context_suggestion"]');if(!button)return;
  const suggestion=contextSuggestions[Number(button.dataset.index||-1)];if(!suggestion)return;
  try{
    if(suggestion.kind==='agent_prompt'){await openContextAgent(suggestion.prompt||'');return;}
    if(suggestion.kind==='open'&&suggestion.url){await msg('open_url',{url:absolute(suggestion.url)});return;}
    if(suggestion.kind==='manual_flow'){setView('this_page');note('Use the explicit page controls to choose what to share or save.','success');return;}
    if(suggestion.kind==='manual_follow'){
      if(!caps().has('team.chat.read'))return note('Following sources is not enabled for this account.','error');
      const src=contextRelationships&&contextRelationships.source;if(!src||!src.id)return;
      const follow=!src.following;await sourceAction('follow_source',{source_id:src.id,url:capture&&capture.source_url||'',canonical_url:capture&&capture.canonical_url||'',title:capture&&capture.title||'',follow});
      note(follow?'Source followed.':'Source unfollowed.','success');await loadNow(true);return;
    }
  }catch(err){await fail(err);}
}
function scheduleNow(seconds){
  clearInterval(cognitiveTimer);cognitiveTimer=null;
  if(activeView!=='now')return;
  cognitiveTimer=setInterval(()=>{if(state&&state.connected&&caps().has('agent.message'))loadNow(false).catch(()=>{});},Math.max(60,Number(seconds||60))*1000);
}
async function loadNow(force){
  if(cognitiveBusy||!state||!state.connected||!caps().has('agent.message'))return;cognitiveBusy=true;
  if(force)ui.nowStatus.textContent='Refreshing current VP3 intelligence…';
  try{
    if(capture&&capture.available&&!nowContextIgnored){
      const payload=await msg('context_now',{capture:capture});renderNow(payload&&payload.feed||null);renderContextualNow(payload);scheduleNow(payload&&payload.feed&&payload.feed.refresh_seconds||60);
    }else{
      const feed=await msg('cognitive_now');renderNow(feed);clearNowContextUi();
      if(capture&&capture.available&&nowContextIgnored){ui.nowContextStrip.hidden=false;ui.nowContextTitle.textContent=capture.title||host(capture.source_url)||'Current page';ui.nowContextMeta.textContent=(host(capture.source_url)||'')+' · page context ignored';ui.toggleNowContextBtn.textContent='Use page';}
      scheduleNow(feed&&feed.refresh_seconds||60);
    }
  }
  catch(e){
    ui.nowStatus.textContent=e.message||'Agent Now is unavailable.';
    if(e.code==='capability_denied'){
      dropCapability('agent.message');
      if(caps().has('team.chat.read')||caps().has('team.share.create'))setView('this_page');
      else renderNow(null);
    }
    throw e;
  }
  finally{cognitiveBusy=false;}
}
async function cognitiveClick(e){
  const b=e.target.closest('button[data-action]');if(!b||b.dataset.action==='cognitive_noop')return;const box=b.closest('.now-card'),item=box&&box._item;if(!item)return;const payload={item_key:String(item.key||''),fingerprint:String(item.fingerprint||'')};
  try{
    if(b.dataset.action==='cognitive_hide'){await cognitiveAction('hide',payload);await loadNow(true);return;}
    if(b.dataset.action==='cognitive_explain'){const r=await cognitiveAction('explain',payload),x=box.querySelector('.now-explanation');x.textContent=String(r.explanation&&r.explanation.explanation||'This item is ranked from current VP3 state and authorized cognitive context.');x.hidden=!x.hidden;return;}
    if(b.dataset.action==='cognitive_open'){await cognitiveAction('feedback',{...payload,event:'engaged',action_type:'open_object'});if(b.dataset.url)await msg('open_url',{url:absolute(b.dataset.url)});return;}
    if(b.dataset.action==='cognitive_agent_review'){await cognitiveAction('feedback',{...payload,event:'engaged',action_type:'agent_review'});await msg('open_url',{url:absolute(cognitiveData&&cognitiveData.agent_url||'/chat.php')});return;}
    if(b.dataset.action==='cognitive_plan_accept'||b.dataset.action==='cognitive_plan_dismiss'){const planId=String(item.key||'').replace(/^plan:/,'');const decision=b.dataset.action==='cognitive_plan_accept'?'accept':'dismiss';await cognitiveAction('plan_decide',{plan_id:planId,decision:decision});await loadNow(true);note(decision==='accept'?'Plan accepted for review.':'Plan dismissed.','success');return;}
  }catch(err){await fail(err);if(err.code==='state_changed')await loadNow(true).catch(()=>{});}
}
function agentWorkspaceRequestV2160(action,payload){return msg('agent_workspace',{action:action,payload:payload||{}});}
function agentMessageNodeV2160(message){
  const role=message&&message.role==='user'?'user':'assistant';
  const box=el('div','agent-message '+role,message&&message.message||'');
  const meta=el('span','agent-message-meta',role==='user'?'You':'VP3 Agent');
  if(message&&message.created_at){const when=date(message.created_at);if(when)meta.textContent+=' · '+when;}
  box.append(meta);box.dataset.messageId=String(Number(message&&message.id||0));return box;
}
function renderAgentMessagesV2160(messages,append){
  if(!append){ui.agentMessages.replaceChildren();agentLastMessageId=0;}
  for(const message of Array.isArray(messages)?messages:[]){
    const id=Number(message&&message.id||0);if(id&&ui.agentMessages.querySelector('[data-message-id="'+id+'"]'))continue;
    ui.agentMessages.append(agentMessageNodeV2160(message));agentLastMessageId=Math.max(agentLastMessageId,id);
  }
  ui.agentEmpty.hidden=ui.agentMessages.children.length>0;
  if(!ui.agentEmpty.parentNode)ui.agentMessages.prepend(ui.agentEmpty);
  ui.agentMessages.scrollTop=ui.agentMessages.scrollHeight;
}
function renderAgentConversationsV2160(payload){
  agentConversations=Array.isArray(payload&&payload.conversations)?payload.conversations:[];
  if(payload&&payload.agent&&payload.agent.name)ui.agentWorkspaceName.textContent=payload.agent.name;
  const current=agentConversationId;
  ui.agentConversationSelect.replaceChildren(new Option('New chat',''));
  agentConversations.forEach(row=>ui.agentConversationSelect.append(new Option(row.title||'Chat',String(Number(row.id||0)))));
  ui.agentConversationSelect.value=current?String(current):'';
}
async function loadAgentConversationsV2160(){
  const payload=await agentWorkspaceRequestV2160('list',{});
  renderAgentConversationsV2160(payload);return payload;
}
async function loadAgentConversationV2160(id){
  id=Math.max(0,Number(id||0));agentConversationId=id;
  if(!id){renderAgentMessagesV2160([],false);ui.agentConversationSelect.value='';return;}
  const payload=await agentWorkspaceRequestV2160('load',{conversation_id:id});
  if(payload&&payload.agent&&payload.agent.name)ui.agentWorkspaceName.textContent=payload.agent.name;
  renderAgentMessagesV2160(payload&&payload.messages||[],false);ui.agentConversationSelect.value=String(id);
}
async function pollAgentMessagesV2160(){
  if(activeView!=='agent'||!agentConversationId||agentBusy)return;
  try{
    const payload=await agentWorkspaceRequestV2160('messages_after',{conversation_id:agentConversationId,after_id:agentLastMessageId});
    renderAgentMessagesV2160(payload&&payload.messages||[],true);
  }catch(e){if(e.code==='capability_denied')dropCapability('agent.message');}
}
function scheduleAgentPollV2160(){
  clearInterval(agentPollTimer);agentPollTimer=null;
  if(activeView==='agent')agentPollTimer=setInterval(()=>pollAgentMessagesV2160(),4000);
}
async function refreshAgentWorkspaceV2160(){
  if(!state||!state.connected||!caps().has('agent.message'))return;
  const list=await loadAgentConversationsV2160();
  if(agentConversationId){
    if(agentConversations.some(row=>Number(row.id)===agentConversationId))await loadAgentConversationV2160(agentConversationId);
    else await loadAgentConversationV2160(0);
  }
  ui.agentWorkspaceStatus.textContent=(list&&list.agent&&list.agent.name?list.agent.name:'VP3 Agent')+' · canonical VP3 Chat history';
  scheduleAgentPollV2160();
}
async function sendAgentMessageV2160(){
  const message=String(ui.agentMessageInput.value||'').trim();if(!message||agentBusy)return;
  agentBusy=true;renderCaps();
  const optimistic=agentMessageNodeV2160({id:0,role:'user',message:message,created_at:new Date().toISOString()});
  optimistic.dataset.pending='1';ui.agentMessages.append(optimistic);ui.agentEmpty.hidden=true;ui.agentMessages.scrollTop=ui.agentMessages.scrollHeight;
  ui.agentMessageInput.value='';
  try{
    const payload=await agentWorkspaceRequestV2160('send',{
      conversation_id:agentConversationId,
      message:message,
      use_context:Boolean(ui.agentUsePageContext.checked&&capture&&capture.available),
      capture:capture&&capture.available?capture:null
    });
    optimistic.remove();
    agentConversationId=Number(payload&&payload.conversation_id||0);
    const now=new Date().toISOString();
    renderAgentMessagesV2160([
      {id:Number(payload&&payload.user_message_id||0),role:'user',message:message,created_at:now},
      {id:Number(payload&&payload.assistant_message_id||0),role:'assistant',message:payload&&payload.answer||'',created_at:now}
    ],true);
    await loadAgentConversationsV2160();
    ui.agentConversationSelect.value=String(agentConversationId);
  }catch(e){
    optimistic.remove();ui.agentMessageInput.value=message;await fail(e);
  }finally{agentBusy=false;renderCaps();}
}
function setView(v){
  activeView=v;const now=v==='now',agent=v==='agent',page=v==='this_page',following=v==='following',live=v==='live',alerts=v==='alerts',search=v==='search';
  ui.nowView.hidden=!now;ui.agentView.hidden=!agent;ui.thisPageView.hidden=!page;ui.followingView.hidden=!following;ui.liveView.hidden=!live;ui.alertsView.hidden=!alerts;ui.searchView.hidden=!search;
  ui.nowTab.classList.toggle('active',now);ui.agentTab.classList.toggle('active',agent);ui.thisPageTab.classList.toggle('active',page);ui.followingTab.classList.toggle('active',following);ui.liveTab.classList.toggle('active',live);ui.alertsTab.classList.toggle('active',alerts);ui.searchTab.classList.toggle('active',search);
  ui.nowTab.setAttribute('aria-selected',String(now));ui.agentTab.setAttribute('aria-selected',String(agent));ui.thisPageTab.setAttribute('aria-selected',String(page));ui.followingTab.setAttribute('aria-selected',String(following));ui.liveTab.setAttribute('aria-selected',String(live));ui.alertsTab.setAttribute('aria-selected',String(alerts));ui.searchTab.setAttribute('aria-selected',String(search));
  if(!now){clearInterval(cognitiveTimer);cognitiveTimer=null;}if(now)loadNow(true).catch(fail);
  if(!agent){clearInterval(agentPollTimer);agentPollTimer=null;}if(agent)refreshAgentWorkspaceV2160().catch(fail);
  if(page&&caps().has('team.chat.read'))loadThis(true).catch(fail);if(following)loadFollowing(true).catch(fail);if(live)loadLiveRooms().then(pollLiveRoom).catch(fail);if(alerts)loadAlerts().catch(fail);if(search)loadDiscovery().catch(fail);
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
  const actions=el('div','feed-actions','');actions.append(act(item.interactions&&item.interactions.saved?'Saved':'Save','save',item.interactions&&item.interactions.saved),act(item.interactions&&item.interactions.in_research?'Research Inbox':'Research','research',item.interactions&&item.interactions.in_research),act('Knowledge','knowledge'),act('Ask VP3','ask'),act('Share with Team','share_team'),act('Live','live'),act(item.source_identity&&item.source_identity.following?'Following source':'Follow source','follow_source',item.source_identity&&item.source_identity.following),act('File claim','claim'),act('Report','report'),act('Open context','context'));if(item.interactions&&item.interactions.unread)actions.append(act('Mark read','read'));c.append(actions);
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
async function runSidebarQuickActionV2150(action,button){
  if(!capture||!capture.available)return note('Open a normal web page first.','error');
  if(action==='annotate'||action==='share_team'){
    setView('this_page');
    if(action==='share_team'){
      note('Choose a Team or conversation under Deliver to, then publish this annotation.','success');
      window.setTimeout(()=>ui.destinationSelect.focus(),0);
    }else{
      note('Add your note or capture, then publish when ready.','success');
      window.setTimeout(()=>ui.shareNote.focus(),0);
    }
    return;
  }
  busy(button,true,'Opening…');
  try{
    const result=await msg('quick_action_run',{action:action,capture:capture});
    if(result&&result.mode==='agent')note('Opened with temporary page context in Agent Chat.','success');
    else if(result&&result.mode==='open')note('Opened related VP3 context.','success');
  }catch(e){
    if(e.code==='capability_denied')dropCapability(
      action==='add_research'||action==='save_knowledge'?'knowledge.write':
      action==='create_task'?'task.propose':'agent.message'
    );
    await fail(e);
  }finally{busy(button,false);}
}

async function applyPendingQuickActionV2150(pending){
  if(!pending||!pending.capture||!pending.capture.source_url)return;
  const action=String(pending.action||'annotate');
  renderCapture({...pending.capture,available:true});
  setView('this_page');
  if(action==='share_team'){
    note('Choose a Team or conversation under Deliver to, then publish this annotation.','success');
    window.setTimeout(()=>ui.destinationSelect.focus(),0);
    return;
  }
  note('Selection loaded into the annotation composer. Nothing is saved until you publish.','success');
  window.setTimeout(()=>ui.shareNote.focus(),0);
}

async function refreshState(){
  const x=await msg('state');renderConnection(x);
  const pendingQuick=await msg('quick_action_consume').catch(()=>null);
  if(x.connected){
    renderLast(x.last_share);
    if(pendingQuick&&pendingQuick.capture&&pendingQuick.capture.source_url){
      renderCapture({...pendingQuick.capture,available:true});
    }else if(x.pending_capture&&x.pending_capture.available&&x.pending_capture.selected_text){
      renderCapture(x.pending_capture);await message('clear_pending_capture').catch(()=>{});
    }else await refreshCapture(false);
    const c=caps();
    if(activeView==='now'&&!c.has('agent.message')&&(c.has('team.chat.read')||c.has('team.share.create')))setView('this_page');
    if(c.has('team.destinations.read'))await loadDestinations();else{destinations={recent:[],teams:[],conversations:[]};renderAccountTeams();}
    if(c.has('team.chat.read')&&activeView==='this_page')await loadThis(true);
    if(pendingQuick)await applyPendingQuickActionV2150(pendingQuick);
    else if(c.has('agent.message')&&activeView==='now')await loadNow(true);
    else if(c.has('agent.message')&&activeView==='agent')await refreshAgentWorkspaceV2160();
  }
}
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
    if(a==='claim'){claimShareId=id;ui.claimStatement.value='';ui.claimRationale.value='';ui.claimVisibility.value='public';ui.claimTeamField.hidden=true;ui.claimDialog.showModal();renderCaps();return;}
    if(a==='report'){const reason=prompt('Why are you reporting this annotation?');if(!reason)return;await trustAction('report_create',{target_type:'annotation',target_id:id,reason:reason,detail:''});return note('Report submitted.','success');}
    if(a==='context'&&i.annotation_url)await msg('open_url',{url:absolute(i.annotation_url)});
  }catch(err){fail(err);}
}
function reload(){return activeView==='now'?loadNow(true):activeView==='following'?loadFollowing(true):activeView==='live'?loadLiveRooms():activeView==='alerts'?loadAlerts():activeView==='search'?loadDiscovery():loadThis(true);}

ui.connectBtn.onclick=async()=>{busy(ui.connectBtn,true,'Connecting…');try{await msg('connect',{device_name:'Chrome Browser'});note('Browser connected to VP3.','success');await refreshState();}catch(e){fail(e);}finally{busy(ui.connectBtn,false);}};
ui.settingsBtn.onclick=()=>chrome.runtime.openOptionsPage();
ui.accountOptionsBtn.onclick=()=>chrome.runtime.openOptionsPage();
ui.openVp3Btn.onclick=()=>msg('open_url',{url:absolute('/')}).catch(fail);
ui.refreshAccountBtn.onclick=async()=>{busy(ui.refreshAccountBtn,true,'Refreshing…');try{await refreshState();note('VP3 account refreshed.','success');}catch(e){await fail(e);}finally{busy(ui.refreshAccountBtn,false);}};
ui.refreshNowBtn.onclick=()=>loadNow(true).catch(fail);ui.openAgentChatBtn.onclick=()=>{if(contextAgentPayload)return openContextAgent('Review this page with me. Start with what is most relevant to my current VP3 work.').catch(fail);return msg('open_url',{url:absolute(cognitiveData&&cognitiveData.agent_url||'/chat.php')}).catch(fail);};ui.restoreNowBtn.onclick=async()=>{try{await cognitiveAction('restore_all',{});await loadNow(true);note('Hidden Agent items restored.','success');}catch(e){await fail(e);}};ui.toggleNowContextBtn.onclick=()=>{nowContextIgnored=!nowContextIgnored;loadNow(true).catch(fail);};ui.nowContextActions.onclick=contextSuggestionClick;ui.nowRelationshipList.onclick=contextSuggestionClick;ui.nowFeed.onclick=cognitiveClick;
ui.nowTab.onclick=()=>setView('now');ui.agentTab.onclick=()=>setView('agent');ui.thisPageTab.onclick=()=>setView('this_page');ui.followingTab.onclick=()=>setView('following');ui.liveTab.onclick=()=>setView('live');ui.alertsTab.onclick=()=>setView('alerts');ui.searchTab.onclick=()=>setView('search');ui.refreshCaptureBtn.onclick=()=>refreshCapture(true).catch(fail);ui.refreshFollowingBtn.onclick=()=>loadFollowing(true).catch(fail);ui.refreshLiveBtn.onclick=()=>loadLiveRooms().catch(fail);ui.refreshAlertsBtn.onclick=()=>loadAlerts().catch(fail);
ui.agentRefreshBtn.onclick=()=>refreshAgentWorkspaceV2160().catch(fail);
ui.agentConversationSelect.onchange=()=>loadAgentConversationV2160(Number(ui.agentConversationSelect.value||0)).catch(fail);
ui.agentNewChatBtn.onclick=()=>loadAgentConversationV2160(0).catch(fail);
ui.agentOpenFullBtn.onclick=()=>msg('open_url',{url:absolute(agentConversationId?'/chat.php?conversation_id='+agentConversationId:'/chat.php')});
ui.agentSendBtn.onclick=()=>sendAgentMessageV2160().catch(fail);
ui.agentMessageInput.oninput=renderCaps;
ui.agentMessageInput.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendAgentMessageV2160().catch(fail);}};
ui.agentUsePageContext.onchange=()=>{ui.agentContextLabel.textContent=ui.agentUsePageContext.checked?(capture&&capture.available?'Temporary context: '+(capture.title||host(capture.source_url)||'current page'):'No current page context.'):'Page context disabled for this turn.';};
ui.visibilitySelect.onchange=()=>{ui.visibilityTeamField.hidden=ui.visibilitySelect.value!=='team';renderCaps();};ui.visibilityTeamSelect.onchange=renderCaps;ui.destinationSelect.onchange=renderCaps;
ui.liveScope.onchange=()=>{ui.liveTeamField.hidden=ui.liveScope.value!=='team';renderCaps();};ui.liveTeamSelect.onchange=renderCaps;
ui.claimVisibility.onchange=()=>{ui.claimTeamField.hidden=ui.claimVisibility.value!=='team';renderCaps();};
ui.searchSubmitBtn.onclick=()=>runSearch().catch(fail);ui.searchInput.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();runSearch().catch(fail);}};[ui.searchType,ui.searchVisibility,ui.searchChanged,ui.searchTeam].forEach(x=>x.onchange=()=>{if(String(ui.searchInput.value||'').trim())runSearch().catch(fail);else loadDiscovery().catch(fail);});ui.searchThisSource.onchange=()=>{if(ui.searchThisSource.checked&&!(currentSource&&currentSource.id)){ui.searchThisSource.checked=false;return note('This page is not a recognized VP3 Source yet.','error');}if(String(ui.searchInput.value||'').trim())runSearch().catch(fail);else loadDiscovery().catch(fail);};ui.refreshDiscoveryBtn.onclick=()=>loadDiscovery().catch(fail);ui.openSearchPageBtn.onclick=()=>msg('open_url',{url:absolute('/search.php')});
ui.saveSearchBtn.onclick=async()=>{const q=String(ui.searchInput.value||'').trim();if(!q)return note('Search for something before saving it.','error');const name=prompt('Name this saved search:',q.slice(0,60));if(!name)return;try{await msg('search_action',{action:'save_search',payload:{name:name,q:q,filters:searchParams()}});note('Search saved.','success');await loadSearchHistory();}catch(e){await fail(e);}};
ui.clearRecentSearchesBtn.onclick=async()=>{try{await msg('search_action',{action:'clear_recent',payload:{}});searchRecentData=[];renderSearchHistory();}catch(e){await fail(e);}};
ui.savedSearchesList.onclick=async e=>{const target=e.target.closest('[data-action]');if(!target)return;const id=target.dataset.id||'';if(target.dataset.action==='saved_delete'){await msg('search_action',{action:'delete_saved',payload:{id:id}});await loadSearchHistory();return;}const s=searchSavedData.find(x=>String(x.id)===String(id));if(!s)return;ui.searchInput.value=s.query||'';ui.searchType.value=(s.filters&&Array.isArray(s.filters.types)&&s.filters.types[0])||'';ui.searchVisibility.value=s.filters&&s.filters.visibility||'';ui.searchChanged.value=s.filters&&s.filters.changed||'';ui.searchTeam.value=String(s.filters&&s.filters.team_id||'');ui.searchThisSource.checked=Boolean(s.filters&&s.filters.context_only&&currentSource&&currentSource.id);await runSearch();};
ui.recentSearchesList.onclick=async e=>{const target=e.target.closest('[data-action="recent_run"]');if(!target)return;const s=searchRecentData[Number(target.dataset.index||-1)];if(!s)return;ui.searchInput.value=s.query||'';ui.searchType.value=(s.filters&&Array.isArray(s.filters.types)&&s.filters.types[0])||'';ui.searchVisibility.value=s.filters&&s.filters.visibility||'';ui.searchChanged.value=s.filters&&s.filters.changed||'';ui.searchTeam.value=String(s.filters&&s.filters.team_id||'');ui.searchThisSource.checked=Boolean(s.filters&&s.filters.context_only&&currentSource&&currentSource.id);await runSearch();};
ui.searchResultsList.onclick=async e=>{const b=e.target.closest('button[data-action]');if(!b)return;try{if(b.dataset.action==='search_open'&&b.dataset.url)return await msg('open_url',{url:absolute(b.dataset.url)});if(b.dataset.action==='search_save')return await sourceAction('save',{browser_share_id:b.dataset.id,enabled:true}).then(()=>note('Saved.','success'));if(b.dataset.action==='search_research')return await sourceAction('research',{browser_share_id:b.dataset.id,enabled:true}).then(()=>note('Added to Research.','success'));if(b.dataset.action==='search_follow')return await sourceAction('follow_source',{source_id:b.dataset.sourceId,follow:true}).then(()=>note('Source followed.','success'));if(b.dataset.action==='search_claim'){const u='/claims.php?source='+encodeURIComponent(b.dataset.sourceId||'')+(b.dataset.annotationId?'&annotation='+encodeURIComponent(b.dataset.annotationId):'');return await msg('open_url',{url:absolute(u)});}if(b.dataset.action==='search_join_live'){await liveAction('join',{room:b.dataset.roomId,cloak_mode:false});note('Joined Live Room.','success');if(b.dataset.url)await msg('open_url',{url:absolute(b.dataset.url)});}}catch(err){await fail(err);}};ui.claimTeamSelect.onchange=renderCaps;ui.claimStatement.oninput=renderCaps;
ui.liveAllowCloak.onchange=()=>{ui.liveEnterCloaked.disabled=!ui.liveAllowCloak.checked;if(!ui.liveAllowCloak.checked)ui.liveEnterCloaked.checked=false;};
ui.captureScreenshotBtn.onclick=async()=>{busy(ui.captureScreenshotBtn,true,'Select region on page…');try{const r=await message('capture_region');if(r.cancelled)return;screenshotCapture=r;ui.screenshotImage.src=r.data_url;ui.screenshotMeta.textContent=Number(r.metadata&&r.metadata.width||0)+' × '+Number(r.metadata&&r.metadata.height||0)+' px';ui.screenshotPreview.hidden=false;}catch(e){fail(e);}finally{busy(ui.captureScreenshotBtn,false);renderCaps();}};
ui.removeScreenshotBtn.onclick=()=>{screenshotCapture=null;ui.screenshotPreview.hidden=true;renderCaps();};
ui.captureMediaBtn.onclick=()=>{const m=capture&&capture.media;if(!m||!http(m.source_media_url))return;const s=Math.max(0,Number(m.current_time||0)),d=Math.max(0,Number(m.duration||0)),e=d?Math.min(d,s+30):s+30;mediaReference={kind:m.kind,metadata:{source_media_url:m.source_media_url,source_media_title:String(m.source_media_title||capture.title||'').slice(0,512),source_media_kind:m.source_media_kind||'',duration_seconds:d,start_seconds:s,end_seconds:e}};ui.mediaPreviewTitle.textContent=m.kind==='youtube_clip'?'YouTube moment':m.source_media_kind==='audio'?'Audio moment':'Video moment';ui.mediaStart.value=s;ui.mediaEnd.value=e;ui.mediaPreview.hidden=false;clip('end');renderCaps();};
ui.mediaStart.onchange=()=>clip('start');ui.mediaEnd.onchange=()=>clip('end');ui.removeMediaBtn.onclick=()=>{mediaReference=null;ui.mediaPreview.hidden=true;renderCaps();};
ui.recordCommentaryBtn.onclick=()=>{if(recorder&&recorder.state==='recording')recorder.stop();else record().catch(fail);};ui.removeCommentaryBtn.onclick=()=>{commentaryCapture=null;ui.commentaryPreview.hidden=true;ui.commentaryAudio.pause();ui.commentaryAudio.removeAttribute('src');renderCaps();};
ui.quickAskBtn.onclick=()=>runSidebarQuickActionV2150('ask_page',ui.quickAskBtn);
ui.quickSummarizeBtn.onclick=()=>runSidebarQuickActionV2150('summarize',ui.quickSummarizeBtn);
ui.quickCompareBtn.onclick=()=>runSidebarQuickActionV2150('compare_knowledge',ui.quickCompareBtn);
ui.quickResearchBtn.onclick=()=>runSidebarQuickActionV2150('add_research',ui.quickResearchBtn);
ui.quickKnowledgeBtn.onclick=()=>runSidebarQuickActionV2150('save_knowledge',ui.quickKnowledgeBtn);
ui.quickTaskBtn.onclick=()=>runSidebarQuickActionV2150('create_task',ui.quickTaskBtn);
ui.quickTeamBtn.onclick=()=>runSidebarQuickActionV2150('share_team',ui.quickTeamBtn);
ui.quickAnnotateBtn.onclick=()=>runSidebarQuickActionV2150('annotate',ui.quickAnnotateBtn);
ui.shareBtn.onclick=async()=>{const d=destination();if(!d)return note('Choose where to deliver this annotation.','error');if(ui.visibilitySelect.value==='team'&&!Number(ui.visibilityTeamSelect.value))return note('Choose a Team for visibility.','error');busy(ui.shareBtn,true,'Publishing…');try{const r=await msg('share',{capture:capture,destination:d,visibility:ui.visibilitySelect.value,visibility_team_id:Number(ui.visibilityTeamSelect.value||0),note:ui.shareNote.value,rich_media:{screenshot:screenshotCapture,media_reference:mediaReference,commentary:commentaryCapture},idempotency_key:crypto.randomUUID()});r.source_url=capture.source_url;renderLast(r);ui.shareNote.value='';clearRich();note('Annotation published.','success');await loadThis(true);}catch(e){fail(e);}finally{busy(ui.shareBtn,false);}};
async function lastAction(a,label){if(!lastShare||!lastShare.browser_share)return;try{const r=await msg('share_action',{action:a,browser_share_id:lastShare.browser_share.id});if(a==='ask_agent'&&r.handoff_url)await msg('open_url',{url:r.handoff_url});else note(label,'success');}catch(e){if(e.code==='capability_denied')dropCapability(capabilityForAction(a));fail(e);}}
ui.askAgentBtn.onclick=()=>lastAction('ask_agent','Opened in VP3.');ui.saveKnowledgeBtn.onclick=()=>lastAction('save_knowledge','Saved to Knowledge.');ui.createTaskBtn.onclick=()=>lastAction('create_task','Task created.');
ui.openSourceBtn.onclick=()=>{const u=http(lastShare&&lastShare.source_url);if(u)msg('open_url',{url:u});};ui.openMessagesBtn.onclick=()=>{if(lastShare&&lastShare.chat_message)msg('open_url',{url:absolute('/messages.php?conversation_id='+Number(lastShare.chat_message.conversation_id))});};
ui.followCurrentSourceBtn.onclick=async()=>{if(!capture)return;try{const follow=!(currentSource&&currentSource.following),r=await sourceAction('follow_source',{source_id:currentSource&&currentSource.id||'',url:capture.source_url,canonical_url:capture.canonical_url,title:capture.title,follow:follow});currentSource=r.source;sourceHead({source:r.source});note(follow?'Source followed.':'Source unfollowed.','success');}catch(e){fail(e);}};
ui.openSourcePageBtn.onclick=()=>{if(currentSource&&currentSource.page_url)msg('open_url',{url:absolute(currentSource.page_url)});};
ui.notificationSettingsBtn.onclick=()=>msg('open_url',{url:absolute('/notification-settings.php')});
ui.fileClaimBtn.onclick=()=>{if(!currentSource||!currentSource.id)return note('Observe or follow this source first.','error');claimShareId='';ui.claimStatement.value='';ui.claimRationale.value='';ui.claimVisibility.value='public';ui.claimTeamField.hidden=true;ui.claimDialog.showModal();renderCaps();};
ui.claimSubmitBtn.onclick=async()=>{const statement=String(ui.claimStatement.value||'').trim();if(!statement||!currentSource||!currentSource.id)return;busy(ui.claimSubmitBtn,true,'Filing…');try{const r=await trustAction('claim_create',{source_id:currentSource.id,statement:statement,rationale:ui.claimRationale.value,visibility:ui.claimVisibility.value,team_id:Number(ui.claimTeamSelect.value||0),browser_share_id:claimShareId});ui.claimDialog.close();claimShareId='';note('Claim filed.','success');if(r&&r.claim&&r.claim.url)await msg('open_url',{url:absolute(r.claim.url)});await loadAlerts();}catch(e){await fail(e);}finally{busy(ui.claimSubmitBtn,false);}};
ui.notificationsList.onclick=async e=>{const b=e.target.closest('button[data-action]');if(!b)return;try{const id=Number(b.dataset.notificationId||0);if(b.dataset.action==='notification_dismiss'){await trustAction('notification_dismiss',{notification_id:id});await loadAlerts();return;}if(b.dataset.action==='notification_open'){await trustAction('notification_read',{notification_id:id});if(b.dataset.url)await msg('open_url',{url:absolute(b.dataset.url)});await loadAlerts();}}catch(err){await fail(err);}};
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
function pageWatch(){clearInterval(pageTimer);let identity=(capture&&capture.source_url||'')+'\n'+(capture&&capture.title||'');pageTimer=setInterval(async()=>{if(!state||!state.connected||!['now','agent','this_page','live','alerts','search'].includes(activeView))return;try{const x=await msg('tab_identity'),next=(x.source_url||'')+'\n'+(x.title||'');if(x.source_url&&next!==identity){identity=next;nowContextIgnored=false;await refreshCapture(activeView==='this_page');if(activeView==='now')await loadNow(true);if(activeView==='agent'&&ui.agentUsePageContext.checked)ui.agentContextLabel.textContent='Temporary context: '+(capture.title||host(capture.source_url)||'current page');if(activeView==='live'){liveRoom=null;liveCursor=0;ui.liveRoomPanel.hidden=true;await loadLiveRooms();}if(activeView==='alerts')await loadAlerts();if(activeView==='search')await loadDiscovery();}}catch(e){}},2000);}
window.addEventListener('focus',()=>{if(state&&state.connected)refreshState().catch(()=>{});});
window.onbeforeunload=()=>{clearInterval(pageTimer);clearInterval(livePollTimer);clearInterval(liveHeartbeatTimer);clearInterval(cognitiveTimer);stream&&stream.getTracks().forEach(t=>t.stop());};
refreshState().then(pageWatch).catch(fail);
