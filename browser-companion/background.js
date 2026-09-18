const VP3_DEFAULT_BASE = 'https://vp3.me';
const VP3_CONTRACT_VERSION = '1';
const VP3_EXTENSION_VERSION = '21.00.0';
const VP3_REQUESTED_CAPABILITIES = [
  'team.destinations.read',
  'team.share.create',
  'team.chat.read',
  'agent.message',
  'knowledge.write',
  'task.propose'
];
const VP3_MEDIA_CLIP_MAX_SECONDS = 90;

const storage = {
  async get(keys = null) { return chrome.storage.local.get(keys); },
  async set(values) { return chrome.storage.local.set(values); },
  async remove(keys) { return chrome.storage.local.remove(keys); }
};

function cleanBaseUrl(value) {
  const raw = String(value || VP3_DEFAULT_BASE).trim().replace(/\/+$/, '');
  let parsed;
  try { parsed = new URL(raw); } catch { throw new Error('Enter a valid VP3 URL.'); }
  const local = parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1';
  if (parsed.protocol !== 'https:' && !(local && parsed.protocol === 'http:')) {
    throw new Error('VP3 must use HTTPS. HTTP is allowed only for localhost development.');
  }
  if (parsed.username || parsed.password || parsed.search || parsed.hash) throw new Error('VP3 URL must contain only the site origin.');
  return parsed.origin;
}

function apiUrl(base, path) { return `${cleanBaseUrl(base)}${path}`; }
function uuid() { return crypto.randomUUID(); }
function utf8Limit(value, maxBytes) {
  const text = String(value || '');
  const encoder = new TextEncoder();
  if (encoder.encode(text).length <= maxBytes) return text;
  let bytes = 0;
  let result = '';
  for (const character of text) {
    const size = encoder.encode(character).length;
    if (bytes + size > maxBytes) break;
    bytes += size;
    result += character;
  }
  return result;
}

function secondsLabel(value) {
  const seconds = Math.max(0, Math.floor(Number(value || 0)));
  const minutes = Math.floor(seconds / 60);
  return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
}

