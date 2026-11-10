import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const source=name=>fs.readFile(new URL('../'+name,import.meta.url),'utf8');
const browser=await chromium.launch({headless:true,args:['--no-sandbox'],
  executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH||undefined});
const errors=[],requests=[];
let checks=0;
const check=async(name,run)=>{await run();checks++;console.log('PASS '+name);};

async function fixture(shape) {
  const page=await browser.newPage({viewport:{width:1280,height:850}});
  page.setDefaultTimeout(10000);
  page.on('pageerror',error=>{errors.push(error.message);if(process.env.VP3_TEST_DEBUG)console.error(error.message);});
  let releaseSend=null;
  await page.route('https://presence.test/**',async route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:`<!doctype html>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
      <div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
      <main class="chat-main"><header class="chat-topbar">Agent Chat</header>
      <section class="chat-thread" id="chatThread"><div id="chatWelcome" hidden></div></section>
      <div class="chat-composer-shell" id="chatComposerShell"><form class="chat-composer" id="chatForm">
      <textarea id="chatInput" aria-label="Message"></textarea>
      <button id="chatVoiceButton" type="button">Listen</button><button id="sendChatButton" type="submit">Send</button>
      </form><div id="chatVoiceStatus" class="chat-voice-status" hidden></div></div></main></div>`});
    if(url.pathname.startsWith('/api/')) {
      const body=request.postDataJSON()||{};
      requests.push({shape,path:url.pathname,...body});
      if(body.action==='send') {
        await new Promise(resolve=>{releaseSend=resolve;});
        return route.fulfill({json:{ok:true,conversation_id:42,answer:'The workshop sources are ready.',
          user_message_id:101,assistant_message_id:102}});
      }
      return route.fulfill({json:{ok:true,messages:[],conversations:[],items:[],updates:[],folders:[]}});
    }
    return route.fulfill({status:404,body:''});
  });
  await page.goto('https://presence.test/');
  await page.evaluate(shape=>{
    window.STONEFELLOW_CHAT={userId:1,agentId:7,endpoint:'/api/chat.php',csrf:'presence-fixture',
      initialView:'chat',initialConversationId:42,workingAttentionBinding:'presence-binding'};
    window.STONEFELLOW_AGENT_IDENTITY_V236={agentVoiceEnabled:true};
    window.STONEFELLOW_NOTIFICATION_CENTER={};
    window.VP3_ORB_CONFIG={ownerKey:'1',agentId:7,workingAttentionBinding:'presence-binding',shape,
      chatUrl:'/chat.php',chatEndpoint:'/api/chat.php',agentVoiceEnabled:true};
    window.recognizers=[];
    window.SpeechRecognition=class {
      constructor(){window.recognizers.push(this);}
      start(){this.active=true;queueMicrotask(()=>this.onstart?.());}
      stop(){this.active=false;queueMicrotask(()=>this.onend?.());}
      abort(){this.stop();}
    };
    // The browser capture boundary is deterministic; controller ownership,
    // recognizer callbacks and output lifecycle still use the shipped code.
    window.AudioContext=window.webkitAudioContext=undefined;
    Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
      getSupportedConstraints:()=>({}),getUserMedia:async()=>{
        const track={readyState:'live',stop(){this.readyState='ended';},addEventListener(){}};
        return {getTracks:()=>[track],getAudioTracks:()=>[track]};
      }}});
    window.StonefellowPremiumVoiceV122=()=>({unlock:async()=>true,warm:async()=>true,stop(){},
      speak(text,callbacks){window.presenceSpoken=text;window.finishPresenceSpeech=callbacks.onEnd;
        queueMicrotask(()=>callbacks.onStart?.());return Promise.resolve(true);}});
    localStorage.removeItem('stonefellow:voice-mode:1');
  },shape);
  for(const name of ['chat.css','agent-orb.css','agent-orb-particles.css'])
    await page.addStyleTag({content:await source(name)});
  // Actual conversation, voice controller, adapter and renderer. Only external
  // browser speech/audio services and HTTP responses are fixture implementations.
  for(const name of ['chat.js','voice-lease-v122.js','chat-voice.js','agent-eyes-core.js',
    'agent-orb-adapter.js','agent-orb.js','agent-orb-particles.js'])
    await page.addScriptTag({content:await source(name)});
  await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY?.isReady?.()===true&&!!window.VP3AgentOrb);
  await page.evaluate(()=>VP3AgentOrb.setJobs('initiative',[
    {id:'workflow:21',kind:'assessment',step:'Reviewing workshop opportunities',expiresAt:Date.now()+120000}]));
  await page.locator('[data-working="true"] [data-orb-work-tracks="1"]').waitFor();
  return {page,release:()=>{assert.ok(releaseSend,'The real Chat dispatch reached the API fixture.');const release=releaseSend;releaseSend=null;release();}};
}

