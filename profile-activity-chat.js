(() => {
'use strict';
const cfg=window.STONEFELLOW_AGENT_IDENTITY_V236||{};
const endpoint=cfg.profileAgentEndpoint||'';
if(!endpoint||!cfg.csrf)return;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const relative=value=>{if(!value)return '';const d=new Date(String(value).replace(' ','T'));if(Number.isNaN(d.getTime()))return String(value);const s=Math.max(0,Math.round((Date.now()-d.getTime())/1000));if(s<60)return 'just now';if(s<3600)return `${Math.max(1,Math.round(s/60))}m ago`;if(s<86400)return `${Math.round(s/3600)}h ago`;if(s<604800)return `${Math.round(s/86400)}d ago`;return d.toLocaleDateString();};
const req=async(action,payload=null)=>{const post=payload!==null;const url=post?endpoint:`${endpoint}?action=${encodeURIComponent(action)}`;const options=post?{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,csrf_token:cfg.csrf,...payload})}:{credentials:'same-origin',cache:'no-store'};const r=await fetch(url,options),d=await r.json().catch(()=>null);if(!r.ok||!d?.ok)throw new Error(d?.error||'Profile activity is unavailable.');return d;};
let state=null,timer=0,canvas=null,backdrop=null,button=null,badge=null,activeTab='activity',selectedConversation=0,bootstrapped=false,lastActivityId=0;
let selectedThread=null,displayedThread=null,threadRead=0,stateRead=0,refreshBusy=false;
const drafts=new Map(),threadMutations=new Map();
const conversationId=value=>Number.isSafeInteger(Number(value))&&Number(value)>0?Number(value):0;
const threadSelected=binding=>binding!==null&&selectedThread===binding&&selectedConversation===binding.id;
const threadDisplayed=binding=>threadSelected(binding)&&displayedThread===binding;
function rememberDraft(){const form=canvas?.querySelector('[data-profile-reply]');if(displayedThread&&form)drafts.set(displayedThread.id,form.elements.message.value);}
function leaveThread(){rememberDraft();selectedThread=null;displayedThread=null;selectedConversation=0;threadRead++;}