function base64UrlJson(value) {
  const bytes = new TextEncoder().encode(JSON.stringify(value || {}));
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function dataUrlBytes(dataUrl) {
  const match = /^data:([^;,]+);base64,(.+)$/i.exec(String(dataUrl || ''));
  if (!match) throw new Error('Captured media is invalid.');
  const binary = atob(match[2]);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
  return { mime: match[1].toLowerCase(), bytes };
}

async function ensureInstallation() {
  const state = await storage.get(['installation_id']);
  if (state.installation_id) return state.installation_id;
  const installation_id = uuid();
  await storage.set({ installation_id });
  return installation_id;
}

async function config() {
  const state = await storage.get(['base_url']);
  return { base_url: cleanBaseUrl(state.base_url || VP3_DEFAULT_BASE) };
}

async function ensureOriginPermission(baseUrl) {
  const origin = `${new URL(cleanBaseUrl(baseUrl)).origin}/*`;
  if (origin === 'https://vp3.me/*') return true;
  const has = await chrome.permissions.contains({ origins: [origin] });
  if (has) return true;
  return chrome.permissions.request({ origins: [origin] });
}

async function fetchJson(path, options = {}) {
  const { base_url } = await config();
  const allowed = await ensureOriginPermission(base_url);
  if (!allowed) throw new Error('Permission to connect to this VP3 installation was not granted.');
  const headers = new Headers(options.headers || {});
  headers.set('X-VP3-Extension-Version', VP3_EXTENSION_VERSION);
  headers.set('X-VP3-Contract-Version', VP3_CONTRACT_VERSION);
  if (options.json !== undefined) {
    headers.set('Content-Type', 'application/json');
    options.body = JSON.stringify(options.json);
  }
  const response = await fetch(apiUrl(base_url, path), { ...options, headers, cache: 'no-store' });
  let payload = null;
  try { payload = await response.json(); } catch { payload = null; }
  if (!response.ok || !payload?.ok) {
    const message = payload?.error?.message || payload?.message || `VP3 request failed (${response.status}).`;
    const error = new Error(message);
    error.status = response.status;
    error.code = payload?.error?.code || payload?.error || 'request_failed';
    throw error;
  }
  return payload;
}

async function clearRevokedConnection() {
  await storage.remove(['device_id', 'device_credential', 'connected_user', 'approved_capabilities', 'pending_connection', 'session', 'last_share', 'pending_capture', 'release_state', 'compatibility']);
}

async function session(force = false) {
  const state = await storage.get(['device_id', 'device_credential', 'installation_id', 'session']);
  if (!state.device_id || !state.device_credential || !state.installation_id) throw new Error('Connect this browser to VP3 first.');
  const current = state.session;
  if (!force && current?.access_token && current?.expires_at) {
    const expires = Date.parse(current.expires_at);
    if (Number.isFinite(expires) && expires > Date.now() + 60_000) return current;
  }
  let payload;
  try {
    payload = await fetchJson('/api/extension-session.php', {
      method: 'POST',
      json: {
        device_id: state.device_id,
        installation_id: state.installation_id,
        device_credential: state.device_credential
      }
    });
  } catch (error) {
    if (error.status === 401 || error.code === 'reconnect_required' || error.code === 'authentication_required') {
      await clearRevokedConnection();
      const revoked = new Error('This browser connection was revoked or expired. Reconnect to VP3.');
      revoked.status = 401;
      revoked.code = 'reconnect_required';
      throw revoked;
    }
    throw error;
  }
  await storage.set({ session: payload.session, release_state: payload.annotated || null, compatibility: payload.compatibility || null });
  return payload.session;
}

async function authorizedFetch(path, options = {}, capability = '') {
  let current = await session(false);
  if (capability && !Array.isArray(current.capabilities)) current.capabilities = [];
  if (capability && !current.capabilities.includes(capability)) throw new Error(`This browser connection does not have ${capability} permission.`);
  const call = async (token) => fetchJson(path, {
    ...options,
    headers: { ...(options.headers || {}), Authorization: `Bearer ${token}` }
  });
  try { return await call(current.access_token); }
  catch (error) {
    if (error.status !== 401) throw error;
    current = await session(true);
    return call(current.access_token);
  }
}

async function authorizedMediaDataUrl(path) {
  let current = await session(false);
  const call = async (token) => {
    const { base_url } = await config();
    const response = await fetch(apiUrl(base_url, path), {
      method: 'GET',
      headers: {
        Authorization: `Bearer ${token}`,
        'X-VP3-Extension-Version': VP3_EXTENSION_VERSION,
        'X-VP3-Contract-Version': VP3_CONTRACT_VERSION
      },
      cache: 'no-store',
      credentials: 'omit'
    });
    if (!response.ok) {
      const error = new Error('Browser Share media could not be loaded.');
      error.status = response.status;
      throw error;
    }
    const blob = await response.blob();
    if (blob.size > 16 * 1024 * 1024) throw new Error('Browser Share media is too large to preview.');
    const bytes = new Uint8Array(await blob.arrayBuffer());
    let binary = '';
    for (let offset = 0; offset < bytes.length; offset += 0x8000) {
      binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
    }
    return `data:${blob.type || 'application/octet-stream'};base64,${btoa(binary)}`;
  };
  try { return await call(current.access_token); }
  catch (error) {
    if (error.status !== 401) throw error;
    current = await session(true);
    return call(current.access_token);
  }
}

async function activeTabIdentity() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || !/^https?:/i.test(String(tab.url || ''))) return { available: false, source_url: '' };
  return {
    available: true,
    tab_id: tab.id,
    window_id: tab.windowId,
    source_url: String(tab.url || ''),
    title: String(tab.title || '').slice(0, 512)
  };
}

