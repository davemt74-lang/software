<?php
declare(strict_types=1);

/** Current browser observations, never an identity, instruction or durable memory grant. */
const VP3_LIVE_CONTEXT_V1='vp3.live-context.v1';
const VP3_LIVE_CONTEXT_TTL_MS_V1=90000;
const VP3_LIVE_CONTEXT_CHAT_INPUT_TTL_MS_V1=30000;

function vp3_live_context_uuid_v1(mixed $value): bool
{
    return is_string($value)&&preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$value)===1;
}

function vp3_live_context_time_v1(mixed $value): ?int
{
    if(!is_string($value)||preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{3}))?Z\z/D',$value,$match)!==1)return null;
    $time=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',$match[1],new DateTimeZone('UTC'));
    if(!$time||$time->format('Y-m-d\TH:i:s')!==$match[1])return null;
    return $time->getTimestamp()*1000+(int)($match[2]??0);
}

function vp3_live_context_fresh_v1(mixed $value,int $now): bool
{
    return is_int($value)&&$value<=$now+5000&&$value>=$now-VP3_LIVE_CONTEXT_TTL_MS_V1;
}

/** Character and escaped-JSON byte limits both apply, including to multibyte observations. */
function vp3_live_context_text_v1(mixed $value,int $characters,int $bytes): string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))return '';
    $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',' ',$value)??'';
    $value=trim(mb_substr($value,0,$characters));
    if(strlen(json_encode($value,JSON_THROW_ON_ERROR))<=$bytes)return $value;
    $low=0;$high=mb_strlen($value);
    while($low<$high){$mid=(int)ceil(($low+$high)/2);
        if(strlen(json_encode(mb_substr($value,0,$mid),JSON_THROW_ON_ERROR))<=$bytes)$low=$mid;else $high=$mid-1;}
    return trim(mb_substr($value,0,$low));
}

