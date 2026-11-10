<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-v1.php';
require_once __DIR__.'/agent-initiative-actions-v1.php';
require_once __DIR__.'/agent-initiative-engagement-feedback-v1.php';

/** One transaction only: canonical plain source reads hold shared locks until
 * the saved decision/answer commits, including legacy source writers. */
function vp3_initiative_followthrough_begin_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Follow-through transaction is already active.',409);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $pdo->beginTransaction();
}

/** Retry only a rolled-back serialization/deadlock once. Callers must supply
 * database writes/rechecks only, with already-computed model output. */
function vp3_initiative_followthrough_transaction_v1(PDO $pdo,callable $write): mixed
{
    if($pdo->inTransaction())throw new RuntimeException('Follow-through transaction is already active.',409);
    for($attempt=0;$attempt<2;$attempt++){
        try{
            vp3_initiative_followthrough_begin_v1($pdo);
            $value=$write();$pdo->commit();return $value;
        }catch(Throwable $error){
            if($pdo->inTransaction())$pdo->rollBack();
            $retry=$error instanceof PDOException&&((int)($error->errorInfo[1]??0)===1213||(string)$error->getCode()==='40001');
            if($attempt===0&&$retry)continue;
            throw $error;
        }
    }
    throw new RuntimeException('Follow-through transaction could not be verified.',503);
}

function vp3_initiative_followthrough_id_v1(mixed $value): int
{
    if(is_int($value)&&$value>0)return $value;
    if(is_string($value)&&preg_match('/\A[1-9][0-9]*\z/D',$value)&&filter_var($value,FILTER_VALIDATE_INT)!==false)return (int)$value;
    return 0;
}

function vp3_initiative_followthrough_states_v1(): array
{
    return ['open','discussed','queued','deferred','declined','owner_resolved','completed','stale'];
}

function vp3_initiative_followthrough_ref_v1(int $owner,int $agent,int $cid,int $run,int $message,string $text,array $context,string $goalHash): string
{
    return hash('sha256',json_encode(['initiative-followthrough-v1',$owner,$agent,$cid,$run,$message,$text,$goalHash,
        $context['sources']??[],$context['initiative_work']['review_actions']??[],
        $context['tool_receipt']??null,$context['initiative_output']??null],JSON_THROW_ON_ERROR));
}

/** Called by the canonical report writer in its existing transaction. The
 * completed action/receipt is independently required before a reply can bind. */
function vp3_initiative_followthrough_attach_v1(PDO $pdo,array $user,int $agent,int $cid,int $run,int $message,string $text,array $context): array
{
    $q=$pdo->prepare('SELECT goal FROM agent_workflow_runs WHERE id=? AND owner_user_id=?');
    $q->execute([$run,(int)$user['id']]);$goal=$q->fetchColumn();
    if(!is_string($goal))throw new RuntimeException('Initiative proposal is unavailable.',409);
    $hash=hash('sha256',$goal);
    $decoded=json_decode($goal,true,32,JSON_THROW_ON_ERROR);
    if(isset($decoded['followthrough'])){
        $root=vp3_initiative_followthrough_job_current_v1($pdo,$user,$decoded);
        if($root!==null)$context['initiative_followthrough']=vp3_initiative_followthrough_outcome_v1($root);
    }
    $ref=vp3_initiative_followthrough_ref_v1((int)$user['id'],$agent,$cid,$run,$message,$text,$context,$hash);
    $state=['version'=>1,'reply_ref'=>$ref,'revision'=>1,'state'=>'open','goal_hash'=>$hash,
        'last_reply_message_id'=>0,'last_reply_hash'=>'','last_reply_explicit'=>false,'last_intent'=>'','focus'=>'','continuation_run_id'=>0,
        'continuation_revision'=>0,'continuation_reply_message_id'=>0,'continuation_reply_hash'=>'','updated_at'=>gmdate(DATE_ATOM)];
    $context['initiative_followthrough_state']=$state;
    $context['initiative_work']['followthrough']=['reply_ref'=>$ref,'revision'=>1,'state'=>'open'];
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=? AND conversation_id=? AND role=\'assistant\'')
        ->execute([json_encode($context,JSON_THROW_ON_ERROR),$message,$cid]);
    return $context;
}

/** Exact current source reads, independent of whether autonomous work is paused.
 * A reply never restores a revoked Knowledge/screen grant or executes a tool. */
