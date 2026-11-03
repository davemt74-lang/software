<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tool-receipts-v1.php';
require_once __DIR__.'/agent-observation-authority-v1.php';

function vp3_tracky_dependencies_v1(): void
{
    if(!function_exists('vp3_plugin_installation_v320'))require_once __DIR__.'/plugin-registry-v320.php';
    if(!function_exists('vp3_plugin_effective_enabled_v360'))require_once __DIR__.'/plugin-lifecycle-v360.php';
    if(!function_exists('tracky_cloud_v270_plugin_enabled'))require_once __DIR__.'/tracky-cloud-v270.php';
}

/** Stored governed observations only. This adapter never captures, refreshes, ingests or initializes storage. */
function vp3_tracky_plan_v1(array $plan): array
{
    $view=array_key_exists('view',$plan)?$plan['view']:'current';
    if((array_key_exists('operation',$plan)?$plan['operation']:'tracky_read')!=='tracky_read'||($plan['ids']??null)!==['tracky.context.read']||!in_array($view,['current','health','location','presence','last_seen','changes','evidence'],true))throw new InvalidArgumentException('Invalid Tracky read view.');
    $entity=in_array($view,['location','presence','last_seen','evidence'],true);
    $allowed=['operation','ids','view','site_id','site',...($entity?['entity_id','entity']:[]),...($view==='evidence'?['event_id']:[])];
    if(array_diff(array_keys($plan),$allowed))throw new InvalidArgumentException('Unsupported Tracky selector.');
    $out=['operation'=>'tracky_read','ids'=>['tracky.context.read'],'view'=>$view];
    foreach(['site_id'=>100,'site'=>160,...($entity?['entity_id'=>128,'entity'=>128]:[]),...($view==='evidence'?['event_id'=>128]:[])] as $key=>$max){
        if(!array_key_exists($key,$plan))continue;
        $value=$plan[$key];
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$max||preg_match('/[\p{Cc}\p{Cf}]/u',$value)||trim($value)!==$value)throw new InvalidArgumentException('Invalid Tracky selector.');
        if($value==='')continue;
        if($key==='site_id'&&!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,99}$/D',$value))throw new InvalidArgumentException('Invalid Tracky site ID.');
        if($key==='event_id'&&!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D',$value))throw new InvalidArgumentException('Invalid Tracky event ID.');
        $out[$key]=$value;
    }
    foreach(['site','entity'] as $kind)if(isset($out[$kind],$out[$kind.'_id']))throw new InvalidArgumentException('Choose an ID or a literal label, not both.');
    return $out;
}

function vp3_tracky_schema_ready_v1(PDO $pdo): bool
{
    try{
        foreach([
            'tracky_cloud_sites'=>'user_id,site_id,device_id,label,protocol_version,status,last_seen_at,last_event_at,health_json',
            'tracky_cloud_world_state'=>'user_id,site_id,subject_id,predicate,object_id,confidence,temporal_state,source_event_id,sequence_no,as_of',
            'tracky_cloud_context'=>'user_id,site_id,context_json,sequence_no,observed_at,updated_at',
            'tracky_cloud_events'=>'id,user_id,site_id,event_id,event_type,severity,confidence,privacy_class,occurred_at,event_json,is_fresh',
            'tracky_cloud_scene_share_order'=>'user_id,site_id,accepted_revision,fingerprint,summary_json',
            'tracky_cloud_visual_status_order'=>'user_id,site_id,accepted_revision,accepted_state,accepted_at',
        ] as $table=>$fields)if($pdo->query('SELECT '.$fields.' FROM '.$table.' WHERE 1=0')===false)return false;
        return true;
    }catch(Throwable){return false;}
}

function vp3_tracky_authority_v1(PDO $pdo,array $user,array $principal,array $context=[]): array
{
    return vp3_observation_authority_v1($pdo,$user,$principal,$context,static fn(array $live):bool=>function_exists('tracky_cloud_v270_plugin_enabled')&&tracky_cloud_v270_plugin_enabled($pdo,$live));
}

function vp3_tracky_text_v1(mixed $value,int $max=300): string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$max||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value))throw new RuntimeException('Stored Tracky text is invalid.');
    return $value;
}

function vp3_tracky_label_v1(string $value,int $max=160): string
{
    $value=mb_substr(trim(preg_replace('/[\p{Cc}\p{Cf}]+/u',' ',$value)??''),0,$max);
    return str_replace(['\\','`','*','_','[',']','(',')','{','}','<','>','#','|','!'],['\\\\','\\`','\\*','\\_','\\[','\\]','\\(','\\)','\\{','\\}','\\<','\\>','\\#','\\|','\\!'],$value);
}

