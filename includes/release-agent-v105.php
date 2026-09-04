<?php
declare(strict_types=1);

function release_v105_schema_ready(): bool
{
    return table_exists('release_plans')
        && table_exists('release_items')
        && table_exists('agent_resources')
        && table_exists('release_item_resources')
        && table_exists('agent_integrations')
        && table_exists('agent_work_actions')
        && table_exists('track_credits');
}

function release_v105_workspace_owner_id(?array $user = null): int
{
    $user ??= current_user();
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) return 0;

    if (user_has_role('artist', $user)) {
        return $userId;
    }

    $pdo = db();
    if ($pdo && table_exists('artist_team_members')) {
        try {
            $stmt = $pdo->prepare(
                'SELECT artist_user_id
                 FROM artist_team_members
                 WHERE member_user_id=?
                 LIMIT 1'
            );
            $stmt->execute([$userId]);
            $ownerId = (int)$stmt->fetchColumn();
            if ($ownerId > 0) return $ownerId;
        } catch (Throwable $e) {}
    }

    return $userId;
}

function release_v105_statuses(): array
{
    return [
        'planning'=>'Planning',
        'active'=>'Active',
        'scheduled'=>'Scheduled',
        'released'=>'Released',
        'paused'=>'Paused',
        'cancelled'=>'Cancelled',
    ];
}

function release_v105_item_statuses(): array
{
    return [
        'todo'=>'To Do',
        'in_progress'=>'In Progress',
        'blocked'=>'Blocked',
        'waiting'=>'Waiting',
        'scheduled'=>'Scheduled',
        'complete'=>'Complete',
        'cancelled'=>'Cancelled',
    ];
}

function release_v105_item_types(): array
{
    return [
        'song'=>'Song / Master',
        'artwork'=>'Artwork',
        'video'=>'Video',
        'social'=>'Social Asset',
        'show'=>'Show / Event',
        'email'=>'Email',
        'sms'=>'SMS',
        'press'=>'Press / Outreach',
        'distribution'=>'Distribution',
        'document'=>'Document',
        'website'=>'Website',
        'deadline'=>'Deadline',
        'task'=>'Task',
    ];
}

function release_v105_resource_types(): array
{
    return [
        'contact_list'=>'Contact List',
        'document'=>'Document',
        'website'=>'Website',
        'media'=>'Media Asset',
        'track'=>'Track',
        'show'=>'Show',
        'artwork'=>'Artwork',
        'video'=>'Video',
        'social'=>'Social Account / Asset',
        'email'=>'Email Resource',
        'sms'=>'SMS Resource',
        'spreadsheet'=>'Spreadsheet / List',
        'other'=>'Other',
    ];
}

function release_v105_release_types(): array
{
    return [
        'single'=>'Single',
        'ep'=>'EP',
        'album'=>'Album',
        'video'=>'Video',
        'show'=>'Show / Event',
        'campaign'=>'Campaign',
        'other'=>'Other',
    ];
}

function release_v105_clean_enum(string $value, array $allowed, string $fallback): string
{
    return array_key_exists($value, $allowed) ? $value : $fallback;
}

function release_v105_datetime_or_null(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    $time = strtotime($value);
    if ($time === false) throw new RuntimeException('Enter a valid date/time.');
    return date('Y-m-d H:i:s', $time);
}

function release_v105_plans(array $user, int $limit = 100): array
{
    $pdo = db();
    $owner = release_v105_workspace_owner_id($user);
    if (!$pdo || !$owner || !release_v105_schema_ready()) return [];
    $stmt = $pdo->prepare(
        'SELECT rp.*,
                (SELECT COUNT(*) FROM release_items ri WHERE ri.release_id=rp.id) AS item_count,
                (SELECT COUNT(*) FROM release_items ri WHERE ri.release_id=rp.id AND ri.status="complete") AS complete_count,
                (SELECT MIN(ri.due_at) FROM release_items ri WHERE ri.release_id=rp.id AND ri.status NOT IN ("complete","cancelled") AND ri.due_at IS NOT NULL) AS next_due_at
         FROM release_plans rp
         WHERE rp.owner_user_id=?
         ORDER BY COALESCE(rp.target_date,"2999-12-31") ASC,rp.updated_at DESC,rp.id DESC
         LIMIT ' . max(1, min(250, $limit))
    );
    $stmt->execute([$owner]);
    return $stmt->fetchAll() ?: [];
}

function release_v105_plan(array $user, int $releaseId): ?array
{
    $pdo = db();
    $owner = release_v105_workspace_owner_id($user);
    if (!$pdo || $releaseId < 1 || !$owner || !release_v105_schema_ready()) return null;
    $stmt = $pdo->prepare('SELECT * FROM release_plans WHERE id=? AND owner_user_id=? LIMIT 1');
    $stmt->execute([$releaseId, $owner]);
    return $stmt->fetch() ?: null;
}

