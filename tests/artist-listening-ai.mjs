import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';

const page = readFileSync('artist-listening.php', 'utf8');
const client = readFileSync('artist-listening-ai.js', 'utf8');
const workspace = readFileSync('artist-listening-workspace.js', 'utf8');
const css = readFileSync('artist-listening.css', 'utf8');
const api = readFileSync('api/artist-listening-intelligence-v254.php', 'utf8');

const asset = 'artist-listening-ai.js?v=c18c3dc8';
assert.ok(page.includes(asset), 'Artist Listening must load the content-hashed AI app controller');
assert.equal(page.split(asset).length - 1, 1, 'AI Summary controller must load exactly once');
assert.ok(page.includes('&b=1d511bb5'), 'updated AI settings controller must use a fresh secondary cache key');
assert.ok(!page.includes('artist-listening-intelligence-v236.js'), 'legacy v236 browser controller must stay removed');
assert.ok(!page.includes('artist-listening-intelligence-v254.js'), 'legacy v254 browser controller must stay removed');
assert.ok(!existsSync('artist-listening-intelligence-v236.js'), 'legacy v236 browser file must stay deleted');
assert.ok(!existsSync('artist-listening-intelligence-v254.js'), 'legacy v254 browser file must stay deleted');

assert.ok(workspace.includes('data-listening-ai-toggle'), 'workspace must source-own the one AI Summary button');
assert.ok(workspace.includes('<span>AI Summary</span>'), 'workspace must render the AI Summary label');
assert.ok(client.includes("const BUILD = 'artist-listening-ai-settings-toggle-20260903'"), 'controller must identify the collapsible settings build');
assert.ok(client.includes("document.querySelector('[data-listening-ai-toggle]')"), 'controller must resolve the existing workspace button directly');
assert.ok(client.includes("button.addEventListener('click'"), 'the existing button must receive a direct click listener');
assert.ok(!client.includes("document.addEventListener('click'"), 'no delegated document click owner is allowed');
assert.ok(client.includes('setOpen(!state.open)'), 'button click must toggle one open state');
assert.ok(client.includes("panel.classList.toggle('open', state.open)"), 'panel visibility must follow that open state');
assert.ok(client.includes("button.setAttribute('aria-expanded', state.open ? 'true' : 'false')"), 'aria-expanded must follow the same state');
assert.ok(client.includes("document.body.classList.toggle('sf-listening-ai-open', state.open)"), 'shade/body state must follow the same open state');
assert.ok(client.includes("panel.querySelector('[data-listening-ai-close]')?.addEventListener('click'"), 'panel close button must directly close the panel');
assert.ok(client.includes("shade.addEventListener('click'"), 'shade must directly close the panel');

/* Selected transcription apps are one panel with checkbox filters + result tabs. */
for (const id of ['basic','stats','actions','responses','decisions','moments','studio','knowledge']) {
  assert.ok(client.includes(`id:'${id}'`), `${id} transcription app must be registered`);
}
assert.ok(client.includes('settingsOpen: false'), 'transcription app settings must start collapsed');
assert.ok(client.includes('data-listening-ai-settings'), 'status row must expose the app-settings gear');
assert.ok(client.includes('aria-controls="sfListeningAiApps"'), 'settings gear must identify the app-settings panel');
assert.ok(client.includes('id="sfListeningAiApps" hidden'), 'app settings panel must be hidden by default');
assert.ok(client.includes("setSettingsOpen(!state.settingsOpen)"), 'settings gear must toggle the app-settings state');
assert.ok(client.includes('apps.hidden = !state.settingsOpen'), 'render must hide the app-settings panel when settings are closed');
assert.ok(client.indexOf('id="sfListeningAiApps" hidden') < client.indexOf('data-listening-ai-tabs'), 'result tabs must remain immediately after the collapsible app settings panel');
assert.ok(client.includes('data-listening-ai-app-options'), 'AI panel must expose app checkboxes');
assert.ok(client.includes('data-listening-ai-app='), 'checkboxes must carry canonical app IDs');
assert.ok(client.includes('data-listening-ai-tabs'), 'AI panel must expose result tabs');
assert.ok(client.includes('data-listening-ai-tab='), 'result tabs must carry canonical app IDs');
assert.ok(client.includes('setAppSelected'), 'checkbox changes must update selected apps');
assert.ok(client.includes('setActiveApp'), 'tabs must switch the active result without rerunning analysis');
assert.ok(client.includes('localStorage.setItem(appsKey'), 'selected apps must persist per user');
assert.ok(client.includes('apps:state.selectedApps'), 'Analyze must send only the selected transcription apps');
assert.ok(page.includes('.sf-listening-ai-status-row{display:flex!important'), 'status text and settings gear must share one row');
assert.ok(page.includes('.sf-listening-ai-settings{display:grid'), 'settings gear must have a compact visible control');
assert.ok(page.includes('.sf-listening-ai-apps[hidden]{display:none!important}'), 'hidden app settings must not consume slideout space');
assert.ok(page.includes('.sf-listening-ai-app-options{display:grid'), 'app choices must use the compact checkbox grid');
assert.ok(page.includes('.sf-listening-ai-tabs{display:flex'), 'selected app results must use one horizontal tab row');
assert.ok(page.includes('.sf-listening-ai-tabs button.active'), 'active result tab must have a visible state');

