import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {chatChoice} from './fixtures/profile-chat-choice-pa3d.mjs';

const root=fileURLToPath(new URL('../',import.meta.url));
const render=mode=>execFileSync(process.env.PHP_BINARY||'php',[root+'tests/fixtures/profile-canvases-view-v1.php',mode],{encoding:'utf8'});
const pages=Object.fromEntries(['gallery','canvas','second','owner','disabled'].map(mode=>[mode,render(mode)]));
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
check(pages.gallery.includes('data-profile-tab="canvases"')&&!pages.gallery.includes('id="publicCanvas"'),'gallery renders cards without embedding a Canvas');
check((pages.canvas.match(/id="profileAgentShell"/g)||[]).length===1&&pages.canvas.includes('class="profile-canvas-page"'),'full-page view uses exactly one production Profile Agent widget');
check(!pages.canvas.includes('class="profile-shell"')&&!pages.canvas.includes('role="tablist"')&&!pages.canvas.includes('class="profile-cover'),'full-page view has no Profile layout around the Canvas');
check(pages.owner.includes('data-profile-chat-preview')&&!pages.disabled.includes('id="profileAgentShell"'),'owner preview and existing Agent disablement remain effective');
check(pages.canvas.includes('profile-image-edits-v1.js')&&pages.canvas.includes('profile-calls-pa3c.js')&&pages.canvas.includes('profile-conversation-checkout-pa5.js'),'full-page view loads existing collection, calls and checkout capabilities');
const payload=JSON.parse(pages.canvas.match(/id="publicCanvasData">(.*?)<\/script>/s)[1]);
check(Object.keys(payload).sort().join(',')==='artifact,basePath,fullPage,sources'&&!JSON.stringify(payload).includes('FIXTURE_PROFILE_TOKEN'),'Canvas payload excludes the Profile token and visitor namespace');
if(process.argv.includes('--render-only')){
  console.log(`Profile Canvas production rendering: ${checks} checks passed.`);
  process.exit(0);
}

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const server=http.createServer((request,response)=>{
  const url=new URL(request.url,'http://localhost');
  if(/^\/[a-z0-9-]+\.(?:css|js)$/.test(url.pathname)){
    const file=root+url.pathname.slice(1);
    if(fs.existsSync(file)){response.writeHead(200,{'Content-Type':url.pathname.endsWith('.css')?'text/css':'text/javascript'});response.end(fs.readFileSync(file));return;}
  }
  const mode=url.searchParams.has('owner')?'owner':url.searchParams.has('disabled')?'disabled':
    url.pathname.endsWith('/design-notebook')?'second':url.pathname.includes('/canvas/')?'canvas':'gallery';
  response.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'});response.end(pages[mode]);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const origin=`http://127.0.0.1:${server.address().port}`;
const evidence=root+'artifacts/profile-canvases';fs.mkdirSync(evidence,{recursive:true});
let browser;
try{
  browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
  for(const viewport of [{width:1365,height:900},{width:390,height:780}]){
    const context=await browser.newContext({viewport}),page=await context.newPage();page.setDefaultTimeout(10000);
    const requests=[],errors=[],messages=[];let conversation=null;
    page.on('pageerror',error=>errors.push(error.message));
    const image_editing={contract:'vp3.profile-image-edit.v1',enabled:false,max_input_bytes:4194304,catalogs:[],reason:'No free images are shared yet.'};
    const snapshot=after=>({ok:true,agent:{name:'Taylor’s Guide',greeting:'Welcome to my studio. How can I help?'},
      conversation,chat:chatChoice({owner_name:'Taylor Reed',live_text_available:false}),handoff:null,
      messages:messages.filter(message=>message.id>Number(after||0)),attachment_max_bytes:2097152,image_editing});
    await page.route('**/api/**',async route=>{
      const request=route.request(),endpoint=new URL(request.url()),body=request.method()==='GET'?Object.fromEntries(endpoint.searchParams):request.postDataJSON();
      requests.push({path:endpoint.pathname,body});
      if(endpoint.pathname==='/api/profile-phone-v1.php'){
        assert.equal(request.method(),'GET');assert.equal(body.action,'public');assert.equal(body.username,'fixture-owner');
        return route.fulfill({json:{ok:true,contract:'vp3.profile-phone.v1',enabled:false,number:'',href:''}});
      }
      if(endpoint.pathname==='/api/profile-calls-pa3c.php')return route.fulfill({json:{ok:true,contract:'vp3.profile-call.v1',calls:[],can_offer:false,reason:''}});
      if(endpoint.pathname==='/api/profile-speech-pa4a.php')return route.fulfill({json:{ok:true,contract:'vp3.profile-speech.v1',ready:false,revision:1,presets:[]}});
      if(endpoint.pathname==='/api/profile-conversation-checkout-pa5.php')return route.fulfill({json:{ok:true,products:[],checkouts:[]}});
      assert.equal(endpoint.pathname,'/api/profile-agent.php');assert.equal(body.username,'fixture-owner');
      if(body.action==='image_collection')return route.fulfill({json:{ok:true,image_editing}});
      if(body.action==='message'){
        conversation={conversation_id:31,status:'open'};
        messages.push({id:101,sender_type:'visitor',message:body.message},{id:102,sender_type:'agent',message:'Your conversation continues across my canvases.'});
        return route.fulfill({json:{...snapshot(),accepted:true,conversation_id:31,visitor_message_id:101,client_request_id:body.client_request_id}});
      }
      return route.fulfill({json:snapshot(body.after_id)});
    });
    await page.goto(origin+'/fixture-owner#canvases');
    await page.getByRole('tab',{name:'Canvases'}).waitFor();
    check(await page.getByRole('tab',{name:'Canvases'}).getAttribute('aria-selected')==='true','gallery fragment selects Canvases on '+viewport.width+'px');
    check(await page.locator('.profile-canvas-card').count()===2&&await page.locator('iframe').count()===0,'gallery displays selectable cards on '+viewport.width+'px');
    await page.screenshot({path:evidence+`/gallery-${viewport.width}.png`,fullPage:true});
    await page.getByRole('link',{name:'Open Creative studio Canvas'}).click();
    await page.waitForURL('**/fixture-owner/canvas/creative-studio');
    const frame=page.frameLocator('.public-canvas-frame');
    await frame.getByText('Isolated Canvas',{exact:true}).waitFor();
    check(await page.locator('.public-canvas-frame').getAttribute('sandbox')==='allow-scripts','full-page runtime retains its opaque sandbox on '+viewport.width+'px');
    check(await page.evaluate(()=>{
      const rect=document.querySelector('.public-canvas-frame').getBoundingClientRect();
      return rect.x===0&&rect.y===0&&Math.abs(rect.width-innerWidth)<=1&&Math.abs(rect.height-innerHeight)<=1
        &&document.documentElement.scrollWidth<=innerWidth&&document.documentElement.scrollHeight<=innerHeight;
    }),'Canvas fills the viewport with no outer scrollbar or clipped width on '+viewport.width+'px');
    check(await page.evaluate(()=>{
      const back=document.querySelector('.profile-canvas-back').getBoundingClientRect();
      const chat=document.querySelector('[data-open-profile-agent]').getBoundingClientRect();
      return back.top>innerHeight/2&&back.right<chat.left;
    }),'Profile return control leaves the Canvas header and chat launcher clear on '+viewport.width+'px');
    await frame.getByRole('button',{name:'Explore the collection'}).click();
    await frame.getByText('You opened this Canvas as a complete page.',{exact:true}).waitFor();
    await page.screenshot({path:evidence+`/canvas-${viewport.width}.png`,fullPage:true});
    await page.getByRole('button',{name:'Ask Taylor’s Guide',exact:true}).click();
    const shell=page.locator('#profileAgentShell');await shell.waitFor({state:'visible'});
    await shell.getByRole('textbox',{name:'Message Profile Agent'}).fill('Tell me about this studio.');
    await shell.getByRole('button',{name:'Send',exact:true}).click();
    await shell.getByText('Your conversation continues across my canvases.',{exact:true}).waitFor();
    await shell.getByRole('button',{name:'Image collection',exact:true}).click();
    await shell.getByText('No free images are shared yet.',{exact:true}).waitFor();
    check(await shell.getByRole('button',{name:'Add image',exact:true}).isEnabled()&&await shell.getByRole('button',{name:'Add file',exact:true}).isEnabled(),'existing attachment controls remain reachable on '+viewport.width+'px');
    await shell.getByRole('button',{name:'Chat',exact:true}).click();
    check(await shell.evaluate(element=>{const r=element.getBoundingClientRect();return r.x>=0&&r.y>=0&&r.right<=innerWidth&&r.bottom<=innerHeight&&element.scrollWidth<=element.clientWidth;}),'chat fits inside the viewport on '+viewport.width+'px');
    await page.screenshot({path:evidence+`/canvas-chat-${viewport.width}.png`,fullPage:true});
    await shell.getByRole('button',{name:'Close Profile Agent',exact:true}).click();
    await page.getByRole('link',{name:'Back to Taylor Reed’s profile canvases'}).click();
    await page.getByRole('link',{name:'Open Design notebook Canvas'}).click();
    await page.waitForURL('**/fixture-owner/canvas/design-notebook');
    await page.frameLocator('.public-canvas-frame').getByRole('heading',{name:'Design notebook',exact:true}).waitFor();
    await page.getByRole('button',{name:'Ask Taylor’s Guide',exact:true}).click();
    await page.locator('#profileAgentShell').getByText('Your conversation continues across my canvases.',{exact:true}).waitFor();
    check(requests.filter(row=>row.body.action==='message').length===1&&requests.some(row=>row.body.action==='state'&&Number(row.body.conversation_id)===31),'moving between canvases restores the same visitor conversation on '+viewport.width+'px');
    check(requests.filter(row=>row.path==='/api/profile-agent.php'&&row.body.action!=='image_collection').every(row=>row.body.conversation_namespace==='a'.repeat(64)),'all chat requests retain the same owner namespace on '+viewport.width+'px');
    await page.close();
    const ownerPage=await context.newPage(),ownerRequests=[];
    ownerPage.on('pageerror',error=>errors.push(error.message));
    await ownerPage.route('**/api/**',route=>{ownerRequests.push(route.request().url());return route.fulfill({json:{ok:false,error:'Owner preview must not start visitor requests.'}});});
    await ownerPage.goto(origin+'/fixture-owner/canvas/creative-studio?owner=1');
    await ownerPage.frameLocator('.public-canvas-frame').getByText('Isolated Canvas',{exact:true}).waitFor();
    await ownerPage.getByRole('button',{name:'Ask Taylor’s Guide',exact:true}).click();
    const ownerComposer=ownerPage.getByRole('textbox',{name:'Describe an image to generate privately'});
    check(await ownerComposer.isEnabled()&&await ownerPage.locator('form[data-profile-chat-preview] button[type="submit"]').isDisabled()&&ownerRequests.length===0,
      'owner Canvas preview accepts a private image prompt but cannot send visitor messages without a selected reference on '+viewport.width+'px');
    await ownerComposer.fill('A private image request draft');
    check(await ownerComposer.inputValue()==='A private image request draft'&&ownerRequests.length===0,
      'owner preview image draft remains editable without creating a visitor turn on '+viewport.width+'px');
    check(errors.length===0,'no page errors on '+viewport.width+'px');
    await context.close();
  }
  console.log(`Profile Canvas gallery, full-page runtime and shared chat: ${checks} checks passed.`);
}finally{
  await browser?.close();await new Promise(resolve=>server.close(resolve));
}
