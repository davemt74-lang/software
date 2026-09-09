import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const state = read('includes/team-subscription.php');
const team = read('team.php');
const legacyAdminTeam = read('admin/team.php');
const sidebar = read('includes/main-sidebar.php');
const memberNav = read('includes/member-navigation.php');
const bootstrap = read('includes/bootstrap.php');

assert.ok(bootstrap.includes("require_once __DIR__.'/team-subscription.php';"), 'canonical Team package state must load globally');
assert.ok(state.includes("subscription_entitlement_row((int)$subscription['package_id'],'team_seats')"), 'Team capacity must come from the package team_seats entitlement');
assert.ok(state.includes("$row['limit_value']===null"), 'blank package Team limit must be handled explicitly');
assert.ok(state.includes("$state['unlimited']=true"), 'NULL Team limits must be represented as unlimited rather than a hidden hard-coded seat count');
assert.ok(state.includes("$state['limit']=2"), 'two-seat behavior may remain only as the pre-subscription migration fallback');
assert.ok(state.includes("$state['over_limit']=(int)$state['used']>$limit"), 'downgrades must detect over-limit Teams without deleting members');
assert.ok(state.includes("$state['can_add']=$state['authorized']"), 'add-member availability must combine authorization with package capacity');
assert.ok(state.includes("$isInternalAdmin=function_exists('subscription_is_internal_admin')&&subscription_is_internal_admin($user)"), 'canonical Team state must explicitly recognize internal admins');
assert.ok(state.includes("$state['authorized']=$isInternalAdmin||(user_has_role('artist',$user)"), 'internal admins must be authorized even without an Artist role');
assert.ok(state.indexOf("if($isInternalAdmin){") < state.indexOf("subscription_schema_ready($pdo)"), 'internal admin unlimited Team access must not depend on subscription schema/package assignment');
assert.ok(state.includes("$state['package_name']='Internal Admin'"), 'internal admin Team state must be explicit');

assert.ok(team.includes("$teamState=team_subscription_state($user,$pdo)"), 'front-end My Team must render from canonical package state');
assert.ok(team.includes("$lockedTeamState=team_subscription_state($user,$pdo)"), 'add-member requests must re-read package capacity after the owner lock');
assert.ok(team.indexOf("SELECT id FROM users WHERE id=? FOR UPDATE") < team.indexOf("$lockedTeamState=team_subscription_state($user,$pdo)"), 'package capacity must be refreshed after acquiring the account lock');
assert.ok(!team.includes("subscription_package_grants_permission($user,'team.manage')"), 'Team seats, not a duplicate package permission flag, must be the commercial Team authority');
assert.ok(!team.includes("subscription_package_grants_permission($user,'admin.access')"), 'package seat state must not be coupled to an unrelated Admin package flag');
assert.ok(team.includes("$teamInternalAdmin=function_exists('subscription_is_internal_admin')&&subscription_is_internal_admin($user)"), 'My Team page must recognize internal admins');
assert.ok(team.includes("if(!$teamInternalAdmin){"), 'Artist role/permission gates must apply only to non-admin Team owners');
assert.ok(team.includes("Existing relationships remain intact"), 'downgrade UI must explicitly preserve existing Team relationships');
assert.ok(team.includes("No one was removed by the package change"), 'over-limit downgrade UI must be non-destructive');
assert.ok(team.includes("url('/subscription.php')"), 'limit/locked states must link to plan management');
assert.ok(team.includes("$teamCanAdd=!empty($teamState['can_add'])"), 'add form and CTA must use the canonical can_add state');
assert.ok(team.includes("$workspaceSidebarActive='team'"), 'My Team must render inside the member/front-end workspace shell');
assert.ok(team.includes("$memberHeaderTitle='My Team'"), 'My Team must use the shared member header instead of the admin shell');
assert.doesNotMatch(team, /admin\/_header\.php|admin-card|admin-grid/, 'front-end My Team must not render the admin Team interface');

assert.ok(legacyAdminTeam.includes("$target=url('/team.php')"), 'legacy admin Team route must resolve the canonical front-end My Team target');
assert.ok(legacyAdminTeam.includes("header('Location: '.$target,true,307)"), 'legacy admin Team route must issue a method-preserving redirect to the canonical target');
assert.match(legacyAdminTeam, /307/, 'legacy Team redirect must preserve stale POST methods/bodies');

assert.match(sidebar, /member_navigation_menu_links\(\$mainSidebarUser\)/, 'Agent sidebar must source secondary navigation from canonical member navigation');
assert.match(sidebar, /data-agent-user-footer/, 'My Team must live in the Agent bottom user menu rather than compete with primary tools');
assert.ok(memberNav.includes("team_subscription_state($user)"), 'canonical member navigation must use Team package state');
assert.ok(memberNav.includes("'team','My Team',url('/team.php')"), 'authorized Team owners must retain a My Team destination');
assert.doesNotMatch(sidebar, /<strong>My Team<\/strong>/, 'My Team must not appear in the primary Agent tool rail');
assert.doesNotMatch(memberNav, /'my_team','My Team'/, 'legacy duplicate My Team key must stay retired');

console.log('TEAM_SUBSCRIPTION_CONTRACT=PASS');