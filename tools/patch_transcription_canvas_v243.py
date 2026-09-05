#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def replace_once(path: str, old: str, new: str) -> None:
    target = ROOT / path
    text = target.read_text(encoding='utf-8')
    if new in text:
        return
    if old not in text:
        raise SystemExit(f'Expected source fragment not found in {path}: {old[:120]!r}')
    target.write_text(text.replace(old, new, 1), encoding='utf-8')


# Restore the canonical API path already emitted by every retained-recording URL.
(ROOT / 'api/artist-listening.php').write_text("""<?php
declare(strict_types=1);

/**
 * Canonical Artist Listening API route.
 *
 * Retained recording metadata has always emitted /api/artist-listening.php.
 * The recovered repository retained the versioned v172 implementation but lost
 * this canonical entrypoint, leaving otherwise valid audio cards pointed at a
 * 404. Keep one route owner and let the versioned implementation handle auth,
 * upload, transcript actions, byte ranges, and private recording delivery.
 */
require __DIR__ . '/artist-listening-v172.php';
""", encoding='utf-8')

canvas_js = r"""(() => {
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
"""
(ROOT / 'chat-transcription-canvas.js').write_text(canvas_js, encoding='utf-8')

canvas_css = """.chat-transcription-canvas-button{position:relative;width:34px;height:34px;display:grid;place-items:center;padding:0;border:1px solid #e5e7eb;border-radius:50%;background:#fff;color:#4b5563;cursor:pointer}.chat-transcription-canvas-button:hover,.chat-transcription-canvas-button:focus-visible{border-color:#d1d5db;background:#f9fafb;color:#111827;outline:none}.chat-transcription-canvas-button>svg{width:16px;height:16px}.chat-transcription-canvas-button>em{position:absolute;top:-5px;right:-6px;display:grid;place-items:center;min-width:17px;height:17px;padding:0 4px;border:2px solid #fff;border-radius:999px;background:#171717;color:#fff;font:800 8px/1 system-ui;font-style:normal}.chat-transcription-canvas-button>em[hidden]{display:none!important}
.transcription-canvas-backdrop{position:fixed;inset:0;z-index:1210;background:rgba(20,20,20,.22);opacity:0;transition:opacity .18s ease}.transcription-canvas-backdrop.open{opacity:1}.chat-transcription-canvas-v243{position:fixed;top:0;right:0;bottom:0;z-index:1220;width:min(520px,96vw);display:grid;grid-template-rows:auto minmax(0,1fr);background:#fff;color:#171717;border-left:1px solid #e1e1e1;box-shadow:-22px 0 52px rgba(0,0,0,.12);transform:translateX(102%);transition:transform .18s ease;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.chat-transcription-canvas-v243.open{transform:translateX(0)}.chat-transcription-canvas-v243[hidden],.transcription-canvas-backdrop[hidden]{display:none!important}.chat-transcription-canvas-v243>header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;border-bottom:1px solid #e8e8e8;background:#fff}.chat-transcription-canvas-v243>header>div{display:grid;gap:3px}.chat-transcription-canvas-v243>header small{color:#777;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.chat-transcription-canvas-v243>header strong{font-size:19px}.chat-transcription-canvas-v243>header button{width:34px;height:34px;border:1px solid #ddd;border-radius:9px;background:#fff;color:#222;font-size:22px;cursor:pointer}.transcription-canvas-body{min-height:0;overflow-y:auto;padding:18px 18px 28px;background:#fff}.transcription-canvas-current{display:grid;gap:12px}.transcription-canvas-kicker{font-size:9px;font-weight:850;letter-spacing:.1em;color:#6b7280;text-transform:uppercase}.transcription-canvas-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.transcription-canvas-title h2{margin:0;color:#111827;font-size:21px;line-height:1.15}.transcription-canvas-title p{margin:4px 0 0;color:#6b7280;font-size:11px}.transcription-canvas-title>button{width:34px;height:34px;border:1px solid #ddd;border-radius:9px;background:#fff;color:#222;font-size:18px;cursor:pointer}.transcription-canvas-meta{color:#6b7280;font-size:10px}.transcription-canvas-audio{display:block;width:100%;height:38px}.transcription-canvas-audio-status{min-height:14px;color:#6b7280;font-size:10px}.transcription-canvas-summary,.transcription-canvas-preview{padding:13px 14px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}.transcription-canvas-summary.has-ai-summary{border-color:#d1d5db;background:#f9fafb}.transcription-canvas-summary small,.transcription-canvas-preview small{display:block;margin-bottom:6px;color:#6b7280;font-size:9px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}.transcription-canvas-summary div,.transcription-canvas-preview p{margin:0;color:#222;font-size:12px;line-height:1.55;white-space:pre-wrap}.transcription-canvas-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.transcription-canvas-actions button,.transcription-canvas-actions a{min-height:32px;display:inline-flex;align-items:center;padding:0 10px;border:1px solid #d9d9d9;border-radius:8px;background:#fff;color:#222;font:700 10px/1 system-ui;text-decoration:none;cursor:pointer}.transcription-canvas-actions .danger{color:#991b1b}.transcription-canvas-recent{display:grid;gap:6px;margin-top:22px;padding-top:16px;border-top:1px solid #ededed}.transcription-canvas-section-title{display:flex;align-items:center;justify-content:space-between;padding:0 2px 4px;color:#6b7280;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}.transcription-canvas-section-title strong{color:#111827}.transcription-canvas-row{width:100%;display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:12px;padding:10px 11px;border:1px solid #e7e7e7;border-radius:10px;background:#fff;color:#222;text-align:left;cursor:pointer}.transcription-canvas-row:hover,.transcription-canvas-row.active{border-color:#cfd3d8;background:#f9fafb}.transcription-canvas-row>span{display:grid;min-width:0;gap:3px}.transcription-canvas-row strong{overflow:hidden;font-size:12px;text-overflow:ellipsis;white-space:nowrap}.transcription-canvas-row small{overflow:hidden;color:#777;font-size:9px;text-overflow:ellipsis;white-space:nowrap}.transcription-canvas-row em{color:#6b7280;font-size:9px;font-style:normal}.transcription-canvas-empty{padding:38px 18px;text-align:center;color:#777;font-size:12px}.transcription-canvas-open{overflow:hidden}
@media(max-width:720px){.chat-transcription-canvas-button{width:34px;height:34px}.chat-transcription-canvas-v243{width:100vw}.transcription-canvas-body{padding:14px 12px 24px}.transcription-canvas-actions{display:grid;grid-template-columns:1fr 1fr}.transcription-canvas-actions button,.transcription-canvas-actions a{justify-content:center}.transcription-canvas-title h2{font-size:18px}}
"""
(ROOT / 'chat-transcription-canvas.css').write_text(canvas_css, encoding='utf-8')

