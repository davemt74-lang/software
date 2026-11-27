(() => {
  'use strict';
  const cfg = window.PROFILE_AGENT_PORTAL;
  const root = document.getElementById('profileImageSettings');
  if (!root || !cfg?.imageSettingsEndpoint || !Number.isSafeInteger(Number(cfg.ownerUserId)) || Number(cfg.ownerUserId) < 1) return;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const positive = value => Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : 0;
  const money = cents => (Number(cents || 0) / 100).toFixed(2);
  const sameOrigin = value => {
    try { const url = new URL(String(value || ''), location.href); return url.origin === location.origin && ['http:', 'https:'].includes(url.protocol) ? url.href : ''; }
    catch (_) { return ''; }
  };
  let state = null, selected = 0, requestVersion = 0, saving = false, suspended = false;
  const pendingRequests = new Set();
  let historyDestinationPending = location.hash === '#profileImageUseHistory';
  const drafts = new Map();
  root.innerHTML = '<div class="profile-agent-card"><h2>Free photo edits</h2><p role="status">Loading image settings…</p></div>';

  async function request(payload = null, collection = selected) {
    const endpoint = new URL(cfg.imageSettingsEndpoint, location.href);
    if (endpoint.origin !== location.origin) throw new Error('Image settings are unavailable.');
    if (collection) endpoint.searchParams.set('collection_id', String(collection));
    const controller = new AbortController(); pendingRequests.add(controller);
    const timer = setTimeout(() => controller.abort(), 15000);
    const options = {credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {'X-VP3-Media-Owner': String(cfg.ownerUserId)}};
    if (payload) {
      options.method = 'POST'; options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify({...payload, csrf_token: cfg.csrf});
    }
    try {
      const response = await fetch(endpoint, options);
      const body = await response.json().catch(() => null);
      if ([401,403].includes(response.status)) {
        requestVersion++; state = null; drafts.clear(); root.replaceChildren();
        const message = document.createElement('p'); message.setAttribute('role','alert');
        message.textContent = 'Your account or access changed. Reload Profile settings to continue.'; root.append(message);
      }
      if (!response.ok || !body?.ok) throw new Error(body?.error || 'Image settings could not be loaded.');
      return body;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error(payload
        ? 'Saving could not be confirmed. Your draft is still here. Reload settings to check the saved revision before retrying.'
        : 'Image settings took too long to respond. Try again.');
      throw error;
    } finally {
      clearTimeout(timer); pendingRequests.delete(controller);
    }
  }

  function notice(message = '', error = false) {
    const target = root.querySelector('[data-image-settings-notice]');
    if (target) { target.textContent = message; target.setAttribute('role', error ? 'alert' : 'status'); target.classList.toggle('error', error); }
  }

  function parsePrice(value, inherit = false) {
    const text = String(value ?? '').trim();
    if (inherit && text === '') return null;
    if (!/^\d{1,7}(?:\.\d{1,2})?$/.test(text)) throw new Error('Enter a price with at most two decimal places, such as 0.00.');
    const [whole, decimal = ''] = text.split('.');
    const cents = Number(whole) * 100 + Number(decimal.padEnd(2, '0'));
    if (!Number.isSafeInteger(cents) || cents < 0 || cents > 99999999) throw new Error('Enter a price between 0.00 and 999999.99.');
    return cents;
  }

  function settingsHtml() {
    const settings = state.settings || {};
    return `<form data-image-service-form data-revision="${Number(settings.revision || 0)}"><fieldset><legend>Free photo edits</legend><p>Let visitors edit their own photos using your approved image styles. Your configured AI allowance covers processing.</p>
      <label class="profile-agent-toggle"><input type="checkbox" name="enabled"${settings.enabled ? ' checked' : ''}> Offer free photo edits in Profile chat</label>
      <div class="profile-image-settings-limits"><label class="profile-agent-field"><span>Edits per day across your profile</span><input type="number" name="daily_limit" min="1" max="100" step="1" required value="${Number(settings.daily_limit || 10)}"></label>
      <label class="profile-agent-field"><span>Edits per visitor per day</span><input type="number" name="visitor_daily_limit" min="1" max="20" step="1" required value="${Number(settings.visitor_daily_limit || 3)}"></label></div>
      <p class="profile-image-settings-note">Daily limits reset at midnight UTC. Each new edit uses one allowance; viewing and downloading a saved result do not.</p>
      <button type="submit" class="primary">Save photo editing</button></fieldset></form>`;
  }

  function captureDraft() {
    const form = root.querySelector('[data-image-catalog-form]');
    if (!form || !selected) return;
    drafts.set(selected, {
      revision: Number(form.dataset.revision || 0),
      approved: form.elements.approved.checked,
      instructions: form.elements.style_instructions.value,
      price: form.elements.default_price.value,
      references: [...form.querySelectorAll('[data-style-asset]')].map(row => ({
        asset_id: positive(row.dataset.styleAsset), checked: row.querySelector('[data-style-approved]').checked,
        price: row.querySelector('[data-style-price]').value
      }))
    });
  }

  function renderCatalog() {
    const host = root.querySelector('[data-image-catalog-editor]');
    if (!host) return;
    const catalog = (state.collections || []).find(item => positive(item.id) === selected);
    if (!catalog) { host.innerHTML = '<p>Create a collection in your Media Library, then return here to approve its style examples.</p>'; return; }
    const draft = drafts.get(selected);
    const savedRefs = new Map((catalog.references || []).map(item => [positive(item.asset_id), item]));
    const draftRefs = new Map((draft?.references || []).map(item => [item.asset_id, item]));
    const assets = Array.isArray(state.assets) ? state.assets : [];
    const imageRows = assets.map(item => {
      const id = positive(item.asset_id || item.id), saved = savedRefs.get(id), current = draftRefs.get(id);
      const checked = current ? current.checked : !!saved;
      const price = current ? current.price : saved?.price_override_cents == null ? '' : money(saved.price_override_cents);
      const available = item.available !== false && /^image\/(?:jpeg|png|webp)$/.test(item.mime_type || '');
      const src = available ? sameOrigin(item.url) : '';
      return `<div class="profile-image-style-asset" data-style-asset="${id}">
        ${src ? `<img src="${esc(src)}" alt="" width="72" height="72" loading="lazy">` : '<span class="profile-image-style-placeholder" aria-hidden="true">Image</span>'}
        <div class="profile-image-style-detail"><label class="profile-agent-toggle"><input type="checkbox" data-style-approved data-style-available="${available ? '1' : '0'}"${checked ? ' checked' : ''}${available || checked ? '' : ' disabled'}> ${esc(item.title || saved?.title || 'Untitled image')}</label>
        <small>${available ? 'Approve this image as a style reference.' : esc(item.unavailable_reason || 'Choose a JPEG, PNG, or WebP image up to 4 MiB and 4096 pixels per side.')}</small></div>
        <label class="profile-agent-field profile-image-style-price"><span>Image price</span><input inputmode="decimal" data-style-price value="${esc(price)}" placeholder="Inherit catalog"${checked && available ? '' : ' disabled'} aria-label="Price for ${esc(item.title || 'image')}"></label></div>`;
    }).join('');
    host.innerHTML = `<form data-image-catalog-form data-revision="${Number(draft?.revision ?? catalog.revision ?? 0)}"><fieldset>
      <legend>${esc(catalog.name || 'Reference catalog')}</legend>
      <label class="profile-agent-toggle"><input type="checkbox" name="approved"${(draft ? draft.approved : catalog.approved) ? ' checked' : ''}> Make this catalog available for free photo edits</label>
      <label class="profile-agent-field"><span id="profileImageStyleInstructionsLabel">Style instructions</span><textarea name="style_instructions" aria-labelledby="profileImageStyleInstructionsLabel" maxlength="2000" placeholder="Describe the colors, lighting, textures, and treatment visitors can use.">${esc(draft?.instructions ?? catalog.style_instructions ?? '')}</textarea></label>
      <label class="profile-agent-field profile-image-catalog-price"><span>Default image price</span><input name="default_price" inputmode="decimal" value="${esc(draft?.price ?? money(catalog.default_price_cents))}" required></label>
      <p>Approve one to three style examples totaling 4 MiB or less. Approved images with a final price of <strong>0.00</strong> appear in your public Profile chat image collection, where visitors can view and select them for a photo edit. A blank image price inherits the catalog default; <strong>0.00</strong> makes that image free.</p>
      <div class="profile-image-style-assets">${imageRows || '<p>This collection has no images yet. Add images in your Media Library.</p>'}</div>
      ${state.assets_truncated ? '<p>Only the first images are shown. Organize the examples into a smaller Media Library collection to select others.</p>' : ''}
      <button type="submit" class="primary">Save reference catalog</button></fieldset></form>`;
  }

  function renderHistory(history = state?.history || {}) {
    const host = root.querySelector('[data-image-use-history]');
    if (!host) return;
    const records = Array.isArray(history.records) ? history.records : [];
    const labels = {queued:'Queued',editing:'Editing',running:'Editing',completed:'Completed',ready:'Completed',failed:'Could not complete',cancelled:'Cancelled',superseded:'Stopped after conversation changed'};
    host.replaceChildren();
    const heading = document.createElement('h3'); heading.textContent = 'Recent photo edits'; host.append(heading);
    if (historyDestinationPending) {
      historyDestinationPending = false;
      heading.tabIndex = -1;
      requestAnimationFrame(() => { if (heading.isConnected && location.hash === '#profileImageUseHistory') { heading.scrollIntoView({block: 'start'}); heading.focus({preventScroll: true}); } });
    }
    if (history.available === false) { const message = document.createElement('p'); message.textContent = 'Image activity requires Profile chat access.'; host.append(message); return; }
    const summary = document.createElement('p'); summary.textContent = `${Number(history.total_completed || 0).toLocaleString()} completed edits. Each record keeps the original, prompt, source references, and result together.`; host.append(summary);
    if (!records.length) { const empty = document.createElement('p'); empty.textContent = 'Visitor edits will appear here when the service is used.'; host.append(empty); }
    for (const record of records) {
      const row = document.createElement('article'); row.className = 'profile-image-use';
      const title = document.createElement('strong'); title.textContent = record.catalog_name || 'Reference catalog'; row.append(title);
      const details = document.createElement('span'); details.textContent = `${labels[record.status] || 'Saved'} · ${Number(record.source_count || 0)} style reference${Number(record.source_count || 0) === 1 ? '' : 's'}`; row.append(details);
      if (record.created_at) { const time = document.createElement('time'); time.textContent = String(record.created_at).replace('T', ' ').replace(/Z$/, ' UTC'); row.append(time); }
      const actions = document.createElement('div'); actions.className = 'profile-image-use-actions';
      for (const [label, value] of [['Original', record.input_url], ['Result', record.output_url]]) {
        const href = sameOrigin(value); if (!value || !href) continue;
        const link = document.createElement('a'); link.textContent = label; link.href = href; link.target = '_blank'; link.rel = 'noopener'; actions.append(link);
      }
      if (positive(record.conversation_id)) {
        const link = document.createElement('a'); link.textContent = 'Open conversation';
        const href = new URL(location.pathname, location.origin); href.searchParams.set('conversation', String(record.conversation_id)); link.href = href.href; actions.append(link);
      }
      row.append(actions); host.append(row);
      if (record.prompt || record.references?.length) {
        const detail = document.createElement('details'), summary = document.createElement('summary'); summary.textContent = 'Prompt and style references'; detail.append(summary);
        if (record.prompt) { const prompt = document.createElement('p'); prompt.textContent = record.prompt; detail.append(prompt); }
        if (Array.isArray(record.references) && record.references.length) { const refs = document.createElement('p'); refs.textContent = 'Style references: ' + record.references.map(ref => String(ref.title || 'Untitled image')).join('; '); detail.append(refs); }
        row.append(detail);
      }
    }
    const refresh = document.createElement('button'); refresh.type = 'button'; refresh.dataset.imageHistoryRefresh = ''; refresh.textContent = 'Refresh image activity'; host.append(refresh);
  }

  function renderShell() {
    if (state?.available === false) {
      root.innerHTML = `<div class="profile-agent-card"><h2>Free photo edits</h2><p>Run Database Upgrade to install photo editing, then reload these settings.</p><a href="${esc(cfg.upgradeUrl || '/upgrade.php')}">Open Database Upgrade</a></div>`;
      return;
    }
    root.innerHTML = `<div class="profile-agent-card profile-image-settings-card"><div data-image-service-settings>${settingsHtml()}</div>
      <section class="profile-image-catalog-settings"><h3>Reference catalogs</h3><p>Choose a Media Library collection and approve its example images. Free edits are tracked here without a payment step.</p>
      <a href="${esc(cfg.mediaLibraryUrl || '/media-library.php')}">Open Media Library</a>
      <label class="profile-agent-field"><span id="profileImageCollectionLabel">Collection</span><select data-image-catalog-select aria-labelledby="profileImageCollectionLabel"><option value="0">Choose a collection</option>${(state.collections || []).map(item => `<option value="${positive(item.id)}"${positive(item.id) === selected ? ' selected' : ''}>${esc(item.name)}</option>`).join('')}</select></label>
      <div data-image-catalog-editor></div></section><section id="profileImageUseHistory" data-image-use-history></section>
      <p class="profile-agent-notice" data-image-settings-notice role="status" aria-live="polite"></p></div>`;
    renderCatalog(); renderHistory();
  }

  async function load(collection = 0) {
    if (suspended) return;
    const version = ++requestVersion;
    try {
      const data = await request(null, collection);
      if (version !== requestVersion) return;
      state = data; selected = positive(data.selected_collection_id || collection);
      renderShell();
    } catch (error) {
      if (version !== requestVersion) return;
      root.innerHTML = '<div class="profile-agent-card"><h2>Free photo edits</h2><p role="alert" data-image-settings-error></p><button type="button" data-image-settings-retry>Retry image settings</button></div>';
      root.querySelector('[data-image-settings-error]').textContent = error.message;
    }
  }

  root.addEventListener('change', async event => {
    const picker = event.target.closest('[data-image-catalog-select]');
    if (picker) {
      if (saving) return;
      captureDraft(); const id = positive(picker.value), version = ++requestVersion;
      picker.disabled = true; notice('Loading collection…');
      try {
        const data = await request(null, id);
        if (version !== requestVersion) return;
        captureDraft();
        state = data; selected = id; renderCatalog(); renderHistory(); notice();
      } catch (error) { if (version === requestVersion) { picker.value = String(selected); notice(error.message, true); } }
      finally { if (version === requestVersion) picker.disabled = false; }
      return;
    }
    if (event.target.matches('[data-style-approved]')) {
      const form = event.target.closest('form');
      if (event.target.dataset.styleAvailable === '0') { event.target.checked = false; event.target.disabled = true; }
      if (form.querySelectorAll('[data-style-approved]:checked').length > 3) { event.target.checked = false; notice('Choose up to three style examples for this catalog.', true); }
      event.target.closest('[data-style-asset]').querySelector('[data-style-price]').disabled = !event.target.checked;
    }
  });

  root.addEventListener('submit', async event => {
    const form = event.target.closest('[data-image-service-form],[data-image-catalog-form]');
    if (!form) return; event.preventDefault(); if (saving) return;
    let payload;
    try {
      if (form.matches('[data-image-service-form]')) {
        payload = {action:'save_settings', expected_revision:Number(form.dataset.revision || 0), settings:{enabled:form.elements.enabled.checked,
          daily_limit:Number(form.elements.daily_limit.value), visitor_daily_limit:Number(form.elements.visitor_daily_limit.value)}};
      } else {
        captureDraft(); const draft = drafts.get(selected);
        const references = draft.references.filter(item => item.checked).map(item => ({asset_id:item.asset_id, price_override_cents:parsePrice(item.price, true)}));
        if (draft.approved && (references.length < 1 || references.length > 3)) throw new Error('Approve one to three images before making this catalog available.');
        payload = {action:'save_catalog', collection_id:selected, expected_revision:draft.revision, approved:draft.approved,
          style_instructions:draft.instructions, default_price_cents:parsePrice(draft.price), references};
      }
    } catch (error) { notice(error.message, true); return; }
    saving = true; const version = ++requestVersion, fieldset = form.querySelector('fieldset'); fieldset.disabled = true;
    const picker = root.querySelector('[data-image-catalog-select]'); if (picker) picker.disabled = true;
    notice('Saving…');
    try {
      const data = await request(payload);
      if (version !== requestVersion) return;
      state = data;
      if (payload.action === 'save_catalog') { drafts.delete(selected); renderCatalog(); }
      else root.querySelector('[data-image-service-settings]').innerHTML = settingsHtml();
      renderHistory(); notice(payload.action === 'save_catalog' ? 'Reference catalog saved.' : 'Photo editing settings saved.');
    } catch (error) { if (version === requestVersion) notice(error.message, true); }
    finally { saving = false; fieldset.disabled = false; if (picker) picker.disabled = false; }
  });

  root.addEventListener('click', async event => {
    if (event.target.closest('[data-image-settings-retry]')) { if (!saving) void load(selected); return; }
    const button = event.target.closest('[data-image-history-refresh]');
    if (!button || saving) return;
    button.disabled = true; const version = requestVersion;
    try { const data = await request(); if (version === requestVersion) { if (state) state.history = data.history; renderHistory(data.history); notice('Image activity refreshed.'); } }
    catch (error) { if (version === requestVersion) notice(error.message, true); }
    finally { button.disabled = false; }
  });
  window.addEventListener('pagehide', () => {
    captureDraft(); suspended = true; requestVersion++;
    for (const controller of pendingRequests) controller.abort();
    pendingRequests.clear();
  });
  window.addEventListener('pageshow', event => {
    if (event.persisted) { suspended = false; saving = false; void load(selected); }
  });
  void load();
})();
