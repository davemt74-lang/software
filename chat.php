<?php
declare(strict_types=1);

$runtimeBuild = 'conversation-integration-v131-20260826';
$controlBuild = 'chat-voice-canonical-20260903';
$premiumVoiceBuild = 'premium-voice-verified-v157-20260829';
$voiceAssetBuild = 'chat-voice-canonical-20260903';
$voiceCacheBuild = 'chat-voice-canonical-20260903-failover1';
$recordingUiBuild = 'chat-recording-results-v206-20260901';
$recordingPersistenceBuild = 'chat-recordings-v242-20260902';
$transcriptionCanvasBuild = 'chat-transcription-canvas-v243-layout-20260905';
$mediaOverlayBuild = 'chat-media-overlays-source-light-20260905';
$agentOverlayBuild = 'agent-updates-hidden-v206-20260901';
$agentIdentityBuild = 'profile-activity-20260905';
$profileActivityBuild = 'profile-activity-overlay-20260905';
$headerUiBuild = 'live-wiring-20260903-3';
$teamChatAdminBuild = 'team-chat-bootstrap-v236-20260905';
$chatSettingsBuild = 'chat-settings-v239-canonical-20260905';
$notificationDrawerBuild = 'chat-notifications-brain-v240-20260905';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Stonefellow-Runtime: ' . $runtimeBuild);
    header('X-Stonefellow-Chat-Controls: ' . $controlBuild);
    header('X-Stonefellow-Chat-Voice: ' . $voiceAssetBuild);
    header('X-Stonefellow-Chat-Voice-Feature: ' . $voiceCacheBuild);
    header('X-Stonefellow-Agent-UI: ' . $agentIdentityBuild);
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

if (has_permission('artist_listening.access', $user)) {
    $recordingsNavLink = '<a class="chat-sidebar-nav-link chat-sidebar-recordings-link" href="'
        . e(url('/artist-listening.php')) . '" aria-label="Open My Transcriptions">'
        . '<span aria-hidden="true">●</span><strong>My Transcriptions</strong></a>';
    $html = preg_replace_callback(
        '~(\s*</nav>\s*</section>\s*<section class="chat-sidebar-history-section")~',
        static fn(array $matches): string => "\n          " . $recordingsNavLink . $matches[1],
        $html,
        1
    ) ?? $html;
}

$html = str_replace('chat.js?v=101', 'chat.js?v=' . $controlBuild, $html);

$agentFeatureReady = false;
$activeUserAgent = null;
$requestedAgentRaw = trim((string)($_GET['agent'] ?? ''));
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
            // A renamed agent is the owner's Stonefellow identity, not a separate
            // weaker assistant. Normal /chat.php therefore opens it immediately.
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
        $agentOnboarding = !$activeUserAgent
            && !$explicitSystemAgent
            && !user_agents_list_v236($pdoForAgent, (int)$user['id'])
            && !user_agent_onboarding_dismissed_v236($pdoForAgent, (int)$user['id']);
    }
} catch (Throwable $e) {
    $agentFeatureReady = false;
    $activeUserAgent = null;
    $requestedAgentId = 0;
    $agentDisplayName = $systemAgentName;
    $agentInitialConversationId = (int)$chatInitialConversationId;
}

// Canonical account dropdown. Replace the legacy menu as one unit so Chat does
// not inject parallel account.php hash links that drift from the other surfaces.
$chatProfileLinks = '';
$chatProfileMenuExcluded = ['account'=>true, 'profile_agent'=>true];
foreach (member_navigation_menu_links($user) as $menuLink) {
    if (isset($chatProfileMenuExcluded[(string)($menuLink['key'] ?? '')])) {
        continue;
    }
    $class = !empty($menuLink['danger']) ? ' class="logout"' : '';
    $chatProfileLinks .= '<a' . $class
        . ' data-chat-profile-link="' . e((string)$menuLink['key']) . '"'
        . ' href="' . e((string)$menuLink['url']) . '">'
        . '<span>' . e((string)$menuLink['label']) . '</span><span>↗</span></a>';
}
$html = preg_replace(
    '~<nav class="chat-profile-links">.*?</nav>~s',
    '<nav class="chat-profile-links">' . $chatProfileLinks . '</nav>',
    $html,
    1
) ?? $html;

$chatApiEndpoint = $agentFeatureReady
    ? url('/api/chat-v236.php') . ($activeUserAgent ? '?agent=' . (int)$activeUserAgent['id'] : '')
    : url('/api/chat.php');
if (!$agentFeatureReady) {
    $agentInitialConversationId = (int)$chatInitialConversationId;
}