/** Each source needs its own currently enabled context control; an active stream alone is insufficient. */
function vp3_live_context_screen_v1(array $raw,int $owner,int $now): ?array
{
    if(($raw['active']??null)!==true||($raw['context_allowed']??null)!==true
        ||!is_string($raw['source_id']??null)||preg_match('/\Ashare-[1-3]\z/D',$raw['source_id'])!==1
        ||!vp3_live_context_uuid_v1($raw['session_id']??null)||!is_bool($raw['selected']??null)
        ||!in_array($raw['surface']??null,['browser','window','monitor'],true)
        ||($raw['historical_frame']??null)===true
        ||(isset($raw['contract'])&&$raw['contract']!=='vp3.screen-observation.v1')
        ||(isset($raw['owner_key'])&&$raw['owner_key']!==(string)$owner)
        ||!vp3_live_context_fresh_v1(vp3_live_context_time_v1($raw['captured_at']??null),$now))return null;
    $safe=['source_id'=>$raw['source_id'],'session_id'=>$raw['session_id'],'selected'=>$raw['selected'],
        'active'=>true,'context_allowed'=>true,'contract'=>'vp3.screen-observation.v1','owner_key'=>(string)$owner,
        'captured_at'=>$raw['captured_at'],'surface'=>$raw['surface'],'ephemeral'=>true,'inference_only'=>true,
        'authentication_authority'=>false,'instructions_authority'=>false];
    foreach(['summary'=>[1600,2000],'visible_text'=>[2000,2400],'site_hint'=>[200,350],'page_title'=>[300,500],
        'on_screen_translation'=>[1000,1100]] as $key=>[$characters,$bytes]){
        $safe[$key]=vp3_live_context_text_v1($raw[$key]??'',$characters,$bytes);
    }
    $safe['observations']=[];
    foreach(array_slice(is_array($raw['observations']??null)?$raw['observations']:[],0,8) as $row){
        if(!is_array($row)||!in_array($row['kind']??null,['music','video','article','product','error','project','other'],true))continue;
        $label=vp3_live_context_text_v1($row['label']??'',200,250);$detail=vp3_live_context_text_v1($row['detail']??'',500,450);
        if($label===''&&$detail==='')continue;
        $item=['kind'=>$row['kind'],'label'=>$label,'detail'=>$detail,'inference_only'=>true];
        if(in_array($row['confidence']??null,['low','medium','high'],true))$item['confidence']=$row['confidence'];
        $safe['observations'][]=$item;
    }
    $audio=$raw['tab_audio']??null;
    if(is_array($audio)&&vp3_live_context_uuid_v1($audio['capture_id']??null)
        &&($audio['session_id']??null)===$raw['session_id']
        &&vp3_live_context_fresh_v1(vp3_live_context_time_v1($audio['captured_at']??null),$now)){
        $text=vp3_live_context_text_v1($audio['text']??'',2000,2400);$events=[];
        $duration=$audio['duration_seconds']??null;
        $validDuration=(is_int($duration)||is_float($duration))&&is_finite((float)$duration)&&$duration>=.5&&$duration<=35;
        foreach(array_slice(is_array($audio['audio_events']??null)?$audio['audio_events']:[],0,8) as $event){
            if(!is_array($event)||($event['provider']??null)!=='elevenlabs'||($event['model']??null)!=='scribe_v2'
                ||($event['source']??null)!=='shared_tab_audio'||($event['classification']??null)!=='provider_label')continue;
            $start=$event['start_seconds']??null;$end=$event['end_seconds']??null;
            if((!is_int($start)&&!is_float($start))||(!is_int($end)&&!is_float($end))
                ||!is_finite((float)$start)||!is_finite((float)$end)||$start<0||$end<$start||$end>35
                ||($validDuration&&$end>$duration))continue;
            $label=vp3_live_context_text_v1($event['label']??'',80,150);if($label==='')continue;
            $events[]=['label'=>$label,'start_seconds'=>$start,'end_seconds'=>$end,'provider'=>'elevenlabs',
                'model'=>'scribe_v2','source'=>'shared_tab_audio','classification'=>'provider_label','advisory'=>true];
        }
        if($text!==''||$events){
            $safe['tab_audio']=['text'=>$text,'captured_at'=>$audio['captured_at'],'capture_id'=>$audio['capture_id'],
                'session_id'=>$audio['session_id'],'source'=>'shared_tab_audio',
                'instructions_authority'=>false,'audio_events'=>$events];
            if($validDuration){$safe['tab_audio']['duration_seconds']=$duration;
                $frame=vp3_live_context_time_v1($raw['captured_at']);$at=vp3_live_context_time_v1($audio['captured_at']);
                $safe['tab_audio']['frame_within_segment']=$frame>=$at&&$frame<$at+(int)round($duration*1000);}
        }
    }
    $hasContent=$safe['summary']!==''||$safe['visible_text']!==''||$safe['page_title']!==''||$safe['site_hint']!==''
        ||$safe['on_screen_translation']!==''||$safe['observations']||isset($safe['tab_audio']);
    return $hasContent?$safe:null;
}

