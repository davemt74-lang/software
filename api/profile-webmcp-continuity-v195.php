<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-v100.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-continuity-v195.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function vp3_profile_webmcp_continuity_json_v195(bool $ok,array $payload=[],int $status=200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok],$payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')vp3_profile_webmcp_continuity_json_v195(false,['error'=>['code'=>'METHOD_NOT_ALLOWED']],405);
$raw=file_get_contents('php://input')?:'';
if(strlen($raw)>8192)vp3_profile_webmcp_continuity_json_v195(false,['error'=>['code'=>'PAYLOAD_TOO_LARGE']],413);
$input=json_decode($raw,true);
if(!is_array($input))vp3_profile_webmcp_continuity_json_v195(false,['error'=>['code'=>'INVALID_JSON']],400);
$pdo=db();$viewer=current_user();
if(!$pdo||!is_array($viewer)||(int)($viewer['id']??0)<1)vp3_profile_webmcp_continuity_json_v195(false,['error'=>['code'=>'AUTH_REQUIRED']],401);
$username=profile_username_normalize((string)($input['profile_username']??''));
$profile=$username!==''?profile_by_username($pdo,$username):null;
if(!$profile||empty($profile['is_public'])||empty($profile['is_active']))vp3_profile_webmcp_continuity_json_v195(false,['error'=>['code'=>'PROFILE_NOT_FOUND']],404);
if((int)$profile['user_id']!==(int)$viewer['id'])vp3_profile_webmcp_continuity_json_v195(false,['error'=>['code'=>'OWNER_REQUIRED']],403);
$proof=trim((string)($_SERVER['HTTP_X_VP3_WEBMCP_SESSION']??''));
if(!vp3_profile_webmcp_session_proof_valid_v100((int)$profile['user_id'],$proof))vp3_profile_webmcp_continuity_json_v195(false,['error'=>['code'=>'SESSION_PROOF_REQUIRED']],403);
if(!vp3_profile_webmcp_native_origin_allowed_v100((string)($_SERVER['HTTP_ORIGIN']??''),(string)($_SERVER['HTTP_HOST']??'')))vp3_profile_webmcp_continuity_json_v195(false,['error'=>['code'=>'ORIGIN_DENIED']],403);
$context=vp3_profile_webmcp_action_context_note_v195(
    $profile,$viewer,
    (string)($input['context_id']??''),(string)($input['return_token']??''),
    (string)($input['tool']??''),(string)($input['phase']??'error'),
    (string)($input['result_code']??''),!empty($input['idempotent_replay'])
);
if(!$context)vp3_profile_webmcp_continuity_json_v195(false,['error'=>['code'=>'CONTEXT_INVALID']],404);
vp3_profile_webmcp_continuity_json_v195(true,['context'=>$context]);
