<?php
declare(strict_types=1);

// Reuse the actual SQLite Office kernel fixture and its canonical mutations.
require __DIR__.'/agent-initiative-office-actions-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-capabilities-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-tools-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-action-sources-v1.php';

// Host policy only. Domain authority, source readers, read proofs, status/list
// persistence and transition verification all execute production functions.
function vp3_initiative_policy_v1(array $user,int $agent): array{return $GLOBALS['transitionBinding'];}
function vp3_initiative_access_v1(PDO $pdo,array $user,array $binding): array{return $user;}
function url(string $path=''): string{return '/vp3'.$path;}
function transition_load_function(string $file,string $name): void
{
    $tokens=token_get_all(file_get_contents(dirname(__DIR__).'/includes/'.$file));
    for($i=0;$i<count($tokens);$i++){
        if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
        $j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;
        if(!is_array($tokens[$j])||$tokens[$j][1]!==$name)continue;
        $body='';$depth=0;$opened=false;
        for($j=$i;$j<count($tokens);$j++){
            $part=is_array($tokens[$j])?$tokens[$j][1]:$tokens[$j];$body.=$part;
            if($part==='{'){$opened=true;$depth++;}elseif($part==='}'&&--$depth===0&&$opened){eval(str_replace('__DIR__',var_export(dirname(__DIR__).'/includes',true),$body));return;}
        }
    }
    throw new RuntimeException('Missing production function '.$name);
}
transition_load_function('agent-capability-chat-v1.php','vp3_capability_live_user_v1');
transition_load_function('agent-initiative-followthrough-v1.php','vp3_initiative_followthrough_sources_v1');
$transitionBinding=[...$b,'enabled'=>true,'screen_enabled'=>false,'knowledge_scope'=>['mode'=>'off','folder_id'=>0]];
$transitionChecks=0;
function transition_check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['transitionChecks']++;echo 'PASS '.$label.PHP_EOL;}
function transition_reject(callable $work,int $status,string $label): void
{
    try{$work();}catch(Throwable $e){transition_check($e->getCode()===$status,$label.' ('.$e->getMessage().')');return;}
    throw new RuntimeException('Expected rejection: '.$label);
}
function transition_loaded(array $refs,string $tool,string $primary): array
{
    $scope=['user'=>$GLOBALS['u'],'principal'=>$GLOBALS['p'],'agent_id'=>7,'conversation_id'=>10,'verification_only'=>true];$coverage=[];
    $evidence=vp3_initiative_capabilities_evidence_v1(db(),$scope,$GLOBALS['transitionBinding'],new DateTimeImmutable('now',new DateTimeZone('UTC')),$refs,$coverage);
    $sources=[];foreach($refs as $ref)$sources[$ref]=$evidence[$ref]['revision'];
    $goal=['binding'=>$GLOBALS['transitionBinding'],'sources'=>$sources,'capability_sources'=>vp3_initiative_capability_source_map_v1($evidence,$refs),
        'tool'=>$tool,'primary'=>$primary,'focus'=>'Advance the exact saved task.','report'=>true];
    $read=vp3_initiative_capability_snapshot_v1(db(),$scope,$goal['binding'],$tool,['primary'=>$primary,'refs'=>$refs],$evidence);
    $revisions=$sources;ksort($revisions);
    $out=['type'=>'initiative_capability_read','version'=>1,'tool'=>$tool,'status'=>'read_complete','owner_user_id'=>1,'agent_id'=>7,'conversation_id'=>10,
        'primary'=>$primary,'source_revisions'=>$revisions,'read_fingerprint'=>vp3_initiative_capability_fingerprint_v1($read),
        'record_count'=>count($read['records']),'coverage_fingerprint'=>vp3_initiative_capability_fingerprint_v1($read['coverage']),
        'coverage_state'=>in_array($read['coverage']['state']??'', ['ready','limited'],true)?$read['coverage']['state']:'bounded'];
    $json=json_encode($goal,JSON_THROW_ON_ERROR);
    return ['user'=>$GLOBALS['u'],'principal'=>$GLOBALS['p'],'agent_id'=>7,'conversation_id'=>10,'message'=>['id'=>500],
        'run'=>['id'=>600,'goal'=>$json],'goal'=>$goal,'state'=>['goal_hash'=>hash('sha256',$json)],
        'result'=>['initiative_tool_result'=>['output'=>$out]],'evidence'=>$evidence];
}
function transition_apply(array $loaded,array $plan,string $action,?callable $during=null): array
{
    db()->beginTransaction();
    try{
        $before=vp3_initiative_action_sources_prepare_v1(db(),$loaded,$plan);
        $r=vp3_initiative_office_action_apply_v1(db(),$GLOBALS['u'],$GLOBALS['p'],$GLOBALS['transitionBinding'],$plan,$action);
        if($during)$during($r);
        $proof=vp3_initiative_action_sources_capture_v1(db(),$loaded,$plan,$r,$before);db()->commit();
        return [$r,$proof];
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}

$one=office_action_apply(office_action_plan('task.create',['title'=>'Transition target']),'transition-create-target');$target=$one['office_action']['task_id'];
$two=office_action_apply(office_action_plan('task.create',['title'=>'Untouched task']),'transition-create-other');$other=$two['office_action']['task_id'];
$lr=office_action_apply(office_action_plan('task_list.create',['name'=>'Transition list']),'transition-create-list');$list=$lr['office_action']['list_id'];
foreach([$target,$other] as $id)office_action_apply(office_action_plan('task_list.add',['list_id'=>$list,'task_id'=>$id],
    ['lists'=>[['id'=>$list,'name'=>'Transition list']],'tasks'=>[['id'=>$id,'title'=>$id===$target?'Transition target':'Untouched task']]]),'transition-attach-'.$id);
$refs=['task:'.$target,'task:'.$other,'task-list:'.$list];$loaded=transition_loaded($refs,'tasks.read','task:'.$target);
$plan=office_action_plan('task.set_status',['task_id'=>$target,'status'=>'completed'],['tasks'=>[['id'=>$target,'title'=>'Transition target']]]);
transition_reject(fn()=>vp3_initiative_action_sources_prepare_v1($pdo,$loaded,$plan),409,'before witness requires the broker transaction');
$bad=$loaded;$bad['result']['initiative_tool_result']['output']['read_fingerprint']=str_repeat('0',64);
$pdo->beginTransaction();transition_reject(fn()=>vp3_initiative_action_sources_prepare_v1($pdo,$bad,$plan),409,'tampered original readonly proof is rejected before mutation');$pdo->rollBack();
transition_reject(fn()=>transition_apply($loaded,$plan,'transition-collateral',static function()use($pdo,$other):void{
    $pdo->exec("UPDATE agent_memory_items SET memory_text='Unrelated collateral edit' WHERE id=".$other);
}),409,'own mutation cannot hide an unrelated source edit in the same transaction');
transition_check(vp3_initiative_office_action_task_v1($pdo,$u,$p,$target)['status']==='open','rejected transition rolls the native status effect back');
[$result,$proof]=transition_apply($loaded,$plan,'transition-complete-valid');
$current=vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$proof);
transition_check($current['task:'.$target]['kind']==='action_tombstone'&&isset($current['task:'.$other],$current['task-list:'.$list]),'completed original primary has a native-verified tombstone while other evidence stays live');
transition_check($proof['after_revisions']['task:'.$target]===null&&in_array('task-list:'.$list,$proof['affected_refs'],true),'source transition binds both exact task removal and its changed parent list');
transition_check(!str_contains(json_encode($proof),'Untouched task')&&!str_contains(json_encode($proof),'memory_text'),'durable proof retains hashes and exact identities rather than private readonly snapshots');
$beforeWrites=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$proof);
transition_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$beforeWrites,'completed replay is read-only and never reopens or recreates a task');
$forged=$proof;$forged['after_revisions']['task:'.$other]=null;
transition_reject(fn()=>vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$forged),409,'proof cannot declare an unrelated task removed');
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','brain:{$target}','deny')");
transition_reject(fn()=>vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$proof),403,'tombstone does not restore revoked target data authority');$pdo->exec('DELETE FROM user_agent_data_rules');
$pdo->exec("UPDATE agent_memory_items SET memory_text='Later unrelated edit' WHERE id=".$other);
transition_reject(fn()=>vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$proof),409,'later unrelated source edit invalidates saved action context');

