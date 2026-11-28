<?php
declare(strict_types=1);

// The retained synchronous native and signed connected-site calls must enforce
// the same response setting as queued native chat. No service functions mocked.
define('PA1_PUBLIC_BOOTSTRAP_ONLY',true);
require __DIR__.'/profile-public-authority-pa1.php';
require_once dirname(__DIR__).'/includes/profile-agent-responses-v1.php';
require_once dirname(__DIR__).'/includes/profile-owner-reply-v1.php';

function public_response_mode_v1(string $mode): void
{
    $p=db();$p->beginTransaction();
    try{$settings=vp3_profile_response_settings_v1($p,1,true);vp3_profile_response_settings_save_v1($p,1,$mode,(int)$settings['revision']);$p->commit();}
    catch(Throwable $error){if($p->inTransaction())$p->rollBack();throw $error;}
}
function public_response_context_v1(string $transport): array
{
    return $transport==='native'?nativeContext():externalContext();
}
function public_response_waiting_v1(array $ctx,array $result,string $label): array
{
    $p=db();$cid=(int)$result['conversation_id'];$poll=vp3_profile_agent_public_poll_service_v110($p,$ctx,$cid);$request=$poll['response']['request']??null;
    check(is_array($request)&&$request['status']==='waiting'&&$request['conversation_id']===$cid,$label.' exposes the persisted waiting request');
    check(($result['response']['request']['id']??0)===$request['id'],$label.' returns the same request in admission and subsequent poll');
    check(($result['answer']??'')===''&&countRows('profile_agent_messages',"sender_type='agent'")===0,$label.' returns no fabricated Agent answer');
    $q=$p->prepare('SELECT * FROM profile_agent_owner_requests_v1 WHERE id=?');$q->execute([$request['id']]);$stored=$q->fetch();
    check($stored&&(int)$stored['visitor_message_id']===$request['visitor_message_id']&&(int)$stored['receipt_message_id']===$request['receipt_message_id'],$label.' binds owner request and receipt to the exact saved visitor message');
    check(countRows('profile_agent_messages',"sender_type='receipt'")===1&&countRows('profile_agent_messages',"sender_type='system'")===1,$label.' stores one acknowledgment separately from its hidden authority binding');
    $receipt=array_values(array_filter($poll['messages'],static fn($row)=>(int)$row['id']===$request['receipt_message_id']));
    check(count($receipt)===1&&$receipt[0]['sender_type']==='system'&&trim($receipt[0]['message'])!=='',$label.' reload exposes the system acknowledgment');
    check(countRows('agent_attention_items',"source_conversation_id=$cid AND status IN ('pending','seen','snoozed')")>0,$label.' acknowledgment preserves owner attention');
    $wire=json_encode([$result,$poll],JSON_THROW_ON_ERROR);
    check(!preg_match('/PRIVATE_MODEL_EXPLANATION|PRIVATE_LOCAL_EXPLANATION|credential_hash|accepted_json|verification_token|PRIVATE_KNOWLEDGE_SECRET|FOREIGN_KNOWLEDGE_SECRET/',$wire),$label.' public output excludes model diagnostics and private authority data');
    return $request;
}
function public_response_guarded_v1(callable $operation): void
{
    try{$operation();}catch(VP3ProfileAgentPublicException $error){if($error->publicCode!=='PROFILE_AGENT_UNAVAILABLE')throw $error;}
}

