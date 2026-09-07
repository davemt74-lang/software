<?php
declare(strict_types=1);

const VP3_AGENT_ACCESS_CAPABILITIES = ['agent.message'];
const VP3_AGENT_ACCESS_TOKEN_BYTES = 32;
const VP3_AGENT_ACCESS_ONCE_SECONDS = 1800;
const VP3_AGENT_MESSAGE_MAX_LENGTH = 2000;

function vp3_agent_access_token_hash(string $token): string
{
    $token=trim($token);
    return preg_match('/^[a-f0-9]{64}$/i',$token)?hash('sha256',strtolower($token)):'';
}

function vp3_agent_access_bearer_token(): string
{
    $authorization=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
    if(preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/',$authorization,$m))return (string)$m[1];
    return trim((string)($_SERVER['HTTP_X_VP3_AGENT_TOKEN']??''));
}

function vp3_agent_access_owner_context(PDO $pdo,string $username): ?array
{
    $username=profile_username_normalize($username);
    if($username==='')return null;
    $profile=profile_by_username($pdo,$username);
    if(!$profile||empty($profile['is_active'])||empty($profile['is_public']))return null;
    $owner=(int)$profile['user_id'];$ownerUser=profile_user_row($pdo,$owner);
    if(!$ownerUser||!personal_capability_has_v242('profile_agent.access',$ownerUser)||!personal_capability_has_v242('profile_chat.access',$ownerUser))return null;
    if(!vp3_agent_messaging_allowed($ownerUser))return null;
    $agent=profile_active_agent($pdo,$profile);if(!$agent)return null;
    $property=vp3_radar_native_property($pdo,$owner);if(!$property)return null;
    return ['profile'=>$profile,'owner_user'=>$ownerUser,'agent'=>$agent,'property'=>$property];
}

function vp3_agent_access_known_identity(PDO $pdo,array $profile,string $userAgent): ?array
{
    $registry=vp3_radar_match_agent($userAgent,$pdo);
    if(!$registry||(string)$registry['visitor_class']!=='ai_user_agent')return null;
    $identity=vp3_radar_native_identity($pdo,(int)$profile['user_id'],$userAgent);
    if(!$identity||empty($identity['contact']))return null;
    return ['registry'=>$registry,'contact'=>$identity['contact']];
}

function vp3_agent_access_event(PDO $pdo,array $property,array $contact,string $eventType,string $summary,array $details=[],string $severity='low',int $significance=70,?int $statusCode=200): int
{
    $stmt=$pdo->prepare("INSERT INTO vp3_radar_events
      (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at)
      VALUES (?,?,NULL,?,?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        (int)$property['owner_user_id'],(int)$property['id'],(int)$contact['id'],
        mb_strimwidth($eventType,0,80,''),mb_strimwidth($severity,0,20,''),'/api/agent-message.php','POST',$statusCode,
        max(0,min(100,$significance)),max(0,min(100,(int)($contact['risk_score']??0))),mb_strimwidth($summary,0,500,'…'),
        json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    ]);
    return (int)$pdo->lastInsertId();
}

function vp3_agent_access_pending(PDO $pdo,int $ownerUserId,int $contactId,string $capability): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM vp3_agent_access_requests WHERE owner_user_id=? AND agent_contact_id=? AND capability=? AND status='pending' AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 1");
    $stmt->execute([$ownerUserId,$contactId,$capability]);
    return $stmt->fetch()?:null;
}

