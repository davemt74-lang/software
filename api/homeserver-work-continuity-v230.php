<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/homeserver-work-continuity-v230.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$user=current_user();$pdo=db();
if(!$user||!has_permission('account.access',$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Durable Agent work is not available for this account.']);exit;}
if(!$pdo||!homeserver_work_v230_schema_ready($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Durable cross-runtime work is not ready. Run the database upgrade.']);exit;}

try{
    homeserver_work_v230_reconcile_owner($pdo,$user);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $runId=max(0,(int)($_GET['run_id']??$_GET['id']??0));
        if($runId>0){
            $item=homeserver_work_v230_status($pdo,$user,$runId);
            if(!$item){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Durable work was not found.']);exit;}
            echo json_encode(['ok'=>true,'item'=>$item,'build'=>VP3_HOMESERVER_WORK_CONTINUITY_V230],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
        }
        $limit=max(1,min(100,(int)($_GET['limit']??30)));
        echo json_encode(['ok'=>true,'items'=>homeserver_work_v230_recent($pdo,$user,$limit),'build'=>VP3_HOMESERVER_WORK_CONTINUITY_V230],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: GET, POST');echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
    $input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
    $csrf=(string)($input['csrf_token']??'');if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh and try again.']);exit;}
    $action=trim((string)($input['action']??''));$runId=max(0,(int)($input['run_id']??0));$result=null;
    if($action==='enqueue'){
        $result=homeserver_work_v230_enqueue(
          $pdo,$user,(string)($input['title']??'Agent work'),(string)($input['instruction']??''),[
            'conversation_id'=>(string)($input['conversation_id']??''),
            'source_surface'=>(string)($input['source_surface']??'agent_chat'),
            'execution_target'=>(string)($input['execution_target']??'homeserver'),
            'capability_key'=>(string)($input['capability_key']??'agent.next_action'),
            'requires_approval'=>!empty($input['requires_approval']),
            'risk_level'=>(string)($input['risk_level']??'low'),
            'fallback_allowed'=>!array_key_exists('fallback_allowed',$input)||!empty($input['fallback_allowed']),
            'idempotency_key'=>(string)($input['idempotency_key']??''),
            'agent_id'=>(int)($input['agent_id']??0),
          ]
        );
    }elseif($action==='cancel'){
        $result=homeserver_work_v230_cancel($pdo,$user,$runId);
    }elseif($action==='retry'){
        $result=homeserver_work_v230_retry($pdo,$user,$runId);
    }elseif($action==='run_cloud'){
        $result=homeserver_work_v230_route($pdo,$user,$runId,'cloud');
    }elseif($action==='run_homeserver'){
        $result=homeserver_work_v230_route($pdo,$user,$runId,'homeserver');
    }else throw new RuntimeException('Unknown durable work action.');
    echo json_encode(['ok'=>true,'result'=>$result,'build'=>VP3_HOMESERVER_WORK_CONTINUITY_V230],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    http_response_code($e instanceof RuntimeException?400:500);
    echo json_encode(['ok'=>false,'error'=>$e instanceof RuntimeException?$e->getMessage():'Durable work request failed.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
