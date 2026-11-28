import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
import {deflateSync} from 'node:zlib';
import {chatChoice} from './fixtures/profile-chat-choice-pa3d.mjs';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const read=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8');
const profile=read('profile.php'),namespace='a'.repeat(64);
const widgetSource=profile.match(/<div class="profile-agent-widget-root"[\s\S]*?(?=\n<\?php endif; \?>\n<script>)/)?.[0];
const launcher=profile.match(/const profileAgentShell=.*?(?=const webmcpResumeBox=)/s)?.[0];
assert.ok(widgetSource&&launcher,'fixture renders the production public Profile widget');
const renderWidget=ownerPreview=>execFileSync('php',['-r',`
 $agent=['display_name'=>'Profile Guide'];$displayName='Dave';$profileOrbShape='orbit';$agentGreeting='Welcome. Ask a question or try a free photo edit.';$isOwner=$chatPreview=${ownerPreview?'true':'false'};
 function e($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}function url($v){return $v;}eval('?>'.stream_get_contents(STDIN));
`],{input:widgetSource,encoding:'utf8'});
const visitorWidget=renderWidget(false),ownerWidget=renderWidget(true);
const crc=bytes=>{let value=0xffffffff;for(const byte of bytes){value^=byte;for(let bit=0;bit<8;bit++)value=(value>>>1)^((value&1)?0xedb88320:0);}return (value^0xffffffff)>>>0;};
const chunk=(kind,bytes)=>{const label=Buffer.from(kind),length=Buffer.alloc(4),checksum=Buffer.alloc(4);length.writeUInt32BE(bytes.length);checksum.writeUInt32BE(crc(Buffer.concat([label,bytes])));return Buffer.concat([length,label,bytes,checksum]);};
const width=360,height=360,header=Buffer.alloc(13);header.writeUInt32BE(width,0);header.writeUInt32BE(height,4);header[8]=8;header[9]=2;
const raster=Buffer.alloc(height*(1+width*3));
for(let y=0;y<height;y++)for(let x=0;x<width;x++){const at=y*(1+width*3)+1+x*3,face=((x-180)/66)**2+((y-151)/88)**2<1,shoulders=((x-180)/125)**2+((y-335)/110)**2<1;raster[at]=face?211:shoulders?55:184+Math.floor(y/10);raster[at+1]=face?163:shoulders?66:125+Math.floor(x/12);raster[at+2]=face?124:shoulders?70:93+Math.floor(y/15);}
const photo=Buffer.concat([Buffer.from('89504e470d0a1a0a','hex'),chunk('IHDR',header),chunk('IDAT',deflateSync(raster)),chunk('IEND',Buffer.alloc(0))]);
const assets=new Map(['profile.css','profile-agent.js','profile-image-edits-v1.js'].map(name=>['/'+name,read(name)]));
let imageResponse=()=>null,referenceResponse=()=>false;
const server=http.createServer((request,response)=>{
 const url=new URL(request.url,'http://localhost');
 if(assets.has(url.pathname)){response.writeHead(200,{'Content-Type':url.pathname.endsWith('.css')?'text/css':'text/javascript'});response.end(assets.get(url.pathname));return;}
 // Chromium's download-attribute requests can bypass page routing. Serve the
 // real image URL so the download assertion checks bytes, never fallback HTML.
 if(url.pathname==='/api/profile-agent.php'&&url.searchParams.get('action')==='image'){
  const file=imageResponse(url.searchParams);
  if(!file){response.writeHead(404);response.end('Image not found.');return;}
  const preview=url.searchParams.get('preview')==='1';
  response.writeHead(200,{'Content-Type':preview?'image/png':'application/octet-stream','Content-Length':photo.length,
    'Content-Disposition':`${preview?'inline':'attachment'}; filename="photo.png"; filename*=UTF-8''${encodeURIComponent(file.filename)}`,
    'Cache-Control':'private, no-store','X-Content-Type-Options':'nosniff'});response.end(photo);return;
 }
 if(url.pathname==='/api/profile-agent.php'&&url.searchParams.get('action')==='reference_image'){
  if(!referenceResponse(url.searchParams)){response.writeHead(404);response.end('Reference image not found.');return;}
  response.writeHead(200,{'Content-Type':'image/png','Content-Length':photo.length,'Cache-Control':'private, no-store','X-Content-Type-Options':'nosniff'});response.end(photo);return;
 }
 const widget=url.pathname==='/owner-preview'?ownerWidget:visitorWidget;
 const config={username:'fixture-owner',ownerName:'Dave',agentName:'Profile Guide',greeting:'',endpoint:'/api/profile-agent.php',imageSettingsUrl:'/profile-agent.php?tab=agent',profileToken:'FIXTURE_PROFILE_TOKEN',conversationNamespace:namespace};
 response.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});
 response.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/profile.css"></head><body class="profile-page"><main><h1>Dave’s photo catalog</h1></main>${widget}<script>window.STONEFELLOW_PROFILE_AGENT=${JSON.stringify(config)};${launcher}</script><script src="/profile-image-edits-v1.js"></script><script src="/profile-agent.js"></script></body></html>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const evidence='artifacts/profile-image-edits';fs.mkdirSync(evidence,{recursive:true});
