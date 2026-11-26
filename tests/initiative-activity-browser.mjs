import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import fs from 'node:fs/promises';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

// Real Chat, Voice and activity clients -> real HTTP activity controller ->
// production activity storage, snapshot and Initiative text delivery gates.
// The fixture supplies identity, SQLite's upsert syntax and speech/provider IO.
// Single-tab focus is native. ONLY exclusive multi-tab focus is a lifecycle
// adapter: headless Chromium reports every page focused, unlike an OS window.
const root=path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const temp=await fs.mkdtemp(path.join(os.tmpdir(),'vp3-initiative-activity-'));
const quote=value=>"'"+value.replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
let server,browser,serverLog='';
const pageErrors=[];
try{
  const api=await fs.readFile(path.join(root,'api/agent-activity-v94.php'),'utf8');
  const chatPhp=await fs.readFile(path.join(root,'chat.php'),'utf8');
  const preVoice=chatPhp.match(/\$voiceBootstrap = '([^']+)';/)?.[1];
  assert.ok(preVoice,'use the shipped pre-bootstrap that isolates the dormant legacy voice button');
  const bootstrap="require dirname(__DIR__).'/includes/bootstrap.php';";
  assert.equal(api.split(bootstrap).length,2,'fixture replaces only the production bootstrap boundary');
  await fs.writeFile(path.join(temp,'activity-api.php'),api.replace(bootstrap,"require __DIR__.'/bootstrap.php';"));
  await fs.writeFile(path.join(temp,'bootstrap.php'),`<?php
declare(strict_types=1);
define('PROOF_ROOT',${quote(root)});
// Database sessions and PHP need not load the same host time zone. SQLite
// writes the activity row in UTC while this PHP process uses local time.
date_default_timezone_set('America/Denver');
class ActivityProofPDO extends PDO {
 public function exec(string $sql):int|false {
  // Dialect adaptation only: the canonical durable-job installers define
  // every workflow column, index and foreign-key dependency used below.
  $sql=preg_replace('/\\b(?:BIGINT|INT)\\s+UNSIGNED\\s+(?:NOT NULL\\s+)?AUTO_INCREMENT\\b/i','INTEGER',$sql);
  $sql=preg_replace('/\\s+UNSIGNED\\b|\\s+ON UPDATE CURRENT_TIMESTAMP|\\s+AFTER\\s+[a-z_0-9]+/i','',$sql);
  $sql=preg_replace('/UNIQUE KEY\\s+[a-z_0-9]+\\s*\\(/i','UNIQUE (',$sql);
  $sql=preg_replace('/,\\s*(?:INDEX|KEY)\\s+[a-z_0-9]+\\s*\\([^)]*\\)/i','',$sql);
  $sql=preg_replace('/\\s+ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\\s*$/i','',$sql);
  return parent::exec($sql);
 }
 public function prepare(string $sql,array $options=[]):PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  if(str_contains($sql,'FROM information_schema.statistics'))$sql="SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=? LIMIT 1";
  $sql=preg_replace('/SHOW INDEX FROM \\x60?([a-z_0-9]+)\\x60? WHERE Key_name=\\?/i',"SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='$1' AND name=?",$sql);
  if(str_contains($sql,'INSERT INTO agent_memory_items')&&str_contains($sql,'ON DUPLICATE KEY UPDATE')){
   [$sql]=explode('ON DUPLICATE KEY UPDATE',$sql,2);
   $sql.='ON CONFLICT(user_id,memory_hash) DO UPDATE SET memory_text=excluded.memory_text,confidence=excluded.confidence,last_seen_at=NOW(),is_active=1,metadata_json=excluded.metadata_json,memory_scope_version=410';
  }elseif(str_contains($sql,'ON DUPLICATE KEY UPDATE')){
   $sql=str_replace('ON DUPLICATE KEY UPDATE','ON CONFLICT(user_id) DO UPDATE SET',$sql);
   $sql=preg_replace('/VALUES\\((\\w+)\\)/','excluded.$1',$sql);
  }
  return parent::prepare($sql,$options);
 }
}
$pdo=new ActivityProofPDO('sqlite:'.__DIR__.'/state.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('IF',static fn($condition,$yes,$no)=>$condition?$yes:$no);
 $pdo->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,is_active INTEGER,role TEXT);INSERT OR IGNORE INTO users VALUES(1,1,'admin'),(2,1,'member');
 CREATE TABLE IF NOT EXISTS agent_activity_state(user_id INTEGER PRIMARY KEY,surface TEXT,context_key TEXT,task_kind TEXT,task_title TEXT,activity_state TEXT,last_input_at TEXT,last_task_at TEXT,last_heartbeat_at TEXT,idle_since_at TEXT,details_json TEXT,updated_at TEXT);
 CREATE TABLE IF NOT EXISTS agent_activity_events(id INTEGER PRIMARY KEY,user_id INTEGER,surface TEXT,context_key TEXT,task_kind TEXT,task_title TEXT,previous_state TEXT,activity_state TEXT,reason TEXT,details_json TEXT,created_at TEXT);
 CREATE TABLE IF NOT EXISTS chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,updated_at TEXT);
 INSERT OR IGNORE INTO chat_conversations(id,user_id,user_agent_id) VALUES(42,1,NULL),(43,1,NULL),(99,2,NULL);
 CREATE TABLE IF NOT EXISTS chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 INSERT OR IGNORE INTO chat_messages(id,conversation_id,user_id,role,message) VALUES(1,42,NULL,'assistant','Restored private conversation')");
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $name):bool{$q=db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return(bool)$q->fetchColumn();}
function column_exists(string $table,string $column):bool{return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);}
function current_user():array{return ['id'=>1,'is_active'=>1,'role'=>'admin'];}
function user_has_role(string $role,array $user):bool{return ($user['role']??'')===$role;}
function url(string $path):string{return $path;}
function has_permission($key,$user):bool{return true;}
function setting(string $key,mixed $default=null):mixed{return $default;}
function csrf_token():string{return 'activity-fixture';}
function agent_runtime_v125_trace($event,$data):void{}
function agent_runtime_v125_trace_id():string{return 'activity-proof';}
function vp3_live_session_record_activity_v2370(...$args):array{$GLOBALS['activityBridgeCalls']=($GLOBALS['activityBridgeCalls']??0)+1;if(db()->inTransaction())throw new RuntimeException('The activity bridge must not own the caller transaction.');return [];}
function ai_generate_chat_response():never{throw new RuntimeException('Activity and text delivery must not invoke inference.');}
function agent_brain_schema_ready():bool{return table_exists('agent_memory_items');}
function agent_brain_normalize(string $text):string{return strtolower(trim($text));}
function agent_brain_sentences(string $text):array{return array_values(array_filter(preg_split('/(?<=[.!?])\\s+|\\R+/u',trim($text))?:[],static fn(string $part):bool=>mb_strlen(trim($part))>=4));}
require_once PROOF_ROOT.'/includes/agent-memory-scope-v410.php';
require_once PROOF_ROOT.'/includes/agent-activity-v94.php';
require_once PROOF_ROOT.'/includes/agent-initiative-v1.php';
function activity_proof_policy_v1(bool $enabled=true,int $cid=42):void{
 agent_brain_v122_upsert_system_memory(current_user(),'initiative_policy','owner-opt-in','Activity delivery fixture permission.',
  ['enabled'=>$enabled,'conversation_id'=>$cid,'revision'=>str_repeat('a',32),'knowledge_scope'=>['mode'=>'off','folder_id'=>0],'screen_enabled'=>false]);
}
function activity_proof_prepare_storage_v1():void{
 require_once PROOF_ROOT.'/includes/agent-brain-v122.php';
 require_once PROOF_ROOT.'/includes/agent-worker-cloud-v1910.php';
 require_once PROOF_ROOT.'/includes/agent-capability-chat-v1.php';
 require_once PROOF_ROOT.'/includes/user-agent-system-v236.php';
 require_once PROOF_ROOT.'/includes/personal-capabilities-v242.php';
 require_once PROOF_ROOT.'/includes/knowledge-retrieval-v162.php';
 require_once PROOF_ROOT.'/includes/agent-scheduling-v430.php';
 require_once PROOF_ROOT.'/includes/user-calendar-v1300.php';
 require_once PROOF_ROOT.'/includes/agent-reminders-v1.php';
 $pdo=db();
 if(!table_exists('agent_workflow_runs'))agent_job_engine_ensure_schema_v1900($pdo);
 $pdo->exec('CREATE TABLE IF NOT EXISTS agent_memory_items(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,subject TEXT,memory_text TEXT,
  memory_hash TEXT,memory_scope_version INTEGER,source_archive_id INTEGER,confidence REAL,occurrence_count INTEGER,first_seen_at TEXT,last_seen_at TEXT,is_active INTEGER,metadata_json TEXT,UNIQUE(user_id,memory_hash));
  CREATE TABLE IF NOT EXISTS agent_chat_archive(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,created_at TEXT);
  CREATE INDEX IF NOT EXISTS idx_agent_memory_agent_type_v410 ON agent_memory_items(user_id,user_agent_id,memory_type,is_active,last_seen_at);
  CREATE INDEX IF NOT EXISTS idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_id,user_agent_id,occurrence_count,last_seen_at);
  CREATE INDEX IF NOT EXISTS idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_id,user_agent_id,created_at,id)');
 if(!agent_brain_v122_memory(current_user(),'initiative_policy','owner-opt-in'))activity_proof_policy_v1();
 if(!agent_brain_v122_memory(current_user(),'task','Prepared report fixture')){
  agent_brain_v122_upsert_system_memory(current_user(),'task','Prepared report fixture','Review the saved owner notes.',
   ['task_status'=>'open','due_at'=>gmdate('Y-m-d H:i:s',time()-60)]);
 }
}
function activity_proof_stage_report_v1():void{
 $pdo=db();$user=current_user();$hash=hash('sha256','activity-fixture-report');
 $q=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND dedupe_key=?');
 $q->execute([hash('sha256','initiative.prepare|'.$hash)]);if($q->fetchColumn())return;
 $binding=vp3_initiative_policy_v1($user);$task=agent_brain_v122_memory($user,'task','Prepared report fixture');$ref='task:'.$task['id'];$coverage=[];
 $evidence=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,[$ref],0);
 if(!isset($evidence[$ref]))throw new RuntimeException('The actual owned task source must be readable: '.json_encode($coverage));
 $goal=['binding'=>$binding,'sources'=>[$ref=>$evidence[$ref]['revision']],
  'canonical_sources'=>[$ref=>$evidence[$ref]['canonical_source_revision']??$evidence[$ref]['revision']],
  'tool'=>'work.prepare_private','primary'=>$ref,'focus'=>'Prepare the saved owner notes for a silent report.','report'=>true];
 vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',$goal,$hash);
 $q->execute([hash('sha256','initiative.prepare|'.$hash)]);$run=(int)$q->fetchColumn();
 $claim=agent_job_claim_run_v1900($pdo,$user,$run,'cloud',agent_worker_cloud_worker_id_v1910(1));
 if(!$claim)throw new RuntimeException('The actual preparation claim was not acquired.');
 $done=vp3_initiative_execute_v1($pdo,$user,$claim,static function(string $prompt,string $protocol)use($ref):string{
  $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
  if(db()->inTransaction()||$protocol!=='vp3.initiative-draft.v1'||($input['primary']??null)!==$ref
   ||!in_array($ref,array_column($input['evidence']??[],'ref'),true))throw new LogicException('The fixture provider requires the actual owned source outside a transaction.');
  file_put_contents(__DIR__.'/prepared-model-calls','call'.PHP_EOL,FILE_APPEND|LOCK_EX);
  return json_encode(['draft'=>'Verified useful result delivered quietly.'],JSON_THROW_ON_ERROR);
 },allowDelivery:false);
 if(empty($done['ok']))throw new RuntimeException('The canonical preparation did not finish: '.json_encode($done));
}
function activity_proof_report_v1():array{
 $pdo=db();$q=$pdo->prepare("SELECT r.id AS run_id,r.status AS run_status,r.goal,a.id AS action_id,a.status AS action_status,a.attempt_count,a.result_json,
  p.id AS receipt_id,p.status AS receipt_status,p.attempt_no,p.result_json AS receipt_json
  FROM agent_workflow_runs r JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
  JOIN agent_workflow_receipts p ON p.run_id=r.id AND p.action_id=a.id AND p.owner_user_id=r.owner_user_id
   AND p.attempt_no=a.attempt_count AND p.executor='cloud' AND p.status='completed'
  WHERE r.owner_user_id=1 AND r.dedupe_key=? AND a.sequence_no=1 AND a.status='completed'");
 $q->execute([hash('sha256','initiative.prepare|'.hash('sha256','activity-fixture-report'))]);$proof=$q->fetch();
 $result=['message_id'=>0,'report_count'=>(int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE json_extract(context_json,'$.source')='agent_initiative'")->fetchColumn(),
  'prepared_model_calls'=>file_exists(__DIR__.'/prepared-model-calls')?count(file(__DIR__.'/prepared-model-calls')):0];
 if(!$proof)return $result;
 $stored=json_decode($proof['result_json'],true,32,JSON_THROW_ON_ERROR);$message=(int)($stored['initiative_message_id']??0);
 $result+=['run_id'=>(int)$proof['run_id'],'run_status'=>$proof['run_status'],'action_status'=>$proof['action_status'],'receipt_status'=>$proof['receipt_status'],
  'receipt_id'=>(int)$proof['receipt_id'],'attempt_count'=>(int)$proof['attempt_count'],'attempt_no'=>(int)$proof['attempt_no'],
  'receipt_matches'=>$proof['result_json']===$proof['receipt_json'],'delivery'=>$stored['initiative_delivery'],
  'run_goal'=>json_decode($proof['goal'],true),'run_goal_hash'=>hash('sha256',$proof['goal'])];
 if($message>0){
  $verified=vp3_initiative_followthrough_load_v1($pdo,current_user(),vp3_initiative_principal_v1($pdo,current_user(),0),42,$message);
  $result['message_id']=$message;$result['message_context']=$verified['context'];$result['message']=$verified['message'];
  $result['followthrough_verified']=(int)$verified['action']['receipt_id']===(int)$proof['receipt_id'];
 }
 return $result;
}
activity_proof_prepare_storage_v1();
`);
  await fs.writeFile(path.join(temp,'router.php'),`<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/activity'){
 $raw=json_decode(file_get_contents('php://input'),true);if(!is_array($raw))$raw=$_POST;
 file_put_contents(__DIR__.'/requests.jsonl',json_encode($raw)."\\n",FILE_APPEND|LOCK_EX);
 require __DIR__.'/activity-api.php';exit;
}
if($path==='/reset-state'){
 require __DIR__.'/bootstrap.php';$body=json_decode(file_get_contents('php://input'),true);
 if($_SERVER['REQUEST_METHOD']!=='POST'||($body['csrf_token']??'')!==csrf_token()){http_response_code(419);exit;}
 db()->exec('DELETE FROM agent_activity_state');header('Content-Type:application/json');echo '{"ok":true}';exit;
}
if(in_array($path,['/probe','/emit','/prepare-report','/policy','/chat-store','/transaction-proof'],true)){
 require __DIR__.'/bootstrap.php';header('Content-Type:application/json');
 $body=json_decode(file_get_contents('php://input'),true);
 if($path!=='/probe'&&($_SERVER['REQUEST_METHOD']!=='POST'||($body['csrf_token']??'')!==csrf_token())){http_response_code(419);exit;}
 if($path==='/policy'){
  $mode=$body['mode']??'';if(!in_array($mode,['bound','paused','other','foreign'],true)){http_response_code(422);exit;}
  activity_proof_policy_v1($mode!=='paused',$mode==='other'?43:($mode==='foreign'?99:42));
 }
 if($path==='/chat-store'){
  $q=db()->prepare('INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(42,?,?,?)');
  $q->execute([1,'user',(string)$body['message']]);$userMessage=(int)db()->lastInsertId();
  $q->execute([null,'assistant',(string)$body['answer']]);echo json_encode(['ok'=>true,'user_message_id'=>$userMessage,'assistant_message_id'=>(int)db()->lastInsertId()]);exit;
 }
 if($path==='/prepare-report')activity_proof_stage_report_v1();
 $row=db()->query('SELECT details_json FROM agent_activity_state WHERE user_id=1')->fetchColumn();
 $activity=agent_activity_v94_snapshot(current_user(),'chat');
 $cid=(int)($_GET['conversation_id']??42);$present=vp3_initiative_owner_present_v1(db(),current_user(),$cid);
 $reason=vp3_initiative_report_gate_v1(db(),current_user(),$cid);$allowed=vp3_initiative_can_report_v1(db(),current_user(),$cid);
 $result=['snapshot'=>$activity,'details'=>$row?json_decode($row,true):[],'owner_present'=>$present,'text_allowed'=>$allowed,'text_reason'=>$reason];
 if($path==='/transaction-proof'){
  $body=json_decode(file_get_contents('php://input'),true);
  if($_SERVER['REQUEST_METHOD']!=='POST'||($body['csrf_token']??'')!==csrf_token()){http_response_code(419);exit;}
  $before=$result['details'];$write=$before;$write['chat_busy']=true;
  $write['activity_observed_at']=max((int)floor(microtime(true)*1000),(int)($before['activity_observed_at']??0)+1);
  $write['activity_sequence']=(int)($before['activity_sequence']??0)+1;
  db()->beginTransaction();agent_activity_v94_record(current_user(),'chat','working',$write,'outer_transaction');
  $during=json_decode(db()->query('SELECT details_json FROM agent_activity_state WHERE user_id=1')->fetchColumn(),true);
  $result['outer_preserved']=db()->inTransaction()&&$during['chat_busy']===true&&empty($GLOBALS['activityBridgeCalls']);
  db()->rollBack();$after=json_decode(db()->query('SELECT details_json FROM agent_activity_state WHERE user_id=1')->fetchColumn(),true);
  $result['rollback_preserved']=$after===$before;
 }
 if($path==='/emit')$result['delivered']=$allowed?vp3_initiative_deliver_pending_v1(db(),current_user(),0):false;
 if(in_array($path,['/emit','/prepare-report'],true))$result+=activity_proof_report_v1();
 echo json_encode($result);exit;
}
if($path==='/requests'){header('Content-Type:application/json');$lines=file_exists(__DIR__.'/requests.jsonl')?file(__DIR__.'/requests.jsonl',FILE_IGNORE_NEW_LINES):[];echo json_encode(array_map(static fn($line)=>json_decode($line,true),array_slice($lines,-100)));exit;}
if(in_array($path,['/chat.js','/chat-voice.js','/agent-activity-v94.js'],true)){header('Content-Type:application/javascript');readfile(${quote(root)}.$path);exit;}
header('Content-Type:text/html');
echo '<!doctype html><html lang="en"><meta charset="utf-8"><style>[hidden]{display:none!important}</style><button id="browse">Inspect a Chat item</button><button id="newChatButton">New Chat</button><div id="chatHistory"></div><section id="chatThread" data-view="chat"><div id="chatWelcome" hidden></div></section><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button><button type="button" id="chatVoiceButton">Voice</button></form><div id="chatVoiceStatus"></div><script>window.STONEFELLOW_CHAT={userId:1,agentId:0,initialConversationId:42,initialView:"chat",endpoint:"/chat",csrf:"activity-fixture"};window.STONEFELLOW_ACTIVITY={endpoint:"/activity",csrf:"activity-fixture",surface:"chat",conversationId:42};</script>' . ${quote(preVoice)} . '<script src="/chat.js"></script><script src="/chat-voice.js"></script><script src="/agent-activity-v94.js"></script></html>';
`);
  const socket=net.createServer();await new Promise(resolve=>socket.listen(0,'127.0.0.1',resolve));
  const port=socket.address().port;await new Promise(resolve=>socket.close(resolve));
  const origin='http://127.0.0.1:'+port;
  server=spawn(process.env.VP3_PHP_BINARY||'php',['-S','127.0.0.1:'+port,path.join(temp,'router.php')],{cwd:temp,stdio:['ignore','ignore','pipe']});
  server.stderr.on('data',chunk=>{serverLog+=chunk;});
  for(let attempt=0;attempt<50;attempt++){
    try{await fetch(origin);break;}catch{await new Promise(resolve=>setTimeout(resolve,100));}
  }
  browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  const context=await browser.newContext();
  await context.addInitScript(()=>{
    window.recognizers=[];window.spoken=0;window.utterance=null;
    window.SpeechRecognition=class{
      constructor(){window.recognizers.push(this);}
      start(){this.active=true;queueMicrotask(()=>this.onstart?.());}
      stop(){this.active=false;queueMicrotask(()=>this.onend?.());}
      abort(){this.stop();}
    };
    window.SpeechSynthesisUtterance=class{constructor(text){this.text=text;}};
    Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},speak(utterance){window.spoken++;window.utterance=utterance;queueMicrotask(()=>utterance.onstart?.());}}});
    window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,stop(){}});
  });
  let heldSend=null;
  const messages=[{id:1,role:'assistant',message:'Restored private conversation'}];
  await context.route(origin+'/chat',async route=>{
    const body=route.request().postDataJSON();
    if(body.action==='send'){
      if(heldSend){const hold=heldSend;heldSend=null;hold.arrive();await hold.wait;}
      const answer='The requested voice reply.';
      const saved=await fetch(origin+'/chat-store',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({csrf_token:'activity-fixture',message:body.message,answer})}).then(response=>response.json());
      assert.equal(saved.ok,true);
      messages.push({id:saved.user_message_id,role:'user',message:body.message},{id:saved.assistant_message_id,role:'assistant',message:answer});
      return route.fulfill({json:{ok:true,conversation_id:42,user_message_id:saved.user_message_id,assistant_message_id:saved.assistant_message_id,answer}});
    }
    if(body.action==='activity')return route.fulfill({json:{ok:true,conversation_id:42,latest_message_id:messages.at(-1).id,updates:[],latest_id:0,unread_count:0}});
    return route.fulfill({json:{ok:true,messages:body.action==='load'?messages:body.action==='messages_after'?messages.filter(message=>message.id>body.after_id):[],conversations:[{id:42,title:'Current private Chat'}]}});
  });
  const probe=async page=>{
    const response=await page.request.get(origin+'/probe'), body=await response.text();
    assert.equal(response.status(),200,body);
    assert.ok(body.startsWith('{'),body);
    return JSON.parse(body);
  };
  const fixturePost=async(page,route,data={})=>{
    const response=await page.request.post(origin+route,{data:{csrf_token:'activity-fixture',...data}});
    const body=await response.text();assert.equal(response.status(),200,body);
    assert.ok(body.startsWith('{'),body);return JSON.parse(body);
  };
  const packets=async page=>(await (await page.request.get(origin+'/requests')).json()).filter(item=>item.action==='heartbeat');
  let sharedClock=Date.now();
  const advance=async(page,milliseconds)=>{
    sharedClock=Math.max(sharedClock,await page.evaluate(()=>Date.now()));
    await page.clock.setSystemTime(new Date(sharedClock));await page.clock.fastForward(milliseconds);
    sharedClock=Math.max(sharedClock,await page.evaluate(()=>Date.now()));
  };
  const expectSignal=async(page,busy,focused=true,reason=undefined)=>{
    for(let attempt=0;attempt<15;attempt++){
      await advance(page,1001);
      const result=await probe(page);
      if(result.details.chat_busy===busy&&result.details.chat_focused===focused&&
        (reason===undefined||result.details.chat_busy_reason===reason))return result;
    }
    const actual=await probe(page),client=await page.evaluate(()=>({focused:document.hasFocus(),hidden:document.hidden,
      ready:window.STONEFELLOW_CHAT_CONTINUITY?.isReady?.(),busy:window.STONEFELLOW_CHAT_CONTINUITY?.isBusy?.(),
      input:document.getElementById('chatInput')?.value,state:document.body.dataset.stonefellowAgentState}));
    assert.fail('Actual activity heartbeat did not publish the expected Chat availability: '+JSON.stringify({busy,focused,actual,client}));
  };
  const focusLifecycle=()=>{
    let focused=false;
    Object.defineProperty(document,'hasFocus',{configurable:true,value:()=>focused});
    window.activityFixtureFocus=value=>{focused=Boolean(value);window.dispatchEvent(new Event(focused?'focus':'blur'));};
  };
  const setFocus=async(page,focused)=>{
    // Timer acceleration is not a different physical clock for every tab.
    // Restore their shared wall clock before a controlled hardware focus edge.
    await page.clock.setSystemTime(new Date(Date.now()));
    await page.evaluate(value=>window.activityFixtureFocus(value),focused);
  };
  const waitServerSignal=async(page,busy,focused=true)=>{
    for(let attempt=0;attempt<40;attempt++){
      const result=await probe(page);
      if(result.details.chat_busy===busy&&result.details.chat_focused===focused)return result;
      await new Promise(resolve=>setTimeout(resolve,25));
    }
    assert.fail('The actual focus-edge heartbeat did not reach the activity store: '+JSON.stringify(await probe(page)));
  };
  const open=async({controlledFocus=false,clockSkew=0}={})=>{
    const page=await context.newPage();page.setDefaultTimeout(8000);page.on('pageerror',error=>pageErrors.push(error.message));
    if(controlledFocus)await page.addInitScript(focusLifecycle);
    await page.clock.install({time:new Date(Date.now()+clockSkew)});await page.goto(origin+'/?voice_debug=1');await page.bringToFront();
    await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY?.isReady?.());
    await page.waitForFunction(()=>document.documentElement.dataset.agentActivity);
    return page;
  };
  const first=await open();
  let state=await expectSignal(first,false);
  const idlePacket=(await packets(first)).filter(item=>item.context?.chat_busy===false&&item.context?.chat_focused===true).at(-1);
  assert.equal(state.snapshot.state,'working');assert.equal(state.snapshot.interruptible,false);
  assert.ok(state.snapshot.seconds_since_activity<10,'a canonical heartbeat has a small age when Database and PHP use different time zones');
  assert.equal(state.owner_present,true);assert.equal(state.text_allowed,true);assert.equal(state.text_reason,'ready');
  console.log('PASS real Chat + Voice + activity HTTP: focused empty Chat permits silent text while broader activity remains working');
  state=await fixturePost(first,'/policy',{mode:'paused'});
  assert.equal(state.text_allowed,false);assert.equal(state.text_reason,'paused');assert.equal(state.owner_present,true);
  const paused=await fixturePost(first,'/emit');
  assert.equal(paused.delivered,false);assert.equal(paused.report_count,0);assert.equal(paused.prepared_model_calls,0);
  state=await fixturePost(first,'/policy',{mode:'other'});
  assert.equal(state.text_allowed,false);assert.equal(state.text_reason,'linked_elsewhere');
  const other=await (await first.request.get(origin+'/probe?conversation_id=43')).json();
  assert.equal(other.text_allowed,true);assert.equal(other.text_reason,'ready');
  const wrongChat=await fixturePost(first,'/emit');
  assert.equal(wrongChat.delivered,false);assert.equal(wrongChat.report_count,0);assert.equal(wrongChat.prepared_model_calls,0);
  await fixturePost(first,'/policy',{mode:'foreign'});
  const foreign=await (await first.request.get(origin+'/probe?conversation_id=99')).json();
  assert.equal(foreign.text_allowed,false);assert.equal(foreign.text_reason,'unavailable');
  state=await fixturePost(first,'/policy',{mode:'bound'});
  assert.equal(state.text_allowed,true);assert.equal(state.text_reason,'ready');
  assert.equal(await first.evaluate(()=>window.spoken),0);
  console.log('PASS actual saved policy pause, owned-Chat rebinding and foreign-Chat rejection remain authoritative without inference or speech');
  await first.getByRole('button',{name:'Inspect a Chat item'}).click();
  state=await expectSignal(first,false);
  assert.equal(state.snapshot.state,'working');assert.equal(state.snapshot.interruptible,false);assert.equal(state.text_allowed,true);
  console.log('PASS an ordinary empty-composer click no longer starts a blanket 120-second text silence; voice interruption state is unchanged');
  await first.locator('#chatInput').pressSequentially('An unfinished thought');
  state=await expectSignal(first,true);assert.equal(state.owner_present,false);assert.equal(state.text_allowed,true);
  assert.equal(state.details.chat_busy_reason,'typed_draft');
  const delayedDraft=(await packets(first)).filter(item=>item.context?.chat_busy_reason==='typed_draft').at(-1);
  assert.ok(delayedDraft,'the real browser sent a diagnostic code without copying its draft');
  await first.locator('#chatInput').fill('');
  state=await expectSignal(first,false);assert.equal(state.text_allowed,true);
  assert.equal(state.details.chat_busy_reason,undefined);
  await first.request.post(origin+'/activity',{data:delayedDraft});
  state=await probe(first);assert.equal(state.details.chat_busy,false);assert.equal(state.text_allowed,true);
  console.log('PASS real typed input blocks owner availability while saved text permission stays bound; clearing and delayed packets retain actual ordering');

  await first.locator('#chatInput').fill('An old text draft');
  await first.getByRole('button',{name:'Start voice conversation'}).click();
  await first.waitForFunction(()=>window.recognizers.some(item=>item.active));
  // Some in-app draft surfaces clear the composer through an input event.
  // The draft was never an accepted voice turn and must not hold Chat busy.
  await first.evaluate(()=>{
    const input=document.getElementById('chatInput');
    input.value='';input.dispatchEvent(new Event('input',{bubbles:true}));
  });
  state=await expectSignal(first,false);assert.equal(state.text_allowed,true);
  assert.equal(await first.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY.isBusy()),false);
  console.log('PASS an in-app composer clear drops an unused Voice draft and permits text delivery while Voice is listening');
  await first.evaluate(()=>{
    const recognizer=window.recognizers.find(item=>item.active);
    const result=[{transcript:'A phrase the browser never finalized'}];result.isFinal=false;
    recognizer.onresult({resultIndex:0,results:[result]});
  });
  assert.equal(await first.locator('#chatInput').inputValue(),'A phrase the browser never finalized');
  state=await expectSignal(first,true);
  assert.equal(state.details.chat_busy_reason,'voice_preview');
  assert.equal(await first.evaluate(()=>JSON.parse(sessionStorage.getItem('vp3:chat:drafts:1:0')||'{}')[42]?.text),undefined);
  await first.evaluate(()=>window.recognizers.find(item=>item.active).stop());
  assert.equal(await first.locator('#chatInput').inputValue(),'','an abandoned preview cannot remain an unsent Chat draft');
  state=await expectSignal(first,false);
  assert.equal(state.text_allowed,true);
  await first.waitForFunction(()=>window.recognizers.some(item=>item.active));
  await first.locator('#chatInput').pressSequentially('A typed draft to preserve');
  await first.evaluate(()=>{
    const recognizer=window.recognizers.find(item=>item.active);
    const result=[{transcript:'unconfirmed addition'}];result.isFinal=false;
    recognizer.onresult({resultIndex:0,results:[result]});
    recognizer.onerror({error:'no-speech'});
    recognizer.stop();
  });
  assert.equal(await first.locator('#chatInput').inputValue(),'A typed draft to preserve');
  state=await expectSignal(first,true,true,'typed_draft');
  assert.equal(state.details.chat_busy_reason,'typed_draft');
  await first.locator('#chatInput').fill('');
  await first.waitForFunction(()=>window.recognizers.some(item=>item.active));
  console.log('PASS abandoned Voice text stays out of draft recovery and clears without changing the owner’s typed draft');
  let arrive,release;const arrived=new Promise(resolve=>{arrive=resolve;});const wait=new Promise(resolve=>{release=resolve;});
  heldSend={arrive,wait};
  await first.evaluate(()=>{
    const recognizer=window.recognizers.find(item=>item.active);
    const result=[{transcript:'Review my current notes please'}];result.isFinal=true;
    recognizer.onresult({resultIndex:0,results:[result]});
    const interim=[{transcript:'Unconfirmed trailing words'}];interim.isFinal=false;
    recognizer.onresult({resultIndex:1,results:[result,interim]});
    recognizer.stop();
  });
  assert.equal(await first.locator('#chatInput').inputValue(),'Review my current notes please');
  await advance(first,2000);await arrived;
  state=await expectSignal(first,true);assert.equal(state.text_allowed,true);
  assert.equal(await first.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY.isBusy()),true);
  console.log('PASS actual Voice processing owns the turn while the saved Chat remains eligible for silent text');
  release();
  await first.waitForFunction(()=>document.body.dataset.stonefellowAgentState==='speaking');
  state=await expectSignal(first,true);assert.equal(state.text_allowed,true);
  assert.equal(state.details.chat_busy_reason,'voice_playback');
  await first.evaluate(()=>window.utterance.onend());
  state=await expectSignal(first,false);assert.equal(state.text_allowed,true);
  await first.getByRole('button',{name:'Stop voice conversation'}).click();
  console.log('PASS actual Voice speech marks playback busy until transport ends while saved text permission stays independent');

  await first.locator('#chatInput').fill('The focused tab is busy');
  const focusedBusy=await expectSignal(first,true);const firstTab=focusedBusy.details.activity_tab;
  await first.request.post(origin+'/activity',{data:idlePacket});
  state=await probe(first);assert.equal(state.details.chat_busy,true);assert.equal(state.text_allowed,true);
  console.log('PASS a delayed same-document idle packet cannot overwrite a newer typing-busy observation');
  for(const offset of [-300000,300000]){
    const invalid=structuredClone(idlePacket);invalid.context.activity_observed_at=Date.now()+offset;
    invalid.context.activity_sequence=focusedBusy.details.activity_sequence+10000;
    await first.request.post(origin+'/activity',{data:invalid});
    state=await probe(first);assert.equal(state.details.chat_busy,true);assert.equal(state.details.activity_sequence,focusedBusy.details.activity_sequence);
  }
  console.log('PASS unreasonable future/past observations cannot claim foreground, advance the sequence or clear current busy state');
  await first.request.post(origin+'/activity',{data:{action:'heartbeat',csrf_token:'activity-fixture',surface:'chat',state:'paused',
    reason:'legacy_cached_client',context:{conversation_id:42,visible:true,path:'/chat.php'}}});
  state=await probe(first);assert.equal(state.details.activity_tab,firstTab);assert.equal(state.details.chat_busy,true);
  assert.equal(state.text_allowed,true);
  console.log('PASS an actual legacy-format heartbeat without new fields cannot clear a fresh focused tab’s busy state');
  await first.evaluate(focusLifecycle);await setFocus(first,true);await waitServerSignal(first,true);
  let oldFocusedPacket;
  for(let attempt=0;attempt<40;attempt++){
    oldFocusedPacket=(await packets(first)).filter(item=>item.reason==='focus'&&item.context?.activity_tab===firstTab
      &&item.context?.focused===true&&item.context.activity_sequence>focusedBusy.details.activity_sequence
      &&item.context.activity_sequence<focusedBusy.details.activity_sequence+1000).at(-1);
    const stored=await probe(first);
    if(oldFocusedPacket&&stored.details.activity_sequence>=oldFocusedPacket.context.activity_sequence)break;
    if(attempt===39)assert.fail('The current real-clock focused observation was not accepted before the tab switch');
    await new Promise(resolve=>setTimeout(resolve,25));
  }
  const second=await open({controlledFocus:true});
  await advance(second,31000);
  state=await probe(first);
  assert.equal(state.details.activity_tab,firstTab);assert.equal(state.details.chat_busy,true);assert.equal(state.text_allowed,true);
  const secondPackets=await (await second.request.get(origin+'/requests')).json();
  assert.ok(secondPackets.some(item=>item.context?.activity_tab!==firstTab&&item.context?.chat_busy===false&&item.context?.chat_focused===false));
  console.log('PASS controlled exclusive tab focus + real timer/HTTP: an unfocused document cannot clear focused Chat busy state');
  await setFocus(first,false);await waitServerSignal(first,true,false);
  // Observations from separate documents use a strict millisecond ordering.
  // Make this simulated hardware edge strictly later than the committed blur.
  await new Promise(resolve=>setTimeout(resolve,10));
  await setFocus(second,true);state=await waitServerSignal(second,false);const secondTab=state.details.activity_tab;
  assert.notEqual(secondTab,firstTab);assert.equal(state.text_allowed,true);
  await second.request.post(origin+'/activity',{data:oldFocusedPacket});state=await probe(second);
  assert.equal(state.details.activity_tab,secondTab);assert.equal(state.details.chat_busy,false);
  console.log('PASS a delayed previously-focused packet from another document cannot take over a newer foreground observation');
  await setFocus(first,false);state=await probe(second);
  assert.equal(state.details.activity_tab,secondTab);assert.equal(state.details.chat_busy,false);
  // CDP target-close is not a document navigation and need not run pagehide.
  // Depart through Chromium's native lifecycle. DevTools can omit request
  // events for detached-document beacons, so use the real HTTP journal as the
  // receipt barrier, then check the controller's committed foreground state.
  await first.goto('about:blank');
  let departedPacket;
  for(let attempt=0;attempt<15;attempt++){
    const requests=await (await second.request.get(origin+'/requests')).json();
    departedPacket=requests.find(item=>item.reason==='pagehide'
      &&(typeof item.context==='string'?JSON.parse(item.context):item.context)?.activity_tab===firstTab);
    if(departedPacket)break;
    if(attempt===14)assert.fail('The departed document did not send its real bound pagehide beacon');
    await new Promise(resolve=>setTimeout(resolve,50));
  }
  assert.equal(departedPacket.action,'heartbeat');assert.equal(typeof departedPacket.context,'string');
  const departedContext=JSON.parse(departedPacket.context);
  assert.equal(departedContext.conversation_id,42);assert.equal(departedContext.visible,false);
  assert.equal(departedContext.focused,false);assert.equal(departedContext.chat_busy,true);
  await first.close();
  state=await probe(second);assert.equal(state.details.activity_tab,secondTab);assert.equal(state.text_allowed,true);
  console.log('PASS a late pagehide beacon from another document cannot overwrite the newly focused Chat');
  await setFocus(second,false);
  state=await waitServerSignal(second,false,false);assert.equal(state.owner_present,false);assert.equal(state.text_allowed,true);
  await setFocus(second,true);await waitServerSignal(second,false);
  console.log('PASS controlled focus loss withdraws owner presence through actual HTTP storage while saved text permission remains');
  const transaction=await (await second.request.post(origin+'/transaction-proof',{data:{csrf_token:'activity-fixture'}})).json();
  assert.equal(transaction.outer_preserved,true);assert.equal(transaction.rollback_preserved,true);
  console.log('PASS activity ordering and writes preserve an existing caller transaction and its rollback boundary');
  await setFocus(second,false);state=await waitServerSignal(second,false,false);
  assert.equal(state.owner_present,false);assert.equal(state.text_allowed,true);
  const prepared=await fixturePost(second,'/prepare-report');
  assert.equal(prepared.delivery,'pending');assert.equal(prepared.message_id,0);assert.equal(prepared.report_count,0);
  assert.equal(prepared.run_status,'completed');assert.equal(prepared.action_status,'completed');assert.equal(prepared.receipt_status,'completed');
  assert.equal(prepared.receipt_matches,true);assert.equal(prepared.attempt_count,prepared.attempt_no);
  assert.equal(prepared.prepared_model_calls,1);
  const response=await second.request.post(origin+'/emit',{data:{csrf_token:'activity-fixture'}});
  const emitBody=await response.text();let emitted;
  try{emitted=JSON.parse(emitBody);}catch{assert.fail('The canonical report fixture returned invalid JSON: '+emitBody);}
  assert.ok(response.ok(),'The canonical report fixture failed: '+emitBody);assert.ok(emitted.message_id>1);
  assert.ok(emitted.run_id>0);assert.equal(emitted.message_context.initiative_run_id,emitted.run_id);
  assert.equal(emitted.message_context.initiative_work.owner_user_id,1);assert.equal(emitted.message_context.initiative_work.agent_id,0);
  assert.equal(emitted.message_context.initiative_work.conversation_id,42);assert.equal(emitted.message_context.initiative_work.run_id,emitted.run_id);
  assert.equal(emitted.run_goal.binding.conversation_id,42);assert.equal(emitted.report_count,1);
  assert.equal(emitted.owner_present,false);assert.equal(emitted.delivered,true);assert.equal(emitted.delivery,'delivered');
  assert.equal(emitted.receipt_id,prepared.receipt_id);assert.equal(emitted.receipt_matches,true);assert.equal(emitted.followthrough_verified,true);
  assert.equal(emitted.prepared_model_calls,prepared.prepared_model_calls);
  assert.equal(emitted.message_context.initiative_followthrough_state.goal_hash,emitted.run_goal_hash);
  assert.match(emitted.message_context.initiative_work.followthrough.reply_ref,/^[a-f0-9]{64}$/);
  state=await probe(second);assert.equal(state.text_allowed,true);
  const repeated=await fixturePost(second,'/emit');
  assert.equal(repeated.delivered,false);assert.equal(repeated.message_id,emitted.message_id);assert.equal(repeated.report_count,1);
  assert.equal(repeated.receipt_id,prepared.receipt_id);assert.equal(repeated.prepared_model_calls,prepared.prepared_model_calls);
  messages.push({...emitted.message,id:Number(emitted.message.id)});
  await second.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.syncConversation(42));
  const savedReport=second.locator('[data-chat-message-id="'+emitted.message_id+'"]');
  await savedReport.waitFor();assert.match(await savedReport.innerText(),/Verified useful result delivered quietly\./);
  assert.equal(await savedReport.count(),1);
  assert.equal(await second.evaluate(()=>window.spoken),0);
  assert.deepEqual(pageErrors,[]);
  console.log('PASS production text gates deliver one receipt-backed saved report without speech, model replay or duplicate publication');
  await second.close();await context.request.post(origin+'/reset-state',{data:{csrf_token:'activity-fixture'}});
  const skewed=await open({clockSkew:300000});
  for(let attempt=0;attempt<15;attempt++){
    await skewed.clock.fastForward(1001);state=await probe(skewed);
    if(state.details.chat_focused===true&&state.details.chat_busy===false)break;
    if(attempt===14)assert.fail('A clock-skewed client did not recover from the authenticated server clock anchor');
  }
  const skewPackets=(await packets(skewed)).filter(item=>item.context?.activity_tab===state.details.activity_tab);
  assert.ok(skewPackets.some(item=>item.context.activity_observed_at>Date.now()+240000));
  assert.ok(skewPackets.some(item=>Math.abs(item.context.activity_observed_at-Date.now())<90000));
  assert.equal(state.owner_present,true);
  console.log('PASS a client clock five minutes ahead recovers through the same-account/session server clock anchor without weakening timestamp rejection');
  await skewed.close();await context.request.post(origin+'/reset-state',{data:{csrf_token:'activity-fixture'}});
  const reloading=await open();
  await reloading.locator('#chatInput').pressSequentially('Keep only this typed draft');
  await reloading.getByRole('button',{name:'Start voice conversation'}).click();
  await reloading.waitForFunction(()=>window.recognizers.some(item=>item.active));
  await reloading.evaluate(()=>{
    const recognizer=window.recognizers.find(item=>item.active);
    const interim=[{transcript:'Unconfirmed at pagehide'}];interim.isFinal=false;
    recognizer.onresult({resultIndex:0,results:[interim]});
  });
  assert.match(await reloading.locator('#chatInput').inputValue(),/Unconfirmed at pagehide/);
  await reloading.reload();
  await reloading.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY?.isReady?.());
  assert.equal(await reloading.locator('#chatInput').inputValue(),'Keep only this typed draft');
  state=await expectSignal(reloading,true);
  assert.equal(state.details.chat_busy_reason,'restored_draft');
  console.log('PASS native pagehide and reload preserve the typed draft without saving its unconfirmed Voice preview');
  console.log('INITIATIVE_ACTIVITY_BROWSER=PASS (19 real browser/HTTP scenarios)');
}catch(error){
  if(serverLog.includes('Fatal error'))console.error(serverLog.slice(-6000));
  for(const page of browser?.contexts().flatMap(item=>item.pages())||[]){
    try{console.error('Activity fixture client:',await page.evaluate(()=>({focused:document.hasFocus(),hidden:document.hidden,
      state:document.body?.dataset.stonefellowAgentState,input:document.getElementById('chatInput')?.value,
      busy:window.STONEFELLOW_CHAT_CONTINUITY?.isBusy?.(),voice:window.VP3ConversationControls?.state?.(),
      spoken:window.spoken,error:window.STONEFELLOW_CHAT_VOICE?.lastError,
      debug:document.getElementById('stonefellowVoiceDebug')?.textContent.slice(-3000)})));}catch{}
  }
  throw error;
}
finally{await browser?.close();server?.kill('SIGTERM');await fs.rm(temp,{recursive:true,force:true});}
