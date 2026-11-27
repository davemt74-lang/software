<?php
declare(strict_types=1);

require_once __DIR__.'/profile-analytics-period-ref-v1.php';
require_once __DIR__.'/profile-analytics-authority-v1.php';
require_once __DIR__.'/profile-agent-boundary-v390.php';
require_once __DIR__.'/profile-image-analytics-v1.php';

function vp3_profile_analytics_json_v1(mixed $value): ?array
{
    if(!is_string($value)||strlen($value)>262144)return null;
    try{$data=json_decode($value,true,64,JSON_THROW_ON_ERROR);return is_array($data)?$data:null;}catch(JsonException){return null;}
}
function vp3_profile_analytics_hash_v1(mixed $value): string
{
    return hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function vp3_profile_analytics_receipt_id_v1(mixed $value): ?int
{
    if(is_int($value))return $value>0?$value:null;
    if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,18}\z/D',$value))return null;
    $id=(int)$value;return $id>0&&(string)$id===$value?$id:null;
}
function vp3_profile_analytics_epoch_v1(mixed $value): ?int
{
    if((!is_int($value)&&!is_string($value))||!preg_match('/\A[1-9][0-9]{0,13}\z/D',(string)$value))return null;
    $n=(int)$value;return $n>=946684800000&&$n<4133980800000?$n:null;
}
/** Only call for a field whose canonical writer explicitly writes UTC. */
function vp3_profile_analytics_utc_v1(mixed $value): ?int
{
    if(!is_string($value)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',$value))return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return $date&&$date->format('Y-m-d H:i:s')===$value?vp3_profile_analytics_epoch_v1($date->getTimestamp()*1000):null;
}
function vp3_profile_analytics_table_v1(PDO $pdo,string $table): bool
{
    $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?'SELECT 1 FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        :"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?";
    return (bool)vp3_profile_analytics_rows_v1($pdo,$sql,[$table]);
}
function vp3_profile_analytics_tables_v1(PDO $pdo,array $tables): bool
{
    foreach($tables as $table)if(!vp3_profile_analytics_table_v1($pdo,$table))return false;return true;
}
function vp3_profile_analytics_window_key_v1(?int $stamp,array $window): ?string
{
    if($stamp===null)return null;
    if($stamp>=$window['from_utc_ms']&&$stamp<$window['to_utc_ms'])return 'current';
    if($stamp>=$window['previous']['from_utc_ms']&&$stamp<$window['previous']['to_utc_ms'])return 'previous';
    return null;
}
function vp3_profile_analytics_point_v1(int|float|null $value,string $coverage='complete',string $reason='complete'): array
{
    return ['value'=>$coverage==='complete'?$value:null,'observed_value'=>$coverage==='partial'?$value:null,'coverage'=>$coverage,
        'reason'=>$value===null&&$coverage==='complete'?'no_observations':$reason];
}
function vp3_profile_analytics_metric_v1(string $key,string $group,string $label,string $unit,string $definition,array $current,array $previous): array
{
    $comparable=$current['coverage']==='complete'&&$previous['coverage']==='complete'
        &&is_numeric($current['value'])&&is_numeric($previous['value']);
    $delta=$comparable?$current['value']-$previous['value']:null;
    return compact('key','group','label','unit','definition','current','previous')
        +['comparison'=>['delta'=>$delta,'percent'=>$comparable&&$previous['value']!=0?round(100*$delta/abs($previous['value']),2):null]];
}
function vp3_profile_analytics_catalog_v1(): array
{
    return [
        'visit_records'=>['events','Visit records','count','Saved profile_view records with a verified application UTC clock; not people or page loads.'],
        'recognized_browser_contacts'=>['events','Recognized browser contacts','count','Distinct currently disclosed native visitor/browser principals proved on timed profile_view records; not unique people.'],
        'accepted_visitor_turns'=>['conversations','Accepted visitor turns','count','Exact saved native PA3 visitor admissions in this period.'],
        'active_conversations'=>['conversations','Conversations with accepted activity','count','Distinct public Profile threads with at least one verified accepted visitor turn in this period.'],
        'new_conversations'=>['conversations','New native conversations','count','Threads whose first saved visitor message is a verified native admission in this period.'],
        'owner_takeovers'=>['conversations','Owner takeovers','count','Saved canonical owner claim operations with verified native turn lineage.'],
        'owner_reply_messages'=>['conversations','Verified owner replies','count','Distinct owner messages linked to exact saved owner reply operations.'],
        'agent_reply_messages'=>['conversations','Verified Agent replies','count','Distinct Agent messages linked to exact successful native reply receipts.'],
        'calls_offered'=>['calls','Calls offered','count','Verified native calls created in this period.'],
        'calls_accepted'=>['calls','Calls accepted','count','Verified calls accepted in this period; direct/assisted classification is taken only after acceptance.'],
        'calls_finished'=>['calls','Calls with a saved terminal outcome','count','Currently verified terminal call outcomes dated in this period; corrections can revise this measure.'],
        'median_call_accept_seconds'=>['calls','Median call acceptance delay','seconds','Median verified offer-to-accept delay for calls accepted in this period; never talk duration.'],
        'recorded_product_conversions'=>['commerce','Recorded product conversions','count','Timed product_converted records; recorded events are not evidence of paid orders.'],
        'recorded_booking_conversions'=>['commerce','Recorded booking conversions','count','Timed booking_converted records; recorded events are not evidence of paid orders.'],
        'verified_paid_orders'=>['commerce','Orders reaching verified payment completion','count','Owned Profile orders whose verified cumulative payments first reached the frozen total in this period; later refunds remain separate.'],
        'verified_refunded_orders'=>['commerce','Orders with verified refunds','count','Distinct owned Profile orders with a verified completed refund in this period.'],
        'median_first_reply_seconds'=>['conversations','Median first reply delay','seconds','New-native-conversation cohort; the first exact verified reply before the reporting cutoff. Unanswered and unverifiable conversations remain separate.'],
        'image_creations_completed'=>['images','Completed free image creations','count','Distinct free Profile image requests with exact native completion receipts, dated by saved UTC completion within this owner-local period.'],
        'image_initial_creations'=>['images','Initial image creations','count','Verified completed free requests starting from a visitor upload; retries of the same request count once.'],
        'image_revisions_completed'=>['images','Completed image revisions','count','Verified completed free requests using a proved earlier result from the same conversation; each distinct revision counts once.'],
        'image_requests_failed'=>['images','Dated failed image requests','count','Distinct failed free requests with an exact native failure reply and receipt in this period. Undated failures remain incomplete and current failures are shown separately.'],
    ];
}
function vp3_profile_analytics_empty_points_v1(array $keys,string $reason='storage_unconfigured'): array
{
    $out=[];foreach($keys as $key)$out[$key]=['current'=>vp3_profile_analytics_point_v1(null,'unavailable',$reason),
        'previous'=>vp3_profile_analytics_point_v1(null,'unavailable',$reason)];return $out;
}
function vp3_profile_analytics_median_v1(array $values): int|float|null
{
    if(!$values)return null;sort($values,SORT_NUMERIC);$n=count($values);$i=intdiv($n,2);
    return $n%2?$values[$i]:($values[$i-1]+$values[$i])/2;
}
function vp3_profile_analytics_timezone_v1(PDO $pdo,array $scope): array
{
    $zone=trim((string)($scope['user']['timezone']??''));
    $valid=static fn(string $value):bool=>in_array($value,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true);
    if($zone!==''){
        if(!$valid($zone))throw new RuntimeException('The configured Profile reporting timezone is unavailable.',503);
        return [$zone,'explicit'];
    }
    if(vp3_profile_analytics_table_v1($pdo,'agent_scheduling_schedules')){
        $rows=vp3_profile_analytics_rows_v1($pdo,'SELECT id,timezone FROM agent_scheduling_schedules WHERE owner_user_id=? ORDER BY is_default DESC,is_active DESC,id ASC LIMIT 1',[(int)$scope['user']['id']],true);
        if($rows){$zone=(string)$rows[0]['timezone'];if(!$valid($zone))throw new RuntimeException('The scheduling timezone is unavailable.',503);return [$zone,'schedule'];}
    }
    return ['UTC','utc_fallback'];
}

/** Exact native history, independent of today's public Profile visibility. */
function vp3_profile_analytics_native_history_v1(PDO $pdo,int $owner): array
{
    if(!vp3_profile_analytics_tables_v1($pdo,['profile_agent_conversations','profile_agent_messages','profile_visit_sessions','user_agents']))
        throw new RuntimeException('Native history storage is unconfigured.',424);
    $conversations=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_agent_conversations WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $messages=vp3_profile_analytics_rows_v1($pdo,'SELECT m.* FROM profile_agent_messages m INNER JOIN profile_agent_conversations c ON c.id=m.conversation_id WHERE c.owner_user_id=? ORDER BY m.id',[$owner],true);
    $sessions=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id,session_key FROM profile_visit_sessions WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $agents=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id FROM user_agents WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $out=['conversations'=>[],'messages'=>[],'threads'=>[],'bindings'=>[],'sessions'=>[],'agents'=>[],'valid'=>[]];
    foreach($sessions as $row)$out['sessions'][(int)$row['id']]=$row;
    foreach($agents as $row)$out['agents'][(int)$row['id']]=$row;
    foreach($messages as $row){$id=(int)$row['id'];$cid=(int)$row['conversation_id'];$out['messages'][$id]=$row;$out['threads'][$cid][]=$row;
        if($row['sender_type']==='system')$out['bindings'][$cid][]=vp3_profile_analytics_json_v1($row['context_json']??null);}
    foreach($conversations as $row){
        $cid=(int)$row['id'];$out['conversations'][$cid]=$row;$bindings=$out['bindings'][$cid]??[];$binding=count($bindings)===1?$bindings[0]:null;
        $aid=(int)$row['profile_agent_id'];$sid=(int)$row['profile_session_id'];$visitor=(int)($row['visitor_user_id']??0);
        if(!isset($out['agents'][$aid],$out['sessions'][$sid])||!vp3_profile_agent_conversation_authority_valid_v1($binding,$owner)
            ||$binding['transport']!=='native'||(int)($binding['profile_agent_id']??0)!==$aid
            ||(int)($binding['profile_session_id']??0)!==$sid||$binding['visitor_user_id']!==$visitor||$visitor===$owner)continue;
        $out['valid'][$cid]=$binding;
    }
    return $out;
}
function vp3_profile_analytics_turn_valid_v1(array $turn,array $history,int $owner): bool
{
    $cid=(int)$turn['conversation_id'];$c=$history['conversations'][$cid]??null;$b=$history['valid'][$cid]??null;
    $m=$history['messages'][(int)$turn['visitor_message_id']]??null;$snapshot=vp3_profile_analytics_json_v1($turn['accepted_json']??null);
    if(!$c||!$b||!$m||!$snapshot||($snapshot['contract']??null)!=='vp3.profile-accepted-turn.pa3'
        ||(int)$turn['owner_user_id']!==$owner||$turn['transport']!=='native'||$m['sender_type']!=='visitor'
        ||(int)$m['conversation_id']!==$cid||(int)($m['sender_user_id']??0)!==(int)$turn['visitor_user_id']
        ||(int)$turn['profile_agent_id']!==(int)$c['profile_agent_id']||(int)$turn['profile_session_id']!==(int)$c['profile_session_id']
        ||(int)$turn['visitor_user_id']!==$b['visitor_user_id']||!hash_equals($b['credential_hash'],(string)$turn['credential_hash']))return false;
    foreach(['profile_fingerprint','agent_fingerprint','session_fingerprint','message_sha256'] as $field)
        if(!is_string($snapshot[$field]??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$snapshot[$field]))return false;
    $session=$history['sessions'][(int)$turn['profile_session_id']]??[];
    $sessionProof=[];foreach(['id','owner_user_id','session_key'] as $key)$sessionProof[$key]=(string)($session[$key]??'');
    // The accepted Agent/profile fingerprints describe the live inference
    // snapshot, including then-current names, activity and public settings.
    // Historical counts prove current Agent ownership through native_history,
    // not equality between mutable settings today and that saved snapshot.
    return hash_equals($snapshot['message_sha256'],hash('sha256',(string)$m['message']))
        &&hash_equals($snapshot['session_fingerprint'],vp3_profile_analytics_hash_v1($sessionProof));
}

/** A current browser principal is never recovered from a mutable CRM user id. */
function vp3_profile_analytics_contact_v1(PDO $pdo,array $event,int $owner): array
{
    $meta=vp3_profile_analytics_json_v1($event['metadata_json']??null);$p=$meta['source_authority']??null;
    if(!is_array($p)||($p['contract']??null)!=='vp3.profile-event-authority.v1'||strlen(json_encode($p,JSON_THROW_ON_ERROR))>4096)return ['verified'=>false];
    foreach(['owner_user_id','profile_agent_id','profile_session_id','visitor_user_id'] as $key)
        if(!is_int($p[$key]??null)||$p[$key]!== (int)($event[$key]??0))return ['verified'=>false];
    foreach(['credential_hash','session_hash'] as $key)if(!is_string($p[$key]??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$p[$key]))return ['verified'=>false];
    if($p['owner_user_id']!==$owner||$p['visitor_user_id']===$owner||!is_int($p['public_conversation_id']??null)||$p['public_conversation_id']<0
        ||!is_int($p['accepted_message_id']??null)||$p['accepted_message_id']<0||!is_string($p['accepted_message_sha256']??null))return ['verified'=>false];
    $session=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id,session_key FROM profile_visit_sessions WHERE id=?',[$p['profile_session_id']],true)[0]??null;
    $profile=vp3_profile_analytics_rows_v1($pdo,'SELECT user_id,username FROM user_profiles WHERE user_id=?',[$owner],true)[0]??null;
    $agent=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id FROM user_agents WHERE id=?',[$p['profile_agent_id']],true)[0]??null;
    if(!$session||!$profile||!$agent||(int)$session['owner_user_id']!==$owner||(int)$agent['owner_user_id']!==$owner
        ||($p['profile_username']??null)!==$profile['username']||!hash_equals($p['session_hash'],hash('sha256',(string)$session['session_key'])))return ['verified'=>false];
    $cid=$p['public_conversation_id'];
    if($cid>0){
        $c=vp3_profile_analytics_rows_v1($pdo,'SELECT owner_user_id,profile_agent_id,profile_session_id,visitor_user_id FROM profile_agent_conversations WHERE id=?',[$cid],true)[0]??null;
        $rows=vp3_profile_analytics_rows_v1($pdo,"SELECT context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system' ORDER BY id",[$cid],true);
        $binding=count($rows)===1?vp3_profile_analytics_json_v1($rows[0]['context_json']):null;
        if(!$c||(int)$c['owner_user_id']!==$owner||(int)$c['profile_agent_id']!==$p['profile_agent_id']
            ||(int)$c['profile_session_id']!==$p['profile_session_id']||(int)($c['visitor_user_id']??0)!==$p['visitor_user_id']
            ||!vp3_profile_agent_conversation_authority_valid_v1($binding,$owner)||$binding['transport']!=='native'
            ||!hash_equals($binding['credential_hash'],$p['credential_hash']))return ['verified'=>false];
    }
    if($p['accepted_message_id']>0){
        $m=vp3_profile_analytics_rows_v1($pdo,"SELECT message,sender_user_id FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='visitor'",[$p['accepted_message_id'],$cid],true)[0]??null;
        if(!$m||(int)($m['sender_user_id']??0)!==$p['visitor_user_id']||!hash_equals($p['accepted_message_sha256'],hash('sha256',(string)$m['message'])))return ['verified'=>false];
    }elseif($p['accepted_message_sha256']!=='')return ['verified'=>false];
    $visitor=$p['visitor_user_id'];
    if($visitor===0)return ['verified'=>($p['visitor_auth_revision']??null)==='','contact'=>null];
    $user=vp3_profile_analytics_rows_v1($pdo,'SELECT id,is_active,password_hash FROM users WHERE id=?',[$visitor],true)[0]??null;
    $privacy=vp3_profile_analytics_rows_v1($pdo,'SELECT share_visit_identity FROM user_profiles WHERE user_id=?',[$visitor],true)[0]??null;
    $blocks=vp3_profile_analytics_rows_v1($pdo,'SELECT blocker_user_id,blocked_user_id FROM user_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?)',[$owner,$visitor,$visitor,$owner],true);
    if(!$user||empty($user['is_active'])||!$privacy||!in_array($privacy['share_visit_identity'],[1,'1',true],true)||$blocks
        ||!is_string($p['visitor_auth_revision']??null)||!hash_equals($p['visitor_auth_revision'],hash('sha256',$visitor.'|'.(string)$user['password_hash'])))return ['verified'=>false];
    return ['verified'=>true,'contact'=>hash('sha256',$owner.'|'.$visitor.'|'.$p['credential_hash'])];
}

function vp3_profile_analytics_events_v1(PDO $pdo,int $owner,array $window): array
{
    $keys=['visit_records','recognized_browser_contacts','recorded_product_conversions','recorded_booking_conversions'];
    $out=['points'=>vp3_profile_analytics_empty_points_v1($keys),'coverage'=>'unconfigured','reason'=>'storage_unconfigured','evidence'=>[]];
    if(!vp3_profile_analytics_table_v1($pdo,'profile_events'))return $out;
    require_once __DIR__.'/profile-event-clock-v1.php';
    $clock=vp3_profile_event_clock_status_v1($pdo,true);
    if(($clock['availability']??null)!=='ready'||empty($clock['columns_available'])||($clock['writer_contract']??null)!=='vp3.profile-event-clock.v1'){
        $out['coverage']=($clock['availability']??null)==='unavailable'?'unavailable':'unconfigured';
        $out['reason']=$out['coverage']==='unavailable'?'storage_unavailable':'storage_unconfigured';
        $out['points']=vp3_profile_analytics_empty_points_v1($keys,$out['reason']);return $out;
    }
    $activation=$clock['activation']??null;
    if(!is_array($activation)||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($activation['token']??''))
        ||vp3_profile_analytics_epoch_v1($activation['activated_at_utc_ms']??null)===null)throw new RuntimeException('Profile event clock proof is unavailable.',503);
    $rows=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id,profile_agent_id,profile_session_id,visitor_user_id,event_type,metadata_json,recorded_at_utc_ms,clock_legacy_token_v1 FROM profile_events WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $values=['current'=>array_fill_keys($keys,0),'previous'=>array_fill_keys($keys,0)];
    $contacts=['current'=>[],'previous'=>[]];$identityGap=['current'=>false,'previous'=>false];$gap=false;
    foreach($rows as $row){
        $stamp=vp3_profile_analytics_epoch_v1($row['recorded_at_utc_ms']);$marker=$row['clock_legacy_token_v1'];
        if($stamp===null&&$row['recorded_at_utc_ms']===null&&is_string($marker)&&hash_equals($activation['token'],$marker))continue;
        if($stamp===null||$marker!==null){$gap=true;continue;}
        $side=vp3_profile_analytics_window_key_v1($stamp,$window);if($side===null)continue;
        $type=(string)$row['event_type'];$eventKey=match($type){'profile_view'=>'visit_records','product_converted'=>'recorded_product_conversions','booking_converted'=>'recorded_booking_conversions',default=>null};
        if($eventKey===null)continue;
        $values[$side][$eventKey]++;
        $evidence=[(int)$row['id'],$stamp,$type];
        if($type==='profile_view'){
            try{$identity=vp3_profile_analytics_contact_v1($pdo,$row,$owner);}
            catch(Throwable $error){if(vp3_profile_analytics_retryable_v1($error))throw $error;$identity=['verified'=>false];}
            if(!$identity['verified'])$identityGap[$side]=true;
            elseif(isset($identity['contact']))$contacts[$side][$identity['contact']]=true;
            $evidence[]=$identity['verified'];$evidence[]=$identity['contact']??null;
        }
        $out['evidence'][]=$evidence;
    }
    $limited=false;
    foreach(['current','previous'] as $side){
        $from=$side==='current'?$window['from_utc_ms']:$window['previous']['from_utc_ms'];
        $reason=$gap?'clock_gap':($from<(int)$activation['activated_at_utc_ms']?'before_clock_activation':'complete');
        $coverage=$reason==='complete'?'complete':'partial';
        $values[$side]['recognized_browser_contacts']=count($contacts[$side]);
        foreach($keys as $key){
            $pointReason=$key==='recognized_browser_contacts'&&$identityGap[$side]?'identity_proof_unavailable':$reason;
            $pointCoverage=$coverage==='partial'||$pointReason!=='complete'?'partial':'complete';
            $out['points'][$key][$side]=vp3_profile_analytics_point_v1($values[$side][$key],$pointCoverage,$pointReason);
            $limited=$limited||$pointCoverage!=='complete';
        }
    }
    $out['coverage']=$limited?'limited':'ready';$out['reason']=$limited?($gap?'clock_gap':'source_proof_incomplete'):'complete';
    // Only coverage and normalized in-window facts affect the fixed revision.
    // New clocked events after cutoff do not perturb this period.
    return $out;
}

