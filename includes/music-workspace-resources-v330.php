<?php
declare(strict_types=1);

/**
 * Canonical Music Workspace resource ownership.
 *
 * VP3 identity, personal playlists/favorites/listening history remain user-owned.
 * Professional music catalog, production, release and credit resources resolve to
 * artist_workspaces_v181.id. Legacy owner/producer user ids are attribution and
 * migration compatibility only; they are not the primary authorization boundary.
 */
const VP3_MUSIC_WORKSPACE_RESOURCES_V330 = 'music-workspace-resources-v330-20260909';

function music_workspace_resources_v330_index_exists(PDO $pdo,string $table,string $index): bool
{
    try{
        $stmt=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1');
        $stmt->execute([$table,$index]);
        return (bool)$stmt->fetchColumn();
    }catch(Throwable $e){return false;}
}

function music_workspace_resources_v330_required_columns(): array
{
    return [
        'tracks'=>'workspace_id',
        'albums'=>'workspace_id',
        'track_projects'=>'workspace_id',
        'track_stems'=>'workspace_id',
        'shows'=>'workspace_id',
        'release_plans'=>'workspace_id',
        'release_items'=>'workspace_id',
        'track_credits'=>'workspace_id',
    ];
}

function music_workspace_resources_v330_optional_columns(): array
{
    return [
        'track_studio_participants'=>'workspace_id',
        'agent_work_actions'=>'workspace_id',
    ];
}

function music_workspace_resources_v330_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo||!artist_workspace_v181_schema_ready($pdo))return false;
    foreach(music_workspace_resources_v330_required_columns() as $table=>$column){
        if(!table_exists($table)||!column_exists($table,$column))return false;
    }
    if(table_exists('playlists')&&!column_exists('playlists','artist_workspace_id'))return false;
    foreach(music_workspace_resources_v330_optional_columns() as $table=>$column){
        if(table_exists($table)&&!column_exists($table,$column))return false;
    }
    return true;
}

function music_workspace_resources_v330_add_workspace_column(PDO $pdo,string $table,string $afterColumn=''): void
{
    if(!table_exists($table)||column_exists($table,'workspace_id'))return;
    $safeTable=preg_replace('/[^A-Za-z0-9_]/','',$table)??'';
    $safeAfter=preg_replace('/[^A-Za-z0-9_]/','',$afterColumn)??'';
    if($safeTable===''||$safeTable!==$table)throw new RuntimeException('Invalid Music Workspace table.');
    $after=$safeAfter!==''?' AFTER `'.$safeAfter.'`':'';
    $pdo->exec('ALTER TABLE `'.$safeTable.'` ADD COLUMN workspace_id BIGINT UNSIGNED NULL'.$after);
}

function music_workspace_resources_v330_add_index(PDO $pdo,string $table,string $index,string $columns): void
{
    if(!table_exists($table)||music_workspace_resources_v330_index_exists($pdo,$table,$index))return;
    $safeTable=preg_replace('/[^A-Za-z0-9_]/','',$table)??'';
    $safeIndex=preg_replace('/[^A-Za-z0-9_]/','',$index)??'';
    if($safeTable!==$table||$safeIndex!==$index||$safeTable===''||$safeIndex==='')throw new RuntimeException('Invalid Music Workspace index.');
    $pdo->exec('ALTER TABLE `'.$safeTable.'` ADD INDEX `'.$safeIndex.'` ('.$columns.')');
}

function music_workspace_resources_v330_ensure_legacy_workspace(PDO $pdo,int $ownerUserId): int
{
    if($ownerUserId<1)return 0;
    $stmt=$pdo->prepare('SELECT id FROM artist_workspaces_v181 WHERE artist_user_id=? LIMIT 1');
    $stmt->execute([$ownerUserId]);
    $workspaceId=(int)$stmt->fetchColumn();
    if($workspaceId>0)return $workspaceId;

    $u=$pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');
    $u->execute([$ownerUserId]);
    $name=trim((string)$u->fetchColumn());
    if($name==='')return 0;
    $pdo->prepare('INSERT IGNORE INTO artist_workspaces_v181 (artist_user_id,workspace_name) VALUES (?,?)')->execute([$ownerUserId,$name]);
    $stmt->execute([$ownerUserId]);
    return (int)$stmt->fetchColumn();
}

