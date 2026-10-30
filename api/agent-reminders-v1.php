<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-reminders-v1.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('POST required.',405);
    $raw=file_get_contents('php://input',false,null,0,12001);if(strlen($raw)>12000)throw new RuntimeException('Reminder request is too large.',413);
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('Use a reminder request object.');
    $home=trim((string)($_SERVER['HTTP_X_HOMESERVER_DEVICE']??''))!=='';$device='';
    if($home){
        require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
        $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
        if(!preg_match('/^Bearer\s+(.+)$/i',$auth,$m))throw new RuntimeException('HomeServer authentication required.',401);
        $device=trim((string)$_SERVER['HTTP_X_HOMESERVER_DEVICE']);
        $paired=homeserver_https_v1300_authenticate(trim($m[1]),$device);
        $user=vp3_extension_user_for_permission_v2001(db(),(int)$paired['user_id']);
    }else{
        $user=current_user();if(!$user)throw new RuntimeException('Sign in to use reminders.',401);
        if(!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))throw new RuntimeException('Refresh this page before using reminders.',419);
        if((string)($input['owner_key']??'')!==(string)$user['id'])throw new RuntimeException('Reminder account changed. Refresh this page.',409);
    }
    if(!$user||!has_permission('chat.access',$user))throw new RuntimeException('Agent Chat access required.',403);
    $pdo=db();if(!$pdo||!reminder_ready_v1($pdo))throw new RuntimeException('Run the VP3 database upgrade to enable reminders.',503);
    if(($input['action']??'')==='chat'&&$home){
        $query=reminder_text_v1($input['message']??null,6000);$thread=reminder_text_v1($input['thread']??null,80);$key=reminder_text_v1($input['request_id']??null,100);
        if(!preg_match('/^[A-Za-z0-9:_-]{1,80}$/D',$thread)||!preg_match('/^[A-Za-z0-9:_-]{8,100}$/D',$key))throw new InvalidArgumentException('Invalid reminder conversation.');
        $result=reminder_chat_v1($pdo,$user,$query,'home:'.substr(hash('sha256',$device),0,32).':'.$thread,$key,0,reminder_zone_v1($input['timezone']??'UTC'),null,static function()use($m,$device):void{homeserver_https_v1300_authenticate(trim($m[1]),$device);});
        echo json_encode(['ok'=>true,...$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;
    }
    $presentation=reminder_present_v1($pdo,(int)$user['id'],$input);
    if(!empty($presentation['item'])&&!empty($input['thread'])){
        $thread=reminder_text_v1($input['thread'],80);
        if($home&&preg_match('/^[A-Za-z0-9:_-]{1,80}$/D',$thread))$focusThread='home:'.substr(hash('sha256',$device),0,32).':'.$thread;
        elseif(!$home&&ctype_digit($thread)){
            $q=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=?');$q->execute([(int)$thread,(int)$user['id']]);
            $focusThread=$q->fetchColumn()?'cloud:'.(int)$thread:'';
        }else $focusThread='';
        if($focusThread!=='')reminder_save_state_v1($pdo,(int)$user['id'],$focusThread,['focus'=>$presentation['item']['reminder_id'],'pending'=>[]],reminder_utc_v1(reminder_now_v1()));
    }
    echo json_encode($presentation,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){$code=(int)$e->getCode();http_response_code(in_array($code,[401,403,405,409,413,419,429,502,503],true)?$code:422);echo json_encode(['ok'=>false,'error'=>$e instanceof JsonException?'Invalid reminder request.':$e->getMessage()]);}
