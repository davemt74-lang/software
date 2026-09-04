<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user = current_user();
if (!$user || !has_permission('chat.access',$user)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Video Editor access is not available for this account.']);
    exit;
}
if (!media_studio_schema_ready()) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Video Editor storage is not ready. Run the v86 database upgrade.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'),true);
if (!is_array($input)) {
    $input = $_POST;
}
$csrf = (string)($input['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals(csrf_token(),$csrf)) {
    http_response_code(419);
    echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh and try again.']);
    exit;
}

function video_v86_json(bool $ok, array $extra = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok'=>$ok] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function video_v86_project_owned(int $id, array $user): ?array
{
    $pdo = db();
    if (!$pdo || $id < 1) return null;
    $stmt = $pdo->prepare('SELECT * FROM video_editor_projects WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$id,(int)$user['id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function video_v86_sanitize_timeline(array $items, array $user): array
{
    if (count($items) > 300) {
        throw new RuntimeException('A project can contain up to 300 timeline items.');
    }

    $clean = [];
    foreach ($items as $index=>$item) {
        if (!is_array($item)) continue;
        $kind = (string)($item['source_kind'] ?? '');
        $sourceId = max(0,(int)($item['source_id'] ?? 0));
        if ($sourceId < 1 || !in_array($kind,['asset','track'],true)) continue;

        if ($kind === 'asset') {
            $asset = media_studio_asset($sourceId,$user);
            if (!$asset) throw new RuntimeException('Timeline media #'.$sourceId.' is no longer available. Restore or remove that clip before saving.');
            $type = (string)$asset['media_type'];
        } else {
            if (!media_studio_track_visible($sourceId,$user)) throw new RuntimeException('Timeline track #'.$sourceId.' is no longer available to this account.');
            $type = 'audio';
        }

        $clean[] = [
            'id'=>mb_substr((string)($item['id'] ?? ('item-'.$index)),0,80),
            'source_kind'=>$kind,
            'source_id'=>$sourceId,
            'media_type'=>$type,
            'title'=>mb_substr(trim((string)($item['title'] ?? ucfirst($type))),0,190),
            'start'=>max(0.0,min(86400.0,(float)($item['start'] ?? 0))),
            'duration'=>max(0.1,min(86400.0,(float)($item['duration'] ?? ($type === 'photo' ? 5 : 10)))),
            'trim_start'=>max(0.0,min(86400.0,(float)($item['trim_start'] ?? 0))),
            'trim_end'=>max(0.0,min(86400.0,(float)($item['trim_end'] ?? 0))),
            'volume'=>max(0.0,min(1.5,(float)($item['volume'] ?? 1))),
            'muted'=>!empty($item['muted']),
            'opacity'=>max(0.0,min(1.0,(float)($item['opacity'] ?? 1))),
            'fade_in'=>max(0.0,min(60.0,(float)($item['fade_in'] ?? 0))),
            'fade_out'=>max(0.0,min(60.0,(float)($item['fade_out'] ?? 0))),
            'lane'=>max(0,min(7,(int)($item['lane'] ?? 0))),
            'locked'=>!empty($item['locked']),
            'lane_volume'=>max(0.0,min(1.5,(float)($item['lane_volume'] ?? 1))),
            'lane_muted'=>!empty($item['lane_muted']),
            'lane_solo'=>!empty($item['lane_solo']),
            'order'=>count($clean),
        ];
    }
    return $clean;
}

try {
    $action = (string)($input['action'] ?? 'save');

    if ($action === 'save') {
        $projectId = max(0,(int)($input['project_id'] ?? 0));
        $title = trim(mb_substr((string)($input['title'] ?? 'Untitled Video'),0,190));
        if ($title === '') $title = 'Untitled Video';

        $timeline = $input['timeline'] ?? [];
        if (!is_array($timeline)) {
            throw new RuntimeException('Invalid timeline data.');
        }
        $timeline = video_v86_sanitize_timeline($timeline,$user);

        $settings = [
            'width'=>max(320,min(3840,(int)($input['width'] ?? 1920))),
            'height'=>max(240,min(2160,(int)($input['height'] ?? 1080))),
            'fps'=>max(12,min(60,(int)($input['fps'] ?? 30))),
        ];

        $pdo = db();
        $timelineJson = json_encode($timeline,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $settingsJson = json_encode($settings,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

        if ($projectId > 0) {
            if (!video_v86_project_owned($projectId,$user)) {
                throw new RuntimeException('Video project not found.');
            }
            $stmt = $pdo->prepare(
                'UPDATE video_editor_projects
                 SET title=?,settings_json=?,timeline_json=?,updated_at=NOW()
                 WHERE id=? AND user_id=?'
            );
            $stmt->execute([$title,$settingsJson,$timelineJson,$projectId,(int)$user['id']]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO video_editor_projects
                 (user_id,title,settings_json,timeline_json,created_at,updated_at)
                 VALUES (?,?,?,?,NOW(),NOW())'
            );
            $stmt->execute([(int)$user['id'],$title,$settingsJson,$timelineJson]);
            $projectId = (int)$pdo->lastInsertId();
        }

        if (function_exists('agent_tool_log')) {
            agent_tool_log(
                $user,
                'video_editor.save',
                $title,
                'success',
                ['project_id'=>$projectId,'items'=>count($timeline)],
                0
            );
        }
        if (function_exists('agent_brain_store_memory')) {
            agent_brain_store_memory(
                $user,
                'project',
                'Video project #' . $projectId,
                'Video Editor project "' . $title . '" has ' . count($timeline) . ' timeline item(s).',
                0,
                0.9,
                ['project_id'=>$projectId,'kind'=>'video_editor']
            );
        }

        video_v86_json(true,[
            'project_id'=>$projectId,
            'url'=>url('/video-editor.php?project='.$projectId),
            'message'=>'Video project saved.',
        ]);
    }

    if ($action === 'delete') {
        $projectId = max(0,(int)($input['project_id'] ?? 0));
        if (!video_v86_project_owned($projectId,$user)) {
            throw new RuntimeException('Video project not found.');
        }
        $stmt = db()?->prepare('DELETE FROM video_editor_projects WHERE id=? AND user_id=?');
        $stmt?->execute([$projectId,(int)$user['id']]);
        video_v86_json(true);
    }

    throw new RuntimeException('Unknown Video Editor action.');
} catch (Throwable $e) {
    video_v86_json(false,['error'=>$e->getMessage()],400);
}
