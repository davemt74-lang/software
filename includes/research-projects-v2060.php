<?php
declare(strict_types=1);

require_once __DIR__.'/browser-source-feed-v2050.php';

const VP3_RESEARCH_PROJECTS_V2060='research-projects-v2060-20260918';
const VP3_RESEARCH_REPORT_SCHEMA_V2060=1;
const VP3_RESEARCH_TITLE_MAX_V2060=190;
const VP3_RESEARCH_BODY_MAX_V2060=50000;

function vp3_research_schema_ready_v2060(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && vp3_browser_source_feed_schema_ready_v2050($pdo)
        && table_exists('research_projects_v2060')
        && table_exists('research_project_members_v2060')
        && table_exists('research_project_items_v2060')
        && table_exists('research_project_events_v2060')
        && table_exists('research_findings_v2060')
        && table_exists('research_finding_evidence_v2060')
        && table_exists('research_reports_v2060')
        && table_exists('research_report_items_v2060')
        && table_exists('research_report_versions_v2060')
        && table_exists('research_report_version_sources_v2060');
}

function vp3_research_require_ready_v2060(?PDO $pdo=null): PDO
{
    $pdo??=db();
    if(!$pdo||!vp3_research_schema_ready_v2060($pdo))throw new RuntimeException('Research Projects are not ready. Run the current database upgrade.');
    return $pdo;
}

function vp3_research_uuid_v2060(): string
{
    return vp3_extension_uuid_v2000();
}

function vp3_research_role_rank_v2060(string $role): int
{
    return match($role){'owner'=>40,'admin'=>30,'researcher'=>20,'viewer'=>10,default=>0};
}

function vp3_research_role_at_least_v2060(string $role,string $minimum): bool
{
    return vp3_research_role_rank_v2060($role)>=vp3_research_role_rank_v2060($minimum);
}

function vp3_research_clean_title_v2060(string $value,string $label='Title'): string
{
    $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
    if($value===''||mb_strlen($value)>VP3_RESEARCH_TITLE_MAX_V2060)throw new InvalidArgumentException($label.' must contain 1 to '.VP3_RESEARCH_TITLE_MAX_V2060.' characters.');
    return $value;
}

function vp3_research_clean_body_v2060(string $value,int $max=VP3_RESEARCH_BODY_MAX_V2060): string
{
    $value=trim(str_replace("\0",'',$value));
    if(strlen($value)>$max)throw new InvalidArgumentException('Research text is too large.');
    return $value;
}

function vp3_research_tags_v2060(mixed $raw): array
{
    if(is_string($raw)){
        $decoded=json_decode($raw,true);
        $raw=is_array($decoded)?$decoded:preg_split('/[,\n]+/u',$raw);
    }
    if(!is_array($raw))return [];
    $out=[];
    foreach($raw as $tag){
        $tag=trim(preg_replace('/\s+/u',' ',(string)$tag)??'');
        if($tag===''||mb_strlen($tag)>40)continue;
        $key=mb_strtolower($tag);
        if(isset($out[$key]))continue;
        $out[$key]=$tag;
        if(count($out)>=20)break;
    }
    return array_values($out);
}

function vp3_research_tags_json_v2060(mixed $raw): string
{
    return json_encode(vp3_research_tags_v2060($raw),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'[]';
}

function vp3_research_event_v2060(PDO $pdo,int $projectId,int $actorUserId,string $eventType,string $objectType='',string $objectPublicId='',array $metadata=[]): void
{
    if($projectId<1||$actorUserId<1)return;
    $safe=[];
    foreach(['from','to','role','visibility','team_id','version','item_type'] as $key){
        if(array_key_exists($key,$metadata))$safe[$key]=$metadata[$key];
    }
    $encoded=json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}';
    $stmt=$pdo->prepare("INSERT INTO research_project_events_v2060(project_id,actor_user_id,event_type,object_type,object_public_id,metadata_json,created_at)
      VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())");
    $stmt->execute([$projectId,$actorUserId,mb_substr($eventType,0,80),mb_substr($objectType,0,40),mb_substr($objectPublicId,0,64),$encoded]);
}