async function activeCapture(tabHint = null) {
  let tab = tabHint;
  if (!tab?.id) [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || !/^https?:/i.test(String(tab.url || ''))) return { available: false, reason: 'Open a normal web page to capture content.' };
  let result = { selected_text: '', canonical_url: '', media: null };
  try {
    const injected = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: async () => {
        const selected_text = String(window.getSelection?.() || '').trim();
        const canonical_url = document.querySelector('link[rel="canonical"]')?.href || '';
        const pageText = String(document.body?.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 1000000);
        let page_text_sha256 = '';
        if (pageText) {
          const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(pageText));
          page_text_sha256 = [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, '0')).join('');
        }
        const candidate = document.querySelector('video, audio');
        let media = null;
        if (candidate) {
          const pageUrl = location.href;
          const rawSrc = candidate.currentSrc || candidate.src || '';
          const sourceUrl = /^https?:/i.test(rawSrc) ? rawSrc : pageUrl;
          const isYoutube = /(^|\.)youtube\.com$/i.test(location.hostname) || /(^|\.)youtu\.be$/i.test(location.hostname);
          const tag = candidate.tagName.toLowerCase();
          media = {
            kind: isYoutube ? 'youtube_clip' : (tag === 'audio' ? 'audio_reference' : 'video_reference'),
            source_media_url: isYoutube ? pageUrl : sourceUrl,
            source_media_title: document.title || '',
            source_media_kind: isYoutube ? 'youtube' : tag,
            current_time: Number.isFinite(candidate.currentTime) ? candidate.currentTime : 0,
            duration: Number.isFinite(candidate.duration) ? candidate.duration : 0,
            paused: Boolean(candidate.paused)
          };
        }
        return { selected_text, canonical_url, page_text_sha256, media };
      }
    });
    result = injected?.[0]?.result || result;
  } catch {
    // Chrome blocks injection on privileged pages. Tab metadata remains usable.
  }
  return {
    available: true,
    tab_id: tab.id,
    window_id: tab.windowId,
    source_url: tab.url || '',
    canonical_url: result.canonical_url || tab.url || '',
    title: String(tab.title || '').slice(0, 512),
    selected_text: utf8Limit(String(result.selected_text || ''), 32768),
    page_text_sha256: /^[a-f0-9]{64}$/i.test(String(result.page_text_sha256 || '')) ? String(result.page_text_sha256).toLowerCase() : '',
    media: result.media || null,
    captured_at: new Date().toISOString()
  };
}

