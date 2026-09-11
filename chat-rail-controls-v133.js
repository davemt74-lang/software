(() => {
  'use strict';

  let socialChatEnabled = true;

  function removeRedundantAgentHeading() {
    document.querySelectorAll('.workspace-main-sidebar .chat-sidebar-nav-section>.chat-history-label').forEach(label => {
      if ((label.textContent || '').trim().toLowerCase() === 'agent') label.remove();
    });
  }

  function normalizeControl(control) {
    if (!(control instanceof HTMLElement)) return;
    control.style.setProperty('width', '32px', 'important');
    control.style.setProperty('min-width', '32px', 'important');
    control.style.setProperty('max-width', '32px', 'important');
    control.style.setProperty('height', '32px', 'important');
    control.style.setProperty('min-height', '32px', 'important');
    control.style.setProperty('max-height', '32px', 'important');
    control.style.setProperty('margin', '0', 'important');
    control.style.setProperty('padding', '0', 'important');
    control.style.setProperty('box-sizing', 'border-box', 'important');
  }

  function normalizeComposer() {
    const form = document.getElementById('chatForm');
    if (!form) return false;
    const voice = form.querySelector('#chatVoiceButton,#chatVoiceButtonLegacyDormant,.chat-voice-button');
    const send = form.querySelector('#sendChatButton');
    normalizeControl(voice);
    normalizeControl(send);
    form.dataset.railControls = 'v133';
    return Boolean(voice && send);
  }

  function ensureSettingsSlot(rail) {
    let slot = document.getElementById('sfChatSettingsRailSlotV133');
    if (slot && slot.parentElement !== rail) slot.remove();
    if (!slot) {
      slot = document.createElement('div');
      slot.className = 'sf-online-rail-settings-v133';
      slot.id = 'sfChatSettingsRailSlotV133';
      slot.setAttribute('aria-label', 'Chat rail settings');
      rail.appendChild(slot);
    }
    return slot;
  }

  function normalizeRightRail() {
    removeRedundantAgentHeading();
    normalizeComposer();

    const rail = document.getElementById('sfOnlineRailV109');
    const launcher = document.getElementById('chatSettingsLauncher');
    if (!rail || !launcher) return false;

    const slot = ensureSettingsSlot(rail);
    if (launcher.parentElement !== slot) slot.appendChild(launcher);

    launcher.dataset.railDocked = 'right-v133';
    launcher.style.setProperty('position', 'static', 'important');
    launcher.style.setProperty('inset', 'auto', 'important');
    launcher.style.setProperty('display', 'grid', 'important');
    launcher.style.setProperty('place-items', 'center', 'important');
    launcher.style.setProperty('transform', 'none', 'important');
    launcher.style.setProperty('z-index', '4', 'important');
    normalizeControl(launcher);
    normalizeControl(launcher.querySelector('.chat-settings-button'));

    const users = rail.querySelector('.sf-online-users-v109');
    const windows = document.getElementById('sfTeamChatWindowsV109');
    if (users) users.hidden = !socialChatEnabled;
    if (windows) windows.hidden = !socialChatEnabled;

    // The rail remains visible because it now owns the Chat Settings control,
    // even when user-to-user chat is disabled.
    rail.hidden = false;
    document.body.classList.add('sf-team-rail-active');
    return true;
  }

  window.addEventListener('stonefellow:chat-settings-updated', event => {
    if (event?.detail && Object.prototype.hasOwnProperty.call(event.detail, 'social_chat_enabled')) {
      socialChatEnabled = event.detail.social_chat_enabled !== false;
    }
    normalizeRightRail();
  });

  normalizeRightRail();
  document.addEventListener('DOMContentLoaded', normalizeRightRail, { once:true });
  window.addEventListener('load', normalizeRightRail, { once:true });

  const observer = new MutationObserver(normalizeRightRail);
  observer.observe(document.documentElement, { childList:true, subtree:true });

  [0, 50, 150, 400, 1000, 2500, 5000].forEach(delay => {
    window.setTimeout(normalizeRightRail, delay);
  });

  window.addEventListener('pagehide', () => observer.disconnect(), { once:true });
})();
