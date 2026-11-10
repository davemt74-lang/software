import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const browser = await chromium.launch({headless:true,
  ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {})});
const page = await browser.newPage({viewport:{width:1280,height:800}});
page.setDefaultTimeout(10000);
const source = await fs.readFile(new URL('../chat-youtube-v1.js',import.meta.url),'utf8');
const ids = ['abcdefghijk','zyxwvutsrqp'];
const playlistId = '1'.repeat(32);
const requests = [];
const positions = new Map();
let saved = false;
const expiry = Math.floor(Date.now()/1000)+1200;
const videos = ids.map((id,index) => ({video_id:id,title:'Workshop '+(index+1),made_for_kids:false}));
const result = {suggestion_kind:'playlist',suggestion_reason:'Verified non-music workshop sequence.',
  query:'Workshop videos',videos,expires_at:expiry,source_message_id:101};
const errors = [];
page.on('pageerror',error=>errors.push(error.message));
await page.route('http://playback.test/**',async route => {
  if (new URL(route.request().url()).pathname !== '/playback') {
    return route.fulfill({contentType:'text/html',body:'<!doctype html><article data-chat-message-id="302"><div class="message-text">Chat</div></article>'});
  }
  const request = Object.fromEntries(new URLSearchParams(route.request().postData()));
  requests.push(request);
  assert.equal(request.csrf_token,'fixture');
  switch (request.action) {
    case 'position':
      return route.fulfill({json:{ok:true,resume_ms:positions.get(request.video_id) ?? null}});
    case 'position_save':
      positions.set(request.video_id,Number(request.position_ms));
      return route.fulfill({json:{ok:true,recorded:true}});
    case 'record':
      return route.fulfill({json:{ok:true,recorded:true}});
    case 'playlist_save':
      assert.equal(request.message_id,'101','A recalled playlist uses its exact original result when saved');
      saved = true;
      return route.fulfill({json:{ok:true,playlist_id:playlistId}});
    case 'playlists':
      return route.fulfill({json:{ok:true,playlists:saved ? [{playlist_id:playlistId,title:'Saved workshop',available:true}] : []}});
    case 'playlist_open':
      assert.equal(request.playlist_id,playlistId);
      return route.fulfill({json:{ok:true,results:{query:'Saved workshop',playlist_id:playlistId,suggestion_kind:'playlist',videos,expires_at:expiry}}});
    case 'playlist_remove':
      assert.equal(request.playlist_id,playlistId);
      saved = false;
      return route.fulfill({json:{ok:true}});
    default:
      throw new Error('Unexpected playback action '+request.action);
  }
});
await page.route('https://www.youtube.com/embed/**',route=>route.fulfill({contentType:'text/html',body:'<title>YouTube fixture</title>'}));
await page.addInitScript(() => {
  let playCounter = 0;
  Object.defineProperty(window.crypto,'randomUUID',{value:()=> '11111111-1111-4111-8111-'+String(++playCounter).padStart(12,'0')});
  window.VP3_YOUTUBE_PLAYBACK_V1 = {endpoint:'/playback',csrf:'fixture',captureKey:'fixture-capture',resumeCapture:true,playlistReady:true};
  window.VP3ParticipantContinuity = {selectedContext:()=>window.participant ? {participant_id:2} : null};
  window.setHidden = hidden => {
    Object.defineProperty(document,'hidden',{configurable:true,get:()=>hidden});
    document.dispatchEvent(new Event('visibilitychange'));
  };
  window.YT = {PlayerState:{PLAYING:1,PAUSED:2,BUFFERING:3,ENDED:0}, Player:class {
    constructor(frame, config) {
      window.ytFixture = this;
      this.frame = frame;
      this.events = config.events;
      this.state = -1;
      const url = new URL(frame.src);
      this.id = url.pathname.split('/').at(-1);
      this.position = Number(url.searchParams.get('start') || 0);
      queueMicrotask(() => this.events.onReady({target:this}));
    }
    getPlayerState() { return this.state; }
    getCurrentTime() { return this.position; }
    getPlaybackRate() { return 1; }
    getVideoUrl() { return 'https://www.youtube.com/watch?v='+this.id; }
    change(state) { this.state = state; this.events.onStateChange({target:this,data:state}); }
    pauseVideo() { this.change(2); }
    playVideo() { this.change(1); }
    seekTo(position) { this.position = position; }
    destroy() { this.destroyed = true; }
  }};
});
const load = async (pathname='/') => {
  await page.goto('http://playback.test'+pathname);
  await page.addScriptTag({content:source});
};
const attach = payload => page.evaluate(value=>window.VP3_CHAT_YOUTUBE_V1.attach(document.querySelector('article'),value),payload);
const waitForPlayer = id => page.waitForFunction(value=>window.ytFixture?.id===value
  && window.ytFixture.frame===document.querySelector('.vp3-youtube-frame'),id);
