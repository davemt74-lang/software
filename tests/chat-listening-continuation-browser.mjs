import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';
const read=p=>fs.readFile(new URL('../'+p,import.meta.url),'utf8');
const template=await read('chat-legacy-v108.php');
const drawer=template.slice(template.indexOf('<div class="chat-player-overlay" id="chatPlayerDrawer"'),template.indexOf('<div class="chat-player-overlay" id="chatQueueDrawer"'));
const html=`<!doctype html><html><head></head><body><div class="chat-app"><aside class="chat-sidebar"><button id="newChatButton">New chat</button><div id="chatHistory"></div></aside><main class="chat-main"><header>Agent Chat</header><section class="chat-thread" id="chatThread"><section data-chat-view="player" hidden><article id="playerSong"><audio class="chat-audio-player" preload="none" data-track-id="9" data-player-title="Last song" data-player-album="Studio" src="/song.wav"></audio></article></section><div id="chatWelcome" hidden></div></section><div class="chat-composer-shell"><form id="chatForm"><textarea id="chatInput"></textarea><button id="sendChatButton">Send</button></form></div></main></div>${drawer}</body></html>`;
const pcm=Buffer.alloc(8000*2*60),wav=Buffer.alloc(44+pcm.length);wav.write('RIFF');wav.writeUInt32LE(36+pcm.length,4);wav.write('WAVEfmt ',8);wav.writeUInt32LE(16,16);wav.writeUInt16LE(1,20);wav.writeUInt16LE(1,22);wav.writeUInt32LE(8000,24);wav.writeUInt32LE(16000,28);wav.writeUInt16LE(2,32);wav.writeUInt16LE(16,34);wav.write('data',36);wav.writeUInt32LE(pcm.length,40);pcm.copy(wav,44);
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
const calls=[];let recent=[],failPlaylist=false,failHistory=false;const media={id:9,title:'Last song <img>',album:'Studio',audio:'/song.wav',cover:'/cover.svg',detail:'/track.php?id=9'};
await page.route('http://listening.test/chat.php**',r=>r.fulfill({contentType:'text/html',body:html}));
await page.route('**/song.wav',r=>{
 const range=r.request().headers().range?.match(/^bytes=(\d+)-(\d*)$/);if(!range)return r.fulfill({contentType:'audio/wav',headers:{'Accept-Ranges':'bytes'},body:wav});
 const start=Number(range[1]),end=range[2]?Math.min(Number(range[2]),wav.length-1):wav.length-1;
 return r.fulfill({status:206,contentType:'audio/wav',headers:{'Accept-Ranges':'bytes','Content-Range':`bytes ${start}-${end}/${wav.length}`},body:wav.subarray(start,end+1)});
});await page.route('**/cover.svg',r=>r.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'}));
await page.route('**/api/**',async r=>{
 const body=r.request().postDataJSON()||{};calls.push({...body,path:new URL(r.request().url()).pathname});
 if(body.action==='recent_history')return r.fulfill(failHistory?{status:503,json:{ok:false,error:'Unavailable'}}:{json:{ok:true,user_id:11,tracks:recent}});
 if(body.action==='playlist_add_track')return r.fulfill(failPlaylist?{status:403,json:{ok:false,error:'Playlist access changed'}}:{json:{ok:true,playlist_id:5,track_id:9}});
 if(new URL(r.request().url()).pathname==='/api/playback.php'){
  if(body.action==='start')recent=[{...media,last_played_at:new Date().toISOString(),last_position_seconds:Number(body.position)||0}];
  else if(recent.length)recent[0].last_position_seconds=Number(body.position)||0;
  return r.fulfill({json:{ok:true,session_token:'session',session_id:7}});
 }
 if(body.action==='load')return r.fulfill({json:{ok:true,messages:[{id:1,role:'assistant',message:'Earlier conversation'}]}});
 if(body.action==='send')return r.fulfill({json:{ok:true,conversation_id:42,answer:'Conversation continues.',assistant_message_id:2}});
 return r.fulfill({json:{ok:true,conversations:[],updates:[],items:[]}});
});
async function boot(view='chat'){
 await page.goto('http://listening.test/chat.php'+(view==='player'?'?view=player':''));
 await page.evaluate(view=>{window.STONEFELLOW_CHAT={userId:11,endpoint:'/api/chat.php',playbackEndpoint:'/api/playback.php',libraryEndpoint:'/api/player-library-v76.php',csrf:'fixture',initialView:view,initialConversationId:42};window.STONEFELLOW_PLAYER={tracks:{},albums:{},playlists:{5:{id:5,title:'My playlist',owned:true,track_ids:[]},6:{id:6,title:'Other playlist',owned:false,track_ids:[]}}};},view);
 await page.addStyleTag({content:await read('chat.css')});await page.addScriptTag({content:await read('chat.js')});
}
try{
 await boot('player');await page.locator('#playerSong [data-player-toggle]').click();await page.waitForFunction(()=>{const a=document.querySelector('#playerSong audio');return !a.paused&&a.readyState>=3&&a.currentTime>0;});await page.waitForFunction(()=>window.STONEFELLOW_CHAT_CONTINUITY);
 await page.locator('#playerSong audio').evaluate(a=>{a.currentTime=24;});
 await page.waitForFunction(()=>{const a=document.querySelector('#playerSong audio');return !a.seeking&&a.currentTime>=24;});
 await page.locator('#playerSong audio').evaluate(a=>a.pause());
 for(let i=0;i<50&&!(recent[0]?.last_position_seconds>=24);i++)await page.waitForTimeout(100);
 assert.ok(recent[0]?.last_position_seconds>=24,'Player did not record the actual saved position: '+JSON.stringify(calls.filter(x=>x.path==='/api/playback.php')));
 await boot();const card=page.locator('#chatListeningContinuation');await card.getByRole('button',{name:'Resume play',exact:true}).waitFor();
 assert.equal(await card.isVisible(),true);assert.match(await card.innerText(),/Last song <img>/);assert.equal(await card.locator('strong img').count(),0);assert.equal(await card.locator('audio').evaluate(a=>a.paused),true,'Loading Chat auto-played the last song');assert.ok(Number(await card.locator('[data-resume-track]').getAttribute('data-resume-position'))>=24);
 assert.equal(await page.locator('#playerRecent').count(),0,'Fixture accidentally relies on the hidden history shelf');assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Mobile card overflowed');
 const starts=calls.filter(x=>x.path==='/api/playback.php'&&x.action==='start').length;
 await card.getByRole('button',{name:'Resume play',exact:true}).click();await page.waitForFunction(()=>{const a=document.querySelector('#chatListeningContinuation audio');return !a.paused&&a.currentTime>=24;});assert.equal(await page.locator('#playerSong audio').evaluate(a=>a.paused),true,'Resume started the hidden Player audio instead of the Chat card');
 await page.evaluate(()=>{window.keptListeningAudio=document.querySelector('#chatListeningContinuation audio');window.dispatchEvent(new Event('vp3:playback-history-changed'));});await page.waitForTimeout(500);assert.equal(await page.evaluate(()=>keptListeningAudio===document.querySelector('#chatListeningContinuation audio')),true,'Refresh replaced actively playing audio');
 await card.locator('audio').evaluate(a=>a.pause());assert.equal(calls.filter(x=>x.path==='/api/playback.php'&&x.action==='start').length,starts+1,'Resume created duplicate listening sessions');
 await card.getByRole('button',{name:'Add to playlist',exact:true}).click();await page.locator('#chatPlayerDrawer').waitFor({state:'visible'});assert.equal(await page.getByRole('button',{name:/Other playlist/}).count(),0);await page.getByRole('button',{name:/My playlist/}).click();await page.getByRole('button',{name:'Added ✓',exact:true}).waitFor();assert.equal(calls.filter(x=>x.action==='playlist_add_track').at(-1).track_id,9);
 await page.locator('#chatPlayerDrawer [data-close-player-drawer]').last().click();
 await page.locator('#chatInput').fill('Continue our conversation');await page.locator('#chatForm').evaluate(f=>f.requestSubmit());await page.waitForFunction(()=>!document.querySelector('#chatInput').disabled);assert.equal(await card.isVisible(),true);assert.equal(await page.evaluate(()=>document.querySelector('#chatThread').lastElementChild.id),'chatListeningContinuation','Conversation message displaced the listening card');
 failPlaylist=true;await card.getByRole('button',{name:'Add to playlist',exact:true}).click();const dialogPromise=page.waitForEvent('dialog');const clicking=page.locator('[data-picker-playlist="5"]').click();const dialog=await dialogPromise;assert.match(dialog.message(),/access changed/);await dialog.dismiss();await clicking;assert.equal(await page.locator('[data-picker-playlist="5"]').isDisabled(),false,'Failed playlist add stayed disabled');
 recent=[];await page.evaluate(()=>window.dispatchEvent(new Event('vp3:playback-history-changed')));await page.waitForTimeout(500);assert.equal(await card.isVisible(),false,'Unavailable or revoked history left a stale song card');
 failHistory=true;await boot();await page.waitForTimeout(500);assert.equal(await page.locator('#chatListeningContinuation audio').count(),0,'Failed history retrieval fabricated a song card');assert.match(await page.locator('#chatListeningContinuation').innerText(),/could not be loaded/);
 failHistory=false;recent=[{...media,last_played_at:new Date().toISOString(),last_position_seconds:24}];await page.getByRole('button',{name:'Retry',exact:true}).click();await page.locator('#chatListeningContinuation [data-resume-track]').waitFor();assert.equal(await page.locator('#chatListeningContinuation audio').evaluate(a=>a.paused),true);
 assert.deepEqual(errors,[]);console.log('CHAT_LISTENING_CONTINUATION_BROWSER=PASS Player to fresh Chat, actual saved resume, no autoplay, exact audio target, stable active playback, owner playlist controls and failures, conversation continuity, access removal, escaping and mobile');
}finally{await browser.close();}
