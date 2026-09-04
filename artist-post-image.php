<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=db();$postId=(int)($_GET['post']??0);
if(!$pdo || $postId<1 || !artist_posts_v183_schema_ready()){http_response_code(404);exit('Image not found.');}
$image=artist_posts_v183_public_image($pdo,$postId,current_user());
if(!$image){http_response_code(404);exit('Image not found.');}
$path=(string)$image['path'];
$info=@getimagesize($path);$mime=is_array($info)?(string)($info['mime']??''):'';
if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){http_response_code(415);exit('Unsupported image.');}
$size=filesize($path);if($size===false || $size<1){http_response_code(404);exit('Image not found.');}
header('Content-Type: '.$mime);
header('Content-Length: '.$size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($path);exit;
