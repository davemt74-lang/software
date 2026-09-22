<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/chrome-extension-releases.php';
require_login();
require_permission('users.manage');

$pdo = db();
if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
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
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Session expired. Please try again.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');

            if (in_array($action,['automation_control_update','automation_policy_update','automation_run','automation_decide','automation_hold_release'],true)) {
                if($action==='automation_control_update'){
                    client_release_automation_control_update_v170($pdo,$_POST,$adminUserId);
                    flash('notice','Release automation controls updated.');
                }elseif($action==='automation_policy_update'){
                    client_release_automation_policy_update_v170(
                        $pdo,(string)($_POST['product']??''),(string)($_POST['channel']??'stable'),$_POST,$adminUserId
                    );
                    flash('notice','Release automation policy updated.');
                }elseif($action==='automation_run'){
                    $run=client_release_automation_run_v170($pdo,'manual',$adminUserId,true);
                    flash('notice','Automation evaluation completed: '.(int)($run['proposals_created']??0).' proposal(s), '.(int)($run['actions_executed']??0).' action(s), '.(int)($run['holds_created']??0).' hold(s).');
                }elseif($action==='automation_decide'){
                    client_release_automation_decide_proposal_v170(
                        $pdo,max(0,(int)($_POST['proposal_id']??0)),(string)($_POST['decision']??'defer'),
                        $adminUserId,(string)($_POST['note']??''),(int)($_POST['modified_percent']??0)
                    );
                    flash('notice','Automation proposal decision recorded.');
                }elseif($action==='automation_hold_release'){
                    client_release_automation_release_hold_v170(
                        $pdo,max(0,(int)($_POST['hold_id']??0)),$adminUserId,(string)($_POST['note']??'')
                    );
                    flash('notice','Automation hold released.');
                }
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                redirect(url('/admin/homeserver.php#release-automation'));
            }

            if (in_array($action,['readiness_manifest_update','readiness_ci_save','readiness_ci_delete','readiness_evaluate','readiness_signoff'],true)) {
                $product=(string)($_POST['product']??'');
                $releaseId=max(0,(int)($_POST['release_id']??0));
                if($action==='readiness_manifest_update'){
                    client_release_readiness_manifest_update_v150($pdo,$product,$releaseId,$_POST,$adminUserId);
                    flash('notice','Release preflight manifest updated. Existing sign-off is invalidated until the release is re-evaluated.');
                }elseif($action==='readiness_ci_save'){
                    client_release_readiness_ci_save_v150($pdo,$product,$releaseId,$_POST,$adminUserId);
                    flash('notice','CI evidence saved. Re-evaluate preflight before sign-off.');
                }elseif($action==='readiness_ci_delete'){
                    client_release_readiness_ci_delete_v150($pdo,max(0,(int)($_POST['evidence_id']??0)),$adminUserId);
                    flash('notice','CI evidence removed. Re-evaluate preflight before sign-off.');
                }elseif($action==='readiness_evaluate'){
                    $result=client_release_readiness_snapshot_v150($pdo,$product,$releaseId,$adminUserId);
                    flash('notice','Release preflight evaluated: '.ucwords(str_replace('_',' ',(string)$result['status'])).'.');
                }else{
                    client_release_readiness_signoff_v150(
                        $pdo,$product,$releaseId,max(0,(int)($_POST['snapshot_id']??0)),
                        (string)($_POST['decision']??'rejected'),(string)($_POST['note']??''),$adminUserId
                    );
                    flash('notice','Preflight approval decision recorded.');
                }
                redirect(url('/admin/homeserver.php#release-readiness'));
            }

            if (in_array($action,['fleet_policy_update','fleet_version_policy','fleet_compatibility_save','fleet_compatibility_delete','fleet_pin_set','fleet_pin_clear','fleet_upgrade_path_save','fleet_upgrade_path_delete','fleet_campaign_create','fleet_campaign_set','fleet_campaign_refresh'],true)) {
                if($action==='fleet_policy_update'){
                    client_fleet_policy_update_v160($pdo,(string)($_POST['product']??''),(string)($_POST['channel']??'stable'),$_POST,$adminUserId);
                    flash('notice','Fleet support policy updated.');
                }elseif($action==='fleet_version_policy'){
                    client_fleet_version_policy_update_v160(
                        $pdo,(string)($_POST['product']??''),(string)($_POST['channel']??'stable'),
                        (string)($_POST['version']??''),$_POST,$adminUserId
                    );
                    flash('notice','Version support policy updated.');
                }elseif($action==='fleet_compatibility_save'){
                    client_fleet_compatibility_rule_save_v160($pdo,$_POST,$adminUserId);
                    flash('notice','Compatibility rule saved.');
                }elseif($action==='fleet_compatibility_delete'){
                    client_fleet_compatibility_rule_delete_v160($pdo,max(0,(int)($_POST['rule_id']??0)),$adminUserId);
                    flash('notice','Compatibility rule deleted.');
                }elseif($action==='fleet_pin_set'){
                    client_fleet_pin_set_v160(
                        $pdo,max(0,(int)($_POST['user_id']??0)),(string)($_POST['product']??''),
                        (string)($_POST['scope_key']??'account'),(string)($_POST['pinned_version']??''),
                        (string)($_POST['reason']??''),(string)($_POST['expires_at']??''),$adminUserId
                    );
                    flash('notice','Temporary fleet version pin saved.');
                }elseif($action==='fleet_pin_clear'){
                    client_fleet_pin_clear_v160($pdo,max(0,(int)($_POST['pin_id']??0)),$adminUserId);
                    flash('notice','Fleet version pin cleared.');
                }elseif($action==='fleet_upgrade_path_save'){
                    client_fleet_upgrade_path_save_v160($pdo,$_POST,$adminUserId);
                    flash('notice','Fleet upgrade path saved.');
                }elseif($action==='fleet_upgrade_path_delete'){
                    client_fleet_upgrade_path_delete_v160($pdo,max(0,(int)($_POST['path_id']??0)),$adminUserId);
                    flash('notice','Fleet upgrade path deleted.');
                }elseif($action==='fleet_campaign_create'){
                    $campaign=client_fleet_campaign_create_v160($pdo,$_POST,$adminUserId);
                    flash('notice','Maintenance campaign created in draft state.');
                }elseif($action==='fleet_campaign_set'){
                    client_fleet_campaign_set_v160(
                        $pdo,max(0,(int)($_POST['campaign_id']??0)),(string)($_POST['campaign_state']??'paused'),
                        (int)($_POST['cohort_percent']??0),$adminUserId
                    );
                    flash('notice','Maintenance campaign updated. No forced installs were performed.');
                }elseif($action==='fleet_campaign_refresh'){
                    client_fleet_campaign_refresh_v160($pdo,max(0,(int)($_POST['campaign_id']??0)),$adminUserId);
                    flash('notice','Maintenance campaign fleet state refreshed.');
                }
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                redirect(url('/admin/homeserver.php#fleet-maintenance'));
            }

            if (in_array($action,['risk_profile_update','risk_policy_update','risk_assess','risk_review'],true)) {
                $product=(string)($_POST['product']??'');
                $releaseId=max(0,(int)($_POST['release_id']??0));
                if($action==='risk_profile_update'){
                    client_release_risk_profile_update_v140($pdo,$product,$releaseId,$_POST,$adminUserId);
                    flash('notice','Release risk profile updated.');
                }elseif($action==='risk_policy_update'){
                    client_release_risk_policy_update_v140($pdo,$product,(string)($_POST['channel']??'stable'),$_POST,$adminUserId);
                    flash('notice','Release risk policy updated.');
                }elseif($action==='risk_assess'){
                    client_release_risk_snapshot_v140($pdo,$product,$releaseId,$adminUserId);
                    flash('notice','Release risk assessed. Advisory only; no rollout or incident state changed.');
                }else{
                    client_release_risk_review_v140(
                        $pdo,$product,$releaseId,max(0,(int)($_POST['snapshot_id']??0)),
                        (string)($_POST['decision']??'reviewed'),(string)($_POST['note']??''),$adminUserId
                    );
                    flash('notice','Risk review recorded. No release action was performed.');
                }
                redirect(url('/admin/homeserver.php#release-risk'));
            }

            if (in_array($action,['incident_open','incident_contain','incident_start_recovery','incident_cohort','incident_refresh','incident_acknowledge','incident_policy','incident_resolve'],true)) {
                if($action==='incident_open'){
                    $product=(string)($_POST['product']??'');
                    $releaseId=max(0,(int)($_POST['release_id']??0));
                    $incident=client_release_incident_open_v130($pdo,$product,$releaseId,$_POST,$adminUserId);
                    flash('notice','Release incident opened. No rollout state changed until containment is explicitly approved.');
                }else{
                    $incidentId=max(0,(int)($_POST['incident_id']??0));
                    if($action==='incident_contain'){
                        client_release_incident_contain_v130($pdo,$incidentId,$adminUserId);
                        flash('notice','Affected rollout contained and paused.');
                    }elseif($action==='incident_start_recovery'){
                        client_release_incident_start_recovery_v130(
                            $pdo,$incidentId,max(0,(int)($_POST['recovery_release_id']??0)),(int)($_POST['recovery_percent']??10),$adminUserId
                        );
                        flash('notice','Operator-approved recovery cohort started.');
                    }elseif($action==='incident_cohort'){
                        client_release_incident_set_cohort_v130($pdo,$incidentId,(int)($_POST['recovery_percent']??0),$adminUserId);
                        flash('notice','Recovery cohort updated.');
                    }elseif($action==='incident_refresh'){
                        client_release_incident_refresh_v130($pdo,$incidentId,$adminUserId,true);
                        flash('notice','Affected fleet recovery state refreshed.');
                    }elseif($action==='incident_policy'){
                        client_release_incident_policy_update_v130($pdo,$incidentId,$_POST,$adminUserId);
                        flash('notice','Incident closure policy updated.');
                    }elseif($action==='incident_acknowledge'){
                        client_release_incident_acknowledge_client_v130(
                            $pdo,$incidentId,max(0,(int)($_POST['user_id']??0)),(string)($_POST['scope_key']??'account'),
                            (string)($_POST['reason']??''),$adminUserId
                        );
                        flash('notice','Recovery exception acknowledged.');
                    }elseif($action==='incident_resolve'){
                        client_release_incident_resolve_v130($pdo,$incidentId,$_POST,$adminUserId);
                        flash('notice','Release incident resolved and archived.');
                    }
                }
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                redirect(url('/admin/homeserver.php#release-incidents'));
            }

            if (in_array($action,['health_policy_update','health_evaluate','health_promote','health_reject'],true)) {
                $product=(string)($_POST['product']??'');
                $releaseId=max(0,(int)($_POST['release_id']??0));
                if($action==='health_policy_update'){
                    client_release_health_policy_update_v120($pdo,$product,$releaseId,$_POST,$adminUserId);
                    flash('notice','Release health policy updated.');
                }elseif($action==='health_evaluate'){
                    client_release_health_snapshot_v120($pdo,$product,$releaseId,$adminUserId);
                    flash('notice','Release health evaluated. No rollout state was changed.');
                }elseif($action==='health_promote'){
                    client_release_health_approve_promotion_v120(
                        $pdo,$product,$releaseId,max(0,(int)($_POST['snapshot_id']??0)),$adminUserId,(string)($_POST['rationale']??'')
                    );
                    if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                    flash('notice','Health-approved rollout promotion applied.');
                }else{
                    client_release_health_record_rejection_v120(
                        $pdo,$product,$releaseId,max(0,(int)($_POST['snapshot_id']??0)),$adminUserId,(string)($_POST['rationale']??'')
                    );
                    flash('notice','Promotion recommendation rejected; rollout state was left unchanged.');
                }
                redirect(url('/admin/homeserver.php#release-health'));
            }

            if ($action === 'rollout_update') {
                $product=(string)($_POST['product']??'');
                $releaseId=max(0,(int)($_POST['release_id']??0));
                client_release_rollout_update_v110($pdo,$product,$releaseId,$_POST,$adminUserId);
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                flash('notice','Controlled rollout updated.');
                redirect(url('/admin/homeserver.php#controlled-rollouts'));
            }

            if ($action === 'chrome_create') {
                $user = current_user();
                $id = chrome_extension_release_create($_POST, $_FILES, (int)($user['id'] ?? 0));
                $initialState=!empty($_POST['is_latest'])?'general_availability':(!empty($_POST['is_published'])?'testing':'draft');
                client_release_rollout_update_v110($pdo,'browser_companion',$id,['lifecycle_state'=>$initialState,'rollout_percent'=>$initialState==='general_availability'?100:0],$adminUserId);
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                flash('notice', 'Chrome Extension release uploaded and verified.');
                redirect(url('/admin/homeserver.php#chrome-release-' . $id));
            }
            if (in_array($action, ['chrome_publish','chrome_unpublish','chrome_latest'], true)) {
                $releaseId = max(0, (int)($_POST['release_id'] ?? 0));
                $stateAction = substr($action, 7);
                client_release_rollout_sync_legacy_action_v110($pdo,'browser_companion',$releaseId,$stateAction,$adminUserId);
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                flash('notice', $stateAction === 'latest' ? 'Chrome Extension release is now current for its channel.' : 'Chrome Extension release updated.');
                redirect(url('/admin/homeserver.php#chrome-extension-releases'));
            }
            if ($action === 'chrome_delete') {
                $releaseId = max(0, (int)($_POST['release_id'] ?? 0));
                client_release_rollout_delete_v110($pdo,'browser_companion',$releaseId,$adminUserId);
                chrome_extension_release_delete($releaseId);
                flash('notice', 'Chrome Extension release and stored ZIP deleted.');
                redirect(url('/admin/homeserver.php#chrome-extension-releases'));
            }

            if ($action === 'create') {
                $user = current_user();
                $id = homeserver_vp3_create_release($_POST, $_FILES, (int)($user['id'] ?? 0));
                $initialState=!empty($_POST['is_latest'])?'general_availability':(!empty($_POST['is_published'])?'testing':'draft');
                client_release_rollout_update_v110($pdo,'homeserver',$id,['lifecycle_state'=>$initialState,'rollout_percent'=>$initialState==='general_availability'?100:0],$adminUserId);
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                flash('notice', 'HomeServer release uploaded and verified.');
                redirect(url('/admin/homeserver.php#homeserver-release-' . $id));
            }
            $releaseId = max(0, (int)($_POST['release_id'] ?? 0));
            if (in_array($action, ['publish','unpublish','latest'], true)) {
                client_release_rollout_sync_legacy_action_v110($pdo,'homeserver',$releaseId,$action,$adminUserId);
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                flash('notice', $action === 'latest' ? 'HomeServer release is now the current release.' : 'HomeServer release updated.');
                redirect(url('/admin/homeserver.php#homeserver-releases'));
            }
            if ($action === 'delete') {
                client_release_rollout_delete_v110($pdo,'homeserver',$releaseId,$adminUserId);
                homeserver_vp3_delete_release($releaseId);
                flash('notice', 'HomeServer release and stored binaries deleted.');
                redirect(url('/admin/homeserver.php#homeserver-releases'));
            }
            throw new RuntimeException('Unsupported client release action.');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$chromeReleases = chrome_extension_releases();
