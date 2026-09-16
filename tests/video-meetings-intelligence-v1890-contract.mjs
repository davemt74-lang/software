import fs from 'node:fs';
import path from 'node:path';

const root=process.cwd();
const read=(p)=>fs.readFileSync(path.join(root,p),'utf8');
const helper=read('includes/video-meetings-intelligence-hybrid-v1890.php');
const handoff=read('includes/video-meetings-intelligence-handoff-v1890.php');
const api=read('api/video-meeting-intelligence.php');
const ui=read('video-meetings-intelligence-v1820.js');
const base=read('includes/video-meetings-intelligence-v1820.php');
const home=read('homeserver-v1890/app/services/meeting_intelligence.py');
const remote=read('homeserver-v1890/app/services/meeting_intelligence_remote.py');

function must(source,needle,label){
  if(!source.includes(needle))throw new Error(`${label}: missing ${needle}`);
}
function mustNot(source,needle,label){
  if(source.includes(needle))throw new Error(`${label}: forbidden ${needle}`);
}

// Exact cross-repo protocol identity.
must(helper,"'meeting.intelligence.analyze'",'VP3 operation');
must(helper,"'vp3.meeting.intelligence.v1'",'VP3 contract');
must(home,'CONTRACT = "vp3.meeting.intelligence.v1"','HomeServer contract');
must(home,'OPERATION = "meeting.intelligence.analyze"','HomeServer operation');
must(remote,'meeting_intelligence.OPERATION','HomeServer remote operation registration');

// Private route must be an explicitly capability-gated HomeServer request. It
// must never be represented by the transcription capability or cloud fallback.
must(helper,'homeserver_capability_v033_registry','capability registry');
must(helper,'VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_OPERATION_V1890',( 'exact intelligence capability'));
must(helper,"'cloud_processing_allowed'=>false",'private cloud prohibition');
must(helper,"'requested_compute'=>'homeserver'",'private compute binding');
must(helper,'homeserver_vp3_remote_operation','HomeServer relay dispatch');
must(helper,"'explicit_cloud_route_not_authorized'",'explicit cloud fail closed');
mustNot(helper,"meeting.transcription.stream'",'must not substitute STT capability for intelligence');
must(home,'payload.get("cloud_processing_allowed") is not False','HomeServer cloud prohibition');
must(home,'payload.get("requested_compute")','HomeServer compute validation');
must(home,'"cloud_fallback": False','HomeServer no cloud fallback');

// Every private result is rebound to the exact meeting/transcript/mode/request
// before anything reaches VP3 storage.
for(const field of ['contract','operation','meeting','source_hash','mode','idempotency_key','route','compute_source']){
  must(helper,`$result['${field}']`, `response binding ${field}`);
}
must(helper,"$idempotencyKey='vp3-meeting-intelligence:'",'stable VP3 idempotency identity');
must(home,'expected = f"vp3-meeting-intelligence:{public_id}:{source_hash}"','stable HomeServer idempotency identity');
must(helper,"'sanitized_private_analysis'",'sanitized artifact type');
must(helper,'json_encode($sanitized','sanitized persistence');
mustNot(helper,'json_encode($result','raw HomeServer result must not persist');
must(helper,'LIMIT 501','segment cap sentinel');
must(helper,'if(count($rows)>500)','segment cap enforcement');
must(helper,'if($chars>120000)','transcript size cap');

// Existing canonical transcript + 18.2 intelligence state remain authoritative.
must(helper,'video_meeting_intelligence_source_v1820','canonical transcript source');
must(helper,'video_meeting_intelligence_record_analysis_v1820','canonical analysis state');
must(base,'video_meeting_intelligence_policy_v1820','canonical processing policy');

// API is organizer-only. HomeServer and cloud execution paths are mutually
// exclusive, and a verified cloud replacement removes only the exact stale
// HomeServer projection for that source hash.
must(api,'video_meeting_intelligence_owner_allowed_v1820','organizer boundary');
must(api,"$action==='run_hybrid_analysis'",'HomeServer API action');
must(api,'video_meeting_intelligence_run_homeserver_v1890','HomeServer execution');
must(api,"($route['route']??'')!=='cloud'||empty($route['ready'])",'cloud route authorization');
must(api,'DELETE FROM video_meeting_artifacts WHERE meeting_id=? AND app_id=? AND source_hash=?','exact private artifact replacement');
must(api,'VP3_VIDEO_MEETINGS_INTELLIGENCE_HOMESERVER_APP_V1890','private artifact identity');

// Browser route selection cannot send a private meeting to cloud AI.
must(ui,"hybrid.route==='homeserver'",'HomeServer UI route');
must(ui,"intelligence('run_hybrid_analysis'",'HomeServer UI action');
must(ui,"hybrid.route!=='cloud'||policy.cloud_ai_allowed!==true",'cloud UI authorization');
must(ui,"policy.requested_compute==='homeserver_only'",'HomeServer-only UI fail closed');
must(ui,'boot.transcriptionIntelligenceEndpoint','canonical cloud intelligence endpoint');

// Post-meeting publishing remains an explicit reviewed Agent Chat handoff and
// does not silently mutate CRM/tasks.
must(handoff,'video_meeting_intelligence_public_state_v1890','hybrid handoff state');
must(handoff,"$finalHash===''||!hash_equals($finalHash,$sourceHash)",'final review boundary');
must(handoff,"'skip_brain_archive'=>true",'Agent Chat feedback-loop boundary');
must(handoff,"'meeting_intelligence_route'=>$source",'handoff route attribution');
mustNot(handoff,'INSERT INTO crm','no silent CRM mutation');
mustNot(handoff,'INSERT INTO tasks','no silent task mutation');

console.log('Phase 18.9 hybrid Meeting Intelligence contract passed.');
