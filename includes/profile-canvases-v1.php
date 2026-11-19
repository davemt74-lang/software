<?php
declare(strict_types=1);

require_once __DIR__.'/coding-canvas-publishing-v1.php';
require_once __DIR__.'/profile-agent.php';

/** Listing a published app on a Profile is a separate, explicit owner choice. */
function vp3_profile_canvases_install_v1(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $index = $mysql ? ', INDEX idx_profile_canvas_owner (owner_user_id,listed)' : '';
    $options = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_profile_canvases_v1 (
        canvas_id CHAR(36) PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        listed TINYINT NOT NULL DEFAULT 0,
        generation BIGINT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (canvas_id) REFERENCES vp3_coding_canvases_v1(id) ON DELETE CASCADE,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
        $index
    )".$options);
    if (!$mysql) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profile_canvas_owner ON vp3_profile_canvases_v1(owner_user_id,listed)');
}

function vp3_profile_canvases_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT canvas_id,owner_user_id,listed,generation,updated_at FROM vp3_profile_canvases_v1 WHERE 1=0') !== false;
    } catch (PDOException) {
        return false;
    }
}

function vp3_profile_canvas_version_v1(string $canvasId, int $generation): string
{
    return hash('sha256', 'profile-canvas:'.$canvasId.':'.$generation);
}

