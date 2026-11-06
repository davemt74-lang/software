<?php
declare(strict_types=1);

// Reuse the actual scoped SQLite account/policy foundation and its regression
// assertions. No list, member, permission, read or source-proof implementation
// is substituted. The foundation controls only the active Initiative binding.
require __DIR__.'/agent-initiative-office-records-v1.php';
require_once dirname(__DIR__).'/includes/agent-brain-v82.php';
require_once dirname(__DIR__).'/includes/agent-memory-lifecycle-v123.php';
require_once dirname(__DIR__).'/includes/agent-task-lifecycle-v123.php';
require_once dirname(__DIR__).'/includes/agent-initiative-task-lists-v1.php';

vp3_task_lists_schema_v1($pdo);
$pdo->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));
$pdo->exec('CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,user_id INTEGER,role TEXT,context_json TEXT)');
$taskListsChecks=0;
function task_lists_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);$GLOBALS['taskListsChecks']++;echo 'PASS '.$label.PHP_EOL;
}
function task_lists_throws(callable $work,int $code,string $label): void
{
    try{$work();}catch(Throwable $error){task_lists_check($error->getCode()===$code,$label);return;}
    throw new RuntimeException($label.' did not fail');
}
function task_lists_evidence(array $scope,?array $refs=null,?array $binding=null): array
{
    $coverage=[];$rows=vp3_initiative_task_list_capabilities_evidence_v1(db(),$scope,$binding??office_binding($scope),
        new DateTimeImmutable('2026-11-06T12:00:00Z'),$refs,$coverage);return [$rows,$coverage];
}
function task_lists_read(array $scope,string $ref,array $evidence,?array $refs=null,?array $binding=null): array
{
    return vp3_initiative_task_list_capabilities_read_v1(db(),$scope,$binding??office_binding($scope),'tasks.lists.read',
        ['primary'=>$ref,'refs'=>$refs??[$ref]],$evidence);
}
function task_lists_record(array $read,int $index=0): array
{
    return json_decode(explode("\n",$read['records'][$index]['text'],2)[1],true,32,JSON_THROW_ON_ERROR);
}
function task_lists_task(int $id,int $owner=1,?int $agent=null,string $title='',string $status='open',string $body=''): void
{
    db()->prepare('INSERT INTO agent_memory_items VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id,$owner,$agent,410,'task',$title?:'Task '.$id,$body?:'Private task detail '.$id,
            json_encode(['task_status'=>$status,'due_at'=>'2026-11-07','source_kind'=>'explicit_user']),1,'list-hash-'.$id,.8,1,'2026-11-06 12:00:00']);
}
function task_lists_list(int $id,int $owner=1,int $agent=0,string $name=''): void
{
    $name=$name?:'List '.$id;db()->prepare('INSERT INTO agent_task_lists_v1 VALUES(?,?,?,?,?,?)')
        ->execute([$id,$owner,$agent,$name,vp3_task_list_key_v1($name),'2026-11-06T12:00:00Z']);
}
function task_lists_member(int $list,int $task,string $at='2026-11-06T12:00:00Z'): void
{
    db()->prepare('INSERT INTO agent_task_list_items_v1 VALUES(?,?,?,?)')->execute([$list,$task,'member-'.$task,$at]);
}
$scope=office_scope();$named=office_scope(11);
$prepared=vp3_task_list_execute_v1($pdo,$scope['user'],$scope['principal'],['action'=>'create_list','list_name'=>'Launch readiness'],['conversation_id'=>10]);
task_lists_check(($prepared['tool_receipt']['status']??'')==='clarification_required'
    &&(int)$pdo->query('SELECT COUNT(*) FROM agent_task_lists_v1')->fetchColumn()===0,'the actual list executor asks before creating a missing list');
$pdo->prepare("INSERT INTO chat_messages VALUES(1,10,NULL,'assistant',?)")
    ->execute([json_encode(['task_context'=>$prepared['task_context']],JSON_THROW_ON_ERROR)]);
