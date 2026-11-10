<?php
declare(strict_types=1);

$runtimeBuild = 'conversation-integration-v131-20260826';
$controlBuild = 'voice-learning-v1-20261101';
$controlBuild .= '-vrss-outcome-cards-s8b-20261103';
$controlBuild .= '-profile-doorbell-pa3-20261103';
$controlBuild .= '-profile-direct-calls-pa3c-20261104';
$controlBuild .= '-profile-attention-pa4b-' . substr(hash_file('sha256',__DIR__.'/chat.js'),0,12);
$controlBuild .= '-style-' . substr(hash_file('sha256', __DIR__ . '/chat.css'), 0, 12);
$controlBuild .= '-canvas-image-generation-v1-listening-continuation-v1-live-player-history-v1-audio-debug-v1-recognition-reliability-v1-participant-conversation-v2-20261102-participant-updates-20261102-navigation-routing-20261101-task-notes-20261101-screen-v1353-reminders-v1354-longfellow-r3-transcripts-s3';
$controlBuild .= '-eyes-cross-tab-v1';
$controlBuild .= '-connected-sites-v1-initiative-workbench-v1';
$premiumVoiceBuild = 'premium-voice-agent-routing-v244-20260922-provider-audit1' . '-voice-v1351';
$voiceAssetBuild = 'chat-voice-proactive-v244-20260922' . '-voice-v1351-speech-recovery-20261031-mic-startup-20261101';
$voiceCacheBuild = 'chat-voice-proactive-v244-20260922-response-relevance-20261023-composer-r3' . '-voice-v1351';
$voiceCacheBuild .= '-eyes-v1344-speech-recovery-20261031-mic-startup-20261101';
$voiceCacheBuild .= '-learn-my-voice-v1-quiet-abort-20261102-eyes-cross-tab-v1';
$voiceCacheBuild .= '-eyes-named-setups-v1';
$voiceCacheBuild .= '-quiet-chat-startup';
$voiceCacheBuild .= '-' . substr(hash_file('sha256', __DIR__ . '/chat-voice.js'), 0, 12);
$recordingUiBuild = 'chat-recording-results-v206-20260901';
$recordingPersistenceBuild = 'chat-recordings-v242-20260902';
$transcriptionCanvasBuild = 'transcription-intelligence-home-v308-20260907';
$transcriptionCanvasBuild .= '-transcripts-s3-close';
$mediaOverlayBuild = 'chat-media-overlays-source-light-20260905';
$agentOverlayBuild = 'agent-updates-hidden-v206-20260901';
$agentIdentityBuild = 'cloud-agent-onboarding-readiness-v110-20261001';
$profileActivityBuild = 'profile-activity-safety-pa1-20261103';
$headerUiBuild = 'live-wiring-20260903-3';
$teamChatAdminBuild = 'team-chat-bootstrap-v236-20260905';
$chatSettingsBuild = 'chat-settings-v239-canonical-20260905' . '-voice-v1352-chat-settings-v237-pa3c-assisted-phone-visitor-availability';
$chatSettingsBuild .= '-' . substr(hash_file('sha256', __DIR__ . '/chat-settings-v237.js'), 0, 12);
$notificationDrawerBuild = 'chat-notifications-recovery-20261016' . '-voice-v1351';
$notificationDrawerBuild .= '-profile-doorbell-pa3-20261103';
$activityBuild = 'agent-activity-v94-canonical-runtime-20260907';
$activityBuild .= '-' . substr(hash_file('sha256', __DIR__ . '/agent-activity-v94.js'), 0, 12);
$brainLearningBuild = 'brain-learning-history-v317-20260907-pr81-hotfix1';
$agentIntelligenceBuild = 'agent-chat-intelligence-v171-20260914';
$cognitivePresentationBuild = 'cognitive-presentation-footer-v2451-20260922';
$cognitiveCardsBuild = 'cognitive-cards-v520-20260918';
$cognitiveLearningBuild = 'cognitive-learning-v540-20260918';
$cognitivePlanningBuild = 'cognitive-planning-v550-20260918';
$cognitiveOrchestrationBuild = 'cognitive-orchestration-v560-20260919';
$cognitiveMemoryBuild = 'cognitive-memory-v570-20260919';
$cognitiveContextBuild = 'cognitive-context-v2420-20260922';
$cognitiveTurnBuild = 'cognitive-turn-v2430-20260922';
$cognitiveContinuityBuild = 'cognitive-continuity-v2440-20260922';
$cognitiveFollowthroughBuild = 'cognitive-followthrough-v2450-20260922';
$cognitiveSupervisionBuild = 'cognitive-supervision-v2460-20260922';
$cognitiveAutonomyBuild = 'cognitive-autonomy-v2470-20260922';
$cognitivePortfolioBuild = 'cognitive-portfolio-v2480-20260922';
$cognitiveForecastBuild = 'cognitive-forecast-v2490-20260922';
$cognitiveOptimizationBuild = 'cognitive-optimization-v2510-20260922';
$cognitiveResourceBudgetBuild = 'cognitive-resource-budget-v2520-20260922';
$cognitiveReplanningBuild = 'cognitive-replanning-v2530-20260922';
$cognitiveCommitmentProtectionBuild = 'cognitive-commitment-protection-v2540-20260922';
$cognitiveEconomicsBuild = 'cognitive-economics-v2550-20260923';
$cognitiveBudgetGovernanceBuild = 'cognitive-budget-governance-v2560-20260923';
$cognitiveValueRoiBuild = 'cognitive-value-roi-v2570-20260923';
$cognitiveDecisionCalibrationBuild = 'cognitive-decision-calibration-v2580-20260923';
$cognitiveCurrentStateBuild = 'cognitive-current-state-v2590-20260923';
$cognitivePresentationAssetBuild = $cognitivePresentationBuild . '-' . $cognitiveCurrentStateBuild . '-profile-priority-v1341-20261027-everyday-context-v1-greeting-preview-live-context';
$cognitivePresentationAssetBuild .= '-' . substr(hash('sha256',
    hash_file('sha256', __DIR__ . '/chat-cognitive-presentation-v510.js')
    . hash_file('sha256', __DIR__ . '/chat-cognitive-presentation-v510.css')
), 0, 12);
$notificationDrawerAssetBuild = $notificationDrawerBuild . '-' . $cognitiveContextBuild . '-' . $cognitiveTurnBuild . '-' . $cognitiveContinuityBuild . '-' . $cognitiveFollowthroughBuild . '-' . $cognitiveSupervisionBuild . '-' . $cognitiveAutonomyBuild . '-' . $cognitivePortfolioBuild . '-' . $cognitiveForecastBuild . '-' . $cognitiveOptimizationBuild . '-' . $cognitiveResourceBudgetBuild . '-' . $cognitiveReplanningBuild . '-' . $cognitiveCommitmentProtectionBuild . '-' . $cognitiveEconomicsBuild . '-' . $cognitiveBudgetGovernanceBuild . '-' . $cognitiveValueRoiBuild . '-' . $cognitiveDecisionCalibrationBuild . '-' . $cognitiveCurrentStateBuild . '-mission-notices-a5c8-20261011-profile-priority-v1341-20261027-eyes-v1342-activity-v1348r2-longfellow-r3';
$browserContextBuild = 'browser-context-v2130-20260919';
$notificationDrawerAssetBuild .= '-pa4b-' . substr(hash('sha256',hash_file('sha256',__DIR__.'/chat-notifications-drawer-v240.js').hash_file('sha256',__DIR__.'/chat-notifications-drawer-v240.css')),0,12);
$agentIdentityAssetBuild = $agentIdentityBuild . '-activation-v243';
$cognitiveCardsAssetBuild = $cognitiveCardsBuild . '-memory-v570';
$onboardingActivationAssetBuild = 'activation-v243';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Stonefellow-Runtime: ' . $runtimeBuild);
    header('X-Stonefellow-Chat-Controls: ' . $controlBuild);
    header('X-Stonefellow-Chat-Voice: ' . $voiceAssetBuild);
    header('X-Stonefellow-Chat-Voice-Feature: ' . $voiceCacheBuild);
    header('X-Stonefellow-Agent-UI: ' . $agentIdentityBuild);
    header('X-VP3-Agent-Intelligence: ' . $agentIntelligenceBuild);
    header('Permissions-Policy: microphone=(self), camera=(self)');
}