function release_v105_items(array $user, int $releaseId): array
{
    if (!release_v105_plan($user, $releaseId)) return [];
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT ri.*,u.display_name AS assignee_name,t.title AS track_title,s.venue AS show_venue,s.show_date,
                (SELECT COUNT(*) FROM release_item_resources rr WHERE rr.release_item_id=ri.id) AS resource_count,
                (SELECT COUNT(*) FROM agent_work_actions wa WHERE wa.release_item_id=ri.id AND wa.status NOT IN ("complete","cancelled")) AS pending_action_count
         FROM release_items ri
         LEFT JOIN users u ON u.id=ri.assigned_user_id
         LEFT JOIN tracks t ON t.id=ri.track_id
         LEFT JOIN shows s ON s.id=ri.show_id
         WHERE ri.release_id=?
         ORDER BY COALESCE(ri.due_at,"2999-12-31") ASC,ri.sort_order ASC,ri.id ASC'
    );
    $stmt->execute([$releaseId]);
    return $stmt->fetchAll() ?: [];
}

function release_v105_resources(array $user, int $limit = 200): array
{
    $pdo = db();
    $owner = release_v105_workspace_owner_id($user);
    if (!$pdo || !$owner || !release_v105_schema_ready()) return [];
    $stmt = $pdo->prepare(
        'SELECT * FROM agent_resources
         WHERE owner_user_id=? AND is_active=1
         ORDER BY updated_at DESC,id DESC
         LIMIT ' . max(1, min(500, $limit))
    );
    $stmt->execute([$owner]);
    return $stmt->fetchAll() ?: [];
}

function release_v105_integrations(array $user): array
{
    $pdo = db();
    $owner = release_v105_workspace_owner_id($user);
    if (!$pdo || !$owner || !release_v105_schema_ready()) return [];
    $stmt = $pdo->prepare(
        'SELECT provider_key,connection_key,label,status,capabilities_json,metadata_json,last_sync_at,updated_at
         FROM agent_integrations
         WHERE owner_user_id=?
         ORDER BY status="connected" DESC,provider_key,label'
    );
    $stmt->execute([$owner]);
    return $stmt->fetchAll() ?: [];
}

