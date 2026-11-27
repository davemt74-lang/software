import assert from 'node:assert/strict';
import {startMusicChatFixture} from './music-chat-fixture-v1.mjs';

// Actual production HTTP controller and storage. The shared disposable fixture
// supplies authentication and external provider transport; no live Google claim.
const fixture = await startMusicChatFixture();
let checks = 0;
const equal = (actual, expected, message) => { assert.deepEqual(actual, expected, message); checks++; };
const ok = (actual, message) => { assert.ok(actual, message); checks++; };
const response = async (promise, status = 200) => {
  const result = await promise;
  const data = await fixture.json(result);
  equal(result.status, status, JSON.stringify(data));
  equal(data.ok, status === 200, JSON.stringify(data));
  equal(result.headers.get('cache-control'), 'private, no-store');
  return data;
};
const sql = (statement, parameters = []) => fixture.run(
  '$q=db()->prepare($argv[2]);$q->execute(json_decode($argv[3],true,512,JSON_THROW_ON_ERROR));', statement, JSON.stringify(parameters));
const count = table => Number(fixture.readDb('SELECT COUNT(*) AS n FROM ' + table)[0].n);
const raw = (body, type = 'application/json', extra = {}) => fetch(fixture.baseURL + '/api/music-chat-v1.php', {
  method:'POST', headers:{...fixture.credentials.headers, 'Content-Type':type, ...extra}, body
});
const selection = payload => payload.playlist
  ? {playlist_id:payload.playlist.id, revision:payload.playlist.revision}
  : {result_id:payload.result_id, revision:payload.revision};
