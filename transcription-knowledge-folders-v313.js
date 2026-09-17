(() => {
  'use strict';

  const BUILD = 'transcription-knowledge-folders-v314-personal-save-20260917';
  const cfg = window.STONEFELLOW_ARTIST_LISTENING_V172 || {};
  const proof = window.VP3_TRANSCRIPTION_KNOWLEDGE_FOLDERS_V313 = {
    build: BUILD,
    loaded: true,
    saves: 0,
    transcriptSaves: 0,
    lastFolderId: 0,
    lastError: '',
  };

  const clean = value => String(value || '').replace(/\s+/g, ' ').trim();
  const intelligenceEndpoint = String(cfg.endpoint || '').replace(/artist-listening-v172\.php(?:\?.*)?$/i, 'artist-listening-intelligence-v300.php');
  const transcriptEndpoint = new URL('api/transcription-knowledge-save-v314.php', location.href).toString();

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

  function status(message, error = false) {
    const node = document.querySelector('[data-listening-ai-status]');
    if (!node) return;
    if (node.textContent !== message) node.textContent = message;
    const nextState = error ? 'error' : 'saved';
    if (node.dataset.state !== nextState) node.dataset.state = nextState;
  }

  function workspaceStatus(message, error = false) {
    let node = document.querySelector('[data-transcription-knowledge-status]');
    if (!node) {
      const button = document.querySelector('[data-listening-workspace-knowledge]');
      const actions = button?.closest('.sf-listening-workspace-inspector-actions');
      if (!actions) return;
      node = document.createElement('div');
      node.className = 'sf-transcription-knowledge-status';
      node.dataset.transcriptionKnowledgeStatus = '1';
      actions.insertAdjacentElement('afterend', node);
    }
    if (node.textContent !== message) node.textContent = message;
    const nextState = error ? 'error' : 'saved';
    if (node.dataset.state !== nextState) node.dataset.state = nextState;
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

  function ensureWorkspaceControl() {
    const button = document.querySelector('[data-listening-workspace-knowledge]');
    if (!button) return false;
    if (button.textContent !== 'Save to Knowledge Base') button.textContent = 'Save to Knowledge Base';
    if (button.title !== 'Save this transcription to My Knowledge in its selected folder') {
      button.title = 'Save this transcription to My Knowledge in its selected folder';
    }
    workspaceStatus(document.querySelector('[data-transcription-knowledge-status]')?.textContent || 'Not saved to My Knowledge yet.');
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
    workspaceStatus(`Saving to My Knowledge · ${folderName}…`);
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
      proof.lastFolderId = folderId;
      proof.lastError = '';
      const savedFolder = clean(data.folder?.name || folderName);
      workspaceStatus(`Saved to My Knowledge · ${savedFolder}`);
      window.dispatchEvent(new CustomEvent('vp3:transcription-knowledge-saved', {detail:{sessionId,folderId,knowledgeId:Number(data.knowledge_id || 0),source:'transcript'}}));
    } catch (error) {
      proof.lastError = String(error?.message || error);
      workspaceStatus(proof.lastError, true);
      throw error;
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
      proof.lastError = '';
      if (window.STONEFELLOW_ARTIST_LISTENING_AI) {
        window.STONEFELLOW_ARTIST_LISTENING_AI.knowledgeSaves = Math.max(0, Number(window.STONEFELLOW_ARTIST_LISTENING_AI.knowledgeSaves || 0)) + 1;
      }
      status(`Saved to My Knowledge · ${clean(data.folder?.name || folderName)}`);
      window.dispatchEvent(new CustomEvent('vp3:transcription-knowledge-saved', {detail:{sessionId,folderId,knowledgeId:Number(data.knowledge_id || 0),source:'ai_summary'}}));
    } catch (error) {
      proof.lastError = String(error?.message || error);
      status(proof.lastError, true);
      throw error;
    } finally {
      button.disabled = false;
    }
  }

  document.addEventListener('click', event => {
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
    workspaceStatus('Not saved to My Knowledge yet.');
    const select = document.querySelector('[data-listening-ai-folder]');
    if (select) {
      select.dataset.userSelected = '0';
      delete select.dataset.folderSignature;
      renderOptions(select, true);
    }
  });
  window.addEventListener('stonefellow:artist-listening-metadata-saved', () => {
    const select = document.querySelector('[data-listening-ai-folder]');
    if (select && select.dataset.userSelected !== '1') {
      delete select.dataset.folderSignature;
      renderOptions(select, true);
    }
  });

  const style = document.createElement('style');
  style.textContent = '.sf-listening-ai-folder-save{display:grid;gap:2px;flex:1 1 130px;min-width:120px}.sf-listening-ai-folder-save span{font-size:7px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#777}.sf-listening-ai-folder-save select{width:100%;min-height:34px;padding:6px 8px;border:1px solid #ddd;border-radius:7px;background:#fff;color:#222;font:700 9px/1.2 system-ui}.sf-listening-ai-footer-actions{flex-wrap:wrap!important}.sf-listening-ai-footer-actions [data-listening-ai-knowledge]{flex:0 0 auto!important;min-width:94px!important}[data-listening-ai-brain]{display:none!important}.sf-transcription-knowledge-status{margin-top:8px;padding:7px 8px;border:1px solid #ddd;border-radius:7px;background:#f7f7f7;color:#555;font:700 9px/1.35 system-ui}.sf-transcription-knowledge-status[data-state="saved"]{border-color:#b8d7c1;background:#f3faf5;color:#275c35}.sf-transcription-knowledge-status[data-state="error"]{border-color:#e2bcbc;background:#fff6f6;color:#8b3030}';
  document.head.appendChild(style);

  const observer = new MutationObserver(() => ensureControl());
  observer.observe(document.documentElement, {subtree:true,childList:true});
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ensureControl, {once:true});
  else ensureControl();
})();