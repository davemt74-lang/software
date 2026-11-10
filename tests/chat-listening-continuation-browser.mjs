import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const read = path => fs.readFile(new URL('../' + path, import.meta.url), 'utf8');
const template = await read('chat-legacy-v108.php');
const nowPlayingMarkup = template.slice(
  template.indexOf('<section\n        class="chat-now-playing"'),
  template.indexOf('<form class="chat-composer"')
);
const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head>
  <body><div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
  <main class="chat-main"><header>Agent Chat <button type="button" data-prompt="Catch me up" data-preserve-chat-draft>Catch me up</button></header><section class="chat-thread" id="chatThread">
  <section data-chat-view="player" hidden><article id="playerSong"><audio class="chat-audio-player" preload="none" data-track-id="9" data-player-title="Last song" data-player-album="Studio" src="/song.wav"></audio></article></section>
  <div id="chatWelcome" hidden></div></section>
  <div class="chat-composer-shell" id="chatComposerShell">${nowPlayingMarkup}<form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form>
  </div></main></div></body></html>`;

const pcm = Buffer.alloc(8000 * 2 * 60);
const wav = Buffer.alloc(44 + pcm.length);
wav.write('RIFF');
wav.writeUInt32LE(36 + pcm.length, 4);
wav.write('WAVEfmt ', 8);
wav.writeUInt32LE(16, 16);
wav.writeUInt16LE(1, 20);
wav.writeUInt16LE(1, 22);
wav.writeUInt32LE(8000, 24);
wav.writeUInt32LE(16000, 28);
wav.writeUInt16LE(2, 32);
wav.writeUInt16LE(16, 34);
wav.write('data', 36);
wav.writeUInt32LE(pcm.length, 40);
pcm.copy(wav, 44);

const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
const browser = await chromium.launch({headless:true,...(executablePath ? {executablePath} : {})});
const errors = [], calls = [];
let recent = [], historyStatus = 200, historyOwner = 11;
const media = {id:9,title:'Last song <img>',album:'Studio',audio:'/song.wav',cover:'/cover.svg',detail:'/track.php?id=9'};
const savedTrack = () => ({...media,last_played_at:new Date().toISOString(),last_position_seconds:24});
const startCount = () => calls.filter(x => x.path === '/api/playback.php' && x.action === 'start').length;
const refresh = page => page.evaluate(() => window.dispatchEvent(new Event('vp3:playback-history-changed')));

async function fixture(options) {
  const page = await browser.newPage(options);
  page.on('pageerror', error => errors.push(error.message));
  await page.route('http://listening.test/chat.php**', route => route.fulfill({contentType:'text/html',body:html}));
  await page.route('**/song.wav', route => {
    const range = route.request().headers().range?.match(/^bytes=(\d+)-(\d*)$/);
    if (!range) return route.fulfill({contentType:'audio/wav',headers:{'Accept-Ranges':'bytes'},body:wav});
    const start = Number(range[1]);
    const end = range[2] ? Math.min(Number(range[2]), wav.length - 1) : wav.length - 1;
    return route.fulfill({
      status:206,contentType:'audio/wav',headers:{'Accept-Ranges':'bytes','Content-Range':`bytes ${start}-${end}/${wav.length}`},
      body:wav.subarray(start, end + 1)
    });
  });
  await page.route('**/cover.svg', route => route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'}));
  await page.route('**/api/**', route => {
    const body = route.request().postDataJSON() || {};
    const path = new URL(route.request().url()).pathname;
    calls.push({...body,path});
    if (body.action === 'recent_history') return route.fulfill(historyStatus === 200
      ? {json:{ok:true,user_id:historyOwner,tracks:recent}}
      : {status:historyStatus,json:{ok:false,error:'Unavailable'}});
    if (path === '/api/playback.php') {
      const track = recent.find(row => row.id === body.track_id);
      if (track) track.last_position_seconds = Number(body.position) || 0;
      else if (body.action === 'start') recent = [{...savedTrack(),last_position_seconds:Number(body.position) || 0}];
      return route.fulfill({json:{ok:true,session_token:'session',session_id:7}});
    }
    if (body.action === 'load') return route.fulfill({json:{ok:true,messages:body.source_message_id
      ? [{id:body.source_message_id,role:'assistant',message:'Selected saved source'},{id:1000,role:'assistant',message:'Latest conversation'}]
      : [{id:1,role:'assistant',message:'Earlier conversation'}]}});
    if (body.action === 'send') return route.fulfill({json:{ok:true,conversation_id:42,answer:'Conversation continues.',assistant_message_id:2}});
    return route.fulfill({json:{ok:true,conversations:[],updates:[],items:[]}});
  });
  return page;
}

async function boot(page, view = 'chat') {
  await page.goto('http://listening.test/chat.php' + (view === 'player' ? '?view=player' : ''));
  await page.evaluate(initialView => {
    window.STONEFELLOW_CHAT = {
      userId:11,endpoint:'/api/chat.php',playbackEndpoint:'/api/playback.php',libraryEndpoint:'/api/player-library-v76.php',
      csrf:'fixture',initialView,initialConversationId:42
    };
    window.STONEFELLOW_PLAYER = {tracks:{},albums:{},playlists:{}};
  }, view);
  await page.addStyleTag({content:await read('chat.css')});
  await page.addScriptTag({content:await read('chat.js')});
}

function controls(page) {
  const row = page.locator('#chatListeningContinuation');
  return {
    row, audio:row.locator('audio'), position:row.locator('[data-listening-position]'),
    resume:row.getByRole('button', {name:'Resume listening'}), pause:row.getByRole('button', {name:'Pause listening'}),
    nowPlaying:page.locator('#chatNowPlaying'), globalToggle:page.locator('#chatNowPlayingToggle')
  };
}

async function assertCompact(page, row) {
  assert.ok((await row.boundingBox()).height <= 48, 'Resume row is taller than a single control');
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Resume row overflowed');
}

try {
  const mobile = await fixture({viewport:{width:390,height:844},isMobile:true,hasTouch:true});
  await boot(mobile, 'player');
  await mobile.locator('#playerSong [data-player-toggle]').click();
  await mobile.waitForFunction(() => {
    const audio = document.querySelector('#playerSong audio');
    return !audio.paused && audio.readyState >= 3 && audio.currentTime > 0;
  });
  await mobile.locator('#playerSong audio').evaluate(audio => { audio.currentTime = 24; });
  await mobile.waitForFunction(() => {
    const audio = document.querySelector('#playerSong audio');
    return !audio.seeking && audio.currentTime >= 24;
  });
  await mobile.locator('#playerSong audio').evaluate(audio => audio.pause());
  for (let i = 0; i < 50 && !(recent[0]?.last_position_seconds >= 24); i++) await mobile.waitForTimeout(100);
  assert.ok(recent[0]?.last_position_seconds >= 24, 'Player did not record the position');

  await boot(mobile);
  const {row, audio, position, resume, pause, nowPlaying, globalToggle} = controls(mobile);
  await resume.waitFor();
  assert.match(await row.innerText(), /Last song <img>/);
  assert.equal(await row.locator('strong img').count(), 0);
  assert.equal(await audio.evaluate(element => element.paused), true, 'Chat autoplayed the saved track');
  assert.equal(await position.textContent(), '0:24');
  assert.equal(await row.getByRole('button').count(), 1);
  assert.equal(await row.locator('.chat-inline-player, a').count(), 0);
  assert.equal(await mobile.locator('#playerRecent').count(), 0, 'Fixture relies on the hidden Player history shelf');
  assert.equal(await nowPlaying.isVisible(), false);
  await assertCompact(mobile, row);

  const starts = startCount();
  await resume.click();
  await mobile.waitForFunction(() => {
    const audio = document.querySelector('#chatListeningContinuation audio');
    return !audio.paused && audio.currentTime >= 24;
  });
  await pause.waitFor();
  await nowPlaying.waitFor();
  assert.equal(await mobile.locator('#chatNowPlayingTitle').textContent(), media.title);
  assert.equal(await mobile.locator('#playerSong audio').evaluate(element => element.paused), true, 'Resume started the hidden Player audio');
  assert.equal(await mobile.locator('#chatNowPlayingNext').isDisabled(), true);
  await mobile.evaluate(() => { window.keptListeningAudio = document.querySelector('#chatListeningContinuation audio'); });
  await mobile.evaluate(() => {
    const slot = document.createElement('aside');
    slot.dataset.agentActivityListening = '';
    document.body.append(slot);
    window.dispatchEvent(new Event('vp3:agent-activity-listening-slot'));
  });
  await mobile.waitForFunction(() => document.querySelector('[data-agent-activity-listening] #chatListeningContinuation audio') === keptListeningAudio);
  assert.equal(await audio.evaluate(element => element.paused), false, 'Moving the existing player paused live playback');
  assert.equal(await mobile.locator('#chatListeningContinuation').count(), 1, 'The orb slot duplicated the listening player');
  assert.equal(startCount(), starts + 1, 'Mounting the orb slot created a new playback session');
  recent = [{...savedTrack(),id:19,title:'Track from another session'}, savedTrack()];
  const historyRefresh = mobile.waitForResponse(response => response.url().includes('player-library'));
  await refresh(mobile);
  await historyRefresh;
  assert.equal(await mobile.evaluate(() => keptListeningAudio === document.querySelector('#chatListeningContinuation audio')), true, 'History refresh replaced active playback');
  assert.equal(await row.locator('[data-listening-title]').textContent(), media.title);
  recent = [savedTrack()];

  await globalToggle.click();
  await resume.waitFor();
  assert.equal(await audio.evaluate(element => element.paused), true);
  await mobile.locator('#chatNowPlayingSeek').evaluate(input => {
    input.value = '700';
    input.dispatchEvent(new Event('input'));
  });
  await mobile.waitForFunction(() => document.querySelector('#chatListeningContinuation [data-listening-position]').textContent === '0:42');
  await mobile.evaluate(() => {
    const button = document.createElement('button');
    button.id = 'activityResumeFixture';
    button.textContent = 'Resume activity track';
    button.addEventListener('click', async () => { window.activityPlayResult = await STONEFELLOW_CHAT_CONTINUITY.playActivityTrack(9); });
    document.body.append(button);
  });
  await mobile.locator('#activityResumeFixture').click();
  await pause.waitFor();
  assert.equal(await mobile.evaluate(() => window.activityPlayResult), true, 'The activity bridge did not delegate to the canonical player');
  assert.ok(await audio.evaluate(element => element.currentTime >= 42), 'Resume reset the current position');
  await pause.click();
  await resume.waitFor();
  assert.equal(await globalToggle.getAttribute('aria-label'), 'Play');
  assert.equal(startCount(), starts + 1, 'Toggling created duplicate playback sessions');

  const input = mobile.locator('#chatInput');
  await input.fill('Continue our conversation');
  await mobile.getByRole('button', {name:'Catch me up'}).click();
  assert.equal(await input.inputValue(), 'Continue our conversation');
  assert.equal(calls.filter(x => x.action === 'send').length, 0);
  await mobile.locator('#chatForm').evaluate(form => form.requestSubmit());
  await mobile.getByText('Conversation continues.', {exact:true}).waitFor();
  assert.equal(await row.isVisible(), true);
  assert.equal(await mobile.evaluate(() => document.querySelector('[data-agent-activity-listening]').firstElementChild.id), 'chatListeningContinuation');
  assert.equal(await mobile.locator('#chatThread #chatListeningContinuation').count(), 0, 'The resumed player returned to the transcript');

  historyStatus = 503;
  const failedHistory = mobile.waitForResponse(response => response.url().includes('player-library') && response.status() === 503);
  await boot(mobile);
  await failedHistory;
  assert.equal(await row.isVisible(), false);
  assert.equal(await row.textContent(), '', 'Failed history added copy to the transcript');
  historyStatus = 200;
  recent = [savedTrack()];
  await mobile.evaluate(() => window.dispatchEvent(new Event('focus')));
  await resume.waitFor();
  assert.equal(await audio.evaluate(element => element.paused), true, 'Recovering history triggered playback');
  await mobile.close();

  const desktop = await fixture({viewport:{width:1280,height:900}});
  const desktopControls = controls(desktop);
  recent = [{...savedTrack(),cover:'',title:'A long track title '.repeat(40)}];
  await boot(desktop);
  await desktopControls.resume.waitFor();
  assert.equal(await desktopControls.row.locator('img').isVisible(), false);
  await assertCompact(desktop, desktopControls.row);
  await desktopControls.resume.click();
  await desktopControls.nowPlaying.waitFor();
  await desktop.evaluate(() => { window.revokedAudio = document.querySelector('#chatListeningContinuation audio'); });
  historyOwner = 22;
  await refresh(desktop);
  await desktopControls.row.waitFor({state:'hidden'});
  assert.equal(await desktopControls.nowPlaying.isVisible(), false);
  assert.equal(await desktop.evaluate(() => revokedAudio.paused && !revokedAudio.isConnected), true, 'Account change left private audio playing');
  assert.equal(await desktopControls.row.textContent(), '');
  assert.equal(await desktop.locator('#chatNowPlayingTitle').textContent(), '');

  historyOwner = 11;
  await refresh(desktop);
  await desktopControls.resume.waitFor();
  await desktopControls.resume.click();
  await desktopControls.nowPlaying.waitFor();
  historyStatus = 419;
  await refresh(desktop);
  await desktopControls.row.waitFor({state:'hidden'});
  assert.equal(await desktopControls.nowPlaying.isVisible(), false);
  assert.equal(await desktopControls.audio.count(), 0);
  historyStatus = 200;
  await refresh(desktop);
  await desktopControls.resume.waitFor();
  await desktop.evaluate(() => {
    window.VP3ParticipantContinuity = {selectedContext:() => ({id:44})};
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  await desktopControls.row.waitFor({state:'hidden'});
  assert.equal(await desktop.evaluate(() => STONEFELLOW_CHAT_CONTINUITY.playActivityTrack(9)), false, 'Participant scope could resume owner media');
  assert.equal(await desktopControls.audio.count(), 0, 'Participant scope retained the private listening player');
  await desktop.evaluate(() => {
    window.VP3ParticipantContinuity = {selectedContext:() => null};
    window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:-1}}));
    window.dispatchEvent(new Event('vp3:participant-selection-changed'));
  });
  await desktopControls.resume.waitFor();
  assert.equal(await desktopControls.audio.evaluate(element => element.paused), true, 'Restoring the owner scope autoplayed a song');
  assert.equal(await desktop.evaluate(() => STONEFELLOW_CHAT_CONTINUITY.openConversation(42,{messageId:500})), true, 'Activity can load an exact older source alongside the recent Chat tail');
  assert.equal(calls.at(-1).action === 'load' ? calls.at(-1).source_message_id : calls.findLast(call=>call.action === 'load').source_message_id,500);
  assert.equal(await desktop.locator('[data-chat-message-id="500"]').count(),1);
  assert.equal(await desktop.locator('[data-chat-message-id="1000"]').count(),1);
  let sourceStarted, releaseSource;
  const sourceWaiting = new Promise(resolve => { sourceStarted = resolve; });
  const sourceRelease = new Promise(resolve => { releaseSource = resolve; });
  await desktop.route('**/api/chat.php', async route => {
    const body = route.request().postDataJSON();
    if (body.action !== 'load' || body.source_message_id !== 501) return route.fallback();
    sourceStarted();
    await sourceRelease;
    await route.fulfill({json:{ok:true,messages:[{id:501,role:'assistant',message:'Delayed private owner source'}]}});
  });
  const sourceLoad = desktop.evaluate(() => STONEFELLOW_CHAT_CONTINUITY.openConversation(42,{messageId:501}));
  await sourceWaiting;
  await desktop.evaluate(() => window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:14}})));
  releaseSource();
  assert.equal(await sourceLoad,false,'Participant context changes discard the in-flight owner history load');
  assert.equal(await desktop.locator('[data-chat-message-id="501"]').count(),0,'A delayed private source cannot mount after participant selection');
  await desktop.evaluate(() => window.dispatchEvent(new CustomEvent('vp3:participant-task-context',{detail:{participant_id:-1}})));
  await desktop.evaluate(() => { STONEFELLOW_CHAT.userId = 22; });
  assert.equal(await desktop.evaluate(() => STONEFELLOW_CHAT_CONTINUITY.playActivityTrack(9)), false, 'An owner-mismatched bridge could resume a song');
  await refresh(desktop);
  await desktopControls.row.waitFor({state:'hidden'});
  assert.deepEqual(errors, []);
  console.log('CHAT_LISTENING_CONTINUATION_BROWSER=PASS compact resume row, preserved orb audio, canonical bridge, saved position, shared transport, draft preservation, quiet recovery, and owner/participant isolation');
} finally {
  await browser.close();
}
