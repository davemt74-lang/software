<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/browser-share-media-v2040.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version, X-VP3-Media-Kind, X-VP3-Media-Name, X-VP3-Media-Metadata');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

function vp3_browser_share_media_json_v2040(int $status,array $payload=[]): never
{
    http_response_code($status);
    if(!headers_sent())header('Content-Type: application/json; charset=UTF-8');
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_browser_share_media_auth_v2040(PDO $pdo,bool $write=false): array
{
    $auth=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
    if($auth!==''){
        $session=vp3_extension_session_authenticate_v2001($pdo);
        if(!$session)throw new VP3BrowserShareMediaExceptionV2040('authentication_required',401,'Browser Companion authentication is required.');
        $cap=$write?'team.share.create':'team.chat.read';
        if(!vp3_extension_session_has_capability_v2000($session,$cap)){
            throw new VP3BrowserShareMediaExceptionV2040('capability_denied',403,'This browser connection does not have permission for Browser Share media.');
        }
        return ['user_id'=>(int)($session['user_id']??0),'extension'=>true,'session'=>$session];
    }
    $user=current_user();
    $userId=(int)($user['id']??0);
    if($userId<1)throw new VP3BrowserShareMediaExceptionV2040('authentication_required',401,'Sign in to view Browser Share media.');
    if($write)throw new VP3BrowserShareMediaExceptionV2040('method_not_allowed',405,'Browser Share media uploads must come from an approved Browser Companion.');
    return ['user_id'=>$userId,'extension'=>false,'user'=>$user];
}

function vp3_browser_share_media_header_metadata_v2040(): array
{
    $encoded=trim((string)($_SERVER['HTTP_X_VP3_MEDIA_METADATA']??''));
    if($encoded==='')return [];
    if(strlen($encoded)>VP3_BROWSER_SHARE_MEDIA_METADATA_MAX_V2040*2)throw new VP3BrowserShareMediaExceptionV2040('payload_too_large',413,'Media metadata is too large.');
    $encoded=strtr($encoded,'-_','+/');
    $pad=strlen($encoded)%4;
    if($pad)$encoded.=str_repeat('=',4-$pad);
    $json=base64_decode($encoded,true);
    if(!is_string($json))throw new VP3BrowserShareMediaExceptionV2040('invalid_request',422,'Media metadata header is invalid.');
    $data=json_decode($json,true);
    if(!is_array($data))throw new VP3BrowserShareMediaExceptionV2040('invalid_request',422,'Media metadata must be valid JSON.');
    return $data;
}

function vp3_browser_share_media_verified_mime_v2040(string $kind,string $declared,string $detected): string
{
    $declared=strtolower(trim(explode(';',$declared,2)[0]));
    $detected=strtolower(trim(explode(';',$detected,2)[0]));
    if($kind==='screenshot')return $detected;
    if($kind!=='commentary_audio')return $declared;
    if(str_starts_with($detected,'audio/'))return $detected;
    $containerMatch=(
        ($declared==='audio/webm' && $detected==='video/webm')
        || ($declared==='audio/ogg' && $detected==='application/ogg')
        || ($declared==='audio/mp4' && in_array($detected,['video/mp4','application/mp4'],true))
    );
    if($containerMatch)return $declared;
    throw new VP3BrowserShareMediaExceptionV2040('unsupported_media_type',415,'Commentary bytes do not match an approved audio container.');
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_browser_share_media_json_v2040(204);
if(!in_array($method,['GET','POST'],true))vp3_browser_share_media_json_v2040(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);

$contract=trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''));
if($method==='POST' && $contract!=='1')vp3_browser_share_media_json_v2040(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);