$chromeLatest = chrome_extension_latest_release('stable');
$selectedChromeChannel = strtolower((string)($_POST['channel'] ?? 'stable'));
if (!chrome_extension_release_channel_valid($selectedChromeChannel)) $selectedChromeChannel = 'stable';

$releases = homeserver_vp3_releases();
$latest = homeserver_vp3_latest_release('stable');
$relayUrl = homeserver_vp3_relay_base_url();
$selectedChannel = strtolower((string)($_POST['channel'] ?? 'stable'));
if (!homeserver_vp3_channel_valid($selectedChannel)) $selectedChannel = 'stable';

$phpUploadMax = (string)ini_get('upload_max_filesize');
$phpPostMax = (string)ini_get('post_max_size');
$releaseIntelligence = function_exists('client_release_intelligence_admin_summary_v100')
    ? client_release_intelligence_admin_summary_v100($pdo)
    : ['browser_companion'=>[],'homeserver'=>[]];
$rolloutAdoption=client_release_adoption_summary_v110($pdo);
$releaseHealth=client_release_health_admin_summary_v120($pdo);
$healthDecisions=client_release_health_recent_decisions_v120($pdo,12);
$releaseIncidents=client_release_incident_list_v130($pdo,30);
$releaseRisk=client_release_risk_admin_summary_v140($pdo);
$riskReviews=client_release_risk_recent_reviews_v140($pdo,16);
$releaseReadiness=client_release_readiness_admin_summary_v150($pdo);
$releaseAutomation=client_release_automation_admin_summary_v170($pdo);
$fleetState=client_fleet_summary_v160($pdo);
$fleetInventory=(array)($fleetState['inventory']??['browser_companion'=>[],'homeserver'=>[]]);
$fleetSummary=(array)($fleetState['summary']??[]);
$fleetCompatibility=(array)($fleetState['compatibility']??[]);
$fleetRecommendations=client_fleet_recommendations_v160($pdo,$fleetState);
$fleetCompatibilityRules=client_fleet_compatibility_rules_v160($pdo);
$fleetCampaigns=client_fleet_campaigns_v160($pdo,40);
$rolloutAudit=client_release_audit_recent_v110($pdo,20);
$adminTitle = 'Client Releases';
$adminActive = 'homeserver';
require __DIR__ . '/_header.php';
?>
<div class="admin-section-heading">
  <div>
    <span class="eyebrow">Client Distribution</span>
    <h2>Chrome Extension + HomeServer</h2>
    <p>Manage the downloadable VP3 Browser Companion package and Windows HomeServer releases from one controlled release surface.</p>
  </div>
  <div class="form-actions">
    <a class="button" href="<?= e(url('/chrome-extension-download.php')) ?>" target="_blank" rel="noopener">Download Chrome Extension</a>
    <a class="button" href="<?= e(url('/api/homeserver-release.php')) ?>" target="_blank" rel="noopener">HomeServer Release API</a>
  </div>
</div>

<?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

<?php $browserIntel=(array)($releaseIntelligence['browser_companion']??[]);$homeIntel=(array)($releaseIntelligence['homeserver']??[]); ?>
<section class="admin-card" style="margin-bottom:24px">
  <div class="admin-card-head"><div><h3>Release Intelligence</h3><p>Current stable adoption across connected VP3 clients. Version telemetry is account-scoped and contains no browsing history or HomeServer content.</p></div><span class="eyebrow">v1.00</span></div>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Client</th><th>Current stable</th><th>Tracked clients</th><th>Current / ahead</th><th>Update available</th><th>Unknown</th><th>Channel</th></tr></thead>
    <tbody>
      <tr><td><strong>Browser Companion</strong></td><td><?= ($browserIntel['latest_version']??'')!==''?'v'.e((string)$browserIntel['latest_version']):'Not published' ?></td><td><?= (int)($browserIntel['active_clients']??0) ?></td><td><?= (int)($browserIntel['current_clients']??0) ?></td><td><?= (int)($browserIntel['outdated_clients']??0) ?></td><td><?= (int)($browserIntel['unknown_clients']??0) ?></td><td><?= e((string)($browserIntel['channel']??'stable')) ?></td></tr>
      <tr><td><strong>HomeServer</strong></td><td><?= ($homeIntel['latest_version']??'')!==''?'v'.e((string)$homeIntel['latest_version']):'Not published' ?></td><td><?= (int)($homeIntel['paired_clients']??0) ?></td><td><?= (int)($homeIntel['current_clients']??0) ?></td><td><?= (int)($homeIntel['outdated_clients']??0) ?></td><td><?= (int)($homeIntel['unknown_clients']??0) ?></td><td><?= e((string)($homeIntel['channel']??'stable')) ?></td></tr>
    </tbody>
  </table></div>
</section>

<section class="admin-card" id="release-automation" style="margin-bottom:24px">
  <?php
    $automationControl=(array)($releaseAutomation['control']??[]);
    $automationPolicies=(array)($releaseAutomation['policies']??[]);
    $automationPending=(array)($releaseAutomation['pending']??[]);
    $automationHolds=(array)($releaseAutomation['holds']??[]);
    $automationRuns=(array)($releaseAutomation['runs']??[]);
  ?>
  <div class="admin-card-head">
    <div>
      <h3>Governed Release Automation</h3>
      <p>Evaluate release health, readiness, risk, incidents, and fleet maintenance; prepare the next safe action; and optionally execute explicitly permitted low-risk cohort steps. GA promotion remains manual, as do rollback, incident resolution, unsupported-version enforcement, and compatibility overrides.</p>
    </div>
    <span class="eyebrow">v1.70</span>
  </div>

  <div class="admin-table-wrap"><table class="admin-table"><tbody>
    <tr><td><strong>Automation</strong></td><td><?= !empty($automationControl['automation_enabled'])?'Enabled':'Disabled' ?></td></tr>
    <tr><td><strong>Dry run</strong></td><td><?= !empty($automationControl['dry_run'])?'On — proposals only':'Off — policy-authorized low-risk actions may execute' ?></td></tr>
    <tr><td><strong>Global kill switch</strong></td><td><?= !empty($automationControl['kill_switch'])?'ACTIVE — automatic execution blocked':'Inactive' ?></td></tr>
    <tr><td><strong>Scheduled runner</strong></td><td><code>php tools/client-release-automation-v170.php</code><br><small>Default CLI idempotency key is the current UTC hour.</small></td></tr>
  </tbody></table></div>

  <form method="post" class="admin-form" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="action" value="automation_control_update">
    <div class="form-row">
      <label><input type="checkbox" name="automation_enabled" value="1" <?= !empty($automationControl['automation_enabled'])?'checked':'' ?>> Enable automation runner</label>
      <label><input type="checkbox" name="dry_run" value="1" <?= !empty($automationControl['dry_run'])?'checked':'' ?>> Dry run</label>
      <label><input type="checkbox" name="kill_switch" value="1" <?= !empty($automationControl['kill_switch'])?'checked':'' ?>> Global kill switch</label>
    </div>
    <button class="button button-small" type="submit">Save automation controls</button>
  </form>

  <div class="form-actions" style="margin-top:10px">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="automation_run"><button class="button button-small primary" type="submit">Run automation evaluation now</button></form>
  </div>

  <div class="admin-card-head" style="margin-top:18px"><div><h3>Automation Policies</h3><p><strong>Recommend only</strong> never auto-executes a transition. <strong>Auto low risk</strong> may execute only non-GA rollout/fleet cohort advancement when v1.20 health, v1.40 risk, v1.50 readiness, and v1.60 fleet rules all continue to pass.</p></div></div>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Client</th><th>Channel</th><th>Policy</th></tr></thead>
    <tbody>
    <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $autoProduct=>$autoLabel): ?>
      <?php foreach(['stable','beta','dev'] as $autoChannel): $autoPolicy=(array)($automationPolicies[$autoProduct][$autoChannel]??client_release_automation_default_policy_v170()); ?>
        <tr>
          <td><strong><?= e($autoLabel) ?></strong></td>
          <td><?= e(ucfirst($autoChannel)) ?></td>
          <td>
            <form method="post" class="admin-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="automation_policy_update"><input type="hidden" name="product" value="<?= e($autoProduct) ?>"><input type="hidden" name="channel" value="<?= e($autoChannel) ?>">
              <div class="form-row">
                <label><input type="checkbox" name="policy_enabled" value="1" <?= !empty($autoPolicy['policy_enabled'])?'checked':'' ?>> Policy enabled</label>
                <label>Execution<select name="execution_mode"><option value="recommend_only" <?= (string)$autoPolicy['execution_mode']==='recommend_only'?'selected':'' ?>>Recommend only</option><option value="auto_low_risk" <?= (string)$autoPolicy['execution_mode']==='auto_low_risk'?'selected':'' ?>>Auto low risk</option></select></label>
                <label><input type="checkbox" name="auto_hold_enabled" value="1" <?= !empty($autoPolicy['auto_hold_enabled'])?'checked':'' ?>> Automatic holds</label>
                <label><input type="checkbox" name="rollout_progression_enabled" value="1" <?= !empty($autoPolicy['rollout_progression_enabled'])?'checked':'' ?>> Rollout progression</label>
                <label><input type="checkbox" name="fleet_progression_enabled" value="1" <?= !empty($autoPolicy['fleet_progression_enabled'])?'checked':'' ?>> Fleet progression</label>
              </div>
              <div class="form-row">
                <label>Proposal expiry hours<input type="number" name="proposal_expiry_hours" min="1" max="168" value="<?= (int)$autoPolicy['proposal_expiry_hours'] ?>"></label>
                <label>Cooldown minutes<input type="number" name="cooldown_minutes" min="0" max="1440" value="<?= (int)$autoPolicy['cooldown_minutes'] ?>"></label>
                <label>Fleet observation minutes<input type="number" name="fleet_observation_minutes" min="0" max="10080" value="<?= (int)$autoPolicy['fleet_observation_minutes'] ?>"></label>
                <label>Completion gate bps<input type="number" name="fleet_completion_gate_bps" min="5000" max="10000" value="<?= (int)$autoPolicy['fleet_completion_gate_bps'] ?>"></label>
                <label>Failure hold bps<input type="number" name="fleet_failure_hold_bps" min="0" max="5000" value="<?= (int)$autoPolicy['fleet_failure_hold_bps'] ?>"></label>
              </div>
              <button class="button button-small" type="submit">Save policy</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <div class="admin-card-head" style="margin-top:18px"><div><h3>Approval Queue</h3><p>Approve, reject, defer, or reduce a proposed cohort. GA promotion remains manual and cannot be modified into a different percentage.</p></div></div>
  <?php if($automationPending): ?>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Proposal</th><th>Transition</th><th>Rationale</th><th>Authority</th><th>Decision</th></tr></thead><tbody>
      <?php foreach($automationPending as $proposal): ?>
        <tr>
          <td><strong>#<?= (int)$proposal['id'] ?> · <?= e(ucwords(str_replace('_',' ',(string)$proposal['proposal_type']))) ?></strong><br><small><?= e((string)$proposal['product']) ?> · <?= e((string)$proposal['channel']) ?><?php if((int)($proposal['release_id']??0)>0): ?> · release #<?= (int)$proposal['release_id'] ?><?php endif; ?><?php if((int)($proposal['campaign_id']??0)>0): ?> · campaign #<?= (int)$proposal['campaign_id'] ?><?php endif; ?><br><?= e((string)$proposal['proposal_status']) ?> · expires <?= e((string)($proposal['expires_at']??'')) ?></small></td>
          <td><?= e((string)$proposal['from_state']) ?> <?= (int)$proposal['from_percent'] ?>% → <?= e((string)$proposal['to_state']) ?> <?= (int)$proposal['to_percent'] ?>%</td>
          <td><small><?= e((string)$proposal['rationale']) ?></small></td>
          <td><small><?= !empty($proposal['requires_approval'])?'Operator approval required':'Policy may auto-execute' ?><?= !empty($proposal['auto_executable'])?' · auto eligible':'' ?></small></td>
          <td>
            <?php if(in_array((string)$proposal['proposal_status'],['pending','deferred'],true)): ?>
            <form method="post" class="admin-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="automation_decide"><input type="hidden" name="proposal_id" value="<?= (int)$proposal['id'] ?>">
              <label>Decision<select name="decision"><option value="approve">Approve</option><option value="reject">Reject</option><option value="defer">Defer</option><?php if(in_array((string)$proposal['proposal_type'],['rollout_promote','fleet_advance'],true)&&(string)$proposal['to_state']!=='general_availability'): ?><option value="modify">Modify + approve</option><?php endif; ?></select></label>
              <label>Modified cohort %<input type="number" name="modified_percent" min="1" max="99" placeholder="Only for Modify"></label>
              <label>Operator note<input name="note" maxlength="1000" placeholder="Required for reject, defer, or modify"></label>
              <button class="button button-small" type="submit">Apply decision</button>
            </form>
            <?php else: ?><small>Approved and awaiting/recording execution state.</small><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php else: ?><p>No automation proposals are awaiting action.</p><?php endif; ?>

  <div class="admin-card-head" style="margin-top:18px"><div><h3>Active Automation Holds</h3><p>Automation holds stop v1.70 progression without withdrawing the currently exposed cohort. Manual release controls remain available to an operator.</p></div></div>
  <?php if($automationHolds): ?>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Hold</th><th>Scope</th><th>Reason</th><th>Since</th><th></th></tr></thead><tbody>
      <?php foreach($automationHolds as $hold): ?><tr>
        <td>#<?= (int)$hold['id'] ?></td><td><?= e((string)$hold['scope_type']) ?> · <?= e((string)$hold['product']) ?><?php if((int)($hold['release_id']??0)>0): ?> · release #<?= (int)$hold['release_id'] ?><?php endif; ?><?php if((int)($hold['campaign_id']??0)>0): ?> · campaign #<?= (int)$hold['campaign_id'] ?><?php endif; ?></td>
        <td><small><?= e((string)$hold['hold_reason']) ?></small></td><td><?= e((string)$hold['created_at']) ?></td>
        <td><form method="post" class="admin-form"><?= csrf_field() ?><input type="hidden" name="action" value="automation_hold_release"><input type="hidden" name="hold_id" value="<?= (int)$hold['id'] ?>"><label>Release note<input name="note" maxlength="1000"></label><button class="button button-small" type="submit">Release hold</button></form></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?><p>No active automation holds.</p><?php endif; ?>

  <div class="admin-card-head" style="margin-top:18px"><div><h3>Recent Automation Runs</h3><p>Durable run ledger for manual, scheduled, and CLI evaluations.</p></div></div>
  <?php if($automationRuns): ?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Run</th><th>Trigger</th><th>Mode</th><th>Results</th><th>Status</th><th>Started</th></tr></thead><tbody>
    <?php foreach($automationRuns as $run): ?><tr>
      <td>#<?= (int)$run['id'] ?></td><td><?= e((string)$run['trigger_type']) ?></td><td><?= !empty($run['dry_run'])?'Dry run':'Live' ?><?= !empty($run['kill_switch'])?' · kill switch':'' ?></td>
      <td><?= (int)$run['proposals_created'] ?> proposal(s) · <?= (int)$run['actions_executed'] ?> action(s) · <?= (int)$run['holds_created'] ?> hold(s)</td>
      <td><?= e((string)$run['status']) ?></td><td><?= e((string)$run['started_at']) ?></td>
    </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>