function music_workspace_resources_v330_backfill(PDO $pdo): void
{
    // Ensure every existing professional owner has a canonical workspace before
    // assigning workspace ids. Public/platform rows without an owner remain NULL.
    $ownerIds=[];
    $queries=[];
    if(table_exists('tracks')&&column_exists('tracks','owner_user_id'))$queries[]='SELECT DISTINCT owner_user_id id FROM tracks WHERE owner_user_id IS NOT NULL AND owner_user_id>0';
    if(table_exists('shows')&&column_exists('shows','owner_user_id'))$queries[]='SELECT DISTINCT owner_user_id id FROM shows WHERE owner_user_id IS NOT NULL AND owner_user_id>0';
    if(table_exists('release_plans')&&column_exists('release_plans','owner_user_id'))$queries[]='SELECT DISTINCT owner_user_id id FROM release_plans WHERE owner_user_id IS NOT NULL AND owner_user_id>0';
    foreach($queries as $sql){
        try{foreach($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$ownerIds[(int)$id]=true;}catch(Throwable $e){}
    }
    foreach(array_keys($ownerIds) as $ownerId)music_workspace_resources_v330_ensure_legacy_workspace($pdo,(int)$ownerId);

    if(table_exists('tracks')&&column_exists('tracks','workspace_id')){
        $pdo->exec('UPDATE tracks t INNER JOIN artist_workspaces_v181 w ON w.artist_user_id=t.owner_user_id SET t.workspace_id=w.id WHERE t.workspace_id IS NULL AND t.owner_user_id IS NOT NULL');
        // Existing v181 source links are authoritative if a historical owner field disagrees.
        if(table_exists('artist_catalog_tracks_v181'))$pdo->exec('UPDATE tracks t INNER JOIN artist_catalog_tracks_v181 c ON c.source_track_id=t.id SET t.workspace_id=c.workspace_id WHERE t.workspace_id IS NULL OR t.workspace_id<>c.workspace_id');
    }
    if(table_exists('track_projects')&&column_exists('track_projects','workspace_id'))$pdo->exec('UPDATE track_projects p INNER JOIN tracks t ON t.id=p.track_id SET p.workspace_id=t.workspace_id WHERE p.workspace_id IS NULL AND t.workspace_id IS NOT NULL');
    if(table_exists('track_stems')&&column_exists('track_stems','workspace_id'))$pdo->exec('UPDATE track_stems s INNER JOIN tracks t ON t.id=s.track_id SET s.workspace_id=t.workspace_id WHERE s.workspace_id IS NULL AND t.workspace_id IS NOT NULL');
    if(table_exists('track_studio_participants')&&column_exists('track_studio_participants','workspace_id'))$pdo->exec('UPDATE track_studio_participants sp INNER JOIN track_projects p ON p.id=sp.project_id SET sp.workspace_id=p.workspace_id WHERE sp.workspace_id IS NULL AND p.workspace_id IS NOT NULL');

    if(table_exists('albums')&&column_exists('albums','workspace_id')&&table_exists('tracks')&&column_exists('tracks','album_id')){
        // Only claim a legacy album when every linked track belongs to the same workspace.
        $pdo->exec("UPDATE albums a INNER JOIN (
            SELECT album_id,MIN(workspace_id) workspace_id
            FROM tracks
            WHERE album_id IS NOT NULL
            GROUP BY album_id
            HAVING COUNT(*)=COUNT(workspace_id) AND MIN(workspace_id)=MAX(workspace_id) AND MIN(workspace_id) IS NOT NULL
        ) x ON x.album_id=a.id SET a.workspace_id=x.workspace_id WHERE a.workspace_id IS NULL");
        if(table_exists('artist_catalog_albums_v181')&&column_exists('artist_catalog_albums_v181','source_album_id'))$pdo->exec('UPDATE albums a INNER JOIN artist_catalog_albums_v181 c ON c.source_album_id=a.id SET a.workspace_id=c.workspace_id WHERE a.workspace_id IS NULL OR a.workspace_id<>c.workspace_id');
    }

    if(table_exists('shows')&&column_exists('shows','workspace_id')&&column_exists('shows','owner_user_id'))$pdo->exec('UPDATE shows s INNER JOIN artist_workspaces_v181 w ON w.artist_user_id=s.owner_user_id SET s.workspace_id=w.id WHERE s.workspace_id IS NULL AND s.owner_user_id IS NOT NULL');
    if(table_exists('release_plans')&&column_exists('release_plans','workspace_id'))$pdo->exec('UPDATE release_plans r INNER JOIN artist_workspaces_v181 w ON w.artist_user_id=r.owner_user_id SET r.workspace_id=w.id WHERE r.workspace_id IS NULL');
    if(table_exists('release_items')&&column_exists('release_items','workspace_id'))$pdo->exec('UPDATE release_items i INNER JOIN release_plans r ON r.id=i.release_id SET i.workspace_id=r.workspace_id WHERE i.workspace_id IS NULL AND r.workspace_id IS NOT NULL');
    if(table_exists('track_credits')&&column_exists('track_credits','workspace_id'))$pdo->exec('UPDATE track_credits c INNER JOIN tracks t ON t.id=c.track_id SET c.workspace_id=t.workspace_id WHERE c.workspace_id IS NULL AND t.workspace_id IS NOT NULL');
    if(table_exists('agent_work_actions')&&column_exists('agent_work_actions','workspace_id')&&column_exists('agent_work_actions','release_id'))$pdo->exec('UPDATE agent_work_actions a INNER JOIN release_plans r ON r.id=a.release_id SET a.workspace_id=r.workspace_id WHERE a.workspace_id IS NULL AND r.workspace_id IS NOT NULL');
}

function music_workspace_resources_v330_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    artist_workspace_v181_ensure_schema($pdo);

    $definitions=[
        'tracks'=>['after'=>'owner_user_id','index'=>'idx_tracks_workspace_updated','columns'=>'workspace_id,updated_at,id'],
        'albums'=>['after'=>'id','index'=>'idx_albums_workspace_sort','columns'=>'workspace_id,sort_order,id'],
        'track_projects'=>['after'=>'track_id','index'=>'idx_track_projects_workspace','columns'=>'workspace_id,updated_at,id'],
        'track_stems'=>['after'=>'project_id','index'=>'idx_track_stems_workspace','columns'=>'workspace_id,track_id,is_active,id'],
        'shows'=>['after'=>'owner_user_id','index'=>'idx_shows_workspace_date','columns'=>'workspace_id,show_date,id'],
        'release_plans'=>['after'=>'owner_user_id','index'=>'idx_release_workspace_target','columns'=>'workspace_id,target_date,status,id'],
        'release_items'=>['after'=>'release_id','index'=>'idx_release_items_workspace','columns'=>'workspace_id,due_at,status,id'],
        'track_credits'=>['after'=>'track_id','index'=>'idx_track_credits_workspace','columns'=>'workspace_id,track_id,id'],
        'track_studio_participants'=>['after'=>'project_id','index'=>'idx_studio_participants_workspace','columns'=>'workspace_id,user_id,id'],
        'agent_work_actions'=>['after'=>'owner_user_id','index'=>'idx_agent_work_workspace','columns'=>'workspace_id,status,scheduled_for,id'],
    ];
    foreach($definitions as $table=>$def){
        if(!table_exists($table))continue;
        music_workspace_resources_v330_add_workspace_column($pdo,$table,(string)$def['after']);
        music_workspace_resources_v330_add_index($pdo,$table,(string)$def['index'],(string)$def['columns']);
    }
    // v181 already introduced artist_workspace_id on playlists; keep that nullable
    // because a VP3 member's personal playlists are core user data, not Team data.
    if(table_exists('playlists')&&!column_exists('playlists','artist_workspace_id')){
        $pdo->exec('ALTER TABLE playlists ADD COLUMN artist_workspace_id BIGINT UNSIGNED NULL AFTER owner_user_id');
        music_workspace_resources_v330_add_index($pdo,'playlists','idx_playlists_artist_workspace','artist_workspace_id,updated_at,id');
    }
    music_workspace_resources_v330_backfill($pdo);
}

function music_workspace_resources_v330_workspace(PDO $pdo,int $workspaceId): ?array
{
    if($workspaceId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM artist_workspaces_v181 WHERE id=? LIMIT 1');
    $stmt->execute([$workspaceId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function music_workspace_resources_v330_owner_workspace(PDO $pdo,int $ownerUserId): ?array
{
    if($ownerUserId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM artist_workspaces_v181 WHERE artist_user_id=? LIMIT 1');
    $stmt->execute([$ownerUserId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function music_workspace_resources_v330_workspace_owner_id(PDO $pdo,int $workspaceId): int
{
    $workspace=music_workspace_resources_v330_workspace($pdo,$workspaceId);
    return (int)($workspace['artist_user_id']??0);
}

function music_workspace_resources_v330_member_role(PDO $pdo,int $workspaceId,int $userId): string
{
    if($workspaceId<1||$userId<1)return '';
    $ownerId=music_workspace_resources_v330_workspace_owner_id($pdo,$workspaceId);
    if($ownerId<1)return '';
    if($ownerId===$userId)return 'owner';
    if(!table_exists('artist_team_members'))return '';
    $stmt=$pdo->prepare('SELECT atm.team_role FROM artist_team_members atm INNER JOIN users u ON u.id=atm.member_user_id AND u.is_active=1 WHERE atm.artist_user_id=? AND atm.member_user_id=? LIMIT 1');
    $stmt->execute([$ownerId,$userId]);
    return (string)($stmt->fetchColumn()?:'');
}

function music_workspace_resources_v330_can_access(PDO $pdo,int $workspaceId,?array $user=null): bool
{
    $user??=current_user();
    if(!$user||$workspaceId<1)return false;
    if(user_has_role('admin',$user))return true;
    return music_workspace_resources_v330_member_role($pdo,$workspaceId,(int)($user['id']??0))!=='';
}

function music_workspace_resources_v330_can_manage(PDO $pdo,int $workspaceId,string $capability,?array $user=null): bool
{
    $user??=current_user();
    if(!$user||$workspaceId<1)return false;
    if(user_has_role('admin',$user))return true;
    $uid=(int)($user['id']??0);
    $role=music_workspace_resources_v330_member_role($pdo,$workspaceId,$uid);
    if($role==='owner')return music_workspace_enabled_v320($user)||music_workspace_legacy_user_v320($user);
    if($role==='manager')return in_array($capability,['tracks','albums','releases','shows','credits','profile','media','team'],true);
    if($role==='producer')return in_array($capability,['production','track_notes','credits'],true);
    return false;
}

function music_workspace_resources_v330_track_workspace_id(PDO $pdo,array $track): int
{
    $workspaceId=(int)($track['workspace_id']??0);
    if($workspaceId>0)return $workspaceId;
    $ownerId=(int)($track['owner_user_id']??0);
    $workspace=$ownerId>0?music_workspace_resources_v330_owner_workspace($pdo,$ownerId):null;
    return (int)($workspace['id']??0);
}

function music_workspace_resources_v330_can_manage_track(PDO $pdo,array $track,?array $user=null): bool
{
    $user??=current_user();
    if(!$user)return false;
    if(user_has_role('admin',$user))return true;
    $workspaceId=music_workspace_resources_v330_track_workspace_id($pdo,$track);
    if($workspaceId<1)return false;
    $uid=(int)($user['id']??0);
    $role=music_workspace_resources_v330_member_role($pdo,$workspaceId,$uid);
    if($role==='owner'||$role==='manager')return music_workspace_resources_v330_can_manage($pdo,$workspaceId,'tracks',$user);
    if($role==='producer')return (int)($track['producer_user_id']??0)===$uid;
    // A direct production assignment remains a narrow legacy compatibility grant;
    // it never gives Team, catalog or unrelated track access.
    return (int)($track['producer_user_id']??0)===$uid;
}

function music_workspace_resources_v330_resolve_active(PDO $pdo,array $user,int $requestedWorkspaceId=0): ?array
{
    $uid=(int)($user['id']??0);
    if($uid<1)return null;
    if($requestedWorkspaceId>0&&music_workspace_resources_v330_can_access($pdo,$requestedWorkspaceId,$user)){
        $_SESSION['music_workspace_id']=$requestedWorkspaceId;
        return music_workspace_resources_v330_workspace($pdo,$requestedWorkspaceId);
    }
    $sessionId=max(0,(int)($_SESSION['music_workspace_id']??0));
    if($sessionId>0&&music_workspace_resources_v330_can_access($pdo,$sessionId,$user))return music_workspace_resources_v330_workspace($pdo,$sessionId);
    $owned=music_workspace_resources_v330_owner_workspace($pdo,$uid);
    if($owned&&music_workspace_resources_v330_can_access($pdo,(int)$owned['id'],$user)){
        $_SESSION['music_workspace_id']=(int)$owned['id'];
        return $owned;
    }
    if(table_exists('artist_team_members')){
        $stmt=$pdo->prepare('SELECT w.* FROM artist_team_members atm INNER JOIN users owner ON owner.id=atm.artist_user_id AND owner.is_active=1 INNER JOIN artist_workspaces_v181 w ON w.artist_user_id=atm.artist_user_id WHERE atm.member_user_id=? ORDER BY w.workspace_name,w.id LIMIT 1');
        $stmt->execute([$uid]);
        $row=$stmt->fetch();
        if($row){$_SESSION['music_workspace_id']=(int)$row['id'];return $row;}
    }
    return null;
}

function music_workspace_resources_v330_accessible_workspaces(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)return [];
    if(user_has_role('admin',$user))return $pdo->query('SELECT w.*,u.display_name owner_name FROM artist_workspaces_v181 w INNER JOIN users u ON u.id=w.artist_user_id ORDER BY w.workspace_name,w.id')->fetchAll()?:[];
    $stmt=$pdo->prepare("SELECT DISTINCT w.*,u.display_name owner_name,
        CASE WHEN w.artist_user_id=? THEN 'owner' ELSE atm.team_role END access_role
        FROM artist_workspaces_v181 w
        INNER JOIN users u ON u.id=w.artist_user_id AND u.is_active=1
        LEFT JOIN artist_team_members atm ON atm.artist_user_id=w.artist_user_id AND atm.member_user_id=?
        WHERE w.artist_user_id=? OR atm.member_user_id=?
        ORDER BY w.artist_user_id=? DESC,w.workspace_name,w.id");
    $stmt->execute([$uid,$uid,$uid,$uid,$uid]);
    return $stmt->fetchAll()?:[];
}

function music_workspace_resources_v330_catalog_track(PDO $pdo,int $workspaceId,int $catalogTrackId): ?array
{
    if($workspaceId<1||$catalogTrackId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM artist_catalog_tracks_v181 WHERE id=? AND workspace_id=? LIMIT 1');
    $stmt->execute([$catalogTrackId,$workspaceId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function music_workspace_resources_v330_ensure_source_album(PDO $pdo,int $workspaceId,int $catalogAlbumId): int
{
    if($workspaceId<1||$catalogAlbumId<1||!table_exists('albums'))return 0;
    $stmt=$pdo->prepare('SELECT * FROM artist_catalog_albums_v181 WHERE id=? AND workspace_id=? LIMIT 1');
    $stmt->execute([$catalogAlbumId,$workspaceId]);
    $album=$stmt->fetch();
    if(!$album)return 0;
    $sourceId=(int)($album['source_album_id']??0);
    if($sourceId>0){
        $check=$pdo->prepare('SELECT id FROM albums WHERE id=? AND (workspace_id=? OR workspace_id IS NULL) LIMIT 1');
        $check->execute([$sourceId,$workspaceId]);
        if($check->fetchColumn()){
            $pdo->prepare('UPDATE albums SET workspace_id=?,title=?,release_date=?,description=?,visibility=?,is_published=? WHERE id=?')->execute([$workspaceId,(string)$album['title'],$album['release_date']?:null,(string)$album['description'],(string)$album['visibility'],(int)$album['is_published'],$sourceId]);
            return $sourceId;
        }
    }
    $insert=$pdo->prepare('INSERT INTO albums (workspace_id,title,release_date,description,cover_path,visibility,sort_order,is_published) VALUES (?,?,?,?,?,?,?,?)');
    $insert->execute([$workspaceId,(string)$album['title'],$album['release_date']?:null,(string)$album['description'],(string)($album['cover_path']??''),(string)$album['visibility'],(int)($album['sort_order']??0),(int)$album['is_published']]);
    $sourceId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE artist_catalog_albums_v181 SET source_album_id=? WHERE id=? AND workspace_id=?')->execute([$sourceId,$catalogAlbumId,$workspaceId]);
    return $sourceId;
}

function music_workspace_resources_v330_ensure_production_track(PDO $pdo,int $workspaceId,int $catalogTrackId,array $user): array
{
    if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'tracks',$user)&&!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'production',$user))throw new RuntimeException('You do not have production access to this Music Workspace.');
    $catalog=music_workspace_resources_v330_catalog_track($pdo,$workspaceId,$catalogTrackId);
    if(!$catalog)throw new RuntimeException('Track is not available in this Music Workspace.');
    $ownerId=music_workspace_resources_v330_workspace_owner_id($pdo,$workspaceId);
    if($ownerId<1)throw new RuntimeException('Music Workspace owner is unavailable.');
    $sourceId=(int)($catalog['source_track_id']??0);
    $albumId=(int)($catalog['album_id']??0);
    $sourceAlbumId=$albumId>0?music_workspace_resources_v330_ensure_source_album($pdo,$workspaceId,$albumId):0;
    $durationSeconds=max(0,(int)($catalog['duration_seconds']??0));
    $duration=$durationSeconds>0?sprintf('%d:%02d',intdiv($durationSeconds,60),$durationSeconds%60):'';
    $producerId=music_workspace_resources_v330_member_role($pdo,$workspaceId,(int)($user['id']??0))==='producer'?(int)$user['id']:null;

    if($sourceId>0){
        $stmt=$pdo->prepare('SELECT * FROM tracks WHERE id=? AND workspace_id=? LIMIT 1');
        $stmt->execute([$sourceId,$workspaceId]);
        $track=$stmt->fetch();
        if($track){
            $sql='UPDATE tracks SET owner_user_id=?,title=?,album=?,album_id=?,duration=?,description=?,genre=?,audio_path=?,cover_path=?,visibility=?,is_published=?,updated_at=NOW()';
            $params=[$ownerId,(string)$catalog['title'],(string)($catalog['album']??''),$sourceAlbumId?:null,$duration,(string)($catalog['description']??''),(string)($catalog['genre']??''),(string)$catalog['audio_path'],(string)($catalog['cover_path']??''),(string)$catalog['visibility'],(int)$catalog['is_published']];
            if($producerId){$sql.=',producer_user_id=?';$params[]=$producerId;}
            $sql.=' WHERE id=? AND workspace_id=?';$params[]=$sourceId;$params[]=$workspaceId;
            $pdo->prepare($sql)->execute($params);
            $stmt->execute([$sourceId,$workspaceId]);
            return $stmt->fetch()?:$track;
        }
    }

    $stmt=$pdo->prepare('INSERT INTO tracks (workspace_id,owner_user_id,producer_user_id,album_id,title,album,duration,description,genre,audio_path,cover_path,visibility,is_published) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$workspaceId,$ownerId,$producerId,$sourceAlbumId?:null,(string)$catalog['title'],(string)($catalog['album']??''),$duration,(string)($catalog['description']??''),(string)($catalog['genre']??''),(string)$catalog['audio_path'],(string)($catalog['cover_path']??''),(string)$catalog['visibility'],(int)$catalog['is_published']]);
    $sourceId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE artist_catalog_tracks_v181 SET source_track_id=? WHERE id=? AND workspace_id=?')->execute([$sourceId,$catalogTrackId,$workspaceId]);
    $stmt=$pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');$stmt->execute([$sourceId]);
    return $stmt->fetch()?:['id'=>$sourceId,'workspace_id'=>$workspaceId,'owner_user_id'=>$ownerId];
}

function music_workspace_resources_v330_require_track(PDO $pdo,int $trackId,array $user,string $capability='production'): array
{
    if($trackId<1)throw new RuntimeException('Track is required.');
    $stmt=$pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');$stmt->execute([$trackId]);$track=$stmt->fetch();
    if(!$track)throw new RuntimeException('Track not found.');
    $workspaceId=music_workspace_resources_v330_track_workspace_id($pdo,$track);
    if($workspaceId<1||!music_workspace_resources_v330_can_access($pdo,$workspaceId,$user))throw new RuntimeException('Track is not available to this Music Workspace account.');
    if($capability==='production'&&!music_workspace_resources_v330_can_manage_track($pdo,$track,$user))throw new RuntimeException('This track has not been shared with your production role.');
    return $track;
}
