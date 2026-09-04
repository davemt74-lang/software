<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

function user_agent_api_v236(bool $ok,array $data=[],int $status=200): never
{
    http_response_code($status);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode(['ok'=>$ok]+$data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}

function user_agent_api_state_v236(PDO $pdo,array $user): array
{
    $state=user_agent_state_v236($pdo,$user);
    $state['data_usage']=user_data_usage_owner_state_v236($pdo,(int)$user['id'],30);
    $state['shared_knowledge']=['indexed'=>0,'active'=>0];
    if(shared_knowledge_index_schema_ready_v236($pdo)){
        $stmt=$pdo->prepare('SELECT COUNT(*) total,SUM(is_indexed=1 AND revoked_at IS NULL) active FROM shared_knowledge_index WHERE owner_user_id=?');
        $stmt->execute([(int)$user['id']]);$row=$stmt->fetch()?:[];
        $state['shared_knowledge']=['indexed'=>(int)($row['total']??0),'active'=>(int)($row['active']??0)];
    }
    return $state;
}

$user=current_user();
if(!$user||!has_permission('account.access',$user)||!has_permission('chat.access',$user))user_agent_api_v236(false,['error'=>'Agent settings are unavailable for this account.'],403);
$pdo=db();if(!$pdo)user_agent_api_v236(false,['error'=>'Database unavailable.'],503);
try{
    if(!user_agent_system_schema_ready_v236($pdo))user_agent_system_ensure_schema_v236($pdo);
    if(!user_data_usage_schema_ready_v236($pdo))user_data_usage_ensure_schema_v236($pdo);
    if(!shared_knowledge_index_schema_ready_v236($pdo))shared_knowledge_index_ensure_schema_v236($pdo);
}catch(Throwable $e){user_agent_api_v236(false,['error'=>'Agent settings are not ready. Run the database upgrade.'],503);}

if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')user_agent_api_v236(true,['state'=>user_agent_api_state_v236($pdo,$user)]);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')user_agent_api_v236(false,['error'=>'GET or POST required.'],405);
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$csrf=(string)($input['csrf_token']??'');if($csrf===''||!hash_equals(csrf_token(),$csrf))user_agent_api_v236(false,['error'=>'Session expired. Refresh and try again.'],419);
$action=(string)($input['action']??'');
try{
    if($action==='create_agent'){user_agent_create_v236($pdo,$user,$input);user_agent_api_v236(true,['state'=>user_agent_api_state_v236($pdo,$user)]);}
    if($action==='update_agent'){user_agent_update_v236($pdo,$user,$input);user_agent_api_v236(true,['state'=>user_agent_api_state_v236($pdo,$user)]);}
    if($action==='delete_agent'){
        $agentId=(int)($input['id']??0);
        if(!user_agent_get_v236($pdo,(int)$user['id'],$agentId))throw new RuntimeException('Agent not found.');
        $pdo->beginTransaction();
        try{
            // Agent-scoped conversations must never silently become system-agent
            // conversations through the FK's ON DELETE SET NULL behavior.
            $pdo->prepare('DELETE FROM chat_conversations WHERE user_id=? AND user_agent_id=?')->execute([(int)$user['id'],$agentId]);
            user_agent_delete_v236($pdo,$user,$agentId);
            $pdo->commit();
        }catch(Throwable $deleteError){if($pdo->inTransaction())$pdo->rollBack();throw $deleteError;}
        user_agent_api_v236(true,['state'=>user_agent_api_state_v236($pdo,$user)]);
    }
    if($action==='dismiss_onboarding'){user_agent_dismiss_onboarding_v236($pdo,(int)$user['id']);user_agent_api_v236(true,['state'=>user_agent_api_state_v236($pdo,$user)]);}
    if($action==='save_policy'){
        $saved=user_data_policy_save_v236($pdo,$user,$input);
        if((string)($input['resource_type']??'')==='knowledge'){
            $rid=(string)($input['resource_id']??'*');
            if($rid==='*')shared_knowledge_index_sync_owner_v236($pdo,(int)$user['id']);
            elseif(ctype_digit($rid))shared_knowledge_index_sync_item_v236($pdo,(int)$rid);
        }
        user_agent_api_v236(true,['saved_policy'=>$saved,'state'=>user_agent_api_state_v236($pdo,$user)]);
    }
    if($action==='save_rule'){user_agent_rule_save_v236($pdo,$user,(int)($input['agent_id']??0),(string)($input['resource_type']??''),(string)($input['access_mode']??'inherit'));user_agent_api_v236(true,['state'=>user_agent_api_state_v236($pdo,$user)]);}
    if($action==='refresh_shared_knowledge'){shared_knowledge_index_sync_owner_v236($pdo,(int)$user['id']);user_agent_api_v236(true,['state'=>user_agent_api_state_v236($pdo,$user)]);}
    user_agent_api_v236(false,['error'=>'Unknown agent settings action.'],422);
}catch(Throwable $e){user_agent_api_v236(false,['error'=>$e instanceof RuntimeException?$e->getMessage():'Agent settings request failed.'],$e instanceof RuntimeException?422:500);}