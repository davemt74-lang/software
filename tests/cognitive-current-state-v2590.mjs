import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const current=read('includes/cognitive-current-state-v2590.php');
const firewall=read('includes/cognitive-presentation-firewall-v2590.php');
const release=read('includes/cognitive-release-v2590.php');
const context=read('includes/cognitive-context-v2420.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const brainApi=read('api/chat-notifications-brain-v240.php');
const briefJs=read('chat-cognitive-presentation-v510.js');
const brainJs=read('chat-notifications-drawer-v240.js');
const chat=read('chat.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_CURRENT_STATE_PRESENTATION_V2590.md');

const checks=[
 ['v25.90 loads after v25.80 calibration',bootstrap.indexOf("cognitive-decision-calibration-v2580.php")<bootstrap.indexOf("cognitive-current-state-v2590.php")],
 ['presentation firewall loads after current-state materializer',bootstrap.indexOf("cognitive-current-state-v2590.php")<bootstrap.indexOf("cognitive-presentation-firewall-v2590.php")],
 ['v25.90 release gate loads after v25.80 gate',bootstrap.indexOf("cognitive-release-v2580.php")<bootstrap.indexOf("cognitive-release-v2590.php")],
 ['v25.90 creates no new durable tables',!/CREATE TABLE|ALTER TABLE|INSERT INTO|UPDATE\s+[a-z_]+\s+SET|DELETE FROM/i.test(current)&&!/CREATE TABLE|ALTER TABLE|INSERT INTO|UPDATE\s+[a-z_]+\s+SET|DELETE FROM/i.test(firewall)],
 ['current state reads canonical event inbox',/FROM agent_event_inbox/.test(current)&&/agent_event_schema_ready_v1920/.test(current)],
 ['current state reads canonical live session',/vp3_live_session_snapshot_v2370/.test(current)],
 ['current-state attention reconciles latest fresh domain state',/vp3_cognitive_current_state_materialize_events_v2590/.test(current)&&/!empty\(\$event\['attention'\]\)&&!empty\(\$event\['fresh'\]\)/.test(current)],
 ['legacy activity is compatibility input only',/agent_activity_v94_snapshot/.test(current)&&/'legacy_activity_compatibility'=>'agent_activity_v94_snapshot'/.test(release)],
 ['raw event payload is reduced to safe refs',/vp3_cognitive_current_state_refs_v2590/.test(current)&&/'raw_event_payloads_exposed'=>false/.test(current)],
 ['presentation firewall is explicit allowlist',/\$allowed=\['type','title','summary','status','next_action','action_label','href','meta'\]/.test(firewall)],
 ['presentation firewall rejects external links',/str_starts_with\(\$href,'\/\/'\)/.test(firewall)&&/'external_links_allowed'=>false/.test(firewall)],
 ['presentation firewall blocks internal evidence families',/system prompt|tool trace|raw json|confidence vector|retrieval labels?/i.test(firewall)&&/raw_internal_evidence_allowed/.test(firewall)],
 ['working context has bounded current-state section',/'current_state'/.test(context)&&/vp3_cognitive_current_state_context_item_v2590/.test(context)],
 ['Agent Brief uses firewalled current state',/vp3_cognitive_presentation_from_current_state_v2590/.test(presentation)&&/current_state_presentation/.test(presentation)],
 ['Proactive Now uses same current-state projection and firewalled presentation',/vp3_cognitive_current_state_projection_v2590/.test(proactive)&&/'current_state'=>'cognitive_current_state_v2590'/.test(proactive)&&/current_state_presentation/.test(proactive)],
 ['Agent Brain API uses same current-state projection and firewalled presentation',/vp3_cognitive_current_state_projection_v2590/.test(brainApi)&&/'current_state'=>\$currentState/.test(brainApi)&&/'current_state_presentation'=>\$currentStatePresentation/.test(brainApi)],
 ['Agent Brief UI renders only presentation object fields',/current_state_presentation/.test(briefJs)&&/Review current state/.test(briefJs)&&!/currentState\.payload|currentState\.raw_json/.test(briefJs)],
 ['Agent Brain UI renders only firewalled current-state presentation',/Unified Current State/.test(brainJs)&&/current_state_presentation/.test(brainJs)&&!/brain\.current_state\s*\|\|/.test(brainJs)&&!/currentStateRecent/.test(brainJs)],
 ['chat cache bust exposes v25.90 current-state build',/cognitive-current-state-v2590-20260923/.test(chat)&&/currentStateBuild/.test(chat)&&/cognitivePresentationAssetBuild/.test(chat)],
 ['release gate preserves one-event-ledger and authority boundaries',/'second_event_ledger'=>false/.test(release)&&/'canonical_event_inbox_remains_authority'=>true/.test(release)&&/'agent_brain_user_facing_state_uses_presentation_firewall'=>true/.test(release)&&/'phase19_remains_claim_lease_execution_receipt_authority'=>true/.test(release)],
 ['v25.90 CI runs PHP and Node gates',/cognitive-current-state-v2590\.php/.test(workflow)&&/cognitive-current-state-v2590\.mjs/.test(workflow)],
 ['Recovery Baseline retains v25.90 gates',/cognitive-current-state-v2590\.php/.test(recovery)&&/cognitive-current-state-v2590\.mjs/.test(recovery)],
 ['production package retains v25.90 runtime files in later releases',/cognitive-current-state-v2590\.php/.test(packageWorkflow)&&/cognitive-presentation-firewall-v2590\.php/.test(packageWorkflow)&&/cognitive-release-v2590\.php/.test(packageWorkflow)],
 ['docs define materialization and presentation firewall boundaries',/ephemeral materialization/i.test(docs)&&/allowlist/i.test(docs)&&/does not create another event ledger/i.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Current State & Presentation Firewall v25.90 gate: ${checks.length}/${checks.length} passed`);
