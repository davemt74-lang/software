<?php
declare(strict_types=1);

const VP3_AGENT_REFERRAL_TOKEN_BYTES = 24;
const VP3_AGENT_REFERRAL_DAYS = 30;
const VP3_AGENT_REFERRAL_SESSION_SECONDS = 604800;

function vp3_agent_referral_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo&&table_exists('vp3_agent_referrals')&&table_exists('vp3_agent_referral_events');
}

function vp3_agent_referral_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_agent_referrals (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_contact_id BIGINT UNSIGNED NOT NULL,
      source_property_id BIGINT UNSIGNED NULL,
      token_hash CHAR(64) NOT NULL,
      source_type VARCHAR(40) NOT NULL DEFAULT 'agent_message',
      destination_url VARCHAR(1000) NOT NULL DEFAULT '',
      status VARCHAR(20) NOT NULL DEFAULT 'active',
      click_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      conversion_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      first_clicked_at DATETIME NULL,
      last_clicked_at DATETIME NULL,
      last_conversion_at DATETIME NULL,
      expires_at DATETIME NOT NULL,
      metadata_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_agent_referral_token (token_hash),
      INDEX idx_vp3_agent_referral_owner_contact (owner_user_id,agent_contact_id,status,created_at),
      INDEX idx_vp3_agent_referral_expiry (status,expires_at),
      CONSTRAINT fk_vp3_agent_referral_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_agent_referral_contact FOREIGN KEY (agent_contact_id) REFERENCES vp3_agent_contacts(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_agent_referral_property FOREIGN KEY (source_property_id) REFERENCES vp3_radar_properties(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_agent_referral_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      referral_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_contact_id BIGINT UNSIGNED NOT NULL,
      property_id BIGINT UNSIGNED NULL,
      session_hash CHAR(64) NOT NULL,
      event_type VARCHAR(80) NOT NULL,
      value_amount DECIMAL(18,4) NULL,
      occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_vp3_agent_referral_event (referral_id,session_hash,event_type),
      INDEX idx_vp3_agent_ref_event_owner (owner_user_id,occurred_at,id),
      INDEX idx_vp3_agent_ref_event_contact (agent_contact_id,event_type,occurred_at,id),
      CONSTRAINT fk_vp3_agent_ref_event_referral FOREIGN KEY (referral_id) REFERENCES vp3_agent_referrals(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_agent_ref_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_agent_ref_event_contact FOREIGN KEY (agent_contact_id) REFERENCES vp3_agent_contacts(id) ON DELETE CASCADE,
      CONSTRAINT fk_vp3_agent_ref_event_property FOREIGN KEY (property_id) REFERENCES vp3_radar_properties(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_agent_referral_token(string $value): string
{
    $value=strtolower(trim($value));
    return preg_match('/^[a-f0-9]{48}$/',$value)?$value:'';
}

function vp3_agent_referral_public_url(string $destination,string $token): string
{
    $separator=str_contains($destination,'?')?'&':'?';
    return $destination.$separator.'vp3_ref='.rawurlencode($token);
}

function vp3_agent_referral_destination_allowed(PDO $pdo,int $ownerUserId,string $urlValue): bool
{
    if($ownerUserId<1||!filter_var($urlValue,FILTER_VALIDATE_URL))return false;
    $scheme=strtolower((string)parse_url($urlValue,PHP_URL_SCHEME));$host=strtolower((string)parse_url($urlValue,PHP_URL_HOST));
    if(!in_array($scheme,['http','https'],true)||$host==='')return false;
    $profile=profile_for_user($pdo,$ownerUserId,false);
    if($profile&&!empty($profile['username'])){
        $profileHost=strtolower((string)parse_url(profile_public_url((string)$profile['username']),PHP_URL_HOST));
        if($profileHost!==''&&$host===$profileHost)return true;
    }
    $stmt=$pdo->prepare('SELECT 1 FROM vp3_radar_properties WHERE owner_user_id=? AND domain=? AND is_active=1 LIMIT 1');$stmt->execute([$ownerUserId,$host]);
    return (bool)$stmt->fetchColumn();
}

function vp3_agent_referral_create(PDO $pdo,int $ownerUserId,int $agentContactId,?int $sourcePropertyId,string $destination,string $sourceType='agent_message'): ?array
{
    if(!vp3_agent_referral_schema_ready($pdo)||$ownerUserId<1||$agentContactId<1)return null;
    if(!vp3_agent_referral_destination_allowed($pdo,$ownerUserId,$destination))return null;
    $check=$pdo->prepare('SELECT id FROM vp3_agent_contacts WHERE id=? AND owner_user_id=? LIMIT 1');$check->execute([$agentContactId,$ownerUserId]);if(!$check->fetchColumn())return null;
    $token=bin2hex(random_bytes(VP3_AGENT_REFERRAL_TOKEN_BYTES));$hash=hash('sha256',$token);$expires=date('Y-m-d H:i:s',time()+VP3_AGENT_REFERRAL_DAYS*86400);
    $sourceType=mb_strimwidth(preg_replace('/[^a-z0-9_.:-]+/i','_',trim($sourceType))??'agent_message',0,40,'');
    $stmt=$pdo->prepare("INSERT INTO vp3_agent_referrals (owner_user_id,agent_contact_id,source_property_id,token_hash,source_type,destination_url,status,expires_at) VALUES (?,?,?,?,?,?,'active',?)");
    $stmt->execute([$ownerUserId,$agentContactId,$sourcePropertyId?:null,$hash,$sourceType,mb_strimwidth($destination,0,1000,''),$expires]);
    return ['id'=>(int)$pdo->lastInsertId(),'token'=>$token,'url'=>vp3_agent_referral_public_url($destination,$token),'expires_at'=>$expires];
}

function vp3_agent_referral_create_for_message(PDO $pdo,array $context,array $grant): ?array
{
    $profile=$context['profile']??[];$owner=(int)($profile['user_id']??0);$username=(string)($profile['username']??'');$contactId=(int)($grant['agent_contact_id']??0);$propertyId=(int)($context['property']['id']??0);
    if($owner<1||$username===''||$contactId<1)return null;
    return vp3_agent_referral_create($pdo,$owner,$contactId,$propertyId,profile_public_url($username),'agent_message');
}

function vp3_agent_referral_lookup(PDO $pdo,int $ownerUserId,string $token): ?array
{
    $token=vp3_agent_referral_token($token);if($token===''||$ownerUserId<1||!vp3_agent_referral_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT r.*,c.display_name,c.operator_name,c.risk_score,c.trust_score FROM vp3_agent_referrals r INNER JOIN vp3_agent_contacts c ON c.id=r.agent_contact_id WHERE r.owner_user_id=? AND r.token_hash=? AND r.status='active' AND r.expires_at>NOW() LIMIT 1");
    $stmt->execute([$ownerUserId,hash('sha256',$token)]);return $stmt->fetch()?:null;
}

function vp3_agent_referral_session_hash(int $ownerUserId,string $session): string
{
    return hash('sha256','vp3-agent-referral|'.$ownerUserId.'|'.$session);
}

function vp3_agent_referral_conversion_event(string $eventName): bool
{
    $eventName=vp3_analytics_event_name($eventName);
    return in_array($eventName,VP3_ANALYTICS_CONVERSION_EVENTS,true)||str_starts_with($eventName,'conversion.');
}

function vp3_agent_referral_record(PDO $pdo,array $referral,int $propertyId,string $sessionHash,string $eventName,?float $value=null): bool
{
    $referralId=(int)($referral['id']??0);$owner=(int)($referral['owner_user_id']??0);$contactId=(int)($referral['agent_contact_id']??0);
    if($referralId<1||$owner<1||$contactId<1||!preg_match('/^[a-f0-9]{64}$/',$sessionHash))return false;
    $eventName=vp3_analytics_event_name($eventName);$conversion=vp3_agent_referral_conversion_event($eventName);$dedupeType=$conversion?'conversion:'.$eventName:'referral';
    $stmt=$pdo->prepare('INSERT IGNORE INTO vp3_agent_referral_events (referral_id,owner_user_id,agent_contact_id,property_id,session_hash,event_type,value_amount,occurred_at) VALUES (?,?,?,?,?,?,?,NOW())');
    $stmt->execute([$referralId,$owner,$contactId,$propertyId?:null,$sessionHash,$dedupeType,$value]);
    if($stmt->rowCount()<1)return false;
    if($conversion){
        $pdo->prepare('UPDATE vp3_agent_referrals SET conversion_count=conversion_count+1,last_conversion_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$referralId]);
        $pdo->prepare('UPDATE vp3_agent_contacts SET conversion_count=conversion_count+1,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$contactId,$owner]);
    }else{
        $pdo->prepare('UPDATE vp3_agent_referrals SET click_count=click_count+1,first_clicked_at=COALESCE(first_clicked_at,NOW()),last_clicked_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$referralId]);
        $pdo->prepare('UPDATE vp3_agent_contacts SET referral_count=referral_count+1,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$contactId,$owner]);
    }
    $property=$propertyId>0?$propertyId:(int)($referral['source_property_id']??0);if($property<1)return true;
    $contactName=(trim((string)($referral['operator_name']??''))!==''?trim((string)$referral['operator_name']).' · ':'').trim((string)($referral['display_name']??'Automated agent'));
    $summary=$conversion?$contactName.' was attributed to a human conversion ('.$eventName.').':$contactName.' generated an attributed human visit.';
    $details=['referral_id'=>$referralId,'source_type'=>(string)($referral['source_type']??''),'event_name'=>$eventName,'attribution'=>'first_party_token'];if($value!==null)$details['value']=$value;
    $event=$pdo->prepare("INSERT INTO vp3_radar_events (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at) VALUES (?,?,NULL,?,?,'low','/','ATTRIBUTION',NULL,?,?,?,?,NOW())");
    $event->execute([$owner,$property,$contactId,$conversion?'agent_referral_conversion':'agent_human_referral',$conversion?95:85,(int)($referral['risk_score']??0),mb_strimwidth($summary,0,500,'…'),json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $radarEventId=(int)$pdo->lastInsertId();
    if($conversion&&$radarEventId>0){
        create_notification($owner,'radar_agent_conversion','AI-attributed conversion · '.trim((string)($referral['display_name']??'Agent')),$summary,url('/profile-agent.php?tab=radar'),'radar_event',$radarEventId);
        if(function_exists('agent_brain_v122_upsert_system_memory')){
            $contact=$pdo->prepare('SELECT referral_count,conversion_count,value_score,cost_score,risk_score FROM vp3_agent_contacts WHERE id=? AND owner_user_id=? LIMIT 1');$contact->execute([$contactId,$owner]);$counts=$contact->fetch()?:[];
            $ownerUser=function_exists('vp3_radar_owner_user')?vp3_radar_owner_user($pdo,$owner):null;
            if($ownerUser)agent_brain_v122_upsert_system_memory($ownerUser,'agent_radar','agent-radar-attribution:'.$contactId,$summary.' Current attributed totals: '.(int)($counts['referral_count']??0).' referrals and '.(int)($counts['conversion_count']??0).' conversions.',[
                'agent_contact_id'=>$contactId,'referral_id'=>$referralId,'event_name'=>$eventName,'referral_count'=>(int)($counts['referral_count']??0),'conversion_count'=>(int)($counts['conversion_count']??0),'source'=>'agent_radar_attribution',
            ],0.94);
        }
    }
    return true;
}

function vp3_agent_referral_capture_native(PDO $pdo,array $profile): void
{
    if(!vp3_agent_referral_schema_ready($pdo))return;$token=vp3_agent_referral_token((string)($_GET['vp3_ref']??''));if($token==='')return;
    $ua=function_exists('vp3_radar_request_user_agent')?vp3_radar_request_user_agent():mb_strimwidth(trim((string)($_SERVER['HTTP_USER_AGENT']??'')),0,1000,'');
    if(function_exists('vp3_radar_looks_automated')&&vp3_radar_looks_automated($ua))return;
    $owner=(int)($profile['user_id']??0);$referral=vp3_agent_referral_lookup($pdo,$owner,$token);if(!$referral)return;
    $property=function_exists('vp3_radar_native_property')?vp3_radar_native_property($pdo,$owner):null;$propertyId=(int)($property['id']??0);
    $browserSession=function_exists('profile_session_hash')?profile_session_hash($owner):session_id();$sessionHash=vp3_agent_referral_session_hash($owner,$browserSession);
    vp3_agent_referral_record($pdo,$referral,$propertyId,$sessionHash,'page_view',null);
    $_SESSION['vp3_agent_attribution'][$owner]=['referral_id'=>(int)$referral['id'],'expires'=>time()+VP3_AGENT_REFERRAL_SESSION_SECONDS];
}

function vp3_agent_referral_request_boot(): void
{
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET'||empty($_GET['vp3_ref'])||empty($_GET['username']))return;
    $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
    if(str_contains($path,'/api/')||str_contains($path,'/.well-known/'))return;
    try{
        $pdo=db();if(!$pdo||!vp3_agent_referral_schema_ready($pdo))return;
        $username=profile_username_normalize((string)$_GET['username']);if($username==='')return;
        $profile=profile_by_username($pdo,$username);if(!$profile||empty($profile['is_active'])||empty($profile['is_public']))return;
        vp3_agent_referral_capture_native($pdo,$profile);
    }catch(Throwable $e){error_log('VP3 Agent referral capture failed: '.$e->getMessage());}
}

function vp3_agent_referral_external_collect(PDO $pdo,array $property,array $payload,string $userAgent): void
{
    if(!vp3_agent_referral_schema_ready($pdo)||vp3_radar_looks_automated($userAgent))return;
    $token=vp3_agent_referral_token((string)($payload['agent_referral']??''));if($token==='')return;
    $owner=(int)($property['owner_user_id']??0);$referral=vp3_agent_referral_lookup($pdo,$owner,$token);if(!$referral)return;
    $session=vp3_analytics_session_token((string)($payload['session']??''));if($session==='')return;
    $event=vp3_analytics_event_name((string)($payload['event']??'page_view'));if($event==='')$event='page_view';
    $sessionHash=vp3_agent_referral_session_hash($owner,(int)$property['id'].'|'.$session);
    $valueRaw=$payload['value']??null;$value=is_numeric($valueRaw)?max(-1000000000,min(1000000000,(float)$valueRaw)):null;
    vp3_agent_referral_record($pdo,$referral,(int)$property['id'],$sessionHash,$event,$value);
}
