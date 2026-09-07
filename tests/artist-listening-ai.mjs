import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';

const page = readFileSync('artist-listening.php', 'utf8');
const client = readFileSync('artist-listening-ai.js', 'utf8');
const workspace = readFileSync('artist-listening-workspace.js', 'utf8');
const css = readFileSync('artist-listening.css', 'utf8');
const api = readFileSync('api/artist-listening-intelligence-v300.php', 'utf8');
const registry = readFileSync('includes/transcription-app-registry.php', 'utf8');
const wave2 = readFileSync('includes/transcription-apps-wave2.php', 'utf8');
const items = readFileSync('includes/transcription-intelligence-items.php', 'utf8');
const relations = readFileSync('includes/transcription-intelligence-relations.php', 'utf8');
const actions = readFileSync('includes/transcription-intelligence-actions.php', 'utf8');
const workflow = readFileSync('includes/transcription-workflow-config.php', 'utf8');
const legacyApi = readFileSync('api/artist-listening-intelligence-v254.php', 'utf8');

const asset = 'artist-listening-ai.js?v=transcription-relations-v305-20260906';
assert.ok(page.includes(asset), 'Artist Listening must load the canonical v305 AI controller exactly once');
assert.equal(page.split(asset).length - 1, 1, 'AI Summary controller must load exactly once');
assert.ok(!page.includes('artist-listening-ai.js?v=transcription-workflow-v304-20260906'), 'retired v304 AI cache identity must stay removed');
assert.ok(!page.includes('artist-listening-ai.js?v=transcription-app-registry-v300-20260906'), 'retired v300 AI cache identity must stay removed');
assert.ok(!page.includes('artist-listening-ai.js?v=c18c3dc8'), 'old AI controller cache identity must stay retired');
assert.ok(!page.includes('&b=1d511bb5'), 'old secondary AI cache identity must stay retired');
assert.ok(!page.includes('artist-listening-intelligence-v236.js'), 'legacy v236 browser controller must stay removed');
assert.ok(!page.includes('artist-listening-intelligence-v254.js'), 'legacy v254 browser controller must stay removed');
assert.ok(!existsSync('artist-listening-intelligence-v236.js'), 'legacy v236 browser file must stay deleted');
assert.ok(!existsSync('artist-listening-intelligence-v254.js'), 'legacy v254 browser file must stay deleted');

assert.ok(workspace.includes('data-listening-ai-toggle'), 'workspace must source-own the one AI Summary button');
assert.ok(client.includes("const BUILD = 'transcription-relations-v305-20260906'"), 'controller must identify the v305 relationships build');
assert.ok(client.includes("document.querySelector('[data-listening-ai-toggle]')"), 'controller must resolve the existing workspace button directly');
assert.ok(client.includes("button.addEventListener('click'"), 'existing button must receive a direct click listener');
assert.ok(!client.includes("document.addEventListener('click'"), 'no delegated document click owner is allowed');
assert.ok(client.includes('setOpen(!state.open)'), 'button click must toggle one open state');
assert.ok(client.includes("panel.classList.toggle('open', state.open)"), 'panel visibility must follow one open state');
assert.ok(client.includes("document.body.classList.toggle('sf-listening-ai-open', state.open)"), 'shade/body state must follow the same open state');
assert.ok(client.includes("panel.querySelector('[data-listening-ai-close]')?.addEventListener('click'"), 'panel close button must directly close the panel');
assert.ok(client.includes("shade.addEventListener('click'"), 'shade must directly close the panel');

/* The browser remains registry/workflow-config driven and one plugin = one tab/result payload. */
assert.ok(!client.includes('const APP_DEFS'), 'browser must not maintain a duplicate app catalog');
assert.ok(client.includes("request('registry'"), 'browser must load app definitions from the canonical server registry');
assert.ok(client.includes('state.registry'), 'browser must render the server registry');
assert.ok(client.includes('state.workflowConfig'), 'browser must render server-owned workflow configuration');
assert.ok(client.includes('app.sections'), 'structured report sections must be registry-driven');
assert.ok(client.includes('app.view'), 'rendering mode must be registry-driven');
assert.ok(client.includes('data-listening-ai-app-options'), 'AI panel must expose registry-generated app choices');
assert.ok(client.includes('data-listening-ai-tabs'), 'AI panel must expose selected result tabs');
assert.ok(client.includes('localStorage.setItem(appsKey'), 'selected apps must persist per user');
assert.ok(client.includes('localStorage.setItem(workflowKey'), 'workflow settings must persist per user');
assert.ok(client.includes('analyzeApps(state.selectedApps'), 'full Analyze must run the selected plugin set');
assert.ok(client.includes('apps:requested'), 'analysis requests must send only explicitly requested plugin IDs');
assert.ok(client.includes('workflow:{...state.workflow}'), 'analysis requests must send the current workflow profile');
assert.ok(client.includes('appStatus'), 'browser must track independent app status');
assert.ok(client.includes('Needs refresh'), 'stale app results must be visible to the user');
assert.ok(client.includes('External Research'), 'Basic Analysis must preserve visible external research');
assert.ok(page.includes('.sf-listening-ai-structured-list{display:grid'), 'registry reports must have canonical structured-list styling');
assert.ok(page.includes('.sf-listening-ai-item-meta{display:flex'), 'structured report metadata must remain readable');

