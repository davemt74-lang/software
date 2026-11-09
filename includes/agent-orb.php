<?php
declare(strict_types=1);
$orbUser=current_user();
if(!$orbUser||!has_permission('chat.access',$orbUser)||!empty($GLOBALS['VP3_AGENT_ORB_RENDERED']))return;
$GLOBALS['VP3_AGENT_ORB_RENDERED']=true;
require_once __DIR__.'/participant-updates-v1.php';
require_once __DIR__.'/agent-catch-up-v1.php';
require_once __DIR__.'/chat-settings-v237.php';
$orbAgentId=0;
try{
    if(db()&&function_exists('user_agent_system_schema_ready_v236')&&user_agent_system_schema_ready_v236(db())){
        $orbAgents=user_agents_list_v236(db(),(int)$orbUser['id'],true);
        $orbDefault=null;foreach($orbAgents as $candidate)if(!empty($candidate['is_default'])){$orbDefault=$candidate;break;}
        $orbAgentId=(int)(($orbDefault?:($orbAgents[0]??[]))['id']??0);
    }
}catch(Throwable $ignored){}
try{$participantUpdatesEnabled=db()?vp3_participant_settings_v1(db(),$orbUser)['enabled']:true;}catch(Throwable $e){$participantUpdatesEnabled=true;}
?>
<link rel="stylesheet" href="<?= e(url('/agent-orb.css?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-orb.css'),0,12))) ?>">
<link rel="stylesheet" href="<?= e(url('/agent-orb-particles.css?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-orb-particles.css'),0,12))) ?>">
<script>window.VP3_ORB_CONFIG=<?= json_encode(['ownerKey'=>(string)$orbUser['id'],'agentId'=>$orbAgentId,'shape'=>chat_settings_agent_orb_shape_v237(db(),(int)$orbUser['id']),'orbShapeReady'=>chat_settings_agent_orb_shape_schema_ready_v237(db()),'settingsEndpoint'=>url('/api/chat-settings-v237.php'),'participantUpdatesEnabled'=>$participantUpdatesEnabled,'chatUrl'=>url('/chat.php'),'eyesEndpoint'=>url('/api/agent-eyes.php'),'participantUpdatesEndpoint'=>url('/api/participant-updates-v1.php'),'tvEndpoint'=>url('/api/tv-watching-v1.php'),'screenEndpoint'=>url('/api/agent-screen-share-v1.php'),'screenInsightsEndpoint'=>url('/api/agent-screen-insights-v1.php'),'screenSessionsEndpoint'=>url('/api/agent-screen-sessions-v1.php'),'screenAudioEndpoint'=>url('/api/agent-screen-audio-v1.php'),'remindersEndpoint'=>url('/api/agent-reminders-v1.php'),'participantsEndpoint'=>url('/api/studio-participants.php'),'csrf'=>csrf_token(),'agentVoiceEnabled'=>member_agent_voice_enabled($orbUser),'chatEndpoint'=>url('/api/chat.php'),'notificationsEndpoint'=>url('/api/chat-notifications-brain-v240.php'),'topOfMindEndpoint'=>url('/api/agent-top-of-mind-v1.php'),'workingAttentionBinding'=>vp3_catch_up_page_binding_v1($orbUser),'trainedVoiceUrl'=>url('/voice-trained-client.js?v=v1346'),'voiceFeatureUrl'=>url('/voice-profile-core.js?v=eyes-v1348'),'voiceScript'=>url('/premium-voice-v117.js?v=eyes-v1348'),'drawerScript'=>url('/chat-notifications-drawer-v240.js?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/chat-notifications-drawer-v240.js'),0,12))],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(url('/agent-eyes-core.js?v=portrait-refresh-v1')) ?>" defer></script>
<link rel="stylesheet" href="<?= e(url('/chat-notifications-drawer-v240.css?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/chat-notifications-drawer-v240.css'),0,12))) ?>">
<link rel="stylesheet" href="<?= e(url('/agent-top-of-mind-v1.css?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-top-of-mind-v1.css'),0,12))) ?>">
<script src="<?= e(url('/agent-top-of-mind-v1.js?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-top-of-mind-v1.js'),0,12))) ?>" defer></script>
<script src="<?= e(url('/agent-orb-adapter.js?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-orb-adapter.js'),0,12))) ?>" defer></script>
<script src="<?= e(url('/agent-live-context-v1.js?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-live-context-v1.js'),0,12))) ?>" defer></script>
<link rel="stylesheet" href="<?= e(url('/participant-updates-v1.css?v=3')) ?>">
<script src="<?= e(url('/participant-updates-v1.js?v=2')) ?>" defer></script>
<script src="<?= e(url('/participant-recovery-v1.js?v=1')) ?>" defer></script>
<script src="<?= e(url('/agent-participant-continuity.js?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-participant-continuity.js'),0,12))) ?>" defer></script>
<script src="<?= e(url('/agent-orb.js?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-orb.js'),0,12))) ?>" defer></script>
<script src="<?= e(url('/agent-orb-particles.js?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-orb-particles.js'),0,12))) ?>" defer></script>
<script src="<?= e(url('/agent-eyes.js?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-eyes.js'), 0, 12))) ?>" defer></script>
<script src="<?= e(url('/agent-screen-review-v1.js?v=1')) ?>" defer></script>
<script src="<?= e(url('/agent-screen-watch-zone.js?v=2')) ?>" defer></script>
<script src="<?= e(url('/agent-screen-share.js?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-screen-share.js'),0,12))) ?>" defer></script>
<script src="<?= e(url('/agent-trained-voice.js?v=voice-learning-v1')) ?>" defer></script>
<script src="<?= e(url('/agent-voice-guard.js?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-voice-guard.js'),0,12))) ?>" defer></script>

<script src="<?= e(url('/agent-tv-watching.js?v=eyes-workbench-frame-v1')) ?>" defer></script>

<script src="<?= e(url('/agent-orb-motion.js?v=stoneward-status-v1')) ?>" defer></script>
<script src="<?= e(url('/agent-welcome.js?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/agent-welcome.js'),0,12))) ?>" defer></script>

<script src="<?= e(url('/agent-response-window.js?v=chat-greeting-v1')) ?>" defer></script>

<script src="<?= e(url('/agent-reminders.js?v=participant-updates-v1')) ?>" defer></script>

<link rel="stylesheet" href="<?= e(url('/chat-voice-enrollment-v1.css?v=voice-learning-v1')) ?>">
<script src="<?= e(url('/chat-voice-enrollment-v1.js?v=voice-learning-v1')) ?>" defer></script>

<script src="<?= e(url('/chat-participant-setup-v2.js?v=1')) ?>" defer></script>
