<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-workspace-v1.php';
require_once dirname(__DIR__).'/includes/phone-voice-v130.php';
require_once dirname(__DIR__).'/includes/phone-voicemail-v1.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
try{
    $pdo=db();if(!$pdo)throw new RuntimeException('Voicemail is temporarily unavailable.',503);
    $method=(string)($_SERVER['REQUEST_METHOD']??'');
    if($method==='GET'){
        $bytes=vp3_phone_voicemail_greeting_audio_v1($pdo,current_user(),$_GET);if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        header('Content-Type: audio/wav');header('Content-Length: '.strlen($bytes));header('Content-Disposition: inline; filename="voicemail-greeting.wav"');echo $bytes;exit;
    }
    if($method!=='POST')throw new RuntimeException('Use a supported voicemail action.',405);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>VP3_PHONE_VOICEMAIL_GREETING_MAX_V1+16384)throw new RuntimeException('Greeting is too large.',413);
    if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'multipart/form-data'))throw new RuntimeException('Upload a recorded greeting.',415);
    $user=current_user();if(!$user)throw new RuntimeException('Sign in to save a greeting.',401);
    $csrf=$_POST['csrf_token']??null;if(!is_string($csrf)||!is_string($_SESSION['csrf_token']??null)||$_SESSION['csrf_token']===''||!hash_equals($_SESSION['csrf_token'],$csrf))throw new RuntimeException('Refresh the page before saving your greeting.',419);
    $scope=vp3_phone_workspace_scope_v1($pdo,$user,$_POST);$number=vp3_phone_workspace_id_v1($_POST['number_id']??null);$revision=vp3_phone_workspace_id_v1($_POST['expected_revision']??null,true);
    $file=$_FILES['greeting']??null;if(!is_array($file)||($file['error']??-1)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||(int)($file['size']??0)>VP3_PHONE_VOICEMAIL_GREETING_MAX_V1)throw new RuntimeException('Record a greeting between one and sixty seconds.',422);
    $bytes=file_get_contents($file['tmp_name'],false,null,0,VP3_PHONE_VOICEMAIL_GREETING_MAX_V1+1);if(!is_string($bytes))throw new RuntimeException('Greeting upload is unavailable.',422);
    $result=vp3_phone_voicemail_greeting_save_v1($pdo,$scope,$number,$revision,$bytes);header('Content-Type: application/json; charset=UTF-8');echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}catch(Throwable $error){
    $status=$error instanceof VP3PhoneWorkspaceException?$error->httpStatus:(int)$error->getCode();if(!in_array($status,[401,403,404,405,409,413,415,419,422,503],true))$status=503;
    http_response_code($status);header('Content-Type: application/json; charset=UTF-8');echo json_encode(['ok'=>false,'error'=>$error instanceof VP3PhoneWorkspaceException?$error->publicCode:'phone_voicemail_greeting_unavailable','message'=>$status===503?'Voicemail is temporarily unavailable.':($error instanceof RuntimeException?$error->getMessage():'Greeting is unavailable.')]);
}
