<?php
declare(strict_types=1);

/**
 * Stonefellow v104 artist workspace helpers.
 *
 * Artist is a creative/operator account type. It receives the existing content,
 * analytics, Agent Chat and admin-shell permissions, but platform security
 * controls remain Admin-only. Artists may create up to two Manager/Producer
 * team accounts through the dedicated Team page.
 */

function artist_workspace_v104_artist_permissions(): array
{
    return [
        'account.access',
        'chat.access',
        'admin.access',
        'team.manage',
        'listening.view',
        'track_notes.manage',
        'tracks.manage',
        'albums.manage',
        'shows.manage',
        'photos.manage',
        'merch.manage',
        'posts.manage',
        'messages.manage',
        'profile.manage',
        'knowledge.access',
        'knowledge.manage',
    ];
}

function artist_workspace_v104_team_roles(): array
{
    return [
        'manager' => 'Manager',
        'producer' => 'Producer',
    ];
}

function artist_workspace_v104_team_limit(): int
{
    return 2;
}

function artist_workspace_v104_is_artist(?array $user = null): bool
{
    return user_has_role('artist', $user);
}

function artist_workspace_v104_valid_team_role(string $role): bool
{
    return array_key_exists($role, artist_workspace_v104_team_roles());
}

/**
 * Synchronize a user's multi-role assignments without issuing DDL.
 *
 * This helper is safe to call from an existing transaction. Schema creation
 * belongs to ensure_access_schema()/the v104 migration; MySQL DDL can cause an
 * implicit COMMIT and therefore must never run in this mutation path.
 */
function artist_workspace_v104_sync_account_types(
    PDO $pdo,
    int $userId,
    array $roles,
    string $primaryRole
): void {
    if ($userId < 1 || !valid_role($primaryRole)) {
        throw new RuntimeException('A valid primary account type is required.');
    }
    if (!table_exists('user_account_types')) {
        throw new RuntimeException('Stonefellow v104 database upgrade is required.');
    }

    $cleanRoles = [];
    foreach ($roles as $role) {
        $role = trim((string)$role);
        if ($role !== '' && valid_role($role)) {
            $cleanRoles[] = $role;
        }
    }
    $cleanRoles[] = $primaryRole;
    $cleanRoles = array_values(array_unique($cleanRoles));

    if (!$cleanRoles) {
        throw new RuntimeException('Select at least one account type.');
    }

    $delete = $pdo->prepare(
        'DELETE FROM user_account_types WHERE user_id=?'
    );
    $delete->execute([$userId]);

    $insert = $pdo->prepare(
        'INSERT INTO user_account_types (user_id,role) VALUES (?,?)'
    );
    foreach ($cleanRoles as $role) {
        $insert->execute([$userId, $role]);
    }

    $primary = $pdo->prepare('UPDATE users SET role=? WHERE id=?');
    $primary->execute([$primaryRole, $userId]);
}

function artist_workspace_v104_ensure_schema(): void
{
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS artist_team_members (
            artist_user_id INT UNSIGNED NOT NULL,
            member_user_id INT UNSIGNED NOT NULL,
            team_role VARCHAR(30) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (artist_user_id, member_user_id),
            UNIQUE KEY uq_artist_team_member (member_user_id),
            INDEX idx_artist_team_role (artist_user_id, team_role, member_user_id),
            CONSTRAINT fk_artist_team_artist
              FOREIGN KEY (artist_user_id) REFERENCES users(id)
              ON DELETE CASCADE,
            CONSTRAINT fk_artist_team_member
              FOREIGN KEY (member_user_id) REFERENCES users(id)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function artist_workspace_v104_seed_artist_permissions(): void
{
    $pdo = db();
    if (!$pdo || !permissions_schema_ready()) {
        return;
    }

    // Make sure newly introduced permission keys exist before role_permissions
    // attempts to reference them. Do this only for a previously unseeded Artist
    // role so later Admin customizations are not silently restored.
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM role_permissions WHERE role='artist'"
    );
    $countStmt->execute();
    if ((int)$countStmt->fetchColumn() > 0) {
        return;
    }

    seed_permission_catalog();

    $catalog = permission_catalog();
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO role_permissions (role, permission_key) VALUES (?, ?)'
    );

    foreach (artist_workspace_v104_artist_permissions() as $permission) {
        if (isset($catalog[$permission])) {
            $insert->execute(['artist', $permission]);
        }
    }
}

function artist_workspace_v104_team_count(PDO $pdo, int $artistUserId): int
{
    if ($artistUserId < 1) {
        return 0;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM artist_team_members atm
         INNER JOIN users u ON u.id=atm.member_user_id
         WHERE atm.artist_user_id=?'
    );
    $stmt->execute([$artistUserId]);
    return (int)$stmt->fetchColumn();
}

function artist_workspace_v104_team_members(PDO $pdo, int $artistUserId): array
{
    if ($artistUserId < 1) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT
            u.id,
            u.email,
            u.display_name,
            u.role,
            u.avatar_path,
            u.is_active,
            u.last_login_at,
            u.created_at,
            atm.team_role,
            atm.created_at AS team_created_at,
            atm.updated_at AS team_updated_at
         FROM artist_team_members atm
         INNER JOIN users u ON u.id=atm.member_user_id
         WHERE atm.artist_user_id=?
         ORDER BY u.is_active DESC,u.display_name ASC,u.id ASC'
    );
    $stmt->execute([$artistUserId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['roles'] = user_account_types_for_user_id(
            (int)$row['id'],
            (string)$row['role']
        );
    }
    unset($row);
    return $rows;
}

function artist_workspace_v104_team_member(PDO $pdo, int $artistUserId, int $memberUserId): ?array
{
    if ($artistUserId < 1 || $memberUserId < 1) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT
            u.id,
            u.email,
            u.display_name,
            u.role,
            u.avatar_path,
            u.is_active,
            u.last_login_at,
            u.created_at,
            atm.team_role
         FROM artist_team_members atm
         INNER JOIN users u ON u.id=atm.member_user_id
         WHERE atm.artist_user_id=? AND atm.member_user_id=?
         LIMIT 1'
    );
    $stmt->execute([$artistUserId, $memberUserId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    $row['roles'] = user_account_types_for_user_id(
        (int)$row['id'],
        (string)$row['role']
    );
    return $row;
}
