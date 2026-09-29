<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-v100.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-analytics-v130.php';
require_once dirname(__DIR__) . '/includes/profile-agent-transcription-context.php';
require_once dirname(__DIR__) . '/includes/profile-agent-public-service-v110.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-chat-v140.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-scheduling-v150.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function vp3_profile_webmcp_json_v100(bool $ok, array $payload = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok], $payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_profile_webmcp_tool_json_v130(PDO $pdo,?array $telemetryContext,string $tool,float $startedAt,bool $ok,array $payload=[],int $status=200,string $resultCode=''): never
{
    $duration=(int)max(0,round((microtime(true)-$startedAt)*1000));
    vp3_profile_webmcp_record_v130(
        $pdo,$telemetryContext,$ok?'webmcp_tool_completed':'webmcp_tool_failed',
        $tool,$ok?'completed':'failed',$duration,['result_code'=>$resultCode]
    );
    vp3_profile_webmcp_json_v100($ok,$payload,$status);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'POST is required.']], 405);
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > VP3_PROFILE_WEBMCP_MAX_BODY_V100) {
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'PAYLOAD_TOO_LARGE','message'=>'Request is too large.']], 413);
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'INVALID_JSON','message'=>'Invalid JSON request.']], 400);
}

$pdo = db();
if (!$pdo || !profile_agent_schema_ready($pdo)) {
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'PROFILE_UNAVAILABLE','message'=>'Profile services are unavailable.']], 503);
}

$username = profile_username_normalize((string)($input['profile_username'] ?? ''));
$profile = $username !== '' ? profile_by_username($pdo, $username) : null;
if (!$profile || empty($profile['is_active']) || empty($profile['is_public'])) {
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'PROFILE_NOT_FOUND','message'=>'Profile not found.']], 404);
}

$ownerUserId = (int)$profile['user_id'];
$telemetry=vp3_profile_webmcp_telemetry_v130($input);
$proof = trim((string)($_SERVER['HTTP_X_VP3_WEBMCP_SESSION'] ?? ''));
if (!vp3_profile_webmcp_session_proof_valid_v100($ownerUserId, $proof)) {
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'SESSION_PROOF_REQUIRED','message'=>'The profile agent session is not authorized.']], 403);
}
if (!vp3_profile_webmcp_native_origin_allowed_v100(
    (string)($_SERVER['HTTP_ORIGIN'] ?? ''),
    (string)($_SERVER['HTTP_HOST'] ?? '')
)) {
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'ORIGIN_DENIED','message'=>'The request origin is not authorized.']], 403);
}
if (!vp3_profile_webmcp_rate_limit_v100($ownerUserId)) {
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'RATE_LIMITED','message'=>'Too many profile agent requests.']], 429);
}
if ((string)($input['manifest_version'] ?? '') !== VP3_PROFILE_WEBMCP_MANIFEST_V100) {
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'MANIFEST_VERSION_UNSUPPORTED','message'=>'Unsupported profile WebMCP manifest.']], 400);
}
if ((string)($input['surface'] ?? '') !== 'native_profile') {
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'SURFACE_MISMATCH','message'=>'This endpoint accepts only native-profile WebMCP requests.']], 403);
}

$viewer = current_user();
try {
    $manifest = vp3_profile_webmcp_manifest_v100($pdo, $profile, $viewer, ['surface'=>'native_profile']);
} catch (Throwable $e) {
    error_log('VP3 Profile WebMCP manifest failed @'.$username.': '.$e->getMessage());
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'PROFILE_UNAVAILABLE','message'=>'Profile capabilities are temporarily unavailable.']], 503);
}
$tool = trim((string)($input['tool'] ?? ''));
$catalog = vp3_profile_webmcp_tool_catalog_v100();
$telemetryContext=vp3_profile_webmcp_context_v130($pdo,$profile,'native_profile',$telemetry,null,$viewer);
if (!isset($catalog[$tool]) || !in_array($tool, $manifest['allowed_tools'], true)) {
    vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_tool_denied',$tool,'denied',0,['result_code'=>'CAPABILITY_UNAVAILABLE']);
    vp3_profile_webmcp_json_v100(false,['error'=>['code'=>'CAPABILITY_UNAVAILABLE','message'=>'That profile capability is unavailable.']],404);
}
$args = is_array($input['input'] ?? null) ? $input['input'] : [];
$startedAt=microtime(true);
vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_tool_called',$tool,'called');

