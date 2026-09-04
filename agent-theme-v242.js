(() => {
  'use strict';

  const BUILD = 'agent-theme-v243-20260902';
  const cfg = window.STONEFELLOW_CHAT || {};
  const userId = Math.max(0, Number(cfg.userId || 0));
  const key = `stonefellow:agent-theme:v242:${userId}`;
  const proof = window.STONEFELLOW_AGENT_THEME_V242 = {
    build: BUILD,
    loaded: true,
    theme: 'light',
  };

  function savedTheme() {
    try {
      const value = String(localStorage.getItem(key) || 'light');
      return value === 'dark' ? 'dark' : 'light';
    } catch (error) {
      return 'light';
    }
  }

  function setTheme(theme, persist = true) {
    const next = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.dataset.agentTheme = next;
    if (document.body && document.body.dataset.agentTheme !== next) document.body.dataset.agentTheme = next;
    proof.theme = next;
    document.documentElement.style.colorScheme = next;

    const meta = document.querySelector('meta[name="theme-color"]');
    const metaColor = next === 'light' ? '#ffffff' : '#050914';
    if (meta && meta.getAttribute('content') !== metaColor) meta.setAttribute('content', metaColor);

    const button = document.querySelector('[data-v242-theme-toggle]');
    if (button) {
      const light = next === 'light';
      const glyph = light ? '☾' : '☀';
      const title = light ? 'Use dark theme' : 'Use white theme';
      if (button.textContent !== glyph) button.textContent = glyph;
      if (button.title !== title) button.title = title;
      if (button.getAttribute('aria-label') !== title) button.setAttribute('aria-label', title);
      if (button.getAttribute('aria-pressed') !== (light ? 'true' : 'false')) button.setAttribute('aria-pressed', light ? 'true' : 'false');
    }

    if (persist) {
      try { localStorage.setItem(key, next); } catch (error) {}
    }
  }

  function ensureToggle() {
    const actions = document.querySelector('.chat-topbar-actions');
    if (!actions) return false;
    let button = actions.querySelector('[data-v242-theme-toggle]');
    if (!button) {
      button = document.createElement('button');
      button.type = 'button';
      button.className = 'sf-v242-theme-toggle';
      button.dataset.v242ThemeToggle = '1';
      button.addEventListener('click', () => {
        setTheme(document.body.dataset.agentTheme === 'light' ? 'dark' : 'light');
      });
      actions.insertBefore(button, actions.firstChild);
    }
    setTheme(document.body.dataset.agentTheme || savedTheme(), false);
    return true;
  }

  function boot() {
    setTheme(savedTheme(), false);
    ensureToggle();
    const observer = new MutationObserver(() => ensureToggle());
    observer.observe(document.body, {childList:true,subtree:true});
    window.addEventListener('pagehide', () => observer.disconnect(), {once:true});
  }

  /* Light is the default product theme. A previously saved dark choice remains respected. */
  setTheme(savedTheme(), false);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true});
  else boot();
})();
