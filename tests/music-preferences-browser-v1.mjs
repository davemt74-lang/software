import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {randomUUID} from 'node:crypto';
import {chromium, webkit} from 'playwright';
import {startMusicPreferencesFixture} from './music-preferences-fixture-v1.mjs';

// Production preference/history/playlist handlers, SQL and player code. Google
// responses and the YouTube iframe transport are the only provider doubles.
const engine=process.env.MUSIC_PREFERENCES_BROWSER||'chromium';
assert.ok(['chromium','webkit'].includes(engine));
const artifacts=path.resolve(process.env.MUSIC_PREFERENCES_ARTIFACT_DIR||`test-results/music-preferences-${engine}`);
await fs.mkdir(artifacts,{recursive:true});
const fixture=await startMusicPreferencesFixture();
const pages=[],errors=[],captures=[];
let browser,checks=0;
const check=(value,message)=>{assert.ok(value,message);checks++;};
const equal=(actual,expected,message)=>{assert.deepEqual(actual,expected,message);checks++;};
const count=table=>Number(fixture.readDb(`SELECT COUNT(*) AS total FROM ${table}`)[0].total);
const writes=()=>fixture.requests().filter(row=>row.path==='/api/music-preferences-v1.php'&&['set','skip'].includes(row.action)).length;
const preferenceResponse=(page,action)=>page.waitForResponse(response=>response.url().endsWith('/api/music-preferences-v1.php')&&response.request().postDataJSON()?.action===action);
const historyCard=page=>page.locator(`[data-chat-message-id="${fixture.preferences.page.source_message_id}"] .vp3-music-history`);
const historyRow=(page,name)=>historyCard(page).locator(`[data-history-entry-ref="${fixture.seeded.refs[name]}"]`);
const resultCard=page=>page.locator(`[data-music-result-id="${fixture.preferences.result.result_id}"]`);
const choice=(row,value)=>row.locator(`[data-music-preference="${value}"]`);

