(() => {
  'use strict';
  const root = document.getElementById('musicProviderSites');
  if (!root || root.dataset.enabled !== 'true') return;
  const byId = id => document.getElementById('musicProvider' + id);
  const endpoint = root.dataset.endpoint;
  const workspace = Number(root.dataset.workspace);
  const protocol = 'vp3_music_provider_v1';
  let sites = [], selectedSite = null, selected = new Set(), items = [], unavailableItems = [], nextOffset = null, search = '';
  let busy = false, pending = null, readGeneration = 0;
  const credential = () => 'vmp1_' + Array.from(crypto.getRandomValues(new Uint8Array(32)), byte => byte.toString(16).padStart(2, '0')).join('');
  const message = (text, error = false) => {
    byId('Status').textContent = text;
    byId('Status').classList.toggle('error', error);
  };
  const node = (tag, text, className) => {
    const result = document.createElement(tag);
    if (text !== undefined) result.textContent = text;
    if (className) result.className = className;
    return result;
  };
  const setBusy = value => {
    busy = value;
    root.querySelectorAll('button').forEach(button => { button.disabled = value; });
    byId('Retry').disabled = value;
  };
  const request = async input => {
    const serialized = JSON.stringify({protocol, workspace_id: workspace, csrf_token: root.dataset.csrf, ...input});
    let response;
    try { response = await fetch(endpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json'}, body: serialized}); }
    catch { const error = new Error('The response was interrupted. Retry the same request to check its saved result.'); error.retryable = true; throw error; }
    let data;
    try { data = await response.json(); }
    catch { const error = new Error('The server response could not be read. Retry the same request to check its saved result.'); error.retryable = true; throw error; }
    if (!response.ok || data.ok !== true || data.protocol !== protocol) {
      const error = new Error(data.message || 'The music site request could not be completed.');
      error.retryable = data.retryable === true;
      error.code = data.code;
      throw error;
    }
    return data;
  };
  const revealCredential = value => {
    byId('Credential').value = value;
    byId('Secret').hidden = false;
  };
  const updateSite = site => {
    const index = sites.findIndex(entry => entry.site_id === site.site_id);
    const previous = index < 0 ? null : sites[index];
    if (previous?.verification && previous.revision === site.revision && site.status === 'pending' && !site.verification) {
      site = {...site, verification: previous.verification};
    }
    if (index < 0) sites.push(site); else sites[index] = site;
    if (selectedSite?.site_id === site.site_id) selectedSite = site;
    renderSites();
  };
  const mutate = async (input, after, secret = null) => {
    if (busy) return;
    if (pending && pending.input !== input) {
      message('Retry the last request before starting another change. This preserves its original saved result.', true);
      return;
    }
    pending = {input, after, secret};
    byId('Retry').hidden = true;
    setBusy(true);
    message('Saving your music site…');
    try {
      const data = await request(input);
      pending = null;
      if (data.site) updateSite(data.site);
      if (secret) revealCredential(secret);
      try { await after(data); }
      catch { message('Your change was saved, but the view could not refresh. Refresh sites to read the saved state.', true); }
    } catch (error) {
      message(error.message, true);
      if (error.retryable) byId('Retry').hidden = false;
      else pending = null;
    } finally { setBusy(false); }
  };
  const renderSites = () => {
    const list = byId('SiteList');list.replaceChildren();
    if (!sites.length) {
      list.append(node('p', 'No artist sites are connected yet. Register your first site below.', 'music-sites-empty'));
      return;
    }
    for (const site of sites) {
      const card = node('article', undefined, 'music-sites-card');
      card.append(node('h3', site.label));
      const expired = site.credential_expires_ms <= Date.now();
      const status = expired && site.status === 'active' ? 'Credential expired' : ({active: 'Verified', pending: 'Verification needed', revoked: 'Access revoked'}[site.status] || 'Unavailable');
      card.append(node('span', status, 'music-sites-badge ' + (expired ? 'pending' : site.status)));
      card.append(node('p', site.origin));
      card.append(node('p', (site.environment === 'staging' ? 'Staging / testing' : 'Production') + ' · Credential expires ' + new Date(site.credential_expires_ms).toLocaleDateString()));
      const open = node('button', 'Manage site & songs', 'music-sites-button');
      open.type = 'button';open.disabled = busy;
      open.addEventListener('click', () => openSite(site));card.append(open);list.append(card);
    }
  };
  const updateCount = () => {
    byId('SelectedCount').textContent = selected.size + (selected.size === 1 ? ' song selected' : ' songs selected');
  };
  const renderUnavailable = () => {
    const list = byId('UnavailableList');list.replaceChildren();
    byId('Unavailable').hidden = unavailableItems.length === 0;
    for (const item of unavailableItems) {
      const label = node('label', undefined, 'music-sites-song');
      const checkbox = document.createElement('input');checkbox.type = 'checkbox';checkbox.value = item.recording_id;
      checkbox.checked = selected.has(item.recording_id);
      checkbox.addEventListener('change', () => {
        if (checkbox.checked) {checkbox.checked = false;message('Restore public availability before selecting this song again.', true);}
        selected.delete(item.recording_id);updateCount();renderCatalog();
      });
      const text = node('span');text.append(node('strong', item.metadata?.title || 'Unavailable selected recording'));
      text.append(node('small', 'This saved reference grants no playback while the recording is unavailable.'));
      label.append(checkbox, text);list.append(label);
    }
  };
  const renderCatalog = () => {
    const list = byId('Catalog');list.replaceChildren();
    if (!items.length) list.append(node('p', 'No published public songs match this view. Publish a song in the Music Library before selecting it.', 'music-sites-empty'));
    for (const item of items) {
      const label = node('label', undefined, 'music-sites-song');
      const checkbox = document.createElement('input');checkbox.type = 'checkbox';checkbox.value = item.recording_id;
      checkbox.checked = selected.has(item.recording_id);checkbox.disabled = item.availability !== 'ready' && !selected.has(item.recording_id);
      checkbox.addEventListener('change', () => {
        if (checkbox.checked) selected.add(item.recording_id); else selected.delete(item.recording_id);
        updateCount();
      });
      const text = node('span');text.append(node('strong', item.metadata?.title || 'Untitled recording'));
      const details = [item.metadata?.album, item.track_number ? 'Track ' + item.track_number : '', item.availability === 'ready' ? 'Ready for public streaming' : 'Audio needs attention'].filter(Boolean);
      text.append(node('small', details.join(' · ')));label.append(checkbox, text);list.append(label);
    }
    byId('LoadMore').hidden = nextOffset === null;
    updateCount();renderUnavailable();
  };
  const renderDetail = () => {
    if (!selectedSite) return;
    byId('Detail').hidden = false;
    byId('DetailTitle').textContent = selectedSite.label;
    byId('DetailOrigin').textContent = selectedSite.origin;
    byId('NewOrigin').value = selectedSite.origin;
    const proof = selectedSite.verification;
    byId('Verification').hidden = !proof;
    if (proof) {
      byId('VerificationUrl').textContent = proof.url;
      byId('VerificationJson').textContent = JSON.stringify(proof.response, null, 2);
    }
  };
  const loadCatalog = async (append = false) => {
    if (!selectedSite) return;
    const current = selectedSite.site_id;
    const generation = ++readGeneration;
    const data = await request({action: 'catalog.read', site_id: current, offset: append ? nextOffset : 0, limit: 50, search});
    if (generation !== readGeneration || selectedSite?.site_id !== current) return;
    items = append ? [...items, ...data.items] : data.items;
    nextOffset = data.next_offset;
    renderCatalog();
  };
  const loadUnavailable = async () => {
    if (!selectedSite || !selected.size) {unavailableItems = [];renderUnavailable();return;}
    const current = selectedSite.site_id, found = [];
    let offset = 0;
    for (let page = 0; page < 10 && offset !== null; page++) {
      const data = await request({action: 'import.manifest', site_id: current, offset, limit: 50});
      if (selectedSite?.site_id !== current) return;
      found.push(...data.items.filter(item => item.availability !== 'ready'));
      offset = data.next_offset;
    }
    unavailableItems = found;renderUnavailable();
  };
  const openSite = async site => {
    if (busy) return;
    if (pending) { message('Retry the last request before changing the selected site.', true); return; }
    setBusy(true);
    selectedSite = site;selected = new Set();items = [];unavailableItems = [];nextOffset = null;search = '';
    byId('SearchText').value = '';
    renderDetail();renderCatalog();
    try {
      const data = await request({action: 'catalog.read', site_id: site.site_id, offset: 0, limit: 50, search: ''});
      // Keep the owner's verification details; the catalog response is the safe
      // backend projection and intentionally never carries that challenge.
      selectedSite = {...site, ...data.site, verification: site.revision === data.site.revision ? site.verification : undefined};
      selected = new Set(data.recording_ids);items = data.items;nextOffset = data.next_offset;
      renderDetail();renderCatalog();await loadUnavailable();message('Review this site’s songs and connection settings.');
      byId('Detail').scrollIntoView({behavior: 'smooth', block: 'start'});
    } catch (error) { message(error.message, true); }
    finally { setBusy(false); }
  };
  const refresh = async () => {
    if (busy) return;
    setBusy(true);
    try {
      const data = await request({action: 'site.list'});sites = data.sites;renderSites();
      if (selectedSite) {
        const current = sites.find(site => site.site_id === selectedSite.site_id);
        if (!current || current.revision !== selectedSite.revision || current.selection_revision !== selectedSite.selection_revision) {
          selectedSite = null;readGeneration++;byId('Detail').hidden = true;
          message('The connection or selection changed. Open the site again to review its current songs.');
          return;
        }
        selectedSite = current;renderDetail();
      }
      message(sites.length ? 'Your registered sites are up to date.' : 'Register an artist site to get started.');
    } catch (error) {message(error.message, true);}
    finally {setBusy(false);}
  };
  const lifecycle = (action, extra = {}, secret = null) => {
    if (!selectedSite) return;
    mutate({action, site_id: selectedSite.site_id, request_id: crypto.randomUUID(), expected_revision: selectedSite.revision, ...extra}, async data => {
      selectedSite = data.site;renderDetail();
      message(action === 'site.verify' ? 'Site verified. Its selected public songs can now stream.' : action === 'site.revoke' ? 'Site access revoked. New requests and renewals are blocked.' : 'Connection updated. Save the new credential and verify the site again.');
    }, secret);
  };
  byId('Create').addEventListener('submit', event => {
    event.preventDefault();if (busy) return;
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const fields = new FormData(form), secret = credential();
    mutate({action: 'site.create', request_id: crypto.randomUUID(), label: fields.get('label').trim(), origin: fields.get('origin').trim(), environment: fields.get('environment'), credential: secret}, async data => {
      selectedSite = data.site;selected = new Set();items = [];unavailableItems = [];nextOffset = null;search = '';
      form.reset();renderDetail();await loadCatalog();
      message('Site registered. Save its credential, verify its address, and choose its songs.');
    }, secret);
  });
  byId('Retry').addEventListener('click', () => {if (pending) mutate(pending.input, pending.after, pending.secret);});
  byId('Refresh').addEventListener('click', refresh);
  byId('CloseDetail').addEventListener('click', () => {selectedSite = null;readGeneration++;byId('Detail').hidden = true;});
  byId('CopyCredential').addEventListener('click', async () => {
    try {await navigator.clipboard.writeText(byId('Credential').value);message('Site credential copied. Keep it in the artist site’s private server settings.');}
    catch {byId('Credential').focus();byId('Credential').select();message('Select and copy the credential from this field.');}
  });
  byId('HideCredential').addEventListener('click', () => {byId('Credential').value = '';byId('Secret').hidden = true;});
  byId('Verify').addEventListener('click', () => lifecycle('site.verify'));
  byId('Rotate').addEventListener('click', () => {
    if (!confirm('Reconnect this site? Current streams stop, and the site will need its new credential and a fresh verification.')) return;
    const secret = credential();lifecycle('site.rotate', {credential: secret}, secret);
  });
  byId('Revoke').addEventListener('click', () => {
    if (confirm('Revoke this site’s music access? Its song references remain saved, but current and new playback requests will be blocked.')) lifecycle('site.revoke');
  });
  byId('OriginForm').addEventListener('submit', event => {
    event.preventDefault();if (!event.currentTarget.reportValidity()) return;
    if (!confirm('Move this connection? The old address loses access immediately. Verify the new address before streaming again.')) return;
    const secret = credential();lifecycle('site.change_origin', {origin: byId('NewOrigin').value.trim(), credential: secret}, secret);
  });
  byId('Search').addEventListener('submit', async event => {
    event.preventDefault();if (busy) return;
    setBusy(true);search = byId('SearchText').value.trim();
    try {await loadCatalog();} catch (error) {message(error.message, true);} finally {setBusy(false);}
  });
  byId('LoadMore').addEventListener('click', async () => {
    if (busy || nextOffset === null) return;
    setBusy(true);try {await loadCatalog(true);} catch (error) {message(error.message, true);} finally {setBusy(false);}
  });
  byId('SelectDisplayed').addEventListener('click', () => {for (const item of items) if (item.availability === 'ready') selected.add(item.recording_id);renderCatalog();});
  byId('ClearDisplayed').addEventListener('click', () => {for (const item of items) selected.delete(item.recording_id);renderCatalog();});
  byId('ClearAll').addEventListener('click', () => {selected.clear();renderCatalog();message('Selection cleared in this view. Save song selection to apply the change.');});
  byId('SaveSelection').addEventListener('click', () => {
    if (!selectedSite) return;
    if (selected.size > 500) {message('Choose up to 500 songs for one site.', true);return;}
    mutate({action: 'selection.update', site_id: selectedSite.site_id, request_id: crypto.randomUUID(), expected_selection_revision: selectedSite.selection_revision, recording_ids: [...selected].sort()}, async data => {
      selectedSite = {...selectedSite, ...data.site};selected = new Set(data.recording_ids);unavailableItems = unavailableItems.filter(item => selected.has(item.recording_id));renderDetail();renderCatalog();message('Song selection saved. New sessions use this explicit song list.');
    });
  });
  refresh();
})();
