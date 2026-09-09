import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const runtime = read('includes/homeserver-vp3.php');
const approvalRuntime = read('includes/homeserver-approvals-v028.php');
const sidebar = read('includes/main-sidebar.php');
const modalJs = read('homeserver-vp3.js');
const admin = read('admin/homeserver.php');
const statusApi = read('api/homeserver-status.php');
const releaseApi = read('api/homeserver-release.php');
const download = read('homeserver-download.php');
const migration = read('upgrade-vp3-homeserver-integration.sql');
const config = read('config-example.php');

assert.match(sidebar, /chat-sidebar-top[\s\S]*vp3HomeServerStatus/);
assert.match(sidebar, /vp3-homeserver-head-actions/);
assert.match(sidebar, /vp3HomeServerModal/);
assert.match(sidebar, /HomeServer Connection/);
assert.match(sidebar, /Latest VP3 Release/);
assert.match(sidebar, /Agent Brain/);
assert.match(sidebar, /homeserver-vp3\.js/);

assert.match(statusApi, /require_login\(\)/);
assert.match(statusApi, /verify_csrf\(\)/);
assert.match(statusApi, /homeserver_approvals_v028_claim_and_pair/);
assert.match(statusApi, /homeserver_vp3_check_pairing/);
assert.match(statusApi, /homeserver_vp3_disconnect/);
assert.match(statusApi, /homeserver-approvals-v028\.php/);
assert.doesNotMatch(statusApi, /\$pairing\s*=\s*homeserver_vp3_claim_and_pair/);
assert.match(approvalRuntime, /'approvals\.review'/);
assert.match(approvalRuntime, /homeserver_approvals_v028_permissions/);
assert.match(approvalRuntime, /'app_key'=>'vp3'/);

assert.match(runtime, /aes-256-gcm/);
assert.match(runtime, /homeserver-vp3\.key/);
assert.match(runtime, /private\/homeserver-releases/);
assert.match(runtime, /is_uploaded_file/);
assert.match(runtime, /substr\(\$header, 0, 2\) !== 'MZ'/);
assert.match(runtime, /"PE\\0\\0"/);
assert.match(runtime, /hash_file\('sha256'/);
assert.match(runtime, /CURLOPT_FOLLOWLOCATION => false/);
assert.match(runtime, /\/v1\/\(\?:claim\|session/);
assert.match(runtime, /pair\.request/);
assert.match(runtime, /pair\.status/);
assert.doesNotMatch(runtime, /shell_exec\s*\(|proc_open\s*\(|passthru\s*\(/);

assert.match(admin, /require_permission\('users\.manage'\)/);
assert.match(admin, /portable_exe/);
assert.match(admin, /installer_exe/);
assert.match(admin, /Make Current/);
assert.match(admin, /SHA256|sha256/i);

assert.match(releaseApi, /homeserver_vp3_latest_release/);
assert.match(download, /is_published=1/);
assert.match(download, /Content-Range/);
assert.match(download, /Accept-Ranges/);
assert.match(download, /application\/vnd\.microsoft\.portable-executable/);

assert.match(migration, /CREATE TABLE IF NOT EXISTS homeserver_connections/);
assert.match(migration, /CREATE TABLE IF NOT EXISTS homeserver_releases/);
assert.match(config, /homeserver[\s\S]*relay_base_url/);

assert.match(modalJs, /check_pairing/);
assert.match(modalJs, /update_available/);
assert.match(modalJs, /agent_brain_ready/);
assert.match(modalJs, /selected_provider/);
assert.match(modalJs, /compute_source/);

console.log('homeserver-vp3-integration-contract: ok');