function vp3_profile_analytics_empty_cohorts_v1(string $coverage='unavailable',string $reason='storage_unconfigured'): array
{
    $one=['eligible'=>null,'replied'=>null,'unanswered'=>null,'unverifiable'=>null,'coverage'=>$coverage,'reason'=>$reason];
    return ['current'=>$one,'previous'=>$one];
}
/** Exact completed Agent answer receipt. $turn must already pass turn_valid_v1. */
function vp3_profile_analytics_agent_reply_proof_v1(int $owner,array $message,?array $turn,array $runMap,array $actionMap,array $receiptMap): ?array
{
    $mid=(int)($message['id']??0);$cid=(int)($message['conversation_id']??0);
    $context=vp3_profile_analytics_json_v1($message['context_json']??null);$marker=$context['profile_handoff']??null;
    $stamp=vp3_profile_analytics_utc_v1($message['created_at']??null);
    if(!$turn||!is_array($marker)||($marker['contract']??null)!=='vp3.profile-handoff.v1'
        ||($marker['turn_id']??null)!==(int)$turn['id']||($marker['visitor_message_id']??null)!==(int)$turn['visitor_message_id']
        ||(int)$turn['owner_user_id']!==$owner||(int)$turn['conversation_id']!==$cid
        ||($message['sender_type']??null)!=='agent'||($message['sender_user_id']??null)!==null
        ||$stamp===null||!isset($turn['_at'])||$stamp<$turn['_at']||$mid<=(int)$turn['visitor_message_id'])return null;
    $run=$runMap[(int)$turn['run_id']]??null;$action=$actionMap[(int)$turn['action_id']]??null;
    $hash=hash('sha256',$owner.'|'.$turn['run_id'].'|'.$turn['action_id'].'|profile-turn:'.$turn['id'].':reply');
    $found=$receiptMap[$hash]??[];$receipt=count($found)===1?$found[0]:null;
    $result=$receipt?vp3_profile_analytics_json_v1($receipt['result_json']??null):null;
    if(!$run||!$action||!$receipt||$receipt['status']!=='completed'||(int)$receipt['owner_user_id']!==$owner
        ||(int)$receipt['run_id']!==(int)$turn['run_id']||(int)$receipt['action_id']!==(int)$turn['action_id']
        ||(int)$action['run_id']!==(int)$run['id']||$run['source_key']!=='profile-turn:'.$turn['id']
        ||$run['workflow_type']!=='profile_visitor_reply'||(int)$run['agent_id']!==(int)$turn['profile_agent_id']
        ||$action['action_key']!==$run['source_key']||!is_array($result)
        // Native durable receipts normalize scalar IDs to canonical strings;
        // older integer JSON represents the same exact positive identity.
        ||vp3_profile_analytics_receipt_id_v1($result['turn_id']??null)!==(int)$turn['id']
        ||vp3_profile_analytics_receipt_id_v1($result['conversation_id']??null)!==$cid
        ||vp3_profile_analytics_receipt_id_v1($result['visitor_message_id']??null)!==(int)$turn['visitor_message_id']
        ||vp3_profile_analytics_receipt_id_v1($result['answer_message_id']??null)!==$mid)return null;
    return ['receipt_id'=>(int)$receipt['id'],'at'=>$stamp,'public_conversation_id'=>$cid,'turn_id'=>(int)$turn['id'],
        'visitor_message_id'=>(int)$turn['visitor_message_id'],'answer_message_id'=>$mid];
}

