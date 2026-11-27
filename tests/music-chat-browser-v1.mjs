import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {chromium, webkit} from 'playwright';
import {startMusicChatFixture} from './music-chat-fixture-v1.mjs';

// Real PHP/SQLite service, media, production Chat UI and both existing player
// adapters. Only the external Google search transport and IFrame API are mocked.
// Passing this suite does not certify a live Google account or physical phone.
const engineName = process.env.MUSIC_CHAT_BROWSER || 'chromium';
assert.ok(['chromium', 'webkit'].includes(engineName), 'Choose chromium or webkit.');
const artifactDir = path.resolve(process.env.MUSIC_CHAT_ARTIFACT_DIR || `test-results/music-chat-${engineName}`);
await fs.mkdir(artifactDir, {recursive:true});
const fixture = await startMusicChatFixture();
let browser;
let checks = 0;
const errors = [];
const openedPages = [];
const check = (condition, message) => { assert.ok(condition, message); checks += 1; };
const equal = (actual, expected, message) => { assert.deepEqual(actual, expected, message); checks += 1; };

function mockedYouTubeIframeApi() {
  const players = [];
  let blocked = false;
  const states = {UNSTARTED:-1, ENDED:0, PLAYING:1, PAUSED:2, BUFFERING:3, CUED:5};
  window.__musicYouTube = {
    players,
    blockNext() { blocked = true; },
    active() { return players.findLast(player => !player.destroyed); },
    endTwice() {
      const player = this.active();
      if (!player) throw new Error('No mocked YouTube player.');
      player.position = 60; player.state = states.ENDED;
      player.events.onStateChange({target:player, data:states.ENDED});
      player.events.onStateChange({target:player, data:states.ENDED});
    }
  };
  window.YT = {PlayerState:states, Player:class {
    constructor(frame, options) {
      this.frame = frame; this.events = options.events; this.state = blocked ? states.PAUSED : states.PLAYING;
      this.position = 0; this.started = performance.now(); this.destroyed = false;
      const wasBlocked = blocked; blocked = false; players.push(this);
      queueMicrotask(() => {
        if (this.destroyed) return;
        this.events.onReady({target:this});
        if (wasBlocked) this.events.onAutoplayBlocked({target:this});
        else this.events.onStateChange({target:this, data:states.PLAYING});
      });
    }
    getPlayerState() { return this.state; }
    getCurrentTime() { return this.position + (this.state === states.PLAYING ? (performance.now() - this.started) / 1000 : 0); }
    getDuration() { return 60; }
    getPlaybackRate() { return 1; }
    getVideoUrl() { return 'https://www.youtube.com/watch?v=' + new URL(this.frame.src).pathname.split('/').at(-1); }
    getVolume() { return 100; }
    isMuted() { return false; }
    seekTo(position) { this.position = position; this.started = performance.now(); }
    playVideo() { this.position = this.getCurrentTime(); this.started = performance.now(); this.state = states.PLAYING;
      this.events.onStateChange({target:this, data:this.state}); }
    pauseVideo() { this.position = this.getCurrentTime(); this.state = states.PAUSED;
      this.events.onStateChange({target:this, data:this.state}); }
    destroy() { this.position = this.getCurrentTime(); this.state = states.PAUSED; this.destroyed = true; }
  }};
}

async function pageFor(viewport = {width:1280, height:900}) {
  const context = await browser.newContext({viewport, hasTouch:viewport.width < 600, extraHTTPHeaders:{'X-User':'11'}});
  await context.addInitScript(mockedYouTubeIframeApi);
  await context.addInitScript(() => {
    const audioIds = new WeakMap(); let nextAudioId = 0;
    window.__musicNativeEvents = [];
    for (const type of ['loadedmetadata','play','playing','pause','seeking','seeked','ended','emptied','error']) {
      document.addEventListener(type, event => {
        const audio = event.target;
        if (!(audio instanceof HTMLMediaElement) || !audio.matches('.chat-audio-player')) return;
        if (!audioIds.has(audio)) audioIds.set(audio, ++nextAudioId);
        window.__musicNativeEvents.push({event:type, at:Math.round(performance.now()), audio:audioIds.get(audio),
          trackId:audio.dataset.trackId, paused:audio.paused, seeking:audio.seeking, ended:audio.ended,
          readyState:audio.readyState, currentTime:audio.currentTime});
        if (window.__musicNativeEvents.length > 200) window.__musicNativeEvents.shift();
      }, true);
    }
  });
  const page = await context.newPage();
  openedPages.push(page);
  page.on('pageerror', error => errors.push(error.message));
  await page.route('https://www.youtube.com/**', route => route.fulfill({contentType:'text/html', body:
    '<!doctype html><html lang="en"><body style="margin:0;background:#161616;color:white;font:16px Arial;display:grid;place-items:center;height:100vh"><div>Mocked YouTube IFrame API<br><small>Visible player acceptance fixture</small></div></body></html>'}));
  await page.route('https://i.ytimg.com/**', route => route.fulfill({contentType:'image/svg+xml', body:
    '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="120" height="120" fill="#26384d"/><path d="M48 35l36 25-36 25z" fill="white"/></svg>'}));
  await page.goto(fixture.baseURL + '/chat.php');
  await page.waitForFunction(() => window.VP3_MUSIC_CHAT_V1 && window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.() === 42);
  await page.locator('#chatInput').waitFor();
  return {page, context};
}

