<?php
declare(strict_types=1);

ini_set('zend.exception_ignore_args','1');
set_exception_handler(static function(Throwable $error):void{
    fwrite(STDERR,json_encode(['fixture_failure'=>true,'error_class'=>get_class($error),'public_code'=>(int)$error->getCode(),
        'driver_code'=>$error instanceof PDOException?(int)($error->errorInfo[1]??0):0,'file'=>basename($error->getFile()),'line'=>$error->getLine()],JSON_THROW_ON_ERROR).PHP_EOL);exit(1);
});
$dsn=(string)getenv('VP3_MYSQL_TEST_DSN');if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('An explicit disposable native MySQL DSN is required.');
$GLOBALS['actionMysqlUser']=(string)(getenv('VP3_MYSQL_TEST_USER')?:'root');$GLOBALS['actionMysqlPassword']=(string)getenv('VP3_MYSQL_TEST_PASSWORD');
$database='initiative_schedule_'.bin2hex(random_bytes(6));
$admin=new PDO($dsn,$GLOBALS['actionMysqlUser'],$GLOBALS['actionMysqlPassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `'.$database.'`');
$GLOBALS['actionMysqlDsn']=preg_replace('/;dbname=[^;]*/','',$dsn).';dbname='.$database;
require __DIR__.'/fixtures/initiative-actions-mysql-v1.php';
require_once dirname(__DIR__).'/includes/agent-reminders-v1.php';
require_once dirname(__DIR__).'/includes/agent-scheduling-v430.php';
require_once dirname(__DIR__).'/includes/user-calendar-v1300.php';
require_once dirname(__DIR__).'/includes/user-calendar-agent-v1300.php';
date_default_timezone_set('UTC');

function schedule_mysql_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['nativeScheduleChecks']=($GLOBALS['nativeScheduleChecks']??0)+1;echo 'PASS '.$label.PHP_EOL;}
function schedule_mysql_count(string $table,string $where='1=1'):int{return(int)db()->query('SELECT COUNT(*) FROM '.$table.' WHERE '.$where)->fetchColumn();}
function schedule_mysql_reminder(int $id):?array{$q=db()->prepare('SELECT * FROM agent_reminders_v1 WHERE id=? AND owner_user_id=1');$q->execute([$id]);return$q->fetch()?:null;}

/** A real owner-facing preparation with exact authorized schedule context. */
function schedule_mysql_source(array $refs,string $tool,string $label):array
{
    $root=action_mysql_seed($label,array_fill_keys($refs,$tool));
    return ['message_id'=>$root['message_id'],'loaded'=>$root['loaded']];
}

function schedule_mysql_approve(array $review,string $query='Apply this exact reviewed schedule'):array
{
    $selection=action_mysql_decide($review['pending'],['action_decision'=>'approve'],$query);
    $loaded=action_mysql_load($review['message_id']);$action=$loaded['domain_action'];$domain=$action['result']['domain_result'];
    if($action['run']['status']!=='completed'||($domain['verified']??null)!==true)throw new RuntimeException('Actual native schedule action was not verified.');
    return ['selection'=>$selection,'loaded'=>$loaded,'action'=>$action,'domain'=>$domain];
}

function schedule_mysql_receipt(array $saved,string $label):void
{
    $action=$saved['action'];$id=(int)$action['action']['id'];$q=db()->prepare("SELECT result_json FROM agent_workflow_receipts WHERE action_id=? AND owner_user_id=1 AND status='completed'");$q->execute([$id]);$rows=$q->fetchAll();
    schedule_mysql_check(count($rows)===1&&$rows[0]['result_json']===$action['action']['result_json']
        &&$saved['domain']['action_id']==='initiative-action:'.$id,$label);
}