<section class="admin-card" id="release-readiness" style="margin-bottom:24px">
  <div class="admin-card-head">
    <div><h3>Release Readiness &amp; Preflight Gates</h3><p>Verify artifact integrity, CI evidence, migration and rollback preparation, compatibility review, v1.40 risk, and Canary ownership before a release can leave Draft/Testing.</p></div>
    <span class="eyebrow">v1.50</span>
  </div>

  <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $readyProduct=>$readyLabel): ?>
    <div class="admin-card-head" style="margin-top:16px"><div><h3><?= e($readyLabel) ?> Preflight</h3><p>Preflight approval is fingerprint-bound. Changing release inputs after sign-off makes the approval stale.</p></div></div>
    <?php foreach((array)($releaseReadiness[$readyProduct]??[]) as $ready):
      if(!empty($ready['error']))continue;
      $rid=(int)$ready['release_id'];
      $manifest=(array)$ready['manifest'];
      $snapshot=is_array($ready['latest_snapshot']??null)?$ready['latest_snapshot']:null;
      $signoff=is_array($ready['signoff']??null)?$ready['signoff']:null;
      $ciRows=(array)($ready['ci']??[]);
      $requiredChecks=client_release_readiness_required_ci_v150($manifest);
      $rollbackOptions=client_release_admin_rollouts_v110($pdo,$readyProduct);
      $snapshotGates=$snapshot?json_decode((string)($snapshot['gates_json']??'[]'),true):[];
      if(!is_array($snapshotGates))$snapshotGates=[];
    ?>
    <section class="admin-card" style="margin:12px 0">
      <div class="admin-card-head">
        <div>
          <h3>v<?= e((string)$ready['version']) ?> · <?= e((string)$ready['channel']) ?></h3>
          <p><?= e(ucwords(str_replace('_',' ',(string)$ready['state']))) ?> · <?= e((string)$ready['state_reason']) ?></p>
        </div>
        <span class="eyebrow"><?= e((string)($ready['rollout']['lifecycle_state']??'draft')) ?></span>
      </div>

      <div class="admin-table-wrap"><table class="admin-table"><tbody>
        <tr><td><strong>Artifact integrity</strong></td><td>
          <?php
            $artifactGates=array_values(array_filter($snapshotGates,static fn($gate)=>is_array($gate)&&str_starts_with((string)($gate['key']??''),'artifact')));
            if(!$snapshot): ?><small>Not evaluated yet. Evaluate preflight to re-hash and validate the stored artifact.</small>
          <?php elseif(!$artifactGates): ?><small>No artifact gate result stored in this snapshot.</small>
          <?php else: foreach($artifactGates as $gate): ?><small><?= e(ucfirst((string)$gate['status'])) ?> — <?= e((string)$gate['detail']) ?></small><br><?php endforeach; endif; ?>
        </td></tr>
        <tr><td><strong>Snapshot</strong></td><td><?php if($snapshot): ?>#<?= (int)$snapshot['id'] ?> · <?= e((string)$snapshot['readiness_status']) ?> · <?= (int)$snapshot['passed_count'] ?> passed · <?= (int)$snapshot['warning_count'] ?> warnings · <?= (int)$snapshot['blocked_count'] ?> blocked · <?= e((string)$snapshot['evaluated_at']) ?><?php else: ?>None<?php endif; ?></td></tr>
        <tr><td><strong>Preflight approval</strong></td><td><?php if($signoff): ?><?= e(ucwords(str_replace('_',' ',(string)$signoff['decision']))) ?> · <?= e((string)$signoff['created_at']) ?><?php if((string)$signoff['note']!==''): ?><br><small><?= e((string)$signoff['note']) ?></small><?php endif; ?><?php else: ?>Unsigned<?php endif; ?></td></tr>
      </tbody></table></div>

      <form method="post" class="admin-form" style="margin-top:14px">
        <?= csrf_field() ?><input type="hidden" name="action" value="readiness_manifest_update"><input type="hidden" name="product" value="<?= e($readyProduct) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>">
        <div class="form-row">
          <label>Source commit SHA<input name="source_commit_sha" maxlength="40" value="<?= e((string)$manifest['source_commit_sha']) ?>" placeholder="40-character Git SHA"></label>
          <label>Canary initial %<input type="number" name="canary_initial_percent" min="1" max="25" value="<?= (int)$manifest['canary_initial_percent'] ?>"></label>
          <label>Observe hours<input type="number" name="canary_observation_hours" min="1" max="168" value="<?= (int)$manifest['canary_observation_hours'] ?>"></label>
          <label>Escalation owner user ID<input type="number" name="escalation_owner_user_id" min="1" value="<?= (int)($manifest['escalation_owner_user_id']??$adminUserId) ?>"></label>
        </div>
        <label>Required CI checks<textarea name="required_ci_checks" rows="2"><?= e(implode("
",$requiredChecks)) ?></textarea></label>
        <div class="form-row">
          <label><input type="checkbox" name="known_issues_reviewed" value="1" <?= !empty($manifest['known_issues_reviewed'])?'checked':'' ?>> Known issues reviewed</label>
          <label><input type="checkbox" name="compatibility_reviewed" value="1" <?= !empty($manifest['compatibility_reviewed'])?'checked':'' ?>> Compatibility reviewed</label>
          <label><input type="checkbox" name="requires_migration" value="1" <?= !empty($manifest['requires_migration'])?'checked':'' ?>> Requires migration</label>
          <label><input type="checkbox" name="migration_reversible" value="1" <?= !empty($manifest['migration_reversible'])?'checked':'' ?>> Migration reversible</label>
          <label><input type="checkbox" name="rollback_required" value="1" <?= !empty($manifest['rollback_required'])?'checked':'' ?>> Rollback target required</label>
        </div>
        <label>Migration / upgrade notes<textarea name="migration_notes" rows="2" maxlength="3000"><?= e((string)$manifest['migration_notes']) ?></textarea></label>
        <div class="form-row">
          <label>Known-good rollback release<select name="rollback_release_id"><option value="0">None / waived</option>
            <?php foreach($rollbackOptions as $candidate): if((int)$candidate['id']===$rid||(string)$candidate['channel']!==(string)$ready['channel'])continue; ?>
              <option value="<?= (int)$candidate['id'] ?>" <?= (int)($manifest['rollback_release_id']??0)===(int)$candidate['id']?'selected':'' ?>>v<?= e((string)$candidate['version']) ?> · <?= e((string)($candidate['_rollout']['lifecycle_state']??'draft')) ?></option>
            <?php endforeach; ?>
          </select></label>
        </div>
        <label>Rollback / forward-recovery plan<textarea name="rollback_plan" rows="2" maxlength="3000"><?= e((string)$manifest['rollback_plan']) ?></textarea></label>
        <label>Rollback waiver reason<textarea name="rollback_waiver_reason" rows="2" maxlength="1000"><?= e((string)$manifest['rollback_waiver_reason']) ?></textarea></label>
        <label>Compatibility prerequisites<textarea name="compatibility_prerequisites" rows="2" maxlength="2000"><?= e((string)$manifest['compatibility_prerequisites']) ?></textarea></label>
        <label>Operator notes<textarea name="operator_notes" rows="2" maxlength="3000"><?= e((string)$manifest['operator_notes']) ?></textarea></label>
        <button class="button button-small" type="submit">Save preflight manifest</button>
      </form>

      <div class="admin-card-head" style="margin-top:14px"><div><h3>Required CI Evidence</h3><p>Each required check must be recorded as successful against the same source commit SHA as the preflight manifest.</p></div></div>
      <?php if($ciRows): ?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Check</th><th>Conclusion</th><th>Commit</th><th>Run</th><th></th></tr></thead><tbody>
        <?php foreach($ciRows as $ci): ?><tr>
          <td><?= e((string)$ci['check_name']) ?></td><td><?= e((string)$ci['conclusion']) ?></td><td><small><?= e((string)$ci['source_commit_sha']) ?></small></td>
          <td><?php if((string)$ci['details_url']!==''): ?><a href="<?= e((string)$ci['details_url']) ?>" target="_blank" rel="noopener"><?= e((string)($ci['run_id']?:'details')) ?></a><?php else: ?><?= e((string)$ci['run_id']) ?><?php endif; ?></td>
          <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="readiness_ci_delete"><input type="hidden" name="product" value="<?= e($readyProduct) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>"><input type="hidden" name="evidence_id" value="<?= (int)$ci['id'] ?>"><button class="button button-small" type="submit">Remove</button></form></td>
        </tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
      <form method="post" class="admin-form" style="margin-top:8px">
        <?= csrf_field() ?><input type="hidden" name="action" value="readiness_ci_save"><input type="hidden" name="product" value="<?= e($readyProduct) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>">
        <div class="form-row">
          <label>Check name<input name="check_name" maxlength="180" required placeholder="e.g. Recovery Baseline"></label>
          <label>Conclusion<select name="conclusion"><option value="success">Success</option><option value="failure">Failure</option><option value="cancelled">Cancelled</option><option value="skipped">Skipped</option><option value="pending">Pending</option></select></label>
          <label>Source SHA<input name="source_commit_sha" maxlength="40" value="<?= e((string)$manifest['source_commit_sha']) ?>"></label>
          <label>Run ID<input name="run_id" maxlength="80"></label>
        </div>
        <label>HTTPS details URL<input name="details_url" maxlength="1000"></label>
        <label>Evidence notes<input name="evidence_notes" maxlength="1000"></label>
        <button class="button button-small" type="submit">Save CI evidence</button>
      </form>

      <div class="form-actions" style="margin-top:12px">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="readiness_evaluate"><input type="hidden" name="product" value="<?= e($readyProduct) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>"><button class="button button-small primary" type="submit">Evaluate preflight</button></form>
      </div>

      <?php if($snapshot): ?>
        <?php $snapStatus=(string)$snapshot['readiness_status']; ?>
        <form method="post" class="admin-form" style="margin-top:12px">
          <?= csrf_field() ?><input type="hidden" name="action" value="readiness_signoff"><input type="hidden" name="product" value="<?= e($readyProduct) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>"><input type="hidden" name="snapshot_id" value="<?= (int)$snapshot['id'] ?>">
          <div class="form-row"><label>Preflight decision<select name="decision">
            <?php if($snapStatus==='ready'): ?><option value="approved">Approve</option><?php endif; ?>
            <?php if($snapStatus==='warnings'): ?><option value="approved_with_warnings">Approve with warnings</option><?php endif; ?>
            <option value="rejected">Reject</option>
          </select></label></div>
          <label>Approval note<input name="note" maxlength="1500" placeholder="Required for warning approval or rejection"></label>
          <button class="button button-small" type="submit">Record preflight approval</button>
        </form>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
  <?php endforeach; ?>
</section>

<section class="admin-card" id="fleet-maintenance" style="margin-bottom:24px">
  <div class="admin-card-head">
    <div>
      <h3>Fleet Maintenance &amp; Compatibility</h3>
      <p>Keep Browser Companion and HomeServer clients current, supported, compatible, and recoverable over time. <strong>No forced installs:</strong> v1.60 classifies clients and controls which maintenance update may be offered; installation remains client/operator initiated.</p>
    </div>
    <span class="eyebrow">v1.60</span>
  </div>

  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Fleet</th><th>Total</th><th>Current</th><th>Supported old</th><th>Maintenance</th><th>Deprecated</th><th>Unsupported</th><th>Stale</th><th>Pinned</th><th>Incident</th><th>Drift</th></tr></thead>
    <tbody>
    <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $fleetProduct=>$fleetLabel): $counts=(array)($fleetSummary[$fleetProduct]??[]); ?>
      <tr>
        <td><strong><?= e($fleetLabel) ?></strong></td>
        <?php foreach(['total','current','supported','maintenance','deprecated','unsupported','stale','pinned','incident','drift'] as $metric): ?><td><?= (int)($counts[$metric]??0) ?></td><?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php if($fleetRecommendations): ?>
    <div class="admin-card-head" style="margin-top:14px"><div><h3>Maintenance Recommendations</h3><p>Derived from support policy, fleet telemetry, drift, staleness, and cross-client compatibility. Recommendations never execute maintenance automatically.</p></div></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Severity</th><th>Area</th><th>Recommendation</th></tr></thead><tbody>
      <?php foreach($fleetRecommendations as $recommendation): ?><tr><td><?= e(ucfirst((string)$recommendation['severity'])) ?></td><td><?= e((string)$recommendation['product']) ?></td><td><?= e((string)$recommendation['message']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?>
    <p style="margin-top:12px"><small>No fleet maintenance recommendations are currently open.</small></p>
  <?php endif; ?>

  <div class="admin-card-head" style="margin-top:18px"><div><h3>Support &amp; Maintenance Policy</h3><p>Set minimum supported versions, stale-client thresholds, UTC maintenance windows, default cohort sizes, and deprecation warning periods by channel.</p></div></div>
  <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $fleetProduct=>$fleetLabel): ?>
    <div class="admin-card-head" style="margin-top:12px"><div><strong><?= e($fleetLabel) ?></strong></div></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Channel</th><th>Policy</th></tr></thead><tbody>
    <?php foreach(['stable','beta','dev'] as $fleetChannel): $fleetPolicy=client_fleet_policy_v160($pdo,$fleetProduct,$fleetChannel); ?>
      <tr>
        <td><strong><?= e(ucfirst($fleetChannel)) ?></strong><br><small><?= client_fleet_window_open_v160($fleetPolicy)?'Window open now':'Window closed now' ?> · UTC</small></td>
        <td>
          <form method="post" class="admin-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="fleet_policy_update"><input type="hidden" name="product" value="<?= e($fleetProduct) ?>"><input type="hidden" name="channel" value="<?= e($fleetChannel) ?>">
            <div class="form-row">
              <label>Minimum supported<input name="minimum_supported_version" maxlength="64" placeholder="e.g. 22.8.0" value="<?= e((string)$fleetPolicy['minimum_supported_version']) ?>"></label>
              <label>Stale after hours<input type="number" name="stale_after_hours" min="24" max="8760" value="<?= (int)$fleetPolicy['stale_after_hours'] ?>"></label>
              <label>Default cohort %<input type="number" name="default_cohort_percent" min="1" max="100" value="<?= (int)$fleetPolicy['default_cohort_percent'] ?>"></label>
            </div>
            <div class="form-row">
              <label>Maintenance days (1=Mon)<input name="maintenance_days" maxlength="32" value="<?= e((string)$fleetPolicy['maintenance_days']) ?>"></label>
              <label>Window start UTC<input type="time" name="maintenance_window_start" value="<?= e((string)$fleetPolicy['maintenance_window_start']) ?>"></label>
              <label>Window end UTC<input type="time" name="maintenance_window_end" value="<?= e((string)$fleetPolicy['maintenance_window_end']) ?>"></label>
              <label>Deprecation warning days<input type="number" name="deprecation_warning_days" min="0" max="365" value="<?= (int)$fleetPolicy['deprecation_warning_days'] ?>"></label>
            </div>
            <button class="button button-small" type="submit">Save fleet policy</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endforeach; ?>

  <div class="admin-card-head" style="margin-top:18px"><div><h3>Version Support Lifecycle</h3><p>Explicit version policy overrides the channel minimum and supports Current → Supported → Maintenance → Deprecated → Unsupported lifecycle management.</p></div></div>
  <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $fleetProduct=>$fleetLabel): ?>
    <div class="admin-table-wrap" style="margin-top:10px"><table class="admin-table">
      <thead><tr><th><?= e($fleetLabel) ?> release</th><th>Installed</th><th>Support policy</th></tr></thead><tbody>
      <?php foreach(client_release_admin_rollouts_v110($pdo,$fleetProduct) as $fleetRelease):
        $fv=(string)$fleetRelease['version'];$fc=(string)$fleetRelease['channel'];
        $vp=client_fleet_version_policy_v160($pdo,$fleetProduct,$fc,$fv);
        $installedCount=0;foreach((array)($fleetInventory[$fleetProduct]??[]) as $inventoryRow)if((string)$inventoryRow['installed_version']===$fv)$installedCount++;
      ?>
        <tr>
          <td><strong>v<?= e($fv) ?></strong><br><small><?= e($fc) ?> · <?= e((string)($fleetRelease['_rollout']['lifecycle_state']??'draft')) ?></small></td>
          <td><?= $installedCount ?></td>
          <td>
            <form method="post" class="admin-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="fleet_version_policy"><input type="hidden" name="product" value="<?= e($fleetProduct) ?>"><input type="hidden" name="channel" value="<?= e($fc) ?>"><input type="hidden" name="version" value="<?= e($fv) ?>">
              <div class="form-row">
                <label>Status<select name="support_status"><?php foreach(client_fleet_support_statuses_v160() as $supportStatus): ?><option value="<?= e($supportStatus) ?>" <?= (string)($vp['support_status']??'supported')===$supportStatus?'selected':'' ?>><?= e(ucfirst($supportStatus)) ?></option><?php endforeach; ?></select></label>
                <label>Deprecated at<input type="datetime-local" name="deprecated_at" value="<?= !empty($vp['deprecated_at'])?e(str_replace(' ','T',substr((string)$vp['deprecated_at'],0,16))):'' ?>"></label>
                <label>Unsupported at<input type="datetime-local" name="unsupported_at" value="<?= !empty($vp['unsupported_at'])?e(str_replace(' ','T',substr((string)$vp['unsupported_at'],0,16))):'' ?>"></label>
              </div>
              <label>Notes<input name="notes" maxlength="1000" value="<?= e((string)($vp['notes']??'')) ?>"></label>
              <button class="button button-small" type="submit">Save version policy</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endforeach; ?>

  <div class="admin-card-head" style="margin-top:18px"><div><h3>Browser ↔ HomeServer Compatibility</h3><p>Define explicit compatible, warning, or incompatible version ranges. The most restrictive matching active rule wins.</p></div></div>
  <form method="post" class="admin-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="fleet_compatibility_save"><input type="hidden" name="is_active" value="1">
    <div class="form-row">
      <label>Browser min<input name="browser_min_version" maxlength="64"></label><label>Browser max<input name="browser_max_version" maxlength="64"></label>
      <label>HomeServer min<input name="homeserver_min_version" maxlength="64"></label><label>HomeServer max<input name="homeserver_max_version" maxlength="64"></label>
      <label>Status<select name="compatibility_status"><option value="compatible">Compatible</option><option value="warning">Warning</option><option value="incompatible">Incompatible</option></select></label>
    </div>
    <label>Notes<input name="notes" maxlength="1000" placeholder="Reason, prerequisite, or migration guidance"></label>
    <button class="button button-small" type="submit">Add compatibility rule</button>
  </form>
  <?php if($fleetCompatibilityRules): ?><div class="admin-table-wrap" style="margin-top:10px"><table class="admin-table"><thead><tr><th>Browser range</th><th>HomeServer range</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead><tbody>
    <?php foreach($fleetCompatibilityRules as $rule): ?><tr>
      <td><?= e((string)($rule['browser_min_version']?:'any')) ?> → <?= e((string)($rule['browser_max_version']?:'any')) ?></td>
      <td><?= e((string)($rule['homeserver_min_version']?:'any')) ?> → <?= e((string)($rule['homeserver_max_version']?:'any')) ?></td>
      <td><?= e((string)$rule['compatibility_status']) ?><?= empty($rule['is_active'])?' · inactive':'' ?></td><td><?= e((string)$rule['notes']) ?></td>
      <td>
        <form method="post" class="admin-form"><?= csrf_field() ?><input type="hidden" name="action" value="fleet_compatibility_save"><input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
          <input type="hidden" name="browser_min_version" value="<?= e((string)$rule['browser_min_version']) ?>"><input type="hidden" name="browser_max_version" value="<?= e((string)$rule['browser_max_version']) ?>"><input type="hidden" name="homeserver_min_version" value="<?= e((string)$rule['homeserver_min_version']) ?>"><input type="hidden" name="homeserver_max_version" value="<?= e((string)$rule['homeserver_max_version']) ?>"><input type="hidden" name="compatibility_status" value="<?= e((string)$rule['compatibility_status']) ?>"><input type="hidden" name="notes" value="<?= e((string)$rule['notes']) ?>"><label><input type="checkbox" name="is_active" value="1" <?= !empty($rule['is_active'])?'checked':'' ?>> Active</label><button class="button button-small" type="submit">Update</button>
        </form>
        <form method="post" onsubmit="return confirm('Delete this compatibility rule?');"><?= csrf_field() ?><input type="hidden" name="action" value="fleet_compatibility_delete"><input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>"><button class="button button-small" type="submit">Delete</button></form>
      </td>
    </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>

  <?php $compatCounts=(array)($fleetCompatibility['counts']??[]); ?>
  <p style="margin-top:10px"><small>Observed account pairs: <?= array_sum(array_map('intval',$compatCounts)) ?> · compatible <?= (int)($compatCounts['compatible']??0) ?> · warnings <?= (int)($compatCounts['warning']??0) ?> · incompatible <?= (int)($compatCounts['incompatible']??0) ?> · unknown <?= (int)($compatCounts['unknown']??0) ?></small></p>

  <div class="admin-card-head" style="margin-top:18px"><div><h3>Upgrade Paths</h3><p>Define optional intermediate releases for clients that cannot safely move directly to a final GA maintenance target.</p></div></div>
  <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $fleetProduct=>$fleetLabel): ?>
    <?php $fleetReleases=client_release_admin_rollouts_v110($pdo,$fleetProduct); ?>
    <form method="post" class="admin-form" style="margin-top:10px">
      <?= csrf_field() ?><input type="hidden" name="action" value="fleet_upgrade_path_save"><input type="hidden" name="product" value="<?= e($fleetProduct) ?>"><input type="hidden" name="is_active" value="1">
      <strong><?= e($fleetLabel) ?></strong>
      <div class="form-row">
        <label>Channel<select name="channel"><?php foreach(['stable','beta','dev'] as $ch): ?><option value="<?= e($ch) ?>"><?= e(ucfirst($ch)) ?></option><?php endforeach; ?></select></label>
        <label>From min<input name="from_min_version" maxlength="64"></label><label>From max<input name="from_max_version" maxlength="64"></label>
        <label>Final target<select name="target_release_id"><?php foreach($fleetReleases as $rr): ?><option value="<?= (int)$rr['id'] ?>">v<?= e((string)$rr['version']) ?> · <?= e((string)$rr['channel']) ?></option><?php endforeach; ?></select></label>
        <label>Intermediate<select name="intermediate_release_id"><option value="0">None</option><?php foreach($fleetReleases as $rr): ?><option value="<?= (int)$rr['id'] ?>">v<?= e((string)$rr['version']) ?> · <?= e((string)$rr['channel']) ?></option><?php endforeach; ?></select></label>
      </div>
      <label>Notes<input name="notes" maxlength="1000"></label>
      <button class="button button-small" type="submit">Add upgrade path</button>
    </form>
    <?php foreach(['stable','beta','dev'] as $ch): $paths=client_fleet_upgrade_paths_v160($pdo,$fleetProduct,$ch); if(!$paths)continue; ?>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Channel</th><th>From</th><th>Final</th><th>Intermediate</th><th>Notes</th><th></th></tr></thead><tbody>
      <?php foreach($paths as $path): $target=client_release_release_row_v110($pdo,$fleetProduct,(int)$path['target_release_id']);$mid=(int)($path['intermediate_release_id']??0)>0?client_release_release_row_v110($pdo,$fleetProduct,(int)$path['intermediate_release_id']):null; ?>
        <tr><td><?= e($ch) ?></td><td><?= e((string)($path['from_min_version']?:'any')) ?> → <?= e((string)($path['from_max_version']?:'any')) ?></td><td><?= $target?'v'.e((string)$target['version']):'#'.(int)$path['target_release_id'] ?></td><td><?= $mid?'v'.e((string)$mid['version']):'Direct' ?></td><td><?= e((string)$path['notes']) ?></td><td><form method="post" onsubmit="return confirm('Delete this upgrade path?');"><?= csrf_field() ?><input type="hidden" name="action" value="fleet_upgrade_path_delete"><input type="hidden" name="path_id" value="<?= (int)$path['id'] ?>"><button class="button button-small" type="submit">Delete</button></form></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <div class="admin-card-head" style="margin-top:18px"><div><h3>Maintenance Campaigns</h3><p>Create explicit maintenance cohorts from the current fleet. v1.40 risk limits the maximum active cohort; active incidents and pinned clients are excluded.</p></div></div>
  <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $fleetProduct=>$fleetLabel): $fleetReleases=client_release_admin_rollouts_v110($pdo,$fleetProduct); ?>
    <form method="post" class="admin-form" style="margin-top:10px">
      <?= csrf_field() ?><input type="hidden" name="action" value="fleet_campaign_create"><input type="hidden" name="product" value="<?= e($fleetProduct) ?>">
      <strong><?= e($fleetLabel) ?></strong>
      <div class="form-row">
        <label>Title<input name="title" maxlength="180" placeholder="Optional campaign name"></label>
        <label>Channel<select name="channel"><?php foreach(['stable','beta','dev'] as $ch): ?><option value="<?= e($ch) ?>"><?= e(ucfirst($ch)) ?></option><?php endforeach; ?></select></label>
        <label>GA target<select name="target_release_id"><?php foreach($fleetReleases as $rr): ?><option value="<?= (int)$rr['id'] ?>">v<?= e((string)$rr['version']) ?> · <?= e((string)$rr['channel']) ?> · <?= e((string)($rr['_rollout']['lifecycle_state']??'draft')) ?></option><?php endforeach; ?></select></label>
        <label>Eligibility<select name="eligibility_mode"><option value="outdated">Outdated</option><option value="unsupported">Unsupported</option><option value="deprecated">Deprecated + unsupported</option><option value="maintenance">Maintenance/deprecated/unsupported</option><option value="all_supported_old">All older supported</option><option value="stale">Stale</option></select></label>
        <label><input type="checkbox" name="window_enforced" value="1" checked> Enforce maintenance window</label>
      </div>
      <button class="button button-small" type="submit">Create draft campaign</button>
    </form>
  <?php endforeach; ?>

  <?php if($fleetCampaigns): ?><div class="admin-table-wrap" style="margin-top:10px"><table class="admin-table"><thead><tr><th>Campaign</th><th>Target</th><th>Fleet</th><th>Risk cap</th><th>Control</th></tr></thead><tbody>
    <?php foreach($fleetCampaigns as $campaign): $cs=(array)$campaign['_stats'];$target=(array)($campaign['_target_release']??[]);$maxCohort=client_fleet_max_cohort_for_target_v160($pdo,(string)$campaign['product'],(int)$campaign['target_release_id']); ?>
      <tr>
        <td><strong>#<?= (int)$campaign['id'] ?> · <?= e((string)$campaign['title']) ?></strong><br><small><?= e((string)$campaign['product']) ?> · <?= e((string)$campaign['channel']) ?> · <?= e((string)$campaign['campaign_state']) ?> · <?= (int)$campaign['cohort_percent'] ?>%</small></td>
        <td><?= $target?'v'.e((string)$target['version']):'#'.(int)$campaign['target_release_id'] ?><br><small><?= e((string)$campaign['eligibility_mode']) ?><?= !empty($campaign['window_enforced'])?' · window enforced':'' ?></small></td>
        <td><small><?= (int)$cs['total'] ?> total · <?= (int)$cs['queued'] ?> queued · <?= (int)$cs['offered'] ?> offered · <?= (int)$cs['downloaded'] ?> downloaded · <?= (int)$cs['installed'] ?> installed · <?= (int)$cs['failed'] ?> failed · <?= (int)$cs['offline'] ?> offline · <?= (int)$cs['excluded'] ?> excluded</small></td>
        <td><?= $maxCohort ?>%</td>
        <td>
          <form method="post" class="admin-form"><?= csrf_field() ?><input type="hidden" name="action" value="fleet_campaign_set"><input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
            <div class="form-row"><label>State<select name="campaign_state"><?php foreach(['draft','active','paused','completed','cancelled'] as $st): ?><option value="<?= e($st) ?>" <?= (string)$campaign['campaign_state']===$st?'selected':'' ?>><?= e(ucfirst($st)) ?></option><?php endforeach; ?></select></label><label>Cohort %<input type="number" name="cohort_percent" min="0" max="100" value="<?= (int)$campaign['cohort_percent'] ?>"></label></div>
            <button class="button button-small" type="submit">Apply campaign</button>
          </form>
          <form method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="fleet_campaign_refresh"><input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>"><button class="button button-small" type="submit">Refresh fleet state</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>

  <div class="admin-card-head" style="margin-top:18px"><div><h3>Fleet Inventory &amp; Exceptions</h3><p>Version, channel, support status, staleness, drift, incident state, and temporary version pins. Inventory display is capped at 100 clients per product.</p></div></div>
  <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $fleetProduct=>$fleetLabel): ?>
    <div class="admin-table-wrap" style="margin-top:10px"><table class="admin-table"><thead><tr><th><?= e($fleetLabel) ?></th><th>Installed</th><th>Support</th><th>State</th><th>Temporary pin</th></tr></thead><tbody>
    <?php foreach(array_slice((array)($fleetInventory[$fleetProduct]??[]),0,100) as $client): $pin=is_array($client['pinned']??null)?$client['pinned']:null; ?>
      <tr>
        <td><strong>#<?= (int)$client['user_id'] ?> · <?= e((string)$client['name']) ?></strong><br><small><?= e((string)$client['scope_key']) ?> · <?= e((string)$client['channel']) ?></small></td>
        <td>v<?= e((string)$client['installed_version']) ?><br><small>Latest <?= e((string)$client['latest_version']) ?></small></td>
        <td><strong><?= e(ucfirst((string)$client['support_status'])) ?></strong><br><small><?= e((string)$client['support_reason']) ?></small></td>
        <td><small><?= !empty($client['stale'])?'Stale · ':'' ?><?= !empty($client['drift'])?'Drift · ':'' ?><?= !empty($client['incident'])?'Incident-controlled · ':'' ?>Last seen <?= e((string)($client['last_seen_at']??'unknown')) ?></small></td>
        <td>
          <?php if($pin): ?><strong>v<?= e((string)$pin['pinned_version']) ?></strong><br><small><?= e((string)$pin['reason']) ?><?= !empty($pin['expires_at'])?' · until '.e((string)$pin['expires_at']):'' ?></small><form method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="fleet_pin_clear"><input type="hidden" name="pin_id" value="<?= (int)$pin['id'] ?>"><button class="button button-small" type="submit">Clear pin</button></form>
          <?php else: ?>
            <form method="post" class="admin-form"><?= csrf_field() ?><input type="hidden" name="action" value="fleet_pin_set"><input type="hidden" name="product" value="<?= e($fleetProduct) ?>"><input type="hidden" name="user_id" value="<?= (int)$client['user_id'] ?>"><input type="hidden" name="scope_key" value="<?= e((string)$client['scope_key']) ?>">
              <label>Version<input name="pinned_version" maxlength="64" required placeholder="Published version"></label><label>Reason<input name="reason" maxlength="500" required></label><label>Expires<input type="datetime-local" name="expires_at"></label><button class="button button-small" type="submit">Pin version</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if(empty($fleetInventory[$fleetProduct])): ?><tr><td colspan="5">No connected clients.</td></tr><?php endif; ?>
    </tbody></table></div>
  <?php endforeach; ?>

  <?php $compatRows=array_slice((array)($fleetCompatibility['rows']??[]),0,50); if($compatRows): ?>
    <div class="admin-card-head" style="margin-top:18px"><div><h3>Observed Compatibility Pairs</h3><p>Up to 50 Browser Companion ↔ HomeServer pairs for accounts with both clients connected.</p></div></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Account</th><th>Browser</th><th>HomeServer</th><th>Compatibility</th></tr></thead><tbody>
    <?php foreach($compatRows as $pair): $compat=(array)$pair['compatibility']; ?>
      <tr><td>#<?= (int)$pair['user_id'] ?></td><td>v<?= e((string)$pair['browser']['installed_version']) ?></td><td>v<?= e((string)$pair['homeserver']['installed_version']) ?></td><td><strong><?= e(ucfirst((string)$compat['status'])) ?></strong><br><small><?= e((string)$compat['notes']) ?></small></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="admin-card" id="release-risk" style="margin-bottom:24px">
  <div class="admin-card-head"><div><h3>Release Learning &amp; Risk Prediction</h3><p>Use current health plus prior release and incident outcomes to estimate rollout risk before expansion. <strong>Advisory only:</strong> v1.40 never promotes, pauses, contains, rolls back, or installs software.</p></div><span class="eyebrow">v1.40</span></div>

  <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $product=>$productLabel): ?>
    <?php
      $channelLearning=[];
      foreach(['stable','beta','dev'] as $riskChannel)$channelLearning[$riskChannel]=client_release_risk_learning_summary_v140($pdo,$product,$riskChannel);
    ?>
    <div class="admin-card-head" style="margin-top:16px"><div><h3><?= e($productLabel) ?> Historical learning</h3><p>Resolved incident postmortems are converted into explainable risk domains and reused only as evidence for future advisory assessments.</p></div></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Channel</th><th>Incident history</th><th>Recurring domains</th><th>Risk policy</th></tr></thead><tbody>
    <?php foreach(['stable','beta','dev'] as $riskChannel):
      $learn=(array)$channelLearning[$riskChannel];
      $riskPolicy=client_release_risk_policy_v140($pdo,$product,$riskChannel);
    ?>
      <tr>
        <td><strong><?= e(ucfirst($riskChannel)) ?></strong></td>
        <td><?= (int)$learn['incidents'] ?> incidents · <?= (int)$learn['resolved'] ?> resolved</td>
        <td><small><?php if(!empty($learn['domains'])): ?><?php foreach(array_slice((array)$learn['domains'],0,4,true) as $domain=>$count): ?><?= e((string)($domain)) ?> ×<?= (int)$count ?> &nbsp;<?php endforeach; ?><?php else: ?>No recurring domains yet<?php endif; ?></small></td>
        <td>
          <form method="post" class="admin-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="risk_policy_update"><input type="hidden" name="product" value="<?= e($product) ?>"><input type="hidden" name="channel" value="<?= e($riskChannel) ?>">
            <div class="form-row">
              <label>Lookback<input type="number" name="lookback_releases" min="3" max="50" value="<?= (int)$riskPolicy['lookback_releases'] ?>"></label>
              <label>Min comparable<input type="number" name="min_comparable_releases" min="1" max="20" value="<?= (int)$riskPolicy['min_comparable_releases'] ?>"></label>
            </div>
            <div class="form-row">
              <label>Low max<input type="number" name="low_max_score" min="5" max="40" value="<?= (int)$riskPolicy['low_max_score'] ?>"></label>
              <label>Moderate max<input type="number" name="moderate_max_score" min="20" max="70" value="<?= (int)$riskPolicy['moderate_max_score'] ?>"></label>
              <label>High max<input type="number" name="high_max_score" min="40" max="95" value="<?= (int)$riskPolicy['high_max_score'] ?>"></label>
            </div>
            <div class="form-row">
              <label>Incident warn bps<input type="number" name="incident_rate_warning_bps" min="0" max="10000" value="<?= (int)$riskPolicy['incident_rate_warning_bps'] ?>"></label>
              <label>Failure warn bps<input type="number" name="failure_rate_warning_bps" min="0" max="10000" value="<?= (int)$riskPolicy['failure_rate_warning_bps'] ?>"></label>
              <label>Compat warn bps<input type="number" name="compatibility_rate_warning_bps" min="0" max="10000" value="<?= (int)$riskPolicy['compatibility_rate_warning_bps'] ?>"></label>
            </div>
            <button class="button button-small" type="submit">Save risk policy</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>

    <div class="admin-table-wrap" style="margin-top:14px"><table class="admin-table">
      <thead><tr><th>Release</th><th>Predicted risk</th><th>Evidence</th><th>Change profile</th><th>Operator review</th></tr></thead>
      <tbody>
      <?php foreach((array)($releaseRisk[$product]??[]) as $risk):
        if(!empty($risk['error']))continue;
        $rid=(int)$risk['release_id'];
        $profile=(array)$risk['profile'];
        $learning=(array)$risk['learning'];
        $latestRisk=client_release_risk_latest_snapshot_v140($pdo,$product,$rid);
      ?>
        <tr>
          <td><strong>v<?= e((string)$risk['version']) ?></strong><br><small><?= e((string)$risk['channel']) ?> · release #<?= $rid ?><br><?= e((string)($risk['health']['rollout']['lifecycle_state']??'draft')) ?></small></td>
          <td><strong><?= (int)$risk['risk_score'] ?>/100 · <?= e(ucfirst((string)$risk['risk_level'])) ?></strong><br><small><?= e(client_release_risk_recommendation_label_v140((string)$risk['recommendation'])) ?><br>Confidence: <?= e((string)$risk['confidence']) ?></small>
            <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="risk_assess"><input type="hidden" name="product" value="<?= e($product) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>"><button class="button button-small" type="submit">Assess &amp; snapshot</button></form>
          </td>
          <td><small>
            <?= (int)$learning['comparable_releases'] ?> comparable releases · <?= (int)$learning['incident_releases'] ?> had incidents<br>
            Historical incident rate <?= e(client_release_health_percent_v120((int)$learning['historical_incident_rate_bps'])) ?><br>
            Historical avg failure <?= e(client_release_health_percent_v120((int)$learning['historical_avg_failure_rate_bps'])) ?><br>
            Matching prior incident domains <?= (int)$learning['matching_incident_domains'] ?>
            <?php foreach((array)$risk['factors'] as $factor): ?><br><?= e((string)$factor['factor']) ?><?= $factor['points']!==null?' +'.(int)$factor['points']:'' ?>: <?= e((string)$factor['detail']) ?><?php endforeach; ?>
          </small></td>
          <td>
            <form method="post" class="admin-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="risk_profile_update"><input type="hidden" name="product" value="<?= e($product) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>">
              <div class="form-row">
                <label>Change size<select name="change_size"><?php foreach(['unknown','small','medium','large'] as $size): ?><option value="<?= e($size) ?>" <?= (string)$profile['change_size']===$size?'selected':'' ?>><?= e(ucfirst($size)) ?></option><?php endforeach; ?></select></label>
                <label>Rollback<select name="rollback_complexity"><?php foreach(['easy','normal','hard'] as $complexity): ?><option value="<?= e($complexity) ?>" <?= (string)$profile['rollback_complexity']===$complexity?'selected':'' ?>><?= e(ucfirst($complexity)) ?></option><?php endforeach; ?></select></label>
              </div>
              <div class="admin-check-grid">
                <?php foreach(client_release_risk_domains_v140() as $domain=>$label): ?><label><input type="checkbox" name="touches_<?= e($domain) ?>" value="1" <?= !empty($profile['touches_'.$domain])?'checked':'' ?>> <?= e($label) ?></label><?php endforeach; ?>
              </div>
              <label>Notes<textarea name="notes" rows="2" maxlength="2000"><?= e((string)$profile['notes']) ?></textarea></label>
              <button class="button button-small" type="submit">Save change profile</button>
            </form>
          </td>
          <td>
            <?php if($latestRisk): ?>
              <small>Snapshot #<?= (int)$latestRisk['id'] ?> · <?= (int)$latestRisk['risk_score'] ?>/100 · <?= e((string)$latestRisk['risk_level']) ?><br><?= e((string)$latestRisk['assessed_at']) ?></small>
              <form method="post" class="admin-form" style="margin-top:8px">
                <?= csrf_field() ?><input type="hidden" name="action" value="risk_review"><input type="hidden" name="product" value="<?= e($product) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>"><input type="hidden" name="snapshot_id" value="<?= (int)$latestRisk['id'] ?>">
                <label>Decision<select name="decision"><option value="reviewed">Reviewed</option><option value="accepted_risk">Accept risk</option><option value="defer">Defer release</option></select></label>
                <label>Note<input name="note" maxlength="1000" placeholder="Required for accept/defer"></label>
                <button class="button button-small" type="submit">Record review</button>
              </form>
            <?php else: ?><small>Create a risk snapshot before recording a review.</small><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endforeach; ?>

  <?php if($riskReviews): ?>
    <div class="admin-card-head" style="margin-top:16px"><div><h3>Recent Risk Reviews</h3><p>Human review remains separate from rollout execution.</p></div></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>When</th><th>Client</th><th>Release</th><th>Decision</th><th>Note</th></tr></thead><tbody>
      <?php foreach($riskReviews as $review): ?><tr><td><?= e((string)$review['created_at']) ?></td><td><?= e((string)$review['product']) ?></td><td>#<?= (int)$review['release_id'] ?></td><td><?= e(ucwords(str_replace('_',' ',(string)$review['decision']))) ?></td><td><?= e((string)$review['note']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="admin-card" id="release-incidents" style="margin-bottom:24px">
  <div class="admin-card-head"><div><h3>Release Incidents &amp; Fleet Recovery</h3><p>Contain a problematic client release, recover affected clients through deterministic cohorts, verify the fleet, and preserve the incident record. v1.30 does not auto-rollback: containment and recovery are operator-approved actions.</p></div><span class="eyebrow">v1.30</span></div>
  <?php if(!$releaseIncidents): ?>
    <p>No release incidents have been opened.</p>
  <?php endif; ?>
  <?php foreach($releaseIncidents as $incident):
    $iid=(int)$incident['id'];
    $affectedRelease=is_array($incident['_affected_release']??null)?$incident['_affected_release']:null;
    $recoveryRelease=is_array($incident['_recovery_release']??null)?$incident['_recovery_release']:null;
    $stats=(array)($incident['_stats']??[]);
    $closure=is_array($incident['_closure']??null)?$incident['_closure']:null;
    $clients=client_release_incident_clients_v130($pdo,$iid);
    $events=client_release_incident_events_v130($pdo,$iid,8);
    $status=(string)$incident['status'];
  ?>
  <section class="admin-card" id="incident-<?= $iid ?>" style="margin:14px 0">
    <div class="admin-card-head">
      <div>
        <h3>#<?= $iid ?> · <?= e((string)$incident['title']) ?></h3>
        <p><?= e(ucfirst((string)$incident['severity'])) ?> · <?= e(ucwords(str_replace('_',' ',$status))) ?> · <?= e((string)$incident['product']) ?> <?= $affectedRelease?'v'.e((string)$affectedRelease['version']):'#'.(int)$incident['release_id'] ?></p>
        <?php if((string)$incident['symptoms']!==''): ?><p><?= nl2br(e((string)$incident['symptoms'])) ?></p><?php endif; ?>
      </div>
      <span class="eyebrow"><?= (int)($incident['recovery_percent']??0) ?>% recovery</span>
    </div>
    <div class="admin-table-wrap"><table class="admin-table"><tbody>
      <tr><td>Affected</td><td><strong><?= (int)($stats['affected']??0) ?></strong></td><td>Recovered</td><td><strong><?= (int)($stats['recovered']??0) ?></strong> · <?= e(client_release_health_percent_v120((int)($stats['recovery_rate_bps']??0))) ?></td><td>Failed</td><td><?= (int)($stats['recovery_failed']??0) ?></td></tr>
      <tr><td>Pending</td><td><?= (int)($stats['pending']??0) ?></td><td>Offline</td><td><?= (int)($stats['offline']??0) ?></td><td>Acknowledged</td><td><?= (int)($stats['acknowledged']??0) ?></td></tr>
      <tr><td>Bad release</td><td><?= $affectedRelease?'v'.e((string)$affectedRelease['version']):'Unavailable' ?></td><td>Recovery target</td><td><?= $recoveryRelease?'v'.e((string)$recoveryRelease['version']):'Not selected' ?></td><td>Opened</td><td><?= e((string)$incident['opened_at']) ?></td></tr>
    </tbody></table></div>

    <?php if($status==='open'): ?>
      <form method="post" style="margin-top:12px" onsubmit="return confirm('Pause this affected rollout and stop offering it to new clients?');">
        <?= csrf_field() ?><input type="hidden" name="action" value="incident_contain"><input type="hidden" name="incident_id" value="<?= $iid ?>">
        <button class="button button-small primary" type="submit">Contain affected rollout</button>
      </form>
    <?php endif; ?>

    <?php if($status==='contained'): ?>
      <?php
        $recoveryChoices=[];
        foreach(client_release_admin_rollouts_v110($pdo,(string)$incident['product']) as $candidate){
          if((int)$candidate['id']===(int)$incident['release_id'])continue;
          if($affectedRelease&&(string)($candidate['channel']??'stable')!==(string)($affectedRelease['channel']??'stable'))continue;
          $candidateRoll=(array)($candidate['_rollout']??[]);
          if(!in_array((string)($candidateRoll['lifecycle_state']??''),['superseded','general_availability'],true))continue;
          $recoveryChoices[]=$candidate;
        }
      ?>
      <form method="post" class="admin-form" style="margin-top:12px">
        <?= csrf_field() ?><input type="hidden" name="action" value="incident_start_recovery"><input type="hidden" name="incident_id" value="<?= $iid ?>">
        <div class="form-row">
          <label>Known-good release<select name="recovery_release_id" required>
            <?php foreach($recoveryChoices as $candidate): ?><option value="<?= (int)$candidate['id'] ?>" <?= (int)($incident['recovery_release_id']??0)===(int)$candidate['id']?'selected':'' ?>>v<?= e((string)$candidate['version']) ?> · <?= e((string)$candidate['channel']) ?></option><?php endforeach; ?>
          </select></label>
          <label>Initial cohort<select name="recovery_percent"><?php foreach([10,25,50,100] as $pct): ?><option value="<?= $pct ?>"><?= $pct ?>%</option><?php endforeach; ?></select></label>
        </div>
        <?php if($recoveryChoices): ?><button class="button button-small primary" type="submit">Start operator-approved recovery</button><?php else: ?><small>No prior known-good release is available on this channel.</small><?php endif; ?>
      </form>
    <?php endif; ?>

    <?php if(in_array($status,['recovering','monitoring'],true)): ?>
      <div class="form-actions" style="margin-top:12px">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="incident_cohort"><input type="hidden" name="incident_id" value="<?= $iid ?>"><label>Recovery cohort <select name="recovery_percent"><?php foreach([0,10,25,50,100] as $pct): ?><option value="<?= $pct ?>" <?= (int)$incident['recovery_percent']===$pct?'selected':'' ?>><?= $pct===0?'Paused':$pct.'%' ?></option><?php endforeach; ?></select></label><button class="button button-small" type="submit">Apply cohort</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="incident_refresh"><input type="hidden" name="incident_id" value="<?= $iid ?>"><button class="button button-small" type="submit">Refresh fleet state</button></form>
      </div>
    <?php endif; ?>

    <?php if($clients): ?>
      <div class="admin-card-head" style="margin-top:16px"><div><h3>Affected Clients</h3><p>Recovery eligibility is deterministic by incident/client scope. Offline, failed, or otherwise stranded clients remain visible until recovered or explicitly acknowledged.</p></div></div>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Account</th><th>Scope</th><th>Cohort</th><th>Recovery state</th><th>Last seen</th><th>Exception</th></tr></thead><tbody>
      <?php foreach($clients as $client): ?>
        <tr>
          <td>#<?= (int)$client['user_id'] ?></td><td><?= e((string)$client['scope_key']) ?></td><td><?= (int)$client['recovery_bucket'] ?></td>
          <td><?= e(ucwords(str_replace('_',' ',(string)$client['recovery_state']))) ?></td><td><?= e((string)($client['last_seen_at']??'Unknown')) ?></td>
          <td>
            <?php if((string)$client['recovery_state']==='acknowledged'): ?><small><?= e((string)$client['acknowledged_reason']) ?></small>
            <?php elseif(!in_array((string)$client['recovery_state'],['recovered'],true)&&$status!=='resolved'): ?>
              <form method="post" class="admin-form"><?= csrf_field() ?><input type="hidden" name="action" value="incident_acknowledge"><input type="hidden" name="incident_id" value="<?= $iid ?>"><input type="hidden" name="user_id" value="<?= (int)$client['user_id'] ?>"><input type="hidden" name="scope_key" value="<?= e((string)$client['scope_key']) ?>"><label>Reason<input name="reason" maxlength="500" required placeholder="Manual recovery, retired device, unreachable client…"></label><button class="button button-small" type="submit">Acknowledge exception</button></form>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>

    <?php if($status!=='resolved'): ?>
      <form method="post" class="admin-form" style="margin-top:14px">
        <?= csrf_field() ?><input type="hidden" name="action" value="incident_policy"><input type="hidden" name="incident_id" value="<?= $iid ?>">
        <div class="form-row">
          <label>Min recovery bps<input type="number" name="min_recovery_rate_bps" min="5000" max="10000" value="<?= (int)$incident['min_recovery_rate_bps'] ?>"></label>
          <label>Max failure bps<input type="number" name="max_recovery_failure_rate_bps" min="0" max="5000" value="<?= (int)$incident['max_recovery_failure_rate_bps'] ?>"></label>
          <label>Verify hours<input type="number" name="verification_hours" min="0" max="168" value="<?= (int)$incident['verification_hours'] ?>"></label>
        </div>
        <button class="button button-small" type="submit">Save closure gates</button>
      </form>
    <?php endif; ?>

    <?php if($status!=='resolved'&&$closure): ?>
      <div style="margin-top:14px"><strong>Closure readiness: <?= !empty($closure['ready'])?'Ready':'Not ready' ?></strong>
        <?php foreach((array)($closure['reasons']??[]) as $reason): ?><br><small><?= e((string)$reason) ?></small><?php endforeach; ?>
      </div>
      <?php if(!empty($closure['ready'])): ?>
        <form method="post" class="admin-form" style="margin-top:12px">
          <?= csrf_field() ?><input type="hidden" name="action" value="incident_resolve"><input type="hidden" name="incident_id" value="<?= $iid ?>">
          <label>Root cause<textarea name="root_cause" rows="2" maxlength="2000" required></textarea></label>
          <label>Resolution summary<textarea name="resolution_summary" rows="2" maxlength="2000" required></textarea></label>
          <label>Lessons learned<textarea name="lessons_learned" rows="2" maxlength="4000"></textarea></label>
          <button class="button button-small primary" type="submit">Resolve incident</button>
        </form>
      <?php endif; ?>
    <?php elseif($status==='resolved'): ?>
      <div style="margin-top:14px"><strong>Resolved <?= e((string)$incident['resolved_at']) ?></strong><br><small>Root cause: <?= e((string)$incident['root_cause']) ?></small><br><small>Resolution: <?= e((string)$incident['resolution_summary']) ?></small><?php if((string)$incident['lessons_learned']!==''): ?><br><small>Lessons: <?= e((string)$incident['lessons_learned']) ?></small><?php endif; ?></div>
    <?php endif; ?>

    <?php if($events): ?>
      <div class="admin-card-head" style="margin-top:16px"><div><h3>Incident Timeline</h3><p>Latest containment, recovery, verification, exception, and closure events.</p></div></div>
      <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>When</th><th>Event</th><th>Transition</th></tr></thead><tbody><?php foreach($events as $event): ?><tr><td><?= e((string)$event['created_at']) ?></td><td><?= e(ucwords(str_replace('_',' ',(string)$event['event_type']))) ?></td><td><?= e((string)$event['from_state']) ?><?= (string)$event['to_state']!==''?' → '.e((string)$event['to_state']):'' ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
