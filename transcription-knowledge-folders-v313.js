(() => {
  'use strict';

  const BUILD = 'transcription-knowledge-folders-v313-hotfix-20260913';
  const cfg = window.STONEFELLOW_ARTIST_LISTENING_V172 || {};
  const proof = window.VP3_TRANSCRIPTION_KNOWLEDGE_FOLDERS_V313 = {
    build: BUILD,
    loaded: true,
    saves: 0,
    lastFolderId: 0,
    lastError: '',
  };

  const clean = value => String(value || '').replace(/\s+/g, ' ').trim();
  const endpoint = String(cfg.endpoint || '').replace(/artist-listening-v172\.php(?:\?.*)?$/i, 'artist-listening-intelligence-v300.php');

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

  function currentFolderId() {
    return Math.max(0, Number(workspaceState().current?.folder?.id || 0));
  }

  function status(message, error = false) {
    const node = document.querySelector('[data-listening-ai-status]');
    if (!node) return;
    node.textContent = message;
    node.dataset.state = error ? 'error' : 'saved';
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

  function ensureControl() {
    const button = document.querySelector('[data-listening-ai-knowledge]');
    const actions = button?.parentElement;
    if (!button || !actions) return false;

    // This function is invoked by a document-wide childList MutationObserver.
    // Replacing button text on every observer pass creates another childList
    // mutation and can keep the page in a self-sustaining observer loop.
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

  async function saveToFolder(button) {
    const sessionId = Math.max(0, Number(
      window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.currentSessionId ||
      workspaceState().sessionId ||
      cfg.initialSessionId ||
      0
    ));
    if (!sessionId) throw new Error('Choose a transcription before saving its AI summary.');
    if (!endpoint) throw new Error('Transcription intelligence endpoint is unavailable.');
    const select = document.querySelector('[data-listening-ai-folder]');
    const folderId = Math.max(0, Number(select?.value || 0));
    const folderName = clean(select?.selectedOptions?.[0]?.textContent || 'Unfiled');

    button.disabled = true;
    status(`Saving AI summary to ${folderName}…`);
    try {
      const response = await fetch(endpoint, {
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
      window.dispatchEvent(new CustomEvent('vp3:transcription-knowledge-saved', {detail:{sessionId,folderId,knowledgeId:Number(data.knowledge_id || 0)}}));
    } catch (error) {
      proof.lastError = String(error?.message || error);
      status(proof.lastError, true);
      throw error;
    } finally {
      button.disabled = false;
    }
  }

  document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-listening-ai-knowledge]');
    if (!button) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    void saveToFolder(button).catch(() => {});
  }, true);

  window.addEventListener('stonefellow:artist-listening-document-selected', () => {
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
  style.textContent = '.sf-listening-ai-folder-save{display:grid;gap:2px;flex:1 1 130px;min-width:120px}.sf-listening-ai-folder-save span{font-size:7px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#777}.sf-listening-ai-folder-save select{width:100%;min-height:34px;padding:6px 8px;border:1px solid #ddd;border-radius:7px;background:#fff;color:#222;font:700 9px/1.2 system-ui}.sf-listening-ai-footer-actions{flex-wrap:wrap!important}.sf-listening-ai-footer-actions [data-listening-ai-knowledge]{flex:0 0 auto!important;min-width:94px!important}';
  document.head.appendChild(style);

  const observer = new MutationObserver(() => ensureControl());
  observer.observe(document.documentElement, {subtree:true,childList:true});
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ensureControl, {once:true});
  else ensureControl();
})();