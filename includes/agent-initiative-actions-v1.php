<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-v1.php';
require_once __DIR__.'/agent-initiative-office-actions-v1.php';
require_once __DIR__.'/agent-initiative-schedule-actions-v1.php';
require_once __DIR__.'/personal-capabilities-strict-v1.php';
require_once __DIR__.'/agent-initiative-followup-actions-v1.php';

/** Shared live authority. A proposal, model response or family name grants nothing. */
function vp3_initiative_action_scope_v1(PDO $pdo,array $user,array $principal,array $binding): array
{
    $uid=(int)($user['id']??0);$aid=$binding['agent_id']??null;$cid=$binding['conversation_id']??null;
    if($uid<1||!is_int($aid)||$aid<0||!is_int($cid)||$cid<1||($binding['owner_user_id']??null)!==$uid
        ||($principal['owner_user_id']??null)!==($aid?$uid:0)||($principal['viewer_user_id']??null)!==$uid||($principal['kind']??null)!==($aid?'user_agent':'system')
        ||(int)($principal['agent_id']??0)!==$aid||vp3_participant_scope_v1()!==null)
        throw new RuntimeException('Action owner scope changed.',403);
    $canonical=vp3_initiative_principal_v1($pdo,$user,$aid);
    $live=vp3_capability_live_user_v1($pdo,$user,$canonical,['conversation_id'=>$cid],null);
    if(!has_permission('account.access',$live)||!has_permission('chat.access',$live)
        ||!vp3_agent_chat_conversation_v380($pdo,$cid,$uid,$aid?['id'=>$aid]:null,$pdo->inTransaction()))
        throw new RuntimeException('Action access changed.',403);
    $policy=vp3_initiative_policy_v1($live,$aid);
    if(($policy['enabled']??null)!==true||($policy['conversation_id']??null)!==$cid
        ||isset($binding['policy_revision'])&&($policy['revision']??null)!==$binding['policy_revision'])
        throw new RuntimeException('Action policy changed.',403);
    vp3_initiative_access_v1($pdo,$live,$policy);
    return ['user'=>$live,'principal'=>$canonical,'agent_id'=>$aid,'conversation_id'=>$cid];
}

function vp3_initiative_action_binding_v1(array $loaded,int $ownerMessageId,string $query): array
{
    return ['owner_user_id'=>(int)$loaded['user']['id'],'agent_id'=>$loaded['agent_id'],'conversation_id'=>$loaded['conversation_id'],
        'proposal_message_id'=>(int)$loaded['message']['id'],'reply_ref'=>$loaded['state']['reply_ref'],
        'proposal_revision'=>$loaded['state']['revision'],'proposal_goal_hash'=>$loaded['state']['goal_hash'],
        'source_origin'=>$loaded['goal']['followup']['source_origin']??['owner_user_id'=>(int)$loaded['user']['id'],'agent_id'=>$loaded['agent_id'],'conversation_id'=>$loaded['conversation_id'],'message_id'=>(int)$loaded['message']['id'],'goal_hash'=>$loaded['state']['goal_hash']],
        'proposal_source_refs'=>array_keys($loaded['goal']['sources']),'followup_reminder_id'=>$loaded['state']['followup_reminder_id']??0,'review_slot'=>'action_run_id','owner_message_id'=>$ownerMessageId,'owner_message_hash'=>hash('sha256',$query),
        'policy_revision'=>vp3_initiative_policy_v1($loaded['user'],$loaded['agent_id'])['revision']??''];
}

function vp3_initiative_action_family_v1(string $adapter): string
{
    return match(strtok($adapter,'.')){'task','task_list'=>'office','reminder','calendar'=>'schedule','proposal'=>'followup',
        default=>throw new InvalidArgumentException('Action adapter is unavailable.')};
}

/** Server registry; no PHP function names or executable queries come from inference. */
function vp3_initiative_action_call_v1(string $operation,string $adapter,array $arguments): array
{
    if(!in_array($operation,['normalize','apply','inspect'],true))throw new LogicException('Invalid action adapter operation.');
    $family=vp3_initiative_action_family_v1($adapter);
    return ('vp3_initiative_'.$family.'_action_'.$operation.'_v1')(...$arguments);
}

