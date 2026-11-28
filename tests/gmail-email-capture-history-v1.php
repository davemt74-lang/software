<?php
declare(strict_types=1);

// Reuse native MySQL owner/session/grant acceptance, not a second Gmail store.
require __DIR__.'/gmail-email-capture-v1.php';
require_once dirname(__DIR__).'/includes/agent-brain-v82.php';
require_once dirname(__DIR__).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__).'/includes/agent-task-lists-v1.php';
require_once dirname(__DIR__).'/includes/personal-capabilities-v242.php';

// Load the unmodified native role readers without replacing the fixture's transport/bootstrap helpers.
function gh_native_role_function(string $name): void
{
    if(function_exists($name))return;
    $tokens=token_get_all(file_get_contents(dirname(__DIR__).'/includes/permissions.php'));
    for($i=0;$i<count($tokens);$i++){
        if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
        $j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;
        if(!is_array($tokens[$j])||$tokens[$j][0]!==T_STRING||$tokens[$j][1]!==$name)continue;
        $body='';$depth=0;$opened=false;
        for($j=$i;$j<count($tokens);$j++){$part=is_array($tokens[$j])?$tokens[$j][1]:$tokens[$j];$body.=$part;
            if($part==='{'){$opened=true;$depth++;}elseif($part==='}'&&--$depth===0&&$opened){eval($body);return;}}
    }
    throw new RuntimeException('Missing native role reader '.$name);
}
foreach(['user_roles','valid_role','user_account_types_for_user_id','user_roles_for_user','user_has_role','permissions_schema_ready'] as $native)gh_native_role_function($native);

$historyChecks=0;$historyModels=0;
function gh_check(bool $ok,string $label): void
{if(!$ok)throw new RuntimeException($label);++$GLOBALS['historyChecks'];echo 'PASS '.$label.PHP_EOL;}
function gh_model($prompt,$service,$control): array
{++$GLOBALS['historyModels'];$control['before_gmail_capture_dispatch']();return ['ok'=>true,'answer'=>gc_answer()];}
function gh_history(?string $cursor=null): array
{return gmail_email_capture_history_v1(db(),pec_user_v1(),$GLOBALS['gcPrincipal'],11,$GLOBALS['gcSource'],$cursor,gc_current(),'gc_http');}
function gh_get(string $id): array
{return gmail_email_capture_get_v1(db(),pec_user_v1(),$GLOBALS['gcPrincipal'],11,$GLOBALS['gcSource'],$id,gc_current(),'gc_http');}
function gh_finding(string $kind,array $fields,?string $capture=null): array
{return gmail_email_capture_finding_v1(db(),pec_user_v1(),$GLOBALS['gcPrincipal'],11,$GLOBALS['gcSource'],$kind,
    ['text'=>$GLOBALS['gcText'],'fields'=>$fields]+($capture!==null?['capture_id'=>$capture]:[]),gc_current(),'gc_http');}

gh_check(gh_history()['items']===[],'a newly installed source has no invented analysis history');
$ids=[];
for($i=0;$i<13;$i++)$ids[]=gc_analyze(['request_id'=>bin2hex(random_bytes(16))],'gh_model')['id'];
$sameEpoch=(int)floor(microtime(true)*1000000);$pdo->prepare('UPDATE agent_gmail_captures_v1 SET created_epoch=?')->execute([$sameEpoch]);
$calls=$historyModels;$first=gh_history();$second=gh_history($first['next_cursor']);
$seen=array_merge(array_column($first['items'],'id'),array_column($second['items'],'id'));
gh_check(count($first['items'])===10&&count($second['items'])===3&&count(array_unique($seen))===13&&$second['next_cursor']===null,
    'history uses a stable bounded cursor even when all analysis timestamps tie');
