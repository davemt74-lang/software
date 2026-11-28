import assert from 'node:assert/strict';
import fs from 'node:fs';
import {execFileSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import path from 'node:path';
import {chromium} from 'playwright';
import {chatChoice} from './fixtures/profile-chat-choice-pa3d.mjs';

const origin='https://profile-phone-call.test',namespace='a'.repeat(64),profileToken='b'.repeat(48),callId='c'.repeat(32),intent='d'.repeat(64);
const contract='vp3.profile-browser-phone.v1',storageKey='stonefellow-profile-agent:v1:dave:'+namespace;
const assets=new Map(['profile-phone-v1.js','profile-phone-v1.css','profile-agent.js','profile.css','voice-lease-v122.js'].map(name=>['/'+name,fs.readFileSync(name,'utf8')]));
const widgetSource=fs.readFileSync('profile.php','utf8').match(/<div class="profile-agent-widget-root"[\s\S]*?(?=\n<\?php endif; \?>\n<script>)/)?.[0];
assert.ok(widgetSource,'fixture uses the actual public Profile widget');
const widget=execFileSync(process.env.VP3_PHP_BINARY||'php',['-r',"$agent=['display_name'=>'Profile Guide'];$displayName='Dave';$profileOrbShape='orbit';$agentGreeting='Welcome.';$isOwner=$chatPreview=false;function e($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}function url($v){return $v;}eval('?>'.stream_get_contents(STDIN));"],{input:widgetSource,encoding:'utf8'}).replace('id="profileAgentShell" hidden','id="profileAgentShell"');
const pinnedIntegrity='sha384-LUG7DmzDPWNKRYoqyGpV4d/IByMLiya/oUhMSc/4/6hi0wbzO/8PffM4lzeFEYS8';
assert.equal('sha384-'+createHash('sha384').update(fs.readFileSync('assets/twilio-voice-2.18.5.min.js')).digest('base64'),pinnedIntegrity);
const sdk='('+function(){
  const m=window.phoneFixture;m.sdkLoads++;
  class Call {
    constructor(){this.events={};this.state='connecting';this.stream=null;this.muted=false;this.disconnected=false;m.calls.push(this);if(m.permission==='granted')this.grant();}
    on(name,fn){(this.events[name]??=[]).push(fn);return this;}
    emit(name,...args){for(const fn of this.events[name]||[])fn(...args);}
    status(){return this.state;}
    getLocalStream(){return this.stream;}
    grant(){const track={readyState:'live',stop(){if(this.readyState!=='ended'){this.readyState='ended';m.stops++;}}};this.stream={getTracks:()=>[track]};m.tracks.push(track);if(this.disconnected)track.stop();}
    accept(){if(!this.stream)this.grant();if(this.disconnected)return;this.state='open';this.emit('accept',this);}
    isMuted(){return this.muted;}
    mute(value){this.muted=value;this.emit('mute',value);}
    disconnect(){if(this.disconnected)return;this.disconnected=true;this.state='closed';for(const track of this.stream?.getTracks()||[])track.stop();this.emit('disconnect');}
    reject(){this.disconnect();m.rejected++;}
  }
  class Device {
    constructor(token,options){this.events={};this.token=token;this.options=options;this.destroyed=false;m.devices.push(this);}
    on(name,fn){(this.events[name]??=[]).push(fn);return this;}
    emit(name,...args){for(const fn of this.events[name]||[])fn(...args);}
    register(){m.registrations++;return Promise.resolve();}
    async connect(options){m.connects.push(options);const call=new Call();if(m.holdConnect)await new Promise(resolve=>m.releaseConnect=resolve);if(m.permission==='denied')setTimeout(()=>call.emit('error',{name:'PermissionDeniedError',code:31401}),10);return call;}
    disconnectAll(){for(const call of m.calls)call.disconnect();}
    destroy(){this.destroyed=true;}
  }
  window.Twilio={Device};
}.toString()+')();';
const sdkIntegrity='sha384-'+createHash('sha384').update(sdk).digest('base64');
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined,args:['--no-sandbox']});

