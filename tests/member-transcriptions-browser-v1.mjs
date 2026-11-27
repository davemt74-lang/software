import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(new URL('..',import.meta.url).pathname);
const render=page=>execFileSync(process.env.PHP_BINARY||'php',['-r',`function url($p){return '/vp3'.$p;}function e($v){return htmlspecialchars($v,ENT_QUOTES);}function has_permission($p,$u){return true;}function csrf_token(){return 'fixture-csrf';}function vp3_catch_up_page_binding_v1($u){return 'fixture-owner-1';}require ${JSON.stringify(root+'/includes/phone-workspace-ui-v1.php')};require ${JSON.stringify(root+'/includes/member-transcriptions-v1.php')};$_SERVER['SCRIPT_NAME']=${JSON.stringify(page)};echo vp3_member_transcriptions_runtime_v1(['id'=>1,'role'=>'admin']);`],{encoding:'utf8'});
const markup=render('/vp3/phone-tracking.php');assert.ok(markup.includes('chat-transcription-canvas.js'));assert.equal(render('/vp3/admin/phone.php'),'','Admin receives no public transcription interface');
const calls=[];let denied=false;
const server=http.createServer((req,res)=>{
 const u=new URL(req.url,'http://fixture');
 if(u.pathname.startsWith('/vp3/api/')){
  calls.push({method:req.method,path:u.pathname,action:u.searchParams.get('action')});assert.equal(req.headers['x-vp3-working-attention'],'fixture-owner-1');res.setHeader('Content-Type','application/json');
  if(denied){res.statusCode=403;return res.end('{"ok":false,"error":"Your account changed. Refresh this page."}');}
  return res.end(JSON.stringify(u.pathname.includes('intelligence')?{ok:true,master:null,registry:[],app_status:{}}:
   u.pathname.includes('recordings')?{ok:true,library:[]}:{ok:true,sessions:[{id:21,title:'Saved call transcript',status:'draft'},{id:22,title:'Second saved transcript',status:'draft'}]}));
 }
 const name=path.basename(u.pathname);if(['chat-transcription-canvas.js','chat-transcription-canvas.css','artist-listening-recordings.js'].includes(name)){
  res.setHeader('Content-Type',name.endsWith('.css')?'text/css':'text/javascript');return res.end(fs.readFileSync(path.join(root,name)));
 }
 res.setHeader('Content-Type','text/html');res.end('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font:14px system-ui}.chat-topbar-actions{display:flex;justify-content:flex-end;padding:12px}</style><header class="chat-topbar-actions"></header><main><h1>Connected phones</h1></main>'+markup);
});await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
let checks=0;
try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:'+server.address().port+'/vp3/phone-tracking.php');
 for(const width of [1280,390,320]){
  await page.setViewportSize({width,height:800});await page.getByRole('button',{name:'Open Transcription Intelligence',exact:true}).click();
  await page.locator('#chatTranscriptionCanvas [data-transcription-session-select]').waitFor();
  await page.waitForFunction(()=>window.STONEFELLOW_TRANSCRIPTION_CANVAS_V308.intelligenceLoads>0);
  await page.waitForFunction(()=>{const r=document.getElementById('chatTranscriptionCanvas').getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1;});
  const box=await page.locator('#chatTranscriptionCanvas').boundingBox();assert.ok(box.x>=-1&&box.x+box.width<=width+1);
  assert.equal(await page.locator('[data-transcription-session-select]').inputValue(),'21');
  assert.equal(await page.evaluate(()=>typeof STONEFELLOW_CHAT),'undefined');assert.equal(await page.locator('#chatForm,#chatThread').count(),0);
  if(process.env.VP3_SHELL_SCREENSHOT_DIR){fs.mkdirSync(process.env.VP3_SHELL_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.VP3_SHELL_SCREENSHOT_DIR,'member-transcriptions-'+width+'.png')});}
  await page.keyboard.press('Escape');await page.locator('#chatTranscriptionCanvas').waitFor({state:'hidden'});checks++;console.log('PASS native transcription slideout opens without Chat at '+width+'px');
 }
 assert.ok(calls.length>0&&calls.every(c=>c.method==='GET'),'library viewing never sends Chat messages, creates conversations or starts analysis');
 assert.ok(calls.every(c=>['bootstrap','library','status'].includes(c.action)));
 denied=true;await page.getByRole('button',{name:'Open Transcription Intelligence',exact:true}).click();await page.getByText('Your account changed. Refresh this page.',{exact:true}).waitFor();
 assert.deepEqual(errors,[]);checks++;console.log('PASS account-bound native reads fail closed after a session change');
 console.log('MEMBER_TRANSCRIPTIONS_BROWSER_V1=PASS ('+checks+' cases)');
}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