</section>

<section class="admin-card" id="release-health" style="margin-bottom:24px">
  <div class="admin-card-head"><div><h3>Release Health &amp; Promotion</h3><p>Evaluate cohort health before expanding a rollout. Health produces guidance only; a release moves forward only after an administrator explicitly approves a current healthy snapshot.</p></div><span class="eyebrow">v1.20</span></div>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Release</th><th>Health</th><th>Signals</th><th>Policy</th><th>Operator decision</th></tr></thead>
    <tbody>
    <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $product=>$productLabel): ?>
      <?php foreach((array)($releaseHealth[$product]??[]) as $health):
        if(!empty($health['error']))continue;
        $rid=(int)$health['release_id'];
        $roll=(array)($health['rollout']??[]);
        $policy=(array)($health['policy']??[]);
        $snapshot=is_array($health['latest_snapshot']??null)?$health['latest_snapshot']:null;
        $recommendation=(string)($health['recommendation']??'manual_validation');
        $next=is_array($health['next_transition']??null)?$health['next_transition']:null;
        $activeIncident=client_release_incident_active_for_release_v130($pdo,$product,$rid);
      ?>
      <tr>
        <td><strong><?= e($productLabel) ?> v<?= e((string)$health['version']) ?></strong><br><small><?= e((string)$health['channel']) ?> · #<?= $rid ?><br><?= e(ucwords(str_replace('_',' ',(string)($roll['lifecycle_state']??'draft')))) ?> · <?= (int)($roll['rollout_percent']??0) ?>%</small></td>
        <td><strong><?= e(ucwords(str_replace('_',' ',(string)$health['health_status']))) ?></strong><br><small><?= e(client_release_health_recommendation_label_v120($recommendation)) ?></small><?php foreach((array)($health['reasons']??[]) as $reason): ?><br><small><?= e((string)$reason) ?></small><?php endforeach; ?></td>
        <td>
          <small>
            <?= (int)$health['observed_clients'] ?> observed · <?= (int)$health['installed_clients'] ?> installed · <?= (int)$health['failed_clients'] ?> failed<br>
            Failure <?= e(client_release_health_percent_v120((int)$health['failure_rate_bps'])) ?> · compatibility <?= e(client_release_health_percent_v120((int)$health['compatibility_failure_rate_bps'])) ?><br>
            Install <?= e(client_release_health_percent_v120((int)$health['install_rate_bps'])) ?> · <?= e(number_format((float)$health['observation_hours'],1)) ?>h observed<br>
            Adoption velocity <?= e(number_format((float)$health['adoption_velocity_per_day'],2)) ?>/day
          </small>
          <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="health_evaluate"><input type="hidden" name="product" value="<?= e($product) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>"><button class="button button-small" type="submit">Evaluate now</button></form>
        </td>
        <td>
          <form method="post" class="admin-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="health_policy_update"><input type="hidden" name="product" value="<?= e($product) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>">
            <div class="form-row">
              <label>Min clients<input type="number" name="min_observed_clients" min="1" max="100000" value="<?= (int)($policy['min_observed_clients']??3) ?>"></label>
              <label>Observe hours<input type="number" name="min_observation_hours" min="0" max="720" value="<?= (int)($policy['min_observation_hours']??6) ?>"></label>
            </div>
            <div class="form-row">
              <label>Max failure bps<input type="number" name="max_failure_rate_bps" min="0" max="10000" value="<?= (int)($policy['max_failure_rate_bps']??500) ?>"></label>
              <label>Max compat bps<input type="number" name="max_compat_failure_rate_bps" min="0" max="10000" value="<?= (int)($policy['max_compat_failure_rate_bps']??200) ?>"></label>
            </div>
            <div class="form-row">
              <label>Min install bps<input type="number" name="min_install_rate_bps" min="0" max="10000" value="<?= (int)($policy['min_install_rate_bps']??5000) ?>"></label>
              <label>Rollback bps<input type="number" name="rollback_failure_rate_bps" min="0" max="10000" value="<?= (int)($policy['rollback_failure_rate_bps']??1500) ?>"></label>
            </div>
            <label>Limited step %<input type="number" name="limited_step_percent" min="1" max="50" value="<?= (int)($policy['limited_step_percent']??25) ?>"></label>
            <button class="button button-small" type="submit">Save health gates</button>
          </form>
        </td>
        <td>
          <?php if($snapshot&&$recommendation==='promote'&&$next): ?>
            <strong>Suggested: <?= e(ucwords(str_replace('_',' ',(string)$next['state']))) ?> <?= (int)$next['percent'] ?>%</strong>
            <form method="post" class="admin-form" style="margin-top:8px">
              <?= csrf_field() ?><input type="hidden" name="action" value="health_promote"><input type="hidden" name="product" value="<?= e($product) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>"><input type="hidden" name="snapshot_id" value="<?= (int)$snapshot['id'] ?>">
              <label>Rationale<input name="rationale" maxlength="500" placeholder="Optional operator note"></label>
              <button class="button button-small primary" type="submit">Approve promotion</button>
            </form>
            <form method="post" class="admin-form" style="margin-top:6px">
              <?= csrf_field() ?><input type="hidden" name="action" value="health_reject"><input type="hidden" name="product" value="<?= e($product) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>"><input type="hidden" name="snapshot_id" value="<?= (int)$snapshot['id'] ?>">
              <label>Reason<input name="rationale" maxlength="500" placeholder="Why keep this cohort"></label>
              <button class="button button-small" type="submit">Reject recommendation</button>
            </form>
          <?php elseif($recommendation==='rollback_review'): ?>
            <strong>Review rollback</strong><br><small>v1.20 does not auto-rollback. Use the v1.10 lifecycle controls after reviewing the failure evidence.</small>
          <?php else: ?>
            <small>No promotion action is available until a freshly evaluated snapshot passes all configured gates.</small>
          <?php endif; ?>
          <?php if($activeIncident): ?>
            <br><small>Incident #<?= (int)$activeIncident['id'] ?> is <?= e((string)$activeIncident['status']) ?>.</small>
          <?php elseif(in_array((string)($roll['lifecycle_state']??''),['canary','limited','general_availability','paused'],true)): ?>
            <form method="post" class="admin-form" style="margin-top:8px">
              <?= csrf_field() ?><input type="hidden" name="action" value="incident_open"><input type="hidden" name="product" value="<?= e($product) ?>"><input type="hidden" name="release_id" value="<?= $rid ?>">
              <input type="hidden" name="severity" value="<?= $recommendation==='rollback_review'?'critical':'high' ?>">
              <input type="hidden" name="symptoms" value="<?= e('v1.20 health: '.(string)$health['health_status'].' / '.$recommendation) ?>">
              <button class="button button-small" type="submit">Open release incident</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if($healthDecisions): ?><div class="admin-card-head" style="margin-top:18px"><div><h3>Promotion Decisions</h3><p>Recent explicit operator approvals and rejections.</p></div></div>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>When</th><th>Client</th><th>Release</th><th>Decision</th><th>Transition</th><th>Rationale</th></tr></thead><tbody>
    <?php foreach($healthDecisions as $decision): ?><tr><td><?= e((string)$decision['created_at']) ?></td><td><?= e((string)$decision['product']) ?></td><td>#<?= (int)$decision['release_id'] ?></td><td><?= e(ucfirst((string)$decision['decision'])) ?></td><td><?= e((string)$decision['from_state']) ?> <?= (int)$decision['from_percent'] ?>% → <?= e((string)$decision['to_state']) ?> <?= (int)$decision['to_percent'] ?>%</td><td><?= e((string)$decision['rationale']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>

