import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const scheduling = read('includes/agent-scheduling-v430.php');
const bootstrap = read('includes/bootstrap.php');
const upgrade = read('upgrade.php');

assert.match(
  bootstrap,
  /require_once __DIR__\.'\/agent-scheduling-v430\.php';/,
  'bootstrap must load the canonical Agent Scheduling runtime',
);

for (const table of [
  'agent_scheduling_schedules',
  'agent_scheduling_event_types',
  'agent_scheduling_availability',
  'agent_scheduling_overrides',
  'agent_scheduling_bookings',
]) {
  assert.match(scheduling, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`), `${table} must be installed`);
  assert.match(scheduling, new RegExp(`table_exists\\('${table}'\\)`), `${table} must participate in schema readiness`);
}

assert.match(
  scheduling,
  /agent_id BIGINT UNSIGNED NULL/,
  'schedules/bookings must be bindable to a VP3 Agent',
);
assert.match(
  scheduling,
  /FOREIGN KEY \(agent_id\) REFERENCES user_agents\(id\) ON DELETE SET NULL/,
  'Agent scheduling must use the canonical user_agents identity',
);
assert.match(
  scheduling,
  /weekday TINYINT UNSIGNED NOT NULL[\s\S]*start_minute SMALLINT UNSIGNED NOT NULL[\s\S]*end_minute SMALLINT UNSIGNED NOT NULL/,
  'recurring weekly availability must store weekday windows',
);
assert.match(
  scheduling,
  /override_date DATE NOT NULL[\s\S]*is_available TINYINT\(1\) NOT NULL/,
  'date-specific availability and blackout overrides must be supported',
);
assert.match(
  scheduling,
  /minimum_notice_minutes INT UNSIGNED NOT NULL DEFAULT 60/,
  'event types must support minimum booking notice',
);
assert.match(
  scheduling,
  /buffer_before_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0[\s\S]*buffer_after_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0/,
  'event types must preserve pre/post appointment buffers',
);
assert.match(
  scheduling,
  /booking_window_days SMALLINT UNSIGNED NOT NULL DEFAULT 60/,
  'event types must support a forward booking horizon',
);
assert.match(
  scheduling,
  /start_at_utc DATETIME NOT NULL[\s\S]*end_at_utc DATETIME NOT NULL[\s\S]*organizer_timezone VARCHAR\(80\)[\s\S]*guest_timezone VARCHAR\(80\)/,
  'bookings must store canonical UTC timestamps plus display timezones',
);
assert.match(
  scheduling,
  /status IN \('pending','confirmed'\)[\s\S]*start_at_utc < \?[\s\S]*end_at_utc > \?/,
  'booking conflicts must use true interval overlap semantics',
);
assert.match(
  scheduling,
  /SELECT GET_LOCK\(\?,5\)/,
  'booking creation must serialize per schedule to prevent double-booking races',
);
assert.match(
  scheduling,
  /SELECT RELEASE_LOCK\(\?\)/,
  'schedule booking locks must always be released',
);
assert.match(
  scheduling,
  /public_token CHAR\(64\) NOT NULL[\s\S]*cancel_token CHAR\(64\) NOT NULL/,
  'bookings need opaque public/cancellation tokens for future self-service flows',
);
assert.match(
  scheduling,
  /bin2hex\(random_bytes\(32\)\)/,
  'booking tokens must use cryptographically secure randomness',
);

assert.match(
  upgrade,
  /agent_scheduling_schema_ready_v430\(\)/,
  'upgrade completion must require Agent Scheduling schema readiness',
);
assert.match(
  upgrade,
  /agent_scheduling_ensure_schema_v430\(\);/,
  'Run Upgrade must install Agent Scheduling schema',
);

console.log('Agent Scheduling v4.30 contract passed.');
