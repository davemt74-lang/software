<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tool-receipts-v1.php';
require_once __DIR__.'/agent-observation-authority-v1.php';

/** Exact owner scope is a server-validated selector, never a fallback to everybody's properties. */
function vp3_analytics_plan_v1(array $plan): array
{
    $view=array_key_exists('view',$plan)?$plan['view']:'summary';$period=array_key_exists('period',$plan)?$plan['period']:'30d';$scope=$plan['scope']??null;
    if(($plan['operation']??null)!=='analytics_read'||($plan['ids']??null)!==['analytics.read']||
        !in_array($view,['summary','properties','top_pages','referrers','conversions','devices','browsers'],true)||!in_array($period,['today','7d','30d','90d'],true)||!in_array($scope,['all_owned','property'],true)||
        array_diff(array_keys($plan),['operation','ids','view','period','scope','property','property_id']))throw new InvalidArgumentException('Invalid Analytics plan.');
    $hasName=array_key_exists('property',$plan);$hasId=array_key_exists('property_id',$plan);
    if(($scope==='all_owned'&&($hasName||$hasId))||($scope==='property'&&$hasName===$hasId))throw new InvalidArgumentException('Choose one exact property or explicit all-owned scope.');
    if($hasId&&(!is_int($plan['property_id'])||$plan['property_id']<1))throw new InvalidArgumentException('Invalid Analytics property ID.');
    if($hasName&&(!is_string($plan['property'])||!mb_check_encoding($plan['property'],'UTF-8')||trim($plan['property'])===''||mb_strlen($plan['property'])>190||preg_match('/[\p{Cc}\p{Cf}]/u',$plan['property'])))throw new InvalidArgumentException('Invalid Analytics property selector.');
    return ['operation'=>'analytics_read','ids'=>['analytics.read'],'view'=>$view,'period'=>$period,'scope'=>$scope]+($hasName?['property'=>trim($plan['property'])]:($hasId?['property_id'=>$plan['property_id']]:[]));
}

function vp3_analytics_tables_v1(PDO $pdo,array $tables): bool
{
    $marks=implode(',',array_fill(0,count($tables),'?'));
    $sql=match($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)){'sqlite'=>"SELECT name FROM sqlite_master WHERE type='table' AND name IN ($marks)",'mysql'=>"SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks)",default=>throw new RuntimeException('Analytics storage cannot be inspected.')};
    $q=$pdo->prepare($sql);$q->execute($tables);$found=$q->fetchAll(PDO::FETCH_COLUMN);
    return count($found)===count($tables)&&!array_diff($tables,$found);
}

function vp3_analytics_schema_ready_v1(PDO $pdo): bool
{
    return vp3_analytics_tables_v1($pdo,['vp3_radar_properties','vp3_radar_sessions','vp3_radar_events']);
}

/** PDO MySQL SUM(BIGINT) is a decimal string; SQLite commonly returns an integer. */
function vp3_analytics_count_v1(mixed $value): int
{
    if(is_int($value)&&$value>=0)return $value;
    if(is_string($value)&&preg_match('/^(?:0|[1-9][0-9]*)$/D',$value)&&strlen($value)<=strlen((string)PHP_INT_MAX)&&(strlen($value)<strlen((string)PHP_INT_MAX)||strcmp($value,(string)PHP_INT_MAX)<=0))return (int)$value;
    throw new RuntimeException('Analytics count could not be verified.');
}

function vp3_analytics_label_v1(mixed $value,int $limit=160): string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))throw new RuntimeException('Analytics label could not be verified.');
    $value=mb_substr(trim(preg_replace('/[\p{Cc}\p{Cf}]+/u',' ',$value)??''),0,$limit);
    return str_replace(['\\','`','*','_','[',']','(',')','{','}','<','>','#','|','!'],['\\\\','\\`','\\*','\\_','\\[','\\]','\\(','\\)','\\{','\\}','\\<','\\>','\\#','\\|','\\!'],$value);
}

