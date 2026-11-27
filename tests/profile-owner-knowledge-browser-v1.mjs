import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';

const {chromium}=createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const script=fs.readFileSync(new URL('../profile-owner-knowledge-v1.js',import.meta.url),'utf8');
const server=http.createServer((req,res)=>{res.writeHead(200,{'Content-Type':'text/html'});res.end(`<!doctype html><meta charset="utf-8"><div id="actions"></div><script>${script}</script><script>window.__source={conversation_id:101,owner_request_id:51,context:'42:7'};window.__knowledge=window.VP3ProfileOwnerKnowledgeV1.attach(document.getElementById('actions'),{endpoint:'/api/profile-agent.php',csrf:'fixture',source:()=>window.__source});window.__select=value=>{window.__source=value;window.__knowledge.refresh();};</script>`);});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base=`http://127.0.0.1:${server.address().port}`,contract='vp3.profile-knowledge.v1';
let browser,checks=0;const check=(value,message)=>{assert.ok(value,message);checks++;console.log('PASS '+message);};
const copy=value=>JSON.parse(JSON.stringify(value));
const preview=(cid=101,rid=51)=>({contract,conversation_id:cid,owner_request_id:rid,visitor_message_id:cid+100,reply_message_id:cid+200,source_revision:'a'.repeat(64),question:'Can I use <img src=x onerror=alert(1)> in my product?',reply:'Only with the agreed permission.',title:'Product permission',content:'Question: Can I use this in my product?\n\nAnswer: Only with the agreed permission.',folders:[{id:5,name:'Customer questions'},{id:9,name:'<Private folder>'}],saved:null});
async function fixture(){
  const page=await browser.newPage({viewport:{width:390,height:900}}),calls=[],holds=[],errors=[],sources=new Map([[101,preview()],[202,preview(202,52)]]),saved=new Map();
  page.on('pageerror',error=>errors.push(error.message));page.setDefaultTimeout(10000);
  await page.route('**/api/profile-agent.php*',async route=>{
    const request=route.request(),input=request.method()==='POST'?request.postDataJSON():Object.fromEntries(new URL(request.url()).searchParams);calls.push(input);
    let payload={ok:true};const source=sources.get(Number(input.conversation_id));
    if(input.action==='owner_knowledge_preview')payload.preview={...copy(source),saved:saved.get(Number(input.conversation_id))||null};
    else if(input.action==='owner_knowledge_save'){
      assert.equal(input.owner_request_id,source.owner_request_id);assert.equal(input.source_revision,source.source_revision);
      assert.ok(!Object.hasOwn(input,'approve_profile_use')&&!Object.hasOwn(input,'audience_scope'),'saving Knowledge does not submit sharing settings');
      const existing=saved.get(input.conversation_id),item=existing||{knowledge_id:9001,title:input.title,folder_id:input.folder_id,folder_name:source.folders.find(folder=>folder.id===input.folder_id)?.name||'Unfiled',view_url:'/knowledge.php?edit=9001#knowledge-form'};
      saved.set(input.conversation_id,item);payload={ok:true,contract,conversation_id:source.conversation_id,owner_request_id:source.owner_request_id,visitor_message_id:source.visitor_message_id,reply_message_id:source.reply_message_id,source_revision:source.source_revision,saved:item,created:!existing};
    }else throw new Error('Unexpected action '+input.action);
    const index=holds.findIndex(hold=>hold.action===input.action);
    if(index<0){await route.fulfill({json:payload});return;}
    const hold=holds.splice(index,1)[0];hold.arrived({input,payload,async release(overrides={}){await route.fulfill({json:{...payload,...overrides}}).catch(()=>{});},async fail(){await route.abort('failed');}});
  });
  await page.goto(base+'/');
  return {page,calls,sources,saved,hold:action=>new Promise(arrived=>holds.push({action,arrived})),async open(){await page.locator('[data-profile-knowledge-open]').click();await page.locator('[data-profile-knowledge-form]').waitFor();},async select(value){await page.evaluate(value=>window.__select(value),value);},async cancel(){await page.getByRole('dialog').getByRole('button',{name:'Cancel',exact:true}).click();},async close(){check(errors.length===0,'Knowledge editor has no browser errors: '+errors.join('; '));await page.close();}};
}
try{
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
  {
    const f=await fixture(),{page}=f;
    check(f.calls.length===0,'showing the contextual action does not read or save Knowledge');
    await f.select(null);check(await page.locator('[data-profile-knowledge-open]').isHidden(),'unanswered or unavailable source exposes no Knowledge action');
    await f.select({conversation_id:101,owner_request_id:51,context:'42:7'});await f.open();
    check(f.calls[0].conversation_id==='101'&&f.calls[0].owner_request_id==='51'&&f.calls[0].action==='owner_knowledge_preview','review begins with a read for the exact answered visitor request');
    check(await page.locator('[name="folder_id"] option').allTextContents().then(values=>values.join('|')==='Unfiled|Customer questions|<Private folder>'),'review offers canonical existing folders and Unfiled');
    await page.locator('summary').click();
    check(await page.getByRole('dialog').locator('img').count()===0&&(await page.getByRole('dialog').innerText()).includes('<img src=x onerror=alert(1)>'),'source question and folder names render as inert text');
    check(await page.getByRole('dialog').locator('input[type="checkbox"]').count()===1&&await page.locator('[name="approve_profile_use"]').count()===0&&await page.locator('button[type="submit"]').isDisabled(),'saving requires content review and exposes no overlapping sharing control');
    check(await page.getByRole('link',{name:'Open Knowledge Access (new tab)',exact:true}).getAttribute('href')===base+'/profile-agent.php?tab=knowledge'&&(await page.getByRole('dialog').innerText()).includes('Profile Agent use follows your existing Knowledge Access settings.'),'review links to the existing Knowledge Access tab as the permission control');
    await page.locator('[name="title"]').fill('Reviewed product permission');await page.locator('[name="content"]').fill('Reviewed question and answer, with personal details removed.');await page.locator('[name="folder_id"]').selectOption('5');await page.locator('[name="approve_content"]').check();
    const hold=f.hold('owner_knowledge_save');await page.locator('button[type="submit"]').click();const save=await hold;
    check(save.input.title==='Reviewed product permission'&&save.input.folder_id===5&&save.input.approve_content===true&&!Object.hasOwn(save.input,'approve_profile_use')&&!Object.hasOwn(save.input,'audience_scope')&&save.input.source_revision==='a'.repeat(64),'save submits reviewed content, selected folder and exact source revision without changing permissions');
    check(await page.locator('[name="content"]').isDisabled()&&await page.getByRole('button',{name:'Cancel',exact:true}).isDisabled(),'pending save prevents a second submission and retains the prepared note');
    await save.release();await page.getByRole('link',{name:'Open Reviewed product permission'}).waitFor();
    check(f.sources.get(101).reply==='Only with the agreed permission.'&&f.saved.size===1,'saving the edited Knowledge note leaves the original owner reply unchanged');
    check((await page.getByRole('dialog').innerText()).includes('Profile Agent use follows your existing Knowledge Access settings.')&&await page.getByRole('link',{name:'Open Knowledge Access (new tab)',exact:true}).isVisible(),'confirmed save retains the existing Knowledge Access notice and link alongside the saved note');
    await page.getByRole('button',{name:'Close',exact:true}).click();await page.locator('[data-profile-knowledge-open]').click();await page.getByRole('link',{name:'Open Reviewed product permission'}).waitFor();
    check(f.calls.filter(call=>call.action==='owner_knowledge_save').length===1&&await page.locator('[data-profile-knowledge-form]').count()===0,'reopening an already saved answer returns its existing Knowledge link without creating or overwriting a note');
    await f.close();
  }
  {
    const f=await fixture(),{page}=f;await f.open();await page.locator('[name="content"]').fill('Reusable reviewed answer');await page.locator('[name="folder_id"]').selectOption('9');
    await f.cancel();await f.open();
    check(await page.locator('[name="content"]').inputValue()==='Reusable reviewed answer'&&await page.locator('[name="folder_id"]').inputValue()==='9'&&await page.locator('[name="approve_content"]').isChecked()===false,'closing and reopening retains note edits and folder while requiring a fresh review');
    await page.locator('[name="approve_content"]').check();const hold=f.hold('owner_knowledge_save');await page.locator('button[type="submit"]').click();const save=await hold;
    check(!Object.hasOwn(save.input,'approve_profile_use')&&!Object.hasOwn(save.input,'audience_scope')&&save.input.approve_content===true,'recovered draft saves retain review approval without introducing a permission change');
    await save.fail();await page.waitForFunction(()=>!document.querySelector('button[type="submit"]')?.disabled);
    check(await page.locator('[name="content"]').inputValue()==='Reusable reviewed answer'&&(await page.getByRole('dialog').innerText()).includes('Saving could not be confirmed'),'a lost save acknowledgment retains the reviewed note without claiming success');
    await f.cancel();await page.locator('[data-profile-knowledge-open]').click();await page.getByRole('link',{name:'Open Product permission'}).waitFor();
    check(f.calls.filter(call=>call.action==='owner_knowledge_save').length===1&&f.saved.size===1&&(await page.getByRole('dialog').innerText()).includes('Profile Agent use follows your existing Knowledge Access settings.'),'read-only recovery finds the saved note without a second creation or permission change');
    await f.close();
  }
  {
    const f=await fixture(),{page}=f,hold=f.hold('owner_knowledge_preview');await page.locator('[data-profile-knowledge-open]').click();const read=await hold;
    await f.select({conversation_id:202,owner_request_id:52,context:'55:7'});await read.release();
    check(await page.getByRole('dialog').count()===0,'changing visitor or private Chat closes the old review and rejects its late read');
    await f.open();check(f.calls.at(-1).conversation_id==='202'&&await page.locator('[data-profile-knowledge-form]').count()===1,'the new context opens only its own source and folders');await f.cancel();
    const bad=f.hold('owner_knowledge_preview');await page.locator('[data-profile-knowledge-open]').click();const wrong=await bad;await wrong.release({preview:preview(101,51)});
    await page.getByText('The source answer or Knowledge folders could not be verified.',{exact:true}).waitFor();
    check(await page.locator('[data-profile-knowledge-form]').count()===0&&!f.calls.some(call=>call.action==='owner_knowledge_save'),'mismatched source identity cannot create a writable Knowledge form');await f.close();
  }
  {
    const f=await fixture(),{page}=f;await f.open();await page.locator('[name="content"]').fill('Preserved draft for a revised answer');await f.cancel();f.sources.get(101).source_revision='b'.repeat(64);f.sources.get(101).reply='Updated owner answer';await f.open();
    check(await page.locator('[name="content"]').inputValue()==='Preserved draft for a revised answer'&&(await page.getByRole('dialog').innerText()).includes('owner answer changed')&&await page.locator('button[type="submit"]').isDisabled(),'a changed source retains local edits and requires review against the current answer');
    await page.locator('[name="approve_content"]').check();const hold=f.hold('owner_knowledge_save');await page.locator('button[type="submit"]').click();const save=await hold;
    await f.select({conversation_id:202,owner_request_id:52,context:'55:7'});await save.release();await f.open();
    check(f.calls.filter(call=>call.action==='owner_knowledge_save').length===1&&await page.locator('[name="content"]').inputValue()===f.sources.get(202).content,'late old-context save cannot overwrite the new visitor editor or submit its draft');await f.close();
  }
  {
    const f=await fixture(),{page}=f;await page.clock.install();await f.open();await page.locator('[name="approve_content"]').check();
    const hold=f.hold('owner_knowledge_save');await page.locator('button[type="submit"]').click();const saving=await hold;await page.clock.runFor(15001);
    check(await page.locator('button[type="submit"]').isEnabled()&&(await page.getByRole('dialog').innerText()).includes('Saving could not be confirmed')&&await page.locator('[name="content"]').inputValue()===f.sources.get(101).content,'a stalled save has a bounded wait and keeps the reviewed note available for recovery');
    await f.cancel();await saving.release();
    check(await page.getByRole('dialog').count()===0&&f.calls.filter(call=>call.action==='owner_knowledge_save').length===1,'late timeout completion cannot reopen the editor or automatically repeat the save');await f.close();
  }
  console.log(`PROFILE_OWNER_KNOWLEDGE_BROWSER_V1=PASS ${checks} checks`);
}finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
