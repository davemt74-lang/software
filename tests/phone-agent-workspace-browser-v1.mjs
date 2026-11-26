import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,mkdirSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const root=new URL('../',import.meta.url),read=file=>readFileSync(new URL(file,root),'utf8');
const html=execFileSync(process.env.PHP_BINARY||'php',[fileURLToPath(new URL('phone-location.php',root))],{encoding:'utf8'});
const parentCsp=read('phone-location.php').match(/header\("Content-Security-Policy: ([^"]+)"\)/)?.[1];
const hostSource=read('includes/phone-agent-workspace-view-v1.php');
const nativeAssets=[...hostSource.matchAll(/\$asset\('([^']+)'\)/g)].map(match=>match[1]);
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:390,height:700},hasTouch:true,isMobile:true});page.setDefaultTimeout(10000);
const requests=[],emailRequests=[],nativeRequests=[],emailCallRequests=[],errors=[],navigation=[];
const voiceConfirmQueries=[];let voiceReview=null,voiceSequence=0,voiceRequested=false,voiceExpiryMs=120000;
let heldVoiceArm=null,heldVoicePoll=null,heldVoiceClaim=null;
page.on('pageerror',error=>errors.push(error.message));
const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+i7iQAAAAASUVORK5CYII=','base64');
const id=n=>n.toString(16).padStart(32,'0'),hash=n=>n.toString(16).padStart(64,'0');
const device={id:'d'.repeat(32),name:'My iPhone',role:'owner',status:'approved',generation:1,allow_location:false,holder_can_save_places:false,
  push:{state:'registered',revision:1,key_id:'e'.repeat(64)}};
const imageId='20000000-0000-4000-8000-000000000001',canvasId='10000000-0000-4000-8000-000000000001';
const time='2026-11-27T10:00:00Z',emptyReceipt=()=>({seen_at:null,read_at:null,acknowledged_at:null,snoozed_until:null});
let signedIn=false,enrolled=false,enrollmentRevision=0,selectedAgent=0,blockedItem=null,heldFeed=null,heldReview=null,denySave=false;
const scopeRevision=()=>hash(enrollmentRevision+100+selectedAgent);
const row=(n,kind,title,extra={})=>({item_ref:id(n),revision:1,state_version:1,source_kind:kind,source_revision:hash(n+1000),agent_id:0,conversation_id:42,
  title,summary:'A saved result from your Main Agent.',state:'ready',needs_action:false,priority:'normal',occurred_at:time,updated_at:time,receipt:emptyReceipt(),
  target:{kind:kind==='agent_result'?'chat_message':kind,agent_id:0,conversation_id:42,...(kind==='gmail_draft'?{draft_id:id(101)}:
    kind==='image'?{generation_id:imageId}:kind==='canvas'?{canvas_id:canvasId,revision:1}:{message_id:n+100})},...extra});
const rows=[row(1,'gmail_draft','Proposal ready for your review',{summary:'Review the saved message to Alex before sending.',state:'needs_review',needs_action:true,priority:'important'}),
  row(2,'agent_result','Your project brief is ready'),row(3,'image','A saved color study'),row(4,'canvas','A little creative break'),
  ...Array.from({length:8},(_,index)=>row(index+5,'agent_result','Saved result '+(index+5)))];
rows[4]=row(5,'gmail_attention','An important email needs your attention',{summary:'A current Gmail message from your inbox.',state:'needs_review',needs_action:true,priority:'important',
  target:{kind:'gmail_attention',agent_id:0,conversation_id:42,item_ref:hash(700),generation:2,origin:'gmail_mailbox',conversation_role:'discussion_target'}});
const other=row(50,'agent_result','Research Agent result',{agent_id:7,conversation_id:77,target:{kind:'chat_message',agent_id:7,conversation_id:77,message_id:700}});
const draft={id:id(101),status:'prepared',from:'owner@example.test',to:'alex@example.test',subject:'Project proposal',body:'Hi Alex,\n\nHere is the proposal we discussed.\n\nThanks!',
  attachments:[],is_reply:false,can_edit:true,can_send:true,review_token:'review-1',watch_review_token:'watch-review-1',edit_revision:hash(1),saved_at:time,watch_requested:false,
  followthrough:{enabled:false,deadline_at:null,time_zone:'UTC',prepare_if_overdue:false,priority:'normal'}};
let draftRevision=1;
let emailCallPolicy={revision:0,urgent_enabled:false,drafts_enabled:false,grace_seconds:300,device_id:null,number_id:0},emailCall=null,emailActiveCall=null,emailCallItem=id(1);
const envelope=()=>({ok:true,device_id:device.id,enrollment_revision:enrollmentRevision,scope_revision:scopeRevision()});
const emailCallScope=()=>({device_id:device.id,enrollment_revision:enrollmentRevision,scope_revision:scopeRevision()});
const emailCallSettings=()=>({ok:true,scope:emailCallScope(),policy:emailCallPolicy,
  devices:[{device_id:device.id,label:'My iPhone',agent_name:selectedAgent?'Research Agent':'Main Agent',ready:true,reason:'eligible',lines:[{number_id:8,label:'My private calling line'}]}],
  owner_phone:{masked_number:'••• ••• 0199',updates_requested:true,time_zone:'America/Phoenix',window_start:'09:00',window_end:'17:00',max_calls_per_day:1,min_gap_minutes:240,max_duration_seconds:180},
  readiness:{state:emailCallPolicy.urgent_enabled||emailCallPolicy.drafts_enabled?'ready':'off',reason:emailCallPolicy.urgent_enabled||emailCallPolicy.drafts_enabled?'eligible':'off',
    message:emailCallPolicy.urgent_enabled||emailCallPolicy.drafts_enabled?'Email calls are ready within your shared calling hours and limits.':'Email calls are off.'},
  decision:null,active_call:emailActiveCall,links:{account_phone:'/account.php#agent-phone',phone_connection:'/phone-location.php'}});
