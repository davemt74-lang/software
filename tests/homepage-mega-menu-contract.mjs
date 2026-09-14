import fs from 'node:fs';
import assert from 'node:assert/strict';

const index = fs.readFileSync('index.php', 'utf8');
const css = fs.readFileSync('vp3-index-mega-menu.css', 'utf8');
const js = fs.readFileSync('vp3-index-mega-menu.js', 'utf8');

for (const group of ['Products', 'Services', 'HomeServer', 'Pricing', 'About']) {
  assert.ok(index.includes(`<summary>${group}</summary>`), `missing ${group} mega-menu group`);
}

for (const item of [
  'AI Assistant', 'Personal URL', 'Profile Agent',
  'Transcription', 'AI Summary', 'Teams', 'Calendar', 'Booking · Free + Paid', 'E-commerce',
  'Cloud vs Self-hosted', 'OpenRouter + Model Choice', 'Local Knowledge', 'Private Tools + Skills',
  'Monthly', 'Weekly', 'Yearly', 'Token Packages',
  'Team', 'Mission', 'Case Studies', 'Testimonials', 'Contact Us', 'Social Links'
]) {
  assert.ok(index.includes(item), `missing mega-menu item: ${item}`);
}

assert.ok(index.includes('class="desktop-nav mega-nav"'), 'desktop mega nav is not canonical homepage nav');
assert.ok(index.includes('class="mobile-mega-nav"'), 'mobile mega nav is missing');
assert.ok(index.includes('/vp3-index-mega-menu.css?v='), 'mega-menu stylesheet is not loaded');
assert.ok(index.includes('/vp3-index-mega-menu.js?v='), 'mega-menu runtime is not loaded');
assert.ok(css.includes('background:#292b2e'), 'desktop mega menu must use the requested gray surface');
assert.ok(css.includes('grid-template-columns:repeat(4'), 'desktop mega menu must expose a multi-column layout');
assert.ok(css.includes('@media(max-width:1050px)'), 'desktop/mobile handoff is not protected');
assert.ok(css.includes('@media(max-width:560px)'), 'narrow mobile layout is not protected');
assert.ok(js.includes("event.key !== 'Escape'"), 'Escape close behavior is missing');
assert.ok(js.includes("querySelectorAll('.mega-nav > details')"), 'desktop menu exclusivity behavior is missing');
assert.ok(js.includes("querySelectorAll('.mega-nav a')"), 'desktop menu navigation close behavior is missing');

console.log('Homepage mega menu contract passed.');
