<?php
declare(strict_types=1);

require_once __DIR__.'/personal-capabilities-strict-v1.php';

/** Declared domain milestones. Inference selects a supplied reference; it
 * cannot provide code, SQL, a free-form predicate or a completion assertion. */
function vp3_initiative_followup_condition_contract_v1(): array
{
    return [
        'mission_completed'=>['family'=>'mission','description'=>'The exact Cloud Mission run and every action have completed canonical receipts. This does not promise a saved report.'],
    ];
}

function vp3_initiative_followup_condition_hash_v1(mixed $value): string
{
    return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}

function vp3_initiative_followup_condition_scope_v1(PDO $pdo,array $scope): array
{
    return vp3_initiative_action_scope_v1($pdo,$scope['user'],$scope['principal'],[
        'owner_user_id'=>(int)$scope['user']['id'],'agent_id'=>$scope['agent_id'],
        'conversation_id'=>$scope['conversation_id']]);
}

/** Recheck every underlying source grant before returning saved model output.
 * A completed workflow never turns that output into fresh external evidence. */
function vp3_initiative_followup_mission_sources_v1(PDO $pdo,array $scope,array $mission,array $result): void
{
    $user=$scope['user'];$uid=(int)$user['id'];$aid=$scope['agent_id'];
    $policy=vp3_initiative_policy_v1($user,$aid);$knowledge=vp3_initiative_knowledge_scope_v1($policy['knowledge_scope']??null);
    $refs=array_filter(explode(',',(string)($result['source_refs']??'')));
    if(count($refs)>40)throw new RuntimeException('Mission source coverage is unavailable.',503);
    foreach($refs as $ref){
        [$kind,$id]=array_pad(explode(':',$ref,2),2,'');
        if($kind==='brain'){
            if(!personal_capability_has_strict_v1('agent_brain.access',$user))throw new RuntimeException('Mission Brain access changed.',403);
            $resource='brain:'.$id;
        }elseif($kind==='knowledge'){
            if($knowledge['mode']==='off'||!personal_capability_has_strict_v1('personal_knowledge.access',$user))throw new RuntimeException('Mission Knowledge access changed.',403);
            if($knowledge['mode']==='folder'){
                if(knowledge_retrieval_v162_folder($pdo,$uid,$knowledge['folder_id'])===null)throw new RuntimeException('Mission Knowledge folder changed.',403);
                $q=$pdo->prepare('SELECT folder_id FROM knowledge_items WHERE id=?');$q->execute([(int)$id]);$folder=$q->fetch(PDO::FETCH_ASSOC);
                if(!$folder||(int)$folder['folder_id']!==$knowledge['folder_id'])throw new RuntimeException('Mission source is outside the current Knowledge folder.',403);
            }
            $resource=$id;
        }else continue;
        if($aid>0&&(!function_exists('user_data_policy_can_use_v236')
            ||!user_data_policy_can_use_v236($pdo,$scope['principal'],$uid,'knowledge',$resource,false)))throw new RuntimeException('Mission source policy changed.',403);
    }
    cloud_mission_result_authority_v1($pdo,$user,$mission,$result);
}