function vp3_initiative_followthrough_sources_v1(PDO $pdo,array $user,array $principal,array $goal,array $result): array
{
    if(isset($goal['followup'])){require_once __DIR__.'/agent-initiative-followups-v1.php';vp3_initiative_followup_job_current_v1($pdo,$user,$goal);}
    $agent=(int)$principal['agent_id'];$old=$goal['binding'];$policy=vp3_initiative_policy_v1($user,$agent);
    $scope=['user'=>$user,'principal'=>$principal,'agent_id'=>$agent,'conversation_id'=>$old['conversation_id']];
    $screen=!empty($old['screen_enabled'])&&!empty($policy['screen_enabled'])&&$agent===0;
    require_once __DIR__.'/agent-initiative-capabilities-v1.php';
    $basic=[];$reviews=[];$capabilitySources=[];
    $capabilityMap=vp3_initiative_capability_saved_source_map_v1($goal);
    foreach($goal['sources'] as $ref=>$revision){
        if(!is_string($ref)||!is_string($revision)||!preg_match('/\A[a-f0-9]{64}\z/D',$revision))throw new RuntimeException('Proposal sources changed.',409);
        if(str_starts_with($ref,'workbench-'))$reviews[$ref]=$revision;
        elseif(isset($capabilityMap[$ref]))$capabilitySources[$ref]=$revision;
        else $basic[$ref]=$revision;
    }
    $evidence=$basic?vp3_initiative_current_sources_v1($pdo,$user,$basic,$screen,$goal['canonical_sources']??[],$agent):[];
    if($capabilitySources){
        $readonly=[...$scope,'verification_only'=>true];$coverage=[];
        $current=vp3_initiative_capabilities_evidence_v1($pdo,$readonly,$old,new DateTimeImmutable('now',new DateTimeZone('UTC')),array_keys($capabilitySources),$coverage);
        foreach($capabilitySources as $ref=>$revision){
            if(($current[$ref]['revision']??null)!==$revision)throw new RuntimeException('Proposal capability source changed.',409);
            $evidence[$ref]=$current[$ref];
        }
    }
    require_once __DIR__.'/agent-initiative-tools-v1.php';
    foreach($reviews as $ref=>$revision){
        if(!preg_match('/\Aworkbench-(product|screen|mission|marketing):(.+)\z/D',$ref,$match))throw new RuntimeException('Proposal source changed.',409);
        $lane=$match[1];$id=$match[2];
        if($lane==='screen'&&!$screen)throw new RuntimeException('Screen source permission changed.',403);
        require_once __DIR__.'/initiative-work-'.$lane.'-v1.php';
        if($lane==='screen'&&!in_array($id,vp3_initiative_tools_screen_ids_v1($pdo,$scope,[$id]),true))throw new RuntimeException('Screen source changed.',409);
        $input=['source_id'=>$id]+($lane==='screen'?['mode'=>'cleanup','instruction'=>'']:[]);
        if($lane==='marketing'){
            if(!preg_match('/\A([1-9][0-9]{0,18})-([1-9][0-9]{0,18})\z/D',$id,$pair)
                ||($productId=vp3_initiative_followthrough_id_v1($pair[1]))<1)throw new RuntimeException('Proposal source changed.',409);
            $input=['source_id'=>$pair[2],'product_id'=>$productId];
        }
        try{$preview=('initiative_work_'.$lane.'_preview_v1')($pdo,(int)$user['id'],$scope,$input);}
        catch(DomainException $error){
            if($lane!=='marketing')throw $error;
            if($error->getCode()===503)throw new RuntimeException('Proposal source verification is unavailable.',503,$error);
            throw new RuntimeException('Proposal source changed.',409,$error);
        }
        if(!hash_equals($revision,(string)$preview['revision'])||($lane==='marketing'&&empty($preview['details']['linked_product_image']))){
            throw new RuntimeException('Proposal source changed.',409);
        }
        $evidence[$ref]=['ref'=>$ref,'revision'=>$revision,'kind'=>'workbench_review','title'=>$preview['source_title'],
            'detail'=>'An existing source awaits its original explicit review.','url'=>'/chat.php?agent='.$agent.'&conversation_id='.$old['conversation_id']];
    }
    $tool=$goal['tool']??'work.prepare_private';$saved=$result['initiative_tool_result']??[];
    $output=$saved['output']??null;
    if(!vp3_initiative_capability_definition_v1((string)$tool)&&!in_array($tool,['work.prepare_private','chat.check_in','brain.read','knowledge.search','missions.prepare','canvas.prepare',
        'workbench.product.review','workbench.marketing.review','workbench.screen.review','workbench.mission.review'],true))throw new RuntimeException('Proposal tool evidence is unavailable.',503);
    if(vp3_initiative_capability_definition_v1((string)$tool)){
        vp3_initiative_capability_result_current_v1($pdo,[...$scope,'verification_only'=>true],$old,$goal,$saved);
    }elseif($tool==='canvas.prepare'){
        // Discussing or declining a saved proposal grants no build authority.
        // Current source grants were checked above; do not call the execution
        // validator here, which also reads this decision history.
        require_once __DIR__.'/agent-initiative-canvas-v1.php';
        $normalized=vp3_initiative_canvas_tool_result_v1($saved);
        if($normalized===null||($normalized['output']??null)!==$output
            ||($output['source_ref']??null)!==($goal['primary']??null)
            ||($output['source_revision']??null)!==($goal['sources'][$goal['primary']]??null)){
            throw new RuntimeException('Canvas proposal evidence changed.',409);
        }
        $q=$pdo->prepare("SELECT goal,agent_id FROM agent_workflow_runs WHERE id=? AND owner_user_id=?
            AND source_kind='agent_initiative' AND capability_key='initiative.prepare' AND status='completed'");
        $q->execute([$output['parent_run_id'],(int)$user['id']]);$parent=$q->fetch(PDO::FETCH_ASSOC);
        if(!$parent||(int)($parent['agent_id']??0)!==$agent
            ||json_decode((string)$parent['goal'],true,32,JSON_THROW_ON_ERROR)!==$goal){
            throw new RuntimeException('Canvas proposal origin changed.',409);
        }
    }elseif(in_array($tool,['brain.read','knowledge.search'],true)){
        if(!is_array($output)||($output['type']??'')!=='initiative_record_read'||($output['tool']??'')!==$tool
            ||!is_array($output['source_revisions']??null))throw new RuntimeException('Proposal read evidence changed.',409);
        if($tool==='knowledge.search'&&vp3_initiative_knowledge_scope_v1($old['knowledge_scope']??[])!==vp3_initiative_knowledge_scope_v1($policy['knowledge_scope']??[])){
            throw new RuntimeException('Knowledge permission changed.',403);
        }
        $records=vp3_initiative_tools_records_v1($pdo,$scope,$old,$tool,(string)($goal['focus']??''));
        $current=vp3_initiative_tools_record_revisions_v1($records);$expected=$output['source_revisions'];ksort($current);ksort($expected);
        if($current!==$expected||($records?'read_complete':'no_matches')!==($output['status']??''))throw new RuntimeException('Proposal read sources changed.',409);
    }elseif($tool==='missions.prepare'){
        if(!is_array($output)||($output['type']??'')!=='initiative_specialist_review'||($output['status']??'')!=='approval_pending'
            ||!is_string($output['mission_id']??null))throw new RuntimeException('Proposal review changed.',409);
        require_once __DIR__.'/cloud-missions-v1.php';
        $mission=cloud_mission_row_v1($pdo,$user,$output['mission_id']);cloud_mission_authority_v1($pdo,$user,$mission);
        $config=json_decode($mission['config_json'],true,16,JSON_THROW_ON_ERROR);$run=cloud_mission_run_v1($pdo,$user,$mission);
        if((int)$mission['agent_id']!==$agent||($config['thread_id']??null)!==$scope['conversation_id']
            ||(int)$run['id']!==($output['job_id']??null)||$run['status']!=='approval_pending'||empty($run['requires_approval'])
            ||!preg_match('/\Ainitiative-[a-f0-9]{64}\z/D',(string)$mission['request_id']))throw new RuntimeException('Proposal review changed.',409);
    }elseif($tool==='chat.check_in'){
        // The delivery tail fence remains unchanged. A new owner reply is
        // expected here and must not be mistaken for a stale undelivered draft.
        if(!is_array($output)||($output['type']??'')!=='initiative_chat_check_in'
            ||($output['agent_id']??null)!==$agent||($output['conversation_id']??null)!==$scope['conversation_id']){
            throw new RuntimeException('Proposal check-in binding changed.',409);
        }
    }elseif(str_starts_with($tool,'workbench.')){
        $lane=vp3_initiative_tools_workbench_v1($tool);$action=$output['review_action']??null;
        if(!is_array($output)||($output['type']??'')!=='initiative_workbench_review'||($output['status']??'')!=='review_required'
            ||!is_array($action)||($action['tool']??'')!==$lane||($goal['primary']??'')!=='workbench-'.$lane.':'.($action['source_id']??'')
            ||($evidence[$goal['primary']]['revision']??null)!==($action['source_revision']??null))throw new RuntimeException('Proposal review evidence changed.',409);
    }
    return $evidence;
}

/** Internal loader. It returns private context only after exact live authority,
 * immutable anchor, canonical completion/receipt and source checks. */
function vp3_initiative_followthrough_origin_load_v1(PDO $pdo,array $user,array $principal,int $cid,int $messageId,bool $forUpdate=false): array
{
    if($cid<1||$messageId<1||vp3_participant_scope_v1()!==null||!in_array($principal['kind']??'', ['system','user_agent'],true))throw new RuntimeException('Proposal access changed.',403);
    $agent=($principal['kind']??'')==='user_agent'?(int)($principal['agent_id']??0):0;
    if($agent<0||(($principal['kind']??'')==='user_agent'&&($agent<1||($principal['owner_user_id']??null)!==(int)$user['id']))
        ||(($principal['kind']??'')==='system'&&(int)($principal['agent_id']??0)!==0))throw new RuntimeException('Proposal Agent changed.',403);
    return vp3_initiative_in_scope_v1($user,$agent,static function()use($pdo,$user,$principal,$cid,$messageId,$forUpdate,$agent):array{
        $principal=vp3_initiative_principal_v1($pdo,$user,$agent);
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],null);
        if(!has_permission('account.access',$live)||!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],$agent>0?['id'=>$agent]:null,$forUpdate))throw new RuntimeException('Proposal access changed.',403);
        $lock=$forUpdate&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT * FROM chat_messages WHERE id=? AND conversation_id=? LIMIT 1'.$lock);$q->execute([$messageId,$cid]);$message=$q->fetch(PDO::FETCH_ASSOC);
        $context=$message?json_decode((string)$message['context_json'],true,32,JSON_THROW_ON_ERROR):null;
        $marker=$context['initiative_work']??null;$state=$context['initiative_followthrough_state']??null;
        if(!$message||$message['role']!=='assistant'||($context['source']??'')!=='agent_initiative'||!is_array($marker)||!is_array($state)
            ||($marker['owner_user_id']??null)!==(int)$live['id']||($marker['agent_id']??null)!==$agent||($marker['conversation_id']??null)!==$cid
            ||!is_int($marker['run_id']??null)||($context['initiative_run_id']??null)!==$marker['run_id']
            ||($state['version']??null)!==1||!is_int($state['revision']??null)||$state['revision']<1
            ||!in_array($state['state']??'',vp3_initiative_followthrough_states_v1(),true)
            ||!is_string($state['reply_ref']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$state['reply_ref'])
            ||!is_string($state['goal_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$state['goal_hash'])
            ||!is_int($state['last_reply_message_id']??null)||$state['last_reply_message_id']<0
            ||!is_string($state['last_reply_hash']??null)||($state['last_reply_hash']!==''&&!preg_match('/\A[a-f0-9]{64}\z/D',$state['last_reply_hash']))
            ||(isset($state['active_review_slot'])&&!in_array($state['active_review_slot'],['action_run_id','followup_run_id'],true))
            ||(isset($state['followup_run_id'])&&(!is_int($state['followup_run_id'])||$state['followup_run_id']<0))
            ||(isset($state['followup_reminder_id'])&&(!is_int($state['followup_reminder_id'])||$state['followup_reminder_id']<0))
            ||(isset($state['followup_clarification'])&&(!is_string($state['followup_clarification'])||mb_strlen($state['followup_clarification'])>500))
            ||!is_bool($state['last_reply_explicit']??null)||!in_array($state['last_intent']??null,['','none','discuss','advance','defer','decline','resolve'],true)
            ||(isset($state['interest_confirmed'])&&!is_bool($state['interest_confirmed']))
            ||!is_string($state['focus']??null)||mb_strlen($state['focus'])>300
            ||!is_int($state['continuation_run_id']??null)||$state['continuation_run_id']<0
            ||!is_int($state['continuation_revision']??null)||$state['continuation_revision']<0
            ||!is_int($state['continuation_reply_message_id']??null)||$state['continuation_reply_message_id']<0
            ||!is_string($state['continuation_reply_hash']??null)||($state['continuation_reply_hash']!==''&&!preg_match('/\A[a-f0-9]{64}\z/D',$state['continuation_reply_hash']))
            ||($marker['followthrough']??null)!==array_intersect_key($state,array_flip(['reply_ref','revision','state']))){
            throw new RuntimeException('Proposal binding changed.',409);
        }
        $run=agent_workflow_row_v1400($pdo,(int)$live['id'],$marker['run_id'],$forUpdate);
        if(!$run||$run['source_kind']!=='agent_initiative'||$run['status']!=='completed'||$run['capability_key']!=='initiative.prepare'
            ||($run['agent_id']===null?0:(int)$run['agent_id'])!==$agent)throw new RuntimeException('Proposal completion changed.',409);
        $goal=json_decode((string)$run['goal'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($goal)||!is_array($goal['binding']??null)||!is_array($goal['sources']??null)||!$goal['sources']||count($goal['sources'])>5
            ||($goal['binding']['conversation_id']??null)!==$cid||vp3_initiative_binding_agent_v1($goal['binding'])!==$agent
            ||isset($goal['binding']['owner_user_id'])&&$goal['binding']['owner_user_id']!==(int)$live['id']
            ||!hash_equals((string)($state['goal_hash']??''),hash('sha256',(string)$run['goal']))){
            throw new RuntimeException('Proposal source binding changed.',409);
        }
        $ref=vp3_initiative_followthrough_ref_v1((int)$live['id'],$agent,$cid,(int)$run['id'],$messageId,(string)$message['message'],$context,$state['goal_hash']);
        if(!hash_equals($ref,$state['reply_ref']))throw new RuntimeException('Proposal content changed.',409);
        $q=$pdo->prepare("SELECT a.*,p.id AS receipt_id,p.result_json AS receipt_result FROM agent_workflow_actions a
            JOIN agent_workflow_receipts p ON p.run_id=a.run_id AND p.action_id=a.id AND p.owner_user_id=a.owner_user_id
            WHERE a.run_id=? AND a.owner_user_id=? AND a.status='completed' AND a.capability_key='initiative.prepare'
                AND p.status='completed' ORDER BY p.id DESC LIMIT 1".$lock);
        $q->execute([(int)$run['id'],(int)$live['id']]);$action=$q->fetch(PDO::FETCH_ASSOC);
        $result=$action?json_decode((string)$action['result_json'],true,32,JSON_THROW_ON_ERROR):null;
        $receipt=$action?json_decode((string)$action['receipt_result'],true,32,JSON_THROW_ON_ERROR):null;
        if(!is_array($result)||$result!==$receipt||($result['effect_state']??'')!=='private_draft_ready'
            ||($result['initiative_delivery']??'')!=='delivered'||vp3_initiative_followthrough_id_v1($result['initiative_message_id']??null)!==$messageId
            ||($result['initiative_draft']??null)!==(string)$message['message'])throw new RuntimeException('Proposal receipt changed.',409);
        $displayed=agent_workflow_initiative_result_v1400(['output'=>$context['initiative_output']??null,'tool_receipt'=>$context['tool_receipt']??null]);
        if(($displayed['output']??null)!==($result['initiative_tool_result']['output']??null)
            ||($displayed['tool_receipt']??null)!==($result['initiative_tool_result']['tool_receipt']??null))throw new RuntimeException('Proposal displayed evidence no longer matches its receipt.',409);
        return ['user'=>$live,'principal'=>$principal,'agent_id'=>$agent,'conversation_id'=>$cid,'message'=>$message,'context'=>$context,
            'run'=>$run,'goal'=>$goal,'action'=>$action,'result'=>$result,'state'=>$state];
    });
}

/** Ordinary interactions retain every current-source, child and result fence.
 * Historical follow-up origin verification alone cannot execute or deliver. */
function vp3_initiative_followthrough_load_v1(PDO $pdo,array $user,array $principal,int $cid,int $messageId,bool $forUpdate=false): array
{
    $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$cid,$messageId,$forUpdate);
    return vp3_initiative_in_scope_v1($origin['user'],$origin['agent_id'],static function()use($pdo,$origin,$messageId,$forUpdate):array{
        ['user'=>$live,'principal'=>$principal,'agent_id'=>$agent,'conversation_id'=>$cid,'message'=>$message,'context'=>$context,
            'run'=>$run,'goal'=>$goal,'action'=>$action,'result'=>$result,'state'=>$state]=$origin;
        $lock=$forUpdate&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
        $child=null;$childResult=null;
        if(in_array($state['state'],['queued','completed'],true)||($state['continuation_run_id']??0)>0){
            $childId=$state['continuation_run_id']??null;
            if(!is_int($childId)||$childId<1)throw new RuntimeException('Private continuation evidence changed.',409);
            $child=agent_workflow_row_v1400($pdo,(int)$live['id'],$childId,$forUpdate);
            $childGoal=$child?json_decode((string)$child['goal'],true,32,JSON_THROW_ON_ERROR):null;
            $relation=$childGoal['followthrough']??null;
            if(!$child||$child['source_kind']!=='agent_initiative'||$child['capability_key']!=='initiative.prepare'
                ||($child['agent_id']===null?0:(int)$child['agent_id'])!==$agent||!is_array($relation)
                ||($relation['message_id']??null)!==$messageId||($relation['reply_ref']??null)!==$state['reply_ref']
                ||($relation['revision']??null)!==($state['continuation_revision']??null)||($relation['reply_message_id']??null)!==($state['continuation_reply_message_id']??null)
                ||($relation['reply_hash']??null)!==($state['continuation_reply_hash']??null)
                ||($relation['root_run_id']??null)!==(int)$run['id']||($childGoal['sources']??null)!==$goal['sources']
                ||($childGoal['tool']??null)!==vp3_initiative_followthrough_continuation_tool_v1($goal)||($childGoal['binding']['conversation_id']??null)!==$cid){
                throw new RuntimeException('Private continuation binding changed.',409);
            }
            if($child['status']==='failed'){
                $q=$pdo->prepare("SELECT a.result_json,a.error_class,a.attempt_count,p.result_json AS receipt_result,p.error_class AS receipt_error,p.attempt_no FROM agent_workflow_actions a
                    JOIN agent_workflow_receipts p ON p.action_id=a.id AND p.run_id=a.run_id AND p.owner_user_id=a.owner_user_id
                    WHERE a.run_id=? AND a.owner_user_id=? AND a.status='failed' AND p.status='failed'
                        AND a.capability_key='initiative.prepare' ORDER BY p.id DESC LIMIT 1".$lock);
                $q->execute([$childId,(int)$live['id']]);$failure=$q->fetch(PDO::FETCH_ASSOC);
                if(!$failure||!is_string($failure['error_class'])||$failure['error_class']===''||strlen($failure['error_class'])>80
                    ||$failure['error_class']!==$failure['receipt_error']||$failure['error_class']!==$child['last_error_class']
                    ||(int)$failure['attempt_count']!==(int)$failure['attempt_no']||(int)$failure['attempt_no']<1)throw new RuntimeException('Private continuation failure proof changed.',409);
                if($failure['result_json']!==null||$failure['receipt_result']!==null){
                    try{$failedResult=json_decode((string)$failure['result_json'],true,32,JSON_THROW_ON_ERROR);$failedReceipt=json_decode((string)$failure['receipt_result'],true,32,JSON_THROW_ON_ERROR);}
                    catch(Throwable $error){throw new RuntimeException('Private continuation failure proof changed.',409);}
                    if(!is_array($failedResult)||!$failedResult||$failedResult!==$failedReceipt
                        ||($failedResult['effect_state']??'')!=='tool_result_withheld'||($failedResult['initiative_delivery']??'')!=='withheld')throw new RuntimeException('Private continuation failure proof changed.',409);
                }
            }
            if($state['state']==='completed'||$child['status']==='completed'){
                $q=$pdo->prepare("SELECT a.result_json,p.result_json AS receipt_result FROM agent_workflow_actions a
                    JOIN agent_workflow_receipts p ON p.action_id=a.id AND p.run_id=a.run_id AND p.owner_user_id=a.owner_user_id
                    WHERE a.run_id=? AND a.owner_user_id=? AND a.status='completed' AND p.status='completed'
                        AND a.capability_key='initiative.prepare' ORDER BY p.id DESC LIMIT 1".$lock);
                $q->execute([$childId,(int)$live['id']]);$proof=$q->fetch(PDO::FETCH_ASSOC);
                $childResult=$proof?json_decode((string)$proof['result_json'],true,32,JSON_THROW_ON_ERROR):null;
                if($child['status']!=='completed'||!is_array($childResult)
                    ||$childResult!==json_decode((string)($proof['receipt_result']??''),true,32,JSON_THROW_ON_ERROR)
                    ||($childResult['effect_state']??'')!=='private_draft_ready')throw new RuntimeException('Private continuation receipt changed.',409);
            }
        }
        $loaded=['user'=>$live,'principal'=>$principal,'agent_id'=>$agent,'conversation_id'=>$cid,'message'=>$message,'context'=>$context,
            'run'=>$run,'goal'=>$goal,'action'=>$action,'result'=>$result,'state'=>$state,'continuation'=>$child,'continuation_result'=>$childResult];
        $domainAction=vp3_initiative_action_load_v1($pdo,$loaded);
        if($domainAction!==null&&$domainAction['result']!==null){
            require_once __DIR__.'/agent-initiative-action-sources-v1.php';
            $evidence=vp3_initiative_action_sources_current_v1($pdo,$loaded,$domainAction['goal']['plan'],
                $domainAction['result']['domain_result'],$domainAction['result']['source_transition']);
        }else $evidence=vp3_initiative_followthrough_sources_v1($pdo,$live,$principal,$goal,$result);
        $followupCopy=$loaded;$followupCopy['state']['action_run_id']=$state['followup_run_id']??0;
        $followupAction=vp3_initiative_action_load_v1($pdo,$followupCopy);
        if($followupAction!==null&&$followupAction['result']!==null){
            require_once __DIR__.'/agent-initiative-followup-origin-v1.php';
            vp3_initiative_followup_sources_current_v1($pdo,$loaded+['evidence'=>$evidence],$followupAction['goal']['plan'],
                $followupAction['result']['domain_result'],$followupAction['result']['source_transition']);
        }
        return $loaded+['evidence'=>$evidence,'domain_action'=>$domainAction,'followup_action'=>$followupAction];
    });
}

function vp3_initiative_followthrough_outcome_v1(array $loaded): array
{
    $s=$loaded['state'];
    return ['owner_user_id'=>(int)$loaded['user']['id'],'agent_id'=>$loaded['agent_id'],'conversation_id'=>$loaded['conversation_id'],
        'message_id'=>(int)$loaded['message']['id'],'run_id'=>(int)$loaded['run']['id'],'reply_ref'=>$s['reply_ref'],
        'revision'=>$s['revision'],'state'=>$s['state'],'reply_message_id'=>(int)$s['last_reply_message_id'],
        'continuation_run_id'=>(int)$s['continuation_run_id']];
}

/** Refresh only explicitly requested existing proposal metadata through the
 * ordinary Chat activity read. Negative rows reveal no object existence. */
function vp3_initiative_followthrough_updates_v1(PDO $pdo,array $user,array $principal,int $cid,array $messageIds,?callable $authority=null): array
{
    if(!array_is_list($messageIds)||count($messageIds)>12)throw new RuntimeException('Invalid proposal update selection.',422);
    $ids=[];
    foreach($messageIds as $id){
        if(!is_int($id)||$id<1||isset($ids[$id]))throw new RuntimeException('Invalid proposal update selection.',422);
        $ids[$id]=true;
    }
    if($authority)$authority();
    if(vp3_participant_scope_v1()!==null||!in_array($principal['kind']??'', ['system','user_agent'],true))throw new RuntimeException('Private proposal updates are unavailable.',403);
    $aid=($principal['kind']??'')==='user_agent'?(int)($principal['agent_id']??0):0;
    if((($principal['kind']??'')==='user_agent'&&($aid<1||($principal['owner_user_id']??null)!==(int)$user['id']))
        ||(($principal['kind']??'')==='system'&&(int)($principal['agent_id']??0)!==0))throw new RuntimeException('Private proposal updates are unavailable.',403);
    $canonical=vp3_initiative_principal_v1($pdo,$user,$aid);
    $live=vp3_capability_live_user_v1($pdo,$user,$canonical,['conversation_id'=>$cid],null);
    if($cid<1||!has_permission('account.access',$live)||!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],$aid>0?['id'=>$aid]:null))throw new RuntimeException('Private proposal updates are unavailable.',403);
    $updates=[];
    foreach(array_keys($ids) as $id){
        if($authority)$authority();
        try{
            $post=vp3_initiative_followthrough_load_v1($pdo,$live,$canonical,$cid,$id);
            $status=$post['continuation']['status']??'';
            $updates[]=['message_id'=>$id,'available'=>true,'initiative_followthrough'=>vp3_initiative_followthrough_outcome_v1($post),
                'recoverable'=>in_array($post['state']['state'],['queued','stale'],true)&&in_array($status,['failed','cancelled'],true),
                'child_execution_state'=>$status];
        }catch(Throwable $error){$updates[]=['message_id'=>$id,'available'=>false];}
    }
    if($authority)$authority();
    vp3_capability_live_user_v1($pdo,$live,$canonical,['conversation_id'=>$cid],null);
    if(vp3_participant_scope_v1()!==null)throw new RuntimeException('Private proposal updates are unavailable.',403);
    return $updates;
}

