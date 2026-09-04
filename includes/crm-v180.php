<?php
declare(strict_types=1);

/**
 * Stonefellow CRM v180
 *
 * Admin-only CRM helpers for website demo submissions, lead workflow,
 * follow-up tasks, activity history, Agent Chat delivery and proactive scans.
 */

function crm_v180_can_manage(?array $user = null): bool
{
    $user ??= current_user();
    return $user !== null && user_has_role('admin', $user);
}

function crm_v180_require_admin(): array
{
    require_permission('admin.access');
    $user = current_user();
    if (!$user || !crm_v180_can_manage($user)) {
        http_response_code(403);
        exit('CRM access is restricted to Stonefellow Admin accounts.');
    }
    return $user;
}

function crm_v180_stages(): array
{
    return [
        'new' => 'New',
        'qualified' => 'Qualified',
        'contacted' => 'Contacted',
        'demo_scheduled' => 'Demo Scheduled',
        'demo_completed' => 'Demo Completed',
        'trial' => 'Trial',
        'won' => 'Won',
        'lost' => 'Lost',
        'archived' => 'Archived',
    ];
}

function crm_v180_priorities(): array
{
    return [
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];
}

function crm_v180_task_types(): array
{
    return [
        'follow_up' => 'Follow Up',
        'call' => 'Call',
        'email' => 'Email',
        'demo' => 'Demo',
        'trial_check' => 'Trial Check',
        'custom' => 'Custom',
    ];
}

function crm_v180_parse_datetime(string $value, string $label = 'date'): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        throw new RuntimeException('Enter a valid ' . $label . '.');
    }
    return date('Y-m-d H:i:s', $timestamp);
}

