(() => {
  'use strict';
  if (window.__VP3_MEMBER_AGENT_VOICE_MENU__) return;
  window.__VP3_MEMBER_AGENT_VOICE_MENU__ = true;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
  }[char]));
  const clean = (value, limit = 190) => String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, limit);
  const menu = document.getElementById('chatProfileDropdown');
  if (!menu) return;

  const source = document.querySelector('[data-agent-voice-menu-config]') || document.getElementById('chatSidebar');
  const cfg = {
    agentEndpoint: source?.dataset.agentEndpoint || '/api/user-agent-system-v236.php',
    voiceEndpoint: source?.dataset.voiceEndpoint || '/api/studio-voice-profile.php',
    chatSettingsEndpoint: source?.dataset.chatSettingsEndpoint || '/api/chat-settings-v237.php',
    csrf: source?.dataset.csrf || '',
    voiceProfileUrl: source?.dataset.voiceProfileUrl || '/voice-profile.php',
    profileAgentUrl: source?.dataset.profileAgentUrl || '/profile-agent.php',
    accountAgentsUrl: source?.dataset.accountAgentsUrl || '/account.php#agents-data'
  };

  let agentState = null;
  let voiceState = null;
  let agentVoiceAllowed = true;
  let selectedAgentId = 0;
  let busy = false;

  function activeAgents() {
    const rows = Array.isArray(agentState?.agents) ? agentState.agents : [];
    return rows.filter(row => Number(row?.is_active) === 1);
  }

  function selectedAgent() {
    const rows = activeAgents();
    return rows.find(row => Number(row.id) === selectedAgentId)
      || rows.find(row => Number(row.is_default) === 1)
      || rows[0]
      || null;
  }

  function cloneReady() {
    const voice = voiceState?.voice || {};
    return Boolean(voice.has_clone_binding && voice.clone_enabled);
  }

  function cloneLabel() {
    const voice = voiceState?.voice || {};
    if (!cloneReady()) return 'No ElevenLabs clone';
    return voice.clone_verified ? 'ElevenLabs clone ready' : 'ElevenLabs clone pending verification';
  }

  function dashboardMarkup() {
    return `
      <section class="vp3-agent-voice-dashboard" data-vp3-agent-voice-dashboard aria-label="Agent and voice controls">
        <header class="vp3-agent-voice-head">
          <div><small>Agent + Voice</small><strong>Your personal agent</strong></div>
          <span class="vp3-agent-voice-provider" data-vp3-voice-provider>ElevenLabs</span>
        </header>

        <div class="vp3-agent-voice-status" data-vp3-agent-voice-status role="status" aria-live="polite">Loading agent and voice settings…</div>

        <div class="vp3-agent-voice-body" data-vp3-agent-voice-body hidden>
          <label class="vp3-agent-voice-field" data-vp3-agent-selector-wrap hidden>
            <span>Agent</span>
            <select data-vp3-agent-selector aria-label="Choose agent"></select>
          </label>

          <label class="vp3-agent-voice-field" data-vp3-agent-name-wrap>
            <span>Agent name</span>
            <div class="vp3-agent-name-row">
              <input type="text" maxlength="190" data-vp3-agent-name autocomplete="off" aria-label="Agent name">
              <button type="button" data-vp3-save-agent-name>Save</button>
            </div>
          </label>

          <label class="vp3-agent-voice-field">
            <span>Voice source</span>
            <select data-vp3-agent-voice-source aria-label="Agent voice source">
              <option value="default">VP3 default voice</option>
              <option value="clone">My ElevenLabs voice clone</option>
            </select>
          </label>

          <div class="vp3-agent-voice-clone-card">
            <div><small>Voice clone</small><strong data-vp3-clone-state>Checking ElevenLabs…</strong><span data-vp3-clone-detail>Your existing Voice Profile remains the source of truth.</span></div>
            <a data-vp3-manage-voice href="${esc(cfg.voiceProfileUrl)}">Manage clone</a>
          </div>

          <label class="vp3-agent-voice-toggle-row">
            <span><strong>Agent Voice</strong><small>Master switch for spoken Agent responses and notification announcements.</small></span>
            <input type="checkbox" data-vp3-global-agent-voice aria-label="Agent Voice">
            <i aria-hidden="true"></i>
          </label>

          <div class="vp3-agent-voice-actions">
            <a href="${esc(cfg.profileAgentUrl)}">Open My Agent</a>
            <a href="${esc(cfg.accountAgentsUrl)}">Agent settings</a>
          </div>
        </div>
      </section>`;
  }

  function installDashboard() {
    menu.classList.add('vp3-agent-voice-menu');
    menu.querySelector('.chat-profile-links')?.remove();
    const summary = menu.querySelector('.chat-profile-summary');
    summary?.querySelector('.member-agent-voice-toggle')?.remove();
    let dashboard = menu.querySelector('[data-vp3-agent-voice-dashboard]');
    if (!dashboard) {
      menu.insertAdjacentHTML('beforeend', dashboardMarkup());
      dashboard = menu.querySelector('[data-vp3-agent-voice-dashboard]');
    }
    return dashboard;
  }

  const dashboard = installDashboard();
  if (!dashboard) return;

  const el = {
    status: dashboard.querySelector('[data-vp3-agent-voice-status]'),
    body: dashboard.querySelector('[data-vp3-agent-voice-body]'),
    selectorWrap: dashboard.querySelector('[data-vp3-agent-selector-wrap]'),
    selector: dashboard.querySelector('[data-vp3-agent-selector]'),
    nameWrap: dashboard.querySelector('[data-vp3-agent-name-wrap]'),
    name: dashboard.querySelector('[data-vp3-agent-name]'),
    saveName: dashboard.querySelector('[data-vp3-save-agent-name]'),
    voiceSource: dashboard.querySelector('[data-vp3-agent-voice-source]'),
    cloneState: dashboard.querySelector('[data-vp3-clone-state]'),
    cloneDetail: dashboard.querySelector('[data-vp3-clone-detail]'),
    agentVoice: dashboard.querySelector('[data-vp3-global-agent-voice]')
  };

  function setStatus(message = '', kind = '') {
    if (!el.status) return;
    el.status.textContent = message;
    el.status.className = `vp3-agent-voice-status${kind ? ` ${kind}` : ''}`;
    el.status.hidden = message === '';
  }

  async function json(url, options = {}) {
    const response = await fetch(url, {credentials:'same-origin', cache:'no-store', ...options});
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.error || `Request failed (${response.status}).`);
    return data;
  }

  async function post(url, action, payload = {}) {
    if (!cfg.csrf) throw new Error('Your session needs to be refreshed.');
    return json(url, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action, csrf_token:cfg.csrf, ...payload})
    });
  }

  function agentPayload(agent, changes = {}) {
    return {
      id:Number(agent.id || 0),
      display_name:String(changes.display_name ?? agent.display_name ?? ''),
      agent_role:String(agent.agent_role || 'personal'),
      instructions:String(agent.instructions || ''),
      is_default:Number(agent.is_default) === 1,
      is_profile_agent:Number(agent.is_profile_agent) === 1,
      is_active:Number(agent.is_active) === 1,
      voice_enabled:Object.prototype.hasOwnProperty.call(changes, 'voice_enabled')
        ? Boolean(changes.voice_enabled)
        : Number(agent.voice_enabled) === 1
    };
  }

  function renderAgents() {
    const rows = activeAgents();
    if (!rows.length) {
      selectedAgentId = 0;
      el.selectorWrap.hidden = true;
      el.nameWrap.hidden = true;
      el.voiceSource.disabled = true;
      el.voiceSource.value = 'default';
      setStatus('No named personal agent yet. Use Agent settings to name one.', 'info');
      return;
    }

    if (!rows.some(row => Number(row.id) === selectedAgentId)) {
      const preferred = rows.find(row => Number(row.is_default) === 1) || rows[0];
      selectedAgentId = Number(preferred.id || 0);
    }
    const agent = selectedAgent();
    el.selector.innerHTML = rows.map(row => `<option value="${Number(row.id)}"${Number(row.id) === selectedAgentId ? ' selected' : ''}>${esc(row.display_name || 'Agent')}</option>`).join('');
    el.selectorWrap.hidden = rows.length < 2;
    el.nameWrap.hidden = false;
    el.name.value = String(agent?.display_name || '');
    el.voiceSource.disabled = false;
    el.voiceSource.value = Number(agent?.voice_enabled) === 1 ? 'clone' : 'default';
    if (!cloneReady() && el.voiceSource.value === 'clone') el.voiceSource.value = 'default';
  }

  function renderVoice() {
    const ready = cloneReady();
    const cloneOption = el.voiceSource?.querySelector('option[value="clone"]');
    if (cloneOption) cloneOption.disabled = !ready;
    if (el.cloneState) el.cloneState.textContent = cloneLabel();
    if (el.cloneDetail) {
      el.cloneDetail.textContent = ready
        ? 'Use the voice source selector above to assign your existing clone to this agent.'
        : 'Record or upload a sample in Voice Profile to create your ElevenLabs clone.';
    }
    if (!ready && el.voiceSource?.value === 'clone') el.voiceSource.value = 'default';
  }

  function renderAgentVoice(enabled) {
    if (!el.agentVoice) return;
    el.agentVoice.disabled = !agentVoiceAllowed;
    el.agentVoice.checked = agentVoiceAllowed && enabled !== false;
  }

  function render() {
    renderAgents();
    renderVoice();
    if (el.body) el.body.hidden = false;
    if (activeAgents().length) setStatus('');
  }

  async function load() {
    const tasks = [
      json(cfg.agentEndpoint).then(data => { agentState = data.state || {}; }),
      json(`${cfg.voiceEndpoint}?action=state`).then(data => { voiceState = data.state || {}; }).catch(() => { voiceState = null; }),
      json(`${cfg.chatSettingsEndpoint}?action=state`).then(data => { agentVoiceAllowed=data.agent_voice_allowed!==false; renderAgentVoice(data.chat?.agent_voice_enabled !== false); }).catch(() => { agentVoiceAllowed=false; renderAgentVoice(false); })
    ];
    try {
      await Promise.all(tasks);
      render();
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Agent settings could not be loaded.', 'error');
      if (el.body) el.body.hidden = false;
    }
  }

  async function saveAgent(changes, successMessage) {
    const agent = selectedAgent();
    if (!agent || busy) return;
    busy = true;
    dashboard.dataset.saving = 'true';
    try {
      const data = await post(cfg.agentEndpoint, 'update_agent', agentPayload(agent, changes));
      agentState = data.state || agentState;
      render();
      setStatus(successMessage, 'success');
    } catch (error) {
      render();
      setStatus(error instanceof Error ? error.message : 'Agent settings could not be saved.', 'error');
    } finally {
      busy = false;
      delete dashboard.dataset.saving;
    }
  }

  el.selector?.addEventListener('change', () => {
    selectedAgentId = Number(el.selector.value || 0);
    render();
  });

  el.saveName?.addEventListener('click', () => {
    const name = clean(el.name?.value || '');
    if (!name) {
      setStatus('Enter an agent name.', 'error');
      el.name?.focus();
      return;
    }
    void saveAgent({display_name:name}, 'Agent name saved.');
  });

  el.name?.addEventListener('keydown', event => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    el.saveName?.click();
  });

  el.voiceSource?.addEventListener('change', () => {
    const useClone = el.voiceSource.value === 'clone';
    if (useClone && !cloneReady()) {
      el.voiceSource.value = 'default';
      setStatus('Create your ElevenLabs clone in Voice Profile first.', 'error');
      return;
    }
    void saveAgent({voice_enabled:useClone}, useClone ? 'ElevenLabs clone assigned to this agent.' : 'VP3 default voice selected.');
  });

  el.agentVoice?.addEventListener('change', event => {
    event.stopPropagation();
    if(!agentVoiceAllowed){renderAgentVoice(false);return;}
    const requested = Boolean(el.agentVoice.checked);
    void (async () => {
      try {
        const data = await post(cfg.chatSettingsEndpoint, 'save_agent_voice', {agent_voice_enabled:requested});
        renderAgentVoice(data.chat?.agent_voice_enabled !== false);
        window.dispatchEvent(new CustomEvent('stonefellow:agent-voice', {detail:{enabled:data.chat?.agent_voice_enabled !== false}}));
        setStatus(requested ? 'Agent Voice is on.' : 'Agent Voice is off.', 'success');
      } catch (error) {
        renderAgentVoice(!requested);
        setStatus(error instanceof Error ? error.message : 'Agent Voice could not be saved.', 'error');
      }
    })();
  });

  window.addEventListener('stonefellow:agent-voice', event => renderAgentVoice(event.detail?.enabled !== false));
  void load();
})();
