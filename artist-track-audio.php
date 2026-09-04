<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=db();$trackId=(int)($_GET['track']??0);
if(!$pdo||$trackId<1){http_response_code(404);exit('Audio not found.');}
artist_music_v185_ensure_schema($pdo);
$track=artist_music_v185_public_track($pdo,$trackId,current_user());if(!$track){http_response_code(404);exit('Audio not found.');}
$path=artist_music_v185_owned_path((int)$track['workspace_id'],(string)($track['audio_path']??''));if(!$path){http_response_code(404);exit('Audio not found.');}
$allowed=['audio/mpeg','audio/mp4','audio/x-m4a','audio/wav','audio/x-wav','audio/vnd.wave','audio/ogg','application/ogg'];$mime='';
if(function_exists('finfo_open')){$f=finfo_open(FILEINFO_MIME_TYPE);if($f){$det=finfo_file($f,$path);finfo_close($f);$mime=is_string($det)?strtolower(trim($det)):'';if($mime===''||!in_array($mime,$allowed,true)){http_response_code(415);exit('Unsupported audio.');}}}
if($mime===''){$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$mime=match($ext){'mp3'=>'audio/mpeg','m4a'=>'audio/mp4','wav'=>'audio/wav','ogg'=>'audio/ogg',default=>''};if($mime===''){http_response_code(415);exit('Unsupported audio.');}}
$size=filesize($path);if($size===false||$size<1){http_response_code(404);exit('Audio not found.');}
$start=0;$end=$size-1;$status=200;$range=(string)($_SERVER['HTTP_RANGE']??'');
if($range!==''&&preg_match('/bytes=(\d*)-(\d*)/',$range,$m)){
    if($m[1]===''&&$m[2]!==''){$suffix=min($size,(int)$m[2]);$start=max(0,$size-$suffix);}else{$start=$m[1]===''?0:(int)$m[1];$end=$m[2]===''?$end:min($end,(int)$m[2]);}
    if($start>$end||$start>=$size){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}$status=206;
}
$length=$end-$start+1;http_response_code($status);header('Content-Type: '.$mime);header('Accept-Ranges: bytes');header('Content-Length: '.$length);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=300');if($status===206)header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
if($_SERVER['REQUEST_METHOD']==='HEAD')exit;
$fh=fopen($path,'rb');if(!$fh){http_response_code(404);exit;}fseek($fh,$start);$remaining=$length;
while($remaining>0&&!feof($fh)){$chunk=fread($fh,min(1024*1024,$remaining));if($chunk===false||$chunk==='')break;echo $chunk;$remaining-=strlen($chunk);flush();}fclose($fh);exit;
