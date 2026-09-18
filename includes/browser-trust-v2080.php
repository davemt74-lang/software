<?php
declare(strict_types=1);

require_once __DIR__.'/live-rooms-v2070.php';

const VP3_BROWSER_TRUST_V2080='browser-trust-v2080-20260918';
const VP3_BROWSER_TRUST_BODY_MAX_V2080=12000;
const VP3_BROWSER_TRUST_DETAIL_MAX_V2080=8000;

function vp3_browser_trust_schema_ready_v2080(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && vp3_live_room_schema_ready_v2070($pdo)
        && table_exists('browser_source_change_events_v2080')
        && table_exists('browser_notification_preferences_v2080')
        && table_exists('browser_notifications_v2080')
        && table_exists('browser_claims_v2080')
        && table_exists('browser_claim_events_v2080')
        && table_exists('browser_moderation_reports_v2080')
        && table_exists('browser_moderation_actions_v2080');
}

function vp3_browser_trust_require_ready_v2080(?PDO $pdo=null): PDO
{
    $pdo??=db();
    if(!$pdo||!vp3_browser_trust_schema_ready_v2080($pdo))throw new RuntimeException('Browser Trust & Change Intelligence is not ready. Run the current database upgrade.');
    return $pdo;
}

function vp3_browser_trust_uuid_v2080(): string{return vp3_extension_uuid_v2000();}

