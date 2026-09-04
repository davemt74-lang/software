(() => {
  'use strict';

  const cfg = window.STONEFELLOW_ARTIST_LISTENING_V172 || {};
  const BUILD = 'artist-listening-ai-settings-toggle-20260903';
  const userId = Math.max(0, Number(cfg.userId || 0));
  const reportEndpoint = String(cfg.endpoint || '').replace(/artist-listening-v172\.php(?:\?.*)?$/i, 'artist-listening-intelligence-v254.php');
  const researchKey = `stonefellow:artist-listening:ai-summary:${userId}`;
  const appsKey = `stonefellow:artist-listening:ai-apps:${userId}`;

  const APP_DEFS = [
    {id:'basic', label:'Analysis', title:'Basic Analysis'},
    {id:'stats', label:'Stats', title:'Stats Report'},
    {id:'actions', label:'Actions', title:'Suggested Actions'},
    {id:'responses', label:'Responses', title:'Suggested Responses'},
    {id:'decisions', label:'Decisions', title:'Decisions & Commitments'},
    {id:'moments', label:'Moments', title:'Key Moments'},
    {id:'studio', label:'Studio', title:'Studio Notes'},
    {id:'knowledge', label:'Knowledge', title:'Knowledge Extractor'},
  ];
  const APP_IDS = APP_DEFS.map(app => app.id);

  function readResearchEnabled() {
    try { return localStorage.getItem(researchKey) === '1'; } catch (error) { return false; }
  }

  function readSelectedApps() {
    try {
      const parsed = JSON.parse(localStorage.getItem(appsKey) || 'null');
      const clean = Array.isArray(parsed) ? parsed.filter(id => APP_IDS.includes(id)) : [];
      return clean.length ? clean : ['basic'];
    } catch (error) {
      return ['basic'];
    }
  }

  const state = {
    open: false,
    settingsOpen: false,
    clicks: 0,
    bound: false,
    sessionId: 0,
    researchEnabled: readResearchEnabled(),
    selectedApps: readSelectedApps(),
    activeApp: 'basic',
    report: null,
    permissions: {},
    busy: false,
    liveWords: 0,
    lastReportedWords: 0,
    liveTimer: 0,
    lastError: '',
    actionMessage: '',
  };
  if (!state.selectedApps.includes(state.activeApp)) state.activeApp = state.selectedApps[0];

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
    isOpen: () => state.open,
    open: () => setOpen(true),
    close: () => setOpen(false),
    toggle: () => setOpen(!state.open),
    setResearchEnabled: enabled => setResearchEnabled(enabled),
    isResearchEnabled: () => state.researchEnabled,
    setSettingsOpen: open => setSettingsOpen(open),
    isSettingsOpen: () => state.settingsOpen,
    selectedApps: () => [...state.selectedApps],
    activeApp: () => state.activeApp,
  };

  const clean = value => String(value || '').replace(/\s+/g, ' ').trim();
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const wordCount = value => {
    const text = clean(value);
    return text ? text.split(' ').length : 0;
  };
  const appById = id => APP_DEFS.find(app => app.id === id) || APP_DEFS[0];

  function getButton() {
    return document.querySelector('[data-listening-ai-toggle]');
  }

  function currentSessionId() {
    return Math.max(0, Number(
      window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.currentSessionId ||
      state.sessionId ||
      cfg.initialSessionId ||
      0
    ));
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
    if (!reportEndpoint) throw new Error('Artist Listening intelligence endpoint is unavailable.');
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
    const data = await response.json().catch(() => ({ok:false,error:'Artist Listening AI returned an invalid response.'}));
    if (!response.ok || !data.ok) throw new Error(String(data.error || `Artist Listening AI failed (${response.status}).`));
    return data;
  }

  function formatSaved(value) {
    const raw = String(value || '').trim();
    if (!raw) return 'Not analyzed yet';
    const date = new Date(raw.replace(' ', 'T'));
    if (!Number.isFinite(date.getTime())) return 'Saved';
    return `Saved ${date.toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'})}`;
  }

  function listHtml(title, items = []) {
    const rows = (Array.isArray(items) ? items : []).filter(Boolean);
    return rows.length ? `<section><h4>${esc(title)}</h4><ul>${rows.map(item => `<li>${esc(item)}</li>`).join('')}</ul></section>` : '';
  }

  function emptyAppHtml(appId) {
    const app = appById(appId);
    return `<h3>${esc(app.title)}</h3><p class="sf-listening-ai-empty">Run Analyze to generate the ${esc(app.title.toLowerCase())} for this transcript.</p>`;
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
    ];
    const speakerBars = speakers.length
      ? `<section><h4>Speaker share</h4><div class="sf-listening-ai-chart">${speakers.map(row => {
          const share = Math.max(0, Math.min(100, Number(row.word_share || 0)));
          return `<div class="sf-listening-ai-chart-row"><div class="sf-listening-ai-chart-label"><span>${esc(row.label || 'Speaker')}</span><b>${Number(row.words || 0).toLocaleString()} words · ${share.toFixed(1)}%</b></div><div class="sf-listening-ai-chart-track"><i style="width:${share}%"></i></div></div>`;
        }).join('')}</div></section>`
      : '';
    const eventBits = [
      Number(stats.note_count || 0) ? `${Number(stats.note_count).toLocaleString()} notes` : '',
      Number(stats.marker_count || 0) ? `${Number(stats.marker_count).toLocaleString()} markers` : '',
      Number(stats.other_segment_count || 0) ? `${Number(stats.other_segment_count).toLocaleString()} other events` : '',
    ].filter(Boolean);
    return `<h3>Stats Report</h3><div class="sf-listening-ai-stat-grid">${cards.map(([label,value]) => `<div><small>${esc(label)}</small><strong>${esc(value)}</strong></div>`).join('')}</div>${speakerBars}${eventBits.length ? `<section><h4>Transcript events</h4><p>${esc(eventBits.join(' · '))}</p></section>` : ''}`;
  }

  function basicHtml(analysis, research) {
    const sources = Array.isArray(research.sources) ? research.sources : [];
    return `<h3>Basic Analysis</h3>${analysis.logical_report ? `<p class="sf-listening-ai-report-copy">${esc(analysis.logical_report)}</p>` : (analysis.summary ? `<p class="sf-listening-ai-report-copy">${esc(analysis.summary)}</p>` : '')}${listHtml('Agreements', analysis.agreements)}${listHtml('Conflicts', analysis.conflicts)}${listHtml('Changes from prior context', analysis.changes_from_prior)}${listHtml('Key points', analysis.key_points)}${listHtml('Open questions', analysis.open_questions)}${listHtml('Context gaps', analysis.context_gaps)}${research.text ? `<section><h4>External Research</h4><p>${esc(research.text).replace(/\n/g, '<br>')}</p>${sources.length ? `<div class="sf-listening-ai-sources">${sources.map(source => `<a href="${esc(source.url)}" target="_blank" rel="noopener noreferrer">${esc(source.title || source.url)} ↗</a>`).join('')}</div>` : ''}</section>` : ''}`;
  }

  function appResultHtml(appId, analysis, research) {
    const generatedApps = Array.isArray(analysis.apps) ? analysis.apps : ['basic'];
    const generated = generatedApps.includes(appId) || (appId === 'basic' && (analysis.summary || analysis.logical_report));
    if (!generated) return emptyAppHtml(appId);
    switch (appId) {
      case 'stats': return statsHtml(analysis.stats || {});
      case 'actions': return `<h3>Suggested Actions</h3>${listHtml('Recommended next actions', analysis.action_items) || '<p class="sf-listening-ai-empty">No actions were identified.</p>'}`;
      case 'responses': return `<h3>Suggested Responses</h3>${listHtml('Responses to consider', analysis.suggested_responses) || '<p class="sf-listening-ai-empty">No suggested responses were identified.</p>'}`;
      case 'decisions': return `<h3>Decisions & Commitments</h3>${listHtml('Decisions', analysis.decisions)}${listHtml('Commitments', analysis.commitments)}${(!analysis.decisions?.length && !analysis.commitments?.length) ? '<p class="sf-listening-ai-empty">No decisions or commitments were identified.</p>' : ''}`;
      case 'moments': return `<h3>Key Moments</h3>${listHtml('Important moments', analysis.key_moments) || '<p class="sf-listening-ai-empty">No key moments were identified.</p>'}`;
      case 'studio': return `<h3>Studio Notes</h3>${listHtml('Production / song notes', analysis.studio_notes) || '<p class="sf-listening-ai-empty">No studio-specific notes were identified.</p>'}`;
      case 'knowledge': return `<h3>Knowledge Extractor</h3>${listHtml('Knowledge worth saving', analysis.knowledge_candidates)}${listHtml('Changes from prior context', analysis.changes_from_prior)}${listHtml('Conflicts to review', analysis.conflicts)}${(!analysis.knowledge_candidates?.length && !analysis.changes_from_prior?.length && !analysis.conflicts?.length) ? '<p class="sf-listening-ai-empty">No knowledge updates were identified.</p>' : ''}`;
      default: return basicHtml(analysis, research);
    }
  }

  function persistApps() {
    try { localStorage.setItem(appsKey, JSON.stringify(state.selectedApps)); } catch (error) {}
  }

  function setAppSelected(appId, selected) {
    if (!APP_IDS.includes(appId)) return;
    const next = new Set(state.selectedApps);
    if (selected) next.add(appId);
    else next.delete(appId);
    if (!next.size) next.add('basic');
    state.selectedApps = APP_IDS.filter(id => next.has(id));
    if (!state.selectedApps.includes(state.activeApp)) state.activeApp = state.selectedApps[0];
    state.actionMessage = '';
    persistApps();
    render();
  }

  function setActiveApp(appId) {
    if (!state.selectedApps.includes(appId)) return;
    state.activeApp = appId;
    renderReport();
    renderTabs();
  }

  function setSettingsOpen(open) {
    state.settingsOpen = Boolean(open);
    render();
    return state.settingsOpen;
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
          <button type="button" class="sf-listening-ai-power" data-listening-ai-research aria-pressed="false">Research OFF</button>
          <button type="button" data-listening-ai-close aria-label="Close AI Summary">×</button>
        </div>
      </header>
      <div class="sf-listening-ai-status sf-listening-ai-status-row">
        <span data-listening-ai-status>AI Summary ready.</span>
        <button type="button" class="sf-listening-ai-settings" data-listening-ai-settings aria-expanded="false" aria-controls="sfListeningAiApps" aria-label="Toggle transcription app settings" title="Transcription app settings"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.8 1.8 0 0 0 .36 1.98l.06.06-2.78 2.78-.06-.06A1.8 1.8 0 0 0 15 19.4a1.8 1.8 0 0 0-1.08 1.65V21h-3.84v-.09A1.8 1.8 0 0 0 9 19.4a1.8 1.8 0 0 0-1.98.36l-.06.06-2.78-2.78.06-.06A1.8 1.8 0 0 0 4.6 15a1.8 1.8 0 0 0-1.65-1.08H3v-3.84h.09A1.8 1.8 0 0 0 4.6 9a1.8 1.8 0 0 0-.36-1.98l-.06-.06 2.78-2.78.06.06A1.8 1.8 0 0 0 9 4.6a1.8 1.8 0 0 0 1.08-1.65V3h3.84v.09A1.8 1.8 0 0 0 15 4.6a1.8 1.8 0 0 0 1.98-.36l.06-.06 2.78 2.78-.06.06A1.8 1.8 0 0 0 19.4 9a1.8 1.8 0 0 0 1.65 1.08H21v3.84h-.09A1.8 1.8 0 0 0 19.4 15Z"></path></svg></button>
      </div>
      <section class="sf-listening-ai-apps" id="sfListeningAiApps" hidden>
        <div class="sf-listening-ai-apps-head"><strong>Transcription apps</strong><span>Select what Analyze should run</span></div>
        <div class="sf-listening-ai-app-options" data-listening-ai-app-options></div>
      </section>
      <nav class="sf-listening-ai-tabs" data-listening-ai-tabs aria-label="AI result tabs"></nav>
      <div class="sf-listening-ai-scroll">
        <section class="sf-listening-ai-report" data-listening-ai-report></section>
      </div>
      <footer class="sf-listening-ai-footer">
        <div class="sf-listening-ai-footer-actions">
          <button type="button" data-listening-ai-analyze>Analyze</button>
          <button type="button" data-listening-ai-brain>Add to Agent Brain</button>
          <button type="button" data-listening-ai-knowledge>Add to Knowledge Base</button>
        </div>
      </footer>`;
    document.body.appendChild(panel);

    panel.querySelector('[data-listening-ai-close]')?.addEventListener('click', () => setOpen(false));
    panel.querySelector('[data-listening-ai-research]')?.addEventListener('click', () => setResearchEnabled(!state.researchEnabled));
    panel.querySelector('[data-listening-ai-settings]')?.addEventListener('click', () => setSettingsOpen(!state.settingsOpen));
    panel.querySelector('[data-listening-ai-analyze]')?.addEventListener('click', () => void analyze('manual'));
    panel.querySelector('[data-listening-ai-brain]')?.addEventListener('click', () => void saveResult('save_brain'));
    panel.querySelector('[data-listening-ai-knowledge]')?.addEventListener('click', () => void saveResult('save_knowledge'));

    const options = panel.querySelector('[data-listening-ai-app-options]');
    if (options) {
      options.innerHTML = APP_DEFS.map(app => `<label><input type="checkbox" data-listening-ai-app="${esc(app.id)}"><span>${esc(app.title)}</span></label>`).join('');
      options.querySelectorAll('[data-listening-ai-app]').forEach(input => {
        input.addEventListener('change', () => setAppSelected(String(input.dataset.listeningAiApp || ''), input.checked));
      });
    }

    const tabs = panel.querySelector('[data-listening-ai-tabs]');
    tabs?.addEventListener('click', event => {
      const button = event.target.closest('[data-listening-ai-tab]');
      if (!button) return;
      setActiveApp(String(button.dataset.listeningAiTab || ''));
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

  function renderApps() {
    const panel = ensurePanel();
    panel.querySelectorAll('[data-listening-ai-app]').forEach(input => {
      input.checked = state.selectedApps.includes(String(input.dataset.listeningAiApp || ''));
    });
  }

  function renderTabs() {
    const tabs = document.querySelector('[data-listening-ai-tabs]');
    if (!tabs) return;
    tabs.innerHTML = state.selectedApps.map(id => {
      const app = appById(id);
      const active = id === state.activeApp;
      return `<button type="button" data-listening-ai-tab="${esc(id)}" class="${active ? 'active' : ''}" aria-selected="${active ? 'true' : 'false'}">${esc(app.label)}</button>`;
    }).join('');
  }

  function renderReport() {
    const node = document.querySelector('[data-listening-ai-report]');
    if (!node) return;
    const analysis = state.report?.analysis || {};
    const research = state.report?.research || {};
    const saved = formatSaved(state.report?.generated_at);
    const action = clean(state.actionMessage);
    const meta = `<div class="sf-listening-ai-report-state"><strong>${esc(saved)}</strong>${action ? `<span>${esc(action)}</span>` : ''}</div>`;
    if (!state.report) {
      node.innerHTML = `${meta}${emptyAppHtml(state.activeApp)}`;
      return;
    }
    node.innerHTML = `${meta}${appResultHtml(state.activeApp, analysis, research)}`;
  }

  function render() {
    const panel = ensurePanel();
    const button = getButton();
    const status = panel.querySelector('[data-listening-ai-status]');
    const power = panel.querySelector('[data-listening-ai-research]');
    const settings = panel.querySelector('[data-listening-ai-settings]');
    const apps = panel.querySelector('#sfListeningAiApps');
    const analyzeButton = panel.querySelector('[data-listening-ai-analyze]');
    const brain = panel.querySelector('[data-listening-ai-brain]');
    const knowledge = panel.querySelector('[data-listening-ai-knowledge]');

    panel.classList.toggle('open', state.open);
    document.body.classList.toggle('sf-listening-ai-open', state.open);
    if (button) {
      button.setAttribute('aria-expanded', state.open ? 'true' : 'false');
      button.classList.toggle('on', state.researchEnabled);
      const badge = button.querySelector('[data-listening-ai-badge]');
      if (badge) badge.textContent = state.researchEnabled ? 'ON' : 'OFF';
    }
    if (power) {
      power.textContent = state.researchEnabled ? 'Research ON' : 'Research OFF';
      power.setAttribute('aria-pressed', state.researchEnabled ? 'true' : 'false');
      power.classList.toggle('on', state.researchEnabled);
    }
    if (settings) {
      settings.setAttribute('aria-expanded', state.settingsOpen ? 'true' : 'false');
      settings.classList.toggle('open', state.settingsOpen);
    }
    if (apps) apps.hidden = !state.settingsOpen;
    if (analyzeButton) {
      analyzeButton.disabled = state.busy || !currentSessionId() || !state.selectedApps.length;
      analyzeButton.textContent = state.busy ? 'Analyzing…' : `Analyze ${state.selectedApps.length}`;
    }
    if (brain) brain.disabled = state.busy || !state.report || !state.permissions?.agent_brain_write;
    if (knowledge) knowledge.disabled = state.busy || !state.report || !state.permissions?.personal_knowledge_write;
    if (status) {
      if (state.lastError) status.textContent = state.lastError;
      else if (state.busy) status.textContent = `Running ${state.selectedApps.length} transcription app${state.selectedApps.length === 1 ? '' : 's'}…`;
      else if (!currentSessionId()) status.textContent = 'Open a transcription to use AI Summary.';
      else status.textContent = `${state.researchEnabled ? 'Research ON' : 'Research OFF'} · ${state.liveWords.toLocaleString()} transcript words · ${state.selectedApps.length} app${state.selectedApps.length === 1 ? '' : 's'} selected.`;
    }
    renderApps();
    renderTabs();
    renderReport();
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

  function setResearchEnabled(enabled) {
    state.researchEnabled = Boolean(enabled);
    try { localStorage.setItem(researchKey, state.researchEnabled ? '1' : '0'); } catch (error) {}
    state.actionMessage = '';
    if (!state.researchEnabled && state.liveTimer) {
      clearTimeout(state.liveTimer);
      state.liveTimer = 0;
    }
    render();
    if (state.researchEnabled) scheduleLive('toggle');
    return state.researchEnabled;
  }

  async function loadStatus(sessionId = currentSessionId()) {
    sessionId = Math.max(0, Number(sessionId || 0));
    state.sessionId = sessionId;
    state.lastError = '';
    state.actionMessage = '';
    if (!sessionId) {
      state.report = null;
      state.permissions = {};
      state.liveWords = 0;
      state.lastReportedWords = 0;
      render();
      return;
    }
    try {
      const data = await request('status', {session_id:sessionId}, 'GET');
      if (currentSessionId() !== sessionId) return;
      state.report = data.master || null;
      state.permissions = data.permissions || {};
      state.liveWords = Math.max(state.liveWords, Number(state.report?.word_count || 0));
      state.lastReportedWords = Number(state.report?.word_count || 0);
      proof.lastError = '';
    } catch (error) {
      state.lastError = String(error?.message || error);
      proof.lastError = state.lastError;
    }
    render();
  }

  async function analyze(mode = 'manual') {
    const sessionId = currentSessionId();
    if (!sessionId || state.busy || !state.selectedApps.length) return;
    state.busy = true;
    state.lastError = '';
    state.actionMessage = '';
    render();
    try {
      const data = await request('analyze', {
        session_id:sessionId,
        mode,
        research:state.researchEnabled,
        apps:state.selectedApps,
      });
      if (currentSessionId() !== sessionId) return;
      state.report = data.master || state.report;
      state.permissions = data.permissions || state.permissions;
      state.lastReportedWords = Number(state.report?.word_count || state.liveWords || state.lastReportedWords);
      if (!data.skipped) {
        proof.reports += 1;
        if (mode === 'live') proof.liveReports += 1;
      }
      state.actionMessage = data.skipped ? 'Selected analysis is current.' : 'Selected apps analyzed.';
      proof.lastError = '';
    } catch (error) {
      state.lastError = String(error?.message || error);
      proof.lastError = state.lastError;
    } finally {
      state.busy = false;
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
        state.actionMessage = `Added to Agent Brain${data.saved_at ? ` · ${formatSaved(data.saved_at)}` : ''}.`;
      } else {
        proof.knowledgeSaves += 1;
        state.actionMessage = `Added to Personal Knowledge Base${data.saved_at ? ` · ${formatSaved(data.saved_at)}` : ''}.`;
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


  function transcriptionAiState(){
    return {
      sessionId:currentSessionId(),
      open:!!state.open,
      settingsOpen:!!state.settingsOpen,
      researchEnabled:!!state.researchEnabled,
      selectedApps:[...state.selectedApps],
      activeApp:String(state.activeApp||''),
      busy:!!state.busy,
      report:state.report?JSON.parse(JSON.stringify(state.report)):null,
      permissions:{...state.permissions},
      liveWords:Math.max(0,Number(state.liveWords||0)),
      lastError:String(state.lastError||''),
    };
  }
  function transcriptionSetApps(ids=[]){
    const requested=new Set((Array.isArray(ids)?ids:[]).map(String).filter(id=>APP_IDS.includes(id)));
    if(!requested.size)throw new Error('Select at least one transcription analysis app.');
    state.selectedApps=APP_IDS.filter(id=>requested.has(id));
    if(!state.selectedApps.includes(state.activeApp))state.activeApp=state.selectedApps[0];
    state.actionMessage='';
    persistApps();
    render();
    return [...state.selectedApps];
  }
  proof.api={
    getState:transcriptionAiState,
    open:()=>setOpen(true),
    close:()=>setOpen(false),
    setResearchEnabled,
    setApps:transcriptionSetApps,
    setActiveApp:appId=>{setActiveApp(String(appId||''));return state.activeApp;},
    analyze:async(mode='manual')=>{await analyze(mode);if(state.lastError)throw new Error(state.lastError);return state.report;},
    saveBrain:async()=>{await saveResult('save_brain');if(state.lastError)throw new Error(state.lastError);return true;},
    saveKnowledge:async()=>{await saveResult('save_knowledge');if(state.lastError)throw new Error(state.lastError);return true;},
    loadStatus:async sessionId=>{await loadStatus(sessionId);if(state.lastError)throw new Error(state.lastError);return transcriptionAiState();},
  };

  function scheduleLive(reason = 'words') {
    if (!state.researchEnabled || !currentSessionId() || state.busy) return;
    if (!state.report && state.liveWords < 120) return;
    const delta = Math.max(0, state.liveWords - state.lastReportedWords);
    if (reason === 'words' && state.report && delta < 250) return;
    if (state.liveTimer) clearTimeout(state.liveTimer);
    state.liveTimer = setTimeout(() => {
      state.liveTimer = 0;
      if (state.researchEnabled && currentSessionId()) void analyze('live');
    }, reason === 'metadata' ? 450 : 1200);
  }

  function bindButton() {
    const button = getButton();
    if (!button) {
      state.bound = false;
      return false;
    }
    button.addEventListener('click', () => {
      state.clicks += 1;
      proof.buttonClicks = state.clicks;
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
      state.liveWords = Math.max(
        state.liveWords,
        Number(detail.totalWordCount || detail.wordCount || detail.session?.word_count || 0),
        transcriptWordCount(detail.segments)
      );
    }
    render();
    if (['update','synced','stopped','session-started'].includes(String(detail.action || ''))) scheduleLive('words');
  });

  window.addEventListener('stonefellow:artist-listening-metadata-saved', () => scheduleLive('metadata'));

  function boot() {
    ensurePanel();
    bindButton();
    render();
    const current = currentSessionId();
    if (current) void loadStatus(current);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true});
  else boot();
})();
