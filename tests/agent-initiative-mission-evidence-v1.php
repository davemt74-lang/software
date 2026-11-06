<?php
declare(strict_types=1);

// Canonical Mission preparation, workers, action/receipt completion and saved
// public-source authority are real; the surrounding Initiative policy is isolated.
$fixtureFile=tempnam(sys_get_temp_dir(),'vp3-mission-capability-');
if($fixtureFile===false)throw new RuntimeException('Could not create the isolated fixture.');
putenv('VP3_CLOUD_SCHEDULE_DSN=sqlite:'.$fixtureFile);
register_shutdown_function(static function()use($fixtureFile):void{@unlink($fixtureFile);});
require __DIR__.'/fixtures/cloud-mission-schedules-v1.php';

function vp3_participant_scope_v1(): ?array {return $GLOBALS['missionCapabilityParticipant']??null;}
function url(string $path): string {return $path;}
function vp3_initiative_capability_scope_v1(PDO $pdo,array $scope,array $binding): array
{
    $aid=$scope['agent_id']??null;$cid=$scope['conversation_id']??null;$user=$scope['user']??[];
    $principal=$scope['principal']??[];$owner=(int)($user['id']??0);
    if($owner<1||!is_int($aid)||$aid<0||!is_int($cid)||$cid<1||vp3_participant_scope_v1()!==null
        ||($binding['agent_id']??null)!==$aid||($binding['conversation_id']??null)!==$cid
        ||(($binding['enabled']??null)!==true&&($scope['verification_only']??null)!==true)
        ||($principal['kind']??null)!==($aid>0?'user_agent':'system')
        ||($principal['agent_id']??null)!==$aid
        ||($aid>0&&($principal['owner_user_id']??null)!==$owner)
        ||(isset($principal['viewer_user_id'])&&$principal['viewer_user_id']!==$owner)){
        throw new RuntimeException('Initiative scope changed.',403);
    }
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],null);
    if(!has_permission('account.access',$live))throw new RuntimeException('Account access changed.',403);
    $scope['user']=$live;return $scope;
}
require dirname(__DIR__).'/includes/agent-initiative-mission-evidence-v1.php';

class MissionCapabilityReadPDO extends ScheduledMissionPDO
{
    public int $writeAttempts=0;
    public bool $failMissionRead=false;
    private function readQuery(string $query): void
    {
        if(!preg_match('/\A\s*(SELECT|PRAGMA)\b/i',$query)){
            ++$this->writeAttempts;throw new RuntimeException('A read-only adapter attempted a write.');
        }
        if($this->failMissionRead&&str_contains($query,'FROM agent_cloud_missions_v1'))
            throw new PDOException('Fixture transient database outage.');
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $this->readQuery($query);return parent::prepare($query,$options);
    }
    public function exec(string $statement): int|false
    {
        $this->readQuery($statement);return parent::exec($statement);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->readQuery($query);
        return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
    }
}

