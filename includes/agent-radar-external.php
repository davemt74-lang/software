<?php
declare(strict_types=1);

const VP3_RADAR_EXTERNAL_SESSION_SECONDS = 1800;
const VP3_RADAR_EXTERNAL_SESSION_EVENT_CAP = 120;

function vp3_radar_external_domain_normalize(string $value): string
{
    $value=trim($value);
    if($value==='')throw new RuntimeException('Enter a website domain.');
    $candidate=str_contains($value,'://')?$value:'https://'.$value;
    $host=strtolower(trim((string)parse_url($candidate,PHP_URL_HOST)));
    $host=rtrim($host,'.');
    if(str_starts_with($host,'www.'))$host=substr($host,4);
    if($host===''||$host==='localhost'||filter_var($host,FILTER_VALIDATE_IP))throw new RuntimeException('Enter a public website domain.');
    if(!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))throw new RuntimeException('Enter a valid website domain.');
    return mb_strimwidth($host,0,190,'');
}

function vp3_radar_external_site_state(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);
    $limit=vp3_radar_external_site_limit($user);
    $empty=['ready'=>false,'limit'=>$limit,'active_count'=>0,'can_add'=>false,'sites'=>[]];
    if($uid<1||!vp3_radar_schema_ready($pdo))return $empty;
    $stmt=$pdo->prepare("SELECT p.id,p.label,p.domain,p.public_key,p.verified_at,p.is_active,p.created_at,p.updated_at,
      (SELECT COUNT(*) FROM vp3_radar_sessions s WHERE s.property_id=p.id) AS session_count,
      (SELECT COUNT(*) FROM vp3_radar_events e WHERE e.property_id=p.id) AS event_count,
      (SELECT MAX(e2.occurred_at) FROM vp3_radar_events e2 WHERE e2.property_id=p.id) AS last_event_at
      FROM vp3_radar_properties p
      WHERE p.owner_user_id=? AND p.property_type='external'
      ORDER BY p.is_active DESC,p.updated_at DESC,p.id DESC");
    $stmt->execute([$uid]);
    $sites=$stmt->fetchAll()?:[];
    return [
        'ready'=>true,
        'limit'=>$limit,
        'active_count'=>vp3_radar_external_site_count($uid,$pdo),
        'can_add'=>vp3_radar_can_add_external_site($user,$pdo),
        'sites'=>$sites,
    ];
}

function vp3_radar_external_site_create(PDO $pdo,array $user,string $domain,string $label=''): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!vp3_radar_schema_ready($pdo))throw new RuntimeException('Agent Radar is not ready.');
    $domain=vp3_radar_external_domain_normalize($domain);
    $label=mb_strimwidth(trim($label),0,190,'');
    if($label==='')$label=$domain;

    $find=$pdo->prepare("SELECT * FROM vp3_radar_properties WHERE owner_user_id=? AND property_type='external' AND domain=? LIMIT 1");
    $find->execute([$uid,$domain]);
    $existing=$find->fetch();
    if($existing&&!empty($existing['is_active']))return vp3_radar_external_site_state($pdo,$user);
    if(!vp3_radar_can_add_external_site($user,$pdo))throw new RuntimeException('Your current package has reached its connected-site limit.');

    if($existing){
        $pdo->prepare("UPDATE vp3_radar_properties SET label=?,is_active=1,updated_at=NOW() WHERE id=? AND owner_user_id=?")
            ->execute([$label,(int)$existing['id'],$uid]);
        return vp3_radar_external_site_state($pdo,$user);
    }

    $publicKey=bin2hex(random_bytes(20));
    $verificationToken=bin2hex(random_bytes(32));
    $stmt=$pdo->prepare("INSERT INTO vp3_radar_properties
      (owner_user_id,property_type,label,domain,public_key,verification_token,is_active)
      VALUES (?,'external',?,?,?,?,1)");
    $stmt->execute([$uid,$label,$domain,$publicKey,$verificationToken]);
    return vp3_radar_external_site_state($pdo,$user);
}

