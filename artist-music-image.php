<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=db();$kind=(string)($_GET['type']??'album');$id=(int)($_GET['id']??0);$viewer=current_user();
if(!$pdo || $id<1 || !in_array($kind,['album','track'],true)){http_response_code(404);exit('Image not found.');}
artist_music_v185_ensure_schema($pdo);artist_media_v182_ensure_schema($pdo);
$cover=artist_music_v185_public_cover($pdo,$kind,$id,$viewer);
if(!$cover){http_response_code(404);exit('Image not found.');}
require_once __DIR__.'/includes/music-media.php';
$mime=music_media_mime($cover['path'],'cover');if(!$mime){http_response_code(415);exit('Unsupported image.');}
music_media_send($cover['path'],$mime);
