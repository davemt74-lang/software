import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(path, 'utf8');
const manual = read('includes/video-meetings-manual-v1830.php');
const security = read('includes/video-meetings-security-v1800.php');
const lifecycle = read('includes/agent-appointment-lifecycle-v700.php');
const editor = read('calendar-event.php');
const guest = read('meeting-guest.php');
const tokenApi = read('api/video-meeting-token.php');
const presenceApi = read('api/video-meeting-presence.php');
const transcriptApi = read('api/video-meeting-transcript.php');
const upgrade = read('upgrade.php');

// Phase 18.3 is loaded into the existing Appointment/Video Meeting runtime.
assert.ok(lifecycle.includes("require_once __DIR__.'/video-meetings-manual-v1830.php'"));
assert.ok(manual.includes("const VP3_VIDEO_MEETINGS_MANUAL_V1830='video-meetings-manual-v1830-20260915'"));

// Event-specific guest policy is durable and intentionally defaults to the
// existing private invitation model for backward compatibility.
assert.ok(manual.includes('CREATE TABLE IF NOT EXISTS video_meeting_access_settings'));
assert.ok(manual.includes("guest_access_mode VARCHAR(24) NOT NULL DEFAULT 'invite_only'"));
assert.ok(manual.includes("['invite_only','email_gate']"));
assert.ok(upgrade.includes('video_meeting_manual_schema_ready_v1830()'));
assert.ok(upgrade.includes('video_meeting_manual_ensure_schema_v1830($pdo)'));

// Manual meeting creation attaches to the canonical User Calendar event rather
// than generating a second owner event or a Scheduling booking.
assert.ok(manual.includes("'calendar_event_id'=>$eventId"));
assert.ok(manual.includes("'skip_owner_calendar'=>true"));
assert.ok(manual.includes('video_meeting_for_calendar_event_v1800'));
assert.ok(manual.includes('video_meeting_create_v1800'));
assert.ok(manual.includes('video_meeting_update_calendar_events_v1800'));
assert.ok(!manual.includes('agent_scheduling_bookings'));
assert.ok(editor.includes('name="add_video_meeting"'));
assert.ok(editor.includes('video_meeting_manual_sync_event_v1830'));

// Calendar-created meetings stay Notes-first with recording off and canonical
// Phase 18 transcription/intelligence policy left in place.
assert.ok(manual.includes("'agent_mode'=>'notes'"));
assert.ok(manual.includes("'transcription_enabled'=>true"));
assert.ok(manual.includes("'recording_enabled'=>false"));
assert.ok(editor.includes('Recording stays off by default'));
assert.ok(editor.includes('HomeServer processing policy'));

// Attendees are explicit invitees. Existing VP3 users flow through the existing
// participant helper, which owns member Calendar + VP3 notification behavior.
assert.ok(editor.includes('Invite attendees — one per line'));
assert.ok(manual.includes('video_meeting_manual_parse_attendees_v1830'));
assert.ok(manual.includes('video_meeting_add_participant_v1800'));
assert.ok(editor.includes('VP3 accounts are recognized by email and receive a native VP3 notification'));
assert.ok(manual.includes('],false);'), 'manual orchestration must suppress the legacy automatic email helper');

// Existing member attendees are notified when an edited Calendar event changes
// their meeting while newly added members keep the canonical invitation notice.
assert.ok(manual.includes('video_meeting_manual_meeting_changed_v1830'));
assert.ok(manual.includes("'video_meeting_updated'"));
assert.ok(manual.includes('!isset($newIds[$pid])'));

// External guest email is opt-in. Private mode uses the hardened bearer-email
// helper; email-gate mode sends the public page but no bearer capability.
assert.ok(editor.includes('send_guest_emails'));
assert.ok(manual.includes('video_meeting_secure_invitation_email_v1800'));
assert.ok(manual.includes('video_meeting_manual_public_invitation_email_v1830'));
assert.ok(manual.includes('video_meeting_public_guest_url_v1830'));
assert.ok(manual.includes('The public meeting link does not grant access by itself.'));
assert.ok(editor.includes('Copy private invite'), 'organizer must be able to manually copy a private guest capability');

