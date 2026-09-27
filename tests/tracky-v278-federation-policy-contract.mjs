import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const policy=read('includes/tracky-federation-policy-v278.php');
const cloud=read('includes/tracky-cloud-v270.php');
const sync=read('includes/tracky-federation-sync-v278.php');
const identity=read('includes/tracky-identity-continuity-v278.php');
const mobile=read('includes/tracky-mobile-transition-v278.php');
const api=read('api/tracky-federation-policy-v278.php');
const docs=read('docs/TRACKY_V278_FEDERATION_POLICY.md');

assert.match(policy,/VP3_TRACKY_FEDERATION_POLICY_PROTOCOL_V278='physical_federation_policy\.v1'/);
for(const table of [
  'tracky_cloud_federation_policy_state',
  'tracky_cloud_federation_site_policies',
  'tracky_cloud_federation_permissions',
  'tracky_cloud_recognition_consents',
  'tracky_cloud_federation_policy_revocations',
  'tracky_cloud_federation_policy_history'
]) assert.match(policy,new RegExp('CREATE TABLE IF NOT EXISTS '+table));

assert.match(policy,/tracky_v278_policy_topology_authority/);
assert.match(policy,/authority does not match the current topology mirror/);
assert.match(policy,/revocation_epoch/);
assert.match(policy,/source_site_policy_missing/);
assert.match(policy,/destination_not_allowed_peer/);
assert.match(policy,/remote_observation_disabled/);
assert.match(policy,/permission_revoked/);
assert.match(policy,/consent_required/);
assert.match(policy,/consent_revoked/);
assert.match(policy,/tracky_v278_policy_filter_world_fragment/);
assert.match(policy,/tracky_v278_policy_build_relay/);
assert.match(policy,/physical_federation_policy_relay\.v1/);
assert.match(policy,/source_policy_authority_stale/);
assert.match(policy,/consent_authority_stale/);
assert.match(policy,/\['type'\].*person|type.*person/s);
assert.match(policy,/\$filtered\['context'\]=\[\]/);
assert.match(policy,/'cloud_role'=>'mirror_relay_enforcer'/);
assert.match(policy,/'cloud_can_grant'=>false/);
assert.match(policy,/'cloud_can_revoke'=>false/);
assert.match(policy,/'cloud_can_change_consent'=>false/);
assert.match(policy,/'raw_perception'=>false/);
assert.doesNotMatch(policy,/function\s+tracky_v278_policy_(?:grant|revoke|change_consent|assign_authority)\s*\(/i);

assert.match(cloud,/tracky-federation-policy-v278\.php/);
assert.match(cloud,/tracky_v278_policy_normalize/);
assert.match(cloud,/tracky_v278_policy_ingest/);
assert.match(cloud,/governing site must match the uploader federation site/);
assert.match(cloud,/federation_policy_protocol/);
assert.match(cloud,/tracky_v278_policy_build_relay/);
assert.match(cloud,/'federation_policy'=>\$federationPolicyRelay/);

assert.match(sync,/tracky_v278_policy_decision\([^;]+semantic_world_read/s);
assert.match(sync,/tracky_v278_policy_filter_world_fragment/);
assert.match(sync,/'policy'=>\[/);
assert.match(sync,/world_projection.*non_person_v1/s);
assert.match(identity,/tracky_v278_policy_decision\([^;]+identity_continuity_read/s);
assert.match(identity,/tracky_v278_policy_recognition_decision\([^;]+identity_linking/s);
assert.match(mobile,/tracky_v278_policy_decision\([^;]+agent_context_read/s);

assert.match(api,/\$_SERVER\['REQUEST_METHOD'\].*GET/);
assert.doesNotMatch(api,/verify_csrf|tracky_v278_policy_ingest|\b(?:INSERT|UPDATE|DELETE)\b/i);

assert.match(docs,/deny-by-default/i);
assert.match(docs,/Direct federated world sharing is intentionally non-person/i);
assert.match(docs,/revocation epoch/i);
assert.match(docs,/Cloud mirrors and enforces/i);
assert.match(docs,/Raw frames, images, video, recordings, audio, embeddings/i);
assert.match(docs,/destination-specific policy mirror/i);
assert.match(docs,/cached remote state/i);

console.log('TRACKY_V278_FEDERATION_POLICY_CONTRACT=PASS');