function vp3_agent_access_request_create(PDO $pdo,array $context,string $userAgent,string $capability,string $purpose=''): array
{
    $capability=trim($capability);if(!in_array($capability,VP3_AGENT_ACCESS_CAPABILITIES,true))throw new RuntimeException('That agent capability is not available.');
    $identity=vp3_agent_access_known_identity($pdo,$context['profile'],$userAgent);if(!$identity)throw new RuntimeException('Only recognized user-directed agents can request this capability.');
    $contact=$identity['contact'];$property=$context['property'];$owner=(int)$property['owner_user_id'];
    $decision=vp3_radar_gateway_decision($pdo,$property,$contact,'/api/agent-message.php');
    if(empty($decision['allowed']))throw new RuntimeException('Agent Gateway does not allow this agent to request messaging access.');
    if(vp3_agent_access_pending($pdo,$owner,(int)$contact['id'],$capability))return ['created'=>false,'pending'=>true,'contact'=>$contact];

    $token=bin2hex(random_bytes(VP3_AGENT_ACCESS_TOKEN_BYTES));$hash=hash('sha256',$token);$purpose=mb_strimwidth(trim($purpose),0,500,'…');
    $stmt=$pdo->prepare("INSERT INTO vp3_agent_access_requests (owner_user_id,agent_contact_id,property_id,capability,status,request_token_hash,purpose,created_at,updated_at) VALUES (?,?,?,?,'pending',?,?,NOW(),NOW())");
    $stmt->execute([$owner,(int)$contact['id'],(int)$property['id'],$capability,$hash,$purpose]);$requestId=(int)$pdo->lastInsertId();
    $identityLabel=(trim((string)$contact['operator_name'])!==''?trim((string)$contact['operator_name']).' · ':'').trim((string)$contact['display_name']);
    $summary=$identityLabel.' requested permission to message the Profile Agent'.($purpose!==''?': '.$purpose:'.');
    $eventId=vp3_agent_access_event($pdo,$property,$contact,'agent_access_requested',$summary,['request_id'=>$requestId,'capability'=>$capability,'purpose'=>$purpose], 'medium',85,202);
    create_notification($owner,'radar_agent_access_request','Agent access request · '.trim((string)$contact['display_name']),$summary,url('/profile-agent.php?tab=radar'),'radar_event',$eventId);
    return ['created'=>true,'request_id'=>$requestId,'request_token'=>$token,'status'=>'pending','capability'=>$capability,'contact'=>$contact];
}

