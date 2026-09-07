import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap=read('includes/bootstrap.php');
const analytics=read('includes/vp3-analytics.php');
const dashboard=read('includes/vp3-analytics-dashboard.php');
const collect=read('api/analytics-collect.php');
const api=read('api/vp3-analytics.php');
const tracker=read('vp3-analytics.js');
const portal=read('profile-agent.php');
const ui=read('profile-agent-analytics.js');
const css=read('profile-agent-analytics.css');
const bridge=read('profile-agent-analytics-bridge.js');
const sitesUi=read('profile-agent-radar-sites.js');

assert.ok(bootstrap.includes("require_once __DIR__.'/vp3-analytics.php';"), 'bootstrap must load VP3 Analytics runtime');
assert.ok(bootstrap.includes("require_once __DIR__.'/vp3-analytics-dashboard.php';"), 'bootstrap must load unified Analytics dashboard runtime');

assert.ok(analytics.includes('if(vp3_radar_looks_automated($userAgent))return false;'), 'human analytics collector must reject automated traffic before persistence');
assert.ok(analytics.includes("agent_contact_id,session_key,visitor_type") && analytics.includes("NULL,?,'human'"), 'human analytics sessions must remain separate from Agent CRM contacts');
assert.ok(analytics.includes("hash('sha256',$propertyId.'|'.$clientSession.'|'.$bucket)"), 'human sessions must use property-local short-lived identifiers');
assert.ok(!analytics.includes('REMOTE_ADDR'), 'Analytics runtime must not use raw visitor IP addresses');
assert.ok(analytics.includes('vp3_radar_external_path'), 'Analytics must store pathname only through the canonical sanitizer');
assert.ok(analytics.includes('vp3_radar_external_referrer_host'), 'Analytics must reduce referrers to hostnames');
assert.ok(analytics.includes("'browser_family'=>vp3_analytics_browser_family"), 'raw User-Agent must be reduced to coarse browser family before storage');
assert.ok(analytics.includes("'device_type'=>vp3_analytics_device_type"), 'raw User-Agent must be reduced to coarse device type before storage');
assert.ok(analytics.includes("'analytics_page_view'"), 'human page views must have a normalized Analytics event type');
assert.ok(analytics.includes("'analytics_event'"), 'custom conversion/event tracking must use a normalized event type');
assert.ok(analytics.includes('VP3_ANALYTICS_SESSION_EVENT_CAP = 500'), 'anonymous browser sessions must have a bounded event cap');

assert.ok(collect.indexOf('vp3_radar_external_property_by_key') < collect.indexOf("if($method==='OPTIONS')"), 'preflight must resolve the registered property before returning CORS headers');
assert.ok(collect.indexOf('vp3_radar_external_origin_allowed') < collect.indexOf("header('Access-Control-Allow-Origin: '.$origin)"), 'collector must validate the registered Origin before reflecting it');
assert.ok(collect.includes('strlen($raw)>8192'), 'public Analytics collector must cap request bodies');
assert.ok(!collect.includes('REMOTE_ADDR'), 'public Analytics collector must not read visitor IP addresses');

assert.ok(tracker.includes('sessionStorage'), 'human analytics may use session-scoped browser state');
assert.ok(!tracker.includes('localStorage'), 'human analytics must not create persistent browser identifiers');
assert.ok(!tracker.includes('document.cookie'), 'human analytics must not use cookies');
assert.ok(tracker.includes('crypto.randomUUID') || tracker.includes('crypto.getRandomValues'), 'human analytics session identifiers should use browser randomness when available');
assert.ok(tracker.includes('30*60*1000'), 'browser session identity must roll after 30 minutes of inactivity');
assert.ok(tracker.includes('location.pathname'), 'tracker may transmit pathname');
assert.ok(!tracker.includes('location.search'), 'tracker must never transmit URL query strings');
assert.ok(!tracker.includes('location.href'), 'tracker must never transmit full URLs');
assert.ok(tracker.includes('document.referrer'), 'tracker may reduce referrer to hostname');
assert.ok(tracker.includes("credentials:'omit'"), 'analytics requests must not carry cookies/credentials');
assert.ok(tracker.includes('keepalive:true'), 'analytics page events should survive navigation');
assert.ok(tracker.includes('history.pushState') || tracker.includes("wrapHistory('pushState')"), 'Analytics must support SPA navigation');
assert.ok(tracker.includes('window.VP3.track'), 'connected sites must expose a simple custom event API');
assert.ok(!/canvas|AudioContext|hardwareConcurrency|deviceMemory|screen\./.test(tracker), 'Analytics must not fingerprint visitors');