<section class="admin-card" id="controlled-rollouts" style="margin-bottom:24px">
  <div class="admin-card-head"><div><h3>Controlled Rollouts</h3><p>Move each client release through Draft → Testing → Canary/Limited → General Availability. Paused, superseded, and withdrawn releases are never offered to new client cohorts.</p></div><span class="eyebrow">v1.10</span></div>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Client release</th><th>Adoption</th><th>Lifecycle</th><th>Release guidance</th></tr></thead>
    <tbody>
    <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $product=>$productLabel): ?>
      <?php foreach((array)($rolloutAdoption[$product]??[]) as $roll): $rid=(int)$roll['release_id']; $releaseRow=client_release_release_row_v110($pdo,$product,$rid); $meta=$releaseRow?client_release_rollout_for_v110($pdo,$product,$rid,$releaseRow):[]; ?>
      <tr>
        <td><strong><?= e($productLabel) ?> v<?= e((string)$roll['version']) ?></strong><br><small><?= e((string)$roll['channel']) ?> · release #<?= $rid ?></small></td>
        <td><strong><?= (int)$roll['installed_clients'] ?></strong> installed<br><small><?= e(ucwords(str_replace('_',' ',(string)$roll['lifecycle_state']))) ?> · <?= (int)$roll['rollout_percent'] ?>%</small></td>
        <td>
          <form method="post" class="admin-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rollout_update">
            <input type="hidden" name="product" value="<?= e($product) ?>">
            <input type="hidden" name="release_id" value="<?= $rid ?>">
            <div class="form-row">
              <label>State<select name="lifecycle_state">
                <?php foreach(client_release_lifecycle_states_v110() as $state): ?><option value="<?= e($state) ?>" <?= (string)($meta['lifecycle_state']??'draft')===$state?'selected':'' ?>><?= e(ucwords(str_replace('_',' ',$state))) ?></option><?php endforeach; ?>
              </select></label>
              <label>Rollout %<input type="number" name="rollout_percent" min="0" max="100" value="<?= (int)($meta['rollout_percent']??0) ?>"></label>
            </div>
            <label>Summary<input name="summary" maxlength="500" value="<?= e((string)($meta['summary']??'')) ?>" placeholder="What changed in this release"></label>
            <label>Known issues<textarea name="known_issues" rows="2" maxlength="10000"><?= e((string)($meta['known_issues']??'')) ?></textarea></label>
            <label>Compatibility notes<input name="compatibility_notes" maxlength="1000" value="<?= e((string)($meta['compatibility_notes']??'')) ?>" placeholder="OS, architecture, or prerequisite notes"></label>
            <div class="form-actions"><button class="button button-small" type="submit">Update rollout</button></div>
          </form>
        </td>
        <td><small>Canary/Limited cohorts are deterministic per account/client. Choosing General Availability makes this the channel's current public release and supersedes the prior GA release. Selecting an older release as General Availability performs a controlled rollback.</small></td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if(empty($rolloutAdoption['browser_companion'])&&empty($rolloutAdoption['homeserver'])): ?><tr><td colspan="4">Upload a client release to begin a controlled rollout.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if($rolloutAudit): ?>
  <div class="admin-card-head" style="margin-top:18px"><div><h3>Release Audit Ledger</h3><p>Recent lifecycle, channel, download, defer, and rollback events.</p></div></div>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>When</th><th>Client</th><th>Release</th><th>Action</th><th>Transition</th></tr></thead><tbody>
    <?php foreach($rolloutAudit as $event): ?><tr><td><?= e((string)$event['created_at']) ?></td><td><?= e((string)$event['product']) ?></td><td><?= (int)($event['release_id']??0)>0?'#'.(int)$event['release_id']:'—' ?></td><td><?= e(ucwords(str_replace('_',' ',(string)$event['action']))) ?></td><td><?= e((string)$event['from_state']) ?><?= (string)$event['to_state']!==''?' → '.e((string)$event['to_state']):'' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>