function vp3_analytics_domain_v1(string $value): ?string
{
    $value=trim($value);if($value===''||preg_match('/[\s\\\\<>]/u',$value))return null;
    $p=parse_url(str_contains($value,'://')?$value:'https://'.$value);
    if(!is_array($p)||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||(isset($p['path'])&&$p['path']!=='/'&&$p['path']!=='')||!in_array(strtolower($p['scheme']??''),['https','http'],true))return null;
    $host=strtolower(rtrim($p['host']??'','.'));if(str_starts_with($host,'www.'))$host=substr($host,4);
    if($host===''||strlen($host)>190||!str_contains($host,'.')||filter_var($host,FILTER_VALIDATE_IP)||!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))return null;
    return $host;
}

/** Keep public route labels useful without returning query strings, credentials or opaque identifiers. */
function vp3_analytics_path_v1(mixed $value): string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||strlen($value)>2000)throw new RuntimeException('Analytics path could not be verified.');
    $path=(string)parse_url($value,PHP_URL_PATH);
    if(!str_starts_with($path,'/')||str_starts_with($path,'//')||preg_match('~^/(?:etc|srv|var|private|workspace|mnt)/~i',$path))return '(private path omitted)';
    $parts=explode('/',trim($path,'/'));$safe=[];$private=false;
    foreach(array_slice($parts,0,6) as $part){
        $part=rawurldecode($part);if($part==='')continue;
        $sensitive=$private||preg_match('/(?:token|secret|session|credential|password|signature|nonce|api[_-]?key|reset|auth)/i',$part)||!preg_match('/^[a-z][a-z0-9._~-]{0,39}$/iD',$part)||preg_match('/^[a-f0-9-]{16,}$/iD',$part)||(strlen($part)>=20&&preg_match('/[0-9]/',$part)&&preg_match('/[a-z]/i',$part));
        if($sensitive){$safe[]='[redacted]';$private=true;}else $safe[]=$part;
    }
    if(count($parts)>6)$safe[]='…';
    return vp3_analytics_label_v1('/'.implode('/',$safe),190);
}

function vp3_analytics_time_v1(mixed $value): string
{
    if(!is_string($value))throw new RuntimeException('Analytics reporting clock is unavailable.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    if(!$d||$d->format('Y-m-d H:i:s')!==$value)throw new RuntimeException('Analytics reporting clock is invalid.');
    return $value;
}

/** DATETIME collectors use NOW(). Report that verified clock, without assuming historical values were UTC. */
function vp3_analytics_clock_v1(PDO $pdo,string $period): array
{
    $days=match($period){'today'=>0,'7d'=>7,'30d'=>30,'90d'=>90,default=>throw new InvalidArgumentException('Invalid Analytics period.')};
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $from=$days===0?"CONCAT(CURRENT_DATE(),' 00:00:00')":"DATE_SUB(NOW(),INTERVAL $days DAY)";
        $row=$pdo->query("SELECT NOW() AS as_of,$from AS from_at,UTC_TIMESTAMP() AS utc_now,@@session.time_zone AS zone,@@system_time_zone AS system_zone,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW()) AS utc_offset")->fetch(PDO::FETCH_ASSOC);
    }elseif($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $from=$days===0?"datetime('now','start of day')":"datetime('now','-$days days')";
        $row=$pdo->query("SELECT datetime('now') AS as_of,$from AS from_at,datetime('now') AS utc_now,'UTC' AS zone,'UTC' AS system_zone,0 AS utc_offset")->fetch(PDO::FETCH_ASSOC);
    }else throw new RuntimeException('Analytics reporting clock is unsupported.');
    if(!is_array($row))throw new RuntimeException('Analytics reporting clock is missing.');
    foreach(['as_of','from_at','utc_now'] as $key)$row[$key]=vp3_analytics_time_v1($row[$key]??null);
    $offset=$row['utc_offset']??null;
    if(!(is_int($offset)||(is_string($offset)&&preg_match('/^-?(?:0|[1-9][0-9]{0,5})$/D',$offset)))||abs((int)$offset)>86400)throw new RuntimeException('Analytics reporting offset is invalid.');
    $offset=(int)$offset;$utc=new DateTimeZone('UTC');
    if((new DateTimeImmutable($row['as_of'],$utc))->getTimestamp()-(new DateTimeImmutable($row['utc_now'],$utc))->getTimestamp()!==$offset||strcmp($row['from_at'],$row['as_of'])>0)throw new RuntimeException('Analytics reporting clock disagrees.');
    foreach(['zone','system_zone'] as $key)if(!is_string($row[$key]??null)||!preg_match('/^[A-Za-z0-9_+.:\/-]{1,80}$/D',$row[$key]))throw new RuntimeException('Analytics reporting timezone is unavailable.');
    $label=$row['zone']==='SYSTEM'?'SYSTEM (server '.$row['system_zone'].')':$row['zone'];
    $hours=intdiv(abs($offset),3600);$minutes=intdiv(abs($offset)%3600,60);
    $label.='; UTC'.($offset<0?'-':'+').sprintf('%02d:%02d',$hours,$minutes).' at read';
    return ['from'=>$row['from_at'],'to'=>$row['as_of'],'zone'=>$label,'period'=>$period];
}

