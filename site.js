/* Stonefellow v13 */
(() => {
  const body = document.body;
  const toggle = document.getElementById('menuToggle');
  const backdrop = document.getElementById('mobileBackdrop');
  const mobileNav = document.getElementById('mobileNav');
  const userMenu = document.getElementById('userMenu');
  const userMenuButton = document.getElementById('userMenuButton');
  const userMenuDropdown = document.getElementById('userMenuDropdown');
  const notificationMenu = document.getElementById('notificationMenu');
  const notificationButton = document.getElementById('notificationButton');
  const notificationDropdown = document.getElementById('notificationDropdown');

  function closeNav() {
    body.classList.remove('nav-open');
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Open menu');
    }
  }

  function closeNotificationMenu() {
    if (!notificationButton || !notificationDropdown) return;
    notificationButton.setAttribute('aria-expanded', 'false');
    notificationDropdown.hidden = true;
  }

  function closeUserMenu() {
    if (!userMenuButton || !userMenuDropdown) return;
    userMenuButton.setAttribute('aria-expanded', 'false');
    userMenuDropdown.hidden = true;
  }

  if (toggle) {
    toggle.addEventListener('click', () => {
      closeUserMenu();
      closeNotificationMenu();
      const open = body.classList.toggle('nav-open');
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    });
  }

  if (backdrop) backdrop.addEventListener('click', closeNav);
  if (mobileNav) mobileNav.querySelectorAll('a').forEach(link => link.addEventListener('click', closeNav));

  if (userMenuButton && userMenuDropdown) {
    userMenuButton.addEventListener('click', event => {
      event.stopPropagation();
      closeNav();
      const opening = userMenuDropdown.hidden;
      userMenuDropdown.hidden = !opening;
      userMenuButton.setAttribute('aria-expanded', String(opening));
    });
    userMenuDropdown.addEventListener('click', event => event.stopPropagation());
    document.addEventListener('click', event => {
      if (userMenu && !userMenu.contains(event.target)) closeUserMenu();
      if (notificationMenu && !notificationMenu.contains(event.target)) closeNotificationMenu();
    });
  }


  if (notificationButton && notificationDropdown) {
    notificationButton.addEventListener('click', event => {
      event.stopPropagation();
      closeNav();
      closeUserMenu();
      closeNotificationMenu();
      const opening = notificationDropdown.hidden;
      notificationDropdown.hidden = !opening;
      notificationButton.setAttribute('aria-expanded', String(opening));
    });

    notificationDropdown.addEventListener('click', event => event.stopPropagation());
  }

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      closeNav();
      closeUserMenu();
      closeNotificationMenu();
    }
  });

  const year = document.getElementById('year');
  if (year) year.textContent = new Date().getFullYear();
})();