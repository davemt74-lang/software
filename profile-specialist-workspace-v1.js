(() => {
  'use strict';
  if (window.VP3ProfileWorkspaceV1) return;
  const contract = 'vp3.profile-workspace.v1';
  const tabs = [['overview','Overview'],['conversations','Conversations'],['calls','Calls'],['analytics','Analytics'],['reports','Reports']];
  const positive = value => Number.isSafeInteger(value) && value > 0;
  const nonnegative = value => Number.isSafeInteger(value) && value >= 0;
  const token = value => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value);
  const revision = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
  const plain = (value,max=4000) => typeof value === 'string' && Array.from(value).length <= max ? value : '';
  const node = (tag,text='',className='') => {
    const element = document.createElement(tag);
    element.textContent = text;
    if (className) element.className = className;
    return element;
  };
  const button = (label,action) => {
    const element = node('button',label);element.type='button';
    element.addEventListener('click',action);return element;
  };
  const randomId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('');
  const scopeKey = value => value ? JSON.stringify([value.ownerUserId,value.chatAgentId,value.chatConversationId,value.workingAttentionBinding]) : '';
  function captureScope(scope) {
    const value = scope?.();
    if (!value || value.hasChat !== true || !positive(value.owner) || !nonnegative(value.agent) || !nonnegative(value.cid)
      || typeof value.binding !== 'string' || !value.binding || value.binding.length > 256) return null;
    return Object.freeze({ownerUserId:value.owner,chatAgentId:value.agent,chatConversationId:value.cid,workingAttentionBinding:value.binding});
  }
  function localUrl(value) {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || /[\\\u0000-\u0020\u007f]/.test(value)) return '';
    try { const url=new URL(value,location.origin);return url.origin===location.origin&&!url.hash ? url.pathname+url.search : ''; }
    catch { return ''; }
  }
  const analyticsMetricCatalog={
    image_creations_completed:['images','count','Completed image creations'],
    image_initial_creations:['images','count','Initial creations'],
    image_revisions_completed:['images','count','Completed revisions'],
    image_requests_failed:['images','count','Verified failed requests'],
    visit_records:['events','count','Visit records'],
    recognized_browser_contacts:['events','count','Recognized browser contacts'],
    accepted_visitor_turns:['conversations','count','Accepted visitor requests'],
    active_conversations:['conversations','count','Conversations with an accepted request'],
    new_conversations:['conversations','count','Verified first conversations'],
    owner_takeovers:['conversations','count','Owner takeovers'],
    owner_reply_messages:['conversations','count','Owner reply messages'],
    agent_reply_messages:['conversations','count','Agent reply messages'],
    median_first_reply_seconds:['conversations','seconds','Median first-reply delay'],
    calls_offered:['calls','count','Calls offered'],
    calls_accepted:['calls','count','Calls accepted'],
    calls_finished:['calls','count','Recorded call outcomes'],
    median_call_accept_seconds:['calls','seconds','Median offer-to-accept delay'],
    recorded_product_conversions:['commerce','count','Recorded product conversions'],
    recorded_booking_conversions:['commerce','count','Recorded booking conversions'],
    verified_paid_orders:['commerce','count','Verified paid orders'],
    verified_refunded_orders:['commerce','count','Verified refund-affected orders']
  };
  const analyticsGroups=[['images','Free image creations'],['events','Visits'],['conversations','Conversations'],['calls','Calls'],['commerce','Commerce']];
  const responseMetricCatalog={
    owner_requests_submitted:['count','Questions submitted to the owner'],
    knowledge_gap_requests:['count','Knowledge gaps'],user_response_requests:['count','User responses'],
    service_unavailable_requests:['count','Service failures'],quota_exhausted_requests:['count','Allowance limits'],
    unknown_reason_requests:['count','Original reason unknown'],
    owner_requests_answered:['count','Submitted questions answered'],owner_response_messages:['count','Owner replies answering questions'],
    median_owner_response_seconds:['seconds','Median owner response time'],p90_owner_response_seconds:['seconds','90th percentile owner response time']
  };
  const responseReasons={knowledge_gap:'Knowledge gap',user_responses:'User responses',service_unavailable:'Service unavailable',quota_exhausted:'Allowance limit'};
  const analyticsCoverage=new Set(['ready','limited','unavailable','unconfigured']);
  const analyticsPointCoverage=new Set(['complete','partial','unavailable']);
  const analyticsReasons={
    complete:'',
    no_observations:'No verified observations in this period.',
    before_clock_activation:'This period starts before complete event timing was established.',
    clock_gap:'Some saved events do not have verifiable timing.',
    identity_proof_unavailable:'Some browser identity or disclosure evidence cannot currently be verified.',
    source_proof_incomplete:'Only part of the source evidence can be verified.',
    receipt_proof_incomplete:'Only part of the saved receipt evidence can be verified.',
    storage_unconfigured:'The required records are not configured.',
    storage_unavailable:'The required records could not be read.',
    authority_unavailable:'Access to the required evidence could not be verified.',
    timezone_unavailable:'The period timezone could not be verified.'
  };
  const analyticsZoneFormatters=new Map(),analyticsDayStarts=new Map();
  function analyticsCalendarParts(stamp,zone) {
    let formatter=analyticsZoneFormatters.get(zone);
    if(!formatter){formatter=new Intl.DateTimeFormat('en-GB',{timeZone:zone,hourCycle:'h23',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'});analyticsZoneFormatters.set(zone,formatter);}
    const values={};for(const part of formatter.formatToParts(new Date(stamp*1000)))if(part.type!=='literal')values[part.type]=part.value;
    return {date:values.year+'-'+values.month+'-'+values.day,time:values.hour+':'+values.minute+':'+values.second,
      clock:Date.UTC(Number(values.year),Number(values.month)-1,Number(values.day),Number(values.hour),Number(values.minute),Number(values.second))/1000};
  }
  function analyticsDayStart(date,zone) {
    const key=zone+'|'+date;if(analyticsDayStarts.has(key))return analyticsDayStarts.get(key);
    const nominal=Date.parse(date+'T00:00:00Z')/1000;if(!Number.isSafeInteger(nominal))return null;
    // Prefer the first exact midnight when a timezone repeats it. Sampling
    // offsets also handles a brief midnight followed by a previous-date fold.
    const candidates=[];
    for(let offset=-36*3600;offset<=36*3600;offset+=6*3600){
      const stamp=nominal+offset,parts=analyticsCalendarParts(stamp,zone),candidate=nominal-(parts.clock-stamp),actual=analyticsCalendarParts(candidate,zone);
      if(actual.date===date&&actual.time==='00:00:00')candidates.push(candidate);
    }
    let first;
    if(candidates.length)first=Math.min(...candidates);
    else {
      // A skipped midnight starts at the first available instant of that day.
      let low=nominal-36*3600,high=nominal+36*3600;
      while(low<high){const middle=Math.floor((low+high)/2);if(analyticsCalendarParts(middle,zone).date<date)low=middle+1;else high=middle;}
      first=analyticsCalendarParts(low,zone).date===date?low:null;
    }
    if(analyticsDayStarts.size>256)analyticsDayStarts.clear();analyticsDayStarts.set(key,first);return first;
  }
  function analyticsRef(value) {
    if(typeof value!=='string'||value.length>180||value.slice(8).length>155)return null;
    const match=/^profile:analytics:v1:(1|7|30):([1-9][0-9]{8,10}):(e|s|u):([A-Za-z0-9_-]{1,100})$/.exec(value);
    if(!match)return null;
    const cutoff=Number(match[2]);
    if(!positive(cutoff)||cutoff<946684800||cutoff>=4133980800||cutoff>Math.floor(Date.now()/1000))return null;
    let zone;
    try {
      zone=atob(match[4].replace(/-/g,'+').replace(/_/g,'/'));
      if(!zone||zone.length>75||!/^[A-Za-z0-9_+./-]+$/.test(zone)
        ||btoa(zone).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'')!==match[4]
        ||match[3]==='u'&&zone!=='UTC')return null;
      const local=analyticsCalendarParts(cutoff,zone);
      if(analyticsDayStart(local.date,zone)!==cutoff)return null;
    }catch{return null;}
    return {ref:value,period:Number(match[1]),cutoff,timezone:zone,timezone_source:{e:'explicit',s:'schedule',u:'utc_fallback'}[match[3]]};
  }
  function analyticsSource(source,chatUrl) {
    const raw=source?.href||source?.url;
    const ref=analyticsRef(source?.ref),href=localUrl(raw),base=localUrl(chatUrl);
    if(!ref||!href||!base||typeof raw!=='string'||/[\\\u0000-\u0020\u007f]/.test(raw))return null;
    const url=new URL(href,location.origin);
    if(url.hash||url.username||url.password||url.pathname!==new URL(base,location.origin).pathname||[...url.searchParams].length!==3
      ||!['agent','conversation_id','profile_analytics'].every(key=>url.searchParams.getAll(key).length===1)
      ||url.searchParams.get('profile_analytics')!==ref.ref)return null;
    const agentText=url.searchParams.get('agent'),conversationText=url.searchParams.get('conversation_id');
    const id=value=>/^[1-9][0-9]{0,15}$/.test(value||'')&&positive(Number(value))?Number(value):null;
    const agent=agentText==='system'?0:id(agentText),conversation=id(conversationText);
    return agent===null||conversation===null?null:{...ref,chatAgentId:agent,chatConversationId:conversation,href};
  }
  function analyticsNumberPoint(value,unit) {
    const number=n=>n===null||typeof n==='number'&&Number.isFinite(n)&&n>=0&&(unit==='seconds'||Number.isSafeInteger(n));
    return value&&analyticsPointCoverage.has(value.coverage)&&Object.hasOwn(analyticsReasons,value.reason)
      &&number(value.value)&&number(value.observed_value)
      &&(value.coverage==='complete'?value.observed_value===null:value.value===null)
      &&(value.coverage!=='unavailable'||value.observed_value===null)
      &&(unit==='seconds'||value.coverage!=='complete'||nonnegative(value.value));
  }
  function analyticsMoneyPoint(value) {
    const amount=n=>n===null||typeof n==='string'&&/^(?:0|[1-9][0-9]{0,79})$/.test(n);
    return value&&analyticsPointCoverage.has(value.coverage)&&Object.hasOwn(analyticsReasons,value.reason)
      &&amount(value.value_minor)&&amount(value.observed_value_minor)
      &&(value.coverage==='complete'?value.value_minor!==null&&value.observed_value_minor===null:value.value_minor===null)
      &&(value.coverage!=='unavailable'||value.observed_value_minor===null);
  }
  function analyticsWindowMatches(window,ref) {
    try {
      const endDate=analyticsCalendarParts(ref.cutoff,ref.timezone).date;
      const shift=(date,days)=>new Date(Date.parse(date+'T00:00:00Z')-days*86400000).toISOString().slice(0,10);
      const from=analyticsDayStart(shift(endDate,ref.period),ref.timezone),previous=analyticsDayStart(shift(endDate,2*ref.period),ref.timezone);
      if(from===null||previous===null||window.from_utc_ms!==from*1000||window.previous.from_utc_ms!==previous*1000)return false;
      const local=(text,stamp)=>{
        if(typeof text!=='string'||!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/.test(text)||Date.parse(text)!==stamp)return false;
        const parts=analyticsCalendarParts(stamp/1000,ref.timezone);return text.slice(0,19)===parts.date+'T'+parts.time;
      };
      return local(window.from_local,window.from_utc_ms)&&local(window.to_local,window.to_utc_ms)
        &&local(window.previous.from_local,window.previous.from_utc_ms)&&window.previous.to_local===window.from_local;
    }catch{return false;}
  }
  const analyticsImageFailureCodes=new Set(['provider_access','provider_busy','request_rejected','result_unconfirmed','usage_unconfirmed',
    'image_too_large','storage_unavailable','allowance_unavailable','provider_unavailable','source_changed','other']);
  const analyticsImageLabel=(value,max)=>!!plain(value,max)&&value.trim()===value&&!/[\u0000-\u001f\u007f-\u009f]/.test(value);
  function analyticsImageFailures(rows,total=null) {
    if(!Array.isArray(rows)||rows.length>analyticsImageFailureCodes.size)return false;
    const seen=new Set();let count=0;
    for(const row of rows){
      if(!analyticsImageFailureCodes.has(row?.code)||seen.has(row.code)||!analyticsImageLabel(row.label,160)||!positive(row.count))return false;
      seen.add(row.code);count+=row.count;
    }
    return Number.isSafeInteger(count)&&(total===null||count<=total);
  }
  function analyticsImageUsage(value,metrics) {
    if(value?.contract!=='vp3.profile-image-analytics.v1')return false;
    for(const period of ['current','previous']){
      const side=value[period],point=key=>metrics.find(row=>row.key===key)?.[period];
      const number=key=>{const item=point(key);return item?.coverage==='complete'?item.value:item?.observed_value??null;};
      const completed=number('image_creations_completed'),initial=number('image_initial_creations'),revised=number('image_revisions_completed');
      if(!side||!analyticsPointCoverage.has(side.coverage)||!Object.hasOwn(analyticsReasons,side.reason)
        ||!analyticsPointCoverage.has(side.rankings_coverage)||!analyticsPointCoverage.has(side.failure_coverage)
        ||typeof side.catalogs_truncated!=='boolean'||typeof side.images_truncated!=='boolean'
        ||!analyticsImageFailures(side.failures,number('image_requests_failed'))
        ||side.failure_coverage==='unavailable'&&side.failures.length
        ||side.rankings_coverage!==point('image_creations_completed')?.coverage
        ||side.failure_coverage!==point('image_requests_failed')?.coverage)return false;
      if(completed!==null&&initial!==null&&revised!==null&&initial+revised!==completed)return false;
      for(const [key,idKey,labelKey,limit] of [['top_catalogs','collection_id','name',100],['top_images','asset_id','title',500]]){
        const rows=side[key];if(!Array.isArray(rows)||rows.length>10||side.rankings_coverage==='unavailable'&&rows.length)return false;
        const seen=new Set();let prior=null,sum=0;
        for(const row of rows){
          if(!positive(row?.[idKey])||seen.has(row[idKey])||!analyticsImageLabel(row[labelKey],limit)||!positive(row.completed_count)
            ||completed!==null&&row.completed_count>completed
            ||prior&&(row.completed_count>prior.completed_count||row.completed_count===prior.completed_count&&row[idKey]<prior[idKey]))return false;
          if(key==='top_catalogs'&&(!nonnegative(row.initial_count)||!nonnegative(row.revision_count)||row.initial_count+row.revision_count!==row.completed_count))return false;
          seen.add(row[idKey]);prior=row;sum+=row.completed_count;
        }
        if(key==='top_catalogs'&&completed!==null&&(sum>completed||side.rankings_coverage==='complete'&&!side.catalogs_truncated&&sum!==completed))return false;
      }
      if(side.rankings_coverage==='unavailable'&&(side.catalogs_truncated||side.images_truncated))return false;
    }
    return true;
  }
  function analyticsImageBacklog(backlog) {
    const allowance=backlog.image_allowance,failures=backlog.image_failures;
    const day=Math.floor(backlog.as_of_utc_ms/86400000)*86400000;
    if(!allowance||!['complete','unavailable'].includes(allowance.coverage)||!Object.hasOwn(analyticsReasons,allowance.reason)
      ||allowance.timezone!=='UTC'||allowance.day_start_utc_ms!==day||allowance.resets_at_utc_ms!==day+86400000)return false;
    if(allowance.coverage==='complete'){
      if(typeof allowance.enabled!=='boolean'||!positive(allowance.daily_limit)||allowance.daily_limit>100
        ||!positive(allowance.visitor_daily_limit)||allowance.visitor_daily_limit>20||!nonnegative(allowance.used)
        ||allowance.remaining!==Math.max(0,allowance.daily_limit-allowance.used))return false;
    }else if(allowance.enabled!==null||!['daily_limit','visitor_daily_limit','used','remaining'].every(key=>allowance[key]===null))return false;
    if(!failures||!analyticsPointCoverage.has(failures.coverage)||!Object.hasOwn(analyticsReasons,failures.reason))return false;
    const total=failures.coverage==='complete'?failures.count:failures.observed_count;
    if(failures.coverage==='complete'&&(!nonnegative(failures.count)||failures.observed_count!==null)
      ||failures.coverage==='partial'&&(failures.count!==null||!nonnegative(failures.observed_count))
      ||failures.coverage==='unavailable'&&(failures.count!==null||failures.observed_count!==null))return false;
    return analyticsImageFailures(failures.reasons,total)&&(failures.coverage!=='unavailable'||failures.reasons.length===0);
  }
  function responseSource(row) {
    return row&&['request_id','conversation_id','visitor_message_id'].every(key=>positive(row[key]));
  }
  function responseGroups(value,live=false) {
    if(!Array.isArray(value.questions)||value.questions.length>10||!Array.isArray(value.topics)||value.topics.length>10
      ||typeof value.questions_truncated!=='boolean'||typeof value.topics_truncated!=='boolean')return false;
    const seen=new Set();
    for(const group of value.questions){
      if(!revision(group?.key)||seen.has(group.key)||!analyticsImageLabel(group.question_excerpt,240)||!positive(group.count)
        ||!positive(group.conversation_count)||group.conversation_count>group.count
        ||!Array.isArray(group.samples)||!group.samples.length||group.samples.length>3||!group.samples.every(responseSource))return false;
      seen.add(group.key);
      if(group.suggestions!==undefined){
        if(!live||!Array.isArray(group.suggestions)||group.suggestions.length>3)return false;
        const noteIds=new Set();
        for(const note of group.suggestions){
          if(!positive(note?.knowledge_id)||noteIds.has(note.knowledge_id)||!nonnegative(note.folder_id)||!analyticsImageLabel(note.title,190)
            ||!localUrl(note.url)||!Array.isArray(note.matched_terms)||note.matched_terms.length<2||note.matched_terms.length>6
            ||!note.matched_terms.every(term=>analyticsImageLabel(term,240)))return false;
          const url=new URL(note.url,location.origin);
          if(!url.pathname.endsWith('/knowledge.php')||url.search!=='?folder='+note.folder_id+'&edit='+note.knowledge_id)return false;
          noteIds.add(note.knowledge_id);
        }
      }
    }
    return value.topics.every(topic=>analyticsImageLabel(topic?.keyword,240)&&positive(topic.count));
  }
  function responseAnalytics(activity,backlog,observed) {
    if(activity===undefined&&backlog===undefined)return true;
    if(activity?.contract!=='vp3.profile-response-analytics.v1'||activity.record_scope!=='recorded_owner_requests'
      ||!analyticsPointCoverage.has(activity.coverage)||!Object.hasOwn(analyticsReasons,activity.reason)
      ||!Array.isArray(activity.metrics)||activity.metrics.length!==Object.keys(responseMetricCatalog).length)return false;
    const seen=new Set();
    for(const metric of activity.metrics){
      const known=Object.hasOwn(responseMetricCatalog,metric?.key)?responseMetricCatalog[metric.key]:null;
      if(!known||seen.has(metric.key)||metric.group!=='owner_responses'||metric.unit!==known[0]||!plain(metric.definition,600)
        ||!analyticsNumberPoint(metric.current,metric.unit)||!analyticsNumberPoint(metric.previous,metric.unit))return false;
      const c=metric.comparison,comparable=metric.current.coverage==='complete'&&metric.previous.coverage==='complete'
        &&metric.current.value!==null&&metric.previous.value!==null;
      if(!c||!['delta','percent'].every(key=>c[key]===null||typeof c[key]==='number'&&Number.isFinite(c[key])))return false;
      if(!comparable){if(c.delta!==null||c.percent!==null)return false;}
      else {const delta=metric.current.value-metric.previous.value;
        if(c.delta!==delta||metric.previous.value===0&&c.percent!==null
          ||metric.previous.value!==0&&(c.percent===null||Math.abs(c.percent-100*delta/Math.abs(metric.previous.value))>.005001))return false;}
      seen.add(metric.key);
    }
    const optionalCount=n=>n===null||nonnegative(n),optionalSeconds=n=>n===null||typeof n==='number'&&Number.isFinite(n)&&n>=0&&n<=Number.MAX_SAFE_INTEGER;
    for(const period of ['current','previous']){
      const side=activity[period];
      if(!side||!analyticsPointCoverage.has(side.coverage)||!Object.hasOwn(analyticsReasons,side.reason)||!analyticsPointCoverage.has(side.reason_coverage)
        ||!optionalCount(side.unknown_reason_count)||!optionalCount(side.response_sample_size)||!responseGroups(side))return false;
    }
    if(backlog?.contract!=='vp3.profile-response-analytics.v1'||!analyticsPointCoverage.has(backlog.coverage)||!Object.hasOwn(analyticsReasons,backlog.reason)
      ||!['waiting_count','observed_waiting_count','delegated_count','observed_delegated_count','unknown_count'].every(key=>optionalCount(backlog[key]))
      ||!optionalSeconds(backlog.oldest_wait_seconds)||!optionalSeconds(backlog.median_wait_seconds)||!responseGroups(backlog,true)
      ||!Array.isArray(backlog.oldest)||backlog.oldest.length>10||typeof backlog.oldest_truncated!=='boolean'
      ||!Array.isArray(backlog.reasons)||backlog.reasons.length>4)return false;
    for(const prefix of ['waiting','delegated']){
      const count=backlog[prefix+'_count'],subset=backlog['observed_'+prefix+'_count'];
      if(backlog.coverage==='complete'&&(!nonnegative(count)||subset!==null)
        ||backlog.coverage==='partial'&&(count!==null||!nonnegative(subset))
        ||backlog.coverage==='unavailable'&&(count!==null||subset!==null))return false;
    }
    if(backlog.coverage==='unavailable'&&(backlog.questions.length||backlog.oldest.length||backlog.topics.length))return false;
    const reasons=new Set();
    for(const row of backlog.reasons){if(!Object.hasOwn(responseReasons,row?.reason)||reasons.has(row.reason)||!nonnegative(row.count))return false;reasons.add(row.reason);}
    for(const row of backlog.oldest){
      if(!responseSource(row)||!Object.hasOwn(responseReasons,row.reason)||!analyticsImageLabel(row.question_excerpt,240)
        ||!positive(row.first_submitted_at_utc_ms)||!positive(row.waiting_since_utc_ms)||row.waiting_since_utc_ms<row.first_submitted_at_utc_ms
        ||row.waiting_since_utc_ms>observed||!optionalSeconds(row.wait_seconds)||row.wait_seconds===null
        ||Math.abs(row.wait_seconds-(observed-row.waiting_since_utc_ms)/1000)>.000001)return false;
    }
    const knowledge=backlog.knowledge;
    if(!knowledge||!analyticsPointCoverage.has(knowledge.coverage)||!Object.hasOwn(analyticsReasons,knowledge.reason)||typeof knowledge.candidates_truncated!=='boolean')return false;
    if(knowledge.coverage==='unavailable'&&backlog.questions.some(group=>group.suggestions?.length))return false;
    return true;
  }
  function analyticsPeriodModel(value,requestedRef='') {
    const ref=analyticsRef(value?.ref),window=value?.window;
    if(value?.contract!=='vp3.profile-analytics-period.v1'||!ref||requestedRef&&ref.ref!==requestedRef
      ||!revision(value.revision)||!analyticsCoverage.has(value.coverage)||!positive(value.observed_at_utc_ms)
      ||!window||window.period!==ref.period||window.timezone!==ref.timezone||window.timezone_source!==ref.timezone_source
      ||window.as_of_utc_ms!==ref.cutoff*1000||window.to_utc_ms!==window.as_of_utc_ms
      ||!positive(window.from_utc_ms)||window.from_utc_ms>=window.to_utc_ms
      ||!plain(window.from_local,120)||!plain(window.to_local,120)||!window.previous
      ||!positive(window.previous.from_utc_ms)||window.previous.to_utc_ms!==window.from_utc_ms
      ||window.previous.from_utc_ms>=window.previous.to_utc_ms
      ||!plain(window.previous.from_local,120)||!plain(window.previous.to_local,120)
      ||!Array.isArray(value.period_options)||value.period_options.length!==3
      ||!Array.isArray(value.metrics)||value.metrics.length!==Object.keys(analyticsMetricCatalog).length
      ||!value.groups||value.observed_at_utc_ms<ref.cutoff*1000||!analyticsWindowMatches(window,ref))return null;
    const periods=new Set();
    for(const option of value.period_options){
      const other=analyticsRef(option?.ref);
      if(!other||option.period!==other.period||periods.has(other.period)||other.cutoff!==ref.cutoff
        ||other.timezone!==ref.timezone||other.timezone_source!==ref.timezone_source)return null;
      periods.add(other.period);
    }
    const keys=new Set();
    for(const metric of value.metrics){
      const known=Object.hasOwn(analyticsMetricCatalog,metric?.key)?analyticsMetricCatalog[metric.key]:null;
      if(!known||keys.has(metric.key)||metric.group!==known[0]||metric.unit!==known[1]
        ||!plain(metric.definition,600)||!analyticsNumberPoint(metric.current,metric.unit)||!analyticsNumberPoint(metric.previous,metric.unit))return null;
      keys.add(metric.key);
      const comparison=metric.comparison;
      if(comparison!==null){
        if(!comparison||!['delta','percent'].every(key=>comparison[key]===null||typeof comparison[key]==='number'&&Number.isFinite(comparison[key])))return null;
        const comparable=metric.current.coverage==='complete'&&metric.previous.coverage==='complete'&&metric.current.value!==null&&metric.previous.value!==null;
        if(!comparable){if(comparison.delta!==null||comparison.percent!==null)return null;}
        else {
          const delta=metric.current.value-metric.previous.value;
          if(comparison.delta!==delta||metric.previous.value===0&&comparison.percent!==null)return null;
          if(metric.previous.value!==0&&(comparison.percent===null||Math.abs(comparison.percent-100*delta/Math.abs(metric.previous.value))>0.005001))return null;
        }
      }
    }
    for(const [key] of analyticsGroups){
      if(!analyticsCoverage.has(value.groups[key]?.coverage)||!Object.hasOwn(analyticsReasons,value.groups[key]?.reason))return null;
    }
    if(!Array.isArray(value.money)||value.money.length>1000)return null;
    const currencies=new Set();
    for(const row of value.money){
      const key=row?.key+':'+row?.currency;
      if(!['verified_payments','verified_refunds'].includes(row?.key)||!/^[A-Z]{3}$/.test(row.currency||'')
        ||currencies.has(key)||row.amount_unit!=='commerce_cent'||row.scale!==2||row.scale_source!=='vp3.commerce.v800'
        ||!analyticsMoneyPoint(row.current)||!analyticsMoneyPoint(row.previous))return null;
      currencies.add(key);
      if(row.comparison!==null){
        if(!row.comparison)return null;
        const delta=row.comparison.delta_minor,complete=row.current.coverage==='complete'&&row.previous.coverage==='complete';
        if(!complete){if(delta!==null)return null;}
        else if(typeof delta!=='string'||!/^-?(?:0|[1-9][0-9]{0,79})$/.test(delta)
          ||delta!==(BigInt(row.current.value_minor)-BigInt(row.previous.value_minor)).toString())return null;
      }
    }
    const optionalCount=n=>n===null||nonnegative(n);
    if(!value.first_reply_cohorts||!value.call_breakdowns)return null;
    for(const period of ['current','previous']){
      const cohort=value.first_reply_cohorts[period],calls=value.call_breakdowns[period];
      if(!cohort||!analyticsPointCoverage.has(cohort.coverage)||!Object.hasOwn(analyticsReasons,cohort.reason)
        ||!['eligible','replied','unanswered','unverifiable'].every(key=>optionalCount(cohort[key]))
        ||!calls||!['direct','assisted'].every(key=>optionalCount(calls.accepted?.[key]))
        ||!['ended','declined','cancelled','expired','failed'].every(key=>optionalCount(calls.finished?.[key]))
        ||!['eligible','verified','unverifiable'].every(key=>optionalCount(calls.acceptance_delay?.[key])))return null;
    }
    const backlog=value.backlog;
    if(!backlog||!positive(backlog.as_of_utc_ms)||backlog.as_of_utc_ms!==value.observed_at_utc_ms||!revision(backlog.revision)||!analyticsCoverage.has(backlog.coverage)
      ||!Array.isArray(backlog.metrics)||backlog.metrics.length!==2)return null;
    const backlogKeys=new Set();
    for(const metric of backlog.metrics){
      if(!['current_unresolved_conversations','current_unanswered_visitor_turns'].includes(metric?.key)||backlogKeys.has(metric.key)
        ||!analyticsNumberPoint({...metric,observed_value:metric.observed_value??null},'count'))return null;
      backlogKeys.add(metric.key);
    }
    if(!analyticsImageUsage(value.image_usage,value.metrics)||!analyticsImageBacklog(backlog))return null;
    if(!responseAnalytics(value.response_activity,backlog.owner_requests,value.observed_at_utc_ms))return null;
    return value;
  }
  function analyticsLedgerAmount(value,currency,withSign=false) {
    if(typeof value!=='string'||!/^-?(?:0|[1-9][0-9]*)$/.test(value))return 'Unavailable';
    const negative=value.startsWith('-'),digits=negative?value.slice(1):value;
    const padded=digits.padStart(3,'0'),whole=padded.slice(0,-2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    return currency+' '+(negative?'-':withSign&&digits!=='0'?'+':'')+whole+'.'+padded.slice(-2);
  }
  function analyticsCalendarRange(from,to,timezone) {
    // These are fixed period instants. Format in the saved period zone, never
    // the browser's zone; the final included instant names the last local day.
    const formatter=new Intl.DateTimeFormat('en-GB',{timeZone:timezone,year:'numeric',month:'short',day:'numeric'});
    const first=new Date(from),last=new Date(to-1),start=formatter.format(first),end=formatter.format(last);
    return start===end?start:typeof formatter.formatRange==='function'?formatter.formatRange(first,last):start+' – '+end;
  }
  // Marketing publication inventory needs the same pure fixed-period validation
  // even when the current account does not have Profile workspace access.
  const fixedPeriodTools=Object.freeze({parseRef:analyticsRef,windowMatches:analyticsWindowMatches,
    numberPoint:analyticsNumberPoint,moneyPoint:analyticsMoneyPoint,ledgerAmount:analyticsLedgerAmount,
    calendarRange:analyticsCalendarRange,reasons:Object.freeze({...analyticsReasons})});
  window.VP3FixedPeriodToolsV1=fixedPeriodTools;
  if(document.currentScript?.hasAttribute('data-vp3-period-tools-only'))return;
  function normalizeSelection(value={}) {
    const tab=tabs.some(([key])=>key===value.tab)?value.tab:'overview';
    const publicConversationId=positive(value.publicConversationId)?value.publicConversationId:0;
    const callId=positive(value.callId)?value.callId:0;
    const callView=value.callView==='history'?'history':'current';
    const analyticsView=value.analyticsView==='traffic'?'traffic':'periods';
    const analyticsPeriodRef=analyticsRef(value.analyticsRef)?.ref||'';
    return {tab,publicConversationId,callId,callView,analyticsView,analyticsRef:analyticsPeriodRef};
  }
  const currentCallStates=new Set(['offered','preparing','ready','active','ending']);
  const terminalCallStates=new Set(['ended','declined','cancelled','expired','failed']);
  const outcomeSections=[['analysis','Saved analysis'],['followups','Reviewed follow-ups'],['research','Saved research'],['transcript','Saved transcript']];
  const outcomeAvailability=new Set(['ready','empty','unavailable','forbidden','not_applicable']);
  const callNavigationKey=context=>context?`vp3.profile.calls.v1:${context.ownerUserId}:${context.chatAgentId}:${context.chatConversationId}`:'';
  function savedCallSelection(context) {
    try {
      const value=JSON.parse(window.sessionStorage?.getItem(callNavigationKey(context))||'null');
      if (!value || !['current','history'].includes(value.callView) || !nonnegative(value.callId)||!nonnegative(value.publicConversationId)
        || Boolean(value.callId)!==Boolean(value.publicConversationId)) return null;
      return {tab:'calls',callView:value.callView,callId:value.callId,publicConversationId:value.publicConversationId};
    } catch {return null;}
  }
  function callModel(value) {
    if (!value||!positive(value.call_id)||!positive(value.public_conversation_id)||!positive(value.revision)
      || !['audio','video'].includes(value.mode)||typeof value.assisted!=='boolean'||typeof value.activity_available!=='boolean'
      || !plain(value.visitor_label,240)||!plain(value.state_label,320)||!positive(value.created_at_ms)
      || (value.accepted_at_ms!==null&&!positive(value.accepted_at_ms))||(value.ended_at_ms!==null&&!positive(value.ended_at_ms))) return null;
    if (value.detail_kind==='current'&&!currentCallStates.has(value.state)
      || value.detail_kind==='outcome'&&(!terminalCallStates.has(value.state)||value.state==='ended'&&!positive(value.ended_at_ms))
      || !['current','outcome'].includes(value.detail_kind)) return null;
    return value;
  }
  function callListModel(value,filter) {
    if (!value||value.filter!==filter||!Array.isArray(value.calls)||value.calls.length>20
      || !['ready','limited','unavailable','unconfigured'].includes(value.coverage)||typeof value.has_more!=='boolean'
      || value.next_cursor!==null&&(typeof value.next_cursor!=='string'||!value.next_cursor||value.next_cursor.length>2000)
      || value.has_more!==Boolean(value.next_cursor)) return null;
    const ids=new Set();
    for (const call of value.calls) {
      if (!callModel(call)||ids.has(call.call_id)||call.detail_kind!==(filter==='history'?'outcome':'current')) return null;
      ids.add(call.call_id);
    }
    if (value.selected!==null&&value.selected!==undefined&&!callModel(value.selected)) return null;
    return value;
  }
  function validEnvelope(value,context,action) {
    return value?.ok===true && value.contract===contract && value.action===action
      && value.scope?.owner_user_id===context.ownerUserId && value.scope?.chat_agent_id===context.chatAgentId
      && value.scope?.chat_conversation_id===context.chatConversationId
      && value.working_attention_binding===context.workingAttentionBinding
      && typeof value.observed_at==='string' && value.data && typeof value.data==='object' && !Array.isArray(value.data);
  }
  function conversationPage(value) {
    if (!value || !['ready','limited','unavailable','unconfigured'].includes(value.coverage)
      || !Array.isArray(value.items) || value.items.length>50 || typeof value.has_more!=='boolean'
      || (value.next_cursor!==null && value.next_cursor!==undefined && (typeof value.next_cursor!=='string'||value.next_cursor.length>1000))) return null;
    const ids=new Set();
    for (const item of value.items) {
      if (!item || !positive(item.public_conversation_id) || ids.has(item.public_conversation_id)
        || !plain(item.visitor_label,240) || typeof item.identity_disclosed!=='boolean'
        || typeof item.needs_owner!=='boolean' || !['open','owner_joined','resolved'].includes(item.status)
        || typeof item.latest_summary!=='string' || item.latest_summary.length>32000) return null;
      ids.add(item.public_conversation_id);
    }
    return value;
  }
  function conversationView(value,id) {
    if (value?.public_conversation_id!==id || !value.conversation || !revision(value.conversation.source_revision)
      || !value.messages || !Array.isArray(value.messages.items) || value.messages.items.length>100
      || typeof value.messages.has_more!=='boolean') return null;
    const ids=new Set();
    for (const message of value.messages.items) {
      if (!positive(message?.message_id) || ids.has(message.message_id) || !['visitor','owner','agent'].includes(message.sender_type)
        || typeof message.text!=='string' || message.text.length>32000 || typeof message.created_at!=='string') return null;
      ids.add(message.message_id);
    }
    const turn=value.current_turn;
    if (turn!==null && turn!==undefined && (!positive(turn.turn_id) || !positive(turn.revision)
      || ['can_claim','can_reply','can_delegate','can_resolve'].some(key=>typeof turn[key]!=='boolean'))) return null;
    return value;
  }
  function draftModel(value,id) {
    if (!value || value.public_conversation_id!==id || !token(value.prepare_request_id) || value.draft_id!==value.prepare_request_id
      || !['queued','running','ready','uncertain','failed','stale','sent'].includes(value.state)
      || typeof value.text!=='string' || value.text.length>16000 || typeof value.current!=='boolean'
      || typeof value.can_regenerate!=='boolean' || !value.source || !positive(value.source.turn_id)
      || !positive(value.source.revision) || !revision(value.source.source_revision)) return null;
    return value;
  }
  const savedScopes=new Map();
  const attemptListeners=new Set();
  let savedAccount='';
  const textSource=value=>value?{turnId:value.current_turn?.turn_id||0,revision:value.current_turn?.revision||0,sourceRevision:value.conversation.source_revision}:null;
  const sameTextSource=(a,b)=>a!==null&&b!==null&&JSON.stringify(a)===JSON.stringify(b);
  const unsettledPreparation=saved=>saved.prepareAttempt&&['pending','queued','running','uncertain'].includes(saved.prepareAttempt.state);
  function scopeDrafts(context) {
    const account=JSON.stringify([context.ownerUserId,context.workingAttentionBinding]);
    if (account!==savedAccount) { savedScopes.clear();savedAccount=account; }
    const key=scopeKey(context);
    if (!savedScopes.has(key)) savedScopes.set(key,new Map());
    return savedScopes.get(key);
  }
  function errorMessage(error) {
    return [401,403,419].includes(error?.status) ? 'This Chat’s Profile access could not be verified.'
      : plain(error?.message,500) || 'This Profile view could not be verified. Try again.';
  }
  function mountWorkspace(host,{scope,readReports,renderReport,onClose,selection:initialSelection={}}={}) {
    const cfg=window.VP3_PROFILE_WORKSPACE_V1||{};
    const endpoint=localUrl(cfg.endpoint);
    if (!host || !endpoint || !plain(cfg.csrf,512)) return null;
    let alive=true,generation=0,selection=normalizeSelection(initialSelection),analyticsView=null,callActivity=null;
    let selectedData=null,threadData=null,threadEntry=null,conversations=null,callPage=null;
    const analyticsSelection={analyticsView:selection.analyticsView,analyticsRef:selection.analyticsRef};
    let callListHost=null,callListFooter=null,callListNotice=null,callDetailHost=null,callDetailKey='',callOutcome=null,callPageBusy=false,callDetailEpoch=0;
    const outcomeHosts=new Map(),outcomeBusy=new Set();
    const requests=new Map();
    const initial=captureScope(scope);
    const initialKey=scopeKey(initial);
    function selected(value) {
      if(value.tab==='analytics')value={...analyticsSelection,...value};
      return normalizeSelection(value.tab==='calls'&&!['callId','publicConversationId','callView'].some(key=>Object.hasOwn(value,key))
        ? savedCallSelection(initial)||value : value);
    }
    selection=selected(initialSelection);
    function rememberCalls() {
      if (!currentScope()||selection.tab!=='calls')return;
      try {window.sessionStorage?.setItem(callNavigationKey(initial),JSON.stringify({callId:selection.callId,publicConversationId:selection.publicConversationId,callView:selection.callView}));}catch {}
    }
    const drafts=initial?scopeDrafts(initial):new Map();
    const root=node('section','','vp3-profile-workspace');root.dataset.profileWorkspace='';
    const tablist=node('nav','','vp3-profile-tabs');tablist.setAttribute('role','tablist');tablist.setAttribute('aria-label','Profile views');
    const notice=node('p','','vp3-profile-notice');notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');
    const content=node('section','','vp3-profile-content');content.id='vp3ProfileWorkspacePanel';content.setAttribute('role','tabpanel');
    const controls=new Map();
    const currentScope=()=>{
      const current=captureScope(scope);
      return alive && scopeKey(current)===initialKey ? current : null;
    };
    const current=(mine)=>alive&&mine===generation&&currentScope()!==null&&root.isConnected;
    const say=message=>{notice.textContent=message;};
    function entry(id) {
      if (!drafts.has(id)) drafts.set(id,{text:'',textSource:null,linkedPrepareId:'',edited:false,instruction:'',draft:null,draftStatus:'unknown',prepareAttempt:null,actionAttempt:null,notice:''});
      return drafts.get(id);
    }
    function rememberInput() {
      if (!threadEntry) return;
      const input=content.querySelector('[data-profile-reply-body]');
      if (input) {
        if (input.value&&!threadEntry.textSource) threadEntry.textSource=textSource(threadData);
        threadEntry.text=input.value;
        if (!input.value) {threadEntry.textSource=null;threadEntry.linkedPrepareId='';}
      }
      const instruction=content.querySelector('[data-profile-draft-instruction]');
      if (instruction) threadEntry.instruction=instruction.value;
    }
    function abortReads() { for (const [controller,mutation] of requests) if (!mutation) controller.abort(); }
    function disposeTab() {
      analyticsView?.dispose();analyticsView=null;
      callActivity?.dispose();callActivity=null;
    }
    function statusError(error,mine=generation) {
      if (!current(mine)) return;
      disposeTab();
      selectedData=threadData=null;
      content.replaceChildren(node('p',errorMessage(error),'vp3-profile-empty'));
      if ([401,403,419].includes(error?.status)) content.append(button('Reload Chat',()=>location.reload()));
      else content.append(button('Retry',()=>void refresh()));
      say('');
    }
    async function request(action,input={},options={}) {
      const context=options.context||currentScope();
      if (!context || scopeKey(context)!==initialKey || !currentScope()) throw Object.assign(new Error('Profile context changed.'),{name:'AbortError'});
      const controller=new AbortController(),mutation=options.method==='POST';requests.set(controller,mutation);
      const relay=()=>controller.abort();
      if (options.signal?.aborted) controller.abort();else options.signal?.addEventListener('abort',relay,{once:true});
      const timer=setTimeout(()=>controller.abort(),action==='draft_prepare'?75000:mutation?45000:15000);
      const payload={...input,action,chat_agent_id:context.chatAgentId,chat_conversation_id:context.chatConversationId};
      const url=new URL(endpoint,location.origin);
      const settings={method:mutation?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal,
        headers:{'X-VP3-Working-Attention':context.workingAttentionBinding}};
      if (mutation) { settings.headers['Content-Type']='application/json';settings.body=JSON.stringify({...payload,csrf_token:cfg.csrf}); }
      else for (const [key,value] of Object.entries(payload)) if (value!==undefined&&value!==null) url.searchParams.set(key,String(value));
      try {
        const response=await fetch(url,settings);
        const value=await response.json().catch(()=>null);
        if (!currentScope() || controller.signal.aborted) throw Object.assign(new Error('Profile context changed.'),{name:'AbortError'});
        if (!response.ok || value?.ok!==true) throw Object.assign(new Error(plain(value?.error,500)||'This Profile request could not be verified.'),{status:response.status,code:plain(value?.code,80)});
        if (!validEnvelope(value,context,action)) throw new Error('The Profile response did not match this Chat.');
        if (positive(input.public_conversation_id) && value.data.public_conversation_id!==undefined
          && value.data.public_conversation_id!==input.public_conversation_id) throw new Error('The response did not match the selected conversation.');
        return value;
      } finally {
        clearTimeout(timer);options.signal?.removeEventListener('abort',relay);requests.delete(controller);
      }
    }
    function heading(title,description='') {
      const head=node('div','','vp3-profile-section-heading');head.append(node('h3',title));
      if (description) head.append(node('p',description));
      return head;
    }
    function dateText(value) {
      // Database wall-clock timestamps have no implied browser timezone.
      return plain(value,80).replace('T',' ').replace(/Z$/,' UTC');
    }
    function renderConversationRows(page,target,{compact=false}={}) {
      const list=node('div','','vp3-profile-conversations');
      if (page.coverage==='unavailable') list.append(node('p','Conversation activity could not be verified.','vp3-profile-empty'));
      else if (!page.items.length) list.append(node('p','No saved Profile conversations in this view.','vp3-profile-empty'));
      for (const row of page.items.slice(0,compact?4:page.items.length)) {
        const item=node('article','','vp3-profile-conversation');item.dataset.profileConversation=String(row.public_conversation_id);
        const title=node('strong',row.visitor_label);
        const identity=node('small',row.identity_disclosed?'Shared visitor identity':'Visitor identity is private');
        const state=node('span',row.needs_owner?'Needs your attention':row.status==='resolved'?'Resolved':row.mode==='owner'?'Owner responding':'Profile conversation','vp3-profile-state');
        const copy=node('div');copy.append(title,identity,node('p',plain(row.latest_summary,32000)||'No saved message summary.'),node('small',dateText(row.last_message_at)));
        const open=button('Open conversation',()=>select({tab:'conversations',publicConversationId:row.public_conversation_id}));
        item.append(copy,state,open);list.append(item);
      }
      target.append(list);
      if (page.has_more) target.append(node('p',compact?'More conversations are available in Conversations.':'More saved conversations are available.','vp3-profile-note'));
    }
    async function loadOverview(mine) {
      const result=await request('overview');if (!current(mine)) return;
      const value=result.data;
      const page=conversationPage(value.conversations);
      if (!['ready','save_chat_required'].includes(value.status) || !page) throw new Error('The Profile overview could not be verified.');
      selectedData=value;content.replaceChildren(heading('Overview','Current Profile activity for this private Chat.'));
      if (value.status==='save_chat_required') { content.append(node('p','Save this Chat to open Profile conversations, calls and reports.','vp3-profile-empty'));return; }
      const profile=value.profile;
      if (profile!==null && (!profile || typeof profile.is_public!=='boolean' || typeof profile.enabled!=='boolean')) throw new Error('The Profile service status could not be verified.');
      const service=node('section','','vp3-profile-service');
      service.append(node('strong',profile===null?'Profile is not configured':profile.is_public&&profile.enabled?'Public Profile Agent enabled':!profile.is_public?'Public Profile is private':'Public Profile Agent is off'));
      service.append(node('p',profile?.is_public&&profile.enabled
        ? 'Visitor conversations and current follow-ups are available below.'
        : 'Saved conversations and permitted analytics remain available. Public visibility and Agent settings stay in Profile settings.'));
      const profileUrl=localUrl(cfg.profileUrl);
      if (profileUrl) { const link=node('a','Open Profile settings');link.href=profileUrl+'?tab=agent';service.append(link); }
      content.append(service);
      const recent=node('section');recent.append(heading('Recent conversations'));
      renderConversationRows(page,recent,{compact:true});
      recent.append(button('View conversations',()=>select({tab:'conversations'})));content.append(recent);
      if (value.report && typeof renderReport==='function') {
        const report=node('section','','vp3-profile-overview-report');
        renderReport(report,{report:value.report,observedAt:result.observed_at},{full:false,onSelect:selectSource});
        report.append(button('Open full report',()=>select({tab:'reports'})));content.append(report);
      }
    }
    async function loadConversations(mine,cursor='') {
      const result=await request('conversations',{filter:'all',...(cursor?{cursor}:{})});if (!current(mine)) return;
      const page=conversationPage(result.data);if (!page) throw new Error('The conversation list could not be verified.');
      if (cursor && conversations) {
        const existing=new Map(conversations.items.map(row=>[row.public_conversation_id,row]));
        for (const row of page.items) existing.set(row.public_conversation_id,row);
        conversations={...page,items:[...existing.values()]};
      } else conversations=page;
      content.replaceChildren(heading('Conversations','Recent visitor conversations, including resolved and historical threads.'));
      renderConversationRows(conversations,content);
      if (page.has_more&&page.next_cursor) {
        const more=button('Load more conversations',async()=>{more.disabled=true;try{await loadConversations(mine,page.next_cursor);}catch(error){statusError(error,mine);}});
        content.append(more);
      }
    }
    function draftReason(reason) {
      return ({no_accepted_turn:'This historical conversation has no current accepted visitor turn. You can still reply manually.',
        owner_claim_required:'Take over this conversation before preparing a private reply draft.',
        owner_takeover_required:'Take over this conversation before preparing a private reply draft.',
        source_unavailable:'The current visitor source could not be verified for private assistance.',
        draft_storage_unavailable:'Saved private draft storage is unavailable. Manual replies remain governed by the current conversation.',
        reply_unavailable:'Private assistance is unavailable while replies are closed for this visitor turn.',
        source_changed:'The visitor conversation changed. Review the current messages before preparing another draft.',
        unavailable:'Private reply assistance is unavailable for this conversation.'})[reason] || 'Private reply assistance is not available for the current turn.';
    }
    function applyDraft(saved,draft) {
      saved.draft=draft;
      if (saved.prepareAttempt?.payload.prepare_request_id===draft.prepare_request_id) saved.prepareAttempt.state=draft.state;
      if (!saved.edited && !saved.text && draft.state==='ready'&&draft.current) {
        saved.text=draft.text;saved.linkedPrepareId=draft.prepare_request_id;
        saved.textSource={turnId:draft.source.turn_id,revision:draft.source.revision,sourceRevision:draft.source.source_revision};
      }
      if (!saved.prepareAttempt && draft.prepare && typeof draft.prepare==='object') {
        saved.prepareAttempt={payload:{...draft.prepare},pending:false,state:draft.state};
      } else if (!saved.prepareAttempt && typeof draft.instruction==='string') {
        saved.prepareAttempt={payload:{prepare_request_id:draft.prepare_request_id,public_conversation_id:draft.public_conversation_id,
          turn_id:draft.source.turn_id,revision:draft.source.revision,source_revision:draft.source.source_revision,instruction:draft.instruction,
          ...(token(draft.supersedes_request_id)?{supersedes_request_id:draft.supersedes_request_id}:{})},pending:false,state:draft.state};
      }
    }
    async function recoverDraft(id,mine,saved) {
      const result=await request('draft_status',{public_conversation_id:id,latest:1});
      if (!current(mine)||selection.publicConversationId!==id) return;
      if (result.data.public_conversation_id!==id) throw new Error('The saved draft target could not be verified.');
      if (result.data.draft!==null) {
        const draft=draftModel(result.data.draft,id);if (!draft) throw new Error('The saved reply draft could not be verified.');
        applyDraft(saved,draft);
      }
      saved.draftStatus='verified';
      renderThread(threadData,saved);
    }
    async function loadConversation(mine,id,before=0) {
      const result=await request('conversation',{public_conversation_id:id,...(before?{before_message_id:before}:{})});
      if (!current(mine)||selection.publicConversationId!==id) return;
      const value=conversationView(result.data,id);if (!value) throw new Error('The selected conversation could not be verified.');
      const saved=entry(id);
      if (before&&threadData) {
        const messages=new Map([...value.messages.items,...threadData.messages.items].map(row=>[row.message_id,row]));
        value.messages={...value.messages,items:[...messages.values()].sort((a,b)=>a.message_id-b.message_id)};
      }
      threadData=value;threadEntry=saved;selectedData=value;renderThread(value,saved);
      if (!before) {
        try { await recoverDraft(id,mine,saved); }
        catch(error) {
          if (current(mine)&&selection.publicConversationId===id) {
            if ([401,403,419].includes(error.status)) throw error;
            saved.draftStatus='unavailable';saved.notice='Saved reply draft status could not be verified. Check the draft before requesting another.';renderThread(value,saved);
          }
        }
      }
    }
    function renderThread(value,saved) {
      if (!value || selection.tab!=='conversations'||selection.publicConversationId!==value.public_conversation_id||!currentScope()) return;
      const previousInput=content.querySelector('[data-profile-reply-body]');
      const focus=Boolean(previousInput&&previousInput===document.activeElement);
      const selectionStart=focus?document.activeElement.selectionStart:0,selectionEnd=focus?document.activeElement.selectionEnd:0;
      const id=value.public_conversation_id,turn=value.current_turn;
      const busy=saved.actionAttempt?.pending||saved.prepareAttempt?.pending;
      const uncertain=saved.actionAttempt?.state==='uncertain';
      const textIsStale=()=>Boolean(saved.text&&(!sameTextSource(saved.textSource,textSource(value))
        || saved.linkedPrepareId&&(!saved.draft?.current||saved.draft.state!=='ready'||saved.draft.prepare_request_id!==saved.linkedPrepareId)));
      const staleText=textIsStale();
      content.replaceChildren();
      content.append(button('Back to conversations',()=>select({tab:'conversations'})));
      const who=plain(value.conversation.visitor_label,240)||'Visitor conversation';
      content.append(heading(who,'Replies here are sent to this visitor as the Profile owner.'));
      const messages=node('ol','','vp3-profile-messages');messages.setAttribute('aria-label','Conversation messages');
      for (const message of value.messages.items) {
        const row=node('li','','vp3-profile-message');row.dataset.sender=message.sender_type;row.dataset.messageId=String(message.message_id);
        row.append(node('strong',message.sender_type==='owner'?'You':message.sender_type==='visitor'?'Visitor':'Profile Agent'),node('p',message.text),node('small',dateText(message.created_at)));
        const attachment=message.attachment;
        const href=localUrl(attachment?.url);
        if (href && plain(attachment.name,255)) {
          const link=node('a','Download '+attachment.name);link.href=href;
          link.addEventListener('click',event=>{if(!currentScope()||selection.publicConversationId!==id)event.preventDefault();});row.append(link);
        }
        const edit=message.image_edit;
        const editUrl=localUrl(edit?.url),downloadUrl=localUrl(edit?.download_url);
        if (editUrl&&['input','result'].includes(edit?.kind)) {
          const image=node('img','','vp3-profile-edit-image');image.src=editUrl;image.alt=edit.kind==='result'?'Edited visitor photo':'Original visitor photo';image.loading='lazy';
          const link=node('a');link.href=editUrl;link.target='_blank';link.rel='noopener';link.append(image);
          link.addEventListener('click',event=>{if(!currentScope()||selection.publicConversationId!==id)event.preventDefault();});row.append(link);
          if(downloadUrl){const download=node('a',edit.kind==='result'?'Download edited photo':'Download original photo');download.href=downloadUrl;download.download='';download.addEventListener('click',event=>{if(!currentScope()||selection.publicConversationId!==id)event.preventDefault();});row.append(download);}
          if(edit.kind==='result'){
            const credit=edit.source_credit,creditLabel=(value,limit)=>plain(value,limit).replace(/[\p{Cc}\p{Cf}\p{Z}]+/gu,' ').trim();
            const owner=creditLabel(credit?.owner_name,200),catalog=creditLabel(credit?.catalog_name,100);
            const references=Array.isArray(credit?.references)&&credit.references.length<=3?credit.references.map(reference=>creditLabel(reference?.title,500)):[];
            const box=node('section','','vp3-profile-image-credit');box.dataset.profileImageSourceCredit='';box.setAttribute('aria-label','Image source credit');
            if(credit?.contract==='vp3.profile-image-credit.v1'&&credit.price_cents===0&&owner&&catalog&&references.length&&references.every(Boolean)){
              box.append(node('strong','Reference images used'),node('p','Profile owner: '+owner),node('p','Collection: '+catalog));
              const sources=node('ul');sources.setAttribute('aria-label','Selected reference images');for(const title of references)sources.append(node('li',title));
              box.append(sources,node('p','Free use · $0.00'));
            }else box.append(node('p','Reference details are unavailable for this saved edit.'));
            row.append(box);
          }
        }
        messages.append(row);
      }
      if (value.messages.has_more&&positive(value.messages.next_before_message_id)) {
        const more=button('Load older messages',async()=>{more.disabled=true;try{await loadConversation(generation,id,value.messages.next_before_message_id);}catch(error){statusError(error);}});
        content.append(more);
      }
      if (!value.messages.items.length) messages.append(node('li','No saved messages.','vp3-profile-empty'));
      content.append(messages);
      const responder={owner:'You are responding.',agent:'The Profile Agent is responding.',waiting:'Waiting for an owner response.',none:'No current responder.'};
      const state=node('p',turn?(responder[turn.responder]||'Responder status is unavailable.'):'Historical conversation.','vp3-profile-note');
      content.append(state);
      const actions=node('div','','vp3-profile-actions');
      for (const [operation,label,key] of [['claim','Take over','can_claim'],['delegate','Return to Agent','can_delegate'],['resolve','Resolve conversation','can_resolve']]) {
        if (turn?.[key]===true) {
          const control=button(label,()=>void performAction(operation,value,saved));control.disabled=Boolean(busy||uncertain);actions.append(control);
        }
      }
      content.append(actions);
      const draftBox=node('section','','vp3-profile-draft');draftBox.dataset.profileDraft='';draftBox.append(heading('Private reply draft','Review and edit a draft before sending it to this visitor.'));
      const assistance=value.assistance||{};
      const canPrepare=assistance.can_prepare===true;
      const instructionLabel=node('label','What should the draft cover?');
      const instruction=node('textarea');instruction.rows=2;instruction.maxLength=1000;instruction.dataset.profileDraftInstruction='';instruction.value=saved.instruction;
      instruction.placeholder='Optional guidance for this reply';instruction.disabled=Boolean(busy||!canPrepare);
      instruction.addEventListener('input',()=>{saved.instruction=instruction.value;});instructionLabel.append(instruction);draftBox.append(instructionLabel);
      if (!canPrepare) draftBox.append(node('p',draftReason(assistance.reason),'vp3-profile-note'));
      const prepare=button('Prepare private draft',()=>void prepareDraft(value,saved,false));
      prepare.disabled=Boolean(!canPrepare||busy||uncertain||saved.draftStatus!=='verified'||unsettledPreparation(saved)||saved.draft&&saved.draft.state!=='sent');draftBox.append(prepare);
      const draft=saved.draft;
      if (draft) {
        const label={queued:'Draft saved; awaiting preparation.',running:'Preparing a private reply…',ready:'Private draft ready to review.',uncertain:'The draft outcome could not be confirmed.',failed:'The private draft could not be prepared.',stale:'The conversation changed. This earlier draft needs review.',sent:'This draft has a recorded send receipt.'}[draft.state];
        draftBox.append(node('p',label,'vp3-profile-draft-status'));
        if (draft.text&&draft.state!=='ready') { const prior=node('details');prior.append(node('summary','View saved draft'),node('p',draft.text));draftBox.append(prior); }
        if (draft.state==='ready'&&draft.current&&draft.text!==saved.text) {
          const use=button('Use saved draft',()=>{
            if(!currentScope()||saved.actionAttempt?.pending||saved.actionAttempt?.state==='uncertain')return;
            saved.text=draft.text;saved.edited=false;saved.linkedPrepareId=draft.prepare_request_id;
            saved.textSource={turnId:draft.source.turn_id,revision:draft.source.revision,sourceRevision:draft.source.source_revision};renderThread(value,saved);
          });use.disabled=Boolean(busy||uncertain);draftBox.append(use);
        }
        const check=button('Check draft status',async()=>{check.disabled=true;try{await recoverDraft(id,generation,saved);}catch(error){if(currentScope()){saved.notice=errorMessage(error);renderThread(threadData,saved);}}});
        check.disabled=Boolean(busy);draftBox.append(check);
        if (saved.prepareAttempt&&['queued','running','uncertain'].includes(draft.state)) {
          const retry=button('Retry saved preparation',()=>void prepareDraft(value,saved,false,true));retry.disabled=Boolean(busy);draftBox.append(retry);
        }
        if (draft.can_regenerate) {
          const regenerate=button('Prepare a new draft',()=>void prepareDraft(value,saved,true));regenerate.disabled=Boolean(!canPrepare||busy||uncertain);draftBox.append(regenerate);
        }
      } else if (saved.prepareAttempt?.state==='uncertain') {
        draftBox.append(node('p','Preparation could not be confirmed. Retry the saved request before creating another.','vp3-profile-note'),button('Retry saved preparation',()=>void prepareDraft(value,saved,false,true)));
      }
      if (!draft&&saved.draftStatus!=='verified') {
        draftBox.append(button('Check draft status',async()=>{try{await recoverDraft(id,generation,saved);}catch(error){if(currentScope()){saved.notice=errorMessage(error);renderThread(threadData,saved);}}}));
      }
      content.append(draftBox);
      const form=node('form','','vp3-profile-reply');form.dataset.profileReply='';
      const label=node('label','Your reply to this visitor');
      const input=node('textarea');input.rows=4;input.maxLength=4000;input.dataset.profileReplyBody='';input.value=saved.text;
      let staleRecovery=null,feedback=null;
      input.addEventListener('input',()=>{
        if(input.value&&!saved.textSource)saved.textSource=textSource(value);
        saved.text=input.value;saved.edited=true;
        if(!input.value){saved.textSource=null;saved.linkedPrepareId='';}
        const stale=textIsStale();
        send.disabled=Boolean(!canReply||busy||uncertain||stale);
        if(staleRecovery)staleRecovery.hidden=!stale;
        if(!stale&&saved.notice==='Review this text against the current visitor messages before sending.'){
          saved.notice='';feedback?.remove();
        }
      });label.append(input);form.append(label);
      const canReply=turn?turn.can_reply===true:value.conversation.can_manual_reply===true;
      input.disabled=Boolean(busy||!canReply);
      const send=node('button','Send reply to visitor');send.type='submit';send.disabled=Boolean(!canReply||busy||uncertain||staleText);
      form.append(send);form.addEventListener('submit',event=>{event.preventDefault();void performAction('reply',value,saved);});content.append(form);
      if (staleText) {
        const changed=node('section','','vp3-profile-recovery');
        staleRecovery=changed;
        changed.append(node('p','The conversation changed after this text was started. Review the current messages before using it for a new reply.'));
        const review=button('Use this text for the current reply',()=>{
          if(!currentScope()||busy||uncertain)return;
          saved.textSource=textSource(value);saved.linkedPrepareId='';saved.edited=true;saved.notice='Text reviewed for the current visitor turn. It will be sent as your manual reply.';renderThread(value,saved);
        });review.disabled=Boolean(busy||uncertain);changed.append(review);content.append(changed);
      }
      if (uncertain) {
        const recovery=node('section','','vp3-profile-recovery');recovery.setAttribute('role','status');
        recovery.append(node('p','The previous action’s outcome is not confirmed. Retry that same action to recover its result.'));
        if (saved.actionAttempt.payload.operation==='reply') recovery.append(node('blockquote',saved.actionAttempt.payload.message));
        recovery.append(button('Retry previous action',()=>void performAction(saved.actionAttempt.payload.operation,value,saved,true)));content.append(recovery);
      }
      if (saved.notice) { feedback=node('p',saved.notice,'vp3-profile-feedback');feedback.setAttribute('role','status');content.append(feedback); }
      if (focus&&!input.disabled) { input.focus({preventScroll:true});input.setSelectionRange(selectionStart,selectionEnd); }
    }
    async function performAction(operation,value,saved,retry=false) {
      rememberInput();
      if (!currentScope()||saved.actionAttempt?.pending||saved.prepareAttempt?.pending) return;
      if (!retry && saved.actionAttempt?.state==='uncertain') return;
      const id=value.public_conversation_id,turn=value.current_turn;
      if (!retry && (turn?turn['can_'+operation]!==true:operation!=='reply'||value.conversation.can_manual_reply!==true)) return;
      const message=operation==='reply'?saved.text.trim():'';
      if (!retry&&operation==='reply'&&(!message||Array.from(message).length>4000)) {saved.notice='Enter a reply of up to 4,000 characters.';renderThread(value,saved);return;}
      if (!retry&&operation==='reply'&&(!sameTextSource(saved.textSource,textSource(value))
        || saved.linkedPrepareId&&(!saved.draft?.current||saved.draft.state!=='ready'||saved.draft.prepare_request_id!==saved.linkedPrepareId))) {
        saved.notice='Review this text against the current visitor messages before sending.';renderThread(value,saved);return;
      }
      let attempt=saved.actionAttempt;
      if (!retry) {
        attempt={payload:{public_conversation_id:id,operation,request_id:randomId(),turn_id:turn?.turn_id||0,revision:turn?.revision||0,
          ...(operation==='reply'?{message}:{}),...(operation==='reply'&&saved.linkedPrepareId?{prepare_request_id:saved.linkedPrepareId}:{})},raw:saved.text,state:'pending',pending:true};
        saved.actionAttempt=attempt;
      } else if (!attempt || attempt.state!=='uncertain') return;
      attempt.pending=true;saved.notice='';renderThread(value,saved);
      try {
        const result=await request('conversation_action',attempt.payload,{method:'POST'});
        if (result.data.request_id!==attempt.payload.request_id||result.data.operation!==operation
          || result.data.public_conversation_id!==id || operation==='reply'&&!positive(result.data.message_id)) throw new Error('The action receipt could not be verified.');
        const fresh=conversationView(result.data,id);if (!fresh) throw new Error('The updated conversation could not be verified.');
        attempt.state='complete';
        if (operation==='reply') {
          if (saved.text===attempt.raw) {saved.text='';saved.edited=false;saved.textSource=null;saved.linkedPrepareId='';}
          if (result.data.draft) {const draft=draftModel(result.data.draft,id);if(draft)applyDraft(saved,draft);}
        }
        saved.notice=operation==='reply'?'Your reply was sent to this visitor.':operation==='claim'?'You are now responding to this visitor.':operation==='delegate'?'The Profile Agent is responding.':'Conversation resolved.';
        if (currentScope()&&selection.tab==='conversations'&&selection.publicConversationId===id) {threadData=fresh;selectedData=fresh;}
        window.dispatchEvent(new Event('vp3:profile-handoff-refresh'));
      } catch(error) {
        attempt.state=[400,401,403,404,405,409,422].includes(error.status)?'failed':'uncertain';
        saved.notice=attempt.state==='uncertain'?'The action may have completed. Retry the same action to verify its receipt.':errorMessage(error);
        if ([401,403,419].includes(error.status)) {statusError(error);return;}
      } finally {
        attempt.pending=false;
        if (currentScope()&&selection.tab==='conversations'&&selection.publicConversationId===id&&threadData) renderThread(threadData,saved);
        for(const listener of attemptListeners)listener(initialKey,id);
      }
    }
    async function prepareDraft(value,saved,regenerate=false,retry=false) {
      rememberInput();if (!currentScope()||saved.prepareAttempt?.pending||saved.actionAttempt?.pending) return;
      const id=value.public_conversation_id,turn=value.current_turn;
      let attempt=saved.prepareAttempt;
      if (!retry) {
        if (saved.draftStatus!=='verified') return;
        if (unsettledPreparation(saved)&&!regenerate) return;
        if (saved.draft&&saved.draft.state!=='sent'&&!regenerate) return;
        if (regenerate&&!saved.draft?.can_regenerate) return;
        if (value.assistance?.can_prepare!==true||!positive(turn?.turn_id)||!positive(turn?.revision)||!revision(value.conversation.source_revision)) return;
        if (saved.draft&&['queued','running','uncertain'].includes(saved.draft.state)&&!regenerate) return;
        attempt={payload:{prepare_request_id:randomId(),public_conversation_id:id,turn_id:turn.turn_id,revision:turn.revision,
          source_revision:value.conversation.source_revision,instruction:saved.instruction.trim(),
          ...(regenerate&&saved.draft?{supersedes_request_id:saved.draft.prepare_request_id}:{})},state:'pending',pending:true};
        saved.prepareAttempt=attempt;
      } else if (!attempt) return;
      attempt.pending=true;saved.notice='Preparing a private reply…';renderThread(value,saved);
      try {
        const result=await request('draft_prepare',attempt.payload,{method:'POST'});
        const draft=draftModel(result.data.draft,id);
        if (!draft||draft.prepare_request_id!==attempt.payload.prepare_request_id) throw new Error('The saved draft receipt could not be verified.');
        attempt.state=draft.state;applyDraft(saved,draft);saved.draftStatus='verified';saved.notice='';
      } catch(error) {
        attempt.state=[400,401,403,404,405,409,422].includes(error.status)?'failed':'uncertain';
        saved.notice=attempt.state==='uncertain'?'Draft preparation could not be confirmed. Retry the saved request to recover its status.':errorMessage(error);
        if ([401,403,419].includes(error.status)) {statusError(error);return;}
      } finally {
        attempt.pending=false;
        if (currentScope()&&selection.tab==='conversations'&&selection.publicConversationId===id&&threadData) renderThread(threadData,saved);
        for(const listener of attemptListeners)listener(initialKey,id);
      }
    }
    function callContext(call) {
      const context=currentScope();
      return context&&!root.closest('[hidden]')&&selection.tab==='calls'&&selection.callId===call.call_id
        ? {...context,callId:call.call_id,publicConversationId:call.public_conversation_id} : null;
    }
    function callResult(result,key,context) {
      const value=result.data[key];
      if (!value||value.call_id!==context.callId||value.public_conversation_id!==context.publicConversationId) throw new Error('Call activity did not match the selected call.');
      return value;
    }
    function callTime(value) {
      return positive(value)?new Date(value).toLocaleString():'';
    }
    function exactTranscriptUrl(value) {
      const href=localUrl(value);if(!href)return '';
      const url=new URL(href,location.origin),base=new URL(endpoint,location.origin).pathname.replace(/\/api\/[^/]+$/,'');
      return url.pathname===base+'/artist-listening.php'&&[...url.searchParams].length===1
        && /^[1-9][0-9]*$/.test(url.searchParams.get('session')||'')&&positive(Number(url.searchParams.get('session')))?href:'';
    }
    function researchUrl(value) {
      if(typeof value!=='string'||value.length>3000||/[\\\u0000-\u0020\u007f]/.test(value))return '';
      try {const url=new URL(value);return url.protocol==='https:'&&!url.username&&!url.password?url.href:'';}catch{return '';}
    }
    function sectionModel(value,key) {
      const limit={transcript:50,analysis:24,research:6,followups:20}[key];
      if(!value||!outcomeAvailability.has(value.availability)||!['ready','limited'].includes(value.coverage)
        || value.source_revision!==null&&!revision(value.source_revision)||typeof value.reason!=='string'||value.reason.length>100
        || !Array.isArray(value.items)||value.items.length>limit||typeof value.has_more!=='boolean'
        || value.next_cursor!==null&&(typeof value.next_cursor!=='string'||!value.next_cursor||value.next_cursor.length>2500)
        || value.next_cursor&&(!value.has_more||!revision(value.source_revision))||value.has_more&&!value.next_cursor&&value.coverage!=='limited'
        || value.availability!=='ready'&&(value.items.length||value.has_more))return null;
      const ids=new Set();
      for(const item of value.items){
        if(!item||typeof item!=='object')return null;
        if(key==='transcript'){
          if(!positive(item.id)||typeof item.text!=='string'||Array.from(item.text).length>8000||typeof item.speaker!=='string'||Array.from(item.speaker).length>100
            || !nonnegative(item.start_ms)||typeof item.text_truncated!=='boolean')return null;
        }else if(key==='analysis'){
          if(!['summary','finding','question','next_step'].includes(item.kind)||!plain(item.text,2000)||typeof item.reviewed!=='boolean')return null;
          if(item.based_on_reviewed_sources!==undefined&&(typeof item.based_on_reviewed_sources!=='boolean'||item.based_on_reviewed_sources&&item.reviewed))return null;
        }else if(key==='research'){
          if(!plain(item.id,100)||!plain(item.query,500)||typeof item.summary!=='string'||Array.from(item.summary).length>4000
            || !['running','complete','failed'].includes(item.stored_status)||!['complete','failed','incomplete'].includes(item.display_status)
            || item.stored_status==='running'&&item.display_status!=='incomplete'||!Array.isArray(item.sources)||item.sources.length>12
            || !nonnegative(item.started_at_ms)||item.sources.some(source=>!plain(source?.title,220)||!researchUrl(source.url)))return null;
        }else if(!positive(item.id)||item.canonical_ref!=='office:task:'+item.id||!plain(item.title,240)
          || !['open','in_progress','waiting','completed','cancelled'].includes(item.task_status)||!exactTranscriptUrl(item.source_url))return null;
        if(key!=='analysis'){if(ids.has(item.id))return null;ids.add(item.id);}
      }
      if(value.url!==undefined&&value.url!==null&&value.url!==''&&!exactTranscriptUrl(value.url))return null;
      if(value.session_id!==undefined&&value.session_id!==null&&!positive(value.session_id))return null;
      if(['forbidden','not_applicable'].includes(value.availability)&&(value.url||value.session_id))return null;
      if(value.url&&value.session_id&&Number(new URL(value.url,location.origin).searchParams.get('session'))!==value.session_id)return null;
      return value;
    }
    function outcomeModel(value,call,section) {
      const keys=section==='all'?outcomeSections.map(([key])=>key):[section];
      if(!value||value.contract!=='vp3.profile-call-outcome.v1'||value.call_id!==call.call_id
        || value.public_conversation_id!==call.public_conversation_id||!revision(value.context_revision)
        || !callModel(value.call)||value.call.call_id!==call.call_id||value.call.public_conversation_id!==call.public_conversation_id
        || value.call.detail_kind!=='outcome'||!value.sections||Array.isArray(value.sections)
        || Object.keys(value.sections).length!==keys.length||keys.some(key=>!sectionModel(value.sections[key],key)))return null;
      return value;
    }
    function sectionMessage(value) {
      if(value.availability==='empty')return 'No saved records in this section.';
      if(value.availability==='forbidden')return 'Your current access does not include this saved section.';
      if(value.availability==='not_applicable')return 'This section does not apply to this call.';
      if(value.availability==='unavailable')return 'This saved section could not be verified. Refresh to check it again.';
      if(value.reason==='transcript_changed')return 'The saved analysis no longer matches the current transcript. Open the saved transcript to review it.';
      if(value.reason==='analysis_not_supported')return 'No supported saved analysis could be verified for the current transcript.';
      if(value.reason==='partial_analysis')return 'This saved analysis covers part of the verified transcript.';
      return value.coverage==='limited'?'This is a limited view of the verified saved records.':'';
    }
    function appendOutcomeItems(list,key,items) {
      for(const item of items){
        const row=node('li','','vp3-profile-outcome-item');
        if(key==='transcript'){
          row.dataset.profileTranscriptSegment=String(item.id);
          const seconds=Math.floor(item.start_ms/1000),stamp=Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0');
          row.append(node('strong',item.speaker||'Speaker'),node('small',stamp),node('p',item.text));
          if(item.text_truncated)row.append(node('small','This segment is shortened. Open the saved transcript for the complete text.'));
        }else if(key==='analysis'){
          row.append(node('strong',({summary:'Summary',finding:'Finding',question:'Open question',next_step:'Next step'})[item.kind]
            +(item.based_on_reviewed_sources?' · based on reviewed findings':item.reviewed?' · reviewed':'')),node('p',item.text));
        }else if(key==='research'){
          row.dataset.profileResearch=String(item.id);
          row.append(node('strong',item.query),node('small',({complete:'Saved result',failed:'Research failed',incomplete:'Incomplete when the call closed'})[item.display_status]));
          if(item.summary)row.append(node('p',item.summary));
          if(item.sources.length){const links=node('ul');for(const source of item.sources){const li=node('li'),link=node('a',source.title);link.href=researchUrl(source.url);link.target='_blank';link.rel='noopener noreferrer';li.append(link);links.append(li);}row.append(links);}
        }else{
          row.dataset.profileFollowup=item.canonical_ref;
          const link=node('a',item.title);link.href=exactTranscriptUrl(item.source_url);
          row.append(link,node('small',({open:'Open',in_progress:'In progress',waiting:'Waiting',completed:'Completed',cancelled:'Cancelled'})[item.task_status]+' · reviewed task'));
        }
        list.append(row);
      }
    }
    function renderOutcomeSection(key,value,call,mine) {
      const host=outcomeHosts.get(key);if(!host)return;
      host.replaceChildren();
      const message=sectionMessage(value);if(message)host.append(node('p',message,'vp3-profile-note'));
      if(value.availability==='ready'){
        if(key==='analysis'&&value.generated_at)host.append(node('small','Saved analysis: '+plain(value.generated_at,100)));
        const list=node('ol','','vp3-profile-outcome-items');appendOutcomeItems(list,key,value.items);host.append(list);
      }
      if(value.url){const link=node('a','Open saved transcript');link.href=exactTranscriptUrl(value.url);host.append(link);}
      if(value.has_more&&value.next_cursor){
        const next=button('Load more '+({transcript:'transcript',analysis:'analysis',research:'research',followups:'follow-ups'})[key],()=>{
          next.disabled=true;void loadOutcome(call,mine,key,value.next_cursor).finally(()=>{if(next.isConnected)next.disabled=false;});
        });next.dataset.profileOutcomeMore=key;host.append(next);
      }else if(value.coverage==='limited'&&value.availability==='ready')host.append(node('p','Further records could not be established in this checked view.','vp3-profile-note'));
      if(value.availability==='unavailable')host.append(button('Refresh '+({analysis:'analysis',followups:'follow-ups',research:'research',transcript:'transcript'})[key],()=>void loadOutcome(call,mine,key)));
    }
    function outcomeChanged(call,mine,key,error) {
      if(!current(mine)||!callDetailHost?.isConnected)return;
      if([401,403,419].includes(error?.status)){statusError(error,mine);return;}
      if(key==='all'||error.contextChanged||error.status===409){
        callDetailEpoch++;outcomeBusy.clear();callOutcome=null;outcomeHosts.clear();callDetailHost.replaceChildren(heading('Saved call outcome'));
        callDetailHost.append(node('p',error.status===409?'This call outcome changed. Reload it before reviewing saved records.':errorMessage(error),'vp3-profile-note'),button('Reload outcome',()=>void refresh()));
        return;
      }
      if(callOutcome)delete callOutcome.sections[key];
      const host=outcomeHosts.get(key);if(!host)return;
      host.replaceChildren(node('p',error.status===409?'These saved records changed. Refresh this section before continuing.':errorMessage(error),'vp3-profile-note'),
        button('Refresh '+({analysis:'analysis',followups:'follow-ups',research:'research',transcript:'transcript'})[key],()=>void loadOutcome(call,mine,key)));
    }
    async function loadOutcome(call,mine,section='all',cursor='') {
      if(!current(mine)||outcomeBusy.has(section))return;
      outcomeBusy.add(section);
      const prior=callOutcome,priorSection=prior?.sections?.[section],epoch=callDetailEpoch;
      try{
        const result=await request('call_outcome',{call_id:call.call_id,public_conversation_id:call.public_conversation_id,section,cursor});
        if(!current(mine)||epoch!==callDetailEpoch||selection.callId!==call.call_id||!callDetailHost?.isConnected)return;
        const value=outcomeModel(result.data.call_outcome,call,section);
        if(!value)throw new Error('The saved call outcome could not be verified.');
        if(section!=='all'&&(!prior||value.context_revision!==prior.context_revision))throw Object.assign(new Error('The call context changed.'),{status:409,contextChanged:true});
        const latest=callOutcome;
        if(section!=='all'&&(!latest||latest.context_revision!==prior.context_revision||latest.sections[section]!==priorSection
          || cursor&&cursor!==priorSection?.next_cursor))throw Object.assign(new Error('The saved section changed while it was loading.'),{status:409,contextChanged:true});
        if(cursor){
          const next=value.sections[section];
          if(next.availability==='ready'&&(!priorSection||next.source_revision!==priorSection.source_revision))throw Object.assign(new Error('The saved section changed.'),{status:409});
          if(next.availability==='ready'){
            const ids=new Set(priorSection.items.map(item=>item.id));
            if(next.items.some(item=>ids.has(item.id)))throw Object.assign(new Error('The section page no longer matches.'),{status:409});
            next.items=[...priorSection.items,...next.items];
          }
        }
        if(section==='all'){
          callOutcome=value;outcomeHosts.clear();
          for(const [key,title] of outcomeSections){
            const detail=node('details','','vp3-profile-outcome-section');detail.dataset.profileOutcomeSection=key;detail.open=key!=='transcript';
            const summary=node('summary',title),host=node('div');detail.append(summary,host);callDetailHost.append(detail);outcomeHosts.set(key,host);
            renderOutcomeSection(key,value.sections[key],call,mine);
          }
        }else{
          // Different sections may finish out of order. Preserve the current
          // verified pages, while the requested section keeps its exact proof.
          callOutcome={...latest,sections:{...latest.sections,...value.sections}};
          renderOutcomeSection(section,value.sections[section],call,mine);
        }
      }catch(error){if(current(mine)&&epoch===callDetailEpoch)outcomeChanged(call,mine,section,error);}
      finally{if(current(mine)&&epoch===callDetailEpoch)outcomeBusy.delete(section);}
    }
    function renderCallRows(mine) {
      if(!callPage||!callListHost)return;
      callListHost.replaceChildren();callListFooter.replaceChildren();
      callListNotice.textContent=callPage.coverage==='limited'?'Some call sources could not be checked. This list may be incomplete.':'';
      for(const call of callPage.calls){
        const row=node('article','','vp3-profile-call');row.dataset.profileWorkspaceCall=String(call.call_id);
        const copy=node('div');copy.append(node('strong',call.visitor_label),node('p',call.state_label),node('small',callTime(call.created_at_ms)));
        const open=button(call.detail_kind==='outcome'?'View outcome':'View call',()=>select({tab:'calls',callView:selection.callView,callId:call.call_id,publicConversationId:call.public_conversation_id}));
        if(call.call_id===selection.callId)open.setAttribute('aria-current','true');
        row.append(copy,open);callListHost.append(row);
      }
      if(!callPage.calls.length)callListHost.append(node('p',selection.callView==='history'?'No completed calls in this checked view.':'No current calls in this checked view.','vp3-profile-empty'));
      if(callPage.has_more){const more=button('Load more calls',()=>{
        if(callPageBusy)return;more.disabled=true;void loadCalls(mine,callPage.next_cursor).catch(error=>{if(current(mine))statusError(error,mine);});
      });callListFooter.append(more);}
    }
    async function showCallDetail(call,mine) {
      const key=JSON.stringify([call.call_id,call.public_conversation_id,call.detail_kind,call.state,call.assisted,call.activity_available]);
      if(callDetailKey===key)return;
      callActivity?.dispose();callActivity=null;callDetailKey=key;callDetailEpoch++;callOutcome=null;outcomeHosts.clear();outcomeBusy.clear();
      callDetailHost.replaceChildren(heading(call.detail_kind==='outcome'?'Saved call outcome':'Selected current call',call.visitor_label+' · '+call.state_label));
      const meta=node('dl','','vp3-profile-call-facts');
      for(const [label,value] of [['Call',call.mode==='video'?'Video':'Audio'],['Assistance',call.assisted?'Agent assisted':'Direct call'],['Offered',callTime(call.created_at_ms)],['Accepted',callTime(call.accepted_at_ms)],['Ended',callTime(call.ended_at_ms)]]){
        if(value)meta.append(node('dt',label),node('dd',value));
      }callDetailHost.append(meta);
      if(call.detail_kind==='current'&&selection.callView==='history')callDetailHost.append(node('p','This selected call is still current. Its saved outcome is available after it ends.','vp3-profile-note'));
      if(call.detail_kind==='outcome'&&selection.callView==='current')callDetailHost.append(node('p','This selected call has ended and is available in Call history.','vp3-profile-note'));
      const actions=node('div','','vp3-profile-actions');
      actions.append(button('Back to calls',()=>select({tab:'calls',callView:selection.callView,callId:0,publicConversationId:0})));
      const focus=button('Open call in Chat',()=>{
        const context=callContext(call);if(!context)return;
        const focused=window.VP3ProfileCallsPa3c?.focusOwnerCall?.({callId:context.callId,publicConversationId:context.publicConversationId,
          chatConversationId:context.chatConversationId,chatAgentId:context.chatAgentId},{beforeFocus:()=>onClose?.(false)});
        if(!focused)say(call.detail_kind==='current'?'That live call card is not available in this Chat. The call has not been restarted.':'That call card is not available in this Chat. The call has not been restarted.');
      });actions.append(focus,button('Open conversation',()=>select({tab:'conversations',publicConversationId:call.public_conversation_id})));callDetailHost.append(actions);
      if(call.detail_kind==='outcome'){await loadOutcome(call,mine);return;}
      const activity=node('section');callDetailHost.append(activity);
      if(call.assisted===false)activity.append(node('p','This is a direct call. No Agent transcript or research is available.','vp3-profile-note'));
      else if(call.activity_available!==true)activity.append(node('p','Agent call activity is not available for this call and current access.','vp3-profile-note'));
      else if(window.VP3ProfileCallActivityPa3c?.mount){
        callActivity=window.VP3ProfileCallActivityPa3c.mount(activity,{
          context:()=>callContext(call),
          read:async({context,after,signal})=>callResult(await request('call_activity',{call_id:context.callId,public_conversation_id:context.publicConversationId,after},{context,signal}),'call_activity',context),
          mutate:async({context,operation,input,signal})=>{
            if(!['set_assistance','research'].includes(operation))throw new Error('Unknown call action.');
            return callResult(await request(operation==='research'?'call_research':'call_set_assistance',
              {call_id:context.callId,public_conversation_id:context.publicConversationId,...input},{context,signal,method:'POST'}),'call_action',context);
          },onUnavailable:message=>{if(current(mine))say(plain(message,500)||'Call activity is unavailable.');}
        });callActivity?.select(call.call_id);
      }else activity.append(node('p','Call activity is unavailable. The live Chat call card remains in place.','vp3-profile-note'));
    }
    async function loadCalls(mine,cursor='') {
      if(callPageBusy)return;callPageBusy=true;
      const filter=selection.callView;
      try{
        const result=await request('calls',{filter,cursor,...(selection.callId?{call_id:selection.callId}:{}),...(!selection.callId&&selection.publicConversationId?{public_conversation_id:selection.publicConversationId}:{})});
        if(!current(mine))return;
        const value=callListModel(result.data.calls,filter);if(!value)throw new Error('The call list could not be verified.');
        if(cursor){
          const rows=new Map(callPage.calls.map(call=>[call.call_id,call]));for(const call of value.calls)rows.set(call.call_id,call);
          callPage={...value,calls:[...rows.values()]};
        }else{
          callPage=value;content.replaceChildren(heading('Calls','Current calls and saved outcomes in this private Chat.'));
          const filters=node('div','','vp3-profile-call-filters');filters.setAttribute('role','group');filters.setAttribute('aria-label','Calls view');
          for(const [key,label] of [['current','Current calls'],['history','Call history']]){
            const control=button(label,()=>select({tab:'calls',callView:key,callId:0,publicConversationId:0}));control.setAttribute('aria-pressed',String(filter===key));filters.append(control);
          }content.append(filters);
          callListNotice=node('p','','vp3-profile-note');callListHost=node('div','','vp3-profile-calls');callListFooter=node('div','','vp3-profile-actions');
          callDetailHost=node('section','','vp3-profile-call-detail');callDetailHost.dataset.profileCallDetail='';callDetailHost.tabIndex=-1;callDetailKey='';
          content.append(callDetailHost,callListNotice,callListHost,callListFooter);
        }
        if(['unavailable','unconfigured'].includes(value.coverage)){
          callActivity?.dispose();callActivity=null;callOutcome=null;callListHost.replaceChildren(node('p','Calls are not available in this Chat.','vp3-profile-empty'));callListFooter.replaceChildren();callDetailHost.replaceChildren();return;
        }
        renderCallRows(mine);
        if(!selection.callId){rememberCalls();return;}
        const call=value.selected||value.calls.find(row=>row.call_id===selection.callId);
        if(!call||call.call_id!==selection.callId||selection.publicConversationId&&call.public_conversation_id!==selection.publicConversationId)throw new Error('The selected call is not available in this Chat.');
        selection.publicConversationId=call.public_conversation_id;rememberCalls();await showCallDetail(call,mine);
        if(!cursor&&current(mine)&&callDetailHost?.isConnected)callDetailHost.focus({preventScroll:true});
      }finally{if(current(mine))callPageBusy=false;}
    }
    function analyticsPoint(value,unit='count',currency='') {
      const wrap=node('div','','vp3-profile-period-point');
      const complete=value.coverage==='complete';
      const amount=currency?(complete?value.value_minor:value.observed_value_minor):(complete?value.value:value.observed_value);
      let text=amount===null?(complete?'No observations':'Unavailable'):currency?analyticsLedgerAmount(amount,currency)
        :new Intl.NumberFormat(undefined,{maximumFractionDigits:unit==='seconds'?1:0}).format(amount)+(unit==='seconds'?' s':'');
      wrap.append(node('strong',text));
      if(!complete&&amount!==null)wrap.append(node('small','Observed subset · partial coverage'));
      else if(!complete)wrap.append(node('small','Verified total unavailable'));
      const reason=analyticsReasons[value.reason];
      if(reason&&value.reason!=='no_observations')wrap.append(node('small',reason));
      return wrap;
    }
    function analyticsComparison(metric,currency='') {
      const comparison=metric.comparison;
      if(!comparison)return node('span','Not comparable');
      if(currency)return node('span',comparison.delta_minor===null?'Not comparable':analyticsLedgerAmount(comparison.delta_minor,currency,true));
      if(comparison.delta===null)return node('span','Not comparable');
      const format=new Intl.NumberFormat(undefined,{maximumFractionDigits:metric.unit==='seconds'?1:0,signDisplay:'exceptZero'});
      const text=format.format(comparison.delta)+(metric.unit==='seconds'?' s':'');
      const wrap=node('div',text);
      if(comparison.percent!==null)wrap.append(node('small',new Intl.NumberFormat(undefined,{maximumFractionDigits:1,signDisplay:'exceptZero'}).format(comparison.percent)+'%'));
      else if(metric.previous.value===0)wrap.append(node('small','No percentage from a zero baseline'));
      return wrap;
    }
    function analyticsTable(rows,currencyRows=false,responseRows=false) {
      const table=node('table','','vp3-profile-period-table');
      const head=node('thead'),headRow=node('tr');
      for(const label of ['Measure','Selected period','Previous period','Change']){const th=node('th',label);th.setAttribute('scope','col');headRow.append(th);}
      head.append(headRow);table.append(head);
      const body=node('tbody');
      for(const metric of rows){
        const row=node('tr');row.dataset.profileAnalyticsMetric=metric.key+(currencyRows?':'+metric.currency:'');
        const title=currencyRows?(metric.key==='verified_payments'?'Verified payments':'Verified refunds')+' · '+metric.currency
          :responseRows?responseMetricCatalog[metric.key][1]:analyticsMetricCatalog[metric.key][2];
        const name=node('th',title);name.setAttribute('scope','row');row.append(name);
        for(const [key,label] of [['current','Selected period'],['previous','Previous period']]){
          const cell=node('td');cell.dataset.label=label;cell.append(analyticsPoint(metric[key],metric.unit,currencyRows?metric.currency:''));row.append(cell);
        }
        const change=node('td');change.dataset.label='Change';change.append(analyticsComparison(metric,currencyRows?metric.currency:''));row.append(change);body.append(row);
      }
      table.append(body);return table;
    }
    function analyticsCohortDetail(value) {
      const detail=node('details','','vp3-profile-period-definitions');detail.append(node('summary','Response and call evidence'));
      for(const [key,label] of [['current','Selected period'],['previous','Previous period']]){
        const section=node('section');section.append(node('h4',label));
        const cohort=value.first_reply_cohorts[key],calls=value.call_breakdowns[key];
        const display=n=>n===null?'unavailable':String(n);
        section.append(node('p',(cohort.coverage==='complete'?'First-request cohort: ':'Observed first-request cohort (partial coverage): ')+display(cohort.eligible)+' eligible; '+display(cohort.replied)
          +' replied before cutoff; '+display(cohort.unanswered)+' unanswered at cutoff; '+display(cohort.unverifiable)+' unverifiable.'));
        if(cohort.coverage!=='complete')section.append(node('p',analyticsReasons[cohort.reason]||'First-response evidence is incomplete.'));
        if(value.groups.calls.coverage!=='ready')section.append(node('p','Call breakdowns below are observed subsets where evidence is available; complete totals could not be established.'));
        section.append(node('p','Accepted calls: '+display(calls.accepted.direct)+' direct; '+display(calls.accepted.assisted)+' Agent assisted.'));
        section.append(node('p','Recorded outcomes: '+['ended','declined','cancelled','expired','failed'].map(name=>display(calls.finished[name])+' '+name).join('; ')+'.'));
        section.append(node('p','Offer-to-accept evidence: '+display(calls.acceptance_delay.verified)+' verified out of '
          +display(calls.acceptance_delay.eligible)+' eligible; '+display(calls.acceptance_delay.unverifiable)+' unverifiable.'));
        detail.append(section);
      }
      detail.append(node('p','Reply delays use verified first replies before the fixed cutoff. Call outcomes use the currently verified saved outcome. Offer-to-accept delay does not measure time spent talking.'));
      return detail;
    }
    function analyticsImageFailureList(rows) {
      const list=node('ul','','vp3-profile-image-failures');
      for(const row of rows){const item=node('li');item.append(node('span',row.label),node('strong',new Intl.NumberFormat().format(row.count)));list.append(item);}
      return list;
    }
    function analyticsImageRankings(side) {
      const grid=node('div','','vp3-profile-image-rankings');
      for(const [key,title,nameKey,truncated] of [['top_catalogs','Top reference catalogs','name',side.catalogs_truncated],['top_images','Top reference images','title',side.images_truncated]]){
        const section=node('section');section.dataset.profileImageRanking=key;section.append(node('h5',title));
        if(side.rankings_coverage!=='complete')section.append(node('p',side.rankings_coverage==='partial'?'Verified subset · source evidence is incomplete.':'Source usage is unavailable.'));
        if(side[key].length){
          const list=node('ol');
          for(const row of side[key]){
            const item=node('li');item.append(node('strong',row[nameKey]));
            item.append(node('span',new Intl.NumberFormat().format(row.completed_count)+' completed creation'+(row.completed_count===1?'':'s')));
            if(key==='top_catalogs')item.append(node('small',row.initial_count+' initial · '+row.revision_count+' revision'+(row.revision_count===1?'':'s')));
            list.append(item);
          }
          section.append(list);
        }else if(side.rankings_coverage==='complete')section.append(node('p','No completed creations in this period.'));
        else if(side.rankings_coverage==='partial')section.append(node('p','No completed creations with verified references in the available records.'));
        if(truncated)section.append(node('small','Showing the 10 most used sources.'));
        grid.append(section);
      }
      return grid;
    }
    function analyticsImagePeriodDetail(side) {
      const section=node('div','','vp3-profile-image-period-detail');section.append(analyticsImageRankings(side));
      if(side.failures.length||side.failure_coverage!=='complete'){
        const failures=node('section');failures.append(node('h5','Failed image requests'));
        if(side.failure_coverage!=='complete')failures.append(node('p',side.failure_coverage==='partial'
          ?'Only failures with verified timing can be placed in this period. Current saved failures appear separately below.'
          :'Failure totals for this period are unavailable.'));
        if(side.failures.length)failures.append(analyticsImageFailureList(side.failures));
        section.append(failures);
      }
      return section;
    }
    function analyticsImageUsageDetail(value) {
      const detail=node('div','','vp3-profile-image-insights');detail.dataset.profileImageInsights='';
      detail.append(node('p','Reference usage counts completed creations. One creation can use more than one reference image; these counts do not identify unique visitors.'));
      detail.append(analyticsImagePeriodDetail(value.current));
      const previous=node('details','','vp3-profile-period-definitions');previous.append(node('summary','Previous period source usage'),analyticsImagePeriodDetail(value.previous));detail.append(previous);
      const expected=endpoint.replace(/\/api\/[^/]+$/,'/profile-agent.php');
      const profileUrl=localUrl(cfg.profileUrl||expected);
      if(profileUrl&&expected.endsWith('/profile-agent.php')){
        const url=new URL(profileUrl,location.origin);
        if(url.pathname===expected){
          url.search='';url.searchParams.set('tab','agent');url.hash='profileImageUseHistory';
          const link=node('a','Review saved creations');link.href=url.pathname+url.search+url.hash;link.dataset.profileImageHistoryLink='';detail.append(link);
        }
      }
      return detail;
    }
    function analyticsImageCurrent(backlog) {
      const section=node('section','','vp3-profile-image-current');section.dataset.profileImageCurrent='';
      const allowance=backlog.image_allowance,failures=backlog.image_failures;
      const current=node('section');current.dataset.profileImageAllowance='';current.append(node('h5','Today’s image allowance'));
      if(allowance.coverage==='complete'){
        current.append(node('strong',allowance.used+' of '+allowance.daily_limit+' requests used · '+allowance.remaining+' remaining'));
        current.append(node('p',(allowance.enabled?'Free photo editing is enabled.':'Free photo editing is currently off.')+' Each visitor can use up to '+allowance.visitor_daily_limit+' requests per UTC day.'));
        current.append(node('small','Admitted requests use allowance even if an edit fails or is cancelled. This is the current daily allowance, separate from completed-period results.'));
      }else current.append(node('p','Allowance unavailable. '+(analyticsReasons[allowance.reason]||'Current allowance could not be verified.')));
      current.append(node('small','Daily reset: '+new Date(allowance.resets_at_utc_ms).toISOString().replace('T',' ').replace('.000Z',' UTC')+'.'));
      section.append(current);
      const saved=node('section');saved.dataset.profileImageFailures='';saved.append(node('h5','Saved failed image requests'));
      const count=failures.coverage==='complete'?failures.count:failures.observed_count;
      saved.append(node('strong',count===null?'Unavailable':new Intl.NumberFormat().format(count)+(failures.coverage==='partial'?' verified in available records':'')));
      if(failures.coverage!=='complete')saved.append(node('p',analyticsReasons[failures.reason]||'Some failure evidence could not be verified.'));
      if(failures.reasons.length)saved.append(analyticsImageFailureList(failures.reasons));
      saved.append(node('small','Current saved failures across the creation history. These records are not a count of failures in the selected completed period.'));
      section.append(saved);return section;
    }
    function responseDuration(seconds) {
      if(seconds===null)return 'No timing samples';
      const unit=seconds>=86400?'day':seconds>=3600?'hour':seconds>=60?'minute':'second';
      const divisor={day:86400,hour:3600,minute:60,second:1}[unit],n=seconds/divisor;
      return new Intl.NumberFormat(undefined,{maximumFractionDigits:1}).format(n)+' '+unit+(n===1?'':'s');
    }
    function responseTopics(topics) {
      const wrap=node('p','','vp3-profile-response-topics');
      wrap.textContent=topics.length?'Question keywords: '+topics.map(row=>row.keyword+' ('+row.count+')').join(' · '):'No verified question keywords in these records.';
      return wrap;
    }
    function responseQuestionGroups(value,{live=false}={}) {
      const wrap=node('section','','vp3-profile-response-questions');wrap.dataset.profileResponseQuestions=live?'waiting':'period';
      wrap.append(node('h5',live?'Waiting knowledge gaps':'Knowledge-gap question wording'));
      wrap.append(node('p','Matching wording is grouped after case and punctuation are normalized. Counts are questions, not unique people.'));
      if(value.coverage!=='complete')wrap.append(node('p',value.coverage==='unavailable'?'Question evidence is unavailable.':'These are the verified questions in the available evidence.'));
      if(!value.questions.length)wrap.append(node('p',value.coverage==='complete'?'No recorded knowledge-gap questions in this view.':'No knowledge-gap questions could be verified in the available records.'));
      const list=node('ol');
      for(const group of value.questions){
        const item=node('li');item.dataset.profileResponseQuestion=group.key;
        item.append(node('strong',group.question_excerpt),node('p',group.count+' question'+(group.count===1?'':'s')+' in '+group.conversation_count+' conversation'+(group.conversation_count===1?'':'s')));
        const links=node('div','','vp3-profile-actions'),conversations=new Set();
        for(const sample of group.samples){
          if(conversations.has(sample.conversation_id))continue;conversations.add(sample.conversation_id);
          const open=button('Open conversation'+(group.samples.length>1?' '+conversations.size:''),()=>select({tab:'conversations',publicConversationId:sample.conversation_id}));
          open.dataset.profileResponseConversation=String(sample.conversation_id);links.append(open);
        }
        item.append(links);
        if(live&&group.suggestions?.length){
          const notes=node('ul','','vp3-profile-response-notes');notes.setAttribute('aria-label','Existing Knowledge notes to review');
          for(const suggestion of group.suggestions){
            const li=node('li'),link=node('a','Review “'+suggestion.title+'”');link.href=suggestion.url;link.dataset.profileResponseKnowledge=String(suggestion.knowledge_id);
            li.append(link,node('small','Matching keywords: '+suggestion.matched_terms.join(', ')));notes.append(li);
          }
          item.append(notes);
        }
        list.append(item);
      }
      wrap.append(list);
      if(value.questions_truncated)wrap.append(node('small','Showing the 10 most frequent wording groups. Other verified questions remain in their conversations.'));
      return wrap;
    }
    function responsePeriod(activity) {
      const section=node('section','','vp3-profile-period-group vp3-profile-response-period');section.dataset.profileResponsePeriod='';
      section.append(node('h4','Owner responses and knowledge gaps'));
      section.append(node('p','Recorded submissions and verified replies in the selected completed period. Earlier questions that were never recorded as owner requests are outside this report.'));
      if(activity.coverage!=='complete')section.append(node('p',analyticsReasons[activity.reason]||'Some response evidence could not be verified.'));
      section.append(analyticsTable(activity.metrics,false,true));
      const selected=activity.current;
      section.append(node('p',(selected.response_sample_size===null?'Timing sample unavailable':selected.response_sample_size+' verified answered question'+(selected.response_sample_size===1?'':'s')+' in the response-time sample')
        +'. Time starts at first submission and includes any delegation. Waiting questions are shown separately below.'));
      if(selected.unknown_reason_count>0)section.append(node('p',selected.unknown_reason_count+' original submission reason'+(selected.unknown_reason_count===1?' was':'s were')+' not preserved. Later reasons are not used to fill these gaps.'));
      section.append(responseQuestionGroups(selected),responseTopics(selected.topics));
      const previous=node('details','','vp3-profile-period-definitions');previous.append(node('summary','Previous period question details'),responseQuestionGroups(activity.previous),responseTopics(activity.previous.topics));section.append(previous);
      const definitions=node('details','','vp3-profile-period-definitions'),list=node('dl');definitions.append(node('summary','Response definitions and coverage'));
      for(const metric of activity.metrics)list.append(node('dt',responseMetricCatalog[metric.key][1]),node('dd',metric.definition));
      definitions.append(list);section.append(definitions);return section;
    }
    function responseBacklog(value) {
      const section=node('section','','vp3-profile-response-current');section.dataset.profileResponseBacklog='';
      section.append(node('h4','Questions waiting for you now'));
      if(value.coverage==='unavailable'){section.append(node('p','Current owner requests are unavailable. '+(analyticsReasons[value.reason]||'')));return section;}
      const count=value.coverage==='complete'?value.waiting_count:value.observed_waiting_count;
      const delegated=value.coverage==='complete'?value.delegated_count:value.observed_delegated_count;
      section.append(node('p',count+' waiting for your response'+(value.coverage==='partial'?' in the verified subset':'')+' · '+delegated+' delegated to the Agent and still awaiting a reply'));
      if(value.coverage!=='complete')section.append(node('p','Some current request evidence is incomplete; these are observed counts, not a complete backlog.'));
      section.append(node('p',count?'Longest current wait: '+responseDuration(value.oldest_wait_seconds)+' · Median current wait: '+responseDuration(value.median_wait_seconds):'No waiting questions were verified.'));
      section.append(node('small','Wait age starts at the latest submission or re-submission to you. Completed-period owner response times start at the first submission.'));
      const reasons=node('ul','','vp3-profile-image-failures');
      for(const row of value.reasons)reasons.append(node('li',responseReasons[row.reason]+': '+row.count));section.append(reasons);
      if(value.oldest.length){
        const oldest=node('details','','vp3-profile-period-definitions');oldest.append(node('summary','Oldest waiting questions'));
        const list=node('ol');
        for(const row of value.oldest){
          const item=node('li');item.append(node('p',row.question_excerpt),node('small',responseReasons[row.reason]+' · Waiting '+responseDuration(row.wait_seconds)),
            button('Open conversation',()=>select({tab:'conversations',publicConversationId:row.conversation_id})));list.append(item);
        }
        oldest.append(list);if(value.oldest_truncated)oldest.append(node('small','Showing the 10 longest current waits.'));section.append(oldest);
      }
      section.append(responseQuestionGroups(value,{live:true}),responseTopics(value.topics));
      const knowledge=node('p');knowledge.dataset.profileResponseKnowledgeStatus='';
      knowledge.textContent=value.knowledge.coverage==='unavailable'
        ?'Existing-note suggestions are unavailable under the current Knowledge access or storage. Your questions remain available above.'
        :'Existing-note suggestions use matching keywords in notes this Chat Agent may currently read. Open a note to review any update; this report does not edit notes or change Knowledge Access.';
      section.append(knowledge);
      if(value.knowledge.candidates_truncated)section.append(node('small','Suggestions checked the 500 newest personal notes; older notes may also be relevant.'));
      return section;
    }
    function renderAnalyticsPeriod(target,value,mine) {
      const frame=node('section','','vp3-profile-period');frame.dataset.profileAnalyticsPeriod=value.ref;
      const meta=node('div','','vp3-profile-period-meta');
      const selectedRange=analyticsCalendarRange(value.window.from_utc_ms,value.window.to_utc_ms,value.window.timezone);
      const previousRange=analyticsCalendarRange(value.window.previous.from_utc_ms,value.window.previous.to_utc_ms,value.window.timezone);
      meta.append(node('h4',(value.window.period===1?'Completed day':'Completed '+value.window.period+' days')+' · '+selectedRange));
      meta.append(node('p','Previous period: '+previousRange));
      meta.append(node('p','Period timezone: '+value.window.timezone));
      const details=node('details','','vp3-profile-period-details');details.dataset.profilePeriodDetails='';
      details.append(node('summary','Period details'));
      const detailBody=node('div');
      detailBody.append(node('p','Selected period: '+value.window.from_local+' to '+value.window.to_local));
      detailBody.append(node('p','Previous period: '+value.window.previous.from_local+' to '+value.window.previous.to_local));
      detailBody.append(node('small','The start is included; the end is excluded. The date headings name the included local calendar days.'));
      detailBody.append(node('small','Timezone source in this link: '+{explicit:'account choice',schedule:'schedule choice',utc_fallback:'UTC fallback'}[value.window.timezone_source]+'.'));
      detailBody.append(node('small','Fixed cutoff: '+new Date(value.window.as_of_utc_ms).toISOString().replace('T',' ').replace('.000Z',' UTC')));
      detailBody.append(node('small','Evidence checked: '+new Date(value.observed_at_utc_ms).toISOString().replace('T',' ').replace('.000Z',' UTC')));
      details.append(detailBody);meta.append(details);
      frame.append(meta);
      const choices=node('div','','vp3-profile-period-choices');choices.setAttribute('role','group');choices.setAttribute('aria-label','Completed period length');
      for(const option of value.period_options){
        const control=button(option.period===1?'1 day':option.period+' days',()=>select({...selection,tab:'analytics',analyticsView:'periods',analyticsRef:option.ref}));
        control.setAttribute('aria-pressed',String(option.ref===value.ref));control.dataset.profilePeriod=String(option.period);choices.append(control);
      }
      choices.append(button('Latest completed periods',()=>{
        if(!current(mine))return;
        selection.analyticsRef='';analyticsSelection.analyticsRef='';void refresh();
      }));
      frame.append(choices);
      if(value.coverage!=='ready')frame.append(node('p',
        value.coverage==='limited'?'Some measures have incomplete evidence. Observed subsets are labeled below.':'The requested period could not be completely verified.',
        'vp3-profile-period-coverage'));
      if(value.response_activity)frame.append(responsePeriod(value.response_activity));
      for(const [group,label] of analyticsGroups){
        const section=node('section','','vp3-profile-period-group');section.append(node('h4',label));
        const groupState=value.groups[group];
        if(groupState.coverage!=='ready')section.append(node('p',analyticsReasons[groupState.reason]||'Coverage is incomplete for this group.'));
        const metrics=value.metrics.filter(metric=>metric.group===group);
        if(group==='images')section.append(node('p','These creations used approved references priced at $0.00. A revision counts as a separate completed creation.'));
        section.append(analyticsTable(metrics));
        if(group==='images')section.append(analyticsImageUsageDetail(value.image_usage));
        const definitions=node('details','','vp3-profile-period-definitions');definitions.append(node('summary','Definitions and coverage'));
        const list=node('dl');
        for(const metric of metrics){list.append(node('dt',analyticsMetricCatalog[metric.key][2]),node('dd',metric.definition));}
        definitions.append(list);section.append(definitions);frame.append(section);
      }
      const money=node('section','','vp3-profile-period-group');money.append(node('h4','Commerce ledger amounts'));
      money.append(node('p','Amounts use the saved commerce ledger scale of two decimal places. Each currency is reported separately.'));
      if(value.money.length)money.append(analyticsTable(value.money,true));
      else money.append(node('p',value.groups.commerce.coverage==='ready'?'No verified ledger amounts in these periods.':'Ledger amounts are unavailable or incomplete for these periods.'));
      frame.append(money,analyticsCohortDetail(value));
      const backlog=node('section','','vp3-profile-period-backlog');
      backlog.append(node('h4','Current activity and allowance'),analyticsImageCurrent(value.backlog));
      if(value.backlog.owner_requests)backlog.append(responseBacklog(value.backlog.owner_requests));
      for(const point of value.backlog.metrics){
        const row=node('section');row.dataset.profileBacklog=point.key;
        row.append(node('h5',point.key==='current_unresolved_conversations'?'Current unresolved conversations':'Current unanswered visitor requests'));
        row.append(analyticsPoint({...point,observed_value:point.observed_value??null}));
        if(plain(point.definition,600))row.append(node('p',point.definition));
        backlog.append(row);
      }
      backlog.append(node('small','Checked '+new Date(value.backlog.as_of_utc_ms).toISOString().replace('T',' ').replace('.000Z',' UTC')+'. These current counts are separate from the completed periods.'));
      frame.append(backlog);
      const context=currentScope(),chatUrl=localUrl(cfg.chatUrl||endpoint.replace(/\/api\/[^/]+$/,'/chat.php'));
      if(context&&chatUrl){
        const url=new URL(chatUrl,location.origin);url.searchParams.set('agent',context.chatAgentId===0?'system':String(context.chatAgentId));
        url.searchParams.set('conversation_id',String(context.chatConversationId));url.searchParams.set('profile_analytics',value.ref);
        const href=analyticsSource({ref:value.ref,url:url.pathname+url.search},chatUrl)?.href;
        if(href){const link=node('a','Open this exact period');link.href=href;link.dataset.profilePeriodLink='';frame.append(link);}
      }
      target.replaceChildren(frame);
    }
    async function loadAnalytics(mine) {
      content.replaceChildren(heading('Analytics','Completed periods and recorded traffic details.'));
      const modes=node('div','','vp3-profile-period-choices');modes.setAttribute('role','group');modes.setAttribute('aria-label','Analytics views');
      for(const [view,label] of [['periods','Completed periods'],['traffic','Traffic details']]){
        const control=button(label,()=>select({...selection,tab:'analytics',analyticsView:view}));
        control.setAttribute('aria-pressed',String(selection.analyticsView===view));control.dataset.profileAnalyticsView=view;modes.append(control);
      }
      content.append(modes);
      const target=node('section');content.append(target);
      if(selection.analyticsView==='periods'){
        const requestedRef=selection.analyticsRef;
        target.append(node('p','Checking the completed period…','vp3-profile-empty'));
        try {
          const result=await request('analytics_period',requestedRef?{ref:requestedRef}:{});
          if(!current(mine))return;
          const value=analyticsPeriodModel(result.data.analytics_period,requestedRef);
          if(!value)throw new Error('The completed-period response could not be verified.');
          selection.analyticsRef=value.ref;analyticsSelection.analyticsRef=value.ref;
          renderAnalyticsPeriod(target,value,mine);
        } catch(error) {
          if(!current(mine))return;
          if([401,403,419].includes(error?.status))throw error;
          // A period outage must leave the independently authorized traffic
          // view reachable. Authority failures still clear the entire view.
          target.replaceChildren(node('p',errorMessage(error),'vp3-profile-empty'),
            button('Retry completed period',()=>void refresh()));
        }
        return;
      }
      if (!window.VP3ProfileAnalytics?.mountWorkspace) {target.append(node('p','Traffic details are unavailable.','vp3-profile-empty'));return;}
      analyticsView=window.VP3ProfileAnalytics.mountWorkspace(target,{
        readAnalytics:async({propertyId,days,signal})=>{
          const result=await request('analytics',{property_id:propertyId,days},{signal});
          if(!current(mine))throw Object.assign(new Error('Profile view changed.'),{name:'AbortError'});return result.data.analytics;
        },
        readIntelligence:async({signal})=>{
          const result=await request('revenue',{}, {signal});
          if(!current(mine))throw Object.assign(new Error('Profile view changed.'),{name:'AbortError'});return result.data.revenue;
        },onNotice:message=>{if(current(mine))say(plain(message,500));}
      });
    }
    function selectSource(source) {
      if(source?.ref?.startsWith('profile:analytics:')){
        const context=currentScope(),chatUrl=cfg.chatUrl||endpoint.replace(/\/api\/[^/]+$/,'/chat.php');
        const destination=analyticsSource(source,chatUrl);
        if(!context||!destination||destination.chatAgentId!==context.chatAgentId||destination.chatConversationId!==context.chatConversationId)return false;
        return select({tab:'analytics',analyticsView:'periods',analyticsRef:destination.ref});
      }
      const match=/^profile:(conversation|call):([1-9][0-9]*)$/.exec(source?.ref||'');
      if (!match) return false;
      const url=new URL(source.href,location.origin),id=Number(match[2]),publicConversationId=Number(url.searchParams.get('conversation'));
      if (!positive(id)||!positive(publicConversationId)) return false;
      return select({tab:match[1]==='call'?'calls':'conversations',publicConversationId,...(match[1]==='call'?{callId:id}:{})});
    }
    async function loadReports(mine) {
      if (typeof readReports!=='function'||typeof renderReport!=='function') throw new Error('The Profile report is unavailable.');
      const controller=new AbortController();requests.set(controller,false);
      try {
        const value=await readReports({signal:controller.signal});if (!current(mine)||controller.signal.aborted) return;
        content.replaceChildren(heading('Reports','Current source-backed findings and follow-ups.'));
        const target=node('section');content.append(target);renderReport(target,value,{full:true,onSelect:selectSource});
      } finally { requests.delete(controller); }
    }
    async function refresh() {
      if (!alive) return;
      rememberInput();abortReads();disposeTab();callPageBusy=false;outcomeBusy.clear();
      const mine=++generation,context=currentScope();threadEntry=null;selectedData=null;
      if (!context) {content.replaceChildren(node('p','This Chat’s Profile access could not be verified.','vp3-profile-empty'));say('');return;}
      say('Loading current Profile activity…');
      content.replaceChildren(node('p','Loading…','vp3-profile-empty'));
      try {
        if (!context.chatConversationId || selection.tab==='overview') await loadOverview(mine);
        else if (selection.tab==='conversations') {
          if (selection.publicConversationId) await loadConversation(mine,selection.publicConversationId);
          else await loadConversations(mine);
        } else if (selection.tab==='calls') await loadCalls(mine);
        else if (selection.tab==='analytics') await loadAnalytics(mine);
        else await loadReports(mine);
        if(current(mine))say('');
      } catch(error) { if(current(mine))statusError(error,mine); }
    }
    function select(value={}) {
      if (!alive||!currentScope()) return false;
      rememberInput();const next=selected(value);
      const unchanged=JSON.stringify(selection)===JSON.stringify(next);
      selection=next;
      if(selection.tab==='analytics')Object.assign(analyticsSelection,{analyticsView:selection.analyticsView,analyticsRef:selection.analyticsRef});
      for (const [key,control] of controls) {
        const selected=key===selection.tab;control.setAttribute('aria-selected',String(selected));control.tabIndex=selected?0:-1;
      }
      content.setAttribute('aria-labelledby','vp3ProfileWorkspaceTab-'+selection.tab);
      if (!unchanged||!content.childElementCount) {threadData=null;threadEntry=null;conversations=null;void refresh();}
      return true;
    }
    for (const [key,label] of tabs) {
      const control=button(label,()=>select({tab:key}));control.id='vp3ProfileWorkspaceTab-'+key;control.dataset.profileWorkspaceTab=key;
      control.setAttribute('role','tab');control.setAttribute('aria-controls',content.id);controls.set(key,control);tablist.append(control);
    }
    tablist.addEventListener('keydown',event=>{
      if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
      const entries=[...controls.values()],index=entries.indexOf(event.target);if(index<0)return;
      event.preventDefault();const next=event.key==='Home'?0:event.key==='End'?entries.length-1:(index+(event.key==='ArrowLeft'?-1:1)+entries.length)%entries.length;
      entries[next].click();entries[next].focus();
    });
    const tools=node('div','','vp3-profile-tools');tools.append(button('Refresh Profile',()=>void refresh()));
    root.append(tablist,tools,notice,content);host.replaceChildren(root);
    const attemptChanged=(key,id)=>{
      if(alive&&key===initialKey&&currentScope()&&selection.tab==='conversations'&&selection.publicConversationId===id&&threadData)renderThread(threadData,entry(id));
    };
    attemptListeners.add(attemptChanged);
    for (const [key,control] of controls) {control.setAttribute('aria-selected',String(key===selection.tab));control.tabIndex=key===selection.tab?0:-1;}
    content.setAttribute('aria-labelledby','vp3ProfileWorkspaceTab-'+selection.tab);
    void refresh();
    return Object.freeze({select,refresh,dispose(){
      if(!alive)return;rememberInput();alive=false;generation++;attemptListeners.delete(attemptChanged);for(const controller of requests.keys())controller.abort();requests.clear();disposeTab();root.remove();
    }});
  }
  let pendingAnalyticsDestination=null;
  function cancelPendingAnalyticsDestination() {
    pendingAnalyticsDestination=null;
    for(const event of ['vp3:chat-context-ready','vp3:agent-activity-ready'])window.removeEventListener?.(event,openInitialAnalyticsDestination);
  }
  function openInitialAnalyticsDestination() {
    const target=pendingAnalyticsDestination;
    if(!target)return;
    const chat=window.STONEFELLOW_CHAT,continuity=window.STONEFELLOW_CHAT_CONTINUITY;
    if(!chat||chat.userId!==target.ownerUserId||chat.agentId!==target.chatAgentId
      ||chat.workingAttentionBinding!==target.workingAttentionBinding||window.VP3ParticipantContinuity?.selectedContext?.()){
      cancelPendingAnalyticsDestination();return;
    }
    if(!continuity||typeof continuity.profileAnalyticsDestination!=='function')return;
    const original=continuity.profileAnalyticsDestination();
    if(!original||scopeKey(original)!==scopeKey(target)||original.ref!==target.ref){cancelPendingAnalyticsDestination();return;}
    if(continuity.isReady?.()!==true||continuity.isRestoring?.())return;
    if(continuity.agentId?.()!==target.chatAgentId||continuity.conversationId?.()!==target.chatConversationId){
      cancelPendingAnalyticsDestination();return;
    }
    if(!window.VP3AgentActivityHub?.openProfile)return;
    // Consume before opening: close and repeated readiness events cannot reopen the shell.
    cancelPendingAnalyticsDestination();
    window.VP3AgentActivityHub.openProfile({tab:'analytics',analyticsView:'periods',analyticsRef:target.ref});
  }
  function initializeAnalyticsDestination() {
    const cfg=window.VP3_PROFILE_WORKSPACE_V1||{},raw=window.STONEFELLOW_CHAT?.profileAnalyticsDestination;
    const chatUrl=cfg.chatUrl||String(cfg.endpoint||'').replace(/\/api\/[^/]+$/,'/chat.php');
    const parsed=analyticsSource({ref:raw?.ref,href:raw?.href},chatUrl);
    if(!raw||!parsed||!positive(raw.ownerUserId)||raw.ownerUserId!==Number(cfg.ownerKey)
      ||raw.chatAgentId!==cfg.agentId||raw.chatAgentId!==parsed.chatAgentId||raw.chatConversationId!==parsed.chatConversationId
      ||raw.workingAttentionBinding!==cfg.workingAttentionBinding||typeof raw.workingAttentionBinding!=='string'||!raw.workingAttentionBinding)return;
    pendingAnalyticsDestination=Object.freeze({...raw});
    for(const event of ['vp3:chat-context-ready','vp3:agent-activity-ready'])window.addEventListener(event,openInitialAnalyticsDestination);
    window.addEventListener('stonefellow:chat-context-changing',event=>{
      if(event.detail?.initialRestore!==true)cancelPendingAnalyticsDestination();
    });
    window.addEventListener('pagehide',cancelPendingAnalyticsDestination,{once:true});
    openInitialAnalyticsDestination();
  }
  window.VP3ProfileWorkspaceV1=Object.freeze({mountWorkspace,cancelPendingAnalyticsDestination,periodTools:fixedPeriodTools});
  initializeAnalyticsDestination();
  window.dispatchEvent(new Event('vp3:profile-workspace-ready'));
})();