function vp3_browser_trust_user_v2080(PDO $pdo,int $userId): ?array
{
    if($userId<1)return null;
    $stmt=$pdo->prepare('SELECT id,email,display_name,role,is_active FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_trust_is_moderator_v2080(PDO $pdo,int $userId): bool
{
    $user=vp3_browser_trust_user_v2080($pdo,$userId);
    return $user && (int)($user['is_active']??0)===1 && user_has_role('admin',$user);
}

function vp3_browser_trust_pref_v2080(PDO $pdo,int $userId,string $type): bool
{
    $type=strtolower(trim($type));
    if($userId<1||$type==='')return false;
    $stmt=$pdo->prepare('SELECT enabled FROM browser_notification_preferences_v2080 WHERE user_id=? AND notification_type=? LIMIT 1');
    $stmt->execute([$userId,$type]);
    $value=$stmt->fetchColumn();
    return $value===false?true:(bool)$value;
}

function vp3_browser_trust_preferences_v2080(PDO $pdo,int $userId): array
{
    if($userId<1)throw new RuntimeException('Sign in to manage notification preferences.');
    $types=['source_changes','replies','follows','live','claims','moderation'];
    $out=[];
    foreach($types as $type)$out[$type]=vp3_browser_trust_pref_v2080($pdo,$userId,$type);
    return $out;
}

function vp3_browser_trust_set_preference_v2080(PDO $pdo,int $userId,string $type,bool $enabled): array
{
    $allowed=['source_changes','replies','follows','live','claims','moderation'];
    $type=strtolower(trim($type));
    if($userId<1)throw new RuntimeException('Sign in to manage notification preferences.');
    if(!in_array($type,$allowed,true))throw new InvalidArgumentException('Unknown notification preference.');
    $pdo->prepare("INSERT INTO browser_notification_preferences_v2080(user_id,notification_type,enabled,created_at,updated_at)
      VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_at=UTC_TIMESTAMP()")
      ->execute([$userId,$type,$enabled?1:0]);
    return ['type'=>$type,'enabled'=>$enabled,'preferences'=>vp3_browser_trust_preferences_v2080($pdo,$userId)];
}

function vp3_browser_trust_notify_v2080(PDO $pdo,int $userId,string $type,string $eventKey,string $title,string $body,string $targetUrl='',?int $sourceId=null,?int $claimId=null,?int $reportId=null): void
{
    if($userId<1||!vp3_browser_trust_pref_v2080($pdo,$userId,$type))return;
    $eventKey=mb_substr(trim($eventKey),0,190);
    if($eventKey==='')return;
    $stmt=$pdo->prepare("INSERT IGNORE INTO browser_notifications_v2080
      (user_id,event_key,notification_type,source_id,claim_id,report_id,title,body,target_url,is_read,created_at)
      VALUES(?,?,?,?,?,?,?,?,?,0,UTC_TIMESTAMP())");
    $stmt->execute([
        $userId,$eventKey,mb_substr($type,0,40),$sourceId?:null,$claimId?:null,$reportId?:null,
        mb_substr(trim($title),0,190),mb_substr(trim($body),0,1000),mb_substr(trim($targetUrl),0,500)
    ]);
    if($stmt->rowCount()>0){
        $id=(int)$pdo->lastInsertId();
        if(function_exists('create_notification')){
            create_notification($userId,'browser_'.$type,$title,$body,$targetUrl,'browser_phase9',$id);
        }
    }
}

function vp3_browser_trust_notification_public_v2080(array $row): array
{
    return [
        'id'=>(int)$row['id'],
        'type'=>(string)$row['notification_type'],
        'title'=>(string)$row['title'],
        'body'=>(string)$row['body'],
        'target_url'=>(string)$row['target_url'],
        'read'=>!empty($row['is_read']),
        'created_at'=>(string)$row['created_at'],
    ];
}

function vp3_browser_trust_notifications_v2080(PDO $pdo,int $userId,int $limit=50): array
{
    if($userId<1)throw new RuntimeException('Sign in to view notifications.');
    $limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT * FROM browser_notifications_v2080 WHERE user_id=? ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute([$userId]);
    $items=array_map('vp3_browser_trust_notification_public_v2080',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
    $count=$pdo->prepare('SELECT COUNT(*) FROM browser_notifications_v2080 WHERE user_id=? AND is_read=0');
    $count->execute([$userId]);
    return ['items'=>$items,'unread'=>(int)$count->fetchColumn()];
}

function vp3_browser_trust_mark_notification_v2080(PDO $pdo,int $userId,int $notificationId=0,bool $all=false): array
{
    if($userId<1)throw new RuntimeException('Sign in to manage notifications.');
    if($all){
        $pdo->prepare('UPDATE browser_notifications_v2080 SET is_read=1,read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE user_id=? AND is_read=0')->execute([$userId]);
    }elseif($notificationId>0){
        $pdo->prepare('UPDATE browser_notifications_v2080 SET is_read=1,read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE id=? AND user_id=?')->execute([$notificationId,$userId]);
    }
    return vp3_browser_trust_notifications_v2080($pdo,$userId,50);
}

function vp3_browser_trust_source_version_v2080(PDO $pdo,int $versionId): ?array
{
    if($versionId<1)return null;
    $stmt=$pdo->prepare('SELECT id,public_id,source_id,content_hash,version_basis,canonical_url,source_title,captured_at,created_at FROM browser_source_versions_v2050 WHERE id=? LIMIT 1');
    $stmt->execute([$versionId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_trust_version_public_v2080(?array $row): ?array
{
    if(!$row)return null;
    return [
        'id'=>(string)$row['public_id'],
        'hash'=>(string)$row['content_hash'],
        'hash_short'=>substr((string)$row['content_hash'],0,12),
        'basis'=>(string)$row['version_basis'],
        'title'=>(string)$row['source_title'],
        'captured_at'=>(string)$row['captured_at'],
    ];
}

function vp3_browser_trust_source_change_public_v2080(PDO $pdo,array $row): array
{
    return [
        'id'=>(string)$row['public_id'],
        'source_id'=>(string)($row['source_public_id']??''),
        'change_kind'=>(string)$row['change_kind'],
        'summary'=>(string)$row['summary'],
        'from'=>vp3_browser_trust_version_public_v2080(vp3_browser_trust_source_version_v2080($pdo,(int)($row['from_version_id']??0))),
        'to'=>vp3_browser_trust_version_public_v2080(vp3_browser_trust_source_version_v2080($pdo,(int)($row['to_version_id']??0))),
        'observed_at'=>(string)$row['observed_at'],
    ];
}

function vp3_browser_trust_source_history_v2080(PDO $pdo,int $viewerUserId,string $sourcePublicId,int $limit=25): array
{
    $source=vp3_browser_source_row_by_public_id_v2050($pdo,trim($sourcePublicId));
    if(!$source)throw new RuntimeException('Source was not found.');
    $limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT e.*,s.public_id source_public_id FROM browser_source_change_events_v2080 e
      INNER JOIN browser_sources_v2050 s ON s.id=e.source_id WHERE e.source_id=? ORDER BY e.id DESC LIMIT {$limit}");
    $stmt->execute([(int)$source['id']]);
    return [
        'source'=>vp3_browser_source_public_source_v2050($pdo,$source,$viewerUserId),
        'current_version'=>vp3_browser_trust_version_public_v2080(vp3_browser_trust_source_version_v2080($pdo,(int)($source['current_version_id']??0))),
        'changes'=>array_map(fn(array $row): array=>vp3_browser_trust_source_change_public_v2080($pdo,$row),$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]),
    ];
}

function vp3_browser_trust_observe_source_v2080(PDO $pdo,int $userId,string $url,string $canonicalUrl,string $title,string $contentHash): array
{
    if($userId<1)throw new RuntimeException('Sign in to observe source changes.');
    $contentHash=strtolower(trim($contentHash));
    if(!preg_match('/^[a-f0-9]{64}$/',$contentHash))throw new InvalidArgumentException('A current page fingerprint is required.');
    $identity=vp3_browser_source_identity_v2050($url,$canonicalUrl,$title);
    $source=vp3_browser_source_ensure_v2050($pdo,$identity);
    $fromId=(int)($source['current_version_id']??0);
    $from=vp3_browser_trust_source_version_v2080($pdo,$fromId);
    if($from && (string)$from['version_basis']==='page_text_sha256' && hash_equals((string)$from['content_hash'],$contentHash)){
        return ['changed'=>false]+vp3_browser_trust_source_history_v2080($pdo,$userId,(string)$source['public_id'],10);
    }

    $stmt=$pdo->prepare("SELECT id FROM browser_source_versions_v2050 WHERE source_id=? AND content_hash=? AND version_basis='page_text_sha256' LIMIT 1");
    $stmt->execute([(int)$source['id'],$contentHash]);
    $toId=(int)($stmt->fetchColumn()?:0);
    if($toId<1){
        $public=vp3_browser_trust_uuid_v2080();
        $pdo->prepare("INSERT INTO browser_source_versions_v2050(public_id,source_id,content_hash,version_basis,canonical_url,source_title,captured_at,created_at)
          VALUES(?,?,?,'page_text_sha256',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
          ->execute([$public,(int)$source['id'],$contentHash,(string)$identity['canonical_url'],mb_substr((string)$identity['title'],0,512)]);
        $toId=(int)$pdo->lastInsertId();
    }

    $pdo->prepare('UPDATE browser_sources_v2050 SET current_version_id=?,last_seen_at=UTC_TIMESTAMP() WHERE id=?')->execute([$toId,(int)$source['id']]);
    $event=null;
    if($fromId>0 && $fromId!==$toId){
        $eventPublic=vp3_browser_trust_uuid_v2080();
        $summary='Content fingerprint changed from '.substr((string)($from['content_hash']??''),0,12).' to '.substr($contentHash,0,12).'.';
        $insert=$pdo->prepare("INSERT IGNORE INTO browser_source_change_events_v2080
          (public_id,source_id,from_version_id,to_version_id,observed_by_user_id,change_kind,summary,observed_at)
          VALUES(?,?,?,?,?,'content_changed',?,UTC_TIMESTAMP())");
        $insert->execute([$eventPublic,(int)$source['id'],$fromId,$toId,$userId,$summary]);
        $eventId=(int)$pdo->lastInsertId();
        if($eventId>0){
            $rowStmt=$pdo->prepare("SELECT e.*,s.public_id source_public_id FROM browser_source_change_events_v2080 e INNER JOIN browser_sources_v2050 s ON s.id=e.source_id WHERE e.id=?");
            $rowStmt->execute([$eventId]);$eventRow=$rowStmt->fetch(PDO::FETCH_ASSOC);
            if(is_array($eventRow))$event=vp3_browser_trust_source_change_public_v2080($pdo,$eventRow);
            $followers=$pdo->prepare('SELECT user_id FROM browser_source_follows_v2050 WHERE source_id=?');
            $followers->execute([(int)$source['id']]);
            foreach(array_map('intval',$followers->fetchAll(PDO::FETCH_COLUMN)?:[]) as $followerId){
                vp3_browser_trust_notify_v2080(
                    $pdo,$followerId,'source_changes','source-change:'.$eventId,
                    'Source changed',
                    ((string)$identity['title']!==''?(string)$identity['title']:(string)$identity['domain']).' has a new observed version.',
                    '/source.php?source='.rawurlencode((string)$source['public_id']),
                    (int)$source['id']
                );
            }
        }
    }
    return ['changed'=>$event!==null,'change'=>$event]+vp3_browser_trust_source_history_v2080($pdo,$userId,(string)$source['public_id'],10);
}

function vp3_browser_trust_claim_row_v2080(PDO $pdo,string $publicId): ?array
{
    $stmt=$pdo->prepare("SELECT c.*,u.display_name creator_name,s.public_id source_public_id,s.source_domain,s.source_title,
      bs.public_id browser_share_public_id FROM browser_claims_v2080 c
      INNER JOIN users u ON u.id=c.created_by_user_id
      INNER JOIN browser_sources_v2050 s ON s.id=c.source_id
      LEFT JOIN browser_shares_v2010 bs ON bs.id=c.browser_share_id
      WHERE c.public_id=? AND c.deleted_at IS NULL LIMIT 1");
    $stmt->execute([trim($publicId)]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_browser_trust_claim_access_v2080(PDO $pdo,array $claim,int $userId): bool
{
    if((int)$claim['created_by_user_id']===$userId&&$userId>0)return true;
    if(vp3_browser_trust_is_moderator_v2080($pdo,$userId))return true;
    $visibility=(string)$claim['visibility'];
    if($visibility==='public')return true;
    return $visibility==='team'&&$userId>0&&(int)$claim['team_owner_user_id']>0&&vp3_human_team_authorized_v370($pdo,(int)$claim['team_owner_user_id'],$userId);
}

function vp3_browser_trust_claim_events_v2080(PDO $pdo,int $claimId): array
{
    $stmt=$pdo->prepare("SELECT e.public_id,e.event_type,e.from_status,e.to_status,e.note,e.created_at,u.display_name actor_name
      FROM browser_claim_events_v2080 e INNER JOIN users u ON u.id=e.actor_user_id WHERE e.claim_id=? ORDER BY e.id ASC");
    $stmt->execute([$claimId]);
    return array_map(static fn(array $r): array=>[
        'id'=>(string)$r['public_id'],'type'=>(string)$r['event_type'],'from'=>(string)$r['from_status'],'to'=>(string)$r['to_status'],
        'note'=>(string)$r['note'],'actor'=>(string)$r['actor_name'],'created_at'=>(string)$r['created_at']
    ],$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function vp3_browser_trust_claim_public_v2080(PDO $pdo,array $claim,int $viewerUserId,bool $events=false): array
{
    if(!vp3_browser_trust_claim_access_v2080($pdo,$claim,$viewerUserId))throw new RuntimeException('Claim is not available.');
    $out=[
        'id'=>(string)$claim['public_id'],
        'statement'=>(string)$claim['statement'],
        'rationale'=>(string)$claim['rationale'],
        'status'=>(string)$claim['claim_status'],
        'visibility'=>(string)$claim['visibility'],
        'team_id'=>(int)($claim['team_owner_user_id']??0),
        'source_id'=>(string)$claim['source_public_id'],
        'source_domain'=>(string)$claim['source_domain'],
        'browser_share_id'=>(string)($claim['browser_share_public_id']??''),
        'creator'=>['id'=>(int)$claim['created_by_user_id'],'name'=>(string)$claim['creator_name']],
        'resolution_note'=>(string)$claim['resolution_note'],
        'created_at'=>(string)$claim['created_at'],
        'updated_at'=>(string)$claim['updated_at'],
        'url'=>url('/claim.php?id='.rawurlencode((string)$claim['public_id'])),
        'can_moderate'=>vp3_browser_trust_is_moderator_v2080($pdo,$viewerUserId),
        'can_withdraw'=>$viewerUserId>0&&(int)$claim['created_by_user_id']===$viewerUserId&&!in_array((string)$claim['claim_status'],['resolved','withdrawn'],true),
    ];
    if($events)$out['events']=vp3_browser_trust_claim_events_v2080($pdo,(int)$claim['id']);
    return $out;
}

function vp3_browser_trust_claims_for_source_v2080(PDO $pdo,int $viewerUserId,string $sourcePublicId,int $limit=50): array
{
    $source=vp3_browser_source_row_by_public_id_v2050($pdo,trim($sourcePublicId));
    if(!$source)return [];
    $limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT c.public_id FROM browser_claims_v2080 c WHERE c.source_id=? AND c.deleted_at IS NULL ORDER BY c.id DESC LIMIT {$limit}");
    $stmt->execute([(int)$source['id']]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $publicId){
        $row=vp3_browser_trust_claim_row_v2080($pdo,(string)$publicId);
        if($row&&vp3_browser_trust_claim_access_v2080($pdo,$row,$viewerUserId))$out[]=vp3_browser_trust_claim_public_v2080($pdo,$row,$viewerUserId,false);
    }
    return $out;
}

function vp3_browser_trust_user_claims_v2080(PDO $pdo,int $userId,int $limit=100): array
{
    if($userId<1)throw new RuntimeException('Sign in to view claims.');
    $limit=max(1,min(200,$limit));
    $stmt=$pdo->prepare("SELECT public_id FROM browser_claims_v2080 WHERE created_by_user_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute([$userId]);$out=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $publicId){$row=vp3_browser_trust_claim_row_v2080($pdo,(string)$publicId);if($row)$out[]=vp3_browser_trust_claim_public_v2080($pdo,$row,$userId,false);}
    return $out;
}

function vp3_browser_trust_claim_create_v2080(PDO $pdo,int $userId,array $input): array
{
    if($userId<1)throw new RuntimeException('Sign in to file a claim.');
    $source=vp3_browser_source_row_by_public_id_v2050($pdo,trim((string)($input['source_id']??'')));
    if(!$source)throw new RuntimeException('Source was not found.');
    $statement=trim(str_replace("\0",'',(string)($input['statement']??'')));
    $rationale=trim(str_replace("\0",'',(string)($input['rationale']??'')));
    if($statement===''||mb_strlen($statement)>1000)throw new InvalidArgumentException('Claim statement must be between 1 and 1,000 characters.');
    if(strlen($rationale)>VP3_BROWSER_TRUST_BODY_MAX_V2080)throw new InvalidArgumentException('Claim rationale is too long.');
    $visibility=strtolower(trim((string)($input['visibility']??'public')));
    if(!in_array($visibility,['private','team','public'],true))throw new InvalidArgumentException('Choose Private, Team, or Public claim visibility.');
    $teamId=max(0,(int)($input['team_id']??0));
    if($visibility==='team'&&($teamId<1||!vp3_human_team_authorized_v370($pdo,$teamId,$userId)))throw new RuntimeException('Choose a Team workspace you can access.');
    if($visibility!=='team')$teamId=0;

    $shareId=null;$sourceVersionId=(int)($source['current_version_id']??0)?:null;
    $sharePublic=trim((string)($input['browser_share_id']??''));
    if($sharePublic!==''){
        $share=vp3_browser_source_share_row_v2050($pdo,$sharePublic);
        if(!$share||!vp3_browser_source_share_authorized_v2050($pdo,$share,$userId)||(int)$share['source_id']!==(int)$source['id'])throw new RuntimeException('Annotation evidence is not available on this source.');
        $shareId=(int)$share['id'];$sourceVersionId=(int)($share['source_version_id']??0)?:$sourceVersionId;
    }
    if(!$sourceVersionId)throw new RuntimeException('Observe or annotate this source before filing a claim.');

    $public=vp3_browser_trust_uuid_v2080();
    $pdo->prepare("INSERT INTO browser_claims_v2080(public_id,source_id,source_version_id,browser_share_id,created_by_user_id,team_owner_user_id,visibility,statement,rationale,claim_status,resolution_note,created_at,updated_at)
      VALUES(?,?,?,?,?,?,?,?,?,'open','',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
      ->execute([$public,(int)$source['id'],$sourceVersionId,$shareId,$userId,$teamId?:null,$visibility,$statement,$rationale]);
    $claimId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO browser_claim_events_v2080(public_id,claim_id,actor_user_id,event_type,from_status,to_status,note,created_at)
      VALUES(?,?,?,'created','','open','',UTC_TIMESTAMP())")->execute([vp3_browser_trust_uuid_v2080(),$claimId,$userId]);
    $row=vp3_browser_trust_claim_row_v2080($pdo,$public);
    if(!$row)throw new RuntimeException('Claim could not be reloaded.');
    $followers=$pdo->prepare('SELECT user_id FROM browser_source_follows_v2050 WHERE source_id=?');
    $followers->execute([(int)$source['id']]);
    foreach(array_map('intval',$followers->fetchAll(PDO::FETCH_COLUMN)?:[]) as $followerId){
        if($followerId<1||$followerId===$userId||!vp3_browser_trust_claim_access_v2080($pdo,$row,$followerId))continue;
        vp3_browser_trust_notify_v2080(
            $pdo,$followerId,'claims','claim-created:'.$claimId.':'.$followerId,'New claim on a followed source',
            mb_substr($statement,0,500),'/claim.php?id='.rawurlencode($public),(int)$source['id'],$claimId
        );
    }
    return vp3_browser_trust_claim_public_v2080($pdo,$row,$userId,true);
}

function vp3_browser_trust_claim_status_v2080(PDO $pdo,int $userId,string $claimPublicId,string $status,string $note=''): array
{
    $claim=vp3_browser_trust_claim_row_v2080($pdo,$claimPublicId);
    if(!$claim||!vp3_browser_trust_claim_access_v2080($pdo,$claim,$userId))throw new RuntimeException('Claim is not available.');
    $status=strtolower(trim($status));$allowed=['open','under_review','resolved','disputed','withdrawn'];
    if(!in_array($status,$allowed,true))throw new InvalidArgumentException('Unknown claim status.');
    $moderator=vp3_browser_trust_is_moderator_v2080($pdo,$userId);
    $owner=(int)$claim['created_by_user_id']===$userId;
    if(!$moderator&&!($owner&&$status==='withdrawn'))throw new RuntimeException('Only a moderator can change this claim status.');
    $from=(string)$claim['claim_status'];$note=mb_substr(trim($note),0,4000);
    if($from===$status)return vp3_browser_trust_claim_public_v2080($pdo,$claim,$userId,true);
    $pdo->prepare("UPDATE browser_claims_v2080 SET claim_status=?,resolution_note=?,resolved_by_user_id=?,resolved_at=CASE WHEN ? IN ('resolved','disputed','withdrawn') THEN UTC_TIMESTAMP() ELSE NULL END,updated_at=UTC_TIMESTAMP() WHERE id=?")
      ->execute([$status,$note,$moderator?$userId:null,$status,(int)$claim['id']]);
    $pdo->prepare("INSERT INTO browser_claim_events_v2080(public_id,claim_id,actor_user_id,event_type,from_status,to_status,note,created_at)
      VALUES(?,?,?,'status_changed',?,?,?,UTC_TIMESTAMP())")->execute([vp3_browser_trust_uuid_v2080(),(int)$claim['id'],$userId,$from,$status,$note]);
    if((int)$claim['created_by_user_id']!==$userId){
        vp3_browser_trust_notify_v2080($pdo,(int)$claim['created_by_user_id'],'claims','claim-status:'.(int)$claim['id'].':'.$status,'Claim updated','Your claim is now '.str_replace('_',' ',$status).'.','/claim.php?id='.rawurlencode((string)$claim['public_id']),(int)$claim['source_id'],(int)$claim['id']);
    }
    $fresh=vp3_browser_trust_claim_row_v2080($pdo,$claimPublicId);
    return vp3_browser_trust_claim_public_v2080($pdo,$fresh?:$claim,$userId,true);
}

function vp3_browser_trust_validate_report_target_v2080(PDO $pdo,int $userId,string $type,string $publicId): void
{
    if($type==='annotation'){
        $row=vp3_browser_source_share_row_v2050($pdo,$publicId);
        if(!$row||!vp3_browser_source_share_authorized_v2050($pdo,$row,$userId))throw new RuntimeException('Reported annotation is not available.');
        return;
    }
    if($type==='comment'){
        $stmt=$pdo->prepare('SELECT browser_share_id FROM browser_share_comments_v2050 WHERE public_id=? AND deleted_at IS NULL LIMIT 1');$stmt->execute([$publicId]);$shareId=(int)($stmt->fetchColumn()?:0);
        $row=$shareId>0?vp3_browser_source_share_row_by_id_v2050($pdo,$shareId):null;
        if(!$row||!vp3_browser_source_share_authorized_v2050($pdo,$row,$userId))throw new RuntimeException('Reported comment is not available.');
        return;
    }
    if($type==='live_message'){
        $stmt=$pdo->prepare('SELECT m.room_id,r.public_id room_public FROM live_room_messages_v2070 m INNER JOIN live_rooms_v2070 r ON r.id=m.room_id WHERE m.public_id=? AND m.deleted_at IS NULL LIMIT 1');
        $stmt->execute([$publicId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Reported Live message is not available.');
        vp3_live_room_require_v2070($pdo,(string)$row['room_public'],$userId,false);return;
    }
    if($type==='claim'){
        $claim=vp3_browser_trust_claim_row_v2080($pdo,$publicId);
        if(!$claim||!vp3_browser_trust_claim_access_v2080($pdo,$claim,$userId))throw new RuntimeException('Reported claim is not available.');
        return;
    }
    throw new InvalidArgumentException('Unsupported report target.');
}

function vp3_browser_trust_report_create_v2080(PDO $pdo,int $userId,string $type,string $publicId,string $reason,string $detail=''): array
{
    if($userId<1)throw new RuntimeException('Sign in to report content.');
    $type=strtolower(trim($type));$publicId=trim($publicId);$reason=trim($reason);$detail=trim($detail);
    if($publicId===''||$reason===''||mb_strlen($reason)>190||strlen($detail)>VP3_BROWSER_TRUST_DETAIL_MAX_V2080)throw new InvalidArgumentException('Report reason or detail is invalid.');
    vp3_browser_trust_validate_report_target_v2080($pdo,$userId,$type,$publicId);
    $dupe=$pdo->prepare("SELECT public_id FROM browser_moderation_reports_v2080 WHERE reporter_user_id=? AND target_type=? AND target_public_id=? AND report_status IN ('open','under_review') ORDER BY id DESC LIMIT 1");
    $dupe->execute([$userId,$type,$publicId]);$existing=(string)($dupe->fetchColumn()?:'');
    if($existing!=='')return ['id'=>$existing,'status'=>'open','duplicate'=>true];
    $public=vp3_browser_trust_uuid_v2080();
    $pdo->prepare("INSERT INTO browser_moderation_reports_v2080(public_id,reporter_user_id,target_type,target_public_id,reason,detail,report_status,created_at,updated_at)
      VALUES(?,?,?,?,?,?,'open',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$public,$userId,$type,$publicId,$reason,$detail]);
    $id=(int)$pdo->lastInsertId();
    if(function_exists('create_notification_for_permission')){
        create_notification_for_permission('users.manage','browser_moderation','New moderation report',$reason,'/moderation.php','browser_report',$id);
    }
    return ['id'=>$public,'status'=>'open','duplicate'=>false];
}

function vp3_browser_trust_reports_v2080(PDO $pdo,int $moderatorUserId,int $limit=100): array
{
    if(!vp3_browser_trust_is_moderator_v2080($pdo,$moderatorUserId))throw new RuntimeException('Moderator access is required.');
    $limit=max(1,min(250,$limit));
    $rows=$pdo->query("SELECT r.*,u.display_name reporter_name FROM browser_moderation_reports_v2080 r INNER JOIN users u ON u.id=r.reporter_user_id ORDER BY FIELD(r.report_status,'open','under_review','actioned','dismissed'),r.id DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC)?:[];
    return array_map(static fn(array $r): array=>[
        'id'=>(string)$r['public_id'],'target_type'=>(string)$r['target_type'],'target_id'=>(string)$r['target_public_id'],
        'reason'=>(string)$r['reason'],'detail'=>(string)$r['detail'],'status'=>(string)$r['report_status'],
        'reporter'=>(string)$r['reporter_name'],'created_at'=>(string)$r['created_at'],'updated_at'=>(string)$r['updated_at']
    ],$rows);
}

function vp3_browser_trust_report_action_v2080(PDO $pdo,int $moderatorUserId,string $reportPublicId,string $status,string $actionType,string $note=''): array
{
    if(!vp3_browser_trust_is_moderator_v2080($pdo,$moderatorUserId))throw new RuntimeException('Moderator access is required.');
    $status=strtolower(trim($status));if(!in_array($status,['under_review','actioned','dismissed'],true))throw new InvalidArgumentException('Unknown moderation status.');
    $stmt=$pdo->prepare('SELECT * FROM browser_moderation_reports_v2080 WHERE public_id=? LIMIT 1');$stmt->execute([trim($reportPublicId)]);$report=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$report)throw new RuntimeException('Moderation report was not found.');
    $note=mb_substr(trim($note),0,4000);$actionType=mb_substr(trim($actionType)?:$status,0,80);
    $pdo->prepare('UPDATE browser_moderation_reports_v2080 SET report_status=?,assigned_to_user_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$status,$moderatorUserId,(int)$report['id']]);
    $pdo->prepare("INSERT INTO browser_moderation_actions_v2080(public_id,report_id,moderator_user_id,action_type,note,created_at)
      VALUES(?,?,?,?,?,UTC_TIMESTAMP())")->execute([vp3_browser_trust_uuid_v2080(),(int)$report['id'],$moderatorUserId,$actionType,$note]);
    vp3_browser_trust_notify_v2080($pdo,(int)$report['reporter_user_id'],'moderation','moderation-report:'.(int)$report['id'].':'.$status,'Report updated','Your report is now '.str_replace('_',' ',$status).'.','/notifications.php',null,null,(int)$report['id']);
    return ['id'=>(string)$report['public_id'],'status'=>$status];
}

function vp3_browser_trust_notify_comment_v2080(PDO $pdo,array $share,int $actorUserId,string $commentPublicId,string $parentPublicId=''): void
{
    if(!vp3_browser_trust_schema_ready_v2080($pdo))return;
    $recipients=[(int)($share['sender_user_id']??0)];
    if($parentPublicId!==''){
        $stmt=$pdo->prepare('SELECT user_id FROM browser_share_comments_v2050 WHERE public_id=? LIMIT 1');$stmt->execute([$parentPublicId]);$recipients[]=(int)($stmt->fetchColumn()?:0);
    }
    foreach(array_unique($recipients) as $uid){
        if($uid<1||$uid===$actorUserId)continue;
        vp3_browser_trust_notify_v2080($pdo,$uid,'replies','comment:'.$commentPublicId.':'.$uid,'New annotation reply','Someone replied to an annotation you are following.','/annotation.php?id='.rawurlencode((string)$share['public_id']),(int)($share['source_id']??0));
    }
}

function vp3_browser_trust_notify_follow_v2080(PDO $pdo,int $actorUserId,int $followedUserId): void
{
    if(!vp3_browser_trust_schema_ready_v2080($pdo)||$actorUserId<1||$followedUserId<1||$actorUserId===$followedUserId)return;
    $actor=vp3_browser_trust_user_v2080($pdo,$actorUserId);
    vp3_browser_trust_notify_v2080($pdo,$followedUserId,'follows','follow:'.$actorUserId.':'.$followedUserId,'New follower',((string)($actor['display_name']??'A VP3 user')).' followed you.','/profile.php?id='.$actorUserId);
}

function vp3_browser_trust_notify_live_v2080(PDO $pdo,array $room,int $actorUserId,string $event): void
{
    if(!vp3_browser_trust_schema_ready_v2080($pdo))return;
    $owner=(int)($room['owner_user_id']??0);if($owner<1||$owner===$actorUserId)return;
    $actor=vp3_browser_trust_user_v2080($pdo,$actorUserId);
    $label=$event==='joined'?'joined':'posted in';
    vp3_browser_trust_notify_v2080($pdo,$owner,'live','live:'.(int)$room['id'].':'.$event.':'.$actorUserId.':'.gmdate('YmdHi'),'Live Room activity',((string)($actor['display_name']??'A participant')).' '.$label.' your Live Room.','/live-room.php?room='.rawurlencode((string)$room['public_id']),(int)($room['source_id']??0));
}

function vp3_browser_trust_ensure_schema_v2080(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_browser_trust_schema_ready_v2080($pdo))return;
    if(!vp3_live_room_schema_ready_v2070($pdo))throw new RuntimeException('Phase 8 Live Rooms must be installed before Phase 9.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_source_change_events_v2080 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      source_id BIGINT UNSIGNED NOT NULL,
      from_version_id BIGINT UNSIGNED NOT NULL,
      to_version_id BIGINT UNSIGNED NOT NULL,
      observed_by_user_id INT UNSIGNED NOT NULL,
      change_kind VARCHAR(40) NOT NULL DEFAULT 'content_changed',
      summary VARCHAR(1000) NOT NULL DEFAULT '',
      observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_change_public (public_id),
      UNIQUE KEY uq_browser_change_transition (source_id,from_version_id,to_version_id),
      INDEX idx_browser_change_source (source_id,id),
      CONSTRAINT fk_browser_change_source FOREIGN KEY (source_id) REFERENCES browser_sources_v2050(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_change_from FOREIGN KEY (from_version_id) REFERENCES browser_source_versions_v2050(id) ON DELETE RESTRICT,
      CONSTRAINT fk_browser_change_to FOREIGN KEY (to_version_id) REFERENCES browser_source_versions_v2050(id) ON DELETE RESTRICT,
      CONSTRAINT fk_browser_change_observer FOREIGN KEY (observed_by_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_notification_preferences_v2080 (
      user_id INT UNSIGNED NOT NULL,
      notification_type VARCHAR(40) NOT NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,notification_type),
      CONSTRAINT fk_browser_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_claims_v2080 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      source_id BIGINT UNSIGNED NOT NULL,
      source_version_id BIGINT UNSIGNED NOT NULL,
      browser_share_id BIGINT UNSIGNED NULL,
      created_by_user_id INT UNSIGNED NOT NULL,
      team_owner_user_id INT UNSIGNED NULL,
      visibility VARCHAR(16) NOT NULL DEFAULT 'public',
      statement VARCHAR(1000) NOT NULL,
      rationale TEXT NOT NULL,
      claim_status VARCHAR(24) NOT NULL DEFAULT 'open',
      resolution_note TEXT NOT NULL,
      resolved_by_user_id INT UNSIGNED NULL,
      resolved_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      UNIQUE KEY uq_browser_claim_public (public_id),
      INDEX idx_browser_claim_source (source_id,claim_status,id),
      INDEX idx_browser_claim_creator (created_by_user_id,claim_status,id),
      INDEX idx_browser_claim_team (team_owner_user_id,visibility,id),
      CONSTRAINT fk_browser_claim_source FOREIGN KEY (source_id) REFERENCES browser_sources_v2050(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_claim_version FOREIGN KEY (source_version_id) REFERENCES browser_source_versions_v2050(id) ON DELETE RESTRICT,
      CONSTRAINT fk_browser_claim_share FOREIGN KEY (browser_share_id) REFERENCES browser_shares_v2010(id) ON DELETE SET NULL,
      CONSTRAINT fk_browser_claim_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_claim_team FOREIGN KEY (team_owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_browser_claim_resolver FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_claim_events_v2080 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      claim_id BIGINT UNSIGNED NOT NULL,
      actor_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(60) NOT NULL,
      from_status VARCHAR(24) NOT NULL DEFAULT '',
      to_status VARCHAR(24) NOT NULL DEFAULT '',
      note TEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_claim_event_public (public_id),
      INDEX idx_browser_claim_event_claim (claim_id,id),
      CONSTRAINT fk_browser_claim_event_claim FOREIGN KEY (claim_id) REFERENCES browser_claims_v2080(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_claim_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_moderation_reports_v2080 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      reporter_user_id INT UNSIGNED NOT NULL,
      target_type VARCHAR(32) NOT NULL,
      target_public_id VARCHAR(64) NOT NULL,
      reason VARCHAR(190) NOT NULL,
      detail TEXT NOT NULL,
      report_status VARCHAR(24) NOT NULL DEFAULT 'open',
      assigned_to_user_id INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_report_public (public_id),
      INDEX idx_browser_report_status (report_status,id),
      INDEX idx_browser_report_target (target_type,target_public_id,id),
      CONSTRAINT fk_browser_report_user FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_report_assignee FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_moderation_actions_v2080 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      report_id BIGINT UNSIGNED NOT NULL,
      moderator_user_id INT UNSIGNED NOT NULL,
      action_type VARCHAR(80) NOT NULL,
      note TEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_mod_action_public (public_id),
      INDEX idx_browser_mod_action_report (report_id,id),
      CONSTRAINT fk_browser_mod_action_report FOREIGN KEY (report_id) REFERENCES browser_moderation_reports_v2080(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_mod_action_user FOREIGN KEY (moderator_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS browser_notifications_v2080 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      event_key VARCHAR(190) NOT NULL,
      notification_type VARCHAR(40) NOT NULL,
      source_id BIGINT UNSIGNED NULL,
      claim_id BIGINT UNSIGNED NULL,
      report_id BIGINT UNSIGNED NULL,
      title VARCHAR(190) NOT NULL,
      body VARCHAR(1000) NOT NULL DEFAULT '',
      target_url VARCHAR(500) NOT NULL DEFAULT '',
      is_read TINYINT(1) NOT NULL DEFAULT 0,
      read_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_browser_notification_event (user_id,event_key),
      INDEX idx_browser_notification_user (user_id,is_read,id),
      CONSTRAINT fk_browser_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_notification_source FOREIGN KEY (source_id) REFERENCES browser_sources_v2050(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_notification_claim FOREIGN KEY (claim_id) REFERENCES browser_claims_v2080(id) ON DELETE CASCADE,
      CONSTRAINT fk_browser_notification_report FOREIGN KEY (report_id) REFERENCES browser_moderation_reports_v2080(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
