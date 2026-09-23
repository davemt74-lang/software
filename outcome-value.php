<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_permission('account.access');

$pdo=db();
$user=current_user();
if(!$pdo||!$user)redirect(url('/login.php'));
if(!vp3_cognitive_value_schema_ready_v2570($pdo))redirect(url('/upgrade.php'));

function vp3_value_ui_amount_to_micros_v2570(string $value): ?int
{
    $value=trim($value);
    if($value==='')return null;
    if(!preg_match('/^(?:\d+)(?:\.\d{1,6})?$/',$value))throw new RuntimeException('Value must be non-negative with up to 6 decimal places.');
    [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
    $fraction=str_pad(substr($fraction,0,6),6,'0');
    if(strlen($whole)>12)throw new RuntimeException('Value is too large.');
    return ((int)$whole*1000000)+(int)$fraction;
}

function vp3_value_ui_money_v2570(?int $micros,string $currency='USD'): string
{
    if($micros===null)return '—';
    return strtoupper($currency).' '.number_format($micros/1000000,2);
}

function vp3_value_ui_ratio_v2570(mixed $ratio): string
{
    return $ratio===null?'No verified samples':number_format((float)$ratio*100,1).'% realized / expected';
}

$notice=trim((string)($_GET['notice']??''));
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Refresh and try again.';
    else{
        try{
            $action=trim((string)($_POST['action']??''));
            if($action==='save_profile'){
                $input=$_POST;
                $input['expected_value_micros']=vp3_value_ui_amount_to_micros_v2570((string)($_POST['expected_value']??''));
                $input['expected_score']=trim((string)($_POST['expected_score']??''))===''?null:max(0,min(100,(int)$_POST['expected_score']));
                $saved=vp3_cognitive_value_profile_save_v2570($pdo,$user,$input);
                $notice='Outcome value profile saved: '.(string)$saved['label'].'.';
            }elseif($action==='disable_profile'){
                $saved=vp3_cognitive_value_profile_disable_v2570($pdo,$user,max(0,(int)($_POST['profile_id']??0)));
                $notice='Outcome value profile disabled: '.(string)$saved['label'].'.';
            }elseif($action==='set_realized'){
                $profileId=max(0,(int)($_POST['profile_id']??0));
                $profile=vp3_cognitive_value_profile_row_v2570($pdo,(int)$user['id'],$profileId);
                if(!$profile)throw new RuntimeException('Outcome value profile not found.');
                $input=['note'=>(string)($_POST['note']??'')];
                if((string)$profile['value_kind']==='money'){
                    $input['value_micros']=vp3_value_ui_amount_to_micros_v2570((string)($_POST['realized_value']??''));
                }else{
                    $input['score_value']=trim((string)($_POST['realized_score']??''))===''?null:max(0,min(100,(int)$_POST['realized_score']));
                }
                vp3_cognitive_value_realization_set_v2570($pdo,$user,$profileId,$input);
                $notice='Realized value confirmed.';
            }elseif($action==='revoke_realized'){
                vp3_cognitive_value_realization_revoke_v2570(
                    $pdo,$user,max(0,(int)($_POST['profile_id']??0)),
                    vp3_cognitive_value_text_v2570($_POST['note']??'User revoked the manual value confirmation.',500)
                );
                $notice='Manual realized-value confirmation revoked.';
            }else throw new RuntimeException('Unsupported Outcome Value action.');

            redirect(url('/outcome-value.php?notice='.rawurlencode($notice)));
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}

$profiles=vp3_cognitive_value_profile_rows_v2570($pdo,$user,false);
$portfolio=vp3_cognitive_portfolio_snapshot_v2480($pdo,$user);
$valueRoi=is_array($portfolio['value_roi']??null)?$portfolio['value_roi']:[
    'configured'=>false,'goals'=>[],'profiles'=>[],'counts'=>[],'calibration'=>[]
];
$events=vp3_cognitive_value_events_v2570($pdo,$user,60);
$profileState=[];
foreach((array)($valueRoi['profiles']??[]) as $row){
    if(is_array($row)&&isset($row['profile']['id']))$profileState[(int)$row['profile']['id']]=$row;
}
$goalState=[];
foreach((array)($valueRoi['goals']??[]) as $row){
    if(is_array($row)&&isset($row['profile']['id']))$goalState[(int)$row['profile']['id']]=$row;
}

$goals=[];$workflows=[];$agents=[];$meetings=[];$projects=[];
if(table_exists('agent_goals')){
    $stmt=$pdo->prepare("SELECT id,title,goal,status FROM agent_goals WHERE owner_user_id=? ORDER BY id DESC LIMIT 120");
    $stmt->execute([(int)$user['id']]);$goals=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}
if(table_exists('agent_workflow_runs')){
    $stmt=$pdo->prepare("SELECT id,title,source_kind,source_key,status,agent_id FROM agent_workflow_runs
      WHERE owner_user_id=? ORDER BY id DESC LIMIT 160");
    $stmt->execute([(int)$user['id']]);$workflows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $seen=[];
    foreach($workflows as $run){
        $key=trim((string)($run['source_key']??''));if($key===''||isset($seen[$key]))continue;
        $seen[$key]=true;$projects[]=['source_key'=>$key,'title'=>(string)($run['title']??$key)];
    }
}
if(table_exists('user_agents')){
    $stmt=$pdo->prepare('SELECT id,display_name,is_active FROM user_agents WHERE owner_user_id=? ORDER BY is_active DESC,is_default DESC,id');
    $stmt->execute([(int)$user['id']]);$agents=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}
if(table_exists('video_meetings')){
    $stmt=$pdo->prepare('SELECT id,title,start_at_utc,status FROM video_meetings WHERE owner_user_id=? ORDER BY id DESC LIMIT 100');
    $stmt->execute([(int)$user['id']]);$meetings=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

$conversionTargets=[];
if(function_exists('profile_revenue_intelligence_v180')){
    try{
        $rev=profile_revenue_intelligence_v180($pdo,(int)$user['id']);
        foreach((array)($rev['top_targets']??[]) as $target){
            if(!is_array($target))continue;
            $kind=(string)($target['kind']??'product');
            $event=$kind==='booking'?'booking_converted':'product_converted';
            $meta=[
                'target_id'=>(int)($target['target_id']??0),
                'target_slug'=>(string)($target['target_slug']??''),
                'target_title'=>(string)($target['target_title']??''),
            ];
            $key=vp3_cognitive_value_profile_target_key_v2570($event,$meta);
            $conversionTargets[]=['key'=>$key,'label'=>(string)($target['target_title']??$key),'kind'=>$kind];
        }
    }catch(Throwable $e){}
}

$atRisk=array_values(array_filter((array)($valueRoi['goals']??[]),static fn(array $x): bool=>!empty($x['value_at_risk'])));

require_once __DIR__.'/includes/vp3-public.php';
vp3_public_header('Outcome Value & ROI — VP3','Explicit outcome value, verified realized results and AI-cost ROI.',['compact'=>true]);
?>
<link rel="stylesheet" href="<?= e(url('/outcome-value-v2570.css?v=2570')) ?>">
<main class="value-shell">
  <section class="value-hero">
    <div><small>VP3 Cognitive Runtime v25.70</small><h1>Outcome Value & ROI</h1><p>Define what outcomes are worth, keep expected value separate from verified results, and let VP3 use value as a bounded planning signal without inventing dollars or bypassing commitments and budgets.</p></div>
    <div class="value-actions"><a href="<?= e(url('/chat.php')) ?>">Agent Chat</a><a href="<?= e(url('/budget-governance.php')) ?>">Budget Governance</a></div>
  </section>

  <?php if($notice!==''): ?><div class="value-alert success" role="status"><?= e($notice) ?></div><?php endif; ?>
  <?php if($error!==''): ?><div class="value-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

  <section class="value-summary">
    <article><small>Value profiles</small><strong><?= (int)($valueRoi['counts']['profiles']??0) ?></strong></article>
    <article><small>Money profiles</small><strong><?= (int)($valueRoi['counts']['money_profiles']??0) ?></strong></article>
    <article><small>Score profiles</small><strong><?= (int)($valueRoi['counts']['score_profiles']??0) ?></strong></article>
    <article><small>Verified outcomes</small><strong><?= (int)($valueRoi['counts']['verified_outcomes']??0) ?></strong></article>
    <article><small>Value at risk</small><strong><?= (int)($valueRoi['counts']['value_at_risk']??0) ?></strong></article>
    <article><small>Planning adjusted</small><strong><?= (int)($valueRoi['counts']['planning_adjusted']??0) ?></strong></article>
  </section>

  <section class="value-panel">
    <header><div><small>Explicit value definition</small><h2>Create or update a value profile</h2></div><p>Money must be explicitly entered or tied to canonical Profile conversion evidence. Use a 0–100 score when the outcome is valuable but should not be represented as dollars.</p></header>
    <form method="post" class="value-form" id="valueProfileForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_profile"><input type="hidden" name="id" id="valueProfileId" value="0">
      <label><span>Profile name</span><input name="label" id="valueLabel" maxlength="190" placeholder="Launch outcome value"></label>
      <label><span>Scope</span><select name="scope_kind" id="valueScopeKind"><option value="goal">Goal</option><option value="workflow">Workflow</option><option value="project">Project / source key</option><option value="agent">Agent</option><option value="meeting">Meeting</option></select></label>
      <label><span>Scope value</span><input name="scope_key" id="valueScopeKey" maxlength="190" list="valueScopeOptions" required><datalist id="valueScopeOptions"></datalist></label>
      <label><span>Value type</span><select name="value_kind" id="valueKind"><option value="score">Outcome score</option><option value="money">Money</option></select></label>
      <label class="value-money-field"><span>Currency</span><input name="currency" id="valueCurrency" maxlength="3" value="USD"></label>
      <label class="value-money-field"><span>Expected monetary value</span><input name="expected_value" id="valueMoney" inputmode="decimal" placeholder="500.00"></label>
      <label class="value-score-field"><span>Expected outcome score</span><input name="expected_score" id="valueScore" type="number" min="0" max="100" value="50"></label>
      <label><span>Realization evidence</span><select name="realization_mode" id="valueMode"><option value="manual_confirmation">Manual confirmation</option><option value="verified_completion">Canonical verified completion</option><option value="profile_conversion">Profile conversion revenue</option></select></label>
      <label class="value-evidence-field"><span>Profile conversion target</span><input name="evidence_key" id="valueEvidenceKey" maxlength="190" list="valueConversionTargets" placeholder="product:id:123"></label>
      <div class="value-form-actions"><button type="submit">Save value profile</button><button type="button" class="secondary" id="valueReset">New profile</button></div>
    </form>
    <datalist id="valueGoalOptions"><?php foreach($goals as $g): ?><option value="<?= (int)$g['id'] ?>"><?= e(trim((string)($g['title']??''))?:trim((string)($g['goal']??''))) ?></option><?php endforeach; ?></datalist>
    <datalist id="valueWorkflowOptions"><?php foreach($workflows as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e((string)($r['title']??('Workflow #'.(int)$r['id']))) ?></option><?php endforeach; ?></datalist>
    <datalist id="valueProjectOptions"><?php foreach($projects as $p): ?><option value="<?= e((string)$p['source_key']) ?>"><?= e((string)$p['title']) ?></option><?php endforeach; ?></datalist>
    <datalist id="valueAgentOptions"><?php foreach($agents as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e((string)$a['display_name']) ?></option><?php endforeach; ?></datalist>
    <datalist id="valueMeetingOptions"><?php foreach($meetings as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e((string)($m['title']??('Meeting #'.(int)$m['id']))) ?></option><?php endforeach; ?></datalist>
    <datalist id="valueConversionTargets"><?php foreach($conversionTargets as $t): ?><option value="<?= e((string)$t['key']) ?>"><?= e((string)$t['label']) ?> · <?= e((string)$t['kind']) ?></option><?php endforeach; ?></datalist>
  </section>

  <section class="value-panel">
    <header><div><small>Current definitions</small><h2>Outcome value profiles</h2></div><p>AI-cost ROI is shown only for USD value with fully known AI cost. Declared completion value is not revenue.</p></header>
    <div class="value-list">
      <?php foreach($profiles as $profile):
        $id=(int)$profile['id'];$state=$profileState[$id]??['realization'=>['verified'=>false]];$realization=(array)($state['realization']??[]);
        $goal=$goalState[$id]??null;$money=(string)$profile['value_kind']==='money';
        $expected=$money?vp3_value_ui_money_v2570($profile['expected_value_micros'],(string)$profile['currency']):((int)$profile['expected_score'].'/100 score');
        $realized=!empty($realization['verified'])
          ?($money?vp3_value_ui_money_v2570($realization['value_micros']??null,(string)($realization['currency']??$profile['currency'])):((int)($realization['score_value']??0).'/100 score'))
          :'Not verified';
      ?>
      <article class="value-card <?= !empty($goal['value_at_risk'])?'risk':'' ?>">
        <div class="value-card-top"><span><?= e(ucwords(str_replace('_',' ',(string)$profile['scope_kind']))) ?> · <?= e((string)$profile['scope_key']) ?></span><span><?= e(ucwords(str_replace('_',' ',(string)$profile['realization_mode']))) ?></span></div>
        <h3><?= e((string)$profile['label']) ?></h3>
        <div class="value-metrics">
          <div><small>Expected</small><strong><?= e($expected) ?></strong></div>
          <div><small>Realized</small><strong><?= e($realized) ?></strong></div>
          <div><small>Expected AI-cost ROI</small><strong><?= $goal&&$goal['expected_roi_percent']!==null?e(number_format((float)$goal['expected_roi_percent'],1).'%'):'—' ?></strong></div>
          <div><small>Realized AI-cost ROI</small><strong><?= $goal&&$goal['realized_roi_percent']!==null?e(number_format((float)$goal['realized_roi_percent'],1).'%'):'—' ?></strong></div>
        </div>
        <p class="value-note"><?= !empty($realization['verified'])?'Verified by '.e(str_replace('_',' ',(string)$realization['source'])).' · '.e((string)$realization['verified_at']):'Awaiting '.e(str_replace('_',' ',(string)$profile['realization_mode'])) ?><?= !empty($realization['incomplete'])?' · conversion evidence exceeds bounded scan; narrow/reset the evidence baseline before using it for realized value':'' ?><?= !empty($goal['value_at_risk'])?' · value at risk':'' ?><?= !empty($goal['historical_unknown_cost_requests'])?' · ROI withheld: unknown AI pricing':'' ?></p>
        <div class="value-card-actions">
          <button type="button" class="secondary value-edit" data-profile="<?= e(json_encode($profile,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?>">Edit</button>
          <?php if(!empty($profile['is_active'])): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="disable_profile"><input type="hidden" name="profile_id" value="<?= $id ?>"><button type="submit" class="secondary">Disable</button></form><?php endif; ?>
        </div>
        <?php if(!empty($profile['is_active'])&&(string)$profile['realization_mode']==='manual_confirmation'): ?>
        <form method="post" class="value-realize-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="set_realized"><input type="hidden" name="profile_id" value="<?= $id ?>">
          <?php if($money): ?><input name="realized_value" inputmode="decimal" placeholder="Confirmed <?= e((string)$profile['currency']) ?> value"><?php else: ?><input name="realized_score" type="number" min="0" max="100" placeholder="Confirmed score 0–100"><?php endif; ?>
          <input name="note" maxlength="500" placeholder="Evidence note">
          <button type="submit">Confirm realized value</button>
        </form>
        <?php if((string)($realization['source']??'')==='user_confirmation'): ?>
        <form method="post" class="value-revoke-form"><?= csrf_field() ?><input type="hidden" name="action" value="revoke_realized"><input type="hidden" name="profile_id" value="<?= $id ?>"><button type="submit" class="secondary">Revoke manual confirmation</button></form>
        <?php endif; endif; ?>
      </article>
      <?php endforeach; ?>
      <?php if(!$profiles): ?><div class="value-empty">No outcome value profile exists yet. VP3 does not invent one.</div><?php endif; ?>
    </div>
  </section>

  <section class="value-panel">
    <header><div><small>Portfolio risk</small><h2>Value at risk</h2></div><p>These are explicit unrealized values attached to active goals that already have a material execution, commitment, approval, or budget risk.</p></header>
    <div class="value-list">
      <?php foreach($atRisk as $row): $p=(array)($row['profile']??[]); ?>
      <article class="value-card risk"><div class="value-card-top"><span>Value at risk</span><span><?= e((string)($row['profile_source']??'goal')) ?></span></div><h3><?= e((string)($row['title']??$p['label']??'Outcome')) ?></h3><p>Expected <?= (string)($p['value_kind']??'score')==='money'?e(vp3_value_ui_money_v2570($p['expected_value_micros']??null,(string)($p['currency']??'USD'))):e((string)((int)($p['expected_score']??0)).'/100 score') ?> · planning adjustment <?= e(number_format((float)($row['planning_adjustment']??0),3)) ?><?= !empty($row['budget_hard_hold'])?' · hard budget hold':'' ?><?= !empty($row['commitment_protected'])?' · protected commitment':'' ?></p></article>
      <?php endforeach; ?>
      <?php if(!$atRisk): ?><div class="value-empty">No explicit outcome value is currently at risk.</div><?php endif; ?>
    </div>
  </section>

  <section class="value-panel">
    <header><div><small>Advisory calibration</small><h2>Expected vs. realized</h2></div><p>Calibration is evidence only in v25.70. It never rewrites your expected value.</p></header>
    <div class="value-summary compact">
      <article><small>Money samples</small><strong><?= (int)($valueRoi['calibration']['money']['sample_count']??0) ?></strong><span><?= e(vp3_value_ui_ratio_v2570($valueRoi['calibration']['money']['median_realization_ratio']??null)) ?></span></article>
      <article><small>Score samples</small><strong><?= (int)($valueRoi['calibration']['score']['sample_count']??0) ?></strong><span><?= e(vp3_value_ui_ratio_v2570($valueRoi['calibration']['score']['median_realization_ratio']??null)) ?></span></article>
    </div>
  </section>

  <section class="value-panel">
    <header><div><small>Append-only evidence</small><h2>Realized-value history</h2></div><p>Manual confirmations and revocations are value evidence only; they do not alter canonical goal, meeting, revenue, billing, or execution records.</p></header>
    <div class="value-history">
      <?php foreach($events as $event): ?>
      <article><span><?= e(ucwords(str_replace('_',' ',(string)$event['event_type']))) ?></span><strong><?= e((string)$event['profile_label']) ?></strong><p><?= e((string)$event['note']) ?></p><small><?= e((string)$event['created_at']) ?> · <?= e((string)$event['scope_kind']) ?> <?= e((string)$event['scope_key']) ?><?= $event['value_micros']!==null?' · '.e(vp3_value_ui_money_v2570((int)$event['value_micros'],(string)$event['currency'])):'' ?><?= $event['score_value']!==null?' · '.(int)$event['score_value'].'/100':'' ?></small></article>
      <?php endforeach; ?>
      <?php if(!$events): ?><div class="value-empty">Manual realized-value confirmations will appear here.</div><?php endif; ?>
    </div>
  </section>
</main>

<script>
(() => {
  'use strict';
  const form=document.getElementById('valueProfileForm');
  const scope=document.getElementById('valueScopeKind');
  const key=document.getElementById('valueScopeKey');
  const list=document.getElementById('valueScopeOptions');
  const kind=document.getElementById('valueKind');
  const mode=document.getElementById('valueMode');
  if(!form||!scope||!key||!list||!kind||!mode)return;
  const scopeSources={
    goal:document.getElementById('valueGoalOptions'),workflow:document.getElementById('valueWorkflowOptions'),
    project:document.getElementById('valueProjectOptions'),agent:document.getElementById('valueAgentOptions'),
    meeting:document.getElementById('valueMeetingOptions')
  };
  function syncScope(){
    list.innerHTML=scopeSources[scope.value]?.innerHTML||'';
    key.placeholder=scope.value==='project'?'Existing workflow source key':'Numeric '+scope.value+' id';
    const verified=mode.querySelector('option[value="verified_completion"]');
    const completionAllowed=['goal','workflow','meeting'].includes(scope.value);
    if(verified)verified.disabled=!completionAllowed;
    if(!completionAllowed&&mode.value==='verified_completion')mode.value='manual_confirmation';
    syncMode();
  }
  function syncKind(){
    document.querySelectorAll('.value-money-field').forEach(el=>el.hidden=kind.value!=='money');
    document.querySelectorAll('.value-score-field').forEach(el=>el.hidden=kind.value!=='score');
    if(mode.value==='profile_conversion'&&kind.value!=='money')mode.value='manual_confirmation';
  }
  function syncMode(){
    document.querySelectorAll('.value-evidence-field').forEach(el=>el.hidden=mode.value!=='profile_conversion');
    if(mode.value==='profile_conversion'&&kind.value!=='money'){kind.value='money';syncKind();}
  }
  function reset(){
    form.reset();document.getElementById('valueProfileId').value='0';scope.value='goal';kind.value='score';
    mode.value='manual_confirmation';document.getElementById('valueCurrency').value='USD';document.getElementById('valueScore').value='50';
    syncScope();syncKind();syncMode();
  }
  scope.addEventListener('change',syncScope);kind.addEventListener('change',syncKind);mode.addEventListener('change',syncMode);
  document.getElementById('valueReset')?.addEventListener('click',reset);
  document.querySelectorAll('.value-edit').forEach(btn=>btn.addEventListener('click',()=>{
    const p=JSON.parse(btn.dataset.profile||'{}');
    document.getElementById('valueProfileId').value=Number(p.id||0);document.getElementById('valueLabel').value=p.label||'';
    scope.value=p.scope_kind||'goal';syncScope();key.value=p.scope_key||'';kind.value=p.value_kind||'score';
    document.getElementById('valueCurrency').value=p.currency||'USD';
    document.getElementById('valueMoney').value=p.expected_value_micros===null||p.expected_value_micros===undefined?'':(Number(p.expected_value_micros)/1000000).toFixed(6).replace(/0+$/,'').replace(/\.$/,'');
    document.getElementById('valueScore').value=p.expected_score===null||p.expected_score===undefined?'':Number(p.expected_score);
    mode.value=p.realization_mode||'manual_confirmation';document.getElementById('valueEvidenceKey').value=p.evidence_key||'';
    syncKind();syncMode();form.scrollIntoView({behavior:'smooth',block:'start'});
  }));
  syncScope();syncKind();syncMode();
})();
</script>
<?php vp3_public_footer(); ?>
