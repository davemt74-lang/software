<?php
declare(strict_types=1);

// Real canonical retrieval, Mission queue, Workbench preview and receipts; only account/policy adapters are fixtures.
require __DIR__.'/fixtures/cloud-mission-isolated-mysql-v1.php';
require __DIR__.'/fixtures/cloud-mission-schedules-v1.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/agent-brain-owner-context-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-tools-v1.php';

function tool_check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function tool_reject(callable $work,int $code,string $label): void {
    try{$work();}catch(RuntimeException|InvalidArgumentException $e){tool_check($e->getCode()===$code,$label);return;}
    throw new RuntimeException('Expected rejection: '.$label);
}
function user_agent_principal_v236(array $user,?array $agent): array {
    return ['kind'=>$agent?'user_agent':'system','owner_user_id'=>(int)$user['id'],'agent_id'=>(int)($agent['id']??0)];
}
function vp3_agent_memory_scope_current_v410(array $user): int {
    $scope=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??[];
    return ($scope['user_id']??null)===$user['id']?(int)($scope['user_agent_id']??0):0;
}
function vp3_agent_memory_scope_sql_v410(?int $agent,string $alias=''): array {
    return $agent>0?['user_agent_id=?',[$agent]]:['user_agent_id IS NULL',[]];
}
function vp3_initiative_access_v1(PDO $pdo,array $user,array $binding): array {
    $q=$pdo->prepare('SELECT binding_json FROM initiative_tool_policies WHERE owner=? AND agent=?');
    $q->execute([$user['id'],$binding['agent_id']??0]);$saved=$q->fetchColumn();
    if(!$saved||json_decode($saved,true)!==$binding||empty($binding['enabled'])
        ||vp3_agent_memory_scope_current_v410($user)!==($binding['agent_id']??0))throw new RuntimeException('Initiative permission changed.',403);
    return $user;
}
function vp3_initiative_current_sources_v1(PDO $pdo,array $user,array $sources,bool $screen,array $canonical=[]): array {
    $current=[];
    foreach($sources as $ref=>$revision){
        if(str_starts_with($ref,'workbench-')){
            $policy=$pdo->prepare('SELECT binding_json FROM initiative_tool_policies WHERE owner=? AND agent=?');
            $policy->execute([$user['id'],vp3_agent_memory_scope_current_v410($user)]);$binding=json_decode($policy->fetchColumn(),true);
            $coverage=[];$row=vp3_initiative_tools_evidence_v1($pdo,$user,$binding,[$ref],$coverage)[$ref]??null;
        }else{
            $q=$pdo->prepare('SELECT source_json FROM initiative_tool_sources WHERE owner=? AND ref=?');$q->execute([$user['id'],$ref]);
            $saved=$q->fetchColumn();$row=$saved?json_decode($saved,true):null;
        }
        if(!$row||$row['revision']!==$revision)throw new RuntimeException('Source changed.',409);
        $current[$ref]=$row;
    }
    return $current;
}
function vp3_initiative_owner_lock_v1(PDO $pdo,array $user): void {
    $q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$user['id']]);
    if(!$q->fetchColumn())throw new RuntimeException('Owner changed.',403);
}
function agent_brain_schema_ready(): bool {return table_exists('agent_memory_items');}
function agent_brain_normalize(string $text): string {return strtolower(trim($text));}
function url(string $path): string {return $path;}
function user_data_policy_can_use_v236(PDO $pdo,array $principal,int $owner,string $domain,string $id,bool $write): bool {
    return !in_array($id,$GLOBALS['toolDeniedSources']??[],true);
}

