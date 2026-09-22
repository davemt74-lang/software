import assert from 'node:assert/strict';
import fs from 'node:fs';

const index = fs.readFileSync('index.php', 'utf8');
const css = fs.readFileSync('vp3-index-ai-assistants.css', 'utf8');
const megaCss = fs.readFileSync('vp3-index-mega-menu.css', 'utf8');

assert.match(index, /redirect_logged_in_public_page\(\)/, 'homepage must preserve logged-in redirect behavior');
assert.match(index, /<title>VP3 AI Assistants — From Signal to Outcome<\/title>/, 'homepage must use the current AI Assistants positioning');
assert.match(index, /One Agent\. Every surface\./, 'hero must position one Agent across VP3');
assert.match(index, /From signal to outcome\./, 'hero must present the current lifecycle headline');
assert.match(index, /vp3-main-header_bg\.png/, 'homepage must use the supplied VP3 hero background image');
assert.match(css, /\.hero\{[^}]*min-height:/, 'homepage must define a full hero stage');
assert.match(css, /\.hero-image\{[^}]*object-fit:cover/, 'hero image must fill the hero responsively');

for (const route of ['/signup.php','/book-demo.php','/login.php','/pricing.php','/about.php','/transcriptions.php','/teams.php','/product.php','/services.php','/homeserver.php','/chrome-extension.php','/annotations.php','/video-meetings.php','/booking.php','/ecommerce.php','/agent-analytics.php']) {
  assert.ok(index.includes(route), `homepage must preserve ${route}`);
}
assert.match(index, /require_once __DIR__ \. '\/includes\/vp3-public\.php'/, 'homepage must load the canonical VP3 public brand component');
assert.match(index, /class="brand vp3-home-brand"[\s\S]*vp3_public_brand\(\)/, 'homepage header must render the canonical VP3 mark and wordmark');
assert.match(index, /class="footer-brand"[\s\S]*vp3_public_brand\(\)/, 'homepage footer must render the canonical VP3 mark and wordmark');
assert.match(index, /\$homeServerUrl\s*=\s*url\('\/homeserver\.php'\)/, 'HomeServer CTA must target the standalone HomeServer page');
assert.match(index, /\$productUrl\s*=\s*url\('\/product\.php'\)/, 'Product mega-menu landing must target the standalone Product page');
assert.match(index, /\$servicesUrl\s*=\s*url\('\/services\.php'\)/, 'Services mega-menu landing must target the standalone Services page');
assert.match(index, /<section class="homeserver" id="homeserver"/, 'homepage must retain the HomeServer editorial section');
assert.match(index, /vp3-index-mega-menu\.css\?v=20260914-2/, 'homepage must cache-bust the branded mega-menu stylesheet');
assert.match(index, /desktop-nav mega-nav/, 'homepage must expose the desktop mega-menu navigation');
assert.match(index, /mega-mobile-nav/, 'homepage must expose the mobile mega-menu navigation');
assert.match(megaCss, /\.brand>\.vp3-public-mark,\.footer-brand>\.vp3-public-mark/, 'homepage header and footer must style the shared VP3 mark markup');
assert.match(megaCss, /grid-template-columns:repeat\(2,8px\)[\s\S]*grid-template-rows:repeat\(2,8px\)/, 'homepage VP3 mark must render the canonical four-tile grid');
assert.doesNotMatch(index, /homeserver-download\.php/, 'homepage must not link to a missing HomeServer download route');
assert.doesNotMatch(index, /App Store|Google Play|VP3 for iPhone|VP3 for Android/, 'homepage must not expose obsolete app-store CTAs');

for (const stage of ['Capture','Understand','Coordinate','Sell','Measure','Agent follows through']) {
  assert.ok(index.includes(stage), `homepage operating loop must include ${stage}`);
}
assert.match(index, /One Agent from capture to follow-through\./, 'homepage must explain the connected Agent operating loop');
assert.match(index, /The surface changes\. The Agent does not start over\./, 'homepage must explain cross-surface Agent continuity');
for (const surface of ['Browser','Meetings','Profile','Teams','Booking + commerce','HomeServer']) {
  assert.ok(index.includes(surface), `homepage must include current surface: ${surface}`);
}
assert.match(index, /Research can become a meeting\.[\s\S]*Analytics can become the next action\./, 'homepage must show the connected outcome journey');
assert.match(index, /devices-everything-you-need\.webp/, 'homepage must use the desktop and mobile artwork');
assert.match(index, /Start where the work happens\. Keep the context\./, 'homepage must retain the multi-device continuity section');
assert.match(index, /HomeServer gives the same Agent a private local side\./, 'homepage must explain Cloud + HomeServer continuity');
assert.match(index, /Cloud \+ self-hosted capability/, 'homepage must explain hybrid deployment');
assert.match(index, /Use one service or connect the whole loop\./, 'homepage must retain the single pre-footer service CTA');
assert.doesNotMatch(index, /class="final-cta"/, 'homepage must not restore the redundant second pre-footer CTA');
assert.match(index, /home_devices_chrome_download[\s\S]*chrome-extension-download\.php/, 'homepage must expose a direct Chrome Extension download CTA');
assert.match(index, /<footer class="footer">/, 'homepage must include the marketing footer');

assert.match(css, /\.home-lifecycle-grid\{[^}]*grid-template-columns:repeat\(6,minmax\(0,1fr\)\)/, 'desktop operating loop must use six stages');
assert.match(css, /\.home-surface-grid\{[^}]*grid-template-columns:repeat\(3,minmax\(0,1fr\)\)/, 'desktop surface grid must use three columns');
assert.match(css, /\.home-journey-steps\{[^}]*grid-template-columns:repeat\(6,minmax\(0,1fr\)\)/, 'desktop outcome journey must use six steps');
assert.match(css, /@media\(max-width:1100px\)[\s\S]*home-lifecycle-grid/, 'homepage must collapse lifecycle/journey grids on tablet');
assert.match(css, /@media\(max-width:820px\)[\s\S]*home-surface-grid/, 'homepage must collapse surface grid on smaller screens');
assert.match(css, /@media\(max-width:620px\)[\s\S]*home-lifecycle-grid/, 'homepage must collapse current grids to one column on mobile');
assert.match(css, /\.check-list li\{[^}]*grid-template-columns:28px 1fr/, 'timeline checklist must use a two-column icon/content grid');
assert.match(megaCss, /@media\(max-width:/, 'mega menu must include responsive rules');

console.log('vp3-index-contract: PASS');
