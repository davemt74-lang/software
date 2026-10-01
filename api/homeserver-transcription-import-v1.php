<?php
declare(strict_types=1);
/** User-triggered import of explicitly shared HomeServer transcript text. */
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/artist-listening.php';
require_once dirname(__DIR__).'/includes/homeserver-execution-routing-v220.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
function hs_transcription_json(array $body,int $code=200): never {
    http_response_code($code);
    echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
$user=current_user();
if(!$user)hs_transcription_json(['ok'=>false,'error'=>'login_required'],401);
if(!has_permission('artist_listening.access',$user))
    hs_transcription_json(['ok'=>false,'error'=>'transcription_permission_required'],403);
if(!artist_listening_v172_schema_ready())
    hs_transcription_json(['ok'=>false,'error'=>'cloud_transcription_unavailable'],503);
$userId=(int)$user['id'];
$pdo=db();
if(!$pdo)hs_transcription_json(['ok'=>false,'error'=>'database_unavailable'],503);
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='GET'){
    if(!homeserver_execution_v220_can_route($userId,'transcription.shared.list'))
        hs_transcription_json(['ok'=>false,'error'=>'paired_homeserver_unavailable'],503);
    try{
        $remote=homeserver_execution_v220_execute($userId,'transcription.shared.list',['limit'=>30]);
        $rows=is_array($remote['sessions']??null)?$remote['sessions']:[];
        $safe=[];
        foreach($rows as $row){
            if(!is_array($row)||empty($row['cloud_shared'])||($row['status']??'')!=='completed')continue;
            $id=(string)($row['id']??'');
            if(!preg_match('/^[a-f0-9]{32}$/',$id))continue;
            $safe[]=[
                'id'=>$id,
                'title'=>mb_strimwidth((string)($row['title']??'HomeServer transcription'),0,190,''),
                'segment_count'=>max(0,(int)($row['segment_count']??0)),
                'started_at'=>mb_strimwidth((string)($row['started_at']??''),0,48,''),
            ];
        }
        hs_transcription_json(['ok'=>true,'sessions'=>$safe,'source'=>'paired_homeserver']);
    }catch(Throwable $e){
        error_log('HS transcription import list: '.get_class($e));
        hs_transcription_json(['ok'=>false,'error'=>'paired_homeserver_unavailable_or_scope_required'],503);
    }
}
if($method!=='POST')hs_transcription_json(['ok'=>false,'error'=>'method_not_allowed'],405);
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input))hs_transcription_json(['ok'=>false,'error'=>'invalid_request'],400);
if(!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))
    hs_transcription_json(['ok'=>false,'error'=>'session_expired'],419);
$id=(string)($input['session_id']??'');
if(!preg_match('/^[a-f0-9]{32}$/',$id))
    hs_transcription_json(['ok'=>false,'error'=>'invalid_homeserver_session'],422);
if(!homeserver_execution_v220_can_route($userId,'transcription.shared.fetch'))
    hs_transcription_json(['ok'=>false,'error'=>'paired_homeserver_unavailable'],503);
try{
    // HomeServer rechecks paired VP3 identity, knowledge.read and owner consent
    // for every fetch. Never transfer raw audio, recordings or provider keys.
    $remote=homeserver_execution_v220_execute($userId,'transcription.shared.fetch',['session_id'=>$id]);
    $session=is_array($remote['session']??null)?$remote['session']:[];
    if(($session['id']??'')!==$id || empty($session['cloud_shared']) ||
       ($session['status']??'')!=='completed' || !empty($remote['raw_audio_included']))
        throw new RuntimeException('HomeServer sharing permission is unavailable.');
    $segments=$session['segments']??[];
    if(!is_array($segments)||count($segments)>300)
        throw new RuntimeException('HomeServer transcript exceeds the import segment limit.');
    $validated=[];$bytes=0;$i=0;
    foreach($segments as $segment){
        if(!is_array($segment))throw new RuntimeException('Invalid transcript segment.');
        $content=trim((string)($segment['text']??''));
        if($content===''||mb_strlen($content)>8000)continue;
        $bytes+=strlen($content);
        if($bytes>120000)throw new RuntimeException('Transcript document size limit reached.');
        $key=(string)($segment['client_key']??'');
        if(!preg_match('/^[a-f0-9]{32}$/',$key))throw new RuntimeException('Invalid transcript segment key.');
        $validated[]=[
            'text'=>$content,'type'=>'transcript','speaker'=>'Speaker 1',
            'client_segment_key'=>substr(hash('sha256','hsseg:'.$id.':'.$key),0,32),
            'index'=>$i++,
            'started_ms'=>max(0,min(86400000,(int)($segment['started_ms']??0))),
        ];
    }
    $clientKey='hs'.substr(hash('sha256',(string)$userId.':'.$id),0,32);
    $existing=$pdo->prepare(
        "SELECT id,status FROM artist_transcript_sessions_v172 WHERE created_by_user_id=? AND client_session_key=? LIMIT 1"
    );
    $existing->execute([$userId,$clientKey]);
    $original=$existing->fetch(PDO::FETCH_ASSOC);
    if($original&&($original['status']??'')!=='active'){
        hs_transcription_json(['ok'=>true,'imported'=>false,'already_imported'=>true,
            'cloud_session_id'=>(int)$original['id']]);
    }
    if(!$original){
        $running=$pdo->prepare(
            "SELECT id FROM artist_transcript_sessions_v172 WHERE created_by_user_id=? AND status='active' LIMIT 1"
        );
        $running->execute([$userId]);
        if($running->fetchColumn())hs_transcription_json([
            'ok'=>false,'error'=>'stop_current_cloud_transcription_before_import',
        ],409);
        $started=artist_listening_v172_start($user,$clientKey,0,'en-US','1');
        $cloudId=(int)($started['id']??$started['session']['id']??0);
        if($cloudId<1)throw new RuntimeException('Cloud transcription could not be created.');
        artist_listening_v172_rename($user,$cloudId,(string)($session['title']??'HomeServer transcription'));
    }else $cloudId=(int)$original['id'];
    foreach(array_chunk($validated,50) as $batch)
        artist_listening_v172_append($user,$cloudId,$batch);
    artist_listening_v172_stop($user,$cloudId,
        $validated?max(array_column($validated,'started_ms')):0);
    hs_transcription_json([
        'ok'=>true,'imported'=>true,'cloud_session_id'=>$cloudId,
        'segment_count'=>count($validated),
        'raw_audio_imported'=>false,'cloud_copy_is_independent'=>true,
    ]);
}catch(Throwable $e){
    error_log('HS transcription import: '.get_class($e));
    hs_transcription_json(['ok'=>false,'error'=>'transcription_import_failed_or_consent_revoked'],409);
}
