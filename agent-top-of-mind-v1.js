(() => {
  'use strict';
  if (window.VP3TopOfMindFeedV1) return;
  let host=null,open=false,timer=0,controller=null,generation=0,snapshot=null,error='',closed=false,failures=0;
  const initialOwner=Number(window.STONEFELLOW_CHAT?.userId||window.VP3_ORB_CONFIG?.ownerKey||window.STONEFELLOW_NOTIFICATION_DRAWER?.userId||0);
  const initialAgent=Number(window.STONEFELLOW_CHAT?.agentId??window.STONEFELLOW_NOTIFICATION_DRAWER?.agentId??window.VP3_ORB_CONFIG?.agentId??0);
  const initialBinding=String(window.STONEFELLOW_CHAT?.workingAttentionBinding||window.STONEFELLOW_NOTIFICATION_DRAWER?.workingAttentionBinding||window.VP3_ORB_CONFIG?.workingAttentionBinding||'');
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const labels={working:'Working',queued:'Queued',interrupted:'Interrupted — worker lease expired',failed:'Failed',cancelled:'Cancelled',paused:'Paused',unknown:'Status unknown',unverified:'Completion not verified',withheld:'Withheld — source or conversation changed',waiting_owner:'Waiting for your review',ready_to_report:'Prepared — waiting to report',completed:'Completed private preparation',assessed:'Assessment complete'};
  function scope(){
    const cfg=window.STONEFELLOW_NOTIFICATION_DRAWER||{},chat=window.STONEFELLOW_CHAT;
    const owner=Number(chat?.userId||cfg.userId||window.VP3_ORB_CONFIG?.ownerKey||0),agent=Number(chat?.agentId??cfg.agentId??window.VP3_ORB_CONFIG?.agentId??0);
    const binding=String(chat?.workingAttentionBinding||cfg.workingAttentionBinding||window.VP3_ORB_CONFIG?.workingAttentionBinding||'');
    const canonical=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
    const cid=chat?Number(canonical?.conversationId?.()??chat.initialConversationId??0):0;
    if(owner!==initialOwner||agent!==initialAgent||binding!==initialBinding||!owner||!binding
      ||!Number.isSafeInteger(cid)||cid<0||!Number.isSafeInteger(agent)||agent<0)return null;
    return {owner,agent,binding,cid,hasChat:!!chat,endpoint:cfg.topOfMindEndpoint||window.VP3_ORB_CONFIG?.topOfMindEndpoint||''};
  }
  const key=s=>s?JSON.stringify([s.owner,s.agent,s.binding,s.cid]):'';
  const visible=()=>open&&!closed&&!document.hidden&&host?.isConnected;
  function stop(){window.clearTimeout(timer);timer=0;controller?.abort();controller=null;generation++;}
  function safeUrl(value){try{const u=new URL(String(value||''),location.origin);return String(value||'').startsWith('/')&&!String(value).startsWith('//')&&!String(value).includes('\\')&&u.origin===location.origin?u.pathname+u.search+u.hash:'';}catch{return '';}}
  function render(){
    if(!host?.isConnected)return;
    const current=scope();
    if(!current){host.innerHTML='<div class="agent-top-of-mind-error" role="status">Your Agent session changed. Reload to see current work.</div>';return;}
    if(snapshot&&snapshot.conversation_id!==current.cid&&current.cid>0)snapshot=null;
    const observed=snapshot?.observed_at?new Date(snapshot.observed_at):null;
    const checked=observed&&!Number.isNaN(observed.getTime())?observed.toLocaleTimeString():'';
    const staleSnapshot=!!error||observed&&Date.now()-observed.getTime()>10000;
    const runtime=String(document.body?.dataset.stonefellowAgentState||'');
    const localWorking=current.hasChat&&current.cid>0&&['thinking','processing','speaking'].includes(runtime);
    let markup='<header><div><strong>Top of Mind</strong><small>Current work and verified results</small></div><span class="agent-top-of-mind-live">'+(staleSnapshot?'Last verified':snapshot?'Live':'Connecting')+'</span></header>';
    if(localWorking)markup+='<div class="agent-top-of-mind-foreground"><strong>This Chat</strong><span>'+esc(runtime==='speaking'?'The main Agent is speaking.':'The main Agent is preparing a response.')+'</span></div>';
    if(error)markup+='<p class="agent-top-of-mind-error" role="status">'+esc(error)+(checked?' Last verified at '+esc(checked)+'.':'')+(closed?'':' <button type="button" data-top-of-mind-retry>Retry</button>')+'</p>';
    if(current.hasChat&&current.cid<1)markup+='<p>Work can attach after your first message saves this Chat.</p>';
    else if(!snapshot&&!error)markup+='<p role="status">Checking actual work status…</p>';
    else if(snapshot){
      const state=snapshot.state;
      if(state!=='ready')markup+='<p>'+esc({no_chat:'No saved work is attached to this Agent yet.',starting:'This Chat is connecting to the Agent loop.',paused:'Agent background work is paused.',linked_elsewhere:'The Agent is following another saved Chat.'}[state]||'Work status could not be verified.')+'</p>';
      if(state==='ready'&&!snapshot.items.length)markup+='<p>No active or recently verified work in this Chat’s current sources.</p>';
      markup+='<ol>';
      for(const item of snapshot.items){
        const stale=staleSnapshot,expired=item.state==='working'&&Date.parse(item.lease_expires_at)<Date.now();
        const status=expired?'interrupted':item.state,url=safeUrl(item.result_url);
        markup+='<li data-top-of-mind-run="'+item.run_id+'" data-work-state="'+esc(stale?'stale':status)+'"><div><strong>'+esc(item.objective)+'</strong><span>'+esc((stale?'Last observed: ':'')+(labels[status]||'Status unknown'))+'</span></div>';
        if(!stale&&!expired&&status==='working')markup+='<p>'+esc(item.step)+'</p><progress max="100" value="'+Math.max(0,Math.min(99,Number(item.progress)||0))+'" aria-label="Reported work progress"></progress>';
        if(item.sources?.length)markup+='<small>'+item.sources.map(x=>esc(x.title)).join(' · ')+'</small>';
        if(item.owner_state)markup+='<small>'+esc({open:'Available to continue',discussed:'Discussed with you',queued:'Private follow-through queued',deferred:'Deferred by you',declined:'Declined by you',owner_resolved:'Marked resolved by you',completed:'Follow-through preparation completed',stale:'Follow-through needs fresh sources'}[item.owner_state]||'')+'</small>';
        if(url)markup+='<a href="'+esc(url)+'">Open Chat</a>';
        markup+='</li>';
      }
      markup+='</ol>';
      if(snapshot.coverage?.initiative==='limited')markup+='<small>Some work is omitted because its current sources could not be verified.</small>';
      if(checked&&!error)markup+='<footer>Verified at '+esc(checked)+' · Verified Agent work</footer>';
    }
    host.innerHTML=markup;
  }
  function schedule(){window.clearTimeout(timer);timer=0;const active=snapshot?.items?.some(x=>['working','queued'].includes(x.state));const delay=failures?Math.min(30000,2500*2**Math.min(failures,4)):(active?2500:8000);if(visible())timer=window.setTimeout(()=>void refresh(),delay);}
  async function refresh(){
    if(!visible()||controller)return;
    const sent=scope();
    if(!sent){stop();snapshot=null;render();return;}
    if(!sent.endpoint||sent.hasChat&&sent.cid<1){snapshot=null;render();return;}
    const sentKey=key(sent),mine=++generation,request=new AbortController();controller=request;
    let timeout=0;
    try{
      const url=new URL(sent.endpoint,location.href);url.searchParams.set('agent_id',String(sent.agent));url.searchParams.set('conversation_id',String(sent.cid));
      const read=(async()=>{const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'X-VP3-Working-Attention':sent.binding},signal:request.signal});return {response,data:await response.json()};})();
      const deadline=new Promise((resolve,reject)=>{timeout=window.setTimeout(()=>{request.abort();reject(new Error('Status deadline expired.'));},8000);});
      const {response,data}=await Promise.race([read,deadline]);
      if(request.signal.aborted)throw new Error('Status deadline expired.');
      if(!visible()||mine!==generation||sentKey!==key(scope()))return;
      if(!response.ok||data?.ok!==true)throw Object.assign(new Error('Work status could not be refreshed.'),{status:response.status});
      const next=data.state;
      if(!next||next.contract!=='vp3.top-of-mind.v1'||next.owner_user_id!==sent.owner||next.agent_id!==sent.agent
        ||next.working_attention_binding!==sent.binding||!Number.isSafeInteger(next.conversation_id)||next.conversation_id<0
        ||sent.cid>0&&next.conversation_id!==sent.cid||!Array.isArray(next.items)||next.items.length>8
        ||next.items.some(x=>!Number.isSafeInteger(x.run_id)||x.run_id<1||typeof x.objective!=='string'||!Object.hasOwn(labels,x.state))){
        throw Object.assign(new Error('Work status does not match this Agent.'),{status:403});
      }
      snapshot=next;error='';failures=0;render();
    }catch(failure){
      if(mine!==generation||!visible()||sentKey!==key(scope()))return;
      error='The latest work status is unavailable.';
      failures++;
      if([401,403,419].includes(failure?.status)){snapshot=null;error='Your Agent session or access changed. Reload to see current work.';closed=true;}
      render();
    }finally{
      window.clearTimeout(timeout);if(controller===request)controller=null;
      if(mine===generation)schedule();
    }
  }
  function mount(nextHost,isOpen){
    const changed=host!==nextHost;host=nextHost;open=isOpen===true&&!!host;
    if(!visible()){stop();return;}
    if(changed){stop();render();}void refresh();
  }
  window.addEventListener('vp3:activity-center-view',event=>mount(event.detail?.topOfMindHost,event.detail?.open===true&&event.detail?.tab==='brain'));
  document.addEventListener('visibilitychange',()=>{if(document.hidden)stop();else if(visible()){render();void refresh();}});
  window.addEventListener('pagehide',()=>{closed=true;stop();});
  window.addEventListener('pageshow',()=>{closed=false;if(visible())void refresh();});
  for(const name of ['stonefellow:chat-context-changing','vp3:chat-context-ready'])window.addEventListener(name,()=>{stop();snapshot=null;error='';render();if(name==='vp3:chat-context-ready'&&visible())void refresh();});
  document.addEventListener('click',event=>{if(event.target.closest('[data-top-of-mind-retry]')&&host?.contains(event.target)){error='';stop();render();void refresh();}});
  new MutationObserver(()=>{if(visible())render();}).observe(document.body,{attributes:true,attributeFilter:['data-stonefellow-agent-state']});
  window.VP3TopOfMindFeedV1={mount};
  const existing=document.querySelector('#chatNotificationDrawer [data-agent-top-of-mind]');
  if(existing)mount(existing,document.body.classList.contains('chat-notification-drawer-open'));
})();
