<?php
declare(strict_types=1);

/**
 * Music Platform V1 — Section 1
 * Canonical artist identity and workspace authority.
 *
 * Artist identity is intentionally independent from login identity:
 * - artist_workspaces_v181 remains the business/team authority boundary.
 * - one workspace may own multiple canonical artists.
 * - one artist may be managed by multiple users.
 * - artist membership never grants access to a workspace by itself.
 *
 * Existing Music Workspaces are migrated to one primary artist so the mature
 * Music Workspace, catalog, Studio and release systems keep their authority.
 */
const VP3_MUSIC_ARTIST_V100 = 'music-artist-v100-20260929';

function music_artist_v100_index_exists(PDO $pdo,string $table,string $index): bool
{
    try{
        $stmt=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1');
        $stmt->execute([$table,$index]);
        return (bool)$stmt->fetchColumn();
    }catch(Throwable $e){return false;}
}

function music_artist_v100_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach(['music_artists_v100','music_artist_memberships_v100','music_artist_authority_events_v100'] as $table){
        if(!table_exists($table))return false;
    }
    foreach([
        'music_artists_v100'=>['workspace_id','name','slug','status','is_primary','verification_status'],
        'music_artist_memberships_v100'=>['artist_id','user_id','artist_role','membership_status'],
        'music_artist_authority_events_v100'=>['artist_id','workspace_id','event_type','actor_user_id','subject_user_id'],
    ] as $table=>$columns){
        foreach($columns as $column)if(!column_exists($table,$column))return false;
    }
    return true;
}

function music_artist_v100_slug(string $value): string
{
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/','-',$value)??'';
    return substr(trim($value,'-'),0,120);
}

function music_artist_v100_unique_slug(PDO $pdo,string $value,int $excludeArtistId=0): string
{
    $base=music_artist_v100_slug($value);
    if($base==='')$base='artist';
    $slug=$base;
    for($n=0;$n<1000;$n++){
        $stmt=$pdo->prepare('SELECT 1 FROM music_artists_v100 WHERE slug=? AND id<>? LIMIT 1');
        $stmt->execute([$slug,$excludeArtistId]);
        if(!$stmt->fetchColumn())return $slug;
        $slug=$base.'-'.($n+2);
    }
    throw new RuntimeException('A unique artist URL could not be generated.');
}

function music_artist_v100_valid_role(string $role): bool
{
    return in_array($role,['owner','manager','editor','producer','viewer'],true);
}

function music_artist_v100_valid_membership_status(string $status): bool
{
    return in_array($status,['active','suspended','removed'],true);
}

function music_artist_v100_event(
    PDO $pdo,
    string $eventType,
    ?int $artistId,
    ?int $workspaceId,
    ?int $actorUserId=null,
    ?int $subjectUserId=null,
    array $metadata=[]
): void {
    $eventType=substr(trim($eventType),0,80);
    if($eventType==='')return;
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)$json='{}';
    $stmt=$pdo->prepare('INSERT INTO music_artist_authority_events_v100 (artist_id,workspace_id,event_type,actor_user_id,subject_user_id,metadata_json) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$artistId?:null,$workspaceId?:null,$eventType,$actorUserId?:null,$subjectUserId?:null,$json]);
}

