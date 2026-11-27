import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(new URL('..',import.meta.url).pathname),requests=[],contexts=new Map();let nextContext=10,notices=[],denied=false;
const callId='a'.repeat(32),callId2='b'.repeat(32);
const sdk=`(()=>{const m=window.phoneTest={devices:[],accepted:0,rejected:0,captures:0,releases:0};
class Call{constructor(id){this.events={};this.customParameters=new Map([['phone_call_id',id]]);this.parameters={From:'+16025550123'};this.phase='pending';}
on(n,f){(this.events[n]??=[]).push(f);return this;}emit(n){for(const f of this.events[n]||[])f();}status(){return this.phase;}
accept(){m.accepted++;this.phase='open';this.emit('accept');}reject(){m.rejected++;this.phase='closed';this.emit('reject');}disconnect(){this.phase='closed';this.emit('disconnect');}getLocalStream(){return null;}}
class Device{constructor(){this.events={};m.devices.push(this);}on(n,f){(this.events[n]??=[]).push(f);return this;}emit(n,...args){for(const f of this.events[n]||[])f(...args);}async register(){this.emit('registered');}destroy(){}disconnectAll(){}updateToken(){}}
window.Twilio={Device};m.incoming=id=>{const call=new Call(id);m.devices.at(-1).emit('incoming',call);};
})();`;
const integrity='sha384-'+createHash('sha384').update(sdk).digest('base64');
const state=()=>({numbers:[{id:1,phone_e164:'+16025550111',agent_id:7,agent_name:'Fixture Agent',voice_enabled:true,browser_enabled:true}],selected_number_id:1,
 contacts:[],favorites:[],calls:[],voicemails:[],active_call:null,readiness:{ready:true,reason:''},recording_readiness:{ready:false,reason:'Optional recording storage is not installed.'},post_call_policy:null});