function vp3_live_context_eyes_v1(array $raw,int $now): ?array
{
    if(($raw['active']??null)!==true||($raw['context_allowed']??null)!==true
        ||!vp3_live_context_fresh_v1(vp3_live_context_time_v1($raw['observed_at']??null),$now))return null;
    foreach(['participant_count','known_count','unknown_count'] as $field){
        if(!is_int($raw[$field]??null)||$raw[$field]<0||$raw[$field]>8)return null;
    }
    if($raw['known_count']+$raw['unknown_count']!==$raw['participant_count'])return null;
    $safe=['active'=>true,'context_allowed'=>true,'observed_at'=>$raw['observed_at'],
        'participant_count'=>$raw['participant_count'],'known_count'=>$raw['known_count'],'unknown_count'=>$raw['unknown_count'],
        'identity_provisional'=>true,'authentication_authority'=>false,'instructions_authority'=>false,
        'greetings_allowed'=>($raw['greetings_allowed']??null)===true];
    if($safe['participant_count']>0&&is_bool($raw['recent_chat_input']??null)){
        $inputAt=vp3_live_context_time_v1($raw['chat_input_observed_at']??null);
        $safe['recent_chat_input']=$raw['recent_chat_input']&&vp3_live_context_fresh_v1($inputAt,$now)
            &&$now-$inputAt<VP3_LIVE_CONTEXT_CHAT_INPUT_TTL_MS_V1;
        if($safe['recent_chat_input'])$safe['chat_input_observed_at']=$raw['chat_input_observed_at'];
    }
    if(in_array($raw['last_event']??null,['arrival','departure'],true)
        &&vp3_live_context_fresh_v1(vp3_live_context_time_v1($raw['last_event_at']??null),$now)){
        $safe['last_event']=$raw['last_event'];$safe['last_event_at']=$raw['last_event_at'];
    }
    if(($raw['object_detection_active']??null)===true){
        $labels=[];foreach(array_slice(is_array($raw['object_labels']??null)?$raw['object_labels']:[],0,8) as $label){
            $label=vp3_live_context_text_v1($label,64,150);if($label!=='')$labels[$label]=true;
        }
        $safe['object_detection_active']=true;$safe['object_labels']=array_keys($labels);sort($safe['object_labels'],SORT_STRING);
    }
    return $safe;
}

function vp3_live_context_quiet_bucket_v1(int $duration): string
{
    return match(true){$duration===0=>'none',$duration<5000=>'brief',$duration<30000=>'sustained',
        $duration<120000=>'long',default=>'extended'};
}

function vp3_live_context_microphone_v1(array $raw,int $now): ?array
{
    if(($raw['active']??null)!==true||($raw['context_allowed']??null)!==true
        ||!vp3_live_context_fresh_v1(vp3_live_context_time_v1($raw['observed_at']??null),$now)
        ||!in_array($raw['activity']??null,['energy','quiet','uncertain'],true)
        ||!is_int($raw['quiet_duration_ms']??null)||$raw['quiet_duration_ms']<0||$raw['quiet_duration_ms']>300000
        ||!is_int($raw['observed_window_ms']??null)||$raw['observed_window_ms']<0||$raw['observed_window_ms']>30000)return null;
    $quiet=$raw['activity']==='quiet'?$raw['quiet_duration_ms']:0;
    return ['active'=>true,'context_allowed'=>true,'observed_at'=>$raw['observed_at'],'activity'=>$raw['activity'],
        'quiet_duration_ms'=>$quiet,'observed_window_ms'=>$raw['observed_window_ms'],
        'quiet_duration_bucket'=>vp3_live_context_quiet_bucket_v1($quiet),'unclassified'=>true,
        'authentication_authority'=>false,'instructions_authority'=>false];
}