/* Canonical registries remain intact while v302 items, v303 actions, v304 workflow and v305 relationships extend them. */
for (const id of ['basic','stats','actions','responses','decisions','moments','studio','knowledge','topics','entities','risks','timeline']) {
  assert.ok(registry.includes(`'${id}' => [`), `${id} transcription app must remain registered`);
}
for (const title of ['Topics & Themes','Entities & Data','Risks & Blockers','Timeline & Milestones']) {
  assert.ok(registry.includes(`'title'=>'${title}'`), `${title} must remain available as a transcription app`);
}
assert.ok(registry.includes("'execution'=>'deterministic'"), 'registry must support deterministic apps');
assert.ok(registry.includes("'execution'=>'ai'"), 'registry must support AI apps');
assert.ok(wave2.includes('function transcription_app_registry_v301'), 'wave two must extend the canonical registry');
assert.ok(items.includes('function transcription_intelligence_normalize_modules_v302'), 'v302 durable item semantics must remain available');
assert.ok(actions.includes('function transcription_intelligence_execute_action_v303'), 'v303 operational actions must remain available');
assert.ok(workflow.includes('function transcription_app_analyze_v304'), 'v304 workflow execution must extend the existing registry rather than duplicate it');
assert.ok(relations.includes('VP3_TRANSCRIPTION_INTELLIGENCE_RELATIONS_V305'), 'v305 relationship semantics must extend durable intelligence items');
assert.ok(relations.includes('function transcription_intelligence_build_relations_v305'), 'v305 must expose explicit relationship building');

/* Each app owns its result and selected runs cannot erase other modules. */
assert.ok(registry.includes("'modules'=>$modules"), 'master analysis must persist independent modules');
assert.ok(!registry.includes('array_replace($report'), 'cross-app array replacement must stay removed from the registry execution path');
assert.ok(!workflow.includes('array_replace($report'), 'v304 workflow path must not reintroduce destructive report replacement');
assert.ok(workflow.includes('foreach ($pending as $id=>$module) $modules[$id]=$module'), 'v304 must persist successful plugin modules independently');
assert.ok(workflow.includes("'source_hash'=>(string)$map['source_hash']"), 'v304 generated modules must record source hash');
assert.ok(workflow.includes("'generated_at'=>gmdate('c')"), 'v304 generated modules must record generation time');
assert.ok(workflow.includes("'provider'=>(string)$ai['provider']"), 'v304 AI modules must record provider');
assert.ok(workflow.includes("'model'=>(string)$ai['model']"), 'v304 AI modules must record model');
assert.ok(workflow.includes('plugin_errors'), 'v304 must isolate per-plugin run errors');

/* Existing app reports stay evidence-aware. */
for (const field of ['evidence','confidence','owner','timing','priority','rationale','audience','purpose','why_it_matters','category','conflict']) {
  assert.ok(registry.includes(`'${field}'`), `upgraded reports must support ${field}`);
}
assert.ok(registry.includes('Confidence must be high, medium or low'), 'analysis contracts must explicitly calibrate confidence');
assert.ok(registry.includes('unknown when owner or timing is not supported'), 'action reports must not invent owner/timing');
assert.ok(registry.includes('A decision must be a concluded choice, not an idea'), 'decision reports must distinguish decisions from suggestions');
assert.ok(registry.includes('Never treat external research as a private user fact'), 'knowledge extraction must keep research provenance separate');

/* Stats remain deterministic and token-free. */
assert.ok(registry.includes('function transcription_app_stats_v300'), 'server must calculate deterministic transcript stats');
for (const key of ['total_words','duration_ms','transcript_turns','speaker_count','question_count','questions_per_1000_words','avg_words_per_turn','longest_turn_words','turn_share','speakers']) {
  assert.ok(registry.includes(`'${key}'`), `stats output must include ${key}`);
}
assert.ok(client.includes('sf-listening-ai-stat-grid'), 'Stats tab must render stat cards');
assert.ok(client.includes('sf-listening-ai-chart-track'), 'Stats tab must render speaker-share charts');
assert.ok(page.includes('.sf-listening-ai-chart-track{height:7px'), 'speaker-share chart must retain visible geometry');