ob_start();
require __DIR__ . '/chat-legacy-v108.php';
$html = (string)ob_get_clean();

$html = preg_replace(
    '~<script[^>]+src="[^"]*/chat-(?:v100|v107|v108)\.js[^\"]*"[^>]*></script>~i',
    '',
    $html
) ?? $html;

$html = str_replace('chat.js?v=101', 'chat.js?v=' . $controlBuild, $html);
$html = str_replace('chat.css?v=206-source-light-20260905', 'chat.css?v=' . $controlBuild, $html);
$html = str_replace('agent-activity-v94.js?v=101', 'agent-activity-v94.js?v=' . $activityBuild, $html);

// One sidebar authority: render the shared member sidebar once and replace the
// complete legacy sidebar as a unit. Chat contributes only its recent-history
// rows and New Chat button behavior; navigation links come exclusively from
// includes/main-sidebar.php.
$mainSidebarUser = $user;
$mainSidebarActive = 'chat';
$mainSidebarUseNewChatButton = true;
$mainSidebarHistoryRows = isset($recent) && is_array($recent) ? $recent : [];
ob_start();
require __DIR__ . '/includes/main-sidebar.php';
$canonicalSidebarHtml = (string)ob_get_clean();
unset($mainSidebarUser, $mainSidebarActive, $mainSidebarUseNewChatButton, $mainSidebarHistoryRows);
$html = preg_replace(
    '~<aside class="chat-sidebar" id="chatSidebar">.*?</aside>~s',
    $canonicalSidebarHtml,
    $html,
    1
) ?? $html;

// The Create (+) feature is retained in the legacy source for later use but is
// not part of the current Agent Chat header. This decision is server-side so no
// browser runtime is responsible for hiding it.
$html = str_replace('id="chatCreateMenu"', 'id="chatCreateMenu" hidden', $html);

$agentFeatureReady = false;
$activeUserAgent = null;
$requestedAgentRaw = trim((string)($_GET['agent'] ?? ''));
$setupRequested = (string)($_GET['setup'] ?? '') === '1';
$explicitSystemAgent = strcasecmp($requestedAgentRaw, 'system') === 0;
$requestedAgentId = (!$explicitSystemAgent && ctype_digit($requestedAgentRaw))
    ? max(0, (int)$requestedAgentRaw)
    : 0;
