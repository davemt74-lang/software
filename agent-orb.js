(function(){
  'use strict';
  const jobPrefixes={initiative:'workflow:',initiative_work:'workflow:',image:'image:',development:'development:',mission:'mission:',gmail:'gmail:',gmail_work:'gmail:'};
  function normalizeJobs(source,jobs){
    if(typeof source!=='string'||!Object.hasOwn(jobPrefixes,source)||!Array.isArray(jobs))return null;
    const now=Date.now(),prefix=jobPrefixes[source];
    return jobs.slice(0,64).flatMap(job=>{
      const {id,expiresAt}=job||{};
      if(typeof id!=='string'||!id.startsWith(prefix)||id.length===prefix.length||!/^[a-z0-9:-]{1,120}$/.test(id)
        ||!Number.isSafeInteger(expiresAt)||expiresAt<=now)return [];
      const active={id,expiresAt:Math.min(expiresAt,now+120000)};
      if(['initiative','initiative_work'].includes(source)&&/^workflow:[1-9]\d{0,19}$/.test(id)&&['assessment','preparation'].includes(job.kind)){
        active.kind=job.kind;
        if(typeof job.specialist==='string'&&/^[a-z][a-z0-9-]{0,47}$/.test(job.specialist))active.specialist=job.specialist;
        if(typeof job.step==='string')active.step=job.step.replace(/[\u0000-\u001f\u007f]/g,' ').trim().slice(0,240);
      }
      return [active];
    });
  }
  function syncCoordinatorSpecialist(jobs){
    const assessments=jobs.filter(job=>job.kind==='assessment'&&(!job.specialist||job.specialist==='ob1'));
    if(!assessments.length){window.VP3ChatSpecialists?.remove('ob1');return;}
    window.VP3ChatSpecialists?.update('ob1',{
      label:'OB1',title:'Specialist coordination',status:'Working',
      detail:assessments[0].step||'Reviewing Top of Mind for useful opportunities.'
    });
  }
  const specialistFamilies={
    planning:{label:'Planning & Scheduling'},tasks:{label:'Tasks & Lists'},
    gmail:{label:'Gmail',fallbackFor:['email','gmail-inbox-review']},
    media:{label:'Media & Entertainment'},
    location:{label:'Location Specialist'},
    operator:{label:'Operator Specialist'},
    profile:{label:'Profile',viewLabel:'Open Profile report'},
    curiosity:{label:'Curiosity'},
    transcripts:{label:'Transcriptions',viewLabel:'Open Transcription Intelligence',
      onView:()=>window.STONEFELLOW_TRANSCRIPTION_CANVAS?.open?.() ?? false},
    'connected-sites':{label:'Connected Sites',viewLabel:'Open connected sites',onView:()=>window.VP3ConnectedSites?.openWorkspace?.() ?? false},
    research:{label:'Research Specialist',fallbackFor:['research']},
    'image-generator':{label:'Image Generator',fallbackFor:['image-generator']},
    coding:{label:'Coding Agent',fallbackFor:['coding']},
    development:{key:'development:idle',label:'Agent Development',fallbackFor:['development']},
    mission:{label:'Missions',fallbackFor:['mission']}
  };
  const specialistLabels={thinking:'Thinking',working:'Working',waiting_specialist:'Waiting for another specialist',
    waiting_owner:'Waiting for you',waiting_reply:'Awaiting your reply',queued:'Queued',ready:'Ready to review',
    ready_to_report:'Ready to report',completed:'Completed',checked:'Checked',idle:'Idle',
    armed:'Armed',awaiting_consent:'Awaiting phone approval',
    paused:'Paused',blocked:'Blocked',failed:'Needs attention',unavailable:'Status unavailable'};
  const specialistWorking=state=>state==='thinking'||state==='working';
  const activeSpecialist=item=>item.presence?specialistWorking(item.state):['queued','running','paused'].includes(item.state);

  async function readWorkStatus(endpoint,owner,agent,binding,signal,conversationId=null){
    const url=new URL(endpoint,location.href);
    if(url.origin!==location.origin||url.username||url.password)throw Error('Work status origin changed.');
    url.searchParams.set('view','work');url.searchParams.set('agent_id',String(agent));
    if(conversationId!==null){
      url.searchParams.set('scope','chat');url.searchParams.set('conversation_id',String(conversationId));
    }
    const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal,headers:{'X-VP3-Working-Attention':binding}});
    if(!response.ok)throw Object.assign(Error('Work status is unavailable.'),{status:response.status});
    const body=await response.json(),view=body?.state,health=view?.health,observed=Date.parse(view?.observed_at);
    if(body?.ok!==true||view?.contract!=='vp3.agent.work.v1'||view.owner_user_id!==owner||view.agent_id!==agent
      ||view.working_attention_binding!==binding||!Number.isSafeInteger(view.conversation_id)||view.conversation_id<0
      ||(conversationId!==null&&view.conversation_id!==conversationId)||!Number.isFinite(observed)
      ||observed<Date.now()-10000||observed>Date.now()+5000||typeof health?.enabled!=='boolean'
      ||!Array.isArray(health.active_jobs)||health.active_jobs.length>25)throw Error('Work status could not be verified.');
    return view;
  }

  function installChatSpecialistPolling({registry,read,scope,configured,allowed,setAssessmentJobs}){
    let timer=0,request=null,denied=null,participant=0;
    const known=new Set();
    const canKeepLaunchers=()=>!participant&&allowed();
    function placeholder(id){
      const {key=id,label,fallbackFor,viewLabel,onView}=specialistFamilies[id];
      registry.update(key,{label,fallbackFor,state:'unavailable',status:specialistLabels.unavailable,
        presence:true,inline:false,keepLauncher:true,viewLabel,onView});
    }
    function reset(){
      setAssessmentJobs([]);
      const keep=canKeepLaunchers();
      if(!keep)known.clear();
      for(const [id,{key=id}] of Object.entries(specialistFamilies)){
        if(keep&&(known.has(id)||configured(id)))placeholder(id);
        else registry.remove(key);
      }
    }
    function stop(){
      clearTimeout(timer);timer=0;
      request?.abort();request=null;
      reset();
    }
    const current=()=>participant?null:scope();
    function publish(view,conversationId){
      const rows=Array.isArray(view.specialists)?view.specialists.slice(0,24):[];
      const now=Date.now(),freshUntil=Date.parse(view.observed_at)+12000;
      const assessments=(normalizeJobs('initiative_work',view.health.enabled?view.health.active_jobs:[])||[])
        .filter(job=>job.kind==='assessment')
        .map(job=>({...job,expiresAt:Math.min(job.expiresAt,freshUntil)}));
      setAssessmentJobs(assessments);
      let working=assessments.length>0;
      for(const [id,{key=id,label,fallbackFor,viewLabel,onView}] of Object.entries(specialistFamilies)){
        const configuredState=configured(id);
        const observed=rows.find(item=>item?.id===id&&Object.hasOwn(specialistLabels,item.state)
          &&(item.conversation_id==null||item.conversation_id===conversationId));
        if(observed)known.add(id);
        const row=observed||(configuredState?{state:configuredState}:null);
        if(!row){if(known.has(id))placeholder(id);continue;}
        // Operator observes carrier receipts, not a worker lease.
        const lease=Date.parse(id==='operator'?row.status_fresh_until:row.lease_expires_at);
        const live=specialistWorking(row.state)&&lease>now;
        const state=specialistWorking(row.state)&&!live?'unavailable':row.state;
        working||=live;
        registry.update(key,{
          label,fallbackFor,state,status:specialistLabels[state],presence:true,inline:false,keepLauncher:true,viewLabel,onView,
          detail:state===row.state&&typeof row.detail==='string'?row.detail.slice(0,480):'',
          progress:state===row.state?row.progress:null,
          expiresAt:live?Math.min(lease,freshUntil):freshUntil
        });
      }
      return working;
    }
    async function refresh(){
      timer=0;
      const conversationId=current();
      if(conversationId===null){reset();return;}
      if(request||conversationId===denied)return;
      const controller=new AbortController();request=controller;
      const timeout=setTimeout(()=>controller.abort(),5000);
      let active=false;
      try{
        const view=await read(controller.signal,conversationId);
        if(request!==controller||current()!==conversationId)return;
        active=publish(view,conversationId);
      }catch(error){
        if(request!==controller||current()!==conversationId)return;
        if([401,403,419].includes(error?.status)){
          denied=conversationId;
          known.clear();
        }
        reset();
      }finally{
        clearTimeout(timeout);
        if(request===controller){
          request=null;
          if(current()===conversationId&&denied!==conversationId)timer=setTimeout(refresh,active?2500:8000);
        }
      }
    }
    function manage(){
      if(current()===null)stop();
      else if(!timer&&!request)void refresh();
    }
    window.addEventListener('stonefellow:chat-context-changing',stop);
    window.addEventListener('vp3:chat-context-ready',manage);
    window.addEventListener('vp3:participant-selection-changed',()=>{stop();manage();});
    window.addEventListener('vp3:participant-task-context',event=>{participant=Number(event.detail?.participant_id)||0;stop();manage();});
    document.addEventListener('visibilitychange',manage);
    window.addEventListener('pagehide',stop);
    window.addEventListener('pageshow',event=>{if(event.persisted){reset();manage();}});
    reset();
    manage();
  }
  function installOrbAppearance(root,orbVisual,adapter){
    if(typeof adapter.saveOrbShape!=='function'||typeof HTMLDialogElement!=='function')return null;
    const dialog=document.createElement('dialog');
    dialog.id='vp3OrbAppearance';
    dialog.className='vp3-orb-appearance';
    dialog.setAttribute('aria-labelledby','vp3OrbAppearanceTitle');
    dialog.innerHTML=`<form>
      <h2 id="vp3OrbAppearanceTitle">Edit Agent orb</h2>
      <fieldset>
        <legend>Orb style</legend>
        <label><input type="radio" name="orb_shape" value="orbit">Orb with rings</label>
        <label><input type="radio" name="orb_shape" value="particle_face">Original particle face</label>
      </fieldset>
      <p role="status"></p>
      <div class="vp3-orb-appearance-actions">
        <button type="button">Cancel</button><button type="submit">Save style</button>
      </div>
    </form>`;
    document.body.append(dialog);
    const edit=document.createElement('button');
    edit.type='button';
    edit.textContent='Edit';
    edit.setAttribute('aria-label','Edit Agent orb');
    edit.setAttribute('aria-haspopup','dialog');
    edit.setAttribute('aria-controls',dialog.id);
    root.querySelector('.vp3-orb-controls').append(edit);

    const form=dialog.querySelector('form'),choices=form.elements.namedItem('orb_shape');
    const styles=dialog.querySelector('fieldset'),status=dialog.querySelector('[role="status"]');
    const cancel=dialog.querySelector('button[type="button"]'),save=dialog.querySelector('button[type="submit"]');
    const isOwner=()=>!window.VP3ParticipantContinuity?.selectedContext?.();
    let saving=false;
    function syncContext(){
      edit.hidden=!isOwner();
      if(edit.hidden&&dialog.open)dialog.close();
    }
    syncContext();
    window.addEventListener('vp3:participant-selection-changed',syncContext);
    edit.addEventListener('click',()=>{
      if(!isOwner()||saving||dialog.open)return;
      choices.value=orbVisual.dataset.orbShape==='particle_face'?'particle_face':'orbit';
      styles.disabled=save.disabled=!adapter.orbShapeReady;
      status.textContent=adapter.orbShapeReady?'':'Run Database Upgrade to edit the Agent orb.';
      dialog.showModal();
      if(!styles.disabled)form.querySelector('input:checked')?.focus();
    });
    cancel.addEventListener('click',()=>dialog.close());
    dialog.addEventListener('keydown',event=>{if(event.key==='Escape')event.stopPropagation();});
    dialog.addEventListener('cancel',event=>{if(saving)event.preventDefault();});
    dialog.addEventListener('close',()=>{(edit.hidden?root.querySelector('.vp3-orb-ball'):edit).focus();});
    form.addEventListener('submit',async event=>{
      event.preventDefault();
      const shape=choices.value;
      if(saving||!adapter.orbShapeReady||!isOwner()||!['orbit','particle_face'].includes(shape))return;
      if(shape===orbVisual.dataset.orbShape){dialog.close();return;}
      saving=styles.disabled=cancel.disabled=save.disabled=true;
      status.textContent='Saving style…';
      const controller=new AbortController();
      const timeout=setTimeout(()=>controller.abort(),12000);
      try{
        const savedShape=await adapter.saveOrbShape(shape,controller.signal);
        if(savedShape!==shape)throw Error('The saved orb style could not be confirmed.');
        window.dispatchEvent(new CustomEvent('vp3:agent-orb-shape',{detail:{shape:savedShape}}));
        if(dialog.open)dialog.close();
      }catch(error){
        status.textContent=error?.name==='AbortError'?'The save timed out. Try again.':error?.message||'Could not save the orb style.';
      }finally{
        clearTimeout(timeout);
        saving=styles.disabled=cancel.disabled=save.disabled=false;
      }
    });
    return dialog;
  }
  const boot=()=>{
    const adapter=window.VP3_ORB_ADAPTER;if(!adapter||window.VP3AgentOrb)return;
    const $=id=>document.getElementById(id),embed=new URLSearchParams(location.search).get('orb_embed')==='1'&&parent!==window;
    const origin=location.origin;let lastState='',disposed=false,timer=0,pendingText='',pendingListen=false,pendingReplyWindow=false,pendingVoiceEnrollment='',introductionMode='',ready=false,frame=null,returnFocus=null,restorePanel=null,photoRoute='';
    let working=false;
    const pageOwner=String(adapter.ownerKey),pageAgent=Number(window.STONEFELLOW_CHAT?.agentId??window.VP3_ORB_CONFIG?.agentId??0);
    const pageBinding=String(window.STONEFELLOW_CHAT?.workingAttentionBinding||window.VP3_ORB_CONFIG?.workingAttentionBinding||'');
    const workEndpoint=typeof window.VP3_ORB_CONFIG?.topOfMindEndpoint==='string'?window.VP3_ORB_CONFIG.topOfMindEndpoint:'';
    const validWorkAccount=!!workEndpoint&&!!pageBinding&&/^\d+$/.test(pageOwner)&&Number(pageOwner)>0
      &&Number.isSafeInteger(pageAgent)&&pageAgent>=0;
    const jobsAllowed=(participant=0)=>!disposed&&participant<=0&&!window.VP3ParticipantContinuity?.selectedContext?.()
      &&String(adapter.ownerKey)===pageOwner&&String(window.VP3_ORB_CONFIG?.ownerKey??adapter.ownerKey)===pageOwner
      &&Number(window.STONEFELLOW_CHAT?.agentId??window.VP3_ORB_CONFIG?.agentId??0)===pageAgent
      &&(!window.STONEFELLOW_CHAT||String(window.STONEFELLOW_CHAT.userId)===pageOwner)
      &&String(window.STONEFELLOW_CHAT?.workingAttentionBinding||window.VP3_ORB_CONFIG?.workingAttentionBinding||'')===pageBinding;
    const hasChatWorkPoll=Boolean(validWorkAccount&&window.STONEFELLOW_CHAT&&window.VP3ChatSpecialists);
    if(hasChatWorkPoll){
      const scope=()=>{
        if(document.hidden||!jobsAllowed())return null;
        const chat=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
        if(chat?.isReady?.()!==true||chat?.isRestoring?.()||chat.agentId?.()!==pageAgent)return null;
        const cid=chat.conversationId?.();
        return Number.isSafeInteger(cid)&&cid>=0?cid:null;
      };
      const configured=id=>{
        const config=window.VP3_CHAT_SPECIALIST_PRESENCE;
        if(config?.owner_user_id!==Number(pageOwner)||config.agent_id!==pageAgent)return '';
        const state=config.states?.[id];
        return ['idle','paused','unavailable'].includes(state)?state:'';
      };
      installChatSpecialistPolling({registry:window.VP3ChatSpecialists,scope,configured,allowed:jobsAllowed,
        setAssessmentJobs:jobs=>window.VP3AgentOrb?.setJobs?.('initiative_work',jobs),
        read:(signal,cid)=>readWorkStatus(workEndpoint,Number(pageOwner),pageAgent,pageBinding,signal,cid)});
    }
    const state=()=>({...window.VP3EyesCore.visualState(adapter.state()),working});
    const photoRouteEvent=(id,state)=>window.dispatchEvent(new CustomEvent('vp3:chat-route',{detail:{id,state,voiceTurn:true}}));
    const finishPhotoRoute=id=>{if(id&&photoRoute===id){photoRoute='';photoRouteEvent(id,'handled');}};
    const userTurn=event=>{
      if(event.target?.id!=='chatForm')return;
      const input=$('chatInput'),text=String(input?.value||'').slice(0,240),context={voice:window.VP3VoiceGuard?.wasVoiceTurn?.(text)===true,known_voice:window.VP3VoiceGuard?.hasRecognizedTurn?.(text)===true};
      const photoReply=embed?introductionMode==='visual-consent'&&!!window.VP3EyesCore.visualReply(text):window.VP3AgentEyes?.acceptsPhotoReply?.(text);
      const routeId=photoReply&&context.voice?crypto.randomUUID():'';
      if(photoReply){event.preventDefault();event.stopImmediatePropagation();if(input){input.value='';input.dispatchEvent(new Event('input',{bubbles:true}));}if(routeId){photoRoute=routeId;photoRouteEvent(routeId,'started');}}
      if(embed)parent.postMessage({channel:'vp3-orb',kind:'user-turn',text,...context,photoRoute:routeId},origin);
      else void Promise.resolve(window.VP3AgentEyes?.hear?.(text,context)).finally(()=>finishPhotoRoute(routeId)).catch(()=>{});
    };
    document.addEventListener('submit',userTurn,true);window.addEventListener('pagehide',()=>document.removeEventListener('submit',userTurn,true),{once:true});
    if(embed){
      let activityEpoch=0,activityActor=0,jobExpiryTimer=0;
      const jobSnapshots=new Map();
      function refreshEmbeddedJobs(){
        clearTimeout(jobExpiryTimer);
        let nextExpiry=Infinity;
        const active=[];
        for(const [source,jobs] of jobSnapshots){
          const current=jobsAllowed(activityActor)?jobs.filter(job=>job.expiresAt>Date.now()):[];
          if(current.length)jobSnapshots.set(source,current);else jobSnapshots.delete(source);
          for(const job of current){active.push(job);nextExpiry=Math.min(nextExpiry,job.expiresAt);}
        }
        working=active.length>0;
        syncCoordinatorSpecialist(hasChatWorkPoll?jobSnapshots.get('initiative_work')||[]:active);
        if(Number.isFinite(nextExpiry))jobExpiryTimer=setTimeout(refreshEmbeddedJobs,Math.max(1,nextExpiry-Date.now()));
      }
      function contextChanged(){activityEpoch++;jobSnapshots.clear();refreshEmbeddedJobs();announce();}
      for(const name of ['stonefellow:chat-context-changing','vp3:participant-selection-changed'])window.addEventListener(name,contextChanged);
      window.addEventListener('vp3:participant-task-context',event=>{activityActor=Number(event.detail?.participant_id)||0;contextChanged();});
      let queuedEnrollment='',queuedIntroduction='',queuedResponse=false,initiativeAction=null;window.addEventListener('vp3:voice-guard-ready',()=>{if(queuedEnrollment){void window.VP3VoiceGuard.beginEnrollment(queuedEnrollment);queuedEnrollment='';}window.VP3VoiceGuard.inviteIntroduction(queuedIntroduction);introductionMode=queuedIntroduction;});
      document.documentElement.dataset.vp3OrbEmbed='1';
      const activityContext=()=>{
        const chat=window.STONEFELLOW_CHAT,live=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
        const ref=adapter.conversationRef?.();
        return {ownerKey:String(adapter.ownerKey),agent_id:Number(chat?.agentId||0),
          conversation_id:Number(ref?.id||0),working_attention_binding:String(chat?.workingAttentionBinding||''),
          ready:live?.isReady?.()===true&&live?.isRestoring?.()===false&&!!ref,
          participant:activityActor>0||Boolean(window.VP3ParticipantContinuity?.selectedContext?.()),epoch:activityEpoch};
      };
      const announce=()=>{if(!disposed){if(working&&!jobsAllowed(activityActor)){jobSnapshots.clear();refreshEmbeddedJobs();}parent.postMessage({channel:'vp3-orb',kind:'state',state:state(),conversation:adapter.conversationRef?.(),activity_context:activityContext()},origin);}};
      window.addEventListener('vp3:chat-context-ready',announce);
      const commands=async event=>{
        if(event.origin!==origin||event.source!==parent||event.data?.channel!=='vp3-orb')return;
        if(event.data.kind==='compose'){
          const input=$('chatInput'),text=String(event.data.text||'').slice(0,6000);
          if(!input||input.value.trim()||adapter.busy())return parent.postMessage({channel:'vp3-orb',kind:'draft-blocked'},origin);
          input.value=text;input.dispatchEvent(new Event('input',{bubbles:true}));
          parent.postMessage({channel:'vp3-orb',kind:'draft-accepted'},origin);
          $('chatForm')?.requestSubmit();
        }
        if(event.data.kind==='participant-selection')window.VP3ParticipantContinuity?.setSelection(event.data.value,true);
        if(event.data.kind==='participant-presence')window.VP3ParticipantContinuity?.updatePresence(event.data.value,true);
        if(event.data.kind==='participant-reject')window.VP3ParticipantContinuity?.rejectCurrent(true,String(event.data.value?.participant_id||''));
        if(event.data.kind==='participant-photo-state'){const d=event.data.value||{};if(['waiting','capturing','saving','saved','failed','deferred'].includes(d.state)&&typeof d.message==='string')window.dispatchEvent(new CustomEvent('vp3:participant-photo-state',{detail:{state:d.state,message:d.message.slice(0,500)}}));}
        if(event.data.kind==='participant-invalidate')window.VP3ParticipantContinuity?.invalidate();
        if(event.data.kind==='activity-details')window.VP3_COGNITIVE_PRESENTATION?.openDetails?.();
        if(event.data.kind==='activity-specialist'&&typeof event.data.key==='string')window.VP3ChatSpecialists?.view?.(event.data.key);
        if(event.data.kind==='activity-action'){
          const current=adapter.conversationRef?.(),mine=activityEpoch;
          const owner=String(adapter.ownerKey),binding=String(window.STONEFELLOW_CHAT?.workingAttentionBinding||'');
          let accepted=false;
          if(String(event.data.ownerKey)===owner&&event.data.agent_id===Number(current?.agent)&&event.data.conversation_id===Number(current?.id)&&binding&&event.data.working_attention_binding===binding){
            try{accepted=await runActivityAction(event.data.action);}catch(_){}
          }
          if(activityActor>0||window.VP3ParticipantContinuity?.selectedContext?.()||owner!==String(window.VP3_ORB_CONFIG?.ownerKey)||binding!==String(window.STONEFELLOW_CHAT?.workingAttentionBinding||''))accepted=false;
          if(mine!==activityEpoch&&!['chat','youtube','canvas','mission'].includes(event.data.action?.kind))accepted=false;
          parent.postMessage({channel:'vp3-orb',kind:'activity-action-result',request_id:event.data.request_id,accepted},origin);
        }
        if(event.data.kind==='initiative-refresh')window.dispatchEvent(new Event('vp3:agent-orb-ready'));
        if(event.data.kind==='initiative-action'&&['on','off','retry','diagnostics'].includes(event.data.action))initiativeAction?.(event.data.action);
        if(event.data.kind==='stop')adapter.stop();
        if(event.data.kind==='listen')adapter.listen();
        if(event.data.kind==='response-window'){queuedResponse=true;const listen=()=>{if(queuedResponse){queuedResponse=false;(window.VP3ConversationControls||window.HomeServerConversationVoice)?.responseWindow?.();}};if(window.VP3ConversationControls||window.HomeServerConversationVoice)listen();else window.addEventListener('stonefellow:conversation-engine-ready',listen,{once:true});}
        if(event.data.kind==='response-window-cancel'){queuedResponse=false;(window.VP3ConversationControls||window.HomeServerConversationVoice)?.cancelResponseWindow?.();}
        if(event.data.kind==='photo-reply-complete')finishPhotoRoute(String(event.data.id||''));
        if(event.data.kind==='voice-enroll'){if(window.VP3VoiceGuard)void window.VP3VoiceGuard.beginEnrollment(String(event.data.id||''));else queuedEnrollment=String(event.data.id||'');}
        if(event.data.kind==='voice-enroll-cancel'){queuedEnrollment='';window.VP3VoiceGuard?.cancelEnrollment();}
        if(event.data.kind==='introduction-window'){queuedIntroduction=['name','visual-consent'].includes(event.data.mode)?event.data.mode:'';introductionMode=queuedIntroduction;window.VP3VoiceGuard?.inviteIntroduction(queuedIntroduction);}
      };
      window.addEventListener('message',commands);timer=setInterval(announce,250);
      window.addEventListener('vp3:agent-orb-shape',event=>{
        const shape=event.detail?.shape;
        if(shape==='orbit'||shape==='particle_face')parent.postMessage({channel:'vp3-orb',kind:'agent-orb-shape',ownerKey:adapter.ownerKey,shape},origin);
      });
      window.addEventListener('vp3:voice-filter-status',event=>parent.postMessage({channel:'vp3-orb',kind:'voice-filter-status',message:String(event.detail?.message||'').slice(0,300)},origin));
      async function runActivityAction(action){
        if(activityActor>0||window.VP3ParticipantContinuity?.selectedContext?.()||!action||typeof action.kind!=='string')return false;
        const chat=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
        if(action.kind==='song'&&Number.isSafeInteger(action.track_id)&&action.track_id>0)return await chat?.playActivityTrack?.(action.track_id)===true;
        if(action.kind==='canvas'&&typeof action.canvas_id==='string')return await window.VP3CodingCanvasV1?.open?.(action.canvas_id,action.revision)===true;
        if(action.kind==='mission'&&typeof action.mission_id==='string')return await window.VP3AgentMissionsV1?.open?.(action.mission_id)===true;
        if(action.kind==='task_notes'&&window.VP3TaskNotes?.open){
          if(Number.isSafeInteger(action.list_id)&&action.list_id>0)window.VP3TaskNotes.open({kind:'task_list',id:action.list_id});
          else window.VP3TaskNotes.open({kind:'reminders',view:['open','today','overdue','all'].includes(action.view)?action.view:'open'});
          return true;
        }
        if(['chat','youtube'].includes(action.kind)&&Number.isSafeInteger(action.conversation_id)&&action.conversation_id>0&&chat?.openConversation){
          const exact=Number.isSafeInteger(action.message_id)&&action.message_id>0;
          if(await chat.openConversation(action.conversation_id,exact?{messageId:action.message_id}:{})!==true||window.VP3ParticipantContinuity?.selectedContext?.())return false;
          if(exact){const row=document.querySelector('#chatThread [data-chat-message-id="'+action.message_id+'"][data-chat-conversation-id="'+action.conversation_id+'"]');if(!row)return false;row.scrollIntoView({block:'center'});}
          return true;
        }
        return false;
      }
      const announceSpecialists=()=>parent.postMessage({channel:'vp3-orb',kind:'activity-specialists',ownerKey:adapter.ownerKey,conversation:adapter.conversationRef?.(),items:(window.VP3ChatSpecialists?.snapshot?.({includeResults:true})||[]).slice(0,12),activity_context:activityContext()},origin);
      window.addEventListener('vp3:chat-specialists-changed',announceSpecialists);
      window.VP3AgentOrb={
        state,isWorking:()=>working,
        openActivity:options=>{parent.postMessage({channel:'vp3-orb',kind:'activity-open',ownerKey:adapter.ownerKey,specialist:options?.specialist,focus:options?.focus,activity_context:activityContext()},origin);return true;},
        openEyesGallery:()=>parent.postMessage({channel:'vp3-orb',kind:'open-eyes-gallery'},origin),
        participantSignal:(kind,value)=>{if(['speaker','task-heard','attribution-start','reject','selection'].includes(kind))parent.postMessage({channel:'vp3-orb',kind:'participant-'+kind,value},origin);},
        setJobs(source,jobs){
          const active=normalizeJobs(source,jobs);
          if(!active)return;
          jobSnapshots.set(source,jobsAllowed(activityActor)?active:[]);
          refreshEmbeddedJobs();
          if(source!=='initiative_work'){
            parent.postMessage({channel:'vp3-orb',kind:'jobs',ownerKey:adapter.ownerKey,source,jobs:jobSnapshots.get(source)||[],activity_context:activityContext()},origin);
          }
        },
        setInitiativeStatus(view,onAction){
          initiativeAction=view&&typeof onAction==='function'?onAction:null;
          parent.postMessage({channel:'vp3-orb',kind:'initiative-status',ownerKey:adapter.ownerKey,view},origin);
        }
      };
      parent.postMessage({channel:'vp3-orb',kind:'ready',conversation:adapter.conversationRef?.(),activity_context:activityContext()},origin);
      announceSpecialists();
      window.dispatchEvent(new Event('vp3:agent-orb-ready'));
      window.addEventListener('pagehide',()=>{disposed=true;jobSnapshots.clear();refreshEmbeddedJobs();clearInterval(timer);window.removeEventListener('message',commands);},{once:true});
      return;
    }
    const root=document.createElement('aside');root.className='vp3-orb-root';root.dataset.state='idle';root.dataset.working='false';
    root.innerHTML='<div class="vp3-orb-bubble" role="status" hidden></div>'
      +'<button type="button" class="vp3-orb-ball" aria-label="Open Agent Chat" aria-controls="vp3AgentChatPopup vp3AgentActivityHub" aria-expanded="false" aria-describedby="vp3OrbGesture" aria-keyshortcuts="Shift+Enter"><span class="vp3-public-orb" data-vp3-public-orb data-vp3-orb-personal data-vp3-orb-work aria-hidden="true"></span></button>'
      +'<span id="vp3OrbGesture" hidden>Double-click, double-tap, or press Shift+Enter to open or close Top of Mind.</span>'
      +'<span class="vp3-orb-state"><span data-orb-voice-state>Ready</span><span id="vp3OrbJobsStatus" role="status"></span></span><div class="vp3-orb-controls"><button type="button" data-orb-stop>Stop</button><button type="button" data-orb-eyes>Eyes</button></div>'
      +'<section class="vp3-orb-initiative" data-agent-initiative-status aria-label="Agent background work" hidden>'
      +'<section id="vp3AgentActivityHub" data-agent-activity-host hidden></section>'
      +'<details data-agent-worker-details><summary>Worker details</summary><div id="vp3OrbInitiativeDetails"><strong></strong><small hidden></small></div>'
      +'<div class="vp3-orb-initiative-actions"><button type="button" hidden></button><button type="button" data-agent-initiative-retry hidden>Retry</button><button type="button" data-agent-initiative-diagnostics hidden>Open diagnostics</button></div></details></section>';
    const orbVisual=root.querySelector('[data-vp3-orb-work]');
    orbVisual.dataset.orbShape=window.VP3_ORB_CONFIG?.shape==='particle_face'?'particle_face':'orbit';
    document.body.append(root);
    const panel=document.createElement('section');panel.className='vp3-orb-panel';panel.id='vp3AgentChatPopup';panel.hidden=true;panel.setAttribute('role','dialog');panel.setAttribute('aria-label','Agent Chat');
    panel.innerHTML='<header><strong>Agent Chat</strong><a>Full chat ↗</a><button type="button" aria-label="Close Agent Chat">×</button></header><div class="vp3-orb-chat-host"></div>';panel.querySelector('a').href=adapter.chatUrl;document.body.append(panel);
    const appearanceDialog=installOrbAppearance(root,orbVisual,adapter);
    const host=panel.querySelector('.vp3-orb-chat-host'),ball=root.querySelector('.vp3-orb-ball'),label=root.querySelector('[data-orb-voice-state]');
    const initiative=root.querySelector('.vp3-orb-initiative'),initiativeLabel=initiative.querySelector('strong'),initiativeDetail=initiative.querySelector('small');
    const initiativeSetting=initiative.querySelector('.vp3-orb-initiative-actions button'),initiativeRetry=initiative.querySelector('[data-agent-initiative-retry]');
    const initiativeDiagnostics=initiative.querySelector('[data-agent-initiative-diagnostics]');
    const activityHost=initiative.querySelector('[data-agent-activity-host]');
    let frameSpecialists=[];
    const activityRequests=new Map();
    let pendingActivity=null,frameActivityContext=null,activityParticipant=0;
    const activityOwner=String(adapter.ownerKey);
    const activityBinding=String(window.VP3_ORB_CONFIG?.workingAttentionBinding||'');
    function activityAllowed(){
      return !disposed&&activityParticipant<=0&&!window.VP3ParticipantContinuity?.selectedContext?.()
        &&String(adapter.ownerKey)===activityOwner&&String(window.VP3_ORB_CONFIG?.ownerKey)===activityOwner
        &&String(window.VP3_ORB_CONFIG?.workingAttentionBinding||'')===activityBinding;
    }
    function finishActivity(intent,accepted,reason=''){
      if(!intent)return;
      clearTimeout(intent.timer);
      if(intent.requestId)activityRequests.delete(intent.requestId);
      if(pendingActivity===intent)pendingActivity=null;
      intent.resolve(accepted);
      if(!accepted&&reason&&activityAllowed())bubble(reason);
    }
    function cancelActivity(){finishActivity(pendingActivity,false);}
    function dispatchActivity(){
      const intent=pendingActivity,context=frameActivityContext;
      if(!intent||intent.dispatched)return;
      if(!activityAllowed()){cancelActivity();return;}
      if(!context)return;
      if(context.ownerKey!==activityOwner||context.agent_id!==intent.agent||context.working_attention_binding!==activityBinding||context.participant){
        finishActivity(intent,false,'Your Agent context changed. Choose the item again.');return;
      }
      if(intent.epoch!==null&&intent.epoch!==context.epoch){cancelActivity();return;}
      intent.epoch=context.epoch;
      if(!ready||!context.ready)return;
      intent.dispatched=true;
      intent.requestId=crypto.randomUUID();
      activityRequests.set(intent.requestId,accepted=>finishActivity(intent,accepted,accepted?'':intent.action.kind==='song'
        ?'Playback did not start. Use Resume in Agent Chat.'
        :'This item could not be opened. Try again in Agent Chat.'));
      if(intent.action.kind==='song')document.querySelectorAll('audio#audio,audio.chat-audio-player').forEach(audio=>{if(!audio.paused)audio.pause();});
      frame.contentWindow?.postMessage({channel:'vp3-orb',kind:'activity-action',ownerKey:activityOwner,
        agent_id:context.agent_id,conversation_id:context.conversation_id,working_attention_binding:activityBinding,
        request_id:intent.requestId,action:intent.action},origin);
    }
    function receiveActivityContext(context){
      if(!context||typeof context.ownerKey!=='string'||!Number.isSafeInteger(context.agent_id)||context.agent_id<0
        ||!Number.isSafeInteger(context.conversation_id)||context.conversation_id<0||typeof context.working_attention_binding!=='string'
        ||typeof context.ready!=='boolean'||typeof context.participant!=='boolean'||!Number.isSafeInteger(context.epoch)||context.epoch<0)return;
      const previous=frameActivityContext;
      if(previous&&context.ownerKey===previous.ownerKey&&context.agent_id===previous.agent_id
        &&context.working_attention_binding===previous.working_attention_binding&&context.epoch<previous.epoch)return;
      if(previous&&(context.epoch!==previous.epoch||context.agent_id!==previous.agent_id
        ||context.working_attention_binding!==previous.working_attention_binding)||context.participant
        ||context.ownerKey!==activityOwner||activityBinding&&(context.working_attention_binding!==activityBinding||!context.ready))clearJobs();
      frameActivityContext=context;
      dispatchActivity();
    }
    function frameJobsAllowed(context){
      if(!jobsAllowed(activityParticipant))return false;
      // Older local adapters have no private-session binding. Cloud frames must
      // carry the same owner, Agent and context epoch as their current Chat.
      if(!activityBinding)return !context||context.ownerKey===activityOwner&&!context.participant;
      return context&&context===frameActivityContext&&context.ownerKey===activityOwner
        &&context.working_attention_binding===activityBinding&&context.ready&&!context.participant
        &&context.agent_id===pageAgent;
    }
    function relayActivityAction(action){
      if(!activityAllowed()||!['canvas','mission','chat','youtube','song','task_notes'].includes(action?.kind))return Promise.resolve(false);
      if(action.kind==='song'&&(!Number.isSafeInteger(action.track_id)||action.track_id<1))return Promise.resolve(false);
      if(['chat','youtube'].includes(action.kind)&&(!Number.isSafeInteger(action.conversation_id)||action.conversation_id<1))return Promise.resolve(false);
      if(action.kind==='canvas'&&(typeof action.canvas_id!=='string'||action.canvas_id.length>128))return Promise.resolve(false);
      if(action.kind==='mission'&&(typeof action.mission_id!=='string'||action.mission_id.length>128))return Promise.resolve(false);
      cancelActivity();
      const agent=Number(conversationRef?.agent??window.VP3_ORB_CONFIG?.agentId??0);
      if(!Number.isSafeInteger(agent)||agent<0)return Promise.resolve(false);
      return new Promise(resolve=>{
        const intent={action,agent,resolve,epoch:frameActivityContext?.epoch??null,dispatched:false,requestId:'',timer:0};
        pendingActivity=intent;
        intent.timer=setTimeout(()=>finishActivity(intent,false,'Agent Chat is still connecting. Try the item again when Chat is ready.'),10000);
        open();
        dispatchActivity();
      });
    }
    function resetWorkContext(){cancelActivity();clearJobs();}
    window.addEventListener('vp3:participant-selection-changed',resetWorkContext);
    window.addEventListener('vp3:participant-task-context',event=>{activityParticipant=Number(event.detail?.participant_id)||0;resetWorkContext();});
    window.addEventListener('stonefellow:chat-context-changing',resetWorkContext);
    const jobStatus=root.querySelector('#vp3OrbJobsStatus'),jobSnapshots=new Map();
    let jobExpiryTimer=0;
    let initiativeAction=null;
    function describeOrb(){
      const descriptions=['vp3OrbGesture'];
      if(!initiative.hidden)descriptions.push('vp3OrbInitiativeDetails');
      if(jobStatus.textContent)descriptions.push(jobStatus.id);
      ball.setAttribute('aria-describedby',descriptions.join(' '));
    }
    function refreshJobs(){
      clearTimeout(jobExpiryTimer);
      const now=Date.now(),ids=new Set();
      const activeJobs=[];
      let nextExpiry=Infinity;
      for(const [source,jobs] of jobSnapshots){
        const active=jobsAllowed(activityParticipant)?jobs.filter(job=>job.expiresAt>now):[];
        if(active.length)jobSnapshots.set(source,active);
        else jobSnapshots.delete(source);
        for(const job of active){ids.add(job.id);activeJobs.push(job);nextExpiry=Math.min(nextExpiry,job.expiresAt);}
      }
      working=ids.size>0;
      root.dataset.working=String(working);
      renderStateLabel();
      syncCoordinatorSpecialist(hasChatWorkPoll?jobSnapshots.get('initiative_work')||[]:activeJobs);
      const visible=Array.from(ids).sort().slice(0,4).join(',');
      if(visible!==orbVisual.dataset.orbJobIds){
        if(visible)orbVisual.dataset.orbJobIds=visible;
        else delete orbVisual.dataset.orbJobIds;
      }
      const summary=ids.size ? `${ids.size} ${ids.size===1?'job':'jobs'} running` : '';
      if(summary!==jobStatus.textContent){
        jobStatus.textContent=summary;
        root.toggleAttribute('data-orb-jobs-active',!!summary);
        describeOrb();
        if(!initiative.hidden&&(root.matches(':hover,:focus-within')||root.dataset.controlsOpen==='true'))positionInitiative();
      }
      if(Number.isFinite(nextExpiry))jobExpiryTimer=setTimeout(refreshJobs,Math.max(1,nextExpiry-now));
    }
    function setJobs(source,jobs){
      const active=normalizeJobs(source,jobs);
      if(!active||disposed)return;
      jobSnapshots.set(source,jobsAllowed(activityParticipant)?active:[]);
      refreshJobs();
    }
    function clearJobs(){
      jobSnapshots.clear();refreshJobs();
      if(frameSpecialists.length){
        frameSpecialists=[];
        window.dispatchEvent(new Event('vp3:chat-specialists-changed'));
      }
    }
    function positionInitiative(){
      if(initiative.hidden)return;
      const bounds=root.getBoundingClientRect(),width=initiative.getBoundingClientRect().width;
      const left=Math.max(12,Math.min(bounds.left+(bounds.width-width)/2,innerWidth-width-12));
      const above=Math.max(0,bounds.top-10),below=Math.max(0,innerHeight-bounds.bottom-10);
      root.dataset.initiativeAbove=String(above>below);
      initiative.style.setProperty('--vp3-initiative-left',`${left-bounds.left}px`);
      initiative.style.setProperty('--vp3-initiative-room',`${Math.max(above,below)}px`);
    }
    function setInitiativeStatus(view,onAction){
      const text=typeof view?.label==='string'?view.label.trim().slice(0,300):'';
      initiative.querySelector('[data-agent-worker-details]').hidden=!text&&!initiative.querySelector('[data-agent-activity-verified]')?.textContent;
      if(!text){
        initiativeAction=null;initiative.hidden=root.dataset.activityOpen!=='true';delete root.dataset.initiativeDismissed;
        initiativeLabel.textContent='';initiativeDetail.textContent='';
        initiativeSetting.hidden=true;delete initiativeSetting.dataset.agentInitiativeSetting;
        initiativeRetry.hidden=true;initiativeDiagnostics.hidden=true;
        describeOrb();
        return;
      }
      initiativeAction=typeof onAction==='function'?onAction:null;
      initiativeLabel.textContent=text;
      initiativeDetail.textContent=typeof view.detail==='string'?view.detail.trim().slice(0,480):'';
      initiativeDetail.hidden=!initiativeDetail.textContent;
      const setting=initiativeAction&&['on','off'].includes(view.setting)?view.setting:'';
      if(setting)initiativeSetting.dataset.agentInitiativeSetting=setting;
      else delete initiativeSetting.dataset.agentInitiativeSetting;
      initiativeSetting.hidden=!setting;initiativeSetting.disabled=view.settingBusy===true;
      initiativeSetting.textContent=setting==='off'?'Pause':'Resume';
      initiativeRetry.hidden=!initiativeAction||view.retry!==true;initiativeRetry.disabled=view.retryBusy===true;
      initiativeDiagnostics.hidden=!initiativeAction||view.diagnostics!==true;
      initiative.hidden=false;
      describeOrb();
      if(root.matches(':hover,:focus-within')||root.dataset.controlsOpen==='true')positionInitiative();
    }
    initiativeSetting.addEventListener('click',()=>initiativeAction?.(initiativeSetting.dataset.agentInitiativeSetting));
    initiativeRetry.addEventListener('click',()=>initiativeAction?.('retry'));
    initiativeDiagnostics.addEventListener('click',()=>initiativeAction?.('diagnostics'));
    function revealInitiative(){delete root.dataset.initiativeDismissed;positionInitiative();}
    root.addEventListener('pointerenter',revealInitiative);
    root.addEventListener('focusin',event=>{if(!root.contains(event.relatedTarget))revealInitiative();});
    const key=`vp3:orb:${adapter.ownerKey}`,draftKey=`${key}:draft:${location.pathname}`;
    try{const p=JSON.parse(localStorage.getItem(key)||'null');if(p){root.style.left=`${Math.max(0,Math.min(innerWidth-100,Number(p.x)||0))}px`;root.style.top=`${Math.max(0,Math.min(innerHeight-150,Number(p.y)||0))}px`;root.style.right='auto';root.style.bottom='auto';}}catch(_){}
    let bar=null,conversationRef=null;const fullLink=panel.querySelector('a'),handoffKey=`${key}:conversation`;
    function rememberRef(ref){if(!adapter.conversationUrl||!ref||typeof ref!=='object')return;const url=adapter.conversationUrl(ref);if(!url)return;if(conversationRef&&(String(conversationRef.id)!==String(ref.id)||String(conversationRef.agent)!==String(ref.agent)))window.VP3ParticipantContinuity?.clearSelection();const changed=conversationRef&&(String(conversationRef.id)!==String(ref.id)||String(conversationRef.agent)!==String(ref.agent));conversationRef={id:ref.id,agent:ref.agent};if(changed){frameSpecialists=[];window.dispatchEvent(new Event('vp3:orb-conversation-changed'));}fullLink.href=url;try{sessionStorage.setItem(handoffKey,JSON.stringify({ref:conversationRef,at:Date.now()}));}catch(_){}}
    try{const saved=JSON.parse(sessionStorage.getItem(handoffKey)||'null');if(saved&&Number.isFinite(saved.at)&&saved.at<=Date.now()&&Date.now()-saved.at<1800000)rememberRef(saved.ref);}catch(_){}
    fullLink.addEventListener('click',event=>{if(adapter.attachChat&&adapter.isMainChat?.()){event.preventDefault();close();adapter.focusChat?.();}});
    function command(kind){if(frame?.contentWindow)frame.contentWindow.postMessage({channel:'vp3-orb',kind},origin);else adapter[kind]?.();}
    function syncOrbExpanded(){ball.setAttribute('aria-expanded',String(!panel.hidden||root.dataset.activityOpen==='true'));}
    function close(){panel.hidden=true;root.dataset.popupOpen='false';syncOrbExpanded();footerObserver?.disconnect();restorePanel?.();restorePanel=null;document.querySelectorAll('[data-orb-chat]').forEach(b=>b.setAttribute('aria-expanded','false'));if(frame)setInitiativeStatus(null);resize();returnFocus?.focus?.();}
    function open(){
      returnFocus=document.activeElement;
      if(adapter.attachChat)rememberRef(adapter.conversationRef?.());
      if(adapter.isMainChat?.()&&!adapter.attachChat){adapter.focusChat?.();return;}
      if(adapter.attachChat)restorePanel=restorePanel||adapter.attachChat(host);
      else if(!frame){
        frame=document.createElement('iframe');frame.title='VP3 Agent Chat';frame.allow='microphone; camera';const url=new URL(adapter.conversationUrl&&conversationRef?adapter.conversationUrl(conversationRef):adapter.chatUrl,location.href);url.searchParams.set('orb_embed','1');if(pendingActivity)url.searchParams.set('agent',pendingActivity.agent>0?String(pendingActivity.agent):'system');frame.src=url.href;host.append(frame);
      }
      panel.hidden=false;root.dataset.popupOpen='true';syncOrbExpanded();document.querySelectorAll('[data-orb-chat]').forEach(b=>b.setAttribute('aria-expanded','true'));
      reservePopupFooter();
      footerObserver?.observe(popupFooter);
      panel.querySelector('button').focus();
      if(frame&&ready)command('initiative-refresh');
      positionInitiative();
    }
    function toggle(){panel.hidden?open():close();}
    let orbClickTimer=0;
    function cancelOrbClick(){clearTimeout(orbClickTimer);orbClickTimer=0;}
    function openOrbChat(){
      if(root.dataset.activityOpen==='true')window.VP3AgentActivityHub?.close?.(false);
      toggle();
    }
    function toggleTopOfMind(){
      cancelOrbClick();
      const hub=window.VP3AgentActivityHub;
      if(root.dataset.activityOpen==='true'){
        ball.focus();
        hub?.close?.(false);
      }else if(activityAllowed())hub?.open?.();
    }
    panel.querySelector('button').addEventListener('click',close);
    ball.addEventListener('click',event=>{
      if(event.detail===0){cancelOrbClick();openOrbChat();return;}
      if(dragged)return;
      if(orbClickTimer||event.detail===2){toggleTopOfMind();return;}
      // A rapid next pair can start with a browser click count greater than
      // one. An unmatched extra click in that sequence has no single action.
      const single=event.detail===1;
      orbClickTimer=setTimeout(()=>{orbClickTimer=0;if(single&&!disposed&&!document.hidden)openOrbChat();},450);
    });
    ball.addEventListener('keydown',event=>{
      if(event.key!=='Enter'||!event.shiftKey||event.altKey||event.ctrlKey||event.metaKey)return;
      event.preventDefault();
      if(!event.repeat)toggleTopOfMind();
    });
    window.addEventListener('vp3:agent-activity-view',syncOrbExpanded);
    root.querySelector('[data-orb-stop]').addEventListener('click',()=>{pendingReplyWindow=false;command('response-window-cancel');command('stop');});
    root.querySelector('[data-orb-eyes]').addEventListener('click',()=>window.VP3AgentEyes?.toggle());
    const composer=$('chatForm');
    if(adapter.hideBar!==true&&(!composer||adapter.alwaysBar)){
      bar=document.createElement('form');bar.className='vp3-orb-bar';bar.setAttribute('aria-label','Agent chatbar');
      const input=document.createElement('input');input.type='text';input.maxLength=6000;input.placeholder='Message your agent…';input.setAttribute('aria-label','Message your agent');try{input.value=sessionStorage.getItem(draftKey)||'';}catch(_){}bar.append(input);
      const listen=document.createElement('button');listen.type='button';listen.textContent='Listen';listen.addEventListener('click',()=>{open();if(!frame||ready)command('listen');else pendingListen=true;});bar.append(listen);
      const send=document.createElement('button');send.type='submit';send.textContent='Send';bar.append(send);
      input.addEventListener('input',()=>{try{sessionStorage.setItem(draftKey,input.value);}catch(_){}});
      bar.addEventListener('submit',event=>{event.preventDefault();if(pendingText||!input.value.trim())return;pendingText=input.value;open();if(adapter.submitDraft){if(adapter.submitDraft(pendingText)){input.value='';try{sessionStorage.removeItem(draftKey);}catch(_){}}else bubble('Your message is kept in the chatbar. Finish the existing draft in Chat first.');pendingText='';return;}if(ready)frame.contentWindow.postMessage({channel:'vp3-orb',kind:'compose',text:pendingText},origin);});
      document.body.append(bar);document.body.classList.add('vp3-orb-has-bar');
      // Keep the same bounded composer as Chat, centered in the usable workspace.
      // A mobile navigation drawer overlays the page instead of reducing its width.
      const sidebar=document.querySelector('.workspace-main-sidebar');
      const placeBar=()=>{
        const rect=sidebar?.getBoundingClientRect();
        const edge=innerWidth>820&&rect&&rect.left<=1&&rect.right>0&&rect.right<innerWidth/2?Math.ceil(rect.right):0;
        bar.style.setProperty('--vp3-chat-sidebar-edge',`${edge}px`);
      };
      placeBar();window.addEventListener('resize',placeBar);
      if(sidebar&&'ResizeObserver' in window)new ResizeObserver(placeBar).observe(sidebar);
    }
    const popupFooter=bar||composer?.closest('.chat-composer-shell,#chatComposerShell')||composer;
    function reservePopupFooter(){
      const style=document.documentElement.style,property='--vp3-chat-popup-footer';
      const top=panel.hidden?undefined:popupFooter?.getBoundingClientRect().top;
      if(top>innerHeight/2&&top<innerHeight)style.setProperty(property,`${Math.ceil(innerHeight-top+8)}px`);
      else style.removeProperty(property);
    }
    const footerObserver=popupFooter&&'ResizeObserver' in window?new ResizeObserver(()=>{
      reservePopupFooter();
      positionInitiative();
    }):null;
    function message(event){
      if(event.origin!==origin||event.source!==frame?.contentWindow||event.data?.channel!=='vp3-orb')return;
      const data=event.data;if(data.kind==='ready'){if(frameActivityContext)clearJobs();frameActivityContext=null;}if(data.activity_context)receiveActivityContext(data.activity_context);if(data.kind==='ready'&&data.conversation)rememberRef(data.conversation);if(data.kind==='ready'){ready=true;if(pendingListen){pendingListen=false;command('listen');}if(pendingReplyWindow){pendingReplyWindow=false;command('response-window');}if(pendingText)frame.contentWindow.postMessage({channel:'vp3-orb',kind:'compose',text:pendingText},origin);if(pendingVoiceEnrollment){frame.contentWindow.postMessage({channel:'vp3-orb',kind:'voice-enroll',id:pendingVoiceEnrollment},origin);pendingVoiceEnrollment='';}}
      if(data.kind==='agent-orb-shape'&&String(data.ownerKey)===String(adapter.ownerKey)
        &&(data.shape==='orbit'||data.shape==='particle_face'))window.dispatchEvent(new CustomEvent('vp3:agent-orb-shape',{detail:{shape:data.shape}}));
      if(data.kind==='jobs'&&data.source!=='gmail_work'&&String(data.ownerKey)===String(adapter.ownerKey)&&frameJobsAllowed(data.activity_context))setJobs(data.source,data.jobs);
      if(data.kind==='voice-filter-status')window.dispatchEvent(new CustomEvent('vp3:voice-filter-remote-status',{detail:{message:String(data.message||'').slice(0,300)}}));
      if(data.kind==='open-eyes-gallery'){const panel=document.querySelector('.vp3-eyes-panel');if(panel?.hidden)window.VP3AgentEyes?.toggle?.();document.querySelector('.vp3-eyes-portraits')?.scrollIntoView({block:'nearest'});}
      if(data.kind==='user-turn')void Promise.resolve(window.VP3AgentEyes?.hear?.(String(data.text||'').slice(0,240),{voice:data.voice===true,known_voice:data.known_voice===true})).finally(()=>{if(typeof data.photoRoute==='string'&&data.photoRoute.length<=64)frame?.contentWindow?.postMessage({channel:'vp3-orb',kind:'photo-reply-complete',id:data.photoRoute},origin);}).catch(()=>{});
      if(data.kind==='ready'){frame.contentWindow.postMessage({channel:'vp3-orb',kind:'introduction-window',mode:introductionMode},origin);frame.contentWindow.postMessage({channel:'vp3-orb',kind:'participant-presence',value:window.VP3ParticipantContinuity?.snapshot()},origin);frame.contentWindow.postMessage({channel:'vp3-orb',kind:'participant-selection',value:window.VP3ParticipantContinuity?.selectedContext()},origin);}
      if(data.kind==='participant-selection')window.VP3ParticipantContinuity?.setSelection(data.value,true);
      if(data.kind==='participant-attribution-start')window.VP3ParticipantContinuity?.beginVoiceAttribution(true);
      if(data.kind==='participant-task-heard')window.VP3ParticipantContinuity?.receiveTaskHeard(data.value);
      if(data.kind==='participant-speaker')window.VP3ParticipantContinuity?.receiveSpeaker(data.value);
      if(data.kind==='participant-reject')window.VP3ParticipantContinuity?.rejectCurrent(false,String(data.value?.participant_id||''));
      if(data.kind==='state'&&data.conversation)rememberRef(data.conversation);
      if(data.kind==='activity-open'&&String(data.ownerKey)===String(adapter.ownerKey)&&frameJobsAllowed(data.activity_context))window.VP3AgentActivityHub?.open({specialist:data.specialist,focus:data.focus});
      if(data.kind==='activity-specialists'&&String(data.ownerKey)===String(adapter.ownerKey)&&frameJobsAllowed(data.activity_context)&&Array.isArray(data.items)&&data.items.length<=12){if(data.conversation)rememberRef(data.conversation);frameSpecialists=data.items.filter(item=>typeof item?.key==='string');window.dispatchEvent(new Event('vp3:chat-specialists-changed'));}
      if(data.kind==='state')render(data.state);
      if(data.kind==='ready')dispatchActivity();
      if(data.kind==='activity-action-result'&&activityRequests.has(data.request_id)){const finish=activityRequests.get(data.request_id);activityRequests.delete(data.request_id);finish(data.accepted===true);}
      if(data.kind==='initiative-status'&&(!panel.hidden||root.dataset.activityOpen==='true')&&String(data.ownerKey)===String(adapter.ownerKey)){
        setInitiativeStatus(data.view,action=>{
          if(!panel.hidden||root.dataset.activityOpen==='true')frame?.contentWindow?.postMessage({channel:'vp3-orb',kind:'initiative-action',action},origin);
        });
      }
      if(data.kind==='draft-accepted'){const input=bar?.querySelector('input');if(input&&input.value===pendingText){input.value='';try{sessionStorage.removeItem(draftKey);}catch(_){}}pendingText='';}
      if(data.kind==='draft-blocked'){pendingText='';bubble('Your existing draft is in the chat popup. Your chatbar message is still here.');}
    }
    function renderStateLabel(){
      const primary={idle:'Ready',listening:'Listening',thinking:'Thinking',speaking:'Speaking',connecting:'Connecting',error:'Needs attention'}[root.dataset.state]||'Ready';
      label.textContent=(root.dataset.recording==='true'?'Recording · ':'')+(working?(root.dataset.state==='idle'?'Working':primary+' · Working'):primary);
      ball.setAttribute('aria-label',`Agent: ${label.textContent}. Open chat`);
    }
    function render(raw){
      const s=window.VP3EyesCore.visualState(raw),recording=String(s.recording);
      if(root.dataset.state===s.state&&root.dataset.recording===recording)return;
      root.dataset.state=s.state;root.dataset.recording=recording;
      renderStateLabel();
    }
    let workPollTimer=0,workController=null,workGeneration=0,workDenied=false;
    function canPollWork(){
      return validWorkAccount&&!window.STONEFELLOW_CHAT&&!adapter.isMainChat?.()&&!document.hidden
        &&jobsAllowed(activityParticipant)&&(!frameActivityContext||frameActivityContext.ownerKey===pageOwner
          &&frameActivityContext.agent_id===pageAgent&&frameActivityContext.working_attention_binding===pageBinding&&!frameActivityContext.participant);
    }
    function stopWorkPoll(){
      clearTimeout(workPollTimer);workPollTimer=0;
      if(workController){workGeneration++;workController.abort();workController=null;}
      clearWorkPollJobs();
    }
    function clearWorkPollJobs(){
      const hadInitiative=jobSnapshots.delete('initiative_work');
      if(jobSnapshots.delete('gmail_work')||hadInitiative)refreshJobs();
    }
    async function pollWork(){
      if(workController||workDenied||!canPollWork())return;
      const controller=new AbortController(),generation=++workGeneration;
      workController=controller;
      const timeout=setTimeout(()=>controller.abort(),5000);
      try{
        const view=await readWorkStatus(workEndpoint,Number(pageOwner),pageAgent,pageBinding,controller.signal);
        if(generation!==workGeneration||!canPollWork())return;
        const gmailJobs=normalizeJobs('gmail_work',view.gmail_job?.id==='gmail:inbox-updates'?[view.gmail_job]:[]);
        if(gmailJobs?.length)jobSnapshots.set('gmail_work',gmailJobs);
        else jobSnapshots.delete('gmail_work');
        setJobs('initiative_work',view.health.enabled?view.health.active_jobs:[]);
      }catch(error){
        if(generation===workGeneration){
          if([401,403].includes(error?.status))workDenied=true;
          clearWorkPollJobs();
        }
      }finally{
        clearTimeout(timeout);
        if(generation===workGeneration){
          workController=null;
          if(!workDenied&&canPollWork())workPollTimer=setTimeout(()=>{workPollTimer=0;void pollWork();},
            jobSnapshots.get('initiative_work')?.length||jobSnapshots.get('gmail_work')?.length?2500:8000);
        }
      }
    }
    function manageWorkPoll(){
      if(!canPollWork()){stopWorkPoll();return;}
      if(!workDenied&&!workPollTimer&&!workController)void pollWork();
    }
    document.addEventListener('visibilitychange',manageWorkPoll);
    function bubble(text){const node=root.querySelector('.vp3-orb-bubble');node.textContent=String(text).slice(0,600);node.hidden=false;clearTimeout(bubbleTimer);bubbleTimer=setTimeout(()=>{node.hidden=true;},14000);}
    let bubbleTimer=0,drag=null,dragged=false,motionFrame=0,followTarget=null,manualUntil=0;
    const reduced=matchMedia('(prefers-reduced-motion: reduce)');
    function move(at){
      motionFrame=0;if(disposed||!followTarget)return;
      if(!reduced.matches&&!drag&&at>manualUntil&&panel.hidden&&root.dataset.activityOpen!=='true'
        &&root.dataset.controlsOpen!=='true'&&!root.matches(':hover,:focus-within')){
        const r=root.getBoundingClientRect(),margin=12,maxX=Math.max(margin,innerWidth-r.width-margin);
        let maxY=innerHeight-r.height-90;const composer=$('chatForm')?.getBoundingClientRect();
        if(composer&&composer.top>0&&composer.top<innerHeight)maxY=Math.min(maxY,composer.top-r.height-16);
        maxY=Math.max(70,maxY);let x=margin+followTarget.x*(maxX-margin),y=70+followTarget.y*(maxY-70);
        x=Math.max(margin,Math.min(maxX,x+Math.sin(at/2100)*5));y=Math.max(70,Math.min(maxY,y+Math.sin(at/1500)*5));
        const eyes=document.querySelector('.vp3-eyes-panel:not([hidden])')?.getBoundingClientRect();
        if(eyes&&eyes.left<r.width+margin+16){motionFrame=requestAnimationFrame(move);return;}
        if(eyes&&x+r.width>eyes.left-12&&x<eyes.right+12&&y+r.height>eyes.top&&y<eyes.bottom)x=Math.max(margin,eyes.left-r.width-16);
        const step=(from,to)=>from+Math.max(-18,Math.min(18,(to-from)*.065));
        root.style.left=`${step(r.left,x)}px`;root.style.top=`${step(r.top,y)}px`;root.style.right='auto';root.style.bottom='auto';
      }
      motionFrame=requestAnimationFrame(move);
    }
    function follow(rows,{mirrored=true}={}){followTarget=window.VP3EyesCore.followPoint(rows,mirrored);root.dataset.following=String(!!followTarget);if(followTarget&&!motionFrame)motionFrame=requestAnimationFrame(move);if(!followTarget&&motionFrame){cancelAnimationFrame(motionFrame);motionFrame=0;}}
    function save(){const r=root.getBoundingClientRect();try{localStorage.setItem(key,JSON.stringify({x:r.left,y:r.top}));}catch(_){}}
    ball.addEventListener('pointerdown',event=>{
      if(event.button!==0)return;
      dragged=false;
      if(!panel.hidden)return;
      manualUntil=performance.now()+15000;
      const r=root.getBoundingClientRect();
      drag={id:event.pointerId,x:event.clientX,y:event.clientY,left:r.left,top:r.top};
      ball.setPointerCapture(event.pointerId);
    });
    ball.addEventListener('pointermove',event=>{if(drag?.id!==event.pointerId)return;const dx=event.clientX-drag.x,dy=event.clientY-drag.y;if(Math.hypot(dx,dy)>5){dragged=true;cancelOrbClick();}if(!dragged)return;root.dataset.dragging='true';root.style.left=`${Math.max(0,Math.min(innerWidth-root.offsetWidth,drag.left+dx))}px`;root.style.top=`${Math.max(0,Math.min(innerHeight-root.offsetHeight-80,drag.top+dy))}px`;root.style.right='auto';root.style.bottom='auto';});
    const finishDrag=()=>{drag=null;delete root.dataset.dragging;positionInitiative();};
    ball.addEventListener('pointerup',()=>{finishDrag();if(dragged)save();});ball.addEventListener('pointercancel',()=>{cancelOrbClick();finishDrag();});
    const touchControls=event=>{
      if(!root.contains(event.target)){root.dataset.controlsOpen='false';cancelOrbClick();}
      else if(event.pointerType==='touch'){root.dataset.controlsOpen='true';revealInitiative();}
    };
    document.addEventListener('pointerdown',touchControls,{passive:true});
    const resize=()=>{reservePopupFooter();const r=root.getBoundingClientRect();if(panel.hidden&&(r.left>innerWidth-80||r.top>innerHeight-140)){root.style.left='auto';root.style.top='auto';root.style.right='12px';root.style.bottom='94px';}positionInitiative();};
    const escape=event=>{
      if(event.key!=='Escape')return;
      cancelOrbClick();
      if(appearanceDialog?.open||root.dataset.activityOpen==='true')return;
      if(!initiative.hidden&&root.dataset.initiativeDismissed!=='true'&&(root.matches(':hover,:focus-within')||root.dataset.controlsOpen==='true')){
        if(initiative.contains(document.activeElement))ball.focus();
        root.dataset.initiativeDismissed='true';
        return;
      }
      if(!panel.hidden)close();
    };
    window.addEventListener('message',message);window.addEventListener('resize',resize);document.addEventListener('keydown',escape);
    timer=setInterval(()=>{
      if(working&&!jobsAllowed(activityParticipant))clearJobs();
      manageWorkPoll();
      if(!frame){
        // Only a live main Chat can replace the saved popup handoff before
        // the embedded conversation reports its own current context.
        const ref=adapter.conversationRef?.();
        if(ref&&(adapter.isMainChat?.()||!conversationRef&&Number(ref.id)>0))rememberRef(ref);
        const next=state(),json=JSON.stringify(next);
        if(json!==lastState){render(next);lastState=json;}
      }
    },200);
    window.addEventListener('vp3:participant-photo-state',event=>{const d=event.detail||{};if(['waiting','capturing','saving','saved','failed','deferred'].includes(d.state)&&typeof d.message==='string')frame?.contentWindow?.postMessage({channel:'vp3-orb',kind:'participant-photo-state',value:{state:d.state,message:d.message.slice(0,500)}},origin);});
    window.VP3AgentOrb={open,close,toggle,bubble,state,isWorking:()=>working,follow,setInitiativeStatus,setJobs,
      activityHost:()=>activityHost,positionActivity:positionInitiative,prepareActivity:()=>{if(!panel.hidden)close();},
      activityAction:relayActivityAction,
      activityContext:()=>conversationRef||(adapter.isMainChat?.()?adapter.conversationRef?.():{id:0,agent:Number(window.VP3_ORB_CONFIG?.agentId||0)}),
      activitySpecialists:({includeResults=false}={})=>frame?frameSpecialists.filter(item=>
        (!item.presence||!item.expiresAt||item.expiresAt>Date.now())&&(includeResults||activeSpecialist(item))):
        window.VP3ChatSpecialists?.snapshot?.({includeResults})||[],
      viewActivitySpecialist:key=>{if(frame){if(!frameSpecialists.some(item=>item.key===key&&item.canView===true))return false;open();frame.contentWindow?.postMessage({channel:'vp3-orb',kind:'activity-specialist',key},origin);return true;}return window.VP3ChatSpecialists?.view?.(key)??false;},
      showActivityDetails:()=>{if(!frame)return false;open();frame.contentWindow?.postMessage({channel:'vp3-orb',kind:'activity-details'},origin);return true;},
      listenForResponse:()=>{const voice=window.VP3ConversationControls||(document.getElementById('chatForm')&&window.HomeServerConversationVoice);if(voice)return voice.responseWindow?.();open();if(ready)command('response-window');else pendingReplyWindow=true;return true;},cancelResponseWindow:()=>{pendingReplyWindow=false;if(frame)command('response-window-cancel');else window.VP3ConversationControls?.cancelResponseWindow?.();},participantSignal:(kind,value)=>{if(['presence','reject','invalidate','selection'].includes(kind))frame?.contentWindow?.postMessage({channel:'vp3-orb',kind:'participant-'+kind,value},origin);},setIntroductionMode:mode=>{introductionMode=mode;window.VP3VoiceGuard?.inviteIntroduction(mode);frame?.contentWindow?.postMessage({channel:'vp3-orb',kind:'introduction-window',mode},origin);},startVoiceEnrollment:id=>{if(adapter.attachChat||adapter.isMainChat?.())return false;open();if(ready)frame.contentWindow.postMessage({channel:'vp3-orb',kind:'voice-enroll',id},origin);else pendingVoiceEnrollment=id;return true;},cancelVoiceEnrollment:()=>{pendingVoiceEnrollment='';frame?.contentWindow?.postMessage({channel:'vp3-orb',kind:'voice-enroll-cancel'},origin);},stop:()=>command('stop')};
    window.dispatchEvent(new Event('vp3:agent-orb-ready'));
    window.addEventListener('pagehide',()=>{cancelOrbClick();cancelActivity();disposed=true;stopWorkPoll();jobSnapshots.clear();refreshJobs();clearInterval(timer);clearTimeout(bubbleTimer);cancelAnimationFrame(motionFrame);footerObserver?.disconnect();document.removeEventListener('visibilitychange',manageWorkPoll);document.removeEventListener('pointerdown',touchControls);window.removeEventListener('message',message);window.removeEventListener('resize',resize);window.removeEventListener('vp3:agent-activity-view',syncOrbExpanded);document.removeEventListener('keydown',escape);adapter.stop?.();},{once:true});
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
