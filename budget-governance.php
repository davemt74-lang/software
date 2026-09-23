<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_permission('account.access');

$pdo=db();
$user=current_user();
if(!$pdo||!$user)redirect(url('/login.php'));
if(!vp3_cognitive_budget_schema_ready_v2560($pdo))redirect(url('/upgrade.php'));

function vp3_budget_ui_usd_to_micros_v2560(string $value): ?int
{
    $value=trim($value);
    if($value==='')return null;
    if(!preg_match('/^(?:\d+)(?:\.\d{1,6})?$/',$value))throw new RuntimeException('USD limits must be a non-negative dollar amount with up to 6 decimal places.');
    [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
    $fraction=str_pad(substr($fraction,0,6),6,'0');
    if(strlen($whole)>12)throw new RuntimeException('USD budget is too large.');
    return ((int)$whole*1000000)+(int)$fraction;
}

function vp3_budget_ui_money_v2560(?int $micros): string
{
    if($micros===null)return '—';
    return '$'.number_format($micros/1000000,2);
}

function vp3_budget_ui_state_v2560(string $state): string
{
    return ucwords(str_replace('_',' ',$state));
}

$notice=trim((string)($_GET['notice']??''));
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Refresh and try again.';
    else{
        try{
            $action=trim((string)($_POST['action']??''));
            if($action==='save_policy'){
                $input=$_POST;
                $input['cost_limit_micros']=vp3_budget_ui_usd_to_micros_v2560((string)($_POST['cost_limit_usd']??''));
                $input['token_limit']=trim((string)($_POST['token_limit']??''))===''?null:max(0,(int)$_POST['token_limit']);
                $saved=vp3_cognitive_budget_policy_save_v2560($pdo,$user,$input);
                $notice='Budget policy saved: '.(string)$saved['label'].'.';
            }elseif($action==='disable_policy'){
                $saved=vp3_cognitive_budget_policy_disable_v2560($pdo,$user,max(0,(int)($_POST['policy_id']??0)));
                $notice='Budget policy disabled: '.(string)$saved['label'].'.';
            }elseif($action==='grant_override'){
                $policyId=max(0,(int)($_POST['policy_id']??0));
                $goalId=max(0,(int)($_POST['goal_id']??0));
                if($goalId<1)throw new RuntimeException('Choose a held goal to approve.');
                vp3_cognitive_budget_override_v2560(
                    $pdo,$user,$policyId,'goal',(string)$goalId,true,
                    vp3_cognitive_budget_text_v2560($_POST['reason']??'User approved this goal to continue for the current budget period.',500)
                );
                $notice='Budget override granted for goal #'.$goalId.' through the current budget period.';
            }elseif($action==='revoke_override'){
                $policyId=max(0,(int)($_POST['policy_id']??0));
                $goalId=max(0,(int)($_POST['goal_id']??0));
                if($goalId<1)throw new RuntimeException('Override goal is invalid.');
                vp3_cognitive_budget_override_v2560(
                    $pdo,$user,$policyId,'goal',(string)$goalId,false,
                    vp3_cognitive_budget_text_v2560($_POST['reason']??'User revoked this budget override.',500)
                );
                $notice='Budget override revoked for goal #'.$goalId.'.';
            }else throw new RuntimeException('Unsupported Budget Governance action.');

            redirect(url('/budget-governance.php?notice='.rawurlencode($notice)));
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}

$policies=vp3_cognitive_budget_policy_rows_v2560($pdo,$user,false);
$portfolio=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);
$budget=is_array($portfolio['budget_governance']??null)?$portfolio['budget_governance']:[
    'configured'=>false,'policies'=>[],'held_goals'=>[],'counts'=>[]
];
$audit=vp3_cognitive_budget_audit_rows_v2560($pdo,$user,50);
$policySnapshot=[];
foreach((array)($budget['policies']??[]) as $row){
    if(is_array($row)&&isset($row['policy']['id']))$policySnapshot[(int)$row['policy']['id']]=$row;
}
$goals=[];
if(table_exists('agent_goals')){
    $stmt=$pdo->prepare("SELECT id,title,goal,execution_mode,status FROM agent_goals
      WHERE owner_user_id=? AND status<>'archived' ORDER BY priority DESC,id DESC LIMIT 100");
    $stmt->execute([(int)$user['id']]);$goals=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}
$goalLabels=[];
foreach($goals as $goal)$goalLabels[(int)$goal['id']]=trim((string)($goal['title']??''))?:trim((string)($goal['goal']??''))?:('Goal #'.(int)$goal['id']);

$agents=[];
if(table_exists('user_agents')){
    $stmt=$pdo->prepare('SELECT id,display_name,is_active FROM user_agents WHERE owner_user_id=? ORDER BY is_active DESC,is_default DESC,id');
    $stmt->execute([(int)$user['id']]);$agents=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}
$projects=[];
if(table_exists('agent_workflow_runs')){
    $stmt=$pdo->prepare("SELECT source_key,MAX(title) title,COUNT(*) run_count FROM agent_workflow_runs
      WHERE owner_user_id=? AND TRIM(source_key)<>'' GROUP BY source_key ORDER BY MAX(updated_at) DESC LIMIT 100");
    $stmt->execute([(int)$user['id']]);$projects=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

$heldItems=[];
foreach((array)($portfolio['items']??[]) as $item){
    if(is_array($item)&&!empty($item['budget_hard_hold']))$heldItems[]=$item;
}

require_once __DIR__.'/includes/vp3-public.php';
vp3_public_header('Budget Governance — VP3','Explicit AI budget guardrails, forecasts and overrides.',['compact'=>true]);
?>
<link rel="stylesheet" href="<?= e(url('/budget-governance-v2560.css?v=2560')) ?>">
<main class="budget-shell">
  <section class="budget-hero">
    <div><small>VP3 Cognitive Runtime v25.60</small><h1>Budget Governance</h1><p>Set explicit AI cost-estimate and Cloud-token guardrails. Soft policies influence planning; hard policies hold only new autonomous Cloud work until you approve an override.</p></div>
    <div class="budget-hero-actions"><a href="<?= e(url('/chat.php')) ?>">Agent Chat</a><a href="<?= e(url('/account.php')) ?>">Account</a></div>
  </section>

  <?php if($notice!==''): ?><div class="budget-alert success" role="status"><?= e($notice) ?></div><?php endif; ?>
  <?php if($error!==''): ?><div class="budget-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

  <section class="budget-summary">
    <article><small>Policies</small><strong><?= (int)($budget['counts']['policies']??0) ?></strong></article>
    <article><small>Hard policies</small><strong><?= (int)($budget['counts']['hard_policies']??0) ?></strong></article>
    <article><small>Needs attention</small><strong><?= (int)($budget['counts']['attention_policies']??0) ?></strong></article>
    <article><small>Held goals</small><strong><?= (int)($budget['counts']['held_goals']??0) ?></strong></article>
    <article><small>Commitment conflicts</small><strong><?= (int)($budget['counts']['commitment_conflicts']??0) ?></strong></article>
    <article><small>Active overrides</small><strong><?= (int)($budget['counts']['active_overrides']??0) ?></strong></article>
  </section>

  <section class="budget-panel">
    <header><div><small>Explicit user policy</small><h2>Create or update a budget</h2></div><p>No default budget is created. Leave either limit blank if you want to govern only cost or only tokens.</p></header>
    <form method="post" class="budget-form" id="budgetPolicyForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_policy"><input type="hidden" name="id" id="budgetPolicyId" value="0">
      <label><span>Policy name</span><input name="label" id="budgetLabel" maxlength="190" placeholder="Monthly autonomous AI budget"></label>
      <label><span>Scope</span><select name="scope_kind" id="budgetScopeKind"><option value="account">Account</option><option value="goal">Goal</option><option value="agent">Agent</option><option value="project">Project / source key</option></select></label>
      <label class="budget-scope-value"><span>Scope value</span><input name="scope_key" id="budgetScopeKey" maxlength="190" placeholder="Not needed for account scope" list="budgetScopeOptions"><datalist id="budgetScopeOptions"></datalist></label>
      <label><span>Period</span><select name="period_kind" id="budgetPeriod"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly" selected>Monthly</option></select></label>
      <label><span>Enforcement</span><select name="enforcement_mode" id="budgetMode"><option value="soft">Soft warning / replan</option><option value="hard">Hard approval guardrail</option></select></label>
      <label><span>USD estimate limit</span><input name="cost_limit_usd" id="budgetCost" inputmode="decimal" placeholder="25.00"></label>
      <label><span>Cloud-token limit</span><input name="token_limit" id="budgetTokens" inputmode="numeric" min="0" type="number" placeholder="100000"></label>
      <label><span>Warning threshold</span><input name="warning_percent" id="budgetWarning" type="number" min="50" max="99" value="80"><small>Percent of the configured limit.</small></label>
      <div class="budget-form-actions"><button type="submit">Save policy</button><button type="button" class="secondary" id="budgetReset">New policy</button></div>
    </form>
    <datalist id="budgetGoalOptions"><?php foreach($goals as $goal): ?><option value="<?= (int)$goal['id'] ?>"><?= e($goalLabels[(int)$goal['id']]) ?></option><?php endforeach; ?></datalist>
    <datalist id="budgetAgentOptions"><?php foreach($agents as $agent): ?><option value="<?= (int)$agent['id'] ?>"><?= e((string)$agent['display_name']) ?></option><?php endforeach; ?></datalist>
    <datalist id="budgetProjectOptions"><?php foreach($projects as $project): ?><option value="<?= e((string)$project['source_key']) ?>"><?= e((string)($project['title']??$project['source_key'])) ?></option><?php endforeach; ?></datalist>
  </section>

  <section class="budget-panel">
    <header><div><small>Current period</small><h2>Budget policies</h2></div><p>Usage comes from the canonical AI execution ledger. Dollar values are configured-rate estimates, not provider invoices.</p></header>
    <div class="budget-policy-list">
      <?php foreach($policies as $policy):
        $id=(int)$policy['id'];$snapshot=$policySnapshot[$id]??null;$state=(string)($snapshot['state']['state']??($policy['is_active']?'healthy':'disabled'));
        $usage=(array)($snapshot['usage']??[]);$period=(array)($usage['period']??[]);
        $costLimit=$policy['cost_limit_micros'];$tokenLimit=$policy['token_limit'];
      ?>
      <article class="budget-policy <?= e($state) ?>">
        <div class="budget-policy-top"><span><?= e(vp3_budget_ui_state_v2560($state)) ?></span><span><?= e(ucfirst((string)$policy['enforcement_mode'])) ?> · <?= e(ucfirst((string)$policy['period_kind'])) ?></span></div>
        <h3><?= e((string)$policy['label']) ?></h3>
        <p><?= e(ucfirst((string)$policy['scope_kind'])) ?><?= (string)$policy['scope_key']!==''?' · '.e((string)$policy['scope_key']):'' ?><?php if(empty($policy['is_active'])): ?> · disabled<?php endif; ?></p>
        <div class="budget-metrics">
          <div><small>Used est. cost</small><strong><?= e(vp3_budget_ui_money_v2560(isset($usage['known_cost_micros'])?(int)$usage['known_cost_micros']:0)) ?><?= $costLimit!==null?' / '.e(vp3_budget_ui_money_v2560((int)$costLimit)):'' ?></strong></div>
          <div><small>Cloud tokens</small><strong><?= number_format((int)($usage['cloud_tokens_charged']??0)) ?><?= $tokenLimit!==null?' / '.number_format((int)$tokenLimit):'' ?></strong></div>
          <div><small>Projected remaining</small><strong><?= e(vp3_budget_ui_money_v2560(isset($snapshot['projected_remaining_cost_micros'])?(int)$snapshot['projected_remaining_cost_micros']:0)) ?> · <?= number_format((int)($snapshot['projected_remaining_tokens']??0)) ?> tokens</strong></div>
          <div><small>Run-rate period end</small><strong><?= e(vp3_budget_ui_money_v2560(isset($snapshot['run_rate_period_end_cost_micros'])?(int)$snapshot['run_rate_period_end_cost_micros']:0)) ?> · <?= number_format((int)($snapshot['run_rate_period_end_tokens']??0)) ?> tokens</strong></div>
        </div>
        <small class="budget-period"><?= !empty($period['start_at'])?e((string)$period['start_at']).' → '.e((string)$period['end_at']):'No active usage projection' ?><?= (int)($usage['unknown_cost_requests']??0)>0?' · '.(int)$usage['unknown_cost_requests'].' unknown-priced request(s)':'' ?></small>
        <div class="budget-policy-actions">
          <button type="button" class="secondary budget-edit" data-policy="<?= e(json_encode($policy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?>">Edit</button>
          <?php if(!empty($policy['is_active'])): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="disable_policy"><input type="hidden" name="policy_id" value="<?= $id ?>"><button type="submit" class="secondary">Disable</button></form><?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
      <?php if(!$policies): ?><div class="budget-empty">No AI budget policy exists yet. Autonomous behavior remains unchanged until you explicitly create one.</div><?php endif; ?>
    </div>
  </section>

  <section class="budget-panel">
    <header><div><small>Hard-policy review</small><h2>Held autonomous goals</h2></div><p>A held goal is not cancelled. Granting an override allows that goal through the matching hard policy until the current budget period ends.</p></header>
    <div class="budget-policy-list">
      <?php foreach($heldItems as $item):
        $goalId=(int)$item['goal_id'];$holdPolicies=array_map('intval',(array)($item['budget_hold_policy_ids']??[]));
      ?>
      <article class="budget-policy held">
        <div class="budget-policy-top"><span>Approval required</span><span><?= !empty($item['budget_commitment_conflict'])?'Commitment conflict':'Autonomous Cloud work' ?></span></div>
        <h3><?= e((string)($item['title']??($goalLabels[$goalId]??('Goal #'.$goalId)))) ?></h3>
        <p><?= e(implode(', ',array_map(static fn($x): string=>str_replace('_',' ',(string)$x),(array)($item['budget_hold_reasons']??[])))) ?></p>
        <?php foreach($holdPolicies as $policyId): $policy=$policySnapshot[$policyId]['policy']??null;if(!$policy)continue; ?>
        <form method="post" class="budget-override-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="grant_override"><input type="hidden" name="policy_id" value="<?= $policyId ?>"><input type="hidden" name="goal_id" value="<?= $goalId ?>">
          <input name="reason" maxlength="500" placeholder="Optional approval note">
          <button type="submit">Approve through period · <?= e((string)$policy['label']) ?></button>
        </form>
        <?php endforeach; ?>
      </article>
      <?php endforeach; ?>
      <?php if(!$heldItems): ?><div class="budget-empty">No autonomous goals are currently held by a hard budget policy.</div><?php endif; ?>
    </div>
  </section>

  <section class="budget-panel">
    <header><div><small>Governance history</small><h2>Policy & override audit</h2></div><p>This is policy-decision history, not a second usage or billing ledger.</p></header>
    <div class="budget-audit">
      <?php foreach($audit as $event): ?>
      <article><span><?= e(vp3_budget_ui_state_v2560((string)$event['decision_type'])) ?></span><strong><?= e((string)$event['policy_label']) ?></strong><p><?= e((string)$event['reason']) ?></p><small><?= e((string)$event['created_at']) ?> · <?= e((string)$event['subject_kind']) ?> <?= e((string)$event['subject_key']) ?><?= !empty($event['expires_at'])?' · expires '.e((string)$event['expires_at']):'' ?></small></article>
      <?php endforeach; ?>
      <?php if(!$audit): ?><div class="budget-empty">Budget policy changes and overrides will appear here.</div><?php endif; ?>
    </div>
  </section>
</main>
<script>
(() => {
  'use strict';
  const form=document.getElementById('budgetPolicyForm');
  const scope=document.getElementById('budgetScopeKind');
  const key=document.getElementById('budgetScopeKey');
  const list=document.getElementById('budgetScopeOptions');
  if(!form||!scope||!key||!list)return;
  const sources={
    goal:document.getElementById('budgetGoalOptions'),
    agent:document.getElementById('budgetAgentOptions'),
    project:document.getElementById('budgetProjectOptions')
  };
  function syncScope(){
    const kind=scope.value;
    key.disabled=kind==='account';
    if(kind==='account'){key.value='';key.placeholder='Not needed for account scope';list.innerHTML='';return;}
    key.placeholder=kind==='project'?'Existing workflow source key':'Numeric '+kind+' id';
    list.innerHTML=sources[kind]?.innerHTML||'';
  }
  function reset(){
    form.reset();document.getElementById('budgetPolicyId').value='0';
    scope.value='account';document.getElementById('budgetPeriod').value='monthly';
    document.getElementById('budgetMode').value='soft';document.getElementById('budgetWarning').value='80';syncScope();
  }
  scope.addEventListener('change',syncScope);
  document.getElementById('budgetReset')?.addEventListener('click',reset);
  document.querySelectorAll('.budget-edit').forEach(btn=>btn.addEventListener('click',()=>{
    const p=JSON.parse(btn.dataset.policy||'{}');
    document.getElementById('budgetPolicyId').value=Number(p.id||0);
    document.getElementById('budgetLabel').value=p.label||'';
    scope.value=p.scope_kind||'account';syncScope();key.value=p.scope_key||'';
    document.getElementById('budgetPeriod').value=p.period_kind||'monthly';
    document.getElementById('budgetMode').value=p.enforcement_mode||'soft';
    document.getElementById('budgetCost').value=p.cost_limit_micros===null||p.cost_limit_micros===undefined?'':(Number(p.cost_limit_micros)/1000000).toFixed(6).replace(/0+$/,'').replace(/\.$/,'');
    document.getElementById('budgetTokens').value=p.token_limit===null||p.token_limit===undefined?'':Number(p.token_limit);
    document.getElementById('budgetWarning').value=Number(p.warning_percent||80);
    form.scrollIntoView({behavior:'smooth',block:'start'});
  }));
  syncScope();
})();
</script>
<?php vp3_public_footer(); ?>