function vp3_research_project_row_v2060(PDO $pdo,string $publicId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM research_projects_v2060 WHERE public_id=? LIMIT 1');
    $stmt->execute([trim($publicId)]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_research_project_row_by_id_v2060(PDO $pdo,int $projectId): ?array
{
    if($projectId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM research_projects_v2060 WHERE id=? LIMIT 1');
    $stmt->execute([$projectId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_research_project_role_v2060(PDO $pdo,array $project,int $userId): string
{
    if($userId<1||!empty($project['deleted_at']))return '';
    $owner=(int)($project['owner_user_id']??0);
    $team=(int)($project['team_owner_user_id']??0);
    if($owner===$userId||($team>0&&$team===$userId))return 'owner';

    $best='';
    $stmt=$pdo->prepare('SELECT member_role FROM research_project_members_v2060 WHERE project_id=? AND user_id=? LIMIT 1');
    $stmt->execute([(int)$project['id'],$userId]);
    $explicit=(string)($stmt->fetchColumn()?:'');
    if(vp3_research_role_rank_v2060($explicit)>0)$best=$explicit;

    if($team>0&&vp3_human_team_authorized_v370($pdo,$team,$userId)&&vp3_research_role_rank_v2060($best)<vp3_research_role_rank_v2060('viewer'))$best='viewer';
    return $best;
}

function vp3_research_project_require_v2060(PDO $pdo,string $publicId,int $userId,string $minimum='viewer'): array
{
    $project=vp3_research_project_row_v2060($pdo,$publicId);
    if(!$project)throw new RuntimeException('Research project was not found.');
    $role=vp3_research_project_role_v2060($pdo,$project,$userId);
    if(!vp3_research_role_at_least_v2060($role,$minimum))throw new RuntimeException('You do not have access to this Research project.');
    $project['_role']=$role;
    return $project;
}

function vp3_research_project_public_v2060(PDO $pdo,array $project,int $userId): array
{
    $role=vp3_research_project_role_v2060($pdo,$project,$userId);
    $counts=['annotations'=>0,'sources'=>0,'findings'=>0,'reports'=>0];
    foreach([
        'annotations'=>["SELECT COUNT(*) FROM research_project_items_v2060 WHERE project_id=? AND item_type='annotation' AND item_status='active'",(int)$project['id']],
        'sources'=>["SELECT COUNT(*) FROM research_project_items_v2060 WHERE project_id=? AND item_type='source' AND item_status='active'",(int)$project['id']],
        'findings'=>["SELECT COUNT(*) FROM research_findings_v2060 WHERE project_id=? AND deleted_at IS NULL",(int)$project['id']],
        'reports'=>["SELECT COUNT(*) FROM research_reports_v2060 WHERE project_id=? AND deleted_at IS NULL",(int)$project['id']],
    ] as $key=>[$sql,$id]){
        $stmt=$pdo->prepare($sql);$stmt->execute([$id]);$counts[$key]=(int)$stmt->fetchColumn();
    }
    return [
        'id'=>(string)$project['public_id'],
        'title'=>(string)$project['title'],
        'description'=>(string)$project['description'],
        'status'=>(string)$project['project_status'],
        'owner_user_id'=>(int)$project['owner_user_id'],
        'team_id'=>(int)($project['team_owner_user_id']??0),
        'role'=>$role,
        'counts'=>$counts,
        'created_at'=>(string)$project['created_at'],
        'updated_at'=>(string)$project['updated_at'],
        'url'=>url('/research-project.php?project='.rawurlencode((string)$project['public_id'])),
    ];
}

function vp3_research_projects_for_user_v2060(PDO $pdo,int $userId,bool $includeArchived=false): array
{
    if($userId<1)return [];
    $where=$includeArchived?'1=1':"p.project_status='active'";
    $teamClause=table_exists('artist_team_members')?" OR p.team_owner_user_id IN (SELECT artist_user_id FROM artist_team_members WHERE member_user_id=?)":'';
    $sql="SELECT DISTINCT p.* FROM research_projects_v2060 p
      LEFT JOIN research_project_members_v2060 m ON m.project_id=p.id AND m.user_id=?
      WHERE p.deleted_at IS NULL AND {$where} AND (p.owner_user_id=? OR p.team_owner_user_id=? OR m.user_id IS NOT NULL{$teamClause})
      ORDER BY p.updated_at DESC,p.id DESC";
    $params=[$userId,$userId,$userId];
    if($teamClause!=='')$params[]=$userId;
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    $out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        if(vp3_research_project_role_v2060($pdo,$row,$userId)==='')continue;
        $out[]=vp3_research_project_public_v2060($pdo,$row,$userId);
    }
    return $out;
}

function vp3_research_create_project_v2060(PDO $pdo,int $actorUserId,string $title,string $description='',int $teamOwnerUserId=0): array
{
    if($actorUserId<1)throw new RuntimeException('Sign in to create a Research project.');
    $title=vp3_research_clean_title_v2060($title,'Project title');
    $description=vp3_research_clean_body_v2060($description,10000);
    $owner=$actorUserId;
    if($teamOwnerUserId>0){
        if(!vp3_human_team_authorized_v370($pdo,$teamOwnerUserId,$actorUserId))throw new RuntimeException('Choose a Team workspace you can access.');
        $owner=$teamOwnerUserId;
    }
    $publicId=vp3_research_uuid_v2060();
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("INSERT INTO research_projects_v2060(public_id,owner_user_id,team_owner_user_id,title,description,project_status,created_at,updated_at)
          VALUES(?,?,?,?,?,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $stmt->execute([$publicId,$owner,$teamOwnerUserId>0?$teamOwnerUserId:null,$title,$description]);
        $projectId=(int)$pdo->lastInsertId();
        if($actorUserId!==$owner){
            $pdo->prepare("INSERT INTO research_project_members_v2060(project_id,user_id,member_role,added_by_user_id,created_at,updated_at)
              VALUES(?,?,'admin',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$projectId,$actorUserId,$actorUserId]);
        }
        vp3_research_event_v2060($pdo,$projectId,$actorUserId,'project.created','project',$publicId,['team_id'=>$teamOwnerUserId]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=vp3_research_project_row_v2060($pdo,$publicId);
    if(!$row)throw new RuntimeException('Research project could not be reloaded.');
    return vp3_research_project_public_v2060($pdo,$row,$actorUserId);
}

function vp3_research_update_project_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,array $input): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'admin');
    $title=array_key_exists('title',$input)?vp3_research_clean_title_v2060((string)$input['title'],'Project title'):(string)$project['title'];
    $description=array_key_exists('description',$input)?vp3_research_clean_body_v2060((string)$input['description'],10000):(string)$project['description'];
    $status=(string)($input['status']??$project['project_status']);
    if(!in_array($status,['active','archived'],true))throw new InvalidArgumentException('Project status is invalid.');
    $pdo->prepare('UPDATE research_projects_v2060 SET title=?,description=?,project_status=?,updated_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([$title,$description,$status,(int)$project['id']]);
    vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'project.updated','project',(string)$project['public_id'],['to'=>$status]);
    return vp3_research_project_public_v2060($pdo,vp3_research_project_row_by_id_v2060($pdo,(int)$project['id'])??$project,$actorUserId);
}

function vp3_research_set_member_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,int $memberUserId,string $role): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'admin');
    if($memberUserId<1||(int)$project['owner_user_id']===$memberUserId)throw new InvalidArgumentException('Choose a non-owner VP3 member.');
    if(!in_array($role,['admin','researcher','viewer'],true))throw new InvalidArgumentException('Project member role is invalid.');
    $stmt=$pdo->prepare('SELECT 1 FROM users WHERE id=? AND is_active=1 LIMIT 1');$stmt->execute([$memberUserId]);
    if(!$stmt->fetchColumn())throw new RuntimeException('VP3 member was not found.');
    $pdo->prepare("INSERT INTO research_project_members_v2060(project_id,user_id,member_role,added_by_user_id,created_at,updated_at)
      VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE member_role=VALUES(member_role),added_by_user_id=VALUES(added_by_user_id),updated_at=UTC_TIMESTAMP()")
      ->execute([(int)$project['id'],$memberUserId,$role,$actorUserId]);
    vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'member.updated','user',(string)$memberUserId,['role'=>$role]);
    return ['user_id'=>$memberUserId,'role'=>$role];
}

function vp3_research_remove_member_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,int $memberUserId): void
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'admin');
    if($memberUserId<1||(int)$project['owner_user_id']===$memberUserId)throw new InvalidArgumentException('Project owner cannot be removed.');
    $pdo->prepare('DELETE FROM research_project_members_v2060 WHERE project_id=? AND user_id=?')->execute([(int)$project['id'],$memberUserId]);
    vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'member.removed','user',(string)$memberUserId);
}

function vp3_research_members_v2060(PDO $pdo,array $project): array
{
    $stmt=$pdo->prepare("SELECT m.user_id,m.member_role,u.display_name,m.created_at,m.updated_at
      FROM research_project_members_v2060 m INNER JOIN users u ON u.id=m.user_id
      WHERE m.project_id=? ORDER BY FIELD(m.member_role,'admin','researcher','viewer'),u.display_name ASC");
    $stmt->execute([(int)$project['id']]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $ownerName='Owner';
    $ownerStmt=$pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');
    $ownerStmt->execute([(int)$project['owner_user_id']]);
    $ownerName=trim((string)($ownerStmt->fetchColumn()?:'Owner'))?:'Owner';
    array_unshift($rows,['user_id'=>(int)$project['owner_user_id'],'member_role'=>'owner','display_name'=>$ownerName,'created_at'=>(string)$project['created_at'],'updated_at'=>(string)$project['updated_at']]);
    return array_map(static fn(array $r): array=>[
        'user_id'=>(int)$r['user_id'],'role'=>(string)$r['member_role'],'name'=>(string)$r['display_name'],
        'created_at'=>(string)$r['created_at'],'updated_at'=>(string)$r['updated_at'],
    ],$rows);
}

function vp3_research_insert_item_v2060(PDO $pdo,array $project,int $actorUserId,string $type,?int $shareId,int $sourceId,int $sourceVersionId,string $note='',array $tags=[]): array
{
    if(!in_array($type,['annotation','source'],true)||$sourceId<1)throw new InvalidArgumentException('Research item is invalid.');
    $itemKey=$type==='annotation'?'annotation:'.(int)$shareId:'source:'.$sourceId;
    if($type==='annotation'&&($shareId??0)<1)throw new InvalidArgumentException('Annotation item is invalid.');
    $publicId=vp3_research_uuid_v2060();
    $note=vp3_research_clean_body_v2060($note,10000);
    $tagsJson=vp3_research_tags_json_v2060($tags);
    $stmt=$pdo->prepare("INSERT INTO research_project_items_v2060(public_id,project_id,item_key,item_type,browser_share_id,source_id,source_version_id,added_by_user_id,note,tags_json,item_status,created_at,updated_at)
      VALUES(?,?,?,?,?,?,?,?,?,?,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE source_version_id=VALUES(source_version_id),item_status='active',updated_at=UTC_TIMESTAMP()");
    $stmt->execute([$publicId,(int)$project['id'],$itemKey,$type,$shareId,$sourceId,$sourceVersionId>0?$sourceVersionId:null,$actorUserId,$note,$tagsJson]);
    $find=$pdo->prepare('SELECT * FROM research_project_items_v2060 WHERE project_id=? AND item_key=? LIMIT 1');
    $find->execute([(int)$project['id'],$itemKey]);
    $row=$find->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Research item could not be reloaded.');
    return $row;
}

function vp3_research_assign_share_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,string $browserSharePublicId,string $note='',mixed $tags=[]): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'researcher');
    $share=vp3_browser_source_authorized_row_v2050($pdo,$actorUserId,$browserSharePublicId);
    if((int)($share['source_id']??0)<1){
        vp3_browser_source_register_share_v2050($pdo,$share,'','legacy_capture');
        $share=vp3_browser_source_share_row_v2050($pdo,$browserSharePublicId)??$share;
    }
    $sourceId=(int)($share['source_id']??0);$versionId=(int)($share['source_version_id']??0);
    if($sourceId<1||$versionId<1)throw new RuntimeException('Source identity is unavailable for this annotation.');
    $tagsArray=vp3_research_tags_v2060($tags);
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $annotation=vp3_research_insert_item_v2060($pdo,$project,$actorUserId,'annotation',(int)$share['id'],$sourceId,$versionId,$note,$tagsArray);
        vp3_research_insert_item_v2060($pdo,$project,$actorUserId,'source',null,$sourceId,$versionId,'',$tagsArray);
        $pdo->prepare('DELETE FROM browser_research_queue_v2050 WHERE user_id=? AND browser_share_id=?')->execute([$actorUserId,(int)$share['id']]);
        vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'item.added','annotation',(string)$share['public_id'],['item_type'=>'annotation']);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return vp3_research_item_public_v2060($pdo,$annotation,$actorUserId);
}

