import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const loader = read('includes/subscriptions.php');
const schema = read('includes/subscription-schema.php');
const access = read('includes/subscription-access.php');
const quota = read('includes/subscription-quota.php');
const lifecycle = read('includes/subscription-lifecycle.php');
const gates = read('includes/subscription-request-gates.php');
const ai = read('includes/ai-settings.php');
const stream = read('includes/ai-stream-v121.php');
const signup = read('signup.php');
const teamDomain = read('includes/artist-workspaces-v104.php');
const teamSubscription = read('includes/team-subscription.php');
const teamPage = read('team.php');
const nav = read('includes/member-navigation.php');
const bootstrap = read('includes/bootstrap.php');

assert.ok(loader.includes("require_once __DIR__ . '/subscription-schema.php';"), 'subscription entry point must load canonical schema module');
assert.ok(loader.includes("require_once __DIR__ . '/subscription-access.php';"), 'subscription entry point must load canonical access module');
assert.ok(loader.includes("require_once __DIR__ . '/subscription-quota.php';"), 'subscription entry point must load canonical quota module');
assert.ok(!loader.includes('function subscription_'), 'subscription entry point must remain a thin loader');

for (const table of [
  'subscription_packages',
  'package_entitlements',
  'user_subscriptions',
  'ai_token_credits',
  'ai_usage_ledger',
  'ai_token_reservations',
  'subscription_audit_log',
]) {
  assert.ok(schema.includes(table), `subscription schema must own ${table}`);
}

assert.ok(schema.includes("'free-trial'"), 'a configurable Free Trial seed must exist');
assert.ok(schema.includes("'legacy-access'"), 'existing accounts need a non-breaking Legacy Access migration');
assert.ok(schema.includes("'legacy.permissions'"), 'legacy compatibility must be explicit');
assert.ok(schema.includes("'stem_editor.access'"), 'Stem Editor must be package-controlled');
assert.ok(schema.includes("'video_editor.access'"), 'Video Editor must be package-controlled');
assert.ok(schema.includes("'team_seats'"), 'Team seats must be package-controlled');
assert.ok(schema.includes('subscription_permission_key'), 'package permission entitlements need one canonical key namespace');

assert.ok(access.includes('subscription_add_token_credit'), 'token top-ups must be first-class credits');
assert.ok(quota.includes('subscription_ai_preflight'), 'AI requests need a quota preflight');
assert.ok(quota.includes('subscription_ai_commit_usage'), 'provider usage must be committed');
assert.ok(lifecycle.includes('package_snapshot_json') && lifecycle.includes('subscription_lifecycle_snapshot_subscription'), 'subscription history must retain immutable package snapshots');
assert.ok(lifecycle.includes('current_period_start') && lifecycle.includes('current_period_end'), 'monthly periods must roll independently of package edits');

assert.ok(
  quota.includes('$ownsTransaction=!$pdo->inTransaction()')
  && quota.includes('subscription_ai_balance($user,$pdo,true)')
  && quota.includes('INSERT INTO ai_token_reservations'),
  'AI preflight must lock balance state and reserve usage transactionally'
);
assert.ok(
  quota.indexOf('$packageAvailable=max(0,$allowance-$alreadyUsed)') < quota.indexOf('SELECT id,remaining_amount FROM ai_token_credits'),
  'included package tokens must be consumed before purchased/admin credits'
);

assert.ok(access.includes('$ownsTransaction=!$pdo->inTransaction()'), 'package mutations must participate in caller-owned transactions');
assert.ok(access.includes('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE'), 'package assignment must serialize target-account changes');
assert.ok(access.includes('SELECT amount,remaining_amount FROM ai_token_credits WHERE id=? AND user_id=? LIMIT 1 FOR UPDATE'), 'token credit removal must lock the credit');

assert.ok(signup.includes('subscription_assign_default_trial'), 'new public accounts must receive the configured trial');
assert.ok(signup.indexOf('subscription_assign_default_trial($userId)') < signup.indexOf('$pdo->commit();'), 'signup must assign the trial before commit');
assert.ok(signup.includes('if ($trialSubscriptionId < 1)'), 'signup must fail closed when the trial cannot be assigned');
assert.ok(!signup.includes('How will you use VP3?'), 'signup must not restore the old role picker');
assert.ok(!signup.includes('manager') && !signup.includes('producer'), 'signup must never create Team roles');

for (const source of [ai, stream]) {
  assert.ok(source.includes('subscription_ai_preflight'), 'every remote AI path must reserve quota before provider work');
  assert.ok(source.includes('subscription_ai_commit_usage'), 'every remote AI path must commit actual usage');
  assert.ok(source.includes('subscription_ai_release_reservation'), 'failed provider work must release reservations');
}
assert.ok(ai.includes('ai_subscription_scope_from_request'), 'AI usage must be attributed to the calling surface');
for (const scope of ['video_editor', 'stem_editor', 'profile_agent', 'transcription', 'chat']) {
  assert.ok(ai.includes(`'${scope}'`), `AI ledger must recognize ${scope}`);
}

