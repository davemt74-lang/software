import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {randomUUID} from 'node:crypto';
import {mkdtempSync,rmSync} from 'node:fs';
import {createRequire} from 'node:module';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {setTimeout as delay} from 'node:timers/promises';
import {fileURLToPath} from 'node:url';
import {localPort,preparePhoneSite} from './fixtures/phone-site-v1.mjs';

const {chromium,request}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const repo=fileURLToPath(new URL('..',import.meta.url));
const root=mkdtempSync(path.join(tmpdir(),'vp3-owner-reply-'));
const site=path.join(root,'web'), sessions=path.join(root,'sessions');
const php=process.env.VP3_PHP_BINARY || 'php';
const tracking='/api/phone-tracking-v1.php', holder='/api/phone-location-v1.php';
const chat='/api/chat-v236.php', reply='/api/phone-chat-reply-v1.php';
const scope={agent_id:0,conversation_id:8};
const prompt='Can you meet me by the Studio entrance?';
const secondPrompt='Which door should the crew use?';
const responseText='Yes, meet me at the north entrance.';
const normalMessage='Phone camera status';
let server,browser;

async function post(api,endpoint,data,user) {
  const headers=user?{'X-VP3-Working-Attention':user.binding,'X-CSRF-Token':user.csrf}:{'X-VP3-Phone':'1'};
  const response=await api.post(endpoint,{headers,data});
  const result=await response.json();
  assert.equal(result.ok,true,endpoint+' '+data.action+' returned '+response.status()+': '+(result.error || ''));
  return result;
}

function chatData(action,extra={}) {
  return {action,conversation_id:scope.conversation_id,agent_context:{user_agent_id:scope.agent_id},...extra};
}

async function pair(origin,ownerApi,owner,name) {
  const context=browser && await browser.newContext({baseURL:origin,isMobile:true,viewport:{width:390,height:740}});
  const api=context?.request || await request.newContext({baseURL:origin});
  const invite=await post(ownerApi,tracking,{action:'invite',request_id:randomUUID(),csrf_token:owner.csrf},owner);
  const token=new URL(invite.phone_url,origin).hash.slice('#invite='.length);
  const claim=await post(api,holder,{action:'claim',invite:token,name});
  const device=claim.device;
  await post(ownerApi,tracking,{action:'approve',request_id:randomUUID(),csrf_token:owner.csrf,device_id:device.id,
    generation:device.generation,allow_location:false,chat_origin:scope},owner);
  return {api,context,id:device.id,name};
}

async function sendFromFooter(phone,message) {
  if (!phone.context) {
    const request_id=randomUUID();
    const data=await post(phone.api,holder,{action:'chat_send',device_id:phone.id,request_id,message});
    return {request_id,data};
  }
  phone.page=await phone.context.newPage();
  await phone.page.goto('/phone-location.php');
  const home=phone.page.getByRole('tab',{name:'Chat',exact:true});
  await home.waitFor();
  assert.equal(await home.getAttribute('aria-selected'),'true');
  const chatTab=phone.page.getByRole('tab',{name:'Chat',exact:true});
  await chatTab.click();
  assert.equal(await chatTab.getAttribute('aria-selected'),'true');
  const input=phone.page.locator('#phone-chat-input');
  await input.fill(message);
  const sent=phone.page.waitForResponse(r=>new URL(r.url()).pathname===holder && r.request().postDataJSON()?.action==='chat_send');
  await input.press('Enter');
  const result=await sent;
  assert.equal(result.status(),200);
  await phone.page.locator('#phone-chat-turns').getByText(message,{exact:true}).waitFor();
  return {request_id:result.request().postDataJSON().request_id,data:await result.json()};
}

function checkWaiting(turns,request_id,message) {
  const turn=turns.find(item=>item.request_id===request_id);
  assert.equal(turn?.state,'awaiting_owner');
  assert.equal(turn.message,message);
  assert.equal(turn.reply,null);
}

async function history(api,owner,cid=scope.conversation_id) {
  const data=await post(api,chat,chatData('load',{conversation_id:cid,csrf_token:owner.csrf}),owner);
  return data.messages;
}