function vp3_research_share_authorized_v2060(PDO $pdo,string $browserSharePublicId,int $viewerUserId): bool
{
    if($viewerUserId<1||trim($browserSharePublicId)==='')return false;
    $stmt=$pdo->prepare("SELECT DISTINCT p.id,p.owner_user_id,p.team_owner_user_id,p.deleted_at
      FROM browser_shares_v2010 s
      INNER JOIN research_project_items_v2060 i ON i.browser_share_id=s.id AND i.item_type='annotation' AND i.item_status='active'
      INNER JOIN research_projects_v2060 p ON p.id=i.project_id AND p.deleted_at IS NULL
      WHERE s.public_id=? AND s.deleted_at IS NULL");
    $stmt->execute([trim($browserSharePublicId)]);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $project){
        if(vp3_research_role_at_least_v2060(vp3_research_project_role_v2060($pdo,$project,$viewerUserId),'viewer'))return true;
    }
    return false;
}

function vp3_research_item_public_v2060(PDO $pdo,array $row,int $viewerUserId): array
{
    $source=vp3_browser_source_row_by_public_id_v2050($pdo,(string)($row['source_public_id']??'')) ?: null;
    if(!$source){
        $stmt=$pdo->prepare('SELECT * FROM browser_sources_v2050 WHERE id=? LIMIT 1');$stmt->execute([(int)$row['source_id']);$source=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
    }
    $version=[];
    if((int)($row['source_version_id']??0)>0){
        $stmt=$pdo->prepare('SELECT public_id,content_hash,version_basis,canonical_url,source_title,captured_at FROM browser_source_versions_v2050 WHERE id=? LIMIT 1');
        $stmt->execute([(int)$row['source_version_id']]);$version=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
    }
    $annotation=null;
    if((string)$row['item_type']==='annotation'&&(int)($row['browser_share_id']??0)>0){
        $share=vp3_browser_source_share_row_by_id_v2050($pdo,(int)$row['browser_share_id']);
        if($share){
            $phase6Authorized=vp3_browser_source_share_authorized_v2050($pdo,$share,$viewerUserId);
            $projectAuthorized=vp3_research_share_authorized_v2060($pdo,(string)$share['public_id'],$viewerUserId);
            if($phase6Authorized||$projectAuthorized)$annotation=vp3_browser_source_item_v2050($pdo,$share,$viewerUserId,false,$projectAuthorized&&!$phase6Authorized);
        }
    }
    return [
        'id'=>(string)$row['public_id'],'type'=>(string)$row['item_type'],'status'=>(string)$row['item_status'],
        'note'=>(string)$row['note'],'tags'=>json_decode((string)$row['tags_json'],true)?:[],
        'source'=>[
            'id'=>(string)($source['public_id']??''),'url'=>(string)($source['canonical_url']??$source['normalized_url']??''),
            'title'=>(string)($version['source_title']??$source['source_title']??''),'domain'=>(string)($source['source_domain']??''),
            'version'=>[
                'id'=>(string)($version['public_id']??''),'hash'=>(string)($version['content_hash']??''),
                'basis'=>(string)($version['version_basis']??''),'captured_at'=>(string)($version['captured_at']??''),
            ],
        ],
        'annotation'=>$annotation,
        'added_by'=>(int)$row['added_by_user_id'],'created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at'],
    ];
}

