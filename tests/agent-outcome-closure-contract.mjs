import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const actionSystem = read('includes/agent-action-system-v124.php');
const api = read('api/agent-proactive-v93.php');
const cognitive = read('includes/agent-cognitive-loop-v310.php');
const ledger = read('includes/agent-proactive-v93.php');

/* Outcome closure extends the existing action learner rather than creating another store. */
assert.ok(actionSystem.includes("STONEFELLOW_AGENT_OUTCOME_CLOSURE_V313='agent-brain-outcome-closure-v313-20260907'"), 'outcome closure must be explicitly versioned');
assert.ok(actionSystem.includes('agent_proactive_events'), 'outcome closure must reuse the existing proactive-event ledger');
assert.ok(!/CREATE TABLE|ALTER TABLE/.test(actionSystem), 'outcome closure must not create a parallel persistence schema');
assert.ok(ledger.includes("event_type IN ('dismissed','acted')") || ledger.includes("['shown','acted','dismissed']"), 'canonical proactive ledger must retain its existing event vocabulary');

/* Final results are normalized into explicit cognitive outcomes. */
assert.ok(actionSystem.includes('function agent_action_v313_normalize_outcome('), 'outcome closure must normalize surface result vocabulary');
for (const outcome of ['successful','resolved','unsuccessful','ignored','acted']) {
  assert.ok(actionSystem.includes(`'${outcome}'`), `outcome closure must understand ${outcome}`);
}
assert.ok(actionSystem.includes("$context['outcome']=$outcome;"), 'final outcome must be persisted in the existing event context');
assert.ok(actionSystem.includes("$context['outcome_build']=STONEFELLOW_AGENT_OUTCOME_CLOSURE_V313"), 'event context must retain the outcome-closure provenance');
assert.ok(actionSystem.includes("$canonicalEvent=$outcome==='ignored'?'dismissed':'acted';"), 'final results must remain compatible with the canonical acted/dismissed ledger');
assert.ok(actionSystem.includes('agent_action_v313_recent_outcome_exists'), 'duplicate outcome posts must be suppressed');
assert.ok(actionSystem.includes('agent_action_v313_recover_source'), 'final outcome calls must recover the original source when only a suggestion hash is supplied');
assert.ok(actionSystem.includes("return ['recorded'=>false,'reason'=>'missing-hash'];"), 'outcome closure must reject missing suggestion identity');

/* Aggregation avoids double-counting an act that is later closed by a final result. */
assert.ok(actionSystem.includes('SELECT suggestion_hash,event_type,context_json,created_at'), 'feedback aggregation must retain suggestion identity while reading outcomes');
assert.ok(actionSystem.includes('$finalized=[];$unresolvedCounted=[];'), 'feedback aggregation must distinguish finalized and unresolved attempts');
assert.ok(actionSystem.includes('isset($finalized[$hash])||isset($unresolvedCounted[$hash])'), 'older unresolved act events must not double-count a later final outcome');
assert.ok(actionSystem.includes("'acted_unresolved'=>0"), 'feedback must expose unresolved acts separately from final outcomes');
assert.ok(actionSystem.includes("'successful'=>0,'resolved'=>0,'unsuccessful'=>0,'ignored'=>0"), 'feedback must expose final outcome counts');

/* Learning strength follows the real result rather than treating every click as success. */
assert.ok(actionSystem.includes('// Final outcomes are stronger learning evidence than a click/act event.'), 'learning policy must explicitly distinguish final results from clicks');
assert.ok(actionSystem.includes('$successful*1.0'), 'successful outcomes must provide strong positive evidence');
assert.ok(actionSystem.includes('$resolved*0.85'), 'resolved outcomes must provide strong positive evidence');
assert.ok(actionSystem.includes('$actedUnresolved*0.20'), 'unresolved acts must provide only weak positive evidence');
assert.ok(actionSystem.includes('$unsuccessful*1.0'), 'unsuccessful outcomes must provide strong negative evidence');
assert.ok(actionSystem.includes('$ignored*0.50'), 'ignored outcomes must provide gentler negative evidence');
assert.ok(actionSystem.includes('max(0.55,min(1.25,$factor))'), 'learned source weighting must remain bounded');
assert.ok(actionSystem.includes("$result['outcome_learning']='v313'"), 'proactive results must advertise the new learning contract');
assert.ok(actionSystem.includes("$action['outcome_summary']=agent_action_v313_outcome_summary($sourceFeedback)"), 'visible proactive actions must expose source outcome evidence');

/* The canonical cognitive loop automatically consumes the upgraded source factor. */
assert.ok(cognitive.includes('agent_action_v124_source_feedback'), 'Agent Brain ranking must still consume canonical source feedback');
assert.ok(cognitive.includes('agent_action_v124_outcome_factor'), 'Agent Brain ranking must use the upgraded final-outcome factor');
assert.ok(cognitive.includes("$candidate['outcome_factor']=round($factor,4);"), 'Brain priority evidence must retain the learned factor');
assert.ok(cognitive.includes('learned source factor'), 'existing Brain explainability must visibly expose that learned factor');

/* API remains backward compatible while allowing explicit closure. */
assert.ok(api.includes("$outcomeActions=['acted','dismissed','successful','resolved','unsuccessful','ignored','outcome'];"), 'proactive API must accept old and final outcome actions');
assert.ok(api.includes("$requestedOutcome=$action==='outcome'?"), 'generic outcome action must accept an explicit outcome value');
assert.ok(api.includes("'runtime'=>'outcome-closure-v313'"), 'API must identify the final-outcome runtime');
assert.ok(api.includes("if(empty($result['recorded'])&&empty($result['duplicate']))"), 'API must reject invalid outcome writes while treating deduped retries as success');

/* Task lifecycle remains explicit: final outcome alone cannot silently close a task. */
assert.ok(actionSystem.includes("!empty($payload['task_status'])"), 'linked task mutation must require an explicit task status');
assert.ok(!/outcome==='successful'[^\n]{0,180}agent_task_v123_update/.test(actionSystem), 'success must not silently complete a linked task');
assert.ok(!/outcome==='ignored'[^\n]{0,180}agent_task_v123_update/.test(actionSystem), 'ignored must not silently cancel a linked task');

console.log('AGENT_OUTCOME_CLOSURE_CONTRACT=PASS');
