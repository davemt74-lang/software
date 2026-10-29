import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const source=name=>fs.readFile(new URL('../'+name,import.meta.url),'utf8');
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
const page=await browser.newPage({viewport:{width:1280,height:850}}),errors=[],writes=[];
page.on('pageerror',error=>errors.push(error.message));
const now=new Date().toISOString(),mid='11111111-1111-4111-8111-111111111111';
const eyes=Array.from({length:35},(_,i)=>({event_type:'agent_eyes.arrival',received_at:now,payload:{summary:'Participant '+i+' arrived. Provisional browser observation with a readable timestamp.'}}));
const data={ok:true,notifications:{unread:1,items:[{id:3,title:'Profile visitor',body:'One saved profile notification',is_read:0,created_at:now}]},brain:{eyes_events:eyes},history:[{role:'assistant',message:'A saved conversation message',created_at:now,conversation_id:42,input_mode:'text'}]};
const schedules=[{id:'55555555-5555-4555-8555-555555555555',objective:'Prepare the weekly restaurant inventory research summary',status:'active',frequency:'weekly',weekday:0,hour:9,minute:30,timezone:'America/Phoenix',next_run_at:now,last_run_at:now,updated_at:now,runs:[{status:'started',mission_status:'completed',due_at:now,completed_at:now,mission_id:mid}]}];
await page.route('http://activity.test/',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><div class="chat-topbar-actions"><div id="chatNotificationMenu"><button id="chatNotificationButton">Notifications</button></div><div id="chatProfileMenu">Account</div></div><main>Cloud workspace</main><div id="chatComposerShell"><form id="chatForm"><textarea id="chatInput"></textarea></form></div>'}));
await page.route('**/api/**',route=>{
  const body=route.request().method()==='POST'?route.request().postDataJSON():{};
  if(body.action&& !['list','providers','schedule.list','progress.feed'].includes(body.action))writes.push(body);
  if(new URL(route.request().url()).pathname==='/api/activity')return route.fulfill({json:data});
  if(body.action==='schedule.list')return route.fulfill({json:{ok:true,schedules,scheduler_health:{state:'healthy',checked_at:now,last_tick_at:now,last_success_at:now}}});
  if(body.action==='progress.feed')return route.fulfill({json:{ok:true,items:[],notifications:{unread:1,items:[{id:7,mission_id:mid,title:'Review the completed specialist research',created_at:now}]}}});
  return route.fulfill({json:{ok:true,items:[],providers:[]}});
});
let checks=0;const test=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
try{
  await page.goto('http://activity.test/');
  await page.evaluate(()=>{window.VP3_AGENT_TEAMS_A3={endpoint:'/api/missions',csrf:'fixture'};window.STONEFELLOW_NOTIFICATION_DRAWER={endpoint:'/api/activity',csrf:'fixture'};window.VP3_COGNITIVE_PRESENTATION_V510={ownsAttention:true,ownsBrainPresentation:true};});
  for(const name of ['chat-agent-teams-a3.css','chat-notifications-drawer-v240.css'])await page.addStyleTag({content:await source(name)});
  await page.addScriptTag({content:await source('chat-agent-teams-a3.js')});
  await page.addScriptTag({content:await source('chat-notifications-drawer-v240.js')});
  await page.waitForFunction(()=>window.STONEFELLOW_NOTIFICATION_CENTER);
  await page.locator('#chatNotificationButton').click();
  await page.waitForSelector('[data-mission-notices] .vp3-mission-notice');
  await test('one Activity Center and notification feed survive repeat loads and refreshes',async()=>{
    await page.addScriptTag({content:await source('chat-notifications-drawer-v240.js')});
    for(let i=0;i<5;i++)await page.evaluate(()=>STONEFELLOW_NOTIFICATION_CENTER.refresh());
    assert.equal(await page.locator('#chatNotificationDrawer').count(),1);
    assert.equal(await page.locator('#chatNotificationDrawer [data-mission-notices]').count(),1);
    assert.equal(await page.locator('#chatNotificationDrawer .chat-notification-list').count(),1);
    assert.equal(await page.locator('#chatNotificationDrawer [data-notification-id="3"]').count(),1);
  });
  for(const viewport of [{width:1280,height:850},{width:390,height:844},{width:320,height:720}]){
    await page.setViewportSize(viewport);
    await test(`four visible tabs and readable Eyes rows at ${viewport.width}px`,async()=>{
      assert.equal(await page.locator('#chatNotificationDrawer [role="tab"]').count(),4);
      await page.getByRole('tab',{name:'Agent Eyes',exact:true}).click();
      assert.equal(await page.locator('[data-eyes-timeline] .chat-eyes-event').count(),30,'Eyes feed remains bounded');
      assert.equal(await page.locator('#chatNotificationDrawer .chat-brain-history').count(),0);
      const widths=await page.locator('.chat-eyes-event>div').evaluateAll(nodes=>nodes.map(node=>node.getBoundingClientRect().width));
      assert.ok(widths.every(width=>width>180),'Eyes text cannot occupy the 12px marker column');
      assert.equal(await page.locator('.chat-eyes-event time').first().evaluate(node=>getComputedStyle(node).whiteSpace),'nowrap');
      assert.ok((await page.locator('.chat-eyes-event time').first().boundingBox()).height<28);
      if(process.env.VP3_ACTIVITY_SCREENSHOT)await page.screenshot({path:process.env.VP3_ACTIVITY_SCREENSHOT.replace('.png','-eyes-'+viewport.width+'.png')});
      await page.getByRole('tab',{name:'History',exact:true}).click();
      assert.match(await page.locator('.chat-brain-history').innerText(),/A saved conversation message/);
      assert.equal(await page.locator('[data-eyes-timeline]').count(),0,'Conversation History has no duplicate Eyes feed');
    });
    await test(`mission and schedule cards share one scroll surface at ${viewport.width}px`,async()=>{
      await page.getByRole('tab',{name:/Notifications/}).click();
      assert.equal(await page.locator('[data-mission-notices] .vp3-activity-card-head').count(),1);
      await page.getByRole('tab',{name:'Agent Brain',exact:true}).click();
      await page.waitForSelector('.vp3-schedules-brain .vp3-schedule-row');
      assert.match(await page.locator('.vp3-schedules-brain').innerText(),/America\/Phoenix/);
      const dates=await page.locator('.vp3-schedules-brain .vp3-activity-dates>div').evaluateAll(nodes=>nodes.map(node=>node.getBoundingClientRect().width));
      assert.ok(dates.every(width=>width>=115),'Date labels need usable columns');
      const overflow=await page.locator('[data-notification-drawer-body]').evaluate(body=>({horizontal:body.scrollWidth>body.clientWidth+1,nested:[...body.querySelectorAll('*')].filter(node=>['auto','scroll'].includes(getComputedStyle(node).overflowY)&&node.scrollHeight>node.clientHeight+1).length}));
      if(overflow.horizontal)console.error('OVERFLOW',await page.locator('[data-notification-drawer-body]').evaluate(body=>[...body.querySelectorAll('*')].map(node=>({tag:node.tagName,cls:node.className,width:node.getBoundingClientRect().width,right:node.getBoundingClientRect().right,text:node.textContent.slice(0,80)})).filter(node=>node.right>body.getBoundingClientRect().left+body.clientWidth+1)));
      if(process.env.VP3_ACTIVITY_SCREENSHOT)await page.screenshot({path:process.env.VP3_ACTIVITY_SCREENSHOT.replace('.png','-brain-'+viewport.width+'.png')});
      assert.deepEqual(overflow,{horizontal:false,nested:0});
      const box=await page.locator('#chatNotificationDrawer').boundingBox();assert.ok(box.x>=0&&box.width<=viewport.width+1&&box.height<=viewport.height+1,JSON.stringify({viewport,box}));
      if(process.env.VP3_ACTIVITY_SCREENSHOT)await page.screenshot({path:process.env.VP3_ACTIVITY_SCREENSHOT.replace('.png','-brain-'+viewport.width+'.png')});
    });
  }
  await test('tab keyboard navigation and empty Eyes feed do not start work',async()=>{
    await page.getByRole('tab',{name:'History',exact:true}).focus();await page.keyboard.press('ArrowRight');
    assert.equal(await page.getByRole('tab',{name:'Agent Eyes',exact:true}).getAttribute('aria-selected'),'true');
    data.brain.eyes_events=[];await page.evaluate(()=>STONEFELLOW_NOTIFICATION_CENTER.refresh());
    assert.match(await page.locator('[data-eyes-timeline]').innerText(),/No Agent Eyes activity yet/);
    assert.equal(writes.length,0,'Reading activity must not schedule, start, approve, or mark anything read');
  });
  assert.deepEqual(errors,[]);
  if(process.env.VP3_ACTIVITY_SCREENSHOT)await page.screenshot({path:process.env.VP3_ACTIVITY_SCREENSHOT});
  console.log(`ACTIVITY_CENTER_LAYOUT=PASS (${checks} cases)`);
}catch(error){console.error('ACTIVITY_ERRORS',errors);throw error;}finally{await browser.close();}
