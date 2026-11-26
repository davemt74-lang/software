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
  let noticeVoiceBusy = false;
  let noticeVoiceEpoch = 0;
  let noticeVoiceEnabled = window.STONEFELLOW_CHAT?.agentVoiceEnabled !== false;
  let creditNoticeIds = '';
  let suppressedVoiceThrough = 0;
  let initiativeController = null;
  let initiativeTimer = 0;
  let initiativeBusy = false;
  let initiativeSettingBusy = false;
  let initiativeLastTick = 0;
  let initiativePumpHealth = null;
  let initiativeError = '';
  let initiativeHealthError = '';
  let initiativeRetry = null;
  let initiativeDebug = null;
  let initiativeDebugKeys = 0;
  let initiativeDebugKeyAt = 0;
  const initiativeSupersededRevisions = new Set();
  const initiativeSettingsViews = new Set();
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

  async function readUntilCancelled(signal, operation) {
    signal.throwIfAborted();
    let abort;
    const cancelled = new Promise((_, reject) => {
      abort = () => reject(signal.reason);
      signal.addEventListener('abort',abort,{once:true});
    });
    try {
      const result = await Promise.race([operation(),cancelled]);
      signal.throwIfAborted();
      return result;
    } finally {
      signal.removeEventListener('abort',abort);
    }
  }

  async function presentationResponse(response, signal, allowTickOutcome = false) {
    signal.throwIfAborted();
    const data = await response.json().catch(() => ({}));
    signal.throwIfAborted();
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
    const timeout = window.setTimeout(() => controller.abort(new DOMException('Agent status request timed out.', 'TimeoutError')), 10000);
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
      const data = await readUntilCancelled(controller.signal,async () => {
        const response = await fetch(url.toString(), {
          credentials:'same-origin',
          cache:'no-store',
          headers:{Accept:'application/json','X-VP3-Working-Attention':pageBinding},
          signal:controller.signal
        });
        return presentationResponse(response,controller.signal);
      });
      return data.state || {};
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


  function liveConversation(includeInactive = false) {
    if (participantContext() || !liveHost || (!includeInactive && document.hidden) || thread.dataset.view !== 'chat'
      || !requireCurrentConfiguration()) return null;
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

  function clearLiveContext({ keepInitiative = false } = {}) {
    if (!keepInitiative) {
      initiativeController?.abort();
      initiativePumpHealth = null;
    }
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
    previous ??= initiativePumpHealth;
    const prior = String(previous?.policy_revision || ''), current = String(next?.policy_revision || '');
    if (initiativeSupersededRevisions.has(current)) return;
    initiativePumpHealth = next;
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
    if (initiativeBusy) {
      if (health?.execution_ready !== true) return 'Checking for prepared updates.';
      return health.state === 'running' ? 'The Agent is advancing background work.' : 'Checking for permitted background work.';
    }
    switch (health?.policy_reason) {
      case 'service_disabled': return 'Initiative is disabled in AI service settings.';
      case 'background_disabled': return 'Background Initiative is disabled in AI service settings.';
      case 'service_budget_exhausted': return 'The Initiative service token limit blocks background work.';
      case 'service_model_forbidden': return 'The Initiative service has no permitted model.';
      case 'service_policy_unavailable': return 'Initiative could not read its AI service settings.';
    }
    if (health.state === 'blocked' && typeof health.execution_diagnostic?.detail === 'string') return health.execution_diagnostic.detail;
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
      case 'route_check_required': return 'The next background run will verify the execution route.';
      case 'worker_missing': return 'No background worker has been observed. This open Chat can advance permitted work.';
      case 'foreground_active': return 'Open Chat is advancing permitted work.';
      case 'worker_stale': return 'The background worker has not checked in recently. This open Chat can advance permitted work.';
      case 'worker_failed': return 'The last background worker sweep failed. This open Chat can retry permitted work.';
      case 'worker_observation_unavailable': return 'Background worker status could not be read. This open Chat can advance permitted work.';
      case 'assessment_sources_changed': return 'Sources changed during the assessment. The Agent will check again in its next review.';
      case 'assessment_unverified': return 'The last assessment did not produce a verified plan.';
      case 'public_search_unavailable': return 'The public search service could not be reached for the last research step.';
      case 'source_unavailable': return 'A source record could not be verified for the last step.';
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
    const failure = health?.failure_detail;
    const retrying = health?.reason === 'retry_scheduled';
    const currentFailure = (health?.state === 'failed' || retrying) && failure?.is_latest_run === true;
    const label = currentFailure
      && typeof failure.stage === 'string' && failure.stage && typeof failure.reason === 'string' && failure.reason
      ? failure.stage + '. ' + failure.reason : initiativeRuntimeLabel(health);
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
    const worker = enabled && !currentFailure && health?.runner_state === 'never_seen' && !['worker_missing','foreground_active'].includes(health?.reason)
      ? ' No server worker check-in is recorded.' : '';
    const retryAt = Date.parse(failure?.retry_at || health?.retry_due_at || '');
    const failedAt = Date.parse(failure?.at || '');
    const retryStatus = retrying ? Number.isFinite(retryAt)
      ? ' Next attempt is eligible at ' + new Date(retryAt).toLocaleString() + '.'
      : ' A retry is scheduled.'
      : currentFailure && failure.status === 'failed'
        ? (Number.isFinite(failedAt) ? ' Attempt ended ' + new Date(failedAt).toLocaleString() + '.' : ' This run ended.') : '';
    const source = currentFailure ? initiativeFailureSource(failure) : null;
    const sourceStatus = source ? ` Source check: ${source.label} (${source.coverage}).` : '';
    const connectionWarning = health && health.state !== 'starting' && initiativeHealthError ? ' ' + initiativeHealthError : '';
    return {
      label, detail:(sourceStatus + retryStatus + knowledge + verified + decision + worker + connectionWarning).trim(),
      setting:canChange ? (enabled && !linked ? 'off' : 'on') : '',
      settingBusy:initiativeSettingBusy,
      retry:Boolean(initiativeHealthError) || health?.state === 'unavailable',
      retryBusy:Boolean(initiativeRetry),
      diagnostics:liveConversation() !== null
    };
  }

  const initiativeSourceLabels = {
    calendar:'Calendar',reminders:'Reminders',tasks:'Tasks',missions:'Missions',profile_calls:'Profile calls',
    meeting_prep:'Meeting preparation',orders:'Orders',campaigns:'Campaigns',rewards:'Rewards',
    transcripts:'Transcripts',screen_followup:'Shared-screen follow-ups',account_readiness:'Account setup',
    business_readiness:'Business setup',product_readiness:'Product setup',scheduling_readiness:'Scheduling setup',
    calendar_readiness:'Calendar setup',workbench_product:'Product workbench',workbench_marketing:'Marketing workbench',
    workbench_screen:'Screen workbench',workbench_mission:'Mission workbench',open_tasks:'Open tasks',
    activity_situations:'Recent activity',chat_history:'Chat history',canvas_work:'Canvas work',song_history:'Listening history',
    mission_evidence:'Mission evidence',product_context:'Product context',task_lists:'Task lists',live_context:'Live context',
    artist_research:'Artist research',topic_research:'Topic research',native_artist_research:'VP3 artist research',
    task_research:'Task research',transcript_research:'Transcript research',email_canvas:'Email Canvas',canvas:'Canvas Apps',
    office_tasks:'Office tasks',office_reminders:'Office reminders',office_calendar:'Office calendar',
    activity_context:'Activity context',gmail:'Gmail',planning:'Planning',media:'Media & Entertainment',curiosity:'Curiosity',
    casual:'Casual check-ins','connected-sites':'Connected Sites',marketing:'Marketing',media_images:'Media image source',profile:'Profile'
  };

  function initiativeFailureSource(failure) {
    if (failure?.reason_code !== 'source_unavailable' || !Object.hasOwn(initiativeSourceLabels,failure.source_family)
      || !['unavailable','limited'].includes(failure.source_coverage)) return null;
    return {label:initiativeSourceLabels[failure.source_family],coverage:failure.source_coverage};
  }

  const initiativeDebugValues = {
    state: ['unavailable','starting','off','paused','linked_elsewhere','blocked','waiting','checked','running','failed'],
    reason: ['automatic_startup','initiative_paused','initiative_off','linked_elsewhere','context_unavailable',
      'runtime_unavailable','storage_unavailable','quota_exhausted','model_unavailable','route_unavailable','route_check_required','worker_missing',
      'worker_stale','worker_failed','worker_observation_unavailable','foreground_active','assessment_sources_changed','assessment_unverified',
      'review_failed','worker_interrupted','retry_scheduled','reviewing','checked','waiting_for_review','public_search_unavailable','source_unavailable'],
    policy_reason: ['service_disabled','background_disabled','service_budget_exhausted','service_model_forbidden','service_policy_unavailable'],
    route: ['none','vp3_cloud','homeserver'],
    runner_state: ['never_seen','fresh','stale','failed','unknown'],
    outcome: ['idle','existing_assignments','assignments_queued'],
    rejection: ['output_too_large','invalid_json','invalid_envelope','invalid_job','invalid_job_sources','invalid_opportunities']
  };

  const initiativeEngagementLabels = {
    presence: {
      quiet:'Ready for an update',busy:'The browser reported Chat was busy',playing_media:'Media is playing',
      not_focused:'Chat is open without focus',not_visible:'Chat is hidden',
      other_chat:'Activity belongs to another Chat',stale:'The last activity check expired',
      no_heartbeat:'No activity check recorded',unavailable:'Activity could not be verified'
    },
    busy_reason: {
      restore:'Restoring the saved Chat',draft:'Composer contains a draft',chat_request:'A Chat request is active',
      voice_turn:'Waiting for a voice turn',voice_playback:'Agent voice is playing',
      voice_preview:'The composer shows a speech preview',restored_draft:'A saved draft was restored into the composer',
      typed_draft:'The composer contains a typed draft'
    },
    gate: {
      ready:'Chat can receive a verified result',quiet_chat_required:'Waiting for quiet, focused Chat',
      owner_turn_pending:'Waiting for the current Chat turn to finish',
      recent_proactive_post:'Waiting after a recent proactive update',
      paused:'Initiative is paused',linked_elsewhere:'Initiative is linked to another Chat',
      unavailable:'Initiative could not confirm Chat delivery'
    },
    tick: {
      completed:'Completed a step',no_work:'No eligible work on this check',blocked:'Execution is blocked',
      at_capacity:'All open-Chat worker slots are occupied',already_running:'Work is already running',
      retry_scheduled:'A retry is scheduled',schedule_unavailable:'Scheduling could not be verified',
      off:'Initiative is off',execution_no_longer_active:'The assigned step lost its claim',
      assessment_sources_changed:'Sources changed during assessment',
      assessment_unverified:'The assessment did not verify a plan',review_failed:'The assigned step failed',
      model_unavailable:'The AI request failed',quota_exhausted:'The AI allowance is exhausted',
      route_unavailable:'The required execution route is unavailable',
      public_search_unavailable:'The public search service could not be reached',
      source_unavailable:'A source record could not be verified',
      unverified:'The check ended without a verified outcome'
    },
    tick_step: {
      assessment:'Assessment',preparation:'Assigned preparation',saved_delivery:'Delivered a saved result'
    },
    coordination: {
      sources_checked:'Checked specialist sources',
      queued_work:'An existing specialist assignment is in the queue',
      queued_chat_handoff:'Queued a specialist finding for Chat',queued_private_work:'Queued private specialist work',
      queued_checkin:'Queued a casual check-in',queued_media_offer:'Queued a media suggestion',
      morning_brief_posted:'Delivered the morning brief',morning_brief_waiting:'Morning brief waiting after a recent update',
      reminder_posted:'Delivered an open-question reminder',no_candidate:'No eligible specialist action',
      handoff_already_handled:'An eligible finding was already handled',
      handoff_failed:'An eligible finding has a stopped assignment',
      handoff_cancelled:'An eligible finding has a cancelled assignment',
      handoff_paused:'An eligible finding has a paused assignment',failed:'The specialist scan failed'
    },
    scan_phase: {
      scan_setup:'Reading the scan schedule',owner_lock:'Locking the owner record',chat_access:'Verifying Chat access',
      specialist_scan:'Scanning specialist reports',queue_check:'Checking the shared queue',
      candidate_selection:'Selecting a specialist finding',checkpoint:'Saving the scan checkpoint',commit:'Committing the scan'
    },
    opportunity_check: {
      checking:'Checking sources',queued:'Queued preparation',already_handled:'An equivalent assignment already exists',
      no_opportunity:'No eligible opportunity in the current sources',not_ready:'Waiting for a required capability',
      blocked:'Required access is unavailable',failed:'The opportunity check failed'
    },
    opportunity_stage: {
      proposal_ready:'Canvas proposal prepared for review',draft_queued:'Handed to the Coding Agent for a private draft',
      approval_pending:'Mission proposal awaiting approval'
    },
    step: {
      'coordination.review':'Review specialist findings','chat.check_in':'Chat check-in',
      'work.prepare_private':'Prepare a private next step','calendar.read':'Calendar review',
      'tasks.read':'Task review','tasks.lists.read':'Task list review','reminders.read':'Reminder review',
      'transcripts.read':'Transcript review','topic.research':'Topic research','artist.research':'Artist research',
      'canvas.prepare':'Canvas preparation','image.prepare':'Image concept','missions.prepare':'Mission preparation',
      'missions.evidence.read':'Mission review','knowledge.search':'Knowledge search','brain.read':'Agent memory review',
      'youtube.playback_offer':'YouTube playback offer','live.context.read':'Live context review','connected-sites.scan':'Connected Sites POS scan'
    },
    specialist: {
      gmail:'Gmail',planning:'Planning',tasks:'Tasks & Lists',media:'Media & Entertainment',canvas:'Canvas Apps',curiosity:'Curiosity',casual:'Casual check-ins',
      research:'Research',eyes:'Agent Eyes','connected-sites':'Connected Sites',transcripts:'Transcriptions',
      coding:'Coding','image-generator':'Image Generator',mission:'Missions',marketing:'Marketing',commerce:'Commerce',profile:'Profile'
    },
    rule_action: {ask:'Ask in Chat',report:'Report in Chat',watch:'Keep in Top of Mind'},
    rule_trigger: {
      needs_attention:'Needs your input',opportunity:'Opportunity to advance',important:'Important change',
      waiting_owner:'Waiting on you',overdue:'Overdue',blocked:'Blocked',due_soon:'Due soon',
      completed:'Completed with a result',pending_confirmation:'Pending confirmation',conflict:'Schedule conflict',
      changed:'Schedule changed',followup:'Meeting follow-up',start_plan:'Start a plan',
      resume:'Continue listening',catalog_pick:'VP3 Music discovery',recent_search:'Relevant YouTube pick',
      watched_finding:'Finding from a watched video',listened_finding:'Finding from a played track',image_concept:'Media image concept',
      stoneward_repeat:'A track you return to on Stonefellow',idle_invitation:'Media check-ins',high_priority:'High priority',
      open_question:'Question to get to know you',open_followup:'Saved transcription follow-up',pos_attention:'A POS ticket needs attention',
      daily_sales:'Daily sales totals are available',optimization_ready:'A campaign recommendation is ready',
      setup_blocker:'Campaign setup needs attention',campaign_outcome:'Verified campaign outcome available',
      needs_owner:'Visitor needs your reply',open_conversation:'Open Profile conversation',missed_call:'Missed Profile call',
      reviewed_followup:'Reviewed Profile follow-up',activity:'Profile activity'
    },
    scan_disposition: {
      native_presentation:'The existing visitor, call, task, or morning briefing presentation owns this update',
      queued:'Queued for Chat',existing_handoff:'Already queued for Chat',handoff_pending:'Held behind an assignment for this specialist',
      already_handled:'Skipped because the finding was already handled',
      dedupe_conflicts:'Skipped because a matching assignment was already recorded',
      handoff_failed:'Held because the matching assignment failed',
      handoff_cancelled:'Skipped because the matching assignment was cancelled',
      handoff_paused:'Held because the matching assignment is paused',
      quiet_chat_required:'Held until Chat is quiet',owner_turn_pending:'Held until the current Chat turn finishes',
      recent_proactive_post:'Held after a recent proactive update',playing:'Held while Media is playing',
      cooldown:'Held after a recent Media suggestion',new_activity_required:'Held until there is fresh activity',
      already_offered:'Skipped because the offer already appeared',
      route_unavailable:'Held because the required execution route is unavailable',
      observe_only:'Observed on a source-check pass',next_scan:'No assignment selected for this finding on this pass',
      transcript_history:'Kept in Transcriptions because the saved analysis is older',
      transcript_pacing:'Waiting after a recent Transcriptions post'
    },
    post_source: {initiative:'Top of Mind',gmail:'Gmail',coding:'Coding'},
    post_result: {
      question:'Question',report:'Report',draft:'Draft',research:'Research result',check_in:'Check-in',
      playback_offer:'Playback offer',inbox_update:'Inbox update',followthrough:'Follow-up',
      morning_brief:'Morning brief',reminder:'Open-question reminder',
      canvas_draft:'Canvas draft',canvas_proposal:'Canvas proposal',image_concept:'Image concept'
    },
    status: {
      queued:'Queued',approved:'Queued',executing:'Working',completed:'Completed',failed:'Failed',
      paused:'Paused',cancelled:'Cancelled',interrupted:'Claim expired'
    },
    delivery: {
      pending:'Waiting to post in Chat',private:'Saved privately',withheld:'Withheld during verification',
      delivered:'Posted in Chat'
    },
    delivery_reason: {
      private:'This assignment was for private work',specialist_report:'Saved in the specialist report',
      native_presentation:'The existing visitor, call, task, or morning briefing presentation owns this update',
      source_changed:'Source records changed before delivery',
      policy_changed:'Initiative settings changed',rule_changed:'The current Top of Mind rule changed',
      duplicate:'The update was already reported',meeting_changed:'The meeting changed before delivery',
      result_unavailable:'The saved result could not be verified',speech_only:'Reserved for spoken delivery',
      delivery_held:'Waiting to retry delivery',
      public_topic_unavailable:'The topic could not be turned into a public research query',
      transcript_history:'Kept in Transcriptions because the saved analysis is older',
      transcript_pacing:'Waiting after a recent Transcriptions post'
    }
  };

  function initiativeEngagementRows(engagement,time) {
    const label = (group,value) => typeof value === 'string' && Object.hasOwn(initiativeEngagementLabels[group],value)
      ? initiativeEngagementLabels[group][value] : '';
    const id = value => Number.isSafeInteger(value) && value > 0;
    const rows = [
      ['Chat presence',label('presence',engagement.presence) || 'Unavailable'],
      ['Chat delivery',label('gate',engagement.gate) || 'Unavailable']
    ];
    if (label('busy_reason',engagement.busy_reason)) rows.push(['Reported busy reason',label('busy_reason',engagement.busy_reason)]);
    if (engagement.specialist_gate === 'ready') rows.push(['Specialist delivery','Chat can receive a verified specialist handoff']);
    if (Number.isSafeInteger(engagement.presence_age_seconds) && engagement.presence_age_seconds >= 0
      && engagement.presence_age_seconds <= 3600) rows.push(['Chat activity recorded',engagement.presence_age_seconds + ' seconds ago']);
    const tick = engagement.latest_tick;
    const tickAt = time(tick?.at);
    const tickUnavailable = engagement.tick_checked === false || (engagement.tick_checked !== true
      && engagement.presence === 'unavailable' && engagement.gate === 'unavailable');
    rows.push(['Last open-Chat check',tickAt !== '—' ? tickAt : tickUnavailable ? 'Could not verify' : 'None recorded']);
    if (label('tick',tick?.reason)) rows.push(['Open-Chat check result',label('tick',tick.reason)]);
    if (label('tick_step',tick?.step)) rows.push(['Open-Chat step',label('tick_step',tick.step)]);
    if (id(tick?.run_id)) rows.push(['Open-Chat step run',tick.run_id]);
    if (label('coordination',tick?.coordination?.outcome)) {
      rows.push(['Specialist scheduling',label('coordination',tick.coordination.outcome)]);
      if (time(tick.coordination.at) !== '—') rows.push(['Last specialist scheduling check',time(tick.coordination.at)]);
      const failure = tick.coordination.failure;
      if (label('scan_phase',failure?.phase)) rows.push(['Specialist scan failed while',label('scan_phase',failure.phase)]);
      if (/^[a-z][a-z0-9_]{0,40}$/.test(failure?.code || '')) rows.push(['Specialist scan code',failure.code]);
      if (/^[A-Z0-9]{5}$/.test(failure?.sqlstate || '')) {
        rows.push(['Database error code',failure.sqlstate + (id(failure.database_code) && failure.database_code <= 65535 ? ' / ' + failure.database_code : '')]);
      }
      if (/^[a-z0-9-]{1,96}\.php$/.test(failure?.file || '') && id(failure.line)) {
        rows.push(['Specialist scan location',failure.file + ':' + failure.line]);
      }
    }

    const scan = engagement.specialist_scan;
    if (time(scan?.at) !== '—') {
      rows.push(['Specialist counts','Findings and rule matches are from the saved scan below.']);
      rows.push(['Last completed specialist scan',time(scan.at)]);
      const coverageLabels = {ready:'Checked',limited:'Partially checked',unavailable:'Could not check',unconfigured:'Source inactive for this Chat'};
      const mediaNotes = {quiet_chat_required:'Waiting for quiet Chat',playing:'Media is playing',cooldown:'Waiting after a recent suggestion',
        report_only:'Available in the specialist report',new_activity_required:'Waiting for a new Chat turn',already_offered:'Current suggestions were already offered',
        no_playable_sources:'No playable track, resume, or saved YouTube suggestion found',
        ready:'Offer eligible for Chat',disabled:'Media check-ins are off',idle_spacing:'Waiting for the quiet period',
        owner_turn_pending:'Waiting for the current Chat turn',recent_proactive_post:'Waiting after a recent Agent post',
        verification_unavailable:'Activity could not be verified',handoff_pending:'A Media offer is already queued',
        handoff_stopped:'The last Media offer stopped before delivery'};
      const curiosityNotes = {candidate:'Question found and waiting for selection',ready:'A question can be posted',
        handoff_pending:'A Curiosity question is already queued',quiet_chat_required:'Waiting for quiet Chat',playing:'Media is playing',
        owner_turn_pending:'Waiting for the current Chat turn',recent_proactive_post:'Waiting after a recent proactive update',
        awaiting_reply:'Earlier question still open. Continuing to explore other topics.',no_question:'No current question',higher_priority:'Higher-priority work is queued',
        report_only:'The last scan kept questions in the Curiosity report',already_offered:'This question already appeared in Chat'};
      for (const family of ['gmail','tasks','planning','media','canvas','curiosity','connected-sites','transcripts','marketing','profile']) {
        const result = scan.specialists?.[family];
        if (!Object.hasOwn(coverageLabels,result?.coverage)) continue;
        const details = [coverageLabels[result.coverage]];
        if (Number.isSafeInteger(result.found) && result.found >= 0 && result.found <= 100 && ['ready','limited'].includes(result.coverage)) {
          details.push(`${result.found} current ${result.found === 1 ? 'finding' : 'findings'}`);
        }
        for (const [key,suffix] of [['chat_matches','matched Chat rules'],['queued','queued']]) {
          if (Number.isSafeInteger(result[key]) && result[key] > 0 && result[key] <= 100) details.push(`${result[key]} ${suffix}`);
        }
        if (['storage_error','invalid_internal_type','invalid_data','access_changed','code_error','verification_unavailable',
          'storage_unavailable','provider_unavailable'].includes(result.failure)) details.push(result.failure);
        const mediaGate=result.handoff_gate || result.offer_gate;
        if (family === 'media' && Object.hasOwn(mediaNotes,mediaGate)) details.push(mediaNotes[mediaGate]);
        if (family === 'curiosity' && Object.hasOwn(curiosityNotes,result.offer_gate)) {
          const conflicting = result.offer_gate === 'report_only' && Number.isSafeInteger(result.chat_matches) && result.chat_matches > 0;
          details.push(conflicting ? 'Saved scan reported conflicting routing information' : curiosityNotes[result.offer_gate]);
        }
        const specialist = label('specialist',family);
        rows.push([specialist,details.join(' · ')]);
        if (Array.isArray(result.dispositions)) {
          let count = 0;
          for (const match of result.dispositions.slice(0,120)) {
            const rule = family === 'transcripts' && match?.trigger === 'open_question'
              ? 'Unanswered transcription question' : label('rule_trigger',match?.trigger);
            const action = label('rule_action',match?.action);
            const reason = label('scan_disposition',match?.reason);
            if (rule && ['ask','report'].includes(match.action) && action && reason) {
              rows.push([`${specialist} match ${++count}`,[rule,action,reason].join(' · ')]);
            }
          }
        }
      }
    }
    if (['ask','watch'].includes(engagement.curiosity_rule)) {
      rows.push(['Current Curiosity Chat rule',label('rule_action',engagement.curiosity_rule)]);
    }

    for (const [lane,name] of [['app_suggestions','App Suggestions'],['research','Research'],['missions','Missions'],['media_images','Media image concepts']]) {
      const check = engagement.opportunity_checks?.[lane];
      if (!label('opportunity_check',check?.state)) continue;
      const checkedAt = time(check.at);
      const details = [label('opportunity_check',check.state)];
      if (checkedAt !== '—') details.push(checkedAt);
      rows.push([name + ' opportunity check',details.join(' · ')]);
      if (['source_check','opportunity_check'].includes(check.failure?.phase)) {
        rows.push([name + ' failed while',check.failure.phase === 'source_check' ? 'Checking current sources' : 'Evaluating opportunities']);
        rows.push(...initiativeDebugCheckRows(check.failure,name + ' check code',name + ' database code'));
      }
      const assignment = check.assignment;
      if (id(assignment?.run_id)) {
        const outcome = [label('status',assignment.status),label('opportunity_stage',assignment.opportunity_stage),
          label('delivery',assignment.delivery)].filter(Boolean);
        rows.push([name + ' latest assignment',[`Run ${assignment.run_id}`,...outcome].join(' · ')]);
      }
    }

    const work = engagement.latest_preparation;
    if (id(work?.run_id)) {
      const step = label('step',work.step) || 'Private preparation';
      const specialist = label('specialist',work.specialist);
      rows.push(['Latest assignment',specialist ? specialist + ' · ' + step : step]);
      rows.push(['Assignment run',work.run_id]);
      if (id(work.action_id)) rows.push(['Assignment action',work.action_id]);
      if (label('status',work.status)) rows.push(['Assignment status',label('status',work.status)]);
      if (id(work.attempt)) rows.push(['Assignment attempt',work.attempt]);
      if (time(work.at) !== '—') rows.push(['Assignment updated',time(work.at)]);
      if (time(work.heartbeat_at) !== '—') rows.push(['Assignment heartbeat',time(work.heartbeat_at)]);
      if (time(work.lease_expires_at) !== '—') rows.push(['Assignment claim expires',time(work.lease_expires_at)]);
      if (time(work.eligible_at) !== '—') rows.push(['Next eligible attempt',time(work.eligible_at)]);
      if (label('delivery',work.delivery)) rows.push(['Assignment delivery',label('delivery',work.delivery)]);
      if (label('delivery_reason',work.delivery_reason)) rows.push(['Delivery reason',label('delivery_reason',work.delivery_reason)]);
      if (id(work.message_id)) rows.push(['Assignment Chat message',work.message_id]);
    } else rows.push(['Latest assignment',engagement.preparation_checked === false
      ? 'Could not verify' : 'None recorded for this Chat and settings']);

    const handoffStates = {queued:'Queued for Chat',working:'Preparing the Chat handoff',waiting:'Waiting to post in Chat',
      posted:'Posted in Chat',blocked:'Blocked',skipped:'Skipped',unverified:'Could not verify'};
    const handoffReasons = {
      assignment_queued:'Waiting for a worker to claim the assignment',
      assignment_running:'The shared Top of Mind worker has an active claim',
      claim_expired:'The worker claim expired',assignment_paused:'The assignment is paused',
      assignment_cancelled:'The assignment was cancelled',assignment_failed:'The assignment failed before delivery',
      ready:'Prepared and eligible for delivery',saved_message:'Verified against the saved Chat message',playing:'Media is playing'
    };
    const replyStates = {open:'Awaiting your reply',discussed:'Reply recorded',deferred:'Follow-up deferred',
        queued:'Follow-up queued',stale:'Finding changed',declined:'Declined',owner_resolved:'Marked resolved by owner',completed:'Follow-up completed'};
    for (const family of ['tasks','planning','gmail','canvas','curiosity','media','connected-sites','transcripts','marketing','profile']) {
      const handoff = engagement.specialist_handoffs?.[family];
      if (!id(handoff?.run_id) || !Object.hasOwn(handoffStates,handoff.state)) continue;
      const details = [handoffStates[handoff.state]];
      const reason = Object.hasOwn(handoffReasons,handoff.reason) ? handoffReasons[handoff.reason] :
        label('delivery_reason',handoff.reason) || label('gate',handoff.reason);
      if (reason) details.push(reason);
      details.push('Run ' + handoff.run_id);
      if (id(handoff.message_id)) details.push('Message ' + handoff.message_id);
      if (Object.hasOwn(replyStates,handoff.reply_state)) details.push(replyStates[handoff.reply_state]);
      rows.push([label('specialist',family) + ' Chat handoff',details.join(' · ')]);
    }

    const post = engagement.last_post;
    if (id(post?.message_id) && time(post.at) !== '—') {
      rows.push(['Last proactive Chat post',time(post.at) + ' · Message ' + post.message_id]);
      if (id(post.run_id)) rows.push(['Last proactive post run',post.run_id]);
      const source = label('specialist',post.specialist) || label('post_source',post.source);
      if (source) rows.push(['Last proactive post source',source]);
      if (label('post_result',post.result)) rows.push(['Last proactive post type',label('post_result',post.result)]);
      if (post.result === 'reminder' && id(post.original_message_id)) {
        const priority = {high:'High',normal:'Normal',low:'Low'};
        rows.push(['Original question','Message ' + post.original_message_id]);
        if (Object.hasOwn(priority,post.priority)) rows.push(['Reminder priority',priority[post.priority]]);
      }
      if (post.includes_gmail === true && post.specialist !== 'gmail') rows.push(['Last proactive post also used','Gmail']);
    } else rows.push(['Last proactive Chat post',engagement.post_checked === false
      ? 'Could not verify' : 'None recorded in this Chat']);
    return rows;
  }

  function initiativeDebugCheckRows(diagnostic, checkLabel, databaseLabel) {
    const rows = [];
    if (['storage_error','storage_schema','storage_contention','storage_duplicate','invalid_internal_type','invalid_data',
      'access_changed','state_changed','verification_unavailable','code_error','unconfirmed'].includes(diagnostic?.code)) {
      rows.push([checkLabel,diagnostic.code]);
    }
    if (/^[A-Z0-9]{5}$/.test(diagnostic?.sqlstate || '')) {
      const code = Number.isSafeInteger(diagnostic.database_code) && diagnostic.database_code >= 1 && diagnostic.database_code <= 65535
        ? ' / ' + diagnostic.database_code : '';
      rows.push([databaseLabel,diagnostic.sqlstate + code]);
    }
    return rows;
  }

  function initiativeDebugTime(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{3})?(?:Z|[+-]\d\d:\d\d)$/.test(value)) return '—';
    const date = new Date(value);
    return Number.isFinite(date.getTime()) ? date.toLocaleString(undefined,{dateStyle:'medium',timeStyle:'long'}) : '—';
  }

  function browserVoiceDebugSnapshot() {
    try {
      const snapshot = window.VP3VoiceDiagnostics?.snapshot?.();
      const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
      const queue = chat?.proactiveVoiceStatus?.();
      return snapshot || queue ? {...snapshot,queue} : null;
    } catch (_error) {
      return null;
    }
  }

  function browserVoiceTraceLine(record, includeMessage = true) {
    const state = record?.state;
    if (typeof state !== 'string' || !/^[a-z][a-z0-9_]{0,47}$/.test(state)) return '';
    const label = record.code === 'post_expired' ? 'Expired' : state[0].toUpperCase() + state.slice(1).replaceAll('_',' ');
    const detail = typeof record.detail === 'string' ? record.detail.replace(/[\x00-\x1f\x7f]/g,' ').trim().slice(0,240) : '';
    const message = includeMessage && Number.isSafeInteger(record.messageId) && record.messageId > 0 ? `Message ${record.messageId}` : '';
    const at = initiativeDebugTime(record.at);
    return [label,detail,message,at === '—' ? '' : at].filter(Boolean).join(' · ');
  }

  function browserVoicePending(snapshot) {
    const pending = snapshot?.queue?.pending;
    return Array.isArray(pending) ? pending.slice(0,8).filter(row =>
      Number.isSafeInteger(row?.messageId) && row.messageId > 0 && ['queued','waiting','retrying'].includes(row.state)
    ) : [];
  }

  function browserVoiceQueueLine(row) {
    const attempts = Number.isSafeInteger(row.attempts) && row.attempts > 0 ? row.attempts : 0;
    const reason = typeof row.reason === 'string' ? window.VP3VoiceDiagnostics?.describe?.(row.reason) : '';
    const state = attempts || row.state === 'retrying' ? 'Retry pending' : row.state === 'waiting' ? 'Held' : 'Queued';
    const expires = Number.isSafeInteger(row.expiresAt) && row.expiresAt > 0 && row.expiresAt < Date.now() + 300000
      ? new Date(row.expiresAt).toLocaleTimeString() : '';
    return [state,reason,attempts ? `Retries ${attempts}` : '',expires ? `Expires ${expires}` : ''].filter(Boolean).join(' · ');
  }

  function browserVoiceForMessage(snapshot,messageId) {
    if (!Number.isSafeInteger(messageId) || messageId < 1) return '';
    const pending = browserVoicePending(snapshot).find(row => row.messageId === messageId);
    if (pending) return browserVoiceQueueLine(pending);
    const post = Array.isArray(snapshot?.posts) ? snapshot.posts.find(row => row?.messageId === messageId) : null;
    const trace = post?.announcement?.at(-1) || (Array.isArray(snapshot?.timeline?.announcement)
      ? snapshot.timeline.announcement.filter(record => record?.messageId === messageId).at(-1) : null);
    if (snapshot?.queue?.activeMessageId === messageId && !['starting','speaking','completed'].includes(trace?.state)) return 'Voice turn in progress';
    const label = browserVoiceTraceLine(trace,false);
    return label && ['queued','waiting','retrying'].includes(trace?.state) ? 'Last observed · ' + label : label;
  }

  function browserVoiceDebugLines(snapshot) {
    const timeline = snapshot?.timeline;
    const replies = Array.isArray(timeline?.reply) ? timeline.reply.slice(-6) : [];
    const messageId = snapshot?.announcement?.messageId;
    const proactiveReplies = replies.filter(entry => messageId > 0 && entry?.messageId === messageId);
    const greetingReplies = replies.filter(entry => entry && !entry.messageId);
    const channels = [
      ['Page Auto Greet',snapshot?.greeting,timeline?.greeting],
      ['Proactive announcement',snapshot?.announcement,timeline?.announcement],
      ['Voice reply window',proactiveReplies.at(-1),proactiveReplies]
    ];
    if (greetingReplies.length) channels.splice(1,0,['Page greeting reply window',greetingReplies.at(-1),greetingReplies]);
    const lines = ['Voice in this browser'];
    for (const [label,record,events] of channels) {
      const latest = browserVoiceTraceLine(record);
      const unavailable = snapshot ? 'No attempt recorded.' : 'Browser trace unavailable.';
      lines.push(`${label}: ${latest || unavailable}`);
      if (!Array.isArray(events)) continue;
      const previous = events.slice(-6).map(record => browserVoiceTraceLine(record)).filter(entry => entry && entry !== latest);
      for (const entry of previous.slice(-3)) lines.push(`  Earlier: ${entry}`);
    }
    const pending = browserVoicePending(snapshot);
    const active = snapshot?.queue?.activeMessageId;
    const activeMessage = Number.isSafeInteger(active) && active > 0 ? active : null;
    if (snapshot?.queue) {
      lines.push(`Announcement queue: ${pending.length} waiting${activeMessage ? ` · Active message ${activeMessage}` : ''}`);
      for (const row of pending) lines.push(`  Message ${row.messageId}: ${browserVoiceQueueLine(row)}`);
    }
    const posts = Array.isArray(snapshot?.posts) ? snapshot.posts.slice(-4).reverse() : [];
    if (posts.length) {
      lines.push('Recent browser voice traces by Chat message');
      for (const post of posts) {
        if (!Number.isSafeInteger(post?.messageId) || post.messageId < 1) continue;
        const announcement = browserVoiceTraceLine(post.announcement?.at(-1),false);
        const reply = browserVoiceTraceLine(post.reply?.at(-1),false);
        if (announcement) lines.push(`  Message ${post.messageId}: ${announcement}`);
        if (reply) lines.push(`  Message ${post.messageId} reply: ${reply}`);
        const earlier = browserVoiceTraceLine(post.announcement?.at(-2),false);
        if (earlier) lines.push(`    Earlier: ${earlier}`);
      }
    }
    return lines;
  }

  function debugPostIdentity(post) {
    if (!Number.isSafeInteger(post?.message_id) || post.message_id < 1 || initiativeDebugTime(post.at) === '—') return '';
    const source = initiativeEngagementLabels.specialist[post.specialist] || initiativeEngagementLabels.post_source[post.source];
    const run = Number.isSafeInteger(post.run_id) && post.run_id > 0 ? `Run ${post.run_id}` : '';
    const type = initiativeEngagementLabels.post_result[post.result];
    return [typeof source === 'string' ? source : '',typeof type === 'string' ? type : '',run,
      `Message ${post.message_id}`,initiativeDebugTime(post.at)].filter(Boolean).join(' · ');
  }

  function browserChatCanvasSnapshot(engagement) {
    try {
      const recent = Array.isArray(engagement?.recent_posts) ? engagement.recent_posts.slice(0,5) : [];
      const ids = [...recent.filter(row => row?.delivery === 'posted').map(row => row.message_id),engagement?.last_post?.message_id]
        .filter(id => Number.isSafeInteger(id) && id > 0);
      const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
      const status = chat?.chatCanvasStatus?.(Array.from(new Set(ids)).slice(0,5));
      return status?.view === 'chat' || status?.view === 'other' ? status : null;
    } catch (_error) {
      return null;
    }
  }

  function browserChatCanvasPost(canvas,messageId) {
    if (!canvas) return '';
    if (canvas.view !== 'chat') return 'Canvas not checked while another view is open';
    if (canvas.ready !== true) return 'Canvas is still loading this Chat';
    if (!Array.isArray(canvas.renderedMessageIds)) return 'Canvas render could not be checked';
    return canvas.renderedMessageIds.includes(messageId) ? 'Rendered in this browser' : 'Absent from this browser’s loaded Chat';
  }

  function browserGmailDigestStatus(canvas,post) {
    const id = post?.message_id;
    if (post?.silent_digest !== true || canvas?.view !== 'chat' || canvas.ready !== true
        || !Number.isSafeInteger(id) || id < 1 || !canvas.renderedMessageIds?.includes?.(id)) return '';
    const conversation = liveConversation();
    if (!Number.isSafeInteger(conversation) || conversation < 1) return '';
    const thread = document.getElementById('chatThread');
    const row = thread?.querySelector(`[data-chat-conversation-id="${conversation}"][data-chat-message-id="${id}"][data-chat-message-role="assistant"]`);
    if (!row) return '';
    const items = [...row.querySelectorAll('[data-gmail-routine-digest="true"] .gmail-review-items > li')]
      .filter(item => !item.hidden && item.getClientRects().length > 0);
    if (!items.length) return 'Gmail digest rows missing from this browser';
    const previews = items.filter(item => item.querySelector('.gmail-review-item-preview')?.getClientRects().length).length;
    const replies = items.filter(item => item.querySelector('[data-gmail-review-action="reply"]')?.getClientRects().length).length;
    return `Gmail digest shows ${items.length} ${items.length === 1 ? 'email' : 'emails'}, ${previews} ${previews === 1 ? 'preview' : 'previews'}, ${replies} Reply ${replies === 1 ? 'button' : 'buttons'}`;
  }

  function browserChatCanvasLines(canvas,post) {
    if (!canvas) return [];
    const lines = [];
    const view = canvas.view === 'other' ? 'Another view is open' : canvas.ready === true ? 'Ready' : 'Loading this Chat';
    const rendered = post ? browserChatCanvasPost(canvas,post.message_id) : '';
    lines.push('Chat Canvas: ' + [view,canvas.busy === true ? 'Busy' : '',
      rendered && canvas.view === 'chat' && canvas.ready === true ? `Message ${post.message_id} · ${rendered}` : ''].filter(Boolean).join(' · '));
    const ids = [canvas.knownLatestMessageId,post?.message_id].filter(id => Number.isSafeInteger(id) && id > 0);
    const server = ids.length ? `Server reported Message ${Math.max(...ids)}` : 'Server cursor not observed';
    const browser = Number.isSafeInteger(canvas.observedThrough) && canvas.observedThrough > 0
      ? `Browser read through Message ${canvas.observedThrough}` : 'Browser cursor not observed';
    lines.push(`Chat Canvas cursor: ${server} · ${browser}`);
    const sync = canvas.lastSync;
    const states = {reading:'Reading',complete:'Read completed',failed:'Read failed'};
    const reasons = {network:'Network error',timeout:'Timed out',response:'Invalid response',server:'Server error'};
    if (Object.hasOwn(states,sync?.state)) {
      const at = initiativeDebugTime(sync.at);
      const reason = sync.state === 'failed' && Object.hasOwn(reasons,sync.reason) ? reasons[sync.reason] : '';
      lines.push('Latest Canvas sync: ' + [states[sync.state],reason,at === '—' ? '' : at].filter(Boolean).join(' · '));
    } else lines.push('Latest Canvas sync: No read recorded in this browser');
    return lines;
  }

  function savedPostVoiceLabel(post) {
    const labels = {claimed:'Claim saved. Waiting for playback confirmation.',spoken:'Playback reported',suppressed:'Suppressed'};
    if (!Object.hasOwn(labels,post.voice_status)) return '';
    if (post.voice_status !== 'suppressed') return labels[post.voice_status];
    const reason = window.VP3VoiceDiagnostics?.describe?.(post.voice_suppression_reason);
    return 'Suppressed — ' + (reason || 'No reason was saved.');
  }

  function verifiedChatPostLines(engagement,browserCanvas) {
    if (!Array.isArray(engagement?.recent_posts)) return [];
    const sections = [];
    const replies = {open:'Awaiting your reply',discussed:'Reply recorded',deferred:'Follow-up deferred',
      queued:'Follow-up queued',stale:'Finding changed',declined:'Declined',owner_resolved:'Marked resolved by owner',completed:'Follow-up completed'};
    for (const post of engagement.recent_posts.slice(0,5)) {
      const identity = debugPostIdentity(post);
      if (!identity || post.delivery !== 'posted') continue;
      const proof = post.proof === 'receipt' ? 'Receipt verified' : post.proof === 'saved_message' ? 'Saved message verified' : '';
      const receipt = savedPostVoiceLabel(post);
      const voice = receipt ? 'Voice receipt — ' + receipt
        : post.silent_digest === true ? 'Routine Gmail digest is silent'
        : post.voice_eligible === false ? 'Automatic voice unavailable for this post' : 'Voice receipt — No voice receipt recorded';
      const voiceAt = initiativeDebugTime(post.voice_at);
      const reply = Object.hasOwn(replies,post.reply_state) ? replies[post.reply_state] : '';
      sections.push([identity,proof,browserChatCanvasPost(browserCanvas,post.message_id),browserGmailDigestStatus(browserCanvas,post),voice,
        voiceAt === '—' ? '' : voiceAt,reply].filter(Boolean).join(' · '));
    }
    return ['Recent verified Chat posts',...(sections.length ? sections : ['No recent delivery was verified in this Chat.'])];
  }

  function initiativeDeliverySummary(assessmentRows,engagement,browserVoice,browserCanvas) {
    const lines = ['Initiative at a glance'];
    const assessment = assessmentRows.find(([key]) => key === 'Latest assessment')?.[1];
    const run = assessmentRows.find(([key]) => key === 'Assessment run')?.[1];
    if (assessment) lines.push('Initiative assessment: ' + [assessment,run ? `Run ${run}` : ''].filter(Boolean).join(' · '));
    const recent = engagement?.recent_posts;
    const post = Array.isArray(recent) ? recent.find(row => row?.delivery === 'posted' && debugPostIdentity(row)) : engagement?.last_post;
    const identity = debugPostIdentity(post);
    if (identity) {
      lines.push('Latest verified Chat text: ' + identity);
      const trace = browserVoiceForMessage(browserVoice,post.message_id);
      const receipt = savedPostVoiceLabel(post);
      if (receipt) lines.push(`Saved voice receipt for Message ${post.message_id}: ${receipt}`);
      if (trace || !receipt) lines.push(`Browser voice for Message ${post.message_id}: ${trace
        || (browserVoice ? 'No voice attempt recorded for this message in this browser.' : 'Browser voice trace unavailable.')}`);
    } else {
      lines.push('Latest verified Chat text: ' + (engagement?.post_checked === true ? 'None verified in this snapshot' : 'Could not verify'));
    }
    lines.push(...browserChatCanvasLines(browserCanvas,identity ? post : null));
    const browser = browserVoice?.queue?.browser;
    if (typeof browser?.focused === 'boolean') {
      const draft = {restored:'Restored draft in composer',present:'Draft in composer',empty:'Composer empty'};
      const reason = typeof browser.waitReason === 'string' ? window.VP3VoiceDiagnostics?.describe?.(browser.waitReason) : '';
      const view = [browser.focused ? 'Agent Chat focused' : 'Agent Chat lacks focus',
        Object.hasOwn(draft,browser.draft) ? draft[browser.draft] : '',
        browser.manualListening === true ? 'Listening active' : '',reason].filter(Boolean);
      lines.push('Browser now: ' + view.join(' · '));
    }
    if (engagement?.gate === 'ready' && (browserVoicePending(browserVoice).length
      || ['chat_not_focused','draft_in_composer','chat_busy','another_announcement','reply_selected'].includes(browser?.waitReason))) {
      lines.push('Chat text delivery can proceed while the browser holds voice.');
    }
    return lines;
  }

  function browserEyesDebugSnapshot(conversationId) {
    try {
      const capture = window.VP3AgentEyes?.diagnostics?.();
      return capture?.owner_user_id === pageChatOwner && capture.agent_id === pageAgent
        && (!capture.conversation_id || capture.conversation_id === conversationId) ? capture : null;
    } catch (_error) {
      return null;
    }
  }

  function eyesDebugLines(capture, status, voice) {
    if (!capture && !status) return [];
    const captureLabels = {
      off:'Camera off',starting:'Starting camera',no_samples:'No recognition samples on this browser',
      uncertain:'Looking for a stable participant match',local_match:'Provisional local match',
      arrival_detected:'Arrival detected',history_saved:'Scoped arrival saved'
    };
    const visitLabels = {first:'First observed visit',continuing:'Continued presence',return:'Returning visit'};
    const decisionLabels = {no_visit:'No verified current visit',unavailable:'Visit decision could not be verified',
      greetings_off:'Eyes arrival greetings are off',pending:'Waiting for Top of Mind assessment',
      held:'Holding this visit update',quiet:'No Chat update selected',queued:'Queued for Chat',
      working:'Preparing a Chat update',posted:'Verified post in Chat',withheld:'Withheld during verification'};
    const voiceLabels = {pending:'Waiting for voice',played:'Playback confirmed',skipped:'Voice skipped',
      failed:'Playback failed',unavailable:'No verified voice delivery'};
    const id = value => Number.isSafeInteger(value) && value > 0;
    const lines = ['Agent Eyes in this Chat'];
    if (Object.hasOwn(captureLabels,capture?.state)) {
      lines.push(`Camera: ${captureLabels[capture.state]}`);
      if (capture.state !== 'off') lines.push(`Camera to Chat: ${capture.chat_linked === true && id(capture.conversation_id)
        ? 'Linked to this Chat' : capture.waiting_for_chat === true ? 'Waiting for saved Chat' : 'Local to this browser'}`);
    }
    if (Object.hasOwn(visitLabels,status?.visit_state)) {
      lines.push(`Current visit: ${visitLabels[status.visit_state]}`);
      const at = initiativeDebugTime(status.observed_at);
      if (at !== '—') lines.push(`Visit observed: ${at}`);
    }
    if (Object.hasOwn(decisionLabels,status?.decision)) {
      const posted = status.decision === 'posted' && id(status.message_id);
      const decision = status.decision === 'posted' && !posted ? 'unavailable' : status.decision;
      const delivery = [decisionLabels[decision]];
      if (id(status.run_id)) delivery.push(`Run ${status.run_id}`);
      if (posted) delivery.push(`Message ${status.message_id}`);
      lines.push(`Top of Mind: ${delivery.join(' · ')}`);
      if (posted) {
        const timeline = Array.isArray(voice?.timeline?.announcement) ? voice.timeline.announcement : [];
        const recorded = timeline.filter(entry => entry?.messageId === status.message_id).at(-1);
        const localOutcome = recorded?.state === 'completed' && recorded.detail === 'The Chat alert finished. The full post is in Chat.'
          ? 'Chat alert played. The full post is in Chat.'
          : recorded?.state === 'completed' && recorded.detail === 'Playback finished.' ? 'Playback finished in this browser.' : '';
        const outcome = localOutcome || (Object.hasOwn(voiceLabels,status.voice) ? voiceLabels[status.voice] : voiceLabels.unavailable);
        lines.push(`Visit voice: ${outcome} · Message ${status.message_id}`);
      }
    }
    return lines;
  }

  const debugFailureText = value => typeof value === 'string' ? value.replace(/[\x00-\x1f\x7f]/g,' ').trim().slice(0,240) : '';

  function debugWorkflowFailureRows(failure) {
    const rows = [['Run',failure.run_id]];
    if (debugDashboardId(failure.action_id)) rows.push(['Action',failure.action_id]);
    const step = debugDashboardLabel('step',failure.step);
    if (step && step !== failure.stage) rows.push(['Assigned step',step]);
    for (const [label,key] of [['Failed step','stage'],['Execution phase','phase'],['Recorded reason','reason']]) {
      const value = debugFailureText(failure[key]);
      if (value) rows.push([label,value]);
    }
    const source = initiativeFailureSource(failure);
    if (source) rows.push(['Source family',source.label],['Source coverage',source.coverage]);
    for (const [label,key] of [['Code','reason_code'],['Run status','status'],['Action status','action_status'],['Executor','executor'],
      ['Evidence','evidence'],['AI diagnostic','model_diagnostic']]) {
      if (/^[a-z][a-z0-9_-]{0,63}$/.test(failure[key] || '')) rows.push([label,failure[key]]);
    }
    if (['invalid_json','object_required','unexpected_fields','invalid_disposition','missing_draft','invalid_draft','output_too_large','response_empty'].includes(failure.office_report_error)) {
      rows.push(['Office report rejection',failure.office_report_error]);
    }
    if (['openai','anthropic'].includes(failure.model_provider)) {
      rows.push(['Recorded AI provider',failure.model_provider === 'openai' ? 'OpenAI' : 'Anthropic']);
    }
    if (['unconfirmed','rejected','response_recorded','recorded'].includes(failure.model_usage_state)) {
      rows.push(['Provider attempt evidence',failure.model_usage_state]);
    }
    if (Number.isSafeInteger(failure.model_http_status) && failure.model_http_status >= 100 && failure.model_http_status <= 599) {
      rows.push(['Provider HTTP status',failure.model_http_status]);
    }
    if (Number.isSafeInteger(failure.attempt) && Number.isSafeInteger(failure.max_attempts)) {
      rows.push(['Attempt',`${failure.attempt} of ${failure.max_attempts}`]);
    }
    const at = initiativeDebugTime(failure.at), retry = initiativeDebugTime(failure.retry_at);
    if (at !== '—') rows.push(['Recorded at',at]);
    if (failure.status === 'failed') rows.push(['Retry status','Run ended. No retry scheduled.']);
    if (failure.status === 'approved' && retry !== '—') rows.push(['Retry scheduled for',retry]);
    return rows;
  }

  function debugWorkflowFailures(snapshot) {
    const failures = snapshot?.engagement?.workflow_failures;
    if (!Array.isArray(failures)) return [];
    const titles = new Map(debugSpecialists);
    return failures.slice(0,16).filter(failure => debugDashboardId(failure?.run_id) && titles.has(failure.specialist)).map(failure => ({
      specialist:failure.specialist,title:`${titles.get(failure.specialist)} · Run ${failure.run_id}`,pairs:debugWorkflowFailureRows(failure)
    }));
  }

  function initiativeDebugReport(health, snapshot, readStatus, browserVoice, browserEyes, browserCanvas, signals = null) {
    const voiceLines = browserVoiceDebugLines(browserVoice);
    const eyesLines = eyesDebugLines(browserEyes,snapshot?.eyes,browserVoice);
    if (!health && !readStatus) return {current:['Waiting for verified status for this Chat.','',...browserChatCanvasLines(browserCanvas,null),
      '',...eyesLines,'',...voiceLines].join('\n'),history:''};
    health = health || {};
    const known = (field, value) => initiativeDebugValues[field].includes(value) ? value : '—';
    const time = initiativeDebugTime;
    const runtimeUnavailable = health.state === 'unavailable' || !initiativeDebugValues.state.includes(health.state);
    const policyLabels = {paused:'Off',off:'Off',starting:'Starting',linked_elsewhere:'Linked to another Chat'};
    const policyLabel = Object.hasOwn(policyLabels,health.state) ? policyLabels[health.state]
      : health.enabled === true ? 'On' : 'Could not verify';
    const workerState = known('runner_state',health.runner_state);
    const rows = [
      ['State',known('state',health.state)],
      ['Reason',known('reason',health.reason)],
      ['Initiative',policyLabel],
      ['Route',known('route',health.route)],
      ['Execution ready',health.execution_ready === true ? 'Yes' : runtimeUnavailable ? 'Could not verify' : 'No'],
      ['Server worker',workerState === 'unknown' || (runtimeUnavailable && ['never_seen','—'].includes(workerState))
        ? 'Could not verify' : workerState],
      ['Last worker check-in',time(health.runner_last_success_at)],
      ['Sources last checked',time(health.last_checked_at)],
      ['Last execution',time(health.last_execution_at)]
    ];
    rows.push(...initiativeDebugCheckRows(health.runtime_diagnostic,'Runtime status check','Runtime database code'));
    if (initiativeDebugValues.policy_reason.includes(health.policy_reason)) rows.push(['AI service policy',health.policy_reason]);
    if (/^[a-z][a-z0-9_-]{0,63}$/.test(health.execution_diagnostic?.code || '')) {
      rows.push(['Current AI route check',health.execution_diagnostic.code]);
    }
    const retryAt = time(health.retry_due_at);
    if (retryAt !== '—') rows.push(['Retry due',retryAt]);
    const currentRows = [];
    const historyRows = [];
    const assessment = health.last_assessment;
    const latestAssessment = health.latest_assessment_run;
    const assessmentUnavailable = runtimeUnavailable || health.assessment_read === 'unavailable';
    const verified = initiativeDebugValues.outcome.includes(assessment?.outcome) && time(assessment.at) !== '—';
    const latest = verified && assessment.run_id === latestAssessment?.run_id && latestAssessment?.status === 'completed';
    const runId = value => Number.isSafeInteger(value) && value > 0;
    const statuses = {
      failed:'Failed',retry_scheduled:'Retry scheduled',running:'Working',queued:'Queued',
      interrupted:'Claim expired',paused:'Paused',cancelled:'Cancelled',unverified:'Completion could not be verified',
      sources_changed:'Sources changed. Waiting for a fresh assessment.'
    };
    if (assessmentUnavailable) {
      currentRows.push(['Latest assessment','Could not verify']);
      currentRows.push(...initiativeDebugCheckRows(health.assessment_diagnostic,'Assessment status check','Assessment database code'));
    } else if (verified && (latest || !latestAssessment)) {
      currentRows.push(['Latest assessment','Succeeded']);
      if (runId(assessment.run_id)) currentRows.push(['Assessment run',assessment.run_id]);
    } else if (runId(latestAssessment?.run_id) && Object.hasOwn(statuses,latestAssessment.status)) {
      const status = latestAssessment.status === 'failed' && latestAssessment.reason_code === 'assessment_sources_changed'
        ? 'sources_changed' : latestAssessment.status;
      currentRows.push(['Latest assessment',statuses[status]],['Assessment run',latestAssessment.run_id]);
      if (time(latestAssessment.at) !== '—') currentRows.push(['Assessment updated',time(latestAssessment.at)]);
      if (time(latestAssessment.retry_at) !== '—') currentRows.push(['Assessment retry due',time(latestAssessment.retry_at)]);
      if (/^[a-z][a-z0-9_-]{0,63}$/.test(latestAssessment.reason_code || '')
        && health.failure_detail?.run_id !== latestAssessment.run_id) currentRows.push(['Assessment code',latestAssessment.reason_code]);
    } else {
      currentRows.push(['Latest assessment',health.enabled ? 'No verified assessment recorded' : policyLabel === 'Off' ? 'Paused' : policyLabel]);
    }
    if (verified) {
      if (assessmentUnavailable || (!latest && latestAssessment)) {
        currentRows.push(['Last successful assessment',runId(assessment.run_id) ? `Run ${assessment.run_id}` : 'Succeeded']);
      }
      currentRows.push(['Plan verified at',time(assessment.at)]);
      if (Number.isSafeInteger(assessment.queued) && assessment.queued >= 0 && assessment.queued <= 8) {
        currentRows.push(['Jobs queued by this assessment',assessment.queued]);
      }
      if (assessment.outcome === 'idle') currentRows.push(['Assessment result','No new work identified']);
      if (assessment.outcome === 'existing_assignments') currentRows.push(['Assessment result','Work was already assigned']);
      if (Number.isSafeInteger(assessment.opportunities_discarded) && assessment.opportunities_discarded > 0
        && assessment.opportunities_discarded <= 8) {
        currentRows.push(['Invalid optional ideas discarded',assessment.opportunities_discarded]);
      }
      if (Number.isSafeInteger(assessment.activity_sources_omitted) && assessment.activity_sources_omitted > 0
        && assessment.activity_sources_omitted <= 8) {
        currentRows.push(['Outdated Recent activity items skipped',assessment.activity_sources_omitted]);
      }
    }
    const rejection = health.assessment_error;
    if (initiativeDebugValues.rejection.includes(rejection?.code)) {
      const historical = rejection.is_historical === true || latestAssessment && rejection.run_id !== latestAssessment.run_id;
      const target = historical ? historyRows : currentRows;
      target.push([historical ? 'Earlier assessment rejection' : 'Assessment rejection',rejection.code],
        ['Plan rejected at',time(rejection.at)]);
      if (Number.isSafeInteger(rejection.output_bytes) && rejection.output_bytes >= 0 && rejection.output_bytes <= 65536) {
        target.push(['Planner response bytes',rejection.output_bytes]);
      }
    }
    const failure = health.failure_detail;
    const failureRows = [];
    const supersededAssessment = failure?.stage === 'Assessment' && failure.reason_code === 'assessment_sources_changed';
    const assessmentFailure = failure?.run_id === latestAssessment?.run_id
      && ['failed','retry_scheduled'].includes(latestAssessment?.status)
      && latestAssessment.reason_code !== 'assessment_sources_changed';
    const currentFailure = !supersededAssessment && runId(failure?.run_id) && (assessmentFailure
      || failure.is_latest_run === true && (failure.stage !== 'Assessment' || !latestAssessment));
    if (runId(failure?.run_id)) {
      failureRows.push(supersededAssessment ? ['Recorded assessment','Superseded']
        : ['Recorded failure',currentFailure ? 'Latest run' : 'Earlier run']);
      failureRows.push(...debugWorkflowFailureRows(failure));
    }
    const format = ([label,value]) => `${label}: ${value}`;
    const lines = [];
    if (snapshot) {
      const release = /^[a-f0-9]{12}$/.test(snapshot.release || '') ? snapshot.release : 'No release manifest';
      lines.push(`Deployed build: ${release}`,`Snapshot taken: ${time(snapshot.observed_at)}`,'');
    }
    lines.push(...initiativeDeliverySummary(currentRows,snapshot?.engagement,browserVoice,browserCanvas));
    lines.push('','Current Initiative',...currentRows.map(format));
    if (currentFailure) lines.push('','Current failure',...failureRows.map(format));
    if (snapshot) {
      const currentWork = debugSpecialists.map(([id,name]) => ({name,signal:signals?.get(id)}))
        .filter(({signal}) => signal?.details?.length);
      if (currentWork.length) {
        lines.push('','Specialist work');
        for (const {name,signal} of currentWork) lines.push('',`${name} · ${signal.label}`,...signal.details.map(format));
      }
      if (snapshot.engagement && typeof snapshot.engagement === 'object') {
        lines.push('','Current Chat engagement',...initiativeEngagementRows(snapshot.engagement,time).map(format));
        const assignments = snapshot.engagement.specialist_assignments;
        if (assignments && typeof assignments === 'object') {
          const statusRows = [];
          for (const [id,name] of debugSpecialists) {
            const assignment = assignments[id];
            const status = debugDashboardLabel('status',assignment?.status)
              || (assignment?.status === 'unavailable' ? 'Could not verify' : '');
            if (!status || !debugDashboardId(assignment?.run_id)) continue;
            const step = debugDashboardLabel('step',assignment.step);
            statusRows.push([name,[status,`Run ${assignment.run_id}`,step].filter(Boolean).join(' · ')]);
          }
          if (statusRows.length) lines.push('','Verified specialist assignments',...statusRows.map(format));
        }
        const recentPosts = verifiedChatPostLines(snapshot.engagement,browserCanvas);
        if (recentPosts.length) lines.push('',...recentPosts);
      }
      const marketing = marketingDebugDetails(snapshot.marketing);
      if (marketing) {
        lines.push('','Marketing campaign reports',...marketing.pairs.map(format));
        for (const group of marketing.groups) lines.push('',group.title,...group.pairs.map(format));
      }
      const failures = debugWorkflowFailures(snapshot);
      if (failures.length) {
        lines.push('','Recent workflow failures');
        for (const group of failures) lines.push('',group.title,...group.pairs.map(format));
      }
    }
    if (eyesLines.length) lines.push('',...eyesLines);
    lines.push('',...voiceLines);
    if (snapshot) {
      lines.push('','Gmail pipeline');
      const stages = Array.isArray(snapshot.gmail?.stages) ? snapshot.gmail.stages.slice(0,5) : [];
      for (const stage of stages) {
        const label = debugFailureText(stage?.label), detail = debugFailureText(stage?.detail);
        if (label && detail) lines.push(`${label} [${debugFailureText(stage.state) || 'unknown'}]: ${detail}`);
        if (/^[a-z][a-z0-9_]{0,63}$/.test(stage?.code || '')) lines.push(`  Code: ${stage.code}`);
      }
      if (!stages.length) lines.push('Gmail status unavailable.');
      if (debugFailureText(snapshot.gmail?.next_step)) lines.push(`Next step: ${debugFailureText(snapshot.gmail.next_step)}`);
    }
    lines.push('','Runtime details',...rows.map(format));
    if (readStatus) lines.unshift(readStatus,'');
    const history = [currentFailure ? '' : failureRows.map(format).join('\n'),historyRows.map(format).join('\n')]
      .filter(Boolean).join('\n\n');
    return {current:lines.join('\n'),history};
  }

  const debugSpecialists = [
    ['gmail','Gmail'],['planning','Planning & Scheduling'],['tasks','Tasks & Lists'],
    ['transcripts','Transcriptions'],['media','Media & Entertainment'],['profile','Profile'],['curiosity','Curiosity'],
    ['connected-sites','Connected Sites'],['canvas','Canvas Apps'],['research','Research'],
    ['coding','Coding'],['image-generator','Image Generator'],['mission','Missions'],
    ['marketing','Marketing'],['commerce','Commerce'],['development','Agent Development'],['app-suggestions','App Suggestions'],
    ['eyes','Agent Eyes'],['operator','Operator'],['brainstorm','ob1']
  ];
  const debugOpportunityLanes = {research:'research',mission:'missions','app-suggestions':'app_suggestions','image-generator':'media_images'};
  const debugCoverage = {ready:'Checked',limited:'Partially checked',unconfigured:'Source inactive',unavailable:'Could not check'};
  const debugHandoff = {working:'Preparing for Chat',queued:'Queued for Chat',waiting:'Ready for delivery',
    posted:'Posted in Chat',skipped:'Already handled',blocked:'Needs attention',unverified:'Delivery unverified'};
  const debugWorkTone = {executing:'working',queued:'working',approved:'working',failed:'warning',interrupted:'warning',
    unavailable:'warning',paused:'muted',cancelled:'muted',completed:'ready'};
  const debugActivityColors = {
    gmail:'#bf3b36',planning:'#82501b',tasks:'#2459a4',transcripts:'#7441aa',media:'#197254',profile:'#35647e',curiosity:'#a13e75',
    'connected-sites':'#11747e',canvas:'#5148a2',research:'#2c6c81',coding:'#38632c','image-generator':'#b04724',
    mission:'#59486c',marketing:'#ae4c5a',commerce:'#775919',development:'#46556d','app-suggestions':'#675cc1',eyes:'#256d56',operator:'#316a82',brainstorm:'#176984',
  };
  const debugActivityLine = {
    flat:'M0 13 H96',waiting:'M0 13 H36 L40 11 L44 15 L48 13 H96',
    working:'M0 13 H12 L17 11 L20 15 L25 4 L30 22 L34 10 L40 13 H55 L59 11 L62 16 L67 5 L72 21 L76 10 L81 13 H96',
  };
  const debugRefreshInterval = 12000;
  const debugSnapshotMaxAge = 40000;
  const debugActionableSpecialists = new Set(['gmail','planning','tasks','media','brainstorm']);
  const debugJobPhases = {analyzing:'Analyzing',researching:'Researching',brainstorming:'Brainstorming',coordinating:'Coordinating',
    presenting:'Presenting',reviewing:'Reviewing',preparing:'Preparing',monitoring:'Monitoring'};
  const debugJobDestinations = {chat:'Agent Chat',top_of_mind:'Top of Mind',private_preparation:'Private preparation',
    canvas_draft:'Canvas draft',canvas_proposal:'Canvas proposal',image_proposal:'Image proposal'};
  const debugJobTone = {working:'working',waiting:'muted',checked:'ready',stale:'warning',error:'warning',inactive:'muted'};
  const debugActivityDate = value => typeof value === 'string'
    && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{3})?(?:Z|[+-]\d\d:\d\d)$/.test(value) ? Date.parse(value) : NaN;
  const debugReviewNotes = {candidate:'Question found and waiting for selection', ready:'Ready for Chat selection',
    higher_priority:'Higher-priority work is queued',report_only:'Available in the specialist report',
    awaiting_reply:'An earlier question is open',no_question:'No question was ready',
    handoff_pending:'A Chat handoff is already queued',no_playable_sources:'No playable item was found',
    verification_unavailable:'Delivery conditions could not be verified'};
  const debugDashboardLabel = (group,value) => Object.hasOwn(initiativeEngagementLabels[group],value) ? initiativeEngagementLabels[group][value] : '';
  const debugDashboardId = value => Number.isSafeInteger(value) && value > 0;

  function debugActivityClock(current) {
    const observed = current.activityObservation;
    if (!observed) return null;
    const age = Math.max(0,performance.now() - observed.started);
    return {now:observed.serverAt+age,age};
  }

  function debugActivitySignals(current) {
    const clock = debugActivityClock(current);
    return new Map(debugSpecialists.map(([id]) => [id,debugSpecialistActivity(current,id,clock)]));
  }

  function debugProjectedJob(job,clock) {
    if (!job || !Object.hasOwn(debugJobPhases,job.phase)) return null;
    const phase = debugJobPhases[job.phase];
    const work = debugFailureText(job.action).slice(0,120);
    const at = debugActivityDate(job.at);
    const expiry = debugActivityDate(job.expires_at);
    if (!work || (Number.isFinite(at) && at > clock.now + 1000)) return null;
    const message = debugDashboardId(job.message_id) ? `Message ${job.message_id}` : '';
    const destination = Object.hasOwn(debugJobDestinations,job.destination) && !(job.state === 'completed' && job.destination === 'chat' && !message)
      ? debugJobDestinations[job.destination] : '';
    const run = debugDashboardId(job.run_id) ? `Run ${job.run_id}` : '';
    const involved = Array.isArray(job.inputs) ? debugSpecialists.filter(([id]) => job.inputs.includes(id)).map(([,name]) => name) : [];
    const details = [['Current job',work],['Work phase',phase],['Work run',run],['Work destination',destination],['Work message',message],
      ['Next attempt',debugActivityDate(job.next_at) > clock.now ? initiativeDebugTime(job.next_at) : ''],
      ['Checkpoint observed',Number.isFinite(at) ? initiativeDebugTime(job.at) : ''],['Specialist inputs',involved.join(', ')]]
      .filter(([,value]) => value);
    const view = (state,label,expires = null) => ({state,label,expires,intensity:0,work,details});
    switch (job.state) {
      case 'working': return Number.isFinite(expiry)
        ? expiry > clock.now ? view('working',phase,expiry) : view('stale','Claim expired') : view('stale','Claim unverified');
      case 'queued': return view('waiting',job.phase === 'presenting' ? 'Delivery queued' : 'Queued');
      case 'waiting': return view('waiting',job.phase === 'presenting' ? 'Waiting to post' : 'Waiting for next step');
      case 'completed':
        if (job.destination === 'chat' && message) return view('checked','Posted in Chat');
        if (job.destination === 'top_of_mind') return view('checked','Saved to Top of Mind');
        if (job.destination === 'canvas_draft') return view('checked','Canvas draft saved');
        if (job.destination === 'canvas_proposal') return view('checked','Canvas proposal saved');
        if (job.destination === 'image_proposal') return view('checked','Image proposal saved');
        return view('checked','Completed');
      case 'monitoring': return Number.isFinite(at) && job.phase === 'monitoring' ? view('checked','Monitoring') : null;
      case 'failed': return view('error','Job failed');
      case 'interrupted': return view('stale','Claim expired');
      case 'paused': return view('inactive','Paused');
      default: return null;
    }
  }

  function debugSpecialistActivity(current,id,clock) {
    const view = (state,label,expires = null,intensity = 0) => ({state,label,expires,intensity});
    if(id==='operator'){
      const phone=window.VP3PhoneWorkspaceV1?.operatorDiagnostics?.(current.conversationId,pageAgent);
      if(!phone)return view('inactive','Phone status unavailable');
      return {...view(phone.working?'working':phone.registered?'checked':'waiting',phone.working?'Call in progress':phone.registered?'Browser registered':'Browser offline'),details:phone.pairs};
    }
    const {snapshot} = current;
    if (current.conversationId === 0) return view('inactive','Save this Chat');
    if (current.readFailed) return view('stale','Latest read unavailable');
    if (!snapshot || !clock) return view('inactive','Waiting for a snapshot');
    if (clock.age >= debugSnapshotMaxAge) return view('stale','Snapshot out of date');
    if (snapshot.initiative?.state === 'paused') return view('inactive','Paused');
    if (snapshot.initiative?.state === 'off') return view('inactive','Initiative off');
    if (snapshot.initiative?.state === 'linked_elsewhere') return view('inactive','Linked to another Chat');
    if (snapshot.initiative?.state === 'unavailable') return view('error','Runtime unavailable');
    const engagement = snapshot.engagement || {};
    const actionable = debugActionableSpecialists.has(id);
    const projectedJob = engagement.specialist_jobs?.[id];
    const projected = debugProjectedJob(projectedJob,clock);
    const direct = engagement.specialist_activity?.[id];
    const directExpiry = debugActivityDate(direct?.expires_at);
    const opportunity = engagement.opportunity_checks?.[debugOpportunityLanes[id]];
    const assignment = engagement.specialist_assignments?.[id] || opportunity?.assignment;
    const assignmentExpiry = debugActivityDate(assignment?.lease_expires_at);
    const jobs = Array.isArray(snapshot.initiative?.active_jobs) ? snapshot.initiative.active_jobs.filter(job =>
      (job?.specialist || (job?.kind === 'assessment' ? 'brainstorm' : null)) === id && Number.isSafeInteger(job.expiresAt)) : [];
    const liveJobs = jobs.filter(job => job.expiresAt > clock.now);
    const job = liveJobs[0];
    const handoff = engagement.specialist_handoffs?.[id];
    const scan = engagement.specialist_scan?.specialists?.[id];
    const directActive = ['scanning','working'].includes(direct?.state) && directExpiry > clock.now;
    const assignmentActive = assignment?.status === 'executing' && assignmentExpiry > clock.now;
    if (projected?.state === 'working' || directActive || assignmentActive || job) {
      const phases = {analyzing:'Analyzing',brainstorming:'Brainstorming',planning:'Planning'};
      const phase = Object.hasOwn(phases,job?.coordination_phase) ? phases[job.coordination_phase] : '';
      const label = directActive ? (direct.state === 'scanning' ? 'Scanning' : 'Analyzing')
        : phase || (job?.kind === 'assessment' ? 'Assessing' : 'Working');
      const ids = new Set(liveJobs.map(item => item.id).filter(id => typeof id === 'string' && /^workflow:[1-9]\d{0,17}$/.test(id)));
      if (assignmentActive && debugDashboardId(assignment.run_id)) ids.add(`workflow:${assignment.run_id}`);
      if (projected?.state === 'working' && debugDashboardId(projectedJob.run_id)) ids.add(`workflow:${projectedJob.run_id}`);
      const expiry = Math.min(directActive ? directExpiry : Infinity,assignmentActive ? assignmentExpiry : Infinity,
        projected?.state === 'working' ? projected.expires : Infinity,...liveJobs.map(item => item.expiresAt));
      const status = projected?.state === 'working' ? projected.label : label;
      const signal = view('working',ids.size > 1 ? `${status} · ${ids.size} active jobs` : status,expiry,Math.min(3,Math.max(1,ids.size)));
      return projected?.state === 'working' ? {...projected,...signal} : signal;
    }
    if (projected) return projected;
    if (id === 'brainstorm') {
      const assessment = snapshot.initiative?.latest_assessment_run;
      if (assessment?.status === 'queued') return view('waiting','Assessment queued');
      if (assessment?.status === 'retry_scheduled') return view('waiting','Assessment retry scheduled');
      if (assessment?.status === 'interrupted') return view('stale','Assessment claim expired');
      if (assessment?.status === 'failed') return assessment.reason_code === 'assessment_sources_changed'
        ? view('waiting','Sources changed') : view('error','Assessment failed');
      if (assessment?.status === 'unverified') return view('error','Assessment unverified');
      if (assessment?.status === 'paused') return view('inactive','Assessment paused');
    }
    if (assignment?.status === 'interrupted' || (assignment?.status === 'executing' && Number.isFinite(assignmentExpiry))
      || Number.isFinite(directExpiry) || jobs.length) {
      return view('stale','Claim expired');
    }
    if (assignment?.status === 'executing' || handoff?.state === 'working') return view('stale','Claim unverified');
    if (assignment?.status === 'paused') return view('inactive','Paused');
    if (['approved','queued'].includes(assignment?.status) || handoff?.state === 'queued') return view('waiting','Queued');
    if (handoff?.state === 'waiting') return view('waiting','Awaiting Chat delivery');
    if (!actionable && handoff?.state === 'posted' && handoff.reply_state === 'open') return view('waiting','Awaiting reply');
    if (assignment?.status === 'failed' || opportunity?.state === 'failed') return view('error','Last work failed');
    if (assignment?.status === 'unavailable' || scan?.coverage === 'unavailable') return view('error','Could not verify');
    if (id === 'gmail' && Array.isArray(snapshot.gmail?.stages) && snapshot.gmail.stages.some(stage =>
      ['blocked','overdue','failed'].includes(stage?.state))) return view('error','Needs attention');
    if (!actionable && scan?.offer_gate === 'awaiting_reply') return view('waiting','Awaiting reply');
    if (scan?.offer_gate === 'handoff_pending') return view('waiting','Awaiting Chat delivery');
    const waitingGate = scan?.handoff_gate || scan?.offer_gate;
    if (['cooldown','idle_spacing','owner_turn_pending','quiet_chat_required','recent_proactive_post','playing','higher_priority'].includes(waitingGate)) {
      return view('waiting','Waiting for next step');
    }
    if (id === 'brainstorm' && snapshot.initiative?.last_assessment) return view('checked','Assessment saved');
    if (id === 'marketing' && snapshot.marketing?.coverage === 'unavailable') return view('error','Could not verify');
    if (id === 'marketing' && ['ready','limited'].includes(snapshot.marketing?.coverage)) return view('checked','Campaigns checked');
    if (scan?.coverage === 'unconfigured' || (id === 'marketing' && snapshot.marketing?.coverage === 'unconfigured')) {
      return view('inactive','Source inactive');
    }
    if (actionable && ['ready','limited'].includes(scan?.coverage)) {
      const at = debugActivityDate(engagement.specialist_scan?.at);
      if (Number.isFinite(at) && at <= clock.now) {
        return {...view('checked','Monitoring'),work:'Last specialist check',details:[['Checkpoint observed',initiativeDebugTime(engagement.specialist_scan.at)]]};
      }
      return view('inactive','No current work verified');
    }
    if (scan?.coverage === 'ready') return view('checked','Last scan complete');
    if (scan?.coverage === 'limited') return view('checked','Partially checked');
    if (assignment?.status === 'completed') return view('checked','Last work completed');
    return view('inactive','No activity verified');
  }

  function createDebugActivity(current,container) {
    const coordinator = debugSpecialists.at(-1);
    for (const [id,title] of [coordinator,...debugSpecialists.slice(0,-1)]) {
      const lane = document.createElement('li');
      lane.style.setProperty('--initiative-debug-signal',debugActivityColors[id]);
      const name = document.createElement('span');
      name.className = 'initiative-debug-signal-name';
      name.textContent = title;
      const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
      svg.setAttribute('viewBox','0 0 96 26');
      svg.setAttribute('preserveAspectRatio','none');
      svg.setAttribute('aria-hidden','true');
      svg.setAttribute('focusable','false');
      const base = document.createElementNS(svg.namespaceURI,'path');
      const pulse = document.createElementNS(svg.namespaceURI,'path');
      pulse.setAttribute('class','initiative-debug-signal-pulse');
      svg.append(base,pulse);
      const status = document.createElement('span');
      status.className = 'initiative-debug-signal-status';
      const work = document.createElement('span');
      work.className = 'initiative-debug-signal-work';
      lane.append(name,svg,status,work);
      container.append(lane);
      current.activityLanes.set(id,{lane,status,work,base,pulse});
    }
  }

  function renderDebugActivity(current,signals = debugActivitySignals(current)) {
    const observed = current.activityObservation;
    const clock = debugActivityClock(current);
    let changeAt = clock && !current.readFailed && clock.age < debugSnapshotMaxAge
      ? observed.started + debugSnapshotMaxAge : Infinity;
    for (const [id,line] of current.activityLanes) {
      const signal = signals.get(id);
      if (line.lane.dataset.signal !== signal.state) {
        line.lane.dataset.signal = signal.state;
        const path = debugActivityLine[signal.state] || debugActivityLine.flat;
        line.base.setAttribute('d',path);
        line.pulse.setAttribute('d',path);
      }
      if (line.lane.dataset.intensity !== String(signal.intensity)) line.lane.dataset.intensity = String(signal.intensity);
      if (line.status.textContent !== signal.label) line.status.textContent = signal.label;
      if (line.work.textContent !== (signal.work || '')) line.work.textContent = signal.work || '';
      if (signal.expires !== null) changeAt = Math.min(changeAt,observed.started+signal.expires-observed.serverAt);
    }
    if (changeAt === current.activityChangeAt) return;
    window.clearTimeout(current.activityTimer);
    current.activityChangeAt = changeAt;
    if (Number.isFinite(changeAt)) current.activityTimer = window.setTimeout(() => {
      current.activityChangeAt = null;
      if (initiativeDebug === current && current.panel.open && !document.hidden) updateInitiativeDebug();
    },Math.max(50,changeAt - performance.now()));
  }

  const marketingDebugLabels = {
    coverage:{ready:'Campaign sources checked',limited:'Campaign coverage is partial',unconfigured:'Campaign sources inactive',unavailable:'Campaign sources unavailable'},
    campaign:{draft:'Draft',scheduled:'Scheduled',active:'Active',paused:'Paused',completed:'Completed',archived:'Archived'},
    mode:{in_person:'In person',online:'Online',unverified:'Campaign mode unverified'},
    readiness:{ready:'Ready',needs_setup:'Needs setup',limited:'Review coverage limited'},
    check:{ready:'Ready',missing:'Missing',limited:'Could not fully verify',not_applicable:'Not applicable'},
    checkName:{location:'Business location',claim_code:'Claim or QR code',reward:'Reward',audience:'Audience'},
    window:{not_open:'Not open',upcoming:'Upcoming',open:'Open',closed:'Closed'},
    observation:{observed:'Observed',unobserved:'No saved observation',unavailable:'Reporting unavailable'},
    decisionType:{'offer-eligibility':'Offer eligibility',fatigue:'Audience fatigue','low-observed-conversion':'Low observed conversion','conflict-volume':'Campaign conflicts'},
    decisionStatus:{proposed:'Proposed',accepted:'Accepted',dismissed:'Dismissed'},
    artifactState:{saved:'Saved',reviewed:'Reviewed',published:'Published',linked:'Linked',unavailable:'Unavailable'},
  };
  const marketingDebugObservations = {
    claims:{title:'Recorded reward claims',metrics:{reward_redemptions:'Redemptions'}},
    redemptions:{title:'Campaign reward outcomes',metrics:{reward_redemptions:'Redemptions',decisions_sampled:'Decisions sampled'}},
    promotion:{title:'Tracked promotion outcomes',metrics:{clicks:'Clicks',checkout_starts:'Checkout starts',paid_orders:'Paid orders',refunded_orders:'Refunded orders'}},
  };
  const marketingLabel = (group,value) => Object.hasOwn(marketingDebugLabels[group],value) ? marketingDebugLabels[group][value] : '';
  const marketingText = (value,limit = 160) => typeof value === 'string' ? value.replace(/[\x00-\x1f\x7f]/g,' ').trim().slice(0,limit) : '';
  const marketingDate = value => {
    const formatted = initiativeDebugTime(value);
    return formatted === '—' ? '' : formatted;
  };

  function marketingDebugDetails(marketing) {
    const coverage = marketingLabel('coverage',marketing?.coverage);
    if (!coverage) return null;
    const groups = [];
    let needsSetup = 0;
    for (const campaign of Array.isArray(marketing.campaigns) ? marketing.campaigns.slice(0,5) : []) {
      const title = marketingText(campaign?.name);
      if (!title) continue;
      const pairs = [];
      const add = (label,values) => {
        const value = values.filter(Boolean).join(' · ');
        if (value) pairs.push([label,value]);
      };
      add('Business',[marketingText(campaign.business)]);
      add('Location',[marketingText(campaign.location)]);
      add('Campaign status',[marketingLabel('campaign',campaign.status),debugDashboardId(campaign.version) ? `Version ${campaign.version}` : '']);
      const campaignStart = marketingDate(campaign.starts_at), campaignEnd = marketingDate(campaign.ends_at);
      add('Campaign window',[marketingLabel('window',campaign.window_state),
        campaignStart && `Starts ${campaignStart}`,campaignEnd && `Ends ${campaignEnd}`]);
      const readiness = campaign.readiness;
      if (readiness?.state === 'needs_setup') needsSetup += 1;
      add('Readiness',[marketingLabel('readiness',readiness?.state),marketingLabel('mode',readiness?.mode)]);
      for (const [key,name] of Object.entries(marketingDebugLabels.checkName)) {
        const check = readiness?.checks?.[key];
        const checkStatus = marketingLabel('check',check?.state);
        if (!checkStatus) continue;
        const next = marketingText(check.next_step,240);
        add(name,[checkStatus,marketingText(check.detail,240),next && `Next: ${next}`]);
      }
      for (const [key,description] of Object.entries(marketingDebugObservations)) {
        const observation = campaign.outcomes?.observations?.[key];
        const observed = marketingLabel('observation',observation?.state);
        if (!observed) continue;
        const start = marketingDate(observation.window_start), end = marketingDate(observation.window_end);
        const period = start && end ? `${start} to ${end}` : start ? `From ${start}` : end ? `Through ${end}` : '';
        const totals = [];
        if (observation.state === 'observed') {
          for (const [metric,label] of Object.entries(description.metrics)) {
            const value = observation.metrics?.[metric];
            if (Number.isSafeInteger(value) && value >= 0) totals.push(`${label} ${value}`);
          }
        }
        add(description.title,[observed,period,...totals]);
      }
      for (const decision of Array.isArray(campaign.outcomes?.decisions) ? campaign.outcomes.decisions.slice(0,5) : []) {
        const type = marketingLabel('decisionType',decision?.type);
        const state = marketingLabel('decisionStatus',decision?.status);
        if (type && state) add('Saved decision',[type,state,marketingDate(decision.at)]);
      }
      for (const artifact of Array.isArray(campaign.artifacts) ? campaign.artifacts.slice(0,4) : []) {
        const label = marketingText(artifact?.label,80);
        const state = marketingLabel('artifactState',artifact?.state);
        if (label && state) add(label,[state,marketingDate(artifact.at)]);
      }
      groups.push({title,pairs});
    }
    const pairs = [['Campaign evidence',coverage]];
    if (Array.isArray(marketing.campaigns)) pairs.push(['Campaigns shown',String(groups.length)]);
    if (needsSetup) pairs.push(['Campaigns needing setup',String(needsSetup)]);
    return {coverage,groups,pairs,
      needsSetup,summary:groups.length ? [
        `${groups.length} ${groups.length === 1 ? 'campaign' : 'campaigns'} shown`,
        needsSetup && `${needsSetup} ${needsSetup === 1 ? 'needs' : 'need'} setup`
      ].filter(Boolean).join(' · ') : ''};
  }

  function debugSpecialistStatus(current,id,failures = [],signal = null) {
    const {snapshot,conversationId} = current;
    if (conversationId === 0) return {status:'Save this Chat',tone:'muted',summary:''};
    if(id==='operator')return {status:signal?.label||'Phone status unavailable',tone:debugJobTone[signal?.state]||'muted',summary:'Incoming routing and voicemail',pairs:signal?.details||[],groups:[]};
    if (!snapshot) return {status:current.request ? 'Checking…' : 'Status unavailable',tone:'muted',summary:''};
    const engagement = snapshot.engagement || {};
    const marketing = id === 'marketing' ? marketingDebugDetails(snapshot.marketing) : null;
    const scan = engagement.specialist_scan?.specialists?.[id];
    const check = engagement.opportunity_checks?.[debugOpportunityLanes[id]];
    const assignment = engagement.specialist_assignments?.[id] || check?.assignment;
    const handoff = engagement.specialist_handoffs?.[id];
    const match = scan?.dispositions?.find?.(item => ['transcript_pacing','transcript_history'].includes(item.reason));
    const findings = Number.isSafeInteger(scan?.found) && scan.found >= 0
      ? `${scan.found} verified ${scan.found === 1 ? 'finding' : 'findings'}` : '';
    const step = debugDashboardLabel('step',assignment?.step);
    const linked = Object.hasOwn(debugHandoff,handoff?.state) ? debugHandoff[handoff.state] : '';
    const work = debugDashboardLabel('status',assignment?.status) || (assignment?.status === 'unavailable' ? 'Assignment unverified' : '');
    const replyOpen = (handoff?.state === 'posted' && handoff.reply_state === 'open')
      || (id === 'curiosity' && scan?.offer_gate === 'awaiting_reply');
    const reviewGate = scan?.handoff_gate || scan?.offer_gate;
    const reviewNote = debugDashboardLabel('scan_disposition',reviewGate)
      || (Object.hasOwn(debugReviewNotes,reviewGate) ? debugReviewNotes[reviewGate] : '');
    const matches = Number.isSafeInteger(scan?.chat_matches) && scan.chat_matches >= 0 ? String(scan.chat_matches) : '';
    const pairs = [
      ...(signal?.details || []),
      ['Source coverage',debugCoverage[scan?.coverage]],['Source findings',findings],
      ['Last source scan',scan && initiativeDebugTime(engagement.specialist_scan?.at)],
      ['Findings matching Chat rules',matches],['Last routing decision',reviewNote],
      ['Latest assignment',debugDashboardId(assignment?.run_id) && [`Run ${assignment.run_id}`,step,work].filter(Boolean).join(' · ')],
      ['Assignment updated',initiativeDebugTime(assignment?.at)],
      ['Chat handoff',linked && [linked,debugDashboardId(handoff.message_id) ? `Message ${handoff.message_id}` : '',
        replyOpen ? 'Awaiting your reply' : ''].filter(Boolean).join(' · ')],
      ['Earlier question',id === 'curiosity' && scan?.offer_gate === 'awaiting_reply'
        ? 'Open. Curiosity can still review new activity.' : ''],
      ['Delivery reason',debugDashboardLabel('delivery_reason',handoff?.reason) || debugDashboardLabel('delivery_reason',assignment?.delivery_reason)],
      ['Opportunity check',debugDashboardLabel('opportunity_check',check?.state)],
      ['Last opportunity check',initiativeDebugTime(check?.at)],
      ['Transcriptions routing',debugDashboardLabel('scan_disposition',match?.reason)],
      ...(marketing?.pairs || [])
    ].filter(([,value]) => value && value !== '—');
    const stages = id === 'gmail' && Array.isArray(snapshot.gmail?.stages) ? snapshot.gmail.stages.slice(0,5) : [];
    for (const stage of stages) {
      if (typeof stage?.label === 'string' && typeof stage?.detail === 'string') pairs.push([stage.label.slice(0,80),stage.detail.slice(0,320)]);
    }
    const blockedStage = stages.find(stage => ['blocked','overdue','failed'].includes(stage?.state) && typeof stage.label === 'string');
    const item = (status,tone,summary='') => ({status,tone,summary,pairs,groups:[...(marketing?.groups || []),...failures]});
    if (signal?.details?.length) return item(signal.label,debugJobTone[signal.state],signal.work);
    if (['executing','queued','approved','interrupted'].includes(assignment?.status)) return item(work,debugWorkTone[assignment.status],step);
    if (['working','queued','waiting'].includes(handoff?.state)) return item(linked,'working',findings);
    if (snapshot.initiative?.state === 'paused') return item('Initiative paused','muted',findings);
    if (snapshot.initiative?.state === 'linked_elsewhere') return item('Linked to another Chat','muted');
    if (match) return item(match.reason === 'transcript_history' ? 'In Transcriptions' : 'Waiting to post','muted',findings);
    if (['failed','unavailable','paused'].includes(assignment?.status)) return item(work,debugWorkTone[assignment.status],step);
    if (blockedStage) return item('Needs attention','warning',blockedStage.label.slice(0,80));
    if (id === 'brainstorm') {
      const active = snapshot.initiative?.active_jobs?.some?.(job => job.kind === 'assessment'
        && (!job.specialist || job.specialist === 'brainstorm'));
      return item(active ? 'Assessing' : snapshot.initiative?.last_assessment ? 'Assessed' : 'No assessment verified',
        active ? 'working' : 'muted');
    }
    if (marketing) return item(marketing.coverage,(snapshot.marketing.coverage === 'unavailable' || marketing.needsSetup) ? 'warning'
      : snapshot.marketing.coverage === 'unconfigured' ? 'muted' : 'ready', marketing.summary);
    if (Object.hasOwn(debugCoverage,scan?.coverage)) return item(debugCoverage[scan.coverage],
      scan.coverage === 'unavailable' ? 'warning' : scan.coverage === 'unconfigured' ? 'muted' : 'ready',
      [findings,replyOpen ? 'Earlier reply open' : ''].filter(Boolean).join(' · '));
    if (work) return item(work,debugWorkTone[assignment.status] || 'muted',step);
    const opportunity = debugDashboardLabel('opportunity_check',check?.state);
    return item(opportunity || (handoff?.state === 'posted' ? linked : 'No activity verified'),
      ['blocked','failed'].includes(check?.state) ? 'warning' : 'muted');
  }

  function renderDebugDashboard(current,browserCanvas,browserVoice,signals = debugActivitySignals(current)) {
    const {snapshot,tiles,detail,summary} = current;
    const release = /^[a-f0-9]{12}$/.test(snapshot?.release || '') ? `Build ${snapshot.release}` : '';
    const taken = initiativeDebugTime(snapshot?.observed_at);
    current.meta.textContent = [release,taken === '—' ? '' : taken].filter(Boolean).join(' · ');
    current.readNotice.textContent = current.readStatus || '';
    if (current.activityLanes) renderDebugActivity(current,signals);
    const post = snapshot?.engagement?.recent_posts?.[0] || snapshot?.engagement?.last_post;
    const id = post?.message_id;
    const assessment = snapshot?.initiative?.latest_assessment_run?.status;
    const verified = snapshot?.initiative?.last_assessment?.run_id;
    const delivery = debugDashboardLabel('gate',snapshot?.engagement?.gate);
    const rendered = browserCanvas?.renderedMessageIds?.includes?.(id);
    const voice = debugDashboardId(id) ? (savedPostVoiceLabel(post) || browserVoiceForMessage(browserVoice,id)) : '';
    const digest = browserGmailDigestStatus(browserCanvas,post);
    [assessment === 'completed' && verified ? `Succeeded · Run ${verified}` : debugDashboardLabel('status',assessment) || 'No assessment verified',
      delivery || 'Delivery unverified',digest || (rendered ? `Message ${id} rendered` : browserCanvas?.view === 'other' ? 'Another view is open' : 'No post verified on this canvas'),
      voice || 'No playback verified'].forEach((value,index) => {summary[index].textContent = value;});
    const selected = current.selected;
    const failures = debugWorkflowFailures(snapshot);
    for (const [key,tile] of tiles) {
      const view = debugSpecialistStatus(current,key,failures.filter(group => group.specialist === key),signals.get(key));
      tile.button.dataset.tone = view.tone;
      tile.button.setAttribute('aria-pressed',String(key === selected));
      tile.status.textContent = view.status;
      tile.summary.textContent = view.summary;
      if (key === selected) {
        detail.title.textContent = tile.title;
        detail.status.textContent = view.status;
        const pairs = view.pairs || [], groups = view.groups || [];
        const signature = JSON.stringify([pairs,groups]);
        if (detail.signature === signature) continue;
        detail.signature = signature;
        renderDebugDetails(detail.list,pairs);
        detail.groups.replaceChildren(...groups.map(group => {
          const section = document.createElement('section');
          const heading = document.createElement('h5');
          heading.textContent = group.title;
          const list = document.createElement('dl');
          renderDebugDetails(list,group.pairs);
          section.append(heading,list);
          return section;
        }));
        detail.empty.hidden = pairs.length > 0 || groups.length > 0;
      }
    }
  }

  function renderDebugDetails(list,pairs) {
    list.replaceChildren(...pairs.flatMap(([label,value]) => {
      const term = document.createElement('dt');
      const definition = document.createElement('dd');
      term.textContent = label;
      definition.textContent = value;
      return [term,definition];
    }));
  }

  function closeInitiativeDebug(restoreFocus = false) {
    initiativeDebugKeys = 0;
    const current = initiativeDebug;
    initiativeDebug = null;
    current?.request?.abort();
    window.clearTimeout(current?.refreshTimer);
    window.clearTimeout(current?.activityTimer);
    if (current?.panel.open) current.panel.close();
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
    const browserVoice = browserVoiceDebugSnapshot();
    const browserEyes = browserEyesDebugSnapshot(current.conversationId);
    const browserCanvas = browserChatCanvasSnapshot(current.snapshot?.engagement);
    const signals = debugActivitySignals(current);
    renderDebugDashboard(current,browserCanvas,browserVoice,signals);
    const unsaved = 'Send a message to save this Chat and start background work.';
    const report = current.conversationId === 0 ? {current:[unsaved,
      eyesDebugLines(browserEyes,null,browserVoice).join('\n'),
      browserVoice ? browserVoiceDebugLines(browserVoice).join('\n') : ''].filter(Boolean).join('\n\n'),history:''}
      : initiativeDebugReport(current.snapshot?.initiative ?? initiativeHealth(),current.snapshot,current.readStatus,browserVoice,browserEyes,browserCanvas,signals);
    if (current.report.textContent !== report.current) current.report.textContent = report.current;
    if (current.historyReport.textContent !== report.history) current.historyReport.textContent = report.history;
    current.history.hidden = !report.history;
    if (!report.history) current.history.open = false;
    return true;
  }

  function initiativeDebugConversation() {
    if (cfg.initiativeRuntime !== true || !pageChatOwner || pageController.signal.aborted) return null;
    return liveConversation() ?? (initiativeNewChat() ? 0 : null);
  }

  function canRefreshInitiativeDebug(current) {
    return initiativeDebug === current && current.panel.open && !document.hidden
      && current.conversationId > 0 && initiativeDebugConversation() === current.conversationId;
  }

  async function refreshInitiativeDebug(current, automatic = false) {
    if (!canRefreshInitiativeDebug(current) || current.request || !requireCurrentConfiguration()) return;
    if(current.selected==='operator')void window.VP3PhoneWorkspaceV1?.refresh?.();
    const requestedAt = performance.now();
    if (automatic && current.refreshDue && requestedAt < current.refreshDue) return;
    window.clearTimeout(current.refreshTimer);
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(),10000);
    current.request = controller;
    current.refresh.disabled = true;
    if (!automatic || !current.snapshot) {
      current.readStatus = 'Reading a fresh diagnostic snapshot…';
      updateInitiativeDebug();
    }
    let received = false;
    try {
      const url = endpoint('initiative_diagnostics');
      url.searchParams.set('conversation_id',String(current.conversationId));
      const {status,data} = await readUntilCancelled(controller.signal,async () => {
        const response = await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,
          headers:{Accept:'application/json','X-VP3-Working-Attention':pageBinding}});
        return {status:response.status,data:response.ok ? await presentationResponse(response,controller.signal) : null};
      });
      if (!canRefreshInitiativeDebug(current)) return;
      if (status === 401 || status === 403) {
        current.snapshot = null;
        closeInitiativeDebug();
        return;
      }
      if (!data) {
        current.readStatus = `Diagnostic snapshot unavailable (HTTP ${status}). Retrying automatically.`;
        return;
      }
      const snapshot = data.diagnostics;
      const serverAt = debugActivityDate(snapshot?.observed_at);
      if (snapshot?.agent_id !== pageAgent || snapshot?.conversation_id !== current.conversationId
          || !Number.isFinite(serverAt) || !initiativeHealth(snapshot.initiative,current.conversationId)) {
        current.readStatus = 'The server did not return a diagnostic snapshot for this Chat.';
        return;
      }
      current.snapshot = snapshot;
      // Count request time against the observation window to avoid overstating a lease.
      current.activityObservation = {serverAt,started:requestedAt};
      current.readStatus = '';
      received = true;
    } catch (error) {
      if (canRefreshInitiativeDebug(current)) current.readStatus = controller.signal.aborted
        ? 'The diagnostic request timed out. Retrying automatically.' : 'The diagnostic request failed. Retrying automatically.';
    } finally {
      window.clearTimeout(timeout);
      if (initiativeDebug === current) {
        current.request = null;
        current.refresh.disabled = false;
        current.readFailed = !received;
        current.refreshFailures = received ? 0 : Math.min(2,(current.refreshFailures || 0)+1);
        updateInitiativeDebug();
        if (canRefreshInitiativeDebug(current)) {
          const delay = debugRefreshInterval*2**current.refreshFailures;
          current.refreshDue = performance.now() + delay;
          current.refreshTimer = window.setTimeout(() => {
            current.refreshDue = null;
            void refreshInitiativeDebug(current,true);
          },delay);
        }
      }
    }
  }

  function openInitiativeDebug(conversationId) {
    const panel = document.createElement('dialog');
    panel.className = 'chat-initiative-debug';
    panel.setAttribute('aria-labelledby','chatInitiativeDebugTitle');
    panel.innerHTML = '<header><div class="initiative-debug-header"><div><h2 id="chatInitiativeDebugTitle">Initiative Debug</h2>' +
      '<p data-initiative-debug-meta></p></div><div class="initiative-debug-actions">' +
      '<button type="button" data-initiative-debug-refresh>Refresh</button>' +
      '<button type="button" data-initiative-debug-copy>Copy report</button>' +
      '<button type="button" data-initiative-debug-close autofocus>Close</button>' +
      '<p role="status" data-initiative-debug-copy-status></p></div></div></header>' +
      '<div class="initiative-debug-content"><p role="status" data-initiative-debug-read></p>' +
      '<section class="initiative-debug-activity" aria-labelledby="initiativeDebugActivityTitle">' +
      '<div class="initiative-debug-activity-heading"><h3 id="initiativeDebugActivityTitle">Specialist activity</h3>' +
      '<button type="button" data-initiative-debug-motion aria-pressed="true">Animate waveforms</button></div>' +
      '<div class="initiative-debug-activity-scroll" role="group" aria-label="Specialist activity signals" tabindex="0"><ul></ul></div></section>' +
      '<section class="initiative-debug-summary" aria-label="Initiative at a glance">' +
      '<div><h3>Assessment</h3><p></p></div><div><h3>Chat delivery</h3><p></p></div>' +
      '<div><h3>Chat Canvas</h3><p></p></div><div><h3>Latest voice receipt</h3><p></p></div></section>' +
      '<section aria-labelledby="initiativeDebugSpecialists"><h3 id="initiativeDebugSpecialists">Specialists</h3>' +
      '<div class="initiative-debug-specialists"><div class="initiative-debug-grid"></div>' +
      '<aside id="initiativeDebugDetail" class="initiative-debug-detail" aria-labelledby="initiativeDebugDetailTitle" tabindex="0">' +
      '<h4 id="initiativeDebugDetailTitle"></h4><p data-initiative-debug-detail-status></p>' +
      '<p data-initiative-debug-detail-empty>No further details are available for this specialist.</p><dl></dl><div data-initiative-debug-groups></div></aside></div></section>' +
      '<details data-initiative-debug-technical><summary>Full diagnostic report</summary><pre data-initiative-debug-report></pre></details>' +
      '<details data-initiative-debug-history hidden><summary>History</summary><pre data-initiative-debug-history-report></pre></details></div>';
    const copy = panel.querySelector('[data-initiative-debug-copy]');
    const status = panel.querySelector('[data-initiative-debug-copy-status]');
    const refresh = panel.querySelector('[data-initiative-debug-refresh]');
    refresh.disabled = conversationId === 0;
    const current = {panel,conversationId,refresh,report:panel.querySelector('[data-initiative-debug-report]'),
      history:panel.querySelector('[data-initiative-debug-history]'),historyReport:panel.querySelector('[data-initiative-debug-history-report]'),
      returnFocus:document.activeElement,meta:panel.querySelector('[data-initiative-debug-meta]'),
      readNotice:panel.querySelector('[data-initiative-debug-read]'),
      summary:panel.querySelectorAll('.initiative-debug-summary p'),tiles:new Map(),activityLanes:new Map(),selected:'gmail',
      detail:{title:panel.querySelector('#initiativeDebugDetailTitle'),status:panel.querySelector('[data-initiative-debug-detail-status]'),
        empty:panel.querySelector('[data-initiative-debug-detail-empty]'),list:panel.querySelector('.initiative-debug-detail dl'),
        groups:panel.querySelector('[data-initiative-debug-groups]')}};
    const activity = panel.querySelector('.initiative-debug-activity');
    createDebugActivity(current,activity.querySelector('ul'));
    const motion = activity.querySelector('[data-initiative-debug-motion]');
    motion.addEventListener('click',() => {
      const enabled = motion.getAttribute('aria-pressed') !== 'true';
      motion.setAttribute('aria-pressed',String(enabled));
      activity.dataset.motion = enabled ? 'running' : 'paused';
    });
    const grid = panel.querySelector('.initiative-debug-grid');
    for (const [id,title] of debugSpecialists) {
      const tile = document.createElement('button');
      tile.type = 'button';
      tile.dataset.initiativeDebugSpecialist = id;
      tile.setAttribute('aria-controls','initiativeDebugDetail');
      const name = document.createElement('strong');
      name.textContent = title;
      const state = document.createElement('span');
      state.className = 'initiative-debug-state';
      const description = document.createElement('span');
      description.className = 'initiative-debug-tile-summary';
      tile.append(name,state,description);
      tile.addEventListener('click',() => {
        current.selected = id;
        if(id==='operator')void window.VP3PhoneWorkspaceV1?.refresh?.().then(()=>{if(initiativeDebug===current)updateInitiativeDebug();});
        updateInitiativeDebug();
        if (window.matchMedia('(max-width: 58rem)').matches) {
          current.detail.title.scrollIntoView({block:'start'});
          current.detail.title.focus({preventScroll:true});
        }
      });
      current.tiles.set(id,{button:tile,title,status:state,summary:description});
      grid.append(tile);
    }
    current.detail.title.tabIndex = -1;
    refresh.addEventListener('click',() => void refreshInitiativeDebug(current));
    initiativeDebug = current;
    if (!updateInitiativeDebug()) return;
    copy.addEventListener('click', async () => {
      if (!updateInitiativeDebug()) return;
      try {
        const history = current.history.hidden ? '' : `\n\nHistory\n${current.historyReport.textContent}`;
        await navigator.clipboard.writeText(`Initiative Debug\n${current.report.textContent}${history}`);
        if (initiativeDebug === current) status.textContent = 'Copied.';
      } catch (_error) {
        if (initiativeDebug !== current) return;
        const selection = window.getSelection();
        if (selection) {
          const range = document.createRange();
          panel.querySelector('[data-initiative-debug-technical]').open = true;
          if (!current.history.hidden) current.history.open = true;
          range.setStartBefore(current.report);
          range.setEndAfter(current.history.hidden ? current.report : current.history);
          selection.removeAllRanges();
          selection.addRange(range);
        }
        status.textContent = 'Copy the selected report.';
      }
    });
    panel.querySelector('[data-initiative-debug-close]').addEventListener('click', () => closeInitiativeDebug(true));
    panel.addEventListener('cancel',event => {event.preventDefault();closeInitiativeDebug(true);});
    document.body.append(panel);
    panel.showModal();
    void refreshInitiativeDebug(current);
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

  function openDiagnostics() {
    const conversationId = initiativeDebugConversation();
    if (conversationId === null || participantContext() || !requireCurrentConfiguration()) return false;
    if (!initiativeDebug) openInitiativeDebug(conversationId);
    return initiativeDebug !== null;
  }

  function useOrbInitiativeAction(action) {
    if (liveConversation() === null || !liveState) return;
    const view = initiativeRuntimeStatus();
    if (action === 'diagnostics' && view?.diagnostics) {
      openDiagnostics();
    } else if (action === 'retry' && view?.retry) void retryInitiativeHealth();
    else if (['on','off'].includes(action) && view?.setting === action) void changeInitiative(action === 'on');
  }

  function mountInitiativeSettings(container) {
    if (!container || cfg.initiativeRuntime !== true || !pageChatOwner || participantContext()
      || pageController.signal.aborted || !configurationMatches()) return null;
    const signal = pageController.signal;
    const conversation = liveConversation();
    const current = () => !signal.aborted && !participantContext() && configurationMatches()
      && conversation === liveConversation();
    const section = document.createElement('section');
    const heading = document.createElement('h4');
    heading.textContent = 'Opportunity assessment';
    const status = document.createElement('p');
    status.setAttribute('role','status');
    const detail = document.createElement('p');
    const setting = document.createElement('button');
    setting.type = 'button';
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.textContent = 'Retry status check';
    section.append(heading,status,detail,setting,retry);
    container.appendChild(section);

    function render() {
      const allowed = current();
      const view = allowed ? initiativeRuntimeStatus() : null;
      const label = view?.label || (allowed && conversation === null
        ? 'Opportunity assessment becomes available after this Chat is saved.'
        : 'Opportunity assessment status is unavailable.');
      if (status.textContent !== label) status.textContent = label;
      const explanation = view?.detail || '';
      if (detail.textContent !== explanation) detail.textContent = explanation;
      detail.hidden = !explanation;
      setting.hidden = !view?.setting;
      setting.disabled = !view?.setting || view.settingBusy === true;
      setting.textContent = view?.setting === 'on' ? 'Resume Initiative' : 'Pause Initiative';
      retry.hidden = !view?.retry;
      retry.disabled = !view?.retry || view.retryBusy === true;
    }
    setting.addEventListener('click', () => {
      if (section.isConnected && current()) useOrbInitiativeAction(initiativeRuntimeStatus()?.setting);
    });
    retry.addEventListener('click', () => {
      if (section.isConnected && current()) useOrbInitiativeAction('retry');
    });
    initiativeSettingsViews.add(render);
    render();
    return () => {
      initiativeSettingsViews.delete(render);
      section.remove();
    };
  }

  function updateOrbInitiativeStatus() {
    updateInitiativeDebug();
    initiativeSettingsViews.forEach(render => render());
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
    return conversationId !== null && conversationId === liveConversation() && !pageController.signal.aborted
      && !canonical?.isBusy?.();
  }

  async function initiativeRequest(action, payload) {
    const controller = new AbortController();
    initiativeController = controller;
    const signal = pageController.signal;
    const cancel = () => controller.abort(signal.reason);
    signal.addEventListener('abort',cancel,{once:true});
    const timeout = window.setTimeout(() => controller.abort(new DOMException('Background request timed out.', 'TimeoutError')),
      action === 'initiative_tick' ? 150000 : 15000);
    try {
      signal.throwIfAborted();
      return await readUntilCancelled(controller.signal,() => post(action,payload,controller.signal));
    }
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
        if (initiativeDebug?.conversationId === cid) void refreshInitiativeDebug(initiativeDebug,true);
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
    if (navigator.onLine === false) return;
    advanceInitiativeVoice();
    const cid = liveConversation(true), health = initiativeHealth(initiativePumpHealth,cid);
    const revision = String(health?.policy_revision || '');
    const foreground = initiativeMayAdvance(cid);
    const executionReady = health?.execution_ready === true;
    const pumpAvailable = health?.pump_available === true || (health?.pump_available === undefined && executionReady);
    const interval = foreground ? (executionReady ? 15000 : 30000) : 60000;
    // A bound Chat can receive saved text while its composer or tab is inactive.
    // Speaking and interactive suggestions keep their independent readiness checks.
    if (cfg.initiativeRuntime !== true || cid === null || pageController.signal.aborted || initiativeBusy
      || initiativeSettingBusy || health?.enabled !== true || !pumpAvailable
      || !/^[a-f0-9]{32}$/.test(revision) || Date.now() - initiativeLastTick < interval) return;
    initiativeBusy = true;
    initiativeLastTick = Date.now();
    initiativeError = '';
    if (!document.hidden) renderLiveContext(liveState);
    const lifecycle = pageController.signal;
    const currentTick = () => !lifecycle.aborted && cid === liveConversation(true) && initiativePumpHealth?.policy_revision === revision;
    try {
      const result = await initiativeRequest('initiative_tick',{conversation_id:cid,policy_revision:revision});
      if (!currentTick() || !requireCurrentConfiguration()) return;
      const health = initiativeHealth(result?.health,cid);
      if (health) {
        rememberInitiativeRevision(initiativePumpHealth,health);
        if (liveState) liveState.initiative = {...liveState.initiative,runtime:health};
      }
      if (result?.ok === false && !['off','blocked','already_running','at_capacity','assessment_sources_changed'].includes(result.reason)
        && health?.reason !== result.reason && health?.reason !== 'retry_scheduled') {
        initiativeError = 'The last background action could not be verified.';
      }
      const specialistScanUpdated = Boolean(result?.coordination && result.coordination !== 'not_due');
      if (!document.hidden && initiativeDebug?.conversationId === cid
        && (specialistScanUpdated || !['no_work','off','blocked','already_running','at_capacity'].includes(result?.reason))) {
        void refreshInitiativeDebug(initiativeDebug,true);
      }
      // Saved assistant messages use canonical Chat rendering, unread tags and
      // delivery cursors. This UI never manufactures a message or speaks it.
      if (result?.ok === true && ['preparation','saved_delivery'].includes(result.step) && initiativeMaySync(cid)) {
        const canonical = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
        void Promise.resolve(canonical?.syncConversation?.(cid)).catch(() => {});
      }
    } catch (error) {
      if (currentTick() && error?.name !== 'AbortError') {
        initiativeError = 'The background connection was interrupted. The next check will retry.';
        if (error.message === 'initiative_settings_changed' || error.message === 'initiative_context_changed') {
          initiativePumpHealth = null;
          if (!document.hidden) void refresh();
        }
      }
    } finally {
      initiativeBusy = false;
      advanceInitiativeVoice();
      if (currentTick() && !document.hidden) renderLiveContext(liveState);
      if (!pageController.signal.aborted && (!currentTick() || initiativeLastTick === 0)) void advanceInitiative();
    }
  }

  function acceptCoreContext(event) {
    const detail = event.detail, cid = liveConversation();
    if (cfg.initiativeRuntime !== true || cid === null || initiativeSettingBusy || pageController.signal.aborted || !requireCurrentConfiguration()
      || !detail || detail.owner_user_id !== pageChatOwner || detail.agent_id !== pageAgent
      || detail.conversation_id !== cid || detail.working_attention_binding !== pageBinding) return;
    const health = initiativeHealth(detail.health,cid);
    if (!health || initiativeSupersededRevisions.has(health.policy_revision)) return;
    const previous = initiativeHealth(initiativePumpHealth,cid);
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

  async function maybeSpeakNotice(action, identity, conversationId, browserOnly = false) {
    if (noticeVoiceBusy || activeVoiceThrough || conversationId === null) return;
    const center = window.STONEFELLOW_NOTIFICATION_CENTER;
    if (typeof center?.announceWhen !== 'function') return;
    if (browserOnly && (!window.speechSynthesis || !window.SpeechSynthesisUtterance)) return;
    const epoch = noticeVoiceEpoch;
    const signal = pageController.signal;
    const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
    const canSpeak = () => noticeVoiceEnabled && epoch === noticeVoiceEpoch && !signal.aborted && !document.hidden
      && document.hasFocus() && !participantContext() && configurationMatches()
      && liveConversation() === conversationId && !input?.value.trim() && !window.VP3LiveContext?.voiceDeferral?.()?.defer;
    const idle = () => canSpeak() && !chat?.isBusy?.() && !chat?.pendingVoiceSubmit?.()
      && !window.VP3ConversationControls?.isReplyCapturePending?.()
      && !['thinking','processing','speaking','listening'].includes(runtimeState());
    if (!idle()) return;
    noticeVoiceBusy = true;
    try {
      const result = await post(action,{conversation_id:conversationId,...identity},signal);
      const claim = result?.claim;
      if (!claim || Object.entries(identity).some(([key,value]) => claim[key] !== value) || !canSpeak()) return;
      const message = String(claim.message || '');
      if (message) await center.announceWhen(message,canSpeak,{signal,readyToSpeak:idle,autoReply:false,browserOnly});
    } catch (_error) {
      // The durable notice remains available if the voice attempt is interrupted.
    } finally { noticeVoiceBusy = false; }
  }

  function advanceNoticeVoice(credits, github, conversationId) {
    const notices = Array.isArray(credits) ? credits.filter(notice => Number.isSafeInteger(notice?.notification_id)
      && notice.notification_id > 0) : [];
    const ids = notices.map(notice => notice.notification_id).join(',');
    if (ids !== creditNoticeIds) {
      creditNoticeIds = ids;
      void Promise.resolve(window.STONEFELLOW_NOTIFICATION_CENTER?.refresh?.()).catch(()=>{});
    }
    const credit = notices.find(notice => notice.voice_pending === true);
    if (credit && window.speechSynthesis && window.SpeechSynthesisUtterance) {
      void maybeSpeakNotice('credit_voice_claim',{notification_id:credit.notification_id},conversationId,true);
    } else if (Number.isSafeInteger(github?.generation) && github.generation > 0
      && Number.isSafeInteger(github?.through_id) && github.through_id > 0) {
      void maybeSpeakNotice('github_voice_claim',{generation:github.generation,through_id:github.through_id},conversationId);
    }
  }

  async function refresh(force = false) {
    const signal = pageController.signal;
    if (navigator.onLine === false || participantContext() || signal.aborted || !requireCurrentConfiguration() || busy || (document.hidden && !force)) return;
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
            rememberInitiativeRevision(initiativePumpHealth,runtime);
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
      if (liveRequestIsCurrent()) advanceNoticeVoice(state.credit_notices,state.github_voice,conversationId);
      advanceInitiativeVoice();
    } catch (error) {
      if (!signal.aborted && error?.name !== 'AbortError' && !participantContext() && requestedBriefEpoch === briefEpoch) {
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

  function resumeInitiativeConnection(reconnecting = false) {
    if (pageController.signal.aborted || navigator.onLine === false) return;
    if (reconnecting) initiativeLastTick = 0;
    if (!document.hidden) {
      showAwaitingInitiativeHealth();
      if (reconnecting && liveConversation() !== null) {
        void Promise.resolve(window.StonefellowAgentActivity?.refreshCoreContext?.()).catch(() => {});
      }
      void refresh();
    }
    void advanceInitiative();
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
  window.addEventListener('vp3:voice-diagnostics-changed',updateInitiativeDebug);
  window.addEventListener('vp3:proactive-voice-queue-changed',updateInitiativeDebug);
  window.addEventListener('vp3:chat-canvas-status-changed',updateInitiativeDebug);
  window.addEventListener('vp3:gmail-digest-render-changed',updateInitiativeDebug);
  window.addEventListener('vp3:eyes-diagnostics-changed',updateInitiativeDebug);

  window.addEventListener('stonefellow:agent-stop', () => {
    noticeVoiceEpoch++;
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
    if (!document.hidden) resumeInitiativeConnection();
    else {
      closeInitiativeDebug();
      clearWorkingAttention();
      clearLiveContext({keepInitiative:true});
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
      if (event.detail?.state === 'pending' && liveConversation() !== null) clearLiveContext({keepInitiative:true});
    });
  }
  window.addEventListener('stonefellow:chat-context-changing', () => {
    noticeVoiceEpoch++;
    closeInitiativeDebug();
  });
  window.addEventListener('stonefellow:agent-voice', event => {
    noticeVoiceEnabled = event.detail?.enabled !== false;
    if (!noticeVoiceEnabled) noticeVoiceEpoch++;
    else void refresh();
  });
  window.addEventListener('focus', () => resumeInitiativeConnection());
  window.addEventListener('offline', () => {
    initiativeController?.abort();
    readController?.abort();
  });
  window.addEventListener('online', () => resumeInitiativeConnection(true));

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
    initiativeLastTick = 0;
    startUpdates();
  });

  function startUpdates() {
    if (!pageBinding) {
      invalidateAccount(new Error('account_changed'));
      return;
    }
    observer.observe(document.body,{attributes:true,attributeFilter:['data-stonefellow-agent-state']});
    showAwaitingInitiativeHealth();
    schedule();
    void refresh();
  }

  window.VP3_COGNITIVE_PRESENTATION = {
    refresh:refresh,openBrief,openDetails:openPopup,closeBrief:closePopup,openDiagnostics,mountInitiativeSettings,
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
