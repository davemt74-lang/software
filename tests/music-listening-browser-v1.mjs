import assert from 'node:assert/strict';
import {readFile, mkdir} from 'node:fs/promises';
import {musicListeningFixture} from './music-listening-api-v1.mjs';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const read = name => readFile(new URL('../' + name, import.meta.url), 'utf8');
const fixture = await musicListeningFixture();
let browser, context, releaseStart, releaseEnd;
const errors = [], requests = [], responses = [];
let lostHandshake = false, lostStart = false, lostPause = false, heldStart = null, heldEnd = null;
const pendingStart = new Promise(resolve => { releaseStart = resolve; });
const pendingEnd = new Promise(resolve => { releaseEnd = resolve; });
let checks = 0;
const check = (value, message) => { assert.ok(value, message); checks++; };
const until = async (predicate, message) => {
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    if (await predicate()) return;
    await new Promise(resolve => setTimeout(resolve, 40));
  }
  throw new Error(message + '\n' + fixture.logs);
};
const nativeSessions = () => fixture.readDb('SELECT * FROM music_listening_sessions_v1 ORDER BY session_id');
const nativeReceipts = () => fixture.readDb('SELECT * FROM music_listening_receipts_v1 ORDER BY session_id,seq');
const lastRequest = action => requests.filter(row => row.input.action === action).at(-1)?.input;

