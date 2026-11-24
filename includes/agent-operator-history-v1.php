<?php
declare(strict_types=1);

// Operator history reads the original owner's Phone ledger. No new store,
// transcription, provider call, model invocation, or task/calendar mutation.
require_once __DIR__.'/agent-operator-specialist-v1.php';
require_once __DIR__.'/phone-recording-pipeline-v1.php';

/** Verify the saved recording source, not an inferred match by caller number. */
function vp3_operator_recording_evidence_v1(PDO $pdo,array $user,int $agentId,array $job,array $call): ?array
{
    if ((int)$job['owner_user_id']!==(int)$user['id']
        || ($agentId>0 && (int)$job['agent_id']!==$agentId)
        || (int)$job['agent_id']!==(int)$call['agent_id']
        || (int)$job['call_id']!==(int)$call['id']
        || (string)$job['origin_kind']!==(string)$call['source_kind']
        || (string)$job['call_public_id']!==(string)$call['public_id']) return null;
    try {
        $source=vp3_phone_recording_call_v1($pdo,$job);
        if ((string)$source['public_id']!==(string)$call['public_id']) return null;
        if(($source['transport_kind']??'')==='managed_callback'){
            require_once __DIR__.'/phone-managed-recording-v1.php';
            $policy=vp3_managed_recording_saved_policy_v1($pdo,$source,(string)$job['provider_recording_sid']);
            if($policy!==vp3_phone_recording_decode_v1($job['policy_json'])||(int)$job['conversation_id']!==(int)$source['conversation_id'])return null;
        }
    } catch (RuntimeException $error) {return null;}
    $state=(string)$job['state'];
    $out=['state'=>match($state){
        'queued'=>'queued','transcribing','analyzing'=>(int)$job['lease_until']>time()?'processing':'unavailable',
        'ready'=>'ready','failed'=>'failed',default=>'unavailable',
    },'transcript_saved'=>false,'report_saved'=>false];
    $session=(int)$job['session_id'];
    if ($session>0 && has_permission('artist_listening.access',$user)) {
        $q=$pdo->prepare("SELECT metadata_json FROM artist_transcript_sessions_v172 WHERE id=? AND created_by_user_id=? AND discarded_at IS NULL");
        $q->execute([$session,(int)$user['id']]);
        $meta=vp3_phone_recording_decode_v1($q->fetchColumn());
        if (($meta['source']??'')==='phone_call_v1' && (int)($meta['phone_recording_v1']['job_id']??0)===(int)$job['id']
            && ($meta['phone_recording_v1']['call_kind']??null)===$job['origin_kind']
            && ($meta['phone_recording_v1']['call_id']??null)===$job['call_public_id']
            && (int)($meta['phone_recording_v1']['agent_id']??0)===(int)$job['agent_id']
            && preg_match('/^[a-f0-9]{64}$/D',(string)$job['transcript_hash'])) {
            $q=$pdo->prepare("SELECT 1 FROM artist_transcript_segments_v172 WHERE session_id=? AND segment_type='transcript' AND transcript_text IS NOT NULL AND transcript_text<>'' LIMIT 1");
            $q->execute([$session]);$out['transcript_saved']=(bool)$q->fetchColumn();
        }
    }
    // A report is a saved, unreviewed historical artifact, not a verified
    // current analysis. Never expose its text or a cross-Agent Chat pointer.
    if ($state==='ready' && (int)$job['report_message_id']>0 && (int)$job['conversation_id']>0
        && ((string)$job['transcript_hash']==='' || $out['transcript_saved'])) {
        $q=$pdo->prepare("SELECT m.context_json FROM phone_browser_deliveries_v1 d
            JOIN chat_messages m ON m.id=d.chat_message_id AND m.conversation_id=d.conversation_id AND m.role='assistant'
            JOIN chat_conversations c ON c.id=d.conversation_id AND c.user_id=d.owner_user_id AND COALESCE(c.user_agent_id,0)=d.chat_agent_id
            WHERE d.owner_user_id=? AND d.event_key=? AND d.chat_message_id=? AND d.conversation_id=?"
            .($agentId>0?' AND d.chat_agent_id=?':''));
        $args=[(int)$user['id'],'phone-recording-report:'.$job['public_id'],(int)$job['report_message_id'],(int)$job['conversation_id']];
        if ($agentId>0) $args[]=$agentId;
        $q->execute($args);$context=vp3_phone_recording_decode_v1($q->fetchColumn())['phone_v1']??[];
        $out['report_saved']=($context['kind']??null)==='voicemail'
            && ($context['call_id']??null)===$call['public_id']
            && ($context['voicemail_id']??null)==='job-'.(int)$job['id']
            && is_array($context['report']??null)
            && ($context['report']['source_hash']??null)===(string)$job['transcript_hash'];
    }
    return $out;
}

