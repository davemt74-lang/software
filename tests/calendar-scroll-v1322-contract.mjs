import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync('calendar.php', 'utf8');
const sharedScroll = fs.readFileSync('member-page-scroll.css', 'utf8');
const header = fs.readFileSync('includes/member-header.php', 'utf8');

assert.match(page, /<main class="chat-main calendar-main">/);
assert.match(page, /<section class="calendar-canvas">/);
assert.match(sharedScroll, /\.calendar-main\{[^}]*display:flex;[^}]*flex-direction:column;[^}]*height:100dvh;[^}]*max-height:100dvh;[^}]*overflow:hidden/s,
  'Calendar main shell must use a fixed-height flex column instead of the inherited chat grid');
assert.match(sharedScroll, /\.calendar-main>\.chat-topbar\{[^}]*flex:0 0 58px;[^}]*min-height:58px/s,
  'Calendar header must remain a fixed-height flex item');
assert.match(sharedScroll, /\.calendar-main>\.calendar-canvas\{[^}]*flex:1 1 auto;[^}]*min-height:0;[^}]*max-height:calc\(100dvh - 58px\);[^}]*overflow-y:scroll/s,
  'Calendar canvas must be the explicit scroll owner');
assert.match(sharedScroll, /-webkit-overflow-scrolling:touch/);
assert.match(header, /member-page-scroll-20260912-calendar-flex/,
  'Member scroll stylesheet build must be cache-busted when calendar scroll ownership changes');

console.log('Calendar scroll v13.22 contract OK');
