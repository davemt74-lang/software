(() => {
  'use strict';
  if (window.VP3EmailFollowthroughV1) return;
  const refs = /^[a-f0-9]{32}$/, revisions = /^[a-f0-9]{64}$/;
  const instances = new Set();
  let actor = 0, epoch = 0;
  const noticeOwner = String(window.VP3_CHAT_EMAIL_V1?.ownerKey || '');
  const noticeBinding = String(window.STONEFELLOW_CHAT?.workingAttentionBinding || '');
  let noticeTarget = (() => {
    const query = new URLSearchParams(location.search),ref = query.get('gmail_followthrough'),agent = query.get('agent'),conversation = query.get('conversation_id');
    if (!refs.test(ref || '') || !/^(?:system|[1-9][0-9]*)$/.test(agent || '') || !/^[1-9][0-9]*$/.test(conversation || '')) return null;
    const target = {followthrough_ref:ref,agent_id:agent === 'system' ? 0 : Number(agent),conversation_id:Number(conversation)};
    return Number.isSafeInteger(target.agent_id) && Number.isSafeInteger(target.conversation_id) ? target : null;
  })(),noticeStarted = false,pendingNotice = null;
  const node = (tag,text = '',className = '') => {
    const element = document.createElement(tag);element.textContent = text;
    if (className) element.className = className;return element;
  };
  const button = (text,action) => { const element = node('button',text);element.type = 'button';element.addEventListener('click',action);return element; };
  const field = (text,input) => {
    const element = node('label','','vp3-email-followthrough-field');input.setAttribute('aria-label',text);
    element.append(node('span',text),input);return element;
  };
  const validTime = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|\+00:00)$/.test(value) && Number.isFinite(Date.parse(value));
  const validZone = value => {
    if (typeof value !== 'string' || value.length > 80 || !value) return false;
    try { new Intl.DateTimeFormat('en',{timeZone:value});return true; } catch { return false; }
  };
  const browserZone = () => Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  const utc = value => new Date(value).toISOString().replace('.000Z','Z');
  function policy(value) {
    if (value === undefined) return {enabled:false,deadline_at:null,time_zone:browserZone(),prepare_if_overdue:false,priority:'normal'};
    if (!value || typeof value.enabled !== 'boolean' || typeof value.prepare_if_overdue !== 'boolean'
      || !validZone(value.time_zone) || !['low','normal','high'].includes(value.priority)
      || value.deadline_at !== null && !validTime(value.deadline_at)) throw new Error('The saved response deadline could not be verified. Reload this email.');
    return {enabled:value.enabled,deadline_at:value.deadline_at === null ? null : utc(value.deadline_at),time_zone:value.time_zone,
      prepare_if_overdue:value.enabled && value.prepare_if_overdue,priority:value.priority};
  }
  function localTime(value,zone) {
    if (!validTime(value) || !validZone(zone)) return '';
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit',
      hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(new Date(value)).map(part => [part.type,part.value]));
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}`;
  }
  function deadline(local,zone,allowAmbiguous = false) {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(local);
    if (!match || !validZone(zone)) return null;
    const desired = Date.UTC(+match[1],+match[2]-1,+match[3],+match[4],+match[5],+(match[6] || 0));
    const normalized = `${match[1]}-${match[2]}-${match[3]}T${match[4]}:${match[5]}:${match[6] || '00'}`;
    let guess = desired;
    for (let index = 0;index < 4;index++) {
      const actual = localTime(utc(guess),zone);
      if (actual === normalized) {
        if (!allowAmbiguous && [-120,-90,-60,-30,30,60,90,120].some(minutes => localTime(utc(guess+minutes*60000),zone) === normalized)) return null;
        return utc(guess);
      }
      guess += desired-Date.parse(actual+'Z');
      if (!Number.isFinite(guess)) return null;
    }
    return null;
  }
  function formatTime(value,zone) {
    return validTime(value) ? new Intl.DateTimeFormat(undefined,{dateStyle:'medium',timeStyle:'short',timeZone:validZone(zone) ? zone : browserZone()}).format(new Date(value)) : '';
  }
  function settings(value,options = {}) {
    const root = node('section','','vp3-email-followthrough-settings');
    const enabled = node('input');enabled.type = 'checkbox';
    const enableLabel = node('label','','vp3-email-followthrough-check');enableLabel.append(enabled,node('span','Set response deadline'));
    const fields = node('div','','vp3-email-followthrough-fields');
    const when = node('input');when.type = 'datetime-local';when.step = '1';
    const zone = node('select');
    const zones = new Set([browserZone(),'UTC',...(Intl.supportedValuesOf?.('timeZone') || [])]);
    const addZone = value => { if (![...zone.options].some(option => option.value === value)) { const option = node('option',value.replaceAll('_',' '));option.value = value;zone.append(option); } };
    for (const name of zones) addZone(name);
    const prepare = node('input');prepare.type = 'checkbox';
    const prepareLabel = node('label','','vp3-email-followthrough-check');prepareLabel.append(prepare,node('span','Prepare a follow-up draft if overdue'));
    const priority = node('select');
    for (const name of ['low','normal','high']) { const option = node('option',name[0].toUpperCase()+name.slice(1));option.value = name;priority.append(option); }
    const preview = node('small');preview.setAttribute('aria-live','polite');
    fields.append(field('Reply expected by',when),field('Time zone',zone),field('Follow-up priority',priority),prepareLabel,
      node('small','Gmail is checked before an email is marked overdue. Prepared drafts stay available for your review.'),preview);
    root.append(enableLabel,fields);
    const read = () => {
      const on = options.alwaysEnabled === true || enabled.checked;
      return {enabled:on,deadline_at:on ? deadline(when.value,zone.value) : null,time_zone:zone.value,prepare_if_overdue:on && prepare.checked,priority:priority.value};
    };
    const sync = () => {
      fields.hidden = !enabled.checked && !options.alwaysEnabled;
      const value = read();preview.textContent = value.enabled && value.deadline_at ? 'Deadline: '+formatTime(value.deadline_at,value.time_zone)+' · '+value.time_zone : '';
    };
    const update = value => {
      value = policy(value);const timeZone = value.enabled ? value.time_zone : browserZone();addZone(timeZone);enabled.checked = value.enabled;zone.value = timeZone;
      when.value = value.deadline_at ? localTime(value.deadline_at,timeZone) : '';prepare.checked = value.prepare_if_overdue;priority.value = value.priority;sync();
    };
    for (const input of [enabled,when,zone,prepare,priority]) input.addEventListener('input',() => { sync();options.onChange?.(read()); });
    enableLabel.hidden = options.alwaysEnabled === true;update(value);
    return {root,read,update,validate:() => {
      const value = read();
      if (!value.enabled) return value;
      if (!value.deadline_at) {
        when.focus();throw new Error(deadline(when.value,zone.value,true)
          ? 'This local time occurs twice. Choose another time or use UTC.'
          : 'Choose a valid response date and time in the selected time zone.');
      }
      if (Date.parse(value.deadline_at) <= Date.now() || Date.parse(value.deadline_at) > Date.now()+30*86400000) {
        when.focus();throw new Error('Choose a future response deadline within 30 days.');
      }
      return value;
    },setDisabled:disabled => { for (const input of [enabled,when,zone,prepare,priority]) input.disabled = Boolean(disabled); }};
  }
  function contextCurrent(context,binding) {
    const cfg = window.VP3_CHAT_EMAIL_V1,chat = window.STONEFELLOW_CHAT;
    const live = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    return context && String(context.owner_key) === String(cfg?.ownerKey) && String(chat?.userId) === String(context.owner_key)
      && context.agent_id === Number(cfg?.agentId) && context.agent_id === Number(chat?.agentId || 0)
      && context.agent_id === Number(live?.agentId?.()) && Number.isSafeInteger(context.conversation_id) && context.conversation_id > 0
      && context.conversation_id === Number(live?.conversationId?.()) && !actor && !window.VP3ParticipantContinuity?.selectedContext?.()
      && live?.isReady?.() === true && !live?.isRestoring?.() && String(chat?.workingAttentionBinding || '') === binding;
  }
  function lifetime(parent,options) {
    const context = {...options.context},binding = String(window.STONEFELLOW_CHAT?.workingAttentionBinding || ''),born = epoch;
    let disposed = false,request = null;
    const current = () => !disposed && born === epoch && parent.isConnected && !document.hidden
      && contextCurrent(context,binding) && options.isCurrent?.() !== false;
    const stop = () => { request?.abort();request = null; };
    const dispose = () => { disposed = true;stop();instances.delete(api);parent.replaceChildren(); };
    const call = async (action,fields) => {
      if (!current()) throw new DOMException('The private Gmail view changed.','AbortError');
      const cfg = window.VP3_CHAT_EMAIL_V1;
      let endpoint;try { endpoint = new URL(cfg?.endpoint,location.href); } catch { throw new Error('Gmail follow-ups are unavailable.'); }
      if (!cfg?.csrf || endpoint.origin !== location.origin || endpoint.username || endpoint.password) throw new Error('Gmail follow-ups are unavailable.');
      stop();const controller = request = new AbortController();let timeout = false;
      const timer = setTimeout(() => { timeout = true;controller.abort(); },30000);
      try {
        const response = await fetch(endpoint.href,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,
          headers:{'Content-Type':'application/json'},body:JSON.stringify({action,...context,csrf_token:cfg.csrf,...fields})});
        const result = await response.json().catch(() => null);
        if (!current() || request !== controller || controller.signal.aborted) throw new DOMException('The private Gmail view changed.','AbortError');
        if (!response.ok || result?.ok !== true) {
          if (cfg.fixedDraftId && [401,403,419].includes(response.status)) window.dispatchEvent(new Event('vp3:phone-agent-access-changed'));
          throw Object.assign(new Error(typeof result?.error === 'string' ? result.error : 'The follow-up could not be verified. Refresh it before continuing.'),{status:response.status});
        }
        return result;
      } catch (error) { if (timeout && current()) throw new Error('The follow-up check took too long. Refresh its saved status before trying again.');throw error; }
      finally { clearTimeout(timer);if (request === controller) request = null; }
    };
    const api = {context,current,call,stop,dispose};instances.add(api);return api;
  }
  function checkedItem(value,context,expectedRevision = null) {
    if (!refs.test(value?.followthrough_ref || '') || !Number.isSafeInteger(value.revision) || value.revision < 0
      || value.state_version !== undefined && (!Number.isSafeInteger(value.state_version) || value.state_version < 1)
      || !revisions.test(value.mailbox_revision) || expectedRevision && value.mailbox_revision !== expectedRevision
      || value.agent_id !== context.agent_id || value.conversation_id !== context.conversation_id
      || !['subject','recipient'].every(key => typeof value[key] === 'string' && value[key].length <= 1000)
      || !['waiting','overdue','replied','resolved'].includes(value.outcome) || typeof value.paused !== 'boolean'
      || !validTime(value.deadline_at) || !validZone(value.time_zone) || typeof value.prepare_if_overdue !== 'boolean'
      || !['low','normal','high'].includes(value.priority)
      || !['never_checked','verified','unavailable','ambiguous'].includes(value.check?.state)
      || ![null,'no_reply','reply'].includes(value.check.result) || value.check.checked_at !== null && !validTime(value.check.checked_at)
      || !value.capabilities || !['change_deadline','pause','resume','resolve','check'].every(key => typeof value.capabilities[key] === 'boolean'))
      throw new Error('This follow-up could not be verified. Refresh the saved status.');
    for (const [key,id] of [['draft_target','draft_id'],['reply_target','message_id']]) {
      const target = value[key];
      if (target !== null && (!target || target.agent_id !== context.agent_id || target.conversation_id !== context.conversation_id
        || (id === 'draft_id' ? !refs.test(target[id] || '') : !Number.isSafeInteger(target[id]) || target[id] < 1)))
        throw new Error('This follow-up’s original Chat could not be verified.');
    }
    return value;
  }
  const olderItem = (next,current) => Boolean(current && (next.followthrough_ref !== current.followthrough_ref
    || next.revision < current.revision || current.state_version !== undefined
      && (next.state_version === undefined || next.state_version < current.state_version)));
  function mount(parent,value,options = {}) {
    const life = lifetime(parent,options);let item,editing = false,busy = false,stale = null,checkFailed = false;
    const expected = () => typeof options.mailboxRevision === 'function' ? options.mailboxRevision() : options.mailboxRevision || null;
    const root = node('article','','vp3-email-followthrough-card'),body = node('div'),status = node('p','','vp3-email-followthrough-status');
    status.setAttribute('role','status');root.append(body,status);parent.append(root);
    const controls = () => { for (const control of body.querySelectorAll('button,input,select')) control.disabled = busy;root.setAttribute('aria-busy',String(busy)); };
    const fail = error => {
      if (!life.current() || error.name === 'AbortError') return;
      if ([401,403,404,409,419].includes(error.status)) { body.replaceChildren();delete root.dataset.outcome;item = null;stale = null;editing = false; }
      status.textContent = error.message;
    };
    const act = async (operation,fields = {}) => {
      if (!life.current() || busy || !item) return false;
      const selected = item;busy = true;controls();status.textContent = operation === 'check' ? 'Checking this exact Gmail thread…' : 'Saving follow-up…';
      try {
        const result = await life.call(operation === 'check' ? 'followthrough_check' : 'followthrough_update',{
          followthrough_ref:selected.followthrough_ref,expected_revision:selected.revision,...(operation === 'check' ? {} : {operation}),...fields});
        const next = checkedItem(result.item,life.context,expected());
        if (!life.current() || item !== selected) return false;
        if (olderItem(next,selected)) throw new Error('The follow-up changed. Refresh its saved status.');
        item = stale && olderItem(next,stale) ? stale : next;
        editing = false;stale = null;checkFailed = false;status.textContent = '';render();options.onChange?.(item);
        window.dispatchEvent(new CustomEvent('vp3:email-followthrough-changed',{detail:{...life.context,followthrough_ref:item.followthrough_ref}}));return true;
      } catch (error) { if (operation === 'check' && life.current() && error.name !== 'AbortError') { checkFailed = true;render(); }fail(error);return false; }
      finally { busy = false;controls(); }
    };
    const render = () => {
      body.replaceChildren();root.dataset.followthrough = item.followthrough_ref;
      const overdueVerified = item.check.state === 'verified' && item.check.result === 'no_reply'
        && validTime(item.check.checked_at) && Date.parse(item.check.checked_at) >= Date.parse(item.deadline_at);
      const uncertain = checkFailed || item.outcome === 'overdue' && !overdueVerified || item.outcome === 'replied' && !(item.check.state === 'verified' && item.check.result === 'reply');
      const label = uncertain ? 'Needs checking' : item.awaiting_new_deadline === true && item.outcome === 'waiting'
        ? 'Follow-up sent · waiting for reply' : ({waiting:'Waiting for reply',overdue:'Overdue',replied:'Reply received',resolved:'Resolved'})[item.outcome];
      root.dataset.outcome = uncertain ? 'unverified' : item.outcome;
      body.append(node('strong',(item.paused ? 'Paused · ' : '')+label),node('h4',item.subject || '(No subject)'),node('p','To '+item.recipient),
        node('p',item.awaiting_new_deadline === true ? 'Choose a new response deadline to schedule another follow-up.'
          : 'Reply expected '+formatTime(item.deadline_at,item.time_zone)+' · '+item.time_zone));
      if (uncertain || item.check.state === 'unavailable' || item.check.state === 'ambiguous')
        body.append(node('p',item.check.state === 'ambiguous' ? 'Gmail could not establish a reply outcome. Review the thread before continuing.' : 'The current reply outcome could not be verified. Check Gmail again before following up.'));
      else if (validTime(item.check.checked_at)) body.append(node('small','Checked '+formatTime(item.check.checked_at,item.time_zone)));
      if (item.outcome === 'resolved') body.append(node('small','Marked resolved by you.'));
      if (item.priority !== 'normal') body.append(node('small',item.priority === 'high' ? 'High follow-up priority' : 'Low follow-up priority'));
      if (typeof item.detail === 'string' && item.detail.length <= 1000 && !uncertain) body.append(node('p',item.detail));
      const preparation = item.preparation;
      const preparationReasons = {
        initiative_paused:'Draft preparation is waiting because Initiative is paused for this Agent.',
        linked_elsewhere:'Draft preparation is waiting because this Agent is linked to another Chat.',
        source_changed:'The thread or response deadline changed. Check the current email before preparing a follow-up.',
        source_unavailable:'Gmail could not verify the source email. Check the thread before preparing a follow-up.',
        model_unavailable:'The writing model is unavailable. This email’s response deadline is still tracked.',
        inference_failed:'The Agent could not prepare the follow-up draft. Check the email before another attempt.',
        dispatch_uncertain:'The draft attempt could not be confirmed. It will not be retried automatically.',
        upgrade_required:'Follow-up drafting is unavailable until the application update is complete.',
        draft_unavailable:'The prepared draft is no longer available. Check the email and response deadline.',
        draft_discarded:'The follow-up draft was discarded. Choose a new response deadline to authorize another draft.',
        quota_exhausted:'Draft preparation is waiting because this Agent’s available usage has been used.',
        route_unavailable:'The writing service is unavailable. This email’s response deadline is still tracked.',
        tracking_resolved:'Follow-up tracking is resolved.',reply_observed:'A reply was received; another follow-up draft is not needed.',
        awaiting_preparation:'Waiting for background preparation of your follow-up draft.'};
      const preparationStates = {not_requested:'',not_due:'A draft can be prepared after Gmail verifies a missed deadline.',
        paused:'Draft preparation is paused.',waiting_for_deadline:'Choose a new response deadline before another follow-up draft.',
        ready:'Follow-up draft ready for review.',preparing:'Preparing your follow-up draft…',blocked:'Draft preparation is currently blocked.',
        failed:'The follow-up draft could not be prepared.',unavailable:'Draft preparation could not be verified.'};
      if (preparation && !item.awaiting_new_deadline) {
        const explanation = Object.hasOwn(preparationReasons,preparation.reason) ? preparationReasons[preparation.reason]
          : Object.hasOwn(preparationStates,preparation.state) ? preparationStates[preparation.state] : 'Draft preparation could not be verified.';
        if (explanation) body.append(node('small',explanation,'vp3-email-followthrough-preparation'));
      }
      const actions = node('div','','vp3-email-followthrough-actions');
      if (item.draft_target && !item.awaiting_new_deadline && (!preparation || preparation.state === 'ready') && typeof options.openDraft === 'function') actions.append(button('Review follow-up draft',event => {
        if (event.isTrusted && life.current() && !busy) options.openDraft({...item.draft_target},event.currentTarget);
      }));
      if (item.reply_target && typeof options.openReply === 'function') actions.append(button('Read reply',event => {
        if (event.isTrusted && life.current() && !busy) options.openReply({...item.reply_target},event.currentTarget);
      }));
      const manage = node('details','','vp3-email-followthrough-manage');manage.append(node('summary','Manage follow-up'));
      const tools = node('div','','vp3-email-followthrough-actions');
      if (item.capabilities.check) tools.append(button('Check Gmail now',() => void act('check')));
      if (item.capabilities.pause) tools.append(button('Pause follow-up',() => void act('pause')));
      if (item.capabilities.resume) tools.append(button('Resume follow-up',() => void act('resume')));
      if (item.capabilities.resolve) tools.append(button('Mark resolved',() => void act('resolve')));
      if (item.capabilities.change_deadline) tools.append(button('Change deadline',() => {
        if (!life.current() || busy) return;
        editing = true;tools.hidden = true;
        const editor = settings({enabled:true,deadline_at:item.deadline_at,time_zone:item.time_zone,prepare_if_overdue:item.prepare_if_overdue,priority:item.priority},{alwaysEnabled:true});
        const form = node('div','','vp3-email-followthrough-edit'),buttons = node('div','','vp3-email-followthrough-actions');
        buttons.append(button('Save deadline',() => { try { const value = editor.validate();delete value.enabled;void act('deadline',value); } catch (error) { status.textContent = error.message; } }),
          button('Cancel deadline change',() => { editing = false;if (stale) { item = stale;stale = null; }status.textContent = '';render(); }));
        form.append(editor.root,buttons);manage.append(form);editor.root.querySelector('input[type="datetime-local"]')?.focus();
      }));
      if (tools.childElementCount) { manage.append(tools);body.append(actions,manage); } else body.append(actions);
      controls();
    };
    const update = value => {
      if (!life.current()) return false;
      const next = checkedItem(value,life.context,expected());
      if (olderItem(next,item) || olderItem(next,stale)) return false;
      if (editing || busy) { stale = next;return false; }
      if (item && JSON.stringify(next) === JSON.stringify(item)) return true;
      item = next;checkFailed = false;render();return true;
    };
    try { update(value); } catch (error) { fail(error); }
    return {update,dispose:life.dispose,isEditing:() => editing || busy,isOlder:next => olderItem(next,item) || olderItem(next,stale)};
  }
  function mountList(parent,options = {}) {
    const life = lifetime(parent,options),cards = new Map();
    let busy = false,mailboxRevision = null,nextCursor = null,loadedFilter = null,pinnedRef = null,mutationVersion = 0,refreshQueued = false;
    let listed = [],usedCursors = new Set();
    const root = node('section','','vp3-email-followthrough-list'),heading = node('div','','vp3-email-followthrough-top');
    const title = node('h3','Follow-ups in this Chat'),filter = node('select');filter.setAttribute('aria-label','Follow-up filter');
    for (const [value,label] of [['open','Open follow-ups'],['all','All outcomes']]) { const option = node('option',label);option.value = value;filter.append(option); }
    const currentNotice = () => pendingNotice && pendingNotice.epoch === epoch && pendingNotice.context.agent_id === life.context.agent_id
      && pendingNotice.context.conversation_id === life.context.conversation_id && pendingNotice.context.owner_key === life.context.owner_key ? pendingNotice : null;
    if (currentNotice()) filter.value = 'all';
    const reload = button('Refresh follow-ups',() => void refresh()),summary = node('small'),status = node('p'),pinned = node('div'),rows = node('div'),more = node('small');
    const loadMore = button('Load more follow-ups',() => void refresh(true));loadMore.hidden = true;
    status.setAttribute('role','status');heading.append(title,filter,reload);root.append(heading,summary,status,pinned,rows,more,loadMore);parent.append(root);
    const controls = () => { filter.disabled = reload.disabled = loadMore.disabled = busy;loadMore.hidden = !nextCursor; };
    const editing = () => [...cards.values()].some(card => card.view.isEditing());
    const clear = () => {
      for (const card of cards.values()) card.view.dispose();cards.clear();mailboxRevision = nextCursor = loadedFilter = pinnedRef = null;
      listed = [];usedCursors.clear();pinned.replaceChildren();rows.replaceChildren();summary.textContent = '';more.textContent = '';controls();
    };
    const cardFor = item => {
      let card = cards.get(item.followthrough_ref);
      if (!card) {
        const host = node('div');rows.append(host);
        const view = mount(host,item,{...options,isCurrent:life.current,onChange:next => {
          mutationVersion++;summary.textContent = '';options.onChange?.(next);
        }});
        card = {host,view};cards.set(item.followthrough_ref,card);
      } else card.view.update(item);
      return card;
    };
    const arrange = () => {
      let position = rows.firstElementChild;
      for (const ref of listed) {
        const card = cards.get(ref);if (!card) continue;
        if (card.host !== position) rows.insertBefore(card.host,position);
        position = card.host.nextElementSibling;
      }
      if (pinnedRef && listed.includes(pinnedRef)) { pinnedRef = null;pinned.replaceChildren(); }
      more.textContent = nextCursor ? 'Showing '+listed.length+' saved follow-ups. Load more to see older follow-ups.' : '';
      controls();
    };
    const showNotice = notice => {
      notice.show = () => {
        if (currentNotice() !== notice || !life.current()) return;
        const card = cards.get(notice.ref);
        if (card) {
          const heading = card.host.querySelector('h4');if (heading) { heading.tabIndex = -1;heading.focus({preventScroll:true}); }
          card.host.firstElementChild?.scrollIntoView({block:'nearest'});
        } else status.textContent = 'This follow-up is unavailable in the current view. Showing saved follow-ups for this Chat.';
        pendingNotice = null;noticeTarget = null;
        const url = new URL(location.href);url.searchParams.delete('gmail_followthrough');history.replaceState(history.state,'',url.pathname+url.search+url.hash);
      };
      if (notice.sentReady) notice.show();
    };
    const exactTarget = async ref => {
      const result = await life.call('followthrough_get',{followthrough_ref:ref});
      const expected = typeof options.mailboxRevision === 'function' ? options.mailboxRevision() : options.mailboxRevision;
      const item = checkedItem(result.item,life.context,expected || mailboxRevision);
      if (item.followthrough_ref !== ref || item.mailbox_revision !== mailboxRevision)
        throw Object.assign(new Error('This follow-up changed. Refresh this private Gmail view.'),{status:409});
      if (cards.get(ref)?.view.isOlder(item)) return;
      const card = cardFor(item);
      if (!listed.includes(ref)) {
        if (pinnedRef && pinnedRef !== ref) { const previous = cards.get(pinnedRef);previous?.view.dispose();previous?.host.remove();cards.delete(pinnedRef); }
        pinnedRef = ref;if (card.host.parentNode !== pinned) pinned.replaceChildren(node('small','Opened from this notification'),card.host);
      }
    };
    const refresh = async (append = false) => {
      if (!life.current()) return false;
      if (busy) { if (!append) refreshQueued = true;return false; }
      if (editing()) { status.textContent = 'Finish editing the follow-up before refreshing this list.';return false; }
      const cursor = append ? nextCursor : null,requestedFilter = filter.value,startedVersion = mutationVersion;
      if (append && (!cursor || loadedFilter !== requestedFilter)) return false;
      busy = true;controls();status.textContent = append ? 'Loading more saved follow-ups…' : 'Loading saved follow-ups…';
      try {
        const result = await life.call('followthrough_list',{filter:requestedFilter,limit:20,...(cursor ? {cursor} : {})});
        const expected = typeof options.mailboxRevision === 'function' ? options.mailboxRevision() : options.mailboxRevision;
        if (!revisions.test(result.mailbox_revision) || expected && expected !== result.mailbox_revision
          || append && mailboxRevision !== result.mailbox_revision
          || result.coverage !== 'tracked_sends_in_this_private_chat' || !Array.isArray(result.items) || result.items.length > 20
          || typeof result.has_more !== 'boolean' || result.next_cursor !== null
            && (typeof result.next_cursor !== 'string' || !result.next_cursor || result.next_cursor.length > 2048)
          || result.has_more !== Boolean(result.next_cursor)) throw new Error('These follow-ups could not be verified. Refresh this private Gmail view.');
        if (result.state !== 'ready') { clear();status.textContent = 'Follow-ups are unavailable for this Chat. Check Gmail access before continuing.';return false; }
        const items = result.items.map(item => checkedItem(item,life.context,result.mailbox_revision));
        if (new Set(items.map(item => item.followthrough_ref)).size !== items.length
          || result.next_cursor && (result.next_cursor === cursor || append && usedCursors.has(result.next_cursor)))
          throw new Error('The next follow-up page could not be verified. Refresh the saved list.');
        if (startedVersion !== mutationVersion || items.some(item => cards.get(item.followthrough_ref)?.view.isOlder(item))) {
          summary.textContent = '';status.textContent = 'A newer follow-up update is already shown. Refresh to update the list.';return false;
        }
        if (editing()) { status.textContent = 'Finish editing the follow-up before refreshing this list.';return false; }
        mailboxRevision = result.mailbox_revision;nextCursor = result.next_cursor;loadedFilter = requestedFilter;
        if (!append) { listed = [];usedCursors.clear(); }
        if (cursor) usedCursors.add(cursor);
        for (const item of items) { cardFor(item);if (!listed.includes(item.followthrough_ref)) listed.push(item.followthrough_ref); }
        const wanted = new Set(listed);
        for (const [ref,card] of cards) if (!wanted.has(ref) && ref !== pinnedRef) { card.view.dispose();card.host.remove();cards.delete(ref); }
        arrange();
        const counts = result.counts;
        summary.textContent = counts && ['waiting','overdue'].every(key => Number.isSafeInteger(counts[key]) && counts[key] >= 0)
          ? counts.waiting+' waiting · '+counts.overdue+' overdue' : '';
        status.textContent = listed.length ? '' : 'No '+(requestedFilter === 'open' ? 'open ' : '')+'follow-ups in this Chat.';
        const notice = currentNotice(),target = notice?.ref || (!append ? pinnedRef : null);
        if (target && !listed.includes(target)) {
          try { await exactTarget(target); }
          catch (error) {
            if (error.name === 'AbortError' || !life.current()) return false;
            if ([401,403,409,419].includes(error.status)) throw error;
            const card = cards.get(target);card?.view.dispose();card?.host.remove();cards.delete(target);
            if (pinnedRef === target) { pinnedRef = null;pinned.replaceChildren(); }
            status.textContent = 'This follow-up is unavailable in the current view. Showing saved follow-ups for this Chat.';
          }
        }
        if (notice && currentNotice() === notice) showNotice(notice);
        return true;
      } catch (error) {
        if (life.current() && error.name !== 'AbortError') {
          if (!append || !([429,500,502,503,504].includes(error.status) || error.name === 'TypeError')) clear();
          status.textContent = error.message;
        }
        return false;
      } finally {
        busy = false;controls();
        if (refreshQueued) { refreshQueued = false;queueMicrotask(() => { if (life.current() && !editing()) void refresh(); }); }
      }
    };
    filter.addEventListener('change',() => {
      if (editing()) { filter.value = loadedFilter || 'open';status.textContent = 'Finish editing the follow-up before changing the filter.';return; }
      void refresh();
    });
    return {refresh,mailboxRevision:() => mailboxRevision,dispose:() => { clear();life.dispose(); }};
  }
  async function openNotice() {
    if (!noticeTarget || noticeStarted || typeof window.VP3AgentActivityHub?.openGmailSent !== 'function') return;
    const context = {owner_key:noticeOwner,agent_id:noticeTarget.agent_id,conversation_id:noticeTarget.conversation_id};
    if (!contextCurrent(context,noticeBinding) || document.hidden) return;
    noticeStarted = true;pendingNotice = {ref:noticeTarget.followthrough_ref,context,epoch,sentReady:false};
    const notice = pendingNotice;
    try {
      await window.VP3AgentActivityHub.openGmailSent(context);
      if (pendingNotice === notice && notice.epoch === epoch && contextCurrent(context,noticeBinding)) { notice.sentReady = true;notice.show?.(); }
    } catch { if (pendingNotice === notice) pendingNotice = null; }
  }
  const clearAll = () => { epoch++;for (const instance of [...instances]) instance.dispose(); };
  const cancelNotice = () => { noticeTarget = null;pendingNotice = null; };
  window.addEventListener('stonefellow:chat-context-changing',event => { if (event.detail?.initialRestore !== true || noticeStarted) cancelNotice();clearAll(); });
  window.addEventListener('vp3:participant-selection-changed',() => { cancelNotice();clearAll(); });
  window.addEventListener('vp3:participant-task-context',event => { actor = Number(event.detail?.participant_id) || 0;cancelNotice();clearAll(); });
  window.addEventListener('pagehide',() => { cancelNotice();clearAll(); });
  for (const name of ['vp3:chat-context-ready','vp3:agent-activity-ready']) window.addEventListener(name,() => void openNotice());
  document.addEventListener('visibilitychange',() => { if (document.hidden) for (const instance of instances) instance.stop(); });
  window.VP3EmailFollowthroughV1 = Object.freeze({policy,settings,mount,mountList});
  queueMicrotask(() => void openNotice());
})();
