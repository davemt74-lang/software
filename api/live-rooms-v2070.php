<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/live-rooms-v2070.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

function vp3_live_room_api_json_v2070(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_LIVE_ROOMS_V2070]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_live_room_api_auth_v2070(PDO $pdo): array
{
    $auth=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
    if($auth!==''){
        $session=vp3_extension_session_authenticate_v2001($pdo);
        if(!$session)vp3_live_room_api_json_v2070(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
        return ['user_id'=>(int)($session['user_id']??0),'session'=>$session,'extension'=>true];
    }
    $user=current_user();
    return ['user_id'=>(int)($user['id']??0),'user'=>$user,'extension'=>false];
}

function vp3_live_room_api_cap_v2070(array $auth,string $capability): void
{
    if(empty($auth['extension']))return;
    if(!vp3_extension_session_has_capability_v2001((array)$auth['session'],$capability)){
        vp3_live_room_api_json_v2070(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'This browser connection does not have permission for that Live Room action.']]);
    }
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_live_room_api_json_v2070(204);
if(!in_array($method,['GET','POST'],true))vp3_live_room_api_json_v2070(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);
if(trim((string)($_SERVER['HTTP_AUTHORIZATION']??''))!==''&&trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_live_room_api_json_v2070(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=vp3_live_room_require_ready_v2070(db());
    $auth=vp3_live_room_api_auth_v2070($pdo);
    $userId=(int)$auth['user_id'];
    $action=trim((string)($_GET['action']??''));

    if($method==='GET'){
        vp3_annotated_rate_limit_v2100($pdo,$userId,'live_read');
        if($action==='list'){
            vp3_live_room_api_cap_v2070($auth,'team.chat.read');
            vp3_live_room_api_json_v2070(200,['ok'=>true,'rooms'=>vp3_live_room_list_v2070($pdo,$userId,max(1,min(100,(int)($_GET['limit']??50))))]);
        }
        if($action==='source_rooms'){
            vp3_live_room_api_cap_v2070($auth,'team.chat.read');
            $url=trim((string)($_GET['url']??''));
            if($url==='')throw new InvalidArgumentException('A source URL is required.');
            vp3_live_room_api_json_v2070(200,['ok'=>true]+vp3_live_room_rooms_for_source_v2070(
                $pdo,$userId,$url,trim((string)($_GET['canonical_url']??'')),trim((string)($_GET['title']??''))
            ));
        }
        if($action==='room'){
            vp3_live_room_api_cap_v2070($auth,'team.chat.read');
            $room=vp3_live_room_require_v2070($pdo,trim((string)($_GET['room']??'')),$userId,false);
            vp3_live_room_api_json_v2070(200,['ok'=>true,'room'=>vp3_live_room_public_v2070($pdo,$room,$userId,true)]);
        }
        if($action==='poll'){
            vp3_live_room_api_cap_v2070($auth,'team.chat.read');
            $payload=vp3_live_room_poll_v2070($pdo,$userId,trim((string)($_GET['room']??'')),max(0,(int)($_GET['after']??0)),false);
            vp3_live_room_api_json_v2070(200,['ok'=>true]+$payload);
        }
        vp3_live_room_api_json_v2070(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Live Room action.']]);
    }

    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>16384)vp3_live_room_api_json_v2070(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    $input=json_decode($raw,true);
    if(!is_array($input))$input=$_POST;
    $action=trim((string)($input['action']??$action));

    if($userId<1)vp3_live_room_api_json_v2070(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Sign in to use Live Rooms.']]);
    if(empty($auth['extension'])){
        $csrf=trim((string)($input['csrf_token']??''));
        if($csrf===''||!hash_equals(csrf_token(),$csrf))vp3_live_room_api_json_v2070(419,['ok'=>false,'error'=>['code'=>'csrf','message'=>'Session expired.']]);
    }
    if($action==='create')vp3_annotated_rate_limit_v2100($pdo,$userId,'live_create');
    elseif($action==='send')vp3_annotated_rate_limit_v2100($pdo,$userId,'live_send');
    else vp3_annotated_rate_limit_v2100($pdo,$userId,'live_read');

    if($action==='create'){
        vp3_live_room_api_cap_v2070($auth,'team.share.create');
        vp3_live_room_api_json_v2070(201,['ok'=>true,'room'=>vp3_live_room_create_v2070($pdo,$userId,$input)]);
    }
    if($action==='join'){
        vp3_live_room_api_cap_v2070($auth,'team.chat.read');
        vp3_live_room_api_json_v2070(200,['ok'=>true,'room'=>vp3_live_room_join_v2070($pdo,$userId,trim((string)($input['room']??'')),!empty($input['cloak_mode']))]);
    }
    if($action==='leave'){
        vp3_live_room_api_cap_v2070($auth,'team.chat.read');
        vp3_live_room_api_json_v2070(200,['ok'=>true]+vp3_live_room_leave_v2070($pdo,$userId,trim((string)($input['room']??''))));
    }
    if($action==='heartbeat'){
        vp3_live_room_api_cap_v2070($auth,'team.chat.read');
        vp3_live_room_heartbeat_v2070($pdo,$userId,trim((string)($input['room']??'')));
        vp3_live_room_api_json_v2070(200,['ok'=>true,'present'=>true]);
    }
    if($action==='cloak'){
        vp3_live_room_api_cap_v2070($auth,'team.chat.read');
        vp3_live_room_api_json_v2070(200,['ok'=>true]+vp3_live_room_cloak_v2070($pdo,$userId,trim((string)($input['room']??'')),!empty($input['enabled'])));
    }
    if($action==='send'){
        vp3_live_room_api_cap_v2070($auth,'team.share.create');
        vp3_live_room_api_json_v2070(201,['ok'=>true,'message'=>vp3_live_room_send_v2070(
            $pdo,$userId,trim((string)($input['room']??'')),(string)($input['body']??''),trim((string)($input['browser_share_id']??''))
        )]);
    }
    if($action==='end'){
        vp3_live_room_api_cap_v2070($auth,'team.share.create');
        vp3_live_room_api_json_v2070(200,['ok'=>true,'room'=>vp3_live_room_end_v2070($pdo,$userId,trim((string)($input['room']??'')))]);
    }
    vp3_live_room_api_json_v2070(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Live Room action.']]);
}catch(VP3AnnotatedRateLimitExceptionV2100 $e){header('Retry-After: '.$e->retryAfter);vp3_live_room_api_json_v2070(429,['ok'=>false,'error'=>['code'=>'rate_limited','message'=>$e->getMessage()]]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_live_room_api_json_v2070($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_live_room_api_json_v2070(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_live_room_api_json_v2070(422,['ok'=>false,'error'=>['code'=>'action_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Live Rooms v20.70 failed: '.$e->getMessage());
    vp3_live_room_api_json_v2070(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Live Room request failed.']]);
}