function vp3_research_project_items_v2060(PDO $pdo,array $project,int $viewerUserId,string $type=''): array
{
    $params=[(int)$project['id']];
    $where="project_id=? AND item_status='active'";
    if(in_array($type,['annotation','source'],true)){$where.=' AND item_type=?';$params[]=$type;}
    $stmt=$pdo->prepare("SELECT * FROM research_project_items_v2060 WHERE {$where} ORDER BY created_at DESC,id DESC");
    $stmt->execute($params);
    return array_map(fn(array $r): array=>vp3_research_item_public_v2060($pdo,$r,$viewerUserId),$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_research_inbox_v2060(PDO $pdo,int $userId,int $limit=100): array
{
    if($userId<1)return [];
    $limit=max(1,min(200,$limit));
    $stmt=$pdo->prepare("SELECT q.browser_share_id FROM browser_research_queue_v2050 q
      INNER JOIN browser_shares_v2010 s ON s.id=q.browser_share_id AND s.deleted_at IS NULL
      WHERE q.user_id=? ORDER BY q.created_at DESC,q.browser_share_id DESC LIMIT {$limit}");
    $stmt->execute([$userId]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $shareId){
        $row=vp3_browser_source_share_row_by_id_v2050($pdo,(int)$shareId);
        if(!$row||!vp3_browser_source_share_authorized_v2050($pdo,$row,$userId))continue;
        $out[]=vp3_browser_source_item_v2050($pdo,$row,$userId,true);
    }
    return $out;
}

function vp3_research_share_context_v2060(PDO $pdo,int $userId,string $browserSharePublicId): array
{
    if($userId<1)throw new RuntimeException('Sign in to use Research.');
    $share=vp3_browser_source_authorized_row_v2050($pdo,$userId,$browserSharePublicId);
    $projects=array_values(array_filter(
        vp3_research_projects_for_user_v2060($pdo,$userId,false),
        static fn(array $project): bool=>vp3_research_role_at_least_v2060((string)($project['role']??''),'researcher')
    ));
    $placements=[];
    $stmt=$pdo->prepare("SELECT p.public_id,p.title,i.public_id AS item_public_id
      FROM research_project_items_v2060 i
      INNER JOIN research_projects_v2060 p ON p.id=i.project_id AND p.deleted_at IS NULL
      WHERE i.browser_share_id=? AND i.item_type='annotation' AND i.item_status='active'
      ORDER BY p.updated_at DESC,p.id DESC");
    $stmt->execute([(int)$share['id']]);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $project=vp3_research_project_row_v2060($pdo,(string)$row['public_id']);
        if(!$project||vp3_research_project_role_v2060($pdo,$project,$userId)==='')continue;
        $placements[]=[
            'project_id'=>(string)$row['public_id'],
            'project_title'=>(string)$row['title'],
            'item_id'=>(string)$row['item_public_id'],
        ];
    }
    $inbox=$pdo->prepare('SELECT 1 FROM browser_research_queue_v2050 WHERE user_id=? AND browser_share_id=? LIMIT 1');
    $inbox->execute([$userId,(int)$share['id']]);
    return [
        'browser_share_id'=>(string)$share['public_id'],
        'in_inbox'=>(bool)$inbox->fetchColumn(),
        'projects'=>$projects,
        'placements'=>$placements,
        'research_url'=>url('/research.php'),
    ];
}

function vp3_research_create_finding_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,string $title,string $body='',string $evidenceItemPublicId='',string $evidenceRole='support'): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'researcher');
    $title=vp3_research_clean_title_v2060($title,'Finding title');$body=vp3_research_clean_body_v2060($body);
    $publicId=vp3_research_uuid_v2060();
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("INSERT INTO research_findings_v2060(public_id,project_id,created_by_user_id,updated_by_user_id,title,body,finding_status,revision_number,created_at,updated_at)
          VALUES(?,?,?,?,?,?,'draft',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $stmt->execute([$publicId,(int)$project['id'],$actorUserId,$actorUserId,$title,$body]);
        $findingId=(int)$pdo->lastInsertId();
        if(trim($evidenceItemPublicId)!=='')vp3_research_link_evidence_by_ids_v2060($pdo,$project,$findingId,$actorUserId,$evidenceItemPublicId,$evidenceRole,'');
        vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'finding.created','finding',$publicId);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return vp3_research_finding_public_v2060($pdo,vp3_research_finding_row_v2060($pdo,$publicId)??[], $actorUserId);
}

function vp3_research_finding_row_v2060(PDO $pdo,string $publicId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM research_findings_v2060 WHERE public_id=? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([trim($publicId)]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
}

function vp3_research_finding_evidence_v2060(PDO $pdo,int $findingId,int $viewerUserId): array
{
    $stmt=$pdo->prepare("SELECT e.*,i.public_id AS item_public_id,i.item_type,i.browser_share_id,i.source_id,i.source_version_id,i.added_by_user_id,i.note,i.tags_json,i.item_status,i.created_at AS item_created_at,i.updated_at AS item_updated_at
      FROM research_finding_evidence_v2060 e INNER JOIN research_project_items_v2060 i ON i.id=e.project_item_id
      WHERE e.finding_id=? ORDER BY FIELD(e.evidence_role,'support','conflict','context'),e.id ASC");
    $stmt->execute([$findingId]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $item=[
            'public_id'=>$row['item_public_id'],'item_type'=>$row['item_type'],'browser_share_id'=>$row['browser_share_id'],
            'source_id'=>$row['source_id'],'source_version_id'=>$row['pinned_source_version_id']?:$row['source_version_id'],
            'added_by_user_id'=>$row['added_by_user_id'],'note'=>$row['note'],'tags_json'=>$row['tags_json'],'item_status'=>$row['item_status'],
            'created_at'=>$row['item_created_at'],'updated_at'=>$row['item_updated_at'],
        ];
        $out[]=[
            'role'=>(string)$row['evidence_role'],'note'=>(string)$row['evidence_note'],
            'item'=>vp3_research_item_public_v2060($pdo,$item,$viewerUserId),
        ];
    }
    return $out;
}

function vp3_research_finding_public_v2060(PDO $pdo,array $row,int $viewerUserId): array
{
    if(!$row)return [];
    return [
        'id'=>(string)$row['public_id'],'title'=>(string)$row['title'],'body'=>(string)$row['body'],
        'status'=>(string)$row['finding_status'],'revision'=>(int)$row['revision_number'],
        'evidence'=>vp3_research_finding_evidence_v2060($pdo,(int)$row['id'],$viewerUserId),
        'created_by'=>(int)$row['created_by_user_id'],'updated_by'=>(int)$row['updated_by_user_id'],
        'confirmed_at'=>(string)($row['confirmed_at']??''),'published_at'=>(string)($row['published_at']??''),
        'created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at'],
    ];
}

function vp3_research_findings_v2060(PDO $pdo,array $project,int $viewerUserId): array
{
    $stmt=$pdo->prepare('SELECT * FROM research_findings_v2060 WHERE project_id=? AND deleted_at IS NULL ORDER BY updated_at DESC,id DESC');
    $stmt->execute([(int)$project['id']]);
    return array_map(fn(array $r): array=>vp3_research_finding_public_v2060($pdo,$r,$viewerUserId),$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_research_update_finding_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,string $findingPublicId,string $title,string $body): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'researcher');
    $finding=vp3_research_finding_row_v2060($pdo,$findingPublicId);
    if(!$finding||(int)$finding['project_id']!==(int)$project['id'])throw new RuntimeException('Finding was not found.');
    if((string)$finding['finding_status']==='published')throw new RuntimeException('Published Findings are immutable. Create a new Finding revision instead.');
    $title=vp3_research_clean_title_v2060($title,'Finding title');$body=vp3_research_clean_body_v2060($body);
    $pdo->prepare("UPDATE research_findings_v2060 SET title=?,body=?,updated_by_user_id=?,revision_number=revision_number+1,updated_at=UTC_TIMESTAMP() WHERE id=?")
        ->execute([$title,$body,$actorUserId,(int)$finding['id']]);
    vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'finding.updated','finding',$findingPublicId);
    return vp3_research_finding_public_v2060($pdo,vp3_research_finding_row_v2060($pdo,$findingPublicId)??$finding,$actorUserId);
}

function vp3_research_set_finding_status_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,string $findingPublicId,string $status): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'researcher');
    $finding=vp3_research_finding_row_v2060($pdo,$findingPublicId);
    if(!$finding||(int)$finding['project_id']!==(int)$project['id'])throw new RuntimeException('Finding was not found.');
    if(!in_array($status,['draft','confirmed'],true))throw new InvalidArgumentException('Finding status can be Draft or Confirmed before publishing.');
    if((string)$finding['finding_status']==='published')throw new RuntimeException('Published Findings cannot return to draft.');
    $confirmed=$status==='confirmed'?gmdate('Y-m-d H:i:s'):null;
    $pdo->prepare('UPDATE research_findings_v2060 SET finding_status=?,confirmed_at=?,confirmed_by_user_id=?,updated_by_user_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([$status,$confirmed,$status==='confirmed'?$actorUserId:null,$actorUserId,(int)$finding['id']]);
    vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'finding.status','finding',$findingPublicId,['from'=>$finding['finding_status'],'to'=>$status]);
    return vp3_research_finding_public_v2060($pdo,vp3_research_finding_row_v2060($pdo,$findingPublicId)??$finding,$actorUserId);
}

