import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdtempSync, rmSync } from 'node:fs';
import { createRequire } from 'node:module';
import { localPort, preparePhoneSite } from './fixtures/phone-site-v1.mjs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';

const repo = fileURLToPath(new URL('..', import.meta.url));
const { chromium } = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const php = process.env.VP3_PHP_BINARY || 'php';
const temp = mkdtempSync(path.join(tmpdir(), 'vp3-camera-e2e-'));
const site = path.join(temp, 'web');
const sessions = path.join(temp, 'sessions');
let browser, server;
let serverErrors = '';

function seedSite() {
  const users = preparePhoneSite(repo, site, sessions, php);
  // The existing Editor renders the owner's project and media lists even when both are empty.
  execFileSync(php, ['-r', `
    $pdo=new PDO('sqlite:'.$argv[1]);
    $pdo->exec('CREATE TABLE video_editor_projects (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE user_media_assets (id INTEGER PRIMARY KEY, user_id INTEGER, media_type TEXT, title TEXT,
      mime_type TEXT, file_path TEXT, file_size INTEGER, duration_seconds REAL, source TEXT, metadata_json TEXT, created_at TEXT, updated_at TEXT)');
  `, path.join(temp,'data.sqlite')]);
  return users;
}

async function eventually(label, check, timeout=20000) {
  const end = Date.now()+timeout;
  do { if (await check()) return; await delay(180); } while (Date.now()<end);
  throw Error(label);
}

async function startSite() {
  const address = '127.0.0.1:'+await localPort();
  server = spawn(php,['-d','session.save_path='+sessions,'-d','display_errors=0','-S',address,'-t',site],{stdio:['ignore','ignore','pipe']});
  server.stderr.on('data', b=>{ for (const s of b.toString().split('\n')) if (/Fatal error|Parse error|Uncaught/.test(s)) serverErrors+=s+'\n'; });
  const origin='http://'+address;
  await eventually('PHP fixture failed to start',async()=>fetch(origin+'/health.txt').then(r=>r.ok).catch(()=>false));
  return origin;
}

async function post(context, url, data, user) {
  const headers = user ? {'X-VP3-Working-Attention':user.binding} : {'X-VP3-Phone':'1'};
  const response = await context.request.post(url,{headers,data:user?{...data,csrf_token:user.csrf}:data});
  assert.match(response.headers()['content-type']||'',/application\/json/,url+' must return JSON ('+response.status()+')');
  return {status:response.status(),body:await response.json()};
}

function success(result, label) {
  assert.equal(result.body.ok,true,label+' returned '+result.status+' '+(result.body.error||''));
  return result.body;
}

const trackingEndpoint='/api/phone-tracking-v1.php';
const holderEndpoint='/api/phone-location-v1.php';
const editorEndpoint='/api/phone-camera-v1.php';
const cameraProbe = () => {
  const Peer = window.RTCPeerConnection;
  window.cameraPeers = [];
  window.RTCPeerConnection = class extends Peer {
    constructor(configuration) {
      super(configuration);
      window.cameraPeers.push(this);
    }
  };
  const originals = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
  window.cameraReads=[];
  navigator.mediaDevices.getUserMedia = async options=>{
    const read={options,tracks:[]};
    window.cameraReads.push(read);
    const stream=await originals(options);
    read.tracks=stream.getTracks();
    return stream;
  };
};

async function privateContext(baseURL, user) {
  const context = await browser.newContext({baseURL});
  await context.addCookies([{name:'PHPSESSID',value:user.session,url:baseURL,httpOnly:true,sameSite:'Lax'}]);
  return context;
}

async function pair(baseURL, ownerClient, owner, name) {
  const context = await browser.newContext({baseURL,viewport:{width:390,height:844},isMobile:true});
  await context.addInitScript(cameraProbe);
  await context.grantPermissions(['camera'],{origin:baseURL});
  const phone=await context.newPage();
  const invite=success(await post(ownerClient,trackingEndpoint,{action:'invite',request_id:randomUUID()},owner),'invite');
  const claimed=phone.waitForResponse(r=>new URL(r.url()).pathname===holderEndpoint && r.request().postDataJSON()?.action==='claim');
  await phone.goto(invite.phone_url);
  await phone.locator('#phone-name').fill(name);
  await phone.locator('#phone-claim').click();
  const claim=await (await claimed).json();
  assert.ok(claim.ok && claim.device?.id,'existing QR flow pairs the phone');
  const approved=success(await post(ownerClient,trackingEndpoint,{action:'approve',request_id:randomUUID(),device_id:claim.device.id,
    generation:claim.device.generation,role:'owner',allow_location:false},owner),'approve phone without location permission');
  assert.equal(approved.device.allow_location,false);
  return { context, phone, device:approved.device };
}