$agentChatBootstrap = '<script data-user-agent-chat-v236>(function(){"use strict";var cfg=window.STONEFELLOW_CHAT||{};cfg.endpoint='
    . json_encode($chatApiEndpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ';cfg.initialConversationId=' . (int)$agentInitialConversationId . ';window.STONEFELLOW_AGENT_IDENTITY_V236={'
    . 'displayName:' . json_encode($agentDisplayName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ',systemName:' . json_encode($systemAgentName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ',agentId:' . (int)($activeUserAgent['id'] ?? 0)
    . ',showOnboarding:' . ($agentFeatureReady && $agentOnboarding ? 'true' : 'false')
    . ',endpoint:' . json_encode(url('/api/user-agent-system-v236.php'), JSON_UNESCAPED_SLASHES)
    . ',chatBaseUrl:' . json_encode(url('/chat.php'), JSON_UNESCAPED_SLASHES)
    . ',accountUrl:' . json_encode(url('/account.php#agents-data'), JSON_UNESCAPED_SLASHES)
    . ',profileAgentEndpoint:' . json_encode(url('/api/profile-agent.php'), JSON_UNESCAPED_SLASHES)
    . ',profileAgentUrl:' . json_encode(url('/profile-agent.php'), JSON_UNESCAPED_SLASHES)
    . ',csrf:' . json_encode(csrf_token(), JSON_UNESCAPED_SLASHES)
    . '};})();</script>';

$voiceBootstrap = '<script data-chat-voice-pre>(function(){"use strict";var cfg=window.STONEFELLOW_CHAT||{};var b=document.getElementById("chatVoiceButton");window.STONEFELLOW_CHAT_VOICE_BOOT={intro:cfg.intro||null,button:b,legacyDormant:true};cfg.intro=null;if(b){b.id="chatVoiceButtonLegacyDormant";b.disabled=false;}})();</script>';
$html = preg_replace(
    '~(<script[^>]+src="[^"]*chat\.js\?v=[^"]+"[^>]*></script>)~i',
    $agentChatBootstrap . $voiceBootstrap . '$1',
    $html,
    1
) ?? $html;

$videoEditorButton = '<a class="chat-video-editor-button" id="chatVideoEditorButton" href="' . e(url('/video-editor.php')) . '" aria-label="Open Video Editor" title="Video Editor">'
    . '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><rect x="3" y="5" width="13" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M16 9.2 21 6.8v10.4L16 14.8Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>'
    . '</a>';
$html = preg_replace(
    '~(<button class="chat-voice-button" id="chatVoiceButton"[^>]*>.*?</button>)~s',
    '$1' . $videoEditorButton,
    $html,
    1
) ?? $html;

$introText = trim((string)($chatIntro['greeting'] ?? ''));
if ($introText === '') {
    $displayName = trim((string)($user['display_name'] ?? ''));
    $firstName = $displayName !== '' ? preg_split('/\s+/', $displayName)[0] : 'there';
    $introText = 'Hello ' . $firstName . '.';
}
if ($activeUserAgent && strcasecmp($agentDisplayName, $systemAgentName) !== 0) {
    // The proactive greeting is generated by the legacy continuity layer before
    // the active agent is resolved. Correct only its assistant identity wording.
    $introText = preg_replace_callback(
        '/\bStonefellow\b/u',
        static fn(): string => $agentDisplayName,
        $introText
    ) ?? $introText;
}

$debugMarker = '<div id="chatRuntimeDebug" style="max-width:790px;margin:0 auto 12px;color:#6b7280;font:700 11px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.04em">LIVE RUNTIME · ' . e($voiceCacheBuild) . ' · ' . e($agentIdentityBuild) . '</div>';
$introMessage = $debugMarker
    . '<div class="message assistant" id="chatWelcome" hidden>'
    . '<div class="message-avatar" aria-hidden="true">' . e(mb_strtoupper(mb_substr($agentDisplayName, 0, 1))) . '</div>'
    . '<div class="message-body">'
    . '<div class="message-role">' . e($agentDisplayName) . '</div>'
    . '<div class="message-text">' . e($introText) . '</div>'
    . '</div>'
    . '</div>';

$html = preg_replace(
    '~<div class="chat-welcome" id="chatWelcome">.*?</div>\s*</section>~s',
    $introMessage . "\n    </section>",
    $html,
    1
) ?? $html;

$hardening = '<style data-chat-overlay-removal-v206>#chatLiveUpdates,.chat-live-updates,.agent-update-overlay,.agent-updates-overlay,#chatRecordingsCanvas,.chat-recordings-canvas{display:none!important}</style>'
    . '<script data-chat-ui-hardening-v206>(function(){"use strict";var selector="#agentNextMovesCanvas,.agent-next-canvas-v97,.agent-next-moves,.agent-proactive-panel,#chatLiveUpdates,.chat-live-updates,.agent-update-overlay,.agent-updates-overlay,#chatRecordingsCanvas,.chat-recordings-canvas";var purge=function(){document.querySelectorAll(selector).forEach(function(el){el.remove();});};purge();var o=new MutationObserver(purge);o.observe(document.documentElement,{childList:true,subtree:true});window.addEventListener("pagehide",function(){o.disconnect();},{once:true});})();</script>';

$composerControls = '<style data-chat-controls-v142>'
    . '.chat-composer .chat-video-editor-button{display:grid;place-items:center;flex:0 0 34px;width:34px;min-width:34px;height:34px;border:0;border-radius:9px;color:inherit;background:transparent;text-decoration:none;align-self:flex-end;box-sizing:border-box;}'
    . '.chat-composer .chat-video-editor-button:hover,.chat-composer .chat-video-editor-button:focus-visible{background:rgba(127,127,127,.12);outline:none;}'
    . '.chat-composer .chat-video-editor-button svg{display:block;pointer-events:none;}'
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
    . '.chat-topbar{position:relative;isolation:isolate;}'
    . '.chat-topbar::after{content:"";position:absolute;inset:0;pointer-events:none;opacity:0;z-index:0;transition:opacity .16s ease,background .16s ease,box-shadow .16s ease;}'
    . '.chat-topbar>*{position:relative;z-index:1;}'
    . 'body[data-stonefellow-agent-state="listening"] .chat-topbar::after{opacity:1;background:linear-gradient(90deg,rgba(88,166,255,.16),rgba(88,166,255,.045));box-shadow:inset 0 -3px 0 #58a6ff;}'
    . 'body[data-stonefellow-agent-state="processing"] .chat-topbar::after{opacity:1;background:linear-gradient(90deg,rgba(73,209,125,.16),rgba(73,209,125,.045));box-shadow:inset 0 -3px 0 #49d17d;}'
    . 'body[data-stonefellow-agent-state="speaking"] .chat-topbar::after{opacity:1;background:linear-gradient(90deg,rgba(255,98,107,.17),rgba(255,98,107,.045));box-shadow:inset 0 -3px 0 #ff626b;}'
    . '@media(max-width:600px){.chat-composer .chat-video-editor-button{flex-basis:32px;width:32px;min-width:32px;height:32px;}}'
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
        . ',persistEndpoint:' . json_encode(url('/api/chat-recordings-v242.php'), JSON_UNESCAPED_SLASHES)
        . '};</script>'
        . '<link rel="stylesheet" data-chat-transcription-canvas href="' . e(url('/chat-transcription-canvas.css?v=' . $transcriptionCanvasBuild)) . '">'
        . '<script data-chat-recordings-v242 data-recording-persistence-build="' . e($recordingPersistenceBuild) . '" src="' . e(url('/chat-recordings-v242.js?v=' . $recordingPersistenceBuild)) . '"></script>'
        . '<script data-artist-recordings-v198 data-recording-ui-build="' . e($recordingUiBuild) . '" src="' . e(url('/artist-listening-recordings.js?v=' . $recordingUiBuild)) . '"></script>'
        . '<script data-chat-transcription-canvas data-transcription-canvas-build="' . e($transcriptionCanvasBuild) . '" src="' . e(url('/chat-transcription-canvas.js?v=' . $transcriptionCanvasBuild)) . '"></script>'
    : '';

$voiceConfig = '<script data-chat-voice-config>window.STONEFELLOW_AGENT_CONTEXT={userId:' . (int)$user['id'] . ',surface:"chat",trackId:0,projectId:0,conversationId:' . (int)$agentInitialConversationId . ',taskTitle:"Agent Chat",taskKey:"chat",csrf:' . json_encode(csrf_token()) . ',proactiveEndpoint:' . json_encode(url('/api/agent-proactive-v93.php')) . '};</script>';

$agentIdentityRuntime = $agentFeatureReady
    ? '<link rel="stylesheet" data-chat-agent-identity-v236 href="' . e(url('/chat-agent-identity-v236.css?v=' . $agentIdentityBuild)) . '">'
        . '<script data-chat-agent-identity-v236 src="' . e(url('/chat-agent-identity-v236.js?v=' . $agentIdentityBuild)) . '"></script>'
    : '';

$profileActivityRuntime = $agentFeatureReady && $pdoForAgent && profile_agent_schema_ready($pdoForAgent)
    ? '<link rel="stylesheet" data-profile-activity-chat href="' . e(url('/profile-activity-chat.css?v=' . $profileActivityBuild)) . '">'
        . '<script data-profile-activity-chat src="' . e(url('/profile-activity-chat.js?v=' . $profileActivityBuild)) . '"></script>'
    : '';

// Agent Chat itself owns its universal settings and Activity Center. These are
// not gated by Team Chat roles or Profile Agent availability.
$chatSettingsRuntime = '<link rel="stylesheet" data-chat-settings-canonical href="' . e(url('/chat-settings-v237.css?v=' . $chatSettingsBuild)) . '">'
    . '<script data-chat-settings-config>window.STONEFELLOW_CHAT_SETTINGS='
    . json_encode([
        'endpoint'=>url('/api/chat-settings-v237.php'),
        'csrf'=>csrf_token(),
        'build'=>$chatSettingsBuild,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ';</script>'
    . '<script data-chat-settings-canonical src="' . e(url('/chat-settings-v237.js?v=' . $chatSettingsBuild)) . '"></script>';

$notificationDrawerRuntime = '<link rel="stylesheet" data-chat-notification-drawer href="' . e(url('/chat-notifications-drawer-v240.css?v=' . $notificationDrawerBuild)) . '">'
    . '<script data-chat-notification-drawer-config>window.STONEFELLOW_NOTIFICATION_DRAWER='
    . json_encode([
        'endpoint'=>url('/api/chat-notifications-brain-v240.php'),
        'csrf'=>csrf_token(),
        'build'=>$notificationDrawerBuild,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . ';</script>'
    . '<script data-chat-notification-drawer src="' . e(url('/chat-notifications-drawer-v240.js?v=' . $notificationDrawerBuild)) . '"></script>';

$runtime = $headerUiRuntime
         . $mediaOverlayRuntime
         . $hardening
         . $composerControls
         . $recordingLibraryRuntime
         . $chatSettingsRuntime
         . '<script data-agent-updates-autodismiss-v173 data-agent-overlay-build="' . e($agentOverlayBuild) . '" src="' . e(url('/chat-agent-updates-autodismiss-v173.js?v=' . $agentOverlayBuild)) . '"></script>'
         . $voiceConfig
         . '<script data-premium-voice-v142 data-premium-audio-unlock="v147" src="' . e(url('/premium-voice-v117.js?v=' . $premiumVoiceBuild)) . '"></script>'
         . '<script data-agent-context-v142 src="' . e(url('/agent-context-v131.js?v=' . $controlBuild)) . '"></script>'
         . '<script data-chat-voice data-chat-echo-guard="canonical" data-chat-streaming="enabled" data-chat-processed-input="enabled" data-chat-barge="speech-recognition" data-chat-turn-pause="1800" data-chat-lifecycle="canonical" src="' . e(url('/chat-voice.js?v=' . $voiceCacheBuild)) . '"></script>'
         . $agentIdentityRuntime
         . $profileActivityRuntime
         . $notificationDrawerRuntime
         . '<script data-team-chat-admin-v109 data-team-chat-admin-build="' . e($teamChatAdminBuild) . '" src="' . e(url('/team-chat-admin-v109.js?v=' . $teamChatAdminBuild)) . '"></script>'
         . $railLayout
         . '<span data-stonefellow-build="' . e($runtimeBuild) . '" data-chat-controls-build="' . e($controlBuild) . '" data-premium-voice-build="' . e($premiumVoiceBuild) . '" data-chat-voice-build="' . e($voiceAssetBuild) . '" data-chat-voice-feature-build="' . e($voiceCacheBuild) . '" data-recording-ui-build="' . e($recordingUiBuild) . '" data-recording-persistence-build="' . e($recordingPersistenceBuild) . '" data-transcription-canvas-build="' . e($transcriptionCanvasBuild) . '" data-team-chat-admin-build="' . e($teamChatAdminBuild) . '" data-agent-theme-build="' . e($agentThemeBuild) . '" data-chat-media-overlay-build="' . e($mediaOverlayBuild) . '" data-agent-overlay-build="' . e($agentOverlayBuild) . '" data-user-agent-build="' . e($agentIdentityBuild) . '" data-chat-settings-build="' . e($chatSettingsBuild) . '" data-notification-drawer-build="' . e($notificationDrawerBuild) . '" hidden></span>';

$html = str_replace('</body>', $runtime . '</body>', $html);
echo $html;