function vp3_initiative_followthrough_store_v1(PDO $pdo,array &$loaded,array $state): array
{
    $context=$loaded['context'];$context['initiative_followthrough_state']=$state;
    $context['initiative_work']['followthrough']=array_intersect_key($state,array_flip(['reply_ref','revision','state']));
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=? AND conversation_id=?')
        ->execute([json_encode($context,JSON_THROW_ON_ERROR),(int)$loaded['message']['id'],$loaded['conversation_id']]);
    $loaded['context']=$context;$loaded['state']=$state;
    return vp3_initiative_followthrough_outcome_v1($loaded);
}

function vp3_initiative_followthrough_current_v1(PDO $pdo,array $user,array $principal,int $cid,array $outcome,?callable $authority=null): array
{
    if($authority)$authority();
    $loaded=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,$cid,vp3_initiative_followthrough_id_v1($outcome['message_id']??null),$pdo->inTransaction());
    $current=vp3_initiative_followthrough_outcome_v1($loaded);
    if($current!==$outcome)throw new RuntimeException('Proposal response changed.',409);
    $reply=(int)$current['reply_message_id'];
    if($reply>0){
        $lock=$pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT id,user_id,role,message,context_json FROM chat_messages WHERE conversation_id=? AND id>=? ORDER BY id LIMIT 65'.$lock);$q->execute([$cid,$reply]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$ownerReply=array_shift($rows);
        if(!$ownerReply||(int)$ownerReply['id']!==$reply||$ownerReply['role']!=='user'||(int)$ownerReply['user_id']!==(int)$user['id']
            ||!hash_equals((string)($loaded['state']['last_reply_hash']??''),hash('sha256',(string)$ownerReply['message']))||count($rows)>=64){
            throw new RuntimeException('Proposal reply changed.',409);
        }
        foreach($rows as $row){
            $context=json_decode((string)($row['context_json']??''),true);
            if($row['role']!=='assistant'||($context['initiative_followthrough']??null)!==$outcome)throw new RuntimeException('The conversation advanced after this proposal reply.',409);
        }
    }
    $domainAction=($loaded['state']['active_review_slot']??'action_run_id')==='followup_run_id'?($loaded['followup_action']??null):($loaded['domain_action']??null);
    $followupStatus=null;
    if($domainAction!==null&&$domainAction['result']!==null&&str_starts_with($domainAction['goal']['plan']['adapter'],'proposal.revisit.')){
        $verified=vp3_initiative_followup_action_inspect_v1($pdo,$loaded['user'],$loaded['principal'],$domainAction['goal']['plan']['origin'],$domainAction['goal']['plan'],$domainAction['result']['domain_result']);$followupStatus=$verified['resources'][0]??null;
    }
    return ['kind'=>'initiative_followthrough','proposal'=>$current,
        'followup_status'=>$followupStatus,
        'verified_action'=>$domainAction!==null?vp3_initiative_action_preview_v1($domainAction):null,
        'domain_result'=>$domainAction['result']['domain_result']??null,
        'followup_clarification'=>$loaded['state']['followup_clarification']??'',
        'proposal_text'=>mb_substr((string)$loaded['message']['message'],0,2400),
        'verified_sources'=>array_map(static fn(array $row):array=>['ref'=>$row['ref'],'title'=>mb_substr((string)$row['title'],0,170),
            'detail'=>mb_substr((string)($row['detail']??''),0,400)],array_values($loaded['evidence'])),
        'intent'=>$loaded['state']['last_intent'],'review_actions'=>$loaded['context']['initiative_work']['review_actions']??[],
        'continuation_status'=>$loaded['continuation']['status']??'',
        'child_execution_state'=>$loaded['continuation']['status']??'',
        'private_completion'=>$loaded['continuation_result']!==null?['kind'=>'private_preparation',
            'draft'=>mb_substr((string)($loaded['continuation_result']['initiative_draft']??''),0,1600)]:null,
        'continuation_blocked'=>$loaded['state']['last_intent']==='advance'&&$current['continuation_run_id']===0
            &&!array_key_exists('interest_confirmed',$loaded['state'])?'initiative_paused_or_not_current':'',
        'deferred_without_schedule'=>$current['state']==='deferred',
        'focus'=>$loaded['state']['focus'],'private_work_only'=>$domainAction===null||$domainAction['result']===null,'requires_domain_review'=>$domainAction===null||$domainAction['run']['status']==='approval_pending',
        'verified_private_completion'=>$loaded['continuation_result']!==null,'owner_reported_resolution'=>$current['state']==='owner_resolved']
        +(array_key_exists('interest_confirmed',$loaded['state'])?['interest_feedback'=>[
            'confirmed'=>$loaded['state']['interest_confirmed'],'work_started'=>false]]:[]);
}

