<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__.'/includes/music-media.php';
header('Cache-Control: no-store');

$trackId = (int)($_GET['track'] ?? 0);
$type = (string)($_GET['type'] ?? 'audio');

if ($trackId < 1 || !in_array($type, ['audio', 'cover'], true)) {
    http_response_code(400);
    exit('Invalid media request.');
}

$track = music_media_track($trackId, current_user());
if (!$track) {
    http_response_code(404);
    exit('Media not found.');
}

if (!can_view_track($track)) {
    http_response_code(is_logged_in() ? 403 : 401);
    header('Cache-Control: no-store');
    exit('You do not have access to this media.');
}

// Resolve catalog artwork through its owned photo reference, including album inheritance.
$catalogId=(int)($track['artist_track_id']??0);
if($type==='cover'&&$catalogId>0&&($pdo=db())){
    $cover=artist_music_v185_public_cover($pdo,'track',$catalogId,current_user());
    if($cover){$mime=music_media_mime($cover['path'],'cover');if($mime)music_media_send($cover['path'],$mime);}
}
if($type==='audio'&&$catalogId>0&&($pdo=db())){
    $catalog=artist_music_v185_public_track($pdo,$catalogId,current_user());
    $path=$catalog?artist_music_v185_resolve_audio($pdo,$catalog):null;
    if($path){
        $mime=music_media_mime($path,'audio');if(!$mime){http_response_code(415);exit('Unsupported audio.');}
        music_media_send($path,$mime);
    }
}
$relative=$type==='cover'?(string)($track['cover_path']??''):(string)($track['audio_path']??'');
if($type==='cover'&&$relative==='')$relative='/images/stonefellow-studio.png';

if ($relative === '' || preg_match('#^https?://#i', $relative)) {
    http_response_code(404);
    exit('Media file is not available.');
}

$absolute = music_media_local_path($relative);
if(!$absolute && $type==='cover')$absolute=music_media_local_path('/images/stonefellow-studio.png');

if (!$absolute) {
    http_response_code(404);
    exit('Media file is not available.');
}

$size = filesize($absolute);
if ($size === false || $size < 1) {
    http_response_code(404);
    exit('Media file is empty.');
}

$mime=music_media_mime($absolute,$type);
if(!$mime){http_response_code(415);exit('Unsupported media.');}
music_media_send($absolute,$mime);