async function presence(page,state,color) {
  await page.waitForFunction(state=>document.querySelector('.vp3-orb-root')?.dataset.state===state,state);
  const orb=page.locator('.vp3-orb-root');
  assert.equal(await orb.getAttribute('data-working'),'true');
  assert.equal(await page.locator('[data-orb-work-tracks="1"]').count(),1,'Foreground transitions preserve the independent work track.');
  assert.match(await page.locator('[data-orb-voice-state]').innerText(),new RegExp('^'+({idle:'Working',listening:'Listening · Working',thinking:'Thinking · Working',speaking:'Speaking · Working'})[state]+'$'));
  if(!color)return;
  const visual=await orb.evaluate(root=>{
    const ring=root.querySelector('[data-orb-ring="pulse"]'),style=getComputedStyle(ring);
    const canvas=document.createElement('canvas');canvas.width=canvas.height=1;
    const context=canvas.getContext('2d');context.fillStyle=style.borderTopColor;context.fillRect(0,0,1,1);
    return {rgb:[...context.getImageData(0,0,1,1).data].slice(0,3),animation:style.animationName,
      duration:style.animationDuration,visible:style.visibility,orb:getComputedStyle(root).getPropertyValue('--orb').trim()};
  });
  assert.equal(visual.orb,color,'The canonical state color remains available to both orb styles.');
  const expected=color==='#3599ff'?[53,153,255]:color==='#48dd89'?[72,221,137]:[123,200,255];
  expected.forEach((value,index)=>assert.ok(Math.abs(visual.rgb[index]-value)<=2,`Pulse color ${visual.rgb} follows ${state}.`));
  assert.notEqual(visual.animation,'none','The active foreground pulse is animated.');
  assert.notEqual(visual.duration,'0s');
  assert.equal(visual.visible,'visible');
  await page.waitForFunction(color=>{
    const canvas=document.querySelector('.vp3-orb-root .vp3-public-orb-points');
    if(!canvas)return false;
    const pixels=canvas.getContext('2d').getImageData(0,0,canvas.width,canvas.height).data;
    let matching=0;
    for(let i=0;i<pixels.length;i+=4)if(pixels[i+3]>40) {
      const [r,g,b]=pixels.slice(i,i+3);
      if(color==='#48dd89'?g>r+12&&g>b+12:b>r+30&&b>g+12)matching++;
    }
    return matching>20;
  },color);
  if(process.env.VP3_PRESENCE_SCREENSHOTS) {
    const shape=await orb.locator('[data-orb-shape]').getAttribute('data-orb-shape');
    const box=await orb.boundingBox(),viewport=page.viewportSize();
    const x=Math.max(0,box.x-40),y=Math.max(0,box.y-40);
    await page.screenshot({path:new URL(`../../presence-${shape}-${state}.png`,import.meta.url).pathname,
      clip:{x,y,width:Math.min(viewport.width-x,box.width+80),height:Math.min(viewport.height-y,box.height+80)}});
  }
}

try {
  for(const shape of ['orbit','particle_face']) {
    const {page,release}=await fixture(shape);
    const idle=await page.locator('.vp3-public-orb-points').evaluate(canvas=>{
      const data=canvas.getContext('2d').getImageData(0,0,canvas.width,canvas.height).data;
      const rgb=[0,0,0];let count=0;
      for(let i=0;i<data.length;i+=4)if(data[i+3]>40){for(let c=0;c<3;c++)rgb[c]+=data[i+c];count++;}
      return rgb.map(value=>value/count);
    });
    assert.ok(shape==='orbit'?Math.abs(idle[0]-idle[1])<2&&Math.abs(idle[1]-idle[2])<2:idle[2]>idle[0],
      'Idle keeps the silver sphere and original blue particle face distinct.');
    await check(`${shape}: typed request shows green Thinking through the real Chat and voice adapter`,async()=>{
      const sent=page.waitForRequest(request=>request.url().endsWith('/api/chat.php')&&request.postDataJSON()?.action==='send');
      await page.locator('#chatInput').fill('Compare the workshop sources');
      await page.locator('#chatForm').evaluate(form=>form.requestSubmit());
      await sent;
      await presence(page,'thinking','#48dd89');
      assert.equal(await page.evaluate(()=>VP3ConversationControls.state().state),'thinking');
      release();
      await presence(page,'idle');
    });
    await check(`${shape}: actual recognizer, spoken request and output restore blue Listening with Working intact`,async()=>{
      await page.locator('#chatVoiceButton').click();
      await presence(page,'listening','#3599ff');
      const sent=page.waitForRequest(request=>request.url().endsWith('/api/chat.php')&&request.postDataJSON()?.action==='send');
      await page.evaluate(()=>{
        const recognition=window.recognizers.findLast(item=>item.active);
        if(!recognition)throw Error('No actual controller-owned recognizer');
        const result=[{transcript:'Organize the workshop notes',confidence:.99}];result.isFinal=true;
        recognition.onresult({resultIndex:0,results:[result]});
      });
      const request=await sent;
      await presence(page,'thinking','#48dd89');
      assert.equal(request.postDataJSON().input_mode,'voice');
      release();
      await presence(page,'speaking','#7bc8ff');
      assert.equal(await page.evaluate(()=>window.presenceSpoken),'The workshop sources are ready.');
      await page.evaluate(()=>window.finishPresenceSpeech());
      await presence(page,'listening','#3599ff');
      await page.evaluate(()=>window.VP3ConversationControls.stop());
      await presence(page,'idle');
    });
    await page.close();
  }
  assert.equal(requests.filter(request=>request.action==='send').length,4,'Each typed/spoken request uses the canonical Chat dispatch once.');
  assert.deepEqual(errors,[]);
  console.log(`AGENT_PRESENCE_BROWSER=PASS (${checks} cases; actual Chat/voice/adapter, both styles, independent Working)`);
} finally {await browser.close();}
