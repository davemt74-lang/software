(()=>{'use strict';
const boot=window.VP3Meeting;if(!boot||!boot.intelligenceEndpoint)return;
const $=(s,r=document)=>r.querySelector(s);const $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
let state=null,loading=false,analyzing=false,noteTimer=null,autoTimer=null,finalizing=false;
const privateMessage='Meeting Intelligence is private to the organizer.';
const VP3_MIN_LIVE_WORDS=80,VP3_MIN_FINAL_WORDS=20;

async function formPost(endpoint,extra={}){
  const body=new URLSearchParams({meeting:boot.meeting||'',invite:boot.invite||'',csrf_token:boot.csrf||'',...extra});
  const res=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:body.toString(),credentials:'same-origin',cache:'no-store'});
  let data={};try{data=await res.json();}catch(_){data={};}
  if(!res.ok||!data.ok)throw new Error(data.error||'Meeting Intelligence request failed.');return data;
}
async function intelligence(action,extra={}){return formPost(boot.intelligenceEndpoint,{action,...extra});}
function setStatus(message='',kind=''){const el=$('#meetingIntelligenceStatus');if(el){el.textContent=message;el.dataset.kind=kind;}}
function textFrom(row,keys){if(!row||typeof row!=='object')return '';for(const key of keys){const v=String(row[key]??'').trim();if(v)return v;}return '';}
function metaFrom(row,keys){const out=[];for(const key of keys){const v=String(row?.[key]??'').trim();if(v&&v!=='unknown')out.push(v);}return out.join(' · ');}
function clearAndMessage(el,message){if(!el)return;el.innerHTML='';const empty=document.createElement('div');empty.className='meeting-agent-empty';empty.textContent=message;el.appendChild(empty);}
function renderList(id,rows,primaryKeys,metaKeys=[],emptyText='Nothing identified yet.'){
  const el=$(id);if(!el)return;el.innerHTML='';const list=Array.isArray(rows)?rows:[];
  if(!list.length){clearAndMessage(el,emptyText);return;}
  for(const row of list){if(!row||typeof row!=='object')continue;const text=textFrom(row,primaryKeys);if(!text)continue;const card=document.createElement('article');card.className='meeting-intelligence-item';const body=document.createElement('p');body.textContent=text;card.appendChild(body);const meta=metaFrom(row,metaKeys);if(meta){const small=document.createElement('span');small.textContent=meta;card.appendChild(small);}const review=String(row.review_state||'').trim();if(review){const badge=document.createElement('em');badge.textContent=review;card.appendChild(badge);}el.appendChild(card);}
  if(!el.children.length)clearAndMessage(el,emptyText);
}
function renderSummary(snapshot,prep){
  const el=$('#meetingIntelligenceSummary');if(!el)return;el.innerHTML='';const summary=String(snapshot?.summary||'').trim();
  if(summary){const p=document.createElement('p');p.className='meeting-intelligence-summary-text';p.textContent=summary;el.appendChild(p);}
  const points=Array.isArray(snapshot?.key_points)?snapshot.key_points:[];
  if(points.length){const h=document.createElement('strong');h.textContent='Key points';el.appendChild(h);const ul=document.createElement('ul');for(const row of points.slice(0,10)){const t=textFrom(row,['point','text','finding','summary']);if(!t)continue;const li=document.createElement('li');li.textContent=t;ul.appendChild(li);}el.appendChild(ul);}
  if(!summary&&!points.length&&prep?.brief_text){const h=document.createElement('strong');h.textContent='Pre-meeting brief';const p=document.createElement('p');p.textContent=String(prep.brief_text);el.append(h,p);}
  if(!el.children.length)clearAndMessage(el,'Rolling summary will appear after the transcript has enough material to analyze.');
}
function renderObjectives(rows){
  const el=$('#meetingObjectiveList');if(!el)return;el.innerHTML='';const list=Array.isArray(rows)?rows:[];
  if(!list.length){clearAndMessage(el,'No meeting objectives yet. Add the outcome you want this meeting to achieve.');return;}
  for(const row of list){const item=document.createElement('label');item.className='meeting-objective-item';const box=document.createElement('input');box.type='checkbox';box.checked=String(row.status)==='completed';box.dataset.objectiveId=String(row.id||'');box.addEventListener('change',()=>setObjective(row.id,box.checked?'completed':'open'));const text=document.createElement('span');text.textContent=String(row.objective_text||'');if(String(row.status)==='cancelled')text.classList.add('cancelled');item.append(box,text);el.appendChild(item);}
}
function renderActivity(s){
  const el=$('#meetingIntelligenceActivity');if(!el)return;el.innerHTML='';const snap=s.snapshot||{},rows=[];
  if(s.last_live_analysis_at)rows.push(['Rolling intelligence',s.last_live_analysis_at]);if(s.final_analysis_at)rows.push(['Final intelligence',s.final_analysis_at]);if(s.handoff_at)rows.push(['Agent Chat handoff',s.handoff_at]);
  (Array.isArray(snap.plugins)?snap.plugins:[]).slice(0,14).forEach(p=>rows.push([String(p.id||'Plugin'),String(p.generated_at||'')]));
  const q=s.post_meeting_queue||{};if(q.ready)rows.push(['Post-meeting queue',String(q.generated_at||'Ready')]);
  if(!rows.length){clearAndMessage(el,'Analysis activity will appear here as the current authorized Meeting Intelligence route runs.');return;}
  for(const [label,time] of rows){const row=document.createElement('div');row.className='meeting-activity-row';const strong=document.createElement('strong');strong.textContent=label;const span=document.createElement('span');span.textContent=time||'Ready';row.append(strong,span);el.appendChild(row);}
}
function renderPolicy(s){
  const policy=s.processing_policy||{},hybrid=s.hybrid_intelligence||{},el=$('#meetingIntelligencePolicy');if(!el)return;
  if(hybrid.route==='homeserver'&&hybrid.ready===true){el.textContent='Processing route: HomeServer private AI · local model and private context';el.dataset.state='private';}
  else if(hybrid.route==='cloud'&&hybrid.ready===true){el.textContent='Processing route: VP3 Cloud AI';el.dataset.state='allowed';}
  else if(hybrid.status==='required_unavailable'){el.textContent='Processing route: HomeServer required · private Meeting Intelligence is unavailable';el.dataset.state='private';}
  else if(policy.requested_compute==='homeserver_only'){el.textContent='Processing route: HomeServer only · cloud AI is blocked';el.dataset.state='private';}
  else if(policy.policy_resolved===false){el.textContent='Processing route: waiting for organizer policy';el.dataset.state='pending';}
  else{el.textContent='Processing route: no authorized Meeting Intelligence route';el.dataset.state='private';}
}

