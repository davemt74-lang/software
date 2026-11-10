(() => {
  'use strict';
  const cfg = window.VP3_CHAT_EMAIL_V1;
  const chatConfig = window.STONEFELLOW_CHAT;
  if (!cfg?.endpoint || !cfg.csrf || String(chatConfig?.userId) !== cfg.ownerKey) return;

  const element = (tag, text) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const button = (text, handler) => {
    const node = element('button', text);
    node.type = 'button';
    node.addEventListener('click', handler);
    return node;
  };
  const dialog = element('dialog');
  dialog.id = 'vp3ChatEmailReview';
  dialog.setAttribute('aria-labelledby', 'vp3ChatEmailReviewTitle');
  const header = element('header');
  const title = element('h2', 'Review Gmail draft');
  title.id = 'vp3ChatEmailReviewTitle';
  const close = button('Close', () => dialog.close());
  header.append(title, close);

  const details = element('div');
  const status = element('p');
  status.setAttribute('role', 'status');
  const actions = element('div');
  actions.className = 'vp3-chat-email-actions';
  dialog.append(header, details, status, actions);
  document.body.append(dialog);

  let selected = null;
  let pending = null;
  let specialistRequest = null;
  let specialistTimer = 0;
  let specialistActive = false;
  const thread = document.getElementById('chatThread');
  const compactStatus = element('p');
  compactStatus.className = 'vp3-chat-email-specialist-status';
  compactStatus.setAttribute('role', 'status');
  compactStatus.hidden = true;
  document.getElementById('chatComposerShell')?.prepend(compactStatus);

  function currentScope() {
    if (window.VP3ParticipantContinuity?.selectedContext?.()) return null;
    const config = window.STONEFELLOW_CHAT;
    const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    const agent = Number(chat?.agentId?.() ?? cfg.agentId);
    const conversation = Number(chat?.conversationId?.() || 0);
    if (String(config?.userId) !== cfg.ownerKey || Number(config?.agentId || 0) !== agent || agent !== cfg.agentId
        || chat?.isRestoring?.() || chat?.isReady?.() === false || !Number.isSafeInteger(conversation) || conversation < 1) return null;
    return { agent_id: agent, conversation_id: conversation };
  }

  function valid(selection) {
    const scope = currentScope();
    return dialog.open && selected === selection && scope?.conversation_id === selection.conversation_id
      && scope.agent_id === selection.agent_id;
  }

  function specialistScope() {
    return !document.hidden && thread?.dataset.view === 'chat' ? currentScope() : null;
  }

  function showSpecialist(status, scope) {
    specialistActive = status?.state === 'watching' || status?.state === 'paused';
    if (!specialistActive) {
      compactStatus.hidden = true;
      compactStatus.textContent = '';
      window.VP3ChatSpecialists?.remove('email');
      return;
    }
    const state = status.state === 'watching' ? 'Watching Gmail for new mail' : 'Gmail watch paused';
    const caption = 'Email Specialist · ' + state;
    if (compactStatus.textContent !== caption) compactStatus.textContent = caption;
    compactStatus.hidden = false;

    const id = status.origin_message_id;
    const target = Number.isSafeInteger(id) && id > 0 && thread.querySelector(
      `[data-chat-message-id="${id}"][data-chat-conversation-id="${scope.conversation_id}"][data-chat-message-role="user"]`
    );
    window.VP3ChatSpecialists?.update('email', {
      label:'Email Specialist', title:'Gmail follow-up', status:state,
      detail:status.state === 'watching'
        ? 'VP3 checks Gmail in the background and posts the first match in this Chat.'
        : 'This watch is waiting for Gmail to become available again.',
      onView:target ? () => {
        if (specialistScope()?.conversation_id !== scope.conversation_id || !target.isConnected) return;
        target.tabIndex = -1;
        target.scrollIntoView({block:'center'});
        target.focus({preventScroll:true});
      } : null,
    });
  }

  function clearSpecialist() {
    clearTimeout(specialistTimer);
    specialistRequest?.abort();
    specialistRequest = null;
    showSpecialist(null);
  }

  function refreshSpecialistSoon(delay = 0) {
    clearTimeout(specialistTimer);
    if (!specialistScope()) {
      clearSpecialist();
      return;
    }
    specialistTimer = setTimeout(() => void refreshSpecialist(), delay);
  }

  async function refreshSpecialist() {
    const scope = specialistScope();
    if (!scope) { clearSpecialist(); return; }
    const endpoint = new URL(cfg.endpoint, location.href);
    if (endpoint.origin !== location.origin) { clearSpecialist(); return; }

    specialistRequest?.abort();
    const controller = new AbortController();
    specialistRequest = controller;
    const timeout = setTimeout(() => controller.abort(), 10000);
    const wasActive = specialistActive;
    let retry = 0;
    const current = () => specialistRequest === controller && specialistScope()?.conversation_id === scope.conversation_id;
    try {
      const response = await fetch(endpoint, {
        method:'POST', credentials:'same-origin', cache:'no-store', signal:controller.signal,
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({...scope, action:'specialist_status', owner_key:cfg.ownerKey, csrf_token:cfg.csrf}),
      });
      const data = await response.json();
      if (!current()) return;
      if (response.ok && data.ok === true) {
        showSpecialist(data.specialist, scope);
        if (specialistActive) retry = 15000;
      } else {
        showSpecialist(null);
        if (wasActive && response.status >= 500) retry = 30000;
      }
    } catch (_error) {
      if (!current()) return;
      showSpecialist(null);
      if (wasActive) retry = 30000;
    } finally {
      clearTimeout(timeout);
      if (specialistRequest === controller) {
        specialistRequest = null;
        if (retry) refreshSpecialistSoon(retry);
      }
    }
  }

  function labelField(text, field) {
    const label = element('label');
    label.append(element('span', text), field);
    return label;
  }

  async function request(action, extra = {}) {
    const selection = selected;
    if (!selection || !valid(selection) || pending) return;
    const controller = new AbortController();
    pending = controller;
    const progress = {
      review: 'Loading the exact draft…', confirm: 'Checking the reviewed send…',
      revise: 'Saving a new review version…', discard: 'Discarding the draft…'
    };
    status.textContent = progress[action];
    const fields = [...dialog.querySelectorAll('button,input,textarea')].filter(field => field !== close);
    const prior = fields.map(field => field.disabled);
    fields.forEach(field => { field.disabled = true; });
    try {
      const response = await fetch(cfg.endpoint, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ ...selection, ...extra, action, owner_key:cfg.ownerKey, csrf_token:cfg.csrf })
      });
      const data = await response.json().catch(() => null);
      if (!valid(selection) || pending !== controller) return;
      if (!response.ok || !data?.ok || data.draft?.id !== selection.draft_id) {
        throw new Error(data?.error || 'The email review could not be verified.');
      }
      render(data.draft);
      if (action === 'confirm' && data.draft.watch_registered) refreshSpecialistSoon();
      if (action === 'revise') status.textContent = 'Changes saved. Review the updated email before sending.';
    } catch (error) {
      if (!valid(selection) || error.name === 'AbortError') return;
      status.textContent = error.message || 'The email review is unavailable.';
      if (action === 'confirm' || action === 'review' || action === 'revise') {
        actions.replaceChildren(button('Refresh review', () => request('review')));
      }
    } finally {
      if (pending === controller) {
        pending = null;
        fields.forEach((field, index) => { field.disabled = prior[index]; });
      }
    }
  }

  function render(draft) {
    details.replaceChildren();
    actions.replaceChildren();
    const states = {
      prepared: draft.is_reply && !draft.body.trim()
        ? 'Write your reply, then review it before sending.'
        : 'Review the exact email before approving the send.',
      sending: 'Gmail’s send outcome is pending. Check the status of this reviewed email.',
      sent: draft.watch_registered
        ? 'Gmail confirmed the send. A reply watch was registered for up to 30 days.'
        : (draft.watch_requested ? 'Gmail confirmed the send. Reply watching was unavailable.' : 'Gmail confirmed the send.'),
      failed: 'Gmail rejected this send. Prepare a new email in Chat to try again.',
      discarded: 'Draft discarded.',
      expired: 'This review expired. Prepare a new email in Chat.'
    };
    if (draft.connection_changed) {
      status.textContent = draft.status === 'sending'
        ? 'Gmail access changed while this send was pending. Check Sent in Gmail for the outcome.'
        : 'Gmail access changed. Check the account in Connections, then prepare a new draft.';
    } else {
      status.textContent = states[draft.status] || 'The email review is unavailable.';
    }
    if (draft.status === 'discarded') return;

    const addresses = element('dl');
    for (const [name, value] of [['From', draft.from], ['To', draft.to]]) {
      addresses.append(element('dt', name), element('dd', value));
    }
    if (draft.message_id) addresses.append(element('dt', 'Gmail message ID'), element('dd', draft.message_id));
    const subject = element('input');
    subject.type = 'text';
    subject.maxLength = 200;
    subject.value = draft.subject;
    const body = element('textarea');
    body.maxLength = 6000;
    body.rows = 7;
    body.value = draft.body;
    const editable = draft.status === 'prepared' && Boolean(draft.review_token);
    let editing = editable && draft.is_reply === true && !draft.body.trim();
    subject.readOnly = !editing || draft.is_reply === true;
    body.readOnly = !editing;
    details.append(addresses, labelField('Subject', subject), labelField('Message', body));

    const watch = element('input');
    watch.type = 'checkbox';
    watch.checked = Boolean(draft.watch_requested);
    watch.disabled = draft.status !== 'prepared';
    if (draft.watch_review_token) {
      const label = element('label');
      label.className = 'vp3-chat-email-watch';
      label.append(watch, element('span', 'Watch for the first reply for 30 days'));
      details.append(label);
    }
    if (draft.status === 'prepared') actions.append(button('Discard draft', () => request('discard')));
    if (!draft.review_token && !draft.watch_review_token) return;
    const complete = () => (draft.is_reply === true || Boolean(subject.value.trim())) && Boolean(body.value.trim());
    const confirm = button(draft.status === 'sending' ? 'Check send status' : 'Approve and send', () => {
      if (editing || !complete() || subject.value !== draft.subject || body.value !== draft.body) return;
      const token = watch.checked ? draft.watch_review_token : draft.review_token;
      if (token) void request('confirm', { confirmed:true, review_token:token, watch_replies:watch.checked });
    });
    confirm.className = 'vp3-chat-email-primary';
    confirm.disabled = !complete();
    if (editable) {
      const changed = () => subject.value !== draft.subject || body.value !== draft.body;
      const edit = button('Edit manually', () => {
        editing = true;
        status.textContent = 'Make your changes, then select Revise draft.';
        sync();
        body.focus();
      });
      const cancel = button('Cancel edits', () => {
        subject.value = draft.subject;
        body.value = draft.body;
        editing = false;
        status.textContent = states.prepared;
        sync();
        edit.focus();
      });
      const revise = button('Revise draft', () => {
        if (!editing || !changed() || !complete()) return;
        void request('revise', { review_token:draft.review_token, subject:subject.value, body:body.value });
      });
      const sync = () => {
        subject.readOnly = !editing || draft.is_reply === true;
        body.readOnly = !editing;
        watch.disabled = editing;
        edit.hidden = editing;
        cancel.hidden = !editing;
        revise.hidden = !editing;
        revise.disabled = !changed() || !complete();
        confirm.hidden = editing;
        confirm.disabled = editing || !complete() || changed();
      };
      subject.addEventListener('input', sync);
      body.addEventListener('input', sync);
      sync();
      actions.append(edit,revise,cancel);
    }
    actions.append(confirm);
    if (editing) body.focus();
  }

  document.addEventListener('click', event => {
    const target = event.target.closest?.('[data-chat-email-review]');
    if (!target || !document.getElementById('chatThread')?.contains(target)) return;
    const scope = currentScope();
    const draft = target.dataset.chatEmailReview;
    if (!scope || Number(target.dataset.chatEmailConversation) !== scope.conversation_id || !/^[a-f0-9]{32}$/.test(draft)) return;
    selected = { ...scope, draft_id:draft };
    details.replaceChildren();
    actions.replaceChildren();
    if (!dialog.open) dialog.showModal();
    void request('review');
  });

  dialog.addEventListener('close', () => {
    pending?.abort();
    pending = null;
    selected = null;
    details.replaceChildren();
    actions.replaceChildren();
    status.textContent = '';
  });
  window.addEventListener('stonefellow:chat-context-changing', () => {
    if (dialog.open) dialog.close();
    clearSpecialist();
  });
  window.addEventListener('vp3:chat-conversation-changed', () => {
    if (selected && !valid(selected)) dialog.close();
  });
  window.addEventListener('vp3:participant-selection-changed', () => {
    if (dialog.open && !valid(selected)) dialog.close();
    clearSpecialist();
    refreshSpecialistSoon();
  });
  window.addEventListener('vp3:chat-context-ready', () => refreshSpecialistSoon());
  window.addEventListener('vp3:chat-canvas-view-changed', () => refreshSpecialistSoon());
  window.addEventListener('focus', () => refreshSpecialistSoon());
  document.addEventListener('visibilitychange', () => refreshSpecialistSoon());
  window.addEventListener('pagehide', clearSpecialist);
  window.addEventListener('pageshow', () => refreshSpecialistSoon());
  window.addEventListener('vp3:chat-email-watch-changed', () => refreshSpecialistSoon());
  refreshSpecialistSoon();

  function installGithubWorkflowReview(gmail) {
    if (!cfg.githubWorkflow?.endpoint) return;
    const toolsPanel = gmail.closest('#chatToolsPanel');
    const form = element('form');
    form.className = 'vp3-chat-github-workflow';
    const toggle = element('input');
    toggle.type = 'checkbox';
    toggle.setAttribute('aria-describedby', 'vp3GithubWorkflowHelp');
    const toggleLabel = element('label');
    toggleLabel.className = 'vp3-chat-github-toggle';
    toggleLabel.append(toggle, element('strong', 'GitHub Workflow Review'));
    const description = element('p', 'Review new GitHub Actions notifications in your Gmail inbox and save findings in your private Knowledge. Announcements follow your Agent Voice setting.');
    description.id = 'vp3GithubWorkflowHelp';
    const destination = element('label');
    destination.className = 'vp3-chat-github-folder';
    const folder = element('input');
    folder.type = 'text';
    folder.name = 'folder_name';
    folder.maxLength = 160;
    folder.autocomplete = 'off';
    folder.placeholder = 'e.g. Workflow reviews';
    const folders = element('datalist');
    folders.id = 'vp3GithubWorkflowFolders';
    folder.setAttribute('list', folders.id);
    destination.append(element('span', 'Private Knowledge folder name'), folder,
      element('small', 'Choose the name of a root folder. VP3 will use your existing folder or create it for you.'));
    const status = element('p');
    status.setAttribute('role', 'status');
    const save = element('button', 'Save workflow review');
    save.type = 'submit';
    const restart = button('Restart workflow review', () => void send('restart'));
    const retry = button('Retry loading settings', () => void send('state'));
    const controls = element('div');
    controls.className = 'vp3-chat-github-controls';
    controls.append(save, restart, retry);
    form.append(toggleLabel, description, destination, folders, controls, status);
    gmail.append(form);

    let state = null;
    let request = null;
    let loadFailed = false;
    const hasOwnerScope = () => String(window.STONEFELLOW_CHAT?.userId) === cfg.ownerKey
      && !window.VP3ParticipantContinuity?.selectedContext?.();
    const visible = () => hasOwnerScope() && gmail.open && !toolsPanel?.hidden && !document.hidden;
    const messages = {
      disabled:'Workflow review is off.',
      waiting:'Waiting for the next background check.',
      checked:'Workflow review is on.',
      retrying:'The last check could not finish. Workflow review will try again.',
      restart_required:'Gmail’s review position expired. Restart to review new arrivals from now on.',
      destination_unavailable:'Choose a private Knowledge folder to resume workflow review.',
      gmail_required:'Connect Gmail and enable read access to use workflow review.',
      permission_required:'Workflow review requires Gmail read access and permission to manage private Knowledge.',
      unavailable:'Workflow review is currently unavailable.',
    };

    function renderControls() {
      const available = state?.available === true;
      const canDisable = state?.can_disable === true;
      const enabled = toggle.checked;
      const folderName = folder.value.trim();
      toggle.disabled = Boolean(request) || (!available && !canDisable);
      destination.hidden = !enabled;
      folder.disabled = Boolean(request) || !enabled || !available;
      folder.required = enabled;
      save.disabled = Boolean(request) || (enabled
        ? !available || !folderName || (state?.enabled && state.folder_name === folderName && state.status !== 'destination_unavailable')
        : !canDisable);
      restart.hidden = !(available && state?.enabled && state.status === 'restart_required');
      restart.disabled = Boolean(request);
      retry.hidden = !loadFailed;
      retry.disabled = Boolean(request);
    }

    function setState(next) {
      state = next;
      toggle.checked = next?.enabled === true;
      folder.value = typeof next?.folder_name === 'string' ? next.folder_name : '';
      folders.replaceChildren();
      for (const entry of Array.isArray(next?.folders) ? next.folders : []) {
        if (typeof entry?.name !== 'string') continue;
        const option = element('option');
        option.value = entry.name;
        folders.append(option);
      }
      status.textContent = next ? messages[next.status] || '' : '';
      renderControls();
    }

    function clear() {
      request?.abort();
      request = null;
      loadFailed = false;
      setState(null);
    }

    async function send(action) {
      if (request || !visible()) return;
      const payload = {action, owner_key:cfg.ownerKey, csrf_token:cfg.csrf};
      if (action !== 'state') {
        if (!state) return;
        Object.assign(payload, {connection_id:state.connection_id, connection_revision:state.connection_revision, github_revision:state.revision});
      }
      if (action === 'enable') {
        const name = folder.value.trim();
        if (!name || Array.from(name).length > 80) {
          status.textContent = 'Enter a folder name of up to 80 characters.';
          folder.focus();
          return;
        }
        payload.folder_name = name;
      }
      const current = new AbortController();
      request = current;
      const timeout = setTimeout(() => current.abort(), 10000);
      let failure = action === 'state' ? 'Could not load workflow review settings.' : 'Could not save workflow review settings.';
      status.textContent = action === 'state' ? 'Loading workflow review settings…' : 'Saving workflow review settings…';
      renderControls();
      try {
        const response = await fetch(cfg.githubWorkflow.endpoint, {
          method:'POST', credentials:'same-origin', cache:'no-store', signal:current.signal,
          headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify(payload),
        });
        const result = await response.json();
        if (request !== current || !visible()) return;
        if (!response.ok || result?.ok !== true) {
          if ([401,403,409,419].includes(response.status)) setState(null);
          if (response.status === 409) failure = 'Workflow review settings changed. Close and reopen Gmail to refresh them.';
          if (response.status === 422) failure = 'Check the Knowledge folder name and try again.';
          throw new Error(failure);
        }
        if (result.owner_key !== cfg.ownerKey || !result.state) {
          setState(null);
          throw new Error(failure);
        }
        loadFailed = false;
        setState(result.state);
      } catch (_error) {
        if (request !== current || !visible()) return;
        loadFailed = action === 'state' || !state;
        status.textContent = failure;
      } finally {
        clearTimeout(timeout);
        if (request === current) {
          request = null;
          renderControls();
        }
      }
    }

    function sync() {
      form.hidden = !hasOwnerScope();
      if (!visible()) clear();
      else if (!state && !request && !loadFailed) void send('state');
    }

    toggle.addEventListener('change', renderControls);
    folder.addEventListener('input', renderControls);
    form.addEventListener('submit', event => {
      event.preventDefault();
      if (!save.disabled) void send(toggle.checked ? 'enable' : 'disable');
    });
    gmail.addEventListener('toggle', sync);
    const observer = toolsPanel && new MutationObserver(sync);
    observer?.observe(toolsPanel, {attributeFilter:['hidden']});
    window.addEventListener('vp3:participant-selection-changed', sync);
    window.addEventListener('stonefellow:chat-context-changing', sync);
    window.addEventListener('vp3:chat-context-ready', sync);
    window.addEventListener('focus', sync);
    document.addEventListener('visibilitychange', sync);
    window.addEventListener('pagehide', clear);
    window.addEventListener('pageshow', sync);
    renderControls();
    sync();
  }

  const tools = document.getElementById('chatToolsContent');
  if (tools) {
    const help = element('details');
    const link = element('a','Open Connections');
    link.href = cfg.connectionsUrl;
    help.append(element('summary','Gmail'),
      element('p','Try “Show emails from buyer@example.com” or “Draft an email to buyer@example.com about our launch.”'),
      element('p','To get one notification in this Chat, ask “Notify me when I get an email from buyer@example.com.” To end the watch, ask “Stop watching for emails from buyer@example.com.”'));
    tools.append(help);
    installGithubWorkflowReview(help);
    help.append(link);
  }
})();
