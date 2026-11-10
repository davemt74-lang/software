import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

// Render the shipped account/coding footer markup, styles and runtime together.
// Provider responses and the enclosing member page are disposable fixtures.
const read = name => fs.readFile(new URL('../'+name,import.meta.url),'utf8');
const sidebarSource = await read('includes/main-sidebar.php');
const footer = sidebarSource.slice(sidebarSource.indexOf('<footer class="agent-sidebar-footer"'),sidebarSource.indexOf('<nav class="agent-sidebar-user-menu"'))
  .replace(/<\?=[\s\S]*?\?>/g,expression=>expression.includes('/ai-usage.php')?'/ai-usage.php':'Fixture')
  .replace(/<\?php[\s\S]*?\?>/g,'')
  .replace('data-coding-sidebar="Fixture"','data-coding-sidebar="/api/models"')
  .replace('data-coding-owner="Fixture"','data-coding-owner="1"')
  .replace('data-coding-auto-endpoint="Fixture"','data-coding-auto-endpoint="/api/auto"')
  + '<nav class="agent-sidebar-user-menu" id="vp3AgentUserMenu" hidden><a href="/account.php">Account settings</a></nav></footer>';
const assets = Object.fromEntries(await Promise.all(['agent-ui-v034.css','coding-canvas-sidebar-v1.css','agent-orb.css',
  'agent-ui-v034.js','coding-canvas-sidebar-v1.js','agent-eyes-core.js','agent-orb.js'].map(async name=>[name,await read(name)])));
