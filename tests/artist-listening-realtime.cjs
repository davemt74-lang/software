const fs = require('node:fs');
const assert = require('node:assert/strict');
const runtime = require('../artist-listening-realtime.js');

let result = runtime.reconcileFinal('we should move the release date', 'the release date to Friday');
assert.equal(result.delta, 'to Friday');
assert.equal(result.merged, 'we should move the release date to Friday');
assert.equal(result.overlapWords, 3);

result = runtime.reconcileFinal('finish the mix first', 'finish the mix first');
assert.equal(result.duplicate, true);
assert.equal(result.delta, '');

const continuity = new runtime.TranscriptContinuity('keep the first sentence');
continuity.setInterim('partial words survive a native reset');
assert.equal(continuity.interim, 'partial words survive a native reset');
result = continuity.finalize('the first sentence and continue', 4200);
assert.equal(result.delta, 'and continue');
assert.equal(continuity.committed, 'keep the first sentence and continue');

const captureA = new runtime.TranscriptContinuity();
const captureB = new runtime.TranscriptContinuity();
const firstShared = captureA.finalize('this finalized phrase must persist once', 9000);
const secondShared = captureB.finalize('this finalized phrase must persist once', 9040);
assert.equal(firstShared.duplicate, false, 'first capture owner must accept the finalized phrase');
assert.equal(secondShared.duplicate, true, 'second capture owner must suppress the same finalized phrase');
assert.equal(secondShared.sharedDuplicate, true, 'cross-instance duplicate suppression must be explicit');
assert.equal(captureB.committed, '', 'suppressed duplicate must never enter the second continuity history');

const model = new runtime.SpeakerTurnModel({maxSpeakers:4, expectedSpeakers:4});
const voiceA = {rms:.05,zcr:.08,centroid:.18};
const voiceB = {rms:.16,zcr:.31,centroid:.76};
const first = model.assign({features:voiceA,startedMs:0,endedMs:900});
const second = model.assign({features:voiceB,startedMs:1800,endedMs:2600});
const firstAgain = model.assign({features:voiceA,startedMs:3400,endedMs:4100});
assert.equal(first.index, 1);
assert.equal(second.index, 2);
assert.equal(firstAgain.index, 1, 'decisive acoustic match must return to the original participant');
assert.equal(first.inferred, true);
assert.ok(first.confidence >= 0 && first.confidence <= 1);

const stable = new runtime.SpeakerTurnModel({maxSpeakers:4, expectedSpeakers:2});
stable.assign({features:voiceA,startedMs:0,endedMs:700});
const near1 = stable.assign({features:{rms:.052,zcr:.081,centroid:.184},startedMs:900,endedMs:1500});
const near2 = stable.assign({features:{rms:.053,zcr:.083,centroid:.187},startedMs:1700,endedMs:2200});
assert.equal(near1.index, 1, 'small acoustic movement must not create a false participant');
assert.equal(near2.index, 1, 'speaker hysteresis must stay stable across similar turns');

const features = runtime.aggregateFeatures([
  {timeMs:1000,rms:.05,zcr:.08,centroid:.2},
  {timeMs:1080,rms:.07,zcr:.1,centroid:.24},
  {timeMs:9000,rms:.4,zcr:.4,centroid:.9},
], 950, 1200);
assert.ok(features && features.frames === 2);
assert.match(runtime.autoTitle(new Date('2026-08-31T13:42:00')), /^Transcription · /);

const page = fs.readFileSync('artist-listening.php','utf8');
const capture = fs.readFileSync('artist-listening.js','utf8');
const workspace = fs.readFileSync('artist-listening-workspace.js','utf8');
const recognition = fs.readFileSync('artist-listening-recognition.js','utf8');
const realtimeSource = fs.readFileSync('artist-listening-realtime.js','utf8');
const ai = fs.readFileSync('artist-listening-ai.js','utf8');
const api = fs.readFileSync('api/artist-listening-v174.php','utf8');
const checkpointApi = fs.readFileSync('api/artist-listening-intelligence-v236.php','utf8');

assert.match(page,/artist-listening-realtime\.js\?v=e07b7c39/);
assert.match(page,/artist-listening\.js\?v=9ac023be/);
assert.match(page,/artist-listening-ai\.js\?v=c18c3dc8/);
assert.match(page,/STONEFELLOW_ARTIST_LISTENING_CONFIG/);
assert.match(page,/Object\.assign\(window\.STONEFELLOW_ARTIST_LISTENING_V172\|\|\{},window\.STONEFELLOW_ARTIST_LISTENING_CONFIG\|\|\{}\)/);
assert.doesNotMatch(page,/artist-listening-realtime-v\d+\.js/);
assert.doesNotMatch(capture,/data-listening-transcript|data-listening-interim/);
assert.match(capture,/stonefellow:artist-listening-live/);
assert.match(capture,/aggregateFeatures/);
assert.match(capture,/started_ms:started,ended_ms:ended/);
assert.match(capture,/MediaRecorder/,'capture runtime alone may own retained audio recording');
assert.match(capture,/heard==='start recording'/);
assert.match(capture,/heard==='stop recording'/);
assert.doesNotMatch(workspace,/MediaRecorder/);
assert.doesNotMatch(recognition,/MediaRecorder/);
assert.doesNotMatch(realtimeSource,/MediaRecorder/);
assert.match(workspace,/data-listening-workspace-turns/);
assert.match(workspace,/renderEvents\(live\)/);
assert.match(api,/update_turn/);
assert.match(api,/speaker_label=\?,transcript_text=\?/);

/* Realtime reconciles speech only. It must never boot or inject AI. */
assert.match(realtimeSource,/switchVotes/);
assert.match(realtimeSource,/decisive/);
assert.match(realtimeSource,/STONEFELLOW_ARTIST_LISTENING_RECENT_FINALS/);
assert.match(realtimeSource,/sharedDuplicate:true/);
assert.doesNotMatch(realtimeSource,/createElement\('script'\)|artist-listening-intelligence-v236\.js|artist-listening-ai\.js/);

/* AI consumes live transcript events but never owns the realtime/capture engine. */
assert.match(ai,/function getButton\(\)/);
assert.match(ai,/button\.addEventListener\('click'/);
assert.match(ai,/setOpen\(!state\.open\)/);
assert.doesNotMatch(ai,/sfListeningAiDebug|AI SUMMARY DEBUG/);
assert.match(ai,/state\.liveWords/);
assert.match(ai,/data-listening-ai-research/);
assert.match(ai,/scheduleLive\('words'\)/);
assert.match(ai,/request\('analyze'/);
assert.match(ai,/sf-listening-ai-footer-actions/);
assert.match(ai,/sf-listening-ai-report-state/);
assert.doesNotMatch(ai,/data-listening-ai-saved/);
assert.doesNotMatch(ai,/save_participants|queueCheckpoint|checkpointRequest|MediaRecorder/,
  'AI reconnect must stay scoped to analysis/research rather than checkpoint or capture ownership');

/* Backend checkpoint compatibility remains intact but is not reattached here. */
assert.match(checkpointApi,/STONEFELLOW_ARTIST_LISTENING_AI_MIN_WORDS_V236 = 120/);
assert.match(checkpointApi,/artist_listening_v236_checkpoint/);
assert.match(checkpointApi,/persisted_keys/);
assert.match(checkpointApi,/client_segment_key=\?/);
assert.match(checkpointApi,/status.*discarded/s);
assert.match(checkpointApi,/SELECT COALESCE\(MAX\(segment_index\),-1\)/);

console.log('ARTIST_LISTENING_REALTIME=PASS');
