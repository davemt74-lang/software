import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const spec=read('docs/VP3_COGNITIVE_RUNTIME_V500.md');
const contractText=read('contracts/cognitive/cognitive-runtime-v1/contract.json');
const contract=JSON.parse(contractText);
const pinned=read('contracts/cognitive/cognitive-runtime-v1/SHA256').trim().split(/\s+/)[0];
const actual=crypto.createHash('sha256').update(contractText).digest('hex');

assert.equal(contract.contract,'cognitive-runtime-v1');
assert.equal(contract.architecture_version,'5.00');
assert.equal(pinned,actual,'cognitive-runtime-v1 digest must match contract.json');

assert.deepEqual(contract.principal.dimensions,['user_id','agent_namespace']);
assert.equal(contract.principal.llm_grants_authority,false);
assert.equal(contract.security.authorize_before_context,true);
assert.equal(contract.security.reauthorize_before_render,true);
assert.equal(contract.security.reauthorize_before_action,true);
assert.equal(contract.security.unknown_tool_rejected,true);
assert.equal(contract.security.unknown_action_rejected,true);
assert.equal(contract.evidence.chain_of_thought_persisted,false);

for(const stage of ['observe','normalize','authorize','correlate','retrieve','reason','verify','prioritize','propose','present_or_act','learn']){
  assert.ok(contract.pipeline.includes(stage),'missing cognitive pipeline stage '+stage);
}
for(const category of ['opportunity','risk','commitment','unanswered_question','forecast','decision_support','action_plan']){
  assert.ok(contract.observation.categories.includes(category),'missing observation category '+category);
}
for(const outcome of ['none','memory','brief','away_digest','notification','voice_announce','ask_user','chat_response']){
  assert.ok(contract.presentation.outcomes.includes(outcome),'missing presentation outcome '+outcome);
}
assert.equal(contract.presentation.automatic_individual_chat_turns,false);
assert.equal(contract.presentation.default_idle_summary_minutes,60);
assert.equal(contract.presentation.minimum_attention_idle_minutes,30);
assert.equal(contract.presentation.no_material_change_no_summary,true);

for(const card of ['transcription','recording','annotation','team_activity','human_message','profile_agent_update','commerce_order','calendar_booking','meeting','meeting_brief','meeting_summary','workflow','goal','commitment','opportunity','decision','homeserver','browser_companion']){
  assert.ok(contract.cards.initial_types.includes(card),'missing card type '+card);
}
assert.equal(contract.cards.llm_generates_html,false);
assert.equal(contract.cards.render_reauthorizes,true);
assert.equal(contract.cards.actions_server_derived,true);

assert.deepEqual(contract.meetings.default_reminders_minutes,[60,15,5]);
for(const stage of ['scheduled','preparation_window','reminder_due','imminent','active','ended','followup','continuity']){
  assert.ok(contract.meetings.lifecycle.includes(stage),'missing meeting lifecycle stage '+stage);
}
assert.equal(contract.meetings.voice_reminders_supported,true);

assert.equal(contract.model_routing.uses_agent_runtime_v420,true);
assert.equal(contract.model_routing.full_database_context,false);
assert.equal(contract.model_routing.strict_structured_output,true);
assert.equal(contract.model_routing.direct_tool_execution_from_model,false);

assert.equal(contract.specialists.may_interrupt_user,false);
assert.equal(contract.specialists.primary_agent_owns_presentation,true);
assert.equal(contract.integrations.v310_priority_chat_injection_when_v500_active,'disabled');

for(const phrase of [
  'Analyze broadly. Surface selectively. Act only through deterministic authority.',
  'Universal Display Card contract',
  'Presentation Arbiter',
  'Idle and return digest',
  'Meeting Intelligence lifecycle',
  'Agent Brief',
  'Voice Presentation',
  'Opportunity-first behavior',
  'Capability Registry',
  'Context Graph',
  'Model output contract',
  'Evaluation harness'
]){
  assert.ok(spec.toLowerCase().includes(phrase.toLowerCase()),'spec missing '+phrase);
}

assert.match(spec,/agent_cognitive_loop_v310_surface\(\)/);
assert.match(spec,/automatic main-feed injection is disabled/i);
assert.match(spec,/does not persist private model chain-of-thought/i);
assert.match(spec,/re-authorizes the object/i);
assert.match(spec,/one \*\*While you were away\*\* digest/i);
assert.match(spec,/Agent Voice enabled/i);
assert.match(spec,/Pre-Meeting Brief card/i);
assert.match(spec,/meeting starts in 15 minutes/i);
assert.match(spec,/new product modules can register events\/objects\/cards\/tools without editing the central Chat renderer/i);

console.log('VP3 Cognitive Runtime v5.00 specification contract: PASS');