/** Read one exact Mission, including its current native completion proof. */
function vp3_initiative_followup_mission_target_v1(PDO $pdo,array $scope,string $id): array
{
    require_once __DIR__.'/cloud-missions-v1.php';
    $scope=vp3_initiative_followup_condition_scope_v1($pdo,$scope);$uid=(int)$scope['user']['id'];$aid=$scope['agent_id'];
    if(!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$id))throw new InvalidArgumentException('Choose an exact Mission.');
    $lock=$pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $m=cloud_mission_row_v1($pdo,$scope['user'],$id,$lock);$run=cloud_mission_run_v1($pdo,$scope['user'],$m,$lock);
    $config=json_decode((string)$m['config_json'],true,16,JSON_THROW_ON_ERROR);$cid=$config['thread_id']??null;
    if((int)$m['agent_id']!==$aid||($aid===0?$run['agent_id']!==null:(int)$run['agent_id']!==$aid)
        ||!is_int($cid)||$cid<1||!vp3_agent_chat_conversation_v380($pdo,$cid,$uid,$aid?['id'=>$aid]:null))throw new RuntimeException('Mission belongs to another Agent or Chat.',403);
    cloud_mission_authority_v1($pdo,$scope['user'],$m);
    $identity=['owner_user_id'=>$uid,'agent_id'=>$aid,'mission_id'=>$id,'run_id'=>(int)$run['id'],
        'conversation_id'=>$cid,'request_hash'=>(string)$m['request_hash'],'objective'=>(string)$run['goal'],'config_hash'=>hash('sha256',(string)$m['config_json'])];
    $actions=agent_workflow_actions_v1400($pdo,$uid,(int)$run['id']);
    $expected=$config['assignments']??null;
    if(!is_array($expected)||!array_is_list($expected)||count($expected)!==count($actions)||!$actions||count($actions)>8)throw new RuntimeException('Mission actions are unavailable.',409);
    $proof=[];$output='';
    foreach($actions as $index=>$action){
        if(($expected[$index]['task_id']??null)!==$action['action_key'])throw new RuntimeException('Mission assignment identity changed.',409);
        $raw=(string)($action['result_json']??'');
        if(strlen($raw)>262144)throw new RuntimeException('Mission result exceeds its verified read bound.',503);
        $result=$raw!==''?json_decode($raw,true,32,JSON_THROW_ON_ERROR):[];
        if(!is_array($result))throw new RuntimeException('Mission result is unavailable.',409);
        if($result)vp3_initiative_followup_mission_sources_v1($pdo,$scope,$m,$result);
        if($run['status']!=='completed')continue;
        $q=$pdo->prepare("SELECT id,result_json FROM agent_workflow_receipts WHERE owner_user_id=? AND run_id=? AND action_id=? AND executor='cloud' AND status='completed' ORDER BY id DESC LIMIT 1");
        $q->execute([$uid,(int)$run['id'],(int)$action['id']]);$receipt=$q->fetch(PDO::FETCH_ASSOC);
        if($action['status']!=='completed'||!$receipt||$raw!==$receipt['result_json'])throw new RuntimeException('Mission completion receipt is unavailable.',409);
        $proof[]=['action_id'=>(int)$action['id'],'receipt_id'=>(int)$receipt['id'],'result_hash'=>hash('sha256',$raw)];
        if(is_string($result['mission_output']??null))$output=$result['mission_output'];
    }
    return ['ref'=>'mission:'.$id,'title'=>mb_substr((string)$run['goal'],0,190),'status'=>(string)$run['status'],
        'identity_hash'=>vp3_initiative_followup_condition_hash_v1($identity),'identity'=>$identity,
        'completion_proof'=>$proof,'saved_model_output'=>mb_strcut($output,0,3000,'UTF-8'),
        'output_truncated'=>strlen($output)>3000,'output_freshness'=>'saved_run_output_not_new_research'];
}

/** Returns null while legitimately waiting. Failure/cancellation/replacement
 * remains blocked; it never counts as fulfilling the promised milestone. */
