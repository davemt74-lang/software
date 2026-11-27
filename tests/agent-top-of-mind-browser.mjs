import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {copyFileSync,existsSync,mkdirSync,mkdtempSync,readFileSync,rmSync,writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

// Real API, read projection, source readers, queue/claim/heartbeat and browser
// modules. Identity/provider/calendar inputs are the existing SQLite fixture.
const source=fileURLToPath(new URL('../',import.meta.url)),temp=mkdtempSync(join(tmpdir(),'vp3-top-of-mind-'));
const modelGuard=join(temp,'model-attempts');
const probe=net.createServer();await new Promise(resolve=>probe.listen(0,'127.0.0.1',resolve));const port=probe.address().port;await new Promise(resolve=>probe.close(resolve));
const base='http://127.0.0.1:'+port;
mkdirSync(join(temp,'api'));mkdirSync(join(temp,'includes'));
copyFileSync(join(source,'api/agent-top-of-mind-v1.php'),join(temp,'api/agent-top-of-mind-v1.php'));
writeFileSync(join(temp,'includes/agent-top-of-mind-v1.php'),'<?php require_once '+JSON.stringify(join(source,'includes/agent-top-of-mind-v1.php'))+';');
writeFileSync(join(temp,'includes/bootstrap.php'),[
  '<?php require_once '+JSON.stringify(join(source,'tests/fixtures/initiative-ai-settings-v1.php'))+';',
  'session_start(); require '+JSON.stringify(join(source,'tests/fixtures/initiative-chat-loop-http.php'))+';',
  '$GLOBALS["imageFixtureSettingsObserver"]=static function():void{foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame){',
  'if(($frame["function"]??null)==="ai_generate_chat_response"){file_put_contents(getenv("VP3_TOP_OF_MIND_MODEL_GUARD"),"attempt\\n",FILE_APPEND|LOCK_EX);throw new LogicException("Top of Mind must not invoke a model.");}}};',
  'if(isset($_SERVER["HTTP_X_FIXTURE_PARTICIPANT"]))$GLOBALS["VP3_PARTICIPANT_RESOURCE_SCOPE_V1"]=["owner"=>1,"agent"=>7,"participant"=>2,"revision"=>1];'
].join('\n'));
const assets=['agent-top-of-mind-v1.js','agent-top-of-mind-v1.css','agent-orb.js','agent-orb-adapter.js','agent-eyes-core.js','chat-notifications-drawer-v240.js','chat-notifications-drawer-v240.css','agent-orb.css','gmail-inbox-initial-v1.js','chat-email-v1.css'];
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
  'agent_connection_youtube_results_save_v1(db(),1,11,$message,["suggestion_kind"=>"music","expires_at"=>time()+3600,"videos"=>[["video_id"=>"song0000001","title"=>"Confirmed fixture playback","channel_title"=>"Fixture channel","made_for_kids"=>false]]]);',
  'if(!vp3_youtube_history_record_v1(db(),1,$message,"song0000001","10000000-0000-4000-8000-000000000001",vp3_youtube_capture_key_v1(db(),1)))throw new RuntimeException("Fixture playback was rejected.");',
  'db()->exec("INSERT INTO chat_messages(conversation_id,role,message) VALUES(11,\'assistant\',\'YouTube artist results\')");$artistMessage=(int)db()->lastInsertId();',
  'agent_connection_youtube_results_save_v1(db(),1,11,$artistMessage,["query"=>"Artists similar to Fixture Artist","taste_topic"=>"Fixture Artist","suggestion_kind"=>"similar","expires_at"=>time()+3600,"videos"=>[["video_id"=>"song0000002","title"=>"Provider artist title"]]]);',
  '$artistResult=["artist_research_v1"=>["artist"=>"Fixture Artist","mode"=>"read","status"=>"read_complete"],"tool_receipt"=>vp3_tool_receipt_v1("artist.research","search","read_complete")];db()->prepare("INSERT INTO chat_messages(conversation_id,role,message,context_json) VALUES(11,\'assistant\',\'Verified artist report\',?)")->execute([json_encode($artistResult)]);$artistReport=(int)db()->lastInsertId();',
  'foreach(["Acoustic guitar bridge repair","Acoustic guitar bridge replacement"] as $index=>$query){db()->exec("INSERT INTO chat_messages(conversation_id,role,message) VALUES(11,\'assistant\',\'YouTube interest results\')");agent_connection_youtube_results_save_v1(db(),1,11,(int)db()->lastInsertId(),["query"=>$query,"expires_at"=>time()+3600,"videos"=>[["video_id"=>"repair0000".$index,"title"=>"Provider repair clip"]]]);}',
  'db()->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES(14,1,7)");',
  'db()->exec("INSERT INTO chat_messages(conversation_id,role,message) VALUES(14,\'assistant\',\'YouTube topic results\')");$topicMessage=(int)db()->lastInsertId();',
  'agent_connection_youtube_results_save_v1(db(),1,14,$topicMessage,["query"=>"Wood-fired oven heat management","expires_at"=>time()+3600,"videos"=>[["video_id"=>"oven0000001","title"=>"Oven tutorial provider title"]]]);',
  'vp3_youtube_research_save_v1(db(),1,"topic",true);$interests=vp3_initiative_evidence_v1(db(),$u,new DateTimeImmutable("now",new DateTimeZone("UTC")),false,[],$coverage,null,7);',
  '$interest=array_values(array_filter($interests,static fn(array $row):bool=>$row["kind"]==="topic_interest"))[0];$ref=$interest["ref"];',
  '$interestGoal=["binding"=>$policy,"sources"=>[$ref=>$interest["revision"]],"canonical_sources"=>[$ref=>$interest["revision"]],"tool"=>"topic.research","primary"=>$ref,"focus"=>"Find verified bridge repair methods","report"=>true];',
  'vp3_initiative_enqueue_v1(db(),$u,"initiative.prepare",$interestGoal,hash("sha256","top-of-mind-http-interest"));$interestRun=(int)db()->query("SELECT MAX(id) FROM agent_workflow_runs")->fetchColumn();',
  'db()->exec("CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title TEXT,status TEXT,all_day INTEGER,start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,source TEXT,source_reference TEXT,description TEXT,location TEXT)");',
  // The real API keeps reading wall time. A two-day all-day event remains
  // active if desktop and mobile checks straddle the next UTC midnight.
  '$calendarSeed=new DateTimeImmutable("now",new DateTimeZone("UTC"));$calendarStart=$calendarSeed->setTime(0,0);$calendarMidnight=$calendarStart->modify("+1 day");$calendarEnd=$calendarStart->modify("+2 days");',
  'db()->prepare("INSERT INTO user_calendar_events VALUES(4,1,\'Fixture planning session\',\'active\',1,?,?,\'UTC\',\'user\',\'\',\'\',\'https://example.com/meeting\')")->execute([$calendarStart->format("Y-m-d H:i:s"),$calendarEnd->format("Y-m-d H:i:s")]);',
  'require_once $source."/includes/agent-calendar-prep-v1.php";$calendarBoundaries=[];',
  'foreach(["before_midnight"=>$calendarMidnight->modify("-1 second"),"after_midnight"=>$calendarMidnight->modify("+1 second"),"at_expiry"=>$calendarEnd] as $name=>$instant){',
  '$calendarBoundaries[$name]=["at"=>$instant->format(DATE_ATOM),"items"=>vp3_calendar_prep_today_v1(db(),$u,7,11,$instant)];}',
  'header("Content-Type:application/json");echo json_encode(["run_id"=>(int)$r,"interest_run_id"=>$interestRun,"artist_report_id"=>$artistReport,"topic_message_id"=>$topicMessage,"calendar_boundaries"=>$calendarBoundaries]);return;}',
  'if($path==="/api/chat-notifications-brain-v240.php"){header("Content-Type:application/json");echo json_encode(["ok"=>true,"complete"=>true,"agent_namespace"=>"agent:7","notifications"=>["items"=>[],"unread"=>0],"brain"=>["enabled"=>false],"activity_stream"=>["complete"=>true,"agent_namespace"=>"agent:7","items"=>[]]]);return;}',
  'if(!in_array($path,["/","/outside","/chat.php"],true)){http_response_code(404);return;}',
  '$binding=vp3_catch_up_page_binding_v1(current_user());$outside=$path==="/outside";',
  'echo \'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/agent-top-of-mind-v1.css"><link rel="stylesheet" href="/chat-notifications-drawer-v240.css"><link rel="stylesheet" href="/agent-orb.css"><body><main>Owner workspace</main>\';',
  'if(!$outside)echo \'<link rel="stylesheet" href="/chat-email-v1.css"><div class="chat-topbar-actions"><div id="chatNotificationMenu"><button id="chatNotificationButton">Activity Center</button></div><div id="chatProfileMenu">Account</div></div><div id="chatThread" data-view="chat"></div><textarea id="chatInput"></textarea>\';',
  '$orb=["ownerKey"=>"1","agentId"=>7,"chatUrl"=>"/chat.php","workingAttentionBinding"=>$binding,"topOfMindEndpoint"=>"/api/agent-top-of-mind-v1.php","notificationsEndpoint"=>"/api/chat-notifications-brain-v240.php","csrf"=>"fixture","voiceScript"=>"/deliberately-unavailable-voice.js","drawerScript"=>"/chat-notifications-drawer-v240.js"];',
  'echo "<script>window.VP3_ORB_CONFIG=".json_encode($orb).";</script>";',
  '$gmail=["endpoint"=>"/api/gmail-inbox-initial-v1.php","ownerKey"=>"1","agentId"=>7,"csrf"=>"fixture","connectionsUrl"=>"/connections.php","chatUrl"=>"/chat.php"];',
  'if(!$outside)echo "<script>window.STONEFELLOW_CHAT={userId:1,agentId:7,initialConversationId:11,workingAttentionBinding:".json_encode($binding)."};window.fixturePicks=0;window.VP3ParticipantContinuity={selectedContext:()=>window.fixtureParticipant,clearSelection:()=>{window.fixtureParticipant=null}};window.STONEFELLOW_CHAT_CONTINUITY_V87={conversationId:()=>11,agentId:()=>7,isReady:()=>true,showChatView:()=>{},requestGmailReviewAction:command=>{window.fixtureReply=command;return true},requestYoutubePicks:()=>{window.fixturePicks++;return true},openConversation:async (id,options)=>{window.fixtureSource={id,options};return true}};window.STONEFELLOW_NOTIFICATION_DRAWER=".json_encode(["endpoint"=>"/api/chat-notifications-brain-v240.php","csrf"=>"fixture","userId"=>1,"agentId"=>7,"workingAttentionBinding"=>$binding,"topOfMindEndpoint"=>"/api/agent-top-of-mind-v1.php"]).";window.VP3_GMAIL_INBOX_INITIAL_V1=".json_encode($gmail).";</script><script src=/chat-notifications-drawer-v240.js></script><script src=/gmail-inbox-initial-v1.js></script>";',
  'echo \'<script src="/agent-eyes-core.js"></script><script src="/agent-top-of-mind-v1.js"></script><script src="/agent-orb-adapter.js"></script><script src="/agent-orb.js"></script>\';'
].join('\n'));
assert.match(execFileSync(process.env.VP3_PHP_BINARY||'php',['-l',join(temp,'router.php')],{encoding:'utf8'}),/No syntax errors detected/);
let logs='';const server=spawn(process.env.VP3_PHP_BINARY||'php',['-d','session.save_path='+temp,'-S','127.0.0.1:'+port,'-t',temp,join(temp,'router.php')],{env:{...process.env,VP3_IMAGE_TEST_DSN:'sqlite:'+join(temp,'state.sqlite'),VP3_TOP_OF_MIND_MODEL_GUARD:modelGuard},stdio:['ignore','pipe','pipe']});
server.stdout.on('data',x=>{logs+=x;});server.stderr.on('data',x=>{logs+=x;});
let browser;
async function openBrain(page){
  await page.waitForFunction(()=>typeof window.STONEFELLOW_NOTIFICATION_CENTER?.openBrain==='function');
  await page.evaluate(()=>window.STONEFELLOW_NOTIFICATION_CENTER.openBrain());
}
try{
  for(let i=0;;i++){try{const ready=await fetch(base+'/health');if(ready.ok&&(await ready.text())==='ready')break;}catch{}if(i>50)throw Error('PHP did not start');await new Promise(r=>setTimeout(r,50));}
  const session=await fetch(base+'/session');assert.ok(session.headers.get('set-cookie'),'session bootstrap: '+session.status+' '+await session.clone().text());
  const cookie=session.headers.get('set-cookie').split(';')[0],binding=(await session.json()).binding;
  const headers={'Cookie':cookie,'X-VP3-Working-Attention':binding};
  const seed=await fetch(base+'/seed',{method:'POST',headers:{...headers,'X-Fixture-CSRF':'fixture'}});assert.equal(seed.status,200,await seed.clone().text());
  const {run_id:runId,interest_run_id:interestRunId,artist_report_id:artistReportId,topic_message_id:topicMessageId,calendar_boundaries:calendarBoundaries}=await seed.json();
  assert.equal(Date.parse(calendarBoundaries.after_midnight.at)-Date.parse(calendarBoundaries.before_midnight.at),2000);
  for(const boundary of ['before_midnight','after_midnight']){
    assert.deepEqual(calendarBoundaries[boundary].items.map(({id,title,all_day})=>({id,title,all_day})),
      [{id:4,title:'Fixture planning session',all_day:true}],boundary+' must retain the same active all-day event');
  }
  assert.deepEqual(calendarBoundaries.at_expiry.items,[],'the real calendar projection must omit the event at its actual expiry');
  console.log('PASS real calendar projection retains the same all-day event across UTC midnight and removes it at its actual expiry');
  const endpoint=base+'/api/agent-top-of-mind-v1.php?agent_id=7&conversation_id=11';
  const before=await fetch(base+'/counts').then(r=>r.json());
  for(let i=0;i<3;i++){
    const r=await fetch(endpoint,{headers});assert.equal(r.status,200,await r.clone().text());assert.match(r.headers.get('cache-control'),/no-store/);
    const data=await r.json();assert.equal(data.state.owner_user_id,1);assert.equal(data.state.agent_id,7);assert.equal(data.state.conversation_id,11);assert.equal(data.state.working_attention_binding,binding);
    assert.equal(data.state.items.find(x=>x.run_id===runId).state,'working');assert.equal(data.state.items.find(x=>x.run_id===runId).progress,42);
    assert.ok(data.state.specialists.some(item=>item.id==='planning'));
    assert.ok(data.state.specialists.some(item=>item.id==='tasks'));
    assert.equal(data.state.music.title,'Confirmed fixture playback');assert.match(data.state.music.chat_url,/agent=7&conversation_id=11&message_id=\d+$/);
    assert.equal(data.state.artist_research.artist,'Fixture Artist');assert.equal(data.state.artist_research.status,'ready');
    assert.equal(data.state.artist_research.message_id,artistReportId);
    assert.equal(data.state.topic_research,null);assert.equal(data.state.youtube_search.message_id,topicMessageId);
    assert.equal(data.state.youtube_search.conversation_id,14);
    assert.deepEqual(data.state.items.find(x=>x.run_id===interestRunId).youtube_interest,{search_count:2,related_search_count:2});
    assert.equal(data.state.calendar[0].title,'Fixture planning session');assert.equal(data.state.calendar[0].agenda_status,'unknown');
    assert.equal(data.state.calendar[0].meeting_url,'https://example.com/meeting');
    assert.ok(!JSON.stringify(data).includes('FOREIGN SECRET'));assert.ok(!JSON.stringify(data).includes('lease_token'));
  }
  const unsaved=await fetch(endpoint.replace('conversation_id=11','conversation_id=0&scope=chat'),{headers});
  assert.equal(unsaved.status,200);
  const unsavedStatus=(await unsaved.json()).state;
  assert.equal(unsavedStatus.conversation_id,0);
  assert.deepEqual(unsavedStatus.items,[],'an unsaved Chat must not borrow another Chat’s background work');
  assert.deepEqual(await fetch(base+'/counts').then(r=>r.json()),before);
  assert.equal(existsSync(modelGuard),false,'read-only Top of Mind requests cannot enter actual model generation');
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
    const context=await browser.newContext({viewport:{width,height:844},locale:'en-US',timezoneId:'America/Phoenix'}),page=await context.newPage();page.setDefaultTimeout(8000);const errors=[],reads=[];
    const emailId='a'.repeat(64);
    const triageFirst='b'.repeat(64),triageSecond='c'.repeat(64),triageEarlier='d'.repeat(64),triageCursor='e'.repeat(64);
    const triageRows=[
      {id:triageFirst,revision:1,from:'offers@example.test',subject:'Spring offer <img src=bad>',context:'unknown',purpose:'promotion',
        sender:'automated_service',attention:'read_later',reason:'Gmail classified this as a promotion.',group:'promotion'},
      {id:triageSecond,revision:1,from:'sale@example.test',subject:'Second page offer',context:'unknown',purpose:'promotion',
        sender:'automated_service',attention:'read_later',reason:'Gmail classified this as a promotion.',group:'promotion'},
      {id:triageEarlier,revision:1,from:'archive@example.test',subject:'Earlier statement',context:'personal',purpose:'receipt',
        sender:'organization',attention:'informational',reason:'Earlier mail from the catch-up scan.',group:'historical',due_date:'2026-11-12'}
    ];
    const triageCorrections=[],triageOpens=[];
    let emailStage='needs_review',emailRevision=1;
    let emailBinding='';
    await page.route('**/api/agent-top-of-mind-v1.php?*',async route=>{
      const response=await route.fetch();
      const data=await response.json();
      if(!response.ok()||data.state?.contract!=='vp3.top-of-mind.v1')return route.fulfill({response,json:data});
      emailBinding=data.state.working_attention_binding;
      const research=data.state.items.find(item=>item.run_id===interestRunId);
      if(research)Object.assign(research,{state:'completed',message_id:artistReportId,
        result_url:`/chat.php?agent=7&conversation_id=11&message_id=${artistReportId}`,
        meeting_followup_action:{skill:'task',status:'approval_pending'},
        meeting_followup:{title:'Supplier call',commitment:'Check delivery <img src=bad>',url:'/meeting.php?meeting=supplier-call#meetingAgenda'}});
      data.state.gmail_inbox={state:'checked',settings_url:'/agent-connections.php',
        sync:{mode:'catchup',status:'truncated',can_continue:true},
        scan:{at:new Date().toISOString(),from:new Date(Date.now()-60000).toISOString(),total:0,important:0,replies:0},
        matters:{items:[{id:emailId,subject:'Supplier call <img src=bad>',stage:emailStage,revision:emailRevision,
          sent_in_gmail:true,mailbox_state:'archived'}]},
        triage:{generation:4321,groups:[
          {key:'active',label:'Needs review',count:triageRows.filter(item=>item.group==='active').length},
          {key:'promotion',label:'Promotions',count:21+triageRows.filter(item=>item.group==='promotion').length},
          {key:'historical',label:'Catch-up',count:1}
        ]}};
      if(outside){
        const ownerQuestion={category:'action_required',priority:'high',summary:'Approve the venue date <img src=bad>',followup_question:'Does Friday work?'};
        const researchLead={category:'opportunity',priority:'normal',summary:'Verify the venue policy',handoff_question:'Does the venue allow amplified music?'};
        data.state.gmail_inbox.report={handoffs:{needs_attention:[ownerQuestion],agent_work:[researchLead]},
          running_report:{findings:[ownerQuestion,researchLead,{summary:'Routine email report entry'}]}};
      }
      data.state.coordination={phase:'planning',detail:'Preparing the next verified step.',finding:'Research found a supplier option <img src=bad>.'};
      data.state.specialists=[
        {id:'planning',state:'waiting_specialist',detail:'Waiting for Research to verify supplier availability.'},
        {id:'tasks',state:'thinking',detail:'Reviewing saved tasks <img src=bad>.'},
        {id:'research',state:'ready',progress:100,run_ids:[interestRunId],
          conversation_id:11,message_id:artistReportId,result_url:`/chat.php?agent=7&conversation_id=11&message_id=${artistReportId}`},
        {id:'gmail',state:'idle'},
        {id:'fake',state:'working',detail:'This specialist cannot be shown.'}
      ];
      return route.fulfill({response,json:data});
    });
    await page.route('**/api/gmail-inbox-initial-v1.php',async route=>{
      const body=route.request().postDataJSON();
      const session={owner_key:'1',csrf_token:'fixture',working_attention_binding:emailBinding};
      if(body.action==='status')return route.fulfill({json:{ok:true,review:{state:'needs_start'}}});
      if(body.action==='matter_update'){
        assert.deepEqual(body,{...session,action:'matter_update',matter_id:emailId,revision:1,decision:'snooze'});
        emailStage='snoozed';emailRevision=2;
        return route.fulfill({json:{ok:true}});
      }
      const triageSession={...session,agent_id:7,conversation_id:outside?0:11,generation:4321};
      if(body.action==='triage_open'){
        const id=outside?triageSecond:triageEarlier;
        assert.deepEqual(body,{...triageSession,conversation_id:11,action:'triage_open',item_ref:id});
        const item=triageRows.find(item=>item.id===id);
        triageOpens.push(id);
        return route.fulfill({json:{ok:true,item:{subject:item.subject,from:item.from,body:'Exact email text <img src=bad>',
          answered_in_gmail:!outside}}});
      }
      if(body.action==='triage_group'){
        assert.deepEqual(body,{...triageSession,action:'triage_group',group:body.group,cursor:body.cursor});
        assert.ok(['promotion','historical','active'].includes(body.group));
        const rows=triageRows.filter(item=>item.group===body.group);
        const isPaged=body.group==='promotion';
        const second=body.cursor===triageCursor;
        assert.ok(body.cursor===null||isPaged&&second);
        const items=isPaged?rows.slice(second?1:0,second?2:1):rows;
        return route.fulfill({json:{ok:true,group:{items,next:isPaged&&!second&&rows.length>1?triageCursor:null}}});
      }
      const sendingBack=triageCorrections.length===1;
      assert.deepEqual(body,{...triageSession,action:'triage_correct',triage_id:outside?triageSecond:triageFirst,
        revision:sendingBack?2:1,attention:sendingBack?'read_later':'review',remember_sender:outside});
      const item=triageRows.find(item=>item.id===body.triage_id);
      item.revision++;item.group=sendingBack?'promotion':'active';item.attention=body.attention;triageCorrections.push(body);
      return route.fulfill({json:{ok:true,item}});
    });
    // The global orb's lightweight work view remains active outside Chat;
    // these counters measure the full Brain feed controlled by this drawer.
    const isBrainRead=url=>url.includes('/api/agent-top-of-mind-v1.php')&&new URL(url).searchParams.get('view')!=='work';
    page.on('pageerror',error=>errors.push(error.message));page.on('request',request=>{if(isBrainRead(request.url()))reads.push(request);});
    await page.goto(base+(outside?'/outside':'/'),{waitUntil:'domcontentloaded'});
    await openBrain(page);
    const feed=page.locator('[data-agent-top-of-mind]');await feed.getByText('Prepare the saved launch details',{exact:true}).waitFor();
    const coordination=feed.getByRole('region',{name:'Agent coordination'});
    await coordination.getByText('Planning',{exact:true}).waitFor();
    await coordination.getByText('Research found a supplier option <img src=bad>.',{exact:true}).waitFor();
    assert.equal(await coordination.locator('img').count(),0);
    const planning=feed.getByRole('region',{name:'Planning & Scheduling'});
    await planning.getByText('Waiting for another agent',{exact:true}).waitFor();
    await planning.getByText('Waiting for Research to verify supplier availability.',{exact:true}).waitFor();
    assert.equal(await planning.getByRole('progressbar').count(),0,'a specialist wait carries no percent');
    const tasks=feed.getByRole('region',{name:'Tasks & Lists'});
    await tasks.getByText('Thinking',{exact:true}).waitFor();
    const taskProgress=tasks.getByRole('progressbar',{name:'Tasks & Lists progress'});
    assert.equal(await taskProgress.getAttribute('aria-valuenow'),null,'unmeasured thinking is indeterminate');
    assert.equal(await taskProgress.getAttribute('value'),null);
    await tasks.getByText('Reviewing saved tasks <img src=bad>.',{exact:true}).waitFor();
    assert.equal(await tasks.locator('img').count(),0);
    assert.notEqual(await tasks.locator('[data-specialist-state]').evaluate(el=>getComputedStyle(el).getPropertyValue('--vp3-specialist-color')),
      await planning.locator('[data-specialist-state]').evaluate(el=>getComputedStyle(el).getPropertyValue('--vp3-specialist-color')));
    const researchSection=feed.getByRole('region',{name:'Research',exact:true});
    assert.equal(await researchSection.getByRole('progressbar',{name:'Research progress'}).getAttribute('value'),'100');
    assert.equal(await researchSection.getByRole('link',{name:'View in Chat',exact:true}).getAttribute('href'),
      `/chat.php?agent=7&conversation_id=11&message_id=${artistReportId}`);
    assert.equal(await feed.locator('[data-top-of-mind-specialist="fake"]').count(),0);
    if(!outside){
      const image=feed.getByRole('region',{name:'Image Generator',exact:true});
      assert.equal(await image.count(),0,'an unobserved specialist is not shown as active');
      await page.evaluate(()=>{
        window.VP3ChatSpecialists={snapshot:()=>[{key:'image-generator:fixture',state:'running',
          label:'Image Generator',title:'Design rendering',status:'Generating image'}]};
        window.dispatchEvent(new Event('vp3:chat-specialists-changed'));
      });
      await image.getByText('Design rendering',{exact:true}).waitFor();
      assert.equal(await image.getByRole('progressbar').getAttribute('aria-valuenow'),null);
      await page.evaluate(()=>{delete window.VP3ChatSpecialists;window.dispatchEvent(new Event('vp3:chat-specialists-changed'));});
      await image.waitFor({state:'detached'});
    }
    const email=feed.getByRole('region',{name:'Gmail inbox monitoring'});
    await email.getByText('Inbox monitoring details',{exact:true}).click();
    assert.equal(await email.locator('[data-specialist-state]').getAttribute('data-specialist-state'),'idle');
    if(outside){
      const owner=feed.getByRole('region',{name:'Needs attention',exact:true});
      const research=feed.getByRole('region',{name:'Agent work',exact:true});
      await owner.getByText('Approve the venue date <img src=bad>',{exact:true}).waitFor();
      await owner.getByText('Does Friday work?',{exact:true}).waitFor();
      await research.getByText('Does the venue allow amplified music?',{exact:true}).waitFor();
      assert.equal(await feed.getByText('Routine email report entry',{exact:true}).count(),0);
      assert.equal(await owner.locator('img').count(),0);
    }else await email.getByText('0 new Gmail messages reviewed since the previous scan', {exact:false}).waitFor();
    await email.getByText('The Gmail review reached its scan limit. Some earlier messages may still need review.',{exact:true}).waitFor();
    assert.equal(await email.getByRole('link',{name:'Continue in Gmail settings'}).getAttribute('href'),'/agent-connections.php');
    await email.getByText('Supplier call <img src=bad>',{exact:false}).waitFor();
    await email.getByText('Reply sent in Gmail · Archived in Gmail',{exact:true}).waitFor();
    assert.equal(await email.locator('img').count(),0);
    await email.getByRole('button',{name:'Snooze 1 hour'}).click();
    await email.getByRole('button',{name:'Resume',exact:true}).waitFor();
    const triage=email.getByRole('region',{name:'Email triage'});
    await triage.getByRole('group',{name:'Earlier mail'}).getByRole('button',{name:'Catch-up 1'}).click();
    await triage.getByText('Earlier statement',{exact:true}).waitFor();
    await triage.locator('time[datetime="2026-11-12"]').getByText('Nov 12, 2026',{exact:true}).waitFor();
    await triage.getByRole('group',{name:'Current inbox'}).getByRole('button',{name:'Promotions 23'}).click();
    const promotions=triage.getByRole('region',{name:'Promotions messages'});
    await promotions.getByText('Spring offer <img src=bad>',{exact:true}).waitFor();
    await promotions.getByText('Gmail classified this as a promotion.',{exact:true}).waitFor();
    assert.equal(await promotions.locator('img').count(),0);
    await promotions.getByRole('button',{name:'Next page'}).click();
    await promotions.getByText('Second page offer',{exact:true}).waitFor();
    if(!outside){
      await promotions.getByRole('button',{name:'Previous page'}).click();
      await promotions.getByText('Spring offer <img src=bad>',{exact:true}).waitFor();
    }
    await promotions.getByRole('button',{name:/Change priority for/}).click();
    await promotions.getByRole('combobox',{name:'Priority',exact:true}).selectOption('review');
    const remember=promotions.getByRole('checkbox',{name:'Apply to future mail from this sender'});
    assert.equal(await remember.isChecked(),false);
    if(outside)await remember.check();
    await promotions.getByRole('button',{name:'Save priority'}).click();
    await promotions.getByText(outside?'Priority saved for this sender’s future mail.':'Priority saved for this email.',{exact:true}).waitFor();
    await triage.getByRole('button',{name:'Needs review 1'}).click();
    const active=triage.getByRole('region',{name:'Needs review messages'});
    await active.getByText(outside?'Second page offer':'Spring offer <img src=bad>',{exact:true}).waitFor();
    assert.equal(triageCorrections.length,1);
    if(!outside){
      await active.getByRole('button',{name:/Change priority for/}).click();
      await active.getByRole('combobox',{name:'Priority',exact:true}).selectOption('read_later');
      await active.getByRole('button',{name:'Save priority'}).click();
      await active.getByText('This group is empty.',{exact:true}).waitFor();
      await triage.getByRole('button',{name:'Needs review 0'}).click();
      await active.waitFor({state:'detached'});
      assert.equal(triageCorrections.length,2);
    }
    assert.equal(await page.locator('#chatNotificationDrawer').count(),1);await feed.getByText('Working',{exact:true}).waitFor();
    assert.ok(await page.getByText('Saved Brain memory is unavailable for this account.',{exact:true}).isVisible());
    const music=feed.getByRole('region',{name:'YouTube playback'});
    await music.getByText('Confirmed fixture playback',{exact:true}).waitFor();
    const artist=feed.getByRole('region',{name:'Artist research'});
    await artist.getByText('Fixture Artist',{exact:true}).waitFor();
    assert.match(await artist.getByRole('link',{name:'Review in Chat'}).getAttribute('href'),new RegExp(`agent=7&conversation_id=11&message_id=${artistReportId}$`));
    const search=feed.getByRole('region',{name:'Recent YouTube results'});
    await search.getByText('Wood-fired oven heat management',{exact:true}).waitFor();
    const reviewSearch=search.getByRole('link',{name:'Review these results'});
    assert.match(await reviewSearch.getAttribute('href'),new RegExp(`agent=7&conversation_id=14&message_id=${topicMessageId}$`));
    assert.equal(await feed.getByRole('region',{name:'Topic research'}).count(),0,'a single search remains observed activity');
    assert.equal(await search.getByRole('button').count(),0);assert.equal(await artist.getByRole('button').count(),0);
    const linkedResearch=feed.locator(`[data-top-of-mind-run="${interestRunId}"]`);
    assert.equal(await linkedResearch.count(),1);
    assert.equal(await researchSection.locator(`[data-top-of-mind-run="${interestRunId}"]`).count(),1);
    await linkedResearch.getByText('Why this came up · 2 related YouTube searches',{exact:true}).waitFor();
    const linkedMeeting=linkedResearch.getByRole('link',{name:'Meeting follow-up · Check delivery <img src=bad>',exact:true});
    const linkedResult=linkedResearch.getByRole('link',{name:'Open research in Chat',exact:true});
    await linkedResearch.getByText('Planning & Scheduling · Task ready for review',{exact:true}).waitFor();
    assert.equal(await linkedMeeting.getAttribute('href'),'/meeting.php?meeting=supplier-call#meetingAgenda');
    assert.equal(await linkedResearch.locator('img').count(),0);
    assert.equal(await linkedResult.getAttribute('href'),`/chat.php?agent=7&conversation_id=11&message_id=${artistReportId}`);
    assert.equal(await linkedResult.getAttribute('data-top-of-mind-source'),outside?null:String(artistReportId));
    const calendar=feed.getByRole('region',{name:'Calendar preparation'});
    await calendar.getByText('Fixture planning session',{exact:true}).waitFor();
    assert.equal(await calendar.getByRole('link',{name:'Open meeting'}).getAttribute('href'),'https://example.com/meeting');
    await calendar.getByText('Agenda and preparation',{exact:true}).click();
    await calendar.getByText('Saved agenda details are unavailable.',{exact:true}).waitFor();
    if(outside){
      assert.match(await music.getByRole('link',{name:'Review in Chat'}).getAttribute('href'),/agent=7&conversation_id=11&message_id=\d+$/);
    }else{
      await reviewSearch.click();
      assert.deepEqual(await page.evaluate(()=>window.fixtureSource),{id:14,options:{messageId:topicMessageId}});
      await openBrain(page);
      await music.waitFor();
      assert.equal(await page.evaluate(()=>window.fixturePicks),0);
      await page.evaluate(()=>{window.fixtureParticipant={id:2};window.dispatchEvent(new Event('vp3:participant-selection-changed'));});
      await music.waitFor({state:'hidden'});
      await search.waitFor({state:'hidden'});await artist.waitFor({state:'hidden'});await calendar.waitFor({state:'hidden'});
      await linkedMeeting.waitFor({state:'hidden'});await linkedResult.waitFor({state:'hidden'});
      await triage.waitFor({state:'hidden'});
      await planning.waitFor({state:'hidden'});await tasks.waitFor({state:'hidden'});await coordination.waitFor({state:'hidden'});
      await page.evaluate(()=>{window.fixtureParticipant=null;window.dispatchEvent(new Event('vp3:participant-selection-changed'));});
      await music.getByRole('button',{name:'Ask for YouTube music picks'}).click();
      assert.equal(await page.evaluate(()=>window.fixturePicks),1);
      await openBrain(page);
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
    await page.waitForRequest(request=>isBrainRead(request.url()),{timeout:8000});
    if(outside)assert.equal(await calendar.getByText('Saved agenda details are unavailable.',{exact:true}).isVisible(),true,'an expanded agenda survives polling');
    await page.evaluate(()=>{document.body.dataset.stonefellowAgentState='processing';});
    if(!outside)await feed.getByText('The main Agent is preparing a response.',{exact:true}).waitFor();
    const outage=route=>route.abort('failed');await page.route('**/api/agent-top-of-mind-v1.php**',outage);
    await feed.getByText('The latest work status is unavailable.',{exact:false}).waitFor();
    await music.waitFor({state:'hidden'});
    await planning.waitFor({state:'hidden'});await tasks.waitFor({state:'hidden'});await coordination.waitFor({state:'hidden'});
    await linkedMeeting.waitFor({state:'hidden'});await linkedResult.waitFor({state:'hidden'});
    assert.equal(await feed.locator('[data-work-state="working"]').count(),0);
    await feed.locator(`[data-top-of-mind-run="${runId}"][data-work-state="stale"]`).waitFor();
    assert.equal(await feed.getByText('Why this came up', {exact:false}).count(),0,'stale data does not display current interest evidence');
    await page.unroute('**/api/agent-top-of-mind-v1.php**',outage);await feed.getByRole('button',{name:'Retry',exact:true}).click();await feed.getByText('Working',{exact:true}).waitFor();
    await page.evaluate(()=>{window.fixtureHidden=true;Object.defineProperty(document,'hidden',{configurable:true,get:()=>window.fixtureHidden});document.dispatchEvent(new Event('visibilitychange'));});
    const hiddenCount=reads.length;await page.waitForTimeout(2800);assert.equal(reads.length,hiddenCount,'a hidden page must not poll');
    // Drain the refresh started by becoming visible before measuring closed
    // polling. Historical resource entries cannot prove that this GET ran.
    const visibleRefresh=page.waitForResponse(response=>isBrainRead(response.url())&&response.ok());
    await page.evaluate(()=>{window.fixtureHidden=false;document.dispatchEvent(new Event('visibilitychange'));});await visibleRefresh;
    await page.locator('[data-notification-drawer-close]').click();const closedCount=reads.length;await page.waitForTimeout(2800);assert.equal(reads.length,closedCount,'a closed Brain must not poll');
    await openBrain(page);
    await email.getByText('Inbox monitoring details',{exact:true}).click();
    await triage.getByRole('button',{name:outside?'Needs review 1':'Catch-up 1'}).click();
    await triage.getByRole('button',{name:outside?'Open in Chat Second page offer':'Open in Chat Earlier statement'}).click();
    if(outside){
      await page.waitForURL(base+'/chat.php?agent=7');
      assert.equal(await page.evaluate(()=>sessionStorage.getItem('vp3:gmail-triage-open:1')),null);
    }
    const reader=page.locator('#chatThread').getByRole('dialog',{name:'Gmail message'});
    await reader.getByText('Exact email text <img src=bad>',{exact:true}).waitFor();
    assert.equal(await reader.locator('img').count(),0);
    assert.equal(await feed.getByText('Exact email text <img src=bad>',{exact:true}).count(),0);
    if(outside){
      await reader.getByRole('button',{name:'Reply',exact:true}).click();
      assert.equal(await page.evaluate(()=>window.fixtureReply),`Reply to Gmail review item ${triageSecond}`);
    }else{
      await reader.getByText('Already replied in Gmail.',{exact:true}).waitFor();
      assert.equal(await reader.getByRole('button',{name:'Reply',exact:true}).count(),0);
      await page.keyboard.press('Escape');
    }
    await reader.waitFor({state:'detached'});
    assert.deepEqual(triageOpens,[outside?triageSecond:triageEarlier]);
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
  await timeoutPage.goto(base,{waitUntil:'domcontentloaded'});await openBrain(timeoutPage);
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
  assert.equal(existsSync(modelGuard),false,'browser polling must not attempt generation even if a projection catches the refusal');
  console.log('PASS actual expired lease clears Working, changed source removes its private objective/result, and every status read remains inference-free');
}catch(error){
  for(const context of browser?.contexts()||[])for(const page of context.pages())await page.unrouteAll({behavior:'ignoreErrors'});
  console.error(logs.slice(-7000));throw error;
}
finally{await browser?.close();server.kill('SIGTERM');await new Promise(r=>server.once('exit',r));rmSync(temp,{recursive:true,force:true});}
