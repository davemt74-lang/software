import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
import {recoveryFixture} from './stem-recovery-fixture-v235.mjs';
const {chromium}=createRequire(import.meta.url)(process.env.PLAYWRIGHT_MODULE||'playwright');
const f=await recoveryFixture();let browser;
try{
 const assets=['admin/admin.css','admin/stems-v107.css','admin/stems-extra-v107.css','admin/stem-controls.css','admin/stem-editor.js','admin/stem-master-clock-v201.js','admin/stem-buffer-scheduler-v202.js','admin/stem-project-loader.js','admin/stem-media-loader-v235.js','admin/stem-media-health-v235.js','admin/stem-transport-v200.js','admin/stem-agent-v131.js','editor-agent.js','agent-orb-adapter.js','agent-orb.js','agent-orb.css','agent-orb-particles.css'];
 for(const asset of assets){fs.mkdirSync(path.dirname(path.join(f.root,asset)),{recursive:true});fs.copyFileSync(asset,path.join(f.root,asset));}
 const layout=execFileSync(process.env.PHP_BINARY||'php',['-r',`
 function e($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}function url($p){return $p;}function stem_format_duration($v){return '0:02';}
 $source=file_get_contents('admin/stems-legacy-v108.php');$a=strpos($source,'<div class="daw-studio" id="stemStudio">');$b=strpos($source,'  <div class="daw-modal" id="recordingSaveDialog"',$a);
 $maxDuration=1.296;$sourceTempo=170;$durationMeasures=0;$project=['project_name'=>'Recovery fixture','project_sample_rate'=>48000,'time_signature'=>'4/4'];
 $stems=json_decode($argv[1],true);eval('?>'.substr($source,$a,$b-$a).'</div>');
 `,JSON.stringify(f.stems)],{encoding:'utf8'}).replace(/(<audio\b[^>]*class="stem-audio"[^>]*)(>)/g,(_,attrs,end)=>attrs.replace(/preload="[^"]*"/g,'preload="none"').replace(/\bsrc=/g,'data-stem-media-source=')+end);
 const stems=f.stems.map(row=>({id:row.id,name:row.stem_name,role:row.stem_role,duration:row.duration_seconds,offset:0,volume:1,pan:0,channels:1,sampleRate:48000,sourceTempo:170,initialSourceStart:0,initialSourceEnd:row.duration_seconds,url:'/stem-media-v34.php?id='+row.id}));
 const cfg={userId:11,trackId:1,stems,duration:1.296,sourceTempo:170,projectTitle:'Recovery fixture',csrf:f.formData.csrf};
 const missing=f.stems.at(-1),missingPath=path.join(f.root,missing.file_path.replace(/^\//,''));fs.unlinkSync(missingPath);
 const html=`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${assets.filter(x=>x.endsWith('.css')).map(x=>`<link rel="stylesheet" href="/${x}">`).join('')}</head><body class="admin-canvas-mode"><main class="admin-content admin-content-canvas"><header class="daw-canvas-header"><div class="daw-canvas-actions"></div></header>${layout}</main>
 <script>window.STONEFELLOW_STEM_STUDIO=${JSON.stringify(cfg)};window.STONEFELLOW_STUDIO_AGENT={endpoint:'/studio-test.php',userId:11,trackId:1,agentId:7,conversationId:101,csrf:'fixture'};window.STONEFELLOW_AGENT_CONTEXT={surface:'stem'};
 window.calls=[];window.StonefellowEditorAgent={build:'fixture',hasSurface:()=>true};
 window.STONEFELLOW_NOTIFICATION_CENTER={};window.StonefellowPremiumVoiceV122={};
 window.VP3_ORB_CONFIG={ownerKey:'11',agentId:7,chatUrl:'/chat.php',voiceScript:'',drawerScript:''};
 </script>${['stem-master-clock-v201','stem-buffer-scheduler-v202','stem-transport-v200','stem-media-loader-v235','stem-project-loader','stem-editor','stem-media-health-v235','stem-agent-v131'].map(x=>`<script src="/admin/${x}.js"></script>`).join('')}
 <script src="/agent-orb-adapter.js"></script><script src="/agent-orb.js"></script></body></html>`;
 fs.writeFileSync(path.join(f.root,'fixture.html'),html);
 fs.writeFileSync(path.join(f.root,'studio-test.php'),`<?php header('Content-Type: application/json');$p=json_decode(file_get_contents('php://input'),true);echo json_encode(['ok'=>true,'session_id'=>1,'conversation_id'=>101,'history'=>[],'history_id'=>1,'assistant_message_id'=>55,'answer'=>'Your production is private.','commands'=>[]]);`);
 browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_EXECUTABLE_PATH,args:['--no-sandbox','--autoplay-policy=no-user-gesture-required']});
 const page=await browser.newPage({viewport:{width:1440,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 let transient=0,sourceCalls=0;
 await page.route('**/stem-media-v34.php?id=*',async route=>{sourceCalls++;if(route.request().url().endsWith('id='+f.stems[0].id)&&transient++===0)return route.fulfill({status:503,body:'temporary'});return route.continue();});
 await page.goto(f.base+'/fixture.html');
 await page.waitForFunction(()=>window.STONEFELLOW_STUDIO_RUNTIME_V87?.getStem&&window.StonefellowStemMediaHealthV235,{timeout:15000});
 await page.waitForFunction(id=>window.StonefellowStemMediaHealthV235.snapshot().some(x=>x.id===id&&['missing','not_found'].includes(x.reason)),missing.id,{timeout:15000});
 assert.equal(await page.evaluate(()=>window.StonefellowStemProjectLoaderV232.isPlaybackReady()),false,'missing required audio keeps the gate closed');
 assert.equal(await page.locator('.vp3-orb-bar').count(),0,'no permanent bottom composer');
 fs.writeFileSync(missingPath,f.mp3);
 await page.getByRole('button',{name:'Reload and retry'}).click();
 await page.waitForFunction(()=>window.StonefellowStemProjectLoaderV232.isPlaybackReady(),{timeout:15000});
 assert.equal(await page.evaluate(()=>window.StonefellowStemMediaHealthV235.snapshot().length),0);
 const pool=await page.evaluate(()=>window.StonefellowStemMediaV235.snapshot());assert.equal(pool.stems.length,19);assert.ok(pool.stems.every(x=>x.state==='ready'));assert.ok(pool.maxActive<=2);assert.ok(sourceCalls<=22,'source loading shares bytes and retries only failed sources');
 await page.waitForFunction(()=>!!window.VP3AgentOrb,{timeout:10000});
 await page.locator('.studio-agent-trigger').click();await page.locator('.studio-agent-orb-mounted').waitFor({state:'visible'});
 assert.equal(await page.locator('iframe').count(),0,'the editor mounts its one verified conversation');
 assert.equal(await page.locator('.studio-agent-panel').count(),1);
 await page.locator('.studio-agent-panel textarea').fill('What needs attention in this production?');
 const request=page.waitForRequest(r=>r.url().endsWith('/studio-test.php')&&r.postDataJSON()?.action==='send');
 await page.locator('.studio-agent-panel button[type=submit]').click();const posted=(await request).postDataJSON();assert.equal(posted.agent_id,7);assert.equal(posted.conversation_id,101);
 await page.locator('.editor-agent-line.assistant').filter({hasText:'Your production is private.'}).waitFor();
 await page.locator('.studio-agent-trigger').click();await page.locator('.studio-agent-orb-mounted').waitFor({state:'detached'});
 await page.evaluate(()=>window.VP3AgentOrb.open());await page.locator('.studio-agent-orb-mounted').waitFor({state:'visible'});
 assert.equal(await page.locator('.studio-agent-panel').count(),1);
 const fullLink=page.locator('#vp3AgentChatPopup a').first();assert.match(await fullLink.getAttribute('href'),/conversation_id=101/);
 await page.evaluate(()=>window.VP3AgentOrb.close());
 await page.locator('#stemPlayButton').click();
 await page.waitForFunction(()=>window.STONEFELLOW_STUDIO_RUNTIME_V87.isPlaying(),{timeout:10000});
 await page.waitForTimeout(200);await page.evaluate(()=>window.STONEFELLOW_STUDIO_RUNTIME_V87.pause());
 await page.waitForTimeout(300);assert.equal(await page.evaluate(()=>window.STONEFELLOW_STUDIO_RUNTIME_V87.isPlaying()),false,'Stop remains authoritative');
 if(process.env.STEM_RECOVERY_SCREENSHOT){await page.screenshot({path:process.env.STEM_RECOVERY_SCREENSHOT,fullPage:true});}
 await page.setViewportSize({width:390,height:844});await page.locator('.studio-agent-trigger').click();
 await page.locator('.studio-agent-orb-mounted').waitFor({state:'visible'});assert.equal(await page.locator('.vp3-orb-bar').count(),0);
 if(process.env.STEM_RECOVERY_SCREENSHOT)await page.screenshot({path:process.env.STEM_RECOVERY_SCREENSHOT.replace('.png','-mobile.png'),fullPage:true});
 assert.deepEqual(errors,[],'canonical editor and one Agent UI have no uncaught browser errors');
 console.log('STEM_RECOVERY_BROWSER_V235=PASS 19 real imported MP3s, 503 recovery, missing/retry gate, shared loading, playback/Stop, desktop/mobile single-Agent UI');
}finally{await browser?.close();f.close();}