async function ownerUI(context,first,turn,action,second) {
  const page=await context.newPage();
  await page.goto('/chat.php?agent=system&conversation_id='+scope.conversation_id);
  assert.equal(await page.evaluate(()=>typeof window.VP3PhoneOwnerReply?.mount),'function',
    'the active Chat wrapper loads owner-reply controls before phone messages are polled');
  const entry=page.locator('#chatThread .message[data-chat-message-id="'+action.message_id+'"]');
  await entry.getByText(new RegExp(first.name)).first().waitFor();
  assert.ok((await entry.innerText()).includes(prompt));
  const updateText='The crew is unloading by the van.';
  await second.page.locator('#phone-chat-input').fill(updateText);
  await second.page.locator('#phone-chat-input').press('Enter');
  await page.bringToFront();
  const incoming=page.locator('#chatThread .message').filter({hasText:updateText});
  await incoming.getByRole('button',{name:'Reply',exact:true}).waitFor({timeout:30000});
  assert.ok((await incoming.innerText()).includes(second.name),'the open owner conversation receives the other phone’s actionable post');
  await page.locator('#chatInput').fill(normalMessage);
  await entry.getByRole('button',{name:'Reply',exact:true}).click();
  const form=entry.locator('form[data-phone-owner-reply]');
  await form.getByRole('textbox',{name:'Reply to '+first.name,exact:true}).fill(responseText);
  const delivered=page.waitForResponse(r=>new URL(r.url()).pathname===reply && r.request().method()==='POST');
  await form.getByRole('button',{name:'Send reply',exact:true}).click();
  const result=await delivered;
  assert.equal(result.status(),200);
  const body=result.request().postDataJSON();
  assert.equal(body.request_id,turn.request_id);
  assert.equal(body.device_id,first.id);
  assert.equal(body.message,responseText);
  const receipt=await result.json();
  assert.ok(receipt.ok && receipt.reply_message_id>0);
  assert.equal(await page.locator('#chatInput').inputValue(),normalMessage,'a targeted reply preserves the owner’s ordinary Chat draft');
  const chatEndpoint=await page.evaluate(()=>new URL(window.STONEFELLOW_CHAT.endpoint,location.href).href);
  const normal=page.waitForResponse(r=>r.url()===chatEndpoint && r.request().method()==='POST' &&
    r.request().postDataJSON()?.action==='send' && r.request().postDataJSON()?.message===normalMessage);
  await page.locator('#sendChatButton').click();
  assert.ok((await (await normal).json()).ok,'the ordinary owner Chat composer still sends to its agent');
  await page.reload();
  const saved=page.locator('#chatThread .message[data-chat-message-id="'+receipt.reply_message_id+'"]');
  await saved.waitFor();
  assert.ok((await saved.innerText()).includes(responseText),'the owner can read their saved reply after reload');
  const turnEl=first.page.locator('#phone-chat-turns .phone-turn[data-request="'+turn.request_id+'"]');
  const ownerBubble=turnEl.locator('.phone-bubble').filter({hasText:responseText});
  await ownerBubble.waitFor({timeout:20000});
  assert.equal(await ownerBubble.locator('small').innerText(),'Owner','the holder sees the human author');
  await first.page.reload();
  await first.page.getByRole('tab',{name:'Chat',exact:true}).click();
  await ownerBubble.waitFor({timeout:20000});
  assert.equal(await ownerBubble.count(),1);
  assert.equal(await first.page.getByText(normalMessage,{exact:true}).count(),0);
  return receipt;
}