assert.ok(gates.includes('function subscription_admin_only_permissions'), 'Admin-only authority needs one explicit deny list');
for (const permission of ['admin.access', 'users.manage', 'permissions.manage', 'ai.manage']) {
  assert.ok(gates.includes(`'${permission}'`), `${permission} must remain Admin-only`);
}
assert.ok(gates.includes('function subscription_effective_permission'), 'customer permission decisions need one canonical package-aware helper');
assert.ok(gates.includes('subscription_package_grants_permission($user,$permission)'), 'current customer packages must be able to grant customer-facing permissions');
assert.ok(gates.includes("subscription_has_entitlement($user,'legacy.permissions')"), 'Legacy Access must retain an explicit compatibility path');
assert.ok(gates.includes('artist_workspace_v104_memberships_for_user'), 'Manager/Producer request context must derive from workspace relationships');
assert.ok(!gates.includes("user_has_role('manager'"), 'request gates must not depend on a global Manager role');
assert.ok(!gates.includes("user_has_role('producer'"), 'request gates must not depend on a global Producer role');

assert.ok(teamDomain.includes('DROP INDEX uq_artist_team_member'), 'a person must be able to collaborate with multiple Artists');
assert.ok(teamDomain.includes('artist_workspace_v104_migrate_contextual_roles'), 'legacy Team identities need migration cleanup');
assert.ok(teamDomain.includes("return ['manager'=>[],'producer'=>[]];"), 'Manager/Producer relationships must grant no global role permissions');
assert.match(teamDomain, /DELETE FROM user_account_types WHERE user_id=\? AND role IN \('manager','producer'\)/, 'legacy Manager/Producer account rows must be deleted');
assert.ok(teamDomain.includes("DELETE FROM role_permissions WHERE role IN ('manager','producer')"), 'legacy Manager/Producer global permission rows must be deleted');
assert.ok(teamDomain.includes("UPDATE users SET role='fan' WHERE id=? AND role IN ('manager','producer')"), 'legacy Manager/Producer primary roles must normalize to Customer');
assert.ok(teamDomain.includes('artist_workspace_v104_artist_package_permissions'), 'Artist workspace identity must be derived from package/workspace capability');
assert.ok(teamDomain.includes('subscription_package_grants_permission($user,$permission)'), 'Artist package capability must be recognized without a global Artist assignment');
assert.ok(teamDomain.includes('artist_workspace_v104_revoke_producer_assignments'), 'Team changes must revoke stale direct production assignments');
assert.ok(teamDomain.includes('UPDATE tracks SET producer_user_id=NULL WHERE owner_user_id=? AND producer_user_id=?'), 'producer revocation must remain Artist + member scoped');
assert.ok(bootstrap.includes('artist_workspace_v104_boot_contextual_roles();') && bootstrap.indexOf('artist_workspace_v104_boot_contextual_roles();') < bootstrap.indexOf('subscription_request_gate();'), 'Team-role cleanup must run before request authorization');

assert.ok(teamPage.includes('artist_workspace_v104_attach_member'), 'Team must create relationship-scoped roles');
assert.ok(teamPage.includes('artist_workspace_v104_detach_member'), 'Team removal must detach the relationship');
assert.ok(!teamPage.includes('DELETE FROM users'), 'Team removal must preserve the VP3 account');
assert.ok(teamPage.includes('subscription_assign_default_trial'), 'new Team-created accounts must enter the ordinary trial flow');
assert.ok(teamPage.includes('team_subscription_state'), 'Team capacity must consume canonical package state');
assert.ok(teamSubscription.includes("subscription_entitlement_row((int)$subscription['package_id'],'team_seats')"), 'Team capacity must come from the package team_seats entitlement');
assert.ok(teamSubscription.includes("$state['can_add']"), 'canonical Team state must own add-member capacity decisions');
assert.ok(teamSubscription.includes('music_workspace_enabled_v320($user)'), 'Team ownership must recognize the enabled Music Workspace capability');
assert.ok(teamSubscription.includes('artist_workspace_v104_is_artist($user)'), 'Legacy workspace/package context may remain only inside the canonical Team state resolver');
assert.ok(!teamSubscription.includes("user_has_role('artist',$user)"), 'canonical Team authorization must not require a global Artist identity');

assert.ok(nav.includes('subscription_effective_permission($permission,$user)'), 'member navigation must use the canonical package-aware permission decision');
assert.ok(nav.includes("team_subscription_state($user)"), 'Team navigation must consume canonical workspace/package state');
assert.ok(nav.includes('music_workspace_enabled_v320($user)'), 'Music navigation must use explicit plugin/workspace state');
assert.ok(!nav.includes("user_has_role('artist'"), 'member navigation must not depend on a global Artist assignment');
assert.ok(nav.includes("if(user_has_role('admin',$user))$add($links,'admin'"), 'only Admin identity may receive global Admin navigation');

console.log('SUBSCRIPTION_ACCESS_CONTRACT=PASS');