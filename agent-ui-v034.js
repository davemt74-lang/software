(() => {
  'use strict';

  const sidebar = document.getElementById('chatSidebar');
  if (!sidebar) return;

  const history = document.getElementById('chatHistory');
  const userMenuButton = document.getElementById('vp3AgentUserMenuButton');
  const userMenu = document.getElementById('vp3AgentUserMenu');
  const runtimeSource = document.getElementById('vp3AgentRuntimeSource');
  const runtimeModel = document.getElementById('vp3AgentRuntimeModel');
  const runtimeUsage = document.getElementById('vp3AgentRuntimeUsage');
  const runtimeStrip = document.getElementById('vp3AgentRuntimeStrip');
  const runtimeUrl = sidebar.dataset.runtimeUrl || '';
  const renameUrl = sidebar.dataset.renameUrl || '';
  const csrf = sidebar.dataset.csrf || '';
  let runtimeTimer = null;
  let runtimeBusy = false;

  function closeUserMenu() {
    if (!userMenu || !userMenuButton) return;
    userMenu.hidden = true;
    userMenuButton.setAttribute('aria-expanded', 'false');
  }

  userMenuButton?.addEventListener('click', event => {
    event.stopPropagation();
    const opening = userMenu?.hidden !== false;
    if (userMenu) userMenu.hidden = !opening;
    userMenuButton.setAttribute('aria-expanded', opening ? 'true' : 'false');
  });
  userMenu?.addEventListener('click', event => event.stopPropagation());
  document.addEventListener('click', closeUserMenu);
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeUserMenu();
  });

  function decorateHistoryRow(row) {
    if (!(row instanceof HTMLElement) || row.dataset.agentUiV034 === '1') return;
    row.dataset.agentUiV034 = '1';
    const chatButton = row.querySelector('.chat-history-item');
    const deleteButton = row.querySelector('[data-delete-conversation]');
    const conversationId = Number(chatButton?.dataset.conversationId || row.dataset.conversationRow || 0);
    if (!conversationId || !chatButton) return;

    if (!row.querySelector('[data-rename-conversation]')) {
      const rename = document.createElement('button');
      rename.type = 'button';
      rename.className = 'chat-history-rename';
      rename.dataset.renameConversation = String(conversationId);
      rename.setAttribute('aria-label', 'Rename chat');
      rename.title = 'Rename chat';
      rename.textContent = '⋯';
      if (deleteButton) row.insertBefore(rename, deleteButton);
      else row.appendChild(rename);
    }
  }

  function decorateHistory() {
    history?.querySelectorAll('.chat-history-row').forEach(decorateHistoryRow);
  }

  decorateHistory();
  if (history) {
    const observer = new MutationObserver(decorateHistory);
    observer.observe(history, { childList: true, subtree: true });
  }

  async function saveRename(row, input, originalTitle) {
    const id = Number(input.dataset.conversationId || 0);
    const title = input.value.replace(/\s+/g, ' ').trim();
    if (!id || !title || !renameUrl) {
      cancelRename(row, input, originalTitle);
      return;
    }

    input.disabled = true;
    try {
      const response = await fetch(renameUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ conversation_id: id, title, csrf_token: csrf })
      });
      const data = await response.json().catch(() => ({ ok: false, error: 'Invalid server response.' }));
      if (!response.ok || !data.ok) throw new Error(data.error || 'Could not rename chat.');
      const span = row.querySelector('.chat-history-item span');
      if (span) span.textContent = data.title || title;
      input.remove();
      row.classList.remove('is-renaming');
    } catch (error) {
      input.disabled = false;
      input.setCustomValidity(error instanceof Error ? error.message : 'Could not rename chat.');
      input.reportValidity();
      input.setCustomValidity('');
      input.focus();
      input.select();
    }
  }

  function cancelRename(row, input, originalTitle) {
    const span = row.querySelector('.chat-history-item span');
    if (span) {
      span.textContent = originalTitle;
      span.hidden = false;
    }
    input.remove();
    row.classList.remove('is-renaming');
  }

  function beginRename(button) {
    const row = button.closest('.chat-history-row');
    const span = row?.querySelector('.chat-history-item span');
    const id = Number(button.dataset.renameConversation || 0);
    if (!row || !span || !id || row.classList.contains('is-renaming')) return;

    const originalTitle = span.textContent?.trim() || 'Untitled chat';
    const input = document.createElement('input');
    input.className = 'chat-history-rename-input';
    input.type = 'text';
    input.maxLength = 120;
    input.value = originalTitle;
    input.dataset.conversationId = String(id);
    input.setAttribute('aria-label', 'Chat name');
    span.hidden = true;
    span.insertAdjacentElement('afterend', input);
    row.classList.add('is-renaming');
    input.focus();
    input.select();

    input.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        saveRename(row, input, originalTitle);
      } else if (event.key === 'Escape') {
        event.preventDefault();
        cancelRename(row, input, originalTitle);
      }
    });
    input.addEventListener('blur', () => {
      if (document.body.contains(input) && !input.disabled) cancelRename(row, input, originalTitle);
    });
  }

  history?.addEventListener('click', event => {
    const rename = event.target.closest('[data-rename-conversation]');
    if (!rename) return;
    event.preventDefault();
    event.stopPropagation();
    beginRename(rename);
  }, true);

  function activeBrainName() {
    const identity = window.STONEFELLOW_AGENT_IDENTITY_V236 || {};
    return String(identity.displayName || identity.systemName || 'Agent');
  }

  async function refreshRuntime() {
    if (!runtimeUrl || runtimeBusy || document.hidden) return;
    runtimeBusy = true;
    try {
      const response = await fetch(runtimeUrl, { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok) return;
      const latest = data.latest || {};
      const month = data.month || {};
      if (runtimeSource) {
        runtimeSource.textContent = latest.source_label || 'No AI run yet';
        runtimeSource.dataset.source = latest.source || '';
        runtimeSource.title = latest.fallback_used
          ? `Fallback used${latest.failure_class && latest.failure_class !== 'none' ? ` · ${latest.failure_class}` : ''}`
          : 'Latest execution source';
      }
      if (runtimeModel) {
        const model = [latest.provider, latest.model].filter(Boolean).join(' · ');
        runtimeModel.textContent = model || activeBrainName();
        runtimeModel.title = model ? `${activeBrainName()} · ${model}` : activeBrainName();
      }
      if (runtimeUsage) {
        runtimeUsage.textContent = `${Number(month.cloud_tokens || 0).toLocaleString()} cloud · ${Number(month.local_requests || 0).toLocaleString()} local`;
        runtimeUsage.title = `${Number(month.requests || 0).toLocaleString()} AI runs this month · estimated cost ${month.estimated_cost || '—'}`;
      }
      runtimeStrip?.setAttribute('data-ready', '1');
    } catch (error) {
      // Runtime telemetry must never interrupt Agent Chat.
    } finally {
      runtimeBusy = false;
    }
  }

  refreshRuntime();
  runtimeTimer = window.setInterval(refreshRuntime, 15000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshRuntime();
  });

  const thread = document.getElementById('chatThread');
  if (thread) {
    let refreshDelay = null;
    const observer = new MutationObserver(() => {
      window.clearTimeout(refreshDelay);
      refreshDelay = window.setTimeout(refreshRuntime, 500);
    });
    observer.observe(thread, { childList: true, subtree: true });
  }

  window.addEventListener('pagehide', () => {
    if (runtimeTimer) window.clearInterval(runtimeTimer);
  }, { once: true });
})();
