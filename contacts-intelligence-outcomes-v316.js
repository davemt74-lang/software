(() => {
  'use strict';

  if (!document.body.classList.contains('contacts-page')) return;
  const cfg = window.VP3_CONTACTS || {};
  const agents = cfg.agents && typeof cfg.agents === 'object' ? cfg.agents : {};
  const modal = document.getElementById('agentRelationshipModal');
  const body = document.getElementById('agentRelationshipBody');
  if (!modal || !body) return;

  let activeAgentId = 0;
  let decorateQueued = false;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
  }[char]));

  function dateLabel(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const date = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return raw;
    return new Intl.DateTimeFormat(undefined, {
      month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit'
    }).format(date);
  }

  function learnedOutcomes(agent) {
    return (Array.isArray(agent?.recent_activity) ? agent.recent_activity : [])
      .filter(item => String(item?.event_type || '') === 'agent_brain_outcome_learned')
      .slice(0, 4);
  }

  function intelligenceTabActive() {
    return document.querySelector('[data-agent-detail-tab="intelligence"].active') !== null;
  }

  function decorateIntelligence() {
    decorateQueued = false;
    body.querySelector('[data-agent-brain-learning]')?.remove();
    if (modal.hidden || !intelligenceTabActive() || activeAgentId < 1) return;

    const agent = agents[String(activeAgentId)];
    if (!agent) return;
    const outcomes = learnedOutcomes(agent);
    if (!outcomes.length) return;

    const cards = outcomes.map(item => `
      <article class="contacts-activity-card">
        <div><strong>Verified conversion outcome</strong><span class="contacts-detail-pill">Learned</span></div>
        <p>${esc(item.summary || 'Agent Brain learned from a verified Agent Radar outcome.')}</p>
        <footer><span>Source: Agent Radar</span><time>${esc(dateLabel(item.occurred_at))}</time></footer>
      </article>`).join('');

    body.insertAdjacentHTML('beforeend', `
      <section class="contacts-detail-section" data-agent-brain-learning>
        <div class="contacts-detail-section-head">
          <div><span>Agent Brain learning</span><strong>Verified relationship outcomes</strong><small>Closed from canonical Agent Radar evidence, not inferred intent.</small></div>
          <span class="contacts-detail-pill">${outcomes.length} learned</span>
        </div>
        <div class="contacts-activity-list">${cards}</div>
      </section>`);
  }

  function queueDecorate() {
    if (decorateQueued) return;
    decorateQueued = true;
    queueMicrotask(() => requestAnimationFrame(decorateIntelligence));
  }

  document.addEventListener('click', event => {
    const opener = event.target.closest('[data-agent-detail-open]');
    if (opener) {
      activeAgentId = Math.max(0, Number(opener.dataset.agentDetailOpen || 0));
      return;
    }

    const tab = event.target.closest('[data-agent-detail-tab]');
    if (tab?.dataset.agentDetailTab === 'intelligence') queueDecorate();
  });

  // Preserve the enhancement if another relationship renderer refreshes the
  // modal body while Intelligence remains the selected tab.
  const observer = new MutationObserver(() => {
    if (intelligenceTabActive() && !body.querySelector('[data-agent-brain-learning]')) queueDecorate();
  });
  observer.observe(body, {childList:true});
  window.addEventListener('pagehide', () => observer.disconnect(), {once:true});
})();
