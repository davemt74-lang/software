<?php
declare(strict_types=1);

// Backward-compatible wrapper. All member/public workspace pages now render
// the single canonical Main Feed sidebar from includes/main-sidebar.php.
$mainSidebarUser = $mainSidebarUser ?? $workspaceSidebarUser ?? current_user();
$mainSidebarActive = $mainSidebarActive ?? $workspaceSidebarActive ?? '';
require __DIR__ . '/main-sidebar.php';

// Account pages still need their agent-settings loader, but navigation itself
// is owned exclusively by main-sidebar.php.
?>
<script data-workspace-account-live-wiring>(function(){'use strict';function loadAccountAgentUi(){if(!document.querySelector('.account-canvas-content'))return;if(document.querySelector('[data-account-agent-settings-loader]'))return;var s=document.createElement('script');s.src=<?= json_encode(url('/account-agent-settings-loader-v236.js?v=account-light-shell-20260905'),JSON_UNESCAPED_SLASHES) ?>;s.dataset.accountAgentSettingsLoader='server';document.body.appendChild(s);}if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',loadAccountAgentUi,{once:true});else loadAccountAgentUi();})();</script>
