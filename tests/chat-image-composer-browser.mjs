import assert from 'node:assert/strict';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {spawn} from 'node:child_process';
import {createHash} from 'node:crypto';
import {crc32, deflateSync} from 'node:zlib';

// Production upload, private-file and reference endpoints with the real Chat and
// voice clients. Only conversation interpretation and the speech service are
// fixtures. The queue is canonical; no worker or paid provider is called.
const root = path.resolve(new URL('../', import.meta.url).pathname);
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-image-composer-'));
const php = process.env.VP3_PHP_BINARY || 'php';
let browser, server, logs = '', checks = 0;
const errors = [], calls = [], gates = [];
const assets = [
  'chat.js', 'chat.css', 'chat-tools.js', 'chat-tools.css',
  'chat-image-composer-v1.js', 'chat-image-composer-v1.css',
  'chat-canvas-media-v1.js', 'chat-canvas-media-v1.css', 'chat-image-studio-v1.js', 'chat-image-studio-v1.css',
  'media-library-picker-v1.js', 'media-library-picker-v1.css', 'chat-media-commands-v1.js', 'chat-media-commands-v1.css',
  'chat-voice.js', 'voice-lease-v122.js', 'agent-context-v131.js'
];

function png(width, height, noisy = false) {
  const chunk = (name, bytes) => {
    const type = Buffer.from(name), header = Buffer.alloc(4), checksum = Buffer.alloc(4);
    header.writeUInt32BE(bytes.length);
    checksum.writeUInt32BE(crc32(Buffer.concat([type, bytes])));
    return Buffer.concat([header, type, bytes, checksum]);
  };
  const dimensions = Buffer.alloc(13);
  dimensions.writeUInt32BE(width, 0); dimensions.writeUInt32BE(height, 4);
  dimensions[8] = 8; dimensions[9] = 6;
  const pixels = Buffer.alloc((width * 4 + 1) * height);
  let noise = 314159;
  for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) {
    const offset = y * (width * 4 + 1) + x * 4 + 1;
    noise = (Math.imul(noise, 1664525) + 1013904223) >>> 0;
    pixels.set(noisy ? [noise >>> 24, noise >>> 16 & 255, noise >>> 8 & 255, 255]
      : y > height * .65 ? [72, 115, 107, 255] : [233, 191, 137, 255], offset);
  }
  return Buffer.concat([Buffer.from([137,80,78,71,13,10,26,10]), chunk('IHDR', dimensions),
    chunk('IDAT', deflateSync(pixels)), chunk('IEND', Buffer.alloc(0))]);
}
const imageBytes = png(160, 120), imageHash = createHash('sha256').update(imageBytes).digest('hex');
const imageFile = {name: 'Album cover base.png', mimeType: 'image/png', buffer: imageBytes};
fs.cpSync(path.join(root, 'includes'), path.join(tmp, 'includes'), {recursive: true});
fs.mkdirSync(path.join(tmp, 'api')); fs.mkdirSync(path.join(tmp, 'sessions'));
for (const file of [...assets, 'api/media-library-v86.php', 'api/chat-canvas-media-v1.php', 'media-library-file-v86.php']) {
  fs.copyFileSync(path.join(root, file), path.join(tmp, file));
}
fs.copyFileSync(path.join(root, 'tests/fixtures/image-generation-v1.php'), path.join(tmp, 'includes/image-fixture.php'));
fs.copyFileSync(path.join(root, 'tests/fixtures/workflow-agent-schema-v1.php'), path.join(tmp, 'includes/workflow-agent-schema-v1.php'));
fs.writeFileSync(path.join(tmp, 'includes/bootstrap.php'), `<?php
define('STONEFELLOW_ROOT', dirname(__DIR__));
session_save_path(STONEFELLOW_ROOT.'/sessions'); session_start();
$GLOBALS['imageFixtureFile']=STONEFELLOW_ROOT.'/image.sqlite';
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $aid,string $surface): array {
    return ['route'=>'vp3_cloud','cloud'=>['ready'=>true],'blocked'=>false];
}
require __DIR__.'/image-fixture.php';
require_once __DIR__.'/agent-catch-up-v1.php';
db()->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));
db()->exec('INSERT OR IGNORE INTO chat_conversations VALUES(14,1,7)');
`);

const html = `<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>VP3 image composer</title></head><body>
<div class="chat-app"><aside class="chat-sidebar"><strong>VP3</strong><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
<main class="chat-main"><header class="chat-topbar"><strong>Agent Chat</strong></header>
<section id="chatThread" class="chat-thread"><div id="chatWelcome" hidden></div></section>
<div id="chatComposerShell" class="chat-composer-shell"><form id="chatForm" class="chat-composer">
<textarea id="chatInput" aria-label="Message your Agent" placeholder="Message your Agent…" rows="1"></textarea>
<button type="button" id="chatVoiceButton" class="chat-voice-button" aria-label="Start voice conversation">◉</button>
<button type="submit" id="sendChatButton" aria-label="Send message">↑</button></form>
<div id="chatVoiceStatus" class="chat-voice-status" hidden></div></div></main></div></body></html>`;
fs.writeFileSync(path.join(tmp, 'index.php'), '<?php require __DIR__."/includes/bootstrap.php"; ?>' + html
  + '<script>window.FIXTURE_BINDING=<?=json_encode(vp3_catch_up_page_binding_v1(current_user()))?>;</script>');
fs.writeFileSync(path.join(tmp, 'control.php'), `<?php
require __DIR__.'/includes/bootstrap.php';
header('Content-Type: application/json');
$input=json_decode(file_get_contents('php://input'),true)?:[];
if(isset($input['mode']))file_put_contents(__DIR__.'/mode.txt',$input['mode']);
if(isset($input['delete_asset'])){
    $asset=media_studio_asset((int)$input['delete_asset'],current_user());
    if($asset)@unlink(STONEFELLOW_ROOT.'/'.$asset['file_path']);
}
$seeded=null;$blocker=null;
if(!empty($input['seed_blocker'])){
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(12,1,'user','Earlier image request in another Agent Chat')")->execute();
    $turn=(int)db()->lastInsertId();
    $blocker=vp3_image_queue_v1(db(),current_user(),['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>8],
      image_fixture_plan_v1(),['conversation_id'=>12,'chat_user_message_id'=>$turn]);
    $blocker+=['conversation_id'=>12,'agent_id'=>8,'user_message_id'=>$turn];
}
if(!empty($input['seed_batch'])){
    $plan=image_fixture_plan_v1();$plan['count']=4;$plan['title']='Desert variation';
    $generation=vp3_image_queue_v1(db(),current_user(),['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7],$plan,image_fixture_turn_v1());
    $seeded=vp3_image_tick_v1(db(),current_user(),vp3_image_row_v1(db(),current_user(),$generation['id']),
      static fn($url,$body,$key)=>image_fixture_response_v1($body['size'],$body['n']));
    if($seeded['status']!=='completed')throw new RuntimeException('Generated batch fixture did not complete.');
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(11,1,'assistant','Your four generated images are ready.',?)")
      ->execute([json_encode(['canvas_media'=>['asset_ids'=>[],'generation_ids'=>[$generation['id']]]])]);
}
$jobs=[];
if(table_exists('agent_image_generations_v1'))foreach(db()->query('SELECT * FROM agent_image_generations_v1')->fetchAll() as $row){
    $jobs[]=['id'=>$row['generation_id'],'conversation_id'=>(int)$row['conversation_id'],'config'=>json_decode($row['config_json'],true),'run_id'=>(int)$row['run_id']];
}
echo json_encode(['ok'=>true,'binding'=>vp3_catch_up_page_binding_v1(current_user()),'assets'=>media_studio_assets(current_user(),250),'jobs'=>$jobs,'seeded'=>$seeded,'blocker'=>$blocker]);
`);
fs.writeFileSync(path.join(tmp, 'fixture-chat.php'), `<?php
require __DIR__.'/includes/bootstrap.php';
$input=json_decode(file_get_contents('php://input'),true)?:[];
$action=$input['action']??'';$cid=(int)($input['conversation_id']??11);
$result=['ok'=>true,'conversations'=>[['id'=>11,'title'=>'Image project'],['id'=>14,'title'=>'Other project']],'items'=>[],'updates'=>[]];
try {
    if($action==='task.route')$result=['ok'=>true,'route'=>'chat'];
    if($action==='new'){
        $cid=(int)db()->query('SELECT MAX(id)+1 FROM chat_conversations')->fetchColumn();
        db()->prepare('INSERT INTO chat_conversations VALUES(?,1,7)')->execute([$cid]);
        $result=['ok'=>true,'conversation_id'=>$cid];
    }
    if($action==='load'){
        $query=db()->prepare('SELECT id,role,message,context_json FROM chat_messages WHERE conversation_id=? ORDER BY id');$query->execute([$cid]);
        $result=['ok'=>true,'messages'=>$query->fetchAll()];
    }
    if($action==='send'){
        $mode=@file_get_contents(__DIR__.'/mode.txt')?:'ordinary';
        if($mode==='error')throw new RuntimeException('Fixture delivery is unavailable. Your draft is kept.',503);
        if($cid<1){
            $cid=(int)db()->query('SELECT MAX(id)+1 FROM chat_conversations')->fetchColumn();
            db()->prepare('INSERT INTO chat_conversations VALUES(?,1,7)')->execute([$cid]);
        }
        $message=(string)($input['message']??'');
        db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,1,'user',?)")->execute([$cid,$message]);
        $userMessage=(int)db()->lastInsertId();$context=[];$answer='Fixture ordinary reply.';
        if($mode==='queue'||$mode==='active_request'){
            $plan=image_fixture_plan_v1();$plan['prompt']=$message;
            $plan['count']=preg_match('/^Generate ([1-4]) images?:/i',$message,$match)?(int)$match[1]:1;
            $ctx=['conversation_id'=>$cid,'chat_user_message_id'=>$userMessage];
            if(isset($input['image_reference_asset_id']))$ctx['image_reference_asset_id']=$input['image_reference_asset_id'];
            if($mode==='active_request'){
                $context=vp3_capability_image_generate_v1(db(),current_user(),['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7],$plan,$ctx);
                $answer=$context['answer'];unset($context['answer'],$context['handled']);
            }else{
                $job=vp3_image_queue_v1(db(),current_user(),['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7],$plan,$ctx);
                $context=['canvas_media'=>['asset_ids'=>[],'generation_ids'=>[$job['id']]],
                  'tool_receipt'=>vp3_tool_receipt_v1('images.generate','queue','completed','',$job['job_id'],1)];
                $answer='Your image request is queued.';
            }
        }elseif($mode==='malformed'){
            $context=['canvas_media'=>['generation_ids'=>['not-a-job']],
              'tool_receipt'=>['capability'=>'images.generate','operation'=>'queue','status'=>'completed']];
            $answer='This fixture has no confirmed queue receipt.';
        }elseif($mode==='blocked'){
            $context=['tool_receipt'=>vp3_tool_receipt_v1('images.generate','queue','blocked','provider_unavailable')];
            $answer='Image generation is currently unavailable.';
        }
        db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(?,1,'assistant',?,?)")->execute([$cid,$answer,json_encode($context)]);
        $result=['ok'=>true,'conversation_id'=>$cid,'agent_id'=>7,'user_message_id'=>$userMessage,
          'assistant_message_id'=>(int)db()->lastInsertId(),'answer'=>$answer]+$context;
    }
    if(basename($_SERVER['SCRIPT_NAME'])==='chat-stream-v121.php'){
        header('Content-Type: application/x-ndjson');echo json_encode(['type'=>'done','data'=>$result])."\\n";
    }else{header('Content-Type: application/json');echo json_encode($result);}
}catch(Throwable $error){http_response_code($error->getCode()===503?503:422);header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);}
`);
for (const endpoint of ['chat-v236.php','chat-stream-v121.php']) {
  fs.writeFileSync(path.join(tmp, 'api', endpoint), '<?php require dirname(__DIR__)."/fixture-chat.php";');
}

