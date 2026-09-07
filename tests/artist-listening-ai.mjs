import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';

const page = readFileSync('artist-listening.php', 'utf8');
const client = readFileSync('artist-listening-ai.js', 'utf8');
const workspace = readFileSync('artist-listening-workspace.js', 'utf8');
const css = readFileSync('artist-listening.css', 'utf8');
const api = readFileSync('api/artist-listening-intelligence-v300.php', 'utf8');
const registry = readFileSync('includes/transcription-app-registry.php', 'utf8');
const legacyApi = readFileSync('api/artist-listening-intelligence-v254.php', 'utf8');

const asset = 'artist-listening-ai.js?v=transcription-app-registry-v300-20260906';
assert.ok(page.includes(asset), 'Artist Listening must load the v300 registry controller cache identity');
assert.equal(page.split(asset).length - 1, 1, 'AI Summary controller must load exactly once');
assert.ok(!page.includes('artist-listening-ai.js?v=c18c3dc8'), 'old AI controller cache identity must stay retired');
assert.ok(!page.includes('&b=1d511bb5'), 'old secondary AI cache identity must stay retired');
assert.ok(!page.includes('artist-listening-intelligence-v236.js'), 'legacy v236 browser controller must stay removed');
assert.ok(!page.includes('artist-listening-intelligence-v254.js'), 'legacy v254 browser controller must stay removed');
assert.ok(!existsSync('artist-listening-intelligence-v236.js'), 'legacy v236 browser file must stay deleted');
assert.ok(!existsSync('artist-listening-intelligence-v254.js'), 'legacy v254 browser file must stay deleted');

assert.ok(workspace.includes('data-listening-ai-toggle'), 'workspace must source-own the one AI Summary button');
assert.ok(client.includes("const BUILD = 'transcription-app-registry-v300-20260906'"), 'controller must identify the registry build');
assert.ok(client.includes("document.querySelector('[data-listening-ai-toggle]')"), 'controller must resolve the existing workspace button directly');
assert.ok(client.includes("button.addEventListener('click'"), 'existing button must receive a direct click listener');
assert.ok(!client.includes("document.addEventListener('click'"), 'no delegated document click owner is allowed');
assert.ok(client.includes('setOpen(!state.open)'), 'button click must toggle one open state');
assert.ok(client.includes("panel.classList.toggle('open', state.open)"), 'panel visibility must follow one open state');
assert.ok(client.includes("document.body.classList.toggle('sf-listening-ai-open', state.open)"), 'shade/body state must follow the same open state');
assert.ok(client.includes("panel.querySelector('[data-listening-ai-close]')?.addEventListener('click'"), 'panel close button must directly close the panel');
assert.ok(client.includes("shade.addEventListener('click'"), 'shade must directly close the panel');

/* The browser no longer owns a duplicate hard-coded app registry. */
assert.ok(!client.includes('const APP_DEFS'), 'browser must not maintain a duplicate app catalog');
assert.ok(client.includes("request('registry'"), 'browser must load app definitions from the canonical server registry');
assert.ok(client.includes('state.registry'), 'browser must render the server registry');
assert.ok(client.includes('app.sections'), 'structured report sections must be registry-driven');
assert.ok(client.includes('app.view'), 'rendering mode must be registry-driven');
assert.ok(client.includes('data-listening-ai-app-options'), 'AI panel must expose registry-generated app choices');
assert.ok(client.includes('data-listening-ai-tabs'), 'AI panel must expose selected result tabs');
assert.ok(client.includes('localStorage.setItem(appsKey'), 'selected apps must persist per user');
assert.ok(client.includes('apps:state.selectedApps'), 'Analyze must send only selected apps');
assert.ok(client.includes('appStatus'), 'browser must track independent app status');
assert.ok(client.includes('Needs refresh'), 'stale app results must be visible to the user');
assert.ok(client.includes('External Research'), 'Basic Analysis must preserve visible external research');
assert.ok(page.includes('.sf-listening-ai-structured-list{display:grid'), 'registry reports must have canonical structured-list styling');
assert.ok(page.includes('.sf-listening-ai-item-meta{display:flex'), 'structured report metadata must remain readable');

/* Canonical registry contains current apps plus high-value analysis additions. */
for (const id of ['basic','stats','actions','responses','decisions','moments','studio','knowledge','topics','entities','risks','timeline']) {
  assert.ok(registry.includes(`'${id}' => [`), `${id} transcription app must be registered`);
}
for (const title of ['Topics & Themes','Entities & Data','Risks & Blockers','Timeline & Milestones']) {
  assert.ok(registry.includes(`'title'=>'${title}'`), `${title} must be available as a transcription app`);
}
assert.ok(registry.includes("'execution'=>'deterministic'"), 'registry must support deterministic apps');
assert.ok(registry.includes("'execution'=>'ai'"), 'registry must support AI apps');
assert.ok(registry.includes('function transcription_app_registry_public_v300'), 'registry must expose safe browser metadata');
assert.ok(registry.includes('function transcription_app_ids_v300'), 'server must validate requested app IDs from the registry');