$created=vp3_task_list_execute_v1($pdo,$scope['user'],$scope['principal'],['action'=>'confirm'],['conversation_id'=>10]);
$list=(int)$pdo->query("SELECT id FROM agent_task_lists_v1 WHERE name='Launch readiness'")->fetchColumn();$ref='task-list:'.$list;
task_lists_check($list>0&&($created['tool_receipt']['status']??'')==='completed','canonical confirmation creates a real named list');
[$empty]=task_lists_evidence($scope,[$ref]);$emptyRead=task_lists_read($scope,$ref,$empty);$emptyRecord=task_lists_record($emptyRead);
task_lists_check($emptyRecord['title']==='Launch readiness'&&$emptyRecord['progress']['total']===0,'an actual empty named list is context without fabricated tasks');
task_lists_task(50001,1,null,'Confirm the supplier','open',str_repeat('Ordinary saved task detail. ',30).'FULL_LIST_BODY_SENTINEL');
task_lists_task(50002,1,null,'Approve the launch image','waiting');
task_lists_task(50003,1,null,'Verify the previous report','completed');
foreach([50001,50002,50003] as $id){
    $added=vp3_task_list_execute_v1($pdo,$scope['user'],$scope['principal'],['action'=>'add','list_name'=>'Launch readiness','task_id'=>$id],['conversation_id'=>10]);
    task_lists_check(($added['tool_receipt']['status']??'')==='completed','canonical add attaches existing task '.$id.' without copying its payload');
}
[$evidence,$coverage]=task_lists_evidence($scope);$read=task_lists_read($scope,$ref,$evidence);$record=task_lists_record($read);
task_lists_check($coverage['task_lists']==='ready'&&$evidence[$ref]['capability_tool']==='tasks.lists.read','real list context declares the exact readonly child and coverage');
task_lists_check($record['progress']===['total'=>3,'unresolved'=>2,'completed'=>1,'cancelled'=>0,
    'by_status'=>['open'=>1,'in_progress'=>0,'waiting'=>1,'completed'=>1,'cancelled'=>0]],'list progress includes actual completed and waiting members');
task_lists_check(array_column($record['items'],'id')===[50001,50002,50003]&&array_column($record['items'],'position')===[1,2,3],
    'list members retain canonical membership order rather than task recency');
task_lists_check($evidence[$ref]['task_refs']===['task:50001','task:50002','task:50003']
    &&$record['items'][0]['due_at']==='2026-11-07','list context retains canonical task identities and due fields');
task_lists_check($evidence[$ref]['items_preview'][1]['title']==='Approve the launch image'
    &&$evidence[$ref]['items_preview'][1]['status']==='waiting'&&$evidence[$ref]['progress']['unresolved']===2,
    'planner context includes actual member meaning and waiting state rather than only a list count');
$changes=(int)$pdo->query('SELECT total_changes()')->fetchColumn();task_lists_evidence($scope);task_lists_read($scope,$ref,$evidence);
task_lists_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changes,'list discovery and detailed reads perform zero schema, lifecycle, lease or domain writes');
[$later]=task_lists_evidence($scope,[$ref]);task_lists_check($later[$ref]['revision']===$evidence[$ref]['revision'],'unchanged membership is stable across repeated reads');
$pdo->exec('UPDATE agent_memory_items SET occurrence_count=occurrence_count+1,confidence=.99,last_seen_at=\'2026-11-07\' WHERE id=50001');
[$telemetry]=task_lists_evidence($scope,[$ref]);task_lists_check($telemetry[$ref]['revision']===$evidence[$ref]['revision'],'reinforcement and task recency do not become artificial list progress');
$pdo->exec("UPDATE agent_memory_items SET memory_text=memory_text||' changed after the visible excerpt' WHERE id=50001");
task_lists_throws(fn()=>task_lists_read($scope,$ref,$evidence),409,'full task edits beyond model excerpts invalidate exact list proof');
[$fresh]=task_lists_evidence($scope,[$ref]);
vp3_agent_memory_scope_set_current_v410(1,0);$updated=agent_task_v123_update($scope['user'],50002,'completed');
task_lists_check(($updated['status']??'')==='completed','the actual canonical task lifecycle changes a list member status');
task_lists_throws(fn()=>task_lists_read($scope,$ref,$fresh),409,'canonical task completion invalidates an older list preparation');
[$fresh]=task_lists_evidence($scope,[$ref]);$record=task_lists_record(task_lists_read($scope,$ref,$fresh));
task_lists_check($record['progress']['completed']===2&&$record['progress']['unresolved']===1,'a fresh list read reports verified completion progress');
$pdo->exec("UPDATE agent_task_list_items_v1 SET created_at='2026-11-05T12:00:00Z' WHERE list_id=$list AND task_id=50003");
task_lists_throws(fn()=>task_lists_read($scope,$ref,$fresh),409,'an actual canonical membership order edit invalidates a saved list source');
[$fresh]=task_lists_evidence($scope,[$ref]);$positions=array_column(task_lists_record(task_lists_read($scope,$ref,$fresh))['items'],'position','id');
task_lists_check($positions[50003]===1&&$positions[50001]===2,'fresh detail retains the exact changed canonical membership positions');
$pdo->exec("DELETE FROM agent_task_list_items_v1 WHERE list_id=$list AND task_id=50001");
task_lists_throws(fn()=>task_lists_read($scope,$ref,$fresh),409,'removing real membership invalidates prior list preparation');
[$fresh]=task_lists_evidence($scope,[$ref]);$pdo->prepare('UPDATE agent_task_lists_v1 SET name=?,name_key=? WHERE id=?')->execute(['Launch decision',vp3_task_list_key_v1('Launch decision'),$list]);
task_lists_throws(fn()=>task_lists_read($scope,$ref,$fresh),409,'a saved list name change invalidates its old source');
task_lists_list(90001,2,0,'FOREIGN LIST SECRET');task_lists_list(90002,1,11,'Named Agent list');task_lists_list(90003,1,12,'SIBLING LIST SECRET');
task_lists_task(50011,1,11,'Named Agent item');task_lists_task(50012,1,12,'SIBLING ITEM SECRET');task_lists_task(50021,2,null,'FOREIGN ITEM SECRET');
task_lists_member(90001,50021);task_lists_member(90002,50011);task_lists_member(90003,50012);
[$owned]=task_lists_evidence($scope);[$namedEvidence]=task_lists_evidence($named);
task_lists_check(!str_contains(json_encode($owned,JSON_THROW_ON_ERROR),'SECRET')&&!isset($owned['task-list:90002'])
    &&isset($namedEvidence['task-list:90002'])&&!isset($namedEvidence[$ref],$namedEvidence['task-list:90003']),
    'discovery keeps owner, System and named Agent list namespaces isolated');
