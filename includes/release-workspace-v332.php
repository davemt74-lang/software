<?php
declare(strict_types=1);

/**
 * Workspace-safe compatibility layer for the historical v105 release runtime.
 *
 * New UI lives in music-releases.php. These helpers exist so Agent/proactive and
 * stale legacy entry points resolve the same active Music Workspace instead of
 * choosing an arbitrary Artist relationship by owner_user_id.
 */
const VP3_RELEASE_WORKSPACE_V332='release-workspace-v332-20260909';

function release_workspace_v332_context(array $user,int $releaseId=0,int $requestedWorkspaceId=0): ?array
{
    $pdo=db();$uid=(int)($user['id']??0);
    if(!$pdo||$uid<1||!release_v105_schema_ready()||!function_exists('music_workspace_resources_v330_schema_ready')||!music_workspace_resources_v330_schema_ready($pdo))return null;

    if($releaseId>0&&column_exists('release_plans','workspace_id')){
        $stmt=$pdo->prepare('SELECT workspace_id FROM release_plans WHERE id=? LIMIT 1');
        $stmt->execute([$releaseId]);$releaseWorkspaceId=(int)$stmt->fetchColumn();
        if($releaseWorkspaceId>0){
            if(!music_workspace_resources_v330_can_access($pdo,$releaseWorkspaceId,$user))return null;
            $_SESSION['music_workspace_id']=$releaseWorkspaceId;
            $workspace=music_workspace_resources_v330_workspace($pdo,$releaseWorkspaceId);
            if($workspace)return $workspace;
        }
    }

    if($requestedWorkspaceId<1){
        $requestedWorkspaceId=max(0,(int)($_POST['workspace_id']??$_GET['workspace']??$_GET['workspace_id']??0));
    }
    return music_workspace_resources_v330_resolve_active($pdo,$user,$requestedWorkspaceId);
}

function release_workspace_v332_workspace_id(array $user,int $releaseId=0): int
{
    $workspace=release_workspace_v332_context($user,$releaseId);
    return (int)($workspace['id']??0);
}

function release_workspace_v332_owner_id(array $user,int $releaseId=0): int
{
    $pdo=db();$workspaceId=release_workspace_v332_workspace_id($user,$releaseId);
    return $pdo&&$workspaceId>0?music_workspace_resources_v330_workspace_owner_id($pdo,$workspaceId):0;
}

function release_workspace_v332_can_manage(array $user,int $releaseId=0): bool
{
    $pdo=db();$workspaceId=release_workspace_v332_workspace_id($user,$releaseId);
    return (bool)($pdo&&$workspaceId>0&&music_workspace_resources_v330_can_manage($pdo,$workspaceId,'releases',$user));
}

function release_workspace_v332_route(array $user,int $releaseId=0): string
{
    $workspaceId=release_workspace_v332_workspace_id($user,$releaseId);
    $query=$workspaceId>0?'?workspace='.$workspaceId:'';
    if($releaseId>0)$query.=($query===''?'?':'&').'release='.$releaseId;
    return url('/music-releases.php'.$query);
}

function release_workspace_v332_plans(array $user,int $limit=100): array
{
    $pdo=db();$workspaceId=release_workspace_v332_workspace_id($user);
    if(!$pdo||$workspaceId<1)return [];
    $stmt=$pdo->prepare(
        'SELECT rp.*,
                (SELECT COUNT(*) FROM release_items ri WHERE ri.release_id=rp.id AND ri.workspace_id=rp.workspace_id) AS item_count,
                (SELECT COUNT(*) FROM release_items ri WHERE ri.release_id=rp.id AND ri.workspace_id=rp.workspace_id AND ri.status="complete") AS complete_count,
                (SELECT MIN(ri.due_at) FROM release_items ri WHERE ri.release_id=rp.id AND ri.workspace_id=rp.workspace_id AND ri.status NOT IN ("complete","cancelled") AND ri.due_at IS NOT NULL) AS next_due_at
         FROM release_plans rp
         WHERE rp.workspace_id=?
         ORDER BY COALESCE(rp.target_date,"2999-12-31") ASC,rp.updated_at DESC,rp.id DESC
         LIMIT '.max(1,min(250,$limit))
    );
    $stmt->execute([$workspaceId]);return $stmt->fetchAll()?:[];
}

