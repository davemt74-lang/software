// Sidebar links can outlive the conversation that was active at page load.
(() => {
  'use strict';
  const link = document.querySelector('[data-vp3-nav-key="phone_tracking"]');
  if (!link) return;
  const base = new URL(link.href);
  function preserveChat() {
    const runtime = window.STONEFELLOW_CHAT_CONTINUITY;
    const page = new URL(location.href).searchParams;
    const target = new URL(base);
    const agent = runtime?.agentId?.() ?? page.get('agent');
    const chat = runtime?.conversationId?.() ?? page.get('conversation_id');
    if (agent === 0 || agent === 'system') target.searchParams.set('agent', 'system');
    else if (/^[1-9]\d{0,18}$/.test(agent)) target.searchParams.set('agent', agent);
    if (/^[1-9]\d{0,18}$/.test(chat)) target.searchParams.set('conversation_id', chat);
    link.href = target.href;
  }
  for (const event of ['focus', 'pointerdown', 'click', 'contextmenu']) link.addEventListener(event, preserveChat);
})();