gh_check($historyModels===$calls,'listing and paging saved analyses dispatch no model calls');
$raceId=$ids[12];$pdo->prepare("UPDATE agent_gmail_captures_v1 SET state='dispatched',lease_epoch=? WHERE id=?")->execute([time()-1,$raceId]);
$stale=gmail_email_capture_row_v1($pdo,1,7,11,$raceId);$stale['state']='preparing';$expired=gmail_email_capture_expire_v1($pdo,$stale);
gh_check($expired['reason']==='interrupted','a stale preparing read cannot mislabel a physically dispatched attempt as uncharged');
$late=gc_analyze(['request_id'=>bin2hex(random_bytes(16))],static function($prompt,$service,$control)use($pdo):array{
    ++$GLOBALS['historyModels'];
    $control['before_gmail_capture_dispatch']();$id=substr($service['job_key'],strlen('gmail-capture:'));
    $pdo->prepare('UPDATE agent_gmail_captures_v1 SET lease_epoch=? WHERE id=?')->execute([time()-1,$id]);
    $row=gmail_email_capture_expire_v1($pdo,gmail_email_capture_row_v1($pdo,1,7,11,$id));
    gh_check($row['state']==='failed'&&$row['reason']==='interrupted','history can report an overdue in-flight attempt without authorizing a redispatch');
    return ['ok'=>true,'answer'=>gc_answer()];
});
gh_check($late['state']==='ready'&&$late['received_response_retained'],'a late physical response survives history expiry and completes its original saved analysis');
$calls=$historyModels;
gh_check(!str_contains(json_encode($first),str_repeat('a',64))&&!str_contains(json_encode($first),'owner@example.test'),
    'history summaries expose neither Gmail authority nor mailbox tokens');
$old=$ids[0];$restored=gh_get($old);
gh_check($restored['selected_text']===$GLOBALS['gcText']&&$restored['result']['product_query']==='Example Camera X2'&&$restored['mode']==='text'&&$historyModels===$calls,
    'reopening returns the exact saved selection and analysis without inference');
$renewed=$GLOBALS['gcSource'];$renewed['expires']=time()+800;
gh_check(count(gmail_email_capture_history_v1($pdo,pec_user_v1(),$GLOBALS['gcPrincipal'],11,$renewed,null,gc_current(),'gc_http')['items'])===10,
    'reopening the same source with a renewed short-lived reference keeps its saved history');
$other=vp3_agent_chat_principal_v380(pec_user_v1(),vp3_agent_chat_resolve_agent_v380($pdo,pec_user_v1(),8));
gh_check(gmail_email_capture_history_v1($pdo,pec_user_v1(),$other,13,$GLOBALS['gcSource'],null,static fn()=>true,'gc_http')['items']===[],
    'another owned Agent gets no fallback into this Agent’s analysis history');
gh_check(gmail_email_capture_history_v1($pdo,pec_user_v1(),$GLOBALS['gcPrincipal'],12,$GLOBALS['gcSource'],null,static fn()=>true,'gc_http')['items']===[],
    'another owned Chat gets no fallback into this Chat’s analysis history');
gc_reject(static fn()=>gmail_email_capture_history_v1($pdo,pec_user_v1(),$other,13,$GLOBALS['gcSource'],$first['next_cursor'],static fn()=>true,'gc_http'),
    'a history cursor cannot move to another Agent',[422]);
gc_reject(static fn()=>gh_history('00'.substr($first['next_cursor'],2)),'a modified history cursor cannot select a saved range',[422]);

$fields=['title'=>'Saved analysis event','timezone'=>'America/Phoenix','date'=>'2026-12-12','start_time'=>'10:00','end_date'=>'2026-12-12','end_time'=>'11:00','location'=>'Phoenix'];
$save=static fn(array $f,string $id)=>gmail_email_capture_save_v1($pdo,pec_user_v1(),$GLOBALS['gcPrincipal'],11,$GLOBALS['gcSource'],'calendar',
    ['text'=>$GLOBALS['gcText'],'fields'=>$f,'capture_id'=>$id],gc_current(),'gc_http');
$before=(int)$pdo->query('SELECT COUNT(*) FROM user_calendar_events')->fetchColumn();$event=$save($fields,$old);$again=$save($fields,$old);
gh_check($event===$again&&(int)$pdo->query('SELECT COUNT(*) FROM user_calendar_events')->fetchColumn()===$before+1,
    'analysis-linked calendar saves keep one canonical event and one replayable receipt');
gh_check($event['action']['href']==='https://cloud.example/calendar-event.php?id='.$event['id']&&$event['action']['available'],
    'the calendar receipt opens the real owned event editor');
