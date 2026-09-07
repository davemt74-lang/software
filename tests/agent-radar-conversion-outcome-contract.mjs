import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const closure = read('includes/agent-radar-conversion-outcome-v315.php');
const bootstrap = read('includes/bootstrap.php');
const relationship = read('includes/agent-relationship-intelligence.php');
const referral = read('includes/agent-referral-attribution.php');
const cognitive = read('includes/agent-cognitive-loop-v310.php');
const crm = read('includes/agent-crm.php');
const contacts = read('contacts.php');

/* Exact identity chain: opportunity notification -> Radar event -> same agent contact. */
assert.ok(closure.includes("VP3_AGENT_RADAR_CONVERSION_OUTCOME_V315='agent-radar-conversion-outcome-v315-20260907'"), 'Radar conversion closure must be versioned');
assert.ok(closure.includes("sha1('notification|'.$notificationId)"), 'closure identity must exactly match cognitive notification suggestion hashes');
assert.ok(closure.includes("n.type='radar_agent_opportunity'"), 'only Agent Radar opportunity notifications may be closed');
assert.ok(closure.includes("n.source_type='radar_event'"), 'opportunity notification must resolve through its canonical Radar event source');
assert.ok(closure.includes("e.agent_contact_id=?"), 'opportunity correlation must be scoped to the exact Agent CRM contact');
assert.ok(closure.includes("e.event_type='agent_opportunity_detected'"), 'opportunity source event must be the canonical Radar opportunity event');
assert.ok(closure.includes("e.owner_user_id=?"), 'Radar opportunity must be owner scoped');
assert.ok(closure.includes('ORDER BY e.occurred_at DESC,e.id DESC'), 'opportunity candidates must be deterministically newest first');
assert.ok(closure.includes('$candidate=$candidates[0];'), 'only the newest pre-conversion opportunity may own automatic conversion attribution');
assert.ok(closure.includes("'reason'=>'latest-opportunity-'.(string)($cycle['reason']??'not-eligible')"), 'an unsurfaced/final latest opportunity must fail closed instead of falling back to an older recommendation');
assert.ok(!/display_name|operator_name/.test(closure), 'conversion closure must never correlate by contact/operator display names');

/* Conversion evidence is first-party and comes from the existing attribution ledger. */
assert.ok(closure.includes('vp3_agent_referral_events'), 'closure must consume the canonical first-party referral event ledger');
assert.ok(closure.includes("event_type LIKE 'conversion:%'"), 'only explicit conversion events may auto-close as success');
assert.ok(closure.includes("'attribution'=>'first_party_token'"), 'outcome provenance must identify first-party token attribution');
assert.ok(closure.includes("'conversion_referral_event_id'"), 'outcome provenance must retain exact referral-event evidence');
assert.ok(closure.includes("'referral_id'"), 'outcome provenance must retain referral identity');
assert.ok(closure.includes("'agent_contact_id'"), 'outcome provenance must retain CRM/Radar contact identity');
assert.ok(closure.includes("'conversion_event_name'"), 'outcome provenance must retain conversion event name');
assert.ok(closure.includes("'source_url'=>url('/contacts.php#agent-contact-'"), 'outcome provenance must link back to the exact CRM relationship');
assert.ok(referral.includes("'attribution'=>'first_party_token'"), 'existing referral writer must remain the first-party attribution authority');
assert.ok(referral.includes("$conversion?'agent_referral_conversion':'agent_human_referral'"), 'existing Radar attribution event must continue distinguishing conversion from visit');

/* Exposure and final-cycle safeguards prevent unsurfaced or repeated success claims. */
assert.ok(closure.includes("source_kind='radar_agent_opportunity'"), 'only surfaced Radar opportunity recommendation events may train this bridge');
assert.ok(closure.includes("event_type IN ('shown','acted','dismissed')"), 'cycle detection must use canonical proactive event vocabulary');
assert.ok(closure.includes("created_at<=?"), 'recommendation exposure must predate the conversion');
assert.ok(closure.includes("in_array($outcome,['successful','resolved','unsuccessful','ignored'],true)"), 'existing final outcomes must close the exposure cycle');
assert.ok(closure.includes("'reason'=>'already-final'"), 'an already-finalized opportunity must not be counted again');
assert.ok(closure.includes("'reason'=>'new-exposure-after-final'"), 'a genuinely re-surfaced opportunity may start a later cycle');
assert.ok(cognitive.includes("agent_action_v124_mark_shown($user"), 'cognitive Main Feed must create canonical shown evidence after persistence');
assert.ok(cognitive.indexOf("if($conversationId<1)return false;") < cognitive.indexOf("agent_action_v124_mark_shown($user"), 'shown evidence must only be written after Main Feed persistence succeeds');