assert.ok(api.includes("has_permission('account.access',$user)"), 'Analytics dashboard API must require account access');
assert.ok(api.includes("personal_capability_has_v242('profile_agent.access',$user)"), 'Analytics dashboard API must remain in the existing VP3/Profile Agent capability boundary');
assert.ok(!api.includes("$_GET['owner"), 'Analytics dashboard API must never accept an arbitrary owner id');
assert.ok(api.includes('vp3_analytics_dashboard_state_v2'), 'dashboard API must use unified native + connected property aggregation');
assert.ok(dashboard.includes('vp3_radar_native_property($pdo,$uid)'), 'the VP3 profile must always exist as an Analytics property');
assert.ok(dashboard.includes('profile_visit_sessions'), 'native human profile analytics must reuse the existing privacy-preserving profile session system');
assert.ok(dashboard.includes('human_session_count'), 'Connected Sites state must expose human session counts');
assert.ok(dashboard.includes('agent_session_count'), 'Connected Sites state must expose Agent Radar session counts separately');

assert.ok(portal.includes("'analyticsEndpoint'=>url('/api/vp3-analytics.php')"), 'Profile Agent must expose the owner Analytics endpoint');
assert.ok(portal.includes("'analyticsScriptUrl'=>url('/vp3-analytics.js?v=vp3-analytics-20260906')"), 'Connected Sites must expose a versioned Analytics install script');
assert.ok(portal.includes('/profile-agent-analytics.css'), 'Analytics must have isolated dashboard styles');
assert.ok(portal.includes('/profile-agent-analytics-bridge.js'), 'legacy Profile Agent Analytics renderer must be isolated from the full dashboard');
assert.ok(portal.includes('/profile-agent-analytics.js'), 'full Analytics dashboard must load in the existing Analytics tab');
assert.ok(bridge.includes("legacy.id='profileAgentAnalyticsLegacy'"), 'legacy renderer must keep its captured node without owning the full dashboard host');
assert.ok(bridge.includes('legacy.hidden=true'), 'legacy analytics snapshot must be hidden once the full dashboard mounts');

for(const label of ['All properties','7 days','30 days','90 days','Sessions','Page views','Conversions','Custom events','High-risk events','Top pages','Referrers','Device mix','Browser mix','Human + Agent activity'])assert.ok(ui.includes(label),`Analytics UI must include ${label}`);
assert.ok(ui.includes('property_id'), 'Analytics UI must support per-property filtering');
assert.ok(ui.includes('human_sessions') && ui.includes('agent_sessions'), 'Analytics UI must expose human vs agent session totals');
assert.ok(ui.includes('Strict privacy mode'), 'Analytics UI must explain its privacy posture');
assert.ok(css.includes('@media(max-width:760px)'), 'Analytics dashboard must remain usable on smaller screens');

assert.ok(sitesUi.includes('function trackingSnippet(site)'), 'Connected Sites must generate one VP3 Tracking install block');
assert.ok(sitesUi.includes('cfg.analyticsScriptUrl') && sitesUi.includes('cfg.radarScriptUrl'), 'VP3 Tracking must install Analytics and Agent Radar together');
assert.ok(sitesUi.includes('Copy VP3 Tracking'), 'Connected Sites must present one simple browser installation action');
assert.ok(sitesUi.includes('short-lived session-scoped random ID'), 'Connected Sites must explain anonymous human session handling');

console.log('VP3_ANALYTICS_CONTRACT=PASS');
