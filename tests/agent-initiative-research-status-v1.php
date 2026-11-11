<?php
declare(strict_types=1);

require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-research-status-v1.php';

$user=['id'=>1,'is_active'=>1];
scope_command($user,17,170,'off');
scope_command($user,17,170,'on');
$policy=vp3_initiative_policy_v1($user,17);
$pdo->exec('CREATE TABLE IF NOT EXISTS settings(setting_key TEXT PRIMARY KEY,setting_value TEXT)');
agent_connection_youtube_consent_schema_v1($pdo);
agent_connection_youtube_results_schema_v1($pdo);
agent_connection_youtube_set_consent_v1($pdo,1,true);
vp3_youtube_research_save_v1($pdo,1,'topic',true);
$topic='wood fired oven temperature tips';
$message=$pdo->prepare("INSERT INTO chat_messages(conversation_id,role,message,created_at) VALUES(?,'assistant','YouTube results',?)");
foreach([$topic,'wood fired oven temperature guide'] as $index=>$query){
    $cid=170+$index;
    $message->execute([$cid,gmdate('Y-m-d H:i:s',time()-600+$index)]);
    agent_connection_youtube_results_save_v1($pdo,1,$cid,(int)$pdo->lastInsertId(),[
        'query'=>$query,'expires_at'=>time()+3600,
        'videos'=>[['title'=>'Example oven tutorial','video_id'=>'abc_def-123','made_for_kids'=>false]],
    ]);
}
$ref='topic-interest:'.hash('sha256',$topic);
$coverage=[];
$sources=vp3_initiative_research_evidence_v1($pdo,['user'=>$user,'agent_id'=>17],new DateTimeImmutable('now'),[$ref],$coverage);
scope_check(($sources[$ref]['title']??null)===$topic,'related YouTube searches supply a verified non-music topic');
$goal=['binding'=>$policy,'sources'=>[$ref=>$sources[$ref]['revision']],
    'tool'=>'topic.research','primary'=>$ref,'focus'=>'MODEL FOCUS THAT MUST STAY PRIVATE','report'=>true];
$enqueue=static function(string $key,array $payload)use($pdo,$user):int{
    $hash=hash('sha256',$key);
    if(!vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',$payload,$hash))throw new RuntimeException('Research enqueue failed.');
    $stmt=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key=?');
    $stmt->execute([1,hash('sha256','initiative.prepare|'.$hash)]);
    return (int)$stmt->fetchColumn();
};
$read=static fn():array=>vp3_initiative_research_status_v1($pdo,$user,17,170);
$enqueue('research-status-silent',array_replace($goal,['report'=>false]));
scope_check($read()['specialist']===null,'private report-free work never enters Chat progress');
$run=$enqueue('research-status-visible',$goal);
$beforeScope=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;
$beforeWrites=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$pdo->exec('PRAGMA query_only=ON');
$queued=$read();
$queuedItem=['run_id'=>$run,'kind'=>'topic','state'=>'queued','subject'=>$topic];
scope_check($queued===['owner_user_id'=>1,'agent_id'=>17,'conversation_id'=>170,
    'specialist'=>$queuedItem,'specialists'=>[$queuedItem]],
    'read-only queued status exposes only the authorized source title, state and IDs');
scope_check($beforeWrites===(int)$pdo->query('SELECT total_changes()')->fetchColumn()
    &&($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null)===$beforeScope,
    'research progress is query-only and restores the caller memory scope');
$pdo->exec('PRAGMA query_only=OFF');
scope_check(vp3_initiative_research_status_v1($pdo,$user,17,171)['specialist']===null,
    'the second Chat can contribute interest without receiving this Chat’s progress');
$denied=static function(callable $action):bool{
    try{$action();}catch(RuntimeException $error){return $error->getCode()===403;}
    return false;
};
scope_check($denied(static fn()=>vp3_initiative_research_status_v1($pdo,$user,18,170))
    &&$denied(static fn()=>vp3_initiative_research_status_v1($pdo,['id'=>2,'is_active'=>1],17,170)),
    'another Agent or owner cannot read this exact private Chat');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>17,'participant'=>2,'revision'=>1];
