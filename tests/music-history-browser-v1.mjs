import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {chromium, webkit} from 'playwright';
import {startMusicHistoryFixture,startMusicHistoryVoiceBrowserFixture} from './music-history-fixture-v1.mjs';

// Production history reader/API, bound Chat services and existing native/YouTube
// controllers. Only external Google search and IFrame API transport are mocked.
const engine = process.env.MUSIC_HISTORY_BROWSER || 'chromium';
assert.ok(['chromium','webkit'].includes(engine));
const artifacts = path.resolve(process.env.MUSIC_HISTORY_ARTIFACT_DIR || `test-results/music-history-${engine}`);
await fs.mkdir(artifacts, {recursive:true});
const fixture = await startMusicHistoryFixture();
const pages = [];
const errors = [];
const captures = [];
let browser;
let voiceFixture;
let checks = 0;
const check = (actual, message) => { assert.ok(actual, message); checks += 1; };
const equal = (actual, expected, message) => { assert.deepEqual(actual, expected, message); checks += 1; };
const nativeCount = () => Number(fixture.readDb('SELECT COUNT(*) AS total FROM track_play_sessions WHERE user_id=11')[0].total);
const youtubeCount = () => Number(fixture.readDb('SELECT (SELECT COUNT(*) FROM vp3_youtube_playback_v1 WHERE owner_user_id=11)+(SELECT COUNT(*) FROM vp3_youtube_playlist_plays_v1 WHERE owner_user_id=11) AS total')[0].total);
const messageCount = () => Number(fixture.readDb('SELECT COUNT(*) AS total FROM chat_messages WHERE conversation_id=43')[0].total);
const requestCount = action => fixture.requests().filter(row => row.path === '/api/music-history-v1.php' && row.action === action).length;
const historyCard = (page, data) => page.locator(`[data-chat-message-id="${data.music_history_v1.source_message_id}"] .vp3-music-history`);
const musicCard = (page, data) => page.locator(`[data-chat-message-id="${data.assistant_message_id}"] .vp3-music-chat`);
const rows = card => card.locator('.vp3-music-history-item');
const historyResponse = (page, action) => page.waitForResponse(response => response.url().endsWith('/api/music-history-v1.php')
  && response.request().postDataJSON()?.action === action);
const resolveResponse = page => page.waitForResponse(response => response.url().endsWith('/api/music-chat-v1.php')
  && response.request().postDataJSON()?.action === 'resolve');

function iframeMock() {
  const players = [];
  const states = {UNSTARTED:-1,ENDED:0,PLAYING:1,PAUSED:2,BUFFERING:3,CUED:5};
  window.__historyYouTube = {players, active:() => players.findLast(player => !player.destroyed)};
  window.YT = {PlayerState:states, Player:class {
    constructor(frame, options) {
      this.frame = frame; this.events = options.events; this.position = Number(new URL(frame.src).searchParams.get('start') || 0);
      this.started = performance.now(); this.state = states.PLAYING; this.destroyed = false; players.push(this);
      queueMicrotask(() => { if (!this.destroyed) { this.events.onReady({target:this}); this.events.onStateChange({target:this,data:this.state}); } });
    }
    getPlayerState() { return this.state; }
    getCurrentTime() { return this.position + (this.state === states.PLAYING ? (performance.now()-this.started)/1000 : 0); }
    getDuration() { return 60; }
    getPlaybackRate() { return 1; }
    getVideoUrl() { return 'https://www.youtube.com/watch?v=' + new URL(this.frame.src).pathname.split('/').at(-1); }
    getVolume() { return 100; }
    isMuted() { return false; }
    seekTo(position) { this.position = position; this.started = performance.now(); }
    playVideo() { this.position = this.getCurrentTime(); this.started = performance.now(); this.state = states.PLAYING; this.events.onStateChange({target:this,data:this.state}); }
    pauseVideo() { this.position = this.getCurrentTime(); this.state = states.PAUSED; this.events.onStateChange({target:this,data:this.state}); }
    destroy() { this.position = this.getCurrentTime(); this.state = states.PAUSED; this.destroyed = true; }
  }};
}