const origin = 'https://sidebar-footer.test';
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const errors = [],writes = [];
let enabled=false;
try {
  const context = await browser.newContext();
  await context.route(origin+'/**',async route=>{
    const pathname=new URL(route.request().url()).pathname;
    if(assets[pathname.slice(1)])return route.fulfill({contentType:pathname.endsWith('.css')?'text/css':'text/javascript',body:assets[pathname.slice(1)]});
    if(pathname==='/api/models')return route.fulfill({json:{ok:true,state:{funding:'system',selected:null,
      default:{provider:'openai',model:'fixture',label:'System coder'},options:[{provider:'openai',model:'fixture',label:'System coder'}],unavailable:''},
      balance:{unlimited:false,package_allowance:1000000,package_remaining:650000,credits_remaining:50000,reserved:0,period_end:null}}});
    if(pathname==='/api/auto'){
      if(route.request().method()==='POST'){writes.push(route.request().postDataJSON());enabled=writes.at(-1).enabled;}
      return route.fulfill({json:{ok:true,state:{owner_user_id:1,agent_id:7,enabled,initiative_on:true,available:true,can_enable:true,revision:'a'.repeat(32),reason:''}}});
    }
    if(pathname==='/embedded')return route.fulfill({contentType:'text/html; charset=utf-8',body:`<!doctype html><meta charset="utf-8"><form id="chatForm"><textarea id="chatInput"></textarea><button>Send</button></form><script>
      window.submissions=[];window.listened=0;window.VP3_ORB_ADAPTER={ownerKey:'1',state:()=>({state:'idle'}),busy:()=>false,listen:()=>window.listened++};
      document.getElementById('chatForm').addEventListener('submit',event=>{event.preventDefault();submissions.push(document.getElementById('chatInput').value);});
      </script><script src="/agent-eyes-core.js"></script><script src="/agent-orb.js"></script>`});
    return route.fulfill({contentType:'text/html; charset=utf-8',body:`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
      <link rel="stylesheet" href="/agent-ui-v034.css"><link rel="stylesheet" href="/coding-canvas-sidebar-v1.css"><link rel="stylesheet" href="/agent-orb.css">
      <style>*{box-sizing:border-box}body{margin:0;font:14px system-ui;background:#f5f6f8}.workspace-main-sidebar{position:fixed;inset:0 auto 0 0;width:250px;height:100dvh;background:#fff;border-right:1px solid #e5e7eb;padding:12px 8px}.chat-sidebar-sections>div{height:1300px;padding:16px}main{margin-left:250px;padding:28px}.agent-sidebar-footer{min-height:0}@media(max-width:820px){.workspace-main-sidebar{width:280px;transform:translateX(-100%)}body.chat-nav-open .workspace-main-sidebar{transform:none;z-index:20000}main{margin-left:0}}</style>
      <aside id="chatSidebar" class="workspace-main-sidebar" data-csrf="fixture"><div class="chat-sidebar-sections"><div>Workspace navigation</div></div>${footer}</aside>
      <main><h1>AI usage</h1><p>Workspace content</p></main><script>window.STONEFELLOW_CHAT={userId:1,agentId:7};window.VP3_ORB_ADAPTER={ownerKey:'1',chatUrl:'/embedded',state:()=>({state:'idle'}),isMainChat:()=>false};</script>
      <script src="/agent-ui-v034.js"></script><script src="/coding-canvas-sidebar-v1.js"></script><script src="/agent-eyes-core.js"></script><script src="/agent-orb.js"></script>`});
  });
  const page = await context.newPage();page.setDefaultTimeout(10000);page.on('pageerror',error=>errors.push(error.message));
  for(const viewport of [{width:1440,height:900},{width:1024,height:650},{width:390,height:740}]){
    await page.setViewportSize(viewport);await page.goto(origin);
    await page.waitForFunction(()=>!document.getElementById('vp3CodingAgentModel').disabled);
    const bar=page.locator('.vp3-orb-bar');
    const box=await bar.boundingBox();
    assert.ok(box.x>=0&&box.x+box.width<=viewport.width&&box.y>=0&&box.y+box.height<=viewport.height,'Composer stays in the viewport.');
    assert.ok(box.width<viewport.width,'The composer is a bounded bar, without a full-width staging strip.');
    assert.equal(await bar.evaluate(node=>getComputedStyle(node).backgroundColor),'rgb(255, 255, 255)');
    assert.equal(await page.locator('[data-orb-chat],#chatAgentBriefButton').count(),0);
    assert.equal(await page.locator('[data-coding-balance]').textContent(),'650,000');
    assert.equal(await page.getByRole('link',{name:'View AI usage'}).getAttribute('href'),'/ai-usage.php');
    if(viewport.width>820){
      const sidebar=await page.locator('#chatSidebar').boundingBox();
      assert.ok(box.x>sidebar.x+sidebar.width,'The footer does not cover the sidebar controls.');
      assert.ok(Math.abs(box.x+box.width/2-(sidebar.width+(viewport.width-sidebar.width)/2))<1,'The bar centers in the usable workspace.');
    }else{
      await page.evaluate(()=>document.body.classList.add('chat-nav-open'));
      assert.equal(await bar.isVisible(),false,'The mobile drawer keeps its account controls clear.');
    }
    const accountFooter=page.locator('.agent-sidebar-footer');
    assert.equal(await accountFooter.evaluate(node=>node.scrollHeight<=node.clientHeight),true,'The account footer has no nested scroller.');
    assert.equal(await page.locator('.coding-sidebar-runtime').evaluate(node=>node.open),false);
    await page.getByRole('button',{name:/Fixture/}).click();
    assert.equal(await page.getByRole('link',{name:'Account settings'}).isVisible(),true);
    await page.keyboard.press('Escape');
    assert.equal(await page.getByRole('link',{name:'Account settings'}).isVisible(),false);
    await page.getByText('Runtime details',{exact:true}).click();
    assert.equal(await page.locator('#vp3AgentRuntimeStrip').isVisible(),true);
    await page.getByText('Runtime details',{exact:true}).click();
    await page.getByRole('checkbox',{name:'Auto Build drafts'}).setChecked(!enabled);
    await page.locator('[data-coding-auto-status]').getByText('Saved.',{exact:true}).waitFor();
    assert.equal(writes.at(-1).agent_id,7);
    assert.equal(await page.locator('#vp3CodingAgentModel').isEnabled(),true);
    const foot=await accountFooter.boundingBox();assert.ok(foot.y>=0&&foot.y+foot.height<=viewport.height,'Account controls remain accessible above the viewport bottom.');
    await page.evaluate(()=>document.body.classList.remove('chat-nav-open'));
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'The workspace has no horizontal overflow.');
    if(process.env.VP3_FOOTER_SCREENSHOTS)await page.screenshot({path:'/workspace/scratch/48e2f20316b2/footer-'+viewport.width+'.png'});
  }
  await page.locator('.vp3-orb-bar input').fill('Review my latest research');
  await page.locator('.vp3-orb-bar button[type=submit]').click();
  await page.waitForFunction(()=>document.querySelector('iframe')?.contentWindow.submissions?.length===1);
  assert.deepEqual(await page.evaluate(()=>document.querySelector('iframe').contentWindow.submissions),['Review my latest research']);
  assert.equal(await page.locator('.vp3-orb-bar input').inputValue(),'');
  await page.getByRole('button',{name:'Close Agent Chat',exact:true}).click();
  await page.locator('.vp3-orb-bar').getByRole('button',{name:'Listen',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('iframe').contentWindow.listened===1);
  assert.equal(await page.locator('iframe').count(),1,'Send and Listen share the canonical conversation.');
  assert.deepEqual(errors,[]);
  console.log('CHAT_SIDEBAR_FOOTER_BROWSER=PASS desktop/mobile layout, account menu, model/balance/Auto Build, send and Listen');
} finally {await browser.close();}