$systemAgentName = 'STONEFELLOW';
$agentDisplayName = 'STONEFELLOW';
$agentInitialConversationId = 0;
$agentOnboarding = false;
$canonicalProfileUrl = '';
$pdoForAgent = db();
$profileWebmcpReturn=null;
$profileWebmcpReturnToken=trim((string)($_GET['profile_webmcp_return']??''));
if($profileWebmcpReturnToken!==''){
    require_once __DIR__.'/includes/profile-webmcp-continuity-v195.php';
    if(!headers_sent()){
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    try{
        $returnProfile=$pdoForAgent&&function_exists('profile_for_user')?profile_for_user($pdoForAgent,(int)$user['id'],false):null;
        if(is_array($returnProfile)){
            $profileWebmcpReturn=vp3_profile_webmcp_return_consume_v195($returnProfile,$user,$profileWebmcpReturnToken,$pdoForAgent);
            if(is_array($profileWebmcpReturn)){
                // The consumed server context selects its exact source Agent and conversation, including system/zero.
                $requestedAgentId=(int)$profileWebmcpReturn['agent_id'];
                $explicitSystemAgent=$requestedAgentId===0;
                $requestedAgentRaw=$explicitSystemAgent?'system':(string)$requestedAgentId;
                $_GET['agent']=$requestedAgentRaw;
                $_GET['conversation_id']=(int)$profileWebmcpReturn['conversation_id'];
            }
        }
    }catch(Throwable $e){
        error_log('Profile WebMCP return consume failed: '.$e->getMessage());
        $profileWebmcpReturn=null;
    }
}

try {
    $systemAgentName = system_agent_name();
    $agentDisplayName = $systemAgentName;
    $agentFeatureReady = $pdoForAgent && user_agent_system_schema_ready_v236($pdoForAgent);
    if ($agentFeatureReady) {
        if ($requestedAgentId > 0) {
            $candidate = user_agent_get_v236($pdoForAgent, (int)$user['id'], $requestedAgentId);
            if ($candidate && !empty($candidate['is_active'])) {
                $activeUserAgent = $candidate;
            } else {
                $requestedAgentId = 0;
            }
        } elseif (!$explicitSystemAgent && $requestedAgentRaw === '') {
            $ownedAgents = user_agents_list_v236($pdoForAgent, (int)$user['id'], true);
            if ($ownedAgents) {
                $preferred = null;
                foreach ($ownedAgents as $ownedAgent) {
                    if (!empty($ownedAgent['is_default'])) {
                        $preferred = $ownedAgent;
                        break;
                    }
                }
                $activeUserAgent = $preferred ?: $ownedAgents[0];
                $requestedAgentId = (int)$activeUserAgent['id'];
            }
        }

        if ($activeUserAgent) {
            $agentDisplayName = trim((string)$activeUserAgent['display_name']) ?: $systemAgentName;
            $stmt = $pdoForAgent->prepare('SELECT id FROM chat_conversations WHERE user_id=? AND user_agent_id=? ORDER BY updated_at DESC,id DESC LIMIT 1');
            $stmt->execute([(int)$user['id'], (int)$activeUserAgent['id']]);
        } else {
            $stmt = $pdoForAgent->prepare('SELECT id FROM chat_conversations WHERE user_id=? AND user_agent_id IS NULL ORDER BY updated_at DESC,id DESC LIMIT 1');
            $stmt->execute([(int)$user['id']]);
        }
        $agentInitialConversationId = (int)$stmt->fetchColumn();

        $requestedConversationId=max(0,(int)($_GET['conversation_id']??0));
        if($requestedConversationId>0){
            if($activeUserAgent){
                $requestedConversation=$pdoForAgent->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND user_agent_id=? LIMIT 1');
                $requestedConversation->execute([$requestedConversationId,(int)$user['id'],(int)$activeUserAgent['id']]);
            }else{
                $requestedConversation=$pdoForAgent->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND user_agent_id IS NULL LIMIT 1');
                $requestedConversation->execute([$requestedConversationId,(int)$user['id']]);
            }
            $validatedConversationId=(int)$requestedConversation->fetchColumn();
            if($validatedConversationId>0)$agentInitialConversationId=$validatedConversationId;
        }
        if(is_array($profileWebmcpReturn)&&(int)$profileWebmcpReturn['conversation_id']===0)$agentInitialConversationId=0;

        // Onboarding is a persistent Agent skill: an existing Agent identity
        // must not hide unfinished required setup or selected optional tasks.
        $agentOnboarding = $setupRequested || (
            !$explicitSystemAgent
            && !user_agent_onboarding_dismissed_v236($pdoForAgent, (int)$user['id'])
        );
    }
} catch (Throwable $e) {
    $agentFeatureReady = false;
    $activeUserAgent = null;
    $requestedAgentId = 0;
    $agentDisplayName = $systemAgentName;
    $agentInitialConversationId = (int)$chatInitialConversationId;
}

if(is_array($profileWebmcpReturn)&&((int)($activeUserAgent['id']??0)!==(int)$profileWebmcpReturn['agent_id']||(int)$agentInitialConversationId!==(int)$profileWebmcpReturn['conversation_id']))$profileWebmcpReturn=null;
if(is_array($profileWebmcpReturn)){
    $returnStatus=e((string)($profileWebmcpReturn['status_text']??'Profile action status updated.'));
    $returnPhase=e((string)($profileWebmcpReturn['phase']??'viewed'));
    $profileWebmcpReturnMarkup='<section class="chat-profile-webmcp-return" data-profile-webmcp-return-card role="status"><small>PROFILE ACTION · '.strtoupper($returnPhase).'</small><strong>'.$returnStatus.'</strong><span>Continue this conversation with your Agent for the next step.</span></section>'
        .'<script data-profile-webmcp-return-cleanup>(function(){try{var u=new URL(location.href);if(u.searchParams.has("profile_webmcp_return")){u.searchParams.delete("profile_webmcp_return");history.replaceState(null,"",u.pathname+u.search+u.hash);}}catch(e){}})();</script>';
    $html=str_replace('<form class="chat-composer" id="chatForm">',$profileWebmcpReturnMarkup.'<form class="chat-composer" id="chatForm">',$html);
}

$chatApiEndpoint = $agentFeatureReady
    ? url('/api/chat-v236.php') . ($activeUserAgent ? '?agent=' . (int)$activeUserAgent['id'] : '')
    : url('/api/chat.php');
if (!$agentFeatureReady) {
    $agentInitialConversationId = (int)$chatInitialConversationId;
}
$workingAttentionBinding = vp3_catch_up_page_binding_v1($user);

$agentChatBootstrap = '<script data-user-agent-chat-v236>(function(){"use strict";var cfg=window.STONEFELLOW_CHAT||{};cfg.endpoint='
    . json_encode($chatApiEndpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ';cfg.workingAttentionBinding=' . json_encode($workingAttentionBinding)
    . ';cfg.agentId=' . (int)($activeUserAgent['id'] ?? 0)
    . ';cfg.autoGreetEnabled=' . (chat_settings_agent_auto_greet_enabled_v237($pdoForAgent, $user) ? 'true' : 'false')
    . ';cfg.initialConversationId=' . (int)$agentInitialConversationId . ';window.STONEFELLOW_AGENT_IDENTITY_V236={'
    . 'displayName:' . json_encode($agentDisplayName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ',systemName:' . json_encode($systemAgentName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ',agentId:' . (int)($activeUserAgent['id'] ?? 0)
    . ',showOnboarding:' . ($agentFeatureReady && $agentOnboarding ? 'true' : 'false')
    . ',forceOnboarding:' . ($agentFeatureReady && $setupRequested ? 'true' : 'false')
    . ',agentVoiceEnabled:' . (member_agent_voice_enabled($user) ? 'true' : 'false')
    . ',chatSettingsEndpoint:' . json_encode(url('/api/chat-settings-v237.php'), JSON_UNESCAPED_SLASHES)
    . ',endpoint:' . json_encode(url('/api/user-agent-system-v236.php'), JSON_UNESCAPED_SLASHES)
    . ',chatBaseUrl:' . json_encode(url('/chat.php'), JSON_UNESCAPED_SLASHES)
    . ',accountUrl:' . json_encode(url('/account.php#agents-data'), JSON_UNESCAPED_SLASHES)
    . ',profileAgentEndpoint:' . json_encode(url('/api/profile-agent.php'), JSON_UNESCAPED_SLASHES)
    . ',profileAgentUrl:' . json_encode(url('/profile-agent.php'), JSON_UNESCAPED_SLASHES)
    . ',csrf:' . json_encode(csrf_token(), JSON_UNESCAPED_SLASHES)
    . '};})();</script>';

$voiceBootstrap = '<script data-chat-voice-pre>(function(){"use strict";var b=document.getElementById("chatVoiceButton");window.STONEFELLOW_CHAT_VOICE_BOOT={button:b,legacyDormant:true};if(b){b.id="chatVoiceButtonLegacyDormant";b.disabled=false;}})();</script>';
require_once __DIR__.'/includes/agent-connection-youtube-playback-v1.php';
$youtubePdo = db();
$youtubeConfig = [
    'endpoint'=>url('/api/chat-youtube-playback-v1.php'),
    'connections'=>url('/connections.php?app=youtube#connections-app-youtube'),
    'csrf'=>csrf_token(),
    'proactive'=>$youtubePdo && vp3_youtube_picks_ready_v1($youtubePdo)
        && (bool)vp3_youtube_picks_settings_v1($youtubePdo, (int)$user['id'])['proactive'],
    'captureKey'=>$youtubePdo && vp3_youtube_playback_ready_v1($youtubePdo)
        ? vp3_youtube_capture_key_v1($youtubePdo, (int)$user['id']) : '',
    'watchCapture'=>$youtubePdo && vp3_youtube_watch_ready_v1($youtubePdo)
        && vp3_youtube_watch_research_enabled_v1($youtubePdo, (int)$user['id']),
];
$youtubeChatRuntime = '<script>window.VP3_YOUTUBE_PLAYBACK_V1='
    .json_encode($youtubeConfig, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES).';</script>'
    . '<link rel="stylesheet" href="'.e(url('/chat-youtube-v1.css?v='.substr(hash_file('sha256',__DIR__.'/chat-youtube-v1.css'),0,12))).'">'
    . '<script src="'.e(url('/chat-youtube-v1.js?v='.substr(hash_file('sha256',__DIR__.'/chat-youtube-v1.js'),0,12))).'"></script>';
$html = preg_replace(
    '~(<script[^>]+src="[^"]*chat\.js\?v=[^"]+"[^>]*></script>)~i',
    $agentChatBootstrap . $voiceBootstrap . $youtubeChatRuntime . '$1',
    $html,
    1
) ?? $html;

$debugMarker = '<span id="chatRuntimeDebug" hidden data-chat-build="' . e($controlBuild) . '"></span>';
$welcomeAnchor = '<div id="chatWelcome" hidden></div>';
$liveContextHost = '<section id="chatLiveContext" aria-label="Agent check-in" hidden></section>';
$html = str_replace($welcomeAnchor, $debugMarker . $welcomeAnchor . $liveContextHost, $html);

// The orb opens Top of Mind. Keep the detailed Brief available to that shared pane
// without adding a second launcher to the composer or gating the cognitive runtime.

$cognitiveBriefMarkup = <<<'HTML'
<section class="chat-agent-brief-popover" id="chatAgentBriefPopover" hidden aria-label="Agent Brief">
  <header class="chat-agent-brief-head">
    <div><small>Agent Brief</small><strong>Your Agent status and priorities</strong></div>
    <button class="chat-agent-brief-close" type="button" data-agent-brief-close aria-label="Close Agent Brief">×</button>
  </header>
  <div class="chat-agent-brief-body" data-agent-brief-content aria-live="polite" aria-busy="true">
    <div class="chat-agent-brief-status"><span><i class="chat-agent-brief-dot"></i>Agent</span><small>Loading…</small></div>
  </div>
  <footer class="chat-agent-brief-footer">
    <button type="button" data-prompt="Catch me up" data-preserve-chat-draft>Catch me up</button>
    <button type="button" data-agent-brief-brain>Agent Brain</button>
    <button type="button" data-agent-brief-history>History</button>
    <button type="button" data-agent-brief-notifications>Notifications</button>
  </footer>
</section>
HTML;
$html = str_replace(
    '<form class="chat-composer" id="chatForm">',
    $cognitiveBriefMarkup . '<form class="chat-composer" id="chatForm">',
    $html
);

$hardening = '<style data-chat-overlay-removal-v206>.agent-update-overlay,.agent-updates-overlay,#chatRecordingsCanvas,.chat-recordings-canvas{display:none!important}</style>'
    . '<script data-chat-ui-hardening-v206>(function(){"use strict";var selector="#agentNextMovesCanvas,.agent-next-canvas-v97,.agent-next-moves,.agent-proactive-panel,.agent-update-overlay,.agent-updates-overlay,#chatRecordingsCanvas,.chat-recordings-canvas";var purge=function(){document.querySelectorAll(selector).forEach(function(el){el.remove();});};purge();var o=new MutationObserver(purge);o.observe(document.documentElement,{childList:true,subtree:true});window.addEventListener("pagehide",function(){o.disconnect();},{once:true});})();</script>';

$composerControls = '<style data-chat-controls-v142>'
    . '.chat-sidebar-sections .chat-history-label{padding:7px 10px 5px;}'
    . '.chat-sidebar-nav{gap:0;padding:0 1px 2px;}'
    . '.chat-sidebar-nav-link{min-height:28px;padding:3px 9px;line-height:1.05;}'
    . '.chat-sidebar-nav-link>span{height:18px;}'
    . '.chat-sidebar-nav-link strong{line-height:1.05;}'
    . '.chat-recording-card{display:grid;gap:9px;min-height:44px;padding:9px 10px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;color:inherit;}'
    . '.chat-recording-card:hover,.chat-recording-card:focus-within{border-color:#d1d5db;background:#f9fafb;}'
    . '.chat-recording-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;min-width:0;}'
    . '.chat-recording-card-head>span{display:grid;min-width:0;gap:3px;}'
    . '.chat-recording-card strong{overflow:hidden;color:#111827;font-size:.72rem;text-overflow:ellipsis;white-space:nowrap;}'
    . '.chat-recording-card small{color:#6b7280;font-size:.59rem;}'
    . '.chat-transcription-audio{display:block;width:100%;min-width:0;height:34px;}'
    . '.chat-profile-webmcp-return{display:grid;gap:4px;margin:0 auto 10px;max-width:760px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;box-shadow:0 6px 18px rgba(17,24,39,.05);}'
    . '.chat-profile-webmcp-return small{font-size:.58rem;color:#6b7280;letter-spacing:.06em;}'
    . '.chat-profile-webmcp-return strong{font-size:.76rem;color:#111827;}'
    . '.chat-profile-webmcp-return span{font-size:.66rem;color:#6b7280;}'
    . '.chat-topbar{position:relative;isolation:isolate;}'
    . '.chat-topbar::after{content:"";position:absolute;inset:0;pointer-events:none;opacity:0;z-index:0;transition:opacity .16s ease,background .16s ease,box-shadow .16s ease;}'
    . '.chat-topbar>*{position:relative;z-index:1;}'
    . 'body[data-stonefellow-agent-state="listening"] .chat-topbar::after{opacity:1;background:linear-gradient(90deg,rgba(88,166,255,.16),rgba(88,166,255,.045));box-shadow:inset 0 -3px 0 #58a6ff;}'
    . 'body[data-stonefellow-agent-state="processing"] .chat-topbar::after{opacity:1;background:linear-gradient(90deg,rgba(73,209,125,.16),rgba(73,209,125,.045));box-shadow:inset 0 -3px 0 #49d17d;}'
    . 'body[data-stonefellow-agent-state="speaking"] .chat-topbar::after{opacity:1;background:linear-gradient(90deg,rgba(88,166,255,.16),rgba(88,166,255,.045));box-shadow:inset 0 -3px 0 #58a6ff;}'
    . '</style>';

$railLayout = '<style data-team-rail-layout-v111>'
    . '.sf-online-rail-v109{top:var(--sf-chat-header-bottom,58px)!important;}'
    . 'body.sf-team-rail-active .chat-main{padding-right:0!important;}'
    . 'body.sf-team-rail-active .chat-thread{padding-right:68px!important;}'
    . 'body.sf-team-rail-active .chat-composer-shell{padding-right:68px!important;}'
    . '@media(max-width:760px){.sf-online-rail-v109{display:none!important;}body.sf-team-rail-active .chat-main{padding-right:0!important;}body.sf-team-rail-active .chat-thread{padding-right:0!important;}body.sf-team-rail-active .chat-composer-shell{padding-right:0!important;}}'
    . '</style>'
    . '<script data-team-rail-anchor-v111>(function(){var setTop=function(){var h=document.querySelector(".chat-topbar");if(!h)return;document.documentElement.style.setProperty("--sf-chat-header-bottom",Math.ceil(h.getBoundingClientRect().bottom)+"px");};setTop();window.addEventListener("resize",setTop,{passive:true});})();</script>';

$headerUiRuntime = '<link rel="stylesheet" data-chat-header-ui-server href="' . e(url('/chat-header-ui.css?v=' . $headerUiBuild)) . '">';
$agentIntelligenceRuntime = '';
$mediaOverlayRuntime = '<link rel="stylesheet" data-chat-media-overlays href="' . e(url('/chat-media-overlays.css?v=' . $mediaOverlayBuild)) . '">';

$recordingLibraryRuntime = has_permission('artist_listening.access', $user)
    ? '<script>window.STONEFELLOW_CHAT_RECORDINGS_V242_CONFIG={persistEndpoint:'
        . json_encode(url('/api/chat-recordings-v242.php'), JSON_UNESCAPED_SLASHES)
        . ',libraryEndpoint:' . json_encode(url('/api/artist-recordings-v198.php'), JSON_UNESCAPED_SLASHES)
        . ',csrf:' . json_encode(csrf_token(), JSON_UNESCAPED_SLASHES)
        . '};window.STONEFELLOW_RECORDINGS_V198_CONFIG={endpoint:'
        . json_encode(url('/api/artist-recordings-v198.php'), JSON_UNESCAPED_SLASHES)
        . ',csrf:' . json_encode(csrf_token(), JSON_UNESCAPED_SLASHES)
        . ',artistListeningUrl:' . json_encode(url('/artist-listening.php'), JSON_UNESCAPED_SLASHES)
        . ',listeningEndpoint:' . json_encode(url('/api/artist-listening.php'), JSON_UNESCAPED_SLASHES)
        . ',intelligenceEndpoint:' . json_encode(url('/api/artist-listening-intelligence-v300.php'), JSON_UNESCAPED_SLASHES)
        . ',persistEndpoint:' . json_encode(url('/api/chat-recordings-v242.php'), JSON_UNESCAPED_SLASHES)
        . '};</script>'
        . '<link rel="stylesheet" data-chat-transcription-canvas href="' . e(url('/chat-transcription-canvas.css?v=' . $transcriptionCanvasBuild)) . '">'
        . '<script data-chat-recordings-v242 data-recording-persistence-build="' . e($recordingPersistenceBuild) . '" src="' . e(url('/chat-recordings-v242.js?v=' . $recordingPersistenceBuild)) . '"></script>'
        . '<script data-artist-recordings-v198 data-recording-ui-build="' . e($recordingUiBuild) . '" src="' . e(url('/artist-listening-recordings.js?v=' . $recordingUiBuild)) . '"></script>'
        . '<script data-chat-transcription-canvas data-transcription-canvas-build="' . e($transcriptionCanvasBuild) . '" src="' . e(url('/chat-transcription-canvas.js?v=' . $transcriptionCanvasBuild)) . '"></script>'
        . '<link rel="stylesheet" href="' . e(url('/chat-homeserver-transcription-import.css?v=1')) . '">'
        . '<script>window.STONEFELLOW_HS_TRANSCRIPTION_IMPORT={endpoint:' .
          json_encode(url('/api/homeserver-transcription-import-v1.php'),JSON_UNESCAPED_SLASHES) .
          ',csrf:' . json_encode(csrf_token(),JSON_UNESCAPED_SLASHES) . '};</script>'
        . '<script src="' . e(url('/chat-homeserver-transcription-import.js?v=interactive-transfer-section7-20261004')) . '"></script>'
    : '';

$voiceConfig = '<script data-chat-voice-config>window.STONEFELLOW_AGENT_CONTEXT={userId:' . (int)$user['id'] . ',surface:"chat",trackId:0,projectId:0,conversationId:' . (int)$agentInitialConversationId . ',taskTitle:"Agent Chat",taskKey:"chat",csrf:' . json_encode(csrf_token()) . ',proactiveEndpoint:' . json_encode(url('/api/agent-proactive-v93.php')) . '};</script>';

$agentIdentityRuntime = $agentFeatureReady
    ? '<link rel="stylesheet" data-chat-agent-identity-v236 href="' . e(url('/chat-agent-identity-v236.css?v=' . $agentIdentityAssetBuild)) . '">'
        . '<script data-chat-agent-identity-v236 src="' . e(url('/chat-agent-identity-v236.js?v=' . $agentIdentityAssetBuild)) . '"></script>'
    : '';

$profileActivityRuntime = $agentFeatureReady && $pdoForAgent && profile_agent_schema_ready($pdoForAgent)
    ? '<link rel="stylesheet" data-profile-activity-chat href="' . e(url('/profile-activity-chat.css?v=' . $profileActivityBuild)) . '">'
        . '<script data-profile-activity-chat src="' . e(url('/profile-activity-chat.js?v=' . $profileActivityBuild)) . '"></script>'
    : '';

$profileDoorbellBuild='profile-doorbell-pa3-'.substr(hash('sha256',hash_file('sha256',__DIR__.'/profile-visitor-doorbell-pa3.js').hash_file('sha256',__DIR__.'/profile-visitor-doorbell-pa3.css')),0,12);
$profileCallsBuild='profile-calls-pa3c-'.substr(hash('sha256',hash_file('sha256',__DIR__.'/profile-calls-pa3c.js').hash_file('sha256',__DIR__.'/profile-calls-pa3c.css')),0,12);
$profileCallActivityBuild='profile-call-activity-pa3c-'.substr(hash('sha256',hash_file('sha256',__DIR__.'/profile-call-activity-pa3c.js').hash_file('sha256',__DIR__.'/profile-call-activity-pa3c.css')),0,12);
$profileCallsRuntime=$profileActivityRuntime!==''
    ? '<link rel="stylesheet" data-profile-calls-pa3c href="'.e(url('/profile-calls-pa3c.css?v='.$profileCallsBuild)).'">'
        .'<link rel="stylesheet" href="'.e(url('/profile-call-activity-pa3c.css?v='.$profileCallActivityBuild)).'">'
        .'<div id="profileCallActivityPa3c" data-api="'.e(url('/api/profile-call-activity-pa3c.php')).'" data-csrf="'.e(csrf_token()).'" hidden></div>'
        .'<script src="'.e(url('/profile-call-activity-pa3c.js?v='.$profileCallActivityBuild)).'"></script>'
        .'<script>window.VP3_PROFILE_CALLS_PA3C='.json_encode([
            'role'=>'owner','endpoint'=>url('/api/profile-calls-pa3c.php'),'csrf'=>csrf_token(),'userId'=>(int)$user['id'],
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';</script>'
        .'<script data-profile-calls-pa3c src="'.e(url('/profile-calls-pa3c.js?v='.$profileCallsBuild)).'"></script>'
    : '';
$profileDoorbellRuntime=$profileActivityRuntime!==''
    ? '<link rel="stylesheet" data-profile-doorbell-pa3 href="'.e(url('/profile-visitor-doorbell-pa3.css?v='.$profileDoorbellBuild)).'">'
        .'<script>window.VP3_PROFILE_DOORBELL_PA3='.json_encode([
            'endpoint'=>url('/api/chat-notifications-brain-v240.php'),
            'ownerEndpoint'=>url('/api/profile-agent.php'),
            'csrf'=>csrf_token(),'userId'=>(int)$user['id'],
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';</script>'
        .'<script data-profile-doorbell-pa3 src="'.e(url('/profile-visitor-doorbell-pa3.js?v='.$profileDoorbellBuild)).'"></script>'
    : '';

$profileSpeechBuild='profile-speech-pa4a-'.substr(hash('sha256',hash_file('sha256',__DIR__.'/profile-speech-pa4a.js').hash_file('sha256',__DIR__.'/profile-speech-pa4a.css')),0,12);
$profileSpeechRuntime=$profileActivityRuntime!==''
    ? '<link rel="stylesheet" data-profile-speech-pa4a href="'.e(url('/profile-speech-pa4a.css?v='.$profileSpeechBuild)).'">'
        .'<script>window.VP3_PROFILE_SPEECH_PA4A='.json_encode([
            'endpoint'=>url('/api/profile-speech-pa4a.php'),'csrf'=>csrf_token(),'userId'=>(int)$user['id'],
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';</script>'
        .'<script data-profile-speech-pa4a src="'.e(url('/profile-speech-pa4a.js?v='.$profileSpeechBuild)).'"></script>'
    : '';

$chatSettingsRuntime = '<link rel="stylesheet" data-chat-settings-canonical href="' . e(url('/chat-settings-v237.css?v=' . $chatSettingsBuild)) . '">'
    . '<script data-chat-settings-config>window.STONEFELLOW_CHAT_SETTINGS='
    . json_encode([
        'endpoint'=>url('/api/chat-settings-v237.php'),
        'csrf'=>csrf_token(),
        'build'=>$chatSettingsBuild,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ';</script>'
    . '<script data-chat-settings-canonical src="' . e(url('/chat-settings-v237.js?v=' . $chatSettingsBuild)) . '"></script>';

$cognitiveCardsRuntime = '<link rel="stylesheet" data-cognitive-cards-v520 href="' . e(url('/chat-cognitive-cards-v520.css?v=' . $cognitiveCardsAssetBuild . '-' . $onboardingActivationAssetBuild)) . '">'
    . '<script data-cognitive-cards-config>window.VP3_COGNITIVE_CARDS_V520='
    . json_encode([
        'endpoint'=>url('/api/cognitive-cards-v520.php'),
        'csrf'=>csrf_token(),
        'agentId'=>(int)($activeUserAgent['id'] ?? 0),
        'maxBatch'=>20,
        'build'=>$cognitiveCardsBuild,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ';</script>'
    . '<script data-cognitive-cards-v520 src="' . e(url('/chat-cognitive-cards-v520.js?v=' . $cognitiveCardsAssetBuild . '-' . $onboardingActivationAssetBuild)) . '"></script>';

$cognitiveCardsRuntime .= '<link rel="stylesheet" href="'.e(url('/chat-specialist-rail-v1.css?v='.substr(hash_file('sha256',__DIR__.'/chat-specialist-rail-v1.css'),0,12))).'">'
    . '<script src="'.e(url('/chat-specialist-rail-v1.js?v='.substr(hash_file('sha256',__DIR__.'/chat-specialist-rail-v1.js'),0,12))).'"></script>'
    . '<link rel="stylesheet" href="'.e(url('/chat-canvas-media-v1.css?v='.substr(hash_file('sha256',__DIR__.'/chat-canvas-media-v1.css'),0,12))).'">'
    . '<script>window.VP3_CANVAS_MEDIA_V1='.json_encode(['endpoint'=>url('/api/chat-canvas-media-v1.php'),'csrf'=>csrf_token(),'userId'=>(int)$user['id'],'agentId'=>(int)($activeUserAgent['id']??0)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';</script>'
    . '<script src="'.e(url('/chat-canvas-media-v1.js?v='.substr(hash_file('sha256',__DIR__.'/chat-canvas-media-v1.js'),0,12))).'"></script>';
if ($youtubePdo && agent_connection_youtube_enabled_v1()
    && agent_connection_youtube_consent_v1($youtubePdo,(int)$user['id'])
    && (vp3_youtube_research_enabled_v1($youtubePdo,(int)$user['id'],'topic')
        || vp3_youtube_research_enabled_v1($youtubePdo,(int)$user['id'],'artist'))
    && personal_capability_has_v242('agent_brain.access',$user) && personal_knowledge_available($user)) {
    $cognitiveCardsRuntime .= '<link rel="stylesheet" href="'.e(url('/chat-research-specialist-v1.css?v='.substr(hash_file('sha256',__DIR__.'/chat-research-specialist-v1.css'),0,12))).'">'
        . '<script>window.VP3_CHAT_RESEARCH_SPECIALIST_V1='.json_encode([
            'endpoint'=>url('/api/initiative-research-status-v1.php'),
            'ownerKey'=>(string)$user['id'],
            'agentId'=>(int)($activeUserAgent['id']??0),
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';</script>'
        . '<script src="'.e(url('/chat-research-specialist-v1.js?v='.substr(hash_file('sha256',__DIR__.'/chat-research-specialist-v1.js'),0,12))).'" defer></script>';
}
$initiativeWorkbenchRuntime = '<link rel="stylesheet" href="'.e(url('/chat-initiative-workbench-v1.css?v='.substr(hash_file('sha256',__DIR__.'/chat-initiative-workbench-v1.css'),0,12))).'">'
    . '<script>window.VP3_INITIATIVE_WORKBENCH='.json_encode([
        'endpoint'=>url('/api/initiative-workbench-v1.php'),'csrf'=>csrf_token(),
        'mediaEndpoint'=>url('/api/media-library-v86.php'),
        'ownerKey'=>(string)$user['id'],'agentId'=>(int)($activeUserAgent['id']??0),
    ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES).';</script>'
    . '<script src="'.e(url('/chat-marketing-creative-render-v1.js?v='.substr(hash_file('sha256',__DIR__.'/chat-marketing-creative-render-v1.js'),0,12))).'" defer></script>'
    . '<script src="'.e(url('/chat-marketing-canvas-v1.js?v='.substr(hash_file('sha256',__DIR__.'/chat-marketing-canvas-v1.js'),0,12))).'" defer></script>'
    . '<script src="'.e(url('/chat-initiative-workbench-v1.js?v='.substr(hash_file('sha256',__DIR__.'/chat-initiative-workbench-v1.js'),0,12))).'" defer></script>';
$initiativeWorkbenchRuntime .= '<link rel="stylesheet" href="'.e(url('/chat-email-v1.css?v='.substr(hash_file('sha256',__DIR__.'/chat-email-v1.css'),0,12))).'">'
    . '<script>window.VP3_CHAT_EMAIL_V1='.json_encode([
        'endpoint'=>url('/api/chat-email-v1.php'),'csrf'=>csrf_token(),
        'connectionsUrl'=>url('/connections.php'),
        'githubWorkflow'=>['endpoint'=>url('/connection-gmail-github.php')],
        'ownerKey'=>(string)$user['id'],'agentId'=>(int)($activeUserAgent['id']??0),
    ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES).';</script>'
    . '<script src="'.e(url('/chat-email-v1.js?v='.substr(hash_file('sha256',__DIR__.'/chat-email-v1.js'),0,12))).'" defer></script>';
if (has_permission('account.access',$user)) {
    $initiativeWorkbenchRuntime .= '<script>window.VP3_GMAIL_INBOX_INITIAL_V1='.json_encode([
        'endpoint'=>url('/api/gmail-inbox-initial-v1.php'),'csrf'=>csrf_token(),
        'ownerKey'=>(string)$user['id'],'agentId'=>(int)($activeUserAgent['id']??0),
        'connectionsUrl'=>url('/connections.php?app=gmail#gmail-inbox-review'),'chatUrl'=>url('/chat.php'),
    ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES).';</script>'
    . '<script src="'.e(url('/gmail-inbox-initial-v1.js?v='.substr(hash_file('sha256',__DIR__.'/gmail-inbox-initial-v1.js'),0,12))).'" defer></script>';
}
if (user_has_role('admin',$user)) {
    $initiativeWorkbenchRuntime .= '<link rel="stylesheet" href="'.e(url('/chat-agent-development-v1.css?v='.substr(hash_file('sha256',__DIR__.'/chat-agent-development-v1.css'),0,12))).'">'
        . '<script>window.VP3_CHAT_DEVELOPMENT_V1='.json_encode([
            'endpoint'=>url('/api/agent-development-chat-v1.php'), 'csrf'=>csrf_token(),
            'ownerKey'=>(string)$user['id'], 'agentId'=>(int)($activeUserAgent['id']??0),
            'revisionsUrl'=>url('/admin/agent-development.php'),
        ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES).';</script>'
        . '<script src="'.e(url('/chat-agent-development-v1.js?v='.substr(hash_file('sha256',__DIR__.'/chat-agent-development-v1.js'),0,12))).'" defer></script>';
}
$initiativeWorkbenchRuntime .= '<link rel="stylesheet" href="'.e(url('/chat-message-unread-v1.css?v='.substr(hash_file('sha256',__DIR__.'/chat-message-unread-v1.css'),0,12))).'">'
    . '<script>window.VP3_CHAT_MESSAGE_UNREAD_V1='.json_encode([
        'endpoint'=>url('/api/agent-chat-read-v1.php'),
    ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES).';</script>'
    . '<script src="'.e(url('/chat-message-unread-v1.js?v='.substr(hash_file('sha256',__DIR__.'/chat-message-unread-v1.js'),0,12))).'" defer></script>';
$cognitiveCardsRuntime .= '<link rel="stylesheet" href="'.e(url('/chat-task-notes-v1.css?v=20261102-shortcut-free')).'">'
    . '<script>window.VP3_TASK_NOTES_V1='.json_encode(['endpoint'=>url('/api/task-notes-v1.php'),'csrf'=>csrf_token(),'userId'=>(int)$user['id'],'agentId'=>(int)($activeUserAgent['id']??0)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';</script>'
    . '<script src="'.e(url('/chat-task-notes-v1.js?v=voice-managed-lists-v2')).'"></script>';

$cognitiveCardsRuntime .= '<link rel="stylesheet" href="'.e(url('/chat-screen-insights-v1.css?v=1')).'">'
    . '<script>window.VP3_SCREEN_INSIGHTS='.json_encode(['endpoint'=>url('/api/agent-screen-insights-v1.php'),'ownerKey'=>(string)$user['id'],'agentId'=>(int)($activeUserAgent['id']??0),'csrf'=>csrf_token()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES).';</script>'
    . '<script src="'.e(url('/chat-screen-insights-v1.js?v=1')).'"></script>';

$cognitiveOrchestrationRuntime = '<script data-cognitive-orchestration-config>window.VP3_COGNITIVE_ORCHESTRATION_V560='
    . json_encode([
        'endpoint'=>url('/api/cognitive-orchestration-v560.php'),
        'csrf'=>csrf_token(),
        'agentId'=>(int)($activeUserAgent['id'] ?? 0),
        'build'=>$cognitiveOrchestrationBuild,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ';</script>'
    . '<script data-cognitive-orchestration-v560 src="' . e(url('/chat-cognitive-orchestration-v560.js?v=' . $cognitiveOrchestrationBuild)) . '"></script>';

$cognitivePresentationPre = '<link rel="stylesheet" data-cognitive-presentation-v510 href="' . e(url('/chat-cognitive-presentation-v510.css?v=' . $cognitivePresentationAssetBuild)) . '">'
    . '<script data-cognitive-presentation-config>window.VP3_COGNITIVE_PRESENTATION_V510='
    . json_encode([
        'endpoint'=>url('/api/cognitive-presentation-v510.php'),
        'csrf'=>csrf_token(),
        'agentId'=>(int)($activeUserAgent['id'] ?? 0),
        'workingAttentionBinding'=>$workingAttentionBinding,
        'greetingPreview'=>($_GET['greeting_preview']??'')==='1',
        'liveContext'=>true,
        'initiativeRuntime'=>true,
        'pollSeconds'=>30,
        'ownsAttention'=>true,
        'ownsBrainPresentation'=>true,
        'build'=>$cognitivePresentationBuild,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ';</script>';
$cognitivePresentationPost = '<script data-initiative-voice-v1 src="' . e(url('/chat-initiative-voice-v1.js?v=' . substr(hash_file('sha256',__DIR__.'/chat-initiative-voice-v1.js'),0,12))) . '"></script>'
    . '<script data-cognitive-presentation-v510 src="' . e(url('/chat-cognitive-presentation-v510.js?v=' . $cognitivePresentationAssetBuild)) . '"></script>';

$agentTeamsA3Build = 'agent-teams-' . substr(hash('sha256', (string)hash_file('sha256', __DIR__.'/chat-agent-teams-a3.js') . (string)hash_file('sha256', __DIR__.'/chat-agent-teams-a3.css')), 0, 12);
$agentTeamsA3Runtime = '<link rel="stylesheet" data-agent-teams-a3 href="' . e(url('/chat-agent-teams-a3.css?v=' . $agentTeamsA3Build)) . '">'
    . '<script data-agent-teams-config-a3>window.VP3_AGENT_TEAMS_A3='
    . json_encode(['endpoint'=>url('/api/agent-missions-cloud-v1.php'), 'csrf'=>csrf_token(), 'version'=>'A3', 'knowledgeUrl'=>url('/knowledge.php'), 'openSourceCatalog'=>(($_GET['mission_sources']??'')==='1')], JSON_UNESCAPED_SLASHES)
    . ';</script>'
    . '<script data-agent-teams-a3 src="' . e(url('/chat-agent-teams-a3.js?v=' . $agentTeamsA3Build)) . '" defer></script>'
    . '<script>window.VP3_CHAT_RESEARCH={endpoint:' . json_encode(url('/api/chat-browser-research-v1.php'),JSON_UNESCAPED_SLASHES) . ',csrf:' . json_encode(csrf_token()) . '};</script>'
    . '<link rel="stylesheet" href="' . e(url('/chat-browser-research-v1.css?v=research-phase2')) . '">'
    . '<script src="' . e(url('/chat-browser-research-v1.js?v=research-r3')) . '" defer></script>'
    . '<script>window.VP3_PROJECT_KNOWLEDGE={endpoint:' . json_encode(url('/api/project-knowledge-r5.php')) . ',csrf:' . json_encode(csrf_token()) . '};</script>'
    . '<script src="' . e(url('/project-knowledge-r5.js?v=r5')) . '" defer></script>'
    . '<script src="' . e(url('/project-knowledge-r6.js?v=r6-context-ownership-20261017')) . '" defer></script>'
    . '<script src="' . e(url('/project-knowledge-r7.js?v=r7')) . '" defer></script>'
    . '<script src="' . e(url('/knowledge-actions-r8.js?v=r9-action-result-ownership-20261018')) . '" defer></script>';

$browserContextRuntime = '<link rel="stylesheet" data-browser-context-v2130 href="' . e(url('/chat-browser-context-v2130.css?v=' . $browserContextBuild)) . '">'
    . '<script data-browser-context-v2130 src="' . e(url('/chat-browser-context-v2130.js?v=' . $browserContextBuild)) . '"></script>';

$topOfMindBuild=substr(hash('sha256',hash_file('sha256',__DIR__.'/agent-top-of-mind-v1.js').hash_file('sha256',__DIR__.'/agent-top-of-mind-v1.css')),0,12);
$notificationDrawerRuntime = '<link rel="stylesheet" href="' . e(url('/agent-top-of-mind-v1.css?v='.$topOfMindBuild)) . '">'
    . '<script src="' . e(url('/agent-top-of-mind-v1.js?v='.$topOfMindBuild)) . '" defer></script>'
    . '<link rel="stylesheet" data-chat-notification-drawer href="' . e(url('/chat-notifications-drawer-v240.css?v=' . $notificationDrawerAssetBuild)) . '">'
    . '<link rel="stylesheet" data-profile-attention-pa4b href="' . e(url('/profile-attention-pa4b.css?v=' . substr(hash_file('sha256',__DIR__.'/profile-attention-pa4b.css'),0,12))) . '">'
    . '<link rel="stylesheet" data-brain-learning-history-v317 href="' . e(url('/chat-brain-learning-history-v317.css?v=' . $brainLearningBuild)) . '">'
    . '<script>window.PROFILE_ATTENTION_PA4B_CONFIG=' . json_encode([
        'endpoint'=>url('/api/chat-notifications-brain-v240.php'),
        'csrfToken'=>csrf_token(),
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>'
    . '<script data-profile-attention-pa4b src="' . e(url('/profile-attention-pa4b.js?v=' . substr(hash_file('sha256',__DIR__.'/profile-attention-pa4b.js'),0,12))) . '"></script>'
    . '<script data-chat-notification-drawer-config>window.STONEFELLOW_NOTIFICATION_DRAWER='
    . json_encode([
        'endpoint'=>url('/api/chat-notifications-brain-v240.php'),
        'topOfMindEndpoint'=>url('/api/agent-top-of-mind-v1.php'),
        'csrf'=>csrf_token(),
        'build'=>$notificationDrawerBuild,
        'agentVoiceEnabled'=>member_agent_voice_enabled($user),
        'agentId'=>(int)($activeUserAgent['id'] ?? 0),
        'userId'=>(int)$user['id'],
        'workingAttentionBinding'=>$workingAttentionBinding,
        'contextBuild'=>$cognitiveContextBuild,
        'turnBuild'=>$cognitiveTurnBuild,
        'continuityBuild'=>$cognitiveContinuityBuild,
        'followthroughBuild'=>$cognitiveFollowthroughBuild,
        'supervisionBuild'=>$cognitiveSupervisionBuild,
        'autonomyBuild'=>$cognitiveAutonomyBuild,
        'portfolioBuild'=>$cognitivePortfolioBuild,
        'forecastBuild'=>$cognitiveForecastBuild,
        'optimizationBuild'=>$cognitiveOptimizationBuild,
        'resourceBudgetBuild'=>$cognitiveResourceBudgetBuild,
        'replanningBuild'=>$cognitiveReplanningBuild,
        'commitmentProtectionBuild'=>$cognitiveCommitmentProtectionBuild,
        'economicsBuild'=>$cognitiveEconomicsBuild,
        'budgetGovernanceBuild'=>$cognitiveBudgetGovernanceBuild,
        'valueRoiBuild'=>$cognitiveValueRoiBuild,
        'decisionCalibrationBuild'=>$cognitiveDecisionCalibrationBuild,
        'currentStateBuild'=>$cognitiveCurrentStateBuild,
        'learningEndpoint'=>url('/api/agent-learning-history-v317.php'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ';</script>'
    . '<script data-chat-notification-drawer src="' . e(url('/chat-notifications-drawer-v240.js?v=' . $notificationDrawerAssetBuild)) . '"></script>'
    . '<script data-brain-learning-history-v317 src="' . e(url('/chat-brain-learning-history-v317.js?v=' . $brainLearningBuild)) . '"></script>';

$rewardTrayRuntime = function_exists('campaigns_rewards_v110_schema_ready')
    ? '<link rel="stylesheet" data-reward-tray-v110 href="' . e(url('/reward-tray-v110.css?v=113-mobile-header-v114')) . '">'
        . '<script data-reward-tray-config-v110>window.VP3_REWARD_TRAY_V110={endpoint:'
        . json_encode(url('/api/reward-tray-v110.php'),JSON_UNESCAPED_SLASHES)
        . ',csrf:' . json_encode(csrf_token(),JSON_UNESCAPED_SLASHES)
        . ',routes:' . json_encode([
            'inbox'=>url('/reward-inbox.php'),
            'sent'=>url('/reward-sent.php'),
            'claimed'=>url('/reward-claimed.php'),
        ],JSON_UNESCAPED_SLASHES)
        . '};</script>'
        . '<script data-reward-qr-v110 src="' . e(url('/reward-qr-v110.js?v=113')) . '"></script>'
        . '<script data-reward-tray-v110 src="' . e(url('/reward-tray-v110.js?v=113')) . '"></script>'
    : '';

$runtime = $headerUiRuntime
         . $agentIntelligenceRuntime
         . $mediaOverlayRuntime
         . $hardening
         . $composerControls
         . '<link rel="stylesheet" href="' . e(url('/chat-tools.css?v=' . $controlBuild . '-connected-knowledge')) . '">'
         . '<script src="' . e(url('/chat-tools.js?v=' . $controlBuild)) . '"></script>'
         . '<script>window.VP3_CONNECTED_GELATO_V1=' . json_encode([
             'endpoint'=>url('/api/connected-gelato-v1.php'),
             'csrf'=>csrf_token(),
             'knowledgeUrl'=>url('/knowledge.php'),
         ], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) . ';</script>'
         . '<script src="' . e(url('/chat-connected-sites-v1.js?v=3')) . '"></script>'
         . $recordingLibraryRuntime
         . $chatSettingsRuntime
         . '<script data-agent-updates-autodismiss-v173 data-agent-overlay-build="' . e($agentOverlayBuild) . '" src="' . e(url('/chat-agent-updates-autodismiss-v173.js?v=' . $agentOverlayBuild)) . '"></script>'
         . $voiceConfig
         . '<script src="' . e(url('/voice-lease-v122.js?v=interactive-capture-native-receiver-20261023')) . '"></script>'
         . '<script data-premium-voice-v142 data-premium-audio-unlock="v147" src="' . e(url('/premium-voice-v117.js?v=' . $premiumVoiceBuild)) . '"></script>'
         . '<script data-agent-context-v142 src="' . e(url('/agent-context-v131.js?v=' . $controlBuild . '&participants=section5-20261004')) . '"></script>'
         . '<script data-chat-voice data-chat-echo-guard="canonical" data-chat-streaming="enabled" data-chat-processed-input="enabled" data-chat-barge="speech-recognition" data-chat-turn-pause="1800" data-chat-lifecycle="canonical" src="' . e(url('/chat-voice.js?v=' . $voiceCacheBuild . '&capture=interactive-listening-section2-20261003')) . '"></script>'
         . $agentIdentityRuntime
         . $profileActivityRuntime
         . $profileCallsRuntime
         . $profileDoorbellRuntime
         . $profileSpeechRuntime
         . $browserContextRuntime
         . $agentTeamsA3Runtime
         . $cognitivePresentationPre
         . $notificationDrawerRuntime
         . $cognitiveCardsRuntime
         . $initiativeWorkbenchRuntime
         . $cognitiveOrchestrationRuntime
         . $cognitivePresentationPost
         . '<script data-team-chat-admin-v109 data-team-chat-admin-build="' . e($teamChatAdminBuild) . '" src="' . e(url('/team-chat-admin-v109.js?v=' . $teamChatAdminBuild)) . '"></script>'
         . $railLayout
         . $rewardTrayRuntime
         . '<span data-stonefellow-build="' . e($runtimeBuild) . '" data-chat-controls-build="' . e($controlBuild) . '" data-premium-voice-build="' . e($premiumVoiceBuild) . '" data-chat-voice-build="' . e($voiceAssetBuild) . '" data-chat-voice-feature-build="' . e($voiceCacheBuild) . '" data-recording-ui-build="' . e($recordingUiBuild) . '" data-recording-persistence-build="' . e($recordingPersistenceBuild) . '" data-transcription-canvas-build="' . e($transcriptionCanvasBuild) . '" data-team-chat-admin-build="' . e($teamChatAdminBuild) . '" data-agent-theme-build="' . e($agentThemeBuild) . '" data-chat-media-overlay-build="' . e($mediaOverlayBuild) . '" data-agent-overlay-build="' . e($agentOverlayBuild) . '" data-user-agent-build="' . e($agentIdentityBuild) . '" data-chat-settings-build="' . e($chatSettingsBuild) . '" data-notification-drawer-build="' . e($notificationDrawerBuild) . '" data-agent-activity-build="' . e($activityBuild) . '" data-brain-learning-build="' . e($brainLearningBuild) . '" data-agent-intelligence-build="' . e($agentIntelligenceBuild) . '" data-cognitive-cards-build="' . e($cognitiveCardsBuild) . '" data-cognitive-learning-build="' . e($cognitiveLearningBuild) . '" data-cognitive-planning-build="' . e($cognitivePlanningBuild) . '" data-cognitive-orchestration-build="' . e($cognitiveOrchestrationBuild) . '" data-cognitive-memory-build="' . e($cognitiveMemoryBuild) . '" data-cognitive-context-build="' . e($cognitiveContextBuild) . '" data-cognitive-turn-build="' . e($cognitiveTurnBuild) . '" data-cognitive-continuity-build="' . e($cognitiveContinuityBuild) . '" data-cognitive-followthrough-build="' . e($cognitiveFollowthroughBuild) . '" data-cognitive-supervision-build="' . e($cognitiveSupervisionBuild) . '" data-cognitive-autonomy-build="' . e($cognitiveAutonomyBuild) . '" data-cognitive-portfolio-build="' . e($cognitivePortfolioBuild) . '" data-cognitive-forecast-build="' . e($cognitiveForecastBuild) . '" data-cognitive-optimization-build="' . e($cognitiveOptimizationBuild) . '" data-cognitive-resource-budget-build="' . e($cognitiveResourceBudgetBuild) . '" data-cognitive-replanning-build="' . e($cognitiveReplanningBuild) . '" data-cognitive-commitment-protection-build="' . e($cognitiveCommitmentProtectionBuild) . '" data-cognitive-economics-build="' . e($cognitiveEconomicsBuild) . '" data-cognitive-budget-governance-build="' . e($cognitiveBudgetGovernanceBuild) . '" data-cognitive-value-roi-build="' . e($cognitiveValueRoiBuild) . '" data-cognitive-decision-calibration-build="' . e($cognitiveDecisionCalibrationBuild) . '" data-cognitive-current-state-build="' . e($cognitiveCurrentStateBuild) . '" data-browser-context-build="' . e($browserContextBuild) . '" hidden></span>';

$html = str_replace('</body>', $runtime . '</body>', $html);
echo $html;