/** Read current visibility, never a cached owner/viewer flag or request username. */
function vp3_profile_canvases_public_profile_v1(PDO $pdo, int $owner): ?array
{
    if ($owner < 1) return null;
    $query = $pdo->prepare('SELECT p.user_id,p.username FROM user_profiles p
        JOIN users u ON u.id=p.user_id AND u.is_active=1 WHERE p.user_id=? AND p.is_public=1');
    $query->execute([$owner]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    $username = (string)($row['username'] ?? '');
    if (!$row || strlen($username) < 3 || profile_username_normalize($username) !== $username || !profile_username_valid($username)) return null;
    return ['user_id'=>(int)$row['user_id'],'username'=>$username];
}

function vp3_profile_canvas_url_v1(string $username, string $slug): string
{
    return url('/'.$username.'/canvas/'.$slug);
}

function vp3_profile_canvas_row_v1(PDO $pdo, array $canvas, bool $lock = false): ?array
{
    $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $query = $pdo->prepare('SELECT listed,generation FROM vp3_profile_canvases_v1
        WHERE canvas_id=? AND owner_user_id=?'.$suffix);
    $query->execute([$canvas['id'], (int)$canvas['owner_user_id']]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function vp3_profile_canvas_view_v1(PDO $pdo, array $user, array $canvas, ?array $row, ?array $publication): array
{
    $profile = vp3_profile_canvases_public_profile_v1($pdo, (int)$canvas['owner_user_id']);
    $release = isset($publication['revision'])
        ? vp3_coding_canvas_public_release_v1($pdo, (string)$publication['slug']) : null;
    $verified = $release !== null && $release['owner_user_id'] === (int)$canvas['owner_user_id']
        && $release['revision'] === (int)$publication['revision'];
    $reason = match (true) {
        !personal_capability_has_v242('personal_knowledge.manage', $user) => 'This account does not have permission to add a Canvas to the Profile.',
        $profile === null => 'Make your Profile public and choose a username before adding a Canvas.',
        !$verified => 'Publish a version of this Canvas before adding it to your Profile.',
        default => null,
    };
    $listed = !empty($row['listed']);
    return [
        'profile_canvas'=>[
            'listed'=>$listed,
            'url'=>$listed && $profile && $verified ? vp3_profile_canvas_url_v1($profile['username'], $release['slug']) : null,
            'version'=>vp3_profile_canvas_version_v1($canvas['id'], (int)($row['generation'] ?? 0)),
        ],
        'eligibility'=>['can_list'=>$reason === null,'reason'=>$reason],
    ];
}

function vp3_profile_canvas_status_v1(PDO $pdo, array $user, int $agentId, string $canvasId): array
{
    vp3_coding_canvas_authority_v1($pdo, $user, $agentId);
    $canvas = vp3_coding_canvas_require_row_v1($pdo, (int)$user['id'], $agentId, vp3_coding_canvas_id_v1($canvasId));
    if (!vp3_profile_canvases_ready_v1($pdo) || !vp3_coding_canvas_publishing_ready_v1($pdo)) {
        throw new RuntimeException('Run Database Upgrade to add Canvases to Profiles.', 503);
    }
    return vp3_profile_canvas_view_v1($pdo, $user, $canvas, vp3_profile_canvas_row_v1($pdo, $canvas),
        vp3_coding_canvas_publication_row_v1($pdo, $canvas));
}

function vp3_profile_canvas_set_v1(
    PDO $pdo, array $user, int $agentId, string $canvasId, bool $listed, string $expectedRevision, string $expectedPublication
): array {
    vp3_coding_canvas_authority_v1($pdo, $user, $agentId, $listed);
    $id = vp3_coding_canvas_id_v1($canvasId);
    if (!vp3_profile_canvases_ready_v1($pdo) || !vp3_coding_canvas_publishing_ready_v1($pdo)) {
        throw new RuntimeException('Run Database Upgrade to add Canvases to Profiles.', 503);
    }
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $expectedRevision) || !preg_match('/\A[a-f0-9]{64}\z/D', $expectedPublication)) {
        throw new InvalidArgumentException('Reload the current Canvas publication and Profile settings.', 422);
    }
    $owner = (int)$user['id'];
    return vp3_coding_canvas_atomic_v1($pdo, $owner, static function () use ($pdo, $user, $agentId, $id, $listed, $expectedRevision, $expectedPublication, $owner): array {
        vp3_coding_canvas_authority_v1($pdo, $user, $agentId, $listed);
        $canvas = vp3_coding_canvas_require_row_v1($pdo, $owner, $agentId, $id, true);
        $publication = vp3_coding_canvas_publication_row_v1($pdo, $canvas, true);
        $publicationVersion = vp3_coding_canvas_publication_version_v1($id, (int)($publication['generation'] ?? 0));
        if (!hash_equals($publicationVersion, $expectedPublication)) {
            throw new RuntimeException('The published Canvas changed. Reload it before updating your Profile.', 409);
        }
        $row = vp3_profile_canvas_row_v1($pdo, $canvas, true);
        $generation = (int)($row['generation'] ?? 0);
        $same = $listed === !empty($row['listed']);
        $replayed = $same && $generation > 0 && hash_equals(vp3_profile_canvas_version_v1($id, $generation - 1), $expectedRevision);
        if (!$replayed && !hash_equals(vp3_profile_canvas_version_v1($id, $generation), $expectedRevision)) {
            throw new RuntimeException('The Profile listing changed. Reload it before applying this change.', 409);
        }
        $view = vp3_profile_canvas_view_v1($pdo, $user, $canvas, $row, $publication);
        if ($listed && !$view['eligibility']['can_list']) throw new RuntimeException($view['eligibility']['reason'], 409);
        if (!$same) {
            if ($row) {
                $write = $pdo->prepare('UPDATE vp3_profile_canvases_v1 SET listed=?,generation=generation+1,updated_at=CURRENT_TIMESTAMP
                    WHERE canvas_id=? AND owner_user_id=?');
                $write->execute([$listed ? 1 : 0, $id, $owner]);
            } else {
                $write = $pdo->prepare('INSERT INTO vp3_profile_canvases_v1(canvas_id,owner_user_id,listed,generation) VALUES(?,?,?,1)');
                $write->execute([$id, $owner, $listed ? 1 : 0]);
            }
        }
        return vp3_profile_canvas_view_v1($pdo, $user, $canvas, vp3_profile_canvas_row_v1($pdo, $canvas, true), $publication);
    });
}

/** Internal pins are used only for consistency checks, never sent to visitors. */
function vp3_profile_canvas_public_pin_v1(PDO $pdo, int $owner, string $slug): ?array
{
    $query = $pdo->prepare('SELECT l.canvas_id,l.generation AS listing_generation,p.generation AS publication_generation,p.revision
        FROM vp3_profile_canvases_v1 l
        JOIN vp3_coding_canvas_publications_v1 p ON p.canvas_id=l.canvas_id AND p.owner_user_id=l.owner_user_id
        JOIN vp3_coding_canvases_v1 c ON c.id=l.canvas_id AND c.owner_user_id=l.owner_user_id
        WHERE l.owner_user_id=? AND l.listed=1 AND p.slug=? AND p.revision IS NOT NULL');
    $query->execute([$owner, $slug]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Public Profile routes resolve only an opted-in, currently published snapshot. */
function vp3_profile_canvas_release_v1(PDO $pdo, array $profile, string $slug): ?array
{
    if (empty($profile['is_public']) || empty($profile['is_active']) || !vp3_coding_canvas_public_slug_v1($slug)
        || !vp3_profile_canvases_ready_v1($pdo) || !vp3_coding_canvas_publishing_ready_v1($pdo)) return null;
    $owner = (int)($profile['user_id'] ?? 0);
    $live = vp3_profile_canvases_public_profile_v1($pdo, $owner);
    if (!$live || $live['username'] !== ($profile['username'] ?? null)) return null;
    $pin = vp3_profile_canvas_public_pin_v1($pdo, $owner, $slug);
    if (!$pin) return null;
    $release = vp3_coding_canvas_public_release_v1($pdo, $slug);
    if (!$release || $release['owner_user_id'] !== $owner || $release['revision'] !== (int)$pin['revision']) return null;
    if ($pin !== vp3_profile_canvas_public_pin_v1($pdo, $owner, $slug)
        || $live !== vp3_profile_canvases_public_profile_v1($pdo, $owner)) return null;
    return $release;
}

/** The gallery deliberately reads published titles, never private Canvas metadata. */
function vp3_profile_canvases_list_v1(PDO $pdo, array $profile): array
{
    if (empty($profile['is_public']) || empty($profile['is_active'])
        || !vp3_profile_canvases_ready_v1($pdo) || !vp3_coding_canvas_publishing_ready_v1($pdo)) return [];
    $owner = (int)($profile['user_id'] ?? 0);
    $live = vp3_profile_canvases_public_profile_v1($pdo, $owner);
    if (!$live || $live['username'] !== ($profile['username'] ?? null)) return [];
    $query = $pdo->prepare('SELECT p.slug FROM vp3_profile_canvases_v1 l
        JOIN vp3_coding_canvas_publications_v1 p ON p.canvas_id=l.canvas_id AND p.owner_user_id=l.owner_user_id
        WHERE l.owner_user_id=? AND l.listed=1 AND p.revision IS NOT NULL ORDER BY l.updated_at DESC,l.canvas_id ASC');
    $query->execute([$owner]);
    $slugs = $query->fetchAll(PDO::FETCH_COLUMN);
    $rows = [];
    foreach ($slugs as $slug) {
        $release = vp3_profile_canvas_release_v1($pdo, $profile, (string)$slug);
        if (!$release) continue;
        $rows[] = ['title'=>$release['title'],'url'=>vp3_profile_canvas_url_v1($live['username'], $release['slug']),
            'slug'=>$release['slug'],'revision'=>$release['revision']];
    }
    return $live === vp3_profile_canvases_public_profile_v1($pdo, $owner) ? $rows : [];
}
