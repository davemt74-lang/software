import fs from 'node:fs';
import process from 'node:process';

const root = new URL('../', import.meta.url);
const read = name => fs.readFileSync(new URL(name, root), 'utf8');
const picker = read('shared-folder-picker-v161.js');
const library = read('knowledge-library-v161.js');
const naming = read('artist-listening-naming.js');
const page = read('knowledge.php');
const css = read('personal-knowledge.css');
const hardeningCss = read('knowledge-library-folder-browser-v193.css');

const failures = [];
const check = (ok, message) => { if (!ok) failures.push(message); };

check(picker.includes("[data-listening-workspace-folder-select]"), 'Shared picker does not enhance transcription/music folder selection.');
check(picker.includes("[data-listening-ai-folder]"), 'Shared picker does not enhance AI Summary folders.');
check(picker.includes("[data-vp3-shared-folder-picker]"), 'Shared picker does not enhance Knowledge selectors.');
check(picker.includes('workspace.createFolder'), 'Artist Listening folder creation does not use the canonical workspace API.');
check(picker.includes('workspace.filterLibrary'), 'Inline folder creation does not preserve the prior Artist Listening library filter.');
check(picker.includes('stopImmediatePropagation'), 'New-folder sentinel can leak into existing select change handlers.');
check(library.includes("addEventListener('dragstart'"), 'Knowledge rows are not draggable.');
check(library.includes("addEventListener('drop'"), 'Knowledge folder cards are not drop targets.');
check(library.includes('data-knowledge-select-all'), 'Knowledge bulk selection is missing.');
check(naming.includes('shared-folder-picker-v161.js'), 'Artist Listening does not load the shared picker.');
check(page.includes('knowledge-library-v161.js'), 'Knowledge page does not load library interactions.');
check(page.includes('shared-folder-picker-v161.js'), 'Knowledge page does not load the shared picker.');

// Restored folder-first browser and moved actions.
check(library.includes('personal-knowledge-stat-actions'), 'Add Folder / Add Knowledge actions are not mounted in the top stats row.');
check(library.includes('Add Folder') && library.includes('Add Knowledge'), 'Top Knowledge actions are incomplete.');
check(library.includes('removeLegacyHeaderAddAction'), 'Legacy header Add Knowledge action is not removed after moving it into the Knowledge canvas.');
check(library.includes('personal-knowledge-folder-open') && library.includes('Back to folders'), 'Opening a folder does not switch to a focused folder view.');
check(css.includes('.personal-knowledge-folder-open .personal-knowledge-folder-grid{display:none}'), 'Folder cards remain visible after entering a focused folder.');
check(css.includes('.personal-knowledge-folder-card .folder-mark:before') && css.includes('.personal-knowledge-folder-card .folder-mark:after'), 'Knowledge folders do not render with a folder-shaped icon.');
check(css.includes('Folder-first Knowledge library refinement'), 'Restored folder-first Knowledge CSS is missing.');

// Focused document/detail flow.
check(library.includes('openItemDetail') && library.includes('personal-knowledge-open-item'), 'Knowledge items cannot open into a focused document view.');
check(library.includes('personal-knowledge-document-body'), 'Focused item view does not expose stored Knowledge text.');
check(library.includes('System-originated transcription'), 'System-originated transcription provenance is missing.');
check(library.includes('textWithBreaks'), 'Focused document view does not preserve stored line breaks.');
check(library.includes('showModal()') && library.includes('knowledge-form-dialog'), 'Add Knowledge is not dialog-enhanced.');

// 10/10 hardening: cleanup, focus, accessibility and mobile safety.
check(library.includes("addEventListener('close'") && library.includes('dialog.remove()'), 'Dialog lifecycle does not clean up closed dialogs.');
check(library.includes('returnFocus') && library.includes('preventScroll'), 'Dialog close does not restore focus to the invoking control.');
check(library.includes("setAttribute('aria-current', 'page')"), 'Focused folder navigation does not expose aria-current.');
check(library.includes("setAttribute('aria-label', 'Knowledge actions')"), 'Knowledge action group is not labelled.');
check(library.includes('!event.dataTransfer'), 'Drag/drop handlers do not guard missing DataTransfer state.');
check(library.includes('knowledge-library-folder-browser-v193.css?v=20260914'), 'Dedicated hardening stylesheet is not loaded with a unique build URL.');
check(hardeningCss.includes(':focus-visible'), 'Keyboard focus treatment is missing.');
check(hardeningCss.includes('prefers-reduced-motion'), 'Reduced-motion handling is missing.');
check(hardeningCss.includes('@media(max-width:420px)'), 'Small-phone action layout is missing.');

if (failures.length) {
  console.error(`Knowledge Library folder-browser contract failed:\n - ${failures.join('\n - ')}`);
  process.exit(1);
}
console.log('Knowledge Library folder-browser contract: PASS');
