import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const registry = read('includes/plugin-registry-v320.php');
const lifecycle = read('includes/plugin-lifecycle-v360.php');
const music = read('includes/music-workspace-plugin-v320.php');
const resources = read('includes/music-workspace-resources-v330.php');
const nav = read('includes/member-navigation.php');
const agentContext = read('includes/agent-surface-context-v131.php');
const plugins = read('plugins.php');
const bootstrap = read('includes/bootstrap.php');
const setup = read('setup.php');
const upgrade = read('upgrade.php');
const architecture = read('docs/VP3_PLATFORM_ARCHITECTURE_V320.md');

// Registry persistence is preference only and may not perform DDL inside lifecycle transactions.
assert.match(registry, /user_plugin_installations/);
assert.match(registry, /if\(vp3_plugin_schema_ready_v320\(\$pdo\)\)return;/);
assert.match(registry, /if\(\$pdo->inTransaction\(\)\)throw new RuntimeException/);
assert.match(registry, /Plugin registry schema must be installed before starting a plugin lifecycle transaction/);

// v3.60 composes requested preference + commercial eligibility into one effective state.
assert.match(lifecycle, /function vp3_plugin_requested_state_v360/);
assert.match(lifecycle, /function vp3_plugin_entitled_v360/);
assert.match(lifecycle, /function vp3_plugin_effective_state_v360/);
assert.match(lifecycle, /function vp3_plugin_effective_enabled_v360/);
assert.match(lifecycle, /function vp3_plugin_set_enabled_v360/);
assert.match(lifecycle, /'paused_entitlement'/);
assert.match(lifecycle, /'legacy_enabled'/);
assert.match(lifecycle, /'available'/);
assert.match(lifecycle, /if\(\$requested==='disabled'\)\$reason='disabled'/);
assert.match(lifecycle, /if\(\$enabled&&!vp3_plugin_entitled_v360/);

// Legacy migration materializes historical owners but never overwrites an explicit disable.
assert.match(lifecycle, /INSERT IGNORE INTO user_plugin_installations/);
assert.match(lifecycle, /FROM artist_workspaces_v181/);
assert.doesNotMatch(lifecycle, /ON DUPLICATE KEY UPDATE[^;]*status/s);

// Music's stable v3.20 API delegates to the canonical resolver so all existing callers inherit it.
assert.match(music, /vp3_plugin_effective_enabled_v360/);
assert.match(music, /vp3_plugin_set_enabled_v360/);
assert.match(music, /vp3_plugin_effective_state_v360/);

// Workspace access checks the owning workspace's plugin, while collaborators use active workspace role.
assert.match(resources, /function music_workspace_resources_v330_workspace_enabled/);
assert.match(resources, /return \$owner \? music_workspace_enabled_v320\(\$owner\) : false/);
assert.match(resources, /function music_workspace_resources_v330_can_access/);
assert.match(resources, /music_workspace_resources_v330_member_role/);
assert.doesNotMatch(resources, /music_workspace_entitled_v320\(\$user\)/);

// Navigation uses the same effective Music adapter; collaborator workspaces remain discoverable.
assert.match(nav, /music_workspace_enabled_v320\(\$user\)/);
assert.match(nav, /music_workspace_resources_v330_accessible_workspaces/);
assert.match(nav, /if\(\$musicEnabled\|\|\$musicWorkspaces\)/);

// Agent owner capabilities follow effective plugin state; active collaborators receive only contextual workspace access.
assert.match(lifecycle, /function vp3_plugin_agent_capabilities_v360/);
assert.match(lifecycle, /music_workspace_resources_v330_accessible_workspaces/);
assert.match(lifecycle, /\$contextual=\$workspaceCount>0/);
assert.match(lifecycle, /'state'=>\$contextual\?'workspace_access'/);
assert.match(agentContext, /plugin_capabilities/);
assert.match(agentContext, /vp3_plugin_agent_capabilities_v360/);
assert.match(agentContext, /plugin-capability/);

// Member UI distinguishes enabled, disabled, available and entitlement-paused states.
assert.match(plugins, /paused_entitlement/);
assert.match(plugins, /Paused — plan access required/);
assert.match(plugins, /Disabling Music Workspace pauses its working surfaces and collaborator access/);
assert.match(plugins, /does not delete music, releases, Team memberships, messages or history/);
assert.match(plugins, /resumes it when eligibility returns/);

// Plugin lifecycle itself must not mutate identity, Team relationships or professional content.
for (const destructive of [
  /DELETE\s+FROM\s+users/i,
  /DELETE\s+FROM\s+workspace_memberships_v350/i,
  /DELETE\s+FROM\s+artist_team_members/i,
  /DELETE\s+FROM\s+tracks/i,
  /DELETE\s+FROM\s+albums/i,
  /DELETE\s+FROM\s+release_plans/i,
  /DELETE\s+FROM\s+human_messages/i,
]) assert.doesNotMatch(lifecycle, destructive);

// Fresh install, upgrade and bootstrap all own the v3.60 contract.
assert.match(bootstrap, /plugin-lifecycle-v360\.php/);
assert.match(setup, /vp3_plugin_migrate_legacy_v360\(\$pdo\)/);
assert.match(upgrade, /vp3_plugin_lifecycle_v360_ready\(\)/);
assert.match(upgrade, /vp3_plugin_migrate_legacy_v360\(\$pdo\)/);

// Architecture explicitly separates entitlement, preference and effective state.
assert.match(architecture, /commercial eligibility/);
assert.match(architecture, /effective plugin state/i);
assert.match(architecture, /entitlement loss/i);
assert.match(architecture, /Agent capability/i);

console.log('PLUGIN_LIFECYCLE_V360=PASS');
