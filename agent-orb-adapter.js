(() => {
  'use strict';
  const cfg=window.VP3_ORB_CONFIG;if(!cfg)return;
  const $=id=>document.getElementById(id);let audible=false,preparing=false,voiceEpoch=0;
  window.addEventListener('stonefellow:agent-proactive-speech',event=>{audible=event.detail?.state==='start';});
  const loadScript=url=>new Promise((resolve,reject)=>{const s=document.createElement('script');s.src=url;s.onload=resolve;s.onerror=reject;document.head.append(s);});
  if(window.STONEFELLOW_NOTIFICATION_DRAWER)window.STONEFELLOW_NOTIFICATION_DRAWER.agentEndpoint=cfg.chatEndpoint;
  const voiceReady=(async()=>{
    if(!window.StonefellowPremiumVoiceV122)await loadScript(cfg.voiceScript);
    if(!window.STONEFELLOW_NOTIFICATION_CENTER){
      window.STONEFELLOW_NOTIFICATION_DRAWER={endpoint:cfg.notificationsEndpoint,csrf:cfg.csrf,agentVoiceEnabled:cfg.agentVoiceEnabled,agentEndpoint:cfg.chatEndpoint,orbOnly:true};
      await loadScript(cfg.drawerScript);
    }
  })().catch(()=>{});

  async function api(url,body,keepalive=false){const r=await fetch(url,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',keepalive,headers:body?{'Content-Type':'application/json'}:{},body:body?JSON.stringify({...body,csrf_token:cfg.csrf}):undefined});const data=await r.json();if(!r.ok||data.ok===false)throw Error(data.error||'Agent request failed.');return data;}
  async function samples(mode,id,value){
    const db=await new Promise((resolve,reject)=>{const request=indexedDB.open(`vp3-eyes-local-${cfg.ownerKey}`,1);request.onupgradeneeded=()=>request.result.createObjectStore('samples',{keyPath:'id'});request.onsuccess=()=>resolve(request.result);request.onerror=()=>reject(request.error);});
    try{return await new Promise((resolve,reject)=>{const tx=db.transaction('samples',mode==='read'?'readonly':'readwrite'),store=tx.objectStore('samples');const request=mode==='read'?store.getAll():mode==='remove'?store.delete(String(id)):store.put({id:String(id),embeddings:value});let result;request.onsuccess=()=>{result=request.result;};tx.oncomplete=()=>resolve(result);tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error||Error('Local samples could not be saved.'));});}finally{db.close();}
  }
  async function saveTrainedLocal(id,vector,lib,isCurrent){
    const db=await new Promise((resolve,reject)=>{const r=indexedDB.open(`vp3-eyes-local-${cfg.ownerKey}`,1);r.onupgradeneeded=()=>r.result.createObjectStore('samples',{keyPath:'id'});r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error);});
    try{return await new Promise((resolve,reject)=>{const tx=db.transaction('samples','readwrite'),store=tx.objectStore('samples'),key='trained-voice:'+id,r=store.get(key);let profile;
      r.onsuccess=()=>{if(!isCurrent()){tx.abort();return;}const previous=lib.cleanProfile(r.result?.profile);profile={feature:lib.FEATURE,revision:lib.REVISION,consentedAt:previous?.consentedAt||new Date().toISOString(),embeddings:[...(previous?.embeddings||[]),vector].slice(-lib.MAX_SAMPLES),rawAudioStored:false};const put=store.put({id:key,profile});put.onsuccess=()=>{if(!isCurrent())tx.abort();};};
      tx.oncomplete=()=>resolve(profile);tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error||Error('Trained voice enrollment stopped.'));});}finally{db.close();}
  }
  const recording=()=>Boolean(document.querySelector('[data-recording="true"],.is-recording,.chat-media-studio button.recording,[data-agent-state="recording"]'));
  const state=()=>{const current=window.VP3ConversationControls?.state().state||document.body.dataset.stonefellowAgentState;return {state:audible||current==='speaking'?'speaking':preparing?'thinking':current||($('sendChatButton')?.disabled?'thinking':'idle'),recording:recording()};};
  const adapter={tvRequest:(body,{keepalive=false}={})=>api(cfg.tvEndpoint,body,keepalive),
    ownerKey:cfg.ownerKey,chatUrl:cfg.chatUrl,state,voiceFeatureUrl:cfg.voiceFeatureUrl,
    trainedVoiceUrl:cfg.trainedVoiceUrl,trainedVoiceSource:'cloud',
    conversationRef:()=>window.STONEFELLOW_CHAT_CONTINUITY?.isRestoring?.()||window.STONEFELLOW_CHAT_CONTINUITY?.isReady?.()===false?null:({id:window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()||0,agent:Number(window.STONEFELLOW_CHAT?.agentId||0)}),
    conversationUrl:ref=>{const u=new URL(cfg.chatUrl,location.href);if(Number.isSafeInteger(Number(ref?.id))&&Number(ref.id)>0){u.searchParams.set('conversation_id',String(ref.id));u.searchParams.set('agent',Number(ref.agent)>0?String(Number(ref.agent)):'system');}return u.href;},
    participantDetails:async id=>(await api(cfg.participantsEndpoint+'?action=agent_details&participant_id='+encodeURIComponent(id))).participant,
    saveParticipantDetails:async({id,name,note,isCurrent})=>{if(!isCurrent())throw Error('Participant update stopped.');return (await api(cfg.participantsEndpoint,{action:'save_agent_details',participant_id:Number(id),display_name:name,conversation_note:note})).participant;},
    participantSyncStatus:async()=>({text:'Saved in Cloud. A paired HomeServer receives participant text through workspace sync. Recognition samples stay local.'}),
    removeParticipant:async id=>api(cfg.participantsEndpoint,{action:'delete_agent_participant',participant_id:Number(id)}),
    isMainChat:()=>!!$('chatForm'),focusChat:()=>$('chatInput')?.focus(),
    busy:()=>Boolean(['processing','thinking','speaking','starting'].includes(state().state)||recording()||$('sendChatButton')?.disabled||$('chatInput')?.value.trim()),
    listen:()=>{if(!window.VP3ConversationControls?.isEnabled?.())$('chatVoiceButton')?.click();},
    responseWindow:()=>window.VP3AgentOrb?.listenForResponse?.(),
    cancelResponseWindow:()=>window.VP3AgentOrb?.cancelResponseWindow?.(),
    stop:()=>{voiceEpoch++;preparing=false;audible=false;window.VP3ConversationControls?.stop();window.STONEFELLOW_NOTIFICATION_CENTER?.cancelSpeech();if(recording())window.StonefellowMediaStudio?.close?.();},
    greet:async(text,isCurrent=()=>true)=>{
      const epoch=++voiceEpoch;preparing=true;
      try{await voiceReady;if(epoch!==voiceEpoch||!isCurrent())return false;return await window.STONEFELLOW_NOTIFICATION_CENTER?.announceWhen(text,()=>epoch===voiceEpoch&&isCurrent());}
      finally{if(epoch===voiceEpoch)preparing=false;}
    },
    cameraAllowed:async()=>!document.hidden&&!!window.isSecureContext&&!!navigator.mediaDevices?.getUserMedia&&!Array.from(document.querySelectorAll('video')).some(v=>!v.closest('.vp3-eyes-camera')&&v.srcObject?.active===true),
    loadEyesProfiles:async()=>{
      const [data,local]=await Promise.all([api(cfg.participantsEndpoint),samples('read')]);
      return data.profiles.map(p=>({id:String(p.id),name:p.display_name,recognitionEnabled:p.recognition_consent===true,embeddings:p.recognition_consent===true?(local.find(x=>x.id===String(p.id))?.embeddings||[]):[]}));
    },
    introduceEyes:async({name,isCurrent=()=>true})=>{
      const data=await api(cfg.participantsEndpoint);if(!isCurrent())throw Error('Introduction stopped.');
      const existing=data.profiles.filter(p=>p.display_name.normalize('NFKC').toLocaleLowerCase()===name.normalize('NFKC').toLocaleLowerCase());
      if(existing.length>1)throw Error('More than one participant has that name. Choose the participant in Eyes.');
      if(existing.length)return {id:String(existing[0].id),name:existing[0].display_name};
      const created=await api(cfg.participantsEndpoint,{action:'save_profile',display_name:name,relationship:'guest'});
      const p=created.profiles.find(p=>String(p.id)===String(created.participant_id));if(!p)throw Error('Participant name could not be saved.');return {id:String(p.id),name:p.display_name};
    },
    enrollEyes:async({id,name,embeddings,isCurrent=()=>true})=>{
      if(!isCurrent())throw Error('Enrollment stopped.');
      let data=await api(cfg.participantsEndpoint);
      if(!isCurrent())throw Error('Enrollment stopped.');
      if(!id){const created=await api(cfg.participantsEndpoint,{action:'save_profile',display_name:name,relationship:'guest'});id=String(created.participant_id);data=created;}
      const profile=data.profiles.find(p=>String(p.id)===String(id));if(!profile)throw Error('Participant unavailable.');
      if(!isCurrent())throw Error('Enrollment stopped.');
      await api(cfg.participantsEndpoint,{action:'allow_local_visual_recognition',participant_id:Number(id),consent_confirmed:true});
      if(!isCurrent())throw Error('Enrollment stopped.');
      await samples('write',id,embeddings);
    },
    loadVoiceProfiles:async()=>{const [data,local]=await Promise.all([api(cfg.participantsEndpoint),samples('read')]);return data.profiles.map(p=>{const voice=local.find(v=>v.id==='voice:'+p.id);return {id:String(p.id),name:p.display_name,voiceTrainedProfile:p.recognition_consent===true?local.find(v=>v.id==='trained-voice:'+p.id)?.profile||null:null,voiceEmbeddings:voice?.embeddings||[],voiceRecognitionEnabled:p.recognition_consent===true&&!!voice,voiceProfileReady:p.recognition_consent===true&&(voice?.embeddings?.length||0)>=3};});},
    saveTrainedVoiceSample:async({id,embedding,isCurrent})=>{const lib=await import(new URL('./voice-trained-core.js',new URL(cfg.trainedVoiceUrl,location.href)).href),vector=lib.unitVector(embedding);const rows=await adapter.loadVoiceProfiles(),p=rows.find(p=>p.id===String(id));if(!vector||!p||!isCurrent())throw Error('Trained voice enrollment stopped.');await api(cfg.participantsEndpoint,{action:'allow_local_voice_recognition',participant_id:Number(id),consent_confirmed:true});if(!isCurrent())throw Error('Trained voice enrollment stopped.');const profile=await saveTrainedLocal(id,vector,lib,isCurrent);return {...p,voiceTrainedProfile:profile,voiceEmbeddings:profile.embeddings,voiceProfileReady:profile.embeddings.length>=lib.MIN_SAMPLES};},
    saveVoiceSample:async({id,embedding,isCurrent})=>{const rows=await adapter.loadVoiceProfiles(),p=rows.find(p=>p.id===String(id));if(!p||!isCurrent())throw Error('Voice enrollment stopped.');await api(cfg.participantsEndpoint,{action:'allow_local_voice_recognition',participant_id:Number(id),consent_confirmed:true});if(!isCurrent())throw Error('Voice enrollment stopped.');const embeddings=[...p.voiceEmbeddings,embedding].slice(-5);await samples('write','voice:'+id,embeddings);return {...p,voiceEmbeddings:embeddings,voiceRecognitionEnabled:true,voiceProfileReady:embeddings.length>=3};},
    forgetVoice:async id=>{await samples('remove','voice:'+id);await samples('remove','trained-voice:'+id);},
    forgetEyes:id=>samples('remove',id),recordEyes:event=>api(cfg.eyesEndpoint,event,true),
  };
  if($('chatForm')&&$('chatThread'))adapter.attachChat=host=>{
    const thread=$('chatThread'),marker=document.createElement('div');
    marker.className='vp3-cloud-chat-placeholder';marker.setAttribute('aria-hidden','true');
    const view=thread.dataset.view,scroll=thread.scrollTop;
    const canvases=Array.from(thread.querySelectorAll('[data-chat-view]'),node=>({node,hidden:node.hidden}));
    thread.before(marker);thread.dataset.view='chat';canvases.forEach(({node})=>{node.hidden=true;});host.append(thread);
    // Keep the one live composer and conversation runtime in place; move the canvas only.
    const navigate=event=>{if(event.target.closest?.('[data-chat-view-target]'))window.VP3AgentOrb?.close();};
    document.addEventListener('click',navigate,true);
    return ()=>{
      document.removeEventListener('click',navigate,true);marker.replaceWith(thread);
      if(view===undefined)delete thread.dataset.view;else thread.dataset.view=view;
      canvases.forEach(({node,hidden})=>{node.hidden=hidden;});
      if(view&&view!=='chat')thread.scrollTop=scroll;
    };
  };
  window.VP3_ORB_ADAPTER=adapter;
})();
