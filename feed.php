<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();
$html=file_get_contents(__DIR__.'/feed-app/index.html');
if($html===false){http_response_code(503);exit('Feed is unavailable.');}
$config=json_encode(['endpoint'=>url('/api/social-feed-v1.php'),'csrf'=>csrf_token(),'home_url'=>url('/chat.php')],JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$html=str_replace(['href="assets/app.css"','src="assets/app.js"','<script id="feed-config" type="application/json">{}</script>'],['href="'.e(url('/feed-app/app.css?v=feed-v1')).'"','src="'.e(url('/feed-app/app.js?v=feed-v1')).'"','<script id="feed-config" type="application/json">'.$config.'</script>'],$html);
header('Content-Type: text/html; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
echo $html;
