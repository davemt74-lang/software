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
const requests=[],emailRequests=[],nativeRequests=[],errors=[],navigation=[];
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
  attachments:[],is_reply:false,can_edit:true,can_send:true,review_token:'review-1',edit_revision:hash(1),saved_at:time,watch_requested:false,
  followthrough:{enabled:false,deadline_at:null,time_zone:'UTC',prepare_if_overdue:false,priority:'normal'}};
let draftRevision=1;
const envelope=()=>({ok:true,device_id:device.id,enrollment_revision:enrollmentRevision,scope_revision:scopeRevision()});
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
function sourceChanged(){rows[0].revision++;rows[0].state_version++;rows[0].source_revision=hash(1001+draftRevision);rows[0].receipt=emptyReceipt();}
function nativeHtml(url,item){
  const nonce='a'.repeat(48),scope=scopeRevision();
  const endpoint=kind=>'/api/phone-location-v1.php?'+new URLSearchParams({['workspace_'+kind]:'1',device_id:device.id,item_ref:item.item_ref,scope_revision:scope,
    ...(['image','canvas','gmail_attention'].includes(item.source_kind)?{source_revision:item.source_revision}:{})});
  const cfg={channel:url.searchParams.get('channel'),nonce,kind:item.source_kind,title:item.title,ownerKey:'1',agentId:item.agent_id,conversationId:item.conversation_id,
    binding:scope,target:item.target,csrf:'f'.repeat(64),emailEndpoint:endpoint('email'),imageEndpoint:endpoint('image'),canvasEndpoint:endpoint('canvas'),mediaEndpoint:endpoint('media'),connectionsUrl:'/connections.php',basePath:''};
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
  const input=route.request().postDataJSON();
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
      Object.assign(draft,{review_token:'review-'+draftRevision,edit_revision:hash(draftRevision),saved_at:'2026-11-27T10:05:00Z'});sourceChanged();
    }else if(input.action==='confirm'){
      assert.equal(input.confirmed,true);assert.equal(input.review_token,draft.review_token);draft.status='sent';draft.can_send=false;draft.can_edit=false;draft.review_token=null;
      draftRevision++;sourceChanged();rows[0].state='completed';rows[0].needs_action=false;
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
  const original=draft.body;await nativeFrame().getByLabel('Message',{exact:true}).fill(original+'\nUnsaved phone edits.');await back().click();
  await nativeFrame().getByText('Save your changes before leaving this email?').waitFor();
  await page.evaluate(channel=>window.dispatchEvent(new MessageEvent('message',{source:window,origin:location.origin,data:{channel,type:'close_result',id:1,allowed:true}})),new URL(forged).searchParams.get('channel'));
  assert.equal(await page.locator('.phone-agent-native-frame').count(),1,'A forged close result cannot discard edits.');
  await nativeFrame().getByRole('button',{name:'Keep editing',exact:true}).click();assert.equal(await nativeFrame().getByLabel('Message',{exact:true}).inputValue(),original+'\nUnsaved phone edits.');
  await page.getByRole('tab',{name:'Connection',exact:true}).click();await nativeFrame().getByRole('button',{name:'Discard edits',exact:true}).click();
  await page.waitForFunction(()=>document.getElementById('phone-tab-connection').getAttribute('aria-selected')==='true');assert.equal(draft.body,original);
  await page.locator('#phone-tab-agent').click();await card(id(1)).waitFor();assert.equal(await page.locator('.phone-agent-native-frame').count(),0,'Returning from a cleanly closed native view shows results, not a released blank composer.');
  await card(id(1)).getByRole('button',{name:'Review email'}).click();await nativeFrame().getByLabel('Message',{exact:true}).fill(original+'\nSaved on the phone.');await back().click();
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
  assert.deepEqual(errors,[]);console.log('Phone Agent browser: enrollment, privacy, filters, receipts, actual native bridge, dirty Gmail save/send, image, sandboxed canvas, push, scope races and phone/desktop layouts passed.');
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
