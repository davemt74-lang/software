import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync('artist-listening.php','utf8');
const client = fs.readFileSync('artist-listening-ui.js','utf8');
const css = fs.readFileSync('artist-listening.css','utf8');
const api = fs.readFileSync('api/artist-listening-edit-v249.php','utf8');

/* Editing is owned by the single canonical UI controller. */
assert.doesNotMatch(page,/artist-listening-edit-v249\.js/,'legacy Page View edit client must not load');
assert.doesNotMatch(page,/artist-listening-edit-v249\.css/,'legacy Page View edit CSS must not load');
assert.match(page,/artist-listening-ui\.js\?v=artist-listening-normalized-20260903/,'canonical UI controller must own Continuous View editing');
assert.doesNotMatch(page,/artist-listening-ui-v\d+\.js/,'numbered Artist Listening UI controllers must not return');
assert.match(client,/const BUILD = 'artist-listening-ui'/);
assert.match(client,/window\.STONEFELLOW_ARTIST_LISTENING_UI =/);

/* Controls use canonical data attributes only. */
assert.match(client,/button\.dataset\.listeningEditEdit = '1'/,'Edit control must be created only for Continuous View');
assert.match(client,/undo\.dataset\.listeningEditUndo = '1'/,'Undo control must be available while editing');
assert.match(client,/textarea\.dataset\.listeningEditText = '1'/,'each Continuous View section must become directly editable');
assert.match(client,/remove\.dataset\.listeningEditDelete = '1'/,'each editable section must expose Delete');
assert.match(client,/turn\.dataset\.listeningEditSegmentId = String\(id\)/,'loaded transcript sections must receive the canonical segment id');
assert.equal((client.match(/turn\?\.dataset\?\.listeningEditSegmentId/g) || []).length, 5,'all five save/delete/undo paths must read the canonical segment id');
assert.doesNotMatch(client,/dataset\.v(?:251|252)[A-Za-z0-9_]*/,'old v251/v252 edit dataset properties must be gone');

assert.match(client,/continuous\.insertAdjacentElement\('afterend', button\)/,'Edit must sit beside the Continuous View\/Page View toggle');
assert.match(client,/window\.addEventListener\('stonefellow:artist-listening-view-changed'/,'Edit must mount from the completed Continuous View transition');
assert.match(client,/if \(view === 'continuous'\) ensureContinuousEditControls\(\);/,'Edit must be guaranteed after Continuous View finishes opening');
assert.match(client,/if \(!bar \|\| !activeView\) \{/,'Edit must remain hidden outside Continuous View');
assert.match(client,/button\.textContent = edit\.active \? 'Done' : 'Edit'/,'Edit toggles to Done while editing');
assert.match(client,/Ctrl\/Cmd\+Z/,'Undo shortcut must be documented');
assert.match(client,/event\.key\.toLowerCase\(\) !== 'z'/,'Ctrl\/Cmd+Z must be handled');
assert.match(client,/setTimeout\(\(\) => \{[\s\S]*void saveTurn\(turn\);[\s\S]*\}, 700\)/,'section edits must auto-save with a short debounce');
assert.match(client,/editRequest\('update_segment'/,'section text edits must persist');
assert.match(client,/editRequest\('delete_segment'/,'Delete must persist through the edit API');
assert.match(client,/editRequest\('restore_segment'/,'Undo must restore a deleted section');
assert.match(client,/edit\.undo\.push\(\{type:'edit'/,'saved edits must enter the undo stack');
assert.match(client,/edit\.undo\.push\(\{type:'delete'/,'deleted sections must enter the undo stack');
assert.match(client,/document\.body\.classList\.add\('sf-listening-edit-continuous-edit'\)/);
assert.match(client,/async function exitContinuousEdit\(silent = false\) \{[\s\S]*await flushAll\(\);/,'leaving edit mode must flush queued autosaves');
assert.match(client,/if \(!proof\.continuousView && edit\.active\) \{[\s\S]*void exitContinuousEdit\(true\);/,'returning to Page View must exit edit mode through the flush path');
assert.match(client,/response\.status === 419 && !retried/,'stale CSRF must recover without forcing a page refresh');
assert.match(client,/return editRequest\(action, payload, true\)/,'autosave must retry once with the refreshed CSRF token');
assert.match(client,/\/api\/artist-listening-v172\.php/,'canonical UI must retain the real base compatibility endpoint');
assert.match(client,/artist-listening-long-v237\.php/,'canonical UI must retain the real long-transcript compatibility endpoint');
assert.doesNotMatch(client,/MediaRecorder|chat-voice-v142|premium-voice-v117|conversation-voice/);

/* Backend remains a versioned compatibility endpoint until the API migration. */
assert.match(api,/STONEFELLOW_ARTIST_LISTENING_EDIT_V249/);
assert.match(api,/has_permission\('artist_listening\.access'/);
assert.match(api,/hash_equals\(csrf_token\(\), \$csrf\)/);
assert.match(api,/\$method === 'GET' && \$getAction === 'csrf'/,'edit API must expose authenticated CSRF refresh');
assert.match(api,/Stop Listening before editing transcript sections/);
assert.match(api,/Restore this transcript before editing it/);
assert.match(api,/action === 'update_segment'/);
assert.match(api,/action === 'delete_segment'/);
assert.match(api,/action === 'restore_segment'/);
assert.match(api,/SET segment_type='deleted'/);
assert.match(api,/SET segment_type='transcript'/);
assert.match(api,/mb_strlen\(\$text\) > 8000/);
assert.doesNotMatch(api,/CREATE TABLE|ALTER TABLE/);

assert.match(css,/\.sf-listening-edit-edit-toggle\.active/,'Continuous View Edit must have a visible active state');
assert.match(css,/\.sf-listening-edit-delete/,'Continuous View section Delete must be styled');
assert.match(css,/body\.sf-listening-edit-continuous-edit #sfListeningTranscriptContinuous \[data-listening-edit-text\]/,'editable section text must be scoped to explicit Continuous View edit mode');

console.log('ARTIST_LISTENING_EDIT=PASS');
