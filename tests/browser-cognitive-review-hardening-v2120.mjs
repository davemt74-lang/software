import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const security=read('includes/extension-device-auth-v2001.php');
const token=read('includes/extension-device-token-v2100.php');
const cards=read('chat-cognitive-cards-v520.js');
const orchestration=read('chat-cognitive-orchestration-v560.js');
const feedJs=read('chat-cognitive-feed-v530.js');
const feedPhp=read('includes/cognitive-feed-v530.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const panel=read('browser-companion/sidepanel.js');
const cognitiveExtensionApi=read('api/extension-cognitive-now-v2120.php');
const notificationCenter=read('chat-notifications-drawer-v240.js');
const cognitivePresentationJs=read('chat-cognitive-presentation-v510.js');

// 1. Legacy Browser Companion sessions must still authenticate before upgrade.php
// creates the v21 authorization-code table.
must(
  security.includes("vp3_extension_device_token_schema_ready_v2100($pdo)")
    && security.indexOf("vp3_extension_device_token_schema_ready_v2100($pdo)") <
       security.indexOf("vp3_extension_device_token_authenticate_v2100($pdo, $token)"),
  'durable-token auth must be schema-gated before attempting v21 authentication'
);
must(
  security.includes("if (!$session && vp3_extension_schema_ready_v2000($pdo))")
    && security.includes("vp3_extension_session_authenticate_v2000($pdo, $token)"),
  'legacy v20 session fallback must survive a pre-v21-upgrade deployment'
);

// 2. Poll-heavy read traffic must not turn every durable-token request into a DB write.
must(
  token.includes("last_used_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)"),
  'durable device last-used telemetry must be write-throttled'
);
must(
  token.includes("DELETE FROM extension_device_codes_v2100")
    && token.includes("installation_id=?")
    && token.includes("consumed_at IS NOT NULL OR expires_at<=NOW()"),
  'stale one-time browser authorization codes must be cleaned per installation'
);
must(
  !token.includes("SET last_used_at=NOW(),updated_at=NOW() WHERE id=?"),
  'durable token reads must not touch updated_at on every request'
);
must(
  token.includes("SET last_used_at=NOW(),updated_at=updated_at"),
  'activity telemetry must not mutate the device configuration timestamp'
);
const legacyDeviceAuth=read('includes/extension-device-auth-v2000.php');
must(
  legacyDeviceAuth.includes("ORDER BY COALESCE(last_used_at,approved_at,created_at) DESC,id DESC"),
  'device listing must sort by actual activity after durable-token telemetry is decoupled from updated_at'
);

// 3. A Universal Card tool click is a request for authoritative handling, never
// implicit acceptance. No handler means review-only.
must(cards.includes("let resolution = 'unhandled';"),'tool request must start unhandled');
must(cards.includes("accept(){ if (resolution === 'unhandled') resolution = 'accepted'; }"),'tool protocol must require explicit one-shot accept()');
must(cards.includes("reject(){ if (resolution === 'unhandled') resolution = 'rejected'; }"),'tool protocol must expose explicit one-shot reject()');
must(cards.includes("bubbles:true"),'tool request must bubble to an authoritative handler');
must(cards.includes("const handled = resolution !== 'unhandled';"),'tool request must distinguish handled from unhandled');
must(cards.includes("const accepted = resolution === 'accepted';"),'tool request acceptance must be explicit');
must(cards.includes("detail:{action,card,handled,accepted}"),'card action telemetry must carry handled state');
must(cards.includes("Do not execute anything until I explicitly confirm"),'unhandled tool actions must degrade to review-only prompt');
must(!cards.includes("const allowed = window.dispatchEvent(event);"),'dispatchEvent return value must never be treated as authorization');
must(!cards.includes("if (allowed) runPrompt('Use "), 'unhandled tool clicks must not create execution prompts');

must(
  orchestration.includes("if(detail.handled!==true)return;"),
  'orchestration must ignore unhandled tool requests'
);
must(
  orchestration.includes("const accepted=detail.accepted===true;"),
  'orchestration handoff acceptance must be explicit'
);
must(
  feedJs.includes("type==='tool'&&!handled?'tool_review'"),
  'unhandled tool clicks must learn as review/engagement, not action'
);

// 4. Browser Companion must recover immediately when live Agent permission is revoked.
must(panel.includes("function dropCapability(cap){") && panel.includes("renderConnection(state);"),
  'dropping a live capability must re-render the account/workspace shell');
must(panel.includes("if(e.code==='capability_denied')"),'Agent Now must detect live capability revocation');
must(panel.includes("setView('this_page')"),'Agent Now must fall back to an authorized view');
must(panel.includes("else renderNow(null);"),'Agent Now must clear inaccessible cognitive content');

// 5. Voice preference/settings failure is a voice-only degradation, not a failure
// of the entire Cognitive Presentation state.
must(
  presentation.includes("try{")
    && presentation.includes("chat_settings_agent_voice_enabled_v237($pdo,$user)")
    && presentation.includes("chat_settings_get_v237($pdo,(int)$user['id'])"),
  'Agent Voice settings read must be guarded through the canonical helper with legacy fallback'
);
must(presentation.includes("VP3 Cognitive Presentation voice settings unavailable:"),
  'voice-settings failures must be observable');
must(presentation.includes("last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)"),
  'presentation polling presence writes must be throttled');
must(presentation.includes("SET last_seen_at=UTC_TIMESTAMP(),updated_at=updated_at"),
  'presentation presence telemetry must not mutate semantic state timestamps');
must(presentation.includes("VP3 Cognitive Presentation notification read unavailable:"),
  'notification read degradation must be observable');
must(
  presentation.includes("if(!chat_settings_agent_voice_enabled_v237($pdo,$user))return null;")
    && presentation.includes("catch(Throwable $e)")
    && presentation.includes("return null;"),
  'voice-settings failure must fail closed'
);

const runtime=read('includes/cognitive-runtime-v500.php');
must(runtime.includes("$voice=false;"),'core presentation context must default Agent Voice off');
must(runtime.includes("$voice=!empty($settings['agent_voice_enabled']);"),
  'core presentation context must enable voice only from canonical settings');
must(runtime.includes("VP3 Cognitive Runtime voice settings unavailable:"),
  'core voice-settings failures must be observable');
must(runtime.includes("'agent_voice_enabled'=>false"),
  'presentation arbitration defaults must be voice-safe when no canonical context is supplied');

must(notificationCenter.includes("if (!agentVoiceEnabled() || generation !== speechGeneration) return false;"),
  'shared voice delivery must fail closed when Agent Voice is disabled or the proactive speech generation was cancelled');
must(notificationCenter.includes("if (!spoken && generation === speechGeneration) spoken = await browserSpeak(message, generation);"),
  'shared voice delivery must report browser fallback speech only while the proactive generation remains active');
must(notificationCenter.includes("return spoken === true;"),
  'shared voice delivery must return actual speech success');
must(notificationCenter.includes("announce:text => queueSpeech(String(text || ''))"),
  'notification center announce() must return the queued speech result');

must(cognitivePresentationJs.includes("if (!center || typeof center.announce !== 'function') return false;"),
  'Cognitive Presentation must not consume voice when no announcer exists');
must(cognitivePresentationJs.includes("spoken = (await Promise.resolve(center.announce(String(candidate.message)))) === true;"),
  'Cognitive Presentation must await actual voice delivery');
must(cognitivePresentationJs.indexOf("lastVoiceThrough = through;") >
     cognitivePresentationJs.indexOf("if (!spoken) return false;"),
  'local voice cursor must advance only after successful speech');
must(cognitivePresentationJs.includes("if (through <= lastVoiceThrough) {")
  && cognitivePresentationJs.includes("await post('voice_delivered',{through_id:through});"),
  'failed server voice acknowledgement must retry without re-speaking');

must(cognitiveExtensionApi.includes("VP3 Browser Companion Cognitive card unavailable ["),
  'extension card render degradation must be observable without weakening fail-closed behavior');

// 6. Learning reconciliation is bounded to once per feed candidate cycle.
const reconcileCalls=(feedPhp.match(/vp3_cognitive_learning_reconcile_v540\(\$pdo,\$user,\$namespace\)/g)||[]).length;
assert.equal(reconcileCalls,1,'Cognitive Feed must reconcile learning exactly once per compose/candidate cycle');
must(feedPhp.includes('Reconciliation already runs once before orchestration sync'),
  'single-reconciliation boundary must be documented');

const learning=read('includes/cognitive-learning-v540.php');
must(
  learning.includes("last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)"),
  'unchanged cognitive lifecycle sightings must be write-throttled'
);
must(
  learning.includes("item_fingerprint<>VALUES(item_fingerprint)"),
  'cognitive lifecycle fingerprint changes must still persist immediately'
);
must(
  learning.includes("OR source_kind<>VALUES(source_kind)")
    && learning.includes("OR object_scope<>VALUES(object_scope)"),
  'cognitive lifecycle updated_at must represent state/reference changes, not routine sightings'
);

console.log('VP3 Browser Companion + Cognitive Runtime review hardening contract passed.');
