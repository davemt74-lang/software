<?php
declare(strict_types=1);

require __DIR__.'/fixtures/cloud-mission-isolated-mysql-v1.php';
require __DIR__.'/fixtures/cloud-mission-schedules-v1.php';
require dirname(__DIR__).'/includes/initiative-work-mission-v1.php';

function mission_brief_check(bool $condition,string $label): void
{
    if(!$condition)throw new RuntimeException($label);
    echo "PASS $label\n";
}

function mission_brief_rejects(callable $work,int $status,string $label): void
{
    try{$work();}catch(DomainException $e){mission_brief_check($e->getCode()===$status,$label.' ('.$e->getCode().')');return;}
    throw new RuntimeException('Expected rejection: '.$label);
}

function mission_brief_seed(PDO $pdo,string $key,array $tools,bool $finish): array
{
    $user=['id'=>1];
    $m=cloud_mission_prepare_v1($pdo,$user,['objective'=>'Compare fermentation options '.$key,'thread_id'=>11,'request_id'=>'brief-'.$key]);
    $draft=$m['chat_task']['draft'];
    $draft['assignments'][0]=array_replace($draft['assignments'][0],['tools'=>$tools,'max_calls'=>count($tools),
        'web'=>['query'=>'fermentation research','domains'=>['research.example.org'],'max_pages'=>1]]);
    cloud_mission_configure_v1($pdo,$user,['mission_id'=>$m['id'],'request_id'=>cloud_mission_uuid_v1('review-'.$key),
        'expected_revision'=>0,'confirmed'=>true,'assignments'=>$draft]);
    cloud_mission_service_v1($pdo,$user,'tools.start',['mission_id'=>$m['id'],'expected_revision'=>1,'confirmed'=>true]);
    if(!$finish)return $m;
    $transport=[
        'search'=>static fn()=>['ok'=>true,'results'=>[
            ['url'=>'https://research.example.org/study','title'=>'Study'],
            ['url'=>'https://research.example.org/discovery','title'=>'Unread candidate'],
        ]],
        'get'=>static function(){++$GLOBALS['webReads'];return ['status'=>200,'content_type'=>'text/html',
            'body'=>'<title>Study</title><main>'.str_repeat('The controlled study reports twenty hours of cold fermentation. ',10).'</main>'];},
    ];
    cloud_mission_tick_v1($pdo,$user,$m['id'],null,$transport);
    return cloud_mission_tick_v1($pdo,$user,$m['id'],null,$transport);
}

$GLOBALS['webReads']=0;
$pdo=db();
$pdo->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER)');
$pdo->exec('INSERT INTO chat_conversations VALUES(11,1,7),(12,1,7),(13,1,NULL),(14,2,7)');
$mission=mission_brief_seed($pdo,'verified',['web.search','web.read'],true);
$pending=mission_brief_seed($pdo,'pending',['web.search','web.read'],false);
$discovery=mission_brief_seed($pdo,'discovery',['web.search'],true);
$scope=['agent_id'=>7,'conversation_id'=>11,'user'=>['id'=>1]];
$input=['source_id'=>$mission['id'],'request_id'=>hash('sha256','verified')];
$preview=initiative_work_mission_preview_v1($pdo,1,$scope,$input);
mission_brief_check($mission['status']==='completed'&&$preview['details']['coverage']===['captured_sources'=>2,'read_sources'=>1]
    &&strlen($preview['revision'])===64&&!str_contains(json_encode($preview),'twenty hours'),'preview reports saved coverage without returning excerpts');
mission_brief_check(!table_exists('agent_initiative_mission_briefs_v1'),'preview leaves no private output or work row');
mission_brief_check(array_column(initiative_work_mission_sources_v1($pdo,1,$scope),'source_id')===[$mission['id']],'picker returns only eligible completed Missions');
mission_brief_rejects(fn()=>initiative_work_mission_preview_v1($pdo,1,array_replace($scope,['conversation_id'=>12]),$input),404,'different conversation');
mission_brief_rejects(fn()=>initiative_work_mission_preview_v1($pdo,1,array_replace($scope,['agent_id'=>0]),$input),404,'different Agent');
mission_brief_rejects(fn()=>initiative_work_mission_preview_v1($pdo,2,array_replace($scope,['user'=>['id'=>2]]),$input),404,'different owner');
mission_brief_rejects(fn()=>initiative_work_mission_preview_v1($pdo,1,$scope,['source_id'=>$pending['id']]),409,'unfinished Mission');
mission_brief_rejects(fn()=>initiative_work_mission_preview_v1($pdo,1,$scope,['source_id'=>$discovery['id']]),422,'discovery without a captured excerpt');

