import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const endpoint = read('api/transcription-knowledge-save-v314.php');
const ui = read('transcription-knowledge-folders-v313.js');
const loader = read('artist-listening-naming.js');
const artist = read('includes/artist-listening.php');
const knowledge = read('includes/knowledge.php');
const knowledgePage = read('knowledge.php');

function requireText(source, needle, message) {
  if (!source.includes(needle)) throw new Error(message || `Missing required text: ${needle}`);
}
function rejectText(source, needle, message) {
  if (source.includes(needle)) throw new Error(message || `Unexpected text: ${needle}`);
}

requireText(endpoint, 'VP3_TRANSCRIPTION_KNOWLEDGE_ROUNDTRIP_V315', 'Round-trip endpoint must publish a hardening build marker.');
requireText(endpoint, "if ($method === 'GET')", 'Round-trip endpoint must expose a read-only saved-state lookup.');
requireText(endpoint, "sha1('artist-listening-transcript:' . max(0, $sessionId))", 'Saved-state lookup must use the same deterministic per-transcription key as persistence.');
requireText(endpoint, "created_by_user_id=? AND knowledge_scope='personal'", 'Saved-state lookup must be owner scoped to Personal Knowledge.');
requireText(endpoint, "file_type='personal_note' AND file_name=?", 'Saved-state lookup must resolve only the canonical Personal Knowledge marker.');
requireText(endpoint, "'view_url'=>$viewUrl", 'Saved state must include a direct My Knowledge destination.');
requireText(endpoint, "'folder'=>['id'=>$folderId,'name'=>$folderName]", 'Saved state must report the Knowledge item actual folder.');
requireText(endpoint, '$pdo->beginTransaction();', 'Save must serialize the round-trip write.');
requireText(endpoint, 'artist_listening_v172_session($pdo, $user, $sessionId, true)', 'Save must lock the owner-scoped transcription row against concurrent duplicate creation.');
requireText(endpoint, "'artist-listening-transcript:' . $sessionId", 'Save must retain deterministic upsert identity.');
requireText(endpoint, "'Source: Artist Listening · transcription #'", 'Saved Knowledge must retain transcription provenance.');
requireText(endpoint, "hash_equals(csrf_token(), $csrf)", 'Mutating saves must remain CSRF protected.');
requireText(endpoint, "SET knowledge_id=?,last_activity_at=NOW()", 'Successful saves must retain the canonical Knowledge link on the transcription.');
rejectText(endpoint, 'INSERT INTO knowledge_items', 'Round-trip endpoint must not bypass the canonical Personal Knowledge store.');
rejectText(endpoint, 'owner_user_id', 'Round-trip endpoint must not revive the legacy Artist Knowledge ownership model.');

requireText(knowledge, "WHERE created_by_user_id=? AND knowledge_scope='personal' AND file_type='personal_note' AND file_name=?", 'Canonical Personal Knowledge must update a deterministic existing row before inserting.');
requireText(knowledge, "UPDATE knowledge_items", 'Canonical Personal Knowledge must update repeat saves instead of always inserting.');

requireText(ui, 'async function loadTranscriptKnowledgeState', 'Transcriptions must load persisted My Knowledge state when a document is opened.');
requireText(ui, "method: 'GET'", 'Persisted status must use the read-only endpoint path.');
requireText(ui, "cache: 'no-store'", 'Persisted status must not reuse stale browser cache.');
requireText(ui, 'currentSessionId() !== sessionId', 'Late status responses must not overwrite a newly selected transcription.');
requireText(ui, 'data-transcription-knowledge-view', 'Raw transcription status must expose View in My Knowledge.');
requireText(ui, "link.textContent = 'View in My Knowledge'", 'AI Summary saves must also expose View in My Knowledge.');
requireText(ui, "window.addEventListener('pageshow'", 'Returning through browser navigation must refresh saved state.');
requireText(ui, "document.addEventListener('visibilitychange'", 'Returning from My Knowledge in another tab must refresh moved/deleted state.');
requireText(ui, "document.visibilityState !== 'visible'", 'Visibility refresh must only run when the transcription page becomes visible.');
requireText(ui, "statusSessionId !== sessionId", 'MutationObserver passes must deduplicate saved-state fetches.');
requireText(ui, 'knowledgeViewUrl(data.knowledge_id, folderId)', 'AI Summary saves must link to the exact Personal Knowledge item.');
requireText(ui, "document.querySelectorAll('[data-listening-ai-brain]').forEach(button => button.remove())", 'The removed AI Summary Agent Brain action must stay removed.');
requireText(loader, 'transcription-knowledge-roundtrip-v315-20260917', 'The page-scoped loader must bust the previous Knowledge bridge asset URL.');

requireText(artist, 'FOREIGN KEY (knowledge_id) REFERENCES knowledge_items(id) ON DELETE SET NULL', 'Deleting a Personal Knowledge copy must detach, not delete, its source transcription.');
const discardMatch = artist.match(/function artist_listening_v172_discard[\s\S]*?function artist_listening_v172_restore/);
if (!discardMatch) throw new Error('Could not isolate transcription discard behavior.');
rejectText(discardMatch[0], 'DELETE FROM', 'Deleting/discarding a transcription must not delete its Personal Knowledge copy.');
rejectText(discardMatch[0], 'knowledge_items', 'Transcription discard must remain independent of Personal Knowledge persistence.');

requireText(knowledgePage, "DELETE FROM knowledge_items WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal'", 'My Knowledge delete must remain owner scoped and delete only the Personal Knowledge item.');
requireText(knowledgePage, "UPDATE knowledge_items SET folder_id=?", 'Moving an item in My Knowledge must preserve the same Knowledge row identity.');
requireText(knowledgePage, "i.created_by_user_id=? AND i.knowledge_scope='personal'", 'My Knowledge listing must use the same owner/scope as round-trip status.');

console.log('Transcription Knowledge round-trip v3.15 contract passed.');
