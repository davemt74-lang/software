import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import { mkdirSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
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
  window.recordingStarts=0;
  window.recordedBlobs=new Map();
  const createObjectURL=URL.createObjectURL.bind(URL);
  URL.createObjectURL=blob=>{
    const source=createObjectURL(blob);
    if (blob instanceof Blob && blob.type.startsWith('video/')) window.recordedBlobs.set(source,blob);
    return source;
  };
  const revokeObjectURL=URL.revokeObjectURL.bind(URL);
  URL.revokeObjectURL=source=>{
    window.recordedBlobs.delete(source);
    revokeObjectURL(source);
  };
  if (window.MediaRecorder) {
    const Recorder=window.MediaRecorder;
    window.MediaRecorder=class extends Recorder {
      constructor(...args) {
        super(...args);
        this.addEventListener('start',()=>window.recordingStarts++);
      }
    };
  }
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

async function pair(baseURL, ownerClient, owner, name, allowLocation=false) {
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
    generation:claim.device.generation,role:'owner',allow_location:allowLocation},owner),'approve phone');
  assert.equal(approved.device.allow_location,allowLocation);
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
  await decodedVideo(editor.locator('#phoneCameraPreviewVideo'));
}

async function decodedVideo(player) {
  const evidence=await player.evaluate(video=>new Promise((resolve,reject)=>{
    const timeout=setTimeout(()=>reject(Error('remote video never presented a second moving frame')),5000);
    video.requestVideoFrameCallback((_,first)=>video.requestVideoFrameCallback((_,second)=>{
      clearTimeout(timeout);
      resolve({width:video.videoWidth,time:second.mediaTime-first.mediaTime,frames:second.presentedFrames-first.presentedFrames,
        tracks:video.srcObject?.getTracks().map(track=>[track.kind,track.readyState])});
    }));
  }));
  assert.ok(evidence.width>0 && evidence.time>0 && evidence.frames>0,'private preview presents successive decoded frames');
  assert.deepEqual(evidence.tracks,[['video','live']]);
  assert.equal(await player.evaluate(video=>
    [...document.querySelectorAll('video,audio')].filter(el=>el.srcObject===video.srcObject).length),1,
    'the private stream is attached only to the owner preview');
}

async function shareMapPosition(phone, point) {
  const identity={device_id:phone.device.id,generation:phone.device.generation};
  const grant=success(await post(phone.context,holderEndpoint,{action:'start',...identity}),'holder grants GPS').device;
  success(await post(phone.context,holderEndpoint,{action:'fix',...identity,generation:grant.generation,
    ...point,accuracy:8,fix_at:Math.floor(Date.now()/1000)}),'send simulated phone position');
}

async function openMapPreview(map, trigger, keyboard=false) {
  if (keyboard) await trigger.press('Enter');
  else await trigger.click();
  await map.locator('#phone-stream-dialog[open]').waitFor();
  await map.locator('#phone-stream-preview[data-state="live"]').waitFor({timeout:30000});
  await decodedVideo(map.locator('#phone-stream-video'));
}

async function mapPreviewCleared(map) {
  await eventually('a stopped or revoked map stream clears its video',()=>map.locator('#phone-stream-video')
    .evaluate(video=>!video.srcObject));
}

async function screenshot(page, name) {
  const capture=process.env.VP3_PHONE_SCREENSHOTS;
  if (!capture) return;
  mkdirSync(capture,{recursive:true});
  await page.screenshot({path:path.join(capture,name+'.png'),animations:'disabled'});
}

async function openChatStream(chat, announcement) {
  const originalURL=chat.url();
  const pages=chat.context().pages().length;
  await announcement.getByRole('button',{name:'Show live stream',exact:true}).click();
  const live=chat.locator('#chatThread .phone-chat-live-preview[data-state="live"]');
  await live.waitFor({timeout:30000});
  assert.equal(chat.url(),originalURL,'opening a stream keeps the owner in the same conversation');
  assert.equal(chat.context().pages().length,pages,'the stream stays in the same tab');
  assert.equal(await live.evaluate(panel=>Boolean(panel.closest('dialog,[role="dialog"]'))),false,
    'the player renders in the Chat canvas');
  await decodedVideo(live.getByLabel('Private phone camera',{exact:true}));
}

