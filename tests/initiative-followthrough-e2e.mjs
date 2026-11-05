import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

// The actual modern Chat controller also handles load/list/messages_after.
// Only account/database/provider infrastructure is adapted by the shared API
// fixture. Source proposals and continuations use the real leased worker.
const source=fileURLToPath(new URL('../',import.meta.url));
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-followthrough-e2e-'));
fs.cpSync(path.join(source,'includes'),path.join(temp,'includes'),{recursive:true});
fs.mkdirSync(path.join(temp,'api'));
for(const name of ['chat.php','chat-v236.php','chat-stream-v121.php','agent-top-of-mind-v1.php'])fs.copyFileSync(path.join(source,'api',name),path.join(temp,'api',name));
for(const name of ['agent-runtime-routing-v420.php','ai-settings.php'])fs.writeFileSync(path.join(temp,'includes',name),'<?php // Fixture infrastructure.');
for(const name of ['phone-agent-schema-v1.php','workflow-agent-schema-v1.php','record-agent-schema-v1.php','media-agent-schema-v1.php','app-agent-remote-v1.php','system-app-agent-schema-v1.php','app-operation-schema-v1.php']){
  fs.copyFileSync(path.join(source,'tests/fixtures',name),path.join(temp,'includes',name));
}
const companion=fs.readFileSync(path.join(source,'tests/fixtures/initiative-followthrough-chat.php'),'utf8')
  .replace('function ai_generate_chat_response(', 'function followthrough_fixture_ai_generate_chat_response(');
