(function(){
  'use strict';
  const jobPrefixes={initiative:'workflow:',image:'image:',development:'development:',mission:'mission:'};
  function normalizeJobs(source,jobs){
    if(typeof source!=='string'||!Object.hasOwn(jobPrefixes,source)||!Array.isArray(jobs))return null;
    const now=Date.now(),prefix=jobPrefixes[source];
    return jobs.slice(0,64).flatMap(job=>{
      const {id,expiresAt}=job||{};
      if(typeof id!=='string'||!id.startsWith(prefix)||id.length===prefix.length||!/^[a-z0-9:-]{1,120}$/.test(id)
        ||!Number.isSafeInteger(expiresAt)||expiresAt<=now)return [];
      return [{id,expiresAt:Math.min(expiresAt,now+120000)}];
    });
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
    root.querySelector('.vp3-orb-controls').insertBefore(edit,root.querySelector('[data-orb-size]'));

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
    const state=()=>window.VP3EyesCore.visualState(adapter.state());
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
      let queuedEnrollment='',queuedIntroduction='',queuedResponse=false,initiativeAction=null;window.addEventListener('vp3:voice-guard-ready',()=>{if(queuedEnrollment){void window.VP3VoiceGuard.beginEnrollment(queuedEnrollment);queuedEnrollment='';}window.VP3VoiceGuard.inviteIntroduction(queuedIntroduction);introductionMode=queuedIntroduction;});
      document.documentElement.dataset.vp3OrbEmbed='1';
      const announce=()=>{if(!disposed)parent.postMessage({channel:'vp3-orb',kind:'state',state:state(),conversation:adapter.conversationRef?.()},origin);};
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
        if(event.data.kind==='initiative-refresh')window.dispatchEvent(new Event('vp3:agent-orb-ready'));
        if(event.data.kind==='initiative-action'&&['on','off','retry'].includes(event.data.action))initiativeAction?.(event.data.action);
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
      window.VP3AgentOrb={
        openEyesGallery:()=>parent.postMessage({channel:'vp3-orb',kind:'open-eyes-gallery'},origin),
        participantSignal:(kind,value)=>{if(['speaker','task-heard','attribution-start','reject','selection'].includes(kind))parent.postMessage({channel:'vp3-orb',kind:'participant-'+kind,value},origin);},
        setJobs(source,jobs){
          const active=normalizeJobs(source,jobs);
          if(active)parent.postMessage({channel:'vp3-orb',kind:'jobs',ownerKey:adapter.ownerKey,source,jobs:active},origin);
        },
        setInitiativeStatus(view,onAction){
          initiativeAction=view&&typeof onAction==='function'?onAction:null;
          parent.postMessage({channel:'vp3-orb',kind:'initiative-status',ownerKey:adapter.ownerKey,view},origin);
        }
      };
      parent.postMessage({channel:'vp3-orb',kind:'ready'},origin);
      window.dispatchEvent(new Event('vp3:agent-orb-ready'));
      window.addEventListener('pagehide',()=>{disposed=true;clearInterval(timer);window.removeEventListener('message',commands);},{once:true});
      return;
    }
    const root=document.createElement('aside');root.className='vp3-orb-root';root.dataset.state='idle';
    root.innerHTML='<div class="vp3-orb-bubble" role="status" hidden></div>'
      +'<button type="button" class="vp3-orb-ball" aria-label="Open Agent Chat" aria-controls="vp3AgentChatPopup" aria-expanded="false"><span class="vp3-public-orb" data-vp3-public-orb data-vp3-orb-personal data-vp3-orb-work aria-hidden="true"></span></button>'
      +'<span class="vp3-orb-state"><span data-orb-voice-state>Ready</span><span id="vp3OrbJobsStatus" role="status"></span></span><div class="vp3-orb-controls"><button type="button" data-orb-stop>Stop</button><button type="button" data-orb-eyes>Eyes</button><button type="button" data-orb-brain>Brain</button><button type="button" data-orb-size aria-label="Minimize agent">−</button></div>'
      +'<section class="vp3-orb-initiative" data-agent-initiative-status aria-label="Agent background work" hidden>'
      +'<div id="vp3OrbInitiativeDetails"><strong></strong><small hidden></small></div>'
      +'<div class="vp3-orb-initiative-actions"><button type="button" hidden></button><button type="button" data-agent-initiative-retry hidden>Retry</button></div></section>';
    const orbVisual=root.querySelector('[data-vp3-orb-work]');
    orbVisual.dataset.orbShape=window.VP3_ORB_CONFIG?.shape==='particle_face'?'particle_face':'orbit';
    document.body.append(root);
    const panel=document.createElement('section');panel.className='vp3-orb-panel';panel.id='vp3AgentChatPopup';panel.hidden=true;panel.setAttribute('role','dialog');panel.setAttribute('aria-label','Agent Chat');
    panel.innerHTML='<header><strong>Agent Chat</strong><a>Full chat ↗</a><button type="button" aria-label="Close Agent Chat">×</button></header><div class="vp3-orb-chat-host"></div>';panel.querySelector('a').href=adapter.chatUrl;document.body.append(panel);
    const appearanceDialog=installOrbAppearance(root,orbVisual,adapter);
    const host=panel.querySelector('.vp3-orb-chat-host'),ball=root.querySelector('.vp3-orb-ball'),label=root.querySelector('[data-orb-voice-state]');
    const initiative=root.querySelector('.vp3-orb-initiative'),initiativeLabel=initiative.querySelector('strong'),initiativeDetail=initiative.querySelector('small');
    const [initiativeSetting,initiativeRetry]=initiative.querySelectorAll('button');
    const jobStatus=root.querySelector('#vp3OrbJobsStatus'),jobSnapshots=new Map();
    let jobExpiryTimer=0;
    let initiativeAction=null;
    function describeOrb(){
      const descriptions=[];
      if(!initiative.hidden)descriptions.push('vp3OrbInitiativeDetails');
      if(jobStatus.textContent)descriptions.push(jobStatus.id);
      if(descriptions.length)ball.setAttribute('aria-describedby',descriptions.join(' '));
      else ball.removeAttribute('aria-describedby');
    }
    function refreshJobs(){
      clearTimeout(jobExpiryTimer);
      const now=Date.now(),ids=new Set();
      let nextExpiry=Infinity;
      for(const [source,jobs] of jobSnapshots){
        const active=jobs.filter(job=>job.expiresAt>now);
        if(active.length)jobSnapshots.set(source,active);
        else jobSnapshots.delete(source);
        for(const job of active){ids.add(job.id);nextExpiry=Math.min(nextExpiry,job.expiresAt);}
      }
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
      jobSnapshots.set(source,active);
      refreshJobs();
    }
    function positionInitiative(){
      if(initiative.hidden)return;
      const bounds=root.getBoundingClientRect(),width=Math.min(308,Math.max(1,innerWidth-24));
      const left=Math.max(12,Math.min(bounds.left+(bounds.width-width)/2,innerWidth-width-12));
      const above=Math.max(0,bounds.top-10),below=Math.max(0,innerHeight-bounds.bottom-10);
      root.dataset.initiativeAbove=String(above>below);
      initiative.style.setProperty('--vp3-initiative-left',`${left-bounds.left}px`);
      initiative.style.setProperty('--vp3-initiative-room',`${Math.max(above,below)}px`);
    }
    function setInitiativeStatus(view,onAction){
      const text=typeof view?.label==='string'?view.label.trim().slice(0,300):'';
      if(!text){
        initiativeAction=null;initiative.hidden=true;delete root.dataset.initiativeDismissed;
        initiativeLabel.textContent='';initiativeDetail.textContent='';
        initiativeSetting.hidden=true;delete initiativeSetting.dataset.agentInitiativeSetting;
        initiativeRetry.hidden=true;
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
      initiative.hidden=false;
      describeOrb();
      if(root.matches(':hover,:focus-within')||root.dataset.controlsOpen==='true')positionInitiative();
    }
    initiativeSetting.addEventListener('click',()=>initiativeAction?.(initiativeSetting.dataset.agentInitiativeSetting));
    initiativeRetry.addEventListener('click',()=>initiativeAction?.('retry'));
    function revealInitiative(){delete root.dataset.initiativeDismissed;positionInitiative();}
    root.addEventListener('pointerenter',revealInitiative);
    root.addEventListener('focusin',event=>{if(!root.contains(event.relatedTarget))revealInitiative();});
    const key=`vp3:orb:${adapter.ownerKey}`,draftKey=`${key}:draft:${location.pathname}`;
    try{const p=JSON.parse(localStorage.getItem(key)||'null');if(p){root.style.left=`${Math.max(0,Math.min(innerWidth-100,Number(p.x)||0))}px`;root.style.top=`${Math.max(0,Math.min(innerHeight-150,Number(p.y)||0))}px`;root.style.right='auto';root.style.bottom='auto';root.dataset.small=p.small?'true':'false';}}catch(_){}
    let bar=null,conversationRef=null;const fullLink=panel.querySelector('a'),handoffKey=`${key}:conversation`;
    function rememberRef(ref){if(!adapter.conversationUrl||!ref||typeof ref!=='object')return;const url=adapter.conversationUrl(ref);if(!url)return;if(conversationRef&&(String(conversationRef.id)!==String(ref.id)||String(conversationRef.agent)!==String(ref.agent)))window.VP3ParticipantContinuity?.clearSelection();conversationRef={id:ref.id,agent:ref.agent};fullLink.href=url;try{sessionStorage.setItem(handoffKey,JSON.stringify({ref:conversationRef,at:Date.now()}));}catch(_){}}
    try{const saved=JSON.parse(sessionStorage.getItem(handoffKey)||'null');if(saved&&Number.isFinite(saved.at)&&saved.at<=Date.now()&&Date.now()-saved.at<1800000)rememberRef(saved.ref);}catch(_){}
    fullLink.addEventListener('click',event=>{if(adapter.attachChat){event.preventDefault();close();adapter.focusChat?.();}});
    function command(kind){if(frame?.contentWindow)frame.contentWindow.postMessage({channel:'vp3-orb',kind},origin);else adapter[kind]?.();}
    function close(){panel.hidden=true;root.dataset.popupOpen='false';ball.setAttribute('aria-expanded','false');footerObserver?.disconnect();restorePanel?.();restorePanel=null;document.querySelectorAll('[data-orb-chat]').forEach(b=>b.setAttribute('aria-expanded','false'));if(frame)setInitiativeStatus(null);resize();returnFocus?.focus?.();}
    function open(){
      returnFocus=document.activeElement;
      if(adapter.isMainChat?.()&&!adapter.attachChat){adapter.focusChat?.();return;}
      if(adapter.attachChat)restorePanel=restorePanel||adapter.attachChat(host);
      else if(!frame){
        frame=document.createElement('iframe');frame.title='VP3 Agent Chat';frame.allow='microphone; camera';const url=new URL(adapter.conversationUrl&&conversationRef?adapter.conversationUrl(conversationRef):adapter.chatUrl,location.href);url.searchParams.set('orb_embed','1');frame.src=url.href;host.append(frame);
      }
      panel.hidden=false;root.dataset.popupOpen='true';ball.setAttribute('aria-expanded','true');document.querySelectorAll('[data-orb-chat]').forEach(b=>b.setAttribute('aria-expanded','true'));
      reservePopupFooter();
      footerObserver?.observe(popupFooter);
      panel.querySelector('button').focus();
      if(frame&&ready)command('initiative-refresh');
      positionInitiative();
    }
    function toggle(){panel.hidden?open():close();}
    panel.querySelector('button').addEventListener('click',close);ball.addEventListener('click',event=>{if(event.detail===0||!dragged)toggle();});
    root.querySelector('[data-orb-stop]').addEventListener('click',()=>{pendingReplyWindow=false;command('response-window-cancel');command('stop');});
    root.querySelector('[data-orb-eyes]').addEventListener('click',()=>window.VP3AgentEyes?.toggle());
    root.querySelector('[data-orb-brain]').addEventListener('click',()=>void adapter.openBrain?.());
    const sizeButton=root.querySelector('[data-orb-size]');
    function sizeLabel(){sizeButton.textContent=root.dataset.small==='true'?'+':'−';sizeButton.setAttribute('aria-label',root.dataset.small==='true'?'Expand agent':'Minimize agent');}
    sizeButton.addEventListener('click',()=>{root.dataset.small=root.dataset.small==='true'?'false':'true';sizeLabel();save();positionInitiative();});
    sizeLabel();
    function button(){const b=document.createElement('button');b.type='button';b.dataset.orbChat='1';b.textContent='Chat';b.setAttribute('aria-controls',panel.id);b.setAttribute('aria-expanded','false');b.addEventListener('click',toggle);return b;}
    const composer=$('chatForm');
    if(composer){const actions=composer.querySelector('.chat-composer-actions')||composer;actions.insertBefore(button(),actions.firstChild);}
    if(!composer||adapter.alwaysBar){
      bar=document.createElement('form');bar.className='vp3-orb-bar';bar.setAttribute('aria-label','Agent chatbar');bar.append(button());
      const input=document.createElement('input');input.type='text';input.maxLength=6000;input.placeholder='Message your agent…';input.setAttribute('aria-label','Message your agent');try{input.value=sessionStorage.getItem(draftKey)||'';}catch(_){}bar.append(input);
      const listen=document.createElement('button');listen.type='button';listen.textContent='Listen';listen.addEventListener('click',()=>{open();if(!frame||ready)command('listen');else pendingListen=true;});bar.append(listen);
      const send=document.createElement('button');send.type='submit';send.textContent='Send';bar.append(send);
      input.addEventListener('input',()=>{try{sessionStorage.setItem(draftKey,input.value);}catch(_){}});
      bar.addEventListener('submit',event=>{event.preventDefault();if(pendingText||!input.value.trim())return;pendingText=input.value;open();if(adapter.submitDraft){if(adapter.submitDraft(pendingText)){input.value='';try{sessionStorage.removeItem(draftKey);}catch(_){}}else bubble('Your message is kept in the chatbar. Finish the existing draft in Chat first.');pendingText='';return;}if(ready)frame.contentWindow.postMessage({channel:'vp3-orb',kind:'compose',text:pendingText},origin);});
      document.body.append(bar);document.body.classList.add('vp3-orb-has-bar');
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
      const data=event.data;if(data.kind==='ready'){ready=true;if(pendingListen){pendingListen=false;command('listen');}if(pendingReplyWindow){pendingReplyWindow=false;command('response-window');}if(pendingText)frame.contentWindow.postMessage({channel:'vp3-orb',kind:'compose',text:pendingText},origin);if(pendingVoiceEnrollment){frame.contentWindow.postMessage({channel:'vp3-orb',kind:'voice-enroll',id:pendingVoiceEnrollment},origin);pendingVoiceEnrollment='';}}
      if(data.kind==='agent-orb-shape'&&String(data.ownerKey)===String(adapter.ownerKey)
        &&(data.shape==='orbit'||data.shape==='particle_face'))window.dispatchEvent(new CustomEvent('vp3:agent-orb-shape',{detail:{shape:data.shape}}));
      if(data.kind==='jobs'&&String(data.ownerKey)===String(adapter.ownerKey))setJobs(data.source,data.jobs);
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
      if(data.kind==='state')render(data.state);
      if(data.kind==='initiative-status'&&!panel.hidden&&String(data.ownerKey)===String(adapter.ownerKey)){
        setInitiativeStatus(data.view,action=>{
          if(!panel.hidden)frame?.contentWindow?.postMessage({channel:'vp3-orb',kind:'initiative-action',action},origin);
        });
      }
      if(data.kind==='draft-accepted'){const input=bar?.querySelector('input');if(input&&input.value===pendingText){input.value='';try{sessionStorage.removeItem(draftKey);}catch(_){}}pendingText='';}
      if(data.kind==='draft-blocked'){pendingText='';bubble('Your existing draft is in the chat popup. Your chatbar message is still here.');}
    }
    function render(raw){
      const s=window.VP3EyesCore.visualState(raw),recording=String(s.recording);
      if(root.dataset.state===s.state&&root.dataset.recording===recording)return;
      root.dataset.state=s.state;root.dataset.recording=recording;
      label.textContent=(s.recording?'Recording · ':'')+({idle:'Ready',listening:'Listening',thinking:'Thinking',speaking:'Speaking',connecting:'Connecting',error:'Needs attention'}[s.state]||'Ready');
      ball.setAttribute('aria-label',`Agent: ${label.textContent}. Open chat`);
    }
    function bubble(text){const node=root.querySelector('.vp3-orb-bubble');node.textContent=String(text).slice(0,600);node.hidden=false;clearTimeout(bubbleTimer);bubbleTimer=setTimeout(()=>{node.hidden=true;},14000);}
    let bubbleTimer=0,drag=null,dragged=false,motionFrame=0,followTarget=null,manualUntil=0;
    const reduced=matchMedia('(prefers-reduced-motion: reduce)');
    function move(at){
      motionFrame=0;if(disposed||!followTarget)return;
      if(!reduced.matches&&!drag&&at>manualUntil&&panel.hidden&&root.dataset.controlsOpen!=='true'&&!root.matches(':hover,:focus-within')){
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
    function save(){const r=root.getBoundingClientRect();try{localStorage.setItem(key,JSON.stringify({x:r.left,y:r.top,small:root.dataset.small==='true'}));}catch(_){}}
    ball.addEventListener('pointerdown',event=>{
      if(event.button!==0)return;
      dragged=false;
      if(!panel.hidden)return;
      manualUntil=performance.now()+15000;
      const r=root.getBoundingClientRect();
      drag={id:event.pointerId,x:event.clientX,y:event.clientY,left:r.left,top:r.top};
      ball.setPointerCapture(event.pointerId);
    });
    ball.addEventListener('pointermove',event=>{if(drag?.id!==event.pointerId)return;const dx=event.clientX-drag.x,dy=event.clientY-drag.y;if(Math.hypot(dx,dy)>5)dragged=true;if(!dragged)return;root.dataset.dragging='true';root.style.left=`${Math.max(0,Math.min(innerWidth-root.offsetWidth,drag.left+dx))}px`;root.style.top=`${Math.max(0,Math.min(innerHeight-root.offsetHeight-80,drag.top+dy))}px`;root.style.right='auto';root.style.bottom='auto';});
    const finishDrag=()=>{drag=null;delete root.dataset.dragging;positionInitiative();};
    ball.addEventListener('pointerup',()=>{finishDrag();if(dragged)save();});ball.addEventListener('pointercancel',finishDrag);
    const touchControls=event=>{if(event.pointerType==='touch'&&root.contains(event.target)){root.dataset.controlsOpen='true';revealInitiative();}else if(!root.contains(event.target))root.dataset.controlsOpen='false';};
    document.addEventListener('pointerdown',touchControls,{passive:true});
    const resize=()=>{reservePopupFooter();const r=root.getBoundingClientRect();if(panel.hidden&&(r.left>innerWidth-80||r.top>innerHeight-140)){root.style.left='auto';root.style.top='auto';root.style.right='12px';root.style.bottom='94px';}positionInitiative();};
    const escape=event=>{
      if(event.key!=='Escape'||appearanceDialog?.open)return;
      if(!initiative.hidden&&root.dataset.initiativeDismissed!=='true'&&(root.matches(':hover,:focus-within')||root.dataset.controlsOpen==='true')){
        if(initiative.contains(document.activeElement))ball.focus();
        root.dataset.initiativeDismissed='true';
        return;
      }
      if(!panel.hidden)close();
    };
    window.addEventListener('message',message);window.addEventListener('resize',resize);document.addEventListener('keydown',escape);
    timer=setInterval(()=>{if(!frame){const ref=adapter.conversationRef?.();if(ref)rememberRef(ref);const next=state(),json=JSON.stringify(next);if(json!==lastState){render(next);lastState=json;}}},200);
    window.addEventListener('vp3:participant-photo-state',event=>{const d=event.detail||{};if(['waiting','capturing','saving','saved','failed','deferred'].includes(d.state)&&typeof d.message==='string')frame?.contentWindow?.postMessage({channel:'vp3-orb',kind:'participant-photo-state',value:{state:d.state,message:d.message.slice(0,500)}},origin);});
    window.VP3AgentOrb={open,close,toggle,bubble,state,follow,setInitiativeStatus,setJobs,listenForResponse:()=>{const voice=window.VP3ConversationControls||(document.getElementById('chatForm')&&window.HomeServerConversationVoice);if(voice)return voice.responseWindow?.();open();if(ready)command('response-window');else pendingReplyWindow=true;return true;},cancelResponseWindow:()=>{pendingReplyWindow=false;if(frame)command('response-window-cancel');else window.VP3ConversationControls?.cancelResponseWindow?.();},participantSignal:(kind,value)=>{if(['presence','reject','invalidate','selection'].includes(kind))frame?.contentWindow?.postMessage({channel:'vp3-orb',kind:'participant-'+kind,value},origin);},setIntroductionMode:mode=>{introductionMode=mode;window.VP3VoiceGuard?.inviteIntroduction(mode);frame?.contentWindow?.postMessage({channel:'vp3-orb',kind:'introduction-window',mode},origin);},startVoiceEnrollment:id=>{if(adapter.attachChat||adapter.isMainChat?.())return false;open();if(ready)frame.contentWindow.postMessage({channel:'vp3-orb',kind:'voice-enroll',id},origin);else pendingVoiceEnrollment=id;return true;},cancelVoiceEnrollment:()=>{pendingVoiceEnrollment='';frame?.contentWindow?.postMessage({channel:'vp3-orb',kind:'voice-enroll-cancel'},origin);},stop:()=>command('stop')};
    window.dispatchEvent(new Event('vp3:agent-orb-ready'));
    window.addEventListener('pagehide',()=>{disposed=true;jobSnapshots.clear();refreshJobs();clearInterval(timer);clearTimeout(bubbleTimer);cancelAnimationFrame(motionFrame);footerObserver?.disconnect();document.removeEventListener('pointerdown',touchControls);window.removeEventListener('message',message);window.removeEventListener('resize',resize);document.removeEventListener('keydown',escape);adapter.stop?.();},{once:true});
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
