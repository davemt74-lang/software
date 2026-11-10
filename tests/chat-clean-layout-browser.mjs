import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';

// Shared PHP templates, canonical Chat markup and shipped browser assets are
// composed together. Account inventories and API results are disposable data.
const root=fileURLToPath(new URL('../',import.meta.url));
const read=name=>fs.readFileSync(path.join(root,name),'utf8');
const legacy=read('chat-legacy-v108.php'),wrapper=read('chat.php');
assert.doesNotMatch(wrapper,/chat-cognitive-feed-v530\.(?:js|css)/);
const threadOpen=legacy.match(/<section class="chat-thread" id="chatThread">/)?.[0];
const composerOpen=legacy.match(/<div class="chat-composer-shell" id="chatComposerShell">/)?.[0];
const composer=legacy.match(/<form class="chat-composer" id="chatForm">[\s\S]*?<\/form>\s*<div class="chat-voice-status"[^>]*>[\s\S]*?<\/div>/)?.[0];
const brief=wrapper.match(/<section class="chat-agent-brief-popover"[\s\S]*?<\/section>/)?.[0];
assert.ok(threadOpen&&composerOpen&&composer&&brief,'The actual Chat template supplies the conversation and composer.');
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-clean-chat-'));
const fixture=path.join(temp,'shell.php');
fs.writeFileSync(fixture,`<?php
function current_user(){return ['id'=>1,'display_name'=>'Dave','role'=>'admin'];}
function has_permission($key,$user){return in_array($key,['chat.access','account.access'],true);}
function personal_knowledge_available($user){return false;}
function user_role_labels($user){return ['Admin'];}
function system_agent_name(){return 'Longfellow';}
function url($value){return $value;}
function e($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function csrf_token(){return 'layout-fixture';}
function db(){return null;}
function notification_recent($user,$limit){return [];}
function notification_unread_count($user){return 0;}
function member_agent_voice_enabled($user){return false;}
function member_agent_voice_toggle_html($user){return '';}
function user_avatar_url($user){return '';}
function user_initials($user){return 'D';}
function member_navigation_section_label($key){return 'Workspace';}
function member_navigation_group_label($key){return ucfirst($key);}
function member_navigation_menu_links($user){
 $items=['home'=>'Home','chat'=>'Agent Chat','profile_agent'=>'Profile Agent','messages'=>'Messages',
  'contacts'=>'Contacts','connections'=>'Connections','knowledge'=>'My Knowledge','media_library'=>'Media Library',
  'transcriptions'=>'My Transcriptions','calendar'=>'My Calendar','scheduling'=>'Scheduling','profile_commerce'=>'Products',
  'campaigns'=>'Campaigns','rewards'=>'Rewards','team'=>'Team','account'=>'Account','ai_usage'=>'AI Usage History'];
 $out=[];foreach($items as $key=>$label)$out[]=['key'=>$key,'label'=>$label,'url'=>'/'.$key.'.php','group'=>'identity'];return $out;
}
$root=$argv[1];$mainSidebarActive='chat';$mainSidebarUseNewChatButton=true;
$mainSidebarHistoryRows=[['id'=>42,'title'=>'Workshop research','updated_at'=>'2026-11-10 08:00:00'],
 ['id'=>43,'title'=>'Ideas for the next project','updated_at'=>'2026-11-09 14:00:00']];
ob_start();require $root.'/includes/main-sidebar.php';$sidebar=ob_get_clean();
$memberHeaderTitle='Agent Chat';$memberHeaderSubtitle='Longfellow';$memberHeaderActiveKey='chat';$memberHeaderShowAgentOrb=false;
ob_start();require $root.'/includes/member-header.php';$header=ob_get_clean();
echo json_encode(['sidebar'=>$sidebar,'header'=>$header],JSON_THROW_ON_ERROR);
`);
const shell=JSON.parse(execFileSync(process.env.VP3_PHP_BINARY||'php',[fixture,root],{encoding:'utf8'}));
const inertScripts=html=>html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'');
const styles=['chat-cognitive-presentation-v510.css','chat-tools.css','chat-specialist-rail-v1.css','agent-orb.css',
  'agent-orb-particles.css','agent-activity-hub-v1.css','agent-top-of-mind-v1.css'];
const scripts=['chat.js','chat-tools.js','chat-agent-identity-v236.js','agent-ui-v034.js','coding-canvas-sidebar-v1.js',
  'agent-eyes-core.js','chat-specialist-rail-v1.js','agent-activity-hub-v1.js','agent-top-of-mind-v1.js',
  'agent-orb.js','agent-orb-particles.js','chat-cognitive-presentation-v510.js'];
const origin='https://clean-chat.test';
const messages=[
  {id:1,role:'user',message:'Can you help organize the research for the workshop?'},
  {id:2,role:'assistant',message:'Yes. We can keep the sources, notes, and next steps together. What would you like to start with?'},
  {id:3,role:'user',message:'Start with the videos and notes from yesterday.'},
  {id:4,role:'assistant',message:'I can review the latest saved research and keep the next steps here in our conversation.'}
];
const draft='Compare the next two sources before building a tool.';
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],
  executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const errors=[],presentationReads=[];