async function reviewedTake(phone) {
  const player=phone.locator('#phone-record-review');
  await player.waitFor();
  await player.evaluate(async video=>{
    video.muted=true;
    await video.play();
  });
  await eventually('a reviewed take contains playable video frames',()=>player.evaluate(video=>
    video.videoWidth>0 && video.currentTime>0));
  return player.evaluate(async video=>{
    video.pause();
    const source=video.currentSrc;
    if (!source.startsWith('blob:')) throw Error('unsubmitted recording must be local to the holder');
    const reviewed=window.recordedBlobs.get(source);
    if (!reviewed) throw Error('review must play the actual recorded video blob');
    const blob=await reviewed.arrayBuffer();
    const hash=await crypto.subtle.digest('SHA-256',blob);
    return {bytes:blob.byteLength,digest:Array.from(new Uint8Array(hash),byte=>byte.toString(16).padStart(2,'0')).join('')};
  });
}

async function verifyRecording(chat, selected, second, foreign) {
  const panel=chat.locator('#chatThread [data-phone-recording-owner]');
  const phone=selected.phone;
  await phone.setViewportSize({width:375,height:620});
  const uploads=[];
  phone.on('request',request=>{
    if (new URL(request.url()).pathname===holderEndpoint && request.method()==='POST' &&
        request.headers()['content-type']?.startsWith('multipart/form-data')) uploads.push(request);
  });
  await panel.getByRole('button',{name:'Request recording',exact:true}).click();
  const start=phone.locator('#phone-record-start');
  await start.waitFor({timeout:20000});
  assert.equal(await phone.evaluate(()=>window.recordingStarts),0,'the owner request waits for the holder to start recording');
  assert.equal(await second.phone.locator('#phone-record-start').isVisible(),false,'the other camera has no recording request');
  const firstStop=phone.locator('#phone-record-stop');
  await start.click();
  await eventually('first real MediaRecorder is running',()=>phone.evaluate(()=>window.recordingStarts===1));
  await delay(850);
  await firstStop.click();
  const first=await reviewedTake(phone);
  assert.ok(first.bytes>0);
  assert.equal(await phone.locator('#phone-camera-mode').isVisible(),false,'the compact camera preview shows one sharing status during review');
  assert.equal(await phone.locator('#phone-camera-status').isVisible(),true);
  assert.equal(await phone.locator('#phone-record-review').evaluate(video=>video.controls && video.playsInline),true);
  for (const id of ['phone-record-review','phone-record-save','phone-record-submit','phone-record-redo']) {
    const control=phone.locator('#'+id);
    await control.scrollIntoViewIfNeeded();
    assert.ok(await control.evaluate(el=>{
      const rect=el.getBoundingClientRect(), dialog=el.closest('dialog[open]')?.getBoundingClientRect();
      const target=document.elementFromPoint(rect.x+rect.width/2,rect.y+rect.height/2);
      return dialog && rect.left>=Math.max(0,dialog.left)-1 && rect.right<=Math.min(innerWidth,dialog.right)+1 &&
        rect.height>=44 && el.contains(target);
    }),id+' is reachable inside the camera dialog on a short portrait phone');
  }
  await screenshot(phone,'phone-recording-review');
  const [download]=await Promise.all([phone.waitForEvent('download'),phone.locator('#phone-record-save').click()]);
  const savedPath=await download.path();
  assert.ok(savedPath,'the holder can download the reviewed take');
  assert.equal(createHash('sha256').update(readFileSync(savedPath)).digest('hex'),first.digest);
  await phone.locator('#phone-record-redo').click();
  await eventually('holder starts a second real MediaRecorder',()=>phone.evaluate(()=>window.recordingStarts===2));
  await delay(1450);
  await firstStop.click();
  const final=await reviewedTake(phone);
  assert.notEqual(final.digest,first.digest,'the replacement is a distinct second recording');
  assert.equal(uploads.length,0,'review, download and re-record keep both takes local until submission');
  const clip=panel.locator('[data-phone-recording-clip]');
  assert.equal(await clip.isVisible(),false,'a reviewed clip is not yet available to its recipient');
  await phone.locator('#phone-record-submit').click();
  await clip.waitFor({timeout:30000});
  assert.ok(uploads.length>0,'the holder explicitly submitted the final video');
  const clipURL=await clip.getAttribute('href');
  assert.match(new URL(clipURL,chat.url()).pathname,/\/media-library-file-v86\.php$/);
  const delivered=await chat.context().request.get(clipURL);
  assert.equal(delivered.status(),200);
  const deliveredBytes=await delivered.body();
  assert.equal(deliveredBytes.length,final.bytes);
  assert.equal(createHash('sha256').update(deliveredBytes).digest('hex'),final.digest,
    'the owner receives exactly the reviewed replacement recording');
  assert.equal((await foreign.request.get(clipURL)).status(),404,'another owner cannot fetch the submitted clip');
  assert.equal((await second.context.request.get(clipURL)).status(),403,'the idle handset cannot fetch the clip');
  assert.equal(await second.phone.evaluate(()=>window.recordingStarts),0);
  return clipURL;
}