function release_v105_enqueue_action(
    array $user,
    string $providerKey,
    string $actionType,
    array $input = [],
    ?int $releaseId = null,
    ?int $itemId = null,
    bool $requiresApproval = true,
    ?string $scheduledFor = null,
    string $sourceKind = 'agent'
): int {
    $pdo = db();
    $owner = release_v105_workspace_owner_id($user);
    if (!$pdo || !$owner || !release_v105_schema_ready()) return 0;
    if ($releaseId && !release_v105_plan($user, $releaseId)) {
        throw new RuntimeException('Release plan is not available to this workspace.');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO agent_work_actions
         (owner_user_id,release_id,release_item_id,provider_key,action_type,status,source_kind,requires_approval,scheduled_for,input_json)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $owner,
        $releaseId ?: null,
        $itemId ?: null,
        mb_substr(trim($providerKey), 0, 80),
        mb_substr(trim($actionType), 0, 80),
        $requiresApproval ? 'draft' : 'queued',
        mb_substr($sourceKind, 0, 30),
        $requiresApproval ? 1 : 0,
        $scheduledFor,
        json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    return (int)$pdo->lastInsertId();
}

function release_v105_agent_context(array $user, int $limit = 16): array
{
    if (!release_v105_schema_ready()) return [];
    $plans = release_v105_plans($user, 30);
    if (!$plans) return [];

    $context = [];
    $now = time();
    foreach (array_slice($plans, 0, max(1, min(12, $limit))) as $plan) {
        $releaseId = (int)$plan['id'];
        $target = trim((string)($plan['target_date'] ?? ''));
        $targetText = $target !== '' ? $target : 'no target date';
        $itemCount = (int)$plan['item_count'];
        $complete = (int)$plan['complete_count'];
        $lines = [
            'Release #' . $releaseId . ' · ' . (string)$plan['title'],
            'Type: ' . (string)$plan['release_type'] . ' · status: ' . (string)$plan['status'] . ' · priority: ' . (string)$plan['priority'],
            'Target: ' . $targetText . ' · progress: ' . $complete . '/' . $itemCount . ' items complete',
        ];
        if (trim((string)$plan['agent_goal']) !== '') $lines[] = 'Agent goal: ' . trim((string)$plan['agent_goal']);
        if (trim((string)$plan['notes']) !== '') $lines[] = 'Notes: ' . trim((string)$plan['notes']);

        $items = release_v105_items($user, $releaseId);
        foreach (array_slice($items, 0, 12) as $item) {
            $due = trim((string)($item['due_at'] ?? ''));
            $urgency = '';
            if ($due !== '' && !in_array((string)$item['status'], ['complete','cancelled'], true)) {
                $delta = strtotime($due) - $now;
                if ($delta < 0) $urgency = ' · OVERDUE';
                elseif ($delta <= 3 * 86400) $urgency = ' · due soon';
            }
            $lines[] = '- ' . strtoupper((string)$item['status']) . ' · ' . (string)$item['item_type'] . ' · ' . (string)$item['title'] . ($due !== '' ? ' · due ' . $due : '') . $urgency;
        }
        $context[] = [
            'source'=>'agent-brain:release:' . $releaseId,
            'title'=>'Release operations · ' . (string)$plan['title'],
            'text'=>implode("\n", $lines),
        ];
    }

    $integrations = release_v105_integrations($user);
    $resources = release_v105_resources($user, 40);
    $summary = [];
    foreach ($integrations as $integration) {
        $summary[] = 'Integration ' . (string)$integration['provider_key'] . ' · ' . (string)$integration['status'] . ' · ' . ((string)$integration['label'] ?: (string)$integration['connection_key']);
    }
    foreach (array_slice($resources, 0, 20) as $resource) {
        $summary[] = 'Resource #' . (int)$resource['id'] . ' · ' . (string)$resource['resource_type'] . ' · ' . (string)$resource['title'] . (($resource['provider_key'] ?? '') !== '' ? ' · provider ' . (string)$resource['provider_key'] : '');
    }
    if ($summary) {
        $context[] = [
            'source'=>'agent-brain:operations-resources',
            'title'=>'Agent Operations resources and connected capabilities',
            'text'=>implode("\n", $summary),
        ];
    }

    return $context;
}

function credits_v105_rows(array $user, int $trackId): array
{
    $pdo = db();
    $track = get_track_by_id($trackId);
    if (!$pdo || !$track || !release_v105_schema_ready()) return [];
    if (!can_view_track($track, $user) && !agent_tool_can_studio($track, $user) && !has_permission('tracks.manage', $user)) return [];

    $contributors = [];
    $add = static function(array &$rows, int $userId, string $name, string $role, string $detail = '', string $source = 'automatic', int $sourceId = 0): void {
        $key = mb_strtolower(trim(($userId > 0 ? 'u:' . $userId : 'n:' . $name) . '|' . $role));
        if ($key === '|') return;
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'user_id'=>$userId,
                'display_name'=>$name,
                'contribution_role'=>$role,
                'contribution_detail'=>$detail,
                'source_kind'=>$source,
                'source_id'=>$sourceId,
            ];
        } elseif ($detail !== '' && !str_contains((string)$rows[$key]['contribution_detail'], $detail)) {
            $rows[$key]['contribution_detail'] = trim((string)$rows[$key]['contribution_detail'] . '; ' . $detail, '; ');
        }
    };

    foreach ([
        [(int)($track['owner_user_id'] ?? 0), 'Artist / Owner'],
        [(int)($track['producer_user_id'] ?? 0), 'Producer'],
    ] as [$uid, $role]) {
        if ($uid < 1) continue;
        $stmt = $pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$uid]);
        $name = (string)$stmt->fetchColumn();
        if ($name !== '') $add($contributors, $uid, $name, $role, '', 'track');
    }

    if (table_exists('track_projects')) {
        $stmt = $pdo->prepare('SELECT imported_by_user_id FROM track_projects WHERE track_id=? LIMIT 1');
        $stmt->execute([$trackId]);
        $uid = (int)$stmt->fetchColumn();
        if ($uid > 0) {
            $u = $pdo->prepare('SELECT display_name FROM users WHERE id=?');
            $u->execute([$uid]);
            $name = (string)$u->fetchColumn();
            if ($name !== '') $add($contributors, $uid, $name, 'Production / Stem Import', '', 'project');
        }
    }

    if (table_exists('track_notes')) {
        $stmt = $pdo->prepare(
            'SELECT n.user_id,u.display_name,COUNT(*) AS note_count
             FROM track_notes n JOIN users u ON u.id=n.user_id
             WHERE n.track_id=?
             GROUP BY n.user_id,u.display_name'
        );
        $stmt->execute([$trackId]);
        foreach ($stmt->fetchAll() as $row) {
            $add($contributors, (int)$row['user_id'], (string)$row['display_name'], 'Production Notes', (int)$row['note_count'] . ' contribution note(s)', 'track_notes');
        }
    }

    $stmt = $pdo->prepare(
        'SELECT tc.*,COALESCE(NULLIF(tc.display_name,""),u.display_name,"Unknown") AS resolved_name
         FROM track_credits tc LEFT JOIN users u ON u.id=tc.user_id
         WHERE tc.track_id=? ORDER BY tc.sort_order,tc.id'
    );
    $stmt->execute([$trackId]);
    foreach ($stmt->fetchAll() as $row) {
        $add(
            $contributors,
            (int)($row['user_id'] ?? 0),
            (string)$row['resolved_name'],
            (string)$row['contribution_role'],
            (string)$row['contribution_detail'],
            (string)$row['source_kind'],
            (int)($row['id'] ?? 0)
        );
    }

    return array_values($contributors);
}
