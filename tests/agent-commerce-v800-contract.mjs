import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
const core=[read('includes/agent-commerce-v800.php'),...Array.from({length:4},(_,i)=>read(`includes/agent-commerce-v800-part${i+1}.php`))].join('\n');
const adapter=[read('includes/agent-paid-appointments-v800.php'),read('includes/agent-paid-appointments-v800-part1.php'),read('includes/agent-paid-appointments-v800-part2.php')].join('\n');
const config=read('config-example.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const nav=read('includes/member-navigation.php');
const page=read('commerce.php');
const oauth=read('commerce-provider-oauth.php');
const webhook=read('commerce-payment-webhook.php');
const cron=read('cron/commerce-v800.php');

assert.match(core,/VP3_AGENT_COMMERCE_V800/,'Commerce must expose a versioned runtime');
for(const table of ['agent_commerce_provider_connections_v800','agent_commerce_team_provider_v800','agent_commerce_products_v800','agent_commerce_product_bindings_v800','agent_commerce_orders_v800','agent_commerce_order_items_v800','agent_commerce_checkout_attempts_v800','agent_commerce_payments_v800','agent_commerce_refunds_v800','agent_commerce_webhook_events_v800','agent_commerce_audit_v800']){
  assert.ok(core.includes(`CREATE TABLE IF NOT EXISTS ${table}`),`${table} must be canonical Commerce storage`);
}
assert.match(core,/product_type VARCHAR\(32\)/,'Products must have a generic product type');
assert.match(core,/fulfillment_type VARCHAR\(32\)/,'Products and orders must carry fulfillment type');
assert.match(core,/binding_type VARCHAR\(48\)/,'Products must bind to domain resources without becoming those resources');
assert.match(core,/order_number VARCHAR\(40\)/,'Purchases must have canonical order identity');
assert.match(core,/fulfillment_ref_type VARCHAR\(48\)/,'Orders must link fulfillment generically');
assert.doesNotMatch(core,/\bbooking_id\b/,'Generic Commerce core must not depend on appointment booking columns');
assert.doesNotMatch(core,/\bteam_booking_id\b/,'Generic Commerce core must not depend on Team booking columns');
assert.match(core,/agent_commerce_payments_v800/,'Payments must be distinct from checkout attempts and refunds');
assert.match(core,/external_payment_id VARCHAR\(190\) NOT NULL/,'Provider transaction lineage must be canonical');
assert.match(core,/UNIQUE KEY uq_commerce_payment_provider/,'Provider payment callbacks must be idempotent');
assert.match(core,/UNIQUE KEY uq_commerce_fulfillment_ref/,'One canonical fulfillment reference must not silently create duplicate orders');

assert.match(config,/'billing'\s*=>\s*\[[\s\S]*'provider'\s*=>\s*'stripe'/,'VP3 subscription/system billing stays Stripe-only');
assert.match(config,/'commerce'\s*=>\s*\[/,'Customer commerce must have a separate config namespace');
assert.match(config,/VP3_COMMERCE_ENCRYPTION_KEY/,'Commerce provider tokens need a separate encryption secret');
assert.doesNotMatch(core,/billing_stripe_secret_key|billing_customers|billing_subscriptions|billing_checkout_sessions/,'Commerce must never reuse system billing state');

assert.match(core,/function agent_commerce_is_team_super_admin_v800/,'Team provider authority must be explicit');
const superAdmin=core.slice(core.indexOf('function agent_commerce_is_team_super_admin_v800'),core.indexOf('function agent_commerce_connection_v800'));
assert.match(superAdmin,/\(int\)\(\$actor\['id'\]\?\?0\)===\$workspaceOwnerId/,'Canonical Team workspace owner is Team Super Admin');
assert.doesNotMatch(superAdmin,/manager|producer|user_has_role/,'Managers/Producers/global roles cannot override Team payment routing');
assert.match(core,/workspace_owner_user_id INT UNSIGNED NOT NULL PRIMARY KEY/,'A Team has exactly one primary provider row');

for(const provider of ['stripe','square','paypal'])assert.ok(core.includes(`'${provider}'`),`${provider} must be a Commerce provider option`);
assert.match(core,/connect\.stripe\.com\/oauth\/authorize/,'Stripe customer commerce must use Stripe Connect');
assert.match(core,/Stripe-Account:/,'Stripe checkout/refunds must route to the connected merchant account');
assert.match(core,/main_location_id/,'Square must resolve the merchant payment location');
assert.match(core,/Square sandbox does not support application fees for hosted commerce checkout/,'Square sandbox fee limitation must fail clearly');
assert.match(core,/merchant-integrations/,'PayPal merchant integration must be verified');
assert.match(core,/Idempotency-Key|PayPal-Request-Id|idempotency_key/,'Provider writes must be idempotent');

assert.match(core,/function agent_commerce_upsert_bound_product_v800/,'Commerce must have canonical product creation/update');
assert.match(core,/function agent_commerce_create_order_v800/,'Commerce must have canonical orders');
assert.match(core,/function agent_commerce_create_checkout_v800/,'Commerce must have provider-neutral checkout');
assert.match(core,/function agent_commerce_dispatch_fulfillment_v800/,'Commerce must dispatch fulfillment by adapter');
assert.match(core,/agent_commerce_fulfillment_.*_.*_v800/,'Fulfillment dispatch must not hard-code Appointment into Commerce core');
assert.match(core,/partially_paid/,'Deposits must remain distinct from paid-in-full state');
assert.match(core,/balance_due_cents/,'Payment audit must retain remaining-balance state');
assert.match(core,/SELECT id FROM agent_commerce_payments_v800 WHERE provider=\? AND external_payment_id=\?/,'Duplicate provider callbacks must not double-count payments');
assert.match(core,/Explicit refund approval is required/,'External refunds require explicit owner approval');

assert.match(adapter,/appointment_event_type/,'Appointment event types must bind to Commerce products');
assert.match(adapter,/team_scheduling_pool/,'Team pools must bind to Commerce products');
assert.match(adapter,/'product_type'=>'service'/,'Appointment is a service product');
assert.match(adapter,/'fulfillment_type'=>'appointment'/,'Appointment is a fulfillment adapter, not the Commerce object');
assert.match(adapter,/'fulfillment_ref_type'=>'appointment_booking'/,'Appointment order must link to canonical booking by fulfillment reference');
assert.match(adapter,/'fulfillment_group_type'=>'team_booking'/,'Team appointment order must group against one Team booking');
assert.match(adapter,/agent_commerce_create_order_v800/,'Appointment adapter must create canonical Commerce orders');
assert.doesNotMatch(adapter,/CREATE TABLE|ALTER TABLE|DROP TABLE/i,'Appointment adapter must not own parallel commerce schema');

assert.match(page,/Product → Order → Payment → Fulfillment/,'Commerce UI must explain the canonical model');
assert.match(page,/Appointment is the first fulfillment adapter/,'UI must position Appointment as fulfillment');
assert.match(page,/Canonical product catalog/,'Commerce UI must expose products');
assert.match(page,/Canonical commerce ledger/,'Commerce UI must expose orders');
assert.match(page,/Team primary provider/,'Commerce UI must expose Team routing');
assert.match(oauth,/agent_commerce_oauth_exchange_v800/,'Canonical OAuth endpoint must use Commerce runtime');
assert.match(webhook,/agent_commerce_process_webhook_v800/,'Canonical webhook endpoint must use Commerce runtime');
assert.match(cron,/PHP_SAPI!=='cli'/,'Commerce housekeeping runner must be CLI-only');
assert.match(cron,/agent_commerce_housekeeping_v800\(\$pdo,500\)/,'Commerce cron must expire generic payment holds');

const lifeIndex=bootstrap.indexOf("agent-appointment-lifecycle-v700.php");
const commerceIndex=bootstrap.indexOf("agent-commerce-v800.php");
const adapterIndex=bootstrap.indexOf("agent-paid-appointments-v800.php");
assert.ok(lifeIndex>=0&&commerceIndex>lifeIndex&&adapterIndex>commerceIndex,'Commerce must load after lifecycle and before Appointment adapter');
assert.match(bootstrap,/agent_commerce_housekeeping_maybe_v800\(\)/,'Bootstrap must run lightweight generic Commerce housekeeping');
assert.match(upgrade,/agent_commerce_schema_ready_v800\(\)/,'Upgrade completeness must include Commerce');
assert.match(upgrade,/agent_commerce_ensure_schema_v800\(\)/,'Upgrade must install Commerce before adapter integration');
assert.match(nav,/'commerce','Commerce',url\('\/commerce\.php'\),'agent'/,'Commerce must be canonical member navigation');

console.log('AGENT_COMMERCE_V800=PASS');