function voiceInputsMock() {
  // Hardware recognition and speech output are explicit external boundaries;
  // the shipped voice client still performs guards, submission and NDJSON work.
  const recognizers = [];
  window.__historySpeech = [];
  window.SpeechRecognition = class {
    constructor() { this.started = false; recognizers.push(this); }
    start() { this.started = true; queueMicrotask(() => this.onstart?.()); }
    stop() { this.started = false; queueMicrotask(() => this.onend?.()); }
    abort() { this.stop(); }
  };
  window.__historyRecognizers = recognizers;
  window.__historySay = text => {
    const current = recognizers.findLast(recognizer => recognizer.started);
    if (!current) throw new Error('The actual voice client has not started recognition.');
    current.onresult({resultIndex:0,results:[{0:{transcript:text,confidence:.99},isFinal:true}]});
  };
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:undefined});
  window.StonefellowPremiumVoiceV122 = () => ({
    warm:async()=>true,
    createStream(callbacks={}) {
      let started = false; let resolve;
      const promise = new Promise(done => { resolve = done; });
      return {started:promise,push(value) { window.__historySpeech.push(value); if (!started) { started=true; callbacks.onStart?.(); resolve(true); } },
        end() { queueMicrotask(() => callbacks.onEnd?.()); },stop() {}};
    },
    speak:async(value,callbacks={}) => { window.__historySpeech.push(value); callbacks.onStart?.(); queueMicrotask(() => callbacks.onEnd?.()); return true; },
    stop() {}
  });
}

async function pageFor(viewport = {width:1280,height:900}) {
  const context = await browser.newContext({viewport, hasTouch:viewport.width < 600, extraHTTPHeaders:{'X-User':'11'}});
  await context.addInitScript(iframeMock);
  const page = await context.newPage(); pages.push(page);
  page.on('pageerror', error => errors.push(error.message));
  await page.route('https://www.youtube.com/**', route => route.fulfill({contentType:'text/html',body:
    '<!doctype html><html lang="en"><body style="margin:0;background:#161616;color:white;font:16px Arial;display:grid;place-items:center;height:100vh"><div>Mocked YouTube IFrame API<br><small>Visible player acceptance fixture</small></div></body></html>'}));
  await page.route('https://i.ytimg.com/**', route => route.fulfill({contentType:'image/svg+xml',body:
    '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="120" height="120" fill="#26384d"/><path d="M48 35l36 25-36 25z" fill="white"/></svg>'}));
  await page.goto(fixture.baseURL + '/chat.php?conversation_id=43');
  await page.waitForFunction(() => window.VP3_MUSIC_HISTORY_V1 && window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.() === 43);
  await page.locator('#chatInput').waitFor();
  return {page, context};
}
async function readyHistory(page, data) {
  check(data.music_history_v1?.protocol === 'vp3_music_history_v1', 'A history response uses the history protocol');
  const card = historyCard(page, data);
  await card.locator('.vp3-music-history-items').waitFor();
  await page.waitForFunction(({id, revision}) => document.querySelector(`[data-history-message-id="${id}"]`)?.dataset.historyRevision === revision,
    {id:data.music_history_v1.source_message_id, revision:data.music_history_v1.revision});
  return card;
}
async function send(page, message) {
  await page.locator('#chatInput').fill(message);
  const response = page.waitForResponse(response => response.url().endsWith('/api/chat.php') && response.request().postDataJSON()?.action === 'send');
  await page.locator('#sendChatButton').click();
  const result = await response; const data = await result.json();
  equal(result.status(), 200, `${message}: ${JSON.stringify(data)}`);
  if (data.music_history_v1) await readyHistory(page, data);
  if (data.music_chat_v1) await musicCard(page, data).locator('.vp3-music-items').waitFor();
  return data;
}
async function apply(page, card, fields) {
  for (const [name, value] of Object.entries(fields)) {
    const input = card.locator(`[name="${name}"]`);
    if (['source','limit'].includes(name)) await input.selectOption(String(value));
    else await input.fill(String(value));
  }
  const response = historyResponse(page, 'query');
  await card.getByRole('button', {name:'Apply filters',exact:true}).click();
  const result = await response; const data = await result.json();
  equal(result.status(), 200, 'History filters use the actual service: ' + JSON.stringify(data.error || 'ok'));
  return {data, card:await readyHistory(page, data)};
}
async function rowFor(card, ref) {
  const refs = await rows(card).evaluateAll(items => items.map(item => item.dataset.historyEntryRef));
  const index = refs.indexOf(ref); check(index >= 0, 'The exact retained receipt is displayed'); return rows(card).nth(index);
}
async function waitUntil(fn, message) {
  for (let attempt=0; attempt<80; attempt++) {
    if (fn()) { checks += 1; return; }
    await new Promise(resolve => setTimeout(resolve,75));
  }
  assert.fail(message + '\n' + fixture.logs);
}
async function nativeStarted(page, card) {
  await card.locator('[data-music-state="playing"],[data-music-state="blocked"],[data-music-state="unavailable"]').waitFor();
  if (await card.locator('[data-music-state="blocked"]').count()) {
    const toggle = page.locator('#chatNowPlayingToggle');
    if (await toggle.isVisible()) await toggle.click();
    else await page.locator('[data-listening-toggle]').click();
  }
  await card.locator('[data-music-state="playing"]').waitFor();
  await page.waitForFunction(() => [...document.querySelectorAll('audio.chat-audio-player')].some(audio => audio.dataset.trackId === '71' && !audio.paused && audio.readyState >= 3));
}
async function stop(page) {
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('stop'));
  await page.waitForFunction(() => [...document.querySelectorAll('audio.chat-audio-player')].every(audio => audio.paused)
    && window.__historyYouTube.players.every(player => player.destroyed || player.state !== 1));
}
async function select(page, card, ref, operation) {
  const row = await rowFor(card, ref);
  const response = historyResponse(page, 'selection');
  const resolve = operation === 'add_to_playlist' ? null : resolveResponse(page);
  const control = operation === 'resume' ? row.getByRole('button', {name:/^Resume listen /})
    : operation === 'play' ? row.getByRole('button', {name:/^Play listen /}) : row.getByRole('button', {name:/^Add listen /});
  await control.click();
  const result = await response; const data = await result.json();
  equal(result.status(), 200, `Selected historical ${operation}: ${JSON.stringify(data.error || 'ok')}`);
  const selected = musicCard(page, data); await selected.locator('.vp3-music-items').waitFor();
  const resolved = resolve ? await (await resolve).json() : null;
  return {data, card:selected, resolved};
}
async function capture(page, label, card, lower = null) {
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), label + ': no horizontal overflow');
  const violations = await card.evaluate(element => [...element.querySelectorAll('*')].flatMap(child => {
    const style = getComputedStyle(child); const errors = [];
    if (['auto','scroll'].includes(style.overflowY) && child.scrollHeight > child.clientHeight + 2) errors.push('nested vertical scroller');
    if (child.matches('button,input,select,summary') && child.getClientRects().length && child.getBoundingClientRect().height < 43.5) errors.push('small touch target');
    return errors;
  }));
  equal(violations, [], label + ': normal scrolling and touch targets');
  await card.evaluate(element => element.scrollIntoView({block:'start'}));
  await page.screenshot({path:path.join(artifacts, label + '.png'), fullPage:false}); captures.push(label + '.png');
  if (lower) {
    await lower.evaluate(element => element.scrollIntoView({block:'center'}));
    await lower.click({trial:true});
    check(await lower.evaluate(element => {
      const box = element.getBoundingClientRect();
      const hit = document.elementFromPoint(box.left+box.width/2, box.top+box.height/2);
      return box.top >= 0 && box.bottom <= innerHeight && box.left >= 0 && box.right <= innerWidth && (hit === element || element.contains(hit));
    }), label + ': lower control is in the viewport and unobscured');
    await page.screenshot({path:path.join(artifacts, label + '-lower.png'), fullPage:false}); captures.push(label + '-lower.png');
  }
}

