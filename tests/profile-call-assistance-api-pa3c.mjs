import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { once } from 'node:events';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';

// Production HTTP, canonical call authority and SQL. The isolated fixtures
// supply browser identities, time and provider responses.
const root = process.cwd();
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-call-assistance-'));
const config = path.join(temp, 'fixture.json');
const rooms = path.join(temp, 'rooms.json');
const dispatchLog = path.join(temp, 'dispatches.jsonl');
const php = process.env.VP3_PHP_BINARY || 'php';
const dbFixture = path.resolve('tests/fixtures/profile-call-assistance-api-pa3c-db.php');
const workerSecret = 'fixture-only-profile-call-worker-secret';
const csrf = 'pa3-fixture-csrf';
const env = {
  ...process.env,
  VP3_PA3_ROOT: root,
  VP3_PA3_CONFIG: config,
  VP3_PA3C_ROOMS: rooms,
  VP3_PA3C_PROVIDER_LOG: path.join(temp, 'provider.jsonl'),
  VP3_PA3C_ASSISTANCE_DISPATCHES: dispatchLog,
  VP3_PROFILE_CALL_AGENT_ENABLED: '1',
  VP3_PROFILE_CALL_AGENT_URL: 'wss://fixture.livekit.cloud',
  VP3_PROFILE_CALL_AGENT_NAME: 'fixture-profile-agent',
  VP3_PROFILE_CALL_WORKER_SECRET: workerSecret,
};
let now = Date.now();
let checks = 0;
let server;
let origin;
let logs = '';

const callEndpoint = 'api/profile-calls-pa3c.php';
const activityEndpoint = 'api/profile-call-activity-pa3c.php';
const workerEndpoint = 'api/profile-call-worker-pa3c.php';
const key = () => randomBytes(16).toString('hex');
const owner = { user: 1, browser: 'a', session: 'owner' };
const visitor = { user: 2, browser: 'b', session: 'visitor' };
const stranger = { user: 4, browser: 'c', session: 'stranger' };
const guest = { user: 0, browser: 'd', session: 'guest' };

function invoke(action, ...args) {
  const r = spawnSync(php, [dbFixture, action, config, ...args.map(String)], { env, encoding: 'utf8' });
  assert.equal(r.status, 0, r.stderr || r.stdout);
  return r.stdout;
}

function check(value, label) {
  assert.ok(value, label);
  checks++;
  console.log('PASS ' + label);
}

