import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path,'utf8');
const ai = read('includes/ai-settings.php');
const voice = read('api/agent-voice-v117.php');
const adminApi = read('api/admin-elevenlabs-v102.php');
const adminUi = read('admin/ai-elevenlabs-v102.js');
const adminPage = read('admin/ai.php');
const deploy = read('.github/workflows/production-deploy-package.yml');

assert.match(ai,/function ai_master_key_state\(\)/,'AI credential runtime must expose master-key health');
assert.match(ai,/function ai_encrypted_secret_state\(/,'AI credential runtime must distinguish decrypt failure causes');
assert.match(ai,/function ai_saved_encrypted_credentials_exist\(/,'AI credential runtime must know whether encrypted credentials still depend on the local key');
assert.match(ai,/The API credential encryption key is missing while encrypted credentials still exist/,'AI credential runtime must refuse silent key rotation after key loss');
assert.match(ai,/Restore \/private\/ai-key\.php before saving credentials/,'AI credential runtime must direct recovery to the original local key');
assert.match(ai,/crypto_unavailable/,'AI credential runtime must distinguish missing crypto support');
assert.match(ai,/key_mismatch/,'AI credential runtime must distinguish a mismatched key');

assert.match(voice,/ai_encrypted_secret_state\(\$encrypted\)/,'ElevenLabs runtime must classify encrypted credential state before decryption');
assert.match(voice,/ai_credential_state_message\(\$credentialState, 'ElevenLabs'\)/,'ElevenLabs warm state must return actionable key recovery guidance');
assert.doesNotMatch(voice,/cannot be decrypted\. Re-enter it in Admin AI settings/,'voice runtime must not misdiagnose all decrypt failures as a changed credential');

assert.match(adminApi,/'credential_state' => \$credentialState/,'Admin ElevenLabs API must expose credential state');
assert.match(adminApi,/'credential_message' =>/,'Admin ElevenLabs API must expose recovery guidance');
assert.match(adminUi,/Encryption key missing/,'Admin ElevenLabs UI must identify a missing local key');
assert.match(adminUi,/credential_message/,'Admin ElevenLabs UI must render server recovery guidance');
assert.match(adminPage,/AI credential recovery required\./,'Admin AI must show a cross-provider recovery warning');
assert.match(adminPage,/must never replace the <code>\/private<\/code> runtime directory/,'Admin AI must document deploy preservation');

for (const excluded of ["--exclude='config.php'","--exclude='.env'","--exclude='.env.*'","--exclude='private/'","--exclude='uploads/'"]) {
  assert.ok(deploy.includes(excluded), `production deploy must preserve runtime path via ${excluded}`);
}
assert.match(deploy,/test ! -e _deploy\/private/,'production packaging must fail if private runtime storage is included');
assert.match(deploy,/test ! -e _deploy\/uploads/,'production packaging must fail if uploads runtime storage is included');
assert.match(deploy,/preserve_runtime_paths/,'release manifest must declare preserved runtime paths');

console.log('AI credential key preservation contract: PASS');
