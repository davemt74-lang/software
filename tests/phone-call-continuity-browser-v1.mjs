import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(new URL('..',import.meta.url).pathname),read=f=>fs.readFileSync(path.join(root,f),'utf8');
const render=(owner,role,agent,cid)=>execFileSync(process.env.PHP_BINARY||'php',['-r',`
function db(){static $p;return $p??=new PDO('sqlite::memory:');}function vp3_phone_workspace_available_v1($p,$u){return true;}
function url($p){return $p;}function e($v){return htmlspecialchars((string)$v,ENT_QUOTES);}function csrf_token(){return 'continuity-fixture-csrf';}
require ${JSON.stringify(root+'/includes/agent-catch-up-v1.php')};require ${JSON.stringify(root+'/includes/phone-workspace-ui-v1.php')};
$GLOBALS['VP3_PHONE_CONTINUITY_OWNER_V1']=${role==='owner'?'true':'false'};
echo vp3_phone_workspace_runtime_v1(['id'=>${owner}],${agent},${cid});`],{encoding:'utf8'});
const markups=new Map(),bindings=new Map();
function markup(owner,role,agent,cid){const k=[owner,role,agent,cid].join('/');if(!markups.has(k)){const html=render(owner,role,agent,cid);markups.set(k,html);const cfg=JSON.parse(html.match(/window\.VP3_PHONE_WORKSPACE_V1=(.*?);<\/script>/s)[1]);bindings.set(owner,cfg.attention_binding);}return markups.get(k);}
markup(1,'view',7,101);markup(2,'view',8,202);
let denied=false,captionCall='';const calls=new Map(),requests=[],captionRequests=[],tokenCounts=new Map(),errors=[];
const nativeState=owner=>({numbers:[{id:owner,phone_e164:owner===1?'+16025550111':'+16025550222',agent_id:owner===1?7:8,agent_name:'Personal Agent',voice_enabled:true,browser_enabled:true}],
 selected_number_id:owner,contacts:[],favorites:[],calls:[...calls.values()].filter(c=>c.owner===owner),voicemails:[],active_call:null,
 readiness:{ready:true,reason:''},recording_readiness:{ready:false,reason:'Optional recording storage is not installed.'},post_call_policy:null,
 operator_live:{available:true,eligible:!!captionCall,call_id:captionCall,state:'off',reason:''}});