$cases=0;
function mission_capability_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);++$GLOBALS['cases'];echo "PASS $label\n";
}
function mission_capability_reject(callable $read,int $code,string $label): void
{
    try{$read();}catch(RuntimeException|InvalidArgumentException|DomainException $e){
        mission_capability_check($e->getCode()===$code,$label);return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}
function mission_capability_seed(PDO $pdo,string $key,array $tools=['web.search','web.read'],bool $finish=true,bool $multiple=false): array
{
    $user=['id'=>1];
    $m=cloud_mission_prepare_v1($pdo,$user,['objective'=>'Compare independently saved evidence '.$key,
        'thread_id'=>11,'agent_id'=>7,'request_id'=>'mission-capability-'.$key]);
    $assignments=$m['chat_task']['draft'];
    $assignments['assignments'][0]=array_replace($assignments['assignments'][0],[
        'tools'=>$tools,'max_calls'=>count($tools),
        'web'=>['query'=>'controlled fermentation research','domains'=>['research.example.org'],'max_pages'=>1],
    ]);
    if($multiple)foreach($assignments['assignments'] as &$entry){
        $entry=array_replace($entry,['tools'=>['web.search','web.read'],'max_calls'=>3,
            'web'=>['query'=>'controlled fermentation research','domains'=>['research.example.org'],'max_pages'=>2]]);
    }
    unset($entry);
    cloud_mission_configure_v1($pdo,$user,['mission_id'=>$m['id'],
        'request_id'=>cloud_mission_uuid_v1('mission-capability-review-'.$key),'expected_revision'=>0,
        'confirmed'=>true,'assignments'=>$assignments]);
    cloud_mission_service_v1($pdo,$user,'tools.start',['mission_id'=>$m['id'],'expected_revision'=>1,'confirmed'=>true]);
    if(!$finish)return $m;
    $transport=['search'=>static fn()=>['ok'=>true,'results'=>[
        ['url'=>'https://research.example.org/study','title'=>'Controlled source'],
        ['url'=>'https://research.example.org/unread','title'=>'Unfetched discovery'],
    ]],'get'=>static function()use($key):array{
        ++$GLOBALS['missionCapabilityWebReads'];
        return ['status'=>200,'content_type'=>'text/html','body'=>'<title>Controlled source</title><main>'
            .str_repeat('The controlled study provides saved fermentation evidence '.$key.'. ',65).'</main>'];
    }];
    cloud_mission_tick_v1($pdo,$user,$m['id'],null,$transport);
    return cloud_mission_tick_v1($pdo,$user,$m['id'],null,$transport);
}

$GLOBALS['missionCapabilityWebReads']=0;
$writer=db();
$writer->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);
    INSERT INTO chat_conversations VALUES(11,1,7),(12,1,7),(13,1,NULL),(14,2,7)');
$missions=[];
for($i=0;$i<6;$i++)$missions[]=mission_capability_seed($writer,'source-'.$i);
$unfinished=mission_capability_seed($writer,'unfinished',['web.search','web.read'],false);
$discovery=mission_capability_seed($writer,'discovery',['web.search']);
$multi=mission_capability_seed($writer,'four-excerpts',['web.search','web.read'],true,true);
$reader=new MissionCapabilityReadPDO('sqlite:'.$fixtureFile,null,null,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['pdo']=$reader;
$schemaBefore=(int)$reader->query('PRAGMA schema_version')->fetchColumn();
$webBefore=$GLOBALS['missionCapabilityWebReads'];$modelsBefore=count($GLOBALS['modelContexts']??[]);
$scope=['user'=>['id'=>1,'is_active'=>1],'principal'=>['kind'=>'user_agent','agent_id'=>7,
    'owner_user_id'=>1,'viewer_user_id'=>1],'agent_id'=>7,'conversation_id'=>11];
$binding=['enabled'=>true,'agent_id'=>7,'conversation_id'=>11,'revision'=>str_repeat('a',32)];
$now=new DateTimeImmutable('2026-11-06T00:00:00Z');$coverage=[];
$available=vp3_initiative_mission_capabilities_evidence_v1($reader,$scope,$binding,$now,null,$coverage);
mission_capability_check(count($available)===4&&$coverage['mission_evidence']==='limited',
    'bounded discovery returns four real completed Missions and discloses remaining coverage');
$mission=$missions[0];$ref='mission-evidence:'.$mission['id'];
$evidence=vp3_initiative_mission_capabilities_evidence_v1($reader,$scope,$binding,$now,[$ref],$coverage);
$job=['tool'=>'missions.evidence.read','primary'=>$ref,'refs'=>[$ref],'focus'=>'Evaluate the saved findings','report'=>true];
$read=vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence);
mission_capability_check(count($evidence)===1&&isset($evidence[$ref])&&$evidence[$ref]['kind']==='mission_evidence'
    &&strlen($evidence[$ref]['revision'])===64&&!str_contains(json_encode($evidence),'The controlled study provides'),
    'planner receives exact Mission identity and stable semantic revision without a raw excerpt dump');
$canonical=initiative_work_mission_evidence_v1($reader,1,$scope,$mission['id']);
mission_capability_check($read['canonical_revision']===$canonical['revision']
    &&$read['source_revision']===$evidence[$ref]['revision']&&$read['source_ref']===$ref,
    'selected read carries both full canonical Mission revision and composite capability fingerprint');
