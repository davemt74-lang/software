import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const runtime = read('includes/agent-scheduling-public-v450.php');
const bootstrap = read('includes/bootstrap.php');
const page = read('public-booking.php');
const api = read('api/public-scheduling-slots-v450.php');
const calendar = read('public-booking-calendar.php');
const htaccess = read('.htaccess');
const client = read('public-booking-v450.js');
const profileAgent = read('profile-agent.js');

assert.match(bootstrap, /require_once __DIR__\.'\/agent-scheduling-public-v450\.php';/, 'bootstrap must load the public scheduling runtime');

assert.match(htaccess, /booking-calendar\/\(\[A-Fa-f0-9\]\{64\}\)\\\.ics/, 'calendar downloads must use an opaque 64-hex token route');
assert.match(htaccess, /\/book\/manage\/\(\[A-Fa-f0-9\]\{64\}\)/, 'guest self-service must use an opaque management token route');
assert.match(htaccess, /\/book\/\(\[A-Za-z0-9-\]\{1,80\}\)/, 'event-type booking URLs must use public slugs rather than numeric ids');
assert.match(htaccess, /\/book\/\?\$/, 'profiles must expose a canonical booking root');

assert.match(runtime, /WHERE s\.owner_user_id=\? AND s\.is_active=1 AND s\.public_enabled=1/, 'public schedules must enforce owner, active and public boundaries');
assert.match(runtime, /e\.is_active=1 AND s\.is_active=1 AND s\.public_enabled=1/, 'public event lookup must enforce event and schedule publication');
assert.match(runtime, /preg_match\('\/\^\[a-f0-9\]\{64\}\$\/'/, 'public/manage tokens must be strict 256-bit hex values');
assert.match(runtime, /hash_equals\(\(string\)\(\$booking\['cancel_token'\]/, 'management mutations must compare the bearer token in constant time');
assert.match(runtime, /Too many booking attempts/, 'public booking writes need session rate limiting');

assert.match(runtime, /SELECT GET_LOCK\(\?,5\)/, 'rescheduling must hold the schedule lock around cancel+create');
assert.match(runtime, /if \(\$started\) \$pdo->beginTransaction\(\)/, 'rescheduling must use an atomic transaction');
assert.match(runtime, /status='cancelled'[\s\S]*agent_scheduling_create_booking_v430/, 'rescheduling must atomically release the prior row before creating the replacement');
assert.match(runtime, /rescheduled_from_id=\?/, 'rescheduled bookings must preserve lineage');
assert.match(runtime, /SELECT RELEASE_LOCK\(\?\)/, 'the outer reschedule lock must always be released');

assert.match(page, /profile_by_username\(\$pdo, \$username\)/, 'public booking must resolve ownership from the canonical public profile');
assert.match(page, /empty\(\$profile\['is_public'\]\)/, 'private profiles must not expose booking pages');
assert.match(page, /verify_csrf\(\)/, 'guest booking mutations must be CSRF protected');
assert.match(page, /\$_POST\['website'\]/, 'guest booking forms must include a honeypot abuse boundary');
assert.match(page, /filter_var\(\$email, FILTER_VALIDATE_EMAIL\)/, 'public bookings must require a valid guest email');
assert.match(page, /agent_scheduling_public_event_for_owner_v450/, 'posted event ids must be re-authorized against the profile owner');
assert.match(page, /agent_scheduling_create_booking_v430/, 'public booking must use the conflict-safe canonical booking engine');
assert.match(page, /create_notification\(/, 'booking creation/cancellation/reschedule must notify the schedule owner');
assert.match(page, /agent_scheduling_public_reschedule_v450/, 'guest self-service must support rescheduling');
assert.match(page, /agent_scheduling_cancel_booking_v430/, 'guest self-service must support cancellation');
assert.match(page, /X-Robots-Tag: noindex, nofollow, noarchive/, 'management URLs must be excluded from indexing');
assert.match(page, /Referrer-Policy: no-referrer/, 'management bearer tokens must not leak through referrers');

assert.match(api, /Cache-Control: no-store/, 'slot availability must never be served from stale cache');
assert.match(api, /agent_scheduling_slots_for_date_v430/, 'slot API must use canonical live conflict-aware availability');
assert.match(api, /profile_by_username/, 'slot API must re-check public profile ownership');
assert.match(api, /agent_scheduling_public_event_by_slug_v450/, 'slot API must authorize the public event slug');

assert.match(client, /Intl\.DateTimeFormat\(\)\.resolvedOptions\(\)\.timeZone/, 'public booking should detect the guest timezone');
assert.match(client, /cache:'no-store'/, 'live availability fetches must bypass browser cache');
assert.match(client, /data-slot-input/, 'slot selection must bind the canonical UTC start to the form');
assert.match(client, /submit\.disabled = !value/, 'booking cannot submit before a live slot is selected');

assert.match(calendar, /agent_scheduling_booking_by_public_token_v450/, 'calendar download must resolve by opaque public token');
assert.match(calendar, /text\/calendar/, 'calendar response must use the ICS content type');
assert.match(calendar, /DTSTART:/, 'calendar output must contain a UTC start');
assert.match(calendar, /DTEND:/, 'calendar output must contain a UTC end');
assert.match(calendar, /str_replace\(\["\\r\\n", "\\r", "\\n"\]/, 'ICS text must neutralize injected line breaks');
assert.doesNotMatch(calendar, /cancel_token/, 'calendar payload must never expose the management token');

assert.match(profileAgent, /data\.profileBookingLink='1'/, 'active Profile Agents must expose a Book a time entry point');
assert.match(profileAgent, /\/book`/, 'Profile Agent booking CTA must target the native scheduling route');

console.log('Agent Scheduling Public v4.50 contract passed.');
