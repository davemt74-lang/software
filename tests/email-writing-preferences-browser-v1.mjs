import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync,mkdirSync} from 'node:fs';
import path from 'node:path';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const root=new URL('../',import.meta.url),read=file=>readFileSync(new URL(file,root),'utf8');
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const page=await browser.newPage({viewport:{width:1280,height:900}});page.setDefaultTimeout(10000);
const requests=[],errors=[],drafts=new Map(),proposals=new Map(),sourceReasons=new Map();
const id=n=>n.toString(16).padStart(32,'0'),hash=n=>n.toString(16).padStart(64,'0');
let draftSequence=0,proposalSequence=0,editSequence=0,prepareGate=null,denied=false,replaceProposalOnSave=false;
const initialStyle='Write warm, direct emails. Use clear paragraphs and avoid unnecessary repetition.\n';
const defaultStyle='Write helpful, accurate emails using the account owner’s stated preferences.\n';
const history=new Map([[0,defaultStyle],[1,'Write concise emails with a friendly greeting and a clear next step.\n'],[2,initialStyle]]);
let soul={text:initialStyle,version:2,revision:hash(802)};
function publish(text){soul={text:text.trim()+'\n',version:soul.version+1,revision:hash(801+soul.version+1)};history.set(soul.version,soul.text);}
function makeDraft({generated=true,edited=true,...values}={}){
  const n=++draftSequence,draft={id:id(n),generated,edited,status:'prepared',from:'owner@example.test',to:'alex@example.test',subject:'Project update '+n,
    body:'Hi Alex,\n\nHere is the saved project update.\n\nThanks!',attachments:[],is_reply:false,can_edit:true,can_send:true,
    review_token:'review-'+n,watch_review_token:'watch-'+n,edit_revision:hash(++editSequence),saved_at:new Date().toISOString(),...values};
  drafts.set(draft.id,draft);return draft;
}
const publicDraft=draft=>({...draft});
function preference(draft){
  const proposal=proposals.get(draft.id)||null;
  const reason=sourceReasons.get(draft.id)||(proposal?.state==='publishing'?proposal.reason:null)
    ||(!draft.generated?'generated_baseline_unavailable':!draft.edited?'owner_edit_required':null);
  return {draft_id:draft.id,eligible:draft.generated&&draft.edited&&!proposal&&!reason,reason,capture_id:draft.generated?id(500+Number.parseInt(draft.id,16)):null,
    edit_revision:draft.edit_revision,soul_version:soul.version,soul_revision:soul.revision,proposal:proposal?structuredClone(proposal):null};
}
function newProposal(draft,state='ready',reason=null){
  const p={id:id(1000+(++proposalSequence)),revision:1,state,category:'length',preference:'Keep paragraphs short and finish with a clear next step.',reason,accepted_version:null};
  proposals.set(draft.id,p);return p;
}
function profile(input={}){
  const all=[...history.keys()].sort((a,b)=>b-a),offset=input.cursor||0,versions=all.slice(offset,offset+2);
  return {...soul,history:versions.map(version=>({version,current:version===soul.version})),next_cursor:offset+2<all.length?offset+2:null,
    ...(input.version===undefined?{}:{selected_version:input.version,selected_text:history.get(input.version)})};
}
function hold(){let start,release,finish;return {started:new Promise(resolve=>{start=resolve;}),promise:new Promise(resolve=>{release=resolve;}),finished:new Promise(resolve=>{finish=resolve;}),start:()=>start(),release:()=>release(),finish:()=>finish()};}
const count=action=>requests.filter(input=>input.action===action).length;
page.on('pageerror',error=>errors.push(error.message));
await page.route('https://writing.test/',route=>route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
<style>html,body{margin:0;height:100%;font:15px system-ui;background:#f3f5f8}main{height:100vh;display:flex;flex-direction:column}#chatThread{flex:1;min-height:0;overflow:auto;padding:24px;box-sizing:border-box}#chatInput{width:80%;margin:12px}#launchers{position:fixed;left:12px;top:12px;z-index:1}#launchers button{padding:12px}</style>
<main><section id="chatThread" data-view="chat">Private Agent Chat</section><textarea id="chatInput" aria-label="Chat message"></textarea></main>
<nav id="launchers"><button id="gmailLauncher">Gmail</button><button id="otherLauncher">Curiosity</button></nav><aside id="sfOnlineRailV109"></aside>`}));
await page.route('https://writing.test/api/hub**',route=>route.fulfill({json:{ok:true,state:{contract:'vp3.agent.activity.v1',owner_user_id:1,agent_id:7,conversation_id:42,
  working_attention_binding:'fixture-binding',agent_name:'Main Agent',current_chat:{title:'Private Chat'},observed_at:new Date().toISOString(),coverage:{},
  sections:[{id:'gmail_inbox',label:'Gmail',items:[]}],specialists:[{id:'gmail',state:'checked',progress:null,detail:'Inbox checked.'}]}}}));
await page.route('https://writing.test/api/chat-email-v1.php',async route=>{
  const input=route.request().postDataJSON();requests.push(input);
  assert.equal(input.owner_key,'1');assert.equal(input.agent_id,7);assert.equal(input.conversation_id,42);assert.equal(input.csrf_token,'fixture');
  const ok=result=>route.fulfill({json:{ok:true,...result}}).catch(()=>{});
  const error=(status,message)=>route.fulfill({status,json:{ok:false,error:message}}).catch(()=>{});
  if(denied)return error(403,'Access changed. Reopen your private Chat.');
  if(input.action==='compose_create')return ok({draft:publicDraft(makeDraft({generated:false,edited:false,to:'',subject:'',body:'',can_send:false}))});
  if(input.action==='compose_list')return ok({drafts:[],counts:{pending:0,total:0,sent:0,discarded:0,answered:0},scope:input.queue_scope||'chat',filter:input.filter||'pending',next_cursor:null,from:'owner@example.test',can_send:true});
  if(input.action.startsWith('preferences_')){
    if(input.action==='preferences_get')return ok({preferences:profile(input)});
    if(input.soul_revision!==soul.revision)return error(409,'Your writing preferences changed. Load the current version.');
    if(input.action==='preferences_save')publish(input.text);
    else if(input.action==='preferences_restore'){assert.ok(history.has(input.version));publish(history.get(input.version));}
    else if(input.action==='preferences_reset')publish(defaultStyle);
    else assert.fail('Unexpected management action '+input.action);
    return ok({preferences:profile()});
  }
  const draft=drafts.get(input.draft_id);assert.ok(draft,'Every draft action has its exact saved source.');
  if(input.action==='review')return ok({draft:publicDraft(draft)});
  if(input.action==='compose_save'){
    assert.equal(input.review_token,draft.review_token);assert.deepEqual(input.attachment_ids,[]);
    Object.assign(draft,{to:input.to,subject:input.subject,body:input.body,edited:true,edit_revision:hash(++editSequence),saved_at:new Date().toISOString()});
    draft.review_token+='-saved';draft.watch_review_token+='-saved';
    if(replaceProposalOnSave){replaceProposalOnSave=false;newProposal(draft);sourceReasons.delete(draft.id);}
    else if(proposals.has(draft.id))sourceReasons.set(draft.id,'source_changed');
    return ok({draft:publicDraft(draft)});
  }
  if(input.action==='preference_status')return ok({preference:preference(draft)});
  if(input.action==='preference_prepare'){
    assert.equal(input.capture_id,preference(draft).capture_id);assert.equal(input.edit_revision,draft.edit_revision);
    assert.equal(Object.hasOwn(input,'body'),false);assert.equal(Object.hasOwn(input,'before'),false);
    if(!proposals.has(draft.id))newProposal(draft,prepareGate?'preparing':'ready');
    if(prepareGate){const gate=prepareGate;prepareGate=null;gate.start();await gate.promise;const p=proposals.get(draft.id);if(p.state==='preparing'){p.state='ready';p.revision++;}await ok({preference:preference(draft)});gate.finish();return;}
    return ok({preference:preference(draft)});
  }
  const proposal=proposals.get(draft.id);assert.ok(proposal);assert.equal(input.proposal_id,proposal.id);assert.equal(input.proposal_revision,proposal.revision);
  if(input.action==='preference_accept'){
    if(input.soul_revision!==soul.revision||sourceReasons.has(draft.id))return error(409,'The saved email or writing style changed.');
    assert.ok(['ready','publishing'].includes(proposal.state));assert.ok(Array.from(input.preference).length<=600);
    if(proposal.state==='publishing')assert.equal(input.preference,proposal.preference,'An uncertain publication retries only its frozen text.');
    publish(soul.text+'\n'+input.preference);Object.assign(proposal,{state:'accepted',preference:input.preference,reason:null,revision:proposal.revision+1,accepted_version:soul.version});
  }else if(input.action==='preference_dismiss')Object.assign(proposal,{state:'dismissed',reason:'dismissed',revision:proposal.revision+1});
  else assert.fail('Preferences must not dispatch email or a model edit: '+input.action);
  return ok({preference:preference(draft)});
});
const editor=()=>page.locator('.vp3-email-compose').filter({visible:true});
const writing=()=>editor().locator('.vp3-email-writing');
const popup=()=>page.locator('#vp3ChatSpecialistActivity');
const hubWriting=()=>popup().locator('.vp3-email-writing');
async function screenshot(name){const dir=process.env.VP3_EMAIL_WRITING_SCREENSHOT_DIR;if(!dir)return;mkdirSync(dir,{recursive:true});await page.screenshot({path:path.join(dir,name),fullPage:true});}
async function openDraft(draft){
  await page.evaluate(()=>window.dispatchEvent(new Event('stonefellow:chat-context-changing')));
  assert.equal(await page.evaluate(draft_id=>window.VP3EmailComposerV1.openDraft({draft_id,agent_id:7,conversation_id:42},{surface:'popup'}),draft.id),true);
  await editor().getByLabel('Message',{exact:true}).waitFor();
}
async function currentStyle(scope=writing()){
  await scope.getByRole('button',{name:'Writing preferences',exact:true}).click();
  const input=scope.getByLabel('Email writing style',{exact:true});await input.waitFor();
  await page.waitForFunction(expected=>[...document.querySelectorAll('.vp3-email-writing textarea')].some(input=>!input.disabled&&input.value===expected),soul.text);
  return input;
}
async function bounds(container){
  const geometry=await container.evaluate(panel=>({width:innerWidth,body:document.documentElement.scrollWidth,left:panel.getBoundingClientRect().left,right:panel.getBoundingClientRect().right,
    nested:[...panel.querySelectorAll('*')].filter(node=>{const css=getComputedStyle(node);return ['auto','scroll'].includes(css.overflowY)&&node.scrollHeight>node.clientHeight+2;}).map(node=>node.className),
    buttons:[...panel.querySelectorAll('.vp3-email-writing button')].filter(node=>node.getClientRects().length).map(node=>node.getBoundingClientRect().height)}));
  assert.ok(geometry.body<=geometry.width+1);assert.ok(geometry.left>=-1&&geometry.right<=geometry.width+1);assert.deepEqual(geometry.nested,[],'Preference panels use their host’s single scroll area.');
  assert.ok(geometry.buttons.every(height=>height>=43.5),'All writing preference actions remain 44px touch targets.');
}
async function openHubStyle(){
  await page.evaluate(()=>window.dispatchEvent(new Event('stonefellow:chat-context-changing')));
  await page.getByRole('button',{name:'Gmail',exact:true}).click();await popup().getByRole('button',{name:'Gmail settings',exact:true}).click();
  return currentStyle(popup());
}
try{
  await page.goto('https://writing.test/');
  await page.evaluate(()=>{
    window.currentChat=42;window.STONEFELLOW_CHAT={userId:1,agentId:7,workingAttentionBinding:'fixture-binding'};
    const view=name=>{document.getElementById('chatThread').dataset.view=name;window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));};
    window.STONEFELLOW_CHAT_CONTINUITY_V87={agentId:()=>7,conversationId:()=>window.currentChat,isReady:()=>true,isRestoring:()=>false,ensureConversation:async()=>42,showEmailCanvasView:()=>view('email'),showChatView:()=>view('chat')};
    window.VP3_CHAT_EMAIL_V1={endpoint:'/api/chat-email-v1.php',csrf:'fixture',ownerKey:'1',agentId:7};
    window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'fixture-binding',activityEndpoint:'/api/hub'};
    window.VP3TopOfMindFeedV1={specialistNames:{gmail:'Gmail',curiosity:'Curiosity'},specialistLabels:{checked:'Checked'},specialistHeading:()=>'<h3>Gmail</h3>',emailReport:()=>''};
  });
  // Match production order: the reusable preferences module precedes composer assets.
  for(const file of ['agent-activity-hub-v1.css','email-writing-preferences-v1.css','chat-email-composer-v1.css'])await page.addStyleTag({content:read(file)});
  for(const file of ['email-writing-preferences-v1.js','chat-email-composer-v1.js','agent-activity-hub-v1.js'])await page.addScriptTag({content:read(file)});
  await page.evaluate(()=>{
    document.getElementById('gmailLauncher').addEventListener('click',event=>window.VP3AgentActivityHub.openSpecialist('gmail',event.currentTarget,{label:'Gmail'}));
    document.getElementById('otherLauncher').addEventListener('click',event=>window.VP3AgentActivityHub.openSpecialist('curiosity',event.currentTarget,{label:'Curiosity'}));
  });

  const manual=makeDraft({generated:false,edited:false});await openDraft(manual);await currentStyle();
  assert.equal(await writing().getByRole('button',{name:'Remember this writing preference',exact:true}).isVisible(),false);assert.equal(count('preference_prepare'),0);
  const genuine=makeDraft({edited:false});await openDraft(genuine);await editor().getByLabel('Message',{exact:true}).fill('Hi Alex,\n\nThe update is ready. Please review it by Friday.\n\nThanks!');
  await editor().getByRole('button',{name:'Save draft',exact:true}).click();await writing().getByRole('button',{name:'Remember this writing preference',exact:true}).waitFor();
  assert.equal(count('preference_prepare'),0,'Ordinary Save records the owner edit without dispatching inference.');assert.equal(count('preference_accept'),0);
  const exactEmail=JSON.stringify(publicDraft(genuine));await writing().getByRole('button',{name:'Remember this writing preference',exact:true}).click();
  await writing().getByLabel('Preference for future emails',{exact:true}).waitFor();assert.equal(count('preference_prepare'),1);
  const preferenceInput=writing().getByLabel('Preference for future emails',{exact:true});await preferenceInput.fill('Use short paragraphs.\nDo not store a second paragraph.');
  await writing().getByRole('button',{name:'Save writing preference',exact:true}).click();await writing().getByText('Use one paragraph of 1–600 characters for this writing preference.',{exact:true}).waitFor();assert.equal(count('preference_accept'),0);
  const acceptedText='Use short paragraphs, a friendly opening, and a clear next step.';await preferenceInput.fill(acceptedText);
  await preferenceInput.scrollIntoViewIfNeeded();await bounds(page.locator('#vp3EmailComposerPopupV1'));await screenshot('writing-preference-desktop.png');
  await writing().getByRole('button',{name:'Save writing preference',exact:true}).click();await writing().getByText('Writing preference saved for future emails. This email is unchanged.',{exact:true}).waitFor();
  assert.equal(count('preference_accept'),1);assert.ok(soul.text.includes(acceptedText));assert.equal(JSON.stringify(publicDraft(genuine)),exactEmail,'Saving a preference cannot rewrite the current saved email, review token, or watch choice.');
  const acceptedVersion=proposals.get(genuine.id).accepted_version;publish(defaultStyle);await openDraft(genuine);
  await writing().getByText('This preference was saved in version '+acceptedVersion+'. Current writing preferences are version '+soul.version+'.',{exact:true}).waitFor();
  assert.equal(count('preference_accept'),1,'An accepted receipt does not silently reapply after reset or restoration.');

  const concurrent=makeDraft();newProposal(concurrent);await openDraft(concurrent);await writing().getByRole('button',{name:'Review writing preference',exact:true}).click();
  const originalSoul=soul.revision;await writing().getByLabel('Preference for future emails',{exact:true}).fill('Keep this owner-edited suggestion while checking style.');
  publish('Use precise greetings and concise, warm conclusions.');await writing().getByRole('button',{name:'Check suggestion status',exact:true}).click();
  await writing().getByText('Writing preferences changed elsewhere. Review the current writing style, then reopen this suggestion before saving.',{exact:true}).waitFor();
  assert.equal(await writing().getByRole('button',{name:'Save writing preference',exact:true}).isDisabled(),true);assert.notEqual(soul.revision,originalSoul);
  await writing().getByRole('button',{name:'Review writing preference',exact:true}).click();await writing().getByRole('button',{name:'Discard preference edits',exact:true}).click();
  await writing().getByLabel('Email writing style',{exact:true}).waitFor();await page.waitForFunction(text=>[...document.querySelectorAll('.vp3-email-writing textarea')].some(input=>input.value===text),soul.text);
  const reviewedSoul=soul.revision;await writing().getByRole('button',{name:'Review writing preference',exact:true}).click();await writing().getByRole('button',{name:'Save writing preference',exact:true}).click();
  await writing().getByText('Writing preference saved for future emails. This email is unchanged.',{exact:true}).waitFor();
  assert.equal(requests.filter(input=>input.action==='preference_accept').at(-1).soul_revision,reviewedSoul,'Only an explicitly reviewed current style revision is submitted.');

  const pending=makeDraft();await openDraft(pending);prepareGate=hold();const pendingGate=prepareGate;
  await writing().getByRole('button',{name:'Remember this writing preference',exact:true}).click();await pendingGate.started;
  const pendingStyle=await currentStyle();const unsavedStyle='Keep this unsaved writing style while the suggestion finishes.';await pendingStyle.fill(unsavedStyle);
  pendingGate.release();await pendingGate.finished;await writing().getByRole('button',{name:'Review writing preference',exact:true}).waitFor();
  assert.equal(await pendingStyle.isVisible(),true);assert.equal(await pendingStyle.inputValue(),unsavedStyle);assert.equal(await writing().getByLabel('Preference for future emails',{exact:true}).isVisible(),false,'A ready suggestion cannot hide a dirty writing-style manager.');
  await editor().getByRole('button',{name:'Close',exact:true}).click();await writing().getByRole('button',{name:'Keep editing preference',exact:true}).click();assert.equal(await pendingStyle.inputValue(),unsavedStyle);
  assert.equal(await pendingStyle.evaluate(input=>input===document.activeElement),true,'Keep editing restores keyboard focus to the unsaved preference.');
  await writing().getByRole('button',{name:'Review writing preference',exact:true}).click();await writing().getByRole('button',{name:'Discard preference edits',exact:true}).click();
  await writing().getByRole('button',{name:'Dismiss',exact:true}).click();await writing().getByText('This suggestion was dismissed. No writing preference was saved.',{exact:true}).waitFor();
  const preparesAfterDismiss=count('preference_prepare');await openDraft(pending);await writing().getByText('This suggestion was dismissed. No writing preference was saved.',{exact:true}).waitFor();
  assert.equal(count('preference_prepare'),preparesAfterDismiss);assert.equal(await writing().getByRole('button',{name:'Remember this writing preference',exact:true}).isVisible(),false);

  const uncertain=makeDraft(),frozen=newProposal(uncertain,'publishing','publication_unavailable');await openDraft(uncertain);
  await writing().getByRole('button',{name:'Review pending preference',exact:true}).click();assert.equal(await writing().getByLabel('Preference for future emails',{exact:true}).getAttribute('readonly'),'');
  assert.equal(await writing().getByLabel('Preference for future emails',{exact:true}).inputValue(),frozen.preference);assert.equal(await writing().getByRole('button',{name:'Dismiss',exact:true}).isDisabled(),true);
  const acceptsBeforeRetry=count('preference_accept'),retrySoul=soul.revision;
  await writing().getByRole('button',{name:'Retry saving preference',exact:true}).click();await writing().getByText('Writing preference saved for future emails. This email is unchanged.',{exact:true}).waitFor();
  assert.equal(count('preference_accept'),acceptsBeforeRetry+1);assert.equal(requests.filter(input=>input.action==='preference_accept').at(-1).soul_revision,retrySoul);

  const stale=makeDraft();newProposal(stale);await openDraft(stale);await writing().getByRole('button',{name:'Review writing preference',exact:true}).click();
  await writing().getByLabel('Preference for future emails',{exact:true}).fill('This editable suggestion must survive a changed draft.');await editor().getByLabel('Message',{exact:true}).fill('A newer owner revision of the email.');
  assert.equal(await writing().getByRole('button',{name:'Save writing preference',exact:true}).isDisabled(),true);await editor().getByRole('button',{name:'Save draft',exact:true}).click();
  await writing().getByText('The saved email changed. Your preference text is kept, but this suggestion cannot be saved.',{exact:true}).waitFor();
  assert.equal(await writing().getByLabel('Preference for future emails',{exact:true}).inputValue(),'This editable suggestion must survive a changed draft.');assert.equal(await writing().getByRole('button',{name:'Save writing preference',exact:true}).isDisabled(),true);
  replaceProposalOnSave=true;await editor().getByLabel('Message',{exact:true}).fill('A saved pair with another prepared suggestion.');await editor().getByRole('button',{name:'Save draft',exact:true}).click();
  await writing().getByRole('button',{name:'Review writing preference',exact:true}).waitFor();
  assert.equal(await writing().getByLabel('Preference for future emails',{exact:true}).inputValue(),'This editable suggestion must survive a changed draft.','A different ready proposal cannot overwrite dirty preference text from the previous saved pair.');
  assert.equal(await writing().getByRole('button',{name:'Save writing preference',exact:true}).isDisabled(),true);assert.equal(await writing().getByRole('button',{name:'Dismiss',exact:true}).isDisabled(),true);
  await writing().getByRole('button',{name:'Review writing preference',exact:true}).click();await writing().getByRole('button',{name:'Keep editing preference',exact:true}).click();
  assert.equal(await writing().getByLabel('Preference for future emails',{exact:true}).inputValue(),'This editable suggestion must survive a changed draft.');

  const mobile=makeDraft();newProposal(mobile);await openDraft(mobile);await page.setViewportSize({width:390,height:700});
  await writing().getByRole('button',{name:'Review writing preference',exact:true}).click();await writing().getByLabel('Preference for future emails',{exact:true}).fill('Keep future email paragraphs brief, helpful, and easy to read on a phone.');
  await writing().getByRole('button',{name:'Save writing preference',exact:true}).scrollIntoViewIfNeeded();await bounds(page.locator('#vp3EmailComposerPopupV1'));
  assert.equal(await writing().getByLabel('Preference for future emails',{exact:true}).evaluate(input=>getComputedStyle(input).fontSize),'16px');await screenshot('writing-preference-phone.png');
  await writing().getByRole('button',{name:'Writing preferences',exact:true}).click();await writing().getByRole('button',{name:'Discard preference edits',exact:true}).click();
  const style=writing().getByLabel('Email writing style',{exact:true});await style.waitFor();await page.waitForFunction(text=>[...document.querySelectorAll('.vp3-email-writing textarea')].some(input=>input.value===text),soul.text);
  const ownStyle='Use concise, respectful emails and end with one actionable next step.';await style.fill(ownStyle);publish('A different writing style saved from another browser.');
  await writing().getByRole('button',{name:'Save writing style',exact:true}).click();await writing().getByRole('button',{name:'Load current writing style',exact:true}).waitFor();
  assert.equal(await style.inputValue(),ownStyle);assert.equal(await writing().getByRole('button',{name:'Save writing style',exact:true}).isDisabled(),true);
  await writing().getByRole('button',{name:'Load current writing style',exact:true}).click();assert.equal(await style.inputValue(),soul.text);
  await style.fill(ownStyle);const styleSavedVersion=soul.version+1;await writing().getByRole('button',{name:'Save writing style',exact:true}).click();await writing().getByText('Current version '+styleSavedVersion,{exact:true}).waitFor();
  assert.equal(soul.text,ownStyle+'\n');await writing().getByText('Writing preference history',{exact:true}).click();
  for(let pageNumber=0;pageNumber<8&&!await writing().getByRole('button',{name:'Version 1',exact:true}).count();pageNumber++){
    const before=count('preferences_get'),loaded=page.waitForResponse(response=>response.url().endsWith('/api/chat-email-v1.php')&&response.request().postDataJSON()?.action==='preferences_get');
    const previous=await writing().locator('.vp3-email-writing-versions').innerText();await writing().getByRole('button',{name:'Older versions',exact:true}).click();await loaded;
    await page.waitForFunction(previous=>document.querySelector('.vp3-email-writing-versions').innerText!==previous,previous);assert.ok(count('preferences_get')>before);
  }
  await writing().getByRole('button',{name:'Version 1',exact:true}).click();await writing().getByRole('button',{name:'Restore this version',exact:true}).waitFor();
  await writing().getByRole('button',{name:'Restore this version',exact:true}).scrollIntoViewIfNeeded();await bounds(page.locator('#vp3EmailComposerPopupV1'));await screenshot('writing-style-history-phone.png');
  const beforeRestore=soul.version;await writing().getByRole('button',{name:'Restore this version',exact:true}).click();assert.equal(soul.version,beforeRestore);
  await writing().getByRole('button',{name:'Confirm restore',exact:true}).click();await writing().getByText('Current version '+(beforeRestore+1),{exact:true}).waitFor();assert.equal(soul.text,history.get(1));
  await writing().getByRole('button',{name:'Reset to default',exact:true}).click();const beforeReset=soul.version;assert.equal(count('preferences_reset'),0);
  await writing().getByRole('button',{name:'Confirm reset',exact:true}).click();await writing().getByText('Current version '+(beforeReset+1),{exact:true}).waitFor();assert.equal(soul.version,beforeReset+1);assert.equal(soul.text,defaultStyle);

  await page.setViewportSize({width:1280,height:900});let hubStyle=await openHubStyle();await hubStyle.fill('These unsaved Gmail settings must survive compose navigation.');
  const creates=count('compose_create');await popup().getByRole('button',{name:'Compose new email',exact:true}).click();await hubWriting().getByRole('button',{name:'Keep editing preference',exact:true}).click();
  assert.equal(count('compose_create'),creates);assert.equal(await hubStyle.inputValue(),'These unsaved Gmail settings must survive compose navigation.');
  await page.getByRole('button',{name:'Gmail',exact:true}).click();await hubWriting().getByRole('button',{name:'Keep editing preference',exact:true}).click();assert.equal(await popup().isVisible(),true,'The same Gmail icon cannot discard style edits.');
  await page.getByRole('button',{name:'Curiosity',exact:true}).click();await hubWriting().getByRole('button',{name:'Keep editing preference',exact:true}).click();assert.equal(await popup().getByRole('heading',{name:'Gmail settings',exact:true}).isVisible(),true);
  await popup().getByRole('button',{name:'Compose new email',exact:true}).click();await hubWriting().getByRole('button',{name:'Discard preference edits',exact:true}).click();await editor().getByLabel('Message',{exact:true}).waitFor();assert.equal(count('compose_create'),creates+1);
  hubStyle=await openHubStyle();await hubStyle.fill('Discarding explicitly may close the Gmail settings.');await page.getByRole('button',{name:'Gmail',exact:true}).click();await hubWriting().getByRole('button',{name:'Discard preference edits',exact:true}).click();await popup().waitFor({state:'detached'});
  hubStyle=await openHubStyle();await hubStyle.fill('This private style must clear immediately after access changes.');await page.evaluate(()=>window.dispatchEvent(new Event('stonefellow:chat-context-changing')));await popup().waitFor({state:'detached'});
  await openHubStyle();denied=true;await hubWriting().getByRole('button',{name:'Reload writing style',exact:true}).click();await popup().waitFor({state:'detached'});denied=false;

  const late=makeDraft();await openDraft(late);prepareGate=hold();const lateGate=prepareGate;await writing().getByRole('button',{name:'Remember this writing preference',exact:true}).click();await lateGate.started;
  await page.evaluate(()=>window.dispatchEvent(new Event('stonefellow:chat-context-changing')));lateGate.release();await lateGate.finished;
  assert.equal(await page.locator('.vp3-email-writing').count(),0,'A delayed prepared suggestion cannot restore a released private Chat view.');
  assert.equal(count('confirm'),0);assert.equal(count('compose_generate'),0);assert.deepEqual(errors,[]);
  console.log('Email writing preferences browser: explicit AI-edit suggestion, one-shot preparation, pinned Soul revision, dirty manager race, frozen publication retry, accepted-history copy, history/restore/reset, native Gmail exits, access cleanup and mobile single-scroll controls passed.');
}catch(error){try{await screenshot('writing-preference-failure.png');console.error(JSON.stringify({errors,actions:requests.map(input=>input.action),lastRequests:requests.slice(-5)}));}catch{}throw error;}
finally{await browser.close();}