function vp3_agent_access_status_by_token(PDO $pdo,int $ownerUserId,string $token): ?array
{
    $hash=vp3_agent_access_token_hash($token);if($hash===''||$ownerUserId<1)return null;
    $stmt=$pdo->prepare("SELECT r.id,r.owner_user_id,r.agent_contact_id,r.capability,r.status,r.purpose,r.approved_until,r.last_used_at,r.use_count,r.created_at,r.updated_at,c.display_name,c.operator_name,c.visitor_class
      FROM vp3_agent_access_requests r INNER JOIN vp3_agent_contacts c ON c.id=r.agent_contact_id
      WHERE r.owner_user_id=? AND r.request_token_hash=? LIMIT 1");
    $stmt->execute([$ownerUserId,$hash]);$row=$stmt->fetch();if(!$row)return null;
    if(in_array((string)$row['status'],['approved_once','approved'],true)&&!empty($row['approved_until'])&&strtotime((string)$row['approved_until'])<time()){
        $pdo->prepare("UPDATE vp3_agent_access_requests SET status='expired',updated_at=NOW() WHERE id=? AND owner_user_id=?")->execute([(int)$row['id'],$ownerUserId]);$row['status']='expired';
    }
    return $row;
}

function vp3_agent_access_owner_list(PDO $pdo,int $ownerUserId,int $limit=50): array
{
    if($ownerUserId<1)return [];$limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT r.id,r.agent_contact_id,r.property_id,r.capability,r.status,r.purpose,r.approved_until,r.last_used_at,r.use_count,r.created_at,r.updated_at,c.display_name,c.operator_name,c.visitor_class,c.risk_score,c.trust_score,p.label AS property_label,p.domain AS property_domain
      FROM vp3_agent_access_requests r INNER JOIN vp3_agent_contacts c ON c.id=r.agent_contact_id LEFT JOIN vp3_radar_properties p ON p.id=r.property_id
      WHERE r.owner_user_id=? AND r.status IN ('pending','approved_once','approved') ORDER BY (r.status='pending') DESC,r.updated_at DESC,r.id DESC LIMIT {$limit}");
    $stmt->execute([$ownerUserId]);return $stmt->fetchAll()?:[];
}

function vp3_agent_access_owner_decide(PDO $pdo,array $user,int $requestId,string $decision): array
{
    $owner=(int)($user['id']??0);$decision=strtolower(trim($decision));
    if($owner<1||$requestId<1)throw new RuntimeException('Agent access request not found.');
    if(!in_array($decision,['allow_once','allow','deny'],true))throw new RuntimeException('Choose Allow once, Always allow, or Deny.');
    $stmt=$pdo->prepare("SELECT r.*,c.display_name,c.operator_name,c.risk_score,p.id AS radar_property_id,p.domain FROM vp3_agent_access_requests r INNER JOIN vp3_agent_contacts c ON c.id=r.agent_contact_id LEFT JOIN vp3_radar_properties p ON p.id=r.property_id WHERE r.id=? AND r.owner_user_id=? LIMIT 1");
    $stmt->execute([$requestId,$owner]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Agent access request not found.');
    if(!in_array((string)$row['status'],['pending','approved_once','approved'],true))throw new RuntimeException('That access request is already closed.');
    $status=$decision==='allow_once'?'approved_once':($decision==='allow'?'approved':'denied');
    $approvedUntil=$decision==='allow_once'?date('Y-m-d H:i:s',time()+VP3_AGENT_ACCESS_ONCE_SECONDS):null;
    $pdo->prepare('UPDATE vp3_agent_access_requests SET status=?,approved_until=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$status,$approvedUntil,$requestId,$owner]);
    $contact=['id'=>(int)$row['agent_contact_id'],'display_name'=>(string)$row['display_name'],'operator_name'=>(string)$row['operator_name'],'risk_score'=>(int)$row['risk_score']];
    $property=['id'=>(int)($row['radar_property_id']??0),'owner_user_id'=>$owner,'domain'=>(string)($row['domain']??'')];
    if((int)$property['id']>0)vp3_agent_access_event($pdo,$property,$contact,'agent_access_decision',trim((string)$contact['display_name']).' messaging request was '.$status.'.',['request_id'=>$requestId,'capability'=>(string)$row['capability'],'decision'=>$decision],$decision==='deny'?'medium':'low',75,200);
    return ['request_id'=>$requestId,'status'=>$status,'approved_until'=>$approvedUntil,'requests'=>vp3_agent_access_owner_list($pdo,$owner)];
}

function vp3_agent_access_grant_claim(PDO $pdo,int $ownerUserId,string $token,string $userAgent): ?array
{
    $hash=vp3_agent_access_token_hash($token);if($hash===''||$ownerUserId<1)return null;
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT r.*,c.agent_registry_id,c.display_name,c.operator_name,c.visitor_class,c.risk_score,c.trust_score FROM vp3_agent_access_requests r INNER JOIN vp3_agent_contacts c ON c.id=r.agent_contact_id WHERE r.owner_user_id=? AND r.request_token_hash=? FOR UPDATE");
        $stmt->execute([$ownerUserId,$hash]);$row=$stmt->fetch();
        if(!$row||!in_array((string)$row['status'],['approved_once','approved'],true)){$pdo->rollBack();return null;}
        if(!empty($row['approved_until'])&&strtotime((string)$row['approved_until'])<time()){$pdo->prepare("UPDATE vp3_agent_access_requests SET status='expired',updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);$pdo->commit();return null;}
        $registry=vp3_radar_match_agent($userAgent,$pdo);
        if(!$registry||(int)$registry['id']!==(int)$row['agent_registry_id']||(string)$registry['visitor_class']!=='ai_user_agent'){$pdo->rollBack();return null;}
        if((string)$row['status']==='approved_once')$pdo->prepare("UPDATE vp3_agent_access_requests SET status='consumed',use_count=use_count+1,last_used_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
        else $pdo->prepare('UPDATE vp3_agent_access_requests SET use_count=use_count+1,last_used_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        $pdo->commit();return $row;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function vp3_agent_access_restore_once(PDO $pdo,int $ownerUserId,int $requestId): void
{
    if($ownerUserId<1||$requestId<1)return;
    $pdo->prepare("UPDATE vp3_agent_access_requests SET status='approved_once',use_count=GREATEST(0,use_count-1),last_used_at=NULL,updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='consumed' AND approved_until>NOW() AND use_count=1")->execute([$requestId,$ownerUserId]);
}

function vp3_agent_message_rate_check(PDO $pdo,int $ownerUserId,int $contactId): void
{
    $stmt=$pdo->prepare("SELECT COUNT(*) total,MAX(occurred_at) last_at FROM vp3_radar_events WHERE owner_user_id=? AND agent_contact_id=? AND event_type='agent_message_inbound' AND occurred_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)");
    $stmt->execute([$ownerUserId,$contactId]);$row=$stmt->fetch()?:[];
    if((int)($row['total']??0)>=6)throw new RuntimeException('Agent messaging rate limit reached.');
    if(!empty($row['last_at'])&&time()-strtotime((string)$row['last_at'])<2)throw new RuntimeException('Please wait before sending another agent message.');
}

function vp3_agent_message_history(PDO $pdo,int $ownerUserId,int $contactId,int $limit=12): array
{
    $limit=max(2,min(20,$limit));$stmt=$pdo->prepare("SELECT event_type,details_json FROM vp3_radar_events WHERE owner_user_id=? AND agent_contact_id=? AND event_type IN ('agent_message_inbound','agent_message_outbound') ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute([$ownerUserId,$contactId]);$rows=array_reverse($stmt->fetchAll()?:[]);$history=[];
    foreach($rows as $row){$details=json_decode((string)($row['details_json']??''),true);if(!is_array($details))continue;$text=trim((string)($details['message']??''));if($text==='')continue;$history[]=['role'=>(string)$row['event_type']==='agent_message_inbound'?'user':'assistant','message'=>$text];}
    return $history;
}

function vp3_agent_message_generate(PDO $pdo,array $context,array $grant,string $query): array
{
    $profile=$context['profile'];$ownerUser=$context['owner_user'];$agent=$context['agent'];$property=$context['property'];
    $contact=['id'=>(int)$grant['agent_contact_id'],'display_name'=>(string)$grant['display_name'],'operator_name'=>(string)$grant['operator_name'],'visitor_class'=>(string)$grant['visitor_class'],'risk_score'=>(int)$grant['risk_score'],'trust_score'=>(int)$grant['trust_score']];
    vp3_agent_message_rate_check($pdo,(int)$profile['user_id'],(int)$contact['id']);
    $query=trim($query);if($query===''||mb_strlen($query)>VP3_AGENT_MESSAGE_MAX_LENGTH)throw new RuntimeException('Enter an agent message up to 2,000 characters.');
    $decision=vp3_radar_gateway_decision($pdo,$property,$contact,'/api/agent-message.php');if(empty($decision['allowed']))throw new RuntimeException('Agent Gateway denies messaging for this contact.');
    $history=vp3_agent_message_history($pdo,(int)$profile['user_id'],(int)$contact['id'],12);
    vp3_agent_access_event($pdo,$property,$contact,'agent_message_inbound',trim((string)$contact['display_name']).' sent a message to the Profile Agent.',['request_id'=>(int)$grant['id'],'capability'=>'agent.message','message'=>$query], 'low',85,200);
    $approvedContext=profile_agent_context($pdo,$profile,$agent,null,$query);if(count($approvedContext)>20)$approvedContext=array_slice($approvedContext,0,20);
    $substantive=array_values(array_filter($approvedContext,static fn(array $c):bool=>!in_array((string)$c['source'],['profile:identity','profile:rules'],true)));
    $greeting=(bool)preg_match('/^(?:hi|hello|hey|good\s+(?:morning|afternoon|evening))[!.\s]*$/i',$query);
    $usage=[];$provider='local';$model='';
    if(!$substantive&&!$greeting)$answer='I do not have approved public information to answer that accurately.';
    elseif($greeting)$answer=trim((string)($profile['profile_agent_greeting']??''))?:'Hello — I am '.(string)$agent['display_name'].', '.(string)$profile['display_name'].'’s AI representative.';
    else{
        $result=ai_generate_chat_response($query,$history,$approvedContext,$ownerUser,'agent.messaging');
        if(empty($result['ok'])){
            if(!empty($result['quota_exhausted']))throw new RuntimeException('VP3_AGENT_MESSAGE_QUOTA');
            throw new RuntimeException('VP3_AGENT_MESSAGE_PROVIDER');
        }
        $answer=trim((string)($result['answer']??''));if($answer==='')throw new RuntimeException('VP3_AGENT_MESSAGE_PROVIDER');
        $usage=is_array($result['usage']??null)?$result['usage']:[];$provider=(string)($result['provider']??'');$model=(string)($result['model']??'');
    }
    $sources=[];foreach($approvedContext as $item){$source=(string)($item['source']??'');if(!in_array($source,['profile:identity','profile:rules'],true))$sources[]=['source'=>$source,'title'=>(string)($item['title']??'')];}
    vp3_agent_access_event($pdo,$property,$contact,'agent_message_outbound',trim((string)$agent['display_name']).' replied to '.trim((string)$contact['display_name']).'.',['request_id'=>(int)$grant['id'],'capability'=>'agent.message','message'=>$answer,'sources'=>$sources,'provider'=>$provider,'model'=>$model,'usage'=>$usage], 'low',85,200);
    return ['answer'=>$answer,'sources'=>$sources,'agent'=>['name'=>(string)$agent['display_name'],'system_name'=>system_agent_name()],'usage'=>$usage];
}