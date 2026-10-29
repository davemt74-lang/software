(() => {
  'use strict';
  if(window.VP3AgentResponseWindow)return;
  let generation=0,pending=null;
  const enabled=()=>window.VP3_AGENT_PREFERENCE_STATE?.enabled===true;
  async function listen(){
    if(!enabled()||document.hidden)return false;
    const token=++generation;
    try{const permission=await navigator.permissions.query({name:'microphone'});if(permission.state!=='granted'){
      window.VP3AgentOrb?.bubble('I’m waiting for your reply. Select Listen to allow microphone access.');return false;
    }}catch(_){window.VP3AgentOrb?.bubble('Select Listen to reply to your agent.');return false;}
    if(token!==generation||!enabled()||document.hidden)return false;
    return window.VP3_ORB_ADAPTER?.responseWindow?.()===true;
  }
  window.addEventListener('stonefellow:agent-proactive-speech',event=>{
    const detail=event.detail||{};
    if(detail.state==='start'){pending=String(detail.text||'').includes('?')?String(detail.text):null;return;}
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