try{
    $pdo=vp3_browser_share_media_require_ready_v2040(db());
    $auth=vp3_browser_share_media_auth_v2040($pdo,$method==='POST');
    $userId=(int)$auth['user_id'];

    if($method==='GET'){
        $mediaId=trim((string)($_GET['media_id']??''));
        if($mediaId!==''){
            $file=vp3_browser_share_media_file_v2040($pdo,$mediaId,$userId);
            $row=$file['row'];
            $path=$file['path'];
            $mime=trim((string)($row['mime_type']??'application/octet-stream')) ?: 'application/octet-stream';
            $name=preg_replace('/[^A-Za-z0-9._ -]+/','_',trim((string)($row['original_name']??''))) ?: 'browser-share-media';
            header('Content-Type: '.$mime);
            header('Content-Length: '.(string)filesize($path));
            header('Content-Disposition: inline; filename="'.str_replace(['"','\\'],['_','_'],$name).'"');
            header('Cache-Control: private, no-store');
            readfile($path);
            exit;
        }
        $shareId=trim((string)($_GET['browser_share_id']??''));
        if($shareId==='')throw new VP3BrowserShareMediaExceptionV2040('invalid_request',422,'browser_share_id is required.');
        vp3_browser_share_media_json_v2040(200,['ok'=>true,'media'=>vp3_browser_share_media_list_v2040($pdo,$shareId,$userId)]);
    }

    $contentType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''),2)[0]));
    if($contentType==='application/json'){
        $raw=(string)file_get_contents('php://input');
        if(strlen($raw)>VP3_BROWSER_SHARE_MEDIA_METADATA_MAX_V2040+4096)throw new VP3BrowserShareMediaExceptionV2040('payload_too_large',413,'Media reference payload is too large.');
        $input=json_decode($raw,true);
        if(!is_array($input))throw new VP3BrowserShareMediaExceptionV2040('invalid_request',422,'A JSON request body is required.');
        $shareId=trim((string)($input['browser_share_id']??''));
        $kind=trim((string)($input['kind']??''));
        $media=vp3_browser_share_media_create_reference_v2040($pdo,$shareId,$userId,$kind,is_array($input['metadata']??null)?$input['metadata']:[]);
        vp3_browser_share_media_json_v2040(201,['ok'=>true,'media'=>$media]);
    }

    $shareId=trim((string)($_GET['browser_share_id']??''));
    $kind=trim((string)($_SERVER['HTTP_X_VP3_MEDIA_KIND']??''));
    $name=trim(rawurldecode((string)($_SERVER['HTTP_X_VP3_MEDIA_NAME']??'')));
    if($shareId==='')throw new VP3BrowserShareMediaExceptionV2040('invalid_request',422,'browser_share_id is required.');
    $declaredMime=$contentType;
    $limit=$kind==='screenshot'?VP3_BROWSER_SHARE_MEDIA_SCREENSHOT_MAX_V2040:VP3_BROWSER_SHARE_MEDIA_COMMENTARY_MAX_V2040;
    $length=(int)($_SERVER['CONTENT_LENGTH']??0);
    if($length>$limit)throw new VP3BrowserShareMediaExceptionV2040('payload_too_large',413,'Media upload is too large.');
    $bytes=(string)file_get_contents('php://input',false,null,0,$limit+1);
    if(strlen($bytes)>$limit)throw new VP3BrowserShareMediaExceptionV2040('payload_too_large',413,'Media upload is too large.');
    if($bytes==='')throw new VP3BrowserShareMediaExceptionV2040('invalid_request',422,'Media upload is empty.');
    if(!class_exists('finfo'))throw new VP3BrowserShareMediaExceptionV2040('service_unavailable',503,'Server file inspection is unavailable.');

    $finfo=new finfo(FILEINFO_MIME_TYPE);
    $detected=(string)$finfo->buffer($bytes);
    if($detected==='')throw new VP3BrowserShareMediaExceptionV2040('unsupported_media_type',415,'Media type could not be verified.');
    $verifiedMime=vp3_browser_share_media_verified_mime_v2040($kind,$declaredMime,$detected);

    $media=vp3_browser_share_media_store_binary_v2040($pdo,$shareId,$userId,$kind,$verifiedMime,$bytes,$name,vp3_browser_share_media_header_metadata_v2040());
    vp3_browser_share_media_json_v2040(201,['ok'=>true,'media'=>$media]);
}catch(VP3BrowserShareMediaExceptionV2040 $e){
    vp3_browser_share_media_json_v2040($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(VP3BrowserShareExceptionV2010 $e){
    vp3_browser_share_media_json_v2040($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Browser Share media v20.40 failed: '.$e->getMessage());
    vp3_browser_share_media_json_v2040(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Share media request failed.']]);
}