/* Stable v300 URL delegates through v305 relationships while prior contracts stay available. */
assert.ok(api.includes("require_once dirname(__DIR__) . '/includes/transcription-app-registry.php'"), 'stable API must load the canonical registry');
assert.ok(api.includes("require_once dirname(__DIR__) . '/includes/transcription-apps-wave2.php'"), 'stable API must load wave two');
assert.ok(api.includes("require_once dirname(__DIR__) . '/includes/transcription-intelligence-items.php'"), 'stable API must load durable item semantics');
assert.ok(api.includes("require_once dirname(__DIR__) . '/includes/transcription-intelligence-relations.php'"), 'stable API must load v305 relationship semantics');
assert.ok(api.includes("require_once dirname(__DIR__) . '/includes/transcription-intelligence-actions.php'"), 'stable API must load operational action semantics');
assert.ok(api.includes("require_once dirname(__DIR__) . '/includes/transcription-workflow-config.php'"), 'stable API must load v304 workflow configuration');
assert.ok(api.includes("$action === 'analyze'"), 'stable API URL must continue to own app analysis');
assert.ok(api.includes('transcription_app_analyze_v304'), 'stable endpoint must execute analysis through v304');
assert.ok(!api.includes('$result = transcription_app_analyze_v301('), 'stable endpoint must not execute the old v301 analyzer directly');
assert.ok(api.includes("$action === 'build_relations'"), 'stable endpoint must expose explicit v305 relationship building');
assert.ok(api.includes("$action === 'review_relation'"), 'stable endpoint must expose explicit relationship review');
assert.ok(api.includes("$action === 'item_action'"), 'stable endpoint must preserve explicit operational item actions');
assert.ok(api.includes("['save_brain','save_knowledge']"), 'stable API must preserve explicit whole-report save actions');
assert.ok(api.includes('transcription_intelligence_report_text_v302'), 'Brain/Knowledge saves must use review-aware current intelligence');
assert.ok(api.includes("'source'=>'transcription-intelligence-v305'"), 'Agent Brain provenance must identify the v305 runtime');
assert.ok(client.includes('artist-listening-intelligence-v300.php'), 'browser must keep the stable registry API URL');
assert.ok(legacyApi.includes('artist_listening_v254_analyze'), 'legacy v254 endpoint must remain available during migration');

/* Cross-plugin relationships are explicit, evidence-aware and reviewable. */
assert.ok(client.includes('data-listening-ai-relations'), 'AI panel must expose an explicit Connections action');
assert.ok(client.includes('function relationsHtml(item)'), 'durable items must render their relationship adjacency');
assert.ok(client.includes("request('build_relations'"), 'Connections must use an explicit API request');
assert.ok(client.includes("request('review_relation'"), 'relationship review must use the stable API');
assert.ok(client.includes('buildRelations:'), 'controller API must expose explicit relationship building');
assert.ok(client.includes('reviewRelation:'), 'controller API must expose explicit relationship review');
assert.ok(client.includes('data-listening-ai-evidence'), 'relationship evidence must reuse transcript evidence navigation');
assert.ok(page.includes('.sf-listening-ai-relations{'), 'relationship cards must be styled inside the canonical page');
assert.ok(items.includes("['source_fingerprint','edited_text','actions','relations']"), 'internal relationship metadata must stay out of Brain/Knowledge report exports');

/* Live Analysis and Web Research are independent and bounded. */
for (const attr of ['data-listening-ai-live','data-listening-ai-research']) assert.ok(client.includes(attr), `${attr} must exist`);
assert.ok(client.includes('Live ON') && client.includes('Live OFF'), 'Live Analysis state must render visibly');
assert.ok(client.includes('Research ON') && client.includes('Research OFF'), 'Web Research state must render visibly');
assert.ok(client.includes('web_research:Boolean(enabled)'), 'Research compatibility setter must change only Web Research');
assert.ok(client.includes('state.workflow.live_analysis'), 'live scheduler must use Live Analysis state');
assert.ok(client.includes('state.liveWords < 120'), 'live analysis must preserve minimum transcript threshold');
assert.ok(client.includes('delta < 250'), 'live analysis must avoid repeated low-delta requests');
assert.ok(registry.includes('function transcription_app_research_gate_v300'), 'server must retain bounded research gating');

/* Accepted items expose explicit operational actions, still inside the canonical controller. */
assert.ok(client.includes('sf-listening-ai-operational'), 'accepted items must expose a compact action menu');
assert.ok(client.includes("request('item_action'"), 'item actions must use the stable intelligence API');
assert.ok(client.includes('performItemAction:'), 'controller API must expose explicit item actions');
assert.ok(client.includes('state.operations'), 'server-side permission/target context must control available actions');

/* Ownership boundaries remain clean. */
assert.ok(client.includes('sf-listening-ai-footer-actions'), 'AI footer must expose one canonical action row');
for (const attr of ['data-listening-ai-analyze','data-listening-ai-relations','data-listening-ai-brain','data-listening-ai-knowledge']) assert.ok(client.includes(attr), `${attr} must remain available`);
assert.ok(css.includes('.sf-listening-ai-panel.open{transform:translateX(0)}'), 'open panel CSS must move the panel onscreen');
assert.ok(client.includes('fetch('), 'AI controller must make scoped backend requests');
assert.ok(!client.includes('MutationObserver'), 'AI controller must not observe/rewrite the page runtime');
assert.ok(!client.includes('sfListeningTranscriptNav'), 'AI controller must never own transcript navigation DOM');
assert.ok(!client.includes('MediaRecorder'), 'AI controller must never own recording');

console.log('VP3 transcription app registry contract: OK');
