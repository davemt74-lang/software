<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-v100.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-analytics-v130.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-external-v120.php';
require_once dirname(__DIR__).'/includes/profile-agent-public-service-v110.php';
require_once dirname(__DIR__).'/includes/profile-agent-transcription-context.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-chat-v140.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-scheduling-v150.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-commerce-v160.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-campaigns-v170.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-tool-router-v191.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function vp3_profile_webmcp_external_json_v120(bool $ok,array $payload=[],int $status=200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok],$payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_profile_webmcp_external_tool_json_v130(PDO $pdo,?array $telemetryContext,string $tool,float $startedAt,bool $ok,array $payload=[],int $status=200,string $resultCode=''): never
{
    $duration=(int)max(0,round((microtime(true)-$startedAt)*1000));
    vp3_profile_webmcp_record_v130(
        $pdo,$telemetryContext,$ok?'webmcp_tool_completed':'webmcp_tool_failed',
        $tool,$ok?'completed':'failed',$duration,['result_code'=>$resultCode]
    );
    vp3_profile_webmcp_external_json_v120($ok,$payload,$status);
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
$manifestSession=vp3_profile_webmcp_transport_id_v130((string)($_GET['session']??''));
if($method==='OPTIONS'){
    http_response_code(204);
    exit;
}
if(!in_array($method,['GET','POST'],true)){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'GET or POST is required.']],405);
}

try{
    $profile=vp3_profile_webmcp_external_profile_v120($pdo,$property);
    $chatAvailable=$manifestSession!==''&&vp3_profile_webmcp_external_agent_v120($pdo,$profile)!==null;
    $manifest=vp3_profile_webmcp_external_manifest_v120($pdo,$property,$profile,$chatAvailable,true,true,true);
}catch(Throwable $e){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'PROFILE_UNAVAILABLE','message'=>'The connected VP3 profile is unavailable.']],404);
}

if($method==='GET'){
    $chatGrantData=null;
    if($chatAvailable){
        try{$chatGrantData=vp3_profile_webmcp_chat_grant_create_v140($property,$profile,$origin,$manifestSession);}
        catch(Throwable $e){$chatGrantData=null;}
    }
    $manifestTelemetryContext=vp3_profile_webmcp_context_v130(
        $pdo,$profile,'external_site',['webmcp_session_id'=>$manifestSession,'interaction_id'=>'','agent_referral'=>''],$property,null
    );
    vp3_profile_webmcp_record_v130($pdo,$manifestTelemetryContext,'webmcp_manifest_loaded','','loaded');
    vp3_profile_webmcp_external_json_v120(true,[
        'manifest'=>$manifest,
        'chat_grant'=>(string)($chatGrantData['grant']??''),
        'chat_grant_expires_at'=>(string)($chatGrantData['expires_at_utc']??''),
        'runtime'=>[
            'build'=>VP3_PROFILE_WEBMCP_EXTERNAL_V120,
            'read_only'=>!empty($manifest['external']['read_only']),
            'chat_enabled'=>!empty($manifest['external']['stateful_profile_agent']),
            'scheduling_enabled'=>!empty($manifest['external']['scheduling_enabled']),
            'commerce_enabled'=>!empty($manifest['external']['commerce_enabled']),
            'campaigns_enabled'=>!empty($manifest['external']['campaigns_enabled']),
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
$telemetry=vp3_profile_webmcp_telemetry_v130($input);
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
$chatTools=['vp3.agent.chat.start','vp3.agent.conversation.get','vp3.agent.message.send','vp3.agent.owner_handoff.request'];
$telemetryContext=vp3_profile_webmcp_context_v130($pdo,$profile,'external_site',$telemetry,$property,null);
if(!in_array($tool,$manifest['allowed_tools'],true)){
    vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_tool_denied',$tool,'denied',0,['result_code'=>'CAPABILITY_UNAVAILABLE']);
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'CAPABILITY_UNAVAILABLE','message'=>'That connected-site capability is unavailable.']],404);
}
$args=is_array($input['input']??null)?$input['input']:[];
$agentCtx=null;
if(in_array($tool,$chatTools,true)){
    $grant=trim((string)($input['chat_grant']??''));
    try{
        vp3_profile_webmcp_chat_grant_verify_v140(
            $property,$profile,$origin,(string)($telemetry['webmcp_session_id']??''),$grant
        );
        $agentCtx=vp3_profile_webmcp_external_chat_context_v140(
            $pdo,$property,$profile,(string)($telemetry['webmcp_session_id']??'')
        );
    }catch(VP3ProfileAgentPublicException $e){
        vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_tool_denied',$tool,'denied',0,['result_code'=>$e->publicCode]);
        vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>$e->publicCode,'message'=>$e->getMessage()]],$e->httpStatus);
    }
}
$startedAt=microtime(true);
vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_tool_called',$tool,'called');

try{
    vp3_profile_webmcp_dispatch_v191(
        $pdo,$profile,null,$manifest,'external_site',$tool,$args,$telemetry,$telemetryContext,$startedAt,
        static function(bool $ok,array $payload=[],int $status=200,string $resultCode='') use ($pdo,$telemetryContext,$tool,$startedAt): never {
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,$ok,$payload,$status,$resultCode);
        },
        ['property'=>$property,'origin'=>$origin,'agent_context'=>$agentCtx]
    );
}catch(VP3ProfileAgentPublicException $e){
    vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,['error'=>['code'=>$e->publicCode,'message'=>$e->getMessage()]],$e->httpStatus,$e->publicCode);
}catch(Throwable $e){
    $actionError=vp3_profile_webmcp_action_error_v192($e,'The connected-site capability could not be completed.');
    vp3_profile_webmcp_external_tool_json_v130(
        $pdo,$telemetryContext,$tool,$startedAt,false,
        $actionError['payload'],(int)$actionError['status'],(string)$actionError['result_code']
    );
}
