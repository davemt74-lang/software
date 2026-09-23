import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(new URL('../'+p, import.meta.url),'utf8');
const core=read('includes/connected-sites-v100.php');
const boot=read('includes/bootstrap.php');
const account=read('account.php');
const authorize=read('connected-site-authorize.php');
const token=read('api/connected-site-token.php');
const me=read('api/connected-site-me.php');
const artifacts=read('api/connected-site-artifacts.php');
const revoke=read('api/connected-site-revoke.php');
const upgrade=read('upgrade.php');
const setup=read('setup.php');

for(const needle of ['user_connected_sites','user_connected_site_codes','user_connected_site_tokens','access_token_hash','refresh_token_hash','vp3_connected_site_revoke_v100'])assert.ok(core.includes(needle),needle);
for(const scope of ['account.identity.read','transcriptions.read','transcriptions.intelligence.read'])assert.ok(core.includes(scope),scope);
assert.ok(core.includes("hash('sha256',$access)"),'access token stored hashed');
assert.ok(core.includes("hash('sha256',$refresh)"),'refresh token stored hashed');
assert.ok(core.includes('DATE_ADD(NOW(),INTERVAL 5 MINUTE)'),'short-lived authorization code');
assert.ok(core.includes('DATE_ADD(NOW(),INTERVAL 1 HOUR)'),'one-hour access token');
assert.ok(core.includes('DATE_ADD(NOW(),INTERVAL 90 DAY)'),'bounded refresh token');
assert.ok(core.includes('hash_equals((string)$app[\'redirect_uri\'],$redirect)'),'exact redirect validation');
assert.ok(core.includes('video_meeting_recent_for_user_v1800'),'uses existing meeting permission listing');
assert.ok(core.includes('video_meeting_by_public_id_v1800'),'uses existing meeting identity');
assert.ok(core.includes('video_meeting_participants'),'checks existing participant access');
assert.ok(core.includes('video_meeting_transcript_segments'),'projects canonical transcript segments');
assert.ok(core.includes('video_meeting_intelligence_source_v1820'),'projects canonical Meeting Intelligence source');
assert.ok(core.includes('video_meeting_intelligence_snapshot_v1820'),'projects canonical Meeting Intelligence snapshot');
assert.ok(core.includes('artist_transcript_sessions_v172')&&core.includes('artist_listening_v172_segments'),'projects canonical general transcription sessions/segments');
assert.ok(core.includes('transcription_app_modules_v306'),'projects canonical grounded transcription summary output');
assert.ok(!core.includes('CREATE TABLE IF NOT EXISTS video_meeting_transcript_segments'),'does not duplicate transcript store');
assert.ok(!core.includes('CREATE TABLE IF NOT EXISTS video_meeting_intelligence'),'does not duplicate intelligence store');

assert.ok(boot.includes("require_once __DIR__.'/connected-sites-v100.php'"),'bootstrap loads Connected Sites');
assert.ok(account.includes('/connected-sites.php')&&account.includes('Connected Sites'),'account exposes Connected Sites');
assert.ok(authorize.includes('verify_csrf()')&&authorize.includes('Requested access')&&authorize.includes("decision==='approve'"),'authorization requires user consent + CSRF');
assert.ok(token.includes('client_secret')&&token.includes('authorization_code')&&token.includes('refresh_token'),'token endpoint supports authenticated code/refresh grants');
assert.ok(me.includes("vp3_connected_site_auth_v100($pdo,'account.identity.read')"),'identity endpoint scope guarded');
assert.ok(artifacts.includes("vp3_connected_site_auth_v100($pdo,'transcriptions.read')"),'artifact endpoint transcription scope guarded');
assert.ok(artifacts.includes("str_starts_with($id,'transcription-')"),'artifact endpoint supports general transcription detail');
assert.ok(revoke.includes('vp3_connected_site_revoke_v100'),'remote revoke endpoint wired');
assert.ok(upgrade.includes('vp3_connected_sites_schema_ready_v100()')&&upgrade.includes('vp3_connected_sites_ensure_schema_v100($pdo)'),'upgrade installs and verifies Connected Sites');
assert.ok(setup.includes('vp3_connected_sites_ensure_schema_v100($pdo)'),'fresh setup installs Connected Sites');

console.log('Annotated connector v1.0 static contract passed.');
