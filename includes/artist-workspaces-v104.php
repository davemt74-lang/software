<?php
declare(strict_types=1);

/**
 * Artist workspace delegation.
 *
 * Subscription packages control commercial capacity. Artist is an internal
 * workspace-owner identity. Manager and Producer are contextual roles on an
 * Artist workspace membership and never replace a person's base account.
 */

function artist_workspace_v104_artist_permissions(): array
{
    return [
        'account.access','chat.access','admin.access','team.manage','listening.view',
        'track_notes.manage','tracks.manage','albums.manage','shows.manage','photos.manage',
        'merch.manage','posts.manage','messages.manage','profile.manage','knowledge.access','knowledge.manage',
    ];
}

function artist_workspace_v104_team_roles(): array
{
    return ['manager'=>'Manager','producer'=>'Producer'];
}

function artist_workspace_v104_team_limit(?array $artist = null): int
{
    $artist ??= current_user();
    if ($artist && function_exists('subscription_entitlement_limit')) {
        $limit = subscription_entitlement_limit($artist,'team_seats',null);
        if ($limit !== null) return max(0,$limit);
    }
    return 2;
}

function artist_workspace_v104_is_artist(?array $user = null): bool
{
    $user ??= current_user();
    if (!$user) return false;
    return user_has_role('admin',$user) || user_has_role('artist',$user);
}

function artist_workspace_v104_valid_team_role(string $role): bool
{
    return array_key_exists($role,artist_workspace_v104_team_roles());
}

/** Retained only for older callers. Team membership no longer rewrites identity. */
function artist_workspace_v104_sync_account_types(PDO $pdo,int $userId,array $roles,string $primaryRole): void
{
    sync_user_account_types($pdo,$userId,$roles,$primaryRole);
}

/**
 * Normalize old v104 team accounts after the membership table becomes authoritative.
 * Only users already linked to an Artist are migrated. Their login, package,
 * profile and user id remain intact; only the obsolete global Manager/Producer
 * authority is removed.
 */
