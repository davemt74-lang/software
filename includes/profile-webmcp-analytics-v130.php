<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_ANALYTICS_V130='profile-webmcp-analytics-v130-20260928';
const VP3_PROFILE_WEBMCP_EVENT_ENVELOPE_V130='vp3.webmcp.event.v1';
const VP3_PROFILE_WEBMCP_EVENTS_V130=[
    'webmcp_discovered','webmcp_manifest_loaded','webmcp_tool_registered',
    'webmcp_tool_called','webmcp_tool_completed','webmcp_tool_failed','webmcp_tool_cancelled','webmcp_tool_denied',
    'webmcp_confirmation_required','webmcp_authentication_required','webmcp_handoff_requested',
    'webmcp_booking_prepared','webmcp_booking_completed','webmcp_checkout_prepared','webmcp_checkout_started',
    'webmcp_purchase_completed','webmcp_campaign_joined','webmcp_offer_selected','webmcp_reward_applied',
    'webmcp_reward_claim_prepared','webmcp_reward_claimed','webmcp_message_sent',
];

function vp3_profile_webmcp_transport_id_v130(string $value): string
{
    $value=trim($value);
    return preg_match('/^[A-Za-z0-9_-]{8,96}$/',$value)?$value:'';
}

function vp3_profile_webmcp_tool_name_v130(string $value): string
{
    $value=strtolower(trim($value));
    return preg_match('/^vp3\.[a-z0-9_.-]{1,96}$/',$value)?$value:'';
}

function vp3_profile_webmcp_referral_token_v130(string $value): string
{
    $value=strtolower(trim($value));
    return preg_match('/^[a-f0-9]{48}$/',$value)?$value:'';
}

function vp3_profile_webmcp_telemetry_v130(array $input): array
{
    $row=is_array($input['telemetry']??null)?$input['telemetry']:[];
    return [
        'webmcp_session_id'=>vp3_profile_webmcp_transport_id_v130((string)($row['webmcp_session_id']??'')),
        'interaction_id'=>vp3_profile_webmcp_transport_id_v130((string)($row['interaction_id']??'')),
        'agent_referral'=>vp3_profile_webmcp_referral_token_v130((string)($row['agent_referral']??'')),
        'registered_tool_count'=>max(0,min(500,(int)($row['registered_tool_count']??0))),
        'expected_tool_count'=>max(0,min(500,(int)($row['expected_tool_count']??0))),
        'runtime_build'=>mb_strimwidth(preg_replace('/[^A-Za-z0-9_.:-]+/','_',trim((string)($row['runtime_build']??'')))??'',0,160,''),
        'release_version'=>mb_strimwidth(preg_replace('/[^A-Za-z0-9_.:-]+/','_',trim((string)($row['release_version']??'')))??'',0,160,''),
    ];
}

function vp3_profile_webmcp_event_envelope_v130(array $context,string $eventName,string $tool='',string $status='',int $durationMs=0,array $links=[]): array
{
    if(!in_array($eventName,VP3_PROFILE_WEBMCP_EVENTS_V130,true))throw new InvalidArgumentException('Unsupported WebMCP telemetry event.');
    $surface=in_array((string)($context['surface']??''),['native_profile','external_site'],true)?(string)$context['surface']:'';
    $tool=vp3_profile_webmcp_tool_name_v130($tool);
    $status=mb_strimwidth(preg_replace('/[^a-z0-9_.:-]+/i','_',strtolower(trim($status)))??'',0,40,'');
    $out=[
        'envelope_version'=>VP3_PROFILE_WEBMCP_EVENT_ENVELOPE_V130,
        'event_id'=>bin2hex(random_bytes(16)),
        'occurred_at_utc'=>gmdate('c'),
        'event_name'=>$eventName,
        'owner_user_id'=>(int)($context['owner_user_id']??0),
        'property_id'=>(int)($context['property_id']??0),
        'agent_contact_id'=>(int)($context['agent_contact_id']??0)?:null,
        'surface'=>$surface,
        'webmcp_session_id'=>vp3_profile_webmcp_transport_id_v130((string)($context['webmcp_session_id']??'')),
        'interaction_id'=>vp3_profile_webmcp_transport_id_v130((string)($context['interaction_id']??'')),
        'tool'=>$tool,
        'status'=>$status,
        'duration_ms'=>max(0,min(3600000,$durationMs)),
        'profile_username'=>mb_strimwidth(trim((string)($context['profile_username']??'')),0,64,''),
        'attribution_origin'=>mb_strimwidth(trim((string)($context['attribution_origin']??'webmcp_agent')),0,40,''),
        'registered_tool_count'=>max(0,min(500,(int)($context['registered_tool_count']??0))),
        'expected_tool_count'=>max(0,min(500,(int)($context['expected_tool_count']??0))),
        'runtime_build'=>mb_strimwidth((string)($context['runtime_build']??''),0,160,''),
        'release_version'=>mb_strimwidth((string)($context['release_version']??''),0,160,''),
    ];
    foreach([
        'visitor_user_id','profile_session_id','conversation_id','referral_id','referral_agent_contact_id',
        'booking_id','order_id','campaign_id','campaign_decision_id','reward_issuance_id'
    ] as $key){
        $value=(int)($links[$key]??$context[$key]??0);
        if($value>0)$out[$key]=$value;
    }
    $code=mb_strimwidth(preg_replace('/[^A-Z0-9_.:-]+/','_',strtoupper(trim((string)($links['result_code']??''))))??'',0,80,'');
    if($code!=='')$out['result_code']=$code;
    return $out;
}

