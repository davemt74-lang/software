<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/agent-work-dependencies-v174.php';
require_permission('users.manage');
$pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
$error='';$ready=agent_work_dependencies_schema_ready_v174($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else{
        try{agent_work_dependencies_ensure_schema_v174($pdo);$ready=agent_work_dependencies_schema_ready_v174($pdo);if($ready)redirect(url('/agent-workflows.php?notice='.rawurlencode('Agent Work Dependencies v17.4 installed.')));}
        catch(Throwable $e){$error=$e->getMessage();}
    }
}
require_once __DIR__.'/includes/vp3-public.php';
vp3_public_header('Agent Work Dependencies — VP3','Install Phase 17.4 cross-workflow dependencies and delegation.',['compact'=>true]);
?>
<main class="vp3-auth-shell"><section class="vp3-auth-visual"><div class="vp3-auth-visual-content"><div class="vp3-kicker">Phase 17.4</div><h1>Delegate work. Respect dependencies.</h1><p>This adds owner-scoped prerequisite relationships between durable workflows and lets Agent Chat route remaining work to Cloud or HomeServer without replacing the Phase 19 scheduler, approvals, retries, leases or receipts.</p></div></section><section class="vp3-auth-form-side"><div class="vp3-auth-card"><div class="vp3-kicker">Database</div><h1>Work Delegation &amp; Dependencies</h1><?php if($ready): ?><div class="vp3-alert success">Agent Work Dependencies v17.4 is installed.</div><a class="vp3-btn primary" href="<?= e(url('/chat.php')) ?>">Open Agent Chat →</a><?php else: ?><p class="vp3-auth-intro">Add the cross-workflow prerequisite graph. Existing action-level Phase 19 dependencies remain unchanged.</p><?php if($error!==''): ?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><button class="vp3-btn primary" type="submit">Install Phase 17.4 →</button></form><?php endif; ?></div></section></main>
<?php vp3_public_footer(); ?>
