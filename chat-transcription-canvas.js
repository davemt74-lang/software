(() => {
'use strict';

const BUILD='transcription-intelligence-home-v308-20260907';
const thread=document.getElementById('chatThread');
const cfg=window.STONEFELLOW_RECORDINGS_V198_CONFIG||{};
const listeningEndpoint=String(cfg.listeningEndpoint||'/api/artist-listening.php');
const intelligenceEndpoint=String(cfg.intelligenceEndpoint||'/api/artist-listening-intelligence-v300.php');
const artistListeningUrl=String(cfg.artistListeningUrl||'/artist-listening.php');
const SEEN_KEY='stonefellow.transcription-canvas.v308.seen';
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
const clean=value=>String(value||'').replace(/\s+/g,' ').trim();
const asArray=value=>Array.isArray(value)?value:[];
const itemId=item=>`${Math.max(0,Number(item?.session_id||0))}:${String(item?.key||'')}`;
const refId=(sessionId,key)=>`${Math.max(0,Number(sessionId||0))}:${String(key||'')}`;
const sessionIdOf=session=>Math.max(0,Number(session?.id||session?.session_id||0));

let canvas=null,backdrop=null,button=null,badge=null,observer=null;
let recordings=[],sessions=[],currentSession=null,currentRecording=null,currentIntelligence=null,currentView='home';
let seen=new Set(),seenLoaded=false,loadToken=0;
const intelligenceCache=new Map();

const proof=window.STONEFELLOW_TRANSCRIPTION_CANVAS_V308={
  build:BUILD,loaded:true,opens:0,newRecordingOpens:0,audioErrors:0,intelligenceLoads:0,lastError:'',
};
window.STONEFELLOW_TRANSCRIPTION_CANVAS_V243=proof;

function recordingApi(){return window.STONEFELLOW_ARTIST_RECORDINGS_V198?.api||null;}
async function waitForRecordingApi(timeout=5000){
  const started=Date.now();
  while(Date.now()-started<timeout){
    const api=recordingApi();
    if(api)return api;
    await new Promise(resolve=>setTimeout(resolve,40));
  }
  return null;
}
function formatDuration(ms){
  const total=Math.max(0,Math.round(Number(ms||0)/1000));
  const h=Math.floor(total/3600),m=Math.floor((total%3600)/60),s=total%60;
  return h?`${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`:`${m}:${String(s).padStart(2,'0')}`;
}
function formatDate(value){
  const date=new Date(String(value||'').replace(' ','T'));
  return Number.isFinite(date.getTime())?date.toLocaleString([], {month:'short',day:'numeric',year:date.getFullYear()!==new Date().getFullYear()?'numeric':undefined,hour:'numeric',minute:'2-digit'}):'';
}
function sessionTitle(session){return clean(session?.title||session?.session_title||`Transcription ${sessionIdOf(session)}`)||`Transcription ${sessionIdOf(session)}`;}
function transcriptUrl(sessionId,page=0){
  const url=new URL(artistListeningUrl,location.href);
  if(sessionId>0)url.searchParams.set('session',String(sessionId));
  if(page>0)url.searchParams.set('page',String(page));
  return url.href;
}
function loadSeen(){
  if(seenLoaded)return true;
  seenLoaded=true;
  try{
    const parsed=JSON.parse(localStorage.getItem(SEEN_KEY)||'[]');
    if(Array.isArray(parsed))parsed.forEach(id=>{if(id)seen.add(String(id));});
    return Array.isArray(parsed)&&parsed.length>0;
  }catch(_e){return false;}
}
function saveSeen(){try{localStorage.setItem(SEEN_KEY,JSON.stringify([...seen].slice(-800)));}catch(_e){}}
function markSeen(item){if(!item)return;const id=itemId(item);if(id&&!seen.has(id)){seen.add(id);saveSeen();}updateBadge();}
function unseenItems(){return recordings.filter(item=>!seen.has(itemId(item)));}

function ensureButton(){
  if(button?.isConnected)return;
  const actions=document.querySelector('.chat-topbar-actions');
  if(!actions)return;
  button=document.createElement('button');
  button.type='button';
  button.className='chat-transcription-canvas-button';
  button.setAttribute('aria-label','Open Transcription Intelligence');
  button.setAttribute('aria-expanded','false');
  button.title='Transcription Intelligence';
  button.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3.5h7l3 3V20H7z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M14 3.5V7h3M9.5 11h5M9.5 14h5M9.5 17h3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg><em hidden>0</em>';
  badge=button.querySelector('em');
  const profileActivity=actions.querySelector('.chat-profile-activity-button');
  const notificationMenu=document.getElementById('chatNotificationMenu');
  actions.insertBefore(button,profileActivity||notificationMenu||document.getElementById('chatProfileMenu')||null);
  button.addEventListener('click',()=>void openLatestSession());
}
function updateBadge(){
  ensureButton();
  const count=unseenItems().length;
  if(!badge)return;
  badge.hidden=count<1;
  badge.textContent=count>99?'99+':String(count);
  button?.classList.toggle('has-activity',count>0);
}
function ensureCanvas(){
  if(canvas?.isConnected)return;
  backdrop=document.createElement('div');backdrop.className='transcription-canvas-backdrop';backdrop.hidden=true;
  canvas=document.createElement('aside');
  canvas.id='chatTranscriptionCanvas';
  canvas.className='chat-transcription-canvas-v243 chat-transcription-intelligence-v308';
  canvas.hidden=true;
  canvas.setAttribute('aria-label','Transcription Intelligence');
  canvas.innerHTML='<header><div><small>Artist Listening</small><strong>Transcription Intelligence</strong></div><button type="button" data-transcription-close aria-label="Close">×</button></header><div class="transcription-canvas-body" data-transcription-body><div class="transcription-canvas-empty">Loading transcriptions…</div></div>';
  document.body.append(backdrop,canvas);
  backdrop.addEventListener('click',closeCanvas);
  canvas.querySelector('[data-transcription-close]')?.addEventListener('click',closeCanvas);
  canvas.addEventListener('click',handleCanvasClick);
  canvas.addEventListener('change',handleCanvasChange);
  canvas.addEventListener('play',handleCanvasPlay,true);
}
function openShell(){
  ensureButton();ensureCanvas();canvas.hidden=false;backdrop.hidden=false;
  requestAnimationFrame(()=>{canvas?.classList.add('open');backdrop?.classList.add('open');});
  button?.setAttribute('aria-expanded','true');document.body.classList.add('transcription-canvas-open');proof.opens++;
}
function closeCanvas(){
  if(!canvas)return;
  canvas.classList.remove('open');backdrop?.classList.remove('open');button?.setAttribute('aria-expanded','false');document.body.classList.remove('transcription-canvas-open');
  setTimeout(()=>{if(canvas&&!canvas.classList.contains('open')){canvas.hidden=true;if(backdrop)backdrop.hidden=true;}},180);
}

async function refreshRecordings(force=true){
  const api=await waitForRecordingApi();
  if(!api){recordings=[];updateBadge();return recordings;}
  const state=force?await api.refresh():api.getState();
  recordings=Array.isArray(state?.library)?state.library:[];updateBadge();return recordings;
}
async function refreshSessions(){
  const url=new URL(listeningEndpoint,location.href);url.searchParams.set('action','bootstrap');
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
  const data=await response.json().catch(()=>null);
  if(!response.ok||!data?.ok)throw new Error(clean(data?.error)||'Could not load your transcriptions.');
  sessions=asArray(data.sessions).filter(row=>sessionIdOf(row)>0);
  return sessions;
}
async function fetchIntelligence(sessionId,{force=false}={}){
  sessionId=Math.max(0,Number(sessionId||0));
  if(sessionId<1)return null;
  if(!force&&intelligenceCache.has(sessionId))return intelligenceCache.get(sessionId);
  const url=new URL(intelligenceEndpoint,location.href);url.searchParams.set('action','status');url.searchParams.set('session_id',String(sessionId));
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
  const data=await response.json().catch(()=>null);
  if(!response.ok||!data?.ok)throw new Error(clean(data?.error)||'Could not load transcription intelligence.');
  intelligenceCache.set(sessionId,data);proof.intelligenceLoads++;
  return data;
}
function recordingsForSession(sessionId){return recordings.filter(row=>Math.max(0,Number(row?.session_id||0))===Math.max(0,Number(sessionId||0)));}
function preferredRecording(sessionId,key=''){
  const rows=recordingsForSession(sessionId);
  if(key){const exact=rows.find(row=>String(row?.key||'')===String(key));if(exact)return exact;}
  return rows[0]||null;
}

function registryMap(data){
  const map=new Map();
  asArray(data?.registry).forEach(app=>{const id=clean(app?.id).toLowerCase();if(id)map.set(id,app);});
  return map;
}
function moduleMap(data){
  const modules=data?.master?.analysis?.modules;
  return modules&&typeof modules==='object'&&!Array.isArray(modules)?modules:{};
}
function hasValue(value){
  if(typeof value==='string')return clean(value)!=='';
  if(Array.isArray(value))return value.some(hasValue);
  if(value&&typeof value==='object')return Object.values(value).some(hasValue);
  return value!==null&&value!==undefined&&value!==false;
}
function activeModules(data){
  const registry=registryMap(data),modules=moduleMap(data),out=[];
  Object.entries(modules).forEach(([id,module])=>{
    if(!module||typeof module!=='object'||!hasValue(module.result))return;
    const app=registry.get(id)||{id,label:id,title:id,sections:[]};
    out.push({id,app,module,result:module.result||{},status:data?.app_status?.[id]||null});
  });
  return out;
}
function sectionRows(active,sectionKey){return asArray(active?.result?.[sectionKey]).filter(row=>row&&typeof row==='object');}
function primaryFor(active,sectionKey){
  const section=asArray(active?.app?.sections).find(row=>row?.key===sectionKey);
  return clean(section?.primary)||'text';
}
function rowText(active,sectionKey,row){
  const primary=primaryFor(active,sectionKey);
  for(const key of [primary,'text','value','summary','point','action','decision','commitment','risk','question','next_step','follow_up','signal','change','objection','promise','claim','objective','blocker','topic','name','event','moment','response','note']){
    const value=clean(row?.[key]);if(value)return value;
  }
  return '';
}
function reviewState(row){const state=clean(row?.review_state).toLowerCase();return ['accepted','rejected','unreviewed'].includes(state)?state:'unreviewed';}
function evidencePages(row){
  const pages=[];
  asArray(row?.evidence_refs).forEach(ref=>{const page=Math.max(0,Number(ref?.page||0));if(page>0&&!pages.includes(page))pages.push(page);});
  const text=clean(row?.evidence||row?.transcript_evidence||'');
  for(const match of text.matchAll(/\bpage\s+(\d+)\b/gi)){const page=Math.max(0,Number(match[1]||0));if(page>0&&!pages.includes(page))pages.push(page);}
  return pages.sort((a,b)=>a-b);
}
function sourceChip(label){return `<span class="intel-source-chip">${esc(label)}</span>`;}
function statusLabel(status,module){
  const reason=clean(status?.reason||status?.state||'').replace(/_/g,' ');
  if(reason)return reason;
  if(status?.current===true)return 'current';
  if(module?.generated_at)return 'generated';
  return 'saved';
}
function collectSection(active,sectionKey,limit=5,{filter=null}={}){
  const rows=sectionRows(active,sectionKey);const out=[];
  for(const row of rows){
    if(filter&&!filter(row))continue;
    const text=rowText(active,sectionKey,row);if(!text)continue;
    out.push({text,row,pluginId:active.id,pluginLabel:clean(active.app.label||active.app.title||active.id),sectionKey});
    if(out.length>=limit)break;
  }
  return out;
}
function mergeItems(groups,limit=5){
  const out=[],seenText=new Set();
  for(const group of groups){for(const item of group){const key=clean(item.text).toLowerCase();if(!key||seenText.has(key))continue;seenText.add(key);out.push(item);if(out.length>=limit)return out;}}
  return out;
}
function activeById(active,id){return active.find(row=>row.id===id)||null;}
function synopsisText(active){
  const summary=activeById(active,'summary_output');
  const overview=summary&&collectSection(summary,'overview',1)[0];if(overview)return overview.text;
  const basic=activeById(active,'basic');
  const legacy=clean(basic?.result?.summary);if(legacy)return legacy;
  const key=basic&&collectSection(basic,'key_findings',1)[0];if(key)return key.text;
  const first=active.flatMap(row=>asArray(row.app.sections).flatMap(section=>collectSection(row,section.key,1))).find(Boolean);
  return first?.text||'';
}
function allPluginHighlights(active){
  const out=[];
  for(const app of active){
    const first=asArray(app.app.sections).flatMap(section=>collectSection(app,section.key,1)).find(Boolean);
    if(first)out.push(first);
  }
  return out.slice(0,12);
}
function overviewModel(data){
  const active=activeModules(data);const by=id=>activeById(active,id);
  let total=0,accepted=0,rejected=0,unreviewed=0;
  for(const app of active){for(const section of asArray(app.app.sections)){for(const row of sectionRows(app,section.key)){const text=rowText(app,section.key,row);if(!text)continue;total++;const state=reviewState(row);if(state==='accepted')accepted++;else if(state==='rejected')rejected++;else unreviewed++;}}}
  const summary=by('summary_output'),basic=by('basic'),actions=by('actions'),actionPlan=by('action_plan'),decisions=by('decisions'),risks=by('risks'),opps=by('opportunities'),qa=by('qa'),followup=by('followup'),crm=by('crm'),knowledge=by('knowledge'),research=by('research_brief');
  const groups={
    findings:mergeItems([summary?collectSection(summary,'key_points',5):[],basic?collectSection(basic,'key_findings',5):[]],5),
    actions:mergeItems([actionPlan?collectSection(actionPlan,'actions',5):[],actionPlan?collectSection(actionPlan,'follow_ups',5):[],actions?collectSection(actions,'items',5):[],followup?collectSection(followup,'items',5):[]],5),
    decisions:mergeItems([summary?collectSection(summary,'decisions',5):[],decisions?collectSection(decisions,'decisions',5):[],decisions?collectSection(decisions,'commitments',5):[]],5),
    risks:mergeItems([summary?collectSection(summary,'risks',5):[],actionPlan?collectSection(actionPlan,'blockers',5):[],risks?collectSection(risks,'items',5):[]],5),
    questions:mergeItems([summary?collectSection(summary,'open_questions',5):[],basic?collectSection(basic,'open_questions',5):[],qa?collectSection(qa,'unanswered',5):[],qa?collectSection(qa,'answered',5):[]],5),
    opportunities:mergeItems([opps?collectSection(opps,'items',5):[]],5),
    crm:mergeItems([crm?collectSection(crm,'buying_signals',3):[],crm?collectSection(crm,'objections',3):[],crm?collectSection(crm,'relationship_changes',3):[],crm?collectSection(crm,'next_best_actions',3):[]],5),
    knowledge:mergeItems([knowledge?collectSection(knowledge,'items',5,{filter:row=>['conflicting','updates_existing','more_specific','new'].includes(clean(row?.knowledge_state).toLowerCase())}):[]],5),
    research:mergeItems([research?collectSection(research,'claims',5,{filter:row=>['mixed','unsupported','unresolved'].includes(clean(row?.verification).toLowerCase())}):[]],5),
  };
  return {active,total,accepted,rejected,unreviewed,synopsis:synopsisText(active),groups,highlights:allPluginHighlights(active)};
}
function itemMarkup(item,sessionId){
  const pages=evidencePages(item.row);const state=reviewState(item.row);
  const source=sourceChip(item.pluginLabel);
  const evidence=pages.length?`<a class="intel-evidence-link" href="${esc(transcriptUrl(sessionId,pages[0]))}">Page ${pages[0]}${pages.length>1?` +${pages.length-1}`:''} ↗</a>`:'';
  const review=state!=='unreviewed'?`<span class="intel-review ${esc(state)}">${esc(state)}</span>`:'';
  return `<li><div>${esc(item.text)}</div><footer>${source}${review}${evidence}</footer></li>`;
}
function overviewCard(title,items,sessionId,emptyText='No current items from the selected plugins.'){
  return `<section class="intel-home-card"><header><strong>${esc(title)}</strong><span>${items.length}</span></header>${items.length?`<ul>${items.map(item=>itemMarkup(item,sessionId)).join('')}</ul>`:`<p class="intel-card-empty">${esc(emptyText)}</p>`}</section>`;
}
function pluginCoverage(active){
  if(!active.length)return '';
  return `<section class="intel-plugin-coverage"><div class="intel-home-section-head"><div><small>Plugin coverage</small><strong>${active.length} intelligence modules</strong></div></div><div class="intel-plugin-grid">${active.map(row=>`<button type="button" data-intel-view="${esc(row.id)}"><span>${esc(clean(row.app.label||row.app.title||row.id))}</span><em>${esc(statusLabel(row.status,row.module))}</em></button>`).join('')}</div></section>`;
}
function homeMarkup(data,session){
  const model=overviewModel(data),sid=sessionIdOf(session),relations=data?.relations_summary||{};
  if(!model.active.length){
    return `<section class="intel-home-empty"><small>Intelligence Home</small><h2>No intelligence yet</h2><p>Open this transcription and run the plugins you want to analyze. Their saved results will roll up here automatically.</p><a href="${esc(transcriptUrl(sid))}">Open transcription ↗</a></section>`;
  }
  const relationCount=Math.max(0,Number(relations.accepted||relations.accepted_count||0));
  const groups=model.groups;
  return `<section class="intel-home" data-intelligence-home>
    <div class="intel-home-hero"><div><small>Intelligence Home</small><h2>Overall synopsis</h2><p>${esc(model.synopsis||'Saved intelligence is available below. Review the plugin details for the full analysis.')}</p></div><a href="${esc(transcriptUrl(sid))}">Open full transcript ↗</a></div>
    <div class="intel-home-stats"><div><strong>${model.active.length}</strong><span>Plugins</span></div><div><strong>${model.total}</strong><span>Findings</span></div><div><strong>${model.accepted}</strong><span>Accepted</span></div><div><strong>${model.unreviewed}</strong><span>Needs review</span></div><div><strong>${relationCount}</strong><span>Connections</span></div></div>
    <div class="intel-home-grid">
      ${overviewCard('Key findings',groups.findings,sid)}
      ${overviewCard('Actions & follow-ups',groups.actions,sid)}
      ${overviewCard('Decisions & commitments',groups.decisions,sid)}
      ${overviewCard('Risks & blockers',groups.risks,sid)}
      ${overviewCard('Opportunities',groups.opportunities,sid)}
      ${overviewCard('Open questions',groups.questions,sid)}
      ${groups.crm.length?overviewCard('CRM relationship signals',groups.crm,sid):''}
      ${groups.knowledge.length?overviewCard('Knowledge intelligence',groups.knowledge,sid):''}
      ${groups.research.length?overviewCard('Claims to verify',groups.research,sid):''}
      ${overviewCard('All plugin highlights',model.highlights,sid)}
    </div>
    ${pluginCoverage(model.active)}
  </section>`;
}
function metaMarkup(row,key,value,sessionId){
  if(value===null||value===undefined||value==='')return '';
  if(Array.isArray(value))value=value.join(', ');
  if(typeof value==='object')value=JSON.stringify(value);
  const label=key.replace(/_/g,' ');
  if(key==='evidence'||key==='transcript_evidence'){
    const page=(clean(value).match(/\bpage\s+(\d+)\b/i)||[])[1];
    return `<span><b>${esc(label)}</b>${page?`<a href="${esc(transcriptUrl(sessionId,Number(page)))}">${esc(clean(value))} ↗</a>`:esc(clean(value))}</span>`;
  }
  return `<span><b>${esc(label)}</b>${esc(clean(value))}</span>`;
}
function pluginMarkup(data,session,appId){
  const active=activeModules(data);const item=activeById(active,appId);const sid=sessionIdOf(session);
  if(!item)return `<section class="intel-plugin-empty"><h2>Plugin not available</h2><p>This plugin has no saved result for the selected transcription.</p></section>`;
  const app=item.app,result=item.result||{};let sections='';
  if(appId==='basic'&&clean(result.summary))sections+=`<section class="intel-plugin-summary"><small>Executive summary</small><p>${esc(clean(result.summary))}</p></section>`;
  if(appId==='basic'&&clean(result.analysis))sections+=`<section class="intel-plugin-summary"><small>Analysis</small><p>${esc(clean(result.analysis))}</p></section>`;
  for(const section of asArray(app.sections)){
    const rows=sectionRows(item,section.key);if(!rows.length)continue;
    sections+=`<section class="intel-plugin-section"><header><strong>${esc(clean(section.title||section.key))}</strong><span>${rows.length}</span></header><div class="intel-plugin-rows">${rows.map(row=>{
      const text=rowText(item,section.key,row);if(!text)return '';
      const pages=evidencePages(row);const state=reviewState(row);const metaKeys=asArray(section.meta);
      const meta=metaKeys.map(key=>metaMarkup(row,key,row?.[key],sid)).join('');
      return `<article><div class="intel-plugin-row-head"><p>${esc(text)}</p>${state!=='unreviewed'?`<span class="intel-review ${esc(state)}">${esc(state)}</span>`:''}</div>${meta?`<div class="intel-plugin-meta">${meta}</div>`:''}${pages.length?`<footer><a class="intel-evidence-link" href="${esc(transcriptUrl(sid,pages[0]))}">Evidence · Page ${pages[0]} ↗</a></footer>`:''}</article>`;
    }).join('')}</div></section>`;
  }
  if(!sections)sections='<div class="intel-card-empty">This plugin has a saved result but no displayable rows.</div>';
  return `<section class="intel-plugin-view"><div class="intel-plugin-hero"><div><small>Plugin detail</small><h2>${esc(clean(app.title||app.label||appId))}</h2><p>${esc(clean(app.description||''))}</p></div><a href="${esc(transcriptUrl(sid))}">Open transcript ↗</a></div>${sections}</section>`;
}
function viewTabs(data){
  const active=activeModules(data);
  return `<nav class="intel-view-tabs" aria-label="Intelligence views"><button type="button" data-intel-view="home" class="${currentView==='home'?'active':''}">Home</button>${active.map(row=>`<button type="button" data-intel-view="${esc(row.id)}" class="${currentView===row.id?'active':''}">${esc(clean(row.app.label||row.id))}</button>`).join('')}</nav>`;
}
function selectorMarkup(){
  if(!sessions.length)return '<div class="intel-transcript-selector"><span>Transcription</span><strong>No saved transcriptions</strong></div>';
  const selected=sessionIdOf(currentSession);
  return `<label class="intel-transcript-selector"><span>Transcription</span><select data-transcription-session-select>${sessions.map(session=>{const id=sessionIdOf(session);const title=sessionTitle(session);const date=formatDate(session.last_activity_at||session.updated_at||session.created_at);return `<option value="${id}"${id===selected?' selected':''}>${esc(title)}${date?` · ${esc(date)}`:''}</option>`;}).join('')}</select></label>`;
}
function sessionMetaMarkup(session){
  const sid=sessionIdOf(session),recordingRows=recordingsForSession(sid),date=formatDate(session?.last_activity_at||session?.updated_at||session?.created_at);
  return `<div class="intel-session-head"><div><h1>${esc(sessionTitle(session))}</h1><p>${[date,`${Math.max(0,Number(session?.transcript_count||session?.segment_count||0))} transcript segments`,recordingRows.length?`${recordingRows.length} recording${recordingRows.length===1?'':'s'}`:''].filter(Boolean).join(' · ')}</p></div><button type="button" data-intelligence-refresh title="Refresh intelligence">↻</button></div>`;
}
function audioMarkup(session){
  const sid=sessionIdOf(session),rows=recordingsForSession(sid);currentRecording=preferredRecording(sid,currentRecording?.key||'');
  if(!currentRecording)return '';
  const downloadName=clean(currentRecording.name||'recording').replace(/[^a-z0-9_-]+/gi,'-')||'recording';
  const choices=rows.length>1?`<select data-recording-select>${rows.map(row=>`<option value="${esc(row.key||'')}"${String(row.key||'')===String(currentRecording.key||'')?' selected':''}>${esc(clean(row.name||'Recording'))} · ${esc(formatDuration(row.duration_ms))}</option>`).join('')}</select>`:'';
  return `<details class="intel-audio"${rows.length===1?'':' open'}><summary><span>Recording audio</span><em>${rows.length}</em></summary><div>${choices}<audio data-transcription-audio controls preload="metadata" src="${esc(currentRecording.url||'')}"></audio><div class="transcription-canvas-audio-status" data-transcription-audio-status></div><a href="${esc(currentRecording.url||'#')}" download="${esc(downloadName)}">Download recording</a></div></details>`;
}
function renderWorkspace(){
  ensureCanvas();const body=canvas.querySelector('[data-transcription-body]');if(!body)return;
  if(!currentSession){body.innerHTML=`${selectorMarkup()}<div class="transcription-canvas-empty">No saved transcriptions yet.</div>`;return;}
  if(!currentIntelligence){body.innerHTML=`${selectorMarkup()}${sessionMetaMarkup(currentSession)}<div class="intel-loading"><span></span>Loading intelligence…</div>${audioMarkup(currentSession)}`;bindAudioStatus(body.querySelector('[data-transcription-audio]'));return;}
  const active=activeModules(currentIntelligence);if(currentView!=='home'&&!active.some(row=>row.id===currentView))currentView='home';
  body.innerHTML=`<div class="intel-sticky-controls">${selectorMarkup()}${viewTabs(currentIntelligence)}</div>${sessionMetaMarkup(currentSession)}<main class="intel-view">${currentView==='home'?homeMarkup(currentIntelligence,currentSession):pluginMarkup(currentIntelligence,currentSession,currentView)}</main>${audioMarkup(currentSession)}`;
  bindAudioStatus(body.querySelector('[data-transcription-audio]'));
}
function renderError(message){const body=canvas?.querySelector('[data-transcription-body]');if(body)body.innerHTML=`${selectorMarkup()}<div class="transcription-canvas-empty"><strong>Could not load intelligence</strong><p>${esc(message)}</p><button type="button" data-intelligence-refresh>Try again</button></div>`;}
function bindAudioStatus(audio){
  if(!audio)return;const host=canvas.querySelector('[data-transcription-audio-status]');
  const ready=()=>{if(host)host.textContent=Number.isFinite(audio.duration)&&audio.duration>0?`Audio ready · ${formatDuration(audio.duration*1000)}`:'Audio ready';};
  const error=()=>{proof.audioErrors++;if(host)host.textContent='Recording audio could not be loaded. The transcript and intelligence remain available.';};
  audio.addEventListener('loadedmetadata',ready,{once:true});audio.addEventListener('durationchange',ready,{once:true});audio.addEventListener('error',error,{once:true});
}
async function selectSession(sessionId,{force=false,key='',isNew=false}={}){
  const token=++loadToken;sessionId=Math.max(0,Number(sessionId||0));
  if(sessionId<1)return;
  currentSession=sessions.find(row=>sessionIdOf(row)===sessionId)||currentSession;
  currentRecording=preferredRecording(sessionId,key);if(currentRecording)markSeen(currentRecording);
  currentIntelligence=null;currentView='home';openShell();renderWorkspace();
  try{
    const data=await fetchIntelligence(sessionId,{force});if(token!==loadToken)return;
    currentIntelligence=data;proof.lastError='';renderWorkspace();
    if(isNew)proof.newRecordingOpens++;
    window.dispatchEvent(new CustomEvent('stonefellow:transcription-canvas-opened',{detail:{sessionId,key:String(key||''),isNew,view:'home'}}));
  }catch(error){if(token!==loadToken)return;proof.lastError=String(error?.message||error);renderError(proof.lastError);}
}
async function refreshAll(){
  await Promise.allSettled([refreshSessions(),refreshRecordings(true)]);
  if(currentSession){const sid=sessionIdOf(currentSession);currentSession=sessions.find(row=>sessionIdOf(row)===sid)||currentSession;intelligenceCache.delete(sid);await selectSession(sid,{force:true,key:currentRecording?.key||''});}
}
async function openLatestSession(){
  openShell();
  try{
    await Promise.all([refreshSessions(),refreshRecordings(true)]);
    const target=currentSession&&sessions.some(row=>sessionIdOf(row)===sessionIdOf(currentSession))?currentSession:sessions[0];
    if(target)await selectSession(sessionIdOf(target));else renderWorkspace();
  }catch(error){proof.lastError=String(error?.message||error);renderError(proof.lastError);}
}
async function openRef(sessionId,key,options={}){
  openShell();
  try{
    await Promise.all([refreshSessions(),refreshRecordings(true)]);
    const sid=Math.max(0,Number(sessionId||0));
    const session=sessions.find(row=>sessionIdOf(row)===sid);
    if(!session)throw new Error('That transcription is no longer available.');
    await selectSession(sid,{key,isNew:!!options.isNew});
  }catch(error){proof.lastError=String(error?.message||error);renderError(proof.lastError);}
}
async function handleCanvasChange(event){
  const select=event.target.closest('[data-transcription-session-select]');
  if(select){await selectSession(select.value);return;}
  const recordingSelect=event.target.closest('[data-recording-select]');
  if(recordingSelect&&currentSession){currentRecording=preferredRecording(sessionIdOf(currentSession),recordingSelect.value);renderWorkspace();return;}
}
async function handleCanvasClick(event){
  const view=event.target.closest('[data-intel-view]');
  if(view&&currentIntelligence){currentView=clean(view.dataset.intelView)||'home';renderWorkspace();return;}
  if(event.target.closest('[data-intelligence-refresh]')){await refreshAll();return;}
}
function handleCanvasPlay(event){
  const audio=event.target.closest?.('[data-transcription-audio]');if(!audio||!currentRecording)return;
  document.querySelectorAll('audio[data-v206-recording-audio],audio[data-v198-recording-audio],audio.chat-transcription-audio,[data-listening-workspace-recording-audio]').forEach(other=>{if(other!==audio){try{other.pause();}catch(_e){}}});
  const api=recordingApi();if(api)void api.select({sessionId:currentRecording.session_id,key:currentRecording.key}).catch(()=>{});
}
function detectNewCards(root=document){
  if(!root)return;const candidates=[];
  if(root.nodeType===1&&root.matches?.('.sf-v206-recording-message.is-new-recording'))candidates.push(root);
  if(root.querySelectorAll)candidates.push(...root.querySelectorAll('.sf-v206-recording-message.is-new-recording'));
  const message=candidates.at(-1);if(!message||message.dataset.transcriptionCanvasHandled==='1')return;
  const card=message.querySelector('[data-v206-recording-card]');if(!card)return;
  message.dataset.transcriptionCanvasHandled='1';const sessionId=Number(card.dataset.v206Session||0),key=String(card.dataset.v206Key||'');
  if(sessionId>0&&key)void openRef(sessionId,key,{isNew:true}).catch(error=>{proof.lastError=String(error?.message||error);});
}
function bindInlineCards(){
  document.addEventListener('click',event=>{
    const card=event.target.closest?.('[data-v206-recording-card]');if(!card)return;
    if(event.target.closest('audio,button,a,summary,details,input,select,textarea'))return;
    void openRef(card.dataset.v206Session,card.dataset.v206Key).catch(error=>{proof.lastError=String(error?.message||error);});
  });
}
async function bootstrap(){
  ensureButton();ensureCanvas();bindInlineCards();const hadSeen=loadSeen();
  try{
    await Promise.allSettled([refreshSessions(),refreshRecordings(true)]);
    if(!hadSeen){recordings.forEach(item=>seen.add(itemId(item)));saveSeen();updateBadge();}
  }catch(error){proof.lastError=String(error?.message||error);}
  if(thread){
    detectNewCards(thread);
    observer=new MutationObserver(records=>{for(const record of records){for(const node of record.addedNodes){if(node.nodeType===1)detectNewCards(node);}}});
    observer.observe(thread,{childList:true,subtree:true});
  }
  window.addEventListener('stonefellow:recording-saved',()=>setTimeout(()=>void Promise.allSettled([refreshSessions(),refreshRecordings(true)]).then(()=>detectNewCards(thread||document)),80));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&canvas?.classList.contains('open'))closeCanvas();});
}

window.STONEFELLOW_TRANSCRIPTION_CANVAS={
  open:async(args={})=>{if(args.sessionId)return openRef(args.sessionId,args.key||'',args);return openLatestSession();},
  close:closeCanvas,
  refresh:async()=>{await refreshAll();return {sessions:[...sessions],recordings:[...recordings],currentSession:currentSession?{...currentSession}:null,currentView};},
  showHome:()=>{if(currentIntelligence){currentView='home';renderWorkspace();}},
};

window.addEventListener('pagehide',()=>observer?.disconnect(),{once:true});
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>void bootstrap(),{once:true});else void bootstrap();
})();