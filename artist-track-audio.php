<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=db();$trackId=(int)($_GET['track']??0);
if(!$pdo||$trackId<1){http_response_code(404);exit('Audio not found.');}
artist_music_v185_ensure_schema($pdo);
$track=artist_music_v185_public_track($pdo,$trackId,current_user());if(!$track){http_response_code(404);exit('Audio not found.');}
$path=artist_music_v185_resolve_audio($pdo,$track);if(!$path){http_response_code(404);exit('Audio not found.');}
require_once __DIR__.'/includes/music-media.php';
$mime=music_media_mime($path,'audio');if(!$mime){http_response_code(415);exit('Unsupported audio.');}
music_media_send($path,$mime);
