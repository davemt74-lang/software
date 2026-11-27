import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,mkdirSync} from 'node:fs';
import {createHash} from 'node:crypto';
import path from 'node:path';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const read=file=>readFileSync(new URL('../'+file,import.meta.url),'utf8');
const pdf=readFileSync(new URL('fixtures/gmail-documents/quote.pdf',import.meta.url));
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:1280,height:900}});page.setDefaultTimeout(10000);
const errors=[],requests=[];page.on('pageerror',e=>errors.push(e.message));
const id='a'.repeat(32),ref='c'.repeat(200),result={coverage:'readable',summary:'Quote Q-104 totals $265.00 for ten custom shirts and setup.',
  facts:[{text:'The total is $265.00.',page:1},{text:'Five medium and five large shirts.',page:2}],
  questions:['What delivery date is available?','Which shipping address should be used?'],reply_body:'Thanks for the quote. Could you confirm the delivery date and shipping address?'};
let saved=null,denied=false,gate=null,getGate=null,failAnalysis=false;
const draft={id:'d'.repeat(32),status:'prepared',from:'owner@example.test',to:'sender@example.test',subject:'Re: Quote Q-104',body:'Previously reviewed wording.',
  attachments:[],is_reply:true,can_open_source:true,can_edit:true,can_send:true,review_token:'native-review',watch_review_token:'native-watch',edit_revision:'e'.repeat(64),saved_at:new Date().toISOString()};
