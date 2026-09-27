import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const mobile=read('includes/tracky-mobile-transition-v278.php');
const cloud=read('includes/tracky-cloud-v270.php');
const api=read('api/tracky-mobile-transitions-v278.php');
const docs=read('docs/TRACKY_V278_MOBILE_TRANSITIONS.md');

assert.match(mobile,/VP3_TRACKY_MOBILE_TRANSITION_PROTOCOL_V278='physical_mobile_transition\.v1'/);
assert.match(mobile,/CREATE TABLE IF NOT EXISTS tracky_cloud_mobile_transitions/);
assert.match(mobile,/CREATE TABLE IF NOT EXISTS tracky_cloud_mobile_transition_history/);
assert.match(mobile,/tracky_v278_mobile_authority/);
assert.match(mobile,/source authority does not match the current topology mirror/);
assert.match(mobile,/revision conflicts with the existing Cloud mirror/);
assert.match(mobile,/destination_site_uuid/);
assert.match(mobile,/'cloud_role'=>'mirror_relay_only'/);
assert.match(mobile,/'world_mutation_authority'=>false/);
assert.match(mobile,/'person_object_identity_linking'=>false/);
assert.match(mobile,/'temporary_context_site_authority'=>false/);
assert.doesNotMatch(mobile,/function\s+tracky_v278_mobile_(?:assign_authority|link_identity|promote_site|mutate_source)/i);

assert.match(cloud,/tracky-mobile-transition-v278\.php/);
assert.match(cloud,/tracky_v278_mobile_normalize/);
assert.match(cloud,/tracky_v278_mobile_ingest/);
assert.match(cloud,/tracky_v278_mobile_build_relay/);
assert.match(cloud,/only transitions authoritative at its local source site/);
assert.match(cloud,/mobile_transition_protocol/);

assert.match(api,/\$_SERVER\['REQUEST_METHOD'\].*GET/);
assert.doesNotMatch(api,/verify_csrf|tracky_v278_mobile_ingest|\b(?:INSERT|UPDATE|DELETE)\b/i);

assert.match(docs,/temporary context/i);
assert.match(docs,/source site.*owns the transition state/i);
assert.match(docs,/Section 5/);
assert.match(docs,/never invented arrival/i);
console.log('TRACKY_V278_MOBILE_TRANSITION_CONTRACT=PASS');