scope_check($denied($read),'participant scope cannot read owner research progress');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$claim=scope_claim($user,$run);
$nextRun=$enqueue('research-status-second-visible',$goal);
$simultaneous=$read();
$running=$simultaneous['specialist'];
scope_check($running['state']==='running'&&$running['run_id']===$run&&strtotime($running['lease_expires_at'])>time(),
    'running progress requires the canonical worker’s unexpired lease');
scope_check(array_column($simultaneous['specialists'],'run_id')===[$run,$nextRun]
    &&array_column($simultaneous['specialists'],'state')===['running','queued'],
    'simultaneous Research assignments keep separate run IDs and verified states');
$pdo->prepare("UPDATE agent_workflow_runs SET status='cancelled' WHERE id=?")->execute([$nextRun]);
$lease=$pdo->prepare('UPDATE agent_workflow_runs SET lease_expires_at=? WHERE id=?');
$lease->execute([gmdate('Y-m-d H:i:s',time()-1),$run]);
scope_check($read()['specialist']===null,'expired execution disappears without inventing progress or failure');
$lease->execute([gmdate('Y-m-d H:i:s',time()+150),$run]);
$failed=vp3_initiative_execute_v1($pdo,$user,$claim,null,['search'=>static fn():array=>['ok'=>false]]);
scope_check(!$failed['ok']&&$read()['specialist']===['run_id'=>$run,'kind'=>'topic','state'=>'failed','subject'=>$topic],
    'a real terminal research receipt is briefly visible with no transport details');
$age=$pdo->prepare('UPDATE agent_workflow_actions SET completed_at=? WHERE run_id=?');
$age->execute([gmdate('Y-m-d H:i:s',time()-121),$run]);
scope_check($read()['specialist']===null,'a terminal failure disappears after its short status window');
$run=$enqueue('research-status-complete',$goal);
$completed=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$run),null,
    ['search'=>static fn():array=>['ok'=>true,'results'=>[]]]);
$pending=$read();
scope_check($completed['ok']&&$pending['specialist']===null&&count($pending['specialists'])===1
    &&$pending['specialists'][0]['run_id']===$run&&$pending['specialists'][0]['state']==='ready'
    &&!isset($pending['specialists'][0]['message_id']),
    'verified completion awaiting delivery has no Chat result link and preserves the legacy alias');
$ageReports->execute([$oldReportAt]);
scope_check(vp3_initiative_deliver_pending_v1($pdo,$user,17),'the current report delivers the verified Research result');
$delivered=$read()['specialists'][0]??[];
$message=$pdo->prepare("SELECT id FROM chat_messages WHERE conversation_id=170 AND role='assistant' AND id=?");
$message->execute([$delivered['message_id']??0]);
scope_check($delivered['run_id']===$run&&$delivered['state']==='completed'&&(int)$message->fetchColumn()===$delivered['message_id'],
    'a delivered assignment links to its exact saved Chat result');
$receipt=$pdo->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');
$receipt->execute([$run]);$verifiedReceipt=$receipt->fetchColumn();
$setReceipt=$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE run_id=?');
$setReceipt->execute(['{}',$run]);
scope_check($read()['specialists']===[],'an inconsistent worker receipt cannot supply a completed result link');
$setReceipt->execute([$verifiedReceipt,$run]);
$ageReports->execute([$oldReportAt]);
scope_check($read()['specialists']===[],'old delivered results remain in Chat instead of replaying as status');
$enqueue('research-status-revoked',$goal);
scope_check($read()['specialist']!==null,'a fresh reportable research run is eligible under current consent');
vp3_youtube_research_save_v1($pdo,1,'topic',false);
scope_check($read()['specialist']===null,'turning off topic research clears its visible progress');
echo "AGENT_INITIATIVE_RESEARCH_STATUS_V1=PASS\n";
