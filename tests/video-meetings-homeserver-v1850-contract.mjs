import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');
const exec = read('includes/video-meetings-homeserver-v1850.php');
const route = read('includes/video-meetings-homeserver-v1840.php');
const agent = read('includes/video-meetings-agent-v1800.php');
const worker = read('api/video-meeting-worker.php');
const ui = read('video-meetings-v1800.js');

// The concrete executor uses one already-recognized meeting-specific operation.
assert.ok(exec.includes("return 'meeting.transcription.stream';"));
assert.ok(exec.includes("'contract'=>'vp3.meeting.transcription.v1'"));
assert.ok(exec.includes("'idempotency_key'=>'vp3-meeting-transcription:'"));
assert.ok(exec.includes("homeserver_vp3_remote_operation($credentials['relay'],$operation,$payload,$credentials['home'])"));

// HomeServer receives a short-lived subscribe-only LiveKit token. It cannot
// publish media/data or administer the room.
assert.ok(exec.includes("'canPublish'=>false,'canSubscribe'=>true,'canPublishData'=>false"));
assert.ok(exec.includes("'room'=>(string)$meeting['room_name'],'roomJoin'=>true"));
assert.ok(!exec.includes("'roomAdmin'=>true"));
assert.ok(!exec.includes("'roomCreate'=>true"));

// Callback authentication is meeting + room + expiry scoped and derives from
// the existing worker secret without ever forwarding that global secret.
assert.ok(exec.includes("hash_hmac('sha256','vp3-video-meeting-homeserver-callback-v1850',$secret,true)"));
assert.ok(exec.includes("$publicId.'|'.$roomName.'|'.$expiresAt"));
assert.ok(exec.includes("return 'v1850.'.$expiresAt.'.'.$signature"));
assert.ok(exec.includes("'bearer_token'=>video_meeting_homeserver_callback_token_v1850"));
assert.ok(!exec.includes("'bearer_token'=>video_meeting_worker_secret_v1800"));
assert.ok(exec.includes("video_meeting_secure_external_url_v1801('/api/video-meeting-worker.php')"));
assert.ok(!exec.includes("video_meeting_absolute_url_v1800('/api/video-meeting-worker.php')"));
assert.ok(exec.includes("'source'=>'homeserver'"));
assert.ok(exec.includes('participant_identity, speaker_name, start_ms, end_ms'));
assert.ok(exec.includes('source="homeserver", source_key and is_final=true'));

// Readiness is not function-exists theater: v18.4 asks the executor whether the
// exact advertised operation is deployable before reporting HomeServer ready.
assert.ok(route.includes("video_meeting_homeserver_transcription_executor_available_v1850($meeting,$operation)"));
assert.ok(route.includes("__DIR__.'/video-meetings-homeserver-v1850.php'"));
assert.ok(route.includes("return ['meeting.transcription.stream','transcription.stream','transcription.start'];"));
assert.ok(exec.includes("if($operation!==video_meeting_homeserver_transcription_operation_v1850())return false"));
assert.ok(exec.includes("video_meeting_livekit_ready_v1800()"));
assert.ok(exec.includes("homeserver_agent_v018_credentials((int)($meeting['owner_user_id']??0))"));

// Organizer join dispatches the selected route. HomeServer failure never falls
// through into cloud STT when the privacy boundary selected private compute.
assert.ok(agent.includes("if($route==='homeserver'&&$status==='ready'"));
assert.ok(agent.includes('video_meeting_homeserver_transcription_execute_v1840'));
assert.ok(agent.includes("return ['ok'=>false,'dispatched'=>false,'reason'=>'homeserver_dispatch_failed']"));
assert.ok(agent.includes("if($route!=='cloud'||$status!=='ready')"));

// The canonical transcript endpoint accepts either the old server worker secret
// or the new scoped token, with HomeServer source binding and existing room/
// lifecycle checks retained.
assert.ok(worker.includes('video_meeting_homeserver_callback_verify_v1850($publicId,$roomName,$provided)'));
assert.ok(worker.includes("if($homeserverAuthorized&&strtolower(trim((string)($input['source']??'')))!=='homeserver')"));
assert.ok(worker.includes("hash_equals((string)$meeting['room_name'],$roomName)"));
assert.ok(worker.includes("$legacyClosed=['cancelled','processed']"));
assert.ok(worker.includes("array_merge($legacyClosed,['no_show'])"));
assert.ok(worker.includes("time()-$endedAt>300"));
assert.ok(worker.includes('video_meeting_transcription_append_v1800($pdo,$meeting,$input)'));

// A failed private dispatch must override the earlier readiness snapshot so
// transcript polling cannot paint the UI green again.
assert.ok(ui.includes("reason==='homeserver_dispatch_failed'"));
assert.ok(ui.includes("processingStatus={...(processingStatus||{}),route:'blocked',status:'required_unavailable'"));
assert.ok(ui.includes("reason==='homeserver_created'||reason==='homeserver_already_running'"));

console.log('Video Meetings Phase 18.5 HomeServer executor contract passed.');
