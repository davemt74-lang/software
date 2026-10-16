/* Keep workspace controls available without occupying the conversation canvas. */
(() => {
  'use strict';
  const shell = document.getElementById('chatComposerShell');
  const form = document.getElementById('chatForm');
  const input = document.getElementById('chatInput');
  if (!shell || !form || !input || document.getElementById('chatToolsButton')) return;

  const button = document.createElement('button');
  button.id = 'chatToolsButton';
  button.type = 'button';
  button.textContent = '+';
  button.title = 'Chat tools';
  button.setAttribute('aria-label', 'Chat tools');
  button.setAttribute('aria-expanded', 'false');
  button.setAttribute('aria-controls', 'chatToolsPanel');
  button.setAttribute('aria-haspopup', 'dialog');
  form.insertBefore(button, input);

  const panel = document.createElement('section');
  panel.id = 'chatToolsPanel';
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Chat tools');
  const header = document.createElement('header');
  const title = document.createElement('strong');
  title.textContent = 'Chat tools';
  const close = document.createElement('button');
  close.type = 'button';
  close.textContent = '×';
  close.setAttribute('aria-label', 'Close chat tools');
  header.append(title, close);
  const content = document.createElement('div');
  content.id = 'chatToolsContent';
  header.className = 'chat-tools-header';
  panel.append(header, content);
  shell.appendChild(panel);

  function setOpen(open, restoreFocus = false) {
    panel.hidden = !open;
    button.setAttribute('aria-expanded', String(open));
    if (open) close.focus();
    else if (restoreFocus) button.focus();
  }

  // These modules retain their event handlers, selected scope and review state.
  // Observe direct children only: moving a panel must not rebuild any module.
  const selector = '.chat-knowledge-scope-v162,.vp3-agent-teams-a3,.vp3-chat-task-mode,' +
    '.project-knowledge-r5,.project-knowledge-r6,.project-knowledge-r7,.knowledge-actions-r8,' +
    '.vp3-chat-research';
  function dockTools() {
    for (const child of [...shell.children]) {
      if (child.matches(selector)) content.appendChild(child);
    }
  }
  dockTools();
  const observer = new MutationObserver(dockTools);
  observer.observe(shell, {childList: true});

  button.addEventListener('click', () => setOpen(panel.hidden));
  close.addEventListener('click', () => setOpen(false, true));
  document.addEventListener('pointerdown', event => {
    if (!panel.hidden && !panel.contains(event.target) && !button.contains(event.target)) setOpen(false);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !panel.hidden) {
      event.preventDefault();
      setOpen(false, true);
    }
  });
  form.addEventListener('submit', () => {
    // Explicit Chrome research presents its consent review inside these tools.
    setOpen(document.getElementById('vp3ChatTaskMode')?.value === 'chrome');
  }, true);
  window.addEventListener('vp3:chat-route', event => {
    if (['review', 'failed'].includes(event.detail?.state)) setOpen(true);
  });
  window.addEventListener('pagehide', () => observer.disconnect(), {once: true});
})();
