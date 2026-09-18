<?php
declare(strict_types=1);

require_once __DIR__.'/browser-source-feed-v2050.php';
require_once __DIR__.'/research-projects-v2060.php';

const VP3_LIVE_ROOMS_V2070='live-rooms-cloak-v2070-20260918';
const VP3_LIVE_ROOM_MESSAGE_MAX_V2070=4000;
const VP3_LIVE_ROOM_PRESENCE_SECONDS_V2070=45;

function vp3_live_room_uuid_v2070(): string{return vp3_extension_uuid_v2000();}

function vp3_live_room_schema_ready_v2070(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('live_rooms_v2070')
        && table_exists('live_room_members_v2070')
        && table_exists('live_room_messages_v2070');
}

function vp3_live_room_require_ready_v2070(?PDO $pdo=null): PDO
{
    $pdo??=db();
    if(!$pdo||!vp3_live_room_schema_ready_v2070($pdo))throw new RuntimeException('Live Rooms are not ready. Run the current database upgrade.');
    return $pdo;
}

function vp3_live_room_alias_v2070(): string
{
    $a=['Amber','Blue','Copper','Drift','Echo','Frost','Golden','Indigo','Juniper','Lunar','Maple','Nova','Onyx','Quiet','River','Silver','Solar','Velvet','Wild','Zen'];
    $b=['Badger','Cedar','Comet','Falcon','Fox','Heron','Lynx','Moth','Otter','Pine','Raven','Sparrow','Stone','Tiger','Willow','Wolf'];
    return 'Cloaked '.$a[random_int(0,count($a)-1)].' '.$b[random_int(0,count($b)-1)].' '.strtoupper(substr(bin2hex(random_bytes(3)),0,4));
}

