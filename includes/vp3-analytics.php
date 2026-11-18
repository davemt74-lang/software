<?php
declare(strict_types=1);

const VP3_ANALYTICS_SESSION_SECONDS = 1800;
const VP3_ANALYTICS_SESSION_EVENT_CAP = 500;
const VP3_ANALYTICS_CONVERSION_EVENTS = ['signup','contact_form','purchase','booking','lead','conversion'];

function vp3_analytics_session_token(string $value): string
{
    $value=trim($value);
    return preg_match('/^[A-Za-z0-9_-]{20,96}$/',$value)?$value:'';
}

function vp3_analytics_event_name(string $value): string
{
    $value=mb_strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9_.:-]+/','_',$value)??'';
    return mb_substr(trim($value,'_'),0,64);
}

function vp3_analytics_browser_family(string $ua): string
{
    $q=mb_strtolower($ua);
    if(str_contains($q,'edg/'))return 'Edge';
    if(str_contains($q,'firefox/'))return 'Firefox';
    if(str_contains($q,'chrome/')||str_contains($q,'crios/'))return 'Chrome';
    if(str_contains($q,'safari/')&&!str_contains($q,'chrome/'))return 'Safari';
    return 'Other';
}

function vp3_analytics_device_type(string $ua): string
{
    $q=mb_strtolower($ua);
    if(preg_match('/ipad|tablet|kindle|silk/',$q))return 'Tablet';
    if(preg_match('/mobile|iphone|ipod|android/',$q))return 'Mobile';
    return 'Desktop';
}

function vp3_analytics_session_key(int $propertyId,string $clientSession): string
{
    $bucket=(int)floor(time()/VP3_ANALYTICS_SESSION_SECONDS);
    return hash('sha256',$propertyId.'|'.$clientSession.'|'.$bucket);
}

