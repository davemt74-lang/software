import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=path=>fs.readFileSync(new URL(`../${path}`,import.meta.url),'utf8');
const core=[read('includes/agent-appointment-lifecycle-v700.php'),...Array.from({length:8},(_,i)=>read(`includes/agent-appointment-lifecycle-v700-part${i+1}.php`))].join('\n');
const publicPage=read('public-booking.php');
const teamPage=read('team-book.php');
const memberPage=read('appointment-lifecycle.php');
const css=read('appointment-lifecycle.css');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const nav=read('includes/member-navigation.php');
const cron=read('cron/appointment-lifecycle-v700.php');

assert.match(core,/VP3_AGENT_APPOINTMENT_LIFECYCLE_V700/,'Phase 7 must expose a versioned runtime');
for(const table of ['agent_scheduling_intake_questions','agent_scheduling_intake_answers','agent_scheduling_lifecycle_events','agent_scheduling_automation_deliveries','agent_scheduling_agent_briefs','agent_scheduling_followups']){
  assert.ok(core.includes(`CREATE TABLE IF NOT EXISTS ${table}`),`${table} must be canonical Phase 7 storage`);
}
for(const column of ['lifecycle_status','rescheduled_at','no_show_at','last_lifecycle_event_at']){
  assert.ok(core.includes(`agent_scheduling_bookings','${column}`),`Canonical bookings must expose ${column}`);
}
assert.match(core,/agent_scheduling_event_types','confirmation_enabled'/,'Event types must gain confirmation automation settings');
assert.match(core,/agent_scheduling_event_types','reminder_24h_enabled'/,'Event types must gain 24-hour reminder settings');
assert.match(core,/agent_scheduling_event_types','reminder_soon_minutes'/,'Event types must gain upcoming-soon reminder settings');
assert.match(core,/agent_scheduling_event_types','agent_prep_minutes'/,'Event types must gain Agent preparation timing');
assert.match(core,/occurrence_key CHAR\(64\) NOT NULL/,'Automation deliveries must retain occurrence lineage across reschedules');
assert.match(core,/uq_agent_automation_delivery \(booking_id,automation_key,recipient_key,occurrence_key\)/,'Automation uniqueness must include occurrence lineage');

const rescheduleStart=core.indexOf('function agent_appointment_lifecycle_reschedule_v700');
const rescheduleEnd=core.indexOf('function agent_appointment_lifecycle_questions_v700');
const reschedule=rescheduleStart>=0&&rescheduleEnd>rescheduleStart?core.slice(rescheduleStart,rescheduleEnd):'';
assert.ok(reschedule,'In-place lifecycle reschedule must exist');
assert.doesNotMatch(reschedule,/agent_scheduling_create_booking_v430/,'Phase 7 self-service reschedule must never create a replacement canonical booking');
assert.match(reschedule,/UPDATE agent_scheduling_bookings SET start_at_utc=\?,end_at_utc=\?/,'Reschedule must move the existing canonical booking in place');
assert.match(reschedule,/agent_appointment_lifecycle_validate_reschedule_v700/,'Reschedule must revalidate canonical availability and conflict rules');
assert.match(core,/id<>\? AND status IN \('pending','confirmed'\)/,'Reschedule validation must exclude the booking being moved');
assert.match(core,/agent_calendar_booking_links WHERE booking_id=\? AND connection_id=\?/,'Calendar validation must identify the booking’s own provider event');
assert.match(reschedule,/agent_calendar_sync_booking_v500\(\$pdo,\$fresh\)/,'Reschedule must reuse Phase 5 provider synchronization');
assert.match(core,/existing provider link and PUT\/PATCHes the same event id/,'Calendar lineage intent must be explicit in the implementation');
assert.match(reschedule,/agent_appointment_lifecycle_reset_timed_deliveries_v700/,'Reschedule must invalidate stale timed reminders');
assert.match(reschedule,/agent_appointment_lifecycle_queue_notice_v700/,'Reschedule must issue lifecycle notices');
assert.match(core,/agent_appointment_lifecycle_occurrence_key_v700/,'Rescheduled reminders must receive a new occurrence identity without deleting sent history');

assert.match(core,/function agent_appointment_lifecycle_transition_v700/,'Lifecycle transitions must be centralized');
for(const state of ["'rescheduled'","'completed'","'cancelled'","'no_show'"])assert.ok(core.includes(state),`Lifecycle must support ${state}`);
assert.match(core,/agent_scheduling_cancel_booking_v430/,'Cancellation must traverse the canonical Phase 4\/5 cancellation path');
assert.match(core,/agent_scheduling_lifecycle_events/,'Lifecycle changes must be append-only audited');
assert.match(core,/function agent_appointment_lifecycle_reconcile_statuses_v700/,'Direct and legacy booking mutations must reconcile into Phase 7 lifecycle state');
assert.doesNotMatch(core,/'confirmed'=>\['rescheduled'/,'Only the in-place reschedule path may create the rescheduled lifecycle state');
assert.match(core,/rowCount\(\)!==1/,'Lifecycle mutations must detect concurrent booking changes');

assert.match(core,/function agent_appointment_lifecycle_validate_intake_v700/,'Intake must be validated before booking mutation');
assert.match(core,/function agent_appointment_lifecycle_capture_intake_v700/,'Intake answers must persist against the canonical booking');
assert.match(publicPage,/agent_appointment_lifecycle_validate_intake_v700[\s\S]*agent_scheduling_create_booking_v430/,'Public booking must validate required intake before creating a booking');
assert.match(publicPage,/agent_appointment_lifecycle_capture_intake_v700/,'Public booking must persist event-specific intake');
assert.match(publicPage,/agent_scheduling_cancel_booking_v430[\s\S]*throw \$captureError/,'A failed intake persistence step must roll back the canonical booking');
for(const type of ["'short_text'","'long_text'","'select'","'checkbox'"])assert.ok(core.includes(type),`Intake must support ${type}`);

for(const key of ["'confirmation'","'reminder_24h'","'reminder_soon'","'agent_prep'"])assert.ok(core.includes(key),`Automation must schedule ${key}`);
assert.match(core,/attempts<3/,'Automation retries must be bounded');
assert.match(core,/Mail transport did not accept the message/,'Guest email failure must be recorded instead of silently marked sent');
assert.match(core,/agent_appointment_lifecycle_manage_url_v700/,'Guest reminders must recover the private management link');
assert.match(core,/team-book\.php\?manage=/,'Team guest reminders must use the Team management lineage');
assert.match(core,/agent_scheduling_public_manage_url_v450/,'Personal guest reminders must use the canonical private management URL');

assert.match(core,/agent_appointment_lifecycle_team_context_v700/,'Phase 7 must resolve Phase 6 Team booking context');
assert.match(core,/canonical_booking_id/,'Team lifecycle must remain tied to canonical participant bookings');
assert.match(core,/primary_booking_id/,'Collective Team guest reminders must dedupe across participant bookings');
assert.match(teamPage,/agent_appointment_lifecycle_queue_booking_v700/,'Team booking creation must queue Team-aware confirmations\/reminders');
assert.match(teamPage,/agent_appointment_lifecycle_queue_notice_v700/,'Team cancellation must queue Team-aware cancellation notices');
assert.match(teamPage,/agent_appointment_lifecycle_housekeeping_v700/,'Team booking\/cancellation must process immediate due automation');

assert.match(core,/human_messages/,'Agent preparation must include prior VP3 human messages when the attendee maps to a member');
assert.match(core,/crm_v180_schema_ready/,'Agent preparation must include safely scoped CRM context when available');
assert.match(core,/search_knowledge\(\$purpose,\$owner,3\)/,'Agent preparation must search owner-scoped relevant Knowledge');
assert.match(core,/agent_scheduling_agent_briefs/,'Agent preparation must persist a canonical brief');
assert.match(core,/pool_agent_id/,'Team preparation must recognize the Team pool Agent when assigned');
assert.match(core,/user_agent_get_v236\(\$pdo,\(int\)\$booking\['owner_user_id'\],\$poolAgentId\)/,'Team Agent prep must revalidate Agent ownership before private participant context is used');

assert.match(core,/function agent_appointment_lifecycle_agent_followup_v700/,'Assigned Agent must be able to create a post-meeting draft + task');
assert.match(core,/function agent_appointment_lifecycle_send_followup_v700/,'Approved follow-up delivery must have a dedicated boundary');
assert.match(memberPage,/name="confirm_send"[\s\S]*required/,'External follow-up sending must require explicit member approval');
assert.match(core,/followup_sent/,'External follow-up sending must be audited');
assert.match(core,/crm_v180_activity/,'Post-meeting notes must update safely attributable CRM history when available');
assert.match(core,/organizer_timezone/,'Follow-up due times must resolve through the appointment organizer timezone');

assert.match(publicPage,/agent_appointment_lifecycle_reschedule_v700/,'Private personal management links must use the in-place Phase 7 reschedule');
assert.match(publicPage,/management link stays the same/,'Guest UI must explain stable private booking lineage');
assert.match(publicPage,/verify_csrf\(\)/,'Public lifecycle writes must retain CSRF protection');
assert.match(publicPage,/agent_scheduling_public_rate_limit_v450/,'Public lifecycle writes must retain rate limiting');
assert.match(memberPage,/require_permission\('account\.access'\)/,'Lifecycle member workspace must require account access');
assert.match(memberPage,/agent_appointment_lifecycle_booking_v700\(\$pdo,\$selectedId,\$userId\)/,'Member lifecycle reads must enforce canonical owner scope');
assert.match(memberPage,/verify_csrf\(\)/,'Lifecycle member mutations must enforce CSRF');
assert.match(memberPage,/Agent draft \+ task/,'Member workspace must expose Agent post-meeting drafting');
assert.match(memberPage,/Run due automation/,'Member workspace must expose an operational automation run control');
assert.match(css,/@media\(max-width:720px\)/,'Lifecycle workspace must provide a mobile layout');
assert.match(cron,/PHP_SAPI!=='cli'/,'Scheduled lifecycle automation must expose a CLI-only runner');
assert.match(cron,/agent_appointment_lifecycle_housekeeping_v700\(\$pdo,500\)/,'CLI runner must execute the canonical lifecycle automation queue');

const teamIndex=bootstrap.indexOf("agent-team-scheduling-tools-v610.php");
const lifeIndex=bootstrap.indexOf("agent-appointment-lifecycle-v700.php");
const knowledgeIndex=bootstrap.indexOf("knowledge.php");
assert.ok(teamIndex>=0&&lifeIndex>teamIndex,'Phase 7 must load after Team Scheduling');
assert.ok(knowledgeIndex>lifeIndex,'Phase 7 may define before Knowledge because Knowledge access is runtime guarded');
assert.match(bootstrap,/agent_appointment_lifecycle_housekeeping_maybe_v700\(\)/,'Bootstrap must execute request-driven lifecycle automation');
assert.match(upgrade,/agent_appointment_lifecycle_schema_ready_v700\(\)/,'Upgrade completeness must include Phase 7');
assert.match(upgrade,/agent_appointment_lifecycle_ensure_schema_v700\(\)/,'Canonical upgrade must install Phase 7');
assert.match(nav,/'appointment_lifecycle','Appointment Lifecycle',url\('\/appointment-lifecycle\.php'\),'agent'/,'Phase 7 must be reachable from canonical member navigation');

console.log('AGENT_APPOINTMENT_LIFECYCLE_V700=PASS');