function vp3_profile_analytics_conversations_v1(PDO $pdo,int $owner,array $window,array $history): array
{
    $keys=['accepted_visitor_turns','active_conversations','new_conversations','owner_takeovers','owner_reply_messages','agent_reply_messages','median_first_reply_seconds'];
    $out=['points'=>vp3_profile_analytics_empty_points_v1($keys),'coverage'=>'unconfigured','reason'=>'storage_unconfigured','evidence'=>[],
        'cohorts'=>vp3_profile_analytics_empty_cohorts_v1()];
    if(!vp3_profile_analytics_tables_v1($pdo,['profile_agent_handoff_turns_pa3','profile_agent_handoff_operations_pa3','agent_workflow_receipts','agent_workflow_runs','agent_workflow_actions']))return $out;
    $turns=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? ORDER BY visitor_message_id,id',[$owner],true);
    $operations=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_agent_handoff_operations_pa3 WHERE owner_user_id=? ORDER BY created_at_ms,request_id',[$owner],true);
    $receipts=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_workflow_receipts WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $runs=vp3_profile_analytics_rows_v1($pdo,"SELECT id,agent_id,source_key,source_kind,workflow_type FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='profile_visitor_turn' ORDER BY id",[$owner],true);
    $actions=vp3_profile_analytics_rows_v1($pdo,"SELECT id,run_id,action_key,capability_key FROM agent_workflow_actions WHERE owner_user_id=? AND capability_key='profile.visitor.reply' ORDER BY id",[$owner],true);
    $runMap=[];$actionMap=[];$receiptMap=[];$valid=[];$byMessage=[];$cohort=[];
    foreach($runs as $r)$runMap[(int)$r['id']]=$r;
    foreach($actions as $r)$actionMap[(int)$r['id']]=$r;
    foreach($receipts as $r)$receiptMap[(string)$r['receipt_key']][]=$r;
    $numbers=['current'=>array_fill_keys($keys,0),'previous'=>array_fill_keys($keys,0)];$active=['current'=>[],'previous'=>[]];
    $gaps=array_fill_keys($keys,false);$replies=[];$unverified=[];$replyIds=['owner'=>[],'agent'=>[]];
    foreach($turns as $turn){
        $stamp=vp3_profile_analytics_utc_v1($turn['created_at']??null);$cid=(int)$turn['conversation_id'];$mid=(int)$turn['visitor_message_id'];$id=(int)$turn['id'];
        if(!vp3_profile_analytics_turn_valid_v1($turn,$history,$owner)||$stamp===null){$gaps['accepted_visitor_turns']=$gaps['active_conversations']=$gaps['new_conversations']=true;continue;}
        $valid[$id]=$turn+['_at'=>$stamp];$byMessage[$mid]=$id;
        $side=vp3_profile_analytics_window_key_v1($stamp,$window);
        if($side!==null){$numbers[$side]['accepted_visitor_turns']++;$active[$side][$cid]=true;
            $out['evidence'][]=['turn',$id,$cid,$mid,$stamp,vp3_profile_analytics_hash_v1(vp3_profile_analytics_json_v1($turn['accepted_json']))];}
        $visitorMessages=array_values(array_filter($history['threads'][$cid]??[],static fn(array $m):bool=>$m['sender_type']==='visitor'));
        if($visitorMessages&&(int)$visitorMessages[0]['id']===$mid&&!isset($cohort[$cid])){
            $cohort[$cid]=['at'=>$stamp,'mid'=>$mid,'side'=>$side];
            if($side!==null)$numbers[$side]['new_conversations']++;
        }
    }
    foreach($operations as $op){
        $turn=$valid[(int)$op['turn_id']]??null;$stamp=vp3_profile_analytics_epoch_v1($op['created_at_ms']??null);$operation=$op['operation'];
        if(!in_array($operation,['claim','reply'],true))continue;
        $key=$operation==='claim'?'owner_takeovers':'owner_reply_messages';
        if(!$turn||$stamp===null||$stamp<$turn['_at']||!preg_match('/\A[a-f0-9]{32}\z/D',(string)$op['request_id'])){$gaps[$key]=true;continue;}
        $side=vp3_profile_analytics_window_key_v1($stamp,$window);
        if($operation==='claim'){if($side!==null){$numbers[$side][$key]++;$out['evidence'][]=['claim',$op['request_id'],(int)$turn['id'],$stamp];}continue;}
        $mid=(int)($op['message_id']??0);$message=$history['messages'][$mid]??null;$ctx=$message?vp3_profile_analytics_json_v1($message['context_json']??null):null;
        $marker=$ctx['profile_handoff']??null;$cid=(int)$turn['conversation_id'];
        if(!$message||$message['sender_type']!=='owner'||(int)($message['sender_user_id']??0)!==$owner||(int)$message['conversation_id']!==$cid
            ||!is_array($marker)||($marker['contract']??null)!=='vp3.profile-handoff.v1'||($marker['turn_id']??null)!==(int)$turn['id']
            ||($marker['owner_request_id']??null)!==$op['request_id']||$mid<=(int)$turn['visitor_message_id']){$gaps[$key]=true;continue;}
        if(isset($replyIds['owner'][$mid])){$gaps[$key]=true;continue;}$replyIds['owner'][$mid]=true;
        $replies[$cid][]=['id'=>$mid,'at'=>$stamp,'type'=>'owner'];
        if($side!==null){$numbers[$side][$key]++;$out['evidence'][]=['owner_reply',$mid,(int)$turn['id'],$op['request_id'],$stamp];}
    }
    foreach($history['messages'] as $message){
        if(!in_array($message['sender_type'],['owner','agent'],true))continue;
        $mid=(int)$message['id'];$cid=(int)$message['conversation_id'];$type=$message['sender_type'];$key=$type.'_reply_messages';
        if($type==='owner'){
            if(!isset($replyIds['owner'][$mid])){$unverified[$cid][]=$mid;$gaps[$key]=true;}
            continue;
        }
        $context=vp3_profile_analytics_json_v1($message['context_json']??null);$marker=$context['profile_handoff']??null;$turn=$valid[(int)($marker['turn_id']??0)]??null;
        $proof=vp3_profile_analytics_agent_reply_proof_v1($owner,$message,$turn,$runMap,$actionMap,$receiptMap);
        $stamp=$proof['at']??null;
        if(!$proof){$unverified[$cid][]=$mid;$gaps[$key]=true;continue;}
        $replyIds['agent'][$mid]=true;$replies[$cid][]=['id'=>$mid,'at'=>$stamp,'type'=>'agent'];
        $side=vp3_profile_analytics_window_key_v1($stamp,$window);
        if($side!==null){$numbers[$side][$key]++;$out['evidence'][]=['agent_reply',$mid,(int)$turn['id'],$proof['receipt_id'],$stamp];}
    }
    $cohorts=[];$delays=['current'=>[],'previous'=>[]];
    foreach(['current','previous'] as $side)$cohorts[$side]=['eligible'=>0,'replied'=>0,'unanswered'=>0,'unverifiable'=>0,'coverage'=>'complete','reason'=>'complete'];
    foreach($cohort as $cid=>$start){
        $side=$start['side'];if($side===null)continue;$cohorts[$side]['eligible']++;
        $cohortCutoff=$side==='current'?$window['to_utc_ms']:$window['previous']['to_utc_ms'];
        $candidates=array_values(array_filter($replies[$cid]??[],static fn(array $reply):bool=>$reply['id']>$start['mid']&&$reply['at']>=$start['at']&&$reply['at']<$cohortCutoff));
        usort($candidates,static fn(array $a,array $b):int=>[$a['at'],$a['id']]<=>[$b['at'],$b['id']]);
        $first=$candidates[0]??null;$unknown=false;
        foreach($unverified[$cid]??[] as $mid)if($mid>$start['mid']&&($first===null||$mid<$first['id'])){$unknown=true;break;}
        if($unknown){$cohorts[$side]['unverifiable']++;$cohorts[$side]['coverage']='partial';$cohorts[$side]['reason']='receipt_proof_incomplete';}
        elseif($first){$cohorts[$side]['replied']++;$delays[$side][]=($first['at']-$start['at'])/1000;$out['evidence'][]=['first_reply',$cid,$start['mid'],$first['id'],$first['at']];}
        else $cohorts[$side]['unanswered']++;
    }
    $limited=false;
    foreach(['current','previous'] as $side){
        $numbers[$side]['active_conversations']=count($active[$side]);
        $numbers[$side]['median_first_reply_seconds']=vp3_profile_analytics_median_v1($delays[$side]);
        foreach($keys as $key){
            $partial=$gaps[$key]||($key==='median_first_reply_seconds'&&($gaps['new_conversations']||$cohorts[$side]['unverifiable']>0));
            $out['points'][$key][$side]=vp3_profile_analytics_point_v1($numbers[$side][$key],$partial?'partial':'complete',$partial?'source_proof_incomplete':'complete');
            $limited=$limited||$partial;
        }
        if($gaps['new_conversations']){$cohorts[$side]['coverage']='partial';$cohorts[$side]['reason']='source_proof_incomplete';}
    }
    $out['cohorts']=$cohorts;$out['coverage']=$limited?'limited':'ready';$out['reason']=$limited?'source_proof_incomplete':'complete';
    return $out;
}

