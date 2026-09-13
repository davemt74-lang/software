(() => {
  'use strict';

  const BUILD = 'shared-folder-picker-v161-20260913';
  const SENTINEL = '__vp3_new_folder__';
  const proof = window.VP3_SHARED_FOLDER_PICKER_V161 = {
    build: BUILD,
    loaded: true,
    creates: 0,
    lastFolderId: 0,
    lastError: '',
  };

  const clean = value => String(value || '').replace(/\s+/g, ' ').trim();

  function config() {
    const explicit = window.VP3_SHARED_FOLDER_PICKER_V161_CONFIG || {};
    const listening = window.STONEFELLOW_ARTIST_LISTENING_V172 || {};
    return {
      endpoint: String(explicit.endpoint || new URL('api/shared-folders-v161.php', location.href).toString()),
      csrf: String(explicit.csrf || listening.csrf || ''),
    };
  }

  function eligible(select) {
    return select instanceof HTMLSelectElement && (
      select.matches('[data-vp3-shared-folder-picker]') ||
      select.matches('[data-listening-workspace-folder-select]') ||
      select.matches('[data-listening-ai-folder]')
    );
  }

  function ensureSentinel(select) {
    if (!eligible(select)) return;
    const existing = [...select.options].find(option => option.value === SENTINEL);
    if (existing) return;
    const option = document.createElement('option');
    option.value = SENTINEL;
    option.textContent = '+ New folder…';
    option.dataset.vp3SharedFolderCreate = '1';
    select.appendChild(option);
    select.dataset.vp3SharedFolderPicker = '1';
  }

  function enhanceAll(root = document) {
    for (const select of root.querySelectorAll?.('select') || []) ensureSentinel(select);
  }

  function appendFolderEverywhere(folder) {
    const id = Math.max(0, Number(folder?.id || 0));
    const name = clean(folder?.folder_name || folder?.name || '');
    if (!id || !name) return;
    for (const select of document.querySelectorAll('select')) {
      if (!eligible(select)) continue;
      const sentinel = [...select.options].find(option => option.value === SENTINEL) || null;
      let option = [...select.options].find(candidate => Number(candidate.value) === id) || null;
      if (!option) {
        option = document.createElement('option');
        option.value = String(id);
        if (sentinel) select.insertBefore(option, sentinel);
        else select.appendChild(option);
      }
      option.textContent = name;
    }
  }

  async function createWithSharedApi(name) {
    const cfg = config();
    if (!cfg.endpoint || !cfg.csrf) throw new Error('Shared folder creation is unavailable on this page.');
    const response = await fetch(cfg.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {Accept:'application/json','Content-Type':'application/json'},
      body: JSON.stringify({csrf_token:cfg.csrf,name}),
    });
    const data = await response.json().catch(() => ({ok:false,error:'Shared folders returned an invalid response.'}));
    if (!response.ok || !data.ok) throw new Error(String(data.error || `Folder creation failed (${response.status}).`));
    return data.folder || null;
  }

  async function createFolderFor(select) {
    const entered = window.prompt('New folder name:', '');
    if (entered === null) return null;
    const name = clean(entered);
    if (!name) throw new Error('Enter a folder name.');
    if (name.length > 80) throw new Error('Folder names are limited to 80 characters.');

    const workspace = window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.api;
    if (select.matches('[data-listening-workspace-folder-select],[data-listening-ai-folder]') && typeof workspace?.createFolder === 'function') {
      const previousFilter = String(workspace.getState?.()?.filter?.folder || 'all');
      const folder = await workspace.createFolder(name);
      if (previousFilter && typeof workspace.filterLibrary === 'function') workspace.filterLibrary({folder:previousFilter});
      return folder || null;
    }
    return createWithSharedApi(name);
  }

  document.addEventListener('change', event => {
    const select = event.target;
    if (!eligible(select) || select.value !== SENTINEL) return;
    event.stopImmediatePropagation();
    const previous = select.dataset.vp3PreviousFolderValue || '0';
    select.disabled = true;
    void createFolderFor(select).then(folder => {
      if (!folder) {
        select.value = previous;
        return;
      }
      appendFolderEverywhere(folder);
      const id = Math.max(0, Number(folder.id || 0));
      if (id) {
        select.value = String(id);
        select.dataset.vp3PreviousFolderValue = String(id);
        proof.creates += 1;
        proof.lastFolderId = id;
        proof.lastError = '';
        select.dispatchEvent(new Event('change', {bubbles:true}));
        window.dispatchEvent(new CustomEvent('vp3:shared-folder-created', {detail:{folder}}));
      }
    }).catch(error => {
      proof.lastError = String(error?.message || error);
      select.value = previous;
      window.alert(proof.lastError);
    }).finally(() => {
      select.disabled = false;
      ensureSentinel(select);
    });
  }, true);

  document.addEventListener('focusin', event => {
    const select = event.target;
    if (!eligible(select)) return;
    ensureSentinel(select);
    if (select.value !== SENTINEL) select.dataset.vp3PreviousFolderValue = select.value || '0';
  });

  const observer = new MutationObserver(records => {
    for (const record of records) {
      for (const node of record.addedNodes) {
        if (!(node instanceof Element)) continue;
        if (node.matches?.('select')) ensureSentinel(node);
        enhanceAll(node);
      }
    }
  });
  observer.observe(document.documentElement, {subtree:true,childList:true});

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => enhanceAll(), {once:true});
  else enhanceAll();
})();
