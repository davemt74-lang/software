import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');

const bootstrap=read('includes/bootstrap.php');
const bridge=read('includes/cognitive-domain-integration-v2380.php');
const release=read('includes/cognitive-release-v2380.php');
const manifest=read('includes/cognitive-domain-manifest-v2370.php');
const profile=read('includes/profile-agent.php');
const scheduling=read('includes/agent-scheduling-v430.php');
const appointments=read('includes/agent-appointment-lifecycle-v700-part3.php');
const commerce1=read('includes/agent-commerce-v800-part1.php');
const commerce2=read('includes/agent-commerce-v800-part2.php');
const calendar=read('includes/user-calendar-v1300.php');
const relationships=read('includes/agent-relationship-intelligence.php');
const notifications=read('includes/notifications.php');
const cards=read('includes/cognitive-cards-v520.php');
const docs=read('docs/VP3_COGNITIVE_DOMAIN_INTEGRATION_V2380.md');

const checks=[
  ['v23.80 loads after Universal Cards object ownership',bootstrap.indexOf("cognitive-cards-v520.php")<bootstrap.indexOf("cognitive-domain-integration-v2380.php")],
  ['v23.80 release gate loads before proactive/feed presentation',bootstrap.indexOf("cognitive-release-v2380.php")<bootstrap.indexOf("cognitive-proactive-now-v2340.php")],
  ['v23.80 uses canonical event inbox ingress',/agent_event_ingest_v1920/.test(bridge)],
  ['v23.80 does not dispatch domain events directly into Brain',!/agent_event_dispatch_v1920/.test(bridge)&&!/agent_event_brain_observe_v1920/.test(bridge)],
  ['domain events explicitly defer Brain promotion',/brain_promotion_deferred/.test(bridge)&&/'brain_promotion_deferred'=>true/.test(release)],
  ['domain bridge marks record-only ingress processed',/processing_status='processed'/.test(bridge)],
  ['domain bridge projects meaningful external state into open live session',/last_external_event/.test(bridge)&&/vp3_live_session_open_row_v2370/.test(bridge)],
  ['domain events do not call user activity resume path',!/vp3_live_session_record_activity_v2370/.test(bridge)],
  ['Profile Agent central event creator emits into v23.80 bridge',/vp3_cognitive_profile_event_bridge_v2380/.test(profile)],
  ['scheduling mutations emit schedule and availability events',/schedule\.created/.test(scheduling)&&/schedule\.updated/.test(scheduling)&&/availability\.changed/.test(scheduling)],
  ['booking create and cancellation emit canonical events',/booking\.created/.test(scheduling)&&/booking\.cancelled/.test(scheduling)],
  ['appointment lifecycle emits through one central lifecycle bridge',/vp3_cognitive_appointment_lifecycle_bridge_v2380/.test(appointments)],
  ['calendar CRUD emits create update cancel events',/calendar\.event_created/.test(calendar)&&/calendar\.event_updated/.test(calendar)&&/calendar\.event_cancelled/.test(calendar)],
  ['commerce audit is the canonical order payment refund bridge',/vp3_cognitive_commerce_audit_bridge_v2380/.test(commerce1)],
  ['product create update emits at canonical product upsert',/product\.created/.test(commerce2)&&/product\.updated/.test(commerce2)],
  ['relationship intelligence emits opportunity changes',/vp3_cognitive_crm_relationship_bridge_v2380/.test(relationships)],
  ['notification creation and reads emit lifecycle events',/notification\.created/.test(notifications)&&/notification\.read/.test(notifications)],
  ['existing card module still owns product order and contact object types',/'commerce_order','commerce_customer','product','calendar_booking'/.test(cards)&&/'profile_agent_update','contact'/.test(cards)],
  ['v23.80 avoids duplicate registration of Universal Card object types',!/'commerce',\['product','commerce_order'/.test(bridge)&&!/'crm_relationships',\['contact','relationship'/.test(bridge)],
  ['v23.80 registers all first-wave modules',[
    'profile_agent','scheduling','booking_appointments','calendar','commerce','crm_relationships','notifications'
  ].every(x=>bridge.includes(`'${x}'`))],
  ['manifest places notifications in v23.80',/'notifications'=>\[[\s\S]*?'phase'=>'v23\.80'/.test(manifest)],
  ['manifest includes expanded commerce lifecycle',manifest.includes("'order.payment_received'")&&manifest.includes("'order.expired'")&&manifest.includes("'refund.failed'")],
  ['release gate preserves no-second-ledger invariant',/'second_event_ledger'=>false/.test(release)&&/'second_brain'=>false/.test(release)],
  ['docs explicitly preserve authoritative subsystem records',/authoritative for its own records/.test(docs)],
  ['docs explicitly keep domain ingress record-only',/record-only/.test(docs)],
];

for(const [name,ok] of checks){
  assert.equal(ok,true,name);
  console.log('PASS',name);
}
console.log(`Cognitive Domain Integration v23.80 gate: ${checks.length}/${checks.length} passed`);