function artist_workspace_v104_migrate_contextual_roles(PDO $pdo): void
{
    if(!table_exists('artist_team_members')||!table_exists('user_account_types'))return;
    try{
        $linked=$pdo->query("SELECT DISTINCT u.id,u.role
            FROM users u
            INNER JOIN artist_team_members atm ON atm.member_user_id=u.id
            WHERE u.role IN ('manager','producer')")->fetchAll()?:[];
        $delete=$pdo->prepare("DELETE FROM user_account_types WHERE user_id=? AND role IN ('manager','producer')");
        $fan=$pdo->prepare("INSERT INTO user_account_types (user_id,role,assigned_explicitly_at) VALUES (?,'fan',NOW()) ON DUPLICATE KEY UPDATE assigned_explicitly_at=COALESCE(assigned_explicitly_at,NOW())");
        $primary=$pdo->prepare("UPDATE users SET role='fan' WHERE id=? AND role IN ('manager','producer')");
        foreach($linked as $row){
            $uid=(int)($row['id']??0);if($uid<1)continue;
            $delete->execute([$uid]);
            $fan->execute([$uid]);
            $primary->execute([$uid]);
        }
    }catch(Throwable $e){
        error_log('VP3 contextual team-role migration failed: '.$e->getMessage());
    }
}

function artist_workspace_v104_ensure_schema(): void
{
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_team_members (
        artist_user_id INT UNSIGNED NOT NULL,
        member_user_id INT UNSIGNED NOT NULL,
        team_role VARCHAR(30) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (artist_user_id,member_user_id),
        INDEX idx_artist_team_role (artist_user_id,team_role,member_user_id),
        INDEX idx_artist_team_member (member_user_id,artist_user_id,team_role),
        CONSTRAINT fk_artist_team_artist FOREIGN KEY (artist_user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_artist_team_member FOREIGN KEY (member_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try{$pdo->exec('ALTER TABLE artist_team_members DROP INDEX uq_artist_team_member');}catch(Throwable $e){}
    try{$pdo->exec('ALTER TABLE artist_team_members ADD INDEX idx_artist_team_member (member_user_id,artist_user_id,team_role)');}catch(Throwable $e){}
    artist_workspace_v104_migrate_contextual_roles($pdo);
}

function artist_workspace_v104_seed_artist_permissions(): void
{
    $pdo=db();if(!$pdo||!permissions_schema_ready())return;
    $countStmt=$pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role='artist'");$countStmt->execute();
    if((int)$countStmt->fetchColumn()>0)return;
    seed_permission_catalog();$catalog=permission_catalog();
    $insert=$pdo->prepare('INSERT IGNORE INTO role_permissions (role,permission_key) VALUES (?,?)');
    foreach(artist_workspace_v104_artist_permissions() as $permission)if(isset($catalog[$permission]))$insert->execute(['artist',$permission]);
}

function artist_workspace_v104_team_count(PDO $pdo,int $artistUserId): int
{
    if($artistUserId<1)return 0;
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM artist_team_members atm INNER JOIN users u ON u.id=atm.member_user_id WHERE atm.artist_user_id=?');
    $stmt->execute([$artistUserId]);return (int)$stmt->fetchColumn();
}

function artist_workspace_v104_team_members(PDO $pdo,int $artistUserId): array
{
    if($artistUserId<1)return [];
    $stmt=$pdo->prepare('SELECT u.id,u.email,u.display_name,u.role,u.avatar_path,u.is_active,u.last_login_at,u.created_at,atm.team_role,atm.created_at team_created_at,atm.updated_at team_updated_at FROM artist_team_members atm INNER JOIN users u ON u.id=atm.member_user_id WHERE atm.artist_user_id=? ORDER BY u.is_active DESC,u.display_name ASC,u.id ASC');
    $stmt->execute([$artistUserId]);return $stmt->fetchAll()?:[];
}

function artist_workspace_v104_team_member(PDO $pdo,int $artistUserId,int $memberUserId): ?array
{
    if($artistUserId<1||$memberUserId<1)return null;
    $stmt=$pdo->prepare('SELECT u.id,u.email,u.display_name,u.role,u.avatar_path,u.is_active,u.last_login_at,u.created_at,atm.team_role FROM artist_team_members atm INNER JOIN users u ON u.id=atm.member_user_id WHERE atm.artist_user_id=? AND atm.member_user_id=? LIMIT 1');
    $stmt->execute([$artistUserId,$memberUserId]);$row=$stmt->fetch();return is_array($row)?$row:null;
}

function artist_workspace_v104_memberships_for_user(PDO $pdo,int $memberUserId): array
{
    if($memberUserId<1)return [];
    $stmt=$pdo->prepare('SELECT atm.artist_user_id,atm.team_role,atm.created_at,atm.updated_at,u.display_name artist_name,u.email artist_email FROM artist_team_members atm INNER JOIN users u ON u.id=atm.artist_user_id WHERE atm.member_user_id=? AND u.is_active=1 ORDER BY u.display_name,atm.artist_user_id');
    $stmt->execute([$memberUserId]);return $stmt->fetchAll()?:[];
}

function artist_workspace_v104_membership(PDO $pdo,int $artistUserId,int $memberUserId): ?array
{
    if($artistUserId<1||$memberUserId<1)return null;
    $stmt=$pdo->prepare('SELECT artist_user_id,member_user_id,team_role,created_at,updated_at FROM artist_team_members WHERE artist_user_id=? AND member_user_id=? LIMIT 1');
    $stmt->execute([$artistUserId,$memberUserId]);$row=$stmt->fetch();return is_array($row)?$row:null;
}

function artist_workspace_v104_member_role(int $artistUserId,?array $user=null): string
{
    $user??=current_user();$uid=(int)($user['id']??0);$pdo=db();
    if(!$pdo||$artistUserId<1||$uid<1)return '';
    $row=artist_workspace_v104_membership($pdo,$artistUserId,$uid);
    return artist_workspace_v104_valid_team_role((string)($row['team_role']??''))?(string)$row['team_role']:'';
}

function artist_workspace_v104_can_access(int $artistUserId,?array $user=null): bool
{
    $user??=current_user();if(!$user||$artistUserId<1)return false;
    if(user_has_role('admin',$user))return true;
    if((int)($user['id']??0)===$artistUserId&&user_has_role('artist',$user))return true;
    return artist_workspace_v104_member_role($artistUserId,$user)!=='';
}

function artist_workspace_v104_can_manage(int $artistUserId,string $capability,?array $user=null): bool
{
    $user??=current_user();if(!$user)return false;
    if(user_has_role('admin',$user))return true;
    if((int)($user['id']??0)===$artistUserId&&user_has_role('artist',$user))return true;
    $role=artist_workspace_v104_member_role($artistUserId,$user);
    if($role==='manager')return in_array($capability,['tracks','albums','shows','photos','merch','posts','profile','knowledge','listening'],true);
    if($role==='producer')return in_array($capability,['production','track_notes'],true);
    return false;
}

function artist_workspace_v104_attach_member(PDO $pdo,int $artistUserId,int $memberUserId,string $teamRole): void
{
    if(!artist_workspace_v104_valid_team_role($teamRole))throw new RuntimeException('Select a valid team role.');
    if($artistUserId<1||$memberUserId<1||$artistUserId===$memberUserId)throw new RuntimeException('Select another user for this team seat.');
    $stmt=$pdo->prepare('INSERT INTO artist_team_members (artist_user_id,member_user_id,team_role) VALUES (?,?,?) ON DUPLICATE KEY UPDATE team_role=VALUES(team_role),updated_at=NOW()');
    $stmt->execute([$artistUserId,$memberUserId,$teamRole]);
    artist_workspace_v104_migrate_contextual_roles($pdo);
}

function artist_workspace_v104_detach_member(PDO $pdo,int $artistUserId,int $memberUserId): void
{
    $stmt=$pdo->prepare('DELETE FROM artist_team_members WHERE artist_user_id=? AND member_user_id=?');$stmt->execute([$artistUserId,$memberUserId]);
}