function vp3_initiative_followup_condition_check_v1(PDO $pdo,array $scope,array $condition): ?array
{
    if(array_diff(array_keys($condition),['milestone','target_ref','identity_hash'])||count($condition)!==3
        ||!is_string($condition['milestone']??null)||!is_string($condition['target_ref']??null)
        ||!is_string($condition['identity_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$condition['identity_hash']))throw new InvalidArgumentException('Invalid saved follow-up condition.');
    $definition=vp3_initiative_followup_condition_contract_v1()[$condition['milestone']]??null;
    if(!$definition)throw new InvalidArgumentException('Unsupported follow-up milestone.');
    if($definition['family']==='mission'&&preg_match('/\Amission:([a-f0-9-]{36})\z/D',$condition['target_ref'],$m))$target=vp3_initiative_followup_mission_target_v1($pdo,$scope,$m[1]);
    else throw new InvalidArgumentException('Condition target does not match its milestone.');
    if(!hash_equals($target['identity_hash'],$condition['identity_hash']))throw new RuntimeException('Follow-up prerequisite identity changed.',409);
    if(in_array($target['status'],['failed','cancelled','expired','declined'],true))throw new RuntimeException('Follow-up prerequisite did not reach its promised milestone.',409);
    $ready=$target['status']==='completed';
    if(!$ready)return null;
    $proof=['condition'=>$condition,'native_completion'=>$target['completion_proof']];
    return ['target'=>$target,'proof'=>$proof,'proof_hash'=>vp3_initiative_followup_condition_hash_v1($proof)];
}

/** Bounded discovery contains identities and status only, never private output. */
function vp3_initiative_followup_condition_resources_v1(PDO $pdo,array $user,array $principal,array $binding): array
{
    $scope=vp3_initiative_action_scope_v1($pdo,$user,$principal,$binding);$out=[];$uid=(int)$scope['user']['id'];$aid=$scope['agent_id'];
    if(table_exists('agent_cloud_missions_v1')){
        $q=$pdo->prepare('SELECT mission_id FROM agent_cloud_missions_v1 WHERE owner_user_id=? AND agent_id=? ORDER BY created_at DESC,mission_id LIMIT 21');$q->execute([$uid,$aid]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
            try{$target=vp3_initiative_followup_mission_target_v1($pdo,$scope,$id);}
            catch(RuntimeException $e){if($e instanceof PDOException)throw $e;if(in_array($e->getCode(),[403,404,409,503],true))continue;throw $e;}
            $milestones=['mission_completed'];
            $out[]=array_intersect_key($target,array_flip(['ref','title','status','identity_hash']))+['milestones'=>$milestones];
        }
    }
    return $out;
}

function vp3_initiative_followup_condition_normalize_v1(PDO $pdo,array $scope,mixed $input,array $allowed): array
{
    if(!is_array($input)||count($input)!==2||array_diff(array_keys($input),['milestone','target_ref'])
        ||!is_string($input['milestone']??null)||!is_string($input['target_ref']??null)
        ||!isset(vp3_initiative_followup_condition_contract_v1()[$input['milestone']]))throw new InvalidArgumentException('Choose one exact declared follow-up milestone.');
    $supplied=null;foreach($allowed as $target)if(($target['ref']??null)===$input['target_ref'])$supplied=$target;
    if(!$supplied||isset($supplied['milestones'])&&!in_array($input['milestone'],$supplied['milestones'],true))throw new RuntimeException('Choose a currently supplied follow-up target.',403);
    $condition=['milestone'=>$input['milestone'],'target_ref'=>$input['target_ref'],'identity_hash'=>$supplied['identity_hash']];
    vp3_initiative_followup_condition_check_v1($pdo,$scope,$condition);
    return $condition;
}

/** Shares the real native reminder turn receipt and surrounding action transaction.
 * The optional kernel parameter is server-only; no reminder parser can supply it. */
function vp3_initiative_followup_condition_apply_v1(PDO $pdo,array $scope,array $plan,string $actionId): array
{
    if(!$pdo->inTransaction())throw new LogicException('Conditional commitment requires the owner transaction.');
    $native=$plan['native_plan'];$now=reminder_now_v1();
    $payload=json_encode(['action_id'=>$actionId,'adapter'=>$native['adapter'],'arguments'=>$native['arguments']],JSON_THROW_ON_ERROR);
    $result=reminder_execute_locked_v1($pdo,(int)$scope['user']['id'],'initiative:'.$scope['agent_id'].':'.$scope['conversation_id'],
        'initiative_'.hash('sha256',$actionId),$payload,['operation'=>'create','schedule'=>$native['arguments']['schedule']],[],
        $scope['conversation_id'],$now,'UTC',$plan['condition']);
    $id=(int)$result['tool_receipt']['record_id'];$result['action_id']=$actionId;$result['record_id']=$id;$result['applied_at']=reminder_utc_v1($now);
    $result['effect']=vp3_initiative_schedule_action_effect_v1($pdo,$scope,'reminder',$id,[]);
    $result['effect_hash']=hash('sha256',json_encode($result['effect'],JSON_THROW_ON_ERROR));
    return vp3_initiative_schedule_action_inspect_v1($pdo,$scope['user'],$scope['principal'],$plan['origin'],$native,$result);
}
