import assert from 'node:assert/strict';
import {readFile, mkdir} from 'node:fs/promises';
import {createServer} from 'node:http';
import {createRequire} from 'node:module';
import {dirname, resolve, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const project=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const playwright=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const engine=process.env.VP3_INVENTORY_BROWSER||'chromium';
assert.ok(['chromium','webkit'].includes(engine),'Use a supported browser engine');
const defer=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return{promise,resolve};};
async function until(check,label){
    const end=Date.now()+8000;
    while(Date.now()<end){if(check())return;await new Promise(done=>setTimeout(done,10));}
    assert.fail(label);
}

// Exercise the actual page markup, CSS, and both cooperating connection scripts.
// Only PHP-generated URLs/CSRF/sidebar and remote API responses are fixtures.
let html=await readFile(join(project,'settings-homeserver.php'),'utf8');
html=html.replace(/<\?php(?:(?!\?>)[^])*workspaceSidebarActive(?:(?!\?>)[^])*\?>/g,'<aside class="chat-sidebar" aria-label="Fixture navigation">Settings</aside>');
html=html.replace(/<\?=\s*e\(url\((['"])(.*?)\1\)\)\s*\?>/g,(_,quote,url)=>url)
    .replace(/<\?=\s*e\(csrf_token\(\)\)\s*\?>/g,'fixture-csrf')
    .replace(/<\?=\s*e\(system_agent_name\(\)\)\s*\?>/g,'Fixture Agent')
    .replace(/<\?php[^]*?\?>/g,'');
const scripts=['homeserver-settings-v1210.js','homeserver-device-code-v1.js','homeserver-model-inventory-v1.js'];
html=html.replace(/<script\b[^>]*src="([^"]+)"[^>]*><\/script>/g,(tag,src)=>scripts.includes(src.split('?')[0].replace(/^\//,''))?tag:'');
assert.ok(!html.includes('<?'),'All PHP output must be substituted explicitly');
const assets=new Set([...scripts,'chat.css','account.css','homeserver-settings-v1200.css','homeserver-device-code-v1.css']);
const server=createServer(async(req,res)=>{
    const path=new URL(req.url,'http://localhost').pathname.replace(/^\//,'');
    if(!path||path==='settings-homeserver.php'){res.writeHead(200,{'Content-Type':'text/html'});res.end(html);return;}
    if(!assets.has(path)){res.writeHead(404);res.end();return;}
    try{res.writeHead(200,{'Content-Type':path.endsWith('.css')?'text/css':'text/javascript'});res.end(await readFile(join(project,path)));}
    catch{res.writeHead(500);res.end();}
});
await new Promise(done=>server.listen(0,'127.0.0.1',done));
const browser=await playwright[engine].launch({headless:true,...(engine==='chromium'?{args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined}:{})});
let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;};
const snapshot=(model='qwen:latest')=>({ok:true,checked_at:'2026-11-26T09:00:00Z',inventory:{
    version:'vp3.local-model-inventory.v1',state:'reported',configured_model:model,
    configured_model_reported:true,truncated:false,models:[{model,configured_default:true,routing_eligible:true}]
}});
try{
    for(const width of [1280,375]){
        const context=await browser.newContext({viewport:{width,height:900}});
        await context.addInitScript(()=>{window.confirm=()=>true;});
        const page=await context.newPage();page.setDefaultTimeout(8000);
        const errors=[],calls=[];page.on('pageerror',error=>errors.push(error.message));
        let status={connection_state:'connected',device_id:'hs-fixture',paired_scopes:['agent.chat'],capabilities:[],can_remove:true};
        let responseBody=snapshot(),responseStatus=200,inventoryGate=null,connectionGate=null,claimGate=null,claimCount=0;
        const gates=new Set();
        const hold=()=>{const gate=defer();gates.add(gate);return gate;};
        const release=gate=>{gate.resolve();gates.delete(gate);};
        const fulfill=async(route,payload,httpStatus=200)=>{
            try{await route.fulfill({status:httpStatus,contentType:'application/json',body:JSON.stringify(payload)});}
            catch(error){if(!/closed|disposed|cancel|abort|Invalid Interception/i.test(error.message))throw error;}
        };
        await page.route('**/api/homeserver-connection-v1200.php*',async route=>{
            const pending=connectionGate;connectionGate=null;if(pending)await pending.promise;
            const action=new URLSearchParams(route.request().postData()||'').get('action');
            if(action==='disconnect')status={...status,connection_state:'disconnected'};
            await fulfill(route,{ok:true,status});
        });
        await page.route('**/api/homeserver-device-code-v1.php',async route=>{
            claimCount++;const pending=claimGate;claimGate=null;if(pending)await pending.promise;
            await fulfill(route,{ok:true});
        });
        await page.route('**/api/homeserver-model-inventory-v1.php',async route=>{
            const request=route.request();calls.push({method:request.method(),body:request.postData()});
            const body=structuredClone(responseBody),httpStatus=responseStatus,pending=inventoryGate;inventoryGate=null;
            if(pending)await pending.promise;
            await fulfill(route,body,httpStatus);
        });
        const root=page.locator('[data-hs-model-inventory]'),refresh=root.locator('[data-inventory-refresh]');
        const list=root.locator('[data-inventory-list]'),summary=root.locator('[data-inventory-summary]');
        async function ready(){await page.waitForFunction(()=>!document.querySelector('[data-inventory-refresh]').disabled);}
        async function requestInventory(){const before=calls.length;await refresh.click();await page.waitForFunction(()=>document.querySelector('[data-hs-model-inventory]').getAttribute('aria-busy')!=='true');check(calls.length===before+1,'Explicit refresh sends one inventory request');}
        try{
            await page.goto(`http://127.0.0.1:${server.address().port}/settings-homeserver.php`);await ready();
            check(calls.length===0,'Connection discovery does not fetch model inventory automatically');
            const viewport=await page.locator('.hs-settings').evaluate(node=>{const box=node.getBoundingClientRect();return{top:box.top,bottom:box.bottom,height:box.height,view:innerHeight};});
            check(viewport.height>200&&viewport.top>=0&&viewport.top<viewport.view&&viewport.bottom<=viewport.view+1,'The settings scroll area must fit in the visible viewport');
            const providerLink=await page.locator('a[href="/settings-ai-providers.php"]').evaluate(node=>{
                const rect=node.getClientRects()[0],hit=rect&&document.elementFromPoint(rect.x+Math.min(rect.width/2,4),rect.y+rect.height/2);
                return{reachable:!!hit&&(hit===node||node.contains(hit)),overflow:document.documentElement.scrollWidth>innerWidth+1};
            });
            check(providerLink.reachable&&!providerLink.overflow,'Cloud provider settings link remains clickable without horizontal page overflow');
            if(process.env.VP3_INVENTORY_SCREENSHOTS){await mkdir(process.env.VP3_INVENTORY_SCREENSHOTS,{recursive:true});await page.screenshot({path:join(process.env.VP3_INVENTORY_SCREENSHOTS,`settings-${engine}-${width}.png`),fullPage:true});}

            const first=inventoryGate=hold();await refresh.click();
            await page.waitForFunction(()=>document.querySelector('[data-hs-model-inventory]').getAttribute('aria-busy')==='true');
            await until(()=>calls.length===1,'Inventory request reaches the API');
            check(await refresh.isDisabled(),'Refresh disables while inventory is loading');
            await refresh.evaluate(button=>button.click());check(calls.length===1,'A second refresh is suppressed while loading');
            check(calls[0].method==='POST'&&new URLSearchParams(calls[0].body).get('csrf_token')==='fixture-csrf','Inventory uses a CSRF-protected POST');
            const connection=connectionGate=hold();await page.locator('#hsRefresh').click();
            await page.waitForFunction(()=>document.querySelector('#hsConnectCard').getAttribute('aria-busy')==='true');
            release(connection);await page.waitForFunction(()=>document.querySelector('#hsConnectCard').getAttribute('aria-busy')==='false');
            check(await refresh.isDisabled(),'Connection refresh completion preserves inventory busy state');
            release(first);await ready();
            check(await list.isVisible()&&await root.locator('[data-inventory-count]').textContent()==='1','A successful inventory renders the model');
            check((await list.textContent()).includes('Not eligible for routing'),'A reported routing flag cannot change the UI eligibility claim');

            responseBody=snapshot('m'.repeat(153)+':latest');await requestInventory();
            const layout=await root.evaluate(node=>({width:node.clientWidth,scroll:node.scrollWidth,list:[...node.querySelectorAll('[data-inventory-list] strong')].map(item=>({left:item.getBoundingClientRect().left,right:item.getBoundingClientRect().right})),screen:innerWidth}));
            check(layout.scroll<=layout.width+1&&layout.list.every(item=>item.left>=0&&item.right<=layout.screen+1),'A maximum-length model name wraps without horizontal overflow');
            check(await root.evaluate(node=>[node,...node.querySelectorAll('*')].every(item=>!/(auto|scroll)/.test(getComputedStyle(item).overflowY)||item.scrollHeight<=item.clientHeight+1)),'Inventory does not create a nested scrolling surface');
            if(process.env.VP3_INVENTORY_SCREENSHOTS){await root.screenshot({path:join(process.env.VP3_INVENTORY_SCREENSHOTS,`inventory-${engine}-${width}.png`)});await page.screenshot({path:join(process.env.VP3_INVENTORY_SCREENSHOTS,`inventory-page-${engine}-${width}.png`),fullPage:true});}

            responseBody=snapshot('<img src=x onerror="window.inventoryAttack=true">');await requestInventory();
            check(await list.locator('img').count()===0&&!await page.evaluate(()=>window.inventoryAttack),'Model names remain inert text');

            responseBody={ok:false,error:'PRIVATE PROVIDER DETAIL'};responseStatus=503;await requestInventory();
            check(await list.isHidden()&&await summary.isHidden()&&!(await root.textContent()).includes('PRIVATE PROVIDER DETAIL'),'Failed refresh clears old metadata and hides provider details');
            responseStatus=200;responseBody=snapshot();
            const stale=inventoryGate=hold();await refresh.click();status={...status,device_id:'hs-replacement'};
            await page.locator('#hsRefresh').click();await page.waitForFunction(()=>document.querySelector('#hsDeviceId').textContent==='hs-replacement');
            release(stale);await ready();
            check(await list.isHidden()&&await root.locator('[data-inventory-count]').textContent()==='0','Device replacement discards an in-flight inventory');

            await requestInventory();check(await list.isVisible(),'Inventory can refresh after a device change');
            const claim=claimGate=hold();await page.locator('#hsDeviceCode').fill('ABCD-EFGH-JKLM');
            await page.locator('[data-hs-device-claim] button').click();
            await page.waitForFunction(()=>document.querySelector('[data-hs-device-claim] [role=status]').textContent.includes('Authorizing'));
            await until(()=>claimCount===1,'Device claim reaches the API');
            check(await list.isHidden()&&await summary.isHidden(),'Submitting a direct pairing code clears the previous inventory immediately');
            check(await refresh.isDisabled(),'Direct pairing in progress prevents refreshing an old pairing');
            await page.locator('#hsRefresh').click();
            await page.waitForFunction(()=>document.querySelector('#hsConnectCard').getAttribute('aria-busy')==='false');
            check(await refresh.isDisabled(),'Connection status refresh cannot enable inventory during a direct pairing claim');
            await page.locator('[data-hs-device-claim] form').evaluate(form=>form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
            await page.evaluate(()=>new Promise(done=>requestAnimationFrame(()=>requestAnimationFrame(done))));
            check(claimCount===1,'A pending direct pairing claim cannot be submitted twice');
            const beforeClaimRefresh=calls.length;await refresh.evaluate(button=>button.click());
            check(calls.length===beforeClaimRefresh,'A refresh during direct pairing cannot fetch old inventory');
            check(claimCount===1,'Direct pairing submitted through the real device-code script');release(claim);
            await page.waitForFunction(()=>document.querySelector('[data-hs-device-claim] form').hidden);
            check(await list.isHidden()&&await summary.isHidden(),'Direct pairing success does not restore the old snapshot');

            await ready();await requestInventory();const disconnect=inventoryGate=hold();await refresh.click();
            await page.locator('#hsDisconnect').click();release(disconnect);
            await page.waitForFunction(()=>document.querySelector('#hsStatePill').dataset.state==='disconnected');
            check(await list.isHidden()&&await refresh.isDisabled(),'Disconnect clears inventory and disables new refreshes');
            check(errors.length===0,`No browser script errors: ${errors.join('; ')}`);
        }catch(error){
            if(process.env.VP3_INVENTORY_SCREENSHOTS){
                await mkdir(process.env.VP3_INVENTORY_SCREENSHOTS,{recursive:true});
                await page.screenshot({path:join(process.env.VP3_INVENTORY_SCREENSHOTS,`failure-${engine}-${width}.png`),fullPage:true}).catch(()=>{});
            }
            throw error;
        }finally{for(const gate of gates)gate.resolve();await context.close();}
    }
    console.log(`HomeServer inventory ${engine} browser contract passed (${checks} checks across desktop and mobile)`);
}finally{await browser.close();await new Promise(done=>server.close(done));}
