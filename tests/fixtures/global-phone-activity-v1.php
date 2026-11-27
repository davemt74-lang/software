<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/agent-phone-shell-v1.php';
require_once dirname(__DIR__,2).'/includes/notifications.php';
function db():PDO{return $GLOBALS['phoneTestDb'];}
$pdo->exec("CREATE TABLE notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,title TEXT,body TEXT,target_url TEXT,source_type TEXT,source_id INTEGER,is_read INTEGER NOT NULL DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$invite=vp3_phone_owner_v1($pdo,1,'invite',['request_id'=>bin2hex(random_bytes(16))]);
$phone=vp3_phone_device_v1($pdo,'','claim',['name'=>'Field phone','invite'=>$invite['invite'],'claim_key'=>bin2hex(random_bytes(32))]);$id=$phone['device']['id'];
vp3_phone_chat_map_command_v1($pdo,$user,'approve',['device_id'=>$id,'generation'=>$phone['device']['generation'],'request_id'=>bin2hex(random_bytes(16)),
    'chat_origin'=>['agent_id'=>7,'conversation_id'=>2]]);
$rid='a91619e0-e124-47a3-9669-431186a94953';
$send=static fn(string $r)=>vp3_paired_phone_chat_v1($pdo,$phone['token'],'chat_send',['device_id'=>$id,'request_id'=>$r,'message'=>'Private words stay in the personal Chat.']);$send($rid);
$_SERVER['SCRIPT_NAME']='/vp3/admin/phone.php';
$updates=vp3_agent_phone_shell_activity_v1($pdo,$user);
phone_chat_test(count($updates)===1&&$updates[0]['conversation_id']===2,'signed-in owner receives connected phone activity while in Admin');
phone_chat_test(!str_contains(json_encode($updates),'Private words'),'generic announcement does not copy private message text into Admin Agent');
$notice=$pdo->query('SELECT * FROM notifications')->fetch();
phone_chat_test(str_contains($notice['target_url'],'conversation_id=2')&&str_contains($notice['target_url'],'message_id='),'notice opens the exact native private Chat message');
$messages=$pdo->query('SELECT * FROM chat_messages WHERE conversation_id=2')->fetchAll();
$history=vp3_paired_phone_owner_history_v1($pdo,1,7,2,$messages,array_fill_keys(array_column($messages,'id'),true));
phone_chat_test(str_contains(json_encode($history),'Private words'),'the original Chat marker still hydrates its actual private message after the Admin announcement');
phone_chat_test(vp3_agent_phone_shell_activity_v1($pdo,$user)===$updates&&(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===1,'a lost response or reload replays the same unread receipt without duplicate cards');
$pdo->exec('UPDATE vp3_paired_phone_turns_v1 SET attempted_at=0');$send('a91619e0-e124-47a3-9669-431186a94954');
$pdo->exec("CREATE TRIGGER fail_phone_notice BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT,'fixture notice failure'); END");
try{vp3_agent_phone_shell_activity_v1($pdo,$user);throw new LogicException('Missing rollback');}catch(PDOException){}
phone_chat_test((int)$pdo->query('SELECT announced_prompt FROM vp3_paired_phone_turns_v1 ORDER BY user_message_id DESC LIMIT 1')->fetchColumn()===0,'notification failure rolls back the native announcement flag');
$pdo->exec('DROP TRIGGER fail_phone_notice');$retry=vp3_agent_phone_shell_activity_v1($pdo,$user);
phone_chat_test(count($retry)===2&&(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===2,'failed notification is retried once without losing the native Chat message');
$status=static fn()=>vp3_phone_device_v1($pdo,$phone['token'],'status',['device_id'=>$id])['device'];$d=$status();$p=$d['communication'];
vp3_phone_communication_owner_v1($pdo,1,['device_id'=>$id,'generation'=>$d['generation'],'communication_revision'=>$p['revision'],'owner_calls'=>false,'owner_requests'=>true]);
$d=$status();$p=$d['communication'];
$p=vp3_phone_device_v1($pdo,$phone['token'],'save_communication_profile',['device_id'=>$id,'generation'=>$d['generation'],'communication_revision'=>$p['revision'],
    'phone_e164'=>$p['phone_e164'],'holder_calls'=>$p['holder_calls'],'holder_proactive'=>$p['holder_proactive'],'holder_requests'=>true,'holder_memory'=>$p['holder_memory'],
    'timezone'=>$p['timezone'],'window_start'=>$p['window_start'],'window_end'=>$p['window_end'],'max_calls_per_day'=>$p['max_calls_per_day'],'min_gap_minutes'=>$p['min_gap_minutes']])['device']['communication'];
$bound=['device_id'=>$id,'generation'=>$d['generation'],'profile_id'=>$p['profile_id'],'profile_revision'=>$p['revision']];$exchange=str_repeat('f',32);
vp3_phone_information_owner_v1($pdo,1,'information_create',$bound+['exchange_id'=>$exchange,'kind'=>'question','message'=>'When is the delivery?']);
vp3_phone_device_v1($pdo,$phone['token'],'information_accept',$bound+['exchange_id'=>$exchange,'version'=>1]);
vp3_phone_device_v1($pdo,$phone['token'],'information_reply',$bound+['exchange_id'=>$exchange,'version'=>2,'response'=>'Private request response.']);
$requestUpdates=vp3_agent_phone_shell_activity_v1($pdo,$user);$last=end($requestUpdates);
phone_chat_test(count($requestUpdates)===3&&str_contains($last['speech'],'information request'),'a real holder information reply produces its own durable cross-page announcement');
phone_chat_test((string)$pdo->query("SELECT response FROM vp3_phone_information_v1 WHERE exchange_id='$exchange'")->fetchColumn()==='Private request response.'
    &&(string)$pdo->query('SELECT map_link FROM vp3_phone_chat_turns_v1 ORDER BY assistant_message_id DESC LIMIT 1')->fetchColumn()==='information','the exact request response and its native information-exchange Chat card remain available for review');
phone_chat_test(vp3_agent_phone_shell_activity_v1($pdo,$user)===$requestUpdates,'repeated polls do not duplicate information-response cards');
$GLOBALS['phoneTestOwner']=2;$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']=vp3_catch_up_page_binding_v1(current_user());
phone_chat_test(vp3_agent_phone_shell_activity_v1($pdo,current_user())===[],'another signed-in account cannot read the first owner alerts');
unset($GLOBALS['phoneTestOwner']);$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']='old-page';
phone_chat_reject(fn()=>vp3_agent_phone_shell_activity_v1($pdo,$user),403,'a stale account page cannot consume phone activity');
$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']=vp3_catch_up_page_binding_v1($user);$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1];
phone_chat_reject(fn()=>vp3_agent_phone_shell_activity_v1($pdo,$user),403,'a participant context cannot consume owner phone activity');unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
echo 'GLOBAL_PHONE_ACTIVITY_V1=PASS'.PHP_EOL;
