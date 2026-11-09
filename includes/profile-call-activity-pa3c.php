<?php
declare(strict_types=1);

require_once __DIR__.'/profile-calls-pa3c.php';

const VP3_PROFILE_CALL_WORKER_LEASE_MS_PA3C = 8000;

function vp3_profile_call_activity_ensure_schema_pa3c(PDO $pdo): void
{
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_call_assistance_pa3c (
        call_id CHAR(32) PRIMARY KEY,
        capture_enabled SMALLINT NOT NULL DEFAULT 1,
        capture_revision INT NOT NULL DEFAULT 1,
        dispatch_at_ms BIGINT NOT NULL DEFAULT 0,
        dispatch_retry_ms BIGINT NOT NULL DEFAULT 0,
        worker_id CHAR(32) NOT NULL DEFAULT '',
        lease_until_ms BIGINT NOT NULL DEFAULT 0,
        ready_until_ms BIGINT NOT NULL DEFAULT 0,
        transcript_session_id BIGINT NOT NULL DEFAULT 0
    )".$suffix);
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_call_research_pa3c (
        call_id CHAR(32) NOT NULL,
        request_id VARCHAR(80) NOT NULL,
        capture_revision INT NOT NULL,
        query VARCHAR(500) NOT NULL,
        status VARCHAR(16) NOT NULL,
        started_at_ms BIGINT NOT NULL,
        result_json TEXT NULL,
        PRIMARY KEY (call_id,request_id)
    )".$suffix);
}

