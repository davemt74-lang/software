(() => {
  'use strict';

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

  function normalizeRail() {
    removeRedundantAgentHeading();

    const form = document.getElementById('chatForm');
    if (!form) return false;

    const launcher = document.getElementById('chatSettingsLauncher');
    const voice = form.querySelector('#chatVoiceButton,#chatVoiceButtonLegacyDormant,.chat-voice-button');
    const send = form.querySelector('#sendChatButton');

    if (launcher && launcher.parentElement !== form) {
      form.insertBefore(launcher, voice || send || null);
    }

    if (launcher) {
      launcher.dataset.railDocked = '1';
      launcher.style.setProperty('position', 'static', 'important');
      launcher.style.setProperty('inset', 'auto', 'important');
      launcher.style.setProperty('display', 'grid', 'important');
      launcher.style.setProperty('place-items', 'center', 'important');
      launcher.style.setProperty('transform', 'none', 'important');
      launcher.style.setProperty('z-index', '2', 'important');
      normalizeControl(launcher);
      normalizeControl(launcher.querySelector('.chat-settings-button'));
    }

    normalizeControl(voice);
    normalizeControl(send);
    form.dataset.railControls = 'v132';
    return Boolean(launcher && voice && send);
  }

  normalizeRail();
  document.addEventListener('DOMContentLoaded', normalizeRail, { once:true });
  window.addEventListener('load', normalizeRail, { once:true });

  const observer = new MutationObserver(normalizeRail);
  observer.observe(document.documentElement, { childList:true, subtree:true });

  [0, 50, 150, 400, 1000, 2500, 5000].forEach(delay => {
    window.setTimeout(normalizeRail, delay);
  });

  window.addEventListener('pagehide', () => observer.disconnect(), { once:true });
})();
