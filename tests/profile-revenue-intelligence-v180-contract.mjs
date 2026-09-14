import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=path=>fs.readFileSync(new URL(`../${path}`,import.meta.url),'utf8');
const helper=read('includes/profile-revenue-intelligence-v180.php');
const api=read('api/profile-revenue-intelligence.php');
const ui=read('profile-agent-analytics.js');
const css=read('profile-agent-analytics.css');

assert.match(helper,/VP3_PROFILE_REVENUE_INTELLIGENCE_V180/,'Phase 18 helper must expose a stable version marker');
assert.match(helper,/FROM profile_events WHERE owner_user_id=\?/,'intelligence must read from the existing owner-scoped Profile event ledger');
assert.match(helper,/event_type IN \('profile_view','booking_intent','product_intent','booking_converted','product_converted'\)/,'intelligence must use the narrow Profile funnel event set');
assert.doesNotMatch(helper,/\b(?:CREATE|ALTER|DROP)\s+TABLE\b/i,'Phase 18 must not create or alter analytics tables');
assert.doesNotMatch(helper,/\$_SERVER\s*\[\s*['"](?:REMOTE_ADDR|HTTP_USER_AGENT)['"]\s*\]/,'Phase 18 must not read raw IP or User-Agent data');
assert.match(helper,/function profile_revenue_money_add_v180[\s\S]*\$buckets\[\$currency\]/,'revenue must remain bucketed by validated currency');
assert.match(helper,/profile_revenue_currency_v180[\s\S]*\^\[a-z\]\{3\}\$/,'currency codes must be validated before aggregation');
assert.match(helper,/'attribution_model'=>'session_first_touch'/,'the owner API must declare its attribution model');
assert.match(helper,/utm_campaign/,'campaign attribution must use existing first-party UTM metadata');
assert.match(helper,/utm_source/,'source attribution must use existing first-party UTM metadata');
assert.match(helper,/referrer_host/,'referrer attribution must use the already-sanitized referrer hostname');
assert.match(helper,/'today'=>\[/,'intelligence must include a today period');
assert.match(helper,/'7d'=>\[/,'intelligence must include a seven-day period');
assert.match(helper,/'30d'=>\[/,'intelligence must include a thirty-day period');
assert.match(helper,/previousStart/,'period intelligence must calculate a previous-period comparator');
assert.match(helper,/profile_revenue_top_targets_v180/,'intelligence must rank products and booking types');
assert.match(helper,/profile_revenue_sources_v180/,'intelligence must aggregate acquisition sources');
assert.match(helper,/profile_revenue_opportunities_v180/,'intelligence must derive owner opportunities');
assert.match(helper,/'action_key'=>'review_offer'/,'conversion gaps must expose an advisory review action');
assert.match(helper,/'action_key'=>'promote_winner'/,'strong converters must expose an advisory promotion suggestion');
assert.doesNotMatch(helper,/create_notification\s*\(|profile_attention_from_event\s*\(|UPDATE\s+profile_events|INSERT\s+INTO\s+profile_events/i,'Phase 18 intelligence must be read-only and must not auto-act');

assert.match(api,/has_permission\('account\.access',\$user\)/,'revenue intelligence API must require an authenticated account');
assert.match(api,/personal_capability_has_v242\('profile_agent\.access',\$user\)/,'revenue intelligence API must remain owner capability-gated');
assert.match(api,/Cache-Control: no-store/,'revenue intelligence responses must not be cached');
assert.match(api,/REQUEST_METHOD[\s\S]*GET/,'revenue intelligence API must be GET-only');
assert.match(api,/profile_revenue_intelligence_v180\(\$pdo,\(int\)\$user\['id'\]\)/,'API must scope intelligence to the signed-in owner ID');

assert.match(ui,/\/api\/profile-revenue-intelligence\.php/,'Analytics UI must load the owner-only Phase 18 endpoint');
assert.match(ui,/From Profile traffic to real business/,'Analytics UI must surface the Phase 18 intelligence workspace');
assert.match(ui,/Tracked revenue · all time/,'Analytics UI must show tracked revenue');
assert.match(ui,/Top converting products \+ bookings/,'Analytics UI must show top offers');
assert.match(ui,/Conversion sources/,'Analytics UI must show acquisition attribution');
assert.match(ui,/Agent Intelligence/,'Analytics UI must show agent-derived opportunities');
assert.match(ui,/funnelCard\('today','Today'\)/,'Analytics UI must render today funnel comparison');
assert.match(ui,/funnelCard\('7d','7 days'\)/,'Analytics UI must render seven-day funnel comparison');
assert.match(ui,/funnelCard\('30d','30 days'\)/,'Analytics UI must render thirty-day funnel comparison');
assert.match(ui,/rel=\"noopener\"/,'external offer links must be isolated with noopener');
assert.match(ui,/const esc=v=>/,'all Phase 18 text must use the existing HTML escaping boundary');
assert.doesNotMatch(ui,/v\.request_count/,'human Profile analytics must not inherit automated request-count UI');
assert.match(ui,/Suggested actions are advisory until you explicitly choose to act/,'UI must state that opportunity actions are advisory');

assert.match(css,/\.profile-revenue-periods\{display:grid/,'Phase 18 period comparison cards must have a dedicated responsive layout');
assert.match(css,/\.profile-revenue-grid\{display:grid/,'Phase 18 target/source/opportunity sections must have a dedicated grid');
assert.match(css,/@media\(max-width:1100px\)[\s\S]*\.profile-revenue-grid\{grid-template-columns:1fr\}/,'Phase 18 intelligence must collapse responsively');

console.log('profile-revenue-intelligence-v180-contract: ok');