function vp3_radar_external_site_set_active(PDO $pdo,array $user,int $propertyId,bool $active): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||$propertyId<1)throw new RuntimeException('Connected site not found.');
    $stmt=$pdo->prepare("SELECT * FROM vp3_radar_properties WHERE id=? AND owner_user_id=? AND property_type='external' LIMIT 1");
    $stmt->execute([$propertyId,$uid]);
    $site=$stmt->fetch();
    if(!$site)throw new RuntimeException('Connected site not found.');
    if($active&&!$site['is_active']&&!vp3_radar_can_add_external_site($user,$pdo))throw new RuntimeException('Your current package has reached its connected-site limit.');
    $pdo->prepare("UPDATE vp3_radar_properties SET is_active=?,updated_at=NOW() WHERE id=? AND owner_user_id=?")
        ->execute([$active?1:0,$propertyId,$uid]);
    return vp3_radar_external_site_state($pdo,$user);
}

function vp3_radar_external_property_by_key(PDO $pdo,string $publicKey): ?array
{
    $publicKey=strtolower(trim($publicKey));
    if(!preg_match('/^[a-f0-9]{40}$/',$publicKey)||!vp3_radar_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT * FROM vp3_radar_properties WHERE public_key=? AND property_type='external' AND is_active=1 LIMIT 1");
    $stmt->execute([$publicKey]);
    return $stmt->fetch()?:null;
}

function vp3_radar_external_origin_host(): string
{
    $origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
    if($origin!=='')return strtolower(trim((string)parse_url($origin,PHP_URL_HOST)));
    $referer=trim((string)($_SERVER['HTTP_REFERER']??''));
    return $referer!==''?strtolower(trim((string)parse_url($referer,PHP_URL_HOST))):'';
}

function vp3_radar_external_origin_allowed(string $registeredDomain,string $originHost): bool
{
    try{$registeredDomain=vp3_radar_external_domain_normalize($registeredDomain);}catch(Throwable $e){return false;}
    $originHost=strtolower(rtrim(trim($originHost),'.'));
    if(str_starts_with($originHost,'www.'))$originHost=substr($originHost,4);
    if($originHost===''||!filter_var($originHost,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))return false;
    return $originHost===$registeredDomain||str_ends_with($originHost,'.'.$registeredDomain);
}

function vp3_radar_external_path(string $value): string
{
    $value=trim(preg_replace('/[\x00-\x1F\x7F]/u','',$value)??'');
    if($value==='')return '/';
    $path=(string)parse_url($value,PHP_URL_PATH);
    if($path==='')$path='/';
    if(!str_starts_with($path,'/'))$path='/'.$path;
    return mb_strimwidth($path,0,500,'');
}

function vp3_radar_external_referrer_host(string $value,string $siteDomain): string
{
    $value=strtolower(trim($value));
    if($value==='')return '';
    $host=str_contains($value,'://')?(string)parse_url($value,PHP_URL_HOST):$value;
    $host=strtolower(rtrim(trim($host),'.'));
    if(str_starts_with($host,'www.'))$host=substr($host,4);
    if($host===''||!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))return '';
    return vp3_radar_external_origin_allowed($siteDomain,$host)?'':mb_strimwidth($host,0,190,'');
}

function vp3_radar_external_session_key(int $propertyId,int $contactId): string
{
    $bucket=(int)floor(time()/VP3_RADAR_EXTERNAL_SESSION_SECONDS);
    return hash('sha256',$propertyId.'|'.$contactId.'|'.$bucket);
}

function vp3_radar_external_session_at_cap(PDO $pdo,int $propertyId,int $contactId): bool
{
    if($propertyId<1||$contactId<1)return true;
    $sessionKey=vp3_radar_external_session_key($propertyId,$contactId);
    $stmt=$pdo->prepare('SELECT request_count FROM vp3_radar_sessions WHERE property_id=? AND session_key=? LIMIT 1');
    $stmt->execute([$propertyId,$sessionKey]);
    $count=$stmt->fetchColumn();
    return $count!==false&&(int)$count>=VP3_RADAR_EXTERNAL_SESSION_EVENT_CAP;
}

