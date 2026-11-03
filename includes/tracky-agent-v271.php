<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tracky-read-v1.php';

/**
 * Tracky V2.71 — VP3 Agent Brain / Physical Context integration.
 *
 * Tracky remains an OTRO/HomeServer sensing subsystem. VP3 Cloud receives only
 * governed physical meaning from V2.70 and exposes it to the existing Agent
 * Brain through the canonical Cognitive Runtime and Agent tool boundary.
 *
 * This layer is READ ONLY with respect to the physical world. It creates no
 * device-control authority and never exposes raw frames, video, audio or local
 * perception evidence.
 */
const VP3_TRACKY_AGENT_V271='vp3-tracky-agent-v271-20260926';
const VP3_TRACKY_AGENT_CONTRACT_V271='physical-context-agent-v1';
const VP3_TRACKY_AGENT_MAX_ROWS_V271=200;
const VP3_TRACKY_AGENT_MAX_EVENT_SCAN_V271=300;
const VP3_TRACKY_AGENT_CURRENT_SECONDS_V271=300;
const VP3_TRACKY_AGENT_RECENT_SECONDS_V271=3600;

function tracky_agent_empty_v271(): array
{
    return ['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
}

function tracky_agent_enabled_v271(PDO $pdo,array $user): bool
{
    return (int)($user['id']??0)>0
        && function_exists('tracky_cloud_v270_plugin_enabled')
        && tracky_cloud_v270_plugin_enabled($pdo,$user)
        && vp3_tracky_schema_ready_v1($pdo);
}

function tracky_agent_entity_label_v271(string $id): string
{
    $id=trim($id);
    if($id==='')return 'Unknown';
    if(str_contains($id,':'))$id=substr($id,strrpos($id,':')+1);
    $id=str_replace(['_','-','.'], ' ', $id);
    $id=preg_replace('/\s+/u',' ',trim($id))??$id;
    return $id===''?'Unknown':mb_convert_case($id,MB_CASE_TITLE,'UTF-8');
}

function tracky_agent_freshness_v271(?string $asOf,?array $site=null): array
{
    $stamp=vp3_tracky_timestamp_v1($asOf);$age=$stamp===null?null:time()-$stamp;
    if($age===null||$age<0)return ['state'=>'unknown','age_seconds'=>null,'live'=>false];
    $unavailable=in_array(strtolower(trim((string)($site['status']??''))),['offline','disabled','failed','recovering','unknown'],true);
    if(!$unavailable&&$age<=VP3_TRACKY_AGENT_CURRENT_SECONDS_V271)return ['state'=>'current','age_seconds'=>$age,'live'=>true];
    if(!$unavailable&&$age<=VP3_TRACKY_AGENT_RECENT_SECONDS_V271)return ['state'=>'recent','age_seconds'=>$age,'live'=>false];
    return ['state'=>'stale','age_seconds'=>$age,'live'=>false];
}

function tracky_agent_query_terms_v271(string $query): array
{
    $stop=array_flip([
        'where','what','when','who','why','how','which','is','are','was','were','am','do','does','did',
        'the','a','an','my','me','i','you','your','our','we','it','this','that','in','at','on','of','to',
        'tracky','physical','physically','currently','current','right','now','last','seen','see','saw',
        'room','rooms','present','presence','located','location','find','found','tell','show','please'
    ]);
    $parts=preg_split('/[^\pL\pN:_-]+/u',mb_strtolower($query))?:[];
    $out=[];
    foreach($parts as $part){
        $part=trim($part);
        if(mb_strlen($part)<2||isset($stop[$part]))continue;
        $out[$part]=true;
    }
    return array_slice(array_keys($out),0,12);
}

function tracky_agent_nonphysical_subject_v271(string $query): bool
{
    return (bool)preg_match('/\b(?:file|document|doc|email|message|order|booking|appointment|meeting|calendar|project|campaign|reward|invoice|payment|song|track|stem|playlist|website|page|release|conversation|task|crm|account|setting|menu|button|repository|repo|branch|commit|pull request|database|sql|password|credential|api key|secret key|access token|phone number|email address|billing address|shipping address|url|link)\b/i',$query);
}

function tracky_agent_intent_v271(string $query): string
{
    $q=mb_strtolower(trim($query));
    if($q==='')return '';

    $explicit=(bool)preg_match('/\b(?:tracky|physical context|physical awareness|room|rooms|presence|present|camera health|environment|last seen|last saw|what moved|who is here|who\'s here|where am i)\b/u',$q);
    if(!$explicit&&tracky_agent_nonphysical_subject_v271($q))return '';

    if(preg_match('/\b(?:agent eyes|scene sharing)\b|(?:what|show).*tracky.*(?:see|scene)|(?:what|show).*(?:see|scene).*tracky/u',$q))return 'current';

    if(preg_match('/\b(?:tracky|physical awareness|camera|physical context)\b.*\b(?:health|status|online|offline|working|connected)\b|\b(?:health|status)\b.*\btracky\b/u',$q))return 'health';
    if(preg_match('/\b(?:last seen|last saw|when did (?:you|tracky)(?: last)? (?:see|spot|notice)|when (?:did )?(?:you|tracky) last (?:see|spot|notice)|when was .{1,80} seen)\b/u',$q))return 'last_seen';
    if(preg_match('/\b(?:what changed|what has changed|recent changes|what moved|what happened in (?:the )?room|physical changes)\b/u',$q))return 'changes';
    if(preg_match('/\b(?:who is|who\'s|who was|anyone|anybody|people)\b.*\b(?:here|present|in|room)\b|\bwho is here\b/u',$q))return 'present';
    if(preg_match('/\b(?:how sure|confidence|how confident)\b/u',$q))return 'confidence';
    if(preg_match('/\b(?:why do you think|why does tracky|why is tracky|what evidence|based on what)\b/u',$q))return 'why';
    if(preg_match('/\b(?:where am i|what room am i in|what do you see around me|what is around me|current physical context|physical context)\b/u',$q))return 'current';
    if(preg_match('/\bwhere\s+(?:is|are|was|were)\b/u',$q)){
        if(tracky_agent_nonphysical_subject_v271($q)&&!$explicit)return '';
        return 'where';
    }
    if($explicit&&preg_match('/\b(?:who|where|room|present|environment|context|around)\b/u',$q))return 'current';
    return '';
}

function tracky_agent_site_v271(PDO $pdo,int $userId,string $query=''): ?array
{
    if($userId<1||!vp3_tracky_schema_ready_v1($pdo))return null;
    $rows=vp3_tracky_sites_v1($pdo,$userId);$matches=[];
    foreach($rows as $row)if(vp3_tracky_query_has_label_v1($query,$row['site_id'])||($row['label']!==''&&vp3_tracky_query_has_label_v1($query,$row['label'])))$matches[]=$row;
    if(count($matches)===1&&count($rows)<=100)return $matches[0];
    if($matches||preg_match('/\bsite\s+[A-Za-z0-9]/i',$query))return null;
    return count($rows)===1?$rows[0]:null;
}

function tracky_agent_location_rows_v271(PDO $pdo,int $userId,string $siteId): array
{
    $stmt=$pdo->prepare("SELECT site_id,subject_id,predicate,object_id,value_json,confidence,temporal_state,source_event_id,sequence_no,as_of
      FROM tracky_cloud_world_state
      WHERE user_id=? AND site_id=? AND predicate IN ('located_in','located_on','present_in','moving_between')
      ORDER BY as_of DESC,confidence DESC LIMIT ".VP3_TRACKY_AGENT_MAX_ROWS_V271);
    $stmt->execute([$userId,$siteId]);
    return $stmt->fetchAll()?:[];
}

function tracky_agent_row_score_v271(array $row,string $query): int
{
    $subject=(string)($row['subject_id']??'');if($subject==='')return 0;
    if(vp3_tracky_query_has_label_v1($query,$subject))return 100;
    return vp3_tracky_query_has_label_v1($query,tracky_agent_entity_label_v271($subject))?80:0;
}

function tracky_agent_where_v271(PDO $pdo,array $user,array $site,string $query): array
{
    $uid=(int)$user['id'];$siteId=(string)$site['site_id'];
    $context=tracky_cloud_v270_current_context($pdo,$uid,$siteId);
    if(preg_match('/\b(?:where am i|what room am i in)\b/i',$query)){
        $room=trim((string)($context['context']['current_room']??''));
        if($room!==''){
            return [
                'kind'=>'current_room','entity'=>'You','location'=>$room,
                'confidence'=>(float)($context['context']['confidence']??0),
                'as_of'=>(string)($context['observed_at']??''),
                'freshness'=>vp3_tracky_observation_v1($context['observed_at']??null,$site,vp3_tracky_continuity_v1($pdo,$uid)),
                'site_id'=>$siteId,
            ];
        }
    }

    $rows=tracky_agent_location_rows_v271($pdo,$uid,$siteId);
    $scored=[];
    foreach($rows as $row){
        $state=(string)($row['temporal_state']??'current');
        if(!in_array($state,['current','inferred','predicted'],true))continue;
        $score=tracky_agent_row_score_v271($row,$query);
        if($score>0)$scored[]=['score'=>$score,'row'=>$row];
    }
    usort($scored,static function(array $a,array $b): int {
        $score=(int)$b['score']<=>(int)$a['score'];
        if($score!==0)return $score;
        $confidence=(float)$b['row']['confidence']<=>(float)$a['row']['confidence'];
        return $confidence!==0?$confidence:strcmp((string)$b['row']['as_of'],(string)$a['row']['as_of']);
    });
    if(!$scored)return ['kind'=>'where','matches'=>[],'site_id'=>$siteId];

    $top=(int)$scored[0]['score'];$matches=[];
    foreach($scored as $entry){
        if(count($matches)>=5)break;
        if($top>1&&(int)$entry['score']<$top-10)continue;
        $row=$entry['row'];
        $matches[]=[
            'entity_id'=>(string)$row['subject_id'],
            'entity'=>tracky_agent_entity_label_v271((string)$row['subject_id']),
            'predicate'=>(string)$row['predicate'],
            'location_id'=>(string)$row['object_id'],
            'location'=>tracky_agent_entity_label_v271((string)$row['object_id']),
            'confidence'=>(float)$row['confidence'],
            'temporal_state'=>(string)$row['temporal_state'],
            'source_event_id'=>(string)$row['source_event_id'],
            'sequence'=>(int)$row['sequence_no'],
            'as_of'=>(string)$row['as_of'],
            'freshness'=>vp3_tracky_observation_v1($row['as_of']??null,$site,vp3_tracky_continuity_v1($pdo,$uid),(string)($row['temporal_state']??'unknown')),
        ];
    }
    return ['kind'=>'where','matches'=>$matches,'site_id'=>$siteId];
}

function tracky_agent_present_v271(PDO $pdo,array $user,array $site,string $query): array
{
    $uid=(int)$user['id'];$siteId=(string)$site['site_id'];$rows=tracky_agent_location_rows_v271($pdo,$uid,$siteId);
    $roomNeedles=tracky_agent_query_terms_v271($query);$roomId='';$roomLabel='';
    foreach($rows as $row){
        $object=(string)$row['object_id'];
        if(!str_starts_with(strtolower($object),'room:'))continue;
        $label=tracky_agent_entity_label_v271($object);$hay=mb_strtolower($object.' '.$label);
        foreach($roomNeedles as $term){
            if(str_contains($hay,$term)){$roomId=$object;$roomLabel=$label;break 2;}
        }
    }
    if($roomId===''){
        $context=tracky_cloud_v270_current_context($pdo,$uid,$siteId);
        $roomLabel=trim((string)($context['context']['current_room']??''));
    }

    $people=[];
    foreach($rows as $row){
        if(!str_starts_with(strtolower((string)$row['subject_id']),'person:'))continue;
        if(!in_array((string)$row['temporal_state'],['current','inferred'],true))continue;
        if($roomId!==''&&!hash_equals($roomId,(string)$row['object_id']))continue;
        if($roomId===''&&$roomLabel!==''){
            $objectLabel=tracky_agent_entity_label_v271((string)$row['object_id']);
            if(mb_strtolower($objectLabel)!==mb_strtolower($roomLabel))continue;
        }
        $people[]=[
            'entity_id'=>(string)$row['subject_id'],
            'name'=>tracky_agent_entity_label_v271((string)$row['subject_id']),
            'room_id'=>(string)$row['object_id'],
            'room'=>tracky_agent_entity_label_v271((string)$row['object_id']),
            'confidence'=>(float)$row['confidence'],
            'as_of'=>(string)$row['as_of'],
            'freshness'=>vp3_tracky_observation_v1($row['as_of']??null,$site,vp3_tracky_continuity_v1($pdo,$uid),(string)($row['temporal_state']??'unknown')),
        ];
    }
    if(!$people&&$roomId===''&&$roomLabel!==''){
        $context=tracky_cloud_v270_current_context($pdo,$uid,$siteId);
        foreach((array)($context['context']['people_present']??[]) as $name){
            $name=trim((string)$name);if($name==='')continue;
            $observed=(string)($context['observed_at']??'');
            $people[]=['entity_id'=>'','name'=>$name,'room_id'=>'','room'=>$roomLabel,'confidence'=>(float)($context['context']['confidence']??0),'as_of'=>$observed,'freshness'=>vp3_tracky_observation_v1($observed,$site,vp3_tracky_continuity_v1($pdo,$uid))];
        }
    }
    return ['kind'=>'present','room'=>$roomLabel,'room_id'=>$roomId,'people'=>array_slice($people,0,20),'site_id'=>$siteId];
}

function tracky_agent_event_matches_v271(array $event,string $query): bool
{
    $terms=tracky_agent_query_terms_v271($query);
    if(!$terms)return true;
    $hay=mb_strtolower(json_encode([
        $event['event_type']??'',$event['subject']??[],$event['object']??[],
        $event['room_id']??'',$event['environment_id']??'',$event['summary']??'',
        $event['state']??'',$event['previous_state']??''
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'');
    foreach($terms as $term)if(str_contains($hay,$term))return true;
    return false;
}

function tracky_agent_last_seen_v271(PDO $pdo,array $user,array $site,string $query): array
{
    $stmt=$pdo->prepare("SELECT event_id,event_type,severity,confidence,privacy_class,occurred_at,event_json
      FROM tracky_cloud_events WHERE user_id=? AND site_id=? ORDER BY occurred_at DESC,id DESC LIMIT ".VP3_TRACKY_AGENT_MAX_EVENT_SCAN_V271);
    $stmt->execute([(int)$user['id'],(string)$site['site_id']]);
    foreach($stmt->fetchAll()?:[] as $row){
        $event=json_decode((string)$row['event_json'],true);if(!is_array($event))$event=[];
        if(!tracky_agent_event_matches_v271($event,$query))continue;
        return [
            'kind'=>'last_seen','event_id'=>(string)$row['event_id'],'event_type'=>(string)$row['event_type'],
            'occurred_at'=>(string)$row['occurred_at'],'confidence'=>(float)$row['confidence'],
            'privacy_class'=>(string)$row['privacy_class'],'event'=>$event,'site_id'=>(string)$site['site_id'],
        ];
    }
    return ['kind'=>'last_seen','event'=>null,'site_id'=>(string)$site['site_id']];
}

function tracky_agent_changes_v271(PDO $pdo,array $user,array $site,int $limit=8): array
{
    $rows=tracky_cloud_v270_recent_events($pdo,(int)$user['id'],(string)$site['site_id'],max(1,min(20,$limit)));
    $changes=[];
    foreach($rows as $row){
        $event=is_array($row['event']??null)?$row['event']:[];
        $changes[]=[
            'event_id'=>(string)$row['event_id'],'event_type'=>(string)$row['event_type'],
            'severity'=>(string)$row['severity'],'confidence'=>(float)$row['confidence'],
            'occurred_at'=>(string)$row['occurred_at'],'fresh'=>!empty($row['is_fresh']),
            'summary'=>(string)($event['summary']??''),
            'state'=>$event['state']??null,'previous_state'=>$event['previous_state']??null,
            'room_id'=>(string)($event['room_id']??''),
            'subject'=>is_array($event['subject']??null)?$event['subject']:[],
            'object'=>is_array($event['object']??null)?$event['object']:[],
        ];
    }
    return ['kind'=>'changes','changes'=>$changes,'site_id'=>(string)$site['site_id']];
}

function tracky_agent_event_by_id_v271(PDO $pdo,int $userId,string $siteId,string $eventId): ?array
{
    if($eventId==='')return null;
    $q=$pdo->prepare("SELECT event_id,event_type,severity,confidence,privacy_class,occurred_at,event_json
      FROM tracky_cloud_events WHERE user_id=? AND site_id=? AND event_id=? LIMIT 1");
    $q->execute([$userId,$siteId,$eventId]);$row=$q->fetch();if(!$row)return null;
    $event=json_decode((string)$row['event_json'],true);if(!is_array($event))$event=[];
    return [
        'event_id'=>(string)$row['event_id'],'event_type'=>(string)$row['event_type'],
        'severity'=>(string)$row['severity'],'confidence'=>(float)$row['confidence'],
        'privacy_class'=>(string)$row['privacy_class'],'occurred_at'=>(string)$row['occurred_at'],'event'=>$event,
    ];
}

function tracky_agent_explain_v271(PDO $pdo,array $user,array $site,string $query,string $intent): array
{
    $where=tracky_agent_where_v271($pdo,$user,$site,$query);
    $match=is_array($where['matches'][0]??null)?$where['matches'][0]:null;
    if(!$match&&($where['kind']??'')==='current_room'){
        return ['kind'=>$intent,'fact'=>$where,'evidence'=>null,'site_id'=>(string)$site['site_id']];
    }
    if(!$match)return ['kind'=>$intent,'fact'=>null,'evidence'=>null,'site_id'=>(string)$site['site_id']];
    $event=tracky_agent_event_by_id_v271($pdo,(int)$user['id'],(string)$site['site_id'],(string)$match['source_event_id']);
    return ['kind'=>$intent,'fact'=>$match,'evidence'=>$event,'site_id'=>(string)$site['site_id']];
}

function tracky_agent_health_v271(PDO $pdo,array $user): array
{
    $sites=tracky_cloud_v270_sites($pdo,(int)$user['id']);$out=[];
    foreach($sites as $site){
        $out[]=[
            'site_id'=>(string)$site['site_id'],'label'=>(string)($site['label']?:$site['site_id']),
            'status'=>(string)$site['status'],'protocol'=>(string)$site['protocol_version'],
            'last_seen_at'=>$site['last_seen_at']??null,'last_event_at'=>$site['last_event_at']??null,
            'freshness'=>tracky_agent_freshness_v271((string)($site['last_seen_at']??''),$site),
            'health'=>is_array($site['health']??null)?$site['health']:[],
            'capabilities'=>is_array($site['capabilities']??null)?$site['capabilities']:[],
        ];
    }
    return ['kind'=>'health','sites'=>$out];
}

function tracky_agent_current_v271(PDO $pdo,array $user,array $site): array
{
    $projection=vp3_tracky_current_v1($pdo,(int)$user['id'],$site);
    return ['kind'=>'current','site_id'=>(string)$site['site_id'],'site_label'=>(string)($site['label']?:$site['site_id']),'status'=>(string)$site['status'],
        'context'=>['site_id'=>(string)$site['site_id'],'context'=>$projection['context'],'observed_at'=>$projection['freshness']['observed_at']],
        'agent_scene'=>$projection['scene'],'agent_eyes_status'=>$projection['eyes'],'freshness'=>$projection['freshness']];
}

function tracky_agent_query_data_v271(PDO $pdo,array $user,string $query,string $intent=''): array
{
    $intent=$intent!==''?$intent:tracky_agent_intent_v271($query);
    if($intent==='')return ['kind'=>''];
    if($intent==='health')return tracky_agent_health_v271($pdo,$user);
    $site=tracky_agent_site_v271($pdo,(int)$user['id'],$query);
    if(!$site)return ['kind'=>$intent,'unavailable'=>'no_site'];
    return match($intent){
        'where'=>tracky_agent_where_v271($pdo,$user,$site,$query),
        'present'=>tracky_agent_present_v271($pdo,$user,$site,$query),
        'last_seen'=>tracky_agent_last_seen_v271($pdo,$user,$site,$query),
        'changes'=>tracky_agent_changes_v271($pdo,$user,$site,8),
        'confidence','why'=>tracky_agent_explain_v271($pdo,$user,$site,$query,$intent),
        default=>tracky_agent_current_v271($pdo,$user,$site),
    };
}

function tracky_agent_time_label_v271(string $value): string
{
    $value=trim($value);if($value==='')return 'an unknown time';
    try{$dt=new DateTimeImmutable($value,new DateTimeZone('UTC'));return $dt->format('M j, Y g:i:s A').' UTC';}
    catch(Throwable $e){return $value;}
}

function tracky_agent_answer_v271(array $data,string $intent): string
{
    if(isset($data['unavailable']))return 'Tracky is enabled, but no synchronized physical site is available yet.';
    if($intent==='health'){
        $sites=(array)($data['sites']??[]);
        if(!$sites)return 'Tracky is enabled, but no synchronized physical site is available yet.';
        $lines=[];foreach($sites as $site){
            $health=(array)($site['health']??[]);
            $healthText=$health?implode(', ',array_map(static fn($k,$v)=>$k.'='.$v,array_keys($health),array_values($health))):'no detailed health report';
            $fresh=(string)($site['freshness']['state']??'stale');
            $lines[]='• '.(string)$site['label'].' — recorded status '.(string)$site['status'].' · sync '.$fresh.' · '.$healthText.' · last seen '.tracky_agent_time_label_v271((string)($site['last_seen_at']??''));
        }
        return "Tracky physical-awareness status:
".implode("
",$lines);
    }
    if($intent==='current'){
        $ctx=(array)($data['context']['context']??[]);
        $scene=(array)($data['agent_scene']??[]);
        $sceneText=$scene&&function_exists('tracky_scene_text_v1g3d')?tracky_scene_text_v1g3d($scene):'';
        $eyes=(array)($data['agent_eyes_status']??[]);
        $statusText=$eyes?' Agent Eyes: '.$eyes['title'].'. '.$eyes['connection_label'].'. '.$eyes['guidance'].' '.$eyes['consent_note'].' '.($eyes['installed_acceptance']??''):'';
        if(!$ctx)return ($sceneText?:'Tracky has not synchronized a current physical-context snapshot for this site yet.').$statusText;
        $room=(string)($ctx['current_room']??'Unknown');$people=(array)($ctx['people_present']??[]);
        $fresh=(string)($data['freshness']['state']??'stale');
        $answer=($fresh==='current'?'Tracky’s current physical context':'Tracky’s last synchronized physical context').' ('.$fresh.'): room '.$room;
        if($people)$answer.=' · present: '.implode(', ',$people);
        $env=trim((string)($ctx['environment_status']??''));if($env!=='')$answer.=' · environment: '.$env;
        $answer.=' · confidence '.number_format((float)($ctx['confidence']??0)*100,1).'%';
        $observed=(string)($data['context']['observed_at']??$data['context']['updated_at']??'');if($observed!=='')$answer.=' · observed '.tracky_agent_time_label_v271($observed);
        return $answer.'.'.($sceneText!==''?' '.$sceneText:'').$statusText;
    }
    if($intent==='where'){
        if(($data['kind']??'')==='current_room'){
            $fresh=(string)($data['freshness']['state']??'stale');
            $verb=$fresh==='current'?'puts you in':'last recorded you in';
            return 'Tracky '.$verb.' '.$data['location'].' with '.number_format((float)$data['confidence']*100,1).'% confidence ('.$fresh.'), observed '.tracky_agent_time_label_v271((string)$data['as_of']).'.';
        }
        $matches=(array)($data['matches']??[]);
        if(!$matches)return 'Tracky does not currently have a confident location match for that entity.';
        $lines=[];foreach($matches as $m){
            $fresh=(string)($m['freshness']['state']??'stale');
            $qual=((string)$m['temporal_state']==='current'&&$fresh==='current')?'currently':($fresh==='stale'?'last known':(string)$m['temporal_state']);
            $lines[]='• '.(string)$m['entity'].' — '.$qual.' '.str_replace('_',' ',(string)$m['predicate']).' '.(string)$m['location'].' · '.number_format((float)$m['confidence']*100,1).'% · '.$fresh.' · '.tracky_agent_time_label_v271((string)$m['as_of']);
        }
        return "Tracky location state:
".implode("
",$lines);
    }
    if($intent==='present'){
        $people=(array)($data['people']??[]);$room=trim((string)($data['room']??''));
        if(!$people)return $room!==''?'Tracky does not currently report anyone present in '.$room.'.':'Tracky does not currently report a person-presence match.';
        $names=array_values(array_unique(array_map(static fn($p)=>(string)$p['name'],$people)));
        $live=true;$minConfidence=1.0;$latest='';
        foreach($people as $person){
            if(empty($person['freshness']['live']))$live=false;
            $minConfidence=min($minConfidence,(float)($person['confidence']??0));
            if((string)($person['as_of']??'')>$latest)$latest=(string)$person['as_of'];
        }
        $prefix=$live?'Tracky currently reports ':'Tracky’s last recorded presence reports ';
        return $prefix.implode(', ',$names).' present'.($room!==''?' in '.$room:'').' · confidence at least '.number_format($minConfidence*100,1).'%'.($latest!==''?' · '.tracky_agent_time_label_v271($latest):'').'.';
    }
    if($intent==='last_seen'){
        $event=$data['event']??null;if(!$event)return 'Tracky does not have a matching last-seen event in the synchronized history.';
        $subject=is_array($event['subject']??null)?tracky_agent_entity_label_v271((string)($event['subject']['entity_id']??'')):'The matching entity';
        $room=trim((string)($event['room_id']??''));$where=$room!==''?' in '.tracky_agent_entity_label_v271($room):'';
        return $subject.' was last matched by Tracky'.$where.' at '.tracky_agent_time_label_v271((string)$data['occurred_at']).' with '.number_format((float)$data['confidence']*100,1).'% confidence ('.(string)$data['event_type'].').';
    }
    if($intent==='changes'){
        $changes=(array)($data['changes']??[]);if(!$changes)return 'Tracky does not have recent governed physical changes for this site.';
        $lines=[];foreach(array_slice($changes,0,8) as $c){
            $summary=trim((string)($c['summary']??''));if($summary==='')$summary=str_replace(['.','_'],' ',(string)$c['event_type']);
            $room=trim((string)($c['room_id']??''));if($room!=='')$summary.=' · '.tracky_agent_entity_label_v271($room);
            $lines[]='• '.$summary.' · '.number_format((float)$c['confidence']*100,1).'% · '.tracky_agent_time_label_v271((string)$c['occurred_at']);
        }
        return "Recent Tracky physical changes:
".implode("
",$lines);
    }
    if(in_array($intent,['confidence','why'],true)){
        $fact=$data['fact']??null;if(!$fact)return 'Tracky does not currently have enough synchronized evidence to explain that physical fact.';
        if(($fact['kind']??'')==='current_room'){
            $fresh=(string)($fact['freshness']['state']??'stale');
            return 'That room estimate comes from Tracky’s latest governed context snapshot ('.$fresh.'). Confidence is '.number_format((float)$fact['confidence']*100,1).'% and the snapshot is from '.tracky_agent_time_label_v271((string)$fact['as_of']).'. Raw camera evidence stays on the HomeServer.';
        }
        $answer='Tracky’s stored fact is '.(string)$fact['entity'].' '.str_replace('_',' ',(string)$fact['predicate']).' '.(string)$fact['location'].' at '.number_format((float)$fact['confidence']*100,1).'% confidence.';
        $evidence=$data['evidence']??null;
        if(is_array($evidence))$answer.=' It is backed by governed event '.(string)$evidence['event_type'].' from '.tracky_agent_time_label_v271((string)$evidence['occurred_at']).'.';
        else $answer.=' The source event is no longer available in the bounded Cloud projection.';
        if(isset($fact['freshness']))$answer.=' '.vp3_tracky_fresh_text_v1($fact['freshness']).'.';
        return $answer.' Raw perception evidence is not copied into VP3 Cloud.';
    }
    return 'Tracky physical context is available.';
}

function tracky_agent_tools_query_v271(string $query,array $user,int $conversationId=0): array
{
    // The shared adapter keeps stored reads separate from the explicit governed observation path.
    return vp3_tracky_legacy_v1($query,$user,$conversationId);
}

function tracky_agent_tool_catalog_entry_v271(PDO $pdo,array $user): ?array
{
    if(!tracky_agent_enabled_v271($pdo,$user))return null;
    return [
        'key'=>'tracky_physical_context',
        'label'=>'Tracky Physical Awareness',
        'description'=>'Read current room, presence, locations, last-seen events, recent changes, confidence/evidence and Tracky health from governed HomeServer physical context.',
        'kind'=>'physical_context',
        'url'=>url('/tracky.php'),
    ];
}

function tracky_agent_context_relevant_v271(string $query): bool
{
    if(tracky_agent_intent_v271($query)!=='')return true;
    if(tracky_agent_nonphysical_subject_v271($query))return false;
    if((bool)preg_match('/\b(?:office|kitchen|garage|bedroom|living room|environment|around me|near me|in the room|here)\b/i',$query))return true;
    return (bool)preg_match(
        '/\b(?:left|leave|lost|put|placed|dropped|picked|moved|move|near|around|find)\b.{0,80}\b(?:keys?|wallet|phone|glasses|bag|backpack|item|object|car)\b|\b(?:keys?|wallet|phone|glasses|bag|backpack|item|object|car)\b.{0,80}\b(?:left|leave|lost|put|placed|dropped|picked|moved|move|near|around|find)\b/i',
        $query
    );
}


function tracky_agent_fresh_alerts_v271(PDO $pdo,int $userId,int $limit=4): array
{
    if($userId<1||!vp3_tracky_schema_ready_v1($pdo))return [];
    $limit=max(1,min(10,$limit));$now=time();$continuity=vp3_tracky_continuity_v1($pdo,$userId);
    $q=$pdo->prepare("SELECT e.site_id,e.event_id,e.event_type,e.severity,e.confidence,e.privacy_class,e.occurred_at,e.event_json,s.status,s.last_seen_at
      FROM tracky_cloud_events e JOIN tracky_cloud_sites s ON s.user_id=e.user_id AND s.site_id=e.site_id
      WHERE e.user_id=? AND e.is_fresh=1 AND e.severity IN ('urgent','system-health') AND e.occurred_at>=? AND e.occurred_at<=?
      ORDER BY e.occurred_at DESC,e.id DESC LIMIT {$limit}");
    $q->execute([$userId,gmdate('Y-m-d H:i:s',$now-300),gmdate('Y-m-d H:i:s',$now)]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $event=vp3_tracky_event_v1($row,$row,$continuity);
        if($event['freshness']['state']!=='current')continue;
        $out[]=['site_id'=>$row['site_id'],'event_id'=>$event['event_id'],'event_type'=>$event['event_type'],'severity'=>$row['severity'],
            'confidence'=>$event['confidence'],'occurred_at'=>$event['freshness']['observed_at'],'summary'=>$event['summary'],'room_id'=>$event['room_id'],'freshness'=>$event['freshness']];
    }
    return $out;
}

function tracky_agent_context_items_v271(PDO $pdo,array $user,string $namespace,string $query,array $options=[]): array
{
    if(vp3_tracky_nonexecuting_v1($query)!=='')return [];
    if($namespace!=='system'&&!preg_match('/^agent:([1-9][0-9]*)$/D',$namespace,$m))return [];
    $aid=$namespace==='system'?0:(int)$m[1];$principal=['kind'=>$aid?'user_agent':'system','owner_user_id'=>(int)($user['id']??0),'agent_id'=>$aid];
    $context=['conversation_id'=>(int)($options['conversation_id']??0)];
    try{
        $live=vp3_tracky_authority_v1($pdo,$user,$principal,$context);
        if(!tracky_agent_enabled_v271($pdo,$live))return [];
        if(function_exists('tracky_v272_settings')&&empty(tracky_v272_settings($pdo,(int)$live['id'])['chat_enabled']))return [];
        $direct=tracky_agent_context_relevant_v271($query);$continuity=vp3_tracky_continuity_v1($pdo,(int)$live['id']);$alerts=tracky_agent_fresh_alerts_v271($pdo,(int)$live['id'],4);$out=[];$alertSites=[];
        foreach($alerts as $alert){$owned=vp3_tracky_sites_v1($pdo,(int)$live['id'],$alert['site_id']);if(count($owned)!==1)return [];$alertSites[$alert['site_id']]=$owned[0];}
        $site=$direct?tracky_agent_site_v271($pdo,(int)$live['id'],$query):null;
        if($site){
            $read=vp3_tracky_read_v1($pdo,$live,$principal,['operation'=>'tracky_read','ids'=>['tracky.context.read'],'view'=>'current','site_id'=>$site['site_id']],$context);
            if(in_array($read['tool_receipt']['status']??'',['read_complete','no_matches'],true)){
                $current=$read['tracky']['data'];$safe=['site_id'=>$site['site_id'],'site_label'=>$site['label'],'current'=>$current['context'],'freshness'=>$current['freshness'],'scene'=>$current['scene'],'eyes'=>$current['eyes']];
                $json=json_encode($safe,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                if(function_exists('vp3_cognitive_context_item_v2420'))$item=vp3_cognitive_context_item_v2420('physical_context','tracky:physical-context','Stored physical context','Governed stored Tracky observations; use their timestamps and continuity, never infer current presence from missing records: '.$json,97.0,['direct'=>true,'read_only'=>true,'protocol'=>VP3_TRACKY_PROTOCOL_V270,'raw_perception_exposed'=>false]);
                else $item=['source'=>'tracky:physical-context','title'=>'Stored physical context','text'=>$json,'section'=>'physical_context','score'=>97.0];
                if($item)$out[]=$item;
            }
        }
        if($alerts){
            $json=json_encode($alerts,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(function_exists('vp3_cognitive_context_item_v2420')){$item=vp3_cognitive_context_item_v2420('physical_context','tracky:physical-alerts','Recent stored physical alerts','Recent stored Tracky alerts; retain each continuity and observation state: '.$json,99.0,['attention'=>true,'read_only'=>true,'raw_perception_exposed'=>false]);if($item)$out[]=$item;}
        }
        vp3_tracky_authority_v1($pdo,$user,$principal,$context);
        $guardSites=$alertSites;if($site)$guardSites[$site['site_id']]=$site;
        foreach($guardSites as $id=>$before){
            $owned=vp3_tracky_sites_v1($pdo,(int)$live['id'],$id);if(count($owned)!==1)return [];
            foreach(['status','last_seen_at','device_id'] as $key)if(($before[$key]??null)!==($owned[0][$key]??null))return [];
        }
        if(vp3_tracky_continuity_v1($pdo,(int)$live['id'])!==$continuity)return [];
        if(function_exists('tracky_v272_settings')&&empty(tracky_v272_settings($pdo,(int)$live['id'])['chat_enabled']))return [];
        if(($current['scene']['state']??'')==='available'){
            $scene=tracky_scene_read_v1g3d($pdo,(int)$live['id'],$site['site_id']);$keys=array_flip(['state','reason','observed_at','objects','owner_corrections']);
            if(array_intersect_key($current['scene'],$keys)!==array_intersect_key($scene,$keys))return [];
        }
        vp3_tracky_authority_v1($pdo,$user,$principal,$context);
        return array_slice($out,0,3);
    }catch(Throwable){return [];}
}

function tracky_agent_object_exists_v271(PDO $pdo,int $userId,array $ref): bool
{
    $type=(string)($ref['type']??'');$id=(string)($ref['id']??'');
    if($userId<1||$id==='')return false;
    if($type==='physical_site'){
        $q=$pdo->prepare('SELECT 1 FROM tracky_cloud_sites WHERE user_id=? AND site_id=? LIMIT 1');$q->execute([$userId,$id]);return (bool)$q->fetchColumn();
    }
    if($type==='physical_entity'){
        $q=$pdo->prepare('SELECT 1 FROM tracky_cloud_world_state WHERE user_id=? AND (subject_id=? OR object_id=?) LIMIT 1');$q->execute([$userId,$id,$id]);return (bool)$q->fetchColumn();
    }
    if($type==='physical_room'){
        $q=$pdo->prepare("SELECT 1 FROM tracky_cloud_world_state WHERE user_id=? AND (subject_id=? OR object_id=?) LIMIT 1");$q->execute([$userId,$id,$id]);return (bool)$q->fetchColumn();
    }
    if($type==='physical_event'&&ctype_digit($id)){
        $q=$pdo->prepare('SELECT 1 FROM tracky_cloud_events WHERE user_id=? AND id=? LIMIT 1');$q->execute([$userId,(int)$id]);return (bool)$q->fetchColumn();
    }
    return false;
}

function tracky_agent_cognitive_permission_v271(PDO $pdo,array $user,string $agentNamespace,array $ref,string $operation='read'): bool
{
    if($operation!=='read'||!tracky_agent_enabled_v271($pdo,$user))return false;
    return tracky_agent_object_exists_v271($pdo,(int)$user['id'],$ref);
}

function tracky_agent_cognitive_scope_v271(PDO $pdo,array $user,string $namespace,array $options): array
{
    if($namespace!=='system'&&!preg_match('/^agent:([1-9][0-9]*)$/D',$namespace,$m))throw new RuntimeException('Physical context access denied.');
    $aid=$namespace==='system'?0:(int)$m[1];
    $principal=['kind'=>$aid?'user_agent':'system','owner_user_id'=>(int)($user['id']??0),'agent_id'=>$aid];
    $context=['conversation_id'=>(int)($options['conversation_id']??0)];
    return [vp3_tracky_authority_v1($pdo,$user,$principal,$context),$principal,$context];
}

/** Graph consumers drop observation metadata, so authority must be final before their projection. */
function tracky_agent_cognitive_finish_v271(PDO $pdo,array $user,array $principal,array $context,array $sites,array $continuity): void
{
    foreach($sites as $id=>$before){
        $owned=vp3_tracky_sites_v1($pdo,(int)$user['id'],$id);
        if(count($owned)!==1)throw new RuntimeException('Physical context access changed.');
        foreach(['status','last_seen_at','device_id'] as $key)if(($before[$key]??null)!==($owned[0][$key]??null))throw new RuntimeException('Physical observation continuity changed.');
    }
    if(vp3_tracky_continuity_v1($pdo,(int)$user['id'])!==$continuity)throw new RuntimeException('Physical observation continuity changed.');
    vp3_tracky_authority_v1($pdo,$user,$principal,$context);
}

function tracky_agent_cognitive_context_v271(PDO $pdo,array $user,string $agentNamespace,array $ref,array $options=[]): array
{
    [$live,$principal,$context]=tracky_agent_cognitive_scope_v271($pdo,$user,$agentNamespace,$options);
    if(!tracky_agent_cognitive_permission_v271($pdo,$user,$agentNamespace,$ref,'read'))throw new RuntimeException('Physical context access denied.');
    $uid=(int)$live['id'];$type=(string)$ref['type'];$id=(string)$ref['id'];$continuity=vp3_tracky_continuity_v1($pdo,$uid);$sites=[];
    if($type==='physical_site'){
        $owned=vp3_tracky_sites_v1($pdo,$uid,$id);if(count($owned)!==1)throw new RuntimeException('Tracky site unavailable.');$site=$owned[0];
        $q=$pdo->prepare('SELECT capabilities_json FROM tracky_cloud_sites WHERE user_id=? AND site_id=?');$q->execute([$uid,$id]);$caps=$q->fetchColumn();
        $q=$pdo->prepare('SELECT sequence_no,updated_at FROM tracky_cloud_context WHERE user_id=? AND site_id=?');$q->execute([$uid,$id]);$meta=$q->fetch(PDO::FETCH_ASSOC)?:[];
        $health=tracky_cloud_v270_health(vp3_tracky_json_v1($site['health_json']??'[]'));
        $capabilities=tracky_cloud_v270_capabilities($caps===false||$caps===null?[]:vp3_tracky_json_v1($caps));
        // The shared reader performs the final site, consent, continuity and live-principal checks.
        $read=vp3_tracky_read_v1($pdo,$live,$principal,['operation'=>'tracky_read','ids'=>['tracky.context.read'],'view'=>'current','site_id'=>$id],$context);
        if(!in_array($read['tool_receipt']['status']??'',['read_complete','no_matches'],true)||($read['tracky']['site_id']??'')!==$id||!isset($read['tracky']['data']['freshness']))throw new RuntimeException('Tracky site observation unavailable.');
        $current=$read['tracky']['data'];
        return [
            'site'=>['site_id'=>$id,'label'=>(string)$site['label'],'status'=>(string)$site['status'],'protocol'=>(string)$site['protocol_version'],'last_seen_at'=>$site['last_seen_at']??null],
            'current'=>['site_id'=>$id,'sequence'=>(int)($meta['sequence_no']??0),'observed_at'=>$current['freshness']['observed_at'],'updated_at'=>$meta['updated_at']??null,
                'context'=>$current['context'],'agent_scene'=>$current['scene'],'agent_eyes_status'=>$current['eyes'],'freshness'=>$current['freshness']],
            'health'=>$health,'capabilities'=>$capabilities,
            'authority'=>'tracky_cloud_projection','read_only'=>true,'raw_perception_exposed'=>false,
        ];
    }
    if(in_array($type,['physical_entity','physical_room'],true)){
        $entity=$type==='physical_entity';$column=$entity?'subject_id':'object_id';$limit=$entity?40:60;
        $q=$pdo->prepare("SELECT site_id,subject_id,predicate,object_id,value_json,confidence,temporal_state,source_event_id,sequence_no,as_of
          FROM tracky_cloud_world_state WHERE user_id=? AND {$column}=? ORDER BY as_of DESC LIMIT {$limit}");
        $q->execute([$uid,$id]);$rows=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            if(($row[$column]??null)!==$id||tracky_scene_reserved_v1g3d($row))continue;
            $siteId=vp3_tracky_text_v1($row['site_id'],100);
            if(!isset($sites[$siteId])){$owned=vp3_tracky_sites_v1($pdo,$uid,$siteId);if(count($owned)!==1)throw new RuntimeException('Tracky site unavailable.');$sites[$siteId]=$owned[0];}
            foreach(['subject_id'=>128,'predicate'=>80,'object_id'=>128,'source_event_id'=>128] as $key=>$max)$row[$key]=vp3_tracky_text_v1($row[$key],$max);
            if(!in_array($row['temporal_state'],['current','last_seen','historical','inferred','predicted','unknown'],true))throw new RuntimeException('Stored Tracky relation is invalid.');
            $row['confidence']=vp3_tracky_confidence_v1($row['confidence']);
            if($entity)$row['value']=tracky_cloud_v270_relation_value($row['value_json']===null?null:vp3_tracky_json_v1($row['value_json']));
            unset($row['value_json']);
            $row['freshness']=vp3_tracky_observation_v1($row['as_of'],$sites[$siteId],$continuity,$row['temporal_state']);
            $row['observation_note']=vp3_tracky_fresh_text_v1($row['freshness']);
            $rows[]=$row;
        }
        tracky_agent_cognitive_finish_v271($pdo,$user,$principal,$context,$sites,$continuity);
        return [$entity?'entity_id':'room_id'=>$id,'label'=>tracky_agent_entity_label_v271($id),$entity?'relations':'occupants_and_objects'=>$rows,'authority'=>'tracky_cloud_world_state','read_only'=>true];
    }
    if($type==='physical_event'&&ctype_digit($id)&&function_exists('tracky_v272_event_row_by_numeric_id')){
        $row=tracky_v272_event_row_by_numeric_id($pdo,$uid,(int)$id);
        if($row){$owned=vp3_tracky_sites_v1($pdo,$uid,(string)$row['site_id']);if(count($owned)!==1)throw new RuntimeException('Tracky site unavailable.');$sites[$row['site_id']]=$owned[0];$row['freshness']=vp3_tracky_observation_v1($row['occurred_at'],$owned[0],$continuity,'historical');}
        tracky_agent_cognitive_finish_v271($pdo,$user,$principal,$context,$sites,$continuity);
        return $row?['event'=>$row,'authority'=>'tracky_cloud_events','read_only'=>true,'raw_perception_exposed'=>false]:[];
    }
    return [];
}

function tracky_agent_cognitive_relationships_v271(PDO $pdo,array $user,string $agentNamespace,array $ref,array $options=[]): array
{
    try{
    $uid=(int)$user['id'];$type=(string)$ref['type'];$id=(string)$ref['id'];$out=[];
    if($type==='physical_site'){
        [$live,$principal,$context]=tracky_agent_cognitive_scope_v271($pdo,$user,$agentNamespace,$options);
        if(!tracky_agent_cognitive_permission_v271($pdo,$live,$agentNamespace,$ref,'read'))return [];
        $owned=vp3_tracky_sites_v1($pdo,$uid,$id);if(count($owned)!==1)return [];$continuity=vp3_tracky_continuity_v1($pdo,$uid);
        $q=$pdo->prepare("SELECT DISTINCT site_id,object_id FROM tracky_cloud_world_state WHERE user_id=? AND site_id=? AND object_id LIKE 'room:%' LIMIT 24");
        $q->execute([$uid,$id]);
        foreach($q->fetchAll()?:[] as $row){
            $room=(string)$row['object_id'];if($row['site_id']!==$id||$room==='')continue;
            $out[]=['relation'=>'has_location','object_ref'=>['type'=>'physical_room','id'=>$room,'scope'=>'personal'],'provenance'=>'tracky_cloud_world_state','confidence'=>1.0,'confirmation_state'=>'deterministic'];
        }
        tracky_agent_cognitive_finish_v271($pdo,$user,$principal,$context,[$id=>$owned[0]],$continuity);
    }elseif(in_array($type,['physical_entity','physical_room'],true)){
        $data=tracky_agent_cognitive_context_v271($pdo,$user,$agentNamespace,$ref,$options);
        foreach($data[$type==='physical_entity'?'relations':'occupants_and_objects'] as $row){
            $fresh=$row['freshness'];$inferred=$row['temporal_state']==='inferred';
            // The graph contract carries no freshness field. Keep stale/future/unreconciled
            // observations in stored context, never promote them into current location edges.
            if(!$fresh['live']&&!($inferred&&$fresh['state']==='current'&&$fresh['site_contact_current']&&$fresh['continuity']==='current'))continue;
            if($type==='physical_room'){
                $subject=(string)$row['subject_id'];if($subject==='')continue;
                $out[]=['relation'=>'location_of','object_ref'=>['type'=>'physical_entity','id'=>$subject,'scope'=>'personal'],'provenance'=>'tracky_cloud_world_state','confidence'=>$row['confidence'],'confirmation_state'=>$inferred?'model_inferred':'deterministic'];
                continue;
            }
            $object=(string)$row['object_id'];if($object==='')continue;
            $targetType=str_starts_with(strtolower($object),'room:')?'physical_room':'physical_entity';
            $predicate=(string)$row['predicate'];
            $relation=$targetType==='physical_room'&&in_array($predicate,['located_in','located_on','present_in','moving_between'],true)?'has_location':$predicate;
            $out[]=['relation'=>$relation,'object_ref'=>['type'=>$targetType,'id'=>$object,'scope'=>'personal'],'provenance'=>'tracky_cloud_world_state','confidence'=>$row['confidence'],'confirmation_state'=>$inferred?'model_inferred':'deterministic'];
        }
    }
    return array_slice($out,0,$type==='physical_room'?30:24);
    }catch(Throwable){return [];}
}

function tracky_agent_domain_contract_v271(): array
{
    return [
        'id'=>'physical_context',
        'label'=>'Tracky Physical Context',
        'phase'=>'tracky-v2.71',
        'implementation_status'=>'integrated-v2.71',
        'plugin_key'=>'tracky',
        'plugin_catalog_registered'=>true,
        'authority'=>['tracky_cloud_sites','tracky_cloud_events','tracky_cloud_world_state','tracky_cloud_context'],
        'planned_authority'=>[],
        'objects'=>function_exists('tracky_v272_card')
            ?['physical_site','physical_entity','physical_room','physical_event']
            :['physical_site','physical_entity','physical_room'],
        'related_objects'=>[],
        'events'=>['physical_context.changed','physical_context.health_changed','physical_context.alert'],
        'event_classes'=>[
            'physical_context.changed'=>'informational',
            'physical_context.health_changed'=>'failure_recovery',
            'physical_context.alert'=>'actionable',
        ],
        'source_aliases'=>['physical_context','tracky'],
        'current_state_domain'=>'physical_context',
        'presentation_firewall_required'=>true,
        'event_ingress'=>'agent_event_inbox_v1920',
        'attention_policy'=>'cognitive_attention_v2410',
        'current_state'=>'tracky_cloud_context_v270_plus_cognitive_current_state_v2590',
        'presentation'=>'cognitive_presentation_firewall_v2590',
        'notes'=>[
            'Raw frames, video, audio, embeddings and local perception evidence remain HomeServer-only.',
            'Tracky Agent tools are read-only and create no physical device-control authority.',
            'Physical events enter canonical cognitive ingress as compact governed summaries only.',
        ],
    ];
}

function tracky_agent_register_cognitive_v271(): void
{
    if(!function_exists('vp3_cognitive_register_module_v500'))return;
    $registry=vp3_cognitive_registry_storage_v500();
    if(isset($registry['modules']['physical_context']))return;
    $physicalObjects=['physical_site','physical_entity','physical_room'];
    $physicalCards=[];
    if(function_exists('tracky_v272_card')){$physicalObjects[]='physical_event';$physicalCards['physical_event']='tracky_v272_card';}
    vp3_cognitive_register_module_v500([
        'module'=>'physical_context',
        'version'=>function_exists('tracky_v272_card')?'tracky-v2.72':'tracky-v2.71',
        'objects'=>$physicalObjects,
        'events'=>['physical_context.changed','physical_context.health_changed','physical_context.alert'],
        'permission_resolver'=>'tracky_agent_cognitive_permission_v271',
        'context_provider'=>'tracky_agent_cognitive_context_v271',
        'relationship_provider'=>'tracky_agent_cognitive_relationships_v271',
        'cards'=>$physicalCards,
        'tools'=>[
            'tracky.current_context'=>['label'=>'Read current physical context','kind'=>'read','risk'=>'low','requires_approval'=>false],
            'tracky.where_is'=>['label'=>'Find an entity in current physical state','kind'=>'read','risk'=>'low','requires_approval'=>false],
            'tracky.who_is_present'=>['label'=>'Read room presence','kind'=>'read','risk'=>'low','requires_approval'=>false],
            'tracky.last_seen'=>['label'=>'Read last-seen physical event','kind'=>'read','risk'=>'low','requires_approval'=>false],
            'tracky.what_changed'=>['label'=>'Read recent physical changes','kind'=>'read','risk'=>'low','requires_approval'=>false],
            'tracky.confidence'=>['label'=>'Read physical-state confidence','kind'=>'read','risk'=>'low','requires_approval'=>false],
            'tracky.why'=>['label'=>'Explain governed physical evidence','kind'=>'read','risk'=>'low','requires_approval'=>false],
            'tracky.health'=>['label'=>'Read Tracky site health','kind'=>'read','risk'=>'low','requires_approval'=>false],
        ],
        'freshness_policy'=>['current_seconds'=>30,'event_seconds'=>300,'stale_behavior'=>'label_and_continue'],
        'sensitivity_policy'=>['owner_scoped'=>true,'raw_perception_exposed'=>false,'read_only'=>true],
        'surfaces'=>['brief','away_digest','notification','voice_announce','ask_user','chat_response'],
        'voice_safe'=>true,
    ]);
}

function tracky_agent_cognitive_event_type_v271(array $event): string
{
    $severity=(string)($event['severity']??'informational');$type=(string)($event['event_type']??'');
    if(in_array($severity,['urgent','actionable'],true)||str_starts_with($type,'safety.'))return 'physical_context.alert';
    if(str_starts_with($type,'camera.')||str_starts_with($type,'tracky.')||str_contains($type,'health'))return 'physical_context.health_changed';
    return 'physical_context.changed';
}

function tracky_agent_on_sync_v271(PDO $pdo,int $userId,string $siteId,array $events): void
{
    if($userId<1||$siteId===''||!function_exists('vp3_cognitive_domain_ingest_v2600'))return;
    foreach(array_slice($events,0,20) as $event){
        if(!is_array($event))continue;
        $severity=(string)($event['severity']??'informational');$original=(string)($event['event_type']??'');
        if($severity==='informational'&&!str_starts_with($original,'safety.')&&!str_starts_with($original,'camera.')&&!str_starts_with($original,'tracky.'))continue;
        $refs=[['type'=>'physical_site','id'=>$siteId,'scope'=>'personal']];
        foreach(['subject','object'] as $key){
            $entity=is_array($event[$key]??null)?$event[$key]:[];
            $id=trim((string)($entity['entity_id']??''));if($id!=='')$refs[]=['type'=>'physical_entity','id'=>$id,'scope'=>'personal'];
        }
        $room=trim((string)($event['room_id']??''));if($room!=='')$refs[]=['type'=>'physical_room','id'=>$room,'scope'=>'personal'];
        $payload=[
            'tracky_event_id'=>(string)($event['event_id']??''),
            'tracky_event_type'=>$original,
            'site_id'=>$siteId,
            'sequence'=>max(0,(int)($event['sequence']??0)),
            'severity'=>$severity,
            'confidence'=>(float)($event['confidence']??0),
            'privacy_class'=>(string)($event['privacy_class']??''),
            'room_id'=>$room,
            'summary'=>(string)($event['summary']??''),
            'state'=>$event['state']??null,
            'previous_state'=>$event['previous_state']??null,
        ];
        vp3_cognitive_domain_ingest_v2600(
            $pdo,$userId,'physical_context',tracky_agent_cognitive_event_type_v271($event),$refs,$payload,
            ['external_event_id'=>'tracky:'.$siteId.':'.(string)($event['event_id']??''),'occurred_at'=>(string)($event['occurred_at']??gmdate('c'))]
        );
    }
}

require_once __DIR__.'/tracky-proactive-v272.php';
