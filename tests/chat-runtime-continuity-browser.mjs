import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const files = ['chat.js','chat-voice.js','chat-cognitive-presentation-v510.js'];
const sources = Object.fromEntries(await Promise.all(files.map(async name =>
  [name,await fs.readFile(new URL('../' + name,import.meta.url),'utf8')])));
const browser = await chromium.launch({headless:true,
  executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
const origin = 'https://chat-runtime-continuity.test';

function gate() {
  let arrived, release;
  return {arrived:new Promise(resolve => { arrived = resolve; }),
    released:new Promise(resolve => { release = resolve; }), arrive:() => arrived(), release:() => release()};
}

try {
  for (const agentId of [0,17]) for (const voice of ['unsupported','off','on']) {
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(8000);
    const errors = [], reads = [], sends = [], writes = [];
    page.on('pageerror',error => errors.push(error.message));
    const restored = gate();
    let holdRead = null, holdSend = null, failRead = false;
    const histories = new Map([[42,[{id:1,role:'assistant',message:'Restored private conversation'}]],
      [43,[{id:2,role:'assistant',message:'Another private conversation'}]]]);
    const attention = id => ({key:createHash('sha256').update(`${agentId}:${id}`).digest('hex'),
      title:`Review the blocked Mission for Chat ${id}`,summary:'A specialist needs your approval.',
      href:'/missions.php?mission=12',action_label:'Open Mission'});
    await context.addInitScript(({voice}) => {
      window.speechCalls = 0;
      window.announcementCalls = 0;
      window.recognizers = [];
      Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},speak(utterance){
        window.speechCalls++;
        queueMicrotask(() => { utterance.onstart?.(); utterance.onend?.(); });
      }}});
      window.SpeechSynthesisUtterance = class { constructor(text){ this.text = text; } };
      window.STONEFELLOW_NOTIFICATION_CENTER = {cancelSpeech(){},announceWhen(){window.announcementCalls++;return false;}};
      window.StonefellowPremiumVoiceV122 = () => ({warm:async () => false,stop(){},createStream(){}});
      if (voice === 'unsupported') {
        window.SpeechRecognition = undefined;
        window.webkitSpeechRecognition = undefined;
      } else {
        window.SpeechRecognition = class {
          constructor(){window.recognizers.push(this);}
          start(){this.active = true;queueMicrotask(() => this.onstart?.());}
          stop(){this.active = false;queueMicrotask(() => this.onend?.());}
          abort(){this.stop();}
        };
      }
    },{voice});
    await context.route(`${origin}/**`,async route => {
      const request = route.request(), url = new URL(request.url());
      if (url.pathname === '/') return route.fulfill({contentType:'text/html',body:`<!doctype html><html lang="en">
        <meta charset="utf-8"><style>[hidden]{display:none!important}</style>
        <button id="newChatButton">New Chat</button><div id="chatHistory"></div>
        <button id="chatAgentBriefButton">Agent Brief</button>
        <aside id="chatAgentBriefPopover" hidden><div data-agent-brief-content></div><footer></footer></aside>
        <section id="chatThread" data-view="chat"><div id="chatWelcome" hidden></div><section id="chatLiveContext" hidden></section></section>
        <form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button>
        <button id="chatVoiceButton" type="button">Voice</button></form><div id="chatVoiceStatus"></div>
        <script>
          window.STONEFELLOW_CHAT={userId:1,agentId:${agentId},initialConversationId:42,initialView:'chat',
            endpoint:'/api/chat-v236.php${agentId ? '?agent=' + agentId : ''}',csrf:'fixture',workingAttentionBinding:'owner-a-session'};
          window.VP3_COGNITIVE_PRESENTATION_V510={agentId:${agentId},workingAttentionBinding:'owner-a-session',liveContext:true};
        </script><script src="/chat.js"></script><script src="/chat-voice.js"></script>
        <script src="/chat-cognitive-presentation-v510.js"></script></html>`});
      if (sources[url.pathname.slice(1)]) return route.fulfill({contentType:'text/javascript',body:sources[url.pathname.slice(1)]});
      if (url.pathname === '/api/cognitive-presentation-v510.php') {
        if (request.method() === 'POST') {
          writes.push(request.postDataJSON());
          return route.fulfill({json:{ok:true}});
        }
        const read = {agent:Number(url.searchParams.get('agent_id')),
          id:url.searchParams.get('conversation_id'),live:url.searchParams.get('include_live_context'),
          working:url.searchParams.get('include_working_attention'),binding:request.headers()['x-vp3-working-attention']};
        reads.push(read);
        assert.equal(read.agent,agentId);
        assert.equal(read.binding,'owner-a-session');
        assert.equal(read.working,'0');
        if (failRead) return route.abort('internetdisconnected');
        const state = {brief:{agent_name:agentId ? 'Longfellow' : 'VP3 Agent',active:true,status_label:'Active'},
          ...(read.live === '1' && histories.has(Number(read.id)) ? {live_context:{state:'current',
            attention:attention(Number(read.id)),opening:true,initiative:{state:'off'}}} : {})};
        const wait = holdRead;holdRead = null;
        if (wait) { wait.arrive();await wait.released; }
        return route.fulfill({json:{ok:true,state}}).catch(() => {});
      }
      if (url.pathname !== '/api/chat-v236.php') return route.fulfill({status:404});
      const body = request.postDataJSON(), id = Number(body.conversation_id || 42);
      const ok = payload => route.fulfill({json:{ok:true,...payload}});
      if (body.action === 'load' && id === 42 && !reads.some(read => read.live === '1')) {
        restored.arrive();await restored.released;
      }
      if (body.action === 'send') {
        assert.equal(Number(url.searchParams.get('agent') || 0),agentId);
        assert.equal(request.headers()['x-vp3-working-attention'],'owner-a-session');
        sends.push(body);
        const wait = holdSend;holdSend = null;
        if (wait) {wait.arrive();await wait.released;}
        const messages = histories.get(id), next = messages.at(-1).id + 1;
        const answer = 'Verified reply to the visible Mission';
        messages.push({id:next,role:'user',message:body.message},{id:next + 1,role:'assistant',message:answer});
        return ok({conversation_id:id,user_message_id:next,assistant_message_id:next + 1,answer});
      }
      if (body.action === 'activity') return ok({conversation_id:id,latest_message_id:histories.get(id).at(-1).id,
        latest_id:0,unread_count:0,updates:[]});
      if (body.action === 'messages_after') return ok({messages:histories.get(id).filter(item => item.id > body.after_id)});
      return ok({messages:body.action === 'load' ? histories.get(id) : [],
        conversations:[{id:42,title:'Private Chat'},{id:43,title:'Another private Chat'}]});
    });
    try {
      await page.goto(origin,{waitUntil:'domcontentloaded'});
      await restored.arrived;
      await page.waitForFunction(() => Boolean(window.VP3_COGNITIVE_PRESENTATION));
      const restoreContract = await page.evaluate(() => {
        const canonical = window.STONEFELLOW_CHAT_CONTINUITY_V87, facade = window.STONEFELLOW_CHAT_CONTINUITY;
        return {missing:Object.keys(canonical).filter(key => typeof facade[key] !== typeof canonical[key]),
          restoring:facade.isRestoring?.(),busy:facade.isBusy(),ready:facade.isReady?.(),agent:facade.agentId?.()};
      });
      assert.deepEqual(restoreContract.missing,[]);
      assert.equal(restoreContract.restoring,true);
      assert.equal(restoreContract.busy,true);
      assert.equal(restoreContract.ready,false);
      assert.equal(restoreContract.agent,agentId);
      assert.ok(reads.every(read => read.id === null && read.live === null));
      restored.release();
      const card = page.locator('#chatLiveContext');
      await card.getByText(attention(42).title,{exact:true}).waitFor();
      assert.equal(await page.locator('[data-agent-live-attention]').count(),1);
      assert.ok(reads.some(read => read.live === '1' && read.id === '42'));
      assert.deepEqual(await page.evaluate(() => ({speech:window.speechCalls,announcements:window.announcementCalls,
        ready:window.STONEFELLOW_CHAT_CONTINUITY.isReady(),restoring:window.STONEFELLOW_CHAT_CONTINUITY.isRestoring()})),
      {speech:0,announcements:0,ready:true,restoring:false});

      if (voice === 'on') {
        await page.getByRole('button',{name:'Start voice conversation'}).click();
        await page.waitForFunction(() => window.recognizers.some(item => item.active));
        assert.equal(await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.isVoice()),true);
        await page.evaluate(() => window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',
          {detail:{state:'start',text:'Externally controlled speech'}})));
        assert.deepEqual(await page.evaluate(() => ({voice:window.STONEFELLOW_CHAT_CONTINUITY.isBusy(),
          canonical:window.STONEFELLOW_CHAT_CONTINUITY_V87.isBusy()})),{voice:true,canonical:false});
        await page.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.refresh());
        await card.waitFor({state:'hidden'});
        await page.evaluate(() => {
          window.dispatchEvent(new CustomEvent('stonefellow:agent-proactive-speech',{detail:{state:'end'}}));
          window.VP3ConversationControls.stop();
        });
        await page.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.refresh());
        await card.getByText(attention(42).title,{exact:true}).waitFor();
      }

      // A cached deployment with the older voice facade must also remain compatible.
      await page.evaluate(() => {
        delete window.STONEFELLOW_CHAT_CONTINUITY.isReady;
        delete window.STONEFELLOW_CHAT_CONTINUITY.isRestoring;
        delete window.STONEFELLOW_CHAT_CONTINUITY.agentId;
      });
      await page.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.refresh());
      await card.getByText(attention(42).title,{exact:true}).waitFor();
      holdSend = gate();const sending = holdSend;
      await page.locator('#chatInput').fill('Help me with that');
      await page.getByRole('button',{name:'Send',exact:true}).click();
      await sending.arrived;
      assert.equal(await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.isBusy()),true);
      sending.release();
      await page.getByText('Verified reply to the visible Mission',{exact:true}).waitFor();
      assert.equal(sends.length,1);
      assert.equal(sends[0].working_context_ref,attention(42).key);
      assert.equal(sends[0].conversation_id,42);
      assert.equal(await page.evaluate(() => window.speechCalls),0);

      // An authorized old response cannot repopulate a newly selected conversation.
      holdRead = gate();const switching = holdRead;
      const oldRead = page.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.refresh());
      await switching.arrived;
      await page.evaluate(() => window.STONEFELLOW_CHAT_CONTINUITY.openConversation(43));
      await card.waitFor({state:'hidden'});
      switching.release();await oldRead;
      await card.getByText(attention(43).title,{exact:true}).waitFor();
      assert.equal(await page.getByText(attention(42).title,{exact:true}).count(),0);
      assert.equal(await page.locator('[data-agent-live-attention]').count(),1);

      failRead = true;
      await page.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.refresh());
      await card.waitFor({state:'hidden'});
      assert.equal(await page.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.followUpReference(43)),null);
      failRead = false;
      await page.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.refresh());
      await card.getByText(attention(43).title,{exact:true}).waitFor();

      // A successful old-account response is stale even if the server still accepts its binding.
      holdRead = gate();const replacing = holdRead;
      const oldAccountRead = page.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.refresh());
      await replacing.arrived;
      await page.evaluate(voice => {
        if (voice === 'unsupported') window.VP3_COGNITIVE_PRESENTATION_V510 =
          {...window.VP3_COGNITIVE_PRESENTATION_V510,workingAttentionBinding:'owner-b-session'};
        else window.STONEFELLOW_CHAT = {...window.STONEFELLOW_CHAT,userId:2,workingAttentionBinding:'owner-b-session'};
      },voice);
      const writesBefore = writes.length;
      replacing.release();await oldAccountRead;
      await card.waitFor({state:'hidden'});
      assert.equal(await page.evaluate(() => window.VP3_COGNITIVE_PRESENTATION.followUpReference(43)),null);
      await page.locator('#chatForm').dispatchEvent('submit');
      assert.equal(writes.length,writesBefore);
      assert.equal(await page.evaluate(() => window.speechCalls),0);
      assert.deepEqual(errors,[]);
      console.log(`PASS shipped Chat + Voice + presentation: ${agentId ? 'named Agent' : 'System Agent'}, voice ${voice}; restored contract, exact live source, follow-up, busy and stale-account fences`);
    } finally {
      restored.release();holdRead?.release();holdSend?.release();
      await context.close();
    }
  }
} finally {
  await browser.close();
}
