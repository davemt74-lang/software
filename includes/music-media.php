<?php
declare(strict_types=1);

/** One supported byte range. Invalid or unsupported ranges fail explicitly. */
function music_media_range(string $range,int $size): ?array
{
    if($size<1)throw new InvalidArgumentException('Media is empty.');
    if($range==='')return [0,$size-1,200];
    if(!preg_match('/^bytes=(\d*)-(\d*)$/D',trim($range),$m)||($m[1]===''&&$m[2]===''))return null;
    if($m[1]===''){$suffix=(float)$m[2];if($suffix<=0)return null;$start=$suffix>=$size?0:$size-(int)$suffix;$end=$size-1;}
    else{$offset=(float)$m[1];if($offset>=$size)return null;$start=(int)$offset;$end=$m[2]===''?$size-1:(int)min((float)$m[2],$size-1);if($end<$start)return null;}
    return [$start,$end,206];
}

function music_media_send(string $path,string $mime): never
{
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){header('Allow: GET, HEAD');http_response_code(405);exit;}
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $size=filesize($path);if($size===false||$size<1){http_response_code(404);exit('Media is unavailable.');}
    $range=music_media_range((string)($_SERVER['HTTP_RANGE']??''),$size);
    header('Accept-Ranges: bytes');header('Cache-Control: private, no-cache, no-transform');header('X-Content-Type-Options: nosniff');
    if(!$range){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
    [$start,$end,$status]=$range;$length=$end-$start+1;
    $handle=fopen($path,'rb');if(!$handle){http_response_code(404);exit;}
    if($start>0&&fseek($handle,$start)!==0){fclose($handle);http_response_code(500);exit;}
    http_response_code($status);header('Content-Type: '.$mime);header('Content-Length: '.$length);
    if($status===206)header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD'){fclose($handle);exit;}
    $remaining=$length;
    while($remaining>0&&!feof($handle)){$data=fread($handle,min(262144,$remaining));if($data===false||$data==='')break;echo $data;$remaining-=strlen($data);if(connection_status()!==CONNECTION_NORMAL)break;}
    fclose($handle);exit;
}

function music_media_mime(string $path,string $kind): ?string
{
    $allowed=$kind==='cover'?['image/jpeg','image/png','image/webp']:['audio/mpeg','audio/mp4','audio/x-m4a','audio/wav','audio/x-wav','audio/vnd.wave','audio/ogg','application/ogg'];
    if(function_exists('finfo_open')){$f=finfo_open(FILEINFO_MIME_TYPE);if($f){$mime=finfo_file($f,$path);finfo_close($f);return in_array($mime,$allowed,true)?$mime:null;}}
    $mime=match(strtolower(pathinfo($path,PATHINFO_EXTENSION))){'mp3'=>'audio/mpeg','m4a'=>'audio/mp4','wav'=>'audio/wav','ogg'=>'audio/ogg','jpg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp',default=>''};
    return in_array($mime,$allowed,true)?$mime:null;
}
