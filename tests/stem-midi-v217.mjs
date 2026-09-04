import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';

const js=fs.readFileSync('admin/stem-midi-v217.js','utf8');
const css=fs.readFileSync('admin/stem-midi-v217.css','utf8');
const apiPhp=fs.readFileSync('api/stem-midi-v217.php','utf8');
const helper=fs.readFileSync('includes/midi-v217.php','utf8');
const permissions=fs.readFileSync('includes/permissions-v105.php','utf8');
const footer=fs.readFileSync('admin/_footer.php','utf8');
const admin=fs.readFileSync('admin/midi.php','utf8');
const bootstrap=fs.readFileSync('includes/bootstrap.php','utf8');
const upgrade=fs.readFileSync('upgrade.php','utf8');

for(const file of [
  'api/stem-midi-v217.php','includes/midi-v217.php','admin/midi.php','includes/bootstrap.php','upgrade.php','admin/_footer.php'
]) execFileSync('php',['-l',file],{stdio:'pipe'});
execFileSync(process.execPath,['--check','admin/stem-midi-v217.js'],{stdio:'pipe'});

const bridgeMatch=footer.match(/<script data-stem-midi-session-v217>([\s\S]*?)<\/script>/);
assert.ok(bridgeMatch?.[1],'inline MIDI session bridge should exist');
new vm.Script(bridgeMatch[1],{filename:'stem-midi-session-v217-inline.js'});

const sandbox={console};sandbox.globalThis=sandbox;vm.createContext(sandbox);vm.runInContext(js,sandbox,{filename:'stem-midi-v217.js'});
const midi=sandbox.StonefellowStemMidiV217;
assert.ok(midi,'MIDI API should load');
assert.equal(midi.build,'stem-midi-foundation-v217-20260901');
assert.equal(midi.PPQ,960);
assert.equal(midi.FOUR_BARS,15360);
assert.equal(midi.noteName(60),'C4');
assert.ok(Math.abs(midi.midiFrequency(69)-440)<1e-9);
assert.equal(midi.secondsToTicks(1,120),1920);
assert.equal(midi.ticksToSeconds(1920,120),1);
assert.equal(midi.quantizeTick(487,'1/16'),480);
assert.equal(midi.quantizeTick(721,'1/8'),960);
const track=midi.newTrack('Keys','poly');
assert.equal(track.instrument.type,'poly');
assert.equal(track.clips.length,1);
assert.equal(track.clips[0].lengthTick,15360,'new MIDI clips should start at four 4/4 bars');
assert.equal(track.volume,.8);
const drum=midi.newTrack('Drums','drum');
assert.equal(drum.instrument.type,'drum');
const quantized=midi.quantizeNotes([{id:'n',pitch:60,startTick:499,durationTick:200,velocity:.8,channel:1}],'1/16');
assert.equal(quantized[0].startTick,480);
const transposed=midi.transposeNotes([{id:'n',pitch:127,startTick:0,durationTick:200,velocity:.8,channel:1}],12);
assert.equal(transposed[0].pitch,127);
const normalized=midi.normalizeState({tracks:[{name:'X',instrument:{type:'invalid',waveform:'bad'},clips:[{notes:[{pitch:200,velocity:0,startTick:-5,durationTick:0}]}]}]});
assert.equal(normalized.ppq,960);
assert.equal(normalized.tracks[0].instrument.type,'poly');
assert.equal(normalized.tracks[0].volume,.8,'missing MIDI track volume must not normalize to silence');
assert.equal(normalized.tracks[0].clips[0].lengthTick,15360);
assert.equal(normalized.tracks[0].clips[0].notes[0].pitch,127);
assert.equal(normalized.tracks[0].clips[0].notes[0].velocity,.01);
assert.equal(normalized.tracks[0].clips[0].notes[0].durationTick,1);
assert.equal(midi.normalizeNote({pitch:60}).velocity,.8,'missing velocity should use the musical default');

