import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(path, 'utf8');
const hardening = read('includes/browser-share-v2011.php');
const createApi = read('api/browser-share.php');
const destinationsApi = read('api/extension-share-destinations.php');
const must = (condition, message) => assert.equal(Boolean(condition), true, message);

must(hardening.includes('VP3_BROWSER_SHARE_CAPTURE_MAX_AGE_V2011 = 86400'), 'capture timestamps must have a bounded maximum age');
must(hardening.includes('VP3_BROWSER_SHARE_CAPTURE_FUTURE_SKEW_V2011 = 600'), 'capture timestamps must have bounded future clock skew');
must(hardening.includes("'invalid_capture_time'"), 'out-of-window capture timestamps must be rejected explicitly');

must(hardening.includes('vp3_browser_share_request_fingerprint_v2011'), 'request-bound idempotency fingerprint missing');
must(hardening.includes("substr(hash('sha256', $encoded), 0, 40)"), 'idempotency fingerprint must be derived from normalized request data');
must(hardening.includes('destination_kind') && hardening.includes('destination_id'), 'idempotency fingerprint must bind the destination');
must(hardening.includes('selected_text') && hardening.includes('user_note'), 'idempotency fingerprint must bind captured content and note');
must(hardening.includes("'idempotency_conflict'"), 'idempotency key reuse with a different request must return a conflict');
must(hardening.includes('hash_equals((string)$row[\'operation\'], $requestFingerprint)'), 'stored idempotency request fingerprint must be compared in constant-time style');

must(hardening.includes('vp3_browser_share_scrub_message_v2011'), 'explicit Browser Share message-deletion scrub hook missing');
must(hardening.includes('vp3_browser_share_reconcile_deleted_v2011'), 'soft-deletion reconciliation missing');
for (const field of ['source_url','canonical_url','source_title','source_domain','selected_text','user_note']) {
  must(hardening.includes(`${field}=''`), `deleted Browser Share must scrub ${field}`);
}
must(hardening.includes('m.deleted_at IS NOT NULL AND s.deleted_at IS NULL'), 'privacy reconciliation must follow canonical human message deletion state');

must(hardening.includes('vp3_browser_share_audit_v2011'), 'metadata-only operational audit helper missing');
must(hardening.includes("'source_domain'"), 'audit may retain source domain provenance');
must(hardening.includes('Deliberately excludes selected_text, user_note, source_url and canonical_url'), 'audit privacy boundary must be explicit');
const auditBlock = hardening.match(/function vp3_browser_share_audit_v2011[\s\S]*?\n}\n\nfunction vp3_browser_share_scrub_message_v2011/)?.[0] ?? '';
must(auditBlock !== '', 'could not isolate Browser Share audit helper');
must(!auditBlock.includes("'selected_text' =>") && !auditBlock.includes("'user_note' =>") && !auditBlock.includes("'source_url' =>"), 'operational audit must never include captured content or full source URL');

must(hardening.includes("vp3_extension_session_has_capability_v2001($session, 'team.share.create')"), 'create path must use live v20.01 capability authority');
must(createApi.includes("extension-device-auth-v2001.php"), 'create API must load Phase 1 hardening');
must(createApi.includes("browser-share-v2011.php"), 'create API must load Phase 2 hardening');
must(createApi.includes('vp3_extension_apply_cors_v2001()'), 'create API must use fail-closed CORS');
must(createApi.includes('vp3_extension_session_authenticate_v2001($pdo)'), 'create API must use live permission authorization');
must(createApi.includes('vp3_browser_share_create_v2011('), 'public create API must use request-bound v20.11 transaction path');
must(!createApi.includes('vp3_browser_share_create_v2010($pdo'), 'public API must not call the legacy unbound idempotency create path');

must(destinationsApi.includes("extension-device-auth-v2001.php"), 'destination API must load Phase 1 hardening');
must(destinationsApi.includes("browser-share-v2011.php"), 'destination API must load Phase 2 privacy reconciliation');
must(destinationsApi.includes('vp3_extension_apply_cors_v2001()'), 'destination API must use fail-closed CORS');
must(destinationsApi.includes('vp3_extension_session_authenticate_v2001($pdo)'), 'destination API must use live permission authorization');
must(destinationsApi.includes("vp3_extension_session_has_capability_v2001($session,'team.destinations.read')"), 'destination API must enforce live destination capability');
must(destinationsApi.includes('vp3_browser_share_reconcile_deleted_v2011'), 'normal Browser Share activity must reconcile deleted source content');

console.log('VP3 Browser Share Phase 2 v20.11 hardening audit contract passed.');
