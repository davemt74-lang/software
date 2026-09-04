import fs from 'node:fs';
import assert from 'node:assert/strict';

const page=fs.readFileSync('admin/stems.php','utf8');
const advancedStart=page.indexOf('if ($advancedStudioRuntime)');
const safeStart=page.indexOf('} else {',advancedStart);
const safeEnd=page.indexOf("\n}\n\n$html = preg_replace('~<script",safeStart);
assert.ok(advancedStart>=0 && safeStart>advancedStart && safeEnd>safeStart,'default Stem runtime branch must remain structurally identifiable');
const safe=page.slice(safeStart,safeEnd);

assert.match(safe,/\$editingRuntime \. \$professionalEditingRuntime/,'stable v209 and v210 runtimes must remain enabled');
assert.doesNotMatch(safe,/automationMixerRuntime/,'v211 must not execute in the default boot path');
assert.doesNotMatch(page,/data-stem-recovery-v227/,'obsolete recovery cache marker must remain removed');
assert.doesNotMatch(page,/STUDIO SAFE RUNTIME · PHASE 2/,'obsolete recovery badge must remain removed');

console.log('STEM_V211_QUARANTINE_V227=PASS');