function vp3_research_link_evidence_by_ids_v2060(PDO $pdo,array $project,int $findingId,int $actorUserId,string $itemPublicId,string $role,string $note=''): void
{
    if(!in_array($role,['support','conflict','context'],true))throw new InvalidArgumentException('Evidence role is invalid.');
    $stmt=$pdo->prepare('SELECT * FROM research_project_items_v2060 WHERE public_id=? AND project_id=? AND item_status=\'active\' LIMIT 1');
    $stmt->execute([trim($itemPublicId),(int)$project['id']]);$item=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$item)throw new RuntimeException('Research item was not found.');
    $note=vp3_research_clean_body_v2060($note,2000);
    $pdo->prepare("INSERT INTO research_finding_evidence_v2060(finding_id,project_item_id,pinned_source_version_id,evidence_role,evidence_note,created_by_user_id,created_at)
      VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE pinned_source_version_id=VALUES(pinned_source_version_id),evidence_role=VALUES(evidence_role),evidence_note=VALUES(evidence_note)")
      ->execute([$findingId,(int)$item['id'],(int)($item['source_version_id']??0)?:null,$role,$note,$actorUserId]);
}

function vp3_research_link_evidence_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,string $findingPublicId,string $itemPublicId,string $role,string $note=''): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'researcher');
    $finding=vp3_research_finding_row_v2060($pdo,$findingPublicId);
    if(!$finding||(int)$finding['project_id']!==(int)$project['id'])throw new RuntimeException('Finding was not found.');
    if((string)$finding['finding_status']==='published')throw new RuntimeException('Published Findings are immutable.');
    vp3_research_link_evidence_by_ids_v2060($pdo,$project,(int)$finding['id'],$actorUserId,$itemPublicId,$role,$note);
    vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'finding.evidence','finding',$findingPublicId,['role'=>$role]);
    return vp3_research_finding_public_v2060($pdo,vp3_research_finding_row_v2060($pdo,$findingPublicId)??$finding,$actorUserId);
}

function vp3_research_report_row_v2060(PDO $pdo,string $publicId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM research_reports_v2060 WHERE public_id=? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([trim($publicId)]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
}

function vp3_research_create_report_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,string $title,string $summary=''): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'researcher');
    $title=vp3_research_clean_title_v2060($title,'Report title');$summary=vp3_research_clean_body_v2060($summary,15000);
    $publicId=vp3_research_uuid_v2060();
    $stmt=$pdo->prepare("INSERT INTO research_reports_v2060(public_id,project_id,created_by_user_id,updated_by_user_id,title,summary,report_status,visibility,created_at,updated_at)
      VALUES(?,?,?,?,?,?,'draft','private',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $stmt->execute([$publicId,(int)$project['id'],$actorUserId,$actorUserId,$title,$summary]);
    vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'report.created','report',$publicId);
    return vp3_research_report_public_v2060($pdo,vp3_research_report_row_v2060($pdo,$publicId)??[], $actorUserId);
}

function vp3_research_report_items_v2060(PDO $pdo,int $reportId,int $viewerUserId): array
{
    $stmt=$pdo->prepare("SELECT ri.*,f.public_id AS finding_public_id,f.title AS finding_title,f.body AS finding_body,f.finding_status,
      i.public_id AS project_item_public_id,i.item_type AS project_item_type,i.browser_share_id,i.source_id,i.source_version_id,i.added_by_user_id,i.note,i.tags_json,i.item_status,i.created_at AS item_created_at,i.updated_at AS item_updated_at
      FROM research_report_items_v2060 ri
      LEFT JOIN research_findings_v2060 f ON f.id=ri.finding_id AND f.deleted_at IS NULL
      LEFT JOIN research_project_items_v2060 i ON i.id=ri.project_item_id
      WHERE ri.report_id=? ORDER BY ri.sort_order ASC,ri.id ASC");
    $stmt->execute([$reportId]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        if((string)$row['item_type']==='finding'){
            $out[]=['type'=>'finding','id'=>(string)$row['finding_public_id'],'title'=>(string)$row['finding_title'],'status'=>(string)$row['finding_status']];
        }else{
            $item=[
                'public_id'=>$row['project_item_public_id'],'item_type'=>$row['project_item_type'],'browser_share_id'=>$row['browser_share_id'],
                'source_id'=>$row['source_id'],'source_version_id'=>$row['source_version_id'],'added_by_user_id'=>$row['added_by_user_id'],
                'note'=>$row['note'],'tags_json'=>$row['tags_json'],'item_status'=>$row['item_status'],
                'created_at'=>$row['item_created_at'],'updated_at'=>$row['item_updated_at'],
            ];
            $out[]=['type'=>(string)$row['item_type'],'item'=>vp3_research_item_public_v2060($pdo,$item,$viewerUserId)];
        }
    }
    return $out;
}

function vp3_research_report_public_v2060(PDO $pdo,array $row,int $viewerUserId): array
{
    if(!$row)return [];
    return [
        'id'=>(string)$row['public_id'],'title'=>(string)$row['title'],'summary'=>(string)$row['summary'],
        'status'=>(string)$row['report_status'],'visibility'=>(string)$row['visibility'],'team_id'=>(int)($row['team_owner_user_id']??0),
        'current_version'=>(int)($row['current_version_no']??0),
        'items'=>vp3_research_report_items_v2060($pdo,(int)$row['id'],$viewerUserId),
        'published_at'=>(string)($row['published_at']??''),'created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at'],
        'url'=>url('/research-report.php?report='.rawurlencode((string)$row['public_id'])),
    ];
}

function vp3_research_reports_v2060(PDO $pdo,array $project,int $viewerUserId): array
{
    $stmt=$pdo->prepare('SELECT * FROM research_reports_v2060 WHERE project_id=? AND deleted_at IS NULL ORDER BY updated_at DESC,id DESC');
    $stmt->execute([(int)$project['id']]);
    return array_map(fn(array $r): array=>vp3_research_report_public_v2060($pdo,$r,$viewerUserId),$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_research_update_report_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,string $reportPublicId,string $title,string $summary): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'researcher');
    $report=vp3_research_report_row_v2060($pdo,$reportPublicId);
    if(!$report||(int)$report['project_id']!==(int)$project['id'])throw new RuntimeException('Report was not found.');
    $title=vp3_research_clean_title_v2060($title,'Report title');$summary=vp3_research_clean_body_v2060($summary,15000);
    $pdo->prepare('UPDATE research_reports_v2060 SET title=?,summary=?,updated_by_user_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([$title,$summary,$actorUserId,(int)$report['id']]);
    vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'report.updated','report',$reportPublicId);
    return vp3_research_report_public_v2060($pdo,vp3_research_report_row_v2060($pdo,$reportPublicId)??$report,$actorUserId);
}

