<?php
declare(strict_types=1);
// Include the real shared production dependency graph. No DB or provider request.
require dirname(__DIR__).'/includes/phone-workspace-v1.php';
require dirname(__DIR__).'/includes/phone-browser-v1.php';
require dirname(__DIR__).'/includes/phone-tracking-chat-v1.php';
require dirname(__DIR__).'/includes/cognitive-runtime-v500.php';
require dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require dirname(__DIR__).'/includes/phone-workspace-ui-v1.php';
require dirname(__DIR__).'/includes/phone-recording-pipeline-v1.php';
foreach(['vp3_phone_chat_updates_v1','vp3_phone_workspace_chat_updates_v1','vp3_agent_chat_send_v2160','vp3_phone_browser_voice_v1','vp3_phone_workspace_runtime_v1','vp3_phone_recording_receive_v1'] as $name){
    if(!function_exists($name))throw new RuntimeException('Missing integrated phone function: '.$name);
}
$tracking=new ReflectionFunction('vp3_phone_chat_updates_v1');$workspace=new ReflectionFunction('vp3_phone_workspace_chat_updates_v1');
if(basename($tracking->getFileName())!=='phone-tracking-chat-v1.php'||basename($workspace->getFileName())!=='phone-workspace-v1.php')throw new RuntimeException('Browser calls must preserve the existing paired-phone tracking hook.');
echo 'PHONE_BROWSER_LOAD_V1=PASS (real Chat and phone dependency graph)'.PHP_EOL;
