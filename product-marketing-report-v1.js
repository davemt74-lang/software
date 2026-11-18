(() => {
  'use strict';
  if (window.VP3ProductMarketingV1) return;
  const positive=value=>Number.isSafeInteger(value)&&value>0;
  const nonnegative=value=>Number.isSafeInteger(value)&&value>=0;
  const text=(value,limit=600)=>typeof value==='string'&&value.trim()&&value.length<=limit&&!/[\u0000-\u001f\u007f]/.test(value);
  const hex=(value,size)=>typeof value==='string'&&new RegExp('^[a-f0-9]{'+size+'}$').test(value);
  const coverage=new Set(['ready','limited','unavailable','unconfigured']);
  const stages=new Set(['listing_review','marketing_review','photo_needed','design_review','image_starting','image_pending',
    'creative_ready','creative_failed','publication_review','published','paused','listing_unavailable','needs_attention']);
  const catalog={accepted_promotion_clicks:'Accepted promotion clicks',native_promotion_conversations:'Conversations with verified promotion clicks',
    checkout_started_orders:'Orders first opening checkout',verified_paid_orders:'Verified paid orders',
    verified_refunded_orders:'Orders with verified refunds',promotion_associated_exchanges:'Promotion-associated exchanges'};
  const reasons={complete:'',no_observations:'No verified observations in this period.',
    storage_unconfigured:'The required records are not configured.',storage_unavailable:'The required records could not be read.',
    before_clock_activation:'This period starts before complete timing was established.',clock_gap:'Some records do not have verified timing.',
    before_attribution_activation:'This period starts before complete attribution was established.',
    profile_permission_required:'Current Profile conversation access is required to verify these exchanges.',
    source_proof_incomplete:'Only part of the saved source evidence can be verified.',
    receipt_proof_incomplete:'Only part of the saved receipt evidence can be verified.'};
  const stateLabels={current:'Current publication',paused:'Paused publication',historical:'Earlier publication'};
  const node=(tag,value,className)=>{const result=document.createElement(tag);if(value!==undefined)result.textContent=value;if(className)result.className=className;return result;};
  const button=(label,callback)=>{const result=node('button',label);result.type='button';result.addEventListener('click',callback);return result;};
  const zoneFormatters=new Map(),dayStarts=new Map();
  function calendarParts(stamp,zone) {
    let formatter=zoneFormatters.get(zone);
    if(!formatter){formatter=new Intl.DateTimeFormat('en-GB',{timeZone:zone,hourCycle:'h23',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'});zoneFormatters.set(zone,formatter);}
    const fields={};for(const part of formatter.formatToParts(new Date(stamp*1000)))if(part.type!=='literal')fields[part.type]=part.value;
    return {date:fields.year+'-'+fields.month+'-'+fields.day,time:fields.hour+':'+fields.minute+':'+fields.second,
      clock:Date.UTC(Number(fields.year),Number(fields.month)-1,Number(fields.day),Number(fields.hour),Number(fields.minute),Number(fields.second))/1000};
  }
  function dayStart(date,zone) {
    const key=zone+'|'+date;if(dayStarts.has(key))return dayStarts.get(key);
    const nominal=Date.parse(date+'T00:00:00Z')/1000;if(!Number.isSafeInteger(nominal))return null;
    const candidates=[];
    for(let offset=-36*3600;offset<=36*3600;offset+=6*3600){
      const stamp=nominal+offset,parts=calendarParts(stamp,zone),candidate=nominal-(parts.clock-stamp),actual=calendarParts(candidate,zone);
      if(actual.date===date&&actual.time==='00:00:00')candidates.push(candidate);
    }
    let first;
    if(candidates.length)first=Math.min(...candidates);
    else {let low=nominal-36*3600,high=nominal+36*3600;
      while(low<high){const middle=Math.floor((low+high)/2);if(calendarParts(middle,zone).date<date)low=middle+1;else high=middle;}
      first=calendarParts(low,zone).date===date?low:null;
    }
    if(dayStarts.size>256)dayStarts.clear();dayStarts.set(key,first);return first;
  }
  function periodRef(value) {
    if(typeof value!=='string'||value.length>180||value.slice(8).length>155)return null;
    const match=/^profile:analytics:v1:(1|7|30):([1-9][0-9]{8,10}):([esu]):([A-Za-z0-9_-]{1,100})$/.exec(value);
    if(!match)return null;
    const cutoff=Number(match[2]);let zone;
    if(!positive(cutoff)||cutoff<946684800||cutoff>=4133980800||cutoff>Math.floor(Date.now()/1000))return null;
    try {
      zone=atob(match[4].replace(/-/g,'+').replace(/_/g,'/'));
      if(!zone||zone.length>75||!/^[A-Za-z0-9_+./-]+$/.test(zone)
        ||btoa(zone).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'')!==match[4]||match[3]==='u'&&zone!=='UTC'
        ||dayStart(calendarParts(cutoff,zone).date,zone)!==cutoff)return null;
    }catch{return null;}
    return {ref:value,period:Number(match[1]),cutoff,timezone:zone,timezone_source:{e:'explicit',s:'schedule',u:'utc_fallback'}[match[3]]};
  }
  function localUrl(value,base) {
    if(typeof value!=='string'||!value.startsWith('/')||value.startsWith('//')||/[\\\u0000-\u0020\u007f]/.test(value))return null;
    try{const url=new URL(value,location.origin),chat=new URL(base,location.origin);
      return url.origin===location.origin&&chat.origin===location.origin&&!url.username&&!url.password?{url,chat}:null;
    }catch{return null;}
  }
  function destination(href,base) {
    const parsed=localUrl(href,base);if(!parsed)return null;
    const {url,chat}=parsed,params=url.searchParams;
    if(url.pathname!==chat.pathname||url.hash)return null;
    const keys=['agent','conversation_id','marketing_plan'];
    const publication=params.has('marketing_publication')||params.has('marketing_period');
    if(publication)keys.push('marketing_publication','marketing_period');
    if([...params].length!==keys.length||!keys.every(key=>params.getAll(key).length===1))return null;
    const id=value=>/^[1-9][0-9]{0,15}$/.test(value||'')&&positive(Number(value))?Number(value):0;
    const agentText=params.get('agent'),agent=agentText==='system'?0:id(agentText)||-1;
    const cid=id(params.get('conversation_id')),product=id(params.get('marketing_plan'));
    const publicationId=publication?params.get('marketing_publication'):'',period=publication?periodRef(params.get('marketing_period')):null;
    if(agent<0||!cid||!product||publication&&(!hex(publicationId,32)||!period))return null;
    return {href:url.pathname+url.search,agentId:agent,conversationId:cid,productId:product,publicationId,periodRef:period?.ref||''};
  }
  function source(value,base) {
    const target=destination(value?.href||value?.url,base);if(!target||typeof value?.ref!=='string')return null;
    const expected=target.publicationId
      ?'marketing:product-outcome:'+target.publicationId+':'+target.periodRef.slice('profile:analytics:'.length)
      :'marketing:product-plan:'+target.productId;
    if(value.ref!==expected||value.product_id!==undefined&&value.product_id!==target.productId
      ||value.publication_id!==undefined&&value.publication_id!==(target.publicationId||null))return null;
    return {...target,ref:expected};
  }
  function actions(rows,identity,base) {
    if(!Array.isArray(rows)||rows.length>6)return null;
    const result=[];
    for(const row of rows){
      if(!row||!text(row.label,120)||!['url','chat'].includes(row.kind))return null;
      const parsed=localUrl(row.href,base);if(!parsed)return null;
      const {url,chat}=parsed,params=url.searchParams;
      const exact=destination(row.href,base);
      let valid=exact&&exact.productId===identity.product_id&&exact.agentId===identity.agent_id&&exact.conversationId===identity.conversation_id
        &&(!exact.publicationId||exact.publicationId===identity.publication_id&&exact.periodRef===identity.period_ref)&&row.kind==='url';
      if(row.kind==='url'&&url.pathname===chat.pathname.replace(/chat\.php$/,'profile-commerce-products.php')&&!url.hash
        &&[...params].length===1&&params.getAll('workspace_product').length===1&&params.get('workspace_product')===String(identity.product_id))valid=true;
      if(row.kind==='chat'&&url.pathname===chat.pathname&&!url.hash&&[...params].length===3
        &&['agent','conversation_id','message_id'].every(key=>params.getAll(key).length===1)
        &&row.agent_id===identity.agent_id&&row.conversation_id===identity.conversation_id&&positive(row.message_id)
        &&params.get('agent')===(identity.agent_id===0?'system':String(identity.agent_id))
        &&params.get('conversation_id')===String(identity.conversation_id)&&params.get('message_id')===String(row.message_id))valid=true;
      if(!valid)return null;
      result.push({...row,href:url.pathname+url.search});
    }
    return result;
  }
  function planModel(value,options={}) {
    if(!positive(value?.product_id)||!nonnegative(value.agent_id)||!positive(value.conversation_id)||!text(value.product_title,240)
      ||!stages.has(value.stage)||!text(value.stage_label,100)||value.ref!=='marketing:product-plan:'+value.product_id
      ||!hex(value.revision,64)||!coverage.has(value.coverage))return null;
    const target=source({...value,url:value.chat_url},options.chatUrl),links=actions(value.actions,value,options.chatUrl);
    if(!target||target.agentId!==value.agent_id||target.conversationId!==value.conversation_id||!links)return null;
    return {...value,actions:links};
  }
  function point(value,money=false) {
    const amount=n=>n===null||(money?typeof n==='string'&&/^(?:0|[1-9][0-9]{0,79})$/.test(n):nonnegative(n));
    const full=money?'value_minor':'value',subset=money?'observed_value_minor':'observed_value';
    return value&&['complete','partial','unavailable'].includes(value.coverage)&&typeof value.reason==='string'&&/^[a-z][a-z0-9_]{0,79}$/.test(value.reason)
      &&amount(value[full])&&amount(value[subset])&&(value.coverage==='complete'?value[full]!==null&&value[subset]===null:value[full]===null)
      &&(value.coverage!=='unavailable'||value[subset]===null);
  }
  function windowMatches(window,ref) {
    try {
      if(!window||window.period!==ref.period||window.timezone!==ref.timezone||window.timezone_source!==ref.timezone_source
        ||window.as_of_utc_ms!==ref.cutoff*1000||window.to_utc_ms!==ref.cutoff*1000||!window.previous)return false;
      const date=calendarParts(ref.cutoff,ref.timezone).date,shift=days=>new Date(Date.parse(date+'T00:00:00Z')-days*86400000).toISOString().slice(0,10);
      const from=dayStart(shift(ref.period),ref.timezone),previous=dayStart(shift(2*ref.period),ref.timezone);
      if(from===null||previous===null||window.from_utc_ms!==from*1000||window.previous.from_utc_ms!==previous*1000||window.previous.to_utc_ms!==window.from_utc_ms)return false;
      const local=(value,stamp)=>{if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/.test(value)||Date.parse(value)!==stamp)return false;
        const parts=calendarParts(stamp/1000,ref.timezone);return value.slice(0,19)===parts.date+'T'+parts.time;};
      return local(window.from_local,window.from_utc_ms)&&local(window.to_local,window.to_utc_ms)
        &&local(window.previous.from_local,window.previous.from_utc_ms)&&window.previous.to_local===window.from_local;
    }catch{return false;}
  }
  function outcomeModel(value,options={}) {
    const ref=periodRef(value?.period_ref),scope=value?.original_scope;
    if(value?.contract!=='vp3.product-marketing-outcome.v1'||!ref||!hex(value.revision,64)||!coverage.has(value.coverage)
      ||!positive(value.product_id)||!text(value.product_title,240)||!hex(value.publication_id,32)||!hex(value.promotion_id,32)
      ||value.creative_id!==value.promotion_id||!scope||!nonnegative(scope.agent_id)||!positive(scope.conversation_id)
      ||!text(value.source_id,20)||!/^[1-9][0-9]*$/.test(value.source_id)||!positive(Number(value.source_id))||!hex(value.draft_id,64)
      ||!Object.hasOwn(stateLabels,value.publication_status)||value.plan_ref!=='marketing:product-plan:'+value.product_id
      ||value.ref!=='marketing:product-outcome:'+value.publication_id+':'+ref.ref.slice('profile:analytics:'.length)
      ||!positive(value.observed_at_utc_ms)||value.observed_at_utc_ms<ref.cutoff*1000||!windowMatches(value.window,ref)
      ||!Array.isArray(value.metrics)||value.metrics.length!==Object.keys(catalog).length||!Array.isArray(value.money)||value.money.length>1000
      ||!Array.isArray(value.period_options)||value.period_options.length!==3||!Array.isArray(value.placements)||value.placements.length>2)return null;
    if(options.productId!==undefined&&value.product_id!==options.productId||options.publicationId!==undefined&&value.publication_id!==options.publicationId
      ||options.periodRef!==undefined&&value.period_ref!==options.periodRef||options.agentId!==undefined&&scope.agent_id!==options.agentId
      ||options.conversationId!==undefined&&scope.conversation_id!==options.conversationId)return null;
    const periods=new Set();for(const option of value.period_options){const other=periodRef(option?.ref);
      if(!other||option.period!==other.period||periods.has(other.period)||other.cutoff!==ref.cutoff||other.timezone!==ref.timezone||other.timezone_source!==ref.timezone_source)return null;
      periods.add(other.period);
    }
    const keys=new Set();for(const metric of value.metrics){
      if(!Object.hasOwn(catalog,metric?.key)||keys.has(metric.key)||metric.unit!=='count'||!text(metric.definition,800)
        ||!point(metric.current)||!point(metric.previous))return null;
      keys.add(metric.key);const comparison=metric.comparison;
      if(comparison!==null){
        if(!comparison||!['delta','percent'].every(key=>comparison[key]===null||typeof comparison[key]==='number'&&Number.isFinite(comparison[key])))return null;
        if(metric.current.coverage!=='complete'||metric.previous.coverage!=='complete'){
          if(comparison.delta!==null||comparison.percent!==null)return null;
        }else{const delta=metric.current.value-metric.previous.value;
          if(comparison.delta!==delta||metric.previous.value===0&&comparison.percent!==null
            ||metric.previous.value!==0&&(comparison.percent===null||Math.abs(comparison.percent-100*delta/metric.previous.value)>0.005001))return null;
        }
      }
    }
    const currencies=new Set();for(const row of value.money){const key=row?.key+':'+row?.currency;
      if(!['verified_payments','verified_refunds'].includes(row?.key)||!/^[A-Z]{3}$/.test(row.currency||'')||currencies.has(key)
        ||row.amount_unit!=='commerce_cent'||row.scale!==2||row.scale_source!=='vp3.commerce.v800'||!point(row.current,true)||!point(row.previous,true))return null;
      currencies.add(key);if(row.comparison!==null){const delta=row.comparison?.delta_minor;
        if(row.current.coverage!=='complete'||row.previous.coverage!=='complete'){if(delta!==null)return null;}
        else if(typeof delta!=='string'||!/^-?(?:0|[1-9][0-9]{0,79})$/.test(delta)||delta!==(BigInt(row.current.value_minor)-BigInt(row.previous.value_minor)).toString())return null;
      }
    }
    const placements=new Set();for(const row of value.placements){if(!['profile','profile_agent'].includes(row?.placement)||placements.has(row.placement)||!point(row.current)||!point(row.previous))return null;placements.add(row.placement);}
    const associations=value.associations,exchanges=[],exchangeKeys=new Set();
    if(!associations||!['complete','partial','unavailable'].includes(associations.coverage)||!Array.isArray(associations.items)
      ||associations.items.length>4||associations.coverage==='unavailable'&&associations.items.length)return null;
    for(const item of associations.items){
      if(!positive(item?.public_conversation_id)||!positive(item.visitor_message_id)||!positive(item.answer_message_id)||item.answer_message_id<=item.visitor_message_id
        ||!positive(item.at_utc_ms)||!positive(item.reply_at_utc_ms)||item.reply_at_utc_ms<item.at_utc_ms
        ||item.at_utc_ms<value.window.from_utc_ms||item.at_utc_ms>=value.window.to_utc_ms
        ||!text(item.excerpt,600)||!hex(item.evidence_revision,64))return null;
      const side='current',end=value.window.to_utc_ms;
      const key=item.public_conversation_id+':'+item.visitor_message_id+':'+item.answer_message_id;
      if(item.reply_at_utc_ms>=end||exchangeKeys.has(key))return null;exchangeKeys.add(key);
      const base=localUrl(options.chatUrl,options.chatUrl);if(!base)return null;
      const href=base.chat.pathname.replace(/chat\.php$/,'profile-agent.php')+'?tab=inbox&conversation='+item.public_conversation_id;
      if(item.url!==href||item.href!==undefined&&item.href!==href||item.period!==undefined&&item.period!==side)return null;
      exchanges.push({...item,side,href});
    }
    const resume=value.resume;if(!resume||!positive(resume.owner_user_id)||options.ownerId!==undefined&&resume.owner_user_id!==options.ownerId
      ||resume.agent_id!==scope.agent_id||resume.conversation_id!==scope.conversation_id||resume.product_id!==value.product_id
      ||resume.source_id!==value.source_id||resume.draft_id!==value.draft_id||resume.creative_id!==value.creative_id
      ||resume.publication_id!==value.publication_id||resume.stage!=='promotion_outcomes')return null;
    const links=actions(value.actions,{...scope,product_id:value.product_id,publication_id:value.publication_id,period_ref:value.period_ref},options.chatUrl);
    if(!links)return null;
    return {...value,actions:links,associations:{coverage:associations.coverage,items:exchanges}};
  }
  function reviewModel(value,options={}) {
    if(value?.contract!=='vp3.product-marketing-review.v1'||!coverage.has(value.coverage)||!positive(value.observed_at_utc_ms)
      ||!Array.isArray(value.plans)||value.plans.length>12||!Array.isArray(value.publications)||value.publications.length>12)return null;
    if(['unavailable','unconfigured'].includes(value.coverage))return {...value,plans:[],publications:[]};
    const plans=value.plans.map(row=>planModel(row,options)),publications=value.publications.map(row=>outcomeModel(row,options));
    return plans.some(row=>!row)||publications.some(row=>!row)?null:{...value,plans,publications};
  }
  function ledgerAmount(value,currency,signed=false) {
    if(typeof value!=='string'||!/^-?(?:0|[1-9][0-9]{0,79})$/.test(value))return 'Unavailable';
    const negative=value.startsWith('-'),digits=(negative?value.slice(1):value).padStart(3,'0');
    return currency+' '+(negative?'-':signed&&value!=='0'?'+':'')+digits.slice(0,-2).replace(/\B(?=(\d{3})+(?!\d))/g,',')+'.'+digits.slice(-2);
  }
  function pointView(value,currency='') {
    const result=node('div'),full=currency?value.value_minor:value.value,subset=currency?value.observed_value_minor:value.observed_value;
    if(value.coverage==='complete')result.append(node('strong',currency?ledgerAmount(full,currency):String(full)));
    else if(value.coverage==='partial'&&subset!==null)result.append(node('strong',currency?ledgerAmount(subset,currency):String(subset)),node('small','Observed subset'));
    else result.append(node('strong','Unavailable'));
    if(value.coverage!=='complete')result.append(node('small',reasons[value.reason]||'The saved evidence is incomplete.'));
    return result;
  }
  function table(rows,money=false) {
    const result=node('table',undefined,'vp3-product-marketing-table'),head=node('thead'),titles=node('tr');
    for(const label of ['Measure','Selected period','Previous period','Change'])titles.append(node('th',label));
    head.append(titles);const body=node('tbody');
    for(const metric of rows){const row=node('tr');row.dataset.productMarketingMetric=money?metric.key+':'+metric.currency:metric.key;
      const title=money?(metric.key==='verified_payments'?'Verified payments':'Verified refunds')+' · '+metric.currency:catalog[metric.key];
      const name=node('th',title);name.setAttribute('scope','row');row.append(name);
      for(const [key,label] of [['current','Selected period'],['previous','Previous period']]){const cell=node('td');cell.dataset.label=label;cell.append(pointView(metric[key],money?metric.currency:''));row.append(cell);}
      const change=node('td');change.dataset.label='Change';const delta=money?metric.comparison?.delta_minor:metric.comparison?.delta;
      change.append(node('span',delta===null||delta===undefined?'Unavailable':money?ledgerAmount(delta,metric.currency,true):(delta>0?'+':'')+delta));
      if(!money&&delta!==null&&delta!==undefined)change.append(node('small',metric.comparison.percent===null?'No percentage from a zero baseline':(metric.comparison.percent>0?'+':'')+metric.comparison.percent+'%'));
      row.append(change);body.append(row);
    }
    result.append(head,body);return result;
  }
  function appendActions(target,rows,options) {
    const group=node('div',undefined,'vp3-product-marketing-actions');
    for(const action of rows){const link=node('a',action.label);link.href=action.href;
      link.addEventListener('click',event=>{
        if(options.isCurrent?.()===false){event.preventDefault();return;}
        if(options.onAction?.(action,event)===true)event.preventDefault();
      });group.append(link);
    }
    target.append(group);
  }
  function renderOutcome(target,raw,options={}) {
    const value=outcomeModel(raw,options);target.replaceChildren();
    if(!value){target.append(node('p','This publication could not be verified. Open its source again.'));return false;}
    const result=node('section',undefined,'vp3-product-marketing');result.dataset.productMarketingPublication=value.publication_id;result.dataset.productMarketingRef=value.ref;
    result.append(node('h3',value.product_title),node('p',stateLabels[value.publication_status], 'vp3-product-marketing-status'));
    const window=value.window,dates=node('section',undefined,'vp3-product-marketing-period');
    dates.append(node('h4',window.period===1?'Completed day':'Completed '+window.period+' days'),node('p',window.from_local+' to '+window.to_local),
      node('small','Start included; end excluded. Timezone: '+window.timezone+'.'),
      node('small','Timezone source in this link: '+{explicit:'account choice',schedule:'schedule choice',utc_fallback:'UTC fallback'}[window.timezone_source]+'.'),
      node('p','Previous period: '+window.previous.from_local+' to '+window.previous.to_local),
      node('small','Evidence checked: '+new Date(value.observed_at_utc_ms).toISOString()));
    if(options.onPeriod){const choices=node('div',undefined,'vp3-product-marketing-actions');choices.setAttribute('aria-label','Publication reporting period');
      for(const option of value.period_options){const control=button(option.period===1?'1 day':option.period+' days',()=>{if(options.isCurrent?.()!==false)options.onPeriod(option.ref);});control.setAttribute('aria-pressed',String(option.ref===value.period_ref));choices.append(control);}dates.append(choices);}
    result.append(dates);
    if(value.coverage!=='ready')result.append(node('p',value.coverage==='limited'?'Some attribution is incomplete. Observed subsets are labeled below.':'Attribution for this period could not be completely verified.','vp3-product-marketing-coverage'));
    result.append(table(value.metrics));
    const definitions=node('details');definitions.append(node('summary','Definitions and coverage'));const list=node('dl');
    for(const metric of value.metrics)list.append(node('dt',catalog[metric.key]),node('dd',metric.definition));definitions.append(list);result.append(definitions);
    const placements=node('details');placements.append(node('summary','Click placements'));
    for(const row of value.placements){const item=node('section');item.append(node('h4',row.placement==='profile'?'Public Profile':'Profile Agent'),node('small','Selected period'),pointView(row.current),node('small','Previous period'),pointView(row.previous));placements.append(item);}result.append(placements);
    result.append(node('h4','Attributed payments and refunds'),node('p','Amounts are reported separately for each currency.'));
    if(value.money.length)result.append(table(value.money,true));else result.append(node('p','No verified attributed amounts are available for these periods.'));
    const associations=node('section',undefined,'vp3-product-marketing-exchanges');associations.dataset.productMarketingAssociations='';
    associations.append(node('h4','Associated visitor exchanges'),node('p','Saved visitor text from the selected period, with a verified reply showing this exact publication.'));
    if(value.associations.coverage==='unavailable')associations.append(node('p','These exchanges could not be verified with current Profile conversation access.'));
    else{
      if(value.associations.coverage==='partial')associations.append(node('p','Only exchanges with complete saved evidence are shown.'));
      for(const item of value.associations.items){const entry=node('section');entry.dataset.productMarketingExchange=String(item.visitor_message_id);
        entry.append(node('small',(item.side==='current'?'Selected period':'Previous period')+' · '+new Date(item.at_utc_ms).toISOString()),node('blockquote',item.excerpt));
        appendActions(entry,[{kind:'url',label:'Open exact Profile conversation',href:item.href}],options);associations.append(entry);
      }
      if(!value.associations.items.length)associations.append(node('p','No verified associated visitor exchanges are available in the selected period.'));
    }
    result.append(associations);
    result.append(node('h4','Proposed next step'),node('p',text(value.next_step,1000)?value.next_step:'Review this saved publication and its attributed outcomes before deciding whether to revise the promotion.'));
    appendActions(result,value.actions,options);
    if(options.onReview){const control=button('Review saved creative',()=>{if(options.isCurrent?.()!==false)options.onReview(value.resume);});control.dataset.productMarketingReview='';result.append(control);}
    target.append(result);return true;
  }
  function renderReview(target,raw,options={}) {
    const value=reviewModel(raw,options);target.replaceChildren();target.className='vp3-product-marketing';
    target.append(node('h4','Product Marketing'),node('p','Saved product plans and attributable promotion activity.'));
    if(!value||['unavailable','unconfigured'].includes(value.coverage)){
      target.append(node('p',value?.coverage==='unconfigured'?'Product Marketing is not configured for this private Chat.':'Product Marketing could not be verified.'));return false;
    }
    if(value.coverage==='limited')target.append(node('p','Some saved sources could not be fully verified.','vp3-product-marketing-coverage'));
    for(const plan of value.plans){const card=node('section',undefined,'vp3-product-marketing-plan');card.dataset.productMarketingPlan=String(plan.product_id);
      card.append(node('h5',plan.product_title),node('p',plan.stage_label));
      if(text(plan.next_step,1000))card.append(node('p',plan.next_step));appendActions(card,plan.actions,options);target.append(card);}
    for(const publication of value.publications){const card=node('details');card.dataset.productMarketingOutcome=publication.publication_id;
      card.append(node('summary',publication.product_title+' · '+stateLabels[publication.publication_status]));const body=node('div');
      renderOutcome(body,publication,options);card.append(body);target.append(card);}
    if(!value.plans.length&&!value.publications.length)target.append(node('p','No existing product plans or attributable promotion outcomes to show.'));
    return true;
  }
  window.VP3ProductMarketingV1=Object.freeze({periodRef,destination,source,planModel,outcomeModel,reviewModel,renderOutcome,renderReview});
})();
