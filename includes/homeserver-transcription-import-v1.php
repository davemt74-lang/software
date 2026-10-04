<?php
declare(strict_types=1);

require_once __DIR__.'/speaker-attribution-v1.php';

function homeserver_transcription_import_attribution_v1(array $segment): array
{
    $speaker=preg_replace('/\s+/u',' ',trim((string)($segment['speaker']??'Speaker 1')))??'Speaker 1';
    if($speaker===''||mb_strlen($speaker)>80)throw new RuntimeException('Invalid HomeServer speaker label.');
    $raw=$segment['attribution']??null;
    if($raw===null)return vp3_speaker_fuse_v1([['source'=>'unknown','speaker_label'=>$speaker]]);
    if(!is_array($raw)||($raw['contract']??null)!==VP3_SPEAKER_ATTRIBUTION_V1)
        throw new RuntimeException('Invalid HomeServer speaker attribution contract.');
    $source=strtolower(trim((string)($raw['source']??'unknown')));
    if(!in_array($source,['unknown','provider_diarization','heuristic_acoustic'],true))
        throw new RuntimeException('HomeServer speaker attribution cannot assert Cloud identity.');
    if(!empty($raw['participant_id'])||trim((string)($raw['participant_identity']??''))!==''||
       !empty($raw['speaker_identity_verified'])||!empty($raw['authentication_authority'])||
       isset($raw['provider_speaker_id'])||isset($raw['track_id'])||
       (!empty($raw['evidence'])&&is_array($raw['evidence'])))
        throw new RuntimeException('HomeServer transcript identity evidence requires Cloud re-verification.');
    $label=preg_replace('/\s+/u',' ',trim((string)($raw['speaker_label']??$speaker)))??'';
    if($label===''||mb_strlen($label)>80||!hash_equals($speaker,$label))
        throw new RuntimeException('HomeServer speaker label and attribution disagree.');
    return vp3_speaker_fuse_v1([[
        'source'=>$source,'speaker_label'=>$speaker,
        'confidence'=>$raw['confidence']??0,
        'overlap'=>!empty($raw['overlap']),
        'overlap_group'=>(string)($raw['overlap_group']??''),
    ]]);
}


