<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/crm-v180.php';

$user = crm_v180_require_admin();
$pdo = db();
if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}

try {
    crm_v180_ensure_schema($pdo);
    // Existing demo submissions are backfilled without creating a burst of
    // historical notifications or Agent Chat messages.
    crm_v180_import_demo_messages($pdo, 500);
} catch (Throwable $e) {
    flash('error', 'CRM setup failed: ' . $e->getMessage());
    redirect(url('/admin/index.php'));
}

$view = trim((string)($_GET['view'] ?? 'dashboard'));
if (!in_array($view, ['dashboard', 'leads', 'pipeline', 'tasks'], true)) $view = 'dashboard';
$stageFilter = trim((string)($_GET['stage'] ?? 'all'));
if ($stageFilter !== 'all' && !array_key_exists($stageFilter, crm_v180_stages())) $stageFilter = 'all';
$search = trim((string)($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired.');
        redirect(url('/admin/crm.php?view=' . rawurlencode($view)));
    }

    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'complete_task') {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $stmt = $pdo->prepare(
                "SELECT lead_id,title FROM crm_tasks WHERE id=? AND status<>'completed' LIMIT 1"
            );
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
            if (!$task) throw new RuntimeException('Task not found.');

            $pdo->prepare(
                "UPDATE crm_tasks SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=?"
            )->execute([$taskId]);
            crm_v180_activity(
                $pdo,
                (int)$task['lead_id'],
                'task_completed',
                'Task completed: ' . (string)$task['title'],
                (int)$user['id'],
                ['task_id' => $taskId]
            );
            flash('notice', 'CRM task completed.');
        } elseif ($action === 'assign_self') {
            $leadId = (int)($_POST['lead_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id,assigned_user_id FROM crm_leads WHERE id=? LIMIT 1');
            $stmt->execute([$leadId]);
            $lead = $stmt->fetch();
            if (!$lead) throw new RuntimeException('Lead not found.');

            $pdo->prepare(
                'UPDATE crm_leads SET assigned_user_id=?,updated_at=NOW() WHERE id=?'
            )->execute([(int)$user['id'], $leadId]);
            crm_v180_activity(
                $pdo,
                $leadId,
                'assignment',
                'Lead assigned to ' . (string)$user['display_name'] . '.',
                (int)$user['id']
            );
            flash('notice', 'Lead assigned to you.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('/admin/crm.php?view=' . rawurlencode($view)));
}

$summary = [
    'all' => 0,
    'new' => 0,
    'qualified' => 0,
    'demo_scheduled' => 0,
    'trial' => 0,
    'won' => 0,
    'followups_due' => 0,
    'tasks_open' => 0,
];

$row = $pdo->query(
    "SELECT COUNT(*) AS all_count,
            SUM(stage='new') AS new_count,
            SUM(stage='qualified') AS qualified_count,
            SUM(stage='demo_scheduled') AS demo_count,
            SUM(stage='trial') AS trial_count,
            SUM(stage='won') AS won_count,
            SUM(next_follow_up_at IS NOT NULL AND next_follow_up_at<=NOW() AND stage NOT IN ('won','lost','archived')) AS followups_due
     FROM crm_leads"
)->fetch() ?: [];
$summary['all'] = (int)($row['all_count'] ?? 0);
$summary['new'] = (int)($row['new_count'] ?? 0);
$summary['qualified'] = (int)($row['qualified_count'] ?? 0);
$summary['demo_scheduled'] = (int)($row['demo_count'] ?? 0);
$summary['trial'] = (int)($row['trial_count'] ?? 0);
$summary['won'] = (int)($row['won_count'] ?? 0);
$summary['followups_due'] = (int)($row['followups_due'] ?? 0);
$summary['tasks_open'] = (int)$pdo->query("SELECT COUNT(*) FROM crm_tasks WHERE status='open'")->fetchColumn();

$recentLeads = $pdo->query(
    "SELECT l.*,c.name,c.email,c.phone,c.company,u.display_name AS assigned_name
     FROM crm_leads l
     JOIN crm_contacts c ON c.id=l.contact_id
     LEFT JOIN users u ON u.id=l.assigned_user_id
     ORDER BY l.created_at DESC,l.id DESC LIMIT 12"
)->fetchAll() ?: [];

$leadWhere = [];
$leadParams = [];
if ($stageFilter !== 'all') {
    $leadWhere[] = 'l.stage=?';
    $leadParams[] = $stageFilter;
}
if ($search !== '') {
    $leadWhere[] = '(c.name LIKE ? OR c.email LIKE ? OR c.company LIKE ? OR l.role_interest LIKE ?)';
    $needle = '%' . $search . '%';
    array_push($leadParams, $needle, $needle, $needle, $needle);
}

$sql =
    "SELECT l.*,c.name,c.email,c.phone,c.company,u.display_name AS assigned_name
     FROM crm_leads l
     JOIN crm_contacts c ON c.id=l.contact_id
     LEFT JOIN users u ON u.id=l.assigned_user_id" .
    ($leadWhere ? ' WHERE ' . implode(' AND ', $leadWhere) : '') .
    ' ORDER BY l.updated_at DESC,l.id DESC LIMIT 250';
$leadStmt = $pdo->prepare($sql);
$leadStmt->execute($leadParams);
$leads = $leadStmt->fetchAll() ?: [];

$pipeline = [];
foreach (crm_v180_stages() as $key => $label) $pipeline[$key] = [];
$pipelineRows = $pdo->query(
    "SELECT l.*,c.name,c.email,c.company,u.display_name AS assigned_name
     FROM crm_leads l
     JOIN crm_contacts c ON c.id=l.contact_id
     LEFT JOIN users u ON u.id=l.assigned_user_id
     WHERE l.stage<>'archived'
     ORDER BY l.updated_at DESC,l.id DESC"
)->fetchAll() ?: [];
foreach ($pipelineRows as $lead) {
    $stage = (string)$lead['stage'];
    if (isset($pipeline[$stage])) $pipeline[$stage][] = $lead;
}

$tasks = $pdo->query(
    "SELECT t.*,l.stage,c.name,c.company,u.display_name AS assigned_name
     FROM crm_tasks t
     JOIN crm_leads l ON l.id=t.lead_id
     JOIN crm_contacts c ON c.id=l.contact_id
     LEFT JOIN users u ON u.id=t.assigned_user_id
     ORDER BY (t.status='open') DESC,(t.due_at IS NULL),t.due_at ASC,t.id DESC
     LIMIT 250"
)->fetchAll() ?: [];

$attentionItems = $view === 'dashboard'
    ? crm_v180_agent_opportunities($user, date('Y-m-d H:i:s', time() - 7 * 86400))
    : [];

$adminTitle = 'Book Demo CRM';
$adminActive = 'crm';
require __DIR__ . '/_header.php';
?>
<style>
.crm-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 16px}.crm-tabs{display:flex;gap:6px;flex-wrap:wrap}.crm-tabs a{display:inline-flex;align-items:center;min-height:34px;padding:7px 11px;border:1px solid var(--admin-line);border-radius:7px;background:#fff;color:#66707a;text-decoration:none;font-size:.68rem;font-weight:800}.crm-tabs a:hover{background:#f4f6f8;color:#111318}.crm-tabs a.active{background:#111318;color:#fff;border-color:#111318}.crm-stat-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin:0 0 18px}.crm-stat{padding:16px;border:1px solid var(--admin-line);border-radius:10px;background:#fff}.crm-stat span{display:block;color:#7c8590;font-size:.6rem;font-weight:900;letter-spacing:.06em;text-transform:uppercase}.crm-stat strong{display:block;margin-top:6px;color:#111318;font-size:1.55rem;line-height:1;font-weight:850;letter-spacing:-.04em}.crm-two{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(280px,.7fr);gap:14px}.crm-card{padding:18px;border:1px solid var(--admin-line);border-radius:10px;background:#fff}.crm-card h3{margin:0 0 14px;color:#111318;font-size:.95rem}.crm-status{display:inline-flex;align-items:center;padding:4px 7px;border-radius:999px;background:#f0f2f4;color:#4d5660;font-size:.6rem;font-weight:850}.crm-priority-high,.crm-priority-urgent{font-weight:850;color:#8f4f3f}.crm-meta{color:#7c8590;font-size:.64rem}.crm-search{display:grid;grid-template-columns:minmax(220px,1fr) 190px auto;gap:8px;align-items:end;margin:0 0 14px}.crm-search input,.crm-search select{min-height:38px}.crm-pipeline{display:grid;grid-template-columns:repeat(7,minmax(220px,1fr));gap:10px;overflow-x:auto;padding:2px 2px 12px}.crm-column{min-width:220px;padding:10px;border:1px solid var(--admin-line);border-radius:10px;background:#f8fafb}.crm-column-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;color:#4f5862;font-size:.65rem;font-weight:900}.crm-badge{min-width:20px;height:20px;padding:0 6px;display:grid;place-items:center;border-radius:999px;background:#e8ecef;color:#505862;font-size:.58rem}.crm-pipeline-card{display:block;margin-bottom:8px;padding:11px;border:1px solid #e1e5e9;border-radius:8px;background:#fff;color:#111318;text-decoration:none}.crm-pipeline-card:hover{border-color:#bfc7cf;box-shadow:0 8px 20px rgba(17,19,24,.05)}.crm-pipeline-card strong{display:block;font-size:.7rem}.crm-pipeline-card span{display:block;margin-top:4px;color:#7a838e;font-size:.61rem;line-height:1.35}.crm-attention{display:grid;gap:8px}.crm-attention a{display:block;padding:11px;border:1px solid #e1e5e9;border-radius:8px;background:#fafbfb;color:#111318;text-decoration:none}.crm-attention a:hover{background:#f4f6f8}.crm-attention strong{display:block;font-size:.69rem}.crm-attention span{display:block;margin-top:4px;color:#7a838e;font-size:.62rem;line-height:1.4}.crm-empty{padding:24px;text-align:center;color:#7d8691;font-size:.68rem}.crm-task-overdue td:first-child{box-shadow:inset 3px 0 0 #b56554}.crm-page-summary{display:flex;gap:8px;flex-wrap:wrap}.crm-page-summary span{display:inline-flex;align-items:center;min-height:30px;padding:5px 8px;border-radius:999px;background:#f0f2f4;color:#67707b;font-size:.62rem;font-weight:750}
@media(max-width:1180px){.crm-stat-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.crm-two{grid-template-columns:1fr}}@media(max-width:720px){.crm-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.crm-search{grid-template-columns:1fr}.crm-search>*{width:100%}}
</style>

<div class="content-library-heading">
  <div>
    <span class="status">Sales pipeline</span>
    <h2>Book Demo CRM</h2>
    <p class="muted">Book Demo submissions flow directly into this lead pipeline for qualification, scheduling, trials, follow-up and conversion.</p>
  </div>
  <div class="actions">
    <a class="btn" href="<?= e(url('/book-demo.php')) ?>" target="_blank" rel="noopener">View Book Demo Form ↗</a>
  </div>
</div>

<div class="crm-toolbar">
  <nav class="crm-tabs" aria-label="CRM views">
    <?php foreach (['dashboard'=>'Overview','leads'=>'Leads','pipeline'=>'Pipeline','tasks'=>'Tasks'] as $key=>$label): ?>
      <a class="<?= $view===$key?'active':'' ?>" href="<?= e(url('/admin/crm.php?view='.$key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="crm-page-summary"><span><?= number_format($summary['all']) ?> total leads</span><span><?= number_format($summary['tasks_open']) ?> open tasks</span></div>
</div>

<div class="crm-stat-grid">
  <div class="crm-stat"><span>New leads</span><strong><?= $summary['new'] ?></strong></div>
  <div class="crm-stat"><span>Qualified</span><strong><?= $summary['qualified'] ?></strong></div>
  <div class="crm-stat"><span>Demos scheduled</span><strong><?= $summary['demo_scheduled'] ?></strong></div>
  <div class="crm-stat"><span>Trials</span><strong><?= $summary['trial'] ?></strong></div>
  <div class="crm-stat"><span>Follow-ups due</span><strong><?= $summary['followups_due'] ?></strong></div>
  <div class="crm-stat"><span>Won</span><strong><?= $summary['won'] ?></strong></div>
</div>

<?php if ($view === 'dashboard'): ?>
<div class="crm-two">
  <section class="crm-card">
    <div class="admin-card-head"><div><h3>Recent Book Demo leads</h3><p>Newest inquiries entering the sales workflow.</p></div><a class="btn" href="<?= e(url('/admin/crm.php?view=leads')) ?>">View all</a></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Lead</th><th>Stage</th><th>Role / team</th><th>Owner</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recentLeads as $lead): ?>
          <tr>
            <td><strong><?= e($lead['name']) ?></strong><br><span class="crm-meta"><?= e($lead['company'] ?: $lead['email']) ?></span></td>
            <td><span class="crm-status"><?= e(crm_v180_stages()[(string)$lead['stage']] ?? (string)$lead['stage']) ?></span></td>
            <td><?= e($lead['role_interest'] ?: '—') ?><br><span class="crm-meta"><?= e($lead['team_size'] ?: '—') ?></span></td>
            <td><?= e($lead['assigned_name'] ?: 'Unassigned') ?></td>
            <td><?= e(date('M j, g:i A', strtotime((string)$lead['created_at']))) ?></td>
            <td><a class="btn" href="<?= e(url('/admin/crm-lead.php?id='.(int)$lead['id'])) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recentLeads): ?><tr><td colspan="6" class="crm-empty">No Book Demo leads yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <aside class="crm-card">
    <div class="admin-card-head"><div><h3>Needs attention</h3><p>Follow-up opportunities surfaced from the existing CRM Agent logic.</p></div></div>
    <div class="crm-attention">
      <?php foreach ($attentionItems as $item): ?>
        <a href="<?= e($item['target_url']) ?>"><strong><?= e($item['title']) ?></strong><span><?= e($item['body']) ?></span></a>
      <?php endforeach; ?>
      <?php if (!$attentionItems): ?><div class="crm-empty">Nothing urgent right now.</div><?php endif; ?>
    </div>
  </aside>
</div>

<?php elseif ($view === 'leads'): ?>
<section class="admin-card">
  <div class="admin-card-head"><div><h3>Lead directory</h3><p>Search and filter every Book Demo lead without leaving the CRM.</p></div></div>
  <form class="crm-search" method="get">
    <input type="hidden" name="view" value="leads">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, email, company or role">
    <select name="stage"><option value="all">All stages</option><?php foreach (crm_v180_stages() as $key=>$label): ?><option value="<?= e($key) ?>" <?= $stageFilter===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit">Filter</button>
  </form>
  <div class="table-wrap"><table><thead><tr><th>Lead</th><th>Stage</th><th>Priority</th><th>Role / team</th><th>Assigned</th><th>Next follow-up</th><th>Updated</th><th></th></tr></thead><tbody>
  <?php foreach ($leads as $lead): ?><tr><td><strong><?= e($lead['name']) ?></strong><br><span class="crm-meta"><?= e($lead['company'] ?: $lead['email']) ?></span></td><td><span class="crm-status"><?= e(crm_v180_stages()[(string)$lead['stage']] ?? $lead['stage']) ?></span></td><td class="crm-priority-<?= e($lead['priority']) ?>"><?= e(ucfirst((string)$lead['priority'])) ?></td><td><?= e($lead['role_interest'] ?: '—') ?><br><span class="crm-meta"><?= e($lead['team_size'] ?: '—') ?></span></td><td><?= e($lead['assigned_name'] ?: 'Unassigned') ?></td><td><?= $lead['next_follow_up_at'] ? e(date('M j, g:i A',strtotime((string)$lead['next_follow_up_at']))) : '—' ?></td><td><?= e(date('M j',strtotime((string)$lead['updated_at']))) ?></td><td><a class="btn" href="<?= e(url('/admin/crm-lead.php?id='.(int)$lead['id'])) ?>">Open</a></td></tr><?php endforeach; ?>
  <?php if (!$leads): ?><tr><td colspan="8" class="crm-empty">No leads match this view.</td></tr><?php endif; ?></tbody></table></div>
</section>

<?php elseif ($view === 'pipeline'): ?>
<section class="admin-card">
  <div class="admin-card-head"><div><h3>Sales pipeline</h3><p>Book Demo leads grouped by their current sales stage.</p></div></div>
  <div class="crm-pipeline">
  <?php foreach (crm_v180_stages() as $stage=>$label): if ($stage === 'archived') continue; ?>
    <section class="crm-column"><div class="crm-column-head"><span><?= e($label) ?></span><span class="crm-badge"><?= count($pipeline[$stage]) ?></span></div>
      <?php foreach (array_slice($pipeline[$stage],0,40) as $lead): ?><a class="crm-pipeline-card" href="<?= e(url('/admin/crm-lead.php?id='.(int)$lead['id'])) ?>"><strong><?= e($lead['name']) ?></strong><span><?= e($lead['company'] ?: $lead['email']) ?></span><span><?= e($lead['assigned_name'] ?: 'Unassigned') ?> · <?= e(ucfirst((string)$lead['priority'])) ?></span></a><?php endforeach; ?>
      <?php if (!$pipeline[$stage]): ?><div class="crm-empty">No leads</div><?php endif; ?>
    </section>
  <?php endforeach; ?>
  </div>
</section>

<?php elseif ($view === 'tasks'): ?>
<section class="admin-card">
  <div class="admin-card-head"><div><h3>Follow-up tasks</h3><p><?= $summary['tasks_open'] ?> open task<?= $summary['tasks_open']===1?'':'s' ?> tied to Book Demo leads.</p></div></div>
  <div class="table-wrap"><table><thead><tr><th>Task</th><th>Lead</th><th>Assigned</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($tasks as $task): $overdue=$task['status']==='open'&&$task['due_at']&&strtotime((string)$task['due_at'])<time(); ?><tr class="<?= $overdue?'crm-task-overdue':'' ?>"><td><strong><?= e($task['title']) ?></strong><br><span class="crm-meta"><?= e(crm_v180_task_types()[(string)$task['task_type']] ?? $task['task_type']) ?></span></td><td><a href="<?= e(url('/admin/crm-lead.php?id='.(int)$task['lead_id'])) ?>"><?= e($task['name']) ?></a><br><span class="crm-meta"><?= e($task['company']) ?></span></td><td><?= e($task['assigned_name'] ?: 'Unassigned') ?></td><td><?= $task['due_at']?e(date('M j, g:i A',strtotime((string)$task['due_at']))):'—' ?><?= $overdue?' · OVERDUE':'' ?></td><td><?= e(ucfirst((string)$task['status'])) ?></td><td><?php if ($task['status']!=='completed'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="complete_task"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><button class="btn" type="submit">Complete</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$tasks): ?><tr><td colspan="6" class="crm-empty">No CRM tasks yet.</td></tr><?php endif; ?></tbody></table></div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>