function vp3_profile_analytics_empty_calls_v1(): array
{
    $one=['accepted'=>['direct'=>null,'assisted'=>null],'finished'=>array_fill_keys(['ended','declined','cancelled','expired','failed'],null),
        'acceptance_delay'=>['eligible'=>null,'verified'=>null,'unverifiable'=>null]];
    return ['current'=>$one,'previous'=>$one];
}
function vp3_profile_analytics_calls_v1(PDO $pdo,int $owner,array $window,array $history): array
{
    $keys=['calls_offered','calls_accepted','calls_finished','median_call_accept_seconds'];
    $out=['points'=>vp3_profile_analytics_empty_points_v1($keys),'coverage'=>'unconfigured','reason'=>'storage_unconfigured',
        'evidence'=>[],'breakdowns'=>vp3_profile_analytics_empty_calls_v1()];
    if(!vp3_profile_analytics_table_v1($pdo,'profile_calls_pa3c'))return $out;
    require_once __DIR__.'/profile-calls-pa3c.php';
    $rows=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM profile_calls_pa3c WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $counts=['current'=>array_fill_keys($keys,0),'previous'=>array_fill_keys($keys,0)];$gaps=array_fill_keys($keys,false);$delays=['current'=>[],'previous'=>[]];
    $breakdowns=[];foreach(['current','previous'] as $side)$breakdowns[$side]=[
        'accepted'=>['direct'=>0,'assisted'=>0],'finished'=>array_fill_keys(['ended','declined','cancelled','expired','failed'],0),
        'acceptance_delay'=>['eligible'=>0,'verified'=>0,'unverifiable'=>0]];
    foreach($rows as $row){
        $id=(int)$row['id'];$cid=(int)$row['conversation_id'];$c=$history['conversations'][$cid]??null;$binding=$history['valid'][$cid]??null;
        $proof=$c&&$binding&&(int)$row['profile_agent_id']===(int)$c['profile_agent_id']
            &&(int)$row['profile_session_id']===(int)$c['profile_session_id']&&(int)$row['visitor_user_id']===(int)($c['visitor_user_id']??0)
            &&hash_equals($binding['credential_hash'],(string)$row['credential_hash']);
        try{vp3_profile_call_integrity_pa3c($row);}catch(VP3ProfileCallException){$proof=false;}
        $authority=vp3_profile_analytics_json_v1($row['authority_json']??null);
        $proof=$proof&&is_array($authority)&&($authority['contract']??null)==='vp3.profile-call-authority.v1';
        foreach(['profile_fingerprint','agent_fingerprint','session_fingerprint'] as $field)
            if(!is_string($authority[$field]??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$authority[$field]))$proof=false;
        if($proof){
            $proof=hash_equals($authority['agent_fingerprint'],vp3_profile_call_fingerprint_pa3c($history['agents'][(int)$row['profile_agent_id']],['id','owner_user_id']))
                &&hash_equals($authority['session_fingerprint'],vp3_profile_call_fingerprint_pa3c($history['sessions'][(int)$row['profile_session_id']],['id','owner_user_id','session_key']));
        }
        $created=vp3_profile_analytics_epoch_v1($row['created_at_ms']??null);
        if(!$proof||$created===null){foreach($keys as $key)$gaps[$key]=true;continue;}
        if($created>=$window['to_utc_ms'])continue;
        $side=vp3_profile_analytics_window_key_v1($created,$window);
        if($side!==null){$counts[$side]['calls_offered']++;$out['evidence'][]=['call_offered',$id,$cid,$created,(string)$row['mode'],(string)$row['offered_by']];}
        $accepted=vp3_profile_analytics_epoch_v1($row['accepted_at_ms']??null);
        if($row['accepted_at_ms']!==null&&($accepted===null||$accepted<$created)){$gaps['calls_accepted']=$gaps['median_call_accept_seconds']=true;}
        elseif($accepted!==null){
            $side=vp3_profile_analytics_window_key_v1($accepted,$window);
            if($side!==null){
                $counts[$side]['calls_accepted']++;$mode=(int)$row['assisted']===1?'assisted':'direct';
                $breakdowns[$side]['accepted'][$mode]++;$breakdowns[$side]['acceptance_delay']['eligible']++;
                $breakdowns[$side]['acceptance_delay']['verified']++;$delays[$side][]=($accepted-$created)/1000;
                $out['evidence'][]=['call_accepted',$id,$accepted,$mode,$created];
            }
        }elseif(in_array($row['state'],['preparing','ready','active','ending','ended'],true)){$gaps['calls_accepted']=$gaps['median_call_accept_seconds']=true;}
        if(in_array($row['state'],['ended','declined','cancelled','expired','failed'],true)){
            $ended=vp3_profile_analytics_epoch_v1($row['ended_at_ms']??null);
            if($ended===null||$ended<$created||($accepted!==null&&$ended<$accepted)){$gaps['calls_finished']=true;continue;}
            $side=vp3_profile_analytics_window_key_v1($ended,$window);
            if($side!==null){$counts[$side]['calls_finished']++;$breakdowns[$side]['finished'][$row['state']]++;
                $out['evidence'][]=['call_finished',$id,$ended,$row['state']];}
        }elseif(!in_array($row['state'],['offered','preparing','ready','active','ending'],true))$gaps['calls_finished']=true;
    }
    $limited=in_array(true,$gaps,true);
    foreach(['current','previous'] as $side){
        $counts[$side]['median_call_accept_seconds']=vp3_profile_analytics_median_v1($delays[$side]);
        foreach($keys as $key)$out['points'][$key][$side]=vp3_profile_analytics_point_v1($counts[$side][$key],$gaps[$key]?'partial':'complete',$gaps[$key]?'source_proof_incomplete':'complete');
        if($gaps['median_call_accept_seconds'])$breakdowns[$side]['acceptance_delay']['unverifiable']=null;
    }
    $out['breakdowns']=$breakdowns;$out['coverage']=$limited?'limited':'ready';$out['reason']=$limited?'source_proof_incomplete':'complete';
    return $out;
}