assert.ok(client.includes('data-listening-ai-research'), 'Research ON/OFF control must remain global');
assert.ok(client.includes('Research ON'), 'research enabled state must render visibly');
assert.ok(client.includes('Research OFF'), 'research disabled state must render visibly');
assert.ok(client.includes('localStorage.setItem(researchKey'), 'research state must persist per user');
assert.ok(client.includes("artist-listening-intelligence-v254.php"), 'AI controller must use the existing report API');
assert.ok(client.includes("request('status'"), 'opening/selecting a transcript must load AI status');
assert.ok(client.includes("request('analyze'"), 'Analyze must call the report API');
assert.ok(client.includes('research:state.researchEnabled'), 'Analyze must send the Research ON/OFF state');
assert.ok(client.includes("saveResult('save_brain')"), 'Agent Brain save control must remain available');
assert.ok(client.includes("saveResult('save_knowledge')"), 'Knowledge Base save control must remain available');
assert.ok(client.includes("scheduleLive('words')"), 'Research ON must support bounded live re-analysis');
assert.ok(client.includes('state.liveWords < 120'), 'live analysis must preserve the minimum transcript threshold');
assert.ok(client.includes('delta < 250'), 'live analysis must avoid repeated low-delta requests');
assert.ok(client.includes("window.addEventListener('stonefellow:artist-listening-document-selected'"), 'AI must follow explicit document selection events');
assert.ok(client.includes("window.addEventListener('stonefellow:artist-listening-live'"), 'AI may consume transcript live events without owning transcription');

/* Stats are calculated from transcript data, not invented by the model. */
assert.ok(api.includes('function artist_listening_v254_stats'), 'server must calculate deterministic transcript stats');
for (const key of ['total_words','duration_ms','transcript_turns','speaker_count','question_count','words_per_minute','speakers']) {
  assert.ok(api.includes(`'${key}'`), `stats output must include ${key}`);
}
assert.ok(client.includes('sf-listening-ai-stat-grid'), 'Stats tab must render stat cards');
assert.ok(client.includes('sf-listening-ai-chart-track'), 'Stats tab must render speaker-share charts');
assert.ok(page.includes('.sf-listening-ai-chart-track{height:7px'), 'speaker-share chart must have visible track geometry');

/* AI-backed apps use explicit structured result keys. */
for (const key of ['action_items','suggested_responses','decisions','commitments','key_moments','studio_notes','knowledge_candidates']) {
  assert.ok(api.includes(`'${key}'`), `server report decoder must support ${key}`);
}
assert.ok(api.includes('function artist_listening_v254_apps'), 'server must validate requested app IDs');
assert.ok(api.includes('function artist_listening_v254_module_prompt'), 'server must build prompts only for selected AI apps');
assert.ok(api.includes("$apps = artist_listening_v254_apps($input['apps']"), 'Analyze endpoint must accept selected app IDs');

for (const action of ['status','analyze','save_brain','save_knowledge']) assert.ok(api.includes(`'${action}'`), `server intelligence API must support ${action}`);
assert.ok(api.includes("$action === 'analyze'"), 'server must own transcript analysis');
assert.ok(api.includes("['save_brain','save_knowledge']"), 'server must own explicit save actions');

assert.ok(client.includes('sf-listening-ai-footer-actions'), 'AI footer must expose one canonical action row');
for (const attr of ['data-listening-ai-analyze','data-listening-ai-brain','data-listening-ai-knowledge']) assert.ok(client.includes(attr), `${attr} must remain in the one-row footer`);
assert.ok(client.includes('sf-listening-ai-report-state'), 'analysis state must render in the AI report canvas');
assert.ok(client.includes("return 'Not analyzed yet'"), 'empty analysis state must remain explicit');
assert.ok(!client.includes('data-listening-ai-saved'), 'analysis state must not return to the footer');
assert.ok(!client.includes('sfListeningAiDebug'), 'temporary AI debug DOM must stay removed');
assert.ok(!client.includes('AI SUMMARY DEBUG'), 'temporary AI debug copy must stay removed');
assert.ok(page.includes('.sf-listening-ai-footer-actions{display:flex!important'), 'page must keep AI footer actions on one line');
assert.ok(page.includes('flex-wrap:nowrap!important'), 'AI footer actions must not wrap');
assert.ok(page.includes('.sf-listening-ai-report-state{display:flex!important'), 'analysis state must be styled in the main report canvas');
assert.ok(css.includes('.sf-listening-ai-panel.open{transform:translateX(0)}'), 'open panel CSS must move the panel onscreen');

assert.ok(client.includes('fetch('), 'AI controller must make scoped backend requests');
assert.ok(!client.includes('MutationObserver'), 'AI controller must not observe or rewrite the page runtime');
assert.ok(!client.includes('Continuous View'), 'AI controller must never own Continuous View');
assert.ok(!client.includes('sfListeningTranscriptNav'), 'AI controller must never touch transcript navigation');
assert.ok(!client.includes('MediaRecorder'), 'AI controller must never own recording');
assert.ok(!client.includes('queueCheckpoint'), 'checkpoint persistence remains outside this feature');
assert.ok(!client.includes('save_participants'), 'speaker participant mapping remains outside this feature');

console.log('Artist Listening AI transcription apps contract: OK');