function vp3_profile_webmcp_property_v130(PDO $pdo,array $profile,string $surface,?array $property=null): ?array
{
    if($surface==='external_site'){
        if(!$property||empty($property['is_active'])||(string)($property['property_type']??'')!=='external')return null;
        return $property;
    }
    if($surface!=='native_profile'||!function_exists('vp3_radar_native_property'))return null;
    return vp3_radar_native_property($pdo,(int)($profile['user_id']??0));
}

function vp3_profile_webmcp_session_v130(PDO $pdo,array $property,?array $contact,string $webmcpSessionId,string $surface): ?array
{
    $propertyId=(int)($property['id']??0);$owner=(int)($property['owner_user_id']??0);$contactId=(int)($contact['id']??0);
    if($propertyId<1||$owner<1||!vp3_radar_schema_ready($pdo))return null;
    if($webmcpSessionId==='')$webmcpSessionId=bin2hex(random_bytes(16));
    $sessionKey=hash('sha256','webmcp|'.$owner.'|'.$propertyId.'|'.$surface.'|'.$webmcpSessionId);
    $visitorType=$contactId>0?mb_strimwidth((string)($contact['visitor_class']??'webmcp_agent'),0,40,''):'webmcp_unclassified';
    $path=$surface==='native_profile'?'/profile':'/';
    $stmt=$pdo->prepare("INSERT INTO vp3_radar_sessions
      (property_id,owner_user_id,agent_contact_id,session_key,visitor_type,entry_path,exit_path,referrer_host,request_count,page_view_count,event_count,started_at,last_seen_at)
      VALUES (?,?,?,?,?,?,?,'',1,0,0,NOW(),NOW())
      ON DUPLICATE KEY UPDATE agent_contact_id=COALESCE(VALUES(agent_contact_id),agent_contact_id),request_count=request_count+1,last_seen_at=NOW(),id=LAST_INSERT_ID(id)");
    $stmt->execute([$propertyId,$owner,$contactId?:null,$sessionKey,$visitorType,$path,$path]);
    $id=(int)$pdo->lastInsertId();
    if($id<1){$find=$pdo->prepare('SELECT id FROM vp3_radar_sessions WHERE property_id=? AND session_key=? LIMIT 1');$find->execute([$propertyId,$sessionKey]);$id=(int)$find->fetchColumn();}
    if($id<1)return null;
    $get=$pdo->prepare('SELECT * FROM vp3_radar_sessions WHERE id=? AND owner_user_id=? LIMIT 1');$get->execute([$id,$owner]);
    return $get->fetch()?:null;
}

function vp3_profile_webmcp_referral_lineage_v130(PDO $pdo,array $referral,array $property,array $session): void
{
    try{
        if(!function_exists('vp3_agent_referral_schema_ready')||!vp3_agent_referral_schema_ready($pdo))return;
        $referralId=(int)($referral['id']??0);$owner=(int)($referral['owner_user_id']??0);$contact=(int)($referral['agent_contact_id']??0);
        $propertyId=(int)($property['id']??0);$sessionKey=(string)($session['session_key']??'');
        if($referralId<1||$owner<1||$contact<1||$propertyId<1||!preg_match('/^[a-f0-9]{64}$/',$sessionKey))return;
        $sessionHash=function_exists('vp3_agent_referral_session_hash')
            ? vp3_agent_referral_session_hash($owner,'webmcp|'.$propertyId.'|'.$sessionKey)
            : hash('sha256','vp3-agent-referral|'.$owner.'|webmcp|'.$propertyId.'|'.$sessionKey);
        $stmt=$pdo->prepare("INSERT IGNORE INTO vp3_agent_referral_events
          (referral_id,owner_user_id,agent_contact_id,property_id,session_hash,event_type,value_amount,occurred_at)
          VALUES (?,?,?,?,?,'webmcp',NULL,NOW())");
        $stmt->execute([$referralId,$owner,$contact,$propertyId,$sessionHash]);
    }catch(Throwable $e){
        error_log('VP3 WebMCP referral lineage failed: '.$e->getMessage());
    }
}

function vp3_profile_webmcp_context_v130(PDO $pdo,array $profile,string $surface,array $telemetry,?array $property=null,?array $viewer=null): ?array
{
    try{
        if(!vp3_radar_schema_ready($pdo))return null;
        $owner=(int)($profile['user_id']??0);if($owner<1)return null;
        $property=vp3_profile_webmcp_property_v130($pdo,$profile,$surface,$property);if(!$property)return null;
        $ua=trim((string)($_SERVER['HTTP_USER_AGENT']??''));
        $identity=$ua!==''&&function_exists('vp3_radar_native_identity')?vp3_radar_native_identity($pdo,$owner,$ua):null;
        $contact=is_array($identity['contact']??null)?$identity['contact']:null;
        $referral=null;$refToken=vp3_profile_webmcp_referral_token_v130((string)($telemetry['agent_referral']??''));
        if($refToken!==''&&function_exists('vp3_agent_referral_lookup'))$referral=vp3_agent_referral_lookup($pdo,$owner,$refToken);
        $session=vp3_profile_webmcp_session_v130($pdo,$property,$contact,(string)($telemetry['webmcp_session_id']??''),$surface);
        if(!$session)return null;
        if($referral)vp3_profile_webmcp_referral_lineage_v130($pdo,$referral,$property,$session);
        $referralId=(int)($referral['id']??0);$referralContact=(int)($referral['agent_contact_id']??0);
        return [
            'owner_user_id'=>$owner,
            'property_id'=>(int)$property['id'],
            'session_id'=>(int)$session['id'],
            'agent_contact_id'=>(int)($contact['id']??0),
            'surface'=>$surface,
            'profile_username'=>(string)($profile['username']??''),
            'webmcp_session_id'=>(string)($telemetry['webmcp_session_id']??''),
            'interaction_id'=>(string)($telemetry['interaction_id']??''),
            'visitor_user_id'=>(int)($viewer['id']??0),
            'referral_id'=>$referralId,
            'referral_agent_contact_id'=>$referralContact,
            'attribution_origin'=>$referralId>0?'agent_referral':((int)($contact['id']??0)>0?'webmcp_agent':'webmcp_unclassified'),
            'risk_score'=>(int)($contact['risk_score']??0),
            'registered_tool_count'=>(int)($telemetry['registered_tool_count']??0),
            'expected_tool_count'=>(int)($telemetry['expected_tool_count']??0),
            'runtime_build'=>(string)($telemetry['runtime_build']??''),
            'release_version'=>(string)($telemetry['release_version']??''),
        ];
    }catch(Throwable $e){
        error_log('VP3 WebMCP telemetry context failed: '.$e->getMessage());
        return null;
    }
}

function vp3_profile_webmcp_record_v130(PDO $pdo,?array $context,string $eventName,string $tool='',string $status='',int $durationMs=0,array $links=[]): ?int
{
    if(!$context)return null;
    try{
        $owner=(int)($context['owner_user_id']??0);$property=(int)($context['property_id']??0);$session=(int)($context['session_id']??0);$contact=(int)($context['agent_contact_id']??0);
        if($owner<1||$property<1||$session<1)return null;
        $envelope=vp3_profile_webmcp_event_envelope_v130($context,$eventName,$tool,$status,$durationMs,$links);
        $summary=trim((string)($envelope['tool']??''));if($summary==='')$summary=$eventName;
        $summary='WebMCP · '.$summary.' · '.str_replace('webmcp_','',$eventName);
        $risk=max(0,min(100,(int)($context['risk_score']??0)));
        $severity=$risk>=70?'high':($risk>=40?'medium':'low');
        $significance=in_array($eventName,['webmcp_tool_failed','webmcp_tool_denied'],true)?75:55;
        $path=(string)($context['surface']??'')==='external_site'?'/':'/profile';
        $stmt=$pdo->prepare("INSERT INTO vp3_radar_events
          (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at)
          VALUES (?,?,?,?,?,?,?,?,NULL,?,?,?,?,NOW())");
        $stmt->execute([$owner,$property,$session,$contact?:null,$eventName,$severity,$path,'WEBMCP',$significance,$risk,mb_strimwidth($summary,0,500,'…'),json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE vp3_radar_sessions SET event_count=event_count+1,last_seen_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$session,$owner]);
        if($contact>0&&$eventName==='webmcp_tool_called'){
            $pdo->prepare('UPDATE vp3_agent_contacts SET request_count=request_count+1,engagement_score=LEAST(100,engagement_score+2),last_seen_at=NOW() WHERE id=? AND owner_user_id=?')->execute([$contact,$owner]);
        }
        return $id>0?$id:null;
    }catch(Throwable $e){
        error_log('VP3 WebMCP telemetry event failed: '.$e->getMessage());
        return null;
    }
}

function vp3_profile_webmcp_owner_activity_v130(PDO $pdo,int $ownerUserId,int $propertyId=0,int $limit=80): array
{
    if($ownerUserId<1||!vp3_radar_schema_ready($pdo))return [];
    $limit=max(1,min(200,$limit));$propertySql=$propertyId>0?' AND e.property_id=?':'';
    $params=$propertyId>0?[$ownerUserId,$propertyId]:[$ownerUserId];
    $stmt=$pdo->prepare("SELECT e.id,e.event_type,e.property_id,e.agent_contact_id,e.risk_score,e.occurred_at,e.details_json,p.label property_label,p.domain,c.display_name,c.operator_name
      FROM vp3_radar_events e INNER JOIN vp3_radar_properties p ON p.id=e.property_id LEFT JOIN vp3_agent_contacts c ON c.id=e.agent_contact_id
      WHERE e.owner_user_id=? AND e.event_type LIKE 'webmcp\\_%'{$propertySql}
      ORDER BY e.occurred_at DESC,e.id DESC LIMIT {$limit}");
    $stmt->execute($params);$rows=$stmt->fetchAll()?:[];$out=[];
    foreach($rows as $row){
        $details=json_decode((string)($row['details_json']??''),true);if(!is_array($details))$details=[];
        $out[]=[
            'id'=>(int)$row['id'],'event_name'=>(string)$row['event_type'],'property_id'=>(int)$row['property_id'],
            'property_label'=>(string)$row['property_label'],'property_domain'=>(string)$row['domain'],
            'agent_contact_id'=>$row['agent_contact_id']!==null?(int)$row['agent_contact_id']:null,
            'display_name'=>(string)($row['display_name']??''),'operator_name'=>(string)($row['operator_name']??''),
            'surface'=>(string)($details['surface']??''),'tool'=>(string)($details['tool']??''),'status'=>(string)($details['status']??''),
            'interaction_id'=>(string)($details['interaction_id']??''),'attribution_origin'=>(string)($details['attribution_origin']??''),
            'referral_id'=>(int)($details['referral_id']??0)?:null,'duration_ms'=>(int)($details['duration_ms']??0),
            'risk_score'=>(int)$row['risk_score'],'occurred_at'=>(string)$row['occurred_at'],
        ];
    }
    return $out;
}
