<?php
declare(strict_types=1);

require_once __DIR__.'/profile-specialist-workspace-v1.php';
require_once __DIR__.'/agent-tool-json-v1.php';

const VP3_PROFILE_OWNER_DRAFT_V1='vp3.profile-owner-reply-draft.v1';

function vp3_profile_owner_drafts_ready_v1(PDO $pdo): bool
{
    try{$q=$pdo->query('SELECT owner_user_id,prepare_request_id,chat_agent_id,chat_conversation_id,public_conversation_id,turn_id,turn_revision,source_revision,request_hash,request_json,source_json,status,body,error_class,dispatched_at_ms,dispatch_token,superseded_by,send_request_id,sent_message_id,sent_body_hash,owner_edited,created_at_ms,updated_at_ms FROM profile_owner_drafts_v1 WHERE 1=0');$q->closeCursor();return true;}
    catch(Throwable){return false;}
}
function vp3_profile_owner_drafts_ensure_schema_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Profile draft storage must be upgraded before use.');
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'BIGINT UNSIGNED':'INTEGER';$uid=$mysql?'INT UNSIGNED':'INTEGER';$text=$mysql?'MEDIUMTEXT':'TEXT';
    $pdo->exec('CREATE TABLE IF NOT EXISTS profile_owner_drafts_v1 (
        owner_user_id '.$uid.' NOT NULL,prepare_request_id CHAR(32) NOT NULL,chat_agent_id '.$id.' NOT NULL,
        chat_conversation_id '.$id.' NOT NULL,public_conversation_id '.$id.' NOT NULL,turn_id '.$id.' NOT NULL,turn_revision INTEGER NOT NULL,
        source_revision CHAR(64) NOT NULL,request_hash CHAR(64) NOT NULL,request_json '.$text.' NOT NULL,source_json '.$text.' NOT NULL,
        status VARCHAR(16) NOT NULL,body TEXT NOT NULL,error_class VARCHAR(40) NOT NULL DEFAULT \'\',dispatched_at_ms BIGINT NULL,dispatch_token CHAR(32) NOT NULL DEFAULT \'\',
        superseded_by CHAR(32) NOT NULL DEFAULT \'\',send_request_id CHAR(32) NOT NULL DEFAULT \'\',sent_message_id '.$id.' NULL,
        sent_body_hash CHAR(64) NOT NULL DEFAULT \'\',owner_edited SMALLINT NOT NULL DEFAULT 0,created_at_ms BIGINT NOT NULL,updated_at_ms BIGINT NOT NULL,
        PRIMARY KEY(owner_user_id,prepare_request_id)
    )'.($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':''));
    $index='idx_profile_owner_drafts_scope_v1';
    $q=$pdo->prepare($mysql?'SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?':"SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?");
    $q->execute(['profile_owner_drafts_v1',$index]);$exists=(bool)$q->fetchColumn();$q->closeCursor();
    if(!$exists){try{$pdo->exec('CREATE INDEX '.$index.' ON profile_owner_drafts_v1(owner_user_id,chat_agent_id,chat_conversation_id,public_conversation_id,created_at_ms)');}catch(PDOException $error){$q->execute(['profile_owner_drafts_v1',$index]);if(!$q->fetchColumn())throw $error;}}
    if(!vp3_profile_owner_drafts_ready_v1($pdo))throw new RuntimeException('Profile draft storage is unavailable.',503);
}
function vp3_profile_owner_draft_require_v1(PDO $pdo): void
{if(!vp3_profile_owner_drafts_ready_v1($pdo))throw new RuntimeException('Profile drafts need a database upgrade.',503);}
function vp3_profile_owner_draft_row_v1(PDO $pdo,int $owner,string $id,bool $lock=false): ?array
{return vp3_profile_workspace_query_v1($pdo,'SELECT * FROM profile_owner_drafts_v1 WHERE owner_user_id=? AND prepare_request_id=?'.($lock?vp3_profile_handoff_lock_pa3($pdo):''),[$owner,$id])[0]??null;}
function vp3_profile_owner_draft_exact_v1(array $row,array $scope,int $cid): void
{
    if((int)$row['owner_user_id']!==(int)$scope['user']['id']||(int)$row['chat_agent_id']!==$scope['agent_id']
        ||(int)$row['chat_conversation_id']!==$scope['conversation_id']||(int)$row['public_conversation_id']!==$cid)
        throw new RuntimeException('This draft belongs to a different Profile workspace conversation.',409);
}
function vp3_profile_owner_draft_atomic_v1(PDO $pdo,callable $work): mixed
{
    if(!$pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    return vp3_profile_handoff_atomic_pa3($pdo,$work);
}
function vp3_profile_owner_draft_prepare_input_v1(array $scope,array $input): array
{
    $source=$input['source_revision']??null;
    if(!is_string($source)||!preg_match('/\A[a-f0-9]{64}\z/D',$source))throw new RuntimeException('Refresh this visitor conversation before preparing a reply.',422);
    $supersedes=isset($input['supersedes_request_id'])?vp3_profile_owner_reply_request_v1($input['supersedes_request_id']):'';
    $request=vp3_profile_owner_reply_request_v1($input['prepare_request_id']??null);
    if($supersedes===$request)throw new RuntimeException('Regeneration needs a new draft request.',422);
    $wire=['action'=>'draft_prepare','chat_agent_id'=>$scope['agent_id'],'chat_conversation_id'=>$scope['conversation_id'],
        'public_conversation_id'=>vp3_profile_workspace_id_v1($input['public_conversation_id']??null),
        'turn_id'=>vp3_profile_workspace_id_v1($input['turn_id']??null),'revision'=>vp3_profile_workspace_id_v1($input['revision']??null),
        'source_revision'=>$source,'prepare_request_id'=>$request,'instruction'=>vp3_profile_workspace_text_v1($input['instruction']??'',1000,true)];
    if($supersedes!=='')$wire['supersedes_request_id']=$supersedes;
    return $wire;
}
function vp3_profile_owner_draft_source_guard_v1(array $source,array $wire): void
{
    if(!$source['can_prepare']||(int)($source['turn']['id']??0)!==$wire['turn_id']||(int)($source['turn']['version']??0)!==$wire['revision']
        ||!hash_equals($source['source_revision'],$wire['source_revision']))throw new RuntimeException('This draft source changed. Refresh the visitor conversation.',409);
}
function vp3_profile_owner_draft_project_v1(PDO $pdo,array $scope,array $row): array
{
    $cid=(int)$row['public_conversation_id'];vp3_profile_owner_draft_exact_v1($row,$scope,$cid);
    $wire=json_decode((string)$row['request_json'],true,16,JSON_THROW_ON_ERROR);$source=json_decode((string)$row['source_json'],true,16,JSON_THROW_ON_ERROR);
    if(!is_array($wire)||!is_array($source))throw new RuntimeException('Profile draft storage could not be verified.',503);
    $current=vp3_profile_workspace_source_v1($pdo,$scope,$cid);
    $same=$current['can_prepare']&&hash_equals($current['source_revision'],(string)$row['source_revision'])
        &&(int)($current['turn']['id']??0)===(int)$row['turn_id']&&(int)($current['turn']['version']??0)===(int)$row['turn_revision'];
    $status=(string)$row['status'];$state=match($status){'reserved'=>'queued','dispatching'=>'running','prepared'=>'ready','withheld','superseded'=>'stale',default=>$status};
    if($status==='dispatching'&&(int)$row['dispatched_at_ms']<vp3_profile_handoff_now_pa3()-90000)$state='uncertain';
    if(!$same&&in_array($state,['queued','running','ready'],true))$state='stale';
    return ['draft_id'=>$row['prepare_request_id'],'prepare_request_id'=>$row['prepare_request_id'],'public_conversation_id'=>$cid,
        'source'=>$source,'prepare'=>$wire,'instruction'=>$wire['instruction'],'supersedes_request_id'=>$wire['supersedes_request_id']??null,
        'state'=>$state,'text'=>in_array($status,['prepared','sent','superseded'],true)?(string)$row['body']:'','current'=>$same&&$row['superseded_by']==='',
        'reason'=>$state==='stale'?'source_changed':(string)$row['error_class'],
        'can_resume'=>$status==='reserved'&&$same&&$row['superseded_by']==='',
        'can_regenerate'=>$current['can_prepare']&&$row['superseded_by']===''&&!in_array($state,['queued','running','sent'],true),
        'attempted'=>$row['dispatched_at_ms']!==null,'owner_edited'=>!empty($row['owner_edited']),
        'receipt'=>$row['sent_message_id']!==null?['request_id'=>$row['send_request_id'],'message_id'=>(int)$row['sent_message_id']]:null];
}
function vp3_profile_owner_draft_status_v1(PDO $pdo,array $scope,int $cid,array $input): ?array
{
    vp3_profile_owner_draft_require_v1($pdo);
    if(!vp3_profile_agent_owner_conversation_v390($pdo,$cid,(int)$scope['user']['id']))throw new RuntimeException('Profile conversation not found.',404);
    if(isset($input['prepare_request_id'])){
        if(isset($input['latest']))throw new RuntimeException('Choose one exact draft lookup.',422);
        $row=vp3_profile_owner_draft_row_v1($pdo,(int)$scope['user']['id'],vp3_profile_owner_reply_request_v1($input['prepare_request_id']));
        if(!$row)throw new RuntimeException('Profile draft not found.',404);
        vp3_profile_owner_draft_exact_v1($row,$scope,$cid);
    }else{
        if(!in_array($input['latest']??null,[1,'1'],true))throw new RuntimeException('Choose an exact draft or the latest saved draft.',422);
        $row=vp3_profile_workspace_query_v1($pdo,'SELECT * FROM profile_owner_drafts_v1 WHERE owner_user_id=? AND chat_agent_id=? AND chat_conversation_id=? AND public_conversation_id=? ORDER BY created_at_ms DESC,prepare_request_id DESC LIMIT 1',[(int)$scope['user']['id'],$scope['agent_id'],$scope['conversation_id'],$cid])[0]??null;
    }
    return $row?vp3_profile_owner_draft_project_v1($pdo,$scope,$row):null;
}

/** The one physical-attempt fence. HomeServer calls it inside the queue INSERT transaction. */
function vp3_profile_owner_draft_dispatch_v1(PDO $pdo,array $scope,array $wire,string $hash,string $attemptToken,?callable $guard=null): bool
{
    return vp3_profile_owner_draft_atomic_v1($pdo,function()use($pdo,$scope,$wire,$hash,$attemptToken,$guard){
        $scope=vp3_profile_workspace_fresh_v1($pdo,$scope,$guard);
        vp3_profile_owner_reply_lock_v1($pdo,$scope['user']);
        $source=vp3_profile_workspace_source_v1($pdo,$scope,$wire['public_conversation_id'],true);
        vp3_profile_owner_draft_source_guard_v1($source,$wire);
        $row=vp3_profile_owner_draft_row_v1($pdo,(int)$scope['user']['id'],$wire['prepare_request_id'],true);
        if(!$row||$row['status']!=='reserved'||$row['dispatched_at_ms']!==null||$row['superseded_by']!==''||!hash_equals($row['request_hash'],$hash))return false;
        vp3_profile_owner_draft_exact_v1($row,$scope,$wire['public_conversation_id']);
        vp3_profile_workspace_fresh_v1($pdo,$scope,$guard);
        $q=$pdo->prepare("UPDATE profile_owner_drafts_v1 SET status='dispatching',dispatched_at_ms=?,dispatch_token=?,updated_at_ms=?,error_class='' WHERE owner_user_id=? AND prepare_request_id=? AND status='reserved' AND dispatched_at_ms IS NULL AND superseded_by=''");
        $now=vp3_profile_handoff_now_pa3();$q->execute([$now,$attemptToken,$now,(int)$scope['user']['id'],$wire['prepare_request_id']]);
        return $q->rowCount()===1;
    });
}

/** Bounded synchronous preparation; no background worker or public reply is started. */
function vp3_profile_owner_draft_prepare_v1(PDO $pdo,array $scope,array $input,?callable $guard=null,?callable $model=null): array
{
    vp3_profile_owner_draft_require_v1($pdo);$scope=vp3_profile_workspace_fresh_v1($pdo,$scope,$guard);
    $wire=vp3_profile_owner_draft_prepare_input_v1($scope,$input);$owner=(int)$scope['user']['id'];$cid=$wire['public_conversation_id'];$id=$wire['prepare_request_id'];
    $json=json_encode($wire,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$hash=hash('sha256',$owner.'|'.$json);
    $row=vp3_profile_owner_draft_atomic_v1($pdo,function()use($pdo,$scope,$wire,$owner,$cid,$id,$json,$hash){
        vp3_profile_owner_reply_lock_v1($pdo,$scope['user']);
        if(!vp3_profile_agent_owner_conversation_v390($pdo,$cid,$owner,true))throw new RuntimeException('Profile conversation not found.',404);
        $prior=vp3_profile_owner_draft_row_v1($pdo,$owner,$id,true);
        // Recover the immutable saved operation before applying fresh-turn rules.
        if($prior){vp3_profile_owner_draft_exact_v1($prior,$scope,$cid);if(!hash_equals($prior['request_hash'],$hash))throw new RuntimeException('This draft request already has different input.',409);return $prior;}
        $source=vp3_profile_workspace_source_v1($pdo,$scope,$cid,true);vp3_profile_owner_draft_source_guard_v1($source,$wire);
        $latest=vp3_profile_workspace_query_v1($pdo,'SELECT * FROM profile_owner_drafts_v1 WHERE owner_user_id=? AND chat_agent_id=? AND chat_conversation_id=? AND public_conversation_id=? ORDER BY created_at_ms DESC,prepare_request_id DESC LIMIT 1'.vp3_profile_handoff_lock_pa3($pdo),[$owner,$scope['agent_id'],$scope['conversation_id'],$cid])[0]??null;
        if($latest&&$latest['status']!=='sent'&&($wire['supersedes_request_id']??'')!==$latest['prepare_request_id'])throw new RuntimeException('Review or explicitly regenerate the saved draft first.',409);
        if(isset($wire['supersedes_request_id'])){
            $previous=vp3_profile_owner_draft_row_v1($pdo,$owner,$wire['supersedes_request_id'],true);
            if(!$previous)throw new RuntimeException('The original draft is unavailable.',409);
            vp3_profile_owner_draft_exact_v1($previous,$scope,$cid);
            if($previous['status']==='sent'||$previous['superseded_by']!=='')throw new RuntimeException('The original draft changed.',409);
            $sameSource=hash_equals($previous['source_revision'],$source['source_revision']);
            if($sameSource&&($previous['status']==='reserved'||($previous['status']==='dispatching'&&(int)$previous['dispatched_at_ms']>=vp3_profile_handoff_now_pa3()-90000)))
                throw new RuntimeException('This draft is still being prepared. Check its saved status first.',409);
            $pdo->prepare("UPDATE profile_owner_drafts_v1 SET status='superseded',superseded_by=?,updated_at_ms=? WHERE owner_user_id=? AND prepare_request_id=?")
                ->execute([$id,vp3_profile_handoff_now_pa3(),$owner,$wire['supersedes_request_id']]);
        }
        $proof=['turn_id'=>$wire['turn_id'],'revision'=>$wire['revision'],'visitor_message_id'=>(int)$source['turn']['visitor_message_id'],
            'latest_message_id'=>$source['latest_message_id'],'source_revision'=>$source['source_revision']];
        // Monotonic within this exact scope, even for two requests in one millisecond.
        $now=max(vp3_profile_handoff_now_pa3(),(int)($latest['created_at_ms']??0)+1);
        $pdo->prepare("INSERT INTO profile_owner_drafts_v1(owner_user_id,prepare_request_id,chat_agent_id,chat_conversation_id,public_conversation_id,turn_id,turn_revision,source_revision,request_hash,request_json,source_json,status,body,created_at_ms,updated_at_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?,'reserved','',?,?)")
            ->execute([$owner,$id,$scope['agent_id'],$scope['conversation_id'],$cid,$wire['turn_id'],$wire['revision'],$wire['source_revision'],$hash,$json,json_encode($proof,JSON_THROW_ON_ERROR),$now,$now]);
        return vp3_profile_owner_draft_row_v1($pdo,$owner,$id,true);
    });
    if($row['status']!=='reserved'||$row['dispatched_at_ms']!==null||$row['superseded_by']!=='')return vp3_profile_owner_draft_project_v1($pdo,$scope,$row);
    $source=vp3_profile_workspace_source_v1($pdo,$scope,$cid);
    if(!$source['can_prepare']||!hash_equals($source['source_revision'],$wire['source_revision']))return vp3_profile_owner_draft_project_v1($pdo,$scope,$row);
    $history=array_slice($source['messages'],-20);$complete=$source['history_complete']&&count($source['messages'])<=20;
    $history=array_map(static function(array $message)use(&$complete):array{
        $truncated=mb_strlen($message['text'])>1800;if($truncated)$complete=false;
        return ['speaker'=>$message['sender_type'],'text'=>mb_substr($message['text'],0,1800),'truncated'=>$truncated];
    },$history);
    $data=['owner_instruction'=>$wire['instruction'],'private_agent'=>['name'=>$source['agent_proof']['display_name'],'instructions'=>mb_substr($source['agent_proof']['instructions'],0,4000)],
        'visitor_conversation'=>['history_complete'=>$complete,'messages'=>$history],
        'source_is_untrusted'=>true,'purpose'=>'Prepare a private owner-reviewed reply; never send it.'];
    $prompt=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if(strlen($prompt)>180000)throw new RuntimeException('This visitor history is too large to prepare safely.',422);
    $attemptToken=bin2hex(random_bytes(16));
    $dispatch=static fn():bool=>vp3_profile_owner_draft_dispatch_v1($pdo,$scope,$wire,$hash,$attemptToken,$guard);
    $body='';$failure='';
    try{
        $result=$model!==null?$model($prompt,$dispatch,$scope,$wire):vp3_profile_owner_draft_model_v1($pdo,$scope,$wire,$prompt,$dispatch);
        if(!is_array($result)||empty($result['ok'])||!is_string($result['answer']??null))$failure='provider_unavailable';
        else{
            $parsed=vp3_tool_json_object_v1($result['answer'],['body'],20000);
            if(array_keys($parsed)!==['body'])throw new RuntimeException('Invalid private draft result.');
            $body=vp3_profile_workspace_text_v1($parsed['body'],4000);
        }
    }catch(Throwable){$failure='preparation_unconfirmed';}
    $scope=vp3_profile_workspace_fresh_v1($pdo,$scope,$guard);
    $row=vp3_profile_owner_draft_atomic_v1($pdo,function()use($pdo,$scope,$wire,$owner,$cid,$id,$hash,$body,$failure,$attemptToken,$guard){
        vp3_profile_owner_reply_lock_v1($pdo,$scope['user']);$source=vp3_profile_workspace_source_v1($pdo,$scope,$cid,true);
        $row=vp3_profile_owner_draft_row_v1($pdo,$owner,$id,true);
        if(!$row||!hash_equals($row['request_hash'],$hash))throw new RuntimeException('Profile draft storage could not be verified.',503);
        if(!in_array($row['status'],['reserved','dispatching'],true)||$row['superseded_by']!=='')return $row;
        if($row['dispatch_token']!==''&&!hash_equals($row['dispatch_token'],$attemptToken))return $row;
        $same=$source['can_prepare']&&hash_equals($source['source_revision'],$wire['source_revision']);
        $attempted=$row['dispatched_at_ms']!==null;
        $state=!$same?'withheld':($failure!==''?($attempted?'uncertain':'reserved'):($attempted?'prepared':'failed'));
        $reason=!$same?'source_changed':($failure!==''?$failure:($attempted?'':'attempt_unverified'));
        vp3_profile_workspace_fresh_v1($pdo,$scope,$guard);
        $pdo->prepare('UPDATE profile_owner_drafts_v1 SET status=?,body=?,error_class=?,updated_at_ms=? WHERE owner_user_id=? AND prepare_request_id=?')
            ->execute([$state,$state==='prepared'?$body:'',$reason,vp3_profile_handoff_now_pa3(),$owner,$id]);
        return vp3_profile_owner_draft_row_v1($pdo,$owner,$id,true);
    });
    $scope=vp3_profile_workspace_fresh_v1($pdo,$scope,$guard);
    return vp3_profile_owner_draft_project_v1($pdo,$scope,$row);
}

/** This binding commits with the canonical public owner reply operation. */
function vp3_profile_owner_draft_send_guard_v1(PDO $pdo,array $scope,array $input,int $cid,int $turnId,int $revision): void
{
    vp3_profile_owner_draft_require_v1($pdo);vp3_profile_owner_reply_lock_v1($pdo,$scope['user']);
    $source=vp3_profile_workspace_source_v1($pdo,$scope,$cid,true);
    $id=vp3_profile_owner_reply_request_v1($input['prepare_request_id']??null);$row=vp3_profile_owner_draft_row_v1($pdo,(int)$scope['user']['id'],$id,true);
    if(!$row)throw new RuntimeException('Profile draft not found.',404);
    vp3_profile_owner_draft_exact_v1($row,$scope,$cid);
    if($row['status']!=='prepared'||$row['superseded_by']!==''||!$source['can_prepare']||$turnId!==(int)$row['turn_id']||$revision!==(int)$row['turn_revision']
        ||!hash_equals($row['source_revision'],$source['source_revision'])||$row['send_request_id']!=='')throw new RuntimeException('This draft is no longer current. Review the visitor conversation.',409);
    $request=vp3_profile_owner_reply_request_v1($input['request_id']??null);$message=vp3_profile_workspace_text_v1($input['message']??null,4000);
    $pdo->prepare('UPDATE profile_owner_drafts_v1 SET send_request_id=?,sent_body_hash=? WHERE owner_user_id=? AND prepare_request_id=?')
        ->execute([$request,hash('sha256',$message),(int)$scope['user']['id'],$id]);
}
function vp3_profile_owner_draft_sent_v1(PDO $pdo,array $scope,mixed $id,int $cid,string $request,string $message,int $messageId): void
{
    $id=vp3_profile_owner_reply_request_v1($id);$owner=(int)$scope['user']['id'];$row=vp3_profile_owner_draft_row_v1($pdo,$owner,$id,true);
    if(!$row)throw new RuntimeException('Profile draft not found.',404);
    vp3_profile_owner_draft_exact_v1($row,$scope,$cid);
    if(!in_array($row['status'],['prepared','sent'],true)||$row['send_request_id']!==$request||!hash_equals($row['sent_body_hash'],hash('sha256',$message))
        ||($row['sent_message_id']!==null&&(int)$row['sent_message_id']!==$messageId))throw new RuntimeException('The saved draft reply does not match this request.',409);
    $saved=vp3_profile_workspace_query_v1($pdo,"SELECT message,context_json FROM profile_agent_messages WHERE id=? AND conversation_id=? AND sender_type='owner' AND sender_user_id=?",[$messageId,$cid,$owner])[0]??null;
    $marker=$saved?json_decode((string)$saved['context_json'],true):null;
    if(!$saved||$saved['message']!==$message||($marker['profile_handoff']['owner_request_id']??null)!==$request
        ||($marker['profile_handoff']['turn_id']??null)!==(int)$row['turn_id'])throw new RuntimeException('The saved draft reply could not be verified.',409);
    if($row['status']!=='sent')$pdo->prepare("UPDATE profile_owner_drafts_v1 SET status='sent',sent_message_id=?,owner_edited=?,updated_at_ms=? WHERE owner_user_id=? AND prepare_request_id=?")
        ->execute([$messageId,$message!==$row['body']?1:0,vp3_profile_handoff_now_pa3(),$owner,$id]);
}

if(!function_exists('vp3_profile_owner_draft_model_v1')){
    /** Caller context only, governed foreground usage, exactly one transport. */
    function vp3_profile_owner_draft_model_v1(PDO $pdo,array $scope,array $wire,string $prompt,Closure $dispatch): array
    {
        require_once __DIR__.'/agent-inference-budget-v1.php';
        return vp3_ai_with_budget_v1(55,static function()use($pdo,$scope,$wire,$prompt,$dispatch):array{
            require_once __DIR__.'/agent-runtime-routing-v420.php';
            $service=['service'=>'conversation','background'=>false,'stage'=>VP3_PROFILE_OWNER_DRAFT_V1,'agent_id'=>$scope['agent_id'],
                'conversation_id'=>$scope['conversation_id'],'job_key'=>'profile-owner-draft:'.$wire['prepare_request_id']];
            $plan=vp3_agent_runtime_plan_v420($pdo,$scope['user'],$scope['agent_id'],'chat',$service);
            if(!empty($plan['blocked']))return ['ok'=>false];
            if(!in_array($plan['route']??'',['homeserver','vp3_cloud'],true))return ['ok'=>false];
            $route=$plan['route'];
            $preference=vp3_agent_runtime_preference_readonly_v420($pdo,(int)$scope['user']['id'],$scope['agent_id']);
            $preferenceHash=hash('sha256',json_encode($preference,JSON_THROW_ON_ERROR));
            $fence=static function()use($pdo,$scope,$service,$preferenceHash,$dispatch):bool{
                // This fence may run inside the paired queue transaction. It uses
                // local policy reads only; transport discovery already chose this
                // exact route and the queue locks/rechecks its paired session.
                $current=vp3_agent_runtime_preference_readonly_v420($pdo,(int)$scope['user']['id'],$scope['agent_id']);
                if(!hash_equals($preferenceHash,hash('sha256',json_encode($current,JSON_THROW_ON_ERROR))))return false;
                $policy=ai_v100_service_policy($pdo,$scope['user'],$service);
                if($policy!==null&&empty($policy['enabled']))return false;
                return $dispatch();
            };
            if($route==='homeserver'){
                require_once __DIR__.'/homeserver-local-execution-v230.php';
                $run=homeserver_execution_v230_execute((int)$scope['user']['id'],'agent.infer.local',[
                    'messages'=>[['role'=>'system','content'=>vp3_tool_json_contract_v1(VP3_PROFILE_OWNER_DRAFT_V1)],['role'=>'user','content'=>$prompt]]
                ],null,true,$fence);
                return ['ok'=>!empty($run['ok']),'answer'=>(string)($run['result']['reply']??'')];
            }
            return ai_v100_with_service_context($service,static fn()=>ai_generate_chat_response($prompt,[],[],$scope['user'],'main_ai',[
                'surface'=>'tool_json','protocol'=>VP3_PROFILE_OWNER_DRAFT_V1,'before_profile_owner_reply_dispatch'=>$fence]));

        });
    }
}