// Permission and global-feature architecture.
assert.match(permissions,/'midi\.access'/);
assert.match(permissions,/'midi\.manage'/);
assert.match(permissions,/'midi\.access'=>\['artist','manager','producer','supervisor','admin'\]/);
assert.match(permissions,/'midi\.manage'=>\['admin'\]/);
assert.match(helper,/setting\('midi_feature_enabled_v217','0'\) === '1'/,'MIDI must default globally OFF');
assert.match(helper,/return midi_v217_feature_enabled\(\) && permission_v105_has\('midi\.access',\$user\)/,'user access must require both gates');
assert.match(helper,/return permission_v105_has\('midi\.manage',\$user\)/,'management must use the role permission system');
assert.match(helper,/stem_midi_projects_v217/);
assert.match(helper,/midi_permissions_seed_v217/);
assert.match(helper,/midi_v217_clean_snapshot/,'saved Studio versions need a reusable bounded MIDI model');
assert.match(helper,/12000/,'MIDI snapshot sanitizer must bound notes per clip');
assert.match(helper,/FOREIGN KEY \(track_id\) REFERENCES tracks\(id\) ON DELETE CASCADE/);

// Admin must control the switch but never silently run DDL just by opening the page.
assert.match(admin,/Enable MIDI for permitted users/);
assert.match(admin,/Manage Role Permissions/);
assert.match(admin,/midi_v217_require_manage/);
assert.match(admin,/\$setupReady = \$schemaReady && \$permissionsReady/);
assert.match(admin,/Run the Stonefellow database upgrade before enabling MIDI Studio/);
assert.match(admin,/Run Stonefellow Upgrade/);
assert.match(admin,/Opening this page does not modify the database/);
assert.doesNotMatch(admin,/midi_v217_ensure_schema\s*\(/,'Admin > MIDI must not execute schema migration as a page side effect');
assert.doesNotMatch(admin,/midi_v217_seed_permissions\s*\(/,'Admin > MIDI must not reseed role permissions as a page side effect');

// Assets are emitted only inside the global+permission gate.
assert.match(footer,/\$midiV217Allowed = \$midiV217StudioRequest[\s\S]*STONEFELLOW_STEM_ADVANCED_RUNTIME[\s\S]*function_exists\('midi_v217_can_access'\)[\s\S]*midi_v217_can_access\(\)/);
assert.match(footer,/<\?php if \(\$midiV217Allowed\): \?>/);
assert.match(footer,/data-stem-midi-v217/);
assert.match(footer,/data-stem-midi-session-v217/);
assert.match(footer,/'compositionOwnsSnapshots'=>true/,'v218 must be the single saved-mix snapshot owner');
assert.match(footer,/if\(midiCfg\.compositionOwnsSnapshots\)/,'legacy v217 bridge must delegate instead of wrapping fetch twice');
assert.match(footer,/delegatedTo:'v218'/);
assert.match(footer,/stem-midi-v217\.js/);
assert.match(footer,/stem-midi-v217\.css/);
assert.match(footer,/api\/stem-midi-v217\.php/);
assert.match(footer,/stem-midi-session-v217-20260901/);
assert.match(footer,/snapshot_attach/);
assert.match(footer,/snapshot_load/);
assert.match(footer,/retrySnapshot/,'saved mix MIDI sidecars must retry transient failures');
assert.match(footer,/limit=3/,'MIDI sidecar retry policy must be bounded');
assert.match(footer,/MIDI SNAPSHOT FAILED · RETRY SAVE/);
assert.match(footer,/Mix recall was stopped because its MIDI snapshot could not be restored/,'partial session recall must fail closed');
assert.match(footer,/partial_save/,'partial save status must be explicit to the caller');
assert.match(footer,/stonefellow:stem-midi-v217-session-error/);
assert.match(footer,/restoreState\?\./,'saved mix recall must restore MIDI before returning to v216');
assert.match(footer,/root\.fetch=wrapper/,'MIDI snapshot bridge must sit outside the existing v216 fetch wrapper');
assert.doesNotMatch(footer,/SpeechRecognition|ElevenLabs|premium-voice|conversation-voice|chatVoiceButton/);

assert.match(bootstrap,/midi=\(self\)/);
assert.match(bootstrap,/midi-v217\.php/);
assert.match(upgrade,/midi_v217_ensure_schema/);
assert.match(upgrade,/midi_permissions_seed_v217/);

// API must enforce feature + permission + CSRF + production ownership independently of UI.
assert.match(apiPhp,/midi_v217_feature_enabled\(\)/);
assert.match(apiPhp,/permission_v105_has\('midi\.access'\)/);
assert.match(apiPhp,/verify_csrf\(\)/);
assert.match(apiPhp,/midi_v217_track\(\$trackId\)/);
assert.match(apiPhp,/midi_v217_schema_ready\(\)/);
assert.match(apiPhp,/action === 'load'/);
assert.match(apiPhp,/action === 'save'/);
assert.match(apiPhp,/action === 'reset'/);
assert.match(apiPhp,/action === 'snapshot_attach'/);
assert.match(apiPhp,/action === 'snapshot_load'/);
assert.match(apiPhp,/stem_mix_saves/,'MIDI A\/B and checkpoint snapshots must live on the existing saved mix record');
assert.match(apiPhp,/\$mix\['midiV217'\]/);
assert.match(apiPhp,/15360/,'server MIDI clips should default to four 4/4 bars');
assert.match(apiPhp,/\$raw\['updatedAt'\]/,'server must preserve client edit time for crash-recovery ordering');
assert.match(apiPhp,/midi_v217_optional_id/,'optional selection IDs need deterministic empty-state sanitization');
assert.match(apiPhp,/'selectedTrackId'=>midi_v217_optional_id/);
assert.match(apiPhp,/'selectedClipId'=>midi_v217_optional_id/);
assert.doesNotMatch(apiPhp,/'selectedTrackId'=>midi_v217_id/,'empty selected IDs must never become random server IDs');
assert.match(apiPhp,/12000/,'notes per clip must be bounded');
assert.match(apiPhp,/16777216/,'project and saved-session JSON size must be bounded');

// MIDI timing/editor contracts.
assert.match(js,/requestMIDIAccess/);
assert.match(js,/sysex:false/);
assert.match(js,/onmidimessage/);
assert.match(js,/PIANO ROLL/);
assert.match(js,/MIDI RECORD ARMED/);
assert.match(js,/Poly Synth/);
assert.match(js,/Drum Rack/);
assert.match(js,/quantizeSelected/);
assert.match(js,/transposeSelected/);
assert.match(js,/stopScheduledVoices/,'transport stop/seek must cancel future MIDI voices');
assert.match(js,/position<lastPosition-\.05\|\|Math\.abs\(position-lastPosition\)>\.7/,'seek and loop-wrap detection must reset MIDI scheduling');
assert.match(js,/!midiRecording\|\|!Boolean\(agent\(\)\.playing\)/,'MIDI record must require the Studio transport to be running');
assert.match(js,/extendClipTo/,'recorded and edited notes must be allowed to extend their clip');
assert.match(js,/setNoteNodeGeometry/,'note drag should update the captured node instead of replacing it mid-gesture');
assert.match(js,/getMasterInput/,'MIDI audio must enter the normal master processing chain');
assert.match(js,/restoreState/,'saved-session recall needs a MIDI state restore path');
assert.match(js,/getMasterSource/,'MIDI audio should feed the stable Studio output tap');
assert.match(js,/getPosition/,'MIDI clock must use the Studio transport position');
assert.match(js,/agent\(\)\.tempo/,'MIDI timing must use the Studio tempo');
assert.match(js,/stonefellow:stem-midi-v217-change/);
assert.match(js,/root\.addEventListener\('pageshow'/,'MIDI transport must recover after a bfcache restore');
assert.doesNotMatch(js,/pagehide[\s\S]{0,180}clearInterval\(scheduleTimer\)/,'pagehide must not permanently stop the scheduler');
assert.doesNotMatch(js,/SpeechRecognition|ElevenLabs|premium-voice|conversation-voice|chatVoiceButton/);
assert.doesNotMatch(apiPhp,/SpeechRecognition|ElevenLabs|premium-voice|conversation-voice/);

assert.match(css,/sf-midi-editor/);
assert.match(css,/sf-midi-roll/);
assert.match(css,/sf-midi-note/);
assert.match(css,/sf-midi-keyboard/);

console.log('STEM_MIDI_V217=PASS');
