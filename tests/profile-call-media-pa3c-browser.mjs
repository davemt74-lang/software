import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const assets=new Map(['profile-call-pa3c.js','profile-call-pa3c.css'].map(name=>['/'+name,fs.readFileSync(new URL('../'+name,import.meta.url))]));
const html=`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/profile-call-pa3c.css"><section id="call" class="profile-call-owner" hidden></section><div id="card" class="profile-call-card" hidden></div><script src="/profile-call-pa3c.js"></script>`;
const server=http.createServer((req,res)=>{
  const asset=assets.get(req.url);
  res.writeHead(200,{'Content-Type':asset?(req.url.endsWith('.js')?'text/javascript':'text/css'):'text/html'});
  res.end(asset||html);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const origin=`http://127.0.0.1:${server.address().port}`;
let browser, checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;};
const id='a'.repeat(32), cid=37;
const agent='Longfellow <img src=x onerror=window.injected=true>';
const originalCall={id,conversation_id:cid,revision:1,status:'ringing',initiator:'visitor',assisted:true,agent_name:agent,peer_name:'David'};

async function fixture(side, initial=null, options={}) {
  const page=await browser.newPage({viewport:{width:390,height:720}});
  const requests=[], errors=[];
  let call=initial?{...initial}:null, optIn=options.optIn??true;
  page.on('pageerror',error=>errors.push(error.message));
  await page.addInitScript(({denied, hold, side})=>{
    window.media={requests:[],publishes:[],stops:0,disconnects:0,mutes:0,unmutes:0,allowAudio:false,events:[]};
    window.addEventListener('vp3:profile-call-state',event=>media.events.push(event.detail));
    const mic=new EventTarget();
    mic.stop=()=>media.stops++;
    const stream={getTracks:()=>[mic],getAudioTracks:()=>[mic]};
    Object.defineProperty(navigator.mediaDevices,'getUserMedia',{value:async config=>{
      media.requests.push(config);
      if(denied)throw new DOMException('denied','NotAllowedError');
      if(hold)await new Promise(resolve=>window.releaseMicrophone=resolve);
      return stream;
    }});
    const RoomEvent=Object.fromEntries(['TrackSubscribed','TrackUnsubscribed','Reconnecting','SignalReconnecting','Reconnected','Disconnected','AudioPlaybackStatusChanged'].map(name=>[name,name]));
    class Room {
      constructor() {
        window.lastRoom=this;
        this.events=new Map();
        this.canPlaybackAudio=false;
        this.localParticipant={identity:`vp3-profile-${side}-${'a'.repeat(32)}`,publishTrack:async (_track,options)=>{
          media.publishes.push(options);
          return {mute:async()=>media.mutes++,unmute:async()=>media.unmutes++};
        }};
      }
      on(event,listener) {this.events.set(event,listener);}
      emit(event,...args) {this.events.get(event)?.(...args);}
      async connect(url,token) {media.connection={url,token};}
      async disconnect() {media.disconnects++;}
      async startAudio() {
        if(!media.allowAudio)throw new Error('Autoplay blocked');
        this.canPlaybackAudio=true;
      }
    }
    window.LivekitClient={Room,RoomEvent,Track:{Kind:{Audio:'audio'},Source:{Microphone:'microphone'}},DisconnectReason:{ROOM_DELETED:1,DUPLICATE_IDENTITY:2}};
  },{...options,side});
  await page.route('**/api/call',async route=>{
    const req=route.request().postDataJSON();requests.push(req);
    const data=()=>({ok:true,contract:'vp3.profile-call.pa3c',available:req.conversation_id===cid,voice_ready:true,assisted:optIn,agent_assisted_phone_chat_enabled:optIn,agent_name:agent,call});
    const respond=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
    if(req.action==='state')return respond(data());
    if(req.action==='offer') {
      assert.match(req.request_id,/^[a-f0-9]{32}$/);
      if(req.disclosed!==optIn)return respond({ok:false},409);
      call={...originalCall,initiator:side,assisted:optIn};return respond(data());
    }
    if(req.action==='accept') {
      assert.equal(req.disclosed,call.assisted);
      call={...call,status:'accepted',revision:call.revision+1};return respond(data());
    }
    if(req.action==='token')return respond({...data(),url:'wss://fixture.example',token:'fixture-grant',identity:`vp3-profile-${side}-${id}`});
    if(req.action==='heartbeat') {
      if(call.status==='accepted')call={...call,status:'live',revision:call.revision+1};
      return respond(data());
    }
    if(req.action==='end'||req.action==='decline') {
      call={...call,status:req.action==='end'?'ended':'declined',revision:call.revision+1};return respond(data());
    }
    throw new Error('Unexpected call action '+req.action);
  });
  await page.goto(origin);
  await page.evaluate(({side,cid})=>{
    window.callRuntime=VP3ProfileCallPA3C.mount(document.getElementById('call'),{side,endpoint:'/api/call',credentials:()=>({csrf_token:'fixture'})});
    if(side==='owner')callRuntime.bindCard(document.getElementById('card'),cid,true);
    else callRuntime.setConversation(cid);
  },{side,cid});
  return {page,requests,errors,change(changes){call={...call,...changes,revision:(call?.revision||0)+1};},
    async refresh(){await page.evaluate(()=>callRuntime.refresh());},
    async close(){check(!errors.length,'browser emitted no uncaught errors: '+errors.join('; '));await page.close();}};
}
try {
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
  {
    const f=await fixture('visitor');const {page}=f;
    const offer=page.getByRole('button',{name:'Call with Agent',exact:true});await offer.waitFor();
    check((await page.locator('#call .profile-call-notice').textContent()).includes(agent)&&!await page.locator('#call img').count(),'assistance notice uses the exact inert Profile Agent name before call');
    await offer.click();await page.getByRole('button',{name:'Cancel call'}).waitFor();
    check(f.requests.some(row=>row.action==='offer'&&row.disclosed===true&&row.conversation_id===cid),'the exact visitor thread and shown disclosure bind the offer');
    check(!f.requests.some(row=>row.action==='token')&&!await page.evaluate(()=>media.requests.length),'ringing never requests a grant or microphone');
    f.change({status:'accepted'});await f.refresh();await page.getByRole('button',{name:'Mute',exact:true}).waitFor();
    check(await page.evaluate(()=>media.requests.length===1&&media.requests[0].video===false&&media.publishes[0].source==='microphone'),'accepted call publishes one microphone-only track');
    check(f.requests.some(row=>row.action==='token'&&row.call_id===id&&row.revision===2&&/^[a-f0-9]{32}$/.test(row.client_id)),'grant is bound to current call, revision and page lease');
    await page.getByRole('button',{name:'Mute',exact:true}).click();await page.getByRole('button',{name:'Unmute',exact:true}).click();
    check(await page.evaluate(()=>media.mutes===1&&media.unmutes===1),'mute controls operate only the call publication');
    await page.getByRole('button',{name:'Play call audio'}).waitFor();await page.evaluate(()=>{media.allowAudio=true;});
    await page.getByRole('button',{name:'Play call audio'}).click();await page.getByRole('button',{name:'Play call audio'}).waitFor({state:'hidden'});
    check(true,'blocked autoplay exposes a working play control');
    await page.getByText('Voice call with David',{exact:true}).waitFor();
    check(f.requests.some(row=>row.action==='heartbeat'&&row.call_id===id),'actual microphone publication precedes connected heartbeat');
    await page.getByRole('button',{name:'End call',exact:true}).click();
    await page.getByText('Voice call ended. You can continue in text.').waitFor();
    check(await page.evaluate(()=>media.stops===1&&media.disconnects>=1),'hang-up stops the microphone and closes the local room');
    check(!await page.evaluate(()=>window.injected),'untrusted Profile Agent label cannot execute');await f.close();
  }
  {
    const f=await fixture('owner',originalCall);const {page}=f;
    await page.getByRole('button',{name:'Accept Agent-assisted call'}).waitFor();
    check(!await page.evaluate(()=>media.requests.length),'owner can review incoming call before microphone request');
    await page.getByRole('button',{name:'Accept Agent-assisted call'}).click();await page.getByText('Voice call with David',{exact:true}).waitFor();
    check(f.requests.some(row=>row.action==='accept'&&row.call_id===id&&row.disclosed&&row.side==='owner'),'owner action binds exact call and displayed assistance');
    check(await page.evaluate(()=>media.events.some(e=>e.id==='a'.repeat(32)&&e.status==='active'&&e.assisted&&e.connection==='connected')&&VP3_PROFILE_CALL_OWNER_STATE.status==='active'),'rail receives the real canonical active call state');
    f.change({status:'ended'});await f.refresh();await page.getByText('Voice call ended. You can continue in text.').waitFor();
    check(await page.evaluate(()=>media.stops===1&&media.events.at(-1).status==='ended'),'remote terminal state releases microphone and closes activity');await f.close();
  }
  {
    const f=await fixture('owner',{...originalCall,status:'live'},{hold:true});const {page}=f;
    await page.getByRole('button',{name:'Join voice call'}).waitFor();
    check(!f.requests.some(row=>row.action==='token'),'reload hydrates the active call without opening microphone');
    await page.getByRole('button',{name:'Join voice call'}).click();await page.waitForFunction(()=>typeof window.releaseMicrophone==='function');
    await page.getByRole('button',{name:'End call',exact:true}).click();await page.getByText('Voice call ended. You can continue in text.').waitFor();
    await page.evaluate(()=>releaseMicrophone());await page.waitForFunction(()=>media.stops===1);
    check(!await page.evaluate(()=>media.publishes.length),'late microphone permission cannot revive an ended call');await f.close();
  }
  {
    const f=await fixture('owner',null,{optIn:false});
    await f.page.getByRole('button',{name:'Voice call',exact:true}).waitFor();
    check(!await f.page.locator('#card .profile-call-notice').isVisible(),'plain voice call has no assisted notice when disabled');
    await f.page.getByRole('button',{name:'Voice call',exact:true}).click();
    await f.page.getByRole('button',{name:'Cancel call'}).waitFor();
    check(f.requests.some(row=>row.action==='offer'&&row.side==='owner'&&row.disclosed===false),'owner can offer ordinary voice with assistance off');await f.close();
  }
  {
    const f=await fixture('owner',originalCall,{denied:true});
    await f.page.getByRole('button',{name:'Accept Agent-assisted call'}).click();
    await f.page.getByText(/Allow microphone access to join/).waitFor();
    check(!await f.page.evaluate(()=>media.publishes.length),'denied microphone does not publish');
    await f.page.waitForFunction(()=>media.disconnects>=1);
    check(f.requests.some(row=>row.action==='end'),'media failure ends the pending call and preserves text fallback');await f.close();
  }
  console.log(`PROFILE_CALL_MEDIA_PA3C_BROWSER=PASS ${checks} checks`);
} finally {if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
