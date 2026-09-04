import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const page = read('artist-listening.php');
const capture = read('artist-listening.js');
const workspace = read('artist-listening-workspace.js');
const workspaceCss = read('artist-listening.css');
const captureCss = read('artist-listening.css');
const api172 = read('api/artist-listening-v172.php');
const api174 = read('api/artist-listening-v174.php');
const helper = read('includes/artist-listening.php');
const sql = read('upgrade-stonefellow-v172.sql');

const browserUi = `${page}\n${capture}\n${workspace}\n${workspaceCss}\n${captureCss}`;

assert.doesNotMatch(browserUi, /artistListeningDrawer|artist-listening-drawer|data-listening-workspace-open-recorder/);
assert.match(workspace, /sf-listening-workspace-editor-top[\s\S]*data-listening-start[\s\S]*data-listening-workspace-pause[\s\S]*data-listening-stop[\s\S]*data-listening-record/);
assert.match(workspace, /data-listening-workspace-new[\s\S]*createDocument/);
assert.match(workspace, /data-listening-workspace-splash[\s\S]*Create new transcription/);
assert.match(workspace, /data-listening-workspace-splash-message/);
assert.match(workspace, /api174\('create_draft'/);
assert.match(workspace, /api172\('discard'/);
assert.match(workspace, /data-listening-workspace-delete-session/);
assert.match(workspace, /data-listening-workspace-create-folder/);
assert.match(workspace, /data-listening-workspace-delete-folder/);
assert.match(workspace, /data-listening-workspace-folder-select/);
assert.match(workspace, /folder_id:folderId/);
assert.match(workspace, /conversation_id:conversationId/);
assert.match(workspace, /\['marker','note'\]/);
assert.match(workspace, /buildWorkspace\(\);[\s\S]*DOMContentLoaded/);

assert.match(capture, /request\('activate'/);
assert.match(capture, /conversation_id:activeConversationId\(\)/);
assert.match(capture, /stonefellow:artist-listening-document-selected/);
assert.match(capture, /document\.querySelector\('\[data-listening-state\]'/);
assert.doesNotMatch(capture, /createElement\('aside'\)|openDrawer|closeDrawer/);

assert.match(api172, /\$action === 'activate'/);
assert.match(helper, /function artist_listening_v172_activate/);
const coreSchemaCheck = helper.match(/function artist_listening_v172_schema_ready\(\): bool\s*\{([\s\S]*?)\n\}/)?.[1] || '';
assert.doesNotMatch(coreSchemaCheck, /artist_transcript_folders_v177/);
assert.match(api174, /function artist_listening_v174_folder_schema_ready/);
assert.match(api174, /function artist_listening_v174_create_draft/);
assert.match(api174, /function artist_listening_v174_create_folder/);
assert.match(api174, /function artist_listening_v174_delete_folder/);
assert.match(api174, /created_by_user_id=\?/);
assert.match(sql, /CREATE TABLE IF NOT EXISTS artist_transcript_folders_v177/);
assert.match(sql, /FOREIGN KEY \(created_by_user_id\) REFERENCES users\(id\) ON DELETE CASCADE/);

assert.match(capture, /MediaRecorder/);
assert.match(capture, /heard==='start recording'/);
assert.match(capture, /heard==='stop recording'/);
assert.doesNotMatch(workspace, /MediaRecorder/);
assert.match(capture, /audioRetained:true/);
assert.match(workspace, /audioRetained: true/);

console.log('ARTIST_LISTENING_LIBRARY=PASS');
