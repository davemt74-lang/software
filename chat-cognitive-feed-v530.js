(() => {
  'use strict';
  if (window.VP3_COGNITIVE_FEED_V530_RUNTIME) return;

  const cfg = window.VP3_COGNITIVE_FEED_V530 || {};
  const welcome = document.getElementById('chatWelcome');
  if (!welcome) return;

  const clean = value => String(value == null ? '' : value).replace(/\s+/g,' ').trim();
  const el = (tag,className,textValue) => {
    const node=document.createElement(tag);
    if(className)node.className=className;
    if(textValue!==undefined&&textValue!==null)node.textContent=String(textValue);
    return node;
  };

  let root=null;
  let body=null;
  let status=null;
  let timer=null;
  let loading=false;
  let lastFeed=null;

  function visible() {
    return !document.hidden && !welcome.hidden;
  }

  function endpointUrl() {
    const url=new URL(String(cfg.endpoint || '/api/cognitive-feed-v530.php'),window.location.origin);
    url.searchParams.set('action','state');
    url.searchParams.set('agent_id',String(Number(cfg.agentId||0)));
    return url.toString();
  }

  async function learningApi(action,payload={}) {
    const endpoint=String(cfg.learningEndpoint||'/api/cognitive-learning-v540.php');
    if(action==='explain'){
      const url=new URL(endpoint,window.location.origin);
      url.searchParams.set('action','explain');
      url.searchParams.set('agent_id',String(Number(cfg.agentId||0)));
      url.searchParams.set('item_key',clean(payload.item_key));
      const response=await fetch(url.toString(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      const data=await response.json().catch(()=>({}));
      if(!response.ok||!data.ok)throw new Error(data.error||'learning_unavailable');
      return data;
    }
    const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify({action:'feedback',agent_id:Number(cfg.agentId||0),csrf_token:String(cfg.csrf||''),...payload})});
    const data=await response.json().catch(()=>({}));
    if(!response.ok||!data.ok)throw new Error(data.error||'learning_unavailable');
    return data;
  }

  async function planningApi(action,planId) {
    const response=await fetch(String(cfg.planningEndpoint||'/api/cognitive-planning-v550.php'),{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify({
        action,
        plan_id:clean(planId),
        agent_id:Number(cfg.agentId||0),
        csrf_token:String(cfg.csrf||'')
      })
    });
    const data=await response.json().catch(()=>({}));
    if(!response.ok||!data.ok)throw new Error(data.error||'planning_unavailable');
    return data;
  }

  async function api(action,payload={}) {
    if(action==='state'){
      const response=await fetch(endpointUrl(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      const data=await response.json().catch(()=>({}));
      if(!response.ok||!data.ok)throw new Error(data.error||'feed_unavailable');
      return data;
    }
    const response=await fetch(String(cfg.endpoint||'/api/cognitive-feed-v530.php'),{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify({action,agent_id:Number(cfg.agentId||0),csrf_token:String(cfg.csrf||''),...payload})
    });
    const data=await response.json().catch(()=>({}));
    if(!response.ok||!data.ok)throw new Error(data.error||'feed_unavailable');
    return data;
  }

  function mount() {
    if(root)return root;
    root=el('section','vp3-cognitive-feed-v530');
    root.id='vp3CognitiveFeed';
    root.hidden=true;
    root.setAttribute('aria-label','Agent cognitive feed');

    const head=el('header','vp3-cognitive-feed-head');
    const copy=el('div','vp3-cognitive-feed-head-copy');
    copy.appendChild(el('small','vp3-cognitive-feed-kicker','NOW'));
    copy.appendChild(el('strong','vp3-cognitive-feed-title','What deserves your attention'));
    status=el('span','vp3-cognitive-feed-status','');
    status.setAttribute('aria-live','polite');
    copy.appendChild(status);

    const controls=el('div','vp3-cognitive-feed-controls');
    const refresh=el('button','vp3-cognitive-feed-control','Refresh');
    refresh.type='button';
    refresh.addEventListener('click',()=>void refreshFeed(true));
    const restore=el('button','vp3-cognitive-feed-control','Show hidden');
    restore.type='button';
    restore.hidden=true;
    restore.dataset.feedRestore='1';
    restore.addEventListener('click',async()=>{
      restore.disabled=true;
      try{
        const data=await api('restore_all');
        renderFeed(data.feed||null);
      }catch(_error){
        status.textContent='Could not restore hidden items.';
      }finally{
        restore.disabled=false;
      }
    });
    controls.append(refresh,restore);
    head.append(copy,controls);

    body=el('div','vp3-cognitive-feed-body');
    const proactiveBrief=el('section','vp3-cognitive-proactive-brief-v2340');
    proactiveBrief.dataset.cognitiveProactiveBrief='1';
    proactiveBrief.hidden=true;
    const operationsStrip=el('div','vp3-cognitive-operations-strip');
    operationsStrip.dataset.cognitiveOperations='1';
    operationsStrip.hidden=true;
    const priorityQueue=el('section','vp3-cognitive-priority-queue-v2310');
    priorityQueue.dataset.cognitivePriorityQueue='1';
    priorityQueue.hidden=true;
    root.append(head,proactiveBrief,operationsStrip,priorityQueue,body);

    const starters=welcome.querySelector('.chat-starters');
    if(starters)welcome.insertBefore(root,starters);
    else welcome.appendChild(root);
    return root;
  }

  function sectionNodeHasItems(sectionNode) {
    return Boolean(sectionNode&&sectionNode.querySelector('.vp3-cognitive-feed-item'));
  }

  function updateEmptyState() {
    if(!root||!body)return;
    const any=Boolean(body.querySelector('.vp3-cognitive-feed-item'));
    const proactive=Boolean(root.querySelector('[data-cognitive-proactive-brief]:not([hidden])'));
    const hidden=Number(lastFeed&&lastFeed.hidden_count||0);
    root.hidden=!any&&!proactive&&hidden<1;
  }

  function focusFeedItem(key) {
    key=clean(key);
    if(!key||!root)return;
    const target=root.querySelector('[data-feed-item-key="'+CSS.escape(key)+'"]');
    if(!target)return;
    target.scrollIntoView({behavior:'smooth',block:'center'});
    target.setAttribute('tabindex','-1');
    target.focus({preventScroll:true});
  }

  function feedItem(section,item) {
    const wrap=el('article','vp3-cognitive-feed-item');
    wrap.dataset.feedItemKey=clean(item.key);
    if(item.attention)wrap.dataset.attention='1';

    const meta=el('div','vp3-cognitive-feed-item-meta');
    const reason=el('div','vp3-cognitive-feed-reason');
    reason.appendChild(el('small','',section.id==='attention'?'WHY NOW':'CONTEXT'));
    reason.appendChild(el('span','',clean(item.reason)||clean(section.description)));

    const actions=el('div','vp3-cognitive-feed-item-controls');
    const cardType=clean(item&&item.card_request&&item.card_request.card_type);
    if(cardType==='proactive_plan'){
      const ref=item&&item.card_request&&item.card_request.object_ref||{};
      const planId=clean(ref.id);
      const planStatus=clean(item.plan_status);
      if(planStatus==='proposed'){
        const accept=el('button','vp3-cognitive-feed-hide','Accept plan');
        accept.type='button';
        accept.addEventListener('click',async()=>{
          accept.disabled=true;
          try{
            const data=await planningApi('accept',planId);
            renderFeed(data.feed||null);
          }catch(_error){status.textContent='Could not accept this plan.';}
          finally{accept.disabled=false;}
        });
        actions.appendChild(accept);
      }else if(planStatus==='accepted'){
        const accepted=el('button','vp3-cognitive-feed-hide','Accepted');
        accepted.type='button';
        accepted.disabled=true;
        actions.appendChild(accepted);
      }
      const dismiss=el('button','vp3-cognitive-feed-hide','Dismiss plan');
      dismiss.type='button';
      dismiss.addEventListener('click',async()=>{
        dismiss.disabled=true;
        try{
          const data=await planningApi('dismiss',planId);
          renderFeed(data.feed||null);
        }catch(_error){status.textContent='Could not dismiss this plan.';}
        finally{dismiss.disabled=false;}
      });
      actions.appendChild(dismiss);
    }
    if(cardType==='onboarding_setup'){
      const ref=item&&item.card_request&&item.card_request.object_ref||{};
      const workflow=clean(ref.id);
      const later=el('button','vp3-cognitive-feed-hide','Do later');
      later.type='button';
      later.addEventListener('click',async()=>{
        later.disabled=true;
        try{
          const data=await api('activation_defer',{workflow,defer_days:3});
          renderFeed(data.feed||null);
          status.textContent='I’ll bring this setup step back in 3 days.';
        }catch(_error){status.textContent='Could not defer this setup step.';}
        finally{later.disabled=false;}
      });
      const dismissSetup=el('button','vp3-cognitive-feed-hide','Not interested');
      dismissSetup.type='button';
      dismissSetup.addEventListener('click',async()=>{
        dismissSetup.disabled=true;
        try{
          const data=await api('activation_dismiss',{workflow});
          renderFeed(data.feed||null);
          status.textContent='Removed from your selected VP3 setup.';
        }catch(_error){status.textContent='Could not update this setup preference.';}
        finally{dismissSetup.disabled=false;}
      });
      actions.append(later,dismissSetup);
    }
    const why=el('button','vp3-cognitive-feed-hide','Why?');
    why.type='button';
    why.addEventListener('click',async()=>{
      why.disabled=true;
      try{
        const data=await learningApi('explain',{item_key:clean(item.key)});
        const note=el('div','vp3-cognitive-feed-explain',clean(data.explanation&&data.explanation.explanation));
        const existing=wrap.querySelector('.vp3-cognitive-feed-explain');
        if(existing)existing.replaceWith(note); else wrap.insertBefore(note,wrap.querySelector('.vp3-cognitive-feed-card-host'));
      }catch(_error){status.textContent='Could not explain this ranking right now.';}
      finally{why.disabled=false;}
    });
    const hide=el('button','vp3-cognitive-feed-hide','Hide');
    hide.type='button';
    hide.setAttribute('aria-label','Hide this feed item until it changes');
    hide.addEventListener('click',async()=>{
      hide.disabled=true;
      try{
        const data=await api('hide',{item_key:clean(item.key),fingerprint:clean(item.fingerprint)});
        renderFeed(data.feed||null);
      }catch(error){
        status.textContent=String(error&&error.message)==='feed_item_changed'
          ? 'This item changed. The feed has been refreshed.'
          : 'Could not hide this item.';
        void refreshFeed(true);
      }finally{
        hide.disabled=false;
      }
    });
    if(cardType!=='onboarding_setup')actions.append(why,hide);
    meta.append(reason,actions);

    const host=el('div','vp3-cognitive-feed-card-host vp3-cognitive-card-host');
    wrap.append(meta,host);

    const runtime=window.VP3_COGNITIVE_CARDS_V520_RUNTIME;
    if(runtime&&item.card_request){
      void runtime.renderRequests([item.card_request],host,{showError:false}).then(result=>{
        if(result&&result.rendered>0){
          void learningApi('feedback',{
            event_type:'shown',
            item_key:clean(item.key),
            fingerprint:clean(item.fingerprint),
            dedupe:'render:'+clean(item.fingerprint)+':'+String(Math.floor(Date.now()/21600000))
          }).catch(()=>{});
        }
        if(!result||result.rendered<1){
          const sectionNode=wrap.closest('.vp3-cognitive-feed-section');
          wrap.remove();
          if(!sectionNodeHasItems(sectionNode))sectionNode?.remove();
          updateEmptyState();
        }
      });
    }else{
      wrap.remove();
    }
    return wrap;
  }

  function renderFeed(feed) {
    mount();
    lastFeed=feed&&typeof feed==='object'?feed:null;
    body.replaceChildren();

    const restore=root.querySelector('[data-feed-restore]');
    const hiddenCount=Number(lastFeed&&lastFeed.hidden_count||0);
    if(restore){
      restore.hidden=hiddenCount<1;
      restore.textContent=hiddenCount>0?'Show hidden ('+hiddenCount+')':'Show hidden';
    }

    const proactiveBrief=root.querySelector('[data-cognitive-proactive-brief]');
    const proactive=lastFeed&&lastFeed.proactive_brief&&typeof lastFeed.proactive_brief==='object'?lastFeed.proactive_brief:null;
    if(proactiveBrief){
      proactiveBrief.replaceChildren();
      if(proactive){
        const intro=el('div','vp3-cognitive-proactive-copy');
        intro.append(
          el('small','','AGENT NOW'),
          el('strong','',clean(proactive.greeting)||'Your Agent brief.'),
          el('span','',clean(proactive.headline))
        );
        const summary=clean(proactive.summary);
        if(summary)intro.appendChild(el('em','',summary));

        const voice=proactive.voice&&typeof proactive.voice==='object'?proactive.voice:{};
        const voiceBadge=el('span','vp3-cognitive-proactive-voice',voice.enabled?'Agent Voice on':'Agent Voice off');
        voiceBadge.title=voice.enabled
          ? 'Immediate speech uses the existing canonical notification cursor. Opportunities remain visual unless included in a persisted return briefing.'
          : 'Turn on Agent Voice from the Agent + Voice menu to hear eligible canonical alerts and return briefings.';

        const cognitiveCalibration=proactive.calibration&&typeof proactive.calibration==='object'?proactive.calibration:null;
        const badges=el('div','vp3-cognitive-proactive-badges');
        badges.appendChild(voiceBadge);
        if(cognitiveCalibration){
          const rawMode=clean(cognitiveCalibration.mode)||'balanced';
          const modeLabel=rawMode.charAt(0).toUpperCase()+rawMode.slice(1);
          const evidence=Math.max(0,Number(cognitiveCalibration.evidence_count||0));
          const calibrationBadge=el('span','vp3-cognitive-proactive-calibration','Calibration · '+modeLabel+(evidence>0?' · '+evidence:''));
          calibrationBadge.title=clean(cognitiveCalibration.explanation)||'Outcome learning calibrates proactive presentation only; deterministic attention and queue ranking authority remain unchanged.';
          badges.appendChild(calibrationBadge);
        }

        const top=el('header','vp3-cognitive-proactive-head');
        top.append(intro,badges);
        proactiveBrief.appendChild(top);

        const focusItems=Array.isArray(proactive.focus_items)?proactive.focus_items:[];
        if(focusItems.length){
          const list=el('div','vp3-cognitive-proactive-focus');
          focusItems.forEach(item=>{
            const button=el('button','vp3-cognitive-proactive-focus-item');
            button.type='button';
            button.dataset.proactiveFocus=clean(item.key);
            button.append(
              el('small','',clean(item.lane_label)||'Priority'),
              el('strong','',clean(item.title)||'VP3 item'),
              el('span','',clean(item.status))
            );
            button.addEventListener('click',()=>focusFeedItem(item.key));
            list.appendChild(button);
          });
          proactiveBrief.appendChild(list);
        }
        proactiveBrief.hidden=false;
      }else proactiveBrief.hidden=true;
    }

    const operations=root.querySelector('[data-cognitive-operations]');
    const ops=lastFeed&&lastFeed.operations&&typeof lastFeed.operations==='object'?lastFeed.operations:null;
    if(operations){
      operations.replaceChildren();
      if(ops){
        const listening=ops.all_systems_listening||{};
        const counts=ops.lane_counts||{};
        const sourceCount=Number(listening.source_count||0);
        const attentionCount=Number(ops.attention_count||0);
        const planCount=Number(ops.plan_count||0);
        const items=[
          ['Listening',sourceCount+' system'+(sourceCount===1?'':'s')],
          ['Needs attention',String(attentionCount)],
          ['Plans',String(planCount)],
          ['Execution','Authority-gated']
        ];
        items.forEach(([label,value])=>{
          const node=el('span','vp3-cognitive-operations-pill');
          node.append(el('small','',label),el('strong','',value));
          operations.appendChild(node);
        });
        operations.title='All Systems Listening feeds the existing Cognitive Runtime and Agent Brain. Execution remains inside existing authority and approval boundaries.';
        operations.hidden=false;
      }else operations.hidden=true;
    }

    const priorityQueue=root.querySelector('[data-cognitive-priority-queue]');
    const queue=lastFeed&&lastFeed.priority_queue&&typeof lastFeed.priority_queue==='object'?lastFeed.priority_queue:null;
    if(priorityQueue){
      priorityQueue.replaceChildren();
      const queueItems=Array.isArray(queue&&queue.items)?queue.items:[];
      if(queueItems.length){
        const head=el('header','vp3-cognitive-priority-head');
        const copy=el('div','');
        copy.append(el('small','','PRIORITY QUEUE'),el('strong','','One queue across Agent work and cognitive signals'));
        head.append(copy,el('span','vp3-cognitive-priority-count',String(queueItems.length)));
        priorityQueue.appendChild(head);
        const list=el('div','vp3-cognitive-priority-list');
        queueItems.forEach(item=>{
          const button=el('button','vp3-cognitive-priority-row');
          button.type='button';
          button.dataset.queueTarget=clean(item.key);
          const meta=el('span','vp3-cognitive-priority-meta');
          meta.append(el('small','',clean(item.lane_label)||'Priority'),el('em','',clean(item.status)));
          const text=el('span','vp3-cognitive-priority-copy');
          text.append(el('strong','',clean(item.title)||'VP3 item'),el('small','',clean(item.reason)||clean(item.authority)));
          button.append(meta,text);
          button.addEventListener('click',()=>focusFeedItem(item.key));
          list.appendChild(button);
        });
        priorityQueue.appendChild(list);
        priorityQueue.hidden=false;
      }else priorityQueue.hidden=true;
    }

    const sections=Array.isArray(lastFeed&&lastFeed.sections)?lastFeed.sections:[];
    sections.forEach(section=>{
      const items=Array.isArray(section&&section.items)?section.items:[];
      if(!items.length)return;
      const sectionNode=el('section','vp3-cognitive-feed-section');
      sectionNode.dataset.feedSection=clean(section.id);

      const head=el('header','vp3-cognitive-feed-section-head');
      const copy=el('div','');
      copy.appendChild(el('strong','',clean(section.label)||'Updates'));
      const description=clean(section.description);
      if(description)copy.appendChild(el('span','',description));
      const count=el('em','',String(items.length));
      head.append(copy,count);
      sectionNode.appendChild(head);

      const list=el('div','vp3-cognitive-feed-list');
      items.forEach(item=>list.appendChild(feedItem(section,item)));
      sectionNode.appendChild(list);
      body.appendChild(sectionNode);
    });

    const count=Number(lastFeed&&lastFeed.item_count||0);
    status.textContent=count>0
      ? String(count)+' current item'+(count===1?'':'s')+' · updated just now'
      : (hiddenCount>0?'All current items are hidden.':'Nothing needs the canvas right now.');
    updateEmptyState();
  }

  async function refreshFeed(force=false) {
    mount();
    if(loading||(!force&&!visible()))return;
    loading=true;
    root.dataset.feedState='loading';
    try{
      const data=await api('state');
      renderFeed(data.feed||null);
      root.dataset.feedState='ready';
      const seconds=Math.max(30,Number(data.feed&&data.feed.refresh_seconds||cfg.refreshSeconds||60));
      schedule(seconds);
    }catch(_error){
      root.dataset.feedState='error';
      if(!lastFeed)root.hidden=true;
    }finally{
      loading=false;
    }
  }

  function schedule(seconds) {
    if(timer)window.clearInterval(timer);
    timer=window.setInterval(()=>void refreshFeed(false),Math.max(30,seconds)*1000);
  }

  welcome.addEventListener('vp3:cognitive-card-action',event=>{
    const itemNode=event.target&&event.target.closest?event.target.closest('.vp3-cognitive-feed-item'):null;
    if(!itemNode)return;
    const key=clean(itemNode.dataset.feedItemKey);
    const item=lastFeed&&Array.isArray(lastFeed.sections)
      ? lastFeed.sections.flatMap(section=>Array.isArray(section.items)?section.items:[]).find(row=>clean(row.key)===key)
      : null;
    if(!item)return;
    const detail=event.detail||{};
    const type=clean(detail.action&&detail.action.type)||'open';
    const handled=detail.handled===true;
    const accepted=type==='tool'?(handled&&detail.accepted===true):detail.accepted!==false;
    const actionType=type==='tool'&&!handled?'tool_review':(accepted?type:(type+'_rejected'));
    void learningApi('feedback',{
      event_type:accepted?'acted':'engaged',
      action_type:actionType,
      item_key:key,
      fingerprint:clean(item.fingerprint)
    }).catch(()=>{});
  });

  const observer=new MutationObserver(records=>{
    if(records.some(record=>record.type==='attributes'&&record.attributeName==='hidden')&&visible()){
      void refreshFeed(true);
    }
  });
  observer.observe(welcome,{attributes:true,attributeFilter:['hidden']});

  document.addEventListener('visibilitychange',()=>{if(visible())void refreshFeed(false);});
  window.addEventListener('pageshow',()=>{if(visible())void refreshFeed(true);});
  window.addEventListener('pagehide',()=>{
    observer.disconnect();
    if(timer)window.clearInterval(timer);
    timer=null;
  },{once:true});

  const runtime={build:'vp3-cognitive-feed-v531-20260921',refresh:refreshFeed,render:renderFeed};
  window.VP3_COGNITIVE_FEED_V530_RUNTIME=runtime;
  mount();
  if(visible())void refreshFeed(true);
})();