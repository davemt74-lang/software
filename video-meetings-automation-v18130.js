(()=>{'use strict';
const boot=window.VP3Meeting;if(!boot||!boot.isOrganizer)return;
const $=(s,r=document)=>r.querySelector(s);const $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
let prep=null,loading=false;

function endpoint(){
  if(boot.automationEndpoint)return String(boot.automationEndpoint);
  const intelligence=String(boot.intelligenceEndpoint||'');
  return intelligence.replace(/video-meeting-intelligence\.php(?:\?.*)?$/,'video-meeting-automation.php')||'/api/video-meeting-automation.php';
}
async function post(){
  const body=new URLSearchParams({action:'prep',meeting:boot.meeting||'',csrf_token:boot.csrf||''});
  const res=await fetch(endpoint(),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},credentials:'same-origin',cache:'no-store',body:body.toString()});
  let data={};try{data=await res.json();}catch(_){data={};}
  if(!res.ok||!data.ok)throw new Error(data.error||'Meeting preparation could not load.');return data.prep||{};
}
function el(tag,className,text){const node=document.createElement(tag);if(className)node.className=className;if(text!==undefined)node.textContent=text;return node;}
function safeHref(path){try{const u=new URL(String(path||''),location.href);return u.origin===location.origin?u.href:'';}catch(_){return '';}}
function activate(){
  $$('.meeting-agent-tab').forEach(node=>node.classList.toggle('active',node.dataset.pane==='prep'));
  $$('.meeting-agent-pane').forEach(node=>node.classList.toggle('active',node.id==='meetingPane-prep'));
}
function categoryLabel(value){return String(value||'').replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());}
function renderRows(root,rows,emptyText){
  root.replaceChildren();const list=Array.isArray(rows)?rows:[];
  if(!list.length){root.appendChild(el('div','meeting-agent-empty',emptyText));return;}
  for(const row of list){
    const card=el('article','meeting-prep-item');const top=el('div','meeting-prep-top');
    top.append(el('span','meeting-prep-category',categoryLabel(row.category)),el('span','meeting-prep-date',String(row.meeting_when_utc||'')));
    card.append(top,el('strong','meeting-prep-title',String(row.meeting_title||'Meeting')),el('p','meeting-prep-text',String(row.text||'')));
    const meta=[];if(row.owner)meta.push('Owner: '+row.owner);if(row.due_date)meta.push('Due: '+row.due_date);if(row.status)meta.push('State: '+row.status);
    if(meta.length)card.appendChild(el('div','meeting-prep-meta',meta.join(' · ')));
    const href=safeHref(row.review_path);if(href){const link=el('a','meeting-prep-source','Open source meeting →');link.href=href;card.appendChild(link);}
    root.appendChild(card);
  }
}
function renderPane(data){
  const pane=$('#meetingPane-prep');if(!pane)return;
  const participants=$('#meetingPrepParticipants');if(participants){const names=Array.isArray(data.meeting?.participants)?data.meeting.participants:[];participants.textContent=names.length?'Preparing with: '+names.join(', '):'No named participants are attached yet.';}
  renderRows($('#meetingPrepCommitments'),data.unresolved_commitments,'No unresolved source-backed commitments matched this meeting.');
  renderRows($('#meetingPrepDecisions'),data.historical_decisions,'No prior source-backed decisions matched this meeting.');
  renderRows($('#meetingPrepContext'),data.relevant_context,'No additional finalized meeting context matched this meeting.');
  const status=$('#meetingPrepStatus');if(status){const count=(data.sources||[]).length;status.textContent=`Prepared automatically from ${count} finalized meeting source${count===1?'':'s'}. Nothing was changed or executed.`;status.dataset.kind='ready';}
}
function renderLobby(data){
  const card=$('.meeting-lobby-card');if(!card||$('#meetingPrepLobby'))return;
  const wrap=el('section','meeting-prep-lobby');wrap.id='meetingPrepLobby';
  const head=el('div','meeting-prep-lobby-head');head.append(el('span','meeting-lobby-kicker','Agent prep'),el('strong','','Before you join'));
  const total=(data.unresolved_commitments?.length||0)+(data.historical_decisions?.length||0)+(data.relevant_context?.length||0);
  const copy=el('p','',total?`${data.unresolved_commitments?.length||0} unresolved commitment${data.unresolved_commitments?.length===1?'':'s'}, ${data.historical_decisions?.length||0} prior decision${data.historical_decisions?.length===1?'':'s'}, and ${data.relevant_context?.length||0} context item${data.relevant_context?.length===1?'':'s'} resurfaced from finalized meeting memory.`:'No matching finalized meeting history was found.');
  const button=el('button','meeting-prep-open','Review preparation');button.type='button';button.addEventListener('click',()=>{activate();$('#meetingAgentPanel')?.scrollIntoView({behavior:'smooth',block:'start'});});
  wrap.append(head,copy,button);card.appendChild(wrap);
}
function buildPane(){
  const tabs=$('.meeting-agent-tabs'),content=$('.meeting-agent-content');if(!tabs||!content||$('#meetingPane-prep'))return;
  const tab=el('button','meeting-agent-tab','Prep');tab.type='button';tab.dataset.pane='prep';tab.dataset.meetingPrivate='';tab.addEventListener('click',activate);tabs.prepend(tab);
  const pane=el('section','meeting-agent-pane');pane.id='meetingPane-prep';pane.dataset.meetingPrivate='';
  const intro=el('div','meeting-prep-intro');intro.append(el('strong','','Meeting Intelligence Prep'),el('p','','Automatically resurfaces finalized decisions, unresolved commitments, and relevant context for this meeting. Source-backed and read-only: no task, CRM, calendar, email, Agent Brain, or tool action runs here.'));
  const participants=el('div','meeting-prep-participants','Preparing meeting context…');participants.id='meetingPrepParticipants';
  const status=el('div','meeting-prep-status','Loading finalized meeting memory…');status.id='meetingPrepStatus';
  const section=(title,id)=>{const wrap=el('section','meeting-prep-section');wrap.appendChild(el('h3','',title));const body=el('div','meeting-prep-stack');body.id=id;body.appendChild(el('div','meeting-agent-empty','Loading…'));wrap.appendChild(body);return wrap;};
  pane.append(intro,participants,status,section('Unresolved commitments','meetingPrepCommitments'),section('Historical decisions','meetingPrepDecisions'),section('Relevant context','meetingPrepContext'));content.prepend(pane);
}
function installStyle(){if($('#meetingPrepStyle18130'))return;const style=el('style');style.id='meetingPrepStyle18130';style.textContent=`
.meeting-prep-intro{padding:4px 0 12px}.meeting-prep-intro strong{display:block;font-size:15px}.meeting-prep-intro p{margin:7px 0 0;opacity:.72;line-height:1.5}.meeting-prep-participants,.meeting-prep-status{font-size:12px;opacity:.72;margin:8px 0}.meeting-prep-status[data-kind="error"]{opacity:1}.meeting-prep-section{margin-top:16px}.meeting-prep-section h3{font-size:12px;text-transform:uppercase;letter-spacing:.08em;opacity:.65;margin:0 0 8px}.meeting-prep-stack{display:grid;gap:8px}.meeting-prep-item{border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:11px;background:rgba(255,255,255,.035)}.meeting-prep-top{display:flex;justify-content:space-between;gap:8px}.meeting-prep-category{font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:800;opacity:.7}.meeting-prep-date{font-size:10px;opacity:.5}.meeting-prep-title{display:block;margin-top:7px}.meeting-prep-text{margin:5px 0 0;line-height:1.48}.meeting-prep-meta{font-size:11px;opacity:.7;margin-top:7px}.meeting-prep-source{display:inline-block;margin-top:8px;font-size:11px;font-weight:750}.meeting-prep-lobby{margin-top:18px;padding:14px;border:1px solid rgba(255,255,255,.12);border-radius:14px;text-align:left;background:rgba(255,255,255,.045)}.meeting-prep-lobby-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.meeting-prep-lobby-head strong{font-size:14px}.meeting-prep-lobby p{margin:8px 0 10px;line-height:1.45;opacity:.76}.meeting-prep-open{border:0;border-radius:10px;padding:9px 12px;font-weight:750;cursor:pointer}@media(max-width:720px){.meeting-prep-top{flex-direction:column}}
`;document.head.appendChild(style);}
function loadAgendaController(){
  if(window.VP3MeetingAgenda18140||document.querySelector('script[data-vp3-meeting-agenda="18140"]'))return Promise.resolve();
  const automationEndpoint=String(boot.automationEndpoint||endpoint());
  boot.agendaEndpoint=boot.agendaEndpoint||automationEndpoint.replace(/video-meeting-automation\.php(?:\?.*)?$/,'video-meeting-agenda.php')||'/api/video-meeting-agenda.php';
  return new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='video-meetings-agenda-v18140.js?v=18140';script.async=true;script.dataset.vp3MeetingAgenda='18140';script.onload=resolve;script.onerror=()=>reject(new Error('Meeting Agenda & Action Orchestration could not load.'));document.head.appendChild(script);});
}
function loadActionsController(){
  if(window.VP3MeetingActions18150||document.querySelector('script[data-vp3-meeting-actions="18150"]'))return Promise.resolve();
  const agendaEndpoint=String(boot.agendaEndpoint||'');
  boot.actionsEndpoint=boot.actionsEndpoint||agendaEndpoint.replace(/video-meeting-agenda\.php(?:\?.*)?$/,'video-meeting-actions.php')||'/api/video-meeting-actions.php';
  return new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='video-meetings-actions-v18150.js?v=18150';script.async=true;script.dataset.vp3MeetingActions='18150';script.onload=resolve;script.onerror=()=>reject(new Error('Meeting Action Execution could not load.'));document.head.appendChild(script);});
}
async function load(){
  if(loading)return;loading=true;buildPane();installStyle();
  try{prep=await post();renderPane(prep);if(!boot.reviewOnly)renderLobby(prep);window.dispatchEvent(new CustomEvent('vp3:meeting-prep-ready',{detail:{prep}}));}
  catch(err){const status=$('#meetingPrepStatus');if(status){status.textContent=err.message||'Meeting preparation is unavailable.';status.dataset.kind='error';}}
  finally{loading=false;}
}
load();
loadAgendaController().then(loadActionsController).catch(()=>{});
window.VP3MeetingAutomation18130={reload:load,getPrep:()=>prep,activate};
})();