function vp3_live_room_row_v2070(PDO $pdo,string $publicId): ?array
{
    $stmt=$pdo->prepare('SELECT r.*,s.public_id AS source_public_id,s.normalized_url AS source_url,s.canonical_url AS source_canonical_url,s.source_title,s.source_domain,p.public_id AS project_public_id,p.title AS project_title
      FROM live_rooms_v2070 r
      LEFT JOIN browser_sources_v2050 s ON s.id=r.source_id
      LEFT JOIN research_projects_v2060 p ON p.id=r.research_project_id
      WHERE r.public_id=? LIMIT 1');
    $stmt->execute([trim($publicId)]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_live_room_access_v2070(PDO $pdo,array $room,int $userId,bool $write=false): bool
{
    if(!empty($room['deleted_at']))return false;
    if($write && (string)($room['room_status']??'active')!=='active')return false;
    $scope=(string)($room['room_scope']??'');
    if($scope==='public')return !$write||$userId>0;
    if($scope==='team'&&$userId>0){
        $team=(int)($room['team_owner_user_id']??0);
        return $team>0&&vp3_human_team_authorized_v370($pdo,$team,$userId);
    }
    return false;
}

function vp3_live_room_require_v2070(PDO $pdo,string $publicId,int $userId,bool $write=false): array
{
    $room=vp3_live_room_row_v2070($pdo,$publicId);
    if(!$room||!vp3_live_room_access_v2070($pdo,$room,$userId,$write))throw new RuntimeException('This Live Room is not available.');
    return $room;
}

function vp3_live_room_member_v2070(PDO $pdo,int $roomId,int $userId): ?array
{
    if($roomId<1||$userId<1)return null;
    $stmt=$pdo->prepare('SELECT m.*,u.display_name FROM live_room_members_v2070 m INNER JOIN users u ON u.id=m.user_id WHERE m.room_id=? AND m.user_id=? LIMIT 1');
    $stmt->execute([$roomId,$userId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_live_room_member_identity_v2070(array $member,bool $forSelf=false): array
{
    $cloaked=!empty($member['cloak_mode']);
    return [
        'id'=>(string)($cloaked?($member['cloak_public_id']??''):($member['public_id']??'')),
        'name'=>$cloaked?(string)($member['cloak_alias']??'Cloaked participant'):(string)($member['display_name']??'VP3 user'),
        'cloaked'=>$cloaked,
        'is_self'=>$forSelf,
    ];
}

function vp3_live_room_presence_count_v2070(PDO $pdo,int $roomId): int
{
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM live_room_members_v2070
      WHERE room_id=? AND left_at IS NULL AND last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL '.VP3_LIVE_ROOM_PRESENCE_SECONDS_V2070.' SECOND)');
    $stmt->execute([$roomId]);
    return (int)$stmt->fetchColumn();
}

function vp3_live_room_participants_v2070(PDO $pdo,array $room,int $viewerUserId): array
{
    $stmt=$pdo->prepare('SELECT m.*,u.display_name
      FROM live_room_members_v2070 m INNER JOIN users u ON u.id=m.user_id
      WHERE m.room_id=? AND m.left_at IS NULL AND m.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL '.VP3_LIVE_ROOM_PRESENCE_SECONDS_V2070.' SECOND)
      ORDER BY m.joined_at ASC,m.id ASC');
    $stmt->execute([(int)$room['id']]);
    $out=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$out[]=vp3_live_room_member_identity_v2070($row,(int)$row['user_id']===$viewerUserId);
    return $out;
}

function vp3_live_room_public_v2070(PDO $pdo,array $room,int $viewerUserId=0,bool $withParticipants=true): array
{
    $member=$viewerUserId>0?vp3_live_room_member_v2070($pdo,(int)$room['id'],$viewerUserId):null;
    $participants=$withParticipants?vp3_live_room_participants_v2070($pdo,$room,$viewerUserId):[];
    $participantCount=$withParticipants?count($participants):vp3_live_room_presence_count_v2070($pdo,(int)$room['id']);
    return [
        'id'=>(string)$room['public_id'],
        'title'=>(string)$room['title'],
        'scope'=>(string)$room['room_scope'],
        'status'=>(string)$room['room_status'],
        'team_id'=>(int)($room['team_owner_user_id']??0),
        'allow_cloak'=>!empty($room['allow_cloak']),
        'is_owner'=>$viewerUserId>0&&(int)$room['owner_user_id']===$viewerUserId,
        'joined'=>is_array($member)&&empty($member['left_at']),
        'cloak_mode'=>is_array($member)&&!empty($member['cloak_mode']),
        'self_identity'=>is_array($member)?vp3_live_room_member_identity_v2070($member,true):null,
        'participants'=>$participants,
        'participant_count'=>$participantCount,
        'source'=>[
            'id'=>(string)($room['source_public_id']??''),
            'url'=>(string)($room['source_url']??''),
            'canonical_url'=>(string)($room['source_canonical_url']??''),
            'title'=>(string)($room['source_title']??''),
            'domain'=>(string)($room['source_domain']??''),
        ],
        'research_project'=>[
            'id'=>(string)($room['project_public_id']??''),
            'title'=>(string)($room['project_title']??''),
        ],
        'created_at'=>(string)$room['created_at'],
        'ended_at'=>(string)($room['ended_at']??''),
        'url'=>url('/live-room.php?room='.rawurlencode((string)$room['public_id'])),
    ];
}

function vp3_live_room_source_identity_v2070(string $url,string $canonicalUrl='',string $title=''): array
{
    return vp3_browser_source_identity_v2050($url,$canonicalUrl,$title);
}

function vp3_live_room_rooms_for_source_v2070(PDO $pdo,int $viewerUserId,string $url,string $canonicalUrl='',string $title=''): array
{
    $identity=vp3_live_room_source_identity_v2070($url,$canonicalUrl,$title);
    $source=vp3_browser_source_row_by_hash_v2050($pdo,(string)$identity['url_hash']);
    if(!$source)return ['source'=>['id'=>'','url'=>$identity['normalized_url'],'canonical_url'=>$identity['canonical_url'],'title'=>$identity['title'],'domain'=>$identity['domain']],'rooms'=>[]];

    $stmt=$pdo->prepare("SELECT public_id FROM live_rooms_v2070 WHERE source_id=? AND room_status='active' AND deleted_at IS NULL ORDER BY id DESC LIMIT 50");
    $stmt->execute([(int)$source['id']]);
    $rooms=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $publicId){
        $room=vp3_live_room_row_v2070($pdo,(string)$publicId);
        if($room&&vp3_live_room_access_v2070($pdo,$room,$viewerUserId,false))$rooms[]=vp3_live_room_public_v2070($pdo,$room,$viewerUserId,false);
    }
    return ['source'=>[
        'id'=>(string)$source['public_id'],'url'=>(string)$source['normalized_url'],'canonical_url'=>(string)$source['canonical_url'],
        'title'=>(string)$identity['title'],'domain'=>(string)$source['source_domain'],
    ],'rooms'=>$rooms];
}

function vp3_live_room_list_v2070(PDO $pdo,int $viewerUserId,int $limit=50): array
{
    $limit=max(1,min(100,$limit));
    $stmt=$pdo->query("SELECT public_id FROM live_rooms_v2070 WHERE room_status='active' AND deleted_at IS NULL ORDER BY id DESC LIMIT ".max($limit*3,100));
    $out=[];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $publicId){
        $room=vp3_live_room_row_v2070($pdo,(string)$publicId);
        if(!$room||!vp3_live_room_access_v2070($pdo,$room,$viewerUserId,false))continue;
        $out[]=vp3_live_room_public_v2070($pdo,$room,$viewerUserId,false);
        if(count($out)>=$limit)break;
    }
    return $out;
}

function vp3_live_room_create_v2070(PDO $pdo,int $userId,array $input): array
{
    if($userId<1)throw new RuntimeException('Sign in to start a Live Room.');
    $scope=strtolower(trim((string)($input['scope']??'public')));
    if(!in_array($scope,['public','team'],true))throw new InvalidArgumentException('Choose Public or Team room access.');
    $teamId=$scope==='team'?max(0,(int)($input['team_id']??0)):0;
    if($scope==='team'&&($teamId<1||!vp3_human_team_authorized_v370($pdo,$teamId,$userId)))throw new RuntimeException('Choose a Team workspace you can access.');

    $source=null;
    $url=trim((string)($input['url']??''));
    if($url!==''){
        $identity=vp3_live_room_source_identity_v2070($url,trim((string)($input['canonical_url']??'')),trim((string)($input['source_title']??'')));
        $source=vp3_browser_source_ensure_v2050($pdo,$identity);
    }

    $projectId=null;
    $projectPublicId=trim((string)($input['project_id']??''));
    if($projectPublicId!==''){
        $project=vp3_research_project_require_v2060($pdo,$projectPublicId,$userId,'viewer');
        if($scope==='public')throw new RuntimeException('Research-linked Live Rooms must use Team access.');
        if((int)($project['team_owner_user_id']??0)!==$teamId)throw new RuntimeException('The Research project and Live Room must use the same Team.');
        $projectId=(int)$project['id'];
    }

    $title=trim(preg_replace('/\s+/u',' ',(string)($input['title']??''))??'');
    if($title==='')$title='Live'.($source&&!empty($source['source_title'])?': '.(string)$source['source_title']:' Room');
    if(mb_strlen($title)>180)$title=mb_substr($title,0,180);
    $allowCloak=array_key_exists('allow_cloak',$input)?!empty($input['allow_cloak']):true;
    $publicId=vp3_live_room_uuid_v2070();
    $stmt=$pdo->prepare("INSERT INTO live_rooms_v2070(public_id,owner_user_id,team_owner_user_id,source_id,research_project_id,title,room_scope,room_status,allow_cloak,created_at,updated_at)
      VALUES(?,?,?,?,?,?,?,'active',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $stmt->execute([$publicId,$userId,$teamId>0?$teamId:null,$source?(int)$source['id']:null,$projectId,$title,$scope,$allowCloak?1:0]);
    $room=vp3_live_room_row_v2070($pdo,$publicId);
    if(!$room)throw new RuntimeException('Live Room could not be reloaded.');
    vp3_live_room_join_v2070($pdo,$userId,$publicId,!empty($input['cloak_mode']));
    $room=vp3_live_room_row_v2070($pdo,$publicId)??$room;
    return vp3_live_room_public_v2070($pdo,$room,$userId,true);
}

function vp3_live_room_join_v2070(PDO $pdo,int $userId,string $roomPublicId,bool $cloak=false): array
{
    if($userId<1)throw new RuntimeException('Sign in to join a Live Room.');
    $room=vp3_live_room_require_v2070($pdo,$roomPublicId,$userId,true);
    if($cloak&&!$room['allow_cloak'])throw new RuntimeException('Cloak Mode is disabled in this Live Room.');
    $member=vp3_live_room_member_v2070($pdo,(int)$room['id'],$userId);
    if(!$member){
        $stmt=$pdo->prepare("INSERT INTO live_room_members_v2070(public_id,cloak_public_id,room_id,user_id,cloak_mode,cloak_alias,joined_at,last_seen_at,left_at,updated_at)
          VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP())");
        $stmt->execute([vp3_live_room_uuid_v2070(),vp3_live_room_uuid_v2070(),(int)$room['id'],$userId,$cloak?1:0,vp3_live_room_alias_v2070()]);
    }else{
        $pdo->prepare('UPDATE live_room_members_v2070 SET cloak_mode=?,left_at=NULL,last_seen_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([$cloak?1:0,(int)$member['id']]);
    }
    $fresh=vp3_live_room_row_v2070($pdo,$roomPublicId)??$room;
    return vp3_live_room_public_v2070($pdo,$fresh,$userId,true);
}

function vp3_live_room_leave_v2070(PDO $pdo,int $userId,string $roomPublicId): array
{
    $room=vp3_live_room_require_v2070($pdo,$roomPublicId,$userId,false);
    if($userId<1)throw new RuntimeException('Sign in to leave a Live Room.');
    $pdo->prepare('UPDATE live_room_members_v2070 SET left_at=UTC_TIMESTAMP(),last_seen_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE room_id=? AND user_id=?')
        ->execute([(int)$room['id'],$userId]);
    return ['left'=>true];
}

function vp3_live_room_heartbeat_v2070(PDO $pdo,int $userId,string $roomPublicId): void
{
    if($userId<1)return;
    $room=vp3_live_room_require_v2070($pdo,$roomPublicId,$userId,true);
    $pdo->prepare('UPDATE live_room_members_v2070 SET last_seen_at=UTC_TIMESTAMP(),left_at=NULL,updated_at=UTC_TIMESTAMP() WHERE room_id=? AND user_id=?')
        ->execute([(int)$room['id'],$userId]);
}

function vp3_live_room_cloak_v2070(PDO $pdo,int $userId,string $roomPublicId,bool $enabled): array
{
    if($userId<1)throw new RuntimeException('Sign in to use Cloak Mode.');
    $room=vp3_live_room_require_v2070($pdo,$roomPublicId,$userId,true);
    if($enabled&&!$room['allow_cloak'])throw new RuntimeException('Cloak Mode is disabled in this Live Room.');
    $member=vp3_live_room_member_v2070($pdo,(int)$room['id'],$userId);
    if(!$member||!empty($member['left_at']))throw new RuntimeException('Join the Live Room before changing Cloak Mode.');
    $pdo->prepare('UPDATE live_room_members_v2070 SET cloak_mode=?,last_seen_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([$enabled?1:0,(int)$member['id']]);
    $fresh=vp3_live_room_member_v2070($pdo,(int)$room['id'],$userId);
    return ['cloak_mode'=>$enabled,'identity'=>$fresh?vp3_live_room_member_identity_v2070($fresh,true):null];
}

function vp3_live_room_share_compatible_v2070(PDO $pdo,array $room,array $share,int $actorUserId): bool
{
    $phase6=vp3_browser_source_share_authorized_v2050($pdo,$share,$actorUserId);
    $research=function_exists('vp3_research_share_authorized_v2060')&&vp3_research_share_authorized_v2060($pdo,(string)$share['public_id'],$actorUserId);
    if(!$phase6&&!$research)return false;
    $visibility=(string)($share['visibility']??'');
    if((string)$room['room_scope']==='public')return $visibility==='public';
    if((string)$room['room_scope']==='team'){
        if($visibility==='public')return true;
        return $visibility==='team'&&(int)($share['team_owner_user_id']??0)===(int)($room['team_owner_user_id']??0);
    }
    return false;
}

function vp3_live_room_message_row_v2070(PDO $pdo,int $messageId): ?array
{
    $stmt=$pdo->prepare('SELECT m.*,s.public_id AS browser_share_public_id FROM live_room_messages_v2070 m LEFT JOIN browser_shares_v2010 s ON s.id=m.browser_share_id WHERE m.id=? AND m.deleted_at IS NULL LIMIT 1');
    $stmt->execute([$messageId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function vp3_live_room_message_public_v2070(PDO $pdo,array $room,array $row,int $viewerUserId): array
{
    $annotation=null;
    if(!empty($row['browser_share_public_id'])){
        $share=vp3_browser_source_share_row_v2050($pdo,(string)$row['browser_share_public_id']);
        if($share&&vp3_live_room_share_compatible_v2070($pdo,$room,$share,(int)$row['user_id'])&&vp3_browser_source_share_authorized_v2050($pdo,$share,$viewerUserId)){
            $annotation=vp3_browser_source_item_v2050($pdo,$share,$viewerUserId,false);
        }
    }
    return [
        'id'=>(string)$row['public_id'],
        'cursor'=>(int)$row['id'],
        'body'=>(string)$row['body'],
        'sender'=>[
            'id'=>(string)$row['sender_public_id'],
            'name'=>(string)$row['sender_label'],
            'cloaked'=>!empty($row['sender_cloaked']),
            'is_self'=>$viewerUserId>0&&(int)$row['user_id']===$viewerUserId,
        ],
        'annotation'=>$annotation,
        'created_at'=>(string)$row['created_at'],
    ];
}

function vp3_live_room_messages_v2070(PDO $pdo,array $room,int $viewerUserId,int $after=0,int $limit=100): array
{
    $limit=max(1,min(200,$limit));$after=max(0,$after);
    $stmt=$pdo->prepare('SELECT m.*,s.public_id AS browser_share_public_id FROM live_room_messages_v2070 m
      LEFT JOIN browser_shares_v2010 s ON s.id=m.browser_share_id
      WHERE m.room_id=? AND m.id>? AND m.deleted_at IS NULL ORDER BY m.id ASC LIMIT '.$limit);
    $stmt->execute([(int)$room['id'],$after]);
    $items=[];$cursor=$after;
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$cursor=max($cursor,(int)$row['id']);$items[]=vp3_live_room_message_public_v2070($pdo,$room,$row,$viewerUserId);}
    return ['items'=>$items,'cursor'=>$cursor];
}

function vp3_live_room_send_v2070(PDO $pdo,int $userId,string $roomPublicId,string $body,string $browserSharePublicId=''): array
{
    if($userId<1)throw new RuntimeException('Sign in to post in a Live Room.');
    $room=vp3_live_room_require_v2070($pdo,$roomPublicId,$userId,true);
    $member=vp3_live_room_member_v2070($pdo,(int)$room['id'],$userId);
    if(!$member||!empty($member['left_at']))throw new RuntimeException('Join the Live Room before posting.');
    $body=trim(str_replace("\0",'',$body));
    if($body===''&&trim($browserSharePublicId)==='')throw new InvalidArgumentException('Write a message or attach an annotation.');
    if(strlen($body)>VP3_LIVE_ROOM_MESSAGE_MAX_V2070)throw new InvalidArgumentException('Live Room messages are limited to 4,000 bytes.');

    $shareId=null;
    if(trim($browserSharePublicId)!==''){
        $share=vp3_browser_source_share_row_v2050($pdo,trim($browserSharePublicId));
        if(!$share||!vp3_live_room_share_compatible_v2070($pdo,$room,$share,$userId)){
            throw new RuntimeException('That annotation is not visible to everyone who can enter this Live Room.');
        }
        $shareId=(int)$share['id'];
    }

    $cloaked=!empty($member['cloak_mode']);
    $senderPublic=$cloaked?(string)$member['cloak_public_id']:(string)$member['public_id'];
    $senderLabel=$cloaked?(string)$member['cloak_alias']:(string)$member['display_name'];
    $publicId=vp3_live_room_uuid_v2070();
    $stmt=$pdo->prepare("INSERT INTO live_room_messages_v2070(public_id,room_id,user_id,browser_share_id,body,sender_cloaked,sender_public_id,sender_label,created_at)
      VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
    $stmt->execute([$publicId,(int)$room['id'],$userId,$shareId,$body,$cloaked?1:0,$senderPublic,mb_substr($senderLabel,0,190)]);
    $pdo->prepare('UPDATE live_room_members_v2070 SET last_seen_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([(int)$member['id']]);
    $row=vp3_live_room_message_row_v2070($pdo,(int)$pdo->lastInsertId());
    if(!$row)throw new RuntimeException('Live Room message could not be reloaded.');
    return vp3_live_room_message_public_v2070($pdo,$room,$row,$userId);
}

function vp3_live_room_poll_v2070(PDO $pdo,int $viewerUserId,string $roomPublicId,int $after=0,bool $heartbeat=true): array
{
    $room=vp3_live_room_require_v2070($pdo,$roomPublicId,$viewerUserId,false);
    if($heartbeat&&$viewerUserId>0){
        $member=vp3_live_room_member_v2070($pdo,(int)$room['id'],$viewerUserId);
        if($member&&empty($member['left_at']))vp3_live_room_heartbeat_v2070($pdo,$viewerUserId,$roomPublicId);
    }
    $messages=vp3_live_room_messages_v2070($pdo,$room,$viewerUserId,$after,100);
    $fresh=vp3_live_room_row_v2070($pdo,$roomPublicId)??$room;
    return ['room'=>vp3_live_room_public_v2070($pdo,$fresh,$viewerUserId,true),'messages'=>$messages['items'],'cursor'=>$messages['cursor']];
}

function vp3_live_room_end_v2070(PDO $pdo,int $userId,string $roomPublicId): array
{
    $room=vp3_live_room_require_v2070($pdo,$roomPublicId,$userId,false);
    if((int)$room['owner_user_id']!==$userId)throw new RuntimeException('Only the Live Room owner can end this room.');
    $pdo->prepare("UPDATE live_rooms_v2070 SET room_status='ended',ended_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$room['id']]);
    $pdo->prepare('UPDATE live_room_members_v2070 SET left_at=COALESCE(left_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE room_id=?')->execute([(int)$room['id']]);
    $fresh=vp3_live_room_row_v2070($pdo,$roomPublicId)??$room;
    return vp3_live_room_public_v2070($pdo,$fresh,$userId,true);
}

function vp3_live_room_ensure_schema_v2070(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_live_room_schema_ready_v2070($pdo))return;
    if(!vp3_browser_source_feed_schema_ready_v2050($pdo))throw new RuntimeException('Phase 6 Source Feed must be installed before Live Rooms.');
    if(!vp3_research_schema_ready_v2060($pdo))throw new RuntimeException('Phase 7 Research must be installed before Live Rooms.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS live_rooms_v2070 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      team_owner_user_id INT UNSIGNED NULL,
      source_id BIGINT UNSIGNED NULL,
      research_project_id BIGINT UNSIGNED NULL,
      title VARCHAR(180) NOT NULL,
      room_scope VARCHAR(16) NOT NULL DEFAULT 'public',
      room_status VARCHAR(16) NOT NULL DEFAULT 'active',
      allow_cloak TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ended_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      UNIQUE KEY uq_live_room_public (public_id),
      INDEX idx_live_room_status (room_status,created_at,id),
      INDEX idx_live_room_source (source_id,room_status,id),
      INDEX idx_live_room_team (team_owner_user_id,room_status,id),
      INDEX idx_live_room_project (research_project_id,room_status,id),
      CONSTRAINT fk_live_room_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_live_room_team FOREIGN KEY (team_owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_live_room_source FOREIGN KEY (source_id) REFERENCES browser_sources_v2050(id) ON DELETE SET NULL,
      CONSTRAINT fk_live_room_project FOREIGN KEY (research_project_id) REFERENCES research_projects_v2060(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS live_room_members_v2070 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      cloak_public_id CHAR(36) NOT NULL,
      room_id BIGINT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      cloak_mode TINYINT(1) NOT NULL DEFAULT 0,
      cloak_alias VARCHAR(190) NOT NULL,
      joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      left_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_live_room_member_public (public_id),
      UNIQUE KEY uq_live_room_member_cloak_public (cloak_public_id),
      UNIQUE KEY uq_live_room_member_user (room_id,user_id),
      INDEX idx_live_room_member_presence (room_id,left_at,last_seen_at,id),
      CONSTRAINT fk_live_room_member_room FOREIGN KEY (room_id) REFERENCES live_rooms_v2070(id) ON DELETE CASCADE,
      CONSTRAINT fk_live_room_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS live_room_messages_v2070 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      room_id BIGINT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      browser_share_id BIGINT UNSIGNED NULL,
      body TEXT NOT NULL,
      sender_cloaked TINYINT(1) NOT NULL DEFAULT 0,
      sender_public_id CHAR(36) NOT NULL,
      sender_label VARCHAR(190) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      UNIQUE KEY uq_live_room_message_public (public_id),
      INDEX idx_live_room_message_room (room_id,id),
      INDEX idx_live_room_message_user (user_id,created_at,id),
      INDEX idx_live_room_message_share (browser_share_id,id),
      CONSTRAINT fk_live_room_message_room FOREIGN KEY (room_id) REFERENCES live_rooms_v2070(id) ON DELETE CASCADE,
      CONSTRAINT fk_live_room_message_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_live_room_message_share FOREIGN KEY (browser_share_id) REFERENCES browser_shares_v2010(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
