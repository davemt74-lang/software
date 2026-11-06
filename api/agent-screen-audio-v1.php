<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-screen-audio-v1.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$claim='';$uid=0;$status=200;$response=[];
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('POST required.',405);
    $user=current_user();if(!$user)throw new RuntimeException('Sign in before transcribing tab audio.',403);screen_sessions_permission_v1($user,true);$uid=(int)$user['id'];
    if(!hash_equals(csrf_token(),(string)($_POST['csrf_token']??'')))throw new RuntimeException('Refresh before transcribing.',419);
    if((string)($_POST['owner_key']??'')!==(string)$uid)throw new RuntimeException('Screen account changed.',409);
    $pdo=db();if(!$pdo)throw new RuntimeException('My Knowledge storage is unavailable.',503);screen_sessions_schema_v1($pdo);$sid=screen_session_id_v1($_POST['session_id']??null);$id=screen_session_id_v1($_POST['capture_id']??null);$s=screen_session_v1($pdo,$user,$sid);if($s['status']!=='active')throw new RuntimeException('This audio session has stopped.',409);
    $at=screen_text_v1($_POST['captured_at']??'',32);$capturedAt=$at!==''?date_create_immutable($at):false;
    $now=time();if(!$capturedAt||$now-$capturedAt->getTimestamp()>180||$capturedAt->getTimestamp()-$now>5)throw new RuntimeException('Audio segment expired.',409);
    $at=$capturedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    $duration=filter_var($_POST['duration_seconds']??null,FILTER_VALIDATE_FLOAT);if($duration===false||$duration<.5||$duration>35)throw new InvalidArgumentException('Use audio segments up to 35 seconds.');
    $file=$_FILES['audio']??[];screen_audio_file_v1($file);$claim=screen_audio_budget_v1($uid);session_write_close();$transcription=screen_audio_transcribe_v1($file,$user,$duration);
    if($transcription['transcript']===''&&empty($transcription['audio_events']))$response=['ok'=>true,'capture'=>null,'message'=>'No speech or labelled sounds detected.'];
    else{$r=screen_capture_store_v1($pdo,$user,$sid,$id,$transcription+['captured_at'=>$at,'surface'=>'browser','duration_seconds'=>$duration,'provider'=>'elevenlabs','model'=>'scribe_v2'],'tab_audio');$response=['ok'=>true,'capture'=>screen_capture_public_v1($r)];}
}catch(Throwable $e){$code=(int)$e->getCode();$status=in_array($code,[403,404,405,409,413,419,429,502,503],true)?$code:422;$response=['ok'=>false,'error'=>$e instanceof PDOException?'Tab audio storage is unavailable.':$e->getMessage()];}
finally{if($claim!=='')try{screen_audio_budget_v1($uid,$claim);}catch(Throwable $ignored){}}
http_response_code($status);echo json_encode($response,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