foreach(['native','external'] as $transport){
    resetPublic();public_response_mode_v1('user_responses');$ctx=public_response_context_v1($transport);
    $result=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Please send this question to the owner.');
    $request=public_response_waiting_v1($ctx,$result,$transport.' User responses');
    check($modelCalls===[]&&$homeCalls===[],$transport.' synchronous User responses cannot reach Cloud or HomeServer');
    $cid=(int)$result['conversation_id'];
    vp3_profile_agent_public_request_owner_v110($pdo,$ctx,$cid,'Please send this question to the owner.');
    vp3_profile_agent_public_request_owner_v110($pdo,$ctx,$cid,'Please send this question to the owner.');
    $poll=vp3_profile_agent_public_poll_service_v110($pdo,$ctx,$cid);
    check(countRows('profile_agent_owner_requests_v1')===1&&countRows('profile_agent_messages',"sender_type='receipt'")===1
        &&$poll['response']['request']['id']===$request['id'],$transport.' repeated explicit handoff cannot duplicate a saved owner request or receipt');
    check($modelCalls===[]&&$homeCalls===[],$transport.' explicit handoff and read projections never start inference');

    resetPublic();$ctx=public_response_context_v1($transport);
    $result=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'What are the public hours?');
    check($result['answer']==='Fixture approved answer'&&count($modelCalls)===1&&countRows('profile_agent_owner_requests_v1')===0,$transport.' Automated publishes the parsed supported answer');
    $sources=array_column($modelCalls[0]['context'],'source');
    check(in_array('knowledge:101',$sources,true)&&!in_array('knowledge:102',$sources,true)&&!in_array('knowledge:103',$sources,true),$transport.' uses only permitted knowledge');
    check(($modelCalls[0]['control']['profile_answer_contract']??'')==='vp3.profile-answer.v1'&&($homeCalls[0]['structured']??false)===true,$transport.' requests the same explicit output contract from both providers');

    foreach(['cloud','home'] as $provider){
        foreach(['needs_owner','malformed'] as $kind){
            resetPublic();$ctx=public_response_context_v1($transport);
            $raw=$kind==='needs_owner'?json_encode(['disposition'=>'needs_owner','answer'=>'PRIVATE_MODEL_EXPLANATION: facts are missing'],JSON_THROW_ON_ERROR):'unstructured response';
            if($provider==='cloud')$GLOBALS['modelResult']=['ok'=>true,'answer'=>$raw];else $GLOBALS['homeAnswer']=['answer'=>$raw];
            $result=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'What is the unpublished owner price?');
            $request=public_response_waiting_v1($ctx,$result,$transport.' '.$provider.' '.$kind);
            check(countRows('profile_agent_owner_requests_v1')===1,$transport.' '.$provider.' '.$kind.' submits one durable owner request');
            check(count($modelCalls)===($provider==='cloud'?1:0),$transport.' '.$provider.' '.$kind.' does not use another provider to override a cannot-answer disposition');
        }
    }

    resetPublic();$ctx=public_response_context_v1($transport);
    $GLOBALS['homeAnswer']=['answer'=>json_encode(['disposition'=>'answered','answer'=>'Approved local public answer'],JSON_THROW_ON_ERROR)];
    $result=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'What are the public hours?');
    check($result['answer']==='Approved local public answer'&&$modelCalls===[]&&countRows('profile_agent_owner_requests_v1')===0,$transport.' structured HomeServer answer is delivered without Cloud billing');

    foreach(['cloud','home'] as $provider){
        resetPublic();$ctx=public_response_context_v1($transport);
        if($provider==='cloud')$GLOBALS['modelHook']=static fn()=>public_response_mode_v1('user_responses');
        else{
            $GLOBALS['homeAnswer']=['answer'=>json_encode(['disposition'=>'answered','answer'=>'Obsolete local answer'],JSON_THROW_ON_ERROR)];
            $GLOBALS['homeHook']=static fn()=>public_response_mode_v1('user_responses');
        }
        public_response_guarded_v1(fn()=>vp3_profile_agent_public_message_service_v110($pdo,$ctx,'What are the public hours?'));
        check(countRows('profile_agent_messages',"sender_type='agent'")===0,$transport.' mode switch during '.$provider.' inference suppresses the obsolete automatic answer');
        if($provider==='home')check($modelCalls===[],$transport.' mode switch during HomeServer inference cannot fall through to Cloud');
    }

    resetPublic();$ctx=public_response_context_v1($transport);
    $GLOBALS['modelHook']=static fn()=>db()->exec("UPDATE user_data_policies SET profile_agent_allowed=0 WHERE resource_type='knowledge'");
    public_response_guarded_v1(fn()=>vp3_profile_agent_public_message_service_v110($pdo,$ctx,'What are the public hours?'));
    check(countRows('profile_agent_messages',"sender_type='agent'")===0,$transport.' revoked source cannot publish an answered JSON result');

    // Retained synchronous callers may send again after an explicit owner
    // delegation; a later request must not overwrite or answer an older ledger.
    resetPublic();$ctx=public_response_context_v1($transport);$GLOBALS['modelResult']=['ok'=>true,'answer'=>json_encode(['disposition'=>'needs_owner','answer'=>'Owner input is missing.'])];
    $first=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'First question missing approved facts');$cid=(int)$first['conversation_id'];$firstRequest=$first['response']['request'];
    $pdo->beginTransaction();vp3_profile_agent_owner_conversation_v390($pdo,$cid,1,true);vp3_profile_owner_requests_settle_v1($pdo,1,$cid,'delegated');vp3_profile_agent_response_token_v1($pdo,$cid,true);$pdo->commit();
    ageMessages();$GLOBALS['modelResult']=['ok'=>true,'answer'=>json_encode(['disposition'=>'answered','answer'=>'The newly approved answer.'])];
    $supported=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Now answer from approved public facts',$cid);
    check($supported['answer']==='The newly approved answer.'&&$supported['response']['request']['id']===$firstRequest['id']
        &&$supported['response']['request']['status']==='delegated',$transport.' a new supported message after delegation does not falsely settle the earlier owner request');
    ageMessages();$GLOBALS['modelResult']=['ok'=>true,'answer'=>json_encode(['disposition'=>'needs_owner','answer'=>'A different owner decision is needed.'])];
    $later=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'A separate later question needing the owner',$cid);$laterRequest=$later['response']['request'];
    check($laterRequest['id']!==$firstRequest['id']&&$laterRequest['status']==='waiting'&&countRows('profile_agent_owner_requests_v1')===2,$transport.' later inability creates a separate exact-message request after delegation');
    $pdo->beginTransaction();$conversation=vp3_profile_agent_owner_conversation_v390($pdo,$cid,1,true);
    $reply=vp3_profile_owner_reply_legacy_save_v1($pdo,profile_user_row($pdo,1),$conversation,bin2hex(random_bytes(16)),'The owner confirms the later decision.');$pdo->commit();
    $current=vp3_profile_agent_public_poll_service_v110($pdo,$ctx,$cid)['response']['request'];
    $q=$pdo->prepare('SELECT status FROM profile_agent_owner_requests_v1 WHERE id=?');$q->execute([$firstRequest['id']]);
    check($current['id']===$laterRequest['id']&&$current['status']==='answered'&&$current['reply_message_id']===(int)$reply['message_id']
        &&$q->fetchColumn()==='delegated',$transport.' canonical historical owner reply answers only current waiting requests and preserves prior delegation');
    $q->closeCursor();
}

resetPublic();public_response_mode_v1('user_responses');$ctx=nativeContext();$savedPolicy=vp3_profile_response_settings_v1($pdo,1);$pdo->exec('DROP TABLE profile_agent_owner_requests_v1');
$partialPolicy=vp3_profile_response_settings_v1($pdo,1);
check($partialPolicy['mode']==='user_responses'&&$partialPolicy['revision']===$savedPolicy['revision']&&$partialPolicy['ready']===false,'a partial installation preserves the saved response mode and revision while reporting unavailable storage');
$failure=null;try{vp3_profile_agent_public_message_service_v110($pdo,$ctx,'A request whose storage is unavailable');}catch(Throwable $error){$failure=$error;}
check($failure instanceof VP3ProfileResponseException&&$failure->errorCode==='PROFILE_RESPONSES_UPGRADE_REQUIRED'&&$modelCalls===[]&&$homeCalls===[],'missing owner-request schema fails explicitly without automatic inference');
check(countRows('profile_agent_messages',"sender_type IN ('receipt','agent')")===0&&!table_exists('profile_agent_owner_requests_v1'),'a public request cannot install missing schema or claim a successful submission');

echo 'PROFILE_RESPONSE_PUBLIC_V1=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)."; native and signed external production services)\n";
