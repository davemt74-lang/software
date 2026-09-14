import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const header = fs.readFileSync('includes/member-header.php', 'utf8');
const bridge = fs.readFileSync('vp3-authenticated-runtime-bridge.js', 'utf8');

assert.match(header, /vp3-authenticated-runtime-bridge\.js/, 'member header must load the VP3 authenticated runtime bridge');
assert.match(header, /window\.VP3_NOTIFICATION_DRAWER=/, 'notifications must emit canonical VP3 config');
assert.match(header, /window\.STONEFELLOW_NOTIFICATION_DRAWER=window\.VP3_NOTIFICATION_DRAWER/, 'notifications must retain legacy compatibility alias');
assert.match(header, /window\.VP3_RECORDINGS_V198_CONFIG=/, 'recordings must emit canonical VP3 config');
assert.match(header, /window\.STONEFELLOW_RECORDINGS_V198_CONFIG=window\.VP3_RECORDINGS_V198_CONFIG/, 'recordings must retain legacy compatibility alias');

const requiredPairs = [
  ['VP3_NOTIFICATION_DRAWER', 'STONEFELLOW_NOTIFICATION_DRAWER'],
  ['VP3_RECORDINGS_V198_CONFIG', 'STONEFELLOW_RECORDINGS_V198_CONFIG'],
  ['VP3_ARTIST_LISTENING_CONFIG', 'STONEFELLOW_ARTIST_LISTENING_CONFIG'],
  ['VP3_ARTIST_LISTENING_V172', 'STONEFELLOW_ARTIST_LISTENING_V172'],
  ['VP3_ARTIST_RECORDINGS_V198', 'STONEFELLOW_ARTIST_RECORDINGS_V198'],
  ['VP3_AGENT_CONTEXT', 'STONEFELLOW_AGENT_CONTEXT'],
  ['VP3_PROFILE_AGENT', 'STONEFELLOW_PROFILE_AGENT'],
];
for (const [primary, legacy] of requiredPairs) {
  assert.match(bridge, new RegExp(`\\['${primary}', '${legacy}'\\]`), `${primary} must bridge ${legacy}`);
}
assert.match(bridge, /chat-notification-drawer-head small/, 'bridge must normalize the authenticated Activity Center brand');
assert.match(bridge, /label\.textContent = 'VP3'/, 'authenticated Activity Center must display VP3');

const sandbox = {window: {}};
vm.runInNewContext(bridge, sandbox, {filename: 'vp3-authenticated-runtime-bridge.js'});
sandbox.window.STONEFELLOW_PROFILE_AGENT = {username:'legacy'};
assert.equal(sandbox.window.VP3_PROFILE_AGENT.username, 'legacy', 'legacy assignment must flow to canonical VP3 namespace');
sandbox.window.VP3_NOTIFICATION_DRAWER = {endpoint:'/api/test'};
assert.equal(sandbox.window.STONEFELLOW_NOTIFICATION_DRAWER.endpoint, '/api/test', 'canonical assignment must remain visible to legacy consumers');

console.log('Authenticated VP3 runtime cleanup contract passed.');
