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
    userId:Number(source?.dataset.userId || 0),
    agentEndpoint: source?.dataset.agentEndpoint || '/api/user-agent-system-v236.php',
    voiceEndpoint: source?.dataset.voiceEndpoint || '/api/studio-voice-profile.php',
    ttsEndpoint: source?.dataset.ttsEndpoint || '/api/agent-voice-v117.php',
    systemVoiceEndpoint: new URL('agent-voice-settings.php', new URL(source?.dataset.ttsEndpoint || '/api/agent-voice-v117.php',location.href)).href,
    chatSettingsEndpoint: source?.dataset.chatSettingsEndpoint || '/api/chat-settings-v237.php',
    csrf: source?.dataset.csrf || '',
    voiceProfileUrl: source?.dataset.voiceProfileUrl || '/voice-profile.php',
    profileAgentUrl: source?.dataset.profileAgentUrl || '/profile-agent.php',
    accountAgentsUrl: source?.dataset.accountAgentsUrl || '/account.php#agents-data',
    phoneEndpoint: source?.dataset.phoneEndpoint || '/api/phone-cloud-v100.php',
    phoneRegisterUrl: source?.dataset.phoneRegisterUrl || '/phone.php',
    pricingUrl: source?.dataset.pricingUrl || '/pricing.php'
  };

  const agentVoiceSyncKey = `vp3:agent-voice-sync:${cfg.userId || 0}`;

  let agentState = null;
  let voiceState = null;
  let ttsState = null;
  let agentVoiceAllowed = true;
  let agentVoiceEnabled = false;
  let selectedAgentId = 0;
  let busy = false;
  let catalogLoaded = false;
  let catalogBusy = false;

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
            <span>Default agent</span>
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

          <label class="vp3-agent-voice-field"><span>ElevenLabs system voice</span><select data-vp3-system-voice aria-label="ElevenLabs system voice"><option value="">VP3 configured default</option></select><small data-vp3-system-voice-status role="status"></small></label>
          <div class="vp3-agent-voice-clone-card">
            <div><small>Voice clone</small><strong data-vp3-clone-state>Checking ElevenLabs…</strong><span data-vp3-clone-detail>Your existing Voice Profile remains the source of truth.</span></div>
            <a data-vp3-manage-voice href="${esc(cfg.voiceProfileUrl)}">Manage clone</a>
          </div>
          <div class="vp3-agent-phone-card" data-vp3-agent-phone-card>
            <div><small>Agent phone number</small><strong data-vp3-agent-phone-label>Checking phone package…</strong><span data-vp3-agent-phone-detail>Calls and texts are managed with your agent.</span></div>
            <a data-vp3-agent-phone-link href="${esc(cfg.phoneRegisterUrl)}">Register number</a>
          </div>

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
    // Adopt legacy server markup or an early Chat control instead of adding a second owner.
    let toggle=summary?.querySelector('[data-agent-voice-toggle]')||menu.querySelector('[data-agent-voice-toggle]');
    if(summary&&!toggle){
      summary.insertAdjacentHTML('beforeend','<label class="member-agent-voice-toggle"><span class="member-agent-voice-label">Agent Voice</span><input type="checkbox" data-agent-voice-toggle data-vp3-global-agent-voice aria-label="Agent Voice"><span class="member-agent-voice-switch" aria-hidden="true"><span></span></span></label>');
      toggle=summary.querySelector('[data-agent-voice-toggle]');
    }
    if(toggle){
      toggle.setAttribute('data-vp3-global-agent-voice','');
      const row=toggle.closest('.member-agent-voice-toggle');if(summary&&row&&row.parentNode!==summary)summary.append(row);
    }
    const removeExtras=()=>{
      menu.querySelectorAll('[data-agent-voice-toggle]').forEach(input=>{
        if(input===toggle)return;
        const row=input.closest('.member-agent-voice-toggle,.vp3-agent-voice-toggle-row,label');
        if(row&&row!==menu&&!row.contains(toggle))row.remove();else input.remove();
      });
      menu.querySelectorAll('.vp3-agent-voice-toggle-row').forEach(row=>{if(!row.contains(toggle))row.remove();});
    };
    removeExtras();
    const observer=new MutationObserver(removeExtras);observer.observe(menu,{childList:true,subtree:true});
    window.addEventListener('pagehide',()=>observer.disconnect(),{once:true});
    let dashboard = menu.querySelector('[data-vp3-agent-voice-dashboard]');
    if (!dashboard) {
      menu.insertAdjacentHTML('beforeend', dashboardMarkup());
      dashboard = menu.querySelector('[data-vp3-agent-voice-dashboard]');
    }
    if (!dashboard.querySelector('[data-vp3-agent-phone-card]')) {
      const clone=dashboard.querySelector('.vp3-agent-voice-clone-card');
      clone?.insertAdjacentHTML('afterend',`<div class="vp3-agent-phone-card" data-vp3-agent-phone-card><div><small>Agent phone number</small><strong data-vp3-agent-phone-label>Checking phone package…</strong><span data-vp3-agent-phone-detail>Calls and texts are managed with your agent.</span></div><a data-vp3-agent-phone-link href="${esc(cfg.phoneRegisterUrl)}">Register number</a></div>`);
    }
    return dashboard;
  }

  const dashboard = installDashboard();
  if (!dashboard) return;

  const el = {
    status: dashboard.querySelector('[data-vp3-agent-voice-status]'),
    provider: dashboard.querySelector('[data-vp3-voice-provider]'),
    body: dashboard.querySelector('[data-vp3-agent-voice-body]'),
    selectorWrap: dashboard.querySelector('[data-vp3-agent-selector-wrap]'),
    selector: dashboard.querySelector('[data-vp3-agent-selector]'),
    nameWrap: dashboard.querySelector('[data-vp3-agent-name-wrap]'),
    name: dashboard.querySelector('[data-vp3-agent-name]'),
    saveName: dashboard.querySelector('[data-vp3-save-agent-name]'),
    voiceSource: dashboard.querySelector('[data-vp3-agent-voice-source]'),
    cloneState: dashboard.querySelector('[data-vp3-clone-state]'),
    cloneDetail: dashboard.querySelector('[data-vp3-clone-detail]'),
    systemVoice: dashboard.querySelector('[data-vp3-system-voice]'),
    systemVoiceStatus: dashboard.querySelector('[data-vp3-system-voice-status]'),
    agentVoice: menu.querySelector('[data-vp3-global-agent-voice]'),
    phoneLabel: dashboard.querySelector('[data-vp3-agent-phone-label]'),
    phoneDetail: dashboard.querySelector('[data-vp3-agent-phone-detail]'),
    phoneLink: dashboard.querySelector('[data-vp3-agent-phone-link]')
  };

  function setStatus(message = '', kind = '') {
    if (!el.status) return;
    el.status.textContent = message;
    el.status.className = `vp3-agent-voice-status${kind ? ` ${kind}` : ''}`;
    el.status.hidden = message === '';
  }

  async function loadPhone() {
    if (!el.phoneLabel || !el.phoneLink) return;
    try {
      const data=await json(cfg.phoneEndpoint);
      const state=data.state||{};
      const number=state.number?.phone_e164;
      const pending=state.request?.phone_e164;
      const allowed=state.package?.included===true;
      el.phoneLink.href=cfg.phoneRegisterUrl;
      if(number){
        el.phoneLabel.textContent=number;
        el.phoneDetail.textContent=`${state.number.agent_name||'Your agent'} · ${state.number.status||'Pending setup'}`;
        el.phoneLink.textContent='Manage number';
      }else if(pending){
        el.phoneLabel.textContent=`Request pending · ${pending}`;
        el.phoneDetail.textContent='Waiting for administrator review and carrier purchase.';
        el.phoneLink.textContent='View request';
      }else if(allowed){
        el.phoneLabel.textContent='No number registered';
        el.phoneDetail.textContent=data.provider_connected?'Your package includes an agent phone number.':'Your package includes a number; Twilio setup is pending.';
        el.phoneLink.textContent='Register number';
      }else{
        el.phoneLabel.textContent='Not included in your package';
        el.phoneDetail.textContent='Choose a plan with an agent phone number.';
        el.phoneLink.href=cfg.pricingUrl;
        el.phoneLink.textContent='View plans';
      }
    }catch(_error){
      el.phoneLabel.textContent='Phone setup unavailable';
      el.phoneDetail.textContent='Your administrator may need to run a Cloud upgrade.';
      el.phoneLink.textContent='Phone settings';
    }
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
      is_default:Object.prototype.hasOwnProperty.call(changes,'is_default') ? Boolean(changes.is_default) : Number(agent.is_default) === 1,
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
    const hasDefault = rows.some(row=>Number(row.is_default)===1);
    el.selector.innerHTML = (hasDefault?'':'<option value="">Choose your default agent</option>') + rows.map(row => `<option value="${Number(row.id)}"${hasDefault && Number(row.id) === selectedAgentId ? ' selected' : ''}>${esc(row.display_name || 'Agent')}</option>`).join('');
    el.selectorWrap.hidden = false;
    if(!hasDefault)el.selector.value='';
    el.nameWrap.hidden = false;
    el.name.value = String(agent?.display_name || '');
    el.voiceSource.disabled = false;
    el.voiceSource.value = Number(agent?.voice_enabled) === 1 ? 'clone' : 'default';
    if (!cloneReady() && el.voiceSource.value === 'clone') el.voiceSource.value = 'default';
  }

  function renderVoice() {
    const ready = cloneReady();
    const runtimeReady = ttsState?.ready === true;
    const runtimeVerified = ttsState?.verified === true;
    const cloneOption = el.voiceSource?.querySelector('option[value="clone"]');
    if (cloneOption) cloneOption.disabled = !ready;
    if (el.provider) {
      el.provider.textContent = runtimeVerified ? 'ElevenLabs Ready' : (runtimeReady ? 'ElevenLabs Configured' : 'ElevenLabs Offline');
      el.provider.dataset.ready = runtimeReady ? '1' : '0';
    }
    if (el.cloneState) el.cloneState.textContent = cloneLabel();
    if (el.cloneDetail) {
      const source = String(ttsState?.voice_source || '');
      const voiceName = clean(ttsState?.voice_name || '', 90);
      if (runtimeReady) {
        el.cloneDetail.textContent = source === 'agent_clone'
          ? `Agent Chat is using your ElevenLabs clone${voiceName ? ` · ${voiceName}` : ''}.`
          : `Agent Chat is using the configured ElevenLabs default voice${voiceName ? ` · ${voiceName}` : ''}.`;
      } else {
        el.cloneDetail.textContent = ready
          ? 'Your clone exists, but Agent Chat cannot currently verify ElevenLabs playback.'
          : 'Record or upload a sample in Voice Profile to create your ElevenLabs clone.';
      }
    }
    if (!ready && el.voiceSource?.value === 'clone') el.voiceSource.value = 'default';
  }

  function renderAgentVoice(enabled) {
    agentVoiceEnabled = agentVoiceAllowed && enabled !== false;
    if (!el.agentVoice) return;
    el.agentVoice.disabled = !agentVoiceAllowed;
    el.agentVoice.checked = agentVoiceEnabled;
  }

  function publishAgentVoice(enabled) {
    if (cfg.userId < 1) return;
    try {
      localStorage.setItem(agentVoiceSyncKey, JSON.stringify({enabled:enabled !== false, at:Date.now()}));
    } catch (_error) {}
  }

  function render() {
    renderAgents();
    renderVoice();
    if (el.body) el.body.hidden = false;
    publishState();
    if (!activeAgents().length) return;
    if (!agentVoiceAllowed) {
      setStatus('Agent Voice is not available for this account.', 'info');
      return;
    }
    if (!agentVoiceEnabled) {
      setStatus('Agent Voice is off.', 'info');
      return;
    }
    if (ttsState && ttsState.ready === false) {
      setStatus(clean(ttsState.error || 'ElevenLabs is not ready for Agent Chat.', 240), 'error');
      return;
    }
    setStatus('');
  }

  async function refreshTtsState(showStatus = false) {
    if (!agentVoiceAllowed) {
      ttsState = {ready:false,verified:false,error:'Agent Voice is not available for this account.'};
      renderVoice();
      return false;
    }
    if (!agentVoiceEnabled) {
      ttsState = null;
      renderVoice();
      if (showStatus) setStatus('Agent Voice is off.', 'info');
      return false;
    }
    try {
      const data = await post(cfg.ttsEndpoint, 'warm', {agent:selectedAgentId});
      ttsState = data || {ready:false,verified:false};
      renderVoice();
      if (showStatus) {
        setStatus(ttsState.ready
          ? `ElevenLabs is ready${ttsState.voice_name ? ` · ${clean(ttsState.voice_name,90)}` : ''}.`
          : clean(ttsState.error || 'ElevenLabs is not ready.', 240), ttsState.ready ? 'success' : 'error');
      }
      return ttsState.ready === true;
    } catch (error) {
      ttsState = {ready:false,verified:false,error:error instanceof Error ? error.message : 'ElevenLabs readiness check failed.'};
      renderVoice();
      if (showStatus) setStatus(ttsState.error, 'error');
      return false;
    }
  }

  async function load() {
    const tasks = [
      json(cfg.agentEndpoint).then(data => { agentState = data.state || {}; }),
      json(`${cfg.voiceEndpoint}?action=state`).then(data => { voiceState = data.state || {}; }).catch(() => { voiceState = null; }),
      json(`${cfg.chatSettingsEndpoint}?action=state`).then(data => { agentVoiceAllowed=data.agent_voice_allowed!==false; const enabled=agentVoiceAllowed&&data.chat?.agent_voice_enabled!==false; renderAgentVoice(enabled); publishAgentVoice(enabled); }).catch(() => { agentVoiceAllowed=false; renderAgentVoice(false); publishAgentVoice(false); })
    ];
    try {
      await Promise.all(tasks);
      renderAgents();
      renderSystemVoice();
      await refreshTtsState(false);
      render();
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Agent settings could not be loaded.', 'error');
      if (el.body) el.body.hidden = false;
    }
  }

  async function saveAgent(changes, successMessage) {
    const agent = selectedAgent();
    if (!agent || busy) return false;
    busy = true;
    dashboard.dataset.saving = 'true';
    try {
      const data = await post(cfg.agentEndpoint, 'update_agent', agentPayload(agent, changes));
      agentState = data.state || agentState;
      render();
      setStatus(successMessage, 'success');
      return true;
    } catch (error) {
      render();
      setStatus(error instanceof Error ? error.message : 'Agent settings could not be saved.', 'error');
      return false;
    } finally {
      busy = false;
      delete dashboard.dataset.saving;
    }
  }

  function publishState() {
    const agent=activeAgents().find(row=>Number(row.is_default)===1)||null;
    window.VP3_AGENT_PREFERENCE_STATE={ownerKey:String(cfg.userId),enabled:agentVoiceEnabled,allowed:agentVoiceAllowed,agent:agent?{id:Number(agent.id),name:String(agent.display_name)}:null};
    window.dispatchEvent(new CustomEvent('vp3:agent-preferences',{detail:window.VP3_AGENT_PREFERENCE_STATE}));
  }

  function renderSystemVoice(voices=null) {
    if(!el.systemVoice)return;
    const saved=agentState?.system_voice||{};
    if(voices)el.systemVoice.innerHTML='<option value="">VP3 configured default</option>'+voices.map(v=>`<option value="${esc(v.voice_id)}">${esc(v.name)}</option>`).join('');
    if(saved.voice_id&&!Array.from(el.systemVoice.options).some(o=>o.value===saved.voice_id))el.systemVoice.add(new Option(saved.voice_name||'Saved system voice',saved.voice_id));
    el.systemVoice.value=saved.voice_id||'';
    el.systemVoice.disabled=!agentVoiceAllowed;
  }
  async function loadCatalog(){
    if(catalogLoaded||catalogBusy||!agentVoiceAllowed)return;
    catalogBusy=true;el.systemVoiceStatus.textContent='Loading system voices…';
    try{const data=await json(cfg.systemVoiceEndpoint);agentState.system_voice=data.selected;renderSystemVoice(data.voices||[]);catalogLoaded=true;el.systemVoiceStatus.textContent='Used when Voice source is VP3 default voice.';}
    catch(error){el.systemVoiceStatus.textContent=error.message||'System voices could not be loaded.';}
    finally{catalogBusy=false;}
  }
  document.getElementById('chatProfileButton')?.addEventListener('click',()=>void loadCatalog());
  el.systemVoice?.addEventListener('focus',()=>void loadCatalog());
  el.systemVoice?.addEventListener('change',()=>{
    const id=el.systemVoice.value;el.systemVoice.disabled=true;
    void post(cfg.systemVoiceEndpoint,'save_system_voice',{voice_id:id}).then(async data=>{
      agentState.system_voice=data.selected;renderSystemVoice();
      window.dispatchEvent(new CustomEvent('vp3:system-voice-changed'));
      el.systemVoiceStatus.textContent='System voice saved for your account.';
      await refreshTtsState(false);
    }).catch(error=>{renderSystemVoice();el.systemVoiceStatus.textContent=error.message||'Voice could not be saved.';});
  });

  el.selector?.addEventListener('change', () => {
    const id=Number(el.selector.value||0);if(id<1||busy)return;
    busy=true;el.selector.disabled=true;
    void post(cfg.agentEndpoint,'set_default_agent',{agent_id:id}).then(data=>{
      agentState=data.state||agentState;selectedAgentId=id;render();
      setStatus('Default agent saved. New chats will use this agent.','success');
      return refreshTtsState(false);
    }).catch(error=>{render();setStatus(error.message||'Default agent could not be saved.','error');})
      .finally(()=>{busy=false;el.selector.disabled=false;});
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
    void saveAgent({voice_enabled:useClone}, useClone ? 'ElevenLabs clone assigned to this agent.' : 'VP3 default voice selected.').then(() => refreshTtsState(true));
  });

  el.agentVoice?.addEventListener('change', event => {
    event.stopPropagation();
    if(!agentVoiceAllowed){renderAgentVoice(false);return;}
    const requested = Boolean(el.agentVoice.checked);
    el.agentVoice.disabled=true;
    void (async () => {
      try {
        const data = await post(cfg.chatSettingsEndpoint, 'save_agent_voice', {agent_voice_enabled:requested});
        renderAgentVoice(data.chat?.agent_voice_enabled !== false);
        publishState();
        publishAgentVoice(agentVoiceEnabled);
        window.dispatchEvent(new CustomEvent('stonefellow:agent-voice', {detail:{enabled:data.chat?.agent_voice_enabled !== false}}));
        if (requested) await refreshTtsState(true);
        else { ttsState = null; renderVoice(); setStatus('Agent Voice is off.', 'success'); }
      } catch (error) {
        renderAgentVoice(!requested);
        setStatus(error instanceof Error ? error.message : 'Agent Voice could not be saved.', 'error');
      } finally { el.agentVoice.disabled=!agentVoiceAllowed; }
    })();
  });

  window.addEventListener('stonefellow:agent-voice', event => { const enabled=event.detail?.enabled !== false; renderAgentVoice(enabled); if(!event.detail?.fromStorage)publishAgentVoice(enabled); publishState(); });
  window.addEventListener('storage', event => {
    if (event.key !== agentVoiceSyncKey || !event.newValue) return;
    try { const sync=JSON.parse(event.newValue); renderAgentVoice(sync?.enabled !== false); publishState(); if(sync?.enabled===false)window.dispatchEvent(new CustomEvent('stonefellow:agent-voice',{detail:{enabled:false,fromStorage:true}})); } catch (_error) {}
  });
  void load();
  void loadPhone();
})();
