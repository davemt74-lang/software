import assert from 'node:assert/strict';
import fs from 'node:fs';
const get=p=>fs.readFileSync(p,'utf8');
const f={stream:get('includes/phone-voice-stream-v150.php'),
  voice:get('includes/phone-voice-v130.php'), inbound:get('api/phone-voice-inbound-v130.php'),
  resolver:get('api/phone-voice-stream-resolve-v150.php'),
  active:get('api/phone-voice-stream-active-v150.php'),
  complete:get('api/phone-voice-stream-complete-v150.php'),
  admin:get('admin/phone.php'),assignment:get('includes/phone-cloud-v100.php'),
  upgrade:get('upgrade.php'),turn:get('includes/phone-voice-conversation-v140.php')};
let checks=0;const check=(yes,label)=>{assert.ok(yes,label);checks++;};
check(f.stream.includes('phone_voice_stream_routes_v150')&&f.stream.includes('phone_voice_stream_claims_v150'), 'separate streaming route and deduplicated child binding');
check(f.upgrade.includes('vp3_phone_stream_ensure_v150($pdo)')&&f.upgrade.includes('vp3_phone_stream_ready_v150()'), 'migration and readiness');
check(f.resolver.includes('HTTP_X_VP3_SIGNATURE')&&f.resolver.includes("hash_hmac('sha256',$stamp.'.'.$raw,$secret)"), 'signed worker callback with exact body');
check(f.resolver.includes('abs(time()-(int)$stamp)>300'), 'short server signature expiry');
check(f.stream.includes('abs($now-(int)$issued)>180')&&f.stream.includes('phone_voice_stream_claims_v150'), 'three-minute SIP proof and no parallel child');
check(f.active.includes('HTTP_X_VP3_SIGNATURE')&&f.active.includes('vp3_phone_stream_active_v150')
  &&f.stream.includes('vp3_phone_stream_route_v150($pdo,$route)'), 'per-turn signed revocation of Cloud streaming grant');
check(f.stream.includes("['streaming_active']")&&f.stream.includes('vp3_phone_voice_current_route_v131'), 'live signed Twilio receipt and tenant revalidation');
check(f.stream.includes('homeserver_agent_id')&&f.stream.includes('cloud_agent_id')&&f.stream.includes('vp3_phone_agent_owned_v100'), 'independent, owner-scoped Cloud and HomeServer agents');
check(f.stream.includes("'caller_verified'=>false,'allow_actions'=>false"), 'anonymous caller cannot execute tools');
check(f.stream.includes('transport=tls')&&f.stream.includes('livekit\\.cloud'), 'fixed TLS LiveKit-only SIP destination');
check(f.voice.includes('vp3_phone_stream_dial_v150')&&f.inbound.includes('phone-voice-stream-v150.php'), 'Cloud inbound routes streaming without touching Twilio config');
check(f.complete.includes('HTTP_X_TWILIO_SIGNATURE')&&f.stream.includes('vp3_phone_voice_check_event_v130'), 'dial completion signed by carrier');
check(f.stream.includes("'voicemail_fallback'")&&f.stream.includes("$status==='completed'"), 'failed SIP falls back to voicemail, completed never re-records');
check(f.admin.includes('ENABLE STREAMING PHONE')&&f.admin.includes('disable_stream')&&f.admin.includes('csrf_field()'), 'explicit admin opt-in and disable');
check(f.assignment.includes('DELETE FROM phone_voice_stream_routes_v150')&&f.turn.includes('DELETE FROM phone_voice_stream_routes_v150'), 'reassignment and mode changes revoke bridge');
check(!f.stream.includes('agent_brain_archive')&&!f.stream.includes('vp3_agent_chat_send_v2160'), 'Cloud does not grant owner chat or private memory');
console.log('PHONE_VOICE_PH3G_CONTRACT=PASS ('+checks+' checks)');