/** Only a receipt-verified completion of this identical owner decision may
 * refresh a natural acknowledgment once. New decisions/turns never qualify. */
function vp3_initiative_followthrough_refresh_v1(PDO $pdo,array $user,array $principal,int $cid,array $outcome,?callable $authority=null): ?array
{
    if(($outcome['state']??null)!=='queued')return null;
    if($authority)$authority();
    $loaded=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,$cid,vp3_initiative_followthrough_id_v1($outcome['message_id']??null));
    $current=vp3_initiative_followthrough_outcome_v1($loaded);
    if($current!==array_replace($outcome,['state'=>'completed']))return null;
    vp3_initiative_followthrough_current_v1($pdo,$user,$principal,$cid,$current,$authority);
    return $current;
}

function vp3_initiative_followthrough_replay_v1(PDO $pdo,array $user,array $principal,array $input,array $cached): void
{
    if(!array_key_exists('initiative_reply',$input)&&!array_key_exists('initiative_followthrough',$cached))return;
    $outcome=$cached['initiative_followthrough']??null;
    if(!is_array($outcome))throw new RuntimeException('Proposal replay is unavailable.',409);
    $loaded=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,(int)($outcome['conversation_id']??0),vp3_initiative_followthrough_id_v1($outcome['message_id']??null));
    if(($loaded['state']['last_reply_explicit']??false)!==array_key_exists('initiative_reply',$input))throw new RuntimeException('Proposal replay association changed.',409);
    if(array_key_exists('initiative_reply',$input)){
        $ref=$input['initiative_reply'];
        if(!is_array($ref)||($ref['message_id']??null)!==($outcome['message_id']??null)||($ref['reply_ref']??null)!==($outcome['reply_ref']??null)
            ||!is_int($ref['revision']??null)||$ref['revision']!==($outcome['revision']??0)-1)throw new RuntimeException('Proposal replay changed.',409);
    }
    if(vp3_initiative_followthrough_id_v1($input['conversation_id']??null)!==($outcome['conversation_id']??null)
        ||($cached['conversation_id']??null)!==($outcome['conversation_id']??null)||($cached['agent_id']??null)!==($outcome['agent_id']??null))throw new RuntimeException('Proposal replay scope changed.',403);
    $q=$pdo->prepare("SELECT message FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
    $q->execute([(int)($outcome['reply_message_id']??0),(int)$outcome['conversation_id'],(int)$user['id']]);$text=$q->fetchColumn();
    if(!is_string($input['message']??null)||!is_string($text)||!hash_equals($text,trim($input['message'])))throw new RuntimeException('Proposal replay request changed.',409);
    vp3_initiative_followthrough_current_v1($pdo,$user,$principal,(int)$outcome['conversation_id'],$outcome);
}

function vp3_initiative_followthrough_short_v1(string $query): bool
{
    return preg_match('/\A\s*(?:actually[,\s]+)?(?:yes|yeah|yep|sure|ok(?:ay)?|please|do it|build it|go ahead|continue|retry|later|not now|no(?: thanks)?|done|already done|tell me more)[.!?\s]*\z/iu',$query)===1;
}

function vp3_initiative_followthrough_referential_v1(string $query): bool
{
    return vp3_initiative_followthrough_short_v1($query)
        ||preg_match('/\b(?:that|this suggestion|these suggestions|your (?:idea|suggestion|proposal)|the proposal|you (?:mentioned|suggested)|tell me more|what do you mean|follow[ -]?up)\b|\A\s*(?:yes|yeah|yep|sure|okay|go ahead)\b/iu',$query)===1;
}

function vp3_initiative_followthrough_checkin_marker_v1(?array $context): bool
{
    return ($context['source']??null)==='agent_initiative'
        &&($context['initiative_output']['type']??null)==='initiative_chat_check_in'
        &&is_array($context['initiative_work']['followthrough']??null);
}

/** A current typed Canvas proposal can receive an ordinary conversational reply.
 * This is association only; the governed interpreter still decides the intent. */
function vp3_initiative_followthrough_canvas_marker_v1(?array $context): bool
{
    return ($context['source']??null)==='agent_initiative'
        &&($context['initiative_output']['type']??null)==='initiative_canvas'
        &&($context['initiative_output']['status']??null)==='proposal_ready'
        &&is_array($context['initiative_work']['followthrough']??null);
}

/** Cheap eligibility hint for the existing authenticated and verified request preflight. */
function vp3_initiative_followthrough_possible_v1(PDO $pdo,int $owner,int $agentId,int $cid,string $query): bool
{
    if(vp3_initiative_followthrough_referential_v1($query))return true;
    if($owner<1||$agentId<0||$cid<1||trim($query)==='')return false;
    [$agentScope,$agentParams]=vp3_agent_chat_scope_sql_v380($agentId>0?['id'=>$agentId]:null,'c');
    try{
        $q=$pdo->prepare("SELECT m.role,m.message,m.context_json FROM chat_messages m
            JOIN chat_conversations c ON c.id=m.conversation_id
            WHERE c.id=? AND c.user_id=? AND $agentScope ORDER BY m.id DESC LIMIT 2");
        $q->execute(array_merge([$cid,$owner],$agentParams));$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    }catch(PDOException $error){return false;}
    $first=$rows[0]??[];$second=$rows[1]??[];
    $firstContext=json_decode((string)($first['context_json']??''),true);
    $secondContext=json_decode((string)($second['context_json']??''),true);
    $firstContext=is_array($firstContext)?$firstContext:null;$secondContext=is_array($secondContext)?$secondContext:null;
    if(($first['role']??'')==='assistant'){
        if(vp3_initiative_followthrough_checkin_marker_v1($firstContext)||vp3_initiative_followthrough_canvas_marker_v1($firstContext))return true;
        return is_array($firstContext['initiative_followthrough']??null)&&($second['role']??'')==='user'
            &&($second['message']??null)===$query&&is_array($secondContext['initiative_reply_anchor_v1']??null);
    }
    return ($first['role']??'')==='user'&&($first['message']??null)===$query
        &&(is_array($firstContext['initiative_reply_anchor_v1']??null)||(($second['role']??'')==='assistant'
            &&(vp3_initiative_followthrough_checkin_marker_v1($secondContext)||vp3_initiative_followthrough_canvas_marker_v1($secondContext))));
}

/** Resolve only canonical adjacency, without interpreting source prose. This
 * is shared by request idempotency preflight and the saved-turn resolver. */
function vp3_initiative_followthrough_candidate_v1(PDO $pdo,array $user,array $principal,int $cid,int $beforeId,string $query): ?array
{
    $naturalCheckIn=false;$naturalCanvas=false;
    if(!vp3_initiative_followthrough_referential_v1($query)){
        $q=$pdo->prepare('SELECT role,context_json FROM chat_messages WHERE conversation_id=?'.($beforeId>0?' AND id<?':'').' ORDER BY id DESC LIMIT 1');$q->execute($beforeId>0?[$cid,$beforeId]:[$cid]);$last=$q->fetch(PDO::FETCH_ASSOC);
        $context=$last?json_decode((string)$last['context_json'],true):null;
        if(($last['role']??null)!=='assistant')return null;
        if(($context['initiative_followup_clarification']??null)!==true){
            $naturalCheckIn=vp3_initiative_followthrough_checkin_marker_v1(is_array($context)?$context:null);
            $naturalCanvas=vp3_initiative_followthrough_canvas_marker_v1(is_array($context)?$context:null);
            if(!$naturalCheckIn&&!$naturalCanvas)return null;
        }
    }
    $sql='SELECT id,role,context_json,created_at FROM chat_messages WHERE conversation_id=?'.($beforeId>0?' AND id<?':'').' ORDER BY id DESC LIMIT 20';
    $q=$pdo->prepare($sql);$q->execute($beforeId>0?[$cid,$beforeId]:[$cid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $first=$rows[0]??[];$ctx=json_decode((string)($first['context_json']??''),true);$marker=$ctx['initiative_work']['followthrough']??null;
    if(($first['role']??'')!=='assistant')return null;
    $acknowledgment=null;$ref=null;
    if(($ctx['source']??'')!=='agent_initiative'){
        $acknowledgment=$ctx['initiative_followthrough']??null;
        if(!is_array($acknowledgment)||($acknowledgment['owner_user_id']??null)!==(int)$user['id']
            ||($acknowledgment['agent_id']??null)!==(int)($principal['agent_id']??0)||($acknowledgment['conversation_id']??null)!==$cid
            ||!is_int($acknowledgment['message_id']??null)||!is_int($acknowledgment['revision']??null)||!is_string($acknowledgment['reply_ref']??null))return null;
        $ref=array_intersect_key($acknowledgment,array_flip(['message_id','reply_ref','revision']));
    }elseif(!is_array($marker))return null;
    $ambiguous=false;$adjacent=0;
    foreach($rows as $row){
        $c=json_decode((string)($row['context_json']??''),true);
        if($row['role']==='assistant'&&($c['source']??'')==='agent_initiative'){$adjacent++;continue;}
        if($row['role']==='assistant'&&is_array($c)){
            $ambiguous=!empty($c['task_context']['pending_token'])||!empty($c['navigation_offer'])||!empty($c['system_app_plan'])
                ||($c['tool_receipt']['status']??'')==='clarification_required';
        }
        break;
    }
    if($ref===null)$ref=['message_id'=>(int)$first['id'],'reply_ref'=>$marker['reply_ref']??'','revision'=>$marker['revision']??0];
    $checkIn=vp3_initiative_followthrough_checkin_marker_v1(is_array($ctx)?$ctx:null);
    if($naturalCheckIn&&!$checkIn)return null;
    if($checkIn||$naturalCanvas){
        $posted=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)($first['created_at']??''),new DateTimeZone('UTC'));
        if(!$posted||$posted->format('Y-m-d H:i:s')!==$first['created_at'])return null;
        $age=time()-$posted->getTimestamp();
        if($age<0||$age>86400)return null;
        try{$loaded=vp3_initiative_followthrough_selected_v1($pdo,$user,$principal,$cid,$ref,null);}
        catch(Throwable $error){return null;}
        $expectedTool=$naturalCanvas?'canvas.prepare':'chat.check_in';
        if(($loaded['goal']['tool']??null)!==$expectedTool||(($naturalCheckIn||$naturalCanvas)&&($loaded['state']['state']??null)!=='open'))return null;
    }
    if($acknowledgment===null&&($adjacent>1||$ambiguous))return vp3_initiative_followthrough_short_v1($query)?['clarification_required'=>true]:null;
    return ['initiative_reply'=>$ref,'acknowledgment'=>$acknowledgment];
}

function vp3_initiative_followthrough_selected_v1(PDO $pdo,array $user,array $principal,int $cid,array $ref,?array $acknowledgment=null,bool $forUpdate=false): array
{
    $loaded=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,$cid,vp3_initiative_followthrough_id_v1($ref['message_id']??null),$forUpdate);
    if($acknowledgment!==null){
        $expected=vp3_initiative_followthrough_outcome_v1($loaded);
        if($acknowledgment!==$expected&&!(($acknowledgment['state']??'')==='queued'&&$expected===array_replace($acknowledgment,['state'=>'completed'])))throw new RuntimeException('The prior proposal reply changed.',409);
    }
    if($loaded['state']['reply_ref']!==($ref['reply_ref']??null)||$loaded['state']['revision']!==($ref['revision']??null)
        ||!in_array($loaded['state']['state'],['open','discussed','deferred','queued','completed','stale'],true))throw new RuntimeException('Proposal state changed.',409);
    return $loaded;
}

