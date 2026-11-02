/* Server-stored proposals. Only explicit authenticated owner confirmation changes sharing. */
(() => {
  'use strict';
  if(window.VP3ParticipantSetup)return;
  const cfg=window.VP3_ORB_CONFIG||{},sessions=new Set();let disposed=false;
  const make=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);return n;};
  const button=(text,fn)=>{const b=make('button',text);b.type='button';b.addEventListener('click',()=>void fn());return b;};
  const currentId=()=>Number(window.VP3_ORB_ADAPTER?.conversationRef?.()?.id||window.STONEFELLOW_CHAT_CONTINUITY_V87?.conversationId?.()||0);
  async function request(s,body){
    if(!cfg.participantUpdatesEndpoint)throw Error('Reload Chat to enable participant setup.');
    const response=await fetch(cfg.participantUpdatesEndpoint,{method:'POST',credentials:'same-origin',cache:'no-store',signal:s.abort.signal,headers:{'Content-Type':'application/json'},body:JSON.stringify({...body,agent_id:Number(window.VP3_ORB_ADAPTER?.conversationRef?.()?.agent||window.STONEFELLOW_CHAT?.agentId||0),conversation_id:s.cid,token:s.token,csrf_token:cfg.csrf})});
    const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Participant setup unavailable.');return data;
  }
  const valid=s=>!disposed&&!s.stopped&&s.root.isConnected&&!document.hidden&&currentId()===s.cid;
  function label(text,node){const l=make('label',text);l.append(node);return l;}
  function choices(rows,fixed,title){const select=make('select');select.setAttribute('aria-label',title);const empty=make('option','Choose…');empty.value='0';select.append(empty);for(const row of rows){const o=make('option',`${row.name} · #${row.id}`);o.value=String(row.id);select.append(o);}if(fixed){select.value=String(fixed);select.disabled=true;}return select;}
  function result(s,value){
    s.done=true;s.controls.replaceChildren();s.status.textContent=value.answer||'This setup is no longer pending.';
    if(value.decision==='confirm'&&value.participant_id){
      if(value.kind==='share_list')s.controls.append(button(`Use ${value.participant_name} in typed Chat`,async()=>{
        if(!valid(s))return;try{const p=await window.VP3_ORB_ADAPTER?.participantDetails?.(String(value.participant_id));if(!valid(s)||!p)throw Error('Participant details unavailable.');window.VP3ParticipantContinuity?.setSelection({participant_id:String(p.id),display_name:p.name,conversation_note:p.note||'',source:'manual_selection',authentication_authority:false});s.status.textContent=`${p.name} selected for typed Chat. Voice turns still require a current voice match.`;}catch(error){if(valid(s))s.status.textContent=error.message;}
      }));
      const show=button('Show my open work',()=>{if(!valid(s))return;const input=document.getElementById('chatInput'),form=document.getElementById('chatForm');if(!input||!form)return;input.value='What do I need to do?';input.dispatchEvent(new Event('input',{bubbles:true}));form.requestSubmit();});s.controls.append(show);
    }
  }
  async function commit(s,decision){
    if(!valid(s)||s.busy||s.done)return;
    if(decision==='confirm'&&(!s.owner.checked||!Number(s.profile.value)||(s.review.kind==='share_list'&&(!Number(s.list.value)||!s.mode.value)))){s.status.textContent='Choose the participant, list/access when applicable, and confirm you are the signed-in owner.';return;}
    s.busy=true;s.controls.querySelectorAll('button').forEach(b=>b.disabled=true);s.status.textContent=decision==='cancel'?'Cancelling…':'Saving the reviewed change…';
    try{
      const data=await request(s,{action:'confirm_setup',decision,owner_confirmed:true,revision:s.review.revision,participant_id:Number(s.profile.value),list_id:Number(s.list?.value)||0,access_mode:s.mode?.value||''});
      if(!valid(s))return;result(s,data.result);
      window.dispatchEvent(new CustomEvent('vp3:participant-sharing-changed'));
      void window.VP3ParticipantUpdates?.reload?.();
      if(valid(s))void window.STONEFELLOW_CHAT_CONTINUITY_V87?.syncConversation?.(s.cid);
    }catch(error){if(valid(s))s.status.textContent=(error.name==='AbortError'?'This review was stopped. Check the saved state before retrying.':error.message)||'The change could not be verified.';}
    finally{if(valid(s)){s.busy=false;s.controls.querySelectorAll('button').forEach(b=>b.disabled=false);}}
  }
  async function load(s){
    if(!valid(s))return;s.status.textContent='Loading owner review…';s.controls.replaceChildren();
    try{const data=await request(s,{action:'review_setup'});if(!valid(s))return;
      const r=data.review;s.review=r;if(r.status!=='prepared'){result(s,r.result||{});return;}
      s.controls.replaceChildren();s.profile=choices(r.profiles,r.participant_id,'Participant profile');s.controls.append(label(r.kind==='link_self'?'This recognized profile is me':'Share with',s.profile));
      if(r.kind==='share_list'){
        s.list=choices(r.lists,r.list_id,'List to share');s.mode=make('select');s.mode.setAttribute('aria-label','Shared list access');
        const blank=make('option','Choose access…');blank.value='';s.mode.append(blank);
        for(const mode of r.access_modes){const o=make('option',mode==='edit'?'Can view, add items and update tasks':'Can view only');o.value=mode;s.mode.append(o);}
        if(r.access_modes.length===1)s.mode.value=r.access_modes[0];
        s.controls.append(label('List',s.list),label('Access',s.mode),make('p','This share includes the canonical task items in this list. Other shares stay as they are.'));
        if(!r.enabled)s.controls.append(make('p','Participant-aware voice tasks are off. Sharing will be saved; link your own profile to enable voice tasks.'));
      }else s.controls.append(make('p','This enables participant-aware voice tasks and lets this recognized profile use the signed-in account’s personal task tools. It does not sign in another account or grant recognition consent.'));
      s.owner=make('input');s.owner.type='checkbox';s.controls.append(label('I am the signed-in account owner and approve this change',s.owner));
      s.controls.append(button(r.kind==='link_self'?'Confirm profile link':'Confirm sharing',()=>commit(s,'confirm')),button('Cancel',()=>commit(s,'cancel')),button('Reload choices',()=>load(s)));
      if(r.kind==='link_self'){const learn=make('button','Learn my voice');learn.type='button';learn.dataset.voiceEnrollmentOpen='';s.controls.append(learn);}
      s.status.textContent='Review the exact choices. Nothing has changed yet. A spoken yes does not save this card.';
    }catch(error){if(valid(s)){s.status.textContent=error.message||'This review could not be loaded.';s.controls.append(button('Reload review',()=>load(s)));}}
  }
  function open(b){
    const token=b.dataset.participantSetupToken,cid=Number(b.dataset.participantSetupConversation);if(!/^[a-f0-9]{32}$/.test(token||'')||cid<1||cid!==currentId())return;
    const host=b.closest('.message-body')||b.closest('.message')||b.parentElement;
    for(const s of sessions)if(s.token===token&&s.root.isConnected&&!s.stopped){s.root.scrollIntoView({block:'nearest'});return;}
    const root=make('section');root.className='chat-participant-setup';const controls=make('div'),status=make('p');status.setAttribute('role','status');root.append(make('strong','Participant setup · owner review'),controls,status);host.append(root);
    const s={root,controls,status,token,cid,abort:new AbortController(),stopped:false,busy:false,done:false};sessions.add(s);void load(s);
  }
  function stop(){for(const s of sessions){if(s.done||s.stopped)continue;s.stopped=true;s.abort.abort();s.controls.replaceChildren();s.status.textContent='Review closed because the conversation or participant changed. Open the saved card again to check its state.';}}
  document.addEventListener('click',event=>{const b=event.target.closest?.('[data-participant-setup-token]');if(b){event.preventDefault();open(b);}});
  for(const name of ['stonefellow:chat-context-changing','vp3:participant-task-context'])window.addEventListener(name,stop);
  document.addEventListener('visibilitychange',()=>{if(document.hidden)stop();});
  window.addEventListener('pagehide',()=>{disposed=true;stop();},{once:true});
  window.VP3ParticipantSetup={stop};
})();
