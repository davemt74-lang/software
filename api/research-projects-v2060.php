<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/research-projects-v2060.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

function vp3_research_api_json_v2060(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_RESEARCH_PROJECTS_V2060]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_research_api_auth_v2060(PDO $pdo): array
{
    $authorization=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
    if($authorization!==''){
        $session=vp3_extension_session_authenticate_v2001($pdo);
        if(!$session)vp3_research_api_json_v2060(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
        return ['user_id'=>(int)($session['user_id']??0),'extension'=>true,'session'=>$session];
    }
    $user=current_user();
    return ['user_id'=>(int)($user['id']??0),'extension'=>false,'user'=>$user];
}

function vp3_research_api_cap_v2060(array $auth,string $capability): void
{
    if(empty($auth['extension']))return;
    if(!vp3_extension_session_has_capability_v2001((array)$auth['session'],$capability)){
        vp3_research_api_json_v2060(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'This Browser Companion connection does not have permission for Research.']]);
    }
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_research_api_json_v2060(204);
if(!in_array($method,['GET','POST'],true))vp3_research_api_json_v2060(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);

if(trim((string)($_SERVER['HTTP_AUTHORIZATION']??''))!==''&&trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_research_api_json_v2060(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=vp3_research_require_ready_v2060(db());
    $auth=vp3_research_api_auth_v2060($pdo);
    $userId=(int)$auth['user_id'];
    $action=trim((string)($_GET['action']??''));

    if($method==='GET'){
        if($userId<1)vp3_research_api_json_v2060(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Sign in to use Research Projects.']]);
        vp3_research_api_cap_v2060($auth,'team.chat.read');

        if($action==='projects'){
            vp3_research_api_json_v2060(200,['ok'=>true,'projects'=>vp3_research_projects_for_user_v2060($pdo,$userId,!empty($_GET['include_archived']))]);
        }
        if($action==='inbox'){
            vp3_research_api_json_v2060(200,['ok'=>true,'items'=>vp3_research_inbox_v2060($pdo,$userId,max(1,min(200,(int)($_GET['limit']??100))))]);
        }
        if($action==='project'){
            $projectId=trim((string)($_GET['project_id']??''));
            vp3_research_api_json_v2060(200,['ok'=>true]+vp3_research_project_bundle_v2060($pdo,$projectId,$userId));
        }
        if($action==='report'){
            $report=vp3_research_report_row_v2060($pdo,trim((string)($_GET['report_id']??'')));
            if(!$report)vp3_research_api_json_v2060(404,['ok'=>false,'error'=>['code'=>'not_found','message'=>'Report was not found.']]);
            $project=vp3_research_project_row_by_id_v2060($pdo,(int)$report['project_id']);
            if(!$project||!vp3_research_role_at_least_v2060(vp3_research_project_role_v2060($pdo,$project,$userId),'viewer'))vp3_research_api_json_v2060(404,['ok'=>false,'error'=>['code'=>'not_found','message'=>'Report was not found.']]);
            vp3_research_api_json_v2060(200,['ok'=>true,'report'=>vp3_research_report_public_v2060($pdo,$report,$userId)]);
        }
        vp3_research_api_json_v2060(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Research action.']]);
    }

    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>256000)vp3_research_api_json_v2060(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Research request is too large.']]);
    $input=json_decode($raw,true);
    if(!is_array($input))$input=$_POST;
    $action=trim((string)($input['action']??$action));
    if($userId<1)vp3_research_api_json_v2060(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Sign in to use Research Projects.']]);

    if(empty($auth['extension'])){
        $csrf=trim((string)($input['csrf_token']??''));
        if($csrf===''||!hash_equals(csrf_token(),$csrf))vp3_research_api_json_v2060(419,['ok'=>false,'error'=>['code'=>'csrf','message'=>'Session expired.']]);
    }

    $writeActions=['create_project','update_project','set_member','remove_member','assign','create_finding','update_finding','finding_status','link_evidence','create_report','update_report','set_report_items','publish_report','unpublish_report'];
    if(in_array($action,$writeActions,true))vp3_research_api_cap_v2060($auth,'knowledge.write');

    if($action==='create_project'){
        $project=vp3_research_create_project_v2060($pdo,$userId,(string)($input['title']??''),(string)($input['description']??''),max(0,(int)($input['team_id']??0)));
        vp3_research_api_json_v2060(201,['ok'=>true,'project'=>$project]);
    }
    if($action==='update_project'){
        $project=vp3_research_update_project_v2060($pdo,$userId,trim((string)($input['project_id']??'')),[
            'title'=>$input['title']??'','description'=>$input['description']??'','status'=>$input['status']??'active',
        ]);
        vp3_research_api_json_v2060(200,['ok'=>true,'project'=>$project]);
    }
    if($action==='set_member'){
        $member=vp3_research_set_member_v2060($pdo,$userId,trim((string)($input['project_id']??'')),max(0,(int)($input['user_id']??0)),trim((string)($input['role']??'')));
        vp3_research_api_json_v2060(200,['ok'=>true,'member'=>$member]);
    }
    if($action==='remove_member'){
        vp3_research_remove_member_v2060($pdo,$userId,trim((string)($input['project_id']??'')),max(0,(int)($input['user_id']??0)));
        vp3_research_api_json_v2060(200,['ok'=>true]);
    }
    if($action==='assign'){
        $item=vp3_research_assign_share_v2060($pdo,$userId,trim((string)($input['project_id']??'')),trim((string)($input['browser_share_id']??'')),(string)($input['note']??''),$input['tags']??[]);
        vp3_research_api_json_v2060(201,['ok'=>true,'item'=>$item]);
    }
    if($action==='create_finding'){
        $finding=vp3_research_create_finding_v2060($pdo,$userId,trim((string)($input['project_id']??'')),(string)($input['title']??''),(string)($input['body']??''),trim((string)($input['evidence_item_id']??'')),trim((string)($input['evidence_role']??'support')));
        vp3_research_api_json_v2060(201,['ok'=>true,'finding'=>$finding]);
    }
    if($action==='update_finding'){
        $finding=vp3_research_update_finding_v2060($pdo,$userId,trim((string)($input['project_id']??'')),trim((string)($input['finding_id']??'')),(string)($input['title']??''),(string)($input['body']??''));
        vp3_research_api_json_v2060(200,['ok'=>true,'finding'=>$finding]);
    }
    if($action==='finding_status'){
        $finding=vp3_research_set_finding_status_v2060($pdo,$userId,trim((string)($input['project_id']??'')),trim((string)($input['finding_id']??'')),trim((string)($input['status']??'')));
        vp3_research_api_json_v2060(200,['ok'=>true,'finding'=>$finding]);
    }
    if($action==='link_evidence'){
        $finding=vp3_research_link_evidence_v2060($pdo,$userId,trim((string)($input['project_id']??'')),trim((string)($input['finding_id']??'')),trim((string)($input['item_id']??'')),trim((string)($input['role']??'support')),(string)($input['note']??''));
        vp3_research_api_json_v2060(200,['ok'=>true,'finding'=>$finding]);
    }
    if($action==='create_report'){
        $report=vp3_research_create_report_v2060($pdo,$userId,trim((string)($input['project_id']??'')),(string)($input['title']??''),(string)($input['summary']??''));
        vp3_research_api_json_v2060(201,['ok'=>true,'report'=>$report]);
    }
    if($action==='update_report'){
        $report=vp3_research_update_report_v2060($pdo,$userId,trim((string)($input['project_id']??'')),trim((string)($input['report_id']??'')),(string)($input['title']??''),(string)($input['summary']??''));
        vp3_research_api_json_v2060(200,['ok'=>true,'report'=>$report]);
    }
    if($action==='set_report_items'){
        $items=is_array($input['items']??null)?$input['items']:[];
        $report=vp3_research_set_report_items_v2060($pdo,$userId,trim((string)($input['project_id']??'')),trim((string)($input['report_id']??'')),$items);
        vp3_research_api_json_v2060(200,['ok'=>true,'report'=>$report]);
    }
    if($action==='publish_report'){
        $report=vp3_research_publish_report_v2060($pdo,$userId,trim((string)($input['project_id']??'')),trim((string)($input['report_id']??'')),trim((string)($input['visibility']??'private')),max(0,(int)($input['team_id']??0)));
        vp3_research_api_json_v2060(200,['ok'=>true,'report'=>$report]);
    }
    if($action==='unpublish_report'){
        $report=vp3_research_unpublish_report_v2060($pdo,$userId,trim((string)($input['project_id']??'')),trim((string)($input['report_id']??'')));
        vp3_research_api_json_v2060(200,['ok'=>true,'report'=>$report]);
    }

    vp3_research_api_json_v2060(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Research action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_research_api_json_v2060($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_research_api_json_v2060(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_research_api_json_v2060(422,['ok'=>false,'error'=>['code'=>'action_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Research Projects v20.60 failed: '.$e->getMessage());
    vp3_research_api_json_v2060(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Research request failed.']]);
}