async function sendChat(context, user, message, conversationId=8, key=randomUUID()) {
  const data={action:'send',message,input_mode:'text',agent_context:{user_agent_id:0},conversation_id:conversationId,idempotency_key:key};
  return success(await post(context,'/api/chat.php',data,user),'private Agent Chat');
}

function previewLink(reply, baseURL, conversation = 8) {
  assert.match(reply.answer,/Studio iPhone/i);
  const action=reply.actions?.find(a=>a.type==='open_url' && a.label==='Open private camera preview');
  assert.ok(action,'Chat reply exposes the native preview link');
  const url=new URL(action.url,baseURL);
  assert.equal(url.origin,baseURL);
  assert.equal(url.pathname,'/video-editor.php');
  assert.equal(url.searchParams.get('conversation_id'),String(conversation));
  assert.match(url.searchParams.get('camera_session'),/^[a-f0-9]{32}$/);
  return url;
}

async function movingPreview(editor) {
  await editor.locator('#phoneCameraPreview[data-state="live"]').waitFor({timeout:28000});
  const evidence=await editor.locator('#phoneCameraPreviewVideo').evaluate(video=>new Promise((resolve,reject)=>{
    const timeout=setTimeout(()=>reject(Error('remote video never presented a second moving frame')),5000);
    video.requestVideoFrameCallback((_,first)=>video.requestVideoFrameCallback((_,second)=>{
      clearTimeout(timeout);
      resolve({width:video.videoWidth,time:second.mediaTime-first.mediaTime,frames:second.presentedFrames-first.presentedFrames,
        tracks:video.srcObject?.getTracks().map(track=>[track.kind,track.readyState])});
    }));
  }));
  assert.ok(evidence.width>0 && evidence.time>0 && evidence.frames>0,'private preview presents successive decoded frames');
  assert.deepEqual(evidence.tracks,[['video','live']]);
  assert.equal(await editor.locator('#phoneCameraPreviewVideo').evaluate(video=>
    [...document.querySelectorAll('video,audio')].filter(el=>el.srcObject===video.srcObject).length),1,
    'the private stream is attached only to the owner preview');
}

async function ended(editor, phone) {
  await eventually('the editor clears its private preview',()=>editor.evaluate(()=>{
    const root=document.querySelector('#phoneCameraPreview');
    return ['stopped','unavailable'].includes(root?.dataset.state) && !document.querySelector('#phoneCameraPreviewVideo')?.srcObject;
  }));
  await eventually('the holder releases every acquired physical camera track',()=>phone.evaluate(()=>
    window.cameraReads.length>0 && window.cameraReads.every(r=>r.tracks.length>0 && r.tracks.every(t=>t.readyState==='ended'))));
}

