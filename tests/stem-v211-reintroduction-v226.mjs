import fs from 'node:fs';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';

const page=fs.readFileSync('admin/stems.php','utf8');
const runtime=fs.readFileSync('admin/stem-automation-mixer-v211.js','utf8');

execFileSync(process.execPath,['--check','admin/stem-automation-mixer-v211.js'],{stdio:'pipe'});

const advancedStart=page.indexOf('if ($advancedStudioRuntime)');
const safeStart=page.indexOf('} else {',advancedStart);
const safeEnd=page.indexOf("\n}\n\n$html = preg_replace('~<script",safeStart);
assert.ok(advancedStart>=0 && safeStart>advancedStart && safeEnd>safeStart,'default Stem runtime branch must remain structurally identifiable');
const safe=page.slice(safeStart,safeEnd);
assert.ok(safe.indexOf('$editingRuntime')<safe.indexOf('$professionalEditingRuntime'),'v209 must precede v210');
assert.doesNotMatch(safe,/automationMixerRuntime|recordingTakesRuntime|recordingEngineRuntime|renderExportRuntime|audioEngineRuntime|sessionSafetyRuntime/,'v211-v216 must remain quarantined after the v211 performance regression');
assert.doesNotMatch(page,/STUDIO SAFE RUNTIME · PHASE 2/,'obsolete recovery badge must remain removed');
assert.doesNotMatch(page,/data-stem-recovery-v227/,'obsolete recovery badge hook must remain removed');
assert.match(runtime,/StonefellowStemProfessionalEditingV210Runtime/,'v211 must use the v210 editing runtime');
assert.doesNotMatch(runtime,/SpeechRecognition|ElevenLabs|conversation-voice/,'v211 must not create another voice owner');

console.log('STEM_V211_REINTRODUCTION_V226=PASS');
