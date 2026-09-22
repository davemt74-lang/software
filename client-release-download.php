<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_permission('account.access');

$pdo=db();$user=current_user();
if(!$pdo||!$user){http_response_code(401);exit('Authentication required.');}
if(!client_release_rollouts_schema_ready_v110($pdo)){http_response_code(503);exit('Client release service is not ready.');}

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

$start=0;$end=$size-1;$status=200;
$range=trim((string)($_SERVER['HTTP_RANGE']??''));
if($range!==''){
    if(!preg_match('/^bytes=(\d*)-(\d*)$/',$range,$m)||($m[1]===''&&$m[2]==='')){
        header('Content-Range: bytes */'.$size);http_response_code(416);exit;
    }
    if($m[1]===''){
        $suffix=min($size,max(1,(int)$m[2]));$start=$size-$suffix;
    }else{$start=(int)$m[1];}
    if($m[2]!=='')$end=min($end,(int)$m[2]);
    if($start<0||$start>=$size||$end<$start){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
    $status=206;
}
$length=$end-$start+1;

http_response_code($status);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Accept-Ranges: bytes');
header('Content-Type: '.$mime);
header('Content-Disposition: attachment; filename="'.str_replace(['"',"\r","\n"],'',basename($name)).'"');
header('Content-Length: '.(string)$length);
if($status===206)header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
if($hash!=='')header('X-VP3-Release-SHA256: '.$hash);
header('X-VP3-Release-Version: '.(string)$release['version']);
header('X-VP3-Release-Channel: '.$channel);
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='HEAD')exit;

client_release_set_update_state_v110($pdo,$userId,$product,$scope,$releaseId,'downloaded',null,'Downloaded from controlled rollout');
client_release_audit_v110($pdo,$userId,$product,$releaseId,'download','','downloaded',['scope_key'=>$scope,'channel'=>$channel,'type'=>$type]);

$handle=fopen($real,'rb');
if($handle===false||fseek($handle,$start)!==0){if(is_resource($handle))fclose($handle);http_response_code(500);exit;}
$remaining=$length;
while($remaining>0&&!feof($handle)){
    $chunk=fread($handle,min(1048576,$remaining));
    if(!is_string($chunk)||$chunk==='')break;
    echo $chunk;$remaining-=strlen($chunk);
    if(connection_aborted())break;
}
fclose($handle);
