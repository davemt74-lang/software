<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=120');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

function vp3_agent_content_json(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')vp3_agent_content_json(405,['error'=>'Method not allowed.']);
$pdo=db();if(!$pdo||!profile_agent_schema_ready($pdo)||!vp3_radar_schema_ready($pdo))vp3_agent_content_json(503,['error'=>'VP3 public content is not ready.']);
$username=profile_username_normalize((string)($_GET['username']??''));
if($username==='')vp3_agent_content_json(400,['error'=>'Provide a public VP3 username.']);
$profile=profile_by_username($pdo,$username);
if(!$profile||empty($profile['is_active'])||empty($profile['is_public']))vp3_agent_content_json(404,['error'=>'Public profile not found.']);
if(function_exists('vp3_radar_record_native_profile_request'))vp3_radar_record_native_profile_request($pdo,$profile,current_user());
$collection=strtolower(trim((string)($_GET['collection']??'')));
try{
    $payload=vp3_agent_content_collection($pdo,$profile,$collection);
    $payload['schema']='vp3-agent-content';
    $payload['version']=VP3_AGENT_MANIFEST_VERSION;
    $payload['generated_at']=gmdate('c');
    vp3_agent_content_json(200,$payload);
}catch(Throwable $e){vp3_agent_content_json(404,['error'=>'Public content collection not found.']);}