# Chat loads the canonical canvas only for accounts that already have Artist Listening access.
replace_once(
    'chat.php',
    "$recordingPersistenceBuild = 'chat-recordings-v242-20260902';\n",
    "$recordingPersistenceBuild = 'chat-recordings-v242-20260902';\n$transcriptionCanvasBuild = 'chat-transcription-canvas-v243-20260905';\n",
)
replace_once(
    'chat.php',
    "        . '<script data-chat-recordings-v242 data-recording-persistence-build=\"' . e($recordingPersistenceBuild) . '\" src=\"' . e(url('/chat-recordings-v242.js?v=' . $recordingPersistenceBuild)) . '\"></script>'\n        . '<script data-artist-recordings-v198 data-recording-ui-build=\"' . e($recordingUiBuild) . '\" src=\"' . e(url('/artist-listening-recordings.js?v=' . $recordingUiBuild)) . '\"></script>'\n",
    "        . '<link rel=\"stylesheet\" data-chat-transcription-canvas href=\"' . e(url('/chat-transcription-canvas.css?v=' . $transcriptionCanvasBuild)) . '\">'\n        . '<script data-chat-recordings-v242 data-recording-persistence-build=\"' . e($recordingPersistenceBuild) . '\" src=\"' . e(url('/chat-recordings-v242.js?v=' . $recordingPersistenceBuild)) . '\"></script>'\n        . '<script data-artist-recordings-v198 data-recording-ui-build=\"' . e($recordingUiBuild) . '\" src=\"' . e(url('/artist-listening-recordings.js?v=' . $recordingUiBuild)) . '\"></script>'\n        . '<script data-chat-transcription-canvas data-transcription-canvas-build=\"' . e($transcriptionCanvasBuild) . '\" src=\"' . e(url('/chat-transcription-canvas.js?v=' . $transcriptionCanvasBuild)) . '\"></script>'\n",
)
replace_once(
    'chat.php',
    'data-recording-persistence-build=\"\' . e($recordingPersistenceBuild) . \'\" data-agent-theme-build=',
    'data-recording-persistence-build=\"\' . e($recordingPersistenceBuild) . \'\" data-transcription-canvas-build=\"\' . e($transcriptionCanvasBuild) . \'\" data-agent-theme-build=',
)

