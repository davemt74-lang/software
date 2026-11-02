<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__.'/includes/music-media.php';
header('Cache-Control: no-store');

$trackId = (int)($_GET['track'] ?? 0);
$type = (string)($_GET['type'] ?? 'audio');

if ($trackId < 1 || !in_array($type, ['audio', 'cover'], true)) {
    music_media_error(400,'invalid_request','Invalid media request.');
}

$track = music_media_track($trackId, current_user());
if (!$track) {
    music_media_error(404,'track_unavailable','Media not found or not accessible to this account.');
}

if (!can_view_track($track)) {
    music_media_error(is_logged_in()?403:401,'access_denied','You do not have access to this media.');
}

// Temporary owner-only playback diagnostics, requested only after a player failure.
if(($_GET['debug']??'')==='1'&&current_user()&&can_manage_track_production($track,current_user())){
    $saved=(string)($track[$type==='cover'?'cover_path':'audio_path']??'');
    $checked=STONEFELLOW_ROOT.'/'.ltrim($saved,'/');
    $resolved=music_media_local_path($saved);
    $real=realpath($checked);$root=realpath(STONEFELLOW_ROOT);
    $inside=$real&&$root&&str_starts_with($real,rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    $exists=$inside&&is_file($real);$readable=$exists&&is_readable($real);
    header('X-Stonefellow-Media-Debug: '.base64_encode(json_encode([
        'stored_path'=>$saved,'checked_path'=>$checked,'file_exists'=>(bool)$exists,'readable'=>(bool)$readable,'bytes'=>$exists?(int)filesize($real):null,'playable_file_found'=>$resolved!==null,
    ],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)));
}

// Resolve catalog artwork through its owned photo reference, including album inheritance.
$catalogId=(int)($track['artist_track_id']??0);
if($type==='cover'&&$catalogId>0&&($pdo=db())){
    $cover=artist_music_v185_public_cover($pdo,'track',$catalogId,current_user());
    if($cover){$mime=music_media_mime($cover['path'],'cover');if($mime)music_media_send($cover['path'],$mime);}
}
// Platform tracks retain their original master even when a catalog shadow has
// stale audio. Native catalog tracks still resolve through their owned store.
$original=$type==='audio'&&!empty($track['artist_track_id'])&&$trackId<1000000000
    ?music_media_local_path((string)($track['audio_path']??'')):null;
if($original){
    $mime=music_media_mime($original,'audio');
    if($mime)music_media_send($original,$mime);
}
if($type==='audio'&&$catalogId>0&&($pdo=db())){
    $catalog=artist_music_v185_public_track($pdo,$catalogId,current_user());
    $path=$catalog?artist_music_v185_resolve_audio($pdo,$catalog):null;
    if($path){
        $mime=music_media_mime($path,'audio');if(!$mime){music_media_error(415,'unsupported_audio','Unsupported audio.');}
        music_media_send($path,$mime);
    }
}
$relative=$type==='cover'?(string)($track['cover_path']??''):(string)($track['audio_path']??'');
if($type==='cover'&&$relative==='')$relative='/images/stonefellow-studio.png';

if ($relative === '' || preg_match('#^https?://#i', $relative)) {
    music_media_error(404,$relative===''?'audio_path_empty':'stored_file_unavailable','Media file is not available.');
}

$absolute = music_media_local_path($relative);
if(!$absolute && $type==='cover')$absolute=music_media_local_path('/images/stonefellow-studio.png');

if (!$absolute) {
    music_media_error(404,'stored_file_unavailable','Media file is not available.');
}

$size = filesize($absolute);
if ($size === false || $size < 1) {
    music_media_error(404,'file_empty','Media file is empty.');
}

$mime=music_media_mime($absolute,$type);
if(!$mime){music_media_error(415,'unsupported_audio','Unsupported media.');}
music_media_send($absolute,$mime);
