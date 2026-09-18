import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const core=read('includes/cognitive-runtime-v500.php');
const meetings=read('includes/cognitive-runtime-meetings-v500.php');
const api=read('api/cognitive-runtime-v500.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const moduleContract=JSON.parse(read('contracts/cognitive/modules/meeting-intelligence-v1/contract.json'));

assert.match(core,/VP3_COGNITIVE_CONTRACT_V500='cognitive-runtime-v1'/);
for(const fn of [
  'vp3_cognitive_register_module_v500',
  'vp3_cognitive_object_ref_v500',
  'vp3_cognitive_context_for_ref_v500',
  'vp3_cognitive_relationships_for_ref_v500',
  'vp3_cognitive_validate_event_envelope_v500',
  'vp3_cognitive_event_from_agent_event_v500',
  'vp3_cognitive_context_packet_v500',
  'vp3_cognitive_validate_observation_v500',
  'vp3_cognitive_observation_store_v500',
  'vp3_cognitive_presentation_decide_v500',
  'vp3_cognitive_render_card_v500',
  'vp3_cognitive_relationship_upsert_v500'
]) assert.match(core,new RegExp('function '+fn+'\\b'),fn+' missing');

for(const table of ['cognitive_observations_v500','cognitive_presentations_v500','cognitive_relationships_v500']){
  assert.match(core,new RegExp(table),table+' missing');
}
assert.match(core,/Unknown cognitive observation field/,'model output must reject unknown fields');
assert.match(core,/Unregistered cognitive action/,'model actions must be registry-bound');
assert.match(core,/Unregistered cognitive card type/,'model cards must be registry-bound');
assert.match(core,/Cognitive observation evidence is not authorized|Observation evidence is not authorized/);
assert.match(core,/Cognitive card access denied/);
assert.match(core,/str_starts_with\(\$target,'\/'\)/,'card URLs must be internal');
assert.doesNotMatch(core,/openai|anthropic|gemini|curl_exec/i,'11B.1 core must not invoke a model provider');
assert.doesNotMatch(core,/agent_chat_v101_append_ecosystem_message/,'11B.1 must not inject background cognition into Chat');

for(const surface of ['none','memory','brief','away_digest','notification','voice_announce','ask_user','chat_response']){
  assert.match(core,new RegExp("'"+surface+"'"),'missing presentation surface '+surface);
}
assert.match(core,/\$idle>=60/,'normal idle digest threshold must be 60 minutes');
assert.match(core,/\$idle>=30/,'attention idle threshold must be 30 minutes');
assert.match(core,/agent_voice_enabled/,'presentation must consume canonical Agent Voice state');
assert.match(core,/voice_candidate_allowed/,'LLM recommendation alone must not authorize spoken interruption');
assert.match(core,/paired_surface'=>'notification'/,'voice presentation must retain a paired visual notification');

assert.match(meetings,/vp3_cognitive_register_meetings_v500/);
for(const objectType of ['meeting','meeting_brief','meeting_summary','meeting_followup'])assert.match(meetings,new RegExp("'"+objectType+"'"));
for(const tool of ['meeting.context','meeting.prepare_brief','meeting.draft_followup'])assert.match(meetings,new RegExp(tool.replace('.','\\.')));
assert.match(meetings,/video_meeting_access_v1800/,'meeting access must reuse canonical meeting authorization');
assert.match(meetings,/video_meeting_intelligence_public_state_v1820/,'meeting cognition must reuse canonical Meeting Intelligence');
assert.match(meetings,/video_meeting_transcription_session_v1800/,'meeting cognition must reuse canonical transcription linkage');
assert.match(meetings,/decisions_and_commitments/,'meeting context must map the canonical combined decisions + commitments snapshot');
assert.match(meetings,/\['questions'\]/,'meeting context must use the canonical questions snapshot');
assert.doesNotMatch(meetings,/CREATE TABLE|ALTER TABLE/,'meeting adapter must not create a parallel meeting store');

assert.equal(moduleContract.runtime_contract,'cognitive-runtime-v1');
assert.equal(moduleContract.module,'meetings');
assert.equal(moduleContract.privacy.organizer_private_notes_are_owner_only,true);
assert.equal(moduleContract.presentation.voice_decision_owned_by_runtime,true);

assert.match(api,/render_card/);
assert.match(api,/context/);
assert.doesNotMatch(api,/observation_store|presentation_decide/,'public API must not let clients inject cognition or choose presentation');
assert.doesNotMatch(core,/CREATE TABLE IF NOT EXISTS cognitive_events_v500/,'11B.1 must reuse agent_event_inbox instead of creating a parallel event ledger');

assert.match(bootstrap,/cognitive-runtime-v500\.php/);
assert.match(bootstrap,/cognitive-runtime-meetings-v500\.php/);
assert.match(upgrade,/vp3_cognitive_schema_ready_v500\(\)/);
assert.match(upgrade,/vp3_cognitive_ensure_schema_v500\(\$pdo\)/);

console.log('VP3 Phase 11B.1 Cognitive Runtime Core v5.00 contract: PASS');