function vp3_initiative_action_resources_v1(PDO $pdo,array $loaded,array $binding): array
{
    $scope=vp3_initiative_action_scope_v1($pdo,$loaded['user'],$loaded['principal'],$binding);
    $out=['tasks'=>[],'lists'=>[],'reminders'=>[],'calendar_events'=>[]];
    require_once __DIR__.'/agent-initiative-task-lists-v1.php';
    foreach(array_keys($loaded['goal']['sources']) as $ref){
        if(preg_match('/\Atask:([1-9][0-9]*)\z/D',$ref,$match)){
            $out['tasks'][]=vp3_initiative_office_action_task_v1($pdo,$scope['user'],$scope['principal'],(int)$match[1]);
        }elseif(preg_match('/\Atask-list:([1-9][0-9]*)\z/D',$ref,$match)){
            $id=(int)$match[1];$out['lists'][]=vp3_initiative_office_action_list_v1($pdo,$scope['user'],$scope['principal'],$id);
            $state=vp3_initiative_task_list_state_v1($pdo,$scope,$id);
            if(!$state)throw new RuntimeException('Action list changed.',409);
            foreach($state['members'] as $member)$out['tasks'][]=vp3_initiative_office_action_task_v1($pdo,$scope['user'],$scope['principal'],$member['id']);
        }
    }
    $schedule=vp3_initiative_schedule_action_resources_v1($pdo,$scope['user'],$scope['principal'],$binding,array_keys($loaded['goal']['sources']));
    return array_replace($out,$schedule,vp3_initiative_followup_action_resources_v1($pdo,$loaded,$binding));
}

function vp3_initiative_action_catalog_v1(PDO $pdo,array $loaded,array $binding): array
{
    vp3_initiative_action_scope_v1($pdo,$loaded['user'],$loaded['principal'],$binding);$out=[];
    foreach(['office','schedule','followup'] as $family){
        try{$entries=('vp3_initiative_'.$family.'_action_catalog_v1')($pdo,$loaded['user'],$loaded['principal'],$binding);}
        catch(RuntimeException $error){if($error instanceof PDOException)throw $error;if(!in_array((int)$error->getCode(),[403,503],true))throw $error;continue;}
        foreach($entries as $entry)$out[$entry['adapter']]=$entry;
    }
    return $out;
}

/** Keep discovery bounded and explicit about coverage. Full server resources
 * remain authoritative for normalization; inference receives no memory hashes. */
function vp3_initiative_action_prompt_resources_v1(array $resources): array
{
    $out=[];
    foreach($resources as $family=>$rows){
        if(!is_array($rows)||!array_is_list($rows))throw new RuntimeException('Action resource discovery changed.',503);
        $sample=array_slice($rows,0,80);
        $out[$family]=['coverage'=>$family==='condition_targets'||count($rows)>count($sample)?'limited':'complete','total'=>count($rows),
            'records'=>array_map(static fn(array $row):array=>array_intersect_key($row,array_flip(['id','ref','title','name','status','occurrence_id','milestones'])),$sample)];
    }
    return $out;
}

/** A server clock is not the owner's local timezone. Only an actual configured
 * default schedule supplies one; otherwise the Agent must ask when needed. */
function vp3_initiative_action_clock_v1(PDO $pdo,array $user): array
{
    $clock=['current_time_utc'=>gmdate(DATE_ATOM),'owner_timezone'=>null,'timezone_status'=>'not_configured'];
    try{
        if(!table_exists('agent_scheduling_schedules'))return $clock;
        $q=$pdo->prepare('SELECT timezone FROM agent_scheduling_schedules WHERE owner_user_id=? AND is_default=1 AND is_active=1 ORDER BY id LIMIT 1');
        $q->execute([(int)$user['id']]);$zone=$q->fetchColumn();
        if(!is_string($zone)||trim($zone)==='')return $clock;
        require_once __DIR__.'/agent-reminders-v1.php';
        $clock['owner_timezone']=reminder_zone_v1($zone);$clock['timezone_status']='configured_default_schedule';
        $clock['current_local_time']=(new DateTimeImmutable('now',new DateTimeZone($clock['owner_timezone'])))->format(DATE_ATOM);
    }catch(Throwable $error){$clock['timezone_status']='unavailable';}
    return $clock;
}

