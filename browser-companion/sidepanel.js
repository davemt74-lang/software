
const $=id=>document.getElementById(id);
const ui={};
[
'connectionState','connectControls','shareWorkspace','connectBtn','settingsBtn','connectedAccount','disconnectedAccount','accountAvatar','accountName','accountMeta','accountTeams','openVp3Btn','refreshAccountBtn','accountOptionsBtn','accessNotice','quickActionsCard','composerCard','agentWorkspaceName','agentWorkspaceStatus','agentRefreshBtn','agentConversationSelect','agentNewChatBtn','agentOpenFullBtn','agentUsePageContext','agentContextLabel','agentMessages','agentEmpty','agentMessageInput','agentSendBtn','delegationAgentName','delegationStatus','delegationRefreshBtn','delegationInstruction','delegationMaxSteps','delegationExpiry','delegationRisk','delegationDomains','delegationPreviewBtn','delegationStartBtn','delegationPlan','delegationActive','delegationActiveTitle','delegationActiveMeta','delegationProgress','delegationRunBtn','delegationPauseBtn','delegationResumeBtn','delegationCancelBtn','delegationOpenWorkflowBtn','runtimePanel','runtimeSessionBadge','runtimePlanRevision','runtimeCurrentSkill','runtimeRecovery','runtimeObservationCount','runtimeLastVerified','runtimeReplanBtn','runtimeSkipBtn','runtimeMultiPanel','runtimeMultiStatus','runtimeMultiAttachBtn','runtimeMultiCurrentDomain','runtimeMultiHandoffCount','runtimeMultiTabCount','runtimeMultiConflictCount','runtimeMultiDomains','runtimeMultiDomainsEmpty','runtimeMultiHandoffComposer','runtimeMultiTargetUrl','runtimeMultiGoBtn','runtimeMultiFactComposer','runtimeMultiFactKey','runtimeMultiFactValue','runtimeMultiFactAddBtn','runtimeMultiFacts','runtimeMultiFactsEmpty','runtimeMultiHandoffs','runtimeMultiHandoffsEmpty','runtimeMultiArtifacts','runtimeMultiArtifactsEmpty','runtimeResearchPanel','runtimeResearchStatus','runtimeResearchRefreshBtn','runtimeResearchStart','runtimeResearchQuestion','runtimeResearchProject','runtimeResearchMaxSources','runtimeResearchMaxPages','runtimeResearchMaxClaims','runtimeResearchDuration','runtimeResearchStartBtn','runtimeResearchActive','runtimeResearchPageCount','runtimeResearchClaimCount','runtimeResearchCorroborated','runtimeResearchConflicts','runtimeResearchAnalyzeBtn','runtimeResearchOpenChatBtn','runtimeResearchSaveBtn','runtimeResearchCancelBtn','runtimeResearchOpenReportBtn','runtimeResearchSources','runtimeResearchSourcesEmpty','runtimeResearchClaims','runtimeResearchClaimsEmpty','runtimeResearchGaps','runtimeResearchGapsEmpty','runtimeResearchHandoffs','runtimeResearchKnowledgeBtn','runtimeResearchCrmBtn','runtimeResearchTaskBtn','runtimeTimeline','runtimeTimelineEmpty','runtimeTabs','runtimeTabsEmpty','runtimeWebPanel','runtimeWebStatus','runtimeWebScanBtn','runtimeWebControlCount','runtimeWebInteractionCount','runtimeWebElements','runtimeWebElementsEmpty','runtimeWebComposer','runtimeWebSelectedLabel','runtimeWebSelectedMeta','runtimeWebActionSelect','runtimeWebValueField','runtimeWebValueLabel','runtimeWebValueInput','runtimeWebOptionField','runtimeWebOptionSelect','runtimeWebToggleField','runtimeWebToggleSelect','runtimeWebPreviewBtn','runtimeWebClearSelectionBtn','runtimeWebProposal','runtimeWebProposalTitle','runtimeWebProposalDetail','runtimeWebCheckpointNotice','runtimeWebRunBtn','runtimeWebConfirmRunBtn','runtimeWebCancelBtn','runtimeWebRecent','runtimeWebRecentEmpty','delegationCheckpoint','delegationCheckpointText','delegationCheckpointOpenBtn','delegationCheckpointDoneBtn','delegationSteps','delegationRecent','delegationRecentEmpty','executionAgentName','executionStatus','executionRefreshBtn','executionPageLabel','executionCandidates','executionCandidatesEmpty','executionTickets','executionTicketsEmpty','executionContinuity','executionContinuityEmpty','memoryAgentName','memoryStatus','memoryRefreshBtn','memoryPageLabel','memoryCandidates','memoryCandidatesEmpty','memoryRemembered','memoryRememberedEmpty','memoryCount',
'nowTab','agentTab','delegationTab','executionTab','memoryTab','thisPageTab','followingTab','liveTab','alertsTab','searchTab','nowView','agentView','delegationView','executionView','memoryView','thisPageView','followingView','liveView','alertsView','searchView','refreshNowBtn','openAgentChatBtn','restoreNowBtn','nowStatus','nowAttentionCount','nowItemCount','nowContextualCount','nowContextStrip','nowContextTitle','nowContextMeta','toggleNowContextBtn','nowContextPanel','nowRelationshipSummary','nowRelationshipList','nowContextActions','nowEmpty','nowFeed','refreshCaptureBtn','pageTitle','pageHost','sourceMeta','sourceStatus',
'followCurrentSourceBtn','openSourcePageBtn','quickAskBtn','quickSummarizeBtn','quickCompareBtn','quickResearchBtn','quickKnowledgeBtn','quickTaskBtn','quickMemoryBtn','quickTeamBtn','quickAnnotateBtn','selectedText','selectionCount','captureSummary','captureScreenshotBtn','screenshotPreview',
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
const VP3_DELEGATION_CLIENT_STEP_LIMIT_V2190=12;
const VP3_RUNTIME_CLIENT_STEP_LIMIT_V2200=16;
let state=null,capture=null,destinations=null,lastShare=null,currentSource=null;
let screenshotCapture=null,mediaReference=null,commentaryCapture=null,recorder=null,stream=null,recordTimer=null,recordStarted=0,pageTimer=null;
let thisCursor='',followingCursor='',thisBusy=false,followingBusy=false,activeView='now';
let cognitiveBusy=false,cognitiveData=null,cognitiveTimer=null;
let agentConversationId=0,agentWorkspaceAgentId=0,agentLastMessageId=0,agentPollTimer=null,agentBusy=false,agentConversations=[];
let delegationBusy=false,delegationRunnerBusy=false,delegationPreviewData=null,delegationActiveData=null,delegationRecentData=[],delegationCheckpointData=null;
let runtimeBusyV2200=false,runtimeRunnerBusyV2200=false,runtimeDataV2200=null,runtimeSkillsV2200=[],runtimeOpenedTabsV2200=[];
let runtimeWebBusyV2210=false,runtimeWebObservationV2210=null,runtimeWebElementsV2210=[],runtimeWebActionsV2210=[],runtimeWebSelectedV2210=null,runtimeWebProposalV2210=null,runtimeWebReceiptsV2210=[];
let runtimeMultiBusyV2220=false,runtimeMultiStateV2220=null;
let runtimeResearchBusyV2230=false,runtimeResearchMissionV2230=null,runtimeResearchProjectsV2230=[];
let executionBusy=false,executionCandidatesData=[],executionTicketsData=[],executionContinuityData=[];
let memoryBusy=false,memoryCandidatesData=[],memoryRememberedData=[];
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
  ui.delegationTab.disabled=!(state&&state.connected&&c.has('agent.message'));
  ui.executionTab.disabled=!(state&&state.connected&&c.has('agent.message'));
  ui.memoryTab.disabled=!(state&&state.connected&&c.has('agent.message'));
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
  ui.quickMemoryBtn.disabled=!quickPageOk||!c.has('agent.message');
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
  if(ui.agentContextLabel)ui.agentContextLabel.textContent=!ui.agentUsePageContext.checked
    ?'Page context disabled for this turn.'
    :(capture&&capture.available
      ?'Temporary context: '+(capture.title||host(capture.source_url)||'current page')
      :'No current page context.');
  if(ui.executionPageLabel)ui.executionPageLabel.textContent=capture&&capture.available
    ?'Current page: '+(capture.title||host(capture.source_url)||'current page')
    :'No current page context.';
  if(ui.memoryPageLabel)ui.memoryPageLabel.textContent=capture&&capture.available
    ?'Current page: '+(capture.title||host(capture.source_url)||'current page')
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
function agentWorkspaceRequestV2160(action,payload){
  const request=Object.assign({},payload||{});
  if(agentWorkspaceAgentId>0)request.agent_id=agentWorkspaceAgentId;
  return msg('agent_workspace',{action:action,payload:request});
}
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
  if(payload&&payload.agent){
    agentWorkspaceAgentId=Math.max(0,Number(payload.agent.id||0));
    if(payload.agent.name)ui.agentWorkspaceName.textContent=payload.agent.name;
  }
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
  if(payload&&payload.agent){
    agentWorkspaceAgentId=Math.max(0,Number(payload.agent.id||0));
    if(payload.agent.name)ui.agentWorkspaceName.textContent=payload.agent.name;
  }
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
    agentWorkspaceAgentId=Math.max(0,Number(payload&&payload.agent_id||agentWorkspaceAgentId||0));
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
function normalizeDomainV2220(value){return String(value||'').trim().toLowerCase().replace(/^www\./,'');}
function runtimeMultiResetV2220(){
  runtimeMultiStateV2220=null;
  ui.runtimeMultiStatus.textContent='Attach the approved domain envelope to coordinate cross-site work.';
  ui.runtimeMultiCurrentDomain.textContent='Current: —';
  ui.runtimeMultiHandoffCount.textContent='0 / 0 handoffs';
  ui.runtimeMultiTabCount.textContent='0 / 0 tabs';
  ui.runtimeMultiConflictCount.textContent='0 conflicts';
  ui.runtimeMultiDomains.replaceChildren();ui.runtimeMultiDomainsEmpty.hidden=false;
  ui.runtimeMultiHandoffComposer.hidden=true;ui.runtimeMultiFactComposer.hidden=true;
  ui.runtimeMultiFacts.replaceChildren();ui.runtimeMultiFactsEmpty.hidden=false;
  ui.runtimeMultiHandoffs.replaceChildren();ui.runtimeMultiHandoffsEmpty.hidden=false;
  ui.runtimeMultiArtifacts.replaceChildren();ui.runtimeMultiArtifactsEmpty.hidden=false;
}
function runtimeMultiPolicyV2220(domain){
  const d=normalizeDomainV2220(domain);
  return (runtimeMultiStateV2220&&Array.isArray(runtimeMultiStateV2220.domains)?runtimeMultiStateV2220.domains:[]).find(item=>normalizeDomainV2220(item.domain)===d)||null;
}
function runtimeMultiDomainRowV2220(item){
  const row=el('div','runtime-multi-domain'+(normalizeDomainV2220(item.domain)===normalizeDomainV2220(runtimeMultiStateV2220&&runtimeMultiStateV2220.current_domain)?' current':''),'');
  const copy=el('div','runtime-multi-domain-copy','');
  copy.append(el('strong','',String(item.domain||'Approved domain')));
  copy.append(el('span','',(item.visit_count||0)+' visits'+(item.last_visited_at?' · '+date(item.last_visited_at):'')+' · '+(item.allowed_actions||[]).length+' skills'));
  const controls=el('div','runtime-multi-policy','');
  const select=document.createElement('select');select.dataset.multiPolicyDomain=String(item.domain||'');
  for(const mode of [['browse','Browse'],['delegated','Delegated'],['blocked','Blocked']]){
    const option=document.createElement('option');option.value=mode[0];option.textContent=mode[1];option.selected=String(item.policy_mode||'browse')===mode[0];select.append(option);
  }
  controls.append(select);row.append(copy,controls);return row;
}
function runtimeMultiRowV2220(title,detail,status=''){
  const row=el('div','runtime-multi-row '+String(status||''),'');
  const copy=el('div','runtime-multi-row-copy','');
  copy.append(el('strong','',String(title||'Runtime item')),el('span','',String(detail||'')));
  row.append(copy);return row;
}
function renderRuntimeMultiV2220(state){
  runtimeMultiStateV2220=state&&state.attached?state:null;
  if(!runtimeMultiStateV2220){runtimeMultiResetV2220();return;}
  ui.runtimeMultiStatus.textContent=String(state.status||'active')+' · '+(state.domains||[]).length+' approved domains · URLs stay local in Chrome';
  ui.runtimeMultiCurrentDomain.textContent='Current: '+String(state.current_domain||'—');
  ui.runtimeMultiHandoffCount.textContent=String(state.handoff_count||0)+' / '+String(state.max_handoffs||0)+' handoffs';
  const openTabs=(state.tabs||[]).filter(x=>String(x.status||'')==='open').length;
  ui.runtimeMultiTabCount.textContent=openTabs+' / '+String(state.max_tabs||0)+' tabs';
  const conflicts=(state.facts||[]).filter(x=>String(x.status||'')==='conflict').length;
  ui.runtimeMultiConflictCount.textContent=conflicts+' conflicts';

  ui.runtimeMultiDomains.replaceChildren();(state.domains||[]).forEach(item=>ui.runtimeMultiDomains.append(runtimeMultiDomainRowV2220(item)));
  ui.runtimeMultiDomainsEmpty.hidden=(state.domains||[]).length>0;
  ui.runtimeMultiHandoffComposer.hidden=false;ui.runtimeMultiFactComposer.hidden=false;

  ui.runtimeMultiFacts.replaceChildren();
  (state.facts||[]).forEach(item=>ui.runtimeMultiFacts.append(runtimeMultiRowV2220(
    String(item.fact_key||'Fact')+' · '+String(item.value||''),
    String(item.source_domain||'')+(item.created_at?' · '+date(item.created_at):''),
    String(item.status||'')
  )));
  ui.runtimeMultiFactsEmpty.hidden=(state.facts||[]).length>0;

  ui.runtimeMultiHandoffs.replaceChildren();
  (state.handoffs||[]).forEach(item=>ui.runtimeMultiHandoffs.append(runtimeMultiRowV2220(
    String(item.source_domain||'')+' → '+String(item.target_domain||''),
    String(item.status||'')+(item.result_code?' · '+String(item.result_code):'')+(item.created_at?' · '+date(item.created_at):''),
    String(item.status||'')
  )));
  ui.runtimeMultiHandoffsEmpty.hidden=(state.handoffs||[]).length>0;

  ui.runtimeMultiArtifacts.replaceChildren();
  (state.artifacts||[]).forEach(item=>ui.runtimeMultiArtifacts.append(runtimeMultiRowV2220(
    (item.file_ext?'.'+String(item.file_ext):'download')+' · '+String(item.mime_type||'unknown type'),
    String(item.source_domain||'')+' · '+String(item.byte_size||0)+' bytes · recorded only',
    String(item.status||'')
  )));
  ui.runtimeMultiArtifactsEmpty.hidden=(state.artifacts||[]).length>0;
}
async function attachRuntimeMultiV2220(){
  if(runtimeMultiBusyV2220||!runtimeDataV2200||!runtimeDataV2200.runtime_id)return;
  runtimeMultiBusyV2220=true;busy(ui.runtimeMultiAttachBtn,true,'Attaching…');
  try{
    const result=await msg('multisite_attach',{payload:{runtime_id:runtimeDataV2200.runtime_id,agent_id:agentWorkspaceAgentId}});
    renderRuntimeMultiV2220(result&&result.state||null);
    note('Multi-site Runtime attached to the approved domain envelope.','success');
    await loadRuntimeDetailV2200();
  }finally{runtimeMultiBusyV2220=false;busy(ui.runtimeMultiAttachBtn,false);}
}
async function loadRuntimeMultiV2220(){
  if(!runtimeDataV2200||!runtimeDataV2200.runtime_id)return null;
  try{
    const state=await msg('multisite_state',{payload:{runtime_id:runtimeDataV2200.runtime_id,agent_id:agentWorkspaceAgentId}});
    renderRuntimeMultiV2220(state||null);return state||null;
  }catch(error){
    if(String(error.message||'').includes('Attach the multi-site runtime')){runtimeMultiResetV2220();return null;}
    throw error;
  }
}
async function updateRuntimeMultiPolicyV2220(domain,mode){
  if(!runtimeDataV2200||!runtimeDataV2200.runtime_id)return;
  const state=await msg('multisite_policy',{payload:{runtime_id:runtimeDataV2200.runtime_id,agent_id:agentWorkspaceAgentId,domain,policy_mode:mode}});
  renderRuntimeMultiV2220(state||null);await loadRuntimeDetailV2200();
}
async function runRuntimeMultiHandoffV2220(targetUrl){
  if(!runtimeDataV2200||!runtimeDataV2200.runtime_id)throw new Error('Browser Runtime is not attached.');
  if(!runtimeMultiStateV2220)throw new Error('Attach Multi-Site Runtime before moving between domains.');
  const raw=String(targetUrl||'').trim();if(!raw)throw new Error('Enter an approved destination URL.');
  const result=await msg('multisite_handoff',{payload:{runtime_id:runtimeDataV2200.runtime_id,agent_id:agentWorkspaceAgentId,target_url:raw}});
  renderRuntimeMultiV2220(result&&result.state||null);
  const ok=Boolean(result&&result.outcome&&result.outcome.verified);
  note(ok?'Cross-domain handoff completed and verified.':'Cross-domain handoff stopped because the approved destination could not be verified.',ok?'success':'error');
  await refreshCapture(false).catch(()=>{});
  await loadRuntimeDetailV2200();
  return result;
}
async function addRuntimeMultiFactV2220(){
  if(runtimeMultiBusyV2220||!runtimeDataV2200||!runtimeDataV2200.runtime_id)return;
  const factKey=String(ui.runtimeMultiFactKey.value||'').trim(),value=String(ui.runtimeMultiFactValue.value||'').trim();
  if(!factKey||!value)throw new Error('Enter both a fact name and value.');
  runtimeMultiBusyV2220=true;busy(ui.runtimeMultiFactAddBtn,true,'Adding…');
  try{
    const state=await msg('multisite_fact_add',{payload:{runtime_id:runtimeDataV2200.runtime_id,agent_id:agentWorkspaceAgentId,fact_key:factKey,value}});
    renderRuntimeMultiV2220(state||null);
    ui.runtimeMultiFactKey.value='';ui.runtimeMultiFactValue.value='';
    const conflicts=(state&&state.facts||[]).filter(x=>String(x.status||'')==='conflict').length;
    note(conflicts?'Structured fact added; a source conflict needs review.':'Structured fact added with source provenance.',conflicts?'error':'success');
    await loadRuntimeDetailV2200();
  }finally{runtimeMultiBusyV2220=false;busy(ui.runtimeMultiFactAddBtn,false);}
}
async function runtimeMultiDomainChangeV2220(event){
  const select=event.target.closest('[data-multi-policy-domain]');if(!select)return;
  await updateRuntimeMultiPolicyV2220(String(select.dataset.multiPolicyDomain||''),String(select.value||'browse'));
}

function researchRequestV2230(action,payload={}){
  const request=Object.assign({},payload||{});
  if(agentWorkspaceAgentId>0)request.agent_id=agentWorkspaceAgentId;
  return msg('research_action',{action:String(action||'list'),payload:request});
}
function runtimeResearchResetV2230(){
  runtimeResearchMissionV2230=null;
  ui.runtimeResearchStatus.textContent='Start a bounded mission inside the approved Browser Runtime.';
  ui.runtimeResearchStart.hidden=false;ui.runtimeResearchActive.hidden=true;
  ui.runtimeResearchPageCount.textContent='0 / 0 pages';ui.runtimeResearchClaimCount.textContent='0 / 0 claims';
  ui.runtimeResearchCorroborated.textContent='0 corroborated';ui.runtimeResearchConflicts.textContent='0 conflicts';
  ui.runtimeResearchSources.replaceChildren();ui.runtimeResearchSourcesEmpty.hidden=false;
  ui.runtimeResearchClaims.replaceChildren();ui.runtimeResearchClaimsEmpty.hidden=false;
  ui.runtimeResearchGaps.replaceChildren();ui.runtimeResearchGapsEmpty.hidden=false;
  ui.runtimeResearchHandoffs.hidden=true;ui.runtimeResearchOpenReportBtn.hidden=true;
}
function renderResearchProjectsV2230(projects){
  runtimeResearchProjectsV2230=Array.isArray(projects)?projects:[];
  const current=String(ui.runtimeResearchProject.value||'');
  ui.runtimeResearchProject.replaceChildren();
  const none=document.createElement('option');none.value='';none.textContent='Choose later';ui.runtimeResearchProject.append(none);
  runtimeResearchProjectsV2230.forEach(project=>{
    const opt=document.createElement('option');opt.value=String(project.id||'');opt.textContent=String(project.title||'Research project');ui.runtimeResearchProject.append(opt);
  });
  if(current&&runtimeResearchProjectsV2230.some(x=>String(x.id)===current))ui.runtimeResearchProject.value=current;
}
function researchSourceRowV2230(item){
  const row=el('div','runtime-research-source '+(item.checked?'checked':''),'');
  row.append(el('strong','',String(item.domain||'Approved source')),el('span','',item.checked?String(item.pages||0)+' page'+(Number(item.pages||0)===1?'':'s')+' analyzed':'Not checked yet'));
  return row;
}
function researchClaimRowV2230(item){
  const state=String(item.state||'single_source');
  const row=el('div','runtime-research-claim '+state,'');
  const top=el('div','row-between','');
  top.append(el('strong','',String(item.value||item.statement||'Claim')),el('span','pill',state.replace(/_/g,' ')));
  row.append(top);
  if(item.statement&&item.statement!==item.value)row.append(el('span','',String(item.statement)));
  const meta=[Number(item.support_sources||0)+' source group'+(Number(item.support_sources||0)===1?'':'s')];
  if(Number(item.primary_sources||0)>0)meta.push(Number(item.primary_sources)+' primary');
  if(Number(item.direct_sources||0)>0)meta.push(Number(item.direct_sources)+' direct');
  if(item.freshest_at)meta.push('freshest '+String(item.freshest_at));
  row.append(el('small','',meta.join(' · ')));
  const evidence=Array.isArray(item.evidence)?item.evidence:[];
  evidence.slice(0,6).forEach(e=>{
    const ev=el('div','runtime-research-evidence','');
    ev.append(el('strong','',String(e.domain||'source')+(e.source_kind&&e.source_kind!=='unknown'?' · '+String(e.source_kind):'')),el('span','',String(e.excerpt||'')));
    row.append(ev);
  });
  return row;
}
function renderRuntimeResearchV2230(mission){
  runtimeResearchMissionV2230=mission||null;
  if(!mission){runtimeResearchResetV2230();return;}
  const status=String(mission.status||'active');
  const progress=mission.progress||{},budgets=mission.budgets||{};
  ui.runtimeResearchStatus.textContent=status.replace(/_/g,' ')+' · '+String(mission.question||'Browser Research mission');
  ui.runtimeResearchStart.hidden=true;ui.runtimeResearchActive.hidden=false;
  ui.runtimeResearchPageCount.textContent=String(progress.pages||0)+' / '+String(budgets.pages||0)+' pages';
  ui.runtimeResearchClaimCount.textContent=String(progress.claims||0)+' / '+String(budgets.claims||0)+' claims';
  ui.runtimeResearchCorroborated.textContent=String(progress.corroborated||0)+' corroborated';
  ui.runtimeResearchConflicts.textContent=String(progress.conflicted||0)+' conflicts';
  ui.runtimeResearchSources.replaceChildren();(mission.source_plan||[]).forEach(item=>ui.runtimeResearchSources.append(researchSourceRowV2230(item)));
  ui.runtimeResearchSourcesEmpty.hidden=(mission.source_plan||[]).length>0;
  ui.runtimeResearchClaims.replaceChildren();(mission.claims||[]).forEach(item=>ui.runtimeResearchClaims.append(researchClaimRowV2230(item)));
  ui.runtimeResearchClaimsEmpty.hidden=(mission.claims||[]).length>0;
  ui.runtimeResearchGaps.replaceChildren();(mission.gaps||[]).forEach(gap=>ui.runtimeResearchGaps.append(el('div','runtime-research-gap',String(gap))));
  ui.runtimeResearchGapsEmpty.hidden=(mission.gaps||[]).length>0;
  const active=status==='active';
  ui.runtimeResearchAnalyzeBtn.disabled=!active;ui.runtimeResearchCancelBtn.hidden=!active;
  ui.runtimeResearchSaveBtn.hidden=!active||Number(progress.claims||0)<1;
  ui.runtimeResearchHandoffs.hidden=!(mission.report&&mission.report.id);
  ui.runtimeResearchOpenReportBtn.hidden=!(mission.report&&mission.report.url);
  if(mission.project&&mission.project.id)ui.runtimeResearchProject.value=String(mission.project.id);
}
async function loadRuntimeResearchV2230(){
  if(!runtimeDataV2200||!runtimeDataV2200.runtime_id)return null;
  try{
    const payload=await researchRequestV2230('list',{});
    renderResearchProjectsV2230(payload&&payload.projects||[]);
    const missions=Array.isArray(payload&&payload.missions)?payload.missions:[];
    const current=missions.find(item=>String(item.runtime_id||'')===String(runtimeDataV2200.runtime_id||'')&&!['cancelled','expired'].includes(String(item.status||'')))
      ||missions.find(item=>String(item.runtime_id||'')===String(runtimeDataV2200.runtime_id||''))||null;
    renderRuntimeResearchV2230(current);return current;
  }catch(error){
    if(String(error.message||'').includes('upgrade')){runtimeResearchResetV2230();return null;}
    throw error;
  }
}
async function startRuntimeResearchV2230(){
  if(runtimeResearchBusyV2230||!runtimeDataV2200||!runtimeDataV2200.runtime_id)return;
  const question=String(ui.runtimeResearchQuestion.value||'').trim();if(question.length<5)throw new Error('Describe the research question or goal.');
  if(!runtimeMultiStateV2220)await attachRuntimeMultiV2220();
  runtimeResearchBusyV2230=true;busy(ui.runtimeResearchStartBtn,true,'Starting…');
  try{
    const payload=await researchRequestV2230('start',{
      runtime_id:runtimeDataV2200.runtime_id,question,project_id:String(ui.runtimeResearchProject.value||''),
      max_sources:Number(ui.runtimeResearchMaxSources.value||3),max_pages:Number(ui.runtimeResearchMaxPages.value||10),
      max_claims:Number(ui.runtimeResearchMaxClaims.value||50),duration_minutes:Number(ui.runtimeResearchDuration.value||60)
    });
    renderRuntimeResearchV2230(payload&&payload.mission||null);
    note('Browser Research mission started inside the approved source envelope.','success');
    await loadRuntimeDetailV2200();
  }finally{runtimeResearchBusyV2230=false;busy(ui.runtimeResearchStartBtn,false);}
}
async function analyzeRuntimeResearchPageV2230(){
  if(runtimeResearchBusyV2230||!runtimeResearchMissionV2230)return;
  runtimeResearchBusyV2230=true;busy(ui.runtimeResearchAnalyzeBtn,true,'Analyzing…');
  try{
    const payload=await msg('research_analyze_current',{payload:{mission_id:String(runtimeResearchMissionV2230.mission_id||''),agent_id:agentWorkspaceAgentId}});
    renderRuntimeResearchV2230(payload&&payload.mission||null);
    const conflicts=Number(payload&&payload.mission&&payload.mission.progress&&payload.mission.progress.conflicted||0);
    note(conflicts?'Page analyzed. Conflicting evidence needs review.':'Page analyzed and evidence ledger updated.',conflicts?'error':'success');
    await loadRuntimeDetailV2200();
  }finally{runtimeResearchBusyV2230=false;busy(ui.runtimeResearchAnalyzeBtn,false);}
}
async function saveRuntimeResearchV2230(){
  if(runtimeResearchBusyV2230||!runtimeResearchMissionV2230)return;
  const projectId=String(ui.runtimeResearchProject.value||runtimeResearchMissionV2230.project&&runtimeResearchMissionV2230.project.id||'');
  if(!projectId)throw new Error('Choose a Research project before saving the mission.');
  runtimeResearchBusyV2230=true;busy(ui.runtimeResearchSaveBtn,true,'Saving…');
  try{
    const payload=await researchRequestV2230('save',{mission_id:runtimeResearchMissionV2230.mission_id,project_id:projectId});
    renderRuntimeResearchV2230(payload&&payload.mission||null);
    note('Draft Findings and Research Report saved for review. Nothing was published.','success');
    await loadRuntimeDetailV2200();
  }finally{runtimeResearchBusyV2230=false;busy(ui.runtimeResearchSaveBtn,false);}
}
async function cancelRuntimeResearchV2230(){
  if(!runtimeResearchMissionV2230)return;
  const payload=await researchRequestV2230('cancel',{mission_id:runtimeResearchMissionV2230.mission_id});
  renderRuntimeResearchV2230(payload&&payload.mission||null);note('Browser Research mission cancelled.','success');
}
async function openRuntimeResearchChatV2230(){
  if(!runtimeResearchMissionV2230)return;
  setView('agent');
  const cid=Number(runtimeResearchMissionV2230.conversation_id||0);
  if(cid>0)await loadAgentConversationV2160(cid);else await loadAgentConversationV2160(0);
  note(cid>0?'Research extraction conversation opened.':'No research extraction conversation exists yet. Analyze a page first.','success');
}
function draftRuntimeResearchHandoffV2230(kind){
  if(!runtimeResearchMissionV2230)return;
  const prompt=String(runtimeResearchMissionV2230.handoffs&&runtimeResearchMissionV2230.handoffs[kind]||'');if(!prompt)return;
  setView('agent');ui.agentMessageInput.value=prompt;renderCaps();ui.agentMessageInput.focus();
  note('Draft handoff prepared in Agent. Review it before sending.','success');
}
async function openRuntimeResearchReportV2230(){
  const url=runtimeResearchMissionV2230&&runtimeResearchMissionV2230.report&&runtimeResearchMissionV2230.report.url;if(url)await msg('open_url',{url:absolute(url)});
}

function webInteractionRequestV2210(action,payload={}){
  const request=Object.assign({},payload||{});
  if(agentWorkspaceAgentId>0)request.agent_id=agentWorkspaceAgentId;
  return msg('web_interaction_action',{action:action,payload:request});
}
function runtimeWebElementLabelV2210(item){
  return String(item&&item.label||item&&item.aria_label||item&&item.placeholder||item&&item.name||item&&item.kind||'Page control').trim()||'Page control';
}
function runtimeWebElementMetaV2210(item){
  const bits=[String(item&&item.kind||'control').replace(/_/g,' ')];
  if(item&&item.input_type)bits.push(String(item.input_type));
  if(item&&item.target_host)bits.push(String(item.target_host));
  if(item&&item.sensitive)bits.push('manual sensitive field');
  if(item&&item.submit_like)bits.push('submit control');
  return bits.join(' · ');
}
function runtimeWebResetProposalV2210(){
  runtimeWebProposalV2210=null;
  ui.runtimeWebProposal.hidden=true;
  ui.runtimeWebCheckpointNotice.hidden=true;
  ui.runtimeWebRunBtn.hidden=false;
  ui.runtimeWebConfirmRunBtn.hidden=true;
}
async function runtimeWebInvalidateProposalV2210(){
  const current=runtimeWebProposalV2210;
  runtimeWebResetProposalV2210();
  if(current&&current.proposal&&current.proposal.interaction_id&&runtimeDataV2200&&runtimeDataV2200.runtime_id){
    try{await webInteractionRequestV2210('cancel',{runtime_id:runtimeDataV2200.runtime_id,interaction_id:current.proposal.interaction_id});}catch(_error){}
  }
}
function runtimeWebResetV2210(){
  runtimeWebObservationV2210=null;runtimeWebElementsV2210=[];runtimeWebActionsV2210=[];runtimeWebSelectedV2210=null;runtimeWebReceiptsV2210=[];
  ui.runtimeWebStatus.textContent='Scan the current approved page to inspect interactive controls.';
  ui.runtimeWebControlCount.textContent='0 controls';
  ui.runtimeWebInteractionCount.textContent='0 / 0 interactions';
  ui.runtimeWebElements.replaceChildren();ui.runtimeWebElementsEmpty.hidden=false;
  ui.runtimeWebComposer.hidden=true;ui.runtimeWebRecent.replaceChildren();ui.runtimeWebRecentEmpty.hidden=false;
  runtimeWebResetProposalV2210();
}
function runtimeWebActionRegistryV2210(key){
  return runtimeWebActionsV2210.find(item=>String(item.key||'')===String(key||''))||null;
}
function runtimeWebActionsForElementV2210(item){
  const source=normalizeDomainV2220(runtimeWebObservationV2210&&runtimeWebObservationV2210.domain);
  const target=normalizeDomainV2220(item&&item.target_host);
  const crossDomain=String(item&&item.kind||'')==='link'&&target&&source&&target!==source;
  const actions=runtimeWebActionsV2210.filter(action=>{
    const key=String(action.key||'');
    if(!Array.isArray(action.kinds)||!action.kinds.includes(String(item.kind||'control')))return false;
    if(key==='submit'&&!item.submit_like)return false;
    if(key==='open_link'&&(!item.target_host||String(item.kind)!=='link'||crossDomain))return false;
    if(item.sensitive&&['type','clear','select','toggle','submit'].includes(key))return false;
    if(String(item.kind)==='radio'&&key==='toggle')return true;
    return true;
  });
  const policy=crossDomain?runtimeMultiPolicyV2220(target):null;
  if(crossDomain&&policy&&String(policy.policy_mode||'')!=='blocked'&&item.target_url){
    actions.unshift({key:'handoff',label:'Move to approved domain',risk_level:'low',kinds:['link']});
  }
  return actions;
}
function runtimeWebElementRowV2210(item,index){
  const row=el('button','runtime-web-element','');
  row.type='button';row.dataset.webElementIndex=String(index);
  if(runtimeWebSelectedV2210&&runtimeWebSelectedV2210.element_fingerprint===item.element_fingerprint)row.classList.add('selected');
  const copy=el('div','runtime-web-element-copy','');
  copy.append(el('strong','',runtimeWebElementLabelV2210(item)),el('span','',runtimeWebElementMetaV2210(item)));
  const flags=el('div','runtime-web-element-flags','');
  if(item.sensitive)flags.append(el('span','pill','manual'));
  if(item.dangerous||item.submit_like)flags.append(el('span','pill','checkpoint'));
  if(item.disabled)flags.append(el('span','pill','disabled'));
  row.append(copy,flags);return row;
}
function renderRuntimeWebElementsV2210(){
  ui.runtimeWebElements.replaceChildren();
  runtimeWebElementsV2210.forEach((item,index)=>ui.runtimeWebElements.append(runtimeWebElementRowV2210(item,index)));
  ui.runtimeWebElementsEmpty.hidden=runtimeWebElementsV2210.length>0;
  ui.runtimeWebControlCount.textContent=runtimeWebElementsV2210.length+' controls';
}
function runtimeWebConfigureValueV2210(){
  const action=String(ui.runtimeWebActionSelect.value||'');
  const item=runtimeWebSelectedV2210;
  ui.runtimeWebValueField.hidden=true;ui.runtimeWebOptionField.hidden=true;ui.runtimeWebToggleField.hidden=true;
  if(action==='type'){
    ui.runtimeWebValueField.hidden=false;ui.runtimeWebValueLabel.textContent='Value · stays local in Chrome';
  }else if(action==='select'){
    ui.runtimeWebOptionField.hidden=false;ui.runtimeWebOptionSelect.replaceChildren();
    (item&&Array.isArray(item.options)?item.options:[]).forEach(option=>{
      const opt=document.createElement('option');opt.value=String(option.value??'');opt.textContent=String(option.label||option.value||'Option');ui.runtimeWebOptionSelect.append(opt);
    });
  }else if(action==='toggle'){
    ui.runtimeWebToggleField.hidden=false;
    const off=ui.runtimeWebToggleSelect.querySelector('option[value="false"]');
    if(off)off.disabled=String(item&&item.kind||'')==='radio';
    if(String(item&&item.kind||'')==='radio')ui.runtimeWebToggleSelect.value='true';
    else if(item&&typeof item.checked==='boolean')ui.runtimeWebToggleSelect.value=item.checked?'false':'true';
  }
}
async function runtimeWebSelectV2210(index){
  await runtimeWebInvalidateProposalV2210();
  const item=runtimeWebElementsV2210[Number(index)];
  if(!item)return;
  runtimeWebSelectedV2210=item;
  renderRuntimeWebElementsV2210();
  ui.runtimeWebComposer.hidden=false;
  ui.runtimeWebSelectedLabel.textContent=runtimeWebElementLabelV2210(item);
  ui.runtimeWebSelectedMeta.textContent=runtimeWebElementMetaV2210(item);
  ui.runtimeWebActionSelect.replaceChildren();
  for(const action of runtimeWebActionsForElementV2210(item)){
    const opt=document.createElement('option');opt.value=String(action.key||'');opt.textContent=String(action.label||action.key||'Interaction')+' · '+String(action.risk_level||'low')+' risk';
    ui.runtimeWebActionSelect.append(opt);
  }
  ui.runtimeWebPreviewBtn.disabled=!ui.runtimeWebActionSelect.options.length||Boolean(item.disabled);
  ui.runtimeWebValueInput.value='';
  runtimeWebConfigureValueV2210();
}
function runtimeWebLocalValueV2210(){
  const action=String(ui.runtimeWebActionSelect.value||'');
  if(action==='type')return String(ui.runtimeWebValueInput.value||'').slice(0,4000);
  if(action==='select')return String(ui.runtimeWebOptionSelect.value??'');
  if(action==='toggle')return String(ui.runtimeWebToggleSelect.value||'true');
  return '';
}
function runtimeWebPreviewDetailV2210(action,item,value,proposal){
  const label=runtimeWebElementLabelV2210(item);
  if(action==='type')return 'Type “'+value.slice(0,120)+(value.length>120?'…':'')+'” into '+label+'. The value remains local in Chrome.';
  if(action==='clear')return 'Clear '+label+'.';
  if(action==='select'){
    const option=(item.options||[]).find(x=>String(x.value??'')===value);
    return 'Select “'+String(option&&option.label||value)+'” in '+label+'.';
  }
  if(action==='toggle')return (value==='true'?'Turn on / check ':'Turn off / uncheck ')+label+'.';
  if(action==='open_link')return 'Open '+label+' on '+String(item.target_host||'the current domain')+'.';
  return String(proposal&&proposal.label||action.replace(/_/g,' '))+' · '+label+'.';
}
async function previewRuntimeWebInteractionV2210(){
  if(runtimeWebBusyV2210||!runtimeDataV2200||!runtimeDataV2200.runtime_id||!runtimeWebObservationV2210||!runtimeWebSelectedV2210)return;
  await runtimeWebInvalidateProposalV2210();
  const action=String(ui.runtimeWebActionSelect.value||''),item=runtimeWebSelectedV2210,value=runtimeWebLocalValueV2210();
  if(!action)throw new Error('Choose an interaction first.');
  if(action==='type'&&!value)throw new Error('Enter the field value before previewing this interaction.');
  runtimeWebBusyV2210=true;busy(ui.runtimeWebPreviewBtn,true,'Previewing…');
  try{
    if(action==='handoff'){
      runtimeWebProposalV2210={proposal:{label:'Move to approved domain',requires_checkpoint:false,risk_level:'low'},action,item,value:''};
      ui.runtimeWebProposal.hidden=false;ui.runtimeWebCheckpointNotice.hidden=true;ui.runtimeWebRunBtn.hidden=false;ui.runtimeWebConfirmRunBtn.hidden=true;
      ui.runtimeWebProposalTitle.textContent='Ready · Move to approved domain';
      ui.runtimeWebProposalDetail.textContent='Move from '+String(runtimeWebObservationV2210.domain||'this site')+' to '+String(item.target_host||'the approved destination')+'. The destination URL stays local in Chrome.';
      note('Cross-domain handoff preview ready.','success');return;
    }
    const payload=await webInteractionRequestV2210('preview',{
      runtime_id:runtimeDataV2200.runtime_id,domain:runtimeWebObservationV2210.domain,
      page_fingerprint:runtimeWebObservationV2210.page_fingerprint,dom_fingerprint:runtimeWebObservationV2210.dom_fingerprint,
      action_key:action,element_fingerprint:item.element_fingerprint,
      target_host:action==='submit'?String(item.form_action_host||''):String(item.target_host||''),
      value_length:value.length,
      element:{
        kind:item.kind,tag:item.tag,input_type:item.input_type,role:item.role,label:item.label,name:item.name,
        placeholder:item.placeholder,aria_label:item.aria_label,autocomplete:item.autocomplete,
        form_action_host:item.form_action_host||'',submit_like:Boolean(item.submit_like),dangerous:Boolean(item.dangerous)
      }
    });
    const proposal=payload&&payload.proposal||null;if(!proposal)throw new Error('VP3 did not return an interaction preview.');
    runtimeWebProposalV2210={proposal,action,item,value};
    ui.runtimeWebProposal.hidden=false;
    ui.runtimeWebProposalTitle.textContent=(proposal.requires_checkpoint?'Checkpoint required · ':'Ready · ')+(proposal.label||action.replace(/_/g,' '));
    ui.runtimeWebProposalDetail.textContent=runtimeWebPreviewDetailV2210(action,item,value,proposal)+' '+String(proposal.risk_level||'low')+' risk.';
    ui.runtimeWebCheckpointNotice.hidden=!proposal.requires_checkpoint;
    ui.runtimeWebRunBtn.hidden=Boolean(proposal.requires_checkpoint);
    ui.runtimeWebConfirmRunBtn.hidden=!proposal.requires_checkpoint;
    note(proposal.requires_checkpoint?'Interaction preview ready. Confirm the checkpoint to run it.':'Interaction preview ready.','success');
  }finally{runtimeWebBusyV2210=false;busy(ui.runtimeWebPreviewBtn,false);}
}
async function loadRuntimeWebReceiptsV2210(){
  if(!runtimeDataV2200||!runtimeDataV2200.runtime_id)return;
  const payload=await webInteractionRequestV2210('list',{runtime_id:runtimeDataV2200.runtime_id});
  runtimeWebReceiptsV2210=Array.isArray(payload&&payload.interactions)?payload.interactions:[];
  ui.runtimeWebRecent.replaceChildren();
  for(const item of runtimeWebReceiptsV2210){
    const row=el('div','runtime-web-receipt '+String(item.status||''),'');
    const copy=el('div','runtime-web-receipt-copy','');
    copy.append(el('strong','',String(item.label||item.action_key||'Web interaction')),el('span','',String(item.status||'')+(item.result_code?' · '+String(item.result_code):'')+(item.created_at?' · '+date(item.created_at):'')));
    row.append(copy,el('span','pill',String(item.risk_level||'low')));
    ui.runtimeWebRecent.append(row);
  }
  ui.runtimeWebRecentEmpty.hidden=runtimeWebReceiptsV2210.length>0;
  const max=Number(payload&&payload.max_interactions||runtimeWebObservationV2210&&runtimeWebObservationV2210.authority&&runtimeWebObservationV2210.authority.max_interactions||0);
  const remaining=Number(payload&&payload.remaining_interactions||0);
  if(max>0)ui.runtimeWebInteractionCount.textContent=(max-remaining)+' / '+max+' interactions';
}
async function scanRuntimeWebControlsV2210(){
  if(runtimeWebBusyV2210||!runtimeDataV2200||!runtimeDataV2200.runtime_id)return;
  runtimeWebBusyV2210=true;busy(ui.runtimeWebScanBtn,true,'Scanning…');
  try{
    await runtimeWebInvalidateProposalV2210();
    runtimeWebSelectedV2210=null;ui.runtimeWebComposer.hidden=true;
    const observation=await msg('web_interaction_observe',{payload:{runtime_id:runtimeDataV2200.runtime_id,agent_id:agentWorkspaceAgentId}});
    runtimeWebObservationV2210=observation||null;
    runtimeWebElementsV2210=Array.isArray(observation&&observation.elements)?observation.elements:[];
    runtimeWebActionsV2210=Array.isArray(observation&&observation.actions)?observation.actions:[];
    renderRuntimeWebElementsV2210();
    const auth=observation&&observation.authority||{};
    const max=Number(auth.max_interactions||0),remaining=Number(auth.remaining_interactions||0);
    if(max>0)ui.runtimeWebInteractionCount.textContent=(max-remaining)+' / '+max+' interactions';
    ui.runtimeWebStatus.textContent=runtimeWebElementsV2210.length
      ?'Observed '+runtimeWebElementsV2210.length+' semantic controls on '+String(observation.domain||'this page')+'. Mutation epoch '+String(observation.mutation_epoch||0)+'.'
      :'No visible interactive controls were found on this page.';
    await loadRuntimeWebReceiptsV2210();
    await loadRuntimeDetailV2200();
  }finally{runtimeWebBusyV2210=false;busy(ui.runtimeWebScanBtn,false);}
}
async function executeRuntimeWebProposalV2210(confirmCheckpoint=false){
  const local=runtimeWebProposalV2210;
  if(!local||!local.proposal||!runtimeDataV2200||!runtimeDataV2200.runtime_id||!runtimeWebObservationV2210)return;
  if(Boolean(local.proposal.requires_checkpoint)!==Boolean(confirmCheckpoint)&&local.proposal.requires_checkpoint)throw new Error('Confirm this checkpoint before running the interaction.');
  runtimeWebBusyV2210=true;
  const button=confirmCheckpoint?ui.runtimeWebConfirmRunBtn:ui.runtimeWebRunBtn;busy(button,true,'Running…');
  try{
    if(local.action==='handoff'){
      const result=await runRuntimeMultiHandoffV2220(local.item&&local.item.target_url||'');
      runtimeWebResetProposalV2210();
      await scanRuntimeWebControlsV2210().catch(()=>{});
      return result;
    }
    if(local.proposal.requires_checkpoint){
      const confirmed=await webInteractionRequestV2210('confirm',{runtime_id:runtimeDataV2200.runtime_id,interaction_id:local.proposal.interaction_id});
      if(confirmed&&confirmed.proposal)local.proposal=confirmed.proposal;
    }
    const result=await msg('web_interaction_execute',{payload:{
      runtime_id:runtimeDataV2200.runtime_id,agent_id:agentWorkspaceAgentId,
      interaction_id:local.proposal.interaction_id,action_key:local.action,
      element_key:local.item.element_key,element_fingerprint:local.item.element_fingerprint,
      value:local.value
    }});
    const ok=Boolean(result&&result.outcome&&result.outcome.verified);
    note(ok?'Web interaction completed and verified.':'Web interaction ran but its expected state could not be verified. Rescan before retrying.',ok?'success':'error');
    runtimeWebResetProposalV2210();
    await loadRuntimeWebReceiptsV2210();
    await loadRuntimeDetailV2200();
    await scanRuntimeWebControlsV2210();
  }finally{runtimeWebBusyV2210=false;busy(button,false);}
}
async function cancelRuntimeWebProposalV2210(){
  await runtimeWebInvalidateProposalV2210();
  note('Web interaction preview cancelled.','success');
}
async function clearRuntimeWebSelectionV2210(){
  await runtimeWebInvalidateProposalV2210();runtimeWebSelectedV2210=null;ui.runtimeWebComposer.hidden=true;renderRuntimeWebElementsV2210();
}
async function runtimeWebElementClickV2210(event){
  const row=event.target.closest('[data-web-element-index]');if(!row)return;
  await runtimeWebSelectV2210(Number(row.dataset.webElementIndex||0));
}

function runtimeRequestV2200(action,payload={}){
  const request=Object.assign({},payload||{});
  if(agentWorkspaceAgentId>0)request.agent_id=agentWorkspaceAgentId;
  return msg('runtime_action',{action:action,payload:request});
}
function runtimeEventRowV2200(item){
  const row=el('div','runtime-event','');
  row.dataset.kind=String(item.event_type||'');
  row.append(el('strong','',String(item.event_type||'runtime event').replace(/_/g,' ')));
  if(item.summary)row.append(el('span','',String(item.summary)));
  const meta=[item.skill_key?String(item.skill_key).replace(/_/g,' '):'',item.created_at?date(item.created_at):''].filter(Boolean).join(' · ');
  if(meta)row.append(el('span','',meta));
  return row;
}
function runtimeTabRowV2200(item){
  const row=el('div','runtime-tab','');
  row.append(el('strong','',(item.role||'runtime')+' · '+String(item.target_type||'VP3 object').replace(/_/g,' ')));
  row.append(el('span','',(item.status||'open')+' · '+(item.target_id||'')+(item.last_seen_at?' · '+date(item.last_seen_at):'')));
  return row;
}
function renderRuntimeV2200(runtime){
  const previousRuntimeId=runtimeDataV2200&&runtimeDataV2200.runtime_id||'';
  runtimeDataV2200=runtime||null;
  if(!runtime){
    ui.runtimePanel.hidden=true;
    runtimeWebResetV2210();runtimeMultiResetV2220();runtimeResearchResetV2230();
    return;
  }
  if(previousRuntimeId&&previousRuntimeId!==String(runtime.runtime_id||'')){runtimeWebResetV2210();runtimeMultiResetV2220();runtimeResearchResetV2230();}
  ui.runtimePanel.hidden=false;
  ui.runtimeSessionBadge.textContent=(runtime.status||'ready')+' · '+String(runtime.runtime_id||'').slice(0,8);
  ui.runtimePlanRevision.textContent='r'+String(runtime.plan_revision||1);
  ui.runtimeCurrentSkill.textContent=runtime.current_skill_key?String(runtime.current_skill_key).replace(/_/g,' '):'Waiting';
  ui.runtimeRecovery.textContent=runtime.recovery_code?String(runtime.recovery_code).replace(/_/g,' '):'Clear';
  ui.runtimeObservationCount.textContent=String(runtime.observation_count||0);
  ui.runtimeLastVerified.textContent=runtime.last_verified_at?date(runtime.last_verified_at):'—';
  ui.runtimeTimeline.replaceChildren();
  (runtime.timeline||[]).slice(0,20).forEach(item=>ui.runtimeTimeline.append(runtimeEventRowV2200(item)));
  ui.runtimeTimelineEmpty.hidden=(runtime.timeline||[]).length>0;
  ui.runtimeTabs.replaceChildren();
  (runtime.tabs||[]).forEach(item=>ui.runtimeTabs.append(runtimeTabRowV2200(item)));
  ui.runtimeTabsEmpty.hidden=(runtime.tabs||[]).length>0;
  const terminal=['completed','cancelled','expired'].includes(String(runtime.status||''));
  ui.runtimeWebPanel.hidden=terminal;
  ui.runtimeMultiPanel.hidden=terminal;
  ui.runtimeResearchPanel.hidden=terminal;
  const current=runtime.current_step||null;
  ui.runtimeReplanBtn.disabled=terminal||Number(runtime.replan_count||0)>=Number(runtime.max_replans||0)||String(runtime.status||'')==='checkpoint';
  ui.runtimeSkipBtn.disabled=terminal||!current||!['queued','failed','approval_pending','executing'].includes(String(current.status||''));
}
async function attachRuntimeV2200(delegationId){
  if(!delegationId)return null;
  const payload=await runtimeRequestV2200('attach',{delegation_id:String(delegationId)});
  if(payload&&payload.agent){agentWorkspaceAgentId=Math.max(0,Number(payload.agent.id||agentWorkspaceAgentId||0));}
  renderRuntimeV2200(payload&&payload.runtime||null);
  await loadRuntimeMultiV2220().catch(()=>{});
  await loadRuntimeResearchV2230().catch(()=>{});
  return payload&&payload.runtime||null;
}
async function loadRuntimeDetailV2200(){
  if(!runtimeDataV2200||!runtimeDataV2200.runtime_id)return null;
  const payload=await runtimeRequestV2200('detail',{runtime_id:String(runtimeDataV2200.runtime_id)});
  renderRuntimeV2200(payload&&payload.runtime||null);
  return payload&&payload.runtime||null;
}
async function closeRuntimeOpenedTabsV2200(){
  if(!runtimeOpenedTabsV2200.length)return;
  const ids=[...new Set(runtimeOpenedTabsV2200)];
  if(runtimeDataV2200&&runtimeDataV2200.runtime_id){
    for(const id of ids){
      try{await runtimeRequestV2200('tab_closed',{runtime_id:runtimeDataV2200.runtime_id,tab_id:id});}catch(_error){}
    }
  }
  await msg('runtime_close_tabs',{tab_ids:ids}).catch(()=>{});
  runtimeOpenedTabsV2200=[];
}
async function runRuntimeV2200(){
  if(runtimeRunnerBusyV2200||!runtimeDataV2200||!runtimeDataV2200.runtime_id)return;
  if(!capture||!capture.available)throw new Error('Open the authorized Browser source before continuing this runtime.');
  runtimeRunnerBusyV2200=true;renderCaps();
  try{
    for(let guard=0;guard<VP3_RUNTIME_CLIENT_STEP_LIMIT_V2200;guard++){
      const runtimeId=String(runtimeDataV2200.runtime_id||'');
      const result=await runtimeRequestV2200('tick',{runtime_id:runtimeId,context:capture});
      if(result&&result.runtime)renderRuntimeV2200(result.runtime);
      if(result&&result.delegation)renderDelegationActiveV2190(result.delegation);
      if(result.state==='advanced'||result.state==='ready'){
        await refreshCapture(false);
        continue;
      }
      if(result.state==='navigate'){
        if(!result.navigation||!result.navigation.url||!result.step)throw new Error('Runtime navigation is incomplete.');
        const opened=await msg('runtime_navigate',{url:absolute(result.navigation.url)});
        const tabId=Number(opened&&opened.tab_id||0);
        if(tabId>0){
          runtimeOpenedTabsV2200.push(tabId);
          await runtimeRequestV2200('tab_seen',{
            runtime_id:runtimeId,tab_id:tabId,role:'runtime',
            target_type:String(result.navigation.target_type||result.step.target_type||''),
            target_id:String(result.navigation.target_id||result.step.target_id||'')
          });
        }
        await refreshCapture(false);
        const verified=await runtimeRequestV2200('verify_navigation',{
          runtime_id:runtimeId,action_id:Number(result.step.id||0),tab_id:tabId,context:capture
        });
        if(verified&&verified.runtime)renderRuntimeV2200(verified.runtime);
        if(verified&&verified.delegation)renderDelegationActiveV2190(verified.delegation);
        continue;
      }
      if(result.state==='checkpoint'){
        showDelegationCheckpointV2190(result);
        note('Browser Runtime reached a user checkpoint.','success');
        break;
      }
      if(result.state==='authority_required'){
        delegationCheckpointData=result||null;
        ui.delegationCheckpoint.hidden=false;
        ui.delegationCheckpointText.textContent=result.message||('This runtime step requires '+String(result.required_capability||'additional authority')+'.');
        ui.delegationCheckpointOpenBtn.hidden=true;
        ui.delegationCheckpointDoneBtn.hidden=true;
        note('Browser Runtime needs additional authority before it can continue.','error');
        break;
      }
      if(result.state==='recovering'||result.state==='failed'){
        note('Browser Runtime needs recovery. Retry, skip, or replan within the approved scope.','error');
        break;
      }
      if(result.state==='completed'){
        note('Browser Agent Runtime completed and verified the delegated job.','success');
        await closeRuntimeOpenedTabsV2200();
        await loadDelegationsV2190();
        break;
      }
      if(['paused','cancelled','expired'].includes(String(result.state||''))){
        if(result.state==='cancelled'||result.state==='expired')await closeRuntimeOpenedTabsV2200();
        break;
      }
      break;
    }
  }finally{
    runtimeRunnerBusyV2200=false;renderCaps();
  }
}
async function runtimeLifecycleV2200(action){
  if(!runtimeDataV2200||!runtimeDataV2200.runtime_id)return delegationLifecycleV2190(action);
  const payload=await runtimeRequestV2200(action,{runtime_id:runtimeDataV2200.runtime_id});
  if(payload&&payload.runtime)renderRuntimeV2200(payload.runtime);
  if(payload&&payload.delegation)renderDelegationActiveV2190(payload.delegation);
  await loadDelegationsV2190();
  if(action==='resume')await runRuntimeV2200();
  if(action==='cancel')await closeRuntimeOpenedTabsV2200();
}
async function runtimeReplanV2200(){
  if(!runtimeDataV2200||!runtimeDataV2200.runtime_id||!capture||!capture.available)return;
  busy(ui.runtimeReplanBtn,true,'Replanning…');
  try{
    const payload=await runtimeRequestV2200('replan',{runtime_id:runtimeDataV2200.runtime_id,context:capture});
    if(payload&&payload.runtime)renderRuntimeV2200(payload.runtime);
    note('Remaining runtime steps replanned inside the original authority.','success');
    await runRuntimeV2200();
  }finally{busy(ui.runtimeReplanBtn,false);}
}
async function runtimeSkipV2200(){
  if(!runtimeDataV2200||!runtimeDataV2200.runtime_id||!runtimeDataV2200.current_step)return;
  busy(ui.runtimeSkipBtn,true,'Skipping…');
  try{
    const payload=await runtimeRequestV2200('skip',{
      runtime_id:runtimeDataV2200.runtime_id,
      action_id:Number(runtimeDataV2200.current_step.id||0)
    });
    if(payload&&payload.runtime)renderRuntimeV2200(payload.runtime);
    if(payload&&payload.delegation)renderDelegationActiveV2190(payload.delegation);
    note('Runtime step skipped explicitly.','success');
    await runRuntimeV2200();
  }finally{busy(ui.runtimeSkipBtn,false);}
}
async function runtimeRetryV2200(actionId){
  if(!runtimeDataV2200||!runtimeDataV2200.runtime_id)return;
  const payload=await runtimeRequestV2200('retry',{runtime_id:runtimeDataV2200.runtime_id,action_id:Number(actionId||0)});
  if(payload&&payload.runtime)renderRuntimeV2200(payload.runtime);
  if(payload&&payload.delegation)renderDelegationActiveV2190(payload.delegation);
  await runRuntimeV2200();
}

function delegationRequestV2190(action,payload={}){
  const request=Object.assign({},payload||{});
  if(agentWorkspaceAgentId>0)request.agent_id=agentWorkspaceAgentId;
  return msg('delegation_action',{action:action,payload:request});
}
function delegationAllowedActionsV2190(){
  const out=[];
  document.querySelectorAll('[data-delegation-action]:checked').forEach(input=>{
    const action=String(input.dataset.delegationAction||'');
    if(action==='follow_source'){out.push('follow_source','unfollow_source');return;}
    if(action)out.push(action);
  });
  return [...new Set(out)];
}
function delegationConstraintsV2190(){
  const extra=String(ui.delegationDomains.value||'').split(',').map(x=>x.trim()).filter(Boolean);
  return {
    max_steps:Number(ui.delegationMaxSteps.value||5),
    expires_minutes:Number(ui.delegationExpiry.value||120),
    risk_budget:String(ui.delegationRisk.value||'low'),
    allowed_domains:extra,
    allowed_actions:delegationAllowedActionsV2190()
  };
}
function delegationInvalidatePreviewV2190(){
  delegationPreviewData=null;ui.delegationStartBtn.disabled=true;ui.delegationPlan.hidden=true;ui.delegationPlan.replaceChildren();
}
function delegationPlanStepV2190(step,index){
  const row=el('div','delegation-plan-step','');
  row.append(el('span','delegation-step-index',String(index+1)));
  const copy=el('div','delegation-step-copy','');
  copy.append(el('strong','',step.label||'Delegated step'));
  copy.append(el('span','',String(step.step_kind||'step').replace(/_/g,' ')+' · '+(step.requires_checkpoint?'checkpoint required':'bounded auto step')));
  row.append(copy,el('span','pill',String(step.risk_level||'low')+' risk'));
  return row;
}
function renderDelegationPlanV2190(plan){
  delegationPreviewData=plan||null;ui.delegationPlan.replaceChildren();
  if(!plan){ui.delegationPlan.hidden=true;ui.delegationStartBtn.disabled=true;return;}
  const head=el('div','delegation-step-copy','');
  head.append(el('strong','',(plan.steps||[]).length+'-step bounded plan'));
  const c=plan.constraints||{};
  head.append(el('span','',(c.allowed_domains||[]).join(', ')+' · max '+(c.max_steps||0)+' steps · expires '+(c.expires_minutes||0)+' min'));
  ui.delegationPlan.append(head);
  (plan.steps||[]).forEach((step,index)=>ui.delegationPlan.append(delegationPlanStepV2190(step,index)));
  ui.delegationPlan.hidden=false;ui.delegationStartBtn.disabled=false;
}
function delegationStepRowV2190(step){
  const row=el('div','delegation-step','');
  row.dataset.actionId=String(step.id||'');
  row.append(el('span','delegation-step-index',String(step.sequence_no||'')));
  const copy=el('div','delegation-step-copy','');
  copy.append(el('strong','',step.label||'Delegated step'));
  const detail=[String(step.status||'queued').replace(/_/g,' '),String(step.action_key||'').replace(/_/g,' ')];
  if(step.result_summary)detail.push(step.result_summary);
  copy.append(el('span','',detail.filter(Boolean).join(' · ')));row.append(copy);
  const side=el('div','delegation-controls','');
  side.append(el('span','pill',String(step.status||'queued').replace(/_/g,' ')));
  if(step.status==='failed'){
    const retry=act('Retry','delegation_retry');retry.dataset.actionId=String(step.id||'');side.append(retry);
  }
  row.append(side);return row;
}
function renderDelegationActiveV2190(item){
  delegationActiveData=item||null;delegationCheckpointData=null;
  if(!item){ui.delegationActive.hidden=true;return;}
  ui.delegationActive.hidden=false;
  ui.delegationActiveTitle.textContent=item.title||'Delegated Browser job';
  ui.delegationActiveMeta.textContent=String(item.status||'active').replace(/_/g,' ')+' · '+(item.completed_steps||0)+' / '+(item.total_steps||0)+' steps · expires '+date(item.expires_at);
  ui.delegationProgress.textContent=String(item.progress_percent||0)+'%';
  ui.delegationSteps.replaceChildren();(item.steps||[]).forEach(step=>ui.delegationSteps.append(delegationStepRowV2190(step)));
  const paused=item.status==='paused',terminal=['completed','cancelled','expired'].includes(String(item.status||''));
  ui.delegationPauseBtn.hidden=paused||terminal;ui.delegationResumeBtn.hidden=!paused||terminal;
  ui.delegationCancelBtn.hidden=terminal;ui.delegationRunBtn.hidden=paused||terminal||item.status==='checkpoint';
  ui.delegationOpenWorkflowBtn.hidden=!item.agent_workflow_url;
  ui.delegationStatus.textContent=(terminal?'Delegation '+item.status:'Bounded delegation · '+(item.progress_percent||0)+'% complete');
  if(item.status!=='checkpoint')ui.delegationCheckpoint.hidden=true;
}
function renderDelegationRecentV2190(items){
  delegationRecentData=Array.isArray(items)?items:[];
  ui.delegationRecent.replaceChildren();
  delegationRecentData.forEach(item=>{
    const row=el('div','delegation-recent-row','');
    const copy=el('div','delegation-recent-copy','');
    copy.append(el('strong','',item.title||'Delegated Browser job'));
    copy.append(el('span','',String(item.status||'').replace(/_/g,' ')+' · '+(item.completed_steps||0)+'/'+(item.total_steps||0)+' · '+date(item.updated_at)));
    const open=act('Open','delegation_open');open.dataset.delegationId=String(item.delegation_id||'');row.append(copy,open);ui.delegationRecent.append(row);
  });
  ui.delegationRecentEmpty.hidden=delegationRecentData.length>0;
}
async function loadDelegationsV2190(){
  if(delegationBusy||!state||!state.connected||!caps().has('agent.message'))return;
  delegationBusy=true;ui.delegationStatus.textContent='Loading delegated Browser jobs…';
  try{
    const payload=await delegationRequestV2190('list',{});
    if(payload&&payload.agent){agentWorkspaceAgentId=Math.max(0,Number(payload.agent.id||agentWorkspaceAgentId||0));ui.delegationAgentName.textContent=String(payload.agent.name||'VP3 Agent');}
    renderDelegationRecentV2190(payload&&payload.delegations||[]);
    if(!delegationActiveData){
      const recover=(payload&&payload.delegations||[]).find(x=>['active','checkpoint','paused'].includes(String(x.status||'')));
      if(recover)await loadDelegationDetailV2190(recover.delegation_id);
      else ui.delegationStatus.textContent='Ready for a bounded delegated job.';
    }else ui.delegationStatus.textContent='Bounded delegation · '+(delegationActiveData.progress_percent||0)+'% complete';
  }catch(e){ui.delegationStatus.textContent=e.message||'Delegated Browser Workflows are unavailable.';if(e.code==='capability_denied')dropCapability('agent.message');throw e;}
  finally{delegationBusy=false;renderCaps();}
}
async function loadDelegationDetailV2190(id){
  const payload=await delegationRequestV2190('detail',{delegation_id:String(id||'')});
  renderDelegationActiveV2190(payload&&payload.delegation||null);
  try{await attachRuntimeV2200(String(id||''));}catch(e){ui.delegationStatus.textContent='Delegation loaded · Runtime upgrade may be required';}
  return payload&&payload.delegation||null;
}
async function previewDelegationV2190(){
  if(!capture||!capture.available)throw new Error('Open an authorized VP3-connected web page before creating a delegation.');
  const instruction=String(ui.delegationInstruction.value||'').trim();
  if(instruction.length<3)throw new Error('Describe the Browser job you want the Agent to complete.');
  busy(ui.delegationPreviewBtn,true,'Planning…');
  try{
    const payload=await delegationRequestV2190('preview',{instruction:instruction,constraints:delegationConstraintsV2190(),context:capture});
    renderDelegationPlanV2190(payload&&payload.plan||null);
    note('Bounded plan ready. Review it before starting.','success');
  }finally{busy(ui.delegationPreviewBtn,false);}
}
async function startDelegationV2190(){
  if(!delegationPreviewData||!capture||!capture.available)throw new Error('Preview the delegation plan again before starting.');
  busy(ui.delegationStartBtn,true,'Starting…');
  try{
    const payload=await delegationRequestV2190('create',{
      instruction:String(ui.delegationInstruction.value||'').trim(),
      constraints:delegationConstraintsV2190(),
      context:capture,
      plan_hash:String(delegationPreviewData.plan_hash||'')
    });
    renderDelegationActiveV2190(payload&&payload.delegation||null);
    renderDelegationRecentV2190(payload&&payload.delegations||[]);
    delegationInvalidatePreviewV2190();
    await attachRuntimeV2200(payload&&payload.delegation&&payload.delegation.delegation_id||'');
    note('Runtime attached. Running bounded Browser skills.','success');
    await runRuntimeV2200();
  }finally{busy(ui.delegationStartBtn,false);}
}
function showDelegationCheckpointV2190(result){
  delegationCheckpointData=result||null;
  const step=result&&result.step||{};
  ui.delegationCheckpoint.hidden=false;
  ui.delegationCheckpointOpenBtn.hidden=false;
  ui.delegationCheckpointDoneBtn.hidden=false;
  ui.delegationCheckpointText.textContent=(step.label||'This step')+' requires your explicit completion before the delegation can continue.';
  ui.delegationRunBtn.hidden=true;
}
async function openDelegationCheckpointV2190(){
  const step=delegationCheckpointData&&delegationCheckpointData.step||null;
  if(step&&String(step.action_key||'')==='multisite_handoff'){
    if(!runtimeMultiStateV2220)await attachRuntimeMultiV2220();
    ui.runtimeMultiPanel.scrollIntoView({behavior:'smooth',block:'start'});
    note('Multi-site checkpoint opened. Complete the approved-domain work, then mark this step done.','success');
    return;
  }
  if(step&&String(step.action_key||'')==='browser_research'){
    if(!runtimeMultiStateV2220)await attachRuntimeMultiV2220();
    await loadRuntimeResearchV2230();
    ui.runtimeResearchPanel.scrollIntoView({behavior:'smooth',block:'start'});
    note('Browser Research checkpoint opened. Run or review the mission, then mark this step done.','success');
    return;
  }
  const handoff=delegationCheckpointData&&delegationCheckpointData.handoff||null;if(!handoff)return;
  if(handoff.kind==='agent_prompt'){
    setView('agent');ui.agentMessageInput.value=String(handoff.prompt||'');renderCaps();ui.agentMessageInput.focus();
    note('Checkpoint prepared in Agent. Review and send when ready.','success');return;
  }
  if(handoff.kind==='manual_flow'){
    setView('this_page');note('Checkpoint handed to the existing Team-share flow. Publish explicitly when ready.','success');return;
  }
  if(handoff.kind==='open'&&handoff.url){await msg('open_url',{url:absolute(handoff.url)});return;}
}
async function runDelegationV2190(){
  if(delegationRunnerBusy||!delegationActiveData||!delegationActiveData.delegation_id)return;
  delegationRunnerBusy=true;renderCaps();
  try{
    for(let guard=0;guard<VP3_DELEGATION_CLIENT_STEP_LIMIT_V2190;guard++){
      const id=String(delegationActiveData.delegation_id||'');
      const result=await delegationRequestV2190('next',{delegation_id:id});
      if(result&&result.delegation)renderDelegationActiveV2190(result.delegation);
      if(result.state==='advanced')continue;
      if(result.state==='navigate'){
        if(!result.navigation||!result.navigation.url||!result.step)throw new Error('Delegated navigation is incomplete.');
        await msg('delegation_navigate',{url:absolute(result.navigation.url)});
        await refreshCapture(false);
        const verified=await delegationRequestV2190('verify_navigation',{
          delegation_id:id,action_id:Number(result.step.id||0),context:capture
        });
        if(verified&&verified.delegation)renderDelegationActiveV2190(verified.delegation);
        continue;
      }
      if(result.state==='checkpoint'){showDelegationCheckpointV2190(result);note('Delegation reached a user checkpoint.','success');break;}
      if(result.state==='completed'){note('Delegated Browser job completed and verified.','success');await loadDelegationsV2190();break;}
      if(result.state==='paused'||result.state==='cancelled'||result.state==='expired'){break;}
      if(result.state==='failed'){note('A delegated step failed. Review the step and retry if appropriate.','error');break;}
      break;
    }
  }finally{delegationRunnerBusy=false;renderCaps();}
}
async function delegationLifecycleV2190(action){
  if(!delegationActiveData)return;
  const payload=await delegationRequestV2190(action,{delegation_id:delegationActiveData.delegation_id});
  renderDelegationActiveV2190(payload&&payload.delegation||null);
  await loadDelegationsV2190();
  if(action==='resume')await runDelegationV2190();
}
async function delegationClickV2190(e){
  const b=e.target.closest('button[data-action]');if(!b)return;
  if(b.dataset.action==='delegation_open'){
    await loadDelegationDetailV2190(String(b.dataset.delegationId||''));return;
  }
  if(b.dataset.action==='delegation_retry'){
    if(runtimeDataV2200&&runtimeDataV2200.runtime_id)return runtimeRetryV2200(Number(b.dataset.actionId||0));
    if(!delegationActiveData)return;
    const payload=await delegationRequestV2190('retry',{delegation_id:delegationActiveData.delegation_id,action_id:Number(b.dataset.actionId||0)});
    renderDelegationActiveV2190(payload&&payload.delegation||null);await runDelegationV2190();
  }
}

function executionRequestV2180(action,payload={}){
  const request=Object.assign({},payload||{});
  if(agentWorkspaceAgentId>0)request.agent_id=agentWorkspaceAgentId;
  return msg('execution_action',{action:action,payload:request});
}
function executionCandidateRowV2180(item){
  const row=el('div','execution-row candidate','');
  row.dataset.actionKey=String(item.action_key||'');
  row.dataset.targetType=String(item.target_type||'');
  row.dataset.targetId=String(item.target_id||'');
  const copy=el('div','execution-row-copy','');
  copy.append(
    el('small','',String(item.target_type||'VP3 object').replace(/_/g,' ')),
    el('strong','',item.label||'Browser action')
  );
  if(item.detail)copy.append(el('span','',item.detail));
  const status=el('div','execution-status-line','');
  status.append(el('span','pill',String(item.risk_level||'low')+' risk'));
  status.append(el('span','pill',item.requires_confirmation?'confirmation required':'no extra confirmation'));
  copy.append(status);
  const actions=el('div','execution-row-actions','');
  actions.append(act(item.requires_confirmation?'Prepare':'Prepare to run','execution_propose'));
  row.append(copy,actions);
  return row;
}
function executionTicketRowV2180(item){
  const status=String(item.status||'proposed');
  const row=el('div','execution-row ticket '+status,'');
  row.dataset.ticketId=String(item.ticket_id||'');
  const copy=el('div','execution-row-copy','');
  copy.append(
    el('small','',String(item.target_type||'VP3 object').replace(/_/g,' ')),
    el('strong','',item.label||String(item.action_key||'Browser action').replace(/_/g,' '))
  );
  const line=el('div','execution-status-line','');
  line.append(el('span','pill',status.replace(/_/g,' ')));
  line.append(el('span','pill',String(item.verification_state||'waiting').replace(/_/g,' ')));
  copy.append(line);
  if(item.updated_at)copy.append(el('span','',date(item.updated_at)));
  const actions=el('div','execution-row-actions','');
  if(status==='proposed'){
    actions.append(act('Confirm','execution_confirm'),act('Cancel','execution_cancel'));
  }else if(status==='confirmed'){
    actions.append(act('Execute','execution_execute'),act('Cancel','execution_cancel'));
  }else if(status==='awaiting_user'){
    actions.append(act('Mark done','execution_complete'),act('Cancel','execution_cancel'));
  }
  row.append(copy,actions);
  return row;
}
function executionContinuityRowV2180(item){
  const row=el('div','execution-row continuity','');
  const copy=el('div','execution-row-copy','');
  copy.append(
    el('small','','cognitive plan'),
    el('strong','',String(item.status||'active').replace(/_/g,' ')+' · '+String(item.current_step_key||'next step').replace(/_/g,' '))
  );
  copy.append(el('span','',(Number(item.completed_steps||0))+' of '+(Number(item.total_steps||0))+' steps complete · '+String(item.verification_state||'waiting').replace(/_/g,' ')));
  if(item.updated_at)copy.append(el('span','',date(item.updated_at)));
  row.append(copy);
  return row;
}
function renderExecutionV2180(payload){
  const agent=payload&&payload.agent||null;
  if(agent){
    agentWorkspaceAgentId=Math.max(0,Number(agent.id||agentWorkspaceAgentId||0));
    ui.executionAgentName.textContent=String(agent.name||'VP3 Agent');
  }
  executionCandidatesData=Array.isArray(payload&&payload.candidates)?payload.candidates:[];
  executionTicketsData=Array.isArray(payload&&payload.tickets)?payload.tickets:[];
  executionContinuityData=Array.isArray(payload&&payload.continuity)?payload.continuity:[];

  ui.executionCandidates.replaceChildren();
  executionCandidatesData.forEach(item=>ui.executionCandidates.append(executionCandidateRowV2180(item)));
  ui.executionCandidatesEmpty.hidden=executionCandidatesData.length>0;

  ui.executionTickets.replaceChildren();
  executionTicketsData.forEach(item=>ui.executionTickets.append(executionTicketRowV2180(item)));
  ui.executionTicketsEmpty.hidden=executionTicketsData.length>0;

  ui.executionContinuity.replaceChildren();
  executionContinuityData.forEach(item=>ui.executionContinuity.append(executionContinuityRowV2180(item)));
  ui.executionContinuityEmpty.hidden=executionContinuityData.length>0;
  ui.executionStatus.textContent='Propose → Confirm → Execute → Verify · '+executionTicketsData.length+' recent';
}
async function loadExecutionV2180(){
  if(executionBusy||!state||!state.connected||!caps().has('agent.message'))return;
  executionBusy=true;ui.executionStatus.textContent='Checking authorized actions…';
  try{
    const payload=capture&&capture.available
      ?await executionRequestV2180('candidates',{context:capture})
      :await executionRequestV2180('list',{});
    renderExecutionV2180(payload);
  }catch(e){
    ui.executionStatus.textContent=e.message||'Browser Execution is unavailable.';
    if(e.code==='capability_denied')dropCapability('agent.message');
    throw e;
  }finally{executionBusy=false;renderCaps();}
}
async function executionClickV2180(e){
  const b=e.target.closest('button[data-action]');if(!b)return;
  const row=b.closest('.execution-row');if(!row)return;
  try{
    if(b.dataset.action==='execution_propose'){
      if(!capture||!capture.available)throw new Error('The current page changed. Refresh Browser Execution before preparing this action.');
      busy(b,true,'Preparing…');
      const payload=await executionRequestV2180('propose',{
        action_key:String(row.dataset.actionKey||''),
        target_type:String(row.dataset.targetType||''),
        target_id:String(row.dataset.targetId||''),
        context:capture
      });
      renderExecutionV2180(payload);
      note('Action prepared. Review and confirm before execution.','success');
      return;
    }
    const ticketId=String(row.dataset.ticketId||'');if(!ticketId)return;
    if(b.dataset.action==='execution_confirm'){
      busy(b,true,'Confirming…');
      const payload=await executionRequestV2180('confirm',{ticket_id:ticketId});
      renderExecutionV2180(payload);note('Action confirmed. It is ready to execute.','success');return;
    }
    if(b.dataset.action==='execution_execute'){
      if(!capture||!capture.available)throw new Error('The current page changed. Refresh Browser Execution before executing this action.');
      busy(b,true,'Executing…');
      const payload=await executionRequestV2180('execute',{ticket_id:ticketId,context:capture});
      renderExecutionV2180(payload);
      const handoff=payload&&payload.handoff||null;
      if(handoff&&handoff.kind==='agent_prompt'){
        setView('agent');ui.agentMessageInput.value=String(handoff.prompt||'');renderCaps();ui.agentMessageInput.focus();
        note('Prepared in Agent. Review the prompt and send it when ready.','success');
        return;
      }
      if(handoff&&handoff.kind==='manual_flow'){
        setView('this_page');note('Execution handed off to the existing Team-share flow. Choose the destination and publish when ready.','success');
        return;
      }
      if(handoff&&handoff.kind==='open'&&handoff.url){
        await msg('open_url',{url:absolute(handoff.url)});note('Authorized VP3 context opened.','success');return;
      }
      note('Action executed and verified by VP3.','success');return;
    }
    if(b.dataset.action==='execution_complete'){
      busy(b,true,'Verifying…');
      const payload=await executionRequestV2180('complete',{ticket_id:ticketId});
      renderExecutionV2180(payload);note('Follow-through marked complete.','success');return;
    }
    if(b.dataset.action==='execution_cancel'){
      busy(b,true,'Cancelling…');
      const payload=await executionRequestV2180('cancel',{ticket_id:ticketId});
      renderExecutionV2180(payload);note('Action cancelled.','success');return;
    }
  }catch(err){await fail(err);}
  finally{if(b.isConnected)busy(b,false);}
}

function memoryRequestV2170(action,payload={}){
  const request=Object.assign({},payload||{});
  if(agentWorkspaceAgentId>0)request.agent_id=agentWorkspaceAgentId;
  return msg('memory_action',{action:action,payload:request});
}
function memoryRowV2170(item,source){
  const row=el('div','memory-row'+(item.approved?' remembered':''),'');
  row.dataset.targetType=String(item.target_type||'');
  row.dataset.targetId=String(item.target_id||'');
  row.dataset.approvalId=String(item.approval_id||'');
  const copy=el('div','memory-row-copy','');
  copy.append(
    el('small','',String(item.target_type||'VP3 object').replace(/_/g,' ')),
    el('strong','',item.title||'VP3 object')
  );
  if(item.detail)copy.append(el('span','',item.detail));
  if(item.approved_at)copy.append(el('span','', 'Approved '+date(item.approved_at)));
  const actions=el('div','memory-row-actions','');
  if(item.url){const open=act('Open','memory_open');open.dataset.url=String(item.url);actions.append(open);}
  if(item.approved){
    const forget=act('Forget','memory_forget');actions.append(forget);
  }else if(source==='candidate'){
    const remember=act('Remember','memory_remember');actions.append(remember);
  }
  row.append(copy,actions);
  return row;
}
function renderMemoryV2170(payload){
  const agent=payload&&payload.agent||null;
  if(agent){
    agentWorkspaceAgentId=Math.max(0,Number(agent.id||agentWorkspaceAgentId||0));
    ui.memoryAgentName.textContent=String(agent.name||'VP3 Agent');
  }
  memoryCandidatesData=Array.isArray(payload&&payload.candidates)?payload.candidates:[];
  memoryRememberedData=Array.isArray(payload&&payload.remembered)?payload.remembered:[];
  ui.memoryCandidates.replaceChildren();
  memoryCandidatesData.forEach(item=>ui.memoryCandidates.append(memoryRowV2170(item,'candidate')));
  ui.memoryCandidatesEmpty.hidden=memoryCandidatesData.length>0;
  ui.memoryRemembered.replaceChildren();
  memoryRememberedData.forEach(item=>ui.memoryRemembered.append(memoryRowV2170(item,'remembered')));
  ui.memoryRememberedEmpty.hidden=memoryRememberedData.length>0;
  ui.memoryCount.textContent=memoryRememberedData.length+' remembered';
  ui.memoryStatus.textContent='Reference-only memory · '+memoryRememberedData.length+' approved';
}
async function loadMemoryV2170(){
  if(memoryBusy||!state||!state.connected||!caps().has('agent.message'))return;
  memoryBusy=true;ui.memoryStatus.textContent='Refreshing approved VP3 references…';
  try{
    const payload=capture&&capture.available
      ?await memoryRequestV2170('candidates',{context:capture})
      :await memoryRequestV2170('list',{});
    renderMemoryV2170(payload);
  }catch(e){
    ui.memoryStatus.textContent=e.message||'Browser Memory is unavailable.';
    if(e.code==='capability_denied')dropCapability('agent.message');
    throw e;
  }finally{memoryBusy=false;renderCaps();}
}
async function memoryClickV2170(e){
  const b=e.target.closest('button[data-action]');if(!b)return;
  const row=b.closest('.memory-row');if(!row)return;
  try{
    if(b.dataset.action==='memory_open'){
      const path=String(b.dataset.url||'');if(path)await msg('open_url',{url:absolute(path)});
      return;
    }
    if(b.dataset.action==='memory_remember'){
      busy(b,true,'Remembering…');
      if(!capture||!capture.available)throw new Error('The current page changed. Refresh Browser Memory before remembering this item.');
      await memoryRequestV2170('approve',{
        target_type:String(row.dataset.targetType||''),
        target_id:String(row.dataset.targetId||''),
        context:capture
      });
      note('Approved for VP3 Agent Memory.','success');await loadMemoryV2170();return;
    }
    if(b.dataset.action==='memory_forget'){
      const approvalId=String(row.dataset.approvalId||'');if(!approvalId)return;
      busy(b,true,'Forgetting…');
      await memoryRequestV2170('revoke',{approval_id:approvalId});
      note('Browser Memory approval revoked.','success');await loadMemoryV2170();return;
    }
  }catch(err){await fail(err);}
  finally{if(b.isConnected)busy(b,false);}
}

function setView(v){
  activeView=v;const now=v==='now',agent=v==='agent',delegation=v==='delegation',execution=v==='execution',memory=v==='memory',page=v==='this_page',following=v==='following',live=v==='live',alerts=v==='alerts',search=v==='search';
  ui.nowView.hidden=!now;ui.agentView.hidden=!agent;ui.delegationView.hidden=!delegation;ui.executionView.hidden=!execution;ui.memoryView.hidden=!memory;ui.thisPageView.hidden=!page;ui.followingView.hidden=!following;ui.liveView.hidden=!live;ui.alertsView.hidden=!alerts;ui.searchView.hidden=!search;
  ui.nowTab.classList.toggle('active',now);ui.agentTab.classList.toggle('active',agent);ui.delegationTab.classList.toggle('active',delegation);ui.executionTab.classList.toggle('active',execution);ui.memoryTab.classList.toggle('active',memory);ui.thisPageTab.classList.toggle('active',page);ui.followingTab.classList.toggle('active',following);ui.liveTab.classList.toggle('active',live);ui.alertsTab.classList.toggle('active',alerts);ui.searchTab.classList.toggle('active',search);
  ui.nowTab.setAttribute('aria-selected',String(now));ui.agentTab.setAttribute('aria-selected',String(agent));ui.delegationTab.setAttribute('aria-selected',String(delegation));ui.executionTab.setAttribute('aria-selected',String(execution));ui.memoryTab.setAttribute('aria-selected',String(memory));ui.thisPageTab.setAttribute('aria-selected',String(page));ui.followingTab.setAttribute('aria-selected',String(following));ui.liveTab.setAttribute('aria-selected',String(live));ui.alertsTab.setAttribute('aria-selected',String(alerts));ui.searchTab.setAttribute('aria-selected',String(search));
  if(!now){clearInterval(cognitiveTimer);cognitiveTimer=null;}if(now)loadNow(true).catch(fail);
  if(!agent){clearInterval(agentPollTimer);agentPollTimer=null;}if(agent)refreshAgentWorkspaceV2160().catch(fail);
  if(delegation)loadDelegationsV2190().catch(fail);
  if(execution)loadExecutionV2180().catch(fail);
  if(memory)loadMemoryV2170().catch(fail);
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
    if(['now','agent','delegation','execution','memory'].includes(activeView)&&!c.has('agent.message')){
      if(c.has('team.chat.read')||c.has('team.share.create'))setView('this_page');
      else if(c.has('notifications.read'))setView('alerts');
    }
    if(c.has('team.destinations.read'))await loadDestinations();else{destinations={recent:[],teams:[],conversations:[]};renderAccountTeams();}
    if(c.has('team.chat.read')&&activeView==='this_page')await loadThis(true);
    if(pendingQuick)await applyPendingQuickActionV2150(pendingQuick);
    else if(c.has('agent.message')&&activeView==='now')await loadNow(true);
    else if(c.has('agent.message')&&activeView==='agent')await refreshAgentWorkspaceV2160();
    else if(c.has('agent.message')&&activeView==='delegation')await loadDelegationsV2190();
    else if(c.has('agent.message')&&activeView==='execution')await loadExecutionV2180();
    else if(c.has('agent.message')&&activeView==='memory')await loadMemoryV2170();
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
ui.nowTab.onclick=()=>setView('now');ui.agentTab.onclick=()=>setView('agent');ui.delegationTab.onclick=()=>setView('delegation');ui.executionTab.onclick=()=>setView('execution');ui.memoryTab.onclick=()=>setView('memory');ui.thisPageTab.onclick=()=>setView('this_page');ui.followingTab.onclick=()=>setView('following');ui.liveTab.onclick=()=>setView('live');ui.alertsTab.onclick=()=>setView('alerts');ui.searchTab.onclick=()=>setView('search');ui.refreshCaptureBtn.onclick=()=>refreshCapture(true).catch(fail);ui.refreshFollowingBtn.onclick=()=>loadFollowing(true).catch(fail);ui.refreshLiveBtn.onclick=()=>loadLiveRooms().catch(fail);ui.refreshAlertsBtn.onclick=()=>loadAlerts().catch(fail);
ui.agentRefreshBtn.onclick=()=>refreshAgentWorkspaceV2160().catch(fail);
ui.delegationRefreshBtn.onclick=()=>loadDelegationsV2190().catch(fail);
ui.delegationPreviewBtn.onclick=()=>previewDelegationV2190().catch(fail);
ui.delegationStartBtn.onclick=()=>startDelegationV2190().catch(fail);
ui.delegationRunBtn.onclick=()=>((runtimeDataV2200&&runtimeDataV2200.runtime_id)?runRuntimeV2200():runDelegationV2190()).catch(fail);
ui.delegationPauseBtn.onclick=()=>runtimeLifecycleV2200('pause').catch(fail);
ui.delegationResumeBtn.onclick=()=>runtimeLifecycleV2200('resume').catch(fail);
ui.delegationCancelBtn.onclick=()=>runtimeLifecycleV2200('cancel').catch(fail);
ui.delegationOpenWorkflowBtn.onclick=()=>{if(delegationActiveData&&delegationActiveData.agent_workflow_url)msg('open_url',{url:absolute(delegationActiveData.agent_workflow_url)}).catch(fail);};
ui.delegationCheckpointOpenBtn.onclick=()=>openDelegationCheckpointV2190().catch(fail);
ui.delegationCheckpointDoneBtn.onclick=async()=>{try{if(!delegationActiveData||!delegationCheckpointData||!delegationCheckpointData.step)return;if(runtimeDataV2200&&runtimeDataV2200.runtime_id){const payload=await runtimeRequestV2200('complete_checkpoint',{runtime_id:runtimeDataV2200.runtime_id,action_id:Number(delegationCheckpointData.step.id||0)});if(payload&&payload.runtime)renderRuntimeV2200(payload.runtime);if(payload&&payload.delegation)renderDelegationActiveV2190(payload.delegation);ui.delegationCheckpoint.hidden=true;delegationCheckpointData=null;await runRuntimeV2200();return;}const payload=await delegationRequestV2190('complete_checkpoint',{delegation_id:delegationActiveData.delegation_id,action_id:Number(delegationCheckpointData.step.id||0)});renderDelegationActiveV2190(payload&&payload.delegation||null);ui.delegationCheckpoint.hidden=true;delegationCheckpointData=null;await runDelegationV2190();}catch(e){await fail(e);}};
ui.runtimeReplanBtn.onclick=()=>runtimeReplanV2200().catch(fail);
ui.runtimeSkipBtn.onclick=()=>runtimeSkipV2200().catch(fail);
ui.runtimeResearchRefreshBtn.onclick=()=>loadRuntimeResearchV2230().catch(fail);
ui.runtimeResearchStartBtn.onclick=()=>startRuntimeResearchV2230().catch(fail);
ui.runtimeResearchAnalyzeBtn.onclick=()=>analyzeRuntimeResearchPageV2230().catch(fail);
ui.runtimeResearchSaveBtn.onclick=()=>saveRuntimeResearchV2230().catch(fail);
ui.runtimeResearchCancelBtn.onclick=()=>cancelRuntimeResearchV2230().catch(fail);
ui.runtimeResearchOpenChatBtn.onclick=()=>openRuntimeResearchChatV2230().catch(fail);
ui.runtimeResearchOpenReportBtn.onclick=()=>openRuntimeResearchReportV2230().catch(fail);
ui.runtimeResearchKnowledgeBtn.onclick=()=>draftRuntimeResearchHandoffV2230('knowledge');
ui.runtimeResearchCrmBtn.onclick=()=>draftRuntimeResearchHandoffV2230('crm');
ui.runtimeResearchTaskBtn.onclick=()=>draftRuntimeResearchHandoffV2230('task');
ui.runtimeMultiAttachBtn.onclick=()=>attachRuntimeMultiV2220().catch(fail);
ui.runtimeMultiGoBtn.onclick=()=>runRuntimeMultiHandoffV2220(String(ui.runtimeMultiTargetUrl.value||'')).catch(fail);
ui.runtimeMultiFactAddBtn.onclick=()=>addRuntimeMultiFactV2220().catch(fail);
ui.runtimeMultiDomains.onchange=e=>runtimeMultiDomainChangeV2220(e).catch(fail);
ui.runtimeWebScanBtn.onclick=()=>scanRuntimeWebControlsV2210().catch(fail);
ui.runtimeWebElements.onclick=e=>runtimeWebElementClickV2210(e).catch(fail);
ui.runtimeWebActionSelect.onchange=()=>{runtimeWebInvalidateProposalV2210().catch(()=>{});runtimeWebConfigureValueV2210();};
ui.runtimeWebValueInput.oninput=()=>runtimeWebInvalidateProposalV2210().catch(()=>{});
ui.runtimeWebOptionSelect.onchange=()=>runtimeWebInvalidateProposalV2210().catch(()=>{});
ui.runtimeWebToggleSelect.onchange=()=>runtimeWebInvalidateProposalV2210().catch(()=>{});
ui.runtimeWebPreviewBtn.onclick=()=>previewRuntimeWebInteractionV2210().catch(fail);
ui.runtimeWebRunBtn.onclick=()=>executeRuntimeWebProposalV2210(false).catch(fail);
ui.runtimeWebConfirmRunBtn.onclick=()=>executeRuntimeWebProposalV2210(true).catch(fail);
ui.runtimeWebCancelBtn.onclick=()=>cancelRuntimeWebProposalV2210().catch(fail);
ui.runtimeWebClearSelectionBtn.onclick=()=>clearRuntimeWebSelectionV2210().catch(fail);
ui.delegationRecent.onclick=e=>delegationClickV2190(e).catch(fail);
ui.delegationSteps.onclick=e=>delegationClickV2190(e).catch(fail);
ui.delegationInstruction.oninput=delegationInvalidatePreviewV2190;
[ui.delegationMaxSteps,ui.delegationExpiry,ui.delegationRisk,ui.delegationDomains].forEach(x=>x.onchange=delegationInvalidatePreviewV2190);
document.querySelectorAll('[data-delegation-action]').forEach(x=>x.onchange=delegationInvalidatePreviewV2190);
ui.executionRefreshBtn.onclick=()=>loadExecutionV2180().catch(fail);ui.executionCandidates.onclick=executionClickV2180;ui.executionTickets.onclick=executionClickV2180;
ui.memoryRefreshBtn.onclick=()=>loadMemoryV2170().catch(fail);ui.memoryCandidates.onclick=memoryClickV2170;ui.memoryRemembered.onclick=memoryClickV2170;
ui.agentConversationSelect.onchange=()=>loadAgentConversationV2160(Number(ui.agentConversationSelect.value||0)).catch(fail);
ui.agentNewChatBtn.onclick=()=>loadAgentConversationV2160(0).catch(fail);
ui.agentOpenFullBtn.onclick=()=>{
  const params=new URLSearchParams();
  params.set('agent',agentWorkspaceAgentId>0?String(agentWorkspaceAgentId):'system');
  if(agentConversationId>0)params.set('conversation_id',String(agentConversationId));
  msg('open_url',{url:absolute('/chat.php?'+params.toString())});
};
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
ui.quickMemoryBtn.onclick=()=>setView('memory');
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
function pageWatch(){clearInterval(pageTimer);let identity=(capture&&capture.source_url||'')+'\n'+(capture&&capture.title||'');pageTimer=setInterval(async()=>{if(!state||!state.connected||!['now','agent','delegation','execution','memory','this_page','live','alerts','search'].includes(activeView))return;try{const x=await msg('tab_identity'),next=(x.source_url||'')+'\n'+(x.title||'');if(x.source_url&&next!==identity){identity=next;nowContextIgnored=false;await refreshCapture(activeView==='this_page');if(activeView==='now')await loadNow(true);if(activeView==='agent'&&ui.agentUsePageContext.checked)ui.agentContextLabel.textContent='Temporary context: '+(capture.title||host(capture.source_url)||'current page');if(activeView==='execution')await loadExecutionV2180();if(activeView==='memory')await loadMemoryV2170();if(activeView==='live'){liveRoom=null;liveCursor=0;ui.liveRoomPanel.hidden=true;await loadLiveRooms();}if(activeView==='alerts')await loadAlerts();if(activeView==='search')await loadDiscovery();}}catch(e){}},2000);}
window.addEventListener('focus',()=>{if(state&&state.connected)refreshState().catch(()=>{});});
window.onbeforeunload=()=>{clearInterval(pageTimer);clearInterval(livePollTimer);clearInterval(liveHeartbeatTimer);clearInterval(cognitiveTimer);clearInterval(agentPollTimer);stream&&stream.getTracks().forEach(t=>t.stop());};
refreshState().then(pageWatch).catch(fail);
