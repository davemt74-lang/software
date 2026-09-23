<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_permission('account.access');

$pdo=db();
$user=current_user();
if(!$pdo||!$user)redirect(url('/login.php'));
if(!vp3_cognitive_decision_schema_ready_v2580($pdo))redirect(url('/upgrade.php'));

$state=vp3_cognitive_decision_refresh_v2580($pdo,$user);
$cal=(array)($state['calibration']??[]);
$accuracy=(array)($state['accuracy']??[]);
$recent=(array)($state['recent']??[]);

function vp3_decision_ui_factor_v2580(array $factor): string
{
    $value=(float)($factor['factor']??1.0);
    $samples=max(0,(int)($factor['sample_count']??0));
    return number_format($value,2).'× · '.$samples.' sample'.($samples===1?'':'s')
        .(!empty($factor['calibrated'])?' · calibrated':' · baseline');
}

function vp3_decision_ui_duration_v2580(mixed $seconds): string
{
    if($seconds===null||$seconds==='')return '—';
    $seconds=abs((int)$seconds);
    if($seconds<3600)return number_format($seconds/60,0).' min';
    if($seconds<86400)return number_format($seconds/3600,1).' hr';
    return number_format($seconds/86400,1).' days';
}

function vp3_decision_ui_ratio_v2580(mixed $ratio): string
{
    return $ratio===null||$ratio===''?'—':number_format((float)$ratio,2).'× actual / predicted';
}

function vp3_decision_ui_meta_v2580(string $json): array
{
    $row=json_decode($json,true);
    return is_array($row)?$row:[];
}