async function selectScreenshotRegion() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || !/^https?:/i.test(String(tab.url || ''))) throw new Error('Open a normal web page before capturing a screenshot.');
  const injected = await chrome.scripting.executeScript({
    target: { tabId: tab.id },
    func: () => new Promise((resolve) => {
      document.getElementById('vp3-region-capture-overlay')?.remove();
      const overlay = document.createElement('div');
      overlay.id = 'vp3-region-capture-overlay';
      Object.assign(overlay.style, { position:'fixed', inset:'0', zIndex:'2147483647', cursor:'crosshair', background:'rgba(0,0,0,.18)', userSelect:'none' });
      const hint = document.createElement('div');
      hint.textContent = 'Drag to capture a region · Esc to cancel';
      Object.assign(hint.style, { position:'fixed', top:'16px', left:'50%', transform:'translateX(-50%)', background:'#111', color:'#fff', padding:'8px 12px', borderRadius:'8px', font:'13px system-ui', pointerEvents:'none' });
      const box = document.createElement('div');
      Object.assign(box.style, { position:'fixed', border:'2px solid #fff', background:'rgba(255,255,255,.10)', boxShadow:'0 0 0 9999px rgba(0,0,0,.18)', display:'none', pointerEvents:'none' });
      overlay.append(hint, box);
      document.documentElement.append(overlay);
      let startX = 0, startY = 0, dragging = false;
      const cleanup = (value) => { window.removeEventListener('keydown', onKey, true); overlay.remove(); resolve(value); };
      const onKey = (event) => { if (event.key === 'Escape') { event.preventDefault(); cleanup(null); } };
      window.addEventListener('keydown', onKey, true);
      overlay.addEventListener('pointerdown', (event) => {
        dragging = true; startX = event.clientX; startY = event.clientY; box.style.display = 'block'; overlay.setPointerCapture(event.pointerId);
      });
      overlay.addEventListener('pointermove', (event) => {
        if (!dragging) return;
        const x = Math.min(startX, event.clientX), y = Math.min(startY, event.clientY);
        const width = Math.abs(event.clientX - startX), height = Math.abs(event.clientY - startY);
        Object.assign(box.style, { left:`${x}px`, top:`${y}px`, width:`${width}px`, height:`${height}px` });
      });
      overlay.addEventListener('pointerup', (event) => {
        if (!dragging) return;
        dragging = false;
        const x = Math.max(0, Math.min(startX, event.clientX));
        const y = Math.max(0, Math.min(startY, event.clientY));
        const width = Math.abs(event.clientX - startX);
        const height = Math.abs(event.clientY - startY);
        if (width < 8 || height < 8) return cleanup(null);
        cleanup({ x, y, width, height, device_pixel_ratio: window.devicePixelRatio || 1, viewport_width: innerWidth, viewport_height: innerHeight });
      });
    })
  });
  const rect = injected?.[0]?.result;
  if (!rect) return { cancelled: true };
  const dataUrl = await chrome.tabs.captureVisibleTab(tab.windowId, { format: 'png' });
  const source = await fetch(dataUrl);
  const bitmap = await createImageBitmap(await source.blob());
  const viewportWidth = Math.max(1, Number(rect.viewport_width || 0));
  const viewportHeight = Math.max(1, Number(rect.viewport_height || 0));
  const fallbackScale = Math.max(0.5, Number(rect.device_pixel_ratio || 1));
  const scaleX = Number.isFinite(bitmap.width / viewportWidth) ? bitmap.width / viewportWidth : fallbackScale;
  const scaleY = Number.isFinite(bitmap.height / viewportHeight) ? bitmap.height / viewportHeight : fallbackScale;
  const sx = Math.min(bitmap.width - 1, Math.max(0, Math.round(rect.x * scaleX)));
  const sy = Math.min(bitmap.height - 1, Math.max(0, Math.round(rect.y * scaleY)));
  const sw = Math.min(bitmap.width - sx, Math.max(1, Math.round(rect.width * scaleX)));
  const sh = Math.min(bitmap.height - sy, Math.max(1, Math.round(rect.height * scaleY)));
  const canvas = new OffscreenCanvas(sw, sh);
  const context = canvas.getContext('2d');
  context.drawImage(bitmap, sx, sy, sw, sh, 0, 0, sw, sh);
  bitmap.close();
  const blob = await canvas.convertToBlob({ type: 'image/png' });
  if (blob.size > 8 * 1024 * 1024) throw new Error('Captured screenshot is larger than 8 MB. Select a smaller region.');
  const array = new Uint8Array(await blob.arrayBuffer());
  let binary = '';
  for (let i = 0; i < array.length; i += 0x8000) binary += String.fromCharCode(...array.subarray(i, Math.min(i + 0x8000, array.length)));
  return {
    cancelled: false,
    data_url: `data:image/png;base64,${btoa(binary)}`,
    metadata: { width: sw, height: sh, device_pixel_ratio: Number(rect.device_pixel_ratio || 1), capture_scale_x: scaleX, capture_scale_y: scaleY, x: rect.x, y: rect.y }
  };
}

async function beginConnect(deviceName) {
  const installation_id = await ensureInstallation();
  const payload = await fetchJson('/api/extension-connect-request.php', {
    method: 'POST',
    json: {
      contract_version: 1,
      installation_id,
      device_name: String(deviceName || 'Chrome').slice(0, 120),
      browser_family: 'Chrome',
      extension_version: VP3_EXTENSION_VERSION,
      requested_capabilities: VP3_REQUESTED_CAPABILITIES
    }
  });
  const pending = payload.connection_request;
  await storage.set({ pending_connection: pending });
  await chrome.tabs.create({ url: pending.approval_url });
  return pending;
}

async function pollConnect() {
  const state = await storage.get(['installation_id', 'pending_connection']);
  const pending = state.pending_connection;
  if (!pending?.id || !pending?.poll_token || !state.installation_id) return { status: 'none' };
  const payload = await fetchJson('/api/extension-connect-status.php', {
    method: 'POST',
    json: {
      connection_request_id: pending.id,
      poll_token: pending.poll_token,
      installation_id: state.installation_id
    }
  });
  if (payload.status === 'approved' && payload.device_id && payload.device_credential) {
    await storage.set({
      device_id: payload.device_id,
      device_credential: payload.device_credential,
      connected_user: payload.user || null,
      approved_capabilities: payload.capabilities || [],
      pending_connection: null,
      session: null,
      release_state: payload.annotated || null,
      compatibility: payload.compatibility || null
    });
    const issued = await session(true);
    return { ...payload, session: issued };
  }
  if (['denied', 'expired'].includes(payload.status) || payload.reconnect_required) await storage.set({ pending_connection: null });
  return payload;
}

