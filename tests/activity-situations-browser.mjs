import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const read=name=>fs.readFile(new URL('../'+name,import.meta.url),'utf8');
const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:390,height:844}});
page.setDefaultTimeout(8000);
const errors=[];
page.on('pageerror',error=>errors.push(error.message));
const hash=value=>createHash('sha256').update(value).digest('hex');
const now=new Date().toISOString();
const origin='https://situations.test';
const requests=[];
let failure=false;
let heldRead=null;
const situation=(id,title,extra={})=>({id:hash(id),kind:'situation',state:'needs_attention',title,
  summary:'The saved task and reviewed call follow-up concern the same launch.',updated_at:now,revision:hash(id+':revision'),
  source_label:'Launch follow-up',source_url:'/task-lists.php?task=12',action_label:'Open task',
  sources:[{kind:'brain',label:'Saved task',occurred_at:now,url:'/task-lists.php?task=12'},
    {kind:'history',label:'Reviewed call follow-up',occurred_at:now,url:'/transcriptions.php?session=9'}],...extra});
const first=situation('launch','Review the launch follow-up');
const state={ok:true,agent_namespace:'system',notifications:{unread:1,items:[{id:4,title:'A saved notification',
  body:'Notification details remain available.',is_read:0,created_at:now,target_url:'/reminders.php'}]},
  activity_stream:{complete:true,agent_namespace:'system',as_of:now,items:[first]},
  brain:{enabled:true,namespace:'system',operations:[{id:7,title:'A saved operational detail',body:'An existing Brain operation.',created_at:now}],
    eyes_events:[{event_type:'agent_eyes.arrival',received_at:now,payload:{summary:'Provisional arrival observation'}}]},
  history:[{role:'assistant',message:'An older private conversation',created_at:now,conversation_id:42,input_mode:'text'}]};
