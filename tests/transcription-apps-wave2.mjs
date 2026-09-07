import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const client = read('artist-listening-ai.js');
const api = read('api/artist-listening-intelligence-v300.php');
const baseRegistry = read('includes/transcription-app-registry.php');
const wave2 = read('includes/transcription-apps-wave2.php');
const save = read('includes/transcription-apps-wave2-save.php');

/* Eight wave-two plugins are first-class registry entries. */
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
assert.match(api,/transcription_app_analyze_v301\(/,'analysis must execute through the wave-two engine');

/* One enabled plugin = one tab; a tab renders only that plugin result. */
assert.match(client,/state\.selectedApps\.map\(id => \{/,'each enabled plugin must receive an independent result tab');
assert.match(client,/data-listening-ai-tab="\$\{esc\(id\)\}"/,'tab identity must be the plugin id');
assert.match(client,/function setActiveApp\(appId\)/);
assert.match(client,/state\.activeApp = appId/,'clicking a plugin tab must select that plugin');
assert.match(client,/const modules = state\.report\?\.analysis\?\.modules \|\| \{\};/);
assert.match(client,/modules\?\.\[state\.activeApp\]\?\.result \|\| \{\}/,'the report canvas must read only the active plugin payload');
assert.match(client,/appResultHtml\(app, activeResult\(\)\)/,'the active tab must render only its own result');
assert.doesNotMatch(client,/Object\.values\(modules\)|Object\.entries\(modules\)/,'the browser must not blend all plugin results into one master canvas');

/* Plugin output contracts are structured and evidence-aware. */
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

/* Expensive context-dependent plugins are manual-only. */
for (const id of ['changes','crm','research_brief']) {
  const start = wave2.indexOf(`'${id}' => [`);
  assert.ok(start >= 0, `${id} plugin definition missing`);
  assert.ok(wave2.slice(start, start + 700).includes("'live'=>false"), `${id} must be manual-only`);
}
assert.match(wave2,/selected_plugins_manual_only/);
assert.match(wave2,/array_filter\(\$requested, static fn\(string \$id\): bool => !empty\(\$registry\[\$id\]\['live'\]\)\)/);

/* Large selections are batched instead of forced through one oversized AI response. */
assert.match(wave2,/VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301 = 4/);
assert.match(wave2,/array_chunk\(\$aiApps, VP3_TRANSCRIPTION_APP_BATCH_SIZE_V301\)/);
assert.match(wave2,/\$pending\[\$id\]/,'AI results must stage independently before persistence');
assert.match(wave2,/No transcription plugin results were overwritten/,'missing batch output must fail without destructive persistence');
assert.doesNotMatch(wave2,/array_replace\(\$report/,'wave two must not reintroduce destructive master-report replacement');

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

/* Research Brief has public-source provenance and bounded freshness. */
assert.match(wave2,/artist_listening_v237_research/,'Research Brief must use the canonical web-research path');
assert.match(wave2,/PUBLIC RESEARCH is the only evidence for external factual verification/);
assert.match(wave2,/Do not invent source URLs or source numbers/);
assert.match(wave2,/VP3_TRANSCRIPTION_RESEARCH_TTL_SECONDS_V301 = 86400/,'Research Brief must age out after one day');
assert.match(wave2,/research_age/);
assert.match(wave2,/Turn Research ON and run Analyze/,'Research Brief must explain when public research is disabled');

/* Existing v300 plugins remain present and persistence keeps all modules independent. */
for (const id of ['basic','stats','actions','responses','decisions','moments','studio','knowledge','topics','entities','risks','timeline']) {
  assert.ok(baseRegistry.includes(`'${id}' => [`), `${id} v300 plugin must remain intact`);
}
assert.match(wave2,/transcription_app_modules_v301/);
assert.match(wave2,/transcription_app_compat_projection_v300\(\$modules\)/,'legacy projections must be generated from independent module results');
assert.match(wave2,/\$projection\['registry_version'\] = 301/);

/* Brain / Knowledge export must honor the same freshness shown in each plugin tab. */
assert.match(api,/transcription-apps-wave2-save\.php/);
assert.match(api,/transcription_app_status_v301\(/,'save path must calculate plugin freshness before exporting');
assert.match(api,/transcription_app_report_text_current_v301/);
assert.match(save,/empty\(\$appStatus\[\$id\]\['fresh'\]\)/,'stale plugin results must be excluded from exported intelligence');
assert.match(save,/researchBriefCurrent/,'public research must only export with a current Research Brief');

console.log('VP3 transcription apps wave two contract: PASS');