let unavailable=false;
const unavailableReason='No coding model is available for your account funding and access. Open account settings to configure an allowed provider and model before starting another coding run.';
try{
  const page=await browser.newPage();page.setDefaultTimeout(10000);page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',route=>new URL(route.request().url()).origin===origin?route.fallback():route.fulfill({status:404,body:''}));
  await page.route(origin+'/**',async route=>{
    const request=route.request(),url=new URL(request.url()),pathname=url.pathname;
    const reply=json=>route.fulfill({json});
    if(pathname==='/api/presentation'){
      presentationReads.push(url.href);
      assert.equal(url.searchParams.get('include_return_digest'),'0');
      return reply({ok:true,state:{brief:{agent_name:'Longfellow',active:true,status_label:'Active',counts:{tasks:2},
        now:[{id:'legacy-now',title:'Legacy Now dashboard should stay hidden'}]},
        digest:{id:'legacy-digest',summary:'Legacy return digest should stay hidden',items:[{title:'Old return summary'}]},
        items:[{id:'legacy-now',title:'Legacy Now dashboard should stay hidden'}],poll_seconds:30}});
    }
    if(pathname==='/api/chat-v236.php'){
      const body=request.postDataJSON()||{};
      return reply({ok:true,messages:body.action==='load'?messages:[],conversations:[
        {id:42,title:'Workshop research',updated_at:'2026-11-10 08:00:00'},
        {id:43,title:'Ideas for the next project',updated_at:'2026-11-09 14:00:00'}],items:[],updates:[]});
    }
    if(pathname==='/api/coding-canvas-settings-v1.php'&&unavailable)return reply({ok:true,state:{funding:'system',selected:null,
      default:null,options:[],unavailable:unavailableReason},balance:{unlimited:true}});
    if(pathname==='/api/coding-canvas-settings-v1.php')return reply({ok:true,state:{funding:'system',selected:null,
      default:{provider:'openai',model:'layout-coder',label:'System coder'},options:[{provider:'openai',model:'layout-coder',label:'System coder'}],unavailable:''},
      balance:{unlimited:false,package_allowance:1000000,package_remaining:650000,credits_remaining:50000,reserved:0,period_end:null}});
    if(pathname==='/api/initiative-canvas-settings-v1.php')return reply({ok:true,state:{owner_user_id:1,agent_id:7,
      enabled:false,initiative_on:true,available:true,can_enable:true,revision:'a'.repeat(32),reason:''}});
    if(pathname==='/api/hub')return reply({ok:true,state:{contract:'vp3.agent.activity.v1',owner_user_id:1,agent_id:7,
      conversation_id:42,working_attention_binding:'layout-binding',agent_name:'Longfellow',current_chat:{title:'Workshop research'},
      observed_at:new Date().toISOString(),coverage:{reminders:'ready'},sections:[{id:'reminders',label:'Reminders',items:[
        {id:'reminder:1',kind:'reminder',title:'Review workshop notes',detail:'Today',actions:[]}]}]}});
    if(pathname.startsWith('/api/'))return reply({ok:true,items:[],updates:[],conversations:[],history:[],tracks:[],folders:[],
      latest:{},month:{},state:{},contract:'layout.fixture'});
    if(/\.(css|js)$/.test(pathname)){
      const file=path.resolve(root,'.'+pathname);
      if(file.startsWith(root)&&fs.existsSync(file))return route.fulfill({contentType:pathname.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(file)});
    }
    if(pathname!=='/chat.php')return route.fulfill({status:404,body:''});
    const config=`<script>
      window.STONEFELLOW_CHAT={userId:1,agentId:7,initialConversationId:42,initialView:'chat',endpoint:'/api/chat-v236.php',csrf:'layout-fixture',workingAttentionBinding:'layout-binding'};
      window.STONEFELLOW_AGENT_IDENTITY_V236={displayName:'Longfellow',systemName:'Longfellow',showOnboarding:false,agentId:7};
      window.VP3_COGNITIVE_PRESENTATION_V510={endpoint:'/api/presentation',agentId:7,csrf:'layout-fixture',workingAttentionBinding:'layout-binding',liveContext:false,initiativeRuntime:false};
      window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,shape:'orbit',workingAttentionBinding:'layout-binding',activityEndpoint:'/api/hub'};
      window.VP3ParticipantContinuity={selectedContext:()=>null};
      window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/chat.php',isMainChat:()=>true,state:()=>({state:'idle'}),
        conversationRef:()=>({id:window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()||42,agent:7}),focusChat:()=>document.getElementById('chatInput').focus(),stop:()=>{}};
      </script>`;
    return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/chat.css"></head><body class="chat-page"><div class="chat-app">
      ${inertScripts(shell.sidebar)}<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main">
      ${inertScripts(shell.header)}${threadOpen}<div id="chatWelcome" hidden></div></section>
      ${composerOpen}${brief}${composer}</div></main></div>
      ${styles.map(name=>'<link rel="stylesheet" href="/'+name+'">').join('')}${config}
      ${scripts.map(name=>'<script src="/'+name+'"></script>').join('')}</body></html>`});
  });
  for(const limited of [false,true])for(const viewport of [{width:1440,height:900},{width:1024,height:650},{width:390,height:740}]){
    unavailable=limited;
    await page.setViewportSize(viewport);await page.goto(origin+'/chat.php?agent=7&conversation_id=42');
    await page.getByText(messages[3].message,{exact:true}).waitFor();
    await page.waitForFunction(()=>document.querySelector('[data-agent-brief-content]').getAttribute('aria-busy')==='false');
    await page.waitForFunction(limited=>limited?document.getElementById('vp3CodingAgentModel').title.includes('No coding model is available'):
      !document.getElementById('vp3CodingAgentModel').disabled,limited);
    const input=page.locator('#chatInput');await input.fill(draft);await input.focus();
    await page.evaluate(()=>window.VP3_COGNITIVE_PRESENTATION.refresh());
    assert.equal(await input.inputValue(),draft);
    assert.equal(await page.locator('#chatThread>.message').count(),messages.length);
    assert.equal(await page.locator('.vp3-return-digest,#vp3ReturnDigest,[data-cognitive-feed],#chatCognitiveFeed').count(),0);
    assert.equal(await page.getByText('Legacy Now dashboard should stay hidden',{exact:true}).isVisible(),false);
    assert.equal(await page.getByText('Legacy return digest should stay hidden',{exact:true}).isVisible(),false);
    assert.equal(await page.locator('#chatAgentBriefButton,[data-orb-chat]').count(),0);
    assert.equal(await page.locator('.vp3-orb-root').count(),1);
    const footer=page.locator('.agent-sidebar-footer');
    assert.ok((await footer.boundingBox()).height<=120,'Actual Chat CSS keeps the permanent three-row footer compact.');
    assert.equal(await footer.locator('[data-coding-funding],[data-coding-credits],[data-coding-period],#vp3AgentRuntimeStrip').count(),0);
    assert.equal(await footer.locator('[data-coding-balance]').textContent(),limited?'Unlimited':'700,000');
    if(limited){
      assert.equal(await page.locator('#vp3CodingAgentModel').isDisabled(),true);
      assert.equal(await page.locator('#vp3CodingAgentModel').getAttribute('aria-invalid'),'true');
      assert.equal(await page.locator('#vp3CodingAgentModel').getAttribute('title'),unavailableReason);
      assert.equal(await footer.locator('[data-coding-token-meter]').isVisible(),false);
      assert.ok((await footer.locator('#vp3CodingSidebarStatus').boundingBox()).height<=1,'Long unavailable reasons remain accessible without expanding the footer.');
    }
    const main=await page.locator('.chat-main').boundingBox(),composerBox=await page.locator('#chatComposerShell').boundingBox();
    assert.ok(composerBox.y>=0&&composerBox.y+composerBox.height<=viewport.height+1);
    assert.ok(main.width>0&&main.x+main.width<=viewport.width+1);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    const shotName='clean-chat-'+(limited?'unavailable-':'')+viewport.width;
    if(process.env.VP3_FOOTER_SCREENSHOTS)await page.screenshot({path:path.join(root,'../'+shotName+'.png')});
    if(viewport.width<820){
      await page.getByRole('button',{name:'Open VP3 navigation'}).click();
      await page.waitForFunction(()=>document.getElementById('chatSidebar').getBoundingClientRect().x>=-1);
      await page.locator('#vp3AgentUserMenuButton').waitFor({state:'visible'});
      const footerBox=await footer.boundingBox();assert.ok(footerBox.height<=120);
      if(process.env.VP3_FOOTER_SCREENSHOTS)await page.screenshot({path:path.join(root,'../'+shotName+'-nav.png')});
      await page.locator('#closeChatSidebar').click();
    }
    await page.evaluate(()=>window.VP3AgentActivityHub.open());
    await page.getByText('Review workshop notes',{exact:true}).waitFor();
    assert.equal(await page.locator('[data-agent-activity-host]:visible').count(),1,'The orb has one shared Top of Mind pane.');
    assert.equal(await input.inputValue(),draft);
    await page.evaluate(()=>window.VP3AgentActivityHub.close(false));
    console.log('PASS integrated Chat '+viewport.width+(limited?' unavailable/Admin':' configured'));
  }
  assert.ok(presentationReads.length>=3);assert.deepEqual(errors,[]);
  console.log('CHAT_CLEAN_LAYOUT=PASS shipped sidebar/header/conversation/composer desktop1440+1024/mobile390; no automatic dashboard/digest; draft and shared Top of Mind preserved');
}finally{await browser.close();fs.rmSync(temp,{recursive:true,force:true});}
