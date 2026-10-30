import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
const {chromium}=await import(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const script=fs.readFileSync('agent-reminders.js','utf8');
const html=`<!doctype html><button data-orb-stop>Stop</button><script>
window.calls=[];window.bubbles=[];window.spoken=[];window.remaining=1;window.disabledAck=false;window.busy=false;window.hiddenFixture=false;
Object.defineProperty(document,'hidden',{get:()=>hiddenFixture});
window.VP3_ORB_CONFIG={remindersEndpoint:'/reminders',csrf:'fixture',ownerKey:'1'};
window.VP3_AGENT_PREFERENCE_STATE={enabled:true};window.VP3AgentOrb={bubble:text=>bubbles.push(text)};
window.VP3_ORB_ADAPTER={busy:()=>busy,greet:async(text,current)=>{if(current())spoken.push(text);return true;},reminderRequest:async body=>{calls.push(body);if(body.action==='ack')return {ok:true,acknowledged:!disabledAck};if(window.waitClaim)return await new Promise(resolve=>window.releaseClaim=resolve);return {ok:true,item:remaining-->0?{id:1,token:'a'.repeat(32),text:'Reminder #1: <b>Yoga</b>. Done, or snooze?'}:null};}};
</script><script src="/agent-reminders.js"></script>`;
const server=http.createServer((req,res)=>{res.setHeader('Content-Type',req.url==='/agent-reminders.js'?'text/javascript':'text/html');res.end(req.url==='/agent-reminders.js'?script:html);});await new Promise(r=>server.listen(0,'127.0.0.1',r));
const browser=await chromium.launch({headless:true});let checks=0;const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
try{
 const page=await browser.newPage();let errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(`http://127.0.0.1:${server.address().port}`);await page.waitForFunction(()=>spoken.length===1);
 await check('due reminder uses existing bubble and voice adapter with literal title',async()=>{assert.deepEqual(await page.evaluate(()=>[bubbles,spoken]),[['Reminder #1: <b>Yoga</b>. Done, or snooze?'],['Reminder #1: <b>Yoga</b>. Done, or snooze?']]);assert.deepEqual(await page.evaluate(()=>calls.map(x=>x.action)),['claim','ack']);});
 await check('receipt prevents repeated poll from repeating speech',async()=>{await page.evaluate(()=>VP3AgentReminders.poll());assert.equal(await page.evaluate(()=>spoken.length),1);});
 await check('voice off still delivers visible reminder',async()=>{await page.evaluate(async()=>{VP3_AGENT_PREFERENCE_STATE.enabled=false;remaining=1;await VP3AgentReminders.poll();});assert.equal(await page.evaluate(()=>spoken.length),1);assert.equal(await page.evaluate(()=>bubbles.length),2);});
 await check('busy voice or typed draft defers claim',async()=>{const before=await page.evaluate(()=>calls.length);await page.evaluate(async()=>{busy=true;remaining=1;await VP3AgentReminders.poll();});assert.equal(await page.evaluate(()=>calls.length),before);await page.evaluate(()=>busy=false);});
 await check('hidden page does not claim delivery',async()=>{const before=await page.evaluate(()=>calls.length);await page.evaluate(async()=>{hiddenFixture=true;await VP3AgentReminders.poll();});assert.equal(await page.evaluate(()=>calls.length),before);await page.evaluate(()=>hiddenFixture=false);});
 await check('cancelled lease does not speak or display',async()=>{await page.evaluate(async()=>{disabledAck=true;remaining=1;await VP3AgentReminders.poll();});assert.equal(await page.evaluate(()=>bubbles.length),2);await page.evaluate(()=>disabledAck=false);});
 await check('Stop fences a late delivery response',async()=>{await page.evaluate(()=>{window.waitClaim=true;void VP3AgentReminders.poll();});await page.waitForFunction(()=>typeof releaseClaim==='function');await page.locator('[data-orb-stop]').click();await page.evaluate(()=>releaseClaim({ok:true,item:{id:2,token:'b'.repeat(32),text:'late'}}));await page.waitForTimeout(30);assert.equal(await page.evaluate(()=>bubbles.length),2);});
 await check('page unload stops polling',async()=>{await page.evaluate(()=>window.dispatchEvent(new Event('pagehide')));const before=await page.evaluate(()=>calls.length);await page.evaluate(()=>VP3AgentReminders.poll());assert.equal(await page.evaluate(()=>calls.length),before);});
 assert.deepEqual(errors,[]);console.log(`AGENT_REMINDERS_BROWSER=PASS (${checks} cases)`);
}finally{await browser.close();await new Promise(r=>server.close(r));}
