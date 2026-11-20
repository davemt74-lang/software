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
  function renderCampaigns(session) {
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
    target.append(n('h3','Marketing analysis'),n('p','Live campaign state and observation coverage from the existing Marketing specialist. A missing metric is never shown as zero.'));
    const grid=n('div','','vp3-marketing-workspace-grid');
    for(const item of session.campaigns||[]){
      const block=card(item.name||'Campaign', 'Lifecycle: '+(item.window_state||'unavailable').replaceAll('_',' '));
      const list=n('ul');
      for(const kind of ['claims','redemptions','promotion']){
        list.append(n('li',kind[0].toUpperCase()+kind.slice(1)+': '+String(item.observations[kind]||'unavailable').replaceAll('_',' ')));
      }
      block.append(list);rowActions(block,link('Review analytics',item.href,'campaigns'));grid.append(block);
    }
    if(!grid.childElementCount)grid.append(card('No verified campaign results yet','Create or open a campaign to review its saved outcomes.'));
    target.append(grid);
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
        observations:item.observations&&typeof item.observations==='object'?item.observations:{}});
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
    session.coverage='loading';session.nextBefore=null;render(session);
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
      productPicker:null,productPickerActive:false};
    active=session;
    live.showMarketingCanvasView();
    if(!current(session)){clearSession();return false;}
    selectTab('overview');render(session);reload();thread.scrollTo({top:0,behavior:'instant'});title.focus({preventScroll:true});
    return true;
  }
  window.VP3MarketingWorkspaceV1=Object.freeze({open,close:leave});
})();
