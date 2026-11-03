import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const source=fs.readFileSync(new URL('../profile-agent.js',import.meta.url),'utf8');
const server=http.createServer((request,response)=>{
  if(request.url==='/profile-agent.js'){response.writeHead(200,{'Content-Type':'text/javascript'});response.end(source);return;}
  response.writeHead(200,{'Content-Type':'text/html'});
  response.end(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Profile handoff fixture</title><section id="profileAgentShell"><div data-profile-agent-thread></div><form><textarea aria-label="Visitor message"></textarea><button type="submit">Send</button></form><div data-profile-agent-status role="status"></div></section><textarea id="chatInput">PRIVATE_OWNER_DRAFT</textarea><script>window.STONEFELLOW_PROFILE_AGENT={username:'fixture-owner',endpoint:'/api/profile-agent.php',conversationNamespace:'${'a'.repeat(64)}',profileToken:'FIXTURE_PROFILE_TOKEN'};</script><script src="/profile-agent.js"></script>`);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base=`http://127.0.0.1:${server.address().port}`;let browser,checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
async function fixture({loseFirst=false,owner=false}={}){
  const context=await browser.newContext({viewport:{width:390,height:844}}),page=await context.newPage(),requests=[],errors=[],saved=new Map(),messages=[];
  const state={conversation:null,handoff:null,switched:false};let sequence=100;
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/api/profile-agent.php*',async route=>{
    const request=route.request(),body=request.method()==='GET'?Object.fromEntries(new URL(request.url()).searchParams):request.postDataJSON();requests.push({method:request.method(),body});
    const send=(payload,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify({ok:true,...payload})});
    if(state.switched){await send({ok:false,code:'PROFILE_SESSION_CHANGED',error:'Refresh the profile.'},409);return;}
    if(body.action==='state'||body.action==='poll'){
      await send({agent:{greeting:''},conversation:state.conversation,messages:messages.filter(message=>message.id>Number(body.after_id||0)),handoff:state.handoff&&{...state.handoff,server_now_ms:Date.now()}});return;
    }
    if(body.action==='message'){
      let receipt=saved.get(body.client_request_id);
      if(!receipt){
        const message={id:++sequence,sender_type:'visitor',message:body.message};messages.push(message);
        state.conversation={conversation_id:31,status:owner?'owner_joined':'open'};
        state.handoff={turn_id:501,revision:1,conversation_id:31,state:owner?'owner_claimed':'agent_ready',dispatch_turn_id:501,dispatch_state:owner?'owner_claimed':'agent_ready',server_now_ms:Date.now()};
        receipt={accepted:true,conversation_id:31,visitor_message_id:message.id,client_request_id:body.client_request_id,conversation:state.conversation,handoff:state.handoff};saved.set(body.client_request_id,receipt);
      }
      if(loseFirst){loseFirst=false;await route.abort('failed');return;}
      await send(receipt);return;
    }
    if(body.action==='dispatch'){
      messages.push({id:++sequence,sender_type:'agent',message:'Saved public response'});state.handoff={...state.handoff,state:'replied',dispatch_state:'none',revision:2};
      await send({conversation_id:31,turn_id:501,dispatch_checked:true});return;
    }
    await send({ok:false,error:'Unexpected request'},422);
  });
  await page.goto(base+'/fixture-owner');
  return {page,requests,errors,saved,messages,state,async send(text){await page.getByRole('textbox',{name:'Visitor message'}).fill(text);await page.getByRole('button',{name:/Send|Retry message/}).click();},
    async close(){check(errors.length===0,'native visitor browser has no script errors');await context.close();}};
}
try{
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
  {
    const f=await fixture({loseFirst:true});await f.send('Show your public hours');await f.page.getByRole('button',{name:'Retry message'}).waitFor();
    const id=f.requests.find(request=>request.body.action==='message').body.client_request_id;
    check(f.saved.size===1&&(await f.page.getByRole('status').innerText()).includes('may already be saved'),'actual browser preserves the uncertain saved intent');
    await f.page.reload();await f.page.getByText('Saved public response',{exact:true}).waitFor();
    const sends=f.requests.filter(request=>request.body.action==='message');
    check(sends.length===2&&sends[1].body.client_request_id===id&&f.saved.size===1,'reload resends the identical key and obtains the existing saved turn');
    check(await f.page.locator('.profile-agent-message.visitor').count()===1,'real DOM deduplicates the visitor message recovered after reload');
    check(f.requests.filter(request=>request.body.action==='poll').every(request=>request.method==='GET')&&f.requests.filter(request=>request.body.action==='dispatch').every(request=>request.method==='POST'),'browser separates state reads from execution POSTs');
    check(await f.page.locator('#chatInput').inputValue()==='PRIVATE_OWNER_DRAFT'&&!JSON.stringify(f.requests).includes('PRIVATE_OWNER_DRAFT'),'owner private draft remains local and unchanged');await f.close();
  }
  {
    const f=await fixture({loseFirst:true});await f.send('Visitor A pending request');await f.page.getByRole('button',{name:'Retry message'}).waitFor();f.state.switched=true;
    await f.page.getByRole('button',{name:'Retry message'}).click();await f.page.getByText('Your visitor session changed. Refresh this profile before continuing.',{exact:true}).waitFor();
    check(await f.page.getByRole('textbox',{name:'Visitor message'}).isDisabled()&&await f.page.locator('.profile-agent-message').count()===0,'changed account clears history and disables further sends until refreshed');
    check(f.requests.every(request=>request.body.conversation_namespace==='a'.repeat(64)),'all native browser requests bind the original principal namespace');await f.close();
  }
  {
    const f=await fixture({owner:true});await f.send('Please speak with the owner');await f.page.getByText('The profile owner has joined this conversation.',{exact:true}).waitFor();
    check(!f.requests.some(request=>request.body.action==='dispatch'),'owner takeover does not trigger an automatic Agent dispatch');await f.close();
  }
  console.log(`PROFILE_VISITOR_HANDOFF_PA3_BROWSER=PASS ${checks} checks`);
}finally{await browser?.close();await new Promise(resolve=>server.close(resolve));}