/** The canonical workflow ledger holds the immutable reviewed plan. */
function vp3_initiative_action_load_v1(PDO $pdo,array $loaded): ?array
{
    $id=$loaded['state']['action_run_id']??0;if($id===0)return null;
    if(!is_int($id)||$id<1)throw new RuntimeException('Action identity changed.',409);
    $uid=(int)$loaded['user']['id'];$row=agent_workflow_row_v1400($pdo,$uid,$id,$pdo->inTransaction());
    if(!$row||$row['source_kind']!=='agent_initiative_action'||$row['capability_key']!=='initiative.action'
        ||($row['agent_id']===null?0:(int)$row['agent_id'])!==$loaded['agent_id']
        ||!in_array($row['status'],['approval_pending','completed','cancelled'],true))throw new RuntimeException('Action workflow changed.',409);
    $goal=json_decode((string)$row['goal'],true,64,JSON_THROW_ON_ERROR);$b=$goal['binding']??null;
    if(!is_array($b)||($b['owner_user_id']??null)!==$uid||($b['agent_id']??null)!==$loaded['agent_id']
        ||($b['conversation_id']??null)!==$loaded['conversation_id']||($b['proposal_message_id']??null)!==(int)$loaded['message']['id']
        ||($b['reply_ref']??null)!==$loaded['state']['reply_ref']||($goal['proposal_goal_hash']??null)!==$loaded['state']['goal_hash']
        ||!is_array($goal['plan']??null)||!hash_equals(hash('sha256',json_encode($goal['plan'],JSON_THROW_ON_ERROR)),(string)($goal['plan_hash']??'')))
        throw new RuntimeException('Reviewed action binding changed.',409);
    $q=$pdo->prepare("SELECT id,message FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
    $q->execute([$b['owner_message_id'],$loaded['conversation_id'],$uid]);$owner=$q->fetch(PDO::FETCH_ASSOC);
    if(!$owner||!hash_equals($b['owner_message_hash'],hash('sha256',(string)$owner['message'])))throw new RuntimeException('Action request changed.',409);
    $q=$pdo->prepare('SELECT * FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? ORDER BY sequence_no,id');$q->execute([$id,$uid]);$actions=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($actions)!==1||$actions[0]['capability_key']!=='initiative.action'||$actions[0]['status']!==$row['status'])throw new RuntimeException('Action step changed.',409);
    $result=null;$receiptId=0;
    if($row['status']==='completed'){
        $q=$pdo->prepare("SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? AND action_id=? AND owner_user_id=? AND status='completed'");
        $q->execute([$id,(int)$actions[0]['id'],$uid]);$receipts=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($receipts)!==1||$receipts[0]['result_json']!==$actions[0]['result_json'])throw new RuntimeException('Action receipt changed.',409);
        $result=json_decode((string)$receipts[0]['result_json'],true,64,JSON_THROW_ON_ERROR);$receiptId=(int)$receipts[0]['id'];
        if(!is_array($result)||($result['version']??null)!==1||($result['plan_hash']??null)!==$goal['plan_hash']||($result['action_id']??null)!==(int)$actions[0]['id']
            ||!is_array($result['domain_result']??null)||!is_array($result['source_transition']??null))throw new RuntimeException('Completed action proof changed.',409);
        $domain=$result['domain_result'];$identity='initiative-action:'.(int)$actions[0]['id'];
        $nested=vp3_initiative_action_family_v1($goal['plan']['adapter'])==='office'?($domain['office_action']['action_id']??null):($domain['action_id']??null);
        if($nested!==$identity||!is_int($result['approval_message_id']??null)||$result['approval_message_id']<=$b['owner_message_id']
            ||!is_string($result['approval_message_hash']??null))throw new RuntimeException('Action approval identity changed.',409);
        $q=$pdo->prepare("SELECT message FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
        $q->execute([$result['approval_message_id'],$loaded['conversation_id'],$uid]);$approved=$q->fetchColumn();
        if(!is_string($approved)||!hash_equals(hash('sha256',$approved),$result['approval_message_hash']))throw new RuntimeException('Saved action approval changed.',409);
    }
    return ['run'=>$row,'action'=>$actions[0],'goal'=>$goal,'result'=>$result,'receipt_id'=>$receiptId];
}

/** The exact parameters must already have reached the owner's canonical Chat.
 * A killed review generation or an unrelated pending question grants nothing. */
