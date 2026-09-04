import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const page = read('artist-listening.php');
const client = read('artist-listening-workspace.js');
const css = read('artist-listening.css');

/* Canonical boot assets only. */
const build='artist-listening-normalized-20260903';
assert.match(page,new RegExp(`artist-listening-realtime\\.js\\?v=e07b7c39[\\s\\S]*artist-listening-recognition\\.js\\?v=${build}[\\s\\S]*artist-listening-transcript\\.js\\?v=${build}[\\s\\S]*artist-listening-workspace\\.js\\?v=${build}[\\s\\S]*artist-listening\\.js\\?v=9ac023be`));
assert.match(page,new RegExp(`artist-listening\\.css\\?v=${build}`));
assert.doesNotMatch(page,/artist-listening[^"']*-v\d+[^"']*\.(?:js|css)/,'workspace boot must not use numbered Artist Listening frontend layers');
assert.doesNotMatch(page,/Loading Artist Listening|sf-listening-card/);
assert.doesNotMatch(page,/id="artistListeningButton"/,'page markup must not duplicate the workspace-created listener button');

assert.match(client,/const BUILD = 'artist-listening-workspace'/);
assert.match(client,/window\.STONEFELLOW_ARTIST_LISTENING_WORKSPACE =/);
assert.match(client,/workspaceMode/);
assert.match(client,/sessionCount/);
assert.doesNotMatch(client,/<h2>My Recordings<\/h2>|>MY RECORDINGS</,'retired My Recordings heading must stay gone');
assert.doesNotMatch(client,/Transcript text remains in the original private session\.|Phase 2 · transcription by default · retained audio is opt-in/,'retired footer copy must be removed from source');
assert.match(client,/const accordionKey = `stonefellow:artist-listening:sidebar:\$\{accordionUserId\}`/);
assert.match(client,/legacyAccordionKey = `stonefellow:artist-listening:sidebar:v238:\$\{accordionUserId\}`/,'one-time localStorage migration may read the old key');
assert.match(client,/localStorage\.setItem\(accordionKey/);
assert.match(client,/data-listening-workspace-accordion/);
assert.match(client,/All Recordings/);
assert.match(client,/Studio Projects/);
assert.match(client,/No song \/ project/);
assert.match(client,/No transcription loaded/);
assert.match(client,/Create new transcription/);
assert.match(client,/create_draft/);
assert.match(client,/data-listening-workspace-delete-session/);
assert.match(client,/data-listening-workspace-folder-select/);
assert.match(client,/data-listening-workspace-create-folder/);
assert.match(client,/data-listening-start/);
assert.match(client,/data-listening-stop/);
assert.match(client,/data-listening-record/);
assert.match(client,/Start Listening/);
assert.match(client,/Stop Listening/);
assert.match(client,/Audio Clips/);
assert.match(client,/sf-listening-workspace-listening-player/);
assert.match(client,/data-listening-workspace-play-recording/);
assert.match(client,/Search transcripts/);
assert.match(client,/Transcript document editor/);
assert.match(client,/Speaker turn transcript/);
assert.match(client,/stonefellow:artist-listening-live/);
assert.match(client,/update_turn/);
assert.match(client,/data-listening-workspace-live-interim/);
assert.match(client,/replace_transcript/);
assert.match(client,/update_metadata/);
assert.match(client,/association_type/);
assert.match(client,/studio_project/);
assert.match(client,/Send selection to Agent Brain/);
assert.match(client,/Send selection to Knowledge Base/);
assert.match(client,/Send selection to Project Notes/);
assert.match(client,/pauseTranscription/);
assert.match(client,/resumeTranscription/);
assert.match(client,/Too quiet/);
assert.match(client,/Clipping/);
assert.match(client,/audioRetained: true/);

/* Header is exactly EXIT + Save + AI Summary; view controls live elsewhere. */
assert.match(client,/data-listening-workspace-exit/);
assert.match(client,/data-listening-workspace-save>Save<\/button>/);
assert.match(client,/data-listening-ai-toggle aria-expanded="false"/);
assert.doesNotMatch(client,/>Copy<\/button>|>Download<\/button>|copyDocument|downloadDocument/);

/* Sidebar files remain usable unless this browser is actually transcribing. */
assert.match(client,/function browserListeningActive\(\)/);
assert.match(client,/const listeningActive = browserListeningActive\(\)/);
assert.match(client,/if \(!listeningActive && state\.liveSessionId\)/);
assert.match(client,/listeningActive \? state\.sessions\.find\(row => String\(row\.status \|\| ''\) === 'active'\)\?\.id : 0/);
assert.match(client,/if \(listeningActive && activeSessionId && id !== activeSessionId\)/);
assert.match(client,/const data = await api174\('session', \{session_id:id\}\)/,'sidebar must retain canonical session loader');
assert.match(client,/file\.dataset\.listeningWorkspaceFileOpen/,'sidebar file clicks must use canonical dataset names');
assert.match(client,/proof\.sidebarOpens \+= 1/);
assert.match(client,/if \(browserListeningActive\(\)\) \{[\s\S]*Stop the active transcription before creating another transcript/);
assert.doesNotMatch(client,/dataset\.v175FileOpen/,'old v175 file-open contract must be gone');

assert.doesNotMatch(client,/MediaRecorder/,'workspace must not own retained-audio capture');
assert.doesNotMatch(client,/artistListeningDrawer|data-listening-workspace-open-recorder/);

assert.match(css,/grid-template-columns:292px minmax\(0,1fr\)/);
assert.match(css,/sf-listening-workspace-sidebar/);
assert.match(css,/sf-listening-workspace-files/);
assert.match(css,/sf-listening-workspace-accordion/);
assert.match(css,/\.sf-listening-workspace-listening-player\{position:fixed!important;z-index:10020!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important/,'listening player must remain full-width and fixed to the viewport bottom');
assert.match(css,/\.sf-listening-workspace-clip-menu/);
assert.match(css,/sf-listening-workspace-document-area/);
assert.match(css,/sf-listening-workspace-editor/);
assert.match(css,/sf-listening-workspace-inspector/);
assert.match(css,/sf-listening-workspace-workspace-open/);
assert.match(css,/sf-transcript-workspace\[hidden\]\{display:none!important\}/);

console.log('ARTIST_LISTENING_WORKSPACE=PASS');