fs.writeFileSync(path.join(temp,'includes/initiative-followthrough-chat-fixture.php'),companion);
let bootstrap=fs.readFileSync(path.join(source,'tests/fixtures/capability-chat-bootstrap.php'),'utf8');
for(const name of ['chat_remote_answer','ai_generate_chat_response','calendar_schedule_awareness_snapshot_v1330','agent_runtime_v125_idempotency_claim','agent_runtime_v125_idempotency_complete'])bootstrap=bootstrap.replace('function '+name+'(', 'function capability_fixture_'+name+'(');
bootstrap=bootstrap.replace('declare(strict_types=1);','declare(strict_types=1); session_start();');
const runtimeSource=fs.readFileSync(path.join(source,'includes/agent-runtime-v125.php'),'utf8');
const primitiveStart=runtimeSource.indexOf('function agent_runtime_v125_idempotency_path('),primitiveEnd=runtimeSource.indexOf('function agent_background_v125_jobs_dir(',primitiveStart);
assert.ok(primitiveStart>=0&&primitiveEnd>primitiveStart);
bootstrap+="\nfunction agent_runtime_v125_root(): string {return dirname(__DIR__).'/runtime-fixture';}\nfunction agent_runtime_v125_dir(string $bucket): string { $dir=agent_runtime_v125_root().'/'.preg_replace('/[^a-z0-9_-]/i','',$bucket);if(!is_dir($dir))mkdir($dir,0700,true);return $dir;}\n"+runtimeSource.slice(primitiveStart,primitiveEnd);
bootstrap+="\nrequire_once __DIR__.'/initiative-followthrough-chat-fixture.php';\n";
bootstrap+=`\nfunction ai_generate_chat_response($prompt,...$args): array {
 $options=is_array($args[4]??null)?$args[4]:[];$input=is_string($prompt)?json_decode($prompt,true):null;
 if(isset($_SERVER['HTTP_X_FIXTURE_FOLLOWTHROUGH_HOLD'])&&($options['protocol']??'')==='vp3.initiative-draft.v1'&&($input['response_kind']??'')==='followthrough_reply'){
  if(db()->inTransaction())throw new RuntimeException('The actual main model held a database transaction.');
  $gate=getenv('VP3_FOLLOWTHROUGH_GATE');file_put_contents($gate.'.entered',json_encode(['session_active'=>session_status()===PHP_SESSION_ACTIVE]));
  $deadline=microtime(true)+10;while(!is_file($gate.'.release')){if(microtime(true)>$deadline)throw new RuntimeException('The controlled provider gate timed out.');usleep(10000);}
 }
 return followthrough_fixture_ai_generate_chat_response($prompt,...$args);
}\n`;
fs.writeFileSync(path.join(temp,'includes/bootstrap.php'),bootstrap);
fs.writeFileSync(path.join(temp,'includes/ai-stream-v121.php'),`<?php
function ai_v121_stream_chat_response($query,$history,$context,$user,$onDelta){$answer=chat_remote_answer($query,$history,$context,$user);$onDelta($answer);return ['ok'=>true,'answer'=>$answer,'usage'=>[]];}`);
fs.writeFileSync(path.join(temp,'followthrough-control.php'),"<?php require __DIR__.'/includes/bootstrap.php';followthrough_fixture_control_v1();");
const preVoice=fs.readFileSync(path.join(source,'chat.php'),'utf8').match(/\$voiceBootstrap = '([^']+)';/)[1];
const router=path.join(temp,'router.php');
fs.writeFileSync(router,[
  '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);',
  'if($path==="/health"){echo "ready";return;}',
  'if(str_starts_with($path,"/api/")||$path==="/followthrough-control.php")return false;',
  '$source='+JSON.stringify(source)+';',
  'if(in_array($path,["/chat.js","/chat-voice.js","/chat.css"],true)){header("Content-Type: ".(str_ends_with($path,".css")?"text/css":"text/javascript"));readfile($source.ltrim($path,"/"));return;}',
  'require __DIR__."/includes/bootstrap.php";',
  '$cfg=["userId"=>1,"agentId"=>7,"agentName"=>"Longfellow","initialConversationId"=>11,"initialView"=>"chat","endpoint"=>"/api/chat-v236.php?agent=7","csrf"=>csrf_token(),"workingAttentionBinding"=>vp3_catch_up_page_binding_v1(current_user())];',
  'echo \'<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/chat.css"><style>body{margin:0}.chat-thread{min-height:0}.chat-topbar{padding:14px 22px;border-bottom:1px solid #e5e7eb}#chatHistory{padding:12px}</style><body class="chat-page"><div class="chat-app"><aside class="chat-sidebar"><h2>VP3</h2><button id="newChatButton">New Chat</button><div id="chatHistory"></div></aside><main class="chat-main"><header class="chat-topbar">Longfellow · Agent Chat</header><section id="chatThread" class="chat-thread" data-view="chat"><div id="chatWelcome" hidden></div></section><div id="chatComposerShell" class="chat-composer-shell"><form id="chatForm" class="chat-composer"><textarea id="chatInput" placeholder="Message Longfellow…"></textarea><button id="sendChatButton">↑</button><button type="button" id="chatVoiceButton">Voice</button></form><div id="chatVoiceStatus"></div></div></main></div>\';',
  'echo "<script>window.STONEFELLOW_CHAT=".json_encode($cfg).";</script>";',
  'echo '+JSON.stringify(preVoice)+';',
  'echo \'<script src="/chat.js"></script><script src="/chat-voice.js"></script></body></html>\';'
].join('\n'));
const reserve=net.createServer();await new Promise(resolve=>reserve.listen(0,'127.0.0.1',resolve));
const port=reserve.address().port;await new Promise(resolve=>reserve.close(resolve));
const base='http://127.0.0.1:'+port,headers={'X-Fixture-Brain-Ready':'1'};
let logs='',browser;
const providerGate=path.join(temp,'provider-gate');
const server=spawn(process.env.VP3_PHP_BINARY||'php',['-S','127.0.0.1:'+port,'-t',temp,router],{detached:true,
  env:{...process.env,PHP_CLI_SERVER_WORKERS:'2',VP3_FOLLOWTHROUGH_GATE:providerGate},stdio:['ignore','pipe','pipe']});