function vp3_initiative_action_reviewed_v1(PDO $pdo,array $loaded,array $action,int $ownerMessageId): void
{
    $q=$pdo->prepare('SELECT id,role,context_json FROM chat_messages WHERE conversation_id=? AND id<? ORDER BY id DESC LIMIT 1');
    $q->execute([$loaded['conversation_id'],$ownerMessageId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $context=$row?json_decode((string)$row['context_json'],true,64,JSON_THROW_ON_ERROR):null;
    if(!$row||$row['role']!=='assistant'||!is_array($context)
        ||($context['initiative_action']??null)!==vp3_initiative_action_preview_v1($action)
        ||($context['initiative_followthrough']??null)!==vp3_initiative_followthrough_outcome_v1($loaded))
        throw new RuntimeException('The exact action review must be shown before approval.',409);
}

function vp3_initiative_action_cancel_v1(PDO $pdo,array $loaded,array $action): void
{
    if(!$pdo->inTransaction()||$action['run']['status']!=='approval_pending')throw new RuntimeException('Action cancellation changed.',409);
    $uid=(int)$loaded['user']['id'];$id=(int)$action['run']['id'];
    $pdo->prepare("UPDATE agent_workflow_runs SET status='cancelled',approval_status='rejected',cancelled_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=? AND status='approval_pending'")->execute([$id,$uid]);
    $pdo->prepare("UPDATE agent_workflow_actions SET status='cancelled',completed_at=UTC_TIMESTAMP() WHERE run_id=? AND owner_user_id=? AND status='approval_pending'")->execute([$id,$uid]);
    agent_workflow_event_v1400($pdo,$uid,$id,'initiative_action_cancelled','approval_pending','cancelled','owner','Owner declined the pending private action.');
}

function vp3_initiative_action_preview_v1(array $action): array
{
    $expected=$action['goal']['plan']['expected'];$targets=[];
    if(isset($expected['task']))$targets[]=['ref'=>'task:'.$expected['task']['id'],'title'=>$expected['task']['title']];
    if(isset($expected['list'])&&is_array($expected['list']))$targets[]=['ref'=>'task-list:'.$expected['list']['id'],'title'=>$expected['list']['name']];
    if(isset($expected['ref'],$expected['title']))$targets[]=['ref'=>$expected['ref'],'title'=>$expected['title']];
    return ['run_id'=>(int)$action['run']['id'],'status'=>$action['run']['status'],'plan_hash'=>$action['goal']['plan_hash'],
        'adapter'=>$action['goal']['plan']['adapter'],'arguments'=>$action['goal']['plan']['arguments'],
        'targets'=>$targets,'effect_class'=>$action['goal']['plan']['effect_class'],'review_required'=>true,'receipt_id'=>$action['receipt_id']];
}

/** Called only inside the owner-locked final decision transaction. */
function vp3_initiative_action_prepare_v1(PDO $pdo,array &$loaded,array &$state,array $binding,array $raw,array $allowedResources): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Action review needs the owner transaction.',409);
    $catalog=vp3_initiative_action_catalog_v1($pdo,$loaded,$binding);
    if(!is_string($raw['adapter']??null)||!isset($catalog[$raw['adapter']]))throw new RuntimeException('Action adapter is unavailable.',403);
    $plan=vp3_initiative_action_call_v1('normalize',$raw['adapter'],[$pdo,$loaded['user'],$loaded['principal'],$binding,$raw,$allowedResources]);
    if(($plan['review_required']??null)!==true||($plan['effect_class']??null)!=='private_record')throw new RuntimeException('Action review contract changed.',403);
    $uid=(int)$loaded['user']['id'];$hash=hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR));
    $goal=['version'=>1,'binding'=>$binding,'proposal_goal_hash'=>$loaded['state']['goal_hash'],'plan'=>$plan,'plan_hash'=>$hash];
    $key=hash('sha256',json_encode(['initiative.action',$binding,$hash],JSON_THROW_ON_ERROR));
    $pdo->prepare("INSERT INTO agent_workflow_runs(owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,status,risk_level,requires_approval,approval_status,execution_target,capability_key)
        VALUES(?,?,'initiative','brain','agent_initiative_action',?,?,?,'Review proposed Agent action',?,'approval_pending','low',1,'pending','cloud','initiative.action')")
        ->execute([$uid,$loaded['agent_id']?:null,$key,substr($hash,0,40),$key,json_encode($goal,JSON_THROW_ON_ERROR)]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO agent_workflow_actions(run_id,owner_user_id,sequence_no,action_key,action_type,label,status,requires_approval,execution_target,capability_key)
        VALUES(?,?,1,?,'execute','Review proposed Agent action','approval_pending',1,'cloud','initiative.action')")->execute([$id,$uid,$key]);
    $slot=$binding['review_slot']??'action_run_id';
    if(!in_array($slot,['action_run_id','followup_run_id'],true))throw new LogicException('Unknown server review slot.');
    $state[$slot]=$id;$state['active_review_slot']=$slot;$state['state']='discussed';
    agent_workflow_event_v1400($pdo,$uid,$id,'initiative_action_review','','approval_pending','owner','An exact private action is ready for owner review.',['proposal_message_id'=>$binding['proposal_message_id'],'owner_message_id'=>$binding['owner_message_id'],'plan_hash'=>$hash]);
    return ['run_id'=>$id,'status'=>'approval_pending','plan_hash'=>$hash,'adapter'=>$plan['adapter'],'arguments'=>$plan['arguments'],'effect_class'=>'private_record','review_required'=>true,'receipt_id'=>0];
}

function vp3_initiative_action_apply_v1(PDO $pdo,array &$loaded,array &$state,array $action,int $ownerMessageId,string $query,?callable $authority): array
{
    if(!$pdo->inTransaction()||$action['run']['status']!=='approval_pending')throw new RuntimeException('Action is not awaiting this review.',409);
    $binding=$action['goal']['binding'];vp3_initiative_action_scope_v1($pdo,$loaded['user'],$loaded['principal'],$binding);
    $uid=(int)$loaded['user']['id'];$runId=(int)$action['run']['id'];$actionId=(int)$action['action']['id'];
    if($authority)$authority();
    require_once __DIR__.'/agent-initiative-action-sources-v1.php';
    $followup=vp3_initiative_action_family_v1($action['goal']['plan']['adapter'])==='followup';
    if($followup)require_once __DIR__.'/agent-initiative-followup-origin-v1.php';
    $witness=$followup?vp3_initiative_followup_sources_prepare_v1($pdo,$loaded):vp3_initiative_action_sources_prepare_v1($pdo,$loaded,$action['goal']['plan']);
    $result=vp3_initiative_action_call_v1('apply',$action['goal']['plan']['adapter'],[$pdo,$loaded['user'],$loaded['principal'],$binding,$action['goal']['plan'],'initiative-action:'.$actionId]);
    if(($result['verified']??null)!==true)throw new RuntimeException('Action result is unverified.',503);
    require_once __DIR__.'/agent-initiative-action-sources-v1.php';
    $transition=$followup?vp3_initiative_followup_sources_capture_v1($pdo,$loaded,$action['goal']['plan'],$result,$witness):vp3_initiative_action_sources_capture_v1($pdo,$loaded,$action['goal']['plan'],$result,$witness);
    if($followup)$state['followup_reminder_id']=$result['record_id'];
    if($authority)$authority();
    $proof=['version'=>1,'action_id'=>$actionId,'plan_hash'=>$action['goal']['plan_hash'],
        'approval_message_id'=>$ownerMessageId,'approval_message_hash'=>hash('sha256',$query),'domain_result'=>$result,'source_transition'=>$transition];
    $json=json_encode($proof,JSON_THROW_ON_ERROR);if(strlen($json)>262144)throw new RuntimeException('Action receipt exceeds its bound.',503);
    $pdo->prepare("UPDATE agent_workflow_actions SET status='completed',attempt_count=1,result_json=?,completed_at=UTC_TIMESTAMP(),started_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=? AND status='approval_pending'")->execute([$json,$actionId,$uid]);
    $pdo->prepare("INSERT INTO agent_workflow_receipts(run_id,action_id,owner_user_id,receipt_key,executor,worker_id,attempt_no,status,summary,result_json,error_class) VALUES(?,?,?,?,'cloud','initiative-owner-action',1,'completed','Canonical private action verified.',?,'')")
        ->execute([$runId,$actionId,$uid,hash('sha256','initiative-action:'.$actionId),$json]);
    $receiptId=(int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE agent_workflow_runs SET status='completed',approval_status='approved',approved_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP(),attempt_count=1 WHERE id=? AND owner_user_id=? AND status='approval_pending'")->execute([$runId,$uid]);
    agent_workflow_event_v1400($pdo,$uid,$runId,'initiative_action_completed','approval_pending','completed','owner','Reviewed action committed with its canonical domain receipt.',['action_id'=>$actionId,'receipt_id'=>$receiptId,'approval_message_id'=>$ownerMessageId]);
    $state['state']='discussed';$action['run']['status']='completed';$action['receipt_id']=$receiptId;$action['result']=$proof;
    return vp3_initiative_action_preview_v1($action);
}
