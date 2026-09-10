import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const legacy = read('includes/release-agent-v105.php');
const compat = read('includes/release-workspace-v332.php');
const guard = read('includes/release-guard-v105.php');
const proactive = read('includes/release-proactive-v105.php');
const chat = read('includes/release-chat-v105.php');
const bootstrap = read('includes/bootstrap.php');

// Legacy public signatures stay compatible, but the old arbitrary first-Team
// owner selection is removed and current installs delegate to workspace v332.
assert.match(legacy, /function release_v105_workspace_owner_id/);
assert.match(legacy, /release_workspace_v332_owner_id/);
assert.doesNotMatch(legacy, /SELECT artist_user_id[\s\S]{0,180}LIMIT 1/, 'legacy release owner must never be guessed from the first Team membership');
for (const fn of ['plans','plan','items','resources','integrations','enqueue_action','agent_context']) {
  assert.match(legacy, new RegExp(`release_workspace_v332_[a-z_]+`));
}

// Compatibility context resolves an explicit release workspace first, then the
// requested/session active workspace; owner_user_id is attribution only.
assert.match(compat, /SELECT workspace_id FROM release_plans WHERE id=\? LIMIT 1/);
assert.match(compat, /music_workspace_resources_v330_can_access/);
assert.match(compat, /music_workspace_resources_v330_resolve_active/);
assert.match(compat, /WHERE rp\.workspace_id=\?/);
assert.match(compat, /WHERE id=\? AND workspace_id=\? LIMIT 1/);
assert.match(compat, /WHERE ri\.release_id=\? AND ri\.workspace_id=\?/);

// External Agent work tied to a release carries workspace_id and validates the
// exact release/item workspace. Generic owner integrations stay owner-only until
// they are migrated into workspace-owned resources.
assert.match(compat, /INSERT INTO agent_work_actions \(workspace_id,owner_user_id,release_id,release_item_id/);
assert.match(compat, /WHERE id=\? AND release_id=\? AND workspace_id=\? LIMIT 1/);
assert.match(compat, /Only the Music Workspace owner can queue external provider actions/);
assert.match(compat, /\$uid!==\$ownerId/);

// The old Admin Release Calendar is a compatibility URL only once v330 is ready.
// Both GET and stale POST paths redirect before admin/releases.php can mutate.
assert.match(guard, /legacy Release Calendar is read-only compatibility/i);
assert.match(guard, /No changes were applied/);
assert.match(guard, /release_workspace_v332_route/);
assert.match(guard, /music_workspace_resources_v330_schema_ready/);

// Proactive intelligence reads one active workspace and emits canonical links.
assert.match(proactive, /release_workspace_v332_can_manage/);
assert.match(proactive, /JOIN release_plans rp ON rp\.id=ri\.release_id AND rp\.workspace_id=ri\.workspace_id/);
assert.match(proactive, /WHERE rp\.workspace_id=\?/);
assert.match(proactive, /music-releases\.php\?workspace=/);
assert.doesNotMatch(proactive, /release_v105_workspace_owner_id/);

// Agent open/list/create routes are workspace-native and creation uses the
// canonical v331 writer rather than a raw owner_user_id insert.
assert.match(chat, /release_workspace_v332_can_manage/);
assert.match(chat, /release_workspace_v332_plans/);
assert.match(chat, /music_workspace_resources_v331_save_release/);
assert.match(chat, /music-releases\.php\?workspace=/);
assert.match(chat, /release_workspace_v332_agent_context/);
assert.doesNotMatch(chat, /INSERT INTO release_plans/);
assert.doesNotMatch(chat, /release_v105_workspace_owner_id/);

// Load ordering must make v105 signatures available first, then install v332
// before the legacy guard/proactive/chat consumers execute.
const agentIndex = bootstrap.indexOf("release-agent-v105.php");
const compatIndex = bootstrap.indexOf("release-workspace-v332.php");
const guardIndex = bootstrap.indexOf("release-guard-v105.php");
const proactiveIndex = bootstrap.indexOf("release-proactive-v105.php");
const chatIndex = bootstrap.indexOf("release-chat-v105.php");
assert.ok(agentIndex >= 0 && compatIndex > agentIndex);
assert.ok(guardIndex > compatIndex && proactiveIndex > compatIndex && chatIndex > compatIndex);

console.log('RELEASE_WORKSPACE_COMPAT_V332=PASS');
