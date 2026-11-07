(() => {
  'use strict';
  const cfg = window.VP3_INITIATIVE_WORKBENCH;
  const tools = document.getElementById('chatToolsContent');
  if (!tools || !cfg?.endpoint || !cfg.csrf || !cfg.ownerKey) return;
  const initialChat = window.STONEFELLOW_CHAT || null;
  const account = {
    owner:String(cfg.ownerKey),agent:Number(cfg.agentId || 0),csrf:String(cfg.csrf),endpoint:String(cfg.endpoint),
    hasChat:Boolean(initialChat),chatOwner:Number(initialChat?.userId || 0),
    chatAgent:Number(initialChat?.agentId || 0),binding:String(initialChat?.workingAttentionBinding || '')
  };

  const lanes = {
    product: {label:'Product listing', source:'Product source', empty:'Product draft storage is unavailable.', create:'Create private draft'},
    screen: {label:'Screen image', source:'Saved screen capture', empty:'Save a screen capture from Agent Eyes in this Chat scope.', create:'Create edited image'},
    mission: {label:'Mission decision brief', source:'Completed Cloud Mission', empty:'Complete a Cloud Mission with readable saved web evidence in this Chat.', create:'Create private brief'}
  };
  const make = (tag, text) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const button = (text, action) => {
    const node = make('button', text);
    node.type = 'button';
    node.addEventListener('click', action);
    return node;
  };
  const label = (text, field) => {
    const node = make('label');
    node.append(make('span', text), field);
    return node;
  };
  const dialog = make('dialog');
  dialog.id = 'vp3InitiativeWorkbench';
  dialog.setAttribute('aria-labelledby', 'vp3InitiativeWorkbenchTitle');

  const header = make('header');
  const title = make('h2', 'Initiative workbench');
  title.id = 'vp3InitiativeWorkbenchTitle';
  const close = button('Close', () => dialog.close());
  header.append(title, close);
  const intro = make('p', 'Choose a saved source, review how it will be used, then create a private result.');

  const lane = make('select');
  lane.dataset.workbenchTool = '';
  for (const [key, info] of Object.entries(lanes)) lane.add(new Option(info.label, key));
  const source = make('select');
  source.dataset.workbenchSource = '';
  const sourceLabel = label('Source', source);
  const mode = make('select');
  mode.dataset.workbenchMode = '';
  mode.add(new Option('AI cleanup', 'cleanup'));
  mode.add(new Option('Creative redraw', 'redraw'));
  const instructions = make('textarea');
  instructions.dataset.workbenchInstruction = '';
  instructions.maxLength = 600;
  instructions.rows = 2;
  const screenOptions = make('div');
  screenOptions.className = 'vp3-workbench-options';
  screenOptions.append(label('Image treatment', mode), label('Directions (optional)', instructions));

  const productDescription = make('textarea');
  productDescription.maxLength = 600;
  productDescription.rows = 3;
  productDescription.dataset.workbenchProductDescription = '';
  const productDescriptionLabel = label('Describe the product', productDescription);
  productDescriptionLabel.hidden = true;

  const actions = make('div');
  actions.className = 'vp3-workbench-actions';
  const reload = button('Reload sources', () => { requestedSourceRevision = ''; void loadSources(source.value); });
  const review = button('Review source', () => void previewSource());
  const create = button('Create private draft', () => void createResult());
  actions.append(reload, review, create);
  const status = make('p');
  status.setAttribute('role', 'status');
  const reviewPanel = make('div');
  reviewPanel.dataset.workbenchReview = '';
  const result = make('div');
  result.dataset.workbenchResult = '';
  result.setAttribute('aria-live', 'polite');
  dialog.append(header, intro, label('Work to create', lane), sourceLabel, productDescriptionLabel, screenOptions, status, reviewPanel, actions, result);
  document.body.append(dialog);

  const launch = button('Initiative workbench', () => open());
  launch.className = 'vp3-workbench-launch';
  tools.prepend(launch);
  let scope = null;
  let pending = null;
  let reviewed = null;
  let returnFocus = null;
  let requestedSourceRevision = '';
  let scopeTimer = 0;

  function configurationMatches() {
    const current = window.VP3_INITIATIVE_WORKBENCH;
    if (!current || String(current.ownerKey) !== account.owner || Number(current.agentId || 0) !== account.agent
      || String(current.csrf) !== account.csrf || String(current.endpoint) !== account.endpoint) return false;
    const chat = window.STONEFELLOW_CHAT;
    if (!account.hasChat) return !chat;
    return Boolean(chat) && account.binding !== '' && account.chatOwner === Number(account.owner)
      && account.chatAgent === account.agent && Number(chat.userId) === account.chatOwner
      && Number(chat.agentId || 0) === account.chatAgent
      && String(chat.workingAttentionBinding || '') === account.binding;
  }

  function currentScope() {
    if (!configurationMatches()) return null;
    const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    const cid = Number(chat?.conversationId?.() || 0);
    const aid = Number(chat?.agentId?.() ?? cfg.agentId ?? 0);
    if (chat?.isRestoring?.() || chat?.isReady?.() === false || !Number.isSafeInteger(cid) || cid < 1
        || !Number.isSafeInteger(aid) || aid !== Number(cfg.agentId ?? 0)) return null;
    return {conversation_id:cid, agent_id:aid};
  }

  function sameScope() {
    const now = currentScope();
    return scope && now?.conversation_id === scope.conversation_id && now.agent_id === scope.agent_id;
  }

  function clearReview() {
    reviewed = null;
    reviewPanel.replaceChildren();
    result.replaceChildren();
  }

  function stopPending() {
    pending?.controller.abort();
    pending = null;
  }

  function controls() {
    const active = dialog.open && sameScope();
    lane.disabled = !active || pending?.action === 'create';
    source.disabled = !active || !!pending || !source.options.length;
    mode.disabled = instructions.disabled = !active || pending?.action === 'create';
    reload.disabled = !active || !!pending;
    const fromDescription = lane.value === 'product' && source.value === 'description';
    productDescriptionLabel.hidden = !fromDescription;
    productDescription.disabled = !active || !!pending;
    review.disabled = !active || !!pending || !source.value || (fromDescription && !productDescription.value.trim());
    create.hidden = !reviewed;
    create.disabled = !active || !!pending || !reviewed;
    create.textContent = lanes[lane.value].create;
    screenOptions.hidden = lane.value !== 'screen';
    sourceLabel.firstChild.textContent = lanes[lane.value].source;
  }

  function open(tool = 'product', sourceId = '', sourceRevision = '') {
    if (!configurationMatches()) { scopeChanged(); return; }
    if (!Object.hasOwn(lanes, tool)) return;
    if (sourceRevision && (typeof sourceRevision !== 'string' || !/^[a-f0-9]{64}$/.test(sourceRevision))) return;
    requestedSourceRevision = sourceRevision;
    stopPending();
    clearReview();
    scope = currentScope();
    lane.value = tool;
    source.replaceChildren();
    mode.value = 'cleanup';
    instructions.value = '';
    productDescription.value = '';
    returnFocus = document.activeElement;
    window.dispatchEvent(new Event('vp3:initiative-workbench-open'));
    if (!dialog.open) dialog.showModal();
    window.clearInterval(scopeTimer);
    scopeTimer = window.setInterval(() => {
      if (!configurationMatches() || (scope && !sameScope())) scopeChanged();
    },200);
    if (scope) void loadSources(String(sourceId));
    else status.textContent = 'Open an existing Agent Chat, or send a message to start one.';
    controls();
  }

  function scopeChanged() {
    window.clearInterval(scopeTimer);
    scopeTimer = 0;
    requestedSourceRevision = '';
    stopPending();
    clearReview();
    scope = null;
    source.replaceChildren();
    if (dialog.open) dialog.close();
    controls();
  }

  function validateAttempt(attempt) {
    return dialog.open && pending === attempt && sameScope() && lane.value === attempt.tool;
  }

  async function request(action, fields, onSuccess) {
    if (!configurationMatches()) { scopeChanged(); return; }
    if (!sameScope() || !dialog.open) return;
    stopPending();
    const attempt = {action, tool:lane.value, scope, controller:new AbortController()};
    pending = attempt;
    controls();
    try {
      const response = await fetch(cfg.endpoint, {
        method:'POST', credentials:'same-origin', cache:'no-store', signal:attempt.controller.signal,
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action, tool:attempt.tool, owner_key:String(cfg.ownerKey), csrf_token:cfg.csrf, ...scope, ...fields})
      });
      const data = await response.json();
      if (!configurationMatches()) { scopeChanged(); return; }
      if (!validateAttempt(attempt)) return;
      if (!response.ok || !data.ok) {
        const error = new Error(data.error || 'Initiative work is unavailable.');
        error.status = response.status;
        throw error;
      }
      if (data.owner_key !== String(cfg.ownerKey) || data.conversation_id !== attempt.scope.conversation_id
          || data.agent_id !== attempt.scope.agent_id || data.tool !== attempt.tool) {
        clearReview();
        source.replaceChildren();
        scope = null;
        status.textContent = 'The account or Chat changed. Refresh this page.';
        return;
      }
      onSuccess(data);
    } catch (error) {
      if (!validateAttempt(attempt)) return;
      if ([403, 404, 409, 419, 422].includes(error.status)) clearReview();
      status.textContent = error instanceof SyntaxError || !error.status
        ? 'Connection interrupted. Check the source and retry this same request.'
        : error.message;
    } finally {
      if (pending === attempt) {
        pending = null;
        if (scope && !sameScope()) scopeChanged();
        else controls();
      }
    }
  }

  function dateLabel(value) {
    const date = new Date(value);
    return value && Number.isFinite(date.getTime()) ? date.toLocaleString() : '';
  }

  async function loadSources(preselect = '') {
    clearReview();
    source.replaceChildren();
    status.textContent = 'Loading saved sources…';
    await request('sources', {}, data => {
      source.add(new Option('Choose a saved source', ''));
      for (const item of data.sources || []) {
        const captured = dateLabel(item.captured_at);
        source.add(new Option(item.source_title + (captured ? ' · ' + captured : ''), item.source_id));
      }
      if (preselect && ![...source.options].some(option => option.value === preselect)) {
        source.add(new Option('Selected source', preselect));
      }
      source.value = preselect;
      status.textContent = source.options.length > 1 ? '' : lanes[lane.value].empty;
    });
  }

  function proposal() {
    return {
      source_id: source.value,
      ...(lane.value === 'product' && source.value === 'description' ? {product_description:productDescription.value.trim()} : {}),
      ...(lane.value === 'screen' ? {mode:mode.value, instruction:instructions.value.trim()} : {})
    };
  }

  function safeUrl(value, localPath = '') {
    if (typeof value !== 'string' || !value) return '';
    try {
      const url = new URL(value, location.href);
      if (url.username || url.password) return '';
      if (localPath) return url.origin === location.origin && url.pathname.endsWith(localPath) ? url.href : '';
      return url.protocol === 'https:' ? url.href : '';
    } catch {
      return '';
    }
  }

  async function previewSource() {
    const fields = proposal();
    clearReview();
    status.textContent = 'Checking the source…';
    await request('preview', fields, data => {
      if (data.preview?.source_id !== fields.source_id || typeof data.review_token !== 'string') {
        status.textContent = 'The selected source could not be verified.';
        return;
      }
      const referenceRevision = lane.value === 'screen' && typeof data.preview.reference_revision === 'string'
        ? data.preview.reference_revision : data.preview.revision;
      if (requestedSourceRevision && referenceRevision !== requestedSourceRevision) {
        status.textContent = 'This source changed after the Agent prepared its suggestion. Reload the source to review its current version.';
        return;
      }
      reviewed = {fields, token:data.review_token};
      const preview = data.preview;
      const details = preview.details || {};
      const captured = dateLabel(details.captured_at);
      const sourceTitle = preview.source_title + (captured ? ' · ' + captured : '');
      if (fields.source_id !== 'description') source.selectedOptions[0].text = sourceTitle;
      reviewPanel.append(make('h3', sourceTitle));
      let imageUrl = safeUrl(details.image_url, '/media-library-file-v86.php');
      if (lane.value === 'screen' && typeof details.image_data === 'string' && details.image_data.length <= 420000
          && /^data:image\/jpeg;base64,[A-Za-z0-9+/]+={0,2}$/.test(details.image_data)) imageUrl = details.image_data;
      if (imageUrl) {
        const image = make('img');
        image.src = imageUrl;
        image.alt = preview.source_title;
        reviewPanel.append(image);
      }
      for (const key of ['effect', 'photo_use', 'owner_review', 'summary']) {
        if (typeof details[key] === 'string') reviewPanel.append(make('p', details[key]));
      }
      if (lane.value === 'mission' && details.coverage) {
        reviewPanel.append(make('p', `Use ${details.coverage.read_sources} readable excerpts from ${details.coverage.captured_sources} collected sources. The brief is saved privately with this Mission.`));
      }
      if (lane.value === 'screen') {
        reviewPanel.append(make('p', 'Creating this edit sends the selected saved frame to OpenAI. AI edits can change details. Your saved capture stays in Agent Eyes.'));
      }
      status.textContent = 'Source ready for your review.';
    });
  }

  function showProduct(output, card) {
    card.append(make('h3', output.title), make('p', output.description));
    if (output.missing_fields?.length) card.append(make('p', 'Still to decide · ' + output.missing_fields.map(field => field.replaceAll('_', ' ')).join(', ')));
    const reviewUrl = safeUrl(output.review_url, '/profile-commerce-products.php');
    if (reviewUrl) {
      const link = make('a', 'Open private Commerce draft');
      link.href = reviewUrl;
      card.append(link);
    }
  }

  function showMission(output, card) {
    card.append(make('h3', output.title));
    const sources = new Map((output.sources || []).map(item => [item.id, item]));
    const findings = make('ul');
    for (const finding of output.findings || []) {
      const row = make('li');
      row.append(make('strong', finding.text));
      if (finding.quote && finding.quote !== finding.text) row.append(make('blockquote', finding.quote));
      const source = sources.get(finding.source_id);
      const url = safeUrl(source?.url);
      if (url) {
        const link = make('a', source.title || 'Read source');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        row.append(link);
      }
      findings.append(row);
    }
    if (findings.childElementCount) card.append(make('h4', 'Source findings'), findings);
    for (const [key, heading] of [['recommendation','Recommendation'], ['uncertainty','What’s uncertain'], ['next_step','Proposed next step']]) {
      if (output[key]) card.append(make('h4', heading), make('p', output[key]));
    }
    if (output.coverage) card.append(make('small', `${output.coverage.read_sources} readable excerpts from ${output.coverage.captured_sources} collected sources`));
  }

  function showResult(output) {
    result.replaceChildren();
    const card = make('article');
    result.append(card);
    if (output.kind === 'product_listing_draft') {
      showProduct(output, card);
      status.textContent = 'Private Commerce draft ready.';
    } else if (output.type === 'mission_decision_brief') {
      showMission(output, card);
      status.textContent = 'Private Mission brief ready.';
    } else if (output.canvas_media && window.VP3CanvasMedia?.mount) {
      card.className = 'vp3-canvas-media';
      window.VP3CanvasMedia.mount(card, output.canvas_media);
      status.textContent = 'Screen image job opened.';
    } else {
      status.textContent = 'The result could not be displayed. Reopen the source to check its status.';
    }
  }

  async function createResult() {
    if (!reviewed) return;
    status.textContent = 'Creating your private result…';
    await request('create', {...reviewed.fields, review_token:reviewed.token, confirmed:true}, data => {
      if (data.output) showResult(data.output);
      reviewed = null;
    });
  }

  lane.addEventListener('change', () => {
    requestedSourceRevision = '';
    stopPending();
    mode.value = 'cleanup';
    instructions.value = '';
    productDescription.value = '';
    void loadSources();
  });
  function editProposal() {
    stopPending();
    clearReview();
    status.textContent = '';
    controls();
  }
  source.addEventListener('change', () => { requestedSourceRevision = ''; editProposal(); });
  mode.addEventListener('change', editProposal);
  instructions.addEventListener('input', editProposal);
  productDescription.addEventListener('input', editProposal);
  dialog.addEventListener('close', () => {
    if (dialog.open) return;
    window.clearInterval(scopeTimer);
    scopeTimer = 0;
    scope = null;
    stopPending();
    clearReview();
    source.replaceChildren();
    const opener = returnFocus;
    returnFocus = null;
    if (opener?.isConnected && opener.getClientRects().length) opener.focus();
    else document.getElementById('chatToolsButton')?.focus();
  });
  window.addEventListener('stonefellow:chat-context-changing', scopeChanged);
  window.addEventListener('vp3:chat-conversation-changed', scopeChanged);
  window.addEventListener('pagehide', scopeChanged);
  window.VP3InitiativeWorkbench = Object.freeze({open});
})();