try {
  const executablePath = engine === 'chromium' ? process.env.MUSIC_HISTORY_CHROMIUM_EXECUTABLE : '';
  browser = await ({chromium,webkit}[engine]).launch({headless:true,...(executablePath ? {executablePath} : {})});
  const desktop = await pageFor(); const page = desktop.page;
  const quietCounts = [nativeCount(),youtubeCount()];
  equal(await page.locator('.vp3-music-chat').count(), 0, 'Player history is discoverable before an MC1 card exists');
  equal(requestCount('query') + requestCount('read'), 0, 'Mounting History does not query or create a Chat turn');
  const beforeEntryMessages = messageCount();
  await page.locator('[data-chat-view-target="player"]').click();
  const initialResponse = page.waitForResponse(response => response.url().endsWith('/api/chat.php') && response.request().postDataJSON()?.action === 'send');
  await page.locator('[data-music-history-open]').click();
  const initial = await (await initialResponse).json();
  let card = await readyHistory(page, initial);
  check(messageCount() > beforeEntryMessages, 'Explicit Player History uses the normal saved Chat turn');
  equal([nativeCount(),youtubeCount()], quietCounts, 'Opening history creates no listening receipts');
  check((await card.innerText()).includes('not a complete lifetime archive'), 'Retained coverage is explicit');
  let filtered = await apply(page, card, {limit:5}); card = filtered.card;
  equal(filtered.data.music_history_v1.items.map(item => item.entry_ref), [fixture.seeded.refs.native,fixture.seeded.refs.ordinary,
    fixture.seeded.refs.playlist,fixture.seeded.refs.repeat,fixture.seeded.refs.legacy], 'Individual receipt order preserves native and YouTube repeats');
  check((await card.innerText()).includes('Reported listening'), 'Legacy reported duration is not labeled as accepted measurement');
  await capture(page, 'desktop-history', card, rows(card).last().getByRole('button', {name:/^Add listen /}));

  const firstRefs = filtered.data.music_history_v1.items.map(item => item.entry_ref);
  const olderResponse = historyResponse(page, 'query'); await card.getByRole('button', {name:'Older listens',exact:true}).click();
  const older = await (await olderResponse).json(); card = await readyHistory(page, older);
  check(older.music_history_v1.items.every(item => !firstRefs.includes(item.entry_ref)), 'Older page does not repeat first-page receipts');
  equal(older.music_history_v1.as_of, filtered.data.music_history_v1.as_of, 'Older pagination preserves the original snapshot cutoff');
  check(await card.getByRole('button', {name:'Refresh newest',exact:true}).isEnabled(), 'Returning to newest is explicitly a fresh query');
  await capture(page, 'desktop-older', card, rows(card).last().getByRole('button', {name:/^Add listen /}));
  const newestResponse = historyResponse(page, 'query'); await card.getByRole('button', {name:'Refresh newest',exact:true}).click();
  const newest = await (await newestResponse).json(); card = await readyHistory(page, newest);
  equal(newest.music_history_v1.items.map(item => item.entry_ref), firstRefs, 'A deliberate newest refresh restores current top-page order');

  // Hold a real old read while a newer same-message server query is attached.
  // A replay/poll response must never replace a newer visible revision.
  let oldReadReady; const oldReadReached = new Promise(resolve => { oldReadReady = resolve; });
  let releaseOldRead; const oldReadRelease = new Promise(resolve => { releaseOldRead = resolve; });
  const oldReadRoute = async route => {
    const input = route.request().postDataJSON();
    if (input.action !== 'read') return route.continue();
    const response = await route.fetch(); oldReadReady(); await oldReadRelease;
    await route.fulfill({response}).catch(() => {});
  };
  await page.route('**/api/music-history-v1.php', oldReadRoute);
  await page.evaluate(payload => { const message=document.querySelector(`[data-chat-message-id="${payload.source_message_id}"]`);
    window.__oldHistoryRead=window.VP3_MUSIC_HISTORY_V1.attach(message,payload,{live:false}); }, newest.music_history_v1);
  await oldReadReached;
  const freshResponse = await fixture.historyRequest('query', {source_message_id:newest.music_history_v1.source_message_id,
    revision:newest.music_history_v1.revision,source:'vp3',query:'Moonlight',timezone:'UTC',limit:5,
    from_at:new Date((fixture.seeded.clock-61)*1000).toISOString(),until_at:new Date((fixture.seeded.clock+1)*1000).toISOString()});
  const fresh = await fixture.json(freshResponse); equal(freshResponse.status, 200, 'Newer bound query commits while old read is delayed');
  await page.evaluate(payload => window.VP3_MUSIC_HISTORY_V1.attach(document.querySelector(`[data-chat-message-id="${payload.source_message_id}"]`),payload,{live:true}), fresh.music_history_v1);
  releaseOldRead(); await page.evaluate(() => window.__oldHistoryRead); await page.unroute('**/api/music-history-v1.php', oldReadRoute);
  card = await readyHistory(page, fresh);
  equal(await rows(card).count(), 2, 'Late old read cannot overwrite the newer filtered attachment');
  equal(await card.getAttribute('data-history-revision'), fresh.music_history_v1.revision, 'Newer attachment revision survives stale completion');
  check(await card.getAttribute('aria-busy') !== 'true', 'Accepted live attachment releases the superseded read state');
  check((await card.locator('.vp3-music-history-window').innerText()).includes('Time window:'), 'Exact-time history displays its actual window instead of a whole-day claim');
  const retainedWindow = await apply(page,card,{query:'Moonlight'}); card=retainedWindow.card;
  equal(retainedWindow.data.music_history_v1.filters.from_at,fresh.music_history_v1.filters.from_at,'Changing a title filter preserves the exact time window');
  await capture(page,'desktop-time-window',card,rows(card).last().getByRole('button',{name:/^Add listen /}));
  equal([nativeCount(),youtubeCount()], quietCounts, 'Reads, filters and pagination remain quiet');
  const day = new Date(fixture.seeded.clock*1000).toISOString().slice(0,10);
  filtered = await apply(page, card, {source:'vp3',query:'Moonlight',from:day,to:day,limit:5}); card = filtered.card;
  check(!filtered.data.music_history_v1.filters.from_at && filtered.data.music_history_v1.filters.from===day,
    'Editing calendar dates explicitly replaces the exact-time window');
  equal(filtered.data.music_history_v1.items.map(item => item.entry_ref), [fixture.seeded.refs.native,fixture.seeded.refs.repeat], 'Source, date and title filters preserve separate matching plays');
  equal(await rows(card).nth(1).getByRole('button', {name:/^Resume listen /}).count(), 0, 'Started-only receipt cannot claim a resumable position');
  await capture(page, 'desktop-filtered', card, rows(card).last().getByRole('button', {name:/^Add listen /}));

  const typedResolve = resolveResponse(page);
  const selectedSecond = await send(page, 'play the second one');
  const secondResolved = await (await typedResolve).json();
  equal(secondResolved.media_playback.history_ref, fixture.seeded.refs.repeat, 'Typed ordinal selects the displayed repeat receipt, not a new search');
  await nativeStarted(page, musicCard(page, selectedSecond));
  await waitUntil(() => nativeCount() === quietCounts[0]+1, 'Exact historical Play must create one accepted native session');
  equal(secondResolved.media_playback.operation, 'play', 'Historical Play starts from the beginning');
  await stop(page);

  const resumed = await select(page, card, fixture.seeded.refs.native, 'resume');
  equal(resumed.resolved.media_playback.history_ref, fixture.seeded.refs.native, 'Resume is bound to the exact measured historical receipt');
  equal(resumed.resolved.media_playback.position_seconds, 22, 'Native resume position is server-validated historical position');
  await nativeStarted(page, resumed.card);
  await waitUntil(() => nativeCount() === quietCounts[0]+2, 'Exact historical Resume creates one accepted native session');
  check(await page.evaluate(() => [...document.querySelectorAll('audio.chat-audio-player')].some(audio => audio.dataset.trackId==='71'
    && !audio.paused && audio.currentTime>=21 && audio.currentTime<30)), 'The canonical native player actually resumes near22seconds');
  const start = fixture.readDb('SELECT s.position_ms,s.requested_track_id FROM music_listening_sessions_v1 s JOIN track_play_sessions t ON t.id=s.session_id WHERE t.user_id=11 ORDER BY s.session_id DESC LIMIT 1')[0];
  equal(Number(start.requested_track_id), 71, 'Accepted native receipt belongs to the selected canonical song');
  check(Number(start.position_ms)>=21000, 'Accepted native receipt retains the resumed position');
  await capture(page, 'desktop-native-resume', resumed.card);
  await stop(page);

  filtered = await apply(page, card, {source:'youtube',query:'',from:'',to:'',limit:5}); card = filtered.card;
  equal(filtered.data.music_history_v1.items.map(item => item.entry_ref), [fixture.seeded.refs.ordinary,fixture.seeded.refs.playlist], 'Ordinary and private-playlist YouTube receipts remain separate');
  const playlistResume = await select(page, card, fixture.seeded.refs.playlist, 'resume');
  equal(playlistResume.resolved.resume_ms, 15000, 'Saved-list receipt resolves its own15second position');
  await playlistResume.card.locator('[data-music-state="playing"]').waitFor();
  await page.evaluate(() => window.VP3_MUSIC_CHAT_V1.runCommand('pause'));
  const ordinaryResume = await select(page, card, fixture.seeded.refs.ordinary, 'resume');
  equal(ordinaryResume.resolved.resume_ms, 12000, 'Earlier ordinary receipt resolves its exact12second position');
  await ordinaryResume.card.locator('[data-music-state="playing"]').waitFor();
  check((await ordinaryResume.card.locator('iframe').getAttribute('src')).includes('start=12'), 'Visible YouTube player uses exact resume, not the newer same-video cache');
  equal(await page.evaluate(() => window.__historyYouTube.players.filter(player => !player.destroyed && player.state===1).length), 1, 'Only one YouTube player is active');
  equal(await page.locator('audio.chat-audio-player').evaluateAll(audios => audios.filter(audio => !audio.paused).length), 0, 'Historical YouTube playback owns audio without a second native player');
  await waitUntil(() => youtubeCount() === quietCounts[1]+2, 'Both explicit YouTube resumes must write one accepted source receipt each');
  await capture(page, 'desktop-youtube-resume', ordinaryResume.card, ordinaryResume.card.getByRole('button', {name:'Close player',exact:true}));
  await stop(page);

  const target = await fixture.seed({conversation_id:43,playlist:true,title:'Saved history',items:[{source:'vp3',source_id:'41'}]});
  filtered = await apply(page, card, {source:'vp3',query:'Moonlight',limit:5}); card = filtered.card;
  const beforeAdd = [nativeCount(),youtubeCount()];
  const addition = await select(page, card, fixture.seeded.refs.native, 'add_to_playlist');
  await addition.card.getByRole('heading', {name:'Add to a playlist',exact:true}).waitFor();
  const saved = addition.card.locator('.vp3-music-saved-row').filter({hasText:'Saved history'});
  await saved.getByRole('button', {name:'Add',exact:true}).click();
  await addition.card.locator('[data-music-state="saved"]').waitFor();
  const savedPlaylist = await fixture.json(await fixture.request('playlist', {playlist_id:target.music_chat_v1.playlist.id,conversation_id:43}));
  equal(savedPlaylist.music_chat_v1.items.map(item => item.source_id), ['41','71'], 'History Add uses the existing private playlist writer for the exact source item');
  equal([nativeCount(),youtubeCount()], beforeAdd, 'Adding a historical selection does not play it');

  // Current view authorization is stricter than stale source metadata.
  fixture.run("db()->exec(\"UPDATE tracks SET owner_user_id=22,visibility='private' WHERE id=71\");");
  filtered = await apply(page, card, {source:'vp3',query:'',from:'',to:'',limit:20}); card = filtered.card;
  const revoked = await rowFor(card, fixture.seeded.refs.native);
  check(!(await revoked.innerText()).includes('History Moonlight'), 'Revoked native title is redacted on current query');
  check(await revoked.getByRole('button', {name:/^Play listen /}).isDisabled(), 'Unavailable native history cannot play');
  equal(await revoked.getByRole('button', {name:/^Resume listen /}).count(), 0, 'Unavailable historical source cannot resume');
  await capture(page, 'desktop-unavailable', revoked);
  const beforeReload = [nativeCount(),youtubeCount(),messageCount(),requestCount('query')];
  await page.reload();
  await page.waitForFunction(() => window.VP3_MUSIC_HISTORY_V1 && window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()===43);
  await readyHistory(page, filtered.data);
  await page.waitForFunction(() => [...document.querySelectorAll('.vp3-music-history')].every(card => card.getAttribute('aria-busy')!=='true'));
  equal([nativeCount(),youtubeCount(),messageCount(),requestCount('query')], beforeReload, 'Restoring Chat uses quiet reads without a new query, turn or playback');
  check(requestCount('read')>0, 'Restored history is rehydrated by the current-authority read endpoint');
  check(!(await page.locator('.message.assistant:has(.vp3-music-history)').allTextContents()).some(value => value.includes('History Moonlight')),
    'Restored history does not resurrect revoked titles in cards or parent answers');
  fixture.run("db()->exec(\"UPDATE tracks SET owner_user_id=11,visibility='public' WHERE id=71\");");

  // Real phone-sized controls use the same API and production layout.
  for (const width of [390,320]) {
    const mobile = await pageFor({width,height:844});
    const result = await send(mobile.page, 'show my listening history');
    const initialCard = historyCard(mobile.page, result);
    const narrow = await apply(mobile.page, initialCard, {source:'vp3',query:'Moonlight',limit:5});
    await capture(mobile.page, 'phone-'+width+'-history', narrow.card, rows(narrow.card).last().getByRole('button', {name:/^Add listen /}));
    const nativeBefore = nativeCount();
    const shown = await select(mobile.page, narrow.card, fixture.seeded.refs.native, 'resume');
    await nativeStarted(mobile.page, shown.card);
    await waitUntil(() => nativeCount()===nativeBefore+1, 'Phone explicit Resume reaches accepted canonical receipts');
    await capture(mobile.page, 'phone-'+width+'-resume', shown.card);
    await stop(mobile.page);
    await mobile.context.close();
  }

  await page.evaluate(() => window.dispatchEvent(new Event('stonefellow:chat-context-changing')));
  equal(await page.locator('.vp3-music-history').count(), 0, 'Context transition clears owner-private history cards');
  await desktop.context.close();

  // Real typed and voice PHP controllers, plus the actual browser voice-to-Chat
  // renderer. Repeating the exact wire request models a transport retry only;
  // every response is produced by PHP and rehydrates current source authority.
  voiceFixture = await startMusicHistoryVoiceBrowserFixture(fixture);
  const voiceContext = await browser.newContext({viewport:{width:1280,height:900}});
  const [cookieName,cookieValue] = voiceFixture.cookie.split(';')[0].split('=');
  await voiceContext.addCookies([{name:cookieName,value:cookieValue,url:voiceFixture.baseURL}]);
  await voiceContext.addInitScript(iframeMock);
  await voiceContext.addInitScript(voiceInputsMock);
  const voicePage = await voiceContext.newPage(); pages.push(voicePage);
  voicePage.on('pageerror', error => errors.push(error.message));
  await voicePage.route('https://i.ytimg.com/**', route => route.fulfill({contentType:'image/svg+xml',body:
    '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="120" height="120" fill="#26384d"/></svg>'}));
  const voiceRequests = new Map(); let voiceWireCount=0;
  await voicePage.route('**/api/chat-stream-v121.php*', async route => {
    const input = route.request().postDataJSON(); voiceWireCount += 1;
    if (!voiceRequests.has(input.message)) voiceRequests.set(input.message, {...input,idempotency_key:'history-browser-'+engine+'-'+voiceRequests.size});
    await route.continue({postData:JSON.stringify(voiceRequests.get(input.message))});
  });
  await voicePage.goto(voiceFixture.baseURL + '/history-browser.html');
  await voicePage.waitForFunction(() => window.VP3ConversationControls && window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()===14
    && window.STONEFELLOW_CHAT_CONTINUITY.isReady() && !window.STONEFELLOW_CHAT_CONTINUITY.isRestoring());
  const voiceState = await voiceFixture.state();
  const voiceCounts = [voiceState.track_play_sessions.length,voiceState.vp3_youtube_playback_v1.length,voiceState.vp3_youtube_playlist_plays_v1.length];
  async function voiceTurn(message) {
    await voicePage.getByRole('button', {name:'Start voice conversation',exact:true}).click();
    await voicePage.waitForFunction(() => window.__historyRecognizers.some(recognizer => recognizer.started));
    const response = voicePage.waitForResponse(response => response.url().includes('/api/chat-stream-v121.php'));
    await voicePage.evaluate(message => window.__historySay(message), message);
    const received = await response; equal(received.status(), 200, 'Canonical browser voice reaches the actual NDJSON endpoint');
    const events = (await received.text()).trim().split('\n').map(line => JSON.parse(line));
    const data = events.findLast(event => event.type==='done')?.data;
    check(data?.ok===true, 'Voice completes with a real accepted server response');
    if (data.music_history_v1) await readyHistory(voicePage,data);
    if (data.music_chat_v1) await musicCard(voicePage,data).locator('.vp3-music-items').waitFor();
    await voicePage.waitForFunction(() => !document.getElementById('sendChatButton').disabled && !window.VP3ConversationControls.isBusy());
    await voicePage.evaluate(() => window.VP3ConversationControls.stop());
    return data;
  }
  const voiced = await voiceTurn('show my listening history');
  equal(voiced.music_history_v1.owner_user_id,1,'Browser voice preserves the actual signed-in owner');
  equal(voiced.music_history_v1.items.length,6,'Voice history renders all individual fixture receipts');
  const firstVoiceMessageCount = (await voiceFixture.state()).chat_messages.length;
  await voiceFixture.control("UPDATE tracks SET owner_user_id=2,visibility='private' WHERE id=71");
  // Respect the shipped post-speech echo window before repeating the same phrase.
  await voicePage.waitForTimeout(4200);
  const replayed = await voiceTurn('show my listening history');
  check(replayed.replayed===true,'The real voice transport reports an exact cached-request replay');
  equal(replayed.assistant_message_id,voiced.assistant_message_id,'Voice replay remains bound to the same stored assistant message');
  await voicePage.waitForFunction(id => !document.querySelector(`[data-chat-message-id="${id}"]`)?.textContent.includes('History Moonlight'),voiced.assistant_message_id);
  check(!(await voicePage.locator(`[data-chat-message-id="${voiced.assistant_message_id}"]`).innerText()).includes('History Moonlight'),
    'Voice replay removes revoked titles from the entire existing assistant message');
  equal((await voiceFixture.state()).chat_messages.length,firstVoiceMessageCount,'Voice retry does not create another saved turn');
  await capture(voicePage,'desktop-voice-replay',historyCard(voicePage,replayed));
  await voiceFixture.control("UPDATE tracks SET owner_user_id=1,visibility='public' WHERE id=71");
  const selectedVoice = await voiceTurn('show the first one');
  check(selectedVoice.music_chat_v1?.history_operation==='play','Voice exact selection uses the standard one-item Music Chat path');
  equal(selectedVoice.music_chat_v1.items.length,1,'Voice follow-up selects exactly one retained receipt');
  check(!selectedVoice.music_chat_v1.autoplay,'A Show follow-up remains quiet');
  const beforeSelectedReplay = (await voiceFixture.state()).chat_messages.length;
  await voiceFixture.control("UPDATE tracks SET owner_user_id=2,visibility='private' WHERE id=71");
  await voicePage.waitForTimeout(4200);
  const selectedReplay = await voiceTurn('show the first one');
  check(selectedReplay.replayed===true,'Exact voice selection retry uses current rehydration');
  equal(selectedReplay.assistant_message_id,selectedVoice.assistant_message_id,'Selected voice replay does not append a replacement result');
  await voicePage.waitForFunction(id => !document.querySelector(`[data-chat-message-id="${id}"]`)?.textContent.includes('History Moonlight'),selectedVoice.assistant_message_id);
  check(!(await voicePage.locator(`[data-chat-message-id="${selectedVoice.assistant_message_id}"]`).innerText()).includes('History Moonlight'),
    'Quiet MC1 voice replay refreshes both the existing card and its parent answer');
  const finalVoiceState=await voiceFixture.state();
  equal(finalVoiceState.chat_messages.length,beforeSelectedReplay,'Selected voice retry does not create another saved turn');
  equal([finalVoiceState.track_play_sessions.length,finalVoiceState.vp3_youtube_playback_v1.length,finalVoiceState.vp3_youtube_playlist_plays_v1.length],voiceCounts,
    'Voice history, Show and replay create no native or YouTube listening receipts');
  equal(await voicePage.locator('.vp3-music-chat iframe,.vp3-music-chat audio').count(),0,'Voice history replay does not open any player');
  equal(voiceWireCount,4,'All four voice requests used the actual NDJSON transport');
  await voiceContext.close();
  equal(errors, [], 'No unhandled browser errors');
  await fs.writeFile(path.join(artifacts, 'acceptance.json'), JSON.stringify({engine,checks,captures,
    application:'production history reader/API, typed/NDJSON controllers and browser voice renderer, canonical native media/receipts and visible YouTube controller',
    external:'Google search/IFrame transport, hardware speech recognition and speech output explicitly mocked; no live account or physical-device certification'}, null, 2));
  console.log(`Music History ${engine}: ${checks} checks passed; ${captures.length} screenshots. External YouTube APIs explicitly mocked.`);
} catch (error) {
  const state = [];
  for (const [index,page] of pages.entries()) {
    if (page.isClosed()) continue;
    state.push(await page.evaluate(() => ({url:location.href,history:!!window.VP3_MUSIC_HISTORY_V1,
      conversation:window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.(),cards:document.querySelectorAll('.vp3-music-history').length,
      audio:[...document.querySelectorAll('audio')].map(audio => ({track:audio.dataset.trackId,paused:audio.paused,seeking:audio.seeking,
        readyState:audio.readyState,time:audio.currentTime,duration:audio.duration}))})).catch(reason => ({error:String(reason)})));
    await Promise.allSettled([page.screenshot({path:path.join(artifacts,`failure-${index}.png`)}),
      page.content().then(html => fs.writeFile(path.join(artifacts,`failure-${index}.html`),html))]);
  }
  await Promise.allSettled([fs.writeFile(path.join(artifacts,'failure.json'),JSON.stringify({engine,checks,error:String(error.stack||error),errors,state},null,2)),
    fs.writeFile(path.join(artifacts,'php.log'),fixture.logs+'\n'+(voiceFixture?.logs||''))]);
  throw error;
} finally { await browser?.close(); voiceFixture?.close(); fixture.close(); }
