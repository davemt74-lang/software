(() => {
  'use strict';
  if (window.__VP3_COGNITIVE_PRESENTATION_V510__) return;
  window.__VP3_COGNITIVE_PRESENTATION_V510__ = true;

  const cfg = window.VP3_COGNITIVE_PRESENTATION_V510 || {};
  const pageBinding = String(cfg.workingAttentionBinding || '');
  const pageAgent = Number(cfg.agentId || 0);
  const pageChatOwner = window.STONEFELLOW_CHAT ? Number(window.STONEFELLOW_CHAT.userId || 0) : null;
  const button = document.getElementById('chatAgentBriefButton');
  const popup = document.getElementById('chatAgentBriefPopover');
  const content = popup ? popup.querySelector('[data-agent-brief-content]') : null;
  const statusDot = button ? button.querySelector('[data-agent-status-dot]') : null;
  const badge = button ? button.querySelector('[data-agent-attention-badge]') : null;
  const thread = document.getElementById('chatThread');
  const form = document.getElementById('chatForm');
  const input = document.getElementById('chatInput');
  const liveHost = cfg.liveContext === true ? document.getElementById('chatLiveContext') : null;
  // The orb opens Top of Mind; the optional legacy launcher must not gate its runtime.
  if (!popup || !content || !thread || !form) return;

  let state = null;
  let briefEpoch = 0;
  let participantActor = 0;
  const participantContext = () => participantActor > 0 || Boolean(window.VP3ParticipantContinuity?.selectedContext?.());
  let pageController = new AbortController();
  let readController = null;
  let accountError = null;
  let timer = 0;
  let profileAttentionTimer = 0;
  function refreshProfileAttention() {
    if (state && !pageController.signal.aborted && !document.hidden) void window.STONEFELLOW_NOTIFICATION_CENTER?.pollProfileAttention?.();
  }
  let busy = false;
  let workingAttentionView = 0;
  let liveView = 0;
  let liveState = null;
  let explicitlySelectedReference = null;
  let liveAgentName = '';
  let liveMarkup = '';
  let openingKey = '';
  const liveSeen = new Set();
  const liveDismissed = new Set();
  let lastVoiceThrough = 0;
  let activeVoiceThrough = 0;
  let githubVoiceBusy = false;
  let githubVoiceEpoch = 0;
  let githubVoiceEnabled = window.STONEFELLOW_CHAT?.agentVoiceEnabled !== false;
  let suppressedVoiceThrough = 0;
  let initiativeController = null;
  let initiativeTimer = 0;
  let initiativeBusy = false;
  let initiativeSettingBusy = false;
  let initiativeLastTick = 0;
  let initiativeError = '';
  let initiativeHealthError = '';
  let initiativeRetry = null;
  let initiativeDebug = null;
  let initiativeDebugKeys = 0;
  let initiativeDebugKeyAt = 0;
  const initiativeSupersededRevisions = new Set();
  const greetingReasons = {
    agent_busy:'Your Agent is working.',
    source_unavailable:'A source couldn’t be checked.',
    recent_interaction:'You were here recently.',
    no_candidate:'No current update stood out.',
    linked_meeting_followup:'An upcoming meeting has an open follow-up.'
  };

  const esc = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[char]));

  function endpoint(action) {
    const url = new URL(String(cfg.endpoint || '/api/cognitive-presentation-v510.php'), window.location.origin);
    url.searchParams.set('action', action);
    url.searchParams.set('agent_id', String(Number(cfg.agentId || 0)));
    return url;
  }

  function configurationMatches() {
    const current = window.VP3_COGNITIVE_PRESENTATION_V510 || {};
    const chat = window.STONEFELLOW_CHAT;
    return Boolean(pageBinding) && String(current.workingAttentionBinding || '') === pageBinding
      && Number(current.agentId || 0) === pageAgent
      && ((pageChatOwner === null && !chat) || (chat && String(chat.workingAttentionBinding || '') === pageBinding
        && Number(chat.agentId || 0) === pageAgent
        && (pageChatOwner === null || Number(chat.userId || 0) === pageChatOwner)));
  }

  function requireCurrentConfiguration() {
    if (configurationMatches()) return true;
    invalidateAccount(new Error('account_changed'));
    return false;
  }

  async function presentationResponse(response, signal, allowTickOutcome = false) {
    const data = await response.json().catch(() => ({}));
    if (signal.aborted) throw new DOMException('Agent status request cancelled.', 'AbortError');
    if (!requireCurrentConfiguration()) throw new Error('account_changed');
    const error = new Error(data.error || 'Cognitive presentation is unavailable.');
    if (response.status === 401 || response.status === 403 || error.message === 'account_changed') invalidateAccount(error);
    const tickOutcome = allowTickOutcome && data.ok === false && typeof data.reason === 'string' && data.health;
    if (!response.ok || (!data.ok && !tickOutcome)) throw error;
    return data;
  }

  async function getState(includeWorkingAttention, conversationId) {
    const controller = new AbortController();
    readController = controller;
    const timeout = window.setTimeout(() => controller.abort(), 10000);
    const url = endpoint('state');
    url.searchParams.set('include_working_attention', includeWorkingAttention ? '1' : '0');
    // Top of Mind owns briefs; conversation reads do not create legacy away cards.
    url.searchParams.set('include_return_digest','0');
    if (includeWorkingAttention && cfg.greetingPreview === true) url.searchParams.set('greeting_preview','1');
    if (conversationId !== null) {
      url.searchParams.set('include_live_context','1');
      url.searchParams.set('conversation_id',String(conversationId));
      if (cfg.initiativeRuntime === true) url.searchParams.set('include_initiative_runtime','1');
    }
    try {
      const response = await fetch(url.toString(), {
        credentials:'same-origin',
        cache:'no-store',
        headers:{Accept:'application/json','X-VP3-Working-Attention':pageBinding},
        signal:controller.signal
      });
      const data = await presentationResponse(response, controller.signal);
      return data.state || {};
    } catch (error) {
      if (error && error.name === 'AbortError') throw new Error('Agent status request timed out.');
      throw error;
    } finally {
      window.clearTimeout(timeout);
      if (readController === controller) readController = null;
    }
  }

  async function post(action, payload, signal = pageController.signal) {
    if (signal.aborted || !requireCurrentConfiguration()) return;
    const response = await fetch(String(cfg.endpoint || '/api/cognitive-presentation-v510.php'), {
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      headers:{'Content-Type':'application/json','Accept':'application/json','X-VP3-Working-Attention':pageBinding},
      signal,
      body:JSON.stringify(Object.assign({
        action:action,
        agent_id:Number(cfg.agentId || 0),
        csrf_token:String(cfg.csrf || '')
      }, payload || {}))
    });
    return presentationResponse(response, signal, action === 'initiative_tick');
  }

  function clearWorkingAttention() {
    workingAttentionView += 1;
    if (state?.brief) {
      delete state.brief.working_attention;
      delete state.brief.greeting_preview;
    }
    content.querySelectorAll('[data-agent-brief-working-attention],[data-agent-greeting-preview]').forEach(node => node.remove());
  }


  function liveConversation() {
    if (participantContext() || !liveHost || document.hidden || thread.dataset.view !== 'chat' || !requireCurrentConfiguration()) return null;
    const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    if (!chat?.isReady?.() || chat.isRestoring?.()) return null;
    const agent = Number(chat.agentId?.() ?? cfg.agentId ?? 0);
    const id = Number(chat.conversationId?.());
    return agent === Number(cfg.agentId || 0) && Number.isSafeInteger(id) && id > 0 ? id : null;
  }

  function initiativeNewChat() {
    if (participantContext() || cfg.initiativeRuntime !== true || !liveHost || document.hidden || thread.dataset.view !== 'chat'
      || !requireCurrentConfiguration()) return false;
    const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    return chat?.isReady?.() === true && !chat.isRestoring?.() && Number(chat.conversationId?.()) === 0
      && Number(chat.agentId?.() ?? pageAgent) === pageAgent;
  }

  function showAwaitingInitiativeHealth() {
    if (cfg.initiativeRuntime === true && !liveState && liveConversation() !== null) {
      renderLiveContext({state:'awaiting_runtime'});
    }
  }

  function clearLiveContext() {
    initiativeController?.abort();
    initiativeError = '';
    initiativeHealthError = '';
    initiativeRetry = null;
    liveView++;
    liveState = null;
    openingKey = '';
    updateOrbInitiativeStatus();
    content.querySelector('[data-agent-live-status]')?.remove();
    if (!liveHost) return;
    liveMarkup = '';
    liveHost.hidden = true;
    liveHost.replaceChildren();
  }

  const liveMemoryPrefix = 'vp3:live-context:v1:';

  function liveMemory(bucket, seen, key, add = false) {
    if (add) seen.add(key);
    if (seen.has(key) && !add) return true;
    const name = liveMemoryPrefix + bucket;
    try {
      const stored = JSON.parse(localStorage.getItem(name) || '[]');
      const entries = Array.isArray(stored) ? stored.slice(0,32) : [];
      if (add) localStorage.setItem(name, JSON.stringify([key,...entries.filter(value => value !== key)].slice(0,32)));
      return add || entries.includes(key);
    } catch (_error) {
      return seen.has(key);
    }
  }

  function initiativeLabel(initiative) {
    switch (initiative?.state) {
      case 'off': return 'Initiative is off.';
      case 'linked_elsewhere': return 'Initiative is linked to another Chat.';
      case 'waiting_for_review': return 'Initiative is on · waiting for a review.';
      case 'reviewing': return 'Initiative is reviewing its sources.';
      case 'checked': return 'Initiative checked its sources.';
      case 'failed': return 'Initiative could not verify its last review.';
      case 'ready': return 'Initiative has a private draft ready.';
      case 'unavailable': return 'Initiative status is unavailable.';
      default: return '';
    }
  }

  function initiativeScopeLabel(scope) {
    if (scope?.mode === 'all') return 'all personal Knowledge';
    if (scope?.mode === 'folder' && Number.isSafeInteger(Number(scope.folder_id)) && Number(scope.folder_id) > 0) {
      return 'the selected Knowledge folder';
    }
    return 'no personal Knowledge';
  }

  function initiativeHealth(health, conversationId = liveConversation()) {
    if (arguments.length === 0) health = liveState?.initiative?.runtime;
    return health && Number(health.agent_id) === pageAgent && Number(health.conversation_id) === conversationId
      && typeof health.enabled === 'boolean' && typeof health.state === 'string' && typeof health.reason === 'string'
      && typeof health.policy_revision === 'string'
      && (!(health.enabled || health.paused === true || health.state === 'paused') || /^[a-f0-9]{32}$/.test(health.policy_revision))
      ? health : null;
  }

  function rememberInitiativeRevision(previous, next) {
    const prior = String(previous?.policy_revision || ''), current = String(next?.policy_revision || '');
    if (/^[a-f0-9]{32}$/.test(prior) && /^[a-f0-9]{32}$/.test(current) && prior !== current) {
      initiativeSupersededRevisions.add(prior);
      if (initiativeSupersededRevisions.size > 64) initiativeSupersededRevisions.delete(initiativeSupersededRevisions.values().next().value);
    }
  }

  function initiativeRuntimeLabel(health) {
    if (initiativeError) return initiativeError;
    if ((!health || health.state === 'starting') && initiativeHealthError) return initiativeHealthError;
    if (!health) return 'Waiting for the Agent’s first status check.';
    if (initiativeSettingBusy) return 'Saving the background preparation setting.';
    if (initiativeBusy) return health?.state === 'running'
      ? 'The Agent is advancing background work.' : 'Checking for permitted background work.';
    switch (health?.policy_reason) {
      case 'service_disabled': return 'Initiative is disabled in AI service settings.';
      case 'background_disabled': return 'Background Initiative is disabled in AI service settings.';
      case 'service_budget_exhausted': return 'The Initiative service token limit blocks background work.';
      case 'service_model_forbidden': return 'The Initiative service has no permitted model.';
      case 'service_policy_unavailable': return 'Initiative could not read its AI service settings.';
    }
    switch (health?.reason) {
      case 'automatic_startup': return 'The Agent is connecting this Chat.';
      case 'initiative_paused': return 'Agent background work is paused.';
      case 'initiative_off': return /^[a-f0-9]{32}$/.test(String(health?.policy_revision || ''))
        ? 'Agent background work is paused.' : 'The Agent is connecting this Chat.';
      case 'linked_elsewhere': return 'The Agent is following your active Chat.';
      case 'context_unavailable': return 'The Agent could not confirm access to this saved Chat.';
      case 'runtime_unavailable': return 'The Agent’s background runtime could not be checked.';
      case 'storage_unavailable': return 'Background preparation needs the current database upgrade.';
      case 'quota_exhausted': return 'Background preparation is paused because the AI allowance is exhausted.';
      case 'model_unavailable': return 'The configured AI model is unavailable for background preparation.';
      case 'route_unavailable': return 'The configured AI route cannot run background preparation.';
      case 'worker_missing': return 'No background worker has been observed. This open Chat can advance permitted work.';
      case 'worker_stale': return 'The background worker has not checked in recently. This open Chat can advance permitted work.';
      case 'worker_failed': return 'The last background worker sweep failed. This open Chat can retry permitted work.';
      case 'worker_observation_unavailable': return 'Background worker status could not be read. This open Chat can advance permitted work.';
      case 'assessment_sources_changed': return 'Sources changed during the assessment. The Agent will check again in its next review.';
      case 'assessment_unverified': return 'The last assessment did not produce a verified plan.';
      case 'review_failed': return 'The last background action could not be verified.';
      case 'worker_interrupted': return 'Background work was interrupted and is waiting for recovery.';
      case 'retry_scheduled': return 'The AI service was interrupted. A bounded retry is scheduled.';
      case 'reviewing': return 'The Agent is reviewing current sources.';
      case 'checked': return 'The Agent checked the current sources.';
      default:
        if (health?.state === 'off') return /^[a-f0-9]{32}$/.test(String(health?.policy_revision || ''))
          ? 'Agent background work is paused.' : 'The Agent is connecting this Chat.';
        if (health?.state === 'starting') return 'The Agent is connecting this Chat.';
        if (health?.state === 'paused') return 'Agent background work is paused.';
        if (health?.state === 'running') return 'The Agent is reviewing current sources.';
        if (health?.state === 'checked') return 'The Agent checked the current sources.';
        if (health?.state === 'waiting') return 'Background preparation is waiting for its next review.';
        return 'The Agent’s status could not be verified for this Chat.';
    }
  }

  function initiativeRuntimeStatus() {
    if (cfg.initiativeRuntime !== true || !liveState) return null;
    if (liveState.state === 'new_chat') {
      return {label:'The Agent can continue useful work after your first message saves this Chat.'};
    }
    const health = initiativeHealth();
    const label = initiativeRuntimeLabel(health);
    const enabled = health?.enabled === true;
    const linked = health?.reason === 'linked_elsewhere' || health?.state === 'linked_elsewhere';
    const paused = (health?.paused === true || ['off','paused'].includes(health?.state))
      && /^[a-f0-9]{32}$/.test(String(health.policy_revision || ''));
    const canChange = health && (enabled && !linked || paused) && health.configuration_ready !== false
      && health.reason !== 'storage_unavailable';
    const selected = health?.knowledge_scope;
    const knowledge = !health || !enabled && !paused ? '' : (enabled && !linked ? 'Uses ' : 'When resumed, uses ')
      + initiativeScopeLabel(selected) + ' for permitted private work.';
    const verifiedAt = Date.parse(health?.last_execution_at || health?.last_checked_at || '');
    const verified = Number.isFinite(verifiedAt) ? (health?.last_execution_at ? ' Latest completed step: ' : ' Sources last checked: ')
      + new Date(verifiedAt).toLocaleString() + '.' : '';
    const assessment = health?.last_assessment;
    const decision = assessment?.outcome === 'idle' ? ' The last assessment selected no new work.'
      : assessment?.outcome === 'existing_assignments' ? ' The last assessment found work already assigned.'
      : assessment?.outcome === 'assignments_queued' && Number.isSafeInteger(assessment.queued) && assessment.queued > 0
        ? ' The last assessment queued ' + assessment.queued + (assessment.queued === 1 ? ' item.' : ' items.') : '';
    const worker = enabled && health?.runner_state === 'never_seen' && health?.reason !== 'worker_missing'
      ? ' No server worker check-in is recorded.' : '';
    const connectionWarning = health && health.state !== 'starting' && initiativeHealthError ? ' ' + initiativeHealthError : '';
    return {
      label, detail:(knowledge + verified + decision + worker + connectionWarning).trim(),
      setting:canChange ? (enabled && !linked ? 'off' : 'on') : '',
      settingBusy:initiativeSettingBusy,
      retry:Boolean(initiativeHealthError) || health?.state === 'unavailable',
      retryBusy:Boolean(initiativeRetry)
    };
  }

  const initiativeDebugValues = {
    state: ['unavailable','starting','off','paused','linked_elsewhere','blocked','waiting','checked','running','failed'],
    reason: ['automatic_startup','initiative_paused','initiative_off','linked_elsewhere','context_unavailable',
      'runtime_unavailable','storage_unavailable','quota_exhausted','model_unavailable','route_unavailable','worker_missing',
      'worker_stale','worker_failed','worker_observation_unavailable','assessment_sources_changed','assessment_unverified',
      'review_failed','worker_interrupted','retry_scheduled','reviewing','checked','waiting_for_review'],
    policy_reason: ['service_disabled','background_disabled','service_budget_exhausted','service_model_forbidden','service_policy_unavailable'],
    route: ['none','vp3_cloud','homeserver'],
    runner_state: ['never_seen','fresh','stale','failed','unknown'],
    outcome: ['idle','existing_assignments','assignments_queued'],
    rejection: ['output_too_large','invalid_json','invalid_envelope','invalid_job','invalid_job_sources','invalid_opportunities']
  };

  function initiativeDebugReport(health) {
    if (!health) return 'Waiting for verified status for this Chat.';
    const known = (field, value) => initiativeDebugValues[field].includes(value) ? value : '—';
    const time = value => {
      if (typeof value !== 'string' || !/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{3})?(?:Z|[+-]\d\d:\d\d)$/.test(value)) return '—';
      const date = new Date(value);
      return Number.isFinite(date.getTime()) ? date.toLocaleString(undefined,{dateStyle:'medium',timeStyle:'long'}) : '—';
    };
    const rows = [
      ['State',known('state',health.state)],
      ['Reason',known('reason',health.reason)],
      ['Initiative',health.enabled ? 'On' : 'Off'],
      ['Route',known('route',health.route)],
      ['Execution ready',health.execution_ready === true ? 'Yes' : 'No'],
      ['Server worker',known('runner_state',health.runner_state)],
      ['Last worker check-in',time(health.runner_last_success_at)],
      ['Sources last checked',time(health.last_checked_at)],
      ['Last execution',time(health.last_execution_at)]
    ];
    if (initiativeDebugValues.policy_reason.includes(health.policy_reason)) rows.push(['AI service policy',health.policy_reason]);
    const retryAt = time(health.retry_due_at);
    if (retryAt !== '—') rows.push(['Retry due',retryAt]);
    const assessment = health.last_assessment;
    if (initiativeDebugValues.outcome.includes(assessment?.outcome)) {
      rows.push(['Last verified assessment',assessment.outcome],['Plan verified at',time(assessment.at)]);
      if (Number.isSafeInteger(assessment.queued) && assessment.queued >= 0 && assessment.queued <= 8) {
        rows.push(['Jobs queued',assessment.queued]);
      }
    }
    const rejection = health.assessment_error;
    if (initiativeDebugValues.rejection.includes(rejection?.code)) {
      rows.push(['Assessment rejection',rejection.code],['Plan rejected at',time(rejection.at)]);
      if (Number.isSafeInteger(rejection.output_bytes) && rejection.output_bytes >= 0 && rejection.output_bytes <= 65536) {
        rows.push(['Planner response bytes',rejection.output_bytes]);
      }
    }
    return rows.map(([label,value]) => `${label}: ${value}`).join('\n');
  }

  function closeInitiativeDebug(restoreFocus = false) {
    initiativeDebugKeys = 0;
    const current = initiativeDebug;
    initiativeDebug = null;
    current?.panel.remove();
    if (restoreFocus && current?.returnFocus?.isConnected) current.returnFocus.focus({preventScroll:true});
  }

  function updateInitiativeDebug() {
    const current = initiativeDebug;
    if (!current) return false;
    if (initiativeDebugConversation() !== current.conversationId || initiativeDebug !== current) {
      closeInitiativeDebug();
      return false;
    }
    const text = current.conversationId === 0 ? 'Send a message to save this Chat and start background work.'
      : initiativeDebugReport(initiativeHealth());
    if (current.report.textContent !== text) current.report.textContent = text;
    return true;
  }

  function initiativeDebugConversation() {
    if (cfg.initiativeRuntime !== true || !pageChatOwner || pageController.signal.aborted) return null;
    return liveConversation() ?? (initiativeNewChat() ? 0 : null);
  }

  function openInitiativeDebug(conversationId) {
    const panel = document.createElement('section');
    panel.className = 'chat-initiative-debug';
    panel.setAttribute('aria-labelledby','chatInitiativeDebugTitle');
    panel.innerHTML = '<header><h2 id="chatInitiativeDebugTitle">Initiative Debug</h2><div>' +
      '<button type="button" data-initiative-debug-copy>Copy</button>' +
      '<button type="button" data-initiative-debug-close>Close</button></div></header>' +
      '<pre data-initiative-debug-report></pre><p role="status" data-initiative-debug-copy-status></p>';
    const copy = panel.querySelector('[data-initiative-debug-copy]');
    const status = panel.querySelector('[data-initiative-debug-copy-status]');
    const current = {panel,conversationId,report:panel.querySelector('pre'),returnFocus:document.activeElement};
    initiativeDebug = current;
    if (!updateInitiativeDebug()) return;
    copy.addEventListener('click', async () => {
      if (!updateInitiativeDebug()) return;
      try {
        await navigator.clipboard.writeText(`Initiative Debug\n${current.report.textContent}`);
        if (initiativeDebug === current) status.textContent = 'Copied.';
      } catch (_error) {
        if (initiativeDebug !== current) return;
        window.getSelection()?.selectAllChildren(current.report);
        status.textContent = 'Copy the selected report.';
      }
    });
    panel.querySelector('[data-initiative-debug-close]').addEventListener('click', () => closeInitiativeDebug(true));
    document.body.append(panel);
    copy.focus({preventScroll:true});
  }

  function initiativeDebugShortcut(event) {
    if (cfg.initiativeRuntime !== true || !pageChatOwner || pageController.signal.aborted) return;
    if (event.key !== 'z' || event.defaultPrevented || event.repeat || event.isComposing || event.ctrlKey || event.metaKey ||
        event.altKey || event.shiftKey || event.composedPath().some(node => node instanceof Element &&
        (node.isContentEditable || node.matches('input, textarea, select, [role="textbox"]')))) {
      initiativeDebugKeys = 0;
      return;
    }
    const conversationId = initiativeDebugConversation();
    if (conversationId === null) {
      initiativeDebugKeys = 0;
      return;
    }
    const now = performance.now();
    initiativeDebugKeys = now - initiativeDebugKeyAt <= 750 ? initiativeDebugKeys + 1 : 1;
    initiativeDebugKeyAt = now;
    if (initiativeDebugKeys < 3) return;
    event.preventDefault();
    initiativeDebugKeys = 0;
    if (initiativeDebug) closeInitiativeDebug(true);
    else openInitiativeDebug(conversationId);
  }

  function useOrbInitiativeAction(action) {
    if (liveConversation() === null || !liveState) return;
    const view = initiativeRuntimeStatus();
    if (action === 'retry' && view?.retry) void retryInitiativeHealth();
    else if (['on','off'].includes(action) && view?.setting === action) void changeInitiative(action === 'on');
  }

  function updateOrbInitiativeStatus() {
    updateInitiativeDebug();
    const orb = window.VP3AgentOrb;
    orb?.setInitiativeStatus?.(initiativeRuntimeStatus(), useOrbInitiativeAction);
    if (!orb?.setJobs) return;
    const health = cfg.initiativeRuntime === true && liveState && !initiativeHealthError && !initiativeSettingBusy
      && !window.VP3ParticipantContinuity?.selectedContext?.()
      ? initiativeHealth() : null;
    const now = Date.now();
    const jobs = health?.enabled === true && Array.isArray(health.active_jobs) ? health.active_jobs.slice(0,25)
      .filter(job => typeof job?.id === 'string' && /^workflow:[1-9]\d{0,19}$/.test(job.id)
        && Number.isSafeInteger(job.expiresAt) && job.expiresAt > now)
      .map(({id,expiresAt,kind,step}) => ({id,expiresAt,
        ...(['assessment','preparation'].includes(kind) ? {kind} : {}),
        ...(typeof step === 'string' ? {step:step.slice(0,120)} : {})})) : [];
    orb.setJobs('initiative', jobs);
  }

  function liveSourceLink(item) {
    const href = String(item?.href || '');
    if (!href.startsWith('/') || href.startsWith('//') || href.includes('\\') || !item.action_label) return '';
    try {
      const url = new URL(href, location.origin);
      return url.origin === location.origin && !url.username && !url.password
        ? '<a href="' + esc(url.pathname + url.search + url.hash) + '">' + esc(item.action_label) + '</a>' : '';
    } catch (_error) {
      return '';
    }
  }

  function liveStatusMarkup() {
    if (!liveState) return '';
    const initiative = initiativeLabel(liveState.initiative);
    const message = liveState.state === 'quiet' ? 'Nothing pressing in the sources checked.' :
      liveState.state === 'unavailable' ? 'Current sources could not be checked.' : '';
    if (!initiative && !message) return '';
    const checkedAt = Date.parse(liveState.initiative?.last_checked_at || '');
    const checked = Number.isFinite(checkedAt) ? ' Sources last checked ' + new Date(checkedAt).toLocaleString() + '.' : '';
    return '<div class="chat-agent-brief-card" data-agent-live-status><small>Top of mind</small>' +
      (message ? '<p>' + esc(message) + '</p>' : '') +
      (initiative ? '<p data-agent-live-initiative>' + esc(initiative + checked) + '</p>' : '') + '</div>';
  }

  function renderLiveContext(context, agentName = liveAgentName) {
    if (!liveHost) return;
    liveState = context || null;
    liveAgentName = String(agentName || 'Agent');
    updateOrbInitiativeStatus();
    let markup = '';
    const item = liveState?.attention;
    const key = String(item?.key || '');
    const isBusy = window.STONEFELLOW_CHAT_CONTINUITY?.isBusy?.() ||
      ['processing','speaking'].includes(runtimeState());
    if (item?.title && /^[a-f0-9]{64}$/.test(key) && !liveMemory('dismissed',liveDismissed,key) && !isBusy) {
      const canOpen = liveState.opening === true && runtimeState() !== 'listening' && !input?.value.trim();
      if (canOpen && openingKey !== key && !liveMemory('seen',liveSeen,key)) {
        openingKey = key;
        liveMemory('seen',liveSeen,key,true);
      } else if (!canOpen || openingKey !== key) {
        openingKey = '';
      }
      const heading = openingKey === key ? 'Agent check-in' : 'Top of mind';
      markup = '<article class="message assistant chat-live-context" data-agent-live-attention>' +
        '<div class="message-avatar" aria-hidden="true">' + esc(liveAgentName.slice(0,1)) + '</div>' +
        '<div class="message-body"><div class="message-role">' + esc(liveAgentName) + '</div>' +
        '<div class="message-text chat-live-context-copy"><small data-agent-live-heading>' + heading + '</small>' +
        '<strong>' + esc(item.title) + '</strong>' + (item.summary ? '<span>' + esc(item.summary) + '</span>' : '') +
        '</div><div class="chat-live-context-actions">' + liveSourceLink(item) +
        '<button type="button" data-agent-live-ask>Ask Agent</button>' +
        '<button type="button" data-agent-live-dismiss aria-label="Dismiss this update">×</button></div></div></article>';
    } else if (!item) {
      openingKey = '';
    }
    if (markup !== liveMarkup) {
      const nearBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 64;
      liveMarkup = markup;
      liveHost.innerHTML = markup;
      liveHost.hidden = !markup;
      if (markup) {
        thread.append(liveHost);
        if (nearBottom && !input?.value.trim()) thread.scrollTop = thread.scrollHeight;
      }
    }
  }

  function dismissLiveContext() {
    const key = String(liveState?.attention?.key || '');
    if (/^[a-f0-9]{64}$/.test(key)) liveMemory('dismissed',liveDismissed,key,true);
    renderLiveContext(liveState);
  }

  liveHost?.addEventListener('click', event => {
    if (liveConversation() === null || !liveState) return;
    if (event.target.closest('[data-agent-live-dismiss]')) {
      dismissLiveContext();
    }
    if (event.target.closest('[data-agent-live-ask]') && input && !input.disabled) {
      if (!input.value.trim()) {
        input.value = 'Help me with that';
        input.dispatchEvent(new Event('input',{bubbles:true}));
        explicitlySelectedReference = liveState.attention?.key;
        try { form.requestSubmit(); } finally { explicitlySelectedReference = null; }
      } else {
        input.focus();
      }
    }
  });

  function initiativeMayAdvance(conversationId = liveConversation()) {
    // Private queue work may continue during an owner turn. The canonical
    // worker separately verifies whether a result can be delivered to Chat.
    return cfg.initiativeRuntime === true && conversationId !== null && conversationId === liveConversation()
      && !pageController.signal.aborted && requireCurrentConfiguration() && !document.hidden && document.hasFocus();
  }

  function advanceInitiativeVoice() {
    const cid=liveConversation(),voice=window.VP3InitiativeVoice;
    if(!voice?.advance||cid===null||!initiativeMayAdvance(cid)||!Number.isSafeInteger(pageChatOwner)||pageChatOwner<1)return;
    void Promise.resolve(voice.advance({endpoint:cfg.endpoint,csrf:cfg.csrf,userId:pageChatOwner,
      agentId:pageAgent,conversationId:cid,workingAttentionBinding:pageBinding,
      activityTab:window.StonefellowAgentActivity?.activityTab?.()||''})).catch(()=>{});
  }

  async function retryInitiativeHealth() {
    const cid = liveConversation();
    if (initiativeRetry || cid === null || pageController.signal.aborted || !requireCurrentConfiguration()) return;
    const request = {conversationId:cid};
    initiativeRetry = request;
    renderLiveContext(liveState);
    try {
      await Promise.allSettled([
        Promise.resolve(window.StonefellowAgentActivity?.refreshCoreContext?.()),
        refresh(true)
      ]);
    } finally {
      if (initiativeRetry === request) {
        initiativeRetry = null;
        if (cid === liveConversation() && requireCurrentConfiguration()) renderLiveContext(liveState);
      }
    }
  }

  function initiativeMaySync(conversationId = liveConversation()) {
    const canonical = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    return initiativeMayAdvance(conversationId) && !canonical?.isBusy?.() && !window.STONEFELLOW_CHAT_CONTINUITY?.isBusy?.()
      && !['thinking','processing','speaking'].includes(runtimeState()) && !input?.value.trim();
  }

  async function initiativeRequest(action, payload) {
    const controller = new AbortController();
    initiativeController = controller;
    const signal = pageController.signal;
    const cancel = () => controller.abort();
    signal.addEventListener('abort',cancel,{once:true});
    const timeout = window.setTimeout(cancel,action === 'initiative_tick' ? 150000 : 15000);
    try { return await post(action,payload,controller.signal); }
    finally {
      window.clearTimeout(timeout);
      signal.removeEventListener('abort',cancel);
      if (initiativeController === controller) initiativeController = null;
    }
  }

  async function changeInitiative(enabled) {
    const cid = liveConversation();
    if (initiativeSettingBusy || cid === null || pageController.signal.aborted || !requireCurrentConfiguration()) return;
    const view = ++liveView;
    // Pausing must stay available while a tick is in inference. The server
    // rotates the policy revision before that old job can commit its result.
    initiativeController?.abort();
    initiativeSettingBusy = true;
    initiativeError = '';
    renderLiveContext(liveState);
    try {
      const result = await initiativeRequest('initiative_setting',{
        conversation_id:cid,enabled,
        policy_revision:String(initiativeHealth()?.policy_revision || '')
      });
      if (cid !== liveConversation() || view !== liveView || !requireCurrentConfiguration()) return;
      if (initiativeHealth(result?.health,cid) && liveState) {
        rememberInitiativeRevision(initiativeHealth(),result.health);
        liveState.initiative = {...liveState.initiative,runtime:result.health};
      }
      initiativeLastTick = 0;
      window.dispatchEvent(new CustomEvent('vp3:initiative-setting-changed',{detail:{agent_id:pageAgent,conversation_id:cid}}));
    } catch (error) {
      if (cid === liveConversation() && view === liveView && error?.name !== 'AbortError') {
        initiativeError = error?.message === 'initiative_settings_changed'
          ? 'Background settings changed in another tab. Review the current setting and try again.'
          : 'The background setting could not be confirmed. Refresh and try again.';
      }
    } finally {
      initiativeSettingBusy = false;
      if (cid === liveConversation() && view === liveView) renderLiveContext(liveState);
    }
    if (cid === liveConversation() && view === liveView) void refresh();
  }

  async function advanceInitiative() {
    advanceInitiativeVoice();
    const cid = liveConversation(), health = initiativeHealth();
    const revision = String(health?.policy_revision || '');
    if (initiativeBusy || initiativeSettingBusy || !initiativeMayAdvance(cid) || health?.enabled !== true || health?.execution_ready !== true
      || !/^[a-f0-9]{32}$/.test(revision) || Date.now() - initiativeLastTick < 15000) return;
    initiativeBusy = true;
    initiativeLastTick = Date.now();
    initiativeError = '';
    renderLiveContext(liveState);
    try {
      const result = await initiativeRequest('initiative_tick',{conversation_id:cid,policy_revision:revision});
      if (cid !== liveConversation() || initiativeHealth()?.policy_revision !== revision || !requireCurrentConfiguration()) return;
      const health = initiativeHealth(result?.health,cid);
      if (health && liveState) {
        rememberInitiativeRevision(initiativeHealth(),health);
        liveState.initiative = {...liveState.initiative,runtime:health};
      }
      if (result?.ok === false && !['off','blocked','already_running','at_capacity','assessment_sources_changed'].includes(result.reason)
        && health?.reason !== result.reason) {
        initiativeError = 'The last background action could not be verified.';
      }
      // Saved assistant messages use canonical Chat rendering, unread tags and
      // delivery cursors. This UI never manufactures a message or speaks it.
      if (result?.ok === true && initiativeMaySync(cid)) {
        const canonical = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
        await canonical?.syncConversation?.(cid);
      }
    } catch (error) {
      if (cid === liveConversation() && initiativeHealth()?.policy_revision === revision && error?.name !== 'AbortError') {
        initiativeError = 'The background connection was interrupted. The next check will retry.';
      }
    } finally {
      initiativeBusy = false;
      advanceInitiativeVoice();
      if (cid === liveConversation() && initiativeHealth()?.policy_revision === revision) renderLiveContext(liveState);
      else if (!pageController.signal.aborted) void advanceInitiative();
    }
  }

  function acceptCoreContext(event) {
    const detail = event.detail, cid = liveConversation();
    if (cfg.initiativeRuntime !== true || cid === null || initiativeSettingBusy || pageController.signal.aborted || !requireCurrentConfiguration()
      || !detail || detail.owner_user_id !== pageChatOwner || detail.agent_id !== pageAgent
      || detail.conversation_id !== cid || detail.working_attention_binding !== pageBinding) return;
    const health = initiativeHealth(detail.health,cid);
    if (!health || initiativeSupersededRevisions.has(health.policy_revision)) return;
    const previous = initiativeHealth();
    initiativeHealthError = '';
    // This accepted foreground activity supersedes any older presentation read.
    // Activity owns automatic attachment; GET polling never enables or rebinds it.
    liveView++;
    rememberInitiativeRevision(previous,health);
    if (!previous || previous.policy_revision !== health.policy_revision) {
      initiativeController?.abort();
      initiativeLastTick = 0;
    }
    if (!previous || previous.policy_revision !== health.policy_revision || previous.reason !== health.reason) initiativeError = '';
    renderLiveContext({...liveState,state:liveState?.state || 'quiet',
      initiative:{...liveState?.initiative,runtime:health}});
    void advanceInitiative();
  }

  function acceptCoreContextError(event) {
    const detail = event.detail, cid = liveConversation();
    if (cfg.initiativeRuntime !== true || cid === null || pageController.signal.aborted || !requireCurrentConfiguration()
      || !detail || detail.owner_user_id !== pageChatOwner || detail.agent_id !== pageAgent
      || detail.conversation_id !== cid || detail.working_attention_binding !== pageBinding) return;
    initiativeHealthError = detail.reason === 'timeout'
      ? 'The Agent’s connection timed out before its status could be confirmed.'
      : 'The Agent’s connection could not be confirmed. Retry its status check.';
    renderLiveContext(liveState || {state:'awaiting_runtime'});
  }

  function closePopup() {
    if (!popup.hidden) clearWorkingAttention();
    popup.hidden = true;
    button?.setAttribute('aria-expanded','false');
  }

  function openPopup() {
    if (participantContext() || !requireCurrentConfiguration()) return false;
    if (popup.hidden) workingAttentionView += 1;
    popup.hidden = false;
    button?.setAttribute('aria-expanded','true');
    void refresh(true);
    return true;
  }

  function openBrief() {
    if (window.VP3AgentActivityHub?.open) { closePopup(); return window.VP3AgentActivityHub.open(); }
    return openPopup();
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

  function updateHubLauncher() {
    if (!button || typeof window.VP3AgentActivityHub?.open !== 'function') return;
    button.setAttribute('aria-label','Top of Mind');
    if (badge) { badge.hidden = true; badge.textContent = ''; }
  }

  function renderStatus(brief) {
    const active = brief && brief.active !== false;
    if (statusDot) statusDot.classList.toggle('active', active);
    if (button) {
      button.dataset.active = active ? '1' : '0';
      button.dataset.runtimeState = runtimeState();
      button.setAttribute('aria-label',
        String((brief && brief.agent_name) || 'Agent') + ' · ' +
        String((brief && brief.status_label) || (active ? 'Active' : 'Inactive'))
      );
    }
    const count = Math.max(0, Number((brief && brief.attention_count) || 0));
    if (badge) {
      badge.hidden = count < 1;
      badge.textContent = count > 99 ? '99+' : String(count);
    }
    updateHubLauncher();
  }

  function actionButton(label, attrs, primary) {
    return '<button type="button"' +
      (primary ? ' class="primary"' : '') +
      attrs + '>' + esc(label) + '</button>';
  }

  function workingAttentionMarkup(item) {
    let link = '';
    if (item.href && item.action_label) {
      try {
        const url = new URL(item.href, window.location.origin);
        if (url.origin === window.location.origin && item.href.startsWith('/')) {
          link = '<a href="' + esc(item.href) + '">' + esc(item.action_label) + '</a>';
        }
      } catch (_error) {}
    }
    return '<article class="chat-agent-brief-card" data-agent-brief-working-attention>' +
      '<small>What needs attention</small><strong>' + esc(item.title) + '</strong>' +
      (item.summary ? '<p>' + esc(item.summary) + '</p>' : '') +
      (link ? '<div class="chat-agent-brief-actions">' + link + '</div>' : '') + '</article>';
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
    const replanning = brief.replanning_focus || null;
    const replanningHealth = String(brief.replanning_health || 'unavailable');
    const replanningCounts = brief.replanning_counts || {};
    const commitment = brief.commitment_focus || null;
    const commitmentCounts = brief.commitment_counts || {};
    const economics = brief.economics_focus || null;
    const economicsCounts = brief.economics_counts || {};
    const economicsUsage = brief.economics_usage || {};
    const economicsQuota = brief.economics_quota || {};
    const budgetGovernance = brief.budget_focus || null;
    const budgetCounts = brief.budget_counts || {};
    const budgetManageUrl = String(brief.budget_manage_url || '/budget-governance.php');
    const valueFocus = brief.value_focus || null;
    const valueCounts = brief.value_counts || {};
    const valueManageUrl = String(brief.value_manage_url || '/outcome-value.php');
    const decisionCalibration = brief.decision_calibration || {};
    const decisionAccuracy = brief.decision_accuracy || {};
    const decisionManageUrl = String(brief.decision_manage_url || '/decision-calibration.php');
    const currentStatePresentation = brief.current_state_presentation || {};
    const workingAttention = brief.working_attention || null;
    const counts = brief.counts || {};
    let html = '<div class="chat-agent-brief-status"><span><i class="chat-agent-brief-dot ' +
      (brief.active !== false ? 'active' : '') + '"></i>' +
      esc(brief.agent_name || 'Agent') + ' · ' + esc(brief.status_label || 'Active') +
      '</span><small>' + esc(brief.activity_title || 'Ready') + '</small></div>';

    if (currentStatePresentation.title && (!workingAttention || currentStatePresentation.type === 'attention')) {
      html += '<article class="chat-agent-brief-card"><small>Agent overview</small><strong>' +
        esc(currentStatePresentation.title || 'Current state') + '</strong>' +
        '<p>' + esc(currentStatePresentation.summary || '') + '</p>' +
        (currentStatePresentation.next_action ? '<p><small>' + esc(currentStatePresentation.next_action) + '</small></p>' : '') +
        '<div class="chat-agent-brief-actions">' +
        actionButton(currentStatePresentation.action_label || 'Review current state',
          ' data-agent-brief-prompt="' + esc('Review my unified current state across VP3. Summarize the most relevant current condition and any item that needs my attention. Use canonical state only; do not expose raw event payloads, system prompts, tool traces, confidence vectors, retrieval labels, or internal JSON.') + '"',false) +
        '</div></article>';
    }
    if (workingAttention && workingAttention.title) html += workingAttentionMarkup(workingAttention);
    html += liveStatusMarkup();
    const greeting = cfg.greetingPreview === true ? brief.greeting_preview : null;
    const greetingDecision = greeting?.decision;
    if (greetingDecision === 'would_mention' || greetingDecision === 'quiet') {
      const reason = Object.hasOwn(greetingReasons, greeting.reason) ? greetingReasons[greeting.reason] : '';
      html += '<p data-agent-greeting-preview><small>Greeting preview · ' +
        (greetingDecision === 'would_mention' ? 'An update may be worth mentioning.' : 'Stay quiet for now.') +
        (reason ? ' ' + reason : '') + '</small></p>';
    }

    if (commitment) {
      const state = String(commitment.deadline_state || commitment.status || 'protected').replaceAll('_',' ');
      const source = String(commitment.source_kind || 'commitment').replaceAll('_',' ');
      html += '<article class="chat-agent-brief-card"><small>Commitment protection · ' + esc(source) + '</small><strong>' +
        esc(commitment.title || 'Protected commitment') + '</strong>' +
        '<p>' + esc(state) + ' · protection ' + Number(commitment.protection_score || 0).toFixed(2) + '</p>' +
        '<p><small>' + Number(commitmentCounts.protected || 0) + ' protected · ' +
        Number(commitmentCounts.at_risk || 0) + ' at risk · ' + Number(commitmentCounts.conflicts || 0) + ' conflict(s) · ' +
        Number(commitmentCounts.verified_complete || 0) + ' verified complete</small></p>' +
        '<div class="chat-agent-brief-actions">' +
        actionButton('Review commitments',
          ' data-agent-brief-prompt="' + esc('Review my protected commitments and deadlines. Explain what is at risk, what is waiting on me, any capacity conflicts, and what can safely be reprioritized without changing commitments or executors.') + '"',false) +
        '</div></article>';
    }

    if (budgetGovernance) {
      const policy = budgetGovernance.policy || {};
      const budgetState = String(budgetGovernance.state?.state || 'healthy').replaceAll('_',' ');
      const usage = budgetGovernance.usage || {};
      const costUsed = '$' + (Number(usage.known_cost_micros || 0) / 1000000).toFixed(2);
      const tokenUsed = Number(usage.cloud_tokens_charged || 0).toLocaleString();
      html += '<article class="chat-agent-brief-card"><small>Budget governance · ' + esc(String(policy.enforcement_mode || 'soft')) + '</small><strong>' +
        esc(policy.label || 'AI budget') + '</strong>' +
        '<p>' + esc(budgetState) + ' · ' + esc(costUsed) + ' est. cost used · ' + esc(tokenUsed) + ' cloud tokens</p>' +
        '<p><small>' + Number(budgetCounts.held_goals || 0) + ' held goal(s) · ' + Number(budgetCounts.commitment_conflicts || 0) + ' commitment conflict(s) · ' + Number(budgetCounts.active_overrides || 0) + ' active override(s)</small></p>' +
        '<div class="chat-agent-brief-actions">' +
        actionButton('Review budget',
          ' data-agent-brief-prompt="' + esc('Review my explicit AI budget guardrails. Explain current-period usage, projected burn, held autonomous work, commitment conflicts, and any override I need to approve. Do not change budgets, packages, tokens, executors, deadlines, or workflow approvals.') + '"',false) +
        '<a href="' + esc(budgetManageUrl) + '">Manage budgets ↗</a></div></article>';
    }

    if (valueFocus) {
      const row = valueFocus.profile ? valueFocus : null;
      const profile = row?.profile || valueFocus.profile || {};
      const realization = row?.realization || valueFocus.realization || {};
      const money = String(profile.value_kind || '') === 'money';
      const expected = money
        ? String(profile.currency || '') + ' ' + (Number(profile.expected_value_micros || 0) / 1000000).toFixed(2)
        : Number(profile.expected_score || 0) + '/100 score';
      const realized = realization.verified
        ? (money ? String(realization.currency || profile.currency || '') + ' ' + (Number(realization.value_micros || 0) / 1000000).toFixed(2) : Number(realization.score_value || 0) + '/100 score')
        : 'not verified';
      const roi = row && row.expected_roi_percent !== null && row.expected_roi_percent !== undefined ? Number(row.expected_roi_percent).toFixed(1) + '% expected AI-cost ROI' : 'ROI unavailable';
      html += '<article class="chat-agent-brief-card"><small>Outcome value & ROI · explicit value</small><strong>' +
        esc(row?.title || profile.label || 'Outcome value') + '</strong>' +
        '<p>Expected ' + esc(expected) + ' · realized ' + esc(realized) + '</p>' +
        '<p><small>' + esc(roi) + ' · ' + Number(valueCounts.value_at_risk || 0) + ' value-at-risk goal(s) · ' + Number(valueCounts.verified_outcomes || 0) + ' verified outcome(s)</small></p>' +
        '<div class="chat-agent-brief-actions">' +
        actionButton('Review value',
          ' data-agent-brief-prompt="' + esc('Review my explicit outcome value and ROI. Separate expected value from verified realized value, explain value at risk and AI-cost ROI only where the evidence supports it, and do not invent monetary value, convert currencies, change budgets, commitments, executors, deadlines, approvals, or execution state.') + '"',false) +
        '<a href="' + esc(valueManageUrl) + '">Manage value ↗</a></div></article>';
    }

    if (decisionCalibration && Object.keys(decisionCalibration).length) {
      const fc = decisionCalibration.forecast?.cloud || {};
      const cost = decisionCalibration.cost?.cloud || {};
      const value = decisionCalibration.value?.money || {};
      const hit = decisionAccuracy.forecast_window_hit_rate === null || decisionAccuracy.forecast_window_hit_rate === undefined ? 'n/a' : (Number(decisionAccuracy.forecast_window_hit_rate) * 100).toFixed(0) + '% window hit';
      html += '<article class="chat-agent-brief-card"><small>Decision calibration · bounded learning</small><strong>Portfolio calibration</strong>' +
        '<p>Forecast ×' + Number(fc.factor || 1).toFixed(2) + ' · cost ×' + Number(cost.factor || 1).toFixed(2) + ' · value reliability ×' + Number(value.factor || 1).toFixed(2) + '</p>' +
        '<p><small>' + esc(hit) + ' · ' + Number(decisionAccuracy.settled_goals || 0) + ' settled goal(s) · minimum 5 evidence units per factor</small></p>' +
        '<div class="chat-agent-brief-actions">' +
        actionButton('Review calibration',
          ' data-agent-brief-prompt="' + esc('Review my portfolio decision calibration. Compare raw versus calibrated forecast accuracy, cost/token projection accuracy, value realization reliability, and the evidence sample counts. Do not change budgets, expected values, commitments, executors, deadlines, approvals, or execution state.') + '"',false) +
        '<a href="' + esc(decisionManageUrl) + '">Calibration details ↗</a></div></article>';
    }

    if (economics || economicsUsage.available || economicsQuota.available) {
      const knownMicros = Number(economicsUsage.known_cost_micros || 0);
      const knownCost = '$' + (knownMicros / 1000000).toFixed(knownMicros > 0 && knownMicros < 10000 ? 4 : 2);
      const unknown = Number(economicsUsage.unknown_cost_requests || 0);
      const quotaState = String(economicsQuota.state || 'unavailable').replaceAll('_',' ');
      const remaining = economicsQuota.unlimited ? 'unlimited' : Number(economicsQuota.remaining || 0).toLocaleString() + ' tokens remaining';
      html += '<article class="chat-agent-brief-card"><small>Cost & resource economics · 30-day ledger estimate</small><strong>' +
        esc(economics?.title || 'AI resource economics') + '</strong>' +
        '<p>' + esc(knownCost) + ' estimated AI cost from recorded usage' + (unknown ? ' + ' + unknown + ' unknown-priced request(s)' : '') + '</p>' +
        '<p><small>' + esc(quotaState) + ' · ' + esc(remaining) + ' · ' + Number(economicsCounts.planning_adjusted || 0) + ' autonomous goal(s) economically adjusted</small></p>' +
        '<div class="chat-agent-brief-actions">' +
        actionButton('Review economics',
          ' data-agent-brief-prompt="' + esc('Review my AI cost and resource economics. Separate ledger-estimated cost from unknown pricing, explain token pressure and any autonomous planning adjustments, and do not change commitments, executors, deadlines, packages, or token balances.') + '"',false) +
        '</div></article>';
    }

    if (replanning && replanningHealth !== 'unavailable') {
      const health = replanningHealth.replaceAll('_',' ');
      const action = String(replanning.recommended_action || 'keep_plan').replaceAll('_',' ');
      html += '<article class="chat-agent-brief-card"><small>Portfolio replanning · ' +
        esc(health) + '</small><strong>' +
        esc(replanning.title || ('Goal #' + Number(replanning.goal_id || 0))) + '</strong>' +
        '<p>' + esc(action) + ' · recovery score ' + Number(replanning.recovery_score || 0).toFixed(2) + '</p>' +
        '<p><small>' + Number(replanningCounts.deadline_threats || 0) + ' deadline threat(s) · ' +
        Number(replanningCounts.capacity_loss || 0) + ' capacity loss · ' +
        Number(replanningCounts.changes || 0) + ' rank change(s)</small></p>' +
        '<div class="chat-agent-brief-actions">' +
        actionButton('Review replan',
          ' data-agent-brief-prompt="' + esc('Review my portfolio replan. Explain the plan-health issue, proposed ordering changes, capacity impact, and anything that requires my approval. Do not execute or change executors.') + '"',false) +
        '</div></article>';
    }

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
    if (/account_changed/i.test(raw)) return 'Your session changed. Reload Chat to see your current brief.';
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

  function renderBriefError(error, retry = true) {
    if (statusDot) statusDot.classList.remove('active');
    if (button) {
      button.dataset.active='0';
      button.setAttribute('aria-label','Agent · status unavailable');
    }
    updateHubLauncher();
    if (badge) {
      badge.hidden = true;
      badge.textContent = '';
    }
    content.setAttribute('aria-busy','false');
    const message = briefErrorMessage(error);
    content.innerHTML =
      '<div class="chat-agent-brief-status"><span><i class="chat-agent-brief-dot"></i>Agent</span><small>Unavailable</small></div>' +
      '<article class="chat-agent-brief-card" role="status"><small>Status</small><strong>Agent Brief could not load.</strong>' +
      '<p>' + esc(message) + '</p>' + (retry ? '<div class="chat-agent-brief-actions">' +
      '<button type="button" class="primary" data-agent-brief-retry>Retry</button></div>' : '') + '</article>';
  }

  function stopUpdates() {
    pageController.abort();
    closeInitiativeDebug();
    readController?.abort();
    initiativeController?.abort();
    window.clearInterval(timer);
    window.clearInterval(profileAttentionTimer);
    window.clearInterval(initiativeTimer);
    observer.disconnect();
  }

  function clearPrivatePresentation() {
    state = null;
    clearLiveContext();
    activeVoiceThrough = 0;
    content.replaceChildren();
    thread.querySelectorAll('[data-homeserver-presence-id]').forEach(node => node.remove());
    renderStatus({active:false});
    try { window.STONEFELLOW_NOTIFICATION_CENTER?.cancelSpeech?.(); } catch (_error) {}
  }

  function invalidateAccount(error) {
    if (accountError) return;
    accountError = error;
    stopUpdates();
    clearPrivatePresentation();
    popup.querySelector('footer')?.setAttribute('inert','');
    window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
    renderBriefError(error, false);
  }

  function renderHomeServerPresence(candidate) {
    if (!candidate || !candidate.id || !thread) return;
    const id = Number(candidate.id || 0);
    if (id < 1) return;
    const key = 'vp3:homeserver-presence-presented';
    let previous = 0;
    try { previous = Number(sessionStorage.getItem(key) || 0); } catch (_error) {}
    if (id <= previous || thread.querySelector('[data-homeserver-presence-id="' + id + '"]')) return;
    const message = String(candidate.body || candidate.title || '').trim();
    if (!message) return;
    const node = document.createElement('div');
    node.className = 'message assistant homeserver-presence-update';
    node.dataset.homeserverPresenceId = String(id);
    node.dataset.homeserverPresencePriority = String(candidate.priority || 'status');
    node.innerHTML = '<div class="message-avatar" aria-hidden="true">✦</div>' +
      '<div class="message-body"><div class="message-role">Agent</div><div class="message-text"></div>' +
      '<div class="message-sources"><span class="message-source">HomeServer · ' +
      esc(String(candidate.priority || 'status')) + '</span></div></div>';
    const text = node.querySelector('.message-text');
    if (text) text.textContent = message;
    const welcome = document.getElementById('chatWelcome');
    if (welcome && welcome.parentNode === thread) thread.insertBefore(node,welcome);
    else thread.appendChild(node);
    try { sessionStorage.setItem(key,String(id)); } catch (_error) {}
    thread.scrollTop = thread.scrollHeight;
  }

  async function maybeSpeak(candidate) {
    const signal = pageController.signal;
    if (signal.aborted || document.hidden || activeVoiceThrough || !candidate || !candidate.through_id || !candidate.message) return false;
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
    if (!center || typeof center.announceWhen !== 'function') return false;

    let spoken = false;
    activeVoiceThrough = through;
    try {
      const canSpeak = () => !signal.aborted && requireCurrentConfiguration() && !document.hidden
        && activeVoiceThrough === through && !window.VP3LiveContext?.voiceDeferral?.()?.defer;
      spoken = (await Promise.resolve(center.announceWhen(String(candidate.message),canSpeak))) === true;
    } catch (_error) {
      spoken = false;
    }
    if (signal.aborted) return false;
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

  async function maybeSpeakGitHub(offer, conversationId) {
    if (githubVoiceBusy || activeVoiceThrough || !Number.isSafeInteger(offer?.generation) || offer.generation < 1
      || !Number.isSafeInteger(offer?.through_id) || offer.through_id < 1 || conversationId === null) return;
    const center = window.STONEFELLOW_NOTIFICATION_CENTER;
    if (typeof center?.announceWhen !== 'function') return;
    const epoch = githubVoiceEpoch;
    const signal = pageController.signal;
    const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    const canSpeak = () => githubVoiceEnabled && epoch === githubVoiceEpoch && !signal.aborted && document.hasFocus()
      && liveConversation() === conversationId && !input?.value.trim() && !window.VP3LiveContext?.voiceDeferral?.()?.defer;
    const idle = () => canSpeak() && !chat?.isBusy?.() && !chat?.pendingVoiceSubmit?.()
      && !window.VP3ConversationControls?.isReplyCapturePending?.()
      && !['thinking','processing','speaking','listening'].includes(runtimeState());
    if (!idle()) return;
    githubVoiceBusy = true;
    try {
      const result = await post('github_voice_claim',{conversation_id:conversationId,
        generation:offer.generation,through_id:offer.through_id},signal);
      const claim = result?.claim;
      if (claim?.generation !== offer.generation || claim.through_id !== offer.through_id || !canSpeak()) return;
      const message = String(claim.message || '');
      if (message) await center.announceWhen(message,canSpeak,{signal,readyToSpeak:idle,autoReply:false});
    } catch (_error) {
      // The durable notice remains available if the voice attempt is interrupted.
    } finally { githubVoiceBusy = false; }
  }

  async function refresh(force = false) {
    const signal = pageController.signal;
    if (participantContext() || signal.aborted || !requireCurrentConfiguration() || busy || (document.hidden && !force)) return;
    busy = true;
    const includeWorkingAttention = !popup.hidden && !document.hidden;
    const requestedBriefEpoch = briefEpoch;
    const requestedView = workingAttentionView;
    const requestedLiveView = liveView;
    const conversationId = liveConversation();
    const liveRequestIsCurrent = () => conversationId !== null && conversationId === liveConversation()
      && requestedLiveView === liveView;
    content.setAttribute('aria-busy','true');
    try {
      const next = await getState(includeWorkingAttention, conversationId);
      if (signal.aborted || document.hidden || participantContext() || requestedBriefEpoch !== briefEpoch) return;
      state = next;
      if (state.brief && (!includeWorkingAttention || requestedView !== workingAttentionView)) {
        delete state.brief.working_attention;
        delete state.brief.greeting_preview;
      }
      if (liveRequestIsCurrent()) {
        if (cfg.initiativeRuntime === true) {
          const runtime = state.live_context?.initiative?.runtime;
          if (initiativeHealth(runtime,conversationId)) {
            if (runtime.state !== 'starting') initiativeHealthError = '';
          } else initiativeHealthError = runtime ? 'The Agent returned status that does not match this Chat.'
            : 'The Agent did not return its status for this Chat.';
        }
        renderLiveContext(state.live_context || {state:'unavailable'},state.brief?.agent_name);
      }
      else {
        delete state.live_context;
        if (initiativeNewChat()) renderLiveContext({state:'new_chat'},state.brief?.agent_name);
      }
      renderBrief(state.brief || {});
      renderHomeServerPresence(state.homeserver_presence || null);
      refreshProfileAttention();
      void maybeSpeak(state.voice_candidate || null);
      if (liveRequestIsCurrent()) void maybeSpeakGitHub(state.github_voice,conversationId);
      advanceInitiativeVoice();
    } catch (error) {
      if (!signal.aborted && !participantContext() && requestedBriefEpoch === briefEpoch) {
        renderBriefError(error);
        if (liveRequestIsCurrent()) {
          const health = initiativeHealth();
          if (!health) initiativeHealthError = 'The Agent’s first status check could not be completed.';
          renderLiveContext({state:'unavailable',initiative:{...liveState?.initiative,
            runtime:health && {...health,active_jobs:[]}}});
        }
      }
    } finally {
      if (signal === pageController.signal) {
        content.setAttribute('aria-busy','false');
        busy = false;
        // An open or return to the tab can overtake an in-flight background read.
        if (!signal.aborted && !document.hidden && (
          (!popup.hidden && requestedView !== workingAttentionView) ||
          (requestedLiveView !== liveView && liveConversation() !== null)
        )) void refresh();
        else if (!signal.aborted && liveRequestIsCurrent()) void advanceInitiative();
      }
    }
  }

  function schedule() {
    if (timer) window.clearInterval(timer);
    const seconds = Math.max(15, Number((state && state.poll_seconds) || cfg.pollSeconds || 30));
    timer = window.setInterval(refresh, seconds * 1000);
    if (profileAttentionTimer) window.clearInterval(profileAttentionTimer);
    profileAttentionTimer = window.setInterval(refreshProfileAttention, 5000);
    if (initiativeTimer) window.clearInterval(initiativeTimer);
    if (cfg.initiativeRuntime === true) initiativeTimer = window.setInterval(advanceInitiative,15000);
  }

  button?.addEventListener('click', event => {
    event.preventDefault();
    if (window.VP3AgentActivityHub?.open) { openBrief(); return; }
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
    if (!pageController.signal.aborted || !event.target.closest('footer')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
  }, true);
  popup.addEventListener('click', event => {
    if (event.target.closest('[data-preserve-chat-draft]')) {
      closePopup();
      return;
    }
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
    if (popup.hidden || popup.contains(event.target) || button?.contains(event.target)) return;
    closePopup();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      closePopup();
      closeInitiativeDebug(true);
    } else initiativeDebugShortcut(event);
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
    githubVoiceEpoch++;
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
    if (!document.hidden) { showAwaitingInitiativeHealth(); void refresh(); }
    else {
      closeInitiativeDebug();
      clearWorkingAttention();
      clearLiveContext();
    }
  });
  if (liveHost) {
    window.addEventListener('vp3:agent-orb-ready',updateOrbInitiativeStatus);
    window.addEventListener('vp3:participant-task-context',updateOrbInitiativeStatus);
    window.addEventListener('vp3:agent-core-context',acceptCoreContext);
    window.addEventListener('vp3:agent-core-context-error',acceptCoreContextError);
    const acceptedCoreContext = window.StonefellowAgentActivity?.coreContext?.();
    if (acceptedCoreContext) acceptCoreContext({detail:acceptedCoreContext});
    window.addEventListener('stonefellow:chat-context-changing', clearLiveContext);
    window.addEventListener('vp3:chat-context-ready', () => {
      clearLiveContext();
      showAwaitingInitiativeHealth();
      if (liveConversation() !== null || initiativeNewChat()) void refresh();
    });
    window.addEventListener('vp3:chat-canvas-view-changed', () => {
      closeInitiativeDebug();
      clearLiveContext();
      showAwaitingInitiativeHealth();
      if (liveConversation() !== null || initiativeNewChat()) void refresh();
    });
    window.addEventListener('vp3:initiative-setting-changed', () => void refresh());
    window.addEventListener('stonefellow:knowledge-scope', () => {
      if (liveState && liveConversation() !== null) renderLiveContext(liveState);
    });
    window.addEventListener('storage', event => {
      if (event.key === liveMemoryPrefix + 'dismissed' && liveState && liveConversation() !== null) {
        renderLiveContext(liveState);
      }
    });
    document.addEventListener('stonefellow:chat-turn', event => {
      if (event.detail?.state === 'pending' && liveConversation() !== null) clearLiveContext();
    });
  }
  window.addEventListener('stonefellow:chat-context-changing', () => {
    githubVoiceEpoch++;
    closeInitiativeDebug();
  });
  window.addEventListener('stonefellow:agent-voice', event => {
    githubVoiceEnabled = event.detail?.enabled !== false;
    if (!githubVoiceEnabled) githubVoiceEpoch++;
    else void refresh();
  });
  window.addEventListener('focus', () => void refresh());

  const observer = new MutationObserver(records => {
    if (records.some(record => record.target === document.body)) {
      if (button) button.dataset.runtimeState = runtimeState();
      if (state && state.brief) renderStatus(state.brief);
    }
  });
  window.addEventListener('pagehide', () => {
    closePopup();
    stopUpdates();
    if (!accountError) clearPrivatePresentation();
  });
  window.addEventListener('pageshow', event => {
    if (!event.persisted || accountError) return;
    pageController = new AbortController();
    busy = false;
    startUpdates();
  });

  function startUpdates() {
    if (!pageBinding) {
      invalidateAccount(new Error('account_changed'));
      return;
    }
    observer.observe(document.body,{attributes:true,attributeFilter:['data-stonefellow-agent-state']});
    showAwaitingInitiativeHealth();
    const signal = pageController.signal;
    void refresh().finally(() => { if (!signal.aborted) schedule(); });
  }

  window.VP3_COGNITIVE_PRESENTATION = {
    refresh:refresh,openBrief,openDetails:openPopup,closeBrief:closePopup,
    allowsMusicOffer:() => initiativeMayAdvance() && initiativeHealth()?.enabled === true,
    followUpReference(conversationId) {
      const key = String(liveState?.attention?.key || '');
      if (pageController.signal.aborted || !liveHost || liveHost.hidden || liveConversation() !== conversationId
        || !/^[a-f0-9]{64}$/.test(key)) return null;
      const messages = thread.querySelectorAll('.message');
      const latestMessage = messages[messages.length - 1];
      return explicitlySelectedReference === key || liveHost.contains(latestMessage) ? key : null;
    }
  };
  function participantChanged() {
    briefEpoch++;
    closePopup();
    closeInitiativeDebug();
    readController?.abort();
    if (participantContext()) clearPrivatePresentation();
    else void refresh();
  }
  window.addEventListener('vp3:participant-selection-changed',participantChanged);
  window.addEventListener('vp3:participant-task-context',event => { participantActor = Number(event.detail?.participant_id) || 0; participantChanged(); });
  window.addEventListener('vp3:agent-activity-ready',updateHubLauncher);
  updateHubLauncher();
  startUpdates();
})();
