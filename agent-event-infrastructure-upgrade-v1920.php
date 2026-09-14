<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-event-infrastructure-v1920.php';
require_permission('users.manage');
$pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
$error='';$ready=agent_event_schema_ready_v1920($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else{
        try{agent_event_ensure_schema_v1920($pdo);$ready=agent_event_schema_ready_v1920($pdo);if($ready)redirect(url('/agent-events.php?notice='.rawurlencode('Webhook and Event Infrastructure installed.')));}
        catch(Throwable $e){$error=$e->getMessage();}
    }
}
require_once __DIR__.'/includes/vp3-public.php';
vp3_public_header('Webhook + Event Infrastructure — VP3','Install the Phase 19.2 durable event inbox.',['compact'=>true]);
?>
<main class="vp3-auth-shell"><section class="vp3-auth-visual"><div class="vp3-auth-visual-content"><div class="vp3-kicker">Phase 19.2</div><h1>Install Durable Events.</h1><p>Adds verified webhook and trusted internal event intake with idempotency, redaction, replay and Agent Brain routing. Phase 19 remains the only execution queue.</p></div></section><section class="vp3-auth-form-side"><div class="vp3-auth-card"><div class="vp3-kicker">Database</div><h1>Event Infrastructure</h1><?php if($ready): ?><div class="vp3-alert success">Webhook + Event Infrastructure v19.2 is installed.</div><a class="vp3-btn primary" href="<?= e(url('/agent-events.php')) ?>">Open Agent Events →</a><?php else: ?><p class="vp3-auth-intro">Create the sanitized durable event inbox without changing the Agent Brain or job-engine authority boundaries.</p><?php if($error!==''): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><button class="vp3-btn primary" type="submit">Install Phase 19.2 →</button></form><?php endif; ?></div></section></main>
<?php vp3_public_footer(); ?>
