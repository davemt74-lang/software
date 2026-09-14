(() => {
  'use strict';

  const desktopMenus = Array.from(document.querySelectorAll('.mega-nav > details'));
  const mobileMenu = document.querySelector('.mobile-menu');

  const closeDesktopMenus = (except = null) => {
    desktopMenus.forEach((menu) => {
      if (menu !== except) menu.open = false;
    });
  };

  desktopMenus.forEach((menu) => {
    menu.addEventListener('toggle', () => {
      if (menu.open) closeDesktopMenus(menu);
    });
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('.mega-nav')) closeDesktopMenus();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    closeDesktopMenus();
    if (mobileMenu) mobileMenu.open = false;
  });

  if (mobileMenu) {
    mobileMenu.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        mobileMenu.open = false;
      });
    });
  }
})();
