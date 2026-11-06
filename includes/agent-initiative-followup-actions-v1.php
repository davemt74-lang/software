<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-schedule-actions-v1.php';
if(!function_exists('reminder_ready_v1'))require_once __DIR__.'/agent-reminders-v1.php';

function vp3_initiative_followup_action_ready_v1(PDO $pdo): bool
{
    if(!function_exists('reminder_execute_locked_v1')||!function_exists('reminder_schedule_v1')||!reminder_ready_v1($pdo)||!column_exists('agent_reminders_v1','followup_action_id'))return false;
    try{
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            $indexes=$pdo->query('PRAGMA index_list(agent_reminders_v1)')->fetchAll(PDO::FETCH_ASSOC);
            foreach($indexes as $index)if($index['name']==='agent_reminders_v1_followup_action'&&(int)$index['unique']===1){
                $cols=$pdo->query('PRAGMA index_info(agent_reminders_v1_followup_action)')->fetchAll(PDO::FETCH_ASSOC);
                return count($cols)===1&&$cols[0]['name']==='followup_action_id';
            }
            return false;
        }
        $q=$pdo->prepare('SELECT COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $q->execute(['agent_reminders_v1','agent_reminders_v1_followup_action']);$cols=$q->fetchAll(PDO::FETCH_ASSOC);
        return count($cols)===1&&$cols[0]['COLUMN_NAME']==='followup_action_id'&&(int)$cols[0]['NON_UNIQUE']===0&&(int)$cols[0]['SEQ_IN_INDEX']===1;
    }catch(Throwable $error){return false;}
}

/** A future revisit needs persistent, authorized record readers. A live camera,
 * microphone sample or arrival cannot silently become a saved capture grant. */
function vp3_initiative_followup_durable_refs_v1(array $binding): bool
{
    $refs=$binding['proposal_source_refs']??null;
    if(!is_array($refs)||!$refs||count($refs)>5)return false;
    foreach($refs as $ref){
        if(!is_string($ref))return false;
        if(preg_match('/\A(?:task|open-task|task-list|reminder):[1-9][0-9]*\z/D',$ref))continue;
        $supported=false;
        foreach(vp3_capability_contract_adapters_v1() as $definition){
            if(($definition['domain']??null)==='live')continue;
            if(vp3_initiative_capability_source_matches_v1($ref,$definition)){$supported=true;break;}
        }
        if(!$supported)return false;
    }
    return true;
}

function vp3_initiative_followup_action_source_authority_v1(array $user,array $binding): void
{
    foreach($binding['proposal_source_refs']??[] as $ref){
        if(is_string($ref)&&preg_match('/\A(?:task|open-task|task-list):/D',$ref)
            &&!personal_capability_has_strict_v1('agent_brain.access',$user))throw new RuntimeException('Follow-up task access changed.',403);
    }
}

function vp3_initiative_followup_action_catalog_v1(PDO $pdo,array $user,array $principal,array $binding): array
{
    vp3_initiative_action_scope_v1($pdo,$user,$principal,$binding);
    if(!vp3_initiative_followup_action_ready_v1($pdo))throw new RuntimeException('Linked follow-ups need the database upgrade.',503);
    if(!vp3_initiative_followup_durable_refs_v1($binding))return [];
    vp3_initiative_followup_action_source_authority_v1($user,$binding);
    $out=[];
    foreach(['create','update','cancel'] as $op)$out[]=['adapter'=>'proposal.revisit.'.$op,'capability'=>'reminders.manage',
        'operation'=>$op,'effect_class'=>'private_record','review_required'=>true,
        'description'=>'Explicitly '.$op.' a one-time main Chat revisit of this exact saved proposal. A plain later/defer is unscheduled. Review exact local date, time, timezone and focus. A revisit only reassesses current sources; it never approves the original action.',
        'arguments'=>$op==='cancel'?['followup_id'=>'exact supplied followup ID']:
            ($op==='update'?['followup_id'=>'exact supplied followup ID']:[])+['focus'=>'plain text, 1–240 characters','start_local'=>'YYYY-MM-DDTHH:MM:SS','timezone'=>'explicit or configured IANA timezone']];
    return $out;
}

