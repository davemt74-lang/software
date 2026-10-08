<?php
declare(strict_types=1);
/** Same-origin, owner-scoped relay for the HomeServer read-only mission runtime. */
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/homeserver-https-relay-v1300.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new RuntimeException('POST required.',405);
    require_permission('chat.access');
    $user=current_user();
    $userId=(int)($user['id']??0);
    if($userId<=0)throw new RuntimeException('Login required.',401);
    $raw=file_get_contents('php://input',false,null,0,16385);
    if(!is_string($raw)||strlen($raw)>16384)throw new RuntimeException('Mission request exceeds the limit.',413);
    $input=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
    if(!is_array($input)||array_is_list($input))throw new RuntimeException('JSON object required.',422);
    if(!hash_equals(csrf_token(),trim((string)($input['csrf_token']??''))))
        throw new RuntimeException('Your session expired. Refresh the page.',419);

    $action=trim((string)($input['action']??''));
    if(!in_array($action,['list','get','create','start','cancel','pause','resume','retry','events'],true))
        throw new RuntimeException('Unsupported mission operation.',422);
    $status=homeserver_https_v1300_status($userId);
    if(!$status||!($status['connected']??false))
        throw new RuntimeException('HomeServer is not connected. Pair it in Settings.',503);
    $payload=[];
    if($action==='create'){
        $objective=trim((string)($input['objective']??''));
        if($objective===''||strlen($objective)>4000)throw new RuntimeException('Mission objective must contain 1 to 4,000 characters.',422);
        $requestId=trim((string)($input['request_id']??''));
        if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$requestId))
            throw new RuntimeException('Mission request identifier is invalid.',422);
        $thread=$input['thread_id']??0;
        if(!is_int($thread)||$thread<0||$thread>2147483647)
            throw new RuntimeException('Chat thread identifier is invalid.',422);
        $payload=['objective'=>$objective,'request_id'=>$requestId,'thread_id'=>$thread];
    }elseif($action==='list'){
        $payload=['limit'=>8];
    }else{
        $id=trim((string)($input['mission_id']??''));
        if(!preg_match('/^[0-9a-f-]{36}$/i',$id))
            throw new RuntimeException('Mission identifier is invalid.',422);
        $payload=['mission_id'=>$id];
        if($action==='resume'){
            if(($input['allow_reexecution']??null)!==true)throw new RuntimeException('Explicit reexecution approval required.',409);
            $payload['allow_reexecution']=true;
        }
        if($action==='retry'){
            $taskId=trim((string)($input['task_id']??''));
            if(!preg_match('/^[0-9a-f-]{36}$/i',$taskId))throw new RuntimeException('Task identifier is invalid.',422);
            $payload['task_id']=$taskId;
        }
        if($action==='events'){
            $after=$input['after']??0;
            if(!is_int($after)||$after<0)throw new RuntimeException('Event cursor is invalid.',422);
            $payload['after']=$after;
            $payload['limit']=40;
        }
    }
    $request=homeserver_https_v1300_queue($userId,'agent.missions.'.$action,$payload);
    $remote=homeserver_https_v1300_wait($request,24000);
    if(!is_array($remote)||($remote['contract']??'')!=='vp3.agent-missions.cloud.v1')
        throw new RuntimeException('HomeServer mission protocol is unavailable. Update HomeServer.',503);
    echo json_encode($remote,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    $status=(int)$e->getCode();
    if(!in_array($status,[401,403,405,409,413,419,422,503],true))$status=503;
    http_response_code($status);
    if($status===503)error_log('HomeServer agent mission relay unavailable: '.get_class($e));
    $message=$status===503?'HomeServer mission service is temporarily unavailable.':$e->getMessage();
    echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_SLASHES);
}