function schedule_mysql_replay(array $saved,string $label):void
{
    $before=[schedule_mysql_count('agent_reminders_v1'),schedule_mysql_count('user_calendar_events'),schedule_mysql_count('agent_workflow_receipts')];
    $current=vp3_initiative_followthrough_current_v1(db(),action_mysql_user(),action_mysql_principal(),10,$saved['selection']['initiative_followthrough']);
    schedule_mysql_check(($current['verified_action']['status']??null)==='completed'&&$before===[schedule_mysql_count('agent_reminders_v1'),schedule_mysql_count('user_calendar_events'),schedule_mysql_count('agent_workflow_receipts')],$label);
}

try{
    action_mysql_schema();
    db()->exec("CREATE TABLE notifications(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,type VARCHAR(40),title VARCHAR(240),body TEXT,
        target_url VARCHAR(500),source_type VARCHAR(40),source_id BIGINT UNSIGNED,is_read TINYINT DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    reminder_schema_v1(db());user_calendar_ensure_schema_v1300(db());
    $schedule=['title'=>'Native release follow-up','timezone'=>'America/New_York','start_local'=>(new DateTimeImmutable('+2 hours'))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d\TH:i:s'),'recurrence'=>'daily','delivery'=>'both'];
    $root=action_mysql_review(action_mysql_seed('Native reminder creation source'),'reminder.create',['schedule'=>$schedule]);
    schedule_mysql_check(schedule_mysql_count('agent_reminders_v1')===0&&$root['pending']['domain_action']['run']['status']==='approval_pending','actual reminder review stores no reminder before exact approval');
    $created=schedule_mysql_approve($root);$rid=$created['domain']['record_id'];$row=schedule_mysql_reminder($rid);
    schedule_mysql_check($row&&$row['title']===$schedule['title']&&$row['timezone']===$schedule['timezone']&&(int)$row['conversation_id']===10,'native reminder create saves exact timezone and conversation through canonical locked kernel');
    schedule_mysql_receipt($created,'native reminder effect has one atomic completed workflow/action receipt with exact stable action identity');
    schedule_mysql_replay($created,'lost reminder acknowledgement replays verified state without another reminder or receipt');

    $root=action_mysql_review(schedule_mysql_source(['reminder:'.$rid],'reminders.read','the exact current reminder'),'reminder.update',
        ['target_ref'=>'reminder:'.$rid,'schedule'=>array_merge($schedule,['title'=>'Native reviewed follow-up'])]);
    $updated=schedule_mysql_approve($root);
    schedule_mysql_check(schedule_mysql_reminder($rid)['title']==='Native reviewed follow-up'&&(int)schedule_mysql_reminder($rid)['revision']===2,'native reminder update uses only the server-curated exact reminder source');
    schedule_mysql_check(isset($updated['action']['result']['source_transition']['after_revisions']['reminder:'.$rid])
        &&$updated['loaded']['evidence']['reminder:'.$rid]['kind']==='reminder','native reminder update retains an exact verified own-source transition');
    schedule_mysql_receipt($updated,'native reminder update effect and receipt commit together');
    schedule_mysql_replay($updated,'native updated reminder can be reloaded through its own changed source');

    $root=action_mysql_review(schedule_mysql_source(['reminder:'.$rid],'reminders.read','stop the exact repeat series'),'reminder.cancel',['target_ref'=>'reminder:'.$rid]);
    $cancelled=schedule_mysql_approve($root);
    schedule_mysql_check(schedule_mysql_reminder($rid)['status']==='cancelled'&&schedule_mysql_reminder($rid)['next_at']===null,'native reminder cancellation stops the exact current series');
    schedule_mysql_check($cancelled['loaded']['evidence']['reminder:'.$rid]['kind']==='action_tombstone'
        &&$cancelled['action']['result']['source_transition']['removed_refs']===['reminder:'.$rid],'native cancelled reminder reload uses its verified narrow tombstone');
    schedule_mysql_replay($cancelled,'native cancelled reminder replay does not recreate or reactivate its source');
    $retainedRef=$root['pending']['goal']['primary'];$retainedTaskId=(int)substr($retainedRef,5);$retainedTask=action_mysql_task($retainedTaskId);
    schedule_mysql_check($cancelled['loaded']['run']['goal']===$root['pending']['run']['goal']
        &&$cancelled['loaded']['goal']['capability_sources']['reminder:'.$rid]==='reminders.read'
        &&isset($cancelled['loaded']['evidence'][$retainedRef])&&$retainedTask['metadata']['task_status']==='open',
        'supporting reminder cancellation retains the immutable original declaration and revalidates the unaffected task');
    $unchangedReceipts=schedule_mysql_count('agent_workflow_receipts');
    $proof=$cancelled['action']['result']['source_transition'];$forged=$proof;
    $forged['after_revisions'][$retainedRef]=null;$forged['removed_refs'][]=$retainedRef;$rejected=false;
    try{vp3_initiative_action_sources_current_v1(db(),$root['pending'],$cancelled['action']['goal']['plan'],$cancelled['domain'],$forged);}
    catch(RuntimeException $error){$rejected=$error->getCode()===409;}
    schedule_mysql_check($rejected&&schedule_mysql_count('agent_workflow_receipts')===$unchangedReceipts
        &&action_mysql_task($retainedTaskId)===$retainedTask,
        'a forged transition cannot omit an unaffected supporting source or mutate its record');
    $forgedRoot=$root['pending'];unset($forgedRoot['goal']['sources']['reminder:'.$rid],$forgedRoot['goal']['capability_sources']['reminder:'.$rid]);$rejected=false;
    try{vp3_initiative_action_sources_current_v1(db(),$forgedRoot,$cancelled['action']['goal']['plan'],$cancelled['domain'],$proof);}
    catch(RuntimeException $error){$rejected=$error->getCode()===409;}
    schedule_mysql_check($rejected&&schedule_mysql_count('agent_workflow_receipts')===$unchangedReceipts,
        'dropping a source declaration from the original proposal fails its canonical goal binding');
    db()->prepare('UPDATE agent_memory_items SET subject=? WHERE id=?')->execute(['Retained source changed after cancellation',$retainedTaskId]);$rejected=false;
    try{action_mysql_load($root['message_id']);}
    catch(Vp3InitiativeSourcesChangedV1 $error){$rejected=$error->getCode()===409;}
    catch(Vp3InitiativeSourceProofUnavailableV1 $error){$rejected=$error->family==='tasks'&&$error->getCode()===503&&$error->getPrevious()===null;}
    finally{db()->prepare('UPDATE agent_memory_items SET subject=? WHERE id=?')->execute([$retainedTask['subject'],$retainedTaskId]);}
    schedule_mysql_check($rejected&&schedule_mysql_reminder($rid)['status']==='cancelled'
        &&schedule_mysql_count('agent_workflow_receipts')===$unchangedReceipts,
        'a later unrelated task change invalidates cancellation replay without changing the verified effect or receipt');
    schedule_mysql_replay($cancelled,'restoring the exact retained source permits readonly cancellation reconciliation');

    $day=(new DateTimeImmutable('+2 days'))->format('Y-m-d');
    $event=['title'=>'Native launch review','description'=>'Review the exact launch.','location'=>'Office','timezone'=>'America/New_York',
        'date'=>$day,'start_time'=>'09:00','end_time'=>'10:00','all_day'=>false,'recurrence'=>'none','agent_notifications'=>false];
    $root=action_mysql_review(action_mysql_seed('Native personal-calendar creation source'),'calendar.create',['event'=>$event]);
    schedule_mysql_check(schedule_mysql_count('user_calendar_events')===0,'actual personal-calendar preview stores no event before exact approval');
    $calendar=schedule_mysql_approve($root);$eid=$calendar['domain']['record_id'];$row=user_calendar_event_v1300(db(),1,$eid);
    schedule_mysql_check($row&&$row['title']===$event['title']&&(int)$row['created_by_agent_id']===7&&$row['source']==='agent','native calendar create uses actual owner/Agent and canonical personal event ledger');
    schedule_mysql_receipt($calendar,'native calendar create has one atomic completed workflow/action receipt');
    schedule_mysql_replay($calendar,'native calendar completion replay does not duplicate its event');
    $root=action_mysql_review(schedule_mysql_source(['calendar:event:'.$eid],'calendar.read','the exact current calendar event'),'calendar.update',
        ['target_ref'=>'calendar:event:'.$eid,'event'=>array_merge($event,['start_time'=>'10:00','end_time'=>'11:00'])]);
    $moved=schedule_mysql_approve($root);$row=user_calendar_event_v1300(db(),1,$eid);
    schedule_mysql_check($row['start_at_utc']===user_calendar_local_range_v1300(array_merge($event,['start_time'=>'10:00','end_time'=>'11:00']))['start_at_utc'],'native calendar update preserves reviewed local time and timezone conversion');
    schedule_mysql_receipt($moved,'native calendar update effect and workflow receipt commit together');
    schedule_mysql_replay($moved,'native moved event remains verifiable against its exact changed source');
    $root=action_mysql_review(schedule_mysql_source(['calendar:event:'.$eid],'calendar.read','cancel the exact current event'),'calendar.cancel',['target_ref'=>'calendar:event:'.$eid]);
    $retired=schedule_mysql_approve($root);
    schedule_mysql_check(user_calendar_event_v1300(db(),1,$eid)['status']==='cancelled'&&$retired['loaded']['evidence']['calendar:event:'.$eid]['kind']==='action_tombstone','native calendar cancellation verifies actual status and removed primary source');
    schedule_mysql_replay($retired,'native cancelled event replay never recreates the event');

    foreach(['reminder','calendar'] as $family){
        $args=$family==='reminder'?['schedule'=>array_merge($schedule,['title'=>'Native rollback reminder'])]:['event'=>array_merge($event,['title'=>'Native rollback calendar'])];
        $root=action_mysql_review(action_mysql_seed('Native '.$family.' rollback source'),$family.'.create',$args);$table=$family==='reminder'?'agent_reminders_v1':'user_calendar_events';
        $before=[schedule_mysql_count($table),schedule_mysql_count('agent_workflow_receipts'),schedule_mysql_count('agent_reminder_turns_v1')];$effectSeen=false;$blocked=false;
        try{
            action_mysql_decide($root['pending'],['action_decision'=>'approve'],'Apply the exact '.$family.' rollback probe',null,
                static function()use($table,$before,&$effectSeen):void{
                    if(db()->inTransaction()&&schedule_mysql_count($table)>$before[0]){$effectSeen=true;throw new RuntimeException('Native schedule rollback probe.',409);}
                });
        }catch(RuntimeException $error){if($error->getCode()!==409)throw $error;$blocked=true;}
        schedule_mysql_check($blocked&&$effectSeen&&$before===[schedule_mysql_count($table),schedule_mysql_count('agent_workflow_receipts'),schedule_mysql_count('agent_reminder_turns_v1')],
            'native '.$family.' verified effect rolls back atomically with domain and workflow receipts on final authority rejection');
        $loaded=action_mysql_load($root['message_id']);
        schedule_mysql_check($loaded['domain_action']['run']['status']==='approval_pending'&&!db()->inTransaction(),'native '.$family.' rollback leaves the exact review pending and releases transaction ownership');
    }
    echo 'INITIATIVE_SCHEDULE_ACTIONS_MYSQL=PASS ('.($GLOBALS['nativeScheduleChecks']??0).' cases; actual MySQL, full owner review, source transitions, canonical schedule effects and atomic receipts)'.PHP_EOL;
}finally{
    if(db()->inTransaction())db()->rollBack();$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');
}
