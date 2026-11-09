(() => {
  'use strict';
  if(window.VP3AgentResponseWindow)return;
  let generation=0,pending=null;
  const enabled=()=> (window.VP3_AGENT_PREFERENCE_STATE?.enabled??window.STONEFELLOW_AGENT_IDENTITY_V236?.agentVoiceEnabled)===true;
  async function listen({quiet=false,canListen=()=>true,greetingText='',automatic=false}={}){
    if(!enabled()||document.hidden||!canListen())return false;
    const token=++generation;
    let deadline;
    try{
      const timeout=new Promise(resolve=>{deadline=setTimeout(resolve,2000);});
      const permission=await Promise.race([navigator.permissions.query({name:'microphone'}),timeout]);
      if(token!==generation||!enabled()||document.hidden||!canListen())return false;
      if(permission?.state!=='granted'){
        if(!quiet)window.VP3AgentOrb?.bubble('I’m waiting for your reply. Select Listen to allow microphone access.');
        return false;
      }
    }catch(_){if(!quiet&&token===generation&&enabled()&&!document.hidden)window.VP3AgentOrb?.bubble('Select Listen to reply to your agent.');return false;}
    finally{clearTimeout(deadline);}
    if(automatic)return window.VP3ConversationControls?.responseWindow?.({automatic,greetingText})===true;
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
  const cancel=()=>{generation++;pending=null;window.VP3_ORB_ADAPTER?.cancelResponseWindow?.();};
  window.addEventListener('vp3:agent-eyes-stopped',cancel);
  document.addEventListener('click',event=>{if(event.target.closest?.('[data-orb-stop]'))cancel();},true);
  window.addEventListener('stonefellow:agent-voice',event=>{if(event.detail?.enabled===false)cancel();});
  document.addEventListener('visibilitychange',()=>{if(document.hidden)cancel();});
  window.addEventListener('pagehide',cancel,{once:true});
  window.VP3AgentResponseWindow={listen,cancel};
})();