let browser,checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
async function fixture({viewport={width:390,height:780},loseAfterCommit=false,loseBeforeCommit=false,paid=false,ownerPreview=false,empty=false,disabled=false,collectionUnavailable=false}={}){
 const context=await browser.newContext({viewport,acceptDownloads:true}),page=await context.newPage();page.setDefaultTimeout(10000);
 const requests=[],messages=[],jobs=[],records=new Map(),errors=[];let messageId=100,turnId=500,changed=false,collectionGate=null;
 const state={conversation:null,handoff:null,chat:chatChoice({owner_name:'Dave',live_text_available:false}),providerAttempts:0,allowanceReady:true,remaining:null};
 const referenceUrl=id=>'/api/profile-agent.php?'+new URLSearchParams({action:'reference_image',username:'fixture-owner',catalog_id:'7',image_id:String(id),catalog_revision:'3'});
 const catalogs=[{collection_id:7,name:'Warm portrait style',catalog_revision:3,price_cents:0,references:[{asset_id:12,title:'Amber portrait',price_cents:paid?100:0,preview_url:referenceUrl(12)},{asset_id:14,title:'Soft window light',price_cents:0,preview_url:referenceUrl(14)}]}];
 const availability=()=>({contract:'vp3.profile-image-edit.v1',enabled:!empty&&!disabled,max_input_bytes:4194304,catalogs:empty||disabled?[]:catalogs,daily_limit:10,visitor_daily_limit:3,
   allowance:{contract:'vp3.profile-image-allowance.v1',ready:state.allowanceReady,remaining:state.allowanceReady?(state.remaining??Math.max(0,3-records.size)):null,resets_at_utc_ms:state.allowanceReady?(Math.floor(Date.now()/86400000)+1)*86400000:null},
   ...(disabled?{reason:'Free photo editing is not enabled on this profile.'}:empty?{reason:'No approved free images are available in this collection yet.'}:{})});
 const url=(id,preview)=>'/api/profile-agent.php?'+new URLSearchParams({action:'image',username:'fixture-owner',conversation_namespace:namespace,conversation_id:'31',message_id:String(id),...(preview?{preview:'1'}:{})});
 const image=(kind,id,job)=>({kind,request_id:job.request_id,source_message_id:job.input_message_id,root_message_id:job.root_message_id,filename:kind==='result'?'edited-photo.png':'portrait.png',mime:'image/png',bytes:photo.length,url:url(id,true),download_url:url(id,false),
   ...(kind==='result'?{source_credit:{contract:'vp3.profile-image-credit.v1',owner_name:'Dave',catalog_name:'Warm portrait style',references:catalogs[0].references.filter(reference=>!job.image_id||reference.asset_id===job.image_id).map(reference=>({title:reference.title})),price_cents:0}}:{})});
 imageResponse=params=>!changed&&params.get('username')==='fixture-owner'&&params.get('conversation_namespace')===namespace&&params.get('conversation_id')==='31'
   ?messages.find(item=>item.id===Number(params.get('message_id')))?.image_edit:null;
 referenceResponse=params=>!changed&&!empty&&!disabled&&!paid&&params.get('username')==='fixture-owner'&&params.get('catalog_id')==='7'&&params.get('catalog_revision')==='3'&&['12','14'].includes(params.get('image_id'));
 const snapshot=(after=0)=>({ok:true,agent:{name:'Profile Guide',greeting:''},conversation:state.conversation,chat:state.chat,handoff:state.handoff&&{...state.handoff,server_now_ms:Date.now()},messages:messages.filter(item=>item.id>Number(after)),attachment_max_bytes:2097152,
   image_editing:availability(),image_edits:jobs});
 page.on('pageerror',error=>errors.push(error.message));
 await page.route('**/api/profile-agent.php*',async route=>{
  const request=route.request();let body,file=null;
  if((request.headers()['content-type']||'').startsWith('multipart/form-data')){
   const form=await new Request(request.url(),{method:'POST',headers:{'Content-Type':request.headers()['content-type']},body:request.postDataBuffer()}).formData();
   body=JSON.parse(form.get('image_edit_payload')||form.get('attachment_payload'));file=form.get('profile_image')||form.get('profile_attachment');
  }else body=request.method()==='GET'?Object.fromEntries(new URL(request.url()).searchParams):request.postDataJSON();
  requests.push({body,method:request.method(),file:file?{name:file.name,size:file.size,mime:file.type}:null});
  const reply=(value,status=200)=>route.fulfill({status,json:{ok:status<400,...value}});
  if(changed)return reply({code:'PROFILE_SESSION_CHANGED',error:'Visitor session changed.'},409);
  if(body.action==='image'||body.action==='reference_image')return route.continue();
  if(body.action==='image_collection'){
   const value={image_editing:availability()},gate=collectionGate;
   if(gate){gate.started();await gate.wait;collectionGate=null;}
   const response=collectionUnavailable?reply({error:'Collection unavailable.'},503):reply(value);
   if(gate)await response.finally(gate.finished);else await response;return;
  }
  if(body.action==='state'||body.action==='poll')return reply(snapshot(body.after_id||0));
  if(body.action==='image_edit_receipt'){
   const receipt=records.get(body.client_request_id);
   return reply(receipt?{...snapshot(),...receipt,found:true,duplicate:true}:{found:false,client_request_id:body.client_request_id});
  }
  if(body.action==='image_edit'){
   if(loseBeforeCommit){loseBeforeCommit=false;return route.abort('failed');}
   let receipt=records.get(body.client_request_id);
   if(!receipt){
    const input=++messageId,job={request_id:(jobs.length+1).toString(16).padStart(32,'0'),input_message_id:input,result_message_id:0,root_message_id:body.base_message_id?jobs.find(item=>item.result_message_id===body.base_message_id)?.root_message_id:input,
      base_message_id:body.base_message_id||0,catalog_id:body.catalog_id,image_id:body.image_id||0,state:'queued',started_at_ms:null,active_lease_expires_at:null,error:'',created_at:new Date().toISOString()};
    jobs.push(job);state.conversation={conversation_id:31,status:'open'};state.handoff={turn_id:++turnId,revision:1,conversation_id:31,state:'agent_ready',dispatch_turn_id:turnId,dispatch_state:'agent_ready'};
    const inputImage=image('input',input,job);messages.push({id:input,sender_type:'visitor',message:body.message,image_edit:inputImage});
    receipt={accepted:true,conversation_id:31,visitor_message_id:input,client_request_id:body.client_request_id,image_edit:inputImage};records.set(body.client_request_id,receipt);
   }
   if(loseAfterCommit){loseAfterCommit=false;return route.abort('failed');}
   return reply({...snapshot(),...receipt});
  }
  if(body.action==='dispatch'){
   const job=jobs.at(-1);if(job?.state==='queued'){job.state='editing';job.started_at_ms=Date.now();job.active_lease_expires_at=Date.now()+120000;state.providerAttempts++;state.handoff={...state.handoff,state:'agent_running',dispatch_state:'agent_running',revision:state.handoff.revision+1};}
   return reply({conversation_id:31,turn_id:body.turn_id,dispatch_checked:true});
  }
  return reply({error:'Unexpected fixture action '+body.action},422);
 });
 const launch=()=>page.getByRole('button',{name:'Ask Profile Guide'}).click();
 await page.goto(`http://127.0.0.1:${server.address().port}/${ownerPreview?'owner-preview':'fixture-owner'}`);await launch();
 await page.waitForFunction(expected=>document.querySelector('[data-profile-agent-presence]')?.textContent===expected,ownerPreview?'Owner preview':'Dave unavailable for live chat');
 const refresh=async()=>{await page.evaluate(()=>window.dispatchEvent(new Event('vp3:profile-agent-open')));};
 return {page,requests,records,jobs,state,launch,refresh,
  async browse(){const read=page.waitForResponse(response=>new URL(response.url()).searchParams.get('action')==='image_collection');await page.getByRole('button',{name:'Image collection',exact:true}).click();await read;await page.waitForFunction(()=>document.querySelector('[data-profile-image-collection-state]')?.textContent!=='Loading image collection…');},
  async choose(reference=0){await this.browse();if(reference)await page.locator(`[data-profile-image-reference="${reference}"]`).click();
    const waiting=page.waitForEvent('filechooser');await page.getByRole('button',{name:'Choose photo',exact:true}).click();await(await waiting).setFiles({name:'portrait.png',mimeType:'image/png',buffer:photo});await page.locator('[data-profile-image-draft]').waitFor();},
  deferCollection(){let started,release,finished;const began=new Promise(resolve=>{started=resolve;}),wait=new Promise(resolve=>{release=resolve;}),done=new Promise(resolve=>{finished=resolve;});collectionGate={started,wait,finished};return {began,release,done};},
  async send(prompt='Match this style and soften the lighting.'){await page.getByRole('button',{name:'Use photo in chat',exact:true}).click();await page.getByRole('textbox',{name:'Message Profile Agent'}).fill(prompt);await page.getByRole('button',{name:'Edit photo',exact:true}).click();},
  async complete(){const job=jobs.at(-1);job.state='ready';job.result_message_id=++messageId;job.active_lease_expires_at=null;messages.push({id:job.result_message_id,sender_type:'agent',message:'Your edited photo is ready.',image_edit:image('result',job.result_message_id,job)});state.handoff={...state.handoff,state:'replied',dispatch_state:'none',revision:state.handoff.revision+1};await refresh();await page.locator(`[data-profile-image-result="${job.result_message_id}"]`).waitFor();return job;},
  async reload(){await page.reload();await launch();},
  changeSession(){changed=true;},
  async close(){check(!errors.length,'public image widget has no browser script errors'+(errors.length?': '+errors.join('; '):''));await context.close();}
 };
}
try{
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
 {
  const f=await fixture();await f.browse();await f.page.locator('[data-profile-image-reference="14"]').click();
  check((await f.page.locator('[data-profile-image-allowance]').innerText()).startsWith('3 free image renders left today.'),'Image collection displays the authoritative visitor render balance');
  await f.page.waitForFunction(()=>[...document.querySelectorAll('[data-profile-image-collection] img')].length===2&&[...document.querySelectorAll('[data-profile-image-collection] img')].every(image=>image.complete&&image.naturalWidth>0));
  check(!f.requests.some(item=>item.method==='POST')&&await f.page.locator('[data-profile-image-draft]').isHidden(),'visitors can browse successful free image thumbnails and select one before uploading without creating a conversation or edit');
  check(await f.page.getByRole('button',{name:'Image collection',exact:true}).getAttribute('aria-pressed')==='true'&&await f.page.locator('[data-profile-image-reference="14"]').getAttribute('aria-pressed')==='true','persistent collection navigation and the chosen image show their selected states');
  await f.page.locator('#profileAgentShell').screenshot({path:evidence+'/collection-mobile.png'});
  await f.choose(14);
  check(!f.requests.some(item=>item.method==='POST'),'choosing a subject and free style sends no generation request');
  await f.page.getByRole('button',{name:'Chat',exact:true}).click();await f.browse();
  check(await f.page.locator('[data-profile-image-reference="14"]').getAttribute('aria-pressed')==='true'&&await f.page.locator('[data-profile-image-draft]').textContent().then(text=>text.includes('portrait.png')),'Chat and Image collection preserve the uploaded photo and exact visual choice');
  check(await f.page.locator('[data-profile-image-panel]').evaluate(panel=>{
   const scrollables=[panel,...panel.querySelectorAll('*')].filter(element=>['auto','scroll'].includes(getComputedStyle(element).overflowY)&&element.scrollHeight>element.clientHeight+1);
   return scrollables.length===1&&scrollables[0]===panel&&panel.scrollWidth<=panel.clientWidth+1&&[...panel.querySelectorAll('[data-profile-image-reference] img')].every(image=>getComputedStyle(image).objectFit==='contain');
  }),'the mobile collection keeps full images within one scrolling conversation canvas');
  await f.page.locator('[data-profile-image-reference="14"]').scrollIntoViewIfNeeded();
  await f.page.locator('#profileAgentShell').screenshot({path:evidence+'/choose-style-mobile.png'});
  await f.send();await f.page.locator('[data-profile-image-job][data-active="true"]').waitFor();
  check((await f.page.locator('[data-profile-image-allowance]').textContent()).startsWith('2 free image renders left today.'),'confirmed admission refreshes the balance while the render is still working');
  const submitted=f.requests.find(item=>item.body.action==='image_edit');
  check(submitted.body.catalog_id===7&&submitted.body.image_id===14&&submitted.body.catalog_revision===3&&submitted.file?.size===photo.length&&submitted.file.name==='portrait.png','offline visitor edit submits exact catalog, image, prompt and subject through the separate AI upload action');
  check(!('image_subject'in submitted.body)&&f.state.providerAttempts===1,'local recovery metadata stays out of the public action and only one provider attempt starts');
  await f.page.locator('#profileAgentShell').screenshot({path:evidence+'/editing-mobile.png'});
  const job=await f.complete();
  check((await f.page.locator('[data-profile-image-allowance]').textContent()).startsWith('2 free image renders left today.'),'render completion does not deduct the same accepted request again');
  const cardCredit=f.page.locator(`[data-message-id="${job.result_message_id}"] [data-profile-image-source-credit]`);
  check((await cardCredit.textContent()).includes('Profile owner: Dave')&&(await cardCredit.textContent()).includes('Collection: Warm portrait style')&&(await cardCredit.locator('li').allTextContents()).join('|')==='Soft window light'&&(await cardCredit.textContent()).includes('Free use · $0.00'),'completed result card credits the saved owner, catalog and exact selected reference at zero price');
  await f.page.locator(`[data-profile-image-result="${job.result_message_id}"]`).click();
  check(await f.page.locator('[data-profile-image-viewer]').isVisible()&&!await f.page.getByRole('log').isVisible(),'result viewer shares the conversation canvas');
  const viewerCredit=f.page.locator('[data-profile-image-viewer] [data-profile-image-source-credit]');
  check(await viewerCredit.textContent()===await cardCredit.textContent(),'mobile result viewer retains exactly the credit shown on its saved result card');
  await viewerCredit.scrollIntoViewIfNeeded();
  check(await f.page.locator('[data-profile-image-viewer]').evaluate(panel=>panel.scrollWidth<=panel.clientWidth+1),'source credit wraps within the mobile conversation canvas');
  await f.page.locator('#profileAgentShell').screenshot({path:evidence+'/result-mobile.png'});
  const downloaded=f.page.waitForEvent('download');await f.page.getByRole('link',{name:'Download photo',exact:true}).click();const download=await downloaded;
  check(download.suggestedFilename()==='edited-photo.png','download keeps the saved image filename (received '+download.suggestedFilename()+')');
  const downloadedBytes=fs.readFileSync(await download.path());
  check(downloadedBytes.equals(photo),'download returns the actual saved image bytes ('+downloadedBytes.length+' received; '+photo.length+' expected)');
  await f.page.getByRole('button',{name:'Edit this result',exact:true}).click();await f.send('Keep the subject and make the lighting cooler.');
  await f.page.locator('[data-profile-image-job][data-active="true"]').waitFor();const revision=f.requests.filter(item=>item.body.action==='image_edit').at(-1);
  check((await f.page.locator('[data-profile-image-allowance]').textContent()).startsWith('1 free image render left today.'),'a distinct accepted revision refreshes the remaining allowance');
  check(revision.body.base_message_id===job.result_message_id&&revision.body.image_id===14&&revision.file===null,'a conversational revision reuses the exact saved result and style without uploading another subject');
  await f.complete();f.changeSession();await f.refresh();await f.page.getByRole('textbox',{name:'Message Profile Agent'}).waitFor({state:'visible'});
  await f.page.waitForFunction(()=>document.querySelector('.profile-agent-compose textarea').disabled);
  check(await f.page.locator('[data-profile-image-result]').count()===0,'visitor session change clears generated previews and old image authority');await f.close();
 }
 {
  const f=await fixture({viewport:{width:1280,height:900},loseAfterCommit:true});await f.choose();await f.send('Match the catalog style.');
  await f.page.getByRole('button',{name:'Retry photo edit',exact:true}).waitFor();
  const before=f.requests.filter(item=>item.body.action==='image_edit'),original=before[0].body.client_request_id;
  const saved=await f.page.evaluate(()=>JSON.parse(sessionStorage.getItem(Object.keys(sessionStorage).find(key=>key.endsWith(':pending')))||'null'));
  check(saved.client_request_id===original&&/^[a-f0-9]{64}$/.test(saved.image_subject.sha256)&&!saved.upload,'lost upload acknowledgement preserves a frozen retry key and subject hash across reload');
  await f.reload();await f.page.locator('[data-profile-image-job]').waitFor();
  check((await f.page.locator('[data-profile-image-allowance]').textContent()).startsWith('2 free image renders left today.'),'lost-response receipt recovery shows one admitted render without a local double deduction');
  check(f.requests.some(item=>item.body.action==='image_edit_receipt'&&item.body.client_request_id===original)&&f.requests.filter(item=>item.body.action==='image_edit').length===1&&f.records.size===1,'reload recovers the committed first upload through a read-only receipt instead of generating again');
  await f.page.locator('#profileAgentShell').screenshot({path:evidence+'/recovered-edit-desktop.png'});await f.complete();await f.close();
 }
 {
  const f=await fixture({loseBeforeCommit:true});await f.choose();await f.send('Use the soft style.');await f.page.getByRole('button',{name:'Retry photo edit',exact:true}).waitFor();
  const original=f.requests.find(item=>item.body.action==='image_edit').body;await f.reload();const reattach=f.page.getByRole('button',{name:'Choose original photo to retry',exact:true});await reattach.waitFor();
  const different=Buffer.from(photo);different[different.length-1]^=1;
  let chosen=f.page.waitForEvent('filechooser');await reattach.click();await(await chosen).setFiles({name:'portrait.png',mimeType:'image/png',buffer:different});
  await f.page.getByText('This is a different photo. Choose the same original photo to retry the saved edit.',{exact:true}).waitFor();
  check(f.requests.filter(item=>item.body.action==='image_edit').length===1,'reattachment with a different content hash cannot change the saved edit');
  chosen=f.page.waitForEvent('filechooser');await reattach.click();await(await chosen).setFiles({name:'portrait.png',mimeType:'image/png',buffer:photo});await f.page.locator('[data-profile-image-job]').waitFor();
  const retry=f.requests.filter(item=>item.body.action==='image_edit').at(-1).body;
  check(retry.client_request_id===original.client_request_id&&retry.message===original.message&&retry.catalog_id===original.catalog_id&&f.records.size===1,'same-file recovery retries the original request ID, prompt and catalog');await f.complete();await f.close();
 }
 {
  const f=await fixture();f.state.remaining=0;await f.browse();
  check((await f.page.locator('[data-profile-image-allowance]').innerText()).startsWith('0 free image renders left today.')&&await f.page.locator('[data-profile-image-allowance]').getAttribute('data-profile-image-allowance')==='ready','zero remaining is displayed as an authoritative balance');
  f.state.allowanceReady=false;await f.page.getByRole('button',{name:'Chat',exact:true}).click();await f.browse();
  check((await f.page.locator('[data-profile-image-allowance]').innerText()).includes('could not be checked')&&!(await f.page.locator('[data-profile-image-allowance]').innerText()).includes('0 free'),'unavailable allowance never masquerades as zero remaining');
  f.state.allowanceReady=true;f.state.remaining=2;await f.refresh();
  await f.page.waitForFunction(()=>document.querySelector('[data-profile-image-allowance]')?.textContent.startsWith('2 free image renders'));
  check(await f.page.locator('[data-profile-image-allowance]').getAttribute('data-profile-image-allowance')==='ready','state refresh restores a verified balance while the image collection stays open');
  await f.close();
 }
 {
  const f=await fixture({paid:true});await f.page.getByRole('button',{name:'Add image',exact:true}).click();
  check(await f.page.locator('[data-profile-attachment-help] [data-profile-image-edit-open]').isHidden()&&!f.requests.some(item=>item.body.action==='image_edit'),'a nonzero-priced style is not offered by the free-only widget');await f.browse();
  check(await f.page.locator('[data-profile-image-reference]').count()===0&&await f.page.getByRole('button',{name:'Choose photo',exact:true}).isDisabled(),'a catalog containing a priced reference cannot offer collection choices or uploads');await f.close();
 }
 for(const viewport of [{width:1280,height:900},{width:390,height:780}]){
  const f=await fixture({ownerPreview:true,viewport});
  check(f.requests.length===0,'opening the owner preview starts no visitor session or collection request');
  await f.browse();await f.page.locator('[data-profile-image-reference="12"]').scrollIntoViewIfNeeded();
  await f.page.waitForFunction(()=>[...document.querySelectorAll('[data-profile-image-collection] img')].length===2&&[...document.querySelectorAll('[data-profile-image-collection] img')].every(image=>image.complete&&image.naturalWidth>0));
  await f.page.locator('[data-profile-image-reference="14"]').click();
  check(await f.page.locator('[data-profile-image-reference="14"]').getAttribute('aria-pressed')==='true'&&await f.page.getByRole('button',{name:'Choose photo',exact:true}).isHidden()&&await f.page.getByRole('button',{name:'Use photo in chat',exact:true}).isHidden()&&await f.page.getByRole('textbox',{name:'Describe an image to generate privately'}).isEnabled()&&await f.page.locator('form[data-profile-chat-preview] button[type="submit"]').isDisabled(),'owner preview shows selectable public thumbnails and editable private image drafts while visitor uploading and sending stay disabled');
  check(await f.page.getByRole('link',{name:'Manage image collection',exact:true}).getAttribute('href')===`http://127.0.0.1:${server.address().port}/profile-agent.php?tab=agent`&&f.requests.filter(item=>item.body.action==='image_collection').length===1&&f.requests.filter(item=>item.body.action==='image_collection').every(item=>Object.keys(item.body).sort().join(',')==='action,username')&&f.requests.every(item=>item.method==='GET'&&['image_collection','reference_image'].includes(item.body.action)),'owner browsing uses only public collection and image reads and links to image settings');
  await f.page.locator('#profileAgentShell').screenshot({path:evidence+`/collection-owner-${viewport.width<600?'mobile':'desktop'}.png`});
  await f.page.getByRole('button',{name:'Chat',exact:true}).click();
  check(await f.page.getByRole('log').isVisible()&&await f.page.getByRole('button',{name:'Chat',exact:true}).getAttribute('aria-pressed')==='true','owner Chat navigation restores the greeting after browsing');await f.close();
 }
 for(const [ownerPreview,disabled] of [[false,false],[false,true],[true,false],[true,true]]){
  const f=await fixture({ownerPreview,empty:!disabled,disabled});
  if(!ownerPreview)await f.page.getByRole('textbox',{name:'Message Profile Agent'}).fill('Keep this chat draft.');
  await f.browse();const message=await f.page.locator('[data-profile-image-collection-state]').textContent();
  check(await f.page.getByRole('button',{name:'Image collection',exact:true}).isVisible()&&await f.page.locator('[data-profile-image-reference]').count()===0&&(ownerPreview?message.includes('approve images priced at 0.00'):message.includes(disabled?'not enabled':'No approved free images')),'empty or disabled '+(ownerPreview?'owner':'visitor')+' collections stay visible and explain the next step');
  check(!f.requests.some(item=>item.method==='POST')&&(ownerPreview?await f.page.getByRole('link',{name:'Manage image collection',exact:true}).isVisible():await f.page.getByRole('button',{name:'Choose photo',exact:true}).isDisabled()),'unavailable collections offer settings guidance or disabled upload without a generation request');
  if(!ownerPreview&&!disabled)await f.page.locator('#profileAgentShell').screenshot({path:evidence+'/collection-empty-mobile.png'});
  await f.page.getByRole('button',{name:'Chat',exact:true}).click();
  if(!ownerPreview)check(await f.page.getByRole('textbox',{name:'Message Profile Agent'}).inputValue()==='Keep this chat draft.','browsing an unavailable collection preserves an ordinary chat draft');await f.close();
 }
 {
  const f=await fixture({ownerPreview:true,collectionUnavailable:true});await f.browse();
  check((await f.page.locator('[data-profile-image-collection-state]').textContent()).includes('could not be loaded')&&await f.page.getByRole('button',{name:'Image collection',exact:true}).isVisible()&&!f.requests.some(item=>item.method==='POST'),'a failed collection read shows a visible retry explanation without starting a visitor conversation');await f.close();
 }
 {
  const f=await fixture({collectionUnavailable:true}),pending=f.deferCollection();
  await f.page.getByRole('button',{name:'Image collection',exact:true}).click();await pending.began;
  f.state.remaining=2;await f.refresh();await f.page.waitForFunction(()=>document.querySelector('[data-profile-image-allowance]')?.textContent.startsWith('2 free image renders'));
  pending.release();await pending.done;await f.page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  check(await f.page.locator('[data-profile-image-allowance]').getAttribute('data-profile-image-allowance')==='ready'&&(await f.page.locator('[data-profile-image-allowance]').innerText()).startsWith('2 free image renders')&&await f.page.locator('[data-profile-image-reference]').count()===2,'an older failed collection read cannot erase a newer authoritative balance or catalog');await f.close();
 }
 {
  const f=await fixture({ownerPreview:true}),pending=f.deferCollection();
  await f.page.getByRole('button',{name:'Image collection',exact:true}).click();await pending.began;
  check((await f.page.locator('[data-profile-image-collection-state]').textContent())==='Loading image collection…','a delayed collection read has a visible loading state');
  await f.page.getByRole('button',{name:'Chat',exact:true}).click();pending.release();await pending.done;
  await f.page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  check(await f.page.getByRole('log').isVisible()&&await f.page.locator('[data-profile-image-panel]').isHidden()&&await f.page.locator('[data-profile-image-reference]').count()===0&&!f.requests.some(item=>item.method==='POST'),'a collection response arriving after Chat is selected cannot reopen the panel or populate stale images');
  await f.browse();check(await f.page.locator('[data-profile-image-reference]').count()===2,'opening the collection again reads and displays the current images');await f.close();
 }
 console.log(`PROFILE_IMAGE_EDITS_BROWSER_V1=PASS ${checks} checks`);
}finally{await browser?.close();await new Promise(resolve=>server.close(resolve));}