try {
    if ($tool === 'vp3.profile.capabilities.get') {
        vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,[
            'manifest_version'=>VP3_PROFILE_WEBMCP_MANIFEST_V100,
            'capabilities'=>$manifest['capabilities'],
            'allowed_tools'=>$manifest['allowed_tools'],
        ]);
    }
    if ($tool === 'vp3.profile.get') {
        vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,['profile'=>vp3_profile_webmcp_public_profile_v100($profile)]);
    }
    if ($tool === 'vp3.intent.resolve') {
        $goal = trim((string)($args['goal'] ?? ''));
        vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,['resolution'=>vp3_profile_webmcp_resolve_intent_v100($goal, $manifest)]);
    }
    if (str_starts_with($tool, 'vp3.booking.')) {
        $schedulingContext=vp3_profile_webmcp_scheduling_context_v150($profile,'native_profile',$telemetry,$proof,null,'');
        if ($tool === 'vp3.booking.options.list') {
            vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,['appointment_types'=>vp3_profile_webmcp_scheduling_options_v150($pdo,$profile)]);
        }
        if ($tool === 'vp3.booking.availability.list') {
            vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,vp3_profile_webmcp_scheduling_availability_v150($pdo,$profile,$args));
        }
        if ($tool === 'vp3.booking.get') {
            vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,vp3_profile_webmcp_scheduling_get_v150($pdo,$profile,$args));
        }
        if (in_array($tool,['vp3.booking.prepare','vp3.booking.reschedule.prepare','vp3.booking.cancel.prepare'],true)) {
            if(!vp3_profile_webmcp_actions_schema_ready_v150($pdo)){
                vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,['error'=>['code'=>'SCHEDULING_UNAVAILABLE','message'=>'Booking confirmation ledger is unavailable.']],503,'SCHEDULING_UNAVAILABLE');
            }
            $operation=match($tool){
                'vp3.booking.prepare'=>'booking.create',
                'vp3.booking.reschedule.prepare'=>'booking.reschedule',
                default=>'booking.cancel',
            };
            $result=vp3_profile_webmcp_scheduling_prepare_v150($pdo,$profile,$schedulingContext,$operation,$args);
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_booking_prepared',$tool,'prepared',(int)max(0,round((microtime(true)-$startedAt)*1000)));
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_confirmation_required',$tool,'confirmation_required',(int)max(0,round((microtime(true)-$startedAt)*1000)));
            vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
        if (in_array($tool,['vp3.booking.confirm','vp3.booking.reschedule.confirm','vp3.booking.cancel.confirm'],true)) {
            if(!vp3_profile_webmcp_actions_schema_ready_v150($pdo)){
                vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,['error'=>['code'=>'SCHEDULING_UNAVAILABLE','message'=>'Booking confirmation ledger is unavailable.']],503,'SCHEDULING_UNAVAILABLE');
            }
            $operation=match($tool){
                'vp3.booking.confirm'=>'booking.create',
                'vp3.booking.reschedule.confirm'=>'booking.reschedule',
                default=>'booking.cancel',
            };
            $intent=is_array($args['intent']??null)?$args['intent']:[];
            $result=vp3_profile_webmcp_scheduling_confirm_v150(
                $pdo,$profile,$schedulingContext,$operation,$intent,
                trim((string)($args['confirmation_token']??'')),trim((string)($args['idempotency_key']??''))
            );
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_booking_completed',$tool,'completed',(int)max(0,round((microtime(true)-$startedAt)*1000)),['booking_id'=>(int)($result['booking']['booking_id']??0)]);
            vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
    }
    if (str_starts_with($tool, 'vp3.agent.')) {
        $agentCtx=vp3_profile_agent_public_context_v110($pdo,$profile,$viewer);
        if ($tool === 'vp3.agent.get') {
            $state=vp3_profile_agent_public_state_service_v110($pdo,$agentCtx,0);
            vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,['agent'=>$state['agent']]);
        }
        if ($tool === 'vp3.agent.chat.start') {
            $result=vp3_profile_webmcp_chat_start_v140($pdo,$agentCtx);
            vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
        if ($tool === 'vp3.agent.conversation.get') {
            $cid=max(0,(int)($args['conversation_id']??0));
            vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,vp3_profile_agent_public_state_service_v110($pdo,$agentCtx,$cid));
        }
        if ($tool === 'vp3.agent.message.send') {
            $cid=max(0,(int)($args['conversation_id']??0));
            $message=trim((string)($args['message']??''));
            $result=vp3_profile_agent_public_message_service_v110($pdo,$agentCtx,$message,$cid);
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_message_sent',$tool,'completed',(int)max(0,round((microtime(true)-$startedAt)*1000)),['conversation_id'=>(int)($result['conversation_id']??0)]);
            vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
        if ($tool === 'vp3.agent.owner_handoff.request') {
            $cid=max(0,(int)($args['conversation_id']??0));
            $reason=trim((string)($args['reason']??''));
            $result=vp3_profile_agent_public_request_owner_v110($pdo,$agentCtx,$cid,$reason);
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_handoff_requested',$tool,'completed',(int)max(0,round((microtime(true)-$startedAt)*1000)),['conversation_id'=>$cid]);
            vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
    }
    vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,['error'=>['code'=>'CAPABILITY_UNAVAILABLE','message'=>'That profile capability is unavailable.']],404,'CAPABILITY_UNAVAILABLE');
} catch (VP3ProfileAgentPublicException $e) {
    vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,['error'=>['code'=>$e->publicCode,'message'=>$e->getMessage()]],$e->httpStatus,$e->publicCode);
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'The profile capability could not be completed.';
    vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,['error'=>['code'=>'VALIDATION_FAILED','message'=>$message]],422,'VALIDATION_FAILED');
}
