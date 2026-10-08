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
    if(!in_array($action,['list','get','create','start','cancel','pause','resume','retry','events','evaluate','decisions','approve','reject','execution','bind_provider','browser.grant','browser.get','browser.capture','browser.revoke','browser.live.start','browser.live.get','browser.live.refresh','browser.live.propose','browser.live.approve','browser.live.stop','browser.action.propose','browser.action.approve','browser.owner.takeover','browser.owner.release','browser.owner.control','browser.owner.search.review','browser.owner.search.submit'],true))
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
        if($action==='evaluate'){
            $requestId=trim((string)($input['request_id']??''));
            if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$requestId))
                throw new RuntimeException('Supervisor request identifier is invalid.',422);
            $payload['request_id']=$requestId;
        }
        if($action==='approve'||$action==='reject'){
            $decisionId=trim((string)($input['decision_id']??''));
            if(!preg_match('/^[0-9a-f-]{36}$/i',$decisionId))
                throw new RuntimeException('Supervisor decision identifier is invalid.',422);
            if(($input['confirmed']??null)!==true)
                throw new RuntimeException('Explicit staffing review confirmation required.',409);
            $payload['decision_id']=$decisionId;
        }
        if($action==='bind_provider'){
            $taskId=trim((string)($input['task_id']??''));
            if(!preg_match('/^[0-9a-f-]{36}$/i',$taskId))
                throw new RuntimeException('Task identifier is invalid.',422);
            $provider=trim((string)($input['provider_key']??''));
            if(!in_array($provider,['auto','ollama','anthropic','openai','openrouter'],true))
                throw new RuntimeException('Unrecognized worker inference provider.',422);
            $payload['task_id']=$taskId;
            $payload['provider_key']=$provider;
        }
        if(str_starts_with($action,'browser.')){
            $worker=trim((string)($input['task_id']??''));
            if(!preg_match('/^[0-9a-f-]{36}$/i',$worker))
                throw new RuntimeException('Browser worker ID is invalid.',422);
            $payload['task_id']=$worker;
            if($action==='browser.grant'||$action==='browser.capture'){
                $url=trim((string)($input['url']??''));
                if($action==='browser.grant'&&$url==='')
                    throw new RuntimeException('Explicit HTTPS browser URL required.',422);
                if($url!==''){
                    if(strlen($url)>1400||!str_starts_with(strtolower($url),'https://'))
                        throw new RuntimeException('Browser URL must be HTTPS and within the limit.',422);
                    $payload['url']=$url;
                }
            }
        }
        if(str_starts_with($action,'browser.live.')){
            $taskId=trim((string)($input['task_id']??''));
            if(!preg_match('/^[0-9a-f-]{36}$/i',$taskId))
                throw new RuntimeException('Live browser task ID is invalid.',422);
            $payload['task_id']=$taskId;
            if($action==='browser.live.approve'){
                $proposalId=trim((string)($input['proposal_id']??''));
                if(!preg_match('/^[0-9a-f-]{36}$/i',$proposalId)||($input['confirmed']??null)!==true)
                    throw new RuntimeException('Explicit navigation proposal confirmation required.',422);
                $payload['proposal_id']=$proposalId;
            }
        }
        if(str_starts_with($action,'browser.action.')){
            $taskId=trim((string)($input['task_id']??''));
            if(!preg_match('/^[0-9a-f-]{36}$/i',$taskId))
                throw new RuntimeException('Invalid interactive worker identifier.',422);
            $payload['task_id']=$taskId;
            if($action==='browser.action.approve'){
                $proposalId=trim((string)($input['proposal_id']??''));
                if(!preg_match('/^[0-9a-f-]{36}$/i',$proposalId)||($input['confirmed']??null)!==true)
                    throw new RuntimeException('Explicit action confirmation is required.',422);
                $value=$input['value']??null;
                if(!is_bool($value)&&!is_int($value)&&!is_string($value))
                    throw new RuntimeException('Invalid browser control value.',422);
                if(is_string($value)&&strlen($value)>300)
                    throw new RuntimeException('Browser input exceeds limit.',422);
                $payload['proposal_id']=$proposalId;
                $payload['confirmed']=true;
                $payload['value']=$value;
            }
        }
        if(str_starts_with($action,'browser.owner.')){
            $taskId=trim((string)($input['task_id']??''));
            if(!preg_match('/^[0-9a-f-]{36}$/i',$taskId))
                throw new RuntimeException('Invalid owner browser worker identifier.',422);
            $payload['task_id']=$taskId;
            if($action==='browser.owner.control'){
                if(($input['confirmed']??null)!==true)throw new RuntimeException('Owner control requires confirmation.',422);
                $index=$input['index']??null;
                if(!is_int($index)||$index<0||$index>119)throw new RuntimeException('Control index invalid.',422);
                $fingerprint=trim((string)($input['fingerprint']??''));
                if(!preg_match('/^[a-f0-9]{24}$/',$fingerprint))throw new RuntimeException('Control fingerprint invalid.',422);
                $kind=trim((string)($input['kind']??''));
                if(!in_array($kind,['fill','check','select','toggle'],true))
                    throw new RuntimeException('Unsupported owner control.',422);
                $value=$input['value']??null;
                if(!is_string($value)&&!is_int($value)&&!is_bool($value))
                    throw new RuntimeException('Invalid owner control value.',422);
                if(is_string($value)&&strlen($value)>300)throw new RuntimeException('Owner input too long.',422);
                $payload+=['index'=>$index,'fingerprint'=>$fingerprint,'kind'=>$kind,
                           'value'=>$value,'confirmed'=>true];
            }
            if($action==='browser.owner.search.review'){
                $index=$input['index']??null;
                $fingerprint=trim((string)($input['fingerprint']??''));
                if(!is_int($index)||$index<0||$index>19||!preg_match('/^[a-f0-9]{24}$/',$fingerprint))
                    throw new RuntimeException('Search form selection invalid.',422);
                $payload+=['index'=>$index,'fingerprint'=>$fingerprint];
            }
            if($action==='browser.owner.search.submit'){
                $proposalId=trim((string)($input['proposal_id']??''));
                if(!preg_match('/^[0-9a-f-]{36}$/i',$proposalId)||($input['confirmed']??null)!==true)
                    throw new RuntimeException('Exact GET search approval required.',422);
                $payload+=['proposal_id'=>$proposalId,'confirmed'=>true];
            }
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
