import fs from 'node:fs';

const read=path=>fs.readFileSync(path,'utf8');
const page=read('video-editor.php');
const editor=read('video-editor.js');
const header=read('video-header-v92.js');
const agent=read('editor-agent-v131.js');

function assert(ok,label){
  console.log(`${ok?'PASS':'FAIL'} ${label}`);
  if(!ok)throw new Error(`Failed: ${label}`);
}

assert(page.includes('/video-editor.js?v='),'Video page loads canonical editor runtime');
assert(!page.includes('/video-editor-v90.js'),'Video page does not load versioned v90 editor runtime');
assert(page.includes("hash_file('sha256', __DIR__ . '/video-editor.js')"),'canonical editor uses a content-derived cache token');
assert(page.indexOf('/video-editor.js?v=')<page.indexOf('/video-header-v92.js'),'canonical editor loads before the Video header consumer');
assert(page.indexOf('/video-editor.js?v=')<page.indexOf('/editor-agent-v131.js'),'canonical editor loads before the active Editor Agent consumer');
assert(editor.includes('window.StonefellowVideoEditor={'),'canonical editor publishes unversioned bridge');
assert(!editor.includes('StonefellowVideoEditorV90'),'canonical editor contains no v90 ownership alias');
assert(header.includes('window.StonefellowVideoEditor||null'),'Video header/autosave uses canonical bridge');
assert(!header.includes('StonefellowVideoEditorV90'),'Video header has no versioned bridge dependency');
assert(agent.includes('window.StonefellowVideoEditor,state='),'active Editor Agent uses canonical Video bridge');
assert(!agent.includes('StonefellowVideoEditorV90'),'active Editor Agent has no versioned Video bridge dependency');
for(const token of ['getState:','executeCommands','saveProject','previewLibraryAsset','getProjectId:','getPlayhead:','recordLedger','diffStates']){
  assert(editor.includes(token),`canonical editor preserves ${token.replace(':','')} capability`);
}
assert(editor.includes('selected_id:selectedId'),'canonical state publishes the current Video selection for Editor Agent adapters');
assert(editor.includes('return {before,after,changes:diffStates(before,after)}'),'command execution preserves before/after verification evidence');
assert(editor.includes("document.dispatchEvent(new CustomEvent('stonefellow:video-change'"),'editor continues publishing change events for autosave');
assert(editor.includes("sourceError(`“${asset.title||'This media item'}” is saved but its file is unavailable.`)"),'canonical editor preserves unavailable-media handling');

console.log('VIDEO_EDITOR_CANONICAL=PASS');