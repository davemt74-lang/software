<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/coding-canvas-provider-v1.php';
require_once __DIR__.'/shared-folders-v161.php';

function vp3_coding_canvas_schema_v1(PDO $pdo): void
{
    $options = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_coding_canvases_v1 (
        id CHAR(36) PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL,
        folder_id BIGINT UNSIGNED NOT NULL UNIQUE,
        title VARCHAR(190) NOT NULL,
        state_item_id INT UNSIGNED NOT NULL UNIQUE,
        state_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
        active_revision INT UNSIGNED NULL,
        draft_revision INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (folder_id) REFERENCES artist_transcript_folders_v177(id) ON DELETE CASCADE
    )".$options);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_coding_canvas_versions_v1 (
        canvas_id CHAR(36) NOT NULL,
        revision INT UNSIGNED NOT NULL,
        run_id BIGINT UNSIGNED NULL,
        code_item_id INT UNSIGNED NOT NULL UNIQUE,
        artifact_hash CHAR(64) NOT NULL,
        tested_state_revision CHAR(64) NULL,
        ever_activated TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (canvas_id,revision),
        UNIQUE (canvas_id,run_id),
        FOREIGN KEY (canvas_id) REFERENCES vp3_coding_canvases_v1(id) ON DELETE CASCADE
    )".$options);
}

function vp3_coding_canvas_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT c.state_version, v.tested_state_revision
            FROM vp3_coding_canvases_v1 c JOIN vp3_coding_canvas_versions_v1 v ON v.canvas_id=c.id
            WHERE 1=0') !== false;
    } catch (PDOException) {
        return false;
    }
}

function vp3_coding_canvas_authority_v1(PDO $pdo, array $user, int $agentId, bool $manage = false): ?array
{
    if ((int)($user['id'] ?? 0) < 1 || (isset($user['is_active']) && empty($user['is_active']))
        || $agentId < 0 || vp3_participant_scope_v1() !== null || !has_permission('chat.access', $user)) {
        throw new RuntimeException('A signed-in Agent Chat account is required.', 403);
    }
    if (!personal_capability_has_v242('personal_knowledge.access', $user)
        || ($manage && !personal_capability_has_v242('personal_knowledge.manage', $user))) {
        throw new RuntimeException('This account cannot access the required private Knowledge storage.', 403);
    }
    if (!vp3_coding_canvas_ready_v1($pdo) || !personal_knowledge_available($user)
        || !shared_folders_v161_ready() || !column_exists('knowledge_items', 'folder_id')) {
        throw new RuntimeException('Coding Canvas storage is unavailable until the database upgrade is complete.', 503);
    }
    return vp3_agent_chat_resolve_agent_v380($pdo, $user, $agentId);
}

function vp3_coding_canvas_id_v1(string $id): string
{
    $id = strtolower($id);
    if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $id)) {
        throw new InvalidArgumentException('Invalid Coding Canvas ID.', 422);
    }
    return $id;
}

