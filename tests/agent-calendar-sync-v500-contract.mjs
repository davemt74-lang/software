import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const sync = read('includes/agent-calendar-sync-v500.php');
const scheduling = read('includes/agent-scheduling-v430.php');
const bootstrap = read('includes/bootstrap.php');
const upgrade = read('upgrade.php');
const ui = read('scheduling.php');
const oauth = read('calendar-oauth.php');
const config = read('config-example.php');

assert.match(sync, /VP3_AGENT_CALENDAR_SYNC_V500/, 'calendar sync must expose a versioned runtime');
assert.match(bootstrap, /require_once __DIR__\.'\/agent-calendar-sync-v500\.php';/, 'bootstrap must load the calendar sync runtime');

for (const table of ['agent_calendar_connections','agent_calendar_schedule_connections','agent_calendar_busy_blocks','agent_calendar_booking_links']) {
  assert.ok(sync.includes(`CREATE TABLE IF NOT EXISTS ${table}`), `${table} must be upgrade-managed`);
}
assert.match(upgrade, /agent_calendar_sync_schema_ready_v500\(\)/, 'upgrade completeness must include calendar sync');
assert.match(upgrade, /agent_calendar_sync_ensure_schema_v500\(\)/, 'upgrade must install calendar sync schema');

assert.match(sync, /access_token_ciphertext MEDIUMTEXT/, 'access tokens must use encrypted storage');
assert.match(sync, /refresh_token_ciphertext MEDIUMTEXT/, 'refresh tokens must use encrypted storage');
assert.match(sync, /aes-256-gcm/, 'calendar OAuth tokens must be encrypted with authenticated encryption');
assert.match(sync, /VP3_CALENDAR_ENCRYPTION_KEY/, 'calendar encryption key must support an environment secret');
assert.doesNotMatch(ui, /access_token_ciphertext|refresh_token_ciphertext/, 'Scheduling UI must never render encrypted token material');

for (const env of [
  'VP3_GOOGLE_CALENDAR_CLIENT_ID','VP3_GOOGLE_CALENDAR_CLIENT_SECRET',
  'VP3_MICROSOFT_CALENDAR_CLIENT_ID','VP3_MICROSOFT_CALENDAR_CLIENT_SECRET',
  'VP3_MICROSOFT_CALENDAR_TENANT','VP3_CALENDAR_ENCRYPTION_KEY'
]) assert.ok(config.includes(env), `${env} must be documented in config-example.php`);

assert.match(sync, /accounts\.google\.com\/o\/oauth2\/v2\/auth/, 'Google OAuth authorize endpoint must be present');
assert.match(sync, /oauth2\.googleapis\.com\/token/, 'Google OAuth token endpoint must be present');
assert.match(sync, /www\.googleapis\.com\/auth\/calendar\.readonly/, 'Google calendar read scope must be explicit');
assert.match(sync, /www\.googleapis\.com\/auth\/calendar\.events/, 'Google event write scope must be explicit');
assert.match(sync, /login\.microsoftonline\.com/, 'Microsoft OAuth endpoint must be present');
assert.match(sync, /Calendars\.ReadWrite/, 'Microsoft calendar permission must be explicit');
assert.match(sync, /offline_access/, 'calendar OAuth must request refresh capability');

assert.match(sync, /expires_at'=>time\(\)\+900/, 'OAuth state must expire after a short window');
assert.match(sync, /unset\(\$_SESSION\['vp3_calendar_oauth'\]\[\$token\]\)/, 'OAuth state must be one-time');
assert.match(sync, /'user_id'=>\(int\)\$user\['id'\]/, 'OAuth state must bind to the signed-in owner');
assert.match(oauth, /agent_scheduling_schedule_v430\(\$pdo,\(int\)\$user\['id'\],\$scheduleId\)/, 'OAuth connect must authorize the target schedule to the signed-in owner');

assert.match(sync, /freeBusy/, 'Google busy time must use provider free-busy data');
assert.match(sync, /\/me\/calendarView/, 'Microsoft busy time must use calendarView');
assert.match(sync, /blocks_availability/, 'per-schedule busy blocking must be configurable');
assert.match(sync, /writes_bookings/, 'per-schedule booking writeback must be configurable');
assert.match(sync, /bb\.start_at_utc<\? AND bb\.end_at_utc>\?/, 'external busy ranges must be overlap-checked');
assert.match(scheduling, /agent_calendar_sync_conflict_v500/, 'canonical scheduling conflict checks must include connected calendars');
assert.match(scheduling, /agent_calendar_sync_maybe_schedule_v500/, 'slot generation must refresh stale connected-calendar busy data');

assert.match(sync, /conferenceData/, 'Google Meet creation must be supported for virtual bookings');
assert.match(sync, /hangoutsMeet/, 'Google Meet conference solution must be requested');
assert.match(sync, /isOnlineMeeting/, 'Microsoft Teams meeting creation must be supported');
assert.match(sync, /teamsForBusiness/, 'Microsoft Teams provider must be requested when available');
assert.match(sync, /attendees/, 'provider events must support guest attendees');
assert.match(sync, /sendUpdates=all/, 'Google booking changes must notify attendees through the provider');
assert.match(scheduling, /agent_calendar_sync_booking_v500/, 'new VP3 bookings must write through to linked calendars');
assert.match(scheduling, /agent_calendar_sync_cancel_booking_v500/, 'VP3 cancellations must propagate to linked calendars');

assert.match(sync, /WHERE c\.owner_user_id=\?/, 'calendar connections must be owner-scoped');
assert.match(sync, /WHERE id=\? AND owner_user_id=\?/, 'calendar connection mutations must re-authorize ownership');
assert.match(sync, /SET status='disconnected',sync_enabled=0,write_enabled=0,access_token_ciphertext='',refresh_token_ciphertext=NULL/, 'disconnect must revoke VP3-stored calendar credentials');
assert.match(sync, /last_error/, 'provider failures must be persisted for reconciliation and UI visibility');
assert.match(sync, /agent_calendar_sync_reconcile_connection_v500/, 'calendar sync must reconcile future VP3 bookings');

assert.match(ui, /id="calendars"/, 'Scheduling must expose a connected calendars section');
assert.match(ui, /Connect Google/, 'Scheduling must expose Google connect when configured');
assert.match(ui, /Connect Outlook/, 'Scheduling must expose Outlook connect when configured');
assert.match(ui, /name="blocks_availability"/, 'Scheduling must control external busy blocking');
assert.match(ui, /name="writes_bookings"/, 'Scheduling must control event writeback');
assert.match(ui, /csrf_field\(\)/, 'calendar management POSTs must use the canonical CSRF field');
assert.match(oauth, /agent_calendar_sync_oauth_state_take_v500/, 'OAuth callback must validate and consume state');

assert.doesNotMatch(sync, /CREATE TABLE IF NOT EXISTS agent_scheduling_/, 'Phase 5 must extend rather than duplicate the canonical scheduling store');
console.log('AGENT_CALENDAR_SYNC_V500=PASS');
