import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const external = read('includes/agent-radar-external.php');
const foundation = read('includes/agent-radar-foundation.php');
const bootstrap = read('includes/bootstrap.php');
const sitesApi = read('api/agent-radar-sites.php');
const collectApi = read('api/radar-collect.php');
const tracker = read('vp3-radar.js');
const portal = read('profile-agent.php');
const sitesUi = read('profile-agent-radar-sites.js');
const sitesCss = read('profile-agent-radar-sites.css');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-radar-external.php';"), 'bootstrap must load external Radar runtime');
assert.ok(external.includes('vp3_radar_can_add_external_site($user,$pdo)'), 'external site registration must use canonical package entitlement limits');
assert.ok(foundation.includes("const VP3_RADAR_SITE_CAPABILITY = 'analytics.sites'"), 'external sites must remain governed by analytics.sites');
assert.ok(external.includes("property_type='external'"), 'external websites must use canonical Radar properties');
assert.ok(external.includes('bin2hex(random_bytes(20))'), 'external sites must receive unguessable 40-hex public keys');
assert.ok(external.includes('VP3_RADAR_EXTERNAL_SESSION_SECONDS = 1800'), 'external agent sessions must use coarse 30-minute grouping');
assert.ok(external.includes("hash('sha256',$propertyId.'|'.$contactId.'|'.$bucket)"), 'external sessions must aggregate by property/contact/time without fingerprinting');
assert.ok(external.includes('vp3_radar_native_identity($pdo'), 'external traffic must reuse the same Agent CRM identities as the native VP3 profile');
assert.ok(external.includes('vp3_radar_risk_score($signals)'), 'external traffic must use canonical deterministic risk scoring');
assert.ok(external.includes('vp3_radar_sync_agent_memory'), 'meaningful external activity must flow into canonical Agent Brain memory');
assert.ok(external.includes("'radar_external_security_action'"), 'high-risk external activity must use Main Feed attention');
assert.ok(external.includes("'radar_external_visit_needs_attention'"), 'meaningful external AI/search visits must use Main Feed attention');
assert.ok(external.includes("'agent_activity_radar_external_visit'"), 'routine external crawler activity must use Agent Brain operational activity');
assert.ok(external.includes("if(!vp3_radar_looks_automated($userAgent))return false;"), 'normal human browsers must be discarded before Radar persistence');
assert.ok(!external.includes('REMOTE_ADDR'), 'external Radar runtime must not use raw IP addresses');

assert.ok(sitesApi.includes("has_permission('account.access',$user)"), 'connected-site management must require account access');
assert.ok(sitesApi.includes("personal_capability_has_v242('profile_agent.access',$user)"), 'connected-site management must require Profile Agent access');
assert.ok(sitesApi.includes('hash_equals(csrf_token(),$csrf)'), 'connected-site writes must require CSRF');
assert.ok(!sitesApi.includes("$_GET['owner"), 'connected-site API must never accept arbitrary owner ids');

assert.ok(collectApi.includes('vp3_radar_external_property_by_key'), 'collector must resolve only a registered active Radar property');
assert.ok(collectApi.includes('vp3_radar_external_origin_allowed'), 'collector must validate Origin or Referer against the registered domain');
assert.ok(collectApi.includes("header('Access-Control-Allow-Origin: '.$origin)"), 'collector must return only the validated requesting Origin');
assert.ok(collectApi.includes('strlen($raw)>8192'), 'collector must cap public request bodies');
assert.ok(collectApi.includes('vp3_radar_external_collect'), 'collector must route through canonical external Radar runtime');
assert.ok(!collectApi.includes('REMOTE_ADDR'), 'collector must never read raw IP addresses');

assert.ok(tracker.includes('document.currentScript'), 'tracker must bind the public key to its own script tag');
assert.ok(tracker.includes('location.pathname'), 'tracker may send pathname');
assert.ok(tracker.includes('document.referrer'), 'tracker may reduce referrer to hostname');
assert.ok(tracker.includes("credentials:'omit'"), 'tracker must not send cookies or site credentials');
assert.ok(tracker.includes('keepalive:true'), 'tracker should survive fast page exits');
assert.ok(!/localStorage|sessionStorage|document\.cookie|indexedDB|canvas|AudioContext|navigator\.(?:userAgentData|hardwareConcurrency|deviceMemory)|screen\./.test(tracker), 'tracker must not use persistent browser state or fingerprinting signals');
assert.ok(!tracker.includes('location.search'), 'tracker must not send query strings');
assert.ok(!tracker.includes('location.href'), 'tracker must not send full page URLs');

assert.ok(portal.includes("'radarSitesEndpoint'=>url('/api/agent-radar-sites.php')"), 'Radar portal must expose connected-site management endpoint');
assert.ok(portal.includes("'radarScriptUrl'=>url('/vp3-radar.js?v=agent-radar-external-sites-20260906')"), 'install snippet must use a versioned tracker URL');
assert.ok(portal.includes('/profile-agent-radar-sites.css'), 'Connected Sites must have isolated styles');
assert.ok(portal.includes('/profile-agent-radar-sites.js'), 'Connected Sites UI must load after the Radar portal');
assert.ok(sitesUi.includes('data-vp3-key'), 'Connected Sites UI must generate a keyed install snippet');
assert.ok(sitesUi.includes('sitesState.can_add'), 'Connected Sites UI must honor package capacity');
assert.ok(sitesUi.includes('MutationObserver'), 'Connected Sites shell must survive the parent Radar 15-second rerender');
assert.ok(sitesUi.includes("action:'set_active'"), 'Connected Sites UI must support pause/reactivate');
assert.ok(sitesUi.includes('navigator.clipboard.writeText'), 'Connected Sites UI must offer copyable install snippet');
assert.ok(sitesUi.includes('uses no cookies, local storage, account identity or fingerprinting'), 'Connected Sites UI must disclose privacy behavior');
assert.ok(sitesCss.includes('@media(max-width:620px)'), 'Connected Sites UI must have small-screen behavior');

console.log('AGENT_RADAR_EXTERNAL_SITES_CONTRACT=PASS');
