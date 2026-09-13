<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/agent-workflow-runs-v1400.php';
require_permission('users.manage');
$pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
$error='';$ready=agent_workflow_schema_ready_v1400($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){$error='Session expired. Please try again.';}
    else{
        try{agent_workflow_ensure_schema_v1400($pdo);$ready=agent_workflow_schema_ready_v1400($pdo);if($ready)redirect(url('/agent-workflows.php?notice='.rawurlencode('Agent Workflow schema installed.')));}
        catch(Throwable $e){$error=$e->getMessage();}
    }
}
require_once __DIR__ . '/includes/vp3-public.php';
vp3_public_header('Agent Workflow Upgrade — VP3','Install the Phase 14 Agent Workflow run ledger.',['compact'=>true]);
?>
<main class="vp3-auth-shell"><section class="vp3-auth-visual"><div class="vp3-auth-visual-content"><div class="vp3-kicker">Phase 14</div><h1>Install Agent Workflow Runs.</h1><p>This adds the durable run, action and execution-history tables. Existing Calendar, Scheduling, Commerce, HomeServer and Agent data remain unchanged.</p></div></section><section class="vp3-auth-form-side"><div class="vp3-auth-card"><div class="vp3-kicker">Database</div><h1>Agent Workflow Upgrade</h1><?php if($ready): ?><div class="vp3-alert success">Agent Workflow Runs v14.00 is installed.</div><a class="vp3-btn primary" href="<?= e(url('/agent-workflows.php')) ?>">Open Agent Workflows →</a><?php else: ?><p class="vp3-auth-intro">Create the owner-scoped workflow run ledger and execution history tables.</p><?php if($error!==''): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><button class="vp3-btn primary" type="submit">Install Phase 14 →</button></form><?php endif; ?></div></section></main>
<?php vp3_public_footer(); ?>
