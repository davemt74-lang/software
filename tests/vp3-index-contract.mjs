import assert from 'node:assert/strict';
import fs from 'node:fs';

const index = fs.readFileSync('index.php', 'utf8');
const css = fs.readFileSync('vp3-home.css', 'utf8');
const refreshCss = fs.readFileSync('vp3-index-refresh.css', 'utf8');
const publicShell = fs.readFileSync('includes/vp3-public.php', 'utf8');
const mountain = fs.readFileSync('assets/vp3-mountain-bg.svg', 'utf8');

assert.match(index, /redirect_logged_in_public_page\(\)/, 'homepage must preserve logged-in redirect behavior');
assert.match(index, /<title>VP3 — Capture\. Understand\. Take Action\.<\/title>/, 'public homepage must use VP3 positioning');
assert.match(index, /Capture\. Understand\.[\s\S]*Take Action\./, 'hero must use the approved headline');
assert.doesNotMatch(index, /<h1[^>]*>\s*VP3\s*<\/h1>/, 'homepage must not repeat VP3 as a second hero title');

assert.match(index, /vp3-public-header vp3-home-header/, 'homepage must use the canonical public-header structure');
const homePrimaryNav = index.match(/<nav class="vp3-public-links"[\s\S]*?<\/nav>/)?.[0] || '';
assert.ok(homePrimaryNav, 'homepage must expose a primary navigation block');
assert.match(homePrimaryNav, /url\('\/transcriptions\.php'\)[\s\S]*>Transcriptions</, 'homepage primary nav must link to the standalone Transcriptions page');
assert.match(homePrimaryNav, /url\('\/teams\.php'\)[\s\S]*>Teams</, 'homepage primary nav must link to the standalone Teams page');
assert.doesNotMatch(homePrimaryNav, /index\.php#transcriptions|index\.php#teams|>Features</, 'homepage primary nav must not use removed homepage product anchors');
assert.match(index, /\$vp3DemoUrl\s*=\s*url\('\/book-demo\.php'\)/, 'homepage must derive the demo CTA from the canonical demo URL');
assert.match(index, /class="vp3-public-primary"[^>]*href="<\?= e\(\$vp3DemoUrl\) \?>"[^>]*>BOOK DEMO<\/a>/, 'homepage dark primary CTA must be BOOK DEMO');
assert.match(refreshCss, /\.vp3-home-header \.vp3-public-primary\{[^}]*color:#fff/, 'BOOK DEMO text must remain white');

const sharedPrimaryNav = publicShell.match(/<nav class="vp3-public-links"[\s\S]*?<\/nav>/)?.[0] || '';
assert.ok(sharedPrimaryNav, 'shared public shell must expose a primary navigation block');
assert.match(sharedPrimaryNav, /url\('\/transcriptions\.php'\)/, 'shared public nav must link to standalone Transcriptions');
assert.match(sharedPrimaryNav, /url\('\/teams\.php'\)/, 'shared public nav must link to standalone Teams');
assert.doesNotMatch(sharedPrimaryNav, /index\.php#transcriptions|index\.php#teams|>Features</, 'shared public nav must not use removed homepage product anchors');
const sharedMobileNav = publicShell.match(/<nav aria-label="Mobile navigation">[\s\S]*?<\/nav>/)?.[0] || '';
assert.match(sharedMobileNav, /url\('\/transcriptions\.php'\)/, 'shared mobile nav must link to standalone Transcriptions');
assert.match(sharedMobileNav, /url\('\/teams\.php'\)/, 'shared mobile nav must link to standalone Teams');
assert.doesNotMatch(sharedMobileNav, /index\.php#transcriptions|index\.php#teams|>Features</, 'shared mobile nav must not use removed homepage product anchors');

assert.match(index, /url\('\/signup\.php'\)/, 'homepage must route account creation to the canonical signup page');
assert.match(index, /Create account/, 'homepage must expose a create-account CTA');
assert.match(index, /url\('\/book-demo\.php'\)/, 'homepage must route demos to the CRM-backed booking page');
assert.match(index, /Already have an account\? Sign in/, 'homepage must keep the sign-in path visible');
assert.doesNotMatch(index, /App Store|Google Play|VP3 for iPhone|VP3 for Android/, 'homepage must not expose obsolete mobile-store CTAs');
assert.match(index, /My Contacts[\s\S]*My Knowledge[\s\S]*My Transcriptions/, 'desktop mockup must reflect the existing Agent Chat navigation');
assert.match(index, /Good morning, Dave\./, 'desktop and mobile previews must use the assistant interaction pattern');

assert.doesNotMatch(index, /How it works|From recording to action in four steps\.|Capture the conversation once\. VP3 turns it into searchable context/, 'homepage must not restore the removed workflow heading or intro copy');
assert.doesNotMatch(index, /Everything you need\. All in one place\./, 'legacy Everything You Need section must remain removed');
assert.match(index, /<section class="vp3-steps-only"[^>]*>/, 'homepage must keep the four-step workflow as a standalone card section');
for (const step of ['Record', 'Transcribe', 'AI Analysis', 'Summary or Action Plan']) {
  assert.match(index, new RegExp(`<h3>${step}<\\/h3>`), `homepage workflow must include ${step}`);
}
for (const copy of [
  'Capture conversations, meetings, ideas, or voice notes directly into your VP3 workspace.',
  'Turn your recording into accurate, searchable text you can review, save, and reuse.',
  'Let VP3 identify the key ideas, decisions, questions, opportunities, and next steps in the conversation.',
  'Receive a clear summary or practical action plan that helps you move forward.'
]) {
  assert.ok(index.includes(copy), `homepage workflow must include: ${copy}`);
}
assert.match(index, /vp3-step-number">01<[\s\S]*vp3-step-number">02<[\s\S]*vp3-step-number">03<[\s\S]*vp3-step-number">04</, 'workflow steps must remain explicitly ordered');
assert.match(refreshCss, /\.vp3-step-grid\{[^}]*grid-template-columns:repeat\(4,minmax\(0,1fr\)\)/, 'desktop workflow must use four columns');
assert.match(refreshCss, /@media\(max-width:620px\)[\s\S]*\.vp3-step-grid\{grid-template-columns:1fr\}/, 'workflow cards must collapse to one column on small screens');

const results = index.match(/<section class="vp3-results"[\s\S]*?<\/section>/)?.[0] || '';
assert.ok(results, 'homepage must keep the Turn Your Thoughts Into Results section');
assert.match(results, /Turn Your Thoughts[\s\S]*Into Results\./, 'results section must keep its approved headline');
assert.doesNotMatch(results, /vp3-get-started|>Get Started</, 'results section must not include a Get Started button');
assert.match(refreshCss, /\.vp3-results-content\{[^}]*text-align:center[^}]*align-items:center/, 'results section content must be centered');
assert.match(refreshCss, /\.vp3-results:after\{[^}]*linear-gradient/, 'centered results copy must keep a readable centered overlay');

const footerNav = index.match(/<nav class="vp3-footer-links"[\s\S]*?<\/nav>/)?.[0] || '';
assert.ok(footerNav, 'marketing navigation must remain available in the footer');
assert.match(footerNav, /url\('\/transcriptions\.php'\)/, 'homepage footer must link to standalone Transcriptions');
assert.match(footerNav, /url\('\/teams\.php'\)/, 'homepage footer must link to standalone Teams');
assert.doesNotMatch(footerNav, /#transcriptions|#teams|>Features</, 'homepage footer must not link to removed homepage product anchors');
assert.match(css, /@media\(max-width:620px\)/, 'homepage must include a dedicated small-screen layout');
assert.match(css, /url\('\/assets\/vp3-mountain-bg\.svg'\)/, 'hero and CTA must use the reusable mountain background asset');
assert.match(mountain, /<svg[\s\S]*viewBox="0 0 1600 900"/, 'mountain background must be a scalable SVG asset');
assert.doesNotMatch(mountain, /<text|VP3|Capture|Understand|Take Action/, 'background asset must contain no baked-in marketing copy');

console.log('vp3-index-contract: PASS');
