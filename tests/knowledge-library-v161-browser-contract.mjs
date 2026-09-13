import fs from 'node:fs';
import process from 'node:process';

const root = new URL('../', import.meta.url);
const read = name => fs.readFileSync(new URL(name, root), 'utf8');
const picker = read('shared-folder-picker-v161.js');
const library = read('knowledge-library-v161.js');
const naming = read('artist-listening-naming.js');
const page = read('knowledge.php');

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

if (failures.length) {
  console.error(`Knowledge Library v16.1 browser contract failed:\n - ${failures.join('\n - ')}`);
  process.exit(1);
}
console.log('Knowledge Library v16.1 browser contract: PASS');