mission_capability_check(count($read['records'])===1&&$read['records'][0]['text']===array_values($canonical['sources'])[0]['excerpt']
    &&mb_strlen($read['records'][0]['text'])===1400&&$read['coverage']['returned_excerpts']===1
    &&$read['coverage']['captured_sources']===2&&$read['coverage']['state']==='limited',
    'actual saved fetched excerpt is bounded and unread discovery is excluded with honest partial coverage');
mission_capability_check(preg_match('/\Aweb:[a-f0-9]{20}@[a-f0-9-]{36}\z/D',$read['records'][0]['source'])===1
    &&$read['records'][0]['url']==='https://research.example.org/study'
    &&$read['records'][0]['data']['captured_at']!==''&&$read['records'][0]['data']['partial_excerpt']===true,
    'record provenance identifies the saved page, collection, captured time and public URL');
mission_capability_check(!str_contains(json_encode($read),'Source-backed research draft.')
    &&$read['coverage']['model_assessments_included']===false
    &&$read['coverage']['new_research_performed']===false,
    'generated Mission analysis is not presented as independent source evidence or fresh research');
$again=vp3_initiative_mission_capabilities_evidence_v1($reader,$scope,$binding,$now->modify('+30 days'),[$ref],$coverage);
mission_capability_check($again===$evidence,'observation time alone does not change the saved semantic revision');
mission_capability_check(str_contains($evidence[$ref]['url'],'conversation_id=11')
    &&str_contains($evidence[$ref]['url'],'agent=7')&&str_contains($evidence[$ref]['url'],$mission['id']),
    'Mission return URL is bound to its exact owner Chat and Agent');
$multiRef='mission-evidence:'.$multi['id'];
$multiEvidence=vp3_initiative_mission_capabilities_evidence_v1($reader,$scope,$binding,$now,[$multiRef],$coverage);
$multiRead=vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',
    array_replace($job,['primary'=>$multiRef,'refs'=>[$multiRef]]),$multiEvidence);
mission_capability_check(count($multiRead['records'])===4&&count($multiRead['sources'])===4
    &&count(array_unique(array_column($multiRead['records'],'source')))===4
    &&max(array_map(static fn(array $row):int=>mb_strlen($row['text']),$multiRead['records']))===1400,
    'two completed canonical workers return at most four separately attributed bounded excerpts');

$empty=vp3_initiative_mission_capabilities_evidence_v1($reader,$scope,$binding,$now,['calendar:1'],$coverage);
mission_capability_check($empty===[]&&$coverage['mission_evidence']==='ready','selected reads do not fall back to unrelated Missions');
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_evidence_v1($reader,$scope,$binding,$now,['mission-evidence:../foreign'],$coverage),0,
    'malformed saved-source selectors are rejected');
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'images.generate',$job,$evidence),0,
    'the read adapter cannot dispatch an unrelated or paid tool');
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',array_replace($job,['refs'=>[]]),$evidence),0,
    'a Mission primary must belong to the explicit assignment source set');
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',
    array_replace($job,['primary'=>'mission-evidence:'.$missions[1]['id']]),$evidence),0,
    'a different completed Mission cannot substitute for the bound primary');
$wrongOwner=array_replace($scope,['user'=>['id'=>2,'is_active'=>1]]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$wrongOwner,$binding,'missions.evidence.read',$job,$evidence),403,
    'foreign owner cannot reuse a saved source or principal');
$wrongConversation=array_replace($scope,['conversation_id'=>12]);$wrongBinding=array_replace($binding,['conversation_id'=>12]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$wrongConversation,$wrongBinding,'missions.evidence.read',$job,$evidence),404,
    'another same-Agent conversation cannot read the Mission');
$systemScope=array_replace($scope,['agent_id'=>0,'conversation_id'=>13,'principal'=>['kind'=>'system','agent_id'=>0,
    'owner_user_id'=>0,'viewer_user_id'=>1]]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$systemScope,
    array_replace($binding,['agent_id'=>0,'conversation_id'=>13]),'missions.evidence.read',$job,$evidence),404,
    'System Agent cannot inherit a named-Agent Mission');
