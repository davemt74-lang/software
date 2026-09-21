<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/agent-workflow-runs-v1400.php';
require_once __DIR__ . '/includes/agent-job-engine-v1900.php';
require_once __DIR__ . '/includes/agent-worker-runtime-v1910.php';
require_once __DIR__ . '/includes/browser-agent-runtime-v2200.php';
require_once __DIR__ . '/includes/browser-web-interaction-v2210.php';
require_once __DIR__ . '/includes/browser-multisite-v2220.php';
require_once __DIR__ . '/includes/browser-research-save-v2230.php';
require_once __DIR__ . '/includes/browser-transaction-safety-v2240.php';
require_permission('account.access');
$pdo=db();$user=current_user();if(!$pdo||!$user)redirect(url('/login.php'));
if(!agent_workflow_schema_ready_v1400($pdo))redirect(url('/agent-workflow-upgrade-v1400.php'));
$durable=agent_job_engine_schema_ready_v1900($pdo);
$workerRuntime=$durable?agent_worker_runtime_summary_v1910($pdo,$user):['build'=>'','workers'=>[]];

$notice='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){$error='Session expired. Refresh the page and try again.';}
    else{
        try{
            $action=trim((string)($_POST['action']??''));$run=null;
            if($action==='create_from_brain'){
                $priority=agent_workflow_find_brain_priority_v1400($user,trim((string)($_POST['priority_key']??'')),trim((string)($_POST['suggestion_hash']??'')));
                if(!$priority)throw new RuntimeException('That Agent Brain priority is no longer available. Refresh and try again.');
                $run=$durable?agent_job_enqueue_from_brain_v1900($pdo,$user,$priority):agent_workflow_create_from_priority_v1400($pdo,$user,$priority);
                $notice=$durable?'Durable job created from the Agent Brain plan.':'Workflow created from the Agent Brain plan.';
            }elseif($action==='approve'){
                $runId=(int)($_POST['run_id']??0);$run=agent_workflow_approve_v1400($pdo,$user,$runId);
                if($durable){$pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at=UTC_TIMESTAMP(),progress_message='Approved and ready' WHERE id=? AND owner_user_id=?")->execute([$runId,(int)$user['id']]);$row=agent_workflow_row_v1400($pdo,(int)$user['id'],$runId);if($row)$run=agent_job_public_run_v1900($pdo,$row,true);}
                $notice='Workflow approved.';
            }elseif($action==='cancel'){$run=$durable?agent_job_cancel_v1900($pdo,$user,(int)($_POST['run_id']??0)):agent_workflow_cancel_v1400($pdo,$user,(int)($_POST['run_id']??0));$notice='Workflow cancelled.';}
            elseif($action==='retry'){$run=$durable?agent_job_retry_v1900($pdo,$user,(int)($_POST['run_id']??0)):agent_workflow_retry_v1400($pdo,$user,(int)($_POST['run_id']??0));$notice='Workflow retry queued.';}
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
if($detailId>0){$row=agent_workflow_row_v1400($pdo,(int)$user['id'],$detailId);if($row)$detail=$durable?agent_job_public_run_v1900($pdo,$row,true):agent_workflow_public_run_v1400($pdo,$row,true);}
$browserRuntime=$detail&&vp3_browser_runtime_schema_ready_v2200($pdo)?vp3_browser_runtime_for_workflow_v2200($pdo,(int)$user['id'],$detailId):null;
$browserWeb=$detail&&vp3_browser_web_schema_ready_v2210($pdo)?vp3_browser_web_for_workflow_v2210($pdo,(int)$user['id'],$detailId):['count'=>0,'verified'=>0,'failed'=>0,'checkpointed'=>0,'interactions'=>[]];
$browserMulti=$detail&&vp3_browser_multisite_schema_ready_v2220($pdo)?vp3_browser_multisite_for_workflow_v2220($pdo,(int)$user['id'],$detailId):['attached'=>false];
$browserResearch=$detail&&vp3_browser_research_schema_ready_v2230($pdo)?vp3_browser_research_for_workflow_v2230($pdo,(int)$user['id'],$detailId):[];
$browserTransactions=$detail&&vp3_browser_transaction_schema_ready_v2240($pdo)?vp3_browser_transaction_for_workflow_v2240($pdo,(int)$user['id'],$detailId):['count'=>0,'completed'=>0,'uncertain'=>0,'failed'=>0,'manual_only'=>0,'intents'=>[]];

function workflow_v1400_status_label(string $status): string{return str_replace('_',' ',ucwords($status,'_'));}
function workflow_v1400_time(string $value): string{$ts=strtotime($value);return $ts?date('M j, g:i A',$ts):'—';}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f5"><title><?= e(system_agent_name()) ?> | Agent Workflows</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/agent-workflows-v1400.css?v=1400')) ?>"></head>
<body class="workflow-page"><div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='agent_workflows';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main workflow-main">
<?php $memberHeaderUser=$user;$memberHeaderTitle='Agent Workflows';$memberHeaderSubtitle=$durable?'Durable jobs, distributed workers, approvals + receipts':'Plans, approvals, execution targets + observable results';$memberHeaderActions='';require __DIR__.'/includes/member-header.php'; ?>
<section class="workflow-canvas"><div class="workflow-inner">
<?php if($notice!==''): ?><div class="workflow-notice success" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if($error!==''): ?><div class="workflow-notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if(!$durable): ?><div class="workflow-notice" role="status">Phase 19.0 durable execution is not installed yet. <a href="<?= e(url('/agent-job-engine-upgrade-v1900.php')) ?>">Install the Durable Job Engine</a>.</div><?php endif; ?>

<section class="workflow-hero"><div><small><?= $durable?'Phase 19.1':'Phase 14' ?></small><h1><?= $durable?'Distributed Agent Jobs':'Agent Workflow Runs' ?></h1><p><?= $durable?'The Agent Brain decides what matters. The durable job engine owns leases, retries and receipts while the worker runtime safely routes approved work to VP3 Cloud or a paired HomeServer.':'The Brain can turn a prioritized next move into a durable run. VP3 records the goal, approval boundary, action sequence, execution target and result without storing hidden reasoning.' ?></p></div><div class="workflow-hero-actions"><a class="workflow-button" href="<?= e(url('/calendar.php')) ?>">Calendar</a><a class="workflow-button" href="<?= e(url('/chat.php')) ?>">Ask Agent</a></div></section>

<?php if($durable): ?>
<section class="workflow-panel" aria-labelledby="workerRuntimeTitle">
  <div class="workflow-panel-head"><div><small>Phase 19.1</small><h2 id="workerRuntimeTitle">Worker Runtime</h2></div><span><?= count((array)($workerRuntime['workers']??[])) ?> execution routes</span></div>
  <div class="workflow-priority-grid">
    <?php foreach((array)($workerRuntime['workers']??[]) as $worker): $receipts=is_array($worker['receipts']??null)?$worker['receipts']:[]; ?>
    <article class="workflow-priority">
      <div class="workflow-priority-top"><span><?= e(ucfirst((string)($worker['executor']??'worker'))) ?></span><span><?= e(workflow_v1400_status_label((string)($worker['state']??'unavailable'))) ?></span></div>
      <h3><?= e((string)($worker['label']??'Worker')) ?></h3>
      <p><?= (int)($worker['active_jobs']??0) ?> / <?= (int)($worker['max_concurrency']??0) ?> active jobs · <?= (int)($worker['capability_count']??0) ?> routed capabilities<?php if(!empty($worker['last_seen_at'])): ?> · last seen <?= e(workflow_v1400_time((string)$worker['last_seen_at'])) ?><?php endif; ?>.</p>
      <small>Completed <?= (int)($receipts['completed']??0) ?> · Retries <?= (int)($receipts['retry_scheduled']??0) ?> · Dead-lettered <?= (int)($receipts['dead_lettered']??0) ?></small>
    </article>
    <?php endforeach; ?>
  </div>
  <div class="workflow-notice" role="note" style="margin:14px 0 0">Worker capabilities only narrow execution routing. Owner scope, workflow approvals, active leases and domain authorization remain authoritative; failed jobs keep the canonical Phase 19.0 <strong>failed</strong> state.</div>
</section>
<?php endif; ?>

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
    <?php if($durable): ?><div><small>Progress</small><strong><?= (int)($detail['progress_percent']??0) ?>%<?= !empty($detail['progress_message'])?' · '.e((string)$detail['progress_message']):'' ?></strong></div><div><small>Attempts</small><strong><?= (int)($detail['attempt_count']??0) ?> / <?= (int)($detail['max_attempts']??3) ?></strong></div><?php endif; ?>
  </div>
  <?php if($browserRuntime): ?>
  <section class="workflow-panel" aria-labelledby="browserRuntimeTitle">
    <div class="workflow-panel-head">
      <div><small>Browser Companion v22.00</small><h3 id="browserRuntimeTitle">Browser Agent Runtime</h3></div>
      <span class="workflow-status <?= e((string)$browserRuntime['status']) ?>"><?= e(workflow_v1400_status_label((string)$browserRuntime['status'])) ?></span>
    </div>
    <div class="workflow-summary-grid">
      <div><small>Runtime session</small><strong><?= e(substr((string)$browserRuntime['runtime_id'],0,8)) ?>…</strong></div>
      <div><small>Current skill</small><strong><?= e((string)($browserRuntime['current_skill_key']??'')!==''?str_replace('_',' ',(string)$browserRuntime['current_skill_key']):'Waiting') ?></strong></div>
      <div><small>Plan revision</small><strong>r<?= (int)($browserRuntime['plan_revision']??1) ?> · <?= (int)($browserRuntime['replan_count']??0) ?>/<?= (int)($browserRuntime['max_replans']??3) ?> replans</strong></div>
      <div><small>Observations</small><strong><?= (int)($browserRuntime['observation_count']??0) ?> task-scoped</strong></div>
      <div><small>Recovery</small><strong><?= e((string)($browserRuntime['recovery_code']??'')!==''?str_replace('_',' ',(string)$browserRuntime['recovery_code']):'Clear') ?></strong></div>
      <div><small>Last verified</small><strong><?= e(workflow_v1400_time((string)($browserRuntime['last_verified_at']??''))) ?></strong></div>
    </div>
    <div class="workflow-two-col">
      <section class="workflow-panel">
        <div class="workflow-panel-head"><h3>Runtime timeline</h3><span><?= count((array)($browserRuntime['timeline']??[])) ?> events</span></div>
        <div class="workflow-event-list">
          <?php foreach(array_slice((array)($browserRuntime['timeline']??[]),0,20) as $event): ?>
          <article><strong><?= e(workflow_v1400_status_label((string)$event['event_type'])) ?></strong><p><?= e((string)$event['summary']) ?></p><small><?= e(workflow_v1400_time((string)$event['created_at'])) ?><?= !empty($event['skill_key'])?' · '.e(str_replace('_',' ',(string)$event['skill_key'])):'' ?></small></article>
          <?php endforeach; ?>
          <?php if(!(array)($browserRuntime['timeline']??[])): ?><div class="workflow-empty">No Browser Runtime events yet.</div><?php endif; ?>
        </div>
      </section>
      <section class="workflow-panel">
        <div class="workflow-panel-head"><h3>Runtime context</h3><span>Reference-only</span></div>
        <div class="workflow-event-list">
          <article><strong><?= count((array)($browserRuntime['tabs']??[])) ?> runtime tabs</strong><p>Opaque Browser tab references are tied to authorized VP3 objects; URLs and titles are not stored.</p></article>
          <article><strong><?= count((array)($browserRuntime['observations']??[])) ?> live observations</strong><p>Task-scoped canonical state and fingerprints expire with runtime authority.</p></article>
          <article><strong>Authority remains bounded</strong><p>v21.90 Source, action, risk, step and expiration limits remain authoritative.</p></article>
        </div>
      </section>
    </div>
  </section>
  <?php if(!empty($browserMulti['attached'])): ?>
  <section class="workflow-panel" aria-labelledby="browserMultiSiteTitle">
    <div class="workflow-panel-head">
      <div><small>Browser Companion v22.20</small><h3 id="browserMultiSiteTitle">Multi-Site Workflow Runtime</h3></div>
      <span><?= (int)($browserMulti['handoff_count']??0) ?> handoffs · <?= count((array)($browserMulti['domains']??[])) ?> domains</span>
    </div>
    <div class="workflow-summary-grid">
      <div><small>Current domain</small><strong><?= e((string)($browserMulti['current_domain']??'—')) ?></strong></div>
      <div><small>Open runtime tabs</small><strong><?= count(array_filter((array)($browserMulti['tabs']??[]),static fn($x): bool=>(string)($x['status']??'')==='open')) ?> / <?= (int)($browserMulti['max_tabs']??0) ?></strong></div>
      <div><small>Structured facts</small><strong><?= count((array)($browserMulti['facts']??[])) ?> task-scoped</strong></div>
      <div><small>Conflicts</small><strong><?= count(array_filter((array)($browserMulti['facts']??[]),static fn($x): bool=>(string)($x['status']??'')==='conflict')) ?> need review</strong></div>
    </div>
    <div class="workflow-two-col">
      <section class="workflow-panel">
        <div class="workflow-panel-head"><h3>Approved domain map</h3><span>Never expands silently</span></div>
        <div class="workflow-event-list">
          <?php foreach((array)($browserMulti['domains']??[]) as $domain): ?>
          <article><strong><?= e((string)($domain['domain']??'')) ?> · <?= e(workflow_v1400_status_label((string)($domain['policy_mode']??'browse'))) ?></strong><p><?= count((array)($domain['allowed_actions']??[])) ?> permitted skills · <?= (int)($domain['visit_count']??0) ?> visits</p><small><?= !empty($domain['last_visited_at'])?e(workflow_v1400_time((string)$domain['last_visited_at'])):'Not visited yet' ?></small></article>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="workflow-panel">
        <div class="workflow-panel-head"><h3>Cross-site receipts</h3><span>URL fingerprints only</span></div>
        <div class="workflow-event-list">
          <?php foreach(array_slice((array)($browserMulti['handoffs']??[]),0,20) as $handoff): ?>
          <article><strong><?= e((string)($handoff['source_domain']??'')) ?> → <?= e((string)($handoff['target_domain']??'')) ?></strong><p><?= e(workflow_v1400_status_label((string)($handoff['status']??''))) ?><?= !empty($handoff['result_code'])?' · '.e((string)$handoff['result_code']):'' ?></p><small><?= e(workflow_v1400_time((string)($handoff['created_at']??''))) ?></small></article>
          <?php endforeach; ?>
          <?php if(!(array)($browserMulti['handoffs']??[])): ?><div class="workflow-empty">No cross-domain handoffs yet.</div><?php endif; ?>
        </div>
      </section>
    </div>
    <div class="workflow-notice" role="note" style="margin:14px 0 0">Raw URLs, browser history, cookies, credentials, MFA codes and tokens are not persisted by v22.20. Structured facts are task-scoped, source-attributed and removed when runtime authority ends.</div>
  </section>
  <?php endif; ?>

  <?php foreach((array)$browserResearch as $researchMission): $rp=(array)($researchMission['progress']??[]); ?>
  <section class="workflow-panel" aria-labelledby="browserResearchAgentTitle">
    <div class="workflow-panel-head">
      <div><small>Browser Companion v22.30</small><h3 id="browserResearchAgentTitle">Browser Research Agent</h3></div>
      <span><?= (int)($rp['pages']??0) ?> pages · <?= (int)($rp['claims']??0) ?> claims</span>
    </div>
    <div class="workflow-summary-grid">
      <div><small>Status</small><strong><?= e(workflow_v1400_status_label((string)($researchMission['status']??''))) ?></strong></div>
      <div><small>Corroborated</small><strong><?= (int)($rp['corroborated']??0) ?></strong></div>
      <div><small>Conflicted</small><strong><?= (int)($rp['conflicted']??0) ?></strong></div>
      <div><small>Research gaps</small><strong><?= count((array)($researchMission['gaps']??[])) ?></strong></div>
    </div>
    <div class="workflow-notice" role="note"><?= e((string)($researchMission['question']??'')) ?></div>
    <div class="workflow-two-col">
      <section class="workflow-panel">
        <div class="workflow-panel-head"><h3>Approved source plan</h3><span>v22.20 envelope</span></div>
        <div class="workflow-event-list">
          <?php foreach((array)($researchMission['source_plan']??[]) as $source): ?>
          <article><strong><?= e((string)($source['domain']??'')) ?></strong><p><?= !empty($source['checked'])?(int)($source['pages']??0).' page(s) analyzed':'Not checked yet' ?></p></article>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="workflow-panel">
        <div class="workflow-panel-head"><h3>Claim ledger</h3><span>Evidence-aware</span></div>
        <div class="workflow-event-list">
          <?php foreach(array_slice((array)($researchMission['claims']??[]),0,20) as $claim): ?>
          <article>
            <strong><?= e((string)($claim['value']??$claim['statement']??'Claim')) ?> · <?= e(workflow_v1400_status_label((string)($claim['state']??'single_source'))) ?></strong>
            <p><?= e((string)($claim['statement']??'')) ?></p>
            <small><?= (int)($claim['support_sources']??0) ?> source groups · <?= (int)($claim['primary_sources']??0) ?> primary · <?= (int)($claim['direct_sources']??0) ?> direct<?= !empty($claim['freshest_at'])?' · freshest '.e((string)$claim['freshest_at']):'' ?></small>
          </article>
          <?php endforeach; ?>
          <?php if(!(array)($researchMission['claims']??[])): ?><div class="workflow-empty">No structured claims yet.</div><?php endif; ?>
        </div>
      </section>
    </div>
    <?php if(!empty($researchMission['report']['url'])): ?><div class="workflow-actions-bar"><a class="workflow-button" href="<?= e((string)$researchMission['report']['url']) ?>">Open draft Research Report</a></div><?php endif; ?>
    <div class="workflow-notice" role="note" style="margin:14px 0 0">Raw page text, URLs and browser history are not persisted by the Browser Research mission. Durable state is limited to structured claims, bounded evidence excerpts, fingerprints, source domains, freshness metadata and canonical VP3 Research references. Saving creates drafts; it does not publish.</div>
  </section>
  <?php endforeach; ?>

  <?php if((int)($browserTransactions['count']??0)>0): ?>
  <section class="workflow-panel" aria-labelledby="browserTransactionTitle">
    <div class="workflow-panel-head">
      <div><small>Browser Companion v22.40</small><h3 id="browserTransactionTitle">Transaction & Submission Safety</h3></div>
      <span><?= (int)($browserTransactions['completed']??0) ?> verified · <?= (int)($browserTransactions['uncertain']??0) ?> uncertain · <?= (int)($browserTransactions['failed']??0) ?> stopped</span>
    </div>
    <div class="workflow-summary-grid">
      <div><small>Final reviews</small><strong><?= (int)($browserTransactions['count']??0) ?></strong></div>
      <div><small>Manual-only</small><strong><?= (int)($browserTransactions['manual_only']??0) ?></strong></div>
      <div><small>Approval binding</small><strong>Exact form hash · one use</strong></div>
      <div><small>Persistence</small><strong>No raw field values</strong></div>
    </div>
    <div class="workflow-event-list">
      <?php foreach(array_slice((array)($browserTransactions['intents']??[]),0,20) as $intent): ?>
      <article>
        <strong><?= e(workflow_v1400_status_label((string)($intent['submission_kind']??'form_submission'))) ?> · <?= e(workflow_v1400_status_label((string)($intent['status']??''))) ?></strong>
        <p><?= e((string)($intent['domain']??'')) ?> · <?= (int)($intent['field_count']??0) ?> reviewed fields<?= !empty($intent['manual_only'])?' · manual-only':'' ?></p>
        <small><?= e(workflow_v1400_time((string)($intent['created_at']??''))) ?><?= !empty($intent['result_code'])?' · '.e((string)$intent['result_code']):'' ?></small>
      </article>
      <?php endforeach; ?>
    </div>
    <div class="workflow-notice" role="note" style="margin:14px 0 0">v22.40 records hashes, consequence metadata, lifecycle state and dispatch receipts—not form values, passwords, payment details, MFA codes, hidden tokens, or browser history. A completed receipt means dispatch was immediately verified; an uncertain receipt means dispatch may have occurred and must be reviewed before retrying. Neither state asserts downstream merchant, booking, application, or account success.</div>
  </section>
  <?php endif; ?>

  <?php if((int)($browserWeb['count']??0)>0): ?>
  <section class="workflow-panel" aria-labelledby="browserWebInteractionTitle">
    <div class="workflow-panel-head">
      <div><small>Browser Companion v22.10</small><h3 id="browserWebInteractionTitle">Controlled Web Interaction Receipts</h3></div>
      <span><?= (int)($browserWeb['verified']??0) ?> verified · <?= (int)($browserWeb['failed']??0) ?> failed</span>
    </div>
    <div class="workflow-summary-grid">
      <div><small>Interactions</small><strong><?= (int)($browserWeb['count']??0) ?></strong></div>
      <div><small>Checkpointed</small><strong><?= (int)($browserWeb['checkpointed']??0) ?></strong></div>
      <div><small>Persistence</small><strong>Fingerprint-only · no typed values</strong></div>
      <div><small>Authority</small><strong>v21.90 delegation + v22.00 runtime</strong></div>
    </div>
    <div class="workflow-event-list">
      <?php foreach(array_slice((array)($browserWeb['interactions']??[]),0,20) as $interaction): ?>
      <article>
        <strong><?= e((string)($interaction['label']??'Web interaction')) ?> · <?= e(workflow_v1400_status_label((string)($interaction['status']??''))) ?></strong>
        <p><?= e((string)($interaction['domain']??'')) ?> · <?= e((string)($interaction['element_kind']??'control')) ?> · <?= e((string)($interaction['result_code']??'')) ?></p>
        <small><?= e(workflow_v1400_time((string)($interaction['created_at']??''))) ?> · <?= e((string)($interaction['risk_level']??'low')) ?> risk<?= !empty($interaction['requires_checkpoint'])?' · checkpoint':'' ?></small>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
  <?php endif; ?>
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
  <?php if($durable&&!empty($detail['receipts'])): ?><section class="workflow-panel"><div class="workflow-panel-head"><h3>Execution receipts</h3><span><?= count((array)$detail['receipts']) ?> receipts</span></div><div class="workflow-event-list"><?php foreach((array)$detail['receipts'] as $receipt): ?><article><strong><?= e(workflow_v1400_status_label((string)$receipt['status'])) ?> · action #<?= (int)$receipt['action_id'] ?></strong><p><?= e((string)$receipt['summary']) ?></p><small><?= e(workflow_v1400_time((string)$receipt['created_at'])) ?> · <?= e((string)$receipt['executor']) ?> · attempt <?= (int)$receipt['attempt_no'] ?></small></article><?php endforeach; ?></div></section><?php endif; ?>
</section>
<?php endif; ?>

<section class="workflow-panel brain-priorities"><div class="workflow-panel-head"><div><small>Agent Brain</small><h2>Ready to become workflows</h2></div><span><?= count($priorities) ?> priorities</span></div>
<div class="workflow-priority-grid">
<?php foreach($priorities as $priority): $key=(string)($priority['key']??'');$hash=(string)($priority['suggestion_hash']??'');if($key==='')continue; ?>
<article class="workflow-priority"><div class="workflow-priority-top"><span><?= e((string)($priority['source']??'Agent Brain')) ?></span><span><?= e(ucfirst((string)($priority['risk_level']??'low'))) ?> risk</span></div><h3><?= e((string)($priority['title']??'Agent next action')) ?></h3><p><?= e((string)($priority['reason']??'')) ?></p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create_from_brain"><input type="hidden" name="priority_key" value="<?= e($key) ?>"><input type="hidden" name="suggestion_hash" value="<?= e($hash) ?>"><button class="workflow-button primary" type="submit"><?= $durable?'Create durable job':'Create workflow' ?></button></form></article>
<?php endforeach; ?>
<?php if(!$priorities): ?><div class="workflow-empty">No current Brain priorities are ready to turn into a workflow.</div><?php endif; ?>
</div></section>

<section class="workflow-panel"><div class="workflow-panel-head"><div><small>Execution ledger</small><h2>Recent runs</h2></div><span><?= count($runs) ?> shown</span></div><div class="workflow-run-list">
<?php foreach($runs as $row): ?><a class="workflow-run" href="<?= e(url('/agent-workflows.php?id='.(int)$row['id'])) ?>"><div><small>#<?= (int)$row['id'] ?> · <?= e(workflow_v1400_status_label((string)$row['workflow_type'])) ?></small><strong><?= e((string)$row['title']) ?></strong><span><?= e((string)$row['decision_summary']) ?></span></div><div class="workflow-run-meta"><span class="workflow-status <?= e((string)$row['status']) ?>"><?= e(workflow_v1400_status_label((string)$row['status'])) ?></span><small><?= e(workflow_v1400_time((string)$row['updated_at'])) ?></small></div></a><?php endforeach; ?>
<?php if(!$runs): ?><div class="workflow-empty">No workflow runs yet. Convert a Brain priority above to create the first one.</div><?php endif; ?>
</div></section>
</div></section></main></div><script src="<?= e(url('/member-shell-v77.js?v=20260911')) ?>"></script></body></html>
