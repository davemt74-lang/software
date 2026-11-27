import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {execFileSync, spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Real media, history and playback routes; isolated login/bootstrap adapters. */
export async function musicListeningFixture({install = true} = {}) {
  const php = process.env.PHP_BINARY || 'php';
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-music-listening-'));
  let server;
  let logs = '';
  const close = () => {
    server?.kill();
    fs.rmSync(root, {recursive: true, force: true});
  };
  try {
    for (const name of [
      'api/playback.php', 'api/player-library-v76.php', 'media.php',
      'includes/functions.php', 'includes/music-catalog.php', 'includes/music-media.php',
      'includes/artist-music-v185.php', 'includes/artist-media-v182.php',
      'includes/music-workspace-resources-v330.php', 'includes/music-song-metadata-v1.php',
      'includes/music-listening-v1.php', 'includes/stem-mp3-metadata-v1.php',
      'includes/player-v76.php', 'includes/notifications.php', 'includes/notification-read-transactions-v1.php', 'includes/agent-chat-activity.php',
      'includes/recommendations.php', 'tests/music-song-metadata-v1.php',
      'tests/fixtures/music-listening-v1.php', 'tests/fixtures/stem-mp3-metadata-v1.json',
      'scripts/music-listening-v1.php',
    ]) {
      const target = path.join(root, name);
      fs.mkdirSync(path.dirname(target), {recursive: true});
      fs.copyFileSync(path.join(repository, name), target);
    }
    fs.writeFileSync(path.join(root, 'includes/bootstrap.php'), `<?php
declare(strict_types=1);
session_save_path(dirname(__DIR__));
session_start();
define('STONEFELLOW_ROOT', dirname(__DIR__));
putenv('MUSIC_LISTENING_TEST_DB=' . STONEFELLOW_ROOT . '/data.sqlite');
$config = ['site'=>['base_path'=>'', 'base_url'=>'http://127.0.0.1'], 'uploads'=>[]];
require STONEFELLOW_ROOT . '/tests/fixtures/music-listening-v1.php';
`);
    fs.writeFileSync(path.join(root, '_test-session.php'), `<?php
require __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json');
echo json_encode(['csrf_token'=>csrf_token()]);
`);
    const run = (code, ...args) => execFileSync(php, [
      '-r', 'require $argv[1];' + code, path.join(root, 'includes/bootstrap.php'), ...args,
    ], {encoding: 'utf8', cwd: repository});
    run('music_listening_test_seed(); music_song_metadata_v1_install(db());');
    if (install) run('music_listening_v1_install(db());');
    const readDb = (sql, params = []) => JSON.parse(run(
      '$q=db()->prepare($argv[2]);$q->execute(json_decode($argv[3],true,512,JSON_THROW_ON_ERROR));echo json_encode($q->fetchAll(),JSON_THROW_ON_ERROR);',
      sql, JSON.stringify(params),
    ));
    const snapshot = () => JSON.parse(run('echo json_encode(music_listening_test_snapshot(),JSON_THROW_ON_ERROR);'));
    const configFile = path.join(root, 'test-config.php');
    fs.writeFileSync(configFile, "<?php return ['db'=>['dsn'=>'sqlite:' . __DIR__ . '/data.sqlite','user'=>'','pass'=>'']];");
    const migration = (...args) => JSON.parse(execFileSync(php, [
      path.join(root, 'scripts/music-listening-v1.php'), '--config=' + configFile, ...args,
    ], {encoding: 'utf8'}));

    const socket = net.createServer();
    await new Promise((resolve, reject) => {
      socket.once('error', reject);
      socket.listen(0, '127.0.0.1', resolve);
    });
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    fs.appendFileSync(path.join(root, 'includes/bootstrap.php'), `\n$config['site']['base_url'] = 'http://127.0.0.1:${port}';\n`);
    server = spawn(php, ['-d', 'display_errors=stderr', '-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', root], {
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    server.stderr.on('data', bytes => { logs += bytes; });
    server.on('error', error => { logs += error.stack; });
    const base = `http://127.0.0.1:${port}`;
    let ready = false;
    for (let attempt = 0; attempt < 60; attempt++) {
      try {
        await fetch(base + '/_test-session.php', {headers: {'X-User': '11'}});
        ready = true;
        break;
      } catch {
        if (server.exitCode !== null) break;
        await new Promise(resolve => setTimeout(resolve, 50));
      }
    }
    assert.ok(ready, 'PHP listening fixture failed to start: ' + logs);
    const json = async response => {
      const text = await response.text();
      try { return JSON.parse(text); }
      catch { throw new Error(`Invalid JSON (${response.status}): ${text}\n${logs}`); }
    };
    const auth = async (user = 11) => {
      const response = await fetch(base + '/_test-session.php', {headers: {'X-User': String(user)}});
      const {csrf_token: csrf} = await json(response);
      const session = response.headers.get('set-cookie')?.split(';')[0];
      assert.ok(csrf && session, 'Listening fixture could not initialize a PHP session.');
      const listener = crypto.randomBytes(32).toString('hex');
      const cookie = `${session}; sf_listener=${listener}`;
      return {user, csrf, cookie, listener, headers: {'X-User': String(user), Cookie: cookie, Accept: 'application/json'}};
    };
    const initial = await auth();
    const headers = (user = 11, credentials = initial) => ({...credentials.headers, 'X-User': String(user)});
    const get = (route, {user = 11, auth: credentials = initial, headers: extra = {}} = {}) => fetch(base + route, {
      headers: {...headers(user, credentials), ...extra},
    });
    const postJson = (route, fields, {user = 11, auth: credentials = initial, token = credentials.csrf, headers: extra = {}} = {}) => fetch(base + route, {
      method: 'POST', headers: {...headers(user, credentials), 'Content-Type': 'application/json', ...extra},
      body: JSON.stringify({csrf_token: token, ...fields}),
    });
    const identity = readDb('SELECT recording_id,work_id,current_asset_id AS asset_id FROM music_recordings_v1 WHERE catalog_track_id=10')[0];
    return {
      root, base, php, run, readDb, snapshot, migration, auth, headers, get, postJson, json, close,
      csrf: initial.csrf, cookie: initial.cookie, listener: initial.listener,
      identity, trackId: 42, catalogId: 10, audioUrl: '/media.php?track=42&type=audio',
      get logs() { return logs; },
    };
  } catch (error) {
    close();
    throw error;
  }
}

async function runTests() {
  const fixture = await musicListeningFixture({install: false});
  const route = '/api/playback.php';
  const checked = async (response, status, message) => {
    const data = await fixture.json(response);
    assert.equal(response.status, status, `${message}: ${JSON.stringify(data)}\n${fixture.logs}`);
    return data;
  };
  const start = (changes = {}) => ({
    protocol: 'native_listening_v1', action: 'start', listener_user_id: 11,
    track_id: 42, play_id: crypto.randomUUID(), position_ms: 0, playback_rate: 1,
    source: 'player', source_url: fixture.base + '/chat.php?private=do-not-store#player', ...changes,
  });
  const event = (input, receipt, changes = {}) => ({
    protocol: input.protocol, listener_user_id: input.listener_user_id, track_id: input.track_id,
    play_id: input.play_id, session_token: receipt.session_token,
    event_id: crypto.randomUUID(), seq: 1, cumulative_played_ms: 0, elapsed_ms: 0,
    position_ms: 0, playback_rate: 1, action: 'resume', state: 'playing', ...changes,
  });
  try {
    const beforeUpgrade = fixture.snapshot();
    await checked(await fixture.postJson(route, start()), 503, 'Unupgraded native listening must not silently downgrade');
    const beforeReportBytes = fs.readFileSync(path.join(fixture.root, 'data.sqlite'));
    let missingReport;
    try {
      fixture.migration();
      assert.fail('Unupgraded CLI report should use exit code2');
    } catch (error) {
      assert.equal(error.status, 2, 'Unupgraded report failed unexpectedly');
      missingReport = JSON.parse(error.stdout);
    }
    assert.equal(missingReport.ready, false);
    assert.equal(missingReport.counts.legacy_history_rows, 1);
    assert.deepEqual(fixture.snapshot(), beforeUpgrade, 'Unupgraded request/report changed existing data');
    assert.deepEqual(fs.readFileSync(path.join(fixture.root, 'data.sqlite')), beforeReportBytes, 'Unupgraded report changed SQLite bytes');
    const upgrade = fixture.migration('--migrate');
    assert.equal(upgrade.ready, true);
    assert.equal(upgrade.counts.sessions, 0, 'Upgrade fabricated measured historical listens');
    assert.equal(upgrade.counts.legacy_unversioned_rows, 1);
    const upgraded = fixture.snapshot();
    for (const [table, rows] of Object.entries(beforeUpgrade)) assert.deepEqual(upgraded[table], rows, 'Upgrade changed ' + table);
    fixture.migration('--migrate');
    assert.deepEqual(fixture.snapshot(), upgraded, 'Immediate upgrade rerun changed existing data');
    const original = fixture.snapshot();
    await checked(await fixture.get(route), 405, 'GET must not start listening');
    await checked(await fixture.postJson(route, start(), {token: 'wrong'}), 419, 'CSRF failure');
    await checked(await fixture.postJson(route, start({track_id: 84})), 404, 'Cross-workspace private draft');
    await checked(await fixture.postJson(route, start({listener_user_id: 14})), 403, 'Spoofed account');
    await checked(await fixture.postJson(route, start({protocol: 'native_listening_typo'})), 422, 'Unknown protocol cannot downgrade into legacy accumulator');
    const missingProtocol = start();
    delete missingProtocol.protocol;
    await checked(await fixture.postJson(route, missingProtocol), 422, 'Missing protocol cannot downgrade modern counters');
    for (const changes of [
      {play_id: "' OR 1=1 --"}, {position_ms: -1}, {position_ms: 1.5}, {position_ms: '0'},
      {playback_rate: 0}, {playback_rate: 99}, {track_id: "42 OR 1=1"},
    ]) await checked(await fixture.postJson(route, start(changes)), 422, 'Malformed start must not write');
    assert.deepEqual(fixture.snapshot(), original, 'Rejected HTTP requests changed catalog/history');

    // A lost first response also loses Set-Cookie. Initialization must establish
    // the listener before allocating any attempt that could be duplicated.
    const noListenerCookie = fixture.cookie.split(';').map(value => value.trim()).filter(value => !value.startsWith('sf_listener=')).join('; ');
    const frozenFirstStart = start();
    let initialize = await fixture.postJson(route, frozenFirstStart, {headers: {Cookie: noListenerCookie}});
    let initialized = await checked(initialize, 428, 'First listener initialization');
    assert.equal(initialized.code, 'listener_initialization_required');
    assert.equal(initialized.retryable, true);
    initialize = await fixture.postJson(route, frozenFirstStart, {headers: {Cookie: noListenerCookie}});
    const listenerCookie = initialize.headers.get('set-cookie')?.match(/(?:^|,\s*)(sf_listener=[a-f0-9]{64})(?:;|$)/)?.[1];
    initialized = await checked(initialize, 428, 'Lost listener initialization response');
    assert.equal(initialized.code, 'listener_initialization_required');
    assert.deepEqual(fixture.snapshot(), original, 'Missing-cookie retries allocated duplicate listening attempts');
    assert.ok(listenerCookie, 'Initialization did not establish the listener cookie');
    const establishedHeaders = {Cookie: `${noListenerCookie}; ${listenerCookie}`};
    const initializedStart = await checked(await fixture.postJson(route, frozenFirstStart, {headers: establishedHeaders}), 200, 'Established listener start');
    const afterInitialization = fixture.snapshot();
    assert.deepEqual(await checked(await fixture.postJson(route, frozenFirstStart, {headers: establishedHeaders}), 200, 'Frozen start after initialization'), initializedStart);
    assert.deepEqual(fixture.snapshot(), afterInitialization, 'Established first start was allocated twice');
    await checked(await fixture.postJson(route, event(frozenFirstStart, initializedStart), {headers: {Cookie: noListenerCookie}}), 403, 'Lost listener cookie cannot continue an allocated session');

    const input = start();
    const receipt = await checked(await fixture.postJson(route, input), 200, 'Start');
    assert.equal(receipt.accepted_played_ms, 0, 'Autoplay attempt received listening credit');
    assert.equal(receipt.duration_ms, null, 'Unprobed WAV/catalog duration became trusted');
    assert.equal(receipt.source.url, fixture.base + '/chat.php', 'Configured source origin/route was not safely recorded');
    assert.equal(receipt.source.provenance, 'client_claimed_same_origin', 'Client page claim became independent source proof');
    const afterStart = fixture.snapshot();
    assert.deepEqual(await checked(await fixture.postJson(route, input), 200, 'Duplicate start'), receipt, 'Start retry did not return original receipt');
    assert.deepEqual(fixture.snapshot(), afterStart, 'Start replay duplicated history or sidecar rows');
    await checked(await fixture.postJson(route, {...input, position_ms: 1}), 409, 'Conflicting start');

    const first = event(input, receipt);
    const firstReceipt = await checked(await fixture.postJson(route, first), 200, 'Playing boundary');
    const afterFirst = fixture.snapshot();
    await checked(await fixture.postJson(route, {action: 'heartbeat', track_id: 42, session_token: receipt.session_token, position: 20, duration: 120, delta: 20}), 409, 'Legacy payload cannot mutate a v1 session');
    assert.deepEqual(await checked(await fixture.postJson(route, first), 200, 'Exact event retry'), firstReceipt);
    assert.deepEqual(fixture.snapshot(), afterFirst, 'Event replay wrote a second event or credit');
    await checked(await fixture.postJson(route, {...first, position_ms: 10}), 409, 'Changed event payload');
    await checked(await fixture.postJson(route, {...first, event_id: crypto.randomUUID()}), 409, 'Same sequence with another event identity');
    const gap = event(input, receipt, {seq: 3, elapsed_ms: 1, action: 'heartbeat'});
    await checked(await fixture.postJson(route, gap), 409, 'Out-of-order sequence');
    assert.deepEqual(fixture.snapshot(), afterFirst, 'Rejected ordering modified accepted state');

    const otherCookie = await fixture.auth(11);
    await checked(await fixture.postJson(route, first, {auth: otherCookie}), 404, 'Same user with another listener cookie');
    await checked(await fixture.postJson(route, first, {user: 14}), 403, 'Account changed while request was queued');
    await checked(await fixture.postJson(route, {...first, listener_user_id: 14}, {user: 14}), 404, 'Different account cannot consume another account receipt');

    for (const changes of [
      {seq: 0}, {seq: 1.5}, {seq: '2'}, {cumulative_played_ms: -1}, {cumulative_played_ms: '10'},
      {elapsed_ms: -1}, {elapsed_ms: 1.1}, {playback_rate: '1'}, {playback_rate: 0},
      {event_id: 'bad-id'}, {state: 'pretend_playing'},
    ]) await checked(await fixture.postJson(route, event(input, receipt, {seq: 2, ...changes})), 422, 'Malformed event');
    assert.deepEqual(fixture.snapshot(), afterFirst, 'Malformed/foreign event changed stored rows');

    await new Promise(resolve => setTimeout(resolve, 150));
    const heartbeat = event(input, receipt, {seq: 2, elapsed_ms: 150, cumulative_played_ms: 100, position_ms: 100, action: 'heartbeat'});
    const heartbeatReceipt = await checked(await fixture.postJson(route, heartbeat), 200, 'Measured heartbeat');
    assert.ok(heartbeatReceipt.accepted_played_ms > 0 && heartbeatReceipt.accepted_played_ms <= 100, 'HTTP measured interval escaped its bound');
    const measured = fixture.snapshot();
    assert.deepEqual(await checked(await fixture.postJson(route, heartbeat), 200, 'Lost-ACK retry'), heartbeatReceipt);
    assert.deepEqual(fixture.snapshot(), measured, 'Lost acknowledgement led to double credit');

    // The controller must return a retryable error only after the real native
    // counters/event and sidecar changes have rolled back with a failed receipt.
    const next = event(input, receipt, {seq: 3, elapsed_ms: 150, cumulative_played_ms: 100, position_ms: 100, action: 'pause', state: 'paused'});
    fixture.run("db()->exec(\"CREATE TRIGGER fixture_receipt_failure BEFORE INSERT ON music_listening_receipts_v1 BEGIN SELECT RAISE(ABORT,'fixture failure'); END\");");
    try {
      const failure = await checked(await fixture.postJson(route, next), 503, 'Receipt insert failure');
      assert.equal(failure.retryable, true);
      assert.deepEqual(fixture.snapshot(), measured, 'HTTP receipt failure left partial native counters/events');
    } finally { fixture.run("db()->exec('DROP TRIGGER fixture_receipt_failure');"); }
    const pausedReceipt = await checked(await fixture.postJson(route, next), 200, 'Retry after rolled-back receipt');
    assert.equal(pausedReceipt.accepted_seq, 3);
    const history = await checked(await fixture.postJson('/api/player-library-v76.php', {action: 'recent_history'}), 200, 'Personal history');
    assert.ok(history.tracks.some(track => Number(track.id) === 42), 'Native session was disconnected from Player history');
    const notices = fixture.readDb('SELECT * FROM notifications WHERE source_type=? AND source_id=?', ['track_play_session', receipt.session_id]);
    assert.equal(notices.length, 1, 'Measured retry did not keep one existing history notification');
    const foreignHistory = await checked(await fixture.postJson('/api/player-library-v76.php', {action: 'recent_history'}, {user: 14}), 200, 'Foreign personal history');
    assert.deepEqual(foreignHistory.tracks, [], 'Another account saw owner listening history');

    const mediaBefore = fixture.snapshot();
    const media = await fixture.get(fixture.audioUrl, {headers: {Range: 'bytes=0-43'}});
    assert.equal(media.status, 206, 'Native media route did not serve the real WAV range');
    assert.equal((await media.arrayBuffer()).byteLength, 44);
    assert.deepEqual(fixture.snapshot(), mediaBefore, 'Serving bytes wrote a second listening event');
    assert.equal((await fixture.get('/media.php?track=84&type=audio')).status, 404, 'Foreign private audio leaked');
    const stored = JSON.stringify(fixture.snapshot());
    assert.ok(!stored.includes('do-not-store'), 'Raw playback URL query leaked into stored rows');

    const end = event(input, receipt, {seq: 4, elapsed_ms: 150, cumulative_played_ms: 100, position_ms: 100, action: 'stop', state: 'stopped'});
    const closed = await checked(await fixture.postJson(route, end), 200, 'Stop');
    assert.deepEqual(await checked(await fixture.postJson(route, end), 200, 'Closed exact retry'), closed);
    await checked(await fixture.postJson(route, event(input, receipt, {seq: 5, action: 'heartbeat', elapsed_ms: 151, cumulative_played_ms: 100})), 410, 'New event after close');
    assert.deepEqual(await checked(await fixture.postJson(route, first), 200, 'Old receipt after later events'), firstReceipt, 'Historical receipt changed to current session state');

    const anonymous = await fixture.auth(0);
    const anonInput = start({listener_user_id: 0});
    const anonReceipt = await checked(await fixture.postJson(route, anonInput, {user: 0, auth: anonymous}), 200, 'Anonymous public start');
    assert.equal(anonReceipt.user_id, 0);
    await checked(await fixture.postJson(route, event(anonInput, anonReceipt), {user: 11, auth: anonymous}), 403, 'Sign-in cannot inherit anonymous queued request');

    const reportBefore = fixture.snapshot();
    const bytesBefore = fs.readFileSync(path.join(fixture.root, 'data.sqlite'));
    const report = fixture.migration();
    assert.equal(report.ready, true);
    assert.deepEqual(fixture.snapshot(), reportBefore, 'Default listening CLI report wrote database rows');
    assert.deepEqual(fs.readFileSync(path.join(fixture.root, 'data.sqlite')), bytesBefore, 'Default report changed SQLite bytes');
    fixture.migration('--migrate');
    assert.deepEqual(fixture.snapshot(), reportBefore, 'Rerunnable listening install rewrote existing events/history');
    console.log('MUSIC_LISTENING_API_V1=PASS real HTTP/session/CSRF, exact receipts and lost-ACK replay, strict ordering, cookie/account authority, malformed input, native history/activity, private media, read-only report and rerunnable upgrade');
  } finally {
    fixture.close();
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  runTests().catch(error => { console.error(error); process.exitCode = 1; });
}