$pdo=db();$user=['id'=>1,'is_active'=>1];
$pdo->exec("CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);
    INSERT INTO chat_conversations VALUES(11,1,7),(12,1,NULL),(13,2,7);
    CREATE TABLE initiative_tool_policies(owner INTEGER,agent INTEGER,binding_json TEXT,PRIMARY KEY(owner,agent));
    CREATE TABLE initiative_tool_sources(owner INTEGER,ref VARCHAR(190),source_json TEXT,PRIMARY KEY(owner,ref));
    CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,is_active INTEGER,memory_type TEXT,
        subject TEXT,memory_text TEXT,occurrence_count INTEGER,last_seen_at TEXT,confidence REAL);
    INSERT INTO agent_memory_items VALUES(1,1,7,1,'task','Cold fermentation launch','Finish the product image before promoting the new service.',1,'2026-11-01',1),
        (2,1,NULL,1,'note','Cold fermentation SYSTEM SECRET','System-only private note.',1,'2026-11-01',1),
        (3,2,7,1,'note','Cold fermentation OWNER SECRET','Other owner private note.',1,'2026-11-01',1)");
$binding=['enabled'=>true,'agent_id'=>7,'conversation_id'=>11,'revision'=>str_repeat('a',32),'knowledge_scope'=>['mode'=>'all','folder_id'=>0]];
$savePolicy=static function(array $value)use($pdo):void {
    $pdo->prepare('DELETE FROM initiative_tool_policies WHERE owner=1 AND agent=7')->execute();
    $pdo->prepare('INSERT INTO initiative_tool_policies VALUES(1,7,?)')->execute([json_encode($value,JSON_THROW_ON_ERROR)]);
};
$savePolicy($binding);
$anchor=['ref'=>'transcript:44','kind'=>'transcript','title'=>'Launch call follow-up','detail'=>'Review cold fermentation product launch actions.',
    'revision'=>str_repeat('b',64),'url'=>'/artist-listening.php?session=44'];
$saveAnchor=static function(array $value)use($pdo):void {
    $pdo->prepare('DELETE FROM initiative_tool_sources WHERE owner=1 AND ref=?')->execute([$value['ref']]);
    $pdo->prepare('INSERT INTO initiative_tool_sources VALUES(1,?,?)')->execute([$value['ref'],json_encode($value,JSON_THROW_ON_ERROR)]);
};
$saveAnchor($anchor);$evidence=[$anchor['ref']=>$anchor];
$job=['tool'=>'brain.read','primary'=>$anchor['ref'],'refs'=>[$anchor['ref']],'focus'=>'cold fermentation','report'=>true];
$report=static function(string $prompt,string $protocol): string {
    $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
    tool_check($protocol==='vp3.initiative-draft.v1'&&isset($input['tool_result'])
        &&!str_contains($prompt,'SYSTEM SECRET')&&!str_contains($prompt,'OWNER SECRET'),'report receives real scoped tool evidence and exact tool state');
    return json_encode(['draft'=>'I reviewed the saved evidence. '.str_repeat('The launch still needs a product image and an owner-reviewed promotion plan. ',12)],JSON_THROW_ON_ERROR);
};
$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=['user_id'=>1,'user_agent_id'=>7];
$catalog=vp3_initiative_tools_catalog_v1($pdo,$user,$binding,$evidence);
tool_check($catalog['brain.read']['ready']&&$catalog['knowledge.search']['ready']&&$catalog['missions.prepare']['mode']==='review'
    &&!isset($catalog['images.generate']),'catalog exposes connected reads and review, never a paid image bypass');
$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=['user_id'=>99,'user_agent_id'=>100];
$brain=vp3_initiative_tool_execute_v1($pdo,$user,$binding,$job,$evidence,hash('sha256','brain'),$report);
tool_check(strlen($brain['draft'])>490&&$brain['tool_receipt']['capability']==='brain.read'
    &&$brain['tool_receipt']['count']===1&&array_keys($brain['output']['source_revisions'])===['brain:1'],
    'actual named-Agent Brain read returns a substantive report and exact verified record');
tool_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===['user_id'=>99,'user_agent_id'=>100],'tool restores the caller memory namespace');
vp3_initiative_tools_result_current_v1($pdo,$user,$binding,$job,$brain);
tool_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===['user_id'=>99,'user_agent_id'=>100],'delayed result verification uses no inference and restores the incoming namespace');

