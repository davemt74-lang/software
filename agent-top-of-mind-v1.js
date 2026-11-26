(() => {
  'use strict';
  if (window.VP3TopOfMindFeedV1) return;
  let host=null,open=false,timer=0,controller=null,generation=0,snapshot=null,error='',closed=false,failures=0;
  let gmailUpdating=false,gmailStatus='';
  let triageView=null,triageRequest=null;
  let renderedHost=null,renderedMarkup='';
  const initialOwner=Number(window.STONEFELLOW_CHAT?.userId||window.VP3_ORB_CONFIG?.ownerKey||window.STONEFELLOW_NOTIFICATION_DRAWER?.userId||0);
  const initialAgent=Number(window.STONEFELLOW_CHAT?.agentId??window.STONEFELLOW_NOTIFICATION_DRAWER?.agentId??window.VP3_ORB_CONFIG?.agentId??0);
  const initialBinding=String(window.STONEFELLOW_CHAT?.workingAttentionBinding||window.STONEFELLOW_NOTIFICATION_DRAWER?.workingAttentionBinding||window.VP3_ORB_CONFIG?.workingAttentionBinding||'');
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const labels={working:'Working',queued:'Queued',interrupted:'Interrupted — worker lease expired',failed:'Failed',superseded:'Sources changed. Waiting for the next review.',cancelled:'Cancelled',paused:'Paused',unknown:'Status unknown',unverified:'Completion not verified',withheld:'Withheld — source or conversation changed',waiting_owner:'Waiting for your review',ready_to_report:'Prepared — waiting to report',completed:'Completed private preparation',assessed:'Assessment complete'};
  const specialistNames={gmail:'Gmail',planning:'Planning & Scheduling',tasks:'Tasks & Lists',
    media:'Media & Entertainment',curiosity:'Curiosity',transcripts:'Transcriptions',canvas:'Canvas Apps',casual:'Casual check-ins',research:'Research',ob1:'OB1',brainstorm:'Brainstorming','image-generator':'Image Generator',
    coding:'Coding Agent',development:'Agent Development',mission:'Missions','connected-sites':'Connected Sites',
    marketing:'Marketing',commerce:'Commerce',profile:'Profile',location:'Location Specialist',operator:'Operator Specialist'};
  const handoffNames={...specialistNames,ob1:'OB1',transcripts:'Transcripts',commerce:'Commerce'};
  function handoffLabel(handoff){
    const known=id=>typeof id==='string'&&Object.hasOwn(handoffNames,id);
    if(!known(handoff?.to)||!Array.isArray(handoff.from)||!handoff.from.length||handoff.from.length>5
      ||!handoff.from.every(known))return '';
    const from=[...new Set(handoff.from)].map(id=>handoffNames[id]);
    return from.join(' + ')+(from.length===1?' result':' results')+' → '+handoffNames[handoff.to];
  }
  const specialistLabels={thinking:'Thinking',working:'Working',waiting_specialist:'Waiting for another agent',
    waiting_agent:'Waiting for another agent',waiting_owner:'Waiting for your review',waiting_reply:'Awaiting your reply',
    waiting_retry:'Waiting to retry',ready:'Ready to review',ready_to_report:'Ready to report',completed:'Complete',
    assessed:'Assessment complete',queued:'Queued',scheduled:'Scheduled',checked:'Checked',idle:'Idle',paused:'Paused',
    armed:'Armed',awaiting_consent:'Awaiting phone approval',interrupted:'Interrupted',blocked:'Blocked',failed:'Failed',unavailable:'Unavailable',cancelled:'Cancelled',
    superseded:'Sources changed',unknown:'Status unknown',unverified:'Completion unverified',withheld:'Source changed'};
  const coordinationLabels={brainstorming:'Brainstorming',planning:'Planning',acting:'Acting',analyzing:'Analyzing',
    reviewing:'Reviewing',coordinating:'Coordinating',
    presenting:'Presenting',checked:'Checked',waiting:'Waiting',idle:'Idle',paused:'Paused',unavailable:'Unavailable'};
  const localSpecialists=['research','ob1','brainstorm','image-generator','coding','development','mission','connected-sites','transcripts'];
  const localState={running:'working',working:'working',thinking:'thinking',queued:'queued',paused:'paused',
    ready:'ready',completed:'completed',failed:'failed',waiting_specialist:'waiting_specialist',waiting_owner:'waiting_owner'};
  const activeSpecialist=state=>state==='thinking'||state==='working';
  const specialistKey=/^[a-z][a-z0-9-]{0,47}(?::[a-z0-9][a-z0-9-]{0,63})?$/;
  const expiredLease=value=>Boolean(value)&&!(Date.parse(value)>Date.now());
  const gmailMonitorLabels={
    scheduled:'Background check scheduled.',
    checked:'Last check completed.',
    working:'Checking Gmail for new messages.',
    overdue:'A scheduled Gmail check is due. It can run in open Chat.',
    retrying:'The last check failed. A retry is scheduled.',
    restart_required:'Restart inbox monitoring in Gmail settings.',
    unavailable:'Gmail monitoring status is unavailable.'
  };
  const triageGroups=['active','waiting','historical','promotion','newsletter','update','receipt','other'];
  const triageAttention={act_soon:'Act soon',review:'Review',read_later:'Read later',informational:'Informational'};
  const triageLabels={personal:'Personal',business:'Business',personal_interest:'Personal interest',mixed:'Mixed',
    conversation:'Conversation',request:'Request',meeting:'Meeting',receipt:'Receipt',update:'Update',newsletter:'Newsletter',
    promotion:'Promotion',other:'Other',individual:'Individual',small_business:'Small business',organization:'Organization',
    automated_service:'Automated service',waiting:'Waiting',unknown:'Unclassified',...triageAttention};
  const isTriageId=value=>typeof value==='string'&&/^[a-f0-9]{64}$/.test(value);
  const participantSelected=()=>!!window.VP3ParticipantContinuity?.selectedContext?.();
  function scope(){
    const cfg=window.STONEFELLOW_NOTIFICATION_DRAWER||{},chat=window.STONEFELLOW_CHAT;
    const owner=Number(chat?.userId||cfg.userId||window.VP3_ORB_CONFIG?.ownerKey||0),agent=Number(chat?.agentId??cfg.agentId??window.VP3_ORB_CONFIG?.agentId??0);
    const binding=String(chat?.workingAttentionBinding||cfg.workingAttentionBinding||window.VP3_ORB_CONFIG?.workingAttentionBinding||'');
    const canonical=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
    const cid=chat?Number(canonical?.conversationId?.()??chat.initialConversationId??0):0;
    if(owner!==initialOwner||agent!==initialAgent||binding!==initialBinding||!owner||!binding
      ||!Number.isSafeInteger(cid)||cid<0||!Number.isSafeInteger(agent)||agent<0)return null;
    return {owner,agent,binding,cid,hasChat:!!chat,endpoint:cfg.topOfMindEndpoint||window.VP3_ORB_CONFIG?.topOfMindEndpoint||'',
      csrf:chat?.csrf||cfg.csrf||window.VP3_ORB_CONFIG?.csrf||''};
  }
  const key=s=>s?JSON.stringify([s.owner,s.agent,s.binding,s.cid]):'';
  const visible=()=>open&&!closed&&!document.hidden&&host?.isConnected;
  function stop(){window.clearTimeout(timer);timer=0;controller?.abort();controller=null;generation++;}
  function clearTriage(){
    triageRequest?.abort();
    triageRequest=null;
    triageView=null;
  }
  function safeUrl(value){try{const u=new URL(String(value||''),location.origin);return String(value||'').startsWith('/')&&!String(value).startsWith('//')&&!String(value).includes('\\')&&u.origin===location.origin?u.pathname+u.search+u.hash:'';}catch{return '';}}
  function safeMeetingUrl(value){
    const local=safeUrl(value);
    if(local)return local;
    try{
      const url=new URL(String(value||''));
      return url.protocol==='https:'&&!url.username&&!url.password?url.href:'';
    }catch{return '';}
  }
  function sourceLink(current,record,label){
    const url=safeUrl(record.chat_url);
    if(!url)return '';
    const cid=record.conversation_id??current.cid;
    const source=current.hasChat&&(record.agent_id===undefined||record.agent_id===current.agent)
      &&Number.isSafeInteger(record.message_id)&&record.message_id>0
      &&Number.isSafeInteger(cid)&&cid>0
      ?` data-top-of-mind-source="${record.message_id}" data-top-of-mind-conversation="${cid}"`:'';
    return `<a${source} href="${esc(url)}">${esc(label)}</a>`;
  }
  function progressMeter(label,state,value){
    if(typeof value==='number'&&Number.isFinite(value)&&value>=0&&value<=100){
      return `<progress class="agent-top-of-mind-meter" aria-label="${esc(label)}" max="100" value="${value}"></progress>`;
    }
    const attributes=activeSpecialist(state)?` role="progressbar" aria-label="${esc(label)}" data-indeterminate`:' aria-hidden="true"';
    return `<div class="agent-top-of-mind-meter agent-top-of-mind-track"${attributes}></div>`;
  }
  function specialistHeading(name,state,value){
    if(!Object.hasOwn(specialistLabels,state))return '';
    return `<div class="agent-top-of-mind-specialist-heading" data-specialist-state="${state}">
      <div><strong>${esc(name)}</strong><span>${specialistLabels[state]}</span></div>
      ${progressMeter(name+' progress',state,value)}</div>`;
  }
  function specialistRows(current){
    const rows=new Map();
    if(Array.isArray(snapshot.specialists)){
      for(const row of snapshot.specialists.slice(0,24)){
        if(Object.hasOwn(specialistNames,row?.id)&&Object.hasOwn(specialistLabels,row.state))rows.set(row.id,row);
      }
    }
    if(!rows.has('research')){
      const research=[snapshot.artist_research,snapshot.topic_research]
        .find(result=>typeof result?.artist==='string'||typeof result?.topic==='string');
      const state={ready:'ready',waiting_owner:'waiting_owner',mission_ready:'ready',no_matches:'completed',
        unavailable:'unavailable'}[research?.status];
      if(state)rows.set('research',{id:'research',state});
    }
    const orb=window.VP3AgentOrb;
    const ref=orb?.activityContext?.();
    // The embedded Chat can be following a different conversation than this snapshot.
    const entries=current.hasChat&&current.cid>0?window.VP3ChatSpecialists?.snapshot?.({includeResults:true})
      :Number(ref?.id)>0&&Number(ref.id)===snapshot.conversation_id&&Number(ref.agent)===current.agent
        ?orb?.activitySpecialists?.({includeResults:true}):null;
    const local=new Map();
    if(Array.isArray(entries)){
      for(const entry of entries.slice(0,12)){
        if(typeof entry?.key!=='string'||!specialistKey.test(entry.key))continue;
        const [id]=entry.key.split(':');
        if(!localSpecialists.includes(id))continue;
        const state=Object.hasOwn(localState,entry.state)?localState[entry.state]
          :['connected-sites','transcripts'].includes(id)&&Object.hasOwn(specialistLabels,entry.state)?entry.state:'';
        if(!state)continue;
        if(!local.has(id))local.set(id,[]);
        local.get(id).push({...entry,state});
      }
    }
    const priority=['working','thinking','queued','waiting_specialist','waiting_owner','waiting_reply','paused','ready','completed','checked','failed','unavailable','idle'];
    for(const [id,entries] of local){
      const ordered=entries.sort((a,b)=>priority.indexOf(a.state)-priority.indexOf(b.state));
      const {state,progress}=ordered[0];
      const row=rows.get(id);
      const live=activeSpecialist(state)&&['idle','ready','completed'].includes(row?.state);
      rows.set(id,{...(row||{id}),...(!row||live?{state,progress,detail:'',lease_expires_at:''}:{}),entries:ordered});
    }
    return rows;
  }
  function renderSpecialist(current,row,contents){
    const name=specialistNames[row.id];
    const conversation=row.conversation_id??snapshot.conversation_id;
    const href=safeUrl(row.result_url);
    let result='';
    if(href&&Number.isSafeInteger(conversation)&&conversation>0&&Number.isSafeInteger(row.message_id)&&row.message_id>0){
      const url=new URL(href,location.origin);
      if(url.pathname.endsWith('/chat.php')&&url.searchParams.get('agent')===String(current.agent||'system')
        &&url.searchParams.get('conversation_id')===String(conversation)&&url.searchParams.get('message_id')===String(row.message_id)){
        result=sourceLink(current,{chat_url:href,conversation_id:conversation,message_id:row.message_id},'View in Chat');
      }
    }
    const entries=row.entries?.map(entry=>{
      const title=[entry.label===name?'':entry.label,entry.title].filter(value=>typeof value==='string'&&value).join(' · ');
      return `<li>${title?`<strong>${esc(title)}</strong>`:''}
        ${typeof entry.status==='string'?`<small>${esc(entry.status)}</small>`:''}
        ${typeof entry.detail==='string'&&entry.detail?`<small>${esc(entry.detail)}</small>`:''}
        ${entry.canView===true?`<button type="button" data-top-of-mind-specialist-view="${entry.key}">${esc(typeof entry.viewLabel==='string'&&entry.viewLabel||'View in Chat')}</button>`:''}</li>`;
    }).join('');
    const profileCoverage={ready:'Profile sources checked',limited:'Profile sources partially checked',
      unavailable:'Profile sources unavailable',unconfigured:'Profile activity is not configured or available'};
    const profile=row.id==='profile'
      ? `${Object.hasOwn(profileCoverage,row.source_coverage)?`<small>${profileCoverage[row.source_coverage]}</small>`:''}
        ${current.hasChat?'<button type="button" data-top-of-mind-profile-report>Open Profile report</button>':''}`:'';
    return `<section class="agent-top-of-mind-context agent-top-of-mind-specialist" aria-label="${esc(name)}" data-top-of-mind-specialist="${row.id}">
      ${specialistHeading(name,row.state,row.progress)}
      ${typeof row.detail==='string'&&row.detail?`<p>${esc(row.detail)}</p>`:''}
      ${profile}${result}${entries?`<ul aria-label="${esc(name)} work">${entries}</ul>`:''}${renderOpenEngagements(current,row)}${contents}</section>`;
  }
  function transcriptReportRows(row){
    const reports=[];
    const ids=new Set();
    for(const item of Array.isArray(row?.transcript_reports)?row.transcript_reports.slice(0,4):[]){
      const id=item?.session_id, href=safeUrl(item?.url);
      if(!Number.isSafeInteger(id)||id<1||ids.has(id)||!href||typeof item.title!=='string'||!item.title.trim()
        ||typeof item.summary!=='string'||!item.summary.trim())continue;
      const url=new URL(href,location.origin);
      if(!/\/artist-listening(?:-evidence)?\.php$/.test(url.pathname)||url.searchParams.get('session')!==String(id))continue;
      ids.add(id);
      reports.push({id,href,title:item.title.slice(0,190),summary:item.summary.slice(0,1200),
        nextStep:typeof item.next_step==='string'?item.next_step.slice(0,320):'',
        question:typeof item.question==='string'?item.question.slice(0,320):''});
    }
    return reports;
  }
  function transcriptReportsMarkup(row){
    const reports=transcriptReportRows(row);
    if(!reports.length)return '';
    return '<ul aria-label="Saved transcript findings">'+reports.map(report=>`<li><strong>${esc(report.title)}</strong>
      <p>${esc(report.summary)}</p>
      ${report.nextStep?`<p><b>Next step </b>${esc(report.nextStep)}</p>`:''}
      ${report.question?`<p><b>Question for you </b>${esc(report.question)}</p>`:''}
      <a href="${esc(report.href)}">Open source finding</a></li>`).join('')+'</ul>';
  }
  function renderOpenEngagements(current,row){
    const unavailable=row?.open_engagements_status==='unavailable';
    if(!Array.isArray(row?.open_engagements))return unavailable?'<p>Open questions could not be verified.</p>':'';
    const items=[];
    for(const item of row.open_engagements.slice(0,30)){
      const href=safeUrl(item?.result_url);
      if(!href||item.conversation_id!==snapshot.conversation_id||!Number.isSafeInteger(item.message_id)||item.message_id<1
        ||typeof item.question!=='string'||!item.question.trim())continue;
      const url=new URL(href,location.origin);
      if(!url.pathname.endsWith('/chat.php')||url.searchParams.get('agent')!==String(current.agent||'system')
        ||url.searchParams.get('conversation_id')!==String(item.conversation_id)||url.searchParams.get('message_id')!==String(item.message_id))continue;
      items.push(`<li><p>${esc(item.question.slice(0,1000))}</p>${sourceLink(current,{chat_url:href,
        conversation_id:item.conversation_id,message_id:item.message_id},'View in Chat')}</li>`);
    }
    if(!items.length)return unavailable?'<p>Open questions could not be verified.</p>':'';
    const expanded=host?.querySelector(`[data-top-of-mind-engagements="${row.id}"]`)?.open===true;
    const hasMore=Number.isSafeInteger(row.open_engagements_next_before)&&row.open_engagements_next_before>0;
    return `<details data-top-of-mind-engagements="${row.id}"${expanded?' open':''}>
      <summary>Awaiting your reply (${items.length}${hasMore?'+':''})</summary><ul>${items.join('')}</ul>
      ${unavailable?'<p>Some open questions could not be verified.</p>':''}</details>`;
  }
  function emailTime(value,label){
    const time=Date.parse(value);
    return Number.isFinite(time)?`<small>${label} <time datetime="${esc(value)}">${esc(new Date(time)
      .toLocaleString(undefined,{dateStyle:'short',timeStyle:'short'}))}</time></small>`:'';
  }
  function triageDue(item){
    if(item.due_at)return emailTime(item.due_at,'Due');
    if(typeof item.due_date!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(item.due_date))return '';
    const day=new Date(item.due_date+'T00:00:00Z');
    return Number.isFinite(day.getTime())?`<small>Due <time datetime="${esc(item.due_date)}">${esc(day.toLocaleDateString(undefined,
      {dateStyle:'medium',timeZone:'UTC'}))}</time></small>`:'';
  }
  const emailCategories={action_required:'Action needed',opportunity:'Opportunity',decision:'Decision',
    routine:'Routine update',newsletter:'Newsletter',automated:'Automated notice',likely_junk:'Likely junk'};
  const emailPriorities={high:0,normal:1,low:2};
  const emailText=value=>typeof value==='string'?value.trim().slice(0,1000):'';
  function emailSourceButton(current,monitor,item){
    if(!Number.isSafeInteger(monitor.triage?.generation)||monitor.triage.generation<1
      ||(!current.hasChat&&!triageChatUrl(current))||!isTriageId(item.item_ref))return '';
    const subject=emailText(item.subject)||'(No subject)';
    const label=item.source_is_evidence===false?'Open latest message':'Open source email';
    return `<button type="button" data-gmail-report-item="${item.item_ref}" aria-label="${label} ${esc(subject)}">${label}</button>`;
  }
  function emailHandoffs(current,monitor,key,covered=new Set()){
    const items=monitor?.report?.handoffs?.[key];
    if(!Array.isArray(items))return '';
    return items.filter(item=>emailText(item?.summary)&&Object.hasOwn(emailCategories,item.category)&&!covered.has(item.item_ref)).map(item=>{
      const next=emailText(item.handoff_question)||emailText(item.followup_question)||emailText(item.next_step);
      return `<li><small>${esc(['Gmail',emailCategories[item.category],item.priority==='high'?'High priority':''].filter(Boolean).join(' · '))}</small>
        <strong>${esc(emailText(item.summary))}</strong>${next?`<p>${esc(next)}</p>`:''}${emailSourceButton(current,monitor,item)}</li>`;
    }).join('');
  }
  function emailReport(current,monitor,expanded=false){
    const report=monitor?.report?.running_report;
    const findings=(Array.isArray(report?.findings)?report.findings:[]).filter(item=>emailText(item?.summary)
      &&Object.hasOwn(emailCategories,item.category)&&Object.hasOwn(emailPriorities,item.priority)
      &&['open','resolved','informational'].includes(item.state));
    const groups=new Map([['Needs attention',[]],['Opportunities',[]],['Decisions',[]],['Follow-ups',[]],['Recent updates',[]]]);
    for(const item of findings){
      const group=item.category==='opportunity'?'Opportunities':item.category==='decision'?'Decisions'
        :item.category==='action_required'||item.handoff==='needs_attention'?'Needs attention'
        :item.state==='open'&&(emailText(item.followup_question)||emailText(item.next_step))?'Follow-ups'
        :item.state==='open'?'Needs attention':'Recent updates';
      groups.get(group).push(item);
    }
    for(const items of groups.values()){
      items.sort((left,right)=>Number(right.state==='open')-Number(left.state==='open')
        ||emailPriorities[left.priority]-emailPriorities[right.priority]);
    }
    const itemMarkup=item=>{
      const labels=[item.priority==='high'?'High priority':'',item.state==='resolved'?'Resolved':''].filter(Boolean);
      const question=emailText(item.followup_question),next=emailText(item.next_step),why=emailText(item.importance_reason);
      const research=emailText(item.handoff_question);
      return `<li><strong>${esc(emailText(item.summary))}</strong>
        ${labels.length?`<small>${esc(labels.join(' · '))}</small>`:''}
        ${why&&(expanded||(!next&&!question))?`<p>${esc(why)}</p>`:''}
        ${next?`<p><b>Next step</b> ${esc(next)}</p>`:''}
        ${question?`<p><b>Question for you</b> ${esc(question)}</p>`:''}
        ${research&&research!==question?`<p><b>Research question</b> ${esc(research)}</p>`:''}
        ${item.updating===true?'<small>Newer email hasn’t been analyzed yet.</small>':''}
        ${emailSourceButton(current,monitor,item)}</li>`;
    };
    const summary=emailText(report?.summary);
    const pending=Number.isSafeInteger(report?.pending)&&report.pending>0;
    const failures=Number.isSafeInteger(report?.unavailable)&&report.unavailable>0;
    const analysisState=monitor?.analysis_state;
    const stages=Array.isArray(monitor?.diagnostics?.stages)?monitor.diagnostics.stages:[];
    const contentStage=stages.find(stage=>stage?.label==='Content analysis')?.state;
    let notice='';
    if(analysisState==='disabled')notice='Inbox content analysis is off. Turn it on in Gmail settings.';
    else if(analysisState==='setup_required')notice=emailText(stages.find(stage=>stage?.label==='Gmail setup')?.detail)
      ||'Gmail setup needs attention. Open Gmail settings to continue.';
    else if(monitor?.state==='restart_required')notice='Gmail inbox access needs to be refreshed in settings.';
    else if(contentStage==='retrying')notice='Inbox analysis is waiting to retry.';
    else if(contentStage==='queued')notice='Email is queued for analysis. Findings appear here once it has been reviewed.';
    else if(analysisState==='processing'||pending||monitor?.state==='working')notice='Inbox analysis is in progress. Findings appear here as email is reviewed.';
    else if(analysisState==='unavailable'||failures||monitor?.state==='unavailable')notice=findings.length
      ?'Some email could not be analyzed. The available findings are shown here.'
      :'The specialist couldn’t analyze the available email.';
    else if(analysisState==='empty')notice='There are no findings from the latest inbox analysis.';
    else if(analysisState==='waiting'&&!findings.length&&!summary)notice='No email has been analyzed yet. Analyze your recent inbox to get started.';
    else if(!findings.length&&!summary)notice=report?'No email has been analyzed yet.':'Inbox analysis is unavailable. Check Gmail settings.';
    let remaining=expanded?Infinity:3;
    const sections=[];
    for(const [group,items] of groups){
      if(!items.length||remaining<=0)continue;
      const selected=items.slice(0,expanded?items.length:1);
      sections.push(`<section class="vp3-email-report-group" aria-label="${group}"><h5>${group}</h5>
        <ul>${selected.map(itemMarkup).join('')}</ul></section>`);
      remaining-=selected.length;
    }
    return `<section class="vp3-email-report" aria-label="Inbox analysis"><h4>Inbox analysis</h4>
      ${summary?`<p class="vp3-email-report-summary">${esc(summary)}</p>`:''}
      ${sections.join('')}${notice?`<p role="status" class="vp3-email-report-status">${esc(notice)}</p>`:''}</section>`;
  }
  function renderTriageItem(item,view,current){
    const subject=item.subject||'(No subject)';
    const facets=[item.context,item.purpose,item.sender].filter(value=>value!=='unknown')
      .map(value=>Object.hasOwn(triageLabels,value)?triageLabels[value]:'').filter(Boolean);
    const priority=Object.hasOwn(triageLabels,item.attention)?triageLabels[item.attention]:'Unclassified';
    const editor=view.editor?.id===item.id?view.editor:null;
    const disabled=view.phase==='saving'?' disabled':'';
    const canRemember=typeof item.from==='string'&&item.from.includes('@');
    const open=current.hasChat||triageChatUrl(current)?`<button type="button" data-top-of-mind-triage="open"
      data-item-id="${item.id}" data-triage-focus="open-${item.id}" aria-label="Open in Chat ${esc(subject)}"
      ${current.hasChat?'aria-haspopup="dialog"':''}${disabled}>Open in Chat</button>`:'';
    const edit=editor?`<form data-top-of-mind-triage-form aria-label="Change priority for ${esc(subject)}">
      <label>Priority <select name="attention" data-triage-focus="attention"${disabled}>
        ${Object.entries(triageAttention).map(([value,label])=>`<option value="${value}"${value===editor.attention?' selected':''}>${label}</option>`).join('')}
      </select></label>
      ${canRemember?`<label><input type="checkbox" name="remember_sender" data-triage-focus="remember"${editor.remember?' checked':''}${disabled}> Apply to future mail from this sender</label>`:''}
      <div><button type="submit" data-triage-focus="save"${disabled}>Save priority</button>
      <button type="button" data-top-of-mind-triage="cancel" data-triage-focus="cancel"${disabled}>Cancel</button></div>
    </form>`:`<button type="button" data-top-of-mind-triage="edit" data-item-id="${item.id}"
      data-triage-focus="edit-${item.id}"${disabled}>Change priority<span class="agent-top-of-mind-sr-only"> for ${esc(subject)}</span></button>`;
    return `<li><strong>${esc(subject)}</strong><small>${esc([item.from,priority,...facets].filter(Boolean).join(' · '))}</small>
      ${item.reason?`<p>${esc(item.reason)}</p>`:''}
      ${emailTime(item.received_at,'Received')}${triageDue(item)}
      ${open}${edit}</li>`;
  }
  function renderTriage(current,triage){
    if(!current.csrf||!gmailApiUrl(current)||!Number.isSafeInteger(triage?.generation)||triage.generation<1||!Array.isArray(triage.groups))return '';
    const groups=triage.groups.filter(item=>triageGroups.includes(item?.key)&&typeof item.label==='string'
      &&Number.isSafeInteger(item.count)&&item.count>0);
    const view=triageView?.mailbox===triage.generation?triageView:null;
    if(view&&!groups.some(item=>item.key===view.group))groups.push({key:view.group,label:view.label,count:0});
    if(!groups.length&&!view)return '';
    const groupButtons=items=>items.map(item=>`<button type="button" data-top-of-mind-triage="group"
      data-group="${item.key}" data-triage-focus="group-${item.key}" aria-expanded="${view?.group===item.key}"
      ${view?.phase==='saving'?'disabled':''}>${esc(item.label)} <span>${item.count}</span></button>`).join('');
    const live=groups.filter(item=>item.key!=='historical'),history=groups.filter(item=>item.key==='historical');
    let result='<section class="agent-top-of-mind-triage" aria-label="Email triage"><strong>Email triage</strong>';
    if(live.length)result+=`<div class="agent-top-of-mind-triage-groups" role="group" aria-label="Current inbox">${groupButtons(live)}</div>`;
    if(history.length)result+=`<div class="agent-top-of-mind-triage-groups" role="group" aria-label="Earlier mail">${groupButtons(history)}</div>`;
    if(view){
      result+=`<section class="agent-top-of-mind-triage-page" aria-label="${esc(view.label)} messages"
        aria-busy="${view.phase==='loading'||view.phase==='saving'}"><strong tabindex="-1" data-triage-focus="page">${esc(view.label)}</strong>`;
      if(view.status)result+=`<p role="status">${esc(view.status)}</p>`;
      if(view.phase==='loading')result+='<p role="status">Loading messages…</p>';
      if(view.items.length)result+=`<ul>${view.items.map(item=>renderTriageItem(item,view,current)).join('')}</ul>`;
      if(view.phase==='ready'&&!view.items.length)result+='<p>This group is empty.</p>';
      if(view.phase==='failed'){
        result+='<button type="button" data-top-of-mind-triage="first" data-triage-focus="first">Reload group</button>';
      }else if(view.phase==='ready'){
        if(view.trail.length>1)result+='<button type="button" data-top-of-mind-triage="previous" data-triage-focus="previous">Previous page</button>';
        if(view.next)result+='<button type="button" data-top-of-mind-triage="next" data-triage-focus="next">Next page</button>';
      }
      result+='</section>';
    }
    return result+'</section>';
  }
  function gmailFindings(current,monitor,showClosed,showScan){
    const scan=monitor.scan,matters=monitor.matters;
    const count=value=>Number.isSafeInteger(value)&&value>=0?value:0;
    let result='';
    if(showScan&&scan&&Number.isFinite(Date.parse(scan.at))){
      const total=count(scan.total);
      const since=Number.isFinite(Date.parse(scan.from))?' since the previous scan':' in the first completed scan';
      result+=`<p>${total} new Gmail message${total===1?'':'s'} reviewed${since} · ${count(scan.important)} marked important · ${count(scan.replies)} replies</p>`;
    }
    if(scan?.in_progress)result+='<small>Checking more mailbox changes.</small>';
    const stages={needs_review:'Needs review',awaiting_reply:'Waiting for a reply',reply_received:'Reply received',
      actioned:'Action verified',followup_due:'Follow-up review due',snoozed:'Snoozed',dismissed:'Dismissed',resolved:'Resolved'};
    const buttons=(item,decisions)=>current.csrf&&/^[a-f0-9]{64}$/.test(item.id)&&Number.isSafeInteger(item.revision)
      ?decisions.map(([decision,label])=>`<button type="button" data-top-of-mind-email="${decision}" data-matter-id="${item.id}"
        ${gmailUpdating?'disabled':''}>${label}</button>`).join(''):'';
    const formatItem=(item,decisions)=>{
      const timeLine=(label,value)=>{
        const time=Date.parse(value);
        return Number.isFinite(time)?`<small>${label} ${esc(new Date(time).toLocaleString(undefined,{dateStyle:'short',timeStyle:'short'}))}</small>`:'';
      };
      const waiting=item.stage==='awaiting_reply'&&typeof item.waiting_for==='string'?`<small>Waiting for ${esc(item.waiting_for)}</small>`:'';
      const mailbox=[item.sent_in_gmail?'Reply sent in Gmail':'',item.mailbox_state==='archived'?'Archived in Gmail':''].filter(Boolean);
      const next=timeLine('Next check',item.next_check_at);
      const wake=timeLine(item.stage==='snoozed'?'Snoozed until':'Watch until',item.next_wake_at);
      const tracking=item.response_followthrough;
      const followthrough=tracking?.agent_id===current.agent&&/^[a-f0-9]{32}$/.test(tracking.followthrough_ref)
        &&Number.isSafeInteger(tracking.conversation_id)&&tracking.conversation_id>0?tracking:null;
      const outcome=followthrough?.paused?'Tracking paused':followthrough?.awaiting_new_deadline?'Waiting for a reply; choose a new deadline for another draft'
        :({waiting:'Waiting for a reply',overdue:'Response overdue',replied:'Reply verified',resolved:'Tracking resolved'}[followthrough?.outcome]||'');
      const tracked=followthrough&&outcome?timeLine('Response deadline',followthrough.deadline_at)
        +(followthrough.check?.state==='verified'&&followthrough.check?.result==='no_reply'
          ?timeLine('No matching reply in the checked thread as of',followthrough.check.checked_at):''):'';
      const stageLabel=followthrough&&outcome?outcome:stages[item.stage];
      const watchDetails=followthrough&&outcome?next:waiting+next+wake;
      let controls=buttons(item,decisions);
      if(followthrough){
        const chatUrl=safeUrl(window.STONEFELLOW_CHAT?.chatUrl||window.VP3_ORB_CONFIG?.chatUrl||'/chat.php')||'/chat.php';
        const manage=new URL(chatUrl,location.origin);
        manage.search='';manage.searchParams.set('agent',current.agent===0?'system':String(current.agent));
        manage.searchParams.set('conversation_id',String(followthrough.conversation_id));
        manage.searchParams.set('gmail_followthrough',followthrough.followthrough_ref);
        controls=`<a href="${esc(manage.pathname+manage.search)}">Manage follow-up</a>`;
      }
      const reply=item.reply_result?.agent_id===current.agent?sourceLink(current,item.reply_result,'Read the reply in Chat'):'';
      return `<li>${esc(item.subject||'(No subject)')} · ${esc(stageLabel)}${watchDetails}${tracked}${reply}
        ${mailbox.length?`<small>${esc(mailbox.join(' · '))}</small>`:''}${controls}</li>`;
    };
    const items=Array.isArray(matters?.items)?matters.items.filter(item=>Object.hasOwn(stages,item?.stage)).slice(0,3):[];
    if(items.length){
      const actions=[['snooze','Snooze 1 hour'],['dismiss','Dismiss'],['resolve','Mark resolved']];
      result+=`<ul aria-label="Open email matters">${items.map(item=>formatItem(item,item.stage==='snoozed'?[['reopen','Resume']]:actions)).join('')}</ul>`;
    }
    const closed=Array.isArray(matters?.closed)?matters.closed.filter(item=>['dismissed','resolved'].includes(item?.stage)).slice(0,3):[];
    if(closed.length)result+=`<details data-top-of-mind-email-closed${showClosed?' open':''}><summary>Recently closed email matters</summary>
      <ul>${closed.map(item=>formatItem(item,[['reopen','Reopen']])).join('')}</ul></details>`;
    if(gmailStatus)result+=`<small role="status">${esc(gmailStatus)}</small>`;
    return result;
  }
  function renderGmail(current,monitor,openDetails,row,contents=''){
    if(!Object.hasOwn(gmailMonitorLabels,monitor?.state))return '';
    const working=monitor.state==='working'&&Date.parse(monitor.lease_until)>Date.now();
    const state=monitor.state==='working'&&!working?'overdue':monitor.state;
    const settings=safeUrl(monitor.settings_url);
    const times=[['Last check',monitor.last_checked_at],['Next check',monitor.next_check_at]].map(([label,value])=>{
      const time=Date.parse(value);
      return Number.isFinite(time)?`<small>${label} <time datetime="${esc(value)}">${esc(new Date(time).toLocaleString(undefined,{dateStyle:'medium',timeStyle:'short'}))}</time></small>`:'';
    }).join('');
    const sync=monitor.sync;
    const coverage=sync?.status==='pending'?(sync.mode==='recovery'?'Recovering the Gmail review.':'Catching up on earlier mail.')
      :sync?.status==='truncated'?'The Gmail review reached its scan limit. Some earlier messages may still need review.':'';
    const expired=(monitor.state==='working'&&!working)||monitor.overdue_reason==='claim_expired';
    const retryDue=monitor.overdue_reason==='retry_due';
    const overdueState=expired?'interrupted':retryDue?'waiting_retry':'queued';
    const detail=state!=='overdue'?gmailMonitorLabels[state]:expired?'The inbox check was interrupted. It can resume in open Chat.'
      :retryDue?'The last inbox check failed. Its retry is due.':gmailMonitorLabels.overdue;
    const monitorState={scheduled:'scheduled',checked:'idle',working:'working',overdue:overdueState,
      retrying:'waiting_retry',restart_required:'blocked',unavailable:'unavailable'}[state];
    const workState=row?.state || monitorState;
    return `<section class="agent-top-of-mind-context agent-top-of-mind-specialist" aria-label="Gmail inbox monitoring"
      data-top-of-mind-specialist="gmail" data-work-state="${workState}">
      ${specialistHeading(specialistNames.gmail,workState,row?.progress)}
      <details data-gmail-monitor-details${openDetails.monitor?' open':''}><summary>Inbox monitoring details</summary>
      <p>${detail}</p>${times}${coverage?`<small>${coverage}</small>`:''}
      ${monitor.pending===true?'<small>An inbox update is waiting to post to Chat.</small>':''}
      ${monitor.latest?.agent_id===current.agent?sourceLink(current,monitor.latest,'View latest update'):''}
      ${settings?`<a href="${esc(settings)}">${sync?.status==='truncated'&&sync.can_continue===true?'Continue in Gmail settings':'Gmail settings'}</a>`:''}
      ${gmailFindings(current,monitor,openDetails.closed,!monitor.report?.running_report)}${renderTriage(current,monitor.triage)}</details>${contents}
    </section>`;
  }
  function renderCalendar(entry,openAgenda){
    if(!entry||typeof entry.title!=='string')return '';
    const review=safeUrl(entry.review_url),meeting=safeMeetingUrl(entry.meeting_url);
    let agenda='';
    if(entry.agenda_status!=='available'){
      agenda=`<small>${entry.agenda_status==='empty'?'No agenda items are saved for this event.':'Saved agenda details are unavailable.'}</small>`;
    }
    for(const [label,key] of [['Open','open_items'],['Completed','completed_items'],['Status unknown','unknown_items']]){
      const items=Array.isArray(entry[key])?entry[key].slice(0,6):[];
      if(items.length)agenda+=`<small>${label}</small><ul>${items.map(item=>`<li>${esc(item)}</li>`).join('')}</ul>`;
    }
    if(entry.items_truncated)agenda+='<small>Open calendar details to see more agenda items.</small>';
    return `<section class="agent-top-of-mind-context" aria-label="Calendar preparation">
      <strong>Next on your calendar</strong><p>${esc(entry.title)}</p><small>${esc(entry.starts_label)}</small>
      ${agenda?`<details data-top-of-mind-agenda="${esc(entry.occurrence_id)}"${openAgenda===entry.occurrence_id?' open':''}><summary>Agenda and preparation</summary>${agenda}</details>`:''}
      ${review&&review!==meeting?`<a href="${esc(review)}">Review calendar details</a>`:''}
      ${meeting?`<a href="${esc(meeting)}"${meeting.startsWith('https:')?' target="_blank" rel="noopener noreferrer"':''}>Open meeting</a>`:''}
    </section>`;
  }
  function renderYoutube(current,search,artist,topic){
    let markup='';
    if(search&&typeof search.query==='string'){
      const kind=search.kind==='playlist'?'YouTube playlist draft':'YouTube search';
      markup+=`<section class="agent-top-of-mind-context" aria-label="Recent YouTube results">
        <strong>${kind}</strong><p>${esc(search.query)}</p><small>${search.conversation_id===current.cid?'This Chat':'Agent History'} · ${esc(search.result_count)} results</small>
        ${sourceLink(current,search,'Review these results')}</section>`;
    }
    for(const [kind,research] of [['artist',artist],['topic',topic]]){
      if(typeof research?.[kind]!=='string')continue;
      const label=kind==='artist'?'Artist research':'Topic research';
      const status={ready:'Research is ready to review.',waiting_owner:'Specialist plan is ready for review.',mission_ready:'The specialist Mission is available.',no_matches:'The last research found no verified results.',unavailable:'The last research could not finish.'}[research.status];
      if(!status)continue;
      markup+=`<section class="agent-top-of-mind-context" aria-label="${label}">
        <strong>${label}</strong><p>${esc(research[kind])}</p><small>${status}</small>
        ${sourceLink(current,research,'Review in Chat')}</section>`;
    }
    return markup;
  }
  function youtubePlaylistEntries(activity){
    if(activity?.status!=='ready'||!Array.isArray(activity.playlist))return [];
    const seen=new Set(),entries=[];
    for(const entry of activity.playlist.slice(0,18)){
      const id=entry?.video_id,action=entry?.action;
      if(typeof id!=='string'||!/^[A-Za-z0-9_-]{11}$/.test(id)||seen.has(id)
        ||typeof entry.title!=='string'||!entry.title.trim()||entry.title.length>200
        ||typeof entry.channel!=='string'||entry.channel.length>120
        ||!Number.isSafeInteger(entry.expires_at)||entry.expires_at<=Date.now()/1000
        ||action?.kind!=='chat'||!Number.isSafeInteger(action.agent_id)||action.agent_id<0
        ||!Number.isSafeInteger(action.conversation_id)||action.conversation_id<1
        ||!Number.isSafeInteger(action.message_id)||action.message_id<1)continue;
      const href=safeUrl(action.href);
      if(!href)continue;
      const source=new URL(href,location.origin);
      if(!source.pathname.endsWith('/chat.php')||source.searchParams.get('agent')!==String(action.agent_id||'system')
        ||source.searchParams.get('conversation_id')!==String(action.conversation_id)
        ||source.searchParams.get('message_id')!==String(action.message_id))continue;
      seen.add(id);
      entries.push({...entry,action:{...action,href},watch_url:'https://www.youtube.com/watch?v='+id});
    }
    return entries;
  }
  function youtubePlaylistEmptyText(activity){
    if(activity?.status==='unavailable')return 'YouTube history could not be loaded. Check YouTube in Connections.';
    if(activity?.searches?.length)return 'Your saved searches are listed below. Their video results are currently unavailable.';
    if(activity?.played?.length)return 'Your played videos are listed below. This playlist uses results from YouTube searches in Agent Chat.';
    return 'Videos from YouTube searches in Agent Chat will appear here.';
  }
  function renderYoutubePlaylist(current,activity){
    if(!['ready','unavailable'].includes(activity?.status))return '';
    const playlist=youtubePlaylistEntries(activity).slice(0,6).map(entry=>{
      const source=sourceLink(current,{...entry.action,chat_url:entry.action.href},'Open in Chat');
      return `<li data-top-of-mind-youtube-expires="${entry.expires_at}"><strong>${esc(entry.title)}</strong>
        ${entry.channel?`<small>${esc(entry.channel)}</small>`:''}${source}
        <a href="${esc(entry.watch_url)}" target="_blank" rel="noopener noreferrer">Play on YouTube</a></li>`;
    });
    const empty=youtubePlaylistEmptyText(activity);
    return `<section class="agent-top-of-mind-context" aria-label="YouTube search history playlist">
      <strong>YouTube search history playlist</strong>${playlist.length?`<ol>${playlist.join('')}</ol>`:`<p>${empty}</p>`}</section>`;
  }
  function renderYoutubeActivity(current,activity,music){
    if(activity?.status!=='ready')return '';
    const groups=[['searches','Recent YouTube searches'],['played','Played videos']];
    const contents=groups.map(([key,label])=>{
      const rows=(Array.isArray(activity[key])?activity[key]:[]).slice(0,3).flatMap(item=>{
        const action=item?.actions?.[0];
        if(typeof item?.title!=='string'||!item.title||!Number.isSafeInteger(action?.agent_id)
          ||action.agent_id<0||typeof action.label!=='string')return [];
        const link=sourceLink(current,{...action,chat_url:action.href},action.label);
        if(!link)return [];
        return [`<li><p>${esc(item.title)}</p>${item.detail?`<small>${esc(item.detail)}</small>`:''}${link}</li>`];
      });
      return rows.length?`<section aria-label="${label}"><strong>${label}</strong><ul>${rows.join('')}</ul></section>`:'';
    }).join('');
    if(!contents)return '';
    const open=host.querySelector('[data-top-of-mind-youtube-activity]')?.open??true;
    const picks=music?.music_suggestion&&current.hasChat&&typeof window.STONEFELLOW_CHAT_CONTINUITY_V87?.requestYoutubePicks==='function'
      ?'<button type="button" data-top-of-mind-picks>Ask for YouTube music picks</button><small data-top-of-mind-music-status role="status"></small>':'';
    return `<details data-top-of-mind-youtube-activity${open?' open':''}><summary>Recent YouTube activity</summary>${contents}${picks}</details>`;
  }
  function renderYoutubeInterest(interest){
    if(!interest)return '';
    const positive=value=>Number.isSafeInteger(value)&&value>0?value:0;
    const searches=positive(interest.search_count),related=positive(interest.related_search_count);
    const sessions=positive(interest.watch_sessions),seconds=positive(interest.watch_seconds);
    const facts=[];
    if(related>1)facts.push(`${related} related YouTube searches`);
    else if(searches>1)facts.push(`${searches} searches for this topic on YouTube`);
    else if(searches===1)facts.push('A YouTube search');
    if(seconds>0){
      const duration=seconds<60?`${seconds} sec`:`about ${Math.round(seconds/60)} min`;
      facts.push(`${duration} watched${sessions>1?` across ${sessions} sessions`:''}`);
    }
    if(interest.owner_reply===true)facts.push('Your reply to the check-in');
    return facts.length?`<small>Why this came up · ${esc(facts.join(' · '))}</small>`:'';
  }
  function listeningSourceLink(entries){
    const actions=Array.isArray(entries)?entries.slice(0,2):[];
    const site=actions.find(action=>action?.kind==='stoneward_history'
      &&typeof action.href==='string'&&/^https:\/\/[a-z0-9.-]{1,253}\/\?view=history$/i.test(action.href));
    const local=safeUrl(actions.find(action=>action?.kind==='url')?.href);
    if(site)return `<a href="${esc(site.href)}" target="_blank" rel="noopener noreferrer">Open listening history</a>`;
    return local?`<a href="${esc(local)}">Review track</a>`:'';
  }
  function renderRecentListening(listening){
    if(!Array.isArray(listening))return '';
    const rows=listening.slice(0,4).flatMap(item=>{
      if(typeof item?.title!=='string'||!item.title||item.title.length>200
        ||typeof item.detail!=='string'||item.detail.length>420)return [];
      return [`<li><p>${esc(item.title)}</p><small>${esc(item.detail)}</small>${listeningSourceLink(item.actions)}</li>`];
    });
    if(!rows.length)return '';
    const open=host.querySelector('[data-top-of-mind-recent-listening]')?.open??true;
    return `<details data-top-of-mind-recent-listening${open?' open':''}><summary>Recent listening</summary><ul>${rows.join('')}</ul></details>`;
  }
  function renderMediaFindings(current,findings){
    if(!Array.isArray(findings))return '';
    const rows=findings.slice(0,2).flatMap(item=>{
      if(typeof item?.title!=='string'||!item.title||item.title.length>190
        ||typeof item.summary!=='string'||!item.summary||item.summary.length>600)return [];
      const action=item.actions?.[0];
      const next=typeof item.next_step==='string'?item.next_step.slice(0,320):'';
      const question=typeof item.question==='string'?item.question.slice(0,320):'';
      return [`<article><h4>${esc(item.title)}</h4><p>${esc(item.summary)}</p>
        ${next?`<p><b>Next step</b> ${esc(next)}</p>`:''}
        ${question?`<p><b>Question for you</b> ${esc(question)}</p>`:''}
        ${action?.kind==='chat'?sourceLink(current,{...action,chat_url:action.href},'Review video'):listeningSourceLink(item.actions)}</article>`];
    });
    return rows.length?`<section class="agent-top-of-mind-context" aria-label="Media key findings"><strong>Key findings</strong>${rows.join('')}</section>`:'';
  }
  function renderWorkItem(current,item,currentContext,stale=false,showMeter=true){
    const expired=item.state==='working'&&Date.parse(item.lease_expires_at)<Date.now();
    const state=expired?'interrupted':item.state,url=safeUrl(item.result_url);
    const meeting=currentContext?item.meeting_followup:null,meetingUrl=safeUrl(meeting?.url);
    let markup=`<li data-top-of-mind-run="${item.run_id}" data-work-state="${stale?'stale':state}">
      <div><strong>${esc(item.objective)}</strong><span>${stale?'Last observed: ':''}${esc(labels[state])}</span></div>`;
    if(!stale&&state==='working'){
      if(item.step)markup+=`<p>${esc(item.step)}</p>`;
      if(showMeter)markup+=progressMeter('Reported work progress',state,item.progress);
    }
    if(item.sources?.length)markup+=`<small>${item.sources.map(x=>esc(x.title)).join(' · ')}</small>`;
    const handoff=currentContext&&!stale?handoffLabel(item.handoff):'';
    if(handoff)markup+=`<small>${esc(handoff)}</small>`;
    if(currentContext)markup+=renderYoutubeInterest(item.youtube_interest);
    if(item.owner_state)markup+=`<small>${esc({open:'Available to continue',discussed:'Discussed with you',queued:'Private follow-through queued',deferred:'Deferred by you',declined:'Declined by you',owner_resolved:'Marked resolved by you',completed:'Follow-through preparation completed',stale:'Follow-through needs fresh sources'}[item.owner_state]||'')}</small>`;
    if(meetingUrl&&typeof meeting.title==='string'){
      markup+=`<small><a href="${esc(meetingUrl)}">Meeting follow-up · ${esc(meeting.commitment||meeting.title)}</a></small>`;
      const next=item.meeting_followup_action,skill={task:'Task',calendar:'Calendar change',reminder:'Reminder'}[next?.skill];
      if(skill&&['approval_pending','completed'].includes(next.status)){
        markup+=`<small>Planning &amp; Scheduling · ${esc(skill+(next.status==='approval_pending'?' ready for review':' saved'))}</small>`;
      }
      if(state==='completed')markup+=sourceLink(current,{chat_url:item.result_url,conversation_id:item.conversation_id,message_id:item.message_id},'Open research in Chat');
    }else if(url&&!item.meeting_followup)markup+=`<a href="${esc(url)}">Open Chat</a>`;
    return markup+'</li>';
  }
  function render(){
    if(!host?.isConnected)return;
    const current=scope();
    if(!current){renderedHost=null;renderedMarkup='';host.innerHTML='<div class="agent-top-of-mind-error" role="status">Your Agent session changed. Reload to see current work.</div>';return;}
    const activeElement=document.activeElement;
    const triageFocus=host.contains(activeElement)?activeElement.dataset.triageFocus:null;
    const specialistFocus=host.contains(activeElement)?activeElement.dataset.topOfMindSpecialistView:null;
    if(snapshot&&current.hasChat&&snapshot.conversation_id!==current.cid)snapshot=null;
    const observed=snapshot?.observed_at?new Date(snapshot.observed_at):null;
    const checked=observed&&!Number.isNaN(observed.getTime())?observed.toLocaleTimeString():'';
    const staleSnapshot=!!error||observed&&Date.now()-observed.getTime()>10000;
    const runtime=String(document.body?.dataset.stonefellowAgentState||'');
    const localWorking=current.hasChat&&current.cid>0&&['thinking','processing','speaking'].includes(runtime);
    let markup='<header><div><strong>Top of Mind</strong><small>Current work and verified results</small></div><span class="agent-top-of-mind-live">'+(staleSnapshot?'Last verified':snapshot?'Live':'Connecting')+'</span></header>';
    if(localWorking)markup+='<div class="agent-top-of-mind-foreground"><strong>This Chat</strong><span>'+esc(runtime==='speaking'?'The main Agent is speaking.':'The main Agent is preparing a response.')+'</span></div>';
    if(error)markup+='<p class="agent-top-of-mind-error" role="status">'+esc(error)+(checked?' Last verified at '+esc(checked)+'.':'')+(closed?'':' <button type="button" data-top-of-mind-retry>Retry</button>')+'</p>';
    if(current.hasChat&&current.cid<1)markup+='<p>Work can attach after your first message saves this Chat.</p>';
    if(!snapshot&&!error)markup+='<p role="status">Checking actual work status…</p>';
    else if(snapshot){
      const state=snapshot.state;
      if(state!=='ready')markup+='<p>'+esc({no_chat:'No saved work is attached to this Agent yet.',starting:'This Chat is connecting to the Agent loop.',paused:'Agent background work is paused.',linked_elsewhere:'The Agent is following another saved Chat.'}[state]||'Work status could not be verified.')+'</p>';
      const currentContext=!staleSnapshot&&!participantSelected();
      const youtubeActivity=currentContext?renderYoutubeActivity(current,snapshot.youtube_activity,snapshot.music):'';
      const mediaActivity=(currentContext?renderYoutubePlaylist(current,snapshot.youtube_activity)
        +renderMediaFindings(current,snapshot.media_findings)+renderRecentListening(snapshot.recent_listening):'')+youtubeActivity;
      const specialists=currentContext?specialistRows(current):new Map();
      const groupedRuns=new Set();
      if(currentContext){
        const coordination=snapshot.coordination;
        if(Object.hasOwn(coordinationLabels,coordination?.phase)&&!expiredLease(coordination.lease_expires_at)){
          specialists.delete('brainstorm');
          for(const item of snapshot.items)if(item.kind==='assessment'&&item.state==='working')groupedRuns.add(item.run_id);
          markup+=`<section class="agent-top-of-mind-context agent-top-of-mind-coordination" aria-label="Agent coordination">
            <strong>OB1 coordination</strong><span>${coordinationLabels[coordination.phase]}</span>
            ${typeof coordination.detail==='string'&&coordination.detail?`<small>${esc(coordination.detail)}</small>`:''}
            ${typeof coordination.finding==='string'&&coordination.finding?`<small>${esc(coordination.finding)}</small>`:''}</section>`;
        }
        const openAgenda=host.querySelector('[data-top-of-mind-agenda][open]')?.dataset.topOfMindAgenda;
        const openGmailDetails={closed:!!host.querySelector('[data-top-of-mind-email-closed][open]'),
          monitor:!!host.querySelector('[data-gmail-monitor-details][open]')};
        for(const [category,label] of [['needs_attention','Needs attention'],['agent_work','Agent work']]){
          const items=emailHandoffs(current,snapshot.gmail_inbox,category);
          if(items)markup+=`<section class="agent-top-of-mind-context" aria-label="${label}"><strong>${label}</strong>
            <ul class="vp3-email-handoffs" aria-label="Gmail findings">${items}</ul></section>`;
        }
        const calendar=renderCalendar(snapshot.calendar?.[0],openAgenda);
        const research=renderYoutube(current,null,snapshot.artist_research,snapshot.topic_research);
        for(const id of Object.keys(specialistNames)){
          let row=specialists.get(id);
          if(!row){
            if(id==='gmail')markup+=renderGmail(current,snapshot.gmail_inbox,openGmailDetails);
            if(id==='planning')markup+=calendar;
            if(id==='research')markup+=research;
            if(id==='media')markup+=mediaActivity;
            continue;
          }
          const ids=new Set(Array.isArray(row.run_ids)?row.run_ids:[]);
          if(Number.isSafeInteger(row.run_id))ids.add(row.run_id);
          const items=snapshot.items.filter(item=>!groupedRuns.has(item.run_id)&&(ids.has(item.run_id)||item.specialist_id===id));
          for(const item of items)groupedRuns.add(item.run_id);
          const run=items.find(item=>item.run_id===row.run_id);
          if(activeSpecialist(row.state)&&(expiredLease(row.lease_expires_at)
            ||run?.state==='working'&&expiredLease(run.lease_expires_at))){
            row={...row,state:'interrupted',progress:null};
          }
          const work=items.length?`<ol>${items.map(item=>renderWorkItem(current,item,true,false,false)).join('')}</ol>`:'';
          const extra=id==='planning'?calendar:id==='research'?research:id==='media'?mediaActivity
            :id==='transcripts'?transcriptReportsMarkup(row):'';
          const gmail=id==='gmail'?renderGmail(current,snapshot.gmail_inbox,openGmailDetails,row,renderOpenEngagements(current,row)+work):'';
          markup+=gmail||renderSpecialist(current,row,extra+work);
        }
        const recording=snapshot.recording, recordingUrl=safeUrl(recording?.url);
        if(recordingUrl&&typeof recording?.title==='string'){
          markup+=`<section class="agent-top-of-mind-context" aria-label="Recording in this Chat">
            <strong>Recording in this Chat</strong><p>${esc(recording.title)}</p><a href="${esc(recordingUrl)}">Open recording</a></section>`;
        }
        if(!youtubeActivity)markup+=renderYoutube(current,snapshot.youtube_search,null,null);
      }
      const music=currentContext?snapshot.music:null;
      if(music&&typeof music.title==='string'&&!youtubeActivity){
        const source='Played in VP3'+(music.channel_title?' · '+music.channel_title:'');
        markup+=`<section class="agent-top-of-mind-music" aria-label="YouTube playback">
          <strong>YouTube playback</strong><p>${esc(music.title)}</p><small>${esc(source)}</small>
          ${sourceLink(current,music,'Review in Chat')}`;
        if(music.music_suggestion&&current.hasChat&&typeof window.STONEFELLOW_CHAT_CONTINUITY_V87?.requestYoutubePicks==='function'){
          markup+='<button type="button" data-top-of-mind-picks>Ask for YouTube music picks</button><small data-top-of-mind-music-status role="status"></small>';
        }
        markup+='</section>';
      }
      if(state==='ready'&&!snapshot.items.length&&!specialists.size)markup+='<p>No active or recently verified work in this Chat’s current sources.</p>';
      const ungrouped=snapshot.items.filter(item=>!groupedRuns.has(item.run_id));
      if(ungrouped.length)markup+=`<ol>${ungrouped.map(item=>renderWorkItem(current,item,currentContext,staleSnapshot)).join('')}</ol>`;
      if(snapshot.coverage?.initiative==='limited')markup+='<small>Some work is omitted because its current sources could not be verified.</small>';
      if(checked&&!error)markup+='<footer data-top-of-mind-verified></footer>';
    }
    // Expansion belongs to the live controls. A poll with the same displayed
    // content only refreshes the verification time.
    const content=markup.replace(/(<details\b[^>]*) open(?=>)/g,'$1');
    if(renderedHost!==host||content!==renderedMarkup){
      host.innerHTML=markup;
      renderedHost=host;
      renderedMarkup=content;
      if(triageFocus)focusTriage(triageFocus);
      if(specialistFocus)Array.from(host.querySelectorAll('[data-top-of-mind-specialist-view]'))
        .find(button=>button.dataset.topOfMindSpecialistView===specialistFocus)?.focus({preventScroll:true});
    }
    const footer=host.querySelector('[data-top-of-mind-verified]');
    const verification=checked?'Verified at '+checked+' · Verified Agent work':'';
    if(footer&&footer.textContent!==verification)footer.textContent=verification;
  }
  function focusTriage(name){
    Array.from(host?.querySelectorAll('[data-triage-focus]')||[]).find(item=>item.dataset.triageFocus===name)
      ?.focus({preventScroll:true});
  }
  function schedule(){
    window.clearTimeout(timer);timer=0;
    const live=item=>['working','thinking','queued','waiting_specialist','waiting_agent'].includes(item?.state);
    const active=live(snapshot?.gmail_inbox)||snapshot?.items?.some(live)
      ||Array.isArray(snapshot?.specialists)&&snapshot.specialists.some(live);
    const delay=failures?Math.min(30000,2500*2**Math.min(failures,4)):(active?2500:8000);
    if(visible())timer=window.setTimeout(()=>void refresh(),delay);
  }
  async function refresh(){
    if(!visible()||controller||participantSelected())return;
    const sent=scope();
    if(!sent){stop();snapshot=null;render();return;}
    if(!sent.endpoint){snapshot=null;render();return;}
    const sentKey=key(sent),mine=++generation,request=new AbortController();controller=request;
    let timeout=0;
    try{
      const url=new URL(sent.endpoint,location.href);url.searchParams.set('agent_id',String(sent.agent));url.searchParams.set('conversation_id',String(sent.cid));
      if(sent.hasChat)url.searchParams.set('scope','chat');
      const read=(async()=>{const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'X-VP3-Working-Attention':sent.binding},signal:request.signal});return {response,data:await response.json()};})();
      const deadline=new Promise((resolve,reject)=>{timeout=window.setTimeout(()=>{request.abort();reject(new Error('Status deadline expired.'));},8000);});
      const {response,data}=await Promise.race([read,deadline]);
      if(request.signal.aborted)throw new Error('Status deadline expired.');
      if(!visible()||mine!==generation||sentKey!==key(scope())||participantSelected())return;
      if(!response.ok||data?.ok!==true)throw Object.assign(new Error('Work status could not be refreshed.'),{status:response.status});
      const next=data.state;
      if(!next||next.contract!=='vp3.top-of-mind.v1'||next.owner_user_id!==sent.owner||next.agent_id!==sent.agent
        ||next.working_attention_binding!==sent.binding||!Number.isSafeInteger(next.conversation_id)||next.conversation_id<0
        ||sent.hasChat&&next.conversation_id!==sent.cid||!Number.isFinite(Date.parse(next.observed_at))||!Array.isArray(next.items)||next.items.length>8
        ||next.items.some(x=>!Number.isSafeInteger(x.run_id)||x.run_id<1||typeof x.objective!=='string'||!Object.hasOwn(labels,x.state))){
        throw Object.assign(new Error('Work status does not match this Agent.'),{status:403});
      }
      if(triageView&&triageView.mailbox!==next.gmail_inbox?.triage?.generation)clearTriage();
      snapshot=next;error='';failures=0;
      render();
    }catch(failure){
      if(mine!==generation||!visible()||sentKey!==key(scope()))return;
      error='The latest work status is unavailable.';
      failures++;
      if([401,403,419].includes(failure?.status)){clearTriage();snapshot=null;error='Your Agent session or access changed. Reload to see current work.';closed=true;}
      render();
    }finally{
      window.clearTimeout(timeout);if(controller===request)controller=null;
      if(mine===generation)schedule();
    }
  }
  function mount(nextHost,isOpen){
    const wasVisible=visible();
    const changed=host!==nextHost;host=nextHost;open=isOpen===true&&!!host;
    if(!visible()){stop();clearTriage();return;}
    if(!changed&&wasVisible)return;
    if(changed){stop();clearTriage();}
    render();void refresh();
  }
  function gmailApiUrl(current){
    const endpoint=safeUrl(current.endpoint);
    return endpoint?new URL('gmail-inbox-initial-v1.php',new URL(endpoint,location.origin)):null;
  }
  function triageChatUrl(current){
    const href=safeUrl(window.VP3_ORB_CONFIG?.chatUrl);
    if(!href)return null;
    const url=new URL(href,location.origin);
    url.searchParams.set('agent',current.agent||'system');
    return url;
  }
  function openExactGmailMessage(current,id,generation){
    if(!isTriageId(id)||!Number.isSafeInteger(generation)||generation<1)return false;
    const request={id,generation,binding:current.binding,agent:current.agent,conversation:current.cid};
    if(current.hasChat){
      if(window.VP3GmailInboxInitialV1?.openTriageItem(request)){
        window.STONEFELLOW_NOTIFICATION_CENTER?.close?.();
        return true;
      }
    }else{
      const url=triageChatUrl(current);
      if(url){
        try{
          sessionStorage.setItem(`vp3:gmail-triage-open:${current.owner}`,JSON.stringify({...request,at:Date.now()}));
          location.assign(url.href);
          return true;
        }catch{}
      }
    }
    return false;
  }
  function openEmailReportItem(current,monitor,id){
    const report=monitor?.report;
    const sources=[report?.running_report?.findings,report?.handoffs?.needs_attention,report?.handoffs?.agent_work];
    return sources.some(findings=>Array.isArray(findings)&&findings.some(item=>item?.item_ref===id))
      &&openExactGmailMessage(current,id,monitor.triage?.generation);
  }
  function openTriageInChat(current,view,id){
    if(view.phase!=='ready'||!view.items.some(item=>item.id===id)||view.mailbox!==snapshot?.gmail_inbox?.triage?.generation)return;
    if(openExactGmailMessage(current,id,view.mailbox))return;
    view.status='Open Agent Chat and try again to read this email.';
    render();
  }
  function actionScope(){
    const observedAt=Date.parse(snapshot?.observed_at||'');
    const current=scope();
    return !snapshot||!visible()||participantSelected()||!current||(current.hasChat&&current.cid!==snapshot.conversation_id)
      ||error||!Number.isFinite(observedAt)||Date.now()-observedAt>10000?null:current;
  }
  async function requestTriage(current,view,payload){
    const url=gmailApiUrl(current);
    if(!url||!current.csrf)return null;
    triageRequest?.abort();
    const request=new AbortController(),sent=key(current);
    triageRequest=request;
    const stillCurrent=()=>triageRequest===request&&triageView===view&&visible()&&!participantSelected()
      &&sent===key(scope())&&view.mailbox===snapshot?.gmail_inbox?.triage?.generation;
    let timeout=0;
    try{
      const read=(async()=>{
        const response=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',signal:request.signal,
          headers:{'Content-Type':'application/json'},body:JSON.stringify({owner_key:String(current.owner),csrf_token:current.csrf,
            working_attention_binding:current.binding,agent_id:current.agent,conversation_id:current.cid,generation:view.mailbox,...payload})});
        return {status:response.status,data:await response.json()};
      })();
      const deadline=new Promise((resolve,reject)=>{timeout=window.setTimeout(()=>{
        request.abort();reject(new Error('Email triage deadline expired.'));
      },8000);});
      const result=await Promise.race([read,deadline]);
      return stillCurrent()?result:null;
    }catch{
      return stillCurrent()?{status:0,data:null}:null;
    }finally{
      window.clearTimeout(timeout);
      if(triageRequest===request)triageRequest=null;
    }
  }
  function triageAccessChanged(result){
    if(![401,403,419].includes(result.status))return false;
    clearTriage();
    gmailStatus='Gmail review access changed. Refreshing the latest status.';
    stop();render();void refresh();
    return true;
  }
  async function inspectTriage(current,group,trail,status=''){
    const model=snapshot?.gmail_inbox?.triage;
    const groups=Array.isArray(model?.groups)?model.groups:[];
    const label=groups.find(item=>item?.key===group)?.label??(triageView?.group===group?triageView.label:null);
    if(typeof label!=='string'||!triageGroups.includes(group)||!Number.isSafeInteger(model?.generation))return;
    const view={mailbox:model.generation,group,label,trail,items:[],next:null,editor:null,phase:'loading',status};
    triageView=view;
    render();
    const result=await requestTriage(current,view,{action:'triage_group',group,cursor:trail.at(-1)});
    if(!result||triageAccessChanged(result))return;
    const page=result.data?.group;
    if(result.status===200&&result.data?.ok===true&&Array.isArray(page?.items)&&page.items.length<=20
      &&page.items.every(item=>isTriageId(item?.id)&&Number.isSafeInteger(item.revision)&&item.revision>0&&item.group===group)
      &&(page.next===null||isTriageId(page.next))){
      if(!page.items.length&&trail.length>1){
        await inspectTriage(current,group,[null],status||'The group changed. Showing its first page.');
        return;
      }
      view.items=page.items;
      view.next=page.next;
      view.phase='ready';
    }else{
      view.phase='failed';
      view.status=result.status===409?'This email group changed. Reload it to continue.':'Email messages could not be loaded.';
      if(result.status===409){stop();void refresh();}
    }
    render();
    if(trail.length>1||view.status)focusTriage('page');
  }
  async function correctTriage(current,view,item,attention,rememberSender){
    view.phase='saving';
    view.status='Saving priority…';
    render();
    const result=await requestTriage(current,view,{action:'triage_correct',triage_id:item.id,revision:item.revision,attention,
      remember_sender:rememberSender});
    if(!result||triageAccessChanged(result))return;
    if(result.status===200&&result.data?.ok===true&&result.data.item?.id===item.id){
      const status=result.data.item.correction_applied===false
        ?'Sender rule saved. This email keeps its priority because it has a deadline or active follow-up.'
        :rememberSender?'Priority saved for this sender’s future mail.':'Priority saved for this email.';
      stop();void refresh();
      await inspectTriage(current,view.group,view.trail,status);
    }else if(result.status===409){
      stop();void refresh();
      await inspectTriage(current,view.group,[null],'This email changed. Review its current priority.');
    }else{
      stop();void refresh();
      await inspectTriage(current,view.group,view.trail,'Priority could not be confirmed. Review the current messages.');
    }
  }
  function triageClick(current,button){
    const action=button.dataset.topOfMindTriage;
    const view=triageView;
    if(view?.phase==='saving')return;
    if(action==='group'){
      const group=button.dataset.group;
      if(view?.group===group){clearTriage();render();}
      else void inspectTriage(current,group,[null]);
    }else if(view){
      if(action==='open')openTriageInChat(current,view,button.dataset.itemId);
      if(action==='first')void inspectTriage(current,view.group,[null]);
      if(action==='previous'&&view.trail.length>1)void inspectTriage(current,view.group,view.trail.slice(0,-1));
      if(action==='next'&&view.next)void inspectTriage(current,view.group,[...view.trail,view.next]);
      if(action==='edit'){
        const item=view.items.find(item=>item.id===button.dataset.itemId);
        if(!item)return;
        view.editor={id:item.id,attention:Object.hasOwn(triageAttention,item.attention)?item.attention:'review',remember:false};
        view.status='';
        render();
        focusTriage('attention');
      }
      if(action==='cancel'){
        const id=view.editor?.id;
        view.editor=null;
        view.status='';
        render();
        if(id)focusTriage('edit-'+id);
      }
    }
  }
  async function updateGmail(current,item,decision){
    if(gmailUpdating||!current.csrf||item.response_followthrough?.agent_id===current.agent
      ||!['snooze','dismiss','resolve','reopen'].includes(decision))return;
    const sent=key(current);
    const url=gmailApiUrl(current);
    if(!url)return;
    const request=new AbortController();
    const timeout=window.setTimeout(()=>request.abort(),8000);
    gmailUpdating=true;gmailStatus='Saving the email matter…';render();
    try{
      const response=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',signal:request.signal,
        headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'matter_update',owner_key:String(current.owner),
          csrf_token:current.csrf,working_attention_binding:current.binding,matter_id:item.id,revision:item.revision,decision})});
      const result=await response.json();
      if(sent!==key(scope())||participantSelected())return;
      gmailStatus=response.ok&&result?.ok===true?'Email matter updated.':
        response.status===409?'The email matter changed. Refreshing the latest status.':'The email matter could not be updated.';
    }catch{
      if(sent===key(scope())&&!participantSelected())gmailStatus='The update could not be confirmed. Checking the latest status.';
    }finally{
      window.clearTimeout(timeout);
      gmailUpdating=false;
      if(sent===key(scope())&&!participantSelected()){stop();render();void refresh();}
    }
  }
  window.addEventListener('vp3:activity-center-view',event=>mount(event.detail?.topOfMindHost,event.detail?.open===true&&event.detail?.tab==='brain'));
  window.addEventListener('vp3:chat-specialists-changed',()=>{if(visible())render();});
  window.addEventListener('vp3:orb-conversation-changed',()=>{if(visible())render();});
  document.addEventListener('visibilitychange',()=>{if(document.hidden){stop();clearTriage();}else if(visible()){render();void refresh();}});
  window.addEventListener('pagehide',()=>{closed=true;stop();clearTriage();});
  window.addEventListener('pageshow',()=>{closed=false;if(visible()){render();void refresh();}});
  for(const name of ['stonefellow:chat-context-changing','vp3:chat-context-ready'])window.addEventListener(name,()=>{stop();clearTriage();snapshot=null;error='';gmailStatus='';render();if(name==='vp3:chat-context-ready'&&visible())void refresh();});
  for(const name of ['vp3:participant-selection-changed','vp3:youtube-history-changed'])window.addEventListener(name,()=>{
    stop();clearTriage();snapshot=null;error='';gmailStatus='';render();if(visible())void refresh();
  });
  document.addEventListener('change',event=>{
    const input=event.target;
    const editor=triageView?.editor;
    if(!host?.contains(input)||!input.closest('[data-top-of-mind-triage-form]')||!editor)return;
    if(input.name==='attention'&&Object.hasOwn(triageAttention,input.value))editor.attention=input.value;
    if(input.name==='remember_sender')editor.remember=input.checked;
  });
  document.addEventListener('submit',event=>{
    const form=event.target;
    if(!host?.contains(form)||!form.matches('[data-top-of-mind-triage-form]'))return;
    event.preventDefault();
    const current=actionScope(),view=triageView;
    if(!current||view?.phase!=='ready'||view.mailbox!==snapshot?.gmail_inbox?.triage?.generation||!view.editor)return;
    const item=view.items.find(item=>item.id===view.editor.id);
    const attention=form.elements.namedItem('attention')?.value;
    if(item&&Object.hasOwn(triageAttention,attention))void correctTriage(current,view,item,attention,
      form.elements.namedItem('remember_sender')?.checked===true);
  });
  document.addEventListener('click',event=>{
    if(!host?.contains(event.target))return;
    if(event.target.closest('[data-top-of-mind-retry]')){error='';stop();render();void refresh();return;}
    const current=actionScope();
    const youtube=event.target.closest('[data-top-of-mind-youtube-expires] a');
    if(youtube&&(!current||Number(youtube.closest('li').dataset.topOfMindYoutubeExpires)<=Date.now()/1000)){
      event.preventDefault();
      if(current){render();void refresh();}
      return;
    }
    if(!current)return;
    const profileReport=event.target.closest('[data-top-of-mind-profile-report]');
    if(profileReport){
      if(event.isTrusted&&current.hasChat&&specialistRows(current).has('profile')
        &&window.VP3AgentActivityHub?.openSpecialist?.('profile',profileReport,{label:'Profile',fullReport:true,
          focusReturn:document.getElementById('chatInput'),selection:{tab:'reports'}})===true){
        window.STONEFELLOW_NOTIFICATION_CENTER?.close?.();
      }
      return;
    }
    const specialist=event.target.closest('[data-top-of-mind-specialist-view]');
    if(specialist){
      if(!event.isTrusted)return;
      const target=specialist.dataset.topOfMindSpecialistView;
      const row=specialistRows(current).get(target.split(':')[0]);
      if(row?.entries?.some(entry=>entry.key===target&&entry.canView===true)){
        const viewed=current.hasChat?window.VP3ChatSpecialists?.view?.(target):window.VP3AgentOrb?.viewActivitySpecialist?.(target);
        if(viewed===true)window.STONEFELLOW_NOTIFICATION_CENTER?.close?.();
      }
      return;
    }
    const triage=event.target.closest('[data-top-of-mind-triage]');
    if(triage){if(event.isTrusted)triageClick(current,triage);return;}
    const reportItem=event.target.closest('[data-gmail-report-item]');
    if(reportItem){
      if(event.isTrusted&&!openEmailReportItem(current,snapshot.gmail_inbox,reportItem.dataset.gmailReportItem)){
        gmailStatus='Open Agent Chat and try again to read this email.';
        render();
      }
      return;
    }
    const email=event.target.closest('[data-top-of-mind-email]');
    if(email){
      const matters=snapshot.gmail_inbox?.matters;
      const item=[...(Array.isArray(matters?.items)?matters.items:[]),...(Array.isArray(matters?.closed)?matters.closed:[])]
        .find(row=>row?.id===email.dataset.matterId);
      if(item)void updateGmail(current,item,email.dataset.topOfMindEmail);
      return;
    }
    const chat=window.STONEFELLOW_CHAT_CONTINUITY_V87;
    const source=event.target.closest('[data-top-of-mind-source]');
    if(source&&!event.metaKey&&!event.ctrlKey&&!event.shiftKey&&!event.altKey){
      const id=Number(source.dataset.topOfMindSource);
      const cid=Number(source.dataset.topOfMindConversation);
      if(!Number.isSafeInteger(id)||id<1||!Number.isSafeInteger(cid)||cid<1)return;
      const row=cid===current.cid?document.getElementById('chatThread')?.querySelector(`[data-chat-message-id="${id}"][data-chat-conversation-id="${cid}"]`):null;
      if(row){
        event.preventDefault();
        chat?.showChatView?.();
        window.STONEFELLOW_NOTIFICATION_CENTER?.close?.();
        row.scrollIntoView({block:'center'});
      }else if(typeof chat?.openConversation==='function'&&!chat.isBusy?.()){
        event.preventDefault();
        void chat.openConversation(cid,{messageId:id}).then(opened=>{
          if(opened)window.STONEFELLOW_NOTIFICATION_CENTER?.close?.();
        }).catch(()=>{});
      }
      return;
    }
    if(!event.target.closest('[data-top-of-mind-picks]')||!snapshot.music?.music_suggestion)return;
    if(chat?.requestYoutubePicks?.()){
      chat.showChatView?.();
      window.STONEFELLOW_NOTIFICATION_CENTER?.close?.();
    }else{
      const status=host.querySelector('[data-top-of-mind-music-status]');
      if(status)status.textContent='Finish the current Chat turn before asking for music picks.';
    }
  });
  new MutationObserver(()=>{if(visible())render();}).observe(document.body,{attributes:true,attributeFilter:['data-stonefellow-agent-state']});
  window.VP3TopOfMindFeedV1=Object.freeze({mount,specialistHeading,emailReport,emailHandoffs,openEmailReportItem,openExactGmailMessage,youtubePlaylistEntries,youtubePlaylistEmptyText,handoffLabel,transcriptReportRows,specialistNames:Object.freeze(specialistNames),
    specialistLabels:Object.freeze(specialistLabels),coordinationLabels:Object.freeze(coordinationLabels)});
  const existing=document.querySelector('#chatNotificationDrawer [data-agent-top-of-mind]');
  if(existing)mount(existing,document.body.classList.contains('chat-notification-drawer-open'));
})();
