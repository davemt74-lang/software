<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-v100.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-analytics-v130.php';
require_once dirname(__DIR__) . '/includes/profile-agent-transcription-context.php';
require_once dirname(__DIR__) . '/includes/profile-agent-public-service-v110.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-chat-v140.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-scheduling-v150.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-commerce-v160.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-campaigns-v170.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-rewards-v180.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-tool-router-v191.php';

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
$clientVersions=vp3_profile_webmcp_client_versions_v200($input);
$negotiation=vp3_profile_webmcp_negotiate_v200('native_profile',$clientVersions);
$telemetry['negotiation_mode']=(string)($negotiation['mode']??'');
if(empty($negotiation['compatible'])){
    vp3_profile_webmcp_json_v100(false,vp3_profile_webmcp_negotiation_error_v200($negotiation),409);
}
if ((string)($input['surface'] ?? '') !== 'native_profile') {
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'SURFACE_MISMATCH','message'=>'This endpoint accepts only native-profile WebMCP requests.']], 403);
}

$viewer = current_user();
$continuityContext=null;$nativeTransport=null;
if(array_key_exists('continuity',$input)){
    try{
        if(!is_array($input['continuity'])||$input['continuity']===[]||!is_array($viewer))throw new RuntimeException('Invalid continuity proof.',403);
        require_once dirname(__DIR__).'/includes/agent-profile-webmcp-read-v1.php';
        require_once dirname(__DIR__).'/includes/agent-profile-webmcp-outcomes-v1.php';
        $continuityContext=vp3_profile_webmcp_context_for_native_v1($pdo,$profile,$viewer,$input['continuity']);
        if(!$continuityContext)throw new RuntimeException('Invalid continuity proof.',403);
        $session=vp3_profile_webmcp_transport_id_v130((string)($telemetry['webmcp_session_id']??''));
        if($session==='')throw new RuntimeException('Missing native action session.',403);
        $nativeTransport=['owner_user_id'=>(int)$profile['user_id'],'profile_username'=>(string)$profile['username'],'surface'=>'native_profile','property_id'=>0,'session_hash'=>hash('sha256',$session)];
    }catch(Throwable){
        vp3_profile_webmcp_json_v100(false,['error'=>['code'=>'CONTINUITY_DENIED','message'=>'The Chat handoff is no longer authorized. Return to its originating Chat to continue.','retryable'=>false]],403);
    }
}
try {
    $manifest = vp3_profile_webmcp_manifest_v100($pdo, $profile, $viewer, ['surface'=>'native_profile']);
    $manifest['negotiation']=$negotiation;
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
if($continuityContext){
    try{
        $continuityContext=vp3_profile_webmcp_context_live_v1($pdo,$profile,$viewer,$continuityContext);
        vp3_profile_webmcp_request_binding_v1($pdo,$profile,$continuityContext,$nativeTransport,$tool,$args);
    }catch(Throwable){
        vp3_profile_webmcp_json_v100(false,['error'=>['code'=>'CONTINUITY_DENIED','message'=>'This action does not match the authorized Chat handoff.','retryable'=>false]],403);
    }
}
$startedAt=microtime(true);
vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_tool_called',$tool,'called');

try {
    vp3_profile_webmcp_dispatch_v191(
        $pdo,$profile,$viewer,$manifest,'native_profile',$tool,$args,$telemetry,$telemetryContext,$startedAt,
        static function(bool $ok,array $payload=[],int $status=200,string $resultCode='') use ($pdo,$telemetryContext,$tool,$startedAt,$profile,$viewer,$continuityContext,$nativeTransport,$args): never {
            if($continuityContext){
                try{
                    $liveContext=vp3_profile_webmcp_context_live_v1($pdo,$profile,$viewer,$continuityContext);
                    $patch=$ok?vp3_profile_webmcp_server_evidence_v1($pdo,$profile,$liveContext,$nativeTransport,$tool,$args,$payload):[];
                    if($patch){
                        vp3_profile_webmcp_context_store_server_v1($pdo,$profile,$viewer,$liveContext,$patch);
                        $payload['profile_webmcp_outcome']=$patch['server_outcome'];
                    }else vp3_profile_webmcp_context_live_v1($pdo,$profile,$viewer,$liveContext);
                }catch(Throwable){
                    vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,
                        ['error'=>['code'=>'ACTION_OUTCOME_UNVERIFIED','message'=>'The action response could not be verified for this Chat return. Do not repeat it with a new idempotency key.','retryable'=>false]],503,'ACTION_OUTCOME_UNVERIFIED');
                }
            }
            vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,$ok,$payload,$status,$resultCode);
        },
        ['proof'=>$proof]
    );
} catch (VP3ProfileAgentPublicException $e) {
    vp3_profile_webmcp_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,['error'=>['code'=>$e->publicCode,'message'=>$e->getMessage()]],$e->httpStatus,$e->publicCode);
} catch (Throwable $e) {
    $actionError=vp3_profile_webmcp_action_error_v192($e,'The profile capability could not be completed.');
    vp3_profile_webmcp_tool_json_v130(
        $pdo,$telemetryContext,$tool,$startedAt,false,
        $actionError['payload'],(int)$actionError['status'],(string)$actionError['result_code']
    );
}
