import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import {startMusicHistoryFixture} from './music-history-fixture-v1.mjs';

// Real PHP HTTP endpoints, real SQLite storage and canonical player receipts.
// The inherited fixture supplies isolated authentication and no live provider.
const fixture = await startMusicHistoryFixture();
let checks = 0;
const equal = (actual, expected, label) => { assert.deepEqual(actual, expected, label); checks++; };
const ok = (actual, label) => { assert.ok(actual, label); checks++; };
const sql = (statement, parameters = []) => fixture.run('$q=db()->prepare($argv[2]);$q->execute(json_decode($argv[3],true,512,JSON_THROW_ON_ERROR));', statement, JSON.stringify(parameters));
const count = table => Number(fixture.readDb('SELECT COUNT(*) AS n FROM ' + table)[0].n);
const ledgers = () => Object.fromEntries(['track_play_sessions','music_listening_starts_v1','music_listening_sessions_v1',
  'music_listening_receipts_v1','track_play_events','vp3_youtube_playback_v1','vp3_youtube_watch_v1','vp3_youtube_playhead_v1','vp3_youtube_playlist_plays_v1']
  .map(table => [table, count(table)]));
const databaseSnapshot = () => fixture.run('$out=[];$tables=db()->query("SELECT name FROM sqlite_master WHERE type=\'table\' AND name NOT LIKE \'sqlite_%\' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);foreach($tables as $table){$rows=db()->query("SELECT * FROM ".$table)->fetchAll(PDO::FETCH_ASSOC);$encoded=array_map(static fn(array $row):string=>json_encode($row,JSON_THROW_ON_ERROR),$rows);sort($encoded);$out[$table]=$encoded;}echo hash("sha256",json_encode($out,JSON_THROW_ON_ERROR));');
const response = async (promise, status = 200, cache = 'private, no-store') => {
  const result = await promise;
  const data = await fixture.json(result);
  equal(result.status, status, JSON.stringify(data));
  equal(data.ok, status === 200, JSON.stringify(data));
  if (cache !== null) equal(result.headers.get('cache-control'), cache);
  return data;
};
const history = (action, fields = {}, options = {}) => fixture.historyRequest(action, fields, options);
const query = async (fields = {}) => (await response(history('query', fields))).music_history_v1;
const pageFields = page => ({source_message_id:page.source_message_id, revision:page.revision});
const select = async (page, ref, operation, requestId = crypto.randomUUID(), target = {}, status = 200) =>
  response(history('selection', {...pageFields(page), entry_ref:ref, operation, request_id:requestId, ...target}), status);
const music = (action, fields = {}, options = {}) => fixture.request(action, {conversation_id:43, ...fields}, options);
const selectionFields = payload => ({result_id:payload.result_id, revision:payload.revision, index:0});
const resolve = async payload => response(music('resolve', selectionFields(payload)));
const player = (action, options = {}) => fixture.postJson('/api/player-library-v76.php', {action:'playback_action', media_playback:action}, options);
const nativeBody = (action, extra = {}) => ({protocol:'native_listening_v1', action:'start', listener_user_id:11,
  track_id:action.track_id, play_id:crypto.randomUUID(), position_ms:action.operation === 'resume' ? Math.round(action.position_seconds * 1000) : 0,
  playback_rate:1, muted:false, loop:false, source:'agent_chat', source_url:fixture.baseURL + '/chat.php', media_offer_playback:action, ...extra});
const nativeStart = (body, options = {}) => fixture.postJson('/api/playback.php', body, options);
const legacyStart = (action, extra = {}, options = {}) => fixture.postJson('/api/playback.php', {action:'start', track_id:action.track_id,
  position:action.operation === 'resume' ? action.position_seconds : 0, duration:65, source:'agent_chat', media_offer_playback:action, ...extra}, options);
const youtubePost = fields => fetch(fixture.baseURL + '/api/chat-youtube-playback-v1.php', {
  method:'POST', headers:{...fixture.credentials.headers, 'Content-Type':'application/x-www-form-urlencoded'},
  body:new URLSearchParams({csrf_token:fixture.csrf, ...fields})});
const raw = (body, type = 'application/json') => fetch(fixture.baseURL + '/api/music-history-v1.php', {
  method:'POST', headers:{...fixture.credentials.headers, 'Content-Type':type}, body});

