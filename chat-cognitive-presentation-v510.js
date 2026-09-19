(() => {
  'use strict';
  if (window.__VP3_COGNITIVE_PRESENTATION_V510__) return;
  window.__VP3_COGNITIVE_PRESENTATION_V510__ = true;

  const cfg = window.VP3_COGNITIVE_PRESENTATION_V510 || {};
  const button = document.getElementById('chatAgentBriefButton');
  const popup = document.getElementById('chatAgentBriefPopover');
  const content = popup ? popup.querySelector('[data-agent-brief-content]') : null;
  const statusDot = button ? button.querySelector('[data-agent-status-dot]') : null;
  const badge = button ? button.querySelector('[data-agent-attention-badge]') : null;
  const thread = document.getElementById('chatThread');
  const form = document.getElementById('chatForm');
  const input = document.getElementById('chatInput');
  if (!button || !popup || !content || !thread || !form) return;

  let state = null;
  let timer = 0;
  let busy = false;
  let lastVoiceThrough = 0;
  let currentDigestId = '';

  const esc = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[char]));

  function endpoint(action) {
    const url = new URL(String(cfg.endpoint || '/api/cognitive-presentation-v510.php'), window.location.origin);
    url.searchParams.set('action', action);
    url.searchParams.set('agent_id', String(Number(cfg.agentId || 0)));
    return url.toString();
  }

  async function getState() {
    const response = await fetch(endpoint('state'), {credentials:'same-origin',headers:{Accept:'application/json'}});
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error || 'Cognitive presentation is unavailable.');
    return data.state || {};
  }

  async function post(action, payload) {
    const response = await fetch(String(cfg.endpoint || '/api/cognitive-presentation-v510.php'), {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify(Object.assign({
        action:action,
        agent_id:Number(cfg.agentId || 0),
        csrf_token:String(cfg.csrf || '')
      }, payload || {}))
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error || 'Cognitive presentation update failed.');
    return data;
  }

  function closePopup() {
    popup.hidden = true;
    button.setAttribute('aria-expanded','false');
  }

  function openPopup() {
    popup.hidden = false;
    button.setAttribute('aria-expanded','true');
  }

  function openBrain() {
    const center = window.STONEFELLOW_NOTIFICATION_CENTER;
    if (center && typeof center.openBrain === 'function') center.openBrain();
    else {
      if (center && typeof center.open === 'function') center.open();
      window.setTimeout(() => {
        const tab = document.querySelector('[data-notification-tab="brain"]');
        if (tab) tab.click();
      }, 20);
    }
    closePopup();
  }

  function openHistory() {
    const center = window.STONEFELLOW_NOTIFICATION_CENTER;
    if (center && typeof center.openHistory === 'function') center.openHistory();
    else {
      if (center && typeof center.open === 'function') center.open();
      window.setTimeout(() => {
        const tab = document.querySelector('[data-notification-tab="history"]');
        if (tab) tab.click();
      }, 20);
    }
    closePopup();
  }

  function openNotifications() {
    const center = window.STONEFELLOW_NOTIFICATION_CENTER;
    if (center && typeof center.open === 'function') center.open();
    closePopup();
  }

  function runPrompt(prompt) {
    const text = String(prompt || '').trim();
    if (!text || !input) return;
    input.value = text;
    input.dispatchEvent(new Event('input',{bubbles:true}));
    form.requestSubmit();
    closePopup();
  }

  function runtimeState() {
    return String(document.body.dataset.stonefellowAgentState || 'idle');
  }

  function renderStatus(brief) {
    const active = brief && brief.active !== false;
    if (statusDot) statusDot.classList.toggle('active', active);
    button.dataset.active = active ? '1' : '0';
    button.dataset.runtimeState = runtimeState();
    button.setAttribute('aria-label',
      String((brief && brief.agent_name) || 'Agent') + ' · ' +
      String((brief && brief.status_label) || (active ? 'Active' : 'Inactive'))
    );
    const count = Math.max(0, Number((brief && brief.attention_count) || 0));
    if (badge) {
      badge.hidden = count < 1;
      badge.textContent = count > 99 ? '99+' : String(count);
    }
  }

  function actionButton(label, attrs, primary) {
    return '<button type="button"' +
      (primary ? ' class="primary"' : '') +
      attrs + '>' + esc(label) + '</button>';
  }

  function briefMarkup(brief) {
    brief = brief || {};
    const suggestion = brief.top_suggestion || null;
    const work = brief.current_work || null;
    const calendar = brief.next_calendar || null;
    const counts = brief.counts || {};
    let html = '<div class="chat-agent-brief-status"><span><i class="chat-agent-brief-dot ' +
      (brief.active !== false ? 'active' : '') + '"></i>' +
      esc(brief.agent_name || 'Agent') + ' · ' + esc(brief.status_label || 'Active') +
      '</span><small>' + esc(brief.activity_title || 'Ready') + '</small></div>';

    if (suggestion) {
      html += '<article class="chat-agent-brief-card"><small>Suggested next action</small><strong>' +
        esc(suggestion.title || 'Next action') + '</strong>' +
        (suggestion.reason ? '<p>' + esc(suggestion.reason) + '</p>' : '');
      if (suggestion.prompt) {
        html += '<div class="chat-agent-brief-actions">' +
          actionButton('Ask Agent',' data-agent-brief-prompt="' + esc(suggestion.prompt) + '"',true) +
          '</div>';
      }
      html += '</article>';
    }

    if (work) {
      html += '<article class="chat-agent-brief-card"><small>Current work · ' + esc(work.lane || '') +
        '</small><strong>' + esc(work.title || 'Agent work') + '</strong>' +
        (work.detail ? '<p>' + esc(work.detail) + '</p>' : '') +
        '<div class="chat-agent-brief-actions"><a href="/agent-workflows.php">Open work</a></div></article>';
    }

    if (calendar) {
      const preparePrompt = 'Help me prepare for this upcoming calendar item: ' + String(calendar.title || 'meeting');
      html += '<article class="chat-agent-brief-card"><small>Next scheduled</small><strong>' +
        esc(calendar.title || 'Calendar item') + '</strong>' +
        (calendar.start_at_utc ? '<p>' + esc(calendar.start_at_utc) + '</p>' : '') +
        '<div class="chat-agent-brief-actions"><a href="/calendar.php">Open calendar</a>' +
        actionButton('Prepare',' data-agent-brief-prompt="' + esc(preparePrompt) + '"',false) +
        '</div></article>';
    }

    html += '<div class="chat-agent-brief-counts">' +
      '<span><strong>' + Number(counts.approvals || 0) + '</strong><small>Approvals</small></span>' +
      '<span><strong>' + Number(counts.blocked || 0) + '</strong><small>Blocked</small></span>' +
      '<span><strong>' + Number(counts.failed || 0) + '</strong><small>Failed</small></span>' +
      '<span><strong>' + Number(counts.unread || 0) + '</strong><small>Unread</small></span>' +
      '</div>';
    return html;
  }

  function renderBrief(brief) {
    renderStatus(brief || {});
    content.innerHTML = briefMarkup(brief || {});
  }

  function digestMarkup(digest) {
    const items = Array.isArray(digest && digest.items) ? digest.items : [];
    let list = '';
    items.forEach(item => {
      const isLink = Boolean(item.target_url);
      const tag = isLink ? 'a' : 'div';
      const href = isLink ? ' href="' + esc(item.target_url) + '"' : '';
      let flag = '';
      if (Number(item.count || 1) > 1) flag = '<em>' + Number(item.count) + ' updates</em>';
      else if (item.attention) flag = '<em>Attention</em>';
      list += '<' + tag + ' class="vp3-return-digest-item' + (item.attention ? ' attention' : '') + '"' + href +
        (item.card_request ? ' data-has-card="1"' : '') + '>' +
        '<div><strong>' + esc(item.title || 'Update') + '</strong>' +
        (item.body ? '<span>' + esc(item.body) + '</span>' : '') +
        '</div>' + flag + '</' + tag + '>';
    });
    return '<section class="vp3-return-digest" data-vp3-return-digest="' + esc(digest.id || '') + '">' +
      '<div class="vp3-return-digest-head"><div><small>While you were away</small><strong>' +
      esc(digest.summary || 'There are updates to review.') +
      '</strong></div><div class="vp3-return-digest-actions">' +
      '<button type="button" data-digest-action="acknowledged">Acknowledge</button>' +
      '<button type="button" data-digest-action="dismissed">Dismiss</button></div></div>' +
      '<div class="vp3-return-digest-list">' + list + '</div></section>';
  }

  function renderDigest(digest) {
    document.querySelectorAll('[data-vp3-return-digest]').forEach(node => {
      if (!digest || node.dataset.vp3ReturnDigest !== String(digest.id || '')) node.remove();
    });
    if (!digest || !digest.id) {
      currentDigestId = '';
      return;
    }
    currentDigestId = String(digest.id);
    const selector = '[data-vp3-return-digest="' + CSS.escape(currentDigestId) + '"]';
    if (document.querySelector(selector)) return;
    const holder = document.createElement('div');
    holder.innerHTML = digestMarkup(digest);
    const node = holder.firstElementChild;
    if (!node) return;
    const welcome = document.getElementById('chatWelcome');
    if (welcome && welcome.parentNode === thread) thread.insertBefore(node,welcome);
    else thread.appendChild(node);
    const requests = (Array.isArray(digest.items) ? digest.items : [])
      .map(item => item && item.card_request ? item.card_request : null)
      .filter(Boolean);
    if (requests.length && window.VP3_COGNITIVE_CARDS_V520_RUNTIME) {
      const host = document.createElement('div');
      host.className = 'vp3-return-digest-card-host vp3-cognitive-card-host';
      node.appendChild(host);
      const fallbackItems = Array.from(node.querySelectorAll('.vp3-return-digest-item[data-has-card="1"]'));
      void window.VP3_COGNITIVE_CARDS_V520_RUNTIME.renderRequests(requests,host,{showError:false}).then(result => {
        const available = result && Array.isArray(result.availableIndices) ? result.availableIndices : [];
        available.forEach(index => {
          const fallback = fallbackItems[Number(index || 0)];
          if (fallback) fallback.hidden = true;
        });
      });
    }
  }

  async function maybeSpeak(candidate) {
    if (!candidate || !candidate.through_id || !candidate.message) return;
    const through = Number(candidate.through_id || 0);
    if (through < 1 || through <= lastVoiceThrough) return;
    lastVoiceThrough = through;
    const center = window.STONEFELLOW_NOTIFICATION_CENTER;
    if (center && typeof center.announce === 'function') center.announce(String(candidate.message));
    try { await post('voice_delivered',{through_id:through}); } catch (_error) {}
  }

  async function refresh() {
    if (busy || document.hidden) return;
    busy = true;
    try {
      state = await getState();
      renderBrief(state.brief || {});
      renderDigest(state.digest || null);
      await maybeSpeak(state.voice_candidate || null);
    } catch (_error) {
      if (statusDot) statusDot.classList.remove('active');
      button.dataset.active='0';
    } finally {
      busy = false;
    }
  }

  function schedule() {
    if (timer) window.clearInterval(timer);
    const seconds = Math.max(15, Number((state && state.poll_seconds) || cfg.pollSeconds || 30));
    timer = window.setInterval(refresh, seconds * 1000);
  }

  button.addEventListener('click', event => {
    event.preventDefault();
    if (popup.hidden) openPopup(); else closePopup();
  });
  const close = popup.querySelector('[data-agent-brief-close]');
  if (close) close.addEventListener('click',closePopup);
  const brain = popup.querySelector('[data-agent-brief-brain]');
  if (brain) brain.addEventListener('click',openBrain);
  const history = popup.querySelector('[data-agent-brief-history]');
  if (history) history.addEventListener('click',openHistory);
  const notifications = popup.querySelector('[data-agent-brief-notifications]');
  if (notifications) notifications.addEventListener('click',openNotifications);

  popup.addEventListener('click', event => {
    const prompt = event.target.closest('[data-agent-brief-prompt]');
    if (prompt) runPrompt(prompt.dataset.agentBriefPrompt || '');
  });

  document.addEventListener('click', event => {
    if (popup.hidden || popup.contains(event.target) || button.contains(event.target)) return;
    closePopup();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closePopup();
  });

  thread.addEventListener('click', async event => {
    const action = event.target.closest('[data-digest-action]');
    if (!action || !currentDigestId) return;
    action.disabled = true;
    try {
      await post('digest_ack',{digest_id:currentDigestId,status:String(action.dataset.digestAction || 'acknowledged')});
      const selector = '[data-vp3-return-digest="' + CSS.escape(currentDigestId) + '"]';
      const node = document.querySelector(selector);
      if (node) node.remove();
      currentDigestId = '';
    } catch (_error) {
      action.disabled = false;
    }
  });

  form.addEventListener('submit', () => {
    window.setTimeout(() => post('interaction').catch(() => {}),0);
  }, true);

  window.addEventListener('stonefellow:chat-voice', event => {
    if (String(event.detail && event.detail.type || '') === 'TRANSCRIPT_SUBMIT') {
      void post('interaction').catch(() => {});
    }
  });

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) void refresh();
  });

  const observer = new MutationObserver(() => {
    button.dataset.runtimeState = runtimeState();
    if (state && state.brief) renderStatus(state.brief);
  });
  observer.observe(document.body,{attributes:true,attributeFilter:['data-stonefellow-agent-state']});

  window.addEventListener('pagehide', () => {
    if (timer) window.clearInterval(timer);
    observer.disconnect();
  }, {once:true});

  window.VP3_COGNITIVE_PRESENTATION = {refresh:refresh,openBrief:openPopup,closeBrief:closePopup};
  void refresh().finally(schedule);
})();