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
$adminUser=current_user();
$adminUserId=(int)($adminUser['id']??0);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Session expired. Please try again.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');

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
                chrome_extension_release_set_state($releaseId, $stateAction);
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
                homeserver_vp3_set_release_state($releaseId, $action);
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