/** Pure allowlist. The server binding and receipt time must come from the authenticated caller. */
function vp3_live_context_sanitize_v1(array $raw,array $binding,?int $nowMs=null): ?array
{
    $nowMs??=(int)floor(microtime(true)*1000);
    if(($raw['contract']??null)!==VP3_LIVE_CONTEXT_V1||!vp3_live_context_uuid_v1($raw['session_id']??null)
        ||($binding['visible']??null)!==true||($binding['focused']??null)!==true
        ||!is_string($binding['activity_tab']??null)||preg_match('/\A[a-f0-9]{32}\z/D',$binding['activity_tab'])!==1
        ||($raw['activity_tab']??null)!==$binding['activity_tab']
        ||!vp3_live_context_fresh_v1(vp3_live_context_time_v1($raw['observed_at']??null),$nowMs))return null;
    foreach(['owner_user_id'=>1,'agent_id'=>0,'conversation_id'=>1] as $field=>$minimum){
        if(!is_int($binding[$field]??null)||$binding[$field]<$minimum||($raw[$field]??null)!==$binding[$field])return null;
    }
    $screens=[];$seen=[];
    if(is_array($raw['screens']??null)&&array_is_list($raw['screens'])&&count($raw['screens'])<=3){
        foreach($raw['screens'] as $entry){
            if(!is_array($entry))continue;
            $screen=vp3_live_context_screen_v1($entry,$binding['owner_user_id'],$nowMs);if(!$screen)continue;
            if(isset($seen[$screen['source_id']]))return null;
            $seen[$screen['source_id']]=true;$screens[]=$screen;
        }
    }
    usort($screens,static fn(array $a,array $b):int=>strcmp($a['source_id'],$b['source_id']));
    $eyes=is_array($raw['eyes']??null)?vp3_live_context_eyes_v1($raw['eyes'],$nowMs):null;
    $microphone=is_array($raw['microphone']??null)?vp3_live_context_microphone_v1($raw['microphone'],$nowMs):null;
    if(!$screens&&$eyes===null&&$microphone===null)return null;
    return ['contract'=>VP3_LIVE_CONTEXT_V1,'session_id'=>$raw['session_id'],'owner_user_id'=>$binding['owner_user_id'],
        'agent_id'=>$binding['agent_id'],'conversation_id'=>$binding['conversation_id'],'activity_tab'=>$binding['activity_tab'],
        'observed_at'=>$raw['observed_at'],'received_at_ms'=>$nowMs,'screens'=>$screens,'eyes'=>$eyes,'microphone'=>$microphone,
        'ephemeral'=>true,'authentication_authority'=>false,'instructions_authority'=>false];
}

/** Only semantic values enter source revisions and model records. Clocks still gate each actual read. */
function vp3_live_context_semantic_v1(array $packet): array
{
    $safe=array_intersect_key($packet,array_flip(['contract','screens','eyes','microphone','ephemeral','authentication_authority','instructions_authority']));
    foreach($safe['screens'] as &$screen){unset($screen['captured_at'],$screen['session_id'],$screen['owner_key'],$screen['active'],$screen['context_allowed']);
        if(isset($screen['tab_audio'])){
            unset($screen['tab_audio']['captured_at'],$screen['tab_audio']['frame_within_segment'],$screen['tab_audio']['capture_id'],
                $screen['tab_audio']['session_id'],$screen['tab_audio']['duration_seconds']);
            foreach($screen['tab_audio']['audio_events'] as &$event)unset($event['start_seconds'],$event['end_seconds']);unset($event);
        }
    }unset($screen);
    if(is_array($safe['eyes']))unset($safe['eyes']['observed_at'],$safe['eyes']['last_event_at'],$safe['eyes']['chat_input_observed_at'],
        $safe['eyes']['active'],$safe['eyes']['context_allowed']);
    if(is_array($safe['microphone']))unset($safe['microphone']['observed_at'],$safe['microphone']['quiet_duration_ms'],
        $safe['microphone']['observed_window_ms'],$safe['microphone']['active'],$safe['microphone']['context_allowed']);
    return $safe;
}