async function verifyMapMarker(origin, desktop, owner, idle) {
  const paired=await pair(origin,desktop,owner,'Map camera',true);
  await shareMapPosition(paired,{latitude:33.45,longitude:-112.08});
  // Distinct Phoenix and Tucson fixtures stay selectable at the local map's max fit zoom (7).
  await shareMapPosition(idle,{latitude:32.22,longitude:-110.97});
  const mapContext=await privateContext(origin,owner);
  const map=await mapContext.newPage();
  await map.goto('/phone-tracking.php');
  const pin=map.locator('.phone-map-marker[title^="Map camera"]');
  const idlePin=map.locator('.phone-map-marker[title^="Crew Android"]');
  const livePin=map.locator('.phone-map-marker.is-streaming[title^="Map camera"]');
  await pin.waitFor();
  await idlePin.waitFor();
  await map.getByRole('button',{name:'Fit phones',exact:true}).click();
  await idlePin.click();
  assert.equal(await map.locator('#phone-detail').getAttribute('data-device-id'),idle.device.id);
  assert.equal(await map.locator('#phone-stream-dialog').isVisible(),false,'an ordinary GPS pin still selects its phone');
  assert.equal(await map.locator('.phone-map-marker.is-streaming').count(),0);
  await paired.phone.getByRole('tab',{name:'Chat',exact:true}).click();
  await paired.phone.locator('#phone-apps-toggle').click();
  await paired.phone.locator('#phone-app-camera').click();
  await paired.phone.locator('#phone-camera-local').click();
  await paired.phone.waitForFunction(()=>document.querySelector('#phone-camera-preview')?.videoWidth>0);
  assert.equal(await map.locator('.phone-map-marker.is-streaming').count(),0,'local preview is private to the handset');
  const prepared=paired.phone.waitForResponse(r=>new URL(r.url()).pathname===holderEndpoint &&
    r.request().postDataJSON()?.action==='camera_prepare');
  await paired.phone.locator('#phone-camera-start').click();
  const {camera}=await (await prepared).json();
  assert.ok(camera?.session_id);
  await livePin.waitFor({timeout:30000});
  assert.equal(await livePin.getAttribute('aria-haspopup'),'dialog');
  assert.equal(await map.locator('.phone-map-marker.is-streaming').count(),1);
  const ring=await livePin.evaluate(marker=>{
    const style=getComputedStyle(marker,'::before');
    return {color:style.borderTopColor,animation:style.animationName};
  });
  const [red,green,blue]=ring.color.match(/\d+/g).map(Number);
  assert.ok(red>green*2 && red>blue*2 && ring.animation!=='none','the live marker has an animated red ring');
  await map.emulateMedia({reducedMotion:'reduce'});
  assert.equal(await livePin.evaluate(marker=>getComputedStyle(marker,'::before').animationName),'none');
  await openMapPreview(map,livePin);
  const streamIdentity=await map.locator('#phone-stream-dialog').innerText();
  assert.match(streamIdentity,/Map camera/i);
  const editor=await desktop.newPage();
  const editorURL=new URL('/video-editor.php',origin);
  editorURL.searchParams.set('agent',camera.agent_id || 'system');
  editorURL.searchParams.set('conversation_id',camera.conversation_id);
  editorURL.searchParams.set('camera_session',camera.session_id);
  await editor.goto(editorURL.href);
  await movingPreview(editor);
  await decodedVideo(map.locator('#phone-stream-video'));
  await map.locator('#phone-stream-close').click();
  await map.locator('#phone-stream-dialog').waitFor({state:'hidden'});
  await mapPreviewCleared(map);
  await movingPreview(editor);
  await openMapPreview(map,livePin,true);
  assert.equal(await paired.phone.evaluate(()=>window.cameraReads.length),1,'opening and reopening the map uses the existing sensor track');
  assert.equal(await idle.phone.evaluate(()=>window.cameraReads.length),0);
  const status=success(await post(paired.context,holderEndpoint,{action:'status'}),'map camera before revocation').device;
  success(await post(desktop,trackingEndpoint,{action:'revoke',request_id:randomUUID(),
    device_id:status.id,generation:status.generation},owner),'revoke the phone while its popup is playing');
  await pin.waitFor({state:'hidden',timeout:20000});
  await mapPreviewCleared(map);
  await ended(editor,paired.phone);
  assert.equal(await idlePin.isVisible(),true);
  await mapContext.close();
  await editor.close();
  await paired.context.close();
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
  const second=await pair(origin,desktop,owner,'Crew Android',true);
  const requestKey=randomUUID();
  const message='Use my iPhone as a camera';
  const reply=await sendChat(desktop,owner,message,8,requestKey);
  const link=previewLink(reply,origin);
  assert.ok(!reply.actions.some(action=>action.type==='phone_live_stream'),'a camera request has no live-stream action before the holder consents');
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
  const chatEndpoint=await ownerChat.evaluate(()=>new URL(window.STONEFELLOW_CHAT.endpoint,location.href).href);
  async function typeCameraRequest(text) {
    await ownerChat.locator('#chatInput').fill(text);
    const [response]=await Promise.all([
      ownerChat.waitForResponse(response=>{
        const request=response.request();
        if (response.url()!==chatEndpoint || request.method()!=='POST') return false;
        const payload=request.postDataJSON();
        return payload?.action==='send' && payload.message===text;
      }),
      ownerChat.locator('#sendChatButton').click()
    ]);
    return success({status:response.status(),body:await response.json()},'camera request in the Chat composer');
  }
  const choice=await typeCameraRequest('Request a camera');
  assert.match(choice.answer,/Studio iPhone/i);
  assert.match(choice.answer,/Crew Android/i);
  await ownerChat.locator('#chatThread .message.assistant').filter({hasText:'Crew Android'}).last().waitFor();
  assert.equal(success(await post(second.context,holderEndpoint,{action:'camera_status'}),'pending phone choice').camera,null);
  const nextLink=previewLink(await typeCameraRequest('Studio iPhone'),origin);
  await ownerChat.locator('#chatThread a').filter({hasText:'Open private camera preview'})
    .and(ownerChat.locator('[href*="'+nextLink.searchParams.get('camera_session')+'"]')).waitFor();
  assert.equal(await ownerChat.getByRole('button',{name:'Show live stream',exact:true}).count(),0,
    'neither the pending camera request nor the earlier ended session offers a live stream');
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
  await selected.phone.getByRole('tab',{name:'Chat',exact:true}).click();
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
  const mapContext=await privateContext(origin,owner);
  const map=await mapContext.newPage();
  await map.goto('/phone-tracking.php');
  await map.locator('#phone-devices [data-focus="'+selected.device.id+'"]').click();
  assert.equal(await map.locator('.phone-map-marker').count(),0,'sharing a camera cannot reveal an unshared location');
  await openMapPreview(map,map.locator('#phone-detail [data-action="open_stream"]'));
  const startActivity=success(await post(desktop,chatEndpoint,{action:'activity',phone_updates:true,after_id:1,
    conversation_id:camera.conversation_id,agent_context:{user_agent_id:camera.agent_id}},owner),'holder start announcement');
  const receipt=startActivity.phone_updates.find(update=>update.conversation_id===camera.conversation_id &&
    /Studio iPhone started sharing/i.test(update.speech));
  assert.deepEqual(receipt?.actions,[{type:'phone_live_stream',label:'Show live stream',session_id:camera.session_id,
    device_id:selected.device.id,agent_id:camera.agent_id,conversation_id:camera.conversation_id}],
    'the owner receives the named start announcement with this phone and conversation');
  const chatContext=await privateContext(origin,owner);
  const chat=await chatContext.newPage();
  await chat.goto('/chat.php?agent='+(camera.agent_id || 'system')+'&conversation_id='+camera.conversation_id);
  const announcement=chat.locator('#chatThread .message.assistant').filter({hasText:/Studio iPhone started sharing/i});
  await openChatStream(chat,announcement);
  await screenshot(chat,'owner-chat-live-stream');
  await movingPreview(editor);
  await decodedVideo(map.locator('#phone-stream-video'));
  await chat.getByRole('button',{name:'Hide live stream',exact:true}).click();
  const chatVideoCleared=()=>chat.locator('#chatThread .phone-chat-live-preview video').evaluateAll(videos=>videos.every(video=>!video.srcObject));
  await eventually('hiding the inline stream releases its receiver',chatVideoCleared);
  await movingPreview(editor);
  await decodedVideo(map.locator('#phone-stream-video'));
  await openChatStream(chat,announcement);
  assert.equal(await selected.phone.evaluate(()=>window.cameraReads.length),1,'all three private viewers share the holder’s one authorized camera capture');
  const submittedClip=await verifyRecording(chat,selected,second,foreign);
  await selected.phone.locator('#phone-camera-stop').click();
  await ended(editor,selected.phone);
  await mapPreviewCleared(map);
  await eventually('holder stop clears the inline Chat video',chatVideoCleared);
  await chat.reload();
  const receiptLink=chat.locator('#chatThread').getByRole('link',{name:'Open submitted recording',exact:true});
  await receiptLink.waitFor({timeout:20000});
  assert.equal(await receiptLink.evaluate(link=>link.href),new URL(submittedClip,chat.url()).href,
    'the owner can reopen the submitted take from the private Chat after live sharing ends');
  await chatContext.close();
  await mapContext.close();
  assert.equal(await second.phone.evaluate(()=>window.cameraReads.length),0);
  console.log('PASS private camera: two existing pairings, scoped Chat request, real decoded WebRTC video, stalled media on a connected peer, named stop announcement, fresh consent on reconnect and editor stop.');
  console.log('PASS owner Chat camera: the holder’s start announcement opens decoded video inside the conversation, hiding and reopening preserves map and editor streams, and holder stop clears all three viewers.');
  console.log('PASS per-camera recording: holder approval, local playback and download, replacement take, explicit submission, and byte-identical delivery only to the owner.');
  await verifyMapMarker(origin,desktop,owner,second);
  console.log('PASS phone map camera: only the streaming marker opens a private moving preview, concurrent editor video survives closing and reopening the popup, and revocation clears both viewers.');
} catch (error) {
  if (serverErrors) console.error(serverErrors.slice(-2500));
  throw error;
} finally {
  if (browser) await browser.close();
  if (server && server.exitCode===null) { const closed=new Promise(resolve=>server.once('close',resolve)); server.kill(); await closed; }
  rmSync(temp,{recursive:true,force:true});
}
