<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/phone-call-continuity-v1.php';
if(!function_exists('csrf_token')){function csrf_token():string{return $GLOBALS['continuityCsrf']??'continuity-fixture-csrf';}}
$u=user();$input=['owner'=>'1','agent_id'=>'0','conversation_id'=>'11'];
$before=[];foreach(['chat_conversations','phone_browser_calls_v1','phone_browser_contexts_v1'] as $t)$before[$t]=$pdo->query('SELECT * FROM '.$t)->fetchAll();
$s=vp3_phone_call_window_scope_v1($pdo,$u,$input);
c($s['owner_user_id']===1&&$s['agent_id']===0&&$s['conversation_id']===11,'calling window resolves the exact existing owner Chat');
$empty=vp3_phone_call_window_scope_v1($pdo,$u,['owner'=>'1']);
c($empty['conversation_id']===0,'opening an account Phone window does not create a conversation');
denies(fn()=>vp3_phone_call_window_scope_v1($pdo,null,$input),401,'calling window requires the signed-in owner');
denies(fn()=>vp3_phone_call_window_scope_v1($pdo,$u,['owner'=>'2']+$input),403,'an old owner window cannot become another account’s phone');
bad(fn()=>vp3_phone_call_window_scope_v1($pdo,$u,['owner'=>['1']]+$input),'calling window rejects array-shaped account identities');
denies(fn()=>vp3_phone_call_window_scope_v1($pdo,$u,['conversation_id'=>'999999']+$input),403,'unknown conversations cannot authorize a calling window');
denies(fn()=>vp3_phone_call_window_scope_v1($pdo,$u,['agent_id'=>'8']+$input),403,'a foreign Agent cannot authorize a calling window');
foreach($before as $t=>$rows)c($pdo->query('SELECT * FROM '.$t)->fetchAll()===$rows,'calling window HTML authorization leaves '.$t.' unchanged');
$binding=vp3_catch_up_page_binding_v1($u);
c(vp3_phone_call_binding_matches_v1($u,$binding),'the current account/session page binding is accepted');
c(!vp3_phone_call_binding_matches_v1(user(2),$binding),'another account cannot reuse the owner page binding');
c(!vp3_phone_call_binding_matches_v1(null,$binding),'logged-out browser cannot reuse a calling window binding');
$GLOBALS['continuityCsrf']='rotated-session-csrf';
c(!vp3_phone_call_binding_matches_v1($u,$binding),'rotated session authorization fences the older calling document');
unset($GLOBALS['continuityCsrf']);
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
denies(fn()=>vp3_phone_call_window_scope_v1($pdo,$u,$input),403,'inactive account cannot reopen a cached calling window');
$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
echo 'PHONE_CALL_CONTINUITY_NATIVE=PASS ('.$checks.' checks)'.PHP_EOL;
