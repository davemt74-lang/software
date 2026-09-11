import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const scheduling = read('includes/agent-scheduling-v430.php');
const bootstrap = read('includes/bootstrap.php');
const upgrade = read('upgrade.php');

assert.match(bootstrap, /require_once __DIR__\.'\/agent-scheduling-v430\.php';/, 'bootstrap must load the canonical Agent Scheduling runtime');

for (const table of [
  'agent_scheduling_schedules',
  'agent_scheduling_event_types',
  'agent_scheduling_availability',
  'agent_scheduling_overrides',
  'agent_scheduling_bookings',
]) {
  assert.match(scheduling, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`), `${table} must be installed`);
  assert.match(scheduling, new RegExp(`['\"]${table}['\"]`), `${table} must participate in schema readiness`);
}
assert.match(scheduling, /if \(!table_exists\(\$table\)\) return false;/, 'schema readiness must reject any missing scheduling table');

assert.match(scheduling, /function agent_scheduling_save_schedule_v430\(/, 'schedules need a canonical owner-scoped save primitive');
assert.match(scheduling, /function agent_scheduling_save_event_type_v430\(/, 'event types need a canonical owner-scoped save primitive');
assert.match(scheduling, /function agent_scheduling_replace_weekly_availability_v430\(/, 'weekly availability needs a canonical replacement primitive');
assert.match(scheduling, /function agent_scheduling_replace_date_override_v430\(/, 'date overrides need a canonical replacement primitive');
assert.match(scheduling, /Appointment type does not belong to this schedule/, 'availability writes must reject cross-schedule event types');

assert.match(scheduling, /agent_id BIGINT UNSIGNED NULL/, 'schedules/bookings must be bindable to a VP3 Agent');
assert.match(scheduling, /FOREIGN KEY \(agent_id\) REFERENCES user_agents\(id\) ON DELETE SET NULL/, 'Agent scheduling must use canonical user_agents identity');
assert.match(scheduling, /Booking Agent does not belong to this account/, 'booking attribution must not accept another owner\'s Agent');

assert.match(scheduling, /weekday TINYINT UNSIGNED NOT NULL[\s\S]*start_minute SMALLINT UNSIGNED NOT NULL[\s\S]*end_minute SMALLINT UNSIGNED NOT NULL/, 'recurring weekly availability must store weekday windows');
assert.match(scheduling, /override_date DATE NOT NULL[\s\S]*is_available TINYINT\(1\) NOT NULL/, 'date-specific availability and blackout overrides must be supported');
assert.match(scheduling, /function agent_scheduling_windows_for_date_v430\(/, 'booking availability must resolve recurring and override windows');
assert.match(scheduling, /\(\(\$startMinute - \$windowStart\) % \$slotInterval\) === 0/, 'booking starts must align to configured slot intervals');
assert.match(scheduling, /outside the available booking hours/, 'booking creation must reject unavailable times');

assert.match(scheduling, /minimum_notice_minutes INT UNSIGNED NOT NULL DEFAULT 60/, 'event types must support minimum booking notice');
assert.match(scheduling, /inside the minimum booking notice/, 'minimum notice must be enforced by the booking engine');
assert.match(scheduling, /booking_window_days SMALLINT UNSIGNED NOT NULL DEFAULT 60/, 'event types must support a forward booking horizon');
assert.match(scheduling, /outside the booking window/, 'booking horizon must be enforced by the booking engine');
assert.match(scheduling, /max_bookings_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 0/, 'event types must support daily limits');
assert.match(scheduling, /daily booking limit has been reached/, 'daily booking limits must be enforced');

assert.match(scheduling, /buffer_before_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0[\s\S]*buffer_after_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0/, 'event types and booking snapshots must preserve buffers');
assert.match(scheduling, /DATE_SUB\(start_at_utc, INTERVAL buffer_before_minutes MINUTE\) < \?/, 'conflict detection must include the existing booking pre-buffer');
assert.match(scheduling, /DATE_ADD\(end_at_utc, INTERVAL buffer_after_minutes MINUTE\) > \?/, 'conflict detection must include the existing booking post-buffer');
assert.match(scheduling, /function agent_scheduling_slots_for_date_v430\(/, 'the foundation must expose canonical available-slot generation for public/member UIs');

assert.match(scheduling, /start_at_utc DATETIME NOT NULL[\s\S]*end_at_utc DATETIME NOT NULL[\s\S]*organizer_timezone VARCHAR\(80\)[\s\S]*guest_timezone VARCHAR\(80\)/, 'bookings must store canonical UTC timestamps plus display timezones');
assert.match(scheduling, /!empty\(\$input\['start_at_utc'\]\)/, 'booking creation must accept canonical UTC starts from later public/Agent clients');
assert.match(scheduling, /Public booking is disabled for this schedule/, 'public booking must honor the schedule public-enabled boundary');
assert.match(scheduling, /Choose a future appointment time/, 'past bookings must be rejected');

assert.match(scheduling, /SELECT GET_LOCK\(\?,5\)/, 'booking creation must serialize per schedule to prevent double-booking races');
assert.match(scheduling, /SELECT RELEASE_LOCK\(\?\)/, 'schedule booking locks must always be released');
assert.match(scheduling, /public_token CHAR\(64\) NOT NULL[\s\S]*cancel_token CHAR\(64\) NOT NULL/, 'bookings need opaque public/cancellation tokens for self-service flows');
assert.match(scheduling, /bin2hex\(random_bytes\(32\)\)/, 'booking tokens must use cryptographically secure randomness');

assert.match(upgrade, /agent_scheduling_schema_ready_v430\(\)/, 'upgrade completion must require Agent Scheduling schema readiness');
assert.match(upgrade, /agent_scheduling_ensure_schema_v430\(\);/, 'Run Upgrade must install Agent Scheduling schema');

console.log('Agent Scheduling v4.30 contract passed.');
