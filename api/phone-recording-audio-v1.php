<?php
declare(strict_types=1);

/** Private saved-call playback; identifiers never accept an owner or media URL. */
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-workspace-v1.php';
require_once dirname(__DIR__).'/includes/phone-recording-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/artist-recording-playback-v243.php';

function vp3_phone_recording_audio_error_v1(int $status,?int $size=null): never
{
    artist_recording_playback_v243_prepare_transport();
    http_response_code($status);
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: 0');
    if($status===405)header('Allow: GET, HEAD');
    if($status===416){header('Accept-Ranges: bytes');header('Content-Range: bytes */'.max(0,(int)$size));}
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if(!in_array($method,['GET','HEAD'],true))vp3_phone_recording_audio_error_v1(405);
$user=current_user();
if(!$user)vp3_phone_recording_audio_error_v1(401);
if(empty($user['is_active'])||!has_permission('account.access',$user)||!has_permission('chat.access',$user))
    vp3_phone_recording_audio_error_v1(403);
$pdo=db();
if(!$pdo instanceof PDO)vp3_phone_recording_audio_error_v1(503);
if(!vp3_phone_workspace_available_v1($pdo,$user))vp3_phone_recording_audio_error_v1(403);
$id=$_GET['id']??null;
if(!is_string($id)||strlen($id)>32||preg_match('/\A(?:job|legacy)-[1-9][0-9]{0,15}\z/D',$id)!==1)
    vp3_phone_recording_audio_error_v1(404);
// Current Phone access requires any assigned number. Historical recordings
// still belong to their immutable receipt owner, not the current DID assignee.
artist_recording_playback_v243_prepare_transport();
$handle=null;
try{
    $media=vp3_phone_recording_audio_any_v1($pdo,$user,$id);
    if(($media['mime']??'')!=='audio/mpeg')throw new RuntimeException('Recording unavailable.',404);
    $bytes=null;
    if(isset($media['path'])&&is_string($media['path'])){
        if(!is_file($media['path'])||!is_readable($media['path']))throw new RuntimeException('Recording unavailable.',404);
        if($method==='GET'){
            $handle=@fopen($media['path'],'rb');
            if($handle===false)throw new RuntimeException('Recording unavailable.',404);
            $stat=fstat($handle);$size=is_array($stat)?(int)$stat['size']:0;
        }else $size=(int)filesize($media['path']);
    }elseif(isset($media['bytes'])&&is_string($media['bytes'])){
        $bytes=$media['bytes'];$size=strlen($bytes);
    }else throw new RuntimeException('Recording unavailable.',404);
    if($size<1||$size>VP3_PHONE_RECORDING_MAX_BYTES_V1)throw new RuntimeException('Recording unavailable.',404);
    $rangeHeader=(string)($_SERVER['HTTP_RANGE']??'');
    try{
        if(strlen($rangeHeader)>512)throw new OutOfBoundsException('Invalid range.');
        $range=artist_recording_playback_v243_range($size,$rangeHeader);
    }catch(OutOfBoundsException){
        if(is_resource($handle))fclose($handle);
        vp3_phone_recording_audio_error_v1(416,$size);
    }
    $start=(int)$range['start'];$length=(int)$range['length'];
    if(is_resource($handle)&&$start>0&&fseek($handle,$start)!==0)throw new RuntimeException('Recording unavailable.',404);
    http_response_code((int)$range['status']);
    header('Content-Type: audio/mpeg');
    header('Content-Length: '.$length);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store, max-age=0');
    header('Vary: Cookie');
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    header('Content-Disposition: inline; filename="phone-recording.mp3"');
    if((int)$range['status']===206)header('Content-Range: bytes '.$start.'-'.(int)$range['end'].'/'.$size);
    if($method==='HEAD')exit;
    if($bytes!==null)echo substr($bytes,$start,$length);
    else{
        $remaining=$length;
        while($remaining>0&&!feof($handle)){
            $chunk=fread($handle,min(65536,$remaining));
            if($chunk===false||$chunk==='')break;
            echo $chunk;$remaining-=strlen($chunk);
        }
        fclose($handle);
    }
    exit;
}catch(Throwable $error){
    if(is_resource($handle))fclose($handle);
    // Avoid exposing filesystem paths, provider addresses or signed-in owner data.
    $status=$error instanceof RuntimeException&&in_array($error->getCode(),[403,404],true)?$error->getCode():503;
    vp3_phone_recording_audio_error_v1($status);
}