<section id="chrome-extension-releases">
  <div class="admin-section-heading">
    <div>
      <span class="eyebrow">Browser Companion</span>
      <h2>Chrome Extension Releases</h2>
      <p>Upload verified Manifest V3 ZIP packages, publish release channels, and choose the version served by the public Chrome Extension download button.</p>
    </div>
  </div>

  <div class="admin-grid admin-grid-2">
    <section class="admin-card">
      <div class="admin-card-head"><div><h3>Extension Release Service</h3><p>VP3-managed Browser Companion distribution.</p></div></div>
      <div class="admin-table-wrap"><table class="admin-table"><tbody>
        <tr><td>Current stable</td><td><?= $chromeLatest ? '<strong>v'.e((string)$chromeLatest['version']).'</strong>' : '<strong>Repository fallback</strong><br><small>No managed stable release has been published yet.</small>' ?></td></tr>
        <tr><td>Public endpoint</td><td><a href="<?= e(url('/chrome-extension-download.php')) ?>" target="_blank" rel="noopener">chrome-extension-download.php</a><br><small>The endpoint serves the current managed stable ZIP and falls back to the repository package until one is published.</small></td></tr>
        <tr><td>Storage</td><td><strong>Private</strong><br><small>Uploaded ZIP packages live outside the public web surface and are served only through the managed download endpoint.</small></td></tr>
        <tr><td>Validation</td><td><strong>Manifest V3 + root package contract + SHA256</strong><br><small>Packages must contain manifest.json and the Browser Companion runtime files at ZIP root. Unsafe ZIP paths are rejected.</small></td></tr>
        <tr><td>Upload limit</td><td><strong>64 MB application limit</strong><br><small>PHP/server limits currently report <?= e($phpUploadMax) ?> per file and <?= e($phpPostMax) ?> per POST.</small></td></tr>
      </tbody></table></div>
    </section>

    <section class="admin-card">
      <div class="admin-card-head"><div><h3>Upload Chrome Extension</h3><p>The version is read directly from the package manifest.</p></div></div>
      <form method="post" enctype="multipart/form-data" class="admin-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="chrome_create">
        <div class="form-row">
          <label>Channel<select name="channel"><option value="stable" <?= $selectedChromeChannel==='stable'?'selected':'' ?>>Stable</option><option value="beta" <?= $selectedChromeChannel==='beta'?'selected':'' ?>>Beta</option><option value="dev" <?= $selectedChromeChannel==='dev'?'selected':'' ?>>Development</option></select></label>
          <label>Browser Companion ZIP<input type="file" name="chrome_zip" accept=".zip,application/zip" required></label>
        </div>
        <label>Release notes<textarea name="release_notes" rows="5" maxlength="10000"><?= e((string)($_POST['action'] ?? '') === 'chrome_create' ? (string)($_POST['release_notes'] ?? '') : '') ?></textarea></label>
        <div class="admin-check-grid">
          <label><input type="checkbox" name="is_published" value="1" <?= (string)($_POST['action'] ?? '') !== 'chrome_create' || !empty($_POST['is_published'])?'checked':'' ?>> Publish immediately</label>
          <label><input type="checkbox" name="is_latest" value="1" <?= (string)($_POST['action'] ?? '') !== 'chrome_create' || !empty($_POST['is_latest'])?'checked':'' ?>> Make current for channel</label>
        </div>
        <div class="form-actions"><button class="button primary" type="submit">Upload &amp; Verify Extension</button></div>
      </form>
    </section>
  </div>

  <section class="admin-card" style="margin-top:18px">
    <div class="admin-card-head"><div><h3>Chrome Extension History</h3><p><?= count($chromeReleases) ?> managed release<?= count($chromeReleases) === 1 ? '' : 's' ?></p></div></div>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Release</th><th>Package</th><th>Verification</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($chromeReleases as $release): $id=(int)$release['id']; $published=(int)$release['is_published']===1; ?>
        <tr id="chrome-release-<?= $id ?>">
          <td><strong>v<?= e((string)$release['version']) ?></strong><br><small><?= e((string)$release['channel']) ?> · <?= e((string)$release['created_at']) ?></small><?php if(trim((string)$release['release_notes'])!==''): ?><br><small><?= e(mb_strimwidth((string)$release['release_notes'],0,140,'…')) ?></small><?php endif; ?></td>
          <td><?= $published?'<a href="'.e(url('/chrome-extension-download.php')).'">Download current endpoint</a>':'<strong>Stored draft</strong>' ?><br><small><?= e((string)$release['package_name']) ?><br><?= number_format((int)$release['package_size']) ?> bytes</small></td>
          <td><strong>Manifest V<?= (int)$release['manifest_version'] ?></strong><br><small>SHA256 <?= e(substr((string)$release['package_sha256'],0,16)) ?>…</small></td>
          <td><?= $published?'Published':'Draft' ?><?= (int)$release['is_latest']===1?' · Current':'' ?></td>
          <td>
            <div class="form-actions">
              <?php if(!$published): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="chrome_publish"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Publish</button></form><?php endif; ?>
              <?php if((int)$release['is_latest']!==1): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="chrome_latest"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Make Current</button></form><?php endif; ?>
              <?php if($published): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="chrome_unpublish"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Unpublish</button></form><?php endif; ?>
              <form method="post" onsubmit="return confirm('Delete this Chrome Extension release and stored ZIP package?');"><?= csrf_field() ?><input type="hidden" name="action" value="chrome_delete"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Delete</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$chromeReleases): ?><tr><td colspan="5">No managed Chrome Extension releases uploaded yet. The public endpoint is still using the repository Browser Companion package.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
