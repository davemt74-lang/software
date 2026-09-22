import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const runtime=read('includes/client-release-intelligence-v100.php');
const token=read('includes/extension-device-token-v2100.php');
const me=read('api/extension-me.php');
const notifications=read('includes/notifications.php');
const cognitive=read('includes/cognitive-feed-v530.php');
const extNotifications=read('includes/extension-notifications-v2140.php');
const browserPage=read('connected-browsers.php');
const homePage=read('settings-homeserver.php');
const clientPage=read('client-updates.php');
const clientCss=read('client-updates-v100.css');
const nav=read('includes/member-navigation.php');
const admin=read('admin/homeserver.php');
const bootstrap=read('includes/bootstrap.php');
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const manifest=JSON.parse(read('browser-companion/manifest.json'));

assert.match(runtime,/VP3_CLIENT_RELEASE_INTELLIGENCE_V100/);
assert.match(runtime,/client_release_version_state_v100/);
assert.match(runtime,/version_compare\(/);
assert.match(runtime,/client_release_browser_snapshot_v100/);
assert.match(runtime,/client_release_homeserver_snapshot_v100/);
assert.match(runtime,/client_release_intelligence_snapshot_v100/);
assert.match(runtime,/client_release_intelligence_reconcile_user_v100/);
assert.match(runtime,/client_release_intelligence_reconcile_all_v100/);
assert.match(runtime,/client_release_intelligence_admin_summary_v100/);
assert.match(runtime,/client_release_browser_latest_v100/);
assert.match(runtime,/'rollout' => 'general_availability'/);
assert.match(runtime,/'client_release_browser'/);
assert.match(runtime,/'client_release_homeserver'/);
assert.match(runtime,/'client_release_update'/);
assert.match(runtime,/client-updates\.php#browser-companion/);
assert.match(runtime,/client-updates\.php#homeserver/);
assert.match(runtime,/is_read=1,read_at=COALESCE\(read_at,NOW\(\)\)/,'resolved updates must close stale notifications');
assert.doesNotMatch(runtime,/shell_exec\s*\(|proc_open\s*\(|passthru\s*\(/,'release intelligence must never install or execute client software');

assert.match(token,/function vp3_extension_reported_version_v2100/);
assert.match(token,/HTTP_X_VP3_EXTENSION_VERSION/);
assert.match(token,/SET extension_version=\?,last_used_at=NOW\(\),updated_at=NOW\(\)/);
assert.match(token,/'extension_version'=\>\(string\)\(\$row\['extension_version'\]/);
assert.match(me,/'release'=\>\[/);
assert.match(me,/'installed_version'=\>\$installedVersion/);
assert.match(me,/'update_available'=\>\$releaseState==='update_available'/);

assert.match(notifications,/\$type === 'client_release_update'/,'client update must be a canonical attention event');
assert.match(cognitive,/client_release_intelligence_reconcile_user_v100/,'Agent Now must reconcile client updates before reading notifications');
assert.match(extNotifications,/client_release_intelligence_reconcile_user_v100/,'Browser proactive notifications must reconcile release updates before delivery');

assert.match(clientPage,/Client Release Operations[\s\S]*Controlled Rollouts v1\.10/);
assert.match(clientPage,/id="browser-companion"/);
assert.match(clientPage,/id="homeserver"/);
assert.match(clientPage,/never installs (?:a HomeServer build|them) automatically/);
assert.match(clientPage,/Agent Now and Browser Companion notifications/);
assert.match(clientCss,/\.client-release-card/);
assert.match(browserPage,/Client Updates/);
assert.match(browserPage,/Download update/);
assert.match(homePage,/client-updates\.php#homeserver/);
assert.match(nav,/'client-updates\.php'=>'client_updates'/);
assert.match(nav,/'client_updates','Client Updates',url\('\/client-updates\.php'\)/);

assert.match(admin,/Release Intelligence/);
assert.match(admin,/client_release_intelligence_admin_summary_v100/);
assert.match(admin,/client_release_intelligence_reconcile_all_v100/);
assert.match(admin,/Current \/ ahead/);
assert.match(admin,/Update available/);
assert.match(admin,/Unknown/);

assert.match(bootstrap,/client-release-intelligence-v100\.php/);
assert.equal(manifest.version,'22.9.0');
assert.match(manifest.description,/Client Release Intelligence/);
assert.match(background,/const VP3_EXTENSION_VERSION = '22\.9\.0';/);
assert.match(background,/release: account\.release \|\| null/);
assert.match(panel,/Update available/);
assert.match(panel,/installed_version/);

console.log('Client Release Intelligence v1.00 contract: PASS');
