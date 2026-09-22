import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const offscreen=read('browser-companion/offscreen.js');
const runtime=read('includes/extension-notifications-v2140.php');
const api=read('api/extension-notifications-v2140.php');
const voice=read('api/extension-agent-voice-v2140.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const cognitivePresentation=read('includes/cognitive-presentation-v510.php');

const p=String(manifest.version||'').split('.').map(Number);
must(p.length===3&&(p[0]>21||(p[0]===21&&p[1]>=4)),'v21.40+ manifest version missing');
must(background.includes(`const VP3_EXTENSION_VERSION = '${manifest.version}';`),'retained Browser Companion request version must match the manifest');

for(const permission of ['notifications','alarms','offscreen']) must(manifest.permissions.includes(permission),`missing MV3 permission ${permission}`);
must(manifest.icons?.['128']==='notification-icon.png','PNG extension icon missing');
must(manifest.action?.default_icon==='notification-icon.png','PNG action icon missing');
must(fs.existsSync('browser-companion/notification-icon.png'),'packaged PNG notification icon missing');
must(!fs.existsSync('browser-companion/notification-icon.svg'),'notification runtime must not depend on SVG icon support');

must(background.includes("const VP3_NOTIFICATION_ALARM_V2140 = 'vp3-proactive-notifications-v2140';"),'notification alarm missing');
must(background.includes("periodInMinutes:1"),'notification polling must be bounded to one minute');
must(background.includes("authorizedFetch('/api/extension-notifications-v2140.php'"),'durable-token notification API bridge missing');
must(background.includes("chrome.notifications.create(id, options)"),'Chrome visual notification delivery missing');
must(background.includes("proactiveNotificationApi('visual_delivered'"),'visual delivery acknowledgement missing');
must(background.includes("proactiveNotificationApi('release'"),'failed visual claim release missing');
must(background.includes("proactiveNotificationApi('voice_delivered'"),'confirmed voice delivery acknowledgement missing');
must(background.includes("proactiveNotificationApi('voice_failed'"),'voice failure retry signal missing');
must(background.includes("proactiveNotificationApi('snooze'"),'notification snooze missing');
must(background.includes("proactiveNotificationApi('dismiss'"),'notification dismiss missing');
must(background.includes("proactiveNotificationApi('open'"),'canonical notification open flow missing');
must(background.includes("vp3AgentVoiceBusy()"),'active/audible VP3 Agent Voice suppression missing');
must(background.indexOf("if (await vp3AgentVoiceBusy()) return false;") < background.indexOf("authorizedVoiceAudioDataUrl(candidate.event_key)"),
  'voice must be suppressed before premium audio is requested');
must(background.includes("chrome.runtime.getContexts"),'offscreen document detection missing');
must(background.includes("reasons:['AUDIO_PLAYBACK']"),'offscreen audio-only reason missing');
must(!background.includes('speechSynthesis'),'v21.40 must not substitute OS TTS for VP3 Agent Voice');

must(offscreen.includes("audio.onended = () => finish(true)"),'offscreen playback must confirm actual completion');
must(offscreen.includes("audio.onerror = () => finish(false"),'offscreen playback failure signal missing');
must(offscreen.indexOf("sendResponse({ ok:true })") > offscreen.indexOf("play(message.data_url)"),
  'offscreen success must follow playback promise');
must(!offscreen.includes('localStorage')&&!offscreen.includes('sessionStorage'),'offscreen voice must not create local delivery state');

must(runtime.includes('CREATE TABLE IF NOT EXISTS extension_notification_delivery_v2140'),'server-owned delivery ledger missing');
must(runtime.includes('chat_settings_agent_voice_enabled_v237'),'Browser Agent Voice must use the canonical account voice authority');
must(runtime.includes('UNIQUE KEY uq_extension_notification_event_v2140 (owner_user_id,event_key)'),'cross-device event dedupe key missing');
must(runtime.includes('claim_token_hash CHAR(64)'), 'hashed claim token missing');
must(runtime.includes('claimed_device_id CHAR(36)'), 'device claim binding missing');
must(runtime.includes('claim_expires_at DATETIME'), 'claim expiry missing');
must(runtime.includes('voice_through_notification_id BIGINT UNSIGNED'), 'canonical voice through-id ledger missing');
must(runtime.includes('SELECT * FROM extension_notification_delivery_v2140')&&runtime.includes('FOR UPDATE'),'cross-device claim row lock missing');
must(runtime.includes("if($claimFresh){$pdo->commit();return null;}"),
  'fresh claim must be exclusive even to the same browser so claim tokens cannot rotate');
must(runtime.includes("source_kind='notification'")&&runtime.includes("notification_id>? AND notification_id<=?"),
  'canonical voice must bind to the exact canonical cursor window');
must(runtime.includes("vp3_cognitive_presentation_open_digest_v510")&&runtime.includes("vp3_cognitive_presentation_voice_candidate_v510($pdo,$user,$state,$digest)"),
  'Chrome voice must reuse the canonical persisted return digest and Cognitive Presentation voice candidate');
must(runtime.includes("voice_through_notification_id=?"),'spoken canonical through-id must be persisted before playback');
must(runtime.includes("vp3_cognitive_presentation_voice_delivered_v510($pdo,$user,$namespace,$through)"),
  'shared voice cursor must advance only with stored spoken through-id');
must(runtime.includes("$ownsTransaction=!$pdo->inTransaction();")&&runtime.includes("if($ownsTransaction)$pdo->beginTransaction();"),
  'shared voice cursor and Browser delivery acknowledgement must share one transaction');
must(runtime.includes("if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();"),
  'shared voice acknowledgement must roll back atomically on failure');
must(runtime.includes("if((string)($item['source']??'')==='notification')continue;"),
  'Cognitive Feed notification projections must not duplicate canonical notification interruptions');

must(runtime.includes("'VP3 needs your attention'")&&runtime.includes("'Open VP3 to review this update.'"),
  'sensitive lock-screen content must be minimal');
must(runtime.includes("'voice_allowed'=>!empty($candidate['voice_allowed'])&&!$sensitive"),
  'sensitive notification voice must fail closed');
must(cognitivePresentation.includes('function vp3_cognitive_presentation_voice_sensitive_v510'),
  'canonical Cognitive Presentation must share the sensitive voice gate');
must(cognitivePresentation.includes("if(vp3_cognitive_presentation_voice_sensitive_v510($row))return false;"),
  'canonical Agent Voice must fail closed before attention/type eligibility');
must(!cognitivePresentation.includes("order|payment|message|approval|workflow|security"),
  'canonical Agent Voice type regex must not re-enable sensitive payment/security categories');
must(runtime.includes("$safe=vp3_extension_notification_candidate_public_v2140($candidate);"),
  'delivery ledger must store the sanitized/redacted candidate');
must(runtime.includes("vp3_extension_notification_context_terms_v2140"),'ephemeral page-related delivery scoring missing');
must(!runtime.includes('current_context VARCHAR')&&!runtime.includes('page_url VARCHAR'),'ephemeral browser context must not be persisted in delivery ledger');

must(api.includes("vp3_extension_session_has_capability_v2001($session,'notifications.read')"),'live notifications.read capability gate missing');
must(api.includes("notification_unavailable"),'stale notification action rejection missing');
must(api.includes("'delivery_authority'=>'server'"),'server delivery authority marker missing');

must(voice.includes("vp3_extension_session_has_capability_v2001($session,'notifications.read')"),'voice endpoint live notification capability missing');
must(voice.includes("has_permission('chat.access',$user)"),'voice endpoint live Agent permission missing');
must(voice.includes("vp3_extension_notification_voice_pending_v2140"),'voice endpoint must resolve pending server event');
must(!voice.includes("$input['text']")&&!voice.includes("$input['message']"),'extension voice endpoint must never accept arbitrary TTS text');
must(voice.includes("https://api.elevenlabs.io/v1/text-to-speech/"),'premium Agent Voice provider missing');
must(voice.includes("CURLOPT_SSL_VERIFYPEER=>true")&&voice.includes("CURLOPT_FOLLOWLOCATION=>false"),
  'premium voice transport hardening missing');

must(!bootstrap.includes("require_once __DIR__.'/extension-notifications-v2140.php';"),'v21.40 notification module must not load inside low-level core bootstrap');
must(upgrade.includes("require_once __DIR__ . '/includes/extension-notifications-v2140.php';"),'v21.40 upgrade must explicitly load notification schema module');
must(upgrade.includes('vp3_extension_notifications_schema_ready_v2140()'),'v21.40 upgrade readiness missing');
must(upgrade.includes('vp3_extension_notifications_ensure_schema_v2140();'),'v21.40 schema install missing');

for(const forbidden of ['execute_tool','tool_execute','chrome.storage.local.set({ notification','notification_history']){
  must(!background.includes(forbidden),`Chrome must not add autonomous/local notification authority: ${forbidden}`);
}

console.log('VP3 Browser Companion Proactive Notifications + Agent Voice v21.40 contract passed.');