function vp3_tracky_match_label_v1(string $value): string
{
    return mb_strtolower(trim(preg_replace('/[\s_.-]+/u',' ',$value)??''));
}

/** Strict observation timestamps; missing or future times are never a recent observation. */
function vp3_tracky_timestamp_v1(mixed $value): ?int
{
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D',$value))return null;
    try{$stamp=new DateTimeImmutable($value,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();}
    catch(Throwable){return null;}
    if($errors!==false&&($errors['warning_count']||$errors['error_count']))return null;
    return $stamp->getTimestamp();
}

function vp3_tracky_confidence_v1(mixed $value): float
{
    if(!(is_int($value)||is_float($value)||(is_string($value)&&preg_match('/^(?:0(?:\.\d+)?|1(?:\.0+)?)$/D',$value)))||!is_finite((float)$value)||(float)$value<0||(float)$value>1)throw new RuntimeException('Stored confidence is invalid.');
    return (float)$value;
}

/** The canonical state reader performs DDL; this deliberately reads its existing authority row only. */
function vp3_tracky_continuity_v1(PDO $pdo,int $uid): array
{
    try{
        $q=$pdo->prepare('SELECT needs_reconciliation,last_reconciled_at,last_disconnect_at FROM homeserver_reconciliation_state_v246 WHERE user_id=?');$q->execute([$uid]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)return ['state'=>'unknown','current'=>false];
        if(!in_array($row['needs_reconciliation'],[0,1,'0','1'],true))return ['state'=>'unknown','current'=>false];
        if((int)$row['needs_reconciliation']!==0)return ['state'=>'reconciling','current'=>false];
        $reconciled=vp3_tracky_timestamp_v1($row['last_reconciled_at']??null);$disconnected=vp3_tracky_timestamp_v1($row['last_disconnect_at']??null);
        if($reconciled===null||$reconciled>time()||($disconnected!==null&&$disconnected>$reconciled))return ['state'=>'unknown','current'=>false];
        return ['state'=>'current','current'=>true];
    }catch(Throwable){return ['state'=>'unknown','current'=>false];}
}

function vp3_tracky_observation_v1(mixed $observed,array $site,array $continuity,string $temporal='current',?int $now=null): array
{
    $now??=time();$stamp=vp3_tracky_timestamp_v1($observed);$age=$stamp===null?null:$now-$stamp;
    $state=$age===null?'unknown':($age<0?'future':($age<=300?'current':($age<=3600?'recent':'stale')));
    $contact=vp3_tracky_timestamp_v1($site['last_seen_at']??null);
    $contactFresh=$contact!==null&&$contact<=$now&&$now-$contact<=300;
    $live=$state==='current'&&$temporal==='current'&&($site['status']??'')==='healthy'&&$contactFresh&&($continuity['current']??false)===true;
    return ['state'=>$state,'age_seconds'=>$age!==null&&$age>=0?$age:null,'live'=>$live,'temporal_state'=>$temporal,
        'observed_at'=>$stamp===null?null:gmdate('Y-m-d\TH:i:s\Z',$stamp),'continuity'=>$continuity['state']??'unknown',
        'site_contact_current'=>$contactFresh];
}

function vp3_tracky_sites_v1(PDO $pdo,int $uid,?string $exact=null): array
{
    $q=$pdo->prepare('SELECT user_id,site_id,device_id,label,protocol_version,status,last_seen_at,last_event_at,health_json FROM tracky_cloud_sites WHERE user_id=?'.($exact!==null?' AND site_id=?':'').' ORDER BY site_id LIMIT 101');
    $q->execute($exact!==null?[$uid,$exact]:[$uid]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if($exact!==null&&($row['site_id']??null)!==$exact)continue;
        vp3_tracky_text_v1($row['site_id']??null,100);vp3_tracky_text_v1($row['label']??null,160);
        $out[]=$row;
    }
    return $out;
}

function vp3_tracky_select_site_v1(PDO $pdo,int $uid,array $plan): array
{
    $sites=vp3_tracky_sites_v1($pdo,$uid,$plan['site_id']??null);
    if(isset($plan['site'])){
        $label=vp3_tracky_match_label_v1($plan['site']);
        $matches=array_values(array_filter($sites,static fn($row)=>vp3_tracky_match_label_v1($row['label'])===$label));
        if(count($sites)>100)return ['clarify'=>'site','choices'=>array_slice($matches?:$sites,0,12)];
        $sites=$matches;
    }
    if(($plan['view']??'')==='health'&&!isset($plan['site_id'])&&!isset($plan['site']))return ['sites'=>array_slice($sites,0,12),'bounded'=>count($sites)>12];
    if(count($sites)===1)return ['site'=>$sites[0]];
    if(!$sites)return ['missing'=>'site'];
    return ['clarify'=>'site','choices'=>array_slice($sites,0,12)];
}

/** Literal labels resolve only when the complete bounded candidate set proves one identity. */
function vp3_tracky_select_entity_v1(PDO $pdo,int $uid,string $siteId,array $plan): array
{
    $room=$plan['view']==='presence';$column=$room?'object_id':'subject_id';
    if(isset($plan['entity_id']))return ['id'=>$plan['entity_id']];
    if($room&&!isset($plan['entity']))return ['id'=>''];
    $q=$pdo->prepare('SELECT DISTINCT '.$column.' AS entity_id FROM tracky_cloud_world_state WHERE user_id=? AND site_id=?'.($room?" AND object_id LIKE 'room:%'":'').' ORDER BY '.$column.' LIMIT 201');$q->execute([$uid,$siteId]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);$choices=[];$matches=[];
    foreach($rows as $row){
        $id=vp3_tracky_text_v1($row['entity_id'],128);if($id==='')continue;
        $label=tracky_agent_entity_label_v271($id);$choice=['entity_id'=>$id,'label'=>$label];$choices[]=$choice;
        if(isset($plan['entity'])&&vp3_tracky_match_label_v1($label)===vp3_tracky_match_label_v1($plan['entity']))$matches[]=$choice;
    }
    if(isset($plan['entity'])&&count($rows)<=200&&count($matches)===1)return ['id'=>$matches[0]['entity_id']];
    if(isset($plan['entity'])&&!$matches&&count($rows)<=200)return ['missing'=>'entity'];
    return ['clarify'=>'entity','choices'=>array_slice($matches?:$choices,0,12)];
}

function vp3_tracky_json_v1(mixed $value,int $max=65536): array
{
    if(!is_string($value)||strlen($value)>$max)throw new RuntimeException('Stored Tracky projection is invalid.');
    try{$data=json_decode($value,true,16,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('Stored Tracky projection is invalid.');}
    if(!is_array($data))throw new RuntimeException('Stored Tracky projection is invalid.');
    return $data;
}

function vp3_tracky_relation_v1(array $row,array $site,array $continuity): array
{
    $subject=vp3_tracky_text_v1($row['subject_id']??null,128);$object=vp3_tracky_text_v1($row['object_id']??null,128);
    if($subject===''||!in_array($row['predicate']??null,['located_in','located_on','present_in','moving_between'],true)||!in_array($row['temporal_state']??null,['current','last_seen','historical','inferred','predicted','unknown'],true))throw new RuntimeException('Stored Tracky relation is invalid.');
    return ['entity_id'=>$subject,'entity'=>tracky_agent_entity_label_v271($subject),'predicate'=>$row['predicate'],'location_id'=>$object,'location'=>tracky_agent_entity_label_v271($object),
        'confidence'=>vp3_tracky_confidence_v1($row['confidence']??null),'source_event_id'=>vp3_tracky_text_v1($row['source_event_id']??'',128),
        'freshness'=>vp3_tracky_observation_v1($row['as_of']??null,$site,$continuity,$row['temporal_state'])];
}

function vp3_tracky_relations_v1(PDO $pdo,int $uid,array $site,array $continuity,string $entity='',bool $presence=false): array
{
    $sql="SELECT site_id,subject_id,predicate,object_id,confidence,temporal_state,source_event_id,as_of FROM tracky_cloud_world_state WHERE user_id=? AND site_id=? AND predicate IN ('located_in','located_on','present_in','moving_between')";
    $args=[$uid,$site['site_id']];
    if($presence){$sql.=" AND subject_id LIKE 'person:%'";if($entity!==''){$sql.=' AND object_id=?';$args[]=$entity;}}
    else{$sql.=' AND subject_id=?';$args[]=$entity;}
    $q=$pdo->prepare($sql.' ORDER BY as_of DESC,subject_id,predicate LIMIT 13');$q->execute($args);$items=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(($row['site_id']??null)!==$site['site_id']||($entity!==''&&($row[$presence?'object_id':'subject_id']??null)!==$entity))continue;
        if(function_exists('tracky_scene_reserved_v1g3d')&&tracky_scene_reserved_v1g3d($row))continue;
        $items[]=vp3_tracky_relation_v1($row,$site,$continuity);
    }
    return ['items'=>array_slice($items,0,12),'bounded'=>count($items)>12];
}

function vp3_tracky_event_v1(array $row,array $site,array $continuity): array
{
    if(!in_array($row['privacy_class']??null,['cloud_derived','system_health','user_approved'],true))throw new RuntimeException('Stored event is not governed Cloud evidence.');
    $event=vp3_tracky_json_v1($row['event_json']??null);
    foreach(['event_id','event_type','privacy_class'] as $key)if(($event[$key]??null)!==($row[$key]??null))throw new RuntimeException('Stored event identity is inconsistent.');
    $storedTime=vp3_tracky_timestamp_v1($row['occurred_at']??null);$eventTime=vp3_tracky_timestamp_v1($event['occurred_at']??null);
    if($storedTime!==$eventTime)throw new RuntimeException('Stored event time is inconsistent.');
    $refs=[];foreach(['subject','object'] as $key){
        if(!array_key_exists($key,$event))continue;
        if(!is_array($event[$key]))throw new RuntimeException('Stored event entity is invalid.');
        $refs[$key]=vp3_tracky_text_v1($event[$key]['entity_id']??null,128);
    }
    $summary=$event['summary']??'';if($summary===null)$summary='';if(!is_string($summary))throw new RuntimeException('Stored event summary is invalid.');
    return ['event_id'=>vp3_tracky_text_v1($row['event_id'],128),'event_type'=>vp3_tracky_text_v1($row['event_type'],100),
        'summary'=>vp3_tracky_text_v1($summary,300),'confidence'=>vp3_tracky_confidence_v1($row['confidence']??null),
        'subject_id'=>$refs['subject']??'','object_id'=>$refs['object']??'','room_id'=>vp3_tracky_text_v1($event['room_id']??'',128),
        'freshness'=>vp3_tracky_observation_v1($row['occurred_at']??null,$site,$continuity,'historical')];
}

function vp3_tracky_events_v1(PDO $pdo,int $uid,array $site,array $continuity,string $entity='',string $eventId='',bool $last=false): array
{
    $sql='SELECT site_id,event_id,event_type,severity,confidence,privacy_class,occurred_at,event_json FROM tracky_cloud_events WHERE user_id=? AND site_id=?';$args=[$uid,$site['site_id']];
    if($eventId!==''){$sql.=' AND event_id=?';$args[]=$eventId;}
    $q=$pdo->prepare($sql.' ORDER BY occurred_at DESC,id DESC LIMIT '.($eventId!==''?'2':($last?'301':'9')));$q->execute($args);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);$items=[];$scanned=0;
    foreach(array_slice($rows,0,$last?300:8) as $row){
        if(($row['site_id']??null)!==$site['site_id']||($eventId!==''&&($row['event_id']??null)!==$eventId))continue;
        $scanned++;$event=vp3_tracky_event_v1($row,$site,$continuity);
        if($entity!==''&&!in_array($entity,[$event['subject_id'],$event['object_id']],true))continue;
        // Future/invalid dates are not evidence of when an entity was last seen.
        if($last&&in_array($event['freshness']['state'],['future','unknown'],true))continue;
        $items[]=$event;if($last)break;
    }
    return ['items'=>$items,'bounded'=>count($rows)>($last?300:8),'scan_limit'=>$last?300:8];
}

