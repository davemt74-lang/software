(() => {
'use strict';
// profile-agent-portal.js captures the original node before this script runs.
// Move that legacy node out of sight, then give the full Analytics dashboard a
// stable host the legacy 15-second renderer does not own.
const legacy=document.getElementById('profileAgentAnalytics');
if(!legacy)return;
legacy.id='profileAgentAnalyticsLegacy';
legacy.hidden=true;
legacy.setAttribute('aria-hidden','true');
const host=document.createElement('div');
host.id='profileAgentAnalytics';
legacy.insertAdjacentElement('afterend',host);
})();