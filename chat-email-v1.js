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

  function currentScope() {
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
      prepared: 'Review the exact email before approving the send.',
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
    subject.readOnly = body.readOnly = !editable;
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
    const confirm = button(draft.status === 'sending' ? 'Check send status' : 'Approve and send', () => {
      if (subject.value !== draft.subject || body.value !== draft.body) return;
      const token = watch.checked ? draft.watch_review_token : draft.review_token;
      if (token) void request('confirm', { confirmed:true, review_token:token, watch_replies:watch.checked });
    });
    confirm.className = 'vp3-chat-email-primary';
    if (editable) {
      const revise = button('Review edited draft', () => request('revise', {
        review_token:draft.review_token, subject:subject.value, body:body.value,
      }));
      const sync = () => {
        const changed = subject.value !== draft.subject || body.value !== draft.body;
        revise.hidden = !changed;
        revise.disabled = !subject.value.trim() || !body.value.trim();
        confirm.hidden = changed;
      };
      subject.addEventListener('input', sync);
      body.addEventListener('input', sync);
      sync();
      actions.append(revise);
    }
    actions.append(confirm);
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
  window.addEventListener('stonefellow:chat-context-changing', () => { if (dialog.open) dialog.close(); });
  window.addEventListener('vp3:chat-conversation-changed', () => {
    if (selected && !valid(selected)) dialog.close();
  });

  const tools = document.getElementById('chatToolsContent');
  if (tools) {
    const help = element('details');
    const link = element('a','Open Connections');
    link.href = cfg.connectionsUrl;
    help.append(element('summary','Gmail'), element('p','Ask “Draft an email to buyer@example.com about our launch” or “Search Gmail for is:unread invoices.”'), link);
    tools.append(help);
  }
})();
