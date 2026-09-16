(()=>{'use strict';
let ctx=null,queueBusy=false;
const $=(s,r=document)=>r.querySelector(s);const $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
function clearAndMessage(el,message){if(!el)return;el.innerHTML='';const empty=document.createElement('div');empty.className='meeting-agent-empty';empty.textContent=message;el.appendChild(empty);}
function installFollowthroughUi(){
  if(!ctx.boot.reviewOnly||$('#meetingPane-followthrough'))return;
  const tabs=$('.meeting-agent-tabs'),content=$('.meeting-agent-content');if(!tabs||!content)return;
  const button=document.createElement('button');button.className='meeting-agent-tab';button.type='button';button.dataset.pane='followthrough';button.textContent='Follow-through';
  const activity=tabs.querySelector('[data-pane="activity"]');activity?tabs.insertBefore(button,activity):tabs.appendChild(button);
  const pane=document.createElement('section');pane.className='meeting-agent-pane';pane.id='meetingPane-followthrough';
  const head=document.createElement('div');head.className='meeting-followthrough-head';
  const copy=document.createElement('div');const title=document.createElement('strong');title.textContent='Post-Meeting Action Queue';const desc=document.createElement('p');desc.textContent='Approve reviewed meeting outcomes before VP3 writes them into Tasks, Agent Brain, Calendar, CRM, or the canonical follow-up workflow.';copy.append(title,desc);
  const refresh=document.createElement('button');refresh.type='button';refresh.id='meetingFollowthroughRefresh';refresh.textContent='Refresh suggestions';refresh.addEventListener('click',()=>queueRequest('queue_refresh'));
  head.append(copy,refresh);
  const metrics=document.createElement('div');metrics.id='meetingFollowthroughMetrics';metrics.className='meeting-followthrough-metrics';
  const list=document.createElement('div');list.id='meetingFollowthroughList';list.className='meeting-followthrough-list';
  pane.append(head,metrics,list);content.appendChild(pane);
  button.addEventListener('click',()=>activatePane('followthrough'));
  if(!document.querySelector('style[data-followthrough-v18100]')){const style=document.createElement('style');style.dataset.followthroughV18100='1';style.textContent=`
    .meeting-followthrough-head{display:flex;gap:18px;align-items:flex-start;justify-content:space-between;margin-bottom:16px}.meeting-followthrough-head p{margin:6px 0 0;color:#9b9ba1;line-height:1.45}.meeting-followthrough-head button,.meeting-followthrough-actions button,.meeting-followthrough-edit button{border:1px solid #34343a;background:#17171b;color:#f4f4f5;border-radius:9px;padding:8px 11px;cursor:pointer}.meeting-followthrough-actions button.primary{background:#f3f3f4;color:#101012;border-color:#f3f3f4}.meeting-followthrough-metrics{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}.meeting-followthrough-metric{border:1px solid #2c2c31;border-radius:999px;padding:6px 10px;font-size:12px;color:#b7b7bd}.meeting-followthrough-card{border:1px solid #2b2b30;background:#111114;border-radius:14px;padding:14px;margin-bottom:10px}.meeting-followthrough-card header{display:flex;justify-content:space-between;gap:12px}.meeting-followthrough-card header strong{font-size:14px}.meeting-followthrough-status{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#a9a9af}.meeting-followthrough-meta{display:flex;flex-wrap:wrap;gap:8px;color:#8d8d94;font-size:12px;margin:8px 0}.meeting-followthrough-block{color:#e2aa72;font-size:12px;margin:8px 0}.meeting-followthrough-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.meeting-followthrough-edit{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}.meeting-followthrough-edit label{display:grid;gap:4px;font-size:11px;color:#9b9ba1}.meeting-followthrough-edit input,.meeting-followthrough-edit textarea{width:100%;box-sizing:border-box;border:1px solid #303038;background:#0b0b0d;color:#f4f4f5;border-radius:8px;padding:8px}.meeting-followthrough-edit textarea{min-height:90px;resize:vertical}.meeting-followthrough-edit .wide{grid-column:1/-1}.meeting-followthrough-receipt{font-size:12px;margin-top:8px}.meeting-followthrough-receipt a{color:inherit}.meeting-followthrough-error{color:#e18c8c;font-size:12px;margin-top:8px}@media(max-width:700px){.meeting-followthrough-edit{grid-template-columns:1fr}.meeting-followthrough-edit .wide{grid-column:auto}.meeting-followthrough-head{display:block}.meeting-followthrough-head button{margin-top:10px}}
  `;document.head.appendChild(style);}
}
function activatePane(name){
  $$('.meeting-agent-tab').forEach(b=>b.classList.toggle('active',b.dataset.pane===name));
  $$('.meeting-agent-pane').forEach(p=>p.classList.toggle('active',p.id==='meetingPane-'+name));
}
function inputField(label,value,key,type='text',wide=false){
  const wrap=document.createElement('label');if(wide)wrap.classList.add('wide');const span=document.createElement('span');span.textContent=label;
  const input=type==='textarea'?document.createElement('textarea'):document.createElement('input');if(type!=='textarea')input.type=type;input.value=String(value??'');input.dataset.queueField=key;wrap.append(span,input);return wrap;
}
function renderQueue(queue){
  installFollowthroughUi();const list=$('#meetingFollowthroughList'),metrics=$('#meetingFollowthroughMetrics');if(!list||!metrics)return;list.innerHTML='';metrics.innerHTML='';
  if(!queue?.ready){clearAndMessage(list,String(queue?.reason||'Finalize the meeting intelligence to build the action queue.'));return;}
  const counts=queue.counts||{};for(const key of ['suggested','approved','verified','failed']){const chip=document.createElement('span');chip.className='meeting-followthrough-metric';chip.textContent=`${key}: ${Number(counts[key]||0)}`;metrics.appendChild(chip);}
  const items=Array.isArray(queue.items)?queue.items:[];if(!items.length){clearAndMessage(list,'No post-meeting actions were identified.');return;}
  for(const item of items){
    const card=document.createElement('article');card.className='meeting-followthrough-card';card.dataset.queueId=String(item.id||'');
    const header=document.createElement('header'),name=document.createElement('strong'),status=document.createElement('span');name.textContent=String(item.title||'Meeting action');status.className='meeting-followthrough-status';status.textContent=String(item.status||'suggested');header.append(name,status);card.appendChild(header);
    const meta=document.createElement('div');meta.className='meeting-followthrough-meta';for(const value of [String(item.type||'').replaceAll('_',' '),item.owner,item.due_date]){if(!value)continue;const span=document.createElement('span');span.textContent=String(value);meta.appendChild(span);}if(meta.children.length)card.appendChild(meta);
    if(item.blocked_reason){const block=document.createElement('div');block.className='meeting-followthrough-block';block.textContent=String(item.blocked_reason);card.appendChild(block);}
    if(item.error){const err=document.createElement('div');err.className='meeting-followthrough-error';err.textContent=String(item.error);card.appendChild(err);}
    const edit=document.createElement('div');edit.className='meeting-followthrough-edit';
    if(!['executed','verified'].includes(String(item.status))){
      edit.append(inputField('Action',item.title,'title'),inputField('Owner / Agent',item.owner,'owner'));
      if(['agent_task','calendar_event','crm_task'].includes(String(item.type)))edit.append(inputField('Due date',item.due_date,'due_date'));
      if(['crm_note','crm_task'].includes(String(item.type)))edit.append(inputField('CRM lead ID',item.lead_id||'','lead_id','number'));
      if(item.type==='followup_workflow'){edit.append(inputField('Subject',item.followup_subject,'followup_subject','text',true),inputField('Reviewed follow-up draft',item.followup_body,'followup_body','textarea',true));}
      const save=document.createElement('button');save.type='button';save.textContent='Save review';save.addEventListener('click',()=>saveQueueItem(card,item));edit.append(save);card.appendChild(edit);
    }
    const actions=document.createElement('div');actions.className='meeting-followthrough-actions';
    if(item.approvable){const approve=document.createElement('button');approve.type='button';approve.className='primary';approve.textContent=item.status==='failed'?'Approve retry':'Approve';approve.addEventListener('click',()=>queueRequest('queue_approve',{item_id:item.id}));actions.appendChild(approve);}
    if(item.executable){const execute=document.createElement('button');execute.type='button';execute.className='primary';execute.textContent=item.type==='followup_workflow'?'Create review workflow':(item.status==='failed'?'Retry execution':'Execute');execute.addEventListener('click',()=>queueRequest('queue_execute',{item_id:item.id}));actions.appendChild(execute);}
    if(item.target_url){const open=document.createElement('a');open.className='meeting-summary-open';open.href=String(item.target_url);open.textContent='Open canonical record →';actions.appendChild(open);}
    if(actions.children.length)card.appendChild(actions);
    if(item.record_id){const receipt=document.createElement('div');receipt.className='meeting-followthrough-receipt';receipt.textContent=`Verified canonical ${String(item.record_kind||'record')} #${Number(item.record_id)}`;card.appendChild(receipt);}
    list.appendChild(card);
  }
}
async function saveQueueItem(card,item){
  const payload={item_id:String(item.id||'')};for(const field of $$('[data-queue-field]',card))payload[String(field.dataset.queueField)]=field.value;
  await queueRequest('queue_update',payload);
}
async function queueRequest(action,extra={}){
  if(queueBusy)return;queueBusy=true;ctx.setStatus('Updating post-meeting action queue…','working');
  try{const data=await ctx.intelligence(action,extra);const queue=data.queue||data.state?.post_meeting_queue;if(queue){const current=ctx.getState();if(current)current.post_meeting_queue=queue;renderQueue(queue);}if(data.state)ctx.renderState(data.state);ctx.setStatus('Post-meeting action queue updated.','ready');}
  catch(err){ctx.setStatus(err.message||'Post-meeting action could not be completed.','error');}
  finally{queueBusy=false;}
}

window.VP3MeetingFollowthrough18100={
  init(context){ctx=context;installFollowthroughUi();const current=ctx.getState();if(current?.post_meeting_queue)renderQueue(current.post_meeting_queue);},
  render(queue){if(ctx)renderQueue(queue);},
  activatePane,
};
})();