/** Atomic import into the existing canonical transcript tables; no capture starts. */
function homeserver_transcription_import_v1(array $user,string $id,array $remote): array
{
    if(!preg_match('/^[a-f0-9]{32}$/',$id))throw new RuntimeException('Invalid HomeServer session.');
    $session=$remote['session']??null;
    if(!is_array($session)||($session['id']??null)!==$id||($session['cloud_shared']??null)!==true||
       ($session['status']??null)!=='completed'||($remote['raw_audio_included']??null)!==false||
       ($remote['contract']??null)!=='vp3.homeserver.transcription-session.v1')
        throw new RuntimeException('HomeServer sharing permission is unavailable.');
    $segments=$session['segments']??null;
    if(!is_array($segments)||!array_is_list($segments)||count($segments)>300||
       !is_int($session['segment_count']??null)||$session['segment_count']!==count($segments))
        throw new RuntimeException('Invalid HomeServer segment manifest.');
    $validated=[];$keys=[];$bytes=0;$previous=0;$duration=0;
    foreach($segments as $index=>$segment){
        if(!is_array($segment)||!is_string($segment['text']??null)||preg_match('//u',$segment['text'])!==1)
            throw new RuntimeException('Invalid transcript text.');
        $text=trim($segment['text']);$key=$segment['client_key']??null;$time=$segment['started_ms']??null;
        $ended=$segment['ended_ms']??$time;
        if($text===''||mb_strlen($text)>8000||!is_string($key)||!preg_match('/^[a-f0-9]{32}$/',$key)||isset($keys[$key])||
           !is_int($time)||$time<$previous||$time>86400000||!is_int($ended)||$ended<$time||$ended>86400000)
            throw new RuntimeException('Invalid transcript segment identity or timing.');
        $attribution=homeserver_transcription_import_attribution_v1($segment);
        $bytes+=strlen($text);if($bytes>120000)throw new RuntimeException('Transcript document size limit reached.');
        $keys[$key]=true;$previous=$time;$duration=max($duration,$ended);
        $validated[]=[
            'key'=>substr(hash('sha256','hsseg:'.$id.':'.$key),0,32),'text'=>$text,
            'time'=>$time,'ended'=>$ended,'index'=>$index,
            'speaker'=>(string)$attribution['speaker_label'],'attribution'=>$attribution,
        ];
    }
    $title=artist_listening_v172_clean_title(is_string($session['title']??null)?$session['title']:'HomeServer transcription');
    $hash=hash('sha256',json_encode([$id,$title,$validated],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $pdo=db();$userId=(int)($user['id']??0);$ownerId=artist_listening_v172_owner_id($user);
    if(!$pdo||$userId<1||$ownerId<1||!artist_listening_v172_schema_ready())throw new RuntimeException('Cloud transcription unavailable.');
    $clientKey='hs'.substr(hash('sha256',(string)$userId.':'.$id),0,32);
    $pdo->beginTransaction();
    try{
        // Same lock as capture Start/Activate: imports never reuse their active document.
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$userId]);
        if(!$lock->fetchColumn())throw new RuntimeException('User account unavailable.');
        $stmt=$pdo->prepare('SELECT * FROM artist_transcript_sessions_v172 WHERE created_by_user_id=? AND client_session_key=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$userId,$clientKey]);$original=$stmt->fetch(PDO::FETCH_ASSOC);
        if($original&&(string)$original['status']!=='active'){
            $metadata=artist_listening_v197_metadata($original);
            $originalHash=$metadata['homeserver_import_v1']['source_hash']??'';
            if($originalHash!==''&&!hash_equals((string)$originalHash,$hash))throw new RuntimeException('HomeServer source changed; existing Cloud copy was preserved.');
            $pdo->commit();
            return ['ok'=>true,'imported'=>false,'already_imported'=>true,'cloud_session_id'=>(int)$original['id'],
                'cloud_status'=>(string)$original['status'],'raw_audio_imported'=>false,'cloud_copy_is_independent'=>true];
        }
        $running=$pdo->prepare("SELECT id FROM artist_transcript_sessions_v172 WHERE created_by_user_id=? AND status='active' AND id<>? LIMIT 1 FOR UPDATE");
        $running->execute([$userId,(int)($original['id']??0)]);
        if($running->fetchColumn())throw new RuntimeException('stop_current_cloud_transcription_before_import');
        $metadata=$original?artist_listening_v197_metadata($original):['audio_retained'=>false,'capture_mode'=>'homeserver_text_import','speaker_mode'=>'1'];
        $hasDiarization=false;$attributionMap=[];
        foreach($validated as $row){
            if(($row['attribution']['source']??'')==='provider_diarization')$hasDiarization=true;
            $attributionMap[$row['key']]=[
                'contract'=>VP3_SPEAKER_ATTRIBUTION_V1,
                'speaker_label'=>$row['speaker'],
                'source'=>$row['attribution']['source'],
                'confidence'=>$row['attribution']['confidence'],
                'overlap'=>$row['attribution']['overlap'],
                'overlap_group'=>$row['attribution']['overlap_group'],
                'speaker_identity_verified'=>false,
                'authentication_authority'=>false,
            ];
        }
        $metadata['homeserver_import_v1']=['source_id'=>$id,'source_hash'=>$hash,'segment_count'=>count($validated),
            'speaker_attribution'=>$hasDiarization?'provider_diarization':'unidentified_single_channel',
            'speaker_identity_verified'=>false,'authentication_authority'=>false,
            'segment_attribution'=>$attributionMap,'cloud_copy_is_independent'=>true];
        if(!$original){
            $stmt=$pdo->prepare("INSERT INTO artist_transcript_sessions_v172 (owner_user_id,created_by_user_id,client_session_key,title,status,language,metadata_json,duration_ms,stopped_at) VALUES (?,?,?,?,'draft','en-US',?,?,NOW())");
            $stmt->execute([$ownerId,$userId,$clientKey,$title,json_encode($metadata,JSON_THROW_ON_ERROR),$duration]);
            $cloudId=(int)$pdo->lastInsertId();$existing=[];
        }else{
            // Recover legacy partial imports only when every saved row matches this source.
            $cloudId=(int)$original['id'];
            $stmt=$pdo->prepare('SELECT client_segment_key,transcript_text,segment_index,started_ms,segment_type,speaker_label FROM artist_transcript_segments_v172 WHERE session_id=? ORDER BY segment_index');
            $stmt->execute([$cloudId]);$existing=[];$expected=array_column($validated,null,'key');
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $saved){
                $key=(string)$saved['client_segment_key'];$want=$expected[$key]??null;
                if(!$want||(string)$saved['transcript_text']!==$want['text']||(int)$saved['segment_index']!==$want['index']||
                   (int)$saved['started_ms']!==$want['time']||(string)$saved['speaker_label']!==$want['speaker']||
                   (string)$saved['segment_type']!=='transcript')
                    throw new RuntimeException('Unfinished import differs from its source; review the Cloud document.');
                $existing[$key]=true;
            }
        }
        $insert=$pdo->prepare("INSERT INTO artist_transcript_segments_v172 (session_id,client_segment_key,segment_index,segment_type,speaker_label,transcript_text,started_ms,ended_ms,confidence) VALUES (?,?,?,'transcript',?,?,?,?,NULL)");
        foreach($validated as $row)if(!isset($existing[$row['key']]))$insert->execute([
            $cloudId,$row['key'],$row['index'],$row['speaker'],$row['text'],$row['time'],$row['ended']
        ]);
        if($original)$pdo->prepare("UPDATE artist_transcript_sessions_v172 SET status='draft',title=?,metadata_json=?,duration_ms=?,stopped_at=COALESCE(stopped_at,NOW()),last_activity_at=NOW() WHERE id=? AND created_by_user_id=?")
            ->execute([$title,json_encode($metadata,JSON_THROW_ON_ERROR),$duration,$cloudId,$userId]);
        $pdo->commit();
        return ['ok'=>true,'imported'=>true,'cloud_session_id'=>$cloudId,'segment_count'=>count($validated),
            'raw_audio_imported'=>false,'cloud_copy_is_independent'=>true];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
