(() => {
  const build='white-tech-20260904';
  const openSidebar = document.getElementById('openChatSidebar');
  const closeSidebar = document.getElementById('closeChatSidebar');
  const backdrop = document.getElementById('chatSidebarBackdrop');
  const notificationMenu = document.getElementById('chatNotificationMenu');
  const notificationButton = document.getElementById('chatNotificationButton');
  const notificationDropdown = document.getElementById('chatNotificationDropdown');
  const profileMenu = document.getElementById('chatProfileMenu');
  const profileButton = document.getElementById('chatProfileButton');
  const profileDropdown = document.getElementById('chatProfileDropdown');

  if((notificationMenu||profileMenu)&&!document.querySelector('link[data-member-header-ui]')){
    const css=document.createElement('link');css.rel='stylesheet';css.dataset.memberHeaderUi='1';css.href=new URL(`./chat-header-ui.css?v=${build}`,window.location.href).href;document.head.appendChild(css);
  }

  function closeNav() { document.body.classList.remove('chat-nav-open'); }
  function closeNotifications() {
    if (!notificationButton || !notificationDropdown) return;
    notificationDropdown.hidden = true;
    notificationButton.setAttribute('aria-expanded','false');
  }
  function closeProfile() {
    if (!profileButton || !profileDropdown) return;
    profileDropdown.hidden = true;
    profileButton.setAttribute('aria-expanded','false');
  }

  openSidebar?.addEventListener('click',() => { closeNotifications(); closeProfile(); document.body.classList.add('chat-nav-open'); });
  closeSidebar?.addEventListener('click',closeNav);
  backdrop?.addEventListener('click',closeNav);

  notificationButton?.addEventListener('click',event => {
    event.stopPropagation();closeProfile();
    const opening = notificationDropdown?.hidden !== false;
    if (notificationDropdown) notificationDropdown.hidden = !opening;
    notificationButton.setAttribute('aria-expanded',opening ? 'true' : 'false');
  });
  profileButton?.addEventListener('click',event => {
    event.stopPropagation();closeNotifications();
    const opening = profileDropdown?.hidden !== false;
    if (profileDropdown) profileDropdown.hidden = !opening;
    profileButton.setAttribute('aria-expanded',opening ? 'true' : 'false');
  });
  notificationDropdown?.addEventListener('click',event => event.stopPropagation());
  profileDropdown?.addEventListener('click',event => event.stopPropagation());

  document.addEventListener('click',event => {
    if (notificationMenu && !notificationMenu.contains(event.target)) closeNotifications();
    if (profileMenu && !profileMenu.contains(event.target)) closeProfile();
  });
  document.addEventListener('keydown',event => {
    if (event.key !== 'Escape') return;
    closeNav();closeNotifications();closeProfile();
  });

  if (document.querySelector('.account-canvas-content')) {
    if(!document.querySelector('[data-account-agent-settings-loader]')){
      const loader = document.createElement('script');
      loader.src = new URL(`./account-agent-settings-loader-v236.js?v=${build}`,window.location.href).href;
      loader.dataset.accountAgentSettingsLoader = 'member-shell';
      document.body.appendChild(loader);
    }
  }
})();
