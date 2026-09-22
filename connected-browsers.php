<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/vp3-public.php';

require_login();
$user=current_user();
$pdo=db();
$error='';
$notice=flash('notice')??'';

if(!$pdo||!vp3_extension_schema_ready_v2000($pdo)){
    $error='VP3 Browser Companion is not ready. Ask an administrator to run the database upgrade.';
}elseif($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){
        $error='Session expired. Please try again.';
    }else{
        $deviceId=trim((string)($_POST['device_id']??''));
        try{
            if(vp3_extension_device_revoke_v2000($pdo,(int)$user['id'],$deviceId)){
                flash('notice','Browser connection revoked. Its active extension sessions were ended immediately.');
                redirect(url('/connected-browsers.php'));
            }
            $error='That active browser connection was not found.';
        }catch(Throwable $e){
            $error='Browser connection could not be revoked.';
            error_log('VP3 browser revoke failed: '.$e->getMessage());
        }
    }
}

$devices=[];
if(!$error&&$pdo&&vp3_extension_schema_ready_v2000($pdo)){
    try{$devices=vp3_extension_devices_for_user_v2000($pdo,(int)$user['id']);}
    catch(Throwable $e){$error='Connected browsers could not be loaded.';}
}

$browserRelease=['latest_release'=>null,'devices'=>[],'update_count'=>0];
$releaseByDevice=[];
if($pdo&&function_exists('client_release_browser_snapshot_v100')){
    try{
        $browserRelease=client_release_browser_snapshot_v100($pdo,(int)$user['id']);
        foreach((array)($browserRelease['devices']??[]) as $row)$releaseByDevice[(string)($row['device_id']??'')]=$row;
        client_release_intelligence_reconcile_user_v100($pdo,(int)$user['id'],$browserRelease,null);
    }catch(Throwable $e){}
}

vp3_public_header('Connected Browsers — VP3','Manage browsers authorized to connect to your VP3 account.',['compact'=>true]);
?>
<main class="vp3-auth-shell">
  <section class="vp3-auth-visual">
    <div class="vp3-auth-visual-content">
      <div class="vp3-kicker">Account security</div>
      <h1>Connected Browsers</h1>
      <p>Review and revoke Chrome installations that can request VP3 Browser Companion sessions. Revoking a browser immediately ends its active sessions.</p>
    </div>
  </section>
  <section class="vp3-auth-form-side">
    <div class="vp3-auth-card" style="max-width:760px;">
      <div class="vp3-kicker">Browser Companion</div>
      <h1>Your connected browsers</h1>
      <p class="vp3-auth-intro">Current stable: <strong><?= !empty($browserRelease['latest_release']['version'])?'v'.e((string)$browserRelease['latest_release']['version']):'not published' ?></strong><?php if(!empty($browserRelease['update_count'])): ?> · <?= (int)$browserRelease['update_count'] ?> update<?= (int)$browserRelease['update_count']===1?'':'s' ?> available<?php endif; ?> · <a href="<?= e(url('/client-updates.php#browser-companion')) ?>">Client Updates</a></p>
      <?php if($error): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if($notice): ?><div class="vp3-alert success" role="status"><?= e($notice) ?></div><?php endif; ?>

      <?php if(!$devices): ?>
        <p class="vp3-auth-intro">No browser connections are registered for this account.</p>
      <?php else: ?>
        <div style="display:grid;gap:1rem;margin-top:1.25rem;">
          <?php foreach($devices as $device): $releaseDevice=$releaseByDevice[(string)$device['public_id']]??null; ?>
            <article style="border:1px solid rgba(127,127,127,.25);border-radius:14px;padding:1rem;">
              <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                <div>
                  <strong><?= e((string)$device['device_name']) ?></strong>
                  <div style="opacity:.75;margin-top:.25rem;"><?= e((string)$device['browser_family']) ?> · Extension <?= e((string)$device['extension_version'] ?: 'unknown') ?><?php if(is_array($releaseDevice)): ?> · <?= e(match((string)($releaseDevice['version_state']??'unknown')){'current'=>'Current','update_available'=>'Update available','ahead'=>'Ahead of stable','inactive'=>'Inactive',default=>'Version unknown'}) ?><?php endif; ?></div>
                  <div style="opacity:.75;margin-top:.25rem;">Status: <?= e(ucfirst((string)$device['device_status'])) ?> · Last used: <?= e((string)($device['last_used_at']?:'Never')) ?></div>
                </div>
                <?php if((string)$device['device_status']==='active'): ?>
                  <form method="post" onsubmit="return confirm('Revoke this browser connection?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="device_id" value="<?= e((string)$device['public_id']) ?>">
                    <button class="vp3-btn" type="submit">Revoke</button>
                  </form>
                  <?php if(is_array($releaseDevice)&&!empty($releaseDevice['update_available'])): ?><a class="vp3-btn primary" href="<?= e(url('/chrome-extension-download.php')) ?>">Download update</a><?php endif; ?>
                <?php endif; ?>
              </div>
              <?php if(!empty($device['capabilities'])): ?>
                <div style="margin-top:.75rem;font-size:.9rem;opacity:.8;">Access: <?= e(implode(', ',(array)$device['capabilities'])) ?></div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php vp3_public_footer(); ?>
