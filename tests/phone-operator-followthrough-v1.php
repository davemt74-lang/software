<?php
declare(strict_types=1);
// Production SQL on native MySQL; SQLite uses the same small syntax adapter as native task tests.
class OperatorFollowthroughPDO extends PDO {
    public bool $failMasterWrite=false;
    public function __construct(string $dsn,?string $user=null,?string $password=null,array $options=[]){
        parent::__construct($dsn,$user,$password,$options);
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            $this->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
            $this->sqliteCreateFunction('LAST_INSERT_ID',static fn($id)=>$id);
            $this->sqliteCreateFunction('GREATEST',static fn(...$values)=>max($values));
        }
    }
    public function prepare(string $sql,array $options=[]):PDOStatement|false{
        if($this->failMasterWrite&&str_contains($sql,'UPDATE artist_transcript_master_analysis_v237 SET analysis_json'))throw new PDOException('Injected source-receipt failure');
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$sql=preg_replace('/ON DUPLICATE KEY UPDATE/i','ON CONFLICT DO UPDATE SET',$sql);$sql=preg_replace('/VALUES\((\w+)\)/i','excluded.$1',$sql);}
        return parent::prepare($sql,$options);
    }
}
$GLOBALS['phone_fixture_pdo_class']=OperatorFollowthroughPDO::class;
require __DIR__.'/phone-operator-question-preparation-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-followthrough-v1.php';
vp3_operator_followthrough_stack_v1();
require_once dirname(__DIR__).'/includes/agent-memory-lifecycle-v123.php';
require_once dirname(__DIR__).'/includes/agent-task-lifecycle-v123.php';
function personal_capability_has_v242($key,$user):bool{return empty($GLOBALS['followthrough_denied'][$key]);}
function agent_brain_schema_ready():bool{return table_exists('agent_memory_items');}
function agent_brain_normalize($text):string{return mb_strtolower(trim($text));}
function user_data_policy_can_use_v236($pdo,$principal,$owner,$domain,$id,$public):bool{return empty($GLOBALS['followthrough_denied'][$id]);}

