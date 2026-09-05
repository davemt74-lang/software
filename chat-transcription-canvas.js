(() => {
'use strict';

const BUILD='chat-transcription-canvas-v243-20260905';
const thread=document.getElementById('chatThread');
if(!thread)return;

const cfg=window.STONEFELLOW_RECORDINGS_V198_CONFIG||{};
const intelligenceEndpoint=String(cfg.intelligenceEndpoint||'/api/artist-listening-intelligence-v254.php');
const SEEN_KEY='stonefellow.transcription-canvas.v243.seen';
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
const clean=value=>String(value||'').replace(/\s+/g,' ').trim();
const itemId=item=>`${Math.max(0,Number(item?.session_id||0))}:${String(item?.key||'')}`;
const refId=(sessionId,key)=>`${Math.max(0,Number(sessionId||0))}:${String(key||'')}`;

let canvas=null,backdrop=null,button=null,badge=null,current=null,library=[],observer=null;
let seen=new Set(),seenLoaded=false,summaryToken=0;

const proof=window.STONEFELLOW_TRANSCRIPTION_CANVAS_V243={
  build:BUILD,loaded:true,opens:0,newRecordingOpens:0,audioErrors:0,lastError:'',
};

function recordingApi(){return window.STONEFELLOW_ARTIST_RECORDINGS_V198?.api||null;}
async function waitForRecordingApi(timeout=5000){
  const started=Date.now();
  while(Date.now()-started<timeout){
    const api=recordingApi();
    if(api)return api;
    await new Promise(resolve=>setTimeout(resolve,40));
  }
  throw new Error('Recording library is still loading.');
}
function formatDuration(ms){
  const total=Math.max(0,Math.round(Number(ms||0)/1000));
  const h=Math.floor(total/3600),m=Math.floor((total%3600)/60),s=total%60;
  return h?`${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`:`${m}:${String(s).padStart(2,'0')}`;
}
function formatDate(value){
  const date=new Date(String(value||'').replace(' ','T'));
  return Number.isFinite(date.getTime())?date.toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}):'';
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
function saveSeen(){
  try{localStorage.setItem(SEEN_KEY,JSON.stringify([...seen].slice(-800)));}catch(_e){}
}
function markSeen(item){const id=itemId(item);if(id&&!seen.has(id)){seen.add(id);saveSeen();}updateBadge();}
function unseenItems(){return library.filter(item=>!seen.has(itemId(item)));}

function ensureButton(){
  if(button?.isConnected)return;
  const actions=document.querySelector('.chat-topbar-actions');
  if(!actions)return;
  button=document.createElement('button');
  button.type='button';
  button.className='chat-transcription-canvas-button';
  button.setAttribute('aria-label','Open Transcription Activity');
  button.setAttribute('aria-expanded','false');
  button.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3.5h7l3 3V20H7z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M14 3.5V7h3M9.5 11h5M9.5 14h5M9.5 17h3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg><em hidden>0</em>';
  badge=button.querySelector('em');
  const profileActivity=actions.querySelector('.chat-profile-activity-button');
  const notificationMenu=document.getElementById('chatNotificationMenu');
  actions.insertBefore(button,profileActivity||notificationMenu||document.getElementById('chatProfileMenu')||null);
  button.addEventListener('click',()=>void openLatest());
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
  backdrop=document.createElement('div');
  backdrop.className='transcription-canvas-backdrop';
  backdrop.hidden=true;
  canvas=document.createElement('aside');
  canvas.id='chatTranscriptionCanvas';
  canvas.className='chat-transcription-canvas-v243';
  canvas.hidden=true;
  canvas.setAttribute('aria-label','Transcription Activity');
  canvas.innerHTML=`<header><div><small>Artist Listening</small><strong>Transcription Activity</strong></div><button type="button" data-transcription-close aria-label="Close">×</button></header><div class="transcription-canvas-body" data-transcription-body><div class="transcription-canvas-empty">Loading recordings…</div></div>`;
  document.body.append(backdrop,canvas);
  backdrop.addEventListener('click',closeCanvas);
  canvas.querySelector('[data-transcription-close]')?.addEventListener('click',closeCanvas);
  canvas.addEventListener('click',handleCanvasClick);
  canvas.addEventListener('play',handleCanvasPlay,true);
}
function openShell(){
  ensureButton();ensureCanvas();
  canvas.hidden=false;backdrop.hidden=false;
  requestAnimationFrame(()=>{canvas?.classList.add('open');backdrop?.classList.add('open');});
  button?.setAttribute('aria-expanded','true');
  document.body.classList.add('transcription-canvas-open');
  proof.opens++;
}
function closeCanvas(){
  if(!canvas)return;
  canvas.classList.remove('open');backdrop?.classList.remove('open');
  button?.setAttribute('aria-expanded','false');
  document.body.classList.remove('transcription-canvas-open');
  setTimeout(()=>{if(canvas&&!canvas.classList.contains('open')){canvas.hidden=true;if(backdrop)backdrop.hidden=true;}},180);
}
function metaLine(item){
  const association=clean(item?.association?.label||'');
  return [formatDate(item?.created_at),formatDuration(item?.duration_ms),association&&association.toLowerCase()!=='unassigned'?association:''].filter(Boolean).join(' · ');
}
function recentList(){
  const rows=library.slice(0,10);
  if(!rows.length)return '<div class="transcription-canvas-empty">No retained recordings yet.</div>';
  return `<div class="transcription-canvas-recent"><div class="transcription-canvas-section-title"><span>Recent recordings</span><strong>${library.length}</strong></div>${rows.map(item=>`<button type="button" class="transcription-canvas-row${current&&itemId(current)===itemId(item)?' active':''}" data-transcription-session="${Number(item.session_id||0)}" data-transcription-key="${esc(item.key||'')}"><span><strong>${esc(item.name||'Recording')}</strong><small>${esc(clean(item.session_title||'Transcription'))}</small></span><em>${esc(formatDuration(item.duration_ms))}</em></button>`).join('')}</div>`;
}
function renderCurrent(item,{isNew=false}={}){
  ensureCanvas();current=item||null;
  const body=canvas.querySelector('[data-transcription-body]');
  if(!body)return;
  if(!item){body.innerHTML=recentList();return;}
  const downloadName=clean(item.name||'recording').replace(/[^a-z0-9_-]+/gi,'-')||'recording';
  const excerpt=clean(item.transcript_excerpt||'');
  body.innerHTML=`<section class="transcription-canvas-current" data-transcription-current data-session="${Number(item.session_id||0)}" data-key="${esc(item.key||'')}">${isNew?'<div class="transcription-canvas-kicker">NEW TRANSCRIPTION</div>':''}<div class="transcription-canvas-title"><div><h2>${esc(item.name||'Recording')}</h2><p>${esc(clean(item.session_title||'Transcription'))}</p></div><button type="button" data-transcription-favorite>${item.favorite?'★':'☆'}</button></div><div class="transcription-canvas-meta">${esc(metaLine(item)||'Retained recording')}</div><audio class="transcription-canvas-audio" data-transcription-audio controls preload="metadata" src="${esc(item.url||'')}"></audio><div class="transcription-canvas-audio-status" data-transcription-audio-status></div><article class="transcription-canvas-summary"><small>Agent summary</small><div data-transcription-summary>${excerpt?esc(excerpt):'Checking for transcript analysis…'}</div></article>${excerpt?`<article class="transcription-canvas-preview"><small>Transcript preview</small><p>${esc(excerpt)}</p></article>`:''}<div class="transcription-canvas-actions"><button type="button" data-transcription-rename>Rename</button><a href="${esc(item.url||'#')}" download="${esc(downloadName)}">Download</a><a href="${esc(item.open_url||'#')}">Open transcript ↗</a><button type="button" class="danger" data-transcription-delete>Delete</button></div></section>${recentList()}`;
  bindAudioStatus(body.querySelector('[data-transcription-audio]'));
  void hydrateSummary(item);
}
function bindAudioStatus(audio){
  if(!audio)return;
  const host=canvas.querySelector('[data-transcription-audio-status]');
  const ready=()=>{if(host)host.textContent=Number.isFinite(audio.duration)&&audio.duration>0?`Audio ready · ${formatDuration(audio.duration*1000)}`:'Audio ready';};
  const error=()=>{proof.audioErrors++;if(host)host.textContent='Recording audio could not be loaded. Open the transcript if the file was removed.';};
  audio.addEventListener('loadedmetadata',ready,{once:true});
  audio.addEventListener('durationchange',ready,{once:true});
  audio.addEventListener('error',error,{once:true});
}
async function hydrateSummary(item){
  const token=++summaryToken;
  const host=canvas?.querySelector('[data-transcription-summary]');
  if(!host)return;
  let summary='';
  try{
    const url=new URL(intelligenceEndpoint,location.href);
    url.searchParams.set('action','status');
    url.searchParams.set('session_id',String(Number(item.session_id||0)));
    const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
    const data=await response.json().catch(()=>null);
    summary=clean(data?.master?.analysis?.summary||'');
  }catch(_e){}
  if(token!==summaryToken||!current||itemId(current)!==itemId(item))return;
  host.textContent=summary||clean(item.transcript_excerpt||'')||'No Agent Summary has been generated for this transcription yet.';
  host.closest('.transcription-canvas-summary')?.classList.toggle('has-ai-summary',Boolean(summary));
}
async function refreshLibrary(force=true){
  const api=await waitForRecordingApi();
  const state=force?await api.refresh():api.getState();
  library=Array.isArray(state?.library)?state.library:[];
  updateBadge();
  return library;
}
async function openItem(item,options={}){
  if(!item)return;
  openShell();
  markSeen(item);
  renderCurrent(item,options);
  try{const api=await waitForRecordingApi();await api.select({sessionId:item.session_id,key:item.key});}catch(_e){}
  if(options.isNew)proof.newRecordingOpens++;
  window.dispatchEvent(new CustomEvent('stonefellow:transcription-canvas-opened',{detail:{sessionId:Number(item.session_id||0),key:String(item.key||''),isNew:!!options.isNew}}));
}
async function openRef(sessionId,key,options={}){
  await refreshLibrary(true);
  const id=refId(sessionId,key);
  const item=library.find(row=>itemId(row)===id)||null;
  if(item)await openItem(item,options);
}
async function openLatest(){
  try{await refreshLibrary(true);if(library[0])await openItem(library[0]);else{openShell();renderCurrent(null);}}catch(error){proof.lastError=String(error?.message||error);openShell();const body=canvas.querySelector('[data-transcription-body]');if(body)body.innerHTML=`<div class="transcription-canvas-empty">${esc(proof.lastError)}</div>`;}
}
async function handleCanvasClick(event){
  const row=event.target.closest('[data-transcription-session][data-transcription-key]');
  if(row){await openRef(row.dataset.transcriptionSession,row.dataset.transcriptionKey);return;}
  if(!current)return;
  const api=await waitForRecordingApi().catch(()=>null);if(!api)return;
  if(event.target.closest('[data-transcription-favorite]')){const updated=await api.favorite({sessionId:current.session_id,key:current.key,favorite:!current.favorite});await refreshLibrary(true);current=updated||library.find(x=>itemId(x)===itemId(current))||current;renderCurrent(current);return;}
  if(event.target.closest('[data-transcription-rename]')){const name=window.prompt('Rename this recording:',String(current.name||'Recording'));if(name===null||!clean(name))return;const updated=await api.rename({sessionId:current.session_id,key:current.key,name:clean(name)});await refreshLibrary(true);current=updated||library.find(x=>itemId(x)===itemId(current))||current;renderCurrent(current);return;}
  if(event.target.closest('[data-transcription-delete]')){if(!window.confirm(`Delete “${current.name||'this recording'}”? The transcript will remain.`))return;await api.delete({sessionId:current.session_id,key:current.key});await refreshLibrary(true);current=library[0]||null;renderCurrent(current);return;}
}
function handleCanvasPlay(event){
  const audio=event.target.closest?.('[data-transcription-audio]');
  if(!audio||!current)return;
  document.querySelectorAll('audio[data-v206-recording-audio],audio[data-v198-recording-audio],audio.chat-transcription-audio,[data-listening-workspace-recording-audio]').forEach(other=>{if(other!==audio){try{other.pause();}catch(_e){}}});
  const api=recordingApi();if(api)void api.select({sessionId:current.session_id,key:current.key}).catch(()=>{});
}
function detectNewCards(root=document){
  const candidates=[];
  if(root.nodeType===1&&root.matches?.('.sf-v206-recording-message.is-new-recording'))candidates.push(root);
  if(root.querySelectorAll)candidates.push(...root.querySelectorAll('.sf-v206-recording-message.is-new-recording'));
  const message=candidates.at(-1);if(!message||message.dataset.transcriptionCanvasHandled==='1')return;
  const card=message.querySelector('[data-v206-recording-card]');if(!card)return;
  message.dataset.transcriptionCanvasHandled='1';
  const sessionId=Number(card.dataset.v206Session||0),key=String(card.dataset.v206Key||'');
  if(sessionId>0&&key){
    void openRef(sessionId,key,{isNew:true}).catch(error=>{proof.lastError=String(error?.message||error);});
  }
}
function bindInlineCards(){
  document.addEventListener('click',event=>{
    const card=event.target.closest?.('[data-v206-recording-card]');if(!card)return;
    if(event.target.closest('audio,button,a,summary,details,input,select,textarea'))return;
    void openRef(card.dataset.v206Session,card.dataset.v206Key).catch(error=>{proof.lastError=String(error?.message||error);});
  });
}
async function bootstrap(){
  ensureButton();ensureCanvas();bindInlineCards();
  const hadSeen=loadSeen();
  try{await refreshLibrary(true);if(!hadSeen){library.forEach(item=>seen.add(itemId(item)));saveSeen();updateBadge();}}
  catch(error){proof.lastError=String(error?.message||error);}
  detectNewCards(thread);
  observer=new MutationObserver(records=>{for(const record of records){for(const node of record.addedNodes){if(node.nodeType===1)detectNewCards(node);}}});
  observer.observe(thread,{childList:true,subtree:true});
  window.addEventListener('stonefellow:recording-saved',()=>setTimeout(()=>void refreshLibrary(true).then(()=>detectNewCards(thread)).catch(()=>{}),80));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&canvas?.classList.contains('open'))closeCanvas();});
}

window.STONEFELLOW_TRANSCRIPTION_CANVAS={
  open:async(args={})=>{if(args.sessionId&&args.key)return openRef(args.sessionId,args.key,args);return openLatest();},
  close:closeCanvas,
  refresh:async()=>{await refreshLibrary(true);if(current){current=library.find(x=>itemId(x)===itemId(current))||current;renderCurrent(current);}return {library:[...library],current:current?{...current}:null};},
};

window.addEventListener('pagehide',()=>observer?.disconnect(),{once:true});
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>void bootstrap(),{once:true});else void bootstrap();
})();