require_once __DIR__.'/includes/vp3-public.php';
vp3_public_header('Decision Calibration — VP3','Portfolio forecast, cost, token and value calibration evidence.',['compact'=>true]);
?>
<link rel="stylesheet" href="<?= e(url('/decision-calibration-v2580.css?v=2580')) ?>">
<main class="decision-shell">
  <section class="decision-hero">
    <div>
      <small>VP3 Cognitive Runtime v25.80</small>
      <h1>Portfolio Decision Calibration</h1>
      <p>See how prior portfolio predictions compare with verified outcomes. VP3 keeps raw estimates, waits for enough independent evidence, and applies only bounded numeric calibration.</p>
    </div>
    <div class="decision-actions">
      <a href="<?= e(url('/chat.php')) ?>">Agent Chat</a>
      <a href="<?= e(url('/outcome-value.php')) ?>">Outcome Value</a>
      <a href="<?= e(url('/budget-governance.php')) ?>">Budget Governance</a>
    </div>
  </section>

  <section class="decision-summary">
    <article><small>Settled goals</small><strong><?= (int)($accuracy['settled_goals']??0) ?></strong></article>
    <article><small>Forecast samples</small><strong><?= (int)($accuracy['forecast_samples']??0) ?></strong></article>
    <article><small>Window hit rate</small><strong><?= ($accuracy['forecast_window_hit_rate']??null)===null?'—':e(number_format((float)$accuracy['forecast_window_hit_rate']*100,0).'%') ?></strong></article>
    <article><small>Calibrated median error</small><strong><?= e(vp3_decision_ui_duration_v2580($accuracy['median_abs_forecast_error_seconds']??null)) ?></strong></article>
    <article><small>Raw median error</small><strong><?= e(vp3_decision_ui_duration_v2580($accuracy['median_abs_raw_forecast_error_seconds']??null)) ?></strong></article>
    <article><small>Evidence window</small><strong><?= (int)($cal['window_days']??180) ?>d</strong></article>
  </section>

  <section class="decision-panel">
    <header>
      <div><small>Bounded factors</small><h2>Current calibration</h2></div>
      <p>Each factor stays at 1.00× until at least <?= (int)($cal['minimum_samples']??5) ?> independent evidence units are available: settled goals for forecast/cost/tokens, verified value profiles for value reliability.</p>
    </header>
    <div class="decision-factor-grid">
      <article><span>Forecast · Cloud</span><strong><?= e(vp3_decision_ui_factor_v2580((array)($cal['forecast']['cloud']??[]))) ?></strong><p>Bounds 0.75–1.35×.</p></article>
      <article><span>Forecast · HomeServer</span><strong><?= e(vp3_decision_ui_factor_v2580((array)($cal['forecast']['homeserver']??[]))) ?></strong><p>Bounds 0.75–1.35×.</p></article>
      <article><span>Cost · Cloud</span><strong><?= e(vp3_decision_ui_factor_v2580((array)($cal['cost']['cloud']??[]))) ?></strong><p>Calibrates projected remaining AI cost only.</p></article>
      <article><span>Tokens · Cloud</span><strong><?= e(vp3_decision_ui_factor_v2580((array)($cal['tokens']['cloud']??[]))) ?></strong><p>Calibrates projected remaining Cloud tokens only.</p></article>
      <article><span>Value reliability · Money</span><strong><?= e(vp3_decision_ui_factor_v2580((array)($cal['value']['money']??[]))) ?></strong><p>Bounds 0.70–1.15×; user expected value is never rewritten.</p></article>
      <article><span>Value reliability · Score</span><strong><?= e(vp3_decision_ui_factor_v2580((array)($cal['value']['score']??[]))) ?></strong><p>Bounds 0.70–1.15×; scales planning pressure only.</p></article>
    </div>
  </section>

  <section class="decision-panel">
    <header>
      <div><small>Accuracy</small><h2>Observed prediction quality</h2></div>
      <p>These are descriptive comparisons, not claims that reservations or replans caused an outcome.</p>
    </header>
    <div class="decision-factor-grid">
      <article><span>Cost projection</span><strong><?= e(vp3_decision_ui_ratio_v2580($accuracy['median_cost_ratio']??null)) ?></strong><p><?= (int)($accuracy['cost_samples']??0) ?> valid settled goals.</p></article>
      <article><span>Token projection</span><strong><?= e(vp3_decision_ui_ratio_v2580($accuracy['median_token_ratio']??null)) ?></strong><p><?= (int)($accuracy['token_samples']??0) ?> valid settled goals.</p></article>
      <article><span>Value realization</span><strong><?= e(vp3_decision_ui_ratio_v2580($accuracy['median_value_realization_ratio']??null)) ?></strong><p><?= (int)($accuracy['value_samples']??0) ?> verified explicit-value samples.</p></article>
      <article><span>Reservation-associated forecast error</span><strong><?= e(vp3_decision_ui_duration_v2580($accuracy['reservation_median_abs_forecast_error_seconds']??null)) ?></strong><p><?= (int)($accuracy['reservation_samples']??0) ?> settled decisions with a reservation state.</p></article>
    </div>
  </section>

  <section class="decision-panel">
    <header>
      <div><small>Decision evidence</small><h2>Recent snapshots & settlements</h2></div>
      <p>Snapshots and settlements are append-only calibration evidence. Canonical goal outcomes, usage, value and execution remain in their existing systems.</p>
    </header>
    <div class="decision-list">
      <?php foreach($recent as $row):
        $meta=vp3_decision_ui_meta_v2580((string)($row['metadata_json']??''));
        $settled=trim((string)($row['settled_at']??''))!=='';
      ?>
      <article class="<?= $settled?'settled':'open' ?>">
        <div class="decision-row-top">
          <span><?= $settled?'Settled':'Open snapshot' ?> · <?= e((string)($row['executor']??'cloud')) ?> · goal #<?= (int)($row['goal_id']??0) ?></span>
          <span><?= e((string)($row['captured_at']??'')) ?></span>
        </div>
        <h3><?= e((string)($meta['title']??('Goal #'.(int)($row['goal_id']??0)))) ?></h3>
        <div class="decision-row-metrics">
          <div><small>Raw likely</small><strong><?= e((string)($row['raw_forecast_likely_at']??'—')) ?></strong></div>
          <div><small>Calibrated likely</small><strong><?= e((string)($row['forecast_likely_at']??'—')) ?></strong></div>
          <div><small>Forecast factor</small><strong><?= e(number_format((float)($row['forecast_calibration_factor']??1),2).'×') ?></strong></div>
          <div><small>Result</small><strong><?= $settled?e(vp3_decision_ui_duration_v2580($row['forecast_error_seconds']??null).' error'):'Awaiting verified goal outcome' ?></strong></div>
        </div>
        <p><?= !empty($row['budget_hard_hold'])?'Hard budget hold · ':'' ?><?= !empty($row['commitment_protected'])?'protected commitment · ':'' ?><?= trim((string)($row['reservation_state']??''))!==''?'reservation '.e((string)$row['reservation_state']).' · ':'' ?><?= trim((string)($row['replan_action']??''))!==''?'replan '.e(str_replace('_',' ',(string)$row['replan_action'])):'' ?></p>
        <?php if($settled): ?>
        <small>Actual completion <?= e((string)$row['actual_completion_at']) ?> · raw error <?= e(vp3_decision_ui_duration_v2580($row['raw_forecast_error_seconds']??null)) ?> · calibrated error <?= e(vp3_decision_ui_duration_v2580($row['forecast_error_seconds']??null)) ?> · window <?= (int)($row['forecast_window_hit']??0)?'hit':'miss' ?> · cost <?= e(vp3_decision_ui_ratio_v2580($row['cost_error_ratio']??null)) ?> · tokens <?= e(vp3_decision_ui_ratio_v2580($row['token_error_ratio']??null)) ?> · value <?= e(vp3_decision_ui_ratio_v2580($row['value_realization_ratio']??null)) ?></small>
        <?php else: ?>
        <small>Raw projected remaining cost <?= ($row['raw_projected_remaining_cost_micros']??null)===null?'unknown':e('$'.number_format((int)$row['raw_projected_remaining_cost_micros']/1000000,4)) ?> · calibrated <?= ($row['projected_remaining_cost_micros']??null)===null?'unknown':e('$'.number_format((int)$row['projected_remaining_cost_micros']/1000000,4)) ?> · raw tokens <?= ($row['raw_projected_remaining_tokens']??null)===null?'unknown':number_format((int)$row['raw_projected_remaining_tokens']) ?> · calibrated <?= ($row['projected_remaining_tokens']??null)===null?'unknown':number_format((int)$row['projected_remaining_tokens']) ?></small>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
      <?php if(!$recent): ?><div class="decision-empty">No decision snapshots exist yet. They are captured automatically from active portfolio projections.</div><?php endif; ?>
    </div>
  </section>

  <section class="decision-note">
    <strong>Authority boundary</strong>
    <p>v25.80 calibrates numbers only. It does not change a budget cap, expected value, commitment, executor, deadline, approval, workflow eligibility, worker lease, or canonical outcome.</p>
  </section>
</main>
<?php vp3_public_footer(); ?>
