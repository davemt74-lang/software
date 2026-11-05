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
class ActivityProofPDO extends PDO {
 public function prepare(string $sql,array $options=[]):PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  if(str_contains($sql,'ON DUPLICATE KEY UPDATE')){
   $sql=str_replace('ON DUPLICATE KEY UPDATE','ON CONFLICT(user_id) DO UPDATE SET',$sql);
   $sql=preg_replace('/VALUES\\((\\w+)\\)/','excluded.$1',$sql);
  }
  return parent::prepare($sql,$options);
 }
}
$pdo=new ActivityProofPDO('sqlite:'.__DIR__.'/state.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('IF',static fn($condition,$yes,$no)=>$condition?$yes:$no);
 $pdo->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT OR IGNORE INTO users VALUES(1,1);
 CREATE TABLE IF NOT EXISTS agent_activity_state(user_id INTEGER PRIMARY KEY,surface TEXT,context_key TEXT,task_kind TEXT,task_title TEXT,activity_state TEXT,last_input_at TEXT,last_task_at TEXT,last_heartbeat_at TEXT,idle_since_at TEXT,details_json TEXT,updated_at TEXT);
 CREATE TABLE IF NOT EXISTS agent_activity_events(id INTEGER PRIMARY KEY,user_id INTEGER,surface TEXT,context_key TEXT,task_kind TEXT,task_title TEXT,previous_state TEXT,activity_state TEXT,reason TEXT,details_json TEXT,created_at TEXT);
 CREATE TABLE IF NOT EXISTS chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,updated_at TEXT);
 INSERT OR IGNORE INTO chat_conversations(id,user_id,user_agent_id) VALUES(42,1,NULL);
 CREATE TABLE IF NOT EXISTS chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 INSERT OR IGNORE INTO chat_messages(id,conversation_id,user_id,role,message) VALUES(1,42,NULL,'assistant','Restored private conversation')");
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $name):bool{$q=db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return(bool)$q->fetchColumn();}
function current_user():array{return ['id'=>1,'is_active'=>1];}
function has_permission($key,$user):bool{return true;}
function csrf_token():string{return 'activity-fixture';}
function agent_runtime_v125_trace($event,$data):void{}
function agent_runtime_v125_trace_id():string{return 'activity-proof';}
function vp3_live_session_record_activity_v2370(...$args):array{$GLOBALS['activityBridgeCalls']=($GLOBALS['activityBridgeCalls']??0)+1;if(db()->inTransaction())throw new RuntimeException('The activity bridge must not own the caller transaction.');return [];}
function ai_generate_chat_response():never{throw new RuntimeException('Activity and text delivery must not invoke inference.');}
require_once PROOF_ROOT.'/includes/agent-memory-scope-v410.php';
require_once PROOF_ROOT.'/includes/agent-activity-v94.php';
require_once PROOF_ROOT.'/includes/agent-initiative-v1.php';
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
if($path==='/probe'||$path==='/emit'||$path==='/transaction-proof'){
 require __DIR__.'/bootstrap.php';header('Content-Type:application/json');
 $row=db()->query('SELECT details_json FROM agent_activity_state WHERE user_id=1')->fetchColumn();
 $activity=agent_activity_v94_snapshot(current_user(),'chat');
 $present=vp3_initiative_owner_present_v1(db(),current_user(),42);$allowed=vp3_initiative_can_report_v1(db(),current_user(),42);
 $result=['snapshot'=>$activity,'details'=>$row?json_decode($row,true):[],'owner_present'=>$present,'text_allowed'=>$allowed];
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
 if($path==='/emit'){
  $body=json_decode(file_get_contents('php://input'),true);
  if($_SERVER['REQUEST_METHOD']!=='POST'||($body['csrf_token']??'')!==csrf_token()){http_response_code(419);exit;}
  $result['message_id']=$present&&$allowed?vp3_initiative_report_v1(db(),current_user(),42,'Verified useful result delivered quietly.',[],123):0;
 }
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
      const id=messages.at(-1).id+1;const answer='The requested voice reply.';
      messages.push({id,role:'user',message:body.message},{id:id+1,role:'assistant',message:answer});
      return route.fulfill({json:{ok:true,conversation_id:42,user_message_id:id,assistant_message_id:id+1,answer}});
    }
    if(body.action==='activity')return route.fulfill({json:{ok:true,conversation_id:42,latest_message_id:messages.at(-1).id,updates:[],latest_id:0,unread_count:0}});
    return route.fulfill({json:{ok:true,messages:body.action==='load'?messages:[],conversations:[{id:42,title:'Current private Chat'}]}});
  });
  const probe=async page=>{const response=await page.request.get(origin+'/probe');assert.equal(response.status(),200,await response.text());return response.json();};
  const packets=async page=>(await (await page.request.get(origin+'/requests')).json()).filter(item=>item.action==='heartbeat');
  let sharedClock=Date.now();
  const advance=async(page,milliseconds)=>{
    sharedClock=Math.max(sharedClock,await page.evaluate(()=>Date.now()));
    await page.clock.setSystemTime(new Date(sharedClock));await page.clock.fastForward(milliseconds);
    sharedClock=Math.max(sharedClock,await page.evaluate(()=>Date.now()));
  };
  const expectSignal=async(page,busy,focused=true)=>{
    for(let attempt=0;attempt<15;attempt++){
      await advance(page,1001);
      const result=await probe(page);
      if(result.details.chat_busy===busy&&result.details.chat_focused===focused)return result;
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
  assert.equal(state.owner_present,true);assert.equal(state.text_allowed,true);
  console.log('PASS real Chat + Voice + activity HTTP: focused empty Chat permits silent text while broader activity remains working');
  await first.getByRole('button',{name:'Inspect a Chat item'}).click();
  state=await expectSignal(first,false);
  assert.equal(state.snapshot.state,'working');assert.equal(state.snapshot.interruptible,false);assert.equal(state.text_allowed,true);
  console.log('PASS an ordinary empty-composer click no longer starts a blanket 120-second text silence; voice interruption state is unchanged');
  await first.locator('#chatInput').fill('An unfinished thought');
  state=await expectSignal(first,true);assert.equal(state.owner_present,false);assert.equal(state.text_allowed,false);
  await first.locator('#chatInput').fill('');
  state=await expectSignal(first,false);assert.equal(state.text_allowed,true);
  console.log('PASS real typed input blocks text delivery and clearing it restores availability through production activity storage');

  await first.getByRole('button',{name:'Start voice conversation'}).click();
  await first.waitForFunction(()=>window.recognizers.some(item=>item.active));
  let arrive,release;const arrived=new Promise(resolve=>{arrive=resolve;});const wait=new Promise(resolve=>{release=resolve;});
  heldSend={arrive,wait};
  await first.evaluate(()=>{
    const recognizer=window.recognizers.find(item=>item.active);
    const result=[{transcript:'Review my current notes please'}];result.isFinal=true;
    recognizer.onresult({resultIndex:0,results:[result]});
  });
  await advance(first,2000);await arrived;
  state=await expectSignal(first,true);assert.equal(state.text_allowed,false);
  assert.equal(await first.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY.isBusy()),true);
  console.log('PASS actual Voice processing owns the turn and blocks silent report delivery');
  release();
  await first.waitForFunction(()=>document.body.dataset.stonefellowAgentState==='speaking');
  state=await expectSignal(first,true);assert.equal(state.text_allowed,false);
  await first.evaluate(()=>window.utterance.onend());
  state=await expectSignal(first,false);assert.equal(state.text_allowed,true);
  await first.getByRole('button',{name:'Stop voice conversation'}).click();
  console.log('PASS actual Voice speech blocks reports until the speech transport ends; listening alone does not fabricate a busy turn');

  await first.locator('#chatInput').fill('The focused tab is busy');
  const focusedBusy=await expectSignal(first,true);const firstTab=focusedBusy.details.activity_tab;
  await first.request.post(origin+'/activity',{data:idlePacket});
  state=await probe(first);assert.equal(state.details.chat_busy,true);assert.equal(state.text_allowed,false);
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
  assert.equal(state.text_allowed,false);
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
  assert.equal(state.details.activity_tab,firstTab);assert.equal(state.details.chat_busy,true);assert.equal(state.text_allowed,false);
  const secondPackets=await (await second.request.get(origin+'/requests')).json();
  assert.ok(secondPackets.some(item=>item.context?.activity_tab!==firstTab&&item.context?.chat_busy===false&&item.context?.chat_focused===false));
  console.log('PASS controlled exclusive tab focus + real timer/HTTP: an unfocused document cannot clear focused Chat busy state');
  await setFocus(first,false);await waitServerSignal(first,true,false);
  await setFocus(second,true);state=await waitServerSignal(second,false);const secondTab=state.details.activity_tab;
  assert.notEqual(secondTab,firstTab);assert.equal(state.text_allowed,true);
  await second.request.post(origin+'/activity',{data:oldFocusedPacket});state=await probe(second);
  assert.equal(state.details.activity_tab,secondTab);assert.equal(state.details.chat_busy,false);
  console.log('PASS a delayed previously-focused packet from another document cannot take over a newer foreground observation');
  await setFocus(first,false);state=await probe(second);
  assert.equal(state.details.activity_tab,secondTab);assert.equal(state.details.chat_busy,false);
  await first.close();
  for(let attempt=0;attempt<15;attempt++){
    const requests=await (await second.request.get(origin+'/requests')).json();
    if(requests.some(item=>item.reason==='pagehide'&&(typeof item.context==='string'?JSON.parse(item.context):item.context)?.activity_tab===firstTab))break;
    if(attempt===14)assert.fail('The departed tab did not send its real pagehide beacon');
    await new Promise(resolve=>setTimeout(resolve,50));
  }
  state=await probe(second);assert.equal(state.details.activity_tab,secondTab);assert.equal(state.text_allowed,true);
  console.log('PASS a late pagehide beacon from another document cannot overwrite the newly focused Chat');
  await setFocus(second,false);
  state=await waitServerSignal(second,false,false);assert.equal(state.owner_present,false);assert.equal(state.text_allowed,false);
  await setFocus(second,true);await waitServerSignal(second,false);
  console.log('PASS controlled focus loss drives the shipped blur listener and withdraws text availability through actual HTTP storage');
  const transaction=await (await second.request.post(origin+'/transaction-proof',{data:{csrf_token:'activity-fixture'}})).json();
  assert.equal(transaction.outer_preserved,true);assert.equal(transaction.rollback_preserved,true);
  console.log('PASS activity ordering and writes preserve an existing caller transaction and its rollback boundary');
  const response=await second.request.post(origin+'/emit',{data:{csrf_token:'activity-fixture'}});
  const emitted=await response.json();assert.ok(emitted.message_id>1);
  state=await probe(second);assert.equal(state.text_allowed,false);
  assert.equal(await second.evaluate(()=>window.spoken),0);
  assert.deepEqual(pageErrors,[]);
  console.log('PASS production text gates admit one actual saved Chat report without speaking it and retain the existing report cooldown');
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
  console.log('INITIATIVE_ACTIVITY_BROWSER=PASS (15 real browser/HTTP scenarios)');
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
