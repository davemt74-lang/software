<?php
declare(strict_types=1);

const VP3_AGENT_ACCESS_CAPABILITIES = ['agent.message'];
const VP3_AGENT_ACCESS_TOKEN_BYTES = 32;
const VP3_AGENT_ACCESS_ONCE_SECONDS = 1800;
const VP3_AGENT_MESSAGE_MAX_LENGTH = 2000;
const VP3_AGENT_MESSAGE_HISTORY_V1 = 'vp3.agent-message.history.v1';

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

function vp3_agent_access_owner_context(PDO $pdo,string $username,bool $initializeProperty=true): ?array
{
    $username=profile_username_normalize($username);
    if($username==='')return null;
    $profile=profile_by_username($pdo,$username);
    if(!$profile||empty($profile['is_active'])||empty($profile['is_public']))return null;
    $owner=(int)$profile['user_id'];$ownerUser=profile_user_row($pdo,$owner);
    if(!function_exists('vp3_observation_personal_permission_v1'))require_once __DIR__.'/agent-observation-authority-v1.php';
    if(!$ownerUser||empty($ownerUser['is_active'])||!vp3_observation_personal_permission_v1($pdo,$ownerUser,'profile_agent.access')||!vp3_observation_personal_permission_v1($pdo,$ownerUser,'profile_chat.access'))return null;
    if(!vp3_agent_messaging_allowed($ownerUser))return null;
    $agent=profile_active_agent($pdo,$profile);if(!$agent)return null;
    if($initializeProperty)$property=vp3_radar_native_property($pdo,$owner);
    else{$stmt=$pdo->prepare("SELECT * FROM vp3_radar_properties WHERE owner_user_id=? AND property_type='native' ORDER BY id LIMIT 1");$stmt->execute([$owner]);$property=$stmt->fetch();}
    if(!$property||empty($property['is_active'])||(int)$property['owner_user_id']!==$owner)return null;
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
        $stmt=$pdo->prepare("SELECT r.*,c.owner_user_id AS contact_owner_user_id,c.agent_registry_id,c.display_name,c.operator_name,c.visitor_class,c.risk_score,c.trust_score FROM vp3_agent_access_requests r INNER JOIN vp3_agent_contacts c ON c.id=r.agent_contact_id WHERE r.owner_user_id=? AND r.request_token_hash=? FOR UPDATE");
        $stmt->execute([$ownerUserId,$hash]);$row=$stmt->fetch();
        if(!$row||(int)$row['contact_owner_user_id']!==$ownerUserId||(string)$row['capability']!=='agent.message'||!hash_equals($hash,(string)$row['request_token_hash'])||!in_array((string)$row['status'],['approved_once','approved'],true)){$pdo->rollBack();return null;}
        if(!vp3_agent_access_grant_unexpired_v1($row)){$pdo->prepare("UPDATE vp3_agent_access_requests SET status='expired',updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);$pdo->commit();return null;}
        $registry=vp3_radar_match_agent($userAgent,$pdo);
        if(!$registry||(int)$registry['id']!==(int)$row['agent_registry_id']||(string)$registry['visitor_class']!=='ai_user_agent'||(string)$row['visitor_class']!=='ai_user_agent'){$pdo->rollBack();return null;}
        if((string)$row['status']==='approved_once')$pdo->prepare("UPDATE vp3_agent_access_requests SET status='consumed',use_count=use_count+1,last_used_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
        else $pdo->prepare('UPDATE vp3_agent_access_requests SET use_count=use_count+1,last_used_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        // This marker identifies this exact one-use claim, including retries in
        // the same second. It is an audit event, never a conversation identity.
        $row['_claim_event_id']=0;
        if((string)$row['status']==='approved_once')$row['_claim_event_id']=vp3_agent_access_event($pdo,['id'=>(int)$row['property_id'],'owner_user_id'=>$ownerUserId],['id'=>(int)$row['agent_contact_id']],'agent_message_claimed','One-time Agent Messaging grant claimed.',['request_id'=>(int)$row['id'],'capability'=>'agent.message'], 'low',0,200);
        $row['_claim_use_count']=(int)$row['use_count']+1;
        $row['_request_user_agent']=$userAgent; // Request memory only; never persisted.
        $row['_provider_attempted']=false;$row['_outbound_recorded']=false;
        $pdo->commit();return $row;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Only an unchanged, still-authorized claim that never reached inference may retry. */
function vp3_agent_access_restore_once(PDO $pdo,int $ownerUserId,int $requestId,array $grant=[],array $context=[]): bool
{
    if($ownerUserId<1||$requestId<1||(int)($grant['id']??0)!==$requestId||(int)($grant['owner_user_id']??0)!==$ownerUserId||($grant['status']??'')!=='approved_once'||!empty($grant['_provider_attempted'])||!empty($grant['_outbound_recorded'])||!$context)return false;
    try{
        vp3_agent_message_authority_v1($pdo,$context,$grant);
        $pdo->beginTransaction();
        $live=vp3_agent_message_grant_live_v1($pdo,$grant,true);
        $stmt=$pdo->prepare("UPDATE vp3_agent_access_requests SET status='approved_once',use_count=?,last_used_at=?,updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='consumed' AND use_count=? AND request_token_hash=?");
        $stmt->execute([(int)$grant['use_count'],$grant['last_used_at']??null,$requestId,$ownerUserId,(int)$live['use_count'],(string)$grant['request_token_hash']]);
        $restored=$stmt->rowCount()===1;$pdo->commit();return $restored;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();return false;}
}

function vp3_agent_access_grant_unexpired_v1(array $grant): bool
{
    $until=$grant['approved_until']??null;
    if($until===null||$until==='')return ($grant['status']??'')!=='approved_once'&&($grant['status']??'')!=='consumed';
    if(!is_string($until))return false;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$until,new DateTimeZone(date_default_timezone_get()));
    return $date!==false&&$date->format('Y-m-d H:i:s')===$until&&$date->getTimestamp()>time();
}

/** JSON guards work on existing MySQL and SQLite storage; malformed rows are never decoded by SQL. */
function vp3_agent_message_request_sql_v1(): string
{
    return "CAST(JSON_EXTRACT(CASE WHEN LENGTH(details_json)<=131072 AND JSON_VALID(details_json) THEN details_json ELSE '{}' END,'$.request_id') AS UNSIGNED)";
}

function vp3_agent_message_grant_live_v1(PDO $pdo,array $grant,bool $forUpdate=false): array
{
    $deny=static fn()=>new RuntimeException('VP3_AGENT_MESSAGE_AUTHORITY',403);
    $requestId=(int)($grant['id']??0);$owner=(int)($grant['owner_user_id']??0);
    if($requestId<1||$owner<1||!is_int($grant['_claim_use_count']??null)||!is_string($grant['_request_user_agent']??null))throw $deny();
    $stmt=$pdo->prepare('SELECT r.*,c.owner_user_id AS contact_owner_user_id,c.agent_registry_id,c.display_name,c.operator_name,c.visitor_class,c.risk_score,c.trust_score FROM vp3_agent_access_requests r INNER JOIN vp3_agent_contacts c ON c.id=r.agent_contact_id WHERE r.id=? AND r.owner_user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$requestId,$owner]);$live=$stmt->fetch();
    if(!$live||(int)$live['contact_owner_user_id']!==$owner||(string)$live['capability']!=='agent.message'||(string)$live['visitor_class']!=='ai_user_agent'||!vp3_agent_access_grant_unexpired_v1($live))throw $deny();
    foreach(['agent_contact_id','property_id','agent_registry_id'] as $key)if((int)$live[$key]<1||(int)$live[$key]!== (int)($grant[$key]??0))throw $deny();
    foreach(['request_token_hash','capability','approved_until','created_at'] as $key)if(!hash_equals((string)($grant[$key]??''),(string)($live[$key]??'')))throw $deny();
    $registry=vp3_radar_match_agent($grant['_request_user_agent'],$pdo);
    if(!$registry||(int)$registry['id']!==(int)$live['agent_registry_id']||(string)$registry['visitor_class']!=='ai_user_agent')throw $deny();
    if(($grant['status']??'')==='approved_once'){
        if($live['status']!=='consumed'||(int)$live['use_count']!==$grant['_claim_use_count']||(int)($grant['_claim_event_id']??0)<1)throw $deny();
        $last=$pdo->prepare("SELECT id FROM vp3_radar_events WHERE owner_user_id=? AND agent_contact_id=? AND property_id=? AND event_type='agent_message_claimed' AND ".vp3_agent_message_request_sql_v1().'=? ORDER BY id DESC LIMIT 1');
        $last->execute([$owner,(int)$grant['agent_contact_id'],(int)$grant['property_id'],$requestId]);
        if((int)$last->fetchColumn()!==(int)$grant['_claim_event_id'])throw $deny();
    }elseif(($grant['status']??'')!=='approved'||$live['status']!=='approved'||(int)$live['use_count']<$grant['_claim_use_count'])throw $deny();
    return $live;
}

/** Re-read the exact public surface without creating/reactivating Radar state. */
function vp3_agent_message_authority_v1(PDO $pdo,array $expected,array $grant): array
{
    $deny=static fn()=>new RuntimeException('VP3_AGENT_MESSAGE_AUTHORITY',403);
    $owner=(int)($expected['profile']['user_id']??0);$username=(string)($expected['profile']['username']??'');
    if($owner<1||$owner!==(int)($grant['owner_user_id']??0)||$username==='')throw $deny();
    $current=vp3_agent_access_owner_context($pdo,$username,false);
    if(!$current||(int)$current['profile']['user_id']!==$owner||!hash_equals($username,(string)$current['profile']['username']))throw $deny();
    foreach(['id','owner_user_id'] as $key)if((int)($current['agent'][$key]??0)<1||(int)$current['agent'][$key]!== (int)($expected['agent'][$key]??0))throw $deny();
    if(!empty($current['agent']['retired_at']))throw $deny();
    foreach(['display_name','bio','profile_agent_instructions','profile_agent_greeting'] as $key)if((string)($current['profile'][$key]??'')!==(string)($expected['profile'][$key]??''))throw $deny();
    foreach(['instructions','display_name'] as $key)if((string)($current['agent'][$key]??'')!==(string)($expected['agent'][$key]??''))throw $deny();
    if((int)$current['property']['id']!==(int)($expected['property']['id']??0)||(int)$current['property']['id']!==(int)($grant['property_id']??0))throw $deny();
    $live=vp3_agent_message_grant_live_v1($pdo,$grant);
    $contact=['id'=>(int)$live['agent_contact_id'],'display_name'=>(string)$live['display_name'],'operator_name'=>(string)$live['operator_name'],'visitor_class'=>(string)$live['visitor_class'],'risk_score'=>(int)$live['risk_score'],'trust_score'=>(int)$live['trust_score']];
    $decision=vp3_radar_gateway_decision($pdo,$current['property'],$contact,'/api/agent-message.php');
    if(empty($decision['allowed']))throw new RuntimeException('Agent Gateway denies messaging for this contact.',403);
    $current['contact']=$contact;return $current;
}

function vp3_agent_message_rate_check(PDO $pdo,int $ownerUserId,int $contactId): void
{
    $stmt=$pdo->prepare("SELECT COUNT(*) total,MAX(occurred_at) last_at FROM vp3_radar_events WHERE owner_user_id=? AND agent_contact_id=? AND event_type='agent_message_inbound' AND occurred_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)");
    $stmt->execute([$ownerUserId,$contactId]);$row=$stmt->fetch()?:[];
    if((int)($row['total']??0)>=6)throw new RuntimeException('Agent messaging rate limit reached.');
    if(!empty($row['last_at'])&&time()-strtotime((string)$row['last_at'])<2)throw new RuntimeException('Please wait before sending another agent message.');
}

function vp3_agent_message_binding_v1(array $context,array $grant): array
{
    return ['contract'=>VP3_AGENT_MESSAGE_HISTORY_V1,'request_id'=>(int)$grant['id'],'profile_agent_id'=>(int)$context['agent']['id'],'profile_username'=>(string)$context['profile']['username'],
        'grant_binding'=>hash('sha256',json_encode([(int)$grant['owner_user_id'],(int)$grant['agent_contact_id'],(int)$grant['property_id'],(int)$grant['id'],(string)$grant['request_token_hash'],(string)$grant['created_at'],(string)$grant['capability']],JSON_THROW_ON_ERROR))];
}

function vp3_agent_message_history(PDO $pdo,array $context,array $grant,int $limit=12,?array &$sourceProofs=null): array
{
    $sourceProofs=[];
    $scope=vp3_agent_message_binding_v1($context,$grant);$owner=(int)$context['profile']['user_id'];
    if($owner<1||$owner!==(int)$grant['owner_user_id']||$scope['request_id']<1||$scope['profile_agent_id']<1||(int)$grant['agent_contact_id']<1||(int)$grant['property_id']<1)return [];
    $limit=max(2,min(20,$limit));
    $agentSql="CAST(JSON_EXTRACT(CASE WHEN LENGTH(details_json)<=131072 AND JSON_VALID(details_json) THEN details_json ELSE '{}' END,'$.profile_agent_id') AS UNSIGNED)";
    $stmt=$pdo->prepare("SELECT event_type,details_json FROM vp3_radar_events WHERE owner_user_id=? AND agent_contact_id=? AND property_id=? AND event_type IN ('agent_message_inbound','agent_message_outbound') AND ".vp3_agent_message_request_sql_v1()."=? AND {$agentSql}=? ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute([$owner,(int)$grant['agent_contact_id'],(int)$grant['property_id'],$scope['request_id'],$scope['profile_agent_id']]);$rows=array_reverse($stmt->fetchAll()?:[]);$history=[];
    foreach($rows as $row){
        $raw=$row['details_json']??null;if(!is_string($raw)||strlen($raw)>131072)continue;
        try{$details=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(Throwable $e){continue;}
        if(!is_array($details)||array_is_list($details))continue;
        foreach($scope as $key=>$value)if(($details[$key]??null)!==$value)continue 2;
        $text=$details['message']??null;if(!is_string($text)||!mb_check_encoding($text,'UTF-8'))continue;
        $text=trim($text);$isInbound=$row['event_type']==='agent_message_inbound';
        if($text===''||mb_strlen($text)>($isInbound?VP3_AGENT_MESSAGE_MAX_LENGTH:16000))continue;
        if(!$isInbound){
            $citations=$details['sources']??null;$sources=$details['source_dependencies']??null;
            if(!is_array($citations)||!array_is_list($citations)||count($citations)>20||!is_array($sources)||!array_is_list($sources)||count($sources)>128)continue;
            require_once __DIR__.'/profile-agent-public-service-v110.php';
            if(!vp3_profile_agent_public_sources_current_v1($pdo,$context+['visitor'=>null],$citations,[$sources]))continue;
            try{$sourceProofs[]=vp3_profile_agent_public_source_dependencies_v1($citations,[$sources]);}catch(VP3ProfileAgentPublicException $e){continue;}
        }
        $history[]=['role'=>$isInbound?'user':'assistant','message'=>$text];
    }
    return $history;
}

function vp3_agent_message_context_authorized_v1(PDO $pdo,array $context,array $approvedContext,array $historyContexts=[]): void
{
    require_once __DIR__.'/profile-agent-public-service-v110.php';
    if(count($historyContexts)>20)throw new RuntimeException('VP3_AGENT_MESSAGE_AUTHORITY',403);
    foreach($historyContexts as $sources)if(!is_array($sources)||!array_is_list($sources))throw new RuntimeException('VP3_AGENT_MESSAGE_AUTHORITY',403);
    if(!vp3_profile_agent_public_sources_current_v1($pdo,$context+['visitor'=>null],$approvedContext,$historyContexts))throw new RuntimeException('VP3_AGENT_MESSAGE_AUTHORITY',403);
}

function vp3_agent_message_generate(PDO $pdo,array $context,array &$grant,string $query): array
{
    $live=vp3_agent_message_authority_v1($pdo,$context,$grant);
    $profile=$live['profile'];$ownerUser=$live['owner_user'];$agent=$live['agent'];$property=$live['property'];$contact=$live['contact'];
    vp3_agent_message_rate_check($pdo,(int)$profile['user_id'],(int)$contact['id']);
    $query=trim($query);if($query===''||!mb_check_encoding($query,'UTF-8')||mb_strlen($query)>VP3_AGENT_MESSAGE_MAX_LENGTH)throw new RuntimeException('Enter an agent message up to 2,000 characters.');
    $historySources=[];$history=vp3_agent_message_history($pdo,$live,$grant,12,$historySources);$binding=vp3_agent_message_binding_v1($live,$grant);
    vp3_agent_access_event($pdo,$property,$contact,'agent_message_inbound',trim((string)$contact['display_name']).' sent a message to the Profile Agent.',$binding+['capability'=>'agent.message','message'=>$query], 'low',85,200);
    $approvedContext=profile_agent_context($pdo,$profile,$agent,null,$query);if(count($approvedContext)>20)$approvedContext=array_slice($approvedContext,0,20);
    vp3_agent_message_context_authorized_v1($pdo,$live,$approvedContext,$historySources);
    $sourceDependencies=vp3_profile_agent_public_source_dependencies_v1($approvedContext,$historySources);
    vp3_agent_message_authority_v1($pdo,$context,$grant);
    $substantive=array_values(array_filter($approvedContext,static fn(array $c):bool=>!in_array((string)$c['source'],['profile:identity','profile:rules'],true)));
    $greeting=(bool)preg_match('/^(?:hi|hello|hey|good\s+(?:morning|afternoon|evening))[!.\s]*$/i',$query);
    $usage=[];$provider='local';$model='';
    if(!$substantive&&!$greeting)$answer='I do not have approved public information to answer that accurately.';
    elseif($greeting)$answer=trim((string)($profile['profile_agent_greeting']??''))?:'Hello — I am '.(string)$agent['display_name'].', '.(string)$profile['display_name'].'’s AI representative.';
    else{
        $turnControl=['surface'=>'profile_agent','profile_owner_user_id'=>(int)$profile['user_id'],'profile_agent_id'=>(int)$agent['id'],'profile_agent_name'=>(string)$agent['display_name'],'profile_owner_name'=>(string)$profile['display_name'],'profile_instructions'=>(string)($profile['profile_agent_instructions']??'')];
        $grant['_provider_attempted']=true;
        $result=ai_generate_chat_response($query,$history,$approvedContext,$ownerUser,'agent.messaging',$turnControl);
        if(empty($result['ok'])){
            // This canonical flag is returned only before the provider request.
            if(!empty($result['quota_exhausted'])){$grant['_provider_attempted']=false;throw new RuntimeException('VP3_AGENT_MESSAGE_QUOTA');}
            throw new RuntimeException('VP3_AGENT_MESSAGE_PROVIDER');
        }
        $answer=$result['answer']??null;if(!is_string($answer)||!mb_check_encoding($answer,'UTF-8')||mb_strlen($answer)>16000)throw new RuntimeException('VP3_AGENT_MESSAGE_PROVIDER');
        $answer=trim($answer);if($answer==='')throw new RuntimeException('VP3_AGENT_MESSAGE_PROVIDER');
        $usage=is_array($result['usage']??null)?$result['usage']:[];$provider=(string)($result['provider']??'');$model=(string)($result['model']??'');
    }
    vp3_agent_message_context_authorized_v1($pdo,$live,$approvedContext,$historySources);
    vp3_agent_message_authority_v1($pdo,$context,$grant);
    $sources=[];foreach($approvedContext as $item){$source=(string)($item['source']??'');if(!in_array($source,['profile:identity','profile:rules'],true))$sources[]=['source'=>$source,'title'=>(string)($item['title']??'')];}
    $grant['_outbound_recorded']=true;
    vp3_agent_access_event($pdo,$property,$contact,'agent_message_outbound',trim((string)$agent['display_name']).' replied to '.trim((string)$contact['display_name']).'.',$binding+['capability'=>'agent.message','message'=>$answer,'sources'=>$sources,'source_dependencies'=>$sourceDependencies,'provider'=>$provider,'model'=>$model,'usage'=>$usage], 'low',85,200);
    vp3_agent_message_context_authorized_v1($pdo,$live,$approvedContext,$historySources);
    vp3_agent_message_authority_v1($pdo,$context,$grant);
    return ['answer'=>$answer,'sources'=>$sources,'agent'=>['name'=>(string)$agent['display_name'],'system_name'=>system_agent_name()],'usage'=>$usage,'_approved_context'=>$approvedContext,'_history_contexts'=>$historySources];
}