function music_artist_v100_workspace_row(PDO $pdo,int $workspaceId): ?array
{
    if($workspaceId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM artist_workspaces_v181 WHERE id=? LIMIT 1');
    $stmt->execute([$workspaceId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function music_artist_v100_primary_for_workspace(PDO $pdo,int $workspaceId): ?array
{
    if($workspaceId<1)return null;
    $stmt=$pdo->prepare("SELECT * FROM music_artists_v100 WHERE workspace_id=? AND status<>'archived' ORDER BY is_primary DESC,id ASC LIMIT 1");
    $stmt->execute([$workspaceId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function music_artist_v100_artist(PDO $pdo,int $artistId): ?array
{
    if($artistId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM music_artists_v100 WHERE id=? LIMIT 1');
    $stmt->execute([$artistId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function music_artist_v100_artists_for_workspace(PDO $pdo,int $workspaceId,bool $includeArchived=false): array
{
    if($workspaceId<1)return [];
    $sql='SELECT * FROM music_artists_v100 WHERE workspace_id=?';
    if(!$includeArchived)$sql.=" AND status<>'archived'";
    $sql.=' ORDER BY is_primary DESC,name ASC,id ASC';
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$workspaceId]);
    return $stmt->fetchAll()?:[];
}

function music_artist_v100_membership(PDO $pdo,int $artistId,int $userId): ?array
{
    if($artistId<1||$userId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM music_artist_memberships_v100 WHERE artist_id=? AND user_id=? LIMIT 1');
    $stmt->execute([$artistId,$userId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function music_artist_v100_upsert_membership(
    PDO $pdo,
    int $artistId,
    int $userId,
    string $role,
    string $status='active',
    ?int $createdByUserId=null
): void {
    if($artistId<1||$userId<1)throw new RuntimeException('Artist membership requires an artist and user.');
    if(!music_artist_v100_valid_role($role))throw new RuntimeException('Choose a valid artist role.');
    if(!music_artist_v100_valid_membership_status($status))throw new RuntimeException('Choose a valid artist membership status.');
    $stmt=$pdo->prepare('INSERT INTO music_artist_memberships_v100 (artist_id,user_id,artist_role,membership_status,created_by_user_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE artist_role=VALUES(artist_role),membership_status=VALUES(membership_status),updated_at=NOW()');
    $stmt->execute([$artistId,$userId,$role,$status,$createdByUserId?:null]);
}

function music_artist_v100_seed_primary_for_workspace(PDO $pdo,array $workspace): int
{
    $workspaceId=(int)($workspace['id']??0);
    $ownerUserId=(int)($workspace['artist_user_id']??0);
    if($workspaceId<1||$ownerUserId<1)return 0;

    $artist=music_artist_v100_primary_for_workspace($pdo,$workspaceId);
    if(!$artist){
        $name=trim((string)($workspace['workspace_name']??''))?:'Artist';
        $slugSource=trim((string)($workspace['profile_slug']??''))?:$name;
        $slug=music_artist_v100_unique_slug($pdo,$slugSource);
        $stmt=$pdo->prepare("INSERT INTO music_artists_v100 (
            workspace_id,created_by_user_id,name,slug,status,is_primary,verification_status,bio,
            profile_image_path,cover_image_path,website_url,instagram_url,tiktok_url,youtube_url,spotify_url,apple_music_url
        ) VALUES (?,?,?,?,'active',1,'unverified',?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $workspaceId,$ownerUserId,$name,$slug,(string)($workspace['bio']??''),
            (string)($workspace['profile_image_path']??''),(string)($workspace['cover_image_path']??''),
            (string)($workspace['website_url']??''),(string)($workspace['instagram_url']??''),
            (string)($workspace['tiktok_url']??''),(string)($workspace['youtube_url']??''),
            (string)($workspace['spotify_url']??''),(string)($workspace['apple_music_url']??''),
        ]);
        $artistId=(int)$pdo->lastInsertId();
        music_artist_v100_event($pdo,'artist.migrated',$artistId,$workspaceId,$ownerUserId,$ownerUserId,['source'=>'artist_workspaces_v181']);
    }else{
        $artistId=(int)$artist['id'];
    }

    music_artist_v100_upsert_membership($pdo,$artistId,$ownerUserId,'owner','active',$ownerUserId);
    return $artistId;
}

function music_artist_v100_migrate_existing_workspaces(PDO $pdo): void
{
    if(!table_exists('artist_workspaces_v181'))return;
    $rows=$pdo->query('SELECT * FROM artist_workspaces_v181 ORDER BY id ASC')->fetchAll()?:[];
    foreach($rows as $workspace)music_artist_v100_seed_primary_for_workspace($pdo,$workspace);
}

function music_artist_v100_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!table_exists('artist_workspaces_v181'))throw new RuntimeException('Artist Workspace schema must be installed before Music Artist identity.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_artists_v100 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        workspace_id BIGINT UNSIGNED NOT NULL,
        created_by_user_id INT UNSIGNED NULL,
        name VARCHAR(190) NOT NULL,
        slug VARCHAR(190) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        verification_status VARCHAR(30) NOT NULL DEFAULT 'unverified',
        bio TEXT NULL,
        genres TEXT NULL,
        location VARCHAR(190) NOT NULL DEFAULT '',
        profile_image_path VARCHAR(500) NOT NULL DEFAULT '',
        cover_image_path VARCHAR(500) NOT NULL DEFAULT '',
        website_url VARCHAR(500) NOT NULL DEFAULT '',
        instagram_url VARCHAR(500) NOT NULL DEFAULT '',
        tiktok_url VARCHAR(500) NOT NULL DEFAULT '',
        youtube_url VARCHAR(500) NOT NULL DEFAULT '',
        spotify_url VARCHAR(500) NOT NULL DEFAULT '',
        apple_music_url VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_music_artist_slug_v100 (slug),
        INDEX idx_music_artist_workspace_v100 (workspace_id,is_primary,status,id),
        CONSTRAINT fk_music_artist_workspace_v100 FOREIGN KEY (workspace_id) REFERENCES artist_workspaces_v181(id) ON DELETE CASCADE,
        CONSTRAINT fk_music_artist_creator_v100 FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_artist_memberships_v100 (
        artist_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        artist_role VARCHAR(30) NOT NULL DEFAULT 'viewer',
        membership_status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (artist_id,user_id),
        INDEX idx_music_artist_member_user_v100 (user_id,membership_status,artist_role,artist_id),
        CONSTRAINT fk_music_artist_membership_artist_v100 FOREIGN KEY (artist_id) REFERENCES music_artists_v100(id) ON DELETE CASCADE,
        CONSTRAINT fk_music_artist_membership_user_v100 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_music_artist_membership_creator_v100 FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_artist_authority_events_v100 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        artist_id BIGINT UNSIGNED NULL,
        workspace_id BIGINT UNSIGNED NULL,
        event_type VARCHAR(80) NOT NULL,
        actor_user_id INT UNSIGNED NULL,
        subject_user_id INT UNSIGNED NULL,
        metadata_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_music_artist_event_artist_v100 (artist_id,created_at,id),
        INDEX idx_music_artist_event_workspace_v100 (workspace_id,created_at,id),
        INDEX idx_music_artist_event_subject_v100 (subject_user_id,created_at,id),
        CONSTRAINT fk_music_artist_event_artist_v100 FOREIGN KEY (artist_id) REFERENCES music_artists_v100(id) ON DELETE SET NULL,
        CONSTRAINT fk_music_artist_event_workspace_v100 FOREIGN KEY (workspace_id) REFERENCES artist_workspaces_v181(id) ON DELETE SET NULL,
        CONSTRAINT fk_music_artist_event_actor_v100 FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_music_artist_event_subject_v100 FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    music_artist_v100_migrate_existing_workspaces($pdo);
}

function music_artist_v100_effective_role(PDO $pdo,array $artist,array $user): string
{
    $userId=(int)($user['id']??0);
    $workspaceId=(int)($artist['workspace_id']??0);
    if($userId<1||$workspaceId<1)return '';
    if(user_has_role('admin',$user))return 'admin';

    $ownerId=music_workspace_resources_v330_workspace_owner_id($pdo,$workspaceId);
    if($ownerId===$userId)return 'owner';

    // The migrated primary artist preserves the mature Music Workspace role
    // model exactly. Secondary artist identities require explicit scoping.
    if(!empty($artist['is_primary'])){
        $workspaceRole=music_workspace_resources_v330_member_role($pdo,$workspaceId,$userId);
        if(in_array($workspaceRole,['manager','producer'],true))return $workspaceRole;
    }

    $membership=music_artist_v100_membership($pdo,(int)$artist['id'],$userId);
    if(!$membership||(string)($membership['membership_status']??'')!=='active')return '';
    $role=(string)($membership['artist_role']??'');
    return music_artist_v100_valid_role($role)?$role:'';
}

function music_artist_v100_can_access(PDO $pdo,int $artistId,?array $user=null): bool
{
    $user??=current_user();
    if(!$user||$artistId<1)return false;
    $artist=music_artist_v100_artist($pdo,$artistId);
    if(!$artist||(string)($artist['status']??'')==='archived')return false;
    $workspaceId=(int)$artist['workspace_id'];
    if(!music_workspace_resources_v330_can_access($pdo,$workspaceId,$user))return false;
    return music_artist_v100_effective_role($pdo,$artist,$user)!=='';
}

function music_artist_v100_role_capabilities(string $role): array
{
    return match($role){
        'admin','owner'=>['profile','catalog','releases','media','commerce','team','production','credits'],
        'manager'=>['profile','catalog','releases','media','commerce','team','production','credits'],
        'editor'=>['profile','catalog','media','credits'],
        'producer'=>['production','credits'],
        'viewer'=>[],
        default=>[],
    };
}

function music_artist_v100_can_manage(PDO $pdo,int $artistId,string $capability,?array $user=null): bool
{
    $user??=current_user();
    if(!$user||$artistId<1||$capability==='')return false;
    $artist=music_artist_v100_artist($pdo,$artistId);
    if(!$artist||(string)($artist['status']??'')==='archived')return false;
    if(!music_workspace_resources_v330_can_access($pdo,(int)$artist['workspace_id'],$user))return false;
    $role=music_artist_v100_effective_role($pdo,$artist,$user);
    return in_array($capability,music_artist_v100_role_capabilities($role),true);
}

function music_artist_v100_create(PDO $pdo,int $workspaceId,array $user,string $name,string $requestedSlug=''): array
{
    $name=trim($name);
    if($workspaceId<1||$name==='')throw new RuntimeException('Artist name is required.');
    if(mb_strlen($name)>190)throw new RuntimeException('Artist name is too long.');
    if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'profile',$user))throw new RuntimeException('Artist creation is not available to your workspace role.');

    $workspace=music_artist_v100_workspace_row($pdo,$workspaceId);
    if(!$workspace)throw new RuntimeException('Music Workspace was not found.');
    $workspaceOwnerId=(int)($workspace['artist_user_id']??0);
    $actorId=(int)($user['id']??0);
    if($workspaceOwnerId<1||$actorId<1)throw new RuntimeException('Artist authority could not be resolved.');

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM artist_workspaces_v181 WHERE id=? FOR UPDATE');
        $lock->execute([$workspaceId]);
        if(!$lock->fetchColumn())throw new RuntimeException('Music Workspace was not found.');

        $hasArtist=(int)$pdo->prepare("SELECT COUNT(*) FROM music_artists_v100 WHERE workspace_id=? AND status<>'archived'")->execute([$workspaceId]);
        $countStmt=$pdo->prepare("SELECT COUNT(*) FROM music_artists_v100 WHERE workspace_id=? AND status<>'archived'");
        $countStmt->execute([$workspaceId]);
        $isPrimary=(int)$countStmt->fetchColumn()===0?1:0;

        $slug=music_artist_v100_unique_slug($pdo,$requestedSlug!==''?$requestedSlug:$name);
        $insert=$pdo->prepare("INSERT INTO music_artists_v100 (workspace_id,created_by_user_id,name,slug,status,is_primary,verification_status) VALUES (?,?,?,?,'active',?,'unverified')");
        $insert->execute([$workspaceId,$actorId,$name,$slug,$isPrimary]);
        $artistId=(int)$pdo->lastInsertId();

        music_artist_v100_upsert_membership($pdo,$artistId,$workspaceOwnerId,'owner','active',$actorId);
        if($actorId!==$workspaceOwnerId){
            $workspaceRole=music_workspace_resources_v330_member_role($pdo,$workspaceId,$actorId);
            $artistRole=$workspaceRole==='producer'?'producer':'manager';
            music_artist_v100_upsert_membership($pdo,$artistId,$actorId,$artistRole,'active',$actorId);
        }

        music_artist_v100_event($pdo,'artist.created',$artistId,$workspaceId,$actorId,$workspaceOwnerId,['is_primary'=>$isPrimary]);
        if($ownsTransaction)$pdo->commit();
        return music_artist_v100_artist($pdo,$artistId)??throw new RuntimeException('Artist could not be reloaded.');
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function music_artist_v100_set_membership(
    PDO $pdo,
    int $artistId,
    int $subjectUserId,
    string $role,
    string $status,
    array $actor
): void {
    $artist=music_artist_v100_artist($pdo,$artistId);
    if(!$artist)throw new RuntimeException('Artist was not found.');
    if(!music_artist_v100_can_manage($pdo,$artistId,'team',$actor))throw new RuntimeException('Artist membership management is not available to your role.');
    if(!music_artist_v100_valid_role($role)||!music_artist_v100_valid_membership_status($status))throw new RuntimeException('Choose a valid artist membership.');

    $workspaceId=(int)$artist['workspace_id'];
    $workspaceOwnerId=music_workspace_resources_v330_workspace_owner_id($pdo,$workspaceId);
    if($role==='owner'&&$subjectUserId!==$workspaceOwnerId)throw new RuntimeException('The Music Workspace owner is the canonical artist owner.');
    if($subjectUserId===$workspaceOwnerId&&($role!=='owner'||$status!=='active'))throw new RuntimeException('The Music Workspace owner cannot be removed from artist authority.');

    $actorId=(int)($actor['id']??0);
    $before=music_artist_v100_membership($pdo,$artistId,$subjectUserId);
    music_artist_v100_upsert_membership($pdo,$artistId,$subjectUserId,$role,$status,$actorId);
    music_artist_v100_event($pdo,'artist.membership.changed',$artistId,$workspaceId,$actorId,$subjectUserId,[
        'before_role'=>(string)($before['artist_role']??''),
        'before_status'=>(string)($before['membership_status']??''),
        'after_role'=>$role,
        'after_status'=>$status,
    ]);
}
