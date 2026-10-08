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
function btn(name,action,id){const x=el('button','',name);x.type='button';x.dataset.action=action;if(id)x.dataset.id=id;x.disabled=busy;return x;}
function fmt(value){if(!value)return '—';const raw=String(value).trim();
 const valueUtc=raw.includes('T')?raw.replace(/(\+00:00)?$/,'Z'):raw.replace(' ','T')+'Z';
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
 if(['planned','running','waiting_review'].includes(m.status))buttons.appendChild(btn('Cancel','cancel',selected));
 buttons.appendChild(btn('Refresh','get',selected));detail.appendChild(buttons);
 const workers=el('ol','vp3-teams-workers');
 (m.tasks||[]).forEach(t=>{
  const li=el('li','');li.appendChild(el('strong','',t.title||t.role||'Worker'));
  li.appendChild(el('small','',(t.role||'specialist')+' · '+(t.status||'queued')+' · '+fmt(t.completed_at||t.started_at)));
  if(t.error)li.appendChild(el('p','vp3-teams-error',t.error));
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
async function load(){
 if(inflight)return;
 inflight=true;
 try{
  const result=await api('list');items=Array.isArray(result.items)?result.items:[];lastRefresh=Date.now();showList();
  say('HomeServer connected · '+items.length+' recent mission'+(items.length===1?'':'s')+'.');
  if(selected&&items.some(x=>x.id===selected)){const current=await api('get',{mission_id:selected});if(current.mission)showMission(current.mission);}
 }finally{inflight=false;}
}
async function operation(action,id){
 if(busy)return;setBusy(true);
 try{const response=await api(action,{mission_id:id});if(response.mission)showMission(response.mission);
  await load();if(action==='get'&&response.mission)showMission(response.mission);
 }catch(e){say(e.message||'Mission action failed.');}finally{setBusy(false);}
}
root.addEventListener('toggle',()=>{
 if(root.open&&!busy&&Date.now()-lastRefresh>5000){setBusy(true);load().catch(e=>say(e.message||'HomeServer is unavailable.')).finally(()=>setBusy(false));}
});
root.addEventListener('click',ev=>{
 const target=ev.target.closest('button[data-action]');if(!target||!root.contains(target))return;
 operation(target.dataset.action,target.dataset.id);
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