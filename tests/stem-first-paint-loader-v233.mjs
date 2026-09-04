import fs from 'node:fs';
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';

const wrapper = fs.readFileSync('admin/stems.php','utf8');
const scheduler = fs.readFileSync('admin/stem-buffer-scheduler-v202.js','utf8');
const loader = fs.readFileSync('admin/stem-project-loader.js','utf8');
const legacy = fs.readFileSync('admin/stems-legacy-v108.php','utf8');
const studio = fs.readFileSync('admin/stem-editor.js','utf8');

const gitBlobSha = source => {
  const bytes = Buffer.from(source);
  return createHash('sha1')
    .update(Buffer.from(`blob ${bytes.length}\0`))
    .update(bytes)
    .digest('hex');
};
const transportToken = wrapper.match(/\$transportToken = '([a-f0-9]{8})';/);
assert.ok(transportToken,'transport-family cache token must be content-derived');
const transportFamily = [
  ['admin/stem-master-clock-v201.js',fs.readFileSync('admin/stem-master-clock-v201.js','utf8')],
  ['admin/stem-buffer-scheduler-v202.js',scheduler],
  ['admin/stem-time-stretch-v203.js',fs.readFileSync('admin/stem-time-stretch-v203.js','utf8')],
  ['admin/stem-time-stretch-worklet-v203.js',fs.readFileSync('admin/stem-time-stretch-worklet-v203.js','utf8')],
  ['admin/stem-loop-planner-v204.js',fs.readFileSync('admin/stem-loop-planner-v204.js','utf8')],
  ['admin/stem-transport-v200.js',fs.readFileSync('admin/stem-transport-v200.js','utf8')]
];
const familyHash = createHash('sha1');
for (const [path,source] of transportFamily) {
  familyHash.update(path);
  familyHash.update('\0');
  familyHash.update(gitBlobSha(source));
  familyHash.update('\0');
}
assert.equal(transportToken[1],familyHash.digest('hex').slice(0,8),'transport-family cache token must change when any transport-family asset changes');
const loaderToken = wrapper.match(/\$projectLoaderToken = '([a-f0-9]{8})';/);
assert.ok(loaderToken,'project-loader cache token must be content-derived');
const loaderBlob = gitBlobSha(loader);
assert.equal(loaderToken[1],loaderBlob.slice(0,8),'project-loader cache token must match canonical loader content');
assert.match(wrapper,/admin\/stem-project-loader\.js\?v=loader/);
assert.ok(legacy.indexOf('stem-buffer-scheduler-v202.js?v=202') < legacy.indexOf('stem-project-loader.js?v=loader'),'project loader must follow scheduler');
assert.ok(legacy.indexOf('stem-project-loader.js?v=loader') < legacy.indexOf('stem-time-stretch-v203.js?v=203'),'project loader must initialize before later transport engines');
assert.match(legacy,/timeStretchWorkletUrl.*stem-time-stretch-worklet-v203\.js\?v=203/s,'page config must expose the worklet URL');
assert.match(wrapper,/'admin\/stem-time-stretch-worklet-v203\.js\?v=203' => 'admin\/stem-time-stretch-worklet-v203\.js\?v=' \. \$transportToken/,'worklet must use the composite transport token');
assert.doesNotMatch(scheduler,/installProjectLoadingOverlay|stem-project-loader-v232/,'scheduler must stay readiness-free');
const coreToken = wrapper.match(/\$coreToken = '([a-f0-9]{8})';/);
assert.ok(coreToken,'core cache token must be content-derived');
assert.equal(coreToken[1],gitBlobSha(studio).slice(0,8),'core cache token must match canonical Stem Editor content');
assert.match(wrapper,/data-stem-first-paint-loader-v233/);
assert.match(wrapper,/id="stemProjectBootLoaderV233"/);
assert.match(wrapper,/Starting Studio…/);
assert.match(wrapper,/Loading project audio and waveforms\./);
assert.match(wrapper,/preg_replace_callback\([\s\S]*?<body\\b\[\^>\]\*>/);
assert.match(wrapper,/MutationObserver/);
assert.match(wrapper,/find\(node=>node!==boot\)/);
assert.match(wrapper,/stonefellow:stem-playback-ready/);
assert.doesNotMatch(wrapper,/data-loader-force|Open editor anyway|boot\.classList\.add\('is-slow'\)/,'first-paint loader must not expose a bypass');
assert.match(wrapper,/data-stonefellow-build="stem-first-paint-loader-v233-20260902"/);
assert.match(wrapper,/admin\/stem-editor\\\.js/,'runtime injection must anchor to the canonical Stem Editor asset');

const headLoader = wrapper.indexOf("<style data-stem-first-paint-loader-v233>");
const runtimeProbe = wrapper.indexOf("$probe = <<<'HTML'");
assert.ok(headLoader >= 0 && runtimeProbe > headLoader,'first-paint loader must be injected before the later runtime probe');

assert.match(loader,/function installProjectLoadingOverlay\(host\)/);
assert.match(loader,/Loading project audio · \$\{mediaReady\+mediaFailed\} \/ \$\{total\} tracks/,'loader progress must include explicitly failed audio as settled after user choice');
assert.match(loader,/Waveforms · \$\{waveformReady\} \/ \$\{mediaReady\}/,'waveform progress must target only playable audio tracks');
assert.match(loader,/browserWaveformMedia&&!mediaPhaseReady/,'waveform media must wait for the settled audio phase, not final editor release');
assert.match(loader,/await mediaReadyPromise/,'waveforms must begin only after every audio track is ready or explicitly accepted as unavailable');
assert.match(loader,/Number\(audio\.readyState\|\|0\)>=4/,'preloader must wait for HAVE_ENOUGH_DATA on every usable stem');
assert.doesNotMatch(loader,/Number\(audio\.readyState\|\|0\)>=3/,'HAVE_FUTURE_DATA alone must not release the editor');
assert.match(loader,/const projectReady=runtimeReady&&expectedIds\.every/,'final release must evaluate every expected stem');
assert.match(loader,/acceptedFailures\.has\(`audio:/,'failed audio may be skipped only after explicit user acceptance');
assert.match(loader,/acceptedFailures\.has\(`waveform:/,'failed waveforms may be skipped only after explicit user acceptance');
assert.match(loader,/Audio load failed/,'failed audio must remain visible as a loader error');
assert.match(loader,/Waveform load failed/,'failed waveforms must remain visible as a loader error');
assert.match(loader,/Reload and retry/,'loader failures must provide retry');
assert.match(loader,/Continue with available tracks/,'audio failure must provide explicit degraded continuation');
assert.match(loader,/Continue without missing waveforms/,'waveform failure must provide explicit degraded continuation');
assert.doesNotMatch(loader,/Open editor anyway|data-loader-force/,'runtime loader must not expose an automatic bypass');

assert.match(studio,/const waveformQueue = \[\]/);
assert.match(studio,/queueStemWaveform\(stem\)/);
assert.match(studio,/data-stem-waveform-canvas/);
assert.match(studio,/if \(armedRecordingStem\(\)\) \{\s*startStudioRecording\(\);/s);

assert.doesNotMatch(wrapper,/reason\.reason/);
assert.doesNotMatch(wrapper,/STUDIO SAFE RUNTIME · PHASE 2/,'obsolete recovery badge must stay removed');

console.log('STEM_FIRST_PAINT_LOADER_V233=PASS');
