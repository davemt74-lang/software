import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const endpoint = read('api/transcription-knowledge-save-v314.php');
const ui = read('transcription-knowledge-folders-v313.js');
const loader = read('artist-listening-naming.js');
const knowledgePage = read('knowledge.php');

function requireText(source, needle, message) {
  if (!source.includes(needle)) throw new Error(message || `Missing required text: ${needle}`);
}
function rejectText(source, needle, message) {
  if (source.includes(needle)) throw new Error(message || `Unexpected text: ${needle}`);
}

requireText(loader, 'transcription-knowledge-folders-v313.js', 'Artist Listening must load the folder-aware Knowledge bridge.');

requireText(endpoint, "personal_capability_has_v242('personal_knowledge.manage'", 'Save endpoint must require Personal Knowledge management.');
requireText(endpoint, 'personal_knowledge_folder($pdo, $user, $folderId)', 'Save endpoint must validate the selected owner-scoped folder.');
requireText(endpoint, 'personal_knowledge_store(', 'Transcriptions must use the canonical Personal Knowledge store.');
requireText(endpoint, "'artist-listening-transcript:' . $sessionId", 'Repeated saves must use a deterministic transcription key.');
requireText(endpoint, "'Source: Artist Listening · transcription #'", 'Saved items must identify their transcription source for My Knowledge.');
requireText(endpoint, "knowledge_scope='personal'", 'Folder correction must remain scoped to Personal Knowledge.');
requireText(endpoint, 'created_by_user_id=?', 'Knowledge and transcript updates must stay scoped to the signed-in owner.');
requireText(endpoint, 'folder_id=?', 'Explicit Unfiled/folder moves must update the existing Personal Knowledge item.');
requireText(endpoint, "hash_equals(csrf_token(), $csrf)", 'Save endpoint must enforce CSRF.');
rejectText(endpoint, 'owner_user_id', 'The repaired save endpoint must not recreate the legacy Artist Knowledge writer.');
rejectText(endpoint, 'INSERT INTO knowledge_items', 'The repaired endpoint must delegate persistence to the canonical Personal Knowledge store.');

requireText(ui, '[data-listening-workspace-knowledge]', 'UI bridge must intercept the open-transcription Knowledge action.');
requireText(ui, 'event.stopImmediatePropagation();', 'UI bridge must stop the obsolete legacy save handler.');
requireText(ui, 'api/transcription-knowledge-save-v314.php', 'Open transcription save must use the repaired endpoint.');
requireText(ui, 'folder_id: folderId', 'Knowledge saves must submit the chosen folder.');
requireText(ui, 'Saved to My Knowledge ·', 'Successful saves must show explicit confirmation.');
requireText(ui, '[data-listening-workspace-folder-select]', 'Open transcription save must honor the visible selected folder.');
requireText(ui, "document.querySelectorAll('[data-listening-ai-brain]').forEach(button => button.remove())", 'AI Summary Agent Brain action must be removed from the slide-out UI.');
requireText(ui, '[data-listening-ai-brain]{display:none!important}', 'Agent Brain action must remain hidden during dynamic AI panel rendering.');
requireText(ui, "source:'transcript'", 'Raw transcription saves must publish a success event.');
requireText(ui, "source:'ai_summary'", 'AI summary saves must retain their folder-aware success event.');
requireText(ui, 'if (node.textContent !== message) node.textContent = message;', 'Mutation-observed status text must only change when its value changes.');
requireText(ui, "if (node.dataset.state !== nextState) node.dataset.state = nextState;", 'Mutation-observed status state must only change when its value changes.');

requireText(knowledgePage, "i.created_by_user_id=? AND i.knowledge_scope='personal'", 'My Knowledge must continue listing the same Personal Knowledge scope used by the repaired endpoint.');

console.log('Transcription Knowledge v3.14 contract passed.');
