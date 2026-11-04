import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import {chatChoice,choiceMarkup} from './fixtures/profile-chat-choice-pa3d.mjs';
import {callCard,contract as callContract,installFakeMedia} from './fixtures/profile-calls-pa3c.mjs';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const read=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8');
const checkoutEndpoint='/api/profile-conversation-checkout-pa5.php';
const checkoutContract='vp3.profile-conversation-checkout.v1';
const callEndpoint='/api/profile-calls-pa3c.php';
const agentEndpoint='/api/profile-agent.php';
const username='fixture-owner',namespace='a'.repeat(64),profileToken='FIXTURE_PROFILE_TOKEN';
const storageKey=`stonefellow-profile-agent:v1:${username}:${namespace}`;
const checkoutUrl='https://checkout.stripe.com/c/pay_pa5_browser_fixture';
const receiptUrl=`/${username}/order/PA5-7/${'e'.repeat(64)}`;
const deliveryUrl=receiptUrl+'#private-delivery';
const payerEmail='buyer@example.com';
const policy='The seller accepts returns within 30 days. '+('Keep the original order number for this purchase. '.repeat(6))+'<img src=x onerror="window.__termsExecuted=true">';
const stripe={connection_id:3,provider:'stripe',provider_label:'Stripe'};
const product={product_id:7,slug:'field-guide',title:'Field guide',description:'Download the public field guide.',price_cents:2500,price_label:'USD 25.00',currency:'usd',fulfillment_type:'digital',product_url:`/${username}/product/field-guide`,payment_providers:[stripe]};

