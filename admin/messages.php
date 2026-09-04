<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('messages.manage');

if (!access_schema_ready()) {
    redirect(url('/upgrade.php'));
}

$pdo = db();
if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}

$viewId = (int)($_GET['view'] ?? 0);
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$validStatuses = ['all','new','open','replied','archived'];

if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired.');
        redirect(url('/admin/messages.php'));
    }

    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM contact_messages WHERE id=?')->execute([$id]);
            flash('notice', 'Message deleted.');
            redirect(url('/admin/messages.php'));
        }

        if ($action === 'unread') {
            $pdo->prepare(
                "UPDATE contact_messages SET is_read=0,status='new' WHERE id=?"
            )->execute([$id]);

            flash('notice', 'Message marked unread.');
            redirect(url('/admin/messages.php?view=' . $id));
        }

        if ($action === 'save') {
            $status = trim((string)($_POST['status'] ?? 'open'));
            $assignedUserId = (int)($_POST['assigned_user_id'] ?? 0);
            $adminNotes = trim((string)($_POST['admin_notes'] ?? ''));

            if (!in_array($status, ['new','open','replied','archived'], true)) {
                throw new RuntimeException('Select a valid message status.');
            }

            $previousStmt = $pdo->prepare(
                'SELECT assigned_user_id,name,topic FROM contact_messages WHERE id=? LIMIT 1'
            );
            $previousStmt->execute([$id]);
            $previous = $previousStmt->fetch();

            if (!$previous) {
                throw new RuntimeException('Message not found.');
            }

            if ($assignedUserId > 0) {
                $check = $pdo->prepare(
                    'SELECT id,display_name,email,role FROM users WHERE id=? AND is_active=1 LIMIT 1'
                );
                $check->execute([$assignedUserId]);
                $assigned = $check->fetch();

                if (!$assigned || !role_has_permission((string)$assigned['role'], 'messages.manage')) {
                    throw new RuntimeException('Select a user who has message-management access.');
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE contact_messages
                 SET status=?,assigned_user_id=?,admin_notes=?,is_read=1
                 WHERE id=?'
            );
            $stmt->execute([
                $status,
                $assignedUserId ?: null,
                $adminNotes,
                $id,
            ]);

            if (
                $assignedUserId > 0
                && (int)($previous['assigned_user_id'] ?? 0) !== $assignedUserId
            ) {
                create_notification(
                    $assignedUserId,
                    'message_assignment',
                    'Contact message assigned to you',
                    (string)$previous['name'] . ' — ' . (string)$previous['topic'],
                    url('/admin/messages.php?view=' . $id),
                    'contact_message_assignment',
                    $id
                );
            }

            flash('notice', 'Message updated.');
            redirect(url('/admin/messages.php?view=' . $id));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect(url('/admin/messages.php?view=' . $id));
    }
}

$viewing = null;

if ($viewId > 0) {
    $stmt = $pdo->prepare(
        'SELECT m.*,u.display_name AS assigned_name
         FROM contact_messages m
         LEFT JOIN users u ON u.id=m.assigned_user_id
         WHERE m.id=? LIMIT 1'
    );
    $stmt->execute([$viewId]);
    $viewing = $stmt->fetch() ?: null;

    if ($viewing) {
        $pdo->prepare(
            "UPDATE contact_messages
             SET is_read=1,
                 status=CASE WHEN status='new' THEN 'open' ELSE status END
             WHERE id=?"
        )->execute([$viewId]);

        $currentViewer = current_user();
        if ($currentViewer && table_exists('notifications')) {
            $notificationStmt = $pdo->prepare(
                "UPDATE notifications
                 SET is_read=1,read_at=COALESCE(read_at,NOW())
                 WHERE user_id=? AND source_id=?
                   AND source_type IN ('contact_message','contact_message_assignment')"
            );
            $notificationStmt->execute([(int)$currentViewer['id'],$viewId]);
        }

        $stmt->execute([$viewId]);
        $viewing = $stmt->fetch() ?: null;
    }
}

$where = '';
$params = [];

if ($statusFilter !== 'all') {
    $where = ' WHERE m.status=?';
    $params[] = $statusFilter;
}

$stmt = $pdo->prepare(
    'SELECT m.*,u.display_name AS assigned_name
     FROM contact_messages m
     LEFT JOIN users u ON u.id=m.assigned_user_id' .
    $where .
    ' ORDER BY m.created_at DESC'
);
$stmt->execute($params);
$messages = $stmt->fetchAll();

$counts = [
    'all' => 0,
    'new' => 0,
    'open' => 0,
    'replied' => 0,
    'archived' => 0,
];

foreach ($pdo->query(
    'SELECT status,COUNT(*) AS total FROM contact_messages GROUP BY status'
)->fetchAll() as $row) {
    $status = (string)$row['status'];
    if (array_key_exists($status, $counts)) {
        $counts[$status] = (int)$row['total'];
    }
    $counts['all'] += (int)$row['total'];
}