const publicDraft=()=>structuredClone(draft);
function hold(){let release,start;return {promise:new Promise(r=>release=r),started:new Promise(r=>start=r),release:()=>release(),start:()=>start()};}
const count=a=>requests.filter(r=>r.input.action===a).length;
await page.route('https://documents.test/',route=>route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
<style>html,body{margin:0;height:100%;font:15px system-ui;background:#f4f6f9}main{height:100vh;display:flex;flex-direction:column}#chatThread{flex:1;min-height:0;overflow:auto;box-sizing:border-box;padding:16px}#host{max-width:760px;margin:0 auto;padding:16px;background:white}#chatInput{margin:10px;font:inherit}</style>
<main><section id="chatThread"><div id="host"><h2>Quote from North Mountain Printworks</h2><p>Please check the attached quote.</p></div></section><textarea id="chatInput" aria-label="Chat message"></textarea></main>`}));
await page.route('https://documents.test/api/**',async route=>{
  const input=route.request().postDataJSON(),url=new URL(route.request().url());requests.push({input,url:url.href});
  assert.equal(input.owner_key,'1');assert.equal(input.agent_id,7);assert.equal(input.conversation_id,42);assert.equal(input.csrf_token,'fixture');
  const ok=value=>route.fulfill({json:{ok:true,...value}}).catch(()=>{}),fail=(status,error)=>route.fulfill({status,json:{ok:false,error}}).catch(()=>{});
  if(url.pathname.endsWith('/chat-email-v1.php')){
    if(input.action==='review')return ok({draft:publicDraft()});
    if(input.action==='compose_source')return ok({draft_id:draft.id,edit_revision:draft.edit_revision,conversation_id:42,
      message:{subject:'Quote Q-104',from:'sender@example.test',body:'Please check the attached quote.',documents:{source_ref:ref}}});
    if(input.action==='compose_save'){assert.equal(input.review_token,draft.review_token);Object.assign(draft,{body:input.body,subject:input.subject,to:input.to,edit_revision:'f'.repeat(64),review_token:'review-saved'});return ok({draft:publicDraft()});}
    assert.fail('Unexpected composer action '+input.action);
  }
  assert.ok(url.pathname.endsWith('/gmail-documents-v1.php')||url.searchParams.get('workspace_documents')==='1');
  assert.equal(input.source_ref,ref);assert.ok(!('data' in input)&&!('bytes' in input)&&!('url' in input));
  if(denied)return fail(403,'Private attachment access changed.');
  if(input.action==='list')return ok({installed:true,parts:[{part:'0.1',name:'Quote Q-104.pdf',mime:'application/pdf',size:pdf.length,supported:true,reason:null},
    {part:'0.2',name:'<img src=x onerror=alert(1)>.zip',mime:'application/zip',size:1024,supported:false,reason:'unsupported'},
    {part:'0.3',name:'oversized.pdf',mime:'application/pdf',size:2097153,supported:false,reason:'over_limit'}]});
  if(input.action==='get'){const value=structuredClone(saved);if(getGate){const g=getGate;getGate=null;g.start();await g.promise;}return ok({review:value});}
  if(input.action==='analyze'){
    assert.match(input.request_id,/^[a-f0-9]{32}$/);assert.equal(typeof input.instruction,'string');
    if(gate){const g=gate;gate=null;g.start();await g.promise;}
    if(failAnalysis)return fail(503,'The selected model could not read this attachment.');
    saved={id,state:'ready',reason:null,result:structuredClone(result)};return ok({review:saved});
  }
  if(input.action==='open')return ok({file:{name:'Quote Q-104.pdf',mime:'application/pdf',data:pdf.toString('base64'),sha256:createHash('sha256').update(pdf).digest('hex')}});
  if(input.action==='reply'){assert.equal(input.review_id,id);draft.body=saved.result.reply_body;return ok({draft:publicDraft()});}
  assert.fail('Unexpected attachment action '+input.action);
});
const panel=()=>page.locator('.vp3-gmail-documents').last();
const summary=()=>panel().locator(':scope > details').first();
async function mount({phone=false}={}){
  await page.evaluate(({ref,phone})=>{
    window.dispatchEvent(new Event('stonefellow:chat-context-changing'));document.getElementById('host').replaceChildren();
    window.VP3_CHAT_EMAIL_V1.endpoint=phone?'/api/phone-location-v1.php?workspace_email=1&device_id=fixture&item_ref=exact-item&scope_revision=scope&source_revision=source':'/api/chat-email-v1.php';
    window.VP3GmailDocumentsV1.mount(document.getElementById('host'),{source_ref:ref});
  },{ref,phone});
  await summary().waitFor();
}
async function expand(){await summary().locator(':scope > summary').click();await summary().getByRole('button',{name:'Analyze attachment',exact:true}).waitFor();}
async function screenshot(name){if(process.env.VP3_GMAIL_DOCUMENTS_SCREENSHOT_DIR){mkdirSync(process.env.VP3_GMAIL_DOCUMENTS_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.VP3_GMAIL_DOCUMENTS_SCREENSHOT_DIR,name),fullPage:true});}}
async function bounds(){assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Document review fits viewport.');
  assert.ok(await panel().evaluate(el=>[...el.querySelectorAll('textarea')].every(x=>x.scrollHeight<=x.clientHeight+2)),'Textareas use the parent page scroll.');}
try{
  await page.goto('https://documents.test/');await page.evaluate(()=>{
    window.currentChat=42;window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'binding'};
    const view=name=>{document.getElementById('chatThread').dataset.view=name;window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));};
    window.STONEFELLOW_CHAT_CONTINUITY_V87={agentId:()=>7,conversationId:()=>window.currentChat,isReady:()=>true,isRestoring:()=>false,ensureConversation:async()=>42,showEmailCanvasView:()=>view('email'),showChatView:()=>view('chat')};
    window.VP3_CHAT_EMAIL_V1={endpoint:'/api/chat-email-v1.php',csrf:'fixture',ownerKey:'1',agentId:7};
  });
  for(const file of ['gmail-documents-v1.css','chat-email-composer-v1.css'])await page.addStyleTag({content:read(file)});
  for(const file of ['gmail-documents-v1.js','chat-email-composer-v1.js'])await page.addScriptTag({content:read(file)});
  await mount();assert.equal(count('analyze'),0);await expand();await summary().getByText('Ready to analyze this attachment.',{exact:true}).waitFor();assert.equal(count('analyze'),0);
  assert.equal(await panel().locator('img').count(),0,'Attachment filenames render as literal text.');
  await summary().getByLabel('Question or reply direction (optional)').fill('Check the total and ask about delivery.');
  await summary().getByRole('button',{name:'Analyze attachment',exact:true}).click();await summary().getByText(result.summary,{exact:true}).waitFor();assert.equal(count('analyze'),1);
  await summary().getByText('Suggested reply',{exact:true}).click();await bounds();await screenshot('attachment-review-desktop.png');
  const popupEvent=page.waitForEvent('popup');await summary().getByRole('button',{name:'Page 2',exact:true}).click();const original=await popupEvent;
  await original.waitForURL(/blob:.*#page=2/);assert.equal(count('open'),1);await original.close();
  await mount();await expand();await summary().getByText(result.summary,{exact:true}).waitFor();assert.equal(count('analyze'),1,'Reopening uses stored analysis.');
  for(const width of [390,320]){await page.setViewportSize({width,height:844});await summary().getByText('Suggested reply',{exact:true}).click();await bounds();await screenshot('attachment-review-phone-'+width+'.png');}
  await page.setViewportSize({width:1280,height:900});
  // Actual composer: the explicit reply goes through normal review, then using
  // wording must respect unsaved edits and cannot silently save or send.
  await summary().getByText('Suggested reply',{exact:true}).click();await summary().getByRole('button',{name:'Create reply draft',exact:true}).click();
  const composer=page.locator('#chatEmailCanvasV1');await composer.getByLabel('Message',{exact:true}).waitFor();
  await composer.getByRole('button',{name:'Read original email',exact:true}).click();
  await composer.locator('.vp3-gmail-documents > details > summary').first().click();
  await composer.locator('.vp3-gmail-documents').getByText(result.summary,{exact:true}).waitFor();
  await composer.locator('.vp3-gmail-documents').getByText('Suggested reply',{exact:true}).click();
  const body=composer.getByLabel('Message',{exact:true});await body.fill('My unsaved edit.');const saves=count('compose_save');
  await composer.getByRole('button',{name:'Use wording in this draft',exact:true}).click();
  await composer.getByRole('button',{name:'Keep editing',exact:true}).click();assert.equal(await body.inputValue(),'My unsaved edit.');assert.equal(count('compose_save'),saves);
  await composer.getByRole('button',{name:'Use wording in this draft',exact:true}).click();
  await composer.getByRole('button',{name:'Discard edits',exact:true}).click();assert.equal(await body.inputValue(),result.reply_body);assert.equal(count('compose_save'),saves);
  assert.equal(count('send'),0);assert.equal(count('compose_send'),0);
  // Delayed old status cannot re-enable Analyze during an active new request.
  await mount();saved=null;const oldGet=hold();getGate=oldGet;await expand();await oldGet.started;
  const analysis=hold();gate=analysis;await summary().getByRole('button',{name:'Analyze attachment',exact:true}).click();await analysis.started;
  oldGet.release();await page.waitForTimeout(100);assert.equal(await summary().getByRole('button',{name:'Analyze attachment',exact:true}).isDisabled(),true);
  analysis.release();await summary().getByText(result.summary,{exact:true}).waitFor();
  // Native phone retains its exact paired-device and source revision URL.
  await mount({phone:true});await expand();await summary().getByText(result.summary,{exact:true}).waitFor();
  await summary().getByText('Suggested reply',{exact:true}).click();assert.equal(await summary().getByRole('button',{name:'Create reply draft',exact:true}).count(),0);
  const native=new URL(requests.filter(r=>r.input.action==='get').at(-1).url);assert.equal(native.searchParams.get('item_ref'),'exact-item');assert.equal(native.searchParams.get('workspace_documents'),'1');assert.equal(native.searchParams.has('workspace_email'),false);
  saved={id,state:'ready',reason:null,result:{coverage:'unreadable',summary:'The scan cannot be read confidently.',facts:[],questions:['Can you provide a clearer copy?'],reply_body:''}};
  await mount();await expand();await summary().getByText(saved.result.summary,{exact:true}).waitFor();assert.equal(await summary().getByText('Suggested reply',{exact:true}).count(),0);
  saved={id,state:'ready',reason:null,result:{...result,coverage:'partial'}};await mount();await expand();await summary().getByText('Some document content could not be read confidently.',{exact:true}).waitFor();
  failAnalysis=true;await summary().getByRole('button',{name:'Analyze again',exact:true}).click();await summary().getByRole('button',{name:'Check saved review',exact:true}).waitFor();
  const failedCount=count('analyze');await summary().getByRole('button',{name:'Check saved review',exact:true}).click();await summary().getByText(result.summary,{exact:true}).waitFor();assert.equal(count('analyze'),failedCount,'An uncertain request offers a read-only status check.');failAnalysis=false;
  // A response arriving after account/Chat navigation never revives private UI.
  saved=null;await mount();const delayed=hold();getGate=delayed;await expand();await delayed.started;
  await page.evaluate(()=>{window.currentChat=43;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));});delayed.release();await page.waitForTimeout(100);
  assert.equal(await page.locator('.vp3-gmail-documents').count(),0);
  await page.evaluate(()=>window.currentChat=42);denied=true;await page.evaluate(ref=>window.VP3GmailDocumentsV1.mount(document.getElementById('host'),{source_ref:ref}),ref);
  await page.waitForFunction(()=>!document.querySelector('.vp3-gmail-documents'));assert.deepEqual(errors,[]);
  assert.equal(requests.filter(r=>/send|preference_accept|followthrough_update/.test(r.input.action)).length,0);
  console.log('GMAIL_DOCUMENTS_BROWSER_V1=PASS');
}finally{await browser.close();}
