import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const read=path=>fs.readFile(new URL('../'+path,import.meta.url),'utf8');
const [pageSource,script,css,baseCss,cardScript]=await Promise.all(['messages.php','messages-v320.js','messages-v320.css','chat.css','browser-share-card-v2020.js'].map(read));
const template=pageSource.slice(pageSource.indexOf('<!doctype html>'))
  .replace(/<\?(?:php|=)[\s\S]*?\?>/g,'').replace(/<link\b[^>]*>|<script\b[^>]*>[\s\S]*?<\/script>/g,'')
  .replace('data-api=""','data-api="/api/messages-v320.php"').replace('data-browser-share-api=""','data-browser-share-api="/api/shares.php"')
  .replace('data-csrf=""','data-csrf="fixture-csrf"').replace('data-user-id=""','data-user-id="1"')
  .replace('<div class="messages-shell"','<header class="member-header" style="height:58px;min-height:58px">Messages</header><div class="messages-shell"')
  .replace('</head>',`<style>${baseCss}\n${css}</style></head>`).replace('</body>','<script src="/browser-share-card-v2020.js"></script><script src="/messages-v320.js"></script></body>');
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
const page=await browser.newPage({viewport:{width:1440,height:900}}),errors=[],calls=[];
page.on('pageerror',error=>errors.push(error.message));
await page.clock.install();
const message=(id,cid,body,sender=2)=>({id,conversation_id:cid,sender_user_id:sender,body,display_name:sender===1?'Owner':'Member',created_at:'2026-11-27 12:00:00'});
const threads=new Map([[10,[message(101,10,'Alice history')]], [20,[message(201,20,'Bob history',3)]], [30,[message(301,30,'Charlie request',4)]], [40,[message(401,40,'Diana request',1)]], [50,Array.from({length:180},(_,i)=>message(5001+i,50,'History '+(i+1)))] ,[60,[]]]);
const names={10:'Alice',20:'Bob',30:'Charlie',40:'Diana',50:'Long conversation',60:'Empty conversation',70:'New person'};
const requests=new Map([[30,{status:'pending',requester_user_id:4,recipient_user_id:1}],[40,{status:'pending',requester_user_id:1,recipient_user_id:5}]]);
let holdConversation=0,releaseConversation=null,holdSend=0,releaseSend=null,releaseShares=null,holdInbox=false,releaseInbox=null,failInbox=false,nextMessage=6001,accountId=1,includeShare=false,mediaAccountOverride=null;
const boundRequests=[],shareWrites=[];
const privacy={message_policy:'friends',allow_friend_requests:true,show_followers:false,show_following:false};
function inbox(){return {teams:[],conversations:[10,20,40,50,60,...(requests.get(30)?.status==='accepted'?[30]:[]),...(threads.has(70)?[70]:[])].map(id=>({id,other_user_id:id===10?2:id===20?3:id===70?9:5,other_name:names[id],last_message:threads.get(id).at(-1)?.body||'',unread_count:1,request_status:requests.get(id)?.status})),requests:requests.get(30)?.status==='pending'?[{conversation_id:30,requester_user_id:4,recipient_user_id:1,requester_name:'Charlie',initial_message:'Charlie request'}]:[]};}
await page.route('https://messages.test/**',async route=>{
  const url=new URL(route.request().url()),path=url.pathname;
  if(path==='/messages-v320.js')return route.fulfill({contentType:'text/javascript',body:script});
  if(path==='/browser-share-card-v2020.js')return route.fulfill({contentType:'text/javascript',body:cardScript});
  if(path==='/api/messages-v320.php'||path==='/api/shares.php'){
    const binding=route.request().headers()['x-vp3-messages-user'];boundRequests.push({path,binding});
    if(accountId<1)return route.fulfill({status:401,json:{ok:false,error:'login_required'}});
    if(binding!==String(accountId))return route.fulfill({status:403,json:{ok:false,error:'account_changed'}});
  }
  if(path==='/api/shares.php'){
    if(route.request().method()==='POST'){shareWrites.push(route.request().postData());return route.fulfill({json:{ok:true,workflow:{id:1}}});}
    if(!releaseShares)await new Promise(resolve=>{releaseShares=resolve;});
    return route.fulfill({json:{ok:true,feed:{items:includeShare?[{id:'private-share-a',conversation_id:10,source:{title:'Account A private share',domain:'example.test'},note:'Account A private note'}]:[]}}});
  }
  if(path==='/api/browser-share-media-v2040.php'){
    if(mediaAccountOverride!==null){accountId=mediaAccountOverride;mediaAccountOverride=null;}
    const binding=route.request().headers()['x-vp3-messages-user'];boundRequests.push({path,binding});
    if(accountId<1)return route.fulfill({status:401,json:{ok:false,error:{code:'login_required',message:'Session expired'}}});
    if(binding!==String(accountId))return route.fulfill({status:403,json:{ok:false,error:{code:'account_changed',message:'Account changed'}}});
    return route.fulfill({json:{ok:true,media:[]}});
  }
  if(path==='/api/messages-v320.php'){
    const params=Object.fromEntries(url.searchParams);if(route.request().method()==='POST')Object.assign(params,Object.fromEntries(new URLSearchParams(route.request().postData())));
    calls.push(params);let data={ok:true},id=Number(params.conversation_id||0);
    if(params.action==='inbox'){if(failInbox){failInbox=false;return route.fulfill({status:503,json:{ok:false,error:'database_unavailable'}});}data={...data,inbox:inbox(),settings:privacy};if(holdInbox){holdInbox=false;await new Promise(resolve=>{releaseInbox=resolve;});}}
    if(params.action==='conversation'){
      const after=Number(params.after||0),before=Number(params.before||0),all=threads.get(id)||[];
      const filtered=all.filter(row=>(!after||row.id>after)&&(!before||row.id<before)),batch=after?filtered.slice(0,120):filtered.slice(-120);
      data={...data,conversation:{id,conversation_type:'direct'},request:requests.get(id)||null,messages:structuredClone(batch),has_older:!after&&filtered.length>120,has_more:after>0&&filtered.length>120};
      if(id===holdConversation){holdConversation=0;await new Promise(resolve=>{releaseConversation=resolve;});}
    }
    if(params.action==='send'){
      const row=message(nextMessage++,id,params.message,1);threads.get(id).push(row);data.message=row;
      if(id===holdSend){holdSend=0;await new Promise(resolve=>{releaseSend=resolve;});}
    }
    if(params.action==='read')data.last_read_message_id=Number(params.through_message_id);
    if(params.action==='start'){const row=message(8000,70,params.message,1);threads.set(70,[row]);requests.set(70,{status:'pending',requester_user_id:1,recipient_user_id:9});data={...data,conversation:{id:70,conversation_type:'direct'},message:row};}
    if(params.action==='request_accept'){requests.get(id).status='accepted';}
    if(params.action==='request_decline'){requests.get(id).status='declined';}
    if(params.action==='settings')data.settings={...privacy,message_policy:params.message_policy};
    return route.fulfill({json:data});
  }
  const html=template.replace('data-conversation-id=""',`data-conversation-id="${Number(url.searchParams.get('conversation_id')||0)}"`).replace('data-target-id=""',`data-target-id="${Number(url.searchParams.get('user_id')||0)}"`);
  return route.fulfill({contentType:'text/html',body:html});
});
const waitTitle=name=>page.waitForFunction(value=>document.querySelector('[data-thread-title]').textContent===value,name);
const open=async id=>{await page.locator(`[data-open="${id}"]`).click();await waitTitle(names[id]);await page.waitForFunction(()=>!document.querySelector('[data-stream]').hidden);};
try{
  await page.goto('https://messages.test/');
  await page.locator('[data-open="10"]').waitFor();
  assert.equal(await page.locator('[data-composer]').isVisible(),false,'Empty Messages exposes a send composer');
  assert.equal(await page.locator('[data-request-actions]').isVisible(),false,'Hidden request actions are visible because of CSS');
  assert.equal(typeof releaseShares,'function','Shares fixture was not pending');releaseShares();
  await open(50);
  assert.equal(await page.locator('[data-message-id]').count(),120);
  assert.equal(await page.locator('[data-message-id]').first().getAttribute('data-message-id'),'5061');
  await page.locator('[data-history]').click();await page.waitForFunction(()=>document.querySelectorAll('[data-message-id]').length===180);
  assert.equal(await page.locator('[data-message-id]').first().getAttribute('data-message-id'),'5001');
  assert.equal(await page.locator('[data-history]').isVisible(),false);
  // A visitor reply immediately before our send must not be skipped by using the
  // optimistic sent-message id as the server read/catch-up cursor.
  threads.get(50).push(message(6000,50,'Concurrent incoming reply'));
  await page.locator('textarea').fill('Owner reply');
  await page.locator('[data-composer]').evaluate(form=>{form.requestSubmit();form.requestSubmit();});
  await page.locator('[data-message-id="6000"]').waitFor();await page.locator('[data-message-id="6001"]').waitFor();
  assert.equal(calls.filter(call=>call.action==='send'&&call.message==='Owner reply').length,1,'Repeated submit duplicated a message');
  await page.locator('[data-filter="requests"]').click();await open(30);
  assert.equal(await page.locator('[data-composer]').isVisible(),false,'Pending request can be replied to before acceptance');
  assert.equal(await page.locator('[data-request-actions]').isVisible(),true);
  await page.locator('[data-request-accept]').click();await page.locator('[data-composer]').waitFor({state:'visible'});
  await page.locator('[data-filter="people"]').click();await open(40);
  assert.equal(await page.locator('[data-composer]').isVisible(),false,'Outgoing request allows another message');
  assert.equal(await page.locator('[data-request-actions]').isVisible(),false,'Requester sees accept/decline actions');
  assert.match(await page.locator('[data-status]').innerText(),/Request pending/);
  // Complete an older request after another thread was selected.
  holdConversation=10;await page.locator('[data-open="10"]').click();
  while(!releaseConversation)await page.waitForTimeout(5);
  const readsBefore=calls.filter(call=>call.action==='read'&&call.conversation_id==='10').length;
  await open(20);releaseConversation();await page.waitForTimeout(40);
  assert.equal(await page.locator('[data-thread-title]').innerText(),'Bob');
  assert.match(await page.locator('[data-stream]').innerText(),/Bob history/);
  assert.doesNotMatch(await page.locator('[data-stream]').innerText(),/Alice history/);
  assert.equal(calls.filter(call=>call.action==='read'&&call.conversation_id==='10').length,readsBefore,'Stale thread marked read');
  await page.locator('textarea').fill('Bob draft');await open(10);await page.locator('textarea').fill('Alice sent once');
  holdSend=10;await page.locator('[data-composer] button').click();while(!releaseSend)await page.waitForTimeout(5);
  await open(20);assert.equal(await page.locator('textarea').inputValue(),'Bob draft');releaseSend();await page.waitForTimeout(100);
  assert.equal(await page.locator('[data-thread-title]').innerText(),'Bob');assert.equal(await page.locator('textarea').inputValue(),'Bob draft','Late send cleared a different conversation draft');
  threads.get(20).push(message(7000,20,'Live incoming message',3));
  await page.clock.fastForward(10010);await page.locator('[data-message-id="7000"]').waitFor();
  assert.equal(await page.locator('textarea').inputValue(),'Bob draft','Polling overwrote the current draft');
  // Saving message policy must not publish previously private follower lists.
  await page.locator('[data-settings-open]').click();await page.locator('[data-message-policy]').selectOption('anyone');await page.locator('[data-settings-save]').click();await page.locator('[data-settings]').waitFor({state:'hidden'});
  const saved=calls.findLast(call=>call.action==='settings');assert.equal(saved.show_followers,'0');assert.equal(saved.show_following,'0');
  await page.locator('[data-search]').fill('Alice');assert.equal(await page.locator('[data-list="people"] [data-open]').count(),1);await page.locator('[data-search]').fill('');
  // Phone navigation dedicates the viewport to the selected transcript.
  for(const width of [390,320]){
    await page.setViewportSize({width,height:844});
    assert.equal(await page.locator('.messages-inbox').isVisible(),false);
    const bounds=await page.locator('[data-composer]').boundingBox();assert.ok(bounds.y+bounds.height<=845,'Phone composer extends below viewport');
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1&&document.documentElement.scrollHeight<=innerHeight+1),true,'Messages causes page overflow');
  }
  if(process.env.MESSAGES_SCREENSHOTS)await page.screenshot({path:process.env.MESSAGES_SCREENSHOTS+'/messages-phone.png'});
  await page.locator('[data-back]').click();assert.equal(await page.locator('.messages-inbox').isVisible(),true);
  await page.waitForTimeout(50);const readsHidden=calls.filter(call=>call.action==='read').length;threads.get(20).push(message(7001,20,'Unseen while reading inbox',3));
  await page.clock.fastForward(10010);await page.waitForTimeout(50);assert.equal(calls.filter(call=>call.action==='read').length,readsHidden,'Hidden phone transcript marked new messages read');
  // Deep links created by Browser Companion now open their exact conversation.
  await page.goto('https://messages.test/?conversation_id=50');await waitTitle('Long conversation');await page.locator('[data-message-id="6001"]').waitFor();
  await page.setViewportSize({width:1440,height:900});
  if(process.env.MESSAGES_SCREENSHOTS)await page.screenshot({path:process.env.MESSAGES_SCREENSHOTS+'/messages-desktop.png'});
  // A confirmed send followed by failed inbox refresh remains confirmed and does
  // not restore a draft that encourages an accidental duplicate.
  failInbox=true;await page.locator('textarea').fill('Confirmed despite refresh failure');await page.locator('[data-composer] button').click();
  await page.waitForFunction(()=>document.querySelector('[data-status]').textContent.startsWith('Message sent.'));
  assert.equal(await page.locator('textarea').inputValue(),'');
  assert.equal(calls.filter(call=>call.action==='send'&&call.message==='Confirmed despite refresh failure').length,1);
  holdInbox=true;await page.goto('https://messages.test/?user_id=2');await page.locator('[data-composer]').waitFor({state:'visible'});await page.locator('textarea').fill('Draft while finding existing thread');
  while(!releaseInbox)await page.waitForTimeout(5);releaseInbox();await waitTitle('Alice');
  assert.equal(await page.locator('textarea').inputValue(),'Draft while finding existing thread','Loading an existing member conversation erased the newly typed draft');
  await page.goto('https://messages.test/?user_id=9');await page.locator('[data-composer]').waitFor({state:'visible'});
  await page.locator('textarea').fill('New person hello');await page.locator('[data-composer] button').click();await page.locator('[data-message-id="8000"]').waitFor();
  await page.waitForFunction(()=>document.querySelector('[data-thread-kind]').textContent==='Message Request');
  assert.equal(await page.locator('[data-composer]').isVisible(),false,'New outgoing request kept the composer open');
  assert.equal(calls.filter(call=>call.action==='start').length,1,'Starting a conversation submitted twice');
  // Every Messages read/write binds the account rendered in the page. A changed
  // session clears the old transcript and drafts instead of mixing both users.
  await open(10);await page.locator('textarea').fill('Account A private draft');
  accountId=2;await page.locator('[data-refresh]').click();await page.getByText('Your account changed. Reload Messages to continue.',{exact:true}).waitFor();
  assert.equal(await page.locator('textarea').inputValue(),'');assert.equal(await page.locator('[data-message-id]').count(),0);assert.equal(await page.locator('[data-open]').count(),0);
  assert.equal(await page.locator('[data-settings-open]').isDisabled(),true);
  const afterLock=boundRequests.length;await page.locator('[data-composer]').evaluate(form=>form.requestSubmit());await page.clock.fastForward(10010);await page.waitForTimeout(30);assert.equal(boundRequests.length,afterLock,'Locked account kept polling or sending');
  accountId=1;includeShare=true;await page.goto('https://messages.test/');await page.locator('[data-share-id]').waitFor({state:'attached'});await page.locator('[data-filter="shares"]').click();await page.locator('[data-share-id]').click();
  accountId=2;await page.getByRole('button',{name:'Save to Knowledge',exact:true}).click();await page.getByText('Your account changed. Reload Messages to continue.',{exact:true}).waitFor();
  assert.equal(shareWrites.length,0,'A stale share card wrote to the new account');assert.equal(await page.locator('.vp3-browser-share-card-v2020').count(),0,'A stale share card remained visible after account denial');
  // Media has its own endpoint and nested error shape. Its denial must clear the
  // old card immediately, without waiting for the next Messages inbox poll.
  for(const replacement of [2,0]){
    accountId=1;mediaAccountOverride=replacement;await page.goto('https://messages.test/');await page.locator('[data-share-id]').waitFor({state:'attached'});await page.locator('[data-filter="shares"]').click();
    const inboxReads=calls.filter(call=>call.action==='inbox').length;
    await page.locator('[data-share-id]').click();
    await page.getByText(replacement?'Your account changed. Reload Messages to continue.':'Your session expired. Sign in again, then reload Messages.',{exact:true}).waitFor({timeout:1000});
    assert.equal(await page.locator('.vp3-browser-share-card-v2020,.vp3-bs-media').count(),0,'Media account denial left private share content visible');
    assert.equal(calls.filter(call=>call.action==='inbox').length,inboxReads,'Media clearing waited for another inbox poll');
  }
  accountId=1;includeShare=false;await page.goto('https://messages.test/?conversation_id=10');await waitTitle('Alice');await page.locator('textarea').fill('Private draft before logout');
  accountId=0;await page.locator('[data-refresh]').click();await page.getByText('Your session expired. Sign in again, then reload Messages.',{exact:true}).waitFor();
  assert.equal(await page.locator('textarea').inputValue(),'');assert.equal(await page.locator('[data-message-id]').count(),0);
  assert.ok(boundRequests.every(request=>request.binding==='1'),'A Messages, Shares or media request omitted the expected account binding');
  assert.ok(calls.filter(call=>['start','send','read','request_accept','settings'].includes(call.action)).every(call=>call.csrf_token==='fixture-csrf'));
  assert.ok(calls.filter(call=>call.action==='read').every(call=>Number(call.through_message_id)>0),'Empty view used a read-all cursor');
  assert.deepEqual(errors,[]);
  console.log('PASS Messages browser: live refresh, latest/earlier history, stale thread isolation, concurrent send/catch-up, request lifecycle, per-thread drafts, deep links, privacy preservation, mobile viewport, error recovery, account-switch clearing, logout clearing, bound Shares actions and immediate media identity clearing');
}finally{await browser.close();}
