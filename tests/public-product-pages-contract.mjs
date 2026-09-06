import assert from 'node:assert/strict';
import fs from 'node:fs';

const transcriptions = fs.readFileSync('transcriptions.php', 'utf8');
const teams = fs.readFileSync('teams.php', 'utf8');
const shell = fs.readFileSync('includes/vp3-public.php', 'utf8');

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

const desktopNav = shell.match(/<nav class="vp3-public-links"[\s\S]*?<\/nav>/)?.[0] || '';
const mobileNav = shell.match(/<nav aria-label="Mobile navigation">[\s\S]*?<\/nav>/)?.[0] || '';
const footerNav = shell.match(/<nav class="vp3-public-footer-links"[\s\S]*?<\/nav>/)?.[0] || '';
for (const nav of [desktopNav, mobileNav, footerNav]) {
  assert.match(nav, /url\('\/transcriptions\.php'\)/, 'all public navigation surfaces must link to Transcriptions');
  assert.match(nav, /url\('\/teams\.php'\)/, 'all public navigation surfaces must link to Teams');
  assert.doesNotMatch(nav, /index\.php#transcriptions|index\.php#teams/, 'public navigation must not fall back to homepage product anchors');
}
assert.doesNotMatch(footerNav, />Features</, 'shared public footer must not link to the removed Features section');

console.log('public-product-pages-contract: PASS');