function avatar(v){if(v?.identity_disclosed&&v.avatar_url)return `<img src="${esc(v.avatar_url)}" alt="">`;const label=String(v?.visitor_label||'Visitor').trim();return `<span>${esc(label.charAt(0).toUpperCase()||'?')}</span>`;}
function identityLine(v){if(v?.identity_disclosed){const bits=[];if(v.username)bits.push(`@${v.username}`);if(v.role_label)bits.push(v.role_label);if(v.relationship_scope&&v.relationship_scope!=='none')bits.push(v.relationship_scope);return bits.join(' · ');}return v?.signed_in?'Signed-in member · identity private':'Guest visitor';}
function statusLabel(type){return ({profile_view:'Landed on your profile',conversation_started:'Started a Profile Agent conversation',needs_owner:'Needs your input'}[type]||String(type||'Profile activity').replaceAll('_',' '));}
function updateBell(){const n=Number(state?.notifications?.unread||0),bell=document.getElementById('chatNotificationButton');if(!bell)return;let span=bell.querySelector(':scope > span');if(n>0){if(!span){span=document.createElement('span');bell.appendChild(span);}span.textContent=n>99?'99+':String(n);}else span?.remove();}
function ensureButton(){if(button)return;const actions=document.querySelector('.chat-topbar-actions');if(!actions)return;button=document.createElement('button');button.type='button';button.className='chat-profile-activity-button';button.setAttribute('aria-label','Open Profile Activity');button.setAttribute('aria-expanded','false');button.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M5.5 20c.5-4 2.7-6 6.5-6s6 2 6.5 6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';badge=document.createElement('em');badge.hidden=true;button.appendChild(badge);const avatar=document.getElementById('chatProfileMenu');actions.insertBefore(button,avatar||null);button.addEventListener('click',()=>openCanvas());}
function ensureCanvas(){if(canvas)return;backdrop=document.createElement('div');backdrop.className='profile-activity-backdrop';backdrop.hidden=true;canvas=document.createElement('aside');canvas.className='profile-activity-canvas';canvas.id='profileActivityCanvas';canvas.hidden=true;canvas.setAttribute('aria-label','Profile Activity');canvas.innerHTML=`<header><div><small>Profile Agent</small><strong>Profile Activity</strong></div><button type="button" data-profile-activity-close aria-label="Close">×</button></header><nav><button type="button" data-profile-activity-tab="activity" class="active">Activity</button><button type="button" data-profile-activity-tab="visitors">Visitors</button><button type="button" data-profile-activity-tab="conversations">Conversations</button></nav><div class="profile-activity-body" data-profile-activity-body></div>`;document.body.append(backdrop,canvas);backdrop.addEventListener('click',closeCanvas);canvas.addEventListener('click',handleCanvasClick);canvas.querySelector('[data-profile-activity-close]')?.addEventListener('click',closeCanvas);}
function openCanvas(options={}){ensureCanvas();const id=conversationId(options.conversationId);if(options.tab&&options.tab!=='conversations')leaveThread();if(options.tab)activeTab=options.tab;canvas.hidden=false;backdrop.hidden=false;requestAnimationFrame(()=>{canvas.classList.add('open');backdrop.classList.add('open');});button?.setAttribute('aria-expanded','true');document.body.classList.add('profile-activity-open');if(id)loadConversation(id);else if(selectedThread)refreshConversation(selectedThread);else renderCanvas();}
function closeCanvas(){if(!canvas)return;canvas.classList.remove('open');backdrop.classList.remove('open');button?.setAttribute('aria-expanded','false');document.body.classList.remove('profile-activity-open');setTimeout(()=>{if(canvas&&!canvas.classList.contains('open')){canvas.hidden=true;backdrop.hidden=true;}},180);}
function metricStrip(){const a=state?.analytics||{};return `<div class="profile-activity-metrics"><span><strong>${Number(a.active_visitors||0)}</strong> active</span><span><strong>${Number(a.open_conversations||0)}</strong> open chats</span><span><strong>${Number(a.needs_attention||0)}</strong> need you</span></div>`;}
function activityView(){const rows=Array.isArray(state?.activity)?state.activity:[];return metricStrip()+`<div class="profile-activity-list">${rows.length?rows.map(x=>`<article class="profile-activity-event" data-profile-session="${Number(x.profile_session_id||0)}"${Number(x.conversation_id||0)>0?` data-profile-conversation="${Number(x.conversation_id)}"`:''}><div class="profile-activity-avatar">${avatar(x)}</div><div><strong>${esc(x.visitor_label||'Visitor')}</strong><p>${esc(statusLabel(x.event_type))}</p><small>${esc(identityLine(x))} · ${esc(relative(x.created_at))}</small></div>${Number(x.conversation_id||0)>0?'<button type="button">Open</button>':''}</article>`).join(''):'<div class="profile-activity-empty">No profile activity yet.</div>'}</div>`;}
function visitorsView(){const rows=Array.isArray(state?.visits)?state.visits:[];return metricStrip()+`<div class="profile-activity-list">${rows.length?rows.map(v=>`<article class="profile-activity-visitor"><div class="profile-activity-avatar">${avatar(v)}</div><div><strong>${esc(v.visitor_label||'Visitor')}</strong><p>${esc(identityLine(v))}</p><small>${Number(v.view_count||0)} view${Number(v.view_count||0)===1?'':'s'} · ${v.active_now?'active now':`last seen ${esc(relative(v.last_seen_at))}`}</small>${v.profile_url?`<a href="${esc(v.profile_url)}" target="_blank" rel="noopener">View profile ↗</a>`:''}</div><span class="profile-activity-presence${v.active_now?' live':''}">${v.active_now?'Live':v.signed_in?'Member':'Guest'}</span></article>`).join(''):'<div class="profile-activity-empty">No visitors yet.</div>'}</div>`;}
function conversationsView(){const rows=Array.isArray(state?.conversations)?state.conversations:[];return metricStrip()+`<div class="profile-activity-list">${rows.length?rows.map(c=>`<article class="profile-activity-conversation" data-profile-conversation="${Number(c.id)}"><div class="profile-activity-avatar">${avatar(c)}</div><div><strong>${esc(c.visitor_label||'Visitor')}</strong><p>${esc(c.last_summary||'Profile Agent conversation')}</p><small>${esc(identityLine(c))} · ${esc(relative(c.last_message_at))}</small></div><span>${esc(String(c.status||'open').replace('_',' '))}</span></article>`).join(''):'<div class="profile-activity-empty">No Profile Agent conversations yet.</div>'}</div>`;}
function renderCanvas(){if(!canvas)return;canvas.querySelectorAll('[data-profile-activity-tab]').forEach(b=>b.classList.toggle('active',b.dataset.profileActivityTab===activeTab));const body=canvas.querySelector('[data-profile-activity-body]');if(!body)return;body.innerHTML=activeTab==='visitors'?visitorsView():activeTab==='conversations'?conversationsView():activityView();}
function threadNotice(binding,message='',error=false){if(!threadDisplayed(binding))return;const node=canvas.querySelector('[data-profile-thread-notice]');node.textContent=message;node.setAttribute('role',error?'alert':'status');}
function updateThreadControls(binding){if(!threadDisplayed(binding))return;const busy=threadMutations.has(binding.id);canvas.querySelectorAll('[data-profile-reply] input,[data-profile-reply] button,[data-profile-resolve]').forEach(node=>{node.disabled=busy;});}
function renderThread(binding,c,m){
  if(!threadSelected(binding))return;
  const body=canvas.querySelector('[data-profile-activity-body]');
  if(!threadDisplayed(binding)){
    body.innerHTML=`<div class="profile-activity-thread-head"><button type="button" data-profile-thread-back>← Conversations</button><div data-profile-thread-identity></div></div><div class="profile-activity-thread" data-profile-thread-messages></div><form class="profile-activity-reply" data-profile-reply data-conversation-id="${binding.id}"><input name="message" maxlength="4000" autocomplete="off" placeholder="Reply as profile owner"><button type="submit">Send</button></form><p data-profile-thread-notice role="status" aria-live="polite"></p><div class="profile-activity-thread-actions"><button type="button" data-profile-resolve>Resolve conversation</button><a href="${esc(cfg.profileAgentUrl||'/profile-agent.php')}?tab=inbox&conversation=${binding.id}">Open full portal ↗</a></div>`;
    displayedThread=binding;
    const form=body.querySelector('[data-profile-reply]');form.elements.message.value=drafts.get(binding.id)||'';
    form.elements.message.addEventListener('input',()=>drafts.set(binding.id,form.elements.message.value));
    form.addEventListener('submit',e=>sendReply(e,binding));
    body.querySelector('[data-profile-resolve]').addEventListener('click',()=>resolveConversation(binding));
  }
  const identity=body.querySelector('[data-profile-thread-identity]');
  const heading=`<strong>${esc(c.visitor_label||'Visitor')}</strong><small>${esc(identityLine(c))} · ${esc(String(c.status||'open').replace('_',' '))}</small>`;
  if(identity.innerHTML!==heading)identity.innerHTML=heading;
  const messages=body.querySelector('[data-profile-thread-messages]');
  const html=m.map(x=>`<div class="profile-activity-message ${esc(x.sender_type)}"><small>${x.sender_type==='visitor'?'Visitor':x.sender_type==='owner'?'You':'Profile Agent'}</small><p>${esc(x.message)}</p></div>`).join('')||'<div class="profile-activity-empty">No messages yet.</div>';
  if(messages.innerHTML!==html){const top=body.scrollTop,atEnd=body.scrollHeight-body.scrollTop-body.clientHeight<40;messages.innerHTML=html;body.scrollTop=atEnd?body.scrollHeight:top;}
  updateThreadControls(binding);
}
async function refreshConversation(binding){
  if(!threadSelected(binding)||threadMutations.has(binding.id))return;
  const request=++threadRead;
  try{
    const d=await req('conversation_messages',{conversation_id:binding.id});
    if(!threadSelected(binding)||request!==threadRead)return;
    if(conversationId(d.conversation?.id)!==binding.id||!Array.isArray(d.messages))throw new Error('The selected conversation could not be verified.');
    renderThread(binding,d.conversation,d.messages);
    threadNotice(binding);
    return true;
  }catch(err){
    if(!threadSelected(binding)||request!==threadRead)return;
    if(threadDisplayed(binding))threadNotice(binding,err.message,true);
    else canvas.querySelector('[data-profile-activity-body]').innerHTML=`<div class="profile-activity-empty" role="alert">${esc(err.message)}</div>`;
    return false;
  }
}
async function loadConversation(id){
  id=conversationId(id);if(!id)return;ensureCanvas();
  if(selectedThread?.id===id&&threadDisplayed(selectedThread))return refreshConversation(selectedThread);
  rememberDraft();selectedConversation=id;selectedThread=Object.freeze({id});displayedThread=null;activeTab='conversations';renderCanvas();
  canvas.querySelector('[data-profile-activity-body]').innerHTML='<div class="profile-activity-loading">Loading conversation…</div>';
  return refreshConversation(selectedThread);
}
async function sendReply(e,binding){
  e.preventDefault();if(!threadDisplayed(binding)||!canvas.contains(e.currentTarget)||threadMutations.has(binding.id))return;
  const input=e.currentTarget.elements.message,raw=input.value,message=raw.trim();if(!message)return;
  drafts.set(binding.id,raw);const token={};threadMutations.set(binding.id,token);threadRead++;stateRead++;updateThreadControls(binding);threadNotice(binding);
  try{
    const d=await req('owner_reply',{conversation_id:binding.id,message});
    if(conversationId(d.conversation_id)!==binding.id)throw new Error('The reply could not be confirmed for this conversation.');
    if(!threadDisplayed(binding))return;
    if(input.value===raw){input.value='';drafts.delete(binding.id);}
    stateRead++;state=d.state||state;updateButton();updateBell();threadMutations.delete(binding.id);
    const fresh=await refreshConversation(binding);threadNotice(binding,fresh?'Reply sent.':'Reply sent. The latest messages could not be loaded.',!fresh);
  }catch(err){if(threadDisplayed(binding))threadNotice(binding,err.message,true);}
  finally{if(threadMutations.get(binding.id)===token)threadMutations.delete(binding.id);if(selectedThread?.id===binding.id)updateThreadControls(selectedThread);}
}
async function resolveConversation(binding){
  if(!threadDisplayed(binding)||threadMutations.has(binding.id))return;
  rememberDraft();const token={};threadMutations.set(binding.id,token);threadRead++;stateRead++;updateThreadControls(binding);threadNotice(binding);
  try{
    const d=await req('conversation_status',{conversation_id:binding.id,status:'resolved'});
    if(conversationId(d.conversation_id)!==binding.id)throw new Error('The conversation status could not be confirmed.');
    if(!threadDisplayed(binding))return;
    stateRead++;state=d.state||state;updateButton();updateBell();threadMutations.delete(binding.id);
    const fresh=await refreshConversation(binding);threadNotice(binding,fresh?'Conversation resolved.':'Conversation resolved. The latest messages could not be loaded.',!fresh);
  }catch(err){if(threadDisplayed(binding))threadNotice(binding,err.message,true);}
  finally{if(threadMutations.get(binding.id)===token)threadMutations.delete(binding.id);if(selectedThread?.id===binding.id)updateThreadControls(selectedThread);}
}
function handleCanvasClick(e){const tab=e.target.closest('[data-profile-activity-tab]');if(tab){leaveThread();activeTab=tab.dataset.profileActivityTab;renderCanvas();return;}if(e.target.closest('[data-profile-thread-back]')){leaveThread();activeTab='conversations';renderCanvas();return;}const conversation=e.target.closest('[data-profile-conversation]');if(conversation){loadConversation(Number(conversation.dataset.profileConversation));return;}}
function updateButton(){ensureButton();const n=Number(state?.analytics?.active_visitors||0)+Number(state?.analytics?.needs_attention||0);if(badge){badge.hidden=n<1;badge.textContent=n>99?'99+':String(n);}button?.classList.toggle('has-activity',n>0);}
function handleNewActivity(){const rows=Array.isArray(state?.activity)?state.activity:[],latest=Number(rows[0]?.id||0);if(!bootstrapped){lastActivityId=latest;bootstrapped=true;return;}if(latest>lastActivityId)lastActivityId=latest;}
async function refresh(){if(refreshBusy||threadMutations.size)return;refreshBusy=true;const request=++stateRead;try{const d=await req('owner_state');if(request!==stateRead)return;state=d.state||{};handleNewActivity();updateButton();updateBell();if(canvas?.classList.contains('open')){if(selectedThread)await refreshConversation(selectedThread);else renderCanvas();}}catch(_e){}finally{refreshBusy=false;}}
window.addEventListener('profile-activity:open',e=>openCanvas(e.detail||{}));document.addEventListener('keydown',e=>{if(e.key==='Escape'&&canvas?.classList.contains('open'))closeCanvas();});
window.STONEFELLOW_PROFILE_ACTIVITY={open:openCanvas,refresh};
ensureButton();refresh();timer=setInterval(()=>{if(document.visibilityState==='visible')refresh();},15000);window.addEventListener('pagehide',()=>clearInterval(timer),{once:true});
})();
