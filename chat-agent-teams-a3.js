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
 '<button type="submit">Create &amp; run</button></div>'+
 '<label class="vp3-provider-label" for="vp3MissionProvider">Initial worker model</label>'+
 '<select id="vp3MissionProvider" name="provider_key">'+
 '<option value="auto">HomeServer default</option><option value="ollama">Ollama (local)</option>'+
 '<option value="anthropic">Claude / Anthropic</option><option value="openai">OpenAI</option>'+
 '<option value="openrouter">OpenRouter</option></select>'+
 '<small class="vp3-teams-provider-note">Workers use isolated model-only contexts; read-only browser evidence requires separate approval. No model tools or write actions.</small>'+
 '<label class="vp3-teams-browser-optin"><input type="checkbox" name="prepare_only"> Prepare mission first — approve browser pages for individual workers before starting</label></form>'+
 '<div class="vp3-teams-status" role="status" aria-live="polite">Open to load missions.</div>'+
 '<div class="vp3-teams-list" aria-label="Recent missions"></div>'+
 '<section class="vp3-teams-detail" aria-label="Selected mission" hidden></section></div>';
shell.insertBefore(root,form);
const status=root.querySelector('.vp3-teams-status'), list=root.querySelector('.vp3-teams-list'),
 detail=root.querySelector('.vp3-teams-detail'), create=root.querySelector('form'), input=create.querySelector('input'),
 submit=create.querySelector('button');
