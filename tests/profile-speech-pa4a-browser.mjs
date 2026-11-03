import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import {speechItem,contract} from './fixtures/profile-speech-pa4a.mjs';
import {ownerCard,mainChatVoiceMarkup} from './fixtures/profile-visitor-doorbell-pa3.mjs';
import {callCard} from './fixtures/profile-calls-pa3c.mjs';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const read=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8'),copy=value=>structuredClone(value);
const files=Object.fromEntries(['chat.js','chat.css','chat-voice.js','voice-lease-v122.js','profile-calls-pa3c.js','profile-calls-pa3c.css','profile-visitor-doorbell-pa3.js','profile-visitor-doorbell-pa3.css','profile-speech-pa4a.js','profile-speech-pa4a.css','profile-agent.js'].map(name=>['/'+name,read(name)]));
const style='html,body{margin:0;height:100%;overflow:hidden}.chat-app{height:100dvh}.chat-sidebar{display:none}.chat-main{height:100dvh;display:flex;flex-direction:column;min-width:0}.chat-thread{overflow:auto;flex:1;min-height:0;padding:12px}.message{max-width:100%;box-sizing:border-box}.message-body{min-width:0;max-width:100%}#chatComposerShell{flex-shrink:0;padding:8px}#chatForm{display:flex;gap:8px}#chatInput{min-width:0;flex:1;font-size:16px}';
const ownerMarkup=mainChatVoiceMarkup.replace('</head>','<link rel="stylesheet" href="/chat.css"><link rel="stylesheet" href="/profile-calls-pa3c.css"><link rel="stylesheet" href="/profile-visitor-doorbell-pa3.css"><link rel="stylesheet" href="/profile-speech-pa4a.css"><style>'+style+'</style></head>').replace('</body>',`<script>
window.STONEFELLOW_CHAT={userId:1,agentId:7,endpoint:'/api/chat-v236.php',csrf:'FIXTURE_CSRF',initialView:'chat',initialConversationId:42};
window.STONEFELLOW_CHAT_VOICE_BOOT={button:document.getElementById('chatVoiceButtonLegacyDormant'),intro:null,legacyDormant:true};window.STONEFELLOW_AGENT_IDENTITY_V236={agentVoiceEnabled:true};
window.VP3_PROFILE_CALLS_PA3C={role:'owner',endpoint:'/api/profile-calls-pa3c.php',csrf:'FIXTURE_CSRF',userId:1};
window.VP3_PROFILE_DOORBELL_PA3={endpoint:'/api/chat-notifications-brain-v240.php',ownerEndpoint:'/api/profile-agent.php',csrf:'FIXTURE_CSRF',userId:1};
window.VP3_PROFILE_SPEECH_PA4A={endpoint:'/api/profile-speech-pa4a.php',csrf:'FIXTURE_CSRF',userId:1};
</script><script src="/chat.js"></script><script data-voice-capture-scope="fixture-user" src="/voice-lease-v122.js"></script><script src="/chat-voice.js"></script><script src="/profile-calls-pa3c.js"></script><script src="/profile-visitor-doorbell-pa3.js"></script><script src="/profile-speech-pa4a.js"></script></body>`);
const namespace='c'.repeat(64),publicMarkup=`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/profile-speech-pa4a.css"></head><body><section id="profileAgentShell"><div data-profile-agent-thread></div><form><textarea aria-label="Public visitor message"></textarea><button type="submit">Send</button></form><p data-profile-agent-status></p></section><script>window.STONEFELLOW_PROFILE_AGENT={username:'owner',endpoint:'/api/profile-agent.php',speechEndpoint:'/api/profile-speech-pa4a.php',profileToken:'PROFILE_TOKEN',conversationNamespace:'${namespace}'};</script><script src="/profile-agent.js"></script></body></html>`;
const server=http.createServer((request,response)=>{const path=new URL(request.url,'http://localhost').pathname;if(files[path]){response.writeHead(200,{'Content-Type':path.endsWith('.css')?'text/css':'text/javascript'});response.end(files[path]);}else{response.writeHead(200,{'Content-Type':'text/html'});response.end(path==='/visitor'?publicMarkup:ownerMarkup);}});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const base='http://127.0.0.1:'+server.address().port;
let browser,checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
function installBoundaries(){
  window.__recognitions=[];window.SpeechRecognition=class{constructor(){window.__recognitions.push(this);}start(){queueMicrotask(()=>this.onstart?.());}stop(){queueMicrotask(()=>this.onend?.());}abort(){queueMicrotask(()=>this.onend?.());}};
  Object.defineProperty(navigator.mediaDevices,'getUserMedia',{value:async()=>{throw new DOMException('Physical capture disabled in fixture.','NotAllowedError');}});
  const states=new WeakMap();window.__speechMedia={players:[],block:false};
  const state=element=>{if(!states.has(element))states.set(element,{paused:true,ended:false,plays:0});return states.get(element);};
  Object.defineProperty(HTMLMediaElement.prototype,'paused',{get(){return state(this).paused;}});Object.defineProperty(HTMLMediaElement.prototype,'ended',{get(){return state(this).ended;}});
  HTMLMediaElement.prototype.play=async function(){const current=state(this);current.plays++;if(window.__speechMedia.block)throw new DOMException('Autoplay blocked.','NotAllowedError');if(!window.__speechMedia.players.includes(this))window.__speechMedia.players.push(this);current.paused=false;this.dispatchEvent(new Event('play'));this.dispatchEvent(new Event('playing'));};
  HTMLMediaElement.prototype.pause=function(){state(this).paused=true;};HTMLMediaElement.prototype.load=function(){};
  window.__speakFixture=text=>{const result=[{transcript:text,confidence:.98}];result.isFinal=true;window.__recognitions.at(-1).onresult({resultIndex:0,results:[result]});};
}
async function fixture({visitor=false}={}){
  const context=await browser.newContext({viewport:{width:390,height:844}}),requests=[],errors=[],holds=[],messages=[],items=new Map([[81,speechItem()]]),turn=ownerCard(101,{state:'owner_claimed',responder:'owner'}),calls=[],attempts=new Map(),claimRequests=new Map();
  let present=false,sequence=0,attemptId=0,providerCalls=0,style='preset',presetKey='',preferenceRevision=1;
  const projected=item=>{
    const attempt=[...attempts.values()].filter(row=>row.itemId===item.id).at(-1),playback=attempt?.playback||'none';
    const state=({granted:'granted',playing:'playing',ended:'client_finished',interrupted:'interrupted',blocked:'blocked'})[playback]||(attempt&&!attempt.ready?'pending':'available');
    return {...copy(item),state,playback_state:playback,can_play:!['pending','granted','playing'].includes(state),can_retry:false,automatic_allowed:false,latest_attempt_id:attempt?.id||null,latest_attempt_state:attempt?(attempt.ready?'ready':'reserved'):null,latest_attempt_reason:attempt?'':null};
  };
  await context.addInitScript(installBoundaries);
  await context.route('**/api/**',async route=>{
    const req=route.request(),url=new URL(req.url()),input=req.method()==='GET'?Object.fromEntries(url.searchParams):req.postDataJSON()||{};
    for(const key of ['conversation_id','chat_conversation_id','chat_agent_id','item_id','revision','turn_id','attempt_id'])if(input[key]!==undefined)input[key]=Number(input[key]);requests.push({path:url.pathname,...copy(input)});
    let result={ok:true},binary=false;
    if(url.pathname==='/api/chat-v236.php'){
      if(input.action==='load')Object.assign(result,{conversation:{id:42},messages:copy(messages)});
      else if(input.action==='messages_after')Object.assign(result,{conversation_id:42,messages:copy(messages.filter(message=>message.id>input.after_id))});
      else if(input.action==='send')Object.assign(result,{conversation_id:42,user_message_id:1998,assistant_message_id:1999,answer:'Private answer.'});
      else Object.assign(result,{conversations:[{id:42,title:'Private Chat42',updated_at:'2026-11-04 12:00:00'}],items:[],updates:[],tracks:[]});
    }else if(url.pathname==='/api/chat-notifications-brain-v240.php'){
      if(input.action==='doorbell_list')Object.assign(result,{availability:'available',turns:[{...copy(turn),server_now_ms:Date.now()}]});
      else if(input.action==='doorbell_presence'){if(input.presence_sequence>sequence){sequence=input.presence_sequence;present=input.visible;}Object.assign(result,{availability:'available',reachable:present,accepted:true});}
      else if(input.action==='doorbell_delivery'){
        if(!messages.some(message=>message.id===701))messages.push({id:701,role:'assistant',message:'Profile visitor conversation.',context_json:JSON.stringify({profile_handoff:{contract:turn.contract,turn_id:turn.turn_id,revision:turn.revision,conversation_id:turn.conversation_id}})});
        Object.assign(result,{turn:{...copy(turn),chat_agent_id:7,server_now_ms:Date.now()},chat_conversation_id:42,chat_message_id:701,message_id:701,delivery_id:'d'.repeat(32)});
      }else throw new Error('Unexpected doorbell action '+input.action);
    }else if(url.pathname==='/api/profile-agent.php'){
      if(input.action==='doorbell_reply'){turn.revision++;Object.assign(result,{turn_id:101,conversation_id:1101,request_id:input.request_id,turn:{...copy(turn),state:'replied',responder:'owner',server_now_ms:Date.now()}});}
      else if(input.action==='state')Object.assign(result,{agent:{greeting:''},conversation:null,messages:[]});
      else throw new Error('Unexpected Profile action '+input.action);
    }else if(url.pathname==='/api/profile-calls-pa3c.php'){
      if(input.action==='owner_state')Object.assign(result,{contract:'vp3.profile-call.v1',calls:copy(calls),can_offer:false,reason:'',server_now_ms:Date.now(),conversation_id:0});
      else if(input.action==='owner_delivery')Object.assign(result,{contract:'vp3.profile-call.v1',call:copy(calls[0]),call_id:41,revision:1,conversation_id:1101,delivery_id:'b'.repeat(32),chat_conversation_id:42,chat_agent_id:7,chat_message_id:801,message_id:801});
      else throw new Error('Unexpected call action '+input.action);
    }else if(url.pathname==='/api/profile-speech-pa4a.php'){
      const item=items.get(input.item_id||81);
      if(input.action==='owner_state')Object.assign(result,{contract,items:[...items.values()].map(projected),ready:true,reason:'',server_now_ms:Date.now()});
      else if(input.action==='owner_delivery'){
        Object.assign(item,{chat_conversation_id:42,chat_agent_id:7,chat_message_id:981,delivery_id:'e'.repeat(32)});
        if(!messages.some(message=>message.id===981))messages.push({id:981,role:'assistant',message:'Profile visitor announcement.',context_json:JSON.stringify({profile_speech:{contract,item_id:81,revision:item.revision,kind:item.kind,public_conversation_id:item.public_conversation_id}})});
        Object.assign(result,{contract,item:projected(item),chat_conversation_id:42,chat_agent_id:7,chat_message_id:981,delivery_id:'e'.repeat(32),server_now_ms:Date.now()});
      }else if(input.action==='owner_claim'){
        let attempt=claimRequests.get(input.request_id);if(!attempt){const cached=[...attempts.values()].some(row=>row.itemId===item.id&&row.ready);attempt={id:++attemptId,itemId:item.id,ready:cached,until:0};attempts.set(attempt.id,attempt);claimRequests.set(input.request_id,attempt);}
        Object.assign(result,{contract,item:projected(item),attempt_id:attempt.id,state:attempt.ready?'ready':'reserved',ticket:'t'.repeat(64),voice_revision:item.voice_revision,server_now_ms:Date.now(),lease_until_ms:Date.now()+90000,duplicate:false,retry_may_charge:false});
      }else if(input.action==='owner_audio'){const attempt=attempts.get(input.attempt_id);if(!attempt.ready){attempt.ready=true;providerCalls++;}binary=true;}
      else if(input.action==='owner_play'){
        const attempt=attempts.get(input.attempt_id);if(attempt.playRequest&&attempt.playRequest!==input.request_id){await route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({ok:false,contract,code:'PROFILE_SPEECH_ALREADY_PLAYED'})});return;}
        const now=Date.now();attempt.playRequest=input.request_id;attempt.playback='granted';Object.assign(result,{contract,attempt_id:input.attempt_id,play_token:'f'.repeat(32),server_now_ms:now,lease_until_ms:now+30000,absolute_until_ms:now+600000,granted:true});
      }
      else if(input.action==='owner_report'){const attempt=attempts.get(input.attempt_id),now=Date.now();if(input.status==='playing'&&input.report_sequence>(attempt.sequence||0)){attempt.sequence=input.report_sequence;attempt.until=now+30000;}attempt.playback=input.status;Object.assign(result,{contract,attempt_id:input.attempt_id,state:input.status,client_reported:true,report_sequence:attempt.sequence||0,server_now_ms:now,lease_until_ms:attempt.until||now+30000});}
      else if(input.action==='visitor_state'||input.action==='visitor_preference'){
        if(input.action==='visitor_preference'){style=input.style;presetKey=input.preset_key;preferenceRevision++;}
        Object.assign(result,{contract,style,preset_key:presetKey,revision:preferenceRevision,ready:true,reason:'',presets:[{preset_key:'a'.repeat(64),label:'Public reading preset',style:'unclassified'}]});
      }else throw new Error('Unexpected speech action '+input.action);
    }else Object.assign(result,{items:[],updates:[],tracks:[]});
    const send=()=>binary?route.fulfill({status:200,contentType:'audio/mpeg',headers:{'Content-Length':'6'},body:Buffer.from([73,68,51,1,2,3])}):route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(result)});
    const index=holds.findIndex(held=>held.action===input.action);if(index>=0){const held=holds.splice(index,1)[0];held.arrived({input,result,release:send});}else await send();
  });
  const page=await context.newPage();page.setDefaultTimeout(15000);page.on('pageerror',error=>errors.push(error.message));await page.goto(base+(visitor?'/visitor':'/owner'));
  const f={context,page,requests,items,calls,messages,errors,get providerCalls(){return providerCalls;},hold:action=>new Promise(arrived=>holds.push({action,arrived})),
    card:()=>page.locator('.profile-doorbell-card'),speech:()=>page.locator('.profile-speech-card'),
    async arm(){await this.card().locator('[data-speech-action=draft]').scrollIntoViewIfNeeded();await this.card().locator('[data-speech-action=draft]').click();},
    async recognize(text){await page.waitForFunction(()=>window.STONEFELLOW_CHAT_VOICE?.starts>0);await page.evaluate(text=>window.__speakFixture(text),text);},
    async close(){check(errors.length===0,'actual browser scripts have no errors: '+errors.join('; '));await context.close();}};
  if(visitor)await page.locator('#profileVisitorSpeechPreference:enabled').waitFor();else{await f.card().waitFor();await f.speech().waitFor();}return f;
}
try{
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
  {
    const f=await fixture();check(await f.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY!==STONEFELLOW_CHAT_CONTINUITY_V87&&typeof STONEFELLOW_CHAT_CONTINUITY.pendingVoiceSubmit==='function'&&STONEFELLOW_CHAT_VOICE.directOwner===true),'production-order Chat and voice scripts execute before speech controls');
    check(f.requests.filter(r=>r.action==='owner_delivery'&&r.path.includes('speech')).every(r=>r.chat_agent_id===7&&r.chat_conversation_id===42),'browser speech delivery uses exact canonical Agent7 and Chat42');
    await f.page.locator('#chatInput').fill('PRIVATE BEFORE CAPTURE');await f.arm();
    check(await f.page.evaluate(()=>__recognitions.length===0)&&await f.page.locator('#chatInput').inputValue()==='PRIVATE BEFORE CAPTURE','real pre-existing private draft prevents speech arming');
    await f.page.locator('#chatInput').fill('');await f.arm();await f.recognize('I will meet you by the entrance in five minutes.');
    await f.page.waitForFunction(()=>document.querySelector('.profile-doorbell-reply textarea')?.value==='I will meet you by the entrance in five minutes.');
    check(!(await f.page.locator('#chatInput').inputValue())&&!f.requests.some(r=>r.action==='doorbell_reply'||r.action==='send'),'native cancelable voice submit stages a review draft without public or private send');
    await f.card().locator('[data-doorbell-action=reply]').click();await f.page.waitForFunction(()=>document.querySelector('.profile-doorbell-notice')?.textContent.includes('Reply sent'));
    check(f.requests.filter(r=>r.action==='doorbell_reply').length===1&&f.requests.find(r=>r.action==='doorbell_reply').conversation_id===1101,'explicit visitor Send uses the canonical exact thread boundary');
    check(await f.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'speech controls fit the mobile viewport');
    check(await f.speech().evaluate(card=>[card,...card.querySelectorAll('*')].every(el=>!['auto','scroll'].includes(getComputedStyle(el).overflowY))),'speech card adds no nested scroller to the main canvas');
    check(await f.card().locator('textarea').evaluate(input=>parseFloat(getComputedStyle(input).fontSize)>=16),'visitor reply field avoids mobile input zoom');await f.close();
  }
  {
    const f=await fixture();await f.arm();await f.recognize('A pending voice sentence for this selected visitor.');await f.page.locator('#chatInput').fill('PRIVATE TYPED DURING CAPTURE');await f.page.waitForTimeout(2300);
    check(await f.page.locator('#chatInput').inputValue()==='PRIVATE TYPED DURING CAPTURE'&&!(await f.card().locator('textarea').inputValue()),'real trusted private typing during capture cancels staging and preserves that private draft');
    check(!f.requests.some(r=>r.action==='doorbell_reply'),'typing during borrowed capture never relays private text to the public visitor');
    await f.page.locator('#chatForm').evaluate(form=>form.requestSubmit());await f.page.getByText('Private answer.',{exact:true}).waitFor();
    check(f.requests.some(r=>r.action==='send'&&r.message==='PRIVATE TYPED DURING CAPTURE'),'ordinary private Chat submit still routes through real Chat');await f.close();
  }
  {
    const f=await fixture();await f.page.evaluate(()=>__speechMedia.block=true);await f.speech().locator('[data-speech-action=play]').click();await f.page.getByText(/Your browser blocked playback/).waitFor();
    check(!f.requests.some(r=>r.action==='owner_report'&&r.status==='playing')&&f.providerCalls===1,'actual browser autoplay rejection does not claim audible playback');
    await f.page.evaluate(()=>__speechMedia.block=false);await f.speech().locator('[data-speech-action=play]').click();await f.page.getByText('Playing visitor speech.',{exact:true}).waitFor();
    check(f.providerCalls===1,'explicit browser Play reuses the completed cached audio');
    f.calls.push(callCard('owner',{state:'ready',conversation_id:1101,can_accept:false,can_decline:false,can_end:true,can_join:true,dispatch_due:false}));await f.page.evaluate(()=>VP3ProfileCallsPa3c.refresh());
    await f.page.waitForFunction(()=>__speechMedia.players.every(audio=>audio.paused));check(await f.page.evaluate(()=>VP3ProfileCallsPa3c.speechBusy()),'actual accepted call projection pauses visitor speech before any microphone capture');await f.close();
  }
  {
    const f=await fixture();await f.page.reload();await f.speech().waitFor();
    check(await f.page.locator('[data-chat-message-id="981"]').count()===1&&await f.speech().count()===1,'actual saved Chat history restores one source-bound speech card');
    check(!f.requests.some(r=>r.action==='owner_claim'),'reload never autoplays an old speech claim or synthesizes from history');await f.close();
  }
  {
    const f=await fixture();await f.page.evaluate(()=>{window.__idleSpeechEvents=[];window.addEventListener('stonefellow:chat-voice',event=>window.__idleSpeechEvents.push(event.detail?.type));VP3ConversationControls.listen();});await f.page.waitForFunction(()=>VP3ConversationControls.state().state==='listening');
    await f.speech().locator('[data-speech-action=play]').click();await f.page.getByText('Playing visitor speech.',{exact:true}).waitFor();await f.page.waitForTimeout(400);
    const idleSpeech=await f.page.evaluate(()=>{
      const card=document.querySelector('.profile-speech-card'),thread=document.getElementById('chatThread'),rect=card.getBoundingClientRect(),canvas=thread.getBoundingClientRect();
      const top=Math.max(0,rect.top,canvas.top),bottom=Math.min(innerHeight,rect.bottom,canvas.bottom),left=Math.max(0,rect.left,canvas.left),right=Math.min(innerWidth,rect.right,canvas.right),hit=document.elementFromPoint((left+right)/2,(top+bottom)/2);
      return {players:__speechMedia.players.length,media:__speechMedia.players.map(audio=>({paused:audio.paused,ended:audio.ended,error:audio.error?.code||null,readyState:audio.readyState})),speechStarts:STONEFELLOW_CHAT_VOICE.speechStarts,voiceBusy:STONEFELLOW_CHAT_CONTINUITY.isBusy(),voiceEnabled:VP3ConversationControls.isEnabled(),voiceState:VP3ConversationControls.state().state,leaseOwned:StonefellowVoiceLeaseV122.owns(),chatBusy:STONEFELLOW_CHAT_CONTINUITY_V87.isBusy(),callBusy:VP3ProfileCallsPa3c.speechBusy(),notice:card.querySelector('.profile-speech-notice')?.textContent,events:__idleSpeechEvents.slice(-12),context:{cid:STONEFELLOW_CHAT_CONTINUITY_V87.conversationId(),agent:STONEFELLOW_CHAT_CONTINUITY_V87.agentId(),ready:STONEFELLOW_CHAT_CONTINUITY_V87.isReady(),restoring:STONEFELLOW_CHAT_CONTINUITY_V87.isRestoring(),view:thread.dataset.view,visibility:document.visibilityState,presence:VP3_PROFILE_DOORBELL_PA3_RUNTIME.speechContext()},paint:{connected:card.isConnected,hidden:!!card.closest('[hidden]'),card:rect.toJSON(),canvas:canvas.toJSON(),viewport:[innerWidth,innerHeight],overlap:[right-left,bottom-top],hit:hit?{tag:hit.tagName,id:hit.id,className:hit.className,withinCard:card.contains(hit)}:null}};
    });
    check(idleSpeech.players===1&&!idleSpeech.media[0].paused&&idleSpeech.speechStarts===1&&idleSpeech.voiceBusy,'actual idle Chat voice coordinates speech echo without suppressing or stopping the announcement: '+JSON.stringify(idleSpeech));
    await f.page.evaluate(()=>STONEFELLOW_CHAT_CONTINUITY.interrupt('fixture-user-stop'));await f.page.waitForFunction(()=>__speechMedia.players.every(audio=>audio.paused));
    check(await f.page.evaluate(()=>VP3ConversationControls.isEnabled()),'actual voice interrupt stops visitor playback while keeping Chat listening available');await f.close();
  }
  {
    const f=await fixture({visitor:true});await f.page.locator('#profileVisitorSpeechPreference').selectOption('female');await f.page.getByText('Voice preference saved. Your messages stay available as text.',{exact:true}).waitFor();
    const preference=f.requests.find(r=>r.action==='visitor_preference');check(preference.style==='female'&&preference.conversation_namespace===namespace&&preference.profile_token==='PROFILE_TOKEN'&&!Object.hasOwn(preference,'voice_id'),'native preference uses voluntary style with the existing session boundary');
    check(f.providerCalls===0&&!f.requests.some(r=>r.action==='owner_claim'),'choosing a reading style makes no speech request');await f.close();
  }
  console.log('PROFILE_SPEECH_PA4A_BROWSER=PASS ('+checks+' assertions)');
}finally{await browser?.close();await new Promise(resolve=>server.close(resolve));}