/** A saved request anchor is server authority for request identity only. It
 * grants no action, source access or permission and must be freshly rechecked. */
function vp3_initiative_followthrough_anchor_identity_v1(PDO $pdo,array $user,array $principal,int $cid,int $userMessageId,?callable $authority=null): ?array
{
    if($authority)$authority();
    $q=$pdo->prepare("SELECT id,user_id,role,message,context_json FROM chat_messages WHERE id=? AND conversation_id=?");$q->execute([$userMessageId,$cid]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||$row['role']!=='user'||(int)$row['user_id']!==(int)$user['id'])throw new RuntimeException('Saved proposal request changed.',403);
    $context=json_decode((string)($row['context_json']??''),true);$anchor=$context['initiative_reply_anchor_v1']??null;
    if($anchor===null)return null;
    $aid=($principal['kind']??'')==='user_agent'?(int)($principal['agent_id']??0):0;$ref=$anchor['initiative_reply']??null;
    if(!is_array($anchor)||($anchor['version']??null)!==1||($anchor['owner_user_id']??null)!==(int)$user['id']
        ||($anchor['agent_id']??null)!==$aid||($anchor['conversation_id']??null)!==$cid||($anchor['user_message_id']??null)!==$userMessageId
        ||!is_bool($anchor['explicit']??null)||!is_string($anchor['query_hash']??null)||!hash_equals(hash('sha256',(string)$row['message']),$anchor['query_hash'])
        ||!is_array($ref)||count($ref)!==3||!is_int($ref['message_id']??null)||$ref['message_id']<1||$ref['message_id']>=$userMessageId
        ||!is_int($ref['revision']??null)||$ref['revision']<1||!is_string($ref['reply_ref']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$ref['reply_ref']))throw new RuntimeException('Saved proposal request binding changed.',409);
    $loaded=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,$cid,$ref['message_id'],$pdo->inTransaction());$state=$loaded['state'];
    if(!hash_equals($state['reply_ref'],$ref['reply_ref'])||!($state['revision']===$ref['revision']
        ||($state['revision']===$ref['revision']+1&&$state['last_reply_message_id']===$userMessageId&&$state['last_reply_hash']===$anchor['query_hash']&&$state['last_reply_explicit']===$anchor['explicit'])))throw new RuntimeException('Saved proposal request was superseded.',409);
    if($authority)$authority();
    return ['anchor'=>$anchor,'loaded'=>$loaded];
}

function vp3_initiative_followthrough_anchor_v1(PDO $pdo,array $user,array $principal,int $cid,int $userMessageId,?callable $authority=null): ?array
{
    $proof=vp3_initiative_followthrough_anchor_identity_v1($pdo,$user,$principal,$cid,$userMessageId,$authority);
    if($proof===null)return null;
    $anchor=$proof['anchor'];$loaded=$proof['loaded'];$state=$loaded['state'];$ref=$anchor['initiative_reply'];
    if($state['revision']===$ref['revision']){
        $lock=$pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT id FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 1'.$lock);$q->execute([$cid]);
        if((int)$q->fetchColumn()!==$userMessageId)throw new RuntimeException('The conversation advanced after the saved request.',409);
    }else{
        vp3_initiative_followthrough_current_v1($pdo,$user,$principal,$cid,vp3_initiative_followthrough_outcome_v1($loaded),$authority);
    }
    if($authority)$authority();
    return $anchor;
}

/** A completed execution can leave its historical queued acknowledgment in
 * history. This narrow reader proves that exact two-row tail without granting
 * authority to produce a new answer or to advance work. */
function vp3_initiative_followthrough_completed_tail_v1(PDO $pdo,array $user,array $principal,int $cid,int $userMessageId,?array $cached=null,?callable $authority=null): ?array
{
    $proof=vp3_initiative_followthrough_anchor_identity_v1($pdo,$user,$principal,$cid,$userMessageId,$authority);
    if($proof===null)return null;
    $anchor=$proof['anchor'];$loaded=$proof['loaded'];$current=vp3_initiative_followthrough_outcome_v1($loaded);
    if($current['state']!=='completed'||$current['revision']!==$anchor['initiative_reply']['revision']+1
        ||$current['reply_message_id']!==$userMessageId||!is_array($loaded['continuation_result']))return null;
    $old=array_replace($current,['state'=>'queued']);
    if($cached!==null&&($cached['initiative_followthrough']??null)!==$old)return null;
    $childId=vp3_initiative_followthrough_id_v1($loaded['continuation_result']['initiative_message_id']??null);
    $q=$pdo->prepare('SELECT id,user_id,role,message,context_json FROM chat_messages WHERE conversation_id=? AND id>? ORDER BY id LIMIT 4');
    $q->execute([$cid,$userMessageId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==($childId>0?2:1))return null;
    $ack=null;$child=null;
    foreach($rows as $row){
        if($row['role']!=='assistant')return null;
        $context=json_decode((string)($row['context_json']??''),true);
        if((int)$row['id']===$childId){
            if(($context['source']??null)!=='agent_initiative'||($context['initiative_run_id']??null)!==$current['continuation_run_id']
                ||($context['initiative_work']['owner_user_id']??null)!==(int)$user['id']||($context['initiative_work']['agent_id']??null)!==$current['agent_id']
                ||($context['initiative_work']['conversation_id']??null)!==$cid||($context['initiative_work']['run_id']??null)!==$current['continuation_run_id']
                ||($context['initiative_followthrough']??null)!==$current
                ||!is_string($loaded['continuation_result']['initiative_draft']??null)
                ||!hash_equals($loaded['continuation_result']['initiative_draft'],(string)$row['message']))return null;
            $child=$row;
        }else{
            if($ack!==null||($context['source']??null)==='agent_initiative'||($context['initiative_followthrough']??null)!==$old)return null;
            if($cached!==null&&(vp3_initiative_followthrough_id_v1($cached['assistant_message_id']??null)!==(int)$row['id']
                ||!is_string($cached['answer']??null)||!hash_equals($cached['answer'],(string)$row['message'])))return null;
            $ack=$row;
        }
    }
    if($ack===null||$childId>0&&($child===null||(int)$child['id']<=(int)$ack['id']))return null;
    if($authority)$authority();
    // Re-read live source/receipt authority after the bounded tail inspection.
    $fresh=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,$cid,$current['message_id']);
    if(vp3_initiative_followthrough_outcome_v1($fresh)!==$current
        ||$fresh['continuation_result']!==$loaded['continuation_result'])throw new RuntimeException('The completed proposal changed during replay.',409);
    if($authority)$authority();
    $q->execute([$cid,$userMessageId]);
    if($q->fetchAll(PDO::FETCH_ASSOC)!==$rows)throw new RuntimeException('The conversation advanced during completed replay.',409);
    return ['anchor'=>$anchor,'outcome'=>$current,'ack'=>$ack];
}

/** Return the historical saved answer with only its verified public completion
 * projection advanced. No history, idempotency record or job is rewritten. */
function vp3_initiative_followthrough_replay_refresh_v1(PDO $pdo,array $user,array $principal,array $input,array $cached,?callable $authority=null): ?array
{
    $old=$cached['initiative_followthrough']??null;
    if(!is_array($old)||($old['state']??null)!=='queued')return null;
    if($authority)$authority();
    $cid=vp3_initiative_followthrough_id_v1($input['conversation_id']??null);
    if($cid<1||$cid!==($old['conversation_id']??null)||($cached['conversation_id']??null)!==$cid
        ||($cached['agent_id']??null)!==($old['agent_id']??null)||($old['owner_user_id']??null)!==(int)$user['id'])throw new RuntimeException('Proposal replay scope changed.',403);
    if(!is_string($input['message']??null))throw new RuntimeException('Proposal replay request changed.',409);
    $mid=vp3_initiative_followthrough_id_v1($cached['user_message_id']??null);
    if($mid<1||$mid!==($old['reply_message_id']??null))throw new RuntimeException('Proposal replay owner turn changed.',409);
    $proof=vp3_initiative_followthrough_completed_tail_v1($pdo,$user,$principal,$cid,$mid,$cached,$authority);
    if($proof===null)return null;
    $anchor=$proof['anchor'];
    if($anchor['explicit']!==array_key_exists('initiative_reply',$input)||!hash_equals($anchor['query_hash'],hash('sha256',trim($input['message']))))throw new RuntimeException('Proposal replay request changed.',409);
    if($anchor['explicit']&&($input['initiative_reply']??null)!==$anchor['initiative_reply'])throw new RuntimeException('Proposal replay reference changed.',409);
    return array_replace($cached,['initiative_followthrough'=>$proof['outcome']]);
}

/** Read-only preflight lets normal implicit replies use the same existing
 * private request CAS as explicitly selected replies. No model or writes. */
