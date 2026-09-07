import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path,'utf8');
const deep = read('includes/transcription-deeper-intelligence.php');
const advanced = read('includes/transcription-deeper-advanced.php');
const items = read('includes/transcription-deeper-items.php');
const chat = read('includes/transcription-deeper-chat.php');
const report = read('includes/transcription-deeper-report.php');
const api = read('api/artist-listening-intelligence-v300.php');
const client = read('artist-listening-ai.js');
const page = read('artist-listening.php');
const baseline = read('tools/run_recovery_baseline.py');

/* v307 deepens the existing registry/persistence stack instead of creating a parallel product. */
assert.match(deep,/VP3_TRANSCRIPTION_DEEPER_V307 = 'transcription-deeper-intelligence-v307-20260907'/);
assert.match(deep,/\$registry=transcription_app_registry_v306\(\)/,'v307 must extend the proven v306 registry');
assert.match(deep,/transcription_output_projection_v306\(\$modules\)/,'v307 persistence must build on v306 output projection');
assert.match(deep,/\$projection\['registry_version'\]=307/);
assert.match(deep,/\$projection\['deeper_intelligence_version'\]=307/);
for (const source of [deep,advanced,items,chat,report]) {
  assert.doesNotMatch(source,/CREATE TABLE|ALTER TABLE/,'v307 must not introduce a new persistence schema');
}
assert.doesNotMatch(deep,/['"]deep_intelligence['"]\s*=>/,'v307 must deepen existing tabs rather than register a duplicate deep-intelligence plugin');

/* Knowledge intelligence classifies against Personal Knowledge + Agent Brain before durable promotion. */
for (const state of ['new','duplicate','more_specific','updates_existing','conflicting','temporary']) {
  assert.ok(deep.includes(`'${state}'`), `knowledge state ${state} must be represented`);
}
assert.match(deep,/function transcription_deeper_knowledge_candidates_v307/);
assert.match(deep,/search_knowledge\(\$text,\$user/,'Personal Knowledge must be part of duplicate/update detection');
assert.match(deep,/FROM agent_memory_items/,'Agent Brain must be part of duplicate/update detection');
assert.match(deep,/memory_type NOT IN \('conversation_state','conversation_summary'\)/,'conversation rollups must not masquerade as durable knowledge matches');
assert.match(items,/in_array\(\$action,\['agent_brain','personal_knowledge'\],true\)/,'both long-term memory actions must pass the v307 knowledge guard');
assert.match(items,/\$state==='duplicate'/);
assert.match(items,/\$state==='temporary'/);
assert.match(report,/!in_array\(\$state,\['duplicate','temporary'\],true\)/,'whole-report long-term saves must omit duplicate and temporary knowledge');

/* CRM intelligence stays explicitly matched and read-only during analysis while comparing canonical history/tasks. */
assert.match(deep,/function transcription_deeper_crm_history_v307/);
assert.match(deep,/FROM crm_activities WHERE lead_id=\?/);
assert.match(deep,/FROM crm_tasks WHERE lead_id=\?/);
for (const section of ['relationship_changes','objections','buying_signals','promises','next_best_actions']) {
  assert.ok(deep.includes(`'key'=>'${section}'`), `${section} must be a CRM intelligence section`);
}
assert.match(deep,/transcription_app_crm_context_v301/,'v307 CRM must inherit the explicit ID\/exact-email match boundary');
const deepAnalyzeStart = deep.indexOf('function transcription_app_analyze_v307');
assert.ok(deepAnalyzeStart >= 0,'v307 analyzer must exist');
const deepAnalyzeBlock = deep.slice(deepAnalyzeStart);
assert.doesNotMatch(deepAnalyzeBlock,/crm_v180_upsert_contact\(|crm_v180_activity\(|crm_v180_create_task\(/,'analysis must never mutate CRM records');

/* Opportunity scoring is deterministic and sortable. */
for (const field of ['value_score','effort_score','urgency_score','confidence_score','opportunity_score','rank','validation_step']) {
  assert.ok(deep.includes(`'${field}'`), `opportunity intelligence must expose ${field}`);
}
assert.match(deep,/\(\$valueScore\*\.40\)\+\(\$urgencyScore\*\.25\)\+\(\$confidenceScore\*\.25\)\+\(\(100-\$effortScore\)\*\.10\)/,'opportunity score weighting must be deterministic');
assert.match(deep,/usort\(\$rows,static fn\(array \$a,array \$b\):int=>\(int\)\(\$b\['opportunity_score'\]/,'opportunities must sort highest score first');
assert.match(deep,/\$item\['rank'\]=\$index\+1/);

/* Claim verification uses stored public research only, with a server-side source-number allowlist. */
assert.match(advanced,/function transcription_deeper_verify_claims_v307/);
for (const state of ['verified','mixed','unsupported','unresolved']) assert.ok(advanced.includes(state),`claim verification must support ${state}`);
assert.match(advanced,/using ONLY the supplied PUBLIC RESEARCH and PUBLIC SOURCES/);
assert.match(advanced,/Never use Agent Brain, Personal Knowledge, CRM, private context or outside unstated knowledge/);
assert.match(advanced,/\$number>0 && \$number<=count\(\$publicSources\)/,'source numbers must resolve to stored public sources');
assert.match(advanced,/in_array\(\$verification,\['verified','mixed','unsupported'\],true\) && !\$numbers\) \$verification='unresolved'/,'unsupported source citations must downgrade verification');
assert.match(advanced,/'research_date'=>\$date/);
assert.doesNotMatch(advanced,/web_search_20260318|tools.*web_search/,'v307 claim verification must not launch a second hidden web-search path');

/* Advanced comparison resolves only explicit, authorized baselines and validates item IDs. */
assert.match(advanced,/function transcription_deeper_resolve_comparison_v307/);
for (const mode of ['project_history','previous','selected_transcript','accepted_intelligence']) assert.ok(advanced.includes(`'${mode}'`),`comparison mode ${mode} must exist`);
assert.match(advanced,/artist_listening_v172_session\(\$pdo,\$user,\$sessionId\)/,'selected transcript loading must enforce canonical ownership');
assert.match(advanced,/s\.project_track_id=\?/);
assert.match(advanced,/s\.conversation_id=\?/);
assert.match(advanced,/created_by_user_id=\?/,'previous/project-history comparison must stay owner-scoped');
assert.match(advanced,/function transcription_deeper_compare_v307/);
for (const section of ['new','changed','contradicted','resolved','still_open']) assert.ok(advanced.includes(`'${section}'`),`${section} comparison bucket must exist`);
assert.match(advanced,/isset\(\$currentIndex\[\$id\]\)/,'current item IDs must resolve against the current catalog');
assert.match(advanced,/isset\(\$baseIndex\[\$id\]\)/,'baseline item IDs must resolve against the explicit baseline catalog');
assert.match(advanced,/\$section==='new' && !\$currentIds\) continue/);
assert.match(advanced,/in_array\(\$section,\['changed','contradicted','still_open'\],true\) && \(!\$currentIds \|\| !\$baseIds\)\) continue/);
assert.match(advanced,/\$section==='resolved' && !\$baseIds\) continue/);
assert.match(advanced,/comparison_hash/,'comparison freshness must bind to the explicit baseline');

/* Deep enrichment is opt-in through the existing workflow depth. */
assert.match(deep,/function transcription_app_analyze_v307/);
assert.match(deep,/\(string\)\(\$workflow\['depth'\]\?\?'standard'\)!=='deep'/,'non-deep runs must preserve the v306 path without extra enrichment');
assert.match(deep,/transcription_app_analyze_v306/,'v307 must delegate the base run to v306');
assert.match(advanced,/research_context_changed/);
assert.match(advanced,/crm_history_changed/);
assert.match(advanced,/comparison_context_changed|comparison_hash/);

/* Main Chat receives only accepted, high-value deep signals through the existing persisted chat path. */
assert.match(chat,/function transcription_deeper_chat_signals_v307/);
assert.match(chat,/\(string\)\(\$item\['review_state'\]\?\?''\\)!=='accepted'/,'deep chat signals must be review-gated');
for (const signal of ['knowledge_','crm_buying_signal','crm_objection','crm_promise','crm_next_action','risk_high','follow_up_open','ranked_opportunity','claim_']) {
  assert.ok(chat.includes(signal),`Main Chat bridge must support ${signal}`);
}
assert.match(chat,/agent_chat_v101_append_ecosystem_message\(/,'deep notices must use canonical Main Chat persistence');
assert.match(chat,/agent_brain_v122_memory\(/,'dedupe must read the existing Agent Brain notice memory');
assert.match(chat,/transcription_deep_notice/,'deep notice fingerprint must be persisted for dedupe');
assert.doesNotMatch(chat,/INSERT INTO chat_messages|CREATE TABLE/,'v307 must not create a parallel inbox/chat persistence path');

/* Durable v307 rows retain stable item/review/action semantics. */
assert.match(items,/transcription_intelligence_normalize_modules_v302\(\$modules,\$priorReviewIndex\)/,'v307 adapter must preserve proven v302 normalization first');
assert.match(items,/transcription_app_registry_v307\(\)/);
assert.match(items,/\$item\['item_id'\]=\$itemId/);
assert.match(items,/\$item\['review_state'\]=\$state/);
assert.match(items,/\$item\['evidence_refs'\]=transcription_intelligence_evidence_refs_v302\(\$item\)/);

/* Stable API remains one URL and routes through every v307 helper. */
assert.match(api,/vp3-transcription-intelligence-v307-20260907/);
for (const include of ['transcription-deeper-intelligence.php','transcription-deeper-items.php','transcription-deeper-advanced.php','transcription-deeper-chat.php','transcription-deeper-report.php']) {
  assert.ok(api.includes(include),`${include} must be loaded by the stable API`);
}
assert.match(api,/transcription_app_registry_public_v307\(\)/);
assert.match(api,/transcription_app_analyze_v307\(/);
assert.match(api,/transcription_deeper_advanced_status_v307\(/);
assert.match(api,/comparison_targets/);
assert.match(api,/transcription_deeper_report_text_v307\(/,'whole-report saves must use the v307 knowledge filter');
assert.match(api,/'source'=>'transcription-intelligence-v307'/);
assert.doesNotMatch(api,/\$result\s*=\s*transcription_app_analyze_v306\(/,'stable API must not bypass the v307 wrapper');

/* Canonical frontend owns comparison selection without a second controller/catalog. */
assert.match(client,/const BUILD = 'transcription-deeper-v307-20260907'/);
assert.match(page,/artist-listening-ai\.js\?v=transcription-deeper-v307-20260907/);
assert.equal((page.match(/artist-listening-ai\.js\?/g)||[]).length,1,'canonical AI controller must load exactly once');
assert.match(client,/data-listening-ai-comparison/);
assert.match(client,/if \(Array\.isArray\(data\.comparison_targets\)\) setComparisonTargets\(data\.comparison_targets\)/,'comparison choices must come from server data');
assert.match(client,/comparison:comparisonPayload\(\)/,'Analyze must send the explicit comparison target');
assert.match(client,/setComparison:targetId=>setComparisonTarget/,'controller proof API must expose explicit comparison selection');
assert.doesNotMatch(client,/const COMPARISON_|const comparisonTargets\s*=\s*\[/,'browser must not duplicate the server comparison catalog');
assert.doesNotMatch(client,/document\.addEventListener\('click'|MutationObserver|MediaRecorder/,'canonical AI controller ownership boundaries must remain intact');
assert.doesNotMatch(page,/artist-listening-ai\.js\?v=transcription-relations-v305-20260906/,'retired v305 cache identity must be removed after the v307 controller change');

assert.match(baseline,/tests\/transcription-deeper-intelligence\.mjs/,'v307 contract must run in Recovery Baseline');
console.log('VP3 transcription deeper intelligence contract: PASS');
