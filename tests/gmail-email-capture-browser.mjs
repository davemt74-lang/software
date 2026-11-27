import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
const {chromium}=await import(process.env.VP3_PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH,args:['--no-sandbox']});
const scripts=await Promise.all(['gmail-email-capture-v1.js','gmail-email-display-v1.js'].map(p=>fs.readFile(p,'utf8')));
const css=(await Promise.all(['gmail-email-capture-v1.css','gmail-email-display-v1.css'].map(p=>fs.readFile(p,'utf8')))).join('\n');
const analysis={coverage:'readable',summary:'Camera launch is on December 12.',extracted_text:'Camera launch December 12 at 10:00.',product_query:'Example Camera X2',
  dates:[{title:'Camera launch',start_local:'2026-12-12T10:00',end_local:'2026-12-12T11:00',timezone:'America/Phoenix',note:'Review the date.'},
    {title:'Offer deadline',start_local:'2026-12-14T16:00',end_local:'',timezone:'America/Phoenix',note:'Review the deadline.'}]};
let checks=0;
async function check(label,fn){await fn();checks++;console.log('PASS '+label);}
async function setup(viewport={width:1100,height:900},modal=false){
  const page=await browser.newPage({viewport}),errors=[],requests=[],records=new Map(),saves=new Map();
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://capture.test/**',async route=>{
    const request=route.request();
    if(request.method()==='POST'){
      const body=request.postDataJSON();requests.push(body);
      const denied=await page.evaluate(()=>window.denyStatus||0);if(denied)return route.fulfill({status:denied,json:{ok:false,error:'Email access changed.'}});
      assert.equal(body.owner_key,'1');assert.equal(body.agent_id,7);assert.equal(body.conversation_id,11);assert.equal(body.csrf_token,'capture-csrf');assert.equal(body.source_ref,'ab'.repeat(100));
      if(body.action==='analyze'){
        const value={id:body.request_id,state:'ready',reason:null,result:analysis,received_text:'',received_response_retained:true};records.set(body.request_id,value);
        if(await page.evaluate(()=>window.loseAnalysisBody)){await page.evaluate(()=>window.loseAnalysisBody=false);return route.fulfill({status:503,json:{ok:false,error:'Lost response'}});}
        return route.fulfill({json:{ok:true,capture:value}});
      }
      if(body.action==='get')return route.fulfill({json:{ok:true,capture:records.get(body.request_id)}});
      const hash=JSON.stringify(body),id=saves.get(hash)||saves.size+1;saves.set(hash,id);
      if(await page.evaluate(()=>window.loseSaveBody)){await page.evaluate(()=>window.loseSaveBody=false);return route.fulfill({status:503,json:{ok:false,error:'Lost save reply'}});}
      return route.fulfill({json:{ok:true,saved:{id,kind:body.action,message:body.action==='calendar'?'Calendar event saved.':'Reminder saved.'}}});
    }
    return route.fulfill({contentType:'text/html',body:'<!doctype html><html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="margin:16px;font-family:Arial"><input id="chatInput" aria-label="Chat draft"><main id="email"></main></body></html>'});
  });
  await page.goto('https://capture.test/');await page.addStyleTag({content:css});
  if(modal)await page.evaluate(()=>{const dialog=document.createElement('dialog');dialog.id='sourceReader';dialog.style.cssText='width:min(900px,calc(100vw - 24px));max-height:90vh;overflow:auto;box-sizing:border-box';const close=document.createElement('button');close.textContent='Close original email';close.onclick=()=>dialog.close();dialog.append(close,document.getElementById('email'));document.body.append(dialog);dialog.showModal();});
  await page.evaluate(()=>{
    window.cfg={owner_key:'1',agent_id:7,conversation_id:11};window.STONEFELLOW_CHAT={userId:1,agentId:7};
    window.VP3_CHAT_EMAIL_V1={ownerKey:'1',agentId:7,csrf:'capture-csrf',captureEndpoint:'/api/gmail-email-capture-v1.php'};
    window.STONEFELLOW_CHAT_CONTINUITY_V87={conversationId:()=>window.cfg.conversation_id,agentId:()=>window.cfg.agent_id,isReady:()=>true,isRestoring:()=>false,
      stageSpecialistPrompt:(text,cid)=>{const input=document.getElementById('chatInput');if(cid!==window.cfg.conversation_id||input.value)return false;input.value=text;return true;}};
    window.pickers=[];window.mediaOrder=[];window.stopped=0;window.popups=[];window.linkReceipts=[];
    window.open=(...args)=>{window.popups.push(args);return null;};
    window.CropTarget={fromElement:async element=>({element})};
    Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getDisplayMedia:async options=>{
      window.pickers.push({gesture:navigator.userActivation.isActive,options});
      if(window.deferPicker)await new Promise(resolve=>window.releasePicker=resolve);
      if(window.rejectPicker)throw new DOMException('Cancelled','NotAllowedError');
      const canvas=document.createElement('canvas');canvas.width=800;canvas.height=600;
      const ctx=canvas.getContext('2d');ctx.fillStyle='#800080';ctx.fillRect(0,0,800,600);
      const stream=canvas.captureStream(30),track=stream.getVideoTracks()[0],stop=track.stop.bind(track);
      track.getSettings=()=>({displaySurface:window.wrongSurface?'window':'browser'});
      track.cropTo=async target=>{
        window.mediaOrder.push('crop');assertTarget(target.element);
        const rect=target.element.getBoundingClientRect();canvas.width=Math.max(1,Math.round(rect.width));canvas.height=Math.max(1,Math.round(rect.height));
        ctx.fillStyle='#00a040';ctx.fillRect(0,0,canvas.width,canvas.height);window.croppedSize={width:canvas.width,height:canvas.height};
      };
      function assertTarget(el){if(!el?.matches('.vp3-email-capture-box')||!el.isConnected)throw Error('Wrong capture target');}
      const timer=setInterval(()=>{ctx.fillStyle=window.mediaOrder.includes('crop')?'#00a040':'#800080';ctx.fillRect(0,0,canvas.width,canvas.height);},30);
      track.stop=()=>{clearInterval(timer);window.stopped++;stop();};window.latestTrack=track;return stream;
    }}});
    const descriptor=Object.getOwnPropertyDescriptor(HTMLMediaElement.prototype,'srcObject');
    Object.defineProperty(HTMLMediaElement.prototype,'srcObject',{configurable:true,get:descriptor.get,set(value){if(value)window.mediaOrder.push('video');descriptor.set.call(this,value);}});
  });
  for(const content of scripts)await page.addScriptTag({content});
  await page.evaluate(()=>{
    window.emailMessage={subject:'Camera launch <script>bad()</script>',from:'sender@example.test',body:'Camera launch December 12 at 10:00. Example Camera X2.',capture_source:'ab'.repeat(100),
      display:{version:1,html:'<style>body{font:18px/1.6 Arial;padding:8px}p{margin:0 0 28px}a{color:blue}</style><h1 style="font-size:22px">Camera launch</h1><p id="date">December 12 at 10:00</p><p id="product">Example Camera X2</p><p id="outside">Outside this selected area</p><a href="https://product.example.test/item">Open product details</a>',remote_images:0}};
    VP3GmailEmailDisplayV1.mount(document.getElementById('email'),window.emailMessage,{onLink:(event,url)=>{event.preventDefault();window.linkReceipts.push(url);}});
  });
  await page.frameLocator('iframe').locator('#date').waitFor();
  await page.waitForFunction(()=>{const f=document.querySelector('iframe');return f?.contentDocument?.body&&f.clientHeight>=f.contentDocument.body.scrollHeight;});
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  return {page,errors,requests,records,saves};
}
async function dragRegion(page,selector='#date'){
  const box=await page.frameLocator('iframe').locator(selector).boundingBox();assert.ok(box);
  await page.getByRole('button',{name:'Capture area',exact:true}).click();
  await page.mouse.move(box.x+1,box.y+1);await page.mouse.down();await page.mouse.move(box.x+Math.min(box.width-2,330),box.y+box.height-1,{steps:6});await page.mouse.up();
  await page.getByRole('menu',{name:'Selected email actions'}).waitFor();
}
try{
  const {page,errors,requests,saves}=await setup();
  await check('opening email does not request capture, inference or external navigation',async()=>{assert.equal(await page.evaluate(()=>pickers.length+popups.length),0);assert.equal(requests.length,0);});
  await check('a trusted drag selects only visible text in the email region',async()=>{
    await dragRegion(page);await page.getByRole('menuitem',{name:'Add to calendar',exact:true}).click();
    const text=await page.getByRole('textbox',{name:'Selected email text'}).inputValue();assert.match(text,/December 12 at 10:00/);assert.doesNotMatch(text,/Outside|Example Camera/);
    assert.equal(requests.length,0);assert.equal(await page.locator('.vp3-email-capture-dialog script').count(),0);
  });
  await check('text analysis sends the selected excerpt with its exact private scope',async()=>{
    await page.getByRole('button',{name:'Analyze text (uses AI)',exact:true}).click();await page.getByText('Analysis ready. Review the details before saving an action.',{exact:true}).last().waitFor();
    const r=requests.find(r=>r.action==='analyze');assert.equal(r.mode,'text');assert.equal(r.image,'');assert.match(r.text,/December 12/);assert.doesNotMatch(r.text,/Outside/);
    if(process.env.VP3_GMAIL_CAPTURE_SCREENSHOTS){await fs.mkdir(process.env.VP3_GMAIL_CAPTURE_SCREENSHOTS,{recursive:true});await page.screenshot({path:process.env.VP3_GMAIL_CAPTURE_SCREENSHOTS+'/capture-desktop.png',fullPage:true});}
  });
  await check('calendar suggestions are editable and saved only by the explicit form',async()=>{
    assert.equal(saves.size,0);await page.getByRole('button',{name:'Add to calendar',exact:true}).click();
    assert.equal(await page.getByLabel('Date',{exact:true}).inputValue(),'2026-12-12');
    await page.getByLabel('Title',{exact:true}).fill('Reviewed camera launch');await page.getByRole('button',{name:'Save calendar event',exact:true}).click();
    await page.getByText('Calendar event saved.',{exact:true}).waitFor();assert.equal(saves.size,1);assert.equal(requests.at(-1).fields.title,'Reviewed camera launch');
  });
  await check('a lost save acknowledgement retries the same reviewed fields',async()=>{
    await page.getByRole('button',{name:'Set reminder',exact:true}).click();await page.getByLabel('Remind me at',{exact:true}).fill('2026-12-11T10:00');
    await page.evaluate(()=>window.loseSaveBody=true);await page.getByRole('button',{name:'Save reminder',exact:true}).click();await page.getByRole('button',{name:'Retry save',exact:true}).waitFor();
    const original=requests.at(-1);assert.equal(await page.getByLabel('Suggested date',{exact:true}).isDisabled(),true);await page.getByRole('button',{name:'Retry save',exact:true}).click();await page.getByText('Reminder saved.',{exact:true}).waitFor();assert.deepEqual(requests.at(-1),original);assert.equal(saves.size,2);
  });
  await check('multiple dates can be selected without silently saving another event',async()=>{
    await page.getByRole('button',{name:'Add to calendar',exact:true}).click();await page.getByLabel('Suggested date',{exact:true}).selectOption('1');
    assert.equal(await page.getByLabel('Title',{exact:true}).inputValue(),'Offer deadline');assert.equal(await page.getByLabel('Date',{exact:true}).inputValue(),'2026-12-14');assert.equal(await page.getByLabel('Start time',{exact:true}).inputValue(),'16:00');assert.equal(saves.size,2);
  });
  await check('native link receipt behavior survives capture controls',async()=>{
    await page.getByRole('button',{name:'Close',exact:true}).click();await page.frameLocator('iframe').getByRole('link',{name:'Open product details'}).click();assert.deepEqual(await page.evaluate(()=>linkReceipts),['https://product.example.test/item']);
  });
  await check('right-click and keyboard actions use selected text',async()=>{
    await page.frameLocator('iframe').locator('#product').evaluate(el=>{const range=document.createRange();range.selectNodeContents(el);const s=getSelection();s.removeAllRanges();s.addRange(range);});
    await page.frameLocator('iframe').locator('#product').click({button:'right'});await page.getByRole('menu').waitFor();await page.keyboard.press('End');assert.equal(await page.evaluate(()=>document.activeElement.textContent),'Search Amazon');await page.keyboard.press('Escape');assert.equal(await page.getByRole('menu').count(),0);
  });
  await check('research preserves an existing draft and stages only the reviewed search phrase',async()=>{
    await page.getByRole('button',{name:'Email actions',exact:true}).click();await page.getByRole('button',{name:'Research product in Chat',exact:true}).click();
    await page.getByLabel('Search phrase',{exact:true}).fill('Example Camera X2');await page.evaluate(()=>document.getElementById('chatInput').value='Keep my draft');
    await page.getByRole('button',{name:'Prepare in Chat',exact:true}).click();assert.equal(await page.getByLabel('Chat draft').inputValue(),'Keep my draft');
    await page.evaluate(()=>document.getElementById('chatInput').value='');await page.getByRole('button',{name:'Prepare in Chat',exact:true}).click();assert.equal(await page.getByLabel('Chat draft').inputValue(),'/research Example Camera X2');
  });
  await check('Amazon search requires its explicit reviewed action',async()=>{
    await page.getByRole('button',{name:'Email actions',exact:true}).click();await page.getByRole('button',{name:'Search Amazon',exact:true}).click();await page.getByLabel('Search phrase',{exact:true}).fill('Example Camera X2');
    await page.getByRole('button',{name:'Search Amazon',exact:true}).last().click();const result=await page.evaluate(()=>popups.at(-1));assert.equal(result[0],'https://www.amazon.com/s?k=Example+Camera+X2');assert.equal(result[2],'noopener,noreferrer');
    await page.getByRole('button',{name:'Close',exact:true}).click();
  });
  await check('Snap uses a trusted current-tab picker and stops after a cropped JPEG',async()=>{
    await dragRegion(page);await page.getByRole('menuitem',{name:'Snap photo',exact:true}).click();await page.getByAltText('Captured email area').waitFor();
    assert.equal(await page.evaluate(()=>pickers.at(-1).gesture),true);assert.equal(await page.evaluate(()=>pickers.at(-1).options.audio),false);
    assert.deepEqual(await page.evaluate(()=>mediaOrder.slice(-2)),['crop','video']);assert.ok(await page.evaluate(()=>stopped>=1));
    const pixel=await page.getByAltText('Captured email area').evaluate(async img=>{await img.decode();const c=document.createElement('canvas');c.width=c.height=1;c.getContext('2d').drawImage(img,0,0,1,1);return [...c.getContext('2d').getImageData(0,0,1,1).data];});assert.ok(pixel[1]>120&&pixel[0]<30,'Full uncropped purple frame must never enter the photo.');
  });
  await check('photo analysis sends only bounded JPEG pixels and no source URLs',async()=>{
    await page.getByRole('button',{name:'Analyze photo (uses AI)',exact:true}).click();await page.getByText('Analysis ready. Review the details before saving an action.',{exact:true}).last().waitFor();
    const r=requests.at(-1);assert.equal(r.mode,'photo');assert.match(r.image,/^data:image\/jpeg;base64,/);assert.ok(r.image.length<420000);assert.equal('url' in r,false);
  });
  await check('lost analysis body recovers the saved attempt without another inference',async()=>{
    await page.evaluate(()=>window.loseAnalysisBody=true);const before=requests.filter(r=>r.action==='analyze').length;
    await page.getByRole('button',{name:'Analyze text (uses AI)',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.vp3-email-capture-notice')?.textContent.startsWith('Analysis ready'));
    assert.equal(requests.filter(r=>r.action==='analyze').length,before+1);assert.equal(requests.at(-1).action,'get');
  });
  await check('private results and capture controls are removed on Chat change',async()=>{
    await page.getByRole('textbox',{name:'Selected email text'}).fill('');assert.equal(await page.getByRole('button',{name:'Analyze text (uses AI)',exact:true}).isDisabled(),true);
    const before=requests.length;await page.getByRole('textbox',{name:'Selected email text'}).fill('Reviewed excerpt');assert.equal(await page.getByRole('button',{name:'Analyze text (uses AI)',exact:true}).isEnabled(),true);assert.equal(requests.length,before);
    await page.evaluate(()=>{cfg.conversation_id=12;dispatchEvent(new Event('stonefellow:chat-context-changing'));});assert.equal(await page.locator('.vp3-email-capture-dialog,.vp3-email-capture-menu,.vp3-email-capture-box').count(),0);assert.equal(await page.locator('iframe').count(),0);
  });
  assert.deepEqual(errors,[]);await page.close();
  const mobile=await setup({width:390,height:844});
  await check('phone layout provides a visible Actions button and fits its dialog',async()=>{
    await mobile.page.getByRole('button',{name:'Email actions',exact:true}).click();const rect=await mobile.page.locator('.vp3-email-capture-dialog').boundingBox();assert.ok(rect.x>=0&&rect.x+rect.width<=391);assert.ok(await mobile.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    if(process.env.VP3_GMAIL_CAPTURE_SCREENSHOTS)await mobile.page.screenshot({path:process.env.VP3_GMAIL_CAPTURE_SCREENSHOTS+'/capture-phone.png',fullPage:true});
    await mobile.page.getByRole('button',{name:'Close',exact:true}).click();await mobile.page.getByRole('button',{name:'Plain text',exact:true}).click();await mobile.page.getByRole('button',{name:'Email actions',exact:true}).click();assert.match(await mobile.page.getByRole('textbox',{name:'Selected email text'}).inputValue(),/Camera launch/);
  });
  assert.deepEqual(mobile.errors,[]);await mobile.page.close();
  const late=await setup();
  await check('a late picker grant after closing the source releases every track',async()=>{
    await late.page.evaluate(()=>window.deferPicker=true);await dragRegion(late.page);await late.page.getByRole('menuitem',{name:'Snap photo',exact:true}).click();await late.page.waitForFunction(()=>typeof releasePicker==='function');
    await late.page.evaluate(()=>{dispatchEvent(new Event('stonefellow:chat-context-changing'));releasePicker();});await late.page.waitForFunction(()=>window.latestTrack?.readyState==='ended');assert.equal(late.requests.length,0);assert.equal(await late.page.locator('.vp3-email-capture-dialog').count(),0);
  });
  assert.deepEqual(late.errors,[]);await late.page.close();
  const wrong=await setup();
  await check('a non-tab share is rejected and stopped without photo inference',async()=>{
    await wrong.page.evaluate(()=>window.wrongSurface=true);await dragRegion(wrong.page);await wrong.page.getByRole('menuitem',{name:'Snap photo',exact:true}).click();
    await wrong.page.getByText('Choose this VP3 browser tab to capture the email area.',{exact:true}).last().waitFor();assert.equal(await wrong.page.evaluate(()=>latestTrack.readyState),'ended');assert.equal(wrong.requests.length,0);
  });
  await check('a cancelled picker leaves text actions available',async()=>{
    await wrong.page.getByRole('button',{name:'Close',exact:true}).click();await wrong.page.evaluate(()=>{window.wrongSurface=false;window.rejectPicker=true;});await dragRegion(wrong.page);await wrong.page.getByRole('menuitem',{name:'Snap photo',exact:true}).click();
    await wrong.page.getByText('Photo capture was cancelled.',{exact:true}).last().waitFor();assert.equal(await wrong.page.getByRole('button',{name:'Analyze text (uses AI)',exact:true}).isEnabled(),true);assert.equal(wrong.requests.length,0);
  });
  assert.deepEqual(wrong.errors,[]);await wrong.page.close();
  const hidden=await setup();
  await check('closing and reopening a reader restores fresh controls without the old selection',async()=>{
    await dragRegion(hidden.page);await hidden.page.keyboard.press('Escape');await hidden.page.evaluate(()=>document.getElementById('email').hidden=true);await hidden.page.waitForFunction(()=>document.querySelectorAll('.vp3-email-capture-box').length===0);
    await hidden.page.evaluate(()=>document.getElementById('email').hidden=false);await hidden.page.getByRole('button',{name:'Capture area',exact:true}).waitFor();assert.equal(await hidden.page.locator('.vp3-email-capture-box:not([hidden])').count(),0);
  });
  await check('revoked email access scrubs saved analysis and disables the old reader tools',async()=>{
    await hidden.page.getByRole('button',{name:'Email actions',exact:true}).click();await hidden.page.evaluate(()=>window.denyStatus=409);await hidden.page.getByRole('button',{name:'Analyze text (uses AI)',exact:true}).click();
    await hidden.page.getByText('Email access changed. Reopen the original email.',{exact:true}).waitFor();assert.equal(await hidden.page.locator('.vp3-email-capture-dialog,.vp3-email-capture-box').count(),0);assert.equal(await hidden.page.getByRole('button',{name:'Capture area',exact:true}).count(),0);
  });
  assert.deepEqual(hidden.errors,[]);await hidden.page.close();
  const sourceClosed=await setup({width:1100,height:900},true);
  await check('closing the original email fences a pending sharing grant',async()=>{
    await sourceClosed.page.evaluate(()=>window.deferPicker=true);await dragRegion(sourceClosed.page);await sourceClosed.page.getByRole('menuitem',{name:'Snap photo',exact:true}).click();await sourceClosed.page.waitForFunction(()=>typeof releasePicker==='function');
    await sourceClosed.page.getByRole('button',{name:'Close original email',exact:true}).click();await sourceClosed.page.evaluate(()=>releasePicker());await sourceClosed.page.waitForFunction(()=>window.latestTrack?.readyState==='ended');assert.equal(await sourceClosed.page.locator('.vp3-email-capture-dialog').count(),0);assert.equal(sourceClosed.requests.length,0);
  });
  assert.deepEqual(sourceClosed.errors,[]);await sourceClosed.page.close();
  console.log(`GMAIL_EMAIL_CAPTURE_BROWSER=PASS (${checks} workflows)`);
}finally{await browser.close();}
