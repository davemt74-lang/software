import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const subscriptions = read('includes/subscriptions.php');
const lifecycle = read('includes/subscription-lifecycle.php');
const gates = read('includes/subscription-request-gates.php');
const ai = read('includes/ai-settings.php');
const stream = read('includes/ai-stream-v121.php');
const signup = read('signup.php');
const teamDomain = read('includes/artist-workspaces-v104.php');
const teamPage = read('admin/team.php');
const nav = read('includes/member-navigation.php');
const bootstrap = read('includes/bootstrap.php');

for (const table of [
  'subscription_packages',
  'package_entitlements',
  'user_subscriptions',
  'ai_token_credits',
  'ai_usage_ledger',
  'ai_token_reservations',
  'subscription_audit_log',
]) {
  assert.ok(subscriptions.includes(table), `subscription runtime must own ${table}`);
}

assert.ok(subscriptions.includes("'free-trial'"), 'a configurable Free Trial seed must exist');
assert.ok(subscriptions.includes("'legacy-access'"), 'existing accounts need a non-breaking Legacy Access migration');
assert.ok(subscriptions.includes("'legacy.permissions'"), 'legacy compatibility must be explicit rather than implicit');
assert.ok(subscriptions.includes("'stem_editor.access'"), 'Stem Editor must be a package entitlement');
assert.ok(subscriptions.includes("'video_editor.access'"), 'Video Editor must be a package entitlement');
assert.ok(subscriptions.includes("'team_seats'"), 'Team seats must be package-controlled');
assert.ok(subscriptions.includes('subscription_add_token_credit'), 'token top-ups must be first-class credits');
assert.ok(subscriptions.includes('subscription_ai_preflight'), 'AI requests need a quota preflight');
assert.ok(subscriptions.includes('subscription_ai_commit_usage'), 'actual provider usage must be committed');
assert.ok(lifecycle.includes('package_snapshot_json') && lifecycle.includes('subscription_lifecycle_snapshot_subscription'), 'subscription history must retain an immutable package snapshot');
assert.ok(lifecycle.includes('current_period_start') && lifecycle.includes('current_period_end'), 'monthly periods must roll independently of package edits');

assert.ok(signup.includes('subscription_assign_default_trial'), 'new public accounts must receive the configured default trial');
assert.ok(!signup.includes('How will you use VP3?'), 'signup must not restore the old public role picker');
assert.ok(!signup.includes('manager') && !signup.includes('producer'), 'signup must not create Team roles');

for (const source of [ai, stream]) {
  assert.ok(source.includes('subscription_ai_preflight'), 'every remote AI transport path must reserve quota before provider work');
  assert.ok(source.includes('subscription_ai_commit_usage'), 'every remote AI transport path must commit actual provider usage');
  assert.ok(source.includes('subscription_ai_release_reservation'), 'failed provider work must release reservations');
}
assert.ok(ai.includes('ai_subscription_scope_from_request'), 'non-streaming AI must attribute usage to the calling surface');
for (const scope of ['video_editor', 'stem_editor', 'profile_agent', 'transcription', 'chat']) {
  assert.ok(ai.includes(`'${scope}'`), `non-streaming ledger must recognize ${scope}`);
}
assert.ok(ai.indexOf('subscription_ai_preflight') < ai.indexOf("ai_openai_response($query, $history, $context, $user)"), 'non-streaming quota must be checked before the provider call');

assert.ok(gates.includes('function subscription_effective_permission'), 'commercial permission ceilings need one canonical effective-permission helper');
assert.ok(gates.includes("!has_permission($permission,$user)"), 'a package must never grant a missing security permission');
assert.ok(gates.includes('subscription_request_guard_legacy_team_role'), 'legacy/contextual Team compatibility roles must fail closed on global Admin');
assert.ok(gates.includes("'/admin/team-workspaces.php'"), 'Manager compatibility role may enter only the relationship-scoped Team selector');
assert.ok(gates.includes("'/admin/team-workspace.php'"), 'Manager compatibility role may enter only the relationship-scoped Manager workspace');
assert.ok(gates.includes('Legacy Manager authority has been retired'), 'global Manager authority must remain retired');

assert.ok(teamDomain.includes('DROP INDEX uq_artist_team_member'), 'Team membership must allow a person to collaborate with more than one Artist');
assert.ok(teamDomain.includes('artist_workspace_v104_migrate_contextual_roles'), 'legacy Team identities must migrate to relationship-derived roles');
assert.ok(teamDomain.includes('artist_workspace_v104_context_role_permissions'), 'Team compatibility roles need an explicit minimal permission catalog');
assert.ok(teamDomain.includes("'manager'=>['account.access','chat.access','artist_listening.access','knowledge.access']"), 'Manager compatibility role must not regain global CMS permissions');
assert.ok(teamDomain.includes("'producer'=>['account.access','chat.access','artist_listening.access','producer.access']"), 'Producer compatibility role must remain narrow and production-specific');
assert.match(teamDomain, /DELETE FROM user_account_types WHERE user_id=\? AND role IN \('manager','producer'\)/, 'contextual migration must rebuild old Team roles from relationships');
assert.ok(teamDomain.includes("UPDATE users SET role='fan'"), 'linked legacy Team accounts must return to a normal base identity');
assert.ok(
  teamDomain.includes("SELECT DISTINCT team_role FROM artist_team_members WHERE member_user_id=?")
  && teamDomain.includes('artist_workspace_v104_sync_member_context_roles'),
  'derived Team markers must be rebuilt from artist_team_members'
);
assert.ok(teamDomain.includes('if(!$retiredPrimary&&$desired===$existing)return;'), 'steady-state Team reconciliation should avoid unnecessary writes');
assert.ok(teamDomain.includes('artist_workspace_v104_revoke_producer_assignments'), 'Team changes must revoke stale direct production assignments');
assert.ok(teamDomain.includes('UPDATE tracks SET producer_user_id=NULL WHERE owner_user_id=? AND producer_user_id=?'), 'producer revocation must be Artist + member scoped');
assert.ok(bootstrap.includes('artist_workspace_v104_boot_contextual_roles();') && bootstrap.indexOf('artist_workspace_v104_boot_contextual_roles();') < bootstrap.indexOf('subscription_request_gate();'), 'Team migration must run before request authorization');

assert.ok(teamPage.includes("user_has_role('artist',$user)"), 'only an actual Artist identity may own/manage an Artist Team');
assert.ok(teamPage.includes('artist_workspace_v104_detach_member'), 'removing a collaborator must detach the relationship');
assert.ok(!teamPage.includes('DELETE FROM users'), 'removing a Team member must never delete their VP3 account');
assert.ok(teamPage.includes('subscription_assign_default_trial'), 'new Team-created accounts must enter the ordinary trial flow');
assert.ok(teamPage.includes('artist_workspace_v104_team_limit'), 'Team capacity must come from package seats');

assert.ok(nav.includes("$authorized=has_permission($permission,$user)"), 'navigation must authorize before evaluating package permission ceilings');
assert.ok(nav.includes("user_has_role('artist',$user)"), 'a package must not manufacture Artist workspace identity in navigation');

console.log('SUBSCRIPTION_ACCESS_CONTRACT=PASS');
