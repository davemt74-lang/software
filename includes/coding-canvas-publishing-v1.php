<?php
declare(strict_types=1);

require_once __DIR__.'/coding-canvas-store-v1.php';

function vp3_coding_canvas_publishing_install_v1(PDO $pdo): void
{
    $options = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_coding_canvas_publications_v1 (
        canvas_id CHAR(36) PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        slug VARCHAR(64) NOT NULL UNIQUE,
        generation BIGINT UNSIGNED NOT NULL,
        revision INT UNSIGNED NULL,
        title VARCHAR(190) NULL,
        snapshot_json LONGTEXT NULL,
        snapshot_hash CHAR(64) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (canvas_id) REFERENCES vp3_coding_canvases_v1(id) ON DELETE CASCADE
    )".$options);
}

function vp3_coding_canvas_publishing_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT canvas_id,owner_user_id,slug,generation,revision,title,snapshot_json,snapshot_hash,updated_at
            FROM vp3_coding_canvas_publications_v1 WHERE 1=0') !== false;
    } catch (PDOException) {
        return false;
    }
}

function vp3_coding_canvas_public_slug_v1(string $slug): bool
{
    return strlen($slug) >= 3 && strlen($slug) <= 64 && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) === 1;
}

function vp3_coding_canvas_publication_version_v1(string $canvasId, int $generation): string
{
    return hash('sha256', 'canvas-publication:'.$canvasId.':'.$generation);
}

