import assert from 'node:assert/strict';
import fs from 'node:fs';

const transcriptions = fs.readFileSync('transcriptions.php', 'utf8');
const teams = fs.readFileSync('teams.php', 'utf8');
const services = fs.readFileSync('services.php', 'utf8');
const renderer = fs.readFileSync('includes/vp3-marketing-pages.php', 'utf8');
const shell = fs.readFileSync('includes/vp3-public.php', 'utf8');
const index = fs.readFileSync('index.php', 'utf8');

for (const [name, source, active] of [
  ['Transcriptions', transcriptions, 'transcriptions'],
  ['Teams', teams, 'teams'],
]) {
  assert.match(source, /require_once __DIR__ \. '\/includes\/vp3-public\.php'/, `${name} must use the canonical public shell`);
  assert.match(source, /redirect_logged_in_public_page\(\)/, `${name} must preserve logged-in redirect behavior`);
  assert.match(source, new RegExp(`\['active' => '${active}'\]`), `${name} must mark its public navigation item active`);
  assert.match(source, /vp3_public_footer\(\)/, `${name} must use the canonical public footer`);
  assert.match(source, /url\('\/book-demo\.php'\)/, `${name} must offer the canonical demo path`);
}

assert.match(transcriptions, /Turn every conversation into useful context\./, 'Transcriptions hero must explain the product outcome');
assert.match(transcriptions, /1\. Record or upload[\s\S]*2\. Transcribe[\s\S]*3\. Analyze with AI[\s\S]*4\. Use the result/, 'Transcriptions page must explain the end-to-end workflow');
assert.match(transcriptions, /Searchable history[\s\S]*AI summaries[\s\S]*Action plans[\s\S]*Connected knowledge/, 'Transcriptions page must explain core product value');

assert.match(teams, /Give your team one shared AI workspace\./, 'Teams hero must explain the product outcome');
assert.match(teams, /Members &amp; roles[\s\S]*Shared knowledge[\s\S]*Permission-aware AI[\s\S]*One working history/, 'Teams page must explain core team controls');
assert.match(teams, /Shared transcripts[\s\S]*Project continuity[\s\S]*Collaborative knowledge[\s\S]*Controlled administration/, 'Teams page must explain collaboration capabilities');
assert.match(teams, /personal knowledge remains personal unless the owner grants access/i, 'Teams page must preserve the permission-aware positioning');

const desktopNav = shell.match(/<nav class="mega-nav vp3-public-mega-nav"[\s\S]*?<\/nav>/)?.[0] || '';
const mobileNav = shell.match(/<nav class="mega-mobile-nav" aria-label="Mobile navigation">[\s\S]*?<\/nav>/)?.[0] || '';
const footerNav = shell.match(/<nav class="vp3-public-footer-links"[\s\S]*?<\/nav>/)?.[0] || '';

assert.ok(desktopNav, 'canonical public shell must render the desktop mega menu');
assert.ok(mobileNav, 'canonical public shell must render the responsive mobile mega menu');
assert.ok(footerNav, 'canonical public shell must render the public footer navigation');

for (const route of ['/product.php', '/services.php', '/homeserver.php', '/pricing.php', '/about.php']) {
  assert.match(desktopNav, new RegExp(`url\\('\\/${route.slice(1).replace('.', '\\.')}\\'\\)`), `desktop mega menu must link to ${route}`);
  assert.match(footerNav, new RegExp(`url\\('\\/${route.slice(1).replace('.', '\\.')}\\'\\)`), `public footer must link to ${route}`);
}

for (const route of [
  '/ai-assistant.php','/personal-url.php','/profile-agent-overview.php',
  '/transcriptions.php','/ai-summary.php','/teams.php','/calendar-service.php','/booking.php','/ecommerce.php',
  '/cloud-vs-self-hosted.php','/paired-devices.php','/model-choice.php','/local-knowledge-overview.php','/tools-skills.php',
  '/pricing-monthly.php','/pricing-weekly.php','/pricing-yearly.php','/token-packages.php',
  '/about-team.php','/mission.php','/case-studies.php','/testimonials.php','/contact.php'
]) {
  assert.match(mobileNav, new RegExp(`url\\('\\/${route.slice(1).replace('.', '\\.')}\\'\\)`), `mobile mega menu must expose ${route}`);
}
assert.match(mobileNav, /url\('\/pricing\.php'\)/, 'mobile mega menu must expose the canonical Pricing overview');

for (const nav of [desktopNav, mobileNav, footerNav]) {
  assert.doesNotMatch(nav, /index\.php#transcriptions|index\.php#teams/, 'public navigation must not fall back to homepage product anchors');
}
assert.doesNotMatch(footerNav, />Features</, 'shared public footer must not link to the removed Features section');

assert.match(services, /vp3_render_marketing_page\('services'\)/, 'Services must use the shared marketing page renderer');
assert.match(renderer, /\['Transcription',[\s\S]*'\/transcriptions\.php'\]/, 'Services data must expose the standalone Transcriptions page');
assert.match(renderer, /Team scheduling[\s\S]*'\/teams\.php'/, 'marketing data must preserve the standalone Teams destination');
assert.match(index, /href="<\?= e\(\$transcriptionsUrl\) \?>"/, 'homepage mega menu must expose Transcriptions');
assert.match(index, /href="<\?= e\(\$teamsUrl\) \?>"/, 'homepage mega menu must expose Teams');

console.log('public-product-pages-contract: PASS');