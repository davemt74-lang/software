<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Agent-Token');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('X-Content-Type-Options: nosniff');

function vp3_agent_message_json(int $status,array $payload): never
{
    http_response_code($status);
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_agent_message_json(204,[]);
if($method!=='POST')vp3_agent_message_json(405,['ok'=>false,'error'=>'Method not allowed.']);
$pdo=db();if(!$pdo||!vp3_radar_schema_ready($pdo))vp3_agent_message_json(503,['ok'=>false,'error'=>'VP3 Agent Messaging is not ready.']);
$username=profile_username_normalize((string)($_GET['username']??''));$context=vp3_agent_access_owner_context($pdo,$username);
if(!$context)vp3_agent_message_json(404,['ok'=>false,'error'=>'Agent messaging is not available for this profile.']);
$owner=(int)$context['profile']['user_id'];$token=vp3_agent_access_bearer_token();
if($token==='')vp3_agent_message_json(401,['ok'=>false,'error'=>'An approved agent messaging grant is required.']);
$userAgent=vp3_radar_request_user_agent();
$grant=vp3_agent_access_grant_claim($pdo,$owner,$token,$userAgent);
if(!$grant||((string)$grant['capability']!=='agent.message'))vp3_agent_message_json(403,['ok'=>false,'error'=>'This agent messaging grant is invalid, expired, consumed, or does not match the requesting agent.']);

$raw=(string)file_get_contents('php://input');if(strlen($raw)>8192){if((string)$grant['status']==='approved_once')vp3_agent_access_restore_once($pdo,$owner,(int)$grant['id']);vp3_agent_message_json(413,['ok'=>false,'error'=>'Payload too large.']);}
$input=json_decode($raw,true);if(!is_array($input)){if((string)$grant['status']==='approved_once')vp3_agent_access_restore_once($pdo,$owner,(int)$grant['id']);vp3_agent_message_json(400,['ok'=>false,'error'=>'Invalid JSON payload.']);}
$message=trim((string)($input['message']??''));
try{
    $result=vp3_agent_message_generate($pdo,$context,$grant,$message);
    $referral=null;
    if(function_exists('vp3_agent_referral_create_for_message')){
        try{$referral=vp3_agent_referral_create_for_message($pdo,$context,$grant);}catch(Throwable $referralError){error_log('VP3 Agent referral link creation failed: '.$referralError->getMessage());}
    }
    $payload=['ok'=>true,'answer'=>$result['answer'],'sources'=>$result['sources'],'agent'=>$result['agent'],'grant'=>['status'=>(string)$grant['status']==='approved_once'?'consumed':'approved']];
    if($referral)$payload['referral']=['url'=>(string)$referral['url'],'expires_at'=>(string)$referral['expires_at'],'attribution'=>'first_party_token','note'=>'Share this URL with a human when the Profile Agent response leads them to the VP3 profile. VP3 stores only a hash of the referral token.'];
    vp3_agent_message_json(200,$payload);
}catch(Throwable $e){
    if((string)$grant['status']==='approved_once')vp3_agent_access_restore_once($pdo,$owner,(int)$grant['id']);
    $messageText=(string)$e->getMessage();$lower=mb_strtolower($messageText);
    if(str_contains($lower,'rate limit')||str_contains($lower,'please wait')){
        header('Retry-After: 10');
        vp3_agent_message_json(429,['ok'=>false,'error'=>'Agent messaging rate limit reached.']);
    }
    if($messageText==='VP3_AGENT_MESSAGE_QUOTA')vp3_agent_message_json(402,['ok'=>false,'error'=>'The profile owner’s VP3 AI token balance cannot currently fund Agent Messaging.']);
    if($messageText==='VP3_AGENT_MESSAGE_PROVIDER')vp3_agent_message_json(503,['ok'=>false,'error'=>'The Profile Agent AI service is temporarily unavailable.']);
    if(str_contains($lower,'gateway denies'))vp3_agent_message_json(403,['ok'=>false,'error'=>'Agent Gateway denies messaging for this contact.']);
    if(str_contains($lower,'enter an agent message'))vp3_agent_message_json(422,['ok'=>false,'error'=>'Enter an agent message up to 2,000 characters.']);
    error_log('VP3 Agent Messaging failed: '.get_class($e).' '.mb_strimwidth($messageText,0,500,'…'));
    vp3_agent_message_json(500,['ok'=>false,'error'=>'The Profile Agent could not complete this message.']);
}