function iframeMock(){
  const players=[];
  const states={UNSTARTED:-1,ENDED:0,PLAYING:1,PAUSED:2,BUFFERING:3,CUED:5};
  window.__preferencesYouTube={players,acknowledged:0,active:()=>players.findLast(player=>!player.destroyed)};
  window.addEventListener('vp3:youtube-history-changed',()=>window.__preferencesYouTube.acknowledged++);
  window.YT={PlayerState:states,Player:class{
    constructor(frame,options){this.frame=frame;this.events=options.events;this.position=0;this.started=performance.now();this.state=1;this.destroyed=false;players.push(this);
      queueMicrotask(()=>{if(!this.destroyed){this.events.onReady({target:this});this.events.onStateChange({target:this,data:this.state});}});}
    getPlayerState(){return this.state;}
    getCurrentTime(){return this.position+(this.state===1?(performance.now()-this.started)/1000:0);}
    getDuration(){return 60;}
    getPlaybackRate(){return 1;}
    getVideoUrl(){return 'https://www.youtube.com/watch?v='+new URL(this.frame.src).pathname.split('/').at(-1);}
    getVolume(){return 100;}
    isMuted(){return false;}
    seekTo(position){this.position=position;this.started=performance.now();}
    playVideo(){this.position=this.getCurrentTime();this.started=performance.now();this.state=1;this.events.onStateChange({target:this,data:1});}
    pauseVideo(){this.position=this.getCurrentTime();this.state=2;this.events.onStateChange({target:this,data:2});}
    end(){this.position=60;this.state=0;this.events.onStateChange({target:this,data:0});}
    fail(){this.events.onError({target:this,data:100});}
    destroy(){this.position=this.getCurrentTime();this.state=2;this.destroyed=true;}
  }};
}
async function pageFor(viewport={width:1280,height:900}){
  const context=await browser.newContext({viewport,hasTouch:viewport.width<600,extraHTTPHeaders:{'X-User':'11'}});
  await context.addInitScript(iframeMock);
  const page=await context.newPage();pages.push(page);
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('https://www.youtube.com/**',route=>route.fulfill({contentType:'text/html',body:
    '<!doctype html><html><body style="margin:0;background:#161616;color:white;font:16px Arial;display:grid;place-items:center;height:100vh">Visible YouTube transport fixture</body></html>'}));
  await page.route('https://i.ytimg.com/**',route=>route.fulfill({contentType:'image/svg+xml',body:
    '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="120" height="120" fill="#26384d"/></svg>'}));
  await page.goto(fixture.baseURL+'/chat.php?conversation_id=43');
  await page.waitForFunction(()=>window.VP3_MUSIC_PREFERENCES_V1&&window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()===43);
  await historyCard(page).locator('.vp3-music-history-items').waitFor();
  await resultCard(page).locator('.vp3-music-items').waitFor();
  await choice(historyRow(page,'native'),'like').waitFor({state:'visible'});
  await page.waitForFunction(()=>[...document.querySelectorAll('.vp3-music-preferences')].every(box=>box.getAttribute('aria-busy')==='false'));
  return {page,context};
}
async function setChoice(page,row,value){
  const pending=preferenceResponse(page,'set');
  await choice(row,value).click();
  const response=await pending,data=await response.json();
  equal(response.status(),200,`Explicit ${value} uses the production preference handler: ${JSON.stringify(data.error||'ok')}`);
  equal(data.state.preference,value==='clear'?'neutral':value,'The response acknowledges the requested current choice');
  if(value==='clear')await choice(row,'clear').waitFor({state:'visible'});
  else await page.waitForFunction(({selector,value})=>document.querySelector(selector)?.getAttribute('aria-pressed')==='true',
    {selector:`[data-history-entry-ref="${await row.getAttribute('data-history-entry-ref')}"] [data-music-preference="${value}"]`,value});
  return data;
}
async function read(selection){const response=await fixture.preferenceRequest('read',{selection});const data=await fixture.json(response);equal(response.status,200,'A current private preference is readable');return data.state;}
async function directSet(selection,preference){
  const state=await read(selection);
  const response=await fixture.preferenceRequest('set',{selection,preference,expected_revision:state.revision,request_id:randomUUID()});
  const data=await fixture.json(response);equal(response.status,200,'The concurrent fixture uses the actual compare-and-set handler');return data;
}
async function capture(page,label,container,anchor){
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': no horizontal page overflow');
  equal(await container.evaluate(element=>[...element.querySelectorAll('*')].flatMap(child=>{
    if(!child.getClientRects().length)return[];
    const style=getComputedStyle(child),box=child.getBoundingClientRect(),problems=[];
    if(['auto','scroll'].includes(style.overflowY)&&child.scrollHeight>child.clientHeight+2)problems.push('nested vertical scroll');
    if(child.matches('button,input,select,summary')&&box.height<43.5)problems.push('small touch target');
    return problems;
  })),[],label+': readable ordinary scrolling and touch controls');
  await anchor.evaluate(element=>element.scrollIntoView({block:'center',behavior:'instant'}));
  await page.waitForFunction(async element=>{
    const before=element.getBoundingClientRect();await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
    const box=element.getBoundingClientRect(),composer=document.querySelector('#chatComposerShell')?.getBoundingClientRect();
    const hit=document.elementFromPoint(box.left+box.width/2,box.top+box.height/2);
    return element.isConnected&&Math.abs(before.top-box.top)<.5&&box.left>=0&&box.right<=innerWidth&&box.top>=0
      &&box.bottom<=Math.min(innerHeight,composer?.top||innerHeight)&&(hit===element||element.contains(hit));
  },await anchor.elementHandle());
  await anchor.click({trial:true});
  await page.screenshot({path:path.join(artifacts,label+'.png')});captures.push(label+'.png');
}
async function openSaved(page){
  const pending=preferenceResponse(page,'list');
  await resultCard(page).getByRole('button',{name:'Music preferences',exact:true}).click();
  const response=await pending;equal(response.status(),200,'Saved choices use the actual private list handler');
  const panel=resultCard(page).locator('.vp3-music-preferences-panel');
  await panel.locator('.vp3-music-preferences-pages').waitFor();return panel;
}
async function attachMusic(page,seed){
  await page.evaluate(async seed=>{
    const message=document.createElement('article');message.className='message assistant';
    message.dataset.chatMessageId=String(seed.assistant_message_id);message.dataset.chatConversationId='43';message.dataset.chatMessageRole='assistant';
    const avatar=document.createElement('div');avatar.className='message-avatar';avatar.textContent='AI';
    const body=document.createElement('div');body.className='message-body';message.append(avatar,body);
    const thread=document.querySelector('#chatThread');thread.insertBefore(message,thread.querySelector('.chat-listening-continuation'));
    await window.VP3_MUSIC_CHAT_V1.attach(message,seed.music_chat_v1,{live:true});
  },seed);
  return page.locator(`[data-chat-message-id="${seed.assistant_message_id}"] .vp3-music-chat`).last();
}
async function nativeReady(page,card){
  await card.locator('[data-music-state="playing"],[data-music-state="blocked"],[data-music-state="unavailable"]').waitFor();
  if(await card.locator('[data-music-state="blocked"]').count()){
    const toggle=page.locator('#chatNowPlayingToggle');if(await toggle.isVisible())await toggle.click();else await page.locator('[data-listening-toggle]').click();
  }
  await card.locator('[data-music-state="playing"]').waitFor();
  await page.waitForFunction(()=>[...document.querySelectorAll('audio.chat-audio-player')].some(audio=>!audio.paused&&!audio.seeking
    &&audio.readyState>=HTMLMediaElement.HAVE_CURRENT_DATA&&window.VP3MusicListeningV1.attach(audio)?.receipt?.()));
}
async function waitUntil(fn,message){
  for(let i=0;i<80;i++){if(fn()){checks++;return;}await new Promise(resolve=>setTimeout(resolve,75));}
  assert.fail(message+'\n'+fixture.logs);
}

