import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=(p)=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
const delivery=read('includes/profile-commerce-delivery-v1120.php');
const receipt=read('profile-commerce-order.php');
const workspace=read('profile-commerce-delivery.php');
const nav=read('includes/member-navigation.php');

assert.match(delivery,/profile_commerce_delivery_eligible_v1120/);
assert.match(delivery,/payment_status.*paid/s,'seller delivery must require verified full payment');
assert.match(delivery,/\['digital','virtual','membership','event','gift','local_pickup','other'\]/,'delivery must stay bounded to generic non-shipping fulfillment types');
assert.match(delivery,/customer_delivery/,'delivery state must remain attached to the canonical order metadata');
assert.match(delivery,/FILTER_VALIDATE_URL/,'resource URL must be validated');
assert.match(delivery,/scheme.*https/s,'resource URL must be HTTPS-only');
assert.match(delivery,/empty\(\$parts\['user'\]\).*empty\(\$parts\['pass'\]\)/s,'resource URL must reject embedded credentials');
assert.match(delivery,/FOR UPDATE/,'delivery metadata writes must serialize the canonical order');
assert.match(delivery,/profile_delivery_saved/,'delivery saves must enter the Commerce audit ledger');
assert.match(delivery,/message_chars.*resource_present/s,'audit metadata must record only bounded delivery facts, not delivery content');
assert.match(delivery,/profile_commerce_set_fulfillment_v900/,'delivery state transitions must reuse the existing Profile Commerce fulfillment engine');
assert.match(delivery,/\['paid','partially_refunded'\]/,'customer delivery remains readable after a partial refund');
assert.doesNotMatch(delivery,/api\.stripe\.com|connect\.square|api-m\.paypal/,'delivery must not duplicate payment adapters');
assert.doesNotMatch(delivery,/CREATE TABLE|ALTER TABLE/,'delivery must not create a second lifecycle schema');

assert.match(receipt,/profile-commerce-delivery-v1120\.php/,'secure receipt must load the delivery projection');
assert.match(receipt,/profile_commerce_delivery_for_customer_v1120/,'receipt must use the customer-safe delivery projection');
assert.match(receipt,/Private delivery/,'receipt must render delivery only inside the private order surface');
assert.match(receipt,/rel="noopener noreferrer nofollow"/,'delivery resource links must be isolated from the receipt context');
assert.match(receipt,/e\(\(string\)\$delivery\['message'\]\)/,'delivery message must be escaped');
assert.match(receipt,/e\(\(string\)\$delivery\['resource_url'\]\)/,'delivery URL must be escaped');

assert.match(workspace,/require_permission\('account\.access'\)/,'seller delivery workspace requires account access');
assert.match(workspace,/verify_csrf\(\)/,'seller delivery mutation requires CSRF');
assert.match(workspace,/profile_commerce_delivery_save_v1120/,'workspace must delegate mutations to the bounded delivery helper');
assert.match(workspace,/maxlength="4000"/,'delivery message must be bounded in the UI');
assert.match(workspace,/maxlength="2048"/,'resource URL must be bounded in the UI');
assert.match(workspace,/Save \+ mark fulfilled/,'workspace must expose the canonical fulfillment transition');
assert.match(nav,/profile-commerce-delivery\.php/,'member navigation must expose the delivery workspace');

console.log('PROFILE_COMMERCE_DELIVERY_V1120_CONTRACT=PASS');