function activatePane(name){
  $$('.meeting-agent-tab').forEach(b=>b.classList.toggle('active',b.dataset.pane===name));
  $$('.meeting-agent-pane').forEach(p=>p.classList.toggle('active',p.id==='meetingPane-'+name));
}
function renderQueue(queue){window.VP3MeetingFollowthrough18100?.render?.(queue);}
function loadFollowthroughController(){
  if(window.VP3MeetingFollowthrough18100)return Promise.resolve();
  return new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='video-meetings-followthrough-v18100.js?v=18100';script.async=true;script.onload=resolve;script.onerror=()=>reject(new Error('Post-meeting follow-through UI could not load.'));document.head.appendChild(script);});
}
function loadMemoryController(){
  if(!boot.reviewOnly||!boot.isOrganizer)return Promise.resolve();
  if(document.querySelector('script[data-vp3-meeting-memory="18120"]'))return Promise.resolve();
  const intelligenceEndpoint=String(boot.intelligenceEndpoint||'');
  boot.memoryEndpoint=boot.memoryEndpoint||intelligenceEndpoint.replace(/video-meeting-intelligence\.php(?:\?.*)?$/,'video-meeting-memory.php')||'/api/video-meeting-memory.php';
  return new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='video-meetings-memory-v18120.js?v=18120';script.async=true;script.dataset.vp3MeetingMemory='18120';script.onload=resolve;script.onerror=()=>reject(new Error('Meeting Search & Memory UI could not load.'));document.head.appendChild(script);});
}

