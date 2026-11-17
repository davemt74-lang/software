import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser = await chromium.launch({headless:true,args:['--no-sandbox'],...(executablePath?{executablePath}:{})});
const page = await browser.newPage();
const source = await fs.readFile(new URL('../video-meetings-agenda-v18140.js',import.meta.url),'utf8');
const fixture = fileURLToPath(new URL('./fixtures/meeting-agenda-research-page-v1.php',import.meta.url));
const meeting = 'f'.repeat(32);
const origin = 'https://agenda-research.test';
const requests = [];
const errors = [];
let organizer = true;
let meetingStatus = 'scheduled';
let research = {7942:{state:'queued'}};
page.on('pageerror',error=>errors.push(error.message));

await page.route('**/*',route=>route.fulfill({status:204,body:''}));
await page.route('**/meeting.php?*',route=>{
  const request = new URL(route.request().url());
  const body = execFileSync(process.env.VP3_PHP_BINARY||'php',[
    fixture,organizer?'organizer':'attendee',request.searchParams.toString(),meetingStatus,
  ],{encoding:'utf8'});
  return route.fulfill({contentType:'text/html',body});
});
await page.route('**/video-meetings-agenda-v18140.js?*',route=>route.fulfill({contentType:'application/javascript',body:source}));
await page.route('**/api/video-meeting-agenda.php',route=>{
  const request = route.request();
  const payload = Object.fromEntries(new URLSearchParams(request.postData()));
  assert.equal(request.method(),'POST');
  assert.deepEqual(payload,{action:payload.action,meeting,csrf_token:'fixture'});
  requests.push(payload.action);
  if(payload.action==='state'){
    return route.fulfill({json:{ok:true,agenda:{items:[
      {id:7942,status:'open',item_type:'follow_up',item_text:'Exact supplier follow-up'},
      {id:7943,status:'open',item_type:'follow_up',item_text:'Unrelated follow-up'},
    ]}}});
  }
  assert.equal(payload.action,'research_status');
  return route.fulfill({json:{ok:true,research}});
});

try{
  await page.goto(`${origin}/meeting.php?meeting=${meeting}&review=1&view=agenda#agenda-item-7942`);
  const target = page.locator('#agenda-item-7942');
  const other = page.locator('#agenda-item-7943');
  await target.getByText('Research queued',{exact:true}).waitFor();
  assert.equal(await page.locator('#meetingLobby').count(),0);
  assert.equal(await page.locator('script[src*="livekit"]').count(),0);
  assert.equal(await page.locator('script[src*="video-meetings-intelligence-v1820"]').count(),0);
  assert.equal(await page.getByRole('link',{name:'Open meeting room →'}).getAttribute('href'),`/meeting.php?meeting=${meeting}`);
  assert.equal(await page.locator('#meetingPane-agenda').evaluate(pane=>pane.classList.contains('active')),true);
  assert.equal(await page.locator(':focus').getAttribute('id'),'agenda-item-7942');
  assert.equal(await other.locator('.meeting-agenda-research').count(),0);
  research={7942:{state:'running'}};
  await page.evaluate(()=>window.VP3MeetingAgenda18140.activate());
  await target.getByText('Research in progress',{exact:true}).waitFor();
  research={7942:{state:'ready'}};
  await page.evaluate(()=>window.VP3MeetingAgenda18140.activate());
  await target.getByText('Research ready',{exact:true}).waitFor();
  assert.equal(await target.getByRole('link',{name:'Open saved Chat result'}).count(),0);
  const chat = '/chat.php?agent=7&conversation_id=11&message_id=123';
  research={7942:{state:'ready',chat_url:chat}};
  await page.evaluate(()=>window.VP3MeetingAgenda18140.activate());
  const result = target.getByRole('link',{name:'Open saved Chat result'});
  await result.waitFor();
  assert.equal(await result.getAttribute('href'),origin+chat);
  assert.equal(await other.getByRole('link',{name:'Open saved Chat result'}).count(),0);
  research={};
  await page.evaluate(()=>window.VP3MeetingAgenda18140.activate());
  await result.waitFor({state:'detached'});

  meetingStatus='ended';
  await page.reload();
  await target.waitFor();
  assert.equal(await page.getByRole('link',{name:'Open meeting intelligence →'}).count(),1);
  assert.equal(await page.locator('script[src*="video-meetings-intelligence-v1820"]').count(),0);

  organizer=false;
  meetingStatus='scheduled';
  const calls=requests.length;
  await page.reload();
  assert.equal(await page.locator('#meetingPane-agenda').count(),0);
  assert.equal(await page.locator('#meetingLobby').count(),1);
  assert.equal(requests.length,calls);
  assert.deepEqual(errors,[]);
  console.log('MEETING_AGENDA_RESEARCH_BROWSER=PASS (exact fragment, organizer-only status, saved Chat link and stale link withdrawal)');
}finally{
  await browser.close();
}
