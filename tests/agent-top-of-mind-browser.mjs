import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {copyFileSync,mkdirSync,mkdtempSync,readFileSync,rmSync,writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

// Real API, read projection, source readers, queue/claim/heartbeat and browser
// modules. Identity/provider/calendar inputs are the existing SQLite fixture.
const source=fileURLToPath(new URL('../',import.meta.url)),temp=mkdtempSync(join(tmpdir(),'vp3-top-of-mind-'));
const probe=net.createServer();await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));const port=probe.address().port;await new Promise(resolve=>probe.close(resolve));
const base='http://127.0.0.1:'+port;
mkdirSync(join(temp,'api'));mkdirSync(join(temp,'includes'));
copyFileSync(join(source,'api/agent-top-of-mind-v1.php'),join(temp,'api/agent-top-of-mind-v1.php'));
writeFileSync(join(temp,'includes/agent-top-of-mind-v1.php'),'<?php require_once '+JSON.stringify(join(source,'includes/agent-top-of-mind-v1.php'))+';');
writeFileSync(join(temp,'includes/bootstrap.php'),[
  '<?php session_start(); require '+JSON.stringify(join(source,'tests/fixtures/initiative-chat-loop-http.php'))+';',
  'if(isset($_SERVER["HTTP_X_FIXTURE_PARTICIPANT"]))$GLOBALS["VP3_PARTICIPANT_RESOURCE_SCOPE_V1"]=["owner"=>1,"agent"=>7,"participant"=>2,"revision"=>1];'
].join('\n'));
const assets=['agent-top-of-mind-v1.js','agent-top-of-mind-v1.css','agent-orb.js','agent-orb-adapter.js','agent-eyes-core.js','chat-notifications-drawer-v240.js','chat-notifications-drawer-v240.css','agent-orb.css'];
writeFileSync(join(temp,'router.php'),[
  '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);$source='+JSON.stringify(source)+';',
  'if($path==="/health"){echo "ready";return;}',
  '$assets='+JSON.stringify(assets)+';',
  'if(in_array(ltrim($path,"/"),$assets,true)){header("Content-Type: ".(str_ends_with($path,".css")?"text/css":"text/javascript"));readfile($source.ltrim($path,"/"));return;}',
  'if($path==="/api/agent-top-of-mind-v1.php"){require __DIR__.$path;return;}',
  'require __DIR__."/includes/bootstrap.php";',
  'if($path==="/session"){header("Content-Type:application/json");echo json_encode(["binding"=>vp3_catch_up_page_binding_v1(current_user())]);return;}',
  'if($path==="/counts"){header("Content-Type:application/json");echo json_encode(["models"=>(int)db()->query("SELECT COUNT(*) FROM fixture_model_calls")->fetchColumn(),"runs"=>(int)db()->query("SELECT COUNT(*) FROM agent_workflow_runs")->fetchColumn()]);return;}',
  'if($path==="/seed"||$path==="/expire"||$path==="/source-change"){',
  'if($_SERVER["REQUEST_METHOD"]!=="POST"||($_SERVER["HTTP_X_FIXTURE_CSRF"]??"")!==csrf_token()){http_response_code(419);exit;}',
  '$u=current_user();$a=vp3_agent_chat_resolve_agent_v380(db(),$u,7);$p=vp3_agent_chat_principal_v380($u,$a);',
  'if($path==="/source-change"){db()->exec("UPDATE agent_memory_items SET subject=\'Changed launch task\',memory_text=\'Changed launch facts\' WHERE id=100");echo "{}";return;}',
  'if($path==="/expire"){db()->exec("UPDATE agent_workflow_runs SET lease_expires_at=\'2000-01-01 00:00:00\' WHERE status=\'executing\'");echo "{}";return;}',
  'vp3_initiative_chat_v1(db(),$u,$p,["conversation_id"=>11],"on",null);$policy=vp3_initiative_policy_v1($u,7);$coverage=[];',
  '$e=vp3_initiative_evidence_v1(db(),$u,new DateTimeImmutable("now",new DateTimeZone("UTC")),false,[],$coverage,["task:100"],7);$row=$e["task:100"];',
  '$goal=["binding"=>$policy,"sources"=>["task:100"=>$row["revision"]],"canonical_sources"=>["task:100"=>$row["canonical_source_revision"]??$row["revision"]],"tool"=>"work.prepare_private","primary"=>"task:100","focus"=>"Prepare the saved launch details","report"=>true];',
  'vp3_initiative_enqueue_v1(db(),$u,"initiative.prepare",$goal,hash("sha256","top-of-mind-http"));$r=db()->query("SELECT id FROM agent_workflow_runs ORDER BY id DESC LIMIT 1")->fetchColumn();$claim=agent_job_claim_run_v1900(db(),$u,(int)$r,"cloud",agent_worker_cloud_worker_id_v1910(1),180);',
  'agent_job_heartbeat_v1900(db(),$u,(int)$r,(int)$claim["action"]["id"],$claim["lease_token"],42,"Reading verified launch sources",240);',
  'require_once $source."/includes/agent-connection-youtube-playback-v1.php";',
  'db()->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT)");',
  'agent_connection_youtube_results_schema_v1(db());agent_connection_youtube_consent_schema_v1(db());vp3_youtube_playback_install_v1(db());',
  'agent_connection_youtube_set_consent_v1(db(),1,true);vp3_youtube_history_change_v1(db(),1,"enable_history");',
  'db()->exec("INSERT INTO chat_messages(conversation_id,role,message) VALUES(11,\'assistant\',\'YouTube search\')");$message=(int)db()->lastInsertId();',
  'agent_connection_youtube_results_save_v1(db(),1,11,$message,["expires_at"=>time()+3600,"videos"=>[["video_id"=>"song0000001","title"=>"Confirmed fixture playback","channel_title"=>"Fixture channel","made_for_kids"=>false]]]);',
  'if(!vp3_youtube_history_record_v1(db(),1,$message,"song0000001","10000000-0000-4000-8000-000000000001",vp3_youtube_capture_key_v1(db(),1)))throw new RuntimeException("Fixture playback was rejected.");',
  'db()->exec("INSERT INTO chat_messages(conversation_id,role,message) VALUES(11,\'assistant\',\'YouTube artist results\')");$artistMessage=(int)db()->lastInsertId();',
  'agent_connection_youtube_results_save_v1(db(),1,11,$artistMessage,["query"=>"Artists similar to Fixture Artist","taste_topic"=>"Fixture Artist","suggestion_kind"=>"similar","expires_at"=>time()+3600,"videos"=>[["video_id"=>"song0000002","title"=>"Provider artist title"]]]);',
  'db()->exec("CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title TEXT,status TEXT,all_day INTEGER,start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,source TEXT,source_reference TEXT,description TEXT,location TEXT)");',
  'db()->prepare("INSERT INTO user_calendar_events VALUES(4,1,\'Fixture planning session\',\'active\',1,?,?,\'UTC\',\'user\',\'\',\'\',\'https://example.com/meeting\')")->execute([gmdate("Y-m-d 00:00:00"),gmdate("Y-m-d 00:00:00",time()+86400)]);',
  'header("Content-Type:application/json");echo json_encode(["run_id"=>(int)$r]);return;}',
  'if($path==="/api/chat-notifications-brain-v240.php"){header("Content-Type:application/json");echo json_encode(["ok"=>true,"complete"=>true,"agent_namespace"=>"agent:7","notifications"=>["items"=>[],"unread"=>0],"brain"=>["enabled"=>false],"activity_stream"=>["complete"=>true,"agent_namespace"=>"agent:7","items"=>[]]]);return;}',
  'if($path!=="/"&&$path!=="/outside"){http_response_code(404);return;}',
  '$binding=vp3_catch_up_page_binding_v1(current_user());$outside=$path==="/outside";',
  'echo \'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/agent-top-of-mind-v1.css"><link rel="stylesheet" href="/chat-notifications-drawer-v240.css"><link rel="stylesheet" href="/agent-orb.css"><body><main>Owner workspace</main>\';',
  'if(!$outside)echo \'<div class="chat-topbar-actions"><div id="chatNotificationMenu"><button id="chatNotificationButton">Activity Center</button></div><div id="chatProfileMenu">Account</div></div>\';',
  '$orb=["ownerKey"=>"1","agentId"=>7,"chatUrl"=>"/chat.php","workingAttentionBinding"=>$binding,"topOfMindEndpoint"=>"/api/agent-top-of-mind-v1.php","notificationsEndpoint"=>"/api/chat-notifications-brain-v240.php","csrf"=>"fixture","voiceScript"=>"/deliberately-unavailable-voice.js","drawerScript"=>"/chat-notifications-drawer-v240.js"];',
  'echo "<script>window.VP3_ORB_CONFIG=".json_encode($orb).";</script>";',
  'if(!$outside)echo "<script>window.STONEFELLOW_CHAT={userId:1,agentId:7,initialConversationId:11,workingAttentionBinding:".json_encode($binding)."};window.fixturePicks=0;window.fixtureResearch=[];window.VP3ParticipantContinuity={selectedContext:()=>window.fixtureParticipant};window.STONEFELLOW_CHAT_CONTINUITY_V87={conversationId:()=>11,requestYoutubePicks:()=>{window.fixturePicks++;return true},requestArtistResearch:(artist,delegate)=>{if(window.fixtureDraft)return false;window.fixtureResearch.push([artist,delegate]);return true}};window.STONEFELLOW_NOTIFICATION_DRAWER=".json_encode(["endpoint"=>"/api/chat-notifications-brain-v240.php","csrf"=>"fixture","userId"=>1,"agentId"=>7,"workingAttentionBinding"=>$binding,"topOfMindEndpoint"=>"/api/agent-top-of-mind-v1.php"]).";</script><script src=/chat-notifications-drawer-v240.js></script>";',
  'echo \'<script src="/agent-eyes-core.js"></script><script src="/agent-top-of-mind-v1.js"></script><script src="/agent-orb-adapter.js"></script><script src="/agent-orb.js"></script>\';'
].join('\n'));
assert.match(execFileSync(process.env.VP3_PHP_BINARY||'php',['-l',join(temp,'router.php')],{encoding:'utf8'}),/No syntax errors detected/);
let logs='';const server=spawn(process.env.VP3_PHP_BINARY||'php',['-d','session.save_path='+temp,'-S','127.0.0.1:'+port,'-t',temp,join(temp,'router.php')],{env:{...process.env,VP3_IMAGE_TEST_DSN:'sqlite:'+join(temp,'state.sqlite')},stdio:['ignore','pipe','pipe']});
server.stdout.on('data',x=>{logs+=x;});server.stderr.on('data',x=>{logs+=x;});
let browser;
try{
  for(let i=0;;i++){try{const ready=await fetch(base+'/health');if(ready.ok&&(await ready.text())==='ready')break;}catch{}if(i>50)throw Error('PHP did not start');await new Promise(r=>setTimeout(r,50));}
  const session=await fetch(base+'/session');assert.ok(session.headers.get('set-cookie'),'session bootstrap: '+session.status+' '+await session.clone().text());
  const cookie=session.headers.get('set-cookie').split(';')[0],binding=(await session.json()).binding;
  const headers={'Cookie':cookie,'X-VP3-Working-Attention':binding};
  const seed=await fetch(base+'/seed',{method:'POST',headers:{...headers,'X-Fixture-CSRF':'fixture'}});assert.equal(seed.status,200,await seed.clone().text());const runId=(await seed.json()).run_id;
  const endpoint=base+'/api/agent-top-of-mind-v1.php?agent_id=7&conversation_id=11';
  const before=await fetch(base+'/counts').then(r=>r.json());
  for(let i=0;i<3;i++){
    const r=await fetch(endpoint,{headers});assert.equal(r.status,200,await r.clone().text());assert.match(r.headers.get('cache-control'),/no-store/);
    const data=await r.json();assert.equal(data.state.owner_user_id,1);assert.equal(data.state.agent_id,7);assert.equal(data.state.conversation_id,11);assert.equal(data.state.working_attention_binding,binding);
    assert.equal(data.state.items.find(x=>x.run_id===runId).state,'working');assert.equal(data.state.items.find(x=>x.run_id===runId).progress,42);
    assert.equal(data.state.music.title,'Confirmed fixture playback');assert.match(data.state.music.chat_url,/agent=7&conversation_id=11$/);
    assert.equal(data.state.artist_research.artist,'Fixture Artist');assert.equal(data.state.artist_research.status,'available');
    assert.equal(data.state.calendar[0].title,'Fixture planning session');assert.equal(data.state.calendar[0].agenda_status,'unknown');
    assert.equal(data.state.calendar[0].meeting_url,'https://example.com/meeting');
    assert.ok(!JSON.stringify(data).includes('FOREIGN SECRET'));assert.ok(!JSON.stringify(data).includes('lease_token'));
  }
  assert.deepEqual(await fetch(base+'/counts').then(r=>r.json()),before);
  for(const [url,options,status] of [
    [endpoint,{headers:{...headers,'X-Fixture-Anonymous':'1'}},403],
    [endpoint,{headers:{...headers,'X-Fixture-Denied':'1'}},403],
    [endpoint,{headers:{...headers,'X-Fixture-Participant':'1'}},403],
    [endpoint,{headers:{...headers,'X-VP3-Working-Attention':'other-session'}},403],
    [endpoint,{method:'POST',headers},405],
    [endpoint.replace('agent_id=7','agent_id=0'),{headers},403],
    [endpoint.replace('conversation_id=11','conversation_id=12'),{headers},403],
    [endpoint.replace('agent_id=7','agent_id=-1'),{headers},422]
  ])assert.equal((await fetch(url,options)).status,status,url);
  console.log('PASS actual read-only HTTP projection shows canonical claim/heartbeat progress, performs zero inference/writes, and enforces method/account/participant/Agent/conversation/session boundaries');

  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
  for(const [outside,width] of [[false,1280],[true,390]]){
    const context=await browser.newContext({viewport:{width,height:844}}),page=await context.newPage();page.setDefaultTimeout(8000);const errors=[],reads=[];
    page.on('pageerror',error=>errors.push(error.message));page.on('request',request=>{if(request.url().includes('/api/agent-top-of-mind-v1.php'))reads.push(request);});
    await page.goto(base+(outside?'/outside':'/'),{waitUntil:'domcontentloaded'});
    await page.locator('.vp3-orb-root').hover();
    await page.locator('[data-orb-brain]').click();
    const feed=page.locator('[data-agent-top-of-mind]');await feed.getByText('Prepare the saved launch details',{exact:true}).waitFor();
    assert.equal(await page.locator('#chatNotificationDrawer').count(),1);await feed.getByText('Working',{exact:true}).waitFor();
    assert.ok(await page.getByText('Saved Brain memory is unavailable for this account.',{exact:true}).isVisible());
    const music=feed.getByRole('region',{name:'Music and entertainment'});
    await music.getByText('Confirmed fixture playback',{exact:true}).waitFor();
    const artist=feed.getByRole('region',{name:'Artist research'});
    await artist.getByText('Fixture Artist',{exact:true}).waitFor();
    assert.match(await artist.getByRole('link',{name:'Review YouTube results'}).getAttribute('href'),/agent=7&conversation_id=11$/);
    assert.equal(await feed.getByRole('region',{name:'Recent YouTube results'}).count(),0,'one card per result thread');
    const calendar=feed.getByRole('region',{name:'Calendar preparation'});
    await calendar.getByText('Fixture planning session',{exact:true}).waitFor();
    assert.equal(await calendar.getByRole('link',{name:'Open meeting'}).getAttribute('href'),'https://example.com/meeting');
    await calendar.getByText('Agenda and preparation',{exact:true}).click();
    await calendar.getByText('Saved agenda details are unavailable.',{exact:true}).waitFor();
    if(outside){
      assert.match(await music.getByRole('link',{name:'Open this Chat for music picks'}).getAttribute('href'),/agent=7&conversation_id=11$/);
      assert.equal(await artist.getByRole('button').count(),0,'research is only submitted from the exact Chat');
    }else{
      assert.equal(await page.evaluate(()=>window.fixturePicks),0);
      assert.deepEqual(await page.evaluate(()=>window.fixtureResearch),[],'viewing context does not start research');
      await page.evaluate(()=>{window.fixtureParticipant={id:2};window.dispatchEvent(new Event('vp3:participant-selection-changed'));});
      await music.waitFor({state:'hidden'});
      await artist.waitFor({state:'hidden'});await calendar.waitFor({state:'hidden'});
      await page.evaluate(()=>{window.fixtureParticipant=null;window.dispatchEvent(new Event('vp3:participant-selection-changed'));});
      await page.evaluate(()=>{window.fixtureDraft=true;});
      await artist.getByRole('button',{name:'Research artist',exact:true}).click();
      await artist.getByText('Finish the current Chat turn or draft before starting artist research.',{exact:true}).waitFor();
      assert.deepEqual(await page.evaluate(()=>window.fixtureResearch),[]);
      await page.evaluate(()=>{window.fixtureDraft=false;});
      await artist.getByRole('button',{name:'Prepare specialist plan',exact:true}).click();
      assert.deepEqual(await page.evaluate(()=>window.fixtureResearch),[['Fixture Artist',true]]);
      await page.locator('.vp3-orb-root').hover();await page.locator('[data-orb-brain]').click();
      await music.getByRole('button',{name:'Ask for YouTube music picks'}).click();
      assert.equal(await page.evaluate(()=>window.fixturePicks),1);
      await page.locator('.vp3-orb-root').hover();await page.locator('[data-orb-brain]').click();
      await music.waitFor();
    }
    // Fast status IO can finish while the actual drawer is still sliding in.
    // Measure its settled open geometry, not a fractional transform frame.
    await page.locator('#chatNotificationDrawer').evaluate(async element=>{
      await new Promise(requestAnimationFrame);
      await Promise.all(element.getAnimations().map(animation=>animation.finished.catch(()=>{})));
      await new Promise(requestAnimationFrame);
    });
    const box=await page.locator('#chatNotificationDrawer').boundingBox();assert.ok(box.width<=width&&box.x>=-1,JSON.stringify({outside,expectedWidth:width,box,viewport:await page.evaluate(()=>({innerWidth,clientWidth:document.documentElement.clientWidth,drawerWidth:getComputedStyle(document.querySelector('#chatNotificationDrawer')).width,transform:getComputedStyle(document.querySelector('#chatNotificationDrawer')).transform}))}));
    assert.ok(box.x+box.width<=width+1,'the settled drawer must fit inside the right viewport edge');
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    if(process.env.VP3_TOP_OF_MIND_SCREENSHOT_DIR){mkdirSync(process.env.VP3_TOP_OF_MIND_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:join(process.env.VP3_TOP_OF_MIND_SCREENSHOT_DIR,outside?'top-of-mind-mobile.png':'top-of-mind-desktop.png')});}
    const count=reads.length;await page.waitForTimeout(2800);assert.ok(reads.length>count,'active work must refresh without reopening Brain');
    if(outside)assert.equal(await calendar.getByText('Saved agenda details are unavailable.',{exact:true}).isVisible(),true,'an expanded agenda survives polling');
    await page.evaluate(()=>{document.body.dataset.stonefellowAgentState='processing';});
    if(!outside)await feed.getByText('The main Agent is preparing a response.',{exact:true}).waitFor();
    const outage=route=>route.abort('failed');await page.route('**/api/agent-top-of-mind-v1.php**',outage);
    await feed.getByText('The latest work status is unavailable.',{exact:false}).waitFor();
    await music.waitFor({state:'hidden'});
    assert.equal(await feed.locator('[data-work-state="working"]').count(),0);await feed.locator('[data-work-state="stale"]').waitFor();
    await page.unroute('**/api/agent-top-of-mind-v1.php**',outage);await feed.getByRole('button',{name:'Retry',exact:true}).click();await feed.getByText('Working',{exact:true}).waitFor();
    await page.evaluate(()=>{window.fixtureHidden=true;Object.defineProperty(document,'hidden',{configurable:true,get:()=>window.fixtureHidden});document.dispatchEvent(new Event('visibilitychange'));});
    const hiddenCount=reads.length;await page.waitForTimeout(2800);assert.equal(reads.length,hiddenCount,'a hidden page must not poll');
    await page.evaluate(()=>{window.fixtureHidden=false;document.dispatchEvent(new Event('visibilitychange'));});await page.waitForFunction(n=>window.performance.getEntriesByType('resource').filter(x=>x.name.includes('/api/agent-top-of-mind-v1.php')).length>n,0);
    await page.locator('[data-notification-drawer-close]').click();const closedCount=reads.length;await page.waitForTimeout(2800);assert.equal(reads.length,closedCount,'a closed Brain must not poll');
    assert.deepEqual(errors,[]);
    console.log('PASS '+(outside?'mobile floating Agent away from Chat':'desktop main Chat')+': same Brain feed, optional Brain-off/failed voice independence, live progress, stale network recovery, hidden/closed polling stop and responsive layout');
    await context.close();
  }
  const timeoutContext=await browser.newContext({viewport:{width:1100,height:820}}),timeoutPage=await timeoutContext.newPage();
  timeoutPage.setDefaultTimeout(8000);await timeoutPage.clock.install();
  await timeoutPage.addInitScript(()=>{
    const original=window.fetch,originalTimeout=window.setTimeout;
    window.fixtureFeedReadSequence=0;window.fixtureFeedDeadlineSequences=[];
    // The deliberately non-cancelling transport proves the independent
    // deadline rather than relying on fetch to honor AbortController.
    window.fetch=(url,options={})=>{
      const feed=String(url).includes('/api/agent-top-of-mind-v1.php');
      if(feed)window.fixtureFeedReadSequence++;
      return original(url,feed?{...options,signal:undefined}:options);
    };
    window.setTimeout=(callback,delay,...args)=>{
      if(delay===8000)window.fixtureFeedDeadlineSequences.push(window.fixtureFeedReadSequence);
      return originalTimeout.call(window,callback,delay,...args);
    };
  });
  // Force the initial Brain state to finish after the first feed view. Its
  // normal rerender replaces that host and correctly cancels its read. Holding
  // this obsolete initial request was the old test's timing-dependent race.
  let releaseBrain;const brainRelease=new Promise(resolve=>{releaseBrain=resolve;});
  await timeoutPage.route('**/api/chat-notifications-brain-v240.php**',async route=>{await brainRelease;await route.continue();});
  await timeoutPage.goto(base,{waitUntil:'domcontentloaded'});await timeoutPage.locator('.vp3-orb-root').hover();await timeoutPage.locator('[data-orb-brain]').click();
  const deadlineFeed=timeoutPage.locator('[data-agent-top-of-mind]');
  await deadlineFeed.getByText('Prepare the saved launch details',{exact:true}).waitFor();
  releaseBrain();await timeoutPage.getByText('Saved Brain memory is unavailable for this account.',{exact:true}).waitFor();
  await deadlineFeed.getByText('Prepare the saved launch details',{exact:true}).waitFor();
  const currentHost=await deadlineFeed.elementHandle();
  let releaseHeld,arriveHeld;const heldArrived=new Promise(r=>{arriveHeld=r;}),heldRelease=new Promise(r=>{releaseHeld=r;});let intercepted=0,heldSequence=0;
  await timeoutPage.route('**/api/agent-top-of-mind-v1.php**',async route=>{
    if(++intercepted!==1)return route.continue();
    heldSequence=await timeoutPage.evaluate(()=>window.fixtureFeedReadSequence);
    arriveHeld();await heldRelease;
    const pageBinding=await timeoutPage.evaluate(()=>STONEFELLOW_CHAT.workingAttentionBinding);
    await route.fulfill({json:{ok:true,state:{contract:'vp3.top-of-mind.v1',owner_user_id:1,agent_id:7,conversation_id:11,
      working_attention_binding:pageBinding,observed_at:new Date().toISOString(),state:'ready',coverage:{},items:[{
        run_id:999,state:'working',objective:'LATE STALE OBJECTIVE',step:'Obsolete step',progress:99,
        lease_expires_at:new Date(Date.now()+240000).toISOString(),sources:[],result_url:'',owner_state:''
      }]}}}).catch(()=>{});
  });
  await timeoutPage.clock.runFor(2600);let heldArrivalTimeout;
  try{await Promise.race([heldArrived,new Promise((resolve,reject)=>{heldArrivalTimeout=setTimeout(()=>reject(new Error('The current feed poll did not reach the held transport.')),8000);})]);}
  finally{clearTimeout(heldArrivalTimeout);}
  await timeoutPage.waitForFunction(sequence=>window.fixtureFeedDeadlineSequences.includes(sequence),heldSequence);
  assert.equal(await currentHost.evaluate(element=>element.isConnected),true,'the held read must belong to the current, settled Brain host');
  await timeoutPage.clock.fastForward(8100);
  await deadlineFeed.getByText('The latest work status is unavailable.',{exact:false}).waitFor();
  assert.equal(intercepted,1,'a replacement read must not satisfy this deadline proof');
  assert.equal(await currentHost.evaluate(element=>element.isConnected),true,'the current host must report its own deadline');
  await deadlineFeed.getByRole('button',{name:'Retry',exact:true}).evaluate(button=>button.click());await deadlineFeed.getByText('Prepare the saved launch details',{exact:true}).waitFor();
  releaseHeld();await timeoutPage.waitForTimeout(200);assert.equal(await deadlineFeed.getByText('LATE STALE OBJECTIVE',{exact:true}).count(),0);
  await timeoutPage.unroute('**/api/agent-top-of-mind-v1.php**');
  const pageBinding=await timeoutPage.evaluate(()=>STONEFELLOW_CHAT.workingAttentionBinding);
  await timeoutPage.route('**/api/agent-top-of-mind-v1.php**',route=>route.fulfill({json:{ok:true,state:{contract:'vp3.top-of-mind.v1',owner_user_id:2,agent_id:7,conversation_id:11,working_attention_binding:pageBinding,observed_at:new Date().toISOString(),state:'ready',items:[{run_id:999,state:'working',objective:'FOREIGN PRIVATE WORK'}]}}}));
  await timeoutPage.clock.fastForward(2600);await deadlineFeed.getByText('Your Agent session or access changed. Reload to see current work.',{exact:false}).waitFor();
  assert.equal(await deadlineFeed.getByText('Prepare the saved launch details',{exact:true}).count(),0);assert.equal(await deadlineFeed.getByText('FOREIGN PRIVATE WORK',{exact:true}).count(),0);
  await timeoutContext.close();
  console.log('PASS an abort-ignoring transport hits the real8s client deadline, Retry recovers independently, late status cannot replace fresh work, and a foreign-owner response clears private content');
  await fetch(base+'/expire',{method:'POST',headers:{...headers,'X-Fixture-CSRF':'fixture'}});
  const expired=await fetch(endpoint,{headers}).then(r=>r.json());assert.equal(expired.state.items.find(x=>x.run_id===runId).state,'interrupted');
  await fetch(base+'/source-change',{method:'POST',headers:{...headers,'X-Fixture-CSRF':'fixture'}});
  const changed=await fetch(endpoint,{headers}).then(r=>r.json());assert.equal(changed.state.items.some(x=>x.run_id===runId),false);assert.ok(!JSON.stringify(changed).includes('Prepare the saved launch details'));
  assert.equal((await fetch(base+'/counts').then(r=>r.json())).models,0);
  console.log('PASS actual expired lease clears Working, changed source removes its private objective/result, and every status read remains inference-free');
}catch(error){console.error(logs.slice(-7000));throw error;}
finally{await browser?.close();server.kill('SIGTERM');await new Promise(r=>server.once('exit',r));rmSync(temp,{recursive:true,force:true});}