/* Automatic success writes through the existing learner and nothing else. */
assert.ok(closure.includes("agent_action_v124_record_outcome($user,$hash,'successful','agent_radar_conversion_auto'"), 'verified conversion must reuse canonical v313 Outcome Closure');
assert.ok(closure.includes("'source'=>'radar_agent_opportunity'"), 'source feedback must train the same Radar opportunity source used by Brain ranking');
assert.ok(closure.includes("'trigger'=>'first_party_agent_conversion'"), 'automatic success must retain explicit conversion trigger provenance');
assert.ok(closure.includes("'automatic'=>true"), 'automatic result must be explicitly marked');
assert.ok(!/CREATE TABLE|ALTER TABLE/.test(closure), 'conversion closure must not create another persistence schema');
assert.ok(!/agent_task_v123_update|UPDATE\s+agent_memory_items/i.test(closure), 'conversion learning must not mutate Agent Brain tasks');
assert.ok(!/REMOTE_ADDR|HTTP_X_FORWARDED_FOR|document\.cookie|localStorage|sessionStorage/.test(closure), 'conversion closure must not add identity/fingerprinting collection');

/* A recorded learning result becomes visible in the existing CRM Activity ledger. */
assert.ok(closure.includes('function agent_radar_outcome_v315_crm_audit('), 'recorded Radar outcomes must have one CRM/Radar audit bridge');
assert.ok(closure.includes("'agent_brain_outcome_learned'"), 'learned outcome must use a dedicated Radar activity event type');
assert.ok(closure.includes("'low','','SYSTEM'"), 'learning audit must use an empty path and SYSTEM method so it cannot alter path intent or top-path Analytics');
assert.ok(closure.includes("'source_label'=>'Agent Radar'"), 'learning audit must retain Agent Radar source label');
assert.ok(closure.includes("$result['crm_activity_event_id']=agent_radar_outcome_v315_crm_audit"), 'CRM activity receipt must only be created after canonical outcome persistence succeeds');
assert.ok(crm.includes("FROM vp3_radar_events e"), 'Agent CRM Activity must remain a projection of the canonical Radar ledger');
assert.ok(crm.includes("$row['recent_activity']=$recent[$id]??[];"), 'Agent CRM rows must expose recent Radar activity');
assert.ok(contacts.includes('function renderActivity(agent)'), 'CRM relationship modal must render the existing activity projection');
assert.ok(contacts.includes("words(item.event_type||'activity')"), 'learned outcome event type must be visibly labeled in CRM Activity');
assert.ok(contacts.includes("item.summary||'Agent Radar activity'"), 'learned outcome summary must be visible in CRM Activity');

/* Runtime is bounded and integrated into existing bootstrap. */
assert.ok(bootstrap.includes("require_once __DIR__.'/agent-radar-conversion-outcome-v315.php';"), 'bootstrap must load the conversion closure after canonical action system');
assert.ok(bootstrap.includes('agent_radar_outcome_v315_boot();'), 'owner runtime must reconcile external conversions');
assert.ok(closure.includes('VP3_AGENT_RADAR_CONVERSION_SCAN_SECONDS_V315=60'), 'reconciliation must be cadence bounded');
assert.ok(closure.includes("if(PHP_SAPI==='cli'"), 'automatic boot must remain dormant during CLI tests/jobs');
assert.ok(relationship.includes("'radar_agent_opportunity'"), 'Agent Radar relationship intelligence must continue emitting opportunity notifications');

console.log('AGENT_RADAR_CONVERSION_OUTCOME_CONTRACT=PASS');
