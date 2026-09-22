<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/chrome-extension-releases.php';
require_login();
require_permission('users.manage');

$pdo=db();
if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

homeserver_vp3_ensure_schema($pdo);
chrome_extension_releases_ensure_schema($pdo);
client_release_rollouts_ensure_schema_v110($pdo);
client_release_health_ensure_schema_v120($pdo);
client_release_incident_ensure_schema_v130($pdo);
client_release_risk_ensure_schema_v140($pdo);
client_release_readiness_ensure_schema_v150($pdo);
client_fleet_ensure_schema_v160($pdo);
client_release_automation_ensure_schema_v170($pdo);

$adminUser=current_user();
$adminUserId=(int)($adminUser['id']??0);
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){
        $error='Session expired. Please try again.';
    }else{
        try{
            $action=(string)($_POST['action']??'');
            if($action==='command_run_automation'){
                $run=client_release_automation_run_v170($pdo,'manual',$adminUserId,true);
                flash('notice','Governed automation run completed: '.(int)($run['proposals_created']??0).' proposal(s), '.(int)($run['actions_executed']??0).' action(s), '.(int)($run['holds_created']??0).' hold(s).');
            }elseif(in_array($action,['command_kill_on','command_kill_off'],true)){
                $control=client_release_automation_control_v170($pdo);
                client_release_automation_control_update_v170($pdo,[
                    'automation_enabled'=>!empty($control['automation_enabled'])?'1':'',
                    'dry_run'=>!empty($control['dry_run'])?'1':'',
                    'kill_switch'=>$action==='command_kill_on'?'1':'',
                ],$adminUserId);
                flash('notice',$action==='command_kill_on'?'Global release automation kill switch activated. Automatic execution is blocked.':'Global release automation kill switch cleared.');
            }else{
                throw new RuntimeException('Unsupported Release Command Center action.');
            }
            if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
            redirect(url('/admin/release-command-center.php'));
        }catch(Throwable $e){
            $error=$e->getMessage();
        }
    }
}

$commandCenter=client_release_command_center_build_v180($pdo);
$status=(array)$commandCenter['status'];
$metrics=(array)$commandCenter['metrics'];
$intel=(array)$commandCenter['intelligence'];
$releaseRows=(array)$commandCenter['releases'];
$attention=(array)$commandCenter['attention'];
$incidents=(array)$commandCenter['incidents'];
$fleet=(array)$commandCenter['fleet'];
$fleetSummary=(array)($fleet['summary']??[]);
$automation=(array)$commandCenter['automation'];
$automationControl=(array)($automation['control']??[]);
$automationPending=(array)($automation['pending']??[]);
$automationHolds=(array)($automation['holds']??[]);
$audit=(array)$commandCenter['audit'];
$liveAutomation=!empty($automationControl['automation_enabled'])&&empty($automationControl['kill_switch'])&&empty($automationControl['dry_run']);

