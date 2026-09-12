import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const service = read('includes/user-calendar-v1300.php');
const agent = read('includes/user-calendar-agent-v1300.php');
const api = read('api/user-calendar-v1300.php');
const chat = read('api/chat-v236.php');
const calendarSync = read('includes/agent-calendar-sync-v500.php');
const calendar = read('calendar.php');
const editor = read('calendar-event.php');
const scheduling = read('scheduling.php');
const typePage = read('scheduling-type.php');
const oauth = read('calendar-oauth.php');
const nav = read('includes/member-navigation.php');
const upgrade = read('upgrade.php');
const migration = read('upgrade-user-calendar-v1300.sql');

assert.match(service, /CREATE TABLE IF NOT EXISTS user_calendar_events/);
assert.match(migration, /CREATE TABLE IF NOT EXISTS user_calendar_events/);
assert.match(service, /FROM agent_scheduling_bookings b/);
assert.doesNotMatch(service, /INSERT INTO agent_scheduling_bookings/);
assert.match(service, /'user','agent','automation'/);
assert.match(service, /source === 'automation'.*sourceReference/s);
assert.match(service, /SELECT \* FROM user_calendar_events WHERE owner_user_id=\? AND source='automation' AND source_reference=\?/);
assert.match(service, /owner_user_id=\?/);
assert.match(service, /created_by_agent_id/);
assert.match(service, /built-in VP3 system Agent has no user_agents row/);

assert.match(api, /current_user\(\)/);
assert.match(api, /has_permission\('account\.access'/);
assert.match(api, /hash_equals\(csrf_token\(\),\$csrf\)/);
assert.match(api, /user_calendar_create_local_event_v1300\(\$pdo,\$user,\$input,'user'\)/);
assert.doesNotMatch(api, /\$input\['source'\]/);

assert.match(agent, /expires_at.*time\(\)\+900/s);
assert.match(agent, /Confirm\?/);
assert.match(agent, /user_calendar_agent_confirmation_v1300/);
assert.match(agent, /agent_scheduling_tools_date_v460/);
assert.match(agent, /agent_scheduling_tools_time_request_v460/);
assert.match(agent, /\$source='agent'/);
assert.doesNotMatch(agent, /\$source=\$agentId>0\?'agent':'user'/);
assert.match(agent, /calendar\.event\.prepare/);
assert.match(agent, /calendar\.event\.create/);

assert.match(chat, /\$inputMode=\$inputMode==='voice'\?'voice':'text'/);
const calendarToolIndex = chat.indexOf('user_calendar_agent_query_v1300($query,$user,$conversationId)');
const releaseToolIndex = chat.indexOf("release_v105_chat_tool($query,$user,$conversationId)");
assert.ok(calendarToolIndex > 0 && releaseToolIndex > calendarToolIndex, 'User Calendar must route before generic/release tools');
assert.match(chat, /vp3_agent_tool_authorize_result_v400\(\$toolResult,\$user,\$query\)/);

assert.match(calendarSync, /function agent_calendar_sync_conflict_v500/);
assert.match(calendarSync, /table_exists\('user_calendar_events'\)/);
assert.match(calendarSync, /INNER JOIN agent_scheduling_schedules s ON s\.owner_user_id=e\.owner_user_id/);
assert.match(calendarSync, /e\.status='active'/);
assert.match(calendarSync, /e\.start_at_utc<\? AND e\.end_at_utc>\?/);
assert.match(calendarSync, /\$calendarEvent\['user_calendar'\]=true/);
assert.ok(calendarSync.indexOf("table_exists('user_calendar_events')") < calendarSync.indexOf('agent_calendar_sync_schema_ready_v500($pdo)'), 'Native VP3 busy time must work even without a connected external calendar');

assert.match(calendar, /user_calendar_events_v1300/);
assert.match(calendar, /Booking/);
assert.match(calendar, /Personal/);
assert.match(calendar, /Agent/);
assert.match(calendar, /Automated/);
assert.match(calendar, /calendar-event\.php/);
assert.match(editor, /user_calendar_create_local_event_v1300\(\$pdo,\$user,\$input,'user'\)/);
assert.match(editor, /user_calendar_update_event_v1300/);
assert.match(editor, /user_calendar_cancel_event_v1300/);

for (const tab of ['types','availability','bookings','calendars','settings']) assert.match(scheduling, new RegExp("'" + tab + "'"));
assert.match(scheduling, /scheduling-tabs-v1300/);
assert.match(scheduling, /scheduling-type\.php\?schedule=/);
assert.doesNotMatch(scheduling, /<strong>New appointment type<\/strong>/);
assert.match(typePage, /agent_scheduling_save_event_type_v430/);
assert.match(typePage, /Calendar → New event/);
assert.match(oauth, /'tab'=>'calendars'/);
assert.doesNotMatch(oauth, /#calendars/);

assert.match(nav, /'calendar','Calendar',url\('\/calendar\.php'\),'agent'/);
assert.match(upgrade, /user-calendar-v1300\.php/);
assert.match(upgrade, /user_calendar_schema_ready_v1300\(\)/);
assert.match(upgrade, /user_calendar_ensure_schema_v1300\(\)/);
assert.match(upgrade, /HomeServer Agent continuity/);
assert.match(upgrade, /Existing accounts, package assignments, team memberships, token balances, music content/);

console.log('User Calendar v13.00 contract OK');