try {
  const [template, css, emitter, chat] = await Promise.all([
    read('chat-legacy-v108.php'), read('chat.css'), read('assets/js/music-listening-v1.js'), read('chat.js'),
  ]);
  const from = template.indexOf('<section\n        class="chat-now-playing"');
  const to = template.indexOf('<form class="chat-composer"', from);
  assert.ok(from >= 0 && to > from, 'The actual shared now-playing control markup is available');
  const nowPlaying = template.slice(from, to);
  // The full production Chat controller and shared emitter run against real PHP
  // playback/history/media routes. Only unrelated Chat conversation responses
  // and the fixture page shell are supplied here.
  const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>
    <div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside>
    <main class="chat-main"><header>Native listening acceptance</header><section class="chat-thread" id="chatThread">
      <section data-chat-view="player" hidden><div data-player-queue>
        <article id="firstSong" class="chat-audio-card"><strong>Fixture recording</strong>
          <audio class="chat-audio-player" preload="none" data-track-id="42" data-player-title="Fixture recording" data-player-album="First album" src="${fixture.audioUrl}"></audio></article>
        <article id="nextSong" class="chat-audio-card"><strong>Next native song</strong>
          <audio class="chat-audio-player" preload="none" data-track-id="99" data-player-title="Next native song" src="/media.php?track=99&type=audio"></audio></article>
      </div></section><div id="chatWelcome" hidden></div></section>
      <div class="chat-composer-shell" id="chatComposerShell">${nowPlaying}<form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></div>
    </main></div><div id="chatQueueDrawer" hidden><div id="chatQueueList"></div></div></body></html>`;
  const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || process.env.CHROME_EXECUTABLE_PATH;
  browser = await chromium.launch({headless: true, ...(executablePath ? {executablePath} : {}), args: ['--autoplay-policy=no-user-gesture-required']});
  context = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true, extraHTTPHeaders: {'X-User': '11'}});
  // Intentionally omit sf_listener. The first-cookie/lost-ACK boundary is part
  // of this browser test, rather than pre-authorizing it through fixture headers.
  const session = fixture.cookie.split(';')[0], separator = session.indexOf('=');
  await context.addCookies([{name: session.slice(0, separator), value: session.slice(separator + 1), url: fixture.base}]);
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/chat.php**', route => route.fulfill({contentType: 'text/html', body: html}));
  await page.route('**/api/chat.php', route => route.fulfill({json: {ok: true, conversations: [], messages: [], updates: [], items: []}}));
  await page.route('**/api/playback.php', async route => {
    const body = route.request().postData(), input = JSON.parse(body);
    requests.push({body, input});
    const result = await route.fetch();
    const data = await result.json();
    responses.push({input, data, status: result.status()});
    if (data.code === 'listener_initialization_required' && !lostHandshake) {
      lostHandshake = true;
      // route.fetch uses the browser request context; remove any Set-Cookie it
      // observed to model an actual lost response before browser delivery.
      await context.clearCookies({name: 'sf_listener'});
      await route.abort('failed'); return;
    }
    if (input.action === 'start' && data.ok && !lostStart) {
      lostStart = true; heldStart = {body, input, data};
      await pendingStart;
      await route.abort('failed'); return;
    }
    if (input.action === 'pause' && data.ok && !lostPause) {
      lostPause = true;
      await route.abort('failed'); return;
    }
    if (input.action === 'ended' && data.ok && !heldEnd) {
      heldEnd = {body, input, data};
      await pendingEnd;
      await route.fulfill({response: result}); return;
    }
    await route.fulfill({response: result});
  });
  const boot = async () => {
    await page.goto(fixture.base + '/chat.php?view=player&private_context=do-not-store#player');
    await page.evaluate(csrf => {
      window.STONEFELLOW_CHAT = {userId: 11, endpoint: '/api/chat.php', playbackEndpoint: '/api/playback.php',
        libraryEndpoint: '/api/player-library-v76.php', csrf, initialView: 'player', initialConversationId: 42};
      window.STONEFELLOW_PLAYER = {tracks: {}, albums: {}, playlists: {}};
    }, fixture.csrf);
    await page.addStyleTag({content: css});
    await page.addScriptTag({content: emitter});
    await page.addScriptTag({content: chat});
  };
  await boot();
  const audio = page.locator('#firstSong audio'), toggle = page.locator('#firstSong [data-player-toggle]');
  check(nativeSessions().length === 0, 'Rendering the player/history must not allocate listening');
  check(await audio.evaluate(element => element.paused), 'Restored history must not autoplay');
  await toggle.click();
  await until(() => heldStart, 'The allocating start did not reach the real endpoint');
  check(lostHandshake, 'The first missing-cookie response was actually lost');
  check(responses.filter(row => row.status === 428).length === 2, 'The second cookie handshake must also allocate no session');
  check(nativeSessions().length === 1, 'Cookie initialization retries must allocate exactly one eventual play');
  const initialPosition = await audio.evaluate(element => element.currentTime);
  await page.waitForFunction(position => document.querySelector('#firstSong audio').currentTime > position + .4, initialPosition);
  await toggle.click();
  check(await audio.evaluate(element => element.paused), 'The real pause control must remain responsive while start is unacknowledged');
  check(requests.every(row => row.input.action === 'start'), 'Pause evidence must wait for the allocated token');
  releaseStart();
  await until(() => requests.filter(row => row.input.action === 'pause').length === 2 && nativeReceipts().length >= 2,
    'The exact pending pause did not recover after both acknowledgements were lost');
  const startBodies = requests.filter(row => row.input.action === 'start').map(row => row.body);
  check(new Set(startBodies).size === 1, 'Handshake, lost start ACK and retries must preserve the exact start payload');
  const pauseBodies = requests.filter(row => row.input.action === 'pause').map(row => row.body);
  check(pauseBodies[0] === pauseBodies[1], 'The lost pause ACK must be recovered with the same event identity and body');
  check(nativeSessions().length === 1 && nativeReceipts().length === 2, 'Lost acknowledgements must not duplicate durable sessions or events');
  check(lastRequest('pause').cumulative_played_ms > 0, 'A pause before start ACK must retain observed playing evidence');
  const initial = nativeSessions()[0];
  check(initial.recording_id === fixture.identity.recording_id && initial.asset_id === fixture.identity.asset_id,
    'Native sessions must carry the existing canonical recording and catalog-snapshot asset identity');
  check(initial.source_url === fixture.base + '/chat.php' && !initial.source_url.includes('private_context'),
    'Initiating page attribution must omit query values and fragments');
  check(Number(initial.clipped) === 1, 'Delayed pre-acknowledgement playback must expose conservative partial coverage');

  // A paused forward/backward seek uses the real custom player control. The
  // following acknowledged 2x segment must count elapsed time, not seek/rate
  // advancement, and must continue the same logical playback attempt.
  const seek = async value => {
    await page.locator('#firstSong [data-player-seek]').evaluate((input, next) => {
      input.value = String(next); input.dispatchEvent(new Event('input', {bubbles: true}));
    }, value);
    await page.waitForFunction(() => !document.querySelector('#firstSong audio').seeking);
    await until(() => nativeSessions()[0].state === 'paused', 'A paused seek did not return to paused state');
  };
  const beforeSeek = lastRequest('pause').cumulative_played_ms;
  await seek(500);
  await until(() => lastRequest('pause')?.position_ms === 15000, 'The paused seek position was not acknowledged');
  check(lastRequest('pause').cumulative_played_ms === beforeSeek, 'Seeking ahead while paused must add zero time');
  await audio.evaluate(element => { element.playbackRate = 2; });
  await until(() => lastRequest('ratechange')?.playback_rate === 2, 'The paused rate change was not reported');
  const acceptedBefore = Number(nativeSessions()[0].accepted_played_ms);
  await toggle.click();
  await page.waitForFunction(() => document.querySelector('#firstSong audio').currentTime >= 17);
  await toggle.click();
  await until(() => nativeSessions()[0].state === 'paused' && Number(nativeSessions()[0].accepted_played_ms) > acceptedBefore,
    'Acknowledged playing after resume did not record positive accepted time');
  const afterRate = lastRequest('pause');
  check(afterRate.cumulative_played_ms - beforeSeek < (afterRate.position_ms - 15000) * .75,
    'Two-times playback must not double elapsed listening');
  const beforeBack = afterRate.cumulative_played_ms;
  await seek(100);
  await until(() => lastRequest('pause')?.position_ms === 3000, 'Backward seek was not acknowledged');
  check(lastRequest('pause').cumulative_played_ms === beforeBack && nativeSessions().length === 1,
    'Backward seek must preserve accumulated time and the existing play identity');

  await mkdir('artifacts/music-listening-v1', {recursive: true});
  await page.screenshot({path: 'artifacts/music-listening-v1/native-player-mobile.png', fullPage: true});

  // Finish naturally and let the existing queue start the next track while the
  // previous terminal response is still pending. Its receipt stays bound to the
  // original play, without delaying or restarting the next song.
  await seek(975);
  await toggle.click();
  await until(() => heldEnd, 'The native ended transition did not reach the real endpoint');
  await page.waitForFunction(() => {
    const next = document.querySelector('#nextSong audio'); return !next.paused && next.currentTime > 0;
  });
  await page.locator('#nextSong [data-player-toggle]').click();
  await until(() => nativeSessions().length === 2, 'Queue advancement did not allocate the next deliberate play');
  check(await page.locator('#chatNowPlayingTitle').textContent() === 'Next native song', 'The shared now-playing controls must follow the existing queue');
  const rows = nativeSessions();
  check(rows[0].play_id === heldEnd.input.play_id && rows[1].play_id !== rows[0].play_id,
    'Pending terminal evidence and the next song must keep separate play identities');
  releaseEnd();
  await until(() => nativeSessions()[1].state === 'paused', 'The next queue item did not pause independently');
  check(Number(nativeSessions()[0].natural_ended) === 1 && Number(nativeSessions()[0].accepted_played_ms) < 10000,
    'Seeking to a natural end must record ending without manufacturing a qualified listen');
  check(responses.filter(row => row.status >= 400 && row.status !== 428).length === 0,
    'Actual pause, seek, speed and queue transitions must satisfy strict server sequencing');
  const historyResponse = await fixture.postJson('/api/player-library-v76.php', {action: 'recent_history'});
  const history = await fixture.json(historyResponse);
  check(history.ok && history.user_id === 11 && history.tracks.some(row => row.id === 42),
    'Accepted native playback must remain available in the listener’s existing private history');
  const sessionsBeforeReload = nativeSessions().length;
  await boot();
  check(await page.locator('#firstSong audio').evaluate(element => element.paused), 'Reload must not replay a previous page’s pending start');
  check(nativeSessions().length === sessionsBeforeReload, 'Reload/history restoration must not allocate another play');
  check(errors.length === 0, 'Production player scripts must not throw browser errors: ' + errors.join('; '));
  console.log(`MUSIC_LISTENING_BROWSER_V1=PASS checks=${checks} real PHP media/receipts, missing-cookie/lost-ACK recovery, pending pause, seeks/rate, native queue and private history`);
} finally {
  releaseStart?.(); releaseEnd?.();
  await browser?.close();
  fixture.close();
}
