import assert from 'node:assert/strict';
import fs from 'node:fs';
import {spawnSync} from 'node:child_process';

const domain=fs.readFileSync('includes/studio-participants.php','utf8');
const api=fs.readFileSync('api/studio-participants.php','utf8');
const runtime=fs.readFileSync('studio-participants.js','utf8');
const browserContext=fs.readFileSync('agent-context-v131.js','utf8');
const serverContext=fs.readFileSync('includes/agent-surface-context-v131.php','utf8');
const upgrade=fs.readFileSync('upgrade.php','utf8');
const sql=fs.readFileSync('upgrade-studio-participants.sql','utf8');

assert.match(domain,/recognition_provider_speaker_id/,'recognition identity has a dedicated provider binding');
assert.match(domain,/clone_provider_voice_id/,'voice cloning has a separate provider binding');
assert.match(domain,/recognition_verified TINYINT/,'recognition verification has its own state');
assert.match(domain,/clone_verified TINYINT/,'clone verification has its own state');
assert.match(sql,/recognition_verified TINYINT/,'standalone upgrade includes recognition verification state');
assert.match(sql,/clone_verified TINYINT/,'standalone upgrade includes clone verification state');
assert.match(domain,/STONEFELLOW_PARTICIPANT_RECOGNITION_THRESHOLD\s*=\s*0\.82/,'recognition threshold is explicit and conservative');
assert.match(domain,/recognition_consent/,'recognition consent is stored independently');
assert.match(domain,/cloning_consent/,'cloning consent is stored independently');
assert.match(domain,/if \(\$recognitionId !== '' && empty\(\$profile\['recognition_consent'\]\)\)/,'recognition binding requires consent');
assert.match(domain,/if \(\$cloneId !== '' && empty\(\$profile\['cloning_consent'\]\)\)/,'clone binding requires separate consent');
assert.match(domain,/array_key_exists\('recognition_provider_speaker_id', \$binding\)/,'domain binding preserves independent recognition state when omitted');
assert.match(domain,/array_key_exists\('clone_provider_voice_id', \$binding\)/,'domain binding preserves independent clone state when omitted');
assert.match(domain,/v\.recognition_enabled=1 AND v\.recognition_verified=1/,'unverified recognition bindings cannot identify participants');
assert.match(domain,/\$confidence < STONEFELLOW_PARTICIPANT_RECOGNITION_THRESHOLD/,'low-confidence voice matches cannot identify participants');
assert.match(domain,/Participant presence requires an active conversation or transcription session/,'participant presence cannot leak into an unscoped global session');
assert.match(domain,/if \(\$conversationId < 1 && \$transcriptSessionId < 1\) \{\s*return \['build'=>STONEFELLOW_STUDIO_PARTICIPANTS,'count'=>0,'participants'=>\[\]\];/s,'unscoped context is empty');
assert.match(domain,/Voice ' \. substr\(hash\('sha256', \$providerSpeakerId\), 0, 12\)/,'unlabeled provider speakers use a non-secret stable local label');

assert.match(api,/https:\/\/api\.elevenlabs\.io\/v1\/voices\/add/,'voice clone uses the current ElevenLabs Instant Voice Clone endpoint');
assert.match(api,/has_permission\('artist_listening\.access', \$user\)/,'clone source requires Artist Listening permission');
assert.match(api,/consent_confirmed/,'clone creation requires an explicit per-action consent confirmation');
assert.match(api,/ownership confirmation are required/,'server treats voice ownership confirmation as part of clone consent');
assert.match(api,/relationship_scope'\] !== 'self'/,'clone creation is restricted to the signed-in account owner');
assert.match(api,/Contact voice identities must be shared by the contact account/,'contact voice identities cannot be fabricated locally');
assert.match(api,/studio_participants_existing_binding/,'recognition and cloning updates preserve the other independent binding');
assert.match(api,/'recognition_provider_speaker_id'=>\$existing\['recognition_provider_speaker_id'\]/,'creating a clone preserves recognition identity');
assert.match(api,/'clone_provider_voice_id'=>\$existing\['clone_provider_voice_id'\]/,'binding recognition preserves an existing clone');
assert.match(api,/'recognition_verified'=>\$existing\['recognition_verified'\]/,'creating a clone preserves recognition verification independently');
assert.match(api,/'clone_verified'=>\$existing\['clone_verified'\]/,'binding recognition preserves clone verification independently');
assert.match(api,/'authentication_authority'=>false/,'recognition API explicitly denies authentication authority');

assert.match(runtime,/authenticationAuthority:\s*false/,'browser participant runtime never treats recognition as authentication');
assert.match(runtime,/authentication_authority:\s*false/,'Agent-facing participant context is marked non-authenticating');
assert.match(runtime,/recognition_verified:\s*Boolean\(row\?\.voice\?\.recognition_verified\)/,'runtime preserves recognition verification separately');
assert.match(runtime,/clone_verified:\s*Boolean\(row\?\.voice\?\.clone_verified\)/,'runtime preserves clone verification separately');
assert.match(runtime,/participants\.profile\.save/,'Studio Assistant can manage participant profiles');
assert.match(runtime,/participants\.consent\.set/,'Studio Assistant can manage explicit voice consent');
assert.match(runtime,/participants\.presence\.record/,'Studio Assistant can record participant presence');
assert.match(runtime,/participants\.voice\.clone_from_recording/,'Studio Assistant can invoke self-voice cloning from retained recordings');
assert.match(runtime,/registerSurface\('participants'/,'participant operations register with the canonical Editor Agent broker');
assert.match(runtime,/typeof window\.confirm !== 'function'/,'sensitive Editor Agent participant actions require real browser confirmation support');
assert.match(runtime,/enable voice identity features for this participant/,'enabling voice identity requires direct user confirmation');
assert.match(runtime,/I confirm this retained recording contains my own voice/,'voice cloning requires explicit voice-ownership confirmation');
assert.match(runtime,/send it to ElevenLabs to create my Stonefellow voice clone/,'clone confirmation discloses provider transfer and purpose');
assert.match(runtime,/cloneFromRecording\(\{ \.\.\.args, consent_confirmed:true \}\)/,'only the confirmed browser execution path creates the clone consent receipt');
assert.doesNotMatch(runtime,/participants\.voice\.clone_from_recording[^\n]+consent_confirmed/,'the model-facing clone command cannot provide its own consent confirmation');
assert.doesNotMatch(runtime,/provider_speaker_id[^\n]*agentContext/,'provider speaker identities are not deliberately surfaced by Agent Context');
assert.match(browserContext,/participants:participantContext\(\)/,'participant presence is part of browser Agent Context');
assert.match(browserContext,/studio-participants\.js/,'Agent Context bootstraps the participant runtime');
assert.match(browserContext,/\['chat','stem','video','transcription'\]\.includes\(surface\)/,'participant runtime is available across all studio Agent Context surfaces');
assert.match(serverContext,/'participants'=>null/,'server context has an explicit participant field');
assert.match(serverContext,/'authentication_authority'=>false/,'server hard-codes voice recognition as non-authenticating');
assert.match(upgrade,/studio_participants_ensure_schema\(\)/,'normal database upgrade installs participant storage');

const rawContext={
  surface:'chat',
  participants:{
    build:'studio-participants-20260903',
    authentication_authority:true,
    participants:[
      {
        participant_id:12,name:'John Example',speaker_label:'Speaker 2',relationship:'contact',
        recognized:true,method:'voice',confidence:.94,linked_user_id:55,last_seen_at:'2026-09-03 19:00:00',
        provider_speaker_id:'SECRET_PROVIDER_ID'
      },
      {
        participant_id:99,name:'Eve Wrong',speaker_label:'Speaker 3',relationship:'contact',
        recognized:false,method:'voice',confidence:.20,linked_user_id:66,last_seen_at:'2026-09-03 19:01:00',
        provider_speaker_id:'SECRET_PROVIDER_ID_2'
      }
    ]
  }
};
const php=spawnSync('php',['-r',"require 'includes/agent-surface-context-v131.php'; $raw=json_decode(getenv('RAW'),true); echo json_encode(agent_surface_v131_sanitize($raw));"],{
  cwd:process.cwd(),encoding:'utf8',env:{...process.env,RAW:JSON.stringify(rawContext)}
});
assert.equal(php.status,0,php.stderr||'PHP participant sanitizer invocation failed');
const sanitized=JSON.parse(php.stdout);
assert.equal(sanitized.participants.authentication_authority,false,'browser/provider cannot elevate voice recognition to authentication');
assert.equal(sanitized.participants.participants[0].name,'John Example','recognized participant can provide conversational identity');
assert.equal(sanitized.participants.participants[0].participant_id,12);
assert.equal(sanitized.participants.participants[1].name,'','unrecognized speaker name is stripped');
assert.equal(sanitized.participants.participants[1].participant_id,0,'unrecognized participant id is stripped');
assert.equal(sanitized.participants.participants[1].linked_user_id,0,'unrecognized account id is stripped');
assert.doesNotMatch(JSON.stringify(sanitized),/SECRET_PROVIDER_ID/,'provider biometric identifiers never enter model context');

console.log('STUDIO_PARTICIPANTS_CONTRACT=PASS');
