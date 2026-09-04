(() => {
  'use strict';

  const cfg = window.STONEFELLOW_ARTIST_LISTENING_V172 || {};
  if (!cfg.endpoint) return;

  const endpoint172 = String(cfg.endpoint);
  const proof = window.STONEFELLOW_ARTIST_LISTENING_NAMING_V196 = {
    build: 'artist-listening-naming',
    loaded: true,
    renames: 0,
    lastError: '',
  };

  function cleanName(value) {
    return String(value || '').replace(/\s+/g, ' ').trim().slice(0, 190);
  }

  async function request(action, payload = {}) {
    const response = await fetch(endpoint172, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {Accept:'application/json','Content-Type':'application/json'},
      body: JSON.stringify({action, csrf_token:String(cfg.csrf || ''), ...payload}),
    });
    const data = await response.json().catch(() => ({ok:false,error:'Artist Listening returned an invalid response.'}));
    if (!response.ok || !data.ok) throw new Error(String(data.error || `Request failed (${response.status}).`));
    return data;
  }

  function currentSessionId() {
    return Math.max(0, Number(window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.currentSessionId || 0));
  }

  function sessionIdForRename(button) {
    return Math.max(0, Number(button?.dataset.v196Rename || 0)) || currentSessionId();
  }

  function visibleTitleForSession(sessionId) {
    const row = document.querySelector(`[data-listening-workspace-file="${sessionId}"]`);
    const rowTitle = cleanName(row?.querySelector('.sf-listening-workspace-file-title')?.textContent || '');
    if (rowTitle) return rowTitle;
    if (currentSessionId() === sessionId) {
      return cleanName(document.querySelector('[data-listening-workspace-title]')?.value || '');
    }
    return '';
  }

  function updateVisibleTitle(sessionId, title) {
    if (currentSessionId() === sessionId) {
      const input = document.querySelector('[data-listening-workspace-title]');
      if (input) input.value = title;
    }
    const row = document.querySelector(`[data-listening-workspace-file="${sessionId}"]`);
    const rowTitle = row?.querySelector('.sf-listening-workspace-file-title');
    if (rowTitle) rowTitle.textContent = title;
    const openButton = row?.querySelector('[data-listening-workspace-file-open]');
    if (openButton) openButton.setAttribute('aria-label', `Open ${title}`);
  }

  async function renameTranscript(button) {
    const sessionId = sessionIdForRename(button);
    if (sessionId < 1) return;
    const current = visibleTitleForSession(sessionId) || 'Untitled transcription';
    const entered = window.prompt('Rename this transcription:', current);
    if (entered === null) return;
    const title = cleanName(entered);
    if (!title) {
      window.alert('Enter a name for this transcription.');
      return;
    }

    button.disabled = true;
    try {
      const data = await request('rename', {session_id:sessionId, title});
      updateVisibleTitle(sessionId, cleanName(data?.session?.title || title) || title);
      proof.renames += 1;
    } catch (error) {
      proof.lastError = String(error?.message || error);
      window.alert(proof.lastError);
    } finally {
      button.disabled = false;
    }
  }

  function makeRenameButton(sessionId = 0, compact = false) {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.v196Rename = String(Math.max(0, Number(sessionId || 0)));
    button.className = compact ? 'sf-listening-workspace-file-rename' : 'sf-listening-workspace-btn';
    button.textContent = compact ? '✎' : 'Rename';
    button.title = 'Rename transcription';
    button.setAttribute('aria-label', 'Rename transcription');
    return button;
  }

  function enhanceWorkspace() {
    const workspace = document.querySelector('.sf-transcript-workspace');
    if (!workspace) return;

    const toolbar = workspace.querySelector('.sf-listening-workspace-toolbar');
    if (toolbar && !toolbar.querySelector('[data-v196-rename="0"]')) {
      const save = toolbar.querySelector('[data-listening-workspace-save]');
      const rename = makeRenameButton(0, false);
      if (save?.nextSibling) toolbar.insertBefore(rename, save.nextSibling);
      else toolbar.appendChild(rename);
    }

    for (const row of workspace.querySelectorAll('[data-listening-workspace-file]')) {
      const sessionId = Math.max(0, Number(row.dataset.listeningWorkspaceFile || 0));
      if (!sessionId || row.querySelector('[data-v196-rename]')) continue;
      const remove = row.querySelector('[data-listening-workspace-delete-session]');
      const rename = makeRenameButton(sessionId, true);
      if (remove) row.insertBefore(rename, remove);
      else row.appendChild(rename);
    }
  }

  document.addEventListener('click', event => {
    const rename = event.target.closest?.('[data-v196-rename]');
    if (!rename) return;
    event.preventDefault();
    event.stopPropagation();
    void renameTranscript(rename);
  });

  const observer = new MutationObserver(enhanceWorkspace);
  observer.observe(document.documentElement, {subtree:true, childList:true});
  enhanceWorkspace();
})();