const waitForAction = (action,check=()=>true) => page.waitForResponse(response=>{
  if (!response.url().endsWith('/playback')) return false;
  const fields = new URLSearchParams(response.request().postData());
  return fields.get('action') === action && check(fields);
});

try {
  await load();
  await attach({...result,selected_video_id:ids[0],play_selected:false});
  assert.equal(await page.locator('.vp3-youtube-selected').count(),1,'Restored selected result is highlighted');
  assert.equal(await page.locator('iframe').count(),0,'Restoring a Chat never starts playback');
  const draft = page.getByRole('region',{name:'YouTube playlist draft'});
  await draft.getByRole('button',{name:'Play playlist',exact:true}).click();
  await waitForPlayer(ids[0]);
  const firstReceipt = waitForAction('record');
  await page.evaluate(()=>window.ytFixture.change(1));
  await firstReceipt;

  const persisted = waitForAction('position_save',fields=>fields.get('position_ms')==='27452');
  await page.evaluate(() => {
    window.firstFrame = window.ytFixture.frame;
    window.ytFixture.position = 27.452;
    window.setHidden(true);
  });
  await persisted;
  assert.equal(await page.evaluate(()=>window.ytFixture.state),2,'A hidden tab pauses YouTube');
  assert.equal(await page.evaluate(()=>window.ytFixture.frame===window.firstFrame && window.firstFrame.isConnected),true,'Tab switch preserves the same iframe');
  await page.evaluate(()=>window.setHidden(false));
  assert.equal(await page.evaluate(()=>window.ytFixture.state),1,'Returning to the tab resumes prior playback');
  await page.evaluate(()=>{window.ytFixture.pauseVideo();window.setHidden(true);window.setHidden(false);});
  assert.equal(await page.evaluate(()=>window.ytFixture.state),2,'A user-paused video does not start on tab return');
  assert.equal(positions.get(ids[0]),27452,'Position is saved without the research watch-time opt-in');
  assert.equal(requests.filter(request=>request.action==='watch').length,0);

  await load('/');
  await attach(result);
  assert.equal(await page.locator('iframe').count(),0);
  await draft.getByRole('button',{name:'Play in Chat · Workshop 1',exact:true}).click();
  await waitForPlayer(ids[0]);
  await page.waitForFunction(()=>window.ytFixture.position===27.452);
  assert.equal(new URL(await page.locator('iframe').getAttribute('src')).searchParams.get('start'),'27');
  await page.evaluate(()=>{window.ytFixture.change(1);window.ytFixture.change(0);});
  await waitForPlayer(ids[1]);
  assert.equal(await draft.getByRole('button',{name:'Next video'}).isDisabled(),true);
  await draft.getByRole('button',{name:'Previous video'}).click();
  await waitForPlayer(ids[0]);
  assert.equal(await page.evaluate(()=>window.VP3_CHAT_YOUTUBE_V1.command('Play the next YouTube video')),'next');
  await page.evaluate(()=>window.VP3_CHAT_YOUTUBE_V1.runCommand('next'));
  await waitForPlayer(ids[1]);
  assert.equal(await draft.getByRole('button',{name:'Save this music taste'}).count(),0);
  await draft.getByRole('button',{name:'Save playlist'}).click();
  await draft.getByRole('button',{name:'Playlist saved'}).waitFor();

  await load('/another-chat');
  assert.equal(await page.evaluate(()=>window.VP3_CHAT_YOUTUBE_V1.command('Show my saved YouTube playlists')),'playlists');
  const response = await page.evaluate(()=>window.VP3_CHAT_YOUTUBE_V1.runCommand('playlists'));
  await attach(response.results);
  await page.getByRole('button',{name:'Saved workshop'}).click();
  const restored = page.getByRole('region',{name:'Saved YouTube playlist',exact:true});
  await restored.getByRole('button',{name:'Play playlist',exact:true}).click();
  await waitForPlayer(ids[0]);
  const savedReceipt = waitForAction('record',fields=>fields.get('playlist_id')===playlistId);
  await page.evaluate(()=>window.ytFixture.change(1));
  const fields = new URLSearchParams((await savedReceipt).request().postData());
  assert.equal(fields.has('message_id'),false,'Saved playback has stable provenance independent of Chat IDs');
  await page.getByRole('button',{name:'Remove playlist'}).click();
  await page.getByText('No saved YouTube playlists yet.').waitFor();
  assert.equal(await page.locator('iframe').count(),0,'Removing a playing playlist unloads its iframe');

  await load('/fresh-action');
  await attach({...result,selected_video_id:ids[1],play_selected:true});
  await waitForPlayer(ids[1]);
  assert.equal(await page.locator('.vp3-youtube-selected').innerText().then(text=>text.includes('Workshop 2')),true);
  assert.deepEqual(errors,[]);
  console.log('PASS YouTube playback persists through tab visibility and reload, queues exact selected items, and saves across Chats');
} finally {
  await browser.close();
}
