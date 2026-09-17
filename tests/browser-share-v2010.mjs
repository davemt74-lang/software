import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(path, 'utf8');
const share = read('includes/browser-share-v2010.php');
const bootstrap = read('includes/bootstrap.php');
const upgrade = read('upgrade.php');
const createApi = read('api/browser-share.php');
const destinationsApi = read('api/extension-share-destinations.php');
const human = read('includes/human-messaging-v370.php');

const must = (condition, message) => assert.equal(Boolean(condition), true, message);

// Structured Browser Share persistence must remain separate from canonical Chat.
for (const table of ['browser_shares_v2010','human_message_browser_shares_v2010','browser_share_idempotency_v2010']) {
  must(share.includes(table), `missing ${table}`);
}
must(!share.includes('ALTER TABLE human_messages'), 'Browser Share must not mutate the canonical human_messages schema');
must(share.includes('human_message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY'), 'Browser Share must link one structured share to a canonical message');
must(share.includes('browser_share_id BIGINT UNSIGNED NOT NULL'), 'Browser Share link must reference the structured object');
must(share.includes('ON DELETE SET NULL'), 'device deletion must not erase shared collaboration history');

// Immutable/source-aware capture contract.
for (const field of ['source_url','canonical_url','source_title','source_domain','selected_text','captured_at','snapshot_hash','dedupe_fingerprint']) {
  must(share.includes(field), `missing Browser Share field ${field}`);
}
must(share.includes("hash('sha256',$encoded)"), 'snapshot must receive a SHA-256 integrity hash');
must(share.includes('VP3_BROWSER_SHARE_SELECTED_MAX_BYTES_V2010 = 32768'), 'selection payload limit changed unexpectedly');
must(share.includes('VP3_BROWSER_SHARE_NOTE_MAX_BYTES_V2010 = 4096'), 'note payload limit changed unexpectedly');
must(share.includes("$type!=='selection'"), 'v20.10 must remain selection-only');

// Browser URLs are provenance, not a place to persist credentials.
must(share.includes("in_array($scheme,['http','https'],true)"), 'only HTTP(S) URLs may be captured');
must(share.includes("isset($parts['user'])||isset($parts['pass'])"), 'URL credentials must be rejected');
for (const sensitive of ['access_token','refresh_token','authorization','api_key','signature']) {
  must(share.includes(`'${sensitive}'`), `sensitive URL key ${sensitive} must be scrubbed`);
}
must(!share.includes("$normalized.='#'"), 'URL fragments must not be persisted');

// Phase 1 bearer/device authority must be narrowed, never expanded.
must(share.includes("vp3_extension_session_has_capability_v2000($session,$capability)"), 'Browser Share must use Phase 1 capabilities');
must(share.includes("public_id=? AND user_id=? AND device_status='active'"), 'device lookup must be scoped to the authenticated user');
must(share.includes("'team.share.create'"), 'create path must require team.share.create');
must(destinationsApi.includes("'team.destinations.read'"), 'destination API must require team.destinations.read');

// Destination discovery must advertise sendable destinations only.
must(share.includes('vp3_browser_share_conversation_sendable_v2010'), 'destination sendability helper is required');
must(share.includes('vp3_human_can_access_v370'), 'destination access must use canonical Human Messaging authorization');
must(share.includes("$status==='pending'"), 'pending message requests must be considered for sendability');
must(share.includes('vp3_human_dm_route_v370'), 'direct-message policy must remain canonical');

// Transaction and lock-order contract: Human Messaging owns its established locks.
must(human.includes('function vp3_human_send_message_v370'), 'canonical Human Messaging send helper missing');
must(share.includes('vp3_human_send_message_v370('), 'Browser Share must use canonical Human Messaging to create the chat message');
must(share.includes("$owns=!$pdo->inTransaction()"), 'Browser Share must support caller-owned transactions safely');
must(share.includes('if($owns)$pdo->beginTransaction();'), 'Browser Share must begin one transaction when needed');
must(share.includes('if($owns)$pdo->commit();'), 'Browser Share success must commit its transaction');
must(share.includes('if($owns&&$pdo->inTransaction())$pdo->rollBack();'), 'Browser Share failure must roll back its transaction');
must(share.includes('vp3_human_conversation_v370($pdo,$id,false)'), 'Browser Share must not lock an existing conversation before canonical Human Messaging locks users/workspace');
must(!share.includes('vp3_human_conversation_v370($pdo,$id,true)'), 'pre-send conversation FOR UPDATE would invert canonical lock order');

// The canonical human message is a fallback/source marker only; captured text lives in Browser Share.
must(share.includes('Shared from the web'), 'fallback Chat message marker is required');
must(!share.match(/fallback_body_v2010[\s\S]{0,800}selected_text/), 'fallback Chat body must not copy captured selected text');

// Idempotency must serialize retries by device + UUID and return the same result.
must(share.includes('PRIMARY KEY (device_id,idempotency_key)'), 'idempotency key must be unique per connected browser');
must(share.includes('INSERT IGNORE INTO browser_share_idempotency_v2010'), 'idempotency reservation must be concurrency-safe');
must(share.includes('FOR UPDATE'), 'idempotency replay must lock its reservation row');
must(share.includes("'idempotent_replay'=>true"), 'retries must return the previously committed result');
must(createApi.includes('HTTP_X_VP3_IDEMPOTENCY_KEY'), 'create API must require the idempotency header');

// Share lookup must re-authorize via the message's current conversation.
must(share.includes('function vp3_browser_share_for_message_v2010'), 'message-to-share authorized lookup missing');
must(share.includes('function vp3_browser_share_by_public_id_v2010'), 'public Browser Share authorized lookup missing');
must(share.includes("!vp3_human_can_access_v370($pdo,$conversation,$userId)"), 'Browser Share ID must never act as authorization');

// API boundary: bounded, versioned, authenticated, no DDL.
for (const api of [createApi, destinationsApi]) {
  must(api.includes("header('Cache-Control: no-store')"), 'Browser Share APIs must disable caching');
  must(api.includes('vp3_extension_apply_cors_v2000()'), 'Browser Share APIs must use Browser Companion CORS policy');
  must(api.includes('vp3_extension_session_authenticate_v2000($pdo)'), 'Browser Share APIs must authenticate Phase 1 bearer sessions');
  must(!api.includes('vp3_browser_share_ensure_schema_v2010'), 'public Browser Share APIs must never run DDL');
  must(api.includes('HTTP_X_VP3_CONTRACT_VERSION'), 'Browser Share APIs must enforce extension contract version');
}
must(createApi.includes('strlen($raw)>65536'), 'Browser Share request body must be bounded');
must(createApi.includes("schema_version"), 'Browser Share API must enforce object schema version');

// Deployment integration; protect unrelated migration calls from accidental edits.
must(bootstrap.includes("require_once __DIR__.'/human-messaging-v370.php';\nrequire_once __DIR__.'/browser-share-v2010.php';"), 'Browser Share service must load after canonical Human Messaging');
must(upgrade.includes('vp3_browser_share_schema_ready_v2010()'), 'upgrade completeness must include Browser Share schema');
must(upgrade.includes('vp3_browser_share_ensure_schema_v2010();'), 'upgrade must install Browser Share schema');
must(upgrade.includes('video_meeting_closure_ensure_schema_v18220($pdo);'), 'existing meeting-closure upgrade call must remain intact');
must(upgrade.includes('vp3_human_messaging_v370_migrate_legacy($pdo);'), 'existing canonical message migration must remain intact');

console.log('VP3 Browser Share backend v20.10 contract passed.');
