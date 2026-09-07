(() => {
  'use strict';

  const cfg = window.STONEFELLOW_ARTIST_LISTENING_V172 || {};
  const BUILD = 'transcription-deeper-v307-20260907';
  const userId = Math.max(0, Number(cfg.userId || 0));
  const reportEndpoint = String(cfg.endpoint || '').replace(/artist-listening-v172\.php(?:\?.*)?$/i, 'artist-listening-intelligence-v300.php');
  const legacyResearchKey = `stonefellow:artist-listening:ai-summary:${userId}`;
  const appsKey = `stonefellow:artist-listening:ai-apps:${userId}`;
  const workflowKey = `stonefellow:artist-listening:workflow:${userId}`;

  const fallbackWorkflow = {
    preset: 'custom',
    live_analysis: false,
    web_research: readLegacyResearchEnabled(),
    depth: 'standard',
    focus: '',
    context_mode: 'authorized',
  };
  const defaultComparison = () => ({id:'project_history',label:'Project / conversation history',mode:'project_history',session_id:0});

  const state = {
    open: false,
    settingsOpen: false,
    bound: false,
    sessionId: 0,
    registry: [],
    selectedApps: [],
    activeApp: 'basic',
    report: null,
    appStatus: {},
    permissions: {},
    operations: {},
    workflowConfig: {},
    workflow: readWorkflow(),
    runPlan: null,
    pluginErrors: {},
    relationsSummary: {total:0,accepted:0,rejected:0,unreviewed:0,types:{}},
    comparisonTargets: [],
    comparison: defaultComparison(),
    busy: false,
    busyApp: '',
    busyRelations: false,
    editingItemId: '',
    liveWords: 0,
    lastReportedWords: 0,
    liveTimer: 0,
    lastError: '',
    actionMessage: '',
  };

  const proof = window.STONEFELLOW_ARTIST_LISTENING_AI = {
    build: BUILD,
    loaded: true,
    panelOpens: 0,
    panelCloses: 0,
    buttonClicks: 0,
    reports: 0,
    liveReports: 0,
    brainSaves: 0,
    knowledgeSaves: 0,
    itemReviews: 0,
    itemEdits: 0,
    itemActions: 0,
    relationBuilds: 0,
    relationReviews: 0,
    evidenceJumps: 0,
    presetChanges: 0,
    comparisonChanges: 0,
    isOpen: () => state.open,
    open: () => setOpen(true),
    close: () => setOpen(false),
    toggle: () => setOpen(!state.open),
    setResearchEnabled: enabled => setResearchEnabled(enabled),
    isResearchEnabled: () => !!state.workflow.web_research,
    setLiveAnalysisEnabled: enabled => setLiveAnalysisEnabled(enabled),
    isLiveAnalysisEnabled: () => !!state.workflow.live_analysis,
    setSettingsOpen: open => setSettingsOpen(open),
    isSettingsOpen: () => state.settingsOpen,
    selectedApps: () => [...state.selectedApps],
    activeApp: () => state.activeApp,
    comparison: () => ({...state.comparison}),
  };

  const clean = value => String(value || '').replace(/\s+/g, ' ').trim();
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const wordCount = value => {
    const text = clean(value);
    return text ? text.split(' ').length : 0;
  };

  function readLegacyResearchEnabled() {
    try { return localStorage.getItem(legacyResearchKey) === '1'; } catch (error) { return false; }
  }

  function readWorkflow() {
    try {
      const parsed = JSON.parse(localStorage.getItem(workflowKey) || 'null');
      return parsed && typeof parsed === 'object' ? {...fallbackWorkflow, ...parsed} : {...fallbackWorkflow};
    } catch (error) {
      return {...fallbackWorkflow};
    }
  }

  function persistWorkflow() {
    try {
      localStorage.setItem(workflowKey, JSON.stringify(state.workflow));
      localStorage.setItem(legacyResearchKey, state.workflow.web_research ? '1' : '0');
    } catch (error) {}
  }

  function workflowDefaults() {
    const defaults = state.workflowConfig?.defaults;
    return defaults && typeof defaults === 'object' ? {...fallbackWorkflow, ...defaults} : {...fallbackWorkflow};
  }

  function normalizeWorkflow(value = state.workflow) {
    const defaults = workflowDefaults();
    const raw = value && typeof value === 'object' ? value : {};
    const depths = new Set((state.workflowConfig?.depth_options || []).map(row => String(row?.id || '')));
    const contexts = new Set((state.workflowConfig?.context_options || []).map(row => String(row?.id || '')));
    const presetIds = new Set((state.workflowConfig?.presets || []).map(row => String(row?.id || '')));
    const depth = String(raw.depth || defaults.depth || 'standard');
    const context = String(raw.context_mode || defaults.context_mode || 'authorized');
    const preset = String(raw.preset || defaults.preset || 'custom');
    return {
      preset: preset === 'custom' || presetIds.has(preset) ? preset : 'custom',
      live_analysis: Boolean(raw.live_analysis),
      web_research: Boolean(raw.web_research),
      depth: depths.size ? (depths.has(depth) ? depth : 'standard') : (['concise','standard','deep'].includes(depth) ? depth : 'standard'),
      focus: clean(raw.focus || '').slice(0, 240),
      context_mode: contexts.size ? (contexts.has(context) ? context : 'authorized') : (['transcript','authorized'].includes(context) ? context : 'authorized'),
    };
  }

  function readSelectedApps(available = []) {
    const allowed = new Set(available.map(app => String(app.id || '')));
    try {
      const parsed = JSON.parse(localStorage.getItem(appsKey) || 'null');
      const cleanIds = Array.isArray(parsed) ? parsed.map(String).filter(id => allowed.has(id)) : [];
      return cleanIds.length ? cleanIds : (allowed.has('basic') ? ['basic'] : [...allowed].slice(0, 1));
    } catch (error) {
      return allowed.has('basic') ? ['basic'] : [...allowed].slice(0, 1);
    }
  }

  function persistApps() {
    try { localStorage.setItem(appsKey, JSON.stringify(state.selectedApps)); } catch (error) {}
  }

  function appById(id) {
    return state.registry.find(app => String(app.id || '') === String(id || '')) || null;
  }

  function presetById(id) {
    return (Array.isArray(state.workflowConfig?.presets) ? state.workflowConfig.presets : [])
      .find(row => String(row?.id || '') === String(id || '')) || null;
  }

  function currentSessionId() {
    return Math.max(0, Number(
      window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.currentSessionId ||
      state.sessionId ||
      cfg.initialSessionId ||
      0
    ));
  }

  function comparisonTarget(mode, sessionId = 0) {
    mode = clean(mode || 'project_history');
    sessionId = Math.max(0, Number(sessionId || 0));
    return state.comparisonTargets.find(row => clean(row?.mode) === mode && Math.max(0,Number(row?.session_id || 0)) === sessionId) || null;
  }

  function setComparisonTargets(targets) {
    state.comparisonTargets = Array.isArray(targets)
      ? targets.filter(row => row && clean(row.id) && clean(row.mode) && clean(row.label)).map(row => ({
          id:clean(row.id),label:clean(row.label),mode:clean(row.mode),session_id:Math.max(0,Number(row.session_id || 0)),updated_at:clean(row.updated_at || '')
        }))
      : [];
    const selected = state.comparisonTargets.find(row => row.id === state.comparison.id)
      || comparisonTarget(state.comparison.mode,state.comparison.session_id)
      || state.comparisonTargets.find(row => row.id === 'project_history')
      || state.comparisonTargets[0]
      || defaultComparison();
    state.comparison = {...selected};
  }

  function setComparisonTarget(id, userChange = true) {
    const target = state.comparisonTargets.find(row => row.id === String(id || ''));
    if (!target) return {...state.comparison};
    state.comparison = {...target};
    state.actionMessage = `Comparison · ${target.label}.`;
    if (userChange) proof.comparisonChanges += 1;
    render();
    return {...state.comparison};
  }

  function syncComparison(value) {
    if (!value || typeof value !== 'object') return;
    const target = comparisonTarget(String(value.mode || ''),Number(value.session_id || 0));
    state.comparison = target ? {...target} : {
      id:String(value.mode || 'project_history'),label:clean(value.label || 'Project / conversation history'),
      mode:String(value.mode || 'project_history'),session_id:Math.max(0,Number(value.session_id || 0))
    };
  }

  function comparisonPayload() {
    return {mode:String(state.comparison.mode || 'project_history'),session_id:Math.max(0,Number(state.comparison.session_id || 0))};
  }

  function transcriptWordCount(segments = []) {
    if (!Array.isArray(segments)) return 0;
    return segments.reduce((total, row) => {
      const type = String(row?.segment_type || row?.type || 'transcript');
      if (type !== 'transcript') return total;
      return total + wordCount(row?.transcript_text || row?.text || '');
    }, 0);
  }

  async function request(action, payload = {}, method = 'POST') {
    if (!reportEndpoint) throw new Error('Transcription intelligence endpoint is unavailable.');
    let url = reportEndpoint;
    const options = {method, credentials:'same-origin', headers:{Accept:'application/json'}};
    if (method === 'GET') {
      const target = new URL(url, location.href);
      target.searchParams.set('action', action);
      Object.entries(payload).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') target.searchParams.set(key, String(value));
      });
      url = target.toString();
    } else {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify({action, csrf_token:String(cfg.csrf || ''), ...payload});
    }
    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({ok:false,error:'Transcription intelligence returned an invalid response.'}));
    if (!response.ok || !data.ok) throw new Error(String(data.error || `Transcription intelligence failed (${response.status}).`));
    return data;
  }

  function setRegistry(registry) {
    state.registry = Array.isArray(registry) ? registry.filter(app => clean(app?.id) && clean(app?.title)) : [];
    const allowed = new Set(state.registry.map(app => String(app.id)));
    if (!state.selectedApps.length) state.selectedApps = readSelectedApps(state.registry);
    else state.selectedApps = state.selectedApps.filter(id => allowed.has(id));
    if (!state.selectedApps.length && allowed.has('basic')) state.selectedApps = ['basic'];
    if (!state.selectedApps.includes(state.activeApp)) state.activeApp = state.selectedApps[0] || 'basic';
  }

  function setWorkflowConfig(config) {
    if (!config || typeof config !== 'object') return;
    state.workflowConfig = config;
    state.workflow = normalizeWorkflow(state.workflow);
    persistWorkflow();
  }

  function formatSaved(value) {
    const raw = String(value || '').trim();
    if (!raw) return 'Not analyzed yet';
    const date = new Date(raw.replace(' ', 'T'));
    if (!Number.isFinite(date.getTime())) return 'Saved';
    return `Saved ${date.toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'})}`;
  }

  function freshnessText(appId) {
    const status = state.appStatus?.[appId] || {};
    if (!status.generated) return 'Not generated';
    return `${status.fresh ? 'Current' : 'Needs refresh'}${status.generated_at ? ` · ${formatSaved(status.generated_at)}` : ''}`;
  }

  function localRunPlan(appIds = state.selectedApps, mode = 'manual') {
    const requested = [...new Set((Array.isArray(appIds) ? appIds : []).map(String).filter(id => appById(id)))];
    const effective = mode === 'live' ? requested.filter(id => !!appById(id)?.live) : requested;
    const aiApps = effective.filter(id => appById(id)?.execution === 'ai');
    const deterministic = effective.filter(id => appById(id)?.execution === 'deterministic');
    const manualOnly = requested.filter(id => !appById(id)?.live);
    const batchSize = Math.max(1, Number(state.workflowConfig?.batch_size || 1));
    const batches = Math.ceil(aiApps.length / batchSize);
    const cost = batches >= 4 ? 'high' : (batches >= 2 ? 'medium' : (batches === 1 ? 'low' : 'none'));
    return {
      requested_apps:requested,effective_apps:effective,ai_apps:aiApps,deterministic_apps:deterministic,
      ai_batches:batches,batch_size:batchSize,manual_only_apps:manualOnly,estimated_ai_cost:cost,
      web_research:!!state.workflow.web_research,live_analysis:!!state.workflow.live_analysis,
      depth:String(state.workflow.depth||'standard'),context_mode:String(state.workflow.context_mode||'authorized'),focus:String(state.workflow.focus||''),
    };
  }

  function planText(plan = localRunPlan()) {
    const ai = Array.isArray(plan.ai_apps) ? plan.ai_apps.length : 0;
    const deterministic = Array.isArray(plan.deterministic_apps) ? plan.deterministic_apps.length : 0;
    const batches = Math.max(0, Number(plan.ai_batches || 0));
    const pieces = [];
    if (ai) pieces.push(`${ai} AI app${ai === 1 ? '' : 's'} · ${batches} batch${batches === 1 ? '' : 'es'}`);
    if (deterministic) pieces.push(`${deterministic} token-free`);
    pieces.push(`${String(plan.estimated_ai_cost || 'none')} cost`);
    if (state.workflow.web_research) pieces.push('web research');
    if (state.workflow.depth === 'deep' && state.selectedApps.includes('changes')) pieces.push(`compare ${state.comparison.label}`);
    return pieces.join(' · ');
  }

  function itemText(item, primary = 'text') {
    if (!item || typeof item !== 'object') return clean(item);
    return clean(item[primary] ?? item.text ?? item.value ?? item.note ?? item.action ?? item.response ?? item.decision ?? item.commitment ?? item.moment ?? item.topic ?? item.name ?? item.risk ?? item.event ?? item.question ?? item.follow_up ?? item.opportunity ?? item.change ?? item.position ?? item.constraint ?? item.requirement ?? item.item ?? item.claim ?? item.objection ?? item.promise ?? '');
  }

  function metaHtml(item, fields = []) {
    if (!item || typeof item !== 'object') return '';
    const parts = [];
    fields.forEach(field => {
      const value = item[field];
      if (value === undefined || value === null || value === '' || value === false) return;
      let rendered = value;
      if (Array.isArray(value)) rendered = value.join(', ');
      if (typeof value === 'boolean') rendered = value ? 'Yes' : 'No';
      parts.push(`<span><b>${esc(field.replaceAll('_', ' '))}</b> ${esc(rendered)}</span>`);
    });
    return parts.length ? `<div class="sf-listening-ai-item-meta">${parts.join('')}</div>` : '';
  }

  function actionReceipt(item, action, targetId = 0) {
    const key = `${action}${Number(targetId || 0) > 0 ? `:${Number(targetId)}` : ''}`;
    const receipt = item?.actions?.[key];
    return receipt && typeof receipt === 'object' ? receipt : null;
  }

  function actionButtonHtml(item, action, label, targetId = 0) {
    const receipt = actionReceipt(item, action, targetId);
    if (receipt) {
      const text = clean(receipt.label || label);
      const link = clean(receipt.target_url || '');
      return link
        ? `<a class="sf-listening-ai-action-receipt" href="${esc(link)}">✓ ${esc(text)}</a>`
        : `<span class="sf-listening-ai-action-receipt">✓ ${esc(text)}</span>`;
    }
    return `<button type="button" data-listening-ai-item-action="${esc(action)}" data-listening-ai-action-item="${esc(item.item_id)}" data-listening-ai-action-target="${Number(targetId || 0)}">${esc(label)}</button>`;
  }

  function operationalActionsHtml(item, appId) {
    if (!item || typeof item !== 'object' || item.review_state !== 'accepted') return '';
    const options = [];
    if (state.operations?.main_chat?.available) options.push(actionButtonHtml(item,'main_chat','Main Chat'));
    if (state.operations?.agent_brain?.available) options.push(actionButtonHtml(item,'agent_brain','Agent Brain'));
    if (state.operations?.agent_task?.available) {
      const commitment = appId === 'decisions' && String(item.section_key || '') === 'commitments';
      options.push(actionButtonHtml(item,'agent_task',commitment ? 'Create Commitment' : 'Create Task'));
    }
    if (state.operations?.personal_knowledge?.available) options.push(actionButtonHtml(item,'personal_knowledge','Knowledge'));
    if (state.operations?.project_note?.available) {
      const title = clean(state.operations.project_note.title || '');
      options.push(actionButtonHtml(item,'project_note',title ? `Project Note · ${title}` : 'Project Note'));
    }
    if (state.operations?.crm?.available) {
      (Array.isArray(state.operations.crm.targets) ? state.operations.crm.targets : []).forEach(target => {
        const leadId = Math.max(0, Number(target?.lead_id || 0));
        if (!leadId) return;
        const label = clean(target?.name || target?.company || `Lead ${leadId}`);
        options.push(actionButtonHtml(item,'crm_note',`CRM Note · ${label}`,leadId));
        options.push(actionButtonHtml(item,'crm_task',`CRM Task · ${label}`,leadId));
      });
    }
    if (!options.length) return '';
    return `<details class="sf-listening-ai-operational"><summary>Actions</summary><div class="sf-listening-ai-operational-menu">${options.join('')}</div></details>`;
  }

  function relationTypeLabel(type, direction) {
    const key = `${String(type || '')}:${String(direction || '')}`;
    return ({
      'supports:outgoing':'supports','supports:incoming':'supported by',
      'contradicts:outgoing':'contradicts','contradicts:incoming':'contradicted by',
      'depends_on:outgoing':'depends on','depends_on:incoming':'dependency for',
      'answers:outgoing':'answers','answers:incoming':'answered by',
      'follows_from:outgoing':'follows from','follows_from:incoming':'leads to',
      'blocks:outgoing':'blocks','blocks:incoming':'blocked by',
      'duplicates:peer':'duplicates','related:peer':'related to',
    })[key] || String(type || 'related').replaceAll('_',' ');
  }

  function relationsHtml(item) {
    const rows = Array.isArray(item?.relations) ? item.relations : [];
    if (!rows.length) return '';
    return `<details class="sf-listening-ai-relations"><summary>Connections ${rows.length}</summary><div class="sf-listening-ai-relations-list">${rows.map(relation => {
      const id = clean(relation?.relation_id || '');
      if (!id) return '';
      const review = clean(relation?.review_state || 'unreviewed');
      const evidence = (Array.isArray(relation?.evidence_refs) ? relation.evidence_refs : []).map(ref => {
        const page = Math.max(0, Number(ref?.page || 0));
        return page ? `<button type="button" data-listening-ai-evidence="${page}">${esc(ref.label || `Page ${page}`)}</button>` : '';
      }).join('');
      return `<article class="sf-listening-ai-relation relation-${esc(review)}"><div class="sf-listening-ai-relation-head"><b>${esc(relationTypeLabel(relation.type,relation.direction))}</b><span>${esc(relation.other_plugin_title || relation.other_plugin_id || 'Intelligence')} · ${esc(relation.confidence || 'medium')}</span></div><p>${esc(relation.other_text || '')}</p>${relation.rationale ? `<small>${esc(relation.rationale)}</small>` : ''}<div class="sf-listening-ai-relation-actions">${evidence}<button type="button" data-listening-ai-relation-review="accepted" data-listening-ai-relation="${esc(id)}" class="${review === 'accepted' ? 'active' : ''}">Accept</button><button type="button" data-listening-ai-relation-review="rejected" data-listening-ai-relation="${esc(id)}" class="${review === 'rejected' ? 'active' : ''}">Reject</button><em>${esc(review)}</em></div></article>`;
    }).join('')}</div></details>`;
  }

  function itemActionsHtml(item, appId) {
    if (!item || typeof item !== 'object' || !clean(item.item_id)) return '';
    const itemId = String(item.item_id);
    const review = String(item.review_state || 'unreviewed');
    const refs = Array.isArray(item.evidence_refs) ? item.evidence_refs : [];
    const evidence = refs.map(ref => {
      const page = Math.max(0, Number(ref?.page || 0));
      if (!page) return '';
      return `<button type="button" data-listening-ai-evidence="${page}" title="Open transcript evidence">${esc(ref.label || `Page ${page}`)}</button>`;
    }).join('');
    if (state.editingItemId === itemId) {
      return `<div class="sf-listening-ai-item-actions" data-listening-ai-item-actions>${evidence}<button type="button" data-listening-ai-edit-save="${esc(itemId)}">Save edit</button><button type="button" data-listening-ai-edit-cancel="${esc(itemId)}">Cancel</button></div>`;
    }
    return `<div class="sf-listening-ai-item-actions" data-listening-ai-item-actions>${evidence}<button type="button" data-listening-ai-review="accepted" data-listening-ai-item="${esc(itemId)}" class="${review === 'accepted' ? 'active' : ''}">Accept</button><button type="button" data-listening-ai-edit="${esc(itemId)}">Edit</button><button type="button" data-listening-ai-review="rejected" data-listening-ai-item="${esc(itemId)}" class="${review === 'rejected' ? 'active' : ''}">Reject</button><small>${esc(review === 'unreviewed' ? 'Unreviewed' : review)}</small></div>${relationsHtml(item)}${operationalActionsHtml(item,appId)}`;
  }

  function sectionHtml(section, result, appId) {
    const rows = Array.isArray(result?.[section.key]) ? result[section.key] : [];
    if (!rows.length) return '';
    return `<section><h4>${esc(section.title || section.key)}</h4><ul class="sf-listening-ai-structured-list">${rows.map(item => {
      const text = itemText(item, String(section.primary || 'text'));
      if (!text) return '';
      const itemId = clean(item?.item_id || '');
      const review = clean(item?.review_state || 'unreviewed');
      const editing = itemId && state.editingItemId === itemId;
      const body = editing
        ? `<textarea data-listening-ai-edit-input="${esc(itemId)}" rows="4">${esc(text)}</textarea>`
        : `<p>${esc(text)}</p>`;
      return `<li class="sf-listening-ai-structured-item review-${esc(review)}" data-listening-ai-item-row="${esc(itemId)}" data-listening-ai-app-id="${esc(appId)}">${body}${metaHtml(item, Array.isArray(section.meta) ? section.meta : [])}${itemActionsHtml(item, appId)}</li>`;
    }).join('')}</ul></section>`;
  }

  function researchHtml() {
    const research = state.report?.research || {};
    const text = clean(research.text || '');
    const sources = Array.isArray(research.sources) ? research.sources : [];
    if (!text && !sources.length) return '';
    return `<section><h4>External Research</h4>${text ? `<p>${esc(research.text || '').replace(/\n/g, '<br>')}</p>` : ''}${sources.length ? `<div class="sf-listening-ai-sources">${sources.map(source => `<a href="${esc(source.url)}" target="_blank" rel="noopener noreferrer">${esc(source.title || source.url)} ↗</a>`).join('')}</div>` : ''}</section>`;
  }

  function statsHtml(stats = {}) {
    const speakers = Array.isArray(stats.speakers) ? stats.speakers : [];
    const cards = [
      ['Words', Number(stats.total_words || 0).toLocaleString()],
      ['Duration', String(stats.duration_label || '0:00')],
      ['Turns', Number(stats.transcript_turns || 0).toLocaleString()],
      ['Speakers', Number(stats.speaker_count || 0).toLocaleString()],
      ['Questions', Number(stats.question_count || 0).toLocaleString()],
      ['Words / min', Number(stats.words_per_minute || 0).toLocaleString()],
      ['Avg words / turn', Number(stats.avg_words_per_turn || 0).toLocaleString()],
      ['Longest turn', Number(stats.longest_turn_words || 0).toLocaleString()],
    ];
    const bars = speakers.length ? `<section><h4>Speaker share</h4><div class="sf-listening-ai-chart">${speakers.map(row => {
      const share = Math.max(0, Math.min(100, Number(row.word_share || 0)));
      const questionBits = Number(row.questions || 0) ? ` · ${Number(row.questions)} questions` : '';
      return `<div class="sf-listening-ai-chart-row"><div class="sf-listening-ai-chart-label"><span>${esc(row.label || 'Speaker')}</span><b>${Number(row.words || 0).toLocaleString()} words · ${share.toFixed(1)}%${questionBits}</b></div><div class="sf-listening-ai-chart-track"><i style="width:${share}%"></i></div></div>`;
    }).join('')}</div></section>` : '';
    return `<div class="sf-listening-ai-stat-grid">${cards.map(([label,value]) => `<div><small>${esc(label)}</small><strong>${esc(value)}</strong></div>`).join('')}</div>${bars}`;
  }

  function appResultHtml(app, result = {}) {
    if (!app) return '<p class="sf-listening-ai-empty">Unknown transcription app.</p>';
    const pluginError = clean(state.pluginErrors?.[app.id] || '');
    if (!state.appStatus?.[app.id]?.generated) {
      return pluginError
        ? `<div class="sf-listening-ai-plugin-error"><strong>${esc(app.title)} needs a retry.</strong><p>${esc(pluginError)}</p></div>`
        : `<p class="sf-listening-ai-empty">Run Analyze to generate ${esc(app.title)}.</p>`;
    }
    if (app.view === 'stats') return statsHtml(result);
    const error = pluginError ? `<div class="sf-listening-ai-plugin-error"><strong>Latest run needs a retry.</strong><p>${esc(pluginError)}</p></div>` : '';
    const header = app.id === 'basic'
      ? `${result.summary ? `<p class="sf-listening-ai-report-copy">${esc(result.summary)}</p>` : ''}${result.analysis ? `<section><h4>Interpretation</h4><p>${esc(result.analysis)}</p></section>` : ''}`
      : '';
    const sections = (Array.isArray(app.sections) ? app.sections : []).map(section => sectionHtml(section, result, String(app.id))).join('');
    const research = app.id === 'basic' ? researchHtml() : '';
    return header || sections || research || error ? `${error}${header}${sections}${research}` : `<p class="sf-listening-ai-empty">No supported findings were identified for ${esc(app.title)}.</p>`;
  }

  function activeResult() {
    const modules = state.report?.analysis?.modules || {};
    return modules?.[state.activeApp]?.result || {};
  }

  function applyServerView(data) {
    if (Array.isArray(data.registry) && data.registry.length) setRegistry(data.registry);
    if (data.workflow_config) setWorkflowConfig(data.workflow_config);
    if (data.workflow && typeof data.workflow === 'object') {
      state.workflow = normalizeWorkflow(data.workflow);
      persistWorkflow();
    }
    if (Array.isArray(data.comparison_targets)) setComparisonTargets(data.comparison_targets);
    if (data.comparison && typeof data.comparison === 'object') syncComparison(data.comparison);
    state.report = data.master || state.report;
    state.appStatus = data.app_status || state.appStatus;
    state.permissions = data.permissions || state.permissions;
    state.operations = data.operations || state.operations;
    state.runPlan = data.run_plan || state.runPlan;
    state.pluginErrors = data.plugin_errors || {};
    state.relationsSummary = data.relations_summary || state.relationsSummary;
  }

  function ensurePanel() {
    let panel = document.getElementById('sfListeningAiPanel');
    if (panel) return panel;
    panel = document.createElement('aside');
    panel.id = 'sfListeningAiPanel';
    panel.className = 'sf-listening-ai-panel';
    panel.setAttribute('aria-label', 'AI Summary');
    panel.innerHTML = `
      <header class="sf-listening-ai-head">
        <div><small>AI AGENT</small><h2>AI Summary</h2></div>
        <div class="sf-listening-ai-head-actions">
          <button type="button" class="sf-listening-ai-power" data-listening-ai-live aria-pressed="false">Live OFF</button>
          <button type="button" class="sf-listening-ai-power" data-listening-ai-research aria-pressed="false">Research OFF</button>
          <button type="button" data-listening-ai-close aria-label="Close AI Summary">×</button>
        </div>
      </header>
      <div class="sf-listening-ai-status sf-listening-ai-status-row">
        <span data-listening-ai-status>AI Summary ready.</span>
        <button type="button" class="sf-listening-ai-settings" data-listening-ai-settings aria-expanded="false" aria-controls="sfListeningAiApps" aria-label="Toggle transcription workflow settings" title="Transcription workflow settings"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.8 1.8 0 0 0 .36 1.98l.06.06-2.78 2.78-.06-.06A1.8 1.8 0 0 0 15 19.4a1.8 1.8 0 0 0-1.08 1.65V21h-3.84v-.09A1.8 1.8 0 0 0 9 19.4a1.8 1.8 0 0 0-1.98.36l-.06.06-2.78-2.78.06-.06A1.8 1.8 0 0 0 4.6 15a1.8 1.8 0 0 0-1.65-1.08H3v-3.84h.09A1.8 1.8 0 0 0 4.6 9a1.8 1.8 0 0 0-.36-1.98l-.06-.06 2.78-2.78.06.06A1.8 1.8 0 0 0 9 4.6a1.8 1.8 0 0 0 1.08-1.65V3h3.84v.09A1.8 1.8 0 0 0 15 4.6a1.8 1.8 0 0 0 1.98-.36l.06-.06 2.78 2.78-.06.06A1.8 1.8 0 0 0 19.4 9a1.8 1.8 0 0 0 1.65 1.08H21v3.84h-.09A1.8 1.8 0 0 0 19.4 15Z"></path></svg></button>
      </div>
      <section class="sf-listening-ai-apps" id="sfListeningAiApps" hidden>
        <div class="sf-listening-ai-apps-head"><strong>Workflow</strong><span>Preset + run profile</span></div>
        <div class="sf-listening-ai-presets" data-listening-ai-presets></div>
        <div class="sf-listening-ai-workflow-grid">
          <label><span>Depth</span><select data-listening-ai-depth></select></label>
          <label><span>Context</span><select data-listening-ai-context></select></label>
          <label data-listening-ai-comparison-wrap hidden><span>Compare</span><select data-listening-ai-comparison></select></label>
          <label class="sf-listening-ai-focus"><span>Focus</span><input type="text" maxlength="240" data-listening-ai-focus placeholder="Optional analysis focus"></label>
        </div>
        <div class="sf-listening-ai-run-plan" data-listening-ai-run-plan></div>
        <div class="sf-listening-ai-apps-head sf-listening-ai-apps-head-secondary"><strong>Transcription apps</strong><span>Select what Analyze should run</span></div>
        <div class="sf-listening-ai-app-options" data-listening-ai-app-options></div>
      </section>
      <nav class="sf-listening-ai-tabs" data-listening-ai-tabs aria-label="AI result tabs"></nav>
      <div class="sf-listening-ai-scroll"><section class="sf-listening-ai-report" data-listening-ai-report></section></div>
      <footer class="sf-listening-ai-footer"><div class="sf-listening-ai-footer-actions">
        <button type="button" data-listening-ai-analyze>Analyze</button>
        <button type="button" data-listening-ai-relations>Connections</button>
        <button type="button" data-listening-ai-brain>Add to Agent Brain</button>
        <button type="button" data-listening-ai-knowledge>Add to Knowledge Base</button>
      </div></footer>`;
    document.body.appendChild(panel);

    panel.querySelector('[data-listening-ai-close]')?.addEventListener('click', () => setOpen(false));
    panel.querySelector('[data-listening-ai-live]')?.addEventListener('click', () => setLiveAnalysisEnabled(!state.workflow.live_analysis));
    panel.querySelector('[data-listening-ai-research]')?.addEventListener('click', () => setResearchEnabled(!state.workflow.web_research));
    panel.querySelector('[data-listening-ai-settings]')?.addEventListener('click', () => setSettingsOpen(!state.settingsOpen));
    panel.querySelector('[data-listening-ai-analyze]')?.addEventListener('click', () => void analyze('manual'));
    panel.querySelector('[data-listening-ai-relations]')?.addEventListener('click', () => void buildRelations());
    panel.querySelector('[data-listening-ai-brain]')?.addEventListener('click', () => void saveResult('save_brain'));
    panel.querySelector('[data-listening-ai-knowledge]')?.addEventListener('click', () => void saveResult('save_knowledge'));
    panel.querySelector('[data-listening-ai-tabs]')?.addEventListener('click', event => {
      const button = event.target.closest('[data-listening-ai-tab]');
      if (button) setActiveApp(String(button.dataset.listeningAiTab || ''));
    });
    panel.querySelector('[data-listening-ai-presets]')?.addEventListener('click', event => {
      const button = event.target.closest('[data-listening-ai-preset]');
      if (button) applyPreset(String(button.dataset.listeningAiPreset || ''));
    });
    panel.querySelector('[data-listening-ai-depth]')?.addEventListener('change', event => setWorkflowField('depth', String(event.target.value || 'standard')));
    panel.querySelector('[data-listening-ai-context]')?.addEventListener('change', event => setWorkflowField('context_mode', String(event.target.value || 'authorized')));
    panel.querySelector('[data-listening-ai-comparison]')?.addEventListener('change', event => setComparisonTarget(String(event.target.value || 'project_history'), true));
    panel.querySelector('[data-listening-ai-focus]')?.addEventListener('change', event => setWorkflowField('focus', String(event.target.value || '')));
    panel.querySelector('[data-listening-ai-app-options]')?.addEventListener('change', event => {
      const input = event.target.closest('[data-listening-ai-app]');
      if (input) setAppSelected(String(input.dataset.listeningAiApp || ''), input.checked);
    });
    panel.querySelector('[data-listening-ai-report]')?.addEventListener('click', event => {
      const rerun = event.target.closest('[data-listening-ai-rerun]');
      if (rerun) { void analyzeApps([state.activeApp], 'manual'); return; }
      const evidence = event.target.closest('[data-listening-ai-evidence]');
      if (evidence) { void focusEvidence(Number(evidence.dataset.listeningAiEvidence || 0)); return; }
      const relationReview = event.target.closest('[data-listening-ai-relation-review]');
      if (relationReview) {
        void reviewRelation(String(relationReview.dataset.listeningAiRelation || ''),String(relationReview.dataset.listeningAiRelationReview || 'unreviewed'));
        return;
      }
      const review = event.target.closest('[data-listening-ai-review]');
      if (review) { void reviewItem(String(review.dataset.listeningAiItem || ''), String(review.dataset.listeningAiReview || 'unreviewed')); return; }
      const itemAction = event.target.closest('[data-listening-ai-item-action]');
      if (itemAction) {
        void performItemAction(
          String(itemAction.dataset.listeningAiActionItem || ''),
          String(itemAction.dataset.listeningAiItemAction || ''),
          Number(itemAction.dataset.listeningAiActionTarget || 0)
        );
        return;
      }
      const edit = event.target.closest('[data-listening-ai-edit]');
      if (edit) { state.editingItemId = String(edit.dataset.listeningAiEdit || ''); renderReport(); return; }
      const cancel = event.target.closest('[data-listening-ai-edit-cancel]');
      if (cancel) { state.editingItemId = ''; renderReport(); return; }
      const save = event.target.closest('[data-listening-ai-edit-save]');
      if (save) {
        const itemId = String(save.dataset.listeningAiEditSave || '');
        const row = save.closest('[data-listening-ai-item-row]');
        const input = row?.querySelector('[data-listening-ai-edit-input]');
        void editItem(itemId, String(input?.value || ''));
      }
    });

    let shade = document.querySelector('[data-listening-ai-shade]');
    if (!shade) {
      shade = document.createElement('button');
      shade.type = 'button';
      shade.className = 'sf-listening-ai-shade';
      shade.dataset.listeningAiShade = '1';
      shade.setAttribute('aria-label', 'Close AI Summary');
      shade.addEventListener('click', () => setOpen(false));
      document.body.appendChild(shade);
    }
    return panel;
  }

  function renderPresets() {
    const box = document.querySelector('[data-listening-ai-presets]');
    if (!box) return;
    const presets = Array.isArray(state.workflowConfig?.presets) ? state.workflowConfig.presets : [];
    box.innerHTML = presets.map(preset => `<button type="button" data-listening-ai-preset="${esc(preset.id)}" class="${state.workflow.preset === preset.id ? 'active' : ''}" title="${esc(preset.description || '')}">${esc(preset.title || preset.id)}</button>`).join('');
  }

  function renderWorkflowControls() {
    const depth = document.querySelector('[data-listening-ai-depth]');
    const context = document.querySelector('[data-listening-ai-context]');
    const compareWrap = document.querySelector('[data-listening-ai-comparison-wrap]');
    const compare = document.querySelector('[data-listening-ai-comparison]');
    const focus = document.querySelector('[data-listening-ai-focus]');
    const plan = document.querySelector('[data-listening-ai-run-plan]');
    if (depth) {
      const rows = Array.isArray(state.workflowConfig?.depth_options) ? state.workflowConfig.depth_options : [];
      depth.innerHTML = rows.map(row => `<option value="${esc(row.id)}" ${state.workflow.depth === row.id ? 'selected' : ''}>${esc(row.title || row.id)}</option>`).join('');
    }
    if (context) {
      const rows = Array.isArray(state.workflowConfig?.context_options) ? state.workflowConfig.context_options : [];
      context.innerHTML = rows.map(row => `<option value="${esc(row.id)}" ${state.workflow.context_mode === row.id ? 'selected' : ''}>${esc(row.title || row.id)}</option>`).join('');
    }
    const comparisonActive = state.selectedApps.includes('changes');
    if (compareWrap) compareWrap.hidden = !comparisonActive;
    if (compare) {
      compare.disabled = !comparisonActive || !state.comparisonTargets.length;
      compare.innerHTML = state.comparisonTargets.map(row => `<option value="${esc(row.id)}" ${row.id === state.comparison.id ? 'selected' : ''}>${esc(row.label)}</option>`).join('');
    }
    if (focus && focus.value !== state.workflow.focus) focus.value = state.workflow.focus;
    if (plan) plan.textContent = `Run plan · ${planText(localRunPlan())}`;
  }

  function renderApps() {
    const box = document.querySelector('[data-listening-ai-app-options]');
    if (!box) return;
    box.innerHTML = state.registry.map(app => {
      const checked = state.selectedApps.includes(String(app.id));
      const status = state.appStatus?.[app.id] || {};
      const manual = app.live ? '' : ' · manual only';
      return `<label title="${esc(app.description || '')}"><input type="checkbox" data-listening-ai-app="${esc(app.id)}" ${checked ? 'checked' : ''}><span><b>${esc(app.title)}</b><small>${esc(app.execution === 'deterministic' ? 'No AI tokens' : (app.description || ''))}${manual}${status.generated ? ` · ${status.fresh ? 'current' : 'refresh'}` : ''}</small></span></label>`;
    }).join('');
  }

  function renderTabs() {
    const tabs = document.querySelector('[data-listening-ai-tabs]');
    if (!tabs) return;
    tabs.innerHTML = state.selectedApps.map(id => {
      const app = appById(id);
      if (!app) return '';
      const active = id === state.activeApp;
      const status = state.appStatus?.[id] || {};
      const error = clean(state.pluginErrors?.[id] || '');
      return `<button type="button" data-listening-ai-tab="${esc(id)}" class="${active ? 'active' : ''} ${status.generated && !status.fresh ? 'stale' : ''} ${error ? 'error' : ''}" aria-selected="${active ? 'true' : 'false'}">${esc(app.label)}${error ? ' !' : (status.generated && !status.fresh ? ' •' : '')}</button>`;
    }).join('');
  }

  function renderReport() {
    const node = document.querySelector('[data-listening-ai-report]');
    if (!node) return;
    const app = appById(state.activeApp);
    const action = clean(state.actionMessage);
    const canRun = !!app && !state.busy;
    const meta = `<div class="sf-listening-ai-report-state"><div><strong>${app ? esc(app.title) : 'Transcription App'}</strong><span>${esc(action || freshnessText(state.activeApp))}</span></div>${app ? `<button type="button" data-listening-ai-rerun ${canRun ? '' : 'disabled'}>${state.busyApp === state.activeApp ? 'Running…' : 'Run Again'}</button>` : ''}</div>`;
    node.innerHTML = `${meta}${appResultHtml(app, activeResult())}`;
  }

  function render() {
    const panel = ensurePanel();
    const button = getButton();
    panel.classList.toggle('open', state.open);
    document.body.classList.toggle('sf-listening-ai-open', state.open);
    if (button) {
      button.setAttribute('aria-expanded', state.open ? 'true' : 'false');
      button.classList.toggle('on', state.workflow.live_analysis || state.workflow.web_research);
      const badge = button.querySelector('[data-listening-ai-badge]');
      if (badge) badge.textContent = state.workflow.live_analysis ? 'LIVE' : (state.workflow.web_research ? 'WEB' : 'OFF');
    }
    const live = panel.querySelector('[data-listening-ai-live]');
    if (live) {
      live.textContent = state.workflow.live_analysis ? 'Live ON' : 'Live OFF';
      live.setAttribute('aria-pressed', state.workflow.live_analysis ? 'true' : 'false');
      live.classList.toggle('on', state.workflow.live_analysis);
    }
    const research = panel.querySelector('[data-listening-ai-research]');
    if (research) {
      research.textContent = state.workflow.web_research ? 'Research ON' : 'Research OFF';
      research.setAttribute('aria-pressed', state.workflow.web_research ? 'true' : 'false');
      research.classList.toggle('on', state.workflow.web_research);
    }
    const settings = panel.querySelector('[data-listening-ai-settings]');
    if (settings) {
      settings.setAttribute('aria-expanded', state.settingsOpen ? 'true' : 'false');
      settings.classList.toggle('open', state.settingsOpen);
    }
    const apps = panel.querySelector('#sfListeningAiApps');
    if (apps) apps.hidden = !state.settingsOpen;
    const analyzeButton = panel.querySelector('[data-listening-ai-analyze]');
    if (analyzeButton) {
      analyzeButton.disabled = state.busy || !currentSessionId() || !state.selectedApps.length;
      analyzeButton.textContent = state.busy && !state.busyRelations ? 'Analyzing…' : `Analyze ${state.selectedApps.length}`;
    }
    const relations = panel.querySelector('[data-listening-ai-relations]');
    if (relations) {
      relations.disabled = state.busy || !currentSessionId() || !state.report;
      const count = Math.max(0,Number(state.relationsSummary?.total || 0));
      relations.textContent = state.busyRelations ? 'Connecting…' : `Connections${count ? ` ${count}` : ''}`;
    }
    const brain = panel.querySelector('[data-listening-ai-brain]');
    const knowledge = panel.querySelector('[data-listening-ai-knowledge]');
    if (brain) brain.disabled = state.busy || !state.report || !state.permissions?.agent_brain_write;
    if (knowledge) knowledge.disabled = state.busy || !state.report || !state.permissions?.personal_knowledge_write;
    const status = panel.querySelector('[data-listening-ai-status]');
    if (status) {
      if (state.lastError) status.textContent = state.lastError;
      else if (state.busyRelations) status.textContent = 'Building cross-plugin intelligence connections…';
      else if (state.busy) status.textContent = state.busyApp ? `Running ${appById(state.busyApp)?.title || 'transcription plugin'}…` : `Running ${state.selectedApps.length} transcription app${state.selectedApps.length === 1 ? '' : 's'}…`;
      else if (!currentSessionId()) status.textContent = 'Open a transcription to use AI Summary.';
      else status.textContent = `${state.workflow.live_analysis ? 'Live ON' : 'Live OFF'} · ${state.workflow.web_research ? 'Research ON' : 'Research OFF'} · ${state.liveWords.toLocaleString()} words · ${Math.max(0,Number(state.relationsSummary?.total||0))} connections · ${planText(localRunPlan())}`;
    }
    renderPresets();
    renderWorkflowControls();
    renderApps();
    renderTabs();
    renderReport();
  }

  function getButton() {
    return document.querySelector('[data-listening-ai-toggle]');
  }

  function setOpen(open) {
    const next = Boolean(open);
    if (next !== state.open) {
      state.open = next;
      if (next) proof.panelOpens += 1;
      else proof.panelCloses += 1;
    }
    render();
    return state.open;
  }

  function setWorkflow(next, markCustom = true) {
    const merged = normalizeWorkflow({...state.workflow, ...(next || {})});
    if (markCustom) merged.preset = 'custom';
    state.workflow = merged;
    state.actionMessage = '';
    persistWorkflow();
    if (!state.workflow.live_analysis && state.liveTimer) {
      clearTimeout(state.liveTimer);
      state.liveTimer = 0;
    }
    render();
    return {...state.workflow};
  }

  function setWorkflowField(key, value) {
    if (!['depth','focus','context_mode'].includes(key)) return {...state.workflow};
    return setWorkflow({[key]:value}, true);
  }

  function setResearchEnabled(enabled) {
    return setWorkflow({web_research:Boolean(enabled)}, true).web_research;
  }

  function setLiveAnalysisEnabled(enabled) {
    const live = setWorkflow({live_analysis:Boolean(enabled)}, true).live_analysis;
    if (live) scheduleLive('toggle');
    return live;
  }

  function applyPreset(presetId) {
    const preset = presetById(presetId);
    if (!preset) return false;
    const allowed = new Set(state.registry.map(app => String(app.id)));
    const apps = (Array.isArray(preset.apps) ? preset.apps : []).map(String).filter(id => allowed.has(id));
    if (apps.length) {
      state.selectedApps = state.registry.map(app => String(app.id)).filter(id => apps.includes(id));
      if (!state.selectedApps.includes(state.activeApp)) state.activeApp = state.selectedApps[0];
      persistApps();
    }
    state.workflow = normalizeWorkflow({...preset.workflow, preset:String(preset.id)});
    state.workflow.preset = String(preset.id);
    state.pluginErrors = {};
    state.actionMessage = `${preset.title || preset.id} preset selected.`;
    persistWorkflow();
    proof.presetChanges += 1;
    if (!state.workflow.live_analysis && state.liveTimer) {
      clearTimeout(state.liveTimer);
      state.liveTimer = 0;
    }
    render();
    if (state.workflow.live_analysis) scheduleLive('preset');
    return true;
  }

  function setSettingsOpen(open) {
    state.settingsOpen = Boolean(open);
    render();
    return state.settingsOpen;
  }

  function setAppSelected(appId, selected) {
    if (!appById(appId)) return;
    const next = new Set(state.selectedApps);
    if (selected) next.add(appId); else next.delete(appId);
    if (!next.size) next.add('basic');
    state.selectedApps = state.registry.map(app => String(app.id)).filter(id => next.has(id));
    if (!state.selectedApps.includes(state.activeApp)) state.activeApp = state.selectedApps[0];
    state.workflow = normalizeWorkflow({...state.workflow,preset:'custom'});
    state.editingItemId = '';
    state.actionMessage = '';
    state.pluginErrors = {};
    persistApps();
    persistWorkflow();
    render();
  }

  function setActiveApp(appId) {
    if (!state.selectedApps.includes(appId)) return;
    state.activeApp = appId;
    state.editingItemId = '';
    state.actionMessage = '';
    renderTabs();
    renderReport();
  }

  async function loadRegistry() {
    const data = await request('registry', {}, 'GET');
    setRegistry(data.registry || []);
    if (data.workflow_config) setWorkflowConfig(data.workflow_config);
    persistApps();
    persistWorkflow();
  }

  async function loadStatus(sessionId = currentSessionId()) {
    sessionId = Math.max(0, Number(sessionId || 0));
    state.sessionId = sessionId;
    state.lastError = '';
    state.actionMessage = '';
    state.editingItemId = '';
    state.pluginErrors = {};
    if (!sessionId) {
      state.report = null;
      state.appStatus = {};
      state.permissions = {};
      state.operations = {};
      state.runPlan = null;
      state.relationsSummary = {total:0,accepted:0,rejected:0,unreviewed:0,types:{}};
      state.comparisonTargets = [];
      state.comparison = defaultComparison();
      state.liveWords = 0;
      state.lastReportedWords = 0;
      render();
      return;
    }
    try {
      const data = await request('status', {session_id:sessionId}, 'GET');
      if (currentSessionId() !== sessionId) return;
      applyServerView(data);
      state.liveWords = Math.max(state.liveWords, Number(state.report?.word_count || 0));
      state.lastReportedWords = Number(state.report?.word_count || 0);
      proof.lastError = '';
    } catch (error) {
      state.lastError = String(error?.message || error);
      proof.lastError = state.lastError;
    }
    render();
  }

  async function analyzeApps(appIds, mode = 'manual') {
    const sessionId = currentSessionId();
    const requested = [...new Set((Array.isArray(appIds) ? appIds : []).map(String).filter(id => appById(id)))];
    if (!sessionId || state.busy || !requested.length) return;
    if (mode === 'live' && !state.workflow.live_analysis) return;
    state.busy = true;
    state.busyApp = requested.length === 1 ? requested[0] : '';
    state.lastError = '';
    state.actionMessage = '';
    state.editingItemId = '';
    state.runPlan = localRunPlan(requested,mode);
    state.pluginErrors = {};
    render();
    try {
      const data = await request('analyze', {session_id:sessionId,mode,apps:requested,workflow:{...state.workflow},comparison:comparisonPayload()});
      if (currentSessionId() !== sessionId) return;
      applyServerView(data);
      state.lastReportedWords = Number(state.report?.word_count || state.liveWords || state.lastReportedWords);
      if (!data.skipped) {
        proof.reports += 1;
        if (mode === 'live') proof.liveReports += 1;
      }
      const executed = Array.isArray(data.executed_apps) ? data.executed_apps.length : 0;
      const errors = Object.keys(data.plugin_errors || {}).length;
      const label = requested.length === 1 ? (appById(requested[0])?.title || 'Plugin') : `${requested.length} plugins`;
      if (data.skipped) state.actionMessage = `${label} is current.`;
      else if (errors) state.actionMessage = `${executed} completed · ${errors} need retry.`;
      else state.actionMessage = `${label} analyzed.`;
      proof.lastError = '';
    } catch (error) {
      state.lastError = String(error?.message || error);
      proof.lastError = state.lastError;
    } finally {
      state.busy = false;
      state.busyApp = '';
      render();
    }
  }

  async function analyze(mode = 'manual') {
    return analyzeApps(state.selectedApps, mode);
  }

  async function buildRelations() {
    const sessionId = currentSessionId();
    if (!sessionId || !state.report || state.busy) return;
    state.busy = true;
    state.busyRelations = true;
    state.lastError = '';
    state.actionMessage = '';
    render();
    try {
      const data = await request('build_relations',{session_id:sessionId});
      if (currentSessionId() !== sessionId) return;
      applyServerView(data);
      const count = Math.max(0,Number(data.relations_summary?.total || 0));
      state.actionMessage = `${count} cross-plugin connection${count === 1 ? '' : 's'} built.`;
      proof.relationBuilds += 1;
      proof.lastError = '';
    } catch (error) {
      state.lastError = String(error?.message || error);
      proof.lastError = state.lastError;
    } finally {
      state.busy = false;
      state.busyRelations = false;
      render();
    }
  }

  async function reviewRelation(relationId, reviewState) {
    const sessionId = currentSessionId();
    relationId = clean(relationId);
    if (!sessionId || !relationId || state.busy) return;
    state.busy = true;
    state.lastError = '';
    try {
      const data = await request('review_relation',{session_id:sessionId,relation_id:relationId,review_state:reviewState});
      applyServerView(data);
      state.actionMessage = reviewState === 'accepted' ? 'Connection accepted.' : (reviewState === 'rejected' ? 'Connection rejected.' : 'Connection review cleared.');
      proof.relationReviews += 1;
      proof.lastError = '';
    } catch (error) {
      state.lastError = String(error?.message || error);
      proof.lastError = state.lastError;
    } finally {
      state.busy = false;
      render();
    }
  }

  async function reviewItem(itemId, reviewState) {
    const sessionId = currentSessionId();
    if (!sessionId || !itemId || state.busy) return;
    state.busy = true;
    state.busyApp = state.activeApp;
    state.lastError = '';
    try {
      const data = await request('review_item', {session_id:sessionId,app_id:state.activeApp,item_id:itemId,review_state:reviewState});
      applyServerView(data);
      state.actionMessage = reviewState === 'accepted' ? 'Item accepted.' : (reviewState === 'rejected' ? 'Item rejected.' : 'Review cleared.');
      proof.itemReviews += 1;
    } catch (error) {
      state.lastError = String(error?.message || error);
      proof.lastError = state.lastError;
    } finally {
      state.busy = false;
      state.busyApp = '';
      render();
    }
  }

  async function editItem(itemId, text) {
    const sessionId = currentSessionId();
    text = clean(text);
    if (!sessionId || !itemId || !text || state.busy) return;
    state.busy = true;
    state.busyApp = state.activeApp;
    state.lastError = '';
    try {
      const data = await request('edit_item', {session_id:sessionId,app_id:state.activeApp,item_id:itemId,text});
      applyServerView(data);
      state.editingItemId = '';
      state.actionMessage = 'Item edited and accepted. Connections touching it were invalidated.';
      proof.itemEdits += 1;
    } catch (error) {
      state.lastError = String(error?.message || error);
      proof.lastError = state.lastError;
    } finally {
      state.busy = false;
      state.busyApp = '';
      render();
    }
  }

  async function performItemAction(itemId, itemAction, targetId = 0) {
    const sessionId = currentSessionId();
    itemId = clean(itemId);
    itemAction = clean(itemAction);
    targetId = Math.max(0, Number(targetId || 0));
    if (!sessionId || !itemId || !itemAction || state.busy) return;
    state.busy = true;
    state.busyApp = state.activeApp;
    state.lastError = '';
    state.actionMessage = '';
    render();
    try {
      const data = await request('item_action', {
        session_id:sessionId,app_id:state.activeApp,item_id:itemId,item_action:itemAction,target_id:targetId
      });
      applyServerView(data);
      const receipt = data.receipt || {};
      state.actionMessage = data.existing
        ? `Already completed${receipt.label ? ` · ${receipt.label}` : ''}.`
        : `${receipt.label || 'Action completed'}.`;
      if (!data.existing) proof.itemActions += 1;
      proof.lastError = '';
    } catch (error) {
      state.lastError = String(error?.message || error);
      proof.lastError = state.lastError;
    } finally {
      state.busy = false;
      state.busyApp = '';
      render();
    }
  }

  async function focusEvidence(page) {
    page = Math.max(0, Number(page || 0));
    if (!page) return;
    const transcript = window.STONEFELLOW_ARTIST_LISTENING_TRANSCRIPT?.api;
    if (!transcript?.goPage) {
      state.lastError = 'Transcript evidence navigation is unavailable.';
      render();
      return;
    }
    try {
      const transcriptState = transcript.getState?.() || {};
      if (transcriptState.view === 'continuous' && transcript.setView) transcript.setView('page');
      transcript.goPage(page);
      proof.evidenceJumps += 1;
      setOpen(false);
      window.dispatchEvent(new CustomEvent('stonefellow:artist-listening-evidence-requested', {
        detail:{sessionId:currentSessionId(),page,pluginId:state.activeApp,source:'transcription-intelligence'}
      }));
      setTimeout(() => {
        const target = document.querySelector('.sf-listening-workspace-document-area');
        target?.scrollIntoView({behavior:'smooth',block:'start'});
      }, 180);
    } catch (error) {
      state.lastError = String(error?.message || error);
      proof.lastError = state.lastError;
      render();
    }
  }

  async function saveResult(action) {
    const sessionId = currentSessionId();
    if (!sessionId || !state.report || state.busy) return;
    state.busy = true;
    state.lastError = '';
    state.actionMessage = '';
    render();
    try {
      const data = await request(action, {session_id:sessionId});
      if (action === 'save_brain') {
        proof.brainSaves += 1;
        state.actionMessage = `Added current reviewed intelligence to Agent Brain${data.saved_at ? ` · ${formatSaved(data.saved_at)}` : ''}.`;
      } else {
        proof.knowledgeSaves += 1;
        state.actionMessage = `Added current reviewed intelligence to Personal Knowledge Base${data.saved_at ? ` · ${formatSaved(data.saved_at)}` : ''}.`;
      }
      proof.lastError = '';
    } catch (error) {
      state.lastError = String(error?.message || error);
      proof.lastError = state.lastError;
    } finally {
      state.busy = false;
      render();
    }
  }

  function transcriptionAiState() {
    return {
      sessionId:currentSessionId(),open:!!state.open,settingsOpen:!!state.settingsOpen,
      researchEnabled:!!state.workflow.web_research,liveAnalysisEnabled:!!state.workflow.live_analysis,
      workflow:{...state.workflow},workflowConfig:JSON.parse(JSON.stringify(state.workflowConfig||{})),runPlan:JSON.parse(JSON.stringify(state.runPlan||localRunPlan())),
      pluginErrors:{...state.pluginErrors},relationsSummary:JSON.parse(JSON.stringify(state.relationsSummary||{})),
      comparison:{...state.comparison},comparisonTargets:JSON.parse(JSON.stringify(state.comparisonTargets||[])),
      selectedApps:[...state.selectedApps],activeApp:String(state.activeApp||''),busy:!!state.busy,busyApp:String(state.busyApp||''),
      report:state.report?JSON.parse(JSON.stringify(state.report)):null,appStatus:JSON.parse(JSON.stringify(state.appStatus||{})),
      registry:JSON.parse(JSON.stringify(state.registry||[])),permissions:{...state.permissions},operations:JSON.parse(JSON.stringify(state.operations||{})),
      liveWords:Math.max(0,Number(state.liveWords||0)),lastError:String(state.lastError||''),
    };
  }

  function transcriptionSetApps(ids = []) {
    const allowed = new Set(state.registry.map(app => String(app.id)));
    const requested = new Set((Array.isArray(ids) ? ids : []).map(String).filter(id => allowed.has(id)));
    if (!requested.size) throw new Error('Select at least one transcription analysis app.');
    state.selectedApps = state.registry.map(app => String(app.id)).filter(id => requested.has(id));
    if (!state.selectedApps.includes(state.activeApp)) state.activeApp = state.selectedApps[0];
    state.workflow = normalizeWorkflow({...state.workflow,preset:'custom'});
    state.actionMessage = '';
    persistApps();
    persistWorkflow();
    render();
    return [...state.selectedApps];
  }

  proof.api = {
    getState:transcriptionAiState,open:()=>setOpen(true),close:()=>setOpen(false),setResearchEnabled,setLiveAnalysisEnabled,
    setWorkflow:workflow=>setWorkflow(workflow,true),applyPreset:presetId=>applyPreset(String(presetId||'')),setApps:transcriptionSetApps,
    setComparison:targetId=>setComparisonTarget(String(targetId||'project_history'),true),
    setActiveApp:appId=>{setActiveApp(String(appId||''));return state.activeApp;},
    analyze:async(mode='manual')=>{await analyze(mode);if(state.lastError)throw new Error(state.lastError);return state.report;},
    analyzePlugin:async(appId,mode='manual')=>{await analyzeApps([String(appId||state.activeApp)],mode);if(state.lastError)throw new Error(state.lastError);return state.report;},
    buildRelations:async()=>{await buildRelations();if(state.lastError)throw new Error(state.lastError);return transcriptionAiState();},
    reviewRelation:async(relationId,reviewState)=>{await reviewRelation(String(relationId||''),String(reviewState||'unreviewed'));if(state.lastError)throw new Error(state.lastError);return state.report;},
    reviewItem:async(itemId,reviewState)=>{await reviewItem(String(itemId||''),String(reviewState||'unreviewed'));if(state.lastError)throw new Error(state.lastError);return state.report;},
    editItem:async(itemId,text)=>{await editItem(String(itemId||''),String(text||''));if(state.lastError)throw new Error(state.lastError);return state.report;},
    performItemAction:async(itemId,itemAction,targetId=0)=>{await performItemAction(String(itemId||''),String(itemAction||''),Number(targetId||0));if(state.lastError)throw new Error(state.lastError);return state.report;},
    focusEvidence:async page=>{await focusEvidence(page);if(state.lastError)throw new Error(state.lastError);return true;},
    saveBrain:async()=>{await saveResult('save_brain');if(state.lastError)throw new Error(state.lastError);return true;},
    saveKnowledge:async()=>{await saveResult('save_knowledge');if(state.lastError)throw new Error(state.lastError);return true;},
    loadStatus:async sessionId=>{await loadStatus(sessionId);if(state.lastError)throw new Error(state.lastError);return transcriptionAiState();},
  };

  function scheduleLive(reason = 'words') {
    if (!state.workflow.live_analysis || !currentSessionId() || state.busy) return;
    if (!state.report && state.liveWords < 120) return;
    const delta = Math.max(0, state.liveWords - state.lastReportedWords);
    if (reason === 'words' && state.report && delta < 250) return;
    if (state.liveTimer) clearTimeout(state.liveTimer);
    state.liveTimer = setTimeout(() => {
      state.liveTimer = 0;
      if (state.workflow.live_analysis && currentSessionId()) void analyze('live');
    }, reason === 'metadata' ? 450 : 1200);
  }

  function bindButton() {
    const button = getButton();
    if (!button) { state.bound = false; return false; }
    button.addEventListener('click', () => {
      proof.buttonClicks += 1;
      setOpen(!state.open);
    });
    state.bound = true;
    return true;
  }

  window.addEventListener('stonefellow:artist-listening-document-selected', event => {
    const session = event?.detail?.session;
    state.sessionId = Math.max(0, Number(session?.id || 0));
    state.liveWords = Math.max(0, Number(session?.word_count || 0));
    if (state.liveTimer) clearTimeout(state.liveTimer);
    state.liveTimer = 0;
    void loadStatus(state.sessionId);
  });

  window.addEventListener('stonefellow:artist-listening-live', event => {
    const detail = event?.detail || {};
    const id = Math.max(0, Number(detail.sessionId || detail.session?.id || currentSessionId()));
    if (id && id !== state.sessionId) state.sessionId = id;
    if (Array.isArray(detail.segments)) {
      state.liveWords = Math.max(state.liveWords, Number(detail.totalWordCount || detail.wordCount || detail.session?.word_count || 0), transcriptWordCount(detail.segments));
    }
    render();
    if (['update','synced','stopped','session-started'].includes(String(detail.action || ''))) scheduleLive('words');
  });

  window.addEventListener('stonefellow:artist-listening-metadata-saved', () => scheduleLive('metadata'));

  async function boot() {
    ensurePanel();
    bindButton();
    try { await loadRegistry(); } catch (error) { state.lastError = String(error?.message || error); }
    render();
    const current = currentSessionId();
    if (current) void loadStatus(current);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => void boot(), {once:true});
  else void boot();
})();