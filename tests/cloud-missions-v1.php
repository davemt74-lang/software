<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/cloud-missions-v1.php';
require __DIR__.'/fixtures/workflow-agent-schema-v1.php';
class CloudMissionPDO extends PDO{
 public function prepare(string $query,array $options=[]): PDOStatement|false{return parent::prepare(str_replace([' FOR UPDATE','INSERT IGNORE'],['','INSERT OR IGNORE'],$query),$options);}
}
$pdo=new CloudMissionPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('IF',fn($v,$yes,$no)=>$v?$yes:$no);workflow_agent_fixture_v1($pdo);
foreach(['capability_key TEXT','action_key TEXT','action_type TEXT','label TEXT','summary TEXT','attempt_count INTEGER DEFAULT 0','result_summary TEXT','result_json TEXT','error_class TEXT','started_at TEXT','completed_at TEXT','updated_at TEXT'] as $c)$pdo->exec('ALTER TABLE agent_workflow_actions ADD COLUMN '.$c);
foreach(['run_id INTEGER','owner_user_id INTEGER','action_id INTEGER','depends_on_action_id INTEGER'] as $c)$pdo->exec('ALTER TABLE agent_workflow_action_dependencies ADD COLUMN '.$c);
foreach(['receipt_key TEXT','result_json TEXT'] as $c)$pdo->exec('ALTER TABLE agent_workflow_receipts ADD COLUMN '.$c);
$pdo->exec('CREATE UNIQUE INDEX receipt_unique ON agent_workflow_receipts(owner_user_id,receipt_key)');
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $name): bool{$q=db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return (bool)$q->fetchColumn();}
function column_exists(string $t,string $c): bool{$q=db()->query('PRAGMA table_info('.$t.')');return in_array($c,array_column($q->fetchAll(),'name'),true);}
function has_permission($p,$u): bool{return empty($u['denied']);}
function personal_capability_has_v242($p,$u): bool{return empty($GLOBALS['revoked']);}
function personal_knowledge_available($u): bool{return empty($GLOBALS['revoked']);}
function user_agent_get_v236($pdo,$uid,$aid): ?array{return $uid===1&&$aid===7?['id'=>7,'is_active'=>1]:null;}
function vp3_agent_memory_scope_from_conversation_v410($u,$cid,$set=true): int{if($u['id']!==1||$cid!==11)throw new RuntimeException('Wrong conversation.',403);return 7;}
function vp3_agent_memory_scope_set_current_v410($uid,$aid): void{$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=['user_id'=>$uid,'user_agent_id'=>$aid];}
function homeserver_https_v1300_status($uid): array{return ['connected'=>false];}
function search_knowledge(...$args): array{return [['id'=>9,'title'=>'Dough evidence','chunk_text'=>'Cold fermentation test results']];}
function get_knowledge_item($id): ?array{return $id===9&&!($GLOBALS['deletedSource']??false)?['id'=>9,'knowledge_scope'=>'personal','created_by_user_id'=>1]:null;}
function ai_active_provider(): string{return 'fixture';}
function ai_provider_model($p): string{return 'fixture';}
$checks=0;function check($v,$label){if(!$v)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}function rejects($fn,$label,$code=0){try{$fn();}catch(Throwable $e){check(!$code||$e->getCode()===$code,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
cloud_mission_schema_v1($pdo);$u=['id'=>1];$request=['objective'=>'Compare the collected dough research','thread_id'=>11,'request_id'=>'00000000-0000-4000-8000-000000000001'];
$m=cloud_mission_prepare_v1($pdo,$u,$request);$mid=$m['id'];check($m['execution_target']==='cloud'&&$m['status']==='planned'&&count($m['tasks'])===2,'Cloud Mission is prepared in the existing job engine without HomeServer');
check($pdo->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE source_kind='cloud_mission'")->fetchColumn()==1,'Mission has exactly one canonical job, not a parallel execution queue');
check(cloud_mission_prepare_v1($pdo,$u,$request)['id']===$mid,'lost prepare response recovers the same Mission');
rejects(fn()=>cloud_mission_prepare_v1($pdo,$u,[...$request,'objective'=>'Different objective']),'request ID binds the exact objective and Agent',409);
rejects(fn()=>cloud_mission_service_v1($pdo,['id'=>2],'get',['mission_id'=>$mid]),'another account cannot inspect the job',404);
rejects(fn()=>cloud_mission_service_v1($pdo,$u,'tools.start',['mission_id'=>$mid]),'workers cannot start without assignment review',409);
$draft=$m['chat_task']['draft'];$review=['mission_id'=>$mid,'request_id'=>'00000000-0000-4000-8000-000000000002','expected_revision'=>0,'confirmed'=>true,'assignments'=>$draft];
$bad=$draft;$bad['assignments'][0]['tools']=['email.send'];rejects(fn()=>cloud_mission_configure_v1($pdo,$u,[...$review,'assignments'=>$bad]),'unconnected mutation tools cannot be smuggled into a Cloud assignment');
$tools=cloud_mission_configure_v1($pdo,$u,$review);check($tools['configured']&&$tools['revision']===1,'explicit assignment review creates the bounded Cloud grant');check(cloud_mission_configure_v1($pdo,$u,$review)['revision']===1,'review retries do not change the revision twice');
cloud_mission_service_v1($pdo,$u,'tools.start',['mission_id'=>$mid,'expected_revision'=>1,'confirmed'=>true]);$calls=[];$model=function($instruction,$context,$user)use(&$calls){$calls[]=[$instruction,$context];return ['ok'=>true,'provider'=>'fixture','answer'=>str_repeat('Verified draft output. ',60)];};
$first=cloud_mission_tick_v1($pdo,$u,$mid,$model);check($first['tasks'][0]['status']==='completed'&&count($calls)===1,'first worker executes actual model inference and commits a durable receipt');check(strlen($first['tasks'][0]['result'])>500,'Mission output is retained beyond the legacy receipt summary limit');
$second=cloud_mission_tick_v1($pdo,$u,$mid,$model);check($second['status']==='completed'&&count($calls)===2,'second worker completes the canonical job');check(!empty($calls[1][1])&&str_starts_with($calls[1][1][0]['source'],'mission-worker:'),'reviewer receives prior receipt-backed worker output');cloud_mission_tick_v1($pdo,$u,$mid,$model);check(count($calls)===2,'polling a completed Mission does not replay inference');
check((int)$pdo->query('SELECT COUNT(*) FROM agent_workflow_receipts')->fetchColumn()===2,'each worker result has one canonical receipt');
$feed=cloud_mission_dispatch_v1($pdo,$u,'progress.feed',[]);check(count($feed['items'])===1&&$feed['runtimes']['cloud']&&!$feed['runtimes']['homeserver'],'Cloud progress stays available with HomeServer offline');check($feed['notifications']['unread']>=2,'prepared/completed notices project existing workflow events');
$notice=$feed['notifications']['items'][0]['id'];cloud_mission_service_v1($pdo,$u,'notifications.read',['notice_id'=>$notice]);check(cloud_mission_notices_v1($pdo,$u)['unread']===$feed['notifications']['unread']-1,'Cloud notice read state is idempotent and owner scoped');
$next=cloud_mission_prepare_v1($pdo,$u,[...$request,'request_id'=>'00000000-0000-4000-8000-000000000003']);$review2=[...$review,'mission_id'=>$next['id'],'request_id'=>'00000000-0000-4000-8000-000000000004','assignments'=>$next['chat_task']['draft']];cloud_mission_configure_v1($pdo,$u,$review2);cloud_mission_service_v1($pdo,$u,'tools.start',['mission_id'=>$next['id'],'expected_revision'=>1,'confirmed'=>true]);cloud_mission_service_v1($pdo,$u,'pause',['mission_id'=>$next['id']]);check(cloud_mission_view_v1($pdo,$u,cloud_mission_row_v1($pdo,$u,$next['id']))['status']==='waiting_review','pause uses canonical job control');cloud_mission_service_v1($pdo,$u,'resume',['mission_id'=>$next['id'],'allow_reexecution'=>true]);
$cancelModel=function()use($pdo,$u,$next){cloud_mission_service_v1($pdo,$u,'cancel',['mission_id'=>$next['id']]);return ['ok'=>true,'answer'=>'Late output'];};$cancelled=cloud_mission_tick_v1($pdo,$u,$next['id'],$cancelModel);check($cancelled['status']==='cancelled'&&$cancelled['result']==='','cancellation during inference fences a late model result');
$third=cloud_mission_prepare_v1($pdo,$u,[...$request,'request_id'=>'00000000-0000-4000-8000-000000000005']);$d=$third['chat_task']['draft'];$d['assignments'][0]['tools']=['knowledge.search'];$d['assignments'][0]['max_calls']=1;cloud_mission_configure_v1($pdo,$u,[...$review,'mission_id'=>$third['id'],'request_id'=>'00000000-0000-4000-8000-000000000006','assignments'=>$d]);cloud_mission_service_v1($pdo,$u,'tools.start',['mission_id'=>$third['id'],'expected_revision'=>1,'confirmed'=>true]);$c=cloud_mission_tick_v1($pdo,$u,$third['id'],$model);check(str_contains(json_encode(end($calls)[1]),'Cold fermentation test results'),'approved Knowledge reads supply actual chunk text to inference');$GLOBALS['deletedSource']=true;$v=cloud_mission_view_v1($pdo,$u,cloud_mission_row_v1($pdo,$u,$third['id']));check(!$v['authority_current']&&$v['result']===''&&$v['tasks']===[],'deleted sources hide prior results and block further access');
rejects(fn()=>cloud_mission_service_v1($pdo,$u,'execution',['mission_id'=>$third['id']]),'deleted sources cannot be recovered through raw execution receipts',403);

require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-missions-v1.php';
function url(string $path): string{return '/vp3'.$path;}
$approval=cloud_mission_prepare_v1($pdo,$u,['objective'=>'Review the next menu','request_id'=>'catch-up-mission-review']);
$blocked=cloud_mission_prepare_v1($pdo,$u,[...$request,'objective'=>'Update prep plan','request_id'=>'catch-up-mission-blocked']);
$running=cloud_mission_prepare_v1($pdo,$u,[...$request,'objective'=>'Routine research','request_id'=>'catch-up-mission-running']);
$recentFailure=cloud_mission_prepare_v1($pdo,$u,['objective'=>'Review failed check','request_id'=>'catch-up-mission-failed']);
$oldFailure=cloud_mission_prepare_v1($pdo,$u,['objective'=>'Old failure','request_id'=>'catch-up-mission-old']);
$pdo->exec('CREATE TABLE agent_workflow_run_dependencies (owner_user_id INTEGER,run_id INTEGER,depends_on_run_id INTEGER)');
$pdo->prepare('INSERT INTO agent_workflow_run_dependencies VALUES (1,?,41),(2,?,43)')->execute([$blocked['job_id'],$running['job_id']]);
$update=$pdo->prepare('UPDATE agent_workflow_runs SET status=?,updated_at=? WHERE id=?');
foreach([[$approval,'approval_pending','-3 minutes'],[$blocked,'approved','-2 minutes'],[$running,'approved','-1 minute'],[$recentFailure,'failed','-1 hour'],[$oldFailure,'failed','-10 days']] as [$mission,$status,$age]){
    $update->execute([$status,gmdate('Y-m-d H:i:s',strtotime($age)),(int)$mission['job_id']]);
}
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$system=['kind'=>'system','agent_id'=>0,'viewer_user_id'=>1];
$named=['kind'=>'user_agent','agent_id'=>7,'owner_user_id'=>1,'viewer_user_id'=>1];
$changes=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
[$coverage,$items]=vp3_catch_up_missions_v1($pdo,$u,$system,$now);
check($coverage==='ready'&&array_column($items,'status')===['awaiting_review','failed'],'system brief includes its approval and fresh failure, excluding old failures and named work');
check($items[0]['url']==='/vp3/chat.php?agent=system&mission='.$approval['id'],'system Mission links to the exact Agent Teams record');
[$coverage,$items]=vp3_catch_up_missions_v1($pdo,$u,$named,$now);
check($coverage==='ready'&&array_column($items,'id')===['mission:'.$blocked['id']]&&$items[0]['status']==='blocked','named Agent sees only its own dependency-blocked Mission, excluding routine or foreign-edge work');
check(!str_contains(json_encode($items,JSON_THROW_ON_ERROR),'Fixture queued work')&&$items[0]['url']==='/vp3/chat.php?agent=7&mission='.$blocked['id'],'blocker details stay scoped and the linked Agent is exact');
$open=['mission_id'=>$blocked['id'],'agent_id'=>7];
$view=cloud_mission_dispatch_v1($pdo,$u,'catch_up.get',$open,static fn()=>throw new RuntimeException('Unexpected remote read'));
check($view['mission']['id']===$blocked['id']&&$view['mission']['progress']['state']==='dependency_blocked','exact Cloud Mission read opens the canonical Agent Teams state');
rejects(fn()=>cloud_mission_dispatch_v1($pdo,$u,'catch_up.get',[...$open,'agent_id'=>0]),'system Chat cannot open a named Agent Mission through a catch-up link',404);
rejects(fn()=>cloud_mission_dispatch_v1($pdo,['id'=>2],'catch_up.get',$open),'another owner cannot open a Mission through a catch-up link',404);
rejects(fn()=>cloud_mission_dispatch_v1($pdo,$u,'catch_up.get',['mission_id'=>$third['id'],'agent_id'=>7]),'catch-up links recheck result source authority',403);
check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changes,'Mission projection and deep link cause no record writes');
$pdo->exec("UPDATE agent_workflow_runs SET status='completed' WHERE id=41");
check(vp3_catch_up_missions_v1($pdo,$u,$named,$now)[1]===[],'verified prerequisite completion removes the Mission from attention');
echo "CLOUD_MISSIONS_V1=PASS ($checks cases)\n";
