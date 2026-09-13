import fs from 'node:fs';
import process from 'node:process';

const root = new URL('../', import.meta.url);
const read = name => fs.readFileSync(new URL(name, root), 'utf8');
const picker = read('shared-folder-picker-v161.js');
const library = read('knowledge-library-v161.js');
const naming = read('artist-listening-naming.js');
const page = read('knowledge.php');
const css = read('personal-knowledge.css');

const failures = [];
const check = (ok, message) => { if (!ok) failures.push(message); };

check(picker.includes("[data-listening-workspace-folder-select]"), 'Shared picker does not enhance transcription/music folder selection.');
check(picker.includes("[data-listening-ai-folder]"), 'Shared picker does not enhance AI Summary folders.');
check(picker.includes("[data-vp3-shared-folder-picker]"), 'Shared picker does not enhance Knowledge selectors.');
check(picker.includes("workspace.createFolder"), 'Artist Listening folder creation does not use the canonical workspace API.');
check(picker.includes("workspace.filterLibrary"), 'Inline folder creation does not preserve the prior Artist Listening library filter.');
check(picker.includes("stopImmediatePropagation"), 'New-folder sentinel can leak into existing select change handlers.');
check(library.includes("addEventListener('dragstart'"), 'Knowledge rows are not draggable.');
check(library.includes("addEventListener('drop'"), 'Knowledge folder cards are not drop targets.');
check(library.includes("data-knowledge-select-all"), 'Knowledge bulk selection is missing.');
check(naming.includes('shared-folder-picker-v161.js'), 'Artist Listening does not load the shared picker.');
check(page.includes('knowledge-library-v161.js'), 'Knowledge page does not load library interactions.');
check(page.includes('shared-folder-picker-v161.js'), 'Knowledge page does not load the shared picker.');

// Folder-first navigation and compact action row.
check(library.includes('personal-knowledge-stat-actions'), 'New Folder / Add Knowledge actions are not mounted in the stats row.');
check(library.includes('New Folder') && library.includes('Add Knowledge'), 'Knowledge stats-row actions are incomplete.');
check(library.includes('removeLegacyHeaderAddAction'), 'Legacy header Add Knowledge action is not removed after moving it into the stats row.');
check(library.includes('personal-knowledge-folder-open') && library.includes('Back to folders'), 'Opening a folder does not switch to a focused folder view with Back to folders.');
check(css.includes('.personal-knowledge-folder-open .personal-knowledge-folder-grid{display:none}'), 'Folder cards remain visible after entering a focused folder.');
check(css.includes('.personal-knowledge-folder-card .folder-mark:before') && css.includes('.personal-knowledge-folder-card .folder-mark:after'), 'Knowledge folders do not render with a folder-shaped icon.');
check(css.includes('.personal-knowledge-hero>:first-child:not(.personal-knowledge-stats){display:none}'), 'Legacy Personal Agent Data / Knowledge Library hero copy is not removed from the visual layout.');

// Click-through item/document view, including generated transcription provenance.
check(library.includes('openItemDetail') && library.includes('personal-knowledge-open-item'), 'Knowledge items cannot be opened into a focused document view.');
check(library.includes('personal-knowledge-document-body'), 'Focused item view does not expose the stored Knowledge text.');
check(library.includes('System-originated transcription'), 'System-originated transcription detail is not identified in focused item view.');
check(library.includes('personal-knowledge-source') && library.includes('Open file'), 'Focused Knowledge detail does not preserve source/file actions.');
check(library.includes('showModal()') && library.includes('knowledge-form-dialog'), 'Add Knowledge does not open from the compact stats-row action.');

if (failures.length) {
  console.error(`Knowledge Library v16.1 browser contract failed:\n - ${failures.join('\n - ')}`);
  process.exit(1);
}
console.log('Knowledge Library v16.1 browser contract: PASS');
