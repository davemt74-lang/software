import fs from 'node:fs';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';

const read = path => fs.readFileSync(path,'utf8');
const subscriptions=read('includes/subscriptions.php');
const grants=read('includes/subscription-entitlements-v340.php');
const access=read('includes/subscription-access.php');
const permissions=read('includes/permissions-v105.php');
const requestGates=read('includes/subscription-request-gates.php');
const packages=read('admin/packages.php');
const entitlementAdmin=read('admin/entitlements.php');
const team=read('includes/team-subscription.php');
const artistWorkspace=read('includes/artist-workspaces-v104.php');
const music=read('includes/music-workspace-plugin-v320.php');
const schema=read('includes/subscription-schema.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');

// The stable subscription API loads composition before access resolution.
assert.ok(subscriptions.indexOf('subscription-entitlements-v340.php') < subscriptions.indexOf('subscription-access.php'));
assert.match(grants,/CREATE TABLE IF NOT EXISTS user_entitlement_grants/);
assert.match(grants,/status='active' AND is_enabled=1/);
assert.match(grants,/starts_at<=NOW\(\) AND \(ends_at IS NULL OR ends_at>NOW\(\)\)/);
assert.match(grants,/subscription_grant_entitlement_v340/);
assert.match(grants,/subscription_revoke_entitlement_grant_v340/);
assert.match(grants,/\$state\['grant_limit'\]\+=\$delta/);
assert.match(grants,/\$state\['limit'\]\+=\$delta/);

// Commercial data must never encode security authority.
assert.match(grants,/str_starts_with\(\$key,'permission\.'\)/);
assert.match(grants,/DELETE FROM package_entitlements WHERE capability_key LIKE 'permission\.%' OR capability_key='legacy\.permissions'/);
assert.match(access,/function subscription_package_grants_permission[\s\S]*?return false;/);
assert.match(access,/function subscription_permissions_authoritative[\s\S]*?return false;/);
assert.match(access,/subscription_entitlement_key_is_product_v340/);
assert.doesNotMatch(permissions,/subscription_package_grants_permission/);
assert.doesNotMatch(permissions,/subscription_has_entitlement/);
assert.doesNotMatch(permissions,/subscription_current\(/);
assert.match(permissions,/FROM role_permissions WHERE permission_key=\?/);
assert.match(permissions,/Packages and add-ons buy product capabilities/);

// HTTP gates may check purchased product availability, never security authority.
assert.match(requestGates,/return has_permission\(\$permission,\$user\);/);
assert.match(requestGates,/subscription_has_entitlement\(\$user,\$capability\)/);
assert.match(requestGates,/product-availability check only/);
assert.doesNotMatch(requestGates,/subscription_package_grants_permission/);
assert.doesNotMatch(requestGates,/legacy\.permissions/);
assert.match(requestGates,/role\/workspace\/resource authorization/);

// Workspace identity comes from canonical ownership/plugin state, not package permissions.
assert.match(artistWorkspace,/artist_workspace_v104_user_owns_workspace\(\$pdo,\$userId\)/);
assert.match(artistWorkspace,/music_workspace_enabled_v320\(\$user\)/);
assert.match(artistWorkspace,/Product entitlement alone[\s\S]*never creates workspace\/security authority/);
assert.doesNotMatch(artistWorkspace,/subscription_package_grants_permission/);
assert.doesNotMatch(artistWorkspace,/legacy\.permissions/);
assert.doesNotMatch(artistWorkspace,/artist_workspace_v104_artist_package_permissions/);

// Package editor no longer exposes or duplicates permission-shaped rows.
assert.doesNotMatch(packages,/permission_catalog\(\)/);
assert.doesNotMatch(packages,/subscription_permission_key/);
assert.match(packages,/Security authority is managed separately through roles and workspace membership/);
assert.match(packages,/capability_key NOT LIKE 'permission\.%'/);
assert.match(packages,/DELETE FROM package_entitlements[\s\S]*capability_key LIKE 'permission\.%'[\s\S]*legacy\.permissions/);

// Add-ons are independently operable by Admin and never mutate the base package.
assert.match(entitlementAdmin,/require_permission\('users\.manage'\)/);
assert.match(entitlementAdmin,/subscription_grant_entitlement_v340/);
assert.match(entitlementAdmin,/subscription_revoke_entitlement_grant_v340/);
assert.match(entitlementAdmin,/subscription_entitlement_key_is_product_v340/);
assert.doesNotMatch(entitlementAdmin,/UPDATE user_subscriptions/);
assert.doesNotMatch(entitlementAdmin,/UPDATE subscription_packages/);

// Existing consumers get composition through the stable entitlement API.
assert.match(music,/function music_workspace_capability_key_v320\(\): string\{return 'music_workspace\.access';\}/);
assert.match(music,/subscription_has_entitlement\(\$user,music_workspace_capability_key_v320\(\)\)/);
assert.match(music,/function music_workspace_legacy_package_v340/);
assert.match(music,/package_slug[^\n]*legacy-access/);
assert.doesNotMatch(music,/legacy\.permissions/,'Music grandfathering must not depend on retired permission entitlements');
assert.match(team,/subscription_has_entitlement\(\$user,'team_seats'\)/);
assert.match(team,/subscription_entitlement_limit\(\$user,'team_seats',0\)/);
assert.doesNotMatch(team,/subscription_entitlement_row\([^\n]*team_seats/);

// Retired permission-shaped seed rows must not be recreated after v3.40.
assert.doesNotMatch(schema,/['\"]permission\.[a-z0-9._-]+['\"]\s*=>/);
assert.doesNotMatch(schema,/['\"]legacy\.permissions['\"]\s*=>/);

// Fresh installs and upgrades explicitly install and require v3.40 grants.
assert.match(setup,/subscription_entitlements_v340_ensure_schema\(\$pdo\)/);
assert.match(upgrade,/subscription_entitlements_v340_schema_ready\(\)/);
assert.match(upgrade,/subscription_entitlements_v340_ensure_schema\(\)/);
assert.match(upgrade,/Existing accounts, package assignments, team memberships, token balances, music content/);

// Key validation itself is executable without a database.
const probe=spawnSync('php',['-r',String.raw`
require 'includes/subscription-entitlements-v340.php';
$cases=[
 ['music_workspace.access',true],
 ['team_seats',true],
 ['storage_mb',true],
 ['permission.admin.access',false],
 ['legacy.permissions',false],
 ['',false],
];
foreach($cases as [$key,$expected]){
 if(subscription_entitlement_key_is_product_v340($key)!==$expected){fwrite(STDERR,'bad key: '.$key);exit(2);}
}
echo 'OK';
`],{encoding:'utf8'});
assert.equal(probe.status,0,probe.stderr||probe.stdout);
assert.match(probe.stdout,/OK/);

console.log('PACKAGE_ENTITLEMENT_COMPOSITION_V340=PASS');
