(() => {
  'use strict';

  if (window.StonefellowEditorAgent) return;

  const BUILD = 'editor-agent-canonical-20260903';
  const CAPABILITY_SCHEMA = 'editor-agent-capability-catalog-20260903';
  const CATALOG_KEY = 'stonefellow:editor-capability-catalog';
  const VALID_STATUS = new Set(['success', 'failed', 'unsupported', 'no_change', 'cancelled', 'unverified']);
  const REQUIRED_ADAPTER_METHODS = ['commands', 'selection', 'snapshot', 'execute', 'verify'];
  const surfaces = new Map();
  const knownSurfaces = new Map();
  const executions = [];

  function copy(value) {
    if (value === undefined) return undefined;
    try {
      if (typeof structuredClone === 'function') return structuredClone(value);
    } catch (error) {}
    try {
      return JSON.parse(JSON.stringify(value));
    } catch (error) {
      return value;
    }
  }

  function cleanText(value, limit = 280) {
    return String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, limit);
  }

  function cleanSurface(value) {
    return String(value || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '-');
  }

  function cleanCommandId(value) {
    return String(value || '').trim();
  }

  function safePath(value) {
    const path = cleanText(value, 500);
    return path.startsWith('/') ? path : '';
  }

  function currentPath() {
    try {
      return safePath(window.location?.pathname || '');
    } catch (error) {
      return '';
    }
  }

  function storage() {
    try {
      return window.localStorage || null;
    } catch (error) {
      return null;
    }
  }

  function strictResult(value, fallback = 'Editor action') {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      return { status: 'failed', result: `${fallback} did not return a structured result`, verified: false };
    }
    const status = VALID_STATUS.has(String(value.status || '')) ? String(value.status) : 'failed';
    const verified = Boolean(value.verified);
    if (status === 'success' && !verified) {
      return { ...value, status: 'unverified', result: String(value.result || `${fallback} was not verified`), verified: false };
    }
    if (status === 'no_change') {
      return { ...value, status, result: String(value.result || `${fallback} required no change`), verified: true };
    }
    return { ...value, status, result: String(value.result || fallback), verified };
  }

  function normalizeDescriptor(surface, descriptor) {
    if (!descriptor || typeof descriptor !== 'object' || Array.isArray(descriptor)) {
      throw new Error(`Editor Agent surface ${surface} returned an invalid command descriptor.`);
    }
    const id = cleanCommandId(descriptor.id);
    if (!id || !id.startsWith(`${surface}.`)) {
      throw new Error(`Editor Agent command ids for ${surface} must be namespaced as ${surface}.*`);
    }
    return Object.freeze({
      ...copy(descriptor),
      id,
      legacyType: cleanCommandId(descriptor.legacyType || descriptor.legacy_type || ''),
      mutates: descriptor.mutates !== false,
      verifiable: descriptor.verifiable !== false,
      destructive: descriptor.destructive === true,
      args: Array.isArray(descriptor.args) ? copy(descriptor.args) : []
    });
  }

  function commandInventory(entry) {
    const raw = typeof entry.adapter.commands === 'function' ? entry.adapter.commands() : entry.adapter.commands;
    if (!Array.isArray(raw)) throw new Error(`Editor Agent surface ${entry.id} did not provide a command inventory.`);
    const ids = new Set();
    return raw.map(descriptor => {
      const normalized = normalizeDescriptor(entry.id, descriptor);
      if (ids.has(normalized.id)) throw new Error(`Duplicate Editor Agent command id: ${normalized.id}`);
      ids.add(normalized.id);
      return normalized;
    });
  }

  function storedArg(arg) {
    if (typeof arg === 'string') return cleanText(arg, 80);
    if (!arg || typeof arg !== 'object' || Array.isArray(arg)) return null;
    const name = cleanText(arg.name, 80);
    if (!name) return null;
    return {
      name,
      type: cleanText(arg.type, 80),
      required: arg.required === true
    };
  }

  function storedDescriptor(surface, descriptor) {
    const normalized = normalizeDescriptor(surface, descriptor);
    return {
      id: normalized.id,
      legacyType: normalized.legacyType,
      category: cleanText(normalized.category, 80),
      description: cleanText(normalized.description, 280),
      mutates: normalized.mutates !== false,
      verifiable: normalized.verifiable !== false,
      destructive: normalized.destructive === true,
      args: normalized.args.map(storedArg).filter(Boolean).slice(0, 32)
    };
  }

  function parseStoredCatalog(raw) {
    if (!raw) return null;
    try {
      const payload = JSON.parse(raw);
      if (!payload || payload.schema !== CAPABILITY_SCHEMA || !Array.isArray(payload.surfaces)) return null;
      return payload;
    } catch (error) {
      return null;
    }
  }

  function applyStoredCatalog(payload, replace = false) {
    if (!payload || payload.schema !== CAPABILITY_SCHEMA || !Array.isArray(payload.surfaces)) return false;
    if (replace) knownSurfaces.clear();
    for (const row of payload.surfaces.slice(0, 16)) {
      if (!row || typeof row !== 'object' || Array.isArray(row)) continue;
      const id = cleanSurface(row.id);
      if (!id) continue;
      const commands = [];
      for (const descriptor of (Array.isArray(row.commands) ? row.commands : []).slice(0, 200)) {
        try {
          commands.push(storedDescriptor(id, descriptor));
        } catch (error) {}
      }
      knownSurfaces.set(id, {
        id,
        label: cleanText(row.label || id, 120),
        path: safePath(row.path || ''),
        seenAt: Math.max(0, Number(row.seenAt || 0)),
        commands
      });
    }
    return true;
  }

  function hydrateStoredCatalog() {
    const store = storage();
    if (!store) return false;
    return applyStoredCatalog(parseStoredCatalog(store.getItem(CATALOG_KEY)), false);
  }

  function persistKnownCatalog() {
    const store = storage();
    if (!store) return false;
    const current = new Map([...knownSurfaces.entries()].map(([id, row]) => [id, copy(row)]));
    const existing = parseStoredCatalog(store.getItem(CATALOG_KEY));
    if (existing) applyStoredCatalog(existing, false);
    for (const [id, row] of current) knownSurfaces.set(id, row);
    const rows = [...knownSurfaces.values()]
      .sort((a, b) => a.id.localeCompare(b.id))
      .slice(0, 16)
      .map(row => ({
        id: row.id,
        label: cleanText(row.label, 120),
        path: safePath(row.path),
        seenAt: Math.max(0, Number(row.seenAt || 0)),
        commands: row.commands.slice(0, 200).map(command => storedDescriptor(row.id, command))
      }));
    try {
      store.setItem(CATALOG_KEY, JSON.stringify({ schema: CAPABILITY_SCHEMA, updatedAt: Date.now(), surfaces: rows }));
      return true;
    } catch (error) {
      return false;
    }
  }

  function rememberSurface(entry, persist = true) {
    const commands = commandInventory(entry).map(command => storedDescriptor(entry.id, command));
    const prior = knownSurfaces.get(entry.id) || {};
    knownSurfaces.set(entry.id, {
      id: entry.id,
      label: cleanText(entry.label || prior.label || entry.id, 120),
      path: safePath(entry.path || prior.path || ''),
      seenAt: Date.now(),
      commands
    });
    if (persist) persistKnownCatalog();
  }

  function findDescriptor(entry, command) {
    const requested = cleanCommandId(command?.id || command?.command || command?.type);
    if (!requested) return null;
    return commandInventory(entry).find(row => row.id === requested || (row.legacyType && row.legacyType === requested)) || null;
  }

  function normalizeCommand(entry, command, descriptor) {
    const base = { ...(command && typeof command === 'object' ? command : {}) };
    if (typeof entry.adapter.normalizeCommand === 'function') {
      const normalized = entry.adapter.normalizeCommand(base, descriptor);
      if (!normalized || typeof normalized !== 'object' || Array.isArray(normalized)) {
        throw new Error(`Editor Agent surface ${entry.id} returned an invalid normalized command.`);
      }
      return { ...normalized, id: descriptor.id };
    }
    return { ...base, id: descriptor.id, type: descriptor.legacyType || descriptor.id };
  }

  function emit(name, detail) {
    try {
      window.dispatchEvent?.(new CustomEvent(name, { detail: copy(detail) }));
    } catch (error) {}
  }

  function registerSurface(id, adapter) {
    const surface = cleanSurface(id);
    if (!surface) throw new Error('Editor Agent surface id is required.');
    if (!adapter || typeof adapter !== 'object' || Array.isArray(adapter)) throw new Error(`Editor Agent surface ${surface} requires an adapter object.`);
    for (const method of REQUIRED_ADAPTER_METHODS) {
      if (typeof adapter[method] !== 'function' && !(method === 'commands' && Array.isArray(adapter.commands))) {
        throw new Error(`Editor Agent surface ${surface} is missing adapter.${method}().`);
      }
    }
    if (surfaces.has(surface)) throw new Error(`Editor Agent surface ${surface} is already registered.`);
    const entry = {
      id: surface,
      label: String(adapter.label || surface),
      path: safePath(adapter.path || currentPath()),
      adapter
    };
    commandInventory(entry);
    surfaces.set(surface, entry);
    rememberSurface(entry, true);
    emit('stonefellow:editor-agent:surface-registered', { surface, label: entry.label, path: entry.path, commandCount: commandInventory(entry).length });
    emit('stonefellow:editor-agent:catalog-updated', contextCatalog());
    return surface;
  }

  function unregisterSurface(id) {
    const surface = cleanSurface(id);
    const removed = surfaces.delete(surface);
    if (removed) {
      emit('stonefellow:editor-agent:surface-unregistered', { surface });
      emit('stonefellow:editor-agent:catalog-updated', contextCatalog());
    }
    return removed;
  }

  function surfaceList() {
    return [...surfaces.values()].map(entry => ({ id: entry.id, label: entry.label, commands: commandInventory(entry) }));
  }

  function knownSurfaceList() {
    const ids = new Set([...knownSurfaces.keys(), ...surfaces.keys()]);
    return [...ids].sort().map(id => {
      const live = surfaces.get(id) || null;
      const known = knownSurfaces.get(id) || null;
      const commands = live ? commandInventory(live).map(command => storedDescriptor(id, command)) : (known?.commands || []).map(command => storedDescriptor(id, command));
      return {
        id,
        label: cleanText(live?.label || known?.label || id, 120),
        path: safePath(live?.path || known?.path || ''),
        available: Boolean(live),
        commandCount: commands.length,
        commands
      };
    });
  }

  function capabilities(options = {}) {
    const onlySurface = cleanSurface(options.surface || '');
    const liveOnly = options.liveOnly === true;
    const rows = [];
    for (const surface of knownSurfaceList()) {
      if (onlySurface && surface.id !== onlySurface) continue;
      if (liveOnly && !surface.available) continue;
      for (const descriptor of surface.commands) {
        rows.push({
          surface: surface.id,
          surfaceLabel: surface.label,
          path: surface.path,
          available: surface.available,
          ...copy(descriptor)
        });
      }
    }
    return rows;
  }

  function argSearchText(args) {
    return (Array.isArray(args) ? args : []).map(arg => typeof arg === 'string' ? arg : `${arg?.name || ''} ${arg?.type || ''}`).join(' ');
  }

  function searchCapabilities(query, options = {}) {
    const text = cleanText(query, 300).toLowerCase();
    const rows = capabilities(options);
    if (!text) return rows.slice(0, Math.max(1, Math.min(200, Number(options.limit || 50))));
    const terms = text.split(/[^a-z0-9_.-]+/).filter(Boolean);
    const scored = rows.map(row => {
      const id = row.id.toLowerCase();
      const legacy = String(row.legacyType || '').toLowerCase();
      const category = String(row.category || '').toLowerCase();
      const description = String(row.description || '').toLowerCase();
      const args = argSearchText(row.args).toLowerCase();
      const label = String(row.surfaceLabel || '').toLowerCase();
      let score = id === text || legacy === text ? 1000 : 0;
      if (id.startsWith(text)) score += 300;
      for (const term of terms) {
        if (id.includes(term)) score += 60;
        if (legacy.includes(term)) score += 45;
        if (category.includes(term)) score += 25;
        if (description.includes(term)) score += 15;
        if (args.includes(term)) score += 10;
        if (label.includes(term)) score += 5;
      }
      if (row.available) score += 1;
      return { row, score };
    }).filter(item => item.score > 0);
    scored.sort((a, b) => b.score - a.score || a.row.id.localeCompare(b.row.id));
    return scored.slice(0, Math.max(1, Math.min(200, Number(options.limit || 50)))).map(item => ({ ...item.row, score: item.score }));
  }

  function resolveCommand(command, options = {}) {
    const requested = cleanCommandId(command?.id || command?.command || command?.type || command);
    const preferredSurface = cleanSurface(options.surface || command?.surface || '');
    if (!requested) return { status: 'unsupported', result: 'Editor command id is required.', candidates: [] };
    const rows = capabilities(preferredSurface ? { surface: preferredSurface } : {});
    const exactId = rows.filter(row => row.id === requested);
    const matches = exactId.length ? exactId : rows.filter(row => row.legacyType && row.legacyType === requested);
    if (!matches.length) {
      return { status: 'unsupported', result: `No known Editor Agent capability matches ${requested}.`, requested, candidates: [] };
    }
    if (matches.length > 1) {
      return {
        status: 'ambiguous',
        result: `Editor command ${requested} matches more than one surface.`,
        requested,
        candidates: matches.map(row => ({ surface: row.surface, id: row.id, path: row.path, available: row.available }))
      };
    }
    const match = matches[0];
    return {
      status: 'resolved',
      requested,
      surface: match.surface,
      commandId: match.id,
      path: match.path,
      available: match.available,
      descriptor: copy(match)
    };
  }

  function contextCatalog() {
    return {
      build: BUILD,
      schema: CAPABILITY_SCHEMA,
      surfaces: knownSurfaceList().map(surface => ({
        id: surface.id,
        label: surface.label,
        path: surface.path,
        available: surface.available,
        command_count: surface.commandCount,
        commands: surface.commands.map(command => command.id)
      }))
    };
  }

  async function inspect(id) {
    const surface = cleanSurface(id);
    const entry = surfaces.get(surface);
    if (!entry) throw new Error(`Editor Agent surface ${surface || '(missing)'} is not registered.`);
    const [selection, state] = await Promise.all([
      Promise.resolve(entry.adapter.selection()),
      Promise.resolve(entry.adapter.snapshot())
    ]);
    return {
      build: BUILD,
      surface,
      label: entry.label,
      commands: commandInventory(entry),
      selection: copy(selection || {}),
      state: copy(state || {})
    };
  }

  async function execute(options = {}) {
    const surface = cleanSurface(options.surface);
    const entry = surfaces.get(surface);
    const requested = options.command && typeof options.command === 'object' ? options.command : {};
    if (!entry) {
      return { build: BUILD, surface, status: 'unsupported', result: `Editor surface ${surface || '(missing)'} is unavailable`, verified: false };
    }

    let descriptor = null;
    try {
      descriptor = findDescriptor(entry, requested);
    } catch (error) {
      return { build: BUILD, surface, status: 'failed', result: error?.message || 'Editor command inventory failed.', verified: false };
    }
    if (!descriptor) {
      const requestedId = cleanCommandId(requested.id || requested.command || requested.type || 'unknown');
      return { build: BUILD, surface, status: 'unsupported', result: `Editor command ${requestedId} is not registered for ${surface}.`, verified: false };
    }

    let command = null;
    let before = {};
    let after = {};
    let raw = null;
    let verified = null;
    try {
      command = normalizeCommand(entry, requested, descriptor);
      before = copy(await Promise.resolve(entry.adapter.snapshot())) || {};
      raw = await Promise.resolve(entry.adapter.execute(command, copy(options.context || {}), copy(before)));
      after = copy(await Promise.resolve(entry.adapter.snapshot())) || {};
      verified = await Promise.resolve(entry.adapter.verify(command, copy(before), copy(after), raw, copy(options.context || {})));
    } catch (error) {
      verified = { status: 'failed', result: error?.message || `${descriptor.id} threw while executing`, verified: false };
    }

    const result = strictResult(verified || raw, descriptor.id);
    const record = {
      build: BUILD,
      surface,
      commandId: descriptor.id,
      legacyType: descriptor.legacyType,
      status: result.status,
      verified: Boolean(result.verified),
      at: Date.now()
    };
    executions.push(record);
    if (executions.length > 100) executions.splice(0, executions.length - 100);
    emit('stonefellow:editor-agent:execution', record);

    return {
      ...result,
      build: BUILD,
      surface,
      commandId: descriptor.id,
      command: copy(command),
      before,
      after,
      changes: Array.isArray(raw?.changes) ? copy(raw.changes) : [],
      raw: raw && typeof raw === 'object' ? copy(raw) : null
    };
  }

  async function executeAny(options = {}) {
    const requested = typeof options.command === 'string'
      ? { id: options.command }
      : (options.command && typeof options.command === 'object' ? options.command : {});
    const explicitSurface = cleanSurface(options.surface || requested.surface || '');
    if (explicitSurface) return execute({ ...options, surface: explicitSurface, command: requested });
    const resolved = resolveCommand(requested);
    if (resolved.status === 'ambiguous') {
      return {
        build: BUILD,
        status: 'unsupported',
        reason: 'ambiguous',
        result: resolved.result,
        verified: false,
        candidates: resolved.candidates
      };
    }
    if (resolved.status !== 'resolved') {
      return { build: BUILD, status: 'unsupported', reason: 'unknown', result: resolved.result, verified: false, candidates: resolved.candidates || [] };
    }
    if (!resolved.available) {
      return {
        build: BUILD,
        status: 'unsupported',
        reason: 'surface_unavailable',
        result: `${resolved.commandId} belongs to ${resolved.surface}, but that editor is not loaded on this page.`,
        verified: false,
        known: true,
        available: false,
        requiredSurface: resolved.surface,
        path: resolved.path,
        commandId: resolved.commandId
      };
    }
    return execute({ ...options, surface: resolved.surface, command: { ...requested, id: resolved.commandId } });
  }

  hydrateStoredCatalog();

  try {
    window.addEventListener?.('storage', event => {
      if (event?.key !== CATALOG_KEY) return;
      const payload = parseStoredCatalog(event.newValue);
      if (!payload) return;
      applyStoredCatalog(payload, true);
      for (const entry of surfaces.values()) rememberSurface(entry, false);
      emit('stonefellow:editor-agent:catalog-updated', contextCatalog());
    });
  } catch (error) {}

  const api = Object.freeze({
    build: BUILD,
    capabilitySchema: CAPABILITY_SCHEMA,
    validStatuses: Object.freeze([...VALID_STATUS]),
    registerSurface,
    unregisterSurface,
    surfaces: surfaceList,
    knownSurfaces: knownSurfaceList,
    hasSurface: id => surfaces.has(cleanSurface(id)),
    inspect,
    execute,
    executeAny,
    capabilities,
    searchCapabilities,
    resolveCommand,
    contextCatalog,
    commandInventory: id => {
      const entry = surfaces.get(cleanSurface(id));
      return entry ? commandInventory(entry) : [];
    },
    selection: async id => (await inspect(id)).selection,
    state: async id => (await inspect(id)).state,
    proof: () => ({
      build: BUILD,
      capabilitySchema: CAPABILITY_SCHEMA,
      surfaces: surfaceList().map(row => row.id),
      knownSurfaces: knownSurfaceList().map(row => row.id),
      knownCapabilityCount: capabilities().length,
      executions: copy(executions)
    })
  });

  window.StonefellowEditorAgent = api;
  window.STONEFELLOW_EDITOR_AGENT = {
    build: BUILD,
    owner: 'editor-agent.js',
    capabilitySchema: CAPABILITY_SCHEMA,
    registeredSurfaces: () => surfaceList().map(row => row.id),
    knownSurfaces: () => knownSurfaceList().map(row => row.id),
    capabilityCount: () => capabilities().length
  };
  emit('stonefellow:editor-agent:ready', { build: BUILD, capabilitySchema: CAPABILITY_SCHEMA, knownSurfaces: knownSurfaceList().map(row => row.id) });
})();
