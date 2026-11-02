/* Local correction keeps only the user's text, never old authority or an automatic replay. */
(() => {
 'use strict';if(window.VP3ParticipantRecovery)return;
 const make=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);return n;};
 const button=(text,fn)=>{const n=make('button',text);n.type='button';n.addEventListener('click',()=>void fn());return n;};
 const currentId=()=>Number(window.STONEFELLOW_CHAT_CONTINUITY_V87?.conversationId?window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId():window.VP3_ORB_ADAPTER?.conversationRef?.()?.id||0);
 const host=()=>document.getElementById('chatThread')||document.querySelector('.vp3-eyes-content')||document.body;
 let correction=null,photo=null,generation=0;
 function clear(){generation++;if(correction){clearTimeout(correction.timer);correction.root.remove();correction=null;}if(photo){photo.root.remove();photo=null;}}
 async function capture(){
  const adapter=window.VP3_ORB_ADAPTER;if(!adapter)return;
  const question=window.STONEFELLOW_CHAT_CONTINUITY_V87?.pendingParticipantQuestion?.();
  const cid=currentId(),serial=++generation;
  if(correction){clearTimeout(correction.timer);correction.root.remove();}
  const root=make('section');root.className='vp3-participant-recovery';const status=make('p','Incorrect match dismissed. Choose who is speaking; voice matching will still require new voice evidence.');status.setAttribute('role','status');
  const select=make('select');select.setAttribute('aria-label','Correct conversation participant');const blank=make('option','Choose participant…');blank.value='';select.append(blank);
  const copy=question&&Number(question.conversation_id)===cid&&typeof question.message==='string'?question.message.slice(0,6000):'';
  const text=make('p',copy||'No recent question is available to restore.');text.className='vp3-recovery-question';
  root.append(make('strong','Correct participant'),status,select,text);
  if(copy)root.append(make('small','The earlier request may already have run. Check Chat history before sending it again. Restoring creates a draft only.'));
  const s={root,status,select,cid,copy,serial};correction=s;
  const valid=()=>correction===s&&serial===generation&&root.isConnected&&!document.hidden&&currentId()===cid;
  root.append(button('Use selected participant',async()=>{
   if(!valid()||!select.value)return;const id=select.value;status.textContent='Checking participant…';
   try{const p=await adapter.participantDetails(id);if(!valid()||select.value!==id)return;if(!p)throw Error('Participant unavailable.');window.VP3ParticipantContinuity?.setSelection({participant_id:String(p.id),display_name:p.name,conversation_note:p.note||'',source:'manual_selection',authentication_authority:false});status.textContent=`${p.name} selected for typed Chat. Restore the question when ready; voice turns still need a fresh voice match.`;}
   catch(error){if(valid())status.textContent=error.message||'Participant unavailable.';}
  }));
  const restore=button('Restore question as draft',()=>{
   if(!valid()||!s.copy)return;const input=document.getElementById('chatInput');if(!input)return;
   if(input.value.trim()){status.textContent='Your current draft is kept. Clear it before restoring the earlier question.';return;}
   input.value=s.copy;input.dispatchEvent(new Event('input',{bubbles:true}));input.focus();status.textContent='Question restored as a draft. Review the participant and history before choosing Send.';
  });restore.disabled=!copy;root.append(restore,button('Dismiss',()=>{if(correction===s){clearTimeout(s.timer);root.remove();correction=null;generation++;}}));host().append(root);
  s.timer=setTimeout(()=>{if(correction===s){s.copy='';text.textContent='The local recovery preview expired. Your original request remains in Chat history.';restore.disabled=true;}},120000);
  try{const people=await (adapter.loadConversationParticipants?.()||adapter.loadEyesProfiles());if(!valid())return;for(const p of people){const o=make('option',`${p.name} · #${p.id}`);o.value=String(p.id);select.append(o);}}
  catch(_){if(valid())status.textContent='Participant choices are unavailable. You can keep the question and reload the participant list in Agent Eyes.';}
 }
 document.addEventListener('submit',event=>{
  if(event.target?.id!=='chatForm')return;const input=document.getElementById('chatInput'),text=String(input?.value||'').trim();
  if(!/^(?:that['’]s not me|that is not me|you have the wrong person)[.!]?$/i.test(text))return;
  if(!window.VP3ParticipantContinuity?.hear?.(text))return;
  event.preventDefault();event.stopImmediatePropagation();input.value='';input.dispatchEvent(new Event('input',{bubbles:true}));
 },true);
 window.addEventListener('vp3:participant-photo-state',event=>{
  const d=event.detail||{};if(!['waiting','capturing','saving','saved','failed','deferred'].includes(d.state)||typeof d.message!=='string')return;
  if(!photo||!photo.root.isConnected){const root=make('section');root.className='vp3-participant-recovery';const status=make('p');status.setAttribute('role','status');root.append(make('strong','Participant photo capture'),status,button('Open Agent Eyes gallery',()=>{if(window.VP3AgentOrb?.openEyesGallery){window.VP3AgentOrb.openEyesGallery();return;}const panel=document.querySelector('.vp3-eyes-panel');if(panel?.hidden)window.VP3AgentEyes?.toggle?.();document.querySelector('.vp3-eyes-portraits')?.scrollIntoView({block:'nearest'});}));host().append(root);photo={root,status};}
  photo.root.dataset.photoState=d.state;photo.status.textContent=d.message.slice(0,500);
 });
 window.addEventListener('stonefellow:chat-context-changing',clear);window.addEventListener('homeserver:chat-context-changing',clear);window.addEventListener('pagehide',clear,{once:true});document.addEventListener('visibilitychange',()=>{if(document.hidden)clear();});
 window.VP3ParticipantRecovery={capture,clear};
})();
