(() => {
  'use strict';

  const BUILD = 'agent-updates-hidden-v206-20260901';

  const removeNodes = selector => {
    document.querySelectorAll(selector).forEach(node => node.remove());
  };

  const syncArtistProfileLink = rawUrl => {
    const href = String(rawUrl || '').trim();

    document.querySelectorAll('.chat-profile-links').forEach(nav => {
      let link = nav.querySelector('[data-chat-artist-profile-link]');

      if (!link) {
        link = Array.from(nav.querySelectorAll('a')).find(node =>
          String(node.textContent || '').includes('View Artist Profile')
        ) || null;
      }

      if (!href) {
        if (link && link.dataset.chatArtistProfileLink === 'runtime') {
          link.remove();
        }
        return;
      }

      if (link) {
        link.href = href;
        link.dataset.chatArtistProfileLink =
          link.dataset.chatArtistProfileLink || 'server';
        return;
      }

      link = document.createElement('a');
      link.href = href;
      link.dataset.chatArtistProfileLink = 'runtime';

      const label = document.createElement('span');
      label.textContent = 'View Artist Profile';

      const arrow = document.createElement('span');
      arrow.textContent = '↗';

      link.append(label, arrow);

      const adminLink = Array.from(nav.querySelectorAll('a')).find(node =>
        String(node.textContent || '').includes('Admin Dashboard')
      ) || null;
      const logoutLink = nav.querySelector('a.logout');

      nav.insertBefore(link, adminLink || logoutLink || null);
    });
  };

  const syncPermissionUi = async () => {
    try {
      const response = await fetch('api/ui-permissions-v187.php', {
        credentials:'same-origin',
        cache:'no-store',
        headers:{Accept:'application/json'},
      });
      const data = await response.json().catch(() => null);

      if (!response.ok || !data || data.ok !== true) return;

      syncArtistProfileLink(data.artist_profile_url);

      const create = data.create && typeof data.create === 'object'
        ? data.create
        : {};

      document.querySelectorAll('[data-chat-create-type]').forEach(node => {
        const type = String(node.getAttribute('data-chat-create-type') || '');
        if (
          Object.prototype.hasOwnProperty.call(create, type) &&
          create[type] !== true
        ) {
          node.remove();
        }
      });

      document.querySelectorAll('[data-chat-create-form]').forEach(node => {
        const type = String(node.getAttribute('data-chat-create-form') || '');
        if (
          Object.prototype.hasOwnProperty.call(create, type) &&
          create[type] !== true
        ) {
          node.remove();
        }
      });

      if (data.playlists_manage !== true) {
        removeNodes('[data-edit-playlist],[data-duplicate-playlist]');
        document.querySelectorAll('.chat-canvas-empty').forEach(node => {
          if (node.textContent.includes('Use + Add Playlist')) {
            node.textContent = 'No playlists are available to this account.';
          }
        });
      }

      if (data.artist_listening_access !== true) {
        removeNodes('.chat-sidebar-recordings-link,#chatRecordingsCanvas');
      }

      const createMenu = document.getElementById('chatCreateMenu');
      const createDropdown = document.getElementById('chatCreateDropdown');
      const createModal = document.getElementById('chatCreateModal');
      const remainingCreateButtons = createDropdown
        ? createDropdown.querySelectorAll('[data-chat-create-type]').length
        : 0;

      if (createMenu && remainingCreateButtons < 1) {
        createMenu.remove();
      }

      if (
        createModal &&
        createModal.querySelectorAll('[data-chat-create-form]').length < 1
      ) {
        createModal.remove();
      }
    } catch (error) {
      // Server-rendered permission checks remain authoritative if this sync fails.
    }
  };

  const removeAgentUpdateOverlays = () => {
    removeNodes(
      '#chatLiveUpdates,.chat-live-updates,.agent-update-overlay,.agent-updates-overlay'
    );
  };

  // In-chat Agent Update overlays were redundant with Stonefellow's main
  // notification center. Keep that main notification system untouched and
  // remove only the transient Chat overlay surface.
  removeAgentUpdateOverlays();
  syncPermissionUi();

  const observer = new MutationObserver(records => {
    if (!records.some(record => record.addedNodes.length > 0)) return;
    removeAgentUpdateOverlays();
  });

  observer.observe(document.documentElement, {
    childList:true,
    subtree:true,
  });

  window.addEventListener('pagehide', () => {
    observer.disconnect();
  }, {once:true});

  window.STONEFELLOW_AGENT_UPDATE_AUTODISMISS_V173 = {
    build:BUILD,
    overlaysDisabled:true,
    removeAgentUpdateOverlays,
    syncPermissionUi,
    syncArtistProfileLink,
  };
})();
