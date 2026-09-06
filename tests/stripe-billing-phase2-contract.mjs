import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const loader = read('includes/subscriptions.php');
const schema = read('includes/billing-schema.php');
const stripe = read('includes/billing-stripe.php');
const runtime = read('includes/billing-runtime.php');
const webhook = read('billing-webhook.php');
const page = read('subscription.php');
const admin = read('admin/billing.php');
const adminHeader = read('admin/_header.php');
const config = read('config-example.php');
const upgrade = read('upgrade.php');
const sql = read('sql/billing-phase2.sql');

for (const module of ['billing-schema.php','billing-stripe.php','billing-runtime.php']) {
  assert.ok(loader.includes(module), `canonical subscription loader must include ${module}`);
}
for (const table of ['package_billing_prices','billing_customers','billing_subscriptions','billing_checkout_sessions','billing_webhook_events','billing_portal_configs']) {
  assert.ok(schema.includes(`CREATE TABLE IF NOT EXISTS ${table}`), `runtime schema must create ${table}`);
  assert.ok(sql.includes(`CREATE TABLE IF NOT EXISTS ${table}`), `manual SQL must create ${table}`);
}
assert.ok(upgrade.includes('billing_schema_ready()') && upgrade.includes('billing_ensure_schema();'), 'database upgrade must install and verify Phase 2 billing storage');

assert.ok(schema.includes('id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY') && schema.includes('idx_billing_prices_active'), 'Stripe price mappings must support historical rows instead of one mutable row per package interval');
assert.ok(!schema.includes('PRIMARY KEY (package_id,provider,billing_interval)'), 'package/interval must not be the Stripe price primary key');
assert.ok(sql.includes('idx_billing_prices_active') && !sql.includes('PRIMARY KEY (package_id,provider,billing_interval)'), 'manual SQL must preserve historical Stripe Prices too');

assert.ok(config.includes("'billing' =>") && config.includes("'provider' => 'stripe'"), 'config example must expose a Stripe billing section');
assert.ok(config.includes('STRIPE_SECRET_KEY') && config.includes('STRIPE_WEBHOOK_SECRET'), 'billing secrets must support environment variables');
assert.ok(!config.includes('sk_live_') && !config.includes('whsec_'), 'example config must never contain real-looking billing secrets');

assert.ok(stripe.includes('https://api.stripe.com/v1/'), 'Stripe adapter must call Stripe server-side over HTTPS');
assert.ok(stripe.includes('Authorization: Bearer '), 'Stripe adapter must authenticate server-side');
assert.ok(stripe.includes('Idempotency-Key:'), 'Stripe POST operations must support idempotency keys');
assert.ok(stripe.includes('CURLOPT_CONNECTTIMEOUT') && stripe.includes('CURLOPT_TIMEOUT'), 'Stripe calls must have bounded network timeouts');
assert.ok(stripe.includes("hash_hmac('sha256',$timestamp.'.'.$payload,$secret)"), 'webhook signature must cover Stripe timestamp plus exact raw body');
assert.ok(stripe.includes('hash_equals($expected,$signature)'), 'webhook signature comparison must be constant-time');
assert.ok(stripe.includes('abs(time()-$timestamp)>'), 'webhook signatures must enforce a replay tolerance');

assert.ok(stripe.includes("AND is_active=1 ORDER BY id DESC LIMIT 1"), 'new Checkout must resolve only the active package Price');
assert.ok(stripe.includes('billing_price_mapping_latest'), 'price sync must be able to reuse the existing Stripe Product after a price change');
assert.ok(stripe.includes("UPDATE package_billing_prices SET is_active=0"), 'new package pricing must deactivate—not overwrite—previous Stripe Price mappings');
assert.ok(stripe.includes("WHERE bp.provider='stripe' AND bp.provider_price_id=? LIMIT 1"), 'reconciliation must resolve historical Stripe Price IDs without an active-only filter');
assert.ok(stripe.includes("ON DUPLICATE KEY UPDATE package_id=VALUES(package_id)"), 'reverting to historical commercial terms must be able to reactivate the existing immutable Stripe Price');

assert.ok(stripe.includes("'mode'=>'subscription'"), 'first paid activation must use Stripe Checkout subscription mode');
assert.ok(stripe.includes("'line_items'=>[['price'=>(string)$price['provider_price_id']"), 'Checkout price must come from a server-side package mapping');
assert.ok(stripe.includes('billing_stripe_ensure_package_price'), 'VP3 package prices must be synchronized to Stripe server-side');
assert.ok(stripe.includes("'recurring'=>['interval'=>$stripeInterval]"), 'Stripe prices must be recurring monthly/annual prices');
assert.ok(stripe.includes("'vp3_plan_request_id'"), 'Checkout and Stripe subscriptions must carry reconciliation metadata');
assert.ok(stripe.includes("'subscription_update_confirm'"), 'existing paid plan changes must use Stripe hosted update confirmation');
assert.ok(stripe.includes("'payment_method_update'=>['enabled'=>true]"), 'billing portal must support payment-method management');
assert.ok(stripe.includes("'subscription_cancel'=>['enabled'=>true,'mode'=>'at_period_end'"), 'billing portal cancellation must preserve paid access through period end');
assert.ok(stripe.includes("checkout/sessions/'.rawurlencode($sessionId).'/expire"), 'stale Checkout sessions must be explicitly expired at Stripe');
assert.ok(stripe.includes("VALUES (?,?,?,'stripe','checkout',?,?,?,?,'open',?,?,?)"), 'Checkout persistence placeholder count must match its ten bound values');
assert.ok(stripe.includes('billing_stripe_checkout_attempt_state'), 'Checkout resume must inspect prior provider sessions');
assert.ok(stripe.includes("if($status==='open'"), 'an existing open Checkout must be reused instead of duplicated');
assert.ok(stripe.includes(".'-'.$attempt"), 'an expired Checkout must advance the idempotency attempt for a fresh Session');