[$foreign]=task_lists_evidence($scope,['task-list:90001','task-list:90002','task-list:90003']);
task_lists_check($foreign===[],'selected exact IDs cannot bypass owner or Agent scope');
task_lists_member($list,50021);task_lists_member($list,50012);
[$ownSafe]=task_lists_evidence($scope,[$ref]);$safe=task_lists_read($scope,$ref,$ownSafe);
task_lists_check(!str_contains(json_encode($safe,JSON_THROW_ON_ERROR),'SECRET')&&task_lists_record($safe)['progress']['total']===2,
    'foreign and sibling task links cannot launder task details through an owned list');
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(11,'knowledge','brain:50011','deny')");
task_lists_throws(fn()=>task_lists_read($named,'task-list:90002',$namedEvidence),409,'revoking one named Agent task removes its contribution and invalidates the old list proof');
[$denied]=task_lists_evidence($named,['task-list:90002']);$deniedRead=task_lists_read($named,'task-list:90002',$denied);
task_lists_check(task_lists_record($deniedRead)['progress']['total']===0&&!str_contains(json_encode($deniedRead),'Named Agent item'),
    'revoked task titles and counts are absent from fresh list context');
$pdo->exec("DELETE FROM user_agent_data_rules WHERE agent_id=11");
$GLOBALS['office_denied'][1]=['agent_brain.access'];task_lists_throws(fn()=>task_lists_evidence($scope),403,'Brain permission revocation stops list preparation');unset($GLOBALS['office_denied'][1]);
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');task_lists_throws(fn()=>task_lists_evidence($scope),403,'deactivated accounts cannot read saved lists');$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=11');task_lists_throws(fn()=>task_lists_evidence($named),403,'retired Agents cannot fall back to the owner list namespace');$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=11');
$bad=$scope;$bad['conversation_id']=20;task_lists_throws(fn()=>task_lists_evidence($bad),403,'account switched conversations cannot admit list sources');
$bad=$scope;$bad['principal']['viewer_user_id']=2;task_lists_throws(fn()=>task_lists_evidence($bad),403,'a supplied principal cannot replace the current list owner');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>0,'participant'=>9];
task_lists_throws(fn()=>task_lists_evidence($scope),403,'participant conversations cannot select private autonomous owner lists');unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$paused=office_binding($scope);$paused['enabled']=false;task_lists_throws(fn()=>task_lists_evidence($scope,[$ref],$paused),403,'paused Initiative cannot start new list work');
$verify=$scope;$verify['verification_only']=true;[$verified]=task_lists_evidence($verify,[$ref],$paused);
task_lists_check(isset($verified[$ref]),'saved list source review remains readonly after Initiative is paused');
task_lists_throws(fn()=>task_lists_evidence($scope,[$ref,$ref]),0,'duplicate selected list references are rejected');
task_lists_throws(fn()=>task_lists_evidence($scope,['task-list:01']),0,'malformed list references cannot become another record');
task_lists_throws(fn()=>task_lists_read($scope,$ref,$ownSafe,[$ref,$ref]),0,'a duplicate detailed list assignment is rejected');
task_lists_throws(fn()=>vp3_initiative_task_list_capabilities_read_v1($pdo,$scope,office_binding($scope),'tasks.manage',
    ['primary'=>$ref,'refs'=>[$ref]],$ownSafe),0,'no write capability can execute the list read adapter');
