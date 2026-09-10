import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const selector = read('admin/team-workspaces.php');
const manager = read('admin/team-workspace.php');
const producer = read('admin/producer-tracks.php');
const users = read('admin/users.php');
const permissions = read('admin/permissions.php');
const team = read('team.php');
const domain = read('includes/artist-workspaces-v104.php');
const gates = read('includes/subscription-request-gates.php');
const nav = read('includes/member-navigation.php');
const teamChatLegacy = read('api/team-chat-v109.php');
const teamChatScoped = read('api/team-chat-v320.php');
const teamChatWidget = read('includes/team-chat-widget-v81.php');

assert.ok(selector.includes('artist_workspace_v104_memberships_for_user'), 'Team selector must derive workspaces from relationships');
assert.ok(selector.includes("$role==='manager'"), 'Manager relationships need a scoped Manager destination');
assert.ok(selector.includes("$role==='producer'"), 'Producer relationships need a scoped production destination');

assert.ok(manager.includes('artist_workspace_v104_membership'), 'Manager workspace must verify the selected Artist relationship');
assert.ok(manager.includes("$teamRole!=='manager'"), 'non-Manager Team members must not enter Manager workspace');
assert.ok(manager.includes('artist_workspace_v104_can_manage'), 'section authority must remain relationship-scoped');
assert.ok(manager.includes('WHERE id=? AND workspace_id=?'), 'record mutations must include workspace ownership checks');
assert.ok(manager.includes('WHERE workspace_id=?'), 'workspace reads must be workspace-scoped');
assert.ok(!manager.includes('DELETE FROM tracks WHERE'), 'Manager workspace must never fall back to the global Tracks table');
assert.ok(!manager.includes('UPDATE tracks SET'), 'Manager workspace must never mutate the global Tracks table');
for (const table of [
  'artist_catalog_tracks_v181',
  'artist_catalog_albums_v181',
  'artist_catalog_shows_v181',
  'artist_catalog_photos_v181',
  'artist_catalog_merch_v181',
  'artist_posts_v181',
  'artist_workspaces_v181',
]) {
  assert.ok(manager.includes(table), `Manager workspace should operate on private ${table}`);
}
assert.ok(manager.includes('artist_media_v182_store_photo'), 'Manager photo uploads must reuse workspace-owned media storage');
assert.ok(manager.includes('artist_media_v182_delete_owned_photo'), 'Manager photo deletion must validate workspace ownership');

assert.ok(!producer.includes("require_permission('producer.access')"), 'Producer entry must be relationship/direct-assignment driven');
assert.ok(producer.includes('artist_workspace_v104_memberships_for_user'), 'Producer access must recognize Team relationships');
assert.ok(producer.includes('WHERE t.producer_user_id=?'), 'Producer track reads must remain explicitly assigned to the current user');
assert.ok(producer.includes('stem_editor.access'), 'Stem Editor commercial entitlement must remain separate from Producer relationship authority');

assert.ok(users.includes('admin_user_workspace_context'), 'Admin Users must expose workspace relationships separately from global account authority');
assert.ok(users.includes("$roles=$internalAdmin?['fan','admin']:['fan'];"), 'Admin Users must persist only Customer/Admin global account authority');
assert.ok(users.includes("$primary=$internalAdmin?'admin':'fan';"), 'Customer must be the neutral primary account identity');
assert.ok(!users.includes('workspace_artist'), 'Admin Users must not expose a manually assigned Artist identity toggle');
assert.ok(users.includes('Package and workspace relationships were not changed'), 'editing account identity must not silently mutate package or workspace context');
assert.ok(users.includes("atm.team_role IN ('manager','producer')"), 'Manager/Producer labels in Users must come from Team relationships');

// Permissions is a security surface only. It may edit the neutral Customer role
// policy, but it must never mutate commercial packages or workspace authority.
assert.ok(permissions.includes("role='fan'"), 'Permissions must edit the neutral Customer role policy');
assert.ok(permissions.includes('customer_permissions'), 'Permissions must expose the Customer security baseline explicitly');
assert.ok(permissions.includes('workspaceOnly'), 'workspace-scoped powers must be identified as contextual');
assert.ok(permissions.includes('Admin only'), 'Permissions UI must identify internal Admin-only capabilities');
assert.ok(permissions.includes('Product access belongs to Packages and Entitlement Grants'), 'Permissions must explain the product/security separation');
assert.ok(!permissions.includes('INSERT INTO package_entitlements'), 'Permissions must never write package entitlements');
assert.ok(!permissions.includes('UPDATE package_entitlements'), 'Permissions must never update package entitlements');
assert.ok(!permissions.includes('subscription_permission_key'), 'Permissions must not manufacture permission-shaped product keys');
assert.ok(!permissions.includes('Account Type Permissions'), 'legacy account-type permission matrix must be retired');

