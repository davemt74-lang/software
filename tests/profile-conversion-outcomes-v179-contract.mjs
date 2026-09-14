import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const outcomes = read('includes/profile-conversion-outcomes-v179.php');
const runtime = read('includes/profile-agent-runtime.php');
const commerceCore = read('includes/agent-commerce-v800-part4.php');
const booking = read('public-booking-controller-v700.php');
const product = read('profile-commerce-product.php');
const commerceReturn = read('profile-commerce-return.php');
const appointmentReturn = read('appointment-payment-return.php');
const portal = read('profile-agent-portal.js');
const bootstrap = read('includes/bootstrap.php');

assert.match(outcomes, /\['booking_converted', 'product_converted'\]/, 'outcomes must use the narrow Booking/Product conversion event allowlist');
assert.match(outcomes, /profile-conversion-outcome-v179\|' \.[^;]+\$eventType[^;]+\$sourceId/s, 'outcomes must dedupe from canonical entity identity');
assert.doesNotMatch(outcomes, /floor\(time\(|time\(\).*dedupe|bucket/i, 'outcome dedupe must not depend on a time bucket');
assert.match(outcomes, /SELECT \* FROM profile_visit_sessions WHERE id=\? AND owner_user_id=\?/, 'attributed Profile sessions must be owner-scoped before use');
assert.match(outcomes, /INSERT INTO profile_events \(owner_user_id,profile_session_id,visitor_user_id,profile_agent_id,event_type,priority,dedupe_key,metadata_json\) VALUES \(\?,NULL,NULL/, 'webhook outcomes must preserve real conversions without manufacturing visitor identity');
assert.doesNotMatch(outcomes, /CREATE TABLE|ALTER TABLE/i, 'conversion outcomes must remain migration-free');
assert.doesNotMatch(outcomes, /REMOTE_ADDR|HTTP_USER_AGENT|User-Agent|fingerprint/i, 'conversion outcomes must not add IP/User-Agent fingerprinting');
assert.match(outcomes, /profile_conversion_viewer_is_owner_v179/, 'owner self-conversions must be explicitly detectable');
assert.match(outcomes, /profile_conversion_attach_order_v179[\s\S]*profile_conversion_viewer_is_owner_v179\(\$ownerUserId\)/, 'owner self-purchases must not receive Profile conversion attribution');
assert.match(outcomes, /profile_conversion_booking_confirmed_v179[\s\S]*profile_conversion_viewer_is_owner_v179\(\$ownerUserId\)/, 'owner self-bookings must not become Profile conversions');
assert.match(outcomes, /profile_conversion_source.*profile_session_id.*profile_target_id.*profile_target_url/s, 'order attribution must use a narrow metadata allowlist');
assert.match(outcomes, /conversion_stage' => 'outcome'/, 'outcome events must be distinguishable from intent events');
assert.match(outcomes, /paymentStatus, \['paid', 'partially_paid'\]/, 'paid appointments may convert after verified full payment or deposit');
assert.match(outcomes, /agent_appointment_lifecycle_status_v700\(\$booking\) !== 'confirmed'/, 'paid Booking outcomes must require confirmed appointment lifecycle state');
assert.match(outcomes, /source === 'profile_commerce_v900' && \$paymentStatus === 'paid'/, 'generic Profile Commerce must only convert after full payment');

assert.match(bootstrap, /require_once __DIR__\.'\/profile-conversion-outcomes-v179\.php';/, 'conversion outcome bridge must load in canonical bootstrap');

const productAttach = product.indexOf('profile_conversion_attach_order_v179');
const productRedirect = product.indexOf('redirect($checkoutUrl)');
assert.ok(productAttach > -1 && productRedirect > productAttach, 'Profile Commerce attribution must be attached before provider redirect');
assert.match(product, /'profile_conversion_source'=>'profile_commerce_v900'/, 'Profile Commerce orders must identify Profile Commerce origin');
assert.match(product, /'profile_session_id'=>profile_conversion_session_id_v179/, 'Profile Commerce must carry its owner-scoped Profile session when available');

const paidCreate = booking.indexOf('agent_paid_appointments_create_personal_v800');
const paidAttach = booking.indexOf('profile_conversion_attach_order_v179', paidCreate);
const paymentRedirect = booking.indexOf('public_booking_redirect_v450($paymentUrl)', paidCreate);
assert.ok(paidCreate > -1 && paidAttach > paidCreate && paymentRedirect > paidAttach, 'paid Booking attribution must be attached before leaving for payment');
assert.match(booking, /'profile_conversion_source' => 'public_profile_booking'/, 'paid public Booking orders must identify Profile Booking origin');
const freeConfirmed = booking.indexOf("agent_appointment_lifecycle_event_v700($pdo, $booking, 'confirmed'");
const freeOutcome = booking.indexOf('profile_conversion_booking_confirmed_v179', freeConfirmed);
assert.ok(freeConfirmed > -1 && freeOutcome > freeConfirmed, 'free Booking outcome must only be projected after canonical confirmation');
assert.ok(booking.indexOf('profile_conversion_booking_confirmed_v179', paidCreate) > paymentRedirect, 'pending paid bookings must not be counted as converted before payment');

const markPaidStart = commerceCore.indexOf('function agent_commerce_mark_paid_v800');
const markPaidEnd = commerceCore.indexOf('function agent_commerce_expire_one_v800');
const markPaidBody = commerceCore.slice(markPaidStart, markPaidEnd);
const fulfillmentProjection = markPaidBody.lastIndexOf('agent_commerce_dispatch_fulfillment_v800');
const outcomeProjection = markPaidBody.lastIndexOf('profile_conversion_commerce_order_v179');
assert.ok(markPaidStart > -1 && fulfillmentProjection > -1 && outcomeProjection > fulfillmentProjection, 'true paid outcomes must project from canonical Commerce after fulfillment dispatch');
assert.match(markPaidBody, /seen->fetchColumn\(\)[\s\S]*profile_conversion_commerce_order_v179/, 'idempotent duplicate payment verification must be able to repair a missed outcome projection');

const commerceVerify = commerceReturn.indexOf('agent_commerce_return_verify_v800');
const commerceOutcome = commerceReturn.indexOf('profile_conversion_commerce_order_v179');
assert.ok(commerceVerify > -1 && commerceOutcome > commerceVerify, 'Profile Commerce return fallback must run only after provider verification');
const appointmentVerify = appointmentReturn.indexOf('agent_paid_appointments_return_verify_v800');
const appointmentOutcome = appointmentReturn.indexOf('profile_conversion_commerce_order_v179');
assert.ok(appointmentVerify > -1 && appointmentOutcome > appointmentVerify, 'paid appointment return fallback must run only after provider verification');

assert.match(runtime, /event_type='booking_converted'/, 'owner analytics must count Booking outcomes from profile_events');
assert.match(runtime, /event_type='product_converted'/, 'owner analytics must count Product outcomes from profile_events');
assert.match(runtime, /conversions_24h/, 'owner analytics must expose recent conversion outcomes');
assert.match(runtime, /booking_conversion_rate/, 'owner analytics must expose Booking conversion rate');
assert.match(runtime, /product_conversion_rate/, 'owner analytics must expose Product conversion rate');
assert.match(runtime, /\$event\['conversion_stage'\]/, 'owner timeline must expose sanitized outcome stage');
assert.match(runtime, /\$event\['outcome_status'\]/, 'owner timeline must expose sanitized outcome status');
assert.match(runtime, /\$event\['value_cents'\]/, 'owner timeline must expose sanitized outcome value');
assert.match(runtime, /unset\(\$event\['visitor_user_id'\],\$event\['session_key'\],\$event\['metadata_json'\]\)/, 'owner API must continue stripping raw visitor/session identity and metadata');

assert.match(portal, /Booking conversions/, 'Analytics must display Booking conversions');
assert.match(portal, /Product conversions/, 'Analytics must display Product conversions');
assert.match(portal, /Conversions · 24h/, 'Analytics must display recent true conversions');
assert.match(portal, /Booking conversion rate/, 'Analytics must display Booking conversion rate');
assert.match(portal, /Product conversion rate/, 'Analytics must display Product conversion rate');
assert.match(portal, /function moneyMinor\(cents,currency\)/, 'Radar must safely format verified monetary outcome values');
assert.match(portal, /e\?\.conversion_stage==='outcome'/, 'Radar timeline must distinguish outcomes from intent');
assert.doesNotMatch(portal, /v\.request_count/, 'human Profile sessions must not inherit automated request-count UI');

console.log('profile-conversion-outcomes-v179-contract: ok');