async function request(endpoint, method, fields, who, headers = {}) {
  const read = method === 'GET';
  const response = await fetch(`${origin}/${endpoint}${read ? '?' + new URLSearchParams(fields) : ''}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-Fixture-User': String(who.user),
      'X-Fixture-Now': String(now),
      Cookie: `PHPSESSID=assist${who.session}; sf_profile_visitor=${who.browser.repeat(64)}; sf_profile_conversation=${who.browser.repeat(64)}`,
      ...headers,
    },
    ...(!read ? { body: JSON.stringify(fields) } : {}),
    signal: AbortSignal.timeout(12000),
  });
  const raw = await response.text();
  let data;
  try {
    data = JSON.parse(raw);
  } catch {
    throw Error(`Invalid ${response.status} for ${endpoint} ${fields.action || ''}: ${raw}\n${logs.slice(-4000)}`);
  }
  return { status: response.status, data, raw };
}

async function startServer() {
  const reservation = net.createServer();
  reservation.listen(0, '127.0.0.1');
  await once(reservation, 'listening');
  const port = reservation.address().port;
  await new Promise(resolve => reservation.close(resolve));
  server = spawn(php, ['-d', 'display_errors=0', '-d', 'session.save_path=' + path.join(temp, 'sessions'),
    '-S', `127.0.0.1:${port}`, '-t', temp], { env, stdio: ['ignore', 'ignore', 'pipe'] });
  server.stderr.on('data', value => { logs += value; });
  origin = `http://127.0.0.1:${port}`;
  const deadline = Date.now() + 5000;
  while (Date.now() < deadline && server.exitCode === null && server.signalCode === null) {
    try {
      const response = await fetch(origin + '/ready.txt', { signal: AbortSignal.timeout(500) });
      if (response.ok && await response.text() === 'ready') return;
    } catch {
      // The PHP listener can still be starting.
    }
    await sleep(25);
  }
  throw Error('PHP fixture server did not become ready.\n' + logs.slice(-4000));
}

try {
  const driver = invoke('init').trim();
  for (const dir of ['includes', 'api', 'sessions']) fs.mkdirSync(path.join(temp, dir));
  for (const name of fs.readdirSync('includes')) {
    if (name !== 'bootstrap.php') fs.symlinkSync(path.resolve('includes', name), path.join(temp, 'includes', name));
  }
  for (const endpoint of [callEndpoint, activityEndpoint, workerEndpoint]) {
    fs.copyFileSync(endpoint, path.join(temp, endpoint));
  }
  fs.copyFileSync('tests/fixtures/profile-call-assistance-api-pa3c-bootstrap.php', path.join(temp, 'includes/bootstrap.php'));
  fs.writeFileSync(path.join(temp, 'ready.txt'), 'ready');
  fs.writeFileSync(path.join(temp, 'boot.php'), `<?php
require __DIR__.'/includes/bootstrap.php';
header('Content-Type: application/json');
$context=vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'fixture-owner'),current_user());
echo json_encode(['token'=>profile_chat_token(1),'namespace'=>hash('sha256','profile-widget-v1|'.$context['conversation_authority']['credential_hash'])],JSON_THROW_ON_ERROR);
`);
  await startServer();
  now = Date.now();
  const boot = await request('boot.php', 'GET', {}, visitor);
  assert.equal(boot.status, 200, boot.raw);
  const publicInput = { username: 'fixture-owner', profile_token: boot.data.token, conversation_namespace: boot.data.namespace };
  const ownerInput = { csrf_token: csrf, chat_conversation_id: 11, chat_agent_id: 7 };
  const visitorCall = (action, fields = {}) => request(callEndpoint, action === 'state' ? 'GET' : 'POST',
    { ...publicInput, action: 'visitor_' + action, ...fields }, visitor);
  const ownerCall = (action, fields = {}) => request(callEndpoint, action === 'state' ? 'GET' : 'POST',
    { ...ownerInput, action: 'owner_' + action, ...fields }, owner);
  const activity = (callId, who = owner, after = 0) =>
    request(activityEndpoint, 'GET', { call_id: callId, after }, who);
  const worker = (action, fields, secret = workerSecret) =>
    request(workerEndpoint, 'POST', { ...fields, action }, guest, { Authorization: 'Bearer ' + secret });
  const dispatches = () => fs.existsSync(dispatchLog)
    ? fs.readFileSync(dispatchLog, 'utf8').trim().split('\n').map(JSON.parse)
    : [];
  const state = () => JSON.parse(invoke('state'));
  const binding = call => ({ call_id: call.call_id, conversation_id: call.conversation_id, revision: call.revision });
  const ok = result => {
    assert.equal(result.status, 200, result.raw + '\n' + logs.slice(-1200));
    return result.data;
  };

  let view = ok(await visitorCall('state', { conversation_id: 0 }));
  check(view.can_offer && !view.assistance_offer, 'ordinary calling is available with assistance off by default');
  invoke('assist', 1);
  view = ok(await visitorCall('state', { conversation_id: 0 }));
  const notice = view.assistance_offer?.notice;
  check(/^[a-f0-9]{64}$/.test(notice) && view.assistance_offer.agent.name === 'Fixture Public Guide',
    'visitor sees the exact named Agent and a pre-call notice before any room or dispatch');
  check(!fs.existsSync(rooms) && dispatches().length === 0, 'read-only notice never starts media or capture');

  let conversationId = 0;
  for (const [label, fields] of [
    ['legacy offer with no notice', { assisted: true }],
    ['offer with a stale notice', { assistance_notice: '0'.repeat(64) }],
  ]) {
    const offered = ok(await visitorCall('offer', {
      conversation_id: conversationId, mode: 'audio', request_id: key(), ...fields,
    })).call;
    conversationId = offered.conversation_id;
    check(offered.state === 'offered' && !offered.assistance, label + ' remains an ordinary call');
    ok(await visitorCall('cancel', { ...binding(offered), request_id: key() }));
  }
  const video = ok(await ownerCall('offer', {
    conversation_id: conversationId, mode: 'video', request_id: key(), assistance_notice: notice,
  })).call;
  check(video.mode === 'video' && !video.assistance, 'video preserves the human-only path despite a voice notice');
  ok(await visitorCall('decline', { ...binding(video), request_id: key() }));

  view = ok(await visitorCall('state', { conversation_id: conversationId }));
  let call = ok(await visitorCall('offer', {
    conversation_id: conversationId, mode: 'audio', request_id: key(), assistance_notice: view.assistance_offer.notice,
  })).call;
  check(call.state === 'offered' && call.assistance?.agent.name === 'Fixture Public Guide' &&
    !Object.hasOwn(call.assistance, 'id') && dispatches().length === 0, 'normal disclosed offer binds assistance without exposing the private drawer');
  const delivery = ok(await ownerCall('delivery', binding(call)));
  const ownerBound = (action, extra = {}) => ownerCall(action, { ...binding(call), delivery_id: delivery.delivery_id, request_id: key(), ...extra });
  const visitorBound = (action, extra = {}) => visitorCall(action, { ...binding(call), request_id: key(), ...extra });
  call = ok(await ownerBound('accept')).call;
  const callId = call.assistance?.id;
  check(call.state === 'preparing' && /^[a-f0-9]{32}$/.test(callId) && dispatches().length === 0,
    'normal owner acceptance needs no extra prompt and does not start capture');
  call = ok(await ownerBound('dispatch')).call;
  const ownerJoin = ok(await ownerBound('join', { client_instance_id: key(), microphone: true, camera: false }));
  call = ownerJoin.call;
  check(dispatches().length === 0, 'one connected human never dispatches the Agent');
  const visitorJoin = ok(await visitorBound('join', { client_instance_id: key(), microphone: true, camera: false }));
  call = visitorJoin.call;
  now += 10500;
  call = ok(await ownerBound('dispatch')).call;
  const [dispatch] = dispatches();
  check(call.state === 'active' && dispatches().length === 1, 'verified two-human connection dispatches exactly one Agent');
  check(dispatch.binding.call_id === call.call_id && dispatch.binding.public_id === callId &&
    dispatch.binding.room_name === 'vp3-profile-' + callId && dispatch.binding.mode === 'audio' &&
    dispatch.name === 'fixture-profile-agent' && dispatch.metadata.vp3_profile_call_id === callId &&
    dispatch.metadata.room_name === dispatch.binding.room_name &&
    dispatch.metadata.agent_display_name === 'Fixture Public Guide' &&
    /^[a-f0-9]{64}$/.test(dispatch.metadata.worker_key),
  'dispatch binds the exact canonical audio room and keeps the call key off the public projection');

  const leaseInput = {
    call_id: callId, room_name: dispatch.metadata.room_name, worker_key: dispatch.metadata.worker_key,
    worker_id: key(), connected: true, ready: true,
  };
  const wrongBearer = await worker('lease', leaseInput, 'wrong-worker-secret');
  check(wrongBearer.status === 401, 'invalid worker bearer cannot obtain a lease');
  check(ok(await worker('lease', { ...leaseInput, room_name: 'vp3-profile-' + key() })).active === false,
    'worker cannot borrow another room for the same call');
  check(ok(await worker('lease', { ...leaseInput, worker_key: key() + key() })).active === false,
    'global worker bearer alone cannot access a call');
  const lease = ok(await worker('lease', leaseInput));
  check(lease.active && lease.capture && lease.lease_seconds === 8 &&
    JSON.stringify(lease.participants) === JSON.stringify([ownerJoin.participant_identity, visitorJoin.participant_identity]),
  'call lease returns only the two canonical human microphone identities');
  const currentWorker = { ...leaseInput, capture_revision: lease.capture_revision };
  check(ok(await activity(callId)).state === 'connecting', 'drawer stays connecting until the worker acknowledges the capture revision');
  check(ok(await worker('lease', currentWorker)).active, 'worker acknowledges the current revision');
  check(ok(await worker('lease', { ...currentWorker, worker_id: key() })).active === false,
    'another worker cannot replace a fresh lease');

  const segment = {
    ...currentWorker, participant_identity: ownerJoin.participant_identity, source_key: key() + key(),
    start_ms: 1000, end_ms: 2200, text: 'Private Phoenix call transcript', speaker_name: 'FORGED_SPEAKER_LABEL', confidence: 0.96,
  };
  check(ok(await worker('segment', { ...segment, participant_identity: 'agent-' + callId })).accepted === 0,
    'the Agent identity cannot submit a human transcript');
  check(ok(await worker('segment', segment)).accepted === 1, 'exact owner audio segment is stored');
  const replay = ok(await worker('segment', segment));
  check(replay.accepted === 0 && replay.duplicate, 'a repeated finalized segment is stored exactly once');
  check(ok(await worker('segment', { ...segment, participant_identity: visitorJoin.participant_identity,
    start_ms: 1500, end_ms: 2400, text: 'Visitor speech on its own track' })).accepted === 1,
  'overlapping visitor audio retains separate attribution');
  for (const who of [visitor, stranger, guest]) {
    const denied = await activity(callId, who);
    check(denied.status >= 400 && denied.status < 500 && !denied.raw.includes(segment.text), 'actor ' + who.user + ' cannot read the private transcript');
  }
  const activityResponse = await activity(callId);
  const transcript = ok(activityResponse);
  check(transcript.state === 'listening' && transcript.segments.length === 2 &&
    transcript.segments[0].speaker === 'Fixture Owner (owner)' &&
    transcript.segments[1].speaker === 'Visitor A (visitor)' &&
    !activityResponse.raw.includes('FORGED_SPEAKER_LABEL') && !activityResponse.raw.includes(dispatch.metadata.worker_key),
  'owner receives canonical speaker labels and private transcript without worker credentials');
  check(ok(await activity(callId, owner, transcript.cursor)).segments.length === 0, 'drawer cursor returns only new finalized segments');

  const toggle = { action: 'set_assistance', call_id: callId, enabled: false, assistance_revision: transcript.assistance_revision };
  check((await request(activityEndpoint, 'POST', { ...toggle, csrf: 'invalid' }, owner)).status === 419,
    'pause requires the current owner CSRF token');
  const roomBeforePause = JSON.parse(fs.readFileSync(rooms, 'utf8'));
  const paused = ok(await request(activityEndpoint, 'POST', { ...toggle, csrf }, owner));
  check(ok(await activity(callId)).state === 'paused' && paused.assistance_revision === transcript.assistance_revision + 1,
    'pause advances the capture revision and updates the owner drawer');
  check(ok(await worker('segment', { ...segment, source_key: key() + key() })).accepted === 0,
    'in-flight pre-pause audio cannot write a transcript segment');
  const callWhilePaused = state().profile_calls_pa3c.find(row => Number(row.id) === call.call_id);
  check(callWhilePaused.state === 'active' && Number(callWhilePaused.revision) === call.revision &&
    JSON.stringify(JSON.parse(fs.readFileSync(rooms, 'utf8'))) === JSON.stringify(roomBeforePause),
  'Agent pause preserves the canonical call and provider room');
  check((await request(activityEndpoint, 'POST', { ...toggle, csrf, enabled: true }, owner)).status === 409,
    'stale drawer action cannot re-enable paused capture');
  ok(await request(activityEndpoint, 'POST', { ...toggle, csrf, enabled: true, assistance_revision: paused.assistance_revision }, owner));
  const resumed = ok(await worker('lease', currentWorker));
  check(resumed.capture && resumed.capture_revision > paused.assistance_revision &&
    ok(await worker('segment', { ...segment, source_key: key() + key() })).accepted === 0,
  'resume grants a new revision and still rejects pre-pause audio');

  now += 15001;
  const replacementWorker = { ...leaseInput, worker_id: key(), capture_revision: resumed.capture_revision };
  check(ok(await worker('lease', replacementWorker)).active === false, 'expired provider observation blocks audio even before the human leases expire');
  call = ok(await ownerBound('dispatch')).call;
  check(call.state === 'active' && ok(await worker('lease', replacementWorker)).active,
    'fresh exact provider proof restores a scoped worker lease without rebuilding the room');
  call = ok(await ownerBound('end')).call;
  check(call.state === 'ending' && ok(await worker('lease', replacementWorker)).active === false,
    'normal human hang-up revokes the worker lease immediately');
  const retained = ok(await activity(callId));
  check(retained.state === 'ended' && retained.segments.length === 2, 'the owner can review finalized transcript after ending the call');
  now += 10000;
  call = ok(await ownerBound('dispatch')).call;
  check(call.state === 'ended' && !Object.hasOwn(JSON.parse(fs.readFileSync(rooms, 'utf8')), dispatch.metadata.room_name),
    'the canonical cleanup job closes the exact assisted room');

  for (const disclosed of [false, true]) {
    const ownerView = ok(await ownerCall('state', { conversation_id: conversationId }));
    const outgoing = ok(await ownerCall('offer', {
      conversation_id: conversationId, mode: 'audio', request_id: key(), assistance_notice: ownerView.assistance_offer.notice,
    })).call;
    const visitorView = ok(await visitorCall('state', { conversation_id: conversationId }));
    call = visitorView.calls.find(item => item.call_id === outgoing.call_id);
    const incoming = call.assistance;
    check(call.state === 'offered' && incoming?.agent.name === 'Fixture Public Guide' &&
      /^[a-f0-9]{64}$/.test(incoming.notice) && !Object.hasOwn(incoming, 'id'),
    'visitor sees the named Agent on the incoming call before ordinary acceptance');
    call = ok(await visitorBound('accept', disclosed ? { assistance_notice: incoming.notice } : {})).call;
    check(call.state === 'preparing' && Boolean(call.assistance) === disclosed && dispatches().length === 1,
      disclosed ? 'incoming normal acceptance binds the notice without an extra prompt' :
        'legacy incoming acceptance downgrades to the ordinary call without failing');
    call = ok(await visitorBound('end')).call;
    now += 10000;
    call = ok(await visitorBound('dispatch')).call;
    assert.equal(call.state, 'ended');
  }
  console.log(`PROFILE_CALL_ASSISTANCE_API_PA3C=PASS (${checks} checks; ${driver})`);
} finally {
  if (server && server.exitCode === null && server.signalCode === null) {
    server.kill('SIGTERM');
    await once(server, 'exit');
  }
  if (fs.existsSync(config)) invoke('cleanup');
  fs.rmSync(temp, { recursive: true, force: true });
}