function vp3_initiative_followthrough_request_v1(PDO $pdo,array $user,array $principal,int $cid,string $query,?callable $authority=null,?array $initiativeReply=null): ?array
{
    if($authority)$authority();$query=trim($query);
    if($initiativeReply!==null&&(count($initiativeReply)!==3||array_diff(array_keys($initiativeReply),['message_id','reply_ref','revision'])
        ||!is_int($initiativeReply['message_id']??null)||$initiativeReply['message_id']<1||!is_int($initiativeReply['revision']??null)||$initiativeReply['revision']<1
        ||!is_string($initiativeReply['reply_ref']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$initiativeReply['reply_ref'])))throw new RuntimeException('Proposal reference is invalid.',409);
    if($cid<1||vp3_participant_scope_v1()!==null)throw new RuntimeException('Proposal request access changed.',403);
    $aid=($principal['kind']??'')==='user_agent'?(int)($principal['agent_id']??0):0;
    if(!in_array($principal['kind']??'', ['system','user_agent'],true)||(($principal['kind']??'')==='user_agent'&&($aid<1||($principal['owner_user_id']??null)!==(int)$user['id']))
        ||(($principal['kind']??'')==='system'&&(int)($principal['agent_id']??0)!==0))throw new RuntimeException('Proposal request access changed.',403);
    $canonical=vp3_initiative_principal_v1($pdo,$user,$aid);$live=vp3_capability_live_user_v1($pdo,$user,$canonical,['conversation_id'=>$cid],null);
    if(!has_permission('account.access',$live)||!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],$aid>0?['id'=>$aid]:null))throw new RuntimeException('Proposal request access changed.',403);
    $q=$pdo->prepare('SELECT id,user_id,role,message,context_json FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 12');$q->execute([$cid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    // Recover only the latest exact saved turn, optionally followed exclusively
    // by its own canonical associated assistant answer/result.
    $assistantRows=[];
    foreach($rows as $row){
        if($row['role']==='assistant'){$assistantRows[]=$row;continue;}
        if($row['role']!=='user'||(int)$row['user_id']!==(int)$live['id']||!hash_equals((string)$row['message'],$query))break;
        $context=json_decode((string)($row['context_json']??''),true);$raw=$context['initiative_reply_anchor_v1']??null;
        if(!is_array($raw)){
            if(!$assistantRows){
                $ordinary=['version'=>1,'owner_user_id'=>(int)$live['id'],'agent_id'=>$aid,'conversation_id'=>$cid,
                    'user_message_id'=>(int)$row['id'],'query_hash'=>hash('sha256',$query)];
                if($initiativeReply===null&&($context['initiative_reply_unassociated_v1']??null)===$ordinary)break;
                $prior=$initiativeReply!==null?['initiative_reply'=>$initiativeReply,'acknowledgment'=>null]:
                    vp3_initiative_followthrough_candidate_v1($pdo,$live,$canonical,$cid,(int)$row['id'],$query);
                if($prior!==null){
                    if(empty($prior['clarification_required']))vp3_initiative_followthrough_selected_v1($pdo,$live,$canonical,$cid,$prior['initiative_reply'],$prior['acknowledgment']);
                    // A killed process may have committed the owner turn just
                    // before its trusted anchor. Refuse recovery; never insert
                    // a replacement turn or guess ordinary tool authority.
                    throw new RuntimeException('The saved proposal request is incomplete.',409);
                }
            }
            break;
        }
        if($assistantRows){
            $associated=true;
            foreach($assistantRows as $assistant){
                $ctx=json_decode((string)($assistant['context_json']??''),true);$answer=$ctx['initiative_followthrough']??null;
                if(!is_array($answer)||($answer['owner_user_id']??null)!==(int)$live['id']||($answer['agent_id']??null)!==$aid
                    ||($answer['conversation_id']??null)!==$cid||($answer['message_id']??null)!==($raw['initiative_reply']['message_id']??null)
                    ||($answer['reply_ref']??null)!==($raw['initiative_reply']['reply_ref']??null)||($answer['reply_message_id']??null)!==(int)$row['id']){$associated=false;break;}
            }
            // An unrelated assistant/approval is a new ordinary context, not a
            // lost acknowledgment of this old request.
            if(!$associated)break;
        }
        if($initiativeReply!==null){
            if(($raw['explicit']??null)!==true||($raw['initiative_reply']??null)!==$initiativeReply)break;
        }elseif(($raw['explicit']??null)!==false)throw new RuntimeException('Proposal request association changed.',409);
        try{$anchor=vp3_initiative_followthrough_anchor_v1($pdo,$live,$canonical,$cid,(int)$row['id'],$authority);}
        catch(RuntimeException $error){
            if($error->getCode()!==409)throw $error;
            $completed=vp3_initiative_followthrough_completed_tail_v1($pdo,$live,$canonical,$cid,(int)$row['id'],null,$authority);
            if($completed===null)throw $error;
            // Identity only: callers must find the exact successful cached
            // answer and run the dedicated replay reader, or stop with 409.
            return $completed['anchor']+['replay_only'=>true];
        }
        if($assistantRows){
            $loaded=vp3_initiative_followthrough_load_v1($pdo,$live,$canonical,$cid,$anchor['initiative_reply']['message_id']);$outcome=vp3_initiative_followthrough_outcome_v1($loaded);
            if($outcome['reply_message_id']!==(int)$row['id'])break;
            foreach($assistantRows as $assistant){$ctx=json_decode((string)($assistant['context_json']??''),true);if(($ctx['initiative_followthrough']??null)!==$outcome)throw new RuntimeException('The conversation advanced after the saved request.',409);}
            vp3_initiative_followthrough_current_v1($pdo,$live,$canonical,$cid,$outcome,$authority);
        }
        return $anchor;
    }
    if($initiativeReply!==null){$candidate=['initiative_reply'=>$initiativeReply,'acknowledgment'=>null];}
    else{$candidate=vp3_initiative_followthrough_candidate_v1($pdo,$live,$canonical,$cid,0,$query);}
    if($candidate===null||!empty($candidate['clarification_required']))return null;
    $ref=$candidate['initiative_reply'];$loaded=vp3_initiative_followthrough_selected_v1($pdo,$live,$canonical,$cid,$ref,$candidate['acknowledgment']);
    if($authority)$authority();
    return ['version'=>1,'owner_user_id'=>(int)$live['id'],'agent_id'=>$aid,'conversation_id'=>$cid,'user_message_id'=>0,
        'initiative_reply'=>$ref,'query_hash'=>hash('sha256',$query),'explicit'=>$initiativeReply!==null];
}

/** Save immutable request identity before inference, so a killed process can
 * safely reuse the same owner turn after a manual retry. */
function vp3_initiative_followthrough_anchor_store_v1(PDO $pdo,array $loaded,array $principal,int $userMessageId,string $query,array $ref,bool $explicit,?callable $authority): array
{
    return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$loaded,$principal,$userMessageId,$query,$ref,$explicit,$authority):array{
        vp3_initiative_owner_lock_v1($pdo,$loaded['user']);if($authority)$authority();$cid=$loaded['conversation_id'];
        $fresh=vp3_initiative_followthrough_selected_v1($pdo,$loaded['user'],$principal,$cid,$ref,null,true);
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
        $q=$pdo->prepare("SELECT * FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 1".$lock);$q->execute([$cid]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['role']!=='user'||(int)$row['id']!==$userMessageId||(int)$row['user_id']!==(int)$fresh['user']['id']||!hash_equals((string)$row['message'],$query))throw new RuntimeException('A newer owner turn changed this request.',409);
        $anchor=['version'=>1,'owner_user_id'=>(int)$fresh['user']['id'],'agent_id'=>$fresh['agent_id'],'conversation_id'=>$cid,'user_message_id'=>$userMessageId,
            'initiative_reply'=>$ref,'query_hash'=>hash('sha256',$query),'explicit'=>$explicit];
        $context=json_decode((string)($row['context_json']??''),true);if(!is_array($context))$context=[];
        if(isset($context['initiative_reply_anchor_v1'])&&$context['initiative_reply_anchor_v1']!==$anchor)throw new RuntimeException('The saved request identity changed.',409);
        $context['initiative_reply_anchor_v1']=$anchor;
        $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=? AND conversation_id=?')->execute([json_encode($context,JSON_THROW_ON_ERROR),$userMessageId,$cid]);
        return $anchor;
    });
}

/** Resolve a real saved owner turn. A decision grants only private discussion or
 * the existing bounded preparation capability, never domain confirmation. */
/** A continuation retains its exact adapter; Canvas admission separately proves the owner's build request. */
function vp3_initiative_followthrough_continuation_tool_v1(array $goal): string
{
    require_once __DIR__.'/agent-initiative-capabilities-v1.php';
    $tool=$goal['tool']??'work.prepare_private';
    return is_string($tool)&&($tool==='canvas.prepare'||vp3_initiative_capability_definition_v1($tool))?$tool:'work.prepare_private';
}

