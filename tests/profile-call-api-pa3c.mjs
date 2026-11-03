import assert from 'node:assert/strict';
import { createHmac, randomBytes } from 'node:crypto';
import { execFile, spawn, spawnSync } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';

// Real production HTTP adapters and SQL. Only authentication setup, time and LiveKit transport are controlled.
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-pa3c-api-'));
const config = path.join(temp, 'fixture.json');
const root = process.cwd();
const php = process.env.VP3_PHP_BINARY || 'php';
const dbFixture = path.resolve('tests/fixtures/profile-call-api-pa3c-db.php');
const legacyDb = path.resolve('tests/fixtures/profile-handoff-api-pa3-db.php');
const transcriptLog = path.join(temp, 'inference.log');
const csrf = 'pa3-fixture-csrf';
const livekitSecret = 'fixture-livekit-signing-secret-only';
const workerSecret = 'fixture-transcription-worker-secret-only';
const invokeAsync = promisify(execFile);
const requests = [];
let failDelete = false;
let now = Date.now();
let sequence = 0;
let checks = 0;
const servers = [];
let base;
let logs = '';

const key = () => randomBytes(16).toString('hex');
const check = (value, label) => {
  assert.ok(value, label);
  checks++;
  console.log('PASS ' + label);
};

function invoke(action, ...args) {
  const script = action === 'cleanup' ? legacyDb : dbFixture;
  const result = spawnSync(php, [script, action, config, ...args.map(String)], {
    encoding: 'utf8',
    env: { ...process.env, ...env },
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return result.stdout;
}

const mock = http.createServer(async (request, response) => {
  const chunks = [];
  for await (const chunk of request) chunks.push(chunk);
  const input = JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}');
  requests.push({ route: request.url, authorization: request.headers.authorization, input });
  response.setHeader('Content-Type', 'application/json');
  if (request.url === '/twirp/livekit.RoomService/DeleteRoom' && failDelete) {
    response.statusCode = 503;
    response.end('{"code":"unavailable","msg":"controlled provider failure"}');
    return;
  }
  response.end('{}');
});

const env = {
  VP3_PA3_ROOT: root,
  VP3_PA3_CONFIG: config,
  VP3_PA3_INFERENCE_LOG: transcriptLog,
  VP3_LIVEKIT_URL: '',
  VP3_LIVEKIT_API_KEY: 'fixture-livekit-key',
  VP3_LIVEKIT_API_SECRET: livekitSecret,
  VP3_LIVEKIT_AGENT_NAME: 'vp3-fixture-agent',
  VP3_MEETING_WORKER_SECRET: workerSecret,
  VP3_PROFILE_CALL_AGENT_ENABLED: '1',
};

function actor(user, browser, session) {
  return {
    user,
    cookie: `PHPSESSID=pa3c${session}; sf_profile_visitor=${browser.repeat(64)}; sf_profile_conversation=${browser.repeat(64)}`,
  };
}

const visitor = actor(2, 'b', 'visitor');
const sameMemberOtherBrowser = actor(2, 'e', 'visitorotherbrowser');
const foreign = actor(3, 'f', 'foreign');
const owner = actor(1, 'c', 'owner');
const otherOwner = actor(4, 'd', 'otherowner');
const anonymous = actor(0, 'a', 'anonymous');
let publicBase;
let foreignBase;
let foreignBrowserBase;
let driver;

async function request(endpoint, fields, who = visitor, { method = 'POST', headers = {}, origin = base } = {}) {
  const read = ['GET', 'HEAD'].includes(method);
  const query = read && Object.keys(fields).length ? '?' + new URLSearchParams(fields) : '';
  const response = await fetch(`${origin}/${endpoint}${query}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-Fixture-User': String(who.user),
      'X-Fixture-Now': String(now),
      Cookie: who.cookie,
      ...headers,
    },
    ...(read ? {} : { body: JSON.stringify(fields) }),
    signal: AbortSignal.timeout(12000),
  });
  const raw = await response.text();
  let data = null;
  try {
    if (raw) data = JSON.parse(raw);
  } catch {
    throw Error(`Invalid response ${response.status} for ${endpoint}: ${raw}\n${logs.slice(-3500)}`);
  }
  if (response.status === 500) {
    throw Error(`HTTP 500 for ${endpoint} ${fields.action || ''}: ${raw}\n${logs.slice(-3500)}`);
  }
  return { status: response.status, data, raw, headers: response.headers };
}

const callEndpoint = 'api/profile-call-pa3c.php';
const activityEndpoint = 'api/profile-call-activity-pa3c.php';
const workerEndpoint = 'api/profile-call-worker-pa3c.php';
const callVisitor = (action, fields = {}, who = visitor, wire = publicBase, options) =>
  request(callEndpoint, { side: 'visitor', ...wire, action, ...fields }, who, options);
const callOwner = (action, fields = {}, who = owner, options) =>
  request(callEndpoint, { side: 'owner', csrf_token: csrf, action, ...fields }, who, options);
const chat = (action, fields = {}) => request('api/chat-notifications-brain-v240.php',
  { action: 'doorbell_' + action, csrf_token: csrf, chat_conversation_id: 11, chat_agent_id: 7, ...fields }, owner);
const profile = (action, fields = {}, who = visitor, wire = publicBase, options) =>
  request('api/profile-agent.php', { ...wire, action, ...fields }, who, options);
const activity = (callId, who = owner, after = 0) =>
  request(activityEndpoint, { call_id: callId, after }, who, { method: 'GET' });
const worker = (action, fields, authorization = 'Bearer ' + workerSecret) =>
  request(workerEndpoint, { ...fields, action }, anonymous, { headers: { Authorization: authorization } });
const tick = (ms = 25) => { now += ms; };
const presence = () => chat('presence', { tab_id: 'c'.repeat(32), visible: true, presence_sequence: ++sequence });
const safe = raw => !/PRIVATE_CHAT_SECRET|FOREIGN_CHAT_SECRET|PRIVATE_KNOWLEDGE_SECRET|fixture-livekit-signing-secret|fixture-transcription-worker-secret|credential_hash|accepted_json|lease_token|password_hash|SQLSTATE|SECRET fixture/.test(raw);
const denied = r => r.status >= 400 && r.status < 500 && r.data?.ok === false && safe(r.raw);
const binding = call => ({ conversation_id: call.conversation_id, call_id: call.id, revision: call.revision });

function verifyJwt(token) {
  const [header, payload, signature, unexpected] = token.split('.');
  assert.equal(unexpected, undefined);
  assert.deepEqual(JSON.parse(Buffer.from(header, 'base64url')), { alg: 'HS256', typ: 'JWT' });
  assert.equal(signature, createHmac('sha256', livekitSecret).update(header + '.' + payload).digest('base64url'));
  return JSON.parse(Buffer.from(payload, 'base64url'));
}

async function bootstrapActor(who) {
  const result = await request('boot.php', {}, who, { method: 'GET' });
  assert.equal(result.status, 200);
  return { username: 'fixture-owner', profile_token: result.data.token, conversation_namespace: result.data.namespace };
}

async function startPhpServer() {
  const reservation = net.createServer();
  reservation.listen(0, '127.0.0.1');
  await once(reservation, 'listening');
  const port = reservation.address().port;
  await new Promise(resolve => reservation.close(resolve));
  const child = spawn(php, ['-d', 'display_errors=0', '-S', `127.0.0.1:${port}`, '-t', temp], {
    env: { ...process.env, ...env },
    stdio: ['ignore', 'ignore', 'pipe'],
  });
  servers.push(child);
  child.stderr.on('data', value => { logs += value; });
  const origin = `http://127.0.0.1:${port}`;
  const deadline = Date.now() + 5000;
  while (Date.now() < deadline && child.exitCode === null && child.signalCode === null) {
    try {
      const response = await fetch(`${origin}/ready.txt`, { signal: AbortSignal.timeout(500) });
      if (response.ok && await response.text() === 'ready') return origin;
    } catch {
      // The child can still be starting even after spawn has returned.
    }
    await new Promise(resolve => setTimeout(resolve, 30));
  }
  throw Error(`PHP fixture server did not become ready at ${origin}\n${logs.slice(-3500)}`);
}

async function waitForFile(file) {
  const deadline = Date.now() + 5000;
  while (!fs.existsSync(file) && Date.now() < deadline) await new Promise(resolve => setTimeout(resolve, 15));
  assert.ok(fs.existsSync(file), 'Race fixture did not reach ' + path.basename(file));
}

async function startText(who = visitor, wire = publicBase) {
  tick();
  assert.equal((await presence()).status, 200);
  const requested = await profile('message', {
    chat_mode: 'live', message: 'Please open a shared Profile conversation.', client_request_id: key(),
  }, who, wire);
  assert.equal(requested.data?.handoff?.state, 'awaiting_delivery', requested.raw);
  const target = requested.data.handoff;
  const delivered = await chat('delivery', { turn_id: target.turn_id, revision: target.revision, tab_id: 'c'.repeat(32) });
  assert.equal(delivered.status, 200, delivered.raw);
  const card = delivered.data.turn;
  const ack = await chat('ack', {
    turn_id: card.turn_id, revision: card.revision, delivery_id: delivered.data.delivery_id,
    chat_message_id: delivered.data.chat_message_id, tab_id: 'c'.repeat(32),
  });
  assert.equal(ack.status, 200, ack.raw);
  return ack.data.turn;
}

async function acceptText(card) {
  const result = await profile('doorbell_claim', {
    csrf_token: csrf, turn_id: card.turn_id, revision: card.revision, conversation_id: card.conversation_id,
    chat_conversation_id: 11, chat_agent_id: 7, request_id: key(),
  }, owner, {});
  assert.equal(result.status, 200, result.raw);
  assert.equal(result.data.turn.chat.live_status, 'accepted');
  return card.conversation_id;
}

async function openCall(cid, { assisted = false, invite = callVisitor, answer = callOwner } = {}) {
  tick();
  const offered = await invite('offer', { conversation_id: cid, disclosed: assisted, request_id: key() });
  assert.equal(offered.status, 200, offered.raw);
  assert.equal(offered.data.call.status, 'ringing');
  const accepted = await answer('accept', { ...binding(offered.data.call), disclosed: assisted, request_id: key() });
  assert.equal(accepted.status, 200, accepted.raw);
  assert.equal(accepted.data.call.status, 'accepted');
  return accepted.data.call;
}

async function connect(call) {
  const ownerClient = key();
  const visitorClient = key();
  const ownerToken = await callOwner('token', { ...binding(call), client_id: ownerClient });
  const visitorToken = await callVisitor('token', { ...binding(call), client_id: visitorClient });
  assert.equal(ownerToken.status, 200, ownerToken.raw);
  assert.equal(visitorToken.status, 200, visitorToken.raw);
  const ownerBeat = await callOwner('heartbeat', { ...binding(call), client_id: ownerClient });
  assert.equal(ownerBeat.status, 200, ownerBeat.raw);
  const visitorBeat = await callVisitor('heartbeat', { ...binding(call), client_id: visitorClient });
  assert.equal(visitorBeat.status, 200, visitorBeat.raw);
  assert.equal(visitorBeat.data.call.status, 'live');
  return { call: visitorBeat.data.call, ownerClient, visitorClient, ownerToken: ownerToken.data, visitorToken: visitorToken.data };
}

try {
  mock.listen(0, '127.0.0.1');
  await once(mock, 'listening');
  env.VP3_LIVEKIT_URL = `ws://127.0.0.1:${mock.address().port}`;
  driver = invoke('init').trim();
  fs.mkdirSync(path.join(temp, 'includes'));
  fs.mkdirSync(path.join(temp, 'api'));
  for (const name of fs.readdirSync('includes')) {
    if (name !== 'bootstrap.php') fs.symlinkSync(path.resolve('includes', name), path.join(temp, 'includes', name));
  }
  for (const name of ['profile-agent.php', 'chat-notifications-brain-v240.php', 'profile-call-pa3c.php',
    'profile-call-activity-pa3c.php', 'profile-call-worker-pa3c.php']) {
    fs.copyFileSync('api/' + name, path.join(temp, 'api', name));
  }
  const fixtureBootstrap = fs.readFileSync('tests/fixtures/profile-handoff-api-pa3-bootstrap.php', 'utf8');
  fs.writeFileSync(path.join(temp, 'includes/bootstrap.php'), fixtureBootstrap + `
require_once __DIR__.'/agent-compute-v020.php';
require_once __DIR__.'/agent-compute-v023.php';
require_once __DIR__.'/artist-listening.php';
function ai_active_provider(): string { return 'local'; }
function vp3_profile_call_now_pa3c(): int { return $GLOBALS['pa3Now']; }
if (isset($_SERVER['HTTP_X_FIXTURE_LIVEKIT_DISABLED'])) {
    putenv('VP3_LIVEKIT_URL');
    putenv('LIVEKIT_URL');
}
$race = $_SERVER['HTTP_X_FIXTURE_RACE'] ?? '';
$racePath = dirname((string)getenv('VP3_PA3_CONFIG'));
if ($race === 'hold') {
    $pdo->afterSql = static function (string $sql) use ($racePath): void {
        if (!str_starts_with($sql, 'INSERT INTO profile_call_owner_locks_pa3c')) return;
        touch($racePath.'/race-locked');
        $deadline = microtime(true) + 7;
        do {
            clearstatcache(true, $racePath.'/race-release');
            if (is_file($racePath.'/race-release')) return;
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Race fixture release timed out.');
    };
} elseif ($race === 'compete') {
    touch($racePath.'/race-attempted');
}
`);
  fs.writeFileSync(path.join(temp, 'boot.php'), `<?php
require __DIR__.'/includes/bootstrap.php';
header('Content-Type: application/json');
$context = vp3_profile_agent_public_context_v110(db(), profile_by_username(db(),'fixture-owner'), current_user());
echo json_encode(['token'=>profile_chat_token(1),'namespace'=>hash('sha256','profile-widget-v1|'.$context['conversation_authority']['credential_hash'])], JSON_THROW_ON_ERROR);
`);
  fs.writeFileSync(path.join(temp, 'ready.txt'), 'ready');
  base = await startPhpServer();
  publicBase = await bootstrapActor(visitor);
  foreignBase = await bootstrapActor(foreign);
  foreignBrowserBase = await bootstrapActor(sameMemberOtherBrowser);

  let r = await callOwner('state', { conversation_id: 0 });
  check(r.status === 200 && r.data.call === null && r.data.agent_assisted_phone_chat_enabled === false,
    'owner state is empty and call assistance defaults off');
  r = await callOwner('state', { conversation_id: 0 }, anonymous);
  check(denied(r), 'unauthenticated owner state cannot discover call data');
  for (const method of ['GET', 'HEAD', 'PUT', 'DELETE']) {
    r = await callVisitor('offer', { conversation_id: 1 }, visitor, publicBase, { method });
    check(r.status === 405, method + ' cannot offer a call');
  }
  r = await callVisitor('state', { conversation_id: 0, profile_token: 'invalid' });
  check(denied(r), 'invalid Profile token denies native call state');
  r = await callVisitor('state', { conversation_id: 0, conversation_namespace: 'e'.repeat(64) });
  check(denied(r), 'invalid native widget namespace denies call state');
  r = await callOwner('offer', { conversation_id: 1, csrf_token: 'invalid', request_id: key(), disclosed: false });
  check(denied(r), 'owner offer requires authenticated same-session CSRF');

  const pendingText = await startText();
  const cid = pendingText.conversation_id;
  r = await callVisitor('offer', { conversation_id: cid, request_id: key(), disclosed: false });
  check(denied(r), 'waiting live text does not authorize a voice call');
  await acceptText(pendingText);
  r = await callVisitor('state', { conversation_id: cid }, visitor, publicBase, {
    headers: { 'X-Fixture-Livekit-Disabled': '1' },
  });
  check(r.status === 200 && !r.data.voice_ready && !r.data.available, 'unconfigured media preserves text with truthful call unavailability');
  r = await callVisitor('offer', { conversation_id: cid, request_id: key(), disclosed: false }, visitor, publicBase, {
    headers: { 'X-Fixture-Livekit-Disabled': '1' },
  });
  check(denied(r), 'unconfigured media cannot create a ringing offer');
  invoke('settings', 'offline', 1);
  r = await callVisitor('offer', { conversation_id: cid, request_id: key(), disclosed: false });
  check(denied(r), 'existing Offline preference denies a new call');
  invoke('settings', 'online', 0);
  r = await callVisitor('offer', { conversation_id: cid, request_id: key(), disclosed: false });
  check(denied(r), 'existing social-chat preference denies a new call');
  invoke('settings', 'online', 1);

  const offerInput = { conversation_id: cid, request_id: key(), disclosed: false };
  r = await callVisitor('offer', { ...offerInput, disclosed: true });
  check(denied(r), 'a client cannot opt an unassisted call into assistance');
  r = await callVisitor('offer', offerInput);
  assert.equal(r.status, 200, r.raw);
  const ringing = r.data.call;
  check(r.status === 200 && ringing.status === 'ringing' && ringing.initiator === 'visitor' &&
    !ringing.assisted && /^[a-f0-9]{32}$/.test(ringing.id) && safe(r.raw),
  'exact visitor starts an ordinary, unassisted ringing call');
  r = await callVisitor('offer', offerInput);
  check(r.status === 200 && r.data.call.id === ringing.id && r.data.call.revision === ringing.revision,
    'retry of the exact offer preserves call ID and revision');
  r = await callVisitor('offer', { ...offerInput, request_id: key() });
  check(denied(r) || r.data?.call?.id === ringing.id, 'repeat ringing cannot replace the existing call');
  r = await callOwner('state', { conversation_id: 0 });
  check(r.status === 200 && r.data.call?.id === ringing.id, 'owner can discover the exact ringing call from the Chat rail');
  for (const [label, who, wire] of [
    ['other visitor', foreign, foreignBase], ['same account in a different browser', sameMemberOtherBrowser, foreignBrowserBase],
    ['same browser after account switch', { ...visitor, user: foreign.user }, publicBase],
  ]) {
    r = await callVisitor('state', binding(ringing), who, wire);
    check(denied(r) || (r.status === 200 && r.data.call === null), label + ' cannot discover the call');
    r = await callVisitor('end', binding(ringing), who, wire);
    check(denied(r), label + ' cannot cancel the call');
  }
  r = await callOwner('accept', { ...binding(ringing), disclosed: false, request_id: key() }, otherOwner);
  check(denied(r), 'another owner cannot accept a borrowed call ID');
  r = await callVisitor('accept', { ...binding(ringing), disclosed: false, request_id: key() });
  check(denied(r), 'initiating visitor cannot self-accept the call');
  r = await callOwner('token', { ...binding(ringing), client_id: key() });
  check(denied(r), 'room token is withheld before the other party accepts');
  for (const alteration of [{ revision: ringing.revision + 1 }, { conversation_id: cid + 999 }, { call_id: key() }, { disclosed: true }]) {
    r = await callOwner('accept', { ...binding(ringing), disclosed: false, request_id: key(), ...alteration });
    check(denied(r), 'accept rejects substituted revision, conversation, call or notice: ' + Object.keys(alteration)[0]);
  }

  const acceptInput = { ...binding(ringing), disclosed: false, request_id: key() };
  r = await callOwner('accept', acceptInput);
  const accepted = r.data.call;
  check(r.status === 200 && accepted.status === 'accepted' && accepted.revision > ringing.revision,
    'exact owner acceptance changes the ring into a media join');
  r = await callOwner('accept', acceptInput);
  check(r.status === 200 && r.data.call.id === accepted.id && r.data.call.revision === accepted.revision,
    'lost acceptance receipt retries without a second call');
  r = await callOwner('token', { ...binding(ringing), client_id: key() });
  check(denied(r), 'stale ringing revision cannot mint a media token');
  r = await callOwner('heartbeat', { ...binding(accepted), client_id: key() });
  check(denied(r), 'unissued client cannot claim a media heartbeat');

  const live = await connect(accepted);
  const ownerClaims = verifyJwt(live.ownerToken.token);
  const visitorClaims = verifyJwt(live.visitorToken.token);
  check(ownerClaims.iss === env.VP3_LIVEKIT_API_KEY && ownerClaims.sub !== visitorClaims.sub &&
    ownerClaims.sub === live.ownerToken.identity && visitorClaims.sub === live.visitorToken.identity &&
    ownerClaims.video.room === visitorClaims.video.room && ownerClaims.video.room.length > 20 &&
    ownerClaims.exp - ownerClaims.nbf <= 180 && visitorClaims.exp - visitorClaims.nbf <= 180,
  'signed short-lived JWTs bind distinct verified identities to the exact same room');
  for (const claims of [ownerClaims, visitorClaims]) {
    const grant = claims.video;
    check(grant.roomJoin && grant.canPublish && grant.canSubscribe && grant.canPublishData === false &&
      Array.isArray(grant.canPublishSources) && grant.canPublishSources.length === 1 &&
      ['microphone', 'MICROPHONE', 2].includes(grant.canPublishSources[0]) &&
      !grant.roomAdmin && !grant.roomCreate && !grant.roomRecord,
    'media token grants microphone publication and room subscription only');
  }
  r = await callOwner('token', { ...binding(live.call), client_id: key() });
  check(denied(r), 'second owner tab cannot replace an active media lease');
  r = await callVisitor('heartbeat', { ...binding(live.call), client_id: key() });
  check(denied(r), 'second visitor tab cannot claim the connected media lease');
  r = await callOwner('state', { conversation_id: cid });
  check(r.status === 200 && r.data.call.status === 'live' && safe(r.raw), 'both authorized heartbeats produce a live state without worker or private data');
  check(requests.length === 0 && !fs.existsSync(transcriptLog), 'ordinary voice call dispatches no transcription, research or inference');

  const card = (await chat('list')).data.turns.find(turn => turn.conversation_id === cid);
  r = await profile('doorbell_reply', {
    csrf_token: csrf, turn_id: card.turn_id, revision: card.revision, conversation_id: cid,
    chat_conversation_id: 11, chat_agent_id: 7, request_id: key(), message: 'Here is a parallel owner text reply.',
  }, owner, {});
  check(r.status === 200 && r.data.message_id > 0, 'exact owner can continue text during an authorized voice call');
  r = await callOwner('state', { conversation_id: cid });
  check(r.status === 200 && r.data.call.id === live.call.id && r.data.call.status === 'live',
    'owner text reply leaves the same voice call active');

  const otherCid = await acceptText(await startText(foreign, foreignBase));
  r = await callVisitor('offer', { conversation_id: otherCid, disclosed: false, request_id: key() }, foreign, foreignBase);
  check(denied(r), 'one owner cannot start a parallel call from another accepted visitor thread');

  failDelete = true;
  r = await callVisitor('end', { ...binding(live.call), client_id: live.visitorClient });
  const deletes = () => requests.filter(item => item.route === '/twirp/livekit.RoomService/DeleteRoom');
  check(r.status === 200 && (!r.data.call || r.data.call.status === 'ended') && deletes().length >= 1,
    'hang-up closes the exact call even when provider room deletion fails');
  const firstDelete = deletes()[0];
  check(firstDelete.input.room === ownerClaims.video.room &&
    verifyJwt(firstDelete.authorization.replace('Bearer ', '')).video.roomCreate === true,
  'room deletion calls the provider with a service grant bound to the exact room');
  const priorDeletes = deletes().length;
  failDelete = false;
  tick(60000);
  await invokeAsync(php, [dbFixture, 'cleanup_rooms', config, String(now)], { env: { ...process.env, ...env } });
  check(deletes().length > priorDeletes && deletes().at(-1).input.room === ownerClaims.video.room,
    'durable cleanup retries the failed provider operation on the exact old room');
  r = await callOwner('token', { ...binding(live.call), client_id: live.ownerClient });
  check(denied(r), 'ended call cannot mint a fresh or reconnect token');
  r = await callVisitor('heartbeat', { ...binding(live.call), client_id: live.visitorClient });
  check(denied(r), 'stale connected event cannot revive the ended call');

  assert.equal((await presence()).status, 200);
  const next = await openCall(cid, { invite: callOwner, answer: callVisitor });
  check(next.id !== live.call.id && next.initiator === 'owner', 'owner can initiate a new call on the preserved text thread');
  const nextOwnerClient = key();
  r = await callOwner('token', { ...binding(next), client_id: nextOwnerClient });
  check(r.status === 200 && verifyJwt(r.data.token).video.room !== ownerClaims.video.room,
    'a new call uses a different provider room');
  r = await callVisitor('end', { ...binding(live.call), client_id: live.visitorClient });
  check(denied(r) || r.data.call?.id !== next.id, 'stale hang-up cannot end the new call');
  r = await callOwner('state', { conversation_id: cid });
  check(r.status === 200 && r.data.call.id === next.id, 'new call stays live through old-call replay');

  invoke('profile', 0);
  r = await callOwner('token', { ...binding(next), client_id: nextOwnerClient });
  check(denied(r), 'revoking public Profile authority denies another media token');
  invoke('profile', 1);
  r = await callOwner('token', { ...binding(next), client_id: nextOwnerClient });
  check(denied(r), 'restoring Profile cannot revive a revoked media session');

  assert.equal((await presence()).status, 200);
  tick();
  r = await callVisitor('offer', { conversation_id: cid, request_id: key(), disclosed: false });
  assert.equal(r.status, 200, r.raw);
  const toDecline = r.data.call;
  r = await callOwner('decline', { ...binding(toDecline), request_id: key() });
  check(r.status === 200 && (!r.data.call || ['declined', 'ended'].includes(r.data.call.status)),
    'recipient decline safely returns both parties to the same text thread');
  r = await profile('poll', { conversation_id: cid }, visitor, publicBase, { method: 'GET' });
  check(r.status === 200 && r.data.messages.some(message => message.sender_type === 'visitor'),
    'call lifecycle preserves the canonical visitor transcript');

  tick();
  const timedOut = (await callVisitor('offer', { conversation_id: cid, request_id: key(), disclosed: false })).data.call;
  tick(31000);
  assert.equal((await presence()).status, 200);
  r = await callOwner('accept', { ...binding(timedOut), request_id: key(), disclosed: false });
  check(denied(r), 'recipient cannot accept a ringing call after its server deadline');
  r = await callOwner('state', binding(timedOut));
  check(r.status === 200 && r.data.call?.id === timedOut.id && r.data.call?.status === 'expired',
    'expired ring remains a truthful terminal state after fresh owner presence');

  tick(61000);
  assert.equal((await presence()).status, 200);
  const blocked = await openCall(cid);
  invoke('block', 1);
  r = await callOwner('token', { ...binding(blocked), client_id: key() });
  check(denied(r), 'owner or visitor block revokes the current call authority');
  invoke('block', 0);
  r = await callOwner('token', { ...binding(blocked), client_id: key() });
  check(denied(r), 'removing a block cannot revive the revoked call');

  invoke('assist', 1);
  r = await callVisitor('state', { conversation_id: cid });
  check(r.status === 200 && r.data.assisted === true && r.data.agent_assisted_phone_chat_enabled === true &&
    r.data.agent_name === 'Fixture Public Guide', 'owner opt-in projects the actual named Profile Agent before the call');
  r = await callVisitor('offer', { conversation_id: cid, request_id: key(), disclosed: false });
  check(denied(r), 'visitor cannot start an assisted call using the obsolete ordinary-call notice');
  tick();
  const assistedOffer = (await callVisitor('offer', { conversation_id: cid, request_id: key(), disclosed: true })).data.call;
  const dispatches = () => requests.filter(item => item.route === '/twirp/livekit.AgentDispatchService/CreateDispatch');
  check(assistedOffer?.assisted === true && dispatches().length === 0, 'assisted ring records disclosure with no worker dispatch');
  r = await callOwner('accept', { ...binding(assistedOffer), request_id: key(), disclosed: false });
  check(denied(r), 'answering owner must accept the assistance mode actually shown on the call');
  r = await callOwner('accept', { ...binding(assistedOffer), request_id: key(), disclosed: true });
  const assistedAccepted = r.data.call;
  check(r.status === 200 && assistedAccepted?.status === 'accepted' && dispatches().length === 0,
    'accepted assisted call waits for both human media heartbeats before dispatch');
  const assistedLive = await connect(assistedAccepted);
  check(dispatches().length === 1, 'assisted live call creates exactly one room-bound worker dispatch');
  const dispatch = dispatches()[0];
  const dispatchMetadata = JSON.parse(dispatch.input.metadata);
  const assistedClaims = verifyJwt(assistedLive.ownerToken.token);
  const serviceGrant = verifyJwt(dispatch.authorization.replace('Bearer ', '')).video;
  check(dispatchMetadata.vp3_profile_call_id === assistedLive.call.id && dispatchMetadata.room_name === assistedClaims.video.room &&
    dispatchMetadata.agent_display_name === 'Fixture Public Guide' && serviceGrant.room === assistedClaims.video.room && serviceGrant.roomAdmin === true,
    'worker dispatch carries the exact call, room and named Agent under a scoped service grant');
  const workerBinding = {
    call_id: assistedLive.call.id, room_name: dispatchMetadata.room_name,
    worker_key: dispatchMetadata.worker_key, worker_id: key(), connected: true, ready: true,
  };
  r = await worker('lease', workerBinding, 'Bearer wrong-secret');
  check(denied(r), 'invalid worker bearer cannot claim any call lease');
  r = await worker('lease', { ...workerBinding, room_name: ownerClaims.video.room });
  check(r.status === 200 && r.data.active === false, 'worker cannot borrow another room for the same call');
  r = await worker('lease', { ...workerBinding, worker_key: key() + key() });
  check(r.status === 200 && r.data.active === false, 'global worker bearer alone cannot claim the exact call');
  r = await worker('lease', workerBinding);
  const lease = r.data;
  check(r.status === 200 && lease.active && lease.capture && lease.lease_seconds === 8 &&
    JSON.stringify(lease.participants) === JSON.stringify([assistedLive.ownerToken.identity, assistedLive.visitorToken.identity]),
    'worker lease contains only the exact two signed human track identities');
  r = await activity(assistedLive.call.id);
  check(r.status === 200 && r.data.state === 'connecting' && r.data.segments.length === 0,
    'dispatched worker cannot light the listening ring before acknowledging the capture revision');
  const currentWorker = { ...workerBinding, capture_revision: lease.capture_revision };
  r = await worker('lease', currentWorker);
  check(r.status === 200 && r.data.active, 'worker acknowledges its exact current media capture revision');
  r = await worker('lease', { ...currentWorker, worker_id: key() });
  check(r.status === 200 && r.data.active === false, 'a second worker cannot replace a fresh lease');

  const firstSegment = {
    ...currentWorker, participant_identity: assistedLive.ownerToken.identity,
    source_key: key() + key(), start_ms: 1000, end_ms: 2200,
    text: 'The owner mentioned the Phoenix launch date.', speaker_name: 'FORGED_SPEAKER_LABEL', confidence: 0.96,
  };
  r = await worker('segment', { ...firstSegment, participant_identity: 'foreign-agent-audio' });
  check(r.status === 200 && r.data.accepted === 0, 'worker rejects Agent audio and unrelated participant tracks');
  r = await worker('segment', firstSegment);
  check(r.status === 200 && r.data.accepted === 1, 'worker stores one finalized segment from the exact owner track');
  r = await worker('segment', firstSegment);
  check(r.status === 200 && r.data.accepted === 0 && r.data.duplicate, 'retried finalized segment is stored exactly once');
  r = await worker('segment', { ...firstSegment, participant_identity: assistedLive.visitorToken.identity,
    start_ms: 1500, end_ms: 2400, text: 'The visitor asked to verify the date.' });
  check(r.status === 200 && r.data.accepted === 1, 'overlapping visitor track retains separate attribution and timing');

  for (const who of [anonymous, visitor, otherOwner]) {
    r = await activity(assistedLive.call.id, who);
    check(denied(r) && !r.raw.includes('Phoenix'), 'private transcript is withheld from actor ' + who.user);
  }
  r = await activity(assistedLive.call.id);
  const transcript = r.data;
  check(r.status === 200 && transcript.state === 'listening' && transcript.agent.name === 'Fixture Public Guide' &&
    transcript.segments.length === 2 && transcript.segments[0].speaker === 'Fixture Owner (owner)' &&
    transcript.segments[0].start_ms === 1000 && transcript.segments[1].speaker === 'Visitor A (visitor)' &&
    transcript.segments[1].start_ms === 1500 && transcript.transcript_url.includes('artist-listening.php') &&
    !r.raw.includes('FORGED_SPEAKER_LABEL') && !r.raw.includes(dispatchMetadata.worker_key) && safe(r.raw),
    'owner sidebar reads actual listening state and two timestamped canonical speaker segments');
  r = await activity(assistedLive.call.id, owner, transcript.cursor);
  check(r.status === 200 && r.data.segments.length === 0 && r.data.cursor === transcript.cursor,
    'cursor reads return only newly finalized segments');

  const toggle = { action: 'set_assistance', call_id: assistedLive.call.id, enabled: false,
    assistance_revision: transcript.assistance_revision };
  r = await request(activityEndpoint, { ...toggle, csrf: 'invalid' }, owner);
  check(denied(r), 'owner assistance pause requires a valid CSRF token');
  r = await request(activityEndpoint, { ...toggle, csrf }, visitor);
  check(denied(r), 'visitor cannot operate the owner-only sidebar controls');
  r = await request(activityEndpoint, { ...toggle, csrf }, owner);
  assert.equal(r.status, 200, r.raw);
  const pausedRevision = r.data.assistance_revision;
  r = await activity(assistedLive.call.id);
  check(r.status === 200 && r.data.state === 'paused' && r.data.assistance_enabled === false &&
    pausedRevision === transcript.assistance_revision + 1 && r.data.assistance_revision === pausedRevision,
    'owner can pause capture while the human call remains live');
  r = await worker('segment', { ...firstSegment, source_key: key() + key(), text: 'Must not save after pause.' });
  check(r.status === 200 && r.data.accepted === 0, 'in-flight finalized segment is fenced after owner pause');
  r = await callOwner('state', { conversation_id: cid });
  check(r.status === 200 && r.data.call?.status === 'live', 'Agent pause preserves the live human connection');
  r = await request(activityEndpoint, { ...toggle, enabled: true, csrf }, owner);
  check(r.status === 409 && safe(r.raw), 'stale sidebar action cannot re-enable paused capture');
  r = await request(activityEndpoint, { ...toggle, enabled: true, assistance_revision: pausedRevision, csrf }, owner);
  assert.equal(r.status, 200, r.raw);
  r = await activity(assistedLive.call.id);
  check(r.status === 200 && r.data.state === 'connecting', 'resumed assistance waits for current worker readiness');
  r = await worker('lease', currentWorker);
  check(r.status === 200 && r.data.capture && r.data.capture_revision > lease.capture_revision,
    'resumed capture receives a different revision from the paused lease');
  r = await worker('segment', { ...firstSegment, source_key: key() + key() });
  check(r.status === 200 && r.data.accepted === 0, 'resume cannot admit stale pre-pause frames');
  r = await callVisitor('end', { ...binding(assistedLive.call), client_id: assistedLive.visitorClient });
  check(r.status === 200, 'participant hang-up ends the assisted call');
  r = await worker('lease', currentWorker);
  check(r.status === 200 && r.data.active === false, 'worker lease is revoked after the exact call ends');
  r = await activity(assistedLive.call.id);
  check(r.status === 200 && r.data.state === 'ended' && r.data.segments.length === 2,
    'owner can finish reviewing retained transcript without a false listening status');
  invoke('assist', 0);

  if (driver === 'mysql') {
    tick(61000);
    assert.equal((await presence()).status, 200);
    const secondOrigin = await startPhpServer();
    const first = callVisitor('offer', { conversation_id: cid, request_id: key(), disclosed: false }, visitor,
      publicBase, { headers: { 'X-Fixture-Race': 'hold' } });
    let competing;
    try {
      await waitForFile(path.join(temp, 'race-locked'));
      competing = callVisitor('offer', { conversation_id: otherCid, request_id: key(), disclosed: false }, foreign,
        foreignBase, { origin: secondOrigin, headers: { 'X-Fixture-Race': 'compete' } });
      await waitForFile(path.join(temp, 'race-attempted'));
      const waiting = await Promise.race([competing.then(() => false), new Promise(resolve => setTimeout(resolve, 150, true))]);
      check(waiting, 'independent SQL connection waits for the active owner call mutex');
    } finally {
      fs.writeFileSync(path.join(temp, 'race-release'), 'release');
    }
    const [winner, loser] = await Promise.all([first, competing]);
    check(winner.status === 200 && winner.data.call?.status === 'ringing' && loser.status === 409 &&
      loser.data.code === 'CALL_BUSY' && safe(loser.raw), 'concurrent offers from two visitors have exactly one winner');
    r = await callOwner('state', { conversation_id: 0 });
    check(r.status === 200 && r.data.call.id === winner.data.call.id,
      'owner sees exactly the winning call after independent SQL transactions');
    assert.equal((await callVisitor('end', binding(winner.data.call))).status, 200);
  }

  tick(61000);
  assert.equal((await presence()).status, 200);
  const passwordRevoked = await openCall(cid);
  invoke('password', 'changed-visitor-a-password');
  r = await callOwner('token', { ...binding(passwordRevoked), client_id: key() });
  check(denied(r), 'visitor credential rotation invalidates the exact accepted call binding');

  console.log(`PROFILE_CALL_API_PA3C=PASS (${checks} checks; ${driver})`);
} finally {
  await Promise.all(servers.filter(server => server.exitCode === null && server.signalCode === null).map(server => {
    server.kill();
    return once(server, 'exit');
  }));
  if (mock.listening) await new Promise(resolve => mock.close(resolve));
  if (fs.existsSync(config)) invoke('cleanup');
  fs.rmSync(temp, { recursive: true, force: true });
}