</section>

<section id="homeserver-releases" style="margin-top:32px">
  <div class="admin-section-heading">
    <div>
      <span class="eyebrow">HomeServer</span>
      <h2>Windows HomeServer Releases</h2>
      <p>Upload verified Windows builds, publish release channels, and control which HomeServer version VP3 offers to connected users.</p>
    </div>
  </div>

  <div class="admin-grid admin-grid-2">
    <section class="admin-card">
      <div class="admin-card-head"><div><h3>HomeServer Release Service</h3><p>VP3-managed HomeServer distribution.</p></div></div>
      <div class="admin-table-wrap"><table class="admin-table"><tbody>
        <tr><td>Relay</td><td><?= $relayUrl !== '' ? '<strong>Configured</strong><br><small>'.e($relayUrl).'</small>' : '<strong>Not configured</strong><br><small>Set homeserver.relay_base_url or VP3_HOMESERVER_RELAY_URL.</small>' ?></td></tr>
        <tr><td>Current stable</td><td><?= $latest ? '<strong>v'.e((string)$latest['version']).'</strong>' : 'No published release' ?></td></tr>
        <tr><td>Storage</td><td><strong>Private</strong><br><small>Executable files are outside the public web surface and served only through the managed download endpoint.</small></td></tr>
        <tr><td>Validation</td><td><strong>Windows PE + SHA256</strong><br><small>Files must contain valid MZ and PE signatures and are never executed by VP3.</small></td></tr>
        <tr><td>PHP upload limits</td><td><strong><?= e($phpUploadMax) ?> per file</strong><br><small>POST limit <?= e($phpPostMax) ?>. Server limits must also allow the selected executable size.</small></td></tr>
      </tbody></table></div>
    </section>

    <section class="admin-card">
      <div class="admin-card-head"><div><h3>Upload HomeServer</h3><p>VP3 accepts up to 512 MB per executable; PHP/server limits may be lower.</p></div></div>
      <form method="post" enctype="multipart/form-data" class="admin-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-row">
          <label>Version<input name="version" maxlength="64" required placeholder="0.17.1" value="<?= e((string)($_POST['action'] ?? '') === 'create' ? (string)($_POST['version'] ?? '') : '') ?>"></label>
          <label>Channel<select name="channel"><option value="stable" <?= $selectedChannel==='stable'?'selected':'' ?>>Stable</option><option value="beta" <?= $selectedChannel==='beta'?'selected':'' ?>>Beta</option><option value="dev" <?= $selectedChannel==='dev'?'selected':'' ?>>Development</option></select></label>
        </div>
        <label>Portable EXE<input type="file" name="portable_exe" accept=".exe,application/vnd.microsoft.portable-executable"></label>
        <label>Installer EXE<input type="file" name="installer_exe" accept=".exe,application/vnd.microsoft.portable-executable"></label>
        <label>Release notes<textarea name="release_notes" rows="5" maxlength="10000"><?= e((string)($_POST['action'] ?? '') === 'create' ? (string)($_POST['release_notes'] ?? '') : '') ?></textarea></label>
        <div class="admin-check-grid">
          <label><input type="checkbox" name="is_published" value="1" <?= (string)($_POST['action'] ?? '') !== 'create' || !empty($_POST['is_published'])?'checked':'' ?>> Publish immediately</label>
          <label><input type="checkbox" name="is_latest" value="1" <?= (string)($_POST['action'] ?? '') !== 'create' || !empty($_POST['is_latest'])?'checked':'' ?>> Make current for channel</label>
        </div>
        <div class="form-actions"><button class="button primary" type="submit">Upload &amp; Verify HomeServer</button></div>
      </form>
    </section>
  </div>

  <section class="admin-card" style="margin-top:18px">
    <div class="admin-card-head"><div><h3>HomeServer History</h3><p><?= count($releases) ?> managed release<?= count($releases) === 1 ? '' : 's' ?></p></div></div>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Release</th><th>Portable</th><th>Installer</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($releases as $release): $id=(int)$release['id']; $published=(int)$release['is_published']===1; ?>
        <tr id="homeserver-release-<?= $id ?>">
          <td><strong>v<?= e((string)$release['version']) ?></strong><br><small><?= e((string)$release['channel']) ?> · <?= e((string)$release['created_at']) ?></small><?php if(trim((string)$release['release_notes'])!==''): ?><br><small><?= e(mb_strimwidth((string)$release['release_notes'],0,140,'…')) ?></small><?php endif; ?></td>
          <td><?php if((string)$release['portable_path']!==''): ?><?= $published?'<a href="'.e(url('/homeserver-download.php?id='.$id.'&type=portable')).'">Download</a>':'<strong>Stored draft</strong>' ?><br><small><?= number_format((int)$release['portable_size']) ?> bytes<br><?= e(substr((string)$release['portable_sha256'],0,16)) ?>…</small><?php else: ?>—<?php endif; ?></td>
          <td><?php if((string)$release['installer_path']!==''): ?><?= $published?'<a href="'.e(url('/homeserver-download.php?id='.$id.'&type=installer')).'">Download</a>':'<strong>Stored draft</strong>' ?><br><small><?= number_format((int)$release['installer_size']) ?> bytes<br><?= e(substr((string)$release['installer_sha256'],0,16)) ?>…</small><?php else: ?>—<?php endif; ?></td>
          <td><?= $published?'Published':'Draft' ?><?= (int)$release['is_latest']===1?' · Current':'' ?></td>
          <td>
            <div class="form-actions">
              <?php if(!$published): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Publish</button></form><?php endif; ?>
              <?php if((int)$release['is_latest']!==1): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="latest"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Make Current</button></form><?php endif; ?>
              <?php if($published): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unpublish"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Unpublish</button></form><?php endif; ?>
              <form method="post" onsubmit="return confirm('Delete this HomeServer release and both stored executable files?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Delete</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$releases): ?><tr><td colspan="5">No HomeServer releases uploaded yet.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