function vp3_initiative_followthrough_reply_v1(PDO $pdo,array $user,array $principal,int $cid,int $userMessageId,mixed $initiativeReply,string $query,?callable $authority=null,?callable $model=null): array
{
    if($pdo->inTransaction())throw new RuntimeException('Proposal inference cannot hold a transaction.',409);
    if(mb_strlen($query)>6000)throw new RuntimeException('That message is too long.',422);
    if($authority)$authority();
    $explicit=$initiativeReply!==null;$ref=null;$acknowledgment=null;
    $q=$pdo->prepare("SELECT * FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");$q->execute([$userMessageId,$cid,(int)$user['id']]);$reply=$q->fetch(PDO::FETCH_ASSOC);
    if(!$reply||!hash_equals((string)$reply['message'],$query))throw new RuntimeException('Saved owner reply changed.',403);
    if($explicit){
        if(!is_array($initiativeReply)||array_diff(array_keys($initiativeReply),['message_id','reply_ref','revision'])||count($initiativeReply)!==3
            ||!is_int($initiativeReply['message_id']??null)||$initiativeReply['message_id']<1||$initiativeReply['message_id']>=$userMessageId
            ||!is_string($initiativeReply['reply_ref']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$initiativeReply['reply_ref'])
            ||!is_int($initiativeReply['revision']??null)||$initiativeReply['revision']<1)throw new RuntimeException('Proposal reference is invalid.',409);
        $ref=$initiativeReply;
    }else{
        $saved=vp3_initiative_followthrough_anchor_v1($pdo,$user,$principal,$cid,$userMessageId,$authority);
        if($saved!==null){if($saved['explicit'])throw new RuntimeException('Proposal request association changed.',409);$ref=$saved['initiative_reply'];}
        else{
            $candidate=vp3_initiative_followthrough_candidate_v1($pdo,$user,$principal,$cid,$userMessageId,$query);
            if($candidate===null)return ['handled'=>false];
            if(!empty($candidate['clarification_required']))return ['handled'=>true,'clarification_required'=>true,'advisory_context'=>['kind'=>'initiative_followthrough',
                'clarification_required'=>true,'no_action'=>true,'reason'=>'ambiguous_proposal'],'sources'=>[]];
            $ref=$candidate['initiative_reply'];$acknowledgment=$candidate['acknowledgment'];
        }
    }
    $loaded=vp3_initiative_followthrough_selected_v1($pdo,$user,$principal,$cid,$ref,$acknowledgment);
    $anchor=vp3_initiative_followthrough_anchor_store_v1($pdo,$loaded,$principal,$userMessageId,$query,$ref,$explicit,$authority);
    $actionBinding=vp3_initiative_action_binding_v1($loaded,$userMessageId,$query);$actionCatalog=[];$actionResources=[];
    try{
        $actionCatalog=vp3_initiative_action_catalog_v1($pdo,$loaded,$actionBinding);
        if($actionCatalog)$actionResources=vp3_initiative_action_resources_v1($pdo,$loaded,$actionBinding);
    }catch(RuntimeException $error){
        // An unavailable action domain cannot disable ordinary proposal discussion.
        // Selecting an action still fails closed against the live registry below.
        if(!in_array((int)$error->getCode(),[403,409,503],true))throw $error;
        $actionCatalog=[];$actionResources=[];
    }
    $pendingAction=($loaded['state']['active_review_slot']??'action_run_id')==='followup_run_id'?($loaded['followup_action']??null):($loaded['domain_action']??null);
    $primary=$loaded['goal']['primary']??'';
    $interestRef=($loaded['goal']['tool']??null)==='chat.check_in'&&is_string($primary)
        ?vp3_initiative_youtube_interest_ref_v1($primary):null;
    if($interestRef!==null&&($loaded['evidence'][$primary]['interest_ref']??null)!==$interestRef)$interestRef=null;
    $prompt=['response_kind'=>'initiative_followthrough','clock'=>vp3_initiative_action_clock_v1($pdo,$loaded['user']),'action_catalog'=>array_values($actionCatalog),
        'action_resources'=>vp3_initiative_action_prompt_resources_v1($actionResources),'pending_action_review'=>$pendingAction!==null?vp3_initiative_action_preview_v1($pendingAction):null,
        'prior_owner_replies'=>vp3_initiative_followup_prior_replies_v1($pdo,$loaded,$userMessageId),
        'followup_clarification_pending'=>!empty($loaded['state']['followup_clarification']),
        'owner_message'=>['message_id'=>$userMessageId,'text'=>$query],
        'proposal'=>['message_id'=>$ref['message_id'],'run_id'=>(int)$loaded['run']['id'],'state'=>$loaded['state']['state'],
            'revision'=>$ref['revision'],'text'=>mb_substr((string)$loaded['message']['message'],0,2400),
            'source_summaries'=>array_map(static fn(array $row):array=>['ref'=>$row['ref'],'title'=>$row['title']],array_values($loaded['evidence']))],
        'allowed_intents'=>['none','discuss','advance','defer','decline','resolve']];
    if($interestRef!==null)$prompt['interest_check_in']=['source_ref'=>$interestRef,'title'=>$loaded['evidence'][$primary]['title']];
    if(($loaded['goal']['tool']??'')==='canvas.prepare'
        &&($loaded['context']['initiative_output']['type']??'')==='initiative_canvas'){
        $prompt['canvas_proposal']=['status'=>$loaded['context']['initiative_output']['status']??'',
            'permission'=>'Advance only when the owner wants this exact private Canvas draft built. Discussion, passing interest and silence are not build requests. This uses the configured Coding Agent and tokens; it saves a draft, never activates or publishes.'];
    }
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $answer=$model?$model(json_encode($prompt,JSON_THROW_ON_ERROR),'vp3.initiative-reply.v1'):
        vp3_initiative_model_v1($pdo,$loaded['user'],json_encode($prompt,JSON_THROW_ON_ERROR),'vp3.initiative-reply.v1',$loaded['agent_id']);
    $decision=vp3_tool_json_object_v1($answer,['intent','focus','action','action_decision','clarification','interest_confirmed'],16000);
    if(!in_array($decision['intent']??'', $prompt['allowed_intents'],true)||!is_string($decision['focus']??null)||mb_strlen($decision['focus'])>300){throw new InvalidArgumentException('Proposal decision is invalid.');}
    if(vp3_initiative_capability_sources_present_v1($loaded['goal']['sources']??[])){
        // Refresh source-specific remote privacy evidence after a slow model
        // turn, before either short decision/anchor-cleanup transaction.
        $loaded=vp3_initiative_followthrough_selected_v1($pdo,$user,$principal,$cid,$ref,$acknowledgment);
    }
    $clarification=$decision['clarification']??'';
    if(!is_string($clarification)||mb_strlen($clarification)>500||($clarification!==''&&(($decision['intent']??null)!=='discuss'||($decision['action']??null)!==null)))throw new InvalidArgumentException('Invalid follow-up clarification.');
    $intent=$decision['intent'];$focus=trim($decision['focus']);$rawAction=$decision['action']??null;$actionDecision=$decision['action_decision']??'none';
    $interestConfirmed=$decision['interest_confirmed']??false;
    if(array_key_exists('interest_confirmed',$decision)&&($interestRef===null||!is_bool($decision['interest_confirmed'])
        ||($interestConfirmed&&!in_array($intent,['discuss','advance'],true))))throw new InvalidArgumentException('Interest feedback is invalid.');
    if(($rawAction!==null&&(!is_array($rawAction)||!is_string($rawAction['adapter']??null)||!isset($actionCatalog[$rawAction['adapter']])))
        ||!in_array($actionDecision,['none','approve','cancel','revise'],true)||($rawAction!==null&&$intent!=='advance')
        ||($actionDecision==='approve'&&($intent!=='advance'||$rawAction!==null))
        ||($actionDecision==='revise'&&($intent!=='advance'||$rawAction===null))
        ||($rawAction!==null&&!in_array($actionDecision,['none','revise'],true)))throw new InvalidArgumentException('Action decision is invalid.');
    if($intent==='none'&&!$explicit&&!vp3_initiative_followthrough_short_v1($query)){
        vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$loaded,$principal,$cid,$userMessageId,$anchor,$authority):void{
            vp3_initiative_owner_lock_v1($pdo,$loaded['user']);if($authority)$authority();
            vp3_initiative_followthrough_anchor_v1($pdo,$loaded['user'],$principal,$cid,$userMessageId,$authority);
            $q=$pdo->prepare('SELECT context_json FROM chat_messages WHERE id=? AND conversation_id=?');$q->execute([$userMessageId,$cid]);$context=json_decode((string)$q->fetchColumn(),true);
            if(($context['initiative_reply_anchor_v1']??null)!==$anchor)throw new RuntimeException('Saved proposal request changed.',409);
            unset($context['initiative_reply_anchor_v1']);
            $context['initiative_reply_unassociated_v1']=['version'=>1,'owner_user_id'=>(int)$loaded['user']['id'],'agent_id'=>$loaded['agent_id'],
                'conversation_id'=>$cid,'user_message_id'=>$userMessageId,'query_hash'=>$anchor['query_hash']];
            $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=? AND conversation_id=?')->execute([json_encode($context,JSON_THROW_ON_ERROR),$userMessageId,$cid]);
        });
        return ['handled'=>false];
    }
    [$loaded,$outcome,$blocked]=vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$loaded,$principal,$cid,$ref,$userMessageId,$query,$intent,$focus,$user,$explicit,$authority,$rawAction,$actionDecision,$actionResources,$actionBinding,$clarification,$interestRef,$interestConfirmed):array{
        vp3_initiative_owner_lock_v1($pdo,$loaded['user']);
        if($authority)$authority();
        $loaded=vp3_initiative_followthrough_load_v1($pdo,$loaded['user'],$principal,$cid,$ref['message_id'],true);
        if($loaded['state']['reply_ref']!==$ref['reply_ref']||$loaded['state']['revision']!==$ref['revision'])throw new RuntimeException('Proposal state changed.',409);
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
        $q=$pdo->prepare("SELECT id FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 1".$lock);$q->execute([$cid]);
        if((int)$q->fetchColumn()!==$userMessageId)throw new RuntimeException('The conversation advanced during this reply.',409);
        $state=$loaded['state'];$state['followup_clarification']=$clarification;$state['revision']++;$state['last_reply_message_id']=$userMessageId;$state['last_reply_hash']=hash('sha256',$query);$state['last_reply_explicit']=$explicit;$state['last_intent']=$intent;$state['focus']=$focus;$state['updated_at']=gmdate(DATE_ATOM);
        if($interestRef!==null)$state['interest_confirmed']=$interestConfirmed;
        $state['state']=match($intent){'advance'=>'queued','defer'=>'deferred','decline'=>'declined','resolve'=>'owner_resolved',
            default=>in_array($loaded['state']['state'],['queued','completed'],true)?$loaded['state']['state']:'discussed'};
        if($intent!=='advance'&&$state['state']==='queued'&&in_array($loaded['continuation']['status']??'', ['failed','cancelled'],true))$state['state']='stale';
        $blocked='';
        $activeSlot=$loaded['state']['active_review_slot']??'action_run_id';
        $domainAction=$activeSlot==='followup_run_id'?($loaded['followup_action']??null):($loaded['domain_action']??null);$handledAction=false;
        $followupRequest=$rawAction!==null&&str_starts_with($rawAction['adapter'],'proposal.revisit.');
        $actionBinding['review_slot']=$followupRequest?'followup_run_id':'action_run_id';
        if($domainAction!==null&&$domainAction['run']['status']==='approval_pending'){
            $handledAction=true;
            if(!in_array($intent,['decline','resolve','defer'],true))$state['state']='discussed';
            if($actionDecision==='revise'){
                vp3_initiative_action_cancel_v1($pdo,$loaded,$domainAction);
                vp3_initiative_action_prepare_v1($pdo,$loaded,$state,$actionBinding,$rawAction,$actionResources);
            }elseif($rawAction!==null)throw new RuntimeException('Amend this pending review before confirming it.',409);
            elseif($actionDecision==='approve'){
                vp3_initiative_action_reviewed_v1($pdo,$loaded,$domainAction,$userMessageId);
                vp3_initiative_action_apply_v1($pdo,$loaded,$state,$domainAction,$userMessageId,$query,$authority);
            }elseif($actionDecision==='cancel'||in_array($intent,['decline','resolve'],true)){
                vp3_initiative_action_cancel_v1($pdo,$loaded,$domainAction);
            }
        }elseif($rawAction!==null&&$followupRequest){
            if($actionDecision!=='none')throw new RuntimeException('That follow-up review is no longer pending.',409);
            $handledAction=true;vp3_initiative_action_prepare_v1($pdo,$loaded,$state,$actionBinding,$rawAction,$actionResources);
        }elseif($domainAction!==null&&$domainAction['run']['status']==='completed'){
            // A new assessment owns subsequent work. Never reuse an old proposal's
            // pre-action source revisions or replace its committed receipt.
            $handledAction=true;$state['state']='discussed';
            if($rawAction!==null||$actionDecision!=='none')throw new RuntimeException('This proposal already has a completed action.',409);
        }elseif($rawAction!==null){
            if($actionDecision!=='none')throw new RuntimeException('That action review is no longer pending.',409);
            $handledAction=true;
            vp3_initiative_action_prepare_v1($pdo,$loaded,$state,$actionBinding,$rawAction,$actionResources);
        }elseif($actionDecision!=='none')throw new RuntimeException('That action review is no longer pending.',409);
        if($intent==='advance'&&!$handledAction&&$interestRef!==null){
            // An exploratory reply changes the next assessment, without launching competing private work.
            $state['state']='discussed';
        }elseif($intent==='advance'&&!$handledAction){
            if($loaded['state']['state']==='queued'&&!in_array($loaded['continuation']['status']??'', ['failed','cancelled'],true))throw new RuntimeException('This private continuation is already queued.',409);
            $policy=vp3_initiative_policy_v1($loaded['user'],$loaded['agent_id']);
            if(($policy['enabled']??null)!==true||($policy['conversation_id']??null)!==$cid){$blocked='initiative_paused_or_not_current';$state['state']='discussed';}
            else{
                vp3_initiative_access_v1($pdo,$loaded['user'],$policy);
                $state['continuation_revision']=$state['revision'];$state['continuation_reply_message_id']=$userMessageId;$state['continuation_reply_hash']=hash('sha256',$query);
                $work=['binding'=>$policy,'sources'=>$loaded['goal']['sources'],'canonical_sources'=>$loaded['goal']['canonical_sources']??[],
                    'capability_sources'=>vp3_initiative_capability_saved_source_map_v1($loaded['goal']),
                    'tool'=>vp3_initiative_followthrough_continuation_tool_v1($loaded['goal']),'primary'=>$loaded['goal']['primary']??array_key_first($loaded['goal']['sources']),
                    'focus'=>$focus!==''?$focus:'Advance the owner-selected private next step using the verified proposal sources.','report'=>true,
                    'followthrough'=>['message_id'=>$ref['message_id'],'reply_ref'=>$ref['reply_ref'],'revision'=>$state['revision'],
                        'reply_message_id'=>$userMessageId,'reply_hash'=>$state['continuation_reply_hash'],'root_run_id'=>(int)$loaded['run']['id']]];
                $hash=hash('sha256',json_encode(['initiative-followthrough',$ref['message_id'],$ref['reply_ref'],$ref['revision'],$policy['revision']],JSON_THROW_ON_ERROR));
                vp3_initiative_enqueue_v1($pdo,$loaded['user'],'initiative.prepare',$work,$hash);
                $q=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key=?');
                $q->execute([(int)$user['id'],hash('sha256','initiative.prepare|'.$hash)]);$state['continuation_run_id']=(int)$q->fetchColumn();
                if($state['continuation_run_id']<1)throw new RuntimeException('Private continuation could not be verified.',409);
            }
        }
        $outcome=vp3_initiative_followthrough_store_v1($pdo,$loaded,$state);
        agent_workflow_event_v1400($pdo,(int)$user['id'],(int)$loaded['run']['id'],'initiative_owner_decision',$ref['revision']==1?'open':'discussed',$state['state'],'owner','Owner responded to a saved Initiative proposal.',
            ['message_id'=>$ref['message_id'],'reply_message_id'=>$userMessageId,'revision'=>$state['revision'],'intent'=>$intent,
                'continuation_run_id'=>$state['continuation_run_id']]+($interestRef!==null?['interest_confirmed'=>$interestConfirmed]:[]));
        return [$loaded,$outcome,$blocked];
    });
    $advisory=vp3_initiative_followthrough_current_v1($pdo,$loaded['user'],$principal,$cid,$outcome);
    if($blocked!=='')$advisory['continuation_blocked']=$blocked;
    return ['handled'=>true,'advisory_context'=>$advisory,'initiative_followthrough'=>$outcome,
        'sources'=>array_map(static fn(array $row):array=>['source'=>$row['ref'],'title'=>$row['title'],'url'=>$row['url']??''],array_values($loaded['evidence']))];
}

