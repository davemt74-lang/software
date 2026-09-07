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

function vp3_analytics_dashboard_state(PDO $pdo,array $user,int $propertyId=0,int $days=30): array
{
    $uid=(int)($user['id']??0);$days=max(1,min(90,$days));
    $empty=['ready'=>false,'days'=>$days,'property_id'=>0,'properties'=>[],'stats'=>[],'timeline'=>[],'top_pages'=>[],'referrers'=>[],'devices'=>[],'browsers'=>[],'events'=>[],'property_stats'=>[]];
    if($uid<1||!vp3_radar_schema_ready($pdo))return $empty;
    $properties=vp3_analytics_property_rows($pdo,$uid);
    if(!vp3_analytics_property_allowed($properties,$propertyId))$propertyId=0;
    [$sessionScope,$sessionParams]=vp3_analytics_scope_sql($propertyId,'s');
    [$eventScope,$eventParams]=vp3_analytics_scope_sql($propertyId,'e');
    $since="DATE_SUB(NOW(),INTERVAL {$days} DAY)";

    $human=$pdo->prepare("SELECT COUNT(*) sessions,COALESCE(SUM(page_view_count),0) page_views FROM vp3_radar_sessions s WHERE s.owner_user_id=? AND s.agent_contact_id IS NULL AND s.last_seen_at>={$since}{$sessionScope}");
    $human->execute(array_merge([$uid],$sessionParams));$humanRow=$human->fetch()?:[];
    $agents=$pdo->prepare("SELECT COUNT(*) sessions,COALESCE(SUM(page_view_count),0) page_views FROM vp3_radar_sessions s WHERE s.owner_user_id=? AND s.agent_contact_id IS NOT NULL AND s.last_seen_at>={$since}{$sessionScope}");
    $agents->execute(array_merge([$uid],$sessionParams));$agentRow=$agents->fetch()?:[];
    $nativeId=vp3_analytics_native_property_id($pdo,$uid);$includeNative=$propertyId===0||($nativeId>0&&$propertyId===$nativeId);
    $nativeHumanSessions=0;$nativeHumanViews=0;
    if($includeNative&&table_exists('profile_visit_sessions')){
        $n=$pdo->prepare("SELECT COUNT(*) sessions,COALESCE(SUM(view_count),0) views FROM profile_visit_sessions WHERE owner_user_id=? AND last_seen_at>={$since}");$n->execute([$uid]);$nr=$n->fetch()?:[];$nativeHumanSessions=(int)($nr['sessions']??0);$nativeHumanViews=(int)($nr['views']??0);
    }

    $eventStmt=$pdo->prepare("SELECT e.id,e.event_type,e.path,e.occurred_at,e.details_json,e.agent_contact_id,e.risk_score,e.severity,p.label property_label,p.property_type,c.display_name,c.operator_name,c.visitor_class
      FROM vp3_radar_events e INNER JOIN vp3_radar_properties p ON p.id=e.property_id LEFT JOIN vp3_agent_contacts c ON c.id=e.agent_contact_id
      WHERE e.owner_user_id=? AND e.occurred_at>={$since}{$eventScope} ORDER BY e.occurred_at DESC,e.id DESC LIMIT 1200");
    $eventStmt->execute(array_merge([$uid],$eventParams));$eventRows=$eventStmt->fetchAll()?:[];
    $conversions=0;$customEvents=0;$devices=[];$browsers=[];$recent=[];
    foreach($eventRows as $row){
        $details=json_decode((string)($row['details_json']??''),true);if(!is_array($details))$details=[];
        if((string)$row['event_type']==='analytics_event'){
            $customEvents++;$eventName=(string)($details['event_name']??'');if(in_array($eventName,VP3_ANALYTICS_CONVERSION_EVENTS,true)||str_starts_with($eventName,'conversion.'))$conversions++;
        }
        if((string)($details['collector']??'')==='browser_analytics'){
            $device=(string)($details['device_type']??'Other');$browser=(string)($details['browser_family']??'Other');$devices[$device]=($devices[$device]??0)+1;$browsers[$browser]=($browsers[$browser]??0)+1;
        }
        if(count($recent)<80)$recent[]=[
            'id'=>(int)$row['id'],'event_type'=>(string)$row['event_type'],'path'=>(string)$row['path'],'occurred_at'=>(string)$row['occurred_at'],
            'property_label'=>(string)$row['property_label'],'property_type'=>(string)$row['property_type'],'agent_contact_id'=>$row['agent_contact_id']!==null?(int)$row['agent_contact_id']:null,
            'display_name'=>(string)($row['display_name']??''),'operator_name'=>(string)($row['operator_name']??''),'visitor_class'=>(string)($row['visitor_class']??''),
            'risk_score'=>(int)$row['risk_score'],'severity'=>(string)$row['severity'],'event_name'=>(string)($details['event_name']??''),'value'=>$details['value']??null,
        ];
    }
    arsort($devices);arsort($browsers);

    $timeline=$pdo->prepare("SELECT DATE(s.last_seen_at) day,COUNT(*) sessions,COALESCE(SUM(s.page_view_count),0) page_views,COALESCE(SUM(s.agent_contact_id IS NOT NULL),0) agent_sessions FROM vp3_radar_sessions s WHERE s.owner_user_id=? AND s.last_seen_at>={$since}{$sessionScope} GROUP BY DATE(s.last_seen_at) ORDER BY day");
    $timeline->execute(array_merge([$uid],$sessionParams));$timelineRows=$timeline->fetchAll()?:[];
    $timelineMap=[];foreach($timelineRows as $row)$timelineMap[(string)$row['day']]=['day'=>(string)$row['day'],'sessions'=>(int)$row['sessions'],'page_views'=>(int)$row['page_views'],'agent_sessions'=>(int)$row['agent_sessions'],'native_sessions'=>0,'native_views'=>0];
    if($includeNative&&table_exists('profile_visit_sessions')){
        $nt=$pdo->prepare("SELECT DATE(last_seen_at) day,COUNT(*) sessions,COALESCE(SUM(view_count),0) views FROM profile_visit_sessions WHERE owner_user_id=? AND last_seen_at>={$since} GROUP BY DATE(last_seen_at) ORDER BY day");$nt->execute([$uid]);
        foreach($nt->fetchAll()?:[] as $row){$day=(string)$row['day'];$timelineMap[$day]??=['day'=>$day,'sessions'=>0,'page_views'=>0,'agent_sessions'=>0,'native_sessions'=>0,'native_views'=>0];$timelineMap[$day]['native_sessions']=(int)$row['sessions'];$timelineMap[$day]['native_views']=(int)$row['views'];}
    }
    ksort($timelineMap);

    $pages=$pdo->prepare("SELECT e.path,COUNT(*) events,COALESCE(SUM(e.agent_contact_id IS NULL),0) human_events,COALESCE(SUM(e.agent_contact_id IS NOT NULL),0) agent_events FROM vp3_radar_events e WHERE e.owner_user_id=? AND e.occurred_at>={$since}{$eventScope} GROUP BY e.path ORDER BY events DESC,e.path LIMIT 20");
    $pages->execute(array_merge([$uid],$eventParams));$topPages=$pages->fetchAll()?:[];
    $refs=$pdo->prepare("SELECT s.referrer_host,COUNT(*) sessions FROM vp3_radar_sessions s WHERE s.owner_user_id=? AND s.last_seen_at>={$since} AND s.referrer_host<>''{$sessionScope} GROUP BY s.referrer_host ORDER BY sessions DESC,s.referrer_host LIMIT 20");
    $refs->execute(array_merge([$uid],$sessionParams));$referrers=$refs->fetchAll()?:[];
    $propertyStats=$pdo->prepare("SELECT p.id,p.label,p.property_type,p.domain,COUNT(s.id) sessions,COALESCE(SUM(s.page_view_count),0) page_views,COALESCE(SUM(s.agent_contact_id IS NOT NULL),0) agent_sessions FROM vp3_radar_properties p LEFT JOIN vp3_radar_sessions s ON s.property_id=p.id AND s.last_seen_at>={$since} WHERE p.owner_user_id=? GROUP BY p.id ORDER BY page_views DESC,sessions DESC,p.id");
    $propertyStats->execute([$uid]);$propertyRows=$propertyStats->fetchAll()?:[];

    $humanSessions=(int)($humanRow['sessions']??0)+$nativeHumanSessions;$humanViews=(int)($humanRow['page_views']??0)+$nativeHumanViews;$agentSessions=(int)($agentRow['sessions']??0);$agentViews=(int)($agentRow['page_views']??0);
    return [
        'ready'=>true,'days'=>$days,'property_id'=>$propertyId,'properties'=>$properties,
        'stats'=>[
            'human_sessions'=>$humanSessions,'agent_sessions'=>$agentSessions,'sessions'=>$humanSessions+$agentSessions,
            'human_page_views'=>$humanViews,'agent_page_views'=>$agentViews,'page_views'=>$humanViews+$agentViews,
            'conversions'=>$conversions,'custom_events'=>$customEvents,'high_risk_events'=>count(array_filter($eventRows,static fn(array $r):bool=>(int)$r['risk_score']>=70)),
        ],
        'timeline'=>array_values($timelineMap),'top_pages'=>$topPages,'referrers'=>$referrers,
        'devices'=>array_map(static fn(string $k,int $v):array=>['label'=>$k,'count'=>$v],array_keys($devices),array_values($devices)),
        'browsers'=>array_map(static fn(string $k,int $v):array=>['label'=>$k,'count'=>$v],array_keys($browsers),array_values($browsers)),
        'events'=>$recent,'property_stats'=>$propertyRows,
    ];
}
