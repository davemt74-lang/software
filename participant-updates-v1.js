/* Signed-in owner controls sharing; local recognition only offers a scoped view. */
(() => {
  'use strict';
  const adapter=window.VP3_ORB_ADAPTER;
  if(!adapter?.participantUpdates||window.VP3ParticipantUpdates)return;
  const embed=new URLSearchParams(location.search).get('orb_embed')==='1'&&parent!==window;
  const make=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);return n;};
  const button=(text,fn)=>{const n=make('button',text);n.type='button';n.addEventListener('click',fn);return n;};
  const root=make('details');root.className='vp3-participant-updates';root.append(make('summary','Participant tasks and presence'));
  const status=make('p'),enabled=make('input'),offers=make('input'),self=make('select'),recipient=make('select'),shares=make('div'),presence=make('div'),offerBox=make('div'),updates=make('div');status.setAttribute('role','status');offerBox.setAttribute('aria-live','polite');
  enabled.type=offers.type='checkbox';self.setAttribute('aria-label','My recognized profile');recipient.setAttribute('aria-label','Share with participant');
  const field=(text,input)=>{const l=make('label',text);l.append(input);return l;};
  root.append(make('p','Choose your own recognized profile. Other participants can use only records you explicitly share in this Agent. Recognition never signs anyone in.'),field('Enable participant-aware voice tasks',enabled),field('Offer updates once per visit',offers),field('My recognized profile',self),field('Share with participant',recipient),shares,presence,offerBox,updates,status);
  let settings={enabled:window.VP3_ORB_CONFIG?.participantUpdatesEnabled===true},data=null,sequence=0,epoch=0,loadEpoch=0,disposed=false,cameraState={active:false,rows:[],at:0},heardState=null,actor=-1,lastHeartbeat=0,polling=false,settingsBusy=false,offerBusy=false,paused=false;
  const session=crypto.randomUUID();
  const request=body=>adapter.participantUpdates(body);
  const current=()=>!disposed&&!document.hidden;
  const labelFor=p=>`${p.display_name} · #${p.id}`;
  function participantId(){
    if(heardState&&Date.now()-heardState.at<90000)return heardState.id;
    if(cameraState.active&&Date.now()-cameraState.at<3500&&cameraState.rows.length===1)return Number(cameraState.rows[0]?.participant_id)||-1;
    return -1;
  }
  function invalidate(id=-1){
    if(id===actor)return;actor=id;epoch++;updates.replaceChildren();offerBox.replaceChildren();if(settings?.enabled)status.textContent='Participant changed. Request current shared updates.';
    if(settings?.enabled)window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:id}}));
  }
  function paintPresence(){
    presence.replaceChildren(make('strong','Recent observations'));
    for(const r of data?.presence||[]){const p=data.profiles.find(p=>Number(p.id)===Number(r.participant_id));if(!p)continue;
      const stamp=n=>Number(n)>0?new Date(Number(n)*1000).toLocaleString():'not recorded';
      presence.append(make('p',`${p.display_name}: last seen ${stamp(r.last_seen)}; last heard ${stamp(r.last_heard)}.`));
    }
    presence.append(make('small','A recent observation does not prove someone is still present. A new visit begins after 10 minutes without sight or speech. Up to four participants are tracked; overlapping voices are not separated.'));
  }
  function paintShares(){
    shares.replaceChildren();if(!data)return;
    const id=Number(recipient.value);if(!id)return;
    if(id===Number(self.value))shares.append(make('p','Your selected profile uses your own records. No extra shares are needed.'));
    for(const [key,kind] of [['lists','task_list'],['tasks','task'],['reminders','reminder']]){
      const group=make('fieldset');group.append(make('legend',key==='lists'?'Lists (including their items)':key==='tasks'?'Individual tasks':'Reminders'));
      for(const row of data.catalog[key]||[]){const input=make('select');input.dataset.kind=kind;input.dataset.id=String(row.id);
        for(const [value,text] of [['','Private'],['read','Can view'],['edit','Can view and update']]){const o=make('option',text);o.value=value;input.append(o);}
        const grant=data.grants.find(g=>Number(g.participant_id)===id&&g.kind===kind&&Number(g.id)===row.id);input.value=grant?.access||'';
        group.append(field(`${row.title} · #${row.id}`,input));
      }
      shares.append(group);
    }
    shares.append(make('small','These are the latest bounded records in this Agent. Unchecking a share revokes access on Save. Editing a shared list permits adding items and updating its tasks. Guests cannot create new lists or reminders.'));
  }
  async function load(){
    if(embed||disposed)return;const token=++loadEpoch;status.textContent='Loading participant settings…';
    try{const next=await request({action:'settings'});if(disposed||token!==loadEpoch)return;
      const previous=recipient.value;data=next;settings=next.settings;enabled.checked=settings.enabled;offers.checked=settings.offer_updates;
      for(const select of [self,recipient]){select.replaceChildren();const blank=make('option',select===self?'Choose my profile':'Choose a participant');blank.value='0';select.append(blank);for(const p of next.profiles){const o=make('option',labelFor(p));o.value=String(p.id);select.append(o);}}
      self.value=String(settings.self_participant_id);recipient.value=next.profiles.some(p=>String(p.id)===previous)?previous:'0';paintShares();paintPresence();status.textContent=settings.enabled?'Participant-aware voice tasks enabled. Unrecognized speakers will be asked who they are.':'Participant-aware tasks are off. Typed Chat continues to use the signed-in account.';
      invalidate(participantId());
    }catch(error){if(token===loadEpoch)status.textContent=error.message||'Participant settings unavailable.';}
  }
  const refresh=button('Reload settings',()=>void load());
  const save=button('Save participant sharing',async()=>{
    if(!data||settingsBusy)return;settingsBusy=true;save.disabled=true;status.textContent='Saving…';epoch++;updates.replaceChildren();offerBox.replaceChildren();
    try{
      const grants=Array.from(shares.querySelectorAll('select[data-kind]')).filter(s=>s.value).map(s=>({kind:s.dataset.kind,id:Number(s.dataset.id),access:s.value}));
      await request({action:'configure',revision:settings.revision,enabled:enabled.checked,offer_updates:offers.checked,self_participant_id:Number(self.value)||0,participant_id:Number(recipient.value)||0,grants});
      window.VP3VoiceGuard?.clear();await load();window.dispatchEvent(new CustomEvent('vp3:participant-sharing-changed'));status.textContent='Participant sharing saved. Recognition samples and account sign-in were not changed.';
    }catch(error){status.textContent=error.message||'Sharing could not be saved.';}finally{settingsBusy=false;save.disabled=false;}
  });
  async function show(id,{automatic=false}={}){
    const token=++epoch;updates.replaceChildren();status.textContent='Loading shared updates…';
    try{const result=await request({action:'show',participant_id:id});if(!current()||token!==epoch||(automatic&&participantId()!==id))return;
      updates.append(make('strong','Shared updates'));
      for(const list of result.updates.lists){
        const row=make('div');row.append(make('strong',list.title));
        row.append(button('Show list note',()=>window.VP3TaskNotes?.open({kind:'task_list',id:list.id,participant_id:id},{explicitParticipantView:true})));
        if(!list.items.length)row.append(make('p','Empty list.'));
        for(const item of list.items)row.append(make('p',`#${item.id} · ${item.title} · ${item.status}`));updates.append(row);
      }
      for(const task of result.updates.tasks)updates.append(make('p',`Task #${task.id} · ${task.title} · ${task.status}`));
      if(result.updates.reminders.length)updates.append(button('Show reminder note',()=>window.VP3TaskNotes?.open({kind:'reminders',view:'open',timezone:'UTC',participant_id:id},{explicitParticipantView:true})));
      for(const r of result.updates.reminders)updates.append(make('p',`Reminder #${r.id} · ${r.title} · ${r.status}${r.next_at?' · '+r.next_at+' UTC':''}`));
      if(!Object.values(result.updates).some(v=>Array.isArray(v)&&v.length))updates.append(make('p','No shared records are available.'));
      updates.append(make('small','Use a current voice match or “Use in typed Chat” for this participant to request updates. Shared notes are read only; Chat verifies edits.'));
      status.textContent='Shared updates loaded. Reminders were not acknowledged or marked done.';
    }catch(error){if(token===epoch)status.textContent=error.message||'Shared updates unavailable.';}
  }
  root.append(save,refresh,button('Show selected participant updates',()=>{const id=Number(recipient.value)||Number(self.value);if(id)void show(id);else status.textContent='Choose a participant first.';}));
  recipient.addEventListener('change',()=>{epoch++;updates.replaceChildren();paintShares();});self.addEventListener('change',paintShares);
  root.addEventListener('toggle',()=>{if(root.open&&!data)void load();});
  async function poll(){
    if(embed||paused||!current()||polling||settingsBusy||!settings?.enabled)return;polling=true;
    try{
      invalidate(participantId());
      if(cameraState.active&&Date.now()-cameraState.at<3500&&Date.now()-lastHeartbeat>=30000){
        const ids=[...new Set(cameraState.rows.map(r=>Number(r.participant_id)).filter(id=>Number.isInteger(id)&&id>0))].slice(0,4);lastHeartbeat=Date.now();
        await request({action:'heartbeat',session_id:session,sequence:++sequence,participants:ids});
      }
      if(data){const next=await request({action:'presence'});if(current()&&Array.isArray(next.presence)){data.presence=next.presence;paintPresence();}}
      const id=participantId(),token=epoch;if(id<1||!settings.offer_updates||adapter.busy?.()||offerBusy)return;
      offerBusy=true;
      try{
        const result=await request({action:'offer',participant_id:id});if(!current()||token!==epoch||participantId()!==id||adapter.busy?.()||!result.offer)return;
        const offer=result.offer;offerBox.replaceChildren(make('p',`${offer.name}: ${offer.text}`),button('Show my updates',()=>void show(id,{automatic:true})));root.open=true;
        window.VP3AgentOrb?.bubble?.(`${offer.name}, I can show your shared updates.`);
        await request({action:'ack_offer',participant_id:id,token:offer.token,visit_id:offer.visit_id});
      }finally{offerBusy=false;}
    }catch(_){/* No private content is announced; a failed lease can be retried. */}finally{polling=false;}
  }
  const timer=setInterval(()=>void poll(),5000);
  window.VP3ParticipantUpdates={
    mount:panel=>{if(!embed){panel.append(root);void load();}},
    camera:value=>{const rows=Array.isArray(value?.rows)?value.rows.slice(0,4).sort((a,b)=>String(a.track_id).localeCompare(String(b.track_id))):[];const next=JSON.stringify([value?.active===true,rows.map(r=>[r.participant_id,r.track_id])]);const wasActive=cameraState.active;const old=JSON.stringify([cameraState.active,cameraState.rows.map(r=>[r.participant_id,r.track_id])]);cameraState={active:value?.active===true,rows,at:Date.now()};if(next!==old){if(value?.active===true&&!wasActive)paused=false;heardState=null;invalidate(participantId());}void poll();},
    heard:value=>{const id=Number(value?.participant_id);if(!Number.isInteger(id)||id<1)return;paused=false;heardState={id,at:Date.now()};invalidate(id);void poll();},
    selected:value=>{if(settings?.enabled)invalidate(Number(value?.participant_id)||-1);},
    // Scheduled owner reminder speech is paused while participant-aware mode is enabled.
    suppressOwnerAnnouncements:()=>settings.enabled,
    allowsNote:ref=>!settings.enabled||Number(ref.participant_id||0)===actor||(actor===Number(settings.self_participant_id)&&Number(ref.participant_id||0)===0)||(actor===-1&&!cameraState.active&&!heardState&&Number(ref.participant_id||0)===0),
    stop:()=>{paused=true;epoch++;heardState=null;offerBox.replaceChildren();updates.replaceChildren();invalidate(-1);},
    reload:load,
  };
  document.addEventListener('visibilitychange',()=>{if(document.hidden)window.VP3ParticipantUpdates.stop();});
  document.addEventListener('click',event=>{if(event.target.closest?.('[data-orb-stop],[data-participant-reject]'))window.VP3ParticipantUpdates.stop();},true);
  for(const name of ['vp3:participant-memory-changed','vp3:participant-recognition-forgotten'])window.addEventListener(name,()=>{window.VP3ParticipantUpdates.stop();data=null;void load();});
  window.addEventListener('pagehide',()=>{disposed=true;epoch++;clearInterval(timer);},{once:true});
})();
