import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=path=>fs.readFileSync(path,'utf8');let checks=0;
function check(name,truth){assert.ok(truth,name);checks++;console.log(`PASS ${name}`);}
const domain=read('includes/phone-sms-v110.php');
const inbound=read('api/phone-sms-inbound-v110.php');
const api=read('api/phone-sms-v110.php');
const inbox=read('phone-inbox.php');
const admin=read('admin/phone.php');
const upgrade=read('upgrade.php');
check('provider-independent thread/message schema and unique SID replay protection',
  domain.includes('phone_sms_threads_v110')&&domain.includes('phone_sms_messages_v110')
  &&domain.includes('uq_phone_sms_provider_message')&&domain.includes('provider VARCHAR(24)'));
check('signed HTTPS Twilio ingress, POST only, no CSRF trust or caller ID authentication',
  inbound.includes("HTTP_X_TWILIO_SIGNATURE")&&inbound.includes("'POST'")
  &&inbound.includes("application/x-www-form-urlencoded")&&domain.includes('hash_equals($expected,$signature)'));
check('ingress routes by purchased owned number, active agent, and SMS package',
  domain.includes("provider=\\'twilio\\' AND phone_e164=?")
  &&domain.includes('vp3_phone_agent_owned_v100')&&domain.includes("!$package['sms']"));
check('inbound text is never automatically sent to an LLM',
  !domain.includes('anthropic')&&!domain.includes('openai')&&!domain.includes('ai_chat')
  &&inbound.includes('No incoming content')===false);
check('member inbox and API are account-scoped and protect mark read with CSRF',
  inbox.includes('vp3_phone_sms_messages_v110($pdo,$ownerId')
  &&api.includes('verify')===false&&api.includes('csrf_token()')
  &&api.includes('has_permission'));
check('admin callback activation configures Twilio and starts disabled',
  domain.includes("'SmsUrl'=>$callback")&&admin.includes('activate_incoming_sms')
  &&admin.includes('disable_incoming_sms')&&admin.includes('Outbound: disabled'));
check('STOP/START tracking and bounded payload storage',
  domain.includes("'STOP'")&&domain.includes("'START'")&&domain.includes('strlen($body)>6400'));
check('upgrade initializes inbox after phone inventory',
  upgrade.includes('vp3_phone_sms_schema_ready_v110()')
  &&upgrade.indexOf('vp3_phone_sms_ensure_schema_v110($pdo)')>upgrade.indexOf('vp3_phone_ensure_schema_v100($pdo)'));
console.log(`PHONE_SMS_PH3A_CONTRACT=PASS (${checks} checks)`);
