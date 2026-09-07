import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path,'utf8');
const outputs = read('includes/transcription-intelligence-outputs.php');
const api = read('api/artist-listening-intelligence-v300.php');
const client = read('artist-listening-ai.js');
const page = read('artist-listening.php');
const items = read('includes/transcription-intelligence-items.php');
const relations = read('includes/transcription-intelligence-relations.php');
const baseline = read('tools/run_recovery_baseline.py');

/* v306 adds final outputs without a new persistence or frontend layer. */
assert.match(outputs,/VP3_TRANSCRIPTION_INTELLIGENCE_OUTPUTS_V306 = 'transcription-intelligence-outputs-v306-20260906'/);
assert.doesNotMatch(outputs,/CREATE TABLE|ALTER TABLE|INSERT INTO/,'Summary/Action Plan must reuse the existing master-analysis JSON');
assert.match(outputs,/function transcription_output_persist_modules_v306/);
assert.match(outputs,/UPDATE artist_transcript_master_analysis_v237 SET analysis_json=\?/,'outputs must persist into the canonical master analysis row');
assert.match(outputs,/function transcription_app_registry_v306/);
assert.match(outputs,/array_replace\(transcription_app_registry_v301\(\),transcription_output_registry_v306\(\)\)/,'v306 must extend the existing registry rather than replace it');

/* Summary and Action Plan are first-class manual-only registry modules. */
for (const [id,title] of [['summary_output','Summary'],['action_plan','Action Plan']]) {
  assert.ok(outputs.includes(`'${id}'=>[`), `${title} output must be registered`);
  const start=outputs.indexOf(`'${id}'=>[`);
  assert.ok(outputs.slice(start,start+1200).includes("'live'=>false"), `${title} must remain manual-only`);
  assert.ok(outputs.slice(start,start+1200).includes("'execution'=>'ai'"), `${title} is an explicit AI-derived output`);
}
for (const section of ['overview','key_points','decisions','risks','open_questions','next_steps','objective','actions','blockers','follow_ups']) {
  assert.ok(outputs.includes(`'key'=>'${section}'`), `${section} output section must be structured`);
}

/* Reviewed current intelligence is the only factual source. */
assert.match(outputs,/function transcription_output_reviewed_input_v306/);
assert.match(outputs,/!hash_equals\(\$currentHash,\(string\)\(\$module\['source_hash'\]\?\?''\)\)/,'output source catalog must reject stale plugin modules');
assert.match(outputs,/\(string\)\(\$item\['review_state'\]\?\?''\\)!=='accepted'/,'only human-accepted durable intelligence items may enter the output catalog');
assert.match(outputs,/\(string\)\(\$relation\['review_state'\]\?\?''\)==='accepted'/,'only accepted v305 connections may enter output context');
assert.match(outputs,/!isset\(\$items\[\$otherId\]\)/,'accepted connections must link two accepted source items');
assert.match(outputs,/The accepted intelligence items below are the only factual source/);
assert.match(outputs,/Do not use raw transcript text, unreviewed findings, rejected findings, Agent Brain, CRM or outside knowledge/);
assert.match(outputs,/ACCEPTED CONNECTIONS may explain how accepted items relate, but they are not independent facts/);

/* Every generated row must resolve to exact reviewed source IDs, with evidence derived server-side. */
assert.match(outputs,/Every output row must include source_item_ids/);
assert.match(outputs,/isset\(\$sources\[\$sourceId\]\)/,'provider source IDs must resolve against the reviewed source catalog');
assert.match(outputs,/if \(!\$sourceIds\) continue/,'unsupported output rows must be dropped rather than accepted without provenance');
assert.match(outputs,/\$clean\['source_item_ids'\]=\$sourceIds/);
assert.match(outputs,/\$clean\['source_plugins'\]=array_values\(\$plugins\)/);
assert.match(outputs,/\$clean\['evidence'\]=implode/,'output evidence labels must be derived from the accepted source items');
assert.match(outputs,/use unknown/,'unsupported owner/timing must remain unknown');

