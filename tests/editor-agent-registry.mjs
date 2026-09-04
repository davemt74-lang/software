import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const registrySource = fs.readFileSync(new URL('../editor-agent.js', import.meta.url), 'utf8');
const stemSource = fs.readFileSync(new URL('../admin/stem-agent-v131.js', import.meta.url), 'utf8');
const videoSource = fs.readFileSync(new URL('../editor-agent-v131.js', import.meta.url), 'utf8');

class FakeCustomEvent {
  constructor(type, init = {}) {
    this.type = type;
    this.detail = init.detail;
  }
}

const events = [];
const sandbox = {
  console,
  Date,
  JSON,
  Math,
  Promise,
  Set,
  Map,
  Object,
  Array,
  String,
  Number,
  Boolean,
  Error,
  CustomEvent: FakeCustomEvent,
  structuredClone: globalThis.structuredClone,
  dispatchEvent(event) {
    events.push(event);
    return true;
  }
};
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(registrySource, sandbox, { filename: 'editor-agent.js' });

const EditorAgent = sandbox.StonefellowEditorAgent;
assert.ok(EditorAgent, 'canonical Editor Agent global must exist');
assert.equal(EditorAgent.build, 'editor-agent-canonical-20260903');
assert.equal(sandbox.STONEFELLOW_EDITOR_AGENT?.owner, 'editor-agent.js');
assert.ok(events.some(event => event.type === 'stonefellow:editor-agent:ready'));

let state = { value: 1, selected_id: 'clip-a' };
let executions = 0;
const adapter = {
  label: 'Demo Editor',
  commands: () => [
    { id: 'demo.value.set', legacyType: 'set_value', args: ['value'], mutates: true, verifiable: true },
    { id: 'demo.transport.play', legacyType: 'play', args: [], mutates: true, verifiable: false }
  ],
  selection: () => ({ current: { id: state.selected_id }, items: [{ id: 'clip-a' }, { id: 'clip-b' }] }),
  snapshot: () => ({ ...state }),
  normalizeCommand: (command, descriptor) => ({ ...command, type: descriptor.legacyType, editor_command_id: descriptor.id }),
  execute: command => {
    executions += 1;
    if (command.type === 'set_value') {
      state.value = Number(command.value);
      return { status: 'success', result: 'Value updated', verified: false };
    }
    if (command.type === 'play') return { status: 'success', result: 'Playback dispatched', verified: false };
    return { status: 'unsupported', result: 'Unsupported', verified: false };
  },
  verify: (command, before, after, raw) => {
    if (command.type === 'set_value') {
      const ok = Number(after.value) === Number(command.value) && Number(before.value) !== Number(after.value);
      return { status: ok ? 'success' : 'failed', result: ok ? 'Value verified' : 'Value not verified', verified: ok };
    }
    if (command.type === 'play') return { ...raw, status: 'success', verified: false };
    return raw;
  }
};

assert.equal(EditorAgent.registerSurface('demo', adapter), 'demo');
assert.equal(EditorAgent.hasSurface('demo'), true);
assert.throws(() => EditorAgent.registerSurface('demo', adapter), /already registered/);

const inventory = EditorAgent.commandInventory('demo');
assert.deepEqual(Array.from(inventory, row => row.id), ['demo.value.set', 'demo.transport.play']);
assert.equal(inventory[0].legacyType, 'set_value');

const inspection = await EditorAgent.inspect('demo');
assert.equal(inspection.surface, 'demo');
assert.equal(inspection.selection.current.id, 'clip-a');
assert.equal(inspection.state.value, 1);
assert.equal(inspection.commands.length, 2);

const legacyExecution = await EditorAgent.execute({ surface: 'demo', command: { type: 'set_value', value: 7 } });
assert.equal(legacyExecution.commandId, 'demo.value.set');
assert.equal(legacyExecution.command.type, 'set_value');
assert.equal(legacyExecution.status, 'success');
assert.equal(legacyExecution.verified, true);
assert.equal(legacyExecution.before.value, 1);
assert.equal(legacyExecution.after.value, 7);
assert.equal(state.value, 7);

const namespacedExecution = await EditorAgent.execute({ surface: 'demo', command: { id: 'demo.value.set', value: 9 } });
assert.equal(namespacedExecution.commandId, 'demo.value.set');
assert.equal(namespacedExecution.status, 'success');
assert.equal(namespacedExecution.verified, true);
assert.equal(state.value, 9);

const unverifiedExecution = await EditorAgent.execute({ surface: 'demo', command: { type: 'play' } });
assert.equal(unverifiedExecution.commandId, 'demo.transport.play');
assert.equal(unverifiedExecution.status, 'unverified', 'success without verification must never be reported as complete');
assert.equal(unverifiedExecution.verified, false);

const beforeUnknownExecutions = executions;
const unknownExecution = await EditorAgent.execute({ surface: 'demo', command: { type: 'does_not_exist' } });
assert.equal(unknownExecution.status, 'unsupported');
assert.equal(executions, beforeUnknownExecutions, 'unknown commands must not reach the adapter executor');

const missingSurface = await EditorAgent.execute({ surface: 'missing', command: { type: 'play' } });
assert.equal(missingSurface.status, 'unsupported');

const proof = EditorAgent.proof();
assert.equal(proof.build, 'editor-agent-canonical-20260903');
assert.ok(proof.surfaces.includes('demo'));
assert.ok(proof.executions.some(row => row.commandId === 'demo.value.set' && row.verified));
assert.ok(proof.executions.some(row => row.commandId === 'demo.transport.play' && row.status === 'unverified'));

assert.match(stemSource, /import\('\/editor-agent\.js\?v=editor-agent-canonical-20260903'\)/, 'Stem must bootstrap the canonical unversioned registry');
assert.match(videoSource, /import\('\/editor-agent\.js\?v=editor-agent-canonical-20260903'\)/, 'Video must bootstrap the canonical unversioned registry');
assert.match(stemSource, /EditorAgent\.registerSurface\('stem'/);
assert.match(videoSource, /EditorAgent\.registerSurface\('video'/);
assert.match(stemSource, /stem\.track\.mute/);
assert.match(stemSource, /stem\.clip\.split/);
assert.match(stemSource, /stem\.history\.undo/);
assert.match(videoSource, /video\.clip\.split/);
assert.match(videoSource, /video\.project\.save/);
assert.match(stemSource, /EditorAgent\.execute\(\{surface:'stem'/);
assert.match(videoSource, /EditorAgent\.execute\(\{surface:'video'/);
assert.match(stemSource, /StonefellowConversationVoiceV122/);
assert.match(videoSource, /StonefellowConversationVoiceV122/);
assert.match(stemSource, /window\.STONEFELLOW_CHAT_CONTINUITY=continuity/);
assert.match(videoSource, /window\.STONEFELLOW_CHAT_CONTINUITY=continuity/);
assert.doesNotMatch(stemSource, /import\([^)]*editor-agent-v\d+/i);
assert.doesNotMatch(videoSource, /import\([^)]*editor-agent-v\d+/i);

console.log('Universal Editor Agent registry contracts passed.');
