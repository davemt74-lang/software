(() => {
  'use strict';
  if (window.VP3_COGNITIVE_ORCHESTRATION_V560_RUNTIME) return;

  const cfg=window.VP3_COGNITIVE_ORCHESTRATION_V560||{};
  const clean=value=>String(value==null?'':value).replace(/\s+/g,' ').trim();
  let inFlight=new Set();

  async function api(action,runId,toolId='') {
    const key=[action,runId,toolId].join(':');
    if(inFlight.has(key))return null;
    inFlight.add(key);
    try{
      const response=await fetch(String(cfg.endpoint||'/api/cognitive-orchestration-v560.php'),{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({
          action,
          run_id:clean(runId),
          tool_id:clean(toolId),
          agent_id:Number(cfg.agentId||0),
          csrf_token:String(cfg.csrf||'')
        })
      });
      const data=await response.json().catch(()=>({}));
      if(!response.ok||!data.ok)throw new Error(data.error||'orchestration_unavailable');
      if(data.feed&&window.VP3_COGNITIVE_FEED_V530_RUNTIME){
        window.VP3_COGNITIVE_FEED_V530_RUNTIME.render(data.feed);
      }
      return data;
    }finally{
      inFlight.delete(key);
    }
  }

  document.addEventListener('vp3:cognitive-card-action',event=>{
    const detail=event.detail||{};
    const card=detail.card||{};
    if(clean(card.card_type)!=='orchestration_run')return;
    const action=detail.action||{};
    if(clean(action.type)!=='tool')return;
    const ref=card.object_ref||{};
    const runId=clean(ref.id);
    const toolId=clean(action.tool_id);
    if(!runId||!toolId)return;
    if(detail.handled!==true)return;
    const accepted=detail.accepted===true;
    void api(accepted?'handoff_requested':'handoff_rejected',runId,toolId).catch(()=>{});
  });

  const runtime={
    build:'vp3-cognitive-orchestration-v560-20260919',
    reconcile:runId=>api('reconcile',runId)
  };
  window.VP3_COGNITIVE_ORCHESTRATION_V560_RUNTIME=runtime;
})();