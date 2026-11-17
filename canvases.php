<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/coding-canvas-jobs-v1.php';

require_login();
require_permission('account.access');
require_permission('chat.access');
header('Cache-Control: private, no-store');

$pdo = db();
$user = current_user();
if (!$pdo || !$user) {
    http_response_code(503);
    exit('Canvases are unavailable right now.');
}

$agents = $canvases = [];
$agentId = 0;
$agent = null;
$error = '';
try {
    $agents = user_agent_system_schema_ready_v236($pdo) ? user_agents_list_v236($pdo,(int)$user['id'],true) : [];
    $selection = $_GET['agent'] ?? '';
    if ($selection === '') {
        $agentId = (int)($agents[0]['id'] ?? 0);
    } elseif (!is_string($selection)) {
        throw new InvalidArgumentException('Choose an available Agent.');
    } elseif (strcasecmp($selection,'system') !== 0) {
        $agentId = filter_var($selection,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($agentId === false) throw new InvalidArgumentException('Choose an available Agent.');
    }
    $live = vp3_coding_canvas_scope_v1($pdo,$user,$agentId,0);
    $agent = vp3_agent_chat_resolve_agent_v380($pdo,$live,$agentId);
    $canvases = vp3_coding_canvas_list_v1($pdo,$live,$agentId);
} catch (InvalidArgumentException) {
    http_response_code(400);
    $error = 'Choose an available Agent to open its canvases.';
} catch (RuntimeException $exception) {
    $status = $exception instanceof PDOException ? 503 : (int)$exception->getCode();
    if (!in_array($status,[403,404,503],true)) $status = 503;
    http_response_code($status);
    $error = $status === 503 ? 'Canvases are unavailable right now.' : 'You can’t open canvases for this Agent.';
}

$agentKey = $agentId ?: 'system';
$canBuild = $error === '' && personal_capability_has_v242('personal_knowledge.manage',$live);
$originAccess = [];
$chatUrl = static fn(array $params = []): string => url('/chat.php?'.http_build_query(['agent'=>$agentKey] + $params,'','&',PHP_QUERY_RFC3986));
$artwork = static fn(mixed $value): string => is_string($value) && str_starts_with($value,'/')
    && !str_starts_with($value,'//') && !str_contains($value,'\\') && !preg_match('/[\x00-\x20\x7f]/',$value) ? $value : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e(system_agent_name()) ?> | Canvases</title>
  <link rel="stylesheet" href="<?= e(url('/chat.css?v='.substr(hash_file('sha256',__DIR__.'/chat.css'),0,12))) ?>">
  <link rel="stylesheet" href="<?= e(url('/coding-canvas-catalog-v1.css?v='.substr(hash_file('sha256',__DIR__.'/coding-canvas-catalog-v1.css'),0,12))) ?>">
</head>
<body>
<div class="chat-app">
  <?php $workspaceSidebarUser=$user;$workspaceSidebarActive='canvases';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
  <main class="chat-main vp3-canvas-library-main">
    <?php $memberHeaderUser=$user;$memberHeaderActiveKey='canvases';require __DIR__.'/includes/member-header.php'; ?>
    <div class="vp3-canvas-library">
      <header class="vp3-canvas-library-toolbar">
        <h1>Canvases</h1>
        <div class="vp3-canvas-library-tools">
          <?php if ($agents): ?>
            <form method="get" action="<?= e(url('/canvases.php')) ?>">
              <label for="canvasLibraryAgent">Agent</label>
              <select name="agent" id="canvasLibraryAgent">
                <option value="system"<?= $agentKey === 'system' ? ' selected' : '' ?>><?= e(system_agent_name()) ?></option>
                <?php foreach ($agents as $option): ?>
                  <option value="<?= (int)$option['id'] ?>"<?= $agentKey === (int)$option['id'] ? ' selected' : '' ?>><?= e($option['display_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="vp3-canvas-library-action" type="submit">Show canvases</button>
            </form>
          <?php endif; ?>
          <?php if ($canBuild): ?>
            <a class="vp3-canvas-library-action primary" href="<?= e($chatUrl(['canvas_new'=>1])) ?>">New canvas</a>
          <?php endif; ?>
        </div>
      </header>
      <?php if ($error !== ''): ?>
        <p class="vp3-canvas-library-empty" role="status"><?= e($error) ?></p>
      <?php elseif (!$canvases): ?>
        <p class="vp3-canvas-library-empty">No saved canvases for <?= e($agent['display_name'] ?? system_agent_name()) ?> yet.</p>
      <?php else: ?>
        <ul class="vp3-canvas-library-list" aria-label="Saved canvases">
          <?php foreach ($canvases as $canvas):
              $id = $canvas['id'];
              $title = trim($canvas['title']) ?: 'Untitled canvas';
              $active = $canvas['active_revision'];
              $draft = $canvas['draft_revision'];
              $conversation = $canvas['conversation_id'];
              $originAccess[$conversation] ??= $conversation > 0
                  && vp3_agent_chat_conversation_v380($pdo,$conversation,(int)$live['id'],$agent) !== null;
              $openParams = ['canvas_id'=>$id];
              if ($originAccess[$conversation]) $openParams['conversation_id'] = $conversation;
              $icon = $artwork($canvas['icon_url'] ?? null);
              $thumbnail = $artwork($canvas['thumbnail_url'] ?? null);
          ?>
            <li class="vp3-canvas-library-item">
              <?php if ($thumbnail !== ''): ?>
                <img src="<?= e($thumbnail) ?>" alt="" class="vp3-canvas-library-thumb" loading="lazy" width="480" height="270">
              <?php endif; ?>
              <div class="vp3-canvas-library-item-heading">
                <?php if ($icon !== ''): ?>
                  <img src="<?= e($icon) ?>" alt="" class="vp3-canvas-library-icon" loading="lazy" width="32" height="32">
                <?php else: ?>
                  <span class="vp3-canvas-library-icon fallback" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($title,0,1))) ?></span>
                <?php endif; ?>
                <h2><?= e($title) ?></h2>
              </div>
              <p class="vp3-canvas-library-status"><?php
                  if ($active !== null) echo 'Active version '.(int)$active;
                  if ($active !== null && $draft !== null && $draft !== $active) echo ' · ';
                  if ($draft !== null && $draft !== $active) echo 'Draft version '.(int)$draft;
                  if ($active === null && $draft === null) echo 'Preview unavailable';
              ?></p>
              <div class="vp3-canvas-library-actions">
                <?php if ($active !== null): ?>
                  <a class="vp3-canvas-library-action primary" href="<?= e($chatUrl($openParams+['canvas_revision'=>$active])) ?>" aria-label="<?= e('Open app '.$title) ?>">Open app</a>
                <?php endif; ?>
                <?php if ($draft !== null && $draft !== $active): ?>
                  <a class="vp3-canvas-library-action<?= $active === null ? ' primary' : '' ?>" href="<?= e($chatUrl($openParams+['canvas_revision'=>$draft])) ?>" aria-label="<?= e('Review draft '.$title) ?>">Review draft</a>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
