import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');

const runtime = read('includes/token-packs.php');
const subscriptions = read('includes/subscriptions.php');
const webhook = read('billing-webhook.php');
const customer = read('token-packs.php');
const admin = read('admin/token-packs.php');
const upgrade = read('upgrade.php');
const migration = read('sql/ai-token-packs.sql');
const sidebar = read('includes/main-sidebar.php');

assert.match(subscriptions, /token-packs\.php/);
assert.match(runtime, /CREATE TABLE IF NOT EXISTS ai_token_packs/);
assert.match(runtime, /CREATE TABLE IF NOT EXISTS ai_token_pack_purchases/);
assert.match(runtime, /provider_session_id VARCHAR\(160\) NULL DEFAULT NULL/);
assert.match(runtime, /UPDATE ai_token_pack_purchases SET provider_session_id=NULL WHERE provider_session_id=''/);
assert.match(runtime, /pack_name_snapshot/);
assert.match(runtime, /token_amount BIGINT UNSIGNED NOT NULL/);
assert.match(runtime, /price_cents INT UNSIGNED NOT NULL/);
assert.match(runtime, /expires_days SMALLINT UNSIGNED NULL/);
assert.match(runtime, /billing_stripe_webhook_secret\(\)/);
assert.match(runtime, /'mode'=>'payment'/);
assert.match(runtime, /'payment_method_types'=>\['card'\]/);
assert.match(runtime, /'price_data'=>/);
assert.match(runtime, /\(int\)\$purchase\['price_cents'\]/);
assert.match(runtime, /vp3_purchase_type.*ai_token_pack/s);
assert.match(runtime, /vp3_token_purchase_id/);
assert.match(runtime, /vp3_token_pack_id/);
assert.match(runtime, /vp3_user_id/);
assert.match(runtime, /payment_status.*paid/s);
assert.match(runtime, /amount_total/);
assert.match(runtime, /hash_equals\(\$storedSession,\$sessionId\)/);
assert.match(runtime, /Stripe token purchase total does not match the VP3 purchase snapshot/);
assert.match(runtime, /FOR UPDATE/);
assert.match(runtime, /\(string\)\$purchase\['status'\]===\s*'credited'/);
assert.match(runtime, /'purchased_topup'/);
assert.match(runtime, /subscription_add_token_credit/);
assert.match(runtime, /token checkout is already being prepared/i);
assert.equal(runtime.includes('subscription_assign_package('), false, 'Token purchases must never assign or change a package');
assert.equal(runtime.includes("UPDATE user_subscriptions"), false, 'Token purchases must never mutate subscription dates/status');
assert.equal(runtime.includes('trial_ends_at'), false, 'Token purchases must not extend a trial');

assert.match(webhook, /token_pack_is_stripe_event/);
assert.match(webhook, /token_pack_process_stripe_event/);
assert.match(webhook, /billing_process_stripe_event/);
assert.match(webhook, /billing_stripe_verify_webhook/);

assert.match(customer, /require_login\(\)/);
assert.match(customer, /verify_csrf\(\)/);
assert.match(customer, /token_pack_begin_purchase/);
assert.match(customer, /token_pack_reconcile_return/);
assert.match(customer, /token_pack_public/);
assert.match(customer, /token_pack_purchase_history/);
assert.match(customer, /subscription_ai_balance/);
assert.match(customer, /Token packs do not extend trials or change package entitlements/);
assert.match(customer, /name="pack_id"/);
assert.match(customer, /Manage Token Packs/);
assert.match(customer, /\$notice=flash\('notice'\)/);
assert.match(customer, /\$errorNotice=flash\('error'\)/);
assert.match(customer, /class="token-notice" role="status"/);
assert.match(customer, /class="token-notice error" role="alert"/);
assert.equal(/name="(?:token_amount|price_cents|price_dollars)"/.test(customer), false, 'Customer Checkout must not accept token quantity or price from the browser');

assert.match(admin, /require_permission\('users\.manage'\)/);
assert.match(admin, /token_pack_save/);
assert.match(admin, /function admin_token_pack_price_cents/);
assert.match(admin, /credited_revenue_cents/);
assert.match(admin, /Recent Token Purchases/);
assert.match(admin, /Archive Pack/);
assert.match(admin, /Historical purchases were preserved/);

assert.match(upgrade, /token_pack_schema_ready\(\)/);
assert.match(upgrade, /token_pack_ensure_schema\(\)/);
assert.match(migration, /provider_session_id VARCHAR\(160\) NULL DEFAULT NULL/);
assert.match(migration, /FOREIGN KEY \(credit_id\) REFERENCES ai_token_credits/);
assert.match(sidebar, /Plan &amp; Usage/);
assert.match(sidebar, /Buy AI Tokens/);
assert.match(sidebar, /mainSidebarTokenCommerceReady/);

for (const source of [runtime, customer, admin]) {
  assert.equal(/openai|anthropic|gemini/i.test(source), false, 'Token commerce must not invoke an LLM provider');
}

console.log('AI token pack one-time purchase contract: PASS');
