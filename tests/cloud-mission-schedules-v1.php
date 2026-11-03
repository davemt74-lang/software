<?php
declare(strict_types=1);
// The MySQL variant uses an isolated disposable database and production engine migrations.
$mysql=getenv('VP3_SECTION7_MYSQL_DSN');
if($mysql&&!getenv('VP3_CLOUD_SCHEDULE_DSN')){
    $dbName='vp3_missions_'.bin2hex(random_bytes(5));$admin=new PDO($mysql,'root',getenv('VP3_SECTION7_MYSQL_PASSWORD')?:'section7-fixture');$admin->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4');
    putenv('VP3_CLOUD_SCHEDULE_DSN='.preg_replace('/dbname=[^;]+/','dbname='.$dbName,$mysql));putenv('VP3_CLOUD_SCHEDULE_USER=root');putenv('VP3_CLOUD_SCHEDULE_PASSWORD='.(getenv('VP3_SECTION7_MYSQL_PASSWORD')?:'section7-fixture'));
    register_shutdown_function(static function()use($admin,$dbName){$admin->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');});
}
require __DIR__.'/fixtures/cloud-mission-schedules-v1.php';
if(in_array('--race-tick',$argv,true)){cloud_mission_schedule_tick_v1($pdo,['id'=>1],new DateTimeImmutable('2070-01-02 17:00:00',new DateTimeZone('UTC')));exit;}
$checks=0;function check($v,$label): void {if(!$v)throw new RuntimeException($label);echo "PASS $label\n";$GLOBALS['checks']++;}
function rejects($fn,$label,$code=0): void {try{$fn();}catch(Throwable $e){check(!$code||$code===$e->getCode(),$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$testModel=fn($q,$context,$user)=>ai_generate_chat_response($q,[],$context,$user,'main_ai',['surface'=>'cloud_mission']);
$user=['id'=>1];$clock=new DateTimeImmutable('2070-01-01 12:00:00',new DateTimeZone('UTC'));
$m=cloud_mission_prepare_v1($pdo,$user,['objective'=>'Compare dough research','request_id'=>'mission-template-fixture','thread_id'=>11,'agent_id'=>7]);$draft=$m['chat_task']['draft'];$draft['assignments'][0]['tools']=['knowledge.search'];$draft['assignments'][0]['max_calls']=1;
cloud_mission_configure_v1($pdo,$user,['mission_id'=>$m['id'],'expected_revision'=>0,'confirmed'=>true,'request_id'=>cloud_mission_uuid_v1('review'),'assignments'=>$draft]);
$create=['mission_id'=>$m['id'],'request_id'=>cloud_mission_uuid_v1('schedule-create'),'expected_revision'=>1,'confirmed'=>true,'folder_id'=>0,'save_reports'=>true,'timing'=>['frequency'=>'daily','weekday'=>0,'hour'=>9,'minute'=>0,'timezone'=>'America/Phoenix']];
rejects(fn()=>cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.create',[...$create,'confirmed'=>false],$clock),'recurrence needs explicit confirmation');
rejects(fn()=>cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.create',[...$create,'save_reports'=>false],$clock),'Knowledge writes need a reviewed report destination');
$list=cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.create',$create,$clock);$s=$list['schedules'][0];$sid=$s['id'];
check($s['next_run_at']==='2070-01-01 16:00:00'&&$s['folder_id']>0,'timezone schedule creates a persistent plan and real My Knowledge folder');
check(cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.create',$create,$clock)['schedules'][0]['id']===$sid&&count(personal_knowledge_folders($pdo,$user))===1,'lost create response reuses its schedule and folder');
rejects(fn()=>cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.create',[...$create,'request_id'=>cloud_mission_uuid_v1('invalid-zone'),'timing'=>[...$create['timing'],'timezone'=>'Imaginary/City']],$clock),'invalid timezone cannot create a recurring grant');
$foreign=personal_knowledge_create_folder($pdo,['id'=>2],'Foreign folder');
rejects(fn()=>cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.create',[...$create,'request_id'=>cloud_mission_uuid_v1('foreign-folder'),'folder_id'=>(int)$foreign['id']],$clock),'another account’s folder cannot be a report destination',403);
// Only the recurring grant survives expiry; the original one-shot review remains bounded.
$pdo->prepare("UPDATE agent_cloud_missions_v1 SET review_until='2000-01-01 00:00:00' WHERE mission_id=?")->execute([$m['id']]);
rejects(fn()=>cloud_mission_service_v1($pdo,$user,'tools.start',['mission_id'=>$m['id'],'expected_revision'=>1,'confirmed'=>true]),'expired one-shot review cannot start the original template',409);
rejects(fn()=>cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.create',[...$create,'folder_id'=>999],$clock),'operation ID cannot change destination',409);
rejects(fn()=>cloud_mission_schedule_row_v1($pdo,['id'=>2],$sid),'another account cannot inspect this schedule',404);
check(cloud_mission_schedule_list_v1($pdo,['id'=>2])['schedules']===[],'schedule history is owner scoped');
check(cloud_mission_schedule_tick_v1($pdo,$user,$clock)['created']===0,'schedule listing and early ticks do not invoke models or create runs');
$due=new DateTimeImmutable('2070-01-01 17:00:00',new DateTimeZone('UTC'));check(cloud_mission_schedule_tick_v1($pdo,$user,$due)['created']===1,'due slot creates one canonical approved Cloud job');
check(cloud_mission_schedule_tick_v1($pdo,$user,$due)['created']===0,'repeated ticks cannot duplicate a scheduled slot');
$link=$pdo->query('SELECT * FROM agent_cloud_mission_schedule_runs_v1')->fetch();$runId=$link['mission_id'];
$runMeta=cloud_mission_row_v1($pdo,$user,$runId);$tampered=json_decode($runMeta['config_json'],true);$tampered['assignments'][0]['max_calls']=3;
$pdo->prepare('UPDATE agent_cloud_missions_v1 SET config_json=? WHERE mission_id=?')->execute([json_encode($tampered),$runId]);
rejects(fn()=>cloud_mission_authority_v1($pdo,$user,cloud_mission_row_v1($pdo,$user,$runId),true),'a scheduled run cannot expand its inherited read budget',409);
$pdo->prepare('UPDATE agent_cloud_missions_v1 SET config_json=? WHERE mission_id=?')->execute([$runMeta['config_json'],$runId]);
cloud_mission_tick_v1($pdo,$user,$runId,$testModel);
check((int)$pdo->query("SELECT COUNT(*) FROM agent_cloud_mission_schedule_runs_v1 WHERE report_state='saved'")->fetchColumn()===0,'partial worker output is not saved as a completed report');
cloud_mission_tick_v1($pdo,$user,$runId,$testModel);$saved=cloud_mission_schedule_link_v1($pdo,$runId);$note=get_knowledge_item((int)$saved['knowledge_id']);
check($saved['report_state']==='saved'&&$note['knowledge_scope']==='personal'&&(int)$note['folder_id']===$s['folder_id']&&str_contains($note['content_text'],'First saved run'),'completion saves an indexed private report in the selected canonical folder');
$noteCount=(int)$pdo->query('SELECT COUNT(*) FROM knowledge_items')->fetchColumn();cloud_mission_schedule_sync_v1($pdo,$user,$runId);check((int)$pdo->query('SELECT COUNT(*) FROM knowledge_items')->fetchColumn()===$noteCount,'report sync does not duplicate saved Knowledge notes');
$next=new DateTimeImmutable('2070-01-02 17:00:00',new DateTimeZone('UTC'));
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
    $children=[];for($i=0;$i<4;$i++){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--race-tick'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$p,$pipes];}
    foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,'parallel MySQL scheduler completes without an error: '.$out);}
}else cloud_mission_schedule_tick_v1($pdo,$user,$next);
check((int)$pdo->query('SELECT COUNT(*) FROM agent_cloud_mission_schedule_runs_v1')->fetchColumn()===2,'concurrent or repeated schedulers produce exactly one job per slot');
$second=$pdo->query('SELECT mission_id FROM agent_cloud_mission_schedule_runs_v1 ORDER BY due_at DESC LIMIT 1')->fetchColumn();cloud_mission_tick_v1($pdo,$user,$second,$testModel);
check(str_contains(json_encode($GLOBALS['modelContexts']),'previous-mission:'.$runId),'next run receives authorized previous findings for comparison');
cloud_mission_tick_v1($pdo,$user,$second,$testModel);check((int)cloud_mission_schedule_link_v1($pdo,$second)['evidence_changed']===0,'generated reports are excluded from collection and unchanged evidence stays unchanged');
$source=(int)$pdo->query("SELECT id FROM knowledge_items WHERE description NOT LIKE 'Recurring Cloud Mission%' OR description='' LIMIT 1")->fetchColumn();personal_knowledge_store($user,'source-dough','Dough research','Dough cold fermentation research: newly measured evidence.');
$thirdTime=new DateTimeImmutable('2070-01-03 17:00:00',new DateTimeZone('UTC'));cloud_mission_schedule_tick_v1($pdo,$user,$thirdTime);$third=$pdo->query('SELECT mission_id FROM agent_cloud_mission_schedule_runs_v1 ORDER BY due_at DESC LIMIT 1')->fetchColumn();cloud_mission_tick_v1($pdo,$user,$third,$testModel);cloud_mission_tick_v1($pdo,$user,$third,$testModel);check((int)cloud_mission_schedule_link_v1($pdo,$third)['evidence_changed']===1,'changed source text is detected independently of generated wording');
$op=['schedule_id'=>$sid,'expected_revision'=>1,'confirmed'=>true,'request_id'=>cloud_mission_uuid_v1('pause')];cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.pause',$op,$thirdTime);check(cloud_mission_schedule_tick_v1($pdo,$user,new DateTimeImmutable('2070-02-01 UTC'))['created']===0,'pausing a persistent Mission stops future runs');
check(cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.pause',$op,$thirdTime)['schedules'][0]['revision']===2,'lost pause response does not apply twice');
rejects(fn()=>cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.resume',[...$op,'request_id'=>cloud_mission_uuid_v1('stale')],$thirdTime),'stale control revisions cannot resume work',409);
$resume=[...$op,'request_id'=>cloud_mission_uuid_v1('resume'),'expected_revision'=>2];cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.resume',$resume,new DateTimeImmutable('2070-02-01 UTC'));$s=cloud_mission_schedule_row_v1($pdo,$user,$sid);check($s['next_run_at']==='2070-02-01 16:00:00','resume skips missed days and starts with the next future slot');
$later=new DateTimeImmutable('2070-02-04 17:00:00 UTC');check(cloud_mission_schedule_tick_v1($pdo,$user,$later)['created']===1,'missed slots coalesce into one current run');$running=$pdo->query('SELECT mission_id FROM agent_cloud_mission_schedule_runs_v1 ORDER BY due_at DESC LIMIT 1')->fetchColumn();check(cloud_mission_schedule_tick_v1($pdo,$user,new DateTimeImmutable('2070-02-05 17:00:00 UTC'))['skipped']===1,'unfinished work prevents overlapping runs');
cloud_mission_tick_v1($pdo,$user,$running,static fn()=>['ok'=>false,'error'=>'fixture failure']);cloud_mission_schedule_tick_v1($pdo,$user,new DateTimeImmutable('2070-02-06 17:00:00 UTC'));check(cloud_mission_schedule_row_v1($pdo,$user,$sid)['status']==='paused','failed inference pauses future collection instead of silently repeating paid failures');
check(!cloud_mission_schedule_health_v1($pdo)['background_ready'],'missing CLI heartbeat is shown honestly as browser-assisted');cloud_mission_schedule_background_v1($pdo);check(cloud_mission_schedule_health_v1($pdo)['background_ready'],'a live background cycle reports a fresh heartbeat');
$spring=['frequency'=>'daily','weekday'=>0,'hour'=>2,'minute'=>30,'timezone'=>'America/New_York'];check(cloud_mission_schedule_next_v1($spring,'2070-03-09 07:00:00')>'2070-03-09 07:00:00','DST scheduling always selects a future UTC slot');
check(count(search_knowledge('dough',$user,20,['Recurring Cloud Mission · ']))===1,'canonical Knowledge search excludes generated reports before ranking');
$s=cloud_mission_schedule_row_v1($pdo,$user,$sid);cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.resume',['schedule_id'=>$sid,'expected_revision'=>(int)$s['revision'],'confirmed'=>true,'request_id'=>cloud_mission_uuid_v1('resume-for-cancel')],new DateTimeImmutable('2070-03-01 UTC'));
cloud_mission_schedule_tick_v1($pdo,$user,new DateTimeImmutable('2070-03-01 17:00:00 UTC'));$inFlight=$pdo->query('SELECT mission_id FROM agent_cloud_mission_schedule_runs_v1 ORDER BY due_at DESC LIMIT 1')->fetchColumn();$s=cloud_mission_schedule_row_v1($pdo,$user,$sid);
cloud_mission_schedule_mutate_v1($pdo,$user,'schedule.cancel',['schedule_id'=>$sid,'expected_revision'=>(int)$s['revision'],'confirmed'=>true,'request_id'=>cloud_mission_uuid_v1('cancel')],new DateTimeImmutable('2070-03-01 18:00:00 UTC'));
cloud_mission_tick_v1($pdo,$user,$inFlight,$testModel);cloud_mission_tick_v1($pdo,$user,$inFlight,$testModel);
check(cloud_mission_schedule_link_v1($pdo,$inFlight)['report_state']==='saved'&&cloud_mission_schedule_tick_v1($pdo,$user,new DateTimeImmutable('2070-04-01 UTC'))['created']===0,'cancelling future runs preserves current work and completed reports');
$deniedUser=['id'=>1,'denied'=>true];
rejects(fn()=>cloud_mission_schedule_authority_v1($pdo,$deniedUser,cloud_mission_row_v1($pdo,$user,$inFlight)),'recurring grants do not bypass current account permissions',403);
$pdo->prepare('DELETE FROM artist_transcript_folders_v177 WHERE id=?')->execute([$s['folder_id']]);
rejects(fn()=>cloud_mission_schedule_authority_v1($pdo,$user,cloud_mission_row_v1($pdo,$user,$inFlight)),'deleting the destination prevents further writes or implicit folder recreation',409);
echo "CLOUD_MISSION_SCHEDULES_V1=PASS ($checks cases; ".$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