$knowledge=vp3_initiative_tool_execute_v1($pdo,$user,$binding,array_replace($job,['tool'=>'knowledge.search']),$evidence,hash('sha256','knowledge'),$report);
tool_check($knowledge['tool_receipt']['status']==='read_complete'&&$knowledge['tool_receipt']['count']===1
    &&count($knowledge['output']['source_revisions'])===1,'canonical Cloud Knowledge search returns an actual source-backed result');
$knowledgeJob=array_replace($job,['tool'=>'knowledge.search']);
vp3_initiative_tools_result_current_v1($pdo,$user,$binding,$knowledgeJob,$knowledge);
$oldChunk=$pdo->query('SELECT chunk_text FROM knowledge_chunks ORDER BY id LIMIT 1')->fetchColumn();
$pdo->exec("UPDATE knowledge_chunks SET chunk_text='Withdrawn source no longer matches the requested query.'");
tool_reject(fn()=>vp3_initiative_tools_result_current_v1($pdo,$user,$binding,$knowledgeJob,$knowledge),409,
    'Knowledge changes while the owner is away withhold the saved tool result without another model call');
$pdo->prepare('UPDATE knowledge_chunks SET chunk_text=?')->execute([$oldChunk]);
$knowledgeId=(int)$pdo->query('SELECT knowledge_id FROM knowledge_chunks ORDER BY id LIMIT 1')->fetchColumn();
$firstChunk=(int)$pdo->query('SELECT id FROM knowledge_chunks ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare('INSERT INTO knowledge_chunks(knowledge_id,chunk_index,chunk_text) VALUES(?,1,?)')
    ->execute([$knowledgeId,'Cold fermentation launch evidence from another section of the same document.']);
$extraChunk=(int)$pdo->lastInsertId();
$extraDocuments=[];
for($index=0;$index<3;$index++)$extraDocuments[]=(int)personal_knowledge_store($user,'chunk-diversity-'.$index,
    'Cold fermentation guide '.$index,'Cold fermentation research comparison for the launch.');
$multi=vp3_initiative_tool_execute_v1($pdo,$user,$binding,$knowledgeJob,$evidence,hash('sha256','multiple-chunks'),$report);
tool_check($multi['tool_receipt']['count']===5&&count($multi['output']['source_revisions'])===4,
    'multiple cited chunks share one document reference with a combined full-chunk revision');
$pdo->prepare('UPDATE knowledge_chunks SET chunk_text=? WHERE id=?')->execute(['Cold fermentation first section changed while the second stayed current.',$firstChunk]);
tool_reject(fn()=>vp3_initiative_tools_result_current_v1($pdo,$user,$binding,$knowledgeJob,$multi),409,
    'changing any cited chunk invalidates the delayed result, even when another chunk from that document is unchanged');
$pdo->prepare('UPDATE knowledge_chunks SET chunk_text=? WHERE id=?')->execute([$oldChunk,$firstChunk]);
$pdo->prepare('DELETE FROM knowledge_chunks WHERE id=?')->execute([$extraChunk]);
foreach($extraDocuments as $id){$pdo->prepare('DELETE FROM knowledge_chunks WHERE knowledge_id=?')->execute([$id]);$pdo->prepare('DELETE FROM knowledge_items WHERE id=?')->execute([$id]);}
$off=array_replace($binding,['knowledge_scope'=>['mode'=>'off','folder_id'=>0],'revision'=>str_repeat('c',32)]);$savePolicy($off);
tool_reject(fn()=>vp3_initiative_tool_execute_v1($pdo,$user,$off,array_replace($job,['tool'=>'knowledge.search']),$evidence,hash('sha256','off'),$report),403,'saved Knowledge off cannot expand to all in the background');
tool_reject(fn()=>vp3_initiative_tools_result_current_v1($pdo,$user,$binding,$knowledgeJob,$knowledge),403,
    'a saved old-policy Knowledge result cannot be delivered after policy revocation');
$savePolicy($binding);
$GLOBALS['toolDeniedSources']=['brain:1'];
$empty=vp3_initiative_tool_execute_v1($pdo,$user,$binding,$job,$evidence,hash('sha256','denied-source'),$report);
tool_check($empty['tool_receipt']['status']==='no_matches'&&$empty['output']['source_revisions']===[],'per-Agent data policy is preserved by private recall');
vp3_initiative_tools_result_current_v1($pdo,$user,$binding,$job,$empty);
unset($GLOBALS['toolDeniedSources']);
tool_reject(fn()=>vp3_initiative_tools_result_current_v1($pdo,$user,$binding,$job,$empty),409,
    'a saved no-matches report becomes stale when authorized results are now present');
$changeBrain=static function(string $prompt,string $protocol)use($pdo,$report):string {
    $answer=$report($prompt,$protocol);$pdo->exec("UPDATE agent_memory_items SET memory_text='Changed during analysis.' WHERE id=1");return $answer;
};
tool_reject(fn()=>vp3_initiative_tool_execute_v1($pdo,$user,$binding,$job,$evidence,hash('sha256','changed-brain'),$changeBrain),409,'Brain changes during report inference fence stale output');
tool_reject(fn()=>vp3_initiative_tools_result_current_v1($pdo,$user,$binding,$job,$brain),409,
    'Brain changes during an absent-owner delay also fence the old private report');
tool_reject(fn()=>vp3_initiative_tool_execute_v1($pdo,$user,array_replace($binding,['conversation_id'=>12]),$job,$evidence,hash('sha256','wrong-agent'),$report),403,'an exact conversation cannot be borrowed from the System Agent');
tool_reject(fn()=>vp3_initiative_tool_execute_v1($pdo,['id'=>2,'is_active'=>1],$binding,$job,$evidence,hash('sha256','wrong-owner'),$report),403,'another owner cannot reuse the binding');
tool_reject(fn()=>vp3_initiative_tool_execute_v1($pdo,$user,$binding,array_replace($job,['tool'=>'images.generate']),$evidence,hash('sha256','image-bypass'),$report),403,'unapproved image generation cannot be selected as a background tool');

$missionJob=array_replace($job,['tool'=>'missions.prepare','focus'=>'Research promotion options for the product launch']);
$request=hash('sha256','promotion-specialist');
$proposal=vp3_initiative_tool_execute_v1($pdo,$user,$binding,$missionJob,$evidence,$request,$report);
$replay=vp3_initiative_tool_execute_v1($pdo,$user,$binding,$missionJob,$evidence,$request,$report);
$q=$pdo->prepare('SELECT COUNT(*) FROM agent_cloud_missions_v1 WHERE request_id=?');$q->execute(['initiative-'.$request]);
tool_check((int)$q->fetchColumn()===1&&$proposal['output']===$replay['output']&&$proposal['output']['status']==='approval_pending',
    'specialist delegation saves one real canonical Mission review and is idempotent');
$m=cloud_mission_row_v1($pdo,$user,$proposal['output']['mission_id']);$run=cloud_mission_run_v1($pdo,$user,$m);
$config=json_decode($m['config_json'],true,16,JSON_THROW_ON_ERROR);
tool_check($run['status']==='approval_pending'&&(int)$m['agent_id']===7&&$config['thread_id']===11
    &&array_column($config['assignments'],'tools')===[[],[]]&&$m['review_until']==='',
    'prepared research has exact named Chat binding and receives no implicit tool grant, approval or execution');
vp3_initiative_tools_result_current_v1($pdo,$user,$binding,$missionJob,$proposal);
$pdo->prepare("UPDATE agent_workflow_runs SET status='cancelled' WHERE id=?")->execute([$run['id']]);
tool_reject(fn()=>vp3_initiative_tools_result_current_v1($pdo,$user,$binding,$missionJob,$proposal),409,
    'a cancelled specialist review cannot return as a still-pending proposal after a delay');
$pdo->prepare("UPDATE agent_workflow_runs SET status='approval_pending' WHERE id=?")->execute([$run['id']]);
$before=(int)$pdo->query('SELECT COUNT(*) FROM agent_cloud_missions_v1')->fetchColumn();
$revoke=static function(string $prompt,string $protocol)use($report,$savePolicy,$binding):string {
    $answer=$report($prompt,$protocol);$savePolicy(array_replace($binding,['enabled'=>false]));return $answer;
};
tool_reject(fn()=>vp3_initiative_tool_execute_v1($pdo,$user,$binding,$missionJob,$evidence,hash('sha256','revoked-mission'),$revoke),403,
    'revocation during preparation prevents the specialist proposal write');
tool_check((int)$pdo->query('SELECT COUNT(*) FROM agent_cloud_missions_v1')->fetchColumn()===$before,'revocation creates no extra Mission');
$savePolicy($binding);

// A completed actual Cloud Mission is eligible for a Workbench brief review, never automatic creation.
$seed=cloud_mission_prepare_v1($pdo,$user,['objective'=>'Compare product launch evidence','thread_id'=>11,'agent_id'=>7,'request_id'=>'workbench-evidence']);
$assignments=$seed['chat_task']['draft'];
$assignments['assignments'][0]=array_replace($assignments['assignments'][0],['tools'=>['web.search','web.read'],'max_calls'=>2,
    'web'=>['query'=>'product launch evidence','domains'=>['research.example.org'],'max_pages'=>1]]);
cloud_mission_configure_v1($pdo,$user,['mission_id'=>$seed['id'],'request_id'=>cloud_mission_uuid_v1('workbench-review'),'expected_revision'=>0,'confirmed'=>true,'assignments'=>$assignments]);
cloud_mission_service_v1($pdo,$user,'tools.start',['mission_id'=>$seed['id'],'expected_revision'=>1,'confirmed'=>true]);
$web=['search'=>static fn()=>['ok'=>true,'results'=>[['url'=>'https://research.example.org/study','title'=>'Study']]],
    'get'=>static fn()=>['status'=>200,'content_type'=>'text/html','body'=>'<title>Study</title><main>'.str_repeat('The launch study describes an owner-reviewed product promotion plan. ',10).'</main>']];
cloud_mission_tick_v1($pdo,$user,$seed['id'],null,$web);cloud_mission_tick_v1($pdo,$user,$seed['id'],null,$web);
$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=['user_id'=>1,'user_agent_id'=>7];
$coverage=[];$reviews=vp3_initiative_tools_evidence_v1($pdo,$user,$binding,null,$coverage);
tool_check($coverage['workbench_screen']==='forbidden'&&!array_filter(array_keys($reviews),static fn(string $ref):bool=>str_starts_with($ref,'workbench-screen:')),
    'background screen discovery remains forbidden without the separate saved-note opt-in and System scope');
$ref='workbench-mission:'.$seed['id'];
tool_check(isset($reviews[$ref])&&$reviews[$ref]['review_tool']==='mission'&&strlen($reviews[$ref]['revision'])===64,
    'existing Workbench preview supplies a real revision-bound completed Mission source');
$review=vp3_initiative_tool_execute_v1($pdo,$user,$binding,['tool'=>'workbench.mission.review','primary'=>$ref,'refs'=>[$ref],
    'focus'=>'Prepare an owner-reviewed source-quoted decision brief','report'=>true],[$ref=>$reviews[$ref]],hash('sha256','review'),$report);
tool_check($review['output']['status']==='review_required'&&$review['output']['review_action']===
    ['tool'=>'mission','source_id'=>$seed['id'],'source_revision'=>$reviews[$ref]['revision']]
    &&!table_exists('agent_initiative_mission_briefs_v1'),'planner review result opens the exact existing Workbench and does not bypass its create confirmation');
vp3_initiative_tools_result_current_v1($pdo,$user,$binding,['tool'=>'workbench.mission.review','primary'=>$ref],$review);
$pdo->prepare("UPDATE agent_workflow_receipts SET result_json='{}' WHERE run_id=?")->execute([$seed['job_id']]);
tool_reject(fn()=>vp3_initiative_tools_result_current_v1($pdo,$user,$binding,['tool'=>'workbench.mission.review','primary'=>$ref],$review),409,
    'withdrawn completed-Mission evidence removes a delayed Workbench review action');
echo "AGENT_INITIATIVE_TOOLS_V1=PASS\n";