try {
  await response(fixture.get('/api/music-history-v1.php'), 405);
  await response(history('query', {}, {user:999}), 401);
  await response(history('query', {}, {token:'wrong-session'}), 419);
  for (const body of ['{bad json','[]','null']) await response(raw(body), 422);
  await response(raw('{}', 'text/plain'), 415);
  await response(raw('x'.repeat(65537)), 413);
  await response(history('unknown'), 422);
  for (const fields of [{agent_id:'7'}, {conversation_id:0}, {conversation_id:true}, {limit:51}, {limit:'5'},
    {source:'stoneward'}, {owner_user_id:22}, {items:[]}, {query:'q'.repeat(201)}, {timezone:'Invalid/Zone'},
    {from:'2026-02-30'}, {from:'2026-11-27', until_at:'2026-11-28T06:00:00Z'}]) {
    await response(history('query', fields), 422);
  }
  await response(history('query', {agent_id:8}), 403);
  await response(history('query', {conversation_id:52}), 403);
  const originalLedgers = ledgers();
  const originalUsage = fixture.readDb('SELECT * FROM agent_connection_youtube_usage_v1');
  let full = await query({limit:50});
  equal(full.items.length, 15, 'All retained owner receipts, including repeated native and MC1 child YouTube, are visible.');
  equal(full.items.slice(0,4).map(item => item.entry_ref), [fixture.seeded.refs.native, fixture.seeded.refs.ordinary,
    fixture.seeded.refs.playlist, fixture.seeded.refs.repeat]);
  equal(full.items[0].resume_seconds, 22);
  equal(full.items[1].resume_seconds, 12);
  equal(full.items[2].resume_seconds, 15);
  equal(full.items[3].listened_seconds, null);
  equal(full.items[3].evidence, 'started_only');
  equal(full.items.find(item => item.entry_ref === fixture.seeded.refs.legacy).measurement, 'native_legacy_reported');
  equal(full.items.find(item => item.entry_ref === fixture.seeded.refs.unavailable).title, 'Unavailable VP3 song');
  ok(full.items.filter(item => item.source_id === '71').length === 2, 'Repeated song plays are distinct history entries.');
  equal(full.has_more, false);
  equal(full.next_cursor, null);
  ok(full.coverage.summary.includes('not a complete'));
  equal(full.coverage.sources.youtube.capture_enabled, true);
  equal(full.coverage.sources.youtube.measurement_enabled, true);
  const saved = fixture.readDb('SELECT message,context_json FROM chat_messages WHERE id=?', [full.source_message_id])[0];
  const snapshot = JSON.parse(saved.context_json).music_history_v1;
  equal(snapshot.entry_refs, full.items.map(item => item.entry_ref));
  equal(snapshot.items, undefined);
  ok(!saved.context_json.includes('History Moonlight') && !saved.context_json.includes('YouTube Song 6')
    && !saved.context_json.includes('youtube_video') && !saved.context_json.includes('autoplay'), 'Durable page contains opaque refs and filters, not provider titles or playback actions.');
  equal(saved.message, 'Retained listening history was displayed in this Chat.');
  const beforeRead = databaseSnapshot();
  const readBack = (await response(history('read', pageFields(full)))).music_history_v1;
  equal(readBack.items.map(item => item.entry_ref), snapshot.entry_refs);
  equal(readBack.replayed, true);
  equal(databaseSnapshot(), beforeRead, 'Quiet history read makes no table writes or expiry cleanup.');
  await response(history('read', {...pageFields(full), revision:'0'.repeat(64)}), 409);
  await response(history('read', {...pageFields(full), conversation_id:42}), 404);
  const foreign = await fixture.auth(22);
  await response(history('read', {...pageFields(full), agent_id:9, conversation_id:52}, {user:22, auth:foreign}), 404);

  const filtered = await query({source:'vp3', query:'MOONLIGHT', timezone:'America/New_York'});
  equal(filtered.items.map(item => item.entry_ref), [fixture.seeded.refs.native, fixture.seeded.refs.repeat]);
  equal(filtered.timezone, 'America/New_York');
  const day = new Date(fixture.seeded.clock * 1000).toISOString().slice(0,10);
  const dated = await query({source:'vp3', from:day, to:day, timezone:'UTC', limit:50});
  ok(dated.items.every(item => item.played_at.startsWith(day)));
  const exact = await query({from_at:new Date((fixture.seeded.clock - 61) * 1000).toISOString().replace('.000',''),
    until_at:new Date((fixture.seeded.clock + 1) * 1000).toISOString().replace('.000',''), timezone:'UTC'});
  equal(exact.items.map(item => item.entry_ref), [fixture.seeded.refs.native, fixture.seeded.refs.ordinary, fixture.seeded.refs.playlist, fixture.seeded.refs.repeat]);
  equal((await query({query:'PRIVATE FOREIGN TITLE'})).items, []);
  let paged = await query({limit:5});
  const initialPaging = paged;
  const firstRefs = paged.items.map(item => item.entry_ref);
  paged = (await response(history('query', {...pageFields(paged), ...paged.filters, limit:5, cursor:paged.next_cursor}))).music_history_v1;
  equal(paged.source_message_id, initialPaging.source_message_id, 'CAS updates the current history message without inserting another card.');
  ok(paged.revision !== initialPaging.revision);
  equal(new Set([...firstRefs, ...paged.items.map(item => item.entry_ref)]).size, 10, 'Next page preserves distinct ordered receipts.');
  await response(history('query', {...pageFields(initialPaging), ...initialPaging.filters, limit:5}), 409);
  await response(history('query', {...pageFields(paged), ...paged.filters, source:'youtube', limit:5, cursor:paged.next_cursor}), 409);
  equal(ledgers(), originalLedgers, 'Queries, filtering, paging and quiet reload never record listening.');
  equal(fixture.readDb('SELECT * FROM agent_connection_youtube_usage_v1'), originalUsage, 'History does not search or refresh an external provider.');

  const consentEpoch = fixture.readDb('SELECT accepted_at FROM agent_connection_youtube_consent_v1 WHERE owner_user_id=11')[0].accepted_at;
  sql('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=? WHERE owner_user_id=11', [fixture.seeded.clock - 20]);
  equal((await query({source:'youtube'})).items.map(item => item.entry_ref), [fixture.seeded.refs.ordinary], 'Current consent includes its exact boundary and excludes earlier child plays.');
  sql('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=? WHERE owner_user_id=11', [fixture.seeded.clock - 19]);
  equal((await query({source:'youtube'})).items, [], 'Policy reacceptance cannot revive retained pre-consent receipts.');
  const consentRedacted = (await response(history('read', pageFields(full)))).music_history_v1;
  equal(consentRedacted.items.find(item => item.entry_ref === fixture.seeded.refs.ordinary).title, 'Unavailable history entry');
  equal(consentRedacted.items.find(item => item.entry_ref === fixture.seeded.refs.playlist).title, 'Unavailable history entry');
  ok(!JSON.stringify(consentRedacted).includes('YouTube Song 6'), 'An old saved page cannot bypass the consent epoch.');
  sql('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=? WHERE owner_user_id=11', [consentEpoch]);

  const playRequest = 'http-history-play-' + crypto.randomUUID();
  const resultsBefore = count('music_chat_results_v1');
  const play = await select(full, fixture.seeded.refs.native, 'play', playRequest);
  equal(play.music_chat_v1.items.map(item => [item.source,item.source_id]), [['vp3','71']]);
  equal(play.music_chat_v1.history_operation, 'play');
  ok(play.music_chat_v1.autoplay?.action_id);
  equal(count('music_chat_results_v1'), resultsBefore + 1);
  const messagesAfterPlay = count('chat_messages');
  const replay = await select(full, fixture.seeded.refs.native, 'play', playRequest);
  equal(replay.assistant_message_id, play.assistant_message_id);
  equal(replay.music_chat_v1.result_id, play.music_chat_v1.result_id);
  equal(replay.music_chat_v1.source_message_id, play.assistant_message_id);
  equal(replay.music_chat_v1.bound, true);
  equal(replay.music_chat_v1.replayed, true);
  equal(replay.music_chat_v1.autoplay, undefined, 'Response replay is quiet.');
  equal(count('chat_messages'), messagesAfterPlay, 'Exact selection retry never writes a second assistant turn.');
  equal(count('music_chat_results_v1'), resultsBefore + 1);
  await select(full, fixture.seeded.refs.ordinary, 'play', playRequest, {}, 409);
  const outsideRef = JSON.parse(fixture.run('echo json_encode(vp3_music_history_reference_v1(11,"n","999999"));'));
  await select(full, outsideRef, 'play', crypto.randomUUID(), {}, 409);
  await select(full, fixture.seeded.refs.unavailable, 'play', crypto.randomUUID(), {}, 409);
  await select(full, fixture.seeded.refs.repeat, 'resume', crypto.randomUUID(), {}, 409);
  const playResolved = await resolve(play.music_chat_v1);
  equal(playResolved.media_playback.operation, 'play');
  equal(playResolved.media_playback.position_seconds, undefined, 'Play again remains an explicit start from zero.');
  equal(playResolved.media_playback.history_ref, fixture.seeded.refs.native);
  const resumed = await select(full, fixture.seeded.refs.native, 'resume');
  const resumedAction = (await resolve(resumed.music_chat_v1)).media_playback;
  equal(resumedAction.operation, 'resume');
  equal(resumedAction.position_seconds, 22);
  equal(resumedAction.history_ref, fixture.seeded.refs.native);
  const resolvedPlayer = await response(player(resumedAction), 200, 'no-store');
  equal(resolvedPlayer.position_seconds, 22);
  equal(resolvedPlayer.tracks[0].id, 71);
  const ordinarySelection = await select(full, fixture.seeded.refs.ordinary, 'resume');
  const ordinaryResolved = await resolve(ordinarySelection.music_chat_v1);
  equal(ordinaryResolved.resume_ms, 12000);
  equal(ordinaryResolved.youtube_results.videos[0].video_id, 'vp300000006');
  const childSelection = await select(full, fixture.seeded.refs.playlist, 'resume');
  const childResolved = await resolve(childSelection.music_chat_v1);
  equal(childResolved.resume_ms, 15000, 'Same video, different exact child play, different validated position.');
  equal(childResolved.media_playback, undefined);
  equal(ledgers(), originalLedgers, 'Selection cards and player resolution still do not create playback receipts.');

  const target = (await fixture.seed({playlist:true, title:'HTTP driving', items:[{source:'vp3', source_id:'41'}]})).music_chat_v1.playlist;
  const listsBeforePicker = count('music_chat_playlists_v1');
  const quiet = await select(full, fixture.seeded.refs.native, 'add_to_playlist');
  equal(quiet.music_chat_v1.autoplay, undefined);
  equal(quiet.music_chat_v1.intent, 'show');
  equal(count('music_chat_playlists_v1'), listsBeforePicker, 'Opening a picker neither creates nor edits a saved playlist.');
  const addKey = 'http-history-add-' + crypto.randomUUID();
  const added = await select(full, fixture.seeded.refs.native, 'add_to_playlist', addKey, {playlist_id:target.id, playlist_revision:target.revision});
  equal(added.music_chat_v1.playlist.id, target.id);
  equal(added.music_chat_v1.items.map(item => item.source_id), ['41','71']);
  equal(added.music_chat_v1.playlist_changed, true);
  equal(added.tool_receipt.changes_applied, true);
  const addReplay = await select(full, fixture.seeded.refs.native, 'add_to_playlist', addKey, {playlist_id:target.id, playlist_revision:target.revision});
  equal(addReplay.music_chat_v1.playlist.id, target.id);
  equal(addReplay.music_chat_v1.items.map(item => item.source_id), ['41','71']);
  equal(addReplay.music_chat_v1.autoplay, undefined);
  const duplicate = await select(full, fixture.seeded.refs.native, 'add_to_playlist', crypto.randomUUID(),
    {playlist_id:target.id, playlist_revision:added.music_chat_v1.playlist.revision});
  equal(duplicate.music_chat_v1.playlist_changed, false);
  equal(duplicate.tool_receipt.changes_applied, false);
  equal(duplicate.music_chat_v1.playlist.revision, added.music_chat_v1.playlist.revision);
  equal(count('music_chat_playlists_v1'), listsBeforePicker);

  const noWrite = ledgers();
  for (const forged of [{...resumedAction, proof:'0'.repeat(64)}, {...resumedAction, position_seconds:23},
    {...resumedAction, history_ref:fixture.seeded.refs.repeat}, {...resumedAction, track_id:41}, {...resumedAction, conversation_id:42}]) {
    await response(player(forged), 409, 'no-store');
    await response(nativeStart(nativeBody(forged)), 409, null);
    await response(legacyStart(forged), 409, null);
    equal(ledgers(), noWrite, 'Forged signed identity causes no receipt or tombstone writes.');
  }
  await response(nativeStart(nativeBody(resumedAction, {position_ms:50000})), 409, null);
  await response(legacyStart(resumedAction, {position:50}), 409, null);
  equal(ledgers(), noWrite, 'An outer start position cannot replace the signed historical position.');
  await response(player(resumedAction, {user:22, auth:foreign}), 409, 'no-store');
  await response(nativeStart(nativeBody(resumedAction, {listener_user_id:22}), {user:22, auth:foreign}), 409, null);
  await response(legacyStart(resumedAction, {}, {user:22, auth:foreign}), 409, null);
  equal(ledgers(), noWrite, 'Another owner cannot reuse a signed historical start.');
  sql('UPDATE music_listening_sessions_v1 SET position_ms=28000 WHERE session_id=?', [fixture.seeded.native]);
  await response(player(resumedAction), 409, 'no-store');
  await response(nativeStart(nativeBody(resumedAction)), 409, null);
  await response(legacyStart(resumedAction), 409, null);
  sql('UPDATE music_listening_sessions_v1 SET position_ms=22000 WHERE session_id=?', [fixture.seeded.native]);
  sql('UPDATE music_recordings_v1 SET current_asset_id=? WHERE source_track_id=71', ['00000000-0000-4000-8000-000000000999']);
  await response(player(resumedAction), 409, 'no-store');
  await response(nativeStart(nativeBody(resumedAction)), 409, null);
  await response(legacyStart(resumedAction), 409, null);
  sql('UPDATE music_recordings_v1 SET current_asset_id=? WHERE source_track_id=71', ['00000000-0000-4000-8000-000000000271']);
  sql("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','playback-history','deny')");
  await response(history('read', pageFields(full)), 403);
  await response(player(resumedAction), 409, 'no-store');
  await response(nativeStart(nativeBody(resumedAction)), 409, null);
  await response(legacyStart(resumedAction), 409, null);
  sql('DELETE FROM user_agent_data_rules WHERE agent_id=7');
  equal(ledgers(), noWrite, 'Current history position, asset and policy checks happen before any start writes.');

  // A real playing event can advance slightly after the signed seek; preserve
  // that observed position while rejecting unrelated positions above.
  const measured = nativeBody(resumedAction, {position_ms:22500});
  const accepted = await response(nativeStart(measured), 200, null);
  equal(accepted.protocol, 'native_listening_v1');
  ok(Number.isSafeInteger(accepted.session_id) && accepted.session_id > 0);
  const acceptedRow = fixture.readDb('SELECT track_id,user_id,last_position_seconds,source_context FROM track_play_sessions WHERE id=?', [accepted.session_id])[0];
  equal(acceptedRow, {track_id:71, user_id:11, last_position_seconds:22.5, source_context:'agent_chat'});
  equal(count('track_play_sessions'), noWrite.track_play_sessions + 1);
  equal(count('music_listening_sessions_v1'), noWrite.music_listening_sessions_v1 + 1);
  const afterStart = ledgers();
  const retriedStart = await response(nativeStart(measured), 200, null);
  equal(retriedStart.session_id, accepted.session_id);
  equal(ledgers(), afterStart, 'Same canonical native request reuses one receipt after a lost response.');
  const legacyAccepted = await response(legacyStart(playResolved.media_playback), 200, null);
  equal(legacyAccepted.protocol, 'legacy_client_delta_v1');
  equal(count('track_play_sessions'), noWrite.track_play_sessions + 2);
  equal(count('music_listening_sessions_v1'), noWrite.music_listening_sessions_v1 + 1, 'Legacy receipt stays legacy, not invented ML2A measurement.');
  equal(count('vp3_youtube_playback_v1'), noWrite.vp3_youtube_playback_v1);
  equal(count('vp3_youtube_playlist_plays_v1'), noWrite.vp3_youtube_playlist_plays_v1);
  const ytBefore = ledgers();
  const ytPlayId = crypto.randomUUID();
  const ytReceipt = {action:'record', message_id:String(childResolved.source_message_id), video_id:'vp300000006', play_id:ytPlayId,
    capture_key:fixture.readDb('SELECT capture_key FROM vp3_youtube_history_settings_v1 WHERE owner_user_id=11')[0].capture_key,
    ...(childResolved.youtube_playlist_id ? {playlist_id:childResolved.youtube_playlist_id} : {})};
  equal((await response(youtubePost(ytReceipt))).recorded, true);
  equal((await response(youtubePost(ytReceipt))).recorded, true);
  equal(count('track_play_sessions'), ytBefore.track_play_sessions, 'YouTube playback never enters the native ledger.');
  equal(count('vp3_youtube_playback_v1') + count('vp3_youtube_playlist_plays_v1'), ytBefore.vp3_youtube_playback_v1 + ytBefore.vp3_youtube_playlist_plays_v1 + 1,
    'One visible YouTube attempt writes once through the existing source-specific receipt path.');

  sql("UPDATE tracks SET is_published=0 WHERE id=71");
  let restored = (await response(history('read', pageFields(full)))).music_history_v1;
  const draft = restored.items.find(item => item.entry_ref === fixture.seeded.refs.native);
  equal(draft.title, 'History Moonlight');equal(draft.metadata_available, true);equal(draft.play_available, false);
  sql("UPDATE tracks SET is_published=1,visibility='private',owner_user_id=22,title='SECRET REVOKED TITLE' WHERE id=71");
  restored = (await response(history('read', pageFields(full)))).music_history_v1;
  equal(restored.items.find(item => item.entry_ref === fixture.seeded.refs.native).title, 'Unavailable VP3 song');
  ok(!JSON.stringify(restored).includes('History Moonlight') && !JSON.stringify(restored).includes('SECRET REVOKED TITLE'));
  const redactedSelection = (await response(music('result', {result_id:resumed.music_chat_v1.result_id}))).music_chat_v1;
  equal(redactedSelection.items[0].available, false);
  ok(!JSON.stringify(redactedSelection).includes('History Moonlight'));
  sql("UPDATE tracks SET visibility='public',owner_user_id=11,title='History Moonlight' WHERE id=71");
  sql('UPDATE agent_connection_youtube_results_v1 SET expires_at=? WHERE message_id=?', [Math.floor(Date.now()/1000)-1, fixture.seeded.message]);
  restored = (await response(history('read', pageFields(full)))).music_history_v1;
  const expired = restored.items.find(item => item.entry_ref === fixture.seeded.refs.ordinary);
  equal(expired.title, 'Unavailable YouTube video');equal(expired.play_available, false);equal(expired.source_id, '');
  await response(music('resolve', selectionFields(ordinarySelection.music_chat_v1)), 409);
  const expiredMC1 = (await response(music('result', {result_id:ordinarySelection.music_chat_v1.result_id}))).music_chat_v1;
  equal(expiredMC1.items[0].available, false);
  ok(!JSON.stringify(expiredMC1).includes('YouTube Song 6'), 'Restored selected card cannot extend original provider metadata retention.');
  sql('UPDATE agent_connection_youtube_results_v1 SET expires_at=? WHERE message_id=?', [Math.floor(Date.now()/1000)+600, fixture.seeded.message]);
  sql('DELETE FROM vp3_youtube_playback_v1 WHERE owner_user_id=11 AND play_id=?', [fixture.seeded.ordinary]);
  restored = (await response(history('read', pageFields(full)))).music_history_v1;
  equal(restored.items.map(item => item.entry_ref), full.items.map(item => item.entry_ref), 'Cleared entries preserve original page ordinals without reranking.');
  equal(restored.items.find(item => item.entry_ref === fixture.seeded.refs.ordinary).title, 'Unavailable history entry');
  equal(restored.items.find(item => item.entry_ref === fixture.seeded.refs.ordinary).played_at, null);
  sql('DELETE FROM track_play_sessions WHERE id=?', [fixture.seeded.native]);
  const beforeRemovedStart = ledgers();
  await response(nativeStart(nativeBody(resumedAction)), 409, null);
  await response(legacyStart(resumedAction), 409, null);
  equal(ledgers(), beforeRemovedStart, 'Cleared source receipt blocks a new selected native start.');
  equal(fixture.readDb('SELECT * FROM agent_connection_youtube_usage_v1'), originalUsage, 'No acceptance case imported or searched a live provider.');
  ok(!/Fatal error|PHP Warning|Parse error/.test(fixture.logs), fixture.logs.slice(-15000));
  console.log(`Music history HTTP: ${checks} checks passed (real PHP APIs, canonical receipts, no live provider).`);
} catch (error) {
  console.error(fixture.logs.slice(-20000));
  throw error;
} finally { fixture.close(); }
