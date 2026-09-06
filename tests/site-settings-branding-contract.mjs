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
const chatTemplate = read('chat-legacy-v108.php');

assert.match(
  bootstrap,
  /require_once\s+__DIR__\s*\.\s*['"]\/site-settings\.php['"]\s*;/,
  'bootstrap must load one canonical site-settings helper layer'
);

assert.ok(
  helpers.includes("setting('site_logo_path', '')"),
  'site logo must use the existing canonical settings table'
);
assert.ok(
  helpers.includes('/uploads/branding/'),
  'site logo helper must only accept the dedicated branding upload directory'
);
assert.ok(
  helpers.includes('is_file($candidate)'),
  'missing logo files must fall back instead of hiding the brand'
);

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
  'Site Settings must use the canonical upload helper'
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
assert.ok(
  siteSettings.includes("save_setting('site_logo_path', $newLogoPath)"),
  'new logo must be persisted through the canonical settings API'
);
assert.ok(
  siteSettings.includes("save_setting('site_logo_path', '')"),
  'Site Settings must support restoring text branding'
);
assert.ok(
  siteSettings.includes('delete_local_upload('),
  'replaced and removed logos must be cleaned up through the safe upload helper'
);
assert.ok(
  siteSettings.includes('enctype="multipart/form-data"'),
  'logo upload form must use multipart encoding'
);

assert.ok(
  adminHeader.includes("url('/admin/site-settings.php')") && adminHeader.includes('Site Settings'),
  'Admin navigation must expose Site Settings'
);
assert.ok(
  adminHeader.includes('$siteBrandLogoUrl = site_logo_url();'),
  'Admin shell must read the canonical system logo'
);
assert.ok(
  adminHeader.includes('class="site-brand-logo"'),
  'Admin desktop/mobile brand slots must render the uploaded logo as an image'
);
assert.ok(
  adminHeader.includes("url('/site-branding.css?v=1')"),
  'Admin shell must load shared branding styles'
);

assert.ok(
  chatTemplate.includes('class="chat-brand"'),
  'Main Feed must retain the canonical sidebar brand target'
);
assert.ok(
  chatCss.includes('@import url("site-branding.css?v=1");'),
  'Main Feed must load the shared branding layer from canonical chat CSS'
);
assert.ok(
  sharedBrandingCss.includes('@import url("site-branding-runtime.php?v=1");'),
  'shared branding CSS must load the runtime site-logo setting'
);
assert.ok(
  runtimeBranding.includes('$logoUrl = site_logo_url();'),
  'runtime branding must read the same canonical logo as Admin'
);
assert.ok(
  runtimeBranding.includes('.chat-brand{'),
  'runtime branding must apply the uploaded logo to the Main Feed sidebar brand'
);
assert.ok(
  runtimeBranding.includes("if ($logoUrl === '')"),
  'runtime branding must preserve existing text when no valid logo exists'
);

console.log('site-settings-branding-contract: ok');