function vp3_coding_canvas_publication_row_v1(PDO $pdo, array $canvas, bool $lock = false): ?array
{
    $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $query = $pdo->prepare('SELECT slug,generation,revision,updated_at FROM vp3_coding_canvas_publications_v1
        WHERE canvas_id=? AND owner_user_id=?'.$suffix);
    $query->execute([$canvas['id'], (int)$canvas['owner_user_id']]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function vp3_coding_canvas_publication_view_v1(array $canvas, ?array $row): array
{
    $published = isset($row['revision']);
    return [
        'state' => $published ? 'published' : 'unpublished',
        'slug' => $row['slug'] ?? null,
        'url' => $published ? url('/apps/'.$row['slug']) : null,
        'revision' => $published ? (int)$row['revision'] : null,
        'updated_at' => $row['updated_at'] ?? null,
        'version' => vp3_coding_canvas_publication_version_v1($canvas['id'], (int)($row['generation'] ?? 0)),
    ];
}

function vp3_coding_canvas_public_revision_row_v1(PDO $pdo, array $canvas, int $revision): ?array
{
    $query = $pdo->prepare('SELECT run_id,code_item_id,artifact_hash,ever_activated FROM vp3_coding_canvas_versions_v1
        WHERE canvas_id=? AND revision=?');
    $query->execute([$canvas['id'], $revision]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A repair can inherit a legacy private source even if its artifact has no command declarations. */
function vp3_coding_canvas_public_private_origin_v1(PDO $pdo, array $canvas, int $revision, array $version): bool
{
    $query = null;
    while ($version['run_id'] !== null) {
        $query ??= $pdo->prepare("SELECT capability_key,goal FROM agent_workflow_runs
            WHERE id=? AND owner_user_id=? AND COALESCE(agent_id,0)=? AND source_kind='coding_canvas' AND status='completed'");
        $query->bindValue(1, (int)$version['run_id'], PDO::PARAM_INT);
        $query->bindValue(2, (int)$canvas['owner_user_id'], PDO::PARAM_INT);
        $query->bindValue(3, (int)$canvas['agent_id'], PDO::PARAM_INT);
        $query->execute();
        $run = $query->fetch(PDO::FETCH_ASSOC);
        $query->closeCursor();
        if (!$run) return true;
        $goal = json_decode((string)$run['goal'], true, 32);
        if (!is_array($goal) || ($goal['canvas_id'] ?? null) !== $canvas['id']
            || ($goal['conversation_id'] ?? null) !== (int)$canvas['conversation_id']
            || array_key_exists('initiative_origin', $goal)) return true;
        if ($run['capability_key'] === 'coding.build') return false;
        $source = $goal['source_revision'] ?? null;
        if ($run['capability_key'] !== 'coding.repair' || !is_int($source) || $source < 1 || $source >= $revision) return true;
        $version = vp3_coding_canvas_public_revision_row_v1($pdo, $canvas, $source);
        if (!$version) return true;
        $revision = $source;
    }
    return false;
}

function vp3_coding_canvas_public_commands_v1(array $commands): bool
{
    return !array_diff($commands, ['public-products','public-events','public-booking','public-campaigns','public-rewards','public-media']);
}

/** Validate the exact activated source. This does not read or take a snapshot of app data or grants. */
function vp3_coding_canvas_publishing_source_v1(PDO $pdo, array $canvas, ?int $revision): array
{
    $unavailable = ['source'=>null,'reason'=>'Activate this version in the Canvas before publishing.'];
    if ($revision === null || (int)$canvas['draft_revision'] === $revision) return $unavailable;
    $version = vp3_coding_canvas_public_revision_row_v1($pdo, $canvas, $revision);
    if (!$version || (int)$version['ever_activated'] !== 1) return $unavailable;
    try {
        $source = vp3_coding_canvas_artifact_projection_v1($pdo, $canvas, $version);
        $image = vp3_coding_canvas_library_image_manifest_v1($pdo,$canvas,$revision)['image'];
    } catch (RuntimeException|InvalidArgumentException|JsonException $error) {
        if ($error instanceof PDOException) throw $error;
        return ['source'=>null,'reason'=>'This version’s saved source could not be verified.'];
    }
    if ($image !== null) {
        return ['source'=>null,'reason'=>'This version includes a private Media Library image. Choose a version without the image to publish.'];
    }
    if (!vp3_coding_canvas_public_commands_v1($source['commands'])
        || preg_match('/\bCanvasData\b/', $source['artifact']['js'])
        || vp3_coding_canvas_public_private_origin_v1($pdo, $canvas, $revision, $version)) {
        return ['source'=>null,'reason'=>'This version uses a private command or source. Build a public version using public sources.'];
    }
    // Owner-authored test values stay private; published apps contain source and commands only.
    return ['source'=>['artifact'=>$source['artifact'],'commands'=>$source['commands']],'reason'=>null];
}

function vp3_coding_canvas_publication_status_v1(
    PDO $pdo, array $user, int $agentId, string $canvasId, ?int $revision = null
): array {
    vp3_coding_canvas_authority_v1($pdo, $user, $agentId);
    $canvas = vp3_coding_canvas_require_row_v1($pdo, (int)$user['id'], $agentId, vp3_coding_canvas_id_v1($canvasId));
    if (!vp3_coding_canvas_publishing_ready_v1($pdo)) throw new RuntimeException('Run Database Upgrade to publish Canvases.', 503);
    $publication = vp3_coding_canvas_publication_view_v1($canvas, vp3_coding_canvas_publication_row_v1($pdo, $canvas));
    $revision ??= isset($canvas['active_revision']) ? (int)$canvas['active_revision'] : null;
    $reason = personal_capability_has_v242('personal_knowledge.manage', $user)
        ? vp3_coding_canvas_publishing_source_v1($pdo, $canvas, $revision)['reason']
        : 'This account does not have permission to publish the Canvas.';
    return ['publication'=>$publication,'eligibility'=>['can_publish'=>$reason === null,'reason'=>$reason]];
}

function vp3_coding_canvas_publish_v1(
    PDO $pdo, array $user, int $agentId, string $canvasId, int $revision, string $slug, string $expectedRevision
): array {
    if (!vp3_coding_canvas_public_slug_v1($slug)) throw new InvalidArgumentException('Enter a public name using 3–64 lowercase letters or numbers with optional dashes.', 422);
    return vp3_coding_canvas_set_publication_v1($pdo, $user, $agentId, $canvasId, $expectedRevision, $revision, $slug);
}

function vp3_coding_canvas_unpublish_v1(PDO $pdo, array $user, int $agentId, string $canvasId, string $expectedRevision): array
{
    return vp3_coding_canvas_set_publication_v1($pdo, $user, $agentId, $canvasId, $expectedRevision);
}

function vp3_coding_canvas_set_publication_v1(
    PDO $pdo, array $user, int $agentId, string $canvasId, string $expectedRevision,
    ?int $revision = null, ?string $slug = null
): array {
    $publishing = $revision !== null;
    vp3_coding_canvas_authority_v1($pdo, $user, $agentId, $publishing);
    if (!vp3_coding_canvas_publishing_ready_v1($pdo)) throw new RuntimeException('Run Database Upgrade to publish Canvases.', 503);
    if (!preg_match('/\A[a-f0-9]{64}\z/', $expectedRevision)) throw new InvalidArgumentException('Reload the current publication status.', 422);
    $id = vp3_coding_canvas_id_v1($canvasId);
    $owner = (int)$user['id'];
    return vp3_coding_canvas_atomic_v1($pdo, $owner, static function () use ($pdo, $user, $agentId, $owner, $id, $expectedRevision, $revision, $slug, $publishing): array {
        vp3_coding_canvas_authority_v1($pdo, $user, $agentId, $publishing);
        $canvas = vp3_coding_canvas_require_row_v1($pdo, $owner, $agentId, $id, true);
        $current = vp3_coding_canvas_publication_row_v1($pdo, $canvas, true);
        $generation = (int)($current['generation'] ?? 0);
        $publication = vp3_coding_canvas_publication_view_v1($canvas, $current);
        $same = $revision === $publication['revision'] && (!$publishing || $slug === $publication['slug']);
        $replayed = $same && $generation > 0 && hash_equals(vp3_coding_canvas_publication_version_v1($id, $generation - 1), $expectedRevision);
        if (!$replayed && !hash_equals($publication['version'], $expectedRevision)) {
            throw new RuntimeException('The publication changed. Reload it before applying this change.', 409);
        }
        if ($publishing && $current && $slug !== $current['slug']) {
            throw new RuntimeException('This Canvas already has a reserved public URL.', 409);
        }
        $selection = $publishing ? vp3_coding_canvas_publishing_source_v1($pdo, $canvas, $revision) : null;
        if (!$same) {
            if ($publishing) {
                if ($selection['source'] === null) throw new RuntimeException($selection['reason'], 409);
                vp3_coding_canvas_write_publication_v1($pdo, $canvas, $current, $revision, $slug, $selection['source']);
            } else {
                $update = $pdo->prepare('UPDATE vp3_coding_canvas_publications_v1 SET generation=generation+1,
                    revision=NULL,title=NULL,snapshot_json=NULL,snapshot_hash=NULL,updated_at=CURRENT_TIMESTAMP WHERE canvas_id=?');
                $update->execute([$id]);
            }
            $publication = vp3_coding_canvas_publication_view_v1($canvas, vp3_coding_canvas_publication_row_v1($pdo, $canvas, true));
        }
        $reason = $selection['reason'] ?? null;
        if (!$publishing) {
            $active = isset($canvas['active_revision']) ? (int)$canvas['active_revision'] : null;
            if (personal_capability_has_v242('personal_knowledge.manage', $user)) {
                try {
                    $reason = vp3_coding_canvas_publishing_source_v1($pdo, $canvas, $active)['reason'];
                } catch (PDOException) {
                    $reason = 'The next publication source could not be checked.';
                }
            } else {
                $reason = 'This account does not have permission to publish the Canvas.';
            }
        }
        return ['publication'=>$publication,'eligibility'=>['can_publish'=>$reason === null,'reason'=>$reason]];
    });
}

function vp3_coding_canvas_write_publication_v1(PDO $pdo, array $canvas, ?array $current, int $revision, string $slug, array $source): void
{
    $snapshot = json_encode($source, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $values = [$revision, $canvas['title'], $snapshot, hash('sha256', $snapshot)];
    if ($current) {
        $update = $pdo->prepare('UPDATE vp3_coding_canvas_publications_v1 SET generation=generation+1,
            revision=?,title=?,snapshot_json=?,snapshot_hash=?,updated_at=CURRENT_TIMESTAMP WHERE canvas_id=?');
        $update->execute([...$values, $canvas['id']]);
        return;
    }
    try {
        $create = $pdo->prepare('INSERT INTO vp3_coding_canvas_publications_v1
            (canvas_id,owner_user_id,slug,generation,revision,title,snapshot_json,snapshot_hash) VALUES (?,?,?,1,?,?,?,?)');
        $create->execute([$canvas['id'], (int)$canvas['owner_user_id'], $slug, ...$values]);
    } catch (PDOException $error) {
        $vendor = (int)($error->errorInfo[1] ?? 0);
        if ($vendor === 1062 || ($vendor === 19 && str_contains($error->getMessage(), 'vp3_coding_canvas_publications_v1.slug'))) {
            throw new RuntimeException('That public URL is already reserved. Choose another name.', 409, $error);
        }
        throw $error;
    }
}

/** Server-only read. Caller prepares the guest view without forwarding the internal owner and command pins. */
function vp3_coding_canvas_public_release_v1(PDO $pdo, string $slug): ?array
{
    if (!vp3_coding_canvas_public_slug_v1($slug) || !vp3_coding_canvas_publishing_ready_v1($pdo)) return null;
    $query = $pdo->prepare('SELECT p.owner_user_id,p.slug,p.title,p.revision,p.snapshot_json,p.snapshot_hash
        FROM vp3_coding_canvas_publications_v1 p
        JOIN vp3_coding_canvases_v1 c ON c.id=p.canvas_id AND c.owner_user_id=p.owner_user_id
        JOIN users u ON u.id=p.owner_user_id AND u.is_active=1
        WHERE p.slug=? AND p.revision IS NOT NULL');
    $query->execute([$slug]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row || !hash_equals((string)$row['snapshot_hash'], hash('sha256', (string)$row['snapshot_json']))) return null;
    try {
        $snapshot = json_decode($row['snapshot_json'], true, 8, JSON_THROW_ON_ERROR);
        $commands = vp3_coding_canvas_command_list_v1($snapshot['commands'] ?? null);
        if (!vp3_coding_canvas_public_commands_v1($commands)) return null;
        $artifact = vp3_coding_canvas_artifact_v1($snapshot['artifact'] ?? null);
    } catch (InvalidArgumentException|JsonException) {
        return null;
    }
    return ['slug'=>$row['slug'],'title'=>(string)$row['title'],'revision'=>(int)$row['revision'],
        'artifact'=>$artifact,'commands'=>$commands,'owner_user_id'=>(int)$row['owner_user_id']];
}