function release_workspace_v332_plan(array $user,int $releaseId): ?array
{
    $pdo=db();$workspaceId=release_workspace_v332_workspace_id($user,$releaseId);
    if(!$pdo||$workspaceId<1||$releaseId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM release_plans WHERE id=? AND workspace_id=? LIMIT 1');
    $stmt->execute([$releaseId,$workspaceId]);$row=$stmt->fetch();return $row?:null;
}

function release_workspace_v332_items(array $user,int $releaseId): array
{
    $pdo=db();$plan=release_workspace_v332_plan($user,$releaseId);
    if(!$pdo||!$plan)return [];$workspaceId=(int)$plan['workspace_id'];
    $stmt=$pdo->prepare(
        'SELECT ri.*,u.display_name AS assignee_name,t.title AS track_title,s.venue AS show_venue,s.show_date,
                (SELECT COUNT(*) FROM release_item_resources rr WHERE rr.release_item_id=ri.id) AS resource_count,
                (SELECT COUNT(*) FROM agent_work_actions wa WHERE wa.release_item_id=ri.id AND wa.workspace_id=ri.workspace_id AND wa.status NOT IN ("complete","cancelled")) AS pending_action_count
         FROM release_items ri
         LEFT JOIN users u ON u.id=ri.assigned_user_id
         LEFT JOIN tracks t ON t.id=ri.track_id AND t.workspace_id=ri.workspace_id
         LEFT JOIN shows s ON s.id=ri.show_id AND s.workspace_id=ri.workspace_id
         WHERE ri.release_id=? AND ri.workspace_id=?
         ORDER BY COALESCE(ri.due_at,"2999-12-31") ASC,ri.sort_order ASC,ri.id ASC'
    );
    $stmt->execute([$releaseId,$workspaceId]);return $stmt->fetchAll()?:[];
}

function release_workspace_v332_owner_resources(array $user,int $limit=200): array
{
    $pdo=db();$workspace=release_workspace_v332_context($user);if(!$pdo||!$workspace)return [];
    $ownerId=(int)($workspace['artist_user_id']??0);$uid=(int)($user['id']??0);
    if(!user_has_role('admin',$user)&&$uid!==$ownerId)return [];
    $stmt=$pdo->prepare('SELECT * FROM agent_resources WHERE owner_user_id=? AND is_active=1 ORDER BY updated_at DESC,id DESC LIMIT '.max(1,min(500,$limit)));
    $stmt->execute([$ownerId]);return $stmt->fetchAll()?:[];
}

function release_workspace_v332_owner_integrations(array $user): array
{
    $pdo=db();$workspace=release_workspace_v332_context($user);if(!$pdo||!$workspace)return [];
    $ownerId=(int)($workspace['artist_user_id']??0);$uid=(int)($user['id']??0);
    if(!user_has_role('admin',$user)&&$uid!==$ownerId)return [];
    $stmt=$pdo->prepare('SELECT provider_key,connection_key,label,status,capabilities_json,metadata_json,last_sync_at,updated_at FROM agent_integrations WHERE owner_user_id=? ORDER BY status="connected" DESC,provider_key,label');
    $stmt->execute([$ownerId]);return $stmt->fetchAll()?:[];
}

function release_workspace_v332_enqueue_action(array $user,string $providerKey,string $actionType,array $input=[],?int $releaseId=null,?int $itemId=null,bool $requiresApproval=true,?string $scheduledFor=null,string $sourceKind='agent'): int
{
    $pdo=db();$workspace=release_workspace_v332_context($user,(int)($releaseId??0));
    if(!$pdo||!$workspace)return 0;$workspaceId=(int)$workspace['id'];$ownerId=(int)$workspace['artist_user_id'];$uid=(int)($user['id']??0);
    if(!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'releases',$user))throw new RuntimeException('Release management is not available to your workspace role.');
    // Provider credentials/resources are still owner-scoped in v105. Until they
    // become workspace resources, collaborators may plan releases but cannot
    // trigger the owner's external integrations.
    if(!user_has_role('admin',$user)&&$uid!==$ownerId)throw new RuntimeException('Only the Music Workspace owner can queue external provider actions.');
    if($releaseId&& !release_workspace_v332_plan($user,(int)$releaseId))throw new RuntimeException('Release plan is not available to this workspace.');
    if($itemId){
        $stmt=$pdo->prepare('SELECT 1 FROM release_items WHERE id=? AND release_id=? AND workspace_id=? LIMIT 1');
        $stmt->execute([(int)$itemId,(int)$releaseId,$workspaceId]);if(!$stmt->fetchColumn())throw new RuntimeException('Release work item is not available to this workspace.');
    }
    $stmt=$pdo->prepare('INSERT INTO agent_work_actions (workspace_id,owner_user_id,release_id,release_item_id,provider_key,action_type,status,source_kind,requires_approval,scheduled_for,input_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$workspaceId,$ownerId,$releaseId?:null,$itemId?:null,mb_substr(trim($providerKey),0,80),mb_substr(trim($actionType),0,80),$requiresApproval?'draft':'queued',mb_substr($sourceKind,0,30),$requiresApproval?1:0,$scheduledFor,json_encode($input,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    return (int)$pdo->lastInsertId();
}

function release_workspace_v332_agent_context(array $user,int $limit=16): array
{
    if(!release_workspace_v332_can_manage($user))return [];
    $plans=release_workspace_v332_plans($user,30);if(!$plans)return [];
    $context=[];$now=time();
    foreach(array_slice($plans,0,max(1,min(12,$limit))) as $plan){
        $releaseId=(int)$plan['id'];$target=trim((string)($plan['target_date']??''));$lines=[
            'Release #'.$releaseId.' · '.(string)$plan['title'],
            'Type: '.(string)$plan['release_type'].' · status: '.(string)$plan['status'].' · priority: '.(string)$plan['priority'],
            'Target: '.($target!==''?$target:'no target date').' · progress: '.(int)$plan['complete_count'].'/'.(int)$plan['item_count'].' items complete',
        ];
        if(trim((string)($plan['agent_goal']??''))!=='')$lines[]='Agent goal: '.trim((string)$plan['agent_goal']);
        if(trim((string)($plan['notes']??''))!=='')$lines[]='Notes: '.trim((string)$plan['notes']);
        foreach(array_slice(release_workspace_v332_items($user,$releaseId),0,12) as $item){
            $due=trim((string)($item['due_at']??''));$urgency='';
            if($due!==''&&!in_array((string)$item['status'],['complete','cancelled'],true)){$delta=strtotime($due)-$now;if($delta<0)$urgency=' · OVERDUE';elseif($delta<=3*86400)$urgency=' · due soon';}
            $lines[]='- '.strtoupper((string)$item['status']).' · '.(string)$item['item_type'].' · '.(string)$item['title'].($due!==''?' · due '.$due:'').$urgency;
        }
        $context[]=['source'=>'agent-brain:release:'.$releaseId,'title'=>'Release operations · '.(string)$plan['title'],'text'=>implode("\n",$lines)];
    }
    $summary=[];
    foreach(release_workspace_v332_owner_integrations($user) as $integration)$summary[]='Integration '.(string)$integration['provider_key'].' · '.(string)$integration['status'].' · '.((string)$integration['label']?: (string)$integration['connection_key']);
    foreach(array_slice(release_workspace_v332_owner_resources($user,40),0,20) as $resource)$summary[]='Resource #'.(int)$resource['id'].' · '.(string)$resource['resource_type'].' · '.(string)$resource['title'].((string)($resource['provider_key']??'')!==''?' · provider '.(string)$resource['provider_key']:'');
    if($summary)$context[]=['source'=>'agent-brain:operations-resources','title'=>'Agent Operations resources and connected capabilities','text'=>implode("\n",$summary)];
    return $context;
}
