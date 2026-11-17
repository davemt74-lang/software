(() => {
  'use strict';
  if(window.VP3AgentResponseWindow)return;
  let generation=0,pending=null;
  let checkingGreetingPermission=false;
  const enabled=()=> (window.VP3_AGENT_PREFERENCE_STATE?.enabled??window.STONEFELLOW_AGENT_IDENTITY_V236?.agentVoiceEnabled)===true;
  const note=(state,detail)=>window.VP3VoiceDiagnostics?.record('reply',state,detail);
  async function listen({quiet=false,canListen=()=>true,greetingText='',automatic=false}={}){
    if(!enabled()||document.hidden||!canListen()){
      if(automatic)note('blocked','Chat was no longer ready for the greeting reply.');
      return false;
    }
    const token=++generation;
    checkingGreetingPermission=automatic;
    if(automatic)note('opening','Checking microphone permission for the greeting reply.');
    let deadline;
    try{
      const timeout=new Promise(resolve=>{deadline=setTimeout(resolve,2000);});
      const permission=await Promise.race([navigator.permissions.query({name:'microphone'}),timeout]);
      if(token!==generation)return false;
      if(!enabled()||document.hidden||!canListen()){
        if(automatic)note('blocked','Chat was no longer ready for the greeting reply.');
        return false;
      }
      if(permission?.state!=='granted'){
        if(automatic)note('blocked',permission?.state==='denied'?'Microphone permission is denied.':
          permission?.state==='prompt'?'Microphone permission has not been granted.':
          'The browser did not confirm microphone permission in time.');
        if(!quiet)window.VP3AgentOrb?.bubble('I’m waiting for your reply. Select Listen to allow microphone access.');
        return false;
      }
    }catch(_){
      if(automatic&&token===generation)note('blocked','This browser could not report its microphone permission.');
      if(!quiet&&token===generation&&enabled()&&!document.hidden)window.VP3AgentOrb?.bubble('Select Listen to reply to your agent.');
      return false;
    }finally{
      clearTimeout(deadline);
      if(token===generation)checkingGreetingPermission=false;
    }
    if(automatic){
      note('opening','Microphone permission is granted. Starting the greeting reply window.');
      const opened=window.VP3ConversationControls?.responseWindow?.({automatic,greetingText})===true;
      if(!opened)note('blocked','The greeting reply window could not start.');
      return opened;
    }
    return window.VP3_ORB_ADAPTER?.responseWindow?.()===true;
  }
  window.addEventListener('stonefellow:agent-proactive-speech',event=>{
    const detail=event.detail||{};
    if(detail.state==='start'){pending=detail.autoReply!==false&&String(detail.text||'').includes('?')?String(detail.text):null;return;}
    if(detail.state==='end'){
      const expected=pending&&detail.completed===true;pending=null;
      if(expected)void listen();
    }
  });
  window.addEventListener('vp3:agent-eyes-started',()=>void listen());
  const cancel=()=>{
    generation++;
    pending=null;
    if(checkingGreetingPermission)note('blocked','The greeting reply window was cancelled before microphone capture.');
    checkingGreetingPermission=false;
    window.VP3_ORB_ADAPTER?.cancelResponseWindow?.();
  };
  window.addEventListener('vp3:agent-eyes-stopped',cancel);
  document.addEventListener('click',event=>{if(event.target.closest?.('[data-orb-stop]'))cancel();},true);
  window.addEventListener('stonefellow:agent-voice',event=>{if(event.detail?.enabled===false)cancel();});
  document.addEventListener('visibilitychange',()=>{if(document.hidden)cancel();});
  window.addEventListener('pagehide',cancel,{once:true});
  window.VP3AgentResponseWindow={listen,cancel};
})();
