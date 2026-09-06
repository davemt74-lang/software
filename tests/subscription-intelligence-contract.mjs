import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');

const service = read('includes/subscription-intelligence.php');
const subscriptions = read('includes/subscriptions.php');
const billing = read('admin/billing.php');

assert.match(subscriptions, /subscription-intelligence\.php/);
assert.match(service, /function subscription_intelligence_summary/);
assert.match(service, /function subscription_intelligence_package_mix/);
assert.match(service, /function subscription_intelligence_trials_ending/);
assert.match(service, /function subscription_intelligence_ai_by_package/);
assert.match(service, /function subscription_intelligence_ai_by_scope/);
assert.match(service, /function subscription_intelligence_heavy_users/);
assert.match(service, /function subscription_intelligence_ai_daily/);
assert.match(service, /function subscription_intelligence_credit_sources/);
assert.match(service, /function subscription_intelligence_run_rate_by_package/);

assert.match(service, /INNER JOIN user_subscriptions ls ON ls\.id=bs\.user_subscription_id/);
assert.match(service, /bs\.status='active'/);
assert.match(service, /ls\.status='active'/);
assert.match(service, /package_billing_prices bp ON bp\.provider=bs\.provider AND bp\.provider_price_id=bs\.provider_price_id/);
assert.match(service, /billing_interval='annual'.*unit_amount_cents\/12/s);
assert.match(service, /p\.is_trial=1/);
assert.match(service, /billing_subscriptions bs ON bs\.user_id=t\.user_id/);
assert.match(service, /ai_usage_ledger/);
assert.match(service, /LEFT JOIN user_subscriptions s ON s\.id=l\.subscription_id/);
assert.match(service, /credit_tokens_used/);
assert.match(service, /package_tokens_used/);
assert.match(service, /source='purchased_topup'/);
assert.match(service, /ai_token_pack_purchases/);
assert.match(service, /status='credited'/);
assert.equal(/\b(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE)\b/i.test(service), false, 'Subscription intelligence must remain read-only');
assert.equal(/openai|anthropic|gemini/i.test(service), false, 'Business intelligence must not invoke an LLM provider');

assert.match(billing, /Stripe Billing & Subscription Intelligence/);
assert.match(billing, /Recurring MRR/);
assert.match(billing, /Recurring ARR/);
assert.match(billing, /This is not a collected-cash total/);
assert.match(billing, /Trial → Paid/);
assert.match(billing, /Token Revenue · 30d/);
assert.match(billing, /Trials Ending in 7 Days/);
assert.match(billing, /Current Package Mix/);
assert.match(billing, /Recurring Run-Rate by Package/);
assert.match(billing, /AI Credit Sources · 30 Days/);
assert.match(billing, /AI Usage Trend · 30 Days/);
assert.match(billing, /AI Consumption by Package · 30 Days/);
assert.match(billing, /AI Consumption by Feature · 30 Days/);
assert.match(billing, /Highest AI Usage · 30 Days/);
assert.match(billing, /Historical usage is attributed to the subscription ID recorded on each AI request/);
assert.match(billing, /AI Token Packs/);
assert.match(billing, /Run-rate is derived from immutable Stripe Price mappings/);

console.log('Admin subscription intelligence contract: PASS');