let nativeState=state();
const server=http.createServer(async(req,res)=>{
 const u=new URL(req.url,'http://fixture'),owner=Number((req.headers.cookie||'').match(/fixture_owner=(\d+)/)?.[1]||1);
 if(u.pathname==='/assets/twilio-voice-2.18.5.min.js'){res.setHeader('Content-Type','text/javascript');return res.end(sdk);}
 if(u.pathname==='/fixture-premium.js'){res.setHeader('Content-Type','text/javascript');return res.end("window.spoken=[];window.StonefellowPremiumVoiceV122=()=>({speak:async(text,cb)=>{spoken.push(text);cb.onStart();cb.onEnd();return true;},stop(){}});");}
 if(u.pathname.startsWith('/api/')){
  const data=req.method==='GET'?Object.fromEntries(u.searchParams):await new Promise(resolve=>{let raw='';req.on('data',x=>raw+=x);req.on('end',()=>resolve(JSON.parse(raw)));});
  requests.push({owner,action:data.action,conversation_id:Number(data.conversation_id||0),method:req.method});res.setHeader('Content-Type','application/json');
  if(denied){res.statusCode=403;return res.end('{"ok":false}');}
  if(u.pathname==='/api/agent-phone-shell-v1.php'){
   assert.equal(req.headers['x-vp3-working-attention'],'binding-'+owner);assert.equal(data.csrf_token,'csrf-'+owner);
   if(data.action==='phone_context'){assert.equal(data.create,true);assert.equal(data.agent_id,7);if(!contexts.has(owner))contexts.set(owner,nextContext++);
    return res.end(JSON.stringify({ok:true,owner_user_id:owner,context:{owner_user_id:owner,agent_id:7,conversation_id:contexts.get(owner)}}));}
   if(data.action==='activity')return res.end(JSON.stringify({ok:true,owner_user_id:owner,updates:owner===1?notices:[]}));
  }
  if(data.action==='state')return res.end(JSON.stringify({ok:true,state:nativeState}));
  if(data.action==='token'){assert.equal(data.conversation_id,contexts.get(owner));return res.end(JSON.stringify({ok:true,token:'synthetic-fixture-token-only',identity:'fixture-'+owner,expires_at:Date.now()+300000}));}
  if(data.action==='bind_call'){assert.equal(data.conversation_id,contexts.get(owner));assert.equal(data.agent_id,7);return res.end(JSON.stringify({ok:true,call:{public_id:data.call_id,agent_id:7,conversation_id:contexts.get(owner),peer_e164:'+16025550123',direction:'inbound',state:'ringing'}}));}
  res.statusCode=400;return res.end('{"ok":false}');
 }
 const name=path.basename(u.pathname);
 if(['voice-lease-v122.js','phone-workspace-v1.js','phone-workspace-v1.css','agent-phone-shell-v1.js','agent-composer-v1.css','agent-orb.js','agent-orb.css','agent-eyes-core.js','chat-notifications-drawer-v240.js'].includes(name)){
  res.setHeader('Content-Type',name.endsWith('.css')?'text/css':'text/javascript');return res.end(fs.readFileSync(path.join(root,name)));
 }
 const account=Number(u.searchParams.get('owner')||owner),admin=u.pathname.startsWith('/admin/'),chat=u.pathname==='/chat.php';
 res.setHeader('Set-Cookie','fixture_owner='+account+'; Path=/; SameSite=Lax');res.setHeader('Content-Type','text/html');
 const config={endpoint:'/api/phone-workspace-v1.php',urlbase:'/',owner_id:account,agent_id:0,conversation_id:chat?contexts.get(account)||0:0,csrf:'csrf-'+account,
  global_surface:!chat,context_endpoint:'/api/agent-phone-shell-v1.php',attention_binding:'binding-'+account,sdk_url:'/assets/twilio-voice-2.18.5.min.js',sdk_integrity:integrity};
 const bridge={endpoint:'/api/agent-phone-shell-v1.php',owner_id:account,csrf:'csrf-'+account,binding:'binding-'+account,enabled:!chat,admin_surface:admin,
  voice_enabled:true,voice_script:'/fixture-premium.js',drawer_script:'/chat-notifications-drawer-v240.js',notifications_endpoint:'/api/notifications',chat_endpoint:'/api/chat.php'};
 res.end(`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/phone-workspace-v1.css"><link rel="stylesheet" href="/agent-orb.css"><link rel="stylesheet" href="/agent-composer-v1.css"><style>body{font:14px system-ui;margin:0}header{padding:12px;display:flex;justify-content:flex-end}main{padding:20px}</style>
 <header class="${admin?'admin-page-actions':'chat-topbar-actions'}">${admin?'':'<button data-phone-workspace-toggle aria-label="Phone">Phone</button>'}</header><main><h1>${admin?'Admin settings':'Phone Tracking'}</h1>${chat?'<div id="chatThread"></div><form id="chatForm"><textarea id="chatInput"></textarea></form>':''}</main>
 <script>window.VP3_PHONE_WORKSPACE_V1=${JSON.stringify(config)};window.VP3_AGENT_PHONE_SHELL_V1=${JSON.stringify(bridge)};window.activity=[];addEventListener('vp3:personal-phone-activity',e=>activity.push(e.detail));
 ${admin||chat?'':"window.VP3_ORB_ADAPTER={ownerKey:'"+account+"',chatUrl:'/chat.php',state:()=>({state:'idle'}),isMainChat:()=>false,busy:()=>false,stop(){},listen(){}};"}</script>
 <script data-voice-capture-scope="${account}" src="/voice-lease-v122.js" defer></script><script src="/phone-workspace-v1.js" defer></script>${admin||chat?'':'<script src="/agent-eyes-core.js" defer></script><script src="/agent-orb.js" defer></script>'}<script src="/agent-phone-shell-v1.js" defer></script>`);
});await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base='http://127.0.0.1:'+server.address().port;
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});let checks=0;
const context=await browser.newContext({viewport:{width:1280,height:850}}),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
try{
 await page.goto(base+'/phone-tracking.php');await page.bringToFront();await page.getByRole('button',{name:'Phone',exact:true}).click();
 await check('a member page can enable Phone without opening Chat',async()=>{await page.waitForFunction(()=>{const b=document.querySelector('.vp3-phone-connection button');return b&&!b.disabled;});assert.equal(await page.locator('#chatThread').count(),0);assert.equal(await page.evaluate(()=>!!window.phoneTest),false);assert.equal(contexts.size,0);});
 await page.getByRole('button',{name:'Enable calls in this browser',exact:true}).click();await page.getByRole('button',{name:'Turn off browser calls',exact:true}).waitFor();
 await check('explicit enable saves one private context and no microphone is taken',async()=>{assert.equal(contexts.size,1);assert.equal(await page.evaluate(()=>phoneTest.devices.length),1);assert.equal(await page.evaluate(()=>STONEFELLOW_VOICE_LEASE_V122.mediaClaims),0);assert.equal(await page.evaluate(()=>localStorage.getItem('vp3-browser-calls-v2.1.1')),'enabled');});
 await page.reload();await page.waitForFunction(()=>window.phoneTest?.devices.length===1);
 await check('reload restores enabled state using the same private context',async()=>{assert.equal(contexts.size,1);assert.equal(await page.evaluate(()=>STONEFELLOW_VOICE_LEASE_V122.mediaClaims),0);assert.equal(await page.locator('.vp3-agent-composer textarea').count(),1);assert.equal(await page.getByRole('button',{name:'Chat tools',exact:true}).count(),1);});
 if(process.env.VP3_SHELL_SCREENSHOT_DIR){fs.mkdirSync(process.env.VP3_SHELL_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.VP3_SHELL_SCREENSHOT_DIR,'member-footer.png')});}
 await page.goto(base+'/admin/phone.php');await page.bringToFront();await page.waitForFunction(()=>window.phoneTest?.devices.length===1);
 await check('personal calling follows the user into Admin without public Agent chrome',async()=>{assert.equal(await page.locator('[data-phone-workspace-toggle]').count(),1);assert.equal(await page.locator('.vp3-orb-bar,.vp3-orb-root,.chat-transcription-canvas-button').count(),0);assert.equal(await page.evaluate(()=>STONEFELLOW_VOICE_LEASE_V122.mediaClaims),0);});
 await page.evaluate(id=>phoneTest.incoming(id),callId);
 const card=page.getByRole('region',{name:'Personal phone activity'});await card.getByRole('button',{name:'Answer',exact:true}).waitFor();
 await check('incoming calls offer Answer and Decline off Chat without taking a microphone',async()=>{assert.equal(await card.getByRole('button',{name:'Decline',exact:true}).count(),1);assert.equal(await page.evaluate(()=>STONEFELLOW_VOICE_LEASE_V122.mediaClaims),0);});
 if(process.env.VP3_SHELL_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.VP3_SHELL_SCREENSHOT_DIR,'admin-incoming-call.png')});
 await card.getByRole('button',{name:'Decline',exact:true}).click();
 await check('Decline rejects only the matching native call',async()=>{assert.equal(await page.evaluate(()=>phoneTest.rejected),1);assert.equal(requests.filter(x=>x.action==='bind_call').length,0);});
 await page.evaluate(id=>phoneTest.incoming(id),callId2);await card.getByRole('button',{name:'Answer',exact:true}).click();await card.getByRole('button',{name:'End call',exact:true}).waitFor();
 await check('Answer binds the saved Phone context before accepting native audio',async()=>{assert.equal(await page.evaluate(()=>phoneTest.accepted),1);assert.equal(await page.evaluate(()=>STONEFELLOW_VOICE_LEASE_V122.mediaClaims),1);assert.equal(requests.filter(x=>x.action==='bind_call').at(-1).conversation_id,contexts.get(1));});
 await page.evaluate(()=>{const frame=document.createElement('iframe');frame.src='/chat.php?orb_embed=1';document.body.append(frame);});
 await page.waitForFunction(()=>document.querySelector('iframe')?.contentWindow.VP3PhoneWorkspaceV1);
 await check('a Chat popup shares the parent phone runtime without a duplicate device',async()=>{assert.equal(await page.evaluate(()=>phoneTest.devices.length),1);assert.equal(await page.frameLocator('iframe').locator('.vp3-phone-workspace').count(),0);});
 await card.getByRole('button',{name:'End call',exact:true}).click();
 notices=[{id:1,message_id:50,conversation_id:10,agent_id:0,speech:'A connected phone sent a chat message.',url:'/notifications.php?open=1'}];
 await page.evaluate(()=>VP3AgentPhoneShellV1.refresh());await card.getByRole('link',{name:'Open Chat'}).waitFor();
 await check('connected-phone activity announces in Admin and links the durable personal Chat notice',async()=>{assert.equal(await card.getByRole('link',{name:'Open Chat'}).getAttribute('href'),base+'/notifications.php?open=1');await page.waitForFunction(()=>window.spoken?.length===1);assert.equal(await page.evaluate(()=>activity.length),1);});
 await page.evaluate(()=>VP3AgentPhoneShellV1.refresh());assert.equal(await page.evaluate(()=>activity.length),1);
 await page.reload();await page.bringToFront();await page.waitForFunction(()=>window.phoneTest?.devices.length===1);await page.evaluate(()=>VP3AgentPhoneShellV1.refresh());
 await check('page changes do not repeat an already announced unread event',async()=>{assert.equal(await page.evaluate(()=>activity.length),0);assert.equal(await page.evaluate(()=>spoken.length),0);});
 await page.getByRole('button',{name:'Phone',exact:true}).click();await page.getByRole('button',{name:'Turn off browser calls',exact:true}).click();await page.reload();
 await check('an explicit disable remains off after reload',async()=>{await page.getByRole('button',{name:'Phone',exact:true}).click();await page.getByRole('button',{name:'Enable calls in this browser',exact:true}).waitFor();assert.equal(await page.evaluate(()=>!!window.phoneTest),false);});
 await page.goto(base+'/admin/phone.php?owner=2');await page.getByRole('button',{name:'Phone',exact:true}).click();
 await check('another account does not inherit this browser enable choice or phone notifications',async()=>{await page.getByRole('button',{name:'Enable calls in this browser',exact:true}).waitFor();assert.equal(await page.evaluate(()=>!!window.phoneTest),false);assert.equal(contexts.has(2),false);assert.equal(await page.evaluate(()=>activity.length),0);});
 for(const width of [320,390]){await page.setViewportSize({width,height:740});await page.waitForFunction(()=>{const r=document.querySelector('.vp3-phone-panel').getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1;});const box=await page.locator('.vp3-phone-panel').boundingBox();assert.ok(box.x>=-1&&box.x+box.width<=width+1);}
 await check('phone controls fit 320px and 390px screens',async()=>{assert.deepEqual(errors,[]);});
 console.log('AGENT_PHONE_SHELL_BROWSER_V1=PASS ('+checks+' cases)');
}catch(error){console.error(JSON.stringify({errors,requests,phone:await page.locator('.vp3-phone-panel').innerText().catch(()=>''),hidden:await page.locator('.vp3-phone-workspace').getAttribute('hidden').catch(()=>null)}));throw error;}
finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
