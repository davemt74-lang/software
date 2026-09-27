import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const identity=read('includes/tracky-identity-continuity-v278.php');
const cloud=read('includes/tracky-cloud-v270.php');
const api=read('api/tracky-identity-continuity-v278.php');
const docs=read('docs/TRACKY_V278_IDENTITY_CONTINUITY.md');

assert.match(identity,/VP3_TRACKY_IDENTITY_CONTINUITY_PROTOCOL_V278='physical_identity_continuity\.v1'/);
assert.match(identity,/CREATE TABLE IF NOT EXISTS tracky_cloud_canonical_identities/);
assert.match(identity,/CREATE TABLE IF NOT EXISTS tracky_cloud_identity_links/);
assert.match(identity,/CREATE TABLE IF NOT EXISTS tracky_cloud_identity_blocked_pairs/);
assert.match(identity,/CREATE TABLE IF NOT EXISTS tracky_cloud_identity_history/);
assert.match(identity,/tracky_v278_identity_authority/);
assert.match(identity,/governing authority does not match the current topology mirror/);
assert.match(identity,/semantic_hash/);
assert.match(identity,/revision conflicts with the existing Cloud mirror/);
assert.match(identity,/left_site_uuid/);
assert.match(identity,/right_site_uuid/);
assert.match(identity,/governing_site_uuid<>/,'Cloud must not self-relay a governing site identity link');
assert.match(identity,/'cloud_role'=>'mirror_relay_only'/);
assert.match(identity,/'world_mutation_authority'=>false/);
assert.match(identity,/'cloud_can_confirm_links'=>false/);
assert.match(identity,/'cloud_can_merge_identities'=>false/);
assert.match(identity,/'cloud_can_split_identities'=>false/);
assert.doesNotMatch(identity,/function\s+tracky_v278_identity_(?:confirm|merge|split|reject|revoke)_/i);

assert.match(cloud,/tracky-identity-continuity-v278\.php/);
assert.match(cloud,/tracky_v278_identity_normalize/);
assert.match(cloud,/tracky_v278_identity_ingest/);
assert.match(cloud,/tracky_v278_identity_build_relay/);
assert.match(cloud,/identity decisions governed by its local site/);
assert.match(cloud,/requires a resolved local federation site/);
assert.match(cloud,/identity_continuity_protocol/);

assert.match(api,/\$_SERVER\['REQUEST_METHOD'\].*GET/);
assert.doesNotMatch(api,/verify_csrf|tracky_v278_identity_ingest|\b(?:INSERT|UPDATE|DELETE)\b/i);

assert.match(docs,/never silently merged/i);
assert.match(docs,/underlying site-local entity refs are never rewritten/i);
assert.match(docs,/mirror\/relay only/i);
assert.match(docs,/member of that link/i);
console.log('TRACKY_V278_IDENTITY_CONTINUITY_CONTRACT=PASS');
