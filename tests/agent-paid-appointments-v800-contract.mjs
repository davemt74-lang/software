import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
const commerce=[read('includes/agent-commerce-v800.php'),...Array.from({length:4},(_,i)=>read(`includes/agent-commerce-v800-part${i+1}.php`))].join('\n');
const adapter=[read('includes/agent-paid-appointments-v800.php'),read('includes/agent-paid-appointments-v800-part1.php'),read('includes/agent-paid-appointments-v800-part2.php')].join('\n');
const personal=[read('public-booking.php'),read('public-booking-controller-v700.php'),read('public-booking-view-v700.php')].join('\n');
const team=[read('team-book.php'),read('includes/team-book-controller-v700.php'),read('includes/team-book-view-v700.php')].join('\n');
const checkout=read('appointment-payment.php');
const paymentReturn=read('appointment-payment-return.php');
const legacyTools=read('includes/agent-scheduling-tools-v460.php');

assert.match(adapter,/VP3_AGENT_PAID_APPOINTMENTS_V800/,'Appointment fulfillment adapter must remain versioned');
assert.match(adapter,/require_once __DIR__\.'\/agent-commerce-v800\.php'/,'Appointment adapter must sit on canonical Commerce');
assert.doesNotMatch(adapter,/CREATE TABLE|ALTER TABLE|DROP TABLE/i,'Appointment adapter must not own a parallel payment schema');
assert.match(adapter,/appointment_event_type/,'Personal appointment pricing must create/bind Commerce products');
assert.match(adapter,/team_scheduling_pool/,'Team appointment pricing must create/bind Commerce products');
assert.match(adapter,/'product_type'=>'service'/,'Appointments are service products');
assert.match(adapter,/'fulfillment_type'=>'appointment'/,'Appointment is a fulfillment type');
assert.match(adapter,/function agent_commerce_fulfillment_appointment_paid_v800/,'Appointment must implement paid fulfillment hook');
assert.match(adapter,/function agent_commerce_fulfillment_appointment_expired_v800/,'Appointment must implement hold-expiry fulfillment hook');
assert.match(adapter,/agent_commerce_create_order_v800/,'Paid appointments must create canonical Commerce orders');
assert.match(adapter,/agent_commerce_refund_v800/,'Appointment cancellation policy must bound generic Commerce refunds');

assert.match(personal,/agent_paid_appointments_create_personal_v800/,'Personal booking must create Commerce-backed appointment order before confirmation');
const paidBranch=personal.indexOf('if ($paid)');
const confirmed=personal.indexOf("agent_appointment_lifecycle_event_v700($pdo, $booking, 'confirmed'");
assert.ok(paidBranch>=0&&confirmed>paidBranch,'Paid personal booking must redirect before Phase 7 confirmation automation');
assert.match(personal,/Complete or cancel the pending appointment payment before rescheduling/,'Unpaid holds cannot reschedule into confirmed state');
assert.match(personal,/agent_paid_appointments_record_reschedule_v800/,'Reschedule must retain order lineage');
assert.match(team,/agent_paid_appointments_create_team_v800/,'Team booking must create one Commerce order');
assert.match(team,/agent_paid_appointments_record_cancellation_v800/,'Team cancellation must preserve refund eligibility');

assert.match(checkout,/agent_paid_appointments_public_by_token_v800/,'Appointment payment remains private-token scoped');
assert.match(checkout,/verify_csrf\(\)/,'Appointment checkout creation requires CSRF');
assert.match(checkout,/partially_paid/,'Deposit-paid appointment page must expose remaining balance collection');
assert.match(paymentReturn,/agent_paid_appointments_return_verify_v800/,'Provider return must verify through Commerce-backed adapter');
assert.match(paymentReturn,/\(int\)\$paid\['id'\]!==\$paidId/,'Return must bind numeric order id to private appointment token');

const legacyStart=legacyTools.indexOf('function agent_scheduling_tools_reschedule_owner_v460');
const legacyEnd=legacyTools.indexOf('function agent_scheduling_tools_execute_pending_v460',legacyStart);
const reschedule=legacyTools.slice(legacyStart,legacyEnd);
assert.ok(reschedule,'Agent Chat reschedule helper must exist');
assert.match(reschedule,/agent_appointment_lifecycle_reschedule_v700/,'Agent Chat reschedule must use canonical in-place lifecycle path');
assert.doesNotMatch(reschedule,/agent_scheduling_create_booking_v430/,'Agent Chat reschedule must not create replacement booking');
assert.match(reschedule,/agent_paid_appointments_paid_booking_for_booking_v800/,'Agent Chat reschedule must inspect Commerce-backed appointment state');
assert.match(reschedule,/Complete or cancel the pending appointment payment before rescheduling/,'Agent Chat cannot confirm unpaid hold through reschedule');
assert.match(reschedule,/agent_paid_appointments_record_reschedule_v800/,'Agent Chat reschedule must audit retained Commerce lineage');
assert.match(legacyTools,/Paid appointments need the guest email before I can hold the time and create a secure payment link/,'Agent-created paid appointment requires payer email');
assert.match(legacyTools,/Time held pending payment/,'Agent-created paid appointment must not be described as confirmed');
assert.match(legacyTools,/agent_paid_appointments_create_personal_v800/,'Agent-created paid appointment creates Commerce order through adapter');
assert.match(legacyTools,/agent_paid_appointments_payment_url_v800/,'Agent-created paid appointment returns secure payment path');

assert.match(commerce,/partially_paid/,'Commerce must represent deposit-paid state separately from paid-in-full');
assert.match(commerce,/agent_commerce_payments_v800/,'Appointment provider payments must use generic payment ledger');
assert.doesNotMatch(commerce,/\bbooking_id\b/,'Generic Commerce must stay appointment-independent');
console.log('AGENT_PAID_APPOINTMENTS_V800=PASS');
