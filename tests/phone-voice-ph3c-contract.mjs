import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=path=>fs.readFileSync(path,'utf8');
let checks=0;function check(name,test){assert.ok(test,name);console.log('PASS '+name);checks++;}
const voice=read('includes/phone-voice-v130.php');
const inbound=read('api/phone-voice-inbound-v130.php');
const complete=read('api/phone-voice-complete-v130.php');
const recording=read('api/phone-voice-recording-v130.php');
const playback=read('api/phone-voice-audio-v130.php');
const page=read('phone-calls.php');
const admin=read('admin/phone.php');
const upgrade=read('upgrade.php');
check('PH3C install and readiness in upgrade',
  upgrade.includes('vp3_phone_voice_schema_ready_v130()')
  && upgrade.includes('vp3_phone_voice_ensure_schema_v130($pdo)'));
check('voice entitlement and named agent gate',
  voice.includes("['voice']")&&voice.includes('vp3_phone_agent_owned_v100')
  &&voice.includes('voice_enabled'));
check('signed Twilio webhook with account binding and idempotent Call SID',
  inbound.includes('HTTP_X_TWILIO_SIGNATURE')&&voice.includes('vp3_phone_voice_check_event_v130')
  &&voice.includes('hash_equals')&&voice.includes('uq_phone_voice_provider_call'));
check('voicemail avoids generative responses and has Twilio callback chain',
  voice.includes('automated answering service')&&voice.includes('<Record')
  &&complete.includes('<Hangup/>')&&recording.includes('HTTP_X_TWILIO_SIGNATURE'));
check('provider callback URLs exact HTTPS and derived from saved public endpoint',
  voice.includes("!=='https'")&&voice.includes('phone-voice-inbound-v130.php')
  &&voice.includes('vp3_phone_voice_sibling_url_v130'));
check('recording metadata idempotent and account scoped, no provider URL persisted',
  voice.includes('uq_phone_voice_recording')&&voice.includes('voicemail_ready')
  &&!voice.includes('RecordingUrl')&&voice.includes('owner_user_id'));
check('member-only playback, server-held Twilio credential and bounded download',
  playback.includes("require_permission('account.access')")&&playback.includes("has_permission('chat.access'")
  &&playback.includes('owner_user_id=?')&&playback.includes('vp3_phone_twilio_credentials_v100')
  &&playback.includes('maxBytes')&&playback.includes('CURLOPT_PROTOCOLS'));
check('owner call UI and admin per-number activation are wired',
  page.includes('vp3_phone_voice_calls_v130')&&page.includes('phone-voice-audio-v130.php')
  &&admin.includes('activate_incoming_voice')&&admin.includes('save_voice_callback')
  &&admin.includes('disable_incoming_voice'));
check('no live calls enabled on installation, no outgoing call automation',
  voice.includes('voice_enabled=1')&&admin.includes('activate_incoming_voice')
  &&!voice.includes('Calls.json')&&!voice.includes('ai_chat'));
console.log('PHONE_VOICE_PH3C_CONTRACT=PASS ('+checks+' checks)');
