import assert from 'node:assert/strict';
import fs from 'node:fs';

const index = fs.readFileSync('index.php', 'utf8');
const css = fs.readFileSync('vp3-home.css', 'utf8');
const mountain = fs.readFileSync('assets/vp3-mountain-bg.svg', 'utf8');

assert.match(index, /redirect_logged_in_public_page\(\)/, 'homepage must preserve logged-in redirect behavior');
assert.match(index, /<title>VP3 — Capture\. Understand\. Take Action\.<\/title>/, 'public homepage must use VP3 positioning');
assert.match(index, /Capture\. Understand\.[\s\S]*Take Action\./, 'hero must use the approved headline');
assert.doesNotMatch(index, /<h1[^>]*>\s*VP3\s*<\/h1>/, 'homepage must not repeat VP3 as a second hero title');
assert.match(index, /App Store/, 'homepage must expose an iOS download surface');
assert.match(index, /Google Play/, 'homepage must expose an Android download surface');
assert.match(index, /Or use it in your browser/, 'homepage must keep the browser path visible');
assert.match(index, /My Contacts[\s\S]*My Knowledge[\s\S]*My Transcriptions/, 'desktop mockup must reflect the existing Agent Chat navigation');
assert.match(index, /Good morning, Dave\./, 'desktop and mobile previews must use the assistant interaction pattern');

for (const feature of ['Personal URL','Transcriptions','AI Summaries','Teams &amp; Team Management','Second Brain','Profile Chat Widget','Secure &amp; Private','Works Everywhere']) {
  assert.ok(index.includes(feature), `feature section must include ${feature}`);
}

assert.match(index, /vp3-footer-links/, 'marketing navigation must live in the footer');
assert.doesNotMatch(index, /<header[\s\S]{0,900}<nav/, 'top header must stay minimal without the old navigation row');
assert.match(css, /\.vp3-feature-icon\{[^}]*color:#081322[^}]*background:#fff/, 'feature icons must remain monochrome');
assert.match(css, /@media\(max-width:620px\)/, 'homepage must include a dedicated small-screen layout');
assert.match(css, /url\('\/assets\/vp3-mountain-bg\.svg'\)/, 'hero and CTA must use the reusable mountain background asset');
assert.match(mountain, /<svg[\s\S]*viewBox="0 0 1600 900"/, 'mountain background must be a scalable SVG asset');
assert.doesNotMatch(mountain, /<text|VP3|Capture|Understand|Take Action/, 'background asset must contain no baked-in marketing copy');

console.log('vp3-index-contract: PASS');