function renderState(s){
  state=s||{};const snap=state.snapshot||{};renderSummary(snap,state.prep);
  renderList('#meetingIntelligenceActions',snap.actions,['action','follow_up','next_step','text'],['owner','timing','due_date','priority','status'],'No grounded actions or follow-up items yet.');
  renderList('#meetingIntelligenceDecisions',snap.decisions,['decision','commitment','text'],['owner','timing','confidence'],'No confirmed decisions or commitments yet.');
  renderList('#meetingIntelligenceQuestions',snap.questions,['question','text'],['asked_by','why_open','follow_up'],'No unresolved questions identified yet.');
  renderList('#meetingIntelligenceRisks',snap.risks,['risk','blocker','text'],['impact','likelihood','owner'],'No grounded blockers or risks identified yet.');
  renderList('#meetingIntelligenceCrm',snap.crm?.next_best_actions?.length?snap.crm.next_best_actions:(snap.crm?.signals||[]),['action','signal','update','text'],['contact','priority','confidence'],'No explicit CRM relationship signal has been produced yet.');
  renderObjectives(state.objectives);renderActivity(state);renderPolicy(state);renderQueue(state.post_meeting_queue);
  const note=$('#meetingPrivateNotes');if(note&&document.activeElement!==note&&note.value!==String(state.notes?.text||''))note.value=String(state.notes?.text||'');
  const bridge=$('#meetingTranscriptBridgeState');if(bridge&&Number(state.session_id||0)>0)bridge.textContent='VP3 Transcription #'+Number(state.session_id)+' · '+Number(state.word_count||0)+' words';
  [$('#meetingFullIntelligenceLink'),$('#meetingFullIntelligenceLinkSecondary')].forEach(full=>{if(full&&state.full_transcription_url)full.href=String(state.full_transcription_url);});
  const isFinal=['ended','processed'].includes(String(state.meeting_status||'')),minWords=isFinal?VP3_MIN_FINAL_WORDS:VP3_MIN_LIVE_WORDS;
  const refresh=$('#meetingIntelligenceRefresh');if(refresh){refresh.disabled=analyzing||Number(state.word_count||0)<minWords||state.hybrid_intelligence?.ready!==true;refresh.textContent=analyzing?'Analyzing…':(state.final_analysis_due?'Finalize intelligence':'Update intelligence');}
  const handoff=$('#meetingIntelligenceHandoff');if(handoff){handoff.disabled=!state.final_analysis_at||analyzing;handoff.textContent=state.handoff_at?'Sent to Agent Chat':'Send to Agent Chat';}
  if(state.last_error)setStatus(String(state.last_error),'error');
  else if(state.final_analysis_at)setStatus('Final meeting intelligence is current. Review it before sending it to Agent Chat.','ready');
  else if(state.live_analysis_due)setStatus('New transcript material is ready for rolling intelligence.','ready');
  else if(Number(state.word_count||0)<minWords)setStatus(isFinal?'This meeting does not yet have enough final transcript context.':'Waiting for more transcript context.','waiting');
  else if(state.hybrid_intelligence?.ready!==true)setStatus('The configured Meeting Intelligence route is not ready.','private');
  else setStatus('Meeting intelligence is current.','ready');
}
async function loadState(){
  if(!boot.isOrganizer){setStatus(privateMessage,'private');return null;}if(loading)return state;loading=true;
  try{const data=await intelligence('state');renderState(data.state||{});return state;}catch(err){setStatus(err.message||'Meeting Intelligence could not load.','error');return null;}finally{loading=false;}
}
async function runAnalysis(mode='live',automatic=false){
  if(!boot.isOrganizer||analyzing)return false;const s=await loadState();if(!s)return false;const minWords=mode==='final'?VP3_MIN_FINAL_WORDS:VP3_MIN_LIVE_WORDS;
  if(Number(s.word_count||0)<minWords){if(!automatic)setStatus('The transcript needs more context before analysis.','waiting');return false;}
  if(mode==='live'&&!s.live_analysis_due&&automatic)return false;if(mode==='final'&&!s.final_analysis_due&&automatic)return false;
  const sessionId=Number(s.session_id||0),sourceHash=String(s.source_hash||'');if(!sessionId||!sourceHash)return false;const policy=s.processing_policy||{},hybrid=s.hybrid_intelligence||{};
  if(hybrid.ready!==true){setStatus(hybrid.homeserver_required?'HomeServer private Meeting Intelligence is required but is not ready.':'No authorized Meeting Intelligence route is ready.','private');return false;}
  analyzing=true;renderState(s);const homeRoute=hybrid.route==='homeserver';setStatus(mode==='final'?(homeRoute?'Finalizing privately on HomeServer…':'Finalizing meeting intelligence…'):(homeRoute?'Updating privately on HomeServer…':'Updating rolling meeting intelligence…'),'working');
  try{
    if(homeRoute){const recorded=await intelligence('run_hybrid_analysis',{mode:mode==='final'?'final':'live',source_hash:sourceHash});renderState(recorded.state||{});setStatus(mode==='final'?'Final private meeting intelligence complete. Review it before sending it to Agent Chat.':'Private HomeServer meeting intelligence updated.','ready');return true;}
    if(hybrid.route!=='cloud'||policy.cloud_ai_allowed!==true){setStatus(policy.requested_compute==='homeserver_only'?'HomeServer-only policy blocks VP3 Cloud AI for this meeting.':'Cloud AI processing is not authorized for this meeting.','private');return false;}
    const apps=mode==='final'?(s.analysis_apps_final||['basic','actions','decisions','qa','requirements','followup','risks','topics','crm']):(s.analysis_apps_live||['basic','actions','decisions','qa','followup','risks','topics']);
    const payload={csrf_token:boot.csrf||'',action:'analyze',session_id:sessionId,mode:mode==='final'?'manual':'live',apps,workflow:{depth:'standard',web_research:false,live_analysis:mode!=='final'}};
    const res=await fetch(boot.transcriptionIntelligenceEndpoint,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify(payload)});let data={};try{data=await res.json();}catch(_){data={};}
    if(!res.ok||!data.ok)throw new Error(data.error||'Transcription Intelligence could not complete.');
    const recorded=await intelligence('record_analysis',{mode:mode==='final'?'final':'live',source_hash:sourceHash});renderState(recorded.state||{});setStatus(mode==='final'?'Final meeting intelligence complete. Review it before sending it to Agent Chat.':'Rolling meeting intelligence updated.','ready');return true;
  }catch(err){setStatus(err.message||'Meeting Intelligence analysis failed.','error');return false;}finally{analyzing=false;if(state)renderState(state);}
}
async function saveNote(){const note=$('#meetingPrivateNotes');if(!note||!boot.isOrganizer)return;try{const data=await intelligence('save_note',{note_text:note.value});if(state){state.notes=data.notes||state.notes;renderState(state);}setStatus('Private note saved.','ready');}catch(err){setStatus(err.message,'error');}}
function scheduleNoteSave(){clearTimeout(noteTimer);noteTimer=setTimeout(saveNote,650);}
async function addObjective(){const input=$('#meetingObjectiveInput');if(!input)return;const text=input.value.trim();if(!text)return;try{const data=await intelligence('add_objective',{objective_text:text});input.value='';if(state){state.objectives=data.objectives||[];renderObjectives(state.objectives);}setStatus('Meeting objective added.','ready');}catch(err){setStatus(err.message,'error');}}
async function setObjective(id,status){try{const data=await intelligence('objective_status',{objective_id:String(id||0),status});if(state){state.objectives=data.objectives||[];renderObjectives(state.objectives);}}catch(err){setStatus(err.message,'error');}}
async function handoff(){if(!boot.isOrganizer)return;try{setStatus('Publishing reviewed meeting intelligence to Agent Chat…','working');const data=await intelligence('handoff');if(data.state)renderState(data.state);setStatus(data.handoff?.already_published?'This version is already in Agent Chat.':'Reviewed meeting intelligence sent to Agent Chat.','ready');}catch(err){setStatus(err.message,'error');}}
function scheduleAutoLive(){clearTimeout(autoTimer);autoTimer=setTimeout(async()=>{const s=await loadState();if(s?.live_analysis_due)await runAnalysis('live',true);},1800);}
async function finalizeAfterEnd(){if(finalizing||!boot.isOrganizer)return;finalizing=true;try{await new Promise(r=>setTimeout(r,1800));await runAnalysis('final',true);}finally{finalizing=false;}}
function setupReviewTabs(){if(!boot.reviewOnly)return;$$('.meeting-agent-tab').forEach(btn=>btn.addEventListener('click',()=>activatePane(String(btn.dataset.pane||''))));}
function wire(){
  $('#meetingPrivateNotes')?.addEventListener('input',scheduleNoteSave);
  $('#meetingObjectiveAdd')?.addEventListener('click',addObjective);
  $('#meetingObjectiveInput')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();addObjective();}});
  $('#meetingIntelligenceRefresh')?.addEventListener('click',()=>runAnalysis(state?.meeting_status==='ended'||state?.meeting_status==='processed'?'final':'live',false));
  $('#meetingIntelligenceHandoff')?.addEventListener('click',handoff);
  window.addEventListener('vp3:meeting-transcript-updated',scheduleAutoLive);
  window.addEventListener('vp3:meeting-ended',finalizeAfterEnd);
  setupReviewTabs();
}
wire();
loadFollowthroughController().then(()=>window.VP3MeetingFollowthrough18100?.init?.({boot,intelligence,setStatus,renderState,getState:()=>state})).catch(err=>setStatus(err.message,'error'));
loadMemoryController().catch(err=>setStatus(err.message,'error'));
if(!boot.isOrganizer){setStatus(privateMessage,'private');$$('[data-meeting-private]').forEach(el=>el.hidden=true);return;}
loadState().then(s=>{if(boot.reviewOnly&&s?.final_analysis_due)runAnalysis('final',true);});
if(!boot.reviewOnly)setInterval(()=>{if(document.visibilityState==='visible')loadState();},15000);
})();