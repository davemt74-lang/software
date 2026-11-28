import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import {startMusicPreferencesFixture} from './music-preferences-fixture-v1.mjs';

// Shipped PHP controller, real SQLite and canonical playback receipts. No live provider/account.
const fixture = await startMusicPreferencesFixture();
const selections = fixture.preferences.selections;
let checks = 0;
const equal = (actual, expected, label) => { assert.deepEqual(actual, expected, label); checks++; };
const ok = (actual, label) => { assert.ok(actual, label); checks++; };
const sql = (statement, parameters = []) => fixture.run('$q=db()->prepare($argv[2]);$q->execute(json_decode($argv[3],true,512,JSON_THROW_ON_ERROR));', statement, JSON.stringify(parameters));
const count = table => Number(fixture.readDb('SELECT COUNT(*) AS n FROM ' + table)[0].n);
const digest = (except = []) => fixture.run('echo music_preferences_fixture_digest_v1(db(),json_decode($argv[2],true,512,JSON_THROW_ON_ERROR));', JSON.stringify(except));
const sourceDigest = () => digest(['music_preferences_v1','music_preference_requests_v1','music_deliberate_skips_v1']);
const request = (action, fields = {}, options = {}) => fixture.preferenceRequest(action, fields, options);
const response = async (promise, status = 200, privateCache = true) => {
  const result = await promise, data = await fixture.json(result);
  equal(result.status, status, JSON.stringify(data));
  equal(data.ok, status === 200, JSON.stringify(data));
  if (privateCache) equal(result.headers.get('cache-control'), 'private, no-store');
  return data;
};
const read = async selection => (await response(request('read', {selection}))).state;
const write = (selection, preference, expected_revision, request_id = crypto.randomUUID(), status = 200, options = {}) =>
  response(request('set', {selection, preference, expected_revision, request_id}, options), status);
const list = async (fields = {}) => (await response(request('list', fields))).preferences;
const raw = (body, type = 'application/json') => fetch(fixture.baseURL + '/api/music-preferences-v1.php', {
  method:'POST', headers:{...fixture.credentials.headers, 'Content-Type':type}, body});