assert.ok(runtime.includes("in_array($status,['active','trialing'],true)"), 'only active/trialing provider subscriptions can activate entitlements');
assert.ok(runtime.includes("$pending=$subscription['pending_update']??null"), 'Stripe pending updates must not activate a target package early');
assert.ok(runtime.includes('billing_price_mapping_by_provider_price'), 'provider Price ID must map back to an Admin-defined package');
assert.ok(runtime.includes('entitlements were not changed'), 'unknown/stale provider state must fail closed');
assert.ok(runtime.includes("assignment_source='stripe'"), 'paid local subscriptions must be explicitly provider-owned');
assert.ok(runtime.includes('billing_fallback'), 'ended Stripe subscriptions must use a controlled free-package fallback when configured');
assert.ok(runtime.indexOf("billing_stripe_request('POST','subscriptions/'") < runtime.indexOf('billing_reconcile_stripe_subscription($provider,$pdo)'), 'provider cancellation must occur before local cancellation reconciliation');
assert.ok(runtime.includes('billing_expire_request_checkout') && runtime.includes('billing_expire_superseded_checkouts'), 'cancelled/superseded plan requests must expire open Stripe Checkout sessions');
assert.match(runtime, /function billing_cancel_request[\s\S]*billing_expire_request_checkout[\s\S]*subscription_self_service_cancel_request/, 'pending checkout must expire before its VP3 request is cancelled');
assert.ok(runtime.includes("in_array((string)$candidate['status'],['pending_billing','applied'],true)"), 'superseded/cancelled requests must not authorize entitlement activation');

assert.ok(webhook.includes("file_get_contents('php://input')"), 'webhook must verify the exact raw request body');
assert.ok(webhook.includes("$_SERVER['HTTP_STRIPE_SIGNATURE']"), 'webhook must read Stripe-Signature');
assert.ok(webhook.indexOf('billing_stripe_verify_webhook') < webhook.indexOf('json_decode($payload,true)'), 'signature must be verified before trusting webhook JSON');
assert.ok(!webhook.includes('verify_csrf()'), 'Stripe webhook must not depend on browser CSRF tokens');
assert.ok(runtime.includes("$status==='failed'||$stale"), 'failed/stale webhook work must be reclaimable for Stripe retries');
assert.ok(runtime.includes("status='processing',error_message='',processed_at=NULL"), 'reclaimed webhook work must reset processing state atomically');
assert.ok(runtime.includes("hash_equals((string)$existing['payload_sha256'],$hash)"), 'duplicate webhook IDs must match the original payload before retry');

assert.ok(page.includes("$billingReady=billing_stripe_configured()&&billing_stripe_webhook_secret()!==''"), 'paid checkout must require both Stripe API and webhook configuration');
assert.ok(page.includes("$action==='resume_checkout'"), 'users must be able to resume an incomplete Checkout/plan-change flow');
assert.ok(page.includes("$action==='manage_billing'"), 'users with Stripe billing must have a billing portal action');
assert.ok(page.includes('billing_begin_paid_flow'), 'paid selections must enter the provider flow after the durable Phase 1 request');
assert.ok(page.includes('billing_cancel_request'), 'undo/cancel requests must route through provider-aware cancellation logic');

assert.ok(admin.includes('Sync Stripe Catalog') && admin.includes('Test Stripe Connection'), 'Admin needs billing connection and catalog controls');
assert.ok(admin.includes('Webhook Events') && admin.includes('Billing Subscriptions'), 'Admin needs provider reconciliation visibility');
assert.ok(admin.includes("billing_stripe_secret_key()!==''?'Configured':'Missing'"), 'Admin may expose only whether the Stripe secret is configured');
assert.ok(admin.includes("billing_stripe_webhook_secret()!==''?'Configured':'Missing'"), 'Admin may expose only whether the webhook secret is configured');
assert.ok(!admin.includes('e(billing_stripe_secret_key())') && !admin.includes('e(billing_stripe_webhook_secret())'), 'Admin UI must never render Stripe secret values');
assert.ok(adminHeader.includes("url('/admin/packages.php')") && adminHeader.includes('<span>Packages</span>'), 'Admin sidebar must expose Packages');
assert.ok(adminHeader.includes("url('/admin/billing.php')") && adminHeader.includes('<span>Billing</span>'), 'Admin sidebar must expose Billing');
assert.ok(adminHeader.includes("has_permission('users.manage')"), 'Monetization navigation must remain behind users.manage authority');

console.log('STRIPE_BILLING_PHASE2_CONTRACT=PASS');