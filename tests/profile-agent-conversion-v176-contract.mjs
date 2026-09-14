import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const helper = read('includes/profile-agent-booking-context-v176.php');
const bridge = read('includes/profile-agent-transcription-context.php');
const api = read('api/profile-agent.php');
const client = read('profile-agent.js');
const routes = read('.htaccess');

assert.match(helper, /profile_agent_booking_intent_v176/, 'booking conversion must be intent driven');
assert.match(helper, /agent_scheduling_public_schedule_v450/, 'booking context must use the canonical public schedule projection');
assert.match(helper, /agent_scheduling_public_events_v450/, 'booking context must use only active public event types');
assert.match(helper, /agent_scheduling_public_booking_url_v450/, 'booking context must return canonical public booking URLs');
assert.match(helper, /empty\(\$profile\['is_public'\]\)/, 'booking context must require a public profile');
assert.doesNotMatch(helper, /user_calendar_events|agent_scheduling_bookings/, 'booking context must not inspect private calendar or booking records');
assert.doesNotMatch(helper, /SELECT\s|FROM\s+agent_scheduling/i, 'booking context must delegate scheduling reads to the canonical public helper');

assert.match(bridge, /profile-agent-booking-context-v176\.php/, 'Profile Agent bridge must load booking conversion context');
assert.match(bridge, /profile_agent_booking_context_v176/, 'Profile Agent bridge must append public booking context');
assert.match(bridge, /profile_commerce_agent_context_v900/, 'existing public Commerce context must remain available');
assert.match(bridge, /array_merge\(\$conversionContext, \$context\)/, 'public conversion context must survive when Agent Brain context is available');
assert.match(api, /profile_agent_transcript_brain_context_v255/, 'public Profile Agent message flow must consume the supplemental conversion context');

assert.match(client, /appendSafeLinkedText/, 'Profile Agent messages must render safe clickable links');
assert.match(client, /\['http:','https:'\]/, 'only HTTP and HTTPS links may be activated');
assert.match(client, /noopener noreferrer nofollow/, 'external Profile Agent links must be isolated and nofollowed');
assert.doesNotMatch(client, /data-profile-booking-link|Book a time with/, 'client must not inject an unconditional booking CTA when no public schedule exists');
assert.match(client, /type==='agent'\|\|type==='owner'/, 'only trusted response surfaces should receive automatic linkification');
assert.match(routes, /profile-agent\)\\\.js\$[^]*Cache-Control "no-cache, must-revalidate"/, 'Profile Agent client changes must not be hidden behind stale static caching');

console.log('profile-agent-conversion-v176-contract: ok');
