<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-tool-receipts-v1.php';
$checks=0;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
$created=vp3_tool_receipt_v1('reminders.manage','create','completed','',12);
check($created['changes_applied']&&$created['record_id']===12,'successful mutation has a server receipt and record reference');
$empty=vp3_tool_receipt_v1('reminders.manage','list','no_matches');
check(!$empty['changes_applied']&&str_contains(vp3_tool_diagnosis_v1(['receipt'=>$empty]),'no matching records'),'empty read is not diagnosed as lost access');
$pending=vp3_tool_receipt_v1('calendar.manage','prepare','clarification_required','confirmation_required');
check(!$pending['changes_applied']&&str_contains(vp3_tool_diagnosis_v1(['receipt'=>$pending]),'still pending'),'pending confirmation cannot be described as completed');
$failed=vp3_tool_receipt_v1('capabilities.inspect','inspect','failed','provider_unavailable');
check(str_contains(vp3_tool_diagnosis_v1(['receipt'=>$failed]),'AI service was unavailable'),'provider failure is distinguished from permission denial');
check(str_contains(vp3_tool_diagnosis_v1(['receipt'=>$created]),'current access and record state may have changed'),'historical success does not claim current record existence');
check(str_contains(vp3_tool_diagnosis_v1(null),'no verified tool receipt'),'untracked legacy result is explicitly unverified');
foreach([null,[],['contract'=>'vp3.tool-receipt.v1'],[...$created,'capability'=>'shell.execute'],[...$created,'operation'=>'eval'],[...$created,'record_id'=>'12'],[...$created,'status'=>'made_up'],[...$created,'checked_at'=>'yesterday'],[...$created,'reason'=>'SQL password'],[...$created,'reason'=>'execution_failed'],[...$created,'capability'=>'calendar.read','operation'=>'list']] as $bad)check(vp3_tool_receipt_normalize_v1($bad)===null,'malformed or unauthorized receipt is rejected');
$clean=vp3_tool_receipt_normalize_v1([...$empty,'changes_applied'=>true,'token'=>'secret']);check(!$clean['changes_applied']&&!array_key_exists('token',$clean),'normalization derives mutation state and strips arbitrary metadata');
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE chat_conversations(id INTEGER,user_id INTEGER,user_agent_id INTEGER);CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,role TEXT,context_json TEXT)');
$pdo->exec('INSERT INTO chat_conversations VALUES(1,1,NULL),(2,2,NULL),(3,1,7)');
$insert=$pdo->prepare('INSERT INTO chat_messages VALUES(?,?,?,?)');$insert->execute([1,1,'assistant',json_encode(['tool_receipt'=>$created])]);$insert->execute([2,2,'assistant',json_encode(['tool_receipt'=>$failed])]);$insert->execute([3,3,'assistant',json_encode(['tool_receipt'=>$empty])]);
$last=vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>1]);check($last['receipt']===$created,'same-owner system conversation recalls exact executor receipt');
check(vp3_tool_last_receipt_v1($pdo,['id'=>2],['conversation_id'=>1])===null,'foreign account cannot diagnose owner execution');
check(vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>3])===null,'system agent cannot borrow named-agent receipts');
check(vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>3,'user_agent_id'=>7])['receipt']===$empty,'exact named-agent scope can diagnose its own turn');
$insert->execute([4,1,'assistant','{"tool_receipt":{"contract":"forged"}}']);check(vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>1])===null,'invalid latest receipt does not revive an older success');
$insert->execute([5,1,'assistant',json_encode(['tool_receipt'=>$created])]);$insert->execute([6,1,'user','{}']);check(vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>1])!==null,'current diagnostic question may follow the recorded response');
$insert->execute([7,1,'user','{}']);check(vp3_tool_last_receipt_v1($pdo,['id'=>1],['conversation_id'=>1])===null,'an unanswered failed turn prevents attribution of older success');
echo "TOOL_RECEIPTS=PASS ($checks cases)\n";
