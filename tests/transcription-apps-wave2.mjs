import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const client = read('artist-listening-ai.js');
const api = read('api/artist-listening-intelligence-v300.php');
const baseRegistry = read('includes/transcription-app-registry.php');
const wave2 = read('includes/transcription-apps-wave2.php');
const workflow = read('includes/transcription-workflow-config.php');
const save = read('includes/transcription-apps-wave2-save.php');
const items = read('includes/transcription-intelligence-items.php');

/* Eight wave-two plugins remain first-class registry entries. */
const plugins = [
  ['qa','Questions & Answers'],
  ['requirements','Requirements & Constraints'],
  ['followup','Follow-up Tracker'],
  ['opportunities','Opportunity Finder'],
  ['changes','Comparison / Change Report'],
  ['crm','CRM Intelligence'],
  ['research_brief','Research Brief'],
  ['stance','Sentiment & Stance'],
];
for (const [id,title] of plugins) {
  assert.ok(wave2.includes(`'${id}' => [`), `${id} plugin must be registered`);
  assert.ok(wave2.includes(`'title'=>'${title}'`), `${title} must have its own registry definition`);
}
assert.match(wave2,/function transcription_app_registry_v301\(\)/,'wave two must merge with the stable v300 registry');
assert.match(wave2,/array_replace\(transcription_app_registry_v300\(\), transcription_app_wave2_registry_v301\(\)\)/);
assert.match(api,/transcription-apps-wave2\.php/,'the production intelligence endpoint must load wave two');
assert.match(api,/transcription_app_registry_public_v301\(\)/,'the browser registry must receive every wave-two plugin');
assert.match(api,/transcription_app_analyze_v304\(/,'stable analysis must execute through the v304 workflow engine');
assert.doesNotMatch(api,/\$result = transcription_app_analyze_v301\(/,'stable endpoint must not bypass v304 workflow controls');

/* One enabled plugin = one tab; a tab renders only that plugin result. */
assert.match(client,/state\.selectedApps\.map\(id => \{/,'each enabled plugin must receive an independent result tab');
assert.match(client,/data-listening-ai-tab="\$\{esc\(id\)\}"/,'tab identity must be the plugin id');
assert.match(client,/function setActiveApp\(appId\)/);
assert.match(client,/state\.activeApp = appId/,'clicking a plugin tab must select that plugin');
assert.match(client,/const modules = state\.report\?\.analysis\?\.modules \|\| \{\};/);
assert.match(client,/modules\?\.\[state\.activeApp\]\?\.result \|\| \{\}/,'the report canvas must read only the active plugin payload');
assert.match(client,/appResultHtml\(app, activeResult\(\)\)/,'the active tab must render only its own result');
assert.doesNotMatch(client,/Object\.values\(modules\)|Object\.entries\(modules\)/,'the browser must not blend all plugin results into one master canvas');

/* Plugin output contracts stay structured and evidence-aware. */
for (const field of ['asked_by','answer','acceptance_criteria','dependency','source_type','validation_step','compared_to','matched_contacts','verification','source_numbers','stance']) {
  assert.ok(wave2.includes(field), `wave-two contracts must include ${field}`);
}
assert.match(wave2,/Only classify a question as answered when the transcript contains a responsive answer/);
assert.match(wave2,/Never convert a suggestion into a requirement/);
assert.match(wave2,/do not invent deadlines or owners/);
assert.match(wave2,/validation_step must describe how to test the opportunity/);
assert.match(wave2,/return empty arrays rather than inventing a baseline/);
assert.match(wave2,/Never infer identity from a name alone/);
assert.match(wave2,/must not diagnose personality, mental state or emotion/);

/* Context-dependent plugins stay manual-only; v304 owns live filtering. */
for (const id of ['changes','crm','research_brief']) {
  const start = wave2.indexOf(`'${id}' => [`);
  assert.ok(start >= 0, `${id} plugin definition missing`);
  assert.ok(wave2.slice(start, start + 700).includes("'live'=>false"), `${id} must be manual-only`);
}
assert.match(workflow,/selected_plugins_manual_only/);
assert.match(workflow,/array_filter\(\$requested,static fn\(string \$id\):bool=>!empty\(\$registry\[\$id\]\['live'\]\)\)/,'v304 must apply registry live/manual eligibility');

/* Large selections still use the canonical wave-two batch size, now through v304. */
assert.match(wave2,/VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301 = 4/);
assert.match(workflow,/array_chunk\(\$aiApps,VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301\)/);
assert.match(workflow,/\$pending\[\$id\]/,'AI results must stage independently before persistence');
assert.match(workflow,/\$pluginErrors\[\$id\]/,'failed plugin results must be isolated for retry');
assert.match(workflow,/foreach \(\$pending as \$id=>\$module\) \$modules\[\$id\]=\$module/,'successful batches must persist independently');
assert.doesNotMatch(workflow,/array_replace\(\$report/,'v304 must not reintroduce destructive master-report replacement');

/* Comparison uses explicit related-session context only. */
assert.match(wave2,/s\.project_track_id=\?/);
assert.match(wave2,/s\.conversation_id=\?/);
assert.match(wave2,/same_project/);
assert.match(wave2,/same_conversation/);
assert.match(wave2,/RELATED PRIOR TRANSCRIPTS/);
assert.match(wave2,/related_context_changed/,'comparison freshness must invalidate when its prior context changes');

/* CRM Intelligence is read-only and requires explicit matching. */
assert.match(wave2,/crm_v180_can_manage\(\$user\)/);
assert.match(wave2,/crm_v180_schema_ready\(\$pdo\)/);
assert.match(wave2,/FILTER_VALIDATE_EMAIL/);
assert.match(wave2,/stored CRM IDs or exact transcript email addresses/);
assert.match(wave2,/crm_context_changed/,'CRM plugin freshness must invalidate when the matched CRM context changes');
assert.doesNotMatch(wave2,/UPDATE crm_|INSERT INTO crm_|DELETE FROM crm_/,'transcription analysis must never mutate CRM records');

/* Research Brief keeps public-source provenance while Web Research is v304-controlled. */
assert.match(wave2,/artist_listening_v237_research/,'Research Brief must use the canonical web-research path');
assert.match(wave2,/PUBLIC RESEARCH is the only evidence for external factual verification/);
assert.match(wave2,/Do not invent source URLs or source numbers/);
assert.match(wave2,/VP3_TRANSCRIPTION_RESEARCH_TTL_SECONDS_V301 = 86400/,'Research Brief must age out after one day');
assert.match(wave2,/research_age/);
assert.match(workflow,/Turn Web Research ON and run Analyze/,'v304 Research Brief must explain when public research is disabled');
assert.match(workflow,/\$researchOn=!empty\(\$workflow\['web_research'\]\)/,'only Web Research may trigger public research');

/* Existing v300/v301 registries remain intact while v304 owns execution persistence. */
for (const id of ['basic','stats','actions','responses','decisions','moments','studio','knowledge','topics','entities','risks','timeline']) {
  assert.ok(baseRegistry.includes(`'${id}' => [`), `${id} v300 plugin must remain intact`);
}
assert.match(wave2,/transcription_app_modules_v301/);
assert.match(workflow,/transcription_app_compat_projection_v300\(\$modules\)/,'v304 persistence must still generate legacy projections from independent modules');
assert.match(workflow,/\$projection\['registry_version'\]=304/);
assert.match(workflow,/\$projection\['workflow_version'\]=304/);

/* Brain / Knowledge export still honors freshness plus human review. */
assert.match(api,/transcription-apps-wave2-save\.php/,'v301 compatibility helpers must remain loadable');
assert.match(api,/transcription_app_status_v301\(/,'save path must calculate plugin freshness before exporting');
assert.match(api,/transcription_intelligence_report_text_v302/,'v302 must compile review-aware current intelligence');
assert.match(items,/empty\(\$appStatus\[\$id\]\['fresh'\]\)/,'v302 export must exclude stale plugin results');
assert.match(items,/\(\$item\['review_state'\] \?\? ''\) === 'rejected'/,'v302 export must exclude rejected items');
assert.match(items,/research_brief'\]\['fresh'\]/,'public research must only export with a current Research Brief');
assert.match(save,/researchBriefCurrent/,'v301 compatibility helper must retain its original public-research freshness contract');

console.log('VP3 transcription apps wave two contract: PASS');
