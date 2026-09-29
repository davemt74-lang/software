import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const commerce=read('includes/profile-webmcp-commerce-v160.php');
const catalog=read('includes/profile-webmcp-v100.php');
const nativeApi=read('api/profile-webmcp-v100.php');
const externalLayer=read('includes/profile-webmcp-external-v120.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const nativeRuntime=read('profile-webmcp-v100.js');
const externalRuntime=read('profile-webmcp-external-v120.js');
const returnBridge=read('profile-webmcp-commerce-return-v160.php');
const commerceCore=read('includes/agent-commerce-v800-part4.php');
const bootstrap=read('includes/bootstrap.php');
const sitesUi=read('profile-agent-radar-sites.js');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(bootstrap,/profile-webmcp-commerce-v160\.php/,'bootstrap must load Commerce WebMCP for payment-verification attribution');
for(const name of [
 'vp3.commerce.products.list','vp3.commerce.product.get','vp3.commerce.checkout.prepare','vp3.commerce.checkout.confirm',
 'vp3.commerce.order.get','vp3.commerce.receipt.get','vp3.commerce.delivery.get','vp3.commerce.refund.status',
 'vp3.commerce.refund.prepare','vp3.commerce.refund.confirm'
]){
 assert.match(commerce,new RegExp(name.replaceAll('.','\\.')),'server commerce catalog '+name);
 assert.match(nativeRuntime,new RegExp(name.replaceAll('.','\\.')),'native trusted commerce catalog '+name);
 assert.match(externalRuntime,new RegExp(name.replaceAll('.','\\.')),'external trusted commerce catalog '+name);
}

assert.match(commerce,/profile_commerce_public_product_v900/,'product discovery must use public Profile Commerce projection');
assert.match(commerce,/profile_commerce_visibility_v900\(\$row\)!=='public'/,'checkout must revalidate public visibility');
assert.match(commerce,/profile_commerce_terms_digest_v900/,'seller terms must be canonical and digest-bound');
assert.match(commerce,/product_state_hash/,'prepared checkout must bind product state');
assert.match(commerce,/vp3_profile_webmcp_b64url_encode_v140\(\$sig\).*\$sigEncoded/s,'non-canonical signature encoding must fail closed');
assert.match(commerce,/agent_commerce_create_order_v800/,'checkout confirm must create canonical Commerce order');
assert.match(commerce,/agent_commerce_create_checkout_v800/,'checkout confirm must use canonical hosted provider checkout');
assert.match(commerce,/state='executing'/,'provider checkout must have a durable resumable execution barrier');
assert.match(commerce,/SELECT GET_LOCK\(\?,5\)/,'same-key commerce confirms must serialize');
assert.match(commerce,/vp3_profile_webmcp_action_by_idempotency_v150/,'checkout/refund must reuse durable idempotency ledger');
assert.match(commerce,/payment_mode.*full/s,'generic public checkout must stay on canonical full-payment products');
assert.match(commerce,/fulfillment_type.*physical/s,'physical shipping must remain unavailable without shipping adapter');
assert.match(commerce,/appointment_event_type/,'appointment products must stay in Scheduling WebMCP');

assert.doesNotMatch(commerce,/agent_commerce_mark_paid_v800\s*\(/,'WebMCP checkout must never mark payment paid');
assert.doesNotMatch(commerce,/agent_commerce_refund_v800\s*\(/,'WebMCP refund flow must never execute provider refund');
assert.match(commerce,/profile_commerce_customer_refund_request_create_v1100/,'refund confirm must submit existing seller-review request');
assert.match(commerce,/money_moves_on_confirm.*false/s,'refund preview must state that money does not move');
assert.match(commerce,/seller_review_required.*true/s,'refund request must remain seller-reviewed');

assert.match(commerce,/profile_commerce_customer_order_v1100/,'order reads must require canonical receipt authority');
assert.match(commerce,/profile_commerce_customer_projection_v1100/,'order/receipt output must use customer-safe projection');
assert.match(commerce,/private_delivery_available/);
const deliveryStart=commerce.indexOf('function vp3_profile_webmcp_commerce_delivery_get_v160');
const deliveryEnd=commerce.indexOf('\nfunction ',deliveryStart+20);
const delivery=commerce.slice(deliveryStart,deliveryEnd);
const deliveryReturn=delivery.slice(delivery.indexOf('return ['));
assert.doesNotMatch(deliveryReturn,/'message'\s*=>|'resource_url'\s*=>|'resource_label'\s*=>|original_name\s*=>|storage_path\s*=>/,'delivery tool must not return private delivery contents/files');
assert.match(delivery,/delivery_resource_available/,'delivery tool may derive a safe resource-available boolean from canonical delivery metadata');

assert.match(commerce,/profile_commerce_receipt_token_v1100/,'checkout prepare must mint canonical receipt authority');
assert.match(commerce,/receipt_token_sha256/,'canonical order must store only receipt token hash');
assert.doesNotMatch(commerce,/result_json[\s\S]{0,500}receipt_token/,'action result ledger must not store raw receipt token');

assert.match(returnBridge,/agent_commerce_decrypt_v800/,'provider return state must be encrypted/stateless');
assert.match(returnBridge,/agent_commerce_return_verify_v800/,'provider return must preserve canonical Stripe\/Square\/PayPal verification');
assert.doesNotMatch(returnBridge,/profile_commerce_checkout_intent_v900|\$_SESSION/,'WebMCP provider return must not depend on browser-session checkout nonce');

assert.match(commerceCore,/vp3_profile_webmcp_commerce_record_purchase_v160/,'purchase attribution must run from canonical payment verification path');
assert.match(commerce,/payment_status.*paid/s,'purchase attribution must require canonical paid state');
assert.match(commerce,/vp3_agent_referral_record\(\$pdo,\$ref,\$propertyId,\$sessionHash,'purchase'/,'verified purchase must continue first-party agent referral attribution');
assert.match(commerce,/webmcp_purchase_completed/,'verified purchase must emit WebMCP purchase completion');
assert.match(commerce,/webmcp_purchase_telemetry_recorded_at/,'purchase completion must be deduped');

assert.match(catalog,/vp3_profile_webmcp_commerce_tool_catalog_v160/,'main catalog must merge Commerce adapter catalog');
assert.match(catalog,/['"]vp3\.commerce\.checkout\.prepare['"]/);
assert.match(catalog,/['"]vp3\.commerce\.refund\.confirm['"]/);
assert.match(catalog,/registeredCapabilities/,'intent resolver must derive Commerce adapter readiness from registered tools');
assert.match(catalog,/requires_domain_adapter/,'intent resolver must report only genuinely missing domain adapters');
assert.match(nativeApi,/vp3_profile_webmcp_commerce_checkout_confirm_v160/);
assert.match(externalApi,/vp3_profile_webmcp_commerce_checkout_confirm_v160/);
assert.match(nativeApi,/webmcp_checkout_prepared/);
assert.match(externalApi,/webmcp_checkout_started/);
assert.match(externalLayer,/commerce_enabled'\s*=>\s*\$commerceEnabled/);
assert.match(externalRuntime,/commerce_enabled===true/);
assert.match(externalRuntime,/credentials:'omit'/);
assert.doesNotMatch(externalRuntime,/Authorization|document\.cookie|localStorage|sessionStorage/,'connected-site commerce must stay bearer/cookie/storage free');
assert.match(sitesUi,/Commerce <b>/);

assert.match(workflow,/profile-webmcp-commerce-v160\.php/);
assert.match(workflow,/profile-webmcp-commerce-v160-contract\.mjs/);
assert.match(workflow,/profile-webmcp-commerce-v160-runtime\.mjs/);
assert.match(workflow,/profile-webmcp-commerce-return-v160\.php/);
assert.match(recovery,/profile-webmcp-commerce-v160\.php/);
assert.match(recovery,/profile-webmcp-commerce-v160-contract\.mjs/);
assert.match(recovery,/profile-webmcp-commerce-v160-runtime\.mjs/);

console.log('PROFILE_WEBMCP_COMMERCE_V160_CONTRACT=PASS');