async function check(label, fn) { await fn(); checks++; console.log('PASS ' + label); }
async function json(response) {
  const raw = await response.text();
  try { return JSON.parse(raw); } catch { throw Error(raw + '\n' + logs.slice(-3000)); }
}
const deferred = () => { let resolve; const promise = new Promise(done => resolve = done); return {promise, resolve}; };
function createGate(index) { const gate={entered:deferred(),release:deferred(),completed:deferred(),index};gates.push(gate);return gate; }
async function reached(gate) {
  let timer;
  try { await Promise.race([gate.entered.promise, new Promise((_,reject)=>timer=setTimeout(()=>reject(Error('Expected request did not reach its test gate.')),12000))]); }
  finally { clearTimeout(timer); }
}

try {
  const socket = net.createServer();
  await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
  const port = socket.address().port; await new Promise(resolve => socket.close(resolve));
  const base = 'http://127.0.0.1:' + port;
  server = spawn(php, ['-S','127.0.0.1:' + port,'-t',tmp], {stdio:['ignore','ignore','pipe']});
  server.stderr.on('data', data => logs += data);
  let ready = false;
  for (let n = 0; n < 100; n++) {
    try { if ((await fetch(base + '/control.php')).ok) { ready = true; break; } } catch {}
    await new Promise(resolve => setTimeout(resolve, 30));
  }
  assert.ok(ready, 'PHP fixture failed to start: ' + logs.slice(-3000));
  const cookie = {Cookie:'PHPSESSID=fixture-preflight'};
  const control = async input => json(await fetch(base + '/control.php', {method:'POST',headers:cookie,body:JSON.stringify(input || {})}));
  const post = async (endpoint, body, headers={}) => {
    const response = await fetch(base + endpoint, {method:'POST',headers:{...cookie,...headers},body});
    return {response,data:await json(response)};
  };
  const media = async (action, data={}) => {
    const body = new FormData();
    for (const [name,value] of Object.entries({action,csrf_token:'fixture',...data})) body.set(name,value);
    return post('/api/media-library-v86.php', body, {'X-VP3-Media-Owner':'1'});
  };
  const reference = async (asset, binding, extra={}) => post('/api/chat-canvas-media-v1.php',
    JSON.stringify({csrf_token:'fixture',agent_id:7,conversation_id:11,action:'reference',asset_id:asset,...extra}),
    {'Content-Type':'application/json','X-VP3-Working-Attention':binding});
  const cancel = async job => {
    const result=await post('/api/chat-canvas-media-v1.php',JSON.stringify({action:'cancel',csrf_token:'fixture',agent_id:job.agent_id??7,
      conversation_id:job.conversation_id,generation_id:job.id,confirmed:true}),{'Content-Type':'application/json'});
    assert.equal(result.data.generation?.status,'cancelled',JSON.stringify(result.data));
  };
  let seedAsset;
  await check('production upload, private file, scoped reference and canonical queue use the same original bytes', async () => {
    const begin = await media('begin', {title:imageFile.name,original_name:imageFile.name,source:'chat_image_reference',media_type:'photo',mime_type:'image/png'});
    assert.equal(begin.data.ok,true,JSON.stringify(begin.data));
    const chunk = await media('chunk',{token:begin.data.token,index:'0',total_chunks:'1',chunk:new Blob([imageBytes],{type:'image/png'})});
    assert.equal(chunk.data.ok,true,JSON.stringify(chunk.data));
    const finish = await media('finish',{token:begin.data.token});
    assert.equal(finish.data.ok,true,JSON.stringify(finish.data));seedAsset=finish.data.asset.id;
    const state=await control(), verified=await reference(seedAsset,state.binding);
    assert.equal(verified.response.status,200,JSON.stringify(verified.data));
    assert.equal(verified.data.reference.sha256,imageHash);
    assert.deepEqual(Buffer.from(await (await fetch(base+verified.data.asset.url)).arrayBuffer()),imageBytes);
    assert.equal((await reference(seedAsset,'stale-binding')).response.status,403);
    assert.equal((await reference(seedAsset,state.binding,{conversation_id:12})).response.status,403);
    await control({mode:'queue'});
    const queued=await post('/api/chat-v236.php',JSON.stringify({action:'send',conversation_id:11,message:'Generate 3 images: Rework this artwork',image_reference_asset_id:seedAsset}),{'Content-Type':'application/json'});
    assert.equal(queued.data.tool_receipt?.status,'completed',JSON.stringify(queued.data));
    const job=(await control()).jobs.at(-1);
    assert.equal(job.config.count,3);assert.equal(job.config.reference.asset_id,seedAsset);assert.equal(job.config.reference.sha256,imageHash);
    await cancel(job);await control({mode:'ordinary'});
  });
  let seeded,generatedIds,generatedHashes;
  await check('four canonical generated outputs retain distinct private pixels and expose the exact third image as a reference',async()=>{
    seeded=(await control({seed_batch:true})).seeded;
    assert.equal(seeded.assets.length,4);generatedIds=seeded.assets.map(asset=>asset.id);
    generatedHashes=await Promise.all(seeded.assets.map(async asset=>createHash('sha256').update(Buffer.from(await (await fetch(base+asset.url)).arrayBuffer())).digest('hex')));
    assert.equal(new Set(generatedHashes).size,4,'Every batch variant must have distinct source bytes.');
    const verified=await reference(generatedIds[2],(await control()).binding);
    assert.equal(verified.data.reference.asset_id,generatedIds[2]);assert.equal(verified.data.reference.sha256,generatedHashes[2]);
  });
  await check('the production active-request gate returns the original job and links without queueing another job',async()=>{
    const state=await control({seed_blocker:true,mode:'active_request'}),blocker=state.blocker;
    const result=await post('/api/chat-v236.php',JSON.stringify({action:'send',conversation_id:14,
      message:'Generate 2 images: Rework this reference',image_reference_asset_id:seedAsset}),{'Content-Type':'application/json'});
    assert.equal(result.response.status,200,JSON.stringify(result.data));
    assert.equal(result.data.tool_receipt?.reason,'active_request',JSON.stringify(result.data));
    assert.equal(result.data.tool_receipt.status,'blocked');assert.equal(result.data.tool_receipt.operation,'queue');
    const conflict=result.data.canvas_media.image_conflict;
    assert.equal(conflict.contract,'vp3.image-conflict.v1');assert.equal(conflict.generation_id,blocker.id);
    assert.equal(conflict.agent_id,8);assert.equal(conflict.conversation_id,12);assert.equal(conflict.user_message_id,blocker.user_message_id);
    assert.deepEqual(result.data.canvas_media.generation_ids,[]);
    assert.equal(result.data.actions[0].url,'/agent-workflows.php?id='+blocker.job_id);
    assert.equal(result.data.actions[1].url,'/chat.php?agent=8&conversation_id=12&message_id='+blocker.user_message_id);
    assert.equal((await control()).jobs.length,state.jobs.length);
    await cancel(blocker);await control({mode:'ordinary'});
  });
  if (process.env.VP3_IMAGE_COMPOSER_FIXTURE_ONLY === '1') {
    console.log('CHAT_IMAGE_COMPOSER_FIXTURE=PASS (real upload/reference/queue, active-request conflict and controlled canonical batch; no browser or paid provider launched)');
  } else {
    const {chromium} = await import('playwright');
    browser = await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
    const page = await browser.newPage({viewport:{width:1280,height:800}});
    page.setDefaultTimeout(12000);page.on('pageerror',error=>errors.push(error.message));
    let uploadGate=null, referenceGate=null, sendGate=null, newChatGate=null, commandPlan=null, failUpload=false, referenceFailure=null, referenceMismatch=false;
    await page.route('**/api/**',async route=>{
      const request=route.request(), pathname=new URL(request.url()).pathname;
      if(pathname==='/api/media-library-v86.php'){
        const raw=request.postData()||'', action=raw.match(/name="action"\r?\n\r?\n([^\r\n]+)/)?.[1];
        const index=Number(raw.match(/name="index"\r?\n\r?\n([^\r\n]+)/)?.[1]);
        calls.push({pathname,action,index});
        if(action==='chunk'&&failUpload)return route.fulfill({status:503,json:{ok:false,error:'Fixture upload interruption.'}});
        if(action==='chunk'&&uploadGate&&(uploadGate.index===undefined||uploadGate.index===index)){
          const gate=uploadGate;gate.entered.resolve();await gate.release.promise;
        }
        return route.continue();
      }
      const payload=request.postDataJSON()||{};calls.push({pathname,...payload});
      if(pathname==='/api/initiative-workbench-v1.php'&&commandPlan)return route.fulfill({json:{ok:true,
        owner_key:'1',tool:'marketing',agent_id:7,conversation_id:payload.conversation_id,plan:commandPlan}});
      if(pathname==='/api/chat-image-studio-v1.php')return route.fulfill({json:{ok:true,state:{
        contract:'vp3.image-studio.v1',owner_user_id:1,agent_id:7,conversation_id:payload.conversation_id,
        working_attention_binding:request.headers()['x-vp3-working-attention'],source_revision:'a'.repeat(64),sources:[],next_before:null,brainstorm:null,
        generations:[{...seeded,conversation_id:11,chat_label:'Image project',reference_asset_id:0}]
      }}});
      if(pathname==='/api/chat-canvas-media-v1.php'){
        // Keep browser sends at the real canonical queue boundary. The seeded
        // four-output generation above uses an explicit controlled transport.
        if(payload.action==='tick'){
          const response=await route.fetch({postData:JSON.stringify({...payload,action:'get'})});
          return route.fulfill({response});
        }
        if(payload.action==='reference'&&referenceGate){
          const gate=referenceGate,response=await route.fetch();gate.entered.resolve();await gate.release.promise;
          // Navigation/cancellation deliberately aborts these client requests.
          await route.fulfill({response}).catch(()=>{});gate.completed.resolve();return;
        }
        if(payload.action==='reference'&&referenceFailure)return route.fulfill({status:422,json:{ok:false,error:referenceFailure}});
        if(payload.action==='reference'&&referenceMismatch){
          const response=await route.fetch(),data=await response.json(),other=seeded.assets[0];
          data.asset=other;data.reference.asset_id=other.id;return route.fulfill({response,json:data});
        }
        return route.continue();
      }
      if(['/api/chat-v236.php','/api/chat-stream-v121.php'].includes(pathname)){
        if(payload.action==='new'&&newChatGate){
          const gate=newChatGate,response=await route.fetch();gate.entered.resolve();await gate.release.promise;
          await route.fulfill({response}).catch(()=>{});gate.completed.resolve();return;
        }
        if(payload.action==='send'&&sendGate){
          const gate=sendGate,response=await route.fetch();gate.entered.resolve();await gate.release.promise;
          await route.fulfill({response}).catch(()=>{});gate.completed.resolve();return;
        }
        return route.continue();
      }
      return route.fulfill({json:{ok:true,items:[],updates:[],conversations:[],route:'chat'}});
    });
    // Lazy participant/editor modules are outside this slice; all declared Chat
    // assets above are the production sources, served by the PHP fixture.
    await page.route('**/*.js?**',route=>route.fulfill({contentType:'text/javascript',body:''}));
    async function boot({cid=11,voice=false,clear=false,mediaCommands=false,query=''}={}) {
      await page.goto(base + '/'+query);
      await page.evaluate(({cid,voice,clear,mediaCommands})=>{
        if(clear){sessionStorage.clear();localStorage.clear();}
        const workingAttentionBinding=window.FIXTURE_BINDING;
        window.STONEFELLOW_CHAT={userId:1,agentId:7,endpoint:'/api/chat-v236.php',csrf:'fixture',initialView:'chat',initialConversationId:cid,workingAttentionBinding};
        window.VP3_IMAGE_COMPOSER_V1={ownerKey:'1',agentId:7,csrf:'fixture',workingAttentionBinding,
          endpoint:'/api/chat-canvas-media-v1.php',uploadEndpoint:'/api/media-library-v86.php',mediaFileEndpoint:'/media-library-file-v86.php'};
        window.VP3_CANVAS_MEDIA_V1={endpoint:'/api/chat-canvas-media-v1.php',csrf:'fixture',userId:1,agentId:7};
        window.VP3_IMAGE_STUDIO_V1={endpoint:'/api/chat-image-studio-v1.php',csrf:'fixture',ownerKey:'1',agentId:7,workingAttentionBinding};
        if(mediaCommands)window.VP3_INITIATIVE_WORKBENCH={endpoint:'/api/initiative-workbench-v1.php',mediaEndpoint:'/api/media-library-v86.php',csrf:'fixture',ownerKey:'1',agentId:7};
        localStorage.setItem('stonefellow:voice-mode:1','0');
        if(voice){
          window.STONEFELLOW_AGENT_CONTEXT={userId:1,surface:'chat',csrf:'fixture',proactiveEndpoint:'/api/context'};
          window.STONEFELLOW_AGENT_IDENTITY_V236={agentVoiceEnabled:true};
          window.recognizers=[];
          window.SpeechRecognition=class{
            constructor(){window.recognizers.push(this);}
            start(){this.active=true;queueMicrotask(()=>this.onstart?.());}
            stop(){this.active=false;queueMicrotask(()=>this.onend?.());}
            abort(){this.stop();}
          };
          Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},getVoices:()=>[],speak(utterance){queueMicrotask(()=>{utterance.onstart?.();utterance.onend?.();});}}});
          window.SpeechSynthesisUtterance=class{constructor(text){this.text=text;}};
          const button=document.getElementById('chatVoiceButton');window.STONEFELLOW_CHAT_VOICE_BOOT={button};button.id='chatVoiceButtonLegacyDormant';
          window.StonefellowPremiumVoiceV122=()=>({warm:async()=>false,createStream(){return{started:Promise.resolve(),push(){},end(){},stop(){}};},stop(){}});
        }
      },{cid,voice,clear,mediaCommands});
      for(const file of ['chat.css','chat-tools.css','chat-image-composer-v1.css','chat-canvas-media-v1.css','chat-image-studio-v1.css'])await page.addStyleTag({url:base+'/'+file});
      for(const file of ['chat-canvas-media-v1.js','chat.js','chat-tools.js','chat-image-composer-v1.js','chat-image-studio-v1.js',...(voice?['voice-lease-v122.js','agent-context-v131.js','chat-voice.js']:[])])await page.addScriptTag({url:base+'/'+file});
      if(mediaCommands){
        for(const file of ['media-library-picker-v1.css','chat-media-commands-v1.css'])await page.addStyleTag({url:base+'/'+file});
        for(const file of ['media-library-picker-v1.js','chat-media-commands-v1.js'])await page.addScriptTag({url:base+'/'+file});
      }
      await page.waitForFunction(cid=>window.STONEFELLOW_CHAT_CONTINUITY_V87?.isReady()&&window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===cid,cid);
    }
    const input=page.getByRole('textbox',{name:'Message your Agent'});
    const selected=page.getByRole('img',{name:'Selected base image',exact:true});
    const sends=()=>calls.filter(call=>call.action==='send');
    const uploads=()=>calls.filter(call=>call.pathname==='/api/media-library-v86.php');
    const references=()=>calls.filter(call=>call.action==='reference');
    async function upload(name=imageFile.name){
      await page.getByRole('button',{name:'Chat tools',exact:true}).click();
      const chooser=page.waitForEvent('filechooser');
      await page.getByRole('button',{name:'Upload base image',exact:true}).click();
      await (await chooser).setFiles({...imageFile,name});await selected.waitFor();
      await selected.evaluate(image=>image.decode());
      return Number(new URL(await selected.getAttribute('src')).searchParams.get('id'));
    }
    async function send(text){
      await input.fill(text);const before=sends().length;
      await page.getByRole('button',{name:'Send message',exact:true}).click();
      await page.waitForFunction(()=>!window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy());
      assert.equal(sends().length,before+1);return sends().at(-1);
    }
    async function capture(name){
      const directory=process.env.VP3_IMAGE_COMPOSER_SCREENSHOTS;if(!directory)return;
      fs.mkdirSync(directory,{recursive:true});await page.screenshot({path:path.join(directory,name+'.png'),fullPage:true});
    }
    const viewer=page.getByRole('region',{name:'Image viewer',exact:true});
    const choice=page.locator('#chatComposerShell .vp3-image-reference-choice');
    const baseId=async()=>Number(new URL(await selected.getAttribute('src'),base).searchParams.get('id'));
    async function waitForBase(id){
      await page.waitForFunction(id=>{
        const image=document.querySelector('.vp3-image-reference img');
        return image&&new URL(image.src).searchParams.get('id')===String(id);
      },id);
    }
    async function openGenerated(index){
      assert.equal(await page.evaluate(({ids,index})=>window.VP3CanvasMedia.openImages(ids,{index,focusReturn:document.getElementById('chatInput')}),{ids:generatedIds,index}),true);
      await viewer.waitFor();
    }
    async function releaseReference(gate){gate.release.resolve();referenceGate=null;await gate.completed.promise;await page.evaluate(()=>Promise.resolve());}
    async function releaseSend(gate){
      gate.release.resolve();sendGate=null;await gate.completed.promise;
      await page.waitForFunction(()=>!window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy());
    }
    const drafts=()=>page.evaluate(()=>JSON.parse(sessionStorage.getItem('vp3:chat:drafts:1:7')||'{}'));
    async function replacePendingDraft(text){
      await input.evaluate((element,text)=>{element.value=text;element.dispatchEvent(new Event('input',{bubbles:true}));},text);
    }
    await boot({clear:true});
    let currentAsset;
    await check('the Chat tools upload uses begin/chunk/finish and verifies a real private base without replacing a draft',async()=>{
      await input.fill('Keep this album-cover direction while I choose the base.');
      const before=uploads().length, beforeReferences=references().length;
      currentAsset=await upload();
      assert.deepEqual(uploads().slice(before).map(call=>call.action),['begin','chunk','finish']);
      assert.equal(references().length,beforeReferences+1);assert.equal(references().at(-1).asset_id,currentAsset);
      assert.equal(await input.inputValue(),'Keep this album-cover direction while I choose the base.');
      assert.equal(await page.getByRole('dialog',{name:'Chat tools',exact:true}).isVisible(),false);
      const state=await control();assert.equal(state.assets.find(asset=>asset.id===currentAsset).available,true);
      const image=await page.request.get(await selected.getAttribute('src'));assert.deepEqual(await image.body(),imageBytes);
      assert.equal(sends().length,0);await capture('base-image-attached-desktop');
    });
    await check('reload restores only the saved asset identity, reauthorizes it, and retains the exact prompt draft',async()=>{
      const before=uploads().length, beforeReferences=references().length;
      const saved=await page.evaluate(()=>JSON.parse(sessionStorage.getItem('vp3:image-base:1:7:11')));
      assert.deepEqual(Object.keys(saved).sort(),['assetId','binding']);assert.equal(saved.assetId,currentAsset);
      await boot();await selected.waitFor();
      assert.equal(uploads().length,before);assert.equal(references().length,beforeReferences+1);
      assert.equal(await input.inputValue(),'Keep this album-cover direction while I choose the base.');
    });
    await check('delivery failures keep the prompt and base; ordinary, blocked and malformed queue replies cannot consume it',async()=>{
      await control({mode:'error'});await send('Rework this cover using the attached image');
      assert.equal(await input.inputValue(),'Rework this cover using the attached image');assert.equal(await selected.count(),1);
      for(const mode of ['ordinary','blocked','malformed']){
        await control({mode});const payload=await send('Discuss this cover: '+mode);
        assert.equal(payload.image_reference_asset_id,currentAsset);assert.equal(await selected.count(),1);
      }
    });
    let activeBlocker;
    await check('an active image request preserves the exact typed prompt and original base through reload without retrying the blocker',async()=>{
      const before=await control({mode:'active_request',seed_blocker:true});activeBlocker=before.blocker;
      const prompt='Generate 2 images: Keep the exact cover composition.\nUse muted greens, warm paper, and the original lettering.';
      const payload=await send(prompt);
      assert.equal(payload.image_reference_asset_id,currentAsset);assert.equal(await input.inputValue(),prompt);
      assert.equal(await baseId(),currentAsset);assert.equal((await drafts())[11].text,prompt);
      assert.equal(await page.getByRole('link',{name:'Manage earlier image request',exact:true}).last().getAttribute('href'),'/agent-workflows.php?id='+activeBlocker.job_id);
      assert.equal(await page.getByRole('link',{name:'Open original conversation',exact:true}).last().getAttribute('href'),
        '/chat.php?agent=8&conversation_id=12&message_id='+activeBlocker.user_message_id);
      assert.match(await page.locator('#chatRecoveryStatus').innerText(),/Your image prompt is kept/);
      assert.equal((await control()).jobs.length,before.jobs.length);
      assert.equal(calls.filter(call=>call.action==='tick'&&call.generation_id===activeBlocker.id).length,0);
      assert.equal(await page.locator('[data-generation-id="'+activeBlocker.id+'"]').count(),0);
      const beforeSends=sends().length;await boot();await waitForBase(currentAsset);
      assert.equal(await input.inputValue(),prompt);assert.equal(sends().length,beforeSends);
    });
    await check('a held active-request response keeps a newer draft or deliberate clearing instead of restoring the submitted text',async()=>{
      for(const newer of ['A newer direction that must win.','']){
        sendGate=createGate();const gate=sendGate;
        await input.fill('This submitted image prompt must not replace newer edits.');
        await page.getByRole('button',{name:'Send message',exact:true}).click();await reached(gate);
        await replacePendingDraft('Newer draft written during this response.');await replacePendingDraft(newer);
        await releaseSend(gate);
        assert.equal(await input.inputValue(),newer);assert.equal((await drafts())[11]?.text,newer||undefined);
        assert.equal(await baseId(),currentAsset);
        await boot();await waitForBase(currentAsset);assert.equal(await input.inputValue(),newer);
      }
    });
    await check('a delayed active-request response cannot restore private text or leave a typing bubble after a conversation or owner change',async()=>{
      for(const change of ['chat','owner']){
        await boot({clear:true});
        const candidate=(await control()).assets.find(asset=>asset.id===currentAsset);
        assert.equal(await page.evaluate(asset=>window.VP3ImageComposer.attachAsset(asset,
          window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()),candidate),true);
        sendGate=createGate();const gate=sendGate;
        await input.fill('Original private prompt before '+change);await page.getByRole('button',{name:'Send message',exact:true}).click();await reached(gate);
        if(change==='chat'){
          await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(14));
          await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===14&&window.STONEFELLOW_CHAT_CONTINUITY_V87.isReady());
        }else await page.evaluate(()=>{window.STONEFELLOW_CHAT.userId=2;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));});
        const destination='Destination draft after '+change;await replacePendingDraft(destination);
        const beforeDrafts=await drafts();await releaseSend(gate);
        assert.equal(await input.inputValue(),destination);assert.deepEqual(await drafts(),beforeDrafts);
        assert.equal(await selected.count(),0);assert.equal(await page.locator('#chatThread .typing').count(),0);
      }
    });
    await check('a blocked first send binds the new conversation before saving its prompt and never revives draft zero',async()=>{
      await boot({cid:0,clear:true});
      await page.evaluate(()=>{window.recoveryBindings=[];window.addEventListener('vp3:chat-conversation-bound',event=>window.recoveryBindings.push(event.detail));});
      const prompt='Generate 1 image: A quiet forest in watercolor.';
      const first=await send(prompt),cid=await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId());
      assert.equal(first.conversation_id,0);assert.ok(cid>0);assert.equal(await input.inputValue(),prompt);
      const saved=await drafts();assert.equal(saved[0],undefined);assert.equal(saved[cid].text,prompt);
      assert.deepEqual(await page.evaluate(()=>window.recoveryBindings),[{previous_conversation_id:0,conversation_id:cid}]);
      await boot({cid});assert.equal(await input.inputValue(),prompt);
      const next=await send(prompt);assert.equal(next.conversation_id,cid);assert.equal((await drafts())[0],undefined);
    });
    await check('an actual automatic voice conflict keeps the base but cannot restore the submitted utterance or save it as a draft',async()=>{
      await boot({voice:true,clear:true});const candidate=(await control()).assets.find(asset=>asset.id===currentAsset);
      assert.equal(await page.evaluate(asset=>window.VP3ImageComposer.attachAsset(asset,
        window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()),candidate),true);
      await input.fill('');const before=sends().length;
      await page.getByRole('button',{name:'Start voice conversation',exact:true}).click();
      await page.waitForFunction(()=>window.recognizers.some(item=>item.active));
      const delivered=page.waitForResponse(response=>new URL(response.url()).pathname==='/api/chat-stream-v121.php'&&response.request().postDataJSON()?.action==='send');
      await page.evaluate(()=>{
        const recognition=window.recognizers.findLast(item=>item.active),final=[{transcript:'Generate 1 image: Give the attached image a painted background',confidence:.98}];
        final.isFinal=true;recognition.onresult({resultIndex:0,results:[final]});
      });
      await delivered;await page.waitForFunction(()=>!window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy()&&!window.STONEFELLOW_CHAT_CONTINUITY.pendingVoiceSubmit());
      assert.equal(sends().length,before+1);assert.equal(sends().at(-1).input_mode,'voice');
      assert.equal(await input.inputValue(),'');assert.equal((await drafts())[11],undefined);assert.equal(await baseId(),currentAsset);
      await page.getByRole('button',{name:'Stop voice conversation',exact:true}).click();
      await boot();await waitForBase(currentAsset);assert.equal(await input.inputValue(),'');
      assert.equal(calls.filter(call=>call.action==='tick'&&call.generation_id===activeBlocker.id).length,0);
      await cancel(activeBlocker);await control({mode:'ordinary'});
    });
    await check('typed submission freezes the exact base into one canonical job and clears it only after a valid queue receipt',async()=>{
      await control({mode:'queue'});sendGate=createGate();
      await input.fill('Generate 2 images: Put this album artwork on a warm cream background');
      await page.getByRole('button',{name:'Send message',exact:true}).click();await reached(sendGate);
      assert.equal(await selected.count(),1);assert.equal(sends().at(-1).image_reference_asset_id,currentAsset);
      sendGate.release.resolve();sendGate=null;await selected.waitFor({state:'detached'});
      await page.waitForFunction(()=>!window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy());
      const job=(await control()).jobs.at(-1);assert.equal(job.config.count,2);assert.equal(job.config.reference.asset_id,currentAsset);assert.equal(job.config.reference.sha256,imageHash);
      assert.equal(await page.evaluate(()=>sessionStorage.getItem('vp3:image-base:1:7:11')),null);
      await cancel(job);await control({mode:'ordinary'});
      const payload=await send('Continue without an attachment');assert.equal('image_reference_asset_id' in payload,false);
    });
    await check('history staging carries the count and reauthorized base into Chat without sending or overwriting a draft',async()=>{
      await input.fill('My own unfinished direction');const before=sends().length,beforeReferences=references().length;
      assert.equal(await page.evaluate(asset=>window.VP3ImageComposer.stagePrompt('A cut-paper landscape',{count:3,referenceAssetId:asset,conversationId:11}),currentAsset),false);
      assert.equal(await input.inputValue(),'My own unfinished direction');assert.equal(references().length,beforeReferences);
      await input.fill('');
      assert.equal(await page.evaluate(asset=>window.VP3ImageComposer.stagePrompt('A cut-paper landscape',{count:3,referenceAssetId:asset,conversationId:11}),currentAsset),true);
      assert.equal(await input.inputValue(),'Generate 3 images: A cut-paper landscape');assert.equal(sends().length,before);assert.equal(await selected.count(),1);
      await control({mode:'queue'});await send(await input.inputValue());
      const job=(await control()).jobs.at(-1);assert.equal(job.config.count,3);assert.equal(job.config.reference.asset_id,currentAsset);await cancel(job);await control({mode:'ordinary'});
    });
    await check('typing during history base validation wins over the late staged prompt',async()=>{
      await input.fill('');referenceGate=createGate();
      const staging=page.evaluate(asset=>window.VP3ImageComposer.stagePrompt('This staged direction must not replace my typing',{count:4,referenceAssetId:asset,conversationId:11}),currentAsset);
      await reached(referenceGate);await input.fill('New words typed during image lookup');referenceGate.release.resolve();referenceGate=null;
      assert.equal(await staging,false);assert.equal(await input.inputValue(),'New words typed during image lookup');assert.equal(await selected.count(),0);
    });
    await check('choosing image three of a canonical batch offers that exact result in the composer without attaching, uploading or sending',async()=>{
      const before=sends().length,beforeReferences=references().length,beforeUploads=uploads().length;
      const card=page.locator('[data-generation-id="'+seeded.id+'"]');
      await card.locator('.vp3-image-tile').nth(2).click();await choice.waitFor();
      assert.equal(new URL(await viewer.locator('figure>img').getAttribute('src'),base).href,new URL(seeded.assets[2].url,base).href);
      assert.equal(await choice.getByText('Image 3 of 4',{exact:true}).count(),1);
      assert.equal(await selected.count(),0);assert.equal(references().length,beforeReferences);
      assert.equal(uploads().length,beforeUploads);assert.equal(sends().length,before);
      assert.equal(await input.inputValue(),'New words typed during image lookup');
      assert.equal(await card.getByRole('button',{name:'Use this image',exact:true}).count(),0);
      await choice.getByRole('button',{name:'Use this image',exact:true}).click();await waitForBase(generatedIds[2]);
      assert.equal(references().at(-1).asset_id,generatedIds[2]);assert.equal(await input.inputValue(),'New words typed during image lookup');
      assert.equal(await input.evaluate(element=>document.activeElement===element),true);
      assert.equal(sends().length,before);assert.equal(uploads().length,beforeUploads);
      await capture('generated-image-base-desktop');
      await viewer.getByRole('button',{name:/Back to images$/}).click();
      assert.equal(await choice.isVisible(),false);assert.equal(await baseId(),generatedIds[2]);
    });
    await check('a typed follow-up queues exactly the selected generated pixels, preserves the source batch and consumes only its valid receipt',async()=>{
      await control({mode:'queue'});
      const payload=await send('Generate 2 images: Make the background blue and keep everything else the same');
      assert.equal(payload.image_reference_asset_id,generatedIds[2]);await selected.waitFor({state:'detached'});
      const job=(await control()).jobs.at(-1);
      assert.equal(job.config.reference.asset_id,generatedIds[2]);assert.equal(job.config.reference.sha256,generatedHashes[2]);assert.equal(job.config.count,2);
      for(const [index,asset] of seeded.assets.entries()){
        assert.equal(createHash('sha256').update(Buffer.from(await (await fetch(base+asset.url)).arrayBuffer())).digest('hex'),generatedHashes[index]);
      }
      await cancel(job);await control({mode:'ordinary'});await input.fill('New words typed during image lookup');
    });
    await check('the real Image History client opens the selected output in the real viewer and attaches it without restoring the original prompt',async()=>{
      const before=sends().length;
      await page.evaluate(()=>{
        const host=document.createElement('section');host.id='fixtureImageHistory';document.getElementById('chatThread').append(host);
        window.fixtureImageHistory=window.VP3ImageStudio.mountWorkspace(host,()=>({hasChat:true,owner:1,agent:7,
          cid:window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId(),binding:window.FIXTURE_BINDING}),()=>{
          window.fixtureImageHistory?.dispose();host.remove();
        });
      });
      const history=page.locator('#fixtureImageHistory');await history.locator('[data-image-studio-thumbnail]').nth(2).click();
      await history.waitFor({state:'detached'});await choice.getByText('Image 3 of 4',{exact:true}).waitFor();
      await choice.getByRole('button',{name:'Use this image',exact:true}).click();await waitForBase(generatedIds[2]);
      assert.equal(await input.inputValue(),'New words typed during image lookup');assert.equal(sends().length,before);
      await viewer.getByRole('button',{name:/Back to Chat$/}).click();
    });
    await check('failed and mismatched saved-image replacements preserve the prior base, draft and keyboard focus at the composer',async()=>{
      await openGenerated(1);const before=sends().length;
      referenceFailure='This saved image cannot be used as an editing base.';
      await choice.getByRole('button',{name:'Replace base image',exact:true}).focus();
      await page.keyboard.press('Enter');await page.getByText(referenceFailure,{exact:true}).waitFor();
      assert.equal(await baseId(),generatedIds[2]);assert.equal(await input.inputValue(),'New words typed during image lookup');
      assert.equal(await choice.getByRole('button',{name:'Replace base image',exact:true}).evaluate(element=>document.activeElement===element),true);
      referenceFailure=null;referenceMismatch=true;
      await page.keyboard.press('Enter');await page.getByText('The base image could not be verified.',{exact:true}).waitFor();
      assert.equal(await baseId(),generatedIds[2]);assert.equal(sends().length,before);
      assert.equal(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('vp3:image-base:1:7:11')).assetId),generatedIds[2]);
      referenceMismatch=false;
    });
    await check('cancelling a replacement retains the previous attachment and restores keyboard focus without sending',async()=>{
      referenceGate=createGate();const gate=referenceGate,before=sends().length;
      await choice.getByRole('button',{name:'Replace base image',exact:true}).focus();await page.keyboard.press('Enter');await reached(gate);
      assert.equal(await baseId(),generatedIds[2]);
      assert.equal(await choice.getByRole('button',{name:'Cancel image selection',exact:true}).evaluate(element=>document.activeElement===element),true);
      await page.getByRole('button',{name:'Send message',exact:true}).click();assert.equal(sends().length,before);
      await choice.getByRole('button',{name:'Cancel image selection',exact:true}).focus();await page.keyboard.press('Enter');
      assert.equal(await choice.getByRole('button',{name:'Replace base image',exact:true}).evaluate(element=>document.activeElement===element),true);
      await releaseReference(gate);assert.equal(await baseId(),generatedIds[2]);
      assert.equal(await input.inputValue(),'New words typed during image lookup');
    });
    await check('changing the viewed thumbnail retires a pending selection, and an explicit retry attaches only the newly chosen image',async()=>{
      referenceGate=createGate();const gate=referenceGate;
      await choice.getByRole('button',{name:'Replace base image',exact:true}).click();await reached(gate);
      await viewer.getByRole('button',{name:'View image 4',exact:true}).click();
      await releaseReference(gate);assert.equal(await baseId(),generatedIds[2]);
      assert.equal(await choice.getByText('Image 4 of 4',{exact:true}).count(),1);
      assert.equal(await viewer.getByRole('button',{name:'View image 4',exact:true}).evaluate(element=>document.activeElement===element),true);
      await choice.getByRole('button',{name:'Replace base image',exact:true}).click();await waitForBase(generatedIds[3]);
      assert.equal(await input.inputValue(),'New words typed during image lookup');
      await page.getByRole('button',{name:'Remove base image',exact:true}).click();assert.equal(await selected.count(),0);
      await choice.getByRole('button',{name:'Use this image',exact:true}).click();await waitForBase(generatedIds[3]);
    });
    await check('closing the viewer during replacement prevents the late result from changing the current base or draft',async()=>{
      await viewer.getByRole('button',{name:'View image 2',exact:true}).click();referenceGate=createGate();const gate=referenceGate;
      await choice.getByRole('button',{name:'Replace base image',exact:true}).click();await reached(gate);
      await viewer.getByRole('button',{name:/Back to Chat$/}).click();await releaseReference(gate);
      assert.equal(await choice.isVisible(),false);assert.equal(await baseId(),generatedIds[3]);
      assert.equal(await input.inputValue(),'New words typed during image lookup');
    });
    await check('a Chat switch during saved-image validation cannot attach the old image in the new Chat',async()=>{
      await openGenerated(1);referenceGate=createGate();const gate=referenceGate;
      await choice.getByRole('button',{name:'Replace base image',exact:true}).click();await reached(gate);
      await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(14));
      await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===14&&window.STONEFELLOW_CHAT_CONTINUITY_V87.isReady());
      await releaseReference(gate);assert.equal(await selected.count(),0);assert.equal(await choice.isVisible(),false);
      assert.equal(await page.evaluate(()=>window.VP3ImageComposer.requestContext(14)),null);
      await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(11));
      await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===11&&window.STONEFELLOW_CHAT_CONTINUITY_V87.isReady());
      await waitForBase(generatedIds[3]);assert.equal(await input.inputValue(),'New words typed during image lookup');
    });
    await check('participant changes cancel saved-image selection and retire both candidate and prior private attachment',async()=>{
      await openGenerated(0);referenceGate=createGate();const gate=referenceGate;
      await choice.getByRole('button',{name:'Replace base image',exact:true}).click();await reached(gate);
      await page.evaluate(()=>{
        window.VP3ParticipantContinuity={selectedContext:()=>({participant_id:22})};
        window.dispatchEvent(new Event('vp3:participant-selection-changed'));
      });
      await releaseReference(gate);assert.equal(await selected.count(),0);assert.equal(await choice.isVisible(),false);assert.equal(await viewer.count(),0);
      assert.equal(await page.evaluate(()=>sessionStorage.getItem('vp3:image-base:1:7:11')),null);
      assert.equal(await input.inputValue(),'New words typed during image lookup');
      await page.evaluate(()=>{delete window.VP3ParticipantContinuity;window.dispatchEvent(new Event('vp3:participant-selection-changed'));});
    });
    await check('a changed owner, Agent or page session cannot accept a late saved-image validation',async()=>{
      for(const [key,value] of [['userId',2],['agentId',9],['workingAttentionBinding','replaced-page-session']]){
        await openGenerated(1);referenceGate=createGate();const gate=referenceGate;
        await choice.getByRole('button',{name:'Use this image',exact:true}).click();await reached(gate);
        const prior=await page.evaluate(({key,value})=>{
          const prior=window.STONEFELLOW_CHAT[key];window.STONEFELLOW_CHAT[key]=value;return prior;
        },{key,value});
        await releaseReference(gate);await choice.waitFor({state:'hidden'});
        assert.equal(await selected.count(),0);assert.equal(await input.inputValue(),'New words typed during image lookup');
        assert.equal(await page.evaluate(()=>sessionStorage.getItem('vp3:image-base:1:7:11')),null);
        await page.evaluate(({key,prior})=>{window.STONEFELLOW_CHAT[key]=prior;},{key,prior});
        if(await viewer.count())await viewer.getByRole('button',{name:/Back to Chat$/}).click();
      }
    });
    await check('mobile replacement reserves measured space for both images, feedback and a multiline draft without covering viewer thumbnails',async()=>{
      await page.setViewportSize({width:390,height:844});await openGenerated(2);await choice.waitFor();
      await choice.getByRole('button',{name:'Use this image',exact:true}).click();await waitForBase(generatedIds[2]);
      const draft='Keep this exact cover composition.\nMake the background warmer.\nKeep the lettering readable.\nGive me two variations to compare.';
      await input.fill(draft);await viewer.getByRole('button',{name:'View image 4',exact:true}).click();
      referenceFailure='This saved image cannot be used as an editing base.';
      await choice.getByRole('button',{name:'Replace base image',exact:true}).click();
      await page.getByText(referenceFailure,{exact:true}).waitFor();referenceFailure=null;
      await page.waitForFunction(()=>parseFloat(getComputedStyle(document.getElementById('chatThread')).paddingBottom)
        >=document.getElementById('chatComposerShell').getBoundingClientRect().height+15);
      await page.locator('#chatThread').evaluate(element=>element.scrollTo({top:element.scrollHeight,behavior:'instant'}));
      const bounds=await choice.evaluate(element=>{
        const row=element.getBoundingClientRect(),form=document.getElementById('chatForm').getBoundingClientRect();
        const base=document.querySelector('.vp3-image-reference').getBoundingClientRect();
        const thumbnail=document.querySelector('.vp3-image-viewer-thumbnails button:last-child'),thumb=thumbnail.getBoundingClientRect();
        const hit=document.elementFromPoint(thumb.left+thumb.width/2,thumb.top+thumb.height/2);
        return {left:row.left,right:row.right,bottom:row.bottom,top:row.top,baseTop:base.top,baseBottom:base.bottom,
          formTop:form.top,formBottom:form.bottom,thumbTop:thumb.top,thumbBottom:thumb.bottom,thumbClickable:thumbnail===hit||thumbnail.contains(hit),
          width:innerWidth,height:innerHeight,documentWidth:document.documentElement.scrollWidth};
      });
      assert.ok(bounds.left>=0&&bounds.right<=bounds.width+1&&bounds.documentWidth<=bounds.width+1);
      assert.ok(bounds.bottom<=bounds.baseTop&&bounds.baseBottom<=bounds.formTop&&bounds.formBottom<=bounds.height);
      assert.ok(bounds.thumbTop>=0&&bounds.thumbBottom<bounds.top);assert.equal(bounds.thumbClickable,true);
      assert.equal(await baseId(),generatedIds[2]);assert.equal(await input.inputValue(),draft);
      assert.equal(await input.isEnabled(),true);await capture('generated-image-choice-mobile');
      await viewer.getByRole('button',{name:'View image 2',exact:true}).click();
      assert.equal(await choice.getByText('Image 2 of 4',{exact:true}).count(),1);assert.equal(await baseId(),generatedIds[2]);
      await viewer.getByRole('button',{name:/Back to Chat$/}).click();
      await input.fill('New words typed during image lookup');
      const before=references().length;await boot();await waitForBase(generatedIds[2]);assert.equal(references().length,before+1);
      assert.equal(await input.inputValue(),'New words typed during image lookup');
      await page.getByRole('button',{name:'Remove base image',exact:true}).click();assert.equal(await selected.count(),0);
      await page.waitForFunction(()=>!document.getElementById('chatThread').hasAttribute('data-vp3-image-composer-space'));
      await page.setViewportSize({width:1280,height:800});
    });
    await check('an interrupted upload keeps the draft and can be retried; remove is clickable and clears only its attachment',async()=>{
      failUpload=true;await page.getByRole('button',{name:'Chat tools',exact:true}).click();
      await page.getByLabel('Choose base image',{exact:true}).setInputFiles(imageFile);
      await page.getByText('Fixture upload interruption.',{exact:true}).waitFor();
      assert.equal(await input.inputValue(),'New words typed during image lookup');assert.equal(await selected.count(),0);
      failUpload=false;await page.getByLabel('Choose base image',{exact:true}).setInputFiles(imageFile);await selected.waitFor();
      await page.getByRole('button',{name:'Remove base image',exact:true}).click();
      assert.equal(await selected.count(),0);assert.equal(await input.inputValue(),'New words typed during image lookup');
      assert.equal(await input.evaluate(element=>document.activeElement===element),true);
    });
    await check('invalid files fail before uploading and preserve the existing draft',async()=>{
      const before=uploads().length;await page.getByRole('button',{name:'Chat tools',exact:true}).click();
      for(const file of [{name:'vector.svg',mimeType:'image/svg+xml',buffer:Buffer.from('<svg/>')},
        {name:'too-large.png',mimeType:'image/png',buffer:Buffer.alloc(4*1024*1024+1)}]){
        await page.getByLabel('Choose base image',{exact:true}).setInputFiles(file);
        await page.getByText('Choose a PNG, JPG or WebP image up to 4 MB.',{exact:true}).waitFor();
      }
      assert.equal(uploads().length,before);assert.equal(await input.inputValue(),'New words typed during image lookup');
      await page.getByRole('button',{name:'Close chat tools',exact:true}).click();
    });
    await check('mobile upload progress is truthful and cancellable without covering or disabling the composer',async()=>{
      await page.setViewportSize({width:390,height:844});uploadGate=createGate(1);
      const largeBytes=png(512,768,true);assert.ok(largeBytes.length>1024*1024&&largeBytes.length<4*1024*1024);
      await page.getByRole('button',{name:'Chat tools',exact:true}).click();
      await page.getByLabel('Choose base image',{exact:true}).setInputFiles({...imageFile,buffer:largeBytes});
      await reached(uploadGate);
      const progress=page.getByRole('progressbar',{name:'Base image upload progress',exact:true});
      assert.equal(Number(await progress.getAttribute('value')),Math.round(1024*1024/largeBytes.length*100));
      await page.getByRole('button',{name:'Close chat tools',exact:true}).click();
      const before=sends().length;await page.getByRole('button',{name:'Send message',exact:true}).click();
      assert.equal(sends().length,before);assert.equal(await input.inputValue(),'New words typed during image lookup');
      const geometry=await page.locator('.vp3-image-reference').evaluate(element=>{
        const chip=element.getBoundingClientRect(),form=document.getElementById('chatForm').getBoundingClientRect();
        return {left:chip.left,right:chip.right,bottom:chip.bottom,formTop:form.top,formBottom:form.bottom,width:innerWidth,height:innerHeight,documentWidth:document.documentElement.scrollWidth,pointerEvents:getComputedStyle(element).pointerEvents};
      });
      assert.ok(geometry.left>=0&&geometry.right<=geometry.width+1&&geometry.documentWidth<=geometry.width+1);
      assert.ok(geometry.bottom<=geometry.formTop&&geometry.formBottom<=geometry.height);
      assert.equal(geometry.pointerEvents,'auto');assert.equal(await input.isEnabled(),true);
      await capture('base-image-upload-mobile');await page.getByRole('button',{name:'Cancel base image upload',exact:true}).click();
      uploadGate.release.resolve();uploadGate=null;await progress.waitFor({state:'detached'});
      assert.equal(await selected.count(),0);assert.equal(await input.inputValue(),'New words typed during image lookup');
    });
    await check('switching Chats during upload suppresses late private results and sends no base in the new Chat',async()=>{
      uploadGate=createGate();await page.getByRole('button',{name:'Chat tools',exact:true}).click();
      await page.getByLabel('Choose base image',{exact:true}).setInputFiles(imageFile);await reached(uploadGate);
      await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(14));
      await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===14&&window.STONEFELLOW_CHAT_CONTINUITY_V87.isReady());
      uploadGate.release.resolve();uploadGate=null;
      assert.equal(await selected.count(),0);const payload=await send('This message belongs to the other project');
      assert.equal(payload.conversation_id,14);assert.equal('image_reference_asset_id' in payload,false);
      await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(11));await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===11&&window.STONEFELLOW_CHAT_CONTINUITY_V87.isReady());
      assert.equal(await input.inputValue(),'New words typed during image lookup');
    });
    await check('participant changes retire private bases and the send guard prevents a stale attachment crossing into participant context',async()=>{
      for(const eventName of ['vp3:participant-selection-changed','vp3:participant-task-context']){
        await upload();
        await page.evaluate(name=>{
          window.VP3ParticipantContinuity={selectedContext:()=>({participant_id:22})};
          window.dispatchEvent(new CustomEvent(name,{detail:{participant_id:22}}));
        },eventName);
        assert.equal(await selected.count(),0);
        assert.equal(await page.evaluate(()=>sessionStorage.getItem('vp3:image-base:1:7:11')),null);
        assert.equal(await page.evaluate(()=>window.VP3ImageComposer.stagePrompt('A private image',{conversationId:11})),false);
        assert.equal(await input.inputValue(),'New words typed during image lookup');
        await page.evaluate(name=>{
          delete window.VP3ParticipantContinuity;window.dispatchEvent(new CustomEvent(name,{detail:{participant_id:0}}));
        },eventName);
      }
      await upload();const before=sends().length;
      await page.evaluate(()=>{window.VP3ParticipantContinuity={selectedContext:()=>({participant_id:22})};});
      await page.getByRole('button',{name:'Send message',exact:true}).click();
      assert.equal(sends().length,before);assert.equal(await selected.count(),0);
      assert.equal(await input.inputValue(),'New words typed during image lookup');
      assert.equal(await page.evaluate(()=>sessionStorage.getItem('vp3:image-base:1:7:11')),null);
      await page.evaluate(()=>{delete window.VP3ParticipantContinuity;});
    });
    await check('a removed private source cannot return from session storage on reload',async()=>{
      const asset=await upload('Temporary base.png');await control({delete_asset:asset});await boot();
      await page.waitForFunction(()=>!document.querySelector('.vp3-image-reference:not([hidden])'));
      assert.equal(await selected.count(),0);assert.equal(await page.evaluate(()=>sessionStorage.getItem('vp3:image-base:1:7:11')),null);
    });
    await check('a new Chat is created once before its first base upload and preserves its draft',async()=>{
      await boot({cid:0,clear:true});await input.fill('My first image direction');const before=calls.filter(call=>call.action==='new').length;
      const asset=await upload('First Chat base.png');const cid=await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId());
      assert.ok(cid>0);assert.equal(calls.filter(call=>call.action==='new').length,before+1);
      assert.equal(references().at(-1).conversation_id,cid);assert.equal(references().at(-1).asset_id,asset);
      assert.equal(await input.inputValue(),'My first image direction');await page.getByRole('button',{name:'Remove base image',exact:true}).click();
    });
    await check('actual fast voice submission preserves the uploaded base through its stream and consumes only the verified queue',async()=>{
      await page.setViewportSize({width:1280,height:800});await boot({voice:true,clear:true});await control({mode:'queue'});
      const asset=await upload('Spoken prompt base.png');await input.fill('');const before=sends().length;
      await page.getByRole('button',{name:'Start voice conversation',exact:true}).click();
      await page.waitForFunction(()=>window.recognizers.some(item=>item.active));
      await page.evaluate(()=>{
        const recognition=window.recognizers.findLast(item=>item.active), final=[{transcript:'Generate 2 images: Give the attached artwork a vintage paper texture',confidence:.98}];
        final.isFinal=true;recognition.onresult({resultIndex:0,results:[final]});
      });
      await selected.waitFor({state:'detached'});await page.waitForFunction(()=>!window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy());
      const sent=sends().slice(before);assert.equal(sent.length,1);assert.equal(sent[0].pathname,'/api/chat-stream-v121.php');
      assert.equal(sent[0].input_mode,'voice');assert.equal(sent[0].image_reference_asset_id,asset);
      const job=(await control()).jobs.at(-1);assert.equal(job.config.reference.asset_id,asset);assert.equal(job.config.count,2);assert.equal(job.config.reference.sha256,imageHash);
      await cancel(job);await page.getByRole('button',{name:'Stop voice conversation',exact:true}).click();
    });
    await check('library attachment verifies exact private bytes while keeping an existing typed prompt and creating no work',async()=>{
      await control({mode:'ordinary'});await boot({clear:true});
      await input.fill('Keep this complete image-edit draft.');
      const before=await control(),beforeReferences=references().length,beforeSends=sends().length;
      const candidate=before.assets.find(asset=>asset.id===generatedIds[2]);assert.equal(candidate?.available,true);
      assert.equal(await page.evaluate(async asset=>{
        const bound=window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext();
        return window.VP3ImageComposer.attachAsset(asset,bound);
      },candidate),true);
      await waitForBase(candidate.id);
      assert.equal(await input.inputValue(),'Keep this complete image-edit draft.');
      assert.equal(references().length,beforeReferences+1);assert.equal(references().at(-1).asset_id,candidate.id);
      assert.equal(sends().length,beforeSends);
      const after=await control();assert.equal(after.assets.length,before.assets.length);assert.equal(after.jobs.length,before.jobs.length);
    });
    await check('a failed or mismatched library reference keeps the prior base and complete draft',async()=>{
      const candidate=(await control()).assets.find(asset=>asset.id===generatedIds[3]);
      for(const kind of ['failure','mismatch']){
        referenceFailure=kind==='failure'?'This image is no longer available.':null;referenceMismatch=kind==='mismatch';
        const result=await page.evaluate(async asset=>{
          try{return {ok:await window.VP3ImageComposer.attachAsset(asset,window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext())};}
          catch(error){return {error:error.message};}
        },candidate);
        assert.ok(result.error);assert.equal(await baseId(),generatedIds[2]);
        assert.equal(await input.inputValue(),'Keep this complete image-edit draft.');
      }
      referenceFailure=null;referenceMismatch=false;
    });
    await check('cancelling a pending library attachment retires its real reference response without replacing the base',async()=>{
      const candidate=(await control()).assets.find(asset=>asset.id===generatedIds[3]);
      referenceGate=createGate();const gate=referenceGate;
      const attaching=page.evaluate(async asset=>{
        window.librarySelectionAbort=new AbortController();
        return window.VP3ImageComposer.attachAsset(asset,{...window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext(),signal:window.librarySelectionAbort.signal});
      },candidate);
      await reached(gate);await page.evaluate(()=>window.librarySelectionAbort.abort());
      await releaseReference(gate);assert.equal(await attaching,false);
      assert.equal(await baseId(),generatedIds[2]);assert.equal(await input.inputValue(),'Keep this complete image-edit draft.');
      const before=references().length;
      assert.equal(await page.evaluate(asset=>window.VP3ImageComposer.attachAsset(asset,
        {...window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext(),ownerKey:'2'}),candidate),false);
      assert.equal(references().length,before,'A mismatched owner must not reach the reference endpoint.');
    });
    await check('pending library attachment cannot cross Chat, owner, Agent, session or participant changes',async()=>{
      const before=await control(),beforeSends=sends().length;
      const candidate=before.assets.find(asset=>asset.id===generatedIds[3]);
      for(const change of ['chat','owner','agent','session','participant']){
        await boot({clear:true});await input.fill('Original private direction');
        referenceGate=createGate();const gate=referenceGate;
        const attaching=page.evaluate(asset=>window.VP3ImageComposer.attachAsset(asset,
          window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()),candidate);
        await reached(gate);
        if(change==='chat'){
          await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.openConversation(14));
          await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()===14&&window.STONEFELLOW_CHAT_CONTINUITY_V87.isReady());
        }else{
          await page.evaluate(change=>{
            if(change==='participant'){
              window.VP3ParticipantContinuity={selectedContext:()=>({participant_id:22})};
              window.dispatchEvent(new Event('vp3:participant-selection-changed'));
            }else{
              const key={owner:'userId',agent:'agentId',session:'workingAttentionBinding'}[change];
              window.STONEFELLOW_CHAT[key]={owner:2,agent:9,session:'new-private-session'}[change];
              window.dispatchEvent(new Event('stonefellow:chat-context-changing'));
            }
          },change);
          assert.equal(await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()),null);
        }
        const draft='Destination draft after '+change;await input.fill(draft);
        await releaseReference(gate);assert.equal(await attaching,false,change);
        assert.equal(await selected.count(),0,change);assert.equal(await input.inputValue(),draft,change);
        assert.equal(await page.evaluate(()=>window.VP3ImageComposer.requestContext(
          window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId())),null,change);
      }
      assert.equal(sends().length,beforeSends);
      const after=await control();assert.equal(after.assets.length,before.assets.length);assert.equal(after.jobs.length,before.jobs.length);
    });
    await check('/images opens the real shared popup and attaches its exact generated selection through the production reference endpoint',async()=>{
      await boot({clear:true,mediaCommands:true});
      const before=await control(),beforeSends=sends().length;
      await input.fill('/images');await input.press('Enter');
      const picker=page.locator('[data-media-picker]');await picker.waitFor();
      await picker.locator('[data-media-picker-search]').fill(before.assets.find(asset=>asset.id===generatedIds[2]).title);
      await picker.locator('[data-media-picker-asset="'+generatedIds[2]+'"]').click();
      assert.equal(await input.inputValue(),'');assert.equal(sends().length,beforeSends);
      await page.evaluate(()=>{const input=document.getElementById('chatInput');input.value='A vintage cap, keeping the original person.';input.dispatchEvent(new Event('input'));});
      for(const action of ['edit','marketing']){
        const position=await picker.locator('[data-media-picker-action="'+action+'"]').evaluate(element=>{
          const rect=element.getBoundingClientRect(),dialog=element.closest('dialog').getBoundingClientRect();
          return {top:rect.top,bottom:rect.bottom,left:rect.left,right:rect.right,width:innerWidth,height:innerHeight,dialogBottom:dialog.bottom};
        });
        assert.ok(position.top>=0&&position.bottom<=Math.min(position.height,position.dialogBottom)
          &&position.left>=0&&position.right<=position.width,'Both image actions must be visible in the desktop Chat dialog: '+action);
      }
      await capture('images-command-selected-desktop');
      await picker.locator('[data-media-picker-action="edit"]').click();
      await picker.waitFor({state:'detached'});await waitForBase(generatedIds[2]);
      assert.equal(references().at(-1).asset_id,generatedIds[2]);
      assert.equal(await input.inputValue(),'A vintage cap, keeping the original person.');
      assert.equal(sends().length,beforeSends);
      const after=await control();assert.equal(after.assets.length,before.assets.length);assert.equal(after.jobs.length,before.jobs.length);
    });
    await check('exact /marketing is a local command, and an unsuccessful open or changed private context preserves its text',async()=>{
      await boot({clear:true});
      await page.evaluate(()=>{window.commandCalls=[];window.commandResult=true;window.VP3MediaCommands={open:(kind,bound)=>{
        window.commandCalls.push({kind,owner:bound.ownerKey,agent:bound.agentId,cid:bound.conversationId,current:bound.isCurrent()});return window.commandResult;
      }};});
      const before=sends().length;
      await input.fill('  /MARKETING  ');await input.press('Enter');
      await page.waitForFunction(()=>window.commandCalls.length===1);
      assert.deepEqual(await page.evaluate(()=>window.commandCalls),[{kind:'marketing',owner:'1',agent:7,cid:11,current:true}]);
      assert.equal(await input.inputValue(),'');assert.equal(sends().length,before);
      await page.evaluate(()=>{window.commandResult=false;});await input.fill('/images');await input.press('Enter');
      await page.waitForFunction(()=>window.commandCalls.length===2);assert.equal(await input.inputValue(),'/images');
      await page.evaluate(()=>{window.VP3ParticipantContinuity={selectedContext:()=>({participant_id:22})};});
      await input.fill('/marketing');await input.press('Enter');
      assert.equal(await page.evaluate(()=>window.commandCalls.length),2);assert.equal(await input.inputValue(),'/marketing');assert.equal(sends().length,before);
      await page.evaluate(()=>{delete window.VP3ParticipantContinuity;});
      await send('Explain what the /images command does.');assert.equal(await page.evaluate(()=>window.commandCalls.length),2);
    });
    await check('double command submission initializes one Chat and preserves text typed before initialization finishes',async()=>{
      await boot({cid:0,clear:true});const before=await control(),beforeSends=sends().length;
      const beforeNew=calls.filter(call=>call.action==='new').length;
      await page.evaluate(()=>{window.commandCalls=[];window.VP3MediaCommands={open:(kind,bound)=>{
        window.commandCalls.push({kind,cid:bound.conversationId,current:bound.isCurrent()});return true;
      }};});
      assert.equal(await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()),null);
      newChatGate=createGate();const gate=newChatGate;
      await input.fill('/images');await input.press('Enter');await reached(gate);
      await input.press('Enter');assert.equal(calls.filter(call=>call.action==='new').length,beforeNew+1);
      await input.fill('New words typed while the Chat is being created');
      gate.release.resolve();newChatGate=null;await gate.completed.promise;
      await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.conversationId()>0&&window.STONEFELLOW_CHAT_CONTINUITY_V87.isReady());
      await page.evaluate(()=>Promise.resolve());
      assert.equal(await input.inputValue(),'New words typed while the Chat is being created');
      assert.deepEqual(await page.evaluate(()=>window.commandCalls),[]);assert.equal(sends().length,beforeSends);
      const cid=await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandContext()?.conversationId);assert.ok(cid>0);
      await input.fill('/images');await input.press('Enter');
      await page.waitForFunction(()=>window.commandCalls.length===1);
      assert.deepEqual(await page.evaluate(()=>window.commandCalls),[{kind:'images',cid,current:true}]);
      assert.equal(await input.inputValue(),'');assert.equal(calls.filter(call=>call.action==='new').length,beforeNew+1);
      assert.equal(sends().length,beforeSends);
      const after=await control();assert.equal(after.assets.length,before.assets.length);assert.equal(after.jobs.length,before.jobs.length);
    });
    await check('a marketing plan link survives completed Chat restoration before the deferred command module loads',async()=>{
      commandPlan={product_id:87,product_title:'Saved shirt listing',agent_id:7,conversation_id:11,
        stage:'listing_review',stage_label:'Review listing',image:null,
        resume:{owner_user_id:1,agent_id:7,conversation_id:11,product_id:87,stage:'listing_review'},
        chat_url:'/chat.php?agent=7&conversation_id=11&marketing_plan=87'};
      await boot({clear:true,query:'?agent=7&conversation_id=11&marketing_plan=87'});
      const before=sends().length,beforeReads=calls.filter(call=>call.action==='marketing_plan').length;
      await input.fill('Preserve my restored product direction');
      assert.deepEqual(await page.evaluate(()=>window.STONEFELLOW_CHAT_CONTINUITY_V87.mediaCommandIntent()),
        {productId:87,conversationId:11,agentId:7});
      await page.evaluate(()=>{
        const url=new URL(location.href);url.searchParams.delete('conversation_id');window.history.replaceState(null,'',url);
        window.VP3_INITIATIVE_WORKBENCH={endpoint:'/api/initiative-workbench-v1.php',mediaEndpoint:'/api/media-library-v86.php',csrf:'fixture',ownerKey:'1',agentId:7};
        window.resumedPlans=[];window.VP3InitiativeWorkbench={openProductLaunch:plan=>{window.resumedPlans.push(plan);return true;}};
      });
      await page.addStyleTag({url:base+'/chat-media-commands-v1.css'});
      await page.addScriptTag({url:base+'/chat-media-commands-v1.js'});
      await page.waitForFunction(()=>window.resumedPlans.length===1);
      assert.deepEqual(await page.evaluate(()=>window.resumedPlans),[commandPlan.resume]);
      assert.equal(calls.filter(call=>call.action==='marketing_plan').length,beforeReads+1);
      assert.equal(await input.inputValue(),'Preserve my restored product direction');assert.equal(sends().length,before);
      await page.evaluate(()=>{window.dispatchEvent(new Event('vp3:chat-context-ready'));window.dispatchEvent(new Event('vp3:chat-context-ready'));});
      assert.equal(calls.filter(call=>call.action==='marketing_plan').length,beforeReads+1);
      assert.equal(await page.evaluate(()=>new URL(location.href).searchParams.has('marketing_plan')),false);
      commandPlan=null;
    });
    assert.deepEqual(errors,[]);
    console.log('CHAT_IMAGE_COMPOSER_BROWSER=PASS ('+checks+' cases; real uploads, private files, scoped references, canonical queues and typed/voice Chat)');
  }
} catch (error) {
  console.error(logs.slice(-5000));throw error;
} finally {
  for(const gate of gates)gate.release.resolve();
  if(browser)await browser.close();
  if(server){server.kill();await new Promise(resolve=>server.exitCode!==null?resolve():server.once('exit',resolve));}
  fs.rmSync(tmp,{recursive:true,force:true});
}
