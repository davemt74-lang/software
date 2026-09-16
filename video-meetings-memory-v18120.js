(()=>{'use strict';
const boot=window.VP3Meeting;if(!boot||!boot.memoryEndpoint||!boot.isOrganizer||!boot.reviewOnly)return;
const $=(s,r=document)=>r.querySelector(s);const $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
let searching=false,nextCursor=null,lastQuery='';

async function post(action,extra={}){
  const body=new URLSearchParams({action,csrf_token:boot.csrf||'',...extra});
  const res=await fetch(boot.memoryEndpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},credentials:'same-origin',cache:'no-store',body:body.toString()});
  let data={};try{data=await res.json();}catch(_){data={};}
  if(!res.ok||!data.ok)throw new Error(data.error||'Meeting Search & Memory request failed.');return data;
}
function el(tag,className,text){const node=document.createElement(tag);if(className)node.className=className;if(text!==undefined)node.textContent=text;return node;}
function safeHref(path){try{const u=new URL(String(path||''),location.href);return u.origin===location.origin?u.href:'';}catch(_){return '';}}
function activate(){
  $$('.meeting-agent-tab').forEach(node=>node.classList.toggle('active',node.dataset.pane==='memory'));
  $$('.meeting-agent-pane').forEach(node=>node.classList.toggle('active',node.id==='meetingPane-memory'));
}
function categoryLabel(value){return String(value||'').replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());}
function setMemoryStatus(text,kind=''){const node=$('#meetingMemoryStatus');if(!node)return;node.textContent=text||'';node.dataset.kind=kind;}
function renderResults(search,append=false){
  const root=$('#meetingMemoryResults');if(!root)return;if(!append)root.replaceChildren();
  const rows=Array.isArray(search?.results)?search.results:[];
  if(!rows.length&&!append){root.appendChild(el('div','meeting-agent-empty','No finalized meeting memory matched that search.'));return;}
  for(const row of rows){
    const card=el('article','meeting-memory-result');const top=el('div','meeting-memory-result-top');
    top.append(el('span','meeting-memory-category',categoryLabel(row.category)),el('span','meeting-memory-date',String(row.meeting_when_utc||'')));
    const title=el('strong','meeting-memory-title',String(row.meeting_title||'Meeting'));const text=el('p','meeting-memory-text',String(row.text||''));card.append(top,title,text);
    const meta=[];if(row.owner)meta.push('Owner: '+row.owner);if(row.due_date)meta.push('Due: '+row.due_date);if(row.status)meta.push('State: '+row.status);
    if(meta.length)card.appendChild(el('div','meeting-memory-meta',meta.join(' · ')));
    const foot=el('div','meeting-memory-result-foot');const href=safeHref(row.review_path);if(href){const link=el('a','','Open meeting →');link.href=href;foot.appendChild(link);}
    const p=row.provenance||{},source=String(p.source_hash||'').slice(0,10),index=String(p.index_hash||'').slice(0,10);
    foot.appendChild(el('span','meeting-memory-provenance',(source&&index)?`Source ${source} · Index ${index}`:'Finalized meeting memory'));card.appendChild(foot);root.appendChild(card);
  }
}
async function search(reset=true){
  if(searching)return;const input=$('#meetingMemoryQuery');const query=String(input?.value||'').trim();if(!query){setMemoryStatus('Enter what you remember or what you need to find.','waiting');return;}
  searching=true;const button=$('#meetingMemorySearch');if(button)button.disabled=true;
  try{
    const cursor=reset?0:(nextCursor??0);const data=await post('search',{query,limit:'12',cursor:String(cursor)});lastQuery=query;nextCursor=data.search?.next_cursor??null;renderResults(data.search,!reset);
    const count=Number(data.search?.matched_count||0),scanned=Number(data.search?.scanned_meetings||0),windowLabel=String(data.search?.date_window?.label||'');
    setMemoryStatus(`${count} source-backed match${count===1?'':'es'} across ${scanned} recent finalized meeting${scanned===1?'':'s'}${windowLabel?' · '+windowLabel:''}.`,'ready');
    const more=$('#meetingMemoryMore');if(more){more.hidden=nextCursor===null;more.disabled=false;}
  }catch(err){setMemoryStatus(err.message||'Meeting memory search failed.','error');if(reset)renderResults({results:[]},false);}finally{searching=false;if(button)button.disabled=false;}
}
async function refreshCurrent(silent=false){
  try{const data=await post('refresh',{meeting:boot.meeting||''});const m=data.memory||{};if(!silent)setMemoryStatus(`Current meeting memory indexed · ${Number(m.entry_count||0)} safe entries.`,'ready');return true;}
  catch(err){if(!silent)setMemoryStatus(err.message||'Finalize this meeting before indexing it.','waiting');return false;}
}
function build(){
  const tabs=$('.meeting-agent-tabs'),content=$('.meeting-agent-content');if(!tabs||!content||$('#meetingPane-memory'))return;
  const tab=el('button','meeting-agent-tab','Memory');tab.type='button';tab.dataset.pane='memory';tab.addEventListener('click',activate);tabs.appendChild(tab);
  const pane=el('section','meeting-agent-pane');pane.id='meetingPane-memory';
  const intro=el('div','meeting-memory-intro');intro.append(el('strong','','Meeting Search & Memory'),el('p','','Search finalized meeting decisions, commitments, actions, topics, objectives, participant names, and verified or pending follow-through. Raw transcripts and private HomeServer context are not indexed.'));
  const form=el('div','meeting-memory-search');const input=el('input');input.id='meetingMemoryQuery';input.type='search';input.maxLength=300;input.placeholder='What did we decide about pricing last month?';input.autocomplete='off';
  const button=el('button','','Search');button.id='meetingMemorySearch';button.type='button';button.addEventListener('click',()=>search(true));input.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();search(true);}});form.append(input,button);
  const status=el('div','meeting-memory-status','Indexing current finalized meeting memory…');status.id='meetingMemoryStatus';
  const results=el('div','meeting-memory-results');results.id='meetingMemoryResults';results.appendChild(el('div','meeting-agent-empty','Search by decision, topic, person, action, or time window.'));
  const more=el('button','meeting-memory-more','More results');more.id='meetingMemoryMore';more.type='button';more.hidden=true;more.addEventListener('click',()=>{if(lastQuery&&input.value.trim()!==lastQuery)input.value=lastQuery;search(false);});
  pane.append(intro,form,status,results,more);content.appendChild(pane);
  const style=document.createElement('style');style.textContent=`
    .meeting-memory-intro{padding:4px 0 16px}.meeting-memory-intro strong{display:block;font-size:15px}.meeting-memory-intro p{margin:7px 0 0;opacity:.72;line-height:1.5}
    .meeting-memory-search{display:flex;gap:8px;margin-bottom:10px}.meeting-memory-search input{flex:1;min-width:0;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.055);color:inherit;border-radius:10px;padding:11px 12px}.meeting-memory-search button,.meeting-memory-more{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
    .meeting-memory-status{font-size:12px;opacity:.72;margin:4px 0 12px}.meeting-memory-status[data-kind="error"]{opacity:1}.meeting-memory-results{display:grid;gap:9px}.meeting-memory-result{border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:12px;background:rgba(255,255,255,.035)}
    .meeting-memory-result-top,.meeting-memory-result-foot{display:flex;align-items:center;justify-content:space-between;gap:10px}.meeting-memory-category{font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:800;opacity:.72}.meeting-memory-date,.meeting-memory-provenance{font-size:10px;opacity:.52}.meeting-memory-title{display:block;margin-top:8px}.meeting-memory-text{margin:6px 0 0;line-height:1.48}.meeting-memory-meta{font-size:11px;opacity:.7;margin-top:8px}.meeting-memory-result-foot{margin-top:10px}.meeting-memory-result-foot a{font-size:11px;font-weight:750}.meeting-memory-more{margin-top:12px}
    @media(max-width:720px){.meeting-memory-search{flex-direction:column}.meeting-memory-result-top,.meeting-memory-result-foot{align-items:flex-start;flex-direction:column}}
  `;document.head.appendChild(style);
}
build();refreshCurrent(false).then(ok=>{if(!ok)setTimeout(()=>refreshCurrent(true),5000);});
})();