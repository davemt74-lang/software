/* Read-only workspace reports. No audience identities or credentials are saved. */
(() => {
  'use strict';
  const root = document.getElementById('musicAnalytics');
  if (!root || root.dataset.enabled !== 'true') return;
  const protocol = 'vp3_music_analytics_v1';
  const workspace = Number(root.dataset.workspace);
  const byId = suffix => document.getElementById('musicAnalytics' + suffix);
  const form = byId('Filters');
  if (!form || !Number.isSafeInteger(workspace) || workspace < 1) return;
  const count = value => Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
  const integer = value => Math.floor(count(value)).toLocaleString();
  const percent = value => value === null || value === undefined ? 'Unavailable' : count(value).toLocaleString(undefined, {maximumFractionDigits: 1}) + '%';
  const time = value => {
    const seconds = Math.floor(count(value) / 1000);
    if (seconds < 60) return seconds + ' s';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' m ' + seconds % 60 + ' s';
    return Math.floor(seconds / 3600).toLocaleString() + ' h ' + Math.floor(seconds % 3600 / 60) + ' m';
  };
  const bytes = value => {
    const n = count(value);
    if (n < 1024) return integer(n) + ' B';
    const unit = Math.min(3, Math.floor(Math.log(n) / Math.log(1024)));
    return (n / 1024 ** unit).toLocaleString(undefined, {maximumFractionDigits: 1}) + ' ' + ['', 'KiB', 'MiB', 'GiB'][unit];
  };
  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined && text !== null) element.textContent = String(text);
    if (className) element.className = className;
    return element;
  };
  const clear = element => element.replaceChildren();
  const rows = value => Array.isArray(value) ? value : [];
  let report = null;
  let request = null;
  let generation = 0;
  let lastInput = null;
  let offset = 0;
  let dirty = false;
  const limit = 20;
  const dataSections = ['OverviewData', 'BreakdownData', 'CatalogData', 'SitesData'];
  const commonZones = ['UTC', 'America/Phoenix', 'America/Los_Angeles', 'America/Denver', 'America/Chicago', 'America/New_York', 'Europe/London', 'Europe/Berlin', 'Asia/Tokyo', 'Australia/Sydney'];
  let localZone = 'UTC';
  try { localZone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (_) {}
  let zones = commonZones;
  try { if (typeof Intl.supportedValuesOf === 'function') zones = Intl.supportedValuesOf('timeZone'); } catch (_) {}
  for (const zone of [...new Set(['UTC', localZone, ...zones])]) {
    if (zone === 'UTC') continue;
    const option = node('option', zone.replaceAll('_', ' '));
    option.value = zone;
    byId('Timezone').append(option);
  }
  byId('Timezone').value = localZone;

  function status(message = '', kind = '') {
    byId('Status').textContent = message;
    byId('Status').className = 'music-report-status' + (kind ? ' ' + kind : '');
  }
  function showData(visible) {
    for (const key of dataSections) if (byId(key)) byId(key).hidden = !visible;
    byId('BreakdownPending').hidden = visible;
  }
  function stamp(value, timezone) {
    if (!value) return 'Not observed';
    const raw = typeof value === 'number' ? value : String(value);
    const date = new Date(typeof raw === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw) ? raw.replace(' ', 'T') + 'Z' : raw);
    if (!Number.isFinite(date.getTime())) return 'Unavailable';
    try { return new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short', timeZone: timezone}).format(date); }
    catch (_) { return date.toISOString(); }
  }
  function replaceOptions(select, options, label) {
    const selected = select.value;
    clear(select);
    const all = node('option', label); all.value = ''; select.append(all);
    for (const item of rows(options)) {
      if (item?.id === undefined || item?.id === null) continue;
      const option = node('option', item.title || item.label || 'Untitled');
      option.value = String(item.id);
      select.append(option);
    }
    if (selected && ![...select.options].some(option => option.value === selected)) {
      const option = node('option', 'Selected item'); option.value = selected; select.append(option);
    }
    select.value = selected;
  }
  function metric(label, value, note) {
    const article = node('article', null, 'music-metric');
    article.append(node('p', label, 'music-metric-label'), node('strong', value), node('small', note));
    return article;
  }
  function measuredAvailable(data) {
    const channel = data.filters?.channel || 'all';
    return (channel === 'all' || channel === 'native') && data.availability?.native === 'available'
      || (channel === 'all' || channel === 'provider') && data.availability?.provider === 'available';
  }
  function renderMetrics(data) {
    const target = byId('Metrics'), summary = data.summary || {};
    clear(target);
    if (data.filters?.channel === 'legacy') {
      const available = data.availability?.legacy === 'available';
      target.append(metric('Legacy sessions', available ? integer(summary.legacy_sessions) : 'Unavailable', 'Historical reporting only'),
        metric('Legacy listening', available ? time(summary.legacy_listening_ms) : 'Unavailable', 'Kept separate from measured listening'),
        metric('Qualified plays', 'Unavailable', 'Current measured definition does not apply'),
        metric('Completion', 'Unavailable', 'Historical completion is not reassessed'));
      return;
    }
    const available = measuredAvailable(data);
    target.append(metric('Playback starts', available ? integer(summary.starts) : 'Unavailable', available ? integer(summary.playback_requests) + ' playback requests' : 'Measurement source unavailable'),
      metric('Qualified plays', available ? integer(summary.qualified_plays) : 'Unavailable', 'At least 10 seconds accepted'),
      metric('Accepted listening', available ? time(summary.listening_ms) : 'Unavailable', 'Measured native and connected-site sessions'),
      metric('Completion', available ? percent(summary.completion_percent) : 'Unavailable', integer(summary.completed_plays) + ' of ' + integer(summary.completion_known_plays) + ' starts with known duration'));
  }
  function renderCoverage(data) {
    const target = byId('Coverage'), coverage = data.coverage || {};
    clear(target);
    const list = node('ul', null, 'music-coverage-list');
    const badges = [
      ['VP3', coverage.native_sessions, data.availability?.native],
      ['Connected sites', coverage.provider_sessions, data.availability?.provider],
      ['Legacy', coverage.legacy_sessions, data.availability?.legacy],
    ];
    for (const [label, value, availability] of badges) list.append(node('li', label + ': ' + (availability === 'available' ? integer(value) + ' sessions' : 'unavailable'), 'music-badge' + (availability === 'available' ? '' : ' warning')));
    const flags = [['Partial observations', 'partial_sessions'], ['Unknown duration', 'unknown_duration_sessions'], ['Known previews', 'preview_sessions'], ['Staging', 'staging_sessions'], ['Unknown page', 'unknown_page_sessions'], ['Ongoing sessions', 'ongoing_sessions']];
    for (const [label, key] of flags) if (count(coverage[key])) list.append(node('li', label + ': ' + integer(coverage[key]), 'music-badge warning'));
    if (coverage.unfinished_delivery_requests === null) list.append(node('li', 'Delivery accounting unavailable', 'music-badge warning'));
    else if (count(coverage.unfinished_delivery_requests)) list.append(node('li', 'Unfinished delivery records: ' + integer(coverage.unfinished_delivery_requests), 'music-badge warning'));
    target.append(list);
    if (count(data.summary?.legacy_sessions)) target.append(node('p', 'Legacy history: ' + integer(data.summary.legacy_sessions) + ' sessions and ' + time(data.summary.legacy_listening_ms) + ' of historical listening. These are excluded from the measured totals above.'));
    for (const note of rows(coverage.notes)) target.append(node('p', note));
    if (data.options?.truncated) target.append(node('p', 'The song selector shows a limited catalog page. Open the Music Library to find other recordings; report totals include the full matching catalog.'));
    if (!count(data.summary?.playback_requests) && !count(data.summary?.legacy_sessions) && measuredAvailable(data)) target.append(node('p', 'No matching listening sessions were found for these filters. Try a wider period or another traffic selection.'));
  }
  function table(headers, items, cellFactory, caption) {
    const element = node('table', null, 'music-table');
    if (caption) element.append(node('caption', caption));
    const head = node('thead'), headRow = node('tr');
    for (const header of headers) { const th = node('th', header); th.scope = 'col'; headRow.append(th); }
    head.append(headRow); element.append(head);
    const body = node('tbody');
    for (const item of items) {
      const tr = node('tr'), cells = cellFactory(item);
      cells.forEach((value, index) => {
        const td = node('td'); td.dataset.label = headers[index];
        td.append(value instanceof Node ? value : document.createTextNode(String(value)));
        tr.append(td);
      });
      body.append(tr);
    }
    element.append(body);
    return element;
  }
  function renderTrend(data) {
    const target = byId('Trend'), daily = rows(data.daily);
    clear(target); clear(byId('Daily'));
    if (!measuredAvailable(data)) { target.append(node('p', 'Measured listening is unavailable for this source selection.', 'music-empty')); return; }
    if (!daily.length) { target.append(node('p', 'No daily figures are available.', 'music-empty')); return; }
    const namespace = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(namespace, 'svg');
    svg.setAttribute('viewBox', '0 0 900 170'); svg.setAttribute('preserveAspectRatio', 'none'); svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Qualified plays by session start date. Exact figures are in View daily figures.'); svg.classList.add('music-trend-chart');
    const max = Math.max(1, ...daily.map(day => count(day.qualified_plays)));
    const width = 900 / daily.length;
    daily.forEach((day, index) => {
      const rect = document.createElementNS(namespace, 'rect');
      const value = count(day.qualified_plays), height = value / max * 145;
      rect.setAttribute('x', String(index * width + Math.min(2, width / 8)));
      rect.setAttribute('y', String(158 - height));
      rect.setAttribute('width', String(Math.max(.5, width - Math.min(4, width / 4))));
      rect.setAttribute('height', String(height || 1));
      if (!value) rect.setAttribute('style', 'fill:#e8edf3');
      const title = document.createElementNS(namespace, 'title'); title.textContent = String(day.date) + ': ' + integer(value) + ' qualified plays'; rect.append(title); svg.append(rect);
    });
    target.append(svg);
    const labels = node('div', null, 'music-trend-labels');
    labels.append(node('span', daily[0].date), node('span', daily[daily.length - 1].date)); target.append(labels);
    byId('Daily').append(table(['Session date', 'Starts', 'Qualified plays', 'Listening', 'Completion'], daily,
      day => [day.date, integer(day.starts), integer(day.qualified_plays), time(day.listening_ms), percent(day.completion_percent)],
      'Dates use ' + data.period.timezone + '. Listening is attributed to the session’s starting date.'));
  }
  function filterTo(key, value) {
    const select = byId(key);
    if (![...select.options].some(option => option.value === String(value))) {
      const option = node('option', 'Selected item'); option.value = String(value); select.append(option);
    }
    select.value = String(value);
    offset = 0;
    load(readInput());
    document.getElementById('musicOverview').scrollIntoView({block: 'start'});
  }
  function identityCell(item, group) {
    const cell = node('div');
    let title = item.title || 'Untitled';
    let note = '';
    if (group === 'pages') { title = item.url || 'Page unavailable'; note = item.provenance === 'registered_site_reported_page' ? 'Registered site · reported page' : 'Reported source context'; }
    if (group === 'surfaces') title = ({player:'Music player',agent:'Agent',agent_chat:'Agent Chat',agent_player:'Player',embed:'Embedded player',canvas:'Canvas',unknown:'Unknown surface'})[item.surface] || String(item.surface || 'Unknown surface').replaceAll('_', ' ');
    if (group === 'tracks') note = item.album_title || 'No album assigned';
    if (group === 'tracks' && item.recording_id || group === 'albums' && item.album_id) {
      const button = node('button', title, 'music-table-link'); button.type = 'button';
      button.addEventListener('click', () => filterTo(group === 'tracks' ? 'Recording' : 'Album', group === 'tracks' ? item.recording_id : item.album_id));
      cell.append(button);
    } else cell.append(node('strong', title));
    if (note) cell.append(node('span', note, 'music-row-note'));
    if (count(item.legacy_sessions)) cell.append(node('span', integer(item.legacy_sessions) + ' legacy sessions · ' + time(item.legacy_listening_ms), 'music-row-note'));
    return cell;
  }
  function renderBreakdown(data) {
    const group = byId('Breakdown').value;
    const items = rows(data[group]), target = byId('Table');
    clear(target);
    const labels = {tracks:'Song',albums:'Album',pages:'Playback page',surfaces:'Surface'};
    if (!items.length) target.append(node('p', 'No matching ' + ({tracks:'songs',albums:'albums',pages:'pages',surfaces:'surfaces'})[group] + ' in this report.', 'music-empty'));
    else if (data.filters?.channel === 'legacy') target.append(table([labels[group], 'Legacy sessions', 'Legacy listening'], items,
      item => [identityCell(item, group), integer(item.legacy_sessions), time(item.legacy_listening_ms)],
      'Historical measurements only. Current qualified-play and completion definitions are unavailable for this history.'));
    else target.append(table([labels[group], 'Starts', 'Qualified plays', 'Listening', 'Completion'], items,
      item => [identityCell(item, group), integer(item.starts), integer(item.qualified_plays), time(item.listening_ms), percent(item.completion_percent)],
      'Measured sessions for the selected period and filters. Historical sessions are labeled separately.'));
    const page = data.pagination?.[group] || {total:items.length,offset:0,limit,has_more:false};
    const start = count(page.total) ? count(page.offset) + 1 : 0;
    byId('PageLabel').textContent = integer(start) + '–' + integer(count(page.offset) + items.length) + ' of ' + integer(page.total);
    byId('Previous').disabled = count(page.offset) === 0;
    byId('Next').disabled = !page.has_more;
  }
  function renderCatalog(data) {
    const target = byId('Readiness'), attention = byId('Attention'), catalog = data.catalog || {};
    clear(target); clear(attention);
    if (data.availability?.catalog !== 'available') { target.append(node('p', 'Catalog readiness is unavailable. Open the Music Library to review your songs.', 'music-muted')); return; }
    for (const [label, value] of [['Songs', catalog.tracks], ['Canonical catalog records', catalog.metadata_ready], ['Playback ready', catalog.playback_ready]]) {
      const span = node('span'); span.append(node('strong', value === null || value === undefined ? 'Not fully checked' : integer(value)), document.createTextNode(' ' + label.toLowerCase())); target.append(span);
    }
    const checked = count(catalog.readiness_checked), total = count(catalog.readiness_total);
    if (catalog.readiness_complete === false) target.append(node('p', 'Readiness checked for ' + integer(checked) + ' of ' + integer(total) + ' songs. Review the Music Library for the full catalog.', 'music-muted'));
    const needs = rows(catalog.needs_attention);
    if (!needs.length) { attention.append(node('p', catalog.readiness_complete === false ? 'No issues were found in the songs checked.' : 'No catalog issues were found in this check.', 'music-muted')); return; }
    const box = node('div', null, 'music-attention'); box.append(node('h3', 'Needs attention'));
    const list = node('ul');
    for (const item of needs) {
      const li = node('li'), text = node('div'); text.append(node('strong', item.title || 'Untitled song'));
      text.append(node('p', rows(item.reasons).map(reason => typeof reason === 'string' ? reason.replaceAll('_', ' ') : reason?.message || 'Review this song').join(' · '), 'music-muted'));
      li.append(text);
      if (Number.isSafeInteger(Number(item.catalog_track_id)) && Number(item.catalog_track_id) > 0) {
        const link = node('a', 'Review song', 'music-btn');
        const url = new URL(root.dataset.libraryUrl, location.href);
        if (url.origin === location.origin) { url.searchParams.set('tab', 'tracks'); url.searchParams.set('edit', String(item.catalog_track_id)); url.hash = 'editor'; link.href = url.href; li.append(link); }
      }
      list.append(li);
    }
    box.append(list); attention.append(box);
  }
  function renderSites(data) {
    const target = byId('SitesData'); if (!target) return;
    clear(target);
    if (data.availability?.provider !== 'available') { target.append(node('p', 'Connected-site reporting is unavailable. Check the Music Provider upgrade and connection settings.', 'music-empty')); return; }
    if (!rows(data.sites).length) { target.append(node('p', 'No registered sites match this report. Use Manage music sites to connect an artist website.', 'music-empty')); return; }
    for (const site of data.sites) {
      const card = node('article', null, 'music-site-card');
      card.append(node('h3', site.label || 'Artist site'), node('p', site.origin || 'Address unavailable', 'music-site-origin'));
      const state = String(site.status || 'unknown');
      const stateLabel = state.replaceAll('_', ' ');
      card.append(node('span', stateLabel.charAt(0).toUpperCase() + stateLabel.slice(1) + (site.environment === 'staging' ? ' · Staging' : ''), 'music-badge' + (state === 'verified' || state === 'active' ? ' good' : ' warning')));
      const details = node('dl');
      const deliveryAvailable = data.availability?.delivery === 'available';
      for (const [label, value] of [['Selected songs', integer(site.selected_count)], ['Qualified plays', integer(site.qualified_plays)], ['Accepted listening', time(site.listening_ms)], ['Emitted audio', deliveryAvailable ? bytes(site.emitted_bytes) : 'Unavailable'], ['Reserved delivery', deliveryAvailable ? bytes(site.reserved_bytes) : 'Unavailable']]) {
        const div = node('div'); div.append(node('dt', label), node('dd', value)); details.append(div);
      }
      card.append(details, node('p', 'Last observed playback: ' + stamp(site.last_playback_at, data.period.timezone)), node('p', deliveryAvailable ? integer(site.media_requests) + ' media requests in this session cohort. Emitted bytes do not measure received or heard audio.' : 'Delivery accounting is unavailable for this report.'));
      if (site.site_id) { const button = node('button', 'View site listening', 'music-btn'); button.type = 'button'; button.addEventListener('click', () => {byId('Channel').value = 'provider'; filterTo('Site', site.site_id);}); card.append(button); }
      target.append(card);
    }
  }
  function render(data) {
    report = data;
    replaceOptions(byId('Recording'), data.options?.recordings, 'All songs');
    replaceOptions(byId('Album'), data.options?.albums, 'All albums');
    replaceOptions(byId('Site'), data.options?.sites, 'All sites');
    byId('PeriodLabel').textContent = data.period.start_date + ' — ' + data.period.end_date + ' · ' + data.period.timezone;
    if (byId('Period').value !== 'custom') { byId('Start').value = data.period.start_date; byId('End').value = data.period.end_date; }
    renderMetrics(data); renderCoverage(data); renderTrend(data); renderBreakdown(data); renderCatalog(data); renderSites(data);
    byId('Freshness').textContent = 'Updated ' + stamp(data.generated_at, data.period.timezone) + ' · ' + data.period.timezone + '. Ongoing sessions remain provisional.';
    showData(true);
  }
  function readInput() {
    const input = {protocol, action:'report', workspace_id:workspace, period:byId('Period').value, timezone:byId('Timezone').value, channel:byId('Channel').value, recording_id:byId('Recording').value, site_id:byId('Site').value, traffic:byId('Traffic').value, limit, offset};
    if (byId('Album').value) input.album_id = Number(byId('Album').value);
    if (input.period === 'custom') { input.start_date = byId('Start').value; input.end_date = byId('End').value; }
    return input;
  }
  async function load(input) {
    request?.abort();
    const controller = new AbortController(); request = controller;
    const id = ++generation;
    dirty = false; lastInput = {...input};
    root.dataset.reportState = 'loading'; root.setAttribute('aria-busy', 'true');
    showData(false); byId('Retry').hidden = true; status('Loading listening report…');
    byId('Previous').disabled = true; byId('Next').disabled = true;
    const timeout = setTimeout(() => controller.abort(), 30000);
    try {
      const response = await fetch(root.dataset.endpoint, {method:'POST', credentials:'same-origin', cache:'no-store', redirect:'error', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({...input, csrf_token:root.dataset.csrf}), signal:controller.signal});
      let data;
      try { data = await response.json(); }
      catch (_) { throw new Error('The listening report could not be loaded. Please retry.'); }
      if (id !== generation || dirty) return;
      if (!response.ok || data?.ok !== true) {
        const error = new Error(typeof data?.message === 'string' ? data.message : 'The listening report could not be loaded.');
        error.retryable = data?.retryable !== false && ![401,403,419,422].includes(response.status);
        throw error;
      }
      if (data.protocol !== protocol || Number(data.workspace?.id) !== workspace || data.period?.basis !== 'session_start_cohort_v1' || !data.summary || !data.availability) throw new Error('The report response could not be verified.');
      render(data);
      status(''); root.dataset.reportState = 'ready';
    } catch (error) {
      if (id !== generation || dirty) return;
      showData(false);
      status(error?.name === 'AbortError' ? 'The listening report took too long. Retry when the connection is ready.' : error?.message || 'The listening report could not be loaded.', 'error');
      byId('Retry').hidden = error?.retryable === false;
      root.dataset.reportState = 'error';
    } finally {
      clearTimeout(timeout);
      if (id === generation) { root.removeAttribute('aria-busy'); request = null; }
    }
  }
  function setDirty() {
    dirty = true; generation++; request?.abort(); request = null;
    root.removeAttribute('aria-busy'); root.dataset.reportState = 'changed';
    showData(false); byId('Retry').hidden = true;
    status('Filters changed. Choose Update report to load the matching figures.', 'pending');
  }
  function updateDates() {
    const custom = byId('Period').value === 'custom';
    byId('CustomDates').hidden = !custom;
    byId('Start').required = custom; byId('End').required = custom;
  }
  form.addEventListener('change', event => {
    if (event.target === byId('Period')) updateDates();
    if (event.target === byId('Channel')) {
      if (byId('Channel').value === 'legacy') byId('Traffic').value = 'all';
      if (['native','legacy'].includes(byId('Channel').value)) byId('Site').value = '';
    }
    if (event.target === byId('Site') && byId('Site').value) byId('Channel').value = 'provider';
    if (event.target === byId('Traffic') && byId('Channel').value === 'legacy' && byId('Traffic').value !== 'all') byId('Channel').value = 'all';
    setDirty();
  });
  form.addEventListener('submit', event => {event.preventDefault(); if (!form.reportValidity()) return; offset = 0; load(readInput());});
  byId('Refresh').addEventListener('click', () => {if (!form.reportValidity()) return; offset = 0; load(readInput());});
  byId('Retry').addEventListener('click', () => {if (lastInput) load({...lastInput});});
  byId('Reset').addEventListener('click', () => {form.reset(); byId('Timezone').value = localZone; updateDates(); offset = 0; load(readInput());});
  byId('Breakdown').addEventListener('change', () => {
    if (dirty || !report) return;
    if (offset) {offset = 0; load({...lastInput, offset});} else renderBreakdown(report);
  });
  byId('Previous').addEventListener('click', () => {if (!report || dirty || request) return; offset = Math.max(0, offset - limit); load({...lastInput, offset});});
  byId('Next').addEventListener('click', () => {if (!report || dirty || request || !report.pagination?.[byId('Breakdown').value]?.has_more) return; offset += limit; load({...lastInput, offset});});
  window.addEventListener('pagehide', () => {generation++; request?.abort();});
  updateDates();
  load(readInput());
})();