await page.route(origin+'/**',async route=>{
  const request=route.request();
  const url=new URL(request.url());
  if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
    <div class="chat-topbar-actions"><div id="chatNotificationMenu"><button id="chatNotificationButton">Activity</button></div><div id="chatProfileMenu">Account</div></div>
    <main><section id="chatThread" data-view="chat"></section></main><div id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea></form></div>`});
  if(url.pathname!=='/api/activity')return route.fulfill({status:404});
  const body=request.method()==='POST'?request.postDataJSON():null;
  requests.push({body,binding:request.headers()['x-vp3-working-attention'],agent:url.searchParams.get('agent_id')});
  if(heldRead&&!body){const held=heldRead;heldRead=null;held.arrive();await held.release;}
  if(failure)return route.fulfill({status:503,json:{ok:false,error:'Source temporarily unavailable'}});
  return route.fulfill({json:structuredClone(state)});
});
const refresh=()=>page.evaluate(()=>window.STONEFELLOW_NOTIFICATION_CENTER.refresh());
const drawer=page.locator('#chatNotificationDrawer');
const stream=drawer.locator('[data-activity-stream]');
let checks=0;
const pass=message=>{checks++;console.log('PASS '+message);};
try{
  await page.goto(origin);
  await page.evaluate(()=>{
    window.STONEFELLOW_CHAT={userId:1,workingAttentionBinding:'owner-session-a'};
    window.STONEFELLOW_NOTIFICATION_DRAWER={endpoint:'/api/activity',csrf:'fixture',agentId:0,userId:1,workingAttentionBinding:'owner-session-a'};
    window.VP3_COGNITIVE_PRESENTATION_V510={ownsAttention:true,ownsBrainPresentation:true};
    window.spoken=0;window.profileActions=0;
    window.speechSynthesis={speak:()=>window.spoken++,cancel:()=>{}};
    window.VP3ProfileAttentionPa4b={create:()=>({
      view:()=>'<section data-profile-live-controls><strong>Live Profile conversation</strong><button type="button" data-profile-live-takeover>Take Over</button></section>',
      handleClick:event=>{if(!event.target.closest('[data-profile-live-takeover]'))return false;window.profileActions++;return true;},
      getState:()=>({items:[]}),refresh:async()=>{},dispose:()=>{}
    })};
  });
  await page.addStyleTag({content:await read('chat-notifications-drawer-v240.css')});
  await page.addScriptTag({content:await read('chat-notifications-drawer-v240.js')});
  await page.locator('#chatNotificationButton').click();
  await stream.getByText(first.title,{exact:true}).waitFor();
  assert.equal(await drawer.getByRole('tab',{name:/^Activity/}).getAttribute('aria-selected'),'true');
  assert.equal(await drawer.locator('[data-activity-details="notifications"]').getAttribute('open'),null);
  assert.equal(await drawer.getByText('A saved notification',{exact:true}).isVisible(),false);
  assert.equal(await drawer.getByText('Provisional arrival observation',{exact:true}).count(),0);
  assert.equal(await stream.locator('[data-activity-situation]').count(),1);
  assert.ok(requests.every(row=>row.binding==='owner-session-a'&&row.agent==='0'));
  assert.equal(requests.some(row=>row.body),false);
  assert.equal(await page.evaluate(()=>window.spoken),0);
  pass('Activity opens with one processed situation while source ledgers stay in details and reading stays silent');

  await stream.getByText('Source details',{exact:true}).click();
  assert.equal(await stream.getByRole('link',{name:'Saved task',exact:true}).getAttribute('href'),'/task-lists.php?task=12');
  assert.equal(await stream.getByRole('link',{name:'Reviewed call follow-up',exact:true}).getAttribute('href'),'/transcriptions.php?session=9');
  await drawer.getByRole('button',{name:'Take Over',exact:true}).click();
  assert.equal(await page.evaluate(()=>window.profileActions),1);
  await drawer.locator('[data-activity-details="notifications"]>summary').click();
  await drawer.getByText('A saved notification',{exact:true}).waitFor();
  await refresh();
  assert.equal(await drawer.getByText('A saved notification',{exact:true}).isVisible(),true);
  assert.equal(await stream.getByRole('link',{name:'Saved task',exact:true}).isVisible(),true);
  assert.equal(await drawer.locator('[data-profile-live-controls]').count(),1);
  pass('exact source links, expanded details and existing live Profile controls survive a refresh');

  state.activity_stream.items=[situation('unsafe','<img src=x onerror="window.unsafe=true"> Unsafe source',{source_url:'//outside.test/steal',
    summary:'<script>window.unsafe=true</script>',state:'completed',sources:[{label:'External impersonation',url:'/\\outside.test/steal'}]})];
  await refresh();
  assert.equal(await stream.locator('img,script').count(),0);
  assert.equal(await stream.locator('a').count(),0);
  assert.equal(await page.evaluate(()=>window.unsafe),undefined);
  assert.match(await stream.innerText(),/Updated/);
  assert.equal(await stream.getByText('Resolved',{exact:true}).count(),0);
  pass('literal text and internal source validation prevent injected links or invented completion status');

  state.activity_stream.items=Array.from({length:18},(_,index)=>situation('card:'+index,
    'A saved situation with a long enough title to test wrapping on a small mobile screen '+index));
  await refresh();
  assert.equal(await stream.locator('[data-activity-situation]').count(),12);
  for(const viewport of [{width:1280,height:850},{width:390,height:844},{width:320,height:720}]){
    await page.setViewportSize(viewport);
    const overflow=await drawer.locator('[data-notification-drawer-body]').evaluate(body=>({
      horizontal:body.scrollWidth>body.clientWidth+1,
      nested:[...body.querySelectorAll('*')].filter(node=>['auto','scroll'].includes(getComputedStyle(node).overflowY)&&node.scrollHeight>node.clientHeight+1).length
    }));
    assert.deepEqual(overflow,{horizontal:false,nested:0});
    const bounds=await drawer.boundingBox();
    assert.ok(bounds.x>=0&&bounds.width<=viewport.width+1&&bounds.height<=viewport.height+1);
  }
  pass('bounded situations fit desktop and 390px and 320px mobile with one scroll surface');

  for(const [tab,text] of [['Agent Brain','A saved operational detail'],['History','An older private conversation'],['Agent Eyes','Provisional arrival observation']]){
    await drawer.getByRole('tab',{name:tab,exact:true}).click();
    await drawer.getByText(text,{exact:true}).waitFor();
    assert.equal(await stream.count(),0);
  }
  await drawer.getByRole('tab',{name:/^Activity/}).click();
  failure=true;
  await refresh();
  assert.equal(await stream.count(),0);
  assert.match(await drawer.innerText(),/could not refresh/);
  failure=false;
  state.activity_stream.items=[first];
  await refresh();
  await stream.getByText(first.title,{exact:true}).waitFor();
  assert.equal(await stream.locator('[data-activity-situation]').count(),1);
  pass('deep views retain their controls and a failed source read clears the processed stream until a confirmed retry');

  let release;
  const arrived=new Promise(resolve=>{heldRead={arrive:resolve,release:new Promise(done=>{release=done;})};});
  const pending=refresh();
  await arrived;
  await page.evaluate(()=>{window.STONEFELLOW_CHAT.userId=2;window.STONEFELLOW_CHAT.workingAttentionBinding='owner-session-b';});
  release();
  await pending;
  assert.equal(await stream.count(),0);
  assert.equal(await drawer.locator('[data-profile-live-controls]').count(),0);
  assert.match(await drawer.innerText(),/session changed/);
  assert.equal(await drawer.getByText(first.title,{exact:true}).count(),0);
  assert.equal(await page.evaluate(()=>window.spoken),0);
  pass('an account switch during a delayed read clears prior situations and private live controls without speaking');

  await page.evaluate(()=>{window.STONEFELLOW_CHAT.userId=1;window.STONEFELLOW_CHAT.agentId=0;
    window.STONEFELLOW_CHAT.workingAttentionBinding='owner-session-a';});
  await refresh();
  await stream.getByText(first.title,{exact:true}).waitFor();
  state.activity_stream.agent_namespace='agent:7';
  await refresh();
  assert.equal(await stream.count(),0);
  assert.equal(await drawer.locator('[data-profile-live-controls]').count(),0);
  assert.match(await drawer.innerText(),/session changed/);
  assert.equal(await page.evaluate(()=>window.spoken),0);
  pass('a mismatched Agent namespace cannot populate or retain the current Activity stream');
  assert.deepEqual(errors,[]);
  console.log(`ACTIVITY_SITUATIONS_BROWSER=PASS (${checks} cases)`);
}finally{
  await browser.close();
}