async function fixture(options={}){
  const context=await browser.newContext({viewport:options.viewport||{width:1200,height:900}}),page=await context.newPage();
  const requests=[],errors=[],foreign=[],holds=[];
  let cid=options.cid??0,record=null,published=options.published!==false,browserEnabled=options.browserEnabled!==false,phone='+16025550101',authorized=true,leaseMs=30000,unavailableState=false;
  await context.addInitScript(({storageKey,cid,permission,holdConnect})=>{
    if(cid)localStorage.setItem(storageKey,String(cid));
    window.phoneFixture={sdkLoads:0,devices:[],calls:[],connects:[],tracks:[],stops:0,rejected:0,registrations:0,permission,holdConnect};
    const original=window.setTimeout;
    window.setTimeout=(fn,ms,...args)=>original(fn,[10000,12000,15000].includes(ms)?500:ms,...args);
  },{storageKey,cid,permission:options.permission||'granted',holdConnect:options.holdConnect||false});
  page.on('pageerror',error=>errors.push(error.message));
  const state=()=>{const timestamp=Date.now();return {ok:true,contract,conversation_id:cid,server_now_ms:timestamp,valid_until_ms:timestamp+leaseMs,call:record?{...record}:null};};
  const chat=()=>chatChoice({mode:options.ownerJoined?'auto':'agent',live_text_available:false,media_call_active:false,phone_call_active:Boolean(record&&!record.terminal)});
  await context.route('**/*',async route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.origin!==origin){foreign.push(request.url());return route.abort();}
    if(url.pathname==='/api/profile-phone-v1.php')return route.fulfill({json:{ok:true,contract:'vp3.profile-phone.v1',enabled:published,number:published?phone:'',href:published?'tel:'+phone:'',browser:{contract,enabled:published&&browserEnabled,reason:browserEnabled?'':'Browser calling is unavailable. Use your phone app.'}}});
    if(url.pathname==='/api/profile-phone-browser-v1.php'||url.pathname==='/api/profile-agent.php'){
      const input=request.method()==='GET'?Object.fromEntries(url.searchParams):request.postDataJSON();requests.push({path:url.pathname,method:request.method(),input});
      if(!authorized)return route.fulfill({status:419,json:{ok:false,code:'PROFILE_SESSION_CHANGED',error:'The visitor session changed.'}});
      if(url.pathname==='/api/profile-agent.php'){
        if(['state','poll'].includes(input.action))return route.fulfill({json:{ok:true,conversation:cid?{conversation_id:cid,status:options.ownerJoined?'owner_joined':'open'}:null,agent:{name:'Profile Guide',greeting:'Welcome.'},chat:chat(),messages:[],handoff:options.queuedHandoff&&record&&!record.terminal?{turn_id:90,conversation_id:cid,state:'agent_ready',dispatch_state:'agent_ready',revision:1,dispatch_due_at_ms:Date.now()-1000}:null}});
        if(input.action==='message'){cid=cid||31;return route.fulfill({json:{ok:true,conversation_id:cid,conversation:{conversation_id:cid,status:options.ownerJoined?'owner_joined':'open'},visitor_message_id:101,client_request_id:input.client_request_id,chat:chat(),messages:[]}});}
        return route.fulfill({status:422,json:{ok:false,error:'Unexpected fixture chat action.'}});
      }
      let result;
      if(input.action==='prepare'){
        cid=cid||31;
        record={id:callId,state:'prepared',terminal:false,answered:false,duration_seconds:0,route_kind:'pending',end_requested:false};
        result={...state(),token:'fixture-token-with-enough-bytes',expires_at:Math.floor(Date.now()/1000)+3600,connect_params:{profile_call_intent:intent}};
      }else if(input.action==='state'){
        if(unavailableState)return route.fulfill({status:503,json:{ok:false,error:'Temporary status interruption.'}});
        result=state();
      }else if(input.action==='end'){
        if(record&&!record.terminal)Object.assign(record,{state:'canceled',terminal:true,end_requested:true});
        result=state();
      }else throw new Error('Unexpected browser phone action '+input.action);
      const index=holds.findIndex(hold=>hold.action===input.action);
      const send=extra=>route.fulfill({json:{...result,...extra}});
      if(index>=0){const hold=holds.splice(index,1)[0];hold.arrived({input,result,release:send});return;}
      return send({});
    }
    if(url.pathname==='/assets/twilio-voice-2.18.5.min.js')return route.fulfill({contentType:'text/javascript',body:sdk});
    if(assets.has(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:assets.get(url.pathname)});
    const cfg={username:'dave',ownerName:'Dave',endpoint:'/api/profile-agent.php',phoneEndpoint:'/api/profile-phone-v1.php',browserPhoneEndpoint:'/api/profile-phone-browser-v1.php',phoneSdkUrl:options.sdkUrl||'/assets/twilio-voice-2.18.5.min.js',phoneSdkIntegrity:sdkIntegrity,conversationNamespace:namespace,profileToken};
    return route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/profile.css"><link rel="stylesheet" href="/profile-phone-v1.css"></head><body>'+widget+'<script>window.STONEFELLOW_PROFILE_AGENT='+JSON.stringify(cfg)+';document.querySelector("[data-close-profile-agent]").onclick=()=>{document.getElementById("profileAgentShell").hidden=true;window.dispatchEvent(new CustomEvent("vp3:profile-agent-visibility",{detail:{open:false}}));};</script><script src="/profile-phone-v1.js"></script><script data-voice-capture-scope="fixture-user" src="/voice-lease-v122.js"></script><script src="/profile-agent.js"></script></body></html>'});
  });
  await page.goto(origin+'/dave');
  if(published&&browserEnabled)await page.locator('[data-profile-phone-start]:enabled').waitFor();
  else await page.waitForFunction(()=>document.querySelector('[data-profile-phone]')?.dataset.callState==='idle');
  const f={context,page,requests,errors,foreign,start:()=>page.locator('[data-profile-phone-start]').click(),
    hold:action=>new Promise(arrived=>holds.push({action,arrived})),
    async connected(){await f.start();await page.waitForFunction(()=>phoneFixture.calls.length>0);Object.assign(record,{state:'answered',answered:true,route_kind:'owner'});await page.evaluate(()=>phoneFixture.calls.at(-1).accept());await page.getByText('Connected to Dave.',{exact:true}).waitFor();},
    async refresh(){await page.evaluate(()=>window.dispatchEvent(new Event('focus')));},
    setRecord(patch){Object.assign(record,patch);},
    setPublished(value){published=value;},setBrowser(value){browserEnabled=value;},setNumber(value){phone=value;},
    setAuthorized(value){authorized=value;},setLease(value){leaseMs=value;},setStateUnavailable(value){unavailableState=value;},
    async close(){check(errors.length===0,'production Profile and phone scripts emit no runtime errors: '+errors.join('; '));check(foreign.length===0,'browser fixture never contacts Twilio or another external service');await context.close();}
  };
  return f;
}
async function evidence(page,name){
  if(!process.env.PROFILE_PHONE_EVIDENCE_DIR)return;
  fs.mkdirSync(process.env.PROFILE_PHONE_EVIDENCE_DIR,{recursive:true});
  await page.screenshot({path:path.join(process.env.PROFILE_PHONE_EVIDENCE_DIR,name)});
}

