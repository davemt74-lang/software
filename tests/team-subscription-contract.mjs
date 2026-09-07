import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const state = read('includes/team-subscription.php');
const team = read('admin/team.php');
const sidebar = read('includes/main-sidebar.php');
const bootstrap = read('includes/bootstrap.php');

assert.ok(bootstrap.includes("require_once __DIR__.'/team-subscription.php';"), 'canonical Team package state must load globally');
assert.ok(state.includes("subscription_entitlement_row((int)$subscription['package_id'],'team_seats')"), 'Team capacity must come from the package team_seats entitlement');
assert.ok(state.includes("$row['limit_value']===null"), 'blank package Team limit must be handled explicitly');
assert.ok(state.includes("$state['unlimited']=true"), 'NULL Team limits must be represented as unlimited rather than a hidden hard-coded seat count');
assert.ok(state.includes("$state['limit']=2"), 'two-seat behavior may remain only as the pre-subscription migration fallback');
assert.ok(state.includes("$state['over_limit']=(int)$state['used']>$limit"), 'downgrades must detect over-limit Teams without deleting members');
assert.ok(state.includes("$state['can_add']=$state['authorized']"), 'add-member availability must combine authorization with package capacity');

assert.ok(team.includes("$teamState=team_subscription_state($user,$pdo)"), 'My Team must render from canonical package state');
assert.ok(team.includes("$lockedTeamState=team_subscription_state($user,$pdo)"), 'add-member requests must re-read package capacity after the owner lock');
assert.ok(team.indexOf("SELECT id FROM users WHERE id=? FOR UPDATE") < team.indexOf("$lockedTeamState=team_subscription_state($user,$pdo)"), 'package capacity must be refreshed after acquiring the account lock');
assert.ok(!team.includes("subscription_package_grants_permission($user,'team.manage')"), 'Team seats, not a duplicate package permission flag, must be the commercial Team authority');
assert.ok(!team.includes("subscription_package_grants_permission($user,'admin.access')"), 'package seat state must not be coupled to an unrelated Admin package flag');
assert.ok(team.includes("Existing relationships remain intact"), 'downgrade UI must explicitly preserve existing Team relationships');
assert.ok(team.includes("No one was removed by the package change"), 'over-limit downgrade UI must be non-destructive');
assert.ok(team.includes("url('/subscription.php')"), 'limit/locked states must link to plan management');
assert.ok(team.includes("$teamCanAdd=!empty($teamState['can_add'])"), 'add form and CTA must use the canonical can_add state');
assert.ok(team.includes("$adminTitle='My Team'"), 'Team management must use the My Team product label');

assert.ok(sidebar.includes("team_subscription_state($mainSidebarUser)"), 'member navigation must use canonical Team package state');
assert.ok(sidebar.includes('<strong>My Team</strong>'), 'authorized Artist owners must have a My Team entry in the account navigation');
assert.ok(sidebar.includes("$mainSidebarActive === 'team'"), 'My Team must participate in canonical active navigation state');

console.log('TEAM_SUBSCRIPTION_CONTRACT=PASS');