function vp3_live_context_fingerprint_v1(array $semantic): string
{
    return hash('sha256',json_encode($semantic,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

/** Session generations remain proof authority, outside the observational content shown to a model. */
function vp3_live_context_revision_v1(array $packet): string
{
    $binding=array_intersect_key($packet,array_flip(['owner_user_id','agent_id','conversation_id','activity_tab','session_id']));
    $shares=[];foreach($packet['screens'] as $screen)$shares[$screen['source_id']]=$screen['session_id'];
    $semantic=vp3_live_context_semantic_v1($packet);
    // Chat input is current engagement context. Opening the work view or
    // letting the input signal expire does not replace the camera source.
    unset($semantic['eyes']['recent_chat_input']);
    return vp3_live_context_fingerprint_v1(['binding'=>$binding,'shares'=>$shares,'semantic'=>$semantic]);
}

/** Reuse the canonical owner/Agent/conversation boundary; reported presence never supplies a principal. */
function vp3_live_context_scope_v1(PDO $pdo,array $user,array $principal,int $cid): array
{
    if(!function_exists('vp3_agent_chat_resolve_agent_v380'))require_once __DIR__.'/agent-chat-boundary-v380.php';
    if(!function_exists('user_agent_principal_v236'))require_once __DIR__.'/user-agent-system-v236.php';
    $uid=(int)($user['id']??0);$kind=$principal['kind']??null;$aid=$principal['agent_id']??null;
    if($uid<1||$cid<1||!in_array($kind,['system','user_agent'],true)||!is_int($aid)||$aid<0
        ||($kind==='system'&&$aid!==0)||($kind==='user_agent'&&$aid<1)
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)){
        throw new RuntimeException('Current live context is unavailable in this principal.',403);
    }
    $q=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$q->execute([$uid]);$live=$q->fetch(PDO::FETCH_ASSOC);
    if(!$live||empty($live['is_active'])||!has_permission('account.access',$live)||!has_permission('chat.access',$live)){
        throw new RuntimeException('Current live context account access changed.',403);
    }
    try{$agent=vp3_agent_chat_resolve_agent_v380($pdo,$live,$aid);}
    catch(PDOException $error){throw $error;}
    catch(RuntimeException $error){throw new RuntimeException('Current live context Agent access changed.',403,$error);}
    $canonical=vp3_agent_chat_principal_v380($live,$agent);
    foreach(['kind','agent_id','owner_user_id','viewer_user_id'] as $field){
        if(($principal[$field]??null)!==($canonical[$field]??null))throw new RuntimeException('Current live context principal changed.',403);
    }
    if(!vp3_agent_chat_conversation_v380($pdo,$cid,$uid,$agent))throw new RuntimeException('Current live context conversation changed.',403);
    return ['user'=>$live,'principal'=>$canonical,'agent_id'=>$aid,'conversation_id'=>$cid];
}

/** Validate stored operational freshness without renewing the receipt of an older observation. */
function vp3_live_context_row_v1(array $row,int $owner,int $agent,int $cid,?string $tab,int $now): ?array
{
    if(($row['surface']??null)!=='chat'||($row['context_key']??null)!=='chat:'.$cid
        ||!in_array($row['activity_state']??null,['working','paused','idle'],true))return null;
    if(!is_string($row['last_heartbeat_at']??null)||preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D',$row['last_heartbeat_at'])!==1)return null;
    $heartbeat=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$row['last_heartbeat_at'],new DateTimeZone('UTC'));
    if(!$heartbeat||$heartbeat->format('Y-m-d H:i:s')!==($row['last_heartbeat_at']??null)
        ||!vp3_live_context_fresh_v1($heartbeat->getTimestamp()*1000,$now))return null;
    try{$details=json_decode((string)($row['details_json']??''),true,32,JSON_THROW_ON_ERROR);}
    catch(Throwable $error){throw new RuntimeException('Current live context storage is unavailable.',503,$error);}
    if(!is_array($details)||($details['conversation_id']??null)!==$cid||!is_array($details['live_context']??null)
        ||($tab!==null&&($details['activity_tab']??null)!==$tab))return null;
    $raw=$details['live_context'];$received=$raw['received_at_ms']??null;
    if(!vp3_live_context_fresh_v1($received,$now))return null;
    $packet=vp3_live_context_sanitize_v1($raw,['owner_user_id'=>$owner,'agent_id'=>$agent,'conversation_id'=>$cid,
        'activity_tab'=>$details['activity_tab']??null,'visible'=>$details['visible']??null,'focused'=>$details['focused']??null],$now);
    if($packet===null)return null;
    $packet['received_at_ms']=$received;
    return $packet;
}

/** No mutation, history scan, model call, media read, or personal Knowledge lookup. */
function vp3_live_context_snapshot_v1(PDO $pdo,array $user,array $principal,int $cid,?string $tab=null,?DateTimeImmutable $now=null): ?array
{
    $scope=vp3_live_context_scope_v1($pdo,$user,$principal,$cid);
    if($tab!==null&&preg_match('/\A[a-f0-9]{32}\z/D',$tab)!==1)throw new InvalidArgumentException('Invalid live context tab.');
    $clock=$now===null?(int)floor(microtime(true)*1000):(int)$now->format('U')*1000+(int)$now->format('v');
    $q=$pdo->prepare('SELECT * FROM agent_activity_state WHERE user_id=? LIMIT 1');$q->execute([(int)$scope['user']['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $packet=vp3_live_context_row_v1($row,(int)$scope['user']['id'],$scope['agent_id'],$cid,$tab,$clock);if(!$packet)return null;
    $semantic=vp3_live_context_semantic_v1($packet);
    return ['ref'=>'live-context:'.$packet['session_id'],'revision'=>vp3_live_context_revision_v1($packet),
        'semantic'=>$semantic,'freshness'=>['received_at_ms'=>$packet['received_at_ms'],'observed_at'=>$packet['observed_at'],
            'checked_at_ms'=>$clock,'ttl_ms'=>VP3_LIVE_CONTEXT_TTL_MS_V1]];
}

/** Scrub only the current ephemeral packet. Owner-lock/CAS never commits a caller's transaction. */
function vp3_live_context_expire_v1(PDO $pdo,array $user,?DateTimeImmutable $now=null): bool
{
    $uid=(int)($user['id']??0);if($uid<1)return false;
    $clock=$now===null?(int)floor(microtime(true)*1000):(int)$now->format('U')*1000+(int)$now->format('v');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE'));
        $q->execute([$uid]);$ownerExists=$q->fetchColumn()!==false;
        $q=$pdo->prepare('SELECT * FROM agent_activity_state WHERE user_id=? LIMIT 1');$q->execute([$uid]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row){if($owns)$pdo->commit();return false;}
        $malformed=false;
        try{$details=json_decode((string)$row['details_json'],true,32,JSON_THROW_ON_ERROR);}
        catch(Throwable $error){$details=[];$malformed=true;}
        if(!is_array($details)){$details=[];$malformed=true;}
        if(!$malformed&&!array_key_exists('live_context',$details)){if($owns)$pdo->commit();return false;}
        $raw=$details['live_context']??null;$agent=is_array($raw)&&is_int($raw['agent_id']??null)?$raw['agent_id']:-1;
        $cid=$details['conversation_id']??null;$packet=null;
        if($ownerExists&&is_int($cid)&&$cid>0&&$agent>=0){
            try{$packet=vp3_live_context_row_v1($row,$uid,$agent,$cid,null,$clock);}catch(Throwable $ignored){}
        }
        if($packet===null)unset($details['live_context']);else $details['live_context']=$packet;
        $encoded=$details===[]?'{}':json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($encoded===(string)$row['details_json']){if($owns)$pdo->commit();return false;}
        $q=$pdo->prepare('UPDATE agent_activity_state SET details_json=? WHERE user_id=? AND details_json=?');
        $q->execute([$encoded,$uid,(string)$row['details_json']]);$changed=$q->rowCount()===1;
        if($owns)$pdo->commit();return $changed;
    }catch(Throwable $error){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
}

/** Existing worker cleanup also covers crashed clients and inactive/paused owners. */
function vp3_live_context_expire_all_v1(PDO $pdo,int $limit=100): int
{
    $limit=max(1,min(100,$limit));
    try{
        $q=$pdo->query("SELECT user_id FROM agent_activity_state WHERE details_json LIKE '%\"live_context\"%' ORDER BY last_heartbeat_at ASC,user_id ASC LIMIT ".$limit);
        $owners=$q->fetchAll(PDO::FETCH_COLUMN);$changed=0;
        foreach($owners as $owner){$owner=(int)$owner;if($owner>0&&vp3_live_context_expire_v1($pdo,['id'=>$owner]))++$changed;}
        return $changed;
    }catch(Throwable $error){throw new RuntimeException('Current live context cleanup is unavailable.',503);}
}