/* Output freshness follows reviewed state, not just transcript text. */
assert.match(outputs,/function transcription_output_input_hash_v306/);
assert.match(outputs,/\['source_hash'=>\$currentHash,'items'=>\$input\['items'\],'relations'=>\$input\['relations'\]\]/,'input hash must include current source + accepted items + accepted connections');
assert.match(outputs,/'context_hash'=>\$inputHash/,'persisted outputs must bind to the reviewed-input hash');
assert.match(outputs,/reviewed_intelligence_changed/,'accepted item/relation changes must stale prior outputs');
assert.match(outputs,/hash_equals\(\$inputHash,\(string\)\(\$module\['context_hash'\]\?\?''\)\)/);

/* Existing valid outputs survive provider failure and older write paths. */
assert.match(outputs,/Existing output was not overwritten/,'invalid provider output must be explicitly non-destructive');
const persistIndex=outputs.indexOf("$master['analysis']=transcription_output_persist_modules_v306");
const noExecutedGuard=outputs.indexOf('if (!$executed && $errors) throw new RuntimeException');
assert.ok(noExecutedGuard>=0 && noExecutedGuard<persistIndex,'a fully invalid output response must fail before persistence');
assert.match(outputs,/function transcription_output_only_modules_v306/);
assert.match(outputs,/function transcription_output_restore_v306/);
for (const action of ['build_relations','review_relation','review_item','edit_item','item_action']) {
  const start=api.indexOf(action);
  assert.ok(start>=0,`${action} API path must exist`);
}
assert.ok((api.match(/transcription_output_restore_v306/g)||[]).length>=4,'legacy relation/item/action writes must restore preserved output modules');
assert.match(api,/transcription_output_normalize_master_v306/,'status/loading must normalize durable items without dropping v306 outputs');

/* Stable API routes analysis/status/registry through v306 while browser remains the proven registry-driven v305 owner. */
assert.match(api,/transcription-intelligence-outputs\.php/);
assert.match(api,/vp3-transcription-intelligence-v306-20260906/);
assert.match(api,/transcription_app_registry_public_v306\(\)/);
assert.match(api,/transcription_app_status_v306\(/);
assert.match(api,/transcription_app_analyze_v306\(/);
assert.doesNotMatch(api,/\$result = transcription_app_analyze_v304\(/,'stable API must not bypass the v306 output wrapper');
assert.match(api,/'source'=>'transcription-intelligence-v306'/,'whole-report Brain provenance must identify the current runtime');
assert.match(client,/const BUILD = 'transcription-relations-v305-20260906'/,'Section 5 must not replace the stable browser controller unnecessarily');
assert.match(page,/artist-listening-ai\.js\?v=transcription-relations-v305-20260906/,'Section 5 must not churn the proven AI Summary cache identity');
assert.match(client,/state\.registry/);
assert.match(client,/app\.sections/);
assert.match(client,/analyzeApps\(state\.selectedApps/,'new output modules must use the existing generic registry/tab/analyze path');
assert.doesNotMatch(client,/summary_output|action_plan/,'browser must not hardcode v306 output IDs');
assert.doesNotMatch(client,/MutationObserver|sfListeningTranscriptNav|MediaRecorder/,'v306 must not add a frontend fallback/ownership layer');

/* Source intelligence export remains source-only; derived output modules do not recursively feed whole-report saves. */
assert.match(items,/function transcription_intelligence_report_text_v302/);
assert.match(items,/transcription_app_modules_v301/,'whole-report compiler intentionally reads source intelligence registry modules, not derived v306 outputs');
assert.match(relations,/transcription_intelligence_build_relations_v305/,'accepted relation context remains the existing v305 graph');

assert.match(baseline,/tests\/transcription-intelligence-outputs\.mjs/,'v306 output contract must run in Recovery Baseline');
console.log('VP3 transcription intelligence outputs contract: PASS');
