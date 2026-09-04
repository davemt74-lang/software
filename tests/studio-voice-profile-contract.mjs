import assert from 'node:assert/strict';
import fs from 'node:fs';

const page=fs.readFileSync('voice-profile.php','utf8');
const runtime=fs.readFileSync('voice-profile.js','utf8');
const styles=fs.readFileSync('voice-profile.css','utf8');
const domain=fs.readFileSync('includes/studio-voice-profile.php','utf8');
const api=fs.readFileSync('api/studio-voice-profile.php','utf8');
const sidebar=fs.readFileSync('includes/workspace-sidebar-v82.php','utf8');
const upgrade=fs.readFileSync('upgrade.php','utf8');
const sql=fs.readFileSync('upgrade-studio-voice-profile.sql','utf8');

assert.match(page,/Voice Profile/,'account-facing Voice Profile page exists');
assert.match(page,/Start Recording/,'Voice Profile exposes microphone recording');
assert.match(page,/Upload Sample/,'Voice Profile supports sample upload');
assert.match(page,/Create My Voice Clone/,'Voice Profile exposes explicit clone creation');
assert.match(page,/Revoke Voice Clone/,'Voice Profile exposes clone revocation');
assert.match(page,/Who can recognize me\?/,'recognition privacy is visible and understandable');
assert.match(page,/Voice recognition is conversational context only/,'Voice Profile explicitly denies authentication use');
assert.match(page,/voice-profile\.js/,'page owns one canonical Voice Profile runtime');
assert.match(styles,/voice-profile-grid/,'Voice Profile has dedicated responsive layout styling');

assert.match(domain,/CREATE TABLE IF NOT EXISTS studio_voice_samples/,'Voice Profile samples have dedicated storage metadata');
assert.match(domain,/private\/studio-voice-samples/,'raw voice samples live under private server storage');
assert.match(domain,/Voice samples may only be saved to your own Voice Profile/,'sample storage is self-only');
assert.match(domain,/STONEFELLOW_STUDIO_VOICE_SAMPLE_MAX_BYTES = 26214400/,'sample storage has an explicit 25 MB ceiling');
assert.match(domain,/move_uploaded_file/,'uploaded samples are moved into controlled private storage');
assert.match(sql,/studio_voice_samples/,'standalone schema upgrade includes Voice Profile samples');
assert.match(upgrade,/studio_voice_profile_ensure_schema\(\)/,'normal database upgrade installs Voice Profile storage');

assert.match(api,/https:\/\/api\.elevenlabs\.io\/v1\/voices\/add/,'clone creation uses ElevenLabs Instant Voice Clone');
assert.match(api,/https:\/\/api\.elevenlabs\.io\/v1\/voices\//,'clone revocation uses the documented ElevenLabs voice endpoint');
assert.match(api,/function studio_voice_profile_delete_remote_voice/,'remote voice deletion has one lifecycle owner');
assert.match(api,/CURLOPT_CUSTOMREQUEST=>'DELETE'/,'revoke performs remote deletion instead of only hiding the local binding');
assert.match(api,/https:\/\/api\.elevenlabs\.io\/v1\/text-to-speech\//,'clone preview uses ElevenLabs text to speech');
assert.match(api,/ownership_confirmed/,'clone API requires explicit ownership confirmation');
assert.match(api,/selected sample is your own voice/,'server describes the ownership requirement');
assert.match(api,/A voice clone already exists\. Revoke it before creating a replacement/,'replacement cannot orphan an older remote clone');
assert.match(api,/Revoke the active voice clone before disabling cloning consent/,'privacy settings cannot orphan an active remote clone');
assert.match(api,/local clone binding was kept/,'failed remote deletion keeps local provenance instead of pretending revoke succeeded');
assert.match(api,/catch \(Throwable \$bindError\)[\s\S]*studio_voice_profile_delete_remote_voice\(\$apiKey, \$voiceId\)/,'failed local clone binding compensates by deleting the just-created remote voice');
assert.match(api,/remote cleanup was not confirmed/,'failed compensation is surfaced rather than silently orphaning a provider voice');
assert.match(api,/source_sample_id/,'Voice Profile state exposes local clone-source provenance without provider identifiers');
assert.match(api,/studio_participants_bind_voice/,'Voice Profile reuses the participant voice identity domain');
assert.doesNotMatch(domain,/=>\s*\$row\['file_name'\]/,'private sample file names are not returned by the public sample list');

assert.match(runtime,/navigator\.mediaDevices\?\.getUserMedia/,'browser requests microphone access only when recording starts');
assert.match(runtime,/new MediaRecorder/,'recording uses the browser MediaRecorder path');
assert.match(runtime,/Stop & Save|Finishing recording/,'recording is saved through a clear user action');
assert.match(runtime,/MAX_RECORDING_MS=120000/,'microphone capture has a bounded two-minute maximum');
assert.match(runtime,/Two-minute recording limit reached/,'automatic recording stop is visible to the user');
assert.match(runtime,/Active clone source/,'the UI identifies the private sample that produced the active clone');
assert.match(runtime,/I confirm this selected recording contains my own voice/,'clone creation requires direct own-voice confirmation');
assert.match(runtime,/send this sample to ElevenLabs/,'clone confirmation discloses the external provider transfer');
assert.match(runtime,/permanently delete your Stonefellow voice clone from ElevenLabs/,'revoke confirmation describes remote deletion');
assert.match(runtime,/Voice recognition remains conversational context only/,'privacy confirmation preserves the non-authentication boundary');
assert.match(runtime,/URL\.revokeObjectURL/,'generated preview object URLs are cleaned up');
assert.doesNotMatch(runtime,/provider_speaker_id/,'Voice Profile UI never exposes provider biometric speaker identifiers');

assert.match(sidebar,/voice-profile\.php/,'Voice Profile is reachable from the shared workspace sidebar');
assert.match(sidebar,/workspaceSidebarActive === 'voice_profile'/,'Voice Profile sidebar state is first-class');

console.log('STUDIO_VOICE_PROFILE_CONTRACT=PASS');
