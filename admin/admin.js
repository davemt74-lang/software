(() => {
  'use strict';

  const body = document.body;
  const NAV_STORAGE_PREFIX = 'vp3:admin-nav:';
  let lastModalTrigger = null;

  const openSidebar = document.getElementById('adminMenuToggle');
  const closeSidebar = document.getElementById('adminSidebarClose');
  const sidebar = document.getElementById('adminSidebar');
  const backdrop = document.getElementById('adminSidebarBackdrop');
  const userMenu = document.getElementById('adminUserMenu');
  const userButton = document.getElementById('adminUserMenuButton');
  const userDropdown = document.getElementById('adminUserDropdown');
  const mobileUserButton = document.getElementById('adminMobileUserButton');
  const notificationMenu = document.getElementById('adminNotificationMenu');
  const notificationButton = document.getElementById('adminNotificationButton');
  const notificationDropdown = document.getElementById('adminNotificationDropdown');

  const safeStorageGet = key => {
    try { return window.localStorage.getItem(key); } catch (_error) { return null; }
  };
  const safeStorageSet = (key, value) => {
    try { window.localStorage.setItem(key, value); } catch (_error) {}
  };

  function closeSidebarMenu() {
    body.classList.remove('admin-sidebar-open');
    openSidebar?.setAttribute('aria-expanded', 'false');
  }
  function openSidebarMenu() {
    closeUserMenu();
    closeNotificationMenu();
    body.classList.add('admin-sidebar-open');
    openSidebar?.setAttribute('aria-expanded', 'true');
  }
  function closeNotificationMenu() {
    if (!notificationButton || !notificationDropdown) return;
    notificationButton.setAttribute('aria-expanded', 'false');
    notificationDropdown.hidden = true;
  }
  function closeUserMenu() {
    if (!userButton || !userDropdown) return;
    userButton.setAttribute('aria-expanded', 'false');
    userDropdown.hidden = true;
  }
  function toggleUserMenu() {
    if (!userButton || !userDropdown) return;
    closeSidebarMenu();
    closeNotificationMenu();
    const opening = userDropdown.hidden;
    userDropdown.hidden = !opening;
    userButton.setAttribute('aria-expanded', String(opening));
  }

  function setNavGroup(group, open, persist = false) {
    const key = String(group.dataset.adminNavGroup || '');
    const toggle = group.querySelector('.admin-nav-group-toggle');
    const links = group.querySelector('.admin-nav-group-links');
    if (!toggle || !links) return;
    toggle.setAttribute('aria-expanded', String(open));
    links.hidden = !open;
    group.classList.toggle('is-open', open);
    if (persist && key) safeStorageSet(NAV_STORAGE_PREFIX + key, open ? 'open' : 'closed');
  }

  document.querySelectorAll('[data-admin-nav-group]').forEach(group => {
    const key = String(group.dataset.adminNavGroup || '');
    const saved = key ? safeStorageGet(NAV_STORAGE_PREFIX + key) : null;
    const defaultOpen = String(group.dataset.adminNavDefault || 'open') !== 'closed';
    const containsActive = !!group.querySelector('a.active');
    const open = containsActive ? true : (saved === 'open' ? true : saved === 'closed' ? false : defaultOpen);
    setNavGroup(group, open, false);
    group.querySelector('.admin-nav-group-toggle')?.addEventListener('click', () => {
      const expanded = group.querySelector('.admin-nav-group-toggle')?.getAttribute('aria-expanded') === 'true';
      setNavGroup(group, !expanded, true);
    });
  });

  // Legacy specialty links (currently MIDI) are still injected later by the
  // footer. Keep them inside the bottom Music accordion instead of allowing a
  // second uncategorized navigation layer to appear.
  const navigation = document.querySelector('.admin-navigation');
  const musicLinks = document.getElementById('admin-nav-music');
  if (navigation && musicLinks) {
    const relocateLegacyNav = () => {
      navigation.querySelectorAll(':scope > a[data-admin-midi-v217]').forEach(link => musicLinks.appendChild(link));
    };
    relocateLegacyNav();
    const observer = new MutationObserver(relocateLegacyNav);
    observer.observe(navigation, {childList:true});
    window.addEventListener('pagehide', () => observer.disconnect(), {once:true});
  }

  function openModal(modal, trigger = null) {
    if (!(modal instanceof HTMLElement)) return;
    closeUserMenu();
    closeNotificationMenu();
    lastModalTrigger = trigger instanceof HTMLElement ? trigger : document.activeElement;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    body.classList.add('admin-modal-open');
    requestAnimationFrame(() => {
      modal.classList.add('is-open');
      modal.querySelector('[autofocus], input:not([type="hidden"]), select, textarea, button')?.focus({preventScroll:true});
    });
  }

  function closeModal(modal) {
    if (!(modal instanceof HTMLElement) || modal.hidden) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    window.setTimeout(() => {
      modal.hidden = true;
      if (!document.querySelector('[data-admin-modal].is-open')) body.classList.remove('admin-modal-open');
      if (lastModalTrigger instanceof HTMLElement && lastModalTrigger.isConnected) lastModalTrigger.focus({preventScroll:true});
    }, 140);
  }

  document.addEventListener('click', event => {
    const open = event.target.closest('[data-admin-modal-open]');
    if (open) {
      const id = String(open.getAttribute('data-admin-modal-open') || '').replace(/^#/, '');
      const modal = id ? document.getElementById(id) : null;
      if (modal) {
        event.preventDefault();
        openModal(modal, open);
        return;
      }
    }
    const close = event.target.closest('[data-admin-modal-close]');
    if (close) {
      event.preventDefault();
      closeModal(close.closest('[data-admin-modal]'));
      return;
    }
    if (event.target.matches('[data-admin-modal]')) closeModal(event.target);
    if (userMenu && !userMenu.contains(event.target)) closeUserMenu();
    if (notificationMenu && !notificationMenu.contains(event.target)) closeNotificationMenu();
  });

  document.querySelectorAll('[data-admin-modal][data-admin-modal-auto-open="1"]').forEach(modal => openModal(modal));

  openSidebar?.addEventListener('click', openSidebarMenu);
  closeSidebar?.addEventListener('click', closeSidebarMenu);
  backdrop?.addEventListener('click', closeSidebarMenu);
  sidebar?.querySelectorAll('a').forEach(link => link.addEventListener('click', closeSidebarMenu));
  userButton?.addEventListener('click', event => { event.stopPropagation(); toggleUserMenu(); });
  userDropdown?.addEventListener('click', event => event.stopPropagation());
  mobileUserButton?.addEventListener('click', () => { window.scrollTo({top:0, behavior:'smooth'}); window.setTimeout(toggleUserMenu, 120); });
  notificationButton?.addEventListener('click', event => {
    event.stopPropagation();
    const opening = !!notificationDropdown?.hidden;
    closeSidebarMenu();
    closeUserMenu();
    if (!notificationDropdown) return;
    notificationDropdown.hidden = !opening;
    notificationButton.setAttribute('aria-expanded', String(opening));
  });
  notificationDropdown?.addEventListener('click', event => event.stopPropagation());

  document.querySelectorAll('.notice:not(.error)').forEach(notice => {
    const delay = Math.max(800, Math.min(10000, Number(notice.dataset.autoDismiss || 2600)));
    const dismiss = () => {
      if (!notice.isConnected || notice.dataset.dismissed === '1') return;
      notice.dataset.dismissed = '1';
      notice.classList.add('notice-leaving');
      window.setTimeout(() => notice.remove(), 260);
    };
    window.setTimeout(dismiss, delay);
    notice.addEventListener('click', dismiss, {once:true});
  });

  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const modal = document.querySelector('[data-admin-modal].is-open');
    if (modal) { closeModal(modal); return; }
    closeSidebarMenu();
    closeUserMenu();
    closeNotificationMenu();
  });
  window.addEventListener('resize', () => { if (window.innerWidth > 900) closeSidebarMenu(); });

  window.VP3AdminUI = {openModal, closeModal};
})();