function vp3_analytics_external_collect(PDO $pdo,array $property,array $payload,string $userAgent): bool
{
    if(vp3_radar_looks_automated($userAgent))return false;
    $propertyId=(int)($property['id']??0);$owner=(int)($property['owner_user_id']??0);
    if($propertyId<1||$owner<1)return false;
    $clientSession=vp3_analytics_session_token((string)($payload['session']??''));
    if($clientSession==='')return false;
    $eventName=vp3_analytics_event_name((string)($payload['event']??'page_view'));
    if($eventName==='')$eventName='page_view';
    $path=vp3_radar_external_path((string)($payload['path']??'/'));
    $referrer=vp3_radar_external_referrer_host((string)($payload['referrer_host']??''),(string)$property['domain']);
    $sessionKey=vp3_analytics_session_key($propertyId,$clientSession);

    $countStmt=$pdo->prepare('SELECT event_count FROM vp3_radar_sessions WHERE property_id=? AND session_key=? LIMIT 1');
    $countStmt->execute([$propertyId,$sessionKey]);
    $current=$countStmt->fetchColumn();
    if($current!==false&&(int)$current>=VP3_ANALYTICS_SESSION_EVENT_CAP)return false;

    $pageInc=$eventName==='page_view'?1:0;
    $stmt=$pdo->prepare("INSERT INTO vp3_radar_sessions
      (property_id,owner_user_id,agent_contact_id,session_key,visitor_type,entry_path,exit_path,referrer_host,request_count,page_view_count,event_count,started_at,last_seen_at)
      VALUES (?,?,NULL,?,'human',?,?,?,?,?,1,NOW(),NOW())
      ON DUPLICATE KEY UPDATE exit_path=VALUES(exit_path),referrer_host=CASE WHEN referrer_host='' THEN VALUES(referrer_host) ELSE referrer_host END,
        request_count=request_count+1,page_view_count=page_view_count+VALUES(page_view_count),event_count=event_count+1,last_seen_at=NOW(),id=LAST_INSERT_ID(id)");
    $stmt->execute([$propertyId,$owner,$sessionKey,$path,$path,$referrer,1,$pageInc]);
    $sessionId=(int)$pdo->lastInsertId();
    if($sessionId<1){$find=$pdo->prepare('SELECT id FROM vp3_radar_sessions WHERE property_id=? AND session_key=? LIMIT 1');$find->execute([$propertyId,$sessionKey]);$sessionId=(int)$find->fetchColumn();}
    if($sessionId<1)return false;

    $valueRaw=$payload['value']??null;$value=is_numeric($valueRaw)?max(-1000000000,min(1000000000,(float)$valueRaw)):null;
    $label=mb_strimwidth(trim((string)($payload['label']??'')),0,120,'');
    $details=[
        'collector'=>'browser_analytics',
        'event_name'=>$eventName,
        'browser_family'=>vp3_analytics_browser_family($userAgent),
        'device_type'=>vp3_analytics_device_type($userAgent),
        'referrer_host'=>$referrer,
    ];
    if($value!==null)$details['value']=$value;
    if($label!=='')$details['label']=$label;
    $eventType=$eventName==='page_view'?'analytics_page_view':'analytics_event';
    $summary=$eventName==='page_view'?'Anonymous visitor viewed '.$property['domain'].$path.'.':'Anonymous visitor event '.$eventName.' on '.$property['domain'].$path.'.';
    $event=$pdo->prepare("INSERT INTO vp3_radar_events
      (owner_user_id,property_id,session_id,agent_contact_id,event_type,severity,path,method,status_code,significance_score,risk_score,summary,details_json,occurred_at)
      VALUES (?,?,?,NULL,?,'low',?,'GET',NULL,?,?,?, ?,NOW())");
    $event->execute([$owner,$propertyId,$sessionId,$eventType,$path,$eventName==='page_view'?20:45,0,mb_strimwidth($summary,0,500,'…'),json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $pdo->prepare('UPDATE vp3_radar_properties SET verified_at=COALESCE(verified_at,NOW()),updated_at=NOW() WHERE id=?')->execute([$propertyId]);
    return true;
}

function vp3_analytics_property_rows(PDO $pdo,int $ownerUserId): array
{
    $stmt=$pdo->prepare("SELECT id,property_type,label,domain,is_active,verified_at,created_at,updated_at FROM vp3_radar_properties WHERE owner_user_id=? ORDER BY property_type='native' DESC,is_active DESC,label,id");
    $stmt->execute([$ownerUserId]);
    return $stmt->fetchAll()?:[];
}

function vp3_analytics_native_property_id(PDO $pdo,int $ownerUserId): int
{
    $stmt=$pdo->prepare("SELECT id FROM vp3_radar_properties WHERE owner_user_id=? AND property_type='native' ORDER BY id LIMIT 1");
    $stmt->execute([$ownerUserId]);
    return (int)($stmt->fetchColumn()?:0);
}

function vp3_analytics_property_allowed(array $properties,int $propertyId): bool
{
    if($propertyId===0)return true;
    foreach($properties as $property)if((int)$property['id']===$propertyId)return true;
    return false;
}

function vp3_analytics_scope_sql(int $propertyId,string $alias='s'): array
{
    return $propertyId>0?[" AND {$alias}.property_id=?",[$propertyId]]:['',[]];
}

/** One database clock fixes the bounds of every aggregate in a response. */
function vp3_analytics_reporting_window_v1(PDO $pdo,int $days): array
{
    $days=max(1,min(90,$days));
    $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if($driver==='mysql'){
        $row=$pdo->query("SELECT NOW() AS as_of,DATE_SUB(NOW(),INTERVAL $days DAY) AS from_at,UTC_TIMESTAMP() AS utc_now,@@session.time_zone AS zone,@@system_time_zone AS system_zone,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW()) AS utc_offset")->fetch(PDO::FETCH_ASSOC);
    }elseif($driver==='sqlite'){
        $row=$pdo->query("SELECT datetime('now') AS as_of,datetime('now','-$days days') AS from_at,datetime('now') AS utc_now,'UTC' AS zone,'UTC' AS system_zone,0 AS utc_offset")->fetch(PDO::FETCH_ASSOC);
    }else throw new RuntimeException('Analytics reporting clock is unsupported.');
    if(!is_array($row))throw new RuntimeException('Analytics reporting clock is unavailable.');
    $utc=new DateTimeZone('UTC');
    foreach(['from_at','as_of','utc_now'] as $key){
        $value=$row[$key]??null;
        $date=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,$utc):false;
        if(!$date||$date->format('Y-m-d H:i:s')!==$value)throw new RuntimeException('Analytics reporting clock is invalid.');
    }
    $offset=$row['utc_offset']??null;
    if(!(is_int($offset)||(is_string($offset)&&preg_match('/^-?(?:0|[1-9][0-9]{0,5})$/D',$offset)))||abs((int)$offset)>86400)throw new RuntimeException('Analytics reporting offset is invalid.');
    $offset=(int)$offset;
    if((new DateTimeImmutable($row['as_of'],$utc))->getTimestamp()-(new DateTimeImmutable($row['utc_now'],$utc))->getTimestamp()!==$offset||strcmp($row['from_at'],$row['as_of'])>0)throw new RuntimeException('Analytics reporting clock disagrees.');
    foreach(['zone','system_zone'] as $key)if(!is_string($row[$key]??null)||!preg_match('/^[A-Za-z0-9_+.:\/-]{1,80}$/D',$row[$key]))throw new RuntimeException('Analytics reporting timezone is unavailable.');
    $zone=$row['zone']==='SYSTEM'?'SYSTEM (server '.$row['system_zone'].')':$row['zone'];
    $zone.='; UTC'.($offset<0?'-':'+').sprintf('%02d:%02d',intdiv(abs($offset),3600),intdiv(abs($offset)%3600,60)).' at read';
    return ['from'=>$row['from_at'],'to'=>$row['as_of'],'zone'=>$zone,'days'=>$days];
}

function vp3_analytics_query_rows_v1(PDO $pdo,string $sql,array $params): array
{
    $query=$pdo->prepare($sql);$query->execute($params);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

/** The JSON expression is safe even when a historical row contains malformed JSON. */
function vp3_analytics_json_sql_v1(PDO $pdo,string $field,bool $type=false): string
{
    if(!in_array($field,['collector','event_name','device_type','browser_family'],true))throw new InvalidArgumentException('Invalid Analytics field.');
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $value="JSON_EXTRACT(e.details_json,'$.".$field."')";
    $value=$type?($mysql?'JSON_TYPE('.$value.')':"JSON_TYPE(e.details_json,'$.".$field."')"):($mysql?'JSON_UNQUOTE('.$value.')':$value);
    return 'CASE WHEN JSON_VALID(e.details_json) THEN '.$value.' ELSE NULL END';
}

/** Native visits are timestamped events; the browser contact's view_count is lifetime data. */
function vp3_analytics_native_visits_v1(PDO $pdo,int $owner,array $window): array
{
    if(!table_exists('profile_events')||!table_exists('profile_visit_sessions'))throw new RuntimeException('Native Profile visit history is unavailable.');
    $rows=vp3_analytics_query_rows_v1($pdo,"SELECT COUNT(*) AS visits,COUNT(DISTINCT e.profile_session_id) AS browser_contacts
        FROM profile_events e LEFT JOIN profile_visit_sessions s ON s.id=e.profile_session_id
        WHERE e.owner_user_id=? AND (e.profile_session_id IS NULL OR s.owner_user_id=e.owner_user_id)
          AND e.event_type='profile_view' AND e.created_at>=? AND e.created_at<=?",[$owner,$window['from'],$window['to']]);
    return ['visits'=>(int)$rows[0]['visits'],'browser_contacts'=>(int)$rows[0]['browser_contacts']];
}

function vp3_analytics_dashboard_state(PDO $pdo,array $user,int $propertyId=0,int $days=30): array
{
    $uid=(int)($user['id']??0);$days=max(1,min(90,$days));
    $empty=['ready'=>false,'coverage'=>'unavailable','days'=>$days,'property_id'=>$propertyId,'properties'=>[],'stats'=>[],'timeline'=>[],'top_pages'=>[],'referrers'=>[],'devices'=>[],'browsers'=>[],'events'=>[],'property_stats'=>[]];
    if($uid<1||!vp3_radar_schema_ready($pdo))return $empty;
    $properties=vp3_analytics_property_rows($pdo,$uid);
    if(!vp3_analytics_property_allowed($properties,$propertyId))return array_replace($empty,['properties'=>$properties,'error'=>'Choose an available owned Analytics property.']);
    $window=vp3_analytics_reporting_window_v1($pdo,$days);
    $params=[$uid,$window['from'],$window['to']];
    [$eventScope,$eventParams]=vp3_analytics_scope_sql($propertyId,'e');
    [$sessionScope,$sessionParams]=vp3_analytics_scope_sql($propertyId,'s');
    $scopedParams=[...$params,...$eventParams];
    $sessionFrom=' FROM vp3_radar_sessions s JOIN vp3_radar_properties p ON p.id=s.property_id AND p.owner_user_id=s.owner_user_id WHERE p.owner_user_id=? AND s.last_seen_at>=? AND s.last_seen_at<=?';
    $eventFrom=' FROM vp3_radar_events e JOIN vp3_radar_properties p ON p.id=e.property_id AND p.owner_user_id=e.owner_user_id WHERE p.owner_user_id=? AND e.occurred_at>=? AND e.occurred_at<=?';
    $humanSession="s.agent_contact_id IS NULL AND s.visitor_type='human'";
    $agentSession="s.agent_contact_id IS NOT NULL OR SUBSTR(s.visitor_type,1,7)='webmcp_'";
    $humanView="e.event_type='analytics_page_view' AND e.agent_contact_id IS NULL";
    $agentView="e.event_type IN ('agent_profile_view','external_agent_page_view') AND e.agent_contact_id IS NOT NULL";
    $pageView="(($humanView) OR ($agentView))";
    $browserEvent="e.agent_contact_id IS NULL AND e.event_type IN ('analytics_page_view','analytics_event')";
    $name=vp3_analytics_json_sql_v1($pdo,'event_name');
    $collector=vp3_analytics_json_sql_v1($pdo,'collector');
    $jsonString=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'STRING':'text';
    $validJson='('.vp3_analytics_json_sql_v1($pdo,'collector',true)."='$jsonString' AND $collector='browser_analytics' AND "
        .vp3_analytics_json_sql_v1($pdo,'event_name',true)."='$jsonString' AND LENGTH($name) BETWEEN 1 AND 64 AND "
        .vp3_analytics_json_sql_v1($pdo,'device_type',true)."='$jsonString' AND ".vp3_analytics_json_sql_v1($pdo,'browser_family',true)."='$jsonString')";
    $isConversion="e.event_type='analytics_event' AND e.agent_contact_id IS NULL AND ($name IN ('signup','contact_form','purchase','booking','lead','conversion') OR SUBSTR($name,1,11)='conversion.')";

    // Complete aggregates never share the recent-feed row cap. Property cards use the same event definitions.
    $sessionRows=vp3_analytics_query_rows_v1($pdo,"SELECT s.property_id,COUNT(*) AS sessions,
        COALESCE(SUM(CASE WHEN $humanSession THEN 1 ELSE 0 END),0) AS human_sessions,
        COALESCE(SUM(CASE WHEN $agentSession THEN 1 ELSE 0 END),0) AS agent_sessions".$sessionFrom.' GROUP BY s.property_id',$params);
    $eventRows=vp3_analytics_query_rows_v1($pdo,"SELECT e.property_id,
        COALESCE(SUM(CASE WHEN $humanView THEN 1 ELSE 0 END),0) AS human_page_views,
        COALESCE(SUM(CASE WHEN $agentView THEN 1 ELSE 0 END),0) AS agent_page_views,
        COALESCE(SUM(CASE WHEN $isConversion THEN 1 ELSE 0 END),0) AS conversions,
        COALESCE(SUM(CASE WHEN e.event_type='analytics_event' AND e.agent_contact_id IS NULL THEN 1 ELSE 0 END),0) AS custom_events,
        COALESCE(SUM(CASE WHEN e.risk_score>=70 THEN 1 ELSE 0 END),0) AS high_risk_events,
        COALESCE(SUM(CASE WHEN e.event_type='webmcp_tool_called' THEN 1 ELSE 0 END),0) AS webmcp_tool_calls,
        COALESCE(SUM(CASE WHEN e.event_type='webmcp_tool_completed' THEN 1 ELSE 0 END),0) AS webmcp_completed,
        COALESCE(SUM(CASE WHEN e.event_type='webmcp_tool_failed' THEN 1 ELSE 0 END),0) AS webmcp_failed,
        COALESCE(SUM(CASE WHEN e.event_type='webmcp_tool_denied' THEN 1 ELSE 0 END),0) AS webmcp_denied,
        COALESCE(SUM(CASE WHEN ($browserEvent) AND NOT COALESCE($validJson,0) THEN 1 ELSE 0 END),0) AS invalid_browser_events"
        .$eventFrom.' GROUP BY e.property_id',$params);
    $stats=array_fill_keys(['sessions','human_sessions','agent_sessions','human_page_views','agent_page_views','conversions','custom_events','high_risk_events','webmcp_tool_calls','webmcp_completed','webmcp_failed','webmcp_denied'],0);
    $propertyCounts=[];
    foreach([...$sessionRows,...$eventRows] as $row){
        $id=(int)$row['property_id'];$propertyCounts[$id]??=[];
        if(($propertyId===0||$propertyId===$id)&&(int)($row['invalid_browser_events']??0)>0)throw new RuntimeException('Analytics event metadata is incomplete.');
        foreach($row as $key=>$value){
            if(!array_key_exists($key,$stats))continue;
            $propertyCounts[$id][$key]=(int)$value;
            if($propertyId===0||$propertyId===$id)$stats[$key]+=(int)$value;
        }
    }
    $stats['unclassified_sessions']=$stats['sessions']-$stats['human_sessions']-$stats['agent_sessions'];
    $stats['page_views']=$stats['human_page_views']+$stats['agent_page_views'];
    $native=array_values(array_filter($properties,static fn(array $p):bool=>$p['property_type']==='native'));
    if(count($native)>1)throw new RuntimeException('Native Analytics property is ambiguous.');
    $nativeId=(int)($native[0]['id']??0);$includeNative=$nativeId>0&&($propertyId===0||$propertyId===$nativeId);
    $nativeStats=null;
    if($nativeId>0&&($includeNative||(table_exists('profile_events')&&table_exists('profile_visit_sessions'))))$nativeStats=vp3_analytics_native_visits_v1($pdo,$uid,$window);
    $stats['native_visit_events']=$includeNative?$nativeStats['visits']:0;
    $stats['native_browser_contacts']=$includeNative?$nativeStats['browser_contacts']:0;

    $distributions=[];
    foreach(['devices'=>['device_type',['Desktop','Mobile','Tablet','Other']],'browsers'=>['browser_family',['Chrome','Edge','Firefox','Safari','Other']]] as $key=>[$field,$labels]){
        $value=vp3_analytics_json_sql_v1($pdo,$field);$allowed="'".implode("','",$labels)."'";
        $group="CASE WHEN $value IN ($allowed) THEN $value ELSE 'Other' END";
        $rows=vp3_analytics_query_rows_v1($pdo,"SELECT $group AS label,COUNT(*) AS count".$eventFrom." AND ($browserEvent)".$eventScope.' GROUP BY '.$group.' ORDER BY count DESC,label',$scopedParams);
        $distributions[$key]=array_map(static fn(array $r):array=>['label'=>$r['label'],'count'=>(int)$r['count']],$rows);
    }

    $feedSelect='SELECT e.id,e.event_type,e.path,e.occurred_at,e.details_json,e.agent_contact_id,e.risk_score,e.severity,p.label AS property_label,p.property_type,c.display_name,c.operator_name,c.visitor_class';
    $feedFrom=' FROM vp3_radar_events e JOIN vp3_radar_properties p ON p.id=e.property_id AND p.owner_user_id=e.owner_user_id LEFT JOIN vp3_agent_contacts c ON c.id=e.agent_contact_id AND c.owner_user_id=e.owner_user_id WHERE p.owner_user_id=? AND e.occurred_at>=? AND e.occurred_at<=?';
    $feed=vp3_analytics_query_rows_v1($pdo,$feedSelect.$feedFrom.$eventScope.' ORDER BY e.occurred_at DESC,e.id DESC LIMIT 80',$scopedParams);
    $webmcpFeed=vp3_analytics_query_rows_v1($pdo,$feedSelect.$feedFrom." AND SUBSTR(e.event_type,1,7)='webmcp_'".$eventScope.' ORDER BY e.occurred_at DESC,e.id DESC LIMIT 80',$scopedParams);
    $project=static function(array $row):array{
        $details=json_decode((string)($row['details_json']??''),true);if(!is_array($details))$details=[];
        return ['id'=>(int)$row['id'],'event_type'=>$row['event_type'],'path'=>$row['path'],'occurred_at'=>$row['occurred_at'],
            'property_label'=>$row['property_label'],'property_type'=>$row['property_type'],
            'agent_contact_id'=>$row['agent_contact_id']!==null?(int)$row['agent_contact_id']:null,
            'display_name'=>(string)($row['display_name']??''),'operator_name'=>(string)($row['operator_name']??''),'visitor_class'=>(string)($row['visitor_class']??''),
            'risk_score'=>(int)$row['risk_score'],'severity'=>$row['severity'],'event_name'=>(string)($details['event_name']??''),'value'=>$details['value']??null,
            'surface'=>(string)($details['surface']??''),'tool'=>(string)($details['tool']??''),'webmcp_status'=>(string)($details['status']??''),
            'interaction_id'=>(string)($details['interaction_id']??''),'attribution_origin'=>(string)($details['attribution_origin']??''),
            'referral_id'=>(int)($details['referral_id']??0)?:null,'duration_ms'=>(int)($details['duration_ms']??0)];
    };
    $recent=array_map($project,$feed);$webmcpActivity=[];
    foreach($webmcpFeed as $row){$event=$project($row);$event['event_name']=$event['event_type'];$event['status']=$event['webmcp_status'];$webmcpActivity[]=$event;}

    $timelineMap=[];
    $dayRow=static fn(string $day):array=>['day'=>$day,'sessions'=>0,'page_views'=>0,'agent_sessions'=>0,'native_visit_events'=>0,'native_browser_contacts'=>0];
    $rows=vp3_analytics_query_rows_v1($pdo,"SELECT DATE(s.last_seen_at) AS day,COUNT(*) AS sessions,COALESCE(SUM(CASE WHEN $agentSession THEN 1 ELSE 0 END),0) AS agent_sessions".$sessionFrom.$sessionScope.' GROUP BY DATE(s.last_seen_at) ORDER BY day',[...$params,...$sessionParams]);
    foreach($rows as $row){$day=$row['day'];$timelineMap[$day]=$dayRow($day);$timelineMap[$day]['sessions']=(int)$row['sessions'];$timelineMap[$day]['agent_sessions']=(int)$row['agent_sessions'];}
    $rows=vp3_analytics_query_rows_v1($pdo,'SELECT DATE(e.occurred_at) AS day,COUNT(*) AS page_views'.$eventFrom." AND $pageView".$eventScope.' GROUP BY DATE(e.occurred_at) ORDER BY day',$scopedParams);
    foreach($rows as $row){$day=$row['day'];$timelineMap[$day]??=$dayRow($day);$timelineMap[$day]['page_views']=(int)$row['page_views'];}
    if($includeNative){
        $rows=vp3_analytics_query_rows_v1($pdo,"SELECT DATE(e.created_at) AS day,COUNT(*) AS visits,COUNT(DISTINCT e.profile_session_id) AS browser_contacts
            FROM profile_events e LEFT JOIN profile_visit_sessions s ON s.id=e.profile_session_id
            WHERE e.owner_user_id=? AND (e.profile_session_id IS NULL OR s.owner_user_id=e.owner_user_id)
              AND e.event_type='profile_view' AND e.created_at>=? AND e.created_at<=? GROUP BY DATE(e.created_at) ORDER BY day",$params);
        foreach($rows as $row){$day=$row['day'];$timelineMap[$day]??=$dayRow($day);$timelineMap[$day]['native_visit_events']=(int)$row['visits'];$timelineMap[$day]['native_browser_contacts']=(int)$row['browser_contacts'];}
    }
    ksort($timelineMap);
    $topPages=vp3_analytics_query_rows_v1($pdo,"SELECT e.path,COUNT(*) AS events,COALESCE(SUM(CASE WHEN $humanView THEN 1 ELSE 0 END),0) AS human_events,COALESCE(SUM(CASE WHEN $agentView THEN 1 ELSE 0 END),0) AS agent_events".$eventFrom." AND $pageView".$eventScope.' GROUP BY e.path ORDER BY events DESC,e.path LIMIT 20',$scopedParams);
    $referrers=vp3_analytics_query_rows_v1($pdo,'SELECT s.referrer_host,COUNT(*) AS sessions'.$sessionFrom." AND s.referrer_host<>''".$sessionScope.' GROUP BY s.referrer_host ORDER BY sessions DESC,s.referrer_host LIMIT 20',[...$params,...$sessionParams]);
    $propertyRows=[];
    foreach($properties as $property){
        $counts=$propertyCounts[(int)$property['id']]??[];
        $row=array_intersect_key($property,array_flip(['id','label','property_type','domain']));
        foreach(['sessions','human_sessions','agent_sessions','human_page_views','agent_page_views'] as $key)$row[$key]=(int)($counts[$key]??0);
        $row['page_views']=$row['human_page_views']+$row['agent_page_views'];
        $row['native_visit_events']=$property['property_type']==='native'?($nativeStats['visits']??null):null;
        $row['native_browser_contacts']=$property['property_type']==='native'?($nativeStats['browser_contacts']??null):null;
        $propertyRows[]=$row;
    }
    usort($propertyRows,static fn(array $a,array $b):int=>$b['page_views']<=>$a['page_views']?:$b['sessions']<=>$a['sessions']?:(int)$a['id']<=>(int)$b['id']);
    return ['ready'=>true,'coverage'=>'complete','days'=>$days,'property_id'=>$propertyId,'properties'=>$properties,
        'reporting_window'=>$window,'native_included'=>$includeNative,'native_page_views_available'=>false,
        'stats'=>$stats,'timeline'=>array_values($timelineMap),'top_pages'=>$topPages,'referrers'=>$referrers,
        'devices'=>$distributions['devices'],'browsers'=>$distributions['browsers'],
        'events'=>$recent,'webmcp_activity'=>$webmcpActivity,'property_stats'=>$propertyRows];
}
