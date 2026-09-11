import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=path=>fs.readFileSync(new URL(`../${path}`,import.meta.url),'utf8');
const core=[read('includes/agent-paid-appointments-v800.php'),...Array.from({length:5},(_,i)=>read(`includes/agent-paid-appointments-v800-part${i+1}.php`))].join('\n');
const personal=[read('public-booking.php'),read('public-booking-controller-v700.php'),read('public-booking-view-v700.php')].join('\n');
const team=[read('team-book.php'),read('includes/team-book-controller-v700.php'),read('includes/team-book-view-v700.php')].join('\n');
const payments=read('appointment-payments.php');
const checkout=read('appointment-payment.php');
const paymentReturn=read('appointment-payment-return.php');
const webhook=read('appointment-payment-webhook.php');
const oauth=read('appointment-payment-oauth.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const nav=read('includes/member-navigation.php');
const cron=read('cron/paid-appointments-v800.php');
const config=read('config-example.php');
const legacyTools=read('includes/agent-scheduling-tools-v460.php');

assert.match(core,/VP3_AGENT_PAID_APPOINTMENTS_V800/,'Phase 8 must expose a versioned runtime');
for(const table of ['agent_appointment_payment_connections_v800','agent_team_payment_provider_v800','agent_paid_event_types_v800','agent_paid_team_pools_v800','agent_paid_bookings_v800','agent_paid_checkout_attempts_v800','agent_paid_refunds_v800','agent_paid_webhook_events_v800','agent_paid_audit_v800']){
  assert.ok(core.includes(`CREATE TABLE IF NOT EXISTS ${table}`),`${table} must be canonical Phase 8 storage`);
}
assert.match(core,/UNIQUE KEY uq_paid_booking \(booking_id\)/,'Each canonical booking may have only one commercial record');
assert.match(core,/UNIQUE KEY uq_paid_team_booking \(team_booking_id\)/,'Each Team booking may have only one commercial record');
assert.match(core,/FOREIGN KEY \(booking_id\) REFERENCES agent_scheduling_bookings\(id\) ON DELETE RESTRICT/,'Commercial bookings must retain canonical booking lineage');
assert.match(core,/provider_snapshot/,'Commercial booking must snapshot its provider');
assert.match(core,/external_account_snapshot/,'Commercial booking must snapshot merchant routing');
assert.match(core,/terms_snapshot_json/,'Commercial booking must preserve accepted terms');

assert.match(config,/'billing'\s*=>\s*\[[\s\S]*'provider'\s*=>\s*'stripe'/,'VP3 system billing must remain Stripe-only');
assert.match(config,/'appointment_payments'\s*=>\s*\[/,'Appointment commerce must use a separate configuration namespace');
assert.match(config,/VP3_APPOINTMENT_PAYMENTS_ENCRYPTION_KEY/,'Appointment provider tokens must have a dedicated encryption secret');
assert.doesNotMatch(core,/billing_stripe_secret_key\(/,'Appointment commerce must not reuse the subscription billing Stripe runtime');
assert.doesNotMatch(core,/billing_customers|billing_subscriptions|billing_checkout_sessions/,'Appointment commerce must not write subscription billing records');

assert.match(core,/function agent_paid_appointments_is_team_super_admin_v800/,'Team payment authority must be explicit');
const superAdminStart=core.indexOf('function agent_paid_appointments_is_team_super_admin_v800');
const superAdminEnd=core.indexOf('function agent_paid_appointments_connection_v800',superAdminStart);
const superAdmin=core.slice(superAdminStart,superAdminEnd);
assert.match(superAdmin,/\(int\)\(\$actor\['id'\]\?\?0\)===\$workspaceOwnerId/,'Only the canonical Team workspace owner may act as Team Super Admin');
assert.doesNotMatch(superAdmin,/user_has_role|manager|producer/,'Manager, Producer and global role shortcuts must not select Team payment routing');
assert.match(core,/PRIMARY KEY\s*\(workspace_owner_user_id\)/,'A Team must have only one primary appointment provider selection');
assert.match(core,/team_primary_connection_id/,'Team commercial terms must snapshot the selected primary provider');
assert.match(team,/agent_paid_appointments_create_team_v800/,'Public Team booking must create one Team-level commercial record');
assert.doesNotMatch(core,/INSERT INTO agent_paid_bookings_v800[\s\S]{0,600}foreach\(\$members/,'Team participant iteration must not create multiple customer charges');

for(const provider of ['stripe','square','paypal'])assert.ok(core.includes(`'${provider}'`),`${provider} must be a supported appointment provider`);
assert.match(core,/connect\.stripe\.com\/oauth\/authorize/,'Stripe appointment accounts must use Stripe Connect');
assert.match(core,/Stripe-Account:/,'Stripe charges must be routed to the connected merchant account');
assert.match(core,/main_location_id/,'Square connection must resolve the merchant payment location instead of treating merchant_id as location_id');
assert.match(core,/location_id.*agent_paid_appointments_capabilities_v800/s,'Square checkout must use the verified seller location');
assert.match(core,/merchant-integrations/,'PayPal merchants must be verified through partner merchant integration');
assert.match(core,/payments_receivable/,'PayPal merchant eligibility must be checked before enabling checkout');
assert.match(core,/Idempotency-Key|PayPal-Request-Id|idempotency_key/,'Provider writes must use idempotency keys');

assert.match(core,/payment_mode.*free.*full.*deposit/s,'Commercial terms must support free, full-payment and deposit appointments');
assert.match(core,/amount_total_cents/,'Amounts must be stored in integer minor units');
assert.match(core,/amount_due_cents/,'Required checkout amount must be separate from total appointment price');
assert.match(core,/function agent_paid_appointments_decimal_to_minor_v800/,'Member-entered money must be parsed without floating-point arithmetic');
assert.doesNotMatch(payments,/\(float\).*\*100/,'Commercial UI must not convert money through binary floating point');
assert.match(core,/cancellation_fee_cents/,'Cancellation terms must support an explicit fee');
assert.match(core,/refund_before_hours/,'Refund terms must support a cutoff window');
assert.match(core,/cancellation_policy/,'Cancellation terms must retain user-facing policy text');
assert.match(core,/hold_expires_at/,'Paid bookings must have an expiring slot hold');
assert.match(core,/max\(30,min\(120/,'Payment holds must reserve a practical minimum checkout window');
assert.match(core,/payment_hold_expired/,'Expired holds must be audited and release the booking');
assert.match(cron,/PHP_SAPI!=='cli'/,'Paid appointment housekeeping must expose a CLI-only runner');
assert.match(cron,/agent_paid_appointments_housekeeping_v800\(\$pdo,500\)/,'CLI runner must expire holds and reconcile cancellations');

assert.match(personal,/agent_paid_appointments_create_personal_v800/,'Personal booking flow must create commercial state before confirmation');
const personalPaid=personal.indexOf('if ($paid)');
const personalConfirmed=personal.indexOf("agent_appointment_lifecycle_event_v700($pdo, $booking, 'confirmed'");
assert.ok(personalPaid>=0&&personalConfirmed>personalPaid,'Personal confirmation automation must occur only after the paid-branch redirect');
assert.match(core,/agent_paid_appointments_mark_canonical_pending_v800/,'Paid bookings must enter pending lifecycle state while checkout is unresolved');
assert.match(core,/agent_paid_appointments_confirmation_after_payment_v800/,'Payment completion must be the boundary that returns bookings to confirmed');
assert.match(core,/agent_appointment_lifecycle_queue_booking_v700/,'Verified payment must start Phase 7 confirmation/reminder automation');
assert.match(personal,/Complete or cancel the pending appointment payment before rescheduling/,'Unpaid personal holds must not become confirmed through rescheduling');
assert.match(personal,/payment record/,'Public reschedule UI must explain retained commercial lineage');
assert.match(personal,/agent_paid_appointments_record_reschedule_v800/,'Personal reschedule must retain the existing commercial record');
assert.match(team,/agent_paid_appointments_record_cancellation_v800/,'Team cancellation must preserve refund eligibility and audit state');

assert.match(checkout,/verify_csrf\(\)/,'Private checkout creation must remain CSRF protected');
assert.match(checkout,/agent_scheduling_public_rate_limit_v450/,'Private checkout creation must be rate limited');
assert.match(checkout,/agent_paid_appointments_public_by_token_v800/,'Checkout must require the private booking token rather than a numeric paid-booking id alone');
assert.match(checkout,/Cache-Control: no-store, private/,'Private payment page must not be cached');
assert.match(paymentReturn,/agent_paid_appointments_public_by_token_v800/,'Provider return must re-bind to the private booking token');
assert.match(paymentReturn,/\(int\)\$paid\['id'\]!==\$paidId/,'Provider return must verify numeric id belongs to the private token');
assert.match(paymentReturn,/Cache-Control: no-store, private/,'Payment return must not be cached');
assert.match(paymentReturn,/user_profiles/,'Personal payment return must resolve the canonical profile table');
assert.doesNotMatch(paymentReturn,/FROM profiles/,'Payment return must not query the retired profile table name');

for(const verifier of ['agent_paid_appointments_stripe_verify_v800','agent_paid_appointments_square_verify_v800','agent_paid_appointments_paypal_verify_v800'])assert.ok(core.includes(verifier),`${verifier} must verify provider callbacks`);
assert.match(webhook,/agent_paid_appointments_process_webhook_v800/,'Public webhook endpoint must use the canonical verified processor');
assert.match(core,/uq_paid_webhook_event/,'Webhook processing must be idempotent by provider event id');
assert.match(core,/payment amount is less than the required amount/i,'Provider completion must reject underpayment');
assert.match(core,/Payment currency does not match this appointment/,'Provider completion must reject currency mismatch');
assert.match(core,/Payment checkout lineage could not be verified/,'Provider completion must prove checkout lineage before confirming');

assert.match(core,/Explicit refund approval is required/,'External refund movement must require explicit owner approval');
assert.match(core,/Only the appointment account owner can approve a refund/,'Refund authority must remain with the account\/Team owner');
assert.match(payments,/name="confirm_refund" value="1"/,'Member commerce UI must send an explicit refund approval signal');
assert.match(core,/agent_paid_refunds_v800/,'Refunds must have canonical provider lineage');
assert.match(core,/refund_completed|refund_requested/,'Refund activity must be audited');
assert.match(core,/agent_paid_appointments_refundable_cents_v800/,'Refund amount must be bounded by accepted cancellation terms');
assert.doesNotMatch(core,/refund_application_fee/,'Partial customer refunds must not silently refund the entire Stripe application fee');

assert.match(payments,/VP3 subscription billing remains Stripe-only and separate/,'Member UI must clearly separate platform billing from appointment commerce');
assert.match(payments,/Connected providers/,'Members must be able to manage multiple appointment payment providers');
assert.match(payments,/Team primary provider/,'Workspace owner must have a visible Team primary-provider control');
assert.match(payments,/Only that account can select or change/,'Team authority must be clear in the UI');
assert.match(payments,/Payment activity/,'Member workspace must expose appointment commerce history');
assert.match(oauth,/appointment-payments\.php/,'Provider OAuth must return to the canonical appointment commerce workspace');

const teamIndex=bootstrap.indexOf("agent-team-scheduling-tools-v610.php");
const lifeIndex=bootstrap.indexOf("agent-appointment-lifecycle-v700.php");
const paidIndex=bootstrap.indexOf("agent-paid-appointments-v800.php");
assert.ok(teamIndex>=0&&lifeIndex>teamIndex&&paidIndex>lifeIndex,'Phase 8 must load after Team Scheduling and Appointment Lifecycle');
assert.match(bootstrap,/agent_paid_appointments_housekeeping_maybe_v800\(\)/,'Bootstrap must perform lightweight paid-booking housekeeping');
assert.match(upgrade,/agent_paid_appointments_schema_ready_v800\(\)/,'Upgrade completeness must include Phase 8');
assert.match(upgrade,/agent_paid_appointments_ensure_schema_v800\(\)/,'Canonical upgrade must install Phase 8');
assert.match(nav,/'appointment_payments','Appointment Payments',url\('\/appointment-payments\.php'\),'agent'/,'Paid Appointments must be reachable from canonical member navigation');

const legacyStart=legacyTools.indexOf('function agent_scheduling_tools_reschedule_owner_v460');
const legacyEnd=legacyTools.indexOf('function agent_scheduling_tools_execute_pending_v460',legacyStart);
const legacyReschedule=legacyTools.slice(legacyStart,legacyEnd);
assert.ok(legacyReschedule,'Agent Chat reschedule implementation must exist');
assert.match(legacyReschedule,/agent_appointment_lifecycle_reschedule_v700/,'Approved Agent Chat reschedule must delegate to the canonical in-place Phase 7 path');
assert.doesNotMatch(legacyReschedule,/agent_scheduling_create_booking_v430/,'Agent Chat reschedule must not create a replacement booking that severs Phase 8 payment lineage');
assert.match(legacyReschedule,/agent_paid_appointments_paid_booking_for_booking_v800/,'Agent Chat reschedule must inspect commercial state before mutation');
assert.match(legacyReschedule,/Complete or cancel the pending appointment payment before rescheduling/,'Agent Chat must not confirm an unpaid hold through rescheduling');
assert.match(legacyReschedule,/agent_paid_appointments_record_reschedule_v800/,'Paid Agent Chat reschedule must audit retained commercial lineage');

console.log('AGENT_PAID_APPOINTMENTS_V800=PASS');
