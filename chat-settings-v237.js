(() => {
  'use strict';

  const cfg = window.STONEFELLOW_CHAT_SETTINGS;
  const sidebar = document.getElementById('chatSidebar');
  if (!cfg?.endpoint || !cfg?.csrf) return;

  let state = null;
  let busy = false;
  let voiceBusy = false;
  let lastFocus = null;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
  }[char]));

  async function request(action, payload = null) {
    const post = payload !== null;
    const options = post ? {
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      headers:{ 'Content-Type':'application/json' },
      body:JSON.stringify({ action, csrf_token:cfg.csrf, ...payload })
    } : { credentials:'same-origin', cache:'no-store' };
    const url = post ? cfg.endpoint : `${cfg.endpoint}?action=${encodeURIComponent(action)}`;
    const response = await fetch(url, options);
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.error || 'Chat settings request failed.');
    return data;
  }

  function installLauncher() {
    if (!sidebar) return null;
    let host = document.getElementById('chatSettingsLauncher');
    if (host) return host;
    host = document.createElement('div');
    host.className = 'chat-settings-launcher';
    host.id = 'chatSettingsLauncher';
    host.innerHTML = `
      <button class="chat-settings-button" id="chatSettingsButton" type="button" aria-haspopup="dialog" aria-controls="chatSettingsModal" data-presence="online">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V21h-4v-.09A1.7 1.7 0 0 0 9 19.35a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.63 15 1.7 1.7 0 0 0 3.07 14H3v-4h.07A1.7 1.7 0 0 0 4.63 9a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.63 1.7 1.7 0 0 0 10 3.07V3h4v.07A1.7 1.7 0 0 0 15 4.63 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.37 9 1.7 1.7 0 0 0 20.93 10H21v4h-.07A1.7 1.7 0 0 0-1.53 1z"></path></svg>
        <span>Chat Settings</span>
        <small id="chatSettingsPresenceLabel">Online</small>
      </button>`;
    sidebar.appendChild(host);
    return host;
  }

  function installModal() {
    let modal = document.getElementById('chatSettingsModal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.className = 'chat-settings-modal';
    modal.id = 'chatSettingsModal';
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
      <button class="chat-settings-backdrop" type="button" data-close-chat-settings tabindex="-1" aria-label="Close Chat Settings"></button>
      <section class="chat-settings-dialog" role="dialog" aria-modal="true" aria-labelledby="chatSettingsTitle">
        <header class="chat-settings-head">
          <div><h2 id="chatSettingsTitle">Chat Settings</h2><p>Availability, direct messages and Profile Agent chat.</p></div>
          <button class="chat-settings-close" type="button" data-close-chat-settings aria-label="Close Chat Settings">×</button>
        </header>
        <form id="chatSettingsForm">
          <div class="chat-settings-body">
            <section class="chat-settings-section">
              <div class="chat-settings-section-title"><strong>Availability</strong><span>Choose whether other Stonefellow users see you as online.</span></div>
              <label class="chat-settings-field"><span>Status</span><select name="presence_mode"><option value="online">Online</option><option value="offline">Offline</option></select></label>
            </section>
            <section class="chat-settings-section">
              <div class="chat-settings-section-title"><strong>Social Chat</strong><span>Control direct user-to-user conversations and incoming message alerts.</span></div>
              <label class="chat-settings-toggle"><span><strong>Allow user-to-user chat</strong><small>Other eligible users can find you and send direct messages.</small></span><input type="checkbox" name="social_chat_enabled"></label>
              <label class="chat-settings-toggle"><span><strong>Incoming message sound</strong><small>Play one notification sound when a new direct message arrives.</small></span><input type="checkbox" name="sound_enabled"></label>
              <label class="chat-settings-toggle"><span><strong>Agent Voice</strong><small>Speak proactive and Profile Agent attention messages aloud.</small></span><input type="checkbox" name="agent_voice_enabled"></label>
            </section>
            <section class="chat-settings-section" id="chatSettingsProfileAgentSection">
              <div class="chat-settings-section-title"><strong>Profile Agent Chat</strong><span>Use the same public Profile Agent controls already owned by your profile.</span></div>
              <div id="chatSettingsProfileAgentFields"><div class="chat-settings-profile-unavailable">Loading Profile Agent settings…</div></div>
            </section>
          </div>
          <div class="chat-settings-status" id="chatSettingsStatus" role="status" aria-live="polite"></div>
          <footer class="chat-settings-actions">
            <button type="button" data-close-chat-settings>Cancel</button>
            <button class="primary" id="chatSettingsSave" type="submit">Save settings</button>
          </footer>
        </form>
      </section>`;
    document.body.appendChild(modal);
    return modal;
  }

  const launcher = installLauncher();
  const modal = installModal();
  const button = document.getElementById('chatSettingsButton');
  const form = document.getElementById('chatSettingsForm');
  const status = document.getElementById('chatSettingsStatus');
  const saveButton = document.getElementById('chatSettingsSave');
  const profileFields = document.getElementById('chatSettingsProfileAgentFields');
  const presenceLabel = document.getElementById('chatSettingsPresenceLabel');

  function setStatus(message = '', kind = '') {
    if (!status) return;
    status.textContent = message;
    status.className = `chat-settings-status${kind ? ` ${kind}` : ''}`;
  }

  function agentOptions(profileAgent) {
    const profile = profileAgent?.profile || {};
    const statusRow = profileAgent?.public_agent_status || {};
    const selected = Number(profile.profile_agent_id || statusRow.suggested_agent_id || 0);
    const agents = Array.isArray(profileAgent?.agents) ? profileAgent.agents : [];
    return `<option value="0">Choose an agent</option>` + agents.map(agent => {
      const id = Number(agent.id || 0);
      const suffix = Number(agent.is_active) ? '' : ' · inactive';
      return `<option value="${id}"${id === selected ? ' selected' : ''}>${esc(agent.display_name || 'Agent')}${suffix}</option>`;
    }).join('');
  }

  function renderProfileAgent(profileAgent) {
    if (!profileFields) return;
    if (!profileAgent) {
      profileFields.innerHTML = '<div class="chat-settings-profile-unavailable">Profile Agent controls are not available for this account.</div>';
      return;
    }
    const profile = profileAgent.profile || {};
    profileFields.innerHTML = `
      <label class="chat-settings-field"><span>Profile Agent</span><select name="profile_agent_id">${agentOptions(profileAgent)}</select></label>
      <label class="chat-settings-toggle"><span><strong>Accept Profile Agent conversations</strong><small>Allow visitors to chat with the agent on your public profile.</small></span><input type="checkbox" name="profile_agent_enabled"${Number(profile.profile_agent_enabled) ? ' checked' : ''}></label>
      <label class="chat-settings-field"><span>Greeting</span><textarea name="profile_agent_greeting" maxlength="500">${esc(profile.profile_agent_greeting || '')}</textarea></label>
      <label class="chat-settings-field"><span>Public agent instructions</span><textarea name="profile_agent_instructions" maxlength="4000">${esc(profile.profile_agent_instructions || '')}</textarea></label>`;
  }

  function applyAgentVoice(enabled) {
    const active = enabled !== false;
    document.querySelectorAll('[data-agent-voice-toggle]').forEach(toggle => {
      toggle.checked = active;
      toggle.closest('.member-agent-voice-toggle')?.setAttribute('data-enabled', active ? 'true' : 'false');
    });
    window.dispatchEvent(new CustomEvent('stonefellow:agent-voice', {
      detail:{enabled:active}
    }));
    return active;
  }

  function applyRuntimeSettings(chat) {
    const settings = chat || { presence_mode:'online', social_chat_enabled:true, sound_enabled:true, agent_voice_enabled:true };
    const online = String(settings.presence_mode || 'online') === 'online';
    const social = settings.social_chat_enabled !== false;
    const sound = settings.sound_enabled !== false;
    const agentVoice = applyAgentVoice(settings.agent_voice_enabled !== false);

    if (button) button.dataset.presence = online ? 'online' : 'offline';
    if (presenceLabel) presenceLabel.textContent = online ? 'Online' : 'Offline';

    const teamCfg = window.STONEFELLOW_TEAM_CHAT;
    if (teamCfg) teamCfg.soundEnabled = sound;

    const rail = document.getElementById('sfOnlineRailV109');
    const windows = document.getElementById('sfTeamChatWindowsV109');
    if (rail) rail.hidden = !social;
    if (windows) windows.hidden = !social;
    document.body.classList.toggle('sf-team-rail-active', social);

    window.dispatchEvent(new CustomEvent('stonefellow:chat-settings-updated', {
      detail:{
        presence_mode:online ? 'online' : 'offline',
        social_chat_enabled:social,
        sound_enabled:sound,
        agent_voice_enabled:agentVoice
      }
    }));
  }

  function renderState() {
    const chat = state?.chat || { presence_mode:'online', social_chat_enabled:true, sound_enabled:true, agent_voice_enabled:true };
    if (form) {
      const mode = form.elements.namedItem('presence_mode');
      const social = form.elements.namedItem('social_chat_enabled');
      const sound = form.elements.namedItem('sound_enabled');
      const voice = form.elements.namedItem('agent_voice_enabled');
      if (mode) mode.value = String(chat.presence_mode || 'online');
      if (social) social.checked = chat.social_chat_enabled !== false;
      if (sound) sound.checked = chat.sound_enabled !== false;
      if (voice) voice.checked = chat.agent_voice_enabled !== false;
    }
    renderProfileAgent(state?.profile_agent || null);
    applyRuntimeSettings(chat);
  }

  async function syncState(showError = false) {
    try {
      const data = await request('state');
      state = data;
      renderState();
      return data;
    } catch (error) {
      if (showError) setStatus(error instanceof Error ? error.message : 'Could not load Chat settings.', 'error');
      return null;
    }
  }

  function openModal() {
    lastFocus = document.activeElement;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('chat-settings-open');
    setStatus('');
    void syncState(true).then(() => {
      form?.elements.namedItem('presence_mode')?.focus();
    });
  }

  function closeModal() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('chat-settings-open');
    setStatus('');
    if (lastFocus instanceof HTMLElement) lastFocus.focus();
  }

  async function saveSettings(event) {
    event.preventDefault();
    if (busy || !form) return;
    busy = true;
    saveButton.disabled = true;
    saveButton.textContent = 'Saving…';
    setStatus('Saving settings…');

    const presence = form.elements.namedItem('presence_mode');
    const social = form.elements.namedItem('social_chat_enabled');
    const sound = form.elements.namedItem('sound_enabled');
    const voice = form.elements.namedItem('agent_voice_enabled');

    try {
      const chatResponse = await request('save_chat', {
        presence_mode:String(presence?.value || 'online'),
        social_chat_enabled:Boolean(social?.checked),
        sound_enabled:Boolean(sound?.checked),
        agent_voice_enabled:Boolean(voice?.checked)
      });

      let profileAgent = state?.profile_agent || null;
      const agentId = form.elements.namedItem('profile_agent_id');
      if (profileAgent && agentId) {
        const enabled = form.elements.namedItem('profile_agent_enabled');
        const greeting = form.elements.namedItem('profile_agent_greeting');
        const instructions = form.elements.namedItem('profile_agent_instructions');
        const profileResponse = await request('save_profile_agent', {
          profile_agent_id:Number(agentId.value || 0),
          profile_agent_enabled:Boolean(enabled?.checked),
          profile_agent_greeting:String(greeting?.value || ''),
          profile_agent_instructions:String(instructions?.value || '')
        });
        profileAgent = profileResponse.profile_agent || profileAgent;
      }

      state = { ok:true, chat:chatResponse.chat, profile_agent:profileAgent };
      renderState();
      setStatus('Chat settings saved.', 'success');
      window.setTimeout(closeModal, 450);
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Could not save Chat settings.', 'error');
    } finally {
      busy = false;
      saveButton.disabled = false;
      saveButton.textContent = 'Save settings';
    }
  }

  async function saveAgentVoiceToggle(toggle) {
    if (voiceBusy) {
      toggle.checked = !toggle.checked;
      return;
    }
    voiceBusy = true;
    const host = toggle.closest('.member-agent-voice-toggle');
    host?.setAttribute('data-saving', 'true');
    const requested = Boolean(toggle.checked);
    try {
      const response = await request('save_agent_voice', {agent_voice_enabled:requested});
      state = {...(state || {}), ok:true, chat:response.chat || state?.chat || {}};
      applyRuntimeSettings(response.chat || {agent_voice_enabled:requested});
    } catch (error) {
      applyAgentVoice(!requested);
      window.dispatchEvent(new CustomEvent('stonefellow:agent-voice-error', {
        detail:{message:error instanceof Error ? error.message : 'Could not save Agent Voice.'}
      }));
    } finally {
      host?.removeAttribute('data-saving');
      voiceBusy = false;
    }
  }

  function ensureNotificationNextToProfile() {
    const actions = document.querySelector('.chat-topbar-actions');
    const notification = document.getElementById('chatNotificationMenu');
    const profile = document.getElementById('chatProfileMenu');
    if (!actions || !notification || !profile || notification.parentElement !== actions || profile.parentElement !== actions) return;
    if (notification.nextElementSibling !== profile) actions.insertBefore(notification, profile);
  }

  function installPlayerClose() {
    const player = document.getElementById('chatNowPlaying');
    if (!player || document.getElementById('chatNowPlayingClose')) return;
    const close = document.createElement('button');
    close.id = 'chatNowPlayingClose';
    close.className = 'chat-now-playing-close';
    close.type = 'button';
    close.setAttribute('aria-label', 'Close audio player');
    close.title = 'Close player';
    close.textContent = '×';
    close.addEventListener('click', () => {
      document.querySelectorAll('audio.chat-audio-player, audio.chat-audio-native').forEach(audio => {
        try { audio.pause(); } catch (error) {}
      });
      const volumePopover = document.getElementById('chatNowPlayingVolumePopover');
      const volumeButton = document.getElementById('chatNowPlayingVolumeButton');
      if (volumePopover) volumePopover.hidden = true;
      if (volumeButton) volumeButton.setAttribute('aria-expanded', 'false');
      player.hidden = true;
    });
    player.appendChild(close);
  }

  button?.addEventListener('click', openModal);
  modal.querySelectorAll('[data-close-chat-settings]').forEach(node => node.addEventListener('click', closeModal));
  form?.addEventListener('submit', saveSettings);
  document.addEventListener('change', event => {
    const toggle = event.target.closest?.('[data-agent-voice-toggle]');
    if (toggle) void saveAgentVoiceToggle(toggle);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !modal.hidden) closeModal();
  });

  ensureNotificationNextToProfile();
  const topbar = document.querySelector('.chat-topbar-actions');
  if (topbar) new MutationObserver(ensureNotificationNextToProfile).observe(topbar, { childList:true });
  installPlayerClose();
  void syncState(false);
})();
