import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=(p)=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
const lifecycle=read('includes/profile-commerce-lifecycle-v1100.php');
const sellerOps=read('includes/profile-commerce-refund-requests-v1100.php');
const receipt=read('profile-commerce-order.php');
const seller=read('profile-commerce-refund-requests.php');
const nav=read('includes/member-navigation.php');

assert.match(lifecycle,/profile_commerce_customer_refund_request_create_v1100/);
assert.match(lifecycle,/profile_commerce_customer_order_v1100\(/,'customer mutation must re-use opaque receipt authentication');
assert.match(lifecycle,/\['paid','partially_refunded'\]/,'refund requests require verified paid value');
assert.match(lifecycle,/amount_paid_cents.*amount_refunded_cents/s,'requested amount must be bounded to the remaining canonical paid balance');
assert.match(lifecycle,/customer_refund_request/,'refund request state must stay attached to the canonical order');
assert.match(lifecycle,/status.*pending/s,'customer request must enter seller review rather than moving money');
assert.match(lifecycle,/seller_refund_submitted.*still being processed/s,'direct replay must fail closed while an approved provider refund is still processing');
assert.match(lifecycle,/customer_refund_requested/,'customer request must enter the Commerce audit ledger');
assert.match(lifecycle,/'customer',null,null,'customer_refund_requested'/,'audit actor must be customer-safe and not invent a VP3 user identity');
assert.match(lifecycle,/fulfillment_type.*appointment/s,'appointment refunds must remain outside generic Profile Commerce');
assert.match(lifecycle,/FOR UPDATE/,'customer and seller request transitions must serialize canonical order metadata');
assert.doesNotMatch(lifecycle,/api\.stripe\.com|connect\.square|api-m\.paypal/,'customer request lifecycle must not implement payment providers');

assert.match(sellerOps,/profile_commerce_owner_approve_refund_request_v1100/);
assert.match(sellerOps,/GET_LOCK\(\?,10\)/,'seller approvals must serialize concurrent review attempts per canonical order');
assert.match(sellerOps,/RELEASE_LOCK\(\?\)/,'seller approval lock must always be released');
assert.match(sellerOps,/profile_commerce_refund_order_v900/,'seller approval must reuse the audited Phase 8 refund engine');
assert.match(sellerOps,/profile_commerce_owner_refund_request_set_v1100/,'provider submission must resolve the pending customer request state');
assert.match(sellerOps,/profile_commerce_owner_decline_refund_request_v1100/,'seller may explicitly decline without moving money');
assert.doesNotMatch(sellerOps,/api\.stripe\.com|connect\.square|api-m\.paypal/,'seller request operations must not duplicate provider adapters');

assert.match(receipt,/REQUEST_METHOD.*POST/s,'secure receipt must accept an explicit customer POST');
assert.match(receipt,/request_refund/,'receipt must expose only the bounded refund-request mutation');
assert.match(receipt,/profile_commerce_customer_refund_request_create_v1100/,'receipt must delegate customer request creation to the lifecycle layer');
assert.match(receipt,/No money moves until the seller explicitly approves/,'receipt must make the approval boundary explicit');
assert.match(receipt,/maxlength="500"/,'customer reason must be bounded in the UI');

assert.match(seller,/require_permission\('account\.access'\)/,'seller workspace requires account access');
assert.match(seller,/verify_csrf\(\)/,'seller mutations require CSRF protection');
assert.match(seller,/profile_commerce_owner_approve_refund_request_v1100/,'approve action must use bounded seller helper');
assert.match(seller,/profile_commerce_owner_decline_refund_request_v1100/,'decline action must use bounded seller helper');
assert.match(seller,/explicit review before money moves/i,'seller workspace must communicate the approval boundary');
assert.match(nav,/Refund Requests/,'member navigation must expose customer refund requests');

console.log('PROFILE_COMMERCE_REFUND_REQUESTS_V1110_CONTRACT=PASS');
