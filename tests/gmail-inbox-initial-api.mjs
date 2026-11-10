import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {copyFileSync, mkdirSync, mkdtempSync, rmSync, writeFileSync} from 'node:fs';
import {createServer} from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

const root = mkdtempSync(join(tmpdir(), 'vp3-initial-inbox-api-'));
let server;
let logs = '';

try {
  mkdirSync(join(root, 'api'));
  mkdirSync(join(root, 'includes'));
  copyFileSync(new URL('../api/gmail-inbox-initial-v1.php', import.meta.url), join(root, 'api', 'gmail-inbox-initial-v1.php'));
  writeFileSync(join(root, 'includes', 'bootstrap.php'), `<?php
session_start();
$_SESSION['csrf_token'] = 'fixture-csrf';
function csrf_token(): string { return $_SESSION['csrf_token']; }
function current_user(): ?array {
    return isset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS']) ? null : ['id'=>(int)($_SERVER['HTTP_X_FIXTURE_OWNER'] ?? 1)];
}
function has_permission(string $capability, array $user): bool {
    return $capability !== ($_SERVER['HTTP_X_FIXTURE_DENY'] ?? '');
}
function vp3_participant_scope_v1(): ?array {
    return isset($_SERVER['HTTP_X_FIXTURE_PARTICIPANT']) ? ['participant_id'=>3] : null;
}
function db(): PDO { return new PDO('sqlite::memory:'); }
`);
  writeFileSync(join(root, 'includes', 'agent-connection-gmail-inbox-initial-v1.php'), `<?php
$calls = [];
function fixture_record(string $action, array $user, mixed $input): void {
    global $calls;
    if (session_status() === PHP_SESSION_ACTIVE) throw new RuntimeException('Session remained locked.');
    header('X-Fixture-Dispatch: '.$action);
    $calls[] = [$action, $user['id'], $input];
}
function agent_connection_gmail_inbox_initial_view_v1(PDO $pdo, array $user, bool $includeReport = false): array {
    global $calls;
    fixture_record('status', $user, $includeReport);
    return ['calls'=>$calls];
}
function agent_connection_gmail_inbox_initial_start_v1(PDO $pdo, array $user, int $days): void {
    fixture_record('start', $user, $days);
}
function agent_connection_gmail_inbox_initial_advance_v1(PDO $pdo, array $user, string $runId): bool {
    fixture_record('advance', $user, $runId);
    if (isset($_SERVER['HTTP_X_FIXTURE_PROVIDER_FAILURE'])) throw new RuntimeException('Private provider detail.', 502);
    return true;
}
`);

  const socket = createServer();
  await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
  const {port} = socket.address();
  await new Promise((resolve, reject) => socket.close(error => error ? reject(error) : resolve()));
  const address = `127.0.0.1:${port}`;
  server = spawn(process.env.VP3_PHP_BINARY || 'php', ['-d', `session.save_path=${root}`, '-S', address, '-t', root], {stdio:['ignore', 'ignore', 'pipe']});
  server.stderr.on('data', data => { logs += data; });
  const ready = await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(Error('PHP server did not start.\n' + logs)), 5000);
    server.on('error', error => { clearTimeout(timer); reject(error); });
    server.stderr.on('data', () => {
      if (logs.includes('started')) { clearTimeout(timer); resolve(true); }
    });
    server.on('exit', code => { clearTimeout(timer); reject(Error(`PHP server exited (${code}).\n` + logs)); });
  });
  assert.equal(ready, true);

  const runId = 'a'.repeat(32);
  const request = async ({body = {}, headers = {}, method = 'POST', raw} = {}) => {
    const response = await fetch(`http://${address}/api/gmail-inbox-initial-v1.php`, {
      method,
      headers:{'Content-Type':'application/json', ...headers},
      ...(method === 'POST' ? {body:raw ?? JSON.stringify({action:'status', owner_key:'1', csrf_token:'fixture-csrf', ...body})} : {}),
    });
    const text = await response.text();
    try { return {status:response.status, headers:response.headers, data:JSON.parse(text)}; }
    catch { throw Error(text + '\n' + logs); }
  };

  const status = await request();
  assert.equal(status.status, 200);
  assert.equal(status.headers.get('cache-control'), 'private, no-store');
  assert.deepEqual(status.data, {ok:true, review:{calls:[['status', 1, false]]}});
  const start = await request({body:{action:'start', days:7, include_report:true}});
  assert.equal(start.status, 200);
  assert.deepEqual(start.data.review.calls, [['start', 1, 7], ['status', 1, true]]);
  const advance = await request({body:{action:'advance', run_id:runId}});
  assert.equal(advance.status, 200);
  assert.deepEqual(advance.data.review.calls, [['advance', 1, runId], ['status', 1, false]]);

  for (const [code, options] of [
    [405, {method:'GET'}],
    [401, {headers:{'X-Fixture-Anonymous':'1'}}],
    [403, {headers:{'X-Fixture-Participant':'1'}}],
    [403, {headers:{'X-Fixture-Deny':'account.access'}}],
    [403, {headers:{'X-Fixture-Deny':'chat.access'}}],
    [409, {headers:{'X-Fixture-Owner':'2'}}],
    [419, {body:{csrf_token:'wrong'}}],
    [419, {body:{csrf_token:['fixture-csrf']}}],
    [415, {headers:{'Content-Type':'text/plain'}}],
    [413, {raw:' '.repeat(2049)}],
    [422, {raw:'{'}],
    [422, {raw:'[]'}],
    [422, {body:{owner_key:1}}],
    [422, {body:{include_report:null}}],
    [422, {body:{action:'start', days:'7'}}],
    [422, {body:{action:'start', days:2}}],
    [422, {body:{action:'start', days:7, run_id:runId}}],
    [422, {body:{action:'advance', run_id:'wrong'}}],
    [422, {body:{action:'status', run_id:runId}}],
    [422, {body:{action:'send'}}],
  ]) {
    const denied = await request(options);
    assert.equal(denied.status, code, JSON.stringify(options));
    assert.equal(denied.data.ok, false);
    assert.equal(denied.headers.has('x-fixture-dispatch'), false);
    assert.equal(Object.hasOwn(denied.data, 'review'), false);
  }

  const failure = await request({body:{action:'advance', run_id:runId}, headers:{'X-Fixture-Provider-Failure':'1'}});
  assert.equal(failure.status, 503);
  assert.deepEqual(failure.data, {ok:false, error:'Inbox Review is temporarily unavailable.'});
  console.log('Gmail initial inbox HTTP boundary passed');
} finally {
  if (server?.pid && server.exitCode === null && server.signalCode === null) {
    server.kill();
    await new Promise(resolve => server.once('exit', resolve));
  }
  rmSync(root, {recursive:true, force:true});
}
