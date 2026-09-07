import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const workflow = read('includes/transcription-workflow-config.php');
const api = read('api/artist-listening-intelligence-v300.php');
const client = read('artist-listening-ai.js');
const page = read('artist-listening.php');
const wave2 = read('includes/transcription-apps-wave2.php');
const baseline = read('tools/run_recovery_baseline.py');

/* The server owns the workflow catalog and validation. */
assert.match(workflow,/VP3_TRANSCRIPTION_WORKFLOW_V304 = 'transcription-workflow-v304-20260906'/);
assert.match(workflow,/function transcription_workflow_presets_v304\(\)/);
for (const [id,title] of [['meeting','Meeting'],['product','Product'],['sales','Sales'],['studio','Studio'],['research','Research'],['full','Full']]) {
  assert.ok(workflow.includes(`'${id}'=>[`), `${title} preset must be server-defined`);
  assert.ok(workflow.includes(`'id'=>'${id}','title'=>'${title}'`), `${title} preset metadata must be public-configurable`);
}
assert.match(workflow,/'full'=>\[[\s\S]*'apps'=>array_keys\(transcription_app_registry_v301\(\)\)/,'Full preset must follow the live registry instead of duplicating every app ID');
assert.match(workflow,/function transcription_workflow_normalize_v304/);
assert.match(workflow,/\['concise','standard','deep'\]/,'depth is bounded by the server');
assert.match(workflow,/\['transcript','authorized'\]/,'context mode is bounded by the server');
assert.match(workflow,/transcription_app_clean_v300\(\(string\)\(\$raw\['focus'\]/,'focus text must be sanitized and bounded');
assert.doesNotMatch(client,/const\s+PRESETS|meeting:\s*\{|product:\s*\{|sales:\s*\{/,'browser must not own a duplicate preset catalog');
assert.match(client,/state\.workflowConfig\?\.presets/,'browser must render server-defined presets');

/* Live Analysis and Web Research are independent controls. */
assert.match(workflow,/'live_analysis'=>false/);
assert.match(workflow,/'web_research'=>false/);
assert.match(client,/data-listening-ai-live/);
assert.match(client,/data-listening-ai-research/);
assert.match(client,/function setLiveAnalysisEnabled\(enabled\)/);
assert.match(client,/function setResearchEnabled\(enabled\)/);
assert.match(client,/web_research:Boolean\(enabled\)/,'legacy Research API alias may only change Web Research');
const researchSetter = client.match(/function setResearchEnabled\(enabled\) \{([\s\S]*?)\n  \}/)?.[1] || '';
assert.ok(researchSetter,'Research setter must be present');
assert.doesNotMatch(researchSetter,/scheduleLive|live_analysis/,'Web Research setter must never schedule or toggle live analysis');
assert.match(client,/if \(!state\.workflow\.live_analysis \|\| !currentSessionId\(\) \|\| state\.busy\) return;/,'live scheduler must be owned by Live Analysis');
assert.match(client,/if \(state\.workflow\.live_analysis && currentSessionId\(\)\) void analyze\('live'\)/);
assert.match(workflow,/if \(\$mode === 'live' && empty\(\$workflow\['live_analysis'\]\)\)/,'server must independently block live execution when Live Analysis is off');
assert.match(workflow,/\$researchOn=!empty\(\$workflow\['web_research'\]\)/,'server research must depend only on Web Research');

/* Presets are safe defaults, not hidden expensive automation. */
assert.match(workflow,/'meeting'=>\[[\s\S]*'live_analysis'=>true[\s\S]*'web_research'=>false/);
assert.match(workflow,/'sales'=>\[[\s\S]*'live_analysis'=>true[\s\S]*'web_research'=>false/);
assert.match(workflow,/'studio'=>\[[\s\S]*'live_analysis'=>true[\s\S]*'web_research'=>false/);
assert.match(workflow,/'research'=>\[[\s\S]*'live_analysis'=>false[\s\S]*'web_research'=>true/);
assert.match(workflow,/'full'=>\[[\s\S]*'live_analysis'=>false[\s\S]*'web_research'=>false/);
assert.match(client,/function applyPreset\(presetId\)/);
assert.match(client,/presetById\(presetId\)/,'browser preset selection must resolve returned server metadata');
assert.match(client,/state\.workflow\.preset = String\(preset\.id\)/);
assert.match(client,/state\.workflow = normalizeWorkflow\(\{\.\.\.state\.workflow,preset:'custom'\}\)/,'manual app changes must leave preset mode and become custom');

/* Depth, focus and context are real execution settings. */
assert.match(workflow,/function transcription_workflow_max_tokens_v304/);
for (const tokens of ['2400','3400','5000']) assert.ok(workflow.includes(tokens), `depth token budget must include ${tokens}`);
assert.match(workflow,/RUN PROFILE:/);
assert.match(workflow,/User analysis focus:/);
assert.match(workflow,/never as evidence or a fact/,'focus must be explicitly non-evidentiary');
assert.match(workflow,/if \(\(\$workflow\['context_mode'\] \?\? 'authorized'\) === 'transcript'\) \$baseContext=\['brain'=>\[\],'knowledge'=>\[\]\]/,'transcript-only mode must remove general private Brain/Knowledge context');
assert.match(workflow,/return transcription_app_contexts_v301/,'specialized related-transcript/CRM context must stay on the existing explicit context path');
assert.match(client,/data-listening-ai-depth/);
assert.match(client,/data-listening-ai-context/);
assert.match(client,/data-listening-ai-focus/);
assert.match(client,/workflowKey = `stonefellow:artist-listening:workflow:\$\{userId\}`/,'workflow preferences must persist per user');
assert.match(client,/workflow:\{\.\.\.state\.workflow\}/,'Analyze must send the normalized workflow profile');

/* Run planning exposes batching/cost before Analyze and preserves manual-only rules. */
assert.match(workflow,/function transcription_workflow_run_plan_v304/);
assert.match(workflow,/VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301/,'server run plans must use the canonical batch-size constant');
assert.match(workflow,/'estimated_ai_cost'=>\$cost/);
assert.match(workflow,/'manual_only_apps'=>\$manualOnly/);
assert.match(client,/function localRunPlan\(/);
assert.match(client,/function planText\(/);
assert.match(client,/data-listening-ai-run-plan/);
assert.match(client,/Run plan ·/);
assert.match(wave2,/VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301 = 4/,'existing batch limit remains canonical');

/* Batch failures are isolated: good plugin results persist, failed ones become retryable. */
assert.match(workflow,/\$pluginErrors=\[\]/);
assert.match(workflow,/foreach \(array_chunk\(\$aiApps,VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301\) as \$batch\)/);
assert.match(workflow,/\$pluginErrors\[\$id\]='The AI provider omitted the '/);
assert.match(workflow,/foreach \(\$batch as \$id\) if \(!isset\(\$pending\[\$id\]\)\) \$pluginErrors\[\$id\]=\$message/);
assert.match(workflow,/if \(!\$pending && \$pluginErrors\) throw new RuntimeException/,'run may fail only when no selected result survived');
assert.match(workflow,/foreach \(\$pending as \$id=>\$module\) \$modules\[\$id\]=\$module/,'successful plugins must persist independently after another batch fails');
assert.doesNotMatch(workflow,/array_replace\(\$report/,'v304 must not reintroduce destructive master-report replacement');
assert.match(client,/state\.pluginErrors/);
assert.match(client,/needs a retry/);
assert.match(client,/analyzeApps\(\[state\.activeApp\], 'manual'\)/,'retry remains isolated to the active plugin');

/* Stable API and cache identity route the deployed page to v304. */
assert.match(api,/transcription-workflow-config\.php/);
assert.match(api,/transcription_app_analyze_v304\(/,'stable API must route analysis through v304');
assert.doesNotMatch(api,/\$result = transcription_app_analyze_v301\(/,'stable API must no longer execute the v301 analyzer directly');
assert.match(api,/workflow_config/);
assert.match(api,/vp3-transcription-intelligence-v304-20260906/);
const asset='artist-listening-ai.js?v=transcription-workflow-v304-20260906';
assert.ok(page.includes(asset),'page must load the v304 canonical AI controller');
assert.equal(page.split('artist-listening-ai.js?').length-1,1,'page must load exactly one AI controller');
assert.ok(!page.includes('artist-listening-ai.js?v=transcription-app-registry-v300-20260906'),'v300 cache identity must be retired after v304 deploy');
assert.match(client,/const BUILD = 'transcription-workflow-v304-20260906'/);

/* Existing ownership and bounded-live safeguards remain intact. */
assert.match(client,/state\.liveWords < 120/);
assert.match(client,/delta < 250/);
assert.doesNotMatch(client,/document\.addEventListener\('click'/,'AI controller must keep direct scoped event ownership');
assert.doesNotMatch(client,/MutationObserver|sfListeningTranscriptNav|MediaRecorder/,'workflow UI must not take transcript/recording/runtime ownership');

assert.match(baseline,/tests\/transcription-workflow-config\.mjs/,'v304 workflow contract must run in Recovery Baseline');
console.log('VP3 transcription workflow configuration contract: PASS');
