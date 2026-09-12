import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=(p)=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
const lifecycle=read('includes/profile-commerce-lifecycle-v1100.php');
const checkout=read('includes/profile-commerce-checkout-v900.php');
const paymentReturn=read('profile-commerce-return.php');
const orderPage=read('profile-commerce-order.php');
const routes=read('.htaccess');

assert.match(lifecycle,/VP3_PROFILE_COMMERCE_LIFECYCLE_V1100/);
assert.match(lifecycle,/bin2hex\(random_bytes\(32\)\)/,'receipt access must use 256 bits of randomness');
assert.match(lifecycle,/receipt_token_sha256/,'customer order access must use persisted token hashes');
assert.match(lifecycle,/hash_equals\(/,'receipt verification must use constant-time comparison');
assert.match(lifecycle,/owner_user_id=\? AND order_number=\?/,'customer order lookup must be seller-owner and order-number scoped');
assert.match(lifecycle,/profile_commerce_order_is_profile_v900/,'customer lifecycle must remain limited to Profile Commerce lineage');
assert.match(lifecycle,/profile_commerce_fulfillment_state_v900/,'customer lifecycle must read the canonical Profile Commerce fulfillment state');
assert.match(lifecycle,/payment_status/,'customer lifecycle must project canonical payment state');
assert.doesNotMatch(lifecycle,/payer_email.*=>/,'customer projection must not expose payer email');
assert.doesNotMatch(lifecycle,/api\.stripe\.com|connect\.square|api-m\.paypal/,'customer lifecycle must not implement payment adapters');

assert.match(checkout,/receipt_token.*profile_commerce_receipt_token_v1100/,'checkout intent must receive an opaque receipt token');
assert.match(checkout,/receipt_token_sha256.*profile_commerce_receipt_token_hash_v1100/s,'canonical order metadata must persist only the receipt-token hash');
assert.match(checkout,/receipt_token.*=>\$receiptToken/,'checkout result may return the raw token only to the active server-side flow');
assert.match(checkout,/Checkout receipt lineage could not be resumed safely/,'checkout retries must fail closed on receipt-lineage mismatch');

assert.match(paymentReturn,/agent_commerce_return_verify_v800/,'provider return must still verify through canonical Phase 8 Commerce');
assert.match(paymentReturn,/profile_commerce_customer_order_v1100/,'return must validate the receipt token against the canonical order before redirect');
assert.match(paymentReturn,/profile_commerce_customer_order_url_v1100/,'verified return must hand the buyer to the secure lifecycle page');
assert.doesNotMatch(paymentReturn,/api\.stripe\.com|connect\.square|api-m\.paypal/,'return path must not duplicate provider adapters');

assert.match(routes,/\/order\/\(\[A-Za-z0-9\._-\]\{1,80\}\)\/\(\[A-Fa-f0-9\]\{64\}\)/,'receipt route must require order number plus a 64-hex opaque token');
assert.match(orderPage,/Cache-Control: no-store/,'receipt page must not be cached');
assert.match(orderPage,/Referrer-Policy: no-referrer/,'receipt token must not leak through referrers');
assert.match(orderPage,/X-Robots-Tag: noindex, nofollow, noarchive/,'receipt page must not be indexed');
assert.match(orderPage,/profile_commerce_customer_order_v1100/,'receipt page must validate canonical order access');
assert.match(orderPage,/profile_commerce_customer_projection_v1100/,'receipt page must render only the customer-safe projection');
assert.doesNotMatch(orderPage,/provider_snapshot|external_payment|payer_email/,'receipt page must not expose provider or payer internals');

console.log('PROFILE_COMMERCE_LIFECYCLE_V1100_CONTRACT=PASS');
