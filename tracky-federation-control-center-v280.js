(() => {
  const root = document.getElementById('federationControlCenter');
  if (!root) return;
  const endpoint = root.dataset.endpoint || '/api/tracky-federation-operations-v280.php';
  const esc = (value = '') => String(value).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const nice = (value) => {
    const text = String(value || 'unknown').replace(/_/g, ' ');
    return text.charAt(0).toUpperCase() + text.slice(1);
  };
  const badge = (state) => '<span class="fed280-badge ' + esc(state || 'unknown') + '">' + esc(nice(state)) + '</span>';

  function siteCard(site) {
    const authority = site.authority || {};
    const federation = site.federation || {};
    return '<article class="fed280-site ' + esc(site.health || 'unknown') + '">' +
      '<div class="fed280-site-head"><div><small>SITE</small><h3>' + esc(site.label || site.id) + '</h3></div>' + badge(site.health) + '</div>' +
      '<code>' + esc(site.id || '') + '</code>' +
      '<div class="fed280-site-metrics">' +
        '<div><span>Federation</span><strong>' + esc(nice(federation.status)) + '</strong></div>' +
        '<div><span>Devices</span><strong>' + Number(site.device_count || 0) + '</strong></div>' +
        '<div><span>Authority</span><strong>' + esc(authority.status || 'unknown') + '</strong></div>' +
        '<div><span>Epoch</span><strong>' + Number(authority.epoch || 0) + '</strong></div>' +
      '</div>' +
      '<div class="fed280-authority">Authority device <code>' + esc(authority.device_id || 'unassigned') + '</code></div>' +
    '</article>';
  }

  function render(report) {
    const summary = report.summary || {};
    root.querySelector('[data-fed280-health]').innerHTML = badge(report.health || 'unknown');
    root.querySelector('[data-fed280-sites]').textContent = Number(summary.site_count || 0);
    root.querySelector('[data-fed280-devices]').textContent = Number(summary.device_count || 0);
    root.querySelector('[data-fed280-current]').textContent = Number(summary.current_site_count || 0);
    root.querySelector('[data-fed280-issues]').textContent = Number((report.issues || []).length);
    root.querySelector('[data-fed280-topology]').textContent = String(report.topology_revision || 0);
    root.querySelector('[data-fed280-site-cards]').innerHTML = (report.sites || []).length
      ? report.sites.map(siteCard).join('')
      : '<div class="fed280-empty">No federated topology mirror is available yet.</div>';
    root.querySelector('[data-fed280-issue-list]').innerHTML = (report.issues || []).length
      ? report.issues.map(row => '<div class="fed280-issue ' + esc(row.severity || 'unknown') + '"><strong>' + esc(row.code || 'issue') + '</strong><span>' + esc(row.message || '') + '</span></div>').join('')
      : '<div class="fed280-clear">No mirrored federation issues are currently reported.</div>';
    root.dataset.health = report.health || 'unknown';
  }

  async function refresh() {
    const status = root.querySelector('[data-fed280-status]');
    try {
      const response = await fetch(endpoint, {credentials:'same-origin', headers:{'Accept':'application/json'}});
      const data = await response.json();
      if (!response.ok || data.ok === false) throw new Error(data.error || 'Federation control center unavailable.');
      const wrapper = data.federation_operations || {};
      if (!wrapper.available) {
        status.textContent = 'Awaiting HomeServer topology mirror';
        render({health:'unknown',summary:{},sites:[],issues:[]});
        return;
      }
      render(wrapper.operations || {});
      status.textContent = 'Live Cloud mirror · local freshness is never inferred here';
    } catch (err) {
      status.textContent = err.message || 'Federation control center unavailable.';
    }
  }

  root.querySelector('[data-fed280-refresh]')?.addEventListener('click', refresh);
  refresh();
  setInterval(() => { if (document.visibilityState === 'visible') refresh(); }, 5000);
})();