for($i=90100;$i<90210;$i++)task_lists_list($i,1,0);
[$bounded,$boundedCoverage]=task_lists_evidence($scope);task_lists_check(count($bounded)===4&&$boundedCoverage['task_lists']==='limited','list discovery is bounded and discloses partial account coverage');
[$selected]=task_lists_evidence($scope,[$ref]);task_lists_check(isset($selected[$ref]),'an exact selected list remains revalidatable beyond the discovery window');
task_lists_list(95000,1,0,'Long list');
for($i=51000;$i<51030;$i++){task_lists_task($i,1,null,'Long member '.$i,'open',str_repeat('🌵',700));task_lists_member(95000,$i);}
[$long]=task_lists_evidence($scope,['task-list:95000']);$longRead=task_lists_read($scope,'task-list:95000',$long);$longRecord=task_lists_record($longRead);
task_lists_check($longRecord['progress']['total']===30&&count($longRecord['items'])<30&&$longRead['coverage']['task-list:95000']==='limited'
    &&strlen($longRead['records'][0]['text'])<8000,'large multibyte lists retain complete progress and honest bounded item excerpts');
$pdo->exec("UPDATE agent_memory_items SET memory_text=memory_text||' changed hidden member' WHERE id=51029");
task_lists_throws(fn()=>task_lists_read($scope,'task-list:95000',$long),409,'a member outside the model sample still invalidates the complete source revision');
[$longFresh]=task_lists_evidence($scope,['task-list:95000']);$changedStatus=agent_task_v123_update($scope['user'],51029,'completed');
task_lists_check(($changedStatus['status']??'')==='completed','a member outside the shown sample changes through the actual canonical status executor');
task_lists_throws(fn()=>task_lists_read($scope,'task-list:95000',$longFresh),409,'a hidden member status change invalidates the entire saved list proof');
$pdo->exec("UPDATE agent_memory_items SET metadata_json='{\"task_status\":\"completed\"}' WHERE id>=51000 AND id<51028");
[$remaining]=task_lists_evidence($scope,['task-list:95000']);$remainingRead=task_lists_record(task_lists_read($scope,'task-list:95000',$remaining));
task_lists_check($remainingRead['items'][0]['id']===51028&&$remainingRead['items'][0]['position']===29
    &&$remaining['task-list:95000']['items_preview'][0]['task_ref']==='task:51028',
    'completed prefixes cannot hide the remaining useful work and canonical positions remain explicit');
$pdo->exec("UPDATE agent_memory_items SET metadata_json='{\"task_status\":\"open\",\"due_at\":[]}' WHERE id=51029");
task_lists_throws(fn()=>task_lists_evidence($scope,['task-list:95000']),503,'malformed member due metadata cannot become a false missing deadline');
$pdo->exec("UPDATE agent_memory_items SET metadata_json='{\"task_status\":\"open\"}' WHERE id=51029");
for($i=51030;$i<51201;$i++){task_lists_task($i);task_lists_member(95000,$i);}
task_lists_throws(fn()=>task_lists_evidence($scope,['task-list:95000']),503,'a list above the complete member bound is unavailable rather than falsely complete');
[$otherLists,$otherCoverage]=task_lists_evidence($scope);
task_lists_check(!isset($otherLists['task-list:95000'])&&count($otherLists)===4&&$otherCoverage['task_lists']==='limited',
    'an oversized list does not hide other usable lists or claim complete account coverage');
task_lists_throws(fn()=>task_lists_read($scope,'task-list:95000',$long),503,'membership growth beyond the bound cannot replay an old list draft');
[$beforeDelete]=task_lists_evidence($scope,[$ref]);$pdo->exec('DELETE FROM agent_task_lists_v1 WHERE id='.$list);
task_lists_throws(fn()=>task_lists_read($scope,$ref,$beforeDelete),409,'a deleted exact list cannot be replayed from its saved source proof');
task_lists_check($evidence[$ref]['revision']!==$empty[$ref]['revision'],'canonical create and add operations evolve one stable named-list situation');
$pdo->exec('ALTER TABLE agent_task_list_items_v1 RENAME TO hidden_list_items');
task_lists_throws(fn()=>task_lists_evidence($scope),503,'missing list membership storage is unavailable rather than a successful empty account');
$pdo->exec('ALTER TABLE hidden_list_items RENAME TO agent_task_list_items_v1');
echo 'AGENT_INITIATIVE_TASK_LISTS_V1=PASS ('.$taskListsChecks.' focused cases; actual Office foundation also passed)'.PHP_EOL;