/* Each app owns its result and freshness; selected runs cannot erase other modules. */
assert.ok(registry.includes("'modules'=>$modules"), 'master analysis must persist independent modules');
assert.ok(registry.includes("$modules[$id]=['app_id'=>$id"), 'selected AI apps must replace only their own module');
assert.ok(!registry.includes('array_replace($report'), 'cross-app array replacement must stay removed from the new execution path');
assert.ok(registry.includes("'source_hash'=>(string)$map['source_hash']"), 'each generated module must record its source hash');
assert.ok(registry.includes("'generated_at'=>gmdate('c')"), 'each generated module must record generation time');
assert.ok(registry.includes("'provider'=>(string)$ai['provider']"), 'AI modules must record provider');
assert.ok(registry.includes("'model'=>(string)$ai['model']"), 'AI modules must record model');
assert.ok(registry.includes('function transcription_app_status_v300'), 'server must calculate independent app freshness');
assert.ok(registry.includes("'fresh'=>is_array($module)"), 'app freshness must compare its own source hash');
assert.ok(registry.includes('No transcription app results were overwritten.'), 'missing AI app output must fail safely before persistence');

/* Existing app reports are upgraded from flat strings to evidence-aware structures. */
for (const field of ['evidence','confidence','owner','timing','priority','rationale','audience','purpose','why_it_matters','category','conflict']) {
  assert.ok(registry.includes(`'${field}'`), `upgraded reports must support ${field}`);
}
assert.ok(registry.includes('Confidence must be high, medium or low'), 'analysis contracts must explicitly calibrate confidence');
assert.ok(registry.includes('unknown when owner or timing is not supported'), 'action reports must not invent owner/timing');
assert.ok(registry.includes('A decision must be a concluded choice, not an idea'), 'decision reports must distinguish decisions from suggestions');
assert.ok(registry.includes('Never treat external research as a private user fact'), 'knowledge extraction must keep research provenance separate');

/* Stats remain deterministic and now expose deeper conversation measurements. */
assert.ok(registry.includes('function transcription_app_stats_v300'), 'server must calculate deterministic transcript stats');
for (const key of ['total_words','duration_ms','transcript_turns','speaker_count','question_count','questions_per_1000_words','avg_words_per_turn','longest_turn_words','turn_share','speakers']) {
  assert.ok(registry.includes(`'${key}'`), `stats output must include ${key}`);
}
assert.ok(client.includes('sf-listening-ai-stat-grid'), 'Stats tab must render stat cards');
assert.ok(client.includes('sf-listening-ai-chart-track'), 'Stats tab must render speaker-share charts');
assert.ok(page.includes('.sf-listening-ai-chart-track{height:7px'), 'speaker-share chart must retain visible geometry');

/* v300 owns execution while v254 remains untouched for compatibility. */
assert.ok(api.includes("require_once dirname(__DIR__) . '/includes/transcription-app-registry.php'"), 'v300 API must load the canonical registry');
assert.ok(api.includes("$action === 'analyze'"), 'v300 API must own app analysis');
assert.ok(api.includes('transcription_app_analyze_v300'), 'v300 API must delegate execution to registry service');
assert.ok(api.includes("['save_brain','save_knowledge']"), 'v300 API must preserve explicit save actions');
assert.ok(api.includes('transcription_app_report_text_v300'), 'Brain/Knowledge saves must use current module results');
assert.ok(client.includes('artist-listening-intelligence-v300.php'), 'browser must use the registry API');
assert.ok(legacyApi.includes('artist_listening_v254_analyze'), 'legacy v254 endpoint must remain available during migration');

/* Research remains bounded and independent from panel visibility. */
assert.ok(client.includes('data-listening-ai-research'), 'Research ON/OFF control must remain global');
assert.ok(client.includes('Research ON'), 'research enabled state must render visibly');
assert.ok(client.includes('Research OFF'), 'research disabled state must render visibly');
assert.ok(client.includes('localStorage.setItem(researchKey'), 'research state must persist per user');
assert.ok(client.includes("scheduleLive('words')"), 'Research ON must retain bounded live re-analysis');
assert.ok(client.includes('state.liveWords < 120'), 'live analysis must preserve minimum transcript threshold');
assert.ok(client.includes('delta < 250'), 'live analysis must avoid repeated low-delta requests');
assert.ok(registry.includes('function transcription_app_research_gate_v300'), 'server must retain bounded research gating');

/* Ownership boundaries remain clean. */
assert.ok(client.includes('sf-listening-ai-footer-actions'), 'AI footer must expose one canonical action row');
for (const attr of ['data-listening-ai-analyze','data-listening-ai-brain','data-listening-ai-knowledge']) assert.ok(client.includes(attr), `${attr} must remain available`);
assert.ok(css.includes('.sf-listening-ai-panel.open{transform:translateX(0)}'), 'open panel CSS must move the panel onscreen');
assert.ok(client.includes('fetch('), 'AI controller must make scoped backend requests');
assert.ok(!client.includes('MutationObserver'), 'AI controller must not observe/rewrite the page runtime');
assert.ok(!client.includes('Continuous View'), 'AI controller must never own Continuous View');
assert.ok(!client.includes('sfListeningTranscriptNav'), 'AI controller must never touch transcript navigation');
assert.ok(!client.includes('MediaRecorder'), 'AI controller must never own recording');

console.log('VP3 transcription app registry contract: OK');
