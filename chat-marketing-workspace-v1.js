(() => {
  'use strict';
  if (window.VP3MarketingWorkspaceV1) return;
  const cfg = window.VP3_INITIATIVE_WORKBENCH;
  const initial = window.STONEFELLOW_CHAT;
  const thread = document.getElementById('chatThread');
  if (!cfg?.endpoint || !cfg.csrf || !cfg.ownerKey || !initial?.workingAttentionBinding || !thread) return;
  const endpoint = new URL(cfg.endpoint, location.href);
  if (endpoint.origin !== location.origin || !endpoint.pathname.endsWith('/api/initiative-workbench-v1.php')
      || endpoint.username || endpoint.password || endpoint.hash || endpoint.search) return;
  const rootPath = endpoint.pathname.slice(0,-'/api/initiative-workbench-v1.php'.length);
  const owner = String(cfg.ownerKey), agent = Number(cfg.agentId);
  const binding = String(initial.workingAttentionBinding);
  const stages = new Set(['listing_review','listing_unavailable','photo_needed','marketing_review','design_review',
    'image_starting','image_pending','creative_ready','creative_failed','publication_review','published','paused',
    'promotion_outcomes','needs_attention']);
  const campaignStates = new Set(['draft','scheduled','active','paused','completed','archived']);
  const tabs = ['overview','campaigns','plans','creative','analytics'];
  const stageNames = {active:'Active',draft:'Draft',scheduled:'Scheduled',paused:'Paused',
    completed:'Completed',archived:'Archived'};
  const metricGroups=Object.freeze({
    promotion:{title:'Profile promotion',subtitle:'Lifetime tracked attribution since first publication',
      fields:{clicks:'Clicks',checkout_starts:'Checkout starts',paid_orders:'Paid orders',refunded_orders:'Refunded orders'}},
    claims:{title:'Confirmed reward claims',subtitle:'Accepted, unreversed claims after campaign launch',
      fields:{reward_redemptions:'Claims'}},
    redemptions:{title:'Optimization snapshot',subtitle:'Recorded Campaign outcome evidence in the snapshot window',
      fields:{reward_redemptions:'Reward claims',decisions_sampled:'Decisions sampled'}}
  });
  const decisionTypes=Object.freeze({
    'offer-eligibility':'Reward eligibility','fatigue':'Campaign frequency',
    'low-observed-conversion':'Observed conversion','conflict-volume':'Campaign overlap'
  });
  const observationStates=new Set(['observed','unobserved','unavailable']);
  const plainDate=value=>typeof value==='string'&&/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/.test(value)
    ?value.slice(0,10):null;
  function normalizePerformance(raw) {
    const sources={};
    for(const [kind,group] of Object.entries(metricGroups)) {
      const source=raw?.sources?.[kind]||{};
      let state=observationStates.has(source.state)?source.state:'unavailable';
      const metrics={};
      if(state==='observed')for(const field of Object.keys(group.fields)) {
        const value=source.metrics?.[field];
        if(!Number.isSafeInteger(value)||value<0) { state='unavailable';break; }
        metrics[field]=value;
      }
      sources[kind]={state,metrics:state==='observed'?metrics:{},window:{
        start:plainDate(source.window?.start),end:plainDate(source.window?.end)
      }};
    }
    const decisions=Array.isArray(raw?.decisions)?raw.decisions.filter(item=>
      item&&Number.isSafeInteger(item.id)&&item.id>0&&Object.hasOwn(decisionTypes,item.type)
      &&['proposed','accepted','dismissed'].includes(item.status)).slice(0,5):[];
    return {sources,decisions};
  }

  const followthroughStates=new Set(['awaiting_change','awaiting_window','insufficient_sample','observed','unavailable']);
  function normalizedWindow(value) {
    if(!value||!Number.isSafeInteger(value.window_days)||value.window_days<7||value.window_days>365
      ||!plainDate(value.window_start)||!plainDate(value.window_end))return null;
    const metrics={};
    for(const key of ['treatment_entries','converted','reward_claimed']) {
      const number=value.metrics?.[key];
      if(!Number.isSafeInteger(number)||number<0)return null;
      metrics[key]=number;
    }
    return {start:plainDate(value.window_start),end:plainDate(value.window_end),days:value.window_days,metrics};
  }
  const learningDirections=new Set(['higher_observed_rate','lower_observed_rate','same_observed_rate']);
  function normalizeLearning(raw) {
    if(!raw||raw.contract!=='vp3.marketing-outcome-learning.v1'||raw.state!=='descriptive_only'
      ||raw.scope!=='separate_observations_not_causal'||!learningDirections.has(raw.direction)
      ||!plainDate(raw.window_end)||typeof raw.next_step!=='string'||raw.next_step.length>400)return null;
    for(const key of ['prior_rate_bps','later_rate_bps']){
      if(!Number.isSafeInteger(raw[key])||raw[key]<0||raw[key]>10000)return null;
    }
    for(const key of ['prior_entries','later_entries']){
      if(!Number.isSafeInteger(raw[key])||raw[key]<20)return null;
    }
    return {direction:raw.direction,priorRate:raw.prior_rate_bps,laterRate:raw.later_rate_bps,
      priorEntries:raw.prior_entries,laterEntries:raw.later_entries,
      lastWindow:plainDate(raw.window_end),nextStep:raw.next_step};
  }
  function normalizeExperiment(raw,record,learning,campaignId,version) {
    if(!raw||raw.contract!=='vp3.marketing-experiment-proposal.v1'||raw.state!=='proposal_only'
      ||raw.activation!=='not_supported'||raw.causality!=='not_established'
      ||raw.campaign_id!==campaignId||raw.campaign_version!==version
      ||raw.recommendation_id!==record.recommendation_id
      ||raw.observed_direction!==learning?.direction
      ||plainDate(raw.source_window_end)!==learning?.lastWindow
      ||typeof raw.source_revision!=='string'||!/^[a-f0-9]{64}$/.test(raw.source_revision)
      ||raw.primary_metric!=='attributed_conversions_per_admitted_entry')return null;
    const keys=['hypothesis','control','treatment','design','measurement','owner_next_step'];
    if(keys.some(key=>typeof raw[key]!=='string'||raw[key].length<1||raw[key].length>400))return null;
    return Object.fromEntries(keys.map(key=>[key,raw[key]]));
  }
  function normalizeFollowthrough(raw,campaignId,version) {
    if(!Array.isArray(raw))return [];
    return raw.slice(0,3).flatMap(item=>{
      if(!item||!Number.isSafeInteger(item.recommendation_id)||item.recommendation_id<1
        ||!Object.hasOwn(decisionTypes,item.type)||!followthroughStates.has(item.state)
        ||item.scope!=='observational_not_causal'||!plainDate(item.reviewed_at)
        ||item.change_at!==null&&item.change_at!==undefined&&!plainDate(item.change_at))return [];
      const before=normalizedWindow(item.before),after=item.after===null?null:normalizedWindow(item.after);
      if(!before||(item.state==='observed'&&!after))return [];
      const candidate=item.state==='observed'?normalizeLearning(item.learning):null;
      const learning=candidate&&after&&before.metrics.treatment_entries===candidate.priorEntries
        &&after.metrics.treatment_entries===candidate.laterEntries
        &&candidate.lastWindow===after.end
        &&Math.abs(candidate.priorRate-Math.round(10000*before.metrics.converted/before.metrics.treatment_entries))<=1
        &&Math.abs(candidate.laterRate-Math.round(10000*after.metrics.converted/after.metrics.treatment_entries))<=1
          ?candidate:null;
      const experiment=learning?normalizeExperiment(item.experiment,item,learning,campaignId,version):null;
      return [{recommendationId:item.recommendation_id,type:item.type,state:item.state,
        reviewedAt:plainDate(item.reviewed_at),changedAt:plainDate(item.change_at),
        before,after,learning,experiment}];
    });
  }

  function normalizeNativeAB(raw) {
    if(!raw||raw.contract!=='vp3.marketing-native-ab-observation.v1'
      ||raw.scope!=='lifetime_message_level_descriptive_not_causal'
      ||!['observed','no_comparable_variants','unavailable'].includes(raw.state)
      ||!Array.isArray(raw.groups)||raw.groups.length>3)return null;
    if(raw.state!=='observed'&&raw.groups.length)return null;
    const groups=[];
    for(const group of raw.groups){
      if(!group||!['observed','insufficient_sample'].includes(group.state)
        ||group.scope!=='lifetime_message_level_descriptive_not_causal'
        ||group.unit!=='journey_message_deliveries'
        ||typeof group.journey_key!=='string'||!/^[a-z0-9][a-z0-9_-]{0,49}$/.test(group.journey_key)
        ||typeof group.step_key!=='string'||!/^[a-z0-9][a-z0-9_-]{0,49}$/.test(group.step_key)
        ||!Array.isArray(group.variants)||group.variants.length<2||group.variants.length>4)return null;
      const variants=[],seen=new Set();
      for(const variant of group.variants){
        const key=variant?.variant_key;
        if(typeof key!=='string'||!/^[a-z0-9][a-z0-9_-]{0,29}$/.test(key)||seen.has(key)
          ||['sent','viewed','converted'].some(k=>!Number.isSafeInteger(variant[k])||variant[k]<0)
          ||variant.viewed>variant.sent||variant.converted>variant.sent)return null;
        seen.add(key);variants.push({key,sent:variant.sent,viewed:variant.viewed,converted:variant.converted});
      }
      if((group.state==='observed')!==variants.every(v=>v.sent>=20))return null;
      groups.push({journey:group.journey_key,step:group.step_key,state:group.state,variants});
    }
    return {state:raw.state,groups};
  }
  const linkedStates=new Set(['linked','approved_for_use','source_changed']);
  function normalizeLinkedCreative(input) {
    if(!Array.isArray(input))return [];
    return input.slice(0,8).flatMap(item=>{
      if(!item||!Number.isSafeInteger(item.id)||item.id<1
        ||!Number.isSafeInteger(item.recommendation_id)||item.recommendation_id<1
        ||!Number.isSafeInteger(item.product_id)||item.product_id<1
        ||!Number.isSafeInteger(item.campaign_version)||item.campaign_version<0
        ||!linkedStates.has(item.review_state)||typeof item.product_title!=='string'
        ||item.product_title.length>150||typeof item.plan_stage!=='string'
        ||item.plan_stage.length>40||item.implementation!=='not_verified')return [];
      return [{id:item.id,recommendationId:item.recommendation_id,productId:item.product_id,
        productTitle:item.product_title,planStage:item.plan_stage,reviewState:item.review_state,
        campaignVersion:item.campaign_version,evidenceState:typeof item.evidence_state==='string'
          ?item.evidence_state.slice(0,30):'unavailable'}];
    });
  }

  const context = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
  const n = (tag,text='',klass='') => {
    const element = document.createElement(tag);
    element.textContent = text;
    if (klass) element.className = klass;
    return element;
  };
  const button = (text,action,klass='') => {
    const el=n('button',text,klass);
    el.type='button'; el.addEventListener('click',action);
    return el;
  };
  const panel=n('section','','vp3-marketing-workspace');
  panel.id='chatMarketingWorkspaceV1';
  panel.dataset.chatView='marketing';
  panel.hidden=true;
  panel.setAttribute('aria-labelledby','vp3MarketingWorkspaceTitle');
  const header=n('header','','vp3-marketing-workspace-header');
  const headerCopy=n('div');
  const kicker=n('small','LONGFELLOW · MARKETING');
  const title=n('h2','Marketing Workspace');
  title.id='vp3MarketingWorkspaceTitle';title.tabIndex=-1;
  const subtitle=n('p','Your campaigns, product marketing plans, creative work and follow-through in one Chat workspace.');
  headerCopy.append(kicker,title,subtitle);
  const headerActions=n('div','','vp3-marketing-workspace-actions');
  const refresh=button('Refresh',()=>reload());
  const back=button('Back to Chat',()=>leave());
  headerActions.append(refresh,back);header.append(headerCopy,headerActions);
  const status=n('p','','vp3-marketing-workspace-status');
  status.setAttribute('role','status');status.setAttribute('aria-live','polite');
  const tabbar=n('div','','vp3-marketing-workspace-tabs');
  tabbar.setAttribute('role','tablist');tabbar.setAttribute('aria-label','Marketing workspace sections');
  const panes=new Map(),tabButtons=new Map();
  const container=n('div','','vp3-marketing-workspace-content');
  for (const [index,key] of tabs.entries()) {
    const labels={overview:'Overview',campaigns:'Campaigns',plans:'Product plans',creative:'Creative & Rewards',analytics:'Analytics'};
    const t=button(labels[key],()=>selectTab(key),'vp3-marketing-workspace-tab');
    t.id='vp3MarketingTab'+index;t.setAttribute('role','tab');
    t.setAttribute('aria-controls','vp3MarketingPane'+index);t.setAttribute('aria-selected','false');
    t.tabIndex=-1;tabbar.append(t);tabButtons.set(key,t);
    const pane=n('section','','vp3-marketing-workspace-pane');
    pane.id='vp3MarketingPane'+index;pane.setAttribute('role','tabpanel');pane.setAttribute('aria-labelledby',t.id);
    pane.hidden=true;container.append(pane);panes.set(key,pane);
  }
  tabbar.addEventListener('keydown',event=>{
    if (!['ArrowRight','ArrowLeft','Home','End'].includes(event.key))return;
    const activeKey=[...tabButtons].find(([,el])=>el===document.activeElement)?.[0];
    if(!activeKey)return;
    event.preventDefault();const current=tabs.indexOf(activeKey);
    const next=event.key==='Home'?0:event.key==='End'?tabs.length-1:
      (current+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
    selectTab(tabs[next]);tabButtons.get(tabs[next]).focus();
  });
  panel.append(header,status,tabbar,container);
  thread.append(panel);
  let active=null, sequence=0;
  const allowed=()=>String(window.VP3_INITIATIVE_WORKBENCH?.ownerKey)===owner
    &&String(window.VP3_INITIATIVE_WORKBENCH?.csrf)===String(cfg.csrf)
    &&String(window.STONEFELLOW_CHAT?.workingAttentionBinding||'')===binding
    &&String(window.STONEFELLOW_CHAT?.userId)===owner
    &&Number(window.STONEFELLOW_CHAT?.agentId)===agent
    &&!window.VP3ParticipantContinuity?.selectedContext?.();
  function current(session) {
    const live=context();
    return active===session && thread.dataset.view==='marketing' && allowed()
      && Number(live?.conversationId?.())===session.cid && Number(live?.agentId?.())===agent
      && live?.isReady?.()===true && live?.isRestoring?.()!==true;
  }
  function clearSession() {
    if (!active)return;
    active.editor?.dispose();active.editor=null;
    active.canvasLink?.dispose();active.canvasLink=null;
    active.productPicker?.dispose();active.productPicker=null;
    active.activation?.dispose();active.activation=null;
    for(const request of active.requests.values())request.abort();
    active.requests.clear();
    active=null;
    for(const pane of panes.values())pane.replaceChildren();
    status.textContent='';
  }
  function leave() {
    clearSession();
    if(thread.dataset.view==='marketing')context()?.showChatView?.();
    document.getElementById('chatInput')?.focus({preventScroll:true});
  }
  window.addEventListener('vp3:chat-canvas-view-changed',()=>{
    panel.hidden=thread.dataset.view!=='marketing';
    if(panel.hidden)clearSession();
  });
  for(const event of ['stonefellow:chat-context-changing','vp3:chat-conversation-changed','vp3:participant-selection-changed','pagehide']){
    window.addEventListener(event,()=>{
      if(!active)return;
      clearSession();
      if(thread.dataset.view==='marketing')context()?.showChatView?.();
    });
  }
  function selectTab(key) {
    if(!tabs.includes(key)||!active)return;
    active.tab=key;
    for(const name of tabs){
      const selected=name===key;
      panes.get(name).hidden=!selected;
      const control=tabButtons.get(name);
      control.setAttribute('aria-selected',String(selected));control.tabIndex=selected?0:-1;
    }
  }
  function display(text){if(active)status.textContent=text;}
  function localLink(href,kind,plan) {
    if(typeof href!=='string'||href.length>800||/[\x00-\x1f\x7f\\]/.test(href))return '';
    try{
      const url=new URL(href,location.origin);
      if(url.origin!==location.origin||url.username||url.password||!['http:','https:'].includes(url.protocol)
        ||url.pathname.slice(0,rootPath.length)!==rootPath)return '';
      const path=url.pathname.slice(rootPath.length);
      const keys=[...url.searchParams.keys()];
      if(kind==='campaigns') {
        if(path!=='/campaigns.php'||keys.some(x=>!['merchant','edit_campaign'].includes(x))
          ||new Set(keys).size!==keys.length||url.hash&&!['#campaign-editor','#marketing-offer-draft'].includes(url.hash))return '';
        if(keys.some(x=>!/^[1-9]\d*$/.test(url.searchParams.get(x)||'')))return '';
      }else if(kind==='products') {
        if(path!=='/profile-commerce-products.php'||keys.length||url.hash)return '';
      }else if(kind==='rewards') {
        if(path!=='/rewards.php'||keys.length||url.hash)return '';
      }else if(kind==='plan') {
        if(path!=='/chat.php'||url.hash||keys.length!==3
          ||!['agent','conversation_id','marketing_plan'].every(x=>keys.filter(y=>y===x).length===1))return '';
        if(url.searchParams.get('agent')!==(agent===0?'system':String(agent))
          ||url.searchParams.get('conversation_id')!==String(plan?.conversation_id)
          ||url.searchParams.get('marketing_plan')!==String(plan?.product_id))return '';
      }else return '';
      return url.pathname+url.search+url.hash;
    }catch{return '';}
  }
  function link(text,href,kind,plan) {
    const resolved=localLink(href,kind,plan);
    if(!resolved)return n('span','Link unavailable','vp3-marketing-workspace-muted');
    const anchor=n('a',text,'vp3-marketing-workspace-link');
    anchor.href=resolved;
    return anchor;
  }
  async function request(session,key,action,fields={}) {
    if(!current(session))return null;
    session.requests.get(key)?.abort();
    const abort=new AbortController();session.requests.set(key,abort);
    try {
      const response=await fetch(endpoint.href,{method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','Accept':'application/json'},signal:abort.signal,
        body:JSON.stringify({action,tool:'marketing',owner_key:owner,csrf_token:String(cfg.csrf),
          agent_id:agent,conversation_id:session.cid,...fields})});
      if(!current(session)||abort.signal.aborted)return null;
      if([401,403,419].includes(response.status)){leave();return null;}
      const data=await response.json().catch(()=>null);
      if(!current(session)||abort.signal.aborted)return null;
      if(!response.ok||data?.ok!==true)throw new Error(typeof data?.error==='string'
        ?data.error.slice(0,320):'Marketing information is temporarily unavailable.');
      if(data.owner_key!==owner||data.agent_id!==agent||data.conversation_id!==session.cid||data.tool!=='marketing'){
        leave();return null;
      }
      return data;
    }catch(error){
      if(current(session)&&error.name!=='AbortError')display(error.message||'Cannot read Marketing. Please refresh.');
      return null;
    }finally{if(session.requests.get(key)===abort)session.requests.delete(key);}
  }
  const card=(heading,detail='')=>{
    const el=n('article','','vp3-marketing-workspace-card');
    el.append(n('h3',heading));
    if(detail)el.append(n('p',detail));
    return el;
  };
  const rowActions=(host,...actions)=>{
    const row=n('div','','vp3-marketing-workspace-card-actions');
    row.append(...actions);host.append(row);
  };
  function renderOverview(session) {
    const target=panes.get('overview');target.replaceChildren();
    const campaigns=session.campaigns||[],plans=[...session.plans.values()];
    const summary=n('div','','vp3-marketing-workspace-summary');
    for(const [value,label] of [
      [campaigns.filter(c=>c.status==='active').length,'Active campaigns shown'],
      [plans.length,'Saved product plans loaded'],
      [campaigns.filter(c=>c.readiness==='needs_setup'||c.readiness==='limited').length,'Campaigns needing review'],
      [campaigns.filter(c=>Object.values(c.performance?.sources||{}).some(x=>x.state==='observed')).length,'Campaigns with verified results'],
    ]) {
      const block=n('div','','vp3-marketing-workspace-stat');block.append(n('strong',String(value)),n('span',label));summary.append(block);
    }
    target.append(summary);
    const important=campaigns.filter(c=>c.readiness==='needs_setup'||c.readiness==='limited');
    const details=card('What deserves attention',important.length
      ?'These campaign readiness checks need review before the next action.':'No setup blockers were found in the campaigns shown. This is not a full audit.');
    for(const item of important.slice(0,5)){
      const line=n('p',(item.name||'Campaign')+': '+(item.issues[0]||'Review its saved campaign setup.'),'vp3-marketing-workspace-issue');
      details.append(line);
    }
    target.append(details);
    const next=card('Continue your work','Open an unfinished product plan, create an offer, or review a current campaign without leaving the VP3 workflow.');
    rowActions(next,button('Campaigns',()=>selectTab('campaigns')),button('Product plans',()=>selectTab('plans')),
      button('Creative & Rewards',()=>selectTab('creative')));
    target.append(next);
    if(session.coverage!=='ready')target.append(n('p','Campaign coverage: '+session.coverage+'. Unavailable reporting is not counted as zero.','vp3-marketing-workspace-muted'));
    if(session.nextBefore)target.append(n('p','More saved product plans are available in the Product plans tab.','vp3-marketing-workspace-muted'));
  }
  function openDraftEditor(session,campaignId=0) {
    if(!current(session)||!window.VP3MarketingEditorV1?.mount)return;
    session.activation?.dispose();session.activation=null;session.activationActive=false;
    const target=panes.get('campaigns');
    session.editor?.dispose();session.editor=null;session.editorActive=true;
    selectTab('campaigns');
    target.replaceChildren();
    session.editor=window.VP3MarketingEditorV1.mount(target,{
      current:()=>current(session),
      request:(action,fields)=>request(session,'editor',action,fields),
      onClose:()=>{
        if(!current(session))return;
        session.editor?.dispose();session.editor=null;session.editorActive=false;renderCampaigns(session);
      },
      onSaved:draft=>{
        if(!current(session))return;
        session.editor?.dispose();session.editor=null;session.editorActive=false;
        renderCampaigns(session);
        display('Private Campaign draft saved. Nothing published or sent.');
        void loadOverview(session);
      }
    },campaignId);
    if(!session.editor){session.editorActive=false;renderCampaigns(session);}
  }
  function openActivationReview(session,campaignId=0) {
    if(!current(session)||!window.VP3MarketingActivationV1?.mount
      ||!session.campaigns.some(c=>c.id===campaignId&&['draft','scheduled','paused'].includes(c.status)))return;
    session.editor?.dispose();session.editor=null;session.editorActive=false;
    session.activation?.dispose();session.activation=null;session.activationActive=true;
    selectTab('campaigns');
    const target=panes.get('campaigns');
    target.replaceChildren();
    session.activation=window.VP3MarketingActivationV1.mount(target,{
      current:()=>current(session),
      request:(action,fields)=>request(session,'activation',action,fields),
      onClose:()=>{
        if(!current(session))return;
        session.activation?.dispose();session.activation=null;session.activationActive=false;
        renderCampaigns(session);
      },
      onActivated:()=>{
        if(!current(session))return;
        session.activation?.dispose();session.activation=null;session.activationActive=false;
        renderCampaigns(session);
        display('Campaign activation confirmed. Review its live readiness, audience and public landing in Campaigns & Rewards.');
        void loadOverview(session);
      }
    },campaignId);
    if(!session.activation){session.activationActive=false;renderCampaigns(session);}
  }
  function renderCampaigns(session) {
    if(session.activationActive&&session.activation?.host?.isConnected)return;
    if(session.editorActive)return;
    const target=panes.get('campaigns');target.replaceChildren();
    target.append(n('h3','Campaigns & offers'),n('p','Owner-authorized Campaigns & Rewards records. Creating and publishing stay in their existing approved workflows.'));
    const actions=n('div','','vp3-marketing-workspace-card-actions');
    actions.append(button('Create private Campaign',()=>openDraftEditor(session)));
    if(session.links?.campaigns)actions.append(link('Open full Campaign Manager',session.links.campaigns,'campaigns'));
    target.append(actions);
    const grid=n('div','','vp3-marketing-workspace-grid');
    for(const campaign of session.campaigns||[]){
      const item=card(campaign.name||'Untitled campaign',campaign.business||'');
      item.append(n('span',stageNames[campaign.status]||campaign.status,'vp3-marketing-workspace-pill'));
      item.append(n('p','Readiness: '+campaign.readiness.replaceAll('_',' ')));
      if(campaign.issues.length){
        const list=n('ul');for(const issue of campaign.issues)list.append(n('li',issue));item.append(list);
      }
      rowActions(item,...(campaign.status==='draft'?[button('Edit private draft',()=>openDraftEditor(session,campaign.id))]:[]),
        ...(['draft','scheduled','paused'].includes(campaign.status)
            ?[button('Review activation',()=>openActivationReview(session,campaign.id))]:[]),
        ...(['draft','scheduled','active','paused'].includes(campaign.status)
            ?[button('Link tested Canvas',()=>openCanvasLink(session,campaign.id))]:[]),
        link('Manage campaign',campaign.href,'campaigns'));
      grid.append(item);
    }
    if(!grid.childElementCount)grid.append(card('No campaigns in this review','Open the Campaign Builder to create a draft or review existing Merchant accounts.'));
    target.append(grid);
    if(session.coverage==='limited')target.append(n('p','Showing a bounded review. Open the Campaign Builder to see the complete list.','vp3-marketing-workspace-muted'));
  }
  function planUrl(plan){return localLink(plan.chat_url,'plan',plan);}
  function validatePlan(plan) {
    return plan && Number.isSafeInteger(plan.product_id) && plan.product_id>0
      && plan.agent_id===agent && Number.isSafeInteger(plan.conversation_id) && plan.conversation_id>0
      && stages.has(plan.stage) && typeof plan.product_title==='string'
      && typeof plan.stage_label==='string';
  }
  function validResume(plan,session) {
    const r=plan.resume;
    return r && r.owner_user_id===Number(owner)&&r.agent_id===agent&&r.conversation_id===session.cid
      &&r.product_id===plan.product_id&&r.stage===plan.stage
      &&(r.source_id===undefined||typeof r.source_id==='string'&&/^[1-9]\d*$/.test(r.source_id))
      &&(r.draft_id===undefined||typeof r.draft_id==='string'&&/^[a-f0-9]{64}$/.test(r.draft_id))
      &&(r.creative_id===undefined||typeof r.creative_id==='string'&&/^[a-f0-9]{32}$/.test(r.creative_id));
  }
  async function resumePlan(session,plan) {
    if(!current(session)||session.requests.has('plan'))return;
    display('Verifying the latest version of this product plan…');
    const data=await request(session,'plan','marketing_plan',{product_id:plan.product_id});
    if(!data||!current(session))return;
    const fresh=data.plan;
    if(!validatePlan(fresh)||fresh.product_id!==plan.product_id
      ||fresh.conversation_id!==session.cid||!validResume(fresh,session)){
      display('This plan changed or needs review. Open the original Chat to continue safely.');return;
    }
    const marker=fresh.resume;
    leave();
    if(window.VP3InitiativeWorkbench?.openProductLaunch?.(marker)!==true) {
      context()?.showTurnNotice?.('This plan could not be reopened. Your saved work remains unchanged.');
    }
  }
  function renderPlans(session) {
    const target=panes.get('plans');target.replaceChildren();
    target.append(n('h3','Product marketing plans'),n('p','Continue your original product marketing work, including drafts and active promotions.'));
    const grid=n('div','','vp3-marketing-workspace-grid');
    for(const plan of session.plans.values()){
      const item=card(plan.product_title.slice(0,180)||'Untitled product',plan.stage_label.slice(0,80));
      if(plan.conversation_id===session.cid) {
        const ctl=button('Continue plan',()=>void resumePlan(session,plan));
        rowActions(item,ctl);
      } else {
        const url=planUrl(plan);
        rowActions(item,url?link('Open original Chat',url,'plan',plan):n('span','Original Chat unavailable'));
      }
      grid.append(item);
    }
    if(!grid.childElementCount)grid.append(card('No saved product plans found','Create a product listing or start a marketing draft to build your first plan.'));
    target.append(grid);
    if(session.nextBefore)target.append(button('Load more plans',()=>void loadPlans(session,session.nextBefore)));
  }
  function existing(session,tool,source) {
    if(!current(session))return;
    leave();
    let ok=false;
    if(tool==='images'){
      const bound=context()?.mediaCommandContext?.();
      ok=!!bound&&window.VP3MediaCommands?.open?.('images',bound)===true;
    }else ok=window.VP3InitiativeWorkbench?.open?.(tool,source||'')===true;
    if(!ok)context()?.showTurnNotice?.('That editor is not available in this Chat. Your saved content is unchanged.');
  }
  function openCanvasLink(session,campaignId=0) {
    if(!current(session)||!window.VP3MarketingCanvasLinkV1?.mount)return;
    session.productPicker?.dispose();session.productPicker=null;session.productPickerActive=false;
    session.canvasLink?.dispose();session.canvasLink=null;
    session.canvasLinkActive=true;session.canvasCampaignId=campaignId;
    selectTab('creative');renderCreative(session);
  }
  function verifiedLinkEndpoint(value) {
    if(typeof value!=='string'||value.length>480)return null;
    try {
      const url=new URL(value,location.origin);
      const expected=new URL('./marketing-canvas-link-v1.php',endpoint);
      return url.origin===location.origin&&url.pathname===expected.pathname&&!url.hash&&!url.search
        &&!url.username&&!url.password&&['http:','https:'].includes(url.protocol)?url:null;
    }catch{return null;}
  }
  async function submitCanvasLink(session,details,endpointText) {
    if(!current(session)||session.requests.has('canvas-write'))return null;
    const destination=verifiedLinkEndpoint(endpointText);
    if(!destination)return null;
    const previous=session.requests.get('canvas-write');
    if(previous)return null;
    const controller=new AbortController();
    session.requests.set('canvas-write',controller);
    try {
      const response=await fetch(destination.href,{method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','Accept':'application/json',
          'X-VP3-WORKING-ATTENTION':binding},
        signal:controller.signal,
        body:JSON.stringify({...details,csrf_token:String(cfg.csrf),
          agent_id:agent,conversation_id:session.cid})});
      if(!current(session)||controller.signal.aborted)return null;
      if([401,403,419].includes(response.status)){leave();return null;}
      const body=await response.json().catch(()=>null);
      if(!current(session)||controller.signal.aborted)return null;
      if(!response.ok||body?.ok!==true||body.state!=='linked')return null;
      // Do not claim a verified association until the canonical current-Chat read agrees.
      const reread=await request(session,'canvas-verification','marketing_canvas_choices',
        {campaign_id:details.campaign_id});
      if(!current(session)||!reread||reread.creative?.linked?.state!=='linked'
        ||reread.creative.campaign?.version!==details.campaign_version)return null;
      return reread;
    }catch(error){
      if(current(session)&&error?.name!=='AbortError')display('Private Canvas association was not verified. Reload before retrying.');
      return null;
    }finally{
      if(session.requests.get('canvas-write')===controller)session.requests.delete('canvas-write');
    }
  }
  function openProductPicker(session) {
    if(!current(session)||!window.VP3MarketingProductMediaV1?.mount)return;
    session.canvasLink?.dispose();session.canvasLink=null;session.canvasLinkActive=false;
    session.productPicker?.dispose();session.productPicker=null;
    session.productPickerActive=true;
    selectTab('creative');renderCreative(session);
  }
  function continueMarketingProduct(session,productId,photoId){
    if(!current(session)||!Number.isSafeInteger(productId)||productId<1
      ||typeof photoId!=='string'||!/^[1-9][0-9]{0,18}$/.test(photoId)
      ||!window.VP3InitiativeWorkbench?.open)return false;
    leave();
    const opened=window.VP3InitiativeWorkbench.open('marketing',photoId,'',{productId})===true;
    if(!opened)context()?.showTurnNotice?.('The Marketing draft workbench could not be opened. No new draft was saved.');
    return opened;
  }
  /** Owner explicitly starts a private idea, never a Campaign edit or send. */
  async function prepareApprovedCreative(session,campaign,recommendationId) {
    if(!current(session)||!Number.isSafeInteger(recommendationId)||!Number.isSafeInteger(campaign.version)
      ||campaign.version<1||session.requests.has('focus'))return;
    display('Checking current Campaign review and permissions…');
    const data=await request(session,'focus','marketing_overview');
    if(!data||!current(session))return;
    const fresh=Array.isArray(data.campaigns)?data.campaigns.find(row=>row.id===campaign.id):null;
    const accepted=normalizePerformance(fresh?.performance).decisions.some(row=>
      row.id===recommendationId&&row.status==='accepted');
    if(!fresh||!accepted||!Number.isSafeInteger(fresh.version)||fresh.version!==campaign.version||fresh.status!==campaign.status
      ||!localLink(fresh.href,'campaigns')){
      session.campaignFocus=null;
      display('This Campaign review changed. Refresh Marketing and review the current Campaign before preparing creative.');
      return;
    }
    session.campaignFocus={id:fresh.id,version:fresh.version,name:fresh.name,
      href:fresh.href,recommendationId};
    selectTab('creative');renderCreative(session);
    display('Campaign review verified. Choose a product and photo to prepare a private draft; Campaign changes require separate approval.');
  }
  /** A deliberate link is stored only after an exact Campaign + plan reread. */
  async function beginSavedWork(session,campaign,recommendationId) {
    if(!current(session)||session.workBusy||!Number.isSafeInteger(recommendationId))return;
    session.workBusy=true;
    try {
      display('Reviewing saved private Marketing drafts for this accepted Campaign recommendation…');
      const data=await request(session,'work-choices','marketing_work_choices',
        {campaign_id:campaign.id,recommendation_id:recommendationId});
      if(!data||!current(session))return;
      const choices=data.work_choices;
      if(choices?.campaign_id!==campaign.id||choices.recommendation_id!==recommendationId
        ||choices.campaign_version!==campaign.version||!Array.isArray(choices.plans)
        ||choices.plans.length>12){
        display('The Campaign or saved creative choices changed. Refresh Marketing.');return;
      }
      const plans=choices.plans.filter(plan=>plan&&Number.isSafeInteger(plan.product_id)&&plan.product_id>0
        &&typeof plan.title==='string'&&plan.title.length<=140
        &&typeof plan.stage==='string'&&stages.has(plan.stage)
        &&typeof plan.revision==='string'&&/^[a-f0-9]{64}$/.test(plan.revision));
      session.campaignFocus={id:campaign.id,name:campaign.name,version:campaign.version,
        href:campaign.href,recommendationId};
      session.workChoices={...choices,plans};
      selectTab('creative');renderCreative(session);
      display(plans.length?'Choose a saved draft to link privately. No Campaign changes are made.'
        :'No eligible saved Marketing drafts yet. Prepare a private product/image draft first.');
    }finally{session.workBusy=false;}
  }
  async function saveSavedWork(session,plan) {
    const choices=session.workChoices;
    if(!current(session)||session.workBusy||!choices||!choices.plans.includes(plan))return;
    session.workBusy=true;
    try {
      const data=await request(session,'work-write','marketing_work_link',{
        campaign_id:choices.campaign_id,recommendation_id:choices.recommendation_id,
        expected_campaign_version:choices.campaign_version,product_id:plan.product_id,
        expected_plan_revision:plan.revision});
      if(!data||!current(session))return;
      if(data.work_saved?.state!=='linked'||!Number.isSafeInteger(data.work_saved?.id)){
        display('The private association could not be verified. Refresh Marketing.');return;
      }
      session.workChoices=null;
      await loadOverview(session);
      if(!current(session))return;
      const match=session.campaigns.find(c=>c.id===choices.campaign_id)?.linkedCreative
        ?.some(l=>l.id===data.work_saved.id&&l.recommendationId===choices.recommendation_id);
      display(match?'Saved draft linked to the Campaign recommendation. Nothing published or sent.'
        :'The association could not be verified on reread. Refresh before further action.');
    }finally{session.workBusy=false;}
  }
  async function changeSavedWork(session,campaign,link,action) {
    if(!current(session)||session.workBusy||!['marketing_work_approve','marketing_work_unlink'].includes(action)
      ||(action==='marketing_work_approve' && (link.campaignVersion!==campaign.version
        ||link.reviewState==='source_changed')))return;
    session.workBusy=true;
    try {
      const data=await request(session,'work-write',action,{
        campaign_id:campaign.id,recommendation_id:link.recommendationId,
        link_id:link.id,expected_campaign_version:campaign.version});
      if(!data||!current(session))return;
      const expected=action==='marketing_work_approve'?'approved_for_use':'unlinked';
      if(data.work_saved?.id!==link.id||data.work_saved?.state!==expected){
        display('The owner decision could not be verified. Reload the current Campaign.');return;
      }
      session.approvalLinkId=null;session.unlinkLinkId=null;
      await loadOverview(session);
      if(!current(session))return;
      const exists=session.campaigns.find(c=>c.id===campaign.id)?.linkedCreative?.find(x=>x.id===link.id);
      const verified=action==='marketing_work_unlink'?!exists:exists?.reviewState==='approved_for_use';
      display(verified?(action==='marketing_work_approve'
        ?'Private creative approved for use. Apply it separately through the Campaign Manager.'
        :'Private creative association unlinked. No published Campaign content changed.')
        :'The current source changed. Review the Campaign before continuing.');
    }finally{session.workBusy=false;}
  }
  function renderCreative(session) {
    const target=panes.get('creative');
    if(session.productPickerActive&&session.productPicker?.host?.isConnected)return;
    // Do not unmount a reviewed creative selection while background Campaign
    // and Plan reads refresh; that could hide the outcome of a submitted link.
    if(session.canvasLinkActive&&session.canvasLink?.host?.isConnected)return;
    session.canvasLink?.dispose();session.canvasLink=null;
    session.productPicker?.dispose();session.productPicker=null;
    target.replaceChildren();
    target.append(n('h3','Creative Studio & Rewards'),n('p','Use the existing editors, protected media library and campaign tools. No outbound promotion or reward is issued from this workspace automatically.'));
    if(session.campaignFocus){
      const focus=session.campaignFocus;
      const focusCard=card('Approved review · '+focus.name,
        'A recommendation was accepted for review. No Campaign edit or public creative change is implied. '+
        'Choose a product and photo to prepare a separate private Marketing draft; apply any eventual changes through the full Campaign Manager.');
      rowActions(focusCard,button('Choose product & photo',()=>openProductPicker(session)),
        button('Link existing saved draft',()=>void beginSavedWork(session,focus,focus.recommendationId)),
        link('Review Campaign',focus.href,'campaigns'));
      target.append(focusCard);
      if(session.workChoices?.campaign_id===focus.id&&session.workChoices?.recommendation_id===focus.recommendationId){
        const choices=card('Associate an existing private Marketing draft',
          'This only records a private link to the accepted Campaign recommendation. It does not approve the artwork or change the Campaign.');
        for(const plan of session.workChoices.plans){
          const item=n('div','','vp3-marketing-work-option');
          item.append(n('strong',plan.title),n('small',plan.stage.replaceAll('_',' ')));
          item.append(button('Link this saved draft',()=>void saveSavedWork(session,plan)));
          choices.append(item);
        }
        if(!session.workChoices.plans.length)choices.append(n('p','No eligible drafts yet. Use the product/photo picker to prepare one.'));
        target.append(choices);
      }
    }
    const grid=n('div','','vp3-marketing-workspace-grid');
    const artwork=card('Marketing creative','Build promotional images and copy from saved product media.');
    rowActions(artwork,
      button('Choose product & photo',()=>openProductPicker(session)),
      button('Open creative workbench',()=>existing(session,'marketing')),
      button('Select an image',()=>existing(session,'images')),
      button('Link saved Canvas to Campaign',()=>openCanvasLink(session)));
    const products=card('Products','Create or edit product listings before promoting them.');
    rowActions(products,button('New product draft',()=>existing(session,'product','description')),
      link('Manage products',session.links?.products,'products'));
    const rewards=card('Rewards & Campaigns','Configure offers, claim rules and approval using the canonical Campaigns & Rewards controls.');
    rowActions(rewards,link('Manage rewards',session.links?.rewards,'rewards'),
      link('Campaign builder',session.links?.campaigns,'campaigns'));
    grid.append(artwork,products,rewards);target.append(grid);
    if(session.productPickerActive) {
      const host=n('div','','vp3-marketing-product-media-host');target.append(host);
      session.productPicker=window.VP3MarketingProductMediaV1?.mount?.(host,{
        current:()=>current(session),
        request:(action)=>request(session,'product-media',action),
        onContinue:(productId,photoId)=>continueMarketingProduct(session,productId,photoId),
        onClose:()=>{
          if(!current(session))return;
          session.productPicker?.dispose();session.productPicker=null;
          session.productPickerActive=false;renderCreative(session);
        }
      })||null;
      if(!session.productPicker)session.productPickerActive=false;
    }
    if(session.canvasLinkActive) {
      const host=n('div','','vp3-marketing-canvas-link-host');target.append(host);
      session.canvasLink=window.VP3MarketingCanvasLinkV1?.mount?.(host,{
        current:()=>current(session),
        request:(action,fields)=>request(session,'canvas-choices',action,fields),
        submit:(fields,endpointUrl)=>submitCanvasLink(session,fields,endpointUrl),
        campaigns:session.campaigns,
        onClose:()=>{
          if(!current(session))return;
          session.canvasLink?.dispose();session.canvasLink=null;
          session.canvasLinkActive=false;renderCreative(session);
        }
      },session.canvasCampaignId)||null;
      if(!session.canvasLink)session.canvasLinkActive=false;
    }
  }
  function renderAnalytics(session) {
    const target=panes.get('analytics');target.replaceChildren();
    target.append(n('h3','Campaign performance & next actions'),
      n('p','A private, read-only briefing from authorized Campaigns & Rewards and Profile promotion evidence. The full Campaigns page retains journeys, detailed analytics and approvals. Sources use different windows and must not be added together.'));
    const grid=n('div','','vp3-marketing-workspace-performance-list');
    for(const item of session.campaigns||[]){
      const block=card(item.name||'Campaign',item.business+' · '+(stageNames[item.status]||item.status));
      block.classList.add('vp3-marketing-performance-campaign');
      const sources=item.performance?.sources||{};
      const report=n('div','','vp3-marketing-performance-sources');
      let verified=false;
      for(const [kind,group] of Object.entries(metricGroups)) {
        const source=sources[kind]||{state:'unavailable',metrics:{},window:{}};
        const sourceBox=n('section','','vp3-marketing-performance-source');
        sourceBox.append(n('h4',group.title),n('p',group.subtitle));
        if(source.state==='observed') {
          verified=true;
          const metrics=n('dl','','vp3-marketing-performance-metrics');
          for(const [field,label] of Object.entries(group.fields)) {
            const value=source.metrics[field];
            metrics.append(n('dt',label),n('dd',Number(value).toLocaleString()));
          }
          sourceBox.append(metrics);
          const start=source.window.start,end=source.window.end;
          if(start)sourceBox.append(n('small','Observed '+start+(end?' through '+end:'')));
          else sourceBox.append(n('small','Recorded attribution · observation dates not supplied'));
        } else sourceBox.append(n('p',source.state==='unobserved'
          ?'No verified activity recorded yet; not a measured zero.'
          :'Evidence unavailable. No metric is estimated.','vp3-marketing-workspace-muted'));
        report.append(sourceBox);
      }
      block.append(report);
      const decisions=item.performance?.decisions||[];
      const proposals=decisions.filter(x=>x.status==='proposed');
      if(proposals.length) {
        const pending=n('div','','vp3-marketing-performance-next');
        pending.append(n('h4','Human-review recommendations'),n('p',
          proposals.map(x=>decisionTypes[x.type]).join(' · ')+'. Review in Campaigns before making any changes.'));
        block.append(pending);
      }
      const promotion=sources.promotion;
      if(promotion?.state==='observed'&&promotion.metrics.checkout_starts>0&&promotion.metrics.paid_orders===0){
        block.append(n('p','Opportunity: checkout starts are recorded but no paid orders are attributed in this source. Review the checkout path before changing the campaign.','vp3-marketing-workspace-issue'));
      } else if(promotion?.state==='observed'&&promotion.metrics.paid_orders>0) {
        block.append(n('p','Observed paid orders are attributed to this promotion. Review refunds and campaign goals before expanding the audience.','vp3-marketing-workspace-issue'));
      } else if(!verified) {
        block.append(n('p','Next step: verify publication, tracking, and the Campaigns analytics before making performance claims.','vp3-marketing-workspace-muted'));
      }
      const nativeAB=item.nativeAB;
      if(nativeAB?.state==='observed'){
        const ab=n('section','','vp3-marketing-native-ab');
        ab.append(n('h4','Native Journey A/B observations'),
          n('p','Lifetime message-delivery counts from the existing Campaigns journey engine, not unique audiences or a verified causal experiment.'));
        for(const group of nativeAB.groups){
          const entry=n('article','','vp3-marketing-native-ab-group');
          entry.append(n('strong',group.journey+' / '+group.step));
          if(group.state==='insufficient_sample'){
            entry.append(n('p','Fewer than 20 sent messages in at least one variant. Conversion-rate comparison withheld.'));
          }else{
            const values=n('dl','','vp3-marketing-followthrough-metrics');
            for(const v of group.variants){
              values.append(n('dt',v.key),n('dd',v.converted.toLocaleString()+' attributed / '+v.sent.toLocaleString()+
                ' sent · '+(100*v.converted/v.sent).toFixed(2)+'% observed'));
            }
            entry.append(values);
          }
          ab.append(entry);
        }
        block.append(ab);
      }
      const followthrough=item.followthrough||[];
      const accepted=item.performance?.decisions?.filter(d=>d.status==='accepted')||[];
      if(accepted.length){
        const evidence=n('section','','vp3-marketing-followthrough');
        evidence.append(n('h4','After the owner review'),
          n('p','Accepting a recommendation approves its review, not a campaign edit. Later snapshots are observational and cannot prove the effect of a creative change.'));
        for(const record of followthrough){
          const row=n('article','','vp3-marketing-followthrough-record');
          row.append(n('strong',decisionTypes[record.type]));
          if(record.state==='awaiting_change'){
            row.append(n('p','Review accepted on '+record.reviewedAt+'. No owner-recorded Campaign edit is verified yet.'));
          }else if(record.state==='awaiting_window'){
            row.append(n('p','A Campaign edit was recorded on '+record.changedAt+
              '. Awaiting a separately audited, nonoverlapping observation window.'));
          }else if(record.state==='unavailable'){
            row.append(n('p','Post-review evidence is incomplete or unverified. No comparison is presented.'));
          }else if(record.state==='insufficient_sample'){
            row.append(n('p','Post-review evidence was recorded, but fewer than 20 treated entries were available in at least one window. Comparison withheld.'));
          }else if(record.state==='observed'&&record.after){
            const before=record.before,after=record.after;
            row.append(n('p','Two distinct '+before.days+'-day observation windows · before '+
              before.start+'–'+before.end+' · after '+after.start+'–'+after.end));
            const metrics=n('dl','','vp3-marketing-followthrough-metrics');
            for(const [key,label] of [['treatment_entries','Admitted entries'],['converted','Attributed conversions'],['reward_claimed','Reward claims']]){
              metrics.append(n('dt',label),n('dd',before.metrics[key].toLocaleString()+' before · '+
                after.metrics[key].toLocaleString()+' after'));
            }
            row.append(metrics,n('small','Separate observed counts only; not a causal effect, revenue lift, or verified creative attribution.'));
            if(record.learning){
              const learning=record.learning;
              const direction=learning.direction==='higher_observed_rate'?'higher'
                :learning.direction==='lower_observed_rate'?'lower':'the same when rounded';
              const findings=n('div','','vp3-marketing-outcome-learning');
              findings.append(n('h5','Learning from verified observations'),
                n('p','Attributed conversion rates: '+(learning.priorRate/100).toFixed(2)+'% before ('+
                  learning.priorEntries.toLocaleString()+' entries) · '+(learning.laterRate/100).toFixed(2)+
                  '% after ('+learning.laterEntries.toLocaleString()+' entries). The later observed rate was '+
                  direction+'. These observations do not establish causal impact or creative use.'),
                n('p','Suggested next review: '+learning.nextStep));
              row.append(findings);
            }
            if(record.experiment){
              const exp=record.experiment;
              const experiment=n('section','','vp3-marketing-experiment-proposal');
              experiment.append(n('h5','Controlled experiment · proposal only'),
                n('p','Not approved or running. No random assignment, activation, exposure or causal effect has been verified.'));
              for(const [key,label] of [['hypothesis','Hypothesis'],['control','Control'],['treatment','Treatment'],
                ['design','Proposed design'],['measurement','Measurement'],['owner_next_step','Owner review']]){
                const detail=n('p');detail.append(n('strong',label+': '),document.createTextNode(exp[key]));
                experiment.append(detail);
              }
              row.append(experiment);
            }
          }
          evidence.append(row);
        }
        if(!followthrough.length)evidence.append(n('p','No independently verified post-review comparison is available.'));
        block.append(evidence);
      }
      if(accepted.length){
        const work=n('section','','vp3-marketing-linked-work');
        work.append(n('h4','Linked private creative'),
          n('p','An accepted recommendation is not proof of implementation. Linked drafts stay private until separately approved and applied.'));
        for(const linkItem of item.linkedCreative||[]){
          const element=n('article','','vp3-marketing-work-option');
          element.append(n('strong',linkItem.productTitle),
            n('small','Plan: '+linkItem.planStage.replaceAll('_',' ')+' · '+
              (linkItem.reviewState==='approved_for_use'?'Approved for use (private only)'
                :linkItem.reviewState==='source_changed'?'Original draft changed — relink required':'Saved private association')));
          if(linkItem.reviewState!=='source_changed'&&linkItem.campaignVersion===item.version){
            if(linkItem.reviewState==='linked'){
              if(session.approvalLinkId===linkItem.id){
                element.append(n('p','Confirm approval for private use only. This does not publish, send, or apply a Campaign edit.'));
                rowActions(element,button('Confirm private creative approval',()=>void changeSavedWork(session,item,linkItem,'marketing_work_approve')),
                  button('Cancel',()=>{session.approvalLinkId=null;renderAnalytics(session);}));
              }else element.append(button('Review creative approval',()=>{
                session.approvalLinkId=linkItem.id;session.unlinkLinkId=null;renderAnalytics(session);
              }));
            }
          }
          if(session.unlinkLinkId===linkItem.id){
              element.append(n('p','Remove this private association? Existing drafts and public Campaigns remain unchanged.'));
              rowActions(element,button('Confirm unlink',()=>void changeSavedWork(session,item,linkItem,'marketing_work_unlink')),
                button('Cancel',()=>{session.unlinkLinkId=null;renderAnalytics(session);}));
          }else element.append(button('Unlink',()=>{session.unlinkLinkId=linkItem.id;
              session.approvalLinkId=null;renderAnalytics(session);}));
          if(linkItem.reviewState==='source_changed'||linkItem.campaignVersion!==item.version)
            element.append(n('p','The saved draft or Campaign revision changed. A new approval requires a current, verified source.'));
          work.append(element);
        }
        if(!item.linkedCreative?.length)work.append(n('p','No saved creative is linked to an accepted recommendation yet.'));
        block.append(work);
      }
      const linkEligible=accepted.filter(d=>followthrough.some(f=>f.recommendationId===d.id));
      rowActions(block,link('Open detailed Campaign analytics',item.href,'campaigns'),
        ...(accepted.length?[button('Prepare private creative',()=>void prepareApprovedCreative(session,item,accepted[0].id)),
          ...(linkEligible.length?[button('Link saved creative',()=>void beginSavedWork(session,item,linkEligible[0].id))]:[])]:[]));
      grid.append(block);
    }
    if(!grid.childElementCount)grid.append(card('No campaigns in this bounded review',
      'Open the existing Campaigns page for the complete campaign portfolio.'));
    target.append(grid,n('p','Specialist follow-ups remain recommendations for human review; this screen does not publish, send, spend, or issue Rewards.','vp3-marketing-workspace-muted'));
    if(session.coverage==='limited')target.append(n('p','Showing a bounded review; open the Campaigns page for remaining campaigns.','vp3-marketing-workspace-muted'));
  }
  function render(session) {
    if(!current(session))return;
    renderOverview(session);renderCampaigns(session);renderPlans(session);renderCreative(session);renderAnalytics(session);
  }
  async function loadOverview(session) {
    const data=await request(session,'overview','marketing_overview');
    if(!data||!current(session))return;
    const allowedCoverage=['ready','limited','unavailable','unconfigured'];
    if(!allowedCoverage.includes(data.coverage)||!Array.isArray(data.campaigns)||data.campaigns.length>5){
      display('The campaign review could not be verified.');return;
    }
    session.coverage=data.coverage;session.campaigns=[];
    for(const item of data.campaigns){
      if(!item||!Number.isSafeInteger(item.id)||item.id<1||!campaignStates.has(item.status)
        ||typeof item.name!=='string'||typeof item.business!=='string'
        ||!Array.isArray(item.issues)||item.issues.length>4)continue;
      session.campaigns.push({...item,issues:item.issues.filter(x=>typeof x==='string').map(x=>x.slice(0,240)),
        readiness:typeof item.readiness==='string'?item.readiness:'unavailable',
        window_state:typeof item.window_state==='string'?item.window_state:'unavailable',
        observations:item.observations&&typeof item.observations==='object'?item.observations:{},
        performance:normalizePerformance(item.performance),
        followthrough:normalizeFollowthrough(item.followthrough,item.id,item.version),
        linkedCreative:normalizeLinkedCreative(item.linked_creative),
        nativeAB:normalizeNativeAB(item.native_ab)});
    }
    session.links=data.links&&typeof data.links==='object'?data.links:{};
    render(session);display('Marketing review loaded. Only currently authorized records are shown.');
  }
  async function loadPlans(session,before=null) {
    const data=await request(session,'plans','marketing_plans',before?{before}:{});
    if(!data||!current(session))return;
    if(!Array.isArray(data.plans)||data.plans.length>12){
      display('The saved product plans could not be verified.');return;
    }
    if(!before)session.plans.clear();
    for(const plan of data.plans)if(validatePlan(plan))session.plans.set(plan.product_id,plan);
    const cursor=data.next_before;
    session.nextBefore=cursor===null?null:Number.isSafeInteger(cursor)&&cursor>0&&(!before||cursor<before)?cursor:null;
    render(session);
    if(session.coverage==='loading')display('Product plans loaded. Checking campaigns…');
  }
  function reload() {
    const session=active;if(!current(session))return;
    for(const abort of session.requests.values())abort.abort();
    session.requests.clear();session.campaigns=[];session.plans.clear();session.links={};
    session.coverage='loading';session.nextBefore=null;session.campaignFocus=null;
    session.workChoices=null;session.approvalLinkId=null;session.unlinkLinkId=null;render(session);
    display('Checking current campaigns and saved product plans…');
    void loadOverview(session);void loadPlans(session);
  }
  function open(bound) {
    const live=context();
    if(!bound||bound.isCurrent?.()!==true||String(bound.ownerKey)!==owner
      ||bound.agentId!==agent||!Number.isSafeInteger(bound.conversationId)||bound.conversationId<1
      ||!allowed()||!live?.isReady?.()||live?.isRestoring?.()||typeof live?.showMarketingCanvasView!=='function')return false;
    clearSession();
    const session={id:++sequence,cid:bound.conversationId,tab:'overview',requests:new Map(),
      campaigns:[],plans:new Map(),nextBefore:null,links:{},coverage:'loading',editor:null,editorActive:false,
      canvasLink:null,canvasLinkActive:false,canvasCampaignId:0,
      productPicker:null,productPickerActive:false,campaignFocus:null,workChoices:null,
      workBusy:false,approvalLinkId:null,unlinkLinkId:null,
      activation:null,activationActive:false};
    active=session;
    live.showMarketingCanvasView();
    if(!current(session)){clearSession();return false;}
    selectTab('overview');render(session);reload();thread.scrollTo({top:0,behavior:'instant'});title.focus({preventScroll:true});
    return true;
  }
  window.VP3MarketingWorkspaceV1=Object.freeze({open,close:leave});
})();