async function destinations() {
  return authorizedFetch('/api/extension-share-destinations.php', { method: 'GET' }, 'team.destinations.read');
}

async function thisPage(capture, cursor = '') {
  if (!capture?.available || !/^https?:\/\//i.test(String(capture.source_url || ''))) {
    return { source: null, items: [], next_cursor: '', has_more: false };
  }
  const query = new URLSearchParams({
    action: 'this_page',
    url: String(capture.source_url || ''),
    canonical_url: String(capture.canonical_url || ''),
    title: String(capture.title || '').slice(0, 512),
    source_version_hash: String(capture.page_text_sha256 || ''),
    limit: '25'
  });
  if (cursor) query.set('cursor', cursor);
  return (await authorizedFetch('/api/browser-source-feed-v2050.php?' + query.toString(), { method: 'GET' }, 'team.chat.read')).feed;
}

async function followingFeed(cursor = '') {
  const query = new URLSearchParams({ action: 'following', limit: '20' });
  if (cursor) query.set('cursor', cursor);
  return (await authorizedFetch('/api/browser-source-feed-v2050.php?' + query.toString(), { method: 'GET' }, 'team.chat.read')).feed;
}

async function sourceFeedAction(action, payload = {}) {
  const capability = ['save', 'research'].includes(action)
    ? 'knowledge.write'
    : ['publish', 'share_team'].includes(action)
      ? 'team.share.create'
      : 'team.chat.read';
  return authorizedFetch('/api/browser-source-feed-v2050.php', {
    method: 'POST',
    json: { action, ...payload }
  }, capability);
}

async function researchContext(browserShareId) {
  const query = new URLSearchParams({
    action: 'placements',
    browser_share_id: String(browserShareId || '')
  });
  return (await authorizedFetch('/api/research-projects-v2060.php?' + query.toString(), { method: 'GET' }, 'team.chat.read')).context;
}

async function researchAction(action, payload = {}) {
  return authorizedFetch('/api/research-projects-v2060.php', {
    method: 'POST',
    json: { action, ...payload }
  }, 'knowledge.write');
}

async function liveRoomsForSource(capture) {
  if (!capture?.available || !/^https?:\/\//i.test(String(capture.source_url || ''))) {
    return { source: null, rooms: [] };
  }
  const query = new URLSearchParams({
    action: 'source_rooms',
    url: String(capture.source_url || ''),
    canonical_url: String(capture.canonical_url || ''),
    title: String(capture.title || '').slice(0, 512)
  });
  return authorizedFetch('/api/live-rooms-v2070.php?' + query.toString(), { method: 'GET' }, 'team.chat.read');
}

async function liveRoomPoll(roomId, after = 0) {
  const query = new URLSearchParams({
    action: 'poll',
    room: String(roomId || ''),
    after: String(Math.max(0, Number(after || 0)))
  });
  return authorizedFetch('/api/live-rooms-v2070.php?' + query.toString(), { method: 'GET' }, 'team.chat.read');
}

async function liveRoomAction(action, payload = {}) {
  const capability = ['create', 'send', 'end'].includes(action) ? 'team.share.create' : 'team.chat.read';
  return authorizedFetch('/api/live-rooms-v2070.php', {
    method: 'POST',
    json: { action, ...payload }
  }, capability);
}


async function browserTrustGet(action, params = {}) {
  const query = new URLSearchParams({ action, ...Object.fromEntries(Object.entries(params).map(([k,v]) => [k, String(v ?? '')])) });
  return authorizedFetch('/api/browser-trust-v2080.php?' + query.toString(), { method: 'GET' }, 'team.chat.read');
}

async function browserTrustAction(action, payload = {}) {
  const capability = ['claim_create', 'claim_status'].includes(action) ? 'team.share.create' : 'team.chat.read';
  return authorizedFetch('/api/browser-trust-v2080.php', {
    method: 'POST',
    json: { action, ...payload }
  }, capability);
}

