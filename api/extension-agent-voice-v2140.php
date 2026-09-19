<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/extension-notifications-v2140.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_voice_json_v2140(int $status,string $code,string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'build'=>VP3_EXTENSION_NOTIFICATIONS_V2140,'error'=>['code'=>$code,'message'=>$message]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_voice_settings_v2140(): array
{
    $apiKey=trim((string)(getenv('ELEVENLABS_API_KEY')?:''));
    if($apiKey===''){
        $encrypted=trim((string)setting('ai_elevenlabs_api_key',''));
        $apiKey=ai_decrypt_secret($encrypted);
    }
    $voiceId=trim((string)(getenv('ELEVENLABS_VOICE_ID')?:setting('ai_elevenlabs_voice_id','JBFqnCBsd6RMkjVDRZzb')));
    $model=trim((string)(getenv('ELEVENLABS_MODEL_ID')?:setting('ai_elevenlabs_model_id','eleven_flash_v2_5')));
    $allowedModels=['eleven_flash_v2_5','eleven_flash_v2','eleven_turbo_v2_5','eleven_multilingual_v2'];
    if(!in_array($model,$allowedModels,true))$model='eleven_flash_v2_5';
    $format=trim((string)(getenv('ELEVENLABS_OUTPUT_FORMAT')?:'mp3_22050_32'));
    if(!in_array($format,['mp3_22050_32','mp3_44100_128'],true))$format='mp3_22050_32';
    return [$apiKey,$voiceId,$model,$format];
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS'){http_response_code(204);exit;}
if($method!=='POST')vp3_extension_voice_json_v2140(405,'method_not_allowed','POST required.');
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_voice_json_v2140(422,'unsupported_contract','Unsupported extension contract version.');
}

try{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>4096)vp3_extension_voice_json_v2140(413,'payload_too_large','Payload too large.');
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_voice_json_v2140(400,'invalid_request','A JSON request body is required.');

    $eventKey=trim((string)($input['event_key']??''));
    if(!preg_match('/^(?:notification:[1-9][0-9]{0,18}|cognitive:[a-f0-9]{64})$/',$eventKey)){
        vp3_extension_voice_json_v2140(422,'invalid_event','Notification identity is invalid.');
    }

    $pdo=db();
    if(!$pdo||!vp3_extension_notifications_schema_ready_v2140($pdo)){
        vp3_extension_voice_json_v2140(503,'upgrade_required','Run the VP3 database upgrade to enable proactive Browser notifications.');
    }
    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_voice_json_v2140(401,'authentication_required','Browser Companion authentication is required.');
    if(!vp3_extension_session_has_capability_v2001($session,'notifications.read')){
        vp3_extension_voice_json_v2140(403,'capability_denied','Browser notifications are not enabled for this VP3 account.');
    }
    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_voice_json_v2140(403,'voice_denied','Agent Voice access is unavailable for this VP3 account.');
    }

    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,0);
    $candidate=vp3_extension_notification_voice_pending_v2140($pdo,$session,$user,$namespace,[]);
    if(!$candidate||!hash_equals((string)$candidate['event_key'],$eventKey)){
        vp3_extension_voice_json_v2140(409,'voice_not_pending','This Agent Voice notification is not pending for this browser.');
    }
    $text=trim((string)($candidate['text']??''));
    if($text===''||mb_strlen($text)>500)vp3_extension_voice_json_v2140(422,'invalid_voice_text','Agent Voice notification text is invalid.');

    [$apiKey,$voiceId,$model,$format]=vp3_extension_voice_settings_v2140();
    if($apiKey===''||!preg_match('/^[A-Za-z0-9_-]{8,128}$/',$voiceId)){
        vp3_extension_voice_json_v2140(503,'voice_unavailable','Premium Agent Voice is not configured.');
    }
    if(!function_exists('curl_init'))vp3_extension_voice_json_v2140(503,'voice_transport_unavailable','Agent Voice transport is unavailable.');

    $endpoint='https://api.elevenlabs.io/v1/text-to-speech/'.rawurlencode($voiceId).'?output_format='.rawurlencode($format);
    $payload=json_encode(['text'=>$text,'model_id'=>$model],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($payload))vp3_extension_voice_json_v2140(500,'voice_request_failed','Could not prepare Agent Voice.');

    $curl=curl_init($endpoint);
    $options=[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>6,
        CURLOPT_TIMEOUT=>35,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>[
            'xi-api-key: '.$apiKey,
            'Content-Type: application/json',
            'Accept: audio/mpeg',
        ],
        CURLOPT_POSTFIELDS=>$payload,
    ];
    if(defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))$options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
    curl_setopt_array($curl,$options);
    $audio=curl_exec($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $error=curl_error($curl);
    curl_close($curl);

    if(!is_string($audio)||$status<200||$status>=300||$audio===''||strlen($audio)>4*1024*1024){
        error_log('VP3 extension Agent Voice failed status='.$status.' error='.mb_strimwidth($error,0,160,'…'));
        vp3_extension_voice_json_v2140(503,'voice_unavailable','Premium Agent Voice is temporarily unavailable.');
    }

    http_response_code(200);
    header('Content-Type: audio/mpeg');
    header('Content-Length: '.strlen($audio));
    header('X-VP3-Agent-Voice: premium');
    header('X-VP3-Voice-Event: '.rawurlencode($eventKey));
    echo $audio;
    exit;
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_voice_json_v2140($e->httpStatus,$e->apiCode,$e->getMessage());
}catch(Throwable $e){
    error_log('VP3 extension Agent Voice v21.40 failed: '.$e->getMessage());
    vp3_extension_voice_json_v2140(503,'service_unavailable','Premium Agent Voice is temporarily unavailable.');
}