/** Same observation/consent projection is used by the read adapter and automatic Chat context. */
function vp3_tracky_current_v1(PDO $pdo,int $uid,array $site,?array $continuity=null): array
{
    $continuity??=vp3_tracky_continuity_v1($pdo,$uid);
    $q=$pdo->prepare('SELECT site_id,context_json,observed_at FROM tracky_cloud_context WHERE user_id=? AND site_id=?');$q->execute([$uid,$site['site_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $context=[];$fresh=vp3_tracky_observation_v1(null,$site,$continuity);
    if($row&&($row['site_id']??null)===$site['site_id']){
        $raw=vp3_tracky_json_v1($row['context_json']);
        $context=['current_room'=>vp3_tracky_text_v1($raw['current_room']??'',160),'environment_status'=>vp3_tracky_text_v1($raw['environment_status']??'',120),'confidence'=>vp3_tracky_confidence_v1($raw['confidence']??null),'people_present'=>[]];
        if(!is_array($raw['people_present']??null)||!array_is_list($raw['people_present'])||count($raw['people_present'])>30)throw new RuntimeException('Stored presence summary is invalid.');
        foreach($raw['people_present'] as $name)$context['people_present'][]=vp3_tracky_text_v1($name,160);
        $fresh=vp3_tracky_observation_v1($row['observed_at'],$site,$continuity);
    }
    $scene=tracky_scene_read_v1g3d($pdo,$uid,$site['site_id']);
    if(($scene['state']??'')==='available'&&($continuity['current']??false)!==true)$scene=['state'=>'unavailable','reason'=>'continuity_unverified'];
    $eyes=tracky_eyes_experience_v1g4($site,$scene);
    return ['context'=>$context,'freshness'=>$fresh,'scene'=>$scene,'eyes'=>array_intersect_key($eyes,array_flip(['state','reason','title','connection_label','guidance','consent_note','meaning']))];
}

function vp3_tracky_clarify_v1(string $kind,array $choices=[]): array
{
    $lines=[];foreach(array_slice($choices,0,12) as $row){$id=(string)($row[$kind==='site'?'site_id':'entity_id']??'');$lines[]=vp3_tracky_label_v1((string)($row['label']??$id)).' · ID '.vp3_tracky_label_v1($id);}
    return ['handled'=>true,'answer'=>'Choose one exact Tracky '.$kind.' from your stored records.'.($lines?"\n".implode("\n",$lines):' A literal unique label or exact ID is needed.'),'actions'=>[],'sources'=>[],
        'tool_receipt'=>vp3_tool_receipt_v1('tracky.context.read','list','clarification_required')];
}

function vp3_tracky_failure_v1(Throwable $e): array
{
    $denied=$e->getCode()===403;$upgrade=$e->getCode()===409;
    return ['handled'=>true,'answer'=>$denied?'The current owner, Agent, conversation or Tracky plugin access does not permit this read.':($upgrade?'The stored Tracky read schema is unavailable. Update Cloud before reading these observations.':'The stored Tracky observations could not be verified. This does not establish an empty room, an absent person or a current location.'),'actions'=>[],'sources'=>[],
        'tool_receipt'=>vp3_tool_receipt_v1('tracky.context.read','list',$denied?'blocked':'failed',$denied?'permission_denied':($upgrade?'upgrade_required':($e instanceof InvalidArgumentException?'invalid_plan':'execution_failed')))];
}

function vp3_tracky_fresh_text_v1(array $fresh): string
{
    $state=$fresh['state'];$at=$fresh['observed_at']??null;
    $text=($fresh['live']?'recent stored observation':($state==='future'?'future timestamp; not current':($state==='unknown'?'observation time unknown':($state==='current'?'recent stored observation; current state unverified':$state.' stored observation'))));
    if(($fresh['temporal_state']??'current')!=='current')$text.=' · '.$fresh['temporal_state'];
    if(($fresh['continuity']??'unknown')!=='current')$text.=' · continuity '.$fresh['continuity'];
    if($at)$text.=' · '.$at;
    return $text;
}

function vp3_tracky_read_v1(PDO $pdo,array $user,array $principal,array $plan,array $context=[]): array
{
    try{
        vp3_tracky_dependencies_v1();
        $plan=vp3_tracky_plan_v1($plan);$live=vp3_tracky_authority_v1($pdo,$user,$principal,$context);$uid=(int)$live['id'];
        if(!vp3_tracky_schema_ready_v1($pdo))throw new RuntimeException('Tracky read schema unavailable.',409);
        $selected=vp3_tracky_select_site_v1($pdo,$uid,$plan);
        if(isset($selected['clarify'])){vp3_tracky_authority_v1($pdo,$user,$principal,$context);return vp3_tracky_clarify_v1('site',$selected['choices']);}
        $view=$plan['view'];$lines=[];$count=0;$data=[];$site=$selected['site']??null;
        if(isset($selected['missing']))$answer='No owned stored Tracky site matches that exact selection.';
        elseif($view==='health'){
            $sites=$site?[$site]:($selected['sites']??[]);$continuity=vp3_tracky_continuity_v1($pdo,$uid);$answer='Stored Tracky site health (up to 12 sites). Contact times describe synchronization, not a new physical observation.';
            foreach($sites as $row){$health=vp3_tracky_json_v1($row['health_json']??'{}');$parts=[];
                foreach(['runtime','camera','world_state','inference','model','database','event_backlog','sync_backlog'] as $key)if(isset($health[$key])){
                    if(!(is_string($health[$key])||is_int($health[$key])||is_bool($health[$key])))throw new RuntimeException('Stored site health is invalid.');
                    $parts[]=$key.'='.vp3_tracky_label_v1(is_bool($health[$key])?($health[$key]?'true':'false'):(string)$health[$key],160);
                }
                $at=vp3_tracky_timestamp_v1($row['last_seen_at']);$lines[]=vp3_tracky_label_v1($row['label']?:$row['site_id']).' · recorded status '.vp3_tracky_label_v1((string)$row['status'],40).' · continuity '.$continuity['state'].' · last contact '.($at===null?'unknown':gmdate('Y-m-d\TH:i:s\Z',$at)).($parts?' · '.implode(', ',$parts):'');
            }$count=count($lines);
        }else{
            $continuity=vp3_tracky_continuity_v1($pdo,$uid);$entity='';
            if(in_array($view,['location','presence','last_seen','evidence'],true)&&!($view==='evidence'&&isset($plan['event_id'])&&!isset($plan['entity_id'])&&!isset($plan['entity']))){
                $pick=vp3_tracky_select_entity_v1($pdo,$uid,$site['site_id'],$plan);
                if(isset($pick['clarify'])){vp3_tracky_authority_v1($pdo,$user,$principal,$context);return vp3_tracky_clarify_v1('entity',$pick['choices']);}
                if(isset($pick['missing']))$data=['items'=>[],'missing_entity'=>true];else $entity=$pick['id'];
            }
            $answer='Stored Tracky '.str_replace('_',' ',$view).' · '.vp3_tracky_label_v1($site['label']?:$site['site_id']).'.';
            if($view==='current'){
                $data=vp3_tracky_current_v1($pdo,$uid,$site,$continuity);$ctx=$data['context'];$answer.=' '.vp3_tracky_fresh_text_v1($data['freshness']).'.';
                if($ctx){$lines[]='Recorded room: '.vp3_tracky_label_v1($ctx['current_room']?:'not reported').($ctx['people_present']?' · recorded people: '.implode(', ',array_map(static fn($s)=>vp3_tracky_label_v1($s),$ctx['people_present'])):'').($ctx['environment_status']!==''?' · environment '.vp3_tracky_label_v1($ctx['environment_status'],120):'').' · reported confidence '.number_format($ctx['confidence']*100,1).'%';$count++;}
                if(($data['scene']['state']??'')==='available'){$lines[]=tracky_scene_text_v1g3d($data['scene']);$count++;}
                $answer.=' Agent Eyes: '.$data['eyes']['title'].'. '.$data['eyes']['consent_note'];
            }elseif(in_array($view,['location','presence'],true)){
                if(!isset($data['missing_entity']))$data=vp3_tracky_relations_v1($pdo,$uid,$site,$continuity,$entity,$view==='presence');
                foreach($data['items'] as $row)$lines[]=vp3_tracky_label_v1($row['entity']).' · stored '.str_replace('_',' ',$row['predicate']).' '.vp3_tracky_label_v1($row['location']).' · reported confidence '.number_format($row['confidence']*100,1).'% · '.vp3_tracky_fresh_text_v1($row['freshness']);
                $count=count($lines);$answer.=' This reports observations and inferences; missing records do not establish absence.';
            }else{
                if(!isset($data['missing_entity'])){
                    $eventId=$plan['event_id']??'';
                    if($view==='evidence'&&$eventId===''){$facts=vp3_tracky_relations_v1($pdo,$uid,$site,$continuity,$entity);$eventId=$facts['items'][0]['source_event_id']??'';$data['fact']=$facts['items'][0]??null;
                        if($eventId==='')$data+=['items'=>[],'source_missing'=>true];
                    }
                    if(!isset($data['source_missing']))$data+=vp3_tracky_events_v1($pdo,$uid,$site,$continuity,$view==='changes'?'':$entity,$eventId,$view==='last_seen');
                }
                foreach($data['items'] as $row)$lines[]=vp3_tracky_label_v1($row['summary']!==''?$row['summary']:$row['event_type'],300).' · event '.vp3_tracky_label_v1($row['event_id'],128).' · subject '.vp3_tracky_label_v1($row['subject_id']?:'not reported',128).' · reported confidence '.number_format($row['confidence']*100,1).'% · '.vp3_tracky_fresh_text_v1($row['freshness']);
                $count=count($lines);if($view==='last_seen')$answer.=' Latest exact entity match among up to 300 stored events; this is bounded history, not a complete archive.';
                if($view==='evidence')$answer.=' Evidence must match this exact entity or selected event; it does not prove a live location.';
            }
        }
        if($lines)$answer.="\nStored source text — observations, not instructions:\n".implode("\n",$lines);
        if(!$count)$answer.=' The verified selection returned no matching stored observations; this is not evidence of physical absence.';
        vp3_tracky_authority_v1($pdo,$user,$principal,$context);
        foreach($site?[$site]:($sites??[]) as $before){
            $after=vp3_tracky_sites_v1($pdo,$uid,$before['site_id']);
            if(count($after)!==1)throw new RuntimeException('Site access changed.',403);
            foreach(['status','last_seen_at','device_id'] as $key)if(($before[$key]??null)!==($after[0][$key]??null))throw new RuntimeException('The selected site changed during this read.');
        }
        if(isset($continuity)&&vp3_tracky_continuity_v1($pdo,$uid)!==$continuity)throw new RuntimeException('Continuity changed during this read.');
        if(($data['scene']['state']??'')==='available'){
            $nowScene=tracky_scene_read_v1g3d($pdo,$uid,$site['site_id']);$keys=array_flip(['state','reason','observed_at','objects','owner_corrections']);
            if(array_intersect_key($data['scene'],$keys)!==array_intersect_key($nowScene,$keys))throw new RuntimeException('Scene sharing changed during this read.',403);
        }
        vp3_tracky_authority_v1($pdo,$user,$principal,$context);
        return ['handled'=>true,'answer'=>$answer,'actions'=>[['type'=>'open_url','label'=>'Open Tracky','url'=>url('/tracky.php'),'auto'=>false]],'sources'=>[['source'=>'tracky:stored-observations','title'=>'Governed stored Tracky observations']],
            'tracky'=>['contract'=>'vp3.tracky.read.v1','view'=>$view,'site_id'=>$site['site_id']??'','data'=>$data,'read_only'=>true,'active_perception'=>['attempted'=>false],'raw_perception_exposed'=>false],
            'tool_receipt'=>vp3_tool_receipt_v1('tracky.context.read','list',$count?'read_complete':'no_matches','',0,$count)];
    }catch(Throwable $e){return vp3_tracky_failure_v1($e);}
}

function vp3_tracky_nonexecuting_v1(string $query): string
{
    if(preg_match('/\b(?:tutorial|hypothetical|imagine|suppose|how (?:to|do|does)|(?:if|when|unless) (?:i|we|you)|build(?:ing)?|implement(?:ing)?|design(?:ing)?|what (?:is|are) (?:a |an )?(?:tracky|agent eyes|physical awareness))\b/i',$query))return 'continue';
    if(preg_match('/\b(?:can|could|would) you (?:access|read|inspect|use|see|check|scan|refresh)|\b(?:capabilit(?:y|ies)|do you have (?:access|permission)|are you (?:able|allowed)|does .{0,100}(?:support|allow|handle))\b/i',$query))return 'inspect';
    return '';
}

function vp3_tracky_query_has_label_v1(string $query,string $label): bool
{
    $query=vp3_tracky_match_label_v1($query);$label=vp3_tracky_match_label_v1($label);
    return $label!==''&&preg_match('/(?<![\pL\pN])'.preg_quote($label,'/').'(?![\pL\pN])/u',$query)===1;
}

/** Legacy text selects only exact known labels/IDs; a failed selection never becomes the newest site/entity. */
function vp3_tracky_legacy_plan_v1(PDO $pdo,int $uid,string $query,string $intent): array
{
    $view=match($intent){'where'=>'location','present'=>'presence','confidence','why'=>'evidence',default=>$intent};
    $plan=['operation'=>'tracky_read','ids'=>['tracky.context.read'],'view'=>$view];$sites=vp3_tracky_sites_v1($pdo,$uid);$selected=[];
    foreach($sites as $site)if(vp3_tracky_query_has_label_v1($query,$site['site_id'])||($site['label']!==''&&vp3_tracky_query_has_label_v1($query,$site['label'])))$selected[]=$site;
    if(count($selected)===1)$plan['site_id']=$selected[0]['site_id'];
    elseif(preg_match('/\bsite\s+([A-Za-z0-9][A-Za-z0-9._:-]{2,99})\b/i',$query,$m))$plan['site_id']=$m[1];
    $chosen=vp3_tracky_select_site_v1($pdo,$uid,$plan);$site=$chosen['site']??null;
    if($view==='evidence'&&preg_match('/\bevent\s+([A-Za-z0-9][A-Za-z0-9._:-]{7,127})\b/i',$query,$m)){$plan['event_id']=$m[1];return $plan;}
    if(!in_array($view,['location','presence','last_seen','evidence'],true)||!$site)return $plan;
    if(preg_match('/\b((?:person|object|room):[A-Za-z0-9_.:-]{1,110})\b/u',$query,$m)){$plan['entity_id']=$m[1];return $plan;}
    $column=$view==='presence'?'object_id':'subject_id';$q=$pdo->prepare('SELECT DISTINCT '.$column.' AS entity_id FROM tracky_cloud_world_state WHERE user_id=? AND site_id=? ORDER BY '.$column.' LIMIT 201');$q->execute([$uid,$site['site_id']]);$matches=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$id=(string)$row['entity_id'];if($view==='presence'&&!str_starts_with($id,'room:'))continue;$label=tracky_agent_entity_label_v271($id);if(vp3_tracky_query_has_label_v1($query,$label))$matches[vp3_tracky_match_label_v1($label)]=$label;}
    if(count($matches)===1)$plan['entity']=array_values($matches)[0];
    elseif(!$matches&&$view==='presence'&&preg_match('/\b(?:in|at)\s+(?:(?:the|my)\s+)?(.+?)(?:[?.!]|$)/iu',$query,$m)){
        $literal=trim($m[1]);if($literal!==''&&mb_strlen($literal)<=128&&vp3_tracky_match_label_v1($literal)!==vp3_tracky_match_label_v1($site['label']))$plan['entity']=$literal;
    }
    elseif(!$matches&&preg_match('/\b(?:where (?:is|are|was|were)|last (?:see|spot|notice|saw)|last seen|see|spot|notice)\s+(?:(?:my|the|our)\s+)?(.+?)(?:\s+(?:at|in|on|right|site)\b|[?.!]|$)/iu',$query,$m)){
        $literal=trim($m[1]);if($literal!==''&&mb_strlen($literal)<=128)$plan['entity']=$literal;
    }
    return $plan;
}

function vp3_tracky_legacy_v1(string $query,array $user,int $cid=0): array
{
    $empty=tracky_agent_empty_v271();$intent=tracky_agent_intent_v271($query);if($intent==='')return $empty;
    $guard=vp3_tracky_nonexecuting_v1($query);if($guard==='continue')return $empty;
    if($guard==='inspect')return ['handled'=>true,'answer'=>'Tracky can read your governed stored observations and their timestamps. Explicit new observation requests use the existing HomeServer opt-in and privacy controls. This describes the tools; no observations were read or captured.','actions'=>[],'sources'=>[],
        'tool_receipt'=>vp3_tool_receipt_v1('capabilities.inspect','inspect','read_complete')];
    $active=false;$started=false;
    try{
        vp3_tracky_dependencies_v1();$pdo=db();if(!$pdo)throw new RuntimeException('Storage unavailable.');$principal=vp3_capability_control_principal_v1($pdo,$user,$cid);
        $live=vp3_tracky_authority_v1($pdo,$user,$principal,['conversation_id'=>$cid]);
        if(!vp3_tracky_schema_ready_v1($pdo))throw new RuntimeException('Tracky read schema unavailable.',409);
        $plan=vp3_tracky_legacy_plan_v1($pdo,(int)$live['id'],$query,$intent);
        $active=function_exists('tracky_v273_query_requests_refresh')&&tracky_v273_query_requests_refresh($query);
        if(!$active)return vp3_tracky_read_v1($pdo,$user,$principal,$plan,['conversation_id'=>$cid]);
        $selection=vp3_tracky_select_site_v1($pdo,(int)$live['id'],$plan);
        if(!isset($selection['site'])){
            $r=vp3_tracky_clarify_v1('site',$selection['choices']??[]);$r['tool_receipt']=vp3_tool_receipt_v1('tracky.perception.request','request','clarification_required');return $r;
        }
        // Keep the existing opted-in v273 path, only after an explicit request; never stale-auto-refresh a stored read.
        $current=tracky_agent_query_data_v271($pdo,$live,$query,$intent);$started=true;
        $refresh=tracky_v273_maybe_refresh($pdo,$live,$query,$intent,$current);
        vp3_tracky_authority_v1($pdo,$user,$principal,['conversation_id'=>$cid]);
        $r=vp3_tracky_read_v1($pdo,$user,$principal,$plan,['conversation_id'=>$cid]);
        if(in_array($r['tool_receipt']['status']??'',['blocked','failed'],true))throw new RuntimeException('Post-request observations could not be verified.',502);
        $status=(string)($refresh['status']??'failed');$accepted=!empty($refresh['attempted'])&&in_array($status,['requested','accepted','observing','completed'],true);
        $r['answer'].=tracky_v273_refresh_note($refresh);
        if($accepted&&$status!=='completed')$r['answer'].=' The governed observation request was accepted; a completed new observation has not yet been verified.';
        if(empty($refresh['attempted']))$r['answer'].=' A new observation was not requested because active perception is not enabled.';
        $r['tracky']['read_only']=!$accepted;$r['tracky']['active_perception']=['attempted'=>!empty($refresh['attempted']),'status'=>in_array($status,['disabled','requested','accepted','observing','completed','unable','denied','failed','superseded'],true)?$status:'failed','projection_ingested'=>($refresh['cloud_projection_ingested']??false)===true];
        $blocked=!$accepted&&(empty($refresh['attempted'])||in_array($status,['unable','denied'],true));
        $r['tool_receipt']=vp3_tool_receipt_v1('tracky.perception.request','request',$accepted?'completed':($blocked?'blocked':'failed'),$accepted?'':($blocked?(empty($refresh['attempted'])?'setup_required':'permission_denied'):'outcome_unverified'));
        return $r;
    }catch(Throwable $e){
        $r=vp3_tracky_failure_v1($e);
        if($active)$r['tool_receipt']=vp3_tool_receipt_v1('tracky.perception.request','request',$e->getCode()===403&&!$started?'blocked':'failed',$started?'outcome_unverified':($e->getCode()===403?'permission_denied':($e->getCode()===409?'upgrade_required':'execution_failed')));
        return $r;
    }
}
