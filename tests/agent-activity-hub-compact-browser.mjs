import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const browser = await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
const page = await browser.newPage({viewport:{width:1280,height:900},hasTouch:true});
const errors = [];
page.on('pageerror',error => errors.push(error.message));
const longDetail = 'Waiting for review. ' + 'This saved investigation has a longer explanation and keeps all of its original actions available. '.repeat(8);
let reads = 0;
await page.route('https://activity.test/',route => route.fulfill({contentType:'text/html',body:`<!doctype html>
<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}.vp3-orb-initiative{position:fixed!important;left:12px!important;top:12px!important;bottom:auto!important;--vp3-initiative-room:75dvh}</style>
<div class="vp3-orb-root"><section class="vp3-orb-initiative" data-agent-initiative-status><div id="vp3AgentActivityHub" data-agent-activity-host></div><details data-agent-worker-details hidden><summary>Runtime details</summary></details></section></div>`}));
await page.route('https://activity.test/api/hub**',route => {
  reads++;
  return route.fulfill({json:{ok:true,state:{contract:'vp3.agent.activity.v1',owner_user_id:1,agent_id:7,conversation_id:42,working_attention_binding:'fixture-binding',observed_at:new Date().toISOString(),agent_name:'Longfellow',current_chat:{title:'Workshop research'},coverage:{calendar:'unavailable'},sections:[
    {id:'lists',label:'Lists',items:[{id:'list:4',title:'Workshop supplies and materials '.repeat(8),detail:'2 open',actions:[{kind:'task_notes',label:'Open list',list_id:4}]}]},
    {id:'calendar',label:'Calendar',items:[{id:'event:1',title:'Workshop starts',detail:'Tomorrow',actions:[]}]},
    {id:'missions',label:'Missions',items:[{id:'mission:1',title:'Research opportunities <img src=x> · '+reads,detail:longDetail,actions:['Open','Review','Resume','Notes'].map((label,index)=>({kind:'task_notes',label,list_id:index+5}))}]}
  ]}}});
});

try {
  await page.goto('https://activity.test/');
  await page.evaluate(() => {
    window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-binding'};
    window.VP3_ORB_CONFIG={ownerKey:'1',activityEndpoint:'/api/hub'};
    window.STONEFELLOW_CHAT_CONTINUITY={agentId:()=>7,conversationId:()=>42,isReady:()=>true,isRestoring:()=>false};
    window.calls=[];
    window.VP3TaskNotes={open:value=>window.calls.push(value)};
    window.VP3AgentOrb={activityHost:()=>document.getElementById('vp3AgentActivityHub'),activitySpecialists:()=>[],positionActivity:()=>{},prepareActivity:()=>{}};
  });
  for (const file of ['agent-orb.css','agent-activity-hub-v1.css']) await page.addStyleTag({content:await fs.readFile(new URL('../'+file,import.meta.url),'utf8')});
  await page.addScriptTag({content:await fs.readFile(new URL('../agent-activity-hub-v1.js',import.meta.url),'utf8')});
  await page.evaluate(() => window.VP3AgentActivityHub.open());
  const hub=page.locator('#vp3AgentActivityHub');
  await hub.getByText('2 open',{exact:true}).waitFor();
  await hub.locator('[data-activity-group="Agent work"] > summary').click();
  const mission=hub.locator('.vp3-agent-activity-item').filter({hasText:'Research opportunities'});
  const detail=mission.locator('.vp3-agent-activity-detail');
  assert.equal(await detail.evaluate(element=>element.open),false,'Long details start collapsed');
  assert.equal(await detail.locator('p').isVisible(),false);
  assert.equal(await mission.locator('button').count(),4,'All existing actions remain available');
  assert.equal(await hub.locator('img').count(),0,'Titles remain text, including markup');
  assert.equal(await hub.getByText('Some sources are unavailable.',{exact:true}).isVisible(),true,'Source health is retained');

  for (const width of [1280,390,320]) {
    await page.setViewportSize({width,height:900});
    const layout=await hub.evaluate(element=>{
      const rect=value=>{const b=value.getBoundingClientRect();return {left:b.left,right:b.right,top:b.top,bottom:b.bottom,height:b.height};};
      const panel=element.closest('[data-agent-initiative-status]');
      return {panel:rect(panel),horizontalOverflow:panel.scrollWidth-panel.clientWidth,header:[...element.querySelector('header').children].map(rect),rows:[...element.querySelectorAll('.vp3-agent-activity-item')].map(row=>({row:rect(row),copy:rect(row.querySelector('.vp3-agent-activity-copy')),actions:rect(row.querySelector('.vp3-agent-activity-actions'))})),nestedScroll:[...element.querySelectorAll('*')].filter(child=>['auto','scroll'].includes(getComputedStyle(child).overflowY)).length};
    });
    assert.ok(layout.panel.left>=0&&layout.panel.right<=width,'Panel fits at '+width+'px');
    assert.ok(layout.horizontalOverflow<=1,'Long content cannot widen or horizontally scroll the pane at '+width+'px');
    assert.ok(layout.header.every(child=>child.top<layout.header[0].bottom&&child.bottom>layout.header[0].top),'Refresh, Details and Close share the title row at '+width+'px');
    for (const {row,copy,actions} of layout.rows) {
      assert.ok(row.height<=110,'Collapsed item stays compact at '+width+'px');
      assert.ok(actions.left>=copy.right-1&&actions.right<=row.right+1,'Actions stay beside the title at '+width+'px');
      assert.ok(Math.abs((copy.top+copy.bottom)-(actions.top+actions.bottom))<=2,'Text and actions are vertically aligned at '+width+'px');
    }
    assert.equal(layout.nestedScroll,0,'Only the outer pane scrolls');
    if (process.env.VP3_HUB_COMPACT_SCREENSHOTS) await page.screenshot({path:'/tmp/vp3-hub-compact-'+width+'.png'});
  }

  await detail.locator('summary').click();
  assert.equal(await detail.locator('p').textContent(),longDetail,'Expansion retains the complete original detail');
  const previousReads=reads;
  await hub.getByRole('button',{name:'Refresh',exact:true}).click();
  await mission.locator('strong').filter({hasText:' · '+(previousReads+1)}).waitFor();
  assert.ok(reads>previousReads);
  assert.equal(await detail.evaluate(element=>element.open),true,'Refresh preserves the deliberate expansion');
  await detail.locator('summary').click();
  await hub.getByRole('button',{name:'Open list',exact:true}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),{kind:'task_list',id:4});
  await mission.getByRole('button',{name:'Notes',exact:true}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls.at(-1)),{kind:'task_list',id:8});

  await hub.locator('[data-agent-activity-listening]').evaluate(element=>{
    const audio=document.createElement('audio');audio.id='nativeAudio';element.append(audio);window.nativeAudio=audio;
    const resume=document.createElement('button');resume.textContent='Resume listening';resume.onclick=()=>window.calls.push('listening');element.append(resume);
  });
  await hub.getByRole('button',{name:'Refresh',exact:true}).click();
  await hub.getByRole('button',{name:'Resume listening',exact:true}).click();
  assert.equal(await page.evaluate(()=>window.nativeAudio===document.getElementById('nativeAudio')),true,'Refresh keeps the native listening element');
  assert.equal(await page.evaluate(()=>window.calls.at(-1)),'listening');
  assert.deepEqual(errors,[]);
  console.log('Compact activity hub desktop/mobile layout, expansion, actions, source health and listening passed');
} finally { await browser.close(); }