contract = r"""import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
const canonical=read('api/artist-listening.php');
const versioned=read('api/artist-listening-v172.php');
const listening=read('includes/artist-listening.php');
const recordings=read('api/artist-recordings-v198.php');
const chat=read('chat.php');
const canvas=read('chat-transcription-canvas.js');
const css=read('chat-transcription-canvas.css');

assert.ok(canonical.includes("require __DIR__ . '/artist-listening-v172.php'"),'canonical Artist Listening route must delegate to the surviving v172 implementation');
assert.ok(versioned.includes("if ($action === 'recording')"),'versioned Artist Listening API must own recording delivery');
assert.ok(versioned.includes('artist_listening_v197_stream_recording'),'recording delivery must call the private recording stream owner');
assert.ok(listening.includes("url('/api/artist-listening.php?action=recording"),'retained recording metadata must point at the canonical API route');
assert.ok(recordings.includes("url('/api/artist-listening.php?action=recording"),'recording library cards must point at the canonical API route');
assert.ok(listening.includes("header('Accept-Ranges: bytes')"),'recording stream must support browser byte ranges');
assert.ok(listening.includes("header('Content-Range: bytes '"),'partial recording responses must emit Content-Range');
assert.ok(listening.includes("header('Content-Type: ' . (string)$recording['mime_type'])"),'recording stream must preserve the stored browser audio MIME');
assert.ok(listening.includes("fopen($path, 'rb')"),'recording stream must read the retained private audio file');

assert.ok(chat.includes("$transcriptionCanvasBuild = 'chat-transcription-canvas-v243-20260905'"),'Chat must own a cache-busted transcription canvas build');
assert.ok(chat.includes('data-chat-transcription-canvas'),'Chat must load the canonical transcription canvas runtime');
assert.ok(chat.includes('/chat-transcription-canvas.js?v='),'Chat must load the transcription canvas JS under Artist Listening access');
assert.ok(chat.includes('/chat-transcription-canvas.css?v='),'Chat must load the transcription canvas stylesheet');
assert.ok(chat.includes('#chatRecordingsCanvas,.chat-recordings-canvas'),'the obsolete recovered recording canvas must remain suppressed');
assert.ok(!canvas.includes('chatRecordingsCanvas'),'new canvas must not reuse the obsolete recovered canvas identity');
assert.ok(canvas.includes("canvas.id='chatTranscriptionCanvas'"),'new canvas must have one canonical identity');
assert.ok(canvas.includes('STONEFELLOW_ARTIST_RECORDINGS_V198?.api'),'canvas must consume the existing recording library/action owner instead of duplicating its API');
assert.ok(canvas.includes('.sf-v206-recording-message.is-new-recording'),'new recording notices must open the transcription canvas');
assert.ok(canvas.includes('new MutationObserver'),'canvas must observe newly inserted Agent Chat recording results');
assert.ok(canvas.includes("url.searchParams.set('action','status')"),'canvas must query the existing transcript intelligence status');
assert.ok(canvas.includes('data?.master?.analysis?.summary'),'canvas must surface the saved Agent/AI transcript summary when available');
assert.ok(canvas.includes('transcript_excerpt'),'canvas must fall back to the transcript preview without inventing a summary');
assert.ok(canvas.includes('data-transcription-audio controls preload=\\"metadata\\"'),'canvas must render the retained recording as playable audio');
assert.ok(canvas.includes('window.STONEFELLOW_TRANSCRIPTION_CANVAS'),'canvas must expose one integration API to Agent Chat');
assert.ok(css.includes('background:#fff'),'transcription canvas must use the canonical light surface');
assert.ok(!css.includes('#171411'),'transcription canvas must not reintroduce the recovered brown player surface');
console.log('CHAT_TRANSCRIPTION_CANVAS_V243=PASS');
"""
(ROOT / 'tests/chat-transcription-canvas-v243.mjs').write_text(contract, encoding='utf-8')

replace_once(
    'tools/run_recovery_baseline.py',
    "    'tests/chat-recordings-theme-v242.mjs',\n",
    "    'tests/chat-recordings-theme-v242.mjs',\n    'tests/chat-transcription-canvas-v243.mjs',\n",
)

# The workflow and patch helper are scaffolding only; keep them out of the PR.
(ROOT / 'tools/patch_transcription_canvas_v243.py').unlink(missing_ok=True)
(ROOT / '.github/workflows/patch-transcription-canvas-v243.yml').unlink(missing_ok=True)
print('TRANSCRIPTION_CANVAS_PATCH=PASS')