try {
  const [owner, outsider]=seedSite();
  const origin=await startSite();
  browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH,
    args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream']});
  const desktop=await privateContext(origin,owner);
  const foreign=await privateContext(origin,outsider);
  const selected=await pair(origin,desktop,owner,'Studio iPhone');
  const second=await pair(origin,desktop,owner,'Crew Android');
  const requestKey=randomUUID();
  const message='Use my iPhone as a camera';
  const reply=await sendChat(desktop,owner,message,8,requestKey);
  const link=previewLink(reply,origin);
  const retry=await sendChat(desktop,owner,message,8,requestKey);
  assert.equal(reply.assistant_message_id,retry.assistant_message_id);
  assert.equal(previewLink(retry,origin).href,link.href);
  const id=link.searchParams.get('camera_session');
  const read=(context,user,conversation_id)=>post(context,editorEndpoint,{action:'camera_status',agent_id:0,conversation_id,session_id:id},user);
  for(const response of [await read(foreign,outsider,10),await read(desktop,owner,9)]) {
    assert.ok(response.status===403 || response.status===404 || (response.body.ok && !response.body.camera),'camera is private to the original owner and conversation');
    assert.ok(!response.body.camera?.offer_sdp && !response.body.camera?.answer_sdp);
  }
  assert.equal(success(await post(second.context,holderEndpoint,{action:'camera_status'}),'second phone').camera,null);
  const wrong=await post(second.context,holderEndpoint,{action:'camera_start',session_id:id});
  assert.ok([403,404,409].includes(wrong.status),'a second paired phone cannot respond as the selected camera');
  const editor=await desktop.newPage();
  await editor.goto(link.href);
  await editor.locator('#phoneCameraPreview').waitFor();
  const start=selected.phone.locator('#phone-camera-start');
  await start.waitFor({timeout:20000});
  assert.equal(await selected.phone.evaluate(()=>window.cameraReads.length),0,'owner request and opening the preview must leave the physical camera idle');
  assert.equal(await editor.locator('#phoneCameraPreviewVideo').evaluate(v=>v.srcObject),null);
  await start.click();
  await movingPreview(editor);
  const readConstraints=await selected.phone.evaluate(()=>window.cameraReads.map(r=>({audio:r.options.audio,tracks:r.tracks.map(t=>t.kind)})));
  assert.deepEqual(readConstraints,[{audio:false,tracks:['video']}]);
  // Keep the peer and both signaling clients connected while interrupting only outgoing frames.
  // A transport heartbeat would keep the frozen picture labeled live indefinitely.
  await selected.phone.evaluate(async () => {
    const peer = window.cameraPeers.find(connection => connection.connectionState === 'connected');
    const sender = peer?.getSenders().find(sender => sender.track?.kind === 'video');
    if (!sender) throw Error('No connected video sender');
    await sender.replaceTrack(null);
  });
  await editor.locator('#phoneCameraPreview[data-state="stalled"]').waitFor({timeout:15000});
  const hiddenFrame=await editor.locator('#phoneCameraPreviewVideo').evaluate(video=>
    !video.srcObject || video.hidden || getComputedStyle(video).opacity==='0' || getComputedStyle(video).display==='none');
  assert.ok(hiddenFrame,'a stalled private camera must hide the frozen frame');
  assert.equal(await selected.phone.evaluate(()=>window.cameraPeers.some(peer=>peer.connectionState==='connected')),true,
    'the test interrupts frame delivery independently of connection health');
  assert.equal(await second.phone.locator('#phone-camera-start').isVisible(),false);
  await selected.phone.locator('#phone-camera-stop').click();
  await ended(editor,selected.phone);
  const activity=success(await post(desktop,'/api/chat.php',{action:'activity',phone_updates:true,conversation_id:8,
    agent_context:{user_agent_id:0}},owner),'stop announcement');
  assert.ok(activity.phone_updates.some(update=>/ended for Studio iPhone/i.test(update.speech)),
    'private Chat confirms which camera stopped');
  // Render the installed private Chat, including its account and media menus, for the reconnect.
  const chatSchema=fileURLToPath(new URL('./fixtures/phone-camera-chat-shell.sql',import.meta.url));
  execFileSync(php,['-r',"(new PDO('sqlite:'.$argv[1]))->exec(file_get_contents($argv[2]));",
    path.join(temp,'data.sqlite'),chatSchema]);
  const ownerChat=await desktop.newPage();
  await ownerChat.goto('/chat.php?agent=system&conversation_id=8');
  await ownerChat.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY?.phoneCameraScope?.()?.conversation===8);
  async function typeCameraRequest(text) {
    const sent=ownerChat.waitForResponse(response=>new URL(response.url()).pathname==='/api/chat.php'
      && response.request().method()==='POST' && response.request().postDataJSON()?.action==='send'
      && response.request().postDataJSON()?.message===text);
    await ownerChat.locator('#chatInput').fill(text);
    await ownerChat.locator('#sendChatButton').click();
    return (await sent).json();
  }
  const choice=await typeCameraRequest('Request a camera');
  assert.match(choice.answer,/Studio iPhone/i);
  assert.match(choice.answer,/Crew Android/i);
  await ownerChat.locator('#chatThread .message.assistant').filter({hasText:'Crew Android'}).last().waitFor();
  assert.equal(success(await post(second.context,holderEndpoint,{action:'camera_status'}),'pending phone choice').camera,null);
  const nextLink=previewLink(await typeCameraRequest('Studio iPhone'),origin);
  await ownerChat.locator('#chatThread a').filter({hasText:'Open private camera preview'})
    .and(ownerChat.locator('[href*="'+nextLink.searchParams.get('camera_session')+'"]')).waitFor();
  await ownerChat.close();
  assert.notEqual(nextLink.searchParams.get('camera_session'),id,'restart uses a fresh session on the existing pairing');
  await selected.context.addCookies([{name:'PHPSESSID',value:owner.session,url:origin,httpOnly:true,sameSite:'Lax'}]);
  await selected.phone.goto('/chat.php?agent=system&conversation_id=9');
  await selected.phone.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY?.phoneCameraScope?.()?.conversation===9);
  assert.equal(await start.isVisible(),false,'a different conversation cannot activate the handset camera');
  await selected.phone.goto('/chat.php?agent=system&conversation_id=8');
  await editor.goto(nextLink.href);
  await start.waitFor({timeout:20000});
  assert.equal(await selected.phone.evaluate(()=>window.cameraReads.length),0,'opening the originating private Chat requires fresh consent');
  assert.equal(await start.evaluate(button=>Boolean(button.closest('#chatThread .message.assistant'))),true,
    'the phone holder can act inside the original assistant announcement');
  await start.click();
  await movingPreview(editor);
  let interruptedStop=false;
  await editor.route('**/api/phone-camera-v1.php', async route=>{
    if (!interruptedStop && route.request().postDataJSON()?.action === 'camera_stop') {
      interruptedStop=true;
      return route.abort('internetdisconnected');
    }
    return route.continue();
  });
  await editor.locator('#phoneCameraPreviewStop').click();
  await editor.getByRole('button',{name:'Retry stop'}).waitFor();
  assert.equal(interruptedStop,true);
  assert.equal(await editor.locator('#phoneCameraPreviewVideo').evaluate(v=>v.srcObject),null,
    'the local receiver stops immediately while the stop message is retried');
  await editor.getByRole('button',{name:'Retry stop'}).click();
  await ended(editor,selected.phone);
  assert.equal(await second.phone.evaluate(()=>window.cameraReads.length),0,'the other paired handset never opened its camera');
  const device=success(await post(selected.context,holderEndpoint,{action:'status'}),'pairing after private preview').device;
  assert.equal(device.id,selected.device.id);
  assert.equal(device.allow_location,false);
  assert.equal(device.latitude,null);
  // Enter via the phone's app menu with only the existing pairing cookie.
  await selected.context.clearCookies({name:'PHPSESSID'});
  await selected.phone.goto('/phone-location.php');
  await selected.phone.locator('#phone-apps-toggle').click();
  await selected.phone.locator('#phone-app-camera').click();
  assert.equal(await selected.phone.evaluate(()=>window.cameraReads.length),0,'opening Camera leaves the sensor idle');
  await selected.phone.locator('#phone-camera-local').click();
  await selected.phone.waitForFunction(()=>document.querySelector('#phone-camera-preview')?.videoWidth>0);
  assert.equal(success(await post(selected.context,holderEndpoint,{action:'camera_status'}),'local preview').camera,null);
  const prepared=selected.phone.waitForResponse(r=>new URL(r.url()).pathname===holderEndpoint && r.request().postDataJSON()?.action==='camera_prepare');
  await selected.phone.locator('#phone-camera-start').click();
  const {camera}=await (await prepared).json();
  assert.ok(camera?.conversation_id>0,'the pairing determines the destination');
  await selected.phone.locator('#phone-camera-stop').waitFor();
  await eventually('the phone offers its video to the private editor',async()=>{
    const state=success(await post(desktop,editorEndpoint,{action:'camera_status',agent_id:camera.agent_id,
      conversation_id:camera.conversation_id,session_id:camera.session_id},owner),'private editor status');
    return Boolean(state.camera?.offer_sdp);
  });
  const selfLink=previewLink(await sendChat(desktop,owner,'Phone camera status',camera.conversation_id),origin,camera.conversation_id);
  assert.equal(selfLink.searchParams.get('camera_session'),camera.session_id);
  await editor.goto(selfLink.href);
  await movingPreview(editor);
  await selected.phone.locator('#phone-camera-stop').click();
  await ended(editor,selected.phone);
  assert.equal(await second.phone.evaluate(()=>window.cameraReads.length),0);
  console.log('PASS private camera: two existing pairings, scoped Chat request, real decoded WebRTC video, stalled media on a connected peer, named stop announcement, fresh consent on reconnect and editor stop.');
} catch (error) {
  if (serverErrors) console.error(serverErrors.slice(-2500));
  throw error;
} finally {
  if (browser) await browser.close();
  if (server && server.exitCode===null) { const closed=new Promise(resolve=>server.once('close',resolve)); server.kill(); await closed; }
  rmSync(temp,{recursive:true,force:true});
}
