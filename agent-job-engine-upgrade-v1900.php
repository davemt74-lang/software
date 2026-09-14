<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-job-engine-v1900.php';
require_permission('users.manage');
$pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
$error='';$ready=agent_job_engine_schema_ready_v1900($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else{
        try{agent_job_engine_ensure_schema_v1900($pdo);$ready=agent_job_engine_schema_ready_v1900($pdo);if($ready)redirect(url('/agent-workflows.php?notice='.rawurlencode('Durable Agent Job Engine installed.')));}
        catch(Throwable $e){$error=$e->getMessage();}
    }
}
require_once __DIR__.'/includes/vp3-public.php';
vp3_public_header('Durable Agent Job Engine — VP3','Install the Phase 19 durable execution extension.',['compact'=>true]);
?>
<main class="vp3-auth-shell"><section class="vp3-auth-visual"><div class="vp3-auth-visual-content"><div class="vp3-kicker">Phase 19.0</div><h1>Install Durable Agent Jobs.</h1><p>This extends the existing Agent Workflow ledger with leases, retries, dependencies, progress, recovery and idempotent execution receipts. Agent Brain prioritization remains authoritative.</p></div></section><section class="vp3-auth-form-side"><div class="vp3-auth-card"><div class="vp3-kicker">Database</div><h1>Durable Job Engine</h1><?php if($ready): ?><div class="vp3-alert success">Durable Agent Job Engine v19.0 is installed.</div><a class="vp3-btn primary" href="<?= e(url('/agent-workflows.php')) ?>">Open Agent Workflows →</a><?php else: ?><p class="vp3-auth-intro">Upgrade the Phase 14 workflow ledger without replacing existing workflows or Agent Brain state.</p><?php if($error!==''): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><button class="vp3-btn primary" type="submit">Install Phase 19.0 →</button></form><?php endif; ?></div></section></main>
<?php vp3_public_footer(); ?>
