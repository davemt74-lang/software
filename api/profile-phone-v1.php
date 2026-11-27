<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-phone-v1.php';
require_once dirname(__DIR__).'/includes/agent-observation-authority-v1.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try{
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    if(!in_array($method,['GET','POST'],true))throw new RuntimeException('Use GET or POST.',405);
    if($method==='GET'&&($_GET['action']??'')==='public'){
        if(count($_GET)!==2||!is_string($_GET['username']??null)||!profile_username_valid($_GET['username']))throw new RuntimeException('Profile calling is unavailable.',404);
        $pdo=db();if(!$pdo instanceof PDO)throw new RuntimeException('Profile calling is unavailable.',503);
        $profile=profile_by_username($pdo,$_GET['username']);
        if(!$profile)throw new RuntimeException('Profile calling is unavailable.',404);
        $result=vp3_profile_phone_public_v1($pdo,$profile);
    }else{
        $user=current_user();
        if(!$user||!has_permission('account.access',$user)||!personal_capability_has_v242('profile_agent.access',$user))throw new RuntimeException('Sign in to manage your Profile calling.',403);
        if((string)($_SERVER['HTTP_X_VP3_PROFILE_OWNER']??'')!==(string)$user['id'])throw new RuntimeException('Your account changed. Reload Profile settings.',403);
        $pdo=db();if(!$pdo instanceof PDO)throw new RuntimeException('Profile calling is unavailable.',503);
        if($method==='GET'){
            if($_GET)throw new InvalidArgumentException('Choose a supported calling action.',422);
            $result=vp3_profile_phone_owner_v1($pdo,$user);
        }else{
            if((int)($_SERVER['CONTENT_LENGTH']??0)>2048)throw new RuntimeException('Calling settings request is too large.',413);
            $raw=(string)file_get_contents('php://input',false,null,0,2049);
            if(strlen($raw)>2048)throw new RuntimeException('Calling settings request is too large.',413);
            $input=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('Choose valid calling settings.',422);
            if(!is_string($input['csrf_token']??null)||!hash_equals(csrf_token(),$input['csrf_token']))throw new RuntimeException('Your session expired. Reload Profile settings.',419);
            unset($input['csrf_token']);$result=vp3_profile_phone_save_v1($pdo,$user,$input);
        }
    }
    echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}catch(Throwable $error){
    $status=(int)$error->getCode();if($error instanceof JsonException)$status=422;
    if(!in_array($status,[403,404,405,409,413,419,422,503],true))$status=503;
    if($status===405)header('Allow: GET, POST');http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$status===503?'Profile calling is unavailable. Check Database Upgrade and Phone settings.':($error instanceof JsonException?'Choose valid calling settings.':$error->getMessage())],JSON_UNESCAPED_SLASHES);
}
