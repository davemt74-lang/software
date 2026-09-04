import fs from 'node:fs';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';

const recordings=fs.readFileSync('artist-listening-recordings.js','utf8');
const mixer=fs.readFileSync('admin/stem-automation-mixer-v211.js','utf8');

execFileSync(process.execPath,['--check','artist-listening-recordings.js'],{stdio:'pipe'});
execFileSync(process.execPath,['--check','admin/stem-automation-mixer-v211.js'],{stdio:'pipe'});

assert.match(recordings,/title\.textContent !== item\.name/,'workspace observer must not rewrite identical titles');
assert.match(recordings,/favorite\.textContent !== favoriteLabel/,'workspace observer must not rewrite identical controls');
assert.match(recordings,/if \(enhancementQueued\) return/,'workspace mutation work must be frame-coalesced');
assert.match(mixer,/graphMarkupCache\.get\(pointsGroup\)!==pointsMarkup/,'automation graph must not rebuild identical SVG points each frame');
assert.match(mixer,/path\.getAttribute\('d'\)!==pathValue/,'automation path writes must be change-sensitive');
assert.match(mixer,/if\(refreshQueued\)return/,'mixer mutation refreshes must be frame-coalesced');

console.log('PAGE_LOAD_STABILITY_V221=PASS');
