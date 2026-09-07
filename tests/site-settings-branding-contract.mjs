import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');

const bootstrap = read('includes/bootstrap.php');
const helpers = read('includes/site-settings.php');
const siteSettings = read('admin/site-settings.php');
const adminHeader = read('admin/_header.php');
const sharedBrandingCss = read('site-branding.css');
const runtimeBranding = read('site-branding-runtime.php');
const chatCss = read('chat-v97.css');
const mainSidebar = read('includes/main-sidebar.php');

assert.match(
  bootstrap,
  /require_once\s+__DIR__\s*\.\s*['"]\/site-settings\.php['"]\s*;/,
  'bootstrap must load one canonical site-settings helper layer'
);

assert.ok(
  helpers.includes("setting('site_logo_path', '')"),
  'site logo setting may remain available for other public/brandable surfaces'
);
assert.ok(
  helpers.includes('/uploads/branding/'),
  'site logo helper must only accept the dedicated branding upload directory'
);
assert.ok(
  helpers.includes('is_file($candidate)'),
  'missing logo files must fall back safely'
);
assert.match(helpers, /site_config\('name', 'VP3'\)/, 'site brand default must be VP3');
assert.match(helpers, /strcasecmp\(\$name, 'Stonefellow'\) === 0/, 'legacy Stonefellow site setting must translate to VP3 without SQL');
assert.match(helpers, /return 'VP3';/, 'legacy/blank system brand must present as VP3');

assert.ok(
  siteSettings.includes("require_permission('admin.access');"),
  'Site Settings must require admin access'
);
assert.ok(
  siteSettings.includes('if (!verify_csrf())'),
  'Site Settings writes must be CSRF protected'
);
assert.ok(
  siteSettings.includes('upload_file('),
  'Site Settings may retain the canonical logo upload helper for non-shell surfaces'
);
assert.ok(
  siteSettings.includes("['jpg', 'jpeg', 'png', 'webp']"),
  'logo upload must be limited to approved raster image extensions'
);
assert.ok(
  siteSettings.includes("['image/jpeg', 'image/png', 'image/webp']"),
  'logo upload must validate approved image MIME types'
);
assert.ok(
  siteSettings.includes("'branding'"),
  'logo upload must use the dedicated branding directory'
);

assert.match(adminHeader, /\$siteBrandName = 'VP3';/, 'Admin shell must use fixed VP3 application branding');
assert.ok(adminHeader.includes('class="admin-mobile-brand"') && adminHeader.includes('aria-label="VP3">VP3</a>'), 'Admin mobile logo must be VP3');
assert.ok(adminHeader.includes('class="admin-brand"') && adminHeader.includes('aria-label="VP3">VP3</a>'), 'Admin desktop logo must be VP3');
assert.doesNotMatch(adminHeader, /class="site-brand-logo"/, 'legacy uploaded logo must not override the VP3 admin shell');
assert.match(adminHeader, /url\('\/team\.php'\)/, 'Admin Team destination must point at the front-end Team workspace');

assert.ok(mainSidebar.includes('class="chat-brand"') && mainSidebar.includes('aria-label="VP3">VP3</a>'), 'Main Feed/member sidebar logo must be VP3');
assert.ok(
  chatCss.includes('@import url("site-branding.css?v=1");'),
  'Main Feed may retain the shared branding layer for non-logo rules'
);
assert.ok(
  sharedBrandingCss.includes('@import url("site-branding-runtime.php?v=1");'),
  'shared branding CSS may retain the runtime endpoint for compatibility'
);
assert.match(runtimeBranding, /VP3 application shell uses fixed text branding/, 'runtime branding must no longer replace VP3 with an uploaded legacy logo');
assert.doesNotMatch(runtimeBranding, /background-image|color:transparent|text-indent:-9999px/, 'runtime branding must not visually hide the VP3 shell brand');

console.log('site-settings-branding-contract: ok');