$GLOBALS['missionCapabilityParticipant']=['id'=>1];
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),403,
    'participant context cannot read owner Mission evidence');
unset($GLOBALS['missionCapabilityParticipant']);
$GLOBALS['revoked']=true;
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),403,
    'current account permissions are rechecked before reading saved evidence');
unset($GLOBALS['revoked']);
$writer->exec('UPDATE users SET is_active=0 WHERE id=1');
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),403,
    'deactivated account cannot use cached scope');
$writer->exec('UPDATE users SET is_active=1 WHERE id=1');
$paused=array_replace($binding,['enabled'=>false]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$paused,'missions.evidence.read',$job,$evidence),403,
    'paused Initiative cannot start a new read');
mission_capability_check(vp3_initiative_mission_capabilities_read_v1($reader,$scope+['verification_only'=>true],$paused,
    'missions.evidence.read',$job,$evidence)===$read,
    'readonly owner follow-through verification may revalidate an old result after Pause');
$GLOBALS['revoked']=true;
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope+['verification_only'=>true],$paused,
    'missions.evidence.read',$job,$evidence),403,
    'post-Pause verification still requires current domain and account permission');
unset($GLOBALS['revoked']);

$unfinishedRef='mission-evidence:'.$unfinished['id'];$discoveryRef='mission-evidence:'.$discovery['id'];
mission_capability_check(vp3_initiative_mission_capabilities_evidence_v1($reader,$scope,$binding,$now,[$unfinishedRef],$coverage)===[],
    'unfinished canonical work is not admitted as completed evidence');
mission_capability_check(vp3_initiative_mission_capabilities_evidence_v1($reader,$scope,$binding,$now,[$discoveryRef],$coverage)===[],
    'search results without fetched excerpts cannot become source facts');
$run=(int)$mission['job_id'];
$collection=$writer->prepare('SELECT collection_id,sources_json,status FROM agent_cloud_mission_web_v1 WHERE mission_id=?');
$collection->execute([$mission['id']]);$savedCollection=$collection->fetch();
$alteredSources=json_decode($savedCollection['sources_json'],true,24,JSON_THROW_ON_ERROR);
$alteredSources[0]['excerpt'].=' This correction is beyond the visible excerpt prefix.';
$writer->prepare('UPDATE agent_cloud_mission_web_v1 SET sources_json=? WHERE collection_id=?')
    ->execute([json_encode($alteredSources,JSON_THROW_ON_ERROR),$savedCollection['collection_id']]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),409,
    'a source change beyond the displayed prefix invalidates the full semantic revision');
$writer->prepare('UPDATE agent_cloud_mission_web_v1 SET sources_json=? WHERE collection_id=?')
    ->execute([$savedCollection['sources_json'],$savedCollection['collection_id']]);
$writer->prepare("UPDATE agent_cloud_mission_web_v1 SET status='failed' WHERE collection_id=?")->execute([$savedCollection['collection_id']]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),409,
    'revoked public-source collection withholds a saved result');
$writer->prepare('UPDATE agent_cloud_mission_web_v1 SET status=? WHERE collection_id=?')
    ->execute([$savedCollection['status'],$savedCollection['collection_id']]);
$collectionKey=$writer->prepare('SELECT action_key FROM agent_cloud_mission_web_v1 WHERE collection_id=?');
$collectionKey->execute([$savedCollection['collection_id']]);$savedActionKey=$collectionKey->fetchColumn();
$writer->prepare("UPDATE agent_cloud_mission_web_v1 SET action_key='foreign-worker' WHERE collection_id=?")
    ->execute([$savedCollection['collection_id']]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),409,
    'a saved source collection cannot be borrowed from another action');
$writer->prepare('UPDATE agent_cloud_mission_web_v1 SET action_key=? WHERE collection_id=?')
    ->execute([$savedActionKey,$savedCollection['collection_id']]);
$writer->prepare('UPDATE agent_cloud_mission_web_v1 SET mission_id=? WHERE collection_id=?')
    ->execute([$missions[1]['id'],$savedCollection['collection_id']]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),409,
    'a same-owner source collection from a different Mission cannot establish this result');