const assets=new Map(['profile-agent.js','profile.css','profile-calls-pa3c.js','profile-calls-pa3c.css','voice-lease-v122.js','profile-conversation-checkout-pa5.js'].map(name=>['/'+name,read(name)]));
const profileConfig={username,ownerName:'Human Owner',callOwnerName:'Human Owner',endpoint:agentEndpoint,callEndpoint,checkoutEndpoint,checkoutAvailable:true,conversationNamespace:namespace,profileToken};
const profileSource=read('profile.php');
const launcherScript=profileSource.match(/const profileAgentShell=.*?(?=const webmcpResumeBox=)/s)?.[0];
assert.ok(launcherScript,'fixture finds the real public Profile launcher behavior');
const markup=`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Public Profile checkout</title><link rel="stylesheet" href="/profile.css"><link rel="stylesheet" href="/profile-calls-pa3c.css"><style>body{margin:0}#chatInput{display:none}</style></head><body class="profile-page">${choiceMarkup.replace('<section id="profileAgentShell"','<section hidden id="profileAgentShell"')}<button type="button" data-open-profile-agent aria-controls="profileAgentShell" aria-expanded="false">Ask Profile Guide</button><script>window.STONEFELLOW_PROFILE_AGENT=${JSON.stringify(profileConfig)};${launcherScript}</script><script data-voice-capture-scope="guest" src="/voice-lease-v122.js"></script><script src="/profile-calls-pa3c.js"></script><script src="/profile-conversation-checkout-pa5.js"></script><script src="/profile-agent.js"></script></body></html>`;
const server=http.createServer((request,response)=>{
  const path=new URL(request.url,'http://localhost').pathname;
  if(assets.has(path)){
    response.writeHead(200,{'Content-Type':path.endsWith('.css')?'text/css':'text/javascript'});
    response.end(assets.get(path));
  }else{
    response.writeHead(200,{'Content-Type':'text/html'});
    response.end(markup);
  }
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base=`http://127.0.0.1:${server.address().port}`;
let browser,checks=0;
function check(value,label){assert.ok(value,label);checks++;console.log('PASS '+label);}

function orderReview(review,key='payment_pending',urls={}){
  const status={
    payment_pending:{label:'Awaiting payment',message:'The payment provider has not confirmed payment.'},
    payment_review:{label:'Payment verified, review required',message:'The seller must review fulfillment.'},
    fulfilled:{label:'Order fulfilled',message:'Your verified purchase is ready to download.'}
  }[key];
  return {...review,state:key==='payment_pending'?'checkout_ready':'order',
    order:{order_number:'PA5-7',status:{key,...status}},
    checkout_url:key==='payment_pending'?checkoutUrl:'',receipt_url:key==='payment_pending'?'':receiptUrl,
    delivery_url:key==='fulfilled'?deliveryUrl:'',...urls};
}

async function fixture({viewport={width:1160,height:900},withThread=false,losePrepare=false}={}){
  const context=await browser.newContext({viewport});
  await context.addInitScript({content:`(${installFakeMedia.toString()})(window); HTMLMediaElement.prototype.play=function(){return Promise.resolve();};`});
  if(withThread){
    await context.addInitScript(key=>{if(!localStorage.getItem(key))localStorage.setItem(key,'31');},storageKey);
  }
  const requests=[],errors=[],holds=[],messages=[],reviews=new Map(),prepared=new Map();
  let conversation=withThread?31:0,turnId=20,messageId=100,ownerText='none',liveCall=null;
  let handoff=null,agentProduct=false,failConfirm=null,orderCreates=0;
  function snapshot(after=0){
    const callActive=liveCall?.state==='ready';
    const joined=callActive||ownerText==='accepted';
    return {conversation:conversation?{conversation_id:conversation,status:joined?'owner_joined':'open'}:null,
      agent:{name:'Profile Guide',greeting:'Ask about this profile.'},
      chat:chatChoice({mode:callActive?'auto':ownerText==='none'?'agent':'live',owner_name:'Human Owner',live_status:ownerText,can_end_live_chat:ownerText==='accepted'||ownerText==='waiting',media_call_active:callActive}),
      handoff,messages:messages.filter(message=>message.id>Number(after)),checkout_products:agentProduct?[product]:[]};
  }
  const pushMessage=(sender_type,message)=>messages.push({id:++messageId,sender_type,message});
  const reply=(route,data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify({ok:status<400,...data})});
  await context.route('https://checkout.stripe.com/**',route=>route.fulfill({contentType:'text/html',body:'<title>Provider checkout fixture</title><p>Provider checkout fixture</p>'}));
  await context.route('**/api/**',async route=>{
    const request=route.request(),url=new URL(request.url());
    const body=request.method()==='GET'?Object.fromEntries(url.searchParams):request.postDataJSON();
    requests.push({path:url.pathname,method:request.method(),body});
    if(url.pathname===agentEndpoint){
      if(['state','poll'].includes(body.action)){
        const requested=Number(body.conversation_id);
        if(requested&&requested!==conversation)return reply(route,{code:'PROFILE_CONVERSATION_UNAVAILABLE',error:'Conversation unavailable.'},404);
        const projection=snapshot(body.after_id||0);
        return reply(route,requested?projection:{...projection,conversation:null,handoff:null,messages:[]});
      }
      if(body.action==='message'){
        conversation=31;
        pushMessage('visitor',body.message);
        if(body.message==='Can I buy the field guide?')agentProduct=true;
        if(body.chat_mode==='live'&&ownerText!=='accepted')ownerText='waiting';
        const dispatch=liveCall||ownerText==='accepted'?'owner_claimed':ownerText==='waiting'?'awaiting_owner':'agent_ready';
        handoff={turn_id:++turnId,revision:1,conversation_id:31,state:dispatch,dispatch_state:dispatch,dispatch_turn_id:turnId,
          server_now_ms:Date.now(),pickup_deadline_ms:ownerText==='waiting'?Date.now()+15000:null};
        return reply(route,{...snapshot(),conversation_id:31,client_request_id:body.client_request_id,visitor_message_id:messageId});
      }
      if(body.action==='dispatch'){
        pushMessage('agent',agentProduct?'The published field guide is available.':'Here is the Agent response.');
        handoff={...handoff,revision:handoff.revision+1,state:'replied',dispatch_state:'none'};
        return reply(route,{...snapshot(),conversation_id:31,turn_id:body.turn_id});
      }
      if(body.action==='end_live_chat'){
        ownerText='none';
        handoff={...handoff,revision:handoff.revision+1,state:'replied',dispatch_state:'none'};
        return reply(route,snapshot());
      }
    }
    if(url.pathname===callEndpoint){
      if(body.action==='visitor_state')return reply(route,{contract:callContract,conversation_id:Number(body.conversation_id),calls:liveCall?[liveCall]:[],can_offer:!liveCall,reason:'',server_now_ms:Date.now()});
      if(body.action==='visitor_join'||body.action==='visitor_heartbeat'){
        const now=Date.now();
        liveCall.server_now_ms=now;liveCall.authority_valid_until_ms=now+30000;
        return reply(route,{contract:callContract,call:liveCall,call_id:41,revision:liveCall.revision,conversation_id:31,
          role:'visitor',request_id:body.request_id,client_instance_id:body.client_instance_id,join_intent_id:'b'.repeat(32),server_url:'wss://fixture.livekit.cloud',
          participant_token:'FIXTURE_VISITOR_TOKEN_'+'x'.repeat(40),participant_identity:'visitor-41',peer_identity:'owner-41',expires_at_ms:now+60000,microphone:true,camera:false});
      }
    }
    if(url.pathname===checkoutEndpoint){
      const envelope={contract:checkoutContract,conversation_id:Number(body.conversation_id)};
      if(body.action==='catalog')return reply(route,{...envelope,products:[product]});
      if(body.action==='status')return reply(route,{...envelope,reviews:body.conversation_id===31?[...reviews.values()]:[]});
      if(body.action==='prepare'){
        conversation=31;
        let review=prepared.get(body.request_id);
        if(!review){
          const quantity=Number(body.quantity),reviewId=(prepared.size+1).toString(16).padStart(32,'0');
          review={review_id:reviewId,state:'prepared',expires_at_unix:Math.floor(Date.now()/1000)+600,product_slug:product.slug,title:product.title,
            quantity,unit_price_cents:2500,unit_price_label:'USD 25.00',charges_cents:0,charges_label:'USD 0.00',total_cents:2500*quantity,
            total_label:`USD ${(25*quantity).toFixed(2)}`,currency:'usd',fulfillment_type:'digital',payer_email:body.payer_email,
            terms:{hold_minutes:30,refund_before_hours:24,cancellation_fee_cents:500,cancellation_fee_label:'USD 5.00',cancellation_policy:policy},payment_provider:stripe};
          prepared.set(body.request_id,review);reviews.set(reviewId,review);
        }
        if(losePrepare){losePrepare=false;return route.abort('failed');}
        return reply(route,{...envelope,conversation_id:31,review});
      }
      if(body.action==='confirm'){
        const stored=reviews.get(body.review_id);
        if(!stored||body.terms_accepted!==true)return reply(route,{...envelope,code:'PROFILE_CHECKOUT_REVIEW_REQUIRED',error:'Review required.'},422);
        if(failConfirm){const fail=failConfirm;failConfirm=null;return fail({route,stored,envelope,reply});}
        const held=holds.shift();
        const abort=held&&await new Promise(resolve=>held({body,release:resolve}));
        const old=reviews.get(body.review_id);
        if(abort==='before_commit')return route.abort('failed');
        if(old.state==='prepared'){
          reviews.set(body.review_id,orderReview(old));orderCreates++;
          if(abort==='provider_pending'){
            reviews.set(body.review_id,{...reviews.get(body.review_id),state:'checkout_pending',checkout_url:''});
            return reply(route,{...envelope,code:'PROFILE_CHECKOUT_RETRY',error:'Checkout is still pending.'},503);
          }
        }else if(old.state==='checkout_pending')reviews.set(body.review_id,orderReview(old));
        if(abort==='after_commit')return route.abort('failed');
        return reply(route,{...envelope,review:reviews.get(body.review_id)});
      }
    }
    return reply(route,{code:'UNEXPECTED_TEST_REQUEST',error:`Unexpected request ${url.pathname} ${body?.action||''}`},422);
  });
  const page=await context.newPage();
  page.setDefaultTimeout(10000);
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto(`${base}/${username}`);
  const launcher=page.locator('[data-open-profile-agent]');
  await launcher.click();
  const textarea=page.getByRole('textbox',{name:'Visitor message'});
  const host=page.locator('[data-profile-checkout-host]');
  const review=host.locator('[data-profile-checkout-review]');
  const order=host.locator('[data-profile-checkout-order]');
  return {page,context,requests,reviews,host,review,order,textarea,launcher,errors,
    get checkout(){return requests.filter(request=>request.path===checkoutEndpoint).map(request=>request.body);},
    get orderCreates(){return orderCreates;},
    async send(message){
      await textarea.fill(message);
      const reply=page.waitForResponse(response=>response.url().endsWith(agentEndpoint)&&response.request().postDataJSON()?.action==='message');
      await page.locator('.profile-agent-compose').getByRole('button',{name:'Send',exact:true}).click();
      await reply;
    },
    async browse(){await host.getByRole('button',{name:'Browse products',exact:true}).click();await host.locator('[data-profile-checkout-product]').first().waitFor();},
    async prepare(quantity='2',{lost=false}={}){
      const card=host.locator('[data-profile-checkout-product]').filter({hasText:product.title});
      await card.locator('input[type=number]').fill(quantity);
      await card.locator('input[type=email]').fill(payerEmail);
      const isPrepare=request=>request.url().endsWith(checkoutEndpoint)&&request.postDataJSON()?.action==='prepare';
      const saved=lost?page.waitForEvent('requestfailed',isPrepare):page.waitForResponse(response=>isPrepare(response.request()));
      await card.getByRole('button',{name:'Review checkout',exact:true}).click();
      if(lost){await saved;return null;}
      const result=await (await saved).json();
      assert.match(result.review?.review_id||'',/^[a-f0-9]{32}$/);
      const exact=host.locator(`[data-profile-checkout-review="${result.review.review_id}"]`);
      await exact.waitFor();
      return exact;
    },
    holdConfirm:()=>new Promise(resolve=>holds.push(resolve)),
    failNextConfirm:failure=>{failConfirm=failure;},
    acceptText(){
      ownerText='accepted';
      handoff={...handoff,revision:handoff.revision+1,state:'owner_claimed',dispatch_state:'owner_claimed'};
      pushMessage('owner','I can help while you check out.');
    },
    activateCall(){
      liveCall=callCard('visitor',{mode:'audio',state:'ready',revision:2,server_now_ms:Date.now(),authority_valid_until_ms:Date.now()+30000,
        can_accept:false,can_decline:false,can_end:true,can_join:true});
    },
    updateOrder(key,urls){for(const [id,saved] of reviews)if(saved.order)reviews.set(id,orderReview(saved,key,urls));},
    async refresh(){await page.evaluate(()=>{window.dispatchEvent(new CustomEvent('vp3:profile-agent-open'));window.dispatchEvent(new CustomEvent('vp3:profile-agent-visibility',{detail:{open:true}}));});},
    async close(){check(errors.length===0,'the real browser scripts emitted no errors'+(errors.length?': '+errors.join(', '):''));await context.close();}
  };
}

try{
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
  // Route-controlled responses avoid a live merchant or payment provider.
  {
    const f=await fixture(),{page,host,review,order}=f;
    await f.send('Can I buy the field guide?');
    await page.getByText('The published field guide is available.',{exact:true}).waitFor();
    const productCard=host.locator('[data-profile-checkout-product]');
    await productCard.waitFor();
    check(await page.locator('[data-profile-agent-thread]').locator('[data-profile-checkout-product]').count()===1,'ordinary Agent chat shows one current product selection in the conversation');
    await f.prepare();
    const prepare=f.checkout.find(request=>request.action==='prepare');
    check(prepare.conversation_id===31&&prepare.product_slug===product.slug&&prepare.quantity===2&&prepare.connection_id===3&&prepare.payer_email===payerEmail&&/^[a-f0-9]{32}$/.test(prepare.request_id),'review requests bind the selected product, quantity and provider to the current thread');
    check(prepare.username===username&&prepare.conversation_namespace===namespace&&prepare.profile_token===profileToken,'checkout uses the exact same native Profile identity envelope');
    const button=review.getByRole('button',{name:/Confirm checkout|Retry.*checkout/i});
    const terms=review.locator('[data-profile-checkout-terms]');
    const reviewText=await review.innerText();
    check(reviewText.includes(product.title)&&reviewText.includes('USD 25.00')&&reviewText.includes('USD 0.00')&&reviewText.includes('USD 50.00')&&reviewText.includes('Stripe'),'review shows canonical product, unit price, charges, total and provider');
    check(reviewText.includes(policy.trim())&&reviewText.includes('USD 5.00')&&await review.locator('img').count()===0,'full seller terms display as inert text with the cancellation fee');
    check(await button.isDisabled(),'review requires explicit acceptance of the displayed seller terms');
    await f.send('Yes, I accept.');
    check(!f.checkout.some(request=>request.action==='confirm')&&await button.isDisabled(),'ordinary Agent text cannot accept or confirm a purchase');
    await terms.check();
    check(!f.checkout.some(request=>request.action==='confirm')&&await button.isEnabled(),'checking terms enables review confirmation without submitting it');
    const pending=f.holdConfirm();
    await button.click();
    const first=await pending;
    await button.dispatchEvent('click');
    check(await button.isDisabled()&&f.checkout.filter(request=>request.action==='confirm').length===1,'busy review rejects duplicate activation while checkout is pending');
    check(first.body.review_id===[...f.reviews.keys()][0]&&first.body.terms_accepted===true&&first.body.conversation_id===31,'explicit confirmation sends the exact displayed review and visitor thread');
    first.release('before_commit');
    await page.waitForFunction(()=>[...document.querySelectorAll('[data-profile-checkout-review] button')].some(button=>/Confirm checkout|Retry.*checkout/i.test(button.textContent)&&!button.disabled));
    await button.click();
    await order.waitFor();
    const confirmations=f.checkout.filter(request=>request.action==='confirm');
    check(confirmations.length===2&&confirmations[0].review_id===confirmations[1].review_id&&f.orderCreates===1,'lost confirmation response retries the same exact review and creates a single fixture checkout');
    const pay=order.getByRole('link',{name:'Continue to payment',exact:true});
    check((await order.innerText()).includes('Awaiting payment')&&await pay.getAttribute('href')===checkoutUrl&&await order.getByRole('link',{name:'View receipt',exact:true}).count()===0,'checkout handoff identifies pending payment and shows no premature receipt');
    check(await pay.getAttribute('target')==='_blank'&&(await pay.getAttribute('rel')).includes('noopener'),'provider handoff opens in a new isolated tab');
    const [provider]=await Promise.all([page.waitForEvent('popup'),pay.click()]);
    await provider.getByText('Provider checkout fixture',{exact:true}).waitFor();
    check(await order.isVisible()&&new URL(page.url()).origin===base&&provider.url()===checkoutUrl,'provider opens while the original checkout and conversation remain in place');
    await provider.close();
    await page.evaluate(key=>localStorage.removeItem(key),storageKey);
    await page.goto(`${base}/${username}?profile_checkout=31&paid=1`);
    await order.waitFor();
    check((await order.innerText()).includes('Awaiting payment')&&await order.getByRole('link',{name:/delivery/i}).count()===0,'reload and a claimed browser return still show the server pending status');
    check(await page.getByText('Yes, I accept.',{exact:true}).count()===1&&f.orderCreates===1&&await f.launcher.getAttribute('aria-expanded')==='true'&&await page.evaluate(key=>localStorage.getItem(key),storageKey)==='31'&&!new URL(page.url()).searchParams.has('profile_checkout'),'authorized provider return opens the original thread and removes the one-use URL hint');
    f.updateOrder('payment_review');
    await order.getByRole('button',{name:'Refresh payment status',exact:true}).click();
    await order.getByText('Payment verified, review required',{exact:true}).waitFor();
    check(await order.getByRole('link',{name:'Continue to payment',exact:true}).count()===0&&await order.getByRole('link',{name:'View receipt',exact:true}).count()===1,'verified payment review allows the receipt without another payment or delivery');
    f.updateOrder('fulfilled');
    await order.getByRole('button',{name:'Refresh payment status',exact:true}).click();
    await order.getByText('Order fulfilled',{exact:true}).waitFor();
    const receipt=order.getByRole('link',{name:'View receipt',exact:true}),delivery=order.getByRole('link',{name:/delivery/i});
    check(new URL(await receipt.getAttribute('href'),base).href===base+receiptUrl&&new URL(await delivery.getAttribute('href'),base).href===base+deliveryUrl,'verified fulfillment exposes scoped receipt and secure delivery destinations');
    const foreignReturn=page.waitForResponse(response=>response.status()===404&&new URL(response.url()).searchParams.get('conversation_id')==='32');
    await page.goto(`${base}/${username}?profile_checkout=32`);
    await foreignReturn;
    await f.launcher.click();
    await order.getByText('Order fulfilled',{exact:true}).waitFor();
    check(await page.evaluate(key=>localStorage.getItem(key),storageKey)==='31'&&!f.checkout.some(request=>request.conversation_id===32),'unauthorized return hint cannot replace the saved visitor conversation or expose another order');
    await f.close();
  }
  {
    const f=await fixture({viewport:{width:360,height:760},losePrepare:true}),{page,host,review,order}=f;
    await f.browse();
    check(f.checkout.some(request=>request.action==='catalog'&&request.conversation_id===0)&&!f.checkout.some(request=>request.action==='prepare'),'mobile catalog is available before the first thread without preparing a purchase');
    await f.prepare('1',{lost:true});
    await host.getByText(/This review may already be saved/).waitFor();
    const attempt=await page.evaluate(key=>sessionStorage.getItem(key+':checkout-prepare'),storageKey);
    check(attempt&&/^[a-f0-9]{32}$/.test(JSON.parse(attempt).request_id)&&!attempt.includes(payerEmail),'uncertain review persists only its opaque retry identity and selection across reload');
    await page.reload();
    await f.launcher.click();
    await f.browse();
    await f.prepare('1');
    const preparations=f.checkout.filter(request=>request.action==='prepare');
    check(preparations.length===2&&preparations.every(request=>request.request_id===JSON.parse(attempt).request_id)&&f.reviews.size===1,'reloading after a lost first response recovers one exact prepared review');
    check(f.checkout.some(request=>request.action==='prepare'&&request.conversation_id===0)&&await page.evaluate(key=>localStorage.getItem(key),storageKey)==='31','checkout-first mobile visitor adopts the server-assigned thread');
    const bounds=await review.boundingBox();
    check(bounds&&bounds.x>=0&&bounds.x+bounds.width<=361&&await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'real checkout review fits a 360px mobile screen');
    check(await host.evaluate(node=>[node,...node.querySelectorAll('*')].every(element=>!['auto','scroll'].includes(getComputedStyle(element).overflowY))),'checkout cards add no nested scrolling area to the conversation');
    check(await host.locator('input[type=email]').evaluate(input=>parseFloat(getComputedStyle(input).fontSize)>=16),'email field avoids mobile input zoom');
    const live=page.locator('[data-profile-live-chat]');
    await live.check();
    await f.send('Can the owner help me?');
    await page.getByRole('button',{name:'Cancel live request',exact:true}).waitFor();
    f.acceptText();
    await f.refresh();
    await page.getByText('I can help while you check out.',{exact:true}).waitFor();
    check(await review.count()===1&&await review.locator('[data-profile-checkout-terms]').isEnabled(),'accepted owner text and its attribution coexist with the exact purchase review');
    const firstId=[...f.reviews.keys()][0];
    f.failNextConfirm(({route,stored,envelope,reply})=>{
      const expired={...stored,state:'expired'};
      f.reviews.set(firstId,expired);
      return reply(route,{...envelope,code:'PROFILE_CHECKOUT_TERMS_CHANGED',error:'The checkout terms changed. Request a new review.'},409);
    });
    await review.locator('[data-profile-checkout-terms]').check();
    await review.getByRole('button',{name:'Confirm checkout',exact:true}).click();
    await review.getByRole('button',{name:'Review current offer',exact:true}).waitFor();
    check(f.checkout.filter(request=>request.action==='confirm').length===1&&f.orderCreates===0,'changed terms cannot start the provider or complete the original review');
    const replacement=await f.prepare('1');
    const replacementTerms=replacement.locator('[data-profile-checkout-terms]');
    const confirm=replacement.getByRole('button',{name:'Confirm checkout',exact:true});
    check(!await replacementTerms.isChecked()&&await confirm.isDisabled()&&f.reviews.size===2,'replacement review requires its own explicit acceptance');
    await replacementTerms.check();
    const pending=f.holdConfirm();
    await confirm.click();
    const work=await pending;
    check(work.body.review_id!==firstId,'fresh confirmation targets the new server review ID');
    const lost=page.waitForEvent('requestfailed',request=>request.url().endsWith(checkoutEndpoint)&&request.postDataJSON()?.action==='confirm');
    work.release('after_commit');
    await lost;
    await page.reload();
    await f.launcher.click();
    await order.waitFor();
    check((await order.innerText()).includes('Awaiting payment')&&f.orderCreates===1,'server restores a recorded checkout after its browser response is lost');
    check(await page.getByText('I can help while you check out.',{exact:true}).count()===1,'reloading a committed checkout preserves the live owner message');
    await f.close();
  }
  {
    const f=await fixture({withThread:true,viewport:{width:390,height:844}}),{page,review,order}=f;
    await f.browse();
    await f.prepare('1');
    f.activateCall();
    await f.refresh();
    const call=page.locator('.profile-call-card');
    await call.getByRole('button',{name:'Join call',exact:true}).waitFor();
    await call.locator('.profile-call-consent input').first().check();
    await call.getByRole('button',{name:'Join call',exact:true}).click();
    await page.waitForFunction(()=>__callMediaFixture.microphones===1);
    await page.evaluate(()=>__callMediaFixture.rooms[0].addPeer());
    await call.locator('audio').waitFor({state:'attached'});
    await review.locator('[data-profile-checkout-terms]').check();
    const pending=f.holdConfirm();
    await review.getByRole('button',{name:'Confirm checkout',exact:true}).click();
    const work=await pending;
    work.release('provider_pending');
    const retry=order.getByRole('button',{name:'Retry this checkout',exact:true});
    await retry.waitFor();
    check(f.orderCreates===1&&await order.getByRole('link',{name:'Continue to payment',exact:true}).count()===0,'uncertain provider creation restores the recorded order with an explicit retry control');
    await retry.click();
    await order.getByRole('link',{name:'Continue to payment',exact:true}).waitFor();
    const confirmations=f.checkout.filter(request=>request.action==='confirm');
    check(confirmations.length===2&&confirmations.every(request=>request.review_id===work.body.review_id)&&f.orderCreates===1,'browser resumes the pending provider attempt with the same confirmed review');
    await f.send('I can still hear you during checkout.');
    const text=f.requests.filter(request=>request.path===agentEndpoint&&request.body.action==='message').at(-1);
    check(text.body.chat_mode==='auto'&&text.body.conversation_id===31,'checkout during a direct call preserves the canonical owner text routing');
    check(await page.evaluate(()=>__callMediaFixture.microphones===1&&__callMediaFixture.connections===1&&__callMediaFixture.tracks.every(track=>!track.stopped))&&await call.locator('audio').count()===1,'purchase confirmation leaves the connected call and both audio tracks intact');
    f.updateOrder('payment_pending',{checkout_url:'javascript:window.__providerExecuted=true',receipt_url:'https://evil.example/private-receipt'});
    await order.getByRole('button',{name:'Refresh payment status',exact:true}).click();
    await page.waitForFunction(()=>!document.querySelector('[data-profile-checkout-order] a'));
    check(!await page.evaluate(()=>window.__providerExecuted||window.__termsExecuted)&&await order.locator('a').count()===0,'unsafe external receipt and payment destinations never become browser controls');
    f.updateOrder('fulfilled',{receipt_url:receiptUrl.replace('PA5-7','PA5-9'),delivery_url:'https://evil.example/private-delivery'});
    await order.getByRole('button',{name:'Refresh payment status',exact:true}).click();
    await order.getByText('Order fulfilled',{exact:true}).waitFor();
    check(await order.locator('a').count()===0,'paid status never exposes another order receipt or external private delivery');
    check(!JSON.stringify(f.requests).includes('PRIVATE_OWNER_DRAFT'),'checkout and call requests preserve the private composer boundary');
    await f.close();
  }
  console.log(`PROFILE_CONVERSATION_CHECKOUT_PA5_BROWSER=PASS ${checks} checks`);
}finally{
  await browser?.close();
  await new Promise(resolve=>server.close(resolve));
}
