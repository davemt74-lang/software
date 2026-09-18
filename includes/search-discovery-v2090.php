<?php
declare(strict_types=1);

require_once __DIR__.'/browser-trust-v2080.php';
require_once __DIR__.'/profile-agent.php';
require_once __DIR__.'/artist-workspace-v181.php';

const VP3_SEARCH_DISCOVERY_V2090='search-discovery-v2090-20260918';
const VP3_SEARCH_INDEX_BATCH_V2090=20000;
const VP3_SEARCH_CANDIDATES_V2090=400;

function vp3_search_schema_ready_v2090(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && vp3_browser_trust_schema_ready_v2080($pdo)
        && table_exists('search_documents_v2090')
        && table_exists('search_recent_queries_v2090')
        && table_exists('search_saved_queries_v2090')
        && table_exists('search_index_events_v2090');
}

function vp3_search_require_ready_v2090(?PDO $pdo=null): PDO
{
    $pdo??=db();
    if(!$pdo||!vp3_search_schema_ready_v2090($pdo))throw new RuntimeException('Search & Discovery is not ready. Run the current database upgrade.');
    return $pdo;
}

function vp3_search_uuid_v2090(): string{return vp3_extension_uuid_v2000();}

function vp3_search_clean_text_v2090(string $value,int $max=30000): string
{
    $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',' ',str_replace("\0",'',$value))??'';
    $value=preg_replace('/\s+/u',' ',trim($value))??trim($value);
    return mb_substr($value,0,$max);
}

function vp3_search_tokens_v2090(string $query): array
{
    $query=mb_strtolower(vp3_search_clean_text_v2090($query,500));
    preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}._-]{1,63}/u',$query,$m);
    $tokens=[];
    foreach((array)($m[0]??[]) as $token){
        $token=trim((string)$token);
        if(mb_strlen($token)<2)continue;
        $tokens[$token]=true;
        if(count($tokens)>=12)break;
    }
    return array_keys($tokens);
}

function vp3_search_filters_v2090(array $input): array
{
    $allowed=['source','annotation','claim','research','live','user','team'];
    $types=$input['types']??$input['type']??[];
    if(is_string($types))$types=array_filter(array_map('trim',explode(',',$types)));
    if(!is_array($types))$types=[];
    $types=array_values(array_unique(array_values(array_filter(array_map(static fn($v): string=>strtolower(trim((string)$v)),$types),static fn(string $v): bool=>in_array($v,$allowed,true)))));
    $visibility=strtolower(trim((string)($input['visibility']??'')));
    if(!in_array($visibility,['','public','team','private','legacy'],true))$visibility='';
    $changed=(string)($input['changed']??'');
    return [
        'types'=>$types,
        'visibility'=>$visibility,
        'domain'=>mb_strtolower(trim((string)($input['domain']??''))),
        'team_id'=>max(0,(int)($input['team_id']??0)),
        'author_id'=>max(0,(int)($input['author_id']??0)),
        'claim_status'=>strtolower(trim((string)($input['claim_status']??''))),
        'changed'=>in_array($changed,['0','1'],true)?$changed:'',
        'date_from'=>preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($input['date_from']??''))?(string)$input['date_from']:'',
        'date_to'=>preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($input['date_to']??''))?(string)$input['date_to']:'',
        'context_source_id'=>trim((string)($input['context_source_id']??'')),
        'context_domain'=>mb_strtolower(trim((string)($input['context_domain']??''))),
        'context_only'=>!empty($input['context_only']),
    ];
}

