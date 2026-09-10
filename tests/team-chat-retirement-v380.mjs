import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const ROOT = path.resolve(new URL('..', import.meta.url).pathname);
const read = rel => fs.readFileSync(path.join(ROOT, rel), 'utf8');
const v81 = read('api/team-chat-v81.php');
const v103 = read('api/team-chat-v103.php');
const v109 = read('api/team-chat-v109.php');
const canonical = read('api/team-chat-v320.php');
const migration = read('includes/human-messaging-v370.php');
const widget = read('includes/team-chat-widget-v81.php');
const oldJs = read('team-chat-v81.js');
const currentJs = read('team-chat-v109.js');
const docs = read('docs/TEAM_CHAT_RETIREMENT_V380.md');

function phpFiles(dir) {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes:true })) {
    if (entry.name === '.git' || entry.name === 'vendor') continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) out.push(...phpFiles(full));
    else if (entry.isFile() && entry.name.endsWith('.php')) out.push(full);
  }
  return out;
}

// Historical endpoints are URL compatibility only; all logic is delegated.
for (const [name, source] of [['v81',v81],['v103',v103],['v109',v109]]) {
  assert.match(source, /require __DIR__\.'\/team-chat-v320\.php'/, `${name} must delegate to v320`);
  assert.doesNotMatch(source, /team_direct_messages/);
  assert.doesNotMatch(source, /user_has_role|user_roles_for_user|role IN|SELECT |INSERT |UPDATE |DELETE /i);
}

// Canonical Team Chat uses only the v3.70 human ledger and current workspace scope.
assert.match(canonical, /vp3_human_messaging_v370_ready/);
assert.match(canonical, /vp3_human_shared_workspace_v370/);
assert.match(canonical, /vp3_human_start_direct_v370/);
assert.match(canonical, /vp3_human_send_message_v370/);
assert.match(canonical, /vp3_human_mark_read_v370/);
assert.match(canonical, /human_messages/);
assert.match(canonical, /human_conversations/);
assert.match(canonical, /human_conversation_reads_v370/);
assert.doesNotMatch(canonical, /team_direct_messages/);
assert.doesNotMatch(canonical, /user_has_role\(|user_roles_for_user\(|u\.role\s+IN|user_account_types/i);

// v109 consumes the modern directory. v81's deployed legacy client consumes
// `online`, so v320 carries both response keys from one scoped peer set.
assert.match(currentJs, /Array\.isArray\(data\.users\)/);
assert.match(oldJs, /Array\.isArray\(data\.online\)/);
assert.match(canonical, /'users'=>\$users/);
assert.match(canonical, /'online'=>/);
assert.match(canonical, /array_filter\(\$users/);

// Widget ships only the v109 compatibility URL/assets and derives eligibility
// from contextual workspace memberships rather than Manager/Producer globals.
assert.match(widget, /api\/team-chat-v109\.php/);
assert.match(widget, /team-chat-v109\.js/);
assert.match(widget, /artist_workspace_v104_memberships_for_user/);
assert.doesNotMatch(widget, /team-chat-v81\.js|api\/team-chat-v81\.php|api\/team-chat-v103\.php/);

// Repository-wide runtime guard: the one-time v3.70 migration service is the
// only PHP file permitted to mention the retired table at all.
const offenders = [];
for (const full of phpFiles(ROOT)) {
  const rel = path.relative(ROOT, full).replaceAll('\\','/');
  if (rel === 'includes/human-messaging-v370.php') continue;
  const source = fs.readFileSync(full, 'utf8');
  if (/team_direct_messages/i.test(source)) offenders.push(rel);
}
assert.deepEqual(offenders, [], `retired Team DM table leaked into PHP runtime: ${offenders.join(', ')}`);

// Migration is read-only with respect to the retired store and idempotently maps rows.
assert.match(migration, /FROM team_direct_messages/);
assert.match(migration, /human_message_legacy_links_v370/);
assert.doesNotMatch(migration, /INSERT\s+INTO\s+team_direct_messages|UPDATE\s+team_direct_messages|DELETE\s+FROM\s+team_direct_messages/i);

for (const statement of [
  'exactly one Team Chat API implementation',
  'v81, v103 and v109 are compatibility shims only',
  'No PHP runtime outside the v3.70 migration service may reference `team_direct_messages`',
  'global Artist/Manager/Producer/Supervisor roles',
  'online` contains only the currently-online subset',
]) assert.match(docs, new RegExp(statement.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'));

console.log('TEAM_CHAT_RETIREMENT_V380=PASS');