/** Only the explicitly opened private Operator view gets contact details. */
function vp3_operator_history_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    $empty=['coverage'=>'unavailable','recording_coverage'=>'unavailable','calls'=>[]];
    if ($cid<1) return array_replace($empty,['coverage'=>'no_chat']);
    try {
        $scope=vp3_phone_workspace_scope_v1($pdo,$user,['agent_id'=>$agentId,'conversation_id'=>$cid]);
        if (!vp3_phone_package_v100($pdo,$scope['owner_user_id'])['voice']) return $empty;
        $sql="SELECT * FROM phone_browser_calls_v1 WHERE owner_user_id=? AND provider_call_sid IS NOT NULL
            AND ended_epoch IS NOT NULL AND state IN ('completed','busy','no_answer','failed','canceled')";
        $args=[$scope['owner_user_id']];
        if ($agentId>0) {$sql.=' AND agent_id=?';$args[]=$agentId;}
        $q=$pdo->prepare($sql.' ORDER BY created_epoch DESC,id DESC LIMIT 50');$q->execute($args);
        $browserRows=$q->fetchAll(PDO::FETCH_ASSOC);
        $browserById=array_column($browserRows,null,'id');
        $calls=array_map('vp3_phone_workspace_call_projection_v1',$browserRows);
        foreach (vp3_phone_workspace_legacy_calls_v1($pdo,$scope,true) as $legacy) $calls[]=$legacy;
        usort($calls,static fn(array $a,array $b):int=>strcmp($b['created_at'],$a['created_at'])
            ?:strcmp($a['source_kind'],$b['source_kind'])?:($b['id']<=>$a['id']));
        $calls=array_slice($calls,0,50);
        $known=vp3_phone_caller_contacts_lookup_v1($pdo,$scope['owner_user_id'],array_column($calls,'peer_e164'));
        $recordingReady=vp3_phone_recording_schema_ready_v1($pdo);$recordingCoverage=$recordingReady?'ready':'unavailable';
        $out=[];
        foreach ($calls as $call) {
            $peer=(string)$call['peer_e164'];$contact=$known[$peer]??null;
            $recording=['state'=>$recordingReady?'none':'unavailable','transcript_saved'=>false,'report_saved'=>false];
            if ($recordingReady) {
                try {
                    $sources=[$call['source_kind'].':'.$call['id']=>$call];
                    // A browser inbound call can fall back to voicemail on its original
                    // legacy receipt. Verify the exact carrier/owner/Agent/number binding.
                    if ($call['source_kind']==='browser' && $call['direction']==='inbound' && table_exists('phone_voice_calls_v130')) {
                        $raw=$browserById[$call['id']];
                        $q=$pdo->prepare("SELECT id,agent_id,caller_e164 FROM phone_voice_calls_v130 WHERE owner_user_id=? AND agent_id=? AND phone_number_id=? AND provider='twilio' AND provider_call_sid=? AND caller_e164=? LIMIT 1");
                        $q->execute([$scope['owner_user_id'],$call['agent_id'],$call['number_id'],$raw['provider_call_sid'],$peer]);
                        $linked=$q->fetch(PDO::FETCH_ASSOC);
                        if ($linked && (empty($raw['voice_call_id']) || (int)$raw['voice_call_id']===(int)$linked['id'])) {
                            $legacyId=(int)$linked['id'];
                            $sources['legacy:'.$legacyId]=['source_kind'=>'legacy','id'=>$legacyId,'agent_id'=>(int)$linked['agent_id'],'public_id'=>'legacy-'.$legacyId];
                        }
                    }
                    $jobs=[];$legacyIds=[];
                    foreach ($sources as $source) {
                        $q=$pdo->prepare("SELECT * FROM phone_recording_jobs_v1 WHERE owner_user_id=? AND origin_kind=? AND call_id=? ORDER BY id DESC LIMIT 10");
                        $q->execute([$scope['owner_user_id'],$source['source_kind'],$source['id']]);
                        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $job) $jobs[]=$job;
                        if ($source['source_kind']==='legacy') $legacyIds[]=$source['id'];
                    }
                    usort($jobs,static fn(array $a,array $b):int=>(int)$b['id']<=>(int)$a['id']);
                    foreach ($jobs as $job) {
                        $source=$sources[$job['origin_kind'].':'.$job['call_id']]??null;
                        $evidence=$source?vp3_operator_recording_evidence_v1($pdo,$scope['user'],$agentId,$job,$source):null;
                        if ($evidence!==null) {$recording=$evidence;break;}
                    }
                    if ($recording['state']==='none' && $legacyIds && table_exists('phone_voice_recordings_v130')) {
                        foreach ($legacyIds as $legacyId) {
                            $q=$pdo->prepare("SELECT 1 FROM phone_voice_recordings_v130 WHERE owner_user_id=? AND call_id=? AND provider='twilio' AND recording_status='completed' LIMIT 1");
                            $q->execute([$scope['owner_user_id'],$legacyId]);
                            if ($q->fetchColumn()) {$recording['state']='received';break;}
                        }
                    }
                    if ($recording['state']==='unavailable') $recordingCoverage='limited';
                } catch (Throwable $error) {
                    $recording=['state'=>'unavailable','transcript_saved'=>false,'report_saved'=>false];$recordingCoverage='limited';
                }
            }
            $out[]=['call_id'=>$call['public_id'],'contact_id'=>(int)($contact['id']??0),
                'contact_name'=>mb_substr((string)($contact['name']??''),0,120),
                'peer_e164'=>preg_match('/^\+[1-9][0-9]{7,14}$/D',$peer)?$peer:'',
                'direction'=>$call['direction']==='outbound'?'outbound':'inbound','state'=>$call['state'],
                'created_at'=>$call['created_at'],'duration_seconds'=>max(0,min(86400,(int)$call['duration_seconds'])),
                'recording'=>$recording]+(($call['transport_kind']??'')==='managed_callback'?['transport_kind'=>'managed_callback']+(($call['managed_mode']??'')==='twilio_native'?['managed_mode'=>'twilio_native']:[]):[])+(!empty($call['connected_phone'])?['connected_profile_id'=>$call['connected_profile_id'],'peer_masked'=>$call['peer_masked']]:[]);
        }
        return ['coverage'=>'ready','recording_coverage'=>$recordingCoverage,'calls'=>$out];
    } catch (Throwable $error) {return $empty;}
}