function vp3_research_set_report_items_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,string $reportPublicId,array $items): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'researcher');
    $report=vp3_research_report_row_v2060($pdo,$reportPublicId);
    if(!$report||(int)$report['project_id']!==(int)$project['id'])throw new RuntimeException('Report was not found.');
    $validated=[];$seen=[];
    foreach(array_slice($items,0,200) as $position=>$raw){
        if(!is_array($raw))continue;
        $type=(string)($raw['type']??'');$id=trim((string)($raw['id']??''));
        if($id===''||!in_array($type,['finding','source','annotation'],true))continue;
        $key=$type.':'.$id;if(isset($seen[$key]))continue;$seen[$key]=true;
        if($type==='finding'){
            $finding=vp3_research_finding_row_v2060($pdo,$id);
            if(!$finding||(int)$finding['project_id']!==(int)$project['id'])throw new RuntimeException('A selected Finding is unavailable.');
            $validated[]=['type'=>$type,'finding_id'=>(int)$finding['id'],'project_item_id'=>null,'sort'=>$position];
        }else{
            $stmt=$pdo->prepare('SELECT id,item_type FROM research_project_items_v2060 WHERE public_id=? AND project_id=? AND item_status=\'active\' LIMIT 1');
            $stmt->execute([$id,(int)$project['id']]);$item=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$item||(string)$item['item_type']!==$type)throw new RuntimeException('A selected Research item is unavailable.');
            $validated[]=['type'=>$type,'finding_id'=>null,'project_item_id'=>(int)$item['id'],'sort'=>$position];
        }
    }
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $pdo->prepare('DELETE FROM research_report_items_v2060 WHERE report_id=?')->execute([(int)$report['id']]);
        $stmt=$pdo->prepare('INSERT INTO research_report_items_v2060(report_id,item_type,finding_id,project_item_id,sort_order,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())');
        foreach($validated as $item)$stmt->execute([(int)$report['id'],$item['type'],$item['finding_id'],$item['project_item_id'],$item['sort']]);
        $pdo->prepare('UPDATE research_reports_v2060 SET updated_by_user_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$actorUserId,(int)$report['id']]);
        vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'report.items','report',$reportPublicId);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return vp3_research_report_public_v2060($pdo,vp3_research_report_row_v2060($pdo,$reportPublicId)??$report,$actorUserId);
}

function vp3_research_annotation_compatible_v2060(array $annotation,string $reportVisibility,int $teamOwnerUserId): bool
{
    $visibility=(string)($annotation['publication']['visibility']??'legacy');
    if($reportVisibility==='private')return true;
    if($visibility==='public')return true;
    if($reportVisibility==='team'&&$visibility==='team'&&(int)($annotation['publication']['team_id']??0)===$teamOwnerUserId)return true;
    return false;
}

function vp3_research_source_snapshot_v2060(PDO $pdo,int $sourceId,int $sourceVersionId): array
{
    $stmt=$pdo->prepare('SELECT public_id,normalized_url,canonical_url,source_domain,source_title FROM browser_sources_v2050 WHERE id=? LIMIT 1');
    $stmt->execute([$sourceId]);$source=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$source)throw new RuntimeException('Research Source is unavailable.');
    $version=[];
    if($sourceVersionId>0){
        $stmt=$pdo->prepare('SELECT public_id,content_hash,version_basis,canonical_url,source_title,captured_at FROM browser_source_versions_v2050 WHERE id=? AND source_id=? LIMIT 1');
        $stmt->execute([$sourceVersionId,$sourceId]);$version=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
    }
    return [
        'id'=>(string)$source['public_id'],'url'=>(string)$source['canonical_url'],'domain'=>(string)$source['source_domain'],
        'title'=>(string)($version['source_title']??$source['source_title']),
        'version'=>[
            'id'=>(string)($version['public_id']??''),'hash'=>(string)($version['content_hash']??''),
            'basis'=>(string)($version['version_basis']??''),'captured_at'=>(string)($version['captured_at']??''),
        ],
    ];
}

function vp3_research_build_report_snapshot_v2060(PDO $pdo,array $project,array $report,int $actorUserId,string $visibility,int $teamOwnerUserId): array
{
    $items=vp3_research_report_items_v2060($pdo,(int)$report['id'],$actorUserId);
    if(!$items)throw new RuntimeException('Add at least one Finding, Source, or Annotation before publishing.');
    $snapshot=[
        'schema_version'=>VP3_RESEARCH_REPORT_SCHEMA_V2060,
        'report'=>['id'=>(string)$report['public_id'],'title'=>(string)$report['title'],'summary'=>(string)$report['summary']],
        'project'=>['id'=>(string)$project['public_id'],'title'=>(string)$project['title']],
        'visibility'=>$visibility,'team_id'=>$teamOwnerUserId,'findings'=>[],'sources'=>[],'annotations'=>[],
    ];
    $sourceIndex=[];
    foreach($items as $item){
        if($item['type']==='finding'){
            $finding=vp3_research_finding_row_v2060($pdo,(string)$item['id']);
            if(!$finding||!in_array((string)$finding['finding_status'],['confirmed','published'],true))throw new RuntimeException('All published Findings must be Confirmed first.');
            $evidence=[];
            foreach(vp3_research_finding_evidence_v2060($pdo,(int)$finding['id'],$actorUserId) as $ev){
                $src=$ev['item']['source'];
                $sourceIndex[(string)$src['id'].':'.(string)($src['version']['id']??'')]=$src;
                $evidence[]=['role'=>$ev['role'],'source'=>$src];
            }
            $snapshot['findings'][]=[
                'id'=>(string)$finding['public_id'],'title'=>(string)$finding['title'],'body'=>(string)$finding['body'],
                'revision'=>(int)$finding['revision_number'],'evidence'=>$evidence,
            ];
            continue;
        }
        $projectItem=$item['item'];
        $src=$projectItem['source'];$sourceIndex[(string)$src['id'].':'.(string)($src['version']['id']??'')]=$src;
        if($item['type']==='annotation'){
            $annotation=$projectItem['annotation'];
            if($annotation&&vp3_research_annotation_compatible_v2060($annotation,$visibility,$teamOwnerUserId)){
                $snapshot['annotations'][]=[
                    'id'=>(string)$annotation['id'],'selection'=>(string)$annotation['selection'],'note'=>(string)$annotation['note'],
                    'captured_at'=>(string)$annotation['captured_at'],'source'=>$src,
                ];
            }else{
                $snapshot['annotations'][]=['id'=>'','redacted_to_source'=>true,'source'=>$src];
            }
        }
    }
    $snapshot['sources']=array_values($sourceIndex);
    usort($snapshot['sources'],static fn(array $a,array $b): int=>strcmp((string)$a['url'],(string)$b['url']));
    return $snapshot;
}