$writer->prepare('UPDATE agent_cloud_mission_web_v1 SET mission_id=? WHERE collection_id=?')
    ->execute([$mission['id'],$savedCollection['collection_id']]);
$receipt=$writer->prepare('SELECT * FROM agent_workflow_receipts WHERE run_id=? ORDER BY id LIMIT 1');$receipt->execute([$run]);$savedReceipt=$receipt->fetch();
$writer->prepare("UPDATE agent_workflow_receipts SET result_json='{}' WHERE id=?")->execute([$savedReceipt['id']]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),409,
    'completed action without an exact matching receipt cannot supply evidence');
mission_capability_check(vp3_initiative_mission_capabilities_evidence_v1($reader,$scope,$binding,$now,[$ref],$coverage)===[]
    &&$coverage['mission_evidence']==='ready',
    'an exact receipt mismatch is a known stale source and not retryable incomplete coverage');
$writer->prepare('UPDATE agent_workflow_receipts SET result_json=?,owner_user_id=2 WHERE id=?')
    ->execute([$savedReceipt['result_json'],$savedReceipt['id']]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),409,
    'another owner receipt cannot corroborate the action');
$writer->prepare("UPDATE agent_workflow_receipts SET owner_user_id=1,status='failed' WHERE id=?")->execute([$savedReceipt['id']]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),409,
    'a failed receipt cannot establish completed source work');
$writer->prepare('UPDATE agent_workflow_receipts SET status=? WHERE id=?')->execute([$savedReceipt['status'],$savedReceipt['id']]);
$writer->prepare("UPDATE agent_workflow_receipts SET executor='homeserver' WHERE id=?")->execute([$savedReceipt['id']]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),409,
    'a receipt from the wrong executor cannot corroborate completed Cloud work');
$writer->prepare('UPDATE agent_workflow_receipts SET executor=? WHERE id=?')->execute([$savedReceipt['executor'],$savedReceipt['id']]);
$writer->prepare("UPDATE agent_workflow_runs SET goal='Changed Mission objective' WHERE id=?")->execute([$run]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),409,
    'Mission objective changes invalidate the original evidence assignment');
$writer->prepare('UPDATE agent_workflow_runs SET goal=? WHERE id=?')->execute([$mission['objective'],$run]);
$reader->failMissionRead=true;
$missing=vp3_initiative_mission_capabilities_evidence_v1($reader,$scope,$binding,$now,null,$coverage);
mission_capability_check($missing===[]&&$coverage['mission_evidence']==='unavailable',
    'transient database failure is unavailable coverage and cannot masquerade as a complete empty catalog');
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),0,
    'transient selected-read failure propagates rather than returning empty success');
$reader->failMissionRead=false;
$writer->prepare('DELETE FROM agent_cloud_missions_v1 WHERE mission_id=?')->execute([$mission['id']]);
mission_capability_reject(fn()=>vp3_initiative_mission_capabilities_read_v1($reader,$scope,$binding,'missions.evidence.read',$job,$evidence),404,
    'deleted Mission cannot be delivered from cached evidence');
mission_capability_check(vp3_initiative_mission_capabilities_evidence_v1($reader,$scope,$binding,$now,[$ref],$coverage)===[]
    &&$coverage['mission_evidence']==='ready',
    'an exact deleted Mission retires the old source instead of leaving delivery pending forever');
mission_capability_check($reader->writeAttempts===0&&(int)$reader->query('SELECT total_changes()')->fetchColumn()===0
    &&(int)$reader->query('PRAGMA schema_version')->fetchColumn()===$schemaBefore,
    'all adapter reads and rejection paths perform zero attempted writes, schema changes or activation');
mission_capability_check($GLOBALS['missionCapabilityWebReads']===$webBefore&&count($GLOBALS['modelContexts']??[])===$modelsBefore,
    'reading completed evidence performs zero provider calls, model inference or fresh web requests');
echo 'AGENT_INITIATIVE_MISSION_EVIDENCE_V1=PASS ('.$cases." cases)\n";