/** Decimal-string arithmetic retains the Commerce ledger's fixed scale without float sums. */
function vp3_profile_analytics_uint_v1(mixed $value): ?string
{
    if((!is_int($value)&&!is_string($value))||!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$value))return null;
    return (string)$value;
}
function vp3_profile_analytics_add_v1(string $left,string $right): string
{
    $i=strlen($left)-1;$j=strlen($right)-1;$carry=0;$out='';
    while($i>=0||$j>=0||$carry){$n=($i>=0?(int)$left[$i--]:0)+($j>=0?(int)$right[$j--]:0)+$carry;$out=($n%10).$out;$carry=intdiv($n,10);}
    return $out===''?'0':$out;
}
function vp3_profile_analytics_cmp_v1(string $left,string $right): int
{
    return strlen($left)===strlen($right)?strcmp($left,$right):strlen($left)<=>strlen($right);
}
function vp3_profile_analytics_sub_v1(string $left,string $right): string
{
    $negative=vp3_profile_analytics_cmp_v1($left,$right)<0;if($negative)[$left,$right]=[$right,$left];
    $i=strlen($left)-1;$j=strlen($right)-1;$borrow=0;$out='';
    while($i>=0){$n=(int)$left[$i--]-($j>=0?(int)$right[$j--]:0)-$borrow;$borrow=$n<0?1:0;if($borrow)$n+=10;$out=$n.$out;}
    $out=ltrim($out,'0');$out=$out===''?'0':$out;return $negative&&$out!=='0'?'-'.$out:$out;
}
function vp3_profile_analytics_money_point_v1(string $value,string $coverage='complete'): array
{
    return ['value_minor'=>$coverage==='complete'?$value:null,'observed_value_minor'=>$coverage==='partial'?$value:null,
        'coverage'=>$coverage,'reason'=>$coverage==='complete'?'complete':'receipt_proof_incomplete'];
}

/** Validate frozen Profile attribution and item terms, never current titles or recency. */
function vp3_profile_analytics_order_v1(PDO $pdo,array $order,array $items,array $products,array $history): bool
{
    $owner=(int)$order['owner_user_id'];$meta=vp3_profile_analytics_json_v1($order['metadata_json']??null);$terms=vp3_profile_analytics_json_v1($order['terms_snapshot_json']??null);
    if(($meta['source']??null)!=='profile_commerce_v900'||!is_array($terms)||count($items)!==1)return false;
    $item=$items[0];$pid=(int)($item['product_id']??0);$product=$products[$pid]??null;
    $itemMeta=vp3_profile_analytics_json_v1($item['metadata_json']??null);$itemTerms=vp3_profile_analytics_json_v1($item['terms_snapshot_json']??null);
    if(!$product||(int)$product['owner_user_id']!==$owner||$itemTerms!==$terms||!is_array($itemMeta)||(int)($terms['product_id']??0)!==$pid)return false;
    foreach(['source','profile_username','profile_product_slug','receipt_token_sha256','terms_accepted_at','terms_snapshot_sha256'] as $key)
        if(!is_string($meta[$key]??null)||$meta[$key]===''||($itemMeta[$key]??null)!==$meta[$key])return false;
    foreach(['receipt_token_sha256','terms_snapshot_sha256'] as $key)if(!preg_match('/\A[a-f0-9]{64}\z/D',$meta[$key]))return false;
    $termKeys=['product_id','payment_mode','price_cents','deposit_cents','currency','provider_mode','fixed_connection_id','hold_minutes','refund_before_hours','cancellation_fee_cents','cancellation_policy'];
    $frozen=[];foreach($termKeys as $key){if(!array_key_exists($key,$terms))return false;$frozen[$key]=$terms[$key];}
    if(!hash_equals($meta['terms_snapshot_sha256'],vp3_profile_analytics_hash_v1($frozen)))return false;
    $quantity=vp3_profile_analytics_uint_v1($item['quantity']??null);$price=vp3_profile_analytics_uint_v1($item['unit_price_cents']??null);$total=vp3_profile_analytics_uint_v1($order['total_cents']??null);
    if($quantity===null||$price===null||$total===null||$quantity==='0'||strlen($quantity)>5||strlen($price)>10
        ||(int)$quantity>65535||(int)$price>2147483647||((int)$quantity*(int)$price)!==(int)$total
        ||vp3_profile_analytics_uint_v1($item['total_cents']??null)!==$total||(string)($terms['price_cents']??'')!==$price
        ||strtolower((string)($terms['currency']??''))!==strtolower((string)$order['currency']))return false;
    if(isset($meta['profile_checkout_review_id'])){
        if(!is_string($meta['profile_checkout_review_id'])||!preg_match('/\A[a-f0-9]{32}\z/D',$meta['profile_checkout_review_id'])
            ||($itemMeta['profile_checkout_review_id']??null)!==$meta['profile_checkout_review_id']
            ||($itemMeta['profile_conversation_id']??null)!==($meta['profile_conversation_id']??null)
            ||!vp3_profile_analytics_table_v1($pdo,'profile_conversation_checkouts_pa5'))return false;
        $rows=vp3_profile_analytics_rows_v1($pdo,'SELECT owner_user_id,profile_agent_id,profile_session_id,visitor_user_id,conversation_id,credential_hash,review_id,product_id,connection_id,order_id FROM profile_conversation_checkouts_pa5 WHERE review_id=?',[$meta['profile_checkout_review_id']],true);
        $review=count($rows)===1?$rows[0]:null;$cid=(int)($meta['profile_conversation_id']??0);$binding=$history['valid'][$cid]??null;$c=$history['conversations'][$cid]??null;
        if(!$review||!$binding||!$c||(int)$review['owner_user_id']!==$owner||(int)$review['order_id']!==(int)$order['id']
            ||(int)$review['connection_id']!==(int)$order['connection_id']||(int)$review['product_id']!==$pid||(int)$review['conversation_id']!==$cid
            ||(int)$review['profile_agent_id']!==(int)$c['profile_agent_id']||(int)$review['profile_session_id']!==(int)$c['profile_session_id']
            ||(int)$review['visitor_user_id']!==$binding['visitor_user_id']||!hash_equals($binding['credential_hash'],(string)$review['credential_hash']))return false;
    }elseif(isset($meta['profile_conversation_id']))return false;
    return true;
}

