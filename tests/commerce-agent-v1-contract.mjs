import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';

const read=p=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
const contractRaw=read('contracts/commerce/commerce-agent-v1/contract.json');
const contract=JSON.parse(contractRaw);
const digest=crypto.createHash('sha256').update(contractRaw).digest('hex');
const pinned=read('contracts/commerce/commerce-agent-v1/SHA256').trim().split(/\s+/)[0];
const bridge=read('includes/homeserver-commerce-agent-v1000.php');
const api=read('api/homeserver-commerce-agent-v1000.php');
const status=read('api/homeserver-status.php');
const approvals=read('includes/homeserver-approvals-v028.php');

assert.equal(contract.contract,'commerce-agent-v1');
assert.equal(contract.canonical_owner,'vp3-cloud');
assert.equal(digest,pinned);
assert.equal(digest,'b61b1baea945286fb006ef78f7f798b4a604284145a74f71628d43779173bf77');
assert.equal(contract.permissions['vp3.commerce.checkout.handoff'],'commerce.order');
assert.equal(contract.permissions['vp3.commerce.fulfillment.update'],'commerce.fulfill');
assert.equal(contract.security.buyer_terms_acceptance_cloud_only,true);
assert.equal(contract.security.fulfillment_mutation_requires_local_owner_approval,true);
assert.ok(contract.ownership.homeserver_must_not.includes('create_provider_checkout'));
assert.ok(contract.ownership.homeserver_must_not.includes('refund_payment'));

assert.match(bridge,/VP3_COMMERCE_AGENT_SHA256='b61b1bae/);
assert.match(bridge,/vp3\.commerce\.connector\.configure/);
assert.match(bridge,/profile_commerce_set_fulfillment_v900/,'fulfillment must reuse Phase 9 canonical operation');
assert.match(bridge,/creates_order'=>false/,'handoff must not create an order');
assert.match(bridge,/moves_money'=>false/,'handoff must not move money');
assert.match(bridge,/profile_commerce_visibility_v900/,'handoff must enforce public publication');
assert.match(bridge,/homeserver_commerce_agent_idempotency/,'mutation must be idempotent');
assert.doesNotMatch(bridge,/api\.stripe\.com|connect\.square|api-m\.paypal/,'Agent Commerce must not duplicate provider adapters');
assert.doesNotMatch(bridge,/secret_key|access_token|webhook_secret/,'Agent bridge must not project provider credentials');

assert.match(api,/HTTP_AUTHORIZATION/,'endpoint must authenticate the connector bearer credential');
assert.match(api,/Bearer\\s\+\(\.\+\)/,'endpoint must parse a Bearer authorization header');
assert.match(api,/array_diff\(array_keys\(\$body\),\['operation','arguments'\]\)/,'wire envelope must reject unknown fields');
assert.match(api,/GET_LOCK\(\?,10\)/,'matching fulfillment mutations must be serialized before canonical execution');
assert.match(api,/RELEASE_LOCK\(\?\)/,'fulfillment serialization lock must always be released');
assert.match(approvals,/commerce\.read/);assert.match(approvals,/commerce\.order/);assert.match(approvals,/commerce\.fulfill/);
assert.match(status,/homeserver_commerce_agent_v1000_provision/);
assert.match(status,/homeserver_commerce_agent_v1000_revoke/);
assert.match(status,/commerce_agent_connector/);

console.log('COMMERCE_AGENT_V1_CLOUD_CONTRACT=PASS');
