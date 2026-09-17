import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const folderRuntime = readFileSync('transcription-knowledge-folders-v313.js', 'utf8');
const namingRuntime = readFileSync('artist-listening-naming.js', 'utf8');
const page = readFileSync('artist-listening.php', 'utf8');

// The folder integration watches child-list mutations across the document. Any DOM
// write performed unconditionally by the observer callback can feed the observer
// back into itself and freeze the Transcriptions page.
assert.match(
  folderRuntime,
  /new MutationObserver\(\(\) => ensureControl\(\)\)[\s\S]*?childList:true/,
  'Transcription folder integration must keep its document observer contract explicit',
);
assert.match(
  folderRuntime,
  /if \(button\.textContent !== 'Save to folder'\) button\.textContent = 'Save to folder';/,
  'Observer-controlled button text must only mutate when its value actually changes',
);
assert.doesNotMatch(
  folderRuntime,
  /\n\s*button\.textContent = 'Save to folder';/,
  'Observer callback must not unconditionally replace the button text node',
);

// Folder option rendering has its own signature guard so the observer cannot churn
// the select options after they are already synchronized.
assert.match(
  folderRuntime,
  /if \(!force && select\.dataset\.folderSignature === signature && select\.options\.length\) return;/,
  'Folder options must retain their idempotent signature guard',
);

// Keep both loader layers cache-busted whenever the folder/Knowledge bridge changes,
// while preserving the original observer hotfix guarantees.
assert.ok(
  namingRuntime.includes('transcription-knowledge-folders-v313.js?v=transcription-knowledge-roundtrip-v315-20260917'),
  'Artist Listening companion loader must cache-bust the current folder/Knowledge runtime',
);
assert.ok(
  page.includes("artist-listening-naming.js?v=transcription-folder-hotfix-20260913"),
  'Transcriptions page must cache-bust the companion loader itself',
);

console.log('TRANSCRIPTION_FOLDER_OBSERVER_HOTFIX=PASS');