/** Frozen native checkout request, including its exact merchant and integer amount. */
function vp3_profile_analytics_attempt_v1(array $attempt,array $order,string $amount,string $currency): bool
{
    $request=vp3_profile_analytics_json_v1($attempt['creation_request_json']??null);
    if(!$request||!is_bool($request['form']??null)||!is_array($request['body']??null)||array_diff(array_keys($request),['path','form','body'])
        ||!preg_match('/\A[a-f0-9]{64}\z/D',(string)($attempt['idempotency_key']??''))
        ||(string)($attempt['provider_account_id']??'')===''||($attempt['provider_account_id']??null)!==$order['external_account_snapshot']
        ||(string)($attempt['external_session_id']??'')===''||str_starts_with((string)$attempt['external_session_id'],'pending:')
        ||(string)($attempt['provider_order_id']??'')==='')return false;
    $body=$request['body'];$provider=$attempt['provider'];
    if($provider==='stripe'){
        $lines=$body['line_items']??null;$price=is_array($lines)&&count($lines)===1?($lines[0]['price_data']??null):null;
        return $request['path']==='/v1/checkout/sessions'&&$request['form']===true&&($body['mode']??null)==='payment'
            &&is_array($price)&&($lines[0]['quantity']??null)===1&&vp3_profile_analytics_uint_v1($price['unit_amount']??null)===$amount
            &&strtoupper((string)($price['currency']??''))===$currency
            &&($body['metadata']['vp3_commerce_order_id']??null)===(string)$order['id']
            &&($body['payment_intent_data']['metadata']['vp3_commerce_order_id']??null)===(string)$order['id'];
    }
    if($provider==='square'){
        $quick=$body['quick_pay']??null;
        return $request['path']==='/v2/online-checkout/payment-links'&&$request['form']===false&&is_array($quick)
            &&vp3_profile_analytics_uint_v1($quick['price_money']['amount']??null)===$amount
            &&strtoupper((string)($quick['price_money']['currency']??''))===$currency
            &&(string)($attempt['provider_location_id']??'')!==''&&($quick['location_id']??null)===$attempt['provider_location_id']
            &&($body['idempotency_key']??null)===$attempt['idempotency_key'];
    }
    if($provider==='paypal'){
        $units=$body['purchase_units']??null;$unit=is_array($units)&&count($units)===1?($units[0]??null):null;
        $value=$unit['amount']['value']??null;$minor=null;
        if(is_string($value)&&preg_match('/\A(0|[1-9][0-9]*)\.([0-9]{2})\z/D',$value,$match)){$minor=ltrim($match[1].$match[2],'0');if($minor==='')$minor='0';}
        return $request['path']==='/v2/checkout/orders'&&$request['form']===false&&($body['intent']??null)==='CAPTURE'&&is_array($unit)
            &&($unit['reference_id']??null)==='vp3-commerce-'.$order['id']&&($unit['payee']['merchant_id']??null)===$attempt['provider_account_id']
            &&strtoupper((string)($unit['amount']['currency_code']??''))===$currency&&$minor===$amount;
    }
    return false;
}

