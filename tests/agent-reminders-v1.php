<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-reminders-v1.php';
require dirname(__DIR__).'/includes/notifications.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool{$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();}
function url(string $path): string{return $path;}
function has_permission(string $permission,array $user): bool{return (int)$user['id']!==3;}
$checks=0;
function check(bool $ok,string $why): void{if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
function rejects(callable $fn,string $why): void{try{$fn();}catch(Throwable $e){check(true,$why);return;}throw new RuntimeException('Expected rejection: '.$why);}
function countrows(string $table,string $where='1=1'): int{return (int)db()->query("SELECT COUNT(*) FROM $table WHERE $where")->fetchColumn();}
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER)');$pdo->exec('INSERT INTO users VALUES(1,1),(2,1),(3,1)');
$pdo->exec('CREATE TABLE notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,title TEXT,body TEXT,target_url TEXT,source_type TEXT,source_id INTEGER,is_read INTEGER DEFAULT 0,created_at TEXT)');
$pdo->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,updated_at TEXT)');$pdo->exec("INSERT INTO chat_conversations VALUES(1,1,''),(2,2,'')");
$pdo->exec('CREATE TABLE chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT)');
check(!notification_requires_attention(['type'=>'personal_reminder','title'=>'Approval required','body'=>'Needs your response','requires_attention'=>1]),'reminder delivery cannot be repeated by generic proactive attention');
reminder_schema_v1($pdo);reminder_schema_v1($pdo);check(reminder_ready_v1($pdo),'idempotent schema installed');
$now=new DateTimeImmutable('2026-10-30T14:00:00Z');
$base=['title'=>'Do yoga','timezone'=>'America/Phoenix','start_local'=>'2026-10-30T08:00','recurrence'=>'daily','delivery'=>'both'];
$s=reminder_schedule_v1($base,$now);
$precise=reminder_schedule_v1([...$base,'start_local'=>'2026-10-30T07:02:37'],$now);check($precise['next_at']==='2026-10-30T14:02:37Z','relative reminder schedules retain seconds');check($s['next_at']==='2026-10-30T15:00:00Z','Phoenix local schedule converts to UTC');
foreach([['start_local'=>'2026-02-30T08:00'],['start_local'=>'2026-10-29T08:00'],['timezone'=>'Invalid/Zone'],['recurrence'=>'every_second'],['delivery'=>'sms'],['title'=>'']] as $patch)rejects(fn()=>reminder_schedule_v1([...$base,...$patch],$now),'invalid schedule rejected');
rejects(fn()=>reminder_schedule_v1([...$base,'timezone'=>'America/New_York','start_local'=>'2027-03-14T02:30'],$now),'nonexistent DST local time rejected');
$dst=[...$base,'timezone'=>'America/New_York','start_local'=>'2027-03-13T02:30'];
check(reminder_next_v1($dst,new DateTimeImmutable('2027-03-13T08:00Z'))==='2027-03-15T06:30:00Z','daily recurrence skips DST gap');
$dst['start_local']='2027-03-13T08:00';check(reminder_next_v1($dst,new DateTimeImmutable('2027-03-13T14:00Z'))==='2027-03-14T12:00:00Z','daily recurrence keeps local time across DST');
check(reminder_next_v1([...$base,'recurrence'=>'weekdays'],new DateTimeImmutable('2026-10-30T16:00Z'))==='2026-11-02T15:00:00Z','weekdays skip weekend');
check(reminder_next_v1([...$base,'recurrence'=>'weekly'],new DateTimeImmutable('2026-10-30T16:00Z'))==='2026-11-06T15:00:00Z','weekly preserves weekday');
check(reminder_next_v1($base,new DateTimeImmutable('2030-05-01T22:00Z'))==='2030-05-02T15:00:00Z','late catch-up computes next future wall time directly');
$create=['operation'=>'create','schedule'=>$base];
$r=reminder_execute_v1($pdo,1,'cloud:1','request-one','Remind me daily',$create,[],1,$now);
check(str_contains($r['answer'],'Saved reminder #1')&&countrows('agent_reminders_v1')===1,'explicit request saves reminder and returns authoritative receipt');
$r2=reminder_execute_v1($pdo,1,'cloud:1','request-one','Remind me daily',$create,[],1,$now);check($r===$r2&&countrows('agent_reminders_v1')===1,'replayed request is idempotent');
rejects(fn()=>reminder_execute_v1($pdo,1,'cloud:1','request-one','Changed payload',$create,[],1,$now),'idempotency key cannot be reused for another request');
check(reminder_tick_v1($pdo,1,new DateTimeImmutable('2026-10-30T14:59:59Z'))===0,'not delivered early');
$at=new DateTimeImmutable('2026-10-30T15:00:00Z');check(reminder_tick_v1($pdo,1,$at)===1,'due reminder delivers one notification and canonical Chat message');
check(countrows('notifications')===1&&countrows('chat_messages')===1,'delivery stores existing notification and Chat carriers');
check(reminder_tick_v1($pdo,1,$at)===0&&countrows('notifications')===1,'repeated scheduler tick does not duplicate delivery');
$claim=reminder_present_v1($pdo,1,['action'=>'claim'],$at);check((int)$claim['item']['reminder_id']===1,'visible tab claims due reminder');
check(reminder_present_v1($pdo,1,['action'=>'claim'],$at)['item']===null,'second tab cannot claim leased occurrence');
check(!reminder_present_v1($pdo,2,['action'=>'ack','id'=>$claim['item']['id'],'token'=>$claim['item']['token']],$at)['acknowledged'],'foreign owner cannot acknowledge occurrence');
check(reminder_present_v1($pdo,2,['action'=>'claim'],$at)['item']===null,'another account cannot read due reminders');
check(reminder_present_v1($pdo,1,['action'=>'ack','id'=>$claim['item']['id'],'token'=>$claim['item']['token']],$at)['acknowledged'],'delivery receipt is token and owner bound');
$rows=reminder_rows_v1($pdo,1);
$snooze=reminder_execute_v1($pdo,1,'cloud:1','request-snooze','Snooze ten minutes',['operation'=>'snooze','id'=>1,'minutes'=>10],$rows,1,$at);check(str_contains($snooze['answer'],'10 minutes'),'snooze reschedules current occurrence');
check(reminder_tick_v1($pdo,1,$at->modify('+9 minutes'))===0,'snooze does not deliver early');
check(reminder_tick_v1($pdo,1,$at->modify('+10 minutes'))===1&&countrows('notifications')===2,'snooze redelivers at requested time');
rejects(fn()=>reminder_execute_v1($pdo,1,'cloud:1','request-stale','Done',['operation'=>'complete','id'=>1],$rows,1,$at),'concurrent stale revision rejected');
$rows=reminder_rows_v1($pdo,1);$r=reminder_execute_v1($pdo,1,'cloud:1','request-done','Done',['operation'=>'complete','id'=>1],$rows,1,$at->modify('+11 minutes'));
check(str_contains($r['answer'],'Future repeats remain scheduled')&&reminder_rows_v1($pdo,1)[0]['next_at']==='2026-10-31T15:00:00Z','Done completes occurrence without cancelling tomorrow');
check(countrows('agent_reminder_occurrences_v1',"status='done'")===1,'completion state is durable');
$rows=reminder_rows_v1($pdo,1);rejects(fn()=>reminder_execute_v1($pdo,2,'cloud:2','request-foreign','Delete #1',['operation'=>'delete','id'=>1],$rows,2,$at),'foreign owner cannot mutate reminder');
reminder_tick_v1($pdo,1,$at->modify('+3 days'));check(countrows('agent_reminder_occurrences_v1')===2,'missed repeating deliveries coalesce into one catch-up occurrence');
$rows=reminder_rows_v1($pdo,1);reminder_execute_v1($pdo,1,'cloud:1','request-dismiss','Dismiss',['operation'=>'dismiss','id'=>1],$rows,1,$at->modify('+3 days'));
check(reminder_rows_v1($pdo,1)[0]['status']==='active','Dismiss does not cancel series');
$rows=reminder_rows_v1($pdo,1);reminder_execute_v1($pdo,1,'cloud:1','request-cancel','Cancel',['operation'=>'cancel','id'=>1],$rows,1,$at->modify('+3 days'));
check(reminder_tick_v1($pdo,1,$at->modify('+4 days'))===0,'Cancel stops future repeats');
$rows=reminder_rows_v1($pdo,1);reminder_execute_v1($pdo,1,'cloud:1','request-delete','Delete',['operation'=>'delete','id'=>1],$rows,1,$at);
check(countrows('agent_reminders_v1')===0&&countrows('agent_reminder_occurrences_v1')===0&&countrows('notifications')===0,'delete removes reminder occurrences and linked notifications');
check(countrows('chat_messages')===3,'delete retains ordinary Chat history');
$one=reminder_execute_v1($pdo,1,'cloud:1','request-once','Remind me once',['operation'=>'create','schedule'=>[...$base,'recurrence'=>'none']],[],1,$now);
$id=(int)$pdo->lastInsertId();$onceRows=reminder_rows_v1($pdo,1);$id=(int)$onceRows[0]['id'];
check(reminder_tick_v1($pdo,1,$at)===1,'one-time reminder fires once');
reminder_execute_v1($pdo,1,'cloud:1','request-once-done','Done',['operation'=>'complete','id'=>$id],reminder_rows_v1($pdo,1),1,$at);
check(reminder_rows_v1($pdo,1)[0]['status']==='finished'&&reminder_tick_v1($pdo,1,$at->modify('+1 day'))===0,'Done finishes a one-time reminder permanently');
reminder_execute_v1($pdo,1,'cloud:1','request-once-delete','Delete',['operation'=>'delete','id'=>$id],reminder_rows_v1($pdo,1),1,$at);
reminder_execute_v1($pdo,1,'cloud:1','request-edit','Remind me daily',$create,[],1,$now);$id=(int)reminder_rows_v1($pdo,1)[0]['id'];
reminder_tick_v1($pdo,1,$at);$oldLease=reminder_present_v1($pdo,1,['action'=>'claim'],$at);
reminder_execute_v1($pdo,1,'cloud:1','request-move','Move to nine',['operation'=>'update','id'=>$id,'schedule'=>[...$base,'start_local'=>'2026-10-30T09:00']],reminder_rows_v1($pdo,1),1,$at);
check(!reminder_present_v1($pdo,1,['action'=>'ack','id'=>$oldLease['item']['id'],'token'=>$oldLease['item']['token']],$at)['acknowledged'],'rescheduling invalidates an old presentation lease');
check(reminder_tick_v1($pdo,1,$at->modify('+59 minutes'))===0&&reminder_tick_v1($pdo,1,$at->modify('+1 hour'))===1,'updated schedule fires only at new time');
$lease=reminder_present_v1($pdo,1,['action'=>'claim'],$at->modify('+1 hour'));
check(reminder_present_v1($pdo,1,['action'=>'claim'],$at->modify('+1 hour +91 seconds'))['item']!==null,'abandoned tab lease expires for delivery recovery');
check(!reminder_present_v1($pdo,1,['action'=>'ack','id'=>$lease['item']['id'],'token'=>$lease['item']['token']],$at->modify('+1 hour +92 seconds'))['acknowledged'],'expired lease cannot acknowledge a newer presentation');
reminder_execute_v1($pdo,1,'cloud:1','request-edit-delete','Delete',['operation'=>'delete','id'=>$id],reminder_rows_v1($pdo,1),1,$at);
$future=reminder_now_v1()->modify('+1 hour')->setTimezone(new DateTimeZone('America/Phoenix'))->format('Y-m-d\TH:i');
$calls=[];$model=function($prompt)use(&$calls,$future){$calls[]=json_decode(substr($prompt,strpos($prompt,"\n")+1),true);return ['ok'=>true,'answer'=>json_encode(count($calls)===1?['operation'=>'clarify','question'=>'What time each morning?']:['operation'=>'create','schedule'=>['title'=>'Stretch','start_local'=>$future,'timezone'=>'America/Phoenix','recurrence'=>'daily','delivery'=>'both']])];};
$a=reminder_chat_v1($pdo,['id'=>1],'Remind me to stretch each morning','cloud:1','clarify-one',1,'America/Phoenix',$model);
check(str_contains($a['answer'],'What time')&&countrows('agent_reminders_v1')===0,'missing time prompts clarification without writing schedule');
$b=reminder_chat_v1($pdo,['id'=>1],'At eight','cloud:1','clarify-two',1,'America/Phoenix',$model);
check(countrows('agent_reminders_v1')===1&&$calls[1]['pending_user_requests']===['Remind me to stretch each morning'],'follow-up voice/chat answer completes pending request');
$a=reminder_chat_v1($pdo,['id'=>1],'What reminders do I have?','cloud:1','model-failed',1,'UTC',fn()=>['ok'=>false]);check(str_contains($a['answer'],'Nothing was changed'),'model outage produces no false success');
$a=reminder_chat_v1($pdo,['id'=>1],'What reminders do I have?','cloud:1','model-invalid',1,'UTC',fn()=>['ok'=>true,'answer'=>'not json']);check(str_contains($a['answer'],'Nothing was changed'),'malformed model output produces no changes');
rejects(fn()=>reminder_chat_v1($pdo,['id'=>3],'Remind me now','cloud:3','denied-one',0,'UTC',$model),'revoked Chat permission blocks reminder tool');
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');rejects(fn()=>reminder_chat_v1($pdo,['id'=>1],'Cancel reminder','cloud:1','denied-two',0,'UTC',fn()=>['ok'=>true,'answer'=>'{"operation":"list"}']),'account authority rechecked after inference');
echo "AGENT_REMINDERS_V1=PASS ($checks cases)\n";