const inventory=()=>selectedAgent===0?rows:[other];
const counts=()=>({unread:inventory().filter(item=>!item.receipt.read_at).length,needs_action:inventory().filter(item=>item.needs_action&&!item.receipt.acknowledged_at).length,complete:true});
function workspaceStatus(){
  const common={ok:true,device_id:device.id,state:'sign_in_required',enrolled:false,owner_eligible:false,revision:0,enrollment_revision:0,scope_revision:null,agent:null,
    conversation_id:null,counts:null,agents:[],preferences:{notifications_enabled:false}};
  if(!signedIn)return common;
  return {...common,state:enrolled?'ready':'not_enrolled',enrolled,owner_eligible:true,csrf_token:'f'.repeat(64),revision:enrollmentRevision,enrollment_revision:enrollmentRevision,
    scope_revision:enrolled?scopeRevision():null,agent:{id:selectedAgent,name:selectedAgent?'Research Agent':'Main Agent'},conversation_id:enrolled?(selectedAgent?77:42):null,
    agents:[{id:0,name:'Main Agent'},{id:7,name:'Research Agent'}]};
}
function guard(input){assert.equal(input.device_id,device.id);assert.equal(input.csrf_token,'f'.repeat(64));assert.equal(input.enrollment_revision,enrollmentRevision);assert.equal(input.scope_revision,scopeRevision());}
function hold(){let started,release,finish;return {started:new Promise(resolve=>{started=resolve;}),promise:new Promise(resolve=>{release=resolve;}),finished:new Promise(resolve=>{finish=resolve;}),start:()=>started(),release:()=>release(),finish:()=>finish()};}
function draftRow(){return rows.find(row=>row.target.kind==='gmail_draft'&&row.target.draft_id===draft.id);}
function sourceChanged(){const row=draftRow();assert.ok(row);row.revision++;row.state_version++;row.source_revision=hash(1001+draftRevision);row.receipt=emptyReceipt();emailActiveCall=null;}
function nativeHtml(url,item){
  const nonce='a'.repeat(48),scope=scopeRevision();
  const endpoint=kind=>'/api/phone-location-v1.php?'+new URLSearchParams({['workspace_'+kind]:'1',device_id:device.id,item_ref:item.item_ref,scope_revision:scope,
    ...(['image','canvas','gmail_attention'].includes(item.source_kind)?{source_revision:item.source_revision}:{})});
  const cfg={channel:url.searchParams.get('channel'),nonce,kind:item.source_kind,title:item.title,ownerKey:'1',agentId:item.agent_id,conversationId:item.conversation_id,
    binding:scope,target:item.target,csrf:'f'.repeat(64),emailEndpoint:endpoint('email'),imageEndpoint:endpoint('image'),canvasEndpoint:endpoint('canvas'),mediaEndpoint:endpoint('media'),connectionsUrl:'/connections.php',basePath:'',
    callSettingsEndpoint:'/api/phone-location-v1.php?workspace_calls=1',deviceId:device.id,enrollmentRevision,itemRef:item.item_ref};
  const assets=nativeAssets.map(file=>file.endsWith('.css')?`<link rel="stylesheet" href="/${file}">`:`<script src="/${file}" defer></script>`).join('');
  const body=`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><script id="phoneAgentItemData" type="application/json" nonce="${nonce}">${JSON.stringify(cfg).replaceAll('<','\\u003c')}</script>${assets}</head><body><main id="phoneAgentItem"><p id="phoneAgentItemStatus" role="status">Opening your private item…</p><div id="chatThread" data-view="email"></div><section id="phoneAgentItemContent" hidden></section></main></body></html>`;
  const csp=hostSource.match(/header\("Content-Security-Policy: ([^"]+)"\)/)?.[1]?.replaceAll('$nonce',nonce);
  return {body,csp};
}
await page.route('https://phone-agent.test/**',async route=>{
  const url=new URL(route.request().url()),pathname=url.pathname;
  const ok=result=>route.fulfill({json:{ok:true,...result}}).catch(()=>{});
  if(pathname==='/phone-location.php')return route.fulfill({contentType:'text/html',body:html,headers:parentCsp?{'content-security-policy':parentCsp}:{}});
  if(/^\/[a-z0-9-]+\.(js|css)$/.test(pathname)){
    try{return route.fulfill({contentType:pathname.endsWith('.js')?'text/javascript':'text/css',body:read(pathname.slice(1))});}catch{return route.fulfill({status:404});}
  }
  if(pathname==='/media-library-file-v86.php')return route.fulfill({contentType:'image/png',body:png});
  if(pathname==='/chat.php'){navigation.push(url.href);return route.fulfill({contentType:'text/html',body:'<h1>Original private Chat</h1>'});}
  if(pathname!=='/api/phone-location-v1.php')return route.fulfill({status:404});
  if(url.searchParams.has('workspace_view')){
    const item=[...rows,other].find(item=>item.item_ref===url.searchParams.get('item_ref'));assert.ok(item);
    assert.equal(url.searchParams.get('device_id'),device.id);assert.equal(url.searchParams.get('scope_revision'),scopeRevision());assert.equal(url.searchParams.get('source_revision'),item.source_revision);
    const view=nativeHtml(url,item);return route.fulfill({contentType:'text/html',body:view.body,headers:view.csp?{'content-security-policy':view.csp}:{}});
  }
  if(url.searchParams.has('workspace_media')&&route.request().method()==='GET'){
    assert.equal(url.searchParams.get('action'),'browse');assert.equal(route.request().headers()['x-vp3-media-owner'],'1');
    return ok({assets:[{id:11,title:'Attachment fixture',mime_type:'image/png',size:png.length,available:true,url:'/media-library-file-v86.php?id=11'}],next_before:null});
  }
  const input=route.request().postDataJSON();
  if(url.searchParams.has('workspace_calls')){
    emailCallRequests.push({...input});assert.equal(route.request().headers()['x-vp3-phone'],'1');assert.equal(input.owner_key,undefined,'The Phone bridge derives its owner from authenticated enrollment.');
    if(!signedIn||!enrolled)return route.fulfill({status:403,json:{ok:false,error:'owner_access_changed',message:'Sign in again to check email calls.'}}).catch(()=>{});
    guard(input);assert.equal(input.agent_id,selectedAgent);assert.equal(input.conversation_id,selectedAgent?77:42);
    if(input.action==='save'){
      assert.equal(input.expected_revision,emailCallPolicy.revision);assert.equal(input.selected_device_id,device.id);assert.equal(input.number_id,8);
      if(input.urgent_enabled||input.drafts_enabled)assert.equal(input.confirmed,true);
      emailCallPolicy={revision:emailCallPolicy.revision+1,urgent_enabled:input.urgent_enabled,drafts_enabled:input.drafts_enabled,grace_seconds:input.grace_seconds,device_id:input.selected_device_id,number_id:input.number_id};
      return ok(emailCallSettings());
    }
    if(input.action==='state')return ok(emailCallSettings());
    if(input.action==='voice_arm'){
      if(!emailActiveCall)return route.fulfill({status:409,json:{ok:false,error:'voice_review_unavailable',message:'This saved email changed. Voice approval is no longer available for the original call; review the current email and use Send.'}});
      assert.equal(input.request_id,emailActiveCall.request_id);assert.equal(input.draft_id,draft.id);assert.equal(input.review_token,draft.review_token);
      assert.equal(input.watch_replies,false);assert.equal(input.confirmed,true);
      voiceRequested=false;voiceReview={id:id(950+(++voiceSequence)),state:'armed',revision:1,expires_at:new Date(Date.now()+voiceExpiryMs).toISOString()};
      const result=structuredClone({scope:emailCallScope(),review:voiceReview,challenge:'send email 1 2 3 4 5 6'});
      if(heldVoiceArm){const gate=heldVoiceArm;heldVoiceArm=null;gate.start();await gate.promise;await ok(result);gate.finish();return;}return ok(result);
    }
    if(['voice_poll','voice_claim','voice_cancel'].includes(input.action)){
      assert.equal(input.review_id,voiceReview?.id);
      if(input.action==='voice_poll'&&voiceRequested&&voiceReview.state==='armed')voiceReview={...voiceReview,state:'requested',revision:voiceReview.revision+1};
      if(input.action==='voice_claim'){
        assert.equal(voiceReview.state,'requested','Only the exact requested challenge can be claimed.');assert.equal(input.review_token,draft.review_token);
        voiceReview={...voiceReview,state:'claimed',revision:voiceReview.revision+1};
      }
      if(input.action==='voice_cancel'&&['armed','requested'].includes(voiceReview.state))voiceReview={...voiceReview,state:'cancelled',revision:voiceReview.revision+1};
      const result=structuredClone({scope:emailCallScope(),review:voiceReview});
      const gate=input.action==='voice_poll'?heldVoicePoll:input.action==='voice_claim'?heldVoiceClaim:null;
      if(gate){if(input.action==='voice_poll')heldVoicePoll=null;else heldVoiceClaim=null;gate.start();await gate.promise;await ok(result);gate.finish();return;}return ok(result);
    }
    assert.equal(input.action,'statuses');assert.ok(Array.isArray(input.item_refs)&&input.item_refs.length<=40);
    return ok({scope:emailCallScope(),items:input.item_refs.map(item_ref=>({item_ref,call:item_ref===emailCallItem?emailCall:null})),active_call:emailActiveCall});
  }
  if(url.searchParams.has('workspace')){
    requests.push({...input});
    if(input.action==='status')return ok(workspaceStatus());
    if(!signedIn)return route.fulfill({status:401,json:{ok:false,error:'owner_sign_in_required'}});
    if(input.action==='enroll'){
      assert.equal(input.confirmed,true);assert.equal(input.expected_revision,enrollmentRevision);assert.equal(input.csrf_token,'f'.repeat(64));
      enrolled=true;selectedAgent=input.agent_id;enrollmentRevision++;return ok(workspaceStatus());
    }
    guard(input);
    if(['select_agent','preferences','revoke'].includes(input.action)){
      assert.equal(input.expected_revision,enrollmentRevision);if(input.action==='select_agent')selectedAgent=input.agent_id;
      if(input.action==='revoke')enrolled=false;enrollmentRevision++;return ok(workspaceStatus());
    }
    if(input.action==='feed'){
      let items=inventory();
      if(input.filter==='needs_you')items=items.filter(item=>item.needs_action&&!item.receipt.acknowledged_at);
      if(input.filter==='for_you')items=items.filter(item=>!item.needs_action&&!item.receipt.acknowledged_at&&item.state!=='completed');
      if(input.filter==='history')items=items.filter(item=>item.receipt.acknowledged_at||item.state==='completed');
      const start=input.cursor?Number(input.cursor.slice(5)):0,part=items.slice(start,start+input.limit),next=start+part.length;
      const result=structuredClone({...envelope(),items:part,counts:counts(),has_more:next<items.length,next_cursor:next<items.length?'page-'+next:null});
      if(heldFeed){const gate=heldFeed;heldFeed=null;gate.start();await gate.promise;await ok(result);gate.finish();return;}return ok(result);
    }
    const item=inventory().find(item=>item.item_ref===input.item_ref);
    if(!item||blockedItem===input.item_ref)return route.fulfill({status:404,json:{ok:false,error:'item_unavailable'}});
    if(input.action==='detail')return ok({...envelope(),item,detail:item.source_kind==='agent_result'?{kind:'agent_result',text:'A useful result from the Agent.\n\n'+('This saved project update stays in its original private Chat.\n\n').repeat(25),truncated:false}:{kind:item.source_kind,target:item.target}});
    if(input.action==='mutate'){
      assert.equal(input.expected_revision,item.revision);assert.ok(['read','ack'].includes(input.operation));item.revision++;item.state_version++;
      item.receipt[input.operation==='read'?'read_at':'acknowledged_at']='2026-11-27T10:05:00Z';return ok({...envelope(),item});
    }
    if(input.action==='discuss')return ok({...envelope(),item,target:{kind:'chat',agent_id:item.agent_id,conversation_id:item.conversation_id}});
    throw Error('Unexpected workspace action '+input.action);
  }
  if(url.searchParams.has('workspace_email')){
    emailRequests.push({...input});assert.equal(input.agent_id,0);assert.equal(input.conversation_id,42);assert.equal(input.owner_key,'1');
    if(input.action==='attention_open'){
      assert.equal(url.searchParams.get('source_revision'),rows[4].source_revision);
      assert.equal(input.item_ref,hash(700));assert.equal(input.generation,2);
      return ok({message:{subject:'Account review requested',from:'Alex <alex@example.test>',body:'Please review the account update before Friday.',
        display:{version:1,html:'<!doctype html><html><head><style>body{font:16px system-ui;background:#eff7f3;color:#174e46;padding:24px}h2{font-size:26px}td{padding:12px;border-bottom:1px solid #b9d8cb}</style></head><body><h2>Account review</h2><p>Please review the saved account update before Friday.</p><table><tr><td>Requested by</td><td>Alex</td></tr><tr><td>Next step</td><td>Review the details</td></tr></table></body></html>',remote_images:0,truncated:false}}});
    }
    assert.equal(input.draft_id,draft.id);
    if(input.action==='review'&&heldReview){const gate=heldReview;heldReview=null;gate.start();await gate.promise;}
    if(input.action==='compose_save'){
      if(denySave){denySave=false;signedIn=false;return route.fulfill({status:403,json:{ok:false,error:'Workspace access changed.'}});}
      assert.equal(input.review_token,draft.review_token);Object.assign(draft,{to:input.to,subject:input.subject,body:input.body});draftRevision++;
      Object.assign(draft,{review_token:'review-'+draftRevision,watch_review_token:'watch-review-'+draftRevision,edit_revision:hash(draftRevision),saved_at:'2026-11-27T10:05:00Z'});sourceChanged();
    }else if(input.action==='confirm'){
      assert.equal(input.confirmed,true);assert.equal(input.review_token,draft.review_token);draft.status='sent';draft.can_send=false;draft.can_edit=false;draft.review_token=null;
      const voiceId=url.searchParams.get('voice_review');if(voiceId){assert.equal(voiceId,voiceReview?.id);assert.equal(voiceReview.state,'claimed');voiceConfirmQueries.push(voiceId);voiceReview={...voiceReview,state:'completed',revision:voiceReview.revision+1};}
      draftRevision++;sourceChanged();draftRow().state='completed';draftRow().needs_action=false;
    }else assert.equal(input.action,'review');
    return ok({draft});
  }
  if(url.searchParams.has('workspace_image')){
    assert.equal(url.searchParams.get('source_revision'),rows[2].source_revision);
    nativeRequests.push({...input});assert.equal(input.action,'get');assert.equal(input.generation_id,imageId);
    return ok({generation:{id:imageId,status:'completed',title:'A saved color study',assets:[{id:1,title:'Color study',mime_type:'image/png',available:true,url:'/media-library-file-v86.php?id=1'}]}});
  }
  if(url.searchParams.has('workspace_canvas')){
    assert.equal(url.searchParams.get('source_revision'),rows[3].source_revision);
    nativeRequests.push({...input});assert.equal(input.action,'open');assert.equal(input.canvas_id,canvasId);assert.equal(input.revision,1);
    return ok({canvas:{id:canvasId,title:'A little creative break',active_revision:1},state:{revision:0,value:{}},command_grants:{app_data:{app_access:false}},email_source:false,
      revision:{id:1,commands:[],artifact:{html:'<section><h1>A little creative break</h1><p id="result">Ready to play</p><button id="spark">Try a spark</button></section>',
        css:'body{background:#eff7f3;color:#174e46}section{padding:28px;font:18px system-ui}button{font:inherit;padding:14px;border-radius:12px;border:0;background:#0a665b;color:white}',
        js:'CanvasUI.on("#spark","click",()=>CanvasUI.text("#result","A fresh idea is taking shape."));'}}});
  }
  switch(input.action){
    case 'status':return ok({device,owner_name:'Owner'});
    case 'camera_status':return ok({camera:null});
    case 'chat_status':return ok({turns:[]});
    case 'push_resolve':return ok({device,owner_name:'Owner',event_ref:input.event_ref,device_id:device.id,subscription_revision:1,state:'accepted',
      created_epoch:Math.floor(Date.now()/1000)-10,expires_epoch:Math.floor(Date.now()/1000)+600,provider_accepted_epoch:Math.floor(Date.now()/1000)-5,
      client_observed_epoch:0,opened_epoch:Math.floor(Date.now()/1000),target:{tab:'agent',item_ref:id(2)}});
    default:return route.fulfill({status:400,json:{ok:false}});
  }
});
const agent=()=>page.locator('#phone-agent'),card=ref=>agent().locator(`[data-item="${ref}"]`),nativeFrame=()=>page.frameLocator('.phone-agent-native-frame');
const back=()=>agent().getByRole('button',{name:'← Back to results',exact:true});
async function screenshot(name){const dir=process.env.VP3_PHONE_AGENT_SCREENSHOT_DIR;if(!dir)return;mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,name),fullPage:true});}
async function bounds(native=false){
  const geometry=await page.evaluate(()=>({width:innerWidth,body:document.documentElement.scrollWidth,height:innerHeight,
    bottom:document.getElementById('phone-location').getBoundingClientRect().bottom,
    nested:[...document.querySelectorAll('#phone-agent *')].filter(node=>{const style=getComputedStyle(node);return !node.hidden&&['auto','scroll'].includes(style.overflowY)&&node.scrollHeight>node.clientHeight+2;}).map(node=>node.className)}));
  assert.ok(geometry.body<=geometry.width+1,'Phone page has no horizontal overflow.');assert.ok(geometry.bottom<=geometry.height+2,'The phone shell remains within its viewport.');
  assert.deepEqual(geometry.nested,[],'There are no nested parent scroll panes.');
  if(native)assert.equal(await agent().evaluate(node=>getComputedStyle(node).overflowY),'hidden','Only the native child scrolls while viewing an item.');
}
async function wake(){await page.evaluate(()=>window.dispatchEvent(new Event('pageshow')));}
try{
  await page.goto('https://phone-agent.test/phone-location.php');
  await page.getByRole('link',{name:'Sign in to your account'}).waitFor();
  assert.equal(await page.locator('#phone-tab-agent').isVisible(),false,'A role=owner pairing does not grant private Agent access.');
  assert.ok(requests.every(input=>input.action==='status'),'An unauthenticated holder never requests feed or detail.');
  assert.match(await page.getByRole('link',{name:'Sign in to your account'}).getAttribute('href'),/login\.php\?return_to=%2Fphone-location\.php/);
  signedIn=true;await page.getByRole('button',{name:'Check sign-in'}).click();
  await page.getByRole('button',{name:'Connect my Agent'}).waitFor();
  assert.equal(await page.getByRole('button',{name:'Connect my Agent'}).isDisabled(),true);
  await screenshot('owner-enrollment-phone.png');
  await page.getByRole('checkbox',{name:/This is my phone/}).check();await page.getByRole('button',{name:'Connect my Agent'}).click();
  await card(id(1)).waitFor();assert.equal(await page.locator('#phone-tab-agent').getAttribute('aria-selected'),'true');
  assert.equal(await page.locator('#phone-chat-form').isVisible(),false,'Agent has no guest-holder composer.');
  assert.equal(await agent().locator('article').count(),10);await bounds();await screenshot('agent-feed-phone.png');
  await agent().getByRole('button',{name:'Settings',exact:true}).click();
  const emailCalls=page.locator('#phone-agent-enrollment').getByRole('form',{name:'Email calls'});
  await emailCalls.getByText('Email calls are off.',{exact:true}).waitFor();assert.equal(await emailCalls.getByLabel('Grace period (minutes)',{exact:true}).inputValue(),'5');
  await emailCalls.getByLabel('Urgent incoming email',{exact:true}).check();await emailCalls.getByLabel('Agent-prepared email drafts ready for review',{exact:true}).check();
  await emailCalls.getByRole('checkbox',{name:'Allow recorded and transcribed AI calls about these email items to my connected phone.',exact:true}).check();
  await emailCalls.getByRole('button',{name:'Save email calls',exact:true}).click();await emailCalls.getByText('Email call choices saved. New eligible work will use these choices.',{exact:true}).waitFor();
  assert.equal(emailCallPolicy.urgent_enabled,true);assert.equal(emailCallPolicy.drafts_enabled,true);assert.equal(emailCallPolicy.grace_seconds,300);
  await emailCalls.getByLabel('Grace period (minutes)',{exact:true}).fill('6');await emailCalls.getByLabel('Grace period (minutes)',{exact:true}).focus();
  const heldHeading=await page.locator('#phone-agent-enrollment-title').elementHandle(),heldForm=await emailCalls.elementHandle();await wake();
  await page.waitForFunction(previous=>!previous.isConnected,heldHeading);
  assert.equal(await heldForm.evaluate(element=>element.isConnected),true,'An incidental owner refresh keeps the same settings form.');
  assert.equal(await emailCalls.getByLabel('Grace period (minutes)',{exact:true}).inputValue(),'6');
  assert.equal(await emailCalls.getByLabel('Grace period (minutes)',{exact:true}).evaluate(element=>document.activeElement===element),true,'Readiness refresh preserves the editing focus.');
  assert.equal(emailCallRequests.filter(input=>input.action==='save').length,1,'Periodic reads do not re-save consent.');
  await emailCalls.getByLabel('Grace period (minutes)',{exact:true}).fill('5');
  await emailCalls.getByRole('button',{name:'Save email calls',exact:true}).scrollIntoViewIfNeeded();await screenshot('agent-email-call-settings-phone.png');
  const connectionGeometry=await page.locator('#phone-connection').evaluate(panel=>({width:innerWidth,body:document.documentElement.scrollWidth,
    nested:[...panel.querySelectorAll('*')].filter(node=>{const style=getComputedStyle(node);return ['auto','scroll'].includes(style.overflowY)&&node.scrollHeight>node.clientHeight+2;}).map(node=>node.className)}));
  assert.ok(connectionGeometry.body<=connectionGeometry.width+1);assert.deepEqual(connectionGeometry.nested,[],'Phone Connection remains the only scrolling settings surface.');
  await page.getByRole('button',{name:'Open Agent',exact:true}).click();await card(id(1)).waitFor();
  await agent().getByRole('button',{name:'Load more',exact:true}).click();await card(id(12)).waitFor();assert.equal(await agent().locator('article').count(),12);
  await agent().getByRole('button',{name:'Needs you',exact:true}).click();await page.waitForFunction(()=>document.querySelectorAll('#phone-agent article').length===2);assert.equal(await agent().locator('article').count(),2);
  await card(id(1)).getByRole('button',{name:'Review email'}).waitFor();
  await agent().getByRole('button',{name:'History',exact:true}).click();await agent().getByText('No completed or acknowledged items in this view yet.').waitFor();
  await agent().getByRole('button',{name:'For you',exact:true}).click();await card(id(2)).waitFor();assert.equal(await card(id(1)).count(),0);
  await card(id(2)).getByRole('button',{name:'Read result'}).click();await agent().getByRole('heading',{name:'Your project brief is ready'}).waitFor();
  assert.equal(requests.filter(input=>input.action==='mutate').length,0,'Opening a result does not implicitly mark it read or approve anything.');
  await bounds();await screenshot('agent-result-phone.png');await agent().getByRole('button',{name:'Mark read',exact:true}).click();
  await agent().getByText('Marked read. Any requested approval is still pending.').waitFor();assert.equal(emailRequests.length,0);
  await back().click();await agent().getByRole('button',{name:'All',exact:true}).click();await card(id(1)).waitFor();

  heldReview=hold();const reviewGate=heldReview;await card(id(1)).getByRole('button',{name:'Review email'}).click();await reviewGate.started;
  const forged=await page.locator('.phone-agent-native-frame').getAttribute('src');
  await page.evaluate(channel=>window.dispatchEvent(new MessageEvent('message',{source:window,origin:location.origin,data:{channel,type:'ready'}})),new URL(forged).searchParams.get('channel'));
  assert.equal(await page.locator('.phone-agent-native-status').count(),1,'A forged message from the parent cannot complete the private iframe handshake.');
  reviewGate.release();await nativeFrame().getByLabel('Message',{exact:true}).waitFor();await page.waitForFunction(()=>!document.querySelector('.phone-agent-native-status'));
  assert.equal(await nativeFrame().getByRole('button',{name:'Compose another new email'}).isVisible(),false,'A phone work item is fixed to its exact reviewed draft.');
  const original=draft.body;await nativeFrame().getByLabel('Message',{exact:true}).fill(original+'\nUnsaved phone edits.');
  const iframeBeforeCall=await page.locator('.phone-agent-native-frame').getAttribute('src'),reviewCount=emailRequests.filter(input=>input.action==='review').length;
  emailCall={request_id:id(901),revision:2,state:'submitted',reason:'requested',call_id:id(902),updated_at:time};
  emailActiveCall={request_id:id(901),item_ref:id(1),revision:2,state:'submitted',call_id:id(902),source_kind:'gmail_draft'};
  await wake();await agent().getByText('Email call requested · This is the exact email linked to the call.',{exact:true}).waitFor();
  assert.equal(await page.locator('.phone-agent-native-frame').getAttribute('src'),iframeBeforeCall,'Call progress cannot remount a native email view.');
  assert.equal(await nativeFrame().getByLabel('Message',{exact:true}).inputValue(),original+'\nUnsaved phone edits.');
  assert.equal(emailRequests.filter(input=>input.action==='review').length,reviewCount,'Call polling does not reload canonical email review.');
  assert.equal(emailRequests.filter(input=>input.action==='confirm').length,0,'A call status cannot approve an email.');await bounds(true);await screenshot('agent-email-call-review-phone.png');
  await back().click();
  await nativeFrame().getByText('Save your changes before leaving this email?').waitFor();
  await page.evaluate(channel=>window.dispatchEvent(new MessageEvent('message',{source:window,origin:location.origin,data:{channel,type:'close_result',id:1,allowed:true}})),new URL(forged).searchParams.get('channel'));
  assert.equal(await page.locator('.phone-agent-native-frame').count(),1,'A forged close result cannot discard edits.');
  await nativeFrame().getByRole('button',{name:'Keep editing',exact:true}).click();assert.equal(await nativeFrame().getByLabel('Message',{exact:true}).inputValue(),original+'\nUnsaved phone edits.');
  await page.getByRole('tab',{name:'Connection',exact:true}).click();await nativeFrame().getByRole('button',{name:'Discard edits',exact:true}).click();
  await page.waitForFunction(()=>document.getElementById('phone-tab-connection').getAttribute('aria-selected')==='true');assert.equal(draft.body,original);
  await page.locator('#phone-tab-agent').click();await card(id(1)).waitFor();assert.equal(await page.locator('.phone-agent-native-frame').count(),0,'Returning from a cleanly closed native view shows results, not a released blank composer.');
  await agent().getByRole('button',{name:'Review email for this call',exact:true}).click();await nativeFrame().getByLabel('Message',{exact:true}).waitFor();
  assert.equal(new URL(await page.locator('.phone-agent-native-frame').getAttribute('src')).searchParams.get('item_ref'),id(1),'The call banner opens its exact current email item.');
  emailCall={...emailCall,revision:3,state:'ended',reason:'completed'};emailActiveCall=null;await wake();await agent().locator('[data-email-call-detail]').filter({hasText:/^Email call ended$/}).waitFor();
  await nativeFrame().getByLabel('Message',{exact:true}).fill(original+'\nSaved on the phone.');await back().click();
  await nativeFrame().getByRole('button',{name:'Save draft and leave',exact:true}).click();await card(id(1)).waitFor();assert.match(draft.body,/Saved on the phone/);assert.equal(emailRequests.filter(input=>input.action==='confirm').length,0);
  const savedBeforeDenial=draft.body;await card(id(1)).getByRole('button',{name:'Review email'}).click();await nativeFrame().getByLabel('Message',{exact:true}).fill('These unsaved edits must clear when access is lost.');await back().click();
  denySave=true;await nativeFrame().getByRole('button',{name:'Save draft and leave',exact:true}).click();await page.getByRole('link',{name:'Sign in to your account'}).waitFor();
  assert.equal(await page.locator('.phone-agent-native-frame').count(),0,'Denied dirty Save-and-leave clears the private native view.');assert.equal(draft.body,savedBeforeDenial);assert.equal(emailRequests.filter(input=>input.action==='confirm').length,0);
  signedIn=true;await page.getByRole('button',{name:'Check sign-in'}).click();await page.getByRole('button',{name:'Open Agent',exact:true}).click();await card(id(1)).waitFor();
  await card(id(1)).getByRole('button',{name:'Review email'}).click();await nativeFrame().getByLabel('Message',{exact:true}).waitFor();
  await nativeFrame().getByRole('button',{name:'Send',exact:true}).scrollIntoViewIfNeeded();await bounds(true);await screenshot('agent-email-phone.png');
  await nativeFrame().getByRole('button',{name:'Send',exact:true}).click();await nativeFrame().getByText('Gmail confirmed your email was sent.',{exact:true}).waitFor();
  assert.equal(emailRequests.filter(input=>input.action==='confirm').length,1);assert.equal(emailRequests.at(-2).action,'compose_save','Send saves the exact reviewed content before canonical confirmation.');
  assert.equal(rows[0].needs_action,false);assert.equal(await page.locator('.phone-agent-native-frame').count(),1,'A locally saved/sent source updates without remounting its native view.');
  await wake();await nativeFrame().getByLabel('Subject',{exact:true}).waitFor();await back().click();await card(id(3)).waitFor();

  // A separate saved review exercises the actual trusted child/composer voice bridge.
  draftRevision++;Object.assign(draft,{status:'prepared',can_edit:true,can_send:true,review_token:'review-'+draftRevision,watch_review_token:'watch-review-'+draftRevision,edit_revision:hash(draftRevision),
    subject:'A second exact review',body:'This saved email is ready for the call review.'});sourceChanged();rows[0].state='needs_review';rows[0].needs_action=true;
  emailCall={request_id:id(903),revision:1,state:'submitted',reason:'requested',call_id:id(904),updated_at:time};
  emailActiveCall={request_id:id(903),item_ref:id(1),revision:1,state:'submitted',call_id:id(904),source_kind:'gmail_draft'};
  await card(id(1)).getByRole('button',{name:'Review email'}).click();const voiceEnable=()=>nativeFrame().getByRole('button',{name:'Enable voice approval',exact:true});
  const voicePhrase=()=>nativeFrame().getByText('Say: “send email 1 2 3 4 5 6”',{exact:true});
  const cancelResponse=(timeout=10000)=>page.waitForResponse(response=>response.url().includes('workspace_calls=1')&&response.request().postDataJSON()?.action==='voice_cancel',{timeout});
  const noVoiceSend=()=>{assert.equal(voiceConfirmQueries.length,0);assert.equal(emailRequests.filter(input=>input.action==='confirm').length,1);};
  const readyToRearm=()=>page.waitForFunction(()=>document.querySelector('.phone-agent-native-frame')?.contentDocument?.querySelector('.phone-agent-call-review button')?.disabled===false);

  heldVoiceArm=hold();const oldArm=heldVoiceArm;await voiceEnable().click();await oldArm.started;
  assert.equal(await nativeFrame().getByRole('button',{name:'Cancel voice approval',exact:true}).isEnabled(),true,'Cancel remains available while arming is pending.');
  await nativeFrame().getByLabel('Message',{exact:true}).fill(draft.body+' Temporary arm edit.');await nativeFrame().getByLabel('Message',{exact:true}).fill(draft.body);
  const cancelledArm=cancelResponse();oldArm.release();await oldArm.finished;await cancelledArm;
  await nativeFrame().getByText('The email changed. Voice approval was cancelled.',{exact:true}).waitFor();await readyToRearm();assert.equal(voiceReview.state,'cancelled');
  assert.equal(await voicePhrase().isVisible(),false,'A delayed arm cannot restore a challenge after an edit and revert.');noVoiceSend();

  await voiceEnable().click();await voicePhrase().waitFor();heldVoicePoll=hold();const cancelledPendingPoll=heldVoicePoll;voiceRequested=true;await cancelledPendingPoll.started;
  const explicitCancellation=cancelResponse();const cancelVoice=nativeFrame().getByRole('button',{name:'Cancel voice approval',exact:true});
  assert.equal(await cancelVoice.isEnabled(),true,'A pending poll cannot disable explicit cancellation.');await cancelVoice.click();await explicitCancellation;
  await nativeFrame().getByText('Voice approval cancelled. You can keep editing or use Send.',{exact:true}).waitFor();assert.equal(await voicePhrase().isVisible(),false);
  cancelledPendingPoll.release();await cancelledPendingPoll.finished;await readyToRearm();noVoiceSend();

  // A response may arrive with a short remaining lifetime. Expiry must not wait for the next network read.
  voiceExpiryMs=9000;await voiceEnable().click();await voicePhrase().waitFor();voiceExpiryMs=120000;
  heldVoicePoll=hold();const expiredPendingPoll=heldVoicePoll;await expiredPendingPoll.started;const expiryCancellation=cancelResponse(15000);
  await nativeFrame().getByText('Voice approval expired. Review the email before enabling it again.',{exact:true}).waitFor({timeout:15000});await expiryCancellation;
  assert.equal(await voicePhrase().isVisible(),false,'Local expiry removes the phrase while the poll is unresolved.');assert.equal(voiceReview.state,'cancelled');noVoiceSend();
  expiredPendingPoll.release();await expiredPendingPoll.finished;await readyToRearm();

  await voiceEnable().click();await voicePhrase().waitFor();heldVoicePoll=hold();const stalledPoll=heldVoicePoll;await stalledPoll.started;
  const timeoutCancellation=cancelResponse(20000);
  await nativeFrame().getByText('Call approval did not respond in time. Review the email before enabling voice approval again.',{exact:true}).waitFor({timeout:20000});await timeoutCancellation;
  assert.equal(await voicePhrase().isVisible(),false,'A timed-out call request clears its challenge and never sends.');noVoiceSend();
  stalledPoll.release();await stalledPoll.finished;await readyToRearm();

  await voiceEnable().click();await voicePhrase().waitFor();heldVoicePoll=hold();const oldPoll=heldVoicePoll;voiceRequested=true;await oldPoll.started;
  const cancelledPoll=cancelResponse();await nativeFrame().getByLabel('Message',{exact:true}).fill(draft.body+' Temporary poll edit.');await nativeFrame().getByLabel('Message',{exact:true}).fill(draft.body);await cancelledPoll;
  oldPoll.release();await oldPoll.finished;await nativeFrame().getByText('The email changed. Voice approval was cancelled.',{exact:true}).waitFor();await readyToRearm();
  assert.equal(emailCallRequests.filter(input=>input.action==='voice_claim').length,0,'An old requested poll cannot claim an edited-then-restored review.');noVoiceSend();

  await voiceEnable().click();await voicePhrase().waitFor();heldVoicePoll=hold();const oldWatchPoll=heldVoicePoll;voiceRequested=true;await oldWatchPoll.started;
  const cancelledWatch=cancelResponse();const watch=nativeFrame().getByLabel('Watch for the first reply for 30 days',{exact:true});await watch.check();await watch.uncheck();await cancelledWatch;
  oldWatchPoll.release();await oldWatchPoll.finished;await nativeFrame().getByText('The email changed. Voice approval was cancelled.',{exact:true}).waitFor();await readyToRearm();
  assert.equal(emailCallRequests.filter(input=>input.action==='voice_claim').length,0,'Changing a reply-watch choice and restoring it cannot revive approval.');noVoiceSend();

  await voiceEnable().click();await voicePhrase().waitFor();heldVoiceClaim=hold();const oldClaim=heldVoiceClaim;voiceRequested=true;await oldClaim.started;
  await nativeFrame().getByRole('button',{name:'Saved images',exact:true}).click();
  const cancelledAttachment=cancelResponse();await nativeFrame().getByRole('button',{name:'Attach Attachment fixture',exact:true}).click();
  await nativeFrame().locator('.vp3-email-compose-attachments').getByRole('button',{name:'Remove Attachment fixture',exact:true}).click();await cancelledAttachment;
  oldClaim.release();await oldClaim.finished;await nativeFrame().getByText('The email changed. Voice approval was cancelled.',{exact:true}).waitFor();await readyToRearm();
  assert.equal(emailCallRequests.filter(input=>input.action==='voice_claim').length,1);assert.equal(voiceReview.state,'claimed');
  assert.equal(await nativeFrame().locator('.vp3-email-compose-attachments figure').count(),0);noVoiceSend();

  await voiceEnable().click();await nativeFrame().getByText('Say: “send email 1 2 3 4 5 6”',{exact:true}).waitFor();
  const cancellation=page.waitForResponse(response=>response.url().includes('workspace_calls=1')&&response.request().postDataJSON()?.action==='voice_cancel');
  await nativeFrame().getByLabel('Message',{exact:true}).fill(draft.body+' A corrected detail.');await cancellation;
  await nativeFrame().getByText('The email changed. Voice approval was cancelled.',{exact:true}).waitFor();assert.equal(voiceReview.state,'cancelled');
  assert.equal(await voiceEnable().isDisabled(),true,'Unsaved edits cannot keep the previous voice grant enabled.');
  await nativeFrame().getByRole('button',{name:'Save draft',exact:true}).click();await nativeFrame().getByText('Draft saved in this Chat.',{exact:true}).waitFor();
  assert.equal(emailActiveCall,null,'A changed canonical saved source is no longer eligible for its original call.');
  await page.waitForFunction(()=>document.querySelector('.phone-agent-native-frame')?.contentDocument?.querySelector('.phone-agent-call-review button')?.disabled===true);
  assert.equal(await nativeFrame().getByRole('button',{name:'Send',exact:true}).isEnabled(),true,'A revised saved email still supports ordinary manual Send.');
  await back().click();await card(id(3)).waitFor();

  // The successful voice send uses a separate immutable draft/call episode.
  rows.push(row(13,'gmail_draft','A fresh exact call review',{summary:'A separate saved email for a separate call.',state:'needs_review',needs_action:true,priority:'important',
    target:{kind:'gmail_draft',agent_id:0,conversation_id:42,draft_id:id(102)}}));
  draftRevision++;Object.assign(draft,{id:id(102),subject:'Fresh exact call review',body:'This separate saved email is ready for approval.',attachments:[],watch_requested:false,
    review_token:'review-'+draftRevision,watch_review_token:'watch-review-'+draftRevision,edit_revision:hash(draftRevision)});sourceChanged();emailCallItem=id(13);
  emailCall={request_id:id(905),revision:1,state:'submitted',reason:'requested',call_id:id(906),updated_at:time};
  emailActiveCall={request_id:id(905),item_ref:id(13),revision:1,state:'submitted',call_id:id(906),source_kind:'gmail_draft'};
  await agent().getByRole('button',{name:'Refresh',exact:true}).click();
  await page.waitForFunction(ref=>{try{return JSON.parse(document.querySelector('.phone-agent-email-call-banner')?.dataset.signature||'null')?.item_ref===ref;}catch{return false;}},id(13));
  await agent().getByRole('button',{name:'Review email for this call',exact:true}).click();
  await nativeFrame().getByLabel('Subject',{exact:true}).waitFor();assert.equal(await nativeFrame().getByLabel('Subject',{exact:true}).inputValue(),'Fresh exact call review');
  const claimsBeforeSuccess=emailCallRequests.filter(input=>input.action==='voice_claim').length;
  const armedPoll=page.waitForResponse(response=>response.url().includes('workspace_calls=1')&&response.request().postDataJSON()?.action==='voice_poll');
  await voiceEnable().click();await nativeFrame().getByText('Say: “send email 1 2 3 4 5 6”',{exact:true}).waitFor();await armedPoll;
  assert.equal(voiceReview.state,'armed');assert.equal(emailRequests.filter(input=>input.action==='confirm').length,1,'An armed review cannot send without a server-accepted exact challenge.');
  assert.equal(emailCallRequests.filter(input=>input.action==='voice_claim').length,claimsBeforeSuccess);
  await nativeFrame().getByRole('button',{name:'Send',exact:true}).scrollIntoViewIfNeeded();
  assert.equal(await nativeFrame().getByRole('button',{name:'Send',exact:true}).evaluate(button=>{const box=button.getBoundingClientRect();return box.top>=0&&box.bottom<=innerHeight+1;}),true,'The call approval section does not make native Send unreachable.');
  const nativeNested=await nativeFrame().locator('body').evaluate(body=>[...body.querySelectorAll('*')].filter(node=>{
    if(node.tagName==='TEXTAREA')return false;const css=getComputedStyle(node);return ['auto','scroll'].includes(css.overflowY)&&node.scrollHeight>node.clientHeight+2;
  }).map(node=>node.className));assert.deepEqual(nativeNested,[],'The native call review and composer share one document scroll.');
  await nativeFrame().getByRole('heading',{name:'Call approval',exact:true}).scrollIntoViewIfNeeded();await bounds(true);await screenshot('agent-email-voice-approval-phone.png');
  const voiceFrame=await page.locator('.phone-agent-native-frame').getAttribute('src');voiceRequested=true;
  await nativeFrame().getByText('Gmail confirmed your reviewed email was sent.',{exact:true}).waitFor();
  assert.equal(emailCallRequests.filter(input=>input.action==='voice_claim').length,claimsBeforeSuccess+1);assert.equal(emailRequests.filter(input=>input.action==='confirm').length,2);
  assert.deepEqual(voiceConfirmQueries,[voiceReview.id],'The exact claimed review is carried to the canonical confirm endpoint.');
  assert.equal(await page.locator('.phone-agent-native-frame').getAttribute('src'),voiceFrame,'A confirmed voice send updates the same native review.');
  assert.equal(voiceReview.state,'completed');emailCall={...emailCall,state:'ended',reason:'completed',revision:2};emailActiveCall=null;await back().click();await card(id(3)).waitFor();
  await card(id(3)).getByRole('button',{name:'View image'}).click();await nativeFrame().getByRole('img',{name:'Color study'}).waitFor();
  await nativeFrame().getByRole('img',{name:'Color study'}).evaluate(image=>image.decode());await bounds(true);await screenshot('agent-image-phone.png');await back().click();
  await card(id(4)).getByRole('button',{name:'Open canvas'}).click();const canvas=nativeFrame().frameLocator('.phone-agent-canvas-frame');
  await canvas.getByText('Ready to play',{exact:true}).waitFor();assert.equal(await nativeFrame().locator('.phone-agent-canvas-frame').getAttribute('sandbox'),'allow-scripts');
  await canvas.getByRole('button',{name:'Try a spark'}).click();await canvas.getByText('A fresh idea is taking shape.').waitFor();
  await bounds(true);await screenshot('agent-canvas-phone.png');assert.ok(nativeRequests.every(input=>['get','open'].includes(input.action)),'Viewing a saved private canvas does not dispatch generation, source writes, or model calls.');
  await back().click();
  await card(id(5)).getByRole('button',{name:'Read email'}).click();await nativeFrame().getByRole('heading',{name:'Account review requested'}).waitFor();
  const formatted=nativeFrame().frameLocator('.vp3-gmail-email-frame');await formatted.getByRole('heading',{name:'Account review',exact:true}).waitFor();
  assert.ok(!(await nativeFrame().locator('.vp3-gmail-email-frame').getAttribute('sandbox')).includes('allow-scripts'),'Formatted email cannot execute sender scripts.');
  assert.equal(await agent().getByRole('button',{name:'Discuss with your Agent',exact:true}).count(),1,'Mailbox-wide email is not mislabeled as originating in the selected Chat.');
  await bounds(true);await screenshot('agent-important-email-phone.png');await nativeFrame().getByRole('button',{name:'Plain text',exact:true}).click();
  await nativeFrame().getByText('Please review the account update before Friday.',{exact:true}).waitFor();await back().click();

  heldFeed=hold();const oldFeed=heldFeed;await agent().getByRole('button',{name:'Refresh',exact:true}).click();await oldFeed.started;
  await agent().getByRole('button',{name:'Settings',exact:true}).click();await page.getByRole('combobox',{name:'Main Agent for this phone'}).selectOption('7');
  await page.getByRole('button',{name:'Use selected Agent'}).click();await page.getByRole('button',{name:'Open Agent',exact:true}).click();await card(id(50)).waitFor();
  oldFeed.release();await oldFeed.finished;await page.evaluate(()=>{});assert.equal(await card(id(1)).count(),0,'A delayed page from the previous Agent cannot paint in the new scope.');
  await agent().getByRole('button',{name:'Settings',exact:true}).click();await page.getByRole('combobox',{name:'Main Agent for this phone'}).selectOption('0');await page.getByRole('button',{name:'Use selected Agent'}).click();
  await page.getByRole('button',{name:'Open Agent',exact:true}).click();await card(id(3)).waitFor();
  await card(id(3)).getByRole('button',{name:'View image'}).click();await nativeFrame().getByRole('img',{name:'Color study'}).waitFor();
  rows[2].revision++;rows[2].state_version++;rows[2].source_revision=hash(9900);await wake();await card(id(3)).waitFor();
  assert.equal(await page.locator('.phone-agent-native-frame').count(),0,'An externally changed semantic source closes the old private viewer.');
  await page.setViewportSize({width:1200,height:900});await bounds();await screenshot('agent-feed-desktop.png');
  const leaked=await page.evaluate(()=>[...Object.values(localStorage),...Object.values(sessionStorage)].some(value=>value.includes('Proposal ready')||value.includes('Saved on the phone')||value.includes('review-')));
  assert.equal(leaked,false,'Private work content and approval tokens are not cached in web storage.');

  await page.setViewportSize({width:390,height:700});await page.goto('https://phone-agent.test/phone-location.php?push_event='+id(200));
  await agent().getByRole('heading',{name:'Your project brief is ready'}).waitFor();assert.equal(await page.locator('#phone-tab-agent').getAttribute('aria-selected'),'true');
  await back().click();await card(id(2)).getByRole('button',{name:'Read result'}).click();await agent().getByRole('button',{name:'Open original Chat'}).click();
  await page.getByRole('heading',{name:'Original private Chat'}).waitFor();assert.match(navigation.at(-1),/chat\.php\?agent=system&conversation_id=42$/);
  await page.goto('https://phone-agent.test/phone-location.php');await card(id(3)).waitFor();await card(id(3)).getByRole('button',{name:'View image'}).click();await nativeFrame().getByRole('img',{name:'Color study'}).waitFor();
  signedIn=false;await wake();await page.getByRole('link',{name:'Sign in to your account'}).waitFor();
  assert.equal(await page.locator('#phone-tab-agent').isVisible(),false);assert.equal(await page.locator('.phone-agent-native-frame').count(),0);assert.equal(await agent().locator('article').count(),0);
  assert.equal(await page.getByRole('tab',{name:'Chat',exact:true}).isVisible(),true,'Revoking owner workspace authority preserves the holder Chat surface.');
  assert.deepEqual(errors,[]);console.log('Phone Agent browser: enrollment, privacy, explicit email-call settings, stable dirty forms, exact call review without remounting, filters, receipts, actual native bridge, dirty Gmail save/send, image, sandboxed canvas, push, scope races and phone/desktop layouts passed.');
}catch(error){
  try{
    await screenshot('agent-failure.png');
    const view=await page.evaluate(()=>({tab:document.getElementById('phone-location')?.dataset.tab,
      feedback:document.querySelector('.phone-agent-feedback')?.textContent,
      nativeStatus:document.querySelector('.phone-agent-native-frame')?.contentDocument?.getElementById('phoneAgentItemStatus')?.textContent,
      emailNotice:document.querySelector('.phone-agent-native-frame')?.contentDocument?.querySelector('.vp3-email-compose-notice')?.textContent}));
    console.error('Phone Agent fixture diagnostics:',JSON.stringify({view,pageErrors:errors,emailActions:emailRequests.map(input=>input.action),nativeActions:nativeRequests.map(input=>input.action),workspaceActions:requests.slice(-20).map(input=>input.action)}));
  }catch{}
  throw error;
}finally{await browser.close();}
