<?php
declare(strict_types=1);

const VP3_RADAR_SERVER_TOKEN_BYTES = 32;

function vp3_radar_server_property_for_owner(PDO $pdo,int $ownerUserId,int $propertyId): ?array
{
    if($ownerUserId<1||$propertyId<1||!vp3_radar_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT * FROM vp3_radar_properties WHERE id=? AND owner_user_id=? AND property_type='external' LIMIT 1");
    $stmt->execute([$propertyId,$ownerUserId]);
    return $stmt->fetch()?:null;
}

function vp3_radar_server_enrich_site_state(PDO $pdo,array $user,array $state): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||empty($state['sites'])||!is_array($state['sites']))return $state;
    $stmt=$pdo->prepare("SELECT id,(secret_hash IS NOT NULL AND secret_hash<>'') AS server_token_configured FROM vp3_radar_properties WHERE owner_user_id=? AND property_type='external'");
    $stmt->execute([$uid]);
    $configured=[];
    foreach($stmt->fetchAll()?:[] as $row)$configured[(int)$row['id']]=(bool)$row['server_token_configured'];
    foreach($state['sites'] as &$site)$site['server_token_configured']=$configured[(int)($site['id']??0)]??false;
    unset($site);
    return $state;
}

function vp3_radar_server_token_rotate(PDO $pdo,array $user,int $propertyId): array
{
    $uid=(int)($user['id']??0);
    $property=vp3_radar_server_property_for_owner($pdo,$uid,$propertyId);
    if(!$property)throw new RuntimeException('Connected site not found.');
    if(empty($property['is_active']))throw new RuntimeException('Reactivate this connected site before creating a server token.');
    $token=bin2hex(random_bytes(VP3_RADAR_SERVER_TOKEN_BYTES));
    $hash=hash('sha256',$token);
    $pdo->prepare('UPDATE vp3_radar_properties SET secret_hash=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$hash,$propertyId,$uid]);
    return [
        'server_token'=>$token,
        'state'=>vp3_radar_server_enrich_site_state($pdo,$user,vp3_radar_external_site_state($pdo,$user)),
    ];
}

function vp3_radar_server_token_revoke(PDO $pdo,array $user,int $propertyId): array
{
    $uid=(int)($user['id']??0);
    $property=vp3_radar_server_property_for_owner($pdo,$uid,$propertyId);
    if(!$property)throw new RuntimeException('Connected site not found.');
    $pdo->prepare('UPDATE vp3_radar_properties SET secret_hash=NULL,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$propertyId,$uid]);
    return vp3_radar_server_enrich_site_state($pdo,$user,vp3_radar_external_site_state($pdo,$user));
}

function vp3_radar_server_token_valid(array $property,string $token): bool
{
    $stored=strtolower(trim((string)($property['secret_hash']??'')));
    $token=trim($token);
    if(!preg_match('/^[a-f0-9]{64}$/',$stored)||!preg_match('/^[a-f0-9]{64}$/i',$token))return false;
    return hash_equals($stored,hash('sha256',strtolower($token)));
}

function vp3_radar_server_request_token(): string
{
    $token=trim((string)($_SERVER['HTTP_X_VP3_RADAR_TOKEN']??''));
    if($token!=='')return $token;
    $authorization=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
    if(preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/',$authorization,$m))return (string)$m[1];
    return '';
}

function vp3_radar_server_unknown_contact(PDO $pdo,int $ownerUserId): ?array
{
    if($ownerUserId<1)return null;
    $identityKey=hash('sha256',$ownerUserId.'|server-side-unknown-automation');
    $stmt=$pdo->prepare("INSERT INTO vp3_agent_contacts
      (owner_user_id,agent_registry_id,identity_key,display_name,operator_name,contact_type,visitor_class,verification_status,confidence_score,trust_score,risk_score,engagement_score,value_score,cost_score,relationship_status,inferred_intent,intent_confidence,first_seen_at,last_seen_at)
      VALUES (?,NULL,?,'Unrecognized automated agent','','agent','automated_unknown','unknown',35,20,15,0,15,0,'new','',0,NOW(),NOW())
      ON DUPLICATE KEY UPDATE last_seen_at=NOW(),id=LAST_INSERT_ID(id)");
    $stmt->execute([$ownerUserId,$identityKey]);
    $id=(int)$pdo->lastInsertId();
    if($id<1){$find=$pdo->prepare('SELECT id FROM vp3_agent_contacts WHERE owner_user_id=? AND identity_key=? LIMIT 1');$find->execute([$ownerUserId,$identityKey]);$id=(int)$find->fetchColumn();}
    if($id<1)return null;
    $get=$pdo->prepare('SELECT * FROM vp3_agent_contacts WHERE id=? AND owner_user_id=? LIMIT 1');$get->execute([$id,$ownerUserId]);
    return $get->fetch()?:null;
}

function vp3_radar_server_identity(PDO $pdo,array $property,string $userAgent): ?array
{
    $owner=(int)($property['owner_user_id']??0);
    if($owner<1||$userAgent==='')return null;
    $registry=vp3_radar_match_agent($userAgent,$pdo);
    if($registry){
        $identity=vp3_radar_native_identity($pdo,$owner,$userAgent);
        return $identity?['contact'=>$identity['contact'],'registry'=>$registry,'known'=>true]:null;
    }
    if(!vp3_radar_looks_automated($userAgent))return null;
    $contact=vp3_radar_server_unknown_contact($pdo,$owner);
    return $contact?['contact'=>$contact,'registry'=>null,'known'=>false]:null;
}

function vp3_radar_server_method(string $value): string
{
    $value=strtoupper(trim($value));
    if(!preg_match('/^[A-Z]{3,12}$/',$value))return 'GET';
    return $value;
}

function vp3_radar_server_status(mixed $value): ?int
{
    $status=(int)$value;
    return $status>=100&&$status<=599?$status:null;
}

function vp3_radar_server_event(PDO $pdo,array $property,array $contact,array $session,string $path,string $referrerHost,string $method,?int $statusCode,bool $known): ?array
{
    $signals=vp3_radar_external_signals($session,$path);
    $risk=max($known?5:15,vp3_radar_risk_score($signals));
    $severity=vp3_radar_severity($risk);
    $class=(string)$contact['visitor_class'];
    $significance=match($class){'ai_user_agent'=>80,'ai_search'=>60,'ai_crawler'=>45,'automated_unknown'=>40,default=>35};
    if(!empty($session['is_new']))$significance=min(100,$significance+10);
    if($risk>=70)$significance=max($significance,90);
    $identity=trim((string)$contact['operator_name']);
    $identity.=($identity!==''?' · ':'').trim((string)$contact['display_name']);
    $summary=$identity.' requested '.$property['domain'].$path.'.';
    $details=[
        'property_type'=>'external',
        'property_domain'=>(string)$property['domain'],
        'visitor_class'=>$class,
        'verification_status'=>(string)$contact['verification_status'],
        'confidence_score'=>(int)$contact['confidence_score'],
        'referrer_host'=>$referrerHost,
        'known_agent'=>$known,
        'collector'=>'server',
        'signals'=>$signals,
    ];
    $stmt=$pdo->prepare("INSERT INTO vp3_radar_events
      (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at)
      VALUES (?,?,?,?,'external_agent_request',?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([(int)$property['owner_user_id'],(int)$property['id'],(int)$session['id'],(int)$contact['id'],$severity,$path,$method,$statusCode,$significance,$risk,mb_strimwidth($summary,0,500,'…'),json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $eventId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE vp3_radar_sessions SET event_count=event_count+1 WHERE id=?')->execute([(int)$session['id']]);
    $sessionInc=!empty($session['is_new'])?1:0;$referral=$referrerHost!==''?1:0;
    $pdo->prepare('UPDATE vp3_agent_contacts SET session_count=session_count+?,request_count=request_count+1,page_view_count=page_view_count+1,referral_count=referral_count+?,risk_score=GREATEST(risk_score,?),engagement_score=LEAST(100,20+(page_view_count+1)*5+(session_count+?)*8),value_score=?,cost_score=LEAST(100,5+(request_count+1)),last_seen_at=NOW() WHERE id=?')
      ->execute([$sessionInc,$referral,$risk,$sessionInc,vp3_radar_native_value_score($class,(int)$contact['page_view_count']+1,(int)$contact['session_count']+$sessionInc),(int)$contact['id']]);
    $pdo->prepare('UPDATE vp3_radar_properties SET verified_at=COALESCE(verified_at,NOW()),updated_at=NOW() WHERE id=?')->execute([(int)$property['id']]);
    $get=$pdo->prepare('SELECT * FROM vp3_radar_events WHERE id=? LIMIT 1');$get->execute([$eventId]);
    return $get->fetch()?:null;
}

function vp3_radar_server_collect(PDO $pdo,array $property,array $payload): bool
{
    $userAgent=mb_strimwidth(trim((string)($payload['user_agent']??'')),0,1000,'');
    $identity=vp3_radar_server_identity($pdo,$property,$userAgent);
    if(!$identity)return false;
    $contact=$identity['contact'];
    $propertyId=(int)($property['id']??0);$contactId=(int)($contact['id']??0);
    if(vp3_radar_external_session_at_cap($pdo,$propertyId,$contactId))return false;
    $path=vp3_radar_external_path((string)($payload['path']??'/'));
    $referrer=vp3_radar_external_referrer_host((string)($payload['referrer_host']??''),(string)$property['domain']);
    $session=vp3_radar_external_session($pdo,$property,$contact,$path,$referrer);
    if(!$session)return false;
    $method=vp3_radar_server_method((string)($payload['method']??'GET'));
    $status=vp3_radar_server_status($payload['status_code']??null);
    $event=vp3_radar_server_event($pdo,$property,$contact,$session,$path,$referrer,$method,$status,(bool)$identity['known']);
    if(!$event)return false;
    vp3_radar_external_notify($pdo,$property,$contact,$session,$event);
    return true;
}