function vp3_initiative_followup_action_resources_v1(PDO $pdo,array $loaded,array $binding): array
{
    if(!vp3_initiative_followup_action_ready_v1($pdo))return ['followups'=>[]];
    $id=$loaded['state']['followup_reminder_id']??0;if(!is_int($id)||$id<1)return ['followups'=>[]];
    require_once __DIR__.'/agent-initiative-followup-origin-v1.php';
    $linked=vp3_initiative_followup_origin_v1($pdo,$loaded['user'],$loaded['principal'],$id);
    if((int)$linked['origin']['message']['id']!==(int)$loaded['message']['id'])throw new RuntimeException('Follow-up proposal changed.',409);
    $r=$linked['reminder'];
    return ['followups'=>[['id'=>$id,'ref'=>'reminder:'.$id,'title'=>(string)$r['title'],'status'=>(string)$r['status'],
        'revision'=>(int)$r['revision'],'origin_action_id'=>(int)$r['followup_action_id']]]];
}

function vp3_initiative_followup_action_normalize_v1(PDO $pdo,array $user,array $principal,array $binding,array $raw,array $allowed): array
{
    $scope=vp3_initiative_action_scope_v1($pdo,$user,$principal,$binding);
    if(!vp3_initiative_followup_durable_refs_v1($binding))throw new RuntimeException('Choose a saved task, list or supported record for that future revisit.',409);
    vp3_initiative_followup_action_source_authority_v1($scope['user'],$binding);
    if(!vp3_initiative_followup_action_ready_v1($pdo))throw new RuntimeException('Linked follow-up storage is unavailable.',503);
    vp3_initiative_schedule_action_fields_v1($raw,['adapter','arguments']);$adapter=$raw['adapter']??null;$args=$raw['arguments']??null;
    if(!is_string($adapter)||!preg_match('/\Aproposal\.revisit\.(create|update|cancel)\z/D',$adapter,$match)||!is_array($args))throw new InvalidArgumentException('Invalid proposal revisit.');
    $op=$match[1];
    if($op==='create'&&is_int($binding['followup_reminder_id']??null)&&$binding['followup_reminder_id']>0){
        $q=$pdo->prepare('SELECT status FROM agent_reminders_v1 WHERE id=? AND owner_user_id=?');$q->execute([$binding['followup_reminder_id'],(int)$scope['user']['id']]);
        if($q->fetchColumn()==='active')throw new RuntimeException('Update or cancel the existing scheduled revisit first.',409);
    }
    $keys=$op==='cancel'?['followup_id']:($op==='update'?['followup_id','focus','start_local','timezone']:['focus','start_local','timezone']);
    vp3_initiative_schedule_action_fields_v1($args,$keys);
    if(count($args)!==count($keys))throw new InvalidArgumentException('Complete follow-up details are required.');
    $origin=array_intersect_key($binding,array_flip(['owner_user_id','agent_id','conversation_id','proposal_message_id','reply_ref','proposal_revision','owner_message_id','owner_message_hash','proposal_goal_hash']));
    foreach(['proposal_message_id','proposal_revision','owner_message_id'] as $key)if(!is_int($origin[$key]??null)||$origin[$key]<1)throw new RuntimeException('Follow-up origin is unavailable.',409);
    if(!is_string($origin['proposal_goal_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$origin['proposal_goal_hash']))throw new RuntimeException('Follow-up source binding changed.',409);
    $sourceOrigin=$binding['source_origin']??null;
    if(!is_array($sourceOrigin))throw new RuntimeException('Follow-up source origin is unavailable.',409);
    $nativeArgs=[];$resources=['reminders'=>[],'calendar_events'=>[]];$normalized=[];$expected=[];
    if($op!=='create'){
        $id=$args['followup_id']??null;if(!is_int($id)||$id<1)throw new InvalidArgumentException('An exact follow-up ID is required.');
        $supplied=null;foreach($allowed['followups']??[] as $item)if(($item['id']??null)===$id)$supplied=$item;
        if(!$supplied)throw new RuntimeException('Follow-up is outside this proposal.',403);
        if(in_array('reminder:'.$id,$binding['proposal_source_refs']??[],true))throw new RuntimeException('Use the current reminder tools to edit this follow-up source.',409);
        require_once __DIR__.'/agent-initiative-followup-origin-v1.php';$linked=vp3_initiative_followup_origin_v1($pdo,$scope['user'],$scope['principal'],$id);
        $r=$linked['reminder'];
        if((int)$linked['origin']['message']['id']!==$binding['proposal_message_id']||(int)$r['revision']!==($supplied['revision']??null)
            ||(int)$r['followup_action_id']!==($supplied['origin_action_id']??null))throw new RuntimeException('Follow-up changed before review.',409);
        $sourceOrigin=$linked['source_origin'];$origin=$linked['creating_action']['goal']['plan']['origin'];$normalized['followup_id']=$id;$expected=['id'=>$id,'revision'=>(int)$r['revision'],'origin_action_id'=>(int)$r['followup_action_id']];
        $resources=vp3_initiative_schedule_action_resources_v1($pdo,$scope['user'],$scope['principal'],$binding,['reminder:'.$id]);$nativeArgs['target_ref']='reminder:'.$id;
    }
    if($op!=='cancel'){
        if(!is_string($args['focus'])||trim($args['focus'])===''||mb_strlen($args['focus'])>240||preg_match('/[\x00-\x1f\x7f]/',$args['focus']))throw new InvalidArgumentException('Choose a short follow-up focus.');
        $normalized+=['focus'=>trim($args['focus']),'start_local'=>$args['start_local'],'timezone'=>$args['timezone']];
        $nativeArgs['schedule']=['title'=>$normalized['focus'],'start_local'=>$args['start_local'],'timezone'=>$args['timezone'],'recurrence'=>'none','delivery'=>'chat'];
    }
    $native=vp3_initiative_schedule_action_normalize_v1($pdo,$scope['user'],$scope['principal'],$binding,['adapter'=>'reminder.'.$op,'arguments'=>$nativeArgs],$resources);
    if($op!=='cancel'){$normalized['start_local']=$native['arguments']['schedule']['start_local'];$normalized['timezone']=$native['arguments']['schedule']['timezone'];}
    return ['adapter'=>$adapter,'arguments'=>$normalized,'capability'=>'reminders.manage','operation'=>$op,'effect_class'=>'private_record',
        'review_required'=>true,'target_refs'=>$native['target_refs'],'expected'=>$expected,'origin'=>$origin,'source_origin'=>$sourceOrigin,'native_plan'=>$native];
}

function vp3_initiative_followup_action_apply_v1(PDO $pdo,array $user,array $principal,array $binding,array $plan,string $actionId): array
{
    if(!$pdo->inTransaction()||!preg_match('/\Ainitiative-action:([1-9][0-9]*)\z/D',$actionId,$match))throw new RuntimeException('Follow-up action requires the canonical owner transaction.',409);
    $scope=vp3_initiative_action_scope_v1($pdo,$user,$principal,$binding);$op=$plan['operation'];$allowed=['followups'=>[]];
    if($op!=='create')$allowed['followups'][]=$plan['expected'];
    $current=vp3_initiative_followup_action_normalize_v1($pdo,$scope['user'],$scope['principal'],$binding,['adapter'=>$plan['adapter'],'arguments'=>$plan['arguments']],$allowed);
    if($current!==$plan)throw new RuntimeException('Reviewed follow-up changed.',409);
    $native=vp3_initiative_schedule_action_apply_v1($pdo,$scope['user'],$scope['principal'],$binding,$plan['native_plan'],$actionId);$id=$native['record_id'];
    if($op==='create'){
        $q=$pdo->prepare('UPDATE agent_reminders_v1 SET followup_action_id=? WHERE id=? AND owner_user_id=? AND conversation_id=? AND followup_action_id IS NULL');
        $q->execute([(int)$match[1],$id,(int)$scope['user']['id'],$scope['conversation_id']]);if($q->rowCount()!==1)throw new RuntimeException('Follow-up origin was not linked.',409);
        $native['effect']=vp3_initiative_schedule_action_effect_v1($pdo,$scope,'reminder',$id,$plan['native_plan']['expected']);
        $native['effect_hash']=hash('sha256',json_encode($native['effect'],JSON_THROW_ON_ERROR));
    }
    $native['followup_origin']=$plan['origin'];$native['followup_native_plan']=$plan['native_plan'];
    $native['followup_purpose']='proposal_revisit';
    vp3_initiative_followup_action_inspect_v1($pdo,$scope['user'],$scope['principal'],$binding,$plan,$native);
    return $native;
}

/** A saved mutation receipt proves the historical commitment. Current reminder
 * status/schedule comes from the live row, not its mutable creation snapshot. */
function vp3_initiative_followup_action_inspect_v1(PDO $pdo,array $user,array $principal,array $binding,array $plan,array $result): array
{
    $scope=vp3_initiative_action_scope_v1($pdo,$user,$principal,$binding);$uid=(int)$scope['user']['id'];$id=$result['record_id']??null;
    if(!is_int($id)||$id<1||($result['followup_origin']??null)!==$plan['origin']||($result['followup_native_plan']??null)!==$plan['native_plan']
        ||!preg_match('/\Ainitiative-action:([1-9][0-9]*)\z/D',(string)($result['action_id']??''),$match))throw new RuntimeException('Follow-up receipt changed.',409);
    $native=$plan['native_plan'];$payload=json_encode(['action_id'=>$result['action_id'],'adapter'=>$native['adapter'],'arguments'=>$native['arguments']],JSON_THROW_ON_ERROR);
    $q=$pdo->prepare('SELECT payload_hash,result_json FROM agent_reminder_turns_v1 WHERE owner_user_id=? AND thread_key=? AND request_key=?');
    $q->execute([$uid,'initiative:'.$scope['agent_id'].':'.$scope['conversation_id'],'initiative_'.hash('sha256',$result['action_id'])]);$turn=$q->fetch(PDO::FETCH_ASSOC);
    $saved=$turn?json_decode((string)$turn['result_json'],true,32,JSON_THROW_ON_ERROR):null;
    if(!$turn||!hash_equals(hash('sha256',$payload),(string)$turn['payload_hash'])||($saved['tool_receipt']??null)!==($result['tool_receipt']??null)
        ||($saved['tool_receipt']['record_id']??null)!==$id)throw new RuntimeException('Canonical follow-up mutation receipt is unavailable.',409);
    $q=$pdo->prepare('SELECT * FROM agent_reminders_v1 WHERE id=? AND owner_user_id=?');$q->execute([$id,$uid]);$r=$q->fetch(PDO::FETCH_ASSOC);
    $originId=$plan['operation']==='create'?(int)$match[1]:$plan['expected']['origin_action_id'];
    if(!$r||(int)$r['followup_action_id']!==$originId||(int)$r['conversation_id']!==$plan['origin']['conversation_id']
        ||!in_array($r['status'],['active','finished','cancelled'],true))throw new RuntimeException('Follow-up current identity changed.',409);
    return ['verified'=>true,'resources'=>[['id'=>$id,'ref'=>'reminder:'.$id,'title'=>$r['title'],'status'=>$r['status'],'delivery_state'=>reminder_followup_delivered_v1($pdo,$r)?'delivered':$r['status'],'start_local'=>$r['start_local'],'next_at'=>$r['next_at'],'timezone'=>$r['timezone']]],'affected_refs'=>$plan['target_refs']];
}

/** Only actual saved owner requests bound to this proposal supply missing time
 * details. Generated summaries and unrelated Chat history grant nothing. */
function vp3_initiative_followup_prior_replies_v1(PDO $pdo,array $loaded,int $beforeId): array
{
    if(empty($loaded['state']['followup_clarification']))return [];
    $q=$pdo->prepare("SELECT id,message,context_json FROM chat_messages WHERE conversation_id=? AND user_id=? AND role='user' AND id<? ORDER BY id DESC LIMIT 24");
    $q->execute([$loaded['conversation_id'],(int)$loaded['user']['id'],$beforeId]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $ctx=json_decode((string)$row['context_json'],true);$anchor=$ctx['initiative_reply_anchor_v1']??null;
        if(!is_array($anchor)||($anchor['initiative_reply']['message_id']??null)!==(int)$loaded['message']['id']
            ||($anchor['initiative_reply']['reply_ref']??null)!==$loaded['state']['reply_ref']
            ||($anchor['query_hash']??null)!==hash('sha256',(string)$row['message']))continue;
        $out[]=['message_id'=>(int)$row['id'],'text'=>(string)$row['message']];if(count($out)===4)break;
    }
    return array_reverse($out);
}
