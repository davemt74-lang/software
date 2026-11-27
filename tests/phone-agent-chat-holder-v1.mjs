import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {createECDH,createHash,randomBytes} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const {chromium} = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../',import.meta.url);
const html = execFileSync(process.env.PHP_BINARY || 'php',[fileURLToPath(new URL('phone-location.php',root))],{encoding:'utf8'});
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
try {
  const page = await browser.newPage({viewport:{width:375,height:550},hasTouch:true,isMobile:true});
  page.setDefaultTimeout(5000);
  const device = {id:'d'.repeat(32),name:'Field phone',status:'pending',generation:1,allow_location:false,holder_can_save_places:false};
  const turns = [], sent = [], errors = [];
  let chatReads = 0;
  const pushKey=createECDH('prime256v1');pushKey.generateKeys();
  const publicKey=pushKey.getPublicKey().toString('base64url'),keyId=createHash('sha256').update(publicKey).digest('hex');
  const pushSub={endpoint:'https://web.push.apple.com/Qsynthetic-notification-fixture',expirationTime:null,keys:{p256dh:publicKey,auth:randomBytes(16).toString('base64url')}};
  const endpointHash=createHash('sha256').update(pushSub.endpoint).digest('hex'),pushActions=[];
  const pushRefs={test:'1'.repeat(32),chat:'2'.repeat(32),requests:'3'.repeat(32),stale:'4'.repeat(32)};
  const focusedRequest='12345678-1234-1234-1234-123456789abc',focusedExchange='e'.repeat(32),resolutions=[],chatFocus=[];
  const historicPushTurn={request_id:focusedRequest,message:'Earlier private phone question',state:'replied',reply_author:'owner',reply:'The exact earlier owner reply.'};
  const pushReceipt=(event_ref,extra={})=>({event_ref,device_id:device.id,subscription_revision:device.push.revision,state:'queued',attempts:0,
    created_epoch:Math.floor(Date.now()/1000)-5,expires_epoch:Math.floor(Date.now()/1000)+600,provider_accepted_epoch:0,client_observed_epoch:0,opened_epoch:0,retry_at:0,last_code:'',...extra});
  let camera = null;
  page.on('pageerror',error => errors.push(error.message));
  await page.route('https://phone.test/**',async route => {
    const {pathname} = new URL(route.request().url());
    if (pathname === '/phone-location.php') return route.fulfill({contentType:'text/html',body:html});
    if (/^\/phone-[a-z0-9-]+\.(js|css)$/.test(pathname)) {
      return route.fulfill({contentType:pathname.endsWith('.js') ? 'text/javascript' : 'text/css',body:readFileSync(new URL(pathname.slice(1),root))});
    }
    if (pathname !== '/api/phone-location-v1.php') return route.fulfill({status:404});
    const q = route.request().postDataJSON();
    switch (q.action) {
      case 'status': return route.fulfill({json:{ok:true,device,owner_name:'Studio'}});
      case 'push_register':
        assert.equal(q.device_id,device.id);assert.equal(q.confirmed,true);assert.equal(q.key_id,keyId);assert.equal(q.subscription_revision,0);assert.deepEqual(q.subscription,pushSub);
        pushActions.push(q.action);device.push={...device.push,state:'registered',revision:1,endpoint_hash:endpointHash};
        return route.fulfill({json:{ok:true,device,owner_name:'Studio',push:device.push}});
      case 'push_disable':
        assert.equal(q.device_id,device.id);assert.equal(q.subscription_revision,1);assert.equal(q.confirmed,true);
        pushActions.push(q.action);device.push={...device.push,state:'off',revision:2};delete device.push.endpoint_hash;
        return route.fulfill({json:{ok:true,device,owner_name:'Studio',push:device.push}});
      case 'push_test':
        assert.deepEqual(Object.keys(q).sort(),['action','confirmed','device_id','request_id','subscription_revision']);
        assert.equal(q.device_id,device.id);assert.equal(q.subscription_revision,1);assert.equal(q.confirmed,true);assert.match(q.request_id,/^[a-f0-9]{32}$/);
        pushActions.push(q.action);device.push.delivery.last_test=pushReceipt(pushRefs.test);
        return route.fulfill({json:{ok:true,device,owner_name:'Studio',push:device.push,...device.push.delivery.last_test}});
      case 'push_resolve': {
        assert.deepEqual(Object.keys(q).sort(),['action','device_id','event_ref']);assert.equal(q.device_id,device.id);resolutions.push(q.event_ref);
        if(q.event_ref===pushRefs.stale)return route.fulfill({status:404,json:{ok:false,error:'Fixture private source is unavailable'}});
        const target=q.event_ref===pushRefs.chat?{tab:'chat',request_id:focusedRequest}:q.event_ref===pushRefs.requests?{tab:'requests',exchange_id:focusedExchange}:{tab:'info'};
        return route.fulfill({json:{ok:true,device,owner_name:'Studio',...pushReceipt(q.event_ref,{state:'uncertain',opened_epoch:Math.floor(Date.now()/1000)}),target}});
      }
      case 'camera_status': return route.fulfill({json:{ok:true,camera}});
      case 'camera_stop':
        assert.equal(q.session_id,camera.session_id);
        assert.equal(q.requested_only,true);
        camera = null;
        return route.fulfill({json:{ok:true,camera:null}});
      case 'chat_status':
        chatReads++;
        assert.equal(q.device_id,device.id);
        if(q.focus_request_id){assert.equal(q.focus_request_id,focusedRequest);chatFocus.push(q.focus_request_id);}
        return route.fulfill({json:{ok:true,turns:q.focus_request_id?[historicPushTurn,...turns]:turns}});
      case 'information_list': {
        assert.equal(q.device_id,device.id);const now=Math.floor(Date.now()/1000);
        const item=(exchange_id,message)=>({exchange_id,version:1,kind:'question',state:'pending',message,response:'',created_epoch:now-30,expires_epoch:now+600});
        return route.fulfill({json:{ok:true,information:{profile_id:device.communication.profile_id,profile_revision:device.communication.revision,files_ready:false,
          items:[item('f'.repeat(32),'A different current information request.'),item(focusedExchange,'The exact notified information request.')]}}});
      }
      case 'chat_send':
        assert.equal(q.device_id,device.id);
        sent.push(q);
        if (sent.length === 1) return route.abort(); // Lost before the server committed.
        if (sent.length === 2) return route.fulfill({status:503,json:{ok:false,code:'upgrade_required',error:'Do not expose arbitrary server text'}});
        const previous = turns.findIndex(turn => turn.request_id === q.request_id);
        const turn = {request_id:q.request_id,message:q.message,state:'awaiting_owner',reply:null};
        if (previous < 0) turns.push(turn);
        else turns[previous] = turn;
        return route.fulfill({json:{ok:true,turns}});
      default: return route.fulfill({status:400,json:{ok:false}});
    }
  });
  await page.goto('https://phone.test/phone-location.php');
  await page.locator('#phone-label').filter({hasText:'Field phone'}).waitFor();
  assert.equal(await page.locator('#phone-dock').isVisible(),false);
  device.status = 'approved';
  device.allow_location = true;
  const wake = () => page.evaluate(() => window.dispatchEvent(new Event('online')));
  const connection = page.getByRole('tab',{name:'Phone',exact:true});
  const chat = page.getByRole('tab',{name:'Chat',exact:true});
  const invitation = page.locator('#phone-camera');
  await wake();
  await connection.waitFor();
  assert.equal(await page.getByRole('tab',{name:'Home',exact:true}).getAttribute('aria-selected'),'true');
  assert.equal(await page.locator('#phone-chat-form').isVisible(),false);
  assert.equal(chatReads,0,'the Connection tab does not request the private transcript');
  await page.locator('#phone-user-menu summary').click();await page.locator('#phone-tab-info').click();
  await page.locator('#phone-info-title').waitFor();
  assert.equal(await page.locator('#phone-chat-form').isVisible(),false,'Info does not need the composer');
  assert.equal(chatReads,0,'Info guide never requests private Chat');
  assert.equal(await page.locator('#phone-guide-notification-state').textContent(),'Background notifications are not enabled yet.');
  // Synthetic browser API only. Never request actual browser/OS permission or contact a provider.
  await page.evaluate(({publicKey,pushSub})=>{
    let permission='default',sub=null,calls=0,unsubscribed=0;
    Object.defineProperty(navigator,'standalone',{configurable:true,value:true});
    Object.defineProperty(window,'Notification',{configurable:true,value:{get permission(){return permission;},requestPermission(){permission='granted';return Promise.resolve('granted');}}});
    if(!('PushManager' in window))window.PushManager=function(){};
    const registration={scope:location.origin+'/phone-location.php',active:{scriptURL:location.origin+'/phone-service-worker-v1.js'},pushManager:{
      getSubscription:async()=>sub,
      subscribe:options=>{calls++;if(!options.userVisibleOnly)throw Error('silent subscription');
        const base64=btoa(String.fromCharCode(...options.applicationServerKey)).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
        if(base64!==publicKey)throw Error('wrong VAPID key');permission='granted';
        sub={endpoint:pushSub.endpoint,options:{userVisibleOnly:true,applicationServerKey:options.applicationServerKey.buffer},toJSON:()=>pushSub,
          unsubscribe:async()=>{unsubscribed++;sub=null;return true;}};return Promise.resolve(sub);
      }
    }};
    Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{getRegistration:async()=>registration}});
    window.phonePushFixture={get calls(){return calls;},get unsubscribed(){return unsubscribed;}};
  },{publicKey,pushSub});
  device.push={state:'off',revision:0,enrollment_enabled:true,delivery_ready:false,key_id:keyId,application_server_key:publicKey};
  await wake();
  await connection.click();const enable=page.locator('#phone-push-enable');await enable.waitFor();
  await page.waitForFunction(()=>!document.getElementById('phone-push-enable').disabled);
  assert.equal(await page.evaluate(()=>window.phonePushFixture.calls),0,'opening Info never subscribes');
  await enable.click();assert.equal(await page.evaluate(()=>window.phonePushFixture.calls),0);await enable.click();
  await page.getByText('Notifications allowed · subscription saved.',{exact:true}).waitFor();
  assert.equal(await page.evaluate(()=>window.phonePushFixture.calls),1);
  assert.deepEqual(pushActions,['push_register']);
  assert.ok((await page.locator('#phone-push-feedback').textContent()).includes('Background delivery is not active yet.'));
  assert.equal(await page.locator('#phone-push-test').isDisabled(),true,'subscription enrollment alone does not enable delivery testing');
  device.push.delivery_ready=true;
  device.push.delivery={enabled:true,ready:true,worker:{state:'observed',last_observed_epoch:Math.floor(Date.now()/1000),transport_ready:true},last_test:null};
  await wake();
  const testPush=page.getByRole('button',{name:'Send test notification',exact:true});await testPush.waitFor();
  await page.waitForFunction(()=>!document.getElementById('phone-push-test').disabled);
  assert.deepEqual(pushActions,['push_register'],'ready status never sends automatically');
  await testPush.click();
  await page.getByText('Test notification queued. Check this device for an alert.',{exact:true}).waitFor();
  assert.deepEqual(pushActions,['push_register','push_test']);
  device.push.delivery.last_test=pushReceipt(pushRefs.test,{state:'accepted',provider_accepted_epoch:Math.floor(Date.now()/1000)});
  await wake();
  await page.getByText('The push service accepted the test. Display on this device is not confirmed.',{exact:true}).waitFor();
  device.push.delivery.last_test.client_observed_epoch=Math.floor(Date.now()/1000);
  await wake();
  await page.getByText('This installation reported showing the test notification.',{exact:true}).waitFor();
  assert.equal(resolutions.length,0,'display status never opens a private source');
  await page.locator('#phone-push-disable').click();
  await page.getByText('VP3 notification subscription turned off.',{exact:true}).waitFor();
  assert.deepEqual(pushActions,['push_register','push_test','push_disable']);
  assert.equal(await page.evaluate(()=>window.phonePushFixture.unsubscribed),1);
  assert.equal(await page.evaluate(()=>Object.values(localStorage).some(s=>s.includes('Qsynthetic-notification-fixture'))||Object.values(sessionStorage).some(s=>s.includes('Qsynthetic-notification-fixture'))),false);
  assert.equal(await connection.getAttribute('aria-selected'),'true');
  assert.equal(await page.locator('#phone-chat-form').isVisible(),false);
  // Safari pans its visual viewport independently while focusing and closing the keyboard.
  // Offsetting the page by this value used to leave the top of the phone screen blank.
  await page.evaluate(() => {
    Object.defineProperty(window.visualViewport,'offsetTop',{configurable:true,get:()=>210});
    window.visualViewport.dispatchEvent(new Event('scroll'));
  });
  const header = await page.locator('.phone-header').boundingBox();
  assert.ok(header && header.y >= 0 && header.y < 10,'Connection starts at the visible top after viewport pan');
  await page.evaluate(() => { delete window.visualViewport.offsetTop; });
  camera = {device_id:device.id,session_id:'b'.repeat(32),state:'requested',expires_at:Math.floor(Date.now()/1000)+60};
  await wake();
  await invitation.getByRole('button',{name:'Decline'}).click();
  await page.getByRole('button',{name:'Dismiss camera message'}).click();
  await invitation.waitFor({state:'hidden'});
  assert.equal(await connection.getAttribute('aria-selected'),'true','incoming requests work on Connection');
  await chat.click();
  const apps = page.getByRole('button',{name:'Phone apps'});
  await apps.click();
  assert.equal(await page.locator('#phone-app-save-place').isVisible(),false);
  await page.locator('#phone-app-camera').click();
  await page.locator('#phone-camera-close').click();
  assert.equal(await apps.getAttribute('aria-expanded'),'false');
  const input = page.locator('#phone-chat-input');
  await connection.click();await page.locator('#phone-disconnect').scrollIntoViewIfNeeded();
  assert.ok(await page.locator('#phone-disconnect').evaluate(button=>
    button.getBoundingClientRect().bottom <= innerHeight),
    'the last Connection action can be scrolled fully above the composer');
  await chat.click();await input.fill('Where is the <camera>?');
  await page.setViewportSize({width:375,height:335});
  await page.waitForFunction(() => Math.abs(document.querySelector('#phone-dock').getBoundingClientRect().bottom - 335) <= 2);
  await page.setViewportSize({width:375,height:550});
  await input.press('Enter');
  assert.equal(await chat.getAttribute('aria-selected'),'true','sending from Connection opens the conversation');
  assert.equal(await page.locator('#phone-controls').isVisible(),false);
  await page.getByRole('button',{name:'Retry delivery'}).click();
  await page.getByText('Chat needs a VP3 database upgrade. Ask the account owner to run upgrade.php, then retry.').waitFor();
  assert.equal(await page.getByText('Do not expose arbitrary server text').count(),0);
  await chat.click();
  await apps.click();
  assert.equal(await page.locator('#phone-app-camera').isEnabled(),true,'a chat failure does not disable Camera');
  await apps.click();
  await chat.click();
  await page.getByRole('button',{name:'Retry delivery'}).click();
  await page.getByText('Sent. Waiting for the owner to reply.').waitFor();
  assert.equal(sent.length,3);
  assert.ok(sent.every(q=>q.request_id===sent[0].request_id),'all attempts retain the same request ID');
  assert.ok(sent.every(q=>q.message===sent[0].message),'all attempts retain the original message');
  assert.equal(await page.locator('.phone-bubble.mine').count(),1);
  turns.push({request_id:'old-auto-turn',message:'What about earlier?',state:'replied',
    reply:'Previous agent message.',owner_reply:'A personal follow-up.'});
  await connection.click();
  await page.getByRole('tab',{name:'Home',exact:true}).focus();await page.getByRole('tab',{name:'Home',exact:true}).press('ArrowRight');
  assert.equal(await page.locator('#phone-tab-agent').getAttribute('aria-selected'),'true','keyboard navigation includes the visible AGENT approval tab');
  await page.locator('#phone-tab-agent').press('ArrowRight');
  await page.getByText('A personal follow-up.').waitFor();
  const historic = page.locator('[data-request="old-auto-turn"]');
  assert.deepEqual(await historic.locator('small').allTextContents(),['You','Agent','Owner']);
  assert.equal(await page.locator('.phone-chat-retry').count(),0);
  assert.equal(await page.locator('.phone-bubble.mine').count(),2);
  // The owner answers the earlier prompt, making its turn the latest server activity.
  const earlier = turns.shift();
  turns.push({...earlier,state:'replied',reply_author:'owner',reply:'Use the + button to open Camera.'});
  await connection.click();
  await chat.click();
  const lastTurn = page.locator('#phone-chat-turns .phone-turn').last();
  await lastTurn.getByText('Use the + button to open Camera.').waitFor();
  assert.equal(await lastTurn.locator('.phone-bubble:not(.mine) small').textContent(),'Owner');
  const footer = await page.locator('#phone-dock').boundingBox();
  assert.ok(footer && Math.abs(footer.y + footer.height - 550) <= 2,'composer must stay at viewport bottom');
  await input.fill('Unsent chat draft');
  await page.setViewportSize({width:375,height:335});
  await page.waitForFunction(() => Math.abs(document.querySelector('#phone-dock').getBoundingClientRect().bottom - 335) <= 2);
  await page.setViewportSize({width:375,height:550});
  await chat.focus();
  await chat.press('Home');
  assert.equal(await page.getByRole('tab',{name:'Home',exact:true}).getAttribute('aria-selected'),'true');
  assert.equal(await input.isVisible(),false);
  assert.equal(await input.inputValue(),'Unsent chat draft');
  await connection.press('End');
  assert.equal(await connection.getAttribute('aria-selected'),'true');
  await page.locator('#phone-user-menu summary').click();const info=page.locator('#phone-tab-info');await info.click();
  assert.equal(await page.locator('#phone-info').isVisible(),true);
  await chat.click();
  assert.equal(await chat.getAttribute('aria-selected'),'true');
  assert.equal(await input.inputValue(),'Unsent chat draft');
  await input.focus();
  camera = {device_id:device.id,session_id:'a'.repeat(32),state:'requested',expires_at:Math.floor(Date.now()/1000)+60};
  await wake();
  await invitation.getByText(/Studio requested this phone’s camera/).waitFor();
  assert.ok(await input.evaluate(el => el === document.activeElement),'incoming request preserves keyboard focus');
  assert.equal(await input.inputValue(),'Unsent chat draft');
  const accept = await invitation.locator('#phone-camera-start').boundingBox();
  assert.ok(accept && accept.y >= 0 && accept.y + accept.height < 550,'the accept action is visible without scrolling');
  await invitation.getByRole('button',{name:'Decline'}).click();
  await invitation.getByText('Camera request declined.').waitFor();
  await invitation.getByRole('button',{name:'Dismiss camera message'}).click();
  await invitation.waitFor({state:'hidden'});
  device.status = 'revoked';
  await wake();
  await page.locator('#phone-dock').waitFor({state:'hidden'});
  assert.equal(await page.locator('.phone-bubble').count(),0);
  assert.equal(await page.locator('#phone-chat-input').inputValue(),'');
  assert.equal(await chat.isVisible(),false);
  await page.locator('#phone-connection').waitFor();
  // A notification carries only its reference. The normal authenticated holder
  // API chooses a fixed destination and the foreground UI reads the private source.
  device.status='approved';device.push={...device.push,state:'registered',revision:3,endpoint_hash:endpointHash};
  device.communication={profile_id:'c'.repeat(32),revision:2,request_permission_saved:true,information_pending:2};
  await page.goto('https://phone.test/phone-location.php?push_event='+pushRefs.chat);
  const notifiedTurn=page.locator('.phone-turn[data-request="'+focusedRequest+'"]');
  await notifiedTurn.getByText(historicPushTurn.reply,{exact:true}).waitFor();
  assert.equal(await chat.getAttribute('aria-selected'),'true');
  assert.ok(chatFocus.includes(focusedRequest),'tap hydration asks for the exact older canonical UUID');
  await page.waitForFunction(id=>document.activeElement?.dataset.request===id,focusedRequest);
  assert.equal(new URL(page.url()).searchParams.has('push_event'),false,'opaque tap reference is removed from the live address');
  await page.goto('https://phone.test/phone-location.php?push_event='+pushRefs.requests);
  const notifiedCard=page.locator('.phone-information-item[data-exchange-id="'+focusedExchange+'"]');
  await notifiedCard.getByText('The exact notified information request.',{exact:true}).waitFor();
  assert.equal(await page.getByRole('tab',{name:'Requests',exact:true}).getAttribute('aria-selected'),'true');
  assert.equal(await page.locator('#phone-information').evaluate(section=>section.open),true);
  await page.waitForFunction(id=>document.activeElement?.dataset.exchangeId===id,focusedExchange);
  assert.equal(await page.locator('.phone-information-item').count(),2,'only the notified card receives focus within the current inbox');
  await page.goto('https://phone.test/phone-location.php?push_event='+pushRefs.test);
  await page.getByText('Notification opened in VP3 Phone.',{exact:true}).waitFor();
  assert.equal(await connection.getAttribute('aria-selected'),'true');
  const readsBeforeUnavailable=chatReads;
  await page.goto('https://phone.test/phone-location.php?push_event='+pushRefs.stale);
  await page.getByText('This notification is no longer available. Review the current connection in VP3 Phone.',{exact:true}).waitFor();
  assert.equal(await connection.getAttribute('aria-selected'),'true','an unavailable notification opens Phone settings');
  assert.equal(await page.locator('#phone-chat-form').isVisible(),false);
  assert.equal(await page.locator('.phone-bubble').count(),0);
  assert.equal(chatReads,readsBeforeUnavailable,'a stale tap never requests private Chat');
  assert.deepEqual(resolutions,[pushRefs.chat,pushRefs.requests,pushRefs.test,pushRefs.stale]);
  assert.equal(await page.getByText('Fixture private source is unavailable',{exact:true}).count(),0);
  assert.deepEqual(errors,[]);
  console.log('PASS shared mobile composer, enrollment and delivery test status, exact authenticated notification destinations, private owner replies, retry, draft retention and revocation.');
} finally {
  await browser.close();
}