function vp3_profile_analytics_commerce_v1(PDO $pdo,int $owner,array $window,array $history,?array $exactOrderIds=null,array $orderNotBefore=[]): array
{
    $selection=null;
    if($exactOrderIds!==null){
        if(!array_is_list($exactOrderIds))throw new InvalidArgumentException('Exact order selection must be a list.');
        $selection=[];foreach($exactOrderIds as $id){
            if(!is_int($id)||$id<1||isset($selection[$id]))throw new InvalidArgumentException('Choose distinct exact positive order IDs.');
            $selection[$id]=true;
        }
    }
    // Only a caller with independently verified immutable creation clocks may
    // add this constraint. Legacy owner-wide order timestamps remain unknown.
    foreach($orderNotBefore as $id=>$stamp)
        if(!is_int($id)||!isset($selection[$id])||!is_int($stamp)||vp3_profile_analytics_epoch_v1($stamp)!==$stamp)
            throw new InvalidArgumentException('Choose a verified creation clock for each selected order.');
    $keys=['verified_paid_orders','verified_refunded_orders'];
    $out=['points'=>vp3_profile_analytics_empty_points_v1($keys),'coverage'=>'unconfigured','reason'=>'storage_unconfigured','evidence'=>[],'money'=>[]];
    $tables=['agent_commerce_orders_v800','agent_commerce_order_items_v800','agent_commerce_products_v800','agent_commerce_provider_connections_v800',
        'agent_commerce_checkout_attempts_v800','agent_commerce_payments_v800','agent_commerce_refunds_v800'];
    if(!vp3_profile_analytics_tables_v1($pdo,$tables))return $out;
    // account.access was freshly proved under the owner/grant locks. There is no
    // invented "commerce.access" permission. Every contributing object still
    // needs its exact current owned order/product/merchant lineage below.
    $orders=vp3_profile_analytics_rows_v1($pdo,'SELECT * FROM agent_commerce_orders_v800 WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $selectionMissing=false;
    if($selection!==null){
        $orders=array_values(array_filter($orders,static fn(array $row):bool=>isset($selection[(int)$row['id']])));
        $selectionMissing=count($orders)!==count($selection);
    }
    // Keep owner-wide receipt identity maps below: a duplicate external ID on
    // another order cannot be hidden by selecting a narrower publication.
    $items=vp3_profile_analytics_rows_v1($pdo,'SELECT i.* FROM agent_commerce_order_items_v800 i JOIN agent_commerce_orders_v800 o ON o.id=i.order_id WHERE o.owner_user_id=? ORDER BY i.id',[$owner],true);
    $products=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id FROM agent_commerce_products_v800 WHERE owner_user_id=? ORDER BY id',[$owner],true);
    $attempts=vp3_profile_analytics_rows_v1($pdo,'SELECT a.* FROM agent_commerce_checkout_attempts_v800 a JOIN agent_commerce_orders_v800 o ON o.id=a.order_id WHERE o.owner_user_id=? ORDER BY a.id',[$owner],true);
    $payments=vp3_profile_analytics_rows_v1($pdo,'SELECT p.* FROM agent_commerce_payments_v800 p JOIN agent_commerce_orders_v800 o ON o.id=p.order_id WHERE o.owner_user_id=? ORDER BY p.id',[$owner],true);
    $refunds=vp3_profile_analytics_rows_v1($pdo,'SELECT r.* FROM agent_commerce_refunds_v800 r JOIN agent_commerce_orders_v800 o ON o.id=r.order_id WHERE o.owner_user_id=? ORDER BY r.id',[$owner],true);
    $itemMap=[];$productMap=[];$orderMap=[];$attemptMap=[];$paymentMap=[];$paidMap=[];$paymentIdentity=[];$refundIdentity=[];
    foreach($items as $row)$itemMap[(int)$row['order_id']][]=$row;
    foreach($products as $row)$productMap[(int)$row['id']]=$row;
    foreach($attempts as $row)$attemptMap[(int)$row['id']]=$row;
    foreach($payments as $row){$paymentMap[(int)$row['id']]=$row;$paymentIdentity[$row['provider'].'|'.$row['external_payment_id']]=($paymentIdentity[$row['provider'].'|'.$row['external_payment_id']]??0)+1;}
    foreach($refunds as $row)$refundIdentity[$row['provider'].'|'.$row['external_refund_id']]=($refundIdentity[$row['provider'].'|'.$row['external_refund_id']]??0)+1;
    $gap=$selectionMissing;$paidGap=false;$refundGap=false;$invalidOrders=[];$orderPayments=[];$bucket=[];$currencySet=[];
    // Reconcile against all recorded completions, including those after the
    // selected cutoff. Otherwise a missing earlier ledger row can look like a
    // verified zero, or a valid later completion can invalidate an old period.
    $rawPaid=[];$rawRefunded=[];$rawPaidInvalid=[];$rawRefundedInvalid=[];
    foreach($payments as $row)if($row['status']==='completed'){
        $oid=(int)$row['order_id'];$amount=vp3_profile_analytics_uint_v1($row['amount_cents']??null);
        if($amount===null||$amount==='0')$rawPaidInvalid[$oid]=true;
        else $rawPaid[$oid]=vp3_profile_analytics_add_v1($rawPaid[$oid]??'0',$amount);
    }
    foreach($refunds as $row)if($row['status']==='completed'){
        $oid=(int)$row['order_id'];$amount=vp3_profile_analytics_uint_v1($row['amount_cents']??null);
        if($amount===null||$amount==='0')$rawRefundedInvalid[$oid]=true;
        else $rawRefunded[$oid]=vp3_profile_analytics_add_v1($rawRefunded[$oid]??'0',$amount);
    }
    $counts=['current'=>['verified_paid_orders'=>0,'verified_refunded_orders'=>0],'previous'=>['verified_paid_orders'=>0,'verified_refunded_orders'=>0]];
    foreach($orders as $order){
        $meta=vp3_profile_analytics_json_v1($order['metadata_json']??null);
        if(($meta['source']??null)!=='profile_commerce_v900'){
            // Legacy post-creation enrichment is not frozen Profile attribution.
            if($selection!==null||in_array($meta['profile_conversion_source']??null,['public_profile_booking','profile_commerce_v900'],true))$gap=true;
            continue;
        }
        $oid=(int)$order['id'];$currency=strtoupper((string)$order['currency']);$valid=preg_match('/\A[A-Z]{3}\z/D',$currency)===1;
        $valid=$valid&&vp3_profile_analytics_order_v1($pdo,$order,$itemMap[$oid]??[],$productMap,$history);
        $connection=vp3_profile_analytics_rows_v1($pdo,'SELECT id,owner_user_id,provider,external_account_id FROM agent_commerce_provider_connections_v800 WHERE id=?',[(int)($order['connection_id']??0)],true)[0]??null;
        $merchant=(int)($order['workspace_owner_user_id']??0)>0?(int)$order['workspace_owner_user_id']:$owner;
        $valid=$valid&&$connection&&(int)$connection['owner_user_id']===$merchant
            &&$connection['provider']===$order['provider_snapshot']&&$connection['external_account_id']===$order['external_account_snapshot'];
        if(!$valid){$invalidOrders[$oid]=true;$gap=true;}
        $orderMap[$oid]=$order+['_currency'=>$currency,'_valid'=>$valid];
        $canonicalPaid=vp3_profile_analytics_uint_v1($order['amount_paid_cents']??null);
        $canonicalRefunded=vp3_profile_analytics_uint_v1($order['amount_refunded_cents']??null);
        if($canonicalPaid===null||isset($rawPaidInvalid[$oid])||$canonicalPaid!==($rawPaid[$oid]??'0')){$paidGap=true;$invalidOrders[$oid]=true;}
        if($canonicalRefunded===null||isset($rawRefundedInvalid[$oid])||$canonicalRefunded!==($rawRefunded[$oid]??'0'))$refundGap=true;
    }
    foreach($payments as $payment){
        $oid=(int)$payment['order_id'];$order=$orderMap[$oid]??null;if(!$order||$payment['status']!=='completed')continue;
        $stamp=vp3_profile_analytics_utc_v1($payment['received_at']??null);
        if($stamp!==null&&$stamp>=$window['to_utc_ms'])continue;
        $amount=vp3_profile_analytics_uint_v1($payment['amount_cents']??null);$attempt=$attemptMap[(int)($payment['checkout_attempt_id']??0)]??null;
        $valid=$order['_valid']&&$stamp!==null&&$amount!==null&&$amount!=='0'&&$payment['external_payment_id']!==''
            &&(!isset($orderNotBefore[$oid])||$stamp>=$orderNotBefore[$oid])
            &&$paymentIdentity[$payment['provider'].'|'.$payment['external_payment_id']]===1&&$attempt
            &&(int)$attempt['order_id']===$oid&&(int)$attempt['connection_id']===(int)$order['connection_id']
            &&$attempt['status']==='completed'&&$attempt['provider']===$payment['provider']&&$payment['provider']===$order['provider_snapshot']
            &&$attempt['external_payment_id']===$payment['external_payment_id']&&vp3_profile_analytics_uint_v1($attempt['amount_cents'])===$amount
            &&strtoupper((string)$attempt['currency'])===$order['_currency']&&strtoupper((string)$payment['currency'])===$order['_currency']
            &&vp3_profile_analytics_attempt_v1($attempt,$order,$amount,$order['_currency']);
        if(!$valid){$paidGap=true;$invalidOrders[$oid]=true;continue;}
        $pid=(int)$payment['id'];$paidMap[$pid]=$payment+['_at'=>$stamp,'_amount'=>$amount,'_currency'=>$order['_currency']];
        $orderPayments[$oid][]=$paidMap[$pid];$side=vp3_profile_analytics_window_key_v1($stamp,$window);
        if($side!==null){
            $currency=$order['_currency'];$currencySet[$currency]=true;
            $bucket['verified_payments'][$currency][$side]=vp3_profile_analytics_add_v1($bucket['verified_payments'][$currency][$side]??'0',$amount);
            $out['evidence'][]=['payment',$pid,$oid,(int)$attempt['id'],$stamp,$amount,$currency,vp3_profile_analytics_hash_v1(vp3_profile_analytics_json_v1($order['terms_snapshot_json']))];
        }
    }
    foreach($orderPayments as $oid=>$rows){
        if(isset($invalidOrders[$oid]))continue;$total=vp3_profile_analytics_uint_v1($orderMap[$oid]['total_cents']);
        if($total===null||$total==='0')continue;
        usort($rows,static fn(array $a,array $b):int=>[$a['_at'],(int)$a['id']]<=>[$b['_at'],(int)$b['id']]);
        $sum='0';$crossed=false;
        foreach($rows as $payment){
            $sum=vp3_profile_analytics_add_v1($sum,$payment['_amount']);
            if(!$crossed&&vp3_profile_analytics_cmp_v1($sum,$total)>=0){
                $crossed=true;$side=vp3_profile_analytics_window_key_v1($payment['_at'],$window);
                if($side!==null){$counts[$side]['verified_paid_orders']++;$out['evidence'][]=['payment_completion',$oid,(int)$payment['id'],$payment['_at'],$total];}
            }
        }
    }
    usort($refunds,static fn(array $a,array $b):int=>[(string)($a['completed_at']??''),(int)$a['id']]<=>[(string)($b['completed_at']??''),(int)$b['id']]);
    $refunded=[];$refundSums=[];
    foreach($refunds as $refund){
        $oid=(int)$refund['order_id'];$order=$orderMap[$oid]??null;if(!$order||$refund['status']!=='completed')continue;
        $stamp=vp3_profile_analytics_utc_v1($refund['completed_at']??null);if($stamp!==null&&$stamp>=$window['to_utc_ms'])continue;
        $pid=(int)($refund['payment_id']??0);$payment=$paidMap[$pid]??null;$amount=vp3_profile_analytics_uint_v1($refund['amount_cents']??null);
        $valid=$order['_valid']&&$stamp!==null&&$amount!==null&&$amount!=='0'&&$payment
            &&(int)$payment['order_id']===$oid&&$stamp>=$payment['_at']&&$refund['external_refund_id']!==''
            &&$refundIdentity[$refund['provider'].'|'.$refund['external_refund_id']]===1
            &&$refund['provider']===$payment['provider']&&(int)$refund['checkout_attempt_id']===(int)$payment['checkout_attempt_id'];
        $sum=$valid?vp3_profile_analytics_add_v1($refundSums[$pid]??'0',$amount):'0';
        if(!$valid||vp3_profile_analytics_cmp_v1($sum,$payment['_amount'])>0){$refundGap=true;continue;}
        $refundSums[$pid]=$sum;$side=vp3_profile_analytics_window_key_v1($stamp,$window);
        if($side!==null){
            $currency=$order['_currency'];$currencySet[$currency]=true;$refunded[$side][$oid]=true;
            $bucket['verified_refunds'][$currency][$side]=vp3_profile_analytics_add_v1($bucket['verified_refunds'][$currency][$side]??'0',$amount);
            $out['evidence'][]=['refund',(int)$refund['id'],$oid,$pid,$stamp,$amount,$currency];
        }
    }
    $paidGap=$paidGap||$gap;$refundGap=$refundGap||$gap;
    foreach(['current','previous'] as $side){
        $counts[$side]['verified_refunded_orders']=count($refunded[$side]??[]);
        $out['points']['verified_paid_orders'][$side]=vp3_profile_analytics_point_v1($counts[$side]['verified_paid_orders'],$paidGap?'partial':'complete',$paidGap?'receipt_proof_incomplete':'complete');
        $out['points']['verified_refunded_orders'][$side]=vp3_profile_analytics_point_v1($counts[$side]['verified_refunded_orders'],$refundGap?'partial':'complete',$refundGap?'receipt_proof_incomplete':'complete');
    }
    $currencies=array_keys($currencySet);sort($currencies,SORT_STRING);
    foreach(['verified_payments','verified_refunds'] as $key)foreach($currencies as $currency){
        $coverage=($key==='verified_payments'?$paidGap:$refundGap)?'partial':'complete';
        $current=$bucket[$key][$currency]['current']??'0';$previous=$bucket[$key][$currency]['previous']??'0';
        $out['money'][]=['key'=>$key,'currency'=>$currency,'amount_unit'=>'commerce_cent','scale'=>2,'scale_source'=>'vp3.commerce.v800',
            'current'=>vp3_profile_analytics_money_point_v1($current,$coverage),'previous'=>vp3_profile_analytics_money_point_v1($previous,$coverage),
            'comparison'=>['delta_minor'=>$coverage==='complete'?vp3_profile_analytics_sub_v1($current,$previous):null]];
    }
    $limited=$paidGap||$refundGap;$out['coverage']=$limited?'limited':'ready';$out['reason']=$limited?'receipt_proof_incomplete':'complete';
    return $out;
}

function vp3_profile_analytics_group_error_v1(Throwable $error,array $keys): array
{
    if(vp3_profile_analytics_retryable_v1($error))throw $error;
    $coverage=$error->getCode()===424?'unconfigured':'unavailable';$reason=$coverage==='unconfigured'?'storage_unconfigured':'storage_unavailable';
    return ['coverage'=>$coverage,'reason'=>$reason,'points'=>vp3_profile_analytics_empty_points_v1($keys,$reason),'evidence'=>[],
        'money'=>[],'cohorts'=>vp3_profile_analytics_empty_cohorts_v1('unavailable',$reason),'breakdowns'=>vp3_profile_analytics_empty_calls_v1()];
}
function vp3_profile_analytics_backlog_v1(PDO $pdo,int $owner,int $now,?array $history=null): array
{
    $coverage='ready';$reason='complete';$count=null;
    try{
        if(!vp3_profile_analytics_table_v1($pdo,'profile_agent_conversations')){$coverage='unconfigured';$reason='storage_unconfigured';}
        else{$rows=vp3_profile_analytics_rows_v1($pdo,"SELECT id,status FROM profile_agent_conversations WHERE owner_user_id=? ORDER BY id",[$owner],true);
            $count=0;foreach($rows as $row)if(in_array($row['status'],['open','owner_joined'],true))$count++;}
    }catch(Throwable $error){if(vp3_profile_analytics_retryable_v1($error))throw $error;$coverage='unavailable';$reason='storage_unavailable';}
    $metrics=[['key'=>'current_unresolved_conversations','label'=>'Unresolved conversations now',
        'definition'=>'Owned public Profile threads currently open or joined by the owner; separate from completed reporting periods.',
        'value'=>$count,'coverage'=>$coverage==='ready'?'complete':'unavailable','reason'=>$reason]];
    $pending=null;$pendingCoverage='unavailable';$pendingReason='storage_unconfigured';
    try{
        if($history!==null&&vp3_profile_analytics_table_v1($pdo,'profile_agent_handoff_turns_pa3')){
            $rows=vp3_profile_analytics_rows_v1($pdo,"SELECT * FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND state IN ('awaiting_delivery','awaiting_owner','owner_requested','owner_claimed','agent_ready','agent_running') ORDER BY id",[$owner],true);
            $pending=0;$pendingCoverage='complete';$pendingReason='complete';
            foreach($rows as $row){
                if(!vp3_profile_analytics_turn_valid_v1($row,$history,$owner)){$pendingCoverage='partial';$pendingReason='source_proof_incomplete';continue;}
                ++$pending;
            }
        }
    }catch(Throwable $error){if(vp3_profile_analytics_retryable_v1($error))throw $error;$pending=null;$pendingCoverage='unavailable';$pendingReason='storage_unavailable';}
    $metrics[]=['key'=>'current_unanswered_visitor_turns','label'=>'Unanswered visitor turns now',
        'definition'=>'Verified native visitor turns currently awaiting an owner or Agent response; blocked and cancelled turns are excluded.',
        'value'=>$pendingCoverage==='complete'?$pending:null,'observed_value'=>$pendingCoverage==='partial'?$pending:null,
        'coverage'=>$pendingCoverage,'reason'=>$pendingReason];
    if($coverage==='ready'&&$pendingCoverage!=='complete')$coverage='limited';
    return ['as_of_utc_ms'=>$now,'revision'=>vp3_profile_analytics_hash_v1([$owner,$metrics]),'coverage'=>$coverage,'metrics'=>$metrics];
}

/**
 * Query-only fixed periods. Current locking authority reads are separate from
 * the caller's ordinary RR read view, and evaluated directly. An outer owner
 * keeps its transaction and locks; only a transaction started here is ended.
 */
function vp3_profile_analytics_period_v1(PDO $pdo,array $scope,array $selection=[],?DateTimeImmutable $now=null): array
{
    if(array_diff(array_keys($selection),['ref','period'])||(array_key_exists('ref',$selection)&&array_key_exists('period',$selection)))
        vp3_profile_analytics_period_bad_ref_v1();
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));$owns=!$pdo->inTransaction();
    try{
        if($owns){if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();}
        $scope=vp3_profile_analytics_authority_current_v1($pdo,$scope);$owner=(int)$scope['user']['id'];
        if(array_key_exists('ref',$selection)){
            if(!is_string($selection['ref']))vp3_profile_analytics_period_bad_ref_v1();
            $period=vp3_profile_analytics_period_decode_v1($selection['ref'],$now);
        }else{
            $days=$selection['period']??1;
            if((!is_int($days)&&!is_string($days))||!in_array((string)$days,['1','7','30'],true))vp3_profile_analytics_period_bad_ref_v1();
            [$zone,$source]=vp3_profile_analytics_timezone_v1($pdo,$scope);
            $timezone=new DateTimeZone($zone);
            $cutoff=vp3_profile_analytics_day_start_v1($now->setTimezone($timezone)->format('Y-m-d'),$timezone)->getTimestamp();
            $code=match($source){'explicit'=>'e','schedule'=>'s',default=>'u'};
            $period=vp3_profile_analytics_period_decode_v1(vp3_profile_analytics_period_encode_v1((int)$days,$cutoff,$zone,$code),$now);
        }
        $window=$period['window'];$catalog=vp3_profile_analytics_catalog_v1();
        $eventKeys=['visit_records','recognized_browser_contacts','recorded_product_conversions','recorded_booking_conversions'];
        $conversationKeys=['accepted_visitor_turns','active_conversations','new_conversations','owner_takeovers','owner_reply_messages','agent_reply_messages','median_first_reply_seconds'];
        $callKeys=['calls_offered','calls_accepted','calls_finished','median_call_accept_seconds'];
        $commerceKeys=['verified_paid_orders','verified_refunded_orders'];
        try{$events=vp3_profile_analytics_events_v1($pdo,$owner,$window);}catch(Throwable $error){$events=vp3_profile_analytics_group_error_v1($error,$eventKeys);}
        $history=null;$historyError=null;
        try{$history=vp3_profile_analytics_native_history_v1($pdo,$owner);}catch(Throwable $error){if(vp3_profile_analytics_retryable_v1($error))throw $error;$historyError=$error;}
        try{if($historyError)throw $historyError;$conversations=vp3_profile_analytics_conversations_v1($pdo,$owner,$window,$history);}
        catch(Throwable $error){$conversations=vp3_profile_analytics_group_error_v1($error,$conversationKeys);}
        try{if($historyError)throw $historyError;$calls=vp3_profile_analytics_calls_v1($pdo,$owner,$window,$history);}
        catch(Throwable $error){$calls=vp3_profile_analytics_group_error_v1($error,$callKeys);}
        try{$commerce=vp3_profile_analytics_commerce_v1($pdo,$owner,$window,$history??['valid'=>[],'conversations'=>[]]);}
        catch(Throwable $error){$commerce=vp3_profile_analytics_group_error_v1($error,$commerceKeys);}
        try{if($historyError)throw $historyError;$images=vp3_profile_image_analytics_v1($pdo,$owner,$window,$history);}
        catch(Throwable $error){if(vp3_profile_analytics_retryable_v1($error))throw $error;$images=vp3_profile_image_analytics_empty_v1($error->getCode()===424?'storage_unconfigured':'storage_unavailable');}
        $points=$events['points']+$conversations['points']+$calls['points']+$commerce['points']+$images['points'];$metrics=[];
        foreach($catalog as $key=>[$group,$label,$unit,$definition])$metrics[]=vp3_profile_analytics_metric_v1($key,$group,$label,$unit,$definition,$points[$key]['current'],$points[$key]['previous']);
        $groups=[];foreach(['events'=>$events,'conversations'=>$conversations,'calls'=>$calls,'commerce'=>$commerce,'images'=>$images] as $key=>$value)
            $groups[$key]=['coverage'=>$value['coverage'],'reason'=>$value['reason']];
        // Recorded conversions are displayed under Commerce, but retain their
        // independent event-clock evidence and coverage.
        if($groups['commerce']['coverage']==='ready'&&$events['coverage']!=='ready')$groups['commerce']=['coverage'=>'limited','reason'=>$events['reason']];
        elseif(in_array($groups['commerce']['coverage'],['unavailable','unconfigured'],true)&&in_array($events['coverage'],['ready','limited'],true))
            $groups['commerce']=['coverage'=>'limited','reason'=>$groups['commerce']['reason']];
        $usable=false;$complete=true;$hasUnavailable=false;
        foreach($metrics as $metric)foreach(['current','previous'] as $side){$point=$metric[$side];
            $usable=$usable||$point['coverage']==='complete'||($point['coverage']==='partial'&&$point['observed_value']!==null);
            $complete=$complete&&$point['coverage']==='complete';$hasUnavailable=$hasUnavailable||$point['reason']==='storage_unavailable';}
        $coverage=$complete?'ready':($usable?'limited':($hasUnavailable?'unavailable':'unconfigured'));
        $evidence=array_merge($events['evidence'],$conversations['evidence'],$calls['evidence'],$commerce['evidence'],$images['evidence']);
        usort($evidence,static fn(array $a,array $b):int=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));
        $money=$commerce['money']??[];$cohorts=$conversations['cohorts'];$breakdowns=$calls['breakdowns'];
        $revision=vp3_profile_analytics_hash_v1([VP3_PROFILE_ANALYTICS_PERIOD_V1,$owner,$scope['agent_id'],$scope['conversation_id'],$period['ref'],
            $coverage,$metrics,$groups,$money,$cohorts,$breakdowns,$images['usage'],$evidence]);
        $observed=$now->getTimestamp()*1000+(int)$now->format('v');$backlog=vp3_profile_analytics_backlog_v1($pdo,$owner,$observed,$history);
        $backlog['image_allowance']=vp3_profile_image_analytics_allowance_v1($pdo,$owner,$observed);
        $backlog['image_failures']=$images['failures'];
        $backlog['revision']=vp3_profile_analytics_hash_v1([$backlog['revision'],$backlog['image_allowance'],$backlog['image_failures']]);
        if($backlog['coverage']==='ready'&&($backlog['image_allowance']['coverage']!=='complete'||$backlog['image_failures']['coverage']!=='complete'))$backlog['coverage']='limited';
        $scope=vp3_profile_analytics_authority_current_v1($pdo,$scope);
        $result=['contract'=>VP3_PROFILE_ANALYTICS_PERIOD_V1,'ref'=>$period['ref'],'revision'=>$revision,'coverage'=>$coverage,'observed_at_utc_ms'=>$observed,
            'window'=>$window,'period_options'=>$period['period_options'],'metrics'=>$metrics,'groups'=>$groups,
            'first_reply_cohorts'=>$cohorts,'call_breakdowns'=>$breakdowns,'money'=>$money,'image_usage'=>$images['usage'],'backlog'=>$backlog];
        if($owns){
            $pdo->commit();
            // This is a new current authority read after the aggregate snapshot,
            // not another ordinary read from its old repeatable-read view.
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();vp3_profile_analytics_authority_current_v1($pdo,$scope);$pdo->commit();
        }
        // The workspace endpoint reopens its real session and reauthorizes after
        // this owned transaction. A coordinator caller keeps the locks until its
        // own commit and repeats the exact source check before final delivery.
        return $result;
    }catch(Throwable $error){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}
