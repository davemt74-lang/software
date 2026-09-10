import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const state = read('includes/team-subscription.php');
const lifecycle = read('includes/team-workspace-lifecycle-v350.php');
const team = read('team.php');
const legacyAdminTeam = read('admin/team.php');
const sidebar = read('includes/main-sidebar.php');
const memberNav = read('includes/member-navigation.php');
const bootstrap = read('includes/bootstrap.php');

assert.ok(bootstrap.includes("require_once __DIR__.'/team-subscription.php';"), 'canonical Team package state must load globally');
assert.ok(state.includes("subscription_has_entitlement($user,'team_seats')"), 'Team availability must use the composed effective team_seats entitlement');
assert.ok(state.includes("subscription_entitlement_limit($user,'team_seats',0)"), 'Team capacity must use the composed effective team_seats limit so add-on seats are included');
assert.ok(!state.includes("subscription_entitlement_row((int)$subscription['package_id'],'team_seats')"), 'Team capacity must not bypass add-on composition with a direct base-package lookup');
assert.ok(state.includes("$state['unlimited']=true"), 'NULL effective Team limits must be represented as unlimited rather than a hidden hard-coded seat count');
assert.ok(state.includes("$state['limit']=2"), 'two-seat behavior may remain only as the pre-subscription migration fallback');
assert.ok(state.includes("$state['over_limit']=(int)$state['used']>$limit"), 'downgrades must detect over-limit Teams without deleting members');
assert.ok(state.includes("$state['can_add']=$state['authorized']"), 'seat activation availability must combine authorization with effective entitlement capacity');
assert.ok(state.includes("$isInternalAdmin=function_exists('subscription_is_internal_admin')&&subscription_is_internal_admin($user)"), 'canonical Team state must explicitly recognize internal admins');
assert.ok(state.includes("if(function_exists('music_workspace_enabled_v320'))$workspaceOwner=music_workspace_enabled_v320($user);"), 'Music Workspace enablement must be a canonical Team-owner authority');
assert.ok(state.includes("if(!$workspaceOwner&&function_exists('artist_workspace_v104_is_artist'))$workspaceOwner=artist_workspace_v104_is_artist($user);"), 'legacy workspace context may remain only as a migration fallback');
assert.ok(state.includes("music_workspace_owner_permission_v320('team.manage',$user)"), 'Team ownership must still require the scoped Team management capability');
assert.ok(state.includes("$state['authorized']=$isInternalAdmin||($workspaceOwner&&$canManage);"), 'internal admins or capable workspace owners must be authorized without a global Artist role');
assert.ok(!state.includes("user_has_role('artist',$user)"), 'canonical Team authorization must not depend on a global Artist role');
assert.ok(state.indexOf("if($isInternalAdmin){") < state.indexOf("subscription_schema_ready($pdo)"), 'internal admin unlimited Team access must not depend on subscription schema/package assignment');
assert.ok(state.includes("$state['package_name']='Internal Admin'"), 'internal admin Team state must be explicit');
assert.ok(state.includes('u.is_active=1'), 'only active Team members may consume commercial Team seats');

assert.ok(team.includes("$teamState=team_subscription_state($user,$pdo)"), 'front-end My Team must render and authorize from canonical entitlement/workspace state');
assert.ok(team.includes("if(empty($teamState['authorized']))"), 'unauthorized accounts must be denied by canonical Team state');
assert.ok(!team.includes("subscription_package_grants_permission($user,'team.manage')"), 'Team seats, not a duplicate package permission flag, must be the commercial Team authority');
assert.ok(!team.includes("subscription_package_grants_permission($user,'admin.access')"), 'package seat state must not be coupled to an unrelated Admin package flag');
assert.ok(team.includes("$teamInternalAdmin=function_exists('subscription_is_internal_admin')&&subscription_is_internal_admin($user)"), 'My Team page must recognize internal admins');
assert.ok(!team.includes("user_has_role('artist',$user)"), 'My Team page must not require the retired global Artist identity');
assert.match(team, /Existing membership history remains intact|Existing relationships remain intact/, 'downgrades must explicitly preserve existing Team relationships');
assert.match(team, /No relationship was deleted by the package change|No one was removed by the package change/, 'over-limit downgrade UI must be non-destructive');
assert.ok(team.includes("url('/subscription.php')"), 'limit/locked states must link to plan management');
assert.ok(team.includes("$teamCanAdd=!empty($teamState['can_add'])"), 'Team UI must still expose current activation capacity');
assert.ok(team.includes("$teamCanInvite=!empty($teamState['included'])"), 'pending invitation availability must depend on Team product eligibility, not a free seat');
assert.ok(team.includes("$workspaceSidebarActive='team'"), 'My Team must render inside the member/front-end workspace shell');
assert.ok(team.includes("$memberHeaderTitle='My Team'"), 'My Team must use the shared member header instead of the admin shell');
assert.doesNotMatch(team, /admin\/_header\.php|admin-card|admin-grid/, 'front-end My Team must not render the admin Team interface');

