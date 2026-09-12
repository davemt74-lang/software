import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=(p)=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
const layer=read('includes/profile-commerce-v900.php');
const checkout=read('includes/profile-commerce-checkout-v900.php');
const ops=read('includes/profile-commerce-ops-v900.php');
const wrapper=read('profile-v900.php');
const product=read('profile-commerce-product.php');
const paymentReturn=read('profile-commerce-return.php');
const manage=read('profile-commerce-products.php');
const routes=read('.htaccess');
const transcript=read('includes/profile-agent-transcription-context.php');
const nav=read('includes/member-navigation.php');

assert.match(layer,/VP3_PROFILE_COMMERCE_V900/);
assert.match(layer,/profile_visibility.*hidden/s,'publication must be hidden by default');
assert.match(layer,/profile_commerce_visibility_v900\(\$product\)!==['"]public['"]/,'checkout must recheck public visibility');
assert.match(layer,/p\.owner_user_id=\?/,'public profile projection must be owner scoped');
assert.match(layer,/p\.is_active=1/,'public projection must be active-only');
assert.match(layer,/Generic Profile Commerce products must use full payment/,'generic full-payment restriction must be enforced in the domain layer');
assert.match(layer,/Shipped physical products cannot be published/,'unsupported shipping must fail closed in the domain layer');
assert.doesNotMatch(layer,/function profile_commerce_create_checkout_v900/,'obsolete non-idempotent checkout helper must stay removed');
assert.doesNotMatch(layer,/api\.stripe\.com|connect\.square|api-m\.paypal/,'Profile Commerce must not implement provider adapters');
assert.match(layer,/profile_commerce_agent_context_v900/);
assert.match(layer,/profile_commerce_products_for_profile_v900\(\$pdo,\$profile,true/,'Agent context must consume the public projection');
assert.match(layer,/profile_commerce_token_valid_v900/);

assert.match(checkout,/profile_commerce_checkout_nonce_v900/,'checkout must create a session-scoped idempotency nonce');
assert.match(checkout,/profile_commerce_checkout_intent_v900/,'checkout nonce must be owner/product bound');
assert.match(checkout,/payment_mode.*!==['"]full['"]/s,'generic checkout must fail closed unless the canonical product is full-payment');
assert.match(checkout,/fulfillment_type.*===['"]physical['"]/s,'generic checkout must fail closed on unsupported shipped physical fulfillment');
assert.match(checkout,/\$payerEmail===['"]['"]\|\|!filter_var/,'generic checkout must require an email fulfillment contact');
assert.match(checkout,/if\(!\$termsAccepted\)/,'generic checkout must require explicit seller-term acceptance');
assert.match(checkout,/profile_commerce_terms_digest_v900/,'checkout must bind acceptance to canonical product terms');
assert.match(checkout,/terms_snapshot_sha256/,'accepted canonical terms digest must enter order lineage');
assert.match(checkout,/terms_accepted_at/,'accepted terms timestamp must enter order lineage');
assert.match(checkout,/\$intent\['order_id'\]=\(int\)\$order\['id'\]/,'canonical order must be bound to the intent before provider checkout');
assert.match(checkout,/agent_commerce_order_v800\(\$pdo,\$storedOrder\)/,'browser retries must resume the existing canonical order');
assert.match(checkout,/agent_commerce_order_items_v800/,'resumed orders must be verified against the requested product');
assert.match(checkout,/profile-commerce-return\.php\?username=/,'provider returns must use the verified internal callback');
assert.match(checkout,/&intent=/,'provider return callback must carry the session-bound checkout intent');
assert.match(checkout,/agent_commerce_create_order_v800/,'idempotent wrapper must create canonical Phase 8 orders');
assert.match(checkout,/agent_commerce_create_checkout_v800/,'idempotent wrapper must delegate provider execution to Phase 8');
assert.doesNotMatch(checkout,/api\.stripe\.com|connect\.square|api-m\.paypal/,'retry layer must not implement provider adapters');

assert.match(paymentReturn,/profile_commerce_order_for_owner_v900/,'provider return must scope the canonical order to the profile owner');
assert.match(paymentReturn,/profile_commerce_checkout_intent_v900/,'provider return must validate the original checkout intent');
assert.match(paymentReturn,/\$intent\['order_id'\].*\$orderId/s,'provider return must bind the intent to the exact canonical order');
assert.match(paymentReturn,/agent_commerce_return_verify_v800/,'provider return must delegate Stripe, Square and PayPal verification/capture to Phase 8');
assert.match(paymentReturn,/profile_commerce_checkout_intent_forget_v900/,'verified returns must retire the checkout intent');
assert.match(paymentReturn,/profile_commerce_return_notices/,'return state must be carried to the profile as a short-lived server session notice');
assert.doesNotMatch(paymentReturn,/api\.stripe\.com|connect\.square|api-m\.paypal/,'return callback must not implement provider adapters');

assert.match(ops,/profile_commerce_order_is_profile_v900/,'order operations must be limited to Profile Commerce lineage');
assert.match(ops,/\(int\)\(\$order\['owner_user_id'\]/,'order operations must be owner scoped');
assert.match(ops,/\['processing','fulfilled'\]/,'manual fulfillment must use bounded states');
assert.match(ops,/payment_status.*!==['"]paid['"]/s,'generic fulfillment must require full payment');
assert.match(ops,/fulfillment_type.*appointment/s,'appointment fulfillment must remain outside generic Profile Commerce operations');
assert.match(ops,/agent_commerce_audit_v800/,'manual fulfillment must enter the canonical Commerce audit ledger');
assert.match(ops,/agent_commerce_refund_v800/,'generic refunds must reuse the Phase 8 refund engine');
assert.match(ops,/\$approved\)/,'generic refunds must preserve explicit approval');
assert.doesNotMatch(ops,/api\.stripe\.com|connect\.square|api-m\.paypal/,'order operations must not implement provider adapters');

assert.match(routes,/profile-v900\.php\?username=\$1/,'canonical /username route must compose Profile Commerce');
assert.match(routes,/\/product\//,'product detail must remain subordinate to /username');
assert.doesNotMatch(routes,/RewriteRule[^\n]*\/store/i,'no separate public storefront route is allowed');
assert.match(wrapper,/require __DIR__.'\/profile\.php'/,'v9 must compose the canonical profile renderer rather than fork it');
assert.match(wrapper,/profile-commerce-grid-v900/);
assert.match(wrapper,/profile_commerce_return_notices/,'profile return messaging must come from server-verified session state');
assert.doesNotMatch(wrapper,/agent_commerce_return_verify_v800/,'profile renderer itself must not perform provider network verification');

assert.match(product,/profile_commerce_token_valid_v900/,'public checkout must validate its session token');
assert.match(product,/checkout_nonce/,'public checkout form must carry the retry/idempotency nonce');
assert.match(product,/profile_commerce_checkout_intent_v900/,'public checkout must validate the nonce before any action');
assert.match(product,/profile_commerce_checkout_connections_v900/,'checkout must use canonical connected providers');
assert.match(product,/profile_commerce_create_checkout_idempotent_v900/,'product surface must delegate to the idempotent v9 bridge');
assert.match(product,/payer_email[^>]*required/,'public checkout must collect the fulfillment contact before charging');
assert.match(product,/terms_digest/,'public checkout must submit the canonical terms digest');
assert.match(product,/accept_terms[^>]*required/,'public checkout must require buyer acceptance of seller terms');
assert.doesNotMatch(product,/api\.stripe\.com|connect\.square|api-m\.paypal/,'public product page must not call payment providers directly');

assert.match(manage,/require_permission\(['"]account\.access['"]\)/);
assert.match(manage,/verify_csrf\(\)/);
assert.match(manage,/profile_commerce_publish_v900/);
assert.match(manage,/profile_commerce_save_generic_product_v900/);
assert.match(manage,/payment_mode.*full/s,'generic product management must constrain Phase 9 products to full payment');
assert.match(manage,/Generic Profile Commerce products must use full payment/,'legacy or manually-created generic deposits must fail closed at publication');
assert.match(manage,/profile_commerce_set_fulfillment_v900/,'owner workspace must expose manual fulfillment');
assert.match(manage,/profile_commerce_refund_order_v900/,'owner workspace must expose provider-neutral generic refunds');
assert.match(manage,/Shipping.*coming later/,'shipping must remain disabled until its fulfillment adapter exists');
assert.match(manage,/Nothing is published automatically/);

assert.match(transcript,/profile_commerce_agent_context_v900/,'Profile Agent supplemental context must include public Commerce');
assert.match(transcript,/return \$commerceContext/,'Commerce context must not depend on private Agent Brain availability');
assert.match(nav,/Profile Commerce/,'member navigation must expose Profile Commerce management');

console.log('PROFILE_COMMERCE_V900_CONTRACT=PASS');
