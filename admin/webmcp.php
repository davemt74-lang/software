<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-admin-v202.php';
require_permission('admin.access');

$pdo=db();
$ownerFilter=max(0,(int)($_GET['owner_user_id']??0));
$propertyFilter=max(0,(int)($_GET['property_id']??0));
$overview=$pdo?vp3_profile_webmcp_admin_overview_v202($pdo,$ownerFilter,$propertyFilter):[
    'active_profiles'=>0,'connected_sites'=>0,'summary'=>[],'events'=>[],'owners'=>[],'properties'=>[],'release'=>[],'release_audit'=>['ok'=>false],
];
$summary=is_array($overview['summary']??null)?$overview['summary']:[];
$events=is_array($overview['events']??null)?$overview['events']:[];
$runtime=is_array($summary['runtime_distribution']??null)?$summary['runtime_distribution']:[];
$surfaces=is_array($summary['surface_distribution']??null)?$summary['surface_distribution']:[];
$modes=is_array($summary['negotiation_distribution']??null)?$summary['negotiation_distribution']:[];
$failureRate=(float)($summary['failure_rate_percent']??0);

$adminTitle='WebMCP Operations';
$adminActive='webmcp';
require __DIR__.'/_header.php';
?>
<section class="admin-dashboard-hero">
  <div>
    <span class="eyebrow">AI &amp; Platform</span>
    <h2>WebMCP Operations</h2>
    <p>System health, runtime compatibility, connected-site activity and safe operational diagnostics across Native Profile, Connected Sites and Agent Brain.</p>
  </div>
  <div class="actions">
    <a class="btn" href="<?= e(url('/admin/webmcp.php')) ?>">Reset filters</a>
    <a class="btn primary" href="<?= e(url('/admin/release-command-center.php')) ?>">Release Command Center</a>
  </div>
</section>

<section class="admin-kpi-grid" aria-label="WebMCP operating metrics">
  <article class="admin-kpi"><div class="admin-kpi-head"><span>Release health</span><span>Current</span></div><strong><?= !empty($overview['release_audit']['ok'])?'Healthy':'Degraded' ?></strong><small><?= e((string)($overview['release']['version']??'Unknown release')) ?></small></article>
  <article class="admin-kpi"><div class="admin-kpi-head"><span>Active Profiles</span><span>Public</span></div><strong><?= number_format((int)($overview['active_profiles']??0)) ?></strong><small>Public and active Profile surfaces</small></article>
  <article class="admin-kpi"><div class="admin-kpi-head"><span>Connected sites</span><span>Active</span></div><strong><?= number_format((int)($overview['connected_sites']??0)) ?></strong><small>Verified runtime properties available to WebMCP</small></article>
  <article class="admin-kpi"><div class="admin-kpi-head"><span>Failure rate</span><span>30-day sample</span></div><strong><?= e(number_format($failureRate,1)) ?>%</strong><small><?= number_format((int)($summary['failures']??0)+(int)($summary['denied']??0)) ?> failed or denied terminal attempts</small></article>
</section>

<section class="admin-dashboard-card">
  <div class="admin-dashboard-card-head">
    <div><h3>Scope</h3><p>Filter diagnostics by account or connected property.</p></div>
  </div>
  <form method="get" class="admin-form-grid">
    <label>Owner
      <select name="owner_user_id">
        <option value="0">All owners</option>
        <?php foreach((array)($overview['owners']??[]) as $owner): ?><option value="<?= (int)$owner['id'] ?>"<?= $ownerFilter===(int)$owner['id']?' selected':'' ?>><?= e((string)$owner['display_name']) ?> · <?= e((string)$owner['email']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Connected property
      <select name="property_id">
        <option value="0">All properties</option>
        <?php foreach((array)($overview['properties']??[]) as $property): ?><option value="<?= (int)$property['id'] ?>"<?= $propertyFilter===(int)$property['id']?' selected':'' ?>><?= e((string)$property['label']) ?> · <?= e((string)$property['domain']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <div class="actions" style="align-self:end"><button class="btn primary" type="submit">Apply</button></div>
  </form>
</section>

<div class="admin-dashboard-grid">
  <section class="admin-dashboard-card">
    <div class="admin-dashboard-card-head"><div><h3>Runtime distribution</h3><p>Observed sanitized runtime builds from canonical WebMCP events.</p></div></div>
    <div class="admin-package-list">
      <?php foreach($runtime as $build=>$count): ?><div class="admin-package-row"><div><strong><?= e((string)$build) ?></strong><small><?= number_format((int)$count) ?> observed event<?= (int)$count===1?'':'s' ?></small></div></div><?php endforeach; ?>
      <?php if(!$runtime): ?><div class="admin-activity-row"><div><strong>No runtime observations yet</strong><small>Version distribution appears after WebMCP clients call the platform.</small></div></div><?php endif; ?>
    </div>
  </section>

  <section class="admin-dashboard-card">
    <div class="admin-dashboard-card-head"><div><h3>Negotiation &amp; surfaces</h3><p>Compatibility modes and where WebMCP traffic is arriving.</p></div></div>
    <div class="admin-attention-list">
      <?php foreach($modes as $mode=>$count): ?><div class="admin-attention-row"><div><strong><?= e((string)$mode) ?></strong><small>Negotiation mode</small></div><span class="admin-attention-badge"><?= number_format((int)$count) ?></span></div><?php endforeach; ?>
      <?php foreach($surfaces as $surface=>$count): ?><div class="admin-attention-row"><div><strong><?= e((string)$surface) ?></strong><small>Execution surface</small></div><span class="admin-attention-badge"><?= number_format((int)$count) ?></span></div><?php endforeach; ?>
      <?php if(!$modes&&!$surfaces): ?><div class="admin-activity-row"><div><strong>No surface activity yet</strong><small>Runtime and negotiation state will appear here.</small></div></div><?php endif; ?>
    </div>
  </section>
</div>

<section class="admin-dashboard-card">
  <div class="admin-dashboard-card-head">
    <div><h3>Recent WebMCP activity</h3><p>Sanitized operational events only. Tool inputs and credentials are never shown.</p></div>
    <span><?= number_format(count($events)) ?> events</span>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Time</th><th>Surface</th><th>Event</th><th>Tool</th><th>Runtime</th><th>Result</th><th>Latency</th></tr></thead>
      <tbody>
      <?php foreach(array_slice($events,0,100) as $event): ?>
        <tr>
          <td><?= e((string)$event['occurred_at']) ?></td>
          <td><?= e((string)$event['surface']) ?></td>
          <td><?= e((string)$event['event_name']) ?></td>
          <td><?= e((string)$event['tool']) ?></td>
          <td><?= e((string)$event['client_runtime_build']) ?></td>
          <td><?= e((string)($event['result_code']?:$event['status'])) ?></td>
          <td><?= number_format((int)$event['duration_ms']) ?> ms</td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$events): ?><tr><td colspan="7">No WebMCP activity in the selected scope.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php require __DIR__.'/_footer.php'; ?>
