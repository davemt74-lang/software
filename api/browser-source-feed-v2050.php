<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/browser-source-feed-v2050.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

function vp3_browser_source_api_json_v2050(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_SOURCE_FEED_V2050]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_browser_source_api_auth_v2050(PDO $pdo): array
{
    $auth=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
    if($auth!==''){
        $session=vp3_extension_session_authenticate_v2001($pdo);
        if(!$session)vp3_browser_source_api_json_v2050(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
        return ['user_id'=>(int)($session['user_id']??0),'session'=>$session,'extension'=>true];
    }
    $user=current_user();
    return ['user_id'=>(int)($user['id']??0),'user'=>$user,'extension'=>false];
}

function vp3_browser_source_api_require_cap_v2050(array $auth,string $capability): void
{
    if(empty($auth['extension']))return;
    if(!vp3_extension_session_has_capability_v2001((array)$auth['session'],$capability)){
        vp3_browser_source_api_json_v2050(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'This browser connection does not have permission for that action.']]);
    }
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_browser_source_api_json_v2050(204);
if(!in_array($method,['GET','POST'],true))vp3_browser_source_api_json_v2050(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);

if(trim((string)($_SERVER['HTTP_AUTHORIZATION']??''))!=='' && trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_browser_source_api_json_v2050(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=vp3_browser_source_feed_require_ready_v2050(db());
    $auth=vp3_browser_source_api_auth_v2050($pdo);
    $userId=(int)$auth['user_id'];
    $action=trim((string)($_GET['action']??''));

    if($method==='GET'){
        if($action==='this_page'){
            vp3_browser_source_api_require_cap_v2050($auth,'team.chat.read');
            $url=trim((string)($_GET['url']??''));
            if($url==='')throw new InvalidArgumentException('A source URL is required.');
            $feed=vp3_browser_source_this_page_v2050(
                $pdo,$userId,$url,trim((string)($_GET['canonical_url']??'')),trim((string)($_GET['title']??'')),
                max(1,min(VP3_BROWSER_SOURCE_FEED_LIMIT_MAX_V2050,(int)($_GET['limit']??25))),
                trim((string)($_GET['cursor']??'')),
                trim((string)($_GET['source_version_hash']??''))
            );
            vp3_browser_source_api_json_v2050(200,['ok'=>true,'feed'=>$feed]);
        }
        if($action==='following'){
            vp3_browser_source_api_require_cap_v2050($auth,'team.chat.read');
            if($userId<1)vp3_browser_source_api_json_v2050(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Sign in to view Following.']]);
            $feed=vp3_browser_source_following_v2050(
                $pdo,$userId,max(1,min(VP3_BROWSER_SOURCE_FEED_LIMIT_MAX_V2050,(int)($_GET['limit']??20))),
                trim((string)($_GET['cursor']??''))
            );
            vp3_browser_source_api_json_v2050(200,['ok'=>true,'feed'=>$feed]);
        }
        if($action==='annotation'){
            $row=vp3_browser_source_share_row_v2050($pdo,trim((string)($_GET['browser_share_id']??'')));
            if(!$row||!vp3_browser_source_share_authorized_v2050($pdo,$row,$userId))vp3_browser_source_api_json_v2050(404,['ok'=>false,'error'=>['code'=>'not_found','message'=>'Annotation was not found.']]);
            vp3_browser_source_api_json_v2050(200,['ok'=>true,'annotation'=>vp3_browser_source_item_v2050($pdo,$row,$userId,true)]);
        }
        vp3_browser_source_api_json_v2050(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Source Feed action.']]);
    }

    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>16384)vp3_browser_source_api_json_v2050(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    $input=json_decode($raw,true);
    if(!is_array($input))$input=$_POST;
    $action=trim((string)($input['action']??$action));

    if(empty($auth['extension'])){
        $csrf=trim((string)($input['csrf_token']??''));
        if($userId<1)vp3_browser_source_api_json_v2050(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Sign in to use this action.']]);
        if($csrf===''||!hash_equals(csrf_token(),$csrf))vp3_browser_source_api_json_v2050(419,['ok'=>false,'error'=>['code'=>'csrf','message'=>'Session expired.']]);
    }

    if($action==='publish'){
        vp3_browser_source_api_require_cap_v2050($auth,'team.share.create');
        $item=vp3_browser_source_publish_v2050(
            $pdo,$userId,trim((string)($input['browser_share_id']??'')),trim((string)($input['visibility']??'private')),
            max(0,(int)($input['team_id']??0)),trim((string)($input['source_version_hash']??''))
        );
        vp3_browser_source_api_json_v2050(200,['ok'=>true,'annotation'=>$item]);
    }
    if($action==='follow_source'){
        vp3_browser_source_api_require_cap_v2050($auth,'team.chat.read');
        $result=vp3_browser_source_follow_source_v2050(
            $pdo,$userId,trim((string)($input['source_id']??'')),!empty($input['follow']),
            trim((string)($input['url']??'')),trim((string)($input['canonical_url']??'')),trim((string)($input['title']??''))
        );
        vp3_browser_source_api_json_v2050(200,['ok'=>true]+$result);
    }
    if($action==='follow_user'){
        vp3_browser_source_api_require_cap_v2050($auth,'team.chat.read');
        $result=vp3_browser_source_follow_user_v2050($pdo,$userId,max(0,(int)($input['user_id']??0)),!empty($input['follow']));
        vp3_browser_source_api_json_v2050(200,['ok'=>true]+$result);
    }
    if($action==='comment'){
        vp3_browser_source_api_require_cap_v2050($auth,'team.chat.read');
        $result=vp3_browser_source_comment_v2050(
            $pdo,$userId,trim((string)($input['browser_share_id']??'')),(string)($input['body']??''),trim((string)($input['parent_id']??''))
        );
        vp3_browser_source_api_json_v2050(201,['ok'=>true]+$result);
    }
    if($action==='save'){
        vp3_browser_source_api_require_cap_v2050($auth,'knowledge.write');
        vp3_browser_source_api_json_v2050(200,['ok'=>true]+vp3_browser_source_toggle_share_state_v2050(
            $pdo,$userId,trim((string)($input['browser_share_id']??'')),'save',!empty($input['enabled'])
        ));
    }
    if($action==='research'){
        vp3_browser_source_api_require_cap_v2050($auth,'knowledge.write');
        vp3_browser_source_api_json_v2050(200,['ok'=>true]+vp3_browser_source_toggle_share_state_v2050(
            $pdo,$userId,trim((string)($input['browser_share_id']??'')),'research',!empty($input['enabled'])
        ));
    }
    if($action==='read'){
        vp3_browser_source_api_require_cap_v2050($auth,'team.chat.read');
        vp3_browser_source_api_json_v2050(200,['ok'=>true]+vp3_browser_source_mark_read_v2050(
            $pdo,$userId,trim((string)($input['browser_share_id']??''))
        ));
    }
    if($action==='share_team'){
        vp3_browser_source_api_require_cap_v2050($auth,'team.share.create');
        $destination=is_array($input['destination']??null)?$input['destination']:[];
        vp3_browser_source_api_json_v2050(201,['ok'=>true]+vp3_browser_source_share_team_v2050(
            $pdo,$userId,trim((string)($input['browser_share_id']??'')),$destination
        ));
    }

    vp3_browser_source_api_json_v2050(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Source Feed action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_browser_source_api_json_v2050($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(VP3BrowserShareExceptionV2010 $e){
    vp3_browser_source_api_json_v2050($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_browser_source_api_json_v2050(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_browser_source_api_json_v2050(422,['ok'=>false,'error'=>['code'=>'action_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Browser Source Feed v20.50 failed: '.$e->getMessage());
    vp3_browser_source_api_json_v2050(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Source Feed request failed.']]);
}
