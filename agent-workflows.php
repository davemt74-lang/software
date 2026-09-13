<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/agent-workflow-runs-v1400.php';
require_permission('account.access');
$pdo=db();$user=current_user();if(!$pdo||!$user)redirect(url('/login.php'));
if(!agent_workflow_schema_ready_v1400($pdo))redirect(url('/agent-workflow-upgrade-v1400.php'));

$notice='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){$error='Session expired. Refresh the page and try again.';}
    else{
        try{
            $action=trim((string)($_POST['action']??''));$run=null;
            if($action==='create_from_brain'){
                $priority=agent_workflow_find_brain_priority_v1400($user,trim((string)($_POST['priority_key']??'')),trim((string)($_POST['suggestion_hash']??'')));
                if(!$priority)throw new RuntimeException('That Agent Brain priority is no longer available. Refresh and try again.');
                $run=agent_workflow_create_from_priority_v1400($pdo,$user,$priority);
                $notice='Workflow created from the Agent Brain plan.';
            }elseif($action==='approve'){$run=agent_workflow_approve_v1400($pdo,$user,(int)($_POST['run_id']??0));$notice='Workflow approved.';}
            elseif($action==='cancel'){$run=agent_workflow_cancel_v1400($pdo,$user,(int)($_POST['run_id']??0));$notice='Workflow cancelled.';}
            elseif($action==='retry'){$run=agent_workflow_retry_v1400($pdo,$user,(int)($_POST['run_id']??0));$notice='Workflow retry queued.';}
            else throw new RuntimeException('Unknown workflow action.');
            if(is_array($run)&&!empty($run['id']))redirect(url('/agent-workflows.php?id='.(int)$run['id'].'&notice='.rawurlencode($notice)));
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}
if($notice===''&&isset($_GET['notice']))$notice=mb_strimwidth(trim((string)$_GET['notice']),0,240,'…');

$brain=function_exists('agent_cognitive_loop_v310_state')?agent_cognitive_loop_v310_state($user):['priorities'=>[]];
$priorities=array_values(array_filter((array)($brain['priorities']??[]),'is_array'));
$runs=agent_workflow_recent_v1400($pdo,$user,30);
$detail=null;$detailId=max(0,(int)($_GET['id']??0));
if($detailId>0){$row=agent_workflow_row_v1400($pdo,(int)$user['id'],$detailId);if($row)$detail=agent_workflow_public_run_v1400($pdo,$row,true);}

function workflow_v1400_status_label(string $status): string{return str_replace('_',' ',ucwords($status,'_'));}
function workflow_v1400_time(string $value): string{$ts=strtotime($value);return $ts?date('M j, g:i A',$ts):'—';}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f5"><title><?= e(system_agent_name()) ?> | Agent Workflows</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/agent-workflows-v1400.css?v=1400')) ?>"></head>
<body class="workflow-page"><div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='agent_workflows';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main workflow-main">
<?php $memberHeaderUser=$user;$memberHeaderTitle='Agent Workflows';$memberHeaderSubtitle='Plans, approvals, execution targets + observable results';$memberHeaderActions='';require __DIR__.'/includes/member-header.php'; ?>
<section class="workflow-canvas"><div class="workflow-inner">
<?php if($notice!==''): ?><div class="workflow-notice success" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if($error!==''): ?><div class="workflow-notice error" role="alert"><?= e($error) ?></div><?php endif; ?>

<section class="workflow-hero"><div><small>Phase 14</small><h1>Agent Workflow Runs</h1><p>The Brain can turn a prioritized next move into a durable run. VP3 records the goal, approval boundary, action sequence, execution target and result without storing hidden reasoning.</p></div><div class="workflow-hero-actions"><a class="workflow-button" href="<?= e(url('/calendar.php')) ?>">Calendar</a><a class="workflow-button" href="<?= e(url('/chat.php')) ?>">Ask Agent</a></div></section>

<?php if($detail): ?>
<section class="workflow-detail">
  <header class="workflow-section-head"><div><small>Run #<?= (int)$detail['id'] ?></small><h2><?= e((string)$detail['title']) ?></h2></div><span class="workflow-status <?= e((string)$detail['status']) ?>"><?= e(workflow_v1400_status_label((string)$detail['status'])) ?></span></header>
  <div class="workflow-summary-grid">
    <div><small>Goal</small><strong><?= e((string)$detail['goal']) ?></strong></div>
    <div><small>Why now</small><strong><?= e((string)$detail['decision_summary']) ?></strong></div>
    <div><small>Workflow</small><strong><?= e(workflow_v1400_status_label((string)$detail['workflow_type'])) ?></strong></div>
    <div><small>Risk / approval</small><strong><?= e(ucfirst((string)$detail['risk_level'])) ?> · <?= !empty($detail['requires_approval'])?'Approval required':'No approval required' ?></strong></div>
    <div><small>Execution</small><strong><?= e(ucfirst((string)$detail['execution_target'])) ?><?= (string)$detail['capability_key']!==''?' · '.e((string)$detail['capability_key']):'' ?></strong></div>
    <div><small>Updated</small><strong><?= e(workflow_v1400_time((string)$detail['updated_at'])) ?></strong></div>
  </div>
  <div class="workflow-actions-bar">
    <?php if((string)$detail['status']==='approval_pending'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="run_id" value="<?= (int)$detail['id'] ?>"><button class="workflow-button primary" type="submit">Approve workflow</button></form><?php endif; ?>
    <?php if((string)$detail['status']==='failed'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="run_id" value="<?= (int)$detail['id'] ?>"><button class="workflow-button primary" type="submit">Retry</button></form><?php endif; ?>
    <?php if(!in_array((string)$detail['status'],['completed','cancelled'],true)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="run_id" value="<?= (int)$detail['id'] ?>"><button class="workflow-button danger" type="submit">Cancel</button></form><?php endif; ?>
    <a class="workflow-button" href="<?= e(url('/agent-workflows.php')) ?>">All runs</a>
  </div>
  <div class="workflow-two-col">
    <section class="workflow-panel"><div class="workflow-panel-head"><h3>Action plan</h3><span><?= count((array)$detail['actions']) ?> steps</span></div>
      <div class="workflow-step-list">
      <?php foreach((array)$detail['actions'] as $step): ?><article class="workflow-step"><span class="workflow-step-index"><?= (int)$step['sequence_no'] ?></span><div><div class="workflow-step-title"><strong><?= e((string)$step['label']) ?></strong><span class="workflow-status <?= e((string)$step['status']) ?>"><?= e(workflow_v1400_status_label((string)$step['status'])) ?></span></div><p><?= e((string)$step['summary']) ?></p><small><?= e(ucfirst((string)$step['execution_target'])) ?><?= (string)$step['capability_key']!==''?' · '.e((string)$step['capability_key']):'' ?><?= !empty($step['requires_approval'])?' · approval boundary':'' ?></small><?php if((string)$step['result_summary']!==''): ?><div class="workflow-result"><b>Result</b><?= e((string)$step['result_summary']) ?></div><?php endif; ?></div></article><?php endforeach; ?>
      </div>
    </section>
    <section class="workflow-panel"><div class="workflow-panel-head"><h3>Execution history</h3><span>Newest first</span></div><div class="workflow-event-list">
      <?php foreach((array)$detail['events'] as $event): ?><article><strong><?= e(workflow_v1400_status_label((string)$event['event_type'])) ?></strong><p><?= e((string)$event['summary']) ?></p><small><?= e(workflow_v1400_time((string)$event['created_at'])) ?> · <?= e((string)$event['actor_kind']) ?></small></article><?php endforeach; ?>
      <?php if(!(array)$detail['events']): ?><div class="workflow-empty">No execution events recorded yet.</div><?php endif; ?>
    </div></section>
  </div>
</section>
<?php endif; ?>

<section class="workflow-panel brain-priorities"><div class="workflow-panel-head"><div><small>Agent Brain</small><h2>Ready to become workflows</h2></div><span><?= count($priorities) ?> priorities</span></div>
<div class="workflow-priority-grid">
<?php foreach($priorities as $priority): $key=(string)($priority['key']??'');$hash=(string)($priority['suggestion_hash']??'');if($key==='')continue; ?>
<article class="workflow-priority"><div class="workflow-priority-top"><span><?= e((string)($priority['source']??'Agent Brain')) ?></span><span><?= e(ucfirst((string)($priority['risk_level']??'low'))) ?> risk</span></div><h3><?= e((string)($priority['title']??'Agent next action')) ?></h3><p><?= e((string)($priority['reason']??'')) ?></p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create_from_brain"><input type="hidden" name="priority_key" value="<?= e($key) ?>"><input type="hidden" name="suggestion_hash" value="<?= e($hash) ?>"><button class="workflow-button primary" type="submit">Create workflow</button></form></article>
<?php endforeach; ?>
<?php if(!$priorities): ?><div class="workflow-empty">No current Brain priorities are ready to turn into a workflow.</div><?php endif; ?>
</div></section>

<section class="workflow-panel"><div class="workflow-panel-head"><div><small>Execution ledger</small><h2>Recent runs</h2></div><span><?= count($runs) ?> shown</span></div><div class="workflow-run-list">
<?php foreach($runs as $row): ?><a class="workflow-run" href="<?= e(url('/agent-workflows.php?id='.(int)$row['id'])) ?>"><div><small>#<?= (int)$row['id'] ?> · <?= e(workflow_v1400_status_label((string)$row['workflow_type'])) ?></small><strong><?= e((string)$row['title']) ?></strong><span><?= e((string)$row['decision_summary']) ?></span></div><div class="workflow-run-meta"><span class="workflow-status <?= e((string)$row['status']) ?>"><?= e(workflow_v1400_status_label((string)$row['status'])) ?></span><small><?= e(workflow_v1400_time((string)$row['updated_at'])) ?></small></div></a><?php endforeach; ?>
<?php if(!$runs): ?><div class="workflow-empty">No workflow runs yet. Convert a Brain priority above to create the first one.</div><?php endif; ?>
</div></section>
</div></section></main></div><script src="<?= e(url('/member-shell-v77.js?v=20260911')) ?>"></script></body></html>
