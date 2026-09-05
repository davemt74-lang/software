(() => {
  'use strict';

  const cfg = window.STONEFELLOW_AGENT_IDENTITY_V236 || {};
  if (!cfg.displayName) return;

  const thread = document.getElementById('chatThread');
  const top = document.querySelector('.chat-topbar-title');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
  }[char]));
  const build = 'chat-attention-canvas-20260905';
  const onboardingEndpoint = new URL('./api/chat-onboarding-v241.php', window.location.href).href;

  async function settingsRequest(action = '', payload = {}) {
    if (!cfg.endpoint) throw new Error('Agent settings are unavailable.');
    const options = action ? {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action, csrf_token:cfg.csrf, ...payload})
    } : {credentials:'same-origin', cache:'no-store'};
    const response = await fetch(cfg.endpoint, options);
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.error || 'Agent setup failed.');
    return data;
  }

  async function onboardingRequest(action = 'state', payload = null) {
    const post = payload !== null;
    const response = await fetch(onboardingEndpoint, post ? {
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action, csrf_token:cfg.csrf, ...payload})
    } : {credentials:'same-origin', cache:'no-store'});
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.error || 'Onboarding state could not be loaded.');
    return data;
  }

  function chatUrlFor(agentValue) {
    const target = new URL(cfg.chatBaseUrl || './chat.php', window.location.href);
    const current = new URLSearchParams(window.location.search);
    current.set('agent', String(agentValue));
    target.search = current.toString();
    return target.pathname + target.search + target.hash;
  }
  function agentUrl(agentId) { return chatUrlFor(Number(agentId)); }

  function applyMessageIdentity(message) {
    if (!message?.matches?.('.message.assistant')) return;
    const role = message.querySelector('.message-role');
    const avatar = message.querySelector('.message-avatar');
    if (role) role.textContent = cfg.displayName;
    if (avatar) avatar.textContent = String(cfg.displayName).trim().charAt(0).toUpperCase() || 'S';
    message.dataset.agentIdentityV236 = String(cfg.agentId || 0);
  }
  function applyIdentity(scope = document) {
    applyMessageIdentity(scope);
    scope.querySelectorAll?.('.message.assistant').forEach(applyMessageIdentity);
  }

  function ensureHeaderCss() {
    const old = document.querySelector('link[data-chat-header-ui]');
    if (old?.href?.includes(build)) return;
    old?.remove();
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.dataset.chatHeaderUi = '1';
    css.href = new URL(`./chat-header-ui.css?v=${build}`, window.location.href).href;
    document.head.appendChild(css);
  }

  function profileMenuLink(nav, label, href, key, before = null) {
    if (!nav || !href || nav.querySelector(`[data-live-profile-link="${key}"]`)) return;
    const anchor = document.createElement('a');
    anchor.href = href;
    anchor.dataset.liveProfileLink = key;
    anchor.innerHTML = `<span>${esc(label)}</span><span>↗</span>`;
    if (before && before.parentNode === nav) nav.insertBefore(anchor, before);
    else nav.appendChild(anchor);
  }

  async function wireProfileMenu() {
    const nav = document.querySelector('.chat-profile-links');
    if (!nav) return;
    const logout = nav.querySelector('a.logout');
    profileMenuLink(nav, 'Agent Settings', cfg.accountUrl || './account.php#agents-data', 'agent-settings', logout);
    profileMenuLink(nav, 'Profile Agent Dashboard', new URL('./account.php#profile-agent', window.location.href).pathname + '#profile-agent', 'profile-agent', logout);
    try {
      const endpoint = new URL('./api/profile-agent.php', window.location.href);
      endpoint.searchParams.set('action', 'owner_state');
      const response = await fetch(endpoint, {credentials:'same-origin', cache:'no-store'});
      const data = await response.json().catch(() => null);
      if (response.ok && data?.ok && data.state?.profile_url) {
        const oldArtist = [...nav.querySelectorAll('a')].find(a => /View Artist Profile/i.test(a.textContent || ''));
        if (oldArtist) oldArtist.remove();
        const before = nav.querySelector('[data-live-profile-link="agent-settings"]') || logout;
        profileMenuLink(nav, 'My Profile', data.state.profile_url, 'my-profile', before);
      }
    } catch (_error) {}
  }

  if (window.STONEFELLOW_CHAT) window.STONEFELLOW_CHAT.agentDisplayName = cfg.displayName;
  if (top) {
    top.dataset.userAgentV236 = String(cfg.agentId || 0);
    const strong = top.querySelector('strong');
    const span = top.querySelector('span');
    if (strong) strong.textContent = cfg.displayName;
    if (span) span.textContent = Number(cfg.agentId) > 0 ? `Your Stonefellow agent · ${cfg.displayName}` : 'Universal system agent';
  }
  applyIdentity();
  ensureHeaderCss();
  wireProfileMenu();

  const observer = new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
    if (node.nodeType === 1) applyIdentity(node);
  })));
  observer.observe(document.body, {childList:true, subtree:true});
  window.addEventListener('pagehide', () => observer.disconnect(), {once:true});

  const requestedAgent = new URLSearchParams(window.location.search).get('agent');
  if (Number(cfg.agentId) === 0 && requestedAgent !== 'system' && !cfg.showOnboarding && cfg.endpoint && cfg.chatBaseUrl) {
    settingsRequest().then(data => {
      const agents = Array.isArray(data.state?.agents) ? data.state.agents : [];
      const active = agents.filter(agent => Number(agent.is_active));
      const preferred = active.find(agent => Number(agent.is_default)) || active[0] || null;
      if (preferred) window.location.replace(agentUrl(preferred.id));
    }).catch(() => {});
  }

  let onboardingState = null;
  let onboardingCard = null;
  let currentStep = 0;
  let voiceGuideEnabled = false;
  let premiumVoice = null;
  let busy = false;
  let draft = null;

  const steps = [
    {
      key:'voice',
      title:'Choose your onboarding experience',
      prompt:'Welcome. I can guide you through setting up your Stonefellow agent, profile, chat and voice. Would you like voice-guided onboarding?'
    },
    {
      key:'agent',
      title:'Name your agent',
      prompt:'First, choose what you want to call your agent. You can keep Stonefellow or choose a personal name.'
    },
    {
      key:'profile',
      title:'Set up your profile',
      prompt:'Next, choose your profile address and whether your profile is visible to other people.'
    },
    {
      key:'profile_agent',
      title:'Turn on your Profile Agent',
      prompt:'Your Profile Agent can answer visitors on your profile using only the information you allow it to use.'
    },
    {
      key:'chat',
      title:'Choose your chat availability',
      prompt:'Choose whether you appear online and whether other Stonefellow users can message you.'
    },
    {
      key:'voice_clone',
      title:'Set up your voice clone',
      prompt:'You can also create a private voice clone from your Voice Profile. This is optional and uses the voice system only when you choose to create it.'
    },
    {
      key:'review',
      title:'Review your setup',
      prompt:'Review your setup. Nothing in this onboarding uses the language model. These are direct account settings.'
    }
  ];

  function stateStorageKey(suffix) {
    const userId = Number(onboardingState?.user?.id || 0);
    return `stonefellow:onboarding:${userId || 'guest'}:${suffix}`;
  }

  function readStoredDraft() {
    try {
      const raw = window.sessionStorage.getItem(stateStorageKey('draft'));
      return raw ? JSON.parse(raw) : null;
    } catch (_error) { return null; }
  }

  function saveDraft() {
    if (!draft) return;
    try { window.sessionStorage.setItem(stateStorageKey('draft'), JSON.stringify(draft)); } catch (_error) {}
  }

  function readVoicePreference() {
    try { return window.localStorage.getItem(stateStorageKey('voice-guide')); } catch (_error) { return null; }
  }

  function writeVoicePreference(value) {
    try { window.localStorage.setItem(stateStorageKey('voice-guide'), value); } catch (_error) {}
  }

  function stopGuideVoice() {
    try { premiumVoice?.stop?.(); } catch (_error) {}
    try { window.speechSynthesis?.cancel?.(); } catch (_error) {}
  }

  async function premiumGuideVoice() {
    if (premiumVoice) return premiumVoice;
    if (typeof window.StonefellowPremiumVoiceV122 !== 'function') return null;
    premiumVoice = window.StonefellowPremiumVoiceV122({agentEndpoint:cfg.endpoint, csrf:cfg.csrf});
    try { await premiumVoice.unlock?.(); } catch (_error) {}
    return premiumVoice;
  }

  function systemSpeak(text) {
    if (!('speechSynthesis' in window) || typeof SpeechSynthesisUtterance !== 'function') return false;
    try {
      window.speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(String(text || ''));
      utterance.rate = 1;
      utterance.pitch = 1;
      window.speechSynthesis.speak(utterance);
      return true;
    } catch (_error) { return false; }
  }

  async function speakGuide(text) {
    if (!voiceGuideEnabled || !String(text || '').trim()) return;
    stopGuideVoice();
    try {
      const voice = await premiumGuideVoice();
      if (voice) {
        await voice.speak(String(text));
        return;
      }
    } catch (_error) {}
    systemSpeak(text);
  }

  function exposeOnboardingState(state) {
    onboardingState = state || null;
    window.STONEFELLOW_ONBOARDING_STATE = onboardingState;
    window.dispatchEvent(new CustomEvent('stonefellow:onboarding-state', {detail:onboardingState || {}}));
  }

  function defaultGreeting(agentName) {
    const owner = String(onboardingState?.user?.display_name || 'this member').trim() || 'this member';
    return `Hi — I’m ${agentName || cfg.systemName || 'Stonefellow'}, ${owner}’s profile agent. What would you like to know?`;
  }

  function initializeDraft() {
    const state = onboardingState || {};
    const stored = readStoredDraft() || {};
    const profile = state.profile || {};
    const chat = state.chat || {};
    const agentName = String(stored.agent_name || state.agent?.display_name || cfg.systemName || 'STONEFELLOW');
    draft = {
      agent_name:agentName,
      username:String(stored.username || profile.username || state.suggested_username || ''),
      profile_public:stored.profile_public !== undefined ? Boolean(stored.profile_public) : true,
      profile_agent_enabled:stored.profile_agent_enabled !== undefined ? Boolean(stored.profile_agent_enabled) : true,
      profile_agent_greeting:String(stored.profile_agent_greeting || profile.profile_agent_greeting || defaultGreeting(agentName)),
      presence_mode:String(stored.presence_mode || chat.presence_mode || 'online'),
      social_chat_enabled:stored.social_chat_enabled !== undefined ? Boolean(stored.social_chat_enabled) : chat.social_chat_enabled !== false,
      sound_enabled:stored.sound_enabled !== undefined ? Boolean(stored.sound_enabled) : chat.sound_enabled !== false
    };
    saveDraft();
  }

  function setStatus(message = '', kind = '') {
    const status = onboardingCard?.querySelector('.chat-agent-name-status-v236');
    if (!status) return;
    status.textContent = message;
    status.className = `chat-agent-name-status-v236${kind ? ` ${kind}` : ''}`;
  }

  function voiceControlMarkup() {
    if (currentStep === 0) return '';
    return `<button class="chat-agent-voice-control-v241" type="button" data-toggle-guide-voice>${voiceGuideEnabled ? '🔊 Voice on' : '🔇 Voice off'}</button>`;
  }

  function headerMarkup() {
    const progress = Math.round(((currentStep + 1) / steps.length) * 100);
    return `<header class="chat-agent-onboarding-head-v241">
      <div><small>Agent setup</small><h2>Set up your Stonefellow</h2></div>
      <div class="chat-agent-onboarding-progress-v241"><strong>Step ${currentStep + 1} of ${steps.length}</strong><div class="chat-agent-onboarding-track-v241"><i style="width:${progress}%"></i></div></div>
    </header>`;
  }

  function copyMarkup(step) {
    return `<div class="chat-agent-step-copy-v241"><small>${esc(step.key.replace('_', ' '))}</small><h3>${esc(step.title)}</h3><p>${esc(step.prompt)}</p></div>`;
  }

  function actionsMarkup({nextLabel = 'Continue', hideBack = false, finish = false} = {}) {
    return `<div class="chat-agent-onboarding-actions-v241">
      <div class="chat-agent-action-group-v241">${hideBack ? '' : '<button class="chat-agent-button-v241" type="button" data-onboarding-back>Back</button>'}${voiceControlMarkup()}</div>
      <div class="chat-agent-action-group-v241"><button class="chat-agent-button-v241 primary" type="button" ${finish ? 'data-onboarding-finish' : 'data-onboarding-next'}>${esc(nextLabel)}</button></div>
    </div>`;
  }

  function profilePathMarkup() {
    const username = String(draft?.username || onboardingState?.suggested_username || '').trim();
    const display = username ? `/${username}` : '/your-name';
    const existingUrl = String(onboardingState?.profile_url || '');
    return `<div class="chat-agent-profile-path-v241"><code>${esc(display)}</code>${existingUrl ? `<a href="${esc(existingUrl)}?preview=1" target="_blank" rel="noopener">View profile ↗</a>` : '<span>Profile view becomes available when setup is saved.</span>'}</div>`;
  }

  function stepBodyMarkup() {
    const step = steps[currentStep];
    const copy = copyMarkup(step);

    if (step.key === 'voice') {
      return `${copy}<div class="chat-agent-choice-grid-v241">
        <button class="chat-agent-choice-v241" type="button" data-voice-choice="on"><span class="chat-agent-choice-icon-v241">🔊</span><span><strong>Turn on voice</strong><span>Use ElevenLabs voice guidance when available, with automatic system-voice fallback.</span></span></button>
        <button class="chat-agent-choice-v241" type="button" data-voice-choice="off"><span class="chat-agent-choice-icon-v241">⌨</span><span><strong>Continue with text</strong><span>Keep onboarding silent. You can turn voice guidance on later.</span></span></button>
      </div><div class="chat-agent-name-status-v236" role="status" aria-live="polite"></div>`;
    }

    if (step.key === 'agent') {
      return `${copy}<div class="chat-agent-form-v241">
        <label class="chat-agent-field-v241"><span>Agent name</span><div class="chat-agent-inline-v241"><input name="agent_name" maxlength="190" autocomplete="off" value="${esc(draft.agent_name)}" placeholder="Name your agent"><button class="chat-agent-button-v241" type="button" data-keep-system>Keep ${esc(cfg.systemName || 'STONEFELLOW')}</button></div><small class="chat-agent-hint-v241">This is your personal Stonefellow identity. Naming it does not reduce its capabilities.</small></label>
      </div><div class="chat-agent-name-status-v236" role="status" aria-live="polite"></div>${actionsMarkup()}`;
    }

    if (step.key === 'profile') {
      return `${copy}<div class="chat-agent-form-v241">
        <label class="chat-agent-field-v241"><span>Profile address</span><input name="username" maxlength="60" autocomplete="off" value="${esc(draft.username)}" placeholder="your-name"><small class="chat-agent-hint-v241">Letters, numbers, dots, dashes and underscores.</small>${profilePathMarkup()}</label>
        <div><label class="chat-agent-toggle-v241"><span><strong>Show my profile</strong><small>Publish your profile so people can open your profile view.</small></span><input type="checkbox" name="profile_public"${draft.profile_public ? ' checked' : ''}></label></div>
      </div><div class="chat-agent-name-status-v236" role="status" aria-live="polite"></div>${actionsMarkup()}`;
    }

    if (step.key === 'profile_agent') {
      return `${copy}<div class="chat-agent-form-v241">
        <div><label class="chat-agent-toggle-v241"><span><strong>Enable Profile Agent</strong><small>Let visitors chat with your agent from your profile. It only uses information you permit.</small></span><input type="checkbox" name="profile_agent_enabled"${draft.profile_agent_enabled ? ' checked' : ''}></label></div>
        <label class="chat-agent-field-v241"><span>Profile Agent greeting</span><textarea name="profile_agent_greeting" maxlength="500">${esc(draft.profile_agent_greeting)}</textarea></label>
        <div class="chat-agent-panel-v241"><strong>Same agent, separate permissions</strong><p>Your Profile Agent uses your Stonefellow agent identity, but public data access remains controlled by the existing Profile Agent permission system.</p></div>
      </div><div class="chat-agent-name-status-v236" role="status" aria-live="polite"></div>${actionsMarkup()}`;
    }

    if (step.key === 'chat') {
      return `${copy}<div class="chat-agent-form-v241">
        <label class="chat-agent-field-v241"><span>My availability</span><select name="presence_mode"><option value="online"${draft.presence_mode === 'online' ? ' selected' : ''}>Online</option><option value="offline"${draft.presence_mode === 'offline' ? ' selected' : ''}>Offline</option></select></label>
        <div><label class="chat-agent-toggle-v241"><span><strong>Online user-to-user chat</strong><small>Allow eligible Stonefellow users to find you and send direct messages.</small></span><input type="checkbox" name="social_chat_enabled"${draft.social_chat_enabled ? ' checked' : ''}></label>
        <label class="chat-agent-toggle-v241"><span><strong>Incoming chat sound</strong><small>Play one notification sound when a new direct message arrives.</small></span><input type="checkbox" name="sound_enabled"${draft.sound_enabled ? ' checked' : ''}></label></div>
      </div><div class="chat-agent-name-status-v236" role="status" aria-live="polite"></div>${actionsMarkup()}`;
    }

    if (step.key === 'voice_clone') {
      const voice = onboardingState?.voice || {};
      const created = Boolean(voice.clone_created);
      const verified = Boolean(voice.clone_verified);
      return `${copy}<div class="chat-agent-status-grid-v241">
        <div class="chat-agent-status-item-v241" data-ready="${voice.available ? 'true' : 'false'}"><span>Voice Profile</span><strong>${voice.available ? 'Ready' : 'Available to set up'}</strong></div>
        <div class="chat-agent-status-item-v241" data-ready="${created ? 'true' : 'false'}"><span>Voice sample</span><strong>${Number(voice.sample_count || 0)} saved</strong></div>
        <div class="chat-agent-status-item-v241" data-ready="${created ? 'true' : 'false'}"><span>Voice clone</span><strong>${created ? 'Created' : 'Not created'}</strong></div>
        <div class="chat-agent-status-item-v241" data-ready="${verified ? 'true' : 'false'}"><span>Clone state</span><strong>${verified ? 'Verified' : (created ? 'Created · verification pending' : 'Optional')}</strong></div>
      </div><div class="chat-agent-panel-v241"><strong>Voice cloning is opt-in</strong><p>Your existing Voice Profile records or uploads a sample and only sends the selected sample to ElevenLabs when you explicitly choose to create your clone.</p></div>
      <div class="chat-agent-onboarding-actions-v241"><div class="chat-agent-action-group-v241"><button class="chat-agent-button-v241" type="button" data-onboarding-back>Back</button>${voiceControlMarkup()}</div><div class="chat-agent-action-group-v241"><button class="chat-agent-button-v241" type="button" data-refresh-voice>Refresh status</button><a class="chat-agent-button-v241" href="${esc(voice.url || new URL('./voice-profile.php', window.location.href).href)}" target="_blank" rel="noopener">${created ? 'Open Voice Profile' : 'Make a Voice Clone'} ↗</a><button class="chat-agent-button-v241 primary" type="button" data-onboarding-next>${created ? 'Continue' : 'Skip for now'}</button></div></div><div class="chat-agent-name-status-v236" role="status" aria-live="polite"></div>`;
    }

    const rows = [
      ['Agent', draft.agent_name],
      ['Profile', `/${draft.username} · ${draft.profile_public ? 'shown' : 'private'}`],
      ['Profile Agent', draft.profile_agent_enabled ? 'enabled' : 'off'],
      ['Availability', draft.presence_mode === 'online' ? 'online' : 'offline'],
      ['User-to-user chat', draft.social_chat_enabled ? 'enabled' : 'off'],
      ['Incoming sound', draft.sound_enabled ? 'enabled' : 'off'],
      ['Voice clone', onboardingState?.voice?.clone_created ? 'created' : 'not set up yet']
    ];
    return `${copy}<div class="chat-agent-review-v241">${rows.map(([label, value]) => `<div class="chat-agent-review-row-v241"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`).join('')}</div>
      <div class="chat-agent-panel-v241"><strong>Zero LLM tokens used for setup</strong><p>This onboarding reads and writes account state directly through Stonefellow’s profile, chat, Profile Agent and voice systems. The language model is not called.</p></div>
      <div class="chat-agent-name-status-v236" role="status" aria-live="polite"></div>${actionsMarkup({nextLabel:'Finish setup', finish:true})}`;
  }

  function captureCurrentStep() {
    if (!onboardingCard || !draft) return true;
    const step = steps[currentStep]?.key;
    if (step === 'agent') {
      const input = onboardingCard.querySelector('[name="agent_name"]');
      const name = String(input?.value || '').trim().replace(/\s+/g, ' ');
      if (!name) { setStatus('Choose a name for your agent.', 'error'); input?.focus(); return false; }
      const oldName = draft.agent_name;
      draft.agent_name = name.slice(0, 190);
      if (!draft.profile_agent_greeting || draft.profile_agent_greeting === defaultGreeting(oldName)) draft.profile_agent_greeting = defaultGreeting(draft.agent_name);
    }
    if (step === 'profile') {
      const usernameInput = onboardingCard.querySelector('[name="username"]');
      const username = String(usernameInput?.value || '').trim().toLowerCase();
      if (!/^[a-z0-9](?:[a-z0-9._-]{1,58}[a-z0-9])?$/.test(username)) {
        setStatus('Choose a 3–60 character profile address using letters, numbers, dots, dashes or underscores.', 'error');
        usernameInput?.focus();
        return false;
      }
      draft.username = username;
      draft.profile_public = Boolean(onboardingCard.querySelector('[name="profile_public"]')?.checked);
    }
    if (step === 'profile_agent') {
      draft.profile_agent_enabled = Boolean(onboardingCard.querySelector('[name="profile_agent_enabled"]')?.checked);
      draft.profile_agent_greeting = String(onboardingCard.querySelector('[name="profile_agent_greeting"]')?.value || '').trim().slice(0, 500);
      if (draft.profile_agent_enabled && !draft.profile_agent_greeting) draft.profile_agent_greeting = defaultGreeting(draft.agent_name);
    }
    if (step === 'chat') {
      draft.presence_mode = String(onboardingCard.querySelector('[name="presence_mode"]')?.value || 'online');
      draft.social_chat_enabled = Boolean(onboardingCard.querySelector('[name="social_chat_enabled"]')?.checked);
      draft.sound_enabled = Boolean(onboardingCard.querySelector('[name="sound_enabled"]')?.checked);
    }
    saveDraft();
    return true;
  }

  function wireStepEvents() {
    if (!onboardingCard) return;

    onboardingCard.querySelectorAll('[data-voice-choice]').forEach(button => button.addEventListener('click', async () => {
      const choice = button.dataset.voiceChoice === 'on';
      voiceGuideEnabled = choice;
      writeVoicePreference(choice ? 'on' : 'off');
      if (choice) {
        button.disabled = true;
        setStatus('Starting voice guidance…');
        try { await premiumGuideVoice(); } catch (_error) {}
      } else stopGuideVoice();
      currentStep = 1;
      renderStep(false);
      if (choice) void speakGuide(steps[currentStep].prompt);
    }));

    onboardingCard.querySelector('[data-toggle-guide-voice]')?.addEventListener('click', async () => {
      voiceGuideEnabled = !voiceGuideEnabled;
      writeVoicePreference(voiceGuideEnabled ? 'on' : 'off');
      if (!voiceGuideEnabled) stopGuideVoice();
      else {
        try { await premiumGuideVoice(); } catch (_error) {}
      }
      renderStep(false);
      if (voiceGuideEnabled) void speakGuide(steps[currentStep].prompt);
    });

    onboardingCard.querySelector('[data-keep-system]')?.addEventListener('click', () => {
      const input = onboardingCard.querySelector('[name="agent_name"]');
      if (input) { input.value = cfg.systemName || 'STONEFELLOW'; input.focus(); }
    });

    onboardingCard.querySelector('[data-onboarding-back]')?.addEventListener('click', () => {
      captureCurrentStep();
      currentStep = Math.max(0, currentStep - 1);
      renderStep(true);
    });

    onboardingCard.querySelector('[data-onboarding-next]')?.addEventListener('click', () => {
      if (!captureCurrentStep()) return;
      currentStep = Math.min(steps.length - 1, currentStep + 1);
      renderStep(true);
    });

    onboardingCard.querySelector('[data-refresh-voice]')?.addEventListener('click', async event => {
      const target = event.currentTarget;
      target.disabled = true;
      setStatus('Checking Voice Profile…');
      try {
        const data = await onboardingRequest('state');
        exposeOnboardingState(data.state);
        setStatus('Voice status refreshed.', 'success');
        renderStep(false);
      } catch (error) {
        setStatus(error instanceof Error ? error.message : 'Voice status could not be refreshed.', 'error');
      } finally { target.disabled = false; }
    });

    onboardingCard.querySelector('[data-onboarding-finish]')?.addEventListener('click', finishOnboarding);
  }

  function renderStep(speak = false) {
    if (!onboardingCard) return;
    onboardingCard.innerHTML = `${headerMarkup()}<div class="chat-agent-onboarding-body-v241">${stepBodyMarkup()}</div>`;
    wireStepEvents();
    if (speak && voiceGuideEnabled) void speakGuide(steps[currentStep].prompt);
  }

  async function finishOnboarding() {
    if (busy || !captureCurrentStep()) return;
    busy = true;
    const button = onboardingCard?.querySelector('[data-onboarding-finish]');
    if (button) { button.disabled = true; button.textContent = 'Saving…'; }
    setStatus('Saving your setup…');
    try {
      const data = await onboardingRequest('finish', {
        agent_name:draft.agent_name,
        username:draft.username,
        profile_public:draft.profile_public,
        profile_agent_enabled:draft.profile_agent_enabled,
        profile_agent_greeting:draft.profile_agent_greeting,
        presence_mode:draft.presence_mode,
        social_chat_enabled:draft.social_chat_enabled,
        sound_enabled:draft.sound_enabled
      });
      exposeOnboardingState(data.state);
      try { window.sessionStorage.removeItem(stateStorageKey('draft')); } catch (_error) {}
      setStatus('Setup complete. Opening your agent…', 'success');
      if (voiceGuideEnabled) void speakGuide(`Setup complete. ${draft.agent_name} is ready.`);
      window.setTimeout(() => window.location.assign(data.chat_url || agentUrl(data.agent_id)), 350);
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Onboarding could not be completed.', 'error');
      if (button) { button.disabled = false; button.textContent = 'Finish setup'; }
    } finally { busy = false; }
  }

  async function loadOnboardingState() {
    try {
      const data = await onboardingRequest('state');
      exposeOnboardingState(data.state);
      return data.state;
    } catch (_error) {
      return null;
    }
  }

  void loadOnboardingState().then(state => {
    if (!state || !thread || Number(cfg.agentId) > 0 || !cfg.showOnboarding) return;
    initializeDraft();
    const voicePreference = readVoicePreference();
    voiceGuideEnabled = voicePreference === 'on';
    currentStep = voicePreference === 'on' || voicePreference === 'off' ? 1 : 0;

    onboardingCard = document.createElement('section');
    onboardingCard.className = 'chat-agent-name-card-v236';
    onboardingCard.id = 'chatAgentNameCardV236';
    onboardingCard.dataset.deterministicOnboarding = 'v241';
    const firstMessage = thread.querySelector('.message');
    if (firstMessage) firstMessage.insertAdjacentElement('beforebegin', onboardingCard);
    else thread.prepend(onboardingCard);
    renderStep(false);
    if (voiceGuideEnabled && currentStep > 0) void speakGuide(steps[currentStep].prompt);
  });
})();