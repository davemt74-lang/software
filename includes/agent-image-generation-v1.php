<?php
declare(strict_types=1);
require_once __DIR__.'/agent-image-provider-v1.php';
require_once __DIR__.'/ai-service-policy-v1.php';
require_once __DIR__.'/ai-service-usage-v1.php';
require_once __DIR__.'/user-llm-v1.php';

/** Only the exact model and funding are persisted with a job. Credentials are read at execution. */
function vp3_image_route_v1(PDO $pdo,array $user,?array $config=null): array
{
    $settings=vp3_image_settings_v1();
    if(!$settings['enabled']||!function_exists('curl_init'))throw new RuntimeException('provider_unavailable',503);
    $policy=vp3_ai_service_policy_v1($pdo,$user,'image_generation');
    if(!$policy['enabled'])throw new RuntimeException($policy['reason']==='service_budget_exhausted'?'service_budget_exceeded':'service_policy_denied',503);
    $funding=vp3_user_llm_v1_funding($pdo,$user);
    $frozen=null;
    if($config!==null){
        $saved=$config['provider_route']??null;
        // Older queued jobs implicitly used site funding, except for explicit per-service grants.
        $admittedFunding=$config['funding_source']??$saved['funding']??'system';
        $oldPersonal=!isset($config['funding_source'])&&$admittedFunding==='own_key';
        if($oldPersonal||$admittedFunding!==$funding||($saved!==null&&(!$policy['configured']||($saved['model']??null)!==$config['model']))){
            throw new RuntimeException('provider_route_changed',503);
        }
        $frozen=['provider'=>$saved['provider']??'openai','model'=>$config['model']];
    }
    if(($policy['configured']||$funding==='own_key')&&!vp3_ai_service_usage_ready_v1($pdo))throw new RuntimeException('service_usage_unavailable',503);

    $targets=$policy['configured']?[$policy['primary'],$policy['fallback']]:[['provider'=>'openai','model'=>$settings['model']]];
    foreach($targets as $target){
        if($target===null||$target['provider']!=='openai'||!vp3_ai_service_target_allowed_v1($policy,$target))continue;
        if($frozen!==null&&$target!==$frozen)continue;
        $key=$funding==='own_key'
            ?vp3_user_llm_v1_saved_key($pdo,(int)$user['id'],'openai')
            :(ai_provider_enabled('openai')?ai_provider_api_key('openai'):'');
        if($key!=='')return ['target'=>$target,'funding'=>$funding,'api_key'=>$key,'policy'=>$policy];
        if($frozen===null&&$funding==='own_key')throw new RuntimeException('personal_image_key_unavailable',503);
        break;
    }
    throw new RuntimeException($frozen===null?'provider_unavailable':'provider_route_changed',503);
}

function vp3_image_account_provider_ready_v1(PDO $pdo,array $user): bool
{
    try{vp3_image_route_v1($pdo,$user);return true;}
    catch(RuntimeException $e){return false;}
}

function vp3_image_admission_route_v1(PDO $pdo,array $user): array
{
    try{return vp3_image_route_v1($pdo,$user);}
    catch(RuntimeException $e){
        $message=match($e->getMessage()){
            'service_usage_unavailable'=>'Run Database Upgrade to enable image-generation usage tracking.',
            'service_policy_denied'=>'Image generation is unavailable under the current account policy.',
            'service_budget_exceeded'=>'The account’s image-generation token limit was reached.',
            'personal_image_key_unavailable'=>'Save an OpenAI key in AI Providers to generate images with your own keys.',
            default=>'Image generation needs an available route in Admin → AI.',
        };
        throw new RuntimeException($message,503,$e);
    }
}

