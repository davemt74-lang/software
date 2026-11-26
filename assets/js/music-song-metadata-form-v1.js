(() => {
  'use strict';
  const states = new WeakMap();
  const groups = ['writers', 'publishers', 'performers'];
  const field = (root, name) => root.querySelector(`[name="${name}"]`);
  const scalar = value => typeof value === 'string' || typeof value === 'number' ? String(value) : '';

  function mirrorSelect(select, disabled) {
    let mirror = select.nextElementSibling;
    if (!mirror?.hasAttribute('data-select-mirror')) mirror = null;
    if (disabled && !mirror) {
      mirror = document.createElement('input');
      mirror.type = 'hidden';
      mirror.setAttribute('data-select-mirror', '');
      select.after(mirror);
    }
    if (mirror) {
      mirror.name = select.name;
      mirror.value = select.value;
      mirror.disabled = !disabled;
    }
    select.disabled = disabled;
  }

  function contributorLock(row, workLocked = false) {
    const select = row.querySelector('[data-party-select]');
    const linked = !!select?.value;
    row.querySelectorAll('[data-party-field]').forEach(input => {
      input.readOnly = workLocked || (linked && input.dataset.partyField !== 'role');
    });
    if (select) mirrorSelect(select, workLocked);
    const note = row.querySelector('[data-party-reuse-note]');
    if (note) note.hidden = !linked;
    const remove = row.querySelector('[data-party-remove]');
    if (remove) remove.disabled = workLocked;
  }

  function workLock(root) {
    const locked = !!root.querySelector('[data-work-select]')?.value;
    root.querySelectorAll('[data-work-field]').forEach(input => { input.readOnly = locked; });
    const status = root.querySelector('[data-work-status]');
    if (status) mirrorSelect(status, locked);
    const note = root.querySelector('[data-work-reuse-note]');
    if (note) note.hidden = !locked;
    ['writers', 'publishers'].forEach(group => {
      root.querySelectorAll(`[data-party-list="${group}"] [data-party-row]`).forEach(row => contributorLock(row, locked));
      const add = root.querySelector(`[data-party-add="${group}"]`);
      if (add) add.disabled = locked;
    });
    root.querySelectorAll('[data-party-list="performers"] [data-party-row]').forEach(row => contributorLock(row));
  }

  function updatePreview(root) {
    const list = root.querySelector('[data-credit-preview]');
    if (!list) return;
    list.replaceChildren();
    groups.forEach(group => {
      root.querySelectorAll(`[data-party-list="${group}"] [data-party-row]`).forEach(row => {
        const name = row.querySelector('[data-party-field="name"]')?.value.trim();
        if (!name) return;
        const role = row.querySelector('[data-party-field="role"]')?.value.trim();
        const item = document.createElement('li');
        item.textContent = `${name} — ${role || ({writers: 'Writer', publishers: 'Publisher', performers: 'Performer / production credit'})[group]}`;
        list.append(item);
      });
    });
    const label = root.querySelector('[data-label-name]')?.value.trim();
    if (label) {
      const item = document.createElement('li');
      item.textContent = `${label} — Record company / label`;
      list.append(item);
    }
    const empty = root.querySelector('[data-credit-empty]');
    if (empty) empty.hidden = list.childElementCount > 0;
  }

  function fillOptions(select, rows, idKey, titleKey, preserve = true) {
    const current = preserve ? select.value : '';
    const oldLabel = select.selectedOptions?.[0]?.textContent || 'Saved selection';
    const placeholder = select.options[0]?.textContent || 'Enter new details';
    select.replaceChildren(new Option(placeholder, ''));
    for (const row of rows) {
      const id = scalar(row?.[idKey]);
      if (id) select.add(new Option(scalar(row?.[titleKey]), id));
    }
    if (current && ![...select.options].some(option => option.value === current)) {
      select.add(new Option(oldLabel, current));
    }
    select.value = current;
  }

  function addContributor(root, group, values = {}, focus = true) {
    if (!groups.includes(group)) return null;
    const template = root.querySelector(`[data-party-template="${group}"]`);
    const list = root.querySelector(`[data-party-list="${group}"]`);
    const state = states.get(root);
    if (!template || !list || !state) return null;
    if (list.children.length >= 50) {
      showStatus(root, 'A song can list up to 50 contributors in each section.', true);
      return null;
    }
    const index = ++state.nextIndex;
    const fragment = template.content.cloneNode(true);
    fragment.querySelectorAll('[name]').forEach(input => {
      input.name = input.name.replace('__INDEX__', String(index));
    });
    const row = fragment.querySelector('[data-party-row]');
    const select = row.querySelector('[data-party-select]');
    fillOptions(select, state.parties, 'party_id', 'name', false);
    const id = scalar(values.party_id);
    if (id && ![...select.options].some(option => option.value === id)) {
      select.add(new Option(scalar(values.name) || 'Saved contributor', id));
    }
    select.value = id;
    row.querySelectorAll('[data-party-field]').forEach(input => {
      input.value = scalar(values[input.dataset.partyField]);
    });
    list.append(fragment);
    workLock(root);
    updatePreview(root);
    if (focus) (id ? row.querySelector('[data-party-field="role"]') : row.querySelector('[data-party-field="name"]'))?.focus();
    return row;
  }

  function readWork(root) {
    const result = {};
    for (const key of ['work_title', 'iswc', 'iswc_status', 'work_revision']) result[key] = field(root, `music_metadata[${key}]`)?.value || '';
    for (const group of ['writers', 'publishers']) {
      result[group] = [...root.querySelectorAll(`[data-party-list="${group}"] [data-party-row]`)].map(row => {
        const item = {party_id: row.querySelector('[data-party-select]')?.value || ''};
        row.querySelectorAll('[data-party-field]').forEach(input => { item[input.dataset.partyField] = input.value; });
        return item;
      });
    }
    return result;
  }

  function fillWork(root, work) {
    for (const key of ['work_title', 'iswc', 'iswc_status', 'work_revision']) {
      const input = field(root, `music_metadata[${key}]`);
      if (input) input.value = scalar(work[key] ?? (key === 'iswc_status' ? 'unknown' : key === 'work_revision' ? 0 : ''));
    }
    ['writers', 'publishers'].forEach(group => {
      root.querySelector(`[data-party-list="${group}"]`)?.replaceChildren();
      (Array.isArray(work[group]) ? work[group] : []).forEach(row => addContributor(root, group, row, false));
    });
  }

  function applyWork(root) {
    const state = states.get(root);
    const id = root.querySelector('[data-work-select]')?.value;
    if (!state.lastWorkId) state.manualWork = readWork(root);
    if (id) {
      const work = state.works.find(item => scalar(item.work_id) === id);
      if (!work) {
        showStatus(root, 'Reload workspace details before selecting this composition.', true);
        return;
      }
      fillWork(root, work);
    } else if (state.manualWork) fillWork(root, state.manualWork);
    state.lastWorkId = id || '';
    workLock(root);
    updatePreview(root);
  }

  function showStatus(root, message, error = false) {
    const status = root.querySelector('[data-metadata-load-status]');
    if (!status) return;
    status.hidden = !message;
    status.textContent = message;
    status.dataset.state = error ? 'error' : 'working';
  }

  function blocked(form) {
    const root = form.querySelector('[data-song-metadata]');
    if (!root) return false;
    const state = states.get(root);
    return root.dataset.schemaReady !== '1' || !!state?.loading || !!state?.failed || !!(state?.workspace && !state.workspace.value);
  }

  function updateButtons(root) {
    const form = root.closest('form');
    if (!form) return;
    form.querySelectorAll('[data-song-save]').forEach(button => { button.disabled = blocked(form) || form.dataset.submitting === '1'; });
  }

  function filterAlbums(form, workspaceId) {
    const select = form.querySelector('[data-song-album]');
    if (!select) return;
    for (const option of select.options) {
      const unavailable = option.value !== '0' && option.value !== '' && option.dataset.workspaceId !== workspaceId;
      option.hidden = unavailable;
      option.disabled = unavailable;
    }
    if (select.selectedOptions[0]?.disabled) select.value = '0';
    select.disabled = !workspaceId;
  }

  function clearWorkspaceLinks(root) {
    // Keep typed draft fields, but withdraw values loaded from the previous
    // workspace's reusable records as soon as its authority changes.
    const state = states.get(root);
    const work = root.querySelector('[data-work-select]');
    if (work?.value) {
      work.value = '';
      fillWork(root, state.manualWork || {});
    }
    root.querySelectorAll('[data-party-row]').forEach(row => {
      if (row.querySelector('[data-party-select]')?.value) row.remove();
    });
    const label = root.querySelector('[data-label-select]');
    const labelName = root.querySelector('[data-label-name]');
    if (label?.value && labelName) labelName.value = '';
    if (label) label.value = '';
    if (labelName) labelName.readOnly = false;
    if (work) work.value = '';
    const revision = root.querySelector('[data-work-revision]');
    if (revision) revision.value = '0';
    state.lastWorkId = '';
    state.manualWork = readWork(root);
    workLock(root);
    updatePreview(root);
  }

  async function loadWorkspace(root) {
    const state = states.get(root);
    if (!state?.workspace) return;
    const form = root.closest('form');
    const id = state.workspace.value;
    const changedWorkspace = state.workspaceId !== id;
    state.workspaceId = id;
    if (changedWorkspace) {
      const advanced = document.getElementById('chatCreateAdvanced');
      if (advanced) advanced.hidden = true;
    }
    state.controller?.abort();
    const controller = new AbortController();
    state.controller = controller;
    state.loading = false;
    state.failed = false;
    state.parties = [];
    state.works = [];
    filterAlbums(form, id);
    if (changedWorkspace) clearWorkspaceLinks(root);
    root.querySelectorAll('[data-party-select], [data-label-select]').forEach(select => fillOptions(select, [], 'party_id', 'name', !changedWorkspace));
    fillOptions(root.querySelector('[data-work-select]'), [], 'work_id', 'work_title', !changedWorkspace);
    root.querySelector('[data-metadata-retry]').hidden = true;
    if (!id || root.dataset.schemaReady !== '1') {
      showStatus(root, id ? 'Run the database upgrade before saving song metadata.' : 'Choose the Music Workspace for this song.');
      updateButtons(root);
      return;
    }
    state.loading = true;
    showStatus(root, 'Loading this workspace’s reusable contributors and compositions…');
    updateButtons(root);
    try {
      const endpoint = new URL(form.dataset.songMetadataEndpoint, window.location.href);
      if (endpoint.origin !== window.location.origin) throw new Error('Song metadata must load from this VP3 account.');
      endpoint.searchParams.set('workspace_id', id);
      const response = await fetch(endpoint, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {Accept: 'application/json'}});
      const data = await response.json();
      if (!response.ok || data.ok !== true || !Array.isArray(data.parties) || !Array.isArray(data.works)) {
        throw new Error(data.message || 'Workspace details could not be loaded.');
      }
      if (state.controller !== controller || state.workspace.value !== id) return;
      state.parties = data.parties;
      state.works = data.works;
      root.querySelectorAll('[data-party-select], [data-label-select]').forEach(select => fillOptions(select, state.parties, 'party_id', 'name', !changedWorkspace));
      fillOptions(root.querySelector('[data-work-select]'), state.works, 'work_id', 'work_title', !changedWorkspace);
      showStatus(root, 'Workspace selected. You can reuse its contributor and composition details.');
      const advanced = document.getElementById('chatCreateAdvanced');
      if (advanced && form.dataset.songLibraryUrl) {
        const url = new URL(form.dataset.songLibraryUrl, window.location.href);
        url.searchParams.set('workspace', id);
        url.searchParams.set('new', '1');
        url.hash = 'editor';
        advanced.href = url.toString();
        advanced.textContent = 'Open Music Library ↗';
        advanced.hidden = false;
      }
    } catch (error) {
      if (controller.signal.aborted || state.controller !== controller) return;
      state.failed = true;
      showStatus(root, `${error instanceof Error ? error.message : 'Workspace details could not be loaded.'} Your entered fields have been kept.`, true);
      root.querySelector('[data-metadata-retry]').hidden = false;
    } finally {
      if (state.controller === controller) {
        state.loading = false;
        updateButtons(root);
      }
    }
  }

  function focusError(form) {
    const summary = form.querySelector('[data-song-error-summary]') || document.getElementById('chatCreateModalStatus');
    if (summary) {
      summary.tabIndex = -1;
      summary.focus();
    }
  }

  function prepare(form) {
    const root = form.querySelector('[data-song-metadata]');
    if (!root) return true;
    for (const group of groups) {
      root.querySelectorAll(`[data-party-list="${group}"] [data-party-row]`).forEach((row, index) => {
        row.querySelectorAll('[name]').forEach(input => {
          input.name = input.name.replace(/^music_metadata\[(writers|publishers|performers)\]\[[^\]]+\]/, `music_metadata[${group}][${index}]`);
        });
      });
    }
    return !blocked(form);
  }

  function resetAfterSuccess(form) {
    const root = form.querySelector('[data-song-metadata]');
    const state = root && states.get(root);
    if (!state) return;
    groups.forEach(group => root.querySelector(`[data-party-list="${group}"]`)?.replaceChildren());
    root.querySelectorAll('[data-select-mirror]').forEach(input => input.remove());
    const revision = field(root, 'metadata_revision');
    if (revision) revision.value = revision.defaultValue = '0';
    const workRevision = root.querySelector('[data-work-revision]');
    if (workRevision) workRevision.value = workRevision.defaultValue = '0';
    const request = root.querySelector('[data-song-request-key]');
    try {
      const bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      request.value = request.defaultValue = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
    } catch (_) {
      state.failed = true;
      showStatus(root, 'Song saved. Reload this page before creating another song.', true);
      updateButtons(root);
      return;
    }
    workLock(root);
    updatePreview(root);
    if (state.workspace) void loadWorkspace(root);
    else updateButtons(root);
  }

  function mount(root) {
    if (states.has(root)) return;
    const form = root.closest('form');
    if (!form) return;
    let defaults = {};
    try { defaults = JSON.parse(root.querySelector('[data-music-defaults]')?.textContent || '{}'); } catch (_) {}
    const state = {parties: Array.isArray(defaults.parties) ? defaults.parties : [], works: Array.isArray(defaults.works) ? defaults.works : [], nextIndex: 100, workspace: form.querySelector('[data-song-workspace]'), loading: false, failed: false, controller: null, manualWork: readWork(root), lastWorkId: root.querySelector('[data-work-select]')?.value || ''};
    if (state.lastWorkId && !state.works.some(work => scalar(work.work_id) === state.lastWorkId)) state.works.push({work_id: state.lastWorkId, ...state.manualWork});
    root.querySelectorAll('[data-party-row] [name]').forEach(input => {
      const index = input.name.match(/\[(\d+)\]/);
      if (index) state.nextIndex = Math.max(state.nextIndex, Number(index[1]));
    });
    states.set(root, state);
    root.addEventListener('click', event => {
      const add = event.target.closest('[data-party-add]');
      if (add) addContributor(root, add.dataset.partyAdd);
      const remove = event.target.closest('[data-party-remove]');
      if (remove && !remove.disabled) {
        const row = remove.closest('[data-party-row]');
        const group = row.dataset.partyGroup;
        row.remove();
        root.querySelector(`[data-party-add="${group}"]`)?.focus();
        updatePreview(root);
      }
      if (event.target.closest('[data-metadata-retry]')) void loadWorkspace(root);
    });
    root.addEventListener('change', event => {
      const select = event.target.closest('[data-party-select]');
      if (select) {
        const row = select.closest('[data-party-row]');
        const party = state.parties.find(item => scalar(item.party_id) === select.value);
        if (party) for (const key of ['name', 'ipi', 'pro']) row.querySelector(`[data-party-field="${key}"]`).value = scalar(party[key]);
        workLock(root);
      }
      if (event.target.matches('[data-work-select]')) applyWork(root);
      if (event.target.matches('[data-label-select]')) {
        const name = root.querySelector('[data-label-name]');
        const party = state.parties.find(item => scalar(item.party_id) === event.target.value);
        if (party) name.value = scalar(party.name);
        name.readOnly = !!event.target.value;
      }
      if (event.target.matches('[data-identifier]') && !event.target.readOnly && event.target.value.trim()) {
        const status = root.querySelector(`[data-identifier-status="${event.target.dataset.identifier}"]`);
        if (status && !status.disabled) status.value = 'provided';
      }
      updatePreview(root);
    });
    root.addEventListener('input', () => updatePreview(root));
    form.addEventListener('submit', event => {
      prepare(form);
      if (blocked(form) && !['delete', 'restore'].includes(event.submitter?.value)) {
        event.preventDefault();
        showStatus(root, 'Choose a workspace and load its song details before saving.', true);
        root.querySelector('[data-metadata-load-status]')?.scrollIntoView({block: 'nearest'});
        state.workspace?.focus();
      }
    });
    workLock(root);
    updatePreview(root);
    updateButtons(root);
    if (state.workspace) {
      state.workspace.addEventListener('change', () => void loadWorkspace(root));
      void loadWorkspace(root);
    }
    const summary = form.querySelector('[data-song-error-summary]');
    if (summary) focusError(form);
  }

  function boot() {
    document.querySelectorAll('[data-song-metadata]').forEach(mount);
    const summary = document.querySelector('[data-song-error-summary]');
    if (summary) summary.focus();
  }
  window.VP3MusicMetadataFormV1 = Object.freeze({mount, prepare, isBlocked: blocked, resetAfterSuccess, focusError});
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once: true});
  else boot();
})();