/** Join a worker transaction when saving a run outcome and its draft together. */
function vp3_coding_canvas_atomic_v1(PDO $pdo, int $owner, callable $work): array
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        vp3_ai_service_usage_lock_owner_v1($pdo, $owner);
        $result = $work();
        if ($ownsTransaction) $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function vp3_coding_canvas_row_v1(PDO $pdo, int $owner, int $agentId, string $id, bool $lock = false): ?array
{
    $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $query = $pdo->prepare("SELECT c.*, s.content_text AS state_content FROM vp3_coding_canvases_v1 c
        JOIN artist_transcript_folders_v177 f ON f.id=c.folder_id AND f.created_by_user_id=c.owner_user_id
        LEFT JOIN knowledge_items s ON s.id=c.state_item_id AND s.created_by_user_id=c.owner_user_id
          AND s.folder_id=c.folder_id AND s.knowledge_scope='personal' AND s.visibility='private' AND s.is_published=0
        WHERE c.owner_user_id=? AND c.agent_id=? AND c.id=?".$suffix);
    $query->execute([$owner, $agentId, $id]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function vp3_coding_canvas_require_row_v1(PDO $pdo, int $owner, int $agentId, string $id, bool $lock = false): array
{
    $canvas = vp3_coding_canvas_row_v1($pdo, $owner, $agentId, $id, $lock);
    if (!$canvas) throw new RuntimeException('Coding Canvas not found for this Agent.', 404);
    return $canvas;
}

function vp3_coding_canvas_public_v1(array $row): array
{
    return [
        'id' => (string)$row['id'],
        'title' => (string)$row['title'],
        'agent_id' => (int)$row['agent_id'],
        'conversation_id' => (int)$row['conversation_id'],
        'folder_id' => (int)$row['folder_id'],
        'active_revision' => $row['active_revision'] === null ? null : (int)$row['active_revision'],
        'draft_revision' => $row['draft_revision'] === null ? null : (int)$row['draft_revision'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function vp3_coding_canvas_insert_item_v1(PDO $pdo, int $owner, int $folderId, string $title, string $fileName, string $json): int
{
    $insert = $pdo->prepare("INSERT INTO knowledge_items
        (title, description, file_name, file_type, mime_type, file_size, content_text,
         visibility, is_published, created_by_user_id, knowledge_scope, folder_id)
        VALUES (?, 'Saved by Coding Canvas in Agent Chat.', ?, 'coding_canvas', 'application/json', ?, ?, 'private', 0, ?, 'personal', ?)");
    $insert->execute([$title, $fileName, strlen($json), $json, $owner, $folderId]);
    return (int)$pdo->lastInsertId();
}

function vp3_coding_canvas_item_content_v1(PDO $pdo, array $row, int $itemId): string
{
    $query = $pdo->prepare("SELECT content_text FROM knowledge_items
        WHERE id=? AND created_by_user_id=? AND folder_id=? AND knowledge_scope='personal'
          AND visibility='private' AND is_published=0");
    $query->execute([$itemId, (int)$row['owner_user_id'], (int)$row['folder_id']]);
    $content = $query->fetchColumn();
    if (!is_string($content)) throw new RuntimeException('A private Coding Canvas document is unavailable.', 409);
    return $content;
}

function vp3_coding_canvas_state_json_v1(array|stdClass $state): string
{
    if (is_array($state) && $state !== [] && array_is_list($state)) {
        throw new InvalidArgumentException('App data must be a JSON object.', 422);
    }
    try {
        $json = json_encode((object)$state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, 24);
    } catch (JsonException $e) {
        throw new InvalidArgumentException('App data must be a valid JSON object with at most 24 levels.', 422, $e);
    }
    if (strlen($json) > 65536) throw new InvalidArgumentException('App data is limited to 64 KiB.', 422);
    return $json;
}

function vp3_coding_canvas_state_v1(PDO $pdo, array $row): array
{
    $json = $row['state_content'];
    if (!is_string($json)) throw new RuntimeException('The private Coding Canvas app data is unavailable.', 409);
    $value = json_decode($json, false, 25);
    if (!$value instanceof stdClass) throw new RuntimeException('The saved Coding Canvas app data is invalid.', 409);
    return [
        'revision' => hash('sha256', $row['id'].'|'.$row['state_version'].'|'.$row['active_revision'].'|'.$json),
        'value' => $value,
    ];
}

function vp3_coding_canvas_revisions_v1(PDO $pdo, string $id): array
{
    $query = $pdo->prepare('SELECT * FROM vp3_coding_canvas_versions_v1 WHERE canvas_id=? ORDER BY revision DESC');
    $query->execute([$id]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function vp3_coding_canvas_revision_public_v1(array $row, array $canvas, string $stateRevision): array
{
    $id = (int)$row['revision'];
    $status = match ($id) {
        $canvas['active_revision'] => 'active',
        $canvas['draft_revision'] => 'draft',
        default => 'saved',
    };
    $everApplied = (bool)$row['ever_activated'];
    return [
        'id' => $id,
        'status' => $status,
        'test_passed' => $everApplied || ($status === 'draft' && $row['tested_state_revision'] === $stateRevision),
        'can_rollback' => $everApplied && $status !== 'active',
        'created_at' => (string)$row['created_at'],
    ];
}

function vp3_coding_canvas_details_v1(PDO $pdo, array $row, ?int $version = null): array
{
    $canvas = vp3_coding_canvas_public_v1($row);
    $state = vp3_coding_canvas_state_v1($pdo, $row);
    $version ??= $canvas['active_revision'] ?? $canvas['draft_revision'];
    $versions = [];
    $selected = null;
    foreach (vp3_coding_canvas_revisions_v1($pdo, $canvas['id']) as $candidate) {
        $info = vp3_coding_canvas_revision_public_v1($candidate, $canvas, $state['revision']);
        $versions[] = $info;
        if ($info['id'] !== $version) continue;
        $json = vp3_coding_canvas_item_content_v1($pdo, $row, (int)$candidate['code_item_id']);
        if (!hash_equals((string)$candidate['artifact_hash'], hash('sha256', $json))) {
            throw new RuntimeException('The saved Coding Canvas code has changed outside Agent Chat.', 409);
        }
        $selected = $info + ['artifact' => json_decode($json, true, 8, JSON_THROW_ON_ERROR)];
    }
    if ($selected === null) throw new RuntimeException('Coding Canvas revision not found.', 404);
    return ['canvas' => $canvas, 'revision' => $selected, 'state' => $state, 'revisions' => $versions];
}

function vp3_coding_canvas_read_v1(PDO $pdo, array $user, int $agentId, string $canvasId, ?int $version = null): array
{
    vp3_coding_canvas_authority_v1($pdo, $user, $agentId);
    $row = vp3_coding_canvas_require_row_v1($pdo, (int)$user['id'], $agentId, vp3_coding_canvas_id_v1($canvasId));
    return vp3_coding_canvas_details_v1($pdo, $row, $version);
}

function vp3_coding_canvas_list_v1(PDO $pdo, array $user, int $agentId): array
{
    vp3_coding_canvas_authority_v1($pdo, $user, $agentId);
    $query = $pdo->prepare('SELECT c.* FROM vp3_coding_canvases_v1 c
        JOIN artist_transcript_folders_v177 f ON f.id=c.folder_id AND f.created_by_user_id=c.owner_user_id
        WHERE c.owner_user_id=? AND c.agent_id=? ORDER BY c.updated_at DESC,c.id ASC');
    $query->execute([(int)$user['id'], $agentId]);
    return array_map('vp3_coding_canvas_public_v1', $query->fetchAll(PDO::FETCH_ASSOC));
}

function vp3_coding_canvas_create_folder_v1(PDO $pdo, array $user, string $id, string $title): int
{
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $suffix = $attempt === 0 ? '' : ' ('.substr($id, 0, 8).($attempt === 1 ? '' : '-'.$attempt).')';
        $name = rtrim(mb_substr($title, 0, 80 - strlen($suffix), 'UTF-8')).$suffix;
        try {
            return (int)shared_folders_v161_create($pdo, $user, $name)['id'];
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'A folder with that name already exists.') throw $e;
        }
    }
    throw new RuntimeException('A unique Knowledge folder name could not be allocated.', 409);
}

function vp3_coding_canvas_save_draft_v1(
    PDO $pdo, array $user, int $agentId, int $cid, string $canvasId, string $title, array $code, array $initialState,
    int $runId = 0, ?int $expectedDraft = null, ?int $expectedActive = null
): array {
    $agent = vp3_coding_canvas_authority_v1($pdo, $user, $agentId, true);
    $id = vp3_coding_canvas_id_v1($canvasId);
    $owner = (int)$user['id'];
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
    $title = mb_substr($title !== '' ? $title : 'Coding Canvas', 0, 190, 'UTF-8');
    $json = json_encode(vp3_coding_canvas_artifact_v1($code), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return vp3_coding_canvas_atomic_v1($pdo, $owner, static function () use (
        $pdo, $user, $agent, $owner, $agentId, $cid, $id, $title, $json, $initialState, $runId, $expectedDraft, $expectedActive
    ): array {
        $row = vp3_coding_canvas_row_v1($pdo, $owner, $agentId, $id, true);
        if (!$row) {
            if ($expectedDraft !== null || $expectedActive !== null) {
                throw new RuntimeException('Coding Canvas not found for this Agent.', 404);
            }
            $claimed = $pdo->prepare('SELECT 1 FROM vp3_coding_canvases_v1 WHERE id=?');
            $claimed->execute([$id]);
            if ($claimed->fetchColumn()) throw new RuntimeException('Coding Canvas not found for this Agent.', 404);
            $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            if (!vp3_agent_chat_conversation_v380($pdo, $cid, $owner, $agent, $mysql)) {
                throw new RuntimeException('Conversation not found for this Agent.', 404);
            }
            $state = vp3_coding_canvas_state_json_v1($initialState);
            $folder = vp3_coding_canvas_create_folder_v1($pdo, $user, $id, $title);
            $stateItem = vp3_coding_canvas_insert_item_v1($pdo, $owner, $folder, 'App data', 'app-data.json', $state);
            $pdo->prepare('INSERT INTO vp3_coding_canvases_v1
                (id, owner_user_id, agent_id, conversation_id, folder_id, title, state_item_id)
                VALUES (?,?,?,?,?,?,?)')->execute([$id, $owner, $agentId, $cid, $folder, $title, $stateItem]);
            $version = 1;
        } else {
            if ((int)$row['conversation_id'] !== $cid) throw new RuntimeException('The Coding Canvas origin changed.', 409);
            if ($runId > 0) {
                $previous = $pdo->prepare('SELECT revision FROM vp3_coding_canvas_versions_v1 WHERE canvas_id=? AND run_id=?');
                $previous->execute([$id, $runId]);
                $alreadySaved = $previous->fetchColumn();
                if ($alreadySaved !== false) return vp3_coding_canvas_details_v1($pdo, $row, (int)$alreadySaved);
            }
            $canvas = vp3_coding_canvas_public_v1($row);
            if ($canvas['draft_revision'] !== $expectedDraft || $canvas['active_revision'] !== $expectedActive) {
                throw new RuntimeException('The Coding Canvas changed. Reload it before saving a new revision.', 409);
            }
            $latest = $pdo->prepare('SELECT MAX(revision) FROM vp3_coding_canvas_versions_v1 WHERE canvas_id=?');
            $latest->execute([$id]);
            $version = (int)$latest->fetchColumn() + 1;
            $folder = $canvas['folder_id'];
        }
        $codeItem = vp3_coding_canvas_insert_item_v1(
            $pdo, $owner, $folder, 'Code revision '.$version, 'code-r'.$version.'.json', $json
        );
        $pdo->prepare('INSERT INTO vp3_coding_canvas_versions_v1
            (canvas_id, revision, run_id, code_item_id, artifact_hash) VALUES (?,?,?,?,?)')
            ->execute([$id, $version, $runId > 0 ? $runId : null, $codeItem, hash('sha256', $json)]);
        $pdo->prepare('UPDATE vp3_coding_canvases_v1 SET draft_revision=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$version, $id]);
        return vp3_coding_canvas_details_v1(
            $pdo, vp3_coding_canvas_require_row_v1($pdo, $owner, $agentId, $id), $version
        );
    });
}

function vp3_coding_canvas_mark_tested_v1(
    PDO $pdo, array $user, int $agentId, string $canvasId, int $revision, string $stateRevision
): array {
    vp3_coding_canvas_authority_v1($pdo, $user, $agentId, true);
    $id = vp3_coding_canvas_id_v1($canvasId);
    $owner = (int)$user['id'];
    return vp3_coding_canvas_atomic_v1($pdo, $owner, static function () use ($pdo, $owner, $agentId, $id, $revision, $stateRevision): array {
        $row = vp3_coding_canvas_require_row_v1($pdo, $owner, $agentId, $id, true);
        $details = vp3_coding_canvas_details_v1($pdo, $row, $revision);
        if ($details['canvas']['draft_revision'] !== $revision || !hash_equals($details['state']['revision'], $stateRevision)) {
            throw new RuntimeException('The draft or saved app data changed. Reload it before testing again.', 409);
        }
        $pdo->prepare('UPDATE vp3_coding_canvas_versions_v1 SET tested_state_revision=? WHERE canvas_id=? AND revision=?')
            ->execute([$stateRevision, $id, $revision]);
        return vp3_coding_canvas_details_v1($pdo, $row, $revision);
    });
}

function vp3_coding_canvas_activate_v1(
    PDO $pdo, array $user, int $agentId, string $canvasId, int $version, ?int $expectedActive
): array {
    vp3_coding_canvas_authority_v1($pdo, $user, $agentId, true);
    $id = vp3_coding_canvas_id_v1($canvasId);
    $owner = (int)$user['id'];
    return vp3_coding_canvas_atomic_v1($pdo, $owner, static function () use ($pdo, $owner, $agentId, $id, $version, $expectedActive): array {
        $row = vp3_coding_canvas_require_row_v1($pdo, $owner, $agentId, $id, true);
        $details = vp3_coding_canvas_details_v1($pdo, $row, $version);
        $canvas = $details['canvas'];
        if ($canvas['active_revision'] !== $expectedActive) {
            throw new RuntimeException('The active Coding Canvas revision changed. Reload it first.', 409);
        }
        if ($canvas['active_revision'] === $version) return $details;
        $isDraft = $canvas['draft_revision'] === $version;
        $revision = $details['revision'];
        if (!$revision['can_rollback'] && (!$isDraft || !$revision['test_passed'])) {
            throw new RuntimeException('Test the current draft against the current app data before applying it.', 409);
        }
        $pdo->prepare('UPDATE vp3_coding_canvas_versions_v1 SET ever_activated=1 WHERE canvas_id=? AND revision=?')
            ->execute([$id, $version]);
        $pdo->prepare('UPDATE vp3_coding_canvases_v1
            SET active_revision=?,draft_revision=?,state_version=state_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$version, $isDraft ? null : $canvas['draft_revision'], $id]);
        return vp3_coding_canvas_details_v1(
            $pdo, vp3_coding_canvas_require_row_v1($pdo, $owner, $agentId, $id), $version
        );
    });
}

function vp3_coding_canvas_save_state_v1(
    PDO $pdo, array $user, int $agentId, string $canvasId, int $activeVersion, string $expectedStateRevision, array|stdClass $state
): array {
    vp3_coding_canvas_authority_v1($pdo, $user, $agentId, true);
    $id = vp3_coding_canvas_id_v1($canvasId);
    $owner = (int)$user['id'];
    $json = vp3_coding_canvas_state_json_v1($state);
    return vp3_coding_canvas_atomic_v1($pdo, $owner, static function () use ($pdo, $owner, $agentId, $id, $activeVersion, $expectedStateRevision, $json): array {
        $row = vp3_coding_canvas_require_row_v1($pdo, $owner, $agentId, $id, true);
        if ($row['active_revision'] === null || (int)$row['active_revision'] !== $activeVersion
            || !hash_equals(vp3_coding_canvas_state_v1($pdo, $row)['revision'], $expectedStateRevision)) {
            throw new RuntimeException('The saved Coding Canvas app or data changed. Reload it first.', 409);
        }
        $pdo->prepare("UPDATE knowledge_items SET content_text=?,file_size=?,updated_at=CURRENT_TIMESTAMP
            WHERE id=? AND created_by_user_id=? AND folder_id=? AND knowledge_scope='personal' AND visibility='private' AND is_published=0")
            ->execute([$json, strlen($json), (int)$row['state_item_id'], $owner, (int)$row['folder_id']]);
        $pdo->prepare('UPDATE vp3_coding_canvases_v1 SET state_version=state_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$id]);
        return vp3_coding_canvas_details_v1($pdo, vp3_coding_canvas_require_row_v1($pdo, $owner, $agentId, $id));
    });
}

function vp3_coding_canvas_delete_v1(PDO $pdo, array $user, int $agentId, string $canvasId, bool $deleteFolder): array
{
    vp3_coding_canvas_authority_v1($pdo, $user, $agentId, true);
    $id = vp3_coding_canvas_id_v1($canvasId);
    $owner = (int)$user['id'];
    return vp3_coding_canvas_atomic_v1($pdo, $owner, static function () use ($pdo, $user, $owner, $agentId, $id, $deleteFolder): array {
        $row = vp3_coding_canvas_require_row_v1($pdo, $owner, $agentId, $id, true);
        $folderId = (int)$row['folder_id'];
        if ($deleteFolder) {
            $delete = $pdo->prepare("DELETE FROM knowledge_items WHERE id=? AND created_by_user_id=? AND folder_id=?
                AND knowledge_scope='personal' AND visibility='private' AND is_published=0");
            foreach (vp3_coding_canvas_revisions_v1($pdo, $id) as $version) {
                $delete->execute([(int)$version['code_item_id'], $owner, $folderId]);
            }
            $delete->execute([(int)$row['state_item_id'], $owner, $folderId]);
        }
        $pdo->prepare('DELETE FROM vp3_coding_canvases_v1 WHERE id=?')->execute([$id]);
        if ($deleteFolder) shared_folders_v161_delete($pdo, $user, $folderId);
        return ['id' => $id, 'folder_id' => $folderId, 'deleted' => true, 'folder_deleted' => $deleteFolder];
    });
}

/** Keep regular Knowledge edits from breaking saved app revisions or app data. */
function vp3_coding_canvas_assert_knowledge_editable_v1(PDO $pdo, int $owner, array $itemIds): void
{
    if (!$itemIds || !vp3_coding_canvas_ready_v1($pdo)) return;
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $query = $pdo->prepare("SELECT 1 FROM vp3_coding_canvases_v1 c
        WHERE c.owner_user_id=? AND (c.state_item_id IN ($placeholders) OR EXISTS (
            SELECT 1 FROM vp3_coding_canvas_versions_v1 v WHERE v.canvas_id=c.id AND v.code_item_id IN ($placeholders)
        )) LIMIT 1");
    $query->execute([$owner, ...$itemIds, ...$itemIds]);
    if ($query->fetchColumn()) throw new RuntimeException('Manage saved Canvas code and app data from Agent Chat.', 409);
}