function vp3_image_schema_v1(PDO $pdo,bool $workbench=false): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_image_generations_v1 (generation_id CHAR(36) NOT NULL PRIMARY KEY,owner_user_id BIGINT NOT NULL,agent_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,user_message_id BIGINT NULL,request_hash CHAR(64) NOT NULL,run_id BIGINT NOT NULL UNIQUE,config_json TEXT NOT NULL,provider_started_at VARCHAR(32) NOT NULL DEFAULT '',asset_id BIGINT NOT NULL DEFAULT 0,usage_json TEXT NULL,error_code VARCHAR(60) NOT NULL DEFAULT '',created_at VARCHAR(32) NOT NULL,UNIQUE(owner_user_id,user_message_id))");
    if($workbench&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $nullable=$pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME='agent_image_generations_v1' AND COLUMN_NAME='user_message_id'")->fetchColumn();
        if($nullable==='NO')$pdo->exec('ALTER TABLE agent_image_generations_v1 MODIFY user_message_id BIGINT NULL');
    }
}
function vp3_image_scope_v1(PDO $pdo,array $user,array $principal,array $context): array
{
    if(!function_exists('vp3_agent_chat_resolve_agent_v380'))require_once __DIR__.'/agent-chat-boundary-v380.php';
    if(!function_exists('knowledge_retrieval_v162_owner_session'))require_once __DIR__.'/knowledge-retrieval-v162.php';
    if(!knowledge_retrieval_v162_owner_session($user,$principal)||(int)($context['conversation_id']??0)<1)throw new RuntimeException('Owner conversation required.',403);
    return vp3_capability_live_user_v1($pdo,$user,$principal,$context,null);
}
function vp3_image_row_v1(PDO $pdo,array $user,string $id): array
{
    if(!preg_match('/^[a-f0-9-]{36}$/D',$id))throw new InvalidArgumentException('Invalid image request.');
    $q=$pdo->prepare('SELECT * FROM agent_image_generations_v1 WHERE generation_id=? AND owner_user_id=?');$q->execute([$id,(int)$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Image request not found.',404);return $row;
}
function vp3_image_authority_v1(PDO $pdo,array $user,array $row): array
{
    if((int)$row['owner_user_id']!==(int)$user['id'])throw new RuntimeException('Image request not found.',404);
    if(vp3_image_workspace_row_v1($row))return vp3_image_workspace_owner_v1($pdo,$user);
    $principal=['kind'=>(int)$row['agent_id']>0?'user_agent':'system','owner_user_id'=>(int)$user['id'],'agent_id'=>(int)$row['agent_id']];
    return vp3_image_scope_v1($pdo,$user,$principal,['conversation_id'=>(int)$row['conversation_id']]);
}
function vp3_image_workspace_row_v1(array $row): bool
{
    return (int)$row['conversation_id']===0&&(int)$row['agent_id']===0&&$row['user_message_id']===null;
}
function vp3_image_workspace_owner_v1(PDO $pdo,array $user): array
{
    return vp3_capability_live_user_v1($pdo,$user,['kind'=>'system'],[],null);
}
function vp3_image_text_plan_v1(array $plan): array
{
    $prompt=$plan['prompt']??'';$title=$plan['title']??'';$size=$plan['size']??'1024x1024';$quality=$plan['quality']??'medium';$background=$plan['background']??'opaque';
    if(!is_string($prompt)||mb_strlen(trim($prompt))<3||mb_strlen($prompt)>4000||!is_string($title)||mb_strlen($title)>190||!in_array($size,['1024x1024','1536x1024','1024x1536'],true)||!in_array($quality,['low','medium','high'],true)||!in_array($background,['opaque','transparent'],true))throw new InvalidArgumentException('Use a prompt of 3 to 4,000 characters, an optional title of up to 190 characters, and one of the available image settings.');
    return ['prompt'=>trim($prompt),'title'=>trim($title),'size'=>$size,'quality'=>$quality,'background'=>$background];
}
function vp3_image_lock_conversation_v1(PDO $pdo,int $uid,int $cid,int $aid): void
{
    if(!vp3_agent_chat_conversation_v380($pdo,$cid,$uid,$aid>0?['id'=>$aid]:null,true)){
        throw new RuntimeException('Image conversation access changed.',403);
    }
}
function vp3_image_lock_owner_v1(PDO $pdo,int $owner): void
{
    $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
    $lock->execute([$owner]);
    $lock->fetchColumn();
}
function vp3_image_run_v1(PDO $pdo,array $user,array $row,bool $lock=false): array
{
    require_once __DIR__.'/agent-worker-cloud-v1910.php';
    $run=agent_workflow_row_v1400($pdo,(int)$user['id'],(int)$row['run_id'],$lock);
    $origin=vp3_image_workspace_row_v1($row)?'media_library':'chat';
    if(!$run||$run['origin']!==$origin||$run['source_kind']!=='image_generation'||$run['source_key']!==$row['generation_id']||(int)($run['agent_id']??0)!==(int)$row['agent_id']||$run['execution_target']!=='cloud'||$run['capability_key']!=='images.generate')throw new RuntimeException('Image job binding is unavailable.',409);
    return $run;
}
function vp3_image_view_v1(PDO $pdo,array $user,array $row): array
{
    vp3_image_authority_v1($pdo,$user,$row);$run=vp3_image_run_v1($pdo,$user,$row);
    $config=json_decode($row['config_json'],true,16,JSON_THROW_ON_ERROR);$status=match($run['status']){'approved','queued'=>'queued','executing'=>'generating',default=>$run['status']};
    $activeLeaseExpiresAt=null;
    if($run['status']==='executing'&&(int)($run['current_action_id']??0)>0&&!empty($run['lease_token'])){
        $expires=strtotime((string)($run['lease_expires_at']??'').' UTC');
        if($expires!==false&&$expires>time())$activeLeaseExpiresAt=$expires*1000;
    }
    $asset=null;if($status==='completed'&&(int)$row['asset_id']>0){$saved=media_studio_asset((int)$row['asset_id'],$user);if($saved)$asset=media_studio_asset_payload($saved);if(!$asset||!$asset['available'])$status='file_unavailable';}
    $messages=[
        'provider_access_denied'=>'OpenAI denied this image request. Check image-model access for the selected API key.',
        'provider_rate_limited'=>'OpenAI rate-limited the image request. It was not automatically retried.',
        'provider_request_rejected'=>'OpenAI rejected the image request. Revise the prompt or check the image settings.',
        'subscription_required'=>'The account needs sufficient AI quota before generating an image.',
        'provider_unavailable'=>'Image generation is disabled or the selected OpenAI key is unavailable.',
        'provider_route_changed'=>'The approved image route changed or its API key became unavailable. Start a new request to use the current route.',
        'service_policy_denied'=>'Image generation is unavailable under the current account policy.',
        'service_budget_exceeded'=>'The account’s image-generation token limit was reached.',
        'service_usage_unavailable'=>'Image-generation usage tracking is unavailable.',
        'provider_usage_unconfirmed'=>'OpenAI did not return verifiable usage. The request was not automatically retried.',
        'quota_preflight_failed'=>'The AI quota check did not complete. No image request was sent to OpenAI.',
        'image_storage_unavailable'=>'The generated image could not be saved in private storage. The request was not automatically retried.',
        'image_source_changed'=>'The selected screen capture changed or was removed. No edited image was saved.',
        'marketing_source_changed'=>'The reviewed marketing variant or product photo changed. No image was saved.',
    ];
    $error=$row['error_code'];$message=$error!==''?($messages[$error]??'The image result could not be confirmed. The provider may already have processed the request; it was not automatically retried.'):($status==='failed'?'The image job stopped without a confirmed result. It was not automatically retried.':'');
    return ['id'=>$row['generation_id'],'title'=>$config['title'],'prompt'=>$config['prompt'],'model'=>$config['model'],'size'=>$config['size'],'quality'=>$config['quality'],'background'=>$config['background'],'status'=>$status,'message'=>$message,'asset'=>$asset,'job_id'=>(int)$row['run_id'],'provider_started'=>$row['provider_started_at']!=='','created_at'=>$row['created_at'],'active_lease_expires_at'=>$activeLeaseExpiresAt,
        ...(isset($config['mode'])?['mode'=>$config['mode']]:[])];
}

/** The canonical workflow is the execution queue; this table binds prompt, turn and output. */
function vp3_image_queue_v1(PDO $pdo,array $user,array $principal,array $plan,array $context): array
{
    $user=vp3_image_scope_v1($pdo,$user,$principal,$context);$uid=(int)$user['id'];$cid=(int)$context['conversation_id'];$aid=($principal['kind']??'')==='user_agent'?(int)$principal['agent_id']:0;$turn=(int)($context['chat_user_message_id']??0);
    vp3_image_queue_ready_v1($pdo,$user,false);
    $q=$pdo->prepare("SELECT id FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");$q->execute([$turn,$cid,$uid]);if(!$q->fetchColumn())throw new RuntimeException('The current user turn could not be verified.',403);
    $config=['prompt'=>$plan['prompt'],'title'=>mb_substr($plan['title']?:$plan['prompt'],0,190),'size'=>$plan['size'],'quality'=>$plan['quality'],'background'=>$plan['background']];
    return vp3_image_admit_v1($pdo,$user,$cid,$aid,$turn,hash('sha256','image:'.$uid.':'.$turn),static fn(string $model):array=>[...$config,'model'=>$model]);
}

function vp3_image_queue_ready_v1(PDO $pdo,array $user,bool $checkProvider=true): void
{
    require_once __DIR__.'/agent-worker-cloud-v1910.php';
    if(!subscription_has_entitlement($user,'main_ai.access'))throw new RuntimeException('AI image generation is not included in this account.',403);
    if(!agent_job_engine_schema_ready_v1900($pdo)||!agent_job_control_ready_v1900($pdo)||!media_studio_schema_ready())throw new RuntimeException('Image generation needs the current job and Media Studio database upgrade.',503);
    if($checkProvider)vp3_image_admission_route_v1($pdo,$user);
}

function vp3_image_workbench_key_v1(int $owner,string $request): string
{
    return hash('sha256','image:workbench:'.$owner.':'.$request);
}

function vp3_image_queue_work_v1(PDO $pdo,array $user,array $principal,array $context,string $request,callable $configure): array
{
    if(!preg_match('/\A[a-f0-9]{64}\z/',$request))throw new DomainException('Review the image edit again before creating it.',409);
    $user=vp3_image_scope_v1($pdo,$user,$principal,$context);
    vp3_image_queue_ready_v1($pdo,$user,false);
    return vp3_image_admit_v1($pdo,$user,(int)$context['conversation_id'],(int)$principal['agent_id'],null,
        vp3_image_workbench_key_v1((int)$user['id'],$request),$configure);
}

function vp3_image_queue_workspace_v1(PDO $pdo,array $user,array $plan,string $request): array
{
    if(!preg_match('/\A[a-f0-9]{32}\z/',$request))throw new InvalidArgumentException('Review this image request again.');
    $user=vp3_image_workspace_owner_v1($pdo,$user);
    vp3_image_queue_ready_v1($pdo,$user,false);
    $plan=vp3_image_text_plan_v1($plan);
    $plan['title']=mb_substr($plan['title']!==''?$plan['title']:$plan['prompt'],0,190);
    return vp3_image_admit_v1($pdo,$user,0,0,null,hash('sha256','image:media-library:'.$user['id'].':'.$request),
        static fn(string $model):array=>[...$plan,'model'=>$model,'mode'=>'workspace']);
}

function vp3_image_admit_v1(PDO $pdo,array $user,int $cid,int $aid,?int $turn,string $key,callable $configure): array
{
    $uid=(int)$user['id'];$id=substr($key,0,8).'-'.substr($key,8,4).'-'.substr($key,12,4).'-'.substr($key,16,4).'-'.substr($key,20,12);
    $workspace=$cid===0&&$aid===0&&$turn===null;
    $edit=$turn===null&&!$workspace;
    vp3_image_schema_v1($pdo,$turn===null);$pdo->beginTransaction();try{
        // One owner lock serializes concurrent admission without a separate queue.
        vp3_image_lock_owner_v1($pdo,$uid);
        if($workspace)vp3_image_workspace_owner_v1($pdo,$user);
        elseif($edit)vp3_image_lock_conversation_v1($pdo,$uid,$cid,$aid);
        $q=$pdo->prepare('SELECT * FROM agent_image_generations_v1 WHERE owner_user_id=? AND '.($turn===null?'generation_id=?':'user_message_id=?'));
        $q->execute([$uid,$turn??$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
        if($old){
            $saved=json_decode($old['config_json'],true,16,JSON_THROW_ON_ERROR);
            $config=$configure($saved['model']);
            if(isset($saved['provider_route']))$config['provider_route']=$saved['provider_route'];
            if(isset($saved['funding_source']))$config['funding_source']=$saved['funding_source'];
        }else{
            $route=vp3_image_admission_route_v1($pdo,$user);
            $config=$configure($route['target']['model']);
            if($route['policy']['configured'])$config['provider_route']=$route['target'];
            $config['funding_source']=$route['funding'];
        }
        $marketing=in_array($config['mode']??null,['marketing_scene','marketing_reference'],true);
        $hash=hash('sha256',json_encode([$cid,$aid,$config],JSON_THROW_ON_ERROR));
        if($old){if(!hash_equals($old['request_hash'],$hash))throw new RuntimeException($turn===null?'This approval already has a different image request.':'This user turn already has a different image request.',409);$pdo->commit();return vp3_image_view_v1($pdo,$user,$old);}
        $q=$pdo->prepare("SELECT COUNT(*) FROM agent_image_generations_v1 g JOIN agent_workflow_runs r ON r.id=g.run_id AND r.owner_user_id=g.owner_user_id WHERE g.owner_user_id=? AND r.status IN ('approved','executing','paused')");$q->execute([$uid]);
        if((int)$q->fetchColumn()>0){
            $message='An image request is already active. Finish or cancel it before creating another.';
            throw $edit?new DomainException($message,409):new RuntimeException($message,409);
        }
        $title=mb_substr(($edit&&!$marketing?'Edit capture: ':'Generate image: ').$config['title'],0,190);
        $label=match(true){$marketing=>'Create marketing image',$edit=>'Edit selected screen capture',default=>'Generate requested image'};
        $instruction=match(true){$marketing=>'Prepare one private image for the exact reviewed marketing variant.',$edit=>'Edit one reviewed capture with the frozen owner instruction.',default=>'Create one image from the frozen owner prompt.'};
        $pdo->prepare("INSERT INTO agent_workflow_runs(owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,decision_summary,status,risk_level,requires_approval,approval_status,execution_target,capability_key,max_attempts,retry_backoff_seconds,timeout_seconds,current_action_id) VALUES(?,?,'image_generation',?,'image_generation',?,?,?,?,?,?,'approved','low',0,'not_required','cloud','images.generate',1,60,300,NULL)")->execute([$uid,$aid?:null,$workspace?'media_library':'chat',$id,sha1($config['prompt']),$key,$title,$config['title'],'Explicit owner image request. One provider call; no automatic replay.']);$run=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO agent_workflow_actions(run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key,max_attempts,timeout_seconds) VALUES(?,?,1,?,'execute',?,?,'queued',0,'cloud','images.generate',1,300)")->execute([$run,$uid,$id,$label,$instruction]);
        $pdo->prepare('INSERT INTO agent_image_generations_v1(generation_id,owner_user_id,agent_id,conversation_id,user_message_id,request_hash,run_id,config_json,created_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$uid,$aid,$cid,$turn,$hash,$run,json_encode($config,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s')]);
        $summary=match(true){$workspace=>'Image request approved in the owner Media Library.',$marketing=>'Marketing image queued for the exact owner-reviewed variant.',$edit=>'Image edit queued from the current owner Workbench approval.',default=>'Image request queued from the current owner Chat turn.'};
        agent_workflow_event_v1400($pdo,$uid,$run,'image_requested','','approved','user',$summary,['generation_id'=>$id]);$pdo->commit();return vp3_image_view_v1($pdo,$user,vp3_image_row_v1($pdo,$user,$id));
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function vp3_image_execute_v1(PDO $pdo,array $user,array $claim,?callable $transport=null): array
{
    require_once __DIR__.'/agent-worker-cloud-v1910.php';
    $run=agent_worker_cloud_active_row_v1910($pdo,$user,$claim);if(!$run||$run['action_capability_key']!=='images.generate'||$run['source_kind']!=='image_generation')throw new RuntimeException('Image execution lease is unavailable.',409);
    $row=vp3_image_row_v1($pdo,$user,$run['source_key']);$bound=vp3_image_run_v1($pdo,$user,$row);if((int)$bound['id']!==(int)$run['id']||$run['action_key']!==$row['generation_id'])throw new RuntimeException('Image action binding changed.',409);$reservation=0;$file='';$createdFile=false;$committed=false;$sent=false;
    $edit=$row['user_message_id']===null&&!vp3_image_workspace_row_v1($row);$sourceImage=null;$marketing=false;
    $ticket=[];$reportedUsage=[];$usageSettled=false;$startedAt=null;$target=[];$funding='';
    $settleUsage=static function(string $status,bool $confirmedNoCharge=false)use($pdo,$row,&$user,&$target,&$funding,&$ticket,&$reservation,&$reportedUsage,&$usageSettled,&$startedAt):void{
        if($usageSettled)return;
        if(!empty($ticket['metered'])){
            $options=($reportedUsage['output_tokens']??0)>0?['units'=>1,'unit'=>'image']:[];
            if($confirmedNoCharge)$options['confirmed_no_charge']=true;
            if($startedAt!==null)$options['latency_ms']=(int)round((microtime(true)-$startedAt)*1000);
            $settled=vp3_ai_service_usage_finish_v1($pdo,$ticket,$reportedUsage,$status,$options);
            if($status!=='aborted'&&(int)($settled['id']??0)!==(int)$ticket['id'])throw new RuntimeException('service_usage_unavailable');
        }elseif($reportedUsage!==[]&&$funding==='system'){
            subscription_ai_commit_usage($reservation,$user,'image_generation',$target['provider'],$target['model'],$reportedUsage,'image:'.$row['generation_id']);
            $reservation=0;
        }
        $usageSettled=true;
        if($reportedUsage!==[]){
            $pdo->prepare('UPDATE agent_image_generations_v1 SET usage_json=? WHERE generation_id=? AND owner_user_id=?')->execute([json_encode($reportedUsage,JSON_THROW_ON_ERROR),$row['generation_id'],(int)$user['id']]);
        }
    };
    try{
        $user=vp3_image_authority_v1($pdo,$user,$row);if(!subscription_has_entitlement($user,'main_ai.access'))throw new RuntimeException('subscription_required');
        $config=json_decode($row['config_json'],true,16,JSON_THROW_ON_ERROR);
        $route=vp3_image_route_v1($pdo,$user,$config);$target=$route['target'];$funding=$route['funding'];$policy=$route['policy'];
        if($edit){
            $marketing=in_array($config['mode']??null,['marketing_scene','marketing_reference'],true);
            if($marketing){
                require_once __DIR__.'/initiative-marketing-creative-v1.php';
                $resolveSource='initiative_marketing_creative_job_source_v1';
            }else{
                require_once __DIR__.'/initiative-work-screen-v1.php';
                $resolveSource='initiative_work_screen_job_source_v1';
            }
            $resolveSource($pdo,$user,$row,$config);
        }
        $inputTokens=max(1,(int)ceil(mb_strlen($config['prompt'])/3.5))+($edit&&($config['mode']??'')!=='marketing_scene'?4096:0);
        try{
            $ticket=vp3_ai_service_usage_begin_v1($pdo,$user,'image_generation',$target['provider'],$target['model'],$funding,$inputTokens,16000,[
                'agent_id'=>(int)$row['agent_id'],'conversation_id'=>(int)$row['conversation_id'],'run_id'=>(int)$row['run_id'],
                'stage'=>$edit&&($config['mode']??'')!=='marketing_scene'?'edit':'generate','attempt_key'=>'image:'.$row['generation_id'],
                'job_tokens'=>$policy['job_tokens'],'daily_tokens'=>$policy['daily_tokens'],'monthly_tokens'=>$policy['monthly_tokens'],
                'billing_scope'=>'image_generation','required'=>$policy['configured']||$funding==='own_key',
            ]);
        }catch(RuntimeException $e){
            throw new RuntimeException(match($e->getCode()){402=>'service_budget_exceeded',403=>'service_policy_denied',409=>'provider_outcome_unconfirmed',503=>'service_usage_unavailable',default=>'quota_preflight_failed'},0,$e);
        }
        // The image endpoint has no request parameter to apply a reduced token allowance.
        if(!empty($ticket['metered'])){
            if((int)$ticket['max_output_tokens']<16000)throw new RuntimeException('service_budget_exceeded');
        }elseif($funding==='system'){
            $quota=subscription_ai_preflight($user,'image_generation',$inputTokens,16000);$reservation=(int)$quota['reservation_id'];if((int)$quota['max_output_tokens']<16000)throw new RuntimeException('subscription_required');
        }else{
            throw new RuntimeException('service_usage_unavailable');
        }
        $user=vp3_image_authority_v1($pdo,$user,$row);if(!agent_worker_cloud_active_row_v1910($pdo,$user,$claim))throw new RuntimeException('execution_cancelled');
        // Commit the dispatch marker while account funding and credentials are stable.
        $pdo->beginTransaction();
        vp3_ai_service_usage_lock_owner_v1($pdo,(int)$user['id']);
        if($edit){
            vp3_image_run_v1($pdo,$user,$row,true);
            vp3_image_lock_conversation_v1($pdo,(int)$user['id'],(int)$row['conversation_id'],(int)$row['agent_id']);
            $sourceImage=$resolveSource($pdo,$user,$row,$config,true,true);
        }
        if(!agent_worker_cloud_active_row_v1910($pdo,$user,$claim,true))throw new RuntimeException('execution_cancelled');
        $route=vp3_image_route_v1($pdo,$user,$config);
        $q=$pdo->prepare("UPDATE agent_image_generations_v1 SET provider_started_at=? WHERE generation_id=? AND owner_user_id=? AND provider_started_at=''");$q->execute([gmdate('Y-m-d H:i:s'),$row['generation_id'],(int)$user['id']]);if($q->rowCount()!==1)throw new RuntimeException('provider_outcome_unconfirmed');
        $pdo->commit();
        $sent=true;$startedAt=microtime(true);
        $output=vp3_image_provider_v1($config,$user,$transport,$sourceImage,$route['api_key'],static function(array $usage)use(&$reportedUsage):void{$reportedUsage=$usage;});
        // Record actual paid usage even if the owner cancels while the provider runs.
        $settleUsage('completed');
        $user=vp3_image_authority_v1($pdo,$user,$row);if(!agent_worker_cloud_active_row_v1910($pdo,$user,$claim))throw new RuntimeException('execution_cancelled');
        if($edit)$resolveSource($pdo,$user,$row,$config);
        $root=media_studio_ensure_user_root((int)$user['id']);$base=realpath(media_studio_private_root());if(!$base||realpath($root)!==$base.DIRECTORY_SEPARATOR.(int)$user['id'])throw new RuntimeException('image_storage_unavailable');
        $file=$root.'/generated-'.$row['generation_id'].'.png';$handle=@fopen($file,'x+b');if(!$handle)throw new RuntimeException('image_storage_unavailable');$createdFile=true;
        try{if(fwrite($handle,$output['bytes'])!==strlen($output['bytes']))throw new RuntimeException('image_storage_unavailable');}finally{fclose($handle);}@chmod($file,0600);
        $pdo->beginTransaction();
        if($marketing)vp3_image_lock_owner_v1($pdo,(int)$user['id']);
        vp3_image_run_v1($pdo,$user,$row,true);$user=vp3_image_authority_v1($pdo,$user,$row);if(!agent_worker_cloud_active_row_v1910($pdo,$user,$claim,$edit))throw new RuntimeException('execution_cancelled');
        if($edit){
            vp3_image_lock_conversation_v1($pdo,(int)$user['id'],(int)$row['conversation_id'],(int)$row['agent_id']);
            $resolveSource($pdo,$user,$row,$config,true);
        }
        $meta=['generation_id'=>$row['generation_id'],'model'=>$target['model'],'provider'=>$target['provider'],'funding'=>$funding,'prompt'=>$config['prompt'],'size'=>$config['size'],'quality'=>$config['quality'],'background'=>$config['background'],'sha256'=>$output['sha256'],'width'=>$output['width'],'height'=>$output['height'],'usage'=>$output['usage']];
        if($edit)$meta['edit_mode']=$config['mode'];
        if($marketing)$meta['marketing_variant_id']=$config['marketing']['id'];
        $relative='private/user-media/'.(int)$user['id'].'/'.basename($file);
        $pdo->prepare("INSERT INTO user_media_assets(user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,metadata_json,created_at,updated_at) VALUES(?,'photo',?,'image/png',?,?,0,'openai_image',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([(int)$user['id'],$config['title'],$relative,strlen($output['bytes']),json_encode($meta,JSON_THROW_ON_ERROR)]);$asset=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE agent_image_generations_v1 SET asset_id=?,error_code=\'\' WHERE generation_id=? AND owner_user_id=?')->execute([$asset,$row['generation_id'],(int)$user['id']]);
        $receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,true,'Requested image saved in the private media library.',['asset_id'=>$asset,'generation_id'=>$row['generation_id'],'effect_state'=>'image_saved'],'',false);$pdo->commit();$committed=true;
        if($marketing){
            try{
                require_once __DIR__.'/initiative-product-launch-v1.php';
                initiative_product_launch_wake_v1($pdo,(int)$user['id'],(int)$config['marketing']['product_id'],$config['marketing']['id']);
            }catch(Throwable $ignored){} // The persisted launch remains eligible for the regular sweep.
        }
        $priorScope=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;
        try{
            if(function_exists('vp3_agent_memory_scope_set_current_v410'))vp3_agent_memory_scope_set_current_v410((int)$user['id'],(int)$row['agent_id']);
            if(function_exists('vp3_cognitive_media_event_v2390'))vp3_cognitive_media_event_v2390($pdo,(int)$user['id'],$asset,'photo','openai_image');
            if(function_exists('agent_brain_store_memory')&&personal_capability_has_v242('agent_brain.access',$user))agent_brain_store_memory($user,'file','Media asset #'.$asset,'Saved generated image "'.$config['title'].'" in the private media library.',0,.95,['asset_id'=>$asset,'media_type'=>'photo','source'=>'openai_image']);
        }catch(Throwable $ignored){}finally{if($priorScope===null)unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);else $GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$priorScope;}
        return ['ok'=>true,'reason'=>'completed','receipt'=>$receipt];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $code=in_array($e->getMessage(),[
            'provider_access_denied','provider_rate_limited','provider_request_rejected','provider_outcome_unconfirmed',
            'provider_image_unconfirmed','provider_usage_unconfirmed','image_too_large','image_storage_unavailable',
            'subscription_required','provider_unavailable','provider_route_changed','service_policy_denied','service_budget_exceeded',
            'service_usage_unavailable','quota_preflight_failed','execution_cancelled','image_source_changed','marketing_source_changed',
        ],true)?$e->getMessage():($sent?'provider_outcome_unconfirmed':'quota_preflight_failed');
        if($edit&&$e instanceof DomainException)$code=$e->getCode()===503?'provider_unavailable':($marketing?'marketing_source_changed':'image_source_changed');
        $providerRejected=in_array($code,['provider_access_denied','provider_rate_limited','provider_request_rejected'],true);
        $knownFailure=$providerRejected||in_array($code,['image_source_changed','marketing_source_changed'],true);
        try{$settleUsage(!$sent?'aborted':($reportedUsage!==[]||$knownFailure?'failed':'unknown'),$sent&&$providerRejected&&$reportedUsage===[]);}
        catch(Throwable $ignored){$code='service_usage_unavailable';}
        $pdo->prepare('UPDATE agent_image_generations_v1 SET error_code=? WHERE generation_id=? AND owner_user_id=?')->execute([$code,$row['generation_id'],(int)$user['id']]);
        $receipt=null;if(agent_worker_cloud_active_row_v1910($pdo,$user,$claim))$receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,false,'Image result was not confirmed; no automatic provider replay.',['effect_state'=>'unconfirmed'],$code,false);
        return ['ok'=>false,'reason'=>$code,'receipt'=>$receipt];
    }finally{if($reservation>0)subscription_ai_release_reservation($reservation);if(!$committed&&$createdFile&&$file!==''&&is_file($file))@unlink($file);}
}

function vp3_image_tick_v1(PDO $pdo,array $user,array $row,?callable $transport=null): array
{
    $user=vp3_image_authority_v1($pdo,$user,$row);vp3_image_run_v1($pdo,$user,$row);agent_job_recover_expired_v1900($pdo,$user,25);
    $claim=agent_job_claim_run_v1900($pdo,$user,(int)$row['run_id'],'cloud',agent_worker_cloud_worker_id_v1910((int)$user['id']),300);if($claim)vp3_image_execute_v1($pdo,$user,$claim,$transport);
    return vp3_image_view_v1($pdo,$user,vp3_image_row_v1($pdo,$user,$row['generation_id']));
}

function vp3_cloud_media_read_v1(PDO $pdo,array $user,array $principal,array $plan,array $context): array
{
    try{
    $user=vp3_image_scope_v1($pdo,$user,$principal,$context);if(!media_studio_schema_ready())return ['handled'=>true,'answer'=>'The private media library needs its database upgrade.','tool_receipt'=>vp3_tool_receipt_v1('media.assets.read','search','blocked','upgrade_required')];
    $where='user_id=?';$params=[(int)$user['id']];if($plan['media_type']!==''){$where.=' AND media_type=?';$params[]=$plan['media_type'];}
    if(($plan['view']??'all')==='generated')$where.=" AND source='openai_image'";
    elseif(($plan['view']??'all')==='uploaded')$where.=" AND COALESCE(source,'')<>'openai_image'";
    $fields = ['title'=>'title'];
    $json = "CASE WHEN JSON_VALID(metadata_json) THEN metadata_json ELSE '{}' END";
    foreach (['caption'=>'caption','tags'=>'tags','generation prompt'=>'prompt','original filename'=>'original_name'] as $label=>$key) {
        $fields[$label] = "JSON_EXTRACT(($json), '$.$key')";
    }
    $query = mb_strtolower($plan['query']);
    if ($query !== '') {
        $pattern = '%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';
        $clauses = array_fill(0,count($fields),$pattern);
        $matches = array_map(static fn(string $field): string => "LOWER($field) LIKE ? ESCAPE '!'", $fields);
        if (table_exists('agent_commerce_products_v800')) {
            $matches[] = "EXISTS (SELECT 1 FROM agent_commerce_products_v800 p
                WHERE p.owner_user_id=user_media_assets.user_id
                AND CAST(JSON_EXTRACT(CASE WHEN JSON_VALID(p.metadata_json) THEN p.metadata_json ELSE '{}' END,'$.source_asset_id') AS CHAR)
                    IN (CAST(user_media_assets.id AS CHAR),JSON_QUOTE(CAST(user_media_assets.id AS CHAR)))
                AND LOWER(p.title) LIKE ? ESCAPE '!')";
            $clauses[] = $pattern;
        }
        $where .= ' AND ('.implode(' OR ',$matches).')';
        $params = [...$params,...$clauses];
    }
    $q=$pdo->prepare('SELECT id,title,media_type,source,metadata_json FROM user_media_assets WHERE '.$where.' ORDER BY created_at DESC,id DESC LIMIT 13');$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>12;$rows=array_slice($rows,0,12);vp3_image_scope_v1($pdo,$user,$principal,$context);
    $lines = array_map(static function(array $row) use ($query): string {
        $provenance = match($row['source']) {
            'openai_image'=>'AI-generated',
            'upload'=>'uploaded',
            default=>'saved media',
        };
        $reason = '';
        if ($query !== '') {
            $meta = json_decode((string)$row['metadata_json'],true);
            $reason = 'linked product title';
            $values = ['title'=>$row['title'],'caption'=>$meta['caption']??null,'tags'=>$meta['tags']??null,
                'generation prompt'=>$meta['prompt']??null,'original filename'=>$meta['original_name']??null];
            foreach ($values as $label=>$value) {
                $value = is_array($value) ? json_encode($value,JSON_UNESCAPED_UNICODE) : $value;
                if (is_string($value) && str_contains(mb_strtolower($value),$query)) {
                    $reason = $label;
                    break;
                }
            }
            $reason = ' · matched '.$reason;
        }
        return '#'.$row['id'].' '.mb_substr($row['title'],0,190).' · '.$row['media_type'].' · '.$provenance.$reason;
    },$rows);
    return ['handled'=>true,'answer'=>$rows?"Your private media library results (saved metadata):\n".implode("\n",$lines).($more?"\nMore results exist; narrow the search.":''):'The private media search completed with no matching saved assets.','actions'=>[],'sources'=>[['source'=>'cloud:private-media','title'=>'Your private media library']],'canvas_media'=>['asset_ids'=>array_map(static fn($r)=>(int)$r['id'],$rows),'generation_ids'=>[]],'tool_receipt'=>vp3_tool_receipt_v1('media.assets.read','search',$rows?'read_complete':'no_matches','',0,count($rows))];
    }catch(Throwable $e){$denied=$e->getCode()===403;return ['handled'=>true,'answer'=>$denied?'This conversation no longer permits the private media read.':'The private media search could not be verified. This is not an empty library result.','actions'=>[],'sources'=>[],'tool_receipt'=>vp3_tool_receipt_v1('media.assets.read','search',$denied?'blocked':'failed',$denied?'permission_denied':'execution_failed')];}
}
