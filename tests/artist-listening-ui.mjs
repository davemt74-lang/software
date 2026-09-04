import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync('artist-listening.php','utf8');
const runtime = fs.readFileSync('artist-listening-ui.js','utf8');
const css = fs.readFileSync('artist-listening.css','utf8');
const workspace = fs.readFileSync('artist-listening-workspace.js','utf8');
const ai = fs.readFileSync('artist-listening-ai.js','utf8');
const transcript = fs.readFileSync('artist-listening-transcript.js','utf8');
const editApi = fs.readFileSync('api/artist-listening-edit-v249.php','utf8');

/* The page loads one canonical frontend layer per concern. */
for (const asset of [
  'artist-listening.css',
  'artist-listening-transcript.js',
  'artist-listening-workspace.js',
  'artist-listening-ui.js',
  'artist-listening-recordings.js',
]) assert.ok(page.includes(`${asset}?v=artist-listening-normalized-20260903`), `${asset} must use the canonical cache-busted URL`);
assert.ok(page.includes('artist-listening.js?v=9ac023be'), 'fixed capture controller must use its content hash cache key');
assert.ok(page.includes('artist-listening-ai.js?v=c18c3dc8'), 'AI transcription-app controller must use its content hash cache key');
assert.doesNotMatch(page,/artist-listening[^"']*-v\d+[^"']*\.(?:js|css)/,'numbered Artist Listening frontend layers must not return');
assert.doesNotMatch(page,/artist-listening-edit-v249\.(?:js|css)/,'legacy edit frontend must stay removed');

/* Canonical UI owns presentation/edit enhancements, not AI or view switching. */
assert.match(runtime,/const BUILD = 'artist-listening-ui'/);
assert.match(runtime,/window\.STONEFELLOW_ARTIST_LISTENING_UI =/);
assert.doesNotMatch(runtime,/data-listening-ai-toggle|fallbackAiClick|ensureAiButton|setFallbackAiOpen/);
assert.doesNotMatch(runtime,/window\.fetch\s*=/);
assert.match(runtime,/window\.addEventListener\('stonefellow:artist-listening-view-changed', event => \{/);
assert.match(runtime,/proof\.continuousView = view === 'continuous';[\s\S]*syncViewUi\(\);/);
assert.match(runtime,/const tick = \(\) => \{[\s\S]*syncStaticUi\(\);[\s\S]*syncViewUi\(\);/,'bounded startup reconciliation may sync the initial DOM state');
assert.doesNotMatch(runtime,/observer\.observe\(document\.body|new MutationObserver\(sync\)/,'UI must not globally observe and rewrite the page');

/* Header actions and the two-layer footer use the existing controls without changing their feature owners. */
assert.match(workspace,/data-listening-workspace-exit/);
assert.match(workspace,/data-listening-workspace-save>Save<\/button>/);
assert.match(workspace,/data-listening-ai-toggle aria-expanded="false"/);
assert.doesNotMatch(workspace,/>Copy<\/button>|>Download<\/button>|copyDocument|downloadDocument/);
assert.match(page,/--sf-listening-sidebar-width:292px/);
assert.match(page,/#sfListeningTranscriptNav\{[\s\S]*left:var\(--sf-listening-sidebar-width\)!important;[\s\S]*right:0!important;[\s\S]*bottom:var\(--sf-listening-player-height\)!important;/);
assert.match(page,/#sfListeningTranscriptNav \.sf-listening-transcript-total\{[\s\S]*margin-left:auto!important/);
assert.match(page,/\.sf-listening-workspace-listening-player\{[\s\S]*left:0!important;[\s\S]*right:0!important;[\s\S]*width:100vw!important;[\s\S]*padding:8px 16px 8px calc\(var\(--sf-listening-sidebar-width\) \+ 16px\)!important;/);
assert.match(page,/\.sf-listening-footer-right\{[\s\S]*margin-left:auto!important/);
assert.match(page,/right\.appendChild\(save\);[\s\S]*right\.appendChild\(start\);/);
assert.match(page,/sideHead\.insertBefore\(exit, newRecording \|\| null\)/);
assert.doesNotMatch(ai,/sfListeningTranscriptNav|Continuous View/,'AI Summary must never own transcript navigation');
assert.match(transcript,/Continuous View/);
assert.match(transcript,/function enterContinuous\(\)/);
assert.match(transcript,/function exitContinuous\(\)/);
assert.match(transcript,/stonefellow:artist-listening-view-changed/);

/* Account avatar/menu sits after AI Summary and temporary debug UI is fully removed. */
assert.doesNotMatch(page,/sf-listening-ai-debug/,'temporary AI debug styling must be removed from the page');
assert.doesNotMatch(ai,/sfListeningAiDebug|AI SUMMARY DEBUG/,'temporary AI debug DOM must be removed from the controller');
assert.match(page,/function ensureUserMenu\(editorTop\)/);
assert.match(page,/aiButton\.insertAdjacentElement\('afterend', menu\)/,'account menu must mount immediately after AI Summary');
assert.match(page,/data-listening-user-menu-toggle/);
assert.match(page,/data-listening-user-menu-dropdown/);
assert.match(page,/data-listening-user-avatar-fallback/);
assert.match(page,/avatar\.php\?user=\$\{encodeURIComponent\(String\(userId\)\)\}/,'account avatar must use the authenticated avatar endpoint');
for (const label of ['My Account','My Library','View Artist Profile','Agent Chat','Log Out']) assert.ok(page.includes(label), `${label} must remain in the Artist Listening account menu`);
assert.match(page,/\.sf-listening-user-menu-dropdown\{[\s\S]*right:0;[\s\S]*background:#fff/);
assert.match(page,/\.sf-listening-user-menu-dropdown\{position:fixed;top:52px;right:8px;width:min\(250px,calc\(100vw - 16px\)\)\}/,'mobile account dropdown must stay inside the viewport');

/* AI Summary footer is one action row; app choices produce switchable result tabs. */
assert.match(ai,/class="sf-listening-ai-footer-actions"/);
assert.match(ai,/data-listening-ai-analyze>Analyze<\/button>[\s\S]*data-listening-ai-brain>Add to Agent Brain<\/button>[\s\S]*data-listening-ai-knowledge>Add to Knowledge Base<\/button>/);
assert.doesNotMatch(ai,/data-listening-ai-saved/,'analysis state must not remain in the footer');
assert.match(ai,/class="sf-listening-ai-report-state"/);
assert.match(ai,/Not analyzed yet/);
assert.match(ai,/data-listening-ai-app-options/);
assert.match(ai,/data-listening-ai-tabs/);
assert.match(ai,/function setActiveApp\(appId\)/);
assert.match(page,/\.sf-listening-ai-footer-actions\{[\s\S]*display:flex!important;[\s\S]*flex-wrap:nowrap!important/);
assert.match(page,/\.sf-listening-ai-report-state\{[\s\S]*display:flex!important/);
assert.match(page,/\.sf-listening-ai-app-options\{display:grid/);
assert.match(page,/\.sf-listening-ai-tabs\{display:flex/);

/* Mobile uses the existing sidebar as a hamburger drawer rather than duplicating the library. */
assert.match(page,/dataset\.listeningMobileMenuToggle = '1'/);
assert.match(page,/aria-controls', sidebar\.id/);
assert.match(page,/body\.sf-listening-mobile-menu-open \.sf-listening-workspace-sidebar\{transform:translateX\(0\)!important\}/);
assert.match(page,/\.sf-listening-workspace-sidebar\{[\s\S]*width:min\(86vw,320px\)!important;[\s\S]*transform:translateX\(-104%\)!important/);
assert.match(page,/data-listening-workspace-file-open\],\[data-listening-workspace-folder\],\[data-listening-workspace-new\],\[data-listening-workspace-create\]/);
assert.match(page,/event\.key === 'Escape'/);

/* AI Summary keeps one direct slideout owner and selected apps remain inside that owner. */
assert.match(ai,/function getButton\(\)/);
assert.match(ai,/document\.querySelector\('\[data-listening-ai-toggle\]'\)/);
assert.match(ai,/button\.addEventListener\('click'/);
assert.doesNotMatch(ai,/document\.addEventListener\('click'/);
assert.match(ai,/function setOpen\(open\)/);
assert.match(ai,/setOpen\(!state\.open\)/);
assert.match(ai,/function setResearchEnabled\(enabled\)/);
assert.match(ai,/data-listening-ai-research/);
assert.match(ai,/apps:state\.selectedApps/);
assert.match(ai,/request\('analyze'/);
assert.match(ai,/save_brain/);
assert.match(ai,/save_knowledge/);
assert.match(ai,/buttonClicks/);
assert.doesNotMatch(ai,/MutationObserver|window\.fetch\s*=|Continuous View|sfListeningTranscriptNav/);

/* Continuous View editing uses canonical attributes and a narrowly scoped observer. */
assert.match(runtime,/const continuousActive = \(\) => Boolean\(continuousContainer\(\) && !continuousContainer\(\)\.hidden\)/);
assert.match(runtime,/button\.dataset\.listeningEditEdit = '1'/);
assert.match(runtime,/undo\.dataset\.listeningEditUndo = '1'/);
assert.match(runtime,/textarea\.dataset\.listeningEditText = '1'/);
assert.match(runtime,/remove\.dataset\.listeningEditDelete = '1'/);
assert.match(runtime,/turn\.dataset\.listeningEditSegmentId = String\(id\)/);
assert.equal((runtime.match(/turn\?\.dataset\?\.listeningEditSegmentId/g) || []).length,5,'all edit persistence paths must use the canonical segment id');
assert.match(runtime,/continuous\.insertAdjacentElement\('afterend', button\)/);
assert.match(runtime,/if \(view === 'continuous'\) ensureContinuousEditControls\(\);/);
assert.match(runtime,/function enterContinuousEdit\(\)/);
assert.match(runtime,/function deleteContinuousTurn\(turn\)/);
assert.match(runtime,/editRequest\('delete_segment'/);
assert.match(runtime,/editRequest\('restore_segment'/);
assert.match(runtime,/new MutationObserver\(records =>/,'only lazy-loaded Continuous View pages may be observed');
assert.match(runtime,/edit\.pageObserver\.observe\(pages, \{childList:true, subtree:true\}\)/);
assert.doesNotMatch(runtime,/dataset\.v(?:251|252)[A-Za-z0-9_]*/,'old edit dataset contracts must be gone');

/* Stale CSRF tokens recover once; the compatibility API remains server-side only. */
assert.match(editApi,/STONEFELLOW_ARTIST_LISTENING_EDIT_V249/);
assert.match(editApi,/\$method === 'GET' && \$getAction === 'csrf'/);
assert.match(runtime,/async function refreshEditCsrf\(\)/);
assert.match(runtime,/target\.searchParams\.set\('action', 'csrf'\)/);
assert.match(runtime,/response\.status === 419 && !retried/);
assert.match(runtime,/return editRequest\(action, payload, true\)/);
assert.match(runtime,/cfg\.csrf = next/);

assert.match(css,/\.sf-listening-workspace-listening-player\{[\s\S]*height:58px!important/);
assert.match(css,/\.sf-listening-ai-panel\{z-index:10040/);
assert.match(css,/\.sf-listening-edit-edit-toggle\.active/);
assert.doesNotMatch(runtime,/MediaRecorder|chat-voice-v142|premium-voice-v117|conversation-voice/);

console.log('ARTIST_LISTENING_UI=PASS');
