<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-work-control-v173.php';
require_permission('users.manage');
$pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
$error='';$ready=agent_work_control_schema_ready_v173($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else{
        try{agent_work_control_ensure_schema_v173($pdo);$ready=agent_work_control_schema_ready_v173($pdo);if($ready)redirect(url('/agent-workflows.php?notice='.rawurlencode('Agent Work Control v17.3 installed.')));}
        catch(Throwable $e){$error=$e->getMessage();}
    }
}
require_once __DIR__.'/includes/vp3-public.php';
vp3_public_header('Agent Work Control — VP3','Install the Phase 17.3 durable work-control extension.',['compact'=>true]);
?>
<main class="vp3-auth-shell"><section class="vp3-auth-visual"><div class="vp3-auth-visual-content"><div class="vp3-kicker">Phase 17.3</div><h1>Control Agent work from Chat.</h1><p>This extends the existing durable workflow ledger with pause/resume state and queue priority. Scheduling continues to use the Phase 19 durable job engine, and approval/permission boundaries remain authoritative.</p></div></section><section class="vp3-auth-form-side"><div class="vp3-auth-card"><div class="vp3-kicker">Database</div><h1>Agent Work Control</h1><?php if($ready): ?><div class="vp3-alert success">Agent Work Control v17.3 is installed.</div><a class="vp3-btn primary" href="<?= e(url('/chat.php')) ?>">Open Agent Chat →</a><?php else: ?><p class="vp3-auth-intro">Add owner-scoped work priority, pause requests and durable paused state without replacing existing workflows, retries, receipts or approvals.</p><?php if($error!==''): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><button class="vp3-btn primary" type="submit">Install Phase 17.3 →</button></form><?php endif; ?></div></section></main>
<?php vp3_public_footer(); ?>
