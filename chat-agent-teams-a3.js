/* A3 Cloud Agent Teams: same Chat canvas, no additional app or sidebar. */
(function () {
'use strict';
if (window.__VP3_AGENT_TEAMS_A3__) return;
window.__VP3_AGENT_TEAMS_A3__ = true;
const cfg=window.VP3_AGENT_TEAMS_A3||{}, shell=document.getElementById('chatComposerShell'), form=document.getElementById('chatForm');
if (!shell||!form||!cfg.endpoint||!cfg.csrf) return;
const root=document.createElement('details');
root.className='vp3-agent-teams-a3';
root.setAttribute('data-agent-teams-a3','');
root.innerHTML='<summary><span aria-hidden="true">◉</span> Agent Teams <small>HomeServer missions</small></summary>'+
 '<div class="vp3-teams-body"><p>Read-only research and analysis workers on your connected HomeServer.</p>'+
 '<form class="vp3-teams-create"><label for="vp3MissionObjective">Mission objective</label>'+
 '<div class="vp3-teams-entry"><input id="vp3MissionObjective" name="objective" maxlength="4000" required '+
 'placeholder="Give the team a research objective…" autocomplete="off">'+
 '<button type="submit">Create &amp; run</button></div></form>'+
 '<div class="vp3-teams-status" role="status" aria-live="polite">Open to load missions.</div>'+
 '<div class="vp3-teams-list" aria-label="Recent missions"></div>'+
 '<section class="vp3-teams-detail" aria-label="Selected mission" hidden></section></div>';
shell.insertBefore(root,form);
const status=root.querySelector('.vp3-teams-status'), list=root.querySelector('.vp3-teams-list'),
 detail=root.querySelector('.vp3-teams-detail'), create=root.querySelector('form'), input=create.querySelector('input'),
 submit=create.querySelector('button');
let busy=false,selected='',items=[],lastRefresh=0,inflight=false;
function el(tag,cls,txt){const x=document.createElement(tag);if(cls)x.className=cls;if(txt!==undefined)x.textContent=String(txt);return x;}
function btn(name,action,id,taskId){const x=el('button','',name);x.type='button';x.dataset.action=action;if(id)x.dataset.id=id;if(taskId)x.dataset.taskId=taskId;x.disabled=busy;return x;}
function fmt(value){if(!value)return '—';const raw=String(value).trim();
 const valueUtc=/(Z|[+-]\d\d:\d\d)$/.test(raw)?raw:raw.replace(' ','T')+'Z';
 const parsed=new Date(valueUtc);return Number.isNaN(parsed.getTime())?raw:parsed.toLocaleString();}
function setBusy(value){busy=Boolean(value);submit.disabled=busy;root.querySelectorAll('button[data-action]').forEach(b=>b.disabled=busy);}
function say(message){status.textContent=String(message);}
async function api(action,extra){
 const response=await fetch(String(cfg.endpoint),{method:'POST',credentials:'same-origin',cache:'no-store',
 headers:{'Accept':'application/json','Content-Type':'application/json'},
 body:JSON.stringify(Object.assign({csrf_token:String(cfg.csrf),action:action},extra||{}))});
 let payload;try{payload=await response.json();}catch(_){throw new Error('Mission server response was invalid.');}
 if(!response.ok||!payload||!payload.ok)throw new Error(String(payload&&payload.error||'Mission operation failed.').slice(0,240));
 return payload;
}
function showList(){
 list.replaceChildren();
 if(!items.length){list.appendChild(el('p','vp3-teams-empty','No missions yet.'));return;}
 for(const item of items){
  const row=el('div','vp3-teams-item'),main=el('div','vp3-teams-item-main');
  main.appendChild(el('strong','',item.objective||'Mission'));
  main.appendChild(el('small','',(item.status||'unknown')+' · '+fmt(item.updated_at||item.created_at)+' · '+(item.tasks||[]).length+' workers'));
  row.appendChild(main);row.appendChild(btn(selected===item.id?'Viewing':'View','get',item.id));list.appendChild(row);
 }
}
function showMission(m){
 selected=String(m.id||'');detail.hidden=false;detail.replaceChildren();
 detail.appendChild(el('h3','',m.objective||'Mission'));
 detail.appendChild(el('p','vp3-teams-meta',(m.status||'unknown')+' · '+fmt(m.updated_at||m.created_at)+' · Read-only workers'));
 const buttons=el('div','vp3-teams-actions');
 if(m.status==='planned')buttons.appendChild(btn('Start','start',selected));
 if(m.status==='running')buttons.appendChild(btn('Pause','pause',selected));
 if(m.status==='waiting_review')buttons.appendChild(btn('Resume (rerun interrupted)','resume',selected));
 if(['planned','running','waiting_review'].includes(m.status))buttons.appendChild(btn('Cancel','cancel',selected));
 buttons.appendChild(btn('Refresh','get',selected));detail.appendChild(buttons);
 const workers=el('ol','vp3-teams-workers');
 (m.tasks||[]).forEach(t=>{
  const li=el('li','');li.appendChild(el('strong','',t.title||t.role||'Worker'));
  li.appendChild(el('small','',(t.role||'specialist')+' · '+(t.status||'queued')+' · '+fmt(t.completed_at||t.started_at)));
  if(t.error)li.appendChild(el('p','vp3-teams-error',t.error));
  if(['failed','partial','waiting_review'].includes(m.status)&&['failed','interrupted'].includes(t.status)&&t.error!=='Dependency failed')li.appendChild(btn('Retry worker','retry',selected,t.id));
  if(t.result){const exp=el('details','vp3-teams-result');exp.appendChild(el('summary','','Worker result'));
   exp.appendChild(el('pre','',t.result));li.appendChild(exp);}
  workers.appendChild(li);
 });detail.appendChild(workers);
 if(m.result){const result=el('details','vp3-teams-result');
  result.appendChild(el('summary','','Combined output (not independently verified)'));
  result.appendChild(el('pre','',m.result));detail.appendChild(result);}
 const events=(m.events||[]).slice(-10);
 if(events.length){detail.appendChild(el('h4','','Recent activity'));const history=el('ol','vp3-teams-events');
  events.forEach(e=>history.appendChild(el('li','',fmt(e.created_at)+' · '+(e.kind||'event'))));
  detail.appendChild(history);}
 showList();
}
function renderBrain(body){
 if(!body)return;
 const old=body.querySelector('[data-agent-teams-brain-a3]');if(old)old.remove();
 const section=el('section','chat-activity-section vp3-agent-teams-brain-a3');
 section.setAttribute('data-agent-teams-brain-a3','');
 const header=el('div','chat-activity-section-head');
 const name=el('div','');name.appendChild(el('strong','','HomeServer Agent Teams'));
 name.appendChild(el('span','','Mission execution · '+(lastRefresh?fmt(new Date(lastRefresh).toISOString()):'not yet synchronized')));
 header.appendChild(name);
 const open=btn('Open in Chat','open-team-chat','');open.dataset.action='open-team-chat';open.addEventListener('click',()=>{root.open=true;root.scrollIntoView({block:'nearest'});});header.appendChild(open);
 section.appendChild(header);
 const recent=items.slice(0,5);
 if(!recent.length)section.appendChild(el('p','chat-activity-empty','No HomeServer mission activity is available.'));
 for(const m of recent){
  const row=el('div','vp3-teams-brain-row');
  row.appendChild(el('strong','',m.objective||'Mission'));
  row.appendChild(el('small','',(m.status||'unknown')+' · '+fmt(m.updated_at||m.created_at)+' · '+(m.tasks||[]).length+' workers'));
  section.appendChild(row);
 }
 body.prepend(section);
 if(!inflight&&!busy&&Date.now()-lastRefresh>20000){
  // The canonical Brain drawer can request a fresh mission projection, but
  // it must not block or replace the drawer's own Brain rendering.
  lastRefresh=Date.now();
  load().then(()=>{if(body.isConnected&&body.closest('#chatNotificationDrawer')?.querySelector('[data-notification-tab="brain"].active'))renderBrain(body);})
    .catch(()=>{});
 }
}
window.VP3_AGENT_TEAMS_A3_BRAIN=renderBrain;
async function load(){
 if(inflight)return;
 inflight=true;
 try{
  const result=await api('list');items=Array.isArray(result.items)?result.items:[];lastRefresh=Date.now();showList();
  say('HomeServer connected · '+items.length+' recent mission'+(items.length===1?'':'s')+'.');
  if(selected&&items.some(x=>x.id===selected)){const current=await api('get',{mission_id:selected});if(current.mission)showMission(current.mission);}
 }finally{inflight=false;}
}
async function operation(action,id,taskId){
 if(action==='open-team-chat'){root.open=true;root.scrollIntoView({block:'nearest'});return;}
 if(busy)return;
 if(action==='resume'&&!window.confirm('Resume and rerun interrupted model work?'))return;
 if(action==='retry'&&!window.confirm('Retry this worker? The model request may use additional tokens.'))return;
 setBusy(true);
 try{const payload={mission_id:id};if(action==='resume')payload.allow_reexecution=true;
  if(action==='retry')payload.task_id=taskId;
  const response=await api(action,payload);if(response.mission)showMission(response.mission);
  await load();if(action==='get'&&response.mission)showMission(response.mission);
 }catch(e){say(e.message||'Mission action failed.');}finally{setBusy(false);}
}
root.addEventListener('toggle',()=>{
 if(root.open&&!busy&&Date.now()-lastRefresh>5000){setBusy(true);load().catch(e=>say(e.message||'HomeServer is unavailable.')).finally(()=>setBusy(false));}
});
root.addEventListener('click',ev=>{
 const target=ev.target.closest('button[data-action]');if(!target||!root.contains(target))return;
 operation(target.dataset.action,target.dataset.id,target.dataset.taskId);
});
create.addEventListener('submit',async ev=>{
 ev.preventDefault();if(busy)return;const objective=input.value.trim();if(!objective||objective.length>4000)return;
 const request_id=window.crypto&&window.crypto.randomUUID?window.crypto.randomUUID():'';
 if(!request_id){say('Secure request IDs are unavailable in this browser.');return;}
 const raw=Number((window.STONEFELLOW_CHAT||{}).initialConversationId||0);
 const thread_id=Number.isSafeInteger(raw)&&raw>0&&raw<2147483648?raw:0;
 setBusy(true);say('Creating worker mission…');
 try{
  const created=await api('create',{objective,request_id,thread_id});
  const mid=created.mission&&created.mission.id;if(!mid)throw new Error('Mission ID was not returned.');
  selected=mid;input.value='';showMission(created.mission);say('Starting workers…');
  await api('start',{mission_id:mid});await load();
 }catch(e){say(e.message||'Mission creation failed. Refresh mission history before retrying.');}
 finally{setBusy(false);}
});
window.setInterval(()=>{
 if(document.hidden||!root.open||busy||Date.now()-lastRefresh<15000)return;
 load().catch(e=>say(e.message||'HomeServer unavailable.'));
},15000);
})();