$adminTitle='Release Command Center';
$adminActive='release-command-center';
require __DIR__.'/_header.php';
?>
<style>
.release-command-status{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}
.release-command-status-main{min-width:0}
.release-command-status-main h2{margin:2px 0 4px;font-size:1.35rem}
.release-command-status-main p{margin:0;color:#727b86}
.release-command-metrics{grid-template-columns:repeat(4,minmax(0,1fr))!important;margin-bottom:18px}
.release-command-severity{display:inline-flex;align-items:center;min-height:24px;padding:3px 8px;border:1px solid #d7dce1;border-radius:999px;background:#f7f8f9;color:#58616b;font-size:.6rem;font-weight:900;letter-spacing:.05em;text-transform:uppercase}
.release-command-severity[data-level="critical"],.release-command-severity[data-level="high"]{border-color:#ddc1c1;background:#fff7f7;color:#8c4040}
.release-command-severity[data-level="action"]{border-color:#dfd2b6;background:#fffaf0;color:#765d2b}
.release-command-severity[data-level="normal"]{border-color:#c6d9cb;background:#f6fbf7;color:#396348}
.release-command-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.release-command-stack{display:grid;gap:18px;margin-bottom:18px}
.release-command-quicklinks{display:flex;gap:8px;flex-wrap:wrap}
@media(max-width:1100px){.release-command-metrics{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:760px){.release-command-status{display:grid}.release-command-actions{justify-content:flex-start}.release-command-metrics{grid-template-columns:1fr!important}}
</style>

<div class="admin-section-heading">
  <div>
    <span class="eyebrow">Client Release Operations · v1.80</span>
    <h2>Release Command Center</h2>
    <p>One operator view across release intelligence, controlled rollouts, health, incidents, risk, readiness, fleet maintenance, governed automation, and the durable audit trail.</p>
  </div>
  <div class="form-actions">
    <a class="button" href="<?= e(url('/admin/homeserver.php')) ?>">Open detailed Client Releases</a>
  </div>
</div>

<?php if($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

<section class="admin-card" style="margin-bottom:18px">
  <div class="release-command-status">
    <div class="release-command-status-main">
      <span class="release-command-severity" data-level="<?= e((string)$status['key']) ?>"><?= e((string)$status['label']) ?></span>
      <h2><?= e((string)$status['label']) ?></h2>
      <p><?= e((string)$status['detail']) ?> · Snapshot <?= e((string)$commandCenter['generated_at']) ?></p>
    </div>
    <div class="release-command-actions">
      <form method="post" <?= $liveAutomation?'onsubmit="return confirm(\'Live low-risk automation is enabled. Run the governed automation engine now?\');"':'' ?>>
        <?= csrf_field() ?><input type="hidden" name="action" value="command_run_automation">
        <button class="button <?= $liveAutomation?'primary':'' ?>" type="submit"><?= $liveAutomation?'Run governed automation':'Run automation evaluation' ?></button>
      </form>
      <?php if(!empty($automationControl['kill_switch'])): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="command_kill_off"><button class="button" type="submit">Clear kill switch</button></form>
      <?php else: ?>
        <form method="post" onsubmit="return confirm('Activate the global release automation kill switch? Automatic execution will stop immediately.');"><?= csrf_field() ?><input type="hidden" name="action" value="command_kill_on"><button class="button danger" type="submit">Activate kill switch</button></form>
      <?php endif; ?>
    </div>
  </div>
</section>

<div class="admin-grid release-command-metrics">
  <?php foreach([
    ['Tracked clients',$metrics['tracked_clients']],
    ['Active incidents',$metrics['active_incidents']],
    ['Attention items',$metrics['attention_items']],
    ['Automation decisions',$metrics['pending_automation']],
    ['Automation holds',$metrics['automation_holds']],
    ['Unsupported clients',$metrics['unsupported_clients']],
    ['Stale clients',$metrics['stale_clients']],
    ['Compatibility drift',$metrics['compatibility_drift']],
  ] as [$label,$value]): ?>
    <div class="metric"><strong><?= (int)$value ?></strong><span><?= e($label) ?></span></div>
  <?php endforeach; ?>
</div>

<section class="admin-card" style="margin-bottom:18px">
  <div class="admin-card-head">
    <div><h3>Operator Attention Queue</h3><p>Prioritized conditions and decisions gathered across v1.20–v1.70. The command center does not bypass the underlying governed control that owns each item.</p></div>
    <span class="eyebrow"><?= count($attention) ?> open</span>
  </div>
  <?php if($attention): ?>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Priority</th><th>Area</th><th>Item</th><th>Detail</th><th></th></tr></thead>
    <tbody>
    <?php foreach($attention as $item): ?>
      <tr>
        <td><span class="release-command-severity" data-level="<?= e((string)$item['severity']) ?>"><?= e((string)$item['severity']) ?></span></td>
        <td><?= e(client_release_command_center_state_label_v180((string)$item['type'])) ?></td>
        <td><strong><?= e((string)$item['title']) ?></strong></td>
        <td><small><?= e((string)$item['detail']) ?></small></td>
        <td><a class="button button-small" href="<?= e(url((string)$item['href'])) ?>">Review</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p>No release-operation attention items are currently open.</p><?php endif; ?>
</section>

<section class="admin-card" style="margin-bottom:18px">
  <div class="admin-card-head">
    <div><h3>Managed Release Matrix</h3><p>Current non-retired Browser Companion and HomeServer releases with rollout, health, risk, readiness, incident, and automation state side by side.</p></div>
    <a class="button button-small" href="<?= e(url('/admin/homeserver.php#controlled-rollouts')) ?>">Manage rollouts</a>
  </div>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Release</th><th>Rollout</th><th>Health</th><th>Risk</th><th>Readiness</th><th>Incident</th><th>Automation</th></tr></thead>
    <tbody>
    <?php foreach($releaseRows as $row): ?>
      <tr>
        <td><strong><?= e((string)$row['product_label']) ?> v<?= e((string)$row['version']) ?></strong><br><small><?= e((string)$row['channel']) ?> · #<?= (int)$row['release_id'] ?></small></td>
        <td><?= e(client_release_command_center_state_label_v180((string)$row['lifecycle_state'])) ?><br><small><?= (int)$row['rollout_percent'] ?>%</small></td>
        <td><?= e(client_release_command_center_state_label_v180((string)$row['health_status'])) ?><br><small><?= e(client_release_health_recommendation_label_v120((string)$row['health_recommendation'])) ?></small></td>
        <td><?= e(ucfirst((string)$row['risk_level'])) ?><br><small>Score <?= (int)$row['risk_score'] ?></small></td>
        <td><?= e(client_release_command_center_state_label_v180((string)$row['readiness_state'])) ?><?php if((string)$row['readiness_reason']!==''): ?><br><small><?= e((string)$row['readiness_reason']) ?></small><?php endif; ?></td>
        <td><?= (int)$row['incident_id']>0?'<strong>'.e(client_release_command_center_state_label_v180((string)$row['incident_status'])).'</strong><br><small>#'.(int)$row['incident_id'].'</small>':'—' ?></td>
        <td><?= (int)$row['proposal_id']>0?'<strong>#'.(int)$row['proposal_id'].'</strong><br><small>'.e(client_release_command_center_state_label_v180((string)$row['proposal_type'])).' · '.e((string)$row['proposal_status']).'</small>':'—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if(!$releaseRows): ?><tr><td colspan="7">No active managed releases were found.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<div class="admin-grid admin-grid-2" style="margin-bottom:18px">
  <section class="admin-card">
    <div class="admin-card-head"><div><h3>Fleet Health</h3><p>v1.60 support, staleness, incident, and compatibility posture.</p></div><a class="button button-small" href="<?= e(url('/admin/homeserver.php#fleet-maintenance')) ?>">Fleet controls</a></div>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Fleet</th><th>Total</th><th>Current</th><th>Unsupported</th><th>Stale</th><th>Drift</th></tr></thead><tbody>
      <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $product=>$label): $counts=(array)($fleetSummary[$product]??[]); ?>
        <tr><td><strong><?= e($label) ?></strong></td><td><?= (int)($counts['total']??0) ?></td><td><?= (int)($counts['current']??0) ?></td><td><?= (int)($counts['unsupported']??0) ?></td><td><?= (int)($counts['stale']??0) ?></td><td><?= (int)($counts['drift']??0) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <section class="admin-card">
    <div class="admin-card-head"><div><h3>Governed Automation</h3><p>v1.70 execution authority and current queue.</p></div><a class="button button-small" href="<?= e(url('/admin/homeserver.php#release-automation')) ?>">Automation controls</a></div>
    <div class="admin-table-wrap"><table class="admin-table"><tbody>
      <tr><td><strong>Runner</strong></td><td><?= !empty($automationControl['automation_enabled'])?'Enabled':'Disabled' ?></td></tr>
      <tr><td><strong>Mode</strong></td><td><?= !empty($automationControl['dry_run'])?'Dry run':'Live policy execution' ?></td></tr>
      <tr><td><strong>Kill switch</strong></td><td><?= !empty($automationControl['kill_switch'])?'ACTIVE':'Inactive' ?></td></tr>
      <tr><td><strong>Pending / deferred</strong></td><td><?= count($automationPending) ?></td></tr>
      <tr><td><strong>Active holds</strong></td><td><?= count($automationHolds) ?></td></tr>
    </tbody></table></div>
  </section>
</div>

<div class="admin-grid admin-grid-2" style="margin-bottom:18px">
  <section class="admin-card">
    <div class="admin-card-head"><div><h3>Release Intelligence</h3><p>v1.00 connected-client adoption snapshot.</p></div></div>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Client</th><th>Current stable</th><th>Tracked</th><th>Current / ahead</th><th>Update available</th><th>Unknown</th></tr></thead><tbody>
      <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $product=>$label): $row=(array)($intel[$product]??[]); ?>
        <tr><td><strong><?= e($label) ?></strong></td><td><?= ($row['latest_version']??'')!==''?'v'.e((string)$row['latest_version']):'—' ?></td><td><?= (int)($row['active_clients']??$row['paired_clients']??0) ?></td><td><?= (int)($row['current_clients']??0) ?></td><td><?= (int)($row['outdated_clients']??0) ?></td><td><?= (int)($row['unknown_clients']??0) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <section class="admin-card">
    <div class="admin-card-head"><div><h3>Active Incidents</h3><p>v1.30 incidents currently governing release recovery.</p></div><a class="button button-small" href="<?= e(url('/admin/homeserver.php#release-incidents')) ?>">Incident controls</a></div>
    <?php if($incidents): ?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Incident</th><th>Release</th><th>Status</th><th>Severity</th></tr></thead><tbody>
      <?php foreach($incidents as $incident): $release=(array)($incident['_affected_release']??[]); ?>
        <tr><td><strong>#<?= (int)$incident['id'] ?></strong></td><td><?= e(client_release_command_center_product_label_v180((string)$incident['product'])) ?> <?= ($release['version']??'')!==''?'v'.e((string)$release['version']):'#'.(int)$incident['release_id'] ?></td><td><?= e(client_release_command_center_state_label_v180((string)$incident['status'])) ?></td><td><?= e(ucfirst((string)$incident['severity'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div><?php else: ?><p>No active release incidents.</p><?php endif; ?>
  </section>
</div>

<section class="admin-card" style="margin-bottom:18px">
  <div class="admin-card-head"><div><h3>Recent Release Audit</h3><p>Durable v1.10–v1.70 operator and automation activity.</p></div><a class="button button-small" href="<?= e(url('/admin/homeserver.php#controlled-rollouts')) ?>">Full release controls</a></div>
  <?php if($audit): ?><div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>When</th><th>Client</th><th>Release</th><th>Action</th><th>Transition</th></tr></thead><tbody>
    <?php foreach(array_slice($audit,0,20) as $event): ?>
      <tr><td><?= e((string)$event['created_at']) ?></td><td><?= e(client_release_command_center_product_label_v180((string)$event['product'])) ?></td><td><?= (int)($event['release_id']??0)>0?'#'.(int)$event['release_id']:'—' ?></td><td><?= e(client_release_command_center_state_label_v180((string)$event['action'])) ?></td><td><?= e((string)$event['from_state']) ?><?= (string)$event['to_state']!==''?' → '.e((string)$event['to_state']):'' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div><?php else: ?><p>No release audit activity yet.</p><?php endif; ?>
</section>

<section class="admin-card">
  <div class="admin-card-head"><div><h3>Release Operations Workspaces</h3><p>Jump directly into the governed subsystem that owns the operation.</p></div></div>
  <div class="release-command-quicklinks">
    <a class="button" href="<?= e(url('/admin/homeserver.php#controlled-rollouts')) ?>">v1.10 Rollouts</a>
    <a class="button" href="<?= e(url('/admin/homeserver.php#release-health')) ?>">v1.20 Health</a>
    <a class="button" href="<?= e(url('/admin/homeserver.php#release-incidents')) ?>">v1.30 Incidents</a>
    <a class="button" href="<?= e(url('/admin/homeserver.php#release-risk')) ?>">v1.40 Risk</a>
    <a class="button" href="<?= e(url('/admin/homeserver.php#release-readiness')) ?>">v1.50 Readiness</a>
    <a class="button" href="<?= e(url('/admin/homeserver.php#fleet-maintenance')) ?>">v1.60 Fleet</a>
    <a class="button" href="<?= e(url('/admin/homeserver.php#release-automation')) ?>">v1.70 Automation</a>
  </div>
</section>

<?php require __DIR__.'/_footer.php'; ?>
