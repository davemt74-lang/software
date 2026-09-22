import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const runtime=read('includes/chrome-extension-releases.php');
const download=read('chrome-extension-download.php');
const admin=read('admin/homeserver.php');
const nav=read('admin/_header.php');
const index=read('index.php');
const css=read('vp3-index-ai-assistants.css');

assert.match(runtime,/CREATE TABLE IF NOT EXISTS chrome_extension_releases/);
assert.match(runtime,/private\/chrome-extension-releases/);
assert.match(runtime,/is_uploaded_file\(\$tmp\)/);
assert.match(runtime,/ZipArchive/);
assert.match(runtime,/manifest\.json/);
assert.match(runtime,/manifest_version/);
assert.match(runtime,/Chrome Manifest V3/);
assert.match(runtime,/hash_file\('sha256'/);
assert.match(runtime,/str_starts_with\(\$entry, '\/'\)/);
assert.match(runtime,/preg_match\('#\(\^\|\/\)\\\.\\\.\(\/\|\$\)#'/);
assert.doesNotMatch(runtime,/shell_exec\s*\(|proc_open\s*\(|passthru\s*\(/);

assert.match(download,/includes\/chrome-extension-releases\.php/);
assert.match(download,/chrome_extension_latest_release\('stable'\)/);
assert.match(download,/X-Chrome-Extension-Version/);
assert.match(download,/X-Chrome-Extension-SHA256/);
assert.match(download,/Content-Type: application\/zip/);
assert.match(download,/browser-companion/,'repository package fallback must remain available');
assert.match(download,/\$zip->addFile\(\$root \. '\/' \. \$file, \$file\)/,'fallback must keep manifest and runtime files at ZIP root');
assert.doesNotMatch(download,/require_login\(|require_permission\(/,'public extension download must remain public');

assert.match(admin,/\$adminTitle = 'Client Releases'/);
assert.match(admin,/Chrome Extension \+ HomeServer/);
assert.match(admin,/name="action" value="chrome_create"/);
assert.match(admin,/name="chrome_zip"/);
for(const action of ['chrome_publish','chrome_unpublish','chrome_latest','chrome_delete']) assert.ok(admin.includes(action),`missing admin action ${action}`);
assert.match(admin,/portable_exe/);
assert.match(admin,/installer_exe/);
assert.match(admin,/HomeServer History/);
assert.match(admin,/Chrome Extension History/);
assert.match(nav,/>Client Releases<\/span>/);
assert.doesNotMatch(nav,/>HomeServer Releases<\/span>/);

assert.match(index,/\$chromeDownloadUrl = url\('\/chrome-extension-download\.php'\)/);
assert.match(index,/data-vp3-cta="home_devices_chrome_download"/);
assert.match(index,/>Download Chrome Extension<\/a>/);
assert.match(index,/class="home-choice"/);
assert.doesNotMatch(index,/class="final-cta"/);
assert.doesNotMatch(css,/\.final-cta/);
assert.doesNotMatch(css,/\.final-actions/);

console.log('Client Releases + Homepage Download contract: ok');
