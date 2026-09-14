(() => {
  'use strict';

  if (window.__VP3_AUTHENTICATED_RUNTIME_BRIDGE__) return;
  window.__VP3_AUTHENTICATED_RUNTIME_BRIDGE__ = true;

  const pairs = [
    ['VP3_NOTIFICATION_DRAWER', 'STONEFELLOW_NOTIFICATION_DRAWER'],
    ['VP3_RECORDINGS_V198_CONFIG', 'STONEFELLOW_RECORDINGS_V198_CONFIG'],
    ['VP3_ARTIST_LISTENING_CONFIG', 'STONEFELLOW_ARTIST_LISTENING_CONFIG'],
    ['VP3_ARTIST_LISTENING_V172', 'STONEFELLOW_ARTIST_LISTENING_V172'],
    ['VP3_ARTIST_RECORDINGS_V198', 'STONEFELLOW_ARTIST_RECORDINGS_V198'],
    ['VP3_AGENT_CONTEXT', 'STONEFELLOW_AGENT_CONTEXT'],
    ['VP3_PROFILE_AGENT', 'STONEFELLOW_PROFILE_AGENT'],
  ];

  function bridge(primary, legacy) {
    const primaryDescriptor = Object.getOwnPropertyDescriptor(window, primary);
    const legacyDescriptor = Object.getOwnPropertyDescriptor(window, legacy);
    let value = window[primary] ?? window[legacy];

    if (primaryDescriptor && !primaryDescriptor.configurable) {
      if (window[legacy] == null && value != null) window[legacy] = value;
      return;
    }
    if (legacyDescriptor && !legacyDescriptor.configurable) {
      if (window[primary] == null && value != null) window[primary] = value;
      return;
    }

    const descriptor = {
      configurable: true,
      enumerable: true,
      get() { return value; },
      set(next) { value = next; },
    };

    Object.defineProperty(window, primary, descriptor);
    Object.defineProperty(window, legacy, descriptor);
  }

  pairs.forEach(([primary, legacy]) => bridge(primary, legacy));

  function normalizeAuthenticatedBrand(root = document) {
    root.querySelectorAll?.('.chat-notification-drawer-head small').forEach(label => {
      if (String(label.textContent || '').trim().toLowerCase() === 'stonefellow') {
        label.textContent = 'VP3';
      }
    });
  }

  if (typeof document !== 'undefined') {
    const boot = () => {
      normalizeAuthenticatedBrand(document);
      if (typeof MutationObserver === 'function' && document.documentElement) {
        const observer = new MutationObserver(records => {
          for (const record of records) {
            for (const node of record.addedNodes || []) {
              if (node?.nodeType !== 1) continue;
              if (node.matches?.('.chat-notification-drawer-head small')) normalizeAuthenticatedBrand(node.parentElement || node);
              else normalizeAuthenticatedBrand(node);
            }
          }
        });
        observer.observe(document.documentElement, {childList: true, subtree: true});
        window.addEventListener?.('pagehide', () => observer.disconnect(), {once: true});
      }
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once: true});
    else boot();
  }
})();
