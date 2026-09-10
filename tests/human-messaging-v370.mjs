import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const lifecycle = read('includes/human-messaging-v370.php');
const blockLifecycle = read('includes/human-messaging-block-v370.php');
const social = read('includes/social-network-v320.php');
const api = read('api/messages-v320.php');
const team = read('api/team-chat-v320.php');
const legacyTeam = read('api/team-chat-v109.php');
const js = read('messages-v320.js');
const css = read('messages-v320.css');
const bootstrap = read('includes/bootstrap.php');
const setup = read('setup.php');
const upgrade = read('upgrade.php');
const architecture = read('docs/VP3_PLATFORM_ARCHITECTURE_V320.md');

// Canonical persistence + transaction-safe schema installation.
for (const table of [
  'human_conversations',
  'human_conversation_members',
  'human_messages',
  'human_message_requests',
]) assert.match(social, new RegExp(table));
assert.match(lifecycle, /CREATE TABLE IF NOT EXISTS human_message_legacy_links_v370/);
assert.match(lifecycle, /CREATE TABLE IF NOT EXISTS human_conversation_reads_v370/);
assert.match(lifecycle, /if\(\$pdo->inTransaction\(\)\)throw new RuntimeException\('Human messaging schema must be installed before starting a messaging transaction\.'/);
assert.match(lifecycle, /vp3_human_messaging_v370_ready/);

// Read state is not an authorization grant.
assert.match(lifecycle, /function vp3_human_read_cursor_v370/);
assert.match(lifecycle, /function vp3_human_mark_read_v370/);
assert.match(lifecycle, /function vp3_human_unread_count_v370/);
assert.match(lifecycle, /INSERT INTO human_conversation_reads_v370/);
const teamGeneralStart = lifecycle.indexOf('function vp3_human_team_general_v370');
const teamGeneralEnd = lifecycle.indexOf('function vp3_human_messages_v370', teamGeneralStart);
const teamGeneral = lifecycle.slice(teamGeneralStart, teamGeneralEnd);
assert.match(teamGeneral, /vp3_human_team_authorized_v370/);
assert.doesNotMatch(teamGeneral, /INSERT INTO human_conversation_members/);
const accessStart = lifecycle.indexOf('function vp3_human_can_access_v370');
const accessEnd = lifecycle.indexOf('function vp3_human_insert_message_v370', accessStart);
const access = lifecycle.slice(accessStart, accessEnd);
assert.match(access, /if\(\$type==='team_general'\)/);
assert.match(access, /vp3_human_team_authorized_v370/);

// Direct lifecycle uses one stable lock order: users before conversation/request.
const startStart = lifecycle.indexOf('function vp3_human_start_direct_v370');
const startEnd = lifecycle.indexOf('function vp3_human_send_message_v370', startStart);
const startBody = lifecycle.slice(startStart, startEnd);
assert.ok(startBody.indexOf('vp3_human_lock_users_v370') < startBody.indexOf('vp3_human_direct_conversation_locked_v370'));
assert.ok(startBody.indexOf('vp3_human_direct_conversation_locked_v370') < startBody.indexOf('vp3_human_request_v370'));

const sendStart = lifecycle.indexOf('function vp3_human_send_message_v370');
const sendEnd = lifecycle.indexOf('function vp3_human_resolve_request_v370', sendStart);
const sendBody = lifecycle.slice(sendStart, sendEnd);
assert.match(sendBody, /Preview only to establish the correct lock order/);
assert.ok(sendBody.indexOf("if($type==='direct')vp3_human_lock_users_v370") < sendBody.indexOf('vp3_human_conversation_v370($pdo,$conversationId,true)'));

const resolveStart = lifecycle.indexOf('function vp3_human_resolve_request_v370');
const resolveEnd = lifecycle.indexOf('function vp3_human_team_general_v370', resolveStart);
const resolveBody = lifecycle.slice(resolveStart, resolveEnd);
assert.ok(resolveBody.indexOf('vp3_human_lock_users_v370') < resolveBody.indexOf('vp3_human_request_v370($pdo,$conversationId,true)'));
assert.match(resolveBody, /recipient_user_id/);
assert.match(resolveBody, /status'\]!=='pending'/);
assert.match(resolveBody, /vp3_human_blocked_v370/);

// A pending request is one initial requester message until accepted.
assert.match(startBody, /initial_message_id/);
assert.match(startBody, /Your message request is pending/);
assert.match(sendBody, /Accept the message request before replying/);
assert.match(sendBody, /Your message request is pending/);

// Blocks share the exact user serialization boundary with DM sends. Blocking
// resolves a pending request but preserves the conversation/message history.
assert.match(blockLifecycle, /function vp3_human_set_block_v370/);
assert.match(blockLifecycle, /vp3_human_lock_users_v370/);
assert.match(blockLifecycle, /INSERT IGNORE INTO user_blocks/);
assert.match(blockLifecycle, /UPDATE human_message_requests SET status='declined'/);
assert.doesNotMatch(blockLifecycle, /DELETE FROM human_messages|DELETE FROM human_conversations/i);
assert.match(api, /human-messaging-block-v370\.php/);
assert.match(api, /vp3_human_set_block_v370/);
assert.doesNotMatch(api, /vp3_social_block_v320/);

// Blocks and Team scope are live authorization inputs.
assert.match(lifecycle, /function vp3_human_blocked_v370/);
assert.match(lifecycle, /function vp3_human_shared_workspace_v370/);
assert.match(lifecycle, /artist_team_members/);
assert.match(lifecycle, /function vp3_human_team_authorized_v370/);

// Legacy Team DM history migrates idempotently. The historical table is input only.
assert.match(lifecycle, /SELECT id,sender_user_id,recipient_user_id,message_text,created_at,read_at FROM team_direct_messages/);
assert.match(lifecycle, /human_message_legacy_links_v370/);
assert.match(lifecycle, /source_type='team_direct_messages'/);
assert.match(lifecycle, /SELECT human_message_id FROM human_message_legacy_links_v370/);
assert.match(lifecycle, /vp3_human_migrate_existing_read_state_v370/);
assert.match(lifecycle, /human_conversation_reads_v370/);
assert.doesNotMatch(lifecycle, /INSERT INTO team_direct_messages|UPDATE team_direct_messages|DELETE FROM team_direct_messages/i);

// Legacy Team Chat URL remains, but its runtime is only a compatibility adapter.
assert.match(legacyTeam, /require __DIR__\.'\/team-chat-v320\.php'/);
assert.match(team, /vp3_human_messaging_v370_ready/);
assert.match(team, /human_messages/);
assert.match(team, /human_conversations/);
assert.match(team, /human_conversation_reads_v370/);
assert.match(team, /vp3_human_send_message_v370/);
assert.match(team, /vp3_human_start_direct_v370/);
assert.match(team, /vp3_human_mark_read_v370/);
assert.doesNotMatch(team, /team_direct_messages/);

// Main Messages API and UI consume the same service/read cursor.
for (const fn of [
  'vp3_human_inbox_v370',
  'vp3_human_start_direct_v370',
  'vp3_human_send_message_v370',
  'vp3_human_mark_read_v370',
  'vp3_human_team_general_v370',
  'vp3_human_resolve_request_v370',
]) assert.match(api, new RegExp(fn));
assert.match(api, /vp3_human_messaging_v370_ready/);
assert.match(api, /last_read_message_id/);
assert.match(js, /req\('read'/);
assert.match(js, /through_message_id/);
assert.match(js, /unread_count/);
assert.match(js, /messages-unread/);
assert.match(css, /\.messages-unread/);

// Human message bodies stay out of Agent/Profile-Agent persistence.
assert.doesNotMatch(lifecycle, /agent_chat|profile_agent_messages|agent_activity.*body|activity.*body/i);
assert.doesNotMatch(api, /agent_chat|profile_agent_messages/i);

// Fresh setup, canonical upgrade and bootstrap own v3.70.
assert.match(bootstrap, /human-messaging-v370\.php/);
assert.match(setup, /vp3_human_messaging_v370_ensure_schema\(\$pdo\)/);
assert.match(setup, /vp3_human_messaging_v370_migrate_legacy\(\$pdo\)/);
assert.match(upgrade, /vp3_human_messaging_v370_ready\(\)/);
assert.match(upgrade, /vp3_human_messaging_v370_ensure_schema\(\)/);
assert.match(upgrade, /vp3_human_messaging_v370_migrate_legacy\(\$pdo\)/);

// Documentation states the same runtime/security boundary.
for (const statement of [
  'read/unread state is bookkeeping only',
  'legacy Team Chat endpoint is a compatibility adapter',
  'read state never grants conversation or Team authorization',
  'historical Team DM table is migration input only',
  'Human message bodies may not be copied into generic Agent/activity/audit persistence',
]) assert.match(architecture, new RegExp(statement.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'));

console.log('HUMAN_MESSAGING_V370=PASS');
