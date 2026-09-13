import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync('artist-listening.php', 'utf8');

assert.match(
  page,
  /\.sf-listening-ai-panel\{min-height:0!important;overflow:hidden!important\}/,
  'AI Summary panel must clip overflow and allow its flex child to own scrolling',
);
assert.match(
  page,
  /\.sf-listening-ai-scroll\{flex:1 1 auto!important;min-height:0!important;overflow-x:hidden!important;overflow-y:auto!important;/,
  'AI Summary report body must be a shrinkable vertical scroll container',
);
assert.match(
  page,
  /\.sf-listening-ai-head,.sf-listening-ai-status,.sf-listening-ai-apps,.sf-listening-ai-tabs,.sf-listening-ai-footer\{flex:0 0 auto\}/,
  'Fixed AI Summary chrome must not consume the report scroll contract',
);
assert.ok(
  page.includes('overscroll-behavior:contain'),
  'AI Summary scrolling must remain contained inside the slideout',
);
assert.ok(
  page.includes('-webkit-overflow-scrolling:touch'),
  'AI Summary slideout must retain touch momentum scrolling',
);

console.log('TRANSCRIPTION_AI_SUMMARY_SCROLL=PASS');
