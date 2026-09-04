import fs from 'node:fs';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';

const page=fs.readFileSync('admin/stems.php','utf8');
const runtime=fs.readFileSync('admin/stem-professional-editing-v210.js','utf8');

execFileSync(process.execPath,['--check','admin/stem-professional-editing-v210.js'],{stdio:'pipe'});

const advancedStart=page.indexOf('if ($advancedStudioRuntime)');
const safeStart=page.indexOf('} else {',advancedStart);
const safeEnd=page.indexOf("\n}\n\n$html = preg_replace('~<script",safeStart);
assert.ok(advancedStart>=0 && safeStart>advancedStart && safeEnd>safeStart,'default Stem runtime branch must remain structurally identifiable');
const safe=page.slice(safeStart,safeEnd);
assert.ok(safe.indexOf('$editingRuntime')<safe.indexOf('$professionalEditingRuntime'),'v209 must load before dependent v210');
assert.doesNotMatch(safe,/automationMixerRuntime|recordingTakesRuntime|recordingEngineRuntime|renderExportRuntime|audioEngineRuntime|sessionSafetyRuntime/);
assert.doesNotMatch(page,/STUDIO SAFE RUNTIME · PHASE 2/,'obsolete recovery badge must remain removed');
assert.doesNotMatch(page,/data-stem-recovery-v227/,'obsolete recovery badge hook must remain removed');
assert.match(runtime,/StonefellowStemEditingV209Runtime/,'v210 must explicitly depend on v209');

console.log('STEM_V210_REINTRODUCTION_V225=PASS');
