<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/profile-webmcp-v100.php';
require_once dirname(__DIR__) . '/includes/profile-agent-transcription-context.php';
require_once dirname(__DIR__) . '/includes/profile-agent-public-service-v110.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function vp3_profile_webmcp_json_v100(bool $ok, array $payload = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok], $payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
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
if (!isset($catalog[$tool]) || !in_array($tool, $manifest['allowed_tools'], true)) {
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'CAPABILITY_UNAVAILABLE','message'=>'That profile capability is unavailable.']], 404);
}
$args = is_array($input['input'] ?? null) ? $input['input'] : [];

try {
    if ($tool === 'vp3.profile.capabilities.get') {
        vp3_profile_webmcp_json_v100(true, [
            'manifest_version'=>VP3_PROFILE_WEBMCP_MANIFEST_V100,
            'capabilities'=>$manifest['capabilities'],
            'allowed_tools'=>$manifest['allowed_tools'],
        ]);
    }
    if ($tool === 'vp3.profile.get') {
        vp3_profile_webmcp_json_v100(true, ['profile'=>vp3_profile_webmcp_public_profile_v100($profile)]);
    }
    if ($tool === 'vp3.intent.resolve') {
        $goal = trim((string)($args['goal'] ?? ''));
        vp3_profile_webmcp_json_v100(true, ['resolution'=>vp3_profile_webmcp_resolve_intent_v100($goal, $manifest)]);
    }
    if (str_starts_with($tool, 'vp3.agent.')) {
        $agentCtx=vp3_profile_agent_public_context_v110($pdo,$profile,$viewer);
        if ($tool === 'vp3.agent.get') {
            $state=vp3_profile_agent_public_state_service_v110($pdo,$agentCtx,0);
            vp3_profile_webmcp_json_v100(true,['agent'=>$state['agent']]);
        }
        if ($tool === 'vp3.agent.conversation.get') {
            $cid=max(0,(int)($args['conversation_id']??0));
            vp3_profile_webmcp_json_v100(true,vp3_profile_agent_public_state_service_v110($pdo,$agentCtx,$cid));
        }
        if ($tool === 'vp3.agent.message.send') {
            $cid=max(0,(int)($args['conversation_id']??0));
            $message=trim((string)($args['message']??''));
            vp3_profile_webmcp_json_v100(true,vp3_profile_agent_public_message_service_v110($pdo,$agentCtx,$message,$cid));
        }
        if ($tool === 'vp3.agent.owner_handoff.request') {
            $cid=max(0,(int)($args['conversation_id']??0));
            $reason=trim((string)($args['reason']??''));
            vp3_profile_webmcp_json_v100(true,vp3_profile_agent_public_request_owner_v110($pdo,$agentCtx,$cid,$reason));
        }
    }
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'CAPABILITY_UNAVAILABLE','message'=>'That profile capability is unavailable.']], 404);
} catch (VP3ProfileAgentPublicException $e) {
    vp3_profile_webmcp_json_v100(false,['error'=>['code'=>$e->publicCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'The profile capability could not be completed.';
    vp3_profile_webmcp_json_v100(false, ['error'=>['code'=>'VALIDATION_FAILED','message'=>$message]], 422);
}