assert.ok(team.includes('artist_workspace_v104_attach_member'), 'Team must create relationship-scoped roles');
assert.ok(team.includes('artist_workspace_v104_detach_member'), 'Team removal must detach the relationship');
assert.ok(!team.includes('DELETE FROM users'), 'Team removal must preserve the VP3 account');
assert.ok(team.includes("VALUES (?,?,?,'fan','',1)"), 'new Team-created users must start as neutral Customer accounts');

assert.ok(domain.includes('PRIMARY KEY (artist_user_id,member_user_id)'), 'membership identity must be Artist + member, supporting multi-Artist collaboration');
assert.ok(domain.includes("return ['manager'=>[],'producer'=>[]];"), 'Manager/Producer Team relationships must grant no global role permissions');
assert.ok(domain.includes("DELETE FROM role_permissions WHERE role IN ('manager','producer')"), 'legacy Team role permissions must be removed globally');
assert.match(domain, /DELETE FROM user_account_types WHERE user_id=\? AND role IN \('manager','producer'\)/, 'legacy Team account-role rows must be removed');
assert.ok(domain.includes('artist_workspace_v104_user_owns_workspace'), 'Artist workspace context must recognize canonical workspace ownership');
assert.ok(domain.includes('music_workspace_enabled_v320($user)'), 'enabled Music Workspace state may establish owner context');
assert.ok(domain.includes('Product entitlement alone'), 'product access alone must not create workspace authority');
assert.ok(!domain.includes('subscription_package_grants_permission'), 'Artist workspace context must not derive authority from package permissions');
assert.ok(!domain.includes('legacy.permissions'), 'Artist workspace context must not depend on retired legacy permission entitlements');
assert.ok(domain.includes('artist_workspace_v104_revoke_producer_assignments'), 'Producer membership removal or downgrade must revoke direct track assignments');
assert.ok(domain.includes('UPDATE tracks SET producer_user_id=NULL WHERE owner_user_id=? AND producer_user_id=?'), 'producer revocation must be scoped to the owning Artist and member');

assert.ok(gates.includes('artist_workspace_v104_memberships_for_user'), 'request guards must derive Team authority from relationships');
assert.ok(gates.includes("$managerSafe=['/admin/team-workspaces.php','/admin/team-workspace.php']"), 'Manager relationship context must only pass scoped Team Admin routes');
assert.ok(gates.includes("$producerSafe=['/admin/producer-tracks.php','/admin/stems.php','/admin/stems-legacy-v108.php']"), 'Producer relationship context must only pass direct production Admin routes');
assert.ok(nav.includes('artist_workspace_v104_memberships_for_user'), 'member navigation must derive Team Workspaces visibility from relationships');

// v109 is compatibility-only. Contextual Team membership and directory scoping
// are enforced by the canonical v320 runtime, not duplicated in the legacy file.
assert.match(teamChatLegacy, /require __DIR__\.'\/team-chat-v320\.php'/, 'legacy Team Chat URL must delegate to v320');
assert.ok(teamChatScoped.includes('FROM artist_team_members WHERE artist_user_id=?'), 'Team Chat must recognize workspace owners and their contextual members');
assert.ok(teamChatScoped.includes('FROM artist_team_members WHERE member_user_id=?'), 'Team Chat eligibility must include contextual memberships');
assert.ok(teamChatScoped.includes('INNER JOIN artist_team_members a2 ON a2.artist_user_id=a1.artist_user_id'), 'Team Chat directory must include peers in the same contextual workspace');
assert.ok(teamChatScoped.includes('vp3_social_shared_workspace_v320'), 'history/send/read must re-check current shared-workspace authorization');
assert.ok(!teamChatScoped.includes("WHERE u.role IN"), 'Team Chat directory must not use global role membership');
assert.ok(teamChatWidget.includes('artist_workspace_v104_memberships_for_user'), 'Team Chat widget must remain visible to contextual Managers and Producers');

console.log('TEAM_WORKSPACE_SCOPE_CONTRACT=PASS');