// Seat capacity is an activation invariant, not an invitation invariant. The
// lifecycle service locks the owner/member/membership rows and rechecks the
// composed entitlement immediately before an inactive membership becomes active.
const activateStart=lifecycle.indexOf('function workspace_team_v350_activate_member');
const activateEnd=lifecycle.indexOf('function workspace_team_v350_change_role',activateStart);
const activateBody=lifecycle.slice(activateStart,activateEnd);
const capacityStart=lifecycle.indexOf('function workspace_team_v350_assert_can_activate');
const capacityEnd=lifecycle.indexOf('function workspace_team_v350_activate_member',capacityStart);
const capacityBody=lifecycle.slice(capacityStart,capacityEnd);
const inviteStart=lifecycle.indexOf('function workspace_team_v350_create_invitation');
const inviteEnd=lifecycle.indexOf('function workspace_team_v350_invitation(',inviteStart);
const inviteBody=lifecycle.slice(inviteStart,inviteEnd);
assert.ok(activateStart>=0 && activateEnd>activateStart, 'canonical Team activation function must exist');
assert.match(activateBody, /workspace_team_v350_user\(\$pdo,\$ownerId,true\)/, 'activation must lock the workspace owner');
assert.match(activateBody, /workspace_team_v350_user\(\$pdo,\$memberId,true\)/, 'activation must lock the member account');
assert.match(activateBody, /workspace_team_v350_membership\(\$pdo,\$ownerId,\$memberId,true\)/, 'activation must lock the membership row');
assert.match(activateBody, /workspace_team_v350_assert_can_activate/, 'activation must pass through the canonical capacity assertion');
assert.match(capacityBody, /team_subscription_state\(\$owner,\$pdo\)/, 'activation capacity must re-read the composed Team entitlement');
assert.match(capacityBody, /!\$alreadyActive&&empty\(\$state\['can_add'\]\)/, 'a new or resumed seat must fail closed when capacity is unavailable');
assert.match(inviteBody, /empty\(\$state\['authorized'\]\)\|\|empty\(\$state\['included'\]\)/, 'creating an invitation must still require Team product eligibility');
assert.doesNotMatch(inviteBody, /\$state\['can_add'\]/, 'pending invitations must not consume or require an available seat');

assert.ok(legacyAdminTeam.includes("$target=url('/team.php')"), 'legacy admin Team route must resolve the canonical front-end My Team target');
assert.ok(legacyAdminTeam.includes("header('Location: '.$target,true,307)"), 'legacy admin Team route must issue a method-preserving redirect to the canonical target');
assert.match(legacyAdminTeam, /307/, 'legacy Team redirect must preserve stale POST methods/bodies');

assert.match(sidebar, /member_navigation_menu_links\(\$mainSidebarUser\)/, 'Agent sidebar must source secondary navigation from canonical member navigation');
assert.match(sidebar, /data-agent-user-footer/, 'My Team must live in the Agent bottom user menu rather than compete with primary tools');
assert.ok(memberNav.includes("team_subscription_state($user)"), 'canonical member navigation must use Team entitlement/workspace state');
assert.ok(memberNav.includes("'team','My Team',url('/team.php')"), 'authorized Team owners must retain a My Team destination');
assert.doesNotMatch(sidebar, /<strong>My Team<\/strong>/, 'My Team must not appear in the primary Agent tool rail');
assert.doesNotMatch(memberNav, /'my_team','My Team'/, 'legacy duplicate My Team key must stay retired');

console.log('TEAM_SUBSCRIPTION_CONTRACT=PASS');
