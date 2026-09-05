(() => {
  const build='profile-activity-20260905';
  const openSidebar = document.getElementById('openChatSidebar');
  const closeSidebar = document.getElementById('closeChatSidebar');
  const backdrop = document.getElementById('chatSidebarBackdrop');
  const notificationMenu = document.getElementById('chatNotificationMenu');
  const notificationButton = document.getElementById('chatNotificationButton');
  const notificationDropdown = document.getElementById('chatNotificationDropdown');
  const profileMenu = document.getElementById('chatProfileMenu');
  const profileButton = document.getElementById('chatProfileButton');
  const profileDropdown = document.getElementById('chatProfileDropdown');
  let notificationTimer=0;

  if((notificationMenu||profileMenu)&&!document.querySelector('link[data-member-header-ui]')){
    const css=document.createElement('link');css.rel='stylesheet';css.dataset.memberHeaderUi='1';css.href=new URL(`./chat-header-ui.css?v=${build}`,window.location.href).href;document.head.appendChild(css);
  }

  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
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
  function renderNotificationState(data){
    if(!notificationButton||!notificationDropdown||!data?.ok)return;
    const unread=Number(data.unread||0);let badge=notificationButton.querySelector(':scope > span');
    if(unread>0){if(!badge){badge=document.createElement('span');notificationButton.appendChild(badge);}badge.textContent=unread>99?'99+':String(unread);}else badge?.remove();
    const head=notificationDropdown.querySelector('header span');if(head)head.textContent=`${unread} unread`;
    const list=notificationDropdown.querySelector('.chat-notification-dropdown-list');if(!list)return;
    const items=Array.isArray(data.items)?data.items:[];
    list.innerHTML=items.length?items.map(item=>`<a class="${item.is_read?'':'unread'}" href="${esc(item.open_url)}"><span class="chat-dropdown-dot"></span><span><strong>${esc(item.title)}</strong><small>${esc(item.body)}</small></span></a>`).join(''):'<div class="chat-dropdown-empty">No notifications yet.</div>';
  }
  async function refreshNotifications(){
    if(!notificationButton||document.visibilityState!=='visible')return;
    try{const endpoint=new URL('./api/member-notifications.php',window.location.href).pathname,r=await fetch(endpoint,{credentials:'same-origin',cache:'no-store'}),d=await r.json().catch(()=>null);if(r.ok)renderNotificationState(d);}catch(_e){}
  }

  openSidebar?.addEventListener('click',() => { closeNotifications(); closeProfile(); document.body.classList.add('chat-nav-open'); });
  closeSidebar?.addEventListener('click',closeNav);
  backdrop?.addEventListener('click',closeNav);

  notificationButton?.addEventListener('click',event => {
    event.stopPropagation();closeProfile();
    const opening = notificationDropdown?.hidden !== false;
    if (notificationDropdown) notificationDropdown.hidden = !opening;
    notificationButton.setAttribute('aria-expanded',opening ? 'true' : 'false');
    if(opening)refreshNotifications();
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

  if(notificationButton){refreshNotifications();notificationTimer=window.setInterval(refreshNotifications,15000);window.addEventListener('pagehide',()=>clearInterval(notificationTimer),{once:true});}
})();