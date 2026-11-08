import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {copyFileSync, mkdirSync, mkdtempSync, rmSync, writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

const repo = fileURLToPath(new URL('../', import.meta.url));
const root = mkdtempSync(join(tmpdir(), 'vp3-development-runner-api-'));
const php = process.env.VP3_PHP_BINARY || 'php';
const token = 't'.repeat(48);
const base = 'a'.repeat(40);
const head = 'b'.repeat(40);
const origin = 'https://isolated-preview.example.net';
const env = {...process.env, VP3_AGENT_DEVELOPMENT_RUNNER_TOKEN: token,
  VP3_AGENT_DEVELOPMENT_BASE_SHA: base, VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN: origin};
let server;

try {
  mkdirSync(join(root, 'api'));
  mkdirSync(join(root, 'includes'));
  copyFileSync(join(repo, 'api/agent-development-runner-v1.php'), join(root, 'api/agent-development-runner-v1.php'));
  writeFileSync(join(root, 'includes/agent-development-v1.php'), `<?php require_once ${JSON.stringify(join(repo, 'includes/agent-development-v1.php'))};`);
  writeFileSync(join(root, 'includes/bootstrap.php'), `<?php
require_once ${JSON.stringify(join(repo, 'includes/permissions.php'))};
function db(): PDO {
    static $pdo;
    return $pdo ??= new PDO('sqlite:'.dirname(__DIR__).'/fixture.db', null, null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function site_config(string $key, mixed $default = null): mixed { return $key === 'base_url' ? 'https://app.example.test' : $default; }
// Simulate the web server’s HTTPS flag for this loopback-only fixture.
if (($_SERVER['HTTP_X_FIXTURE_HTTPS'] ?? '') === '1') $_SERVER['HTTPS'] = 'on';
`);
  writeFileSync(join(root, 'seed.php'), `<?php
require __DIR__.'/includes/bootstrap.php';
require __DIR__.'/includes/agent-development-v1.php';
$pdo = db();
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER);
    INSERT INTO users VALUES(1,'admin',1);");
vp3_dev_install_v1($pdo);
vp3_dev_set_mode_v1($pdo,['id'=>1],'code_stage',0);
echo json_encode(vp3_dev_create_v1($pdo,['id'=>1],'Build fixture candidate.',1),JSON_THROW_ON_ERROR);
`);
  const seed = spawnSync(php, [join(root, 'seed.php')], {env, encoding: 'utf8'});
  assert.equal(seed.status, 0, seed.stderr || seed.error?.message);
  const revision = JSON.parse(seed.stdout);

  const probe = net.createServer();
  await new Promise(resolve => probe.listen(0, '127.0.0.1', resolve));
  const address = `127.0.0.1:${probe.address().port}`;
  await new Promise(resolve => probe.close(resolve));
  let logs = '';
  server = spawn(php, ['-S', address, '-t', root], {env, stdio: ['ignore', 'ignore', 'pipe']});
  server.stderr.on('data', chunk => { logs += chunk; });
  server.on('error', error => { logs += error.message; });
  const endpoint = `http://${address}/api/agent-development-runner-v1.php`;
  const request = async (body = {action: 'claim'}, {bearer = token, secure = true, method = 'POST'} = {}) => {
    const response = await fetch(endpoint, {
      method,
      headers: {
        'Content-Type': 'application/json',
        ...(bearer ? {Authorization: `Bearer ${bearer}`} : {}),
        ...(secure ? {'X-Fixture-Https': '1'} : {}),
      },
      ...(method === 'POST' ? {body: typeof body === 'string' ? body : JSON.stringify(body)} : {}),
    });
    return {status: response.status, headers: response.headers, data: await response.json()};
  };
  for (let attempt = 0; ; attempt++) {
    try { if ((await request(null, {method: 'GET'})).status === 405) break; } catch {}
    if (attempt >= 60) throw new Error('Runner endpoint did not start.\n'+logs);
    await new Promise(resolve => setTimeout(resolve, 25));
  }

  assert.equal((await request(undefined, {secure: false})).status, 401);
  assert.equal((await request(undefined, {bearer: ''})).status, 401);
  assert.equal((await request(undefined, {bearer: 'x'.repeat(48)})).status, 401);
  assert.equal((await request('x'.repeat(16001))).status, 413);
  assert.equal((await request('{')).status, 400);
  assert.equal((await request({action: 'publish'})).status, 400);

  const claim = await request();
  assert.equal(claim.status, 200);
  assert.match(claim.headers.get('cache-control'), /private.*no-store/);
  const {job} = claim.data;
  assert.deepEqual(Object.keys(job).sort(), ['base_sha', 'id', 'lease', 'request']);
  assert.equal(job.id, revision.id);
  assert.equal(job.base_sha, base);
  assert.match(job.lease, /^[a-f0-9]{64}$/);
  assert.equal((await request()).data.job, null);

  const finish = {action: 'finish', id: job.id, lease: job.lease, result: {
    head_sha: head, artifact_sha: 'c'.repeat(64), preview_url: `${origin}/revisions/${head}/`,
    summary: 'Verified candidate.', checks: ['Fixture smoke passed.'],
  }};
  assert.equal((await request({...finish, lease: '0'.repeat(64)})).status, 409);
  assert.equal((await request({...finish, result: {...finish.result, preview_url: 'https://untrusted.example.net/'}})).status, 422);
  assert.deepEqual((await request(finish)).data, {ok: true});
  assert.equal((await request(finish)).status, 409);
  assert.equal((await request()).data.job, null);
  console.log('AGENT_DEVELOPMENT_RUNNER_API_V1=PASS (HTTP authorization, payload cap, single claim, exact staged result, replay denial)');
} finally {
  if (server?.pid && server.exitCode === null && server.signalCode === null) {
    server.kill();
    await new Promise(resolve => server.once('close', resolve));
  }
  rmSync(root, {recursive: true, force: true});
}
