<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_permission('account.access');

$pdo=db();$user=current_user();
if(!$pdo||!$user){http_response_code(401);exit('Authentication required.');}
$product=(string)($_GET['product']??'');
$releaseId=max(0,(int)($_GET['release_id']??0));
$scope=client_release_scope_key_v110((string)($_GET['scope']??'account'));
$type=(string)($_GET['type']??'package');
$userId=(int)$user['id'];

if(!client_release_product_valid_v110($product)||$releaseId<1||!client_release_scope_authorized_v110($pdo,$userId,$product,$scope)){
    http_response_code(404);exit('Release not found.');
}
$channel=client_release_channel_for_v110($pdo,$userId,$product,$scope);
$release=client_release_applicable_release_v110($pdo,$product,$channel,$userId,$scope);
if(!$release||(int)$release['id']!==$releaseId){http_response_code(404);exit('Release not found.');}

if($product==='browser_companion'){
    if($type!=='package'){http_response_code(404);exit('Release not found.');}
    $path=(string)($release['package_path']??'');
    $name=(string)($release['package_name']??'vp3-browser-companion.zip');
    $hash=(string)($release['package_sha256']??'');
    $mime='application/zip';
    $base=realpath(chrome_extension_release_private_dir());
}else{
    if(!in_array($type,['installer','portable'],true)){http_response_code(404);exit('Release not found.');}
    $path=(string)($release[$type.'_path']??'');
    $name=(string)($release[$type.'_name']??'HomeServer.exe');
    $hash=(string)($release[$type.'_sha256']??'');
    $mime='application/vnd.microsoft.portable-executable';
    $base=realpath(homeserver_vp3_private_dir());
}
$real=$path!==''&&is_file($path)?realpath($path):false;
$size=$real?filesize($real):false;
if(!$base||!$real||!str_starts_with($real,$base.DIRECTORY_SEPARATOR)||!is_int($size)||$size<1){http_response_code(404);exit('Release file not found.');}

client_release_set_update_state_v110($pdo,$userId,$product,$scope,$releaseId,'downloaded',null,'Downloaded from controlled rollout');
client_release_audit_v110($pdo,$userId,$product,$releaseId,'download','','downloaded',['scope_key'=>$scope,'channel'=>$channel,'type'=>$type]);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Content-Type: '.$mime);
header('Content-Disposition: attachment; filename="'.str_replace(['"',"\r","\n"],'',basename($name)).'"');
header('Content-Length: '.(string)$size);
if($hash!=='')header('X-VP3-Release-SHA256: '.$hash);
header('X-VP3-Release-Version: '.(string)$release['version']);
header('X-VP3-Release-Channel: '.$channel);
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='HEAD')exit;
readfile($real);
