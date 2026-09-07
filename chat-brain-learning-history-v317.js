(() => {
  'use strict';

  const cfg = window.STONEFELLOW_NOTIFICATION_DRAWER || {};
  if (!cfg.endpoint) return;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
  }[char]));
  const relative = value => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.max(1, Math.floor(seconds / 60))}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
    return date.toLocaleDateString();
  };
  const label = value => ({
    successful:'Worked',
    resolved:'Resolved',
    unsuccessful:"Didn't work",
    ignored:'Ignored',
    acted:'In progress',
    awaiting:'Awaiting outcome'
  }[String(value || '')] || 'Learning event');
  const sourceLabel = value => String(value || 'agent_brain')
    .replaceAll('_', ' ')
    .replace(/\b\w/g, char => char.toUpperCase());
  const factor = value => {
    const n = Number(value);
    return Number.isFinite(n) ? n : 1;
  };
  const factorDelta = value => {
    const n = Number(value || 0);
    if (!Number.isFinite(n) || Math.abs(n) < 0.0005) return 'No weighting change';
    const sign = n > 0 ? '+' : '';
    return `${sign}${n.toFixed(2)} source weight`;
  };
  const safeInternalUrl = value => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const target = new URL(raw, window.location.href);
      return target.origin === window.location.origin ? target.href : '';
    } catch (_error) {
      return '';
    }
  };

  let drawer = null;
  let body = null;
  let tab = null;
  let active = false;
  let loading = false;
  let cache = null;
  let bodyObserver = null;

  function endpoint() {
    const target = new URL(String(cfg.endpoint), window.location.href);
    if (/chat-notifications-brain-v240\.php$/.test(target.pathname)) {
      target.pathname = target.pathname.replace(/chat-notifications-brain-v240\.php$/, 'agent-learning-history-v317.php');
    } else {
      const slash = target.pathname.lastIndexOf('/');
      target.pathname = `${target.pathname.slice(0, slash + 1)}agent-learning-history-v317.php`;
    }
    target.search = '';
    return target.toString();
  }

  function injectCss() {
    if (document.querySelector('link[data-brain-learning-history-v317]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = new URL('chat-brain-learning-history-v317.css?v=317-20260907', window.location.href).toString();
    link.dataset.brainLearningHistoryV317 = '1';
    document.head.appendChild(link);
  }

  function outcomeClass(outcome) {
    if (['successful','resolved'].includes(outcome)) return 'positive';
    if (['unsuccessful','ignored'].includes(outcome)) return 'negative';
    return 'open';
  }

  function detail(row) {
    const parts = [];
    if (row.automatic) parts.push('Automatic closure');
    if (row.conversion_event_name) parts.push(`Verified conversion: ${row.conversion_event_name}`);
    if (row.task_status) parts.push(`Task: ${String(row.task_status).replaceAll('_',' ')}`);
    if (row.trigger && row.trigger !== 'first_party_agent_conversion') parts.push(String(row.trigger).replaceAll('_',' '));
    return parts.join(' · ');
  }

  function rowView(row) {
    const outcome = String(row?.outcome || 'awaiting');
    const before = factor(row?.factor_before);
    const after = factor(row?.factor_after);
    const source = row?.source_label || sourceLabel(row?.source);
    const open = safeInternalUrl(row?.source_url);
    const shown = row?.shown_at ? `Surfaced ${relative(row.shown_at)}${row.shown_surface ? ` in ${esc(String(row.shown_surface).replaceAll('_',' '))}` : ''}` : 'Exposure not available in recent ledger window';
    const outcomeTime = row?.outcome_at ? relative(row.outcome_at) : '';
    const score = row?.score_at_surface === null || row?.score_at_surface === undefined
      ? ''
      : ` · surfaced score ${Math.round(Math.max(0, Math.min(1, Number(row.score_at_surface))) * 100)}%`;
    const extra = detail(row);
    return `<article class="chat-learning-item ${outcomeClass(outcome)}">
      <header>
        <div><strong>${esc(row?.title || 'Agent Brain recommendation')}</strong><small>${esc(source)}${esc(score)}</small></div>
        <span>${esc(label(outcome))}</span>
      </header>
      <div class="chat-learning-audit-line"><span>${esc(shown)}</span>${outcomeTime ? `<span>Outcome ${esc(outcomeTime)}</span>` : '<span>Waiting for final result</span>'}</div>
      ${extra ? `<p>${esc(extra)}</p>` : ''}
      <footer>
        <div class="chat-learning-factor"><small>Learned weighting</small><strong>${before.toFixed(2)}× → ${after.toFixed(2)}×</strong><em>${esc(factorDelta(row?.factor_delta))}</em></div>
        ${open ? `<a href="${esc(open)}">Open evidence</a>` : ''}
      </footer>
    </article>`;
  }

  function sourceView(source) {
    const value = factor(source?.factor);
    const tone = value > 1.001 ? 'positive' : value < 0.999 ? 'negative' : 'neutral';
    const evidence = Number(source?.successful || 0) + Number(source?.resolved || 0) + Number(source?.unsuccessful || 0) + Number(source?.ignored || 0) + Number(source?.acted_unresolved || 0);
    return `<article class="chat-learning-source ${tone}">
      <div><strong>${esc(sourceLabel(source?.source))}</strong><small>${evidence} learning signal${evidence === 1 ? '' : 's'}</small></div>
      <span>${value.toFixed(2)}×</span>
    </article>`;
  }

  function render(data) {
    if (!body || !active) return;
    const learning = data?.learning || {};
    const summary = learning.summary || {};
    const rows = Array.isArray(learning.rows) ? learning.rows : [];
    const sources = Array.isArray(summary.sources) ? summary.sources : [];
    body.innerHTML = `<div data-brain-learning-view>
      <section class="chat-activity-section chat-learning-overview">
        <div class="chat-activity-section-head"><div><strong>Brain Learning History</strong><span>What Agent Brain surfaced, what happened afterward, and how each outcome changed future source weighting.</span></div></div>
        <div class="chat-learning-metrics">
          <article><strong>${Number(summary.learned || 0)}</strong><span>Learned outcomes</span></article>
          <article><strong>${Number(summary.successful || 0) + Number(summary.resolved || 0)}</strong><span>Positive results</span></article>
          <article><strong>${Number(summary.unsuccessful || 0) + Number(summary.ignored || 0)}</strong><span>Negative / ignored</span></article>
          <article><strong>${Number(summary.open || 0)}</strong><span>Awaiting outcome</span></article>
        </div>
      </section>
      <section class="chat-activity-section">
        <div class="chat-activity-section-head"><div><strong>Current Source Weights</strong><span>1.00× is neutral. The existing Brain learner raises or lowers source trust from recorded outcomes.</span></div></div>
        <div class="chat-learning-sources">${sources.length ? sources.map(sourceView).join('') : '<div class="chat-activity-empty">No learned source weighting yet.</div>'}</div>
      </section>
      <section class="chat-activity-section">
        <div class="chat-activity-section-head"><div><strong>Recommendation Audit</strong><span>Newest learning cycles first. Open recommendations remain visible until a final result is known.</span></div></div>
        <div class="chat-learning-list">${rows.length ? rows.map(rowView).join('') : '<div class="chat-activity-empty">No Agent Brain recommendation history yet.</div>'}</div>
      </section>
    </div>`;
  }

  async function load(force = false) {
    if (!active || loading) return;
    if (cache && !force) {
      render(cache);
      return;
    }
    loading = true;
    if (body) body.innerHTML = '<div class="chat-activity-loading" data-brain-learning-view>Loading Brain learning history…</div>';
    try {
      const response = await fetch(endpoint(), {credentials:'same-origin', cache:'no-store'});
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok) throw new Error(data?.error || 'Unable to load Brain learning history.');
      cache = data;
      render(data);
    } catch (error) {
      if (body && active) body.innerHTML = `<div class="chat-activity-empty error" data-brain-learning-view>${esc(error instanceof Error ? error.message : 'Brain learning history is unavailable.')}</div>`;
    } finally {
      loading = false;
    }
  }

  function setup(target) {
    if (!(target instanceof Element) || target.dataset.brainLearningSetup === '1') return;
    const tabs = target.querySelector('.chat-notification-tabs');
    body = target.querySelector('[data-notification-drawer-body]');
    if (!tabs || !body) return;
    target.dataset.brainLearningSetup = '1';
    drawer = target;
    tab = document.createElement('button');
    tab.type = 'button';
    tab.dataset.notificationTab = 'learning';
    tab.textContent = 'Brain Learning';
    const historyTab = tabs.querySelector('[data-notification-tab="history"]');
    tabs.insertBefore(tab, historyTab || null);

    target.addEventListener('click', event => {
      const clicked = event.target.closest('[data-notification-tab]');
      if (!clicked) return;
      if (clicked.dataset.notificationTab === 'learning') {
        active = true;
        void load(true);
      } else {
        active = false;
      }
    });

    bodyObserver = new MutationObserver(() => {
      if (!active || body.querySelector('[data-brain-learning-view]')) return;
      queueMicrotask(() => {
        if (active && !body.querySelector('[data-brain-learning-view]')) {
          if (cache) render(cache);
          else void load(false);
        }
      });
    });
    bodyObserver.observe(body, {childList:true, subtree:false});
  }

  injectCss();
  const existing = document.getElementById('chatNotificationDrawer');
  if (existing) setup(existing);
  const observer = new MutationObserver(records => {
    for (const record of records) {
      for (const node of record.addedNodes) {
        if (!(node instanceof Element)) continue;
        if (node.id === 'chatNotificationDrawer') setup(node);
        else {
          const found = node.querySelector?.('#chatNotificationDrawer');
          if (found) setup(found);
        }
      }
    }
  });
  observer.observe(document.documentElement, {childList:true, subtree:true});
  window.addEventListener('pagehide', () => {
    observer.disconnect();
    bodyObserver?.disconnect();
  }, {once:true});
})();
