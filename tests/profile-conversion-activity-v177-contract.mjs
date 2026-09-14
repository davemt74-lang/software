import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const helper = read('includes/profile-conversion-activity-v177.php');
const booking = read('public-booking-controller-v700.php');
const product = read('profile-commerce-product.php');
const portal = read('profile-agent-portal.js');

assert.match(helper, /\['booking_intent', 'product_intent'\]/, 'conversion activity must use a narrow event allow-list');
assert.match(helper, /empty\(\$profile\['is_active'\]\).*empty\(\$profile\['is_public'\]\)/s, 'conversion activity requires an active public profile');
assert.match(helper, /current_user\(\)/, 'conversion activity must use the canonical signed-in viewer when available');
assert.match(helper, /\(int\)\(\$viewer\['id'\] \?\? 0\) === \$ownerUserId/, 'profile owners must not create conversion visitor activity');
assert.match(helper, /profile_runtime_session\(\$pdo, \$ownerUserId, \$viewer, false\)/, 'conversion activity must reuse the existing owner-scoped visitor session without incrementing profile views');
assert.match(helper, /profile_event_create\(/, 'conversion activity must use the existing Profile event ledger');
assert.match(helper, /VP3_PROFILE_CONVERSION_DEDUPE_SECONDS_V177 = 1800/, 'conversion intent must be deduped in a bounded window');
assert.match(helper, /hash\('sha256'.*\$session\['session_key'\]/s, 'dedupe identity must use the existing owner-scoped session hash');
assert.match(helper, /profile_visitor_request_context_v243/, 'conversion events should retain the existing privacy-safe request attribution context');
assert.match(helper, /target_title/, 'conversion event metadata must retain the resolved public target title');
assert.doesNotMatch(helper, /REMOTE_ADDR|HTTP_USER_AGENT|fingerprint/i, 'conversion activity must not introduce IP or user-agent fingerprinting');
assert.doesNotMatch(helper, /profile_attention_from_event|create_notification\(/, 'conversion intent must remain activity-only and avoid notification noise');
assert.doesNotMatch(helper, /CREATE TABLE|ALTER TABLE/i, 'conversion activity must remain migration-free');

assert.match(booking, /profile-conversion-activity-v177\.php/, 'public Booking must load conversion activity recorder');
assert.match(booking, /REQUEST_METHOD'\] === 'GET'.*\$manageToken === ''.*\$schedule && \$events/s, 'Booking intent must only record a valid public booking destination, never a private management link');
assert.match(booking, /profile_conversion_activity_v177_record\(\$pdo, \$profile, 'booking_intent'/, 'public Booking must record booking intent');
assert.match(booking, /agent_scheduling_public_booking_url_v450/, 'Booking intent target URL must remain canonical');

assert.match(product, /profile-conversion-activity-v177\.php/, 'public Product page must load conversion activity recorder');
assert.match(product, /profile_commerce_public_product_v900/, 'Product intent must follow the canonical public Commerce projection');
assert.match(product, /REQUEST_METHOD'\]===\s*'GET'\)profile_conversion_activity_v177_record\(\$pdo,\$profile,'product_intent'/, 'Product intent must record only after a public product resolves on GET');
assert.match(product, /'url'=>\(string\)\$product\['product_url'\]/, 'Product intent must retain the canonical product URL');

assert.match(portal, /\(state\?\.activity\|\|\[\]\)\.forEach/, 'existing Profile Agent Radar timeline must consume Profile activity events');
assert.match(portal, /event_type\|\|'profile activity'\)\.replaceAll\('_',' '\)/, 'new intent event types must render through the existing human activity timeline without another UI store');

console.log('profile-conversion-activity-v177-contract: ok');