// List-primary proof must account for full unsampled members, including bytes
// beyond the model excerpt and outside the three-entry preview.
$otherRow=$pdo->query('SELECT memory_text FROM agent_memory_items WHERE id='.$other)->fetchColumn();
$newLoaded=transition_loaded(['task-list:'.$list],'tasks.lists.read','task-list:'.$list);
$reopen=office_action_plan('task.set_status',['task_id'=>$target,'status'=>'open'],['tasks'=>[['id'=>$target,'title'=>'Transition target']]]);
transition_reject(fn()=>transition_apply($newLoaded,$reopen,'transition-list-collateral',static function()use($pdo,$other,$otherRow):void{
    $pdo->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=?')->execute([$otherRow.str_repeat(' hidden',300),$other]);
}),409,'parent-list transition cannot hide edits to another member outside bounded excerpts');
transition_reject(fn()=>transition_apply($newLoaded,$reopen,'transition-list-policy-collateral',static function()use($pdo,$other):void{
    $pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','brain:{$other}','deny')");
}),409,'parent-list transition cannot absorb another member privacy revocation into its allowed source change');
$extra=office_action_apply(office_action_plan('task_list.create',['name'=>'Unrelated transition list']),'transition-unrelated-list');$extraId=$extra['office_action']['list_id'];
$membershipLoaded=transition_loaded(['task-list:'.$list,'task-list:'.$extraId],'tasks.lists.read','task-list:'.$list);
transition_reject(fn()=>transition_apply($membershipLoaded,$reopen,'transition-list-new-parent',static function()use($pdo,$u,$p,$extraId,$target):void{
    vp3_task_list_attach_task_v1($pdo,$u,$p,['id'=>$extraId],$target,'Transition target');
}),409,'a collateral new membership cannot turn an unrelated original list into an allowed parent');
[$listResult,$listProof]=transition_apply($newLoaded,$reopen,'transition-list-reopen');
transition_check(isset(vp3_initiative_action_sources_current_v1($pdo,$newLoaded,$reopen,$listResult,$listProof)['task-list:'.$list]),'legitimate member status transition preserves original list-primary readonly proof');
echo 'ACTION_SOURCES=PASS ('.$transitionChecks.' focused checks)'.PHP_EOL;
