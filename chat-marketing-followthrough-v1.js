(() => {
  'use strict';
  if (window.VP3MarketingFollowthroughV1) return;
  const contract='vp3.marketing-followthrough.v1',reportContract='vp3.profile-commerce-followthrough.v1';
  const positive=value=>Number.isSafeInteger(value)&&value>0;
  const nonnegative=value=>Number.isSafeInteger(value)&&value>=0;
  const token=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
  const revision=value=>typeof value==='string'&&/^[a-f0-9]{64}$/.test(value);
  const text=(value,max=600)=>typeof value==='string'&&Array.from(value).length<=max;
  const coverage=new Set(['ready','limited','unavailable','unconfigured']);
  const reasons={complete:'',storage_unconfigured:'The required records are not configured.',storage_unavailable:'The required records could not be read.',
    source_proof_incomplete:'Only part of the saved source evidence can be verified.',receipt_proof_incomplete:'Only part of the payment receipt evidence can be verified.',
    conversation_attribution_unavailable:'Conversation attribution could not be verified.',legacy_conversation_attribution:'Some older clicks do not have a verified conversation link.',
    checkout_clock_unconfigured:'Complete timing for recorded checkout links is not configured.',checkout_clock_missing:'Some checkout links do not have verified recorded times.',
    source_unavailable:'The original source is unavailable.',source_changed:'The original source has changed.'};
  const catalog={recorded_cta_clicks:['clicks','Recorded promotion clicks'],conversation_attributed_clicks:['clicks','Clicks with a verified conversation link'],
    promotion_linked_exchanges:['exchanges','Promotion-linked exchanges'],recorded_attributed_orders:['orders','Attributed orders recorded'],
    verified_checkout_openings:['checkouts','Usable checkout links recorded'],verified_paid_orders:['commerce','Verified paid orders'],verified_refunded_orders:['commerce','Verified refund-affected orders']};
  const groups=[['clicks','Promotion clicks'],['exchanges','Conversation exchanges'],['orders','Orders'],['checkouts','Checkout links'],['commerce','Verified payments and refunds']];
  const tools=()=>window.VP3FixedPeriodToolsV1;
  const node=(tag,value='',className='')=>{const element=document.createElement(tag);element.textContent=value;if(className)element.className=className;return element;};
  const button=(label,action)=>{const element=node('button',label,'vp3-media-command-button');element.type='button';element.addEventListener('click',action);return element;};
  const sameScope=(a,b)=>Boolean(a&&b&&a.ownerUserId===b.ownerUserId&&a.chatAgentId===b.chatAgentId&&a.chatConversationId===b.chatConversationId&&a.workingAttentionBinding===b.workingAttentionBinding);
  function capture(value){return value&&positive(value.ownerUserId)&&nonnegative(value.chatAgentId)&&positive(value.chatConversationId)
    &&text(value.workingAttentionBinding,256)&&value.workingAttentionBinding?Object.freeze({...value}):null;}
  function endpointUrl(value){try{const url=new URL(value,location.href);return url.origin===location.origin&&!url.username&&!url.password&&!url.hash&&!url.search
    &&url.pathname.endsWith('/api/marketing-followthrough-v1.php')?url:null;}catch{return null;}}
  function parseRef(value){
    const match=typeof value==='string'&&value.length<=220?/^(marketing|profile):ft:v1:([a-f0-9]{32}):(.+)$/.exec(value):null;
    const period=match&&tools()?.parseRef('profile:analytics:v1:'+match[3]);
    return period?{...period,publicationId:match[2],ref:'marketing:ft:v1:'+match[2]+':'+match[3],sourceRef:value,periodRef:period.ref}:null;
  }
  function source(value,chatUrl){
    const parsed=parseRef(value?.ref),raw=value?.href||value?.url;
    if(!parsed||typeof raw!=='string'||!raw.startsWith('/')||raw.startsWith('//')||/[\\\u0000-\u0020\u007f]/.test(raw))return null;
    try{
      const url=new URL(raw,location.origin),base=new URL(chatUrl,location.origin);
      if(url.origin!==location.origin||base.origin!==location.origin||url.pathname!==base.pathname||!base.pathname.endsWith('/chat.php')||url.hash||url.username||url.password
        ||[...url.searchParams].length!==3||!['agent','conversation_id','marketing_followthrough'].every(key=>url.searchParams.getAll(key).length===1)
        ||url.searchParams.get('marketing_followthrough')!==parsed.ref)return null;
      const id=value=>/^[1-9][0-9]{0,15}$/.test(value||'')&&positive(Number(value))?Number(value):null;
      const agent=url.searchParams.get('agent')==='system'?0:id(url.searchParams.get('agent')),chat=id(url.searchParams.get('conversation_id'));
      return agent===null||chat===null?null:{...parsed,chatAgentId:agent,chatConversationId:chat,href:url.pathname+url.search};
    }catch{return null;}
  }
  function knownReason(value){return Object.hasOwn(reasons,value)||Object.hasOwn(tools()?.reasons||{},value);}
  function reason(value){return reasons[value]??tools()?.reasons?.[value]??'The saved evidence could not be verified.';}
  function numberPoint(value){return value&&knownReason(value.reason)&&tools()?.numberPoint({...value,reason:'complete'},'count')===true;}
  function moneyPoint(value){return value&&knownReason(value.reason)&&tools()?.moneyPoint({...value,reason:'complete'})===true;}
  function tuple(value,context){return value&&token(value.publication_id)&&token(value.promotion_id)&&positive(value.product_id)&&positive(value.source_asset_id)
    &&revision(value.draft_key)&&value.chat_agent_id===context.chatAgentId&&value.chat_conversation_id===context.chatConversationId;}
  function currentPlanUrl(value,publication,endpoint){
    if(typeof value!=='string'||!value.startsWith('/')||value.startsWith('//')||/[\\\u0000-\u0020\u007f]/.test(value))return '';
    const url=new URL(value,location.origin),base=new URL('../chat.php',endpoint);
    const expected={agent:publication.chat_agent_id===0?'system':String(publication.chat_agent_id),conversation_id:String(publication.chat_conversation_id),marketing_plan:String(publication.product_id)};
    return url.origin===location.origin&&url.pathname===base.pathname&&!url.hash&&!url.username&&!url.password&&[...url.searchParams].length===3
      &&Object.entries(expected).every(([key,val])=>url.searchParams.getAll(key).length===1&&url.searchParams.get(key)===val)?url.pathname+url.search:'';
  }
  function creativeMarker(value,publication,context){return value&&value.owner_user_id===context.ownerUserId&&value.agent_id===context.chatAgentId
    &&value.conversation_id===context.chatConversationId&&value.product_id===publication.product_id&&value.source_id===String(publication.source_asset_id)
    &&value.draft_id===publication.draft_key&&value.creative_id===publication.promotion_id&&value.stage==='promotion_outcomes';}
  function reportModel(value,context,endpoint,requested=''){
    const parsed=parseRef(value?.ref),window=value?.window;
    if(value?.contract!==reportContract||!parsed||value.ref!==parsed.ref||requested&&value.ref!==requested||!revision(value.revision)||!coverage.has(value.coverage)
      ||!positive(value.observed_at_utc_ms)||value.observed_at_utc_ms<parsed.cutoff*1000||!tuple(value.publication,context)||value.publication.publication_id!==parsed.publicationId
      ||!text(value.publication.title,240)||!(value.publication.recorded_at_utc_ms===null||positive(value.publication.recorded_at_utc_ms))
      ||!window||window.period!==parsed.period||window.timezone!==parsed.timezone||window.timezone_source!==parsed.timezone_source
      ||window.to_utc_ms!==parsed.cutoff*1000||window.as_of_utc_ms!==window.to_utc_ms||!positive(window.from_utc_ms)||window.from_utc_ms>=window.to_utc_ms
      ||!window.previous||window.previous.to_utc_ms!==window.from_utc_ms||!tools().windowMatches(window,parsed)
      ||!Array.isArray(value.period_options)||value.period_options.length!==3||!Array.isArray(value.metrics)||value.metrics.length!==7)return null;
    const periods=new Set();
    for(const option of value.period_options){const other=parseRef(option?.ref);if(!other||option.ref!==other.ref||option.period!==other.period||periods.has(other.period)
      ||other.publicationId!==parsed.publicationId||other.cutoff!==parsed.cutoff||other.timezone!==parsed.timezone||other.timezone_source!==parsed.timezone_source)return null;periods.add(other.period);}
    const keys=new Set();
    for(const metric of value.metrics){const known=Object.hasOwn(catalog,metric?.key)?catalog[metric.key]:null;
      if(!known||keys.has(metric.key)||metric.group!==known[0]||metric.unit!=='count'||!text(metric.definition,600)||!numberPoint(metric.current)||!numberPoint(metric.previous))return null;
      keys.add(metric.key);const compare=metric.comparison;
      if(compare!==null){if(!compare)return null;const complete=metric.current.coverage==='complete'&&metric.previous.coverage==='complete';
        if(!complete){if(compare.delta!==null||compare.percent!==null)return null;}
        else{const delta=metric.current.value-metric.previous.value;if(compare.delta!==delta||metric.previous.value===0&&compare.percent!==null
          ||metric.previous.value!==0&&(typeof compare.percent!=='number'||!Number.isFinite(compare.percent)||Math.abs(compare.percent-100*delta/metric.previous.value)>0.005001))return null;}}
    }
    for(const [key] of groups)if(!coverage.has(value.groups?.[key]?.coverage)||!knownReason(value.groups[key].reason))return null;
    if(!Array.isArray(value.money)||value.money.length>1000)return null;const currencies=new Set();
    for(const row of value.money){const key=row?.key+':'+row?.currency;if(!['verified_payments','verified_refunds'].includes(row?.key)||!/^[A-Z]{3}$/.test(row.currency||'')||currencies.has(key)
      ||row.amount_unit!=='commerce_cent'||row.scale!==2||row.scale_source!=='vp3.commerce.v800'||!moneyPoint(row.current)||!moneyPoint(row.previous))return null;currencies.add(key);
      if(row.comparison!==null){if(!row.comparison)return null;const delta=row.comparison.delta_minor;
        if(row.current.coverage!=='complete'||row.previous.coverage!=='complete'){if(delta!==null)return null;}
        else if(typeof delta!=='string'||delta!==(BigInt(row.current.value_minor)-BigInt(row.previous.value_minor)).toString())return null;}}
    for(const period of ['current','previous']){const point=value.click_breakdowns?.[period];if(!point||!['complete','partial','unavailable'].includes(point.coverage)||!knownReason(point.reason)
      ||!['profile','profile_agent'].every(key=>point[key]===null||nonnegative(point[key]))||point.coverage==='unavailable'&&[point.profile,point.profile_agent].some(n=>n!==null))return null;}
    const exchange=value.exchanges;if(!exchange||!coverage.has(exchange.coverage)||!knownReason(exchange.reason)||!Array.isArray(exchange.items)||exchange.items.length>20||typeof exchange.has_more!=='boolean')return null;
    if(exchange.coverage==='ready'?(!nonnegative(exchange.total)||exchange.observed_total!==null):exchange.total!==null)return null;
    if(exchange.coverage==='limited'?(exchange.observed_total!==null&&!nonnegative(exchange.observed_total)):exchange.observed_total!==null)return null;
    if(['unavailable','unconfigured'].includes(exchange.coverage)&&exchange.items.length)return null;
    const turns=new Set();for(const row of exchange.items){if(!positive(row.public_conversation_id)||!positive(row.turn_id)||!positive(row.visitor_message_id)||!positive(row.answer_message_id)
      ||!positive(row.visitor_at_utc_ms)||!positive(row.linked_at_utc_ms)||!text(row.question,600)||!text(row.answer_excerpt,240)||!revision(row.revision)||turns.has(row.turn_id)
      ||row.open_selection?.tab!=='conversations'||row.open_selection.publicConversationId!==row.public_conversation_id)return null;turns.add(row.turn_id);}
    for(const key of ['current_plan','exact_creative']){const target=value[key];if(!target||!['ready','unconfigured','unavailable'].includes(target.availability)||!knownReason(target.reason)
      ||!(target.revision===null||revision(target.revision)))return null;
      if(key==='current_plan'){if(target.availability==='ready'?(!revision(target.revision)||!currentPlanUrl(target.url,value.publication,endpoint)):target.url!==null)return null;}
      else if(target.availability==='ready'?(!revision(target.revision)||!creativeMarker(target.marker,value.publication,context)):target.marker!==null)return null;}
    return value;
  }
  function publicationsModel(value,context,endpoint,productId){
    if(!value||!coverage.has(value.coverage)||!knownReason(value.reason)||value.product_id!==productId||!Array.isArray(value.publications)||value.publications.length>20
      ||typeof value.has_more!=='boolean'||!(value.next_cursor===null||text(value.next_cursor,2048))||value.has_more&&!value.next_cursor)return null;
    const ids=new Set();for(const row of value.publications){const destination=source(row,new URL('../chat.php',endpoint).pathname);
      if(!tuple(row,context)||row.product_id!==productId||!text(row.title,240)||!destination||row.publication_id!==destination.publicationId||ids.has(row.publication_id)
        ||!(row.recorded_at_utc_ms===null||positive(row.recorded_at_utc_ms)))return null;ids.add(row.publication_id);}
    if(['unavailable','unconfigured'].includes(value.coverage)&&value.publications.length)return null;return value;
  }
  function pointText(value,money=false,currency=''){
    const exact=money?value.value_minor:value.value,observed=money?value.observed_value_minor:value.observed_value;
    const format=n=>money?tools().ledgerAmount(n,currency):String(n);
    if(value.coverage==='complete')return format(exact);
    if(value.coverage==='partial'&&observed!==null)return 'Observed '+format(observed)+' · partial';
    return 'Unavailable';
  }
  function mount(host,options={}){
    const endpoint=endpointUrl(window.VP3_INITIATIVE_WORKBENCH?.followthroughEndpoint),initial=capture(options.context?.());
    if(!endpoint||!initial||!tools())return null;
    let alive=true,serial=0,pending=null,selection={...options.selection},report=null,listRows=new Map(),cursor='';
    const frame=node('section','','vp3-marketing-followthrough');frame.dataset.marketingFollowthrough='';
    const toolbar=node('div','','vp3-marketing-followthrough-tools'),body=node('div'),notice=node('p','','vp3-marketing-followthrough-notice');notice.setAttribute('role','status');
    const back=button('Back to plans',()=>{if(current())options.onBack?.();});back.dataset.marketingFollowthroughBack='';
    toolbar.append(back);frame.append(toolbar,notice,body);host.replaceChildren(frame);
    const current=()=>alive&&host.isConnected&&sameScope(initial,capture(options.context?.()));
    const say=message=>{if(current())notice.textContent=message;};
    async function read(action,fields){
      if(!current())return null;pending?.abort();const controller=new AbortController(),generation=++serial;pending=controller;
      const captured=initial,url=new URL(endpoint);url.search=new URLSearchParams({action,chat_agent_id:String(captured.chatAgentId),chat_conversation_id:String(captured.chatConversationId),...fields});
      const timeout=setTimeout(()=>controller.abort(),20000),valid=()=>current()&&serial===generation&&!controller.signal.aborted;
      try{const response=await fetch(url.href,{method:'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json','X-VP3-WORKING-ATTENTION':captured.workingAttentionBinding}});
        if(!valid())return null;const result=await response.json().catch(()=>null);if(!valid())return null;
        if(!response.ok||result?.ok!==true)throw Object.assign(new Error([401,403,419].includes(response.status)?'Your session or access changed. Return to plans to check current access.':response.status===409?'This evidence changed. Refresh the exact publication again.':response.status===404?'This publication or its original Chat is unavailable.':'Marketing follow-through could not be verified.'),{status:response.status});
        if(result.contract!==contract||result.action!==action||result.scope?.owner_user_id!==captured.ownerUserId||result.scope.chat_agent_id!==captured.chatAgentId
          ||result.scope.chat_conversation_id!==captured.chatConversationId||result.working_attention_binding!==captured.workingAttentionBinding)throw Object.assign(new Error('The report belongs to another Chat. Return to plans.'),{status:403});
        return result.data;
      }finally{clearTimeout(timeout);if(pending===controller)pending=null;}
    }
    function errorView(error,retry){if(!current())return;report=null;delete body.dataset.marketingFixedRef;
      body.replaceChildren(node('p',error?.name==='AbortError'?'The report check timed out. Try this exact view again.':error.message||'Marketing follow-through could not be verified.'));
      if([401,403,419].includes(error.status))options.onAuthorityChanged?.();else body.append(button('Retry this view',retry));say('Saved plans and publications are unchanged.');}
    function renderPublications(value){
      const heading=node('h3','Choose a publication');heading.tabIndex=-1;body.replaceChildren(heading,node('p','Each publication keeps its original product, creative and Chat. Select the one to inspect.'));
      if(value.coverage!=='ready')body.append(node('p',reason(value.reason)||'Only part of the publication list could be verified.'));
      const list=node('ul','','vp3-marketing-followthrough-publications');
      for(const row of listRows.values()){
        const item=node('li'),label=row.recorded_at_utc_ms?'Tracking recorded '+new Date(row.recorded_at_utc_ms).toISOString().slice(0,10):'Tracking date unavailable';
        item.append(node('h4',row.title||'Recorded publication'),node('p',label),node('small','Publication '+row.publication_id),button('View follow-through',()=>void loadReport({ref:row.ref})));
        item.dataset.marketingPublication=row.publication_id;list.append(item);
      }
      body.append(list);if(!listRows.size)body.append(node('p',value.coverage==='ready'?'No recorded publications for this plan.':'Publication records are unavailable.'));
      if(value.has_more)body.append(button('Load more publications',()=>void loadPublications(value.next_cursor)));
    }
    async function loadPublications(next=''){
      if(!current()||!positive(selection.productId))return;const product=selection.productId,generation=serial+1;
      if(!next){listRows.clear();body.replaceChildren(node('p','Loading recorded publications…'));}say('');
      try{const data=await read('publications',{product_id:String(product),...(next?{cursor:next}:{})});if(!data||!current()||serial!==generation)return;
        const value=publicationsModel(data.publications,initial,endpoint,product);if(!value)throw new Error('The publication list could not be verified.');
        for(const row of value.publications)listRows.set(row.publication_id,row);cursor=value.next_cursor||'';renderPublications(value);
      }catch(error){if(serial===generation)errorView(error,()=>void loadPublications(next));}
    }
    function periodDetails(value){
      const window=value.window,meta=node('div','','vp3-marketing-followthrough-meta');
      meta.append(node('h3',(window.period===1?'Completed day':'Completed '+window.period+' days')+' · '+tools().calendarRange(window.from_utc_ms,window.to_utc_ms,window.timezone)),
        node('p','Previous period: '+tools().calendarRange(window.previous.from_utc_ms,window.previous.to_utc_ms,window.timezone)),node('p','Period timezone: '+window.timezone));
      const details=node('details');details.dataset.marketingPeriodDetails='';details.append(node('summary','Period details'));
      const content=node('div');content.append(node('p','Selected period: '+window.from_local+' to '+window.to_local),node('p','Previous period: '+window.previous.from_local+' to '+window.previous.to_local),
        node('p','The start is included; the end is excluded. These are recorded events for this exact publication, not lifetime totals.'),
        node('p','Timezone source in this link: '+{explicit:'account choice',schedule:'schedule choice',utc_fallback:'UTC fallback'}[window.timezone_source]+'.'),
        node('p','Fixed cutoff: '+new Date(window.as_of_utc_ms).toISOString()),node('p','Evidence checked: '+new Date(value.observed_at_utc_ms).toISOString()),
        node('p','Publication '+value.publication.publication_id));
      if(value.publication.recorded_at_utc_ms)content.append(node('p','Tracking recorded: '+new Date(value.publication.recorded_at_utc_ms).toISOString()));
      const link=node('a','Open this fixed report');const href=new URL('../chat.php',endpoint);
      href.search=new URLSearchParams({agent:initial.chatAgentId===0?'system':String(initial.chatAgentId),conversation_id:String(initial.chatConversationId),marketing_followthrough:value.ref});
      link.href=href.pathname+href.search;link.dataset.marketingFixedLink='';content.append(link);
      details.append(content);meta.append(details);return meta;
    }
    function renderReport(value){
      body.replaceChildren();body.dataset.marketingFixedRef=value.ref;
      if(value.publication.title)body.append(node('h3',value.publication.title));
      body.append(periodDetails(value));const controls=node('div','','vp3-marketing-followthrough-tools');controls.setAttribute('aria-label','Completed period length');
      for(const option of value.period_options){const control=button(option.period===1?'1 day':option.period+' days',()=>void loadReport({ref:option.ref}));control.setAttribute('aria-pressed',String(option.ref===value.ref));controls.append(control);}
      controls.append(button('Refresh this period',()=>void loadReport({ref:value.ref})),button('Latest completed periods',()=>void loadReport({publicationId:value.publication.publication_id,period:value.window.period})));
      body.append(controls);if(value.coverage!=='ready')body.append(node('p',(value.coverage==='limited'?'Partial coverage.':value.coverage==='unconfigured'?'The required reporting records are not configured.':'Reporting evidence is unavailable.')+' Unavailable evidence is not counted as zero.','vp3-marketing-followthrough-coverage'));
      for(const [group,label] of groups){const section=node('section');section.dataset.marketingMetricGroup=group;section.append(node('h4',label));
        if(value.groups[group].coverage!=='ready')section.append(node('p',reason(value.groups[group].reason)||'Only verified observations are available.'));
        const table=node('table'),head=node('thead'),header=node('tr');for(const name of ['Measure','Selected period','Previous period'])header.append(node('th',name));head.append(header);table.append(head);
        const rows=node('tbody');for(const metric of value.metrics.filter(row=>row.group===group)){const row=node('tr'),name=node('th',catalog[metric.key][1]);name.setAttribute('scope','row');
          row.dataset.marketingMetric=metric.key;row.append(name,node('td',pointText(metric.current)),node('td',pointText(metric.previous)));rows.append(row);}
        if(group==='commerce')for(const amount of value.money){const row=node('tr');row.dataset.marketingMoney=amount.key+':'+amount.currency;row.append(node('th',amount.key==='verified_payments'?'Verified payments':'Verified refunds'),node('td',pointText(amount.current,true,amount.currency)),node('td',pointText(amount.previous,true,amount.currency)));rows.append(row);}
        table.append(rows);section.append(table);
        if(group==='commerce')section.append(node('p','Commerce ledger amounts use the recorded two-decimal scale. Currency totals are kept separate.'));
        body.append(section);
      }
      const placements=node('section');placements.append(node('h4','Recorded click placements'));
      for(const [key,label] of [['current','Selected period'],['previous','Previous period']]){const point=value.click_breakdowns[key];placements.append(node('p',label+': '+(point.coverage==='unavailable'?'Unavailable':(point.coverage==='partial'?'Observed subset · ':'')+'Profile '+(point.profile??'unavailable')+'; Profile Agent '+(point.profile_agent??'unavailable'))));}body.append(placements);
      const definitions=node('details');definitions.append(node('summary','What these measures count'));const dl=node('dl');for(const metric of value.metrics)dl.append(node('dt',catalog[metric.key][1]),node('dd',metric.definition));definitions.append(dl);body.append(definitions);
      const exchanges=node('section');exchanges.dataset.marketingExchanges='';exchanges.append(node('h4','Promotion-linked exchanges'),node('p','These saved exchanges explicitly included this promotion. They do not prove a visitor’s intent.'));
      if(value.exchanges.coverage!=='ready')exchanges.append(node('p',reason(value.exchanges.reason)||'Some conversation evidence is unavailable.'));
      for(const row of value.exchanges.items){const item=node('article');item.dataset.marketingExchange=String(row.turn_id);item.append(node('p',row.question),node('p',row.answer_excerpt));
        item.append(button('Open visitor conversation',()=>{if(current()&&options.onConversation?.(row.open_selection)!==true)say('The exact visitor conversation could not be opened. Refresh its source before continuing.');}));exchanges.append(item);}
      if(!value.exchanges.items.length)exchanges.append(node('p',value.exchanges.coverage==='ready'?'No verified promotion-linked exchanges in this period.':'No conversation excerpts are available.'));
      if(value.exchanges.has_more)exchanges.append(node('p','Showing '+value.exchanges.items.length+' verified exchanges. The metric above is not limited to this displayed list.'));
      body.append(exchanges);
      const actions=node('section');actions.append(node('h4','Continue this work'),node('p','The original creative and the current plan are separate saved records.'));
      if(value.exact_creative.availability==='ready')actions.append(button('Review original creative',()=>{if(current()&&options.onCreative?.(value.exact_creative.marker)!==true)say('The original creative could not be opened. Refresh its current source.');}));
      else actions.append(node('p','Original creative: '+reason(value.exact_creative.reason)));
      if(value.current_plan.availability==='ready')actions.append(button('Open current plan',()=>{if(current())options.onCurrentPlan?.(value.publication.product_id);}));
      else actions.append(node('p','Current plan: '+reason(value.current_plan.reason)));body.append(actions);
    }
    async function loadReport(next){
      if(!current())return;const parsed=next.ref?parseRef(next.ref):null;
      if(next.ref&&!parsed||!next.ref&&!token(next.publicationId)){errorView(new Error('Choose an exact publication.'),()=>{});return;}
      selection=parsed?{ref:parsed.ref,productId:selection.productId}:{publicationId:next.publicationId,period:next.period||1,productId:selection.productId};report=null;
      const fields=selection.ref?{ref:selection.ref}:{publication_id:selection.publicationId,period:String(selection.period)},generation=serial+1;
      delete body.dataset.marketingFixedRef;body.replaceChildren(node('p','Checking saved follow-through…'));say('');
      try{const data=await read('report',fields);if(!data||!current()||serial!==generation)return;
        const value=reportModel(data.followthrough,initial,endpoint,selection.ref||'');if(!value||!selection.ref&&value.publication.publication_id!==selection.publicationId)throw new Error('The fixed publication report could not be verified.');
        report=value;selection={ref:value.ref,productId:value.publication.product_id};renderReport(value);
      }catch(error){if(serial===generation)errorView(error,()=>void loadReport(selection));}
    }
    if(selection.ref||selection.publicationId)void loadReport(selection);else void loadPublications();
    return Object.freeze({refresh(){if(current()){if(selection.ref||selection.publicationId)return loadReport(selection);return loadPublications();}},dispose(){if(!alive)return;alive=false;serial++;pending?.abort();pending=null;frame.remove();}});
  }
  function openSource(value){
    const cfg=window.VP3_INITIATIVE_WORKBENCH,endpoint=endpointUrl(cfg?.followthroughEndpoint);if(!endpoint)return false;
    const destination=source(value,new URL('../chat.php',endpoint).pathname),runtime=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
    const bound=runtime?.mediaCommandContext?.();if(!destination||!bound||bound.agentId!==destination.chatAgentId||bound.conversationId!==destination.chatConversationId||bound.isCurrent?.()!==true)return false;
    return window.VP3MediaCommands?.openFollowthrough?.({ref:destination.ref},bound)===true;
  }
  let pendingDestination=null;
  function cancelPendingDestination(){pendingDestination=null;for(const event of ['vp3:chat-context-ready','vp3:media-commands-ready'])window.removeEventListener?.(event,openInitialDestination);}
  function openInitialDestination(){
    const target=pendingDestination;if(!target)return;const chat=window.STONEFELLOW_CHAT,continuity=window.STONEFELLOW_CHAT_CONTINUITY;
    if(!chat||chat.userId!==target.ownerUserId||chat.agentId!==target.chatAgentId||chat.workingAttentionBinding!==target.workingAttentionBinding||window.VP3ParticipantContinuity?.selectedContext?.()){cancelPendingDestination();return;}
    if(!continuity||typeof continuity.marketingFollowthroughDestination!=='function')return;
    const original=continuity.marketingFollowthroughDestination();if(!sameScope(original,target)||original.ref!==target.ref){cancelPendingDestination();return;}
    if(continuity.isReady?.()!==true||continuity.isRestoring?.())return;
    if(continuity.agentId?.()!==target.chatAgentId||continuity.conversationId?.()!==target.chatConversationId){cancelPendingDestination();return;}
    if(!window.VP3MediaCommands?.openFollowthrough)return;
    cancelPendingDestination();openSource(target);
  }
  function initializeDestination(){const raw=window.STONEFELLOW_CHAT?.marketingFollowthroughDestination,cfg=window.VP3_INITIATIVE_WORKBENCH,endpoint=endpointUrl(cfg?.followthroughEndpoint);if(!raw||!endpoint)return;
    const parsed=source(raw,new URL('../chat.php',endpoint).pathname);
    if(!parsed||!capture(raw)||raw.ownerUserId!==Number(cfg.ownerKey)||raw.chatAgentId!==cfg.agentId||raw.chatAgentId!==parsed.chatAgentId||raw.chatConversationId!==parsed.chatConversationId)return;
    pendingDestination=Object.freeze({...raw});for(const event of ['vp3:chat-context-ready','vp3:media-commands-ready'])window.addEventListener(event,openInitialDestination);
    window.addEventListener('stonefellow:chat-context-changing',event=>{if(event.detail?.initialRestore!==true)cancelPendingDestination();});window.addEventListener('pagehide',cancelPendingDestination,{once:true});openInitialDestination();}
  window.VP3MarketingFollowthroughV1=Object.freeze({mount,parseRef,source,openSource,cancelPendingDestination});
  initializeDestination();
})();
