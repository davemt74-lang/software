(() => {
  'use strict';

  if (window.VP3MediaLibraryPicker?.version === 1) return;

  const instances = new WeakMap();
  const supportedMimes = ['image/jpeg', 'image/png', 'image/webp'];
  const mimeNames = {'image/jpeg': 'JPEG', 'image/png': 'PNG', 'image/webp': 'WebP'};
  const sourceNames = {openai_image: 'Generated', image_editor: 'Edited copy', upload: 'Uploaded', promotion_design: 'Marketing design'};
  let sequence = 0;

  function positiveId(value) {
    if (typeof value === 'string' && !/^[1-9]\d*$/.test(value)) return null;
    if (typeof value !== 'string' && typeof value !== 'number') return null;
    const number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : null;
  }

  function node(tag, text, className) {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
  }

  function button(text, selector, className = 'vp3-media-picker-button') {
    const element = node('button', text, className);
    element.type = 'button';
    element.setAttribute('data-media-picker-' + selector, '');
    return element;
  }

  function mime(value) {
    return typeof value === 'string' ? value.split(';', 1)[0].trim().toLowerCase() : '';
  }

  function bytes(value) {
    if (!Number.isSafeInteger(value) || value <= 0) return 'Size checked when used';
    if (value >= 1024 * 1024) return (value / (1024 * 1024)).toLocaleString(undefined, {maximumFractionDigits: 1}) + ' MiB';
    return Math.max(1, Math.ceil(value / 1024)).toLocaleString() + ' KiB';
  }

  // Only the private media route, with one exact asset ID, can become an image URL.
  function privateUrl(value, id, expectedPath) {
    if (typeof value !== 'string') return '';
    try {
      const url = new URL(value, location.href);
      return url.origin === location.origin && /^https?:$/.test(url.protocol)
        && !url.username && !url.password && !url.hash
        && url.pathname === expectedPath
        && url.searchParams.getAll('id').length === 1 && url.searchParams.get('id') === String(id)
        && [...url.searchParams.keys()].every(key => key === 'id') ? url.href : '';
    } catch {
      return '';
    }
  }

  function imageMark() {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 32 32');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M6 5h20a1 1 0 0 1 1 1v20a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm-1 16 7-7 6 6 4-4 5 5M22 10h.01');
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', 'currentColor');
    path.setAttribute('stroke-width', '1.7');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');
    svg.append(path);
    return svg;
  }

  function errorMessage(error, fallback) {
    return typeof error?.message === 'string' && error.message.trim()
      ? error.message.trim().slice(0, 500) : fallback;
  }

  function open(options = {}) {
    const {host, onSelect, onClose, isCurrent} = options;
    if (!host || host.nodeType !== 1 || host.ownerDocument !== document || !host.isConnected) {
      throw new Error('Open Media Library inside a connected page section.');
    }
    if (typeof onSelect !== 'function') throw new Error('Media Library needs an image selection handler.');
    if (isCurrent !== undefined && typeof isCurrent !== 'function') throw new Error('Media Library needs a valid context check.');
    const owner = positiveId(options.ownerKey);
    if (!owner) throw new Error('Your account could not be verified. Reopen Media Library.');
    let endpoint;
    try { endpoint = new URL(options.endpoint || 'api/media-library-v86.php', location.href); } catch { /* Validated below. */ }
    if (!endpoint || endpoint.origin !== location.origin || !/^https?:$/.test(endpoint.protocol)
      || endpoint.username || endpoint.password || endpoint.hash) {
      throw new Error('Media Library must use a secure endpoint on this site.');
    }
    const mediaFilePath = new URL('../media-library-file-v86.php', endpoint).pathname;
    const maxBytes = options.maxBytes === undefined ? 4 * 1024 * 1024 : options.maxBytes;
    if (!Number.isSafeInteger(maxBytes) || maxBytes <= 0) throw new Error('Media Library needs a valid image size limit.');
    const requestedMimes = options.acceptedMimes === undefined ? supportedMimes
      : options.acceptedMimes instanceof Set ? [...options.acceptedMimes] : options.acceptedMimes;
    if (!Array.isArray(requestedMimes)) throw new Error('Media Library needs a list of supported image formats.');
    const acceptedMimes = [...new Set(requestedMimes.map(mime).filter(value => supportedMimes.includes(value)))];
    if (!acceptedMimes.length) throw new Error('Choose at least one supported image format: JPEG, PNG or WebP.');
    const customActions = options.actions !== undefined;
    const definitions = customActions ? options.actions : [{id: 'use', label: 'Use image'}];
    if (!Array.isArray(definitions) || !definitions.length || definitions.length > 4) throw new Error('Media Library needs between one and four image actions.');
    const actionIds = new Set();
    const actionSpecs = definitions.map(definition => {
      if (!definition || typeof definition.id !== 'string' || !/^[a-z][a-z0-9_-]{0,39}$/.test(definition.id)
        || actionIds.has(definition.id) || typeof definition.label !== 'string' || !definition.label.trim()
        || (definition.eligibility !== undefined && typeof definition.eligibility !== 'function')) {
        throw new Error('Media Library needs valid, uniquely named image actions.');
      }
      actionIds.add(definition.id);
      const limit = definition.maxBytes === undefined ? maxBytes : definition.maxBytes;
      const formats = definition.acceptedMimes === undefined ? acceptedMimes : definition.acceptedMimes;
      if (!Number.isSafeInteger(limit) || limit <= 0 || !Array.isArray(formats)) throw new Error('Media Library needs valid image action limits.');
      const accepted = [...new Set(formats.map(mime).filter(value => supportedMimes.includes(value)))];
      if (!accepted.length) throw new Error('Media Library needs at least one supported format for each action.');
      return Object.freeze({id: definition.id, label: definition.label.trim().slice(0, 80), maxBytes: limit,
        acceptedMimes: accepted, eligibility: definition.eligibility});
    });

    instances.get(host)?.close('stale');
    const idPrefix = 'vp3-media-picker-' + (++sequence);
    const selectedId = positiveId(options.selectedId);
    const returnFocus = options.returnFocus || document.activeElement;
    const records = new Map();
    let closed = false, loading = false, candidate = null, nextBefore = null;
    let viewSerial = 0, requestSerial = 0, operationSerial = 0;
    let request = null, pending = null, searchTimer = 0, scopeTimer = 0, observer = null, retryContext = null;
    let filters = {q: '', source: '', collection: ''};

    const shell = node('section', undefined, 'vp3-media-picker');
    shell.setAttribute('data-media-picker', '');
    shell.setAttribute('role', 'region');
    shell.setAttribute('aria-labelledby', idPrefix + '-title');
    shell.setAttribute('aria-describedby', idPrefix + '-intro');
    shell.tabIndex = -1;
    const header = node('header', undefined, 'vp3-media-picker-header');
    const heading = node('h3', typeof options.title === 'string' && options.title.trim() ? options.title.trim().slice(0, 190) : 'Choose from Media Library');
    heading.id = idPrefix + '-title';
    const intro = node('p', 'Browse your saved images and choose one to use here.', 'vp3-media-picker-intro');
    intro.id = idPrefix + '-intro';
    header.append(heading, intro);

    // A div avoids introducing a nested form inside a destination's editor form.
    const controls = node('div', undefined, 'vp3-media-picker-filters');
    const searchLabel = node('label', undefined, 'vp3-media-picker-search-field');
    searchLabel.append(node('span', 'Search by name'));
    const search = node('input');
    search.type = 'search';
    search.maxLength = 190;
    search.placeholder = 'Search image names';
    search.autocomplete = 'off';
    search.setAttribute('data-media-picker-search', '');
    search.setAttribute('aria-controls', idPrefix + '-grid');
    searchLabel.append(search);
    const sourceLabel = node('label');
    sourceLabel.append(node('span', 'Source'));
    const source = node('select');
    source.setAttribute('data-media-picker-source', '');
    source.setAttribute('aria-controls', idPrefix + '-grid');
    for (const [value, label] of [['', 'All sources'], ['generated', 'Generated'], ['edited', 'Edited'], ['uploaded', 'Uploaded & other']]) {
      source.append(new Option(label, value));
    }
    sourceLabel.append(source);
    const collectionLabel = node('label');
    collectionLabel.hidden = true;
    collectionLabel.setAttribute('data-media-picker-collection-filter', '');
    collectionLabel.append(node('span', 'Collection'));
    const collection = node('select');
    collection.disabled = true;
    collection.setAttribute('data-media-picker-collection', '');
    collection.setAttribute('aria-controls', idPrefix + '-grid');
    collection.append(new Option('All collections', ''));
    collectionLabel.append(collection);
    controls.append(searchLabel, sourceLabel, collectionLabel);

    const status = node('p', '', 'vp3-media-picker-status');
    status.setAttribute('data-media-picker-status', '');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-atomic', 'true');
    const errorBox = node('div', undefined, 'vp3-media-picker-error');
    errorBox.setAttribute('data-media-picker-error', '');
    errorBox.setAttribute('role', 'alert');
    errorBox.hidden = true;
    const errorText = node('p');
    errorText.setAttribute('data-media-picker-error-message', '');
    const retry = button('Try again', 'retry');
    errorBox.append(errorText, retry);

    const body = node('div', undefined, 'vp3-media-picker-body');
    const results = node('div', undefined, 'vp3-media-picker-results');
    const grid = node('ul', undefined, 'vp3-media-picker-grid');
    grid.id = idPrefix + '-grid';
    grid.setAttribute('data-media-picker-grid', '');
    grid.setAttribute('aria-label', 'Saved images');
    grid.setAttribute('aria-busy', 'false');
    const empty = node('div', undefined, 'vp3-media-picker-empty');
    empty.hidden = true;
    empty.setAttribute('data-media-picker-empty', '');
    const emptyTitle = node('h4');
    const emptyText = node('p');
    const reset = button('Reset filters', 'reset');
    empty.append(imageMark(), emptyTitle, emptyText, reset);
    const more = button('Load more images', 'more');
    more.hidden = true;
    results.append(grid, empty, more);
    const preview = node('aside', undefined, 'vp3-media-picker-preview');
    preview.setAttribute('data-media-picker-preview', '');
    preview.setAttribute('aria-labelledby', idPrefix + '-preview-title');
    const previewHeading = node('h4', 'Selected image');
    previewHeading.id = idPrefix + '-preview-title';
    const previewBody = node('div', undefined, 'vp3-media-picker-preview-body');
    preview.append(previewHeading, previewBody);
    body.append(results, preview);

    const footer = node('footer', undefined, 'vp3-media-picker-footer');
    const limit = node('p', customActions ? 'Choose where to use the selected image.'
      : acceptedMimes.map(value => mimeNames[value]).join(', ') + ' · up to ' + bytes(maxBytes), 'vp3-media-picker-limit');
    limit.id = idPrefix + '-limit';
    const actions = node('div', undefined, 'vp3-media-picker-actions');
    const cancel = button('Cancel', 'cancel');
    actions.append(cancel);
    actions.dataset.mediaPickerActions = customActions ? 'custom' : 'default';
    const actionControls = actionSpecs.map((action, index) => {
      const group = node('div', undefined, 'vp3-media-picker-action');
      const control = button(action.label, customActions ? 'action' : 'use',
        'vp3-media-picker-button' + (index === 0 ? ' vp3-media-picker-use' : ''));
      control.dataset.mediaPickerAction = action.id;
      const reason = node('p', '', 'vp3-media-picker-action-reason');
      reason.id = idPrefix + '-action-' + action.id;
      reason.dataset.mediaPickerActionReason = action.id;
      reason.hidden = true;
      control.setAttribute('aria-describedby', limit.id + ' ' + reason.id);
      control.disabled = true;
      group.append(control, reason);
      actions.append(group);
      return {action, control, reason};
    });
    footer.append(limit, actions);
    shell.append(header, controls, status, errorBox, body, footer);

    function scopeCurrent() {
      if (closed || !host.isConnected || !shell.isConnected) return false;
      try { return !isCurrent || Boolean(isCurrent()); } catch { return false; }
    }

    function ensureCurrent() {
      if (closed) return false;
      if (scopeCurrent()) return true;
      close('stale');
      return false;
    }

    function operationCurrent(operation) {
      return ensureCurrent() && pending === operation && candidate === operation.candidate
        && operationSerial === operation.serial && viewSerial === operation.view
        && !operation.controller.signal.aborted;
    }

    function syncActions() {
      for (const {action, control, reason} of actionControls) {
        const unavailable = candidate?.actionReasons[action.id] || '';
        control.disabled = closed || !candidate || Boolean(pending) || loading || Boolean(unavailable);
        control.textContent = pending?.action === action ? 'Checking image…' : action.label;
        control.setAttribute('aria-busy', String(pending?.action === action));
        reason.textContent = unavailable;
        reason.hidden = !unavailable;
      }
      more.disabled = closed || loading || !nextBefore;
      retry.disabled = closed || loading || Boolean(pending);
      shell.dataset.mediaPickerLoading = String(loading);
    }

    function cancelPending() {
      const previous = pending;
      pending = null;
      operationSerial++;
      previous?.controller.abort();
      syncActions();
    }

    function clearError() {
      retryContext = null;
      errorBox.hidden = true;
      errorText.textContent = '';
    }

    function showError(message, context) {
      errorText.textContent = message;
      retryContext = context;
      retry.hidden = !context;
      errorBox.hidden = false;
    }

    function renderPreview() {
      previewBody.replaceChildren();
      if (!candidate) {
        const placeholder = node('div', undefined, 'vp3-media-picker-preview-placeholder');
        placeholder.append(imageMark(), node('p', 'Select an image to preview it.'));
        previewBody.append(placeholder);
        preview.dataset.mediaPickerCandidate = '';
        return;
      }
      const record = candidate;
      preview.dataset.mediaPickerCandidate = String(record.asset.id);
      const picture = node('div', undefined, 'vp3-media-picker-picture');
      const image = node('img');
      image.alt = record.asset.title;
      image.decoding = 'async';
      const unavailable = node('p', customActions ? 'Preview unavailable. Choose an available action to check this image.'
        : 'Preview unavailable. Use image will check whether this file can be attached.', 'vp3-media-picker-image-fallback');
      unavailable.hidden = true;
      image.addEventListener('error', () => {
        if (!ensureCurrent() || candidate !== record) return;
        image.hidden = true;
        unavailable.hidden = false;
      }, {once: true});
      image.src = record.url;
      picture.append(image, unavailable);
      const name = node('p', record.asset.title, 'vp3-media-picker-preview-name');
      const detail = node('p', record.detail, 'vp3-media-picker-meta');
      const note = node('p', record.asset.id === selectedId ? 'This image is currently used here.'
        : customActions ? 'Choose how to use this image.' : 'Choose Use image to confirm your selection.', 'vp3-media-picker-preview-note');
      previewBody.append(picture, name, detail, note);
    }

    function updateCard(record) {
      const selected = candidate === record;
      record.card.setAttribute('aria-pressed', String(selected));
      record.card.dataset.mediaPickerSelected = String(selected);
      record.badge.textContent = selected ? 'Selected' : record.asset.id === selectedId ? 'Current image' : '';
      record.badge.hidden = !record.badge.textContent;
    }

    function choose(record) {
      if (!ensureCurrent() || records.get(record.asset.id) !== record || record.reason) return;
      cancelPending();
      candidate = record;
      for (const row of records.values()) updateCard(row);
      if (retryContext?.kind === 'selection') clearError();
      renderPreview();
      status.textContent = record.asset.title.slice(0, 90) + (customActions ? ' selected. Choose an action to confirm.' : ' selected. Choose Use image to confirm.');
      syncActions();
    }

    function makeRecord(raw) {
      const id = positiveId(raw?.id);
      if (!id) return null;
      const title = typeof raw.title === 'string' && raw.title.trim() ? raw.title.trim().slice(0, 500) : 'Untitled image';
      const format = mime(raw.mime_type);
      const url = privateUrl(raw.url, id, mediaFilePath);
      const size = Number.isSafeInteger(raw.file_size) && raw.file_size > 0 ? raw.file_size : null;
      let baseReason = '';
      if (raw.available !== true) baseReason = 'File unavailable';
      else if (raw.media_type !== 'photo') baseReason = 'Choose an image file';
      else if (!url) baseReason = 'Image address could not be verified';
      const asset = Object.freeze({...raw, id, title, mime_type: format, url,
        collection_ids: Object.freeze(Array.isArray(raw.collection_ids) ? raw.collection_ids.map(positiveId).filter(Boolean) : [])});
      const actionReasons = {};
      for (const action of actionSpecs) {
        let reason = baseReason;
        if (!reason && !action.acceptedMimes.includes(format)) reason = 'Use ' + action.acceptedMimes.map(value => mimeNames[value]).join(', ') + ' images';
        if (!reason && size > action.maxBytes) reason = 'Exceeds the ' + bytes(action.maxBytes) + ' image limit';
        if (!reason && action.eligibility) {
          try {
            const eligible = action.eligibility(asset);
            if (eligible === false) reason = 'This image cannot be used for ' + action.label + '.';
            else if (typeof eligible === 'string' && eligible.trim()) reason = eligible.trim().slice(0, 300);
          } catch { reason = 'Image compatibility could not be verified'; }
        }
        actionReasons[action.id] = reason;
      }
      const reasons = Object.values(actionReasons);
      const reason = reasons.every(Boolean) ? [...new Set(reasons)].join(' · ') : '';
      const detail = [sourceNames[raw.source] || 'Saved image', mimeNames[format] || 'Unsupported format', bytes(size)].join(' · ');
      const item = node('li', undefined, 'vp3-media-picker-item');
      const card = button('', 'asset', 'vp3-media-picker-card');
      card.dataset.mediaPickerAsset = String(id);
      card.disabled = Boolean(reason);
      card.setAttribute('aria-disabled', String(Boolean(reason)));
      card.setAttribute('aria-pressed', 'false');
      const thumb = node('span', undefined, 'vp3-media-picker-thumbnail');
      const fallback = node('span', raw.available === true ? 'Preview unavailable' : 'File unavailable', 'vp3-media-picker-image-fallback');
      if (url && raw.available === true && raw.media_type === 'photo') {
        const image = node('img');
        image.alt = '';
        image.loading = 'lazy';
        image.decoding = 'async';
        fallback.hidden = true;
        image.addEventListener('error', () => {
          if (!ensureCurrent() || records.get(id)?.card !== card) return;
          image.hidden = true;
          fallback.hidden = false;
        }, {once: true});
        image.src = url;
        thumb.append(image);
      }
      thumb.append(fallback);
      const badge = node('span', '', 'vp3-media-picker-badge');
      badge.hidden = true;
      thumb.append(badge);
      const caption = node('span', undefined, 'vp3-media-picker-caption');
      const name = node('strong', title, 'vp3-media-picker-name');
      const metadata = node('span', detail, 'vp3-media-picker-meta');
      caption.append(name, metadata);
      if (reason) {
        const why = node('span', reason, 'vp3-media-picker-reason');
        why.id = idPrefix + '-reason-' + id;
        why.setAttribute('data-media-picker-disabled-reason', '');
        card.setAttribute('aria-describedby', why.id);
        caption.append(why);
      }
      card.append(thumb, caption);
      item.append(card);
      const record = {asset, url, reason, actionReasons, detail, item, card, badge};
      card.addEventListener('click', () => choose(record));
      updateCard(record);
      return record;
    }

    function renderCollections(data) {
      const available = data.collections_available === true && Array.isArray(data.collections);
      const rows = available ? data.collections.filter(row => positiveId(row?.id) && typeof row.name === 'string') : [];
      const seen = new Set();
      const choices = [new Option('All collections', '')];
      for (const row of rows) {
        const id = String(positiveId(row.id));
        if (seen.has(id)) continue;
        seen.add(id);
        const name = row.name.trim().slice(0, 190) || 'Untitled collection';
        choices.push(new Option(name, id));
      }
      collection.replaceChildren(...choices);
      collectionLabel.hidden = !available;
      collection.disabled = !available;
      collection.value = seen.has(filters.collection) ? filters.collection : '';
      return collection.value === filters.collection;
    }

    function refreshEmpty() {
      empty.hidden = records.size > 0;
      const filtered = Boolean(filters.q || filters.source || filters.collection);
      emptyTitle.textContent = filtered ? 'No matching images' : 'No saved images yet';
      emptyText.textContent = filtered ? 'Try a different name or reset the filters.'
        : 'Images you save in Media Library will appear here.';
      reset.hidden = !filtered;
    }

    async function loadPage(before = null) {
      if (!ensureCurrent()) return;
      clearTimeout(searchTimer);
      cancelPending();
      request?.abort();
      const controller = new AbortController();
      const serial = ++requestSerial;
      const view = viewSerial;
      request = controller;
      const current = () => ensureCurrent() && request === controller && requestSerial === serial
        && viewSerial === view && !controller.signal.aborted;
      loading = true;
      grid.setAttribute('aria-busy', 'true');
      clearError();
      status.textContent = before ? 'Loading more images…' : filters.q ? 'Searching images…' : 'Loading images…';
      syncActions();
      const url = new URL(endpoint.href);
      for (const key of ['action', 'type', 'q', 'source', 'collection_id', 'before']) url.searchParams.delete(key);
      url.searchParams.set('action', 'browse');
      url.searchParams.set('type', 'photo');
      if (filters.q) url.searchParams.set('q', filters.q);
      if (filters.source) url.searchParams.set('source', filters.source);
      if (filters.collection) url.searchParams.set('collection_id', filters.collection);
      if (before) url.searchParams.set('before', String(before));
      try {
        const response = await fetch(url.href, {method: 'GET', credentials: 'same-origin', cache: 'no-store',
          headers: {'Accept': 'application/json', 'X-VP3-Media-Owner': String(owner)}, signal: controller.signal});
        if (!current()) return;
        if (response.status === 401 || response.status === 403) { close('stale'); return; }
        const data = await response.json().catch(() => null);
        if (!current()) return;
        if (!response.ok || data?.ok !== true) {
          throw new Error(typeof data?.error === 'string' && data.error.trim() ? data.error.slice(0, 500) : 'Images could not be loaded. Please try again.');
        }
        if (!Array.isArray(data.assets)) throw new Error('Media Library returned an unexpected response. Please try again.');
        const cursor = data.next_before == null ? null : positiveId(data.next_before);
        if ((data.next_before != null && !cursor) || (before && cursor && cursor >= before)) {
          throw new Error('The next page could not be verified. Please try again.');
        }
        if (!renderCollections(data)) { applyFilters(true); return; }
        const fragment = document.createDocumentFragment();
        let firstNewCard = null;
        for (const raw of data.assets) {
          const id = positiveId(raw?.id);
          if (!id || records.has(id)) continue;
          const record = makeRecord(raw);
          records.set(id, record);
          fragment.append(record.item);
          if (!record.reason && !firstNewCard) firstNewCard = record.card;
        }
        grid.append(fragment);
        nextBefore = data.assets.length ? cursor : null;
        more.hidden = !nextBefore;
        refreshEmpty();
        const count = records.size;
        const noneEligible = count && ![...records.values()].some(record => !record.reason);
        status.textContent = count ? 'Showing ' + count + (count === 1 ? ' image.' : ' images.')
          + (noneEligible ? ' None can be used here; see the reasons below.' : nextBefore ? ' Load more to see earlier images.' : '')
          : 'No images found.';
        if (before && document.activeElement === more) {
          (firstNewCard || (nextBefore ? more : [...records.values()].find(record => !record.reason)?.card) || search).focus({preventScroll: false});
        }
      } catch (error) {
        if (!current()) return;
        const message = error instanceof TypeError ? 'Could not connect to Media Library. Check your connection and try again.'
          : errorMessage(error, 'Images could not be loaded. Please try again.');
        showError(message, {kind: 'browse', before, view});
        status.textContent = records.size ? 'Showing ' + records.size + ' images. More images could not be loaded.' : 'Images could not be loaded.';
      } finally {
        if (request === controller && !closed) {
          request = null;
          loading = false;
          grid.setAttribute('aria-busy', 'false');
          syncActions();
        }
      }
    }

    function applyFilters(immediate = false) {
      if (!ensureCurrent()) return;
      const next = {q: search.value.trim().slice(0, 190), source: source.value, collection: collection.value};
      if (!immediate && Object.keys(next).every(key => next[key] === filters[key])) return;
      filters = next;
      viewSerial++;
      requestSerial++;
      clearTimeout(searchTimer);
      request?.abort();
      request = null;
      cancelPending();
      candidate = null;
      records.clear();
      grid.replaceChildren();
      nextBefore = null;
      more.hidden = true;
      empty.hidden = true;
      clearError();
      renderPreview();
      loading = true;
      grid.setAttribute('aria-busy', 'true');
      status.textContent = filters.q ? 'Searching images…' : 'Loading images…';
      syncActions();
      if (immediate) void loadPage();
      else searchTimer = window.setTimeout(() => void loadPage(), 220);
    }

    async function useCandidate(action = actionSpecs[0]) {
      if (!ensureCurrent() || loading || pending || !candidate || candidate.reason || candidate.actionReasons[action.id]) return;
      const operation = {candidate, action, controller: new AbortController(), serial: ++operationSerial, view: viewSerial};
      pending = operation;
      clearError();
      status.textContent = 'Checking the selected image…';
      syncActions();
      try {
        const accepted = await onSelect(operation.candidate.asset, {
          isCurrent: () => operationCurrent(operation), signal: operation.controller.signal, action: action.id,
        });
        if (!operationCurrent(operation)) return;
        if (accepted === true) { close('selected'); return; }
        showError('This image could not be used. Try again or choose another image.', {kind: 'selection', action: action.id});
        status.textContent = 'Image was not attached.';
      } catch (error) {
        if (!operationCurrent(operation)) return;
        showError(errorMessage(error, 'This image could not be used. Try again or choose another image.'), {kind: 'selection', action: action.id});
        status.textContent = 'Image was not attached.';
      } finally {
        if (pending === operation) {
          pending = null;
          operation.controller.abort();
          syncActions();
        }
      }
    }

    function close(reason = 'cancelled') {
      if (closed) return;
      const current = scopeCurrent();
      const reported = ['selected', 'cancelled', 'stale', 'destroyed'].includes(reason) ? reason : 'cancelled';
      const actual = !current && (reported === 'selected' || reported === 'cancelled') ? 'stale' : reported;
      closed = true;
      viewSerial++;
      requestSerial++;
      clearTimeout(searchTimer);
      clearInterval(scopeTimer);
      request?.abort();
      request = null;
      cancelPending();
      observer?.disconnect();
      window.removeEventListener('focus', ensureCurrent);
      window.removeEventListener('pageshow', ensureCurrent);
      window.removeEventListener('popstate', ensureCurrent);
      window.removeEventListener('hashchange', ensureCurrent);
      window.removeEventListener('pagehide', leavePage);
      document.removeEventListener('visibilitychange', ensureCurrent);
      shell.remove();
      records.clear();
      candidate = null;
      if (instances.get(host) === handle) instances.delete(host);
      try { if (typeof onClose === 'function') onClose(actual); } catch (error) { console.error('Media Library close handler failed.', error); }
      // onClose normally clears the caller's session; the pre-close scope check is
      // authoritative. Respect any deliberate focus change made by that callback.
      const focusMoved = document.activeElement !== document.body && document.activeElement !== returnFocus
        && document.activeElement?.isConnected && !shell.contains(document.activeElement);
      // Stale or destroyed contexts never return focus to an old account, Agent or Chat.
      if (current && (actual === 'cancelled' || actual === 'selected') && returnFocus?.isConnected
        && !focusMoved && typeof returnFocus.focus === 'function' && !returnFocus.disabled && returnFocus.getClientRects().length) {
        returnFocus.focus({preventScroll: true});
      }
    }

    function leavePage() { close('stale'); }

    search.addEventListener('input', () => applyFilters());
    search.addEventListener('search', () => applyFilters(true));
    search.addEventListener('keydown', event => {
      if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); applyFilters(true); }
    });
    source.addEventListener('change', () => applyFilters(true));
    collection.addEventListener('change', () => applyFilters(true));
    reset.addEventListener('click', () => {
      search.value = ''; source.value = ''; collection.value = '';
      applyFilters(true);
      search.focus({preventScroll: true});
    });
    more.addEventListener('click', () => { if (nextBefore && !loading) void loadPage(nextBefore); });
    retry.addEventListener('click', () => {
      if (!ensureCurrent() || !retryContext) return;
      if (retryContext.kind === 'selection') void useCandidate(actionSpecs.find(action => action.id === retryContext.action));
      else if (retryContext.view === viewSerial) void loadPage(retryContext.before);
    });
    for (const {action, control} of actionControls) control.addEventListener('click', () => void useCandidate(action));
    cancel.addEventListener('click', () => close('cancelled'));
    shell.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close('cancelled'); }
    });
    grid.addEventListener('keydown', event => {
      if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)
        || event.altKey || event.ctrlKey || event.metaKey || !ensureCurrent()) return;
      const cards = [...records.values()].map(record => record.card).filter(card => !card.disabled);
      const index = cards.indexOf(document.activeElement);
      if (index < 0) return;
      const columns = Math.max(1, getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length);
      const step = event.key === 'ArrowUp' ? -columns : event.key === 'ArrowDown' ? columns : event.key === 'ArrowLeft' ? -1 : 1;
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? cards.length - 1 : Math.max(0, Math.min(cards.length - 1, index + step));
      event.preventDefault();
      event.stopPropagation();
      cards[next]?.focus();
    });

    const handle = Object.freeze({close, destroy: () => close('destroyed')});
    instances.set(host, handle);
    host.append(shell);
    renderPreview();
    if (!ensureCurrent()) return handle;
    observer = new MutationObserver(() => { ensureCurrent(); });
    observer.observe(document.documentElement, {childList: true, subtree: true});
    // The callback may depend on application state that changes without a DOM mutation.
    scopeTimer = window.setInterval(ensureCurrent, 200);
    window.addEventListener('focus', ensureCurrent);
    window.addEventListener('pageshow', ensureCurrent);
    window.addEventListener('popstate', ensureCurrent);
    window.addEventListener('hashchange', ensureCurrent);
    window.addEventListener('pagehide', leavePage);
    document.addEventListener('visibilitychange', ensureCurrent);
    applyFilters(true);
    queueMicrotask(() => { if (ensureCurrent()) search.focus({preventScroll: true}); });
    return handle;
  }

  window.VP3MediaLibraryPicker = Object.freeze({version: 1, open});
})();
