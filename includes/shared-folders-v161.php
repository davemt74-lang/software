<?php
declare(strict_types=1);

/**
 * Phase 16.1 canonical shared-folder service.
 *
 * The authoritative table remains artist_transcript_folders_v177. This service
 * only centralizes owner-scoped CRUD so Knowledge, Transcriptions and Music can
 * present the same folder library without introducing another folder model.
 */

function shared_folders_v161_ready(): bool
{
    return table_exists('artist_transcript_folders_v177');
}

function shared_folders_v161_user_id(array $user): int
{
    return max(0, (int)($user['id'] ?? 0));
}

function shared_folders_v161_clean_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name) > 80) {
        throw new RuntimeException('Folder names must contain 1 to 80 characters.');
    }
    return $name;
}

function shared_folders_v161_list(PDO $pdo, array $user): array
{
    $userId = shared_folders_v161_user_id($user);
    if ($userId < 1 || !shared_folders_v161_ready()) return [];

    $stmt = $pdo->prepare(
        'SELECT id,folder_name,sort_order,created_at,updated_at
         FROM artist_transcript_folders_v177
         WHERE created_by_user_id=?
         ORDER BY sort_order ASC,folder_name ASC,id ASC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll() ?: [];
}

function shared_folders_v161_get(PDO $pdo, array $user, int $folderId): ?array
{
    $userId = shared_folders_v161_user_id($user);
    if ($userId < 1 || $folderId < 1 || !shared_folders_v161_ready()) return null;

    $stmt = $pdo->prepare(
        'SELECT id,folder_name,sort_order,created_at,updated_at
         FROM artist_transcript_folders_v177
         WHERE id=? AND created_by_user_id=? LIMIT 1'
    );
    $stmt->execute([$folderId, $userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function shared_folders_v161_create(PDO $pdo, array $user, string $name): array
{
    $userId = shared_folders_v161_user_id($user);
    if ($userId < 1 || !shared_folders_v161_ready()) {
        throw new RuntimeException('Shared folders are unavailable until the latest database upgrade is complete.');
    }
    $name = shared_folders_v161_clean_name($name);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO artist_transcript_folders_v177 (created_by_user_id,folder_name,sort_order)
             VALUES (?,?,0)'
        );
        $stmt->execute([$userId, $name]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            throw new RuntimeException('A folder with that name already exists.');
        }
        throw $e;
    }

    $folder = shared_folders_v161_get($pdo, $user, (int)$pdo->lastInsertId());
    if (!$folder) throw new RuntimeException('The folder could not be reloaded.');
    return $folder;
}

function shared_folders_v161_rename(PDO $pdo, array $user, int $folderId, string $name): array
{
    $folder = shared_folders_v161_get($pdo, $user, $folderId);
    if (!$folder) throw new RuntimeException('Folder not found.');
    $name = shared_folders_v161_clean_name($name);

    try {
        $stmt = $pdo->prepare(
            'UPDATE artist_transcript_folders_v177
             SET folder_name=?,updated_at=NOW()
             WHERE id=? AND created_by_user_id=?'
        );
        $stmt->execute([$name, $folderId, shared_folders_v161_user_id($user)]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            throw new RuntimeException('A folder with that name already exists.');
        }
        throw $e;
    }

    return shared_folders_v161_get($pdo, $user, $folderId) ?? $folder;
}

function shared_folders_v161_unfile_transcripts(PDO $pdo, array $user, int $folderId): void
{
    $userId = shared_folders_v161_user_id($user);
    if ($userId < 1 || $folderId < 1 || !table_exists('artist_transcript_sessions_v172')) return;

    $sessions = $pdo->prepare(
        'SELECT id,metadata_json FROM artist_transcript_sessions_v172
         WHERE created_by_user_id=? FOR UPDATE'
    );
    $sessions->execute([$userId]);
    $update = $pdo->prepare(
        'UPDATE artist_transcript_sessions_v172 SET metadata_json=?,last_activity_at=NOW() WHERE id=? AND created_by_user_id=?'
    );

    foreach ($sessions->fetchAll() ?: [] as $session) {
        $metadata = json_decode((string)($session['metadata_json'] ?? ''), true);
        if (!is_array($metadata) || (int)($metadata['folder_id'] ?? 0) !== $folderId) continue;
        $metadata['folder_id'] = 0;
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) $update->execute([$json, (int)$session['id'], $userId]);
    }
}

function shared_folders_v161_delete(PDO $pdo, array $user, int $folderId): array
{
    $folder = shared_folders_v161_get($pdo, $user, $folderId);
    if (!$folder) throw new RuntimeException('Folder not found.');
    $userId = shared_folders_v161_user_id($user);

    $pdo->beginTransaction();
    try {
        // Knowledge uses a real nullable FK on current installs, but update
        // explicitly as well so pre-FK upgraded databases preserve Unfiled.
        if (table_exists('knowledge_items') && column_exists('knowledge_items', 'folder_id')) {
            $stmt = $pdo->prepare(
                "UPDATE knowledge_items SET folder_id=NULL
                 WHERE folder_id=? AND created_by_user_id=? AND knowledge_scope='personal'"
            );
            $stmt->execute([$folderId, $userId]);
        }

        // Transcription organization stores the shared id in private metadata.
        // Clear that reference before deleting the canonical row.
        shared_folders_v161_unfile_transcripts($pdo, $user, $folderId);

        $stmt = $pdo->prepare(
            'DELETE FROM artist_transcript_folders_v177 WHERE id=? AND created_by_user_id=?'
        );
        $stmt->execute([$folderId, $userId]);
        if ($stmt->rowCount() < 1) throw new RuntimeException('Folder could not be deleted.');

        $pdo->commit();
        return [
            'id'=>$folderId,
            'name'=>(string)$folder['folder_name'],
            'deleted'=>true,
            'items_moved_to_unfiled'=>true,
            'transcriptions_moved_to_unfiled'=>true,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
