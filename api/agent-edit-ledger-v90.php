<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user = current_user();
if (!$user) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Sign in required.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
if (!hash_equals(csrf_token(), (string)($input['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['ok'=>false,'error'=>'Session expired.']);
    exit;
}

function ledger_v90_json(bool $ok, array $extra = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok'=>$ok] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ledger_v90_project_allowed(string $editor, int $projectId, array $user): bool
{
    $pdo = db();
    if (!$pdo) return false;

    if ($editor === 'stem') {
        if ($projectId < 1) return false;
        $track = get_track_by_id($projectId);
        return $track && agent_tool_can_studio($track, $user);
    }

    if ($editor === 'video') {
        if (!has_permission('chat.access', $user)) return false;
        if ($projectId < 1) return true; // unsaved project, tied to authenticated user + session
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM video_editor_projects WHERE id=? AND user_id=? LIMIT 1');
            $stmt->execute([$projectId,(int)$user['id']]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    return false;
}

function ledger_v90_json_value(mixed $value, int $maxBytes): ?string
{
    if ($value === null) return null;
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) return null;
    if (strlen($encoded) > $maxBytes) {
        throw new RuntimeException('The edit state is too large to archive safely.');
    }
    return $encoded;
}

try {
    if (!table_exists('agent_edit_events')) {
        throw new RuntimeException('Run the v90 database upgrade first.');
    }

    $action = (string)($input['action'] ?? 'record');
    $editor = (string)($input['editor_kind'] ?? '');
    $projectId = max(0,(int)($input['project_id'] ?? 0));
    if (!in_array($editor,['stem','video'],true) || !ledger_v90_project_allowed($editor,$projectId,$user)) {
        ledger_v90_json(false,['error'=>'This editor project is not available to your account.'],403);
    }

    if ($action === 'list') {
        $limit = max(1,min(250,(int)($input['limit'] ?? 100)));
        $sessionKey = mb_substr(trim((string)($input['session_key'] ?? '')),0,100);
        $params = [(int)$user['id'],$editor,$projectId];
        $sql = 'SELECT id,conversation_id,editor_kind,project_id,session_key,source_kind,action_key,request_text,model_provider,model_name,playhead_seconds,changes_json,created_at
                FROM agent_edit_events WHERE user_id=? AND editor_kind=? AND project_id=?';
        if ($sessionKey !== '') {
            $sql .= ' AND session_key=?';
            $params[] = $sessionKey;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = db()?->prepare($sql);
        $stmt?->execute($params);
        $rows = $stmt ? $stmt->fetchAll() : [];
        foreach ($rows as &$row) {
            $decoded = json_decode((string)($row['changes_json'] ?? ''),true);
            $row['changes'] = is_array($decoded) ? $decoded : [];
            unset($row['changes_json']);
        }
        unset($row);
        ledger_v90_json(true,['events'=>$rows]);
    }

    if ($action !== 'record') {
        throw new RuntimeException('Unknown edit-ledger action.');
    }

    $source = in_array((string)($input['source_kind'] ?? ''),['manual','agent','restore','import'],true)
        ? (string)$input['source_kind'] : 'manual';
    $sessionKey = mb_substr(trim((string)($input['session_key'] ?? '')),0,100);
    if ($sessionKey === '') throw new RuntimeException('Missing editor session key.');
    $actionKey = mb_substr(trim((string)($input['action_key'] ?? 'edit')),0,100);
    if ($actionKey === '') $actionKey = 'edit';

    $before = $input['before'] ?? null;
    $after = $input['after'] ?? null;
    $changes = $input['changes'] ?? [];
    if (!is_array($changes)) $changes = [];
    $changes = array_slice($changes,0,500);

    $beforeJson = ledger_v90_json_value($before, 2 * 1024 * 1024);
    $afterJson = ledger_v90_json_value($after, 2 * 1024 * 1024);
    $changesJson = ledger_v90_json_value($changes, 1024 * 1024);
    if ($beforeJson === $afterJson && !$changes) {
        ledger_v90_json(true,['recorded'=>false]);
    }

    $conversationId = max(0,(int)($input['conversation_id'] ?? 0));
    if($conversationId>0){$owned=db()?->prepare('SELECT 1 FROM chat_conversations WHERE id=? AND user_id=? LIMIT 1');$owned?->execute([$conversationId,(int)$user['id']]);if(!$owned||!$owned->fetchColumn())throw new RuntimeException('The Agent conversation is not available to this account.');}
    $playhead = isset($input['playhead_seconds']) ? max(0.0,min(86400.0,(float)$input['playhead_seconds'])) : null;
    $requestText = trim(mb_substr((string)($input['request_text'] ?? ''),0,4000));
    $provider = mb_substr(trim((string)($input['model_provider'] ?? '')),0,30);
    $model = mb_substr(trim((string)($input['model_name'] ?? '')),0,100);

    $stmt = db()?->prepare(
        'INSERT INTO agent_edit_events
         (user_id,conversation_id,editor_kind,project_id,session_key,source_kind,action_key,request_text,model_provider,model_name,playhead_seconds,before_json,after_json,changes_json,created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
    );
    $stmt?->execute([
        (int)$user['id'],
        $conversationId > 0 ? $conversationId : null,
        $editor,
        $projectId,
        $sessionKey,
        $source,
        $actionKey,
        $requestText !== '' ? $requestText : null,
        $provider,
        $model,
        $playhead,
        $beforeJson,
        $afterJson,
        $changesJson,
    ]);
    $eventId = (int)(db()?->lastInsertId() ?? 0);

    if (function_exists('agent_tool_log')) {
        agent_tool_log($user,$editor.'.edit_ledger',$requestText !== '' ? $requestText : $actionKey,'success',[
            'event_id'=>$eventId,
            'project_id'=>$projectId,
            'source'=>$source,
            'changes'=>count($changes),
        ],$conversationId > 0 ? $conversationId : null);
    }

    ledger_v90_json(true,['recorded'=>true,'event_id'=>$eventId]);
} catch (Throwable $e) {
    ledger_v90_json(false,['error'=>$e->getMessage()],400);
}
