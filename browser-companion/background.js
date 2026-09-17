const VP3_DEFAULT_BASE = 'https://vp3.me';
const VP3_CONTRACT_VERSION = '1';
const VP3_EXTENSION_VERSION = '20.30.0';
const VP3_REQUESTED_CAPABILITIES = [
  'team.destinations.read',
  'team.share.create',
  'team.chat.read',
  'agent.message',
  'knowledge.write',
  'task.propose'
];

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
  await storage.remove(['device_id', 'device_credential', 'connected_user', 'approved_capabilities', 'pending_connection', 'session', 'last_share']);
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
  await storage.set({ session: payload.session });
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

async function activeCapture(tabHint = null) {
  let tab = tabHint;
  if (!tab?.id) [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || !/^https?:/i.test(String(tab.url || ''))) return { available: false, reason: 'Open a normal web page to capture content.' };
  let result = { selected_text: '', canonical_url: '' };
  try {
    const injected = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: () => ({
        selected_text: String(window.getSelection?.() || '').trim(),
        canonical_url: document.querySelector('link[rel="canonical"]')?.href || ''
      })
    });
    result = injected?.[0]?.result || result;
  } catch {
    // Chrome blocks injection on privileged pages. The tab metadata is still safe to show.
  }
  return {
    available: true,
    tab_id: tab.id,
    source_url: tab.url || '',
    canonical_url: result.canonical_url || tab.url || '',
    title: String(tab.title || '').slice(0, 512),
    selected_text: utf8Limit(String(result.selected_text || ''), 32768),
    captured_at: new Date().toISOString()
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
      session: null
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

async function createShare(input) {
  const capture = input?.capture || {};
  const selection = utf8Limit(String(capture.selected_text || '').trim(), 32768);
  if (!selection) throw new Error('Highlight text on the page before sharing.');
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
  await storage.set({ last_share: { ...payload, source_url: sourceUrl, created_at: new Date().toISOString() } });
  return payload;
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
      // A 401 means the server has already invalidated this credential. For all
      // other failures retain local credentials so the user can retry revocation.
      if (error.status !== 401) throw error;
    }
  }
  await clearRevokedConnection();
  return { ok: true };
}

async function publicState() {
  const state = await storage.get(['base_url', 'installation_id', 'device_id', 'connected_user', 'approved_capabilities', 'pending_connection', 'session', 'last_share', 'pending_capture']);
  return {
    base_url: cleanBaseUrl(state.base_url || VP3_DEFAULT_BASE),
    installation_id: state.installation_id || '',
    connected: Boolean(state.device_id),
    device_id: state.device_id || '',
    user: state.connected_user || state.session?.user || null,
    capabilities: state.session?.capabilities || state.approved_capabilities || [],
    pending_connection: state.pending_connection || null,
    last_share: state.last_share || null,
    pending_capture: state.pending_capture || null
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
      case 'capture': {
        const capture = await activeCapture();
        await storage.set({ pending_capture: capture });
        return capture;
      }
      case 'connect': return beginConnect(message.device_name);
      case 'poll_connect': return pollConnect();
      case 'destinations': return destinations();
      case 'share': return createShare(message);
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
        if (!(await ensureOriginPermission(base_url))) throw new Error('VP3 site permission was not granted.');
        await storage.set({ base_url, session: null });
        return { base_url };
      }
      default: throw new Error('Unsupported Browser Companion request.');
    }
  };
  run().then(value => sendResponse({ ok: true, value })).catch(error => sendResponse({ ok: false, error: error.message, code: error.code || '' }));
  return true;
});