try{
  {
    const f=await fixture({permission:'pending',queuedHandoff:true});
    check(await f.page.evaluate(()=>phoneFixture.sdkLoads===0&&phoneFixture.calls.length===0),'opening a Profile never loads the SDK, captures a microphone, or calls automatically');
    await f.page.locator('[data-profile-phone-start]').evaluate(button=>button.click());
    check(!f.requests.some(r=>r.input.action==='prepare')&&await f.page.evaluate(()=>phoneFixture.sdkLoads===0),'synthetic clicks cannot start an automatic phone call');
    check(await f.page.locator('.profile-phone-link').getAttribute('href')==='tel:+16025550101','phone-app choice remains next to browser calling');
    await f.start();await f.page.getByText('Allow microphone access to start your call.',{exact:true}).waitFor();
    const prepare=f.requests.find(r=>r.input.action==='prepare');
    check(prepare.input.username==='dave'&&prepare.input.profile_token===profileToken&&prepare.input.conversation_namespace===namespace&&prepare.input.conversation_id===0&&/^[a-f0-9]{32}$/.test(prepare.input.client_request_id),'actual Profile adapter supplies canonical visitor binding and unique explicit call intent');
    check(Object.keys(prepare.input).sort().join(',')==='action,client_request_id,conversation_id,conversation_namespace,profile_token,username','call admission carries no destination, arbitrary number, or private Chat content');
    check(await f.page.evaluate(key=>localStorage.getItem(key),storageKey)==='31'&&!f.requests.some(r=>r.input.action==='message'),'call-only flow adopts its server conversation without inventing a visitor message');
    await f.page.getByText('Phone call in progress',{exact:true}).waitFor();
    check(await f.page.locator('[data-profile-agent-disclosure]').textContent()==='Profile Agent replies are paused during your phone call.'&&!f.requests.some(r=>r.input.action==='dispatch'),'canonical phone hold uses neutral presence and pauses queued text without claiming the owner is live');
    await f.page.locator('.profile-agent-compose textarea').fill('Keep this draft until after the phone call');
    await f.page.locator('.profile-agent-compose').evaluate(form=>form.requestSubmit());
    check(await f.page.locator('.profile-agent-compose textarea').inputValue()==='Keep this draft until after the phone call'&&!f.requests.some(r=>r.input.action==='message')&&await f.page.locator('[data-profile-agent-status]').textContent()==='Finish your phone call to continue with the Profile Agent. Your draft stays here.','phone hold preserves a new Agent draft without submitting, queuing, or claiming an owner joined');
    check(await f.page.evaluate(()=>phoneFixture.registrations===0&&Object.keys(phoneFixture.connects[0].params).join(',')==='profile_call_intent'),'outbound device uses only the opaque Profile intent and never registers for incoming calls');
    await f.page.evaluate(()=>phoneFixture.calls[0].grant());await f.page.getByText('Connecting your call…',{exact:true}).waitFor();
    await f.page.evaluate(()=>phoneFixture.calls[0].emit('ringing'));await f.page.getByText('Ringing Dave…',{exact:true}).waitFor();
    check(await f.page.getByRole('button',{name:'Mute',exact:true}).isEnabled(),'microphone and ringing stages expose usable mute and hang-up controls');
    await f.page.evaluate(()=>phoneFixture.calls[0].accept());await f.page.getByText('Phone service connected. Waiting for Dave…',{exact:true}).waitFor();
    check(await f.page.getByText('Connected to Dave.',{exact:true}).count()===0,'SDK accept alone does not claim the profile owner answered');
    f.setRecord({state:'answered',answered:true,route_kind:'owner'});await f.refresh();await f.page.getByText('Connected to Dave.',{exact:true}).waitFor();
    await evidence(f.page,'profile-browser-phone-desktop.png');
    await f.page.getByRole('button',{name:'Mute',exact:true}).click();
    check(await f.page.getByRole('button',{name:'Unmute',exact:true}).getAttribute('aria-pressed')==='true'&&await f.page.evaluate(()=>phoneFixture.calls[0].isMuted()),'mute state is reflected in the SDK and accessible control');
    await f.page.getByRole('button',{name:'Unmute',exact:true}).click();
    const held=f.hold('end');await f.page.getByRole('button',{name:'Hang up',exact:true}).click();const ending=await held;
    check(await f.page.evaluate(()=>phoneFixture.tracks.every(t=>t.readyState==='ended')&&phoneFixture.devices.every(d=>d.destroyed)&&!StonefellowVoiceLeaseV122.owns()),'Hang up stops tracks, destroys device and releases capture before server acknowledgement');
    check(await f.page.locator('[data-profile-phone-start]').isDisabled(),'another call cannot start while server ending is uncertain');
    await ending.release({});await f.page.getByText('Call ended.',{exact:true}).waitFor();
    check(ending.input.call_id===callId&&ending.input.client_request_id===prepare.input.client_request_id&&ending.input.conversation_id===31,'end is bound to the exact prepared call and adopted visitor conversation');
    await f.close();
  }
  {
    const f=await fixture({viewport:{width:390,height:844},cid:47,ownerJoined:true});
    await f.connected();
    await f.page.getByText('Phone call in progress',{exact:true}).waitFor();
    check(f.requests.find(r=>r.input.action==='prepare').input.conversation_id===47&&await f.page.evaluate(key=>localStorage.getItem(key),storageKey)==='47','calling inside an existing chat keeps its conversation and immediately refreshes the canonical phone hold');
    await f.page.locator('.profile-agent-compose textarea').fill('Message in the existing owner conversation');
    await f.page.locator('.profile-agent-compose').evaluate(form=>form.requestSubmit());
    await f.page.waitForFunction(()=>!document.querySelector('.profile-agent-compose textarea').disabled);
    check(f.requests.some(r=>r.input.action==='message'&&r.input.conversation_id===47&&r.input.chat_mode==='auto'),'a phone call preserves messaging only in an owner conversation that was already joined');
    await evidence(f.page,'profile-browser-phone-mobile.png');
    check(await f.page.locator('[data-profile-phone]').evaluate(host=>host.getBoundingClientRect().right<=innerWidth&&[...host.querySelectorAll('button:not([hidden])')].every(button=>button.getBoundingClientRect().right<=innerWidth)),'browser call controls fit the mobile chat width');
    await f.page.getByRole('button',{name:'Close Profile Agent',exact:true}).click();
    check(await f.page.evaluate(()=>phoneFixture.tracks.every(t=>t.readyState==='ended')&&phoneFixture.devices.every(d=>d.destroyed)),'closing the real Profile widget immediately cleans up browser audio');
    await f.close();
  }
  {
    const f=await fixture();const held=f.hold('prepare');const click=f.start();const prepared=await held;await click;
    check(await f.page.locator('.profile-agent-compose button[type=submit]').isDisabled(),'call admission fences zero-conversation text creation until the canonical ID arrives');
    await f.page.getByRole('button',{name:'Hang up',exact:true}).click();await f.page.getByText('Call ended.',{exact:true}).waitFor();
    check(await f.page.evaluate(key=>localStorage.getItem(key),storageKey)==='31','cancellation receipt recovers the exact admitted conversation before future text');
    await prepared.release({});await f.page.waitForFunction(()=>!document.querySelector('.profile-agent-compose button[type=submit]').disabled);
    check(await f.page.evaluate(()=>phoneFixture.devices.length===0&&phoneFixture.connects.length===0),'late prepare after cancellation cannot load a device or start audio');
    check(f.requests.some(r=>r.input.action==='end'&&r.input.client_request_id===prepared.input.client_request_id&&!r.input.call_id),'cancel during prepare uses the exact request ID before a call ID is known');
    await f.close();
  }
  {
    const f=await fixture();const held=f.hold('prepare');await f.start();const prepared=await held;
    await f.page.getByText('The call could not be started. Your microphone is off. Check call status before trying again.',{exact:true}).waitFor();
    check(f.requests.filter(r=>r.input.action==='prepare').length===1&&f.requests.some(r=>r.input.action==='end'&&r.input.client_request_id===prepared.input.client_request_id)&&await f.page.evaluate(()=>phoneFixture.devices.length===0),'lost prepare acknowledgement cancels its exact admission without retrying or dialing');
    check(await f.page.evaluate(key=>localStorage.getItem(key),storageKey)==='31','lost prepare response recovers its canonical conversation from the verified end receipt');
    await prepared.release({});await f.close();
  }
  {
    const f=await fixture();await f.connected();const held=f.hold('end');await f.page.getByRole('button',{name:'Hang up',exact:true}).click();const ending=await held;
    await f.page.getByText('Your microphone is off. The call ending could not be confirmed. Check call status before trying again.',{exact:true}).waitFor();
    check(await f.page.locator('[data-profile-phone-start]').isDisabled()&&await f.page.evaluate(()=>phoneFixture.tracks.every(t=>t.readyState==='ended')),'lost end acknowledgement leaves audio off and blocks another call');
    await f.page.getByRole('button',{name:'Check call status',exact:true}).click();await f.page.getByText('Call ended.',{exact:true}).waitFor();
    check(f.requests.filter(r=>r.input.action==='prepare').length===1&&await f.page.locator('[data-profile-phone-start]').isEnabled(),'read-only status recovery confirms the ended call without creating a new intent');
    await ending.release({});await f.close();
  }
  {
    const f=await fixture({holdConnect:true});
    await f.start();await f.page.waitForFunction(()=>typeof phoneFixture.releaseConnect==='function');
    await f.page.getByRole('button',{name:'Hang up',exact:true}).click();await f.page.getByText('Call ended.',{exact:true}).waitFor();
    check(await f.page.locator('[data-profile-phone-start]').isEnabled(),'confirmed cancellation releases the call controls even if the old SDK promise is still pending');
    await f.page.evaluate(()=>phoneFixture.releaseConnect());await f.page.waitForFunction(()=>phoneFixture.calls[0].disconnected);
    check(await f.page.evaluate(()=>phoneFixture.tracks.every(t=>t.readyState==='ended')),'late Device.connect result after hang-up is disconnected and its tracks stopped');
    await f.close();
  }
  {
    const f=await fixture({permission:'pending'});
    await f.start();await f.page.waitForFunction(()=>phoneFixture.calls.length===1);
    await f.page.getByRole('button',{name:'Hang up',exact:true}).click();await f.page.getByText('Call ended.',{exact:true}).waitFor();
    await f.page.evaluate(()=>phoneFixture.calls[0].grant());
    check(await f.page.evaluate(()=>phoneFixture.tracks.every(t=>t.readyState==='ended')),'late microphone permission after cancel cannot leave capture running');
    await f.close();
  }
  {
    const f=await fixture({permission:'denied'});
    await f.start();await f.page.getByText('Microphone access was not allowed. Enable it in your browser before trying again.',{exact:true}).waitFor();
    check(await f.page.locator('[data-profile-phone-start]').isEnabled()&&await f.page.evaluate(()=>phoneFixture.devices.every(d=>d.destroyed)),'microphone denial provides a usable error and fully cleans the failed device');
    await f.close();
  }
  {
    const f=await fixture();await f.connected();f.setAuthorized(false);await f.refresh();
    await f.page.getByRole('button',{name:'Reload chat',exact:true}).waitFor();
    check(await f.page.evaluate(()=>phoneFixture.tracks.every(t=>t.readyState==='ended')&&phoneFixture.devices.every(d=>d.destroyed)),'account or visitor-session revocation closes media through the existing Profile session fence');
    await f.close();
  }
  {
    const f=await fixture();await f.connected();f.setLease(650);
    const renewed=f.page.waitForResponse(response=>response.url().includes('/api/profile-phone-browser-v1.php')&&new URL(response.url()).searchParams.get('action')==='state');
    await f.refresh();await renewed;f.setStateUnavailable(true);
    await f.page.getByText('Call access could not be renewed. Your microphone is off.',{exact:true}).waitFor();
    check(await f.page.evaluate(()=>phoneFixture.tracks.every(t=>t.readyState==='ended')),'expired server authorization stops capture instead of keeping a call alive through failed status reads');
    await f.close();
  }
  {
    const f=await fixture();await f.page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:profile-call-state',{detail:{busy:true,source:'profile-calls-pa3c'}})));
    check(await f.page.locator('[data-profile-phone-start]').isDisabled()&&!f.requests.some(r=>r.input.action==='prepare'),'native Profile call activity prevents concurrent browser-phone admission');
    await f.page.evaluate(()=>window.dispatchEvent(new CustomEvent('vp3:profile-call-state',{detail:{busy:false,source:'profile-calls-pa3c'}})));
    await f.connected();await f.page.evaluate(()=>StonefellowVoiceLeaseV122.yield());
    await f.page.getByText('Microphone ownership changed. Your microphone is off.',{exact:true}).waitFor();
    check(await f.page.evaluate(()=>phoneFixture.tracks.every(t=>t.readyState==='ended')),'shared capture loss immediately ends the exact phone call');
    await f.close();
  }
  {
    const f=await fixture();await f.connected();f.setRecord({answered:false,route_kind:'agent',state:'dialing'});await f.refresh();await f.page.getByText('Connected to Dave’s phone Agent.',{exact:true}).waitFor();
    f.setRecord({route_kind:'voicemail'});await f.refresh();await f.page.getByText('Connected to voicemail.',{exact:true}).waitFor();
    check(await f.page.getByText('Connected to Dave.',{exact:true}).count()===0,'Agent and voicemail fallback states do not claim the owner is live');
    f.setRecord({state:'no_answer',terminal:true});await f.page.evaluate(()=>phoneFixture.calls[0].disconnect());await f.page.getByText('There was no answer. You can send a message in Chat.',{exact:true}).waitFor();
    check(await f.page.evaluate(()=>phoneFixture.tracks.every(t=>t.readyState==='ended')),'authoritative no-answer outcome survives SDK disconnect and closes local audio');
    await f.close();
  }
  {
    const f=await fixture();f.setNumber('+16025550202');await f.start();await f.page.getByText('The phone line changed. Select Call in browser again.',{exact:true}).waitFor();
    check(!f.requests.some(r=>r.input.action==='prepare'),'a changed approved line requires another explicit browser-call click');
    f.setPublished(false);await f.refresh();await f.page.locator('[data-profile-phone]').waitFor({state:'hidden'});
    check(await f.page.locator('.profile-phone-link').getAttribute('href')===null,'revoked approval removes both browser calling and stale phone-app target');
    await f.close();
  }
  {
    const f=await fixture({sdkUrl:'https://untrusted.invalid/twilio.js'});await f.start();await f.page.getByText('The call could not be started. Your microphone is off. Check call status before trying again.',{exact:true}).waitFor();
    check(await f.page.evaluate(()=>phoneFixture.sdkLoads===0&&phoneFixture.devices.length===0),'untrusted SDK origin is rejected before token-bearing device creation');
    await f.close();
  }
  {
    const f=await fixture({browserEnabled:false});
    check(await f.page.locator('[data-profile-phone-start]').isHidden()&&await f.page.locator('.profile-phone-link').getAttribute('href')==='tel:+16025550101','unavailable browser integration retains the owner-approved phone-app option');
    await f.close();
  }
  console.log('PROFILE_PHONE_CALL_BROWSER_V1=PASS '+checks+' checks');
}finally{await browser.close();}
