(() => {
  'use strict';
  const root = document.querySelector('[data-media-workspace]');
  if (!root) return;
  const {mediaEndpoint, imageEndpoint, ownerKey, csrf} = root.dataset;
  const form = root.querySelector('[data-media-filters]');
  const grid = root.querySelector('[data-media-assets]');
  const status = root.querySelector('[data-media-status]');
  const more = root.querySelector('[data-media-more]');
  const upload = root.querySelector('[data-media-upload]');
  const uploadStatus = root.querySelector('[data-media-upload-status]');
  const collectionFilter = root.querySelector('[data-media-collection-filter]');
  const collectionSelect = root.querySelector('[data-media-collection-select]');
  const collectionManager = root.querySelector('[data-media-collection-manager]');
  const collectionCreate = root.querySelector('[data-media-collection-create]');
  const collectionEdit = root.querySelector('[data-media-collection-edit]');
  const collectionDelete = root.querySelector('[data-media-collection-delete]');
  const collectionStatus = root.querySelector('[data-media-collection-status]');
  const supportedMime = {jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp'};
  const views = [...root.querySelectorAll('[data-media-view]')];
  const panels = [...root.querySelectorAll('[data-media-panel]')];
  let request;
  let nextBefore = null;
  let loadedPages = 0;
  let collections = new Map();
  let collectionsAvailable = false;
  let collectionBusy = false;

  function show(view) {
    for (const button of views) button.setAttribute('aria-pressed', String(button.dataset.mediaView === view));
    for (const panel of panels) panel.hidden = panel.dataset.mediaPanel !== view;
  }

  function element(tag, text) {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function privateUrl(value) {
    try {
      const resolved = new URL(value, location.href);
      return resolved.origin === location.origin && resolved.pathname.endsWith('/media-library-file-v86.php') ? resolved.href : '';
    } catch {
      return '';
    }
  }

  function openEditor(asset) {
    show('library');
    editor.open(asset);
  }

  function assetCard(asset, openCollections = false) {
    const card = element('article');
    card.className = 'vp3-media-card';
    card.dataset.mediaAsset = String(asset.id);
    const url = asset.available ? privateUrl(asset.url) : '';
    if (url && asset.media_type === 'photo') {
      const link = element('a');
      link.href = url;
      link.target = '_blank';
      link.rel = 'noopener';
      link.className = 'vp3-media-preview';
      const image = element('img');
      image.src = url;
      image.alt = asset.title;
      image.loading = 'lazy';
      link.append(image);
      card.append(link);
    } else {
      const summary = element('div', url ? ({video: 'Video', audio: 'Audio'}[asset.media_type] || 'Media') : 'File unavailable');
      summary.className = 'vp3-media-preview';
      card.append(summary);
    }
    const body = element('div');
    body.className = 'vp3-media-card-body';
    const source = ({openai_image: 'Generated', image_editor: 'Edited copy', promotion_design: 'Marketing design', upload: 'Upload'})[asset.source] || 'Saved media';
    body.append(element('h3', asset.title), element('small', source));
    if (url) {
      const actions = element('div');
      actions.className = 'vp3-media-actions';
      if (asset.media_type === 'photo') {
        const edit = element('button', 'Edit image');
        edit.type = 'button';
        edit.addEventListener('click', () => openEditor(asset));
        actions.append(edit);
      } else {
        const open = element('a', 'Open');
        open.href = url;
        open.target = '_blank';
        open.rel = 'noopener';
        actions.append(open);
      }
      const download = element('a', 'Download');
      download.href = url;
      download.download = '';
      actions.append(download);
      body.append(actions);
    }
    if (collectionsAvailable && collections.size && Array.isArray(asset.collection_ids)) {
      body.append(assetCollections(asset, openCollections));
    }
    card.append(body);
    return card;
  }

  function assetCollections(asset, open) {
    const members = new Set(asset.collection_ids);
    const count = [...collections.keys()].filter(id => members.has(id)).length;
    const disclosure = element('details');
    disclosure.className = 'vp3-media-asset-collections';
    disclosure.dataset.mediaAssetCollections = '';
    disclosure.open = open;
    const summary = element('summary', count ? `Collections (${count})` : 'Collections');
    summary.setAttribute('aria-label', `${summary.textContent} for ${asset.title}`);
    const fields = element('fieldset');
    fields.setAttribute('aria-label', `Collections for ${asset.title}`);
    disclosure.append(summary, fields);
    const fill = () => {
      if (!disclosure.open || fields.childElementCount) return;
      for (const {id, name} of collections.values()) {
        const check = element('input');
        check.type = 'checkbox';
        check.checked = members.has(id);
        check.disabled = collectionBusy;
        check.dataset.mediaCollectionMember = String(id);
        check.addEventListener('change', async () => {
          const intended = check.checked;
          const restoreFocus = document.activeElement === check;
          const saved = await updateCollection('collection_set_asset', {
            collection_id: id, asset_id: asset.id, member: intended ? '1' : '0',
          }, intended ? `Added to ${name}.` : `Removed from ${name}.`);
          if (!saved) check.checked = members.has(id);
          if (restoreFocus && document.activeElement === document.body) {
            const card = grid.querySelector(`[data-media-asset="${Number(asset.id)}"]`);
            (card?.querySelector(`[data-media-collection-member="${id}"]`) || collectionSelect).focus();
          }
        });
        const label = element('label');
        label.append(check, name);
        fields.append(label);
      }
    };
    disclosure.addEventListener('toggle', fill);
    fill();
    return disclosure;
  }

  const selectedCollection = () => collections.get(Number(collectionSelect.value));

  function syncCollectionControls() {
    collectionEdit.hidden = !selectedCollection();
    for (const control of [...collectionManager.querySelectorAll('input, button'), ...grid.querySelectorAll('[data-media-collection-member]')]) {
      control.disabled = collectionBusy;
    }
  }

  function renderCollections(data) {
    const previous = selectedCollection();
    collectionsAvailable = data.collections_available === true && Array.isArray(data.collections);
    const rows = collectionsAvailable ? data.collections : [];
    collections = new Map(rows.filter(row => Number.isSafeInteger(row?.id) && row.id > 0 && typeof row.name === 'string')
      .map(row => [row.id, row]));
    collectionFilter.hidden = collectionManager.hidden = !collectionsAvailable;
    collectionSelect.disabled = !collectionsAvailable;
    const options = [...collections.values()].map(({id, name, count}) =>
      new Option(Number.isSafeInteger(count) && count >= 0 ? `${name} (${count})` : name, id));
    collectionSelect.replaceChildren(new Option('All media', ''), ...options);
    collectionSelect.value = previous && collections.has(previous.id) ? String(previous.id) : '';
    const selected = selectedCollection();
    if (selected?.id !== previous?.id || selected?.name !== previous?.name) {
      collectionEdit.elements.name.value = selected?.name || '';
    }
    syncCollectionControls();
  }

  async function json(url, options) {
    const response = await fetch(url, {credentials: 'same-origin', headers: {'X-VP3-Media-Owner': ownerKey}, ...options});
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.error || 'The Media Library request failed. Try again.');
    return data;
  }

  async function loadAssets(before = null, pages = 1) {
    request?.abort();
    const controller = new AbortController();
    request = controller;
    const url = new URL(mediaEndpoint, location.href);
    url.searchParams.set('action', 'browse');
    for (const [name, value] of new FormData(form)) if (value) url.searchParams.set(name, value);
    if (before) url.searchParams.set('before', before);
    const openCollections = new Set([...grid.querySelectorAll('[data-media-asset-collections][open]')]
      .map(element => element.closest('[data-media-asset]')?.dataset.mediaAsset));
    if (!before) {
      grid.replaceChildren();
      nextBefore = null;
      loadedPages = 0;
    }
    status.textContent = 'Loading saved media…';
    more.disabled = true;
    try {
      for (let page = 0; page < pages; page++) {
        const data = await json(url, {signal: controller.signal});
        if (request !== controller) return;
        if (page === 0) renderCollections(data);
        grid.append(...data.assets.map(asset => assetCard(asset, openCollections.has(String(asset.id)))));
        nextBefore = data.next_before;
        loadedPages++;
        if (!nextBefore) break;
        url.searchParams.set('before', nextBefore);
      }
      status.textContent = grid.childElementCount ? '' : 'No saved media matches this view.';
    } catch (error) {
      if (request === controller) status.textContent = error.message;
    } finally {
      if (request === controller) {
        more.hidden = !nextBefore;
        more.disabled = false;
      }
    }
  }

  function uploadPart(action, values) {
    const body = new FormData();
    body.set('action', action);
    body.set('csrf_token', csrf);
    for (const [name, value] of Object.entries(values)) {
      if (value instanceof Blob) body.set(name, value, 'image-part.bin');
      else body.set(name, value);
    }
    return json(mediaEndpoint, {method: 'POST', body});
  }

  async function updateCollection(action, values, success, afterSave) {
    if (collectionBusy || !collectionsAvailable) return false;
    collectionBusy = true;
    syncCollectionControls();
    collectionStatus.textContent = 'Saving collection changes…';
    try {
      await uploadPart(action, values);
      const lastCollection = collectionSelect.value;
      afterSave?.();
      await loadAssets(null, lastCollection === collectionSelect.value ? Math.max(1, loadedPages) : 1);
      collectionStatus.textContent = success;
      return true;
    } catch (error) {
      collectionStatus.textContent = error.message;
      return false;
    } finally {
      collectionBusy = false;
      syncCollectionControls();
    }
  }

  async function saveImage(blob, {title, mime, originalName, sourceAssetId}) {
    if (!blob.size) throw new Error('Choose a nonempty image.');
    const {token, chunk_size: chunkSize} = await uploadPart('begin', {
      media_type: 'photo', mime_type: mime, title,
      source: sourceAssetId ? 'image_editor' : 'upload', original_name: originalName || ''
    });
    const size = Math.min(chunkSize || 1048576, 1048576);
    const total = Math.ceil(blob.size / size);
    for (let index = 0; index < total; index++) {
      await uploadPart('chunk', {
        token, index, total_chunks: total, chunk: blob.slice(index * size, (index + 1) * size, mime)
      });
    }
    const {asset} = await uploadPart('finish', {
      token, metadata_json: JSON.stringify(sourceAssetId ? {source_asset_id: sourceAssetId} : {})
    });
    return asset;
  }

  const editor = window.VP3MediaImageEditor.mount(root.querySelector('[data-media-editor]'), {
    resolveReturnFocus: asset => grid.querySelector(`[data-media-asset="${Number(asset.id)}"] button`) || views[0],
    async saveCopy({blob, title, sourceAssetId}) {
      const asset = await saveImage(blob, {title, mime: 'image/png', sourceAssetId});
      void loadAssets();
      return asset;
    }
  });
  window.VP3MediaImageGeneration.mount(root.querySelector('[data-media-generation]'), {
    endpoint: imageEndpoint, csrf, ownerKey, onOpen: openEditor, onSaved: () => void loadAssets()
  });

  for (const button of views) button.addEventListener('click', () => show(button.dataset.mediaView));
  form.addEventListener('submit', event => {
    event.preventDefault();
    void loadAssets();
  });
  for (const select of form.querySelectorAll('select')) select.addEventListener('change', () => {
    if (select === collectionSelect) {
      collectionEdit.elements.name.value = selectedCollection()?.name || '';
      syncCollectionControls();
    }
    void loadAssets();
  });
  collectionCreate.addEventListener('submit', async event => {
    event.preventDefault();
    const name = collectionCreate.elements.name.value.trim();
    if (!name) { collectionStatus.textContent = 'Enter a collection name.'; return; }
    const created = await updateCollection('collection_create', {name}, `Created ${name}.`, () => collectionCreate.reset());
    if (created && document.activeElement === document.body) collectionCreate.elements.name.focus();
  });
  collectionEdit.addEventListener('submit', async event => {
    event.preventDefault();
    const collection = selectedCollection();
    if (!collection) return;
    const name = collectionEdit.elements.name.value.trim();
    if (!name) { collectionStatus.textContent = 'Enter a collection name.'; return; }
    const renamed = await updateCollection('collection_rename', {collection_id: collection.id, name}, `Renamed collection to ${name}.`);
    if (renamed && document.activeElement === document.body && !collectionEdit.hidden) collectionEdit.elements.name.focus();
  });
  collectionDelete.addEventListener('click', async () => {
    const collection = selectedCollection();
    if (!collection || !window.confirm(`Delete “${collection.name}”? Its files will stay in your Media Library.`)) return;
    const deleted = await updateCollection('collection_delete', {collection_id: collection.id},
      `Deleted ${collection.name}. Its files are still in your Media Library.`, () => {
        if (selectedCollection()?.id === collection.id) collectionSelect.value = '';
      });
    if (deleted && document.activeElement === document.body) collectionSelect.focus();
  });
  more.addEventListener('click', () => void loadAssets(nextBefore));
  upload.addEventListener('change', async () => {
    const file = upload.files[0];
    if (!file) return;
    upload.disabled = true;
    uploadStatus.textContent = 'Uploading image…';
    try {
      const mime = file.type || supportedMime[file.name.split('.').pop().toLowerCase()];
      if (!Object.values(supportedMime).includes(mime)) throw new Error('Choose a JPEG, PNG, or WebP image.');
      const title = file.name.replace(/\.[^.]+$/, '') || 'Uploaded image';
      const asset = await saveImage(file, {title, mime, originalName: file.name});
      uploadStatus.textContent = selectedCollection()
        ? 'Image saved in All media. Use its Collections control to add it to a collection.'
        : 'Image saved to your Media Library.';
      void loadAssets();
      openEditor(asset);
    } catch (error) {
      uploadStatus.textContent = error.message;
    } finally {
      upload.value = '';
      upload.disabled = false;
    }
  });
  void loadAssets();
})();