try {
  await response(fixture.get('/api/music-preferences-v1.php'), 405);
  await response(request('list', {}, {user:999}), 401);
  await response(request('list', {}, {token:'wrong-session'}), 419);
  for (const body of ['{bad json','[]','null']) await response(raw(body), 422);
  await response(raw('{}', 'text/plain'), 415);
  await response(raw('x'.repeat(65537)), 413);
  await response(request('unknown'), 422);
  for (const fields of [{agent_id:'7'}, {conversation_id:0}, {conversation_id:true}, {limit:51}, {limit:'5'},
    {source:'stoneward'}, {owner_user_id:22}, {items:[]}, {cursor:[]}, {unexpected:true}]) {
    await response(request('list', fields), 422);
  }
  await response(request('list', {agent_id:8}), 403);
  await response(request('list', {conversation_id:52}), 403);
  const beforeRead = digest();
  equal((await read(selections.native)).preference, 'neutral');
  equal((await read(selections.native)).revision, 0);
  equal((await list()).items, []);
  equal(digest(), beforeRead, 'Reading choices does not write schema, preferences, listening or provider usage.');

  const originalSources = sourceDigest(), likeKey = crypto.randomUUID();
  const first = await write(selections.native, 'like', 0, likeKey);
  equal([first.state.source, first.state.source_id, first.state.preference, first.state.revision, first.replayed], ['vp3','71','like',1,false]);
  const requestRows = count('music_preference_requests_v1');
  const retry = await write(selections.native, 'like', 0, likeKey);
  equal([retry.state.preference, retry.state.revision, retry.replayed], ['like',1,true]);
  equal(count('music_preference_requests_v1'), requestRows);
  equal((await read(selections.repeat)).preference, 'like', 'Repeat listening keeps one source identity.');
  equal((await read(selections.result_native)).preference, 'like', 'Displayed results share explicit taste.');
  equal((await read(selections.saved_native)).preference, 'like', 'Saved playlists share explicit taste.');
  await write(selections.native, 'dislike', 0, likeKey, 409);
  await write(selections.result_other, 'like', 0, likeKey, 409);
  await write(selections.native, 'dislike', 0, crypto.randomUUID(), 409);
  const no = await write(selections.native, 'dislike', 1);
  equal([no.state.preference,no.state.revision], ['dislike',2]);
  const prior = await write(selections.native, 'like', 0, likeKey);
  equal([prior.state.preference,prior.state.revision,prior.replayed], ['dislike',2,true], 'Old successful request returns current state.');
  const clear = await write(selections.native, 'clear', 2);
  equal([clear.state.preference,clear.state.revision], ['neutral',3]);
  const afterClear = await write(selections.native, 'like', 0, likeKey);
  equal([afterClear.state.preference,afterClear.state.revision,afterClear.replayed], ['neutral',3,true], 'Old response cannot restore a cleared choice.');
  equal((await list()).items, []);
  equal(sourceDigest(), originalSources, 'Preferences never create playback, change playlists, or call a provider.');

  for (const fields of [
    {preference:'skip'},{preference:'failed'},{preference:'declined'},{preference:'neutral'},{preference:'LIKE'},
    {expected_revision:-1},{expected_revision:'3'},{expected_revision:true},{request_id:''},{request_id:'invalid request key'},
    {selection:null},{selection:[]},{selection:{kind:'raw',source:'vp3',source_id:'71'}},
    {selection:{...selections.native,title:'Forged provider title'}},{source:'youtube'},{owner_user_id:22},
  ]) await response(request('set', {selection:selections.native,preference:'like',expected_revision:3,request_id:crypto.randomUUID(),...fields}), 422);
  await response(request('read', {selection:{...selections.native,revision:'0'.repeat(64)}}), 409);
  await response(request('read', {selection:selections.native,conversation_id:42}), 404);
  const foreign = await fixture.auth(22);
  await response(request('read', {selection:selections.native,agent_id:9,conversation_id:52}, {user:22,auth:foreign}), 404);

  const native = (await write(selections.native, 'like', 3)).state;
  const youtube = (await write(selections.ordinary, 'dislike', 0)).state;
  equal([youtube.source,youtube.source_id,youtube.preference], ['youtube','vp300000006','dislike']);
  equal((await read(selections.playlist)).preference, 'dislike');
  equal((await read(selections.result_youtube)).preference, 'dislike');
  equal((await read(selections.saved_youtube)).preference, 'dislike');
  equal((await list({source:'vp3'})).items.length, 1);
  equal((await list({source:'youtube'})).items.length, 1);
  const page = await list({limit:1}), next = await list({limit:1,cursor:page.next_cursor});
  equal([page.items.length,page.has_more,next.items.length,next.has_more], [1,true,1,false]);
  ok(page.items[0].reference !== next.items[0].reference);
  await response(request('list', {limit:1,source:'youtube',cursor:page.next_cursor}), 409);
  const stored = JSON.stringify(fixture.readDb('SELECT * FROM music_preferences_v1'));
  for (const value of ['History Moonlight','YouTube Song 6','vp300000006','Fixture Channel','thumbnail','audio_path','autoplay']) {
    ok(!stored.includes(value), 'Persistent choice does not retain provider/private metadata: ' + value);
  }

  for (const column of ['is_active','account_allowed','chat_allowed']) {
    sql('UPDATE users SET ' + column + '=0 WHERE id=11');
    await response(request('list'), 403);
    await write(selections.native, 'clear', native.revision, crypto.randomUUID(), 403);
    sql('UPDATE users SET ' + column + '=1 WHERE id=11');
  }
  sql("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','playback-history','deny')");
  await response(request('read', {selection:selections.native}), 403);
  await response(request('list'), 403);
  sql('DELETE FROM user_agent_data_rules WHERE agent_id=7');
  const nativeChoice = (await list({source:'vp3'})).items[0];
  sql("UPDATE tracks SET visibility='private',owner_user_id=22,title='REVOKED PREFERENCE HTTP SECRET' WHERE id=71");
  const unavailable = await list({source:'vp3'});
  ok(!JSON.stringify(unavailable).includes('REVOKED PREFERENCE HTTP SECRET') && !JSON.stringify(unavailable).includes('History Moonlight'));
  await write(selections.native, 'like', native.revision, crypto.randomUUID(), 409);
  const forgotten = await write({kind:'preference',reference:nativeChoice.reference}, 'clear', nativeChoice.revision);
  equal(forgotten.state.preference, 'neutral', 'Owner can remove the choice without regaining revoked metadata.');
  sql("UPDATE tracks SET visibility='public',owner_user_id=11,title='History Moonlight' WHERE id=71");

  const youtubeChoice = (await list({source:'youtube'})).items[0];
  const consent = fixture.readDb('SELECT * FROM agent_connection_youtube_consent_v1 WHERE owner_user_id=11')[0];
  sql("UPDATE agent_connection_youtube_consent_v1 SET policy_version='revoked' WHERE owner_user_id=11");
  ok(!JSON.stringify(await list({source:'youtube'})).includes('YouTube Song 6'));
  const youtubeForgotten = await write({kind:'preference',reference:youtubeChoice.reference}, 'clear', youtubeChoice.revision);
  equal(youtubeForgotten.state.preference, 'neutral');
  sql('UPDATE agent_connection_youtube_consent_v1 SET policy_version=?,accepted_at=? WHERE owner_user_id=11', [consent.policy_version,consent.accepted_at]);

  // Only an acknowledged canonical start permits a skip observation. Resolution alone is insufficient.
  const resolve = await response(fixture.request('resolve', {conversation_id:43,
    result_id:selections.result_native.result_id,revision:selections.result_native.revision,index:0}));
  const action = resolve.media_playback, playId = crypto.randomUUID(), skipKey = crypto.randomUUID();
  let receipt = {kind:'n',id:'999999'};
  await response(request('skip', {selection:selections.result_native,receipt,request_id:skipKey}), 409);
  const accepted = await response(fixture.postJson('/api/playback.php', {protocol:'native_listening_v1',action:'start',listener_user_id:11,
    track_id:action.track_id,play_id:playId,position_ms:0,playback_rate:1,muted:false,loop:false,source:'agent_chat',
    source_url:fixture.baseURL+'/chat.php',media_offer_playback:action}), 200, false);
  ok(Number.isSafeInteger(accepted.session_id) && accepted.session_id > 0);
  receipt = {kind:'n',id:String(accepted.session_id)};
  const neutralBefore = await read(selections.result_native);
  const skipped = await response(request('skip', {selection:selections.result_native,receipt,request_id:skipKey}));
  equal([skipped.recorded,skipped.replayed], [true,false]);
  const skippedAgain = await response(request('skip', {selection:selections.result_native,receipt,request_id:skipKey}));
  equal(skippedAgain.replayed, true);
  await response(request('skip', {selection:selections.result_native,receipt,request_id:crypto.randomUUID()}));
  equal(count('music_deliberate_skips_v1'), 1, 'Different request ids cannot duplicate a skip of one actual play.');
  equal(await read(selections.result_native), neutralBefore, 'Next is separate evidence and never an automatic Dislike.');
  await response(request('skip', {selection:selections.result_other,receipt,request_id:crypto.randomUUID()}), 409);
  for (const invalid of [{kind:'failed',id:String(accepted.session_id)},{kind:'n',id:''},{kind:'y',id:playId,video_id:'forged'}]) {
    await response(request('skip', {selection:selections.result_native,receipt:invalid,request_id:crypto.randomUUID()}), 422);
  }
  equal(count('music_deliberate_skips_v1'), 1);

  // Retained YouTube starts remain readable while capture is paused, but new Next evidence does not.
  const capture = fixture.readDb('SELECT capture_key FROM vp3_youtube_history_settings_v1 WHERE owner_user_id=11')[0].capture_key;
  const youtubeReceipts = [
    [selections.ordinary,{kind:'y',id:fixture.seeded.ordinary}],
    [selections.playlist,{kind:'p',id:fixture.seeded.playlist}],
  ];
  sql("UPDATE vp3_youtube_history_settings_v1 SET capture_key='' WHERE owner_user_id=11");
  const pausedDigest = digest();
  for (const [selection,receipt] of youtubeReceipts) {
    await response(request('skip', {selection,receipt,request_id:crypto.randomUUID()}), 403);
  }
  equal(digest(), pausedDigest, 'Paused capture writes neither YouTube Next observations nor request receipts.');
  sql('UPDATE vp3_youtube_history_settings_v1 SET capture_key=? WHERE owner_user_id=11', [capture]);
  for (const [selection,receipt] of youtubeReceipts) {
    const observed = await response(request('skip', {selection,receipt,request_id:crypto.randomUUID()}));
    equal([observed.recorded,observed.replayed], [true,false]);
  }
  equal(count('music_deliberate_skips_v1'), 3, 'Native, ordinary YouTube and saved-list YouTube starts retain separate Next receipts.');
  equal((await read(selections.ordinary)).preference, 'neutral', 'YouTube Next still does not create a Dislike.');

  // Missing schema reports the ordinary upgrade gate, without installing during a request.
  sql('DROP TABLE music_preference_requests_v1');
  const beforeMissing = digest();
  const missing = await response(request('list'), 503);
  equal(missing.code, 'upgrade_required');
  equal(digest(), beforeMissing);
  ok(!/Fatal error|PHP Warning|Parse error/.test(fixture.logs), fixture.logs.slice(-16000));
  console.log(`Music preferences HTTP: ${checks} checks passed (real PHP, exact source selections, current authority and canonical skip receipts).`);
} catch (error) {
  console.error(fixture.logs.slice(-20000));
  throw error;
} finally { fixture.close(); }
