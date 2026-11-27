/* Read-only lifetime aggregates. Source labels are text; listener identities are never requested. */
(() => {
  'use strict';
  const protocol = 'vp3_music_listening_totals_v1';
  const safeCount = value => Number.isSafeInteger(value) && value >= 0;
  const integer = value => safeCount(value) ? value.toLocaleString() : 'Unavailable';
  const quantity = (value, singular, plural = singular + 's') => integer(value) + ' ' + (value === 1 ? singular : plural);
  const time = value => {
    if (!safeCount(value)) return 'Unavailable';
    const seconds = Math.floor(value / 1000);
    if (seconds < 60) return seconds + ' s';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' m ' + seconds % 60 + ' s';
    return Math.floor(seconds / 3600).toLocaleString() + ' h ' + Math.floor(seconds % 3600 / 60) + ' m';
  };
  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined && text !== null) element.textContent = String(text);
    if (className) element.className = className;
    return element;
  };
  const stamp = value => {
    if (!value) return 'Not observed';
    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return 'Unavailable';
    return new Intl.DateTimeFormat(undefined, {dateStyle:'medium', timeStyle:'short', timeZone:'UTC'}).format(date) + ' UTC';
  };

  for (const root of document.querySelectorAll('[data-listening-totals]')) {
    const scope = root.dataset.scope;
    const workspace = Number(root.dataset.workspace || 0);
    if (!['workspace', 'platform'].includes(scope) || scope === 'workspace' && (!safeCount(workspace) || !workspace)) continue;
    const platform = scope === 'platform';
    const prefix = root.id;
    const refs = {};
    const identified = (tag, key, text, className) => {
      const element = node(tag, text, className);
      element.id = prefix + key;
      refs[key] = element;
      return element;
    };
    const button = (key, label, action, primary = false) => {
      const element = identified('button', key, label, 'mlt-button' + (primary ? ' primary' : ''));
      element.type = 'button';
      if (action) element.addEventListener('click', action);
      return element;
    };
    let selectedWorkspace = platform ? 0 : workspace;
    let selectedName = '';
    let offset = 0;
    let platformOffset = 0;
    let generation = 0;
    let controller = null;
    let report = null;
    const limit = 25;

    root.querySelector('.mlt-heading').append(button('Refresh', 'Refresh totals', () => load()));
    const context = identified('div', 'Context', null, 'mlt-context');
    const back = button('Back', 'Back to platform totals', () => {
      selectedWorkspace = 0;
      selectedName = '';
      offset = platformOffset;
      load();
    });
    back.hidden = true;
    context.append(identified('p', 'ScopeLabel', platform ? 'All artist workspaces' : 'This artist workspace'), back);
    root.append(context);
    const form = identified('form', 'Filters', null, 'mlt-filters');
    const fields = node('div', null, 'mlt-fields');
    const select = (key, label, choices) => {
      const wrapper = node('label', label);
      const control = identified('select', key);
      for (const [value, labelText] of choices) {
        const option = node('option', labelText);
        option.value = value;
        control.append(option);
      }
      wrapper.htmlFor = control.id;
      wrapper.append(control);
      fields.append(wrapper);
      control.addEventListener('change', () => {
        offset = 0;
        generation++;
        controller?.abort();
        root.dataset.reportState = 'changed';
        root.setAttribute('aria-busy', 'false');
        refs.Data.hidden = true;
        refs.Retry.hidden = true;
        setStatus('Filters changed. Select Update totals to apply them.', 'pending');
      });
    };
    select('Channel', 'Source', [['all','VP3 + connected sites'],['native','VP3 player'],['provider','Connected artist sites']]);
    select('Traffic', 'Traffic', [['audience','Audience observations'],['tests','Known previews & staging'],['all','All observed traffic']]);
    const actions = node('div', null, 'mlt-actions');
    const apply = button('Apply', 'Update totals', null, true);
    apply.type = 'submit';
    actions.append(apply, button('Reset', 'Reset filters', () => {
      refs.Channel.value = 'all';
      refs.Traffic.value = 'audience';
      offset = 0;
      load();
    }));
    form.append(fields, actions, node('p', 'Audience observations exclude known owner previews and staging. Anonymous production listening may include unrecognized self-tests.', 'mlt-muted'));
    form.addEventListener('submit', event => {event.preventDefault(); offset = 0; load();});
    root.append(form);
    const status = identified('p', 'Status', 'Loading all-time listening totals…', 'mlt-status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    root.append(status, button('Retry', 'Retry totals', () => load()));
    refs.Retry.hidden = true;
    const data = identified('div', 'Data');
    data.hidden = true;
    data.append(identified('div', 'Metrics', null, 'mlt-metrics'), identified('div', 'Sources', null, 'mlt-sources'));
    data.append(node('p', 'One listen requires at least 10 seconds of accepted listening. Listens are play sessions, not unique people. Sessions can update while playback continues.', 'mlt-method'));
    data.append(identified('p', 'History', null, 'mlt-muted'), identified('p', 'Freshness', null, 'mlt-muted'));
    const coverage = identified('details', 'Coverage', null, 'mlt-coverage');
    coverage.append(node('summary', 'Coverage and counting details'), identified('div', 'CoverageNotes'));
    data.append(coverage);
    const breakdown = node('section', null, 'mlt-breakdown');
    const heading = identified('h3', 'BreakdownTitle');
    breakdown.setAttribute('aria-labelledby', heading.id);
    breakdown.append(heading, identified('p', 'BreakdownNote', null, 'mlt-muted'), identified('div', 'Table'));
    const pagination = identified('div', 'Pagination', null, 'mlt-pagination');
    const pageLabel = identified('span', 'PageLabel');
    pageLabel.setAttribute('aria-live', 'polite');
    pagination.append(button('Previous', 'Previous', () => {offset = Math.max(0, offset - limit); load();}), pageLabel,
      button('Next', 'Next', () => {if (report?.groups?.has_more && Number.isSafeInteger(offset + limit)) {offset += limit; load();}}));
    breakdown.append(pagination);
    data.append(breakdown);
    root.append(data);

    function setStatus(message, state = '') {
      status.textContent = message;
      status.className = 'mlt-status' + (state ? ' ' + state : '');
      status.hidden = !message;
    }
    function availability(data) {
      const selected = data.filters.channel === 'all' ? ['native', 'provider'] : [data.filters.channel];
      return {
        any: selected.some(channel => data.availability[channel] === 'available'),
        complete: selected.every(channel => data.availability[channel] === 'available'),
      };
    }
    function metric(key, label, value, note) {
      const item = node('article', null, 'mlt-metric');
      item.dataset.metric = key;
      item.append(node('p', label), node('strong', value), node('small', note));
      return item;
    }
    function renderMetrics(data, available) {
      const summary = data.summary;
      const partial = available.any && !available.complete;
      refs.Metrics.replaceChildren(
        metric('qualified_plays', 'All-time listens (10s+)', available.any ? integer(summary.qualified_plays) : 'Unavailable',
          partial ? 'Partial total · available measurement sources' : 'All retained measured listening'),
        metric('starts', 'Playback starts', available.any ? integer(summary.starts) : 'Unavailable',
          available.any ? quantity(summary.playback_requests, 'playback request') : 'Measurement sources are unavailable'),
        metric('listening_ms', 'Accepted listening', available.any ? time(summary.listening_ms) : 'Unavailable', 'Server-accepted listening time')
      );
      refs.Sources.replaceChildren();
      for (const [key, label] of [['native','VP3 player'],['provider','Connected sites']]) {
        const source = node('div');
        const value = data.availability[key] === 'not_selected' ? 'Not selected'
          : data.availability[key] === 'available' ? quantity(data.channels[key].qualified_plays, 'listen') : 'Unavailable';
        source.append(node('span', label), node('strong', value));
        refs.Sources.append(source);
      }
      refs.History.textContent = data.history?.first_measured_at
        ? 'Selected measured sessions observed from ' + stamp(data.history.first_measured_at) + ' through ' + stamp(data.history.last_measured_at) + '. This is the retained observation window, not a verified lifetime start date.'
        : available.any ? 'No selected measured sessions have been observed in the retained history.' : 'The retained observation window is unavailable.';
      refs.Freshness.textContent = 'Snapshot: ' + stamp(data.snapshot_at) + '. Refresh to include newly accepted listening.';
    }
    function renderCoverage(data) {
      const target = refs.CoverageNotes;
      target.replaceChildren();
      target.append(node('p', 'Older legacy measurements stay separate and never increase the measured all-time listen count. Removed listening history cannot be reconstructed from these totals.'));
      if (data.coverage?.legacy_included && data.availability.legacy === 'available') {
        target.append(node('p', 'Separate legacy history: ' + quantity(data.summary.legacy_sessions, 'session') + ', ' + time(data.summary.legacy_listening_ms) + ' historical listening.'));
      }
      for (const note of Array.isArray(data.coverage?.notes) ? data.coverage.notes : []) target.append(node('p', note));
      const observations = [
        ['Partial observations','partial_sessions'],['Unknown duration','unknown_duration_sessions'],['Ongoing sessions','ongoing_sessions'],
        ['Known previews','preview_sessions'],['Authorized private sessions','private_sessions'],['Staging sessions','staging_sessions'],
        ['Native sessions without a safe catalog association','native_unassociated_sessions'],['Connected sessions without a safe catalog association','provider_unassociated_sessions'],
        ['Native sessions with unknown classification','native_unclassified_sessions'],['Connected sessions with unknown classification','provider_unclassified_sessions'],
        ['Catalog songs without recording records','catalog_tracks_without_recordings'],['Recording records without a safe catalog association','orphan_recordings'],
      ];
      const list = node('ul');
      for (const [label, key] of observations) {
        const value = data.coverage?.[key];
        if (safeCount(value) && value > 0) list.append(node('li', label + ': ' + integer(value)));
      }
      if (list.childElementCount) target.append(list);
    }
    function renderRows(data, available) {
      const groups = data.groups;
      const workspaces = groups.type === 'workspaces';
      refs.BreakdownTitle.textContent = workspaces ? 'Artist workspace totals' : 'Song totals';
      refs.BreakdownNote.textContent = workspaces
        ? 'Choose an artist workspace to view its songs. The platform totals include every matching workspace, independently of the page shown. Workspaces with no measured listening are included.'
        : 'Totals above include every matching song. This list is paged independently and includes catalog songs with no measured listening.';
      const container = refs.Table;
      container.replaceChildren();
      if (!groups.rows.length) {
        container.append(node('p', groups.total > 0 ? 'No rows remain on this page. Use Previous to return to the catalog.'
          : workspaces ? 'No artist workspaces are available for this report.' : 'No safely associated songs are available in this catalog yet.', 'mlt-empty'));
      } else {
        const table = node('table', null, 'mlt-table');
        table.append(node('caption', (workspaces ? 'Artist workspace' : 'Song') + ' listening totals for the selected source and traffic.'));
        const headings = [workspaces ? 'Artist workspace' : 'Song', 'Listens (10s+)', 'Starts', 'Accepted listening'];
        const thead = node('thead'), tr = node('tr');
        for (const label of headings) {const th = node('th', label); th.scope = 'col'; tr.append(th);}
        thead.append(tr); table.append(thead);
        const tbody = node('tbody');
        for (const item of groups.rows) {
          const row = node('tr');
          const name = node('td');
          name.dataset.label = headings[0];
          if (workspaces) {
            const choose = node('button', null, 'mlt-row-button');
            choose.type = 'button';
            choose.setAttribute('aria-label', 'View songs for ' + (item.workspace_name || 'Untitled workspace'));
            choose.append(node('strong', item.workspace_name || 'Untitled workspace'), node('span', 'View songs'));
            choose.addEventListener('click', () => {
              platformOffset = offset;
              selectedWorkspace = item.workspace_id;
              selectedName = item.workspace_name || 'Untitled workspace';
              offset = 0;
              load();
            });
            name.append(choose);
          } else name.append(node('strong', item.title || 'Untitled song'));
          row.append(name);
          const values = available.any ? [integer(item.metrics.qualified_plays), integer(item.metrics.starts), time(item.metrics.listening_ms)] : ['Unavailable','Unavailable','Unavailable'];
          for (let i = 0; i < values.length; i++) {
            const cell = node('td', values[i]);
            cell.dataset.label = headings[i + 1];
            row.append(cell);
          }
          tbody.append(row);
        }
        table.append(tbody); container.append(table);
      }
      refs.PageLabel.textContent = groups.total ? (groups.rows.length ? integer(groups.offset + 1) + '–' + integer(groups.offset + groups.rows.length) : '0') + ' of ' + integer(groups.total) : '0 ' + (workspaces ? 'workspaces' : 'songs');
      refs.Previous.disabled = groups.offset === 0;
      refs.Next.disabled = !groups.has_more;
      refs.Pagination.hidden = groups.total === 0;
    }
    function validate(data, input) {
      const metrics = value => value && ['playback_requests','starts','qualified_plays','listening_ms'].every(key => safeCount(value[key]));
      const groups = data?.groups;
      if (!data?.ok || data.protocol !== protocol || !metrics(data.summary) || !data.availability || !data.filters
        || !['native','provider'].every(channel => ['available','unavailable','not_selected'].includes(data.availability[channel])
          && (data.availability[channel] !== 'available' || metrics(data.channels?.[channel])))
        || data.filters.scope !== input.scope || data.filters.workspace_id !== (input.workspace_id || 0)
        || data.filters.channel !== input.channel || data.filters.traffic !== input.traffic
        || !groups || groups.type !== input.group || !Array.isArray(groups.rows) || groups.rows.length > input.limit
        || !safeCount(groups.total) || groups.offset !== input.offset || groups.limit !== input.limit || typeof groups.has_more !== 'boolean'
        || !groups.rows.every(item => metrics(item.metrics) && safeCount(item.workspace_id) && item.workspace_id > 0 && (!input.workspace_id || item.workspace_id === input.workspace_id))
        || !Number.isFinite(new Date(data.snapshot_at).getTime())) throw new Error('invalid_report');
      return data;
    }
    async function load() {
      controller?.abort();
      controller = new AbortController();
      const active = controller;
      const current = ++generation;
      const input = {
        protocol, action:'report', scope, channel:refs.Channel.value, traffic:refs.Traffic.value,
        group:platform && !selectedWorkspace ? 'workspaces' : 'tracks', limit, offset, csrf_token:root.dataset.csrf,
      };
      if (selectedWorkspace) input.workspace_id = selectedWorkspace;
      root.dataset.reportState = 'loading';
      root.setAttribute('aria-busy', 'true');
      refs.ScopeLabel.textContent = platform ? selectedWorkspace ? 'Selected artist workspace: ' + selectedName : 'All artist workspaces' : 'This artist workspace';
      back.hidden = !platform || !selectedWorkspace;
      refs.Data.hidden = true;
      refs.Retry.hidden = true;
      setStatus('Loading all-time listening totals…');
      const timeout = setTimeout(() => active.abort(), 30000);
      let response;
      try {
        response = await fetch(root.dataset.endpoint, {
          method:'POST', credentials:'same-origin', cache:'no-store', signal:active.signal,
          headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify(input),
        });
        const data = await response.json();
        if (current !== generation) return;
        if (!response.ok || !data.ok) throw new Error('report_failed');
        report = validate(data, input);
        const available = availability(report);
        renderMetrics(report, available);
        renderCoverage(report);
        renderRows(report, available);
        refs.Data.hidden = false;
        root.dataset.reportState = 'ready';
        if (!available.any) setStatus('Listening measurement is unavailable for the selected sources. No listen count is being reported.', 'pending');
        else if (!available.complete) setStatus('Partial total: a selected measurement source is unavailable. The figures include only available measured history.', 'pending');
        else if (report.summary.playback_requests === 0) setStatus('No matching measured listening has been recorded for these filters. Catalog songs remain available below.', 'pending');
        else setStatus('All-time totals updated. Date filters in the separate historical report do not change these figures.');
      } catch (error) {
        if (current !== generation) return;
        report = null;
        root.dataset.reportState = 'error';
        refs.Data.hidden = true;
        const code = response?.status;
        const canRetry = !code || code >= 500 || code === 429 || response?.ok;
        refs.Retry.hidden = !canRetry;
        const message = code === 401 || code === 403 || code === 419 ? 'Your session or reporting access changed. Reload this page and sign in to an authorized account.'
          : code === 400 || code === 422 ? 'These totals could not be requested. Reset the filters and try again.'
          : 'All-time listening totals are temporarily unavailable. Retry to load a fresh report.';
        setStatus(message, 'error');
      } finally {
        clearTimeout(timeout);
        if (current === generation) root.setAttribute('aria-busy', 'false');
      }
    }
    load();
  }
})();
