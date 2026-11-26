import assert from 'node:assert/strict';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {spawn, execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Real PHP routes and multipart uploads; only login/bootstrap are fixture adapters. */
export async function musicSongFixture() {
  const php = process.env.PHP_BINARY || 'php';
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-song-metadata-'));
  let server;
  let logs = '';
  const close = () => {
    server?.kill();
    fs.rmSync(root, {recursive: true, force: true});
  };
  try {
    for (const name of [
      'api/chat-create-v76.php', 'api/music-song-metadata-v1.php',
      'includes/functions.php', 'includes/music-catalog.php',
      'includes/artist-music-v185.php', 'includes/artist-media-v182.php',
      'includes/music-workspace-resources-v330.php',
      'includes/music-song-metadata-v1.php', 'tests/music-song-metadata-v1.php',
      'scripts/music-song-metadata-v1.php',
      'api/music-file-repair.php', 'includes/music-file-repair.php',
      'includes/music-media.php', 'includes/stem-import-media.php',
      'stem-media-v34.php', 'includes/stem-mp3-metadata-v1.php',
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
define('MUSIC_SONG_TEST_FIXTURE_ONLY', true);
putenv('MUSIC_SONG_TEST_DB=' . STONEFELLOW_ROOT . '/data.sqlite');
$config = ['base_path'=>'', 'uploads'=>['max_artist_audio_bytes'=>1048576, 'max_artist_image_bytes'=>1048576]];
require STONEFELLOW_ROOT . '/tests/music-song-metadata-v1.php';
require_once __DIR__ . '/artist-media-v182.php';
require_once __DIR__ . '/music-song-metadata-v1.php';
if (($_SERVER['HTTP_X_TEST_OUTER_TRANSACTION'] ?? '') === '1') {
    db()->beginTransaction();
    register_shutdown_function(static function(): void { if (db()->inTransaction()) db()->rollBack(); });
}
`);
    fs.writeFileSync(path.join(root, '_test-session.php'), `<?php
require __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json');
echo json_encode(['csrf_token'=>csrf_token()]);
`);

    const run = code => execFileSync(php, [
      '-r', 'require $argv[1];' + code, path.join(root, 'includes/bootstrap.php'),
    ], {encoding: 'utf8', cwd: repository});
    run('music_song_test_seed();');
    const configFile = path.join(root, 'test-config.php');
    fs.writeFileSync(configFile, `<?php return ['db'=>['dsn'=>'sqlite:' . __DIR__ . '/data.sqlite', 'user'=>'', 'pass'=>'']];`);
    const migration = (...args) => JSON.parse(execFileSync(php, [
      path.join(root, 'scripts/music-song-metadata-v1.php'), '--config=' + configFile, ...args,
    ], {encoding: 'utf8'}));

    const wav = Buffer.alloc(44 + 16000);
    wav.write('RIFF');
    wav.writeUInt32LE(wav.length - 8, 4);
    wav.write('WAVEfmt ', 8);
    wav.writeUInt32LE(16, 16);
    wav.writeUInt16LE(1, 20);
    wav.writeUInt16LE(1, 22);
    wav.writeUInt32LE(8000, 24);
    wav.writeUInt32LE(16000, 28);
    wav.writeUInt16LE(2, 32);
    wav.writeUInt16LE(16, 34);
    wav.write('data', 36);
    wav.writeUInt32LE(wav.length - 44, 40);
    fs.mkdirSync(path.join(root, 'uploads/artist-music/1'), {recursive: true});
    fs.writeFileSync(path.join(root, 'uploads/artist-music/1/legacy.wav'), wav);

    const socket = net.createServer();
    await new Promise((resolve, reject) => {
      socket.once('error', reject);
      socket.listen(0, '127.0.0.1', resolve);
    });
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    server = spawn(php, [
      '-d', 'display_errors=stderr', '-d', 'opcache.enable_cli=0',
      '-S', `127.0.0.1:${port}`, '-t', root,
    ], {stdio: ['ignore', 'pipe', 'pipe']});
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
    assert.ok(ready, 'PHP fixture server did not start: ' + logs);
    const response = await fetch(base + '/_test-session.php', {headers: {'X-User': '11'}});
    const {csrf_token: csrf} = await response.json();
    const cookie = response.headers.get('set-cookie')?.split(';')[0];
    assert.ok(csrf && cookie, 'Fixture session/CSRF initialization failed: ' + logs);

    const requestHeaders = (user = 11) => ({'X-User': String(user), Cookie: cookie, Accept: 'application/json'});
    const get = (route, {user = 11} = {}) => fetch(base + route, {headers: requestHeaders(user)});
    const post = (route, fields, {user = 11, token = csrf, bytes, filename = 'recording.wav', cover, outerTransaction = false} = {}) => {
      const body = new FormData();
      body.set('csrf_token', token);
      const append = (name, value) => {
        if (value === null || value === undefined) return;
        if (typeof value === 'object') {
          for (const [key, entry] of Object.entries(value)) append(`${name}[${key}]`, entry);
        } else {
          body.set(name, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
        }
      };
      for (const [name, value] of Object.entries(fields)) {
        append(name, value);
      }
      if (bytes) body.set('audio_file', new Blob([bytes], {type: 'audio/wav'}), filename);
      if (cover) body.set('cover_file', new Blob([cover], {type: 'image/png'}), 'cover.png');
      const headers = requestHeaders(user);
      if (outerTransaction) headers['X-Test-Outer-Transaction'] = '1';
      return fetch(base + route, {method: 'POST', headers, body});
    };
    const postJson = (route, fields, {user = 11, token = csrf} = {}) => fetch(base + route, {
      method: 'POST', headers: {...requestHeaders(user), 'Content-Type': 'application/json'},
      body: JSON.stringify({csrf_token: token, ...fields}),
    });
    const json = async response => {
      const text = await response.text();
      try { return JSON.parse(text); }
      catch { throw new Error(`Invalid JSON (${response.status}): ${text}\n${logs}`); }
    };
    const snapshot = () => JSON.parse(run(`
$result=[];
foreach(db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $result[$table]=db()->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll();
}
echo json_encode($result, JSON_THROW_ON_ERROR);`));
    const audioFiles = () => fs.readdirSync(path.join(root, 'uploads/artist-music/1')).sort();
    const storedFiles = () => {
      const files = [];
      const walk = directory => {
        for (const entry of fs.readdirSync(directory, {withFileTypes: true})) {
          const filename = path.join(directory, entry.name);
          if (entry.isDirectory()) walk(filename);
          else files.push(path.relative(root, filename));
        }
      };
      walk(path.join(root, 'uploads'));
      return files.sort();
    };
    return {root, base, run, migration, wav, csrf, cookie, get, post, postJson, json, snapshot, audioFiles, storedFiles, close, get logs() { return logs; }};
  } catch (error) {
    close();
    throw error;
  }
}

async function runTests() {
  const fixture = await musicSongFixture();
  const api = '/api/music-song-metadata-v1.php';
  const chat = '/api/chat-create-v76.php';
  try {
    const checked = async (response, status, message) => {
      const data = await fixture.json(response);
      assert.equal(response.status, status, `${message}: ${JSON.stringify(data)}\n${fixture.logs}`);
      return data;
    };
    await checked(await fixture.get(api + '?workspace_id=1&id=10'), 503, 'Missing upgrade was not reported');
    const beforeReport = fixture.snapshot();
    const databaseBeforeReport = fs.readFileSync(path.join(fixture.root, 'data.sqlite'));
    const report = fixture.migration();
    assert.equal(report.mode, 'report');
    assert.equal(report.schema_ready, false);
    assert.equal(report.migratable, 2);
    assert.deepEqual(fixture.snapshot(), beforeReport, 'Default CLI report wrote schema or mapping rows');
    assert.deepEqual(fs.readFileSync(path.join(fixture.root, 'data.sqlite')), databaseBeforeReport, 'Read-only CLI report changed the database file');
    const migrated = fixture.migration('--migrate');
    assert.equal(migrated.migrated, 2, 'Explicit CLI migration did not import the two unambiguous legacy recordings');
    const metadata = JSON.parse(fixture.run('echo json_encode(music_song_test_metadata(), JSON_THROW_ON_ERROR);'));
    const originalReferences = JSON.parse(fixture.run('echo json_encode(music_song_test_legacy_references(), JSON_THROW_ON_ERROR);'));
    const initial = await checked(await fixture.get(api + '?workspace_id=1&id=10'), 200, 'Owner could not read legacy metadata');
    assert.ok(initial.recording.recording_id && initial.revision, 'GET must provide stable identity and an edit revision');
    assert.equal(initial.catalog.source_track_id, 42);

    const beforeDenied = fixture.snapshot();
    await checked(await fixture.get(api + '?workspace_id=1&id=10', {user: 0}), 401, 'Anonymous read');
    await checked(await fixture.get(api + '?workspace_id=1&id=10', {user: 14}), 403, 'Cross-workspace private read');
    await checked(await fixture.get(api + '?workspace_id=1&id=20'), 404, 'Cross-workspace catalog ID');
    await checked(await fixture.post(api, {workspace_id: 1, title: 'CSRF must fail'}, {token: 'wrong'}), 419, 'Bad CSRF');
    await checked(await fixture.post(chat, {type: 'track', workspace_id: 1, title: 'Anonymous'}, {user: 0}), 401, 'Anonymous create');
    await checked(await fixture.post(chat, {type: 'track', workspace_id: 1, title: 'Foreign'}, {user: 14}), 403, 'Foreign create');
    await checked(await fixture.post(chat, {type: 'track', workspace_id: 1, title: 'Producer'}, {user: 13}), 403, 'Producer catalog mutation');
    await checked(await fixture.get(chat), 405, 'Chat endpoint HTTP method');
    assert.deepEqual(fixture.snapshot(), beforeDenied, 'Denied requests changed stored rows');

    const create = {
      type: 'track', workspace_id: 1, title: 'Main Add Track fixture', album_id: 1,
      visibility: 'admin', is_published: 0, duration: '0:01',
      credits: 'Keep the existing free-text credits', mood: 'Calm', energy: 'low', keywords: 'fixture',
      metadata_revision: 0, music_metadata: metadata, request_key: 'api-create-main-track-0001',
    };
    const cover = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jx1kAAAAASUVORK5CYII=', 'base64');
    const created = await checked(await fixture.post(chat, create, {bytes: fixture.wav, cover}), 200, 'Main Add Track shared save');
    assert.equal(created.ok, true);
    assert.ok(created.catalog_track_id > 0 && created.id > 0 && created.recording_id);
    assert.match(created.open_url, /(?:music-library|artist-music|music-workspace)\.php/);
    let saved = await checked(await fixture.get(api + `?workspace_id=1&id=${created.catalog_track_id}`), 200, 'Workspace open after Add Track');
    assert.equal(saved.recording.recording_id, created.recording_id, 'Main and workspace resolved different recordings');
    assert.equal(saved.catalog.source_track_id, created.id, 'Main and workspace resolved different Player IDs');
    assert.equal(saved.recording.metadata.rights_notes, metadata.rights_notes);
    assert.equal(saved.recording.metadata.contact_email, metadata.contact_email);
    assert.doesNotMatch(JSON.stringify(saved.recording.public), /PRIVATE-AGREEMENT|private@example\.test|00123456789|\/uploads\//, 'Public preview leaked private data');
    assert.ok(saved.catalog.cover_photo_id > 2, 'Cover upload did not become owned catalog art');
    assert.ok(fs.existsSync(path.join(fixture.root, saved.catalog.audio_path)), 'Actual multipart audio was not stored');
    const firstAsset = saved.recording.asset_id;
    const firstPath = saved.catalog.audio_path;
    const afterCreate = fixture.snapshot();
    const filesAfterCreate = fixture.storedFiles();
    const replay = await checked(await fixture.post(chat, create, {bytes: fixture.wav, cover}), 200, 'Completed create retry');
    assert.equal(replay.id, created.id);
    assert.equal(replay.catalog_track_id, created.catalog_track_id);
    assert.equal(replay.recording_id, created.recording_id);
    assert.deepEqual(fixture.snapshot(), afterCreate, 'Retry created duplicate metadata, art, backing or receipts');
    assert.deepEqual(fixture.storedFiles(), filesAfterCreate, 'Retry left orphan audio/art');
    await checked(await fixture.post(chat, {...create, title: 'Conflicting retry'}, {bytes: fixture.wav, cover}), 409, 'Request-key content conflict');
    assert.deepEqual(fixture.snapshot(), afterCreate, 'Conflicting request key partially saved');
    assert.deepEqual(fixture.storedFiles(), filesAfterCreate, 'Conflicting retry left orphan audio/art');

    const editFields = (state, changes = {}) => ({
      workspace_id: 1, id: state.catalog.id, action: 'save', title: state.catalog.title,
      album_id: state.catalog.album_id || 0, description: state.catalog.description,
      genre: state.catalog.genre, duration_seconds: state.catalog.duration_seconds || 0,
      track_number: state.catalog.track_number || 0, cover_photo_id: state.catalog.cover_photo_id || 0,
      visibility: state.catalog.visibility, is_published: state.catalog.is_published,
      revision: state.revision, metadata_revision: state.recording.revision,
      music_metadata: state.recording.metadata, ...changes,
    });
    const edit = editFields(saved, {title: 'Edited from Music Workspace'});
    const beforeOuterUpload = fixture.snapshot();
    const filesBeforeOuterUpload = fixture.storedFiles();
    await checked(await fixture.post(api, edit, {bytes: fixture.wav, cover, outerTransaction: true}), 409, 'Upload under caller-owned transaction');
    assert.deepEqual(fixture.snapshot(), beforeOuterUpload, 'Nested upload changed database rows before caller rollback');
    assert.deepEqual(fixture.storedFiles(), filesBeforeOuterUpload, 'Nested upload left orphan audio/art after caller rollback');
    const repairRevision = fixture.run(`require_once STONEFELLOW_ROOT . '/includes/music-file-repair.php'; echo music_file_repair_revision(music_file_repair_target(db(),1,current_user(),'catalog',${created.catalog_track_id}));`);
    await checked(await fixture.post('/api/music-file-repair.php', {
      workspace_id: 1, kind: 'catalog', id: created.catalog_track_id, revision: repairRevision,
    }, {bytes: fixture.wav, outerTransaction: true}), 409, 'Audio repair under caller-owned transaction');
    assert.deepEqual(fixture.snapshot(), beforeOuterUpload, 'Nested repair changed database rows before caller rollback');
    assert.deepEqual(fixture.storedFiles(), filesBeforeOuterUpload, 'Nested repair left orphan audio after caller rollback');
    const updated = await checked(await fixture.post(api, edit), 200, 'Workspace canonical edit');
    assert.equal(updated.catalog_track_id, created.catalog_track_id);
    assert.equal(updated.player_track_id, created.id);
    assert.equal(updated.recording.recording_id, created.recording_id);
    assert.equal(updated.recording.asset_id, firstAsset, 'Metadata-only edit replaced audio version');
    const afterEdit = fixture.snapshot();
    await checked(await fixture.post(api, edit), 409, 'Stale editor revision');
    assert.deepEqual(fixture.snapshot(), afterEdit, 'Stale edit mutated state');
    saved = await checked(await fixture.get(api + `?workspace_id=1&id=${created.catalog_track_id}`), 200, 'Reload current edit');

    // A legacy editor that omits structured fields cannot erase private metadata.
    const legacyEdit = editFields(saved, {title: 'Metadata survives old fields'});
    delete legacyEdit.music_metadata;
    const retained = await checked(await fixture.postJson(api, legacyEdit), 200, 'JSON field-only update');
    assert.equal(retained.recording.metadata.rights_notes, metadata.rights_notes);
    assert.equal(retained.recording.metadata.contact_email, metadata.contact_email);
    saved = await checked(await fixture.get(api + `?workspace_id=1&id=${created.catalog_track_id}`), 200, 'Reload before failure cases');
    const beforeFailures = fixture.snapshot();
    const filesBeforeFailures = fixture.storedFiles();
    await checked(await fixture.post(api, editFields(saved, {album_id: 2}), {bytes: fixture.wav}), 422, 'Foreign album during upload');
    await checked(await fixture.post(api, editFields(saved, {cover_photo_id: 2})), 422, 'Foreign artwork selection');
    await checked(await fixture.post(api, editFields(saved), {bytes: Buffer.from('<html>not audio</html>')}), 422, 'Invalid upload content');
    assert.deepEqual(fixture.snapshot(), beforeFailures, 'Validation failure left related rows');
    assert.deepEqual(fixture.storedFiles(), filesBeforeFailures, 'Validation failure left orphan audio/art');

    fixture.run(`db()->exec("CREATE TRIGGER fail_song_revision BEFORE INSERT ON music_metadata_revisions_v1 BEGIN SELECT RAISE(ABORT,'forced metadata failure'); END;");`);
    await checked(await fixture.post(api, editFields(saved, {title: 'Rollback everything'}), {bytes: fixture.wav, cover}), 500, 'Related-record failure');
    fixture.run("db()->exec('DROP TRIGGER fail_song_revision');");
    assert.deepEqual(fixture.snapshot(), beforeFailures, 'Metadata transaction failure left partial catalog/backing/asset changes');
    assert.deepEqual(fixture.storedFiles(), filesBeforeFailures, 'Failed transaction leaked new audio/art');
    assert.ok(fs.existsSync(path.join(fixture.root, firstPath)), 'Failed replacement removed previous audio');

    // A replacement creates a new asset while preserving the prior immutable source.
    const replacement = Buffer.from(fixture.wav);
    replacement[replacement.length - 1] = 1;
    const replaced = await checked(await fixture.post(api, editFields(saved), {bytes: replacement}), 200, 'Audio replacement');
    assert.notEqual(replaced.recording.asset_id, firstAsset);
    assert.equal(replaced.recording.recording_id, created.recording_id);
    assert.ok(fs.existsSync(path.join(fixture.root, firstPath)), 'Successful replacement destroyed previous asset bytes');
    saved = await checked(await fixture.get(api + `?workspace_id=1&id=${created.catalog_track_id}`), 200, 'Reload replacement');
    const replacedPath = saved.catalog.audio_path;
    const archived = await checked(await fixture.post(api, editFields(saved, {action: 'delete'})), 200, 'Archive through shared API');
    assert.ok(archived.recording.archived);
    assert.equal(archived.recording.recording_id, created.recording_id);
    assert.ok(fs.existsSync(path.join(fixture.root, firstPath)) && fs.existsSync(path.join(fixture.root, replacedPath)), 'Archive removed retained audio versions');
    saved = await checked(await fixture.get(api + `?workspace_id=1&id=${created.catalog_track_id}`), 200, 'Read archived song as owner');
    const restored = await checked(await fixture.post(api, editFields(saved, {action: 'restore'})), 200, 'Restore through shared API');
    assert.equal(restored.recording.archived, false);
    assert.equal(restored.recording.recording_id, created.recording_id);
    assert.equal(restored.recording.asset_id, replaced.recording.asset_id);
    saved = await checked(await fixture.get(api + `?workspace_id=1&id=${created.catalog_track_id}`), 200, 'Read restored draft');
    assert.equal(saved.catalog.is_published, 0);

    const draft = await checked(await fixture.post(chat, {
      type: 'track', workspace_id: 1, title: 'Draft without audio', visibility: 'admin',
      metadata_revision: 0, request_key: 'api-create-audio-free-draft-0002',
      music_metadata: JSON.parse(fixture.run("echo json_encode(music_song_metadata_v1_defaults(['title'=>'Draft without audio']), JSON_THROW_ON_ERROR);")),
    }), 200, 'Audio-free draft');
    const draftRead = await checked(await fixture.get(api + `?workspace_id=1&id=${draft.catalog_track_id}`), 200, 'Open audio-free draft');
    assert.equal(draftRead.recording.asset_id, null);
    assert.equal(draftRead.recording.readiness.playback.ready, false);
    await checked(await fixture.post(api, editFields(draftRead, {is_published: 1})), 422, 'Publish without audio');

    await checked(await fixture.get(api + `?workspace_id=1&id=${created.catalog_track_id}`, {user: 12}), 200, 'Current manager read');
    fixture.run('db()->exec("DELETE FROM artist_team_members WHERE member_user_id=12");');
    await checked(await fixture.get(api + `?workspace_id=1&id=${created.catalog_track_id}`, {user: 12}), 403, 'Revoked manager read');
    await checked(await fixture.post(api, editFields(saved), {user: 12}), 403, 'Revoked manager mutation');
    assert.deepEqual(JSON.parse(fixture.run('echo json_encode(music_song_test_legacy_references(), JSON_THROW_ON_ERROR);')), originalReferences, 'New create/edit journey changed existing personal/production references');
    console.log('MUSIC_SONG_METADATA_API_V1=PASS real Add Track/workspace routes, multipart audio/art, shared identity, legacy/private preservation, CSRF/authority/revocation, stale/retry conflicts, failed-upload cleanup, immutable replacement and archive/restore');
  } finally {
    fixture.close();
  }
}

if (path.resolve(process.argv[1] || '') === fileURLToPath(import.meta.url)) await runTests();
