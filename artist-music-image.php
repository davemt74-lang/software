<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=db();$kind=(string)($_GET['type']??'album');$id=(int)($_GET['id']??0);$viewer=current_user();
if(!$pdo || $id<1 || !in_array($kind,['album','track'],true)){http_response_code(404);exit('Image not found.');}
artist_music_v185_ensure_schema($pdo);artist_media_v182_ensure_schema($pdo);
$row=null;
if($kind==='track'){
    $row=artist_music_v185_public_track($pdo,$id,$viewer);
}else{
    $stmt=$pdo->prepare('SELECT a.*,w.artist_user_id FROM artist_catalog_albums_v181 a INNER JOIN artist_workspaces_v181 w ON w.id=a.workspace_id WHERE a.id=? LIMIT 1');$stmt->execute([$id]);$candidate=$stmt->fetch();
    if($candidate){$isOwner=$viewer && user_has_role('artist',$viewer) && (int)($viewer['id']??0)===(int)$candidate['artist_user_id'];if($isOwner || artist_music_v185_can_view($candidate,$viewer))$row=$candidate;}
}
if(!$row){http_response_code(404);exit('Image not found.');}
$photoId=(int)($row['cover_photo_id']??0);
if($kind==='track' && $photoId<1 && (int)($row['album_id']??0)>0){$stmt=$pdo->prepare('SELECT cover_photo_id FROM artist_catalog_albums_v181 WHERE id=? AND workspace_id=? LIMIT 1');$stmt->execute([(int)$row['album_id'],(int)$row['workspace_id']]);$photoId=(int)($stmt->fetchColumn()?:0);}
$path=$photoId>0?artist_media_v182_resolve_photo_file($pdo,(int)$row['workspace_id'],$photoId):null;
if(!$path){http_response_code(404);exit('Image not found.');}
$info=@getimagesize($path);$mime=is_array($info)?(string)($info['mime']??''):'';
if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){http_response_code(415);exit('Unsupported image.');}
$size=filesize($path);if($size===false||$size<1){http_response_code(404);exit('Image not found.');}
header('Content-Type: '.$mime);header('Content-Length: '.$size);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=300');readfile($path);exit;
