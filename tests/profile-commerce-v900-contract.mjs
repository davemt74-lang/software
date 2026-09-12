import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=(p)=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
const layer=read('includes/profile-commerce-v900.php');
const checkout=read('includes/profile-commerce-checkout-v900.php');
const wrapper=read('profile-v900.php');
const product=read('profile-commerce-product.php');
const manage=read('profile-commerce-products.php');
const routes=read('.htaccess');
const transcript=read('includes/profile-agent-transcription-context.php');
const nav=read('includes/member-navigation.php');

assert.match(layer,/VP3_PROFILE_COMMERCE_V900/);
assert.match(layer,/profile_visibility.*hidden/s,'publication must be hidden by default');
assert.match(layer,/profile_commerce_visibility_v900\(\$product\)!==['"]public['"]/,'checkout must recheck public visibility');
assert.match(layer,/p\.owner_user_id=\?/,'public profile projection must be owner scoped');
assert.match(layer,/p\.is_active=1/,'public projection must be active-only');
assert.match(layer,/agent_commerce_create_order_v800/,'v9 must reuse canonical Phase 8 orders');
assert.match(layer,/agent_commerce_create_checkout_v800/,'v9 must reuse canonical Phase 8 checkout');
assert.doesNotMatch(layer,/api\.stripe\.com|connect\.square|api-m\.paypal/,'Profile Commerce must not implement provider adapters');
assert.match(layer,/profile_commerce_agent_context_v900/);
assert.match(layer,/profile_commerce_products_for_profile_v900\(\$pdo,\$profile,true/,'Agent context must consume the public projection');
assert.match(layer,/profile_commerce_token_valid_v900/);

assert.match(checkout,/profile_commerce_checkout_nonce_v900/,'checkout must create a session-scoped idempotency nonce');
assert.match(checkout,/profile_commerce_checkout_intent_v900/,'checkout nonce must be owner/product bound');
assert.match(checkout,/\$intent\['order_id'\]=\(int\)\$order\['id'\]/,'canonical order must be bound to the intent before provider checkout');
assert.match(checkout,/agent_commerce_order_v800\(\$pdo,\$storedOrder\)/,'browser retries must resume the existing canonical order');
assert.match(checkout,/agent_commerce_order_items_v800/,'resumed orders must be verified against the requested product');
assert.match(checkout,/agent_commerce_create_checkout_v800/,'idempotent wrapper must still delegate provider execution to Phase 8');
assert.doesNotMatch(checkout,/api\.stripe\.com|connect\.square|api-m\.paypal/,'retry layer must not implement provider adapters');

assert.match(routes,/profile-v900\.php\?username=\$1/,'canonical /username route must compose Profile Commerce');
assert.match(routes,/\/product\//,'product detail must remain subordinate to /username');
assert.doesNotMatch(routes,/RewriteRule[^\n]*\/store/i,'no separate public storefront route is allowed');
assert.match(wrapper,/require __DIR__.'\/profile\.php'/,'v9 must compose the canonical profile renderer rather than fork it');
assert.match(wrapper,/profile-commerce-grid-v900/);
assert.match(wrapper,/verifying the provider result/i,'return UI must not claim payment succeeded before verification');

assert.match(product,/profile_commerce_token_valid_v900/,'public checkout must validate its session token');
assert.match(product,/checkout_nonce/,'public checkout form must carry the retry/idempotency nonce');
assert.match(product,/profile_commerce_checkout_intent_v900/,'public checkout must validate the nonce before any action');
assert.match(product,/profile_commerce_checkout_connections_v900/,'checkout must use canonical connected providers');
assert.match(product,/profile_commerce_create_checkout_idempotent_v900/,'product surface must delegate to the idempotent v9 bridge');
assert.doesNotMatch(product,/api\.stripe\.com|connect\.square|api-m\.paypal/,'public product page must not call payment providers directly');

assert.match(manage,/require_permission\(['"]account\.access['"]\)/);
assert.match(manage,/verify_csrf\(\)/);
assert.match(manage,/profile_commerce_publish_v900/);
assert.match(manage,/profile_commerce_save_generic_product_v900/);
assert.match(manage,/Nothing is published automatically/);

assert.match(transcript,/profile_commerce_agent_context_v900/,'Profile Agent supplemental context must include public Commerce');
assert.match(transcript,/return \$commerceContext/,'Commerce context must not depend on private Agent Brain availability');
assert.match(nav,/Profile Commerce/,'member navigation must expose Profile Commerce management');

console.log('PROFILE_COMMERCE_V900_CONTRACT=PASS');