/** Fence continuation generation and delivery against the exact owner decision. */
function vp3_initiative_followthrough_job_current_v1(PDO $pdo,array $user,array $goal,?int $expectedRunId=null): ?array
{
    if(!isset($goal['followthrough']))return null;
    $f=$goal['followthrough'];$binding=$goal['binding'];$aid=vp3_initiative_binding_agent_v1($binding);
    $principal=vp3_initiative_principal_v1($pdo,$user,$aid);
    if(!is_array($f)||!is_int($f['message_id']??null)||!is_int($f['revision']??null)||!is_int($f['reply_message_id']??null))throw new RuntimeException('Continuation binding changed.',409);
    $loaded=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,(int)$binding['conversation_id'],$f['message_id'],$pdo->inTransaction());
    $s=$loaded['state'];
    if($expectedRunId!==null&&$s['continuation_run_id']!==$expectedRunId)throw new RuntimeException('Continuation job changed.',409);
    if($s['reply_ref']!==($f['reply_ref']??null)||($s['continuation_revision']??null)!==$f['revision']||($s['continuation_reply_message_id']??null)!==$f['reply_message_id']
        ||!in_array($s['state'],['queued','completed'],true)||(int)$loaded['run']['id']!==($f['root_run_id']??null))throw new RuntimeException('Continuation decision changed.',409);
    $q=$pdo->prepare("SELECT message FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
    $q->execute([$f['reply_message_id'],(int)$binding['conversation_id'],(int)$user['id']]);$reply=$q->fetchColumn();
    if(!is_string($reply)||!is_string($s['continuation_reply_hash']??null)||!hash_equals($s['continuation_reply_hash'],hash('sha256',$reply))
        ||($f['reply_hash']??null)!==$s['continuation_reply_hash'])throw new RuntimeException('Continuation owner request changed.',409);
    $loaded['continuation_owner_reply']=$reply;
    return $loaded;
}

/** Called only after canonical successful completion, in the same transaction. */
function vp3_initiative_followthrough_complete_v1(PDO $pdo,array $user,array $goal,int $runId): ?array
{
    $loaded=vp3_initiative_followthrough_job_current_v1($pdo,$user,$goal);
    if($loaded===null)return null;
    if($loaded['state']['continuation_run_id']!==$runId)throw new RuntimeException('Continuation job changed.',409);
    $q=$pdo->prepare("SELECT p.id FROM agent_workflow_receipts p JOIN agent_workflow_actions a ON a.id=p.action_id AND a.run_id=p.run_id
        WHERE p.owner_user_id=? AND p.run_id=? AND p.status='completed' AND a.status='completed' AND a.capability_key='initiative.prepare' LIMIT 1");
    $q->execute([(int)$user['id'],$runId]);if(!$q->fetchColumn())throw new RuntimeException('Continuation receipt is unavailable.',409);
    $state=$loaded['state'];$state['state']='completed';$state['updated_at']=gmdate(DATE_ATOM);
    // Preserve the decision revision so a pending delivery still binds to this
    // same successful preparation; no new owner decision occurred.
    $outcome=vp3_initiative_followthrough_store_v1($pdo,$loaded,$state);
    $q=$pdo->prepare("SELECT result_json FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? AND status='completed' LIMIT 1");
    $q->execute([$runId,(int)$user['id']]);$completed=json_decode((string)$q->fetchColumn(),true);
    $messageId=vp3_initiative_followthrough_id_v1($completed['initiative_message_id']??null);
    if($messageId>0){
        $q=$pdo->prepare('SELECT context_json FROM chat_messages WHERE id=? AND conversation_id=?');$q->execute([$messageId,$loaded['conversation_id']]);
        $context=json_decode((string)$q->fetchColumn(),true);
        if(!is_array($context))throw new RuntimeException('Continuation message changed.',409);
        $context['initiative_followthrough']=$outcome;
        $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=? AND conversation_id=?')->execute([json_encode($context,JSON_THROW_ON_ERROR),$messageId,$loaded['conversation_id']]);
    }
    agent_workflow_event_v1400($pdo,(int)$user['id'],(int)$loaded['run']['id'],'initiative_private_completed','queued','completed','executor',
        'The owner-selected private continuation completed with a canonical receipt.',['continuation_run_id'=>$runId,'reply_message_id'=>$state['last_reply_message_id']]);
    return $outcome;
}

/** A terminal canonical private-job failure withdraws its pending-work claim.
 * This writes only identifiers/state; revoked source content is never loaded. */
function vp3_initiative_followthrough_failed_v1(PDO $pdo,array $user,array $goal,int $runId): void
{
    $f=$goal['followthrough']??null;if(!is_array($f))return;
    $aid=vp3_initiative_binding_agent_v1($goal['binding']??[]);$cid=(int)($goal['binding']['conversation_id']??0);
    vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$goal,$runId,$f,$aid,$cid):void{
        vp3_initiative_owner_lock_v1($pdo,$user);
        $principal=vp3_initiative_principal_v1($pdo,$user,$aid);
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],null);
        if(!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],$aid>0?['id'=>$aid]:null,true))throw new RuntimeException('Continuation Chat changed.',403);
        $run=agent_workflow_row_v1400($pdo,(int)$live['id'],$runId,true);
        if(!$run||$run['status']!=='failed'||$run['source_kind']!=='agent_initiative'||$run['capability_key']!=='initiative.prepare'
            ||($run['agent_id']===null?0:(int)$run['agent_id'])!==$aid
            ||json_decode((string)$run['goal'],true)!==$goal)return;
        $q=$pdo->prepare("SELECT p.id FROM agent_workflow_receipts p JOIN agent_workflow_actions a ON a.id=p.action_id AND a.run_id=p.run_id
            WHERE p.owner_user_id=? AND p.run_id=? AND p.status='failed' AND a.status='failed' AND a.capability_key='initiative.prepare' LIMIT 1");
        $q->execute([(int)$live['id'],$runId]);if(!$q->fetchColumn())return;
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
        $q=$pdo->prepare('SELECT message,context_json FROM chat_messages WHERE id=? AND conversation_id=? AND role=\'assistant\''.$lock);$q->execute([(int)($f['message_id']??0),$cid]);
        $message=$q->fetch(PDO::FETCH_ASSOC);$context=$message?json_decode((string)$message['context_json'],true):null;$s=$context['initiative_followthrough_state']??null;
        if(!is_array($s)||($context['source']??'')!=='agent_initiative'||($s['state']??'')!=='queued'
            ||($context['initiative_work']['owner_user_id']??null)!==(int)$live['id']||($context['initiative_work']['agent_id']??null)!==$aid
            ||($context['initiative_work']['conversation_id']??null)!==$cid||($context['initiative_work']['run_id']??null)!==($f['root_run_id']??null)
            ||($s['reply_ref']??null)!==($f['reply_ref']??null)||($s['continuation_run_id']??null)!==$runId
            ||($s['continuation_revision']??null)!==($f['revision']??null)||($s['continuation_reply_message_id']??null)!==($f['reply_message_id']??null))return;
        $root=agent_workflow_row_v1400($pdo,(int)$live['id'],(int)$f['root_run_id'],true);
        if(!$root||!hash_equals((string)($s['goal_hash']??''),hash('sha256',(string)$root['goal']))
            ||!hash_equals($s['reply_ref'],vp3_initiative_followthrough_ref_v1((int)$live['id'],$aid,$cid,(int)$f['root_run_id'],(int)$f['message_id'],(string)$message['message'],$context,$s['goal_hash'])))return;
        $s['revision']++;$s['state']='stale';$s['updated_at']=gmdate(DATE_ATOM);$context['initiative_followthrough_state']=$s;
        $context['initiative_work']['followthrough']=array_intersect_key($s,array_flip(['reply_ref','revision','state']));
        $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=? AND conversation_id=?')->execute([json_encode($context,JSON_THROW_ON_ERROR),(int)$f['message_id'],$cid]);
        agent_workflow_event_v1400($pdo,(int)$live['id'],(int)$f['root_run_id'],'initiative_private_failed','queued','stale','executor',
            'The private continuation ended without a verified result.',['continuation_run_id'=>$runId,'revision'=>$s['revision']]);
    });
}
