import fs from 'node:fs';
import assert from 'node:assert/strict';

const page=fs.readFileSync('artist-listening.php','utf8');
const core=fs.readFileSync('includes/artist-listening-transcript.php','utf8');
const api=fs.readFileSync('api/artist-listening-long-v237.php','utf8');
const client=fs.readFileSync('artist-listening-transcript.js','utf8');
const css=fs.readFileSync('artist-listening.css','utf8');
const ai=fs.readFileSync('artist-listening-ai.js','utf8');
const ui=fs.readFileSync('artist-listening-ui.js','utf8');
const sql=fs.readFileSync('upgrade-stonefellow-v237-long-transcripts.sql','utf8');
const upgrade=fs.readFileSync('upgrade.php','utf8');

assert.match(page,/artist-listening-transcript\.js\?v=artist-listening-normalized-20260903/);
assert.match(page,/artist-listening-workspace\.js\?v=artist-listening-normalized-20260903/);
assert.match(page,/artist-listening\.js\?v=9ac023be/);
assert.match(page,/artist-listening-ai\.js\?v=c18c3dc8/);
assert.doesNotMatch(page,/artist-listening-long-v237\.js|artist-listening-ui-v242\.js|artist-listening-ai-toggle-v256\.js|artist-listening-intelligence-v236\.js/);
assert.ok(page.indexOf('artist-listening-transcript.js') < page.indexOf('artist-listening-workspace.js'), 'transcript adapter must be available before workspace session loading');
assert.match(page,/#sfListeningTranscriptNav\{[\s\S]*left:var\(--sf-listening-sidebar-width\)!important;[\s\S]*right:0!important;[\s\S]*bottom:var\(--sf-listening-player-height\)!important;/);
assert.match(page,/#sfListeningTranscriptNav \.sf-listening-transcript-total\{[\s\S]*margin-left:auto!important/);
assert.match(page,/@media\(max-width:720px\)\{[\s\S]*#sfListeningTranscriptNav\{left:0!important;right:0!important;width:100vw!important/);

/* Backend compatibility remains intentionally versioned until API migration. */
assert.match(page,/api\/artist-listening-long-v237\.php/);
assert.match(core,/STONEFELLOW_ARTIST_LISTENING_PAGE_WORDS_V237 = 2500/);
assert.match(core,/artist_transcript_page_analysis_v237/);
assert.match(core,/artist_transcript_master_analysis_v237/);
assert.match(core,/function artist_listening_transcript_page_map/);
assert.match(core,/function artist_listening_v237_analyze_page/);
assert.match(core,/function artist_listening_v237_analyze_master/);
for(const action of ['library','manifest','page','analysis','analyze_page','analyze_master']) assert.match(api,new RegExp(`action === '${action}'`));
assert.match(api,/function artist_listening_v237_analyze_page_request/);

/* Frontend transcript controller owns paging/view only, not AI. */
assert.match(client,/artist-listening-long-v237\.php/);
assert.match(client,/workspaceRequest/);
assert.match(client,/stonefellow:artist-listening-live/);
assert.match(client,/detail\.segments=last\.segments/);
assert.match(client,/detail\.totalWordCount=mapped\.total/);
assert.match(client,/2500/);
assert.match(client,/Continuous View/);
assert.match(client,/IntersectionObserver/);
assert.match(client,/function enterContinuous\(\)/);
assert.match(client,/documentArea\.hidden=true/);
assert.match(client,/container\.hidden=false/);
assert.match(client,/function exitContinuous\(\)/);
assert.match(client,/container\.hidden=true/);
assert.match(client,/documentArea\.hidden=false/);
assert.match(client,/toggle\.textContent='Page View'/);
assert.match(client,/toggle\.textContent='Continuous View'/);
assert.match(client,/stonefellow:artist-listening-view-changed/);
assert.doesNotMatch(client,/window\.fetch\s*=|ensureAiUi|renderAi\(|analyze_page|analyze_master/);

/* UI consumes the authoritative completed view event directly. */
assert.match(ui,/window\.addEventListener\('stonefellow:artist-listening-view-changed', event => \{/);
assert.match(ui,/proof\.continuousView = view === 'continuous';[\s\S]*syncViewUi\(\);/);
assert.match(ui,/const tick = \(\) => \{[\s\S]*syncStaticUi\(\);[\s\S]*syncViewUi\(\);/,'bootstrap reconciliation may run through the bounded startup tick');
assert.doesNotMatch(ui,/observer\.observe\(document\.body|new MutationObserver\(sync\)/);

/* Layout remains one canonical stylesheet plus the page composition geometry. */
assert.match(css,/\.sf-listening-workspace-document-area\[hidden\],\.sf-listening-transcript-continuous\[hidden\]\{display:none!important\}/);
assert.match(css,/\.sf-listening-workspace-editor-content\{grid-template-rows:auto auto auto minmax\(0,1fr\)!important\}/);
assert.match(css,/\.sf-listening-workspace-editor-top\{grid-row:1\}\.sf-listening-transcript-nav\{grid-row:2/);
assert.match(css,/\.sf-listening-workspace-meta-bar\{grid-row:3\}/);
assert.match(css,/\.sf-listening-workspace-document-area,\.sf-listening-transcript-continuous\{grid-row:4/);
assert.doesNotMatch(css,/\.sf-listening-workspace-toolbar>\.sf-listening-transcript-nav/);
assert.match(css,/\.sf-listening-workspace-listening-player\{position:fixed!important;z-index:10020!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important/);
assert.match(page,/\.sf-listening-workspace-listening-player\{[\s\S]*padding:8px 16px 8px calc\(var\(--sf-listening-sidebar-width\) \+ 16px\)!important/);

/* AI is separate from transcript view ownership while research/analysis live inside the AI owner. */
assert.match(ai,/sfListeningAiPanel/);
assert.match(ai,/AI Summary/);
assert.match(ai,/button\.addEventListener\('click'/);
assert.match(ai,/setOpen\(!state\.open\)/);
assert.match(ai,/Research ON/);
assert.match(ai,/Research OFF/);
assert.match(ai,/data-listening-ai-research/);
assert.match(ai,/request\('analyze'/);
assert.match(ai,/sf-listening-ai-footer-actions/);
assert.match(ai,/sf-listening-ai-report-state/);
assert.doesNotMatch(ai,/data-listening-ai-saved|sfListeningAiDebug|AI SUMMARY DEBUG/);
assert.doesNotMatch(ai,/Continuous View|sfListeningTranscriptNav/);
assert.match(css,/\.sf-listening-ai-panel\{z-index:10040/);

assert.match(sql,/CREATE TABLE IF NOT EXISTS artist_transcript_page_analysis_v237/);
assert.match(sql,/CREATE TABLE IF NOT EXISTS artist_transcript_master_analysis_v237/);
assert.match(upgrade,/includes\/artist-listening-transcript\.php/,'database upgrade must load the canonical transcript include');
assert.match(upgrade,/artist_listening_v237_ensure_schema\(\)/);
assert.match(upgrade,/artist_listening_v237_schema_ready\(\)/);

console.log('ARTIST_LISTENING_TRANSCRIPT=PASS');
