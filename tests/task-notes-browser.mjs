import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
let checks=0;const check=(ok,why)=>{assert.ok(ok,why);checks++;console.log('PASS '+why);};
try{
 for(const viewport of [{width:1280,height:800},{width:390,height:844}]){
  const context=await browser.newContext({viewport,hasTouch:true}),page=await context.newPage(),errors=[];let blocked=false,completed=false,delayed=false,release,arrived;const delayedArrival=new Promise(resolve=>arrived=resolve);
  page.setDefaultTimeout(10000);page.on('pageerror',e=>errors.push(e.message));
  const js=await fs.readFile('chat-task-notes-v1.js','utf8'),css=await fs.readFile('chat-task-notes-v1.css','utf8');
  await page.route('http://notes.test/',r=>r.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}body{background:#17212c;color:white;font-family:system-ui}form{position:fixed;bottom:10px}</style><h1>Agent Chat</h1><form id="chatForm"><textarea id="chatInput"></textarea><button>Send</button></form><script>window.prompts=[];document.getElementById('chatForm').addEventListener('submit',e=>{e.preventDefault();prompts.push(document.getElementById('chatInput').value)});window.VP3_TASK_NOTES_V1={endpoint:'/api/notes',csrf:'test',userId:1,agentId:7};</script><script>${js}</script>`}));
  await page.route('**/api/notes',async r=>{const ref=r.request().postDataJSON().note;if(delayed){arrived();await new Promise(resolve=>release=resolve);}return blocked?r.fulfill({status:403,json:{ok:false,error:'Note access denied.'}}):r.fulfill({json:{ok:true,note:{kind:ref.kind,id:ref.id,title:ref.kind==='reminders'?'Reminders':'Prep',items:[{id:42,title:'<img src=x onerror=alert(1)> Pizza dough',status:completed?'completed':'open',done:completed}],truncated:false}}});});
  await page.goto('http://notes.test/');await page.evaluate(()=>VP3TaskNotes.consume([{kind:'task_list',id:1}]));await page.getByText('Pizza dough',{exact:false}).waitFor();
  check(await page.locator('.vp3-task-note img').count()===0,'note labels are text, including HTML-like task titles at '+viewport.width);
  check(await page.locator('.vp3-task-notes-shortcuts').count()===0&&await page.getByRole('button',{name:/^Show (?:reminders|prep list)$/i}).count()===0,'canvas has no fixed reminder or prep-list shortcut buttons at '+viewport.width);
  const note=page.locator('.vp3-task-note'),header=note.locator('header');const before=await note.boundingBox();
  await header.focus();await page.keyboard.press('ArrowLeft');let after=await note.boundingBox();check(after.x<before.x,'keyboard moves the note at '+viewport.width);
  const box=await header.boundingBox();await page.mouse.move(box.x+30,box.y+15);await page.mouse.down();await page.mouse.move(box.x+45,box.y+60);await page.mouse.up();after=await note.boundingBox();
  check(after.x>=0&&after.x+after.width<=viewport.width+1&&after.y>=62,'drag keeps the note inside the viewport at '+viewport.width);
  if(viewport.width<600){const touch=await context.newCDPSession(page),h=await header.boundingBox(),oldY=(await note.boundingBox()).y;await touch.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:h.x+40,y:h.y+15}]});await touch.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:h.x+40,y:h.y+55}]});await touch.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});check((await note.boundingBox()).y>oldY,'touch drag moves the mobile note');}
  await note.locator('input').click();check((await page.evaluate(()=>prompts.at(-1)))==='Mark task #42 completed','checkbox submits a task ID and waits for canonical completion at '+viewport.width);
  completed=true;await page.evaluate(()=>VP3TaskNotes.consume([]));await page.waitForFunction(()=>document.querySelector('.vp3-task-note-row')?.dataset.done==='true');check(await note.locator('input').isChecked(),'refresh reflects verified task status at '+viewport.width);
  await page.reload();await page.waitForFunction(()=>document.querySelector('.vp3-task-note-row')?.dataset.done==='true');check(await page.locator('.vp3-task-note').count()===1,'reload restores note references and rereads content at '+viewport.width);
  await note.getByRole('button',{name:'Collapse or expand note'}).click();check(await note.locator('.vp3-task-note-body').isHidden(),'collapse hides only note contents at '+viewport.width);
  await note.getByRole('button',{name:'Collapse or expand note'}).click();
  blocked=true;await note.getByRole('button',{name:'Refresh note'}).click();await page.getByText('Note access denied.').waitFor();check(await note.locator('.vp3-task-note-row').count()===0,'revoked access clears old task content at '+viewport.width);
  blocked=false;delayed=true;await note.getByRole('button',{name:'Refresh note'}).click();await page.waitForFunction(()=>document.querySelector('[role=status]')?.textContent==='Loading…');
  await delayedArrival;await note.getByRole('button',{name:'Close note'}).click();release();await page.waitForTimeout(60);check(await page.locator('.vp3-task-note').count()===0,'a late refresh cannot reopen a closed note at '+viewport.width);
  check(errors.length===0,'browser runtime has no page errors at '+viewport.width);await context.close();
 }
 console.log(`TASK_NOTES_BROWSER=PASS (${checks} cases; production renderer, controlled API)`);
}finally{await browser.close();}