async function observeSource(capture) {
  if (!capture?.available || !/^https?:\/\//i.test(String(capture.source_url || '')) || !/^[a-f0-9]{64}$/i.test(String(capture.page_text_sha256 || ''))) {
    return { changed: false, source: null, changes: [] };
  }
  return browserTrustAction('observe_source', {
    url: String(capture.source_url || ''),
    canonical_url: String(capture.canonical_url || capture.source_url || ''),
    title: String(capture.title || '').slice(0, 512),
    source_version_hash: String(capture.page_text_sha256 || '').toLowerCase()
  });
}


async function browserSearchGet(action, params = {}) {
  const query = new URLSearchParams({ action, ...Object.fromEntries(Object.entries(params).filter(([,v]) => v !== undefined && v !== null && v !== '').map(([k,v]) => [k, String(v)])) });
  return authorizedFetch('/api/search-v2090.php?' + query.toString(), { method: 'GET' }, 'team.chat.read');
}

async function browserSearchAction(action, payload = {}) {
  return authorizedFetch('/api/search-v2090.php', { method: 'POST', json: { action, ...payload } }, 'team.chat.read');
}

function fallbackSelection(capture, rich = {}) {
  const selected = utf8Limit(String(capture?.selected_text || '').trim(), 32768);
  if (selected) return selected;
  const title = String(capture?.title || 'this page').trim() || 'this page';
  if (rich.screenshot?.data_url) return `Screenshot captured from ${title}.`;
  if (rich.media_reference?.kind) {
    const start = Number(rich.media_reference.metadata?.start_seconds || 0);
    const end = Number(rich.media_reference.metadata?.end_seconds || start);
    return `Media clip ${secondsLabel(start)}–${secondsLabel(end)} captured from ${title}.`;
  }
  if (rich.commentary?.data_url) return `Voice commentary captured from ${title}.`;
  return '';
}