function crm_v180_schema_ready(?PDO $pdo = null): bool
{
    $pdo ??= db();
    if (!$pdo) return false;
    try {
        foreach (['crm_contacts', 'crm_leads', 'crm_activities', 'crm_tasks'] as $table) {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function crm_v180_ensure_schema(?PDO $pdo = null): void
{
    $pdo ??= db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS crm_contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            email_normalized VARCHAR(190) NOT NULL,
            phone VARCHAR(80) NOT NULL DEFAULT '',
            company VARCHAR(190) NOT NULL DEFAULT '',
            source VARCHAR(60) NOT NULL DEFAULT 'website',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_crm_contacts_email_normalized (email_normalized),
            INDEX idx_crm_contacts_company (company),
            INDEX idx_crm_contacts_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS crm_leads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            contact_id BIGINT UNSIGNED NOT NULL,
            source_contact_message_id INT UNSIGNED NULL,
            source VARCHAR(60) NOT NULL DEFAULT 'book_demo',
            stage VARCHAR(40) NOT NULL DEFAULT 'new',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            role_interest VARCHAR(60) NOT NULL DEFAULT '',
            team_size VARCHAR(30) NOT NULL DEFAULT '',
            workflows_json LONGTEXT NULL,
            demo_focus TEXT NULL,
            internal_notes TEXT NULL,
            assigned_user_id INT UNSIGNED NULL,
            next_follow_up_at DATETIME NULL,
            demo_scheduled_at DATETIME NULL,
            last_contacted_at DATETIME NULL,
            stage_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_crm_leads_source_message (source_contact_message_id),
            INDEX idx_crm_leads_stage_updated (stage, updated_at),
            INDEX idx_crm_leads_assigned_stage (assigned_user_id, stage),
            INDEX idx_crm_leads_followup (next_follow_up_at, stage),
            INDEX idx_crm_leads_demo (demo_scheduled_at, stage),
            INDEX idx_crm_leads_created (created_at),
            CONSTRAINT fk_crm_leads_contact FOREIGN KEY (contact_id) REFERENCES crm_contacts(id) ON DELETE CASCADE,
            CONSTRAINT fk_crm_leads_assigned FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS crm_activities (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,
            activity_type VARCHAR(50) NOT NULL,
            summary VARCHAR(500) NOT NULL,
            details_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_crm_activities_lead_created (lead_id, created_at, id),
            INDEX idx_crm_activities_user_created (user_id, created_at),
            CONSTRAINT fk_crm_activities_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
            CONSTRAINT fk_crm_activities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS crm_tasks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id BIGINT UNSIGNED NOT NULL,
            assigned_user_id INT UNSIGNED NULL,
            created_by_user_id INT UNSIGNED NULL,
            task_type VARCHAR(40) NOT NULL DEFAULT 'follow_up',
            title VARCHAR(190) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            due_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_crm_tasks_status_due (status, due_at),
            INDEX idx_crm_tasks_assigned_due (assigned_user_id, status, due_at),
            INDEX idx_crm_tasks_lead_status (lead_id, status, due_at),
            CONSTRAINT fk_crm_tasks_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
            CONSTRAINT fk_crm_tasks_assigned FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_crm_tasks_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function crm_v180_admin_users(?PDO $pdo = null): array
{
    $pdo ??= db();
    if (!$pdo) return [];
    try {
        if (table_exists('user_account_types')) {
            return $pdo->query(
                "SELECT DISTINCT u.id,u.display_name,u.email,u.role,u.avatar_path,u.is_active,u.last_login_at
                 FROM users u
                 LEFT JOIN user_account_types uat ON uat.user_id=u.id AND uat.role='admin'
                 WHERE u.is_active=1 AND (u.role='admin' OR uat.role='admin')
                 ORDER BY u.display_name,u.id"
            )->fetchAll() ?: [];
        }
        return $pdo->query(
            "SELECT id,display_name,email,role,avatar_path,is_active,last_login_at FROM users
             WHERE is_active=1 AND role='admin' ORDER BY display_name,id"
        )->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function crm_v180_activity(
    PDO $pdo,
    int $leadId,
    string $type,
    string $summary,
    ?int $userId = null,
    array $details = []
): int {
    $json = $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    $stmt = $pdo->prepare(
        'INSERT INTO crm_activities (lead_id,user_id,activity_type,summary,details_json,created_at)
         VALUES (?,?,?,?,?,NOW())'
    );
    $stmt->execute([
        $leadId,
        $userId ?: null,
        mb_strimwidth($type, 0, 50, ''),
        mb_strimwidth($summary, 0, 500, '…'),
        $json,
    ]);
    return (int)$pdo->lastInsertId();
}

function crm_v180_upsert_contact(PDO $pdo, array $data): int
{
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid CRM contact email is required.');
    }

    $name = trim((string)($data['name'] ?? '')) ?: $email;
    $phone = trim((string)($data['phone'] ?? ''));
    $company = trim((string)($data['company'] ?? ''));
    $source = trim((string)($data['source'] ?? 'website')) ?: 'website';

    $stmt = $pdo->prepare(
        "INSERT INTO crm_contacts (name,email,email_normalized,phone,company,source,created_at,updated_at)
         VALUES (?,?,?,?,?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE
           name=VALUES(name),
           email=VALUES(email),
           phone=CASE WHEN VALUES(phone)<>'' THEN VALUES(phone) ELSE phone END,
           company=CASE WHEN VALUES(company)<>'' THEN VALUES(company) ELSE company END,
           source=VALUES(source),
           updated_at=NOW()"
    );
    $stmt->execute([$name, $email, $email, $phone, $company, $source]);

    $find = $pdo->prepare('SELECT id FROM crm_contacts WHERE email_normalized=? LIMIT 1');
    $find->execute([$email]);
    return (int)$find->fetchColumn();
}

function crm_v180_notify_new_lead(PDO $pdo, int $leadId, array $contact, array $lead): void
{
    $admins = crm_v180_admin_users($pdo);
    if (!$admins) return;

    $company = trim((string)($contact['company'] ?? ''));
    $team = trim((string)($lead['team_size'] ?? ''));
    $role = trim((string)($lead['role_interest'] ?? ''));
    $label = trim((string)($contact['name'] ?? 'New lead')) ?: 'New lead';
    if ($company !== '') $label .= ' — ' . $company;

    $bodyParts = [];
    if ($role !== '') $bodyParts[] = ucfirst(str_replace('_', ' ', $role));
    if ($team !== '') $bodyParts[] = $team . ($team === '1' ? ' person' : ' people');
    $body = $bodyParts ? implode(' · ', $bodyParts) : 'New Stonefellow demo request';
    $target = url('/admin/crm-lead.php?id=' . $leadId);

    foreach ($admins as $admin) {
        $adminId = (int)($admin['id'] ?? 0);
        if ($adminId < 1) continue;

        $admin['roles'] = user_account_types_for_user_id(
            $adminId,
            (string)($admin['role'] ?? '')
        );

        if (function_exists('create_notification')) {
            create_notification(
                $adminId,
                'crm_lead',
                'New demo request',
                $label . ($body !== '' ? ' · ' . $body : ''),
                $target,
                'crm_lead',
                $leadId
            );
        }

        if (function_exists('agent_chat_v101_append_ecosystem_message')) {
            $message = 'New demo request from ' . trim((string)($contact['name'] ?? 'a prospect'));
            if ($company !== '') $message .= ' at ' . $company;
            $message .= '. ';
            if ($role !== '') $message .= 'Role: ' . ucfirst(str_replace('_', ' ', $role)) . '. ';
            if ($team !== '') $message .= 'Team size: ' . $team . '. ';
            $workflows = (array)($lead['workflows'] ?? []);
            if ($workflows) $message .= 'Interested in: ' . implode(', ', $workflows) . '. ';
            $message .= 'I added the request to the CRM for follow-up.';

            agent_chat_v101_append_ecosystem_message($admin, $message, [
                'source' => 'crm',
                'lead_id' => $leadId,
                'target_url' => $target,
            ]);
        }
    }
}

function crm_v180_create_demo_lead(
    array $data,
    int $sourceMessageId = 0,
    ?PDO $pdo = null,
    bool $notify = true
): int {
    $pdo ??= db();
    if (!$pdo) return 0;
    crm_v180_ensure_schema($pdo);

    if ($sourceMessageId > 0) {
        $existing = $pdo->prepare('SELECT id FROM crm_leads WHERE source_contact_message_id=? LIMIT 1');
        $existing->execute([$sourceMessageId]);
        $existingId = (int)$existing->fetchColumn();
        if ($existingId > 0) return $existingId;
    }

    $contactId = crm_v180_upsert_contact($pdo, [
        'name' => (string)($data['name'] ?? ''),
        'email' => (string)($data['email'] ?? ''),
        'phone' => (string)($data['phone'] ?? ''),
        'company' => (string)($data['company'] ?? ''),
        'source' => 'book_demo',
    ]);
    if ($contactId < 1) return 0;

    $role = trim((string)($data['role'] ?? $data['role_interest'] ?? ''));
    $team = trim((string)($data['team_size'] ?? ''));
    $workflows = array_values(array_unique(array_filter(array_map('strval', (array)($data['workflows'] ?? [])))));
    $focus = trim((string)($data['notes'] ?? $data['demo_focus'] ?? ''));
    $priority = in_array($team, ['21+', '6-20'], true) ? 'high' : 'normal';
    $json = json_encode($workflows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO crm_leads
             (contact_id,source_contact_message_id,source,stage,priority,role_interest,team_size,workflows_json,demo_focus,stage_changed_at,created_at,updated_at)
             VALUES (?,?, 'book_demo','new',?,?,?,?,?,NOW(),NOW(),NOW())"
        );
        $stmt->execute([
            $contactId,
            $sourceMessageId ?: null,
            $priority,
            $role,
            $team,
            is_string($json) ? $json : '[]',
            $focus,
        ]);
        $leadId = (int)$pdo->lastInsertId();
        crm_v180_activity($pdo, $leadId, 'lead_created', 'Demo request added to the CRM.', null, [
            'source_contact_message_id' => $sourceMessageId ?: null,
            'stage' => 'new',
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($sourceMessageId > 0) {
            $existing = $pdo->prepare('SELECT id FROM crm_leads WHERE source_contact_message_id=? LIMIT 1');
            $existing->execute([$sourceMessageId]);
            $existingId = (int)$existing->fetchColumn();
            if ($existingId > 0) return $existingId;
        }
        throw $e;
    }

    if ($notify) {
        $contactStmt = $pdo->prepare('SELECT * FROM crm_contacts WHERE id=? LIMIT 1');
        $contactStmt->execute([$contactId]);
        $contact = $contactStmt->fetch() ?: [];
        crm_v180_notify_new_lead($pdo, $leadId, $contact, [
            'team_size' => $team,
            'role_interest' => $role,
            'workflows' => $workflows,
        ]);
    }

    return $leadId;
}

function crm_v180_parse_demo_message(string $message): array
{
    $read = static function (string $label) use ($message): string {
        if (preg_match('/^' . preg_quote($label, '/') . ':\s*(.+)$/mi', $message, $m)) {
            return trim((string)$m[1]);
        }
        return '';
    };

    $workflowText = $read('Workflows');
    $workflows = $workflowText !== '' && strcasecmp($workflowText, 'Not specified') !== 0
        ? array_values(array_filter(array_map('trim', explode(',', $workflowText))))
        : [];

    $focus = '';
    if (preg_match('/Requested demo focus:\s*\R(.*)$/si', $message, $m)) {
        $focus = trim((string)$m[1]);
        if (strcasecmp($focus, 'Not provided') === 0) $focus = '';
    }

    $company = $read('Studio / company');
    if (strcasecmp($company, 'Not provided') === 0) $company = '';
    $phone = $read('Phone');
    if (strcasecmp($phone, 'Not provided') === 0) $phone = '';
    $role = $read('Role');
    if (strcasecmp($role, 'Not specified') === 0) $role = '';
    $team = $read('Team size');
    if (strcasecmp($team, 'Not specified') === 0) $team = '';

    return [
        'company' => $company,
        'phone' => $phone,
        'role' => $role,
        'team_size' => $team,
        'workflows' => $workflows,
        'notes' => $focus,
    ];
}

function crm_v180_import_demo_messages(?PDO $pdo = null, int $limit = 250): int
{
    $pdo ??= db();
    if (!$pdo || !table_exists('contact_messages')) return 0;
    crm_v180_ensure_schema($pdo);
    $limit = max(1, min(1000, $limit));

    $rows = $pdo->query(
        "SELECT m.*
         FROM contact_messages m
         LEFT JOIN crm_leads l ON l.source_contact_message_id=m.id
         WHERE m.topic='Book a Demo' AND l.id IS NULL
         ORDER BY m.id ASC
         LIMIT " . $limit
    )->fetchAll() ?: [];

    $count = 0;
    foreach ($rows as $row) {
        $parsed = crm_v180_parse_demo_message((string)($row['message'] ?? ''));
        try {
            $leadId = crm_v180_create_demo_lead(array_merge($parsed, [
                'name' => (string)$row['name'],
                'email' => (string)$row['email'],
            ]), (int)$row['id'], $pdo, false);
            if ($leadId > 0) $count++;
        } catch (Throwable $e) {
            error_log('Stonefellow CRM demo import failed for message #' . (int)$row['id'] . ': ' . $e->getMessage());
        }
    }
    return $count;
}

function crm_v180_create_task(PDO $pdo, int $leadId, array $data, int $creatorUserId): int
{
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') throw new RuntimeException('Task title is required.');

    $type = trim((string)($data['task_type'] ?? 'follow_up'));
    if (!array_key_exists($type, crm_v180_task_types())) $type = 'custom';

    $assigned = max(0, (int)($data['assigned_user_id'] ?? 0));
    if ($assigned > 0) {
        $validAdmin = false;
        foreach (crm_v180_admin_users($pdo) as $candidate) {
            if ((int)$candidate['id'] === $assigned) {
                $validAdmin = true;
                break;
            }
        }
        if (!$validAdmin) throw new RuntimeException('CRM tasks can only be assigned to an Admin account.');
    }

    $dueValue = crm_v180_parse_datetime((string)($data['due_at'] ?? ''), 'task due date');

    $stmt = $pdo->prepare(
        "INSERT INTO crm_tasks
         (lead_id,assigned_user_id,created_by_user_id,task_type,title,status,due_at,created_at,updated_at)
         VALUES (?,?,?,?,?,'open',?,NOW(),NOW())"
    );
    $stmt->execute([
        $leadId,
        $assigned ?: null,
        $creatorUserId ?: null,
        $type,
        mb_strimwidth($title, 0, 190, '…'),
        $dueValue,
    ]);

    $taskId = (int)$pdo->lastInsertId();
    crm_v180_activity($pdo, $leadId, 'task_created', 'Task created: ' . $title, $creatorUserId, [
        'task_id' => $taskId,
        'due_at' => $dueValue,
    ]);
    return $taskId;
}

function crm_v180_agent_opportunities(array $user, string $since = ''): array
{
    if (!crm_v180_can_manage($user)) return [];
    $pdo = db();
    if (!$pdo || !crm_v180_schema_ready($pdo)) return [];
    $since = $since !== '' ? $since : date('Y-m-d H:i:s', time() - 86400);
    $items = [];

    $make = static function (string $key, string $title, string $body, int $leadId, int $priority): array {
        return [
            'id' => 'crm-opportunity-' . sha1($key),
            'type' => 'opportunity',
            'title' => mb_strimwidth($title, 0, 140, '…'),
            'body' => mb_strimwidth($body, 0, 320, '…'),
            'target_url' => url('/admin/crm-lead.php?id=' . $leadId),
            'created_at' => date('Y-m-d H:i:s'),
            'priority' => $priority,
            'source' => 'crm',
            'key' => $key,
        ];
    };

    try {
        // New leads already create an Admin notification and an Agent Chat entry.
        // The proactive scan intentionally starts with unresolved follow-up work
        // so the user's return summary does not repeat the same new-lead event.
        $stmt = $pdo->query(
            "SELECT l.id,l.next_follow_up_at,c.name,c.company
             FROM crm_leads l JOIN crm_contacts c ON c.id=l.contact_id
             WHERE l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at<NOW()
               AND l.stage NOT IN ('won','lost','archived')
             ORDER BY l.next_follow_up_at ASC LIMIT 3"
        );
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $who = (string)$row['name'] . (trim((string)$row['company']) !== '' ? ' at ' . (string)$row['company'] : '');
            $items[] = $make(
                'overdue:' . (int)$row['id'] . ':' . (string)$row['next_follow_up_at'],
                'CRM follow-up overdue: ' . $who,
                'This lead has a follow-up date that has passed. Stonefellow can help you decide the next contact step.',
                (int)$row['id'],
                188
            );
        }

        $stmt = $pdo->query(
            "SELECT l.id,l.demo_scheduled_at,c.name,c.company
             FROM crm_leads l JOIN crm_contacts c ON c.id=l.contact_id
             WHERE l.demo_scheduled_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 36 HOUR)
               AND l.stage NOT IN ('won','lost','archived')
             ORDER BY l.demo_scheduled_at ASC LIMIT 3"
        );
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $who = (string)$row['name'] . (trim((string)$row['company']) !== '' ? ' at ' . (string)$row['company'] : '');
            $items[] = $make(
                'demo:' . (int)$row['id'] . ':' . (string)$row['demo_scheduled_at'],
                'Upcoming demo: ' . $who,
                'A Stonefellow demo is scheduled for ' . date('M j g:i A', strtotime((string)$row['demo_scheduled_at'])) . '. Review the lead before the meeting.',
                (int)$row['id'],
                184
            );
        }

        $stmt = $pdo->query(
            "SELECT l.id,l.created_at,c.name,c.company
             FROM crm_leads l JOIN crm_contacts c ON c.id=l.contact_id
             WHERE l.assigned_user_id IS NULL AND l.stage='new'
               AND l.created_at<DATE_SUB(NOW(),INTERVAL 6 HOUR)
             ORDER BY l.created_at ASC LIMIT 2"
        );
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $who = (string)$row['name'] . (trim((string)$row['company']) !== '' ? ' at ' . (string)$row['company'] : '');
            $items[] = $make(
                'unassigned:' . (int)$row['id'],
                'Unassigned CRM lead: ' . $who,
                'This demo request is still unassigned. Assign an owner so follow-up does not get missed.',
                (int)$row['id'],
                170
            );
        }

        $stmt = $pdo->query(
            "SELECT l.id,l.stage,l.updated_at,c.name,c.company
             FROM crm_leads l JOIN crm_contacts c ON c.id=l.contact_id
             WHERE l.stage IN ('qualified','contacted','demo_completed','trial')
               AND l.updated_at<DATE_SUB(NOW(),INTERVAL 72 HOUR)
             ORDER BY l.updated_at ASC LIMIT 2"
        );
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $who = (string)$row['name'] . (trim((string)$row['company']) !== '' ? ' at ' . (string)$row['company'] : '');
            $items[] = $make(
                'stalled:' . (int)$row['id'] . ':' . (string)$row['stage'],
                'CRM lead may be stalled: ' . $who,
                'This lead has been in ' . (crm_v180_stages()[(string)$row['stage']] ?? (string)$row['stage']) . ' without activity for more than 72 hours.',
                (int)$row['id'],
                162
            );
        }
    } catch (Throwable $e) {
        return [];
    }

    $dedup = [];
    foreach ($items as $item) {
        $key = (string)$item['key'];
        if (!isset($dedup[$key]) || (int)$item['priority'] > (int)$dedup[$key]['priority']) {
            $dedup[$key] = $item;
        }
    }
    $items = array_values($dedup);
    usort($items, static fn(array $a, array $b): int => (int)$b['priority'] <=> (int)$a['priority']);
    return array_slice($items, 0, 6);
}