$quote='The controlled study reports twenty hours of cold fermentation.';
$calls=0;
$answer=static fn(string $source,string $quote): array=>['ok'=>true,'answer'=>json_encode([
    'findings'=>[['source_id'=>$source,'quote'=>$quote]],
    'recommendation'=>'Consider a controlled cold fermentation test.',
    'uncertainty'=>'This excerpt does not establish effects for different recipes.',
    'next_step'=>'Compare the recipe conditions with the study.',
],JSON_THROW_ON_ERROR)];
$model=static function(string $instruction,array $context)use(&$calls,$answer,$quote): array {
    $calls++;
    mission_brief_check(count($context)===1&&str_contains($context[0]['text'],$quote)&&!str_contains($context[0]['text'],'Unread candidate')
        &&str_contains($instruction,'exact, contiguous'),'model receives only exact captured excerpt');
    return $answer($context[0]['source'],$quote);
};
mission_brief_rejects(fn()=>initiative_work_mission_create_v1($pdo,1,$scope,$input,$preview,
    fn($instruction,$context)=>$answer('web:unknown',$quote)),503,'invented citation');
mission_brief_rejects(fn()=>initiative_work_mission_create_v1($pdo,1,$scope,$input,$preview,
    fn($instruction,$context)=>$answer($context[0]['source'],'Invented numerical finding from a plausible source.')),503,'fabricated text for a valid source');
$reentrant=static function(string $instruction,array $context)use($pdo,$scope,$input,$preview,$model): array {
    mission_brief_rejects(fn()=>initiative_work_mission_create_v1($pdo,1,$scope,$input,$preview,$model),409,'same request cannot claim a second active generation');
    return $model($instruction,$context);
};
$output=initiative_work_mission_create_v1($pdo,1,$scope,$input,$preview,$reentrant);
$replay=initiative_work_mission_create_v1($pdo,1,$scope,$input,$preview,$model);
mission_brief_check($output===$replay&&$calls===1&&$GLOBALS['webReads']===1,'confirmed replay returns one durable result without another inference or page request');
mission_brief_check($output['findings'][0]['quote']===$quote&&$output['findings'][0]['text']===$quote
    &&$output['sources'][0]['url']==='https://research.example.org/study'&&!isset($output['sources'][0]['excerpt'])
    &&initiative_work_mission_read_v1($pdo,1,$scope,$output)===$output
    &&initiative_work_mission_receipt_v1($pdo,1,$scope,$output['request_id'])===$output,'private output includes verified quotes and safe source metadata');
mission_brief_rejects(fn()=>initiative_work_mission_receipt_v1($pdo,2,array_replace($scope,['user'=>['id'=>2]]),$output['request_id']),404,'durable receipt remains owner-private');
$pdo->exec('UPDATE chat_conversations SET user_agent_id=NULL WHERE id=11');
mission_brief_rejects(fn()=>initiative_work_mission_receipt_v1($pdo,1,$scope,$output['request_id']),404,'durable receipt rechecks current conversation binding');
$pdo->exec('UPDATE chat_conversations SET user_agent_id=7 WHERE id=11');
mission_brief_check((int)$pdo->query('SELECT COUNT(*) FROM agent_initiative_mission_briefs_v1 WHERE output_json IS NOT NULL')->fetchColumn()===1,
    'failed attempts leave no durable output');

$changedInput=['source_id'=>$mission['id'],'request_id'=>hash('sha256','changed')];
$revoke=static function(string $instruction,array $context)use($pdo,$mission,$model): array {
    $answer=$model($instruction,$context);
    $pdo->prepare("UPDATE agent_cloud_mission_web_v1 SET status='failed' WHERE mission_id=?")->execute([$mission['id']]);
    return $answer;
};
mission_brief_rejects(fn()=>initiative_work_mission_create_v1($pdo,1,$scope,$changedInput,$preview,$revoke),409,'source revoked during inference cannot publish');
mission_brief_rejects(fn()=>initiative_work_mission_read_v1($pdo,1,$scope,$output),409,'revoked source hides the previously saved brief');
mission_brief_check((int)$pdo->query('SELECT COUNT(*) FROM agent_initiative_mission_briefs_v1')->fetchColumn()===1,'revocation produces no extra output');
$pdo->prepare("UPDATE agent_cloud_mission_web_v1 SET status='completed',evidence_hash=? WHERE mission_id=?")->execute([str_repeat('a',64),$mission['id']]);
mission_brief_rejects(fn()=>initiative_work_mission_create_v1($pdo,1,$scope,$input,$preview,$model),409,'stale review cannot replay against a changed source revision');
$pdo->prepare("UPDATE agent_workflow_receipts SET result_json='{}' WHERE owner_user_id=? AND run_id=?")->execute([1,$mission['job_id']]);
mission_brief_rejects(fn()=>initiative_work_mission_preview_v1($pdo,1,$scope,$input),409,'Mission action without matching canonical completed receipt is ineligible');
echo "INITIATIVE_WORK_MISSION_V1=PASS\n";