let busy=false,selected='',items=[],lastRefresh=0,inflight=false;
const staffingByMission=new Map();
const browserByWorker=new Map();
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
 const openBrowsers=new Set(Array.from(detail.querySelectorAll('.vp3-worker-browser[open]'))
   .map(pane=>pane.dataset.browserWorker));
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
  li.appendChild(el('small','',(t.role||'specialist')+' · '+(t.status||'queued')+(t.model?' · '+t.model:'')+' · '+fmt(t.completed_at||t.started_at)));
  if(t.error)li.appendChild(el('p','vp3-teams-error',t.error));
  if(['failed','partial','waiting_review'].includes(m.status)&&['failed','interrupted'].includes(t.status)&&t.error!=='Dependency failed')li.appendChild(btn('Retry worker','retry',selected,t.id));
  if(t.result){const exp=el('details','vp3-teams-result');exp.appendChild(el('summary','','Worker result'));
   exp.appendChild(el('pre','',t.result));li.appendChild(exp);}
  const browser=el('details','vp3-worker-browser');
  browser.dataset.browserWorker=t.id;
  browser.open=openBrowsers.has(t.id);
  browser.appendChild(el('summary','','Worker browser · supervised read-only'));
  const area=el('div','vp3-worker-browser-body');
  const key=selected+'|'+t.id;
  const state=browserByWorker.get(key);
  if(state){
   area.appendChild(el('p','vp3-teams-meta','Status: '+state.status+' · '+(state.visit_count||0)+' / '+(state.max_visits||5)+' captures'));
   if(state.approved_origin)area.appendChild(el('p','vp3-teams-meta','Approved origin: '+state.approved_origin));
   if(state.page_title)area.appendChild(el('p','',state.page_title));
   if(state.image_base64&&/^[A-Za-z0-9+/=]{100,200000}$/.test(state.image_base64)){
    const preview=el('img','vp3-worker-browser-preview');
    preview.alt='Read-only screenshot for '+(t.title||'worker');
    preview.src='data:image/jpeg;base64,'+state.image_base64;
    area.appendChild(preview);
   }
   if(state.text_snapshot){
    const text=el('details','vp3-teams-result');
    text.appendChild(el('summary','','Page text evidence'));
    text.appendChild(el('pre','',state.text_snapshot));
    area.appendChild(text);
   }
   if(state.last_error)area.appendChild(el('p','vp3-teams-error',state.last_error));
  }else area.appendChild(el('p','vp3-teams-meta','No browser snapshot loaded.'));
  const entry=el('input','vp3-worker-browser-url');
  entry.type='url';entry.placeholder='https://public-site.example/page';
  entry.setAttribute('aria-label','Approved HTTPS page for '+(t.title||'worker'));
  entry.dataset.browserTask=t.id;
  entry.maxLength=1400;
  if(state&&state.current_url)entry.value=state.current_url;
  area.appendChild(entry);
  const actions=el('div','vp3-teams-actions');
  if(t.status==='queued'&&m.status==='planned'&&(!state||state.status==='closed')){
   actions.appendChild(btn('Approve URL','browser.grant',selected,t.id));
  }
  if(state&&state.status==='approved'){
   if(['planned','running'].includes(m.status))actions.appendChild(btn('Capture page','browser.capture',selected,t.id));
   actions.appendChild(btn('Revoke','browser.revoke',selected,t.id));
  }
  actions.appendChild(btn('View browser','browser.get',selected,t.id));
  area.appendChild(actions);browser.appendChild(area);li.appendChild(browser);
  workers.appendChild(li);
 });detail.appendChild(workers);
 if(['completed','partial','failed'].includes(m.status)||staffingByMission.has(selected)){
  const review=el('section','vp3-teams-supervisor');
  review.setAttribute('data-agent-teams-supervisor','');
  review.appendChild(el('h4','','Supervisor · adaptive staffing'));
  const proposals=staffingByMission.get(selected)||[];
  const latest=proposals[0];
  if(latest){
   review.appendChild(el('p','vp3-teams-meta',
     (latest.status==='proposed'?'Awaiting your approval':latest.status.replaceAll('_',' '))+
     ' · '+fmt(latest.created_at)+(latest.private?' · Private HomeServer review':' · Confidence '+latest.confidence+'%')));
   if(latest.reason)review.appendChild(el('p','vp3-teams-supervisor-reason',latest.reason));
   const roster=el('ol','vp3-teams-supervisor-candidates');
   (latest.tasks||[]).forEach(t=>{
    const li=el('li','');li.appendChild(el('strong','',t.title||t.role||'Specialist'));
    li.appendChild(el('small','',(t.role||'specialist')+' · '+(t.objective||'')));
    roster.appendChild(li);
   });
   review.appendChild(roster);
   if(latest.status==='proposed'){
    review.appendChild(btn('Approve new workers','approve',selected,latest.id));
    review.appendChild(btn('Reject proposal','reject',selected,latest.id));
   }
  }else{
   review.appendChild(el('p','vp3-teams-meta','No additional staffing review yet.'));
  }
  if(['completed','partial','failed'].includes(m.status)&&!(latest&&latest.status==='proposed')&&proposals.filter(p=>p.status==='approved').length<2){
   review.appendChild(btn('Evaluate need for specialists','evaluate',selected));
  }
  detail.appendChild(review);
 }
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
  row.appendChild(el('small','',(m.status||'unknown')+' · '+fmt(m.updated_at||m.created_at)+' · '+(m.tasks||[]).length+' workers'+
   ((staffingByMission.get(m.id)||[]).some(p=>p.status==='proposed')?' · Staffing approval needed':'')));
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
async function loadSupervision(mid){
 const result=await api('decisions',{mission_id:mid});
 const records=Array.isArray(result.items)?result.items:[];
 staffingByMission.set(mid,records);
 if(mid===selected){
  const mission=await api('get',{mission_id:mid});
  if(mission.mission)showMission(mission.mission);
 }
}
async function load(){
 if(inflight)return;
 inflight=true;
 try{
  const result=await api('list');items=Array.isArray(result.items)?result.items:[];lastRefresh=Date.now();showList();
  say('HomeServer connected · '+items.length+' recent mission'+(items.length===1?'':'s')+'.');
  if(selected&&items.some(x=>x.id===selected)){const current=await api('get',{mission_id:selected});if(current.mission)showMission(current.mission);
   try{await loadSupervision(selected);}catch(_){/* Mission status remains available if supervision is unsupported. */}}
 }finally{inflight=false;}
}
async function operation(action,id,taskId){
 if(action==='open-team-chat'){root.open=true;root.scrollIntoView({block:'nearest'});return;}
 if(busy)return;
 if(action==='resume'&&!window.confirm('Resume and rerun interrupted model work?'))return;
 if(action==='retry'&&!window.confirm('Retry this worker? The model request may use additional tokens.'))return;
 if(action==='approve'&&!window.confirm('Approve these new read-only workers? Additional model usage will occur.'))return;
 if(action==='reject'&&!window.confirm('Reject this staffing proposal? No workers will be created.'))return;
 if(action==='browser.grant'&&!window.confirm('Approve this read-only HTTPS origin for the selected worker for 15 minutes?'))return;
 if(action==='browser.revoke'&&!window.confirm('Revoke this worker browser and erase its stored snapshot?'))return;
 setBusy(true);
 try{const payload={mission_id:id};if(action==='resume')payload.allow_reexecution=true;
  if(action==='retry')payload.task_id=taskId;
  if(action.startsWith('browser.')){
   payload.task_id=taskId;
   const field=Array.from(detail.querySelectorAll('input[data-browser-task]'))
     .find(node=>node.dataset.browserTask===taskId);
   if(action==='browser.grant'||action==='browser.capture'){
    const url=field?.value.trim()||'';
    if(action==='browser.grant'&&!url)throw new Error('Enter the HTTPS page to approve.');
    if(url)payload.url=url;
   }
   say(action==='browser.capture'?'Capturing approved browser page…':'Updating worker browser…');
  }
  if(action==='evaluate'){
   const requestId=window.crypto&&window.crypto.randomUUID?window.crypto.randomUUID():'';
   if(!requestId)throw new Error('Secure staffing request IDs are not available.');
   payload.request_id=requestId;
   say('Supervisor is evaluating mission results…');
  }
  if(action==='approve'||action==='reject'){payload.decision_id=taskId;payload.confirmed=true;}
  const response=await api(action,payload);
  if(response.browser!==undefined){
   const key=id+'|'+taskId;
   if(response.browser)browserByWorker.set(key,response.browser);
   else browserByWorker.delete(key);
  }
  if(response.mission)showMission(response.mission);
  if(response.supervision){
   staffingByMission.set(id,[response.supervision,...(staffingByMission.get(id)||[]).filter(p=>p.id!==response.supervision.id)]);
   say(action==='evaluate'?'Supervisor recommendation ready for your review.':'Staffing decision recorded.');
  }
  await load();
  if(action==='get'&&response.mission)showMission(response.mission);
  if(action.startsWith('browser.')){
   const current=await api('get',{mission_id:id});
   if(current.mission)showMission(current.mission);
  }
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
  selected=mid;input.value='';showMission(created.mission);
  const preferred=String(create.querySelector('[name="provider_key"]')?.value||'auto');
  if(preferred!=='auto'){
   const workers=Array.isArray(created.mission.tasks)?created.mission.tasks:[];
   if(!workers.length)throw new Error('Mission has no configurable workers.');
   say('Configuring '+workers.length+' worker models…');
   for(const task of workers){await api('bind_provider',{mission_id:mid,task_id:task.id,provider_key:preferred});}
  }
  if(create.querySelector('[name="prepare_only"]')?.checked){
   say('Mission prepared. Approve each worker browser under its task, then select Start.');
   await load();
  }else{
   say('Starting workers…');
   await api('start',{mission_id:mid});await load();
  }
 }catch(e){say(e.message||'Mission creation failed. Refresh mission history before retrying.');}
 finally{setBusy(false);}
});
window.setInterval(()=>{
 if(document.hidden||!root.open||busy||Date.now()-lastRefresh<15000)return;
 load().catch(e=>say(e.message||'HomeServer unavailable.'));
},15000);
})();