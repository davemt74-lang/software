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
    const note=(state,detail)=>window.VP3VoiceDiagnostics?.record('greeting',state,detail);
    function waitingReason() {
      if(document.hidden||!document.hasFocus())return 'Waiting for this Chat tab to be visible and focused.';
      if(thread.hidden||thread.dataset.view!=='chat')return 'Waiting for the Agent Chat view.';
      if(window.VP3ParticipantContinuity?.selectedContext?.())return 'The owner greeting is unavailable in participant Chat.';
      if(!chat.isReady()||chat.isRestoring())return 'Waiting for Chat to finish opening.';
      if(chat.isBusy()||window.STONEFELLOW_CHAT_CONTINUITY?.isBusy?.())return 'Waiting for Chat to be idle.';
      if(window.VP3ConversationControls?.isEnabled?.()||window.VP3ConversationControls?.isReplyCapturePending?.())
        return 'Waiting for the current voice session to finish.';
      if(String(document.getElementById('chatInput')?.value||'').trim())return 'Waiting for the Chat draft to be cleared or sent.';
      if(adapter.state?.()?.recording)return 'Waiting for the current recording to finish.';
      return '';
    }
    const available=()=>autoGreet&&enabled&&!waitingReason();
    const recentlyGreeted=()=>{try{const at=Number(localStorage.getItem(key)||0);return at>0&&Date.now()-at<cooldown;}catch(_){return false;}};
    function cancel(detail) {
      const wasPending=pending;
      pending=false;
      const record=active;active=null;
      record?.controller?.abort();
      record?.retry?.remove();
      if(detail&&(wasPending||record&&!record.greetingFinished))note('cancelled',detail);
    }
    const isCurrent=record=>active===record&&available()&&chat.conversationId()===record.conversationId;
    async function listen(record,greetingText='') {
      try {
        const responseWindow=window.VP3AgentResponseWindow;
        if(!responseWindow?.listen){
          window.VP3VoiceDiagnostics?.record('reply','blocked','Greeting reply controls are unavailable on this page.');
          return;
        }
        await responseWindow.listen({quiet:true,automatic:true,greetingText,
          canListen:()=>isCurrent(record)&&adapter.state?.()?.state!=='speaking'});
      } finally {
        if(active===record)active=null;
      }
    }
    async function play(record) {
      if(!isCurrent(record))return;
      record.retry?.remove();
      note('queued','Greeting text is in Chat. Waiting for audio playback.');
      const controller=record.controller=new AbortController();
      const current=()=>!controller.signal.aborted&&isCurrent(record);
      let playbackIssue='';
      const playbackFailed=detail=>note(playbackIssue==='playback_cancelled'?'cancelled':'failed',playbackIssue||detail);
      const complete=completed=>{
        record.greetingFinished=true;
        if(active===record&&!controller.signal.aborted){
          if(completed)note('completed','Greeting playback completed.');
          else playbackFailed('Greeting playback started and ended before completion.');
        }
        if(completed&&current())return listen(record,record.text);
        if(completed&&active===record)window.VP3VoiceDiagnostics?.record('reply','blocked','Chat was no longer ready for the greeting reply.');
        if(active===record)active=null;
      };
      const started=await window.STONEFELLOW_NOTIFICATION_CENTER.announceWhen(record.text,current,{
        signal:controller.signal,onComplete:complete,autoReply:false,
        onPlaybackStart:()=>{if(active===record)note('speaking','Greeting playback started.');},
        onPlaybackIssue:reason=>{playbackIssue=reason;}
      });
      if(!started&&active===record&&(!current()||!record.element.isConnected)){
        note('cancelled',waitingReason()||'The Chat context changed before greeting playback.');
      }
      if(started||!current()||!record.element.isConnected)return;
      playbackFailed('Greeting audio did not start. Hear greeting is available in Chat.');
      const retry=document.createElement('button');
      retry.type='button';retry.className='chat-action-link';retry.textContent='Hear greeting';
      record.retry=retry;
      retry.addEventListener('click',()=>void play(record));
      (record.element.querySelector('.message-body')||record.element).append(retry);
    }
    function refresh() {
      if(!pending||claiming)return;
      const reason=waitingReason();
      if(reason){note('waiting',reason);return;}
      if(typeof window.STONEFELLOW_NOTIFICATION_CENTER?.announceWhen!=='function'){
        note('waiting','Waiting for the Chat speech engine.');
        return;
      }
      const greet=()=>{
        if(!pending)return;
        if(!available()){note('waiting',waitingReason()||'Waiting for greeting preferences.');return;}
        pending=false;
        const skipGreeting=!explicit&&recentlyGreeted();
        explicit=false;
        const conversationId=chat.conversationId();
        if(skipGreeting){
          note('skipped','Greeting cooldown is active. Auto Greet can still open a quiet reply window.');
          void listen(active={conversationId,greetingFinished:true});
          return;
        }
        const name=String(window.STONEFELLOW_AGENT_IDENTITY_V236?.displayName||'your agent').trim().slice(0,120);
        const message=String(window.STONEFELLOW_CHAT?.intro?.greeting||'').trim()||`Hi, I’m ${name||'your agent'}. I’m here if you’d like to talk.`;
        const element=chat.mountAgentGreeting(message);
        if(!element){note('failed','The greeting could not be added to Chat.');return;}
        try{localStorage.setItem(key,String(Date.now()));}catch(_){}
        const record=active={text:message,element,conversationId};
        void play(record);
      };
      if(!navigator.locks?.request){greet();return;}
      note('queued','Waiting to check the greeting cooldown across tabs.');
      claiming=true;
      void navigator.locks.request(key,greet).catch(greet).finally(()=>{claiming=false;});
    }
    window.addEventListener('stonefellow:agent-voice',event=>{
      enabled=event.detail?.enabled===true;
      if(!enabled){cancel();note('skipped','Agent Voice is off.');return;}
      if(autoGreet&&event.detail?.source==='account-menu'){cancel();pending=true;explicit=true;}
      refresh();
    });
    window.addEventListener('vp3:agent-auto-greet',event=>{
      if(event.detail?.enabled===false){autoGreet=false;cancel();note('skipped','Auto Greet is off.');return;}
      if(event.detail?.enabled!==true||event.detail.source!=='account-menu'||event.detail.fromStorage===true)return;
      autoGreet=true;pending=true;explicit=true;
      queueMicrotask(refresh);
    });
    window.addEventListener('vp3:participant-selection-changed',()=>cancel('Participant selection changed before the greeting finished.'));
    window.addEventListener('vp3:chat-context-ready',()=>queueMicrotask(refresh));
    window.addEventListener('vp3:chat-canvas-view-changed',()=>{if(thread.dataset.view!=='chat')cancel('The view changed before the greeting finished.');});
    window.addEventListener('stonefellow:chat-context-changing',()=>{if(active||chat.isReady())cancel('The Chat context changed before the greeting finished.');});
    window.addEventListener('focus',refresh);
    window.addEventListener('blur',()=>{if(active)cancel('The Chat tab lost focus before the greeting finished.');});
    window.addEventListener('pagehide',()=>cancel(),{once:true});
    document.addEventListener('visibilitychange',()=>{if(document.hidden){if(active)cancel('The Chat tab was hidden before the greeting finished.');}else refresh();});
    document.addEventListener('click',event=>{if(event.target.closest?.('[data-orb-stop],#chatVoiceButton'))cancel('The user stopped the greeting or selected Listen.');},true);
    document.addEventListener('input',event=>{if(event.isTrusted&&event.target.id==='chatInput')cancel('The user started typing before the greeting finished.');});
    document.getElementById('chatForm')?.addEventListener('submit',()=>cancel('The user sent a Chat message before the greeting finished.'),true);
    if(!autoGreet)note('skipped','Auto Greet is off.');
    else if(!enabled)note('skipped','Agent Voice is off.');
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
