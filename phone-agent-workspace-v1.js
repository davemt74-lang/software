(() => {
  'use strict';
  const references = /^[a-f0-9]{32}$/, revisions = /^[a-f0-9]{64}$/;
  const filters = ['all','needs_you','for_you','history'];
  const labels = {gmail_draft:'Email draft',gmail_attention:'Important email',agent_result:'Result',image:'Image',canvas:'Canvas'};
  const openLabels = {gmail_draft:'Review email',gmail_attention:'Read email',agent_result:'Read result',image:'View image',canvas:'Open canvas'};
  const states = {ready:'Ready',needs_review:'Needs review',waiting:'Waiting',completed:'Completed',unavailable:'Unavailable'};
  const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  const integer = (value,min=0) => Number.isSafeInteger(value) && value >= min;
  const text = (value,max) => typeof value === 'string' && value.length <= max && !/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/.test(value);
  const reference = value => typeof value === 'string' && references.test(value);
  const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
  const revision = value => typeof value === 'string' && revisions.test(value);
  const stamp = value => value === null || typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)$/.test(value) && Number.isFinite(Date.parse(value));
  const check = (ok,message='The Agent workspace response could not be verified.') => {if(!ok){const error=new Error(message);error.code='invalid_workspace_response';throw error;}};
  const counts = value => {
    check(isObject(value)&&integer(value.unread)&&integer(value.needs_action)&&typeof value.complete==='boolean');
    return {unread:value.unread,needs_action:value.needs_action,complete:value.complete};
  };
  function target(value,kind,agentId){
    check(isObject(value)&&integer(value.agent_id)&&value.agent_id===agentId&&integer(value.conversation_id,1));
    const expected = {gmail_draft:'gmail_draft',gmail_attention:'gmail_attention',agent_result:'chat_message',image:'image',canvas:'canvas'}[kind];
    check(value.kind===expected);
    const result={kind:value.kind,agent_id:value.agent_id,conversation_id:value.conversation_id};
    if(kind==='gmail_draft'){check(reference(value.draft_id));result.draft_id=value.draft_id;}
    if(kind==='gmail_attention'){
      check(revision(value.item_ref)&&integer(value.generation,1)&&value.origin==='gmail_mailbox'&&value.conversation_role==='discussion_target');
      Object.assign(result,{item_ref:value.item_ref,generation:value.generation,origin:value.origin,conversation_role:value.conversation_role});
    }
    if(kind==='agent_result'){check(integer(value.message_id,1));result.message_id=value.message_id;}
    if(kind==='image'){check(uuid(value.generation_id));result.generation_id=value.generation_id;}
    if(kind==='canvas'){check(uuid(value.canvas_id)&&integer(value.revision,1));result.canvas_id=value.canvas_id;result.revision=value.revision;}
    check(Object.keys(value).every(key=>Object.hasOwn(result,key)));
    return result;
  }
  function item(value,agentId){
    check(isObject(value)&&reference(value.item_ref)&&integer(value.revision,1)&&integer(value.state_version,1)
      &&value.agent_id===agentId&&integer(value.conversation_id,1)
      &&Object.hasOwn(labels,value.source_kind)&&revision(value.source_revision)&&text(value.title,480)&&text(value.summary,2400)
      &&Object.hasOwn(states,value.state)&&typeof value.needs_action==='boolean'
      &&['normal','important','urgent'].includes(value.priority)&&stamp(value.occurred_at)&&value.occurred_at!==null
      &&stamp(value.updated_at)&&value.updated_at!==null&&isObject(value.receipt));
    const receipt={};
    for(const key of ['seen_at','read_at','acknowledged_at','snoozed_until']){check(stamp(value.receipt[key]));receipt[key]=value.receipt[key];}
    const destination=target(value.target,value.source_kind,agentId);check(destination.conversation_id===value.conversation_id);
    return {item_ref:value.item_ref,revision:value.revision,state_version:value.state_version,source_kind:value.source_kind,agent_id:agentId,conversation_id:value.conversation_id,
      source_revision:value.source_revision,title:value.title,summary:value.summary,state:value.state,needs_action:value.needs_action,
      priority:value.priority,occurred_at:value.occurred_at,updated_at:value.updated_at,receipt,target:destination};
  }
  function scopeResponse(raw,scope){
    check(isObject(raw)&&raw.ok===true&&raw.device_id===scope.device_id&&raw.enrollment_revision===scope.enrollment_revision
      &&raw.scope_revision===scope.scope_revision,'Your phone or Agent authorization changed. Refresh the connection.');
  }
  function status(raw,deviceId){
    check(isObject(raw)&&raw.ok===true&&raw.device_id===deviceId&&['sign_in_required','not_enrolled','ready','unavailable'].includes(raw.state)
      &&typeof raw.enrolled==='boolean'&&typeof raw.owner_eligible==='boolean'&&integer(raw.revision)
      &&integer(raw.enrollment_revision)&&raw.enrollment_revision===raw.revision);
    const value={state:raw.state,enrolled:raw.enrolled,owner_eligible:raw.owner_eligible,device_id:deviceId,
      revision:raw.revision,enrollment_revision:raw.enrollment_revision,scope_revision:null,agents:[],agent:null,csrf_token:'',
      counts:null,preferences:{notifications_enabled:false}};
    if(raw.owner_eligible){
      check(text(raw.csrf_token,256)&&raw.csrf_token.length>=16&&Array.isArray(raw.agents)&&raw.agents.length<=101);
      const ids=new Set();
      value.agents=raw.agents.map(agent=>{check(isObject(agent)&&integer(agent.id)&&text(agent.name,480)&&!ids.has(agent.id));ids.add(agent.id);return {id:agent.id,name:agent.name};});
      value.csrf_token=raw.csrf_token;
    }
    if(raw.state==='ready'){
      check(raw.enrolled&&raw.owner_eligible&&raw.enrollment_revision>0&&revision(raw.scope_revision)
        &&isObject(raw.agent)&&integer(raw.agent.id)&&text(raw.agent.name,480)&&integer(raw.conversation_id)
        &&isObject(raw.preferences)&&typeof raw.preferences.notifications_enabled==='boolean');
      value.agent={id:raw.agent.id,name:raw.agent.name};value.conversation_id=raw.conversation_id;
      value.scope_revision=raw.scope_revision;value.counts=raw.counts===null?null:counts(raw.counts);value.preferences={notifications_enabled:raw.preferences.notifications_enabled};
    }else check(raw.scope_revision===null);
    return value;
  }
  function page(raw,scope){
    scopeResponse(raw,scope);check(Array.isArray(raw.items)&&raw.items.length<=20&&typeof raw.has_more==='boolean'
      &&(raw.next_cursor===null||text(raw.next_cursor,2048)&&raw.next_cursor.length>0)&&raw.has_more===(raw.next_cursor!==null));
    const ids=new Set(),items=raw.items.map(value=>{const row=item(value,scope.agent_id);check(!ids.has(row.item_ref));ids.add(row.item_ref);return row;});
    return {items,counts:counts(raw.counts),next_cursor:raw.next_cursor,has_more:raw.has_more};
  }
  function detail(raw,scope){
    scopeResponse(raw,scope);const row=item(raw.item,scope.agent_id);check(isObject(raw.detail)&&raw.detail.kind===row.source_kind);
    let content;
    if(row.source_kind==='agent_result'){check(text(raw.detail.text,24000)&&typeof raw.detail.truncated==='boolean');content={kind:row.source_kind,text:raw.detail.text,truncated:raw.detail.truncated};}
    else{const value=target(raw.detail.target,row.source_kind,scope.agent_id);check(JSON.stringify(value)===JSON.stringify(row.target));content={kind:row.source_kind,target:value};}
    return {item:row,detail:content};
  }
  // The same fence protects list, detail and mutation responses, including delayed results after a phone/Agent change.
  function createState(){
    let key='',generation=0;const latest=new Map(),controllers=new Set();
    return Object.freeze({
      bind(next){if(next===key)return false;key=next;generation++;for(const controller of controllers)controller.abort();controllers.clear();latest.clear();return true;},
      capture:()=>({key,generation}),current:value=>value?.key===key&&value?.generation===generation&&key!=='',
      request(){const controller=new AbortController();controllers.add(controller);return {controller,finish:()=>controllers.delete(controller)};},
      cancel(){for(const controller of controllers)controller.abort();controllers.clear();},
      merge(row){const previous=latest.get(row.item_ref);if(previous&&(row.state_version<previous.state_version||row.revision<previous.revision))return previous;
        latest.set(row.item_ref,row);if(latest.size>200)latest.delete(latest.keys().next().value);return row;},
      get:ref=>latest.get(ref),clear(){key='';generation++;for(const controller of controllers)controller.abort();controllers.clear();latest.clear();}
    });
  }
  window.VP3PhoneAgentWorkspaceContractV1=Object.freeze({status,item,page,detail,scopeResponse,createState});

  window.VP3PhoneAgentWorkspaceV1=({shell})=>{
    const root=document.getElementById('phone-location'),panel=document.getElementById('phone-agent');
    const host=document.getElementById('phone-agent-workspace'),enrollment=document.getElementById('phone-agent-enrollment');
    if(!root||!panel||!host||!enrollment||!shell)return null;
    const endpoint=new URL(root.dataset.endpoint,location.href);if(endpoint.origin!==location.origin)return null;endpoint.searchParams.set('workspace','1');
    const model=createState();
    let device=null,reachable=false,visible=false,owner=null,scope=null,selected='all',rows=[],nextCursor=null,hasMore=false;
    let loading=false,writing=false,statusLoading=false,statusAt=0,statusSerial=0,listSerial=0,detailSerial=0,auditSerial=0;
    let native=null,nativeController=null,opened=null,returnRef=null,feedScroll=0,pendingNotification=null,settingsSignature='',recoveredScope='';
    let callClient=null,callSettings=null,callSettingsHost=null,callLoading=false,callSerial=0,callAt=0,callRefreshPending=false;
    const callContract=window.VP3EmailCallSettingsContractV1,callState=callContract?.createCallState();
    const callEndpoint=root.dataset.emailCallSettingsApi||'';
    const sourceRefreshes=new Set();
    const make=(tag,value='',className='')=>{const node=document.createElement(tag);node.textContent=value;if(className)node.className=className;return node;};
    const button=(label,handler,className='')=>{const node=make('button',label,className);node.type='button';node.addEventListener('click',event=>{if(event.isTrusted)void handler(event);});return node;};
    const enrollmentContent=make('div');
    const head=make('header','','phone-agent-head'),heading=make('h1','Your Agent'),sub=make('p','Results and decisions, brought to you.','phone-agent-subtitle');
    const title=make('div');title.append(heading,sub);const headControls=make('div','','phone-agent-head-controls');
    headControls.append(button('Refresh',async()=>{await refreshStatus(true);if(live())void loadFeed();}),button('Settings',()=>shell.openConnection()));head.append(title,headControls);
    const filterBar=make('nav','','phone-agent-filters');filterBar.setAttribute('aria-label','Filter Agent results');
    const filterButtons=new Map();
    for(const [key,label] of [['all','All'],['needs_you','Needs you'],['for_you','For you'],['history','History']]){
      const control=button(label,()=>chooseFilter(key));control.setAttribute('aria-pressed',String(key===selected));filterButtons.set(key,control);filterBar.append(control);
    }
    const feedback=make('p','','phone-agent-feedback');feedback.setAttribute('role','status');feedback.setAttribute('aria-live','polite');
    const list=make('div','','phone-agent-list');list.setAttribute('aria-label','Agent results');
    const empty=make('p','','phone-agent-empty');empty.hidden=true;
    const more=button('Load more',()=>loadFeed(true),'secondary phone-agent-more');more.hidden=true;
    const callBanner=make('aside','','phone-agent-email-call-banner');callBanner.hidden=true;callBanner.setAttribute('aria-label','Exact email call review');
    const feed=make('div');feed.append(head,callBanner,filterBar,list,empty,more);
    const detailHost=make('div','','phone-agent-detail');detailHost.hidden=true;
    host.append(feedback,feed,detailHost);
    function message(value,error=false){feedback.textContent=value;feedback.dataset.error=String(error);}
    function live(){return reachable&&device?.status==='approved'&&owner?.state==='ready'&&scope!==null&&!document.hidden;}
    function captured(){return {fence:model.capture(),device:device?.id,scope};}
    function current(value){return value.device===device?.id&&value.scope===scope&&model.current(value.fence);}
    function setCounts(value){if(owner)owner.counts=value;shell.setAgent({enabled:owner?.state==='ready',unread:value.unread,countsComplete:value.complete});sub.textContent=value.complete
      ? value.needs_action>0?value.needs_action+' '+(value.needs_action===1?'item needs':'items need')+' you.':'Results and decisions, brought to you.'
      :'Recent results and decisions. More may be available.';}
    function closeNative(){nativeController?.abort();nativeController=null;try{native?.close?.();}catch{}native=null;panel.classList.remove('phone-agent-native');}
    function disposeCalls(){
      callSerial++;callLoading=false;callAt=0;callRefreshPending=false;callClient?.dispose();callClient=null;
      callSettings?.dispose();callSettings=null;callSettingsHost?.remove();callSettingsHost=null;callState?.clear();callBanner.replaceChildren();callBanner.hidden=true;
    }
    function clearPrivate(){
      model.clear();listSerial++;detailSerial++;auditSerial++;sourceRefreshes.clear();disposeCalls();closeNative();scope=null;opened=null;rows=[];nextCursor=null;hasMore=false;loading=false;writing=false;
      list.replaceChildren();detailHost.replaceChildren();detailHost.hidden=true;feed.hidden=false;empty.hidden=true;more.hidden=true;message('');
      shell.setAgent({enabled:false});
    }
    function invalidate(value){clearPrivate();owner=null;settingsSignature='';renderEnrollment(value);}
    async function api(action,body={},capture=null){
      const held=device?.id;check(reference(held));
      const request=model.request(),input={action,device_id:held};
      if(action!=='status'){
        check(owner?.owner_eligible&&owner.csrf_token,'Sign in as the account owner to continue.');input.csrf_token=owner.csrf_token;
        if(scope&&action!=='enroll'){input.enrollment_revision=scope.enrollment_revision;input.scope_revision=scope.scope_revision;}
      }
      Object.assign(input,body);const timer=setTimeout(()=>request.controller.abort(),15000);
      try{
        const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',referrerPolicy:'no-referrer',
          headers:{'Content-Type':'application/json','X-VP3-Phone':'1'},signal:request.controller.signal,body:JSON.stringify(input)});
        const result=await response.json().catch(()=>null);
        if(device?.id!==held||capture&&!current(capture)||request.controller.signal.aborted)throw new DOMException('Connection changed','AbortError');
        if(!response.ok||result?.ok!==true){const error=new Error('This Agent item could not be verified. Refresh to check its current state.');error.status=response.status;throw error;}
        return result;
      }finally{clearTimeout(timer);request.finish();}
    }
    function failure(error,capture){
      if(error?.name==='AbortError'||capture&&!current(capture))return;
      if([401,403,404,409,419].includes(error.status)||error.code==='invalid_workspace_response'){
        const deniedScope=scope?[scope.device_id,scope.enrollment_revision,scope.scope_revision].join('/'):'',recover=Boolean(deniedScope&&deniedScope!==recoveredScope&&[401,403,404,409,419].includes(error.status));
        if(recover)recoveredScope=deniedScope;
        statusSerial++;statusLoading=false;statusAt=0;invalidate('Your private Agent workspace changed. Check your connection and sign in again if needed.');
        if(recover)void refreshStatus(true);
      }else message(error.message||'The Agent workspace is temporarily unavailable. Try Refresh.',true);
    }
    function controls(){
      more.disabled=!live()||loading||writing;more.hidden=!hasMore;
      for(const [key,control] of filterButtons){control.disabled=!live()||writing;control.setAttribute('aria-pressed',String(key===selected));}
      for(const control of list.querySelectorAll('button'))control.disabled=!live()||writing;
    }
    function localDate(value){try{return new Intl.DateTimeFormat(undefined,{dateStyle:'medium',timeStyle:'short'}).format(new Date(value));}catch{return value;}}
    function callDescription(value){
      if(!value)return '';
      const label=callContract.labels[value.state];
      return value.state==='uncertain'?label+'. No automatic redial.':label;
    }
    function callLine(ref){
      const line=make('p',callDescription(callState?.get(ref)),'phone-agent-email-call-status');line.dataset.emailCallStatus=ref;line.hidden=!line.textContent;return line;
    }
    function renderCallProgress(){
      for(const element of list.querySelectorAll('[data-email-call-status]')){
        const value=callDescription(callState?.get(element.dataset.emailCallStatus));if(element.textContent!==value)element.textContent=value;element.hidden=!value;
      }
      const active=callState?.active();
      const signature=active?JSON.stringify(active):'';
      if(callBanner.dataset.signature!==signature){
        callBanner.dataset.signature=signature;callBanner.replaceChildren();callBanner.hidden=!active;
        if(active){
          const action=make('div','','phone-agent-actions');action.append(button(active.source_kind==='gmail_draft'?'Review email for this call':'Read email for this call',()=>openItem(active.item_ref)));
          callBanner.append(make('h2',callDescription(active)),make('p','Open the exact email for this call. Sending requires its own review and approval.'),action);
        }
      }
      const detail=detailHost.querySelector('[data-email-call-detail]');
      if(detail&&opened){
        const value=callState?.get(opened.item.item_ref),matching=active?.item_ref===opened.item.item_ref;
        detail.textContent=callDescription(value||matching&&active)+(matching?' · This is the exact email linked to the call.':'');detail.hidden=!detail.textContent;
      }
    }
    function setupCalls(){
      if(!scope||callClient||!callEndpoint||!callContract||!window.VP3EmailCallSettingsV1?.createClient)return;
      const capture=captured();
      try{callClient=window.VP3EmailCallSettingsV1.createClient({endpoint:callEndpoint,context:capture.scope,isCurrent:()=>current(capture)});}
      catch{callClient=null;}
    }
    function mountCallSettings(){
      if(!scope||!callClient||!window.VP3EmailCallSettingsV1?.mount)return;
      if(!callSettingsHost)callSettingsHost=make('div','','phone-agent-email-call-settings');
      if(!enrollment.contains(callSettingsHost))enrollment.append(callSettingsHost);
      if(!callSettings){
        const capture=captured();callSettings=window.VP3EmailCallSettingsV1.mount({host:callSettingsHost,endpoint:callEndpoint,context:capture.scope,
          isCurrent:()=>current(capture),onAccessChanged:error=>{if(error.code==='invalid_email_calls_response')error.code='invalid_workspace_response';failure(error,capture);},onSaved:()=>void refreshCalls(true)});
      }
    }
    function visibleCallRefs(){
      const refs=[];if(opened)refs.push(opened.item.item_ref);
      if(!feed.hidden){
        const area=panel.getBoundingClientRect();
        for(const card of list.children){const box=card.getBoundingClientRect();if(box.bottom>=area.top-200&&box.top<=area.bottom+200)refs.push(card.dataset.item);}
      }
      if(!refs.length)refs.push(...rows.slice(-40).map(row=>row.item_ref));
      return [...new Set(refs)].slice(0,40);
    }
    async function refreshCalls(force=false){
      if(!live()||!callClient||!callState||!visible&&document.getElementById('phone-connection')?.hidden!==false)return;
      if(callLoading){if(force)callRefreshPending=true;return;}
      if(!force&&Date.now()-callAt<10000)return;
      const capture=captured(),serial=++callSerial,refs=visibleCallRefs();callLoading=true;
      try{
        const result=callContract.statuses(await callClient.request('statuses',{item_refs:refs}),capture.scope,refs);
        if(!current(capture)||serial!==callSerial)return;callState.merge(result);callAt=Date.now();renderCallProgress();
      }catch(error){
        if(!current(capture)||serial!==callSerial||error.name==='AbortError')return;
        callState.clear();renderCallProgress();
        if(error.code==='invalid_email_calls_response')error.code='invalid_workspace_response';
        if([401,403,404,409,419].includes(error.status)||error.code==='invalid_workspace_response')failure(error,capture);
      }finally{
        if(current(capture)&&serial===callSerial){callLoading=false;if(callRefreshPending){callRefreshPending=false;void refreshCalls(true);}}
      }
    }
    function metadata(row){
      const line=make('div','','phone-agent-meta');line.dataset.agentMetadata='true';line.append(make('span',labels[row.source_kind]));
      if(!row.receipt.read_at)line.append(make('span','Unread','phone-agent-tag unread'));
      if(row.needs_action)line.append(make('span','Needs you','phone-agent-tag attention'));
      else line.append(make('span',states[row.state],'phone-agent-tag'));
      if(row.priority!=='normal')line.append(make('span',row.priority==='urgent'?'Urgent':'Important','phone-agent-tag attention'));
      if(row.receipt.acknowledged_at)line.append(make('span','Acknowledged','phone-agent-tag'));
      if(row.receipt.snoozed_until)line.append(make('span','Snoozed until '+localDate(row.receipt.snoozed_until),'phone-agent-tag'));
      return line;
    }
    function renderFeed(){
      const retained=new Map(Array.from(list.children,node=>[node.dataset.item,node]));let previous=null;
      for(const row of rows){
        let card=retained.get(row.item_ref);const signature=JSON.stringify(row);
        if(!card){card=make('article','','phone-agent-item');card.dataset.item=row.item_ref;}
        const next=previous?previous.nextElementSibling:list.firstElementChild;if(next!==card)list.insertBefore(card,next);previous=card;
        retained.delete(row.item_ref);
        if(card.dataset.signature!==signature){
          card.dataset.signature=signature;card.dataset.unread=String(!row.receipt.read_at);const title=make('h2',row.title||'Agent result');
          const actions=make('div','','phone-agent-actions');actions.append(button(openLabels[row.source_kind],()=>openItem(row.item_ref)));
          card.replaceChildren(metadata(row),title,make('p',row.summary),make('small',localDate(row.occurred_at),'phone-agent-meta'),callLine(row.item_ref),actions);
        }
      }
      for(const node of retained.values())node.remove();
      empty.hidden=rows.length>0||loading;empty.textContent=selected==='needs_you'?'Nothing in this view needs your decision.':selected==='history'?'No completed or acknowledged items in this view yet.':selected==='for_you'?'Your Agent’s results will appear here.':'Your Agent has no current results to show here.';
      controls();renderCallProgress();
    }
    async function loadFeed(append=false,{silent=false}={}){
      if(!live()||loading||writing||append&&!nextCursor)return false;
      const capture=captured(),serial=++listSerial,cursor=append?nextCursor:null,filter=selected;loading=true;controls();
      if(!silent)message(append?'Loading more results…':'Checking your Agent’s results…');
      try{
        const result=page(await api('feed',{filter,limit:10,...(cursor?{cursor}:{})},capture),capture.scope);
        if(!current(capture)||serial!==listSerial||filter!==selected)return false;
        const received=result.items.map(row=>model.merge(row));
        if(append){const values=new Map(rows.map(row=>[row.item_ref,row]));for(const row of received)values.set(row.item_ref,row);rows=[...values.values()];}else rows=received;
        nextCursor=result.next_cursor;hasMore=result.has_more;setCounts(result.counts);if(!silent)message('');callRefreshPending=true;return true;
      }catch(error){failure(error,capture);return false;}
      finally{if(current(capture)&&serial===listSerial){loading=false;renderFeed();if(callRefreshPending){callRefreshPending=false;void refreshCalls(true);}}}
    }
    async function canClose(){
      if(native?.canClose)return (await native.canClose())!==false;
      if(native?.requestClose)return (await native.requestClose())!==false;
      return true;
    }
    function resetDetail(){
      detailSerial++;auditSerial++;closeNative();opened=null;detailHost.replaceChildren();detailHost.hidden=true;feed.hidden=false;message('');panel.scrollTop=feedScroll;
      const card=Array.from(list.children).find(node=>node.dataset.item===returnRef);card?.querySelector('button')?.focus({preventScroll:true});
    }
    async function back(){
      if(!await canClose())return false;resetDetail();
      return true;
    }
    async function chooseFilter(filter){
      if(!filters.includes(filter)||selected===filter||!await canClose())return;
      if(opened)resetDetail();listSerial++;loading=false;selected=filter;rows=[];nextCursor=null;hasMore=false;renderFeed();panel.scrollTop=0;void loadFeed();
    }
    function detailActions(row){
      const actions=make('div','','phone-agent-actions');actions.dataset.agentActions='true';
      if(!row.receipt.read_at)actions.append(button('Mark read',()=>mutate('read',row.item_ref),'secondary'));
      if(!row.needs_action&&!row.receipt.acknowledged_at)actions.append(button('Acknowledge',()=>mutate('ack',row.item_ref),'secondary'));
      actions.append(button(row.source_kind==='gmail_attention'?'Discuss with your Agent':'Open original Chat',()=>discuss(row.item_ref),'secondary'));
      for(const control of actions.querySelectorAll('button'))control.disabled=!live()||writing;
      return actions;
    }
    function updateDetailRow(row){
      if(opened?.item.item_ref!==row.item_ref)return;
      opened.item=row;const actions=detailHost.querySelector('[data-agent-actions]');if(actions)actions.replaceWith(detailActions(row));
      const meta=detailHost.querySelector('[data-agent-metadata]');if(meta)meta.replaceWith(metadata(row));
      native?.updateItem?.(row);
    }
    async function sourceChanged(ref,capture){
      if(!current(capture)||!live())return;
      const audit=++auditSerial,refresh={};sourceRefreshes.add(refresh);
      try{const value=detail(await api('detail',{item_ref:ref},capture),capture.scope);if(!current(capture)||opened?.item.item_ref!==ref)return;
        if(audit!==auditSerial)return;
        const row=model.merge(value.item);updateDetailRow(row);rows=rows.map(value=>value.item_ref===ref?row:value);renderFeed();void loadFeed(false,{silent:true});
      }catch(error){failure(error,capture);}finally{sourceRefreshes.delete(refresh);}
    }
    async function auditOpenSource(){
      if(!live()||!opened||sourceRefreshes.size)return;const capture=captured(),ref=opened.item.item_ref,source=opened.item.source_revision,serial=++auditSerial;
      try{
        const value=detail(await api('detail',{item_ref:ref},capture),capture.scope);
        if(!current(capture)||serial!==auditSerial||opened?.item.item_ref!==ref)return;
        const row=model.merge(value.item);
        if(row.source_revision!==source||row.state==='unavailable'){
          resetDetail();rows=rows.filter(row=>row.item_ref!==ref);renderFeed();message('This item changed. Reopen its current version before continuing.');void loadFeed(false,{silent:true});
        }else updateDetailRow(row);
      }catch(error){if(serial===auditSerial)failure(error,capture);}
    }
    async function openItem(ref,{notification=false}={}){
      if(!reference(ref)||!live()||writing||!await canClose())return false;
      const capture=captured(),serial=++detailSerial;closeNative();opened=null;returnRef=ref;feedScroll=panel.scrollTop;
      feed.hidden=true;detailHost.hidden=false;detailHost.replaceChildren();message('Opening current result…');panel.scrollTop=0;
      try{
        const value=detail(await api('detail',{item_ref:ref},capture),capture.scope);
        if(!current(capture)||serial!==detailSerial)return false;
        const sourceRevision=value.item.source_revision;value.item=model.merge(value.item);check(value.item.item_ref===ref&&value.item.source_revision===sourceRevision);
        opened=value;const top=make('div','','phone-agent-detail-top');top.append(button('← Back to results',back,'phone-agent-back'));
        if(['gmail_draft','gmail_attention'].includes(value.item.source_kind)){
          const progress=make('p','','phone-agent-email-call-status');progress.dataset.emailCallDetail='true';progress.hidden=true;top.append(progress);
        }
        detailHost.append(top);
        const row=value.item;
        if(row.source_kind==='agent_result'){
          const title=make('h1',row.title||'Agent result');title.tabIndex=-1;
          detailHost.append(title,metadata(row),make('div',value.detail.text,'phone-agent-body'),detailActions(row));
          if(value.detail.truncated)detailHost.append(make('p','This is a preview of a longer result. Open the original Chat to read the full result.','phone-agent-source-note'));
          title.focus({preventScroll:true});
        }else{
          check(typeof window.VP3PhoneAgentMediaV1?.open==='function','The private viewer is unavailable. Refresh the app to try again.');
          panel.classList.add('phone-agent-native');const body=make('div','','phone-agent-native-host');detailHost.append(body,detailActions(row));
          nativeController=new AbortController();const openedController=nativeController;
          const controller=await window.VP3PhoneAgentMediaV1.open({host:body,item:row,detail:value.detail,scope:{...capture.scope},signal:openedController.signal,
            onChanged:()=>void sourceChanged(ref,capture),onBack:()=>void back(),discuss:()=>void discuss(ref)});
          if(!current(capture)||serial!==detailSerial||openedController.signal.aborted){controller?.close?.();return false;}
          native=controller;
        }
        renderCallProgress();void refreshCalls(true);message(notification?'Opened the current notified item.':'');return true;
      }catch(error){
        if(current(capture)&&serial===detailSerial){closeNative();opened=null;detailHost.replaceChildren(button('← Back to results',back,'phone-agent-back'));
          message('This result is no longer available in the current Agent workspace. Refresh your results to check.',true);}
        failure(error,capture);return false;
      }
    }
    async function mutate(operation,ref){
      if(!live()||writing||!['read','ack'].includes(operation))return;
      const capture=captured(),row=model.get(ref);if(!row)return;writing=true;listSerial++;loading=false;statusSerial++;statusLoading=false;controls();
      if(opened)updateDetailRow(opened.item);
      try{
        const result=await api('mutate',{item_ref:ref,expected_revision:row.revision,operation},capture);scopeResponse(result,capture.scope);
        const changed=model.merge(item(result.item,capture.scope.agent_id));check(changed.item_ref===ref);
        if(!current(capture))return;rows=rows.map(value=>value.item_ref===ref?changed:value);updateDetailRow(changed);
        if(result.counts)setCounts(counts(result.counts));message(operation==='read'?'Marked read. Any requested approval is still pending.':'Acknowledged.');
      }catch(error){failure(error,capture);}
      finally{if(current(capture)){writing=false;renderFeed();if(opened)updateDetailRow(opened.item);void loadFeed(false,{silent:true});void refreshStatus(true);}}
    }
    async function discuss(ref){
      if(!live()||writing||!await canClose())return false;
      const capture=captured(),row=model.get(ref);if(!row)return false;
      try{
        const result=await api('discuss',{item_ref:ref,expected_revision:row.revision},capture);scopeResponse(result,capture.scope);
        const value=result.target;check(isObject(value)&&['chat','chat_message'].includes(value.kind)&&value.agent_id===scope.agent_id&&integer(value.conversation_id,1)
          &&(value.kind!=='chat_message'||integer(value.message_id,1)),'The source conversation is unavailable.');
        if(!current(capture))return false;
        const url=new URL('chat.php',location.href);url.searchParams.set('agent',value.agent_id>0?String(value.agent_id):'system');url.searchParams.set('conversation_id',String(value.conversation_id));
        // Navigation carries only server-verified identifiers. It never sends a Chat message.
        location.assign(url.href);return true;
      }catch(error){failure(error,capture);return false;}
    }
    function renderEnrollment(note=''){
      enrollment.hidden=!device||device.status!=='approved';if(enrollment.hidden)return;
      const signature=JSON.stringify([device.id,reachable,owner?.state,owner?.revision,owner?.scope_revision,owner?.agents,owner?.preferences,writing,note]);
      if(signature===settingsSignature)return;settingsSignature=signature;
      if(!enrollment.contains(enrollmentContent))enrollment.replaceChildren(enrollmentContent);
      enrollmentContent.replaceChildren();
      const heading=make('h2','My Agent on this phone');heading.id='phone-agent-enrollment-title';enrollmentContent.append(heading);
      if(note)enrollmentContent.append(make('p',note,'phone-agent-feedback'));
      if(!reachable){enrollmentContent.append(make('p','Reconnect to check your private Agent workspace.'));return;}
      if(!owner){enrollmentContent.append(make('p','Check whether this phone can receive your private Agent results.'),button('Check Agent access',()=>refreshStatus(true),'secondary'));return;}
      if(owner.state==='sign_in_required'){
        enrollmentContent.append(make('p','Sign in as the account owner to receive your private Agent results and review email on this phone.'));
        const link=make('a','Sign in to your account');const url=new URL('login.php',location.href);url.searchParams.set('return_to',new URL('phone-location.php',location.href).pathname);link.href=url.href;
        const actions=make('div','','phone-agent-enrollment-actions');actions.append(link,button('Check sign-in',()=>refreshStatus(true),'secondary'));enrollmentContent.append(actions);return;
      }
      if(!owner.owner_eligible||owner.state==='unavailable'){enrollmentContent.append(make('p','Private Agent access is unavailable for this connection. Your existing phone features are still available.'),button('Check again',()=>refreshStatus(true),'secondary'));return;}
      const select=make('select');select.setAttribute('aria-label','Main Agent for this phone');
      for(const candidate of owner.agents){const option=make('option',candidate.name);option.value=String(candidate.id);select.append(option);}
      select.value=String(owner.agent?.id??0);const field=make('label','','phone-agent-setting');field.append(make('span','Main Agent'),select);enrollmentContent.append(field);
      if(owner.state==='ready'){
        enrollmentContent.append(make('p','Your private Agent results and email decisions are available in the Agent tab.'));
        const actions=make('div','','phone-agent-enrollment-actions');actions.append(button('Use selected Agent',()=>changeEnrollment('select_agent',{agent_id:Number(select.value)}),'secondary'),
          button('Open Agent',()=>shell.openAgent(),'secondary'));enrollmentContent.append(actions);
        const label=make('label'),toggle=make('input');toggle.type='checkbox';toggle.checked=owner.preferences.notifications_enabled;
        label.append(toggle,make('span','Notify this phone about new Agent items. Phone notification permission is managed in Info.'));
        toggle.addEventListener('change',event=>{if(event.isTrusted)void changeEnrollment('preferences',{notifications_enabled:toggle.checked});});enrollmentContent.append(label,
          button('Remove Agent access',()=>changeEnrollment('revoke',{}),'text-button'));
        mountCallSettings();
      }else{
        const label=make('label'),confirm=make('input');confirm.type='checkbox';label.append(confirm,make('span','This is my phone. Allow it to show my private Agent results and email reviews while I am signed in.'));
        const enroll=button('Connect my Agent',()=>{if(confirm.checked)return changeEnrollment('enroll',{confirmed:true,agent_id:Number(select.value)});});enroll.disabled=true;
        confirm.addEventListener('change',()=>{enroll.disabled=!confirm.checked||writing;});enrollmentContent.append(label,enroll);
      }
      if(callSettingsHost)callSettingsHost.inert=writing;
      for(const control of enrollmentContent.querySelectorAll('button,select,input'))if(writing)control.disabled=true;
    }
    function acceptStatus(value){
      const previousCounts=owner?.counts;
      const next=value.state==='ready'?{device_id:value.device_id,enrollment_revision:value.enrollment_revision,scope_revision:value.scope_revision,
        agent_id:value.agent.id,agent_name:value.agent.name,conversation_id:value.conversation_id,csrf_token:value.csrf_token}:null;
      const changed=!next||!scope||scope.device_id!==next.device_id||scope.scope_revision!==next.scope_revision||scope.enrollment_revision!==next.enrollment_revision;
      if(changed)clearPrivate();value.counts=value.counts||(!changed&&previousCounts)||{unread:0,needs_action:0,complete:false};owner=value;
      if(next){scope=changed?next:Object.assign(scope,next);model.bind([next.device_id,next.enrollment_revision,next.scope_revision].join('/'));heading.textContent=value.agent.name||'Your Agent';
        setCounts(value.counts);shell.setAgent({enabled:true,unread:value.counts.unread,countsComplete:value.counts.complete,defaultView:true});setupCalls();}
      settingsSignature='';renderEnrollment();
      if(next&&visible&&!opened&&(!rows.length||changed))void loadFeed();
      else if(next&&visible&&opened)void auditOpenSource();
      if(next)void refreshCalls();
      if(next&&pendingNotification){const ref=pendingNotification;pendingNotification=null;void shell.openAgent().then(ok=>{if(ok)void openItem(ref,{notification:true});});}
    }
    async function refreshStatus(force=false){
      if(!device||!reachable||document.hidden||statusLoading||!force&&Date.now()-statusAt<30000)return;
      const held=device.id,serial=++statusSerial;statusLoading=true;
      try{const result=await api('status');if(device?.id!==held||serial!==statusSerial)return;acceptStatus(status(result,held));statusAt=Date.now();}
      catch(error){if(device?.id===held&&serial===statusSerial&&error.name!=='AbortError'){invalidate('Agent access could not be verified. Check again when connected.');}}
      finally{if(serial===statusSerial)statusLoading=false;}
    }
    async function changeEnrollment(action,body){
      if(!reachable||writing||!owner?.owner_eligible||!await canClose()||callSettings&&!await callSettings.requestClose())return;
      const held=device.id,revision=owner.revision;writing=true;statusSerial++;statusLoading=false;renderEnrollment();
      try{const result=await api(action,{expected_revision:revision,...body});if(device?.id!==held)return;
        writing=false;acceptStatus(status(result,held));statusAt=Date.now();if(action==='enroll')void shell.openAgent();}
      catch(error){if(device?.id===held){writing=false;failure(error);settingsSignature='';renderEnrollment('The change could not be confirmed. Check the current settings before trying again.');}}
      finally{if(device?.id===held){writing=false;controls();settingsSignature='';renderEnrollment();}}
    }
    function bind(next,available){
      const valid=next?.status==='approved'&&reference(next.id),changed=(valid?next.id:null)!==device?.id;
      const wasReachable=reachable;reachable=Boolean(valid&&available);device=valid?next:null;
      if(changed||wasReachable&&!reachable){statusSerial++;statusLoading=false;statusAt=0;pendingNotification=null;owner=null;clearPrivate();settingsSignature='';if(changed)recoveredScope='';}
      enrollment.hidden=!device;if(!device)return;
      renderEnrollment();controls();if(reachable)void refreshStatus(!wasReachable||changed);
    }
    function setVisible(value){visible=value===true;if(visible){void refreshStatus();if(live()&&!opened&&!rows.length)void loadFeed();}void refreshCalls();}
    async function openNotification(ref){
      if(!reference(ref))return false;pendingNotification=ref;
      if(live()){pendingNotification=null;if(await shell.openAgent())return openItem(ref,{notification:true});return false;}
      await refreshStatus(true);return false;
    }
    document.addEventListener('visibilitychange',()=>{
      if(document.hidden){model.cancel();statusSerial++;listSerial++;detailSerial++;statusLoading=loading=false;}
      else{statusAt=0;callAt=0;void refreshStatus(true);}
    });
    window.addEventListener('pagehide',()=>{statusSerial++;statusLoading=false;owner=null;clearPrivate();enrollment.replaceChildren();});
    window.addEventListener('pageshow',()=>{statusAt=0;callAt=0;void refreshStatus(true);});
    setInterval(()=>{if(device&&reachable&&!document.hidden)void refreshStatus();},30000);
    setInterval(()=>void refreshCalls(),10000);
    panel.addEventListener('scroll',()=>{if(!opened)void refreshCalls();},{passive:true});
    async function leave(){if(!await canClose())return false;if(opened)resetDetail();return true;}
    return Object.freeze({bind,setVisible,canClose,leave,openNotification,refresh:()=>refreshStatus(true)});
  };
})();
