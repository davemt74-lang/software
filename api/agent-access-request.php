<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Agent-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('X-Content-Type-Options: nosniff');

function vp3_agent_access_json(int $status,array $payload): never
{
    http_response_code($status);
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_agent_access_json(204,[]);
if(!in_array($method,['GET','POST'],true))vp3_agent_access_json(405,['ok'=>false,'error'=>'Method not allowed.']);
$pdo=db();if(!$pdo||!vp3_radar_schema_ready($pdo))vp3_agent_access_json(503,['ok'=>false,'error'=>'VP3 Agent Access is not ready.']);
$username=profile_username_normalize((string)($_GET['username']??''));$context=vp3_agent_access_owner_context($pdo,$username);
if(!$context)vp3_agent_access_json(404,['ok'=>false,'error'=>'Agent messaging is not available for this profile.']);
$owner=(int)$context['profile']['user_id'];

if($method==='GET'){
    $token=vp3_agent_access_bearer_token();$request=vp3_agent_access_status_by_token($pdo,$owner,$token);
    if(!$request)vp3_agent_access_json(404,['ok'=>false,'error'=>'Agent access request not found.']);
    vp3_agent_access_json(200,['ok'=>true,'request'=>[
        'id'=>(int)$request['id'],'capability'=>(string)$request['capability'],'status'=>(string)$request['status'],
        'approved_until'=>$request['approved_until']?:null,'use_count'=>(int)$request['use_count'],
        'agent'=>['name'=>(string)$request['display_name'],'operator'=>(string)$request['operator_name']],
        'message_endpoint'=>in_array((string)$request['status'],['approved_once','approved'],true)?url('/api/agent-message.php?username='.rawurlencode($username)):null,
    ]]);
}

$raw=(string)file_get_contents('php://input');if(strlen($raw)>4096)vp3_agent_access_json(413,['ok'=>false,'error'=>'Payload too large.']);
$input=json_decode($raw,true);if(!is_array($input))$input=[];
$capability=trim((string)($input['capability']??'agent.message'));$purpose=(string)($input['purpose']??'');
$userAgent=vp3_radar_request_user_agent();
try{
    $result=vp3_agent_access_request_create($pdo,$context,$userAgent,$capability,$purpose);
    if(empty($result['created']))vp3_agent_access_json(409,['ok'=>false,'pending'=>true,'error'=>'A messaging access request from this agent is already pending. Keep the token returned with the original request and poll its status.']);
    vp3_agent_access_json(202,['ok'=>true,'request'=>[
        'id'=>(int)$result['request_id'],'capability'=>(string)$result['capability'],'status'=>'pending',
        'request_token'=>(string)$result['request_token'],
        'status_endpoint'=>url('/api/agent-access-request.php?username='.rawurlencode($username)),
    ],'note'=>'Keep request_token private. VP3 stores only its hash and cannot display it again.']);
}catch(Throwable $e){vp3_agent_access_json(403,['ok'=>false,'error'=>$e->getMessage()]);}