function vp3_research_publish_report_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,string $reportPublicId,string $visibility,int $teamOwnerUserId=0): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'admin');
    $report=vp3_research_report_row_v2060($pdo,$reportPublicId);
    if(!$report||(int)$report['project_id']!==(int)$project['id'])throw new RuntimeException('Report was not found.');
    $visibility=strtolower(trim($visibility));
    if(!in_array($visibility,['private','team','public'],true))throw new InvalidArgumentException('Report visibility is invalid.');
    if($visibility==='team'){
        if($teamOwnerUserId<1||!vp3_human_team_authorized_v370($pdo,$teamOwnerUserId,$actorUserId))throw new RuntimeException('Choose a Team workspace you can access.');
    }else $teamOwnerUserId=0;

    $snapshot=vp3_research_build_report_snapshot_v2060($pdo,$project,$report,$actorUserId,$visibility,$teamOwnerUserId);
    $next=(int)$report['current_version_no']+1;
    $snapshot['report']['version']=$next;
    $snapshot['published_at']=gmdate('Y-m-d H:i:s');
    $encoded=json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($encoded))throw new RuntimeException('Research report snapshot could not be encoded.');
    $hash=hash('sha256',$encoded);$versionPublicId=vp3_research_uuid_v2060();
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("INSERT INTO research_report_versions_v2060(public_id,report_id,version_no,visibility,team_owner_user_id,title,summary,snapshot_json,snapshot_hash,published_by_user_id,published_at)
          VALUES(?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$versionPublicId,(int)$report['id'],$next,$visibility,$teamOwnerUserId>0?$teamOwnerUserId:null,(string)$report['title'],(string)$report['summary'],$encoded,$hash,$actorUserId,$snapshot['published_at']]);
        $versionId=(int)$pdo->lastInsertId();
        $sourceStmt=$pdo->prepare("INSERT IGNORE INTO research_report_version_sources_v2060(report_version_id,source_id,source_version_id,created_at) VALUES(?,?,?,UTC_TIMESTAMP())");
        foreach($snapshot['sources'] as $src){
            $s=$pdo->prepare('SELECT id FROM browser_sources_v2050 WHERE public_id=? LIMIT 1');$s->execute([(string)$src['id']]);$sourceId=(int)$s->fetchColumn();
            if($sourceId<1)continue;
            $versionIdDb=null;
            if(!empty($src['version']['id'])){$v=$pdo->prepare('SELECT id FROM browser_source_versions_v2050 WHERE public_id=? AND source_id=? LIMIT 1');$v->execute([(string)$src['version']['id'],$sourceId]);$versionIdDb=(int)$v->fetchColumn()?:null;}
            $sourceStmt->execute([$versionId,$sourceId,$versionIdDb]);
        }
        $pdo->prepare("UPDATE research_reports_v2060 SET report_status='published',visibility=?,team_owner_user_id=?,current_version_id=?,current_version_no=?,published_at=UTC_TIMESTAMP(),updated_by_user_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([$visibility,$teamOwnerUserId>0?$teamOwnerUserId:null,$versionId,$next,$actorUserId,(int)$report['id']]);
        $findingIds=$pdo->prepare("SELECT finding_id FROM research_report_items_v2060 WHERE report_id=? AND item_type='finding' AND finding_id IS NOT NULL");
        $findingIds->execute([(int)$report['id']]);
        foreach($findingIds->fetchAll(PDO::FETCH_COLUMN)?:[] as $findingId){
            $pdo->prepare("UPDATE research_findings_v2060 SET finding_status='published',published_at=COALESCE(published_at,UTC_TIMESTAMP()),published_by_user_id=COALESCE(published_by_user_id,?),updated_at=UTC_TIMESTAMP() WHERE id=? AND finding_status='confirmed'")
                ->execute([$actorUserId,(int)$findingId]);
        }
        vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'report.published','report',$reportPublicId,['visibility'=>$visibility,'team_id'=>$teamOwnerUserId,'version'=>$next]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return vp3_research_report_public_v2060($pdo,vp3_research_report_row_v2060($pdo,$reportPublicId)??$report,$actorUserId);
}

function vp3_research_unpublish_report_v2060(PDO $pdo,int $actorUserId,string $projectPublicId,string $reportPublicId): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$actorUserId,'admin');
    $report=vp3_research_report_row_v2060($pdo,$reportPublicId);
    if(!$report||(int)$report['project_id']!==(int)$project['id'])throw new RuntimeException('Report was not found.');
    $pdo->prepare("UPDATE research_reports_v2060 SET report_status='unpublished',updated_by_user_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$actorUserId,(int)$report['id']]);
    vp3_research_event_v2060($pdo,(int)$project['id'],$actorUserId,'report.unpublished','report',$reportPublicId);
    return vp3_research_report_public_v2060($pdo,vp3_research_report_row_v2060($pdo,$reportPublicId)??$report,$actorUserId);
}

function vp3_research_report_version_v2060(PDO $pdo,array $report,int $viewerUserId,int $versionNo=0): ?array
{
    if($versionNo>0){$stmt=$pdo->prepare('SELECT * FROM research_report_versions_v2060 WHERE report_id=? AND version_no=? LIMIT 1');$stmt->execute([(int)$report['id'],$versionNo]);}
    else{$stmt=$pdo->prepare('SELECT * FROM research_report_versions_v2060 WHERE id=? AND report_id=? LIMIT 1');$stmt->execute([(int)($report['current_version_id']??0),(int)$report['id']]);}
    $version=$stmt->fetch(PDO::FETCH_ASSOC);if(!$version)return null;

    $project=vp3_research_project_row_by_id_v2060($pdo,(int)$report['project_id']);
    $projectRole=$project?vp3_research_project_role_v2060($pdo,$project,$viewerUserId):'';
    if((string)$report['report_status']!=='published'){
        if(!vp3_research_role_at_least_v2060($projectRole,'viewer'))return null;
    }else{
        $visibility=(string)$version['visibility'];
        if($visibility==='public'){}
        elseif($visibility==='team'){
            $team=(int)($version['team_owner_user_id']??0);
            if($viewerUserId<1||$team<1||!vp3_human_team_authorized_v370($pdo,$team,$viewerUserId))return null;
        }elseif(!vp3_research_role_at_least_v2060($projectRole,'viewer'))return null;
    }
    $snapshot=json_decode((string)$version['snapshot_json'],true);
    if(!is_array($snapshot))return null;
    return [
        'id'=>(string)$version['public_id'],'version'=>(int)$version['version_no'],'visibility'=>(string)$version['visibility'],
        'team_id'=>(int)($version['team_owner_user_id']??0),'snapshot'=>$snapshot,'sha256'=>(string)$version['snapshot_hash'],
        'published_at'=>(string)$version['published_at'],
    ];
}

function vp3_research_reports_for_source_v2060(PDO $pdo,int $sourceId,int $viewerUserId): array
{
    if($sourceId<1)return [];
    $stmt=$pdo->prepare("SELECT DISTINCT r.* FROM research_reports_v2060 r
      INNER JOIN research_report_versions_v2060 v ON v.id=r.current_version_id
      INNER JOIN research_report_version_sources_v2060 rs ON rs.report_version_id=v.id
      WHERE rs.source_id=? AND r.report_status='published' AND r.deleted_at IS NULL
      ORDER BY r.published_at DESC,r.id DESC LIMIT 50");
    $stmt->execute([$sourceId]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $report){
        $version=vp3_research_report_version_v2060($pdo,$report,$viewerUserId,0);
        if(!$version)continue;
        $out[]=['id'=>(string)$report['public_id'],'title'=>(string)$report['title'],'summary'=>(string)$report['summary'],'visibility'=>$version['visibility'],'published_at'=>$version['published_at'],'url'=>url('/research-report.php?report='.rawurlencode((string)$report['public_id']))];
    }
    return $out;
}

function vp3_research_project_bundle_v2060(PDO $pdo,string $projectPublicId,int $viewerUserId): array
{
    $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$viewerUserId,'viewer');
    return [
        'project'=>vp3_research_project_public_v2060($pdo,$project,$viewerUserId),
        'members'=>vp3_research_role_at_least_v2060((string)$project['_role'],'admin')?vp3_research_members_v2060($pdo,$project):[],
        'items'=>vp3_research_project_items_v2060($pdo,$project,$viewerUserId),
        'findings'=>vp3_research_findings_v2060($pdo,$project,$viewerUserId),
        'reports'=>vp3_research_reports_v2060($pdo,$project,$viewerUserId),
    ];
}