function vp3_radar_external_session(PDO $pdo,array $property,array $contact,string $path,string $referrerHost): ?array
{
    $propertyId=(int)($property['id']??0);$owner=(int)($property['owner_user_id']??0);$contactId=(int)($contact['id']??0);
    if($propertyId<1||$owner<1||$contactId<1)return null;
    $sessionKey=vp3_radar_external_session_key($propertyId,$contactId);
    $stmt=$pdo->prepare("INSERT INTO vp3_radar_sessions
      (property_id,owner_user_id,agent_contact_id,session_key,visitor_type,entry_path,exit_path,referrer_host,request_count,page_view_count,event_count,started_at,last_seen_at)
      VALUES (?,?,?,?,?,?,?,?,1,1,0,NOW(),NOW())
      ON DUPLICATE KEY UPDATE exit_path=VALUES(exit_path),referrer_host=CASE WHEN referrer_host='' THEN VALUES(referrer_host) ELSE referrer_host END,
        request_count=request_count+1,page_view_count=page_view_count+1,last_seen_at=NOW(),id=LAST_INSERT_ID(id)");
    $stmt->execute([$propertyId,$owner,$contactId,$sessionKey,mb_strimwidth((string)$contact['visitor_class'],0,40,''),$path,$path,$referrerHost]);
    $id=(int)$pdo->lastInsertId();
    $get=$pdo->prepare('SELECT * FROM vp3_radar_sessions WHERE id=? AND property_id=? LIMIT 1');
    $get->execute([$id,$propertyId]);
    $session=$get->fetch();
    if(!$session)return null;
    $session['is_new']=(int)$session['request_count']===1;
    return $session;
}

function vp3_radar_external_signals(array $session,string $path): array
{
    $requestCount=max(1,(int)($session['request_count']??1));
    $started=strtotime((string)($session['started_at']??''))?:time();
    $minutes=max(1.0,(time()-$started+60)/60);
    $rpm=(int)ceil($requestCount/$minutes);
    $lower=strtolower($path);
    return [
        'verification_failed'=>false,
        'restricted_probe'=>(bool)preg_match('/(?:\/\.env|\/config(?:\.php)?|\/wp-admin|\/private\/|etc\/passwd|%2e%2e|\.\.\/)/i',$lower),
        'credential_probe'=>false,
        'injection_pattern'=>(bool)preg_match('/(?:union(?:%20|\s)+select|<script|%3cscript|javascript:|sleep\(|benchmark\()/i',$lower),
        'failed_login_burst'=>false,
        'requests_per_minute'=>$rpm,
        'repeat_denied'=>false,
    ];
}

function vp3_radar_external_event(PDO $pdo,array $property,array $contact,array $session,string $path,string $referrerHost): ?array
{
    $signals=vp3_radar_external_signals($session,$path);
    $risk=max(5,vp3_radar_risk_score($signals));
    $severity=vp3_radar_severity($risk);
    $class=(string)$contact['visitor_class'];
    $significance=match($class){'ai_user_agent'=>80,'ai_search'=>60,'ai_crawler'=>45,default=>35};
    if(!empty($session['is_new']))$significance=min(100,$significance+10);
    if($risk>=70)$significance=max($significance,90);
    $identity=trim((string)$contact['operator_name']);
    $identity.=($identity!==''?' · ':'').trim((string)$contact['display_name']);
    $summary=$identity.' visited '.$property['domain'].$path.'.';
    $details=[
        'property_type'=>'external',
        'property_domain'=>(string)$property['domain'],
        'visitor_class'=>$class,
        'verification_status'=>(string)$contact['verification_status'],
        'confidence_score'=>(int)$contact['confidence_score'],
        'referrer_host'=>$referrerHost,
        'known_agent'=>true,
        'collector'=>'browser',
        'signals'=>$signals,
    ];
    $stmt=$pdo->prepare("INSERT INTO vp3_radar_events
      (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at)
      VALUES (?,?,?,?,'external_agent_page_view',?,?,'GET',NULL,?,?,?,?,NOW())");
    $stmt->execute([(int)$property['owner_user_id'],(int)$property['id'],(int)$session['id'],(int)$contact['id'],$severity,$path,$significance,$risk,mb_strimwidth($summary,0,500,'…'),json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $eventId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE vp3_radar_sessions SET event_count=event_count+1 WHERE id=?')->execute([(int)$session['id']]);
    $sessionInc=!empty($session['is_new'])?1:0;
    $referral=$referrerHost!==''?1:0;
    $pdo->prepare('UPDATE vp3_agent_contacts SET session_count=session_count+?,request_count=request_count+1,page_view_count=page_view_count+1,referral_count=referral_count+?,risk_score=GREATEST(risk_score,?),engagement_score=LEAST(100,20+(page_view_count+1)*5+(session_count+?)*8),value_score=?,cost_score=LEAST(100,5+(request_count+1)),last_seen_at=NOW() WHERE id=?')
      ->execute([$sessionInc,$referral,$risk,$sessionInc,vp3_radar_native_value_score($class,(int)$contact['page_view_count']+1,(int)$contact['session_count']+$sessionInc),(int)$contact['id']]);
    $pdo->prepare('UPDATE vp3_radar_properties SET verified_at=COALESCE(verified_at,NOW()),updated_at=NOW() WHERE id=?')->execute([(int)$property['id']]);
    $get=$pdo->prepare('SELECT * FROM vp3_radar_events WHERE id=? LIMIT 1');$get->execute([$eventId]);
    return $get->fetch()?:null;
}

function vp3_radar_external_notify(PDO $pdo,array $property,array $contact,array $session,array $event): void
{
    $owner=(int)$property['owner_user_id'];$risk=(int)$event['risk_score'];$class=(string)$contact['visitor_class'];
    $name=trim((string)$contact['display_name'])?:'Automated agent';$operator=trim((string)$contact['operator_name']);
    $site=trim((string)$property['label'])?:trim((string)$property['domain']);
    $target=url('/profile-agent.php?tab=radar');$sourceId=(int)$event['id'];
    if(!empty($session['is_new'])||$risk>=70)vp3_radar_sync_agent_memory($pdo,$owner,$contact,$event);
    if($risk>=70){
        create_notification($owner,'radar_external_security_action','Agent Radar · High-risk activity on '.$site,$name.' produced a risk score of '.$risk.'/100 on '.$property['domain'].'. Review the Radar timeline before allowing broader access.',$target,'radar_event',$sourceId);
        return;
    }
    if(empty($session['is_new']))return;
    if(in_array($class,['ai_user_agent','ai_search'],true)){
        $body=($operator!==''?$operator.' · ':'').str_replace('_',' ',$class).' visited '.$property['domain'].'. Known signatures are not cryptographic identity verification.';
        create_notification($owner,'radar_external_visit_needs_attention','Agent Radar · '.$name.' visited '.$site,$body,$target,'radar_event',$sourceId);
        return;
    }
    create_notification($owner,'agent_activity_radar_external_visit','Agent Radar · '.$name.' · '.$site,($operator!==''?$operator.' · ':'').str_replace('_',' ',$class).' activity recorded on '.$property['domain'].'.',$target,'radar_event',$sourceId);
}

/**
 * Browser collection intentionally accepts only maintained Radar signatures.
 * The public install key is visible in page source, so allowing arbitrary
 * user-agent strings here would permit unbounded spoofed Agent CRM identities.
 * Unknown/non-JavaScript crawler discovery belongs to the server-side layer.
 */
function vp3_radar_external_collect(PDO $pdo,array $property,array $payload,string $userAgent): bool
{
    $registry=vp3_radar_match_agent($userAgent,$pdo);
    if(!$registry)return false;
    $identity=vp3_radar_native_identity($pdo,(int)$property['owner_user_id'],$userAgent);
    if(!$identity)return false;
    $contact=$identity['contact'];
    $propertyId=(int)($property['id']??0);$contactId=(int)($contact['id']??0);
    if(vp3_radar_external_session_at_cap($pdo,$propertyId,$contactId))return false;
    $path=vp3_radar_external_path((string)($payload['path']??'/'));
    $referrer=vp3_radar_external_referrer_host((string)($payload['referrer_host']??''),(string)$property['domain']);
    $session=vp3_radar_external_session($pdo,$property,$contact,$path,$referrer);
    if(!$session)return false;
    $event=vp3_radar_external_event($pdo,$property,$contact,$session,$path,$referrer);
    if(!$event)return false;
    vp3_radar_external_notify($pdo,$property,$contact,$session,$event);
    return true;
}
