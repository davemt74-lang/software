<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-event-infrastructure-v1920.php';
require_once dirname(__DIR__).'/includes/agent-event-routes-v1920.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$user=current_user();$pdo=db();
if(!$user||!has_permission('account.access',$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Agent Event access is not available for this account.']);exit;}
if(!$pdo||!agent_event_schema_ready_v1920($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Agent Events are not ready. An administrator needs to run the Phase 19.2 database upgrade.']);exit;}
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $id=max(0,(int)($_GET['id']??0));if($id>0){$row=agent_event_row_v1920($pdo,(int)$user['id'],$id);if(!$row){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Event not found.']);exit;}echo json_encode(['ok'=>true,'event'=>agent_event_public_v1920($row),'build'=>VP3_AGENT_EVENT_INFRA_V1920],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
        echo json_encode(['ok'=>true,'events'=>agent_event_recent_v1920($pdo,$user,max(1,min(100,(int)($_GET['limit']??30)))),'build'=>VP3_AGENT_EVENT_INFRA_V1920],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
    $input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;$csrf=(string)($input['csrf_token']??'');if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh and try again.']);exit;}
    if((string)($input['action']??'')!=='replay')throw new RuntimeException('Unknown event action.');$result=agent_event_dispatch_v1920($pdo,$user,(int)($input['event_id']??0),true);
    echo json_encode(['ok'=>true,'result'=>$result,'build'=>VP3_AGENT_EVENT_INFRA_V1920],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){$safe=$e instanceof RuntimeException?$e->getMessage():'Event request failed.';http_response_code($e instanceof RuntimeException?400:500);echo json_encode(['ok'=>false,'error'=>$safe],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
