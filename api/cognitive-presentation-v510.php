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
$agentId=max(0,(int)($input['agent_id']??0));
try{
    $action=trim((string)($input['action']??'state'));
    $readState=$method==='GET'&&$action==='state';
    $liveConversationId=null;
    if($readState){
        // Existing clients that supplied a binding used it to request working attention.
        $includeWorkingAttention=$input['include_working_attention']??'1';
        if(!in_array($includeWorkingAttention,['0','1'],true))vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'invalid_working_attention']);
        $includeLiveContext=$input['include_live_context']??'0';
        if(!in_array($includeLiveContext,['0','1'],true))vp3_cognitive_presentation_api_json_v510(422,['ok'=>false,'error'=>'invalid_live_context']);
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
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);
    if($readState){
        $greetingPreview=$includeWorkingAttention==='1'&&($input['greeting_preview']??'')==='1';
        vp3_cognitive_presentation_api_json_v510(200,['ok'=>true,'state'=>vp3_cognitive_presentation_state_v510($pdo,$user,$namespace,$agentId,$includeWorkingAttention==='1',$greetingPreview,$liveConversationId)]);
    }
    if($method!=='POST')vp3_cognitive_presentation_api_json_v510(405,['ok'=>false,'error'=>'method_not_allowed']);
    if(!hash_equals(csrf_token(),trim((string)($input['csrf_token']??''))))vp3_cognitive_presentation_api_json_v510(419,['ok'=>false,'error'=>'csrf']);

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
    error_log('VP3 Cognitive Presentation v5.10 API: '.$e->getMessage());
    vp3_cognitive_presentation_api_json_v510(500,['ok'=>false,'error'=>'presentation_unavailable']);
}
