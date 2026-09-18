(()=>{
'use strict';
const cfgEl=document.getElementById('liveRoomConfig');if(!cfgEl)return;
const cfg=JSON.parse(cfgEl.textContent||'{}'),roomId=String(cfg.room?.id||''),api=String(cfg.api||'');
let room=cfg.room||{},cursor=Number(cfg.cursor||0),timer=null,heartbeatTimer=null;
const messages=document.getElementById('liveMessages'),participants=document.getElementById('liveParticipants'),status=document.getElementById('roomStatus'),count=document.getElementById('presenceCount');
const join=document.getElementById('joinRoomBtn'),cloak=document.getElementById('cloakRoomBtn'),leave=document.getElementById('leaveRoomBtn'),end=document.getElementById('endRoomBtn'),compose=document.getElementById('liveCompose'),input=document.getElementById('liveMessageInput');
function e(tag,cls,text){const x=document.createElement(tag);if(cls)x.className=cls;x.textContent=String(text||'');return x;}
function time(v){if(!v)return '';const d=new Date(String(v).replace(' ','T')+'Z');return isNaN(d)?'':d.toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});}
function appendMessage(m){
  if(!m||!m.id||messages.querySelector('[data-message-id="'+CSS.escape(String(m.id))+'"]'))return;
  const box=e('div','live-msg'+(m.sender?.is_self?' self':''),'');box.dataset.messageId=String(m.id);
  const head=e('div','live-msg-head','');head.append(e('span','live-msg-name',m.sender?.name||'Participant'),e('span','',m.sender?.cloaked?'Cloaked':''),e('span','',time(m.created_at)));box.append(head);
  if(m.body)box.append(e('div','live-msg-body',m.body));
  if(m.annotation){const a=e('div','live-msg-annotation','');a.append(e('strong','',m.annotation.source_identity?.title||'Shared annotation'));if(m.annotation.selection)a.append(e('div','',m.annotation.selection));if(m.annotation.annotation_url){const link=e('a','','Open annotation');link.href=m.annotation.annotation_url;a.append(link);}box.append(a);}
  messages.append(box);messages.scrollTop=messages.scrollHeight;
}
function renderRoom(next){
  room=next||room;status.textContent=String(room.status||'active').replace(/^./,c=>c.toUpperCase());count.textContent=Number(room.participant_count||0)+' present';
  participants.replaceChildren();(room.participants||[]).forEach(p=>{const row=e('div','live-person',''),name=e('span','',p.name+(p.is_self?' · You':''));row.append(name);if(p.cloaked)row.append(e('span','live-room-pill cloak','Cloaked'));participants.append(row);});
  if(join)join.hidden=!!room.joined||room.status!=='active';
  if(leave)leave.hidden=!room.joined;
  if(cloak){cloak.hidden=!room.joined||!room.allow_cloak||room.status!=='active';cloak.textContent=room.cloak_mode?'Leave Cloak':'Cloak Mode';}
  if(compose){compose.hidden=!room.joined||room.status!=='active';}
  if(end)end.hidden=!room.is_owner||room.status!=='active';
}
async function get(action,params={}){const u=new URL(api,location.origin);u.searchParams.set('action',action);Object.entries(params).forEach(([k,v])=>u.searchParams.set(k,String(v)));const r=await fetch(u,{credentials:'same-origin',cache:'no-store'});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error?.message||'Live Room request failed.');return j;}
async function post(action,payload={}){const r=await fetch(api,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action,csrf_token:cfg.csrf,...payload})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error?.message||'Live Room request failed.');return j;}
async function poll(){try{const j=await get('poll',{room:roomId,after:cursor});renderRoom(j.room);(j.messages||[]).forEach(appendMessage);cursor=Math.max(cursor,Number(j.cursor||0));}catch(e){}}
async function heartbeat(){if(!cfg.signed_in||!room.joined||room.status!=='active')return;try{await post('heartbeat',{room:roomId});}catch(e){}}
(cfg.messages||[]).forEach(appendMessage);renderRoom(room);
if(join)join.onclick=async()=>{try{const j=await post('join',{room:roomId,cloak_mode:false});renderRoom(j.room);await heartbeat();}catch(e){alert(e.message);}};
if(cloak)cloak.onclick=async()=>{try{await post('cloak',{room:roomId,enabled:!room.cloak_mode});await poll();}catch(e){alert(e.message);}};
if(leave)leave.onclick=async()=>{try{await post('leave',{room:roomId});room.joined=false;renderRoom(room);}catch(e){alert(e.message);}};
if(end)end.onclick=async()=>{if(!confirm('End this Live Room?'))return;try{const j=await post('end',{room:roomId});renderRoom(j.room);}catch(e){alert(e.message);}};
if(compose)compose.onsubmit=async ev=>{ev.preventDefault();const body=String(input.value||'').trim();if(!body)return;input.disabled=true;try{const j=await post('send',{room:roomId,body});input.value='';appendMessage(j.message);cursor=Math.max(cursor,Number(j.message?.cursor||0));}catch(e){alert(e.message);}finally{input.disabled=false;input.focus();}};
timer=setInterval(poll,2000);heartbeatTimer=setInterval(heartbeat,15000);poll();heartbeat();
window.addEventListener('beforeunload',()=>{clearInterval(timer);clearInterval(heartbeatTimer);});
})();