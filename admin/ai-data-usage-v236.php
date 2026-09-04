<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('ai.manage');

$pdo=db();
if(!$pdo){flash('error','Database unavailable.');redirect(url('/admin/index.php'));}
try{
    if(!user_data_usage_schema_ready_v236($pdo))user_data_usage_ensure_schema_v236($pdo);
    if(!shared_knowledge_index_schema_ready_v236($pdo))shared_knowledge_index_ensure_schema_v236($pdo);
}catch(Throwable $e){flash('error','AI data usage storage is not ready. Run the database upgrade.');redirect(url('/upgrade.php'));}

$usage=user_data_usage_admin_state_v236($pdo,200);
$indexStats=['total'=>0,'active'=>0,'owners'=>0];
try{
    $indexStats=$pdo->query("SELECT COUNT(*) total,SUM(is_indexed=1 AND revoked_at IS NULL) active,COUNT(DISTINCT IF(is_indexed=1 AND revoked_at IS NULL,owner_user_id,NULL)) owners FROM shared_knowledge_index")->fetch()?:$indexStats;
}catch(Throwable $e){}

$adminTitle='AI Data Usage';
$adminActive='ai-data-usage';
require __DIR__ . '/_header.php';
?>
<style>
.ai-usage-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}.ai-usage-stat{padding:16px;border:1px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(255,255,255,.025)}.ai-usage-stat strong{display:block;font-size:1.35rem}.ai-usage-stat span{display:block;margin-top:4px;color:#8c847d;font-size:.72rem}.ai-usage-table-wrap{overflow:auto}.ai-usage-table{width:100%;min-width:1050px;border-collapse:collapse}.ai-usage-table th,.ai-usage-table td{padding:10px 9px;border-bottom:1px solid rgba(255,255,255,.07);text-align:left;vertical-align:top;font-size:.72rem}.ai-usage-table th{color:#817a74;font-size:.63rem;text-transform:uppercase;letter-spacing:.06em}.ai-usage-table td small{display:block;margin-top:2px;color:#756e68}.ai-usage-note{line-height:1.6}@media(max-width:800px){.ai-usage-stats{grid-template-columns:1fr 1fr}}@media(max-width:480px){.ai-usage-stats{grid-template-columns:1fr}}
</style>
<div class="panel">
  <div style="display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap">
    <div>
      <span class="status">Transparency Ledger</span>
      <h2>AI Data Usage</h2>
      <p class="muted ai-usage-note">This ledger records data that actually entered an AI context: the data owner, requester account, agent identity, resource and time. It intentionally does not duplicate the requester’s prompt or conversation text.</p>
    </div>
    <a class="btn" href="<?= e(url('/admin/ai.php')) ?>">AI Settings</a>
  </div>
</div>

<div class="ai-usage-stats">
  <article class="ai-usage-stat"><strong><?= number_format((int)$usage['total']) ?></strong><span>Total data retrievals</span></article>
  <article class="ai-usage-stat"><strong><?= number_format((int)$usage['shared_total']) ?></strong><span>Cross-user/network uses</span></article>
  <article class="ai-usage-stat"><strong><?= number_format((int)$usage['last_30_days']) ?></strong><span>Retrievals in last 30 days</span></article>
  <article class="ai-usage-stat"><strong><?= number_format((int)($indexStats['active']??0)) ?></strong><span>Active shared knowledge pointers</span></article>
</div>

<div class="panel">
  <h2>Recent Retrievals</h2>
  <p class="muted">Newest 200 authorized retrieval events. Revoking sharing prevents future cross-user retrieval even if an older ledger record remains for accountability.</p>
  <div class="ai-usage-table-wrap">
    <table class="ai-usage-table">
      <thead><tr><th>When</th><th>Data owner</th><th>Requester</th><th>Agent</th><th>Resource</th><th>Access</th><th>Source</th></tr></thead>
      <tbody>
      <?php foreach($usage['recent'] as $row): ?>
        <tr>
          <td><?= e((string)$row['created_at']) ?></td>
          <td><strong><?= e((string)$row['owner_name']) ?></strong><small><?= e((string)$row['owner_email']) ?></small></td>
          <td><?php if((int)($row['requester_user_id']??0)>0): ?><strong><?= e((string)($row['requester_name']?:'User #'.$row['requester_user_id'])) ?></strong><small><?= e((string)$row['requester_email']) ?></small><?php else: ?><span>System / anonymous</span><?php endif; ?></td>
          <td><strong><?= e((string)$row['agent_name_snapshot']) ?></strong><small><?= e((string)$row['agent_kind']) ?><?= (int)($row['agent_id']??0)>0?' · #'.(int)$row['agent_id']:'' ?></small></td>
          <td><strong><?= e((string)$row['resource_title_snapshot']) ?></strong><small><?= e((string)$row['resource_label']) ?> · <?= e((string)$row['resource_id']) ?></small></td>
          <td><?= e((string)$row['access_class']) ?></td>
          <td><code><?= e((string)$row['source_key']) ?></code></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$usage['recent']): ?><tr><td colspan="7">No AI data retrievals have been logged yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <h2>Shared Knowledge Index</h2>
  <p class="muted ai-usage-note"><?= number_format((int)($indexStats['active']??0)) ?> active pointer<?= (int)($indexStats['active']??0)===1?'':'s' ?> from <?= number_format((int)($indexStats['owners']??0)) ?> owner<?= (int)($indexStats['owners']??0)===1?'':'s' ?>. The index stores ownership, knowledge ID, title/search tags, permission snapshot, content hash and future embedding reference—not the original knowledge body or chunks.</p>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