const cards = page => page.locator('.vp3-music-chat');
const resultCard = (page, result) => page.locator(`[data-chat-message-id="${result.assistant_message_id}"] .vp3-music-chat`).last();
const rows = card => card.locator('.vp3-music-item');
const requestCount = action => fixture.requests().filter(row => row.path === '/api/music-chat-v1.php' && row.action === action).length;
const nativeCount = () => Number(fixture.readDb('SELECT COUNT(*) AS total FROM track_play_sessions')[0].total);
const youtubeCount = () => Number(fixture.readDb('SELECT (SELECT COUNT(*) FROM vp3_youtube_playback_v1)+(SELECT COUNT(*) FROM vp3_youtube_playlist_plays_v1) AS total')[0].total);
async function send(page, message) {
  await page.locator('#chatInput').fill(message);
  const response = page.waitForResponse(response => response.url().endsWith('/api/chat.php') && response.request().postDataJSON()?.action === 'send');
  await page.locator('#sendChatButton').click();
  const result = await response;
  const data = await result.json();
  equal(result.status(), 200, `${message}: ${JSON.stringify(data)}`);
  if (data.music_chat_v1) await page.locator(`[data-music-result-id="${data.music_chat_v1.result_id}"] .vp3-music-items`).waitFor();
  return data;
}
async function attach(page, seed, live = false) {
  await page.evaluate(async ({seed, live}) => {
    const message = document.createElement('article');
    message.className = 'message assistant';
    message.dataset.chatMessageId = String(seed.assistant_message_id);
    message.dataset.chatConversationId = '42'; message.dataset.chatMessageRole = 'assistant';
    const avatar = document.createElement('div'); avatar.className = 'message-avatar'; avatar.textContent = 'AI';
    const body = document.createElement('div'); body.className = 'message-body';
    message.append(avatar, body);
    const thread = document.querySelector('#chatThread');
    thread.insertBefore(message, thread.querySelector('.chat-listening-continuation'));
    await window.VP3_MUSIC_CHAT_V1.attach(message, seed.music_chat_v1, {live});
  }, {seed, live});
  return page.locator(`[data-chat-message-id="${seed.assistant_message_id}"] .vp3-music-chat`).last();
}
async function playbackState(card, expected) {
  await card.locator(`[data-music-state="${expected}"]`).waitFor({timeout:15000});
}
async function nativeStarted(page, card) {
  await card.locator('[data-music-state="playing"],[data-music-state="blocked"],[data-music-state="unavailable"]').waitFor();
  if (await card.locator('[data-music-state="blocked"]').count()) {
    // Browser autoplay policy may require the actual existing player control.
    // This is an observed blocked state, never a fabricated "playing" result.
    const toggle = page.locator('#chatNowPlayingToggle');
    if (await toggle.isVisible()) await toggle.click();
    else await page.locator('[data-listening-toggle]').click();
  }
  await playbackState(card, 'playing');
  await page.waitForFunction(() => [...document.querySelectorAll('audio.chat-audio-player')].some(audio => !audio.paused && audio.readyState >= 3));
}
async function waitUntil(fn, message) {
  for (let attempt = 0; attempt < 80; attempt++) {
    if (fn()) { checks += 1; return; }
    await new Promise(resolve => setTimeout(resolve, 75));
  }
  assert.fail(message + '\n' + fixture.logs);
}
async function layout(page, label, card) {
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${label}: horizontal overflow`);
  const violations = await page.locator('.vp3-music-chat').evaluateAll(elements => elements.flatMap(card => {
    const errors = [];
    for (const element of card.querySelectorAll('*')) {
      const style = getComputedStyle(element);
      if (['auto','scroll'].includes(style.overflowY) && element.scrollHeight > element.clientHeight + 2) errors.push('nested vertical scroller');
      if (element.matches('button') && element.getClientRects().length && element.getBoundingClientRect().height < 43.5) errors.push('small button');
    }
    return errors;
  }));
  equal(violations, [], `${label}: touch controls and normal card scrolling`);
  await card.evaluate(element => element.scrollIntoView({block:'start'}));
  await card.screenshot({path:path.join(artifactDir, `${label}.png`)});
  await page.screenshot({path:path.join(artifactDir, `${label}-page.png`), fullPage:false});
}
async function lowerPhoneLayout(page, label, control) {
  // A tall card cannot fit in a phone viewport. Keep the top capture and show
  // its lower controls after an ordinary scroll, with the real composer intact.
  await control.evaluate(element => element.scrollIntoView({block:'center'}));
  await control.click({trial:true});
  const visibility = await control.evaluate(element => {
    const rect = element.getBoundingClientRect();
    const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
    return {
      withinViewport:rect.top >= 0 && rect.left >= 0 && rect.bottom <= innerHeight && rect.right <= innerWidth,
      topmost:hit === element || element.contains(hit)
    };
  });
  check(visibility.withinViewport, `${label}: lower control fits in the phone viewport after scrolling`);
  check(visibility.topmost, `${label}: lower control is unobscured at its center`);
  await page.screenshot({path:path.join(artifactDir, `${label}-lower-page.png`), fullPage:false});
}

try {
  const executablePath = engineName === 'chromium' ? process.env.MUSIC_CHAT_CHROMIUM_EXECUTABLE : '';
  browser = await ({chromium, webkit}[engineName]).launch({headless:true, ...(executablePath ? {executablePath} : {})});
  const desktop = await pageFor();
  const page = desktop.page;

  const one = await send(page, 'show me one song');
  const oneCard = resultCard(page, one);
  equal(one.music_chat_v1.items.length, 1, 'One song has one global result');
  equal(await rows(oneCard).count(), 1, 'One song renders one row');
  equal(await oneCard.locator('iframe,audio').count(), 0, 'Show is quiet');
  equal(nativeCount() + youtubeCount(), 0, 'Display creates no listening receipts');
  const youtube = await send(page, 'show me one YouTube song');
  const youtubeCard = resultCard(page, youtube);
  equal(youtube.music_chat_v1.items[0].source, 'youtube', 'YouTube source selection');
  equal(await rows(youtubeCard).count(), 1, 'YouTube song is a single unified row');
  equal(await youtubeCard.locator('.vp3-youtube-results').count(), 0, 'No duplicate legacy source card');
  await layout(page, 'desktop-song', youtubeCard);
  const shortfall = await fixture.seed({playlist:true,title:'Short playlist',requested_count:8,
    items:[{source:'vp3',source_id:'41'},{source:'vp3',source_id:'42'}]});
  const shortfallCard = await attach(page, shortfall, true);
  await shortfallCard.getByText('Found 2 of 8 requested songs.', {exact:true}).waitFor();
  checks += 1;
  await layout(page, 'desktop-results', shortfallCard);

  const three = await send(page, 'show me three songs');
  const chosen = three.music_chat_v1.items[1];
  fixture.run('db()->exec("UPDATE tracks SET sort_order=-id");');
  const followup = await send(page, 'play the second song');
  const followupCard = resultCard(page, followup);
  equal(followup.music_chat_v1.items.map(item => [item.source,item.source_id]), [[chosen.source,chosen.source_id]], 'Exact follow-up uses stored ordering');
  await nativeStarted(page, followupCard);
  await waitUntil(() => nativeCount() === 1, 'Native player did not use the existing receipt endpoint');
  const commandsBefore = fixture.requests().filter(row => row.path === '/api/chat.php' && row.action === 'send').length;
  await page.locator('#chatInput').fill('pause'); await page.locator('#sendChatButton').click();
  await playbackState(followupCard, 'paused');
  equal(fixture.requests().filter(row => row.path === '/api/chat.php' && row.action === 'send').length, commandsBefore, 'Local pause did not re-run a search');
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('stop'));

  const mixed = await fixture.seed({playlist:true,title:'Night Walk',items:[{source:'vp3',source_id:'41'},
    {source:'youtube',source_id:'vp300000001'},{source:'vp3',source_id:'42'}]});
  const mixedCard = await attach(page, mixed, true);
  const quietNative = nativeCount(), quietYoutube = youtubeCount();
  equal(await mixedCard.locator('iframe').count(), 0, 'Playlist creation is quiet');
  await mixedCard.getByRole('button', {name:'Play playlist', exact:true}).click();
  await nativeStarted(page, mixedCard);
  await waitUntil(() => nativeCount() === quietNative + 1, 'First mixed native receipt missing');
  const resolveBeforeEnd = requestCount('resolve');
  const firstYouTubeResolve = page.waitForResponse(response => response.url().endsWith('/api/music-chat-v1.php')
    && response.request().postDataJSON()?.action === 'resolve'
    && response.request().postDataJSON()?.playlist_id === mixed.music_chat_v1.playlist.id
    && response.request().postDataJSON()?.index === 1);
  await page.locator('audio[data-music-chat-token]').last().evaluate(audio => { audio.currentTime = audio.duration - 0.15; });
  await mixedCard.locator('iframe').waitFor();
  const firstYouTubeAction = await (await firstYouTubeResolve).json();
  const youtubeChild = firstYouTubeAction.youtube_playlist_id;
  check(typeof youtubeChild === 'string' && /^[a-f0-9]{32}$/.test(youtubeChild), 'Mixed playback resolves its private YouTube child playlist');
  await playbackState(mixedCard, 'playing');
  equal(requestCount('resolve'), resolveBeforeEnd + 1, 'Native end advances mixed queue once');
  equal(await page.locator('audio').evaluateAll(elements => elements.filter(audio => !audio.paused).length), 0, 'YouTube owns audio after native handoff');
  check(await mixedCard.locator('iframe').isVisible(), 'YouTube remains a visible normal iframe');
  await waitUntil(() => youtubeCount() === quietYoutube + 1, 'YouTube player did not use its existing history endpoint');
  const beforeYouTubeEnd = requestCount('resolve');
  await page.evaluate(() => window.__musicYouTube.endTwice());
  await nativeStarted(page, mixedCard);
  equal(requestCount('resolve'), beforeYouTubeEnd + 1, 'Duplicate YouTube end callbacks advance only once');
  equal(await page.evaluate(() => window.__musicYouTube.players.filter(player => !player.destroyed && player.state === 1).length), 0, 'Native handoff stops YouTube');
  check((await mixedCard.innerText()).includes('First Light'), 'Mixed queue retains source order');
  await waitUntil(() => nativeCount() === quietNative + 2, 'Third mixed song native receipt missing');
  const nativeSession = fixture.readDb('SELECT session_id,play_id FROM music_listening_sessions_v1 WHERE requested_track_id=42 ORDER BY session_id DESC LIMIT 1')[0];
  const readNativeSession = () => fixture.readDb('SELECT state,last_seq FROM music_listening_sessions_v1 WHERE session_id=?', [nativeSession.session_id])[0];
  const beforeResumeSessions = nativeCount();
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('pause'));
  await playbackState(mixedCard, 'paused');
  await waitUntil(() => readNativeSession().state === 'paused', 'Native pause receipt was not accepted');
  const pausedSequence = Number(readNativeSession().last_seq);
  const beforeResume = requestCount('resolve');
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('resume'));
  equal(requestCount('resolve'), beforeResume + 1, 'Resume revalidates the selected source');
  await nativeStarted(page, mixedCard);
  await waitUntil(() => readNativeSession().state === 'playing' && Number(readNativeSession().last_seq) > pausedSequence,
    'Native resume did not advance the existing receipt session');
  equal(nativeCount(), beforeResumeSessions, 'Pause and Resume preserve the same accepted native listening session');
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('stop'));
  await playbackState(mixedCard, 'stopped');
  await waitUntil(() => readNativeSession().state === 'stopped', 'Music Stop did not close the canonical native listening session');
  await rows(mixedCard).nth(2).getByRole('button', {name:'Play First Light', exact:true}).click();
  await nativeStarted(page, mixedCard);
  await waitUntil(() => nativeCount() === beforeResumeSessions + 1, 'Same-song Play after Stop did not create one new accepted listening session');
  const restarted = fixture.readDb('SELECT session_id,play_id FROM music_listening_sessions_v1 WHERE requested_track_id=42 ORDER BY session_id DESC LIMIT 1')[0];
  check(restarted.session_id !== nativeSession.session_id && restarted.play_id !== nativeSession.play_id,
    'Explicit same-song replay has a new canonical session and play identity');
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('stop'));
  await waitUntil(() => fixture.readDb('SELECT state FROM music_listening_sessions_v1 WHERE session_id=?', [restarted.session_id])[0].state === 'stopped',
    'The replayed native session did not accept its terminal Stop');
  equal(nativeCount(), beforeResumeSessions + 1, 'Stop and same-song replay created exactly one new native session');
  await layout(page, 'desktop-playlist', mixedCard);

  // Queued results preserve the currently playing song and validate each saved
  // source selection when it reaches the front. Creating a ready queue is quiet.
  // The earlier handoff test deliberately ended track 41. Use a fresh song so
  // resuming its saved final fraction of a second cannot race this queue test.
  const queueBase = await fixture.seed({playlist:true,title:'Queue base',items:[{source:'vp3',source_id:'45'},{source:'vp3',source_id:'42'}]});
  const queueCard = await attach(page, queueBase, true);
  await queueCard.getByRole('button', {name:'Play playlist', exact:true}).click();
  await nativeStarted(page, queueCard);
  const beforeQueuedYouTube = youtubeCount();
  const appended = await fixture.seed({intent:'queue_append',autoplay:true,items:[{source:'youtube',source_id:'vp300000001'}]});
  const appendedCard = await attach(page, appended, true);
  await playbackState(appendedCard, 'queued');
  equal(await page.locator('audio').evaluateAll(elements => elements.filter(audio => !audio.paused).map(audio => audio.dataset.trackId)), ['45'],
    'Append leaves the current native song playing');
  equal(youtubeCount(), beforeQueuedYouTube, 'Queue append creates no YouTube listening receipt');
  const inserted = await fixture.seed({intent:'play_next',autoplay:true,items:[{source:'youtube',source_id:'vp300000002'}]});
  const insertedCard = await attach(page, inserted, true);
  await playbackState(insertedCard, 'queued');
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('next'));
  await playbackState(insertedCard, 'playing');
  check((await insertedCard.locator('iframe').getAttribute('src')).includes('/vp300000002?'), 'Play next inserts directly after the current song');
  await page.evaluate(() => window.__musicYouTube.endTwice());
  await nativeStarted(page, queueCard);
  equal(await page.locator('audio').evaluateAll(elements => elements.filter(audio => !audio.paused).map(audio => audio.dataset.trackId)), ['42'],
    'Inserted source finishes before the original playlist continuation');
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('next'));
  await playbackState(appendedCard, 'playing');
  check((await appendedCard.locator('iframe').getAttribute('src')).includes('/vp300000001?'), 'Appended source stays at the queue tail');
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('previous'));
  await nativeStarted(page, queueCard);
  equal(await page.locator('audio').evaluateAll(elements => elements.filter(audio => !audio.paused).map(audio => audio.dataset.trackId)), ['42'],
    'Previous returns across source boundaries to the exact queued selection');
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('stop'));
  const ready = await fixture.seed({intent:'queue_append',autoplay:true,items:[{source:'vp3',source_id:'41'}]});
  const readyCount = nativeCount(), readyYouTube = youtubeCount();
  const readyCard = await attach(page, ready, true);
  await playbackState(readyCard, 'queued');
  equal(await page.locator('audio').evaluateAll(elements => elements.filter(audio => !audio.paused).length), 0, 'Queue without active playback waits for Play');
  equal([nativeCount(),youtubeCount()], [readyCount,readyYouTube], 'Ready queue does not create listening receipts');
  const readyClaims = requestCount('resolve');
  await attach(page, {...ready,assistant_message_id:ready.assistant_message_id + 100000}, true);
  equal(requestCount('resolve'), readyClaims, 'A repeated queue action cannot append twice');
  await readyCard.locator('[data-music-toggle]').click();
  await nativeStarted(page, readyCard);
  await fixture.control({track_published:false,track_id:41});
  const deniedResume = await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('resume'));
  check(!/^Resuming/.test(deniedResume.answer), 'Revoked resume does not claim playback success');
  await playbackState(readyCard, 'unavailable');
  equal(await page.locator('audio').evaluateAll(elements => elements.filter(audio => !audio.paused).length), 0, 'Revoked resume pauses the previous player');
  await fixture.control({track_published:true,track_id:41});
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('stop'));

  // Private playlist mutations use stable item IDs and the current server revision.
  await mixedCard.getByRole('button', {name:'Edit playlist', exact:true}).click();
  await rows(mixedCard).nth(1).getByRole('button', {name:'Move up YouTube Song 1', exact:true}).click();
  await mixedCard.locator('[data-music-state="saved"]').waitFor();
  equal(await rows(mixedCard).evaluateAll(elements => elements.map(row => row.dataset.musicItemSource)), ['youtube','vp3','vp3'], 'Saved mixed playlist reorder');
  await rows(mixedCard).nth(2).getByRole('button', {name:'Remove First Light', exact:true}).click();
  await page.waitForFunction(id => document.querySelector(`[data-music-playlist-id="${id}"]`)?.querySelectorAll('.vp3-music-item').length === 2, mixed.music_chat_v1.playlist.id);
  const listResponse = await fixture.json(await fixture.request('playlist', {playlist_id:mixed.music_chat_v1.playlist.id}));
  const currentPlaylist = listResponse.music_chat_v1.playlist;
  await fixture.request('playlist_update', {playlist_id:currentPlaylist.id,revision:currentPlaylist.revision,title:'Updated elsewhere'});
  await rows(mixedCard).nth(0).getByRole('button', {name:'Remove YouTube Song 1', exact:true}).click();
  await mixedCard.getByText('This selection changed. Refresh it before continuing.', {exact:true}).waitFor();
  equal(await rows(mixedCard).count(), 2, 'Stale revision leaves the visible playlist intact');
  await mixedCard.getByRole('button', {name:'Refresh', exact:true}).click();
  await mixedCard.getByRole('heading', {name:'Updated elsewhere'}).waitFor();
  await mixedCard.getByLabel('Playlist name').fill('Night Walk');
  await mixedCard.getByRole('button', {name:'Save name', exact:true}).click();
  await mixedCard.getByRole('heading', {name:'Night Walk', exact:true}).waitFor();

  const addSource = await fixture.seed({items:[{source:'vp3',source_id:'42'}]});
  const addCard = await attach(page, addSource, true);
  await addCard.getByRole('button', {name:'Add to playlist', exact:true}).click();
  const savedRow = addCard.locator('.vp3-music-saved-row').filter({hasText:'Night Walk'});
  await savedRow.getByRole('button', {name:'Add', exact:true}).click();
  await addCard.getByText('Song added to the playlist.', {exact:true}).waitFor();
  equal(await rows(addCard).count(), 3, 'Add from exact stored search result');
  await fixture.control({track_published:false,track_id:41});
  await addCard.getByRole('button', {name:'Refresh', exact:true}).click();
  await addCard.getByText('Unavailable VP3 song', {exact:true}).waitFor();
  equal(await rows(addCard).count(), 3, 'Revoked item retains the other playlist entries');
  check(await addCard.locator('[data-music-item-id=""] button').first().isDisabled(), 'Revoked item cannot play');
  await fixture.control({track_published:true,track_id:41});
  await addCard.getByRole('button', {name:'Refresh', exact:true}).click();
  await addCard.getByText('Native Midnight', {exact:true}).waitFor();

  await page.reload();
  const restored = page.locator(`[data-chat-message-id="${mixed.assistant_message_id}"] .vp3-music-chat`).last();
  await restored.getByRole('heading', {name:'Night Walk', exact:true}).waitFor();
  equal(await rows(restored).evaluateAll(elements => elements.map(row => [row.dataset.musicItemSource,row.dataset.musicItemId])),
    [['youtube','vp300000001'],['vp3','41'],['vp3','42']], 'Edited playlist reload uses current items and current ordering');
  const selectedYouTubePlays = () => Number(fixture.readDb('SELECT COUNT(*) AS total FROM vp3_youtube_playlist_plays_v1 WHERE playlist_id=? AND video_id=?',
    [youtubeChild,'vp300000001'])[0].total);
  const beforeRestoredYouTube = selectedYouTubePlays();
  check(beforeRestoredYouTube >= 1, 'The selected YouTube child already has its initial accepted play');
  const restoredResolve = page.waitForResponse(response => response.url().endsWith('/api/music-chat-v1.php')
    && response.request().postDataJSON()?.action === 'resolve');
  await restored.getByRole('button', {name:'Play playlist', exact:true}).click();
  const restoredAction = await (await restoredResolve).json();
  equal([restoredAction.item.source,restoredAction.item.source_id], ['youtube','vp300000001'], 'Reopened playlist plays its current first item');
  equal(restoredAction.youtube_playlist_id, youtubeChild, 'Reopened playlist resolves the same validated YouTube child');
  await playbackState(restored, 'playing');
  await waitUntil(() => selectedYouTubePlays() === beforeRestoredYouTube + 1, 'Reopened selected playlist did not create exactly one additional accepted receipt');
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('stop'));

  // An accepted automatic action can start only once; history restoration is read-only.
  const automatic = await fixture.seed({items:[{source:'vp3',source_id:'41'}],autoplay:true});
  const autoCard = await attach(page, automatic, true);
  await nativeStarted(page, autoCard);
  const afterAuto = requestCount('resolve');
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('stop'));
  await attach(page, {...automatic,assistant_message_id:automatic.assistant_message_id + 100000}, true);
  equal(requestCount('resolve'), afterAuto, 'Repeated response cannot claim automatic play again');
  const nativeBeforeReload = nativeCount(), youtubeBeforeReload = youtubeCount();
  await page.reload();
  await page.locator(`[data-music-result-id="${automatic.music_chat_v1.result_id}"] .vp3-music-items`).waitFor();
  equal(requestCount('resolve'), afterAuto, 'History reload does not resolve a playback action');
  equal(nativeCount(), nativeBeforeReload, 'History reload does not create native listen');
  equal(youtubeCount(), youtubeBeforeReload, 'History reload does not create YouTube listen');

  // Cancellation of a real, delayed API completion must not play a stale source.
  const pendingSeed = await fixture.seed({items:[{source:'vp3',source_id:'42'}]});
  const pendingCard = await attach(page, pendingSeed, true);
  let release, observed, delivered;
  const pendingObserved = new Promise(resolve => { observed = resolve; });
  const pendingDelivered = new Promise(resolve => { delivered = resolve; });
  const gate = new Promise(resolve => { release = resolve; });
  const delayRoute = async route => {
    const input = route.request().postDataJSON();
    if (input?.action !== 'resolve') return route.continue();
    const response = await route.fetch(); observed(); await gate; await route.fulfill({response}); delivered();
  };
  await page.route('**/api/music-chat-v1.php', delayRoute);
  await pendingCard.getByRole('button', {name:'Play First Light', exact:true}).click();
  await pendingObserved;
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('pause'));
  release();
  await pendingDelivered;
  await page.unroute('**/api/music-chat-v1.php', delayRoute);
  await playbackState(pendingCard, 'paused');
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  equal(await page.locator('audio').evaluateAll(elements => elements.filter(audio => !audio.paused).length), 0, 'Late native resolution cannot defeat pause');

  const ytSeed = await fixture.seed({items:[{source:'youtube',source_id:'vp300000001'}]});
  const ytCard = await attach(page, ytSeed, true);
  await page.evaluate(() => window.__musicYouTube.blockNext());
  await ytCard.getByRole('button', {name:'Play YouTube Song 1', exact:true}).click();
  await playbackState(ytCard, 'blocked');
  check(!await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.isPlaying()), 'Blocked autoplay is not reported as playing');
  check(await ytCard.locator('iframe').isVisible(), 'Blocked YouTube retains visible iframe controls');
  await ytCard.locator('[data-music-toggle]').click();
  await playbackState(ytCard, 'playing');
  await page.evaluate(() => {
    const audio = document.createElement('audio'); audio.className = 'chat-audio-player'; audio.dataset.trackId = '42';
    audio.id = 'fixtureNativeAudio';
    audio.src = '/media.php?track=42&type=audio'; document.body.append(audio);
    window.dispatchEvent(new CustomEvent('vp3:audio-players-added', {detail:document.body}));
    const button = document.createElement('button'); button.id = 'fixtureNativeOwner'; button.textContent = 'Start existing VP3 player';
    button.onclick = () => { window.__musicNativeTakeover = audio.play(); }; document.body.append(button);
  });
  await page.locator('#fixtureNativeOwner').click();
  await page.evaluate(async () => {
    if (!window.__musicNativeTakeover) throw new Error('The native user Play request was not made.');
    await window.__musicNativeTakeover;
  });
  check(await page.locator('#fixtureNativeAudio').evaluate(audio => !audio.paused && audio.readyState >= 3), 'The exact existing native player started playback');
  equal(await page.evaluate(() => window.__musicYouTube.players.filter(player => !player.destroyed && player.state === 1).length), 0, 'Existing native player takes audio ownership from YouTube');

  const failedNext = await fixture.seed({playlist:true,title:'Unavailable next',items:[
    {source:'vp3',source_id:'41'},{source:'vp3',source_id:'42'}]});
  const failedNextCard = await attach(page, failedNext, true);
  await failedNextCard.getByRole('button', {name:'Play playlist', exact:true}).click();
  await nativeStarted(page, failedNextCard);
  await fixture.control({track_published:false,track_id:42});
  const nextReply = await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('next'));
  check(/could not be opened/.test(nextReply.answer), 'Failed next resolve is reported accurately in Chat');
  check(!/^Opened/.test(nextReply.answer), 'Failed next resolve does not claim that playback opened');
  await playbackState(failedNextCard, 'unavailable');
  await fixture.control({track_published:true,track_id:42});
  await page.evaluate(() => window.dispatchEvent(new Event('stonefellow:chat-context-changing')));
  equal(await cards(page).count(), 0, 'Context transition removes private cards');

  // Phone-size rendering uses the same production controllers and real API.
  const mobile = await pageFor({width:390, height:844});
  const phone = mobile.page;
  const phoneSong = await send(phone, 'show me one YouTube song');
  await layout(phone, 'phone-result', resultCard(phone, phoneSong));
  const phonePlaylist = await fixture.seed({playlist:true,title:'Phone playlist',items:[{source:'vp3',source_id:'41'},
    {source:'youtube',source_id:'vp300000001'},{source:'vp3',source_id:'42'}]});
  const phoneCard = await attach(phone, phonePlaylist, true);
  await phoneCard.getByRole('button', {name:'Edit playlist', exact:true}).click();
  await layout(phone, 'phone-playlist-edit', phoneCard);
  await lowerPhoneLayout(phone, 'phone-playlist-edit', rows(phoneCard).last().getByRole('button', {name:'Remove First Light', exact:true}));
  await phoneCard.getByRole('button', {name:'Done editing', exact:true}).click();
  await rows(phoneCard).nth(1).getByRole('button', {name:'Play YouTube Song 1', exact:true}).click();
  await playbackState(phoneCard, 'playing');
  await layout(phone, 'phone-visible-youtube', phoneCard);
  await lowerPhoneLayout(phone, 'phone-visible-youtube', phoneCard.getByRole('button', {name:'Close player', exact:true}));
  await phone.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('stop'));
  await phoneCard.getByRole('button', {name:'Edit playlist', exact:true}).click();
  await phoneCard.getByRole('button', {name:'Delete playlist', exact:true}).click();
  await phoneCard.getByRole('button', {name:'Delete playlist permanently', exact:true}).click();
  await phoneCard.getByText('Private playlist deleted.', {exact:true}).waitFor();
  const removed = await fixture.request('playlist', {playlist_id:phonePlaylist.music_chat_v1.playlist.id});
  equal(removed.status, 404, 'Delete removes the owner playlist through the real service');
  await phoneCard.getByRole('button', {name:'My playlists', exact:true}).click();
  await phoneCard.getByRole('heading', {name:'Your private playlists'}).waitFor();
  equal(await phoneCard.locator('.vp3-music-saved-row').filter({hasText:'Phone playlist'}).count(), 0, 'Deleted list is absent from saved picker');
  await desktop.context.close(); await mobile.context.close();
  equal(errors, [], 'No unhandled browser errors');
  await fs.writeFile(path.join(artifactDir, 'acceptance.json'), JSON.stringify({engine:engineName,checks,
    php:'actual production Music Chat/API, canonical media/receipts and YouTube history',
    youtube:'explicit external search and IFrame API mocks; no live Google certification'}, null, 2));
  console.log(`Music Chat ${engineName}: ${checks} checks passed; screenshots in ${artifactDir}. External YouTube APIs explicitly mocked.`);
} catch (error) {
  // Keep the original failing assertion, plus the actual browser/PHP evidence.
  // In particular, bootstrap errors must not disappear behind a readiness timeout.
  console.error('Music Chat browser failure:', JSON.stringify({engine:engineName, checks, errors}));
  const pages = [];
  for (const [index, page] of openedPages.entries()) {
    if (page.isClosed()) continue;
    const state = await page.evaluate(() => ({
      url:location.href,
      musicLoaded:!!window.VP3_MUSIC_CHAT_V1,
      continuityLoaded:!!window.STONEFELLOW_CHAT_CONTINUITY,
      conversationId:window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.(),
      cards:document.querySelectorAll('.vp3-music-chat').length,
      audio:[...document.querySelectorAll('audio')].map(audio => ({
        trackId:audio.dataset.trackId, paused:audio.paused, seeking:audio.seeking, ended:audio.ended, readyState:audio.readyState,
        currentTime:audio.currentTime, duration:audio.duration
      })),
      nativeEvents:window.__musicNativeEvents
    })).catch(reason => ({error:String(reason)}));
    pages.push(state);
    await Promise.allSettled([
      page.screenshot({path:path.join(artifactDir, `failure-page-${index}.png`), fullPage:false}),
      page.content().then(html => fs.writeFile(path.join(artifactDir, `failure-page-${index}.html`), html))
    ]);
  }
  let nativeSessions;
  try {
    nativeSessions = fixture.readDb('SELECT session_id,requested_track_id,state,last_seq,created_ms FROM music_listening_sessions_v1 ORDER BY session_id');
  } catch (reason) { nativeSessions = {error:String(reason)}; }
  await Promise.allSettled([
    fs.writeFile(path.join(artifactDir, 'failure.json'), JSON.stringify({engine:engineName, checks,
      error:String(error.stack || error), errors, pages, nativeSessions}, null, 2)),
    fs.writeFile(path.join(artifactDir, 'php.log'), fixture.logs)
  ]);
  throw error;
} finally {
  await browser?.close(); fixture.close();
}
