<?php
declare(strict_types=1);
$orbUser=current_user();
if(!$orbUser||!has_permission('chat.access',$orbUser)||!empty($GLOBALS['VP3_AGENT_ORB_RENDERED']))return;
$GLOBALS['VP3_AGENT_ORB_RENDERED']=true;
?>
<link rel="stylesheet" href="<?= e(url('/agent-orb.css?v=eyes-v1348r2-voice-v1351')) ?>">
<script>window.VP3_ORB_CONFIG=<?= json_encode(['ownerKey'=>(string)$orbUser['id'],'chatUrl'=>url('/chat.php'),'eyesEndpoint'=>url('/api/agent-eyes.php'),'tvEndpoint'=>url('/api/tv-watching-v1.php'),'participantsEndpoint'=>url('/api/studio-participants.php'),'csrf'=>csrf_token(),'agentVoiceEnabled'=>member_agent_voice_enabled($orbUser),'chatEndpoint'=>url('/api/chat.php'),'notificationsEndpoint'=>url('/api/chat-notifications-brain-v240.php'),'trainedVoiceUrl'=>url('/voice-trained-client.js?v=v1346'),'voiceFeatureUrl'=>url('/voice-profile-core.js?v=eyes-v1348'),'voiceScript'=>url('/premium-voice-v117.js?v=eyes-v1348'),'drawerScript'=>url('/chat-notifications-drawer-v240.js?v=eyes-v1348')],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(url('/agent-eyes-core.js?v=eyes-v1348')) ?>" defer></script>
<script src="<?= e(url('/agent-orb-adapter.js?v=eyes-v1348r2-voice-v1351')) ?>" defer></script>
<script src="<?= e(url('/agent-participant-continuity.js?v=eyes-v1348')) ?>" defer></script>
<script src="<?= e(url('/agent-orb.js?v=eyes-v1348r2-voice-v1351')) ?>" defer></script>
<script src="<?= e(url('/agent-eyes.js?v=eyes-v1348-voice-v1351')) ?>" defer></script>
<script src="<?= e(url('/agent-trained-voice.js?v=eyes-v1348')) ?>" defer></script>
<script src="<?= e(url('/agent-voice-guard.js?v=eyes-v1348-voice-v1351')) ?>" defer></script>

<script src="<?= e(url('/agent-tv-watching.js?v=eyes-v1348r1')) ?>" defer></script>

<script src="<?= e(url('/agent-orb-motion.js?v=voice-v1351')) ?>" defer></script>
<script src="<?= e(url('/agent-welcome.js?v=voice-v1351')) ?>" defer></script>

<script src="<?= e(url('/agent-response-window.js?v=voice-v1351')) ?>" defer></script>
