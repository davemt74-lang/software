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
crm_v180_ensure_schema($pdo);

$leadId = max(0, (int)($_GET['id'] ?? $_POST['lead_id'] ?? 0));
if ($leadId < 1) {
    flash('error', 'Lead not found.');
    redirect(url('/admin/crm.php?view=leads'));
}

$loadLead = static function (PDO $pdo, int $leadId): ?array {
    $stmt = $pdo->prepare(
        "SELECT l.*,c.name,c.email,c.phone,c.company,c.source AS contact_source,
                u.display_name AS assigned_name
         FROM crm_leads l
         JOIN crm_contacts c ON c.id=l.contact_id
         LEFT JOIN users u ON u.id=l.assigned_user_id
         WHERE l.id=? LIMIT 1"
    );
    $stmt->execute([$leadId]);
    return $stmt->fetch() ?: null;
};

$lead = $loadLead($pdo, $leadId);
if (!$lead) {
    flash('error', 'Lead not found.');
    redirect(url('/admin/crm.php?view=leads'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired.');
        redirect(url('/admin/crm-lead.php?id=' . $leadId));
    }

    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'save_lead') {
            $stage = trim((string)($_POST['stage'] ?? 'new'));
            $priority = trim((string)($_POST['priority'] ?? 'normal'));
            $assignedUserId = max(0, (int)($_POST['assigned_user_id'] ?? 0));
            $nextFollow = trim((string)($_POST['next_follow_up_at'] ?? ''));
            $demoAt = trim((string)($_POST['demo_scheduled_at'] ?? ''));
            $notes = trim((string)($_POST['internal_notes'] ?? ''));

            if (!array_key_exists($stage, crm_v180_stages())) {
                throw new RuntimeException('Select a valid CRM stage.');
            }
            if (!array_key_exists($priority, crm_v180_priorities())) {
                throw new RuntimeException('Select a valid priority.');
            }
            if ($assignedUserId > 0) {
                $validAdmin = false;
                foreach (crm_v180_admin_users($pdo) as $candidate) {
                    if ((int)$candidate['id'] === $assignedUserId) {
                        $validAdmin = true;
                        break;
                    }
                }
                if (!$validAdmin) {
                    throw new RuntimeException('CRM leads can only be assigned to an Admin account.');
                }
            }

            $nextValue = crm_v180_parse_datetime($nextFollow, 'next follow-up date');
            $demoValue = crm_v180_parse_datetime($demoAt, 'demo date');
            $stageChanged = (string)$lead['stage'] !== $stage;
            $isClosing = in_array($stage, ['won', 'lost', 'archived'], true);
            $existingClosed = trim((string)($lead['closed_at'] ?? ''));
            $closed = $isClosing
                ? ($existingClosed !== '' ? $existingClosed : date('Y-m-d H:i:s'))
                : null;

            $stmt = $pdo->prepare(
                "UPDATE crm_leads
                 SET stage=?,priority=?,assigned_user_id=?,next_follow_up_at=?,demo_scheduled_at=?,internal_notes=?,
                     stage_changed_at=CASE WHEN ?=1 THEN NOW() ELSE stage_changed_at END,
                     closed_at=?,updated_at=NOW()
                 WHERE id=?"
            );
            $stmt->execute([
                $stage,
                $priority,
                $assignedUserId ?: null,
                $nextValue,
                $demoValue,
                $notes,
                $stageChanged ? 1 : 0,
                $closed,
                $leadId,
            ]);

            $changes = [];
            if ($stageChanged) $changes[] = 'stage to ' . (crm_v180_stages()[$stage] ?? $stage);
            if ((string)$lead['priority'] !== $priority) $changes[] = 'priority to ' . ucfirst($priority);
            if ((int)($lead['assigned_user_id'] ?? 0) !== $assignedUserId) {
                $changes[] = $assignedUserId > 0 ? 'assignment updated' : 'lead unassigned';
            }
            if ((string)($lead['next_follow_up_at'] ?? '') !== (string)($nextValue ?? '')) {
                $changes[] = 'follow-up date updated';
            }
            if ((string)($lead['demo_scheduled_at'] ?? '') !== (string)($demoValue ?? '')) {
                $changes[] = 'demo date updated';
            }
            if ($changes) {
                crm_v180_activity(
                    $pdo,
                    $leadId,
                    'lead_updated',
                    'Updated ' . implode(', ', $changes) . '.',
                    (int)$user['id']
                );
            }
            flash('notice', 'CRM lead saved.');
        } elseif ($action === 'mark_contacted') {
            $oldStage = (string)$lead['stage'];
            $newStage = in_array($oldStage, ['new', 'qualified'], true) ? 'contacted' : $oldStage;
            $stageChanged = $newStage !== $oldStage;
            $pdo->prepare(
                'UPDATE crm_leads
                 SET stage=?,last_contacted_at=NOW(),
                     stage_changed_at=CASE WHEN ?=1 THEN NOW() ELSE stage_changed_at END,
                     updated_at=NOW()
                 WHERE id=?'
            )->execute([$newStage, $stageChanged ? 1 : 0, $leadId]);
            crm_v180_activity($pdo, $leadId, 'contact', 'Lead marked contacted.', (int)$user['id']);
            flash('notice', 'Contact activity recorded.');
        } elseif ($action === 'add_note') {
            $note = trim((string)($_POST['note'] ?? ''));
            if ($note === '') throw new RuntimeException('Enter a note first.');
            crm_v180_activity($pdo, $leadId, 'note', $note, (int)$user['id']);
            $pdo->prepare('UPDATE crm_leads SET updated_at=NOW() WHERE id=?')->execute([$leadId]);
            flash('notice', 'CRM note added.');
        } elseif ($action === 'create_task') {
            crm_v180_create_task($pdo, $leadId, [
                'title' => (string)($_POST['title'] ?? ''),
                'task_type' => (string)($_POST['task_type'] ?? 'follow_up'),
                'assigned_user_id' => (int)($_POST['task_assigned_user_id'] ?? 0),
                'due_at' => (string)($_POST['due_at'] ?? ''),
            ], (int)$user['id']);
            flash('notice', 'CRM task created.');
        } elseif ($action === 'complete_task') {
            $taskId = max(0, (int)($_POST['task_id'] ?? 0));
            $stmt = $pdo->prepare(
                "SELECT id,title FROM crm_tasks
                 WHERE id=? AND lead_id=? AND status<>'completed' LIMIT 1"
            );
            $stmt->execute([$taskId, $leadId]);
            $task = $stmt->fetch();
            if (!$task) throw new RuntimeException('Open task not found.');
            $pdo->prepare(
                "UPDATE crm_tasks SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=?"
            )->execute([$taskId]);
            crm_v180_activity(
                $pdo,
                $leadId,
                'task_completed',
                'Task completed: ' . (string)$task['title'],
                (int)$user['id'],
                ['task_id' => $taskId]
            );
            flash('notice', 'Task completed.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('/admin/crm-lead.php?id=' . $leadId));
}

$lead = $loadLead($pdo, $leadId);
if (!$lead) {
    flash('error', 'Lead not found.');
    redirect(url('/admin/crm.php?view=leads'));
}

$workflows = json_decode((string)($lead['workflows_json'] ?? '[]'), true);
if (!is_array($workflows)) $workflows = [];

$activityStmt = $pdo->prepare(
    "SELECT a.*,u.display_name AS user_name
     FROM crm_activities a
     LEFT JOIN users u ON u.id=a.user_id
     WHERE a.lead_id=? ORDER BY a.created_at DESC,a.id DESC LIMIT 150"
);
$activityStmt->execute([$leadId]);
$activities = $activityStmt->fetchAll() ?: [];

$taskStmt = $pdo->prepare(
    "SELECT t.*,u.display_name AS assigned_name
     FROM crm_tasks t LEFT JOIN users u ON u.id=t.assigned_user_id
     WHERE t.lead_id=? ORDER BY (t.status='open') DESC,(t.due_at IS NULL),t.due_at ASC,t.id DESC"
);
$taskStmt->execute([$leadId]);
$tasks = $taskStmt->fetchAll() ?: [];
$admins = crm_v180_admin_users($pdo);

$adminTitle = 'CRM Lead';
$adminActive = 'crm';
require __DIR__ . '/_header.php';
?>
<style>
.crm-lead-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap}.crm-lead-head h2{margin:5px 0}.crm-lead-sub{color:#a49d94;font-size:12px}.crm-layout{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(310px,.7fr);gap:16px}.crm-card{border:1px solid #312d29;border-radius:16px;padding:18px;background:rgba(255,255,255,.02);margin-bottom:16px}.crm-card h3{margin:0 0 14px}.crm-pill{display:inline-flex;border:1px solid #38322e;border-radius:999px;padding:6px 9px;margin:3px 4px 3px 0;font-size:10px}.crm-contact-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.crm-contact-grid div{padding:12px;border:1px solid #302c28;border-radius:11px}.crm-contact-grid span{display:block;color:#918a83;font-size:10px;text-transform:uppercase;letter-spacing:.07em}.crm-contact-grid strong,.crm-contact-grid a{display:block;margin-top:4px;font-size:12px}.crm-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.crm-field{display:grid;gap:6px}.crm-field.full{grid-column:1/-1}.crm-field label{font-size:10px;color:#a59d95;text-transform:uppercase;letter-spacing:.06em}.crm-field input,.crm-field select,.crm-field textarea{width:100%;background:#12110f;color:#fff;border:1px solid #38332e;border-radius:9px;padding:10px 11px}.crm-field textarea{min-height:110px;resize:vertical}.crm-actions{display:flex;gap:8px;flex-wrap:wrap}.crm-timeline{display:grid;gap:10px}.crm-event{border-left:2px solid #3d3732;padding:3px 0 3px 14px}.crm-event strong{display:block;font-size:12px}.crm-event span{display:block;color:#8f8880;font-size:10px;margin-top:3px}.crm-event p{font-size:12px;color:#c7c1bb;margin:5px 0 0;white-space:pre-wrap}.crm-task{display:grid;grid-template-columns:1fr auto;gap:12px;padding:12px 0;border-bottom:1px solid #2e2a26}.crm-task:last-child{border:0}.crm-task strong{font-size:12px}.crm-task span{display:block;color:#918a83;font-size:10px}.crm-overdue{color:#e3907b!important}.crm-demo-box{padding:14px;border:1px solid #302c28;border-radius:12px;background:#13110f}.crm-demo-box p{white-space:pre-wrap;color:#c1bbb4;font-size:12px}.crm-back{display:inline-flex;margin-bottom:14px;color:#aaa29a;font-size:11px}
@media(max-width:1050px){.crm-layout{grid-template-columns:1fr}}@media(max-width:680px){.crm-contact-grid,.crm-form-grid{grid-template-columns:1fr}.crm-field.full{grid-column:auto}}
</style>
<div class="panel">
  <a class="crm-back" href="<?= e(url('/admin/crm.php?view=leads')) ?>">← Back to CRM leads</a>
  <div class="crm-lead-head">
    <div>
      <span class="status">Lead #<?= (int)$lead['id'] ?> · <?= e(crm_v180_stages()[(string)$lead['stage']] ?? $lead['stage']) ?></span>
      <h2><?= e($lead['name']) ?><?= $lead['company'] ? ' — '.e($lead['company']) : '' ?></h2>
      <div class="crm-lead-sub">Book a Demo · created <?= e(date('M j, Y g:i A',strtotime((string)$lead['created_at']))) ?></div>
    </div>
    <div class="crm-actions">
      <a class="btn" href="mailto:<?= e($lead['email']) ?>?subject=Stonefellow%20Demo">Email</a>
      <?php if ($lead['phone']): ?><a class="btn" href="tel:<?= e(preg_replace('/[^0-9+]/','',(string)$lead['phone'])) ?>">Call</a><?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_contacted"><input type="hidden" name="lead_id" value="<?= $leadId ?>"><button class="btn primary" type="submit">Mark Contacted</button></form>
    </div>
  </div>
</div>

<div class="crm-layout">
  <div>
    <section class="crm-card">
      <h3>Contact & demo request</h3>
      <div class="crm-contact-grid">
        <div><span>Email</span><a href="mailto:<?= e($lead['email']) ?>"><?= e($lead['email']) ?></a></div>
        <div><span>Phone</span><strong><?= e($lead['phone'] ?: 'Not provided') ?></strong></div>
        <div><span>Company / studio</span><strong><?= e($lead['company'] ?: 'Not provided') ?></strong></div>
        <div><span>Role / team</span><strong><?= e(($lead['role_interest'] ?: 'Not specified') . ($lead['team_size'] ? ' · '.$lead['team_size'] : '')) ?></strong></div>
      </div>
      <?php if ($workflows): ?><div style="margin-top:14px"><span class="muted">Workflow interests</span><br><?php foreach ($workflows as $workflow): ?><span class="crm-pill"><?= e((string)$workflow) ?></span><?php endforeach; ?></div><?php endif; ?>
      <div class="crm-demo-box" style="margin-top:14px"><strong>Requested demo focus</strong><p><?= e($lead['demo_focus'] ?: 'No additional demo notes provided.') ?></p></div>
      <?php if ($lead['source_contact_message_id']): ?><div style="margin-top:12px"><a class="btn" href="<?= e(url('/admin/messages.php?view='.(int)$lead['source_contact_message_id'])) ?>">Open original submission</a></div><?php endif; ?>
    </section>

    <section class="crm-card">
      <h3>Activity timeline</h3>
      <form method="post" class="crm-form-grid" style="margin-bottom:18px">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_note"><input type="hidden" name="lead_id" value="<?= $leadId ?>">
        <div class="crm-field full"><label for="note">Add internal note</label><textarea id="note" name="note" placeholder="Call notes, demo outcome, qualification details, objections, next steps..."></textarea></div>
        <div class="crm-field full"><button class="btn" type="submit">Add Note</button></div>
      </form>
      <div class="crm-timeline">
        <?php foreach ($activities as $activity): ?><article class="crm-event"><strong><?= e(ucwords(str_replace('_',' ',(string)$activity['activity_type']))) ?></strong><span><?= e(date('M j, Y g:i A',strtotime((string)$activity['created_at']))) ?><?= $activity['user_name'] ? ' · '.e($activity['user_name']) : ' · Stonefellow' ?></span><p><?= e($activity['summary']) ?></p></article><?php endforeach; ?>
        <?php if (!$activities): ?><div class="muted">No CRM activity yet.</div><?php endif; ?>
      </div>
    </section>
  </div>

  <aside>
    <section class="crm-card">
      <h3>Lead workflow</h3>
      <form method="post" class="crm-form-grid">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_lead"><input type="hidden" name="lead_id" value="<?= $leadId ?>">
        <div class="crm-field"><label>Stage</label><select name="stage"><?php foreach (crm_v180_stages() as $key=>$label): ?><option value="<?= e($key) ?>" <?= $lead['stage']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="crm-field"><label>Priority</label><select name="priority"><?php foreach (crm_v180_priorities() as $key=>$label): ?><option value="<?= e($key) ?>" <?= $lead['priority']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="crm-field full"><label>Assigned admin</label><select name="assigned_user_id"><option value="0">Unassigned</option><?php foreach ($admins as $admin): ?><option value="<?= (int)$admin['id'] ?>" <?= (int)$lead['assigned_user_id']===(int)$admin['id']?'selected':'' ?>><?= e($admin['display_name']) ?></option><?php endforeach; ?></select></div>
        <div class="crm-field full"><label>Next follow-up</label><input type="datetime-local" name="next_follow_up_at" value="<?= $lead['next_follow_up_at']?e(date('Y-m-d\TH:i',strtotime((string)$lead['next_follow_up_at']))):'' ?>"></div>
        <div class="crm-field full"><label>Demo scheduled</label><input type="datetime-local" name="demo_scheduled_at" value="<?= $lead['demo_scheduled_at']?e(date('Y-m-d\TH:i',strtotime((string)$lead['demo_scheduled_at']))):'' ?>"></div>
        <div class="crm-field full"><label>Private lead notes</label><textarea name="internal_notes" placeholder="Persistent private CRM notes..."><?= e($lead['internal_notes'] ?? '') ?></textarea></div>
        <div class="crm-field full"><button class="btn primary" type="submit">Save Lead</button></div>
      </form>
      <?php if ($lead['last_contacted_at']): ?><p class="muted" style="font-size:10px">Last contacted <?= e(date('M j, Y g:i A',strtotime((string)$lead['last_contacted_at']))) ?></p><?php endif; ?>
    </section>

    <section class="crm-card">
      <h3>Follow-up tasks</h3>
      <form method="post" class="crm-form-grid" style="margin-bottom:16px">
        <?= csrf_field() ?><input type="hidden" name="action" value="create_task"><input type="hidden" name="lead_id" value="<?= $leadId ?>">
        <div class="crm-field full"><label>Task</label><input name="title" maxlength="190" required placeholder="Follow up after demo"></div>
        <div class="crm-field"><label>Type</label><select name="task_type"><?php foreach (crm_v180_task_types() as $key=>$label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="crm-field"><label>Assigned</label><select name="task_assigned_user_id"><option value="0">Unassigned</option><?php foreach ($admins as $admin): ?><option value="<?= (int)$admin['id'] ?>" <?= (int)$lead['assigned_user_id']===(int)$admin['id']?'selected':'' ?>><?= e($admin['display_name']) ?></option><?php endforeach; ?></select></div>
        <div class="crm-field full"><label>Due</label><input type="datetime-local" name="due_at"></div>
        <div class="crm-field full"><button class="btn" type="submit">Create Task</button></div>
      </form>
      <div>
        <?php foreach ($tasks as $task): $overdue=$task['status']==='open'&&$task['due_at']&&strtotime((string)$task['due_at'])<time(); ?><div class="crm-task"><div><strong><?= e($task['title']) ?></strong><span class="<?= $overdue?'crm-overdue':'' ?>"><?= e(crm_v180_task_types()[(string)$task['task_type']] ?? $task['task_type']) ?> · <?= e($task['assigned_name'] ?: 'Unassigned') ?><?= $task['due_at']?' · '.e(date('M j, g:i A',strtotime((string)$task['due_at']))):'' ?><?= $overdue?' · OVERDUE':'' ?></span></div><?php if ($task['status']!=='completed'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="complete_task"><input type="hidden" name="lead_id" value="<?= $leadId ?>"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><button class="btn" type="submit">Done</button></form><?php else: ?><span class="muted">Done</span><?php endif; ?></div><?php endforeach; ?>
        <?php if (!$tasks): ?><div class="muted">No tasks yet.</div><?php endif; ?>
      </div>
    </section>
  </aside>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
