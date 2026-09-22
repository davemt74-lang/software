<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');

$pdo=db();
$user=current_user();
if(!$pdo||!$user){
    flash('error','Your client release status could not be loaded.');
    redirect(url('/login.php'));
}
$userId=(int)$user['id'];
$rolloutsReady=client_release_rollouts_schema_ready_v110($pdo);

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){
        flash('error','Session expired. Please try again.');
        redirect(url('/client-updates.php'));
    }
    try{
        if(!$rolloutsReady)throw new RuntimeException('Client release controls are waiting for the VP3 database upgrade.');
        $action=(string)($_POST['action']??'');
        $product=(string)($_POST['product']??'');
        $scope=client_release_scope_key_v110((string)($_POST['scope']??'account'));
        $anchor=$product==='browser_companion'?'#browser-companion':'#homeserver';

        if($action==='set_release_channel'){
            client_release_set_channel_v110($pdo,$userId,$product,$scope,(string)($_POST['channel']??'stable'),$userId);
            client_release_intelligence_reconcile_user_v110($pdo,$userId);
            flash('notice','Client release channel updated.');
            redirect(url('/client-updates.php'.$anchor));
        }

        if(in_array($action,['defer_release','resume_release'],true)){
            $releaseId=max(0,(int)($_POST['release_id']??0));
            if(!client_release_scope_authorized_v110($pdo,$userId,$product,$scope))throw new RuntimeException('That client is not connected to this account.');
            $channel=client_release_channel_for_v110($pdo,$userId,$product,$scope);
            $target=client_release_applicable_release_v110($pdo,$product,$channel,$userId,$scope);
            if(!$target||(int)$target['id']!==$releaseId)throw new RuntimeException('That release is no longer assigned to this client.');
            if($action==='defer_release'){
                client_release_defer_v110($pdo,$userId,$product,$scope,$releaseId,(int)($_POST['days']??7));
                flash('notice','Update reminder deferred.');
            }else{
                client_release_resume_v110($pdo,$userId,$product,$scope,$releaseId);
                flash('notice','Update reminder resumed.');
            }
            client_release_intelligence_reconcile_user_v110($pdo,$userId);
            redirect(url('/client-updates.php'.$anchor));
        }
        throw new RuntimeException('Unsupported client release action.');
    }catch(Throwable $e){
        flash('error',$e->getMessage());
        redirect(url('/client-updates.php'));
    }
}

try{
    $releaseState=$rolloutsReady
        ?client_release_intelligence_snapshot_v110($pdo,$user,true)
        :client_release_intelligence_snapshot_v100($pdo,$user,true);
    $loadError='';
}catch(Throwable $e){
    $releaseState=[
        'browser_companion'=>['devices'=>[],'public_release'=>null,'latest_release'=>null,'update_count'=>0,'update_available'=>false],
        'homeserver'=>['target_release'=>null,'latest_release'=>null,'installed_version'=>'','update_available'=>false,'version_state'=>'unknown','paired'=>false],
        'update_count'=>0,'updates_available'=>false,
    ];
    $loadError='Client release status is temporarily unavailable.';
    error_log('Client Release Operations page failed: '.$e->getMessage());
}

$browser=(array)($releaseState['browser_companion']??[]);
$home=(array)($releaseState['homeserver']??[]);
$browserPublic=is_array($browser['public_release']??null)?$browser['public_release']:(is_array($browser['latest_release']??null)?$browser['latest_release']:null);
$homeTarget=is_array($home['target_release']??null)?$home['target_release']:(is_array($home['latest_release']??null)?$home['latest_release']:null);
$browserDevices=(array)($browser['devices']??[]);

$lifecycleLabel=static function(string $state): string {
    return match($state){
        'canary'=>'Canary',
        'limited'=>'Limited rollout',
        'general_availability'=>'General availability',
        'testing'=>'Testing',
        'paused'=>'Paused',
        'superseded'=>'Superseded',
        'withdrawn'=>'Withdrawn',
        'draft'=>'Draft',
        default=>'Not assigned',
    };
};

