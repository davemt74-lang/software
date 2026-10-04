<?php
declare(strict_types=1);
/** User-triggered import of explicitly shared HomeServer transcript text. */
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/artist-listening.php';
require_once dirname(__DIR__).'/includes/homeserver-transcription-import-v1.php';
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
    hs_transcription_json(homeserver_transcription_import_v1($user,$id,$remote));
}catch(Throwable $e){
    error_log('HS transcription import: '.get_class($e));
    hs_transcription_json(['ok'=>false,'error'=>$e->getMessage()==='stop_current_cloud_transcription_before_import'?'stop_current_cloud_transcription_before_import':'transcription_import_failed_or_consent_revoked'],409);
}
