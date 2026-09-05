import assert from 'node:assert/strict';
import fs from 'node:fs';

const css = fs.readFileSync(new URL('../chat.css', import.meta.url), 'utf8');
const page = fs.readFileSync(new URL('../chat-legacy-v108.php', import.meta.url), 'utf8');

const v205 = css.slice(css.indexOf('Stonefellow v205'));
assert.ok(v205.length > 500, 'v205 responsive player contract exists');
assert.match(v205, /\.chat-composer-shell \.chat-now-playing\s*\{[\s\S]*?width:100%;[\s\S]*?max-width:790px;/, 'Player matches composer width');
assert.match(v205, /\.chat-now-playing-transport\s*\{[\s\S]*?flex-flow:row nowrap;[\s\S]*?min-width:max-content;/, 'Transport cannot wrap');
assert.match(v205, /@media\(max-width:760px\)[\s\S]*?grid-template-columns:38px minmax\(0,1fr\) auto 34px;/, 'Mobile player uses four explicit columns');
assert.match(v205, /\.chat-now-playing-transport\s*\{[\s\S]*?grid-column:3;[\s\S]*?grid-row:1;/, 'Mobile transport remains on first row');
assert.match(v205, /\.chat-now-playing-progress\s*\{[\s\S]*?grid-column:1 \/ -1;[\s\S]*?grid-row:2;/, 'Only progress occupies the second row');
assert.match(page, /chat\.css\?v=206-source-light-20260905/, 'Page cache-busts the canonical source-light responsive CSS');

console.log('chat player responsive v205 contracts passed');