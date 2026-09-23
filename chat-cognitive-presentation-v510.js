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
  let activeVoiceThrough = 0;
  let suppressedVoiceThrough = 0;
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
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timeout = controller ? window.setTimeout(() => controller.abort(), 10000) : 0;
    try {
      const response = await fetch(endpoint('state'), {
        credentials:'same-origin',
        cache:'no-store',
        headers:{Accept:'application/json'},
        signal:controller ? controller.signal : undefined
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) throw new Error(data.error || 'Cognitive presentation is unavailable.');
      return data.state || {};
    } catch (error) {
      if (error && error.name === 'AbortError') throw new Error('Agent status request timed out.');
      throw error;
    } finally {
      if (timeout) window.clearTimeout(timeout);
    }
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
    void refresh(true);
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
    const followthrough = brief.followthrough || null;
    const awayFollowthrough = brief.away_followthrough || {};
    const supervision = brief.supervision_focus || null;
    const supervisionCounts = brief.supervision_counts || {};
    const autonomy = brief.autonomy_focus || null;
    const autonomyCounts = brief.autonomy_counts || {};
    const portfolio = brief.portfolio_focus || null;
    const portfolioCounts = brief.portfolio_counts || {};
    const portfolioCapacity = brief.portfolio_capacity || {};
    const forecast = brief.forecast_focus || null;
    const forecastCounts = brief.forecast_counts || {};
    const optimization = brief.optimization_focus || null;
    const optimizationStrategy = String(brief.optimization_strategy || '');
    const optimizationCounts = brief.optimization_counts || {};
    const resourceBudget = brief.resource_budget_focus || null;
    const resourceBudgetCounts = brief.resource_budget_counts || {};
    const resourceBudgetExecutors = brief.resource_budget_executors || {};
    const counts = brief.counts || {};
    let html = '<div class="chat-agent-brief-status"><span><i class="chat-agent-brief-dot ' +
      (brief.active !== false ? 'active' : '') + '"></i>' +
      esc(brief.agent_name || 'Agent') + ' · ' + esc(brief.status_label || 'Active') +
      '</span><small>' + esc(brief.activity_title || 'Ready') + '</small></div>';

    if (resourceBudget) {
      const executor = String(resourceBudget.executor || 'cloud');
      const state = String(resourceBudget.reservation_state || 'planned').replaceAll('_',' ');
      const executorBudget = resourceBudgetExecutors?.[executor] || {};
      html += '<article class="chat-agent-brief-card"><small>Capacity reservation · ' +
        esc(executor) + '</small><strong>' +
        esc(resourceBudget.title || ('Goal #' + Number(resourceBudget.goal_id || 0))) + '</strong>' +
        '<p>' + esc(state) + ' · reservation score ' + Number(resourceBudget.reservation_score || 0).toFixed(2) + '</p>' +
        '<p><small>' + Number(resourceBudgetCounts.active || 0) + ' active · ' +
        Number(resourceBudgetCounts.planned || 0) + ' planned · ' +
        Number(executorBudget.capacity_free || 0) + ' current free slot(s)</small></p>' +
        '<div class="chat-agent-brief-actions">' +
        actionButton('Review capacity plan',
          ' data-agent-brief-prompt="' + esc('Review my resource budget and capacity reservations. Explain what capacity is protected, why, and what remains available without changing or executing anything.') + '"',false) +
        '</div></article>';
    }

    if (optimization) {
      const strategy = optimizationStrategy.replaceAll('_',' ') || 'balanced';
      html += '<article class="chat-agent-brief-card"><small>Strategic optimization · ' +
        esc(strategy) + '</small><strong>' +
        esc(optimization.title || ('Goal #' + Number(optimization.goal_id || 0))) + '</strong>' +
        '<p>#' + Number(optimization.rank || 0) + ' optimized sequence · priority ' +
        Number(optimization.priority || 0) + '/100</p>' +
        '<p><small>' + Number(optimizationCounts.strategies || 0) + ' strategies compared · ' +
        Number(optimizationCounts.projected_deadline_risk || 0) + ' projected deadline risk</small></p>' +
        '<div class="chat-agent-brief-actions">' +
        actionButton('Compare strategies',
          ' data-agent-brief-prompt="' + esc('Compare my portfolio optimization strategies and explain why ' + strategy + ' is currently recommended without changing or executing anything.') + '"',false) +
        '</div></article>';
    }

    if (forecast) {
      const risk = String(forecast.risk || 'on_track').replaceAll('_',' ');
      const confidence = String(forecast.confidence?.label || 'low');
      html += '<article class="chat-agent-brief-card"><small>Portfolio forecast · ' +
        esc(forecast.executor || 'cloud') + '</small><strong>' +
        esc(forecast.title || ('Goal #' + Number(forecast.goal_id || 0))) + '</strong>' +
        '<p>' + esc(risk) + ' · ' + esc(confidence) + ' confidence</p>' +
        '<p><small>Likely completion ' + esc(forecast.likely_completion_at || 'unknown') +
        (Number(forecastCounts.conflicts || 0) ? ' · ' + Number(forecastCounts.conflicts || 0) + ' projected conflict(s)' : '') +
        '</small></p></article>';
    }

    if (portfolio) {
      const action = String(portfolio.coordination_action || 'observe').replaceAll('_',' ');
      const hold = String(portfolio.hold_reason || '').replaceAll('_',' ');
      const executor = String(portfolio.executor || 'cloud');
      const free = Number(portfolioCapacity.executors?.[executor]?.free || 0);
      html += '<article class="chat-agent-brief-card"><small>Portfolio coordination · ' +
        esc(executor) + '</small><strong>' +
        esc(portfolio.title || ('Goal #' + Number(portfolio.goal_id || 0))) + '</strong>' +
        '<p>' + Number(portfolio.score_percent || 0) + '/100 coordination score · ' +
        esc(action) + (hold ? ' · held: ' + esc(hold) : '') + '</p>' +
        '<p><small>' + free + ' worker slot' + (free === 1 ? '' : 's') + ' currently free</small></p>' +
        ((portfolio.requires_user || hold === 'semantic overlap') ? '<div class="chat-agent-brief-actions">' +
          actionButton('Review portfolio',
            ' data-agent-brief-prompt="' + esc('Review autonomous goal #' + Number(portfolio.goal_id || 0) + ' in my portfolio, including capacity, overlap, dependencies, and the safest next action.') + '"',true) +
          '</div>' : '') +
        '</article>';
    }

    if (autonomy) {
      const mode = String(autonomy.execution_mode || 'manual');
      const state = String(autonomy.execution_state || 'unknown').replaceAll('_',' ');
      const recommendation = autonomy.recommendation || {};
      html += '<article class="chat-agent-brief-card"><small>Long-horizon goal · ' +
        esc(mode) + '</small><strong>' +
        esc(autonomy.title || ('Goal #' + Number(autonomy.goal_id || 0))) + '</strong>' +
        '<p>' + esc(state) + ' · ' + Number(autonomy.progress_percent || 0) + '% verified</p>' +
        '<p><small>' + esc(String(recommendation.action || 'observe').replaceAll('_',' ')) + '</small></p>' +
        (recommendation.requires_user ? '<div class="chat-agent-brief-actions">' +
          actionButton('Review goal',
            ' data-agent-brief-prompt="' + esc('Review goal #' + Number(autonomy.goal_id || 0) + ' and tell me what is blocking autonomous progress.') + '"',true) +
          '</div>' : '') +
        '</article>';
    }

    if (supervision) {
      const severity = String(supervision.severity || 'info');
      const health = String(supervision.health_state || 'open').replaceAll('_',' ');
      html += '<article class="chat-agent-brief-card"><small>Autonomous supervision · ' +
        esc(severity) + '</small><strong>' +
        esc(supervision.title || 'Open work needs review') + '</strong>' +
        (supervision.reason ? '<p>' + esc(supervision.reason) + '</p>' : '') +
        '<p><small>' + esc(health) +
        (supervision.supervisor_action ? ' · ' + esc(String(supervision.supervisor_action).replaceAll('_',' ')) : '') +
        '</small></p>' +
        (supervision.requires_user ? '<div class="chat-agent-brief-actions">' +
          actionButton(supervision.requires_approval ? 'Review approval' : 'Review with Agent',
            ' data-agent-brief-prompt="' + esc('Review supervised work ' + String(supervision.continuity_ref || '') + ' and tell me the safest next step.') + '"',true) +
          '</div>' : '') +
        '</article>';
    }

    if (Number(awayFollowthrough.count || 0) > 0) {
      html += '<article class="chat-agent-brief-card"><small>While you were away</small><strong>' +
        esc(awayFollowthrough.summary || 'Open work changed while you were away.') + '</strong>' +
        '<p>' + Number(awayFollowthrough.count || 0) + ' meaningful continuity change' +
        (Number(awayFollowthrough.count || 0) === 1 ? '' : 's') + ' detected.</p>' +
        '</article>';
    }

    if (followthrough) {
      const source = String(followthrough.source_surface || 'chat').replaceAll('_',' ');
      const target = String(followthrough.target_surface || 'chat').replaceAll('_',' ');
      const state = String(followthrough.continuity_state || '').replaceAll('_',' ');
      const turn = String(followthrough.turn_type || '').replaceAll('_',' ');
      html += '<article class="chat-agent-brief-card"><small>Follow-through · ' +
        esc(state || 'open') + '</small><strong>' +
        esc(followthrough.title || 'Open work') + '</strong>' +
        (followthrough.body ? '<p>' + esc(followthrough.body) + '</p>' : '') +
        '<p><small>' + esc(source) + ' → ' + esc(target) +
        (turn ? ' · ' + esc(turn) : '') + '</small></p>' +
        '<div class="chat-agent-brief-actions">' +
        (followthrough.target_url ? '<a href="' + esc(followthrough.target_url) + '">' + esc(followthrough.action_label || 'Open') + '</a>' : '') +
        '</div></article>';
    }

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
      (Number(supervisionCounts.critical || 0) + Number(supervisionCounts.high || 0) > 0
        ? '<span><strong>' + (Number(supervisionCounts.critical || 0) + Number(supervisionCounts.high || 0)) + '</strong><small>Supervision</small></span>'
        : '') +
      (Number(autonomyCounts.autonomous || 0) > 0
        ? '<span><strong>' + Number(autonomyCounts.autonomous || 0) + '</strong><small>Autonomous goals</small></span>'
        : '') +
      (Number(portfolioCounts.held || 0) > 0
        ? '<span><strong>' + Number(portfolioCounts.held || 0) + '</strong><small>Portfolio holds</small></span>'
        : '') +
      '</div>';
    return html;
  }

  function renderBrief(brief) {
    renderStatus(brief || {});
    content.setAttribute('aria-busy','false');
    content.innerHTML = briefMarkup(brief || {});
  }

  function briefErrorMessage(error) {
    const raw = String(error && error.message || '').trim();
    if (/presentation_schema_not_ready/i.test(raw)) {
      return 'Agent status needs the latest VP3 database upgrade. Apply the database upgrade, then retry.';
    }
    if (/database_unavailable/i.test(raw)) {
      return 'Agent status cannot reach its database right now. Retry in a moment.';
    }
    if (/forbidden|login_required/i.test(raw)) {
      return 'Agent status is not available for this signed-in account.';
    }
    if (/timed out/i.test(raw)) {
      return 'Agent status took too long to respond. Retry the request.';
    }
    return 'Agent status is temporarily unavailable. Retry in a moment.';
  }

  function renderBriefError(error) {
    if (statusDot) statusDot.classList.remove('active');
    button.dataset.active='0';
    button.setAttribute('aria-label','Agent · status unavailable');
    content.setAttribute('aria-busy','false');
    const message = briefErrorMessage(error);
    content.innerHTML =
      '<div class="chat-agent-brief-status"><span><i class="chat-agent-brief-dot"></i>Agent</span><small>Unavailable</small></div>' +
      '<article class="chat-agent-brief-card" role="status"><small>Status</small><strong>Agent Brief could not load.</strong>' +
      '<p>' + esc(message) + '</p><div class="chat-agent-brief-actions">' +
      '<button type="button" class="primary" data-agent-brief-retry>Retry</button></div></article>';
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
    if (!candidate || !candidate.through_id || !candidate.message) return false;
    const through = Number(candidate.through_id || 0);
    if (through < 1) return false;

    if (through <= Math.max(lastVoiceThrough, suppressedVoiceThrough)) {
      try {
        await post(through <= suppressedVoiceThrough ? 'voice_suppressed' : 'voice_delivered',{through_id:through});
        return true;
      } catch (_error) {
        return false;
      }
    }

    const center = window.STONEFELLOW_NOTIFICATION_CENTER;
    if (!center || typeof center.announce !== 'function') return false;

    let spoken = false;
    activeVoiceThrough = through;
    try {
      spoken = (await Promise.resolve(center.announce(String(candidate.message)))) === true;
    } catch (_error) {
      spoken = false;
    }
    if (!spoken) {
      if (activeVoiceThrough === through) activeVoiceThrough = 0;
      return false;
    }

    lastVoiceThrough = through;
    if (activeVoiceThrough === through) activeVoiceThrough = 0;
    try {
      await post('voice_delivered',{through_id:through});
      return true;
    } catch (_error) {
      return false;
    }
  }

  async function refresh(force = false) {
    if (busy || (document.hidden && !force)) return;
    busy = true;
    content.setAttribute('aria-busy','true');
    try {
      state = await getState();
      renderBrief(state.brief || {});
      renderDigest(state.digest || null);
      await maybeSpeak(state.voice_candidate || null);
    } catch (error) {
      renderBriefError(error);
    } finally {
      content.setAttribute('aria-busy','false');
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
    const retry = event.target.closest('[data-agent-brief-retry]');
    if (retry) {
      retry.disabled = true;
      void refresh(true).finally(() => { retry.disabled = false; });
      return;
    }
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

  window.addEventListener('stonefellow:agent-stop', () => {
    const center = window.STONEFELLOW_NOTIFICATION_CENTER;
    try { center?.cancelSpeech?.(); } catch (_error) {}
    const through = Math.max(0, Number(activeVoiceThrough || 0));
    if (through < 1) return;
    suppressedVoiceThrough = Math.max(suppressedVoiceThrough, through);
    lastVoiceThrough = Math.max(lastVoiceThrough, through);
    activeVoiceThrough = 0;
    void post('voice_suppressed',{through_id:through}).catch(() => {});
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