$before=$checks;
ddl('CREATE TABLE agent_memory_items(id '.($mysql?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT').',user_id '.($mysql?'INT UNSIGNED':'INTEGER').' NOT NULL,user_agent_id BIGINT NULL,memory_type VARCHAR(40),subject VARCHAR(190),memory_text TEXT,memory_hash VARCHAR(40) UNIQUE,memory_scope_version INTEGER,source_archive_id BIGINT NULL,confidence REAL,occurrence_count INTEGER,first_seen_at DATETIME,last_seen_at DATETIME,is_active INTEGER,metadata_json TEXT)');
ddl('CREATE TABLE agent_chat_archive(id INTEGER PRIMARY KEY,user_agent_id BIGINT NULL)');
$pdo->exec('CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id)');
$pdo->exec('CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type)');
$pdo->exec('CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,occurrence_count)');
vp3_task_lists_schema_v1($pdo);reminder_schema_v1($pdo);
$pdo->exec("UPDATE phone_browser_calls_v1 SET state='completed',ended_epoch=created_epoch+20 WHERE state IN ('prepared','dialing','ringing','answered')");
$pdo->exec("UPDATE phone_voice_calls_v130 SET state='completed' WHERE state IN ('conversation_active','streaming_active')");
$pdo->exec("UPDATE phone_operator_occurrences_v1 SET state='ended'");
$ctx=objective_fixture();preparation_grant($ctx);preparation_canonical($ctx);
$principal=['kind'=>'system','viewer_user_id'=>1];$scope=$ctx['scope']+['principal'=>$principal];
function followthrough_turn(string $query,?array $plan=null,?callable $model=null,bool $save=true,int $owner=1,int $cid=11,int $agent=0,?callable $authority=null):array
{
    $pdo=db();$q=$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at) VALUES(?,?,'user',?,'{}',?)");$q->execute([$cid,$owner,$query,gmdate('Y-m-d H:i:s')]);$mid=(int)$pdo->lastInsertId();
    $p=$agent?['kind'=>'user_agent','owner_user_id'=>$owner,'viewer_user_id'=>$owner,'agent_id'=>$agent]:['kind'=>'system','viewer_user_id'=>$owner];
    $context=['conversation_id'=>$cid,'user_agent_id'=>$agent,'chat_user_message_id'=>$mid,'timezone'=>'America/Phoenix'];
    $model??=static function($prompt)use($plan):array{if($plan===null)throw new LogicException('Unexpected paid interpretation');return ['ok'=>true,'answer'=>json_encode($plan,JSON_THROW_ON_ERROR)];};
    $r=vp3_operator_followthrough_chat_v1($pdo,user($owner),$p,$query,$context,$model,$authority);
    if($save){$payload=!empty($r['handled'])?['transcript_context'=>$r['transcript_context'],'tool_receipt'=>$r['tool_receipt']]:[];
        $pdo->prepare("INSERT INTO chat_messages(conversation_id,role,message,context_json,created_at) VALUES(?,'assistant',?,?,?)")->execute([$cid,$r['handled']?$r['answer']:'Ordinary reply',json_encode($payload,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s')]);$r['saved_message_id']=(int)$pdo->lastInsertId();}
    $r['owner_message_id']=$mid;return $r;
}
function followthrough_kind(array $r):string{return $r['transcript_context']['operator_followthrough_v1']['kind']??'';}
function followthrough_prepare_task(string $title='Ask who will attend'):array
{
    followthrough_turn('Show call follow-ups');return followthrough_turn('Make follow-up 1 a task called '.$title,['operation'=>'prepare','index'=>1,'action'=>'task','title'=>$title]);
}
function followthrough_counts():array{return [(int)db()->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn(),(int)db()->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn()];}

$r=followthrough_turn('Show call follow-ups');$selection=$r['transcript_context']['operator_followthrough_v1']['selections'][0];
c(followthrough_kind($r)==='list'&&$selection['ref']['job']===$ctx['job']['public_id']&&$selection['ref']['item']==='qa-open','conversation lists the actual canonical call finding with a frozen source fingerprint');
c(!empty($r['sources'])&&str_contains($r['sources'][0]['url'],'item=qa-open')&&followthrough_counts()===[0,0]&&!$r['tool_receipt']['changes_applied'],'listing is source-linked and creates no task, reminder or inference');
$r=followthrough_prepare_task();c(followthrough_kind($r)==='proposal'&&followthrough_counts()===[0,0]&&str_contains($r['answer'],'Reply yes'),'task request prepares an exact review without executing');
$r=followthrough_turn('No');c(followthrough_kind($r)==='cancelled'&&followthrough_counts()===[0,0],'declining a proposal writes no destination record');
$r=followthrough_turn('Yes');c(!$r['tool_receipt']['changes_applied']&&followthrough_counts()===[0,0],'a declined proposal cannot be revived by a later yes');
$r=followthrough_prepare_task();$pdo->prepare('UPDATE chat_messages SET message=? WHERE id=?')->execute(['A different visible question',$r['saved_message_id']]);
$r=followthrough_turn('Yes');c(followthrough_kind($r)==='blocked'&&followthrough_counts()===[0,0],'editing visible proposal text invalidates its hidden approval payload');
$r=followthrough_prepare_task();$q=$pdo->prepare('SELECT context_json FROM chat_messages WHERE id=?');$q->execute([$r['saved_message_id']]);$tampered=json_decode($q->fetchColumn(),true);$tampered['transcript_context']['operator_followthrough_v1']['title']='A hidden replacement';$pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([json_encode($tampered),$r['saved_message_id']]);
$r=followthrough_turn('Yes');c(followthrough_kind($r)==='blocked'&&followthrough_counts()===[0,0],'the server proof binds hidden wording and schedule as well as visible text');
$r=followthrough_prepare_task();$pdo->prepare("INSERT INTO chat_messages(conversation_id,role,message,context_json) VALUES(11,'assistant','An unrelated question','{}')")->execute();
$r=followthrough_turn('Yes');c(empty($r['handled'])&&followthrough_counts()===[0,0],'an intervening unrelated assistant question owns the next reply');
$pdo->exec("INSERT INTO chat_conversations(id,user_id,title,created_at,updated_at) VALUES(31,3,'No phone','2026-01-01','2026-01-01')");
$r=followthrough_turn('Yes',null,null,true,3,31);c(empty($r['handled']),'ordinary confirmations do not acquire a Phone requirement');
$r=followthrough_prepare_task();$r=followthrough_turn('Show my tasks');c(empty($r['handled']),'an ordinary task command does not get captured by a pending call proposal');

$q=$pdo->prepare('SELECT analysis_json FROM artist_transcript_master_analysis_v237 WHERE session_id=?');$q->execute([(int)$ctx['job']['session_id']]);$original=$q->fetchColumn();
$r=followthrough_prepare_task();$changed=json_decode($original,true);$changed['modules']['qa']['result']['unanswered'][0]['question']='An edited finding';
$pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=?')->execute([json_encode($changed),(int)$ctx['job']['session_id']]);
$r=followthrough_turn('Yes');c(followthrough_kind($r)==='blocked'&&followthrough_counts()===[0,0],'a source edit after preparation fences approval');
$pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=?')->execute([$original,(int)$ctx['job']['session_id']]);
$r=followthrough_prepare_task();$GLOBALS['followthrough_denied']['agent_brain.access']=true;$r=followthrough_turn('Yes');
c(followthrough_kind($r)==='blocked'&&followthrough_counts()===[0,0],'task permission withdrawal between proposal and approval prevents mutation');unset($GLOBALS['followthrough_denied']['agent_brain.access']);
followthrough_turn('Show call follow-ups');$interpretations=0;
$r=followthrough_turn('Make follow-up 1 a task',null,static function($prompt)use(&$interpretations,$pdo,$ctx,$changed):array{$interpretations++;$pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=?')->execute([json_encode($changed),(int)$ctx['job']['session_id']]);return ['ok'=>true,'answer'=>'{"operation":"prepare","index":1,"action":"task"}'];});
c(followthrough_kind($r)==='blocked'&&$interpretations===1&&followthrough_counts()===[0,0],'source changes during interpretation withhold its proposal without retrying inference');
$pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=?')->execute([$original,(int)$ctx['job']['session_id']]);
followthrough_turn('Show call follow-ups');$r=followthrough_turn('Make follow-up 1 a task',null,static function($prompt)use($pdo):array{$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');return ['ok'=>true,'answer'=>'{"operation":"prepare","index":1,"action":"task"}'];});
c(followthrough_kind($r)==='blocked'&&followthrough_counts()===[0,0],'deactivation during interpretation cannot publish private source context');$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
$r=followthrough_turn('Show call follow-ups',null,null,true,1,12,7);c(!str_contains(json_encode($r),$ctx['job']['public_id']),'another private Agent Chat cannot borrow the call finding');
$r=followthrough_turn('Show call follow-ups',null,null,true,2,21);c(!str_contains(json_encode($r),$ctx['job']['public_id']),'another owner cannot borrow the call finding');

$proposal=followthrough_prepare_task('PRIVATE-OWNER-TODO');
$r=followthrough_turn('Yes',null,null,false);$taskId=(int)($r['tool_receipt']['record_id']??0);
if(empty($r['tool_receipt']['changes_applied'])){
    $debugLast=vp3_operator_followthrough_last_v1($pdo,$scope,$r['owner_message_id']);
    $debug=vp3_operator_followthrough_confirm_v1($pdo,$scope,$debugLast,['id'=>$r['owner_message_id']],'Yes');
    throw new RuntimeException('Unexpected task response: '.json_encode([$r,$debug,followthrough_counts()],JSON_THROW_ON_ERROR));
}
c(($r['tool_receipt']['capability']??'')==='tasks.lists'&&$r['tool_receipt']['changes_applied']&&followthrough_counts()===[1,0]&&$taskId>0,'approval creates one real canonical Brain task through the existing kernel');
$q=$pdo->prepare('SELECT subject,metadata_json FROM agent_memory_items WHERE id=?');$q->execute([$taskId]);$task=$q->fetch(PDO::FETCH_ASSOC);c($task['subject']==='PRIVATE-OWNER-TODO'&&str_contains($task['metadata_json'],$ctx['job']['public_id']),'task retains the approved wording and its exact call evidence reference');
$r=followthrough_turn('Yes');c(!$r['tool_receipt']['changes_applied']&&followthrough_counts()===[1,0],'a lost Chat acknowledgement recovers the original approval without a second task');
$source=vp3_operator_followthrough_source_v1($pdo,$scope,$selection['ref']);
c(($source['item']['review_state']??'')==='unreviewed'&&isset($source['item']['actions']['agent_task']),'saving a task records its action receipt without marking generated findings as reviewed truth');
$out=vp3_operator_followthrough_outcomes_v1($pdo,$scope,time());c(count($out)===1&&$out[0]['status']==='open','follow-through reads the actual saved task state');
vp3_agent_memory_scope_set_current_v410(1,0);$waiting=agent_task_v123_update(user(),$taskId,'waiting');
c($waiting&&vp3_operator_followthrough_outcomes_v1($pdo,$scope,time())[0]['status']==='waiting','canonical task lifecycle changes are immediately reflected as a blocker');
$line=vp3_phone_workspace_number_v1($pdo,$ctx['scope'],1);$prep=vp3_operator_question_preparation_v1($pdo,$ctx['scope'],$line,$ctx['contact'],$ctx['peer'],time());$brief=vp3_operator_question_brief_v1($prep,'Ask who will attend.',false,false);
c($prep['state']==='ready'&&count($prep['followthrough'])===1&&str_contains($brief['objectives'],'task waiting: 1')&&!str_contains(json_encode($prep),'PRIVATE-OWNER-TODO'),'next-call preparation receives verified record states without copying private task wording');
$done=agent_task_v123_update(user(),$taskId,'completed');c($done&&vp3_operator_followthrough_outcomes_v1($pdo,$scope,time())[0]['status']==='completed','completion is read from the canonical task, not inferred from call prose');
$completedPrep=vp3_operator_question_preparation_v1($pdo,$ctx['scope'],$line,$ctx['contact'],$ctx['peer'],time());c($prep['fingerprint']!==$completedPrep['fingerprint'],'a native task-state change alters the same preparation fingerprint fenced after model generation');

// Reminder preparation can ask for missing time, accept a conversational answer and still require approval.
followthrough_turn('Show call follow-ups');
$r=followthrough_turn('Remind me about follow-up 1',['operation'=>'prepare','index'=>1,'action'=>'reminder']);c(followthrough_kind($r)==='list'&&followthrough_counts()===[1,0],'missing reminder time asks a question and creates no timer');
$at=(new DateTimeImmutable('+1 day',new DateTimeZone('America/Phoenix')))->format('Y-m-d\TH:i:s');
$schedule=['timezone'=>'America/Phoenix','start_local'=>$at,'recurrence'=>'none','delivery'=>'chat'];
$r=followthrough_turn('Tomorrow at this time in Phoenix',['operation'=>'prepare','index'=>1,'action'=>'reminder','schedule'=>$schedule]);
c(followthrough_kind($r)==='proposal'&&str_contains($r['answer'],'America/Phoenix')&&followthrough_counts()===[1,0],'conversational time clarification produces an exact timezone/delivery review');
$r=followthrough_turn('Yes');$reminderId=(int)($r['tool_receipt']['record_id']??0);
c(($r['tool_receipt']['capability']??'')==='reminders.manage'&&$r['tool_receipt']['changes_applied']&&followthrough_counts()===[1,1],'approval creates one actual native reminder');
$r=followthrough_turn('Yes');c(!$r['tool_receipt']['changes_applied']&&followthrough_counts()===[1,1],'reminder confirmation replay creates no duplicate timer');
$q=$pdo->prepare('SELECT * FROM agent_reminders_v1 WHERE id=?');$q->execute([$reminderId]);$reminder=$q->fetch(PDO::FETCH_ASSOC);
c($reminder['recurrence']==='none'&&$reminder['delivery']==='chat'&&$reminder['conversation_id']==11,'default call follow-up is one occurrence in the original Chat');
$pdo->prepare("INSERT INTO agent_reminder_occurrences_v1(reminder_id,owner_user_id,scheduled_at,wake_at,status) VALUES(?,1,?,?,'pending')")->execute([$reminderId,$reminder['next_at'],$reminder['next_at']]);
$finished=reminder_execute_v1($pdo,1,'cloud:11','fixture:complete-followup','done',['operation'=>'complete','id'=>$reminderId],[$reminder],11,new DateTimeImmutable('+2 days'));
$out=vp3_operator_followthrough_outcomes_v1($pdo,$scope,time());$byKind=array_column($out,null,'kind');
c($finished['tool_receipt']['changes_applied']&&$byKind['reminder']['status']==='occurrence_done'&&$byKind['agent_task']['status']==='completed','native reminder completion stays distinct from task completion');
$r=followthrough_turn('Show call follow-up status');c(str_contains($r['answer'],'occurrence done')&&str_contains($r['answer'],'does not complete its task'),'Chat reports verified domain outcomes with the completion distinction');

// The pre-existing Transcription task/reminder entry points share these same receipts.
$map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,(int)$ctx['job']['session_id']));$master=artist_listening_v237_analysis_status($pdo,(int)$ctx['job']['session_id'],$map)['master'];transcription_deeper_review_item_v307($pdo,(int)$ctx['job']['session_id'],$master,'qa','qa-open','accepted');
$q=$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(11,1,'user','Remind me about this call finding tomorrow','{}')");$q->execute();$mid=(int)$pdo->lastInsertId();
$r=vp3_transcript_action_chat_v1($pdo,user(),$principal,['operation'=>'item_action','plugin'=>'qa','item_id'=>'qa-open','action'=>'reminder','schedule'=>$schedule],(int)$ctx['job']['session_id'],['conversation_id'=>11,'chat_user_message_id'=>$mid,'operator_owner_query'=>'Remind me about this call finding tomorrow']);
c(!$r['tool_receipt']['changes_applied']&&followthrough_counts()===[1,1],'ordinary Transcription reminder action recognizes the existing canonical call reminder');
$r=vp3_transcript_reviewed_action_v1($pdo,user(),$principal,(int)$ctx['job']['session_id'],['operation'=>'item_action','plugin'=>'qa','item_id'=>'qa-open','action'=>'agent_task'],['conversation_id'=>11]);
c(!empty($r['existing'])&&followthrough_counts()===[1,1],'ordinary Transcription task action recognizes the existing task receipt');

// Force a failure after native task insertion but before its source receipt: every domain write rolls back.
$fault=objective_fixture();preparation_canonical($fault);$r=followthrough_prepare_task('Must roll back');$counts=followthrough_counts();$pdo->failMasterWrite=true;
$r=followthrough_turn('Yes');$pdo->failMasterWrite=false;
c(followthrough_kind($r)==='blocked'&&followthrough_counts()===$counts,'source-receipt failure rolls back the canonical task instead of reporting partial success');
$r=followthrough_turn('Yes');c(followthrough_counts()===$counts&&!$r['tool_receipt']['changes_applied'],'failed handoff has no automatic retry');
echo 'OPERATOR_FOLLOWTHROUGH=PASS ('.($checks-$before).' additional checks)'.PHP_EOL;
