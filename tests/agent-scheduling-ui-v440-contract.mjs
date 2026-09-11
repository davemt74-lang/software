import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const page = read('scheduling.php');
const css = read('scheduling.css');
const nav = read('includes/member-navigation.php');

assert.match(page, /require_permission\('account\.access'\)/, 'Scheduling must require authenticated account access');
assert.match(page, /agent_scheduling_schema_ready_v430\(\$pdo\)/, 'Scheduling must gate on the canonical scheduling schema');
assert.match(page, /agent_scheduling_default_schedule_v430\(\$pdo, \$user\)/, 'Scheduling must use the canonical owner schedule foundation');
assert.match(page, /agent_scheduling_schedule_v430\(\$pdo, \(int\)\$user\['id'\]/, 'Schedule selection must be owner scoped');

assert.match(page, /workspaceSidebarActive = 'scheduling'/, 'Scheduling must activate the canonical member sidebar item');
assert.match(page, /includes\/workspace-sidebar-v82\.php/, 'Scheduling must use the canonical workspace sidebar');
assert.match(page, /includes\/member-header\.php/, 'Scheduling must use the canonical member header');
assert.match(page, /member-shell-v77\.js/, 'Scheduling must load the canonical member shell runtime');
assert.match(css, /\.scheduling-main\{[^}]*min-height:0;[^}]*grid-template-rows:58px minmax\(0,1fr\);[^}]*overflow:hidden/, 'Scheduling main must participate in the fixed member shell');
assert.match(css, /\.scheduling-canvas\{[^}]*min-height:0;[^}]*overflow-x:hidden;[^}]*overflow-y:auto/, 'Scheduling must own its vertical scroll region');
assert.match(css, /@media\(max-width:700px\)/, 'Scheduling must provide a mobile layout');

assert.match(nav, /'scheduling','Scheduling',url\('\/scheduling\.php'\),'agent'/, 'Scheduling must be reachable from member navigation');
assert.match(nav, /agent_scheduling_schema_ready_v430\(\)/, 'Navigation must only expose Scheduling after its schema exists');

for (const action of ['save_schedule','save_event_type','save_availability','save_override','cancel_booking']) {
  assert.match(page, new RegExp(`action['\"] value=['\"]${action}`), `${action} must have an authenticated member form`);
}
assert.match(page, /if \(!verify_csrf\(\)\)/, 'Scheduling mutations must enforce CSRF protection');
assert.match(page, /agent_scheduling_save_schedule_v430/, 'Schedule settings must use the canonical write helper');
assert.match(page, /agent_scheduling_save_event_type_v430/, 'Appointment types must use the canonical write helper');
assert.match(page, /agent_scheduling_replace_weekly_availability_v430/, 'Weekly hours must use the canonical availability helper');
assert.match(page, /agent_scheduling_replace_date_override_v430/, 'Date overrides must use the canonical override helper');
assert.match(page, /agent_scheduling_cancel_booking_v430\(\$pdo, \$bookingId, \(int\)\$user\['id'\]\)/, 'Cancellation must remain owner scoped');

for (const field of [
  'duration_minutes','slot_interval_minutes','location_type','location_value','buffer_before_minutes','buffer_after_minutes',
  'minimum_notice_minutes','booking_window_days','max_bookings_per_day','is_active',
]) {
  assert.match(page, new RegExp(`name=['\"]${field}['\"]`), `Appointment editor must expose ${field}`);
}
assert.match(page, /name="agent_id"/, 'Schedule settings must allow binding an owned VP3 Agent');
assert.match(page, /user_agents_list_v236\(\$pdo, \(int\)\$user\['id'\], true\)/, 'Agent choices must be owner scoped and active');
assert.match(page, /name="public_enabled"/, 'Members must control whether a schedule accepts public bookings');
assert.match(page, /Date overrides/, 'Member UI must expose date-specific overrides');
assert.match(page, /Weekly hours/, 'Member UI must expose recurring weekly availability');
assert.match(page, /Upcoming appointments created by visitors, you, or an authorized VP3 Agent/, 'Bookings UI must present Agent-native booking ownership');
assert.match(page, /Past \+ cancelled appointments/, 'Booking history must remain visible');

assert.doesNotMatch(page, /api\.google|graph\.microsoft|oauth/i, 'External calendar sync belongs to a later phase');
assert.doesNotMatch(page, /public-book|booking-public/i, 'Public booking flow belongs to Phase 3');

console.log('Agent Scheduling UI v4.40 contract passed.');