server.stdout.on('data',data=>{logs+=data;});server.stderr.on('data',data=>{logs+=data;});server.on('error',error=>{logs+=error.message;});
const control=async(action='state',query={})=>{
  const response=await fetch(base+'/followthrough-control.php?'+new URLSearchParams({action,...query}),{headers});
  const text=await response.text();let data;try{data=JSON.parse(text);}catch{throw new Error(text);}
  assert.equal(response.status,200,text);assert.equal(data.ok,true,text);return data;
};
const contextOf=message=>JSON.parse(message.context_json||'{}');
const rootPost=state=>state.messages.find(message=>contextOf(message).source==='agent_initiative');
const ready=page=>page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady()&&!window.STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring());
const idle=page=>page.waitForFunction(()=>!window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy());
const choose=(page,id)=>page.locator('[data-initiative-reply="'+id+'"]').click();
const send=async(page,text)=>{await page.locator('#chatInput').fill(text);await page.locator('#chatInput').press('Enter');await idle(page);};
try{
  for(let attempt=0;;attempt++){
    try{if((await fetch(base+'/health')).ok)break;}catch{}
    if(attempt>80)throw new Error('PHP fixture did not start.');await new Promise(resolve=>setTimeout(resolve,50));
  }
  browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  for(const mobile of [false,true]){
    const seed=await control('seed'),proposal=rootPost(seed),marker=contextOf(proposal).initiative_work.followthrough;
    assert.equal(marker.state,'open');
    const context=await browser.newContext({viewport:{width:mobile?390:1280,height:850},extraHTTPHeaders:{...headers,'X-Fixture-Followthrough-Intent':mobile?'discuss':'advance',...(!mobile?{'X-Fixture-Followthrough-Hold':'1'}:{})}});
    await context.addInitScript(()=>{
      window.speechCalls=0;window.recognizers=[];
      window.SpeechRecognition=class{constructor(){window.recognizers.push(this);}start(){this.active=true;queueMicrotask(()=>this.onstart?.());}stop(){this.active=false;queueMicrotask(()=>this.onend?.());}abort(){this.stop();}};
      window.SpeechSynthesisUtterance=class{constructor(text){this.text=text;}};
      Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},speak(u){window.speechCalls++;queueMicrotask(()=>{u.onstart?.();u.onend?.();});}}});
      window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,stop(){},createStream(){}});
      window.StonefellowKnowledgeScopeV162={value:()=>({mode:'off',folder_id:0})};
    });
    const page=await context.newPage(),errors=[],sends=[];page.setDefaultTimeout(12000);
    await page.clock.install();
    page.on('pageerror',error=>errors.push(error.message));
    page.on('request',request=>{if(request.method()==='POST'&&request.url().includes('/api/chat')){const body=request.postDataJSON();if(body?.action==='send')sends.push({url:request.url(),body});}});
    if(!mobile){
      let loseAcknowledgement=true;
      await page.route('**/api/chat-v236.php*',async route=>{
        const body=route.request().method()==='POST'?route.request().postDataJSON():null;
        if(loseAcknowledgement&&body?.action==='send'){
          loseAcknowledgement=false;await route.fetch();return route.abort('internetdisconnected');
        }
        return route.continue();
      });
    }
    try{
      await page.goto(base,{waitUntil:'domcontentloaded'});await ready(page);await choose(page,proposal.id);
      await page.locator('#chatInput').fill(mobile?'Help me think through that proposal.':'Please prepare the next private step.');
      if(process.env.VP3_FOLLOWTHROUGH_SCREENSHOTS){fs.mkdirSync(process.env.VP3_FOLLOWTHROUGH_SCREENSHOTS,{recursive:true});await page.screenshot({path:path.join(process.env.VP3_FOLLOWTHROUGH_SCREENSHOTS,mobile?'reply-mobile.png':'reply-desktop.png'),fullPage:true});}
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
      await page.locator('#chatInput').press('Enter');
      if(!mobile){
        for(let attempt=0;!fs.existsSync(providerGate+'.entered');attempt++){
          if(attempt>100)throw new Error('The actual associated main model did not reach its provider gate.');
          await new Promise(resolve=>setTimeout(resolve,20));
        }
        assert.equal(JSON.parse(fs.readFileSync(providerGate+'.entered','utf8')).session_active,false);
        const binding=await page.evaluate(()=>window.STONEFELLOW_CHAT.workingAttentionBinding),beforeFeed=await control();
        const started=Date.now();
        const feed=await page.request.get(base+'/api/agent-top-of-mind-v1.php?agent_id=7&conversation_id=11',{headers:{'X-VP3-Working-Attention':binding}});
        assert.equal(feed.status(),200,await feed.text());assert.ok(Date.now()-started<5000);
        const feedState=(await feed.json()).state;
        assert.equal(feedState.owner_user_id,1);assert.equal(feedState.agent_id,7);assert.equal(feedState.conversation_id,11);
        assert.equal(feedState.working_attention_binding,binding);assert.equal(feedState.state,'ready');assert.ok(feedState.items.some(item=>item.state==='queued'));
        assert.equal((await control()).calls.length,beforeFeed.calls.length,'Top of Mind must not infer while the main response is held');
        assert.equal(fs.existsSync(providerGate+'.release'),false);
        fs.writeFileSync(providerGate+'.release','1');
        console.log('PASS actual Top of Mind GET remains current/read-only during the same-session held main reply, with no transaction/session lock or added inference');
      }
      await idle(page);
      if(!mobile){
        await page.getByText('Could not connect to Chat. Please try again.',{exact:true}).waitFor();
        const committed=await control(),childCount=committed.runs.length,callCount=committed.calls.length;
        await page.clock.fastForward(3500);
        await page.locator('[data-initiative-followthrough-state="'+proposal.id+'"]').getByText('Private work queued',{exact:true}).waitFor();
        await page.getByText('Retrying your earlier reply',{exact:true}).waitFor();
        await page.locator('#sendChatButton').click();await idle(page);
        await page.waitForFunction(()=>!document.getElementById('chatInitiativeReplyCue'));
        assert.deepEqual(sends[1].body.initiative_reply,sends[0].body.initiative_reply);
        assert.equal((await control()).runs.length,childCount);assert.equal((await control()).calls.length,callCount);
        console.log('PASS actual committed-but-lost ACK remains exactly retryable after activity advances the root revision; server replay creates no duplicate work or inference');
      }
      assert.deepEqual(sends[0].body.initiative_reply,{message_id:Number(proposal.id),reply_ref:marker.reply_ref,revision:marker.revision});
      let state=await control(),reply=state.messages.filter(row=>contextOf(row).initiative_followthrough).at(-1);
      assert.ok(reply,'the actual Chat controller must save followthrough outcome metadata');
      let outcome=contextOf(reply).initiative_followthrough;
      assert.equal(outcome.message_id,proposal.id);assert.equal(outcome.agent_id,7);assert.equal(outcome.conversation_id,11);
      assert.equal(await page.locator('#chatInitiativeReplyCue').count(),0);assert.equal(await page.evaluate(()=>window.speechCalls),0);
      assert.equal(state.task.metadata_json,seed.task.metadata_json);assert.equal(state.reminders,seed.reminders);
      assert.equal(state.calls.some(call=>call.kind==='ordinary_agent_chat'),false);
      assert.equal(JSON.stringify(state.calls).includes('SECRET'),false);
      if(!mobile){
        assert.equal(outcome.state,'queued');const childId=outcome.continuation_run_id;assert.ok(childId>0);
        await page.locator('[data-initiative-followthrough-state="'+proposal.id+'"]').getByText('Private work queued',{exact:true}).waitFor();
        await context.setExtraHTTPHeaders({...headers,'X-Fixture-Followthrough-Intent':'discuss'});
        await choose(page,proposal.id);await send(page,'How is that private work going?');
        state=await control();reply=state.messages.filter(row=>contextOf(row).initiative_followthrough).at(-1);outcome=contextOf(reply).initiative_followthrough;
        assert.equal(outcome.state,'queued');assert.equal(outcome.continuation_run_id,childId);
        const queuedRevision=outcome.revision;
        await page.route('**/api/chat-v236.php*',async route=>{
          const body=route.request().method()==='POST'?route.request().postDataJSON():null;
          if(body?.action==='load'){
            const response=await route.fetch(),data=await response.json();
            // Controlled history pagination omits the acknowledgement. The
            // canonical root, activity reader and actual receipt stay intact.
            data.messages=data.messages.filter(row=>!contextOf(row).initiative_followthrough);
            return route.fulfill({response,json:data});
          }
          return route.fallback();
        });
        await page.reload({waitUntil:'domcontentloaded'});await ready(page);
        await page.locator('[data-initiative-followthrough-state="'+proposal.id+'"]').getByText('Private work queued',{exact:true}).waitFor();
        const finished=await control('execute',{id:String(childId)});assert.equal(finished.execution_result.ok,true);
        const action=finished.actions.find(row=>Number(row.run_id)===childId),receipt=finished.receipts.find(row=>Number(row.run_id)===childId);
        assert.equal(action.status,'completed');assert.equal(receipt.status,'completed');assert.equal(action.result_json,receipt.result_json);
        const result=JSON.parse(action.result_json),delivered=finished.messages.find(row=>Number(row.id)===Number(result.initiative_message_id));
        assert.ok(delivered,JSON.stringify(result));
        assert.equal(result.initiative_delivery,'delivered');assert.equal(contextOf(delivered).initiative_followthrough.revision,queuedRevision);
        await page.clock.fastForward(3500);
        await page.locator('[data-initiative-followthrough-state="'+proposal.id+'"]').getByText('Private work completed',{exact:true}).waitFor();
        assert.equal(await page.locator('[data-initiative-reply="'+proposal.id+'"]').count(),0);
        await page.locator('[data-chat-message-id="'+delivered.id+'"]').waitFor();
        assert.equal(await page.locator('[data-chat-message-id="'+delivered.id+'"]').count(),1);
        await page.reload({waitUntil:'domcontentloaded'});await ready(page);
        await page.locator('[data-initiative-followthrough-state="'+proposal.id+'"]').getByText('Private work completed',{exact:true}).waitFor();
        console.log('PASS real desktop Chromium → actual Chat handler → exact queued proposal → queued discussion preserves actual child → real worker/action/receipt → same-revision completion updates original post live and after reload');
        const retrySeed=await control('seed'),retryPost=rootPost(retrySeed);
        await context.setExtraHTTPHeaders({...headers,'X-Fixture-Followthrough-Intent':'advance'});
        await page.reload({waitUntil:'domcontentloaded'});await ready(page);await choose(page,retryPost.id);await send(page,'Prepare the next private step.');
        const queued=await control(),queuedReply=queued.messages.filter(row=>contextOf(row).initiative_followthrough).at(-1);
        const failedChild=contextOf(queuedReply).initiative_followthrough.continuation_run_id;
        const failed=await control('execute',{id:String(failedChild),fail:'1'});
        assert.equal(failed.actions.find(row=>Number(row.run_id)===failedChild).status,'failed');
        assert.equal(failed.messages.length,queued.messages.length,'terminal failure has no new assistant message');
        const failedMarker=contextOf(rootPost(failed)).initiative_work.followthrough;assert.equal(failedMarker.state,'stale');
        await page.clock.fastForward(3500);
        await page.locator('[data-initiative-followthrough-state="'+retryPost.id+'"]').getByText('Private work interrupted. Reply to review or retry.',{exact:true}).waitFor();
        assert.equal((await control()).runs.length,failed.runs.length,'activity must not retry a failed private job');
        await choose(page,retryPost.id);await send(page,'Please retry that private preparation.');
        const retried=await control(),retriedReply=retried.messages.filter(row=>contextOf(row).initiative_followthrough).at(-1);
        const retriedOutcome=contextOf(retriedReply).initiative_followthrough;
        assert.equal(sends.at(-1).body.initiative_reply.revision,failedMarker.revision);
        assert.equal(retriedOutcome.state,'queued');assert.notEqual(retriedOutcome.continuation_run_id,failedChild);
        assert.equal(retried.runs.length,failed.runs.length+1);assert.equal(retried.task.metadata_json,retrySeed.task.metadata_json);
        console.log('PASS actual terminal receipt updates an existing Chat post through activity without reload/new message; only an exact explicit owner reply queues one fresh private retry');
      }else{
        assert.equal(outcome.state,'discussed');
        await context.setExtraHTTPHeaders({...headers,'X-Fixture-Followthrough-Intent':'none'});
        await choose(page,proposal.id);const voiceRevision=outcome.revision;
        await page.evaluate(()=>window.VP3ConversationControls.listen());await page.waitForFunction(()=>window.recognizers.some(r=>r.active));
        await page.evaluate(()=>{const r=window.recognizers.find(r=>r.active);r.onresult({resultIndex:0,results:[Object.assign([{transcript:'Help me think through this plan'}],{isFinal:true})]});});
        await page.clock.fastForward(2200);
        await page.getByText('Which next step would you like to discuss for the verified launch task?',{exact:true}).waitFor();await idle(page);
        assert.equal(sends.at(-1).body.input_mode,'voice');assert.equal(sends.at(-1).body.initiative_reply.message_id,proposal.id);
        assert.equal(sends.at(-1).body.initiative_reply.revision,voiceRevision);
        assert.ok(sends.at(-1).url.includes('chat-stream-v121.php'));
        state=await control();assert.equal(state.reminders,seed.reminders);assert.equal(state.task.metadata_json,seed.task.metadata_json);
        assert.equal(state.runs.length,seed.runs.length);
        await page.evaluate(()=>window.VP3ConversationControls.stop());
        console.log('PASS real390px mobile canonical proposal/reply controls + actual recognition/form/stream endpoint preserve the exact association and ask a natural clarification without domain writes or private jobs');
      }
      assert.deepEqual(errors,[]);
    }finally{await context.close();}
  }
  for(const phase of ['intent','ack','lost']){
    const seed=await control('seed'),proposal=rootPost(seed),requestHeaders={...headers,'X-Fixture-Followthrough-Intent':'advance',
      ...(phase==='intent'?{'X-Fixture-Followthrough-Intent-Fail':'1'}:phase==='ack'?{'X-Fixture-Followthrough-Model-Fail':'1'}:{})};
    const context=await browser.newContext({extraHTTPHeaders:requestHeaders}),page=await context.newPage(),sends=[],errors=[];
    page.setDefaultTimeout(12000);await page.clock.install();
    page.on('pageerror',error=>errors.push(error.message));
    page.on('request',request=>{if(request.method()==='POST'&&request.url().includes('/api/chat')){const body=request.postDataJSON();if(body?.action==='send')sends.push(body);}});
    if(phase==='lost'){
      let loseAcknowledgement=true;
      await page.route('**/api/chat-v236.php*',async route=>{
        const body=route.request().method()==='POST'?route.request().postDataJSON():null;
        if(loseAcknowledgement&&body?.action==='send'){loseAcknowledgement=false;await route.fetch();return route.abort('internetdisconnected');}
        return route.continue();
      });
    }
    try{
      await page.goto(base,{waitUntil:'domcontentloaded'});await ready(page);
      assert.equal(await page.locator('#chatInitiativeReplyCue').count(),0);
      await send(page,'yes');await page.locator('#chatRecoveryStatus').waitFor();
      assert.equal((await page.locator('#chatRecoveryStatus').textContent()).includes('initiative_reply_'),false);
      const failed=await control(),originalTurn=failed.messages.find(row=>row.role==='user'&&row.message==='yes');
      assert.ok(originalTurn);assert.equal(Object.hasOwn(sends[0],'initiative_reply'),false);
      await context.setExtraHTTPHeaders({...headers,'X-Fixture-Followthrough-Intent':'advance'});
      await page.clock.fastForward(3500);
      assert.equal(await page.locator('#chatInitiativeReplyCue').count(),0);
      await page.locator('#sendChatButton').click();await idle(page);
      await page.waitForFunction(()=>document.getElementById('chatInput').value==='');
      const recovered=await control(),acks=recovered.messages.filter(row=>contextOf(row).initiative_followthrough?.message_id===proposal.id);
      const children=recovered.runs.filter(row=>JSON.parse(row.goal||'{}').followthrough?.message_id===proposal.id);
      assert.equal(recovered.messages.filter(row=>row.role==='user'&&row.message==='yes').length,1);
      assert.equal(recovered.messages.find(row=>row.role==='user'&&row.message==='yes').id,originalTurn.id);
      assert.equal(acks.length,1);assert.equal(children.length,1);
      assert.equal(contextOf(acks[0]).initiative_followthrough.state,'queued');
      if(phase==='lost'){
        assert.equal(recovered.messages.length,failed.messages.length);assert.equal(recovered.calls.length,failed.calls.length);
        assert.equal(recovered.runs.length,failed.runs.length);
      }
      await send(page,'yes');await page.waitForFunction(()=>document.getElementById('chatInput').value==='');
      const replayed=await control();
      assert.equal(replayed.messages.length,recovered.messages.length);assert.equal(replayed.runs.length,recovered.runs.length);
      assert.equal(replayed.calls.length,recovered.calls.length);assert.equal(replayed.task.metadata_json,seed.task.metadata_json);
      assert.equal(sends.every(body=>!Object.hasOwn(body,'initiative_reply')),true);assert.deepEqual(errors,[]);
      console.log('PASS real ordinary composer bare yes '+phase+' recovery uses the immutable server anchor and original owner turn, then replays without another job, message or model call');
    }finally{await context.close();}
  }
  {
    await control('seed');const context=await browser.newContext({extraHTTPHeaders:{...headers,'X-Fixture-Followthrough-Intent':'advance'}});
    const page=await context.newPage();page.setDefaultTimeout(12000);
    let arrived,release,ackId=0;const held=new Promise(resolve=>{arrived=resolve;}),released=new Promise(resolve=>{release=resolve;});
    await page.route('**/api/chat-v236.php*',async route=>{
      const body=route.request().method()==='POST'?route.request().postDataJSON():null;
      if(body?.action==='send'){
        assert.equal(Object.hasOwn(body,'initiative_reply'),false);
        const response=await route.fetch(),data=await response.json();ackId=Number(data.assistant_message_id);
        arrived();await released;return route.fulfill({response,json:data});
      }
      return route.continue();
    });
    try{
      await page.goto(base,{waitUntil:'domcontentloaded'});await ready(page);
      await page.locator('#chatInput').fill('yes');await page.locator('#chatInput').press('Enter');await held;
      assert.ok(ackId>0);
      await page.evaluate(()=>window.STONEFELLOW_CHAT={...window.STONEFELLOW_CHAT,userId:2,workingAttentionBinding:'replaced-session'});
      release();await idle(page);
      assert.equal(await page.locator('[data-chat-message-id="'+ackId+'"]').count(),0);
      assert.equal(await page.getByText('The verified launch task still needs a product image and reviewed promotion plan. Let us work through your next step.',{exact:true}).count(),0);
      console.log('PASS actual implicitly associated main ACK held across account/session replacement is never painted into the new UI identity');
    }finally{release();await context.close();}
  }
  {
    const seed=await control('seed'),proposal=rootPost(seed),marker=contextOf(proposal).initiative_work.followthrough;
    const context=await browser.newContext({extraHTTPHeaders:{...headers,'X-Fixture-Followthrough-Intent':'advance'}});
    const page=await context.newPage(),query='Prepare this private continuation once, then show its verified outcome.';
    page.setDefaultTimeout(12000);await page.clock.install();let loseAcknowledgement=true;
    await page.route('**/api/chat-v236.php*',async route=>{
      const body=route.request().method()==='POST'?route.request().postDataJSON():null;
      if(loseAcknowledgement&&body?.action==='send'){loseAcknowledgement=false;await route.fetch();return route.abort('internetdisconnected');}
      return route.continue();
    });
    try{
      await page.goto(base,{waitUntil:'domcontentloaded'});await ready(page);await choose(page,proposal.id);await send(page,query);
      await page.getByText('Could not connect to Chat. Please try again.',{exact:true}).waitFor();
      const queued=await control(),ack=queued.messages.filter(row=>contextOf(row).initiative_followthrough).at(-1);
      const ackOutcome=contextOf(ack).initiative_followthrough,userId=ackOutcome.reply_message_id,childId=ackOutcome.continuation_run_id;
      await page.clock.fastForward(3500);
      await page.locator('[data-chat-message-id="'+ack.id+'"]').waitFor();
      const finished=await control('execute',{id:String(childId)});assert.equal(finished.execution_result.ok,true);
      await page.clock.fastForward(3500);
      await page.locator('[data-initiative-followthrough-state="'+proposal.id+'"]').getByText('Private work completed',{exact:true}).waitFor();
      const completed=await control(),callsBefore=completed.calls.length,messageCount=completed.messages.length,runCount=completed.runs.length;
      await page.locator('#sendChatButton').click();await idle(page);
      await page.waitForFunction(()=>!document.getElementById('chatInitiativeReplyCue'));
      const replayed=await control();assert.equal(replayed.calls.length,callsBefore);assert.equal(replayed.messages.length,messageCount);assert.equal(replayed.runs.length,runCount);
      assert.equal(await page.locator('[data-chat-message-id="'+userId+'"]').count(),1);
      assert.equal(await page.locator('[data-chat-message-id="'+ack.id+'"]').count(),1);
      assert.equal(await page.locator('.message-text').filter({hasText:query}).count(),1,'the exact local failed/retry optimistic chain leaves no orphan duplicate text');
      await page.locator('[data-initiative-followthrough-state="'+proposal.id+'"]').getByText('Private work completed',{exact:true}).waitFor();
      assert.equal(marker.revision,1);assert.equal(await page.locator('[data-initiative-reply="'+proposal.id+'"]').count(),0);
      console.log('PASS queued ACK lost before polling → actual child receipt completes → old exact retry reuses the original user/ACK DOM IDs, retires only its local optimistic attempts and keeps completed status with zero new work');
    }finally{await context.close();}
  }
}catch(error){console.error(logs.slice(-12000));throw error;}
finally{
  if(browser)await browser.close();
  fs.writeFileSync(providerGate+'.release','1');
  if(server.exitCode===null&&server.signalCode===null){try{process.kill(-server.pid,'SIGTERM');}catch{}await new Promise(resolve=>server.once('exit',resolve));}
  fs.rmSync(temp,{recursive:true,force:true});
}
