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
  function normalizeSelection(value={}) {
    const tab=tabs.some(([key])=>key===value.tab)?value.tab:'overview';
    const publicConversationId=positive(value.publicConversationId)?value.publicConversationId:0;
    const callId=positive(value.callId)?value.callId:0;
    return {tab,publicConversationId,callId};
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
    const requests=new Map();
    const initial=captureScope(scope);
    const initialKey=scopeKey(initial);
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
      const staleText=Boolean(saved.text&&(!sameTextSource(saved.textSource,textSource(value))
        || saved.linkedPrepareId&&(!saved.draft?.current||saved.draft.state!=='ready'||saved.draft.prepare_request_id!==saved.linkedPrepareId)));
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
      input.addEventListener('input',()=>{
        if(input.value&&!saved.textSource)saved.textSource=textSource(value);
        saved.text=input.value;saved.edited=true;
        if(!input.value){saved.textSource=null;saved.linkedPrepareId='';}
      });label.append(input);form.append(label);
      const canReply=turn?turn.can_reply===true:value.conversation.can_manual_reply===true;
      input.disabled=Boolean(busy||!canReply);
      const send=node('button','Send reply to visitor');send.type='submit';send.disabled=Boolean(!canReply||busy||uncertain||staleText);
      form.append(send);form.addEventListener('submit',event=>{event.preventDefault();void performAction('reply',value,saved);});content.append(form);
      if (staleText) {
        const changed=node('section','','vp3-profile-recovery');
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
      if (saved.notice) { const feedback=node('p',saved.notice,'vp3-profile-feedback');feedback.setAttribute('role','status');content.append(feedback); }
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
    async function loadCalls(mine) {
      const result=await request('calls',{...(selection.callId?{call_id:selection.callId}:{}),...(selection.publicConversationId?{public_conversation_id:selection.publicConversationId}:{})});
      if (!current(mine)) return;
      const value=result.data.calls;
      if (!value||!Array.isArray(value.calls)||value.calls.length>20||!['ready','limited','unavailable','unconfigured'].includes(value.coverage)) throw new Error('The call list could not be verified.');
      if (value.calls.some(call=>!positive(call?.call_id)||!positive(call?.public_conversation_id))) throw new Error('The call targets could not be verified.');
      callPage=value;content.replaceChildren(heading('Calls','Current calls and calls already delivered to this Chat.'));
      if (value.coverage==='limited') content.append(node('p','Some call sources could not be checked.','vp3-profile-note'));
      if (['unavailable','unconfigured'].includes(value.coverage)) {content.append(node('p','Calls are not available in this Chat.','vp3-profile-empty'));return;}
      const list=node('div','','vp3-profile-calls');
      for (const call of value.calls) {
        const row=node('article','','vp3-profile-call');row.dataset.profileWorkspaceCall=String(call.call_id);
        row.append(node('strong',plain(call.title,240)||plain(call.visitor_label,240)||'Profile call'),node('p',plain(call.label,320)||plain(call.state_label,320)||plain(call.state,40)));
        const open=button('View call',()=>select({tab:'calls',callId:call.call_id,publicConversationId:call.public_conversation_id}));row.append(open);list.append(row);
      }
      if (!value.calls.length) list.append(node('p','No current or previously delivered calls in this view.','vp3-profile-empty'));
      content.append(list);
      if (value.has_more) content.append(node('p','This is a bounded view of current and delivered calls.','vp3-profile-note'));
      if (!selection.callId) return;
      const call=value.selected||value.calls.find(row=>row.call_id===selection.callId);
      if (!call||call.call_id!==selection.callId||selection.publicConversationId&&call.public_conversation_id!==selection.publicConversationId) throw new Error('The selected call is not available in this Chat.');
      if (!selection.publicConversationId) selection.publicConversationId=call.public_conversation_id;
      const detail=node('section','','vp3-profile-call-detail');detail.append(heading('Selected call'));
      const focus=button('Open call in Chat',()=>{
        const context=callContext(call);
        if (!context) return;
        const focused=window.VP3ProfileCallsPa3c?.focusOwnerCall?.({callId:context.callId,publicConversationId:context.publicConversationId,
          chatConversationId:context.chatConversationId,chatAgentId:context.chatAgentId},{beforeFocus:()=>onClose?.(false)});
        if (!focused) say('That live call card is not available in this Chat. The call has not been restarted.');
      });detail.append(focus,button('Open conversation',()=>select({tab:'conversations',publicConversationId:call.public_conversation_id})));
      const activity=node('section');detail.append(activity);content.append(detail);
      if (call.assisted===false) {
        activity.append(node('p','This is a direct call. No Agent transcript or research is available.','vp3-profile-note'));
      } else if (call.activity_available!==true) {
        activity.append(node('p','Agent call activity is not available for this call and current access.','vp3-profile-note'));
      } else if (window.VP3ProfileCallActivityPa3c?.mount) {
        callActivity=window.VP3ProfileCallActivityPa3c.mount(activity,{
          context:()=>callContext(call),
          read:async({context,after,signal})=>callResult(await request('call_activity',{call_id:context.callId,public_conversation_id:context.publicConversationId,after},{context,signal}),'call_activity',context),
          mutate:async({context,operation,input,signal})=>{
            if (!['set_assistance','research'].includes(operation)) throw new Error('Unknown call action.');
            return callResult(await request(operation==='research'?'call_research':'call_set_assistance',
              {call_id:context.callId,public_conversation_id:context.publicConversationId,...input},{context,signal,method:'POST'}),'call_action',context);
          },onUnavailable:message=>{if(current(mine))say(plain(message,500)||'Call activity is unavailable.');}
        });callActivity?.select(call.call_id);
      } else activity.append(node('p','Call activity is unavailable. The live Chat call card remains in place.','vp3-profile-note'));
    }
    function loadAnalytics(mine) {
      content.replaceChildren(heading('Analytics','Recorded activity and conversion evidence. Reporting windows retain their displayed timezone.'));
      const target=node('section');content.append(target);
      if (!window.VP3ProfileAnalytics?.mountWorkspace) {target.append(node('p','Analytics is unavailable.','vp3-profile-empty'));return;}
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
      rememberInput();abortReads();disposeTab();
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
        else if (selection.tab==='analytics') loadAnalytics(mine);
        else await loadReports(mine);
        if(current(mine))say('');
      } catch(error) { if(current(mine))statusError(error,mine); }
    }
    function select(value={}) {
      if (!alive||!currentScope()) return false;
      rememberInput();const next=normalizeSelection(value);
      const unchanged=JSON.stringify(selection)===JSON.stringify(next);
      selection=next;
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
  window.VP3ProfileWorkspaceV1=Object.freeze({mountWorkspace});
  window.dispatchEvent(new Event('vp3:profile-workspace-ready'));
})();
