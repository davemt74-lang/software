import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const runtime = read('includes/profile-agent-runtime.php');
const portal = read('profile-agent-portal.js');
const analyticsCss = read('profile-agent-analytics.css');
const routes = read('.htaccess');

assert.match(runtime, /\$event\['conversion_kind'\].*\['booking','product'\]/s, 'owner activity must expose only known conversion kinds');
assert.match(runtime, /\$event\['target_id'\]/, 'owner activity must expose the resolved public target id');
assert.match(runtime, /\$event\['target_slug'\]/, 'owner activity must expose the resolved public target slug');
assert.match(runtime, /\$event\['target_title'\]/, 'owner activity must expose the resolved public target title');
assert.match(runtime, /\$event\['target_url'\].*FILTER_VALIDATE_URL/s, 'owner activity must validate target URLs before returning them');
assert.match(runtime, /parse_url\(\$targetUrl,PHP_URL_SCHEME\).*\['http','https'\]/s, 'owner activity must only expose HTTP(S) target URLs');
assert.match(runtime, /unset\(\$event\['visitor_user_id'\],\$event\['session_key'\],\$event\['metadata_json'\]\)/, 'raw session identity and metadata must remain hidden from the owner API');

assert.match(runtime, /event_type='booking_intent'/, 'analytics must count booking intent from the existing Profile event ledger');
assert.match(runtime, /event_type='product_intent'/, 'analytics must count product intent from the existing Profile event ledger');
assert.match(runtime, /conversion_intents_24h/, 'analytics must expose a recent intent signal');
assert.doesNotMatch(runtime, /CREATE TABLE|ALTER TABLE/i, 'conversion intelligence must remain migration-free');

assert.match(portal, /function humanActivityDetail\(e\)/, 'Radar must have a conversion-aware human activity detail renderer');
assert.match(portal, /e\?\.target_title/, 'Radar must show the resolved Booking/Product title when present');
assert.match(portal, /e\.target_url.*target="_blank" rel="noopener"/s, 'resolved target URLs must be isolated when opened from owner Radar');
assert.match(portal, /Conversion intent · 24h/, 'top Profile Agent metrics must surface recent conversion intent');
assert.match(portal, /Booking intent/, 'analytics must display Booking intent');
assert.match(portal, /Product intent/, 'analytics must display Product intent');
assert.match(portal, /Human intent · 24h/, 'Agent Radar metrics must surface first-party human intent');
assert.match(portal, /String\(e\.event_type\|\|'profile activity'\)\.replaceAll\('_',' '\)/, 'generic Profile activity rendering must remain intact');
assert.doesNotMatch(portal, /v\.request_count/, 'human Profile sessions must not inherit automated request-count UI');

assert.match(analyticsCss, /\.profile-agent-metrics\{grid-template-columns:repeat\(6,minmax\(0,1fr\)\)\}/, 'six headline metrics must fit one desktop grid row');
assert.match(routes, /profile-agent-portal\)\\\.js\$[^]*Cache-Control "no-cache, must-revalidate"/, 'Profile Agent portal intelligence must not be hidden behind stale static caching');
assert.match(routes, /profile-agent-analytics\)\\\.\(\?:js\|css\)\$[^]*Cache-Control "no-cache, must-revalidate"/, 'Profile analytics layout changes must not be hidden behind stale static caching');

console.log('profile-conversion-intelligence-v178-contract: ok');