async function createShare(input) {
  const capture = input?.capture || {};
  const rich = input?.rich_media || {};
  const selection = fallbackSelection(capture, rich);
  if (!selection) throw new Error('Highlight text or add a rich capture before sharing.');
  const destination = input?.destination || {};
  if (!['team_general', 'conversation'].includes(destination.kind) || !Number(destination.id)) throw new Error('Choose a VP3 destination.');
  const sourceUrl = String(capture.source_url || '');
  const canonicalUrl = String(capture.canonical_url || sourceUrl);
  if (!/^https?:\/\//i.test(sourceUrl) || !/^https?:\/\//i.test(canonicalUrl)) throw new Error('This page cannot be shared.');
  const idempotencyKey = input.idempotency_key || uuid();
  const payload = await authorizedFetch('/api/browser-share.php', {
    method: 'POST',
    headers: { 'X-VP3-Idempotency-Key': idempotencyKey },
    json: {
      schema_version: 1,
      share_type: 'selection',
      destination: { kind: destination.kind, id: Number(destination.id) },
      source: {
        url: sourceUrl,
        canonical_url: canonicalUrl,
        title: String(capture.title || '').slice(0, 512)
      },
      snapshot: {
        selected_text: selection,
        captured_at: capture.captured_at || new Date().toISOString()
      },
      message: { note: utf8Limit(String(input.note || ''), 4096) }
    }
  }, 'team.share.create');
  const lastShare = {
    browser_share: payload.browser_share || null,
    chat_message: payload.chat_message || null,
    idempotent_replay: Boolean(payload.idempotent_replay),
    created_at: new Date().toISOString()
  };
  await storage.set({ last_share: lastShare });
  await storage.remove('pending_capture');
  return payload;
}

async function uploadBinaryMedia(browserShareId, kind, dataUrl, metadata = {}, name = '') {
  const { mime, bytes } = dataUrlBytes(dataUrl);
  return authorizedFetch(`/api/browser-share-media-v2040.php?browser_share_id=${encodeURIComponent(browserShareId)}`, {
    method: 'POST',
    headers: {
      'Content-Type': mime,
      'X-VP3-Media-Kind': kind,
      'X-VP3-Media-Name': encodeURIComponent(String(name || '').slice(0, 180)),
      'X-VP3-Media-Metadata': base64UrlJson(metadata)
    },
    body: bytes
  }, 'team.share.create');
}

async function createMediaReference(browserShareId, reference) {
  return authorizedFetch('/api/browser-share-media-v2040.php', {
    method: 'POST',
    json: {
      browser_share_id: browserShareId,
      kind: reference.kind,
      metadata: reference.metadata || {}
    }
  }, 'team.share.create');
}

async function createRichShare(input) {
  const payload = await createShare(input);
  const browserShareId = String(payload?.browser_share?.id || '');
  if (!browserShareId) return { ...payload, media: [], media_errors: ['Browser Share media could not resolve the new share ID.'] };
  const media = [];
  const media_errors = [];
  const rich = input?.rich_media || {};
  if (rich.screenshot?.data_url) {
    try {
      const result = await uploadBinaryMedia(browserShareId, 'screenshot', rich.screenshot.data_url, rich.screenshot.metadata || {}, 'browser-share-screenshot.png');
      if (result.media) media.push(result.media);
    } catch (error) { media_errors.push(`Screenshot: ${error.message}`); }
  }
  if (rich.media_reference?.kind) {
    try {
      const result = await createMediaReference(browserShareId, rich.media_reference);
      if (result.media) media.push(result.media);
    } catch (error) { media_errors.push(`Media reference: ${error.message}`); }
  }
  if (rich.commentary?.data_url) {
    try {
      const extension = /ogg/i.test(rich.commentary.mime_type || '') ? 'ogg' : /mp4/i.test(rich.commentary.mime_type || '') ? 'm4a' : 'webm';
      const result = await uploadBinaryMedia(browserShareId, 'commentary_audio', rich.commentary.data_url, rich.commentary.metadata || {}, `browser-share-commentary.${extension}`);
      if (result.media) media.push(result.media);
    } catch (error) { media_errors.push(`Commentary: ${error.message}`); }
  }
  const visibility = ['private', 'team', 'public'].includes(String(input?.visibility || ''))
    ? String(input.visibility)
    : 'private';
  const teamId = visibility === 'team' ? Number(input?.visibility_team_id || 0) : 0;
  const publication = await sourceFeedAction('publish', {
    browser_share_id: browserShareId,
    visibility,
    team_id: teamId,
    source_version_hash: String(input?.capture?.page_text_sha256 || '')
  });
  return { ...payload, media, media_errors, annotation: publication.annotation || null };
}

async function browserShareAction(action, browserShareId, folderId = 0) {
  if (!['ask_agent', 'save_knowledge', 'create_task'].includes(action)) throw new Error('Unsupported Browser Share action.');
  const capability = action === 'save_knowledge' ? 'knowledge.write' : action === 'create_task' ? 'task.propose' : 'agent.message';
  return authorizedFetch('/api/extension-browser-share-actions-v2030.php', {
    method: 'POST',
    json: { action, browser_share_id: String(browserShareId || ''), folder_id: Number(folderId || 0) }
  }, capability);
}

async function disconnect() {
  const state = await storage.get(['device_id']);
  if (state.device_id) {
    try {
      await authorizedFetch('/api/extension-device-disconnect-v2030.php', { method: 'POST', json: {} });
    } catch (error) {
      if (error.status !== 401) throw error;
    }
  }
  await clearRevokedConnection();
  return { ok: true };
}

async function releaseState() {
  const payload = await authorizedFetch('/api/annotated-release-v2100.php?action=state', { method: 'GET' }, 'team.chat.read');
  await storage.set({ release_state: payload.state || null, compatibility: payload.compatibility || null });
  return payload;
}

async function publicState() {
  const state = await storage.get(['base_url', 'installation_id', 'device_id', 'connected_user', 'approved_capabilities', 'pending_connection', 'session', 'last_share', 'pending_capture', 'release_state', 'compatibility']);
  return {
    base_url: cleanBaseUrl(state.base_url || VP3_DEFAULT_BASE),
    installation_id: state.installation_id || '',
    connected: Boolean(state.device_id),
    device_id: state.device_id || '',
    user: state.connected_user || state.session?.user || null,
    capabilities: state.session?.capabilities || state.approved_capabilities || [],
    pending_connection: state.pending_connection || null,
    last_share: state.last_share || null,
    pending_capture: state.pending_capture || null,
    release_state: state.release_state || null,
    compatibility: state.compatibility || null
  };
}

chrome.runtime.onInstalled.addListener(() => {
  chrome.contextMenus.removeAll(() => {
    chrome.contextMenus.create({ id: 'vp3-share-selection', title: 'Share selection with VP3', contexts: ['selection'] });
  });
  chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true }).catch(() => {});
});

