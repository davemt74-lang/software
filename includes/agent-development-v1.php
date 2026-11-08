<?php
declare(strict_types=1);

require_once __DIR__.'/section12-data.php';
require_once __DIR__.'/agent-tool-json-v1.php';

const VP3_DEV_MODES_V1 = ['off', 'outline', 'code_stage'];
const VP3_DEV_BUILD_LEASE_V1 = 1800;
const VP3_DEV_STAGE_TTL_V1 = 86400;

function vp3_dev_is_admin_v1(array $user): bool
{
    return (int)($user['id'] ?? 0) > 0 && user_has_role('admin', $user);
}

function vp3_dev_require_admin_v1(array $user): int
{
    if (!vp3_dev_is_admin_v1($user)) throw new DomainException('Platform administrator access is required.', 403);
    return (int)$user['id'];
}

function vp3_dev_install_v1(PDO $pdo): void
{
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_development_controls_v1 (
        owner_user_id INT UNSIGNED NOT NULL PRIMARY KEY, mode VARCHAR(16) NOT NULL,
        epoch BIGINT UNSIGNED NOT NULL, updated_at BIGINT NOT NULL,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_development_revisions_v1 (
        id CHAR(32) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NULL,
        epoch BIGINT UNSIGNED NOT NULL, mode VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL,
        request_text TEXT NOT NULL, base_sha CHAR(40) NOT NULL, result_json TEXT NULL,
        lease_hash CHAR(64) NULL, lease_expires_at BIGINT NOT NULL DEFAULT 0, stage_expires_at BIGINT NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE SET NULL"
        .($sqlite ? '' : ', INDEX idx_dev_owner_v1 (owner_user_id,created_at), INDEX idx_dev_queue_v1 (status,created_at)')
        .")$engine");
    if ($sqlite) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dev_owner_v1 ON agent_development_revisions_v1(owner_user_id,created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dev_queue_v1 ON agent_development_revisions_v1(status,created_at)');
    }
}

function vp3_dev_schema_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT mode,epoch FROM agent_development_controls_v1 WHERE 1=0');
        $pdo->query('SELECT lease_hash,stage_expires_at,result_json FROM agent_development_revisions_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function vp3_dev_control_v1(PDO $pdo, int $owner): array
{
    $query = $pdo->prepare('SELECT mode,epoch FROM agent_development_controls_v1 WHERE owner_user_id=?');
    $query->execute([$owner]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return $row ? ['mode'=>$row['mode'], 'epoch'=>(int)$row['epoch']] : ['mode'=>'off', 'epoch'=>0];
}

function vp3_dev_set_mode_v1(PDO $pdo, array $user, string $mode, int $expectedEpoch): void
{
    $owner = vp3_dev_require_admin_v1($user);
    if (!in_array($mode, VP3_DEV_MODES_V1, true)) throw new DomainException('Choose a valid automation level.', 422);
    section12_owner_transaction($pdo, $owner, static function () use ($pdo, $user, $owner, $mode, $expectedEpoch): void {
        vp3_dev_require_admin_v1($user);
        $control = vp3_dev_control_v1($pdo, $owner);
        if ($control['epoch'] !== $expectedEpoch) throw new DomainException('The automation level changed. Reload and try again.', 409);
        if ($control['mode'] === $mode) return;
        $now = time();
        if ($expectedEpoch === 0) {
            $pdo->prepare('INSERT INTO agent_development_controls_v1(owner_user_id,mode,epoch,updated_at) VALUES (?,?,1,?)')
                ->execute([$owner, $mode, $now]);
        } else {
            $pdo->prepare('UPDATE agent_development_controls_v1 SET mode=?,epoch=epoch+1,updated_at=? WHERE owner_user_id=?')
                ->execute([$mode, $now, $owner]);
        }
        $pdo->prepare("UPDATE agent_development_revisions_v1 SET status='cancelled',lease_hash=NULL,lease_expires_at=0,updated_at=?
            WHERE owner_user_id=? AND status IN ('outlining','queued','building')")->execute([$now, $owner]);
    });
}

function vp3_dev_runner_token_v1(): string
{
    $value = vp3_dev_setting_v1('VP3_AGENT_DEVELOPMENT_RUNNER_TOKEN', 'agent_development_runner_token');
    return preg_match('/\A[A-Za-z0-9_-]{43,128}\z/D', $value) ? $value : '';
}

function vp3_dev_setting_v1(string $variable, string $key): string
{
    $value = getenv($variable);
    return $value !== false ? $value : (function_exists('site_config') ? (string)site_config($key, '') : '');
}

function vp3_dev_base_sha_v1(): string
{
    $sha = trim(vp3_dev_setting_v1('VP3_AGENT_DEVELOPMENT_BASE_SHA', 'agent_development_base_sha'));
    if ($sha === '') {
        $file = dirname(__DIR__).'/VP3_RELEASE.json';
        $release = is_file($file) ? json_decode((string)file_get_contents($file, false, null, 0, 8192), true) : null;
        if (($release['repository'] ?? '') === 'davemt74-lang/software') $sha = (string)($release['commit'] ?? '');
    }
    return preg_match('/\A[0-9a-f]{40}\z/D', $sha) ? $sha : '';
}

function vp3_dev_preview_origin_v1(): string
{
    $origin = rtrim(trim(vp3_dev_setting_v1('VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN', 'agent_development_preview_origin')), '/');
    if (!preg_match('/\Ahttps:\/\/([a-z0-9-]+(?:\.[a-z0-9-]+)+)(?::([1-9][0-9]{0,4}))?\z/D', $origin, $parts)
        || (int)($parts[2] ?? 0) === 443 || (int)($parts[2] ?? 0) > 65535 || filter_var($parts[1], FILTER_VALIDATE_IP)
        || !filter_var($parts[1], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) return '';
    $production = function_exists('site_config') ? (string)site_config('base_url', '') : '';
    foreach ([parse_url($production, PHP_URL_HOST), parse_url('https://'.($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)] as $host) {
        $host = strtolower(rtrim((string)$host, '.'));
        if ($host !== '' && ($parts[1] === $host || str_ends_with($parts[1], '.'.$host) || str_ends_with($host, '.'.$parts[1]))) return '';
    }
    return $origin;
}

function vp3_dev_runner_ready_v1(): bool
{
    return vp3_dev_runner_token_v1() !== '' && vp3_dev_preview_origin_v1() !== '' && vp3_dev_base_sha_v1() !== '';
}

function vp3_dev_text_v1(mixed $value, int $limit): string
{
    if (!is_string($value) || strlen($value) > $limit * 4 || !mb_check_encoding($value, 'UTF-8')) {
        throw new DomainException('Enter a shorter development description.', 422);
    }
    $text = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if ($text === '' || mb_strlen($text) > $limit || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $text)) {
        throw new DomainException('Enter a valid development description.', 422);
    }
    if (preg_match('/(?:GOCSPX-|gh[pousr]_|github_pat_|sk-(?:proj-|ant-)?[a-z0-9_-]{16}|AIza[a-z0-9_-]{30}|-----BEGIN [A-Z ]*PRIVATE KEY-----|\bBearer\s+[a-z0-9_.-]{16}|\b(?:api[_ -]?key|client[_ -]?secret|password|token)\s*[:=]\s*\S{8})/i', $text)) {
        throw new DomainException('Remove credentials before submitting the development request.', 422);
    }
    return preg_replace('/[a-z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-z0-9.-]+\.[a-z]{2,}/i', 'sender@example.test', $text) ?? $text;
}

function vp3_dev_lines_v1(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 8) {
        throw new DomainException('Development verification details are incomplete.', 422);
    }
    return array_map(static fn($line) => vp3_dev_text_v1($line, 300), $value);
}

function vp3_dev_stage_current_v1(array $row): bool
{
    $origin = vp3_dev_preview_origin_v1();
    $head = $row['head_sha'] ?? '';
    return in_array($row['status'] ?? '', ['staged','approved'], true)
        && (int)($row['stage_expires_at'] ?? 0) > time()
        && ($row['base_sha'] ?? '') !== '' && $row['base_sha'] === vp3_dev_base_sha_v1()
        && $origin !== '' && is_string($head) && preg_match('/\A[0-9a-f]{40}\z/D', $head)
        && ($row['preview_url'] ?? '') === $origin.'/revisions/'.$head.'/';
}

function vp3_dev_public_v1(array $row): array
{
    $result = json_decode((string)($row['result_json'] ?? ''), true) ?: [];
    $status = $row['status'];
    if (in_array($status, ['building','outlining'], true) && (int)$row['lease_expires_at'] <= time()) $status = 'failed';
    return [
        'id'=>$row['id'], 'epoch'=>(int)$row['epoch'], 'mode'=>$row['mode'], 'status'=>$status,
        'request'=>$row['request_text'], 'base_sha'=>$row['base_sha'],
        'summary'=>$result['summary'] ?? ($status === 'failed' ? 'The development run expired.' : ''),
        'plan'=>$result['plan'] ?? [], 'checks'=>$result['checks'] ?? [],
        'head_sha'=>$result['head_sha'] ?? '', 'artifact_sha'=>$result['artifact_sha'] ?? '',
        'preview_url'=>$result['preview_url'] ?? '', 'stage_expires_at'=>(int)$row['stage_expires_at'],
        'created_at'=>gmdate(DATE_ATOM, (int)$row['created_at']), 'updated_at'=>gmdate(DATE_ATOM, (int)$row['updated_at']),
    ];
}

function vp3_dev_row_v1(PDO $pdo, int $owner, string $id): ?array
{
    if (!preg_match('/\A[0-9a-f]{32}\z/D', $id)) return null;
    $query = $pdo->prepare('SELECT * FROM agent_development_revisions_v1 WHERE owner_user_id=? AND id=?');
    $query->execute([$owner, $id]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function vp3_dev_revision_v1(PDO $pdo, int $owner, string $id): ?array
{
    $row = vp3_dev_row_v1($pdo, $owner, $id);
    return $row ? vp3_dev_public_v1($row) : null;
}

function vp3_dev_list_v1(PDO $pdo, int $owner, ?int $conversationId = null): array
{
    $query = $pdo->prepare('SELECT * FROM agent_development_revisions_v1 WHERE owner_user_id=?'
        .($conversationId !== null ? ' AND conversation_id=?' : '').' ORDER BY created_at DESC,id DESC LIMIT 40');
    $query->execute($conversationId !== null ? [$owner, $conversationId] : [$owner]);
    return array_map('vp3_dev_public_v1', $query->fetchAll(PDO::FETCH_ASSOC));
}

function vp3_dev_create_v1(PDO $pdo, array $user, string $request, int $expectedEpoch, ?int $conversationId = null, ?callable $generate = null): array
{
    $owner = vp3_dev_require_admin_v1($user);
    $request = vp3_dev_text_v1($request, 1500);
    $id = bin2hex(random_bytes(16));
    $mode = section12_owner_transaction($pdo, $owner, static function () use ($pdo, $user, $owner, $id, $expectedEpoch, $request, $conversationId): string {
        vp3_dev_require_admin_v1($user);
        $control = vp3_dev_control_v1($pdo, $owner);
        if ($control['epoch'] !== $expectedEpoch) throw new DomainException('The automation level changed. Reload and try again.', 409);
        $mode = $control['mode'];
        if ($mode === 'off') throw new DomainException('Enable an automation level in Agent Development.', 409);
        $base = vp3_dev_base_sha_v1();
        if ($mode === 'code_stage' && ($base === '' || vp3_dev_runner_token_v1() === '' || vp3_dev_preview_origin_v1() === '')) {
            throw new DomainException('Configure the isolated development runner and preview origin first.', 503);
        }
        if ($conversationId !== null) {
            $chat = $pdo->prepare('SELECT id FROM chat_conversations WHERE user_id=? AND id=?');
            $chat->execute([$owner, $conversationId]);
            if (!$chat->fetchColumn()) throw new DomainException('This private Chat is unavailable.', 404);
        }
        $now = time();
        if ($mode === 'code_stage') {
            $pdo->prepare("UPDATE agent_development_revisions_v1 SET status='cancelled',lease_hash=NULL,lease_expires_at=0,updated_at=?
                WHERE owner_user_id=? AND mode='code_stage' AND base_sha<>? AND status IN ('queued','building')")
                ->execute([$now, $owner, $base]);
        }
        $active = $pdo->prepare("SELECT 1 FROM agent_development_revisions_v1 WHERE owner_user_id=? AND
            (status='queued' OR (status IN ('outlining','building') AND lease_expires_at>?)) LIMIT 1");
        $active->execute([$owner, $now]);
        if ($active->fetchColumn()) throw new DomainException('A development revision is already in progress.', 409);
        $pdo->prepare('INSERT INTO agent_development_revisions_v1
            (id,owner_user_id,conversation_id,epoch,mode,status,request_text,base_sha,lease_expires_at,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$id, $owner, $conversationId, $expectedEpoch, $mode,
                $mode === 'outline' ? 'outlining' : 'queued', $request, $base, $mode === 'outline' ? $now + 180 : 0, $now, $now]);
        return $mode;
    });
    if ($mode === 'outline') {
        $status = 'proposed';
        try {
            $input = json_encode(['request'=>$request, 'repository'=>'davemt74-lang/software'], JSON_THROW_ON_ERROR);
            $output = $generate ? $generate($input) : ai_generate_chat_response($input, [], [], $user, 'main_ai',
                ['surface'=>'tool_json','protocol'=>'vp3.agent-development-outline.v1']);
            if (empty($output['ok'])) throw new RuntimeException('Provider unavailable.');
            $draft = vp3_tool_json_object_v1((string)($output['answer'] ?? ''), ['summary','plan','checks'], 12000);
            $result = ['summary'=>vp3_dev_text_v1($draft['summary'] ?? null, 500),
                'plan'=>vp3_dev_lines_v1($draft['plan'] ?? null), 'checks'=>vp3_dev_lines_v1($draft['checks'] ?? null)];
        } catch (Throwable) {
            $status = 'failed';
            $result = ['summary'=>'The outline could not be verified. Check the configured model and retry.'];
        }
        section12_owner_transaction($pdo, $owner, static function () use ($pdo, $user, $owner, $id, $expectedEpoch, $status, $result): void {
            vp3_dev_require_admin_v1($user);
            if (vp3_dev_control_v1($pdo, $owner) !== ['mode'=>'outline','epoch'=>$expectedEpoch]) return;
            $pdo->prepare("UPDATE agent_development_revisions_v1 SET status=?,result_json=?,lease_expires_at=0,updated_at=?
                WHERE id=? AND owner_user_id=? AND status='outlining' AND lease_expires_at>?")
                ->execute([$status, json_encode($result, JSON_THROW_ON_ERROR), time(), $id, $owner, time()]);
        });
    }
    return vp3_dev_revision_v1($pdo, $owner, $id) ?? throw new DomainException('The revision is unavailable.', 404);
}

function vp3_dev_cancel_v1(PDO $pdo, array $user, string $id): void
{
    $owner = vp3_dev_require_admin_v1($user);
    section12_owner_transaction($pdo, $owner, static function () use ($pdo, $user, $owner, $id): void {
        vp3_dev_require_admin_v1($user);
        $query = $pdo->prepare("UPDATE agent_development_revisions_v1 SET status='cancelled',lease_hash=NULL,lease_expires_at=0,stage_expires_at=0,updated_at=?
            WHERE owner_user_id=? AND id=? AND status IN ('outlining','queued','building','staged')");
        $query->execute([time(), $owner, $id]);
        if ($query->rowCount() !== 1) throw new DomainException('This revision can no longer be cancelled.', 409);
    });
}

function vp3_dev_approve_v1(PDO $pdo, array $user, string $id, string $headSha, string $artifactSha): void
{
    $owner = vp3_dev_require_admin_v1($user);
    section12_owner_transaction($pdo, $owner, static function () use ($pdo, $user, $owner, $id, $headSha, $artifactSha): void {
        vp3_dev_require_admin_v1($user);
        $row = vp3_dev_revision_v1($pdo, $owner, $id);
        if (!$row || $row['status'] !== 'staged' || !vp3_dev_stage_current_v1($row)
            || vp3_dev_control_v1($pdo, $owner) !== ['mode'=>'code_stage','epoch'=>$row['epoch']]
            || !hash_equals($row['head_sha'], $headSha) || !hash_equals($row['artifact_sha'], $artifactSha)) {
            throw new DomainException('Review the current verified preview before approving this candidate.', 409);
        }
        $pdo->prepare("UPDATE agent_development_revisions_v1 SET status='approved',updated_at=? WHERE owner_user_id=? AND id=?")
            ->execute([time(), $owner, $id]);
    });
}

function vp3_dev_claim_v1(PDO $pdo): ?array
{
    $jobs = $pdo->query("SELECT id,owner_user_id FROM agent_development_revisions_v1 WHERE status='queued' ORDER BY created_at,id LIMIT 20");
    foreach ($jobs->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        $owner = (int)$candidate['owner_user_id'];
        if (!vp3_dev_is_admin_v1(['id'=>$owner])) {
            $pdo->prepare("UPDATE agent_development_revisions_v1 SET status='cancelled',updated_at=? WHERE id=? AND status='queued'")
                ->execute([time(), $candidate['id']]);
            continue;
        }
        $claimed = section12_owner_transaction($pdo, $owner, static function () use ($pdo, $owner, $candidate): ?array {
            $row = vp3_dev_row_v1($pdo, $owner, $candidate['id']);
            if (!$row || $row['status'] !== 'queued' || $row['mode'] !== 'code_stage') return null;
            if (!vp3_dev_is_admin_v1(['id'=>$owner])
                || vp3_dev_control_v1($pdo, $owner) !== ['mode'=>'code_stage','epoch'=>(int)$row['epoch']]
                || $row['base_sha'] !== vp3_dev_base_sha_v1()) {
                $pdo->prepare("UPDATE agent_development_revisions_v1 SET status='cancelled',updated_at=? WHERE id=?")
                    ->execute([time(), $row['id']]);
                return null;
            }
            $lease = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE agent_development_revisions_v1 SET status='building',lease_hash=?,lease_expires_at=?,updated_at=? WHERE id=?")
                ->execute([hash('sha256', $lease), time() + VP3_DEV_BUILD_LEASE_V1, time(), $row['id']]);
            return ['id'=>$row['id'], 'lease'=>$lease, 'request'=>$row['request_text'], 'base_sha'=>$row['base_sha']];
        });
        if ($claimed) return $claimed;
    }
    return null;
}

function vp3_dev_finish_v1(PDO $pdo, string $id, string $lease, ?array $result): void
{
    if (!preg_match('/\A[0-9a-f]{32}\z/D', $id) || !preg_match('/\A[0-9a-f]{64}\z/D', $lease)) {
        throw new DomainException('Invalid development lease.', 422);
    }
    $ownerQuery = $pdo->prepare('SELECT owner_user_id FROM agent_development_revisions_v1 WHERE id=?');
    $ownerQuery->execute([$id]);
    $owner = (int)$ownerQuery->fetchColumn();
    if ($owner < 1 || !vp3_dev_is_admin_v1(['id'=>$owner])) throw new DomainException('Development lease is unavailable.', 409);
    section12_owner_transaction($pdo, $owner, static function () use ($pdo, $owner, $id, $lease, $result): void {
        $row = vp3_dev_row_v1($pdo, $owner, $id);
        if (!$row || $row['status'] !== 'building' || (int)$row['lease_expires_at'] <= time()
            || !hash_equals((string)$row['lease_hash'], hash('sha256', $lease)) || !vp3_dev_is_admin_v1(['id'=>$owner])
            || vp3_dev_control_v1($pdo, $owner) !== ['mode'=>'code_stage','epoch'=>(int)$row['epoch']]
            || $row['base_sha'] !== vp3_dev_base_sha_v1()) {
            throw new DomainException('Development lease is unavailable.', 409);
        }
        $now = time();
        $expires = 0;
        if ($result === null) {
            $status = 'failed';
            $safe = ['summary'=>'The isolated coding or staging run did not verify.'];
        } else {
            $head = $result['head_sha'] ?? null;
            $digest = $result['artifact_sha'] ?? null;
            $origin = vp3_dev_preview_origin_v1();
            if (!is_string($head) || !preg_match('/\A[0-9a-f]{40}\z/D', $head) || $head === $row['base_sha']
                || !is_string($digest) || !preg_match('/\A[0-9a-f]{64}\z/D', $digest)
                || $origin === '' || ($result['preview_url'] ?? '') !== $origin.'/revisions/'.$head.'/') {
                throw new DomainException('The preview receipt does not match a valid staged candidate.', 422);
            }
            $safe = ['summary'=>vp3_dev_text_v1($result['summary'] ?? null, 500), 'checks'=>vp3_dev_lines_v1($result['checks'] ?? null),
                'head_sha'=>$head, 'artifact_sha'=>$digest, 'preview_url'=>$result['preview_url']];
            $status = 'staged';
            $expires = $now + VP3_DEV_STAGE_TTL_V1;
        }
        $pdo->prepare('UPDATE agent_development_revisions_v1 SET status=?,result_json=?,lease_hash=NULL,lease_expires_at=0,
            stage_expires_at=?,updated_at=? WHERE id=?')->execute([$status, json_encode($safe, JSON_THROW_ON_ERROR), $expires, $now, $id]);
    });
}
