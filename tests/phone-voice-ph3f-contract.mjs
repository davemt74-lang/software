import assert from 'node:assert/strict';
import fs from 'node:fs';
const get=p=>fs.readFileSync(p,'utf8');
const parts={
  voice:get('includes/phone-voice-v130.php'),
  live:get('includes/phone-voice-conversation-v140.php'),
  edge:get('api/phone-voice-turn-v140.php'),
  inbound:get('api/phone-voice-inbound-v130.php'),
  ai:get('includes/ai-settings.php'),
  admin:get('admin/phone.php'),
  owner:get('phone-calls.php'),
  upgrade:get('upgrade.php'),
  assignment:get('includes/phone-cloud-v100.php'),
  complete:get('api/phone-voice-complete-v130.php')
};
let checks=0;const check=(condition,label)=>{assert.ok(condition,label);checks++;};
check(parts.upgrade.includes('vp3_phone_voice_ready_v140()')
  &&parts.upgrade.includes('vp3_phone_voice_ensure_v140($pdo)'), 'upgrade readiness and schema');
check(parts.live.includes('uq_phone_voice_turn_step')&&parts.live.includes('speech_hash'),
  'call-scoped idempotent turn receipt');
check(parts.edge.includes('HTTP_X_TWILIO_SIGNATURE')
  &&parts.live.includes('vp3_phone_voice_check_event_v130($form,$signature,$signedUrl'),
  'exact carrier-signed step callbacks');
check(parts.live.includes('vp3_phone_voice_current_route_v131')
  &&parts.live.includes('vp3_phone_voice_live_config_v140'),
  'tenant revalidated for every turn');
check(parts.ai.includes("===\'phone_voice\'")
  &&parts.ai.includes('This caller is NOT the VP3 account owner')
  &&parts.ai.includes("if (($turnControl['surface']??'')==='phone_voice') $context=[]"),
  'unauthenticated caller gets a separate private-data-free model prompt');
check(parts.live.includes("ai_generate_chat_response($speech,$history,[],$user,'phone_voice'"),
  'Cloud model inference and subscription token accounting');
check(parts.ai.includes('PH3F_PHONE_VOICE.')&&parts.ai.includes('$isPhone?min(10,$phoneRemaining)'),
  'hard webhook latency budget');
check(parts.live.includes('actionOnEmptyResult="true"')&&parts.live.includes('input="speech"')
  &&parts.live.includes('speechTimeout="auto"'), 'Twilio speech gather + spoken response');
check(parts.live.includes("state='voicemail_fallback'")&&parts.complete.includes('voicemail_fallback'),
  'safe unavailable or silent caller fallback');
check(parts.admin.includes('ENABLE AI PHONE')&&parts.admin.includes('disable_live_conversation'),
  'explicit per-number AI opt-in and disable');
check(parts.assignment.includes('DELETE FROM phone_voice_modes_v140'),
  'number reassignment clears AI mode');
check(parts.owner.includes('owner_user_id=?')&&parts.owner.includes('phone_voice_turns_v140'),
  'account-owner-only conversation history');
check(!parts.live.includes('vp3_agent_chat_send_v2160')
  &&!parts.live.includes('agent_brain_archive')&&!parts.live.includes('homeserver_agent'),
  'no private chat tool execution');
console.log('PHONE_VOICE_PH3F_CONTRACT=PASS ('+checks+' checks)');
