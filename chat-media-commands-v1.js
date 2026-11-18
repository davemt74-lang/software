(() => {
  'use strict';
  if (window.VP3MediaCommands?.version === 1) return;
  const cfg = window.VP3_INITIATIVE_WORKBENCH;
  const chat = window.STONEFELLOW_CHAT;
  if (!cfg?.endpoint || !cfg.mediaEndpoint || !cfg.csrf || !cfg.ownerKey || !chat) return;
  const account = Object.freeze({owner: String(cfg.ownerKey), agent: Number(cfg.agentId), csrf: String(cfg.csrf),
    endpoint: String(cfg.endpoint), mediaEndpoint: String(cfg.mediaEndpoint), followthroughEndpoint:String(cfg.followthroughEndpoint||''), binding: String(chat.workingAttentionBinding || '')});
  const formats = ['image/jpeg', 'image/png', 'image/webp'];
  const ordinaryLimit = 4 * 1024 * 1024;
  const generatedLimit = 25 * 1024 * 1024;
  const stageActions = {
    listing_review: 'Review listing', listing_unavailable: 'Review listing', photo_needed: 'Choose an image',
    marketing_review: 'Continue promotion', design_review: 'Continue design', image_starting: 'View image progress',
    image_pending: 'View image progress', creative_ready: 'Review graphic', creative_failed: 'Review image issue',
    publication_review: 'Review promotion', published: 'View active promotion', paused: 'Review paused promotion',
    promotion_outcomes: 'View promotion', needs_attention: 'Recheck plan',
  };
  let active = null, sequence = 0;

  function positive(value) {
    if (typeof value === 'string' && !/^[1-9]\d*$/.test(value)) return 0;
    if (typeof value !== 'number' && typeof value !== 'string') return 0;
    const number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : 0;
  }
  function agentId(value) { return Number.isSafeInteger(value) && value >= 0 ? value : -1; }
  function node(tag, text, className) {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
  }
  function button(text, selector) {
    const element = node('button', text, 'vp3-media-command-button');
    element.type = 'button';
    element.setAttribute('data-media-command-' + selector, '');
    return element;
  }
  function safeEndpoint(value, suffix) {
    try {
      const url = new URL(value, location.href);
      return url.origin === location.origin && /^https?:$/.test(url.protocol) && !url.username && !url.password
        && !url.hash && url.pathname.endsWith(suffix) ? url : null;
    } catch { return null; }
  }
  const endpoint = safeEndpoint(account.endpoint, '/api/initiative-workbench-v1.php');
  const mediaEndpoint = safeEndpoint(account.mediaEndpoint, '/api/media-library-v86.php');
  if (!endpoint || !mediaEndpoint) return;
  const chatPath = new URL('../chat.php', endpoint).pathname;
  const mediaPath = new URL('../media-library-file-v86.php', mediaEndpoint).pathname;

  function runtimeContext() {
    try {
      const runtime = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
      return runtime?.mediaCommandContext?.() || null;
    } catch { return null; }
  }
  function configurationCurrent() {
    const current = window.VP3_INITIATIVE_WORKBENCH;
    const live = window.STONEFELLOW_CHAT;
    return Boolean(current && live && account.binding && String(current.ownerKey) === account.owner
      && Number(current.agentId) === account.agent && String(current.csrf) === account.csrf
      && String(current.endpoint) === account.endpoint && String(current.mediaEndpoint) === account.mediaEndpoint
      && String(current.followthroughEndpoint||'')===account.followthroughEndpoint
      && String(live.userId) === account.owner && Number(live.agentId) === account.agent
      && String(live.workingAttentionBinding || '') === account.binding);
  }
  function scopeCurrent(bound) {
    if (!configurationCurrent() || !bound || String(bound.ownerKey) !== account.owner || bound.agentId !== account.agent
      || !positive(bound.conversationId) || typeof bound.isCurrent !== 'function') return false;
    try {
      const current = runtimeContext();
      return bound.isCurrent() === true && Boolean(current && String(current.ownerKey) === account.owner
        && current.agentId === bound.agentId && current.conversationId === bound.conversationId && current.isCurrent?.() === true);
    } catch { return false; }
  }
  function current(session) {
    return active === session && !session.closed && session.dialog.isConnected && session.dialog.open && scopeCurrent(session.bound);
  }
  function ensureCurrent(session) {
    if (current(session)) return true;
    close(session, 'stale');
    return false;
  }
  function message(error, fallback) {
    return typeof error?.message === 'string' && error.message.trim() ? error.message.trim().slice(0, 500) : fallback;
  }
  function clearError(session) {
    session.error.hidden = true;
    session.errorText.textContent = '';
    session.retryAction = null;
  }
  function showError(session, text, retryAction) {
    session.errorText.textContent = text;
    session.retryAction = retryAction || null;
    session.retry.hidden = !retryAction;
    session.error.hidden = false;
  }
  function syncControls(session) {
    const busy = Boolean(session.pending);
    session.dialog.dataset.mediaCommandBusy = String(busy);
    session.more.disabled = busy;
    session.retry.disabled = busy;
    session.list.setAttribute('aria-busy', String(busy));
    for (const record of session.plans.values()) {
      if (!record.control?.hasAttribute('data-media-plan-open')) continue;
      const checking = session.pending?.action === 'marketing_plan' && session.pending.productId === record.plan.product_id;
      record.control.disabled = checking;
      record.control.textContent = checking ? 'Checking plan…' : stageActions[record.plan.stage];
    }
  }

  function close(session, reason = 'cancelled') {
    if (!session || session.closed) return;
    const validScope = scopeCurrent(session.bound);
    session.closed = true;
    session.serial++;
    clearInterval(session.timer);
    session.pending?.controller.abort();
    session.pending = null;
    session.picker?.destroy();
    session.picker = null;
    session.followthrough?.dispose();session.followthrough=null;
    window.VP3MarketingFollowthroughV1?.cancelPendingDestination?.();
    if (active === session) active = null;
    // Removing our modal avoids the browser restoring an obsolete Chat opener on
    // a stale-context close. Valid cancellation restores focus explicitly below.
    session.dialog.remove();
    session.plans.clear();
    if (!validScope || !['cancelled', 'selected'].includes(reason)) return;
    const target = reason === 'selected' ? document.getElementById('chatInput') : session.returnFocus;
    const focused = document.activeElement;
    if (focused && focused !== document.body && focused !== target && focused.isConnected) return;
    if (scopeCurrent(session.bound) && target?.isConnected && !target.disabled && target.getClientRects().length) {
      target.focus({preventScroll: true});
    }
  }

  function createSession(kind, bound) {
    if (!scopeCurrent(bound)) return null;
    if (active) close(active, 'stale');
    const dialog = node('dialog', undefined, 'vp3-media-command');
    dialog.id = 'vp3MediaCommands';
    dialog.dataset.mediaCommand = kind;
    dialog.setAttribute('aria-labelledby', 'vp3MediaCommandsTitle');
    dialog.setAttribute('aria-describedby', 'vp3MediaCommandsIntro');
    const header = node('header', undefined, 'vp3-media-command-header');
    const heading = node('h2', kind === 'images' ? 'Your images' : 'Marketing plans');
    heading.id = 'vp3MediaCommandsTitle';
    const dismiss = button('Close', 'close');
    header.append(heading, dismiss);
    const intro = node('p', kind === 'images'
      ? 'Choose an image to edit in Chat or use for product marketing.'
      : 'Continue a draft, review an image in progress, or return to an active or paused product promotion.', 'vp3-media-command-intro');
    intro.id = 'vp3MediaCommandsIntro';
    const status = node('p', '', 'vp3-media-command-status');
    status.setAttribute('data-media-command-status', '');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-atomic', 'true');
    const error = node('div', undefined, 'vp3-media-command-error');
    error.setAttribute('data-media-command-error', '');
    error.setAttribute('role', 'alert');
    error.hidden = true;
    const errorText = node('p');
    const retry = button('Try again', 'retry');
    error.append(errorText, retry);
    const content = node('div', undefined, 'vp3-media-command-content');
    content.setAttribute('data-media-command-content', '');
    const list = node('ul', undefined, 'vp3-media-command-plans');
    list.setAttribute('data-media-command-plans', '');
    list.setAttribute('aria-label', 'Product marketing plans');
    const empty = node('div', undefined, 'vp3-media-command-empty');
    empty.setAttribute('data-media-command-empty', '');
    empty.hidden = true;
    empty.append(node('h3', 'No marketing plans yet'), node('p', 'Your product marketing drafts and active promotions will appear here.'));
    const more = button('Load more plans', 'more');
    more.hidden = true;
    if (kind === 'marketing') content.append(list, empty, more);
    dialog.append(header, intro, status, error, content);
    const session = {id: ++sequence, kind, bound, dialog, heading, intro, status, error, errorText, retry, content, list, empty, more,
      returnFocus: document.activeElement, closed: false, serial: 0, pending: null, retryAction: null,
      timer: 0, picker: null, followthrough:null, followthroughHost:null, plans: new Map(), nextBefore: null, selectedAction: ''};
    dismiss.addEventListener('click', () => close(session));
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(session); });
    dialog.addEventListener('close', () => { if (!session.closed) close(session); });
    retry.addEventListener('click', () => { if (ensureCurrent(session)) session.retryAction?.(); });
    more.addEventListener('click', () => { if (session.nextBefore && !session.pending) void loadPlans(session, session.nextBefore); });
    document.body.append(dialog);
    if (typeof dialog.showModal !== 'function') { dialog.remove(); return null; }
    active = session;
    try { dialog.showModal(); } catch { close(session, 'destroyed'); return null; }
    session.timer = window.setInterval(() => ensureCurrent(session), 200);
    return session;
  }

  function openImages(session) {
    const host = node('div');
    host.setAttribute('data-media-command-picker', '');
    session.content.append(host);
    session.picker = window.VP3MediaLibraryPicker.open({host, endpoint: account.mediaEndpoint, ownerKey: account.owner,
      title: 'Choose an image', isCurrent: () => current(session), maxBytes: generatedLimit, acceptedMimes: formats,
      actions: [
        {id: 'edit', label: 'Edit in Chat', maxBytes: generatedLimit, acceptedMimes: formats, eligibility: asset => {
          if (!window.VP3ImageComposer?.attachAsset) return 'Image editing is not available in this Chat.';
          if (asset.file_size > ordinaryLimit && !(asset.source === 'openai_image' && asset.mime_type === 'image/png')) {
            return 'Chat accepts images up to 4 MiB, or generated PNG images up to 25 MiB.';
          }
          return undefined;
        }},
        {id: 'marketing', label: 'Use in Marketing', maxBytes: ordinaryLimit, acceptedMimes: formats,
          eligibility: () => window.VP3InitiativeWorkbench?.openMarketingImage ? undefined : 'Marketing is not available in this Chat.'},
      ],
      onSelect: async (asset, selection) => {
        if (!current(session) || !selection.isCurrent()) return false;
        const bound = {...session.bound, isCurrent: () => current(session) && selection.isCurrent(), signal: selection.signal};
        if (selection.action === 'edit') {
          const attached = await window.VP3ImageComposer.attachAsset(asset, bound);
          if (!current(session) || !selection.isCurrent()) return false;
          if (attached === true) session.selectedAction = 'edit';
          return attached === true;
        }
        if (selection.action === 'marketing') {
          const opened = window.VP3InitiativeWorkbench.openMarketingImage(asset, bound);
          if (opened !== true) throw new Error('Marketing could not open this image. Try again or choose another image.');
          session.selectedAction = 'marketing';
          return true;
        }
        return false;
      },
      onClose: reason => {
        session.picker = null;
        if (session.closed) return;
        close(session, reason === 'selected' ? session.selectedAction === 'edit' ? 'selected' : 'handoff' : reason);
      },
    });
  }

  function imageUrl(image) {
    const id = positive(image?.id);
    if (!id || typeof image.url !== 'string') return '';
    const url = safeEndpoint(image.url, '/media-library-file-v86.php');
    return url && url.pathname === mediaPath && url.searchParams.getAll('id').length === 1
      && url.searchParams.get('id') === String(id) && [...url.searchParams.keys()].every(key => key === 'id') ? url.href : '';
  }
  function planUrl(plan) {
    if (typeof plan.chat_url !== 'string') return '';
    const url = safeEndpoint(plan.chat_url, '/chat.php');
    const expected = {agent: plan.agent_id === 0 ? 'system' : String(plan.agent_id),
      conversation_id: String(plan.conversation_id), marketing_plan: String(plan.product_id)};
    return url && url.pathname === chatPath && Object.entries(expected).every(([key, value]) =>
      url.searchParams.getAll(key).length === 1 && url.searchParams.get(key) === value)
      && [...url.searchParams.keys()].every(key => Object.hasOwn(expected, key)) ? url.href : '';
  }
  function validatePlan(raw) {
    if (!raw || !positive(raw.product_id) || typeof raw.product_id !== 'number' || agentId(raw.agent_id) < 0
      || !positive(raw.conversation_id) || typeof raw.conversation_id !== 'number'
      || !Object.hasOwn(stageActions, raw.stage) || typeof raw.product_title !== 'string'
      || typeof raw.stage_label !== 'string' || !raw.stage_label.trim()) throw new Error('A marketing plan could not be verified. Please reload the plans.');
    return {...raw, product_title: raw.product_title.trim().slice(0, 240) || 'Untitled product', stage_label: raw.stage_label.trim().slice(0, 80)};
  }
  function validResume(plan) {
    const marker = plan.resume;
    return marker && String(marker.owner_user_id) === account.owner && marker.product_id === plan.product_id
      && marker.agent_id === plan.agent_id && marker.conversation_id === plan.conversation_id && marker.stage === plan.stage
      && (marker.source_id === undefined || typeof marker.source_id === 'string' && positive(marker.source_id))
      && (marker.draft_id === undefined || typeof marker.draft_id === 'string' && /^[a-f0-9]{64}$/.test(marker.draft_id))
      && (marker.creative_id === undefined || typeof marker.creative_id === 'string' && /^[a-f0-9]{32}$/.test(marker.creative_id));
  }

  function renderPlan(session, plan) {
    const item = node('li', undefined, 'vp3-media-command-plan');
    item.dataset.mediaPlan = String(plan.product_id);
    const picture = node('div', undefined, 'vp3-media-command-thumbnail');
    const url = imageUrl(plan.image);
    const fallback = node('span', 'No image yet');
    if (url) {
      const image = node('img');
      image.alt = typeof plan.image.title === 'string' ? plan.image.title.slice(0, 240) : plan.product_title;
      image.loading = 'lazy'; image.decoding = 'async';
      fallback.hidden = true;
      image.addEventListener('error', () => {
        if (!ensureCurrent(session) || !item.isConnected) return;
        image.hidden = true; fallback.textContent = 'Preview unavailable'; fallback.hidden = false;
      }, {once: true});
      image.src = url;
      picture.append(image);
    }
    picture.append(fallback);
    const detail = node('div', undefined, 'vp3-media-command-plan-detail');
    const heading = node('h3', plan.product_title);
    const stage = node('span', plan.stage_label, 'vp3-media-command-stage');
    stage.dataset.mediaPlanStage = plan.stage;
    const sameChat = plan.agent_id === session.bound.agentId && plan.conversation_id === session.bound.conversationId;
    const context = node('p', sameChat ? 'In this Chat' : 'Continues in its original Chat', 'vp3-media-command-plan-context');
    let control;
    if (sameChat) {
      control = button(stageActions[plan.stage], 'resume');
      control.dataset.mediaPlanOpen = String(plan.product_id);
      control.addEventListener('click', () => void loadPlan(session, plan.product_id));
    } else {
      const href = planUrl(plan);
      if (href) {
        control = node('a', 'Open original Chat', 'vp3-media-command-button');
        control.dataset.mediaPlanLink = String(plan.product_id);
        control.href = href;
        control.addEventListener('click', event => { if (!ensureCurrent(session)) event.preventDefault(); });
      } else {
        control = button('Plan link unavailable', 'unavailable');
        control.disabled = true;
        context.textContent = 'The link to this plan could not be verified.';
      }
    }
    detail.append(heading, stage, context, control);
    if(sameChat&&account.followthroughEndpoint&&window.VP3MarketingFollowthroughV1){
      const followthrough=button('View follow-through','followthrough');
      followthrough.dataset.mediaPlanFollowthrough=String(plan.product_id);
      followthrough.addEventListener('click',()=>showFollowthrough(session,{productId:plan.product_id}));
      detail.append(followthrough);
    }
    item.append(picture, detail);
    const previous = session.plans.get(plan.product_id);
    if (previous) previous.item.replaceWith(item);
    else session.list.append(item);
    session.plans.set(plan.product_id, {plan, item, control});
  }

  async function request(session, action, fields, onResult, retryAction, statusText) {
    if (!ensureCurrent(session)) return;
    session.pending?.controller.abort();
    const operation = {controller: new AbortController(), serial: ++session.serial, action, productId: fields.product_id || 0};
    session.pending = operation;
    const stillCurrent = () => ensureCurrent(session) && session.pending === operation && session.serial === operation.serial
      && !operation.controller.signal.aborted;
    clearError(session);
    session.status.textContent = statusText;
    syncControls(session);
    try {
      const response = await fetch(endpoint.href, {method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, signal: operation.controller.signal,
        body: JSON.stringify({action, tool: 'marketing', owner_key: account.owner, csrf_token: account.csrf,
          agent_id: session.bound.agentId, conversation_id: session.bound.conversationId, ...fields})});
      if (!stillCurrent()) return;
      if ([401, 403, 419].includes(response.status)) { close(session, 'stale'); return; }
      if (response.status === 404) {
        // A 404 can mean current private-scope access was revoked. Retire every
        // cached card before showing the generic unavailable/retry message.
        session.plans.clear();
        session.list.replaceChildren();
        session.nextBefore = null;
        session.more.hidden = true;
        session.empty.hidden = true;
      }
      const data = await response.json().catch(() => null);
      if (!stillCurrent()) return;
      if (response.status === 409 && typeof data?.error === 'string'
        && /account (?:has )?changed|session (?:has )?expired|scope (?:has )?changed/i.test(data.error)) {
        close(session, 'stale'); return;
      }
      if (!response.ok || data?.ok !== true) throw new Error(typeof data?.error === 'string' && data.error.trim()
        ? data.error.slice(0, 500) : 'Marketing plans could not be loaded. Please try again.');
      if (data.owner_key !== account.owner || data.agent_id !== session.bound.agentId
        || data.conversation_id !== session.bound.conversationId || data.tool !== 'marketing') {
        close(session, 'stale'); return;
      }
      if (stillCurrent()) onResult(data);
    } catch (error) {
      if (!stillCurrent()) return;
      showError(session, error instanceof TypeError ? 'Could not connect to Marketing plans. Check your connection and try again.'
        : message(error, 'This plan could not be opened. Please try again.'), retryAction);
      session.status.textContent = 'Your Chat and saved plans are unchanged.';
    } finally {
      if (session.pending === operation && !session.closed) { session.pending = null; syncControls(session); }
    }
  }

  function loadPlans(session, before = null) {
    return request(session, 'marketing_plans', before ? {before} : {}, data => {
      if (!Array.isArray(data.plans) || data.plans.length > 12) throw new Error('Marketing returned an unexpected plan list. Please try again.');
      const cursor = data.next_before == null ? null : positive(data.next_before);
      if (data.next_before != null && (!cursor || before && cursor >= before)) throw new Error('The next page could not be verified. Please try again.');
      const plans = data.plans.map(validatePlan);
      if (!before) { session.plans.clear(); session.list.replaceChildren(); }
      for (const plan of plans) renderPlan(session, plan);
      session.nextBefore = plans.length ? cursor : null;
      session.more.hidden = !session.nextBefore;
      session.empty.hidden = session.plans.size > 0;
      const count = session.plans.size;
      session.status.textContent = count ? 'Showing ' + count + (count === 1 ? ' marketing plan.' : ' marketing plans.') : 'No marketing plans found.';
      if (before && document.activeElement === session.more && !session.nextBefore) {
        [...session.plans.values()].at(-1)?.control.focus();
      }
    }, () => void loadPlans(session, before), before ? 'Loading more marketing plans…' : 'Loading marketing plans…');
  }

  function loadPlan(session, productId) {
    if (!positive(productId)) return Promise.resolve();
    return request(session, 'marketing_plan', {product_id: productId}, data => {
      const plan = validatePlan(data.plan);
      if (plan.product_id !== productId || plan.agent_id !== session.bound.agentId || plan.conversation_id !== session.bound.conversationId) {
        throw new Error('This plan belongs to a different Chat. Reopen Marketing plans to use its verified link.');
      }
      renderPlan(session, plan);
      session.empty.hidden = true;
      if (plan.stage === 'needs_attention' || !plan.resume) {
        session.status.textContent = 'This plan needs attention.';
        showError(session, 'This plan needs attention before it can be continued. Its saved content has been kept.', () => void loadPlan(session, productId));
        return;
      }
      if (!validResume(plan)) throw new Error('This plan could not be verified for reopening. Please try again.');
      if (!ensureCurrent(session)) return;
      if (window.VP3InitiativeWorkbench?.openProductLaunch?.(plan.resume) !== true) {
        throw new Error('This plan could not be opened. Try again when the current Chat is ready.');
      }
      close(session, 'handoff');
    }, () => void loadPlan(session, productId), 'Checking the latest saved plan…');
  }

  function open(kind, bound) {
    if (!['images', 'marketing'].includes(kind) || !scopeCurrent(bound)
      || kind === 'images' && !window.VP3MediaLibraryPicker?.open) return false;
    window.VP3MarketingFollowthroughV1?.cancelPendingDestination?.();
    const session = createSession(kind, bound);
    if (!session) return false;
    if (kind === 'images') {
      try { openImages(session); } catch (error) {
        showError(session, message(error, 'The image picker could not open. Close this window and try again.'));
      }
    } else void loadPlans(session);
    return !session.closed;
  }

  function backToPlans(session){
    if(!ensureCurrent(session))return;
    session.followthrough?.dispose();session.followthrough=null;session.followthroughHost?.remove();session.followthroughHost=null;
    session.heading.textContent='Marketing plans';
    session.heading.tabIndex=-1;session.heading.focus({preventScroll:true});
    session.intro.textContent='Continue a draft, review an image in progress, or return to an active or paused product promotion.';
    session.list.hidden=false;session.list.replaceChildren();session.plans.clear();session.nextBefore=null;session.more.hidden=true;session.empty.hidden=true;
    clearError(session);void loadPlans(session);
  }

  function showFollowthrough(session,selection){
    if(!ensureCurrent(session)||session.kind!=='marketing'||!account.followthroughEndpoint||!window.VP3MarketingFollowthroughV1?.mount)return false;
    window.VP3MarketingFollowthroughV1.cancelPendingDestination();
    session.pending?.controller.abort();session.pending=null;session.serial++;session.followthrough?.dispose();session.followthroughHost?.remove();
    clearError(session);session.status.textContent='';session.list.hidden=true;session.empty.hidden=true;session.more.hidden=true;
    session.heading.textContent='Marketing follow-through';session.intro.textContent='Inspect a recorded publication, then choose the next step for its original creative or current plan.';
    session.heading.tabIndex=-1;session.heading.focus({preventScroll:true});
    const host=node('div');session.followthroughHost=host;session.content.append(host);
    session.followthrough=window.VP3MarketingFollowthroughV1.mount(host,{selection,
      context:()=>current(session)?{ownerUserId:Number(account.owner),chatAgentId:session.bound.agentId,chatConversationId:session.bound.conversationId,workingAttentionBinding:account.binding}:null,
      onBack:()=>backToPlans(session),onAuthorityChanged:()=>{session.plans.clear();session.list.replaceChildren();session.nextBefore=null;},
      onCreative:marker=>{if(!ensureCurrent(session)||window.VP3InitiativeWorkbench?.openProductLaunch?.(marker)!==true)return false;close(session,'handoff');return true;},
      onCurrentPlan:productId=>{if(ensureCurrent(session))void loadPlan(session,productId);},
      onConversation:selection=>{
        if(!ensureCurrent(session)||selection?.tab!=='conversations'||!positive(selection.publicConversationId))return false;
        if(window.VP3AgentActivityHub?.openProfile?.(selection)!==true)return false;
        close(session,'handoff');return true;
      }
    });
    if(!session.followthrough){backToPlans(session);showError(session,'Marketing follow-through is not available in this Chat.');return false;}
    syncControls(session);return true;
  }

  function openFollowthrough(selection,bound){
    if(!scopeCurrent(bound)||!account.followthroughEndpoint||!window.VP3MarketingFollowthroughV1)return false;
    const parsed=selection?.ref?window.VP3MarketingFollowthroughV1.parseRef(selection.ref):null;
    if(selection?.ref&&!parsed||!selection?.ref&&!positive(selection?.productId))return false;
    const session=active&&active.kind==='marketing'&&current(active)?active:createSession('marketing',bound);
    return Boolean(session&&showFollowthrough(session,parsed?{ref:parsed.ref}:{productId:selection.productId}));
  }

  function initialIntent() {
    const runtime = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    // The runtime captures the original navigation before history restoration can
    // replace its URL. A null from this bridge is authoritative.
    if (typeof runtime?.mediaCommandIntent === 'function') {
      try {
        const intent = runtime.mediaCommandIntent();
        return intent && typeof intent.productId === 'number' && positive(intent.productId)
          && typeof intent.conversationId === 'number' && positive(intent.conversationId) && agentId(intent.agentId) >= 0 ? intent : null;
      } catch { return null; }
    }
    const url = new URL(location.href);
    if(url.searchParams.has('marketing_publication')||url.searchParams.has('marketing_period'))return null;
    const productId = url.searchParams.getAll('marketing_plan').length === 1 ? positive(url.searchParams.get('marketing_plan')) : 0;
    const conversationId = url.searchParams.getAll('conversation_id').length === 1 ? positive(url.searchParams.get('conversation_id')) : 0;
    const selector = url.searchParams.getAll('agent').length === 1 ? url.searchParams.get('agent') : null;
    const requestedAgent = selector === 'system' ? 0 : positive(selector) || -1;
    return productId && conversationId && requestedAgent >= 0 ? {productId, conversationId, agentId: requestedAgent} : null;
  }
  const intent = initialIntent();
  const linkedProduct = intent?.productId || 0;
  const linkedConversation = intent?.conversationId || 0;
  const linkedAgent = intent?.agentId ?? -1;
  let intentConsumed = false;
  function resumeLinkedPlan() {
    if (intentConsumed || active || !linkedProduct || !linkedConversation || linkedAgent < 0) return;
    const bound = runtimeContext();
    if (!scopeCurrent(bound) || bound.agentId !== linkedAgent || bound.conversationId !== linkedConversation) return;
    const session = createSession('marketing', bound);
    if (!session) return;
    intentConsumed = true;
    try {
      const url = new URL(location.href);
      if (url.searchParams.getAll('marketing_plan').length === 1 && url.searchParams.get('marketing_plan') === String(linkedProduct)) {
        url.searchParams.delete('marketing_plan');
        history.replaceState(history.state, '', url.pathname + url.search + url.hash);
      }
    } catch { /* The in-memory consumed flag still prevents duplicate handoffs. */ }
    void loadPlan(session, linkedProduct);
  }
  const invalidate = () => { if (active) close(active, 'stale'); };
  for (const event of ['stonefellow:chat-context-changing', 'vp3:chat-conversation-changed', 'vp3:participant-selection-changed', 'vp3:participant-task-context', 'pagehide']) {
    window.addEventListener(event, invalidate);
  }
  window.addEventListener('vp3:chat-context-ready', resumeLinkedPlan);
  window.VP3MediaCommands = Object.freeze({version: 1, open,openFollowthrough});
  window.dispatchEvent(new Event('vp3:media-commands-ready'));
  resumeLinkedPlan();
})();