// Email-gated public admission only recognizes an already invited, unbound guest
// email. It never creates a participant from an arbitrary address and it cannot
// impersonate a VP3 member account.
assert.ok(guest.includes('Guest meeting access'));
assert.ok(guest.includes('Invited email required'));
assert.ok(guest.includes('verify_csrf()'));
assert.ok(manual.includes("role='attendee' AND LOWER(email)=?"));
assert.ok(manual.includes("(int)($participant['user_id']??0)>0"));
assert.ok(manual.includes('vp3_meeting_email_access'));
assert.ok(manual.includes('vp3_meeting_email_gate_rate'));
assert.ok(manual.includes('attempts>=10'));
assert.ok(manual.includes("['scheduled','ready','live']"), 'closed meetings must reject new email-gate claims');
assert.ok(manual.includes('session_regenerate_id(true)'), 'successful guest proof should rotate the session id');
assert.ok(!manual.includes('INSERT INTO video_meeting_participants'), 'email proof must never create arbitrary guests directly');

// The public URL contains only the opaque meeting id. Authorization after email
// proof is server-side session state, so the normal room APIs reuse one canonical
// secure_access boundary without query-string guest credentials.
assert.ok(manual.includes("'/meeting-guest.php?meeting='"));
assert.ok(!manual.includes("'/meeting-guest.php?invite='"));
assert.ok(security.includes('video_meeting_email_gate_access_v1830'));
for (const [name, source] of [['token', tokenApi], ['presence', presenceApi], ['transcript', transcriptApi]]) {
  assert.ok(source.includes('video_meeting_secure_access_v1800'), `${name} API must keep canonical meeting authorization`);
}
assert.ok(security.includes("$inviteToken===''"));
assert.ok(security.includes('!$user'));

// Selecting email-gate mode is authoritative: any previously issued unbound
// guest bearer link stops being an alternate path around the email check. A
// member-bound invite remains identity-bound and is not weakened by this rule.
assert.ok(security.includes("$inviteToken!==''"));
assert.ok(security.includes("video_meeting_guest_access_mode_v1830($pdo,$meeting)==='email_gate'"));
assert.ok(security.includes("(int)($participant['user_id']??0)===0"));

// Existing individual invitations remain private capabilities and revoked rows
// cannot be used through either the old bearer path or the new email gate.
assert.ok(security.includes("['cancelled','revoked','declined']"));
assert.ok(manual.includes("invitation_status NOT IN ('cancelled','revoked','declined')"));

// Calendar removal first lets User Calendar cancel the canonical owner event.
// Only after commit may the Video Meeting projection cancel attendee calendars;
// doing it earlier would make user_calendar_cancel_event_v1300 report failure.
assert.ok(editor.includes('video_meeting_manual_cancel_event_v1830'));
assert.ok(editor.includes('video_meeting_manual_after_cancel_v1830'));
const cancelStart = manual.indexOf('function video_meeting_manual_cancel_event_v1830');
const afterCancelStart = manual.indexOf('function video_meeting_manual_after_cancel_v1830');
assert.ok(cancelStart >= 0 && afterCancelStart > cancelStart);
const cancelBlock = manual.slice(cancelStart, afterCancelStart);
assert.ok(!cancelBlock.includes('video_meeting_update_calendar_events_v1800'), 'owner calendar must not be pre-cancelled before canonical User Calendar cancellation');
const afterCancelBlock = manual.slice(afterCancelStart, manual.indexOf('function video_meeting_email_gate_rate_check_v1830'));
assert.ok(afterCancelBlock.includes('video_meeting_update_calendar_events_v1800'));
assert.ok(afterCancelBlock.includes('video_meeting_livekit_delete_room_v1800'));
assert.ok(afterCancelBlock.includes('video_meeting_external_calendar_sync_members_v1801'));

// The editor exposes the stable public guest link only for email-gate mode and
// keeps existing meeting identity/link when an event is edited.
assert.ok(editor.includes('Public guest meeting link'));
assert.ok(editor.includes("$guestAccessMode==='email_gate'"));
assert.ok(editor.includes('Editing the event keeps the same meeting identity and link'));
assert.ok(editor.includes("$wasExisting?'calendar.event.update':'calendar.event.create'"), 'audit log must preserve create vs update semantics');

console.log('Video Meetings Phase 18.3 manual Calendar meeting contract passed.');
