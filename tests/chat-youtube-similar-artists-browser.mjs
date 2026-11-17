import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const browser = await chromium.launch({headless:true,
  ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {})});
const page = await browser.newPage({viewport:{width:390,height:844}});
page.setDefaultTimeout(10000);
const source = await fs.readFile(new URL('../chat-youtube-v1.js',import.meta.url),'utf8');
const css = await fs.readFile(new URL('../chat-youtube-v1.css',import.meta.url),'utf8');
const ids = ['seed0000001','seed0000002','seed0000003','moon0000001','soft0000001','sable000001'];
const artists = ['Moon Harbor','Soft Current','Sable'];
const videos = ids.map((id,index) => ({video_id:id,title:'Evening lights '+(index+1),channel_title:index<3?'Paper Lanterns - Topic':artists[index-3]+' - Topic',made_for_kids:false}));
const related = artists.map((artist,index)=>({artist,video_id:ids[index+3]}));
const result = {suggestion_kind:'playlist',initiative_suggestion:true,query:'Paper Lanterns',
  videos,similar_artists:related,expires_at:Math.floor(Date.now()/1000)+600,source_message_id:101};
const requests = [];
const errors = [];
page.on('pageerror',error=>errors.push(error.message));
await page.route('http://similar.test/**',async route => {
  if (new URL(route.request().url()).pathname !== '/playback') {
    return route.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><style>*{box-sizing:border-box}body{margin:0;padding:12px}article{max-width:760px;margin:auto}</style><article data-chat-message-id="302"><div class="message-text">Artist report</div></article>'});
  }
  const request = Object.fromEntries(new URLSearchParams(route.request().postData()));
  requests.push(request);
  assert.equal(request.csrf_token,'fixture');
  if (request.action === 'record') {
    assert.equal(request.message_id,'101','Related tracks use the exact original result message');
    assert.ok(ids.includes(request.video_id));
  }
  return route.fulfill({json:{ok:true,recorded:true,resume_ms:null,playlists:[]}});
});
await page.route('https://www.youtube.com/embed/**',route=>route.fulfill({contentType:'text/html',body:'<title>Player fixture</title>'}));
await page.addInitScript(() => {
  window.VP3_YOUTUBE_PLAYBACK_V1 = {endpoint:'/playback',csrf:'fixture',captureKey:'fixture-capture',resumeCapture:false,playlistReady:true};
  window.VP3ParticipantContinuity = {selectedContext:()=>null};
  window.YT = {PlayerState:{PLAYING:1,PAUSED:2,BUFFERING:3,ENDED:0}, Player:class {
    constructor(frame, config) {
      window.ytFixture = this;
      this.frame = frame;
      this.events = config.events;
      this.state = -1;
      this.id = new URL(frame.src).pathname.split('/').at(-1);
      queueMicrotask(() => this.events.onReady({target:this}));
    }
    getPlayerState() { return this.state; }
    getCurrentTime() { return 0; }
    getPlaybackRate() { return 1; }
    getVideoUrl() { return 'https://www.youtube.com/watch?v='+this.id; }
    change(state) { this.state = state; this.events.onStateChange({target:this,data:state}); }
    pauseVideo() { this.change(2); }
    playVideo() { this.change(1); }
    seekTo() {}
    destroy() {}
  }};
});
const load = async value => {
  await page.goto('http://similar.test/');
  await page.addStyleTag({content:css});
  await page.addScriptTag({content:source});
  await page.evaluate(payload=>window.VP3_CHAT_YOUTUBE_V1.attach(document.querySelector('article'),payload),value);
};
try {
  await load(result);
  assert.equal(await page.locator('.vp3-youtube-result').count(),6,'Base and related tracks are in the same playable result list');
  assert.deepEqual(await page.locator('[data-youtube-similar-artist]').allTextContents(),artists.map(name=>'Artist suggestion · '+name));
  assert.match(await page.locator('[data-youtube-similar-artists]').textContent(),/Moon Harbor, Soft Current, Sable/);
  assert.equal(await page.locator('iframe').count(),0,'A restored report never starts a recommendation automatically');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),true,'Related artist labels fit the phone layout');
  await page.getByRole('button',{name:'Play in Chat · Evening lights 4',exact:true}).click();
  await page.waitForFunction(()=>window.ytFixture?.id==='moon0000001');
  const recorded = page.waitForResponse(response=>response.url().endsWith('/playback')
    && new URLSearchParams(response.request().postData()).get('action')==='record');
  await page.evaluate(()=>window.ytFixture.change(1));
  await recorded;
  assert.equal(requests.filter(request=>request.action==='record').at(-1).video_id,'moon0000001');
  await page.setViewportSize({width:1280,height:800});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),true,'The report also fits desktop');

  await load({...result,suggestion_kind:'similar',videos:videos.slice(3)});
  assert.equal(await page.locator('.vp3-youtube-result').count(),3,'A report without a base playlist still shows all related tracks');
  assert.equal(await page.locator('[data-youtube-similar-artist]').count(),3);
  assert.equal(await page.locator('iframe').count(),0);

  await load({...result,similar_artists:[{artist:'Ghost artist',video_id:'missing0001'}]});
  assert.equal(await page.locator('[data-youtube-similar-artists]').count(),0,'Names without an exact visible video are not advertised');
  await load({...result,similar_artists:[{artist:'<script>bad()</script>',video_id:ids[3]}]});
  assert.equal(await page.locator('[data-youtube-similar-artist] script').count(),0,'Provider labels are text, never executable markup');
  assert.match(await page.locator('[data-youtube-similar-artist]').textContent(),/<script>/);
  assert.deepEqual(errors,[]);
  console.log('YOUTUBE_SIMILAR_ARTISTS_BROWSER=PASS · named related cards, exact playback receipt, no autoplay, phone/desktop layout, standalone results and safe labels');
} finally {
  await browser.close();
}
