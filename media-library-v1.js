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
  const supportedMime = {jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp'};
  const views = [...root.querySelectorAll('[data-media-view]')];
  const panels = [...root.querySelectorAll('[data-media-panel]')];
  let request;
  let nextBefore = null;

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

  function assetCard(asset) {
    const card = element('article');
    card.className = 'vp3-media-card';
    card.dataset.mediaAsset = asset.id;
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
    const source = ({openai_image: 'Generated', image_editor: 'Edited copy', upload: 'Upload'})[asset.source] || 'Saved media';
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
    card.append(body);
    return card;
  }

  async function json(url, options) {
    const response = await fetch(url, {credentials: 'same-origin', headers: {'X-VP3-Media-Owner': ownerKey}, ...options});
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.error || 'The Media Library request failed. Try again.');
    return data;
  }

  async function loadAssets(before = null) {
    request?.abort();
    const controller = new AbortController();
    request = controller;
    const url = new URL(mediaEndpoint, location.href);
    url.searchParams.set('action', 'browse');
    for (const [name, value] of new FormData(form)) if (value) url.searchParams.set(name, value);
    if (before) url.searchParams.set('before', before);
    if (!before) {
      grid.replaceChildren();
      nextBefore = null;
    }
    status.textContent = 'Loading saved media…';
    more.disabled = true;
    try {
      const data = await json(url, {signal: controller.signal});
      if (request !== controller) return;
      grid.append(...data.assets.map(assetCard));
      nextBefore = data.next_before;
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
  for (const select of form.querySelectorAll('select')) select.addEventListener('change', () => void loadAssets());
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
      uploadStatus.textContent = 'Image saved to your Media Library.';
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
