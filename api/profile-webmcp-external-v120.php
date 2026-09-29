<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-v100.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-external-v120.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function vp3_profile_webmcp_external_json_v120(bool $ok,array $payload=[],int $status=200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok],$payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo=db();
if(!$pdo||!vp3_radar_schema_ready($pdo)){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'WEBMCP_UNAVAILABLE','message'=>'Connected-site WebMCP is unavailable.']],503);
}

$key=strtolower(trim((string)($_GET['key']??'')));
$property=vp3_radar_external_property_by_key($pdo,$key);
if(!$property){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'PROPERTY_NOT_FOUND','message'=>'Connected site is unavailable.']],404);
}

try{
    $origin=vp3_profile_webmcp_external_origin_v120($property,(string)($_SERVER['HTTP_ORIGIN']??''));
}catch(Throwable $e){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'ORIGIN_DENIED','message'=>'Connected-site Origin is not authorized.']],403);
}

header('Access-Control-Allow-Origin: '.$origin);
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 600');

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS'){
    http_response_code(204);
    exit;
}
if(!in_array($method,['GET','POST'],true)){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'GET or POST is required.']],405);
}

try{
    $profile=vp3_profile_webmcp_external_profile_v120($pdo,$property);
    $manifest=vp3_profile_webmcp_external_manifest_v120($pdo,$property,$profile);
}catch(Throwable $e){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'PROFILE_UNAVAILABLE','message'=>'The connected VP3 profile is unavailable.']],404);
}

if($method==='GET'){
    vp3_profile_webmcp_external_json_v120(true,[
        'manifest'=>$manifest,
        'runtime'=>[
            'build'=>VP3_PROFILE_WEBMCP_EXTERNAL_V120,
            'read_only'=>true,
        ],
    ]);
}

$raw=(string)file_get_contents('php://input');
if(strlen($raw)>VP3_PROFILE_WEBMCP_MAX_BODY_V100){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'PAYLOAD_TOO_LARGE','message'=>'Request is too large.']],413);
}
$input=json_decode($raw,true);
if(!is_array($input)){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'INVALID_JSON','message'=>'Invalid JSON request.']],400);
}
if((string)($input['manifest_version']??'')!==VP3_PROFILE_WEBMCP_MANIFEST_V100){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'MANIFEST_VERSION_UNSUPPORTED','message'=>'Unsupported profile WebMCP manifest.']],400);
}
if((string)($input['surface']??'')!=='external_site'){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'SURFACE_MISMATCH','message'=>'This endpoint accepts only connected-site WebMCP requests.']],403);
}
if((int)($input['property_id']??0)!==(int)$property['id']){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'PROPERTY_MISMATCH','message'=>'Connected-site property does not match.']],403);
}
if(!hash_equals((string)$profile['username'],(string)($input['profile_username']??''))){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'PROFILE_MISMATCH','message'=>'Connected profile does not match.']],403);
}

$tool=trim((string)($input['tool']??''));
if(!in_array($tool,$manifest['allowed_tools'],true)){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'CAPABILITY_UNAVAILABLE','message'=>'That connected-site capability is unavailable.']],404);
}
$args=is_array($input['input']??null)?$input['input']:[];

try{
    if($tool==='vp3.profile.capabilities.get'){
        vp3_profile_webmcp_external_json_v120(true,[
            'manifest_version'=>VP3_PROFILE_WEBMCP_MANIFEST_V100,
            'capabilities'=>$manifest['capabilities'],
            'allowed_tools'=>$manifest['allowed_tools'],
        ]);
    }
    if($tool==='vp3.profile.get'){
        vp3_profile_webmcp_external_json_v120(true,['profile'=>vp3_profile_webmcp_public_profile_v100($profile)]);
    }
    if($tool==='vp3.intent.resolve'){
        $goal=trim((string)($args['goal']??''));
        vp3_profile_webmcp_external_json_v120(true,['resolution'=>vp3_profile_webmcp_resolve_intent_v100($goal,$manifest)]);
    }
    if($tool==='vp3.agent.get'){
        $agent=vp3_profile_webmcp_external_agent_v120($pdo,$profile);
        if(!$agent)vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'PROFILE_AGENT_UNAVAILABLE','message'=>'This Profile Agent is unavailable.']],404);
        vp3_profile_webmcp_external_json_v120(true,['agent'=>$agent]);
    }
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'CAPABILITY_UNAVAILABLE','message'=>'That connected-site capability is unavailable.']],404);
}catch(Throwable $e){
    $message=$e instanceof RuntimeException?$e->getMessage():'The connected-site capability could not be completed.';
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'VALIDATION_FAILED','message'=>$message]],422);
}
