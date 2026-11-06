<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function vp3_cognitive_presentation_api_json_v510(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode(['build'=>VP3_COGNITIVE_PRESENTATION_V510]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function vp3_cognitive_presentation_api_input_v510(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>16384)vp3_cognitive_presentation_api_json_v510(413,['ok'=>false,'error'=>'payload_too_large']);
    $json=json_decode($raw,true);
    return is_array($json)?$json:$_POST;
}

$user=current_user();
if(!$user||!has_permission('chat.access',$user))vp3_cognitive_presentation_api_json_v510(403,['ok'=>false,'error'=>'forbidden']);
$pageBinding=(string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??'');
if($pageBinding===''||!hash_equals(vp3_catch_up_page_binding_v1($user),$pageBinding)){
    vp3_cognitive_presentation_api_json_v510(403,['ok'=>false,'error'=>'account_changed']);
}
$pdo=db();
if(!$pdo)vp3_cognitive_presentation_api_json_v510(503,['ok'=>false,'error'=>'database_unavailable']);
if(!vp3_cognitive_presentation_schema_ready_v510($pdo))vp3_cognitive_presentation_api_json_v510(503,['ok'=>false,'error'=>'presentation_schema_not_ready']);

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$input=$method==='POST'?vp3_cognitive_presentation_api_input_v510():$_GET;
$rawAgentId=$input['agent_id']??0;
$agentId=is_int($rawAgentId)||is_string($rawAgentId)?max(0,(int)$rawAgentId):0;
try{
    $action=trim((string)($input['action']??'state'));
    $readState=$method==='GET'&&$action==='state';
    $liveConversationId=null;
    $includeInitiativeRuntime=false;
    if($readState){
        // Existing clients that supplied a binding used it to request working attention.
        $includeWorkingAttention=$input['include_working_attention']??'1';
        if(!in_array($includeWorkingAttention,['0','1'],true))vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'invalid_working_attention']);
        $includeLiveContext=$input['include_live_context']??'0';
        if(!in_array($includeLiveContext,['0','1'],true))vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'invalid_live_context']);
        $runtime=$input['include_initiative_runtime']??'0';
        if(!in_array($runtime,['0','1'],true)||($runtime==='1'&&$includeLiveContext!=='1')){
            vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'invalid_initiative_runtime']);
        }
        $includeInitiativeRuntime=$runtime==='1';
        if($includeLiveContext==='1'){
            $conversation=$input['conversation_id']??null;
            $rawAgent=$input['agent_id']??'0';
            if(!is_string($conversation)||!preg_match('/^[1-9][0-9]{0,17}$/D',$conversation)
                ||!is_string($rawAgent)||!preg_match('/^(?:0|[1-9][0-9]{0,17})$/D',$rawAgent)){
                vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'invalid_live_context']);
            }
            $liveConversationId=(int)$conversation;
            try{$agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);}
            catch(PDOException $e){throw $e;}
            catch(RuntimeException $e){vp3_cognitive_presentation_api_json_v510(404,['ok'=>false,'error'=>'conversation_not_found']);}
            if(!vp3_agent_chat_conversation_v380($pdo,$liveConversationId,(int)$user['id'],$agent)){
                vp3_cognitive_presentation_api_json_v510(404,['ok'=>false,'error'=>'conversation_not_found']);
            }
        }
    }
    $initiativeActions=['initiative_tick','initiative_setting','voice_candidates','voice_claim','voice_finish'];
    $initiativeMutation=$method==='POST'&&in_array($action,$initiativeActions,true);
    $namespace=$initiativeMutation?'':vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
    if($readState){
        // Source projection may take longer than the lightweight lifecycle
        // heartbeat. Do not hold the account's PHP session lock behind it.
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $greetingPreview=$includeWorkingAttention==='1'&&($input['greeting_preview']??'')==='1';
        $state=vp3_cognitive_presentation_state_v510($pdo,$user,$namespace,$agentId,$includeWorkingAttention==='1',$greetingPreview,$liveConversationId);
        if($includeInitiativeRuntime){
            // Observability is a read. Scheduling, model calls and tool execution
            // are exclusively performed by the explicitly bound POST below.
            require_once dirname(__DIR__).'/includes/agent-initiative-runtime-v1.php';
            $state['live_context']['initiative']['runtime']=vp3_initiative_worker_health_v1($pdo,$user,$agentId,$liveConversationId);
        }
        vp3_cognitive_presentation_api_json_v510(200,['ok'=>true,'state'=>$state]);
    }
    if($method!=='POST')vp3_cognitive_presentation_api_json_v510(405,['ok'=>false,'error'=>'method_not_allowed']);
    if(!hash_equals(csrf_token(),trim((string)($input['csrf_token']??''))))vp3_cognitive_presentation_api_json_v510(419,['ok'=>false,'error'=>'csrf']);

    if(in_array($action,$initiativeActions,true)){
        $rawAgent=$input['agent_id']??0;
        $rawConversation=$input['conversation_id']??null;
        $validId=static fn(mixed $id,bool $zero):bool=>(is_int($id)||is_string($id))
            &&preg_match($zero?'/^(?:0|[1-9][0-9]{0,17})$/D':'/^[1-9][0-9]{0,17}$/D',(string)$id)===1
            &&filter_var($id,FILTER_VALIDATE_INT)!==false;
        if(!$validId($rawAgent,true)||!$validId($rawConversation,false)){
            vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'invalid_initiative_context']);
        }
        $conversationId=(int)$rawConversation;
        try{$agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);}
        catch(PDOException $e){throw $e;}
        catch(RuntimeException $e){vp3_cognitive_presentation_api_json_v510(404,['ok'=>false,'error'=>'conversation_not_found']);}
        if(!vp3_agent_chat_conversation_v380($pdo,$conversationId,(int)$user['id'],$agent)){
            vp3_cognitive_presentation_api_json_v510(404,['ok'=>false,'error'=>'conversation_not_found']);
        }
        require_once dirname(__DIR__).'/includes/agent-initiative-runtime-v1.php';
        if(in_array($action,['voice_candidates','voice_claim','voice_finish'],true)){
            require_once dirname(__DIR__).'/includes/agent-initiative-voice-v1.php';
            if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
            $voice=vp3_initiative_voice_v1($pdo,$user,$agentId,$conversationId,$action,$input);
            vp3_cognitive_presentation_api_json_v510(200,$voice);
        }
        if($action==='initiative_setting'){
            if(!is_bool($input['enabled']??null))vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'invalid_initiative_setting']);
            $revision=$input['policy_revision']??null;
            if(!is_string($revision)||($revision!==''&&!preg_match('/^[a-f0-9]{32}$/D',$revision))){
                vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'invalid_initiative_revision']);
            }
            $settingContext=['conversation_id'=>$conversationId,'initiative_expected_revision'=>$revision];
            if(array_key_exists('knowledge_scope',$input)){
                if(!is_array($input['knowledge_scope']))vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'invalid_knowledge_scope']);
                $settingContext['knowledge_scope']=$input['knowledge_scope'];
            }
            $principal=vp3_agent_chat_principal_v380($user,$agent);
            $setting=vp3_initiative_chat_v1($pdo,$user,$principal,$settingContext,$input['enabled']?'on':'off',null);
            if(($setting['reason']??'')==='settings_changed'){
                vp3_cognitive_presentation_api_json_v510(409,['ok'=>false,'error'=>'initiative_settings_changed']);
            }
            $policy=vp3_initiative_policy_v1($user,$agentId);
            if(($setting['ok']??false)!==true||!array_key_exists('enabled',$policy)||!empty($policy['enabled'])!==$input['enabled']
                ||($input['enabled']&&($policy['conversation_id']??null)!==$conversationId)){
                vp3_cognitive_presentation_api_json_v510(409,['ok'=>false,'error'=>'initiative_setting_unconfirmed']);
            }
            vp3_cognitive_presentation_api_json_v510(200,['ok'=>true,'health'=>vp3_initiative_worker_health_v1($pdo,$user,$agentId,$conversationId)]);
        }
        $revision=$input['policy_revision']??null;
        if(!is_string($revision)||!preg_match('/^[a-f0-9]{32}$/D',$revision)){
            vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'invalid_initiative_revision']);
        }
        // Release the PHP session lock before bounded model/tool work so an
        // ordinary owner turn is never blocked behind a foreground tick.
        @set_time_limit(180);
        ignore_user_abort(true);
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $result=vp3_initiative_pump_v1($pdo,$user,$agentId,$conversationId,$revision);
        vp3_cognitive_presentation_api_json_v510(200,$result);
    }

    if($action==='interaction'){
        vp3_cognitive_presentation_touch_v510($pdo,$user,$namespace);
        vp3_cognitive_presentation_api_json_v510(200,['ok'=>true]);
    }
    if($action==='digest_ack'){
        $id=trim((string)($input['digest_id']??''));
        if($id==='')vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'digest_required']);
        vp3_cognitive_presentation_digest_ack_v510($pdo,$user,$namespace,$id,(string)($input['status']??'acknowledged'));
        vp3_cognitive_presentation_api_json_v510(200,['ok'=>true]);
    }
    if($action==='voice_delivered'){
        vp3_cognitive_presentation_voice_delivered_v510($pdo,$user,$namespace,max(0,(int)($input['through_id']??0)));
        vp3_cognitive_presentation_api_json_v510(200,['ok'=>true]);
    }
    if($action==='voice_suppressed'){
        vp3_cognitive_presentation_voice_suppressed_v510($pdo,$user,$namespace,max(0,(int)($input['through_id']??0)));
        vp3_cognitive_presentation_api_json_v510(200,['ok'=>true]);
    }
    vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'unknown_action']);
}catch(DomainException|InvalidArgumentException $e){
    vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>$e->getMessage()]);
}catch(Throwable $e){
    if(in_array($action??'',['initiative_tick','initiative_setting','voice_candidates','voice_claim','voice_finish'],true)&&in_array($e->getCode(),[403,404,409,503],true)){
        $reason=$e->getCode()===409?'initiative_settings_changed':($e->getCode()===503?'initiative_unavailable':'initiative_context_changed');
        vp3_cognitive_presentation_api_json_v510($e->getCode(),['ok'=>false,'error'=>$reason]);
    }
    error_log('VP3 Cognitive Presentation v5.10 API: '.$e->getMessage());
    vp3_cognitive_presentation_api_json_v510(500,['ok'=>false,'error'=>'presentation_unavailable']);
}