function vp3_analytics_properties_v1(PDO $pdo,int $uid): array
{
    $q=$pdo->prepare('SELECT id,owner_user_id,property_type,label,domain,is_active FROM vp3_radar_properties WHERE owner_user_id=? ORDER BY id LIMIT 1001');$q->execute([$uid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>1000)throw new RuntimeException('Analytics property selection exceeds the read limit.');
    $out=[];
    foreach($rows as $row){
        $id=vp3_analytics_count_v1($row['id']??null);$owner=vp3_analytics_count_v1($row['owner_user_id']??null);
        if($id<1||$owner!==$uid||!in_array($row['property_type']??null,['native','external'],true)||!in_array($row['is_active']??null,[0,1,'0','1'],true))throw new RuntimeException('Analytics property ownership is invalid.');
        foreach(['label','domain'] as $key)if(!is_string($row[$key]??null)||!mb_check_encoding($row[$key],'UTF-8')||mb_strlen($row[$key])>190)throw new RuntimeException('Analytics property label is invalid.');
        $out[]=['id'=>$id,'type'=>$row['property_type'],'name'=>$row['property_type']==='native'?'VP3 Profile':(trim($row['label'])!==''?$row['label']:$row['domain']),'label'=>$row['label'],'domain'=>$row['domain'],'active'=>(bool)$row['is_active']];
    }
    return $out;
}

/** Projection helper; only explicitly selected values and complete aggregate counts leave SQL. */
function vp3_analytics_rows_v1(PDO $pdo,string $sql,array $params,callable $check): array
{
    $check();$q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$check();return $rows;
}

function vp3_analytics_failure_v1(Throwable $error): array
{
    $denied=$error->getCode()===403;$upgrade=$error->getCode()===409;$invalid=$error instanceof InvalidArgumentException;
    return ['handled'=>true,'answer'=>$denied?'The current owner, Agent, conversation or Analytics permission no longer permits this read.':($upgrade?'Analytics storage is unavailable or requires upgrade.php. This does not establish zero traffic.':($invalid?'Choose a supported Analytics view and period, with all owned properties or one exact owned property.':'The Analytics read could not be verified. No visitor, page-view or conversion total was established.')),'actions'=>[],'sources'=>[],
        'tool_receipt'=>vp3_tool_receipt_v1('analytics.read','list',$denied||$upgrade?'blocked':'failed',$denied?'permission_denied':($upgrade?'upgrade_required':($invalid?'invalid_plan':'execution_failed')))];
}

function vp3_analytics_read_v1(PDO $pdo,array $user,array $principal,array $plan,array $context=[]): array
{
    try{
        $plan=vp3_analytics_plan_v1($plan);
        $check=static fn()=>vp3_observation_authority_v1($pdo,$user,$principal,$context,static fn($live)=>vp3_observation_personal_permission_v1($pdo,$live,'profile_agent.access'));
        $live=$check();$uid=(int)$live['id'];
        if(!vp3_analytics_schema_ready_v1($pdo))throw new RuntimeException('Analytics storage requires upgrade.',409);
        $all=vp3_analytics_properties_v1($pdo,$uid);$check();$properties=$all;
        if($plan['scope']==='property'){
            $selector=$plan['property']??'';$domain=is_string($selector)?vp3_analytics_domain_v1($selector):null;
            $properties=array_values(array_filter($all,static fn($p)=>isset($plan['property_id'])?$p['id']===$plan['property_id']:(mb_strtolower(trim($p['name']))===mb_strtolower($selector)||mb_strtolower(trim($p['label']))===mb_strtolower($selector)||($domain!==null&&vp3_analytics_domain_v1($p['domain'])===$domain))));
            if(count($properties)!==1){$check();return ['handled'=>true,'answer'=>'Select one exact owned Analytics property from “show my Analytics properties”. The supplied selection did not identify one property; the scope has not been broadened.','actions'=>[['type'=>'open_url','label'=>'Open Analytics','url'=>url('/profile-agent.php?tab=analytics'),'auto'=>false]],'sources'=>[],'tool_receipt'=>vp3_tool_receipt_v1('analytics.read','list','clarification_required')];}
        }
        $clock=vp3_analytics_clock_v1($pdo,$plan['period']);$check();
        $scope=$plan['scope']==='all_owned'?'All '.count($properties).' owned tracked properties':vp3_analytics_label_v1($properties[0]['name']).' (property #'.$properties[0]['id'].')';
        $header=$scope.'. Reporting window: '.$clock['from'].' through '.$clock['to'].'; database timezone '.vp3_analytics_label_v1($clock['zone'],100).'. '.($plan['period']==='today'?'Today starts at this database calendar midnight.':'This is a rolling '.$plan['period'].' reporting window.');
        if(!$properties){$check();return ['handled'=>true,'answer'=>$header.' No owned tracked properties are recorded. This does not prove that no one visited a website.','actions'=>[],'sources'=>[['source'=>'analytics:stored','title'=>'Owned stored Analytics properties']],'tool_receipt'=>vp3_tool_receipt_v1('analytics.read','list','no_matches')];}
        $where='p.owner_user_id=?';$scopeParams=[$uid];if($plan['scope']==='property'){$where.=' AND p.id=?';$scopeParams[]=$properties[0]['id'];}
        $sessionFrom=' FROM vp3_radar_sessions s INNER JOIN vp3_radar_properties p ON p.id=s.property_id AND p.owner_user_id=s.owner_user_id WHERE '.$where.' AND s.last_seen_at>=? AND s.last_seen_at<=?';
        $eventFrom=' FROM vp3_radar_events e INNER JOIN vp3_radar_properties p ON p.id=e.property_id AND p.owner_user_id=e.owner_user_id WHERE '.$where.' AND e.occurred_at>=? AND e.occurred_at<=?';
        $params=[...$scopeParams,$clock['from'],$clock['to']];$view=$plan['view'];$lines=[];$status='read_complete';$count=1;$nativeCount=null;
        $native=array_values(array_filter($properties,static fn($p)=>$p['type']==='native'));
        if(count($native)>1)throw new RuntimeException('Native Analytics property is ambiguous.');
        if($native&&in_array($view,['summary','top_pages'],true)){
            if(!vp3_analytics_tables_v1($pdo,['profile_events','profile_visit_sessions']))throw new RuntimeException('Native Analytics storage requires upgrade.',409);
            $rows=vp3_analytics_rows_v1($pdo,"SELECT COUNT(*) AS visits FROM profile_events e LEFT JOIN profile_visit_sessions s ON s.id=e.profile_session_id WHERE e.owner_user_id=? AND (e.profile_session_id IS NULL OR s.owner_user_id=e.owner_user_id) AND e.event_type='profile_view' AND e.created_at>=? AND e.created_at<=?",[$uid,$clock['from'],$clock['to']],$check);
            $nativeCount=vp3_analytics_count_v1($rows[0]['visits']??null);
        }
        if($view==='properties'){
            foreach(array_slice($properties,0,12) as $p){$domain=vp3_analytics_domain_v1($p['domain']);$lines[]='#'.$p['id'].' · '.vp3_analytics_label_v1($p['name']).' · '.($domain??'domain unavailable').' · '.$p['type'].' · '.($p['active']?'tracking active':'tracking inactive; stored history remains available');}
            $count=count($lines);$header.=' Showing '.$count.' of '.count($properties).' current property records. Activity labels do not prove that a collector is working.';
        }elseif($view==='summary'){
            $rows=vp3_analytics_rows_v1($pdo,"SELECT COUNT(*) AS sessions,COALESCE(SUM(CASE WHEN s.agent_contact_id IS NULL AND s.visitor_type='human' THEN 1 ELSE 0 END),0) AS human,COALESCE(SUM(CASE WHEN s.agent_contact_id IS NOT NULL OR SUBSTR(s.visitor_type,1,7)='webmcp_' THEN 1 ELSE 0 END),0) AS automated".$sessionFrom,$params,$check);$s=$rows[0]??[];
            foreach(['sessions','human','automated'] as $key)$s[$key]=vp3_analytics_count_v1($s[$key]??null);if($s['human']+$s['automated']>$s['sessions'])throw new RuntimeException('Analytics session totals disagree.');
            $lines[]=$s['sessions'].' stored Radar sessions last active in this window: '.$s['human'].' classified human, '.$s['automated'].' automated, '.($s['sessions']-$s['human']-$s['automated']).' unclassified. Sessions are not unique people.';
            $rows=vp3_analytics_rows_v1($pdo,"SELECT COALESCE(SUM(CASE WHEN e.event_type='analytics_page_view' AND e.agent_contact_id IS NULL THEN 1 ELSE 0 END),0) AS browser_views,COALESCE(SUM(CASE WHEN e.event_type IN ('agent_profile_view','external_agent_page_view') AND e.agent_contact_id IS NOT NULL THEN 1 ELSE 0 END),0) AS agent_views,COALESCE(SUM(CASE WHEN e.event_type='external_agent_request' AND e.agent_contact_id IS NOT NULL THEN 1 ELSE 0 END),0) AS agent_requests".$eventFrom,$params,$check);$events=$rows[0]??[];
            foreach(['browser_views','agent_views','agent_requests'] as $key)$events[$key]=vp3_analytics_count_v1($events[$key]??null);
            $lines[]=$events['browser_views'].' browser page-view events, '.$events['agent_views'].' automated page-view events and '.$events['agent_requests'].' automated request events recorded in this window. These are complete event aggregates, not lifetime session counters.';
        }elseif($view==='top_pages'){
            $rows=vp3_analytics_rows_v1($pdo,"SELECT e.path,COUNT(*) AS count".$eventFrom." AND e.event_type IN ('analytics_page_view','agent_profile_view','external_agent_page_view') GROUP BY e.path ORDER BY count DESC,e.path LIMIT 13",$params,$check);
            foreach(array_slice($rows,0,12) as $row)$lines[]=vp3_analytics_path_v1($row['path']??null).' · '.vp3_analytics_count_v1($row['count']??null).' recorded page-view events';
            $count=count($lines);$header.=' Counts aggregate every matching page-view event; showing up to 12 path groups. Query strings and sensitive path segments are omitted.'.(count($rows)>12?' More path groups exist.':'');
        }elseif($view==='referrers'){
            $rows=vp3_analytics_rows_v1($pdo,"SELECT s.referrer_host,COUNT(*) AS count".$sessionFrom." AND s.referrer_host<>'' GROUP BY s.referrer_host ORDER BY count DESC,s.referrer_host LIMIT 13",$params,$check);
            foreach(array_slice($rows,0,12) as $row){if(!is_string($row['referrer_host']??null))throw new RuntimeException('Referrer could not be verified.');$host=vp3_analytics_domain_v1($row['referrer_host']);$lines[]=($host??'(private or invalid referrer omitted)').' · '.vp3_analytics_count_v1($row['count']??null).' stored sessions last active in the window';}
            $count=count($lines);$header.=' Complete session counts per referrer; showing up to 12 nonempty referrer groups. These are sessions, not people.'.(count($rows)>12?' More referrer groups exist.':'');
        }
        if(in_array($view,['summary','conversions','devices','browsers'],true)){
            $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$type=$mysql?'STRING':'text';
            $value=static fn($key)=>'CASE WHEN JSON_VALID(e.details_json) THEN '.($mysql?"JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.".$key."'))":"JSON_EXTRACT(e.details_json,'$.".$key."')").' ELSE NULL END';
            $jsonType=static fn($key)=>'CASE WHEN JSON_VALID(e.details_json) THEN '.($mysql?"JSON_TYPE(JSON_EXTRACT(e.details_json,'$.".$key."'))":"JSON_TYPE(e.details_json,'$.".$key."')").' ELSE NULL END';
            $collector=$value('collector');$name=$value('event_name');$field=$view==='devices'?'device_type':'browser_family';
            $valid='('.$jsonType('collector')."='$type' AND ".$collector."='browser_analytics' AND ".$jsonType('event_name')."='$type' AND LENGTH(".$name.') BETWEEN 1 AND 64)';
            if(in_array($view,['devices','browsers'],true))$valid.=' AND '.$jsonType($field)."='$type'";
            $jsonFrom=$eventFrom." AND e.agent_contact_id IS NULL AND e.event_type IN ('analytics_page_view','analytics_event')";
            $rows=vp3_analytics_rows_v1($pdo,'SELECT COUNT(*) AS events,COALESCE(SUM(CASE WHEN COALESCE('.$valid.',0) THEN 0 ELSE 1 END),0) AS invalid'.$jsonFrom,$params,$check);
            $total=vp3_analytics_count_v1($rows[0]['events']??null);if(vp3_analytics_count_v1($rows[0]['invalid']??null)!==0)throw new RuntimeException('Analytics event metadata is incomplete.');
            if(in_array($view,['summary','conversions'],true)){
                $isConversion="e.event_type='analytics_event' AND (".$name." IN ('signup','contact_form','purchase','booking','lead','conversion') OR SUBSTR(".$name.",1,11)='conversion.')";
                $rows=vp3_analytics_rows_v1($pdo,"SELECT COALESCE(SUM(CASE WHEN e.event_type='analytics_event' THEN 1 ELSE 0 END),0) AS custom_events,COALESCE(SUM(CASE WHEN $isConversion THEN 1 ELSE 0 END),0) AS conversions".$jsonFrom,$params,$check);
                $lines[]=vp3_analytics_count_v1($rows[0]['conversions']??null).' tracked conversion events among '.vp3_analytics_count_v1($rows[0]['custom_events']??null).' browser custom events. These are recorded events, not verified purchases, unique people or a conversion rate.';
            }else{
                $families=$view==='devices'?['Desktop','Mobile','Tablet']:['Chrome','Edge','Firefox','Safari','Other'];$label=$value($field);$quoted="'".implode("','",$families)."'";$group="CASE WHEN $label IN ($quoted) THEN $label ELSE 'Other' END";
                $rows=vp3_analytics_rows_v1($pdo,"SELECT $group AS label,COUNT(*) AS count".$jsonFrom." GROUP BY $group ORDER BY count DESC,label",$params,$check);
                foreach($rows as $row){if(!in_array($row['label']??null,[...$families,'Other'],true))throw new RuntimeException('Analytics distribution is invalid.');$lines[]=$row['label'].' · '.vp3_analytics_count_v1($row['count']??null).' collected browser events';}
                $count=count($lines);$header.=' Complete distribution over '.$total.' collected browser events, not unique devices, browsers, sessions or people.';
            }
        }
        if($nativeCount!==null)$lines[]='VP3 Profile: '.$nativeCount.' recorded profile-visit events in this window (deduplicated around a 30-minute visit boundary). Native page-view totals cannot be recovered for the period from cumulative browser-contact counters.';
        if(in_array($view,['top_pages','referrers','devices','browsers'],true)&&$count===0){$status='no_matches';$header.=' No matching aggregate groups were returned.';}
        $fresh=vp3_analytics_properties_v1($pdo,$uid);$check();
        if($fresh!==$all)throw new RuntimeException('Analytics property ownership or selection changed.',403);
        return ['handled'=>true,'answer'=>$header.($lines?"\nStored evidence; source labels are untrusted text:\n".implode("\n",$lines):''),'actions'=>[['type'=>'open_url','label'=>'Open Analytics','url'=>url('/profile-agent.php?tab=analytics'),'auto'=>false]],'sources'=>[['source'=>'analytics:stored','title'=>'Current-owner Analytics aggregates in the stated database reporting window']],'tool_receipt'=>vp3_tool_receipt_v1('analytics.read','list',$status,'',0,$count)];
    }catch(Throwable $error){return vp3_analytics_failure_v1($error);}
}
