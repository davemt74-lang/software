#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def rep(path,old,new):
 p=ROOT/path;t=p.read_text();
 if old not in t: raise SystemExit(f'missing {path}: {old[:100]!r}')
 p.write_text(t.replace(old,new,1))

def append(path,text):
 p=ROOT/path;p.write_text(p.read_text()+text)

# The reusable toast must always open the newest event, not the event that first
# created the DOM node.
rep('profile-activity-chat.js',
"let state=null,timer=0,canvas=null,backdrop=null,button=null,badge=null,activeTab='activity',selectedConversation=0,bootstrapped=false,lastActivityId=0;",
"let state=null,timer=0,canvas=null,backdrop=null,button=null,badge=null,activeTab='activity',selectedConversation=0,bootstrapped=false,lastActivityId=0,toastItem=null;")
rep('profile-activity-chat.js',
"function toast(item){let host=document.getElementById('profileActivityToast');if(!host){host=document.createElement('button');host.type='button';host.id='profileActivityToast';host.className='profile-activity-toast';document.body.appendChild(host);host.addEventListener('click',()=>{host.classList.remove('show');openCanvas({tab:item?.event_type==='profile_view'?'visitors':'activity',conversationId:Number(item?.conversation_id||0)});});}host.innerHTML=`<span>${esc(item?.visitor_label||'Visitor')}</span><strong>${esc(statusLabel(item?.event_type))}</strong>`;host.classList.add('show');clearTimeout(Number(host.dataset.timer||0));host.dataset.timer=String(setTimeout(()=>host.classList.remove('show'),6500));}",
"function toast(item){toastItem=item;let host=document.getElementById('profileActivityToast');if(!host){host=document.createElement('button');host.type='button';host.id='profileActivityToast';host.className='profile-activity-toast';document.body.appendChild(host);host.addEventListener('click',()=>{host.classList.remove('show');const current=toastItem;openCanvas({tab:current?.event_type==='profile_view'?'visitors':'activity',conversationId:Number(current?.conversation_id||0)});});}host.innerHTML=`<span>${esc(item?.visitor_label||'Visitor')}</span><strong>${esc(statusLabel(item?.event_type))}</strong>`;host.classList.add('show');clearTimeout(Number(host.dataset.timer||0));host.dataset.timer=String(setTimeout(()=>host.classList.remove('show'),6500));}")

# Keep presence current while a visitor remains on the public profile. Calling
# state updates the visit session heartbeat but never increments view_count.
rep('profile-agent.js',
"  let pollTimer=0;",
"  let pollTimer=0,presenceTimer=0;")
rep('profile-agent.js',
"  function startPolling(){clearInterval(pollTimer);pollTimer=window.setInterval(poll,10000);}",
"  function startPolling(){clearInterval(pollTimer);pollTimer=window.setInterval(poll,10000);}\n  async function heartbeat(){if(document.visibilityState!=='visible')return;try{await request('state',{},'GET');}catch(_error){}}")
rep('profile-agent.js',
"  window.addEventListener('pagehide',()=>clearInterval(pollTimer),{once:true});\n  loadState();",
"  window.addEventListener('pagehide',()=>{clearInterval(pollTimer);clearInterval(presenceTimer);},{once:true});\n  loadState();presenceTimer=window.setInterval(heartbeat,60000);")
rep('profile.php','profile-agent.js?v=profile-agent-attention-20260903','profile-agent.js?v=profile-activity-20260905')

append('profile-agent-portal.css',"\n.profile-agent-visitor-row{grid-template-columns:38px minmax(0,1fr) auto}.profile-agent-visitor-row.selected{background:#f6f7f8;box-shadow:inset 3px 0 0 #111318}.profile-agent-visitor-avatar{width:38px;height:38px;display:grid;place-items:center;overflow:hidden;border:1px solid #e1e5e8;border-radius:50%;background:#f2f4f6;color:#333;font-size:.72rem;font-weight:850}.profile-agent-visitor-avatar img{width:100%;height:100%;object-fit:cover}.profile-agent-row-main a{display:inline-block;margin-top:6px;color:#30363d;text-decoration:none;font-size:.61rem;font-weight:800}.profile-agent-row-main a:hover{text-decoration:underline}\n")

# Strengthen the regression contract around the two review fixes.
p=ROOT/'tests/profile-activity-chat-contract.mjs';t=p.read_text();
t=t.replace("const portal=read('profile-agent-portal.js');", "const portal=read('profile-agent-portal.js');\nconst publicAgent=read('profile-agent.js');\nconst portalCss=read('profile-agent-portal.css');")
t=t.replace("assert.ok(activity.includes('profile-activity:open'), 'Profile Activity should expose an event bridge for proactive attention');", "assert.ok(activity.includes('profile-activity:open'), 'Profile Activity should expose an event bridge for proactive attention');\nassert.ok(activity.includes('toastItem=item'), 'reused landing toast must track the newest activity event');")
t=t.replace("assert.ok(portal.includes('identity_disclosed'), 'Profile Agent portal should render enriched disclosed visitor identity');", "assert.ok(portal.includes('identity_disclosed'), 'Profile Agent portal should render enriched disclosed visitor identity');\nassert.ok(publicAgent.includes('presenceTimer=window.setInterval(heartbeat,60000)'), 'public profile should heartbeat presence without adding views');\nassert.ok(portalCss.includes('.profile-agent-visitor-row{grid-template-columns:38px minmax(0,1fr) auto}'), 'enriched visitor cards need a dedicated avatar/content/status grid');")
p.write_text(t)
print('PROFILE_ACTIVITY_POLISH=PATCHED')