$memberHeaderUser=$user;
$memberHeaderTitle='Client Updates';
$memberHeaderSubtitle='Controlled channels, staged rollouts, installed versions, and available VP3 client updates';
$memberHeaderActions='<a class="client-release-button" href="'.e(url('/connected-browsers.php')).'">Connected Browsers</a><a class="client-release-button" href="'.e(url('/settings-homeserver.php')).'">HomeServer</a>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(system_agent_name()) ?> | Client Updates</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/client-updates-v100.css?v=20260922')) ?>">
<link rel="stylesheet" href="<?= e(url('/client-updates-v110.css?v=20260922')) ?>">
</head>
<body>
<div class="chat-app">
  <?php $workspaceSidebarUser=$user;$workspaceSidebarActive='client_updates';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
  <main class="chat-main client-release-main">
    <?php require __DIR__.'/includes/member-header.php'; ?>
    <section class="client-release-canvas">
      <div class="client-release-wrap">
        <?php if($loadError): ?><div class="client-release-alert"><?= e($loadError) ?></div><?php endif; ?>
        <?php if(!$rolloutsReady): ?><div class="client-release-alert">Controlled rollout controls will appear after an administrator runs the VP3 database upgrade.</div><?php endif; ?>

        <section class="client-release-hero">
          <div>
            <span>Client Release Operations · Controlled Rollouts v1.10</span>
            <h1><?= !empty($releaseState['updates_available'])?'Updates are available.':'Your assigned VP3 clients are current.' ?></h1>
            <p>VP3 now applies release channels and deterministic staged rollout cohorts before offering an update. Canary and limited releases stay scoped to eligible clients; public downloads remain General Availability only.</p>
          </div>
          <div class="client-release-hero-stat">
            <strong><?= (int)($releaseState['update_count']??0) ?></strong>
            <span>assigned update<?= (int)($releaseState['update_count']??0)===1?'':'s' ?></span>
          </div>
        </section>

        <section class="client-release-card" id="browser-companion">
          <div class="client-release-card-head">
            <div><small>Browser Companion · device-level channels</small><h2>Chrome Extension</h2><p>Each connected browser can stay on Stable or opt into Beta/Development. Rollout cohort assignment is deterministic per device and release.</p></div>
            <div class="client-release-latest"><span>Public stable</span><strong><?= $browserPublic?'v'.e((string)$browserPublic['version']):'Not published' ?></strong></div>
          </div>

          <?php if(!$browserDevices): ?>
            <div class="client-release-empty">No connected Browser Companion installations are registered. <a href="<?= e(url('/chrome-extension-download.php')) ?>">Download the extension</a>.</div>
          <?php else: ?>
            <div class="client-release-list">
              <?php foreach($browserDevices as $device):
                $state=(string)($device['version_state']??'unknown');
                $stateLabel=match($state){'current'=>'Current','update_available'=>'Update available','ahead'=>'Ahead of assigned release','inactive'=>'Inactive','unavailable'=>'No eligible release',default=>'Version unknown'};
                $targetId=(int)($device['target_release_id']??0);
                $scope=(string)($device['device_id']??'');
                $channel=(string)($device['channel']??'stable');
              ?>
                <article class="client-release-row" data-state="<?= e($state) ?>">
                  <div>
                    <strong><?= e((string)($device['device_name']??'Chrome Browser')) ?></strong>
                    <span><?= e((string)($device['browser_family']??'Chrome')) ?> · installed <?= ($device['installed_version']??'')!==''?'v'.e((string)$device['installed_version']):'unknown' ?><?= ($device['target_version']??'')!==''?' · assigned v'.e((string)$device['target_version']):'' ?></span>
                    <span>Last used <?= e((string)($device['last_used_at']??'Never')) ?></span>
                    <div class="client-release-meta">
                      <span><?= e(ucfirst($channel)) ?> channel</span>
                      <span><?= e($lifecycleLabel((string)($device['lifecycle_state']??''))) ?></span>
                      <?php if((int)($device['rollout_percent']??0)>0): ?><span><?= (int)$device['rollout_percent'] ?>% rollout · cohort <?= (int)($device['cohort_bucket']??0) ?></span><?php endif; ?>
                      <span>Status: <?= e(ucfirst((string)($device['update_state']??'unknown'))) ?></span>
                    </div>
                    <?php if(!empty($device['summary'])||!empty($device['known_issues'])||!empty($device['compatibility_notes'])): ?><div class="client-release-detail">
                      <?php if(!empty($device['summary'])): ?><strong>Release summary</strong><?= e((string)$device['summary']) ?><?php endif; ?>
                      <?php if(!empty($device['known_issues'])): ?><strong>Known issues</strong><?= nl2br(e((string)$device['known_issues'])) ?><?php endif; ?>
                      <?php if(!empty($device['compatibility_notes'])): ?><strong>Compatibility</strong><?= e((string)$device['compatibility_notes']) ?><?php endif; ?>
                    </div><?php endif; ?>
                    <?php if($rolloutsReady): ?><div class="client-release-controls">
                      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_release_channel"><input type="hidden" name="product" value="browser_companion"><input type="hidden" name="scope" value="<?= e($scope) ?>"><label>Channel <select name="channel" onchange="this.form.submit()"><?php foreach(['stable'=>'Stable','beta'=>'Beta','dev'=>'Development'] as $key=>$label): ?><option value="<?= $key ?>" <?= $channel===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label></form>
                    </div><?php endif; ?>
                  </div>
                  <div class="client-release-row-actions">
                    <span class="client-release-badge"><?= e($stateLabel) ?></span>
                    <?php if(!empty($device['deferred'])): ?>
                      <span>Deferred until <?= e((string)$device['defer_until']) ?></span>
                      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resume_release"><input type="hidden" name="product" value="browser_companion"><input type="hidden" name="scope" value="<?= e($scope) ?>"><input type="hidden" name="release_id" value="<?= $targetId ?>"><button class="client-release-button" type="submit">Resume reminders</button></form>
                    <?php elseif(!empty($device['update_available'])): ?>
                      <a class="client-release-button primary" href="<?= e((string)$device['download_url']) ?>">Download v<?= e((string)$device['target_version']) ?></a>
                      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="defer_release"><input type="hidden" name="product" value="browser_companion"><input type="hidden" name="scope" value="<?= e($scope) ?>"><input type="hidden" name="release_id" value="<?= $targetId ?>"><input type="hidden" name="days" value="7"><button class="client-release-button" type="submit">Remind me in 7 days</button></form>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="client-release-footer">
            <a href="<?= e(url('/connected-browsers.php')) ?>">Manage connected browsers</a>
            <a href="<?= e(url('/chrome-extension.php')) ?>">Browser Companion details</a>
          </div>
        </section>

        <section class="client-release-card" id="homeserver">
          <?php $homeChannel=(string)($home['channel']??'stable');$homeTargetId=(int)($home['target_release_id']??0); ?>
          <div class="client-release-card-head">
            <div><small>HomeServer · <?= e($homeChannel) ?> channel</small><h2>Private HomeServer</h2><p>Your HomeServer follows an account-level channel and only receives releases assigned to its deterministic rollout cohort.</p></div>
            <div class="client-release-latest"><span>Assigned release</span><strong><?= $homeTarget?'v'.e((string)$homeTarget['version']):'Not assigned' ?></strong></div>
          </div>
          <article class="client-release-row" data-state="<?= e((string)($home['version_state']??'unknown')) ?>">
            <div>
              <strong><?= !empty($home['paired'])?'Paired HomeServer':'No HomeServer paired' ?></strong>
              <span>Installed <?= ($home['installed_version']??'')!==''?'v'.e((string)$home['installed_version']):'unknown' ?><?= ($home['target_version']??'')!==''?' · assigned v'.e((string)$home['target_version']):'' ?></span>
              <span>Connection <?= e((string)($home['connection_state']??'unpaired')) ?><?= !empty($home['last_seen_at'])?' · last seen '.e((string)$home['last_seen_at']):'' ?></span>
              <?php if(!empty($home['paired'])): ?><div class="client-release-meta">
                <span><?= e(ucfirst($homeChannel)) ?> channel</span>
                <span><?= e($lifecycleLabel((string)($home['lifecycle_state']??''))) ?></span>
                <?php if((int)($home['rollout_percent']??0)>0): ?><span><?= (int)$home['rollout_percent'] ?>% rollout · cohort <?= (int)($home['cohort_bucket']??0) ?></span><?php endif; ?>
                <span>Status: <?= e(ucfirst((string)($home['update_state']??'unknown'))) ?></span>
              </div><?php endif; ?>
              <?php if(!empty($home['summary'])||!empty($home['known_issues'])||!empty($home['compatibility_notes'])): ?><div class="client-release-detail">
                <?php if(!empty($home['summary'])): ?><strong>Release summary</strong><?= e((string)$home['summary']) ?><?php endif; ?>
                <?php if(!empty($home['known_issues'])): ?><strong>Known issues</strong><?= nl2br(e((string)$home['known_issues'])) ?><?php endif; ?>
                <?php if(!empty($home['compatibility_notes'])): ?><strong>Compatibility</strong><?= e((string)$home['compatibility_notes']) ?><?php endif; ?>
              </div><?php endif; ?>
              <?php if($rolloutsReady&&!empty($home['paired'])): ?><div class="client-release-controls"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_release_channel"><input type="hidden" name="product" value="homeserver"><input type="hidden" name="scope" value="account"><label>Channel <select name="channel" onchange="this.form.submit()"><?php foreach(['stable'=>'Stable','beta'=>'Beta','dev'=>'Development'] as $key=>$label): ?><option value="<?= $key ?>" <?= $homeChannel===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label></form></div><?php endif; ?>
            </div>
            <div class="client-release-row-actions">
              <?php if(!empty($home['deferred'])): ?>
                <span class="client-release-badge">Deferred</span><span>Until <?= e((string)$home['defer_until']) ?></span>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resume_release"><input type="hidden" name="product" value="homeserver"><input type="hidden" name="scope" value="account"><input type="hidden" name="release_id" value="<?= $homeTargetId ?>"><button class="client-release-button" type="submit">Resume reminders</button></form>
              <?php elseif(!empty($home['update_available'])): ?>
                <span class="client-release-badge">Update available</span>
                <?php $homeFile=$homeTarget['installer']??$homeTarget['portable']??null; if(is_array($homeFile)&&!empty($homeFile['url'])): ?><a class="client-release-button primary" href="<?= e((string)$homeFile['url']) ?>">Download v<?= e((string)$homeTarget['version']) ?></a><?php endif; ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="defer_release"><input type="hidden" name="product" value="homeserver"><input type="hidden" name="scope" value="account"><input type="hidden" name="release_id" value="<?= $homeTargetId ?>"><input type="hidden" name="days" value="7"><button class="client-release-button" type="submit">Remind me in 7 days</button></form>
              <?php elseif(!empty($home['paired'])):
                $homeState=(string)($home['version_state']??'unknown');
                $homeLabel=match($homeState){'current'=>'Current','ahead'=>'Ahead of assigned release','unavailable'=>'No eligible release',default=>'Version unknown'};
              ?><span class="client-release-badge"><?= e($homeLabel) ?></span><?php else: ?><a class="client-release-button" href="<?= e(url('/settings-homeserver.php')) ?>">Connect HomeServer</a><?php endif; ?>
            </div>
          </article>
          <div class="client-release-footer">
            <a href="<?= e(url('/settings-homeserver.php')) ?>">Manage HomeServer</a>
            <span>HomeServer updates remain reviewable and owner-controlled; VP3 never installs them automatically.</span>
          </div>
        </section>

        <section class="client-release-note">
          <strong>How controlled rollouts work</strong>
          <p>Draft and Testing releases stay internal. Canary and Limited rollout releases are assigned deterministically, so a client remains in the same cohort as rollout percentages widen. General Availability becomes the public release. Pausing or withdrawing a release immediately stops new assignment; promoting an older release back to General Availability provides a controlled rollback.</p>
          <p>Deferred updates remain visible here but stop generating attention notifications until the defer window expires. Agent Now and Browser Companion notifications use the same assigned release state.</p>
        </section>
        <!-- controlled route: client-release-download.php -->
      </div>
    </section>
  </main>
</div>
</body>
</html>
