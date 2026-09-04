(() => {
  const body = document.body;

  if (!document.querySelector('link[data-admin-tech-theme]')) {
    const theme = document.createElement('link');
    theme.rel = 'stylesheet';
    theme.href = new URL('./admin-tech.css?v=admin-tech-20260903', window.location.href).href;
    theme.dataset.adminTechTheme = '1';
    document.head.appendChild(theme);
  }

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

  function closeSidebarMenu() {
    body.classList.remove('admin-sidebar-open');
    if (openSidebar) openSidebar.setAttribute('aria-expanded', 'false');
  }

  function openSidebarMenu() {
    closeUserMenu();
    closeNotificationMenu();
    body.classList.add('admin-sidebar-open');
    if (openSidebar) openSidebar.setAttribute('aria-expanded', 'true');
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
    const opening = userDropdown.hidden;
    userDropdown.hidden = !opening;
    userButton.setAttribute('aria-expanded', String(opening));
  }

  if (openSidebar) openSidebar.addEventListener('click', openSidebarMenu);
  if (closeSidebar) closeSidebar.addEventListener('click', closeSidebarMenu);
  if (backdrop) backdrop.addEventListener('click', closeSidebarMenu);

  if (sidebar) {
    sidebar.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', closeSidebarMenu);
    });
  }

  if (userButton) {
    userButton.addEventListener('click', event => {
      event.stopPropagation();
      toggleUserMenu();
    });
  }

  if (userDropdown) {
    userDropdown.addEventListener('click', event => event.stopPropagation());
  }

  if (mobileUserButton) {
    mobileUserButton.addEventListener('click', () => {
      window.scrollTo({top:0, behavior:'smooth'});
      setTimeout(toggleUserMenu, 120);
    });
  }

  if (notificationButton && notificationDropdown) {
    notificationButton.addEventListener('click', event => {
      event.stopPropagation();
      const opening = notificationDropdown.hidden;
      closeSidebarMenu();
      closeUserMenu();

      if (opening) {
        notificationDropdown.hidden = false;
        notificationButton.setAttribute('aria-expanded', 'true');
      } else {
        closeNotificationMenu();
      }
    });

    notificationDropdown.addEventListener('click', event => event.stopPropagation());
  }

  document.addEventListener('click', event => {
    if (userMenu && !userMenu.contains(event.target)) closeUserMenu();
    if (notificationMenu && !notificationMenu.contains(event.target)) closeNotificationMenu();
  });

  document.querySelectorAll('.notice:not(.error)').forEach(notice => {
    notice.classList.add('notice-auto-dismiss');

    const delay = Math.max(
      800,
      Math.min(
        10000,
        Number(notice.dataset.autoDismiss || 2600)
      )
    );

    const dismiss = () => {
      if (!notice.isConnected || notice.dataset.dismissed === '1') return;
      notice.dataset.dismissed = '1';
      notice.classList.add('notice-leaving');
      window.setTimeout(() => notice.remove(), 260);
    };

    window.setTimeout(dismiss,delay);
    notice.addEventListener('click',dismiss,{once:true});
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      closeSidebarMenu();
      closeUserMenu();
      closeNotificationMenu();
    }
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 900) closeSidebarMenu();
  });
})();