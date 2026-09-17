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
      <?php if($error): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if($notice): ?><div class="vp3-alert success" role="status"><?= e($notice) ?></div><?php endif; ?>

      <?php if(!$devices): ?>
        <p class="vp3-auth-intro">No browser connections are registered for this account.</p>
      <?php else: ?>
        <div style="display:grid;gap:1rem;margin-top:1.25rem;">
          <?php foreach($devices as $device): ?>
            <article style="border:1px solid rgba(127,127,127,.25);border-radius:14px;padding:1rem;">
              <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                <div>
                  <strong><?= e((string)$device['device_name']) ?></strong>
                  <div style="opacity:.75;margin-top:.25rem;"><?= e((string)$device['browser_family']) ?> · Extension <?= e((string)$device['extension_version'] ?: 'unknown') ?></div>
                  <div style="opacity:.75;margin-top:.25rem;">Status: <?= e(ucfirst((string)$device['device_status'])) ?> · Last used: <?= e((string)($device['last_used_at']?:'Never')) ?></div>
                </div>
                <?php if((string)$device['device_status']==='active'): ?>
                  <form method="post" onsubmit="return confirm('Revoke this browser connection?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="device_id" value="<?= e((string)$device['public_id']) ?>">
                    <button class="vp3-btn" type="submit">Revoke</button>
                  </form>
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
