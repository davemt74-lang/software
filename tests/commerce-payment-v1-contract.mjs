import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';

const contractPath=new URL('../contracts/commerce/commerce-payment-v1/contract.json',import.meta.url);
const digestPath=new URL('../contracts/commerce/commerce-payment-v1/SHA256',import.meta.url);
const contractText=fs.readFileSync(contractPath,'utf8');
const contract=JSON.parse(contractText);
const expectedDigest=fs.readFileSync(digestPath,'utf8').trim().split(/\s+/)[0];
const actualDigest=crypto.createHash('sha256').update(contractText).digest('hex');
assert.equal(actualDigest,expectedDigest,'commerce-payment-v1 canonical digest must stay pinned');

assert.equal(contract.contract,'commerce-payment-v1');
assert.equal(contract.canonical_owner,'vp3-cloud');
assert.deepEqual(contract.authorities,['cloud','homeserver']);
assert.equal(contract.authority_rules.pin_on_attempt,true);
assert.equal(contract.authority_rules.silent_fallback,false);
assert.equal(contract.wire.id_type,'opaque-string');
assert.equal(contract.wire.money_type,'integer-minor-unit');
assert.equal(contract.wire.unknown_fields,'reject');
assert.equal(contract.compatibility.breaking_change_requires,'commerce-payment-v2');

const expectedOperations={
  'vp3.commerce.payments.status':'payments.read',
  'vp3.commerce.checkout.create':'payments.write',
  'vp3.commerce.checkout.retrieve':'payments.read',
  'vp3.commerce.webhook.verify':'payments.write',
  'vp3.commerce.refund':'payments.refund',
};
assert.deepEqual(contract.permissions,expectedOperations);
assert.deepEqual(Object.keys(contract.operations).sort(),Object.keys(expectedOperations).sort());

for(const [operation,spec] of Object.entries(contract.operations)){
  assert.ok(Array.isArray(spec.request_required),`${operation} must declare required request fields`);
  assert.ok(Array.isArray(spec.request_optional),`${operation} must declare optional request fields`);
  assert.ok(Array.isArray(spec.response_required),`${operation} must declare required response fields`);
  assert.ok(spec.response_required.includes('contract'),`${operation} response must identify the contract`);
  assert.ok(spec.response_required.includes('contract_sha256'),`${operation} response must identify the exact contract revision`);
}
for(const operation of ['vp3.commerce.checkout.create','vp3.commerce.refund']){
  assert.ok(contract.operations[operation].request_required.includes('amount_minor'));
  assert.ok(contract.operations[operation].request_required.includes('idempotency_key'));
}
assert.ok(contract.operations['vp3.commerce.checkout.create'].request_required.includes('platform_fee_minor'));
assert.deepEqual(contract.operations['vp3.commerce.webhook.verify'].request_required,['provider','payload_b64','provider_signature']);

const core=[1,2,3,4].map(i=>fs.readFileSync(new URL(`../includes/agent-commerce-v800-part${i}.php`,import.meta.url),'utf8')).join('\n');
assert.match(core,/agent_commerce_products_v800/,'Phase 8 Cloud Commerce remains the canonical product system');
assert.match(core,/agent_commerce_orders_v800/,'Phase 8 Cloud Commerce remains the canonical order system');
assert.match(core,/agent_commerce_payments_v800/,'Phase 8 Cloud Commerce remains the canonical payment ledger');
assert.match(core,/agent_commerce_refunds_v800/,'Phase 8 Cloud Commerce remains the canonical refund ledger');
assert.match(core,/agent_commerce_minor_to_decimal_v800/,'Cloud already treats provider money as integer minor units');

console.log('COMMERCE_PAYMENT_V1_CONTRACT=PASS');
