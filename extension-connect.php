<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/vp3-public.php';

require_login();
$pdo=db();
$user=current_user();
$error='';
$notice='';

$id=trim((string)($_POST['connection_request_id']??$_GET['id']??''));
$approvalToken=trim((string)($_POST['approval_token']??$_GET['approval_token']??''));
$request=null;

if(!$pdo||!vp3_extension_schema_ready_v2000($pdo)){
    $error='VP3 Browser Companion is not ready. Ask an administrator to run the database upgrade.';
}elseif($id===''||$approvalToken===''){
    $error='This Browser Companion connection link is incomplete.';
}else{
    $request=vp3_extension_connection_for_approval_v2000($pdo,$id,$approvalToken);
    if(!$request)$error='This Browser Companion connection request is not available.';
}

if($_SERVER['REQUEST_METHOD']==='POST'&&$request&&$user){
    if(!verify_csrf()){
        $error='Session expired. Please try again.';
    }else{
        $decision=(string)($_POST['decision']??'');
        try{
            $result=vp3_extension_connection_decide_v2000($pdo,$id,$approvalToken,(int)$user['id'],$decision);
            $status=(string)($result['status']??'');
            if($status==='approved')$notice='Browser Companion approved. Return to the extension to finish connecting.';
            elseif($status==='denied')$notice='Browser Companion connection denied.';
            elseif($status==='expired')$error='This Browser Companion connection request has expired. Start a new connection from the extension.';
            else $notice='This Browser Companion request has already been resolved.';
            $request=vp3_extension_connection_for_approval_v2000($pdo,$id,$approvalToken);
        }catch(Throwable $e){
            $error=$e->getMessage();
        }
    }
}

$status=(string)($request['request_status']??'');
$requested=$request?vp3_extension_json_array_v2000($request['requested_capabilities_json']??'[]'):[];
$capabilityLabels=[
    'team.destinations.read'=>'See the Team conversations you can share to',
    'team.share.create'=>'Share browser content with your Team',
    'team.chat.read'=>'Open authorized Team conversations',
    'agent.message'=>'Ask your VP3 Agent about browser context',
    'knowledge.write'=>'Save browser content to Knowledge',
    'task.propose'=>'Propose tasks from browser content',
    'notifications.read'=>'Receive VP3 extension notifications',
    'browser.asset.upload'=>'Upload browser-captured assets',
];

vp3_public_header('Connect Browser Companion — VP3','Approve a browser connection to your VP3 account.',['compact'=>true]);
?>
<main class="vp3-auth-shell">
  <section class="vp3-auth-visual">
    <div class="vp3-auth-visual-content">
      <div class="vp3-kicker">VP3 Browser Companion</div>
      <h1>Bring VP3 with you across the web.</h1>
      <p>Approve this browser only if you started the connection from the VP3 extension. The extension never receives your VP3 password.</p>
    </div>
  </section>
  <section class="vp3-auth-form-side">
    <div class="vp3-auth-card">
      <div class="vp3-kicker">Browser connection</div>
      <h1>Connect this browser</h1>

      <?php if($error): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if($notice): ?><div class="vp3-alert success" role="status"><?= e($notice) ?></div><?php endif; ?>

      <?php if($request): ?>
        <dl style="display:grid;grid-template-columns:auto 1fr;gap:.6rem 1rem;margin:1.25rem 0;">
          <dt>Device</dt><dd><?= e((string)$request['device_name']) ?></dd>
          <dt>Browser</dt><dd><?= e((string)$request['browser_family']) ?></dd>
          <dt>Extension</dt><dd><?= e((string)$request['extension_version'] ?: 'Unknown version') ?></dd>
          <dt>Status</dt><dd><?= e(ucfirst((string)$request['request_status'])) ?></dd>
        </dl>

        <?php if($requested): ?>
          <h2 style="font-size:1rem;margin-top:1.4rem;">Requested access</h2>
          <ul style="padding-left:1.2rem;">
            <?php foreach($requested as $capability): ?>
              <li><?= e($capabilityLabels[$capability]??$capability) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if($status==='pending'): ?>
          <form method="post" action="<?= e(url('/extension-connect.php')) ?>" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="connection_request_id" value="<?= e($id) ?>">
            <input type="hidden" name="approval_token" value="<?= e($approvalToken) ?>">
            <button class="vp3-btn primary" type="submit" name="decision" value="approve">Approve Browser →</button>
            <button class="vp3-btn" type="submit" name="decision" value="deny">Deny</button>
          </form>
        <?php elseif($status==='approved'): ?>
          <p class="vp3-auth-intro">This browser has been approved. Return to the extension to complete connection.</p>
        <?php elseif($status==='denied'): ?>
          <p class="vp3-auth-intro">This connection was denied. You can close this page.</p>
        <?php elseif($status==='expired'): ?>
          <p class="vp3-auth-intro">This connection request expired. Start a new connection from the extension.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php vp3_public_footer(); ?>