function vp3_search_filters_json_v2090(array $filters): string
{
    $json=json_encode($filters,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    return is_string($json)?$json:'{}';
}

function vp3_search_index_event_v2090(PDO $pdo,string $type,string $publicId,string $event): void
{
    try{
        $pdo->prepare("INSERT INTO search_index_events_v2090(public_id,document_type,object_public_id,event_type,created_at)
          VALUES(?,?,?,?,UTC_TIMESTAMP())")->execute([vp3_search_uuid_v2090(),mb_substr($type,0,32),mb_substr($publicId,0,80),mb_substr($event,0,40)]);
    }catch(Throwable $e){}
}

function vp3_search_upsert_document_v2090(PDO $pdo,array $doc): void
{
    $type=trim((string)($doc['document_type']??''));$publicId=trim((string)($doc['object_public_id']??''));
    if($type===''||$publicId==='')return;
    $stmt=$pdo->prepare("INSERT INTO search_documents_v2090
      (document_type,object_public_id,source_id,owner_user_id,team_owner_user_id,visibility,source_domain,claim_status,title,body,url,activity_score,engagement_count,source_changed,object_created_at,object_updated_at,indexed_at,deleted_at)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),NULL)
      ON DUPLICATE KEY UPDATE source_id=VALUES(source_id),owner_user_id=VALUES(owner_user_id),team_owner_user_id=VALUES(team_owner_user_id),
      visibility=VALUES(visibility),source_domain=VALUES(source_domain),claim_status=VALUES(claim_status),title=VALUES(title),body=VALUES(body),url=VALUES(url),
      activity_score=VALUES(activity_score),engagement_count=VALUES(engagement_count),source_changed=VALUES(source_changed),
      object_created_at=VALUES(object_created_at),object_updated_at=VALUES(object_updated_at),indexed_at=UTC_TIMESTAMP(),deleted_at=NULL");
    $stmt->execute([
        $type,$publicId,(int)($doc['source_id']??0)?:null,(int)($doc['owner_user_id']??0)?:null,(int)($doc['team_owner_user_id']??0)?:null,
        mb_substr((string)($doc['visibility']??'private'),0,16),mb_substr((string)($doc['source_domain']??''),0,253),mb_substr((string)($doc['claim_status']??''),0,24),
        vp3_search_clean_text_v2090((string)($doc['title']??''),512),vp3_search_clean_text_v2090((string)($doc['body']??''),30000),mb_substr((string)($doc['url']??''),0,500),
        max(0,(int)($doc['activity_score']??0)),max(0,(int)($doc['engagement_count']??0)),!empty($doc['source_changed'])?1:0,
        (string)($doc['object_created_at']??gmdate('Y-m-d H:i:s')),(string)($doc['object_updated_at']??gmdate('Y-m-d H:i:s')),
    ]);
    vp3_search_index_event_v2090($pdo,$type,$publicId,'upsert');
}

function vp3_search_tombstone_v2090(PDO $pdo,string $type,string $publicId): void
{
    if($type===''||$publicId==='')return;
    $stmt=$pdo->prepare('UPDATE search_documents_v2090 SET deleted_at=COALESCE(deleted_at,UTC_TIMESTAMP()),indexed_at=UTC_TIMESTAMP() WHERE document_type=? AND object_public_id=?');
    $stmt->execute([$type,$publicId]);
    if($stmt->rowCount()>0)vp3_search_index_event_v2090($pdo,$type,$publicId,'tombstone');
}

function vp3_search_source_changed_v2090(PDO $pdo,int $sourceId): bool
{
    if($sourceId<1||!table_exists('browser_source_change_events_v2080'))return false;
    $stmt=$pdo->prepare('SELECT 1 FROM browser_source_change_events_v2080 WHERE source_id=? LIMIT 1');$stmt->execute([$sourceId]);
    return (bool)$stmt->fetchColumn();
}

function vp3_search_index_source_v2090(PDO $pdo,int $sourceId): void
{
    $stmt=$pdo->prepare('SELECT * FROM browser_sources_v2050 WHERE id=? LIMIT 1');$stmt->execute([$sourceId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)return;
    $activity=0;$engagement=0;
    $q=$pdo->prepare("SELECT COUNT(*) FROM browser_share_sources_v2050 ss INNER JOIN browser_share_publications_v2050 p ON p.browser_share_id=ss.browser_share_id WHERE ss.source_id=? AND p.visibility='public'");
    $q->execute([$sourceId]);$annotations=(int)$q->fetchColumn();$activity+=$annotations*4;$engagement+=$annotations;
    if(table_exists('browser_claims_v2080')){$q=$pdo->prepare("SELECT COUNT(*) FROM browser_claims_v2080 WHERE source_id=? AND visibility='public' AND deleted_at IS NULL");$q->execute([$sourceId]);$n=(int)$q->fetchColumn();$activity+=$n*5;$engagement+=$n;}
    if(table_exists('research_report_version_sources_v2060')){$q=$pdo->prepare("SELECT COUNT(DISTINCT rv.report_id) FROM research_report_version_sources_v2060 rs INNER JOIN research_report_versions_v2060 rv ON rv.id=rs.report_version_id WHERE rs.source_id=? AND rv.visibility='public'");$q->execute([$sourceId]);$n=(int)$q->fetchColumn();$activity+=$n*6;$engagement+=$n;}
    if(table_exists('live_rooms_v2070')){$q=$pdo->prepare("SELECT COUNT(*) FROM live_rooms_v2070 WHERE source_id=? AND room_scope='public' AND deleted_at IS NULL");$q->execute([$sourceId]);$n=(int)$q->fetchColumn();$activity+=$n*6;$engagement+=$n;}
    $changed=vp3_search_source_changed_v2090($pdo,$sourceId);if($changed)$activity+=5;
    vp3_search_upsert_document_v2090($pdo,[
        'document_type'=>'source','object_public_id'=>(string)$row['public_id'],'source_id'=>$sourceId,'visibility'=>'mixed',
        'source_domain'=>(string)$row['source_domain'],'title'=>(string)$row['source_domain'],'body'=>(string)$row['canonical_url'],
        'url'=>url('/source.php?source='.rawurlencode((string)$row['public_id'])),'activity_score'=>$activity,'engagement_count'=>$engagement,'source_changed'=>$changed,
        'object_created_at'=>(string)$row['first_seen_at'],'object_updated_at'=>(string)$row['last_seen_at'],
    ]);
}

function vp3_search_index_annotation_v2090(PDO $pdo,int $shareId): void
{
    $row=vp3_browser_source_share_row_by_id_v2050($pdo,$shareId);
    if(!$row){$stmt=$pdo->prepare("SELECT public_id FROM browser_shares_v2010 WHERE id=? LIMIT 1");$stmt->execute([$shareId]);$id=(string)($stmt->fetchColumn()?:'');if($id!=='')vp3_search_tombstone_v2090($pdo,'annotation',$id);return;}
    $comments=$pdo->prepare('SELECT COUNT(*) FROM browser_share_comments_v2050 WHERE browser_share_id=? AND deleted_at IS NULL');$comments->execute([$shareId]);$commentCount=(int)$comments->fetchColumn();
    $saves=$pdo->prepare('SELECT COUNT(*) FROM browser_share_saves_v2050 WHERE browser_share_id=?');$saves->execute([$shareId]);$saveCount=(int)$saves->fetchColumn();
    $research=$pdo->prepare('SELECT COUNT(*) FROM browser_research_queue_v2050 WHERE browser_share_id=?');$research->execute([$shareId]);$researchCount=(int)$research->fetchColumn();
    $visibility=trim((string)($row['visibility']??''))?:'legacy';
    vp3_search_upsert_document_v2090($pdo,[
        'document_type'=>'annotation','object_public_id'=>(string)$row['public_id'],'source_id'=>(int)($row['source_id']??0),
        'owner_user_id'=>(int)$row['sender_user_id'],'team_owner_user_id'=>(int)($row['team_owner_user_id']??0),'visibility'=>$visibility,
        'source_domain'=>(string)$row['source_domain'],'title'=>(string)$row['source_title'],
        'body'=>(string)$row['selected_text'].' '.(string)$row['user_note'],'url'=>url('/annotation.php?id='.rawurlencode((string)$row['public_id'])),
        'activity_score'=>$commentCount*3+$saveCount*2+$researchCount*3,'engagement_count'=>$commentCount+$saveCount+$researchCount,
        'source_changed'=>vp3_search_source_changed_v2090($pdo,(int)($row['source_id']??0)),
        'object_created_at'=>(string)$row['created_at'],'object_updated_at'=>(string)($row['updated_at']??$row['published_at']??$row['created_at']),
    ]);
}

function vp3_search_index_claim_v2090(PDO $pdo,string $claimPublicId): void
{
    $row=vp3_browser_trust_claim_row_v2080($pdo,$claimPublicId);
    if(!$row){vp3_search_tombstone_v2090($pdo,'claim',$claimPublicId);return;}
    $events=$pdo->prepare('SELECT COUNT(*) FROM browser_claim_events_v2080 WHERE claim_id=?');$events->execute([(int)$row['id']]);$eventCount=(int)$events->fetchColumn();
    vp3_search_upsert_document_v2090($pdo,[
        'document_type'=>'claim','object_public_id'=>(string)$row['public_id'],'source_id'=>(int)$row['source_id'],
        'owner_user_id'=>(int)$row['created_by_user_id'],'team_owner_user_id'=>(int)($row['team_owner_user_id']??0),'visibility'=>(string)$row['visibility'],
        'source_domain'=>(string)$row['source_domain'],'claim_status'=>(string)$row['claim_status'],'title'=>(string)$row['statement'],
        'body'=>(string)$row['rationale'].' '.(string)$row['resolution_note'],'url'=>url('/claim.php?id='.rawurlencode((string)$row['public_id'])),
        'activity_score'=>$eventCount*3+((string)$row['claim_status']==='under_review'?5:0),'engagement_count'=>$eventCount,
        'source_changed'=>vp3_search_source_changed_v2090($pdo,(int)$row['source_id']),
        'object_created_at'=>(string)$row['created_at'],'object_updated_at'=>(string)$row['updated_at'],
    ]);
}

function vp3_search_research_access_v2090(PDO $pdo,array $report,int $viewerUserId): bool
{
    if(!$report||!empty($report['deleted_at']))return false;
    if((string)$report['report_status']==='published'){
        $visibility=(string)$report['visibility'];
        if($visibility==='public')return true;
        if($visibility==='team')return $viewerUserId>0&&(int)($report['team_owner_user_id']??0)>0&&vp3_human_team_authorized_v370($pdo,(int)$report['team_owner_user_id'],$viewerUserId);
    }
    if($viewerUserId<1)return false;
    $stmt=$pdo->prepare('SELECT * FROM research_projects_v2060 WHERE id=? AND deleted_at IS NULL LIMIT 1');$stmt->execute([(int)$report['project_id']]);$project=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($project)&&vp3_research_project_role_v2060($pdo,$project,$viewerUserId)!=='';
}

function vp3_search_index_research_v2090(PDO $pdo,string $reportPublicId): void
{
    $row=vp3_research_report_row_v2060($pdo,$reportPublicId);
    if(!$row){vp3_search_tombstone_v2090($pdo,'research',$reportPublicId);return;}
    $sourceIds=[];$domain='';
    if((int)($row['current_version_id']??0)>0){
        $stmt=$pdo->prepare('SELECT rs.source_id,s.source_domain FROM research_report_version_sources_v2060 rs INNER JOIN browser_sources_v2050 s ON s.id=rs.source_id WHERE rs.report_version_id=?');
        $stmt->execute([(int)$row['current_version_id']]);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $src){$sourceIds[]=(int)$src['source_id'];if($domain==='')$domain=(string)$src['source_domain'];}
    }
    $sourceId=count(array_unique($sourceIds))===1?(int)$sourceIds[0]:0;
    vp3_search_upsert_document_v2090($pdo,[
        'document_type'=>'research','object_public_id'=>(string)$row['public_id'],'source_id'=>$sourceId,'owner_user_id'=>(int)$row['created_by_user_id'],
        'team_owner_user_id'=>(int)($row['team_owner_user_id']??0),'visibility'=>(string)$row['visibility'],'source_domain'=>$domain,
        'title'=>(string)$row['title'],'body'=>(string)$row['summary'],'url'=>url('/research-report.php?report='.rawurlencode((string)$row['public_id'])),
        'activity_score'=>max(1,(int)($row['current_version_no']??0))*4,'engagement_count'=>max(0,(int)($row['current_version_no']??0)),
        'source_changed'=>$sourceId>0?vp3_search_source_changed_v2090($pdo,$sourceId):false,
        'object_created_at'=>(string)$row['created_at'],'object_updated_at'=>(string)$row['updated_at'],
    ]);
}

function vp3_search_index_live_v2090(PDO $pdo,string $roomPublicId): void
{
    $row=vp3_live_room_row_v2070($pdo,$roomPublicId);
    if(!$row){vp3_search_tombstone_v2090($pdo,'live',$roomPublicId);return;}
    $members=$pdo->prepare('SELECT COUNT(*) FROM live_room_members_v2070 WHERE room_id=?');$members->execute([(int)$row['id']);$memberCount=(int)$members->fetchColumn();
    $messages=$pdo->prepare('SELECT COUNT(*) FROM live_room_messages_v2070 WHERE room_id=? AND deleted_at IS NULL');$messages->execute([(int)$row['id']);$messageCount=(int)$messages->fetchColumn();
    vp3_search_upsert_document_v2090($pdo,[
        'document_type'=>'live','object_public_id'=>(string)$row['public_id'],'source_id'=>(int)($row['source_id']??0),'owner_user_id'=>(int)$row['owner_user_id'],
        'team_owner_user_id'=>(int)($row['team_owner_user_id']??0),'visibility'=>(string)$row['room_scope'],'source_domain'=>(string)($row['source_domain']??''),
        'title'=>(string)$row['title'],'body'=>'Live Room '.(string)$row['room_status'],'url'=>url('/live-room.php?room='.rawurlencode((string)$row['public_id'])),
        'activity_score'=>$memberCount*2+$messageCount+((string)$row['room_status']==='active'?20:0),'engagement_count'=>$memberCount+$messageCount,
        'source_changed'=>(int)($row['source_id']??0)>0?vp3_search_source_changed_v2090($pdo,(int)$row['source_id']):false,
        'object_created_at'=>(string)$row['created_at'],'object_updated_at'=>(string)$row['updated_at'],
    ]);
}

function vp3_search_index_user_v2090(PDO $pdo,int $userId): void
{
    $stmt=$pdo->prepare('SELECT p.*,u.display_name,u.is_active FROM user_profiles p INNER JOIN users u ON u.id=p.user_id WHERE p.user_id=? LIMIT 1');$stmt->execute([$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row||empty($row['is_active'])||empty($row['is_public'])||trim((string)$row['username'])===''){
        if($row&&trim((string)$row['username'])!=='')vp3_search_tombstone_v2090($pdo,'user',(string)$row['username']);
        return;
    }
    vp3_search_upsert_document_v2090($pdo,[
        'document_type'=>'user','object_public_id'=>(string)$row['username'],'owner_user_id'=>$userId,'visibility'=>'public',
        'title'=>(string)$row['display_name'].' @'.(string)$row['username'],'body'=>(string)($row['bio']??''),'url'=>profile_public_url((string)$row['username']),
        'activity_score'=>1,'object_created_at'=>(string)$row['created_at'],'object_updated_at'=>(string)$row['updated_at'],
    ]);
}

function vp3_search_index_team_v2090(PDO $pdo,int $ownerUserId): void
{
    if(!table_exists('artist_workspaces_v181'))return;
    $stmt=$pdo->prepare('SELECT * FROM artist_workspaces_v181 WHERE artist_user_id=? LIMIT 1');$stmt->execute([$ownerUserId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)return;
    vp3_search_upsert_document_v2090($pdo,[
        'document_type'=>'team','object_public_id'=>(string)$ownerUserId,'owner_user_id'=>$ownerUserId,'team_owner_user_id'=>$ownerUserId,'visibility'=>'team',
        'title'=>(string)$row['workspace_name'],'body'=>(string)($row['bio']??''),'url'=>url('/chat.php'),'activity_score'=>1,
        'object_created_at'=>(string)$row['created_at'],'object_updated_at'=>(string)$row['updated_at'],
    ]);
}

function vp3_search_refresh_all_v2090(PDO $pdo,int $limit=VP3_SEARCH_INDEX_BATCH_V2090): array
{
    $limit=max(1,min(50000,$limit));$counts=['source'=>0,'annotation'=>0,'claim'=>0,'research'=>0,'live'=>0,'user'=>0,'team'=>0];
    $sets=[
        'source'=>['SELECT id FROM browser_sources_v2050 ORDER BY id DESC LIMIT '.$limit,'vp3_search_index_source_v2090'],
        'annotation'=>['SELECT id FROM browser_shares_v2010 WHERE deleted_at IS NULL ORDER BY id DESC LIMIT '.$limit,'vp3_search_index_annotation_v2090'],
        'claim'=>['SELECT public_id FROM browser_claims_v2080 WHERE deleted_at IS NULL ORDER BY id DESC LIMIT '.$limit,'vp3_search_index_claim_v2090'],
        'research'=>['SELECT public_id FROM research_reports_v2060 WHERE deleted_at IS NULL ORDER BY id DESC LIMIT '.$limit,'vp3_search_index_research_v2090'],
        'live'=>['SELECT public_id FROM live_rooms_v2070 WHERE deleted_at IS NULL ORDER BY id DESC LIMIT '.$limit,'vp3_search_index_live_v2090'],
        'user'=>['SELECT user_id FROM user_profiles WHERE is_public=1 AND username IS NOT NULL ORDER BY user_id DESC LIMIT '.$limit,'vp3_search_index_user_v2090'],
        'team'=>['SELECT artist_user_id FROM artist_workspaces_v181 ORDER BY id DESC LIMIT '.$limit,'vp3_search_index_team_v2090'],
    ];
    foreach($sets as $type=>[$sql,$fn]){
        if(($type==='claim'&&!table_exists('browser_claims_v2080'))||($type==='research'&&!table_exists('research_reports_v2060'))||($type==='live'&&!table_exists('live_rooms_v2070'))||($type==='user'&&!table_exists('user_profiles'))||($type==='team'&&!table_exists('artist_workspaces_v181')))continue;
        try{$rows=$pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)?:[];}catch(Throwable $e){continue;}
        foreach($rows as $id){try{$fn($pdo,is_numeric($id)?(int)$id:(string)$id);$counts[$type]++;}catch(Throwable $e){error_log('Search index '.$type.' failed: '.$e->getMessage());}}
    }
    return $counts;
}

function vp3_search_document_access_v2090(PDO $pdo,array $doc,int $viewerUserId): bool
{
    $type=(string)$doc['document_type'];$id=(string)$doc['object_public_id'];
    if($type==='source'){
        $source=vp3_browser_source_row_by_public_id_v2050($pdo,$id);
        return $source&&vp3_browser_trust_source_access_v2080($pdo,(int)$source['id'],$viewerUserId);
    }
    if($type==='annotation'){
        $row=vp3_browser_source_share_row_v2050($pdo,$id);
        return $row&&vp3_browser_source_share_authorized_v2050($pdo,$row,$viewerUserId);
    }
    if($type==='claim'){
        $row=vp3_browser_trust_claim_row_v2080($pdo,$id);
        return $row&&vp3_browser_trust_claim_access_v2080($pdo,$row,$viewerUserId);
    }
    if($type==='research'){
        $row=vp3_research_report_row_v2060($pdo,$id);
        return $row&&vp3_search_research_access_v2090($pdo,$row,$viewerUserId);
    }
    if($type==='live'){
        $row=vp3_live_room_row_v2070($pdo,$id);
        return $row&&vp3_live_room_access_v2070($pdo,$row,$viewerUserId,false);
    }
    if($type==='user'){
        $profile=profile_by_username($pdo,$id);
        return $profile&&!empty($profile['is_active'])&&!empty($profile['is_public']);
    }
    if($type==='team'){
        $owner=(int)$id;
        return $viewerUserId>0&&$owner>0&&vp3_human_team_authorized_v370($pdo,$owner,$viewerUserId);
    }
    return false;
}

function vp3_search_excerpt_v2090(string $value,string $query,int $max=260): string
{
    $value=vp3_search_clean_text_v2090($value,5000);if($value==='')return '';
    $lower=mb_strtolower($value);$tokens=vp3_search_tokens_v2090($query);$pos=false;
    foreach($tokens as $token){$p=mb_strpos($lower,$token);if($p!==false&&($pos===false||$p<$pos))$pos=$p;}
    $start=$pos===false?0:max(0,(int)$pos-80);$out=mb_substr($value,$start,$max);
    if($start>0)$out='…'.$out;if($start+mb_strlen($out)<mb_strlen($value))$out.='…';
    return $out;
}

function vp3_search_author_name_v2090(PDO $pdo,int $userId): string
{
    if($userId<1)return '';
    $stmt=$pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');$stmt->execute([$userId]);
    return (string)($stmt->fetchColumn()?:'');
}

function vp3_search_result_v2090(PDO $pdo,array $doc,int $viewerUserId,string $query): ?array
{
    if(!vp3_search_document_access_v2090($pdo,$doc,$viewerUserId)){vp3_search_tombstone_if_missing_v2090($pdo,$doc);return null;}
    $type=(string)$doc['document_type'];$id=(string)$doc['object_public_id'];$result=[
        'type'=>$type,'id'=>$id,'title'=>(string)$doc['title'],'snippet'=>vp3_search_excerpt_v2090((string)$doc['body'],$query),
        'domain'=>(string)$doc['source_domain'],'visibility'=>(string)$doc['visibility'],'status'=>(string)$doc['claim_status'],
        'url'=>(string)$doc['url'],'source_id'=>'','team_id'=>(int)($doc['team_owner_user_id']??0),
        'author'=>['id'=>(int)($doc['owner_user_id']??0),'name'=>vp3_search_author_name_v2090($pdo,(int)($doc['owner_user_id']??0))],
        'created_at'=>(string)$doc['object_created_at'],'updated_at'=>(string)$doc['object_updated_at'],
        'source_changed'=>!empty($doc['source_changed']),'engagement'=>(int)$doc['engagement_count'],'actions'=>['open'=>true],
    ];
    if((int)($doc['source_id']??0)>0){$stmt=$pdo->prepare('SELECT public_id FROM browser_sources_v2050 WHERE id=? LIMIT 1');$stmt->execute([(int)$doc['source_id']]);$result['source_id']=(string)($stmt->fetchColumn()?:'');}
    if($type==='source'){
        $result['title']=(string)$doc['source_domain'];$result['snippet']='Source · '.(string)$doc['source_domain'];$result['visibility']='';
        $result['actions']+=['follow_source'=>$viewerUserId>0,'file_claim'=>$viewerUserId>0];
    }elseif($type==='annotation'){
        $row=vp3_browser_source_share_row_v2050($pdo,$id);if(!$row)return null;$item=vp3_browser_source_item_v2050($pdo,$row,$viewerUserId,false);
        $result['title']=(string)($item['source_identity']['title']??$item['source_identity']['domain']??'Annotation');
        $result['snippet']=vp3_search_excerpt_v2090(trim((string)($item['selection']??'').' '.(string)($item['note']??'')),$query);
        $result['visibility']=(string)($item['publication']['visibility']??'legacy');$result['source_id']=(string)($item['source_identity']['id']??'');
        $result['actions']+=['save'=>true,'add_to_research'=>true,'file_claim'=>$viewerUserId>0];
    }elseif($type==='claim'){
        $row=vp3_browser_trust_claim_row_v2080($pdo,$id);if(!$row)return null;$claim=vp3_browser_trust_claim_public_v2080($pdo,$row,$viewerUserId,false);
        $result['title']=(string)$claim['statement'];$result['snippet']=vp3_search_excerpt_v2090((string)$row['rationale'],$query);$result['status']=(string)$claim['status'];$result['visibility']=(string)$claim['visibility'];$result['source_id']=(string)$claim['source_id'];
    }elseif($type==='research'){
        $row=vp3_research_report_row_v2060($pdo,$id);if(!$row)return null;$result['title']=(string)$row['title'];$result['snippet']=vp3_search_excerpt_v2090((string)$row['summary'],$query);$result['status']=(string)$row['report_status'];$result['visibility']=(string)$row['visibility'];
    }elseif($type==='live'){
        $row=vp3_live_room_row_v2070($pdo,$id);if(!$row)return null;$room=vp3_live_room_public_v2070($pdo,$row,$viewerUserId,false);
        $result['title']=(string)$room['title'];$result['snippet']='Live Room · '.ucfirst((string)$room['scope']).' · '.(int)$room['participant_count'].' present';$result['status']=(string)$room['status'];$result['visibility']=(string)$room['scope'];$result['source_id']=(string)($room['source']['id']??'');$result['actions']+=['join_live'=>(string)$room['status']==='active'];
    }elseif($type==='user'){
        $profile=profile_by_username($pdo,$id);if(!$profile)return null;$result['title']=(string)$profile['display_name'].' @'.(string)$profile['username'];$result['snippet']=vp3_search_excerpt_v2090((string)($profile['bio']??''),$query);$result['author']=['id'=>(int)$profile['user_id'],'name'=>(string)$profile['display_name']];
    }elseif($type==='team'){
        $owner=(int)$id;$stmt=$pdo->prepare('SELECT workspace_name,bio FROM artist_workspaces_v181 WHERE artist_user_id=? LIMIT 1');$stmt->execute([$owner]);$team=$stmt->fetch(PDO::FETCH_ASSOC);if(!$team)return null;$result['title']=(string)$team['workspace_name'];$result['snippet']=vp3_search_excerpt_v2090((string)($team['bio']??''),$query);$result['visibility']='team';$result['team_id']=$owner;
    }
    return $result;
}

function vp3_search_tombstone_if_missing_v2090(PDO $pdo,array $doc): void
{
    $type=(string)$doc['document_type'];$id=(string)$doc['object_public_id'];$missing=false;
    if($type==='source')$missing=!vp3_browser_source_row_by_public_id_v2050($pdo,$id);
    elseif($type==='annotation')$missing=!vp3_browser_source_share_row_v2050($pdo,$id);
    elseif($type==='claim')$missing=!vp3_browser_trust_claim_row_v2080($pdo,$id);
    elseif($type==='research')$missing=!vp3_research_report_row_v2060($pdo,$id);
    elseif($type==='live')$missing=!vp3_live_room_row_v2070($pdo,$id);
    elseif($type==='user')$missing=!profile_by_username($pdo,$id);
    elseif($type==='team'){$stmt=$pdo->prepare('SELECT 1 FROM artist_workspaces_v181 WHERE artist_user_id=? LIMIT 1');$stmt->execute([(int)$id]);$missing=!(bool)$stmt->fetchColumn();}
    if($missing)vp3_search_tombstone_v2090($pdo,$type,$id);
}

function vp3_search_score_v2090(PDO $pdo,array $doc,string $query,array $filters,int $viewerUserId): float
{
    $score=(float)min(60,(int)$doc['activity_score']);
    $q=mb_strtolower(trim($query));$title=mb_strtolower((string)$doc['title']);$body=mb_strtolower((string)$doc['body']);$domain=mb_strtolower((string)$doc['source_domain']);
    if($q!==''){
        if($title===$q)$score+=100;elseif(str_contains($title,$q))$score+=55;
        if($domain===$q)$score+=60;elseif($domain!==''&&str_contains($domain,$q))$score+=30;
        if(str_contains($body,$q))$score+=25;
        foreach(vp3_search_tokens_v2090($q) as $token){if(str_contains($title,$token))$score+=18;if(str_contains($domain,$token))$score+=12;if(str_contains($body,$token))$score+=6;}
    }
    if(!empty($filters['context_source_id'])&&(int)($doc['source_id']??0)>0){
        $stmt=$pdo->prepare('SELECT id FROM browser_sources_v2050 WHERE public_id=? LIMIT 1');$stmt->execute([(string)$filters['context_source_id']]);$ctx=(int)($stmt->fetchColumn()?:0);if($ctx>0&&$ctx===(int)$doc['source_id'])$score+=35;
    }
    if(!empty($filters['context_domain'])&&$domain===(string)$filters['context_domain'])$score+=14;
    if((string)$doc['document_type']==='live'&&str_contains((string)$doc['body'],'active'))$score+=15;
    if(!empty($doc['source_changed']))$score+=4;
    if($viewerUserId>0&&(int)($doc['source_id']??0)>0){$stmt=$pdo->prepare('SELECT 1 FROM browser_source_follows_v2050 WHERE user_id=? AND source_id=? LIMIT 1');$stmt->execute([$viewerUserId,(int)$doc['source_id']]);if($stmt->fetchColumn())$score+=12;}
    $ts=strtotime((string)$doc['object_updated_at']);if($ts!==false){$days=max(0,(time()-$ts)/86400);$score+=max(0,18-min(18,$days/5));}
    return round($score,3);
}

function vp3_search_record_recent_v2090(PDO $pdo,int $userId,string $query,array $filters,int $count): void
{
    if($userId<1||trim($query)==='')return;
    $json=vp3_search_filters_json_v2090($filters);$hash=hash('sha256',mb_strtolower(trim($query))."\n".$json);
    $pdo->prepare("INSERT INTO search_recent_queries_v2090(user_id,query_hash,query_text,filters_json,result_count,created_at,last_used_at)
      VALUES(?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE query_text=VALUES(query_text),filters_json=VALUES(filters_json),result_count=VALUES(result_count),last_used_at=UTC_TIMESTAMP()")
      ->execute([$userId,$hash,mb_substr(trim($query),0,500),$json,max(0,$count)]);
}

function vp3_search_query_v2090(PDO $pdo,int $viewerUserId,string $query,array $input=[],int $limit=50,bool $record=true): array
{
    $query=vp3_search_clean_text_v2090($query,500);$filters=vp3_search_filters_v2090($input);$limit=max(1,min(100,$limit));
    $where=['deleted_at IS NULL'];$params=[];
    $tokens=vp3_search_tokens_v2090($query);
    if($query!==''){
        $needle='%'.mb_strtolower($query).'%';$parts=['LOWER(title) LIKE ?','LOWER(body) LIKE ?','LOWER(source_domain) LIKE ?'];array_push($params,$needle,$needle,$needle);
        foreach(array_slice($tokens,0,6) as $token){$parts[]='LOWER(title) LIKE ?';$params[]='%'.$token.'%';$parts[]='LOWER(body) LIKE ?';$params[]='%'.$token.'%';}
        $where[]='('.implode(' OR ',$parts).')';
    }
    if($filters['types']){$ph=implode(',',array_fill(0,count($filters['types']),'?'));$where[]='document_type IN ('.$ph.')';array_push($params,...$filters['types']);}
    if($filters['visibility']!==''){$where[]='visibility=?';$params[]=$filters['visibility'];}
    if($filters['domain']!==''){$where[]='LOWER(source_domain) LIKE ?';$params[]='%'.$filters['domain'].'%';}
    if($filters['team_id']>0){$where[]='team_owner_user_id=?';$params[]=$filters['team_id'];}
    if($filters['author_id']>0){$where[]='owner_user_id=?';$params[]=$filters['author_id'];}
    if($filters['claim_status']!==''){$where[]='claim_status=?';$params[]=$filters['claim_status'];}
    if($filters['changed']!==''){$where[]='source_changed=?';$params[]=$filters['changed']==='1'?1:0;}
    if($filters['date_from']!==''){$where[]='object_created_at>=?';$params[]=$filters['date_from'].' 00:00:00';}
    if($filters['date_to']!==''){$where[]='object_created_at<=?';$params[]=$filters['date_to'].' 23:59:59';}
    if($filters['context_only']&&!empty($filters['context_source_id'])){
        $stmt=$pdo->prepare('SELECT id FROM browser_sources_v2050 WHERE public_id=? LIMIT 1');$stmt->execute([(string)$filters['context_source_id']]);$sourceId=(int)($stmt->fetchColumn()?:0);
        if($sourceId>0){$where[]='source_id=?';$params[]=$sourceId;}else{$where[]='1=0';}
    }
    $sql='SELECT * FROM search_documents_v2090 WHERE '.implode(' AND ',$where).' ORDER BY activity_score DESC,object_updated_at DESC,id DESC LIMIT '.VP3_SEARCH_CANDIDATES_V2090;
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$scored=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $doc){
        $result=vp3_search_result_v2090($pdo,$doc,$viewerUserId,$query);if(!$result)continue;
        $score=vp3_search_score_v2090($pdo,$doc,$query,$filters,$viewerUserId);$result['score']=$score;$scored[]=$result;
    }
    usort($scored,static fn(array $a,array $b): int=>($b['score']<=>$a['score'])?:strcmp((string)$b['updated_at'],(string)$a['updated_at']));
    $items=array_slice($scored,0,$limit);
    if($record)vp3_search_record_recent_v2090($pdo,$viewerUserId,$query,$filters,count($items));
    return ['query'=>$query,'filters'=>$filters,'items'=>$items,'count'=>count($items),'candidate_count'=>count($scored)];
}

function vp3_search_discover_v2090(PDO $pdo,int $viewerUserId,array $input=[]): array
{
    $filters=vp3_search_filters_v2090($input);
    $context=$filters['context_source_id']!==''?vp3_search_query_v2090($pdo,$viewerUserId,'',$filters+['context_only'=>true],30,false):['items'=>[]];
    $trending=vp3_search_query_v2090($pdo,$viewerUserId,'',['types'=>['source']],12,false);
    $active=vp3_search_query_v2090($pdo,$viewerUserId,'',['types'=>['annotation','claim','research','live']],30,false);
    return ['context'=>(array)$context['items'],'trending'=>(array)$trending['items'],'active'=>(array)$active['items']];
}

function vp3_search_recent_v2090(PDO $pdo,int $userId,int $limit=20): array
{
    if($userId<1)return [];$limit=max(1,min(50,$limit));
    $stmt=$pdo->prepare("SELECT query_text,filters_json,result_count,last_used_at FROM search_recent_queries_v2090 WHERE user_id=? ORDER BY last_used_at DESC LIMIT {$limit}");$stmt->execute([$userId]);
    return array_map(static function(array $row): array{$filters=json_decode((string)$row['filters_json'],true);return ['query'=>(string)$row['query_text'],'filters'=>is_array($filters)?$filters:[],'result_count'=>(int)$row['result_count'],'last_used_at'=>(string)$row['last_used_at']];},$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_search_saved_v2090(PDO $pdo,int $userId): array
{
    if($userId<1)return [];
    $stmt=$pdo->prepare('SELECT * FROM search_saved_queries_v2090 WHERE user_id=? ORDER BY updated_at DESC,id DESC');$stmt->execute([$userId]);
    return array_map(static function(array $row): array{$filters=json_decode((string)$row['filters_json'],true);return ['id'=>(string)$row['public_id'],'name'=>(string)$row['name'],'query'=>(string)$row['query_text'],'filters'=>is_array($filters)?$filters:[],'created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at']];},$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_search_save_query_v2090(PDO $pdo,int $userId,string $name,string $query,array $input): array
{
    if($userId<1)throw new RuntimeException('Sign in to save searches.');
    $name=vp3_search_clean_text_v2090($name,120);$query=vp3_search_clean_text_v2090($query,500);if($name===''||$query==='')throw new InvalidArgumentException('Saved search name and query are required.');
    $filters=vp3_search_filters_v2090($input);$public=vp3_search_uuid_v2090();
    $pdo->prepare("INSERT INTO search_saved_queries_v2090(public_id,user_id,name,query_text,filters_json,created_at,updated_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
      ->execute([$public,$userId,$name,$query,vp3_search_filters_json_v2090($filters)]);
    return ['id'=>$public,'name'=>$name,'query'=>$query,'filters'=>$filters];
}

function vp3_search_delete_saved_v2090(PDO $pdo,int $userId,string $publicId): void
{
    if($userId<1)return;$pdo->prepare('DELETE FROM search_saved_queries_v2090 WHERE public_id=? AND user_id=?')->execute([trim($publicId),$userId]);
}

function vp3_search_clear_recent_v2090(PDO $pdo,int $userId): void
{
    if($userId<1)return;$pdo->prepare('DELETE FROM search_recent_queries_v2090 WHERE user_id=?')->execute([$userId]);
}

function vp3_search_filter_options_v2090(PDO $pdo,int $userId): array
{
    $teams=[];
    if($userId>0)foreach(vp3_human_team_workspaces_v370($pdo,$userId) as $workspace)$teams[]=['id'=>(int)$workspace['owner_user_id'],'name'=>(string)$workspace['workspace_name']];
    return ['types'=>['source','annotation','claim','research','live','user','team'],'visibilities'=>['public','team','private','legacy'],'claim_statuses'=>['open','under_review','resolved','disputed','withdrawn'],'teams'=>$teams];
}

function vp3_search_ensure_schema_v2090(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_search_schema_ready_v2090($pdo))return;
    if(!vp3_browser_trust_schema_ready_v2080($pdo))throw new RuntimeException('Phase 9 must be installed before Search & Discovery.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS search_documents_v2090 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      document_type VARCHAR(32) NOT NULL,
      object_public_id VARCHAR(80) NOT NULL,
      source_id BIGINT UNSIGNED NULL,
      owner_user_id INT UNSIGNED NULL,
      team_owner_user_id INT UNSIGNED NULL,
      visibility VARCHAR(16) NOT NULL DEFAULT 'private',
      source_domain VARCHAR(253) NOT NULL DEFAULT '',
      claim_status VARCHAR(24) NOT NULL DEFAULT '',
      title VARCHAR(512) NOT NULL DEFAULT '',
      body MEDIUMTEXT NOT NULL,
      url VARCHAR(500) NOT NULL DEFAULT '',
      activity_score INT UNSIGNED NOT NULL DEFAULT 0,
      engagement_count INT UNSIGNED NOT NULL DEFAULT 0,
      source_changed TINYINT(1) NOT NULL DEFAULT 0,
      object_created_at DATETIME NOT NULL,
      object_updated_at DATETIME NOT NULL,
      indexed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      UNIQUE KEY uq_search_document_object (document_type,object_public_id),
      INDEX idx_search_document_type (document_type,deleted_at,object_updated_at,id),
      INDEX idx_search_document_source (source_id,deleted_at,object_updated_at,id),
      INDEX idx_search_document_owner (owner_user_id,deleted_at,object_updated_at,id),
      INDEX idx_search_document_team (team_owner_user_id,visibility,deleted_at,id),
      INDEX idx_search_document_domain (source_domain,deleted_at,id),
      INDEX idx_search_document_claim (claim_status,deleted_at,id),
      INDEX idx_search_document_changed (source_changed,deleted_at,id),
      CONSTRAINT fk_search_document_source FOREIGN KEY (source_id) REFERENCES browser_sources_v2050(id) ON DELETE SET NULL,
      CONSTRAINT fk_search_document_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_search_document_team FOREIGN KEY (team_owner_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS search_recent_queries_v2090 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      query_hash CHAR(64) NOT NULL,
      query_text VARCHAR(500) NOT NULL,
      filters_json TEXT NOT NULL,
      result_count INT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_search_recent_user_hash (user_id,query_hash),
      INDEX idx_search_recent_user (user_id,last_used_at,id),
      CONSTRAINT fk_search_recent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS search_saved_queries_v2090 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      name VARCHAR(120) NOT NULL,
      query_text VARCHAR(500) NOT NULL,
      filters_json TEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_search_saved_public (public_id),
      INDEX idx_search_saved_user (user_id,updated_at,id),
      CONSTRAINT fk_search_saved_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS search_index_events_v2090 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      document_type VARCHAR(32) NOT NULL,
      object_public_id VARCHAR(80) NOT NULL,
      event_type VARCHAR(40) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_search_index_event_public (public_id),
      INDEX idx_search_index_event_object (document_type,object_public_id,id),
      INDEX idx_search_index_event_created (created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    vp3_search_refresh_all_v2090($pdo,VP3_SEARCH_INDEX_BATCH_V2090);
}