try{
  const executablePath=engine==='chromium'?process.env.MUSIC_PREFERENCES_CHROMIUM_EXECUTABLE:'';
  browser=await ({chromium,webkit}[engine]).launch({headless:true,...(executablePath?{executablePath}:{})});
  const quietPlays=[count('track_play_sessions'),count('vp3_youtube_playback_v1'),count('vp3_youtube_playlist_plays_v1')];
  const quietWrites=writes();
  const {page}=await pageFor();
  equal(writes(),quietWrites,'Restoring history and song cards writes no preference or skip');
  equal([count('track_play_sessions'),count('vp3_youtube_playback_v1'),count('vp3_youtube_playlist_plays_v1')],quietPlays,'Preferences do not start playback');
  check(await page.locator('[data-music-preferences-open]').count()===1,'Player exposes saved music preferences');
  check(await choice(historyRow(page,'native'),'clear').isDisabled(),'Neutral state has no preference to clear');

  const liked=await setChoice(page,historyRow(page,'native'),'like');
  equal((await read(fixture.preferences.selections.native)).preference,'like','A history choice is durably saved');
  await page.waitForFunction(ref=>document.querySelector(`[data-history-entry-ref="${ref}"] [data-music-preference="like"]`)?.getAttribute('aria-pressed')==='true',fixture.seeded.refs.repeat);
  check(await choice(historyRow(page,'repeat'),'like').getAttribute('aria-pressed')==='true','Repeated listens show one current song preference');
  await page.waitForFunction(id=>document.querySelector(`[data-music-result-id="${id}"] .vp3-music-item [data-music-preference="like"]`)?.getAttribute('aria-pressed')==='true',fixture.preferences.result.result_id);
  check(await choice(resultCard(page).locator('.vp3-music-item').first(),'like').getAttribute('aria-pressed')==='true','History and MC1 selections share the same current preference');
  await capture(page,'desktop-history-like',historyRow(page,'native'),choice(historyRow(page,'native'),'like'));

  const mutationBodies=[];let lose=true;
  const lossRoute=async route=>{
    const input=route.request().postDataJSON();
    if(input.action==='set'&&input.preference==='dislike'&&input.selection.entry_ref===fixture.seeded.refs.native){
      mutationBodies.push(route.request().postData());
      if(lose){lose=false;const response=await route.fetch();equal(response.status(),200,'Lost response fixture commits through the real handler');await route.abort('failed');return;}
    }
    await route.continue();
  };
  await page.route('**/api/music-preferences-v1.php',lossRoute);
  await choice(historyRow(page,'native'),'dislike').click();
  const retry=historyRow(page,'native').getByRole('button',{name:'Retry preference',exact:true});await retry.waitFor();
  equal((await read(fixture.preferences.selections.native)).preference,'dislike','A lost response can still have committed its exact choice');
  check(await choice(historyRow(page,'native'),'like').isDisabled(),'An uncertain mutation cannot send a different new choice');
  await directSet(fixture.preferences.selections.native,'clear');
  const retried=preferenceResponse(page,'set');await retry.click();const retryResponse=await retried,data=await retryResponse.json();
  equal(retryResponse.status(),200,'The frozen retry is accepted');equal(data.replayed,true,'The retry is identified as a replay');
  equal(data.state.preference,'neutral','An old retry reports the current cleared state');
  equal(mutationBodies.length,2,'Only the original attempt and explicit retry were sent');
  equal(mutationBodies[0],mutationBodies[1],'Lost-response retry retains exact body and request identity');
  await page.unroute('**/api/music-preferences-v1.php',lossRoute);

  await directSet(fixture.preferences.selections.native,'like');
  const conflict=preferenceResponse(page,'set');await choice(historyRow(page,'native'),'dislike').click();
  equal((await conflict).status(),409,'A stale visible choice cannot overwrite a newer server choice');
  await page.waitForFunction(ref=>document.querySelector(`[data-history-entry-ref="${ref}"] [data-music-preference="like"]`)?.getAttribute('aria-pressed')==='true',fixture.seeded.refs.native);
  equal((await read(fixture.preferences.selections.native)).preference,'like','CAS rejection preserves the newer choice');
  await setChoice(page,historyRow(page,'ordinary'),'dislike');
  let panel=await openSaved(page);
  equal(await panel.locator('.vp3-music-preferences-saved-item').count(),2,'Saved choices contain exact native and YouTube preferences');
  check(await panel.textContent().then(value=>value.includes('History Moonlight')&&value.includes('YouTube Song 6')),'Current authorized saved metadata is visible');
  await capture(page,'desktop-saved-choices',panel,choice(panel.locator('.vp3-music-preferences-saved-item').first(),'like'));
  const sourceList=preferenceResponse(page,'list');await panel.getByLabel('Preference source').selectOption('vp3');await sourceList;
  await page.waitForFunction(()=>document.querySelector('.vp3-music-preferences-panel .vp3-music-preferences-list')?.children.length===1);
  equal(await panel.locator('.vp3-music-preferences-saved-item').count(),1,'Source filtering returns one current native choice');

  const listsBeforeRestore=writes();
  const feedbackReference=liked.state.reference;
  await directSet(fixture.preferences.selections.native,'clear');
  const writesAfterClear=writes();
  await page.evaluate(async reference=>{
    const message=document.createElement('article');message.className='message assistant';message.dataset.preferenceReplay='1';
    const avatar=document.createElement('div');avatar.className='message-avatar';avatar.textContent='AI';
    const body=document.createElement('div');body.className='message-body';message.append(avatar,body);document.querySelector('#chatThread').append(message);
    await window.VP3_MUSIC_PREFERENCES_V1.attach(message,{protocol:'vp3_music_preferences_v1',owner_user_id:11,agent_id:7,conversation_id:43,
      action:'feedback',reference},{live:false});
  },feedbackReference);
  const feedback=page.locator('[data-preference-replay="1"]');
  await feedback.locator('.vp3-music-preferences').waitFor();
  equal(await choice(feedback,'like').getAttribute('aria-pressed'),'false','Restored old Like feedback displays its current cleared state');
  equal(writes(),writesAfterClear,'Restoring a feedback card makes no new writes');
  check(writesAfterClear===listsBeforeRestore+1,'The only write was the deliberate concurrent Clear');
  await setChoice(page,historyRow(page,'native'),'like');

  await fixture.control({consent:false});
  panel=await openSaved(page);
  const unavailable=panel.locator('.vp3-music-preferences-saved-item').filter({hasText:'Unavailable song'});
  await unavailable.waitFor();
  check(!(await panel.textContent()).includes('YouTube Song 6'),'Revoked YouTube metadata is absent from the saved list');
  equal(await unavailable.locator('[data-music-preference]').count(),1,'Unavailable choices expose only Clear');
  await capture(page,'desktop-unavailable-choice',panel,choice(unavailable,'clear'));
  const clearUnavailable=preferenceResponse(page,'set');await choice(unavailable,'clear').click();
  equal((await clearUnavailable).status(),200,'An unavailable saved choice can still be cleared');
  await page.waitForFunction(()=>![...document.querySelectorAll('.vp3-music-preferences-panel .vp3-music-preferences-saved-item')].some(row=>row.textContent.includes('Unavailable song')));
  await fixture.control({consent:true});
  // Renewed policy consent does not silently re-enable playback capture after
  // revocation cleanup. This fixture explicitly opts in through its real helper.
  fixture.run("vp3_youtube_history_change_v1(db(),11,'enable_history');");
  await page.reload();
  await page.waitForFunction(()=>window.VP3_MUSIC_PREFERENCES_V1&&window.STONEFELLOW_CHAT_CONTINUITY?.conversationId?.()===43);
  await historyCard(page).locator('.vp3-music-history-items').waitFor();
  const renewedCapture=fixture.readDb('SELECT capture_key FROM vp3_youtube_history_settings_v1 WHERE owner_user_id=11')[0]?.capture_key;
  check(typeof renewedCapture==='string'&&/^[a-f0-9]{32}$/.test(renewedCapture),'Explicit history opt-in creates a current capture key');
  equal(await page.evaluate(()=>window.VP3_YOUTUBE_PLAYBACK_V1.captureKey),renewedCapture,'Reloaded Chat uses the current explicitly enabled capture key');

  for(const width of [320,390]){
    const mobile=await pageFor({width,height:844});
    const mobileRow=historyRow(mobile.page,'native');
    check(await choice(mobileRow,'like').getAttribute('aria-pressed')==='true','Saved choice restores on '+width+'px without a mutation');
    await capture(mobile.page,'phone-'+width+'-history',mobileRow,choice(mobileRow,'like'));
    const mobilePanel=await openSaved(mobile.page);
    await capture(mobile.page,'phone-'+width+'-preferences',mobilePanel,choice(mobilePanel.locator('.vp3-music-preferences-saved-item').first(),'like'));
    await mobile.context.close();
  }

  // The real native and YouTube recorders must acknowledge a play before its
  // deliberate Next can contribute a skip. A skip does not become Dislike.
  const mixed=await fixture.seed({conversation_id:43,playlist:true,title:'Deliberate skip check',items:[
    {source:'vp3',source_id:'45'},{source:'youtube',source_id:'vp300000003'},{source:'vp3',source_id:'42'}]});
  const mixedCard=await attachMusic(page,mixed);
  const beforeSkips=count('music_deliberate_skips_v1');
  await mixedCard.getByRole('button',{name:'Play playlist',exact:true}).click();await nativeReady(page,mixedCard);
  equal(count('music_deliberate_skips_v1'),beforeSkips,'An acknowledged start is not a skip');
  const acknowledgedBefore=await page.evaluate(()=>window.__preferencesYouTube.acknowledged);
  const ytRecorded=page.waitForResponse(response=>response.url().endsWith('/api/chat-youtube-playback-v1.php')
    &&new URLSearchParams(response.request().postData()).get('action')==='record'
    &&new URLSearchParams(response.request().postData()).has('playlist_id'));
  let next=preferenceResponse(page,'skip');await mixedCard.locator('[data-music-next]').click();
  let nextResponse=await next;equal(nextResponse.status(),200,'Native deliberate Next uses the exact acknowledged receipt');
  check((await nextResponse.json()).recorded===true,'Native skip is acknowledged');
  await mixedCard.locator('[data-music-state="playing"]').waitFor();
  const ytRecordResponse=await ytRecorded;
  check((await ytRecordResponse.json()).recorded===true,'The actual YouTube recorder acknowledges its child-playlist receipt');
  const ytRecordFields=new URLSearchParams(ytRecordResponse.request().postData());
  await waitUntil(()=>fixture.readDb('SELECT play_id FROM vp3_youtube_playlist_plays_v1 WHERE owner_user_id=? AND playlist_id=? AND play_id=?',
    [11,ytRecordFields.get('playlist_id'),ytRecordFields.get('play_id')]).length===1,'The exact acknowledged YouTube receipt must reach its canonical history ledger');
  // The recorder uses a single request for either ordinary or child-list plays;
  // its completion is observed through the ledger and a following browser turn.
  await page.waitForFunction(before=>window.__preferencesYouTube.active()?.state===1&&window.__preferencesYouTube.acknowledged>before,acknowledgedBefore);
  next=preferenceResponse(page,'skip');await mixedCard.locator('[data-music-next]').click();
  nextResponse=await next;equal(nextResponse.status(),200,'YouTube deliberate Next uses its acknowledged child-playlist receipt');
  check((await nextResponse.json()).recorded===true,'YouTube skip is acknowledged');
  equal(count('music_deliberate_skips_v1'),beforeSkips+2,'Each deliberate prior play is recorded once');
  const native45Selection={kind:'playlist',playlist_id:mixed.music_chat_v1.playlist.id,revision:mixed.music_chat_v1.playlist.revision,index:0};
  equal((await read(native45Selection)).preference,'neutral','A deliberate skip never writes an explicit dislike');
  await page.evaluate(()=>window.VP3_MUSIC_CHAT_V1.runCommand('stop'));
  equal(count('music_deliberate_skips_v1'),beforeSkips+2,'Stop adds no skip');

  const natural=await fixture.seed({conversation_id:43,playlist:true,title:'Natural completion',items:[
    {source:'youtube',source_id:'vp300000004'},{source:'vp3',source_id:'45'}]});
  const naturalCard=await attachMusic(page,natural);
  await naturalCard.getByRole('button',{name:'Play playlist',exact:true}).click();
  await page.waitForFunction(()=>window.__preferencesYouTube.active()?.state===1);
  const resolveNext=page.waitForResponse(response=>response.url().endsWith('/api/music-chat-v1.php')&&response.request().postDataJSON()?.action==='resolve'
    &&response.request().postDataJSON()?.index===1);
  await page.evaluate(()=>window.__preferencesYouTube.active().end());await resolveNext;await nativeReady(page,naturalCard);
  equal(count('music_deliberate_skips_v1'),beforeSkips+2,'Natural YouTube completion advances without a skip');
  await naturalCard.locator('[data-music-previous]').click();await page.waitForFunction(()=>window.__preferencesYouTube.active()?.state===1);
  equal(count('music_deliberate_skips_v1'),beforeSkips+2,'Previous does not create preference evidence');
  await page.evaluate(()=>window.__preferencesYouTube.active().fail());
  await naturalCard.locator('[data-music-state="unavailable"]').waitFor();
  await naturalCard.locator('[data-music-next]').click();await nativeReady(page,naturalCard);
  equal(count('music_deliberate_skips_v1'),beforeSkips+2,'Skipping a failed player is not a deliberate listening skip');
  await page.evaluate(()=>window.VP3_MUSIC_CHAT_V1.runCommand('stop'));

  const staleSeed=await fixture.seed({conversation_id:43,items:[{source:'vp3',source_id:'42'}]});
  const staleCard=await attachMusic(page,staleSeed);
  await page.waitForFunction(id=>document.querySelector(`[data-music-result-id="${id}"] .vp3-music-preferences`)?.getAttribute('aria-busy')==='false',staleSeed.music_chat_v1.result_id);
  let release,delivered;const gate=new Promise(resolve=>{release=resolve;});
  const delayedDelivered=new Promise(resolve=>{delivered=resolve;});let started=false;
  const lateRoute=async route=>{
    if(route.request().postDataJSON()?.action!=='set')return route.continue();
    try{started=true;const response=await route.fetch();await gate;await route.fulfill({response});}
    catch(error){if(!route.request().failure())throw error;}
    finally{delivered();}
  };
  await page.route('**/api/music-preferences-v1.php',lateRoute);
  await choice(staleCard,'like').click();await waitUntil(()=>started,'The delayed current-source request must have started');
  await page.evaluate(()=>{window.STONEFELLOW_CHAT.userId=22;window.dispatchEvent(new Event('stonefellow:chat-context-changing'));});
  release();await delayedDelivered;await page.unroute('**/api/music-preferences-v1.php',lateRoute);
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  await page.waitForFunction(()=>document.querySelectorAll('.vp3-music-preferences,.vp3-music-preferences-panel').length===0);
  equal(await page.locator('.vp3-music-preferences,.vp3-music-preferences-panel').count(),0,'Account/Chat changes remove private controls and reject late views');
  equal(errors,[],'No uncaught browser errors');
  await fs.writeFile(path.join(artifacts,'acceptance.json'),JSON.stringify({engine,checks,captures,
    boundaries:['Google responses','YouTube IFrame transport'],canonicalNativeAndYouTubeReceipts:true},null,2));
  console.log(`Music Preferences ${engine}: ${checks} checks passed; ${captures.length} screenshots.`);
}catch(error){
  await Promise.allSettled(pages.filter(page=>!page.isClosed()).map(async(page,index)=>{
    await page.screenshot({path:path.join(artifacts,`failure-${index}.png`)});
    await fs.writeFile(path.join(artifacts,`failure-${index}.html`),await page.content());
  }));
  await fs.writeFile(path.join(artifacts,'failure.json'),JSON.stringify({engine,checks,error:String(error.stack||error),errors},null,2));
  await fs.writeFile(path.join(artifacts,'php.log'),fixture.logs);throw error;
}finally{await browser?.close();fixture.close();}
