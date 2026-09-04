<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$pdo=db();
if(!$pdo || !artist_workspace_v181_schema_ready($pdo)){http_response_code(404);exit;}
$slug=trim((string)($_GET['artist']??''));
$userId=max(0,(int)($_GET['user_id']??0));
$type=(string)($_GET['type']??'profile');
if(!in_array($type,['profile','cover'],true)){http_response_code(404);exit;}
$workspace=artist_workspace_v181_lookup_public($pdo,$slug,$userId);
if(!$workspace){http_response_code(404);exit;}
$field=$type==='cover'?'cover_image_path':'profile_image_path';
$path=artist_workspace_v181_owned_image_path((int)$workspace['id'],(string)($workspace[$field]??''));
if(!$path){http_response_code(404);exit;}

$info=@getimagesize($path);
$mime=(string)($info['mime']??'');
if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){http_response_code(404);exit;}
header('Content-Type: '.$mime);
header('Content-Length: '.(string)filesize($path));
header('Cache-Control: public, max-age=3600, must-revalidate');
header('X-Content-Type-Options: nosniff');
readfile($path);
