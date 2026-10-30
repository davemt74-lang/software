(() => {
  'use strict';
  const cfg=window.VP3_ORB_CONFIG||{},adapter=window.VP3_ORB_ADAPTER;
  if(!adapter||!(cfg.remindersEndpoint||adapter.remindersEndpoint)||window.VP3AgentReminders||new URLSearchParams(location.search).get('orb_embed')==='1')return;
  let busy=false,disposed=false,generation=0;
  const request=async body=>{
    if(adapter.reminderRequest)return adapter.reminderRequest(body);
    const response=await fetch(cfg.remindersEndpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({...body,csrf_token:cfg.csrf,owner_key:String(cfg.ownerKey)})});
    const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Reminders unavailable.');return data;
  };
  async function poll(){
    if(disposed||busy||document.hidden||!window.VP3_AGENT_PREFERENCE_STATE||adapter.busy?.())return;
    busy=true;const token=generation;
    const current=()=>!disposed&&token===generation&&!document.hidden;
    try{
      const thread=String(adapter.conversationRef?.()?.id||'');
      const data=await request({action:'claim',thread}),item=data.item;if(!item||!current())return;
      // A cancelled/snoozed reminder invalidates the lease before presentation.
      const receipt=await request({action:'ack',id:item.id,token:item.token});
      if(!receipt.acknowledged||!current())return;
      // Bubble uses textContent. User reminder titles are never HTML or commands.
      window.VP3AgentOrb?.bubble(item.text);
      window.dispatchEvent(new CustomEvent('vp3:reminder-due',{detail:item}));
      if(current()&&!adapter.busy?.()&&window.VP3_AGENT_PREFERENCE_STATE?.enabled===true)await adapter.greet?.(item.text,current);
    }catch(_){/* The stored notification/Chat occurrence remains available; next poll retries a failed lease. */}
    finally{busy=false;}
  }
  const stop=()=>{generation++;};
  const visible=()=>{if(document.hidden)stop();else void poll();};
  const timer=setInterval(poll,15000);
  window.addEventListener('stonefellow:agent-voice',event=>{if(event.detail?.enabled===false)stop();});
  window.addEventListener('vp3:agent-preferences',poll);
  document.addEventListener('visibilitychange',visible);
  document.addEventListener('click',event=>{if(event.target.closest?.('[data-orb-stop]'))stop();},true);
  window.addEventListener('pagehide',()=>{disposed=true;stop();clearInterval(timer);document.removeEventListener('visibilitychange',visible);window.removeEventListener('vp3:agent-preferences',poll);},{once:true});
  window.VP3AgentReminders={poll};void poll();
})();
