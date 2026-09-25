import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const vp3=read('includes/homeserver-vp3.php');
const shared=read('includes/homeserver-shared-agent-v210.php');
const routing=read('includes/homeserver-execution-routing-v220.php');
const bootstrap=read('includes/bootstrap.php');
const brain=read('includes/agent-brain-context-v142.php');
const notifications=read('includes/notifications.php');
const attentionApi=read('api/chat-notifications-brain-v240.php');
const cognitive=read('includes/cognitive-presentation-v510.php');
const cognitiveClient=read('chat-cognitive-presentation-v510.js');

const checks=[
 ['shared product release is 2.2',/VP3_HOMESERVER_RELEASE_VERSION = '2\.2'/.test(vp3)&&/VP3_HOMESERVER_SHARED_AGENT_VERSION='2\.2'/.test(shared)],
 ['connection transitions create connected and reconnected Agent updates',/homeserver_connection_update/.test(shared)&&/HomeServer connected/.test(shared)&&/HomeServer reconnected/.test(shared)],
 ['disconnect remains a priority Agent update with Cloud fallback wording',/homeserver_needs_attention/.test(shared)&&/continue with Cloud capabilities/.test(shared)&&/local data, models and HomeServer tools/.test(shared)],
 ['connection notifications are eligible for canonical Agent Chat presentation',/homeserver_connection_update/.test(notifications)&&/homeserver_needs_attention/.test(notifications)],
 ['informational connection update does not falsely require a user response',/agent_status_update/.test(attentionApi)&&/required'=>!\$presenceUpdate/.test(attentionApi)&&/response_timeout_ms'=>\$presenceUpdate\?0:10000/.test(attentionApi)],
 ['current cognitive presentation exposes immediate HomeServer presence',/vp3_cognitive_presentation_homeserver_presence_v220/.test(cognitive)&&/homeserver_presence/.test(cognitive)],
 ['current Agent Chat canvas renders the HomeServer presence update',/renderHomeServerPresence/.test(cognitiveClient)&&/homeserver-presence-update/.test(cognitiveClient)&&/HomeServer · /.test(cognitiveClient)],
 ['existing cognitive voice path can speak HomeServer status when Agent Voice is enabled',/notification_requires_attention/.test(cognitive)&&/voice_candidate/.test(cognitive)&&/maybeSpeak\(state\.voice_candidate/.test(cognitiveClient)],
 ['execution router is loaded after shared Agent fabric',bootstrap.indexOf('homeserver-shared-agent-v210.php')<bootstrap.indexOf('homeserver-execution-routing-v220.php')],
 ['execution router reads the canonical HomeServer capability registry',/homeserver_execution_v220_registry/.test(routing)&&/homeserver_https_v1300_remote_operation\(\$userId,'capabilities'/.test(routing)],
 ['execution routing covers compute files knowledge tools voice and devices',["agent_compute","local_files","local_knowledge","local_tools","local_voice","devices"].every(x=>routing.includes("'"+x+"'"))],
 ['execution router preserves explicit operation allowlist and existing authorities',/homeserver_execution_v220_can_route/.test(routing)&&/tools\.execute/.test(routing)&&/speech\.synthesize/.test(routing)&&/The requested HomeServer capability is unavailable/.test(routing)],
 ['Agent Brain receives live HomeServer execution availability',/homeserver_execution_v220_projection/.test(brain)&&/HomeServer execution availability/.test(brain)&&/Local files:/.test(brain)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`VP3 Cloud / HomeServer v2.2 presence + execution routing: ${checks.length}/${checks.length} passed`);