chrome.contextMenus.onClicked.addListener(async (info, tab) => {
  if (info.menuItemId !== 'vp3-share-selection' || !tab?.id) return;
  const capture = await activeCapture(tab);
  if (info.selectionText) capture.selected_text = utf8Limit(String(info.selectionText).trim(), 32768);
  capture.captured_at = new Date().toISOString();
  await storage.set({ pending_capture: capture });
  try { await chrome.sidePanel.open({ tabId: tab.id }); } catch {}
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  const run = async () => {
    switch (message?.type) {
      case 'state': return publicState();
      case 'capture': return activeCapture();
      case 'tab_identity': return activeTabIdentity();
      case 'capture_region': return selectScreenshotRegion();
      case 'clear_pending_capture': await storage.remove('pending_capture'); return { ok: true };
      case 'connect': return beginConnect(message.device_name);
      case 'poll_connect': return pollConnect();
      case 'destinations': return destinations();
      case 'this_page': return thisPage(message.capture || await activeCapture(), message.cursor || '');
      case 'following': return followingFeed(message.cursor || '');
      case 'source_action': return sourceFeedAction(message.action, message.payload || {});
      case 'research_context': return researchContext(message.browser_share_id);
      case 'research_action': return researchAction(message.action, message.payload || {});
      case 'live_rooms': return liveRoomsForSource(message.capture || await activeCapture());
      case 'live_poll': return liveRoomPoll(message.room, message.after || 0);
      case 'live_action': return liveRoomAction(message.action, message.payload || {});
      case 'trust_observe': return observeSource(message.capture || await activeCapture());
      case 'trust_notifications': return browserTrustGet('notifications', { limit: message.limit || 50 });
      case 'trust_history': return browserTrustGet('source_history', { source_id: message.source_id || '', limit: message.limit || 25 });
      case 'trust_claims': return browserTrustGet('claims_for_source', { source_id: message.source_id || '' });
      case 'trust_action': return browserTrustAction(message.action, message.payload || {});
      case 'search_query': return browserSearchGet('search', message.params || {});
      case 'search_discover': return browserSearchGet('discover', message.params || {});
      case 'search_recent': return browserSearchGet('recent', {});
      case 'search_saved': return browserSearchGet('saved', {});
      case 'search_action': return browserSearchAction(message.action, message.payload || {});
      case 'release_state': return releaseState();
      case 'media_data': return authorizedMediaDataUrl(String(message.path || ''));
      case 'share': return createRichShare(message);
      case 'share_action': return browserShareAction(message.action, message.browser_share_id, message.folder_id);
      case 'disconnect': return disconnect();
      case 'open_url': {
        const url = String(message.url || '');
        if (!/^https?:\/\//i.test(url)) throw new Error('Only HTTP(S) links can be opened.');
        await chrome.tabs.create({ url });
        return { ok: true };
      }
      case 'set_base_url': {
        const base_url = cleanBaseUrl(message.base_url);
        const current = await config();
        if (base_url !== current.base_url) {
          const connection = await storage.get(['device_id', 'pending_connection']);
          if (connection.device_id || connection.pending_connection) {
            throw new Error('Disconnect this browser from the current VP3 site before changing the VP3 site.');
          }
        }
        if (!(await ensureOriginPermission(base_url))) throw new Error('VP3 site permission was not granted.');
        await storage.set({ base_url, session: null, release_state: null, compatibility: null });
        return { base_url };
      }
      default: throw new Error('Unsupported Browser Companion request.');
    }
  };
  run().then(value => sendResponse({ ok: true, value })).catch(error => sendResponse({ ok: false, error: error.message, code: error.code || '' }));
  return true;
});