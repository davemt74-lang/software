<?php
declare(strict_types=1);

/** Runtime completion for v330 ownership: post-write graph sync + workspace releases. */
const VP3_MUSIC_WORKSPACE_RESOURCES_V331 = 'music-workspace-resources-v331-20260909';

function music_workspace_resources_v331_track_id_from_request(): int
{
    foreach(['track','track_id'] as $key){
        $value=max(0,(int)($_POST[$key]??$_GET[$key]??0));
        if($value>0)return $value;
    }
    return 0;
}

function music_workspace_resources_v331_sync_track_graph(PDO $pdo,int $trackId): void
{
    if($trackId<1||!table_exists('tracks')||!column_exists('tracks','workspace_id'))return;
    $stmt=$pdo->prepare('SELECT workspace_id FROM tracks WHERE id=? LIMIT 1');
    $stmt->execute([$trackId]);$workspaceId=(int)$stmt->fetchColumn();
    if($workspaceId<1)return;

    if(table_exists('track_projects')&&column_exists('track_projects','workspace_id')){
        $pdo->prepare('UPDATE track_projects SET workspace_id=? WHERE track_id=? AND (workspace_id IS NULL OR workspace_id<>?)')->execute([$workspaceId,$trackId,$workspaceId]);
    }
    if(table_exists('track_stems')&&column_exists('track_stems','workspace_id')){
        $pdo->prepare('UPDATE track_stems SET workspace_id=? WHERE track_id=? AND (workspace_id IS NULL OR workspace_id<>?)')->execute([$workspaceId,$trackId,$workspaceId]);
    }
    if(table_exists('track_studio_participants')&&column_exists('track_studio_participants','workspace_id')&&table_exists('track_projects')){
        $pdo->prepare('UPDATE track_studio_participants sp INNER JOIN track_projects p ON p.id=sp.project_id SET sp.workspace_id=? WHERE p.track_id=? AND (sp.workspace_id IS NULL OR sp.workspace_id<>?)')->execute([$workspaceId,$trackId,$workspaceId]);
    }
    if(table_exists('track_credits')&&column_exists('track_credits','workspace_id')){
        $pdo->prepare('UPDATE track_credits SET workspace_id=? WHERE track_id=? AND (workspace_id IS NULL OR workspace_id<>?)')->execute([$workspaceId,$trackId,$workspaceId]);
    }
}

function music_workspace_resources_v331_boot(): void
{
    static $registered=false;if($registered)return;$registered=true;
    register_shutdown_function(static function(): void {
        try{
            $trackId=music_workspace_resources_v331_track_id_from_request();
            if($trackId<1)return;
            $pdo=db();if(!$pdo)return;
            music_workspace_resources_v331_sync_track_graph($pdo,$trackId);
        }catch(Throwable $e){error_log('VP3 Music workspace graph sync failed: '.$e->getMessage());}
    });
}

/**
 * Prepare a catalog track for the mature Stem Studio without allowing a
 * Producer to self-assign an arbitrary workspace track by guessing its id.
 */
function music_workspace_resources_v331_prepare_studio_track(PDO $pdo,int $workspaceId,int $catalogTrackId,array $user): array
{
    if(!music_workspace_resources_v330_can_access($pdo,$workspaceId,$user))throw new RuntimeException('This Music Workspace is not available to your account.');
    $catalog=music_workspace_resources_v330_catalog_track($pdo,$workspaceId,$catalogTrackId);
    if(!$catalog)throw new RuntimeException('Track is not available in this Music Workspace.');

    $role=music_workspace_resources_v330_member_role($pdo,$workspaceId,(int)($user['id']??0));
    if($role==='producer'){
        $sourceId=(int)($catalog['source_track_id']??0);
        if($sourceId<1)throw new RuntimeException('This track has not been shared with your production role.');
        $stmt=$pdo->prepare('SELECT * FROM tracks WHERE id=? AND workspace_id=? AND producer_user_id=? LIMIT 1');
        $stmt->execute([$sourceId,$workspaceId,(int)$user['id']]);
        $track=$stmt->fetch();
        if(!$track||!music_workspace_resources_v330_can_manage_track($pdo,$track,$user))throw new RuntimeException('This track has not been shared with your production role.');
        return $track;
    }

    if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'tracks',$user))throw new RuntimeException('You do not have production access to this Music Workspace.');
    return music_workspace_resources_v330_ensure_production_track($pdo,$workspaceId,$catalogTrackId,$user);
}

function music_workspace_resources_v331_release_types(): array
{
    return function_exists('release_v105_release_types')?release_v105_release_types():['single'=>'Single','ep'=>'EP','album'=>'Album','video'=>'Video','show'=>'Show / Event','campaign'=>'Campaign','other'=>'Other'];
}
function music_workspace_resources_v331_release_statuses(): array
{
    return function_exists('release_v105_statuses')?release_v105_statuses():['planning'=>'Planning','active'=>'Active','scheduled'=>'Scheduled','released'=>'Released','paused'=>'Paused','cancelled'=>'Cancelled'];
}
function music_workspace_resources_v331_release_item_statuses(): array
{
    return function_exists('release_v105_item_statuses')?release_v105_item_statuses():['todo'=>'To Do','in_progress'=>'In Progress','blocked'=>'Blocked','waiting'=>'Waiting','scheduled'=>'Scheduled','complete'=>'Complete','cancelled'=>'Cancelled'];
}
function music_workspace_resources_v331_release_priorities(): array
{
    return ['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'];
}

function music_workspace_resources_v331_releases(PDO $pdo,int $workspaceId,bool $includeCancelled=false): array
{
    if($workspaceId<1||!table_exists('release_plans'))return [];
    $sql='SELECT r.*,(SELECT COUNT(*) FROM release_items i WHERE i.release_id=r.id AND i.workspace_id=r.workspace_id) item_count,(SELECT COUNT(*) FROM release_items i WHERE i.release_id=r.id AND i.workspace_id=r.workspace_id AND i.status=\'complete\') completed_count FROM release_plans r WHERE r.workspace_id=?';
    if(!$includeCancelled)$sql.=" AND r.status<>'cancelled'";
    $sql.=' ORDER BY CASE WHEN r.target_date IS NULL THEN 1 ELSE 0 END,r.target_date ASC,r.updated_at DESC,r.id DESC';
    $stmt=$pdo->prepare($sql);$stmt->execute([$workspaceId]);return $stmt->fetchAll()?:[];
}

function music_workspace_resources_v331_release(PDO $pdo,int $workspaceId,int $releaseId): ?array
{
    if($workspaceId<1||$releaseId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM release_plans WHERE id=? AND workspace_id=? LIMIT 1');$stmt->execute([$releaseId,$workspaceId]);$row=$stmt->fetch();return $row?:null;
}

function music_workspace_resources_v331_save_release(PDO $pdo,int $workspaceId,array $user,array $input): int
{
    if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'releases',$user))throw new RuntimeException('Release management is not available to your workspace role.');
    $id=max(0,(int)($input['id']??0));$title=trim((string)($input['title']??''));if($title==='')throw new RuntimeException('Release title is required.');if(mb_strlen($title)>190)throw new RuntimeException('Release title is too long.');
    $types=music_workspace_resources_v331_release_types();$statuses=music_workspace_resources_v331_release_statuses();$priorities=music_workspace_resources_v331_release_priorities();
    $type=(string)($input['release_type']??'single');if(!isset($types[$type]))$type='single';
    $status=(string)($input['status']??'planning');if(!isset($statuses[$status]))$status='planning';
    $priority=(string)($input['priority']??'normal');if(!isset($priorities[$priority]))$priority='normal';
    $target=trim((string)($input['target_date']??''));if($target!==''){$d=DateTime::createFromFormat('Y-m-d',$target);if(!$d||$d->format('Y-m-d')!==$target)throw new RuntimeException('Choose a valid release date.');$target.=' 12:00:00';}else $target=null;
    $goal=trim((string)($input['agent_goal']??''));$notes=trim((string)($input['notes']??''));$ownerId=music_workspace_resources_v330_workspace_owner_id($pdo,$workspaceId);if($ownerId<1)throw new RuntimeException('Workspace owner is unavailable.');
    if($id>0){
        if(!music_workspace_resources_v331_release($pdo,$workspaceId,$id))throw new RuntimeException('Release was not found in this Music Workspace.');
        $stmt=$pdo->prepare('UPDATE release_plans SET title=?,release_type=?,status=?,priority=?,target_date=?,agent_goal=?,notes=?,updated_at=NOW() WHERE id=? AND workspace_id=?');$stmt->execute([$title,$type,$status,$priority,$target,$goal,$notes,$id,$workspaceId]);return $id;
    }
    $stmt=$pdo->prepare('INSERT INTO release_plans (workspace_id,owner_user_id,created_by_user_id,title,release_type,status,priority,target_date,agent_goal,notes) VALUES (?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$workspaceId,$ownerId,(int)$user['id'],$title,$type,$status,$priority,$target,$goal,$notes]);return (int)$pdo->lastInsertId();
}

function music_workspace_resources_v331_release_items(PDO $pdo,int $workspaceId,int $releaseId): array
{
    if(!music_workspace_resources_v331_release($pdo,$workspaceId,$releaseId))return [];
    $stmt=$pdo->prepare('SELECT * FROM release_items WHERE release_id=? AND workspace_id=? ORDER BY sort_order,due_at,id');$stmt->execute([$releaseId,$workspaceId]);return $stmt->fetchAll()?:[];
}

function music_workspace_resources_v331_add_release_item(PDO $pdo,int $workspaceId,int $releaseId,array $user,array $input): int
{
    if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'releases',$user))throw new RuntimeException('Release management is not available to your workspace role.');
    if(!music_workspace_resources_v331_release($pdo,$workspaceId,$releaseId))throw new RuntimeException('Release was not found in this Music Workspace.');
    $title=trim((string)($input['title']??''));if($title==='')throw new RuntimeException('Task title is required.');if(mb_strlen($title)>190)throw new RuntimeException('Task title is too long.');
    $due=trim((string)($input['due_at']??''));if($due!==''){$d=DateTime::createFromFormat('Y-m-d',$due);if(!$d||$d->format('Y-m-d')!==$due)throw new RuntimeException('Choose a valid task date.');$due.=' 12:00:00';}else $due=null;
    $instructions=trim((string)($input['instructions']??''));$sort=max(0,(int)($input['sort_order']??0));
    $stmt=$pdo->prepare("INSERT INTO release_items (workspace_id,release_id,item_type,title,status,due_at,instructions,sort_order) VALUES (?,?,'task',?,'todo',?,?,?)");$stmt->execute([$workspaceId,$releaseId,$title,$due,$instructions,$sort]);return (int)$pdo->lastInsertId();
}

function music_workspace_resources_v331_set_release_item_status(PDO $pdo,int $workspaceId,int $releaseId,int $itemId,array $user,string $status): void
{
    if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'releases',$user))throw new RuntimeException('Release management is not available to your workspace role.');
    $allowed=music_workspace_resources_v331_release_item_statuses();if(!isset($allowed[$status]))throw new RuntimeException('Choose a valid task status.');
    $stmt=$pdo->prepare("UPDATE release_items SET status=?,completed_at=CASE WHEN ?='complete' THEN COALESCE(completed_at,NOW()) ELSE NULL END,updated_at=NOW() WHERE id=? AND release_id=? AND workspace_id=?");$stmt->execute([$status,$status,$itemId,$releaseId,$workspaceId]);if($stmt->rowCount()<1){$check=$pdo->prepare('SELECT 1 FROM release_items WHERE id=? AND release_id=? AND workspace_id=? LIMIT 1');$check->execute([$itemId,$releaseId,$workspaceId]);if(!$check->fetchColumn())throw new RuntimeException('Release task was not found in this Music Workspace.');}
}

function music_workspace_resources_v331_delete_release(PDO $pdo,int $workspaceId,int $releaseId,array $user): void
{
    if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'releases',$user))throw new RuntimeException('Release management is not available to your workspace role.');
    $stmt=$pdo->prepare('DELETE FROM release_plans WHERE id=? AND workspace_id=?');$stmt->execute([$releaseId,$workspaceId]);if($stmt->rowCount()<1)throw new RuntimeException('Release was not found in this Music Workspace.');
}

music_workspace_resources_v331_boot();