try {
  const [owner]=preparePhoneSite(repo,site,sessions,php);
  execFileSync(php,['-r',"(new PDO('sqlite:'.$argv[1]))->exec(file_get_contents($argv[2]));",path.join(root,'data.sqlite'),
    path.join(repo,'tests/fixtures/phone-camera-chat-shell.sql')]);
  const port=await localPort(), origin='http://127.0.0.1:'+port;
  server=spawn(php,['-d','session.save_path='+sessions,'-d','display_errors=0','-S','127.0.0.1:'+port,'-t',site],{stdio:'ignore'});
  for (let tries=0;tries<50;tries++) {
    if (await fetch(origin+'/health.txt').then(r=>r.ok).catch(()=>false)) break;
    if (tries===49) throw Error('Phone fixture failed to start');
    await delay(100);
  }
  if (process.env.VP3_PHONE_HTTP_ONLY!=='1') browser=await chromium.launch({headless:true,
    executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH});
  const ownerCookies=[{name:'PHPSESSID',value:owner.session,url:origin,httpOnly:true,sameSite:'Lax'}];
  const ownerContext=browser && await browser.newContext({baseURL:origin});
  if (ownerContext) await ownerContext.addCookies(ownerCookies);
  const ownerApi=ownerContext?.request || await request.newContext({baseURL:origin,extraHTTPHeaders:{Cookie:'PHPSESSID='+owner.session}});
  const first=await pair(origin,ownerApi,owner,'Studio iPhone');
  const second=await pair(origin,ownerApi,owner,'Crew Android');
  // Simulate an installed phone/camera deployment that has not installed the new owner-reply table.
  // The real HTTP path must identify the missing upgrade without exposing SQL or invalidating the pairing.
  const schemaProbe=(sql)=>execFileSync(php,['-r',"(new PDO('sqlite:'.$argv[1]))->exec($argv[2]);",path.join(root,'data.sqlite'),sql]);
  schemaProbe('ALTER TABLE vp3_paired_phone_owner_replies_v1 RENAME TO missing_phone_owner_replies');
  try {
    const holderHeaders={'X-VP3-Phone':'1'};
    const active=await post(first.api,holder,{action:'status'});
    assert.equal(active.device.status,'approved');
    for (const data of [{action:'chat_status',device_id:first.id},
      {action:'chat_send',device_id:first.id,request_id:randomUUID(),message:'Upgrade probe'}]) {
      const rejected=await first.api.post(holder,{headers:holderHeaders,data});
      assert.equal(rejected.status(),503);
      const detail=await rejected.json();
      assert.equal(detail.ok,false);
      assert.equal(detail.code,'upgrade_required');
      assert.ok(!JSON.stringify(detail).includes('vp3_paired_phone_owner_replies_v1'));
    }
  } finally {
    schemaProbe('ALTER TABLE missing_phone_owner_replies RENAME TO vp3_paired_phone_owner_replies_v1');
  }
  const selected=await sendFromFooter(first,prompt);
  const other=await sendFromFooter(second,secondPrompt);
  checkWaiting(selected.data.turns,selected.request_id,prompt);
  checkWaiting(other.data.turns,other.request_id,secondPrompt);
  const activity=await post(ownerApi,chat,chatData('activity',{after_id:1,phone_updates:true,csrf_token:owner.csrf}),owner);
  const update=activity.phone_updates.find(item=>item.speech.includes(prompt));
  const action=update?.actions?.find(item=>item.type==='phone_owner_reply');
  assert.ok(action,'the private owner announcement contains a Reply action');
  assert.ok(update.speech.includes(first.name));
  assert.deepEqual([action.label,action.device_id,action.request_id,action.agent_id,action.conversation_id,action.device_name],
    ['Reply',first.id,selected.request_id,0,8,first.name]);
  assert.equal(action.message_id,update.message_id);
  const idleUpdate=activity.phone_updates.find(item=>item.speech.includes(secondPrompt));
  assert.equal(idleUpdate?.actions?.[0]?.device_id,second.id);
  const initial=await history(ownerApi,owner);
  const savedPrompt=initial.find(item=>Number(item.id)===action.message_id);
  assert.ok(JSON.stringify(savedPrompt).includes('phone_owner_reply'),'history restores the actionable announcement');
  let receipt;
  if (ownerContext) {
    receipt=await ownerUI(ownerContext,first,selected,action,second);
  } else {
    receipt=await post(ownerApi,reply,{action:'reply',...scope,device_id:first.id,request_id:selected.request_id,message:responseText},owner);
    await post(ownerApi,chat,chatData('send',{message:normalMessage,input_mode:'text',idempotency_key:randomUUID(),csrf_token:owner.csrf}),owner);
  }
  const updated=await post(first.api,holder,{action:'chat_status',device_id:first.id});
  assert.equal(updated.turns.length,1);
  const answered=updated.turns[0];
  assert.deepEqual([answered.request_id,answered.state,answered.reply,answered.reply_author],
    [selected.request_id,'replied',responseText,'owner']);
  const idle=await post(second.api,holder,{action:'chat_status',device_id:second.id});
  assert.equal(idle.turns.length,ownerContext?2:1);
  checkWaiting(idle.turns,other.request_id,secondPrompt);
  assert.ok(idle.turns.every(turn=>turn.reply===null && turn.state==='awaiting_owner'));
  if (second.page) assert.equal(await second.page.getByText(responseText,{exact:true}).count(),0);
  const saved=await history(ownerApi,owner);
  assert.ok(JSON.stringify(saved.find(item=>Number(item.id)===receipt.reply_message_id)).includes(responseText));
  assert.ok(saved.some(item=>item.role==='user' && item.message===normalMessage));
  assert.ok(!JSON.stringify(await history(ownerApi,owner,9)).includes(responseText));
  console.log('PASS two paired phones in one owner conversation: manual delivery, scoped Reply, human attribution, restored history, and independent owner Chat.');
} finally {
  if (browser) await browser.close();
  if (server && server.exitCode===null) {
    const closed=new Promise(resolve=>server.once('close',resolve));
    server.kill();
    await closed;
  }
  rmSync(root,{force:true,recursive:true});
}
