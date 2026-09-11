import assert from 'node:assert/strict';
import fs from 'node:fs';

const index = fs.readFileSync('index.php', 'utf8');
const css = fs.readFileSync('vp3-index-ai-assistants.css', 'utf8');

assert.match(index, /redirect_logged_in_public_page\(\)/, 'homepage must preserve logged-in redirect behavior');
assert.match(index, /<title>VP3 AI Assistants — Turn Every Conversation Into What’s Next<\/title>/, 'homepage must use the AI Assistants positioning');
assert.match(index, /VP3 AI Assistants[\s\S]*Turn every conversation[\s\S]*into what’s next\./, 'hero must present the AI Assistants headline');
assert.match(index, /vp3-main-header_bg\.png/, 'homepage must use the supplied VP3 hero background image');
assert.match(css, /\.hero\{[^}]*min-height:/, 'homepage must define a full hero stage');
assert.match(css, /\.hero-image\{[^}]*object-fit:cover/, 'hero image must fill the hero responsively');

for (const route of ['/signup.php', '/book-demo.php', '/login.php', '/pricing.php', '/about.php', '/transcriptions.php', '/teams.php']) {
  assert.ok(index.includes(`url('${route}')`), `homepage must preserve ${route}`);
}
assert.match(index, /\$homeServerUrl\s*=\s*'#homeserver'/, 'HomeServer CTA must target the homepage HomeServer section');
assert.doesNotMatch(index, /homeserver-download\.php/, 'homepage must not link to a missing HomeServer download route');
assert.doesNotMatch(index, /App Store|Google Play|VP3 for iPhone|VP3 for Android/, 'homepage must not expose obsolete app-store CTAs');

assert.match(index, /Built for real work\./, 'homepage must include the real-work section');
for (const asset of [
  'feature-transcription.webp',
  'feature-mobile.webp',
  'feature-ai-summaries.webp',
  'feature-teams.webp'
]) {
  assert.ok(index.includes(`/assets/home/${asset}`), `homepage must use ${asset}`);
}
for (const copy of ['Transcription, organized.', 'Capture anywhere.', 'Summaries that matter.', 'Work together, better.']) {
  assert.ok(index.includes(copy), `homepage must include feature card: ${copy}`);
}

assert.match(index, /A platform for how[\s\S]*you actually work\./, 'homepage must include the platform section');
assert.match(index, /Your profile agent/, 'homepage must position the personal profile agent');
assert.match(index, /Personal link/, 'homepage must expose personal-link capability');
assert.match(index, /Everything you need\.[\s\S]*Nothing in the way\./, 'homepage must include the desktop and phone showcase');
assert.match(index, /devices-everything-you-need\.webp/, 'homepage must use the desktop and phone artwork');

assert.match(index, /HomeServer keeps your[\s\S]*AI close to home\./, 'homepage must include the HomeServer section');
assert.match(index, /self-hosted AI/i, 'homepage must explain self-hosted AI');
assert.match(index, /secure data access/i, 'homepage must explain secure data access');
assert.match(index, /private storage/i, 'homepage must explain private storage');
assert.match(index, /user-controlled/i, 'homepage must explain user-controlled data');

assert.match(index, /The assistant[\s\S]*is the experience\./, 'homepage must include the dark product-positioning section');
assert.match(index, /Put a VP3 assistant to work\./, 'homepage must include the closing CTA');
assert.match(index, /<footer class="footer">/, 'homepage must include the new marketing footer');

assert.match(css, /@media\(max-width:1050px\)/, 'homepage must include tablet responsive rules');
assert.match(css, /@media\(max-width:720px\)/, 'homepage must include small-screen responsive rules');
assert.match(css, /\.feature-grid\{[^}]*grid-template-columns:repeat\(4,1fr\)/, 'desktop real-work section must use four columns');
assert.match(css, /@media\(max-width:1050px\)[\s\S]*\.feature-grid,.value-grid\{grid-template-columns:repeat\(2,1fr\)/, 'feature cards must collapse on tablet');
assert.match(css, /@media\(max-width:720px\)[\s\S]*\.feature-grid,.value-grid,.proof-grid\{grid-template-columns:1fr\}/, 'feature cards must collapse to one column on small screens');
assert.match(css, /\.check-list li\{[^}]*grid-template-columns:28px 1fr/, 'timeline checklist must use a two-column icon/content grid');

console.log('vp3-index-contract: PASS');