$same=$save($fields,$ids[1]);
gh_check($same['id']===$event['id']&&count(gh_get($ids[1])['actions'])===1&&count(gh_get($old)['actions'])===1,
    'two analyses can reference one canonical event without duplicating its effect');
$pdo->prepare("UPDATE user_calendar_events SET status='cancelled',title='Reviewed event title' WHERE id=?")->execute([$event['id']]);
$updated=gh_get($old)['actions'][0];
gh_check($updated['status']==='cancelled'&&$updated['title']==='Reviewed event title','saved action cards read current canonical state instead of a copied receipt title');
$reminderFields=['title'=>'Saved analysis reminder','timezone'=>'America/Phoenix','start_local'=>'2026-12-11T10:00'];
$reminder=gmail_email_capture_save_v1($pdo,pec_user_v1(),$GLOBALS['gcPrincipal'],11,$GLOBALS['gcSource'],'reminder',
    ['text'=>$GLOBALS['gcText'],'fields'=>$reminderFields,'capture_id'=>$old],gc_current(),'gc_http');
gh_check($reminder['id']>0&&$reminder['action']['record_id']===$reminder['id']&&str_contains($reminder['action']['detail'],'America/Phoenix'),
    'a reminder receipt points at its canonical reminder and reviewed wall-clock schedule');
$pdo->prepare('DELETE FROM agent_reminders_v1 WHERE id=?')->execute([$reminder['id']]);
$actions=gh_get($old)['actions'];$missing=array_values(array_filter($actions,static fn($a)=>$a['kind']==='reminder'))[0];
gh_check(!$missing['available']&&$missing['status']==='unavailable','deleting a native reminder leaves a truthful unavailable reference, not an invented active reminder');

$before=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();$chatFields=['message'=>'Reviewed finding <script>untrusted()</script>'];
$shared=gh_finding('chat',$chatFields,$old);$replay=gh_finding('chat',$chatFields,$old);
gh_check($shared===$replay&&(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$before+1,
    'a reviewed Chat finding and lost-response replay save exactly one canonical message');
$q=$pdo->prepare('SELECT * FROM chat_messages WHERE id=?');$q->execute([$shared['id']]);$message=$q->fetch(PDO::FETCH_ASSOC);$context=json_decode($message['context_json'],true);
$origin=json_decode(ai_decrypt_secret($context['email_source_enc']),true);
gh_check((int)$message['conversation_id']===11&&(int)$message['user_id']===1&&$message['role']==='user'&&str_contains($message['message'],$chatFields['message'])
    &&$origin['message']==='capture_one'&&!isset($origin['grant']),'Chat handoff is an explicit owner finding in the original Chat with encrypted source provenance');
gh_check($historyModels===$calls,'saving or replaying calendar, reminder and Chat actions runs no new model');
gc_reject(static fn()=>gh_finding('chat',['message'=>'Denied fields','recipient'=>'someone@example.test'],$old),'a finding cannot introduce recipients or email-send authority',[422]);
gc_reject(static fn()=>gh_finding('chat',$chatFields,str_repeat('0',32)),'a finding cannot attach a forged saved analysis',[409]);

// Canonical scoped Brain tables: same schema used by the existing native task-list acceptance.
$pdo->exec("CREATE TABLE agent_chat_archive(id BIGINT PRIMARY KEY,user_agent_id INT,INDEX idx_agent_archive_agent_created_v410(user_agent_id));
    CREATE TABLE agent_memory_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,user_agent_id INT NULL,memory_type VARCHAR(40),subject VARCHAR(190),memory_text LONGTEXT,memory_hash CHAR(40),memory_scope_version INT,source_archive_id BIGINT NULL,confidence FLOAT,occurrence_count INT,first_seen_at DATETIME,last_seen_at DATETIME,is_active TINYINT,metadata_json LONGTEXT,UNIQUE KEY uq_task_hash(user_id,memory_hash),INDEX idx_agent_memory_agent_type_v410(user_agent_id,memory_type),INDEX idx_agent_memory_agent_occurrence_v410(user_agent_id,occurrence_count)) ENGINE=InnoDB");
vp3_task_lists_schema_v1($pdo);
$pdo->exec("UPDATE users SET role='artist' WHERE id=1; CREATE TABLE permissions(permission_key VARCHAR(120) PRIMARY KEY);
    CREATE TABLE role_permissions(role VARCHAR(40),permission_key VARCHAR(120),PRIMARY KEY(role,permission_key));
    INSERT INTO role_permissions VALUES('artist','agent_brain.access')");
$GLOBALS['pecSettings']['personal_capabilities_seed_v242']='1';
$taskFields=['title'=>'Review the product finding','notes'=>'Owner-reviewed notes from the saved analysis.'];
$task=gh_finding('task',$taskFields,$old);$taskReplay=gh_finding('task',$taskFields,$old);
gh_check($task===$taskReplay&&$task['action']['available']&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===1,
    'task handoff and replay use one canonical Brain task');
$q=$pdo->prepare('SELECT * FROM agent_memory_items WHERE id=?');$q->execute([$task['id']]);$nativeTask=$q->fetch(PDO::FETCH_ASSOC);$meta=json_decode($nativeTask['metadata_json'],true);
$taskSource=json_decode(ai_decrypt_secret($meta['email_capture_source_enc']),true);
gh_check((int)$nativeTask['user_id']===1&&(int)$nativeTask['user_agent_id']===7&&$nativeTask['subject']===$taskFields['title']&&$nativeTask['memory_text']===$taskFields['notes']
    &&$taskSource['message']==='capture_one'&&!isset($taskSource['grant']),'the task stays in its exact private Agent scope with reviewed notes and encrypted email provenance');
$pdo->prepare('UPDATE agent_memory_items SET is_active=0 WHERE id=?')->execute([$task['id']]);$replay=gh_finding('task',$taskFields,$old);
gh_check(!$replay['action']['available']&&(int)$pdo->query('SELECT is_active FROM agent_memory_items WHERE id='.$task['id'])->fetchColumn()===0,
    'retrying a removed task does not reactivate or duplicate it');
$pdo->exec("DELETE FROM role_permissions WHERE permission_key='agent_brain.access'");
$before=(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn();
gc_reject(static fn()=>gh_finding('task',['title'=>'Denied task','notes'=>'Role revoked'],$old),'native Brain permission revocation blocks a task handoff',[403]);
gh_check((int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===$before,'revoked task authority creates no canonical task');
$pdo->exec("INSERT INTO role_permissions VALUES('artist','agent_brain.access')");

$pdo->exec("CREATE TRIGGER gmail_capture_receipt_failure BEFORE INSERT ON agent_gmail_capture_actions_v1 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture receipt failure'");
foreach (['chat'=>['message'=>'Must roll back this Chat finding'],'task'=>['title'=>'Must roll back this task','notes'=>'No partial save']] as $kind=>$f) {
    $table=$kind==='chat'?'chat_messages':'agent_memory_items';$count=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();$caught=false;
    try {gh_finding($kind,$f,$old);}catch(PDOException) {$caught=true;}
    gh_check($caught&&(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$count,'a failed action receipt rolls back the native '.$kind.' effect atomically');
}
$pdo->exec('DROP TRIGGER gmail_capture_receipt_failure');
$pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7');
gc_reject(static fn()=>gh_history(),'revoked Gmail access hides the saved analysis list');
gc_reject(static fn()=>gh_get($old),'revoked Gmail access hides reopened results and native action links');
$pdo->exec('UPDATE agent_connections_v1 SET read_enabled=1 WHERE id=7');
$pdo->exec('DROP TABLE agent_gmail_capture_actions_v1');
gh_check(!gmail_email_capture_ready_v1($pdo),'an old installation cannot claim action-history storage is ready');
$count=$historyModels;gc_reject(static fn()=>gh_history(),'history does not silently install missing storage',[503]);
gh_check($historyModels===$count,'missing history storage cannot cause inference');
gmail_email_capture_install_v1($pdo);gmail_email_capture_install_v1($pdo);
gh_check(gmail_email_capture_ready_v1($pdo)&&count(gh_history()['items'])===10,'explicit idempotent upgrade restores history while retaining previous analyses');
echo 'GMAIL_EMAIL_CAPTURE_HISTORY=PASS ('.$historyChecks.' assertions, native MySQL)'.PHP_EOL;
