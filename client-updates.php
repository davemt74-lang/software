<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');

$pdo = db();
$user = current_user();
if (!$pdo || !$user) {
    flash('error', 'Your client release status could not be loaded.');
    redirect(url('/login.php'));
}

try {
    $releaseState = client_release_intelligence_snapshot_v100($pdo, $user, true);
    $loadError = '';
} catch (Throwable $e) {
    $releaseState = [
        'browser_companion'=>['devices'=>[],'latest_release'=>null,'update_count'=>0,'update_available'=>false],
        'homeserver'=>['latest_release'=>null,'installed_version'=>'','update_available'=>false,'version_state'=>'unknown','paired'=>false],
        'update_count'=>0,'updates_available'=>false,
    ];
    $loadError = 'Client release status is temporarily unavailable.';
    error_log('Client Release Intelligence page failed: ' . $e->getMessage());
}

$browser = (array)($releaseState['browser_companion'] ?? []);
$home = (array)($releaseState['homeserver'] ?? []);
$browserLatest = is_array($browser['latest_release'] ?? null) ? $browser['latest_release'] : null;
$homeLatest = is_array($home['latest_release'] ?? null) ? $home['latest_release'] : null;
$browserDevices = (array)($browser['devices'] ?? []);

$memberHeaderUser=$user;
$memberHeaderTitle='Client Updates';
$memberHeaderSubtitle='Installed versions, release channels, and available VP3 client updates';
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

        <section class="client-release-hero">
          <div>
            <span>Client Release Intelligence v1.00</span>
            <h1><?= !empty($releaseState['updates_available']) ? 'Updates are available.' : 'Your VP3 clients are current.' ?></h1>
            <p>VP3 compares the versions reported by your connected Browser Companion and paired HomeServer against the current managed stable releases.</p>
          </div>
          <div class="client-release-hero-stat">
            <strong><?= (int)($releaseState['update_count'] ?? 0) ?></strong>
            <span>update<?= (int)($releaseState['update_count'] ?? 0)===1?'':'s' ?> available</span>
          </div>
        </section>

        <section class="client-release-card" id="browser-companion">
          <div class="client-release-card-head">
            <div><small>Browser Companion · <?= e((string)($browser['channel'] ?? 'stable')) ?> channel</small><h2>Chrome Extension</h2><p>Each connected browser reports its running extension version on authenticated VP3 requests.</p></div>
            <div class="client-release-latest"><span>Latest managed release</span><strong><?= $browserLatest ? 'v'.e((string)$browserLatest['version']) : 'Not published' ?></strong></div>
          </div>

          <?php if(!$browserDevices): ?>
            <div class="client-release-empty">No connected Browser Companion installations are registered. <a href="<?= e(url('/chrome-extension-download.php')) ?>">Download the extension</a>.</div>
          <?php else: ?>
            <div class="client-release-list">
              <?php foreach($browserDevices as $device):
                $state=(string)($device['version_state']??'unknown');
                $stateLabel=match($state){'current'=>'Current','update_available'=>'Update available','ahead'=>'Ahead of managed release','inactive'=>'Inactive',default=>'Version unknown'};
              ?>
                <article class="client-release-row" data-state="<?= e($state) ?>">
                  <div>
                    <strong><?= e((string)($device['device_name']??'Chrome Browser')) ?></strong>
                    <span><?= e((string)($device['browser_family']??'Chrome')) ?> · installed <?= ($device['installed_version']??'')!==''?'v'.e((string)$device['installed_version']):'unknown' ?></span>
                    <span>Last used <?= e((string)($device['last_used_at']??'Never')) ?></span>
                  </div>
                  <div class="client-release-row-actions">
                    <span class="client-release-badge"><?= e($stateLabel) ?></span>
                    <?php if(!empty($device['update_available'])): ?><a class="client-release-button primary" href="<?= e(url('/chrome-extension-download.php')) ?>">Download v<?= e((string)($browserLatest['version']??'')) ?></a><?php endif; ?>
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
          <div class="client-release-card-head">
            <div><small>HomeServer · <?= e((string)($home['channel'] ?? 'stable')) ?> channel</small><h2>Private HomeServer</h2><p>VP3 compares the last reported HomeServer build with the current managed stable release.</p></div>
            <div class="client-release-latest"><span>Latest managed release</span><strong><?= $homeLatest ? 'v'.e((string)$homeLatest['version']) : 'Not published' ?></strong></div>
          </div>
          <article class="client-release-row" data-state="<?= e((string)($home['version_state']??'unknown')) ?>">
            <div>
              <strong><?= !empty($home['paired']) ? 'Paired HomeServer' : 'No HomeServer paired' ?></strong>
              <span>Installed <?= ($home['installed_version']??'')!==''?'v'.e((string)$home['installed_version']):'unknown' ?></span>
              <span>Connection <?= e((string)($home['connection_state']??'unpaired')) ?><?= !empty($home['last_seen_at'])?' · last seen '.e((string)$home['last_seen_at']):'' ?></span>
            </div>
            <div class="client-release-row-actions">
              <?php if(!empty($home['update_available'])): ?>
                <span class="client-release-badge">Update available</span>
                <?php $homeFile=$homeLatest['installer']??$homeLatest['portable']??null; if(is_array($homeFile)&&!empty($homeFile['url'])): ?><a class="client-release-button primary" href="<?= e((string)$homeFile['url']) ?>">Download v<?= e((string)$homeLatest['version']) ?></a><?php endif; ?>
              <?php elseif(!empty($home['paired'])):
                $homeState=(string)($home['version_state']??'unknown');
                $homeLabel=match($homeState){'current'=>'Current','ahead'=>'Ahead of stable','unavailable'=>'No managed release',default=>'Version unknown'};
              ?><span class="client-release-badge"><?= e($homeLabel) ?></span><?php else: ?><a class="client-release-button" href="<?= e(url('/settings-homeserver.php')) ?>">Connect HomeServer</a><?php endif; ?>
            </div>
          </article>
          <div class="client-release-footer">
            <a href="<?= e(url('/settings-homeserver.php')) ?>">Manage HomeServer</a>
            <span>Updates stay reviewable; VP3 never installs a HomeServer build automatically.</span>
          </div>
        </section>

        <section class="client-release-note">
          <strong>How notifications work</strong>
          <p>When a newer managed stable release applies to one of your connected clients, VP3 creates one deduplicated update notification for that release. It can appear in Agent Now and Browser Companion notifications, and it can be spoken when Agent Voice is enabled. The notification resolves automatically after the installed version catches up.</p>
        </section>
      </div>
    </section>
  </main>
</div>
</body>
</html>