try {
  await response(fixture.get('/api/music-chat-v1.php'), 405);
  await response(fixture.request('playlists', {}, {user:999}), 401);
  await response(fixture.request('playlists', {}, {token:'not-the-session'}), 419);
  await response(raw('{bad json'), 422);
  await response(raw('[]'), 422);
  await response(raw('null'), 422);
  await response(raw('{}', 'text/plain'), 415);
  await response(raw('x'.repeat(65537)), 413);
  for (const fields of [{agent_id:'7'}, {agent_id:-1}, {conversation_id:true}, {conversation_id:0},
    {conversation_id:Number.MAX_SAFE_INTEGER + 1}, {limit:101}, {offset:-1}, {limit:1.5},
    {owner_user_id:22}, {items:[]}, {audio_url:'https://untrusted.invalid/song.mp3'}]) {
    await response(fixture.request('playlists', fields), 422);
  }
  await response(fixture.request('unknown'), 422);
  await response(fixture.request('playlists', {agent_id:8}), 403);
  await response(fixture.request('playlists', {agent_id:9, conversation_id:52}), 403);
  const empty = await response(fixture.request('playlists'));
  equal(empty.playlists, []);
  equal(empty.total, 0);

  const seed = await fixture.seed({playlist:true, title:'Mixed night drive'});
  const result = seed.music_chat_v1;
  equal(result.bound, true);
  equal(result.playlist.visibility, 'private');
  equal(result.items.map(item => [item.source, item.source_id]), [['vp3','41'],['youtube','vp300000001'],['vp3','42']]);
  ok(/^[a-f0-9]{32}$/.test(result.playlist.id));
  ok(result.items.every(item => /^[a-f0-9]{32}$/.test(item.item_id)));
  equal(count('music_chat_playlists_v1'), 1);
  equal(count('vp3_youtube_saved_playlists_v1'), 1, 'Mixed lists reuse private YouTube playback provenance.');
  const child = fixture.readDb('SELECT * FROM vp3_youtube_saved_playlists_v1')[0];
  equal(JSON.parse(child.ordered_ids_json), ['vp300000001']);
  const stored = fixture.readDb('SELECT items_json FROM music_chat_playlists_v1')[0];
  ok(!stored.items_json.includes('YouTube Song'), 'Unified durable references do not retain a second metadata cache.');
  const transcript = fixture.readDb('SELECT context_json FROM chat_messages WHERE id=?', [seed.assistant_message_id])[0].context_json;
  ok(!transcript.includes('YouTube Song') && !transcript.includes('autoplay') && !transcript.includes('videos'));
  for (const table of ['track_play_sessions','vp3_youtube_playback_v1','vp3_youtube_playlist_plays_v1']) equal(count(table), 0, 'Saving is not a listen.');

  const loaded = (await response(fixture.request('result', {result_id:result.result_id}))).music_chat_v1;
  equal(loaded.items.map(item => item.item_id), result.items.map(item => item.item_id));
  equal(loaded.autoplay, undefined);
  const all = await response(fixture.request('playlists', {limit:1, offset:0}));
  equal(all.total, 1);
  equal(all.playlists[0].id, result.playlist.id);
  const sibling = (await response(fixture.request('playlist', {playlist_id:result.playlist.id, conversation_id:43}))).music_chat_v1;
  equal(sibling.playlist.id, result.playlist.id, 'An authorized owner can reopen a private list in another owned Chat.');
  equal(sibling.conversation_id, 43);
  await response(fixture.request('result', {result_id:result.result_id, conversation_id:43}), 404);
  await response(fixture.request('result', {result_id:result.result_id, agent_id:8, conversation_id:44}), 404);
  const foreign = await fixture.auth(22);
  const foreignScope = {agent_id:9, conversation_id:52};
  await response(fixture.request('playlist', {...foreignScope, playlist_id:result.playlist.id}, {user:22, auth:foreign}), 404);
  await response(fixture.request('result', {...foreignScope, result_id:result.result_id}, {user:22, auth:foreign}), 404);
  equal((await response(fixture.request('playlists', foreignScope, {user:22, auth:foreign}))).playlists, []);

  await response(fixture.request('resolve', {...selection(result), index:0, result_id:result.result_id}), 422);
  await response(fixture.request('resolve', {result_id:result.result_id, revision:result.revision, index:0}), 409);
  await response(fixture.request('resolve', {index:0, revision:1}), 422);
  for (const index of [-1, 25, '0', 0.5, true]) await response(fixture.request('resolve', {...selection(result), index}), 422);
  await response(fixture.request('resolve', {...selection(result), index:0, revision:'0'.repeat(64)}), 409);
  await response(fixture.request('resolve', {...selection(result), index:0, source_id:'43'}), 422);
  await response(fixture.request('resolve', {...selection(result), index:0, owner_user_id:22}), 422);
  const native = await response(fixture.request('resolve', {...selection(result), index:0}));
  equal(native.item.source, 'vp3');
  equal(native.media_playback.track_id, 41);
  ok(/^[a-f0-9]{64}$/.test(native.media_playback.proof));
  equal(native.youtube_results, undefined);
  const youtube = await response(fixture.request('resolve', {...selection(result), index:1}));
  equal(youtube.item.source, 'youtube');
  equal(youtube.youtube_results.videos.map(video => video.video_id), ['vp300000001']);
  equal(youtube.source_message_id, seed.assistant_message_id);
  equal(youtube.media_playback, undefined);
  const savedYoutube = await response(fixture.request('resolve', {playlist_id:result.playlist.id, revision:1, index:1}));
  equal(savedYoutube.youtube_playlist_id, child.playlist_id);
  equal(savedYoutube.youtube_results.videos.length, 1);
  for (const table of ['track_play_sessions','vp3_youtube_playback_v1','vp3_youtube_playlist_plays_v1']) equal(count(table), 0, 'Resolving a song does not record playback.');

  let playlist = (await response(fixture.request('playlist', {playlist_id:result.playlist.id}))).music_chat_v1;
  const originalOrder = playlist.items.map(item => item.item_id);
  const reversed = [...originalOrder].reverse();
  playlist = (await response(fixture.request('playlist_update', {playlist_id:playlist.playlist.id, revision:1,
    title:'Road trip', order:reversed}))).music_chat_v1;
  equal(playlist.playlist.title, 'Road trip');
  equal(playlist.playlist.revision, 2);
  equal(playlist.items.map(item => item.item_id), reversed);
  await response(fixture.request('playlist_update', {playlist_id:playlist.playlist.id, revision:1, title:'Stale overwrite'}), 409);
  await response(fixture.request('resolve', {playlist_id:playlist.playlist.id, revision:1, index:0}), 409);
  await response(fixture.request('playlist_update', {playlist_id:playlist.playlist.id, revision:2, order:[reversed[0],reversed[0]]}), 422);
  await response(fixture.request('playlist_update', {playlist_id:playlist.playlist.id, revision:2, order:['0'.repeat(32)]}), 422);
  equal(fixture.readDb('SELECT revision,title FROM music_chat_playlists_v1')[0], {revision:2,title:'Road trip'}, 'Rejected edits preserve persisted revision and name.');
  const addition = (await fixture.seed({items:[{source:'vp3',source_id:'45'}]})).music_chat_v1;
  playlist = (await response(fixture.request('playlist_add', {playlist_id:playlist.playlist.id, revision:2,
    result_id:addition.result_id, index:0}))).music_chat_v1;
  equal(playlist.playlist.revision, 3);
  equal(playlist.items.map(item => item.source_id), ['42','vp300000001','41','45']);
  const duplicate = (await response(fixture.request('playlist_add', {playlist_id:playlist.playlist.id, revision:3,
    result_id:addition.result_id, index:0}))).music_chat_v1;
  equal(duplicate.playlist.revision, 3);
  equal(duplicate.items.length, 4);
  playlist = (await response(fixture.request('playlist_update', {playlist_id:playlist.playlist.id, revision:3,
    order:playlist.items.slice(0,3).map(item => item.item_id)}))).music_chat_v1;
  equal(playlist.playlist.revision, 4);
  equal(playlist.items.map(item => item.source_id), ['42','vp300000001','41']);
  const restoredList = (await response(fixture.request('result', {result_id:result.result_id}))).music_chat_v1;
  equal(restoredList.items.map(item => item.item_id), playlist.items.map(item => item.item_id), 'Restored saved cards keep current order and revision together.');
  equal(restoredList.playlist.revision, 4);
  equal((await response(fixture.request('resolve', {...selection(restoredList), index:0}))).media_playback.track_id, 42);

  // Same action may be delivered twice; only the first claim authorizes an
  // automatic attempt. Real separate-process database races are in the SQL suite.
  const automatic = (await fixture.seed({items:[{source:'vp3',source_id:'41'},{source:'vp3',source_id:'42'}], autoplay:true})).music_chat_v1;
  ok(automatic.autoplay?.action_id);
  await response(fixture.request('resolve', {...selection(automatic), index:1, auto_action_id:automatic.autoplay.action_id}), 409);
  const autoInput = {...selection(automatic), index:0, auto_action_id:automatic.autoplay.action_id};
  const firstAuto = await response(fixture.request('resolve', autoInput));
  equal(firstAuto.media_playback.track_id, 41);
  const secondAuto = await response(fixture.request('resolve', autoInput));
  equal(secondAuto.already_claimed, true);
  equal(secondAuto.media_playback, undefined);
  equal((await response(fixture.request('resolve', {...selection(automatic), index:0}))).media_playback.track_id, 41, 'A later manual Play is deliberate and allowed.');
  equal((await response(fixture.request('result', {result_id:automatic.result_id}))).music_chat_v1.autoplay, undefined, 'Restored history cannot issue a new play action.');

  for (const field of ['user_active','account_allowed','chat_allowed','agent_active']) {
    await fixture.control({[field]:false});
    await response(fixture.request('playlists'), 403);
    await response(fixture.request('resolve', {...selection(automatic), index:0}), 403);
    await fixture.control({[field]:true});
  }
  sql('UPDATE chat_conversations SET user_agent_id=8 WHERE id=42');
  await response(fixture.request('playlist', {playlist_id:playlist.playlist.id}), 403);
  sql('UPDATE chat_conversations SET user_agent_id=7 WHERE id=42');
  await fixture.control({track_id:41, track_published:false});
  const unavailable = (await response(fixture.request('result', {result_id:result.result_id}))).music_chat_v1;
  equal(unavailable.items.length, 3);
  equal(unavailable.items[2].available, false);
  equal(unavailable.items[2].source_id, '');
  equal(unavailable.items[1].available, true);
  await response(fixture.request('resolve', {...selection(unavailable), index:2}), 409);
  await fixture.control({track_id:41, track_published:true});
  await fixture.control({consent:false});
  const noYoutube = (await response(fixture.request('result', {result_id:result.result_id}))).music_chat_v1;
  equal(noYoutube.items[1].available, false);
  equal(noYoutube.items[1].source_id, '');
  ok(!JSON.stringify(noYoutube).includes('YouTube Song 1'), 'Consent withdrawal removes private provider metadata from the response.');
  await response(fixture.request('resolve', {...selection(noYoutube), index:1}), 409);
  equal((await response(fixture.request('resolve', {...selection(noYoutube), index:2}))).media_playback.track_id, 41, 'VP3 remains its own explicit source.');

  sql('UPDATE music_chat_results_v1 SET expires_at=? WHERE result_id=?', [Math.floor(Date.now()/1000)-1, result.result_id]);
  await response(fixture.request('result', {result_id:result.result_id}), 410);
  await response(fixture.request('resolve', {result_id:result.result_id, revision:result.revision, index:0}), 410);
  await response(fixture.request('playlist_remove', {playlist_id:playlist.playlist.id, revision:3}), 409);
  await response(fixture.request('playlist_remove', {playlist_id:playlist.playlist.id, revision:4}));
  await response(fixture.request('playlist', {playlist_id:playlist.playlist.id}), 404);
  equal((await response(fixture.request('playlists'))).total, 0);
  for (const table of ['track_play_sessions','vp3_youtube_playback_v1','vp3_youtube_playlist_plays_v1']) equal(count(table), 0);

  await fixture.control({consent:true});
  const chat = async (message, requestId, expected = 200) => {
    const reply = await fixture.postJson('/api/chat.php', {action:'send', agent_id:7, conversation_id:42,
      message, music_request_id:requestId});
    const data = await fixture.json(reply);
    equal(reply.status, expected, JSON.stringify(data));
    equal(data.ok, expected === 200);
    return data;
  };
  const one = await chat('Show me one calm song.', 'api-one-global-result');
  equal(one.music_chat_v1.requested_count, 1);
  equal(one.music_chat_v1.items.length, 1, 'Two searched sources still produce one result total.');
  equal(one.music_chat_v1.autoplay, undefined);
  const onlyYoutube = await chat('Show one song from YouTube only.', 'api-one-youtube-result');
  equal(onlyYoutube.music_chat_v1.items.map(item => item.source), ['youtube']);
  equal(onlyYoutube.music_chat_v1.requested_count, 1);
  const originalRequest = 'Create a 3-song calm playlist named "HTTP request retry".';
  const created = await chat(originalRequest, 'api-stable-create-request');
  equal(created.music_chat_v1.items.length, 3);
  equal(created.music_chat_v1.playlist.visibility, 'private');
  ok(created.answer.startsWith('Created'), 'Chat confirms creation only after a persisted assistant-bound list.');
  equal(count('music_chat_playlists_v1'), 1);
  const used = fixture.readDb('SELECT * FROM agent_connection_youtube_usage_v1');
  const retried = await chat(originalRequest, 'api-stable-create-request');
  equal(retried.music_chat_v1.result_id, created.music_chat_v1.result_id);
  equal(retried.music_chat_v1.playlist.id, created.music_chat_v1.playlist.id);
  equal(retried.music_chat_v1.replayed, true);
  equal(retried.music_chat_v1.autoplay, undefined);
  equal(count('music_chat_playlists_v1'), 1, 'A response retry never creates a second saved list.');
  equal(fixture.readDb('SELECT * FROM agent_connection_youtube_usage_v1'), used, 'A retried request does not perform another provider search.');
  await chat('Create a 4-song calm playlist named "HTTP request retry".', 'api-stable-create-request', 409);
  equal(count('music_chat_playlists_v1'), 1);
  const ordinal = await chat('Play the second one.', 'api-selected-second-song');
  equal(ordinal.music_chat_v1.items.map(item => [item.source,item.source_id]),
    [[created.music_chat_v1.items[1].source,created.music_chat_v1.items[1].source_id]]);
  ok(ordinal.music_chat_v1.autoplay?.action_id);
  equal(fixture.readDb('SELECT * FROM agent_connection_youtube_usage_v1'), used, 'An exact follow-up uses stored selection without searching.');
  const global = (await fixture.seed({items:[{source:'vp3',source_id:'41'}, {source:'youtube',source_id:'vp300000001'},
    {source:'vp3',source_id:'42'}]})).music_chat_v1;
  const wrongSource = await chat('Play the second one from VP3.', 'api-ordinal-source-mismatch');
  equal(wrongSource.music_chat_v1?.autoplay, undefined, 'Source restriction cannot shift original global #2 to another song.');
  equal(wrongSource.music_chat_v1?.items?.length || 0, 0);
  equal(global.items[1].source, 'youtube');
  await response(fixture.request('playlist_remove', {playlist_id:created.music_chat_v1.playlist.id, revision:1}));
  equal(count('music_chat_playlists_v1'), 0);
  for (const table of ['track_play_sessions','vp3_youtube_playback_v1','vp3_youtube_playlist_plays_v1']) equal(count(table), 0,
    'Search, create and an unclaimed action are never actual playback.');

  const saveSource = (await fixture.seed({items:[{source:'vp3',source_id:'41'}]})).music_chat_v1;
  const saveFields = {result_id:saveSource.result_id, title:'Original HTTP list', request_key:'http-create-key'};
  const saved = (await response(fixture.request('playlist_create', saveFields))).music_chat_v1;
  equal(saved.playlist.visibility, 'private');
  await response(fixture.request('playlist_create', {...saveFields, title:'Conflicting HTTP name'}), 409);
  await response(fixture.request('playlist_update', {playlist_id:saved.playlist.id, revision:1, title:'Renamed HTTP list'}));
  const savedReplay = (await response(fixture.request('playlist_create', saveFields))).music_chat_v1;
  equal(savedReplay.playlist.id, saved.playlist.id);
  equal(savedReplay.playlist.title, 'Renamed HTTP list');
  equal(savedReplay.playlist.revision, 2, 'Creation replay uses immutable input while preserving newer list edits.');
  await response(fixture.request('playlist_create', {...saveFields, request_key:'a-new-key'}), 409);
  await fixture.seed({items:[{source:'vp3',source_id:'45'}]});
  const addCommand = 'Add this to my Renamed HTTP list playlist.';
  const chatAdded = await chat(addCommand, 'http-add-existing-list');
  equal(chatAdded.music_chat_v1.intent, 'add_to_playlist');
  equal(chatAdded.music_chat_v1.playlist.id, saved.playlist.id);
  equal(chatAdded.music_chat_v1.playlist.revision, 3);
  equal(chatAdded.music_chat_v1.items.map(item => item.source_id), ['41','45']);
  equal(chatAdded.music_chat_v1.playlist_changed, true);
  equal(chatAdded.music_chat_v1.autoplay, undefined);
  equal(count('music_chat_playlists_v1'), 1, 'Conversational addition edits the existing private playlist.');
  const chatAddReplay = await chat(addCommand, 'http-add-existing-list');
  equal(chatAddReplay.music_chat_v1.result_id, chatAdded.music_chat_v1.result_id);
  equal(chatAddReplay.music_chat_v1.playlist.revision, 3);
  equal(chatAddReplay.music_chat_v1.replayed, true);
  const chatDuplicate = await chat('Add the second one to my Renamed HTTP list playlist.', 'http-add-existing-duplicate');
  equal(chatDuplicate.music_chat_v1.playlist.revision, 3);
  equal(chatDuplicate.music_chat_v1.playlist_changed, false, 'An already-present selection has an honest no-change receipt.');
  equal(chatDuplicate.music_chat_v1.items.length, 2);
  equal(chatDuplicate.music_chat_v1.autoplay, undefined);
  const missingTarget = await chat('Add the first one to my Missing HTTP list playlist.', 'http-add-missing-target');
  equal(missingTarget.music_chat_v1, undefined);
  equal(count('music_chat_playlists_v1'), 1, 'A missing target is never silently created.');
  await response(fixture.request('playlist_remove', {playlist_id:saved.playlist.id, revision:3}));
  equal(count('music_chat_playlists_v1'), 0);

  // A normal read reports the deployment prerequisite and leaves schema alone.
  fixture.run('db()->exec("DROP TABLE music_chat_play_actions_v1;DROP TABLE music_chat_results_v1;DROP TABLE music_chat_playlists_v1");');
  const beforeSchema = fixture.readDb('SELECT name,sql FROM sqlite_master ORDER BY name');
  const notInstalled = await response(fixture.request('playlists'), 503);
  equal(notInstalled.code, 'upgrade_required');
  equal(fixture.readDb('SELECT name,sql FROM sqlite_master ORDER BY name'), beforeSchema, 'Read paths never install the missing schema.');
  ok(!/Fatal error|Uncaught |PHP Warning/.test(fixture.logs), fixture.logs);
  console.log('MUSIC_CHAT_API_V1=PASS ' + checks + ' actual PHP/SQLite HTTP assertions; external provider/auth fixture boundaries explicit');
} catch (error) {
  console.error(fixture.logs);
  throw error;
} finally {
  fixture.close();
}
