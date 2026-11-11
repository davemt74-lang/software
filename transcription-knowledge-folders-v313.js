(() => {
  'use strict';

  const BUILD = 'transcription-knowledge-roundtrip-v315-20260917';
  const cfg = window.STONEFELLOW_ARTIST_LISTENING_V172 || {};
  const proof = window.VP3_TRANSCRIPTION_KNOWLEDGE_FOLDERS_V313 = {
    build: BUILD,
    loaded: true,
    saves: 0,
    transcriptSaves: 0,
    highlightSaves: 0,
    statusLoads: 0,
    lastFolderId: 0,
    lastKnowledgeId: 0,
    lastError: '',
  };

  const clean = value => String(value || '').replace(/\s+/g, ' ').trim();
  const intelligenceEndpoint = String(cfg.endpoint || '').replace(/artist-listening-v172\.php(?:\?.*)?$/i, 'artist-listening-intelligence-v300.php');
  const transcriptEndpoint = new URL('api/transcription-knowledge-save-v314.php', location.href).toString();
  let statusSessionId = 0;
  let statusRequestId = 0;
  let highlightSelection = null;

  function workspaceState() {
    try {
      return window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.api?.getState?.() || {};
    } catch (error) {
      return {};
    }
  }

  function folders() {
    const rows = workspaceState().folders;
    return Array.isArray(rows) ? rows : [];
  }

  function currentSessionId() {
    return Math.max(0, Number(
      window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.currentSessionId ||
      workspaceState().sessionId ||
      workspaceState().current?.id ||
      cfg.initialSessionId ||
      0
    ));
  }

  function currentFolderId() {
    const workspaceSelect = document.querySelector('[data-listening-workspace-folder-select]');
    if (workspaceSelect) return Math.max(0, Number(workspaceSelect.value || 0));
    return Math.max(0, Number(workspaceState().current?.folder?.id || 0));
  }

  function currentFolderName(folderId = currentFolderId()) {
    const workspaceSelect = document.querySelector('[data-listening-workspace-folder-select]');
    if (workspaceSelect && Number(workspaceSelect.value || 0) === Number(folderId || 0)) {
      return clean(workspaceSelect.selectedOptions?.[0]?.textContent || 'Unfiled');
    }
    const folder = folders().find(row => Number(row?.id || 0) === Number(folderId || 0));
    return clean(folder?.folder_name || folder?.name || (folderId > 0 ? `Folder ${folderId}` : 'Unfiled'));
  }

  function knowledgeViewUrl(knowledgeId, folderId = 0) {
    const id = Math.max(0, Number(knowledgeId || 0));
    if (!id) return '';
    const url = new URL('knowledge.php', location.href);
    url.searchParams.set('folder', Number(folderId || 0) > 0 ? String(Number(folderId)) : 'unfiled');
    url.searchParams.set('edit', String(id));
    url.hash = 'knowledge-form';
    return url.toString();
  }

  function status(message, error = false, viewUrl = '') {
    const node = document.querySelector('[data-listening-ai-status]');
    if (!node) return;
    if (node.textContent !== message) node.textContent = message;
    const nextState = error ? 'error' : 'saved';
    if (node.dataset.state !== nextState) node.dataset.state = nextState;

    let link = document.querySelector('[data-listening-ai-knowledge-view]');
    if (!link) {
      link = document.createElement('a');
      link.dataset.listeningAiKnowledgeView = '1';
      link.className = 'sf-listening-ai-knowledge-view';
      link.textContent = 'View in My Knowledge';
      link.target = '_blank';
      link.rel = 'noopener';
      link.hidden = true;
      node.insertAdjacentElement('afterend', link);
    }
    if (viewUrl) {
      if (link.href !== viewUrl) link.href = viewUrl;
      link.hidden = false;
    } else {
      link.hidden = true;
      link.removeAttribute('href');
    }
  }

  function workspaceStatus(message, state = 'idle', viewUrl = '', highlight = false) {
    const selector = highlight ? '[data-transcription-knowledge-highlight-status]' : '[data-transcription-knowledge-status]';
    let node = document.querySelector(selector);
    if (!node) {
      const button = document.querySelector('[data-listening-workspace-knowledge]');
      const actions = button?.closest('.sf-listening-workspace-inspector-actions');
      if (!actions) return;
      node = document.createElement('div');
      node.className = 'sf-transcription-knowledge-status';
      node.setAttribute(selector.slice(1, -1), '1');
      node.setAttribute('aria-live', 'polite');
      node.innerHTML = '<span data-transcription-knowledge-message></span><a data-transcription-knowledge-view target="_blank" rel="noopener" hidden>View in My Knowledge</a>';
      actions.insertAdjacentElement('afterend', node);
    }

    const messageNode = node.querySelector('[data-transcription-knowledge-message]');
    const link = node.querySelector('[data-transcription-knowledge-view]');
    if (messageNode && messageNode.textContent !== message) messageNode.textContent = message;
    if (state === 'idle' || state === 'saving' || state === 'loading') {
      if (node.dataset.state) delete node.dataset.state;
    } else if (node.dataset.state !== state) {
      node.dataset.state = state;
    }
    if (link) {
      if (viewUrl) {
        if (link.href !== viewUrl) link.href = viewUrl;
        link.hidden = false;
      } else {
        link.hidden = true;
        link.removeAttribute('href');
      }
    }
  }

  function renderOptions(select, force = false) {
    if (!select) return;
    const selected = select.dataset.userSelected === '1'
      ? Math.max(0, Number(select.value || 0))
      : currentFolderId();
    const rows = folders();
    const signature = JSON.stringify([
      selected,
      rows.map(folder => [Math.max(0, Number(folder?.id || 0)), clean(folder?.folder_name || folder?.name || '')]),
    ]);
    if (!force && select.dataset.folderSignature === signature && select.options.length) return;

    const fragment = document.createDocumentFragment();
    const unfiled = document.createElement('option');
    unfiled.value = '0';
    unfiled.textContent = 'Unfiled';
    fragment.appendChild(unfiled);
    rows.forEach(folder => {
      const id = Math.max(0, Number(folder?.id || 0));
      if (!id) return;
      const option = document.createElement('option');
      option.value = String(id);
      option.textContent = clean(folder?.folder_name || folder?.name || `Folder ${id}`);
      fragment.appendChild(option);
    });
    select.replaceChildren(fragment);
    select.dataset.folderSignature = signature;
    if ([...select.options].some(option => Number(option.value) === selected)) select.value = String(selected);
    else select.value = '0';
  }

  function removeAgentBrainAction() {
    document.querySelectorAll('[data-listening-ai-brain]').forEach(button => button.remove());
  }

  async function loadTranscriptKnowledgeState(sessionId = currentSessionId()) {
    sessionId = Math.max(0, Number(sessionId || 0));
    if (!sessionId) {
      workspaceStatus('Open a transcription to view its My Knowledge status.', 'idle');
      return;
    }

    const requestId = ++statusRequestId;
    workspaceStatus('Checking My Knowledge…', 'loading');
    try {
      const url = new URL(transcriptEndpoint);
      url.searchParams.set('session_id', String(sessionId));
      const response = await fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {Accept:'application/json'},
        cache: 'no-store',
      });
      const data = await response.json().catch(() => ({ok:false,error:'Transcription Knowledge status returned an invalid response.'}));
      if (!response.ok || !data.ok) throw new Error(String(data.error || `Status failed (${response.status}).`));
      if (requestId !== statusRequestId || currentSessionId() !== sessionId) return;

      proof.statusLoads += 1;
      const saved = Boolean(data.state?.saved);
      const knowledgeId = Math.max(0, Number(data.state?.knowledge_id || 0));
      const folderId = Math.max(0, Number(data.state?.folder?.id || 0));
      const folderName = clean(data.state?.folder?.name || (folderId > 0 ? `Folder ${folderId}` : 'Unfiled'));
      proof.lastKnowledgeId = knowledgeId;
      proof.lastFolderId = folderId;
      proof.lastError = '';

      if (saved && knowledgeId) {
        workspaceStatus(
          `Saved to My Knowledge · ${folderName}`,
          'saved',
          String(data.state?.view_url || knowledgeViewUrl(knowledgeId, folderId))
        );
      } else {
        workspaceStatus('Not saved to My Knowledge yet.', 'idle');
      }
    } catch (error) {
      if (requestId !== statusRequestId || currentSessionId() !== sessionId) return;
      proof.lastError = String(error?.message || error);
      workspaceStatus(`My Knowledge status unavailable · ${proof.lastError}`, 'error');
    }
  }

  function ensureWorkspaceControl() {
    const button = document.querySelector('[data-listening-workspace-knowledge]');
    if (!button) return false;
    if (button.textContent !== 'Save to Knowledge Base') button.textContent = 'Save to Knowledge Base';
    if (button.title !== 'Save this transcription to My Knowledge in its selected folder') {
      button.title = 'Save this transcription to My Knowledge in its selected folder';
    }
    if (cfg.canSavePersonalKnowledge && !button.parentElement.querySelector('[data-listening-workspace-knowledge-highlight]')) {
      const highlight = document.createElement('button');
      highlight.type = 'button';
      highlight.className = 'sf-listening-workspace-btn';
      highlight.dataset.listeningWorkspaceKnowledgeHighlight = '1';
      highlight.textContent = 'Save highlight to My Knowledge';
      highlight.title = 'Save selected transcript text to the current Knowledge folder';
      button.insertAdjacentElement('afterend', highlight);
    }
    if (!document.querySelector('[data-transcription-knowledge-status]')) {
      workspaceStatus('Checking My Knowledge…', 'loading');
    }
    const sessionId = currentSessionId();
    if (sessionId > 0 && statusSessionId !== sessionId) {
      statusSessionId = sessionId;
      void loadTranscriptKnowledgeState(sessionId);
    }
    return true;
  }

  function ensureControl() {
    removeAgentBrainAction();
    ensureWorkspaceControl();

    const button = document.querySelector('[data-listening-ai-knowledge]');
    const actions = button?.parentElement;
    if (!button || !actions) return false;

    if (button.textContent !== 'Save to folder') button.textContent = 'Save to folder';
    if (button.title !== 'Save this AI summary to My Knowledge') {
      button.title = 'Save this AI summary to My Knowledge';
    }

    let wrap = actions.querySelector('[data-listening-ai-folder-wrap]');
    if (!wrap) {
      wrap = document.createElement('label');
      wrap.className = 'sf-listening-ai-folder-save';
      wrap.dataset.listeningAiFolderWrap = '1';
      wrap.innerHTML = '<span>Knowledge folder</span><select data-listening-ai-folder aria-label="Knowledge folder"></select>';
      actions.insertBefore(wrap, button);
      const select = wrap.querySelector('[data-listening-ai-folder]');
      select?.addEventListener('change', () => {
        select.dataset.userSelected = '1';
        delete select.dataset.folderSignature;
      });
    }
    renderOptions(wrap.querySelector('[data-listening-ai-folder]'));
    return true;
  }

  async function saveTranscriptToKnowledge(button) {
    const sessionId = currentSessionId();
    if (!sessionId) throw new Error('Choose a transcription before saving it to My Knowledge.');
    const folderId = currentFolderId();
    const folderName = currentFolderName(folderId);

    button.disabled = true;
    workspaceStatus(`Saving to My Knowledge · ${folderName}…`, 'saving');
    try {
      const response = await fetch(transcriptEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {Accept:'application/json','Content-Type':'application/json'},
        body: JSON.stringify({
          csrf_token: String(cfg.csrf || ''),
          session_id: sessionId,
          folder_id: folderId,
        }),
      });
      const data = await response.json().catch(() => ({ok:false,error:'Transcription Knowledge save returned an invalid response.'}));
      if (!response.ok || !data.ok) throw new Error(String(data.error || `Save failed (${response.status}).`));
      proof.transcriptSaves += 1;
      const state = data.state || data;
      const knowledgeId = Math.max(0, Number(state.knowledge_id || data.knowledge_id || 0));
      const savedFolderId = Math.max(0, Number(state.folder?.id ?? data.folder?.id ?? folderId));
      const savedFolder = clean(state.folder?.name || data.folder?.name || folderName);
      const viewUrl = String(state.view_url || data.view_url || knowledgeViewUrl(knowledgeId, savedFolderId));
      proof.lastFolderId = savedFolderId;
      proof.lastKnowledgeId = knowledgeId;
      proof.lastError = '';
      statusSessionId = sessionId;
      workspaceStatus(`Saved to My Knowledge · ${savedFolder}`, 'saved', viewUrl);
      window.dispatchEvent(new CustomEvent('vp3:transcription-knowledge-saved', {detail:{sessionId,folderId:savedFolderId,knowledgeId,source:'transcript'}}));
    } catch (error) {
      proof.lastError = String(error?.message || error);
      workspaceStatus(proof.lastError, 'error');
      throw error;
    } finally {
      button.disabled = false;
    }
  }

  function rememberHighlight(editor) {
    if (!editor?.matches?.('[data-listening-workspace-editor], [data-listening-workspace-turn-text]')) return;
    const start = editor.selectionStart;
    const text = editor.value.slice(start, editor.selectionEnd);
    const sessionId = currentSessionId();
    const transcript = window.STONEFELLOW_ARTIST_LISTENING_TRANSCRIPT?.api?.getState?.();
    const page = transcript?.sessionId === sessionId ? transcript.page : 1;
    const segmentId = Number(editor.closest('[data-listening-workspace-turn]')?.dataset.segmentId || 0);
    highlightSelection = text.trim() ? {sessionId, page, start, text, segment_id:segmentId} : null;
  }

  async function saveHighlightToKnowledge(button) {
    rememberHighlight(document.activeElement);
    const sessionId = currentSessionId();
    const selected = highlightSelection;
    if (!selected || selected.sessionId !== sessionId) {
      workspaceStatus('Select text in the saved transcript first.', 'error', '', true);
      return;
    }
    const {page, start, text, segment_id} = selected;
    const folderId = currentFolderId();
    button.disabled = true;
    workspaceStatus(`Saving highlight to My Knowledge · ${currentFolderName(folderId)}…`, 'saving', '', true);
    try {
      const response = await fetch(transcriptEndpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: {Accept:'application/json','Content-Type':'application/json'},
        body: JSON.stringify({csrf_token:String(cfg.csrf || ''), session_id:sessionId, folder_id:folderId, highlight:{page, start, text, segment_id}}),
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(String(data.error || `Save failed (${response.status}).`));
      const knowledgeId = Number(data.knowledge_id);
      if (data.kind !== 'highlight' || !Number.isSafeInteger(knowledgeId) || knowledgeId < 1 || data.folder?.id !== folderId) {
        throw new Error('The saved highlight could not be verified.');
      }
      proof.highlightSaves += 1;
      if (currentSessionId() === sessionId) {
        workspaceStatus(`Highlight saved to My Knowledge · ${data.folder.name}`, 'saved', data.view_url, true);
      }
      window.dispatchEvent(new CustomEvent('vp3:transcription-knowledge-saved', {detail:{sessionId, folderId, knowledgeId, source:'highlight'}}));
    } catch (error) {
      if (currentSessionId() === sessionId) workspaceStatus(String(error.message || error), 'error', '', true);
    } finally {
      button.disabled = false;
    }
  }

  async function saveToFolder(button) {
    const sessionId = currentSessionId();
    if (!sessionId) throw new Error('Choose a transcription before saving its AI summary.');
    if (!intelligenceEndpoint) throw new Error('Transcription intelligence endpoint is unavailable.');
    const select = document.querySelector('[data-listening-ai-folder]');
    const folderId = Math.max(0, Number(select?.value || 0));
    const folderName = clean(select?.selectedOptions?.[0]?.textContent || 'Unfiled');

    button.disabled = true;
    status(`Saving AI summary to ${folderName}…`);
    try {
      const response = await fetch(intelligenceEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {Accept:'application/json','Content-Type':'application/json'},
        body: JSON.stringify({
          action: 'save_knowledge',
          csrf_token: String(cfg.csrf || ''),
          session_id: sessionId,
          folder_id: folderId,
        }),
      });
      const data = await response.json().catch(() => ({ok:false,error:'Transcription intelligence returned an invalid response.'}));
      if (!response.ok || !data.ok) throw new Error(String(data.error || `Save failed (${response.status}).`));
      proof.saves += 1;
      proof.lastFolderId = folderId;
      proof.lastKnowledgeId = Math.max(0, Number(data.knowledge_id || 0));
      proof.lastError = '';
      if (window.STONEFELLOW_ARTIST_LISTENING_AI) {
        window.STONEFELLOW_ARTIST_LISTENING_AI.knowledgeSaves = Math.max(0, Number(window.STONEFELLOW_ARTIST_LISTENING_AI.knowledgeSaves || 0)) + 1;
      }
      status(
        `Saved to My Knowledge · ${clean(data.folder?.name || folderName)}`,
        false,
        String(data.view_url || knowledgeViewUrl(data.knowledge_id, folderId))
      );
      window.dispatchEvent(new CustomEvent('vp3:transcription-knowledge-saved', {detail:{sessionId,folderId,knowledgeId:Number(data.knowledge_id || 0),source:'ai_summary'}}));
    } catch (error) {
      proof.lastError = String(error?.message || error);
      status(proof.lastError, true);
      throw error;
    } finally {
      button.disabled = false;
    }
  }

  document.addEventListener('focusout', event => rememberHighlight(event.target), true);
  document.addEventListener('click', event => {
    const highlightButton = event.target.closest?.('[data-listening-workspace-knowledge-highlight]');
    if (highlightButton) {
      event.preventDefault();
      void saveHighlightToKnowledge(highlightButton);
      return;
    }
    const transcriptButton = event.target.closest?.('[data-listening-workspace-knowledge]');
    if (transcriptButton) {
      event.preventDefault();
      event.stopImmediatePropagation();
      void saveTranscriptToKnowledge(transcriptButton).catch(() => {});
      return;
    }
    const summaryButton = event.target.closest?.('[data-listening-ai-knowledge]');
    if (!summaryButton) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    void saveToFolder(summaryButton).catch(() => {});
  }, true);

  window.addEventListener('stonefellow:artist-listening-document-selected', () => {
    highlightSelection = null;
    document.querySelector('[data-transcription-knowledge-highlight-status]')?.remove();
    statusRequestId += 1;
    statusSessionId = 0;
    workspaceStatus('Checking My Knowledge…', 'loading');
    const select = document.querySelector('[data-listening-ai-folder]');
    if (select) {
      select.dataset.userSelected = '0';
      delete select.dataset.folderSignature;
      renderOptions(select, true);
    }
    setTimeout(() => ensureWorkspaceControl(), 0);
  });
  window.addEventListener('stonefellow:artist-listening-metadata-saved', () => {
    const select = document.querySelector('[data-listening-ai-folder]');
    if (select && select.dataset.userSelected !== '1') {
      delete select.dataset.folderSignature;
      renderOptions(select, true);
    }
  });
  window.addEventListener('pageshow', () => {
    statusSessionId = 0;
    setTimeout(() => ensureWorkspaceControl(), 0);
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    statusSessionId = 0;
    setTimeout(() => ensureWorkspaceControl(), 0);
  });

  const style = document.createElement('style');
  style.textContent = '.sf-listening-ai-folder-save{display:grid;gap:2px;flex:1 1 130px;min-width:120px}.sf-listening-ai-folder-save span{font-size:7px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#777}.sf-listening-ai-folder-save select{width:100%;min-height:34px;padding:6px 8px;border:1px solid #ddd;border-radius:7px;background:#fff;color:#222;font:700 9px/1.2 system-ui}.sf-listening-ai-footer-actions{flex-wrap:wrap!important}.sf-listening-ai-footer-actions [data-listening-ai-knowledge]{flex:0 0 auto!important;min-width:94px!important}[data-listening-ai-brain]{display:none!important}.sf-listening-ai-knowledge-view{display:inline-flex;margin-top:6px;color:#333;font:800 8px/1.2 system-ui;text-decoration:underline}.sf-listening-ai-knowledge-view[hidden]{display:none!important}.sf-transcription-knowledge-status{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:8px;padding:7px 8px;border:1px solid #ddd;border-radius:7px;background:#f7f7f7;color:#555;font:700 9px/1.35 system-ui}.sf-transcription-knowledge-status a{color:inherit;font-weight:850;text-decoration:underline;white-space:nowrap}.sf-transcription-knowledge-status a[hidden]{display:none!important}.sf-transcription-knowledge-status[data-state="saved"]{border-color:#b8d7c1;background:#f3faf5;color:#275c35}.sf-transcription-knowledge-status[data-state="error"]{border-color:#e2bcbc;background:#fff6f6;color:#8b3030}';
  document.head.appendChild(style);

  const observer = new MutationObserver(() => ensureControl());
  observer.observe(document.documentElement, {subtree:true,childList:true});
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ensureControl, {once:true});
  else ensureControl();
})();
