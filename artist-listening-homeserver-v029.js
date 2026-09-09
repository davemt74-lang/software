(() => {
  'use strict';

  const BUILD = 'artist-listening-homeserver-v029-20260908';
  const cfg = window.STONEFELLOW_ARTIST_LISTENING_V172 || {};
  if (!cfg.endpoint) return;

  const endpoint = String(cfg.endpoint).replace(
    /artist-listening-v172\.php(?:\?.*)?$/i,
    'artist-listening-homeserver-v029.php'
  );
  const proof = window.STONEFELLOW_ARTIST_LISTENING_HOMESERVER_V029 = {
    build: BUILD,
    loaded: true,
    saves: 0,
    cloudMirrors: 0,
    syncs: 0,
    permissionUpgrades: 0,
    lastError: '',
  };

  const state = {
    sessionId: 0,
    backup: null,
    homeserver: null,
    syncing: false,
    mirrorTimer: 0,
    pollTimer: 0,
    approvalCode: '',
  };

  function clean(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  }

  function currentSessionId() {
    const workspace = window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE;
    let fromApi = 0;
    try {
      const snapshot = workspace?.api?.getState?.();
      fromApi = Number(snapshot?.currentSessionId || snapshot?.current?.id || 0);
    } catch (error) {}
    return Math.max(
      0,
      Number(fromApi || workspace?.currentSessionId || window.STONEFELLOW_ARTIST_LISTENING_V172?.currentSessionId || 0)
    );
  }

  async function request(action, payload = {}, method = 'POST') {
    let url = endpoint;
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
    const data = await response.json().catch(() => ({ok:false,error:'HomeServer backup returned an invalid response.'}));
    if (!response.ok || !data.ok) throw new Error(String(data.error || `HomeServer backup failed (${response.status}).`));
    return data;
  }

  function controls() {
    return {
      button: document.querySelector('[data-listening-workspace-homeserver-knowledge]'),
      status: document.querySelector('[data-listening-workspace-homeserver-status]'),
      cloud: document.querySelector('[data-listening-workspace-knowledge]'),
    };
  }

  function ensureStyles() {
    if (document.getElementById('sf-homeserver-v029-style')) return;
    const style = document.createElement('style');
    style.id = 'sf-homeserver-v029-style';
    style.textContent = `
      .sf-listening-homeserver-status{margin-top:8px;padding:7px 8px;border:1px solid #e4e4e4;border-radius:7px;background:#fafafa;color:#666;font-size:9px;line-height:1.4}
      .sf-listening-homeserver-status[data-state="synced"]{color:#294a32;background:#f7fbf7}
      .sf-listening-homeserver-status[data-state="permission_required"],.sf-listening-homeserver-status[data-state="unsupported"]{color:#7a4d16;background:#fffaf2}
      .sf-listening-homeserver-status strong{color:inherit}
    `;
    document.head.appendChild(style);
  }

  function ensureControls() {
    const cloud = document.querySelector('[data-listening-workspace-knowledge]');
    const actions = cloud?.closest('.sf-listening-workspace-inspector-actions');
    if (!cloud || !actions) return false;
    ensureStyles();
    if (!actions.querySelector('[data-listening-workspace-homeserver-knowledge]')) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'sf-listening-workspace-btn';
      button.dataset.listeningWorkspaceHomeserverKnowledge = '1';
      button.textContent = 'Save to HomeServer Knowledge';
      cloud.insertAdjacentElement('afterend', button);
    }
    const section = actions.closest('.sf-listening-workspace-inspector-section');
    if (section && !section.querySelector('[data-listening-workspace-homeserver-status]')) {
      const status = document.createElement('div');
      status.className = 'sf-listening-homeserver-status';
      status.dataset.listeningWorkspaceHomeserverStatus = '1';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      status.textContent = 'HomeServer backup status will appear here.';
      section.appendChild(status);
    }
    return true;
  }

  function percent(backup) {
    const total = Math.max(0, Number(backup?.bytes_total || 0));
    const done = Math.max(0, Number(backup?.bytes_synced || 0));
    return total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 100;
  }

  function isComplete(backup) {
    if (!backup?.text_synced) return false;
    return Number(backup.recording_synced || 0) >= Number(backup.recording_total || 0);
  }

  function needsSync(backup) {
    if (!backup?.text_synced) return false;
    if (['unsupported','permission_required'].includes(String(backup.state || ''))) return false;
    return !isComplete(backup) || ['pending','syncing'].includes(String(backup.state || ''));
  }

  function render() {
    if (!ensureControls()) return;
    const {button, status} = controls();
    const sessionId = currentSessionId();
    button.disabled = sessionId < 1 || state.syncing;
    const backup = state.backup || {};
    const homeserver = state.homeserver || {};
    const backupState = String(backup.state || 'idle');

    if (backupState === 'permission_required') {
      button.disabled = sessionId < 1;
      button.textContent = state.approvalCode ? 'Check HomeServer Permission' : 'Enable HomeServer Knowledge';
    } else if (state.syncing) {
      button.textContent = 'Syncing to HomeServer…';
    } else if (isComplete(backup) && backupState === 'synced') {
      button.textContent = 'Backed up to HomeServer';
    } else if (backupState === 'pending' || backupState === 'syncing') {
      button.textContent = 'Retry HomeServer Backup';
    } else {
      button.textContent = 'Save to HomeServer Knowledge';
    }

    let message = 'Save the full transcription and retained recordings to your private HomeServer Knowledge Base.';
    if (!homeserver.paired && sessionId > 0) message = 'Pair HomeServer to enable private Knowledge backup.';
    else if (homeserver.paired && !homeserver.connected) message = 'HomeServer is offline. Any existing backup remains pending.';
    else if (homeserver.connected && !homeserver.supported) message = 'Update HomeServer to enable transcription and recording backup.';
    else if (backupState === 'permission_required') {
      message = state.approvalCode
        ? `Approve code ${state.approvalCode} in HomeServer, then click Check HomeServer Permission.`
        : (backup.last_error || 'HomeServer needs Knowledge write permission.');
    } else if (backupState === 'unsupported') message = backup.last_error || 'Update HomeServer to resume backup.';
    else if (backupState === 'synced' && isComplete(backup)) {
      message = `Backed up · ${Number(backup.recording_synced || 0)}/${Number(backup.recording_total || 0)} recordings`;
    } else if (backup.text_synced) {
      message = `Transcript backed up · recordings ${Number(backup.recording_synced || 0)}/${Number(backup.recording_total || 0)} · ${percent(backup)}% transferred`;
    } else if (backup.last_error) message = backup.last_error;

    status.dataset.state = backupState;
    status.innerHTML = `<strong>HomeServer</strong> · ${String(message).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]))}`;
  }

  function apply(data) {
    if (data?.backup && typeof data.backup === 'object') state.backup = data.backup;
    if (data?.homeserver && typeof data.homeserver === 'object') state.homeserver = data.homeserver;
    render();
    return data;
  }

  async function refreshStatus(sessionId = currentSessionId()) {
    sessionId = Math.max(0, Number(sessionId || 0));
    if (!sessionId) {
      state.sessionId = 0;
      state.backup = null;
      state.homeserver = null;
      render();
      return null;
    }
    state.sessionId = sessionId;
    try {
      const data = await request('status', {session_id:sessionId}, 'GET');
      apply(data);
      if (needsSync(state.backup) && state.homeserver?.connected) schedulePump(100);
      return data;
    } catch (error) {
      proof.lastError = String(error?.message || error);
      render();
      return null;
    }
  }

  async function pump() {
    const sessionId = currentSessionId();
    if (!sessionId || state.syncing || !navigator.onLine) return;
    if (!state.homeserver?.connected || !state.homeserver?.supported) return;
    if (!needsSync(state.backup)) return;
    state.syncing = true;
    render();
    try {
      const data = await request('sync', {session_id:sessionId});
      proof.syncs += 1;
      apply(data);
    } catch (error) {
      proof.lastError = String(error?.message || error);
    } finally {
      state.syncing = false;
      render();
    }
    if (needsSync(state.backup) && state.homeserver?.connected) schedulePump(650);
  }

  function schedulePump(delay = 300) {
    window.clearTimeout(state.pollTimer);
    state.pollTimer = window.setTimeout(() => void pump(), Math.max(100, delay));
  }

  async function saveDirect() {
    const sessionId = currentSessionId();
    if (!sessionId) return;
    if (String(state.backup?.state || '') === 'permission_required') {
      await permissionAction();
      return;
    }
    state.syncing = true;
    render();
    try {
      const data = await request('save_direct', {session_id:sessionId});
      proof.saves += 1;
      apply(data);
      if (String(state.backup?.state || '') === 'permission_required') return;
      if (needsSync(state.backup) && state.homeserver?.connected) schedulePump(150);
    } catch (error) {
      proof.lastError = String(error?.message || error);
      window.alert(proof.lastError);
    } finally {
      state.syncing = false;
      render();
    }
  }

  async function mirrorCloud(sessionId, attempts = 10) {
    sessionId = Math.max(0, Number(sessionId || 0));
    if (!sessionId) return;
    window.clearTimeout(state.mirrorTimer);
    try {
      const data = await request('save_cloud', {session_id:sessionId});
      proof.cloudMirrors += 1;
      apply(data);
      if (needsSync(state.backup) && state.homeserver?.connected) schedulePump(150);
    } catch (error) {
      const message = String(error?.message || error);
      if (attempts > 0 && /cloud Knowledge Base first|cloud Knowledge item/i.test(message)) {
        state.mirrorTimer = window.setTimeout(() => void mirrorCloud(sessionId, attempts - 1), 750);
        return;
      }
      proof.lastError = message;
      await refreshStatus(sessionId);
    }
  }

  async function permissionAction() {
    try {
      if (state.approvalCode) {
        const checked = await request('check_permission', {});
        if (!checked?.permission_upgrade?.ready) {
          render();
          return;
        }
        state.approvalCode = '';
        await refreshStatus(currentSessionId());
        await saveDirect();
        return;
      }
      const data = await request('upgrade_permission', {});
      state.approvalCode = clean(data?.permission_upgrade?.approval_code || '');
      proof.permissionUpgrades += 1;
      render();
    } catch (error) {
      proof.lastError = String(error?.message || error);
      window.alert(proof.lastError);
    }
  }

  function bind() {
    if (!ensureControls()) return;
    const {button, cloud} = controls();
    if (!button.dataset.homeserverBound) {
      button.dataset.homeserverBound = '1';
      button.addEventListener('click', () => void saveDirect());
    }
    if (cloud && !cloud.dataset.homeserverMirrorBound) {
      cloud.dataset.homeserverMirrorBound = '1';
      cloud.addEventListener('click', () => {
        const sessionId = currentSessionId();
        if (sessionId) state.mirrorTimer = window.setTimeout(() => void mirrorCloud(sessionId), 500);
      });
    }
  }

  const observer = new MutationObserver(() => {
    bind();
    const sessionId = currentSessionId();
    if (sessionId !== state.sessionId) void refreshStatus(sessionId);
  });
  observer.observe(document.documentElement, {subtree:true, childList:true});

  bind();
  void refreshStatus();
  window.setInterval(() => {
    bind();
    const sessionId = currentSessionId();
    if (sessionId !== state.sessionId) void refreshStatus(sessionId);
    else if (sessionId && !state.syncing) void refreshStatus(sessionId);
  }, 6000);
  window.addEventListener('online', () => {
    void refreshStatus(currentSessionId()).then(() => schedulePump(200));
  });

  proof.refresh = refreshStatus;
  proof.saveDirect = saveDirect;
  proof.sync = pump;
})();
