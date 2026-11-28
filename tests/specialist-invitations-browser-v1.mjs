import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtemp,readFile,rm,mkdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

const root=fileURLToPath(new URL('../',import.meta.url));
const temp=await mkdtemp(path.join(tmpdir(),'vp3-invitation-browser-'));
let browser;
try{
  const snapshotPath=path.join(temp,'native.json');
  const native=spawnSync(process.env.PHP_BINARY||process.env.VP3_PHP_BINARY||'php',['tests/specialist-invitations-pipeline-v1.php'],
    {cwd:root,encoding:'utf8',timeout:180000,env:{...process.env,VP3_INVITATION_BROWSER_SNAPSHOT:snapshotPath}});
  assert.equal(native.status,0,(native.stdout||'').slice(-6000)+(native.stderr||''));
  const snapshot=JSON.parse(await readFile(snapshotPath,'utf8'));
  const source=await readFile(path.join(root,'chat.js'),'utf8');
  // Execute the production action renderer and escaper, rather than a second renderer in the fixture.
  const pick=name=>{
    const match=source.match(new RegExp('  function '+name+'\\([^]*?\\n  }\\n'));
    assert.ok(match,'Production renderer '+name+' is present');return match[0];
  };
  browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
  const page=await browser.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.setContent('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><main><article><div class="message-text"></div><div id="actions"></div></article></main>');
  await page.addStyleTag({content:await readFile(path.join(root,'chat-media-v86.css'),'utf8')});
  await page.addStyleTag({content:'html,body{margin:0;font:15px Arial,sans-serif}main{box-sizing:border-box;width:100%;padding:16px}article{max-width:760px;margin:auto}.message-text{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.5}#actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}'});
  await page.addScriptTag({content:pick('escapeHtml')+'\n'+pick('agentActionHtml')+'\nwindow.renderInvitationAction=agentActionHtml;'});
  await page.evaluate(snapshot=>{
    document.querySelector('.message-text').textContent=snapshot.draft;
    document.getElementById('actions').innerHTML=snapshot.actions.map(window.renderInvitationAction).join('');
  },snapshot);
  assert.match(await page.locator('.message-text').innerText(),/1\. Travel arrangements[\s\S]*2\. Supplier confirmation[\s\S]*3\. Workshop schedule/);
  for(const action of snapshot.actions){
    const button=page.getByRole('button',{name:'Review: '+action.invitation_title,exact:true});
    assert.equal(await button.count(),1);assert.equal(await button.getAttribute('data-chat-email-review'),action.id);
    assert.equal(await button.getAttribute('data-chat-email-conversation'),String(action.conversation_id));
  }
  assert.equal(await page.locator('a,iframe,script[src]').count(),0);
  await page.evaluate(()=>{
    const extra=document.createElement('div');extra.id='adversarial';
    extra.innerHTML=window.renderInvitationAction({type:'email_review',id:'f'.repeat(32),conversation_id:11,
      invitation_title:'<img src=x onerror="window.invitationXss=1">'+'A'.repeat(100)});
    document.getElementById('actions').append(extra);
  });
  assert.equal(await page.locator('#adversarial img').count(),0);
  assert.equal(await page.evaluate(()=>window.invitationXss),undefined);
  for(const width of [1280,390,320]){
    await page.setViewportSize({width,height:900});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Invitation and long review labels fit '+width+'px');
    for(const button of await page.locator('#actions button').all()){
      const bounds=await button.boundingBox();assert.ok(bounds.width>80&&bounds.x>=0&&bounds.x+bounds.width<=width);
    }
    if(process.env.VP3_INVITATION_SCREENSHOT_DIR){
      await mkdir(process.env.VP3_INVITATION_SCREENSHOT_DIR,{recursive:true});
      await page.screenshot({path:path.join(process.env.VP3_INVITATION_SCREENSHOT_DIR,'specialist-invitations-'+width+'.png'),fullPage:true});
    }
  }
  assert.deepEqual(errors,[]);
  console.log('PASS native invitation worker and reply pipeline, production review buttons, exact targets, inert titles and desktop/390/320 layouts');
}finally{
  if(browser)await browser.close();await rm(temp,{recursive:true,force:true});
}