function vp3_research_ensure_schema_v2060(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_research_schema_ready_v2060($pdo))return;
    if($pdo->inTransaction())throw new RuntimeException('Research Projects schema must be installed before starting a transaction.');
    if(!vp3_browser_source_feed_schema_ready_v2050($pdo))vp3_browser_source_feed_ensure_schema_v2050($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS research_projects_v2060 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      team_owner_user_id INT UNSIGNED NULL,
      title VARCHAR(190) NOT NULL,
      description TEXT NOT NULL,
      project_status VARCHAR(24) NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      UNIQUE KEY uq_research_project_public (public_id),
      INDEX idx_research_project_owner (owner_user_id,project_status,updated_at),
      INDEX idx_research_project_team (team_owner_user_id,project_status,updated_at),
      CONSTRAINT fk_research_project_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_project_team FOREIGN KEY (team_owner_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS research_project_members_v2060 (
      project_id BIGINT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      member_role VARCHAR(24) NOT NULL DEFAULT 'viewer',
      added_by_user_id INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (project_id,user_id),
      INDEX idx_research_member_user (user_id,member_role,updated_at),
      CONSTRAINT fk_research_member_project FOREIGN KEY (project_id) REFERENCES research_projects_v2060(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_member_adder FOREIGN KEY (added_by_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS research_project_items_v2060 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      project_id BIGINT UNSIGNED NOT NULL,
      item_key VARCHAR(100) NOT NULL,
      item_type VARCHAR(20) NOT NULL,
      browser_share_id BIGINT UNSIGNED NULL,
      source_id BIGINT UNSIGNED NOT NULL,
      source_version_id BIGINT UNSIGNED NULL,
      added_by_user_id INT UNSIGNED NOT NULL,
      note TEXT NOT NULL,
      tags_json TEXT NOT NULL,
      item_status VARCHAR(20) NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_research_item_public (public_id),
      UNIQUE KEY uq_research_item_key (project_id,item_key),
      INDEX idx_research_item_project (project_id,item_type,item_status,updated_at),
      CONSTRAINT fk_research_item_project FOREIGN KEY (project_id) REFERENCES research_projects_v2060(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_item_share FOREIGN KEY (browser_share_id) REFERENCES browser_shares_v2010(id) ON DELETE SET NULL,
      CONSTRAINT fk_research_item_source FOREIGN KEY (source_id) REFERENCES browser_sources_v2050(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_item_version FOREIGN KEY (source_version_id) REFERENCES browser_source_versions_v2050(id) ON DELETE SET NULL,
      CONSTRAINT fk_research_item_adder FOREIGN KEY (added_by_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS research_project_events_v2060 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      project_id BIGINT UNSIGNED NOT NULL,
      actor_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(80) NOT NULL,
      object_type VARCHAR(40) NOT NULL DEFAULT '',
      object_public_id VARCHAR(64) NOT NULL DEFAULT '',
      metadata_json TEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_research_event_project (project_id,id),
      CONSTRAINT fk_research_event_project FOREIGN KEY (project_id) REFERENCES research_projects_v2060(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS research_findings_v2060 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      project_id BIGINT UNSIGNED NOT NULL,
      created_by_user_id INT UNSIGNED NOT NULL,
      updated_by_user_id INT UNSIGNED NOT NULL,
      confirmed_by_user_id INT UNSIGNED NULL,
      published_by_user_id INT UNSIGNED NULL,
      title VARCHAR(190) NOT NULL,
      body TEXT NOT NULL,
      finding_status VARCHAR(24) NOT NULL DEFAULT 'draft',
      revision_number INT UNSIGNED NOT NULL DEFAULT 1,
      confirmed_at DATETIME NULL,
      published_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      UNIQUE KEY uq_research_finding_public (public_id),
      INDEX idx_research_finding_project (project_id,finding_status,updated_at),
      CONSTRAINT fk_research_finding_project FOREIGN KEY (project_id) REFERENCES research_projects_v2060(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_finding_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_finding_updater FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_finding_confirmer FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_research_finding_publisher FOREIGN KEY (published_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS research_finding_evidence_v2060 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      finding_id BIGINT UNSIGNED NOT NULL,
      project_item_id BIGINT UNSIGNED NOT NULL,
      pinned_source_version_id BIGINT UNSIGNED NULL,
      evidence_role VARCHAR(20) NOT NULL DEFAULT 'support',
      evidence_note VARCHAR(2000) NOT NULL DEFAULT '',
      created_by_user_id INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_research_evidence_item (finding_id,project_item_id),
      INDEX idx_research_evidence_item (project_item_id,finding_id),
      CONSTRAINT fk_research_evidence_finding FOREIGN KEY (finding_id) REFERENCES research_findings_v2060(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_evidence_item FOREIGN KEY (project_item_id) REFERENCES research_project_items_v2060(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_evidence_version FOREIGN KEY (pinned_source_version_id) REFERENCES browser_source_versions_v2050(id) ON DELETE SET NULL,
      CONSTRAINT fk_research_evidence_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS research_reports_v2060 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      project_id BIGINT UNSIGNED NOT NULL,
      created_by_user_id INT UNSIGNED NOT NULL,
      updated_by_user_id INT UNSIGNED NOT NULL,
      title VARCHAR(190) NOT NULL,
      summary TEXT NOT NULL,
      report_status VARCHAR(24) NOT NULL DEFAULT 'draft',
      visibility VARCHAR(16) NOT NULL DEFAULT 'private',
      team_owner_user_id INT UNSIGNED NULL,
      current_version_id BIGINT UNSIGNED NULL,
      current_version_no INT UNSIGNED NOT NULL DEFAULT 0,
      published_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      UNIQUE KEY uq_research_report_public (public_id),
      INDEX idx_research_report_project (project_id,report_status,updated_at),
      INDEX idx_research_report_publication (report_status,visibility,published_at),
      CONSTRAINT fk_research_report_project FOREIGN KEY (project_id) REFERENCES research_projects_v2060(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_report_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_report_updater FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_report_team FOREIGN KEY (team_owner_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS research_report_items_v2060 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      report_id BIGINT UNSIGNED NOT NULL,
      item_type VARCHAR(20) NOT NULL,
      finding_id BIGINT UNSIGNED NULL,
      project_item_id BIGINT UNSIGNED NULL,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_research_report_item_order (report_id,sort_order,id),
      CONSTRAINT fk_research_report_item_report FOREIGN KEY (report_id) REFERENCES research_reports_v2060(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_report_item_finding FOREIGN KEY (finding_id) REFERENCES research_findings_v2060(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_report_item_project_item FOREIGN KEY (project_item_id) REFERENCES research_project_items_v2060(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS research_report_versions_v2060 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      report_id BIGINT UNSIGNED NOT NULL,
      version_no INT UNSIGNED NOT NULL,
      visibility VARCHAR(16) NOT NULL,
      team_owner_user_id INT UNSIGNED NULL,
      title VARCHAR(190) NOT NULL,
      summary TEXT NOT NULL,
      snapshot_json LONGTEXT NOT NULL,
      snapshot_hash CHAR(64) NOT NULL,
      published_by_user_id INT UNSIGNED NOT NULL,
      published_at DATETIME NOT NULL,
      UNIQUE KEY uq_research_report_version_public (public_id),
      UNIQUE KEY uq_research_report_version_no (report_id,version_no),
      INDEX idx_research_report_version_publication (visibility,published_at,report_id),
      CONSTRAINT fk_research_report_version_report FOREIGN KEY (report_id) REFERENCES research_reports_v2060(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_report_version_team FOREIGN KEY (team_owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_research_report_version_publisher FOREIGN KEY (published_by_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS research_report_version_sources_v2060 (
      report_version_id BIGINT UNSIGNED NOT NULL,
      source_id BIGINT UNSIGNED NOT NULL,
      source_version_id BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_research_report_version_source (report_version_id,source_id,source_version_id),
      INDEX idx_research_report_source (source_id,report_version_id),
      CONSTRAINT fk_research_report_source_version_report FOREIGN KEY (report_version_id) REFERENCES research_report_versions_v2060(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_report_source_source FOREIGN KEY (source_id) REFERENCES browser_sources_v2050(id) ON DELETE CASCADE,
      CONSTRAINT fk_research_report_source_version FOREIGN KEY (source_version_id) REFERENCES browser_source_versions_v2050(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
