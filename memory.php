<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (!is_logged_in()) redirect(url('/login.php'));
$user = current_user();
$pdo = db();
if (!$user || !$pdo) redirect(url('/login.php'));
if (!has_permission('chat.access', $user)) {
    http_response_code(403);
    exit('Agent Memory access is unavailable for this account.');
}

$userId = (int)$user['id'];
$notice = flash('memory_notice');
$error = flash('memory_error');
$ready = table_exists('agent_memory_items');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        flash('memory_error', 'Session expired. Try again.');
        redirect(url('/memory.php'));
    }
    try {
        if (!$ready) throw new RuntimeException('Agent Memory storage is not ready.');
        $action = (string)($_POST['action'] ?? '');
        $memoryId = max(0, (int)($_POST['memory_id'] ?? 0));
        if ($action !== 'forget' || $memoryId < 1) throw new RuntimeException('Invalid memory action.');
        $stmt = $pdo->prepare('UPDATE agent_memory_items SET is_active=0 WHERE id=? AND user_id=?');
        $stmt->execute([$memoryId, $userId]);
        if ($stmt->rowCount() < 1) throw new RuntimeException('Memory not found.');
        flash('memory_notice', 'Memory forgotten.');
    } catch (Throwable $e) {
        flash('memory_error', $e->getMessage());
    }
    redirect(url('/memory.php'));
}

$items = [];
$typeCounts = [];
if ($ready) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM agent_memory_items WHERE user_id=? AND is_active=1 ORDER BY last_seen_at DESC,id DESC LIMIT 300');
        $stmt->execute([$userId]);
        $items = $stmt->fetchAll() ?: [];
        foreach ($items as $item) {
            $type = trim((string)($item['memory_type'] ?? 'memory')) ?: 'memory';
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }
    } catch (Throwable $e) {
        $items = [];
        $error = $error ?: 'Agent Memory could not be loaded.';
    }
}

