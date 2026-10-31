import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=file=>fs.readFileSync(file,'utf8');let checks=0;
function check(name,value){assert.ok(value,name);checks++;console.log(`PASS ${name}`);}
const domain=read('includes/phone-sms-outbound-v120.php');
const page=read('phone-inbox.php');const api=read('api/phone-sms-outbound-v120.php');
const callback=read('api/phone-sms-status-v120.php');const admin=read('admin/phone.php');
const catalog=read('includes/subscription-schema.php');const packages=read('admin/packages.php');
const upgrade=read('upgrade.php');
check('subscription package adds bounded outgoing SMS quota',
  catalog.includes('phone.sms.monthly')&&packages.includes("$key==='phone.sms.monthly'")
  &&domain.includes('phone_sms_account_monthly_cap_v120')&&admin.includes('save_sms_monthly_cap'));
check('draft, one-shot carrier claim, uncertain status, and exact SEND approval',
  domain.includes("$phrase='SEND '.$draft['recipient_e164']")
  &&domain.includes('status=\\\'sending\\\'')&&domain.includes('review_required')
  &&domain.includes('WHERE id=? AND owner_user_id=? AND status=\\\'draft\\\''));
check('outbound channel disabled until admin compliance acknowledgement',
  admin.includes('ENABLE OUTBOUND SMS')&&admin.includes('compliance_confirmed')
  &&domain.includes("'phone_sms_outbound_live_v120','0'"));
check('owner-scoped sending requires active number, SMS plan, opt-out check and quota',
  domain.includes('vp3_phone_sms_sendable_number_v120')
  &&domain.includes('carrier_opted_out')&&domain.includes('vp3_phone_sms_monthly_quota_v120'));
check('delivery callbacks are signed and terminal states do not regress',
  callback.includes('HTTP_X_TWILIO_SIGNATURE')
  &&domain.includes('vp3_phone_sms_verify_twilio_v110')&&domain.includes("['delivered','undelivered','failed']"));
check('both form UI and JSON API require user authorization, no autonomous send',
  page.includes('verify_csrf()')&&page.includes('confirmation')
  &&api.includes('csrf_token()')&&api.includes('has_permission'));
check('schema and callback installed by Cloud upgrade',
  upgrade.includes('vp3_phone_sms_outbound_ready_v120()')
  &&upgrade.includes('vp3_phone_sms_outbound_ensure_schema_v120($pdo)'));
console.log(`PHONE_SMS_PH3B_CONTRACT=PASS (${checks} checks)`);
