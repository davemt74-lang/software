(() => {
  const openSidebar =
    document.getElementById('openChatSidebar');
  const closeSidebar =
    document.getElementById('closeChatSidebar');
  const backdrop =
    document.getElementById('chatSidebarBackdrop');
  const notificationMenu =
    document.getElementById('chatNotificationMenu');
  const notificationButton =
    document.getElementById('chatNotificationButton');
  const notificationDropdown =
    document.getElementById('chatNotificationDropdown');
  const profileMenu =
    document.getElementById('chatProfileMenu');
  const profileButton =
    document.getElementById('chatProfileButton');
  const profileDropdown =
    document.getElementById('chatProfileDropdown');

  function closeNav() {
    document.body.classList.remove(
      'chat-nav-open'
    );
  }

  function closeNotifications() {
    if (
      !notificationButton ||
      !notificationDropdown
    ) {
      return;
    }

    notificationDropdown.hidden = true;
    notificationButton.setAttribute(
      'aria-expanded',
      'false'
    );
  }

  function closeProfile() {
    if (!profileButton || !profileDropdown) {
      return;
    }

    profileDropdown.hidden = true;
    profileButton.setAttribute(
      'aria-expanded',
      'false'
    );
  }

  openSidebar?.addEventListener(
    'click',
    () => {
      closeNotifications();
      closeProfile();
      document.body.classList.add(
        'chat-nav-open'
      );
    }
  );

  closeSidebar?.addEventListener(
    'click',
    closeNav
  );

  backdrop?.addEventListener(
    'click',
    closeNav
  );

  notificationButton?.addEventListener(
    'click',
    event => {
      event.stopPropagation();
      closeProfile();

      const opening =
        notificationDropdown?.hidden !== false;

      if (notificationDropdown) {
        notificationDropdown.hidden =
          !opening;
      }

      notificationButton.setAttribute(
        'aria-expanded',
        opening ? 'true' : 'false'
      );
    }
  );

  profileButton?.addEventListener(
    'click',
    event => {
      event.stopPropagation();
      closeNotifications();

      const opening =
        profileDropdown?.hidden !== false;

      if (profileDropdown) {
        profileDropdown.hidden =
          !opening;
      }

      profileButton.setAttribute(
        'aria-expanded',
        opening ? 'true' : 'false'
      );
    }
  );

  notificationDropdown?.addEventListener(
    'click',
    event => event.stopPropagation()
  );

  profileDropdown?.addEventListener(
    'click',
    event => event.stopPropagation()
  );

  document.addEventListener(
    'click',
    event => {
      if (
        notificationMenu &&
        !notificationMenu.contains(event.target)
      ) {
        closeNotifications();
      }

      if (
        profileMenu &&
        !profileMenu.contains(event.target)
      ) {
        closeProfile();
      }
    }
  );

  document.addEventListener(
    'keydown',
    event => {
      if (event.key !== 'Escape') {
        return;
      }

      closeNav();
      closeNotifications();
      closeProfile();
    }
  );
})();