const server=http.createServer(async(req,res)=>{
 const u=new URL(req.url,'http://fixture'),owner=Number((req.headers.cookie||'').match(/fixture_owner=(\d+)/)?.[1]||1);
 if(u.pathname.startsWith('/api/')){
  let data=Object.fromEntries(u.searchParams);if(req.method==='POST'){let raw='';for await(const chunk of req)raw+=chunk;data=JSON.parse(raw);}
  res.setHeader('Content-Type','application/json');
  if(u.pathname==='/api/phone-operator-live-v1.php'){
   assert.equal(owner,1);assert.equal(data.call_id,captionCall);assert.equal(data.agent_id,7);assert.equal(data.conversation_id,101);assert.equal(data.csrf_token,'continuity-fixture-csrf');
   captionRequests.push({action:data.action,seq:data.seq,bytes:data.pcm?Buffer.from(data.pcm,'base64').length:0});
   const token='f'.repeat(64);
   if(data.action==='start'){assert.equal(data.consent_attested,true);return res.end(JSON.stringify({ok:true,call_id:captionCall,token,expires_epoch:Math.floor(Date.now()/1000)+120,chunk_seconds:4,execution:'captions_only'}));}
   assert.equal(data.token,token);
   if(data.action==='chunk'){assert.equal(Buffer.from(data.pcm,'base64').length,128000);return res.end(JSON.stringify({ok:true,call_id:captionCall,seq:data.seq,speaker:'remote_audio',text:'Caption segment '+data.seq}));}
   assert.equal(data.action,'stop');return res.end(JSON.stringify({ok:true,call_id:captionCall,stopped:true}));
  }
  requests.push({owner,path:u.pathname,method:req.method,...data});
  if(denied||req.headers['x-vp3-working-attention']!==bindings.get(owner)){res.statusCode=403;return res.end('{"ok":false,"error":"phone_account_changed"}');}
  if(Number(data.agent_id)===9){res.statusCode=403;return res.end('{"ok":false,"error":"phone_scope_changed"}');}
  if(data.action==='state')return res.end(JSON.stringify({ok:true,state:nativeState(owner)}));
  if(data.action==='token'){assert.equal(Number(data.conversation_id),owner===1?101:202);tokenCounts.set(owner,(tokenCounts.get(owner)||0)+1);return res.end(JSON.stringify({ok:true,token:'synthetic-transport-token-not-a-provider-credential',identity:'fixture-'+owner,expires_at:Date.now()+300000}));}
  if(data.action==='bind_call'){const call=calls.get(data.call_id);assert.equal(call?.owner,owner);assert.equal(Number(data.conversation_id),call.conversation_id);return res.end(JSON.stringify({ok:true,call}));}
  res.statusCode=400;return res.end('{"ok":false}');
 }
 if(u.pathname.endsWith('.js')||u.pathname.endsWith('.css')){
  const file=path.join(root,u.pathname);if(!fs.existsSync(file)){res.statusCode=404;return res.end();}
  assert.ok(!u.pathname.includes('twilio-voice'),'Tests must not load or contact the real telephony SDK/provider');
  res.setHeader('Content-Type',u.pathname.endsWith('.css')?'text/css':'text/javascript');return res.end(fs.readFileSync(file));
 }
 const account=Number(u.searchParams.get('owner')||owner),isOwner=u.pathname==='/phone-call-window-v1.php',admin=u.pathname.startsWith('/admin/');
 if(isOwner&&account!==owner){res.statusCode=403;return res.end('Your account changed. Reopen Phone from VP3.');}
 res.setHeader('Set-Cookie','fixture_owner='+account+'; Path=/; SameSite=Lax');res.setHeader('Content-Type','text/html');
 const agent=Number(u.searchParams.get('agent_id')||(u.pathname==='/no-phone.php'?9:admin?0:account===1?7:8));
 const cid=Number(u.searchParams.get('conversation_id')||(admin?0:account===1?101:202));
 const bridge={endpoint:'/api/agent-phone-shell-v1.php',owner_id:account,csrf:'continuity-fixture-csrf',binding:bindings.get(account),enabled:false,voice_enabled:false,admin_surface:admin};
 res.end('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
  '<style>body{margin:0;font:14px system-ui}header{padding:12px;display:flex;justify-content:flex-end}main{padding:16px}</style>'+
  (isOwner?'<body class="vp3-phone-call-window"><header class="vp3-call-window-heading"><strong>VP3 · Phone</strong><p id="phoneCallWindowStatus" role="status">Checking your calling window…</p></header>':
   '<body><header class="'+(admin?'admin-page-actions':'chat-topbar-actions')+'"><button data-phone-workspace-toggle aria-label="Phone">Phone</button></header><main><h1>'+(admin?'Admin settings':'Member workspace')+'</h1></main>')+
  (u.searchParams.get('bridge')==='missing'?markup(account,isOwner?'owner':'view',agent,cid).replace(/<script src="[^"]*phone-call-continuity-v1\.js[^"]*" defer><\/script>/,''):markup(account,isOwner?'owner':'view',agent,cid))+(isOwner?'':'<link rel="stylesheet" href="/agent-composer-v1.css"><script>window.VP3_AGENT_PHONE_SHELL_V1='+JSON.stringify(bridge)+'</script><script src="/agent-phone-shell-v1.js" defer></script>'));
});await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const base='http://127.0.0.1:'+server.address().port;
const browser=await chromium.launch({headless:true,args:['--no-sandbox','--use-fake-ui-for-media-stream','--use-fake-device-for-media-stream'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const transport=()=>{
 const proof=window.phoneMediaFixture={devices:[],calls:[],accepted:0,disconnected:0,mediaStarts:0,packets:[]};
 const nativePost=BroadcastChannel.prototype.postMessage;
 BroadcastChannel.prototype.postMessage=function(data){if(data?.protocol==='vp3-phone-window-v1'){proof.packets.push({name:this.name,data:structuredClone(data)});if(proof.dropReplies&&data.type==='reply')return;}return nativePost.call(this,data);};
 class Call{
  constructor(id){this.id=id;this.events={};this.parameters={From:'+16025550999'};this.customParameters=new Map([['phone_call_id',id]]);this.phase='pending';this.muted=false;proof.calls.push(this);}
  on(n,f){(this.events[n]??=[]).push(f);return this;}emit(n){for(const f of this.events[n]||[])f();}status(){return this.phase;}
  accept(){proof.accepted++;void this.media();}
  async media(){
   proof.mediaStarts++;this.stream=await navigator.mediaDevices.getUserMedia({audio:true});
   this.audio=new AudioContext();this.audioSource=this.audio.createMediaStreamSource(this.stream);this.analyser=this.audio.createAnalyser();
   const gain=this.audio.createGain();gain.gain.value=0;this.audioSource.connect(this.analyser);this.analyser.connect(gain);gain.connect(this.audio.destination);await this.audio.resume();
   this.samples=0;this.sampleTimer=setInterval(()=>{this.analyser.getFloatTimeDomainData(new Float32Array(this.analyser.fftSize));this.samples+=this.analyser.fftSize;},50);
   this.left=new RTCPeerConnection({iceServers:[]});this.right=new RTCPeerConnection({iceServers:[]});
   this.peerKey=crypto.randomUUID();
   const pendingLeft=[],pendingRight=[];
   this.left.onicecandidate=e=>{if(e.candidate){if(this.right.remoteDescription)void this.right.addIceCandidate(e.candidate);else pendingRight.push(e.candidate);}};
   this.right.onicecandidate=e=>{if(e.candidate){if(this.left.remoteDescription)void this.left.addIceCandidate(e.candidate);else pendingLeft.push(e.candidate);}};
   this.right.ontrack=e=>{this.remote=e.streams[0];};for(const track of this.stream.getTracks())this.left.addTrack(track,this.stream);
   await this.left.setLocalDescription(await this.left.createOffer());await this.right.setRemoteDescription(this.left.localDescription);
   for(const c of pendingRight)await this.right.addIceCandidate(c);
   await this.right.setLocalDescription(await this.right.createAnswer());await this.left.setRemoteDescription(this.right.localDescription);
   for(const c of pendingLeft)await this.left.addIceCandidate(c);
   if(this.phase==='closed'){for(const t of this.stream.getTracks())t.stop();this.left.close();this.right.close();return;}
   this.phase='open';this.emit('accept');
  }
  mute(v){this.muted=v;for(const t of this.stream?.getAudioTracks()||[])t.enabled=!v;}isMuted(){return this.muted;}
  reject(){this.disconnect();}disconnect(){if(this.phase==='closed')return;proof.disconnected++;this.phase='closed';clearInterval(this.sampleTimer);this.audioSource?.disconnect();if(this.audio)void this.audio.close().catch(()=>{});for(const t of this.stream?.getTracks()||[])t.stop();this.left?.close();this.right?.close();this.emit('disconnect');}
  getLocalStream(){return this.stream||null;}getRemoteStream(){return this.remote||null;}
 }
 class Device{
  constructor(){this.events={};proof.devices.push(this);}on(n,f){(this.events[n]??=[]).push(f);return this;}emit(n,...args){for(const f of this.events[n]||[])f(...args);}
  async register(){this.emit('registered');}destroy(){this.destroyed=true;this.disconnectAll();}disconnectAll(){for(const c of proof.calls)c.disconnect();}updateToken(){}
 }
 window.Twilio={Device};proof.incoming=id=>{const c=new Call(id);proof.devices.at(-1).emit('incoming',c);};
};
const setup=async()=>{const context=await browser.newContext({permissions:['microphone'],viewport:{width:1280,height:900}});await context.addInitScript(transport);context.on('page',page=>page.on('pageerror',e=>errors.push(e.message)));return context;};
let count=0;const pass=name=>{count++;console.log('PASS '+name);};
async function openPhone(page){await page.getByRole('button',{name:'Phone',exact:true}).click();await page.locator('.vp3-phone-panel').waitFor();}
async function openOwner(page){const pending=page.waitForEvent('popup');await page.getByRole('button',{name:'Open calling window',exact:true}).click();const host=await pending;await host.getByRole('button',{name:'Enable calls in this browser',exact:true}).waitFor();return host;}
async function enable(host){await host.getByRole('button',{name:'Enable calls in this browser',exact:true}).click();await host.getByText('Browser ready for incoming calls',{exact:true}).waitFor();}
async function incoming(host,callId){calls.set(callId,{owner:1,public_id:callId,agent_id:7,conversation_id:101,peer_e164:'+16025550999',direction:'inbound',state:'ringing'});await host.evaluate(id=>phoneMediaFixture.incoming(id),callId);}
async function live(host){await host.waitForFunction(()=>{const c=phoneMediaFixture.calls.at(-1);return c?.left?.signalingState==='stable'&&c.audio?.state==='running'&&c.samples>0&&c.stream?.getTracks().every(t=>t.readyState==='live');});}
try{
 const blocked=await setup(),bp=await blocked.newPage();await bp.addInitScript(()=>window.open=()=>null);await bp.goto(base+'/member.php');await openPhone(bp);await bp.getByRole('button',{name:'Open calling window',exact:true}).click();await bp.getByText(/browser blocked the calling window/).waitFor();assert.equal(tokenCounts.size,0);await blocked.close();pass('blocked pop-up has a recovery message and creates no SDK registration or call');
 const missing=await setup(),mp=await missing.newPage();await mp.goto(base+'/member.php?bridge=missing');await openPhone(mp);await mp.getByText(/calling window could not load/).waitFor();assert.equal(await mp.evaluate(()=>phoneMediaFixture.devices.length),0);assert.equal(tokenCounts.size,0);await missing.close();pass('a missing continuity asset fails closed without creating an inline SDK or microphone');
 const context=await setup(),page=await context.newPage();await page.goto(base+'/member.php');await openPhone(page);assert.equal(await page.evaluate(()=>phoneMediaFixture.devices.length),0);assert.equal(tokenCounts.size,0);const host=await openOwner(page);assert.equal(await host.evaluate(()=>phoneMediaFixture.devices.length),0);await enable(host);assert.equal(tokenCounts.get(1),1);pass('actual PHP runtime opens one calling owner and registers only after its Enable click');
 const first='a'.repeat(32);await incoming(host,first);await page.goto(base+'/admin/settings.php');await page.locator('.vp3-agent-phone-card').getByRole('button',{name:'Answer',exact:true}).click();await live(host);assert.equal(await host.evaluate(()=>phoneMediaFixture.accepted),1);pass('Admin Answer controls the exact incoming call with a real browser microphone and peer connection');
 captionCall=first;
 await host.evaluate(()=>{
  const audio=new AudioContext(),tone=audio.createOscillator(),sink=audio.createMediaStreamDestination();tone.connect(sink);tone.start();void audio.resume();
  phoneMediaFixture.captionAudio=audio;phoneMediaFixture.captionTone=tone;phoneMediaFixture.calls.at(-1).getRemoteStream=()=>sink.stream;
 });
 await host.evaluate(()=>VP3PhoneWorkspaceV1.refresh());
 const caption=host.locator('.vp3-phone-live-caption');await caption.getByRole('button',{name:'Live transcription',exact:true}).click();assert.equal(captionRequests.length,0);
 await caption.getByLabel('Everyone on this call has agreed to live Cloud AI transcription.',{exact:true}).check();await caption.getByRole('button',{name:'Start live captions',exact:true}).click();await caption.getByText('Caption segment 1',{exact:true}).waitFor({timeout:20000});
 const identity=await host.evaluate(()=>({voiceTab:STONEFELLOW_VOICE_LEASE_V122.tabId,devices:phoneMediaFixture.devices.length,call:phoneMediaFixture.calls.at(-1).id,peer:phoneMediaFixture.calls.at(-1).peerKey}));
 const audioBefore=await host.evaluate(()=>phoneMediaFixture.calls.at(-1).samples);
 await page.goto(base+'/member.php');await openPhone(page);await page.locator('.vp3-phone-remote-call').getByRole('button',{name:'Mute',exact:true}).click();await host.waitForFunction(()=>phoneMediaFixture.calls.at(-1).isMuted());await page.locator('.vp3-phone-remote-call').getByRole('button',{name:'Unmute',exact:true}).click();await host.waitForFunction(()=>!phoneMediaFixture.calls.at(-1).isMuted());
 await page.reload();await page.goto(base+'/admin/settings.php');await page.waitForFunction(()=>VP3PhoneWorkspaceV1.attention()?.phase==='active');await live(host);
 assert.deepEqual(await host.evaluate(()=>({voiceTab:STONEFELLOW_VOICE_LEASE_V122.tabId,devices:phoneMediaFixture.devices.length,call:phoneMediaFixture.calls.at(-1).id,peer:phoneMediaFixture.calls.at(-1).peerKey})),identity);assert.equal(tokenCounts.get(1),1);assert.equal(requests.filter(r=>r.action==='bind_call'&&r.call_id===first).length,1);
 assert.ok(await host.evaluate(n=>phoneMediaFixture.calls.at(-1).samples>n,audioBefore));pass('full member/Admin navigation and reload preserve one registration, one bind, one PeerConnection, one microphone lease and audio processing');
 await page.bringToFront();await caption.getByText('Caption segment 2',{exact:true}).waitFor({timeout:20000});
 assert.equal(captionRequests.filter(row=>row.action==='start').length,1);assert.equal(captionRequests.filter(row=>row.action==='stop').length,0);
 await caption.getByRole('button',{name:'Stop live captions',exact:true}).click();await caption.getByText('Live captions stopped.',{exact:true}).waitFor();await live(host);
 await host.evaluate(()=>{phoneMediaFixture.captionTone.stop();void phoneMediaFixture.captionAudio.close();});captionCall='';pass('explicitly consented native caption capture continues across navigation and can stop without ending the human call');
 await page.goto(base+'/no-phone.php');await page.evaluate(()=>VP3PhoneWorkspaceV1.contextChanged({agent_id:9,conversation_id:101,ready:true}));await openPhone(page);await page.getByText(/This page has no calling context/).waitFor();assert.equal(await page.locator('.vp3-phone-remote-call').isVisible(),true);await live(host);pass('a page without an assigned calling context keeps the personal call connected');
 const duplicate=await context.newPage();await duplicate.goto(base+'/phone-call-window-v1.php?owner=1&agent_id=7&conversation_id=101');await duplicate.getByText(/Phone is already open in another window/).waitFor();assert.equal(await duplicate.evaluate(()=>phoneMediaFixture.devices.length),0);assert.equal(tokenCounts.get(1),1);await duplicate.close();await live(host);pass('an independently opened duplicate window cannot register or take the microphone');
 await page.goto(base+'/member.php');await openPhone(page);await host.evaluate(()=>phoneMediaFixture.dropReplies=true);await page.locator('.vp3-phone-remote-call').getByRole('button',{name:'Hang up',exact:true}).click();await host.waitForFunction(()=>phoneMediaFixture.calls.at(-1).phase==='closed');await page.getByText(/control could not be confirmed/).waitFor();assert.equal(await host.evaluate(()=>phoneMediaFixture.disconnected),1);assert.equal(requests.filter(r=>r.action==='prepare_call').length,0);pass('a lost control acknowledgement is reported without retrying a mutation or redialing');
 await page.getByLabel('Phone number to call',{exact:true}).fill('+16025550128');await page.getByRole('button',{name:'Review in Phone window',exact:true}).click();
 await host.getByLabel('Phone number to call',{exact:true}).waitFor();await host.waitForFunction(()=>document.querySelector('input[aria-label="Phone number to call"]').value==='+16025550128');
 assert.equal(requests.filter(r=>r.action==='prepare_call').length,0);assert.equal(await host.evaluate(()=>phoneMediaFixture.calls.length),1);
 for(const width of [390,320]){await host.setViewportSize({width,height:850});const r=await host.locator('.vp3-phone-panel').boundingBox();assert.ok(r&&r.x>=-1&&r.x+r.width<=width+1&&r.y+r.height<=851);}
 pass('off-page dial review only prefills the native window without preparing a carrier call and fits narrow phone widths');
 const oldPackets=await host.evaluate(()=>phoneMediaFixture.packets.filter(p=>p.data.type==='state').slice(-1));
 const oldCommands=await page.evaluate(()=>phoneMediaFixture.packets.filter(p=>p.data.type==='command'&&p.data.action==='hangup').slice(-1));
 await host.close();await page.waitForFunction(()=>!VP3PhoneContinuityV1.snapshot().registered);const host2=await openOwner(page);await enable(host2);const second='b'.repeat(32);await incoming(host2,second);await page.locator('.vp3-phone-panel').getByRole('button',{name:'Answer',exact:true}).click();await live(host2);
 await page.evaluate(packets=>{for(const p of packets){const b=new BroadcastChannel(p.name);b.postMessage(p.data);b.close();}},[...oldPackets,...oldCommands]);
 await page.waitForFunction(()=>VP3PhoneWorkspaceV1.attention()?.call_id==='b'.repeat(32));assert.equal(await host2.evaluate(()=>phoneMediaFixture.calls.at(-1).phase),'open');pass('reopening uses a new owner epoch and ignores stale state and commands from the old call');
 await page.goto(base+'/member.php?owner=2');await openPhone(page);assert.equal(await page.locator('.vp3-phone-remote-call').count(),0);assert.equal(tokenCounts.get(2)||0,0);await host2.evaluate(()=>VP3PhoneWorkspaceV1.refresh());await host2.waitForFunction(()=>phoneMediaFixture.devices.every(d=>d.destroyed)&&phoneMediaFixture.calls.every(c=>c.phase==='closed'));pass('account switching exposes no prior caller and revokes the older owner’s SDK and microphone');
 assert.deepEqual(errors,[]);await context.close();
 console.log('PHONE_CALL_CONTINUITY_BROWSER_V1=PASS ('+count+' cases; real browser media and PeerConnection lifetime, isolated provider transport)');
}catch(error){
 for(const c of browser.contexts())for(const p of c.pages())try{console.error('Continuity diagnostics',JSON.stringify({url:p.url(),state:await p.evaluate(()=>({snapshot:window.VP3PhoneContinuityV1?.snapshot(),native:window.VP3PhoneWorkspaceV1?.continuitySnapshot?.(),media:window.phoneMediaFixture&&{accepted:phoneMediaFixture.accepted,starts:phoneMediaFixture.mediaStarts,calls:phoneMediaFixture.calls.map(c=>({phase:c.phase,left:c.left?.connectionState,right:c.right?.connectionState,tracks:c.stream?.getTracks().map(t=>t.readyState)})),packets:phoneMediaFixture.packets.slice(-4)},notice:document.querySelector('.vp3-phone-notice')?.textContent})),errors,requests:requests.slice(-5)}));}catch{}
 throw error;
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
