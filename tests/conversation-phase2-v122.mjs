import fs from 'node:fs';

const read = path => fs.readFileSync(path,'utf8');
const runtime='conversation-integration-v131-20260826';
const chat=read('chat.php');
const chatVoice=read('chat-voice.js');
const stems=read('admin/stems.php');
const video=read('video-editor.php');
const stemAdapter=read('admin/stem-agent-v131.js');
const videoAdapter=read('editor-agent-v131.js');
const controller=read('conversation-voice-v122.js');
const lease=read('voice-lease-v122.js');
const barge=read('editor-voice-barge-v117.js');
const premium=read('premium-voice-v117.js');
const brain=read('includes/agent-brain-v122.php');
const retrieval=read('includes/agent-brain-context-v142.php');
const bootstrap=read('includes/bootstrap.php');
const activityApi=read('api/agent-activity-v94.php');
const phase1=read('tests/conversation-phase1-v121.mjs');
const ht=read('.htaccess');

const groups={
  '6 per-device acoustic calibration':[
    barge.includes('stonefellow:acoustic-profile:'),
    barge.includes('settings.deviceId||settings.groupId||track?.label'),
    barge.includes('profile.echoFloor'),
    barge.includes('sessionFloorSum/sessionFloorCount'),
    barge.includes('historical*1.75+.016'),
    barge.includes('saveProfile()'),
  ],
  '7 multi-tab microphone arbitration':[
    lease.includes('stonefellow:voice-lease:'),
    lease.includes('BroadcastChannel'),
    lease.includes('TTL_MS=6500'),
    lease.includes('RENEW_MS=1800'),
    lease.includes('if(!claim())'),
    lease.includes('leaseDenied:true'),
    lease.includes('leaseLost:true'),
    lease.includes('acquireMedia'),
    lease.includes('releaseMedia'),
    barge.includes('gate.acquireMedia()'),
    barge.includes('lease()?.releaseMedia()'),
    lease.includes("window.addEventListener('storage'"),
    lease.includes("window.addEventListener('pagehide'"),
    !barge.includes('document.write'),
  ],
  '8 explicit conversation state':[
    brain.includes("'current_surface'=>'chat'"),
    brain.includes("'current_project'=>''"),
    brain.includes("'current_goal'=>''"),
    brain.includes("'current_task'=>''"),
    brain.includes("'pending_question'=>''"),
    brain.includes("'last_agent_action'=>''"),
    brain.includes("'next_expected_action'=>''"),
    brain.includes("'conversation_id'=>max(0,$conversationId)"),
    brain.includes("'conversation_state'"),
    brain.includes('agent_brain_v122_activity_context'),
  ],
  '9 rolling conversation summaries':[
    brain.includes("'conversation_summary'"),
    brain.includes('ORDER BY id DESC LIMIT 28'),
    brain.includes('Recent decisions:'),
    brain.includes('Open/recurring commitments:'),
    brain.includes('Open question:'),
    brain.includes("'last_message_id'=>$lastId"),
    brain.includes("if((int)($state['last_message_id']??0)===(int)$latest['id'])return"),
    brain.includes('register_shutdown_function'),
  ],
  '10 hybrid Agent Brain retrieval':[
    retrieval.includes('agent_brain_v123_aliases'),
    retrieval.includes('agent_brain_v123_vector'),
    retrieval.includes('agent_brain_v123_cosine'),
    retrieval.includes('$hits*1.05'),
    retrieval.includes('$semantic*1.45'),
    retrieval.includes('$confidence*.82'),
    retrieval.includes('agent-brain:conversation-state'),
    retrieval.includes('agent-brain:rolling-summary'),
    retrieval.includes('agent-brain:patterns'),
    retrieval.includes('agent-brain:studio-history'),
    bootstrap.includes("agent-brain-context-v142.php") && !bootstrap.includes("agent-brain-context-v123.php") && !bootstrap.includes("agent-brain-context-v99.php"),
  ],
  'Earlier reliability guarantees remain intact':[
    fs.existsSync('conversation-voice-v121.js'),
    controller.includes('createSpeechStream'),
    premium.includes('FIRST_CHUNK_LIMIT = 180'),
    chat.includes('chat-voice.js') && !chat.includes('chat-stream-v121.php'),
    !chat.includes('chat-voice-v117.js'),
    read('team-chat-v109.css').includes('.sf-online-rail-v109{display:none!important}'),
    phase1.includes('PHASE1_CONVERSATION'),
  ],
  'one explicit voice owner per surface':[
    chat.includes(runtime),
    stems.includes(runtime),
    video.includes(runtime),
    chat.includes('chat-voice.js') && !chat.includes('conversation-voice-v122.js?v='),
    chatVoice.includes('directOwner:true') && chatVoice.includes('singleChatApi:true') && chatVoice.includes('sharedConversationController:false'),
    controller.includes('window.StonefellowConversationVoiceV122=api'),
    !controller.includes('StonefellowConversationVoiceV120'),
    !controller.includes('StonefellowConversationVoiceV121'),
    stemAdapter.includes('StonefellowConversationVoiceV122'),
    videoAdapter.includes('StonefellowConversationVoiceV122'),
    stems.includes("'admin/stem-agent-v114.js?v=voice-continuity-v114-20260825' => 'admin/stem-agent-v131.js?v='"),
    !video.includes('editor-agent-v114.js'),
  ],
  'Phase 2 integration/hardening':[
    !barge.includes('voice-lease-v122.js') && !barge.includes('conversation-voice-v122.js'),
    !chat.includes('voice-lease-v122.js?v=') && !chat.includes('conversation-voice-v122.js?v='),
    !chat.includes('voiceStreamEndpoint') && !chat.includes('chat-stream-v121.php'),
    [stems,video].every(source=>source.includes('voice-lease-v122.js')&&source.includes('conversation-voice-v122.js')),
    ht.includes('voice-lease-v122') && ht.includes('conversation-voice-v122'),
    activityApi.includes("$action==='voice_health'"),
    bootstrap.includes('agent-brain-v122.php'),
  ],
};

let passed=0,total=0;
const failed=[];
for(const [name,checks] of Object.entries(groups)){
  const ok=checks.every(Boolean);passed+=ok?1:0;total+=1;
  console.log(`${ok?'PASS':'FAIL'} ${name} (${checks.filter(Boolean).length}/${checks.length})`);
  if(!ok)failed.push(name);
}
console.log(`PHASE2_GROUPS=${passed}/${total}`);
if(failed.length)throw new Error(`Failed: ${failed.join(', ')}`);
