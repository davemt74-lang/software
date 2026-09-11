import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const exists = path => fs.existsSync(new URL(`../${path}`, import.meta.url));

const connector = read('includes/homeserver-scheduling-connector-v620.php');
const endpoint = read('api/homeserver-scheduling-v620.php');
const status = read('api/homeserver-status.php');
const approvals = read('includes/homeserver-approvals-v028.php');

assert.match(connector, /VP3_HOMESERVER_SCHEDULING_CONNECTOR_V620/, 'connector must expose a versioned v6.20 contract');
assert.match(connector, /homeserver_scheduling_tokens/, 'connector must persist a dedicated reverse credential');
assert.match(connector, /token_hash CHAR\(64\).*UNIQUE/s, 'reverse credential must be hash-addressed');
assert.match(connector, /token_enc TEXT NOT NULL/, 'reverse credential must be encrypted at rest');
assert.match(connector, /status ENUM\('active','revoked'\)/, 'reverse credential must be revocable');
assert.match(connector, /function homeserver_scheduling_v620_revoke/, 'connector must expose credential revocation');
assert.match(connector, /hash\('sha256',\$rawToken\)/, 'incoming reverse bearer must be verified by hash');

assert.match(connector, /homeserver_scheduling_idempotency/, 'booking mutations must retain retry receipts');
assert.match(connector, /UNIQUE KEY uq_hs_sched_idempotency \(user_id,operation,idempotency_key\)/, 'idempotency keys must be user+operation scoped');
assert.match(connector, /GET_LOCK\(\?,5\)/, 'duplicate mutation attempts must serialize');
assert.match(connector, /A valid idempotency_key is required for scheduling mutations/, 'cloud mutations must reject missing idempotency keys');

assert.match(connector, /'vp3\.connector\.configure'/, 'provisioning must use the bounded HomeServer bootstrap operation');
assert.match(connector, /catch\(Throwable \$e\)\{\s*homeserver_scheduling_v620_revoke\(\$userId\)/s, 'failed provisioning must revoke the newly issued reverse credential');
assert.match(status, /homeserver_scheduling_v620_revoke\(\$userId\);\s*homeserver_vp3_disconnect\(\$userId\)/s, 'disconnect must revoke scheduling access before relay teardown');
assert.doesNotMatch(status, /REQUEST_METHOD.*GET[\s\S]*homeserver_scheduling_v620_provision/s, 'plain status reads must not provision the connector');
assert.match(status, /\$action === 'check_pairing'[\s\S]*\$pairing\['ready'\][\s\S]*homeserver_scheduling_v620_provision/s, 'successful pairing completion should provision scheduling');

assert.match(approvals, /'scheduling\.read','scheduling\.write'/, 'VP3 pairing must request bounded scheduling permissions');
assert.match(connector, /'overview','availability','booking\.create','booking\.reschedule','booking\.cancel','team\.availability','team\.booking\.create','team\.booking\.cancel'/, 'connector must expose the complete personal+Team scheduling operation allowlist');
assert.match(connector, /agent_scheduling_slots_for_date_v430/, 'personal availability must reuse canonical conflict-safe scheduling');
assert.match(connector, /agent_team_scheduling_slots_v600/, 'Team availability must reuse canonical pooled scheduling');
assert.match(connector, /agent_scheduling_create_booking_v430/, 'personal create must reuse canonical booking');
assert.match(connector, /agent_team_scheduling_create_booking_v600/, 'Team create must reuse canonical Team booking');
assert.match(connector, /agent_scheduling_tools_reschedule_owner_v460/, 'reschedule must reuse canonical owner scheduling logic');
assert.match(connector, /agent_scheduling_cancel_booking_v430/, 'personal cancel must reuse canonical cancellation');
assert.match(connector, /agent_team_scheduling_cancel_booking_v600/, 'Team cancel must reuse canonical Team cancellation');

const safeSlotStart = connector.indexOf('function homeserver_scheduling_v620_safe_slot');
const eventStart = connector.indexOf('function homeserver_scheduling_v620_event');
assert.ok(safeSlotStart >= 0 && eventStart > safeSlotStart, 'safe slot projection must be present');
const safeSlot = connector.slice(safeSlotStart, eventStart);
assert.doesNotMatch(safeSlot, /eligible_member_ids|member_user_id|event_type_id/, 'normalized availability must not expose Team member internals');

const safeBookingStart = connector.indexOf('function homeserver_scheduling_v620_safe_booking');
assert.ok(safeBookingStart >= 0 && safeSlotStart > safeBookingStart, 'safe booking projection must be present');
const safeBooking = connector.slice(safeBookingStart, safeSlotStart);
assert.doesNotMatch(safeBooking, /assigned_user_id|host_user_id|canonical_booking_id|management_key/, 'normalized booking results must not expose internal host or management identifiers');

assert.match(endpoint, /\$_SERVER\['REQUEST_METHOD'\]!==\s*'POST'/, 'machine scheduling endpoint must be POST-only');
assert.match(endpoint, /HTTP_AUTHORIZATION/, 'machine scheduling endpoint must require bearer authorization');
assert.match(endpoint, /homeserver_scheduling_v620_authenticate/, 'machine endpoint must authenticate the reverse credential');
assert.match(endpoint, /homeserver_scheduling_v620_execute/, 'machine endpoint must dispatch only through the bounded connector');
assert.doesNotMatch(endpoint, /access_token|refresh_token|client_secret|provider_token/i, 'machine endpoint must not expose provider OAuth credential material');

assert.equal(exists('api/homeserver-scheduling-provision-v620.php'), false, 'redundant standalone provisioning endpoint must remain absent');

console.log('HomeServer scheduling connector v6.20 contract passed');
