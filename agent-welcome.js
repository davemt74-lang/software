(() => {
  'use strict';
  const cooldown=30*60*1000;

  function bootChat(adapter) {
    const cfg=window.STONEFELLOW_CHAT;
    const identity=window.STONEFELLOW_AGENT_IDENTITY_V236;
    const chat=window.STONEFELLOW_CHAT_CONTINUITY_V87;
    const thread=document.getElementById('chatThread');
    if(window.top!==window||!identity||!thread||!Number.isSafeInteger(Number(cfg?.userId))
      ||Number(cfg.userId)<1||String(cfg.userId)!==String(adapter.ownerKey)
      ||typeof chat?.mountAgentGreeting!=='function')return;
    const key=`vp3:chat-voice-greeting:${adapter.ownerKey}`;
    let autoGreet=cfg.autoGreetEnabled===true, enabled=identity.agentVoiceEnabled===true;
    let pending=autoGreet&&enabled, explicit=false, claiming=false, active=null;
    const inChat=()=>!document.hidden&&document.hasFocus()&&!thread.hidden&&thread.dataset.view==='chat';
    const available=()=>autoGreet&&enabled&&inChat()&&chat.isReady()&&!chat.isRestoring()&&!chat.isBusy()
      &&!window.VP3ParticipantContinuity?.selectedContext?.()
      &&!window.VP3ConversationControls?.isEnabled?.()&&!window.VP3ConversationControls?.isReplyCapturePending?.()
      &&!window.STONEFELLOW_CHAT_CONTINUITY?.isBusy?.()
      &&!String(document.getElementById('chatInput')?.value||'').trim()&&!adapter.state?.()?.recording;
    const recentlyGreeted=()=>{try{const at=Number(localStorage.getItem(key)||0);return at>0&&Date.now()-at<cooldown;}catch(_){return false;}};
    function cancel() {
      pending=false;
      const record=active;active=null;
      record?.controller?.abort();
      record?.retry?.remove();
    }
    const isCurrent=record=>active===record&&available()&&chat.conversationId()===record.conversationId;
    async function listen(record,greetingText='') {
      try {
        await window.VP3AgentResponseWindow?.listen({quiet:true,automatic:true,greetingText,
          canListen:()=>isCurrent(record)&&adapter.state?.()?.state!=='speaking'});
      } finally {
        if(active===record)active=null;
      }
    }
    async function play(record) {
      if(!isCurrent(record))return;
      record.retry?.remove();
      const controller=record.controller=new AbortController();
      const current=()=>!controller.signal.aborted&&isCurrent(record);
      const complete=completed=>{
        if(completed&&current())return listen(record,record.text);
        if(active===record)active=null;
      };
      const started=await window.STONEFELLOW_NOTIFICATION_CENTER.announceWhen(record.text,current,{signal:controller.signal,onComplete:complete,autoReply:false});
      if(started||!current()||!record.element.isConnected)return;
      const retry=document.createElement('button');
      retry.type='button';retry.className='chat-action-link';retry.textContent='Hear greeting';
      record.retry=retry;
      retry.addEventListener('click',()=>void play(record));
      (record.element.querySelector('.message-body')||record.element).append(retry);
    }
    function refresh() {
      if(!pending||claiming||!available()||typeof window.STONEFELLOW_NOTIFICATION_CENTER?.announceWhen!=='function')return;
      const greet=()=>{
        if(!pending||!available())return;
        pending=false;
        const skipGreeting=!explicit&&recentlyGreeted();
        explicit=false;
        const conversationId=chat.conversationId();
        if(skipGreeting){void listen(active={conversationId});return;}
        const name=String(window.STONEFELLOW_AGENT_IDENTITY_V236?.displayName||'your agent').trim().slice(0,120);
        const message=String(window.STONEFELLOW_CHAT?.intro?.greeting||'').trim()||`Hi, I’m ${name||'your agent'}. I’m here if you’d like to talk.`;
        const element=chat.mountAgentGreeting(message);
        if(!element)return;
        try{localStorage.setItem(key,String(Date.now()));}catch(_){}
        const record=active={text:message,element,conversationId};
        void play(record);
      };
      if(!navigator.locks?.request){greet();return;}
      claiming=true;
      void navigator.locks.request(key,greet).catch(greet).finally(()=>{claiming=false;});
    }
    window.addEventListener('stonefellow:agent-voice',event=>{
      enabled=event.detail?.enabled===true;
      if(!enabled){cancel();return;}
      if(autoGreet&&event.detail?.source==='account-menu'){cancel();pending=true;explicit=true;}
      refresh();
    });
    window.addEventListener('vp3:agent-auto-greet',event=>{if(event.detail?.enabled===false){autoGreet=false;cancel();}});
    window.addEventListener('vp3:participant-selection-changed',cancel);
    window.addEventListener('vp3:chat-context-ready',()=>queueMicrotask(refresh));
    window.addEventListener('vp3:chat-canvas-view-changed',()=>{if(thread.dataset.view!=='chat')cancel();});
    window.addEventListener('stonefellow:chat-context-changing',()=>{if(active||chat.isReady())cancel();});
    window.addEventListener('focus',refresh);
    window.addEventListener('blur',()=>{if(active)cancel();});
    window.addEventListener('pagehide',cancel,{once:true});
    document.addEventListener('visibilitychange',()=>{if(document.hidden){if(active)cancel();}else refresh();});
    document.addEventListener('click',event=>{if(event.target.closest?.('[data-orb-stop],#chatVoiceButton'))cancel();},true);
    document.addEventListener('input',event=>{if(event.isTrusted&&event.target.id==='chatInput')cancel();});
    document.getElementById('chatForm')?.addEventListener('submit',cancel,true);
    window.VP3AgentWelcome={refresh};refresh();
  }

  const boot=async()=>{
    const adapter=window.VP3_ORB_ADAPTER;
    if(!adapter||window.VP3AgentWelcome||new URLSearchParams(location.search).get('orb_embed')==='1')return;
    if(adapter.isMainChat?.()){bootChat(adapter);return;}
    let preference=window.VP3_AGENT_PREFERENCE_STATE||null,permission=null,busy=false,disposed=false,generation=0,attempted=false;
    const key=`vp3:agent-welcome:${adapter.ownerKey}`;
    const notice=document.createElement('aside');notice.className='vp3-agent-setup-notice';notice.hidden=true;
    notice.innerHTML='<span role="status"></span><button type="button"></button>';
    document.body.append(notice);
    const text=notice.querySelector('span'),button=notice.querySelector('button');let action=null;
    const show=(message,label,fn)=>{text.textContent=message;button.textContent=label;action=fn;notice.hidden=false;};
    const recentlyGreeted=()=>{try{const at=Number(localStorage.getItem(key)||0);return at>0&&Date.now()-at<cooldown;}catch(_){return false;}};
    const markGreeted=()=>{try{localStorage.setItem(key,String(Date.now()));}catch(_){}notice.hidden=true;};
    function setup(){
      const menu=document.getElementById('chatProfileDropdown');
      if(menu){if(menu.hidden)document.getElementById('chatProfileButton')?.click();menu.querySelector('[data-vp3-agent-selector]')?.focus();}
      else adapter.openAgentSettings?.();
    }
    async function greet(manual=false){
      if(disposed||busy||!preference?.agent||!preference.enabled||document.hidden||recentlyGreeted())return;
      if(adapter.busy?.())return;
      if(!manual&&permission?.state!=='granted')return;
      const token=generation;busy=true;attempted=true;
      const current=()=>!disposed&&token===generation&&preference?.enabled===true&&!document.hidden;
      const run=async()=>{
        if(recentlyGreeted()||!current())return;
        const message=`Hi, I’m ${String(preference.agent.name||'your agent').slice(0,120)}. I’m here when you need me.`;
        window.VP3AgentOrb?.bubble(message);
        const spoken=await adapter.greet(message,current);
        if(!current())return;
        if(spoken===true)markGreeted();
        else show('Your agent is ready. Tap to allow greeting playback.','Hear greeting',()=>greet(true));
      };
      try{
        if(navigator.locks?.request)await navigator.locks.request(key,{ifAvailable:true},lock=>lock?run():undefined);
        else await run();
      }catch(_){if(current())show('Your agent is ready.','Hear greeting',()=>greet(true));}
      finally{busy=false;}
    }
    async function allowMicrophone(){
      try{
        const stream=await navigator.mediaDevices.getUserMedia({audio:true});
        stream.getTracks().forEach(track=>track.stop());
        if(disposed)return;
        if(permission?.state!=='granted')permission={state:'granted'};
        attempted=false;await greet(true);
      }catch(_){show('Microphone access is off. You can enable it in browser site settings.','Agent settings',setup);}
    }
    function refresh(){
      if(!preference||disposed)return;
      if(!preference.agent){show('Choose your default agent to finish setup.','Choose agent',setup);return;}
      if(!preference.enabled||!preference.allowed){notice.hidden=true;return;}
      if(recentlyGreeted()){notice.hidden=true;return;}
      if(permission?.state!=='granted'){show('Your agent is ready. Enable microphone access for your greeting.','Enable microphone',allowMicrophone);return;}
      if(!attempted)void greet();
    }
    button.addEventListener('click',()=>void action?.());
    const update=event=>{generation++;preference=event.detail;attempted=false;refresh();};
    const voice=event=>{generation++;if(preference)preference={...preference,enabled:event.detail?.enabled!==false};attempted=false;refresh();};
    const stop=event=>{if(event.target.closest?.('[data-orb-stop]')){generation++;attempted=true;notice.hidden=true;}};
    window.addEventListener('vp3:agent-preferences',update);window.addEventListener('stonefellow:agent-voice',voice);
    document.addEventListener('click',stop,true);
    try{permission=await navigator.permissions.query({name:'microphone'});permission.onchange=()=>{generation++;attempted=false;refresh();};}catch(_){}
    if(!preference&&adapter.welcomePreferences)try{preference=await adapter.welcomePreferences();}catch(_){}
    const timer=setInterval(refresh,2000);
    document.addEventListener('visibilitychange',refresh);
    window.VP3AgentWelcome={refresh};refresh();
    window.addEventListener('pagehide',()=>{disposed=true;generation++;clearInterval(timer);if(permission)permission.onchange=null;window.removeEventListener('vp3:agent-preferences',update);window.removeEventListener('stonefellow:agent-voice',voice);document.removeEventListener('visibilitychange',refresh);document.removeEventListener('click',stop,true);notice.remove();},{once:true});
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else void boot();
})();
