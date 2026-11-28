import assert from 'node:assert/strict';
import fs from 'node:fs';
import { chromium } from 'playwright';

const origin='https://profile.example';
const HTML='<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>*{box-sizing:border-box}body{margin:0;background:#f1f3f6;font-family:Arial,sans-serif}#profileAgentShell{margin:8px auto;background:#fff;width:min(390px,calc(100vw - 16px));height:min(650px,calc(100dvh - 16px));display:flex;flex-direction:column;overflow:hidden;border-radius:16px}.profile-agent-thread{flex:1;min-height:0;overflow:auto;display:grid;align-content:start;gap:10px;padding:12px}.profile-agent-message{min-width:0;max-width:90%;padding:9px 11px;border-radius:12px;font-size:12px;overflow-wrap:anywhere}.profile-agent-message.agent{background:#f4f6f8;justify-self:start}.profile-agent-message.visitor{justify-self:end;background:#1b262e;color:white}.profile-agent-message-speaker{display:block;margin-bottom:5px;font-size:11px}.profile-agent-compose{display:flex;flex-direction:column;gap:8px;padding:12px;border-top:1px solid #ddd}.profile-agent-toolbar{display:flex;justify-content:flex-end}.profile-agent-toolbar button{padding:10px 17px}.profile-agent-compose textarea{width:100%;min-height:48px;resize:none;font-size:16px}.profile-agent-status{font-size:11px}.profile-agent-message-body{white-space:pre-wrap}</style></head><body><div id="profileAgentShell"><section id="profileAgentMessages" class="profile-agent-thread"><div class="profile-agent-message agent"><strong>Profile Agent</strong><span>Welcome to your profile</span></div></section><form class="profile-agent-compose" data-profile-chat-preview><textarea placeholder="Select a reference image" maxlength="2000" rows="2"></textarea><div class="profile-agent-toolbar"><button type="submit" disabled>Send</button></div><div class="profile-agent-status" data-profile-agent-status></div></form></div></body></html>';
const jobId='11111111-2222-3333-4444-555555555555';
const validRef={username:'dave',catalog_id:11,catalog_revision:4,image_id:201,title:'Vacation Brain',
  preview_url:origin+'/api/profile-agent.php?action=reference_image&username=dave&catalog_id=11&image_id=201&catalog_revision=4'};
fs.mkdirSync('artifacts/profile-owner-image',{recursive:true});
const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
try{
  for(const viewport of [{width:1280,height:800},{width:390,height:760}]){
    const requests=[];
    const page=await browser.newPage({viewport});
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.route(origin+'/**',async route=>{
      const url=new URL(route.request().url());
      if(url.pathname==='/profile.php')return route.fulfill({status:200,contentType:'text/html',body:HTML});
      if(url.pathname==='/api/media-library-image-v1.php'){
        const body=route.request().postDataJSON();requests.push(body);
        assert.equal(body.owner_key,'17');assert.equal(body.csrf_token,'fixture-csrf');
        if(body.action==='preview'){
          assert.deepEqual(body.profile_reference,{username:'dave',catalog_id:11,catalog_revision:4,image_id:201});
          return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({
            ok:true,request_key:'a'.repeat(32),review_token:'4444444444.'+'a'.repeat(64),plan:body
          })});
        }
        if(body.action==='submit'){
          assert.equal(body.confirmed,true);
          return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({
            ok:true,generation:{id:jobId,status:'queued'}
          })});
        }
        if(body.action==='tick'){
          await new Promise(resolve=>setTimeout(resolve,500));
          return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({
            ok:true,generation:{id:jobId,status:'generating'}
          })});
        }
        if(body.action==='get'){
          return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({
            ok:true,generation:{id:jobId,status:'completed',asset:{id:777,title:'Generated image',mime_type:'image/png',
              url:'/media-library-file-v86.php?id=777',available:true},assets:[{id:777,title:'Generated image',mime_type:'image/png',
              url:'/media-library-file-v86.php?id=777',available:true}]}
          })});
        }
        throw new Error('Unexpected owner image action '+body.action);
      }
      if(url.pathname==='/api/profile-agent.php'||url.pathname==='/media-library-file-v86.php'){
        return route.fulfill({status:200,contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/RyUAAAAASUVORK5CYII=','base64')});
      }
      return route.fulfill({status:404,body:'unavailable'});
    });
    await page.goto(origin+'/profile.php');
    await page.addStyleTag({path:'chat-canvas-media-v1.css'});
    await page.addStyleTag({path:'profile-image-owner-preview-v1.css'});
    await page.addScriptTag({path:'profile-image-owner-preview-v1.js'});
    await page.evaluate(()=>{
      const cfg={username:'dave',agentName:'Longfellow',imageSettingsUrl:'/profile-agent.php?tab=agent',
        ownerImage:{endpoint:'/api/media-library-image-v1.php',csrf_token:'fixture-csrf',
          owner_key:'17',library_url:'/media-library.php'}};
      window.preview=window.VP3ProfileImageOwnerPreviewV1.mount({
        shell:document.querySelector('#profileAgentShell'),thread:document.querySelector('#profileAgentMessages'),
        form:document.querySelector('form'),cfg,showPanel:()=>{},notice:msg=>document.querySelector('.profile-agent-status').textContent=msg
      });
    });
    assert.equal(await page.locator('textarea').isEnabled(),true,'owner can type before selection');
    assert.equal(await page.locator('[type=submit]').isDisabled(),true,'owner preview cannot send ordinary visitor chat');
    await page.evaluate(ref=>window.preview.select(ref),validRef);
    assert.match(await page.locator('textarea').getAttribute('placeholder'),/Use this image\. Enter your image description/);
    assert.equal(await page.locator('.profile-owner-image-reference img').count(),1);
    await page.locator('textarea').fill('Turn this into a warm retro desert album cover with textured colors.');
    await page.locator('[type=submit]').click();
    await page.locator('.vp3-image-progress-frame').waitFor({state:'visible'});
    assert.equal(await page.locator('.profile-agent-message.visitor').count(),1,'prompt appears in private preview thread');
    assert.equal(await page.locator('.profile-agent-message.visitor img').count(),1,'reference thumbnail appears with prompt');
    await page.locator('[data-profile-owner-image-result="777"]').waitFor({state:'visible',timeout:15000});
    assert.equal(await page.locator('[data-profile-owner-image-result="777"] img').count(),1,'saved result appears inline');
    assert.deepEqual(requests.map(x=>x.action),['preview','submit','tick','get'],'no visitor message route or duplicated generation');
    assert.equal(await page.locator('[type=submit]').isEnabled(),true,'owner can create another image after success');
    assert.deepEqual(errors,[],'no browser exceptions');
    const bounds=await page.evaluate(()=>({
      page:document.documentElement.scrollWidth,dialog:document.querySelector('#profileAgentShell').getBoundingClientRect().right,
      width:window.innerWidth
    }));
    assert(bounds.page<=bounds.width+1&&bounds.dialog<=bounds.width+1,'no horizontal overflow '+viewport.width);
    await page.screenshot({path:'artifacts/profile-owner-image/'+viewport.width+'.png'});
    await page.close();
    console.log('PASS Profile owner reference typing, image animation, private in-chat result at '+viewport.width+'px');
  }
}finally{await browser.close();}
