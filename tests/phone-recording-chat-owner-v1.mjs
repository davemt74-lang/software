import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { randomUUID } from 'node:crypto';

const source = readFileSync(new URL('../phone-recording-chat-owner-v1.js', import.meta.url), 'utf8');
const endpoint = '/studio/api/phone-camera-v1.php';
const scope = {owner_user_id:42, agent_id:12, conversation_id:7, device_id:'d'.repeat(32), session_id:'a'.repeat(32)};
const flush = () => new Promise(resolve => setImmediate(resolve));

class Element extends EventTarget {
  dataset = {};
  children = [];
  isConnected = true;
  textContent = '';
  setAttribute(name, value) { this[name] = value; }
  removeAttribute(name) { delete this[name]; }
  replaceChildren(...nodes) { this.children = nodes; }
  querySelector(selector) { return selector === '.phone-chat-live-recording' ? this.host : null; }
  click() { this.dispatchEvent(new Event('click')); }
}

function setup() {
  const host = new Element(), panel = new Element();
  panel.host = host;
  const page = {owner:42, agent:12, conversation:7};
  const calls = [], timers = new Map();
  let timerId = 0;
  const window = {
    STONEFELLOW_CHAT:{csrf:'owner-csrf', workingAttentionBinding:'binding'},
    STONEFELLOW_CHAT_CONTINUITY:{phoneCameraScope:() => page},
    VP3PhoneCameraPreview:{post(url, headers, payload, signal) {
      assert.equal(url, endpoint);
      assert.equal(headers['X-CSRF-Token'], 'owner-csrf');
      assert.equal(headers['X-VP3-Working-Attention'], 'binding');
      return new Promise((resolve, reject) => calls.push({payload, signal, resolve, reject}));
    }},
  };
  runInNewContext(source, {
    window, document:{hidden:false, createElement:() => new Element()}, URL, AbortController, crypto:{randomUUID},
    location:new URL('https://owner.example/studio/chat.php'),
    setTimeout(callback) { timers.set(++timerId, callback); return timerId; }, clearTimeout(id) { timers.delete(id); },
  });
  const close = window.VP3PhoneRecordingOwner.bind(panel, {scope, endpoint, csrf:'owner-csrf', binding:'binding'});
  function poll() {
    assert.equal(timers.size, 1);
    const [[id, callback]] = timers;
    timers.delete(id);
    callback();
    return calls.at(-1);
  }
  const [status, link, button] = host.children;
  return {host, panel, status, link, button, page, calls, timers, close, poll};
}

function take(state, key, extras = {}) {
  return {id:'7'.repeat(32), request_id:key, device_id:scope.device_id, session_id:scope.session_id, state, ...extras};
}

async function respond(call, recording) {
  call.resolve({ok:true, recording});
  await flush();
}

{
  const ui = setup();
  assert.deepEqual({...ui.calls[0].payload}, {action:'camera_record_status', agent_id:12, conversation_id:7, device_id:scope.device_id, session_id:scope.session_id});
  assert.ok(ui.button.disabled);
  await respond(ui.calls[0], null);
  assert.equal(ui.button.textContent, 'Request recording');
  assert.equal(ui.button.disabled, false);
  ui.button.click();
  ui.button.click();
  assert.equal(ui.calls.length, 2, 'only the first explicit click starts a request');
  const key = ui.calls[1].payload.request_id;
  assert.match(key, /^[a-f0-9]{32}$/);
  assert.deepEqual({...ui.calls[1].payload}, {...ui.calls[0].payload, action:'camera_record_request', request_id:key});
  ui.calls[1].reject(Error('Lost acknowledgement'));
  await flush();
  await respond(ui.poll(), null);
  assert.equal(ui.button.textContent, 'Retry recording request');
  ui.button.click();
  assert.equal(ui.calls[2].payload.action, 'camera_record_status');
  assert.equal(ui.calls[3].payload.request_id, key);
  await respond(ui.calls[3], take('requested', key));
  for (const state of ['accepted', 'reviewing', 'accepted', 'reviewing']) {
    assert.ok(ui.button.hidden);
    assert.ok(ui.link.hidden);
    await respond(ui.poll(), take(state, key));
    assert.equal(ui.host.dataset.state, state);
  }
  await respond(ui.poll(), take('submitted', key, {asset_id:91, file_url:'/studio/media-library-file-v86.php?id=91'}));
  assert.equal(ui.host.dataset.state, 'submitted');
  assert.equal(ui.link.href, 'https://owner.example/studio/media-library-file-v86.php?id=91');
  assert.equal(ui.link.hidden, false);
  assert.equal(ui.button.textContent, 'Request another recording');
  for (const invalid of [
    'https://other.example/studio/media-library-file-v86.php?id=91', '/studio/delete.php?id=91',
    '/studio/media-library-file-v86.php?id=93', '/studio/media-library-file-v86.php?id=91&redirect=1',
  ]) {
    await respond(ui.poll(), take('submitted', key, {asset_id:91, file_url:invalid}));
    assert.ok(ui.link.hidden);
    assert.equal(ui.link.href, undefined);
  }
  ui.button.click();
  const nextKey = ui.calls.at(-1).payload.request_id;
  assert.notEqual(nextKey, key);
  await respond(ui.calls.at(-1), take('requested', nextKey));
  await respond(ui.poll(), take('declined', nextKey));
  assert.equal(ui.button.hidden, false);
  assert.match(ui.status.textContent, /declined/);
  const missed = ui.poll();
  ui.page.conversation = 8;
  await respond(missed, take('submitted', nextKey, {asset_id:91,file_url:'/studio/media-library-file-v86.php?id=91'}));
  assert.equal(ui.host.children.length, 0);
  assert.equal(ui.timers.size, 0);
  assert.ok(missed.signal.aborted);
}

{
  const ui = setup();
  await respond(ui.calls[0], null);
  const oldStatus = ui.poll();
  ui.button.click();
  const pending = ui.calls.at(-1);
  const key = pending.payload.request_id;
  pending.reject(Error('Lost response'));
  await flush();
  await respond(ui.poll(), take('accepted', key));
  await respond(oldStatus, null);
  assert.equal(ui.host.dataset.state, 'accepted', 'an older poll cannot overwrite a newer request');
  assert.equal(ui.calls.filter(call => call.payload.action === 'camera_record_request').length, 1);
  const read = ui.poll();
  read.reject(Object.assign(Error('Access revoked'), {status:403}));
  await flush();
  assert.equal(ui.host.children.length, 0);
  assert.equal(ui.timers.size, 0);
}

{
  const ui = setup();
  const read = ui.calls[0];
  ui.close();
  await respond(read, take('requested', '1'.repeat(32)));
  assert.ok(read.signal.aborted);
  assert.equal(ui.host.children.length, 0);
  assert.equal(ui.timers.size, 0);
}

console.log('Owner recording requests are scoped to one phone, retriable, private, and cleaned up on context loss.');