$assignableUsers = [];

foreach ($pdo->query(
    'SELECT id,display_name,email,role
     FROM users WHERE is_active=1
     ORDER BY display_name'
)->fetchAll() as $candidate) {
    if (role_has_permission((string)$candidate['role'], 'messages.manage')) {
        $assignableUsers[] = $candidate;
    }
}

$adminTitle = 'Messages';
$adminActive = 'messages';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Contact Inbox</span>
      <h2>Messages</h2>
      <p class="muted">Contact-form submissions, assignment, follow-up status and internal notes.</p>
    </div>
  </div>

  <nav class="message-filters" aria-label="Message filters">
    <?php foreach ([
      'all'=>'All',
      'new'=>'New',
      'open'=>'Open',
      'replied'=>'Replied',
      'archived'=>'Archived'
    ] as $value=>$label): ?>
      <a class="<?= $statusFilter===$value ? 'active' : '' ?>" href="<?= e(url('/admin/messages.php?status='.$value)) ?>">
        <?= e($label) ?> <span><?= (int)($counts[$value] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>From</th>
          <th>Topic</th>
          <th>Status</th>
          <th>Assigned</th>
          <th>Preview</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($messages as $message): ?>
        <tr class="<?= !(int)$message['is_read'] ? 'message-row-unread' : '' ?>">
          <td><?= e(date('M j, Y g:i A', strtotime((string)$message['created_at']))) ?></td>
          <td>
            <strong><?= e($message['name']) ?></strong><br>
            <span class="muted"><?= e($message['email']) ?></span>
          </td>
          <td><?= e($message['topic']) ?></td>
          <td><span class="message-status status-<?= e($message['status']) ?>"><?= e(ucfirst((string)$message['status'])) ?></span></td>
          <td><?= e($message['assigned_name'] ?: 'Unassigned') ?></td>
          <td><?= e(mb_strimwidth((string)$message['message'],0,100,'…')) ?></td>
          <td><a class="btn" href="<?= e(url('/admin/messages.php?view='.(int)$message['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$messages): ?>
        <tr><td colspan="7" class="muted">No messages in this view.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($viewing): ?>
<div class="panel message-detail-panel" id="message-detail">
  <div class="content-form-heading">
    <div>
      <span class="status">Message #<?= (int)$viewing['id'] ?></span>
      <h2><?= e($viewing['topic']) ?></h2>
    </div>
    <a class="btn" href="<?= e(url('/admin/messages.php')) ?>">Close</a>
  </div>

  <div class="message-detail-grid">
    <article class="message-detail-content">
      <div class="message-detail-from">
        <div>
          <strong><?= e($viewing['name']) ?></strong>
          <a href="mailto:<?= e($viewing['email']) ?>"><?= e($viewing['email']) ?></a>
        </div>
        <time><?= e(date('M j, Y g:i A',strtotime((string)$viewing['created_at']))) ?></time>
      </div>

      <div class="message-detail-body"><?= nl2br(e((string)$viewing['message'])) ?></div>

      <div class="actions">
        <a class="btn primary" href="mailto:<?= e($viewing['email']) ?>?subject=Re:%20<?= rawurlencode((string)$viewing['topic']) ?>">Reply by Email</a>

        <form method="post" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="unread">
          <input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>">
          <button class="btn" type="submit">Mark Unread</button>
        </form>

        <form method="post" class="inline-form" onsubmit="return confirm('Delete this contact message?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>">
          <button class="btn danger" type="submit">Delete</button>
        </form>
      </div>
    </article>

    <aside class="message-detail-workflow">
      <form method="post" class="grid-form message-workflow-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>">

        <div class="field full">
          <label>Status</label>
          <select name="status">
            <?php foreach ([
              'new'=>'New',
              'open'=>'Open',
              'replied'=>'Replied',
              'archived'=>'Archived'
            ] as $value=>$label): ?>
              <option value="<?= e($value) ?>" <?= $viewing['status']===$value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field full">
          <label>Assigned To</label>
          <select name="assigned_user_id">
            <option value="0">Unassigned</option>
            <?php foreach ($assignableUsers as $candidate): ?>
              <option value="<?= (int)$candidate['id'] ?>" <?= (int)$viewing['assigned_user_id']===(int)$candidate['id'] ? 'selected' : '' ?>>
                <?= e($candidate['display_name']) ?> — <?= e(role_label((string)$candidate['role'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field full">
          <label>Internal Notes</label>
          <textarea name="admin_notes" style="min-height:180px" placeholder="Private notes about this inquiry..."><?= e($viewing['admin_notes'] ?? '') ?></textarea>
        </div>

        <div class="field full actions">
          <button class="btn primary" type="submit">Save Message</button>
        </div>
      </form>
    </aside>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