function vp3_profile_call_activity_schema_ready_pa3c(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT assisted,agent_name,agent_worker_name FROM profile_calls_pa3c WHERE 1=0');
        $pdo->query('SELECT capture_revision,transcript_session_id FROM profile_call_assistance_pa3c WHERE 1=0');
        $pdo->query('SELECT capture_revision,result_json FROM profile_call_research_pa3c WHERE 1=0');
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

function vp3_profile_call_assistance_ready_pa3c(PDO $pdo): bool
{
    if(getenv('VP3_PROFILE_CALL_AGENT_ENABLED')!=='1'||strlen(vp3_profile_call_worker_secret_pa3c())<32
        ||vp3_profile_call_worker_name_pa3c()===''||!vp3_profile_call_activity_schema_ready_pa3c($pdo))return false;
    if(!function_exists('artist_listening_v172_schema_ready'))require_once __DIR__.'/artist-listening.php';
    if(!artist_listening_v172_schema_ready())return false;
    if(!function_exists('vp3_profile_call_livekit_readiness_pa3c'))require_once __DIR__.'/profile-calls-livekit-pa3c.php';
    return function_exists('vp3_profile_call_livekit_assistance_configured_pa3c')&&vp3_profile_call_livekit_assistance_configured_pa3c();
}

function vp3_profile_call_worker_secret_pa3c(): string
{return trim((string)getenv('VP3_PROFILE_CALL_WORKER_SECRET'));}

function vp3_profile_call_worker_name_pa3c(): string
{
    $name=(string)getenv('VP3_PROFILE_CALL_AGENT_NAME');
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D',$name)?$name:'';
}

/** An ordinary call action carries the version of the notice rendered with its controls. */
function vp3_profile_call_assistance_offer_pa3c(PDO $pdo,int $owner,array $agent,bool $lock=false): ?array
{
    $agentId=(int)($agent['id']??0);
    if($agentId<1||!vp3_profile_call_assistance_ready_pa3c($pdo)||!vp3_profile_call_owner_assistance_allowed_pa3c($pdo,$owner,$agentId,$lock))return null;
    $name=mb_substr(trim((string)($agent['display_name']??'')),0,100)?:'Profile Agent';
    $binding=json_encode([$owner,$agentId,$name,vp3_profile_call_worker_name_pa3c(),vp3_profile_call_provider_ready_pa3c()['config_fingerprint']??''],JSON_THROW_ON_ERROR);
    return ['agent'=>['name'=>$name],'notice'=>hash_hmac('sha256','profile-call-offer|'.$binding,vp3_profile_call_worker_secret_pa3c())];
}

function vp3_profile_call_assistance_incoming_notice_pa3c(array $call): string
{
    $secret=vp3_profile_call_worker_secret_pa3c();
    return strlen($secret)>=32?hash_hmac('sha256','profile-call-incoming|'.$call['public_id'].'|'.$call['binding_hash'],$secret):'';
}

function vp3_profile_call_assistance_projection_pa3c(array $call,string $role): ?array
{
    if(empty($call['assisted'])||$call['mode']!=='audio')return null;
    $out=['agent'=>['name'=>(string)$call['agent_name']]];
    if($role==='owner')$out['id']=(string)$call['public_id'];
    elseif($call['state']==='offered'&&$call['offered_by']==='owner')$out['notice']=vp3_profile_call_assistance_incoming_notice_pa3c($call);
    return $out;
}

function vp3_profile_call_owner_assistance_allowed_pa3c(PDO $pdo,int $ownerId,int $agentId,bool $lock=false): bool
{
    if(!function_exists('chat_settings_agent_assisted_phone_chat_enabled_v237'))require_once __DIR__.'/chat-settings-v237.php';
    if (!chat_settings_agent_assisted_phone_chat_enabled_v237($pdo,$ownerId,$lock)) return false;
    $owner=profile_user_row($pdo,$ownerId);
    if (!$owner||empty($owner['is_active'])||!has_permission('artist_listening.access',$owner)) return false;
    // Workers and visitors use stored policy only. A paired automatic route
    // needs an explicit Cloud choice before guest speech can go to Cloud STT.
    if (!function_exists('agent_compute_v023_policy_from_values')) return false;
    try {
        $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
        $account=$pdo->prepare('SELECT preference FROM agent_compute_preferences WHERE user_id=?'.$suffix);
        $account->execute([$ownerId]);
        $override=$pdo->prepare('SELECT preference FROM agent_compute_overrides WHERE user_id=? AND agent_id=?'.$suffix);
        $override->execute([$ownerId,$agentId]);
        $preference=agent_compute_v023_policy_from_values((string)($account->fetchColumn()?:'auto'),(string)($override->fetchColumn()?:'inherit'));
        $effective=(string)($preference['effective_preference']??'');
        if ($effective==='vp3_cloud') return true;
        if ($effective!=='auto') return false;
        $pairing=$pdo->prepare('SELECT 1 FROM homeserver_connections WHERE user_id=?'.$suffix);
        $pairing->execute([$ownerId]);
        return !$pairing->fetchColumn();
    } catch (Throwable $error) {
        return false;
    }
}

function vp3_profile_call_activity_begin_pa3c(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
}

function vp3_profile_call_assistance_by_id_pa3c(PDO $pdo,string $id): ?array
{
    $query=$pdo->prepare('SELECT * FROM profile_calls_pa3c WHERE public_id=?');
    $query->execute([$id]);
    return $query->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_profile_call_assistance_media_current_pa3c(PDO $pdo,array $call,bool $lock=false): bool
{
    if(!in_array($call['state'],['preparing','ready','active'],true))return false;
    try{vp3_profile_call_media_current_pa3c($pdo,$call,$lock);return true;}
    catch(VP3ProfileCallException $error){return false;}
}

function vp3_profile_call_assistance_live_proof_pa3c(array $call): bool
{
    $now=vp3_profile_call_now_pa3c();$observed=(int)$call['provider_observed_at_ms'];
    return $call['state']==='active'&&$observed<=$now&&$observed>=$now-15000
        &&(int)$call['owner_connected']===1&&(int)$call['visitor_connected']===1
        &&(int)$call['owner_authority_until_ms']>$now&&(int)$call['visitor_authority_until_ms']>$now
        &&preg_match('/^RM_[A-Za-z0-9_-]{1,90}$/D',(string)$call['room_sid'])===1;
}

function vp3_profile_call_assistance_lock_current_pa3c(PDO $pdo,string $id): ?array
{
    $expected=vp3_profile_call_assistance_by_id_pa3c($pdo,$id);
    if(!$expected||empty($expected['assisted'])||!in_array($expected['state'],['preparing','ready','active'],true))return null;
    try{$current=vp3_profile_call_locked_pa3c($pdo,$expected);}
    catch(VP3ProfileCallException $error){return null;}
    return vp3_profile_call_assistance_media_current_pa3c($pdo,$current,true)?$current:null;
}

function vp3_profile_call_activity_row_pa3c(PDO $pdo,string $id,bool $lock=false): ?array
{
    $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
    $query=$pdo->prepare('SELECT * FROM profile_call_assistance_pa3c WHERE call_id=?'.$suffix);
    $query->execute([$id]);
    return $query->fetch(PDO::FETCH_ASSOC)?:null;
}

/** Call row is locked before allocating or changing its assistance state. */
function vp3_profile_call_activity_ensure_row_pa3c(PDO $pdo,string $id): array
{
    $row=vp3_profile_call_activity_row_pa3c($pdo,$id,true);
    if ($row) return $row;
    $pdo->prepare('INSERT INTO profile_call_assistance_pa3c (call_id) VALUES (?)')->execute([$id]);
    return vp3_profile_call_activity_row_pa3c($pdo,$id,true)??throw new RuntimeException('Assistance could not be initialized.');
}

function vp3_profile_call_worker_key_pa3c(array $call): string
{
    return hash_hmac('sha256','vp3-profile-call|'.$call['public_id'].'|'.$call['room_name'].'|'.$call['binding_hash'],vp3_profile_call_worker_secret_pa3c());
}

function vp3_profile_call_try_dispatch_pa3c(PDO $pdo,array $call): void
{
    if (empty($call['assisted'])||!vp3_profile_call_assistance_live_proof_pa3c($call)||!vp3_profile_call_assistance_ready_pa3c($pdo)) return;
    $id=(string)$call['public_id'];
    $now=vp3_profile_call_now_pa3c();
    $snapshot=vp3_profile_call_activity_row_pa3c($pdo,$id);
    if ($snapshot&&((int)$snapshot['dispatch_retry_ms']>$now||(int)$snapshot['lease_until_ms']>$now||empty($snapshot['capture_enabled']))) return;
    $token=bin2hex(random_bytes(16));
    $deadline=$now+12000;
    vp3_profile_call_activity_begin_pa3c($pdo);
    try {
        $current=vp3_profile_call_assistance_lock_current_pa3c($pdo,$id);
        if (!$current||!vp3_profile_call_assistance_live_proof_pa3c($current)||!vp3_profile_call_activity_can_capture_pa3c($pdo,$current,true)
            ||((string)$current['provider_lease_token']!==''&&(int)$current['provider_lease_until_ms']>$now)) {
            $pdo->commit();
            return;
        }
        $row=vp3_profile_call_activity_ensure_row_pa3c($pdo,$id);
        if ((int)$row['dispatch_retry_ms']>$now||(int)$row['lease_until_ms']>$now||empty($row['capture_enabled'])) {
            $pdo->commit();
            return;
        }
        $pdo->prepare('UPDATE profile_call_assistance_pa3c SET dispatch_at_ms=?,dispatch_retry_ms=? WHERE call_id=?')
            ->execute([(int)$row['dispatch_at_ms']?:$now,$now+30000,$id]);
        // CreateDispatch may create a missing room. Use the canonical provider lease
        // so call cleanup cannot overtake a bounded dispatch request.
        $pdo->prepare("UPDATE profile_calls_pa3c SET provider_lease_token=?,provider_lease_kind='assistant',provider_lease_until_ms=? WHERE id=?")
            ->execute([$token,$deadline,(int)$current['id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    $attempted=false;
    $dispatched=false;
    try {
        $fresh=vp3_profile_call_assistance_by_id_pa3c($pdo,$id);
        $latest=vp3_profile_call_activity_row_pa3c($pdo,$id);
        if(!$fresh||!$latest||empty($latest['capture_enabled'])||!hash_equals((string)$fresh['provider_lease_token'],$token)
            ||!vp3_profile_call_assistance_live_proof_pa3c($fresh)||!vp3_profile_call_activity_can_capture_pa3c($pdo,$fresh)
            ||!vp3_profile_call_assistance_media_current_pa3c($pdo,$fresh)||vp3_profile_call_now_pa3c()>=$deadline)return;
        $attempted=true;
        vp3_profile_call_livekit_dispatch_assistant_pa3c(vp3_profile_call_binding_pa3c($fresh),(string)$fresh['agent_worker_name'],[
            'vp3_profile_call_id'=>$id,'room_name'=>(string)$fresh['room_name'],
            'agent_display_name'=>(string)$fresh['agent_name'],'worker_key'=>vp3_profile_call_worker_key_pa3c($fresh),
        ],(int)($row['dispatch_at_ms']??0)>0,$deadline);
        $dispatched=true;
    } finally {
        vp3_profile_handoff_atomic_pa3($pdo,function()use($pdo,$current,$token,$attempted,$dispatched){
            $final=vp3_profile_call_locked_pa3c($pdo,$current,true);
            if(hash_equals((string)$final['provider_lease_token'],$token)){
                // Retain the lease/cleanup fence when an unconfirmed request
                // may still complete after the current call started ending.
                if($final['state']!=='ending'||!$attempted||$dispatched)vp3_profile_call_settled_pa3c($pdo,$final,$token);
            }elseif($attempted&&in_array($final['state'],['ending','ended'],true)){
                vp3_profile_call_late_create_pa3c($pdo,$current,$token);
            }
        });
    }
}

function vp3_profile_call_activity_can_capture_pa3c(PDO $pdo,array $call,bool $lock=false): bool
{
    return !empty($call['assisted'])&&$call['mode']==='audio'&&hash_equals((string)$call['agent_worker_name'],vp3_profile_call_worker_name_pa3c())&&vp3_profile_call_assistance_ready_pa3c($pdo)
        &&vp3_profile_call_owner_assistance_allowed_pa3c($pdo,(int)$call['owner_user_id'],(int)$call['profile_agent_id'],$lock);
}

function vp3_profile_call_worker_pa3c(PDO $pdo,array $input): array
{
    $id=is_string($input['call_id']??null)?$input['call_id']:'';
    $worker=is_string($input['worker_id']??null)?$input['worker_id']:'';
    $action=$input['action']??'';
    if (!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{32}$/D',$worker)||!in_array($action,['lease','segment'],true)) {
        throw new InvalidArgumentException('Invalid worker request.');
    }
    vp3_profile_call_activity_begin_pa3c($pdo);
    try {
        $call=vp3_profile_call_assistance_lock_current_pa3c($pdo,$id);
        $result=['active'=>false];
        if ($call&&vp3_profile_call_assistance_live_proof_pa3c($call)&&is_string($input['room_name']??null)&&hash_equals((string)$call['room_name'],$input['room_name'])
            &&is_string($input['worker_key']??null)&&hash_equals(vp3_profile_call_worker_key_pa3c($call),$input['worker_key'])
            &&vp3_profile_call_activity_can_capture_pa3c($pdo,$call,true)) {
            $row=vp3_profile_call_activity_row_pa3c($pdo,$id,true);
            if ($row&&(int)$row['dispatch_at_ms']>0) $result=vp3_profile_call_worker_locked_pa3c($pdo,$call,$row,$worker,$action,$input);
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function vp3_profile_call_worker_locked_pa3c(PDO $pdo,array $call,array $row,string $worker,string $action,array $input): array
{
    $now=vp3_profile_call_now_pa3c();
    $id=(string)$call['public_id'];
    $ownerIdentity=(string)$call['owner_identity'];
    $visitorIdentity=(string)$call['visitor_identity'];
    $revision=(int)$row['capture_revision'];
    $sameRevision=($input['capture_revision']??null)===$revision;
    $sameWorker=hash_equals((string)$row['worker_id'],$worker);
    $fresh=(int)$row['lease_until_ms']>$now;
    $capture=!empty($row['capture_enabled']);
    if ($fresh&&!$sameWorker) return ['active'=>false];
    if ($action==='lease') {
        if ($sameWorker&&!$fresh&&(int)$row['lease_until_ms']>0) return ['active'=>false];
        $authorityUntil=min((int)$call['provider_observed_at_ms']+15000,(int)$call['owner_authority_until_ms'],(int)$call['visitor_authority_until_ms']);
        $seconds=intdiv(min(VP3_PROFILE_CALL_WORKER_LEASE_MS_PA3C,max(0,$authorityUntil-$now)),1000);
        if($seconds<1)return ['active'=>false];
        $expires=$now+$seconds*1000;
        $ready=$capture&&$sameRevision&&($input['connected']??false)===true&&($input['ready']??false)===true;
        $pdo->prepare('UPDATE profile_call_assistance_pa3c SET worker_id=?,lease_until_ms=?,ready_until_ms=? WHERE call_id=?')
            ->execute([$worker,$expires,$ready?min($expires,$now+5000):0,$id]);
        return [
            'active'=>true,'capture'=>$capture,'capture_revision'=>$revision,'lease_seconds'=>$seconds,
            'participants'=>[$ownerIdentity,$visitorIdentity],
            'elapsed_ms'=>max(0,$now-(int)$call['accepted_at_ms']),
        ];
    }
    if (!$capture||!$fresh||!$sameWorker||!$sameRevision) return ['active'=>$fresh&&$sameWorker,'accepted'=>0];
    $identity=$input['participant_identity']??'';
    if ($identity!==$ownerIdentity&&$identity!==$visitorIdentity) return ['active'=>true,'accepted'=>0];
    return ['active'=>true]+vp3_profile_call_segment_pa3c($pdo,$call,$row,$identity===$ownerIdentity,$input);
}

function vp3_profile_call_segment_pa3c(PDO $pdo,array $call,array $row,bool $ownerTrack,array $input): array
{
    $text=is_string($input['text']??null)?mb_strimwidth(trim($input['text']),0,8000,''):'';
    $source=is_string($input['source_key']??null)?$input['source_key']:'';
    if ($text===''||!preg_match('/^[a-f0-9]{64}$/D',$source)) return ['accepted'=>0];
    $start=$input['start_ms']??null;
    $end=$input['end_ms']??null;
    $latest=vp3_profile_call_now_pa3c()-(int)$call['accepted_at_ms']+10000;
    if (!is_int($start)||!is_int($end)||$start<0||$end<$start||$end>$latest) return ['accepted'=>0];
    $ownerId=(int)$call['owner_user_id'];
    $session=(int)$row['transcript_session_id'];
    $speakerId=$ownerId;
    if (!$ownerTrack) {
        $visitorId=(int)$call['visitor_user_id'];
        $disclosed=$pdo->prepare('SELECT identity_disclosed FROM profile_visit_sessions WHERE id=? AND owner_user_id=? AND visitor_user_id=?');
        $disclosed->execute([(int)$call['profile_session_id'],$ownerId,$visitorId]);
        $speakerId=!empty($disclosed->fetchColumn())?$visitorId:0;
    }
    $speaker=$speakerId>0?profile_user_row($pdo,$speakerId):null;
    $fallback=$ownerTrack?'Profile owner':'Profile visitor';
    $name=trim((string)($speaker['display_name']??''));
    $name=$name===''?$fallback:mb_strimwidth($name,0,68,'').($ownerTrack?' (owner)':' (visitor)');
    $callId=(string)$call['public_id'];
    $confidence=is_numeric($input['confidence']??null)?max(0.0,min(1.0,(float)$input['confidence'])):null;
    if ($session===0) {
        $metadata=json_encode(['source'=>'profile_call_pa3c','profile_call_id'=>$callId,'audio_retained'=>false],JSON_THROW_ON_ERROR);
        $pdo->prepare("INSERT INTO artist_transcript_sessions_v172 (owner_user_id,created_by_user_id,client_session_key,title,status,language,metadata_json,started_at,last_activity_at) VALUES (?,?,?,?,'draft','en-US',?,?,?)")
            ->execute([$ownerId,$ownerId,'profile-call-'.$callId,'Profile call · '.gmdate('Y-m-d',(int)($call['accepted_at_ms']/1000)),
                $metadata,gmdate('Y-m-d H:i:s',(int)($call['accepted_at_ms']/1000)),gmdate('Y-m-d H:i:s')]);
        $session=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE profile_call_assistance_pa3c SET transcript_session_id=? WHERE call_id=?')->execute([$session,$callId]);
    }
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
    $lock=$pdo->prepare('SELECT id FROM artist_transcript_sessions_v172 WHERE id=? AND created_by_user_id=? AND discarded_at IS NULL'.$suffix);
    $lock->execute([$session,$ownerId]);
    if (!$lock->fetchColumn()) return ['accepted'=>0];
    $key=hash('sha256',($ownerTrack?'owner':'visitor').'|'.$source);
    $existing=$pdo->prepare('SELECT id FROM artist_transcript_segments_v172 WHERE session_id=? AND client_segment_key=?');
    $existing->execute([$session,$key]);
    if ($existing->fetchColumn()) return ['accepted'=>0,'duplicate'=>true];
    $sequence=$pdo->prepare('SELECT COALESCE(MAX(segment_index),0)+1 FROM artist_transcript_segments_v172 WHERE session_id=?');
    $sequence->execute([$session]);
    $pdo->prepare("INSERT INTO artist_transcript_segments_v172 (session_id,client_segment_key,segment_index,segment_type,speaker_label,transcript_text,started_ms,ended_ms,confidence) VALUES (?,?,?,'transcript',?,?,?,?,?)")
        ->execute([$session,$key,(int)$sequence->fetchColumn(),$name,$text,$start,$end,$confidence]);
    $pdo->prepare('UPDATE artist_transcript_sessions_v172 SET duration_ms=CASE WHEN duration_ms<? THEN ? ELSE duration_ms END,last_activity_at=? WHERE id=?')
        ->execute([$end,$end,gmdate('Y-m-d H:i:s'),$session]);
    return ['accepted'=>1];
}

function vp3_profile_call_activity_owner_pa3c(PDO $pdo,int $ownerId,string $id): array
{
    if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new InvalidArgumentException('Invalid call.');
    $call=vp3_profile_call_assistance_by_id_pa3c($pdo,$id);
    if (!$call||(int)$call['owner_user_id']!==$ownerId||empty($call['assisted'])) {
        throw new RuntimeException('Assisted call not found.',404);
    }
    return $call;
}

function vp3_profile_call_research_ready_pa3c(): bool
{
    $provider=ai_active_provider();
    return in_array($provider,['openai','anthropic'],true)&&ai_provider_ready($provider);
}

function vp3_profile_call_activity_view_pa3c(PDO $pdo,array $call,int $after): array
{
    $id=(string)$call['public_id'];
    $now=vp3_profile_call_now_pa3c();
    $row=vp3_profile_call_activity_row_pa3c($pdo,$id);
    $live=vp3_profile_call_assistance_media_current_pa3c($pdo,$call);
    $verified=$live&&vp3_profile_call_assistance_live_proof_pa3c($call);
    $eligible=$live&&vp3_profile_call_activity_can_capture_pa3c($pdo,$call);
    $enabled=$eligible&&(!$row||!empty($row['capture_enabled']));
    $researchAllowed=$enabled&&$verified&&vp3_profile_call_research_ready_pa3c();
    $state='connecting';
    if (!$live&&($call['state']!=='offered'||vp3_profile_call_expiry_reason_pa3c($call)!=='')) $state='ended';
    elseif ($live&&!$eligible) $state='unavailable';
    elseif ($eligible&&!$enabled) $state='paused';
    elseif ($verified&&$row&&(int)$row['ready_until_ms']>$now) $state='listening';
    elseif ($row&&(int)$row['dispatch_at_ms']>0&&$now-(int)$row['dispatch_at_ms']>20000&&(int)$row['lease_until_ms']<=$now) $state='unavailable';

    $research=[];
    $query=$pdo->prepare('SELECT * FROM profile_call_research_pa3c WHERE call_id=? ORDER BY started_at_ms,request_id LIMIT 12');
    $query->execute([$id]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $result) {
        $status=(string)$result['status'];
        if ($status==='running') {
            if (!$enabled||!$verified||$now-(int)$result['started_at_ms']>90000) $status='failed';
            else $state='researching';
        }
        $payload=json_decode((string)($result['result_json']??''),true);
        $research[]=[
            'id'=>(string)$result['request_id'],'query'=>(string)$result['query'],'status'=>$status,
            'summary'=>(string)($payload['summary']??''),'sources'=>(array)($payload['sources']??[]),
        ];
    }

    $segments=[];
    $hasMore=false;
    $transcriptUrl='';
    $session=(int)($row['transcript_session_id']??0);
    if ($session>0) {
        $check=$pdo->prepare('SELECT id FROM artist_transcript_sessions_v172 WHERE id=? AND created_by_user_id=? AND discarded_at IS NULL');
        $check->execute([$session,(int)$call['owner_user_id']]);
        if ($check->fetchColumn()) {
            $transcriptUrl=url('/artist-listening.php?session='.$session);
            $query=$pdo->prepare("SELECT id,speaker_label,transcript_text,started_ms FROM artist_transcript_segments_v172 WHERE session_id=? AND id>? AND segment_type='transcript' ORDER BY id LIMIT 101");
            $query->execute([$session,$after]);
            $rows=$query->fetchAll(PDO::FETCH_ASSOC);
            $hasMore=count($rows)>100;
            foreach (array_slice($rows,0,100) as $segment) {
                $after=(int)$segment['id'];
                $segments[]=['id'=>$after,'speaker'=>(string)$segment['speaker_label'],
                    'text'=>(string)$segment['transcript_text'],'start_ms'=>(int)$segment['started_ms']];
            }
        }
    }
    return [
        'call_id'=>$id,'state'=>$state,'agent'=>['name'=>(string)$call['agent_name']],
        'assistance_enabled'=>$enabled,'assistance_toggle_available'=>$eligible,
        'assistance_revision'=>(int)($row['capture_revision']??1),
        'segments'=>$segments,'cursor'=>$after,'has_more'=>$hasMore,'research'=>$research,
        'research_available'=>$researchAllowed,'transcript_url'=>$transcriptUrl,
    ];
}

function vp3_profile_call_assistance_toggle_pa3c(PDO $pdo,int $ownerId,string $id,bool $enabled,int $expectedRevision): array
{
    vp3_profile_call_activity_begin_pa3c($pdo);
    try {
        $call=vp3_profile_call_assistance_lock_current_pa3c($pdo,$id);
        if (!$call||(int)$call['owner_user_id']!==$ownerId||!vp3_profile_call_activity_can_capture_pa3c($pdo,$call,true)) {
            throw new RuntimeException('Assistance is unavailable for this call.',409);
        }
        $row=vp3_profile_call_activity_ensure_row_pa3c($pdo,$id);
        $revision=(int)$row['capture_revision'];
        if ((bool)$row['capture_enabled']!==$enabled) {
            if ($revision!==$expectedRevision) throw new RuntimeException('Agent assistance changed. Try again after it refreshes.',409);
            $pdo->prepare('UPDATE profile_call_assistance_pa3c SET capture_enabled=?,capture_revision=capture_revision+1,ready_until_ms=0 WHERE call_id=?')
                ->execute([(int)$enabled,$id]);
            $pdo->prepare("UPDATE profile_call_research_pa3c SET status='failed' WHERE call_id=? AND status='running'")->execute([$id]);
            $revision++;
        }
        $pdo->commit();
        return ['assistance_enabled'=>$enabled,'assistance_revision'=>$revision];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function vp3_profile_call_research_pa3c(PDO $pdo,array $user,string $id,string $request,string $query): array
{
    $query=trim($query);
    if ($query===''||mb_strlen($query)>500||!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$request)) {
        throw new InvalidArgumentException('Enter a research question of up to 500 characters.');
    }
    if (!vp3_profile_call_research_ready_pa3c()) throw new RuntimeException('Public research is unavailable.',503);
    $ownerId=(int)$user['id'];
    $now=vp3_profile_call_now_pa3c();
    vp3_profile_call_activity_begin_pa3c($pdo);
    try {
        $call=vp3_profile_call_assistance_lock_current_pa3c($pdo,$id);
        if (!$call||!vp3_profile_call_assistance_live_proof_pa3c($call)||(int)$call['owner_user_id']!==$ownerId||!vp3_profile_call_activity_can_capture_pa3c($pdo,$call,true)) {
            throw new RuntimeException('Research is unavailable for this call.',409);
        }
        $row=vp3_profile_call_activity_ensure_row_pa3c($pdo,$id);
        if (empty($row['capture_enabled'])) throw new RuntimeException('Resume Agent assistance to research.',409);
        $revision=(int)$row['capture_revision'];
        $pdo->prepare("UPDATE profile_call_research_pa3c SET status='failed' WHERE call_id=? AND status='running' AND started_at_ms<?")->execute([$id,$now-90000]);
        $existing=$pdo->prepare('SELECT request_id,query,status,started_at_ms FROM profile_call_research_pa3c WHERE call_id=?');
        $existing->execute([$id]);
        $requests=$existing->fetchAll(PDO::FETCH_ASSOC);
        foreach ($requests as $item) {
            if (hash_equals((string)$item['request_id'],$request)) {
                if (!hash_equals((string)$item['query'],$query)) throw new RuntimeException('That research request has already been used.',409);
                $pdo->commit();
                return ['research_status'=>(string)$item['status']];
            }
        }
        foreach ($requests as $item) if ($item['status']==='running'||(int)$item['started_at_ms']>$now-15000) {
            throw new RuntimeException('Please wait before starting another research request.',429);
        }
        if (count($requests)>=12) throw new RuntimeException('This call has reached its research limit.',429);
        $pdo->prepare("INSERT INTO profile_call_research_pa3c (call_id,request_id,capture_revision,query,status,started_at_ms) VALUES (?,?,?,?,'running',?)")
            ->execute([$id,$request,$revision,$query,$now]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    // The owner supplies the only public search text. No transcript, private
    // Agent context, identifiers or credentials are attached to that query.
    try {
        require_once __DIR__.'/artist-listening-transcript.php';
        $result=artist_listening_v237_research([$query],$user,publicProfile:true);
        $sources=[];
        foreach ((array)($result['sources']??[]) as $source) {
            $href=(string)($source['url']??'');
            if (strlen($href)>2048||!filter_var($href,FILTER_VALIDATE_URL)||!str_starts_with($href,'https://')) continue;
            $sources[]=['url'=>$href,'title'=>mb_strimwidth((string)($source['title']??$href),0,220,'')];
        }
        $summary=trim((string)($result['text']??''));
        $payload=$sources&&$summary!==''&&empty($result['error'])?['summary'=>$summary,'sources'=>$sources]:null;
    } catch (Throwable $error) {
        $payload=null;
    }

    vp3_profile_call_activity_begin_pa3c($pdo);
    try {
        $current=vp3_profile_call_assistance_lock_current_pa3c($pdo,$id);
        $row=$current?vp3_profile_call_activity_row_pa3c($pdo,$id,true):null;
        $keep=$payload&&$row&&!empty($row['capture_enabled'])&&(int)$row['capture_revision']===$revision
            &&(int)$current['owner_user_id']===$ownerId&&vp3_profile_call_assistance_live_proof_pa3c($current)&&vp3_profile_call_activity_can_capture_pa3c($pdo,$current,true);
        $status=$keep?'complete':'failed';
        $write=$pdo->prepare("UPDATE profile_call_research_pa3c SET status=?,result_json=? WHERE call_id=? AND request_id=? AND capture_revision=? AND status='running'");
        $write->execute([$status,$keep?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,$id,$request,$revision]);
        if ($write->rowCount()!==1) $status='failed';
        $pdo->commit();
        return ['research_status'=>$status];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
