import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {execFileSync, spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const protocol = 'vp3_music_provider_v1';
export const artistOrigin = 'https://artist.example.test';
export const providerOrigin = 'https://provider.example.test';
export const providerRoute = '/api/music-provider-v1.php';
export const manageRoute = '/api/music-provider-manage-v1.php';

/**
 * Production PHP controllers, provider services, catalog authority and SQLite.
 * Only authentication, unrelated navigation chrome and legacy bootstrap are
 * isolated adapters. No test endpoint changes provider authorization or origin
 * verification. Verified sites are prepared by an injected CLI transport that
 * exercises the same production challenge comparison as the real HTTP fetch.
 */
export async function musicProviderFixture({install = true} = {}) {
  const php = process.env.PHP_BINARY || 'php';
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-music-provider-'));
  let server;
  let logs = '';
  const close = () => {
    server?.kill();
    fs.rmSync(root, {recursive: true, force: true});
  };
  try {
    fs.cpSync(path.join(repository, 'includes'), path.join(root, 'includes'), {recursive: true});
    for (const directory of ['api', 'scripts', 'assets/js', 'assets/css']) {
      fs.mkdirSync(path.join(root, directory), {recursive: true});
      for (const name of fs.readdirSync(path.join(repository, directory))) {
        if (/^music-provider.*\.(?:php|js|css)$/.test(name)) {
          fs.copyFileSync(path.join(repository, directory, name), path.join(root, directory, name));
        }
      }
    }
    for (const name of [
      'tests/music-song-metadata-v1.php', 'tests/fixtures/music-listening-v1.php',
      'tests/fixtures/stem-mp3-metadata-v1.json', 'chat.css', 'music-sites.php', 'api/music-song-metadata-v1.php',
    ]) {
      const target = path.join(root, name);
      fs.mkdirSync(path.dirname(target), {recursive: true});
      if (fs.existsSync(path.join(repository, name))) fs.copyFileSync(path.join(repository, name), target);
    }
    fs.writeFileSync(path.join(root, 'includes/bootstrap.php'), `<?php
declare(strict_types=1);
session_save_path(dirname(__DIR__));
session_start();
define('STONEFELLOW_ROOT', dirname(__DIR__));
putenv('MUSIC_LISTENING_TEST_DB=' . STONEFELLOW_ROOT . '/data.sqlite');
$config = ['site'=>['base_path'=>'', 'base_url'=>'${providerOrigin}'], 'uploads'=>[]];
require STONEFELLOW_ROOT . '/tests/fixtures/music-listening-v1.php';
require_once STONEFELLOW_ROOT . '/includes/music-provider-v1.php';
function require_login(): void { if (!current_user()) { http_response_code(401); exit('Please sign in.'); } }
`);
    fs.copyFileSync(path.join(repository, 'includes/music-provider-bootstrap-v1.php'), path.join(root, 'includes/music-provider-bootstrap-production-v1.php'));
    fs.writeFileSync(path.join(root, 'includes/music-provider-bootstrap-v1.php'), `<?php
declare(strict_types=1);
define('STONEFELLOW_ROOT', dirname(__DIR__));
putenv('MUSIC_LISTENING_TEST_DB=' . STONEFELLOW_ROOT . '/data.sqlite');
$config = ['site'=>['base_path'=>'', 'base_url'=>'${providerOrigin}'], 'uploads'=>[]];
unset($_SERVER['HTTP_X_USER']);
$GLOBALS['musicSongTestUserId']=0;
require STONEFELLOW_ROOT . '/tests/fixtures/music-listening-v1.php';
require_once STONEFELLOW_ROOT . '/includes/music-provider-v1.php';
require_once STONEFELLOW_ROOT . '/includes/music-provider-http-v1.php';
`);
    // The complete music page and its controller/CSS run unchanged. The shared
    // member header/sidebar otherwise boot unrelated Agent, phone and notices.
    fs.writeFileSync(path.join(root, 'includes/workspace-sidebar-v82.php'), '<aside id="chatSidebar" class="chat-sidebar"><a href="/music-sites.php?workspace=1">Music sites</a></aside>');
    fs.writeFileSync(path.join(root, 'includes/member-header.php'), '<?php ?><header class="member-header"><h2><?= e($memberHeaderTitle ?? "Music sites") ?></h2></header>');
    fs.writeFileSync(path.join(root, '_test-session.php'), `<?php
require __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json');
echo json_encode(['csrf_token'=>csrf_token()], JSON_THROW_ON_ERROR);
`);
    const run = (code, ...args) => execFileSync(php, [
      '-r', 'require $argv[1];' + code, path.join(root, 'includes/bootstrap.php'), ...args,
    ], {encoding: 'utf8', cwd: repository});
    run(`music_listening_test_seed(); music_song_metadata_v1_install(db()); music_listening_v1_install(db());
      foreach (['track_projects','track_stems','shows','release_plans','release_items','track_credits'] as $name)
        db()->exec('CREATE TABLE ' . $name . ' (id INTEGER PRIMARY KEY, workspace_id INTEGER)');`);
    if (install) run('music_provider_v1_install(db());');
    const readDb = (sql, params = []) => JSON.parse(run(
      '$q=db()->prepare($argv[2]);$q->execute(json_decode($argv[3],true,512,JSON_THROW_ON_ERROR));echo json_encode($q->fetchAll(),JSON_THROW_ON_ERROR);',
      sql, JSON.stringify(params),
    ));
    const execute = (sql, params = []) => run(
      '$q=db()->prepare($argv[2]);$q->execute(json_decode($argv[3],true,512,JSON_THROW_ON_ERROR));',
      sql, JSON.stringify(params),
    );
    const snapshot = () => JSON.parse(run('echo json_encode(music_listening_test_snapshot(),JSON_THROW_ON_ERROR);'));
    const configFile = path.join(root, 'test-config.php');
    fs.writeFileSync(configFile, "<?php return ['db'=>['dsn'=>'sqlite:' . __DIR__ . '/data.sqlite','user'=>'','pass'=>''], 'site'=>['base_url'=>'https://provider.example.test','base_path'=>'']];");
    fs.copyFileSync(configFile, path.join(root, 'config.php'));
    const productionBootstrapSmoke = (credential = '') => JSON.parse(execFileSync(php, ['-r',
      'require $argv[1];$out=["ready"=>music_provider_v1_schema_ready(db()),"session_status"=>session_status(),"protocol"=>MUSIC_PROVIDER_V1_PROTOCOL];if($argv[2]!==""){try{$site=music_provider_v1_authenticate(db(),$argv[2],music_provider_v1_now());$manifest=music_provider_v1_backend_action(db(),$site,["protocol"=>MUSIC_PROVIDER_V1_PROTOCOL,"action"=>"import.manifest"]);$out["items"]=count($manifest["items"]);}catch(MusicProviderV1Exception $e){$out["code"]=$e->publicCode;$out["status"]=$e->httpStatus;}}echo json_encode($out,JSON_THROW_ON_ERROR);',
      path.join(root, 'includes/music-provider-bootstrap-production-v1.php'), credential,
    ], {encoding: 'utf8'}));
    const migration = (...args) => JSON.parse(execFileSync(php, [
      path.join(root, 'scripts/music-provider-v1.php'), '--config=' + configFile, ...args,
    ], {encoding: 'utf8'}));
    const ownerService = (input, context = {}, userId = 11) => JSON.parse(run(
      '$GLOBALS["musicSongTestUserId"]=(int)$argv[4];$input=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);$context=json_decode($argv[3],true,512,JSON_THROW_ON_ERROR);echo json_encode(music_provider_v1_owner_action(db(),current_user(),$input,$context),JSON_THROW_ON_ERROR);',
      JSON.stringify({protocol, ...input}), JSON.stringify(context), String(userId),
    ));
    const verifySite = site => JSON.parse(run(
      '$site=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);$expected=$site["verification"]["response"];$input=["protocol"=>"vp3_music_provider_v1","action"=>"site.verify","workspace_id"=>$site["workspace_id"],"site_id"=>$site["site_id"],"expected_revision"=>$site["revision"],"request_id"=>$argv[3]];echo json_encode(music_provider_v1_owner_action(db(),current_user(),$input,["verify_request"=>static fn()=>$expected]),JSON_THROW_ON_ERROR);',
      JSON.stringify(site), crypto.randomUUID(),
    ));
    const createSite = ({origin = artistOrigin, label = 'Fixture artist site', userId = 11, workspaceId = 1} = {}) => {
      const credential = 'vmp1_' + crypto.randomBytes(32).toString('hex');
      const create = ownerService({action: 'site.create', workspace_id: workspaceId, request_id: crypto.randomUUID(), origin, label, environment: 'production', credential}, {}, userId);
      const verified = verifySite(create.site);
      return {credential, site: verified.site, create};
    };

    const socket = net.createServer();
    await new Promise((resolve, reject) => {
      socket.once('error', reject);
      socket.listen(0, '127.0.0.1', resolve);
    });
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
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
    assert.ok(ready, 'Provider PHP fixture failed to start: ' + logs);
    const json = async response => {
      const text = await response.text();
      try { return JSON.parse(text); }
      catch { throw new Error(`Invalid provider JSON (${response.status}): ${text}\n${logs}`); }
    };
    const auth = async (user = 11) => {
      const response = await fetch(base + '/_test-session.php', {headers: {'X-User': String(user)}});
      const {csrf_token: csrf} = await json(response);
      const cookie = response.headers.get('set-cookie')?.split(';')[0];
      assert.ok(csrf && cookie, 'Could not initialize owner fixture session.');
      return {user, csrf, cookie, headers: {'X-User': String(user), Cookie: cookie}};
    };
    const initial = await auth();
    const ownerPost = (fields, {user = 11, credentials = initial, csrf = credentials.csrf, headers = {}} = {}) => fetch(base + manageRoute, {
      method: 'POST', headers: {...credentials.headers, 'X-User': String(user), 'Content-Type': 'application/json', ...headers},
      body: JSON.stringify({protocol, csrf_token: csrf, ...fields}),
    });
    const request = (fields, {origin = artistOrigin, token, headers = {}} = {}) => fetch(base + providerRoute
      + (fields.action?.startsWith('playback.') && fields.site_id ? '?site_id=' + encodeURIComponent(fields.site_id) : ''), {
      method: 'POST', headers: {'X-User': '0', 'Content-Type': 'application/json', ...(origin === null ? {} : {Origin: origin}), ...(token ? {Authorization: 'Bearer ' + token} : {}), ...headers},
      body: JSON.stringify({protocol, ...fields}),
    });
    const localUrl = value => {
      const parsed = new URL(value, providerOrigin);
      assert.equal(parsed.origin, providerOrigin, 'Provider response pointed outside configured origin');
      return base + parsed.pathname + parsed.search;
    };
    const identities = readDb('SELECT recording_id,work_id,current_asset_id AS asset_id,catalog_track_id FROM music_recordings_v1 ORDER BY catalog_track_id');
    return {
      root, base, php, run, readDb, execute, snapshot, migration, productionBootstrapSmoke, ownerService, verifySite, createSite,
      auth, ownerPost, request, localUrl, json, close, identities,
      initial, get logs() { return logs; },
    };
  } catch (error) {
    close();
    throw error;
  }
}

export function beginInput(siteId, recordingId, changes = {}) {
  return {action: 'playback.begin', site_id: siteId, recording_id: recordingId,
    play_id: crypto.randomUUID(), playback_key: 'mps1_' + crypto.randomBytes(32).toString('hex'),
    source_url: artistOrigin + '/track.php?token=PRIVATE-QUERY#PRIVATE-FRAGMENT',
    source_surface: 'player', position_ms: 0, playback_rate: 1, ...changes};
}

export function eventInput(sessionId, changes = {}) {
  return {action: 'playback.events', session_id: sessionId, event_id: crypto.randomUUID(), seq: 1,
    event_type: 'resume', state: 'playing', position_ms: 0, elapsed_ms: 0, cumulative_played_ms: 0,
    playback_rate: 1, ...changes};
}