$tasks = 0;
$preferences = 0;
foreach ($items as $item) {
    $type = (string)($item['memory_type'] ?? '');
    if (in_array($type, ['task','commitment'], true)) $tasks++;
    if ($type === 'preference') $preferences++;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#ffffff">
<title>Memory | <?= e(system_agent_name()) ?></title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<style>
.memory-main{background:#f8fafc;min-height:0;grid-template-rows:58px minmax(0,1fr)}.memory-canvas{min-height:0;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;padding:28px 28px 40px}.memory-wrap{max-width:1100px;margin:0 auto}.memory-hero{display:flex;justify-content:space-between;gap:22px;align-items:flex-start;margin-bottom:18px;padding:22px;border:1px solid #e5e7eb;border-radius:18px;background:#fff}.memory-hero small{font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#6b7280}.memory-hero h1{margin:6px 0 7px;font-size:30px;letter-spacing:-.03em}.memory-hero p{max-width:700px;margin:0;color:#6b7280;font-size:13px;line-height:1.55}.memory-stats{display:grid;grid-template-columns:repeat(3,minmax(92px,1fr));gap:8px}.memory-stat{padding:11px;border:1px solid #e5e7eb;border-radius:12px;background:#fafafa;text-align:center}.memory-stat strong{display:block;font-size:19px}.memory-stat span{font-size:9px;font-weight:750;text-transform:uppercase;letter-spacing:.05em;color:#6b7280}.memory-notice{margin:0 0 12px;padding:10px 12px;border:1px solid #bbf7d0;border-radius:10px;background:#f0fdf4;color:#166534;font-size:12px}.memory-notice.error{border-color:#fecaca;background:#fef2f2;color:#991b1b}.memory-panel{border:1px solid #e5e7eb;border-radius:16px;background:#fff;overflow:hidden}.memory-panel-head{display:flex;justify-content:space-between;gap:12px;padding:15px 17px;border-bottom:1px solid #e5e7eb}.memory-panel-head h2{margin:0;font-size:15px}.memory-panel-head p{margin:3px 0 0;color:#6b7280;font-size:11px}.memory-list{display:grid}.memory-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;padding:15px 17px;border-bottom:1px solid #eef2f7}.memory-row:last-child{border-bottom:0}.memory-row h3{margin:0 0 5px;font-size:13px}.memory-row p{margin:0;color:#374151;font-size:12px;line-height:1.55;white-space:pre-wrap}.memory-meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}.memory-meta span{padding:3px 6px;border-radius:999px;background:#f3f4f6;color:#4b5563;font-size:9px;font-weight:700}.memory-actions{display:flex;align-items:flex-start}.memory-actions button{padding:6px 8px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;color:#b91c1c;font-size:10px;font-weight:700;cursor:pointer}.memory-empty{padding:36px 18px;text-align:center;color:#6b7280;font-size:12px}.memory-privacy{margin-top:12px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;color:#6b7280;font-size:11px;line-height:1.5}@media(max-width:760px){.memory-canvas{padding:16px 16px 28px}.memory-hero{display:grid}.memory-stats{width:100%}.memory-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='memory';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main memory-main">
<?php
$memberHeaderUser=$user;
$memberHeaderTitle='Memory';
$memberHeaderSubtitle='What your Agent remembers about your work, preferences and commitments';
$memberHeaderActions='<a href="'.e(url('/chat.php')).'">Open Agent Chat</a><a href="'.e(url('/knowledge.php')).'">Knowledge</a>';
require __DIR__.'/includes/member-header.php';
?>
<section class="memory-canvas"><div class="memory-wrap">
<div class="memory-hero"><div><small>Private Agent Context</small><h1>Your Agent Memory</h1><p>Memory is distilled from your Agent interactions so conversations can continue with useful context. It is separate from documents in Knowledge. You can review active memories here and explicitly forget anything you no longer want the Agent to retain.</p></div><div class="memory-stats"><div class="memory-stat"><strong><?= count($items) ?></strong><span>Active</span></div><div class="memory-stat"><strong><?= $preferences ?></strong><span>Preferences</span></div><div class="memory-stat"><strong><?= $tasks ?></strong><span>Tasks</span></div></div></div>
<?php if ($notice): ?><div class="memory-notice"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="memory-notice error"><?= e($error) ?></div><?php endif; ?>
<div class="memory-panel"><div class="memory-panel-head"><div><h2>Active Memory</h2><p><?= $ready ? 'Newest and most recently reinforced memories first.' : 'Memory storage is not ready on this installation.' ?></p></div></div><div class="memory-list">
<?php foreach ($items as $item):
    $meta = function_exists('agent_memory_v123_metadata') ? agent_memory_v123_metadata($item) : [];
    $confidence = function_exists('agent_memory_v123_effective_confidence') ? agent_memory_v123_effective_confidence($item) : max(0.0,min(1.0,(float)($item['confidence'] ?? 0.5)));
    $subject = trim((string)($item['subject'] ?? ''));
    $text = trim((string)($item['memory_text'] ?? ''));
    $type = trim((string)($item['memory_type'] ?? 'memory')) ?: 'memory';
    $status = trim((string)($meta['task_status'] ?? ''));
?>
<article class="memory-row"><div><h3><?= e($subject !== '' ? $subject : ucfirst(str_replace('_',' ',$type))) ?></h3><p><?= e($text) ?></p><div class="memory-meta"><span><?= e(ucfirst(str_replace('_',' ',$type))) ?></span><span><?= (int)round($confidence*100) ?>% confidence</span><span><?= (int)($item['occurrence_count'] ?? 1) ?>× reinforced</span><?php if ($status !== ''): ?><span><?= e(ucfirst(str_replace('_',' ',$status))) ?></span><?php endif; ?><?php if (!empty($item['last_seen_at'])): ?><span>Seen <?= e(date('M j',strtotime((string)$item['last_seen_at']))) ?></span><?php endif; ?></div></div><div class="memory-actions"><form method="post" onsubmit="return confirm('Forget this memory?')"><?= csrf_field() ?><input type="hidden" name="action" value="forget"><input type="hidden" name="memory_id" value="<?= (int)$item['id'] ?>"><button type="submit">Forget</button></form></div></article>
<?php endforeach; ?>
<?php if (!$items): ?><div class="memory-empty"><?= $ready ? 'No active memories yet. Your Agent will build useful context as you work together.' : 'Run the current database upgrade to enable Agent Memory.' ?></div><?php endif; ?>
</div></div>
<div class="memory-privacy"><strong>Privacy boundary:</strong> only memory rows owned by your user ID are shown or changed here. Forgetting deactivates the memory for Agent recall; it does not delete your underlying chat history.</div>
</div></section>
</main></div>
<script src="<?= e(url('/member-shell-v77.js?v=universal-member-header-20260905')) ?>"></script>
</body></html>
