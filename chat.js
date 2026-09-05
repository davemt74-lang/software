(() => {
  const cfg = window.STONEFELLOW_CHAT;
  const thread = document.getElementById('chatThread');
  const welcome = document.getElementById('chatWelcome');
  const form = document.getElementById('chatForm');
  const composerShell = document.getElementById('chatComposerShell');
  const input = document.getElementById('chatInput');
  const send = document.getElementById('sendChatButton');
  const voiceButton = document.getElementById('chatVoiceButton');
  const voiceStatus = document.getElementById('chatVoiceStatus');
  const history = document.getElementById('chatHistory');
  const newButton = document.getElementById('newChatButton');
  const playerSearch = document.getElementById('chatPlayerSearch');
  const favoriteCount = document.getElementById('chatFavoriteCount');
  const favoritesEmpty = document.getElementById('chatFavoritesEmpty');
  const savedSongsNav = document.getElementById('chatSavedSongsNav');
  const savedSongsCount = document.getElementById('chatSavedSongsCount');
  const savedSongsEmpty = document.getElementById('chatSavedSongsEmpty');
  const canvasViews = [
    ...document.querySelectorAll('[data-chat-view]')
  ];
  const viewButtons = [
    ...document.querySelectorAll('[data-chat-view-target]')
  ];
  const openSidebar = document.getElementById('openChatSidebar');
  const closeSidebar = document.getElementById('closeChatSidebar');
  const backdrop = document.getElementById('chatSidebarBackdrop');
  const notificationCount = document.getElementById('chatNotificationCount');
  const notificationUnreadLabel = document.getElementById('chatNotificationUnreadLabel');
  const createMenu = document.getElementById('chatCreateMenu');
  const createButton = document.getElementById('chatCreateButton');
  const createDropdown = document.getElementById('chatCreateDropdown');
  const createModal = document.getElementById('chatCreateModal');
  const createModalTitle = document.getElementById('chatCreateModalTitle');
  const createModalKicker = document.getElementById('chatCreateModalKicker');
  const createModalStatus = document.getElementById('chatCreateModalStatus');
  const createAdvanced = document.getElementById('chatCreateAdvanced');
  const createForms = [
    ...document.querySelectorAll('[data-chat-create-form]')
  ];
  const createTypeButtons = [
    ...document.querySelectorAll('[data-chat-create-type]')
  ];
  const notificationMenu = document.getElementById('chatNotificationMenu');
  const notificationButton = document.getElementById('chatNotificationButton');
  const notificationDropdown = document.getElementById('chatNotificationDropdown');
  const profileMenu = document.getElementById('chatProfileMenu');
  const profileButton = document.getElementById('chatProfileButton');
  const profileDropdown = document.getElementById('chatProfileDropdown');
  const nowPlaying = document.getElementById('chatNowPlaying');
  const nowPlayingCover = document.getElementById('chatNowPlayingCover');
  const nowPlayingQueue = document.getElementById('chatNowPlayingQueue');
  const nowPlayingTitle = document.getElementById('chatNowPlayingTitle');
  const nowPlayingAlbum = document.getElementById('chatNowPlayingAlbum');
  const nowPlayingPrev = document.getElementById('chatNowPlayingPrev');
  const nowPlayingToggle = document.getElementById('chatNowPlayingToggle');
  const nowPlayingNext = document.getElementById('chatNowPlayingNext');
  const nowPlayingSeek = document.getElementById('chatNowPlayingSeek');
  const nowPlayingCurrent = document.getElementById('chatNowPlayingCurrent');
  const nowPlayingDuration = document.getElementById('chatNowPlayingDuration');
  const nowPlayingVolumeButton = document.getElementById('chatNowPlayingVolumeButton');
  const nowPlayingVolumePopover = document.getElementById('chatNowPlayingVolumePopover');
  const nowPlayingVolume = document.getElementById('chatNowPlayingVolume');
  const PLAYER_VOLUME_KEY = `stonefellow:player:volume:${cfg.userId || 0}`;
  let playerVolume = 1;

  try {
    const storedVolume = Number(localStorage.getItem(PLAYER_VOLUME_KEY));
    if (Number.isFinite(storedVolume)) {
      playerVolume = Math.max(0, Math.min(1, storedVolume));
    }
  } catch (error) {}

  let conversationId = 0;
  let lastLoadedMessageId = 0;
  let busy = false;
  let voiceMode = false;
  let voiceRecognition = null;
  let voiceListening = false;
  let voiceSpeaking = false;
  let voiceSpeechResolve = null;
  let voiceBargeStream = null;
  let voiceBargeContext = null;
  let voiceBargeSource = null;
  let voiceBargeAnalyser = null;
  let voiceBargeTimer = null;
  let voiceBargeHits = 0;
  const SpeechRecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  let activityBusy = false;
  let activityTimer = null;
  let activityCursor = 0;
  let pendingConversationSync = 0;
  const audioState = new WeakMap();
  let activeAudio = null;

  function closeNav() {
    document.body.classList.remove('chat-nav-open');
  }

  const createTypeLabels = {
    track:{
      kicker:'Music',
      title:'Add Track'
    },
    album:{
      kicker:'Music Collection',
      title:'Add Album'
    },
    playlist:{
      kicker:'Your Library',
      title:'Add Playlist'
    },
    event:{
      kicker:'Live',
      title:'Add Event'
    },
    knowledge:{
      kicker:'Agent Knowledge',
      title:'Add Knowledge'
    },
    user:{
      kicker:'Account',
      title:'Add User'
    },
    merch:{
      kicker:'Store',
      title:'Add Merch'
    },
    post:{
      kicker:'Artist Updates',
      title:'Add Post'
    },
    photo:{
      kicker:'Visual Library',
      title:'Add Photo'
    }
  };

  function setCreateModalStatus(
    message = '',
    state = ''
  ) {
    if (!createModalStatus) {
      return;
    }

    createModalStatus.hidden =
      message === '';
    createModalStatus.textContent =
      message;
    createModalStatus.dataset.state =
      state;
  }

  function closeCreateModal() {
    if (!createModal) {
      return;
    }

    createModal.hidden = true;
    createModal.setAttribute(
      'aria-hidden',
      'true'
    );
    document.body.classList.remove(
      'chat-create-modal-open'
    );
    setCreateModalStatus();
  }

  function openCreateModal(
    type,
    adminUrl = ''
  ) {
    if (!createModal) {
      return;
    }

    const cleanType =
      String(type || '');
    const form =
      createForms.find(
        item =>
          item.dataset
            .chatCreateForm ===
          cleanType
      );

    if (!form) {
      if (adminUrl) {
        window.location.href =
          adminUrl;
      }
      return;
    }

    closeTopMenus();

    createForms.forEach(item => {
      item.hidden =
        item !== form;
    });

    const labels =
      createTypeLabels[
        cleanType
      ] || {
        kicker:'Create',
        title:'Add Content'
      };

    if (createModalKicker) {
      createModalKicker.textContent =
        labels.kicker;
    }

    if (createModalTitle) {
      createModalTitle.textContent =
        labels.title;
    }

    if (createAdvanced) {
      createAdvanced.href =
        adminUrl || '#';
      createAdvanced.textContent =
        'Full Editor ↗';
      createAdvanced.hidden =
        !adminUrl;
    }

    setCreateModalStatus();

    createModal.hidden = false;
    createModal.setAttribute(
      'aria-hidden',
      'false'
    );
    document.body.classList.add(
      'chat-create-modal-open'
    );

    window.setTimeout(
      () => {
        form.querySelector(
          'input:not([type="hidden"]), textarea, select'
        )?.focus();
      },
      30
    );
  }

  async function submitCreateForm(
    form
  ) {
    const submit =
      form.querySelector(
        'button[type="submit"]'
      );
    const originalText =
      submit?.textContent || 'Save';

    if (submit) {
      submit.disabled = true;
      submit.textContent =
        'Saving…';
    }

    setCreateModalStatus(
      'Saving…',
      'working'
    );

    try {
      const response =
        await fetch(
          cfg.createEndpoint,
          {
            method:'POST',
            credentials:'same-origin',
            body:new FormData(form)
          }
        );

      const data =
        await response.json()
          .catch(
            () => ({
              ok:false,
              message:
                'Invalid server response.'
            })
          );

      if (
        !response.ok ||
        !data.ok
      ) {
        throw new Error(
          data.message ||
          data.error ||
          'Could not create content.'
        );
      }

      setCreateModalStatus(
        data.message ||
        'Created.',
        'success'
      );

      form.reset();

      const type =
        String(
          data.type ||
          form.dataset.chatCreateForm ||
          ''
        );

      if (
        data.open_url &&
        createAdvanced
      ) {
        createAdvanced.href =
          String(data.open_url);
        createAdvanced.textContent =
          type === 'track'
            ? 'Open Stem Studio ↗'
            : type === 'album'
              ? 'Edit Album / Tracks ↗'
              : 'Open ↗';
        createAdvanced.hidden = false;
      }

      const refreshView =
        String(
          data.refresh_view ||
          (
            type === 'event'
              ? 'shows'
              : type === 'photo'
                ? 'photos'
                : type === 'merch'
                  ? 'merch'
                  : ''
          )
        );

      if (refreshView) {
        window.setTimeout(
          () => {
            const url =
              new URL(
                window.location.href
              );

            url.searchParams.set(
              'view',
              refreshView
            );

            window.location.href =
              url.toString();
          },
          650
        );
      }
    } catch (error) {
      setCreateModalStatus(
        error instanceof Error
          ? error.message
          : 'Could not create content.',
        'error'
      );
    } finally {
      if (submit) {
        submit.disabled = false;
        submit.textContent =
          originalText;
      }
    }
  }

  function closeCreateDropdown() {
    if (
      !createButton ||
      !createDropdown
    ) {
      return;
    }

    createDropdown.hidden = true;
    createButton.setAttribute(
      'aria-expanded',
      'false'
    );
  }

  function closeNotificationDropdown() {
    if (!notificationButton || !notificationDropdown) {
      return;
    }

    notificationDropdown.hidden = true;
    notificationButton.setAttribute(
      'aria-expanded',
      'false'
    );
  }

  function closeProfileDropdown() {
    if (!profileButton || !profileDropdown) {
      return;
    }

    profileDropdown.hidden = true;
    profileButton.setAttribute(
      'aria-expanded',
      'false'
    );
  }

  function closeTopMenus() {
    closeCreateDropdown();
    closeNotificationDropdown();
    closeProfileDropdown();
  }

  function setChatCanvasView(
    view,
    options = {}
  ) {
    const target =
      String(view || 'chat');
    const isChat =
      target === 'chat';

    thread.dataset.view =
      target;

    canvasViews.forEach(section => {
      section.hidden =
        section.dataset.chatView !==
        target;
    });

    if (composerShell) {
      composerShell.hidden = false;
    }

    viewButtons.forEach(button => {
      button.classList.toggle(
        'active',
        button.dataset.chatViewTarget ===
          target &&
        (
          target !== 'chat' ||
          options.newChat === true
        )
      );
    });

    if (!isChat) {
      closeTopMenus();
    }

    closeNav();
  }

  function beginNewChat() {
    conversationId = 0;
    lastLoadedMessageId = 0;

    thread.querySelectorAll(
      '.message'
    ).forEach(el => el.remove());

    if (welcome) {
      welcome.hidden = false;
    }

    document.querySelectorAll(
      '.chat-history-item'
    ).forEach(button => {
      button.classList.remove(
        'active'
      );
    });

    setChatCanvasView(
      'chat',
      {
        newChat:true
      }
    );

    input.focus();
  }

  if (openSidebar) {
    openSidebar.addEventListener('click', () => document.body.classList.add('chat-nav-open'));
  }
  if (closeSidebar) closeSidebar.addEventListener('click', closeNav);
  if (backdrop) backdrop.addEventListener('click', closeNav);

  viewButtons.forEach(button => {
    if (button === newButton) {
      return;
    }

    button.addEventListener(
      'click',
      () => {
        setChatCanvasView(
          button.dataset
            .chatViewTarget ||
          'chat'
        );
      }
    );
  });

  createTypeButtons.forEach(button => {
    button.addEventListener(
      'click',
      event => {
        event.preventDefault();
        event.stopPropagation();

        openCreateModal(
          button.dataset
            .chatCreateType,
          button.dataset
            .chatCreateAdminUrl ||
          ''
        );
      }
    );
  });

  document.querySelectorAll(
    '[data-close-chat-create]'
  ).forEach(button => {
    button.addEventListener(
      'click',
      closeCreateModal
    );
  });

  createForms.forEach(form => {
    form.addEventListener(
      'submit',
      event => {
        event.preventDefault();
        submitCreateForm(form);
      }
    );
  });

  createButton?.addEventListener(
    'click',
    event => {
      event.stopPropagation();
      closeNotificationDropdown();
      closeProfileDropdown();

      const opening =
        createDropdown?.hidden !== false;

      if (createDropdown) {
        createDropdown.hidden =
          !opening;
      }

      createButton.setAttribute(
        'aria-expanded',
        opening ? 'true' : 'false'
      );
    }
  );

  createDropdown?.addEventListener(
    'click',
    event => {
      event.stopPropagation();
    }
  );

  notificationButton?.addEventListener(
    'click',
    event => {
      event.stopPropagation();
      closeCreateDropdown();
      closeProfileDropdown();

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
      closeCreateDropdown();
      closeNotificationDropdown();

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
    event => {
      event.stopPropagation();
    }
  );

  profileDropdown?.addEventListener(
    'click',
    event => {
      event.stopPropagation();
    }
  );

  document.addEventListener(
    'click',
    event => {
      if (
        createMenu &&
        !createMenu.contains(
          event.target
        )
      ) {
        closeCreateDropdown();
      }

      if (
        notificationMenu &&
        !notificationMenu.contains(
          event.target
        )
      ) {
        closeNotificationDropdown();
      }

      if (
        profileMenu &&
        !profileMenu.contains(
          event.target
        )
      ) {
        closeProfileDropdown();
      }
    }
  );

  async function api(payload) {
    const response = await fetch(cfg.endpoint, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify({...payload, csrf_token: cfg.csrf})
    });

    const data = await response.json().catch(() => ({
      ok:false,
      error:'Invalid server response.'
    }));

    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'Request failed.');
    }

    return data;
  }

  function writeActivityCursor(value) {
    activityCursor = Math.max(
      activityCursor,
      Number(value || 0)
    );
  }

  function updateNotificationBadge(count) {
    if (!notificationCount) return;

    const clean = Math.max(
      0,
      Number(count || 0)
    );

    notificationCount.hidden =
      clean < 1;
    notificationCount.textContent =
      clean > 99
        ? '99+'
        : String(clean);

    if (notificationUnreadLabel) {
      notificationUnreadLabel.textContent =
        `${clean} unread`;
    }
  }

  function activityTypeLabel(type) {
    if (type === 'agent_supervisor_listen') {
      return 'Listening';
    }

    if (
      type === 'agent_track_share' ||
      type === 'producer_track_share'
    ) {
      return 'Producer';
    }

    if (type === 'stem_region_note' || type === 'production_note' || type === 'stem_work_update') {
      return 'Production Note';
    }

    if (
      type === 'new_track_release' ||
      type === 'new_album_release'
    ) {
      return 'New Release';
    }

    if (type === 'show_reminder') {
      return 'Show Reminder';
    }

    if (type === 'artist_post') {
      return 'Stonefellow Update';
    }

    return 'Update';
  }

  async function pollAgentActivity(
    force = false
  ) {
    if (
      activityBusy ||
      (
        document.hidden &&
        !force
      )
    ) {
      return;
    }

    activityBusy = true;

    if (liveStatus) {
      liveStatus.textContent =
        'Checking…';
    }

    try {
      const hadCursor = activityCursor > 0;
      const data = await api({
        action:'activity',
        after_id:activityCursor
      });

      if(hadCursor&&Number(data.conversation_id||0)===conversationId&&Number(data.latest_message_id||0)>lastLoadedMessageId){
        pendingConversationSync=conversationId;
      }
      if(pendingConversationSync>0&&!busy&&pendingConversationSync===conversationId&&Number(data.latest_message_id||0)>lastLoadedMessageId){
        await syncConversationMessagesV101(pendingConversationSync);
        pendingConversationSync=0;
      }

      const updateIds =
        Array.isArray(data.updates)
          ? data.updates.map(
              update =>
                Number(update.id || 0)
            )
          : [0];

      writeActivityCursor(
        Math.max(
          Number(data.latest_id || 0),
          ...updateIds
        )
      );

      updateNotificationBadge(
        data.unread_count
      );

      if (liveStatus) {
        liveStatus.textContent =
          'Live';
      }
    } catch (error) {
      if (liveStatus) {
        liveStatus.textContent =
          'Reconnecting…';
      }
    } finally {
      activityBusy = false;
    }
  }

  function startAgentActivityPolling() {
    /*
     * Start at zero on each Chat page load so the latest activity remains
     * visible as a small recent-history shelf. Subsequent polls are cursor
     * based and only fetch new events.
     */
    activityCursor = 0;

    pollAgentActivity(true);

    if (activityTimer) {
      clearInterval(activityTimer);
    }

    activityTimer = window.setInterval(
      () => {
        pollAgentActivity(false);
      },
      Math.max(
        2000,
        Number(
          cfg.activityPollMs ||
          3000
        )
      )
    );
  }

  async function favoriteApi(
    trackId,
    action = 'toggle'
  ) {
    const response = await fetch(
      cfg.favoriteEndpoint,
      {
        method:'POST',
        headers:{
          'Content-Type':
            'application/json',
          'Accept':
            'application/json'
        },
        credentials:'same-origin',
        body:JSON.stringify({
          action,
          track_id:Number(trackId || 0),
          csrf_token:cfg.csrf
        })
      }
    );

    const data =
      await response.json().catch(
        () => ({
          ok:false,
          error:'Favorite request failed.'
        })
      );

    if (
      !response.ok ||
      !data.ok
    ) {
      throw new Error(
        data.error ||
        'Favorite request failed.'
      );
    }

    return data;
  }

  function syncSavedSongsView(
    trackId,
    favorite
  ) {
    const id = String(
      Number(trackId || 0)
    );

    document.querySelectorAll(
      `[data-saved-card-track="${id}"]`
    ).forEach(card => {
      card.hidden = !favorite;
    });

    const visibleSavedSongs = [
      ...document.querySelectorAll(
        '[data-saved-card-track]'
      )
    ].filter(card => !card.hidden).length;

    if (savedSongsNav) {
      savedSongsNav.hidden =
        visibleSavedSongs < 1;
    }

    if (savedSongsCount) {
      savedSongsCount.textContent =
        `${visibleSavedSongs} saved`;
    }

    if (savedSongsEmpty) {
      savedSongsEmpty.hidden =
        visibleSavedSongs > 0;
    }

    if (
      visibleSavedSongs < 1 &&
      thread.dataset.view === 'saved'
    ) {
      setChatCanvasView('player');
    }
  }

  function syncFavoriteUi(
    trackId,
    favorite
  ) {
    const id =
      String(
        Number(trackId || 0)
      );

    document.querySelectorAll(
      `[data-favorite-track="${id}"]`
    ).forEach(button => {
      button.classList.toggle(
        'active',
        favorite
      );
      button.setAttribute(
        'aria-pressed',
        favorite
          ? 'true'
          : 'false'
      );
      button.textContent =
        favorite
          ? '♥'
          : '♡';
      button.title =
        favorite
          ? 'Remove favorite'
          : 'Favorite';
    });

    document.querySelectorAll(
      `[data-favorite-card-track="${id}"]`
    ).forEach(card => {
      card.hidden =
        !favorite;
    });

    const visibleFavorites = [
      ...document.querySelectorAll(
        '[data-favorite-card-track]'
      )
    ].filter(
      card => !card.hidden
    ).length;

    if (favoriteCount) {
      favoriteCount.textContent =
        `${visibleFavorites} saved`;
    }

    if (favoritesEmpty) {
      favoritesEmpty.hidden =
        visibleFavorites > 0;
    }

    syncSavedSongsView(
      trackId,
      favorite
    );
  }

  document.addEventListener(
    'click',
    async event => {
      const button =
        event.target.closest(
          '[data-favorite-track]'
        );

      if (!button) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      if (button.disabled) {
        return;
      }

      button.disabled = true;

      try {
        const data =
          await favoriteApi(
            button.dataset.favoriteTrack,
            'toggle'
          );

        syncFavoriteUi(
          data.track_id,
          data.favorite
        );
      } catch (error) {
        window.alert(
          error.message ||
          'Could not update favorite.'
        );
      } finally {
        button.disabled = false;
      }
    }
  );

  document.addEventListener(
    'click',
    event => {
      const button =
        event.target.closest(
          '[data-play-track]'
        );

      if (!button) {
        return;
      }

      const trackId =
        String(
          Number(
            button.dataset.playTrack ||
            0
          )
        );

      const candidates = [
        ...document.querySelectorAll(
          `.chat-audio-player[data-track-id="${trackId}"]`
        )
      ];

      let audio =
        candidates.find(candidate =>
          !candidate.closest('[hidden]')
        ) ||
        candidates[0];

      if (!audio && button.dataset.songAudio) {
        const host = document.createElement('div');
        host.hidden = true;
        audio = document.createElement('audio');
        audio.className = 'chat-audio-player';
        audio.preload = 'metadata';
        audio.src = String(button.dataset.songAudio || '');
        audio.dataset.trackId = trackId;
        audio.dataset.playerTitle = String(button.dataset.songTitle || 'Stonefellow');
        audio.dataset.playerAlbum = String(button.dataset.songAlbum || 'Stonefellow');
        audio.dataset.playerCover = String(button.dataset.songCover || '');
        audio.dataset.playerDetail = String(button.dataset.songDetail || '');
        host.appendChild(audio);
        document.body.appendChild(host);
        attachAudioTracking(host);
      }

      if (!audio) {
        return;
      }

      document.querySelectorAll('.chat-stem-preview').forEach(preview => preview.pause());
      audio.play().catch(
        () => {}
      );
    }
  );

  document.addEventListener('play', event => {
    const preview = event.target.closest?.('.chat-stem-preview');
    if (!preview) return;
    document.querySelectorAll('.chat-stem-preview').forEach(other => {
      if (other !== preview) other.pause();
    });
    document.querySelectorAll('.chat-audio-player').forEach(other => other.pause());
  }, true);

  playerSearch?.addEventListener(
    'input',
    () => {
      const query =
        playerSearch.value
          .trim()
          .toLowerCase();

      document.querySelectorAll(
        '[data-player-search-text]'
      ).forEach(card => {
        const text =
          String(
            card.dataset.playerSearchText ||
            ''
          );

        card.style.display =
          query === '' ||
          text.includes(query)
            ? ''
            : 'none';
      });
    }
  );

  async function playbackApi(payload, beacon = false) {
    if (!cfg.playbackEndpoint || !cfg.csrf) return null;
    const body = JSON.stringify({...payload, csrf_token: cfg.csrf});

    if (beacon && navigator.sendBeacon) {
      navigator.sendBeacon(
        cfg.playbackEndpoint,
        new Blob([body], {type:'application/json'})
      );
      return null;
    }

    try {
      const response = await fetch(cfg.playbackEndpoint, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        keepalive:true,
        body
      });
      return await response.json().catch(() => null);
    } catch (error) {
      return null;
    }
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#039;');
  }

  function sourceHtml(source) {
    const title = escapeHtml(source.title || source.source || 'Source');
    if (source.url) {
      return `<a class="message-source" href="${escapeHtml(source.url)}" target="_blank" rel="noopener noreferrer">${title} ↗</a>`;
    }
    return `<span class="message-source">${title}</span>`;
  }

  function currentStudioReturnTarget() {
    return `${window.location.pathname}${window.location.search}${window.location.hash}`;
  }

  function withStudioReturn(value) {
    const raw = String(value || '');
    if (!raw) return raw;
    try {
      const target = new URL(raw, window.location.origin);
      if (target.origin !== window.location.origin || !target.pathname.endsWith('/admin/stems.php')) {
        return raw;
      }
      target.searchParams.set('return', currentStudioReturnTarget());
      return `${target.pathname}${target.search}${target.hash}`;
    } catch (error) {
      return raw;
    }
  }

  function mediaHtml(media, index) {
    const meta = [
      media.genre,
      media.mood,
      media.energy
        ? `${media.energy} energy`
        : '',
      Number(media.tempo_bpm) > 0
        ? `${media.tempo_bpm} BPM`
        : '',
      media.duration || ''
    ].filter(Boolean).join(' · ');

    return `
      <article
        class="chat-audio-card"
        data-track-id="${Number(media.id)}"
        data-media-index="${index}"
      >
        <img
          class="chat-audio-cover"
          src="${escapeHtml(media.cover)}"
          alt=""
        >

        <div class="chat-audio-copy">
          <div class="chat-audio-title-row">
            <div>
              <strong>${escapeHtml(media.title)}</strong>
              <span>${escapeHtml(media.album || 'Stonefellow')}</span>
            </div>

            <a
              href="${escapeHtml(media.detail)}"
              aria-label="Open ${escapeHtml(media.title)} details"
            >↗</a>
          </div>

          ${meta
            ? `<p class="chat-audio-meta">${escapeHtml(meta)}</p>`
            : ''
          }

          ${media.description
            ? `<p class="chat-audio-description">${escapeHtml(media.description)}</p>`
            : ''
          }

          ${cfg.canStemStudio
            ? `
              <div class="chat-audio-actions">
                <a
                  class="chat-stem-studio"
                  href="${escapeHtml(withStudioReturn(`${cfg.stemStudioBase}?track=${Number(media.id)}`))}"
                >Stem Studio</a>
              </div>
            `
            : ''
          }

          <audio
            class="chat-audio-player"
            preload="metadata"
            src="${escapeHtml(media.audio)}"
            data-track-id="${Number(media.id)}"
            data-player-title="${escapeHtml(media.title)}"
            data-player-album="${escapeHtml(media.album || 'Stonefellow')}"
            data-player-cover="${escapeHtml(media.cover)}"
            data-player-detail="${escapeHtml(media.detail)}"
          ></audio>
        </div>
      </article>`;
  }


  function normalizeContext(raw) {
    if (!raw) {
      return {sources:[],media:[],stem_media:[],actions:[],playlist_title:''};
    }

    if (Array.isArray(raw)) {
      return {sources:raw,media:[],stem_media:[],actions:[],playlist_title:''};
    }

    return {
      sources:Array.isArray(raw.sources) ? raw.sources : [],
      media:Array.isArray(raw.media) ? raw.media : [],
      stem_media:Array.isArray(raw.stem_media) ? raw.stem_media : [],
      actions:Array.isArray(raw.actions) ? raw.actions : [],
      playlist_title:String(raw.playlist_title || '')
    };
  }

  function stemMediaHtml(item) {
    const meta=[item.role,item.genre,item.mood,Number(item.tempo_bpm)>0?`${item.tempo_bpm} BPM`:''].filter(Boolean).join(' · ');
    const song=String(item.song||'Untitled Song');
    const album=String(item.album||'');
    const studio=withStudioReturn(item.studio||'');
    const art=item.cover
      ? `<img src="${escapeHtml(item.cover)}" alt="" loading="lazy" onerror="this.style.display='none'">`
      : '';
    return `<article class="chat-stem-result">
      <div class="chat-stem-art"><span aria-hidden="true">♪</span>${art}</div>
      <div class="chat-stem-copy">
        <div class="chat-stem-kicker">${escapeHtml(item.role||'Production')} stem</div>
        <strong class="chat-stem-title">${escapeHtml(item.stem_name||'Stem')}</strong>
        <div class="chat-stem-song"><span>Song</span><b>${escapeHtml(song)}</b>${album?`<small>${escapeHtml(album)}</small>`:''}</div>
        ${meta?`<p class="chat-stem-meta">${escapeHtml(meta)}</p>`:''}
        <audio class="chat-stem-preview" controls preload="metadata" src="${escapeHtml(item.audio||'')}"></audio>
        <div class="chat-stem-result-actions">
          <button type="button" data-play-track="${Number(item.track_id)}" data-song-audio="${escapeHtml(item.song_audio||'')}" data-song-title="${escapeHtml(song)}" data-song-album="${escapeHtml(album||'Stonefellow')}" data-song-cover="${escapeHtml(item.cover||'')}" data-song-detail="${escapeHtml(item.song_detail||'')}">Play Full Song</button>
          <a href="${escapeHtml(item.song_detail||'#')}">Song Info</a>
          ${studio?`<a href="${escapeHtml(studio)}">Stem Studio</a>`:''}
        </div>
      </div>
    </article>`;
  }

  function agentActionHtml(action) {
    if (!action) return '';
    if (action.type === 'media_capture') {
      return `<button class="chat-agent-action-button" type="button" data-media-agent-mode="${escapeHtml(action.mode||'camera')}" data-media-camera-index="${Number(action.camera_index||0)}">${escapeHtml(action.label||'Open Camera')}</button>`;
    }
    if (!action.url) return '';
    return `<a class="chat-agent-action" href="${escapeHtml(withStudioReturn(action.url))}">${escapeHtml(action.label||'Open')}</a>`;
  }

  function messageElement(role, text, sources = [], media = [], playlistTitle = '', stemMedia = [], actions = []) {
    const wrapper = document.createElement('article');
    wrapper.className = `message ${role}`;

    wrapper.innerHTML = `
      <div class="message-avatar">${role === 'user' ? 'You' : 'S'}</div>
      <div class="message-body">
        <div class="message-role">${role === 'user' ? 'You' : 'Stonefellow'}</div>
        <div class="message-text"></div>
        ${media.length ? `
          <section class="chat-listening-canvas">
            <div class="chat-listening-head">
              <div>
                <small>${media.length > 1 ? 'Listening canvas' : 'Suggested track'}</small>
                <strong>${escapeHtml(playlistTitle || (media.length > 1 ? 'Stonefellow Picks' : media[0].title))}</strong>
              </div>
              <div class="chat-listening-status">
                <span data-listening-current>
                  ${media.length > 1 ? `Ready · ${media.length} tracks` : 'Ready'}
                </span>
                ${media.length > 1 ? '<small>Plays continue in order</small>' : ''}
              </div>
            </div>
            <div class="chat-audio-list">
              ${media.map(mediaHtml).join('')}
            </div>
          </section>` : ''}
        ${stemMedia.length ? `<section class="chat-stem-results"><div class="chat-listening-head"><div><small>Production search</small><strong>Matching stems</strong></div><span>${stemMedia.length} result${stemMedia.length===1?'':'s'}</span></div>${stemMedia.map(stemMediaHtml).join('')}</section>` : ''}
        ${actions.length ? `<div class="chat-agent-actions">${actions.map(agentActionHtml).join('')}</div>` : ''}
        ${sources.length ? `<div class="message-sources">${sources.map(sourceHtml).join('')}</div>` : ''}
      </div>`;

    wrapper.querySelector('.message-text').textContent = text;
    attachAudioTracking(wrapper);
    return wrapper;
  }

  function addMessage(role, text, sources = [], media = [], playlistTitle = '', stemMedia = [], actions = []) {
    if (welcome) welcome.hidden = true;
    const el = messageElement(role, text, sources, media, playlistTitle, stemMedia, actions);
    thread.appendChild(el);
    thread.scrollTop = thread.scrollHeight;
    return el;
  }

  function addTyping() {
    if (welcome) welcome.hidden = true;
    const el = messageElement('assistant', 'Searching Stonefellow data');
    el.classList.add('typing');
    thread.appendChild(el);
    thread.scrollTop = thread.scrollHeight;
    return el;
  }

  function formatPlaybackTime(value) {
    const seconds =
      Math.max(
        0,
        Number(value || 0)
      );
    const whole =
      Math.floor(seconds);
    const minutes =
      Math.floor(whole / 60);
    const remainder =
      String(
        whole % 60
      ).padStart(2, '0');

    return `${minutes}:${remainder}`;
  }

  function playerQueue(audio) {
    const group =
      audio.closest(
        '[data-player-queue],' +
        '.chat-audio-list,' +
        '.chat-canvas-track-grid'
      );

    return [
      ...(
        group || thread
      ).querySelectorAll(
        '.chat-audio-player'
      )
    ].filter(
      candidate =>
        !candidate.closest('[hidden]')
    );
  }

  function playerQueuePosition(audio) {
    const queue =
      playerQueue(audio);
    const index =
      queue.indexOf(audio);

    return {
      queue,
      index,
      total:queue.length
    };
  }

  function playerTitle(audio) {
    return String(
      audio.dataset.playerTitle ||
      audio.closest('.chat-audio-card, .chat-canvas-track-card')
        ?.querySelector(
          '.chat-audio-title-row strong,' +
          '.chat-canvas-track-copy>strong'
        )
        ?.textContent ||
      'Stonefellow'
    ).trim();
  }

  function playerAlbum(audio) {
    return String(
      audio.dataset.playerAlbum ||
      audio.closest('.chat-audio-card, .chat-canvas-track-card')
        ?.querySelector(
          '.chat-audio-title-row span,' +
          '.chat-canvas-track-copy>span'
        )
        ?.textContent ||
      'Stonefellow'
    ).trim();
  }

  function setPlaylistStatus(audio) {
    const canvas =
      audio.closest(
        '.chat-listening-canvas'
      );

    if (!canvas) {
      return;
    }

    const status =
      canvas.querySelector(
        '[data-listening-current]'
      );

    if (!status) {
      return;
    }

    const {
      index,
      total
    } = playerQueuePosition(audio);

    status.textContent =
      total > 1
        ? `${Math.max(1, index + 1)} of ${total} · ${playerTitle(audio)}`
        : playerTitle(audio);
  }

  function updateNowPlaying(audio) {
    if (
      !audio ||
      !nowPlaying
    ) {
      return;
    }

    activeAudio = audio;
    nowPlaying.hidden = false;

    if (nowPlayingCover) {
      const cover =
        String(
          audio.dataset.playerCover ||
          ''
        );

      nowPlayingCover.src =
        cover;
      nowPlayingCover.hidden =
        cover === '';
    }

    if (nowPlayingTitle) {
      nowPlayingTitle.textContent =
        playerTitle(audio);
    }

    if (nowPlayingAlbum) {
      nowPlayingAlbum.textContent =
        playerAlbum(audio);
    }

    const {
      index,
      total
    } = playerQueuePosition(audio);

    if (nowPlayingQueue) {
      nowPlayingQueue.textContent =
        total > 1
          ? `Now Playing · ${index + 1} of ${total}`
          : 'Now Playing';
    }

    if (nowPlayingPrev) {
      nowPlayingPrev.disabled =
        index <= 0;
    }

    if (nowPlayingNext) {
      nowPlayingNext.disabled =
        index < 0 ||
        index >= total - 1;
    }

    if (nowPlayingToggle) {
      nowPlayingToggle.textContent =
        audio.paused
          ? '▶'
          : 'Ⅱ';
      nowPlayingToggle.setAttribute(
        'aria-label',
        audio.paused
          ? 'Play'
          : 'Pause'
      );
    }

    if (nowPlayingVolumeButton) {
      const muted =
        audio.muted ||
        audio.volume <= .001;

      nowPlayingVolumeButton.classList.toggle(
        'muted',
        muted
      );
      nowPlayingVolumeButton.setAttribute(
        'aria-label',
        muted
          ? 'Volume muted'
          : `Volume ${Math.round(audio.volume * 100)}%`
      );
    }

    if (nowPlayingVolume) {
      nowPlayingVolume.value =
        String(
          audio.muted
            ? 0
            : audio.volume
        );
    }

    const duration =
      Number.isFinite(
        audio.duration
      )
        ? audio.duration
        : 0;
    const current =
      Math.max(
        0,
        Number(
          audio.currentTime ||
          0
        )
      );

    if (nowPlayingSeek) {
      nowPlayingSeek.value =
        duration > 0
          ? String(
              Math.max(
                0,
                Math.min(
                  1000,
                  Math.round(
                    current /
                    duration *
                    1000
                  )
                )
              )
            )
          : '0';
    }

    if (nowPlayingCurrent) {
      nowPlayingCurrent.textContent =
        formatPlaybackTime(
          current
        );
    }

    if (nowPlayingDuration) {
      nowPlayingDuration.textContent =
        formatPlaybackTime(
          duration
        );
    }

    setPlaylistStatus(audio);
  }

  function updateInlinePlayer(audio) {
    const state =
      audioState.get(audio);

    if (!state?.controls) {
      return;
    }

    const controls =
      state.controls;
    const duration =
      Number.isFinite(
        audio.duration
      )
        ? audio.duration
        : 0;
    const current =
      Math.max(
        0,
        Number(
          audio.currentTime ||
          0
        )
      );
    const position =
      duration > 0
        ? Math.max(
            0,
            Math.min(
              1000,
              Math.round(
                current /
                duration *
                1000
              )
            )
          )
        : 0;

    controls.toggle.textContent =
      audio.paused
        ? '▶'
        : 'Ⅱ';
    controls.toggle.setAttribute(
      'aria-label',
      audio.paused
        ? 'Play'
        : 'Pause'
    );

    controls.seek.value =
      String(position);
    controls.current.textContent =
      formatPlaybackTime(
        current
      );
    controls.duration.textContent =
      formatPlaybackTime(
        duration
      );

    const {
      index,
      total
    } = playerQueuePosition(audio);

    controls.prev.disabled =
      index <= 0;
    controls.next.disabled =
      index < 0 ||
      index >= total - 1;

    const card =
      audio.closest(
        '.chat-audio-card,' +
        '.chat-canvas-track-card'
      );

    card?.classList.toggle(
      'playing',
      !audio.paused &&
      !audio.ended
    );

    controls.root.classList.toggle(
      'playing',
      !audio.paused &&
      !audio.ended
    );

    if (audio === activeAudio) {
      updateNowPlaying(audio);
    }
  }

  function playQueueOffset(
    audio,
    offset
  ) {
    const {
      queue,
      index
    } = playerQueuePosition(audio);
    const target =
      queue[
        index + offset
      ];

    if (!target) {
      return;
    }

    target.play().catch(
      () => {}
    );
  }

  function decorateAudioPlayer(audio) {
    const state =
      audioState.get(audio);

    if (
      !state ||
      state.controls
    ) {
      return;
    }

    audio.volume = playerVolume;
    audio.muted = playerVolume <= .001;
    audio.controls = false;
    audio.classList.add(
      'chat-audio-native'
    );

    const root =
      document.createElement(
        'div'
      );

    root.className =
      'chat-inline-player';
    root.innerHTML = `
      <button
        class="chat-player-step"
        data-player-prev
        type="button"
        aria-label="Previous track"
      >‹</button>

      <button
        class="chat-player-toggle"
        data-player-toggle
        type="button"
        aria-label="Play"
      >▶</button>

      <span
        class="chat-player-signal"
        aria-hidden="true"
      >
        <i></i><i></i><i></i>
      </span>

      <div class="chat-player-timeline">
        <input
          data-player-seek
          type="range"
          min="0"
          max="1000"
          value="0"
          step="1"
          aria-label="Playback position"
        >

        <div class="chat-player-time">
          <span data-player-current>0:00</span>
          <span data-player-duration>0:00</span>
        </div>
      </div>

      <button
        class="chat-player-step"
        data-player-next
        type="button"
        aria-label="Next track"
      >›</button>
    `;

    audio.insertAdjacentElement(
      'afterend',
      root
    );

    state.controls = {
      root,
      prev:
        root.querySelector(
          '[data-player-prev]'
        ),
      toggle:
        root.querySelector(
          '[data-player-toggle]'
        ),
      seek:
        root.querySelector(
          '[data-player-seek]'
        ),
      current:
        root.querySelector(
          '[data-player-current]'
        ),
      duration:
        root.querySelector(
          '[data-player-duration]'
        ),
      next:
        root.querySelector(
          '[data-player-next]'
        )
    };

    state.controls.toggle
      ?.addEventListener(
        'click',
        () => {
          if (audio.paused) {
            audio.play().catch(
              () => {}
            );
          } else {
            audio.pause();
          }
        }
      );

    state.controls.seek
      ?.addEventListener(
        'input',
        event => {
          const duration =
            Number(
              audio.duration ||
              0
            );

          if (
            duration <= 0
          ) {
            return;
          }

          audio.currentTime =
            Math.max(
              0,
              Math.min(
                duration,
                Number(
                  event.target.value ||
                  0
                ) /
                1000 *
                duration
              )
            );
        }
      );

    state.controls.prev
      ?.addEventListener(
        'click',
        () =>
          playQueueOffset(
            audio,
            -1
          )
      );

    state.controls.next
      ?.addEventListener(
        'click',
        () =>
          playQueueOffset(
            audio,
            1
          )
      );

    updateInlinePlayer(
      audio
    );
  }

  function pauseOtherAudio(active) {
    const players = new Set([
      ...document.querySelectorAll(
        '.chat-audio-player'
      ),
      ...(activeAudio ? [activeAudio] : [])
    ]);

    players.forEach(audio => {
      if (
        audio !== active &&
        !audio.paused
      ) {
        audio.pause();
      }
    });
  }

  function audioListenedDelta(audio) {
    const state = audioState.get(audio);
    if (!state) return 0;

    const now = performance.now();
    const delta = audio.paused
      ? 0
      : Math.max(
          0,
          Math.min(
            20,
            (
              now -
              state.lastTick
            ) /
            1000
          )
        );

    state.lastTick = now;
    return delta;
  }

  async function audioStartSession(audio) {
    const state =
      audioState.get(audio);

    if (
      !state ||
      state.sessionToken
    ) {
      return;
    }

    const data =
      await playbackApi({
        action:'start',
        track_id:state.trackId,
        position:Number(
          audio.currentTime ||
          0
        ),
        duration:Number(
          audio.duration ||
          0
        ),
        source:
          audio.closest(
            '[data-chat-view="player"]'
          )
            ? 'agent_player'
            : 'agent_chat'
      });

    if (
      data &&
      data.ok &&
      data.session_token
    ) {
      state.sessionToken =
        data.session_token;
      state.lastTick =
        performance.now();
    }
  }

  async function audioTrackEvent(
    audio,
    action,
    delta = 0,
    beacon = false
  ) {
    const state =
      audioState.get(audio);

    if (!state) {
      return;
    }

    if (
      !state.sessionToken &&
      [
        'heartbeat',
        'resume'
      ].includes(action)
    ) {
      await audioStartSession(
        audio
      );
    }

    if (!state.sessionToken) {
      return;
    }

    await playbackApi(
      {
        action,
        track_id:state.trackId,
        session_token:
          state.sessionToken,
        position:Number(
          audio.currentTime ||
          0
        ),
        duration:Number(
          audio.duration ||
          0
        ),
        delta:Number(
          Math.max(
            0,
            Math.min(
              20,
              delta || 0
            )
          )
        )
      },
      beacon
    );
  }

  function attachAudioTracking(scope) {
    const players = [
      ...scope.querySelectorAll(
        '.chat-audio-player'
      )
    ];

    players.forEach(audio => {
      if (
        audioState.has(audio)
      ) {
        return;
      }

      const state = {
        trackId:Number(
          audio.dataset.trackId ||
          0
        ),
        sessionToken:'',
        lastTick:
          performance.now(),
        heartbeat:null,
        controls:null
      };

      audioState.set(
        audio,
        state
      );

      decorateAudioPlayer(
        audio
      );

      audio.addEventListener(
        'loadedmetadata',
        () => {
          updateInlinePlayer(
            audio
          );
        }
      );

      audio.addEventListener(
        'durationchange',
        () => {
          updateInlinePlayer(
            audio
          );
        }
      );

      audio.addEventListener(
        'timeupdate',
        () => {
          updateInlinePlayer(
            audio
          );
        }
      );

      audio.addEventListener(
        'volumechange',
        () => {
          updateInlinePlayer(
            audio
          );
        }
      );

      audio.addEventListener(
        'play',
        async () => {
          pauseOtherAudio(
            audio
          );

          activeAudio =
            audio;
          state.lastTick =
            performance.now();

          updateInlinePlayer(
            audio
          );
          updateNowPlaying(
            audio
          );

          if (
            !state.sessionToken
          ) {
            await audioStartSession(
              audio
            );
          } else {
            await audioTrackEvent(
              audio,
              'resume',
              0
            );
          }

          if (!state.heartbeat) {
            state.heartbeat =
              setInterval(
                () => {
                  if (
                    !audio.paused &&
                    !audio.ended &&
                    state.sessionToken
                  ) {
                    const delta =
                      audioListenedDelta(
                        audio
                      );

                    audioTrackEvent(
                      audio,
                      'heartbeat',
                      delta
                    );
                  }
                },
                10000
              );
          }
        }
      );

      audio.addEventListener(
        'pause',
        () => {
          updateInlinePlayer(
            audio
          );

          if (
            !audio.ended &&
            state.sessionToken
          ) {
            const delta =
              audioListenedDelta(
                audio
              );

            audioTrackEvent(
              audio,
              'pause',
              delta
            );
          }
        }
      );

      audio.addEventListener(
        'seeking',
        () => {
          if (
            !audio.paused &&
            state.sessionToken
          ) {
            const delta =
              audioListenedDelta(
                audio
              );

            audioTrackEvent(
              audio,
              'heartbeat',
              delta
            );
          }
        }
      );

      audio.addEventListener(
        'seeked',
        () => {
          if (
            state.sessionToken
          ) {
            audioTrackEvent(
              audio,
              'seek',
              0
            );
          }

          state.lastTick =
            performance.now();

          updateInlinePlayer(
            audio
          );
        }
      );

      audio.addEventListener(
        'ended',
        () => {
          const delta =
            audioListenedDelta(
              audio
            );

          audioTrackEvent(
            audio,
            'ended',
            delta
          );

          state.sessionToken = '';

          if (state.heartbeat) {
            clearInterval(
              state.heartbeat
            );
            state.heartbeat = null;
          }

          updateInlinePlayer(
            audio
          );

          const {
            queue,
            index
          } = playerQueuePosition(
            audio
          );
          const next =
            queue[
              index + 1
            ];

          if (next) {
            next.play().catch(
              () => {}
            );
          }
        }
      );
    });
  }


  function stopAllTrackedAudio(beacon = false) {
    const players = new Set([
      ...document.querySelectorAll(
        '.chat-audio-player'
      ),
      ...(activeAudio ? [activeAudio] : [])
    ]);

    players.forEach(audio => {
      const state = audioState.get(audio);
      if (!state || !state.sessionToken) return;

      const delta = audioListenedDelta(audio);
      audioTrackEvent(audio, 'stop', delta, beacon);
      state.sessionToken = '';

      if (state.heartbeat) {
        clearInterval(state.heartbeat);
        state.heartbeat = null;
      }
    });
  }

  nowPlayingToggle?.addEventListener(
    'click',
    () => {
      if (!activeAudio) {
        return;
      }

      if (activeAudio.paused) {
        activeAudio.play().catch(
          () => {}
        );
      } else {
        activeAudio.pause();
      }
    }
  );

  nowPlayingPrev?.addEventListener(
    'click',
    () => {
      if (activeAudio) {
        playQueueOffset(
          activeAudio,
          -1
        );
      }
    }
  );

  nowPlayingNext?.addEventListener(
    'click',
    () => {
      if (activeAudio) {
        playQueueOffset(
          activeAudio,
          1
        );
      }
    }
  );

  nowPlayingSeek?.addEventListener(
    'input',
    event => {
      if (!activeAudio) {
        return;
      }

      const duration =
        Number(
          activeAudio.duration ||
          0
        );

      if (duration <= 0) {
        return;
      }

      activeAudio.currentTime =
        Math.max(
          0,
          Math.min(
            duration,
            Number(
              event.target.value ||
              0
            ) /
            1000 *
            duration
          )
        );
    }
  );

  function applyPlayerVolume(value) {
    playerVolume = Math.max(
      0,
      Math.min(
        1,
        Number(value || 0)
      )
    );

    try {
      localStorage.setItem(
        PLAYER_VOLUME_KEY,
        String(playerVolume)
      );
    } catch (error) {}

    const players = new Set([
      ...document.querySelectorAll(
        '.chat-audio-player'
      ),
      ...(activeAudio ? [activeAudio] : [])
    ]);

    players.forEach(audio => {
      audio.volume = playerVolume;
      audio.muted = playerVolume <= .001;
      updateInlinePlayer(audio);
    });

    if (activeAudio) {
      updateNowPlaying(activeAudio);
    } else if (nowPlayingVolumeButton) {
      nowPlayingVolumeButton.classList.toggle(
        'muted',
        playerVolume <= .001
      );
    }
  }

  function closeVolumePopover() {
    if (!nowPlayingVolumePopover) {
      return;
    }

    nowPlayingVolumePopover.hidden = true;
    nowPlayingVolumeButton?.setAttribute(
      'aria-expanded',
      'false'
    );
  }

  nowPlayingVolumeButton?.addEventListener(
    'click',
    event => {
      event.stopPropagation();

      if (!nowPlayingVolumePopover) {
        return;
      }

      const opening =
        nowPlayingVolumePopover.hidden;

      nowPlayingVolumePopover.hidden =
        !opening;
      nowPlayingVolumeButton.setAttribute(
        'aria-expanded',
        opening
          ? 'true'
          : 'false'
      );

      if (opening) {
        nowPlayingVolume?.focus();
      }
    }
  );

  nowPlayingVolume?.addEventListener(
    'input',
    event => {
      applyPlayerVolume(
        event.target.value
      );
    }
  );

  nowPlayingVolumePopover?.addEventListener(
    'click',
    event => event.stopPropagation()
  );

  document.addEventListener(
    'click',
    event => {
      if (
        nowPlayingVolumePopover &&
        !nowPlayingVolumePopover.hidden &&
        !event.target.closest(
          '.chat-now-playing-volume-control'
        )
      ) {
        closeVolumePopover();
      }
    }
  );

  document.addEventListener(
    'keydown',
    event => {
      if (event.key === 'Escape') {
        closeVolumePopover();
      }
    }
  );

  if (nowPlayingVolume) {
    nowPlayingVolume.value =
      String(playerVolume);
  }

  if (nowPlayingVolumeButton) {
    nowPlayingVolumeButton.classList.toggle(
      'muted',
      playerVolume <= .001
    );
  }


  function setVoiceStatus(message = '', state = '') {
    if (!voiceStatus) return;
    voiceStatus.hidden = message === '';
    voiceStatus.textContent = message;
    voiceStatus.dataset.state = state;
  }

  function stopVoiceRecognition() {
    if (!voiceRecognition || !voiceListening) return;
    try { voiceRecognition.stop(); } catch (error) {}
  }

  async function ensureBargeInAudio() {
    if (voiceBargeStream || !navigator.mediaDevices?.getUserMedia) return;
    try {
      voiceBargeStream = await navigator.mediaDevices.getUserMedia({
        audio:{
          echoCancellation:true,
          noiseSuppression:true,
          autoGainControl:true
        },
        video:false
      });
      const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
      if (!AudioContextCtor) return;
      voiceBargeContext = new AudioContextCtor();
      voiceBargeAnalyser = voiceBargeContext.createAnalyser();
      voiceBargeAnalyser.fftSize = 1024;
      voiceBargeAnalyser.smoothingTimeConstant = .65;
      voiceBargeSource = voiceBargeContext.createMediaStreamSource(voiceBargeStream);
      voiceBargeSource.connect(voiceBargeAnalyser);
    } catch (error) {
      // SpeechRecognition can still request/use the microphone on its own.
    }
  }

  function stopBargeInMonitor() {
    if (voiceBargeTimer) {
      clearInterval(voiceBargeTimer);
      voiceBargeTimer = null;
    }
    voiceBargeHits = 0;
  }

  function releaseBargeInAudio() {
    stopBargeInMonitor();
    try { voiceBargeSource?.disconnect(); } catch (error) {}
    voiceBargeSource = null;
    voiceBargeAnalyser = null;
    if (voiceBargeContext) {
      try { voiceBargeContext.close(); } catch (error) {}
    }
    voiceBargeContext = null;
    if (voiceBargeStream) {
      voiceBargeStream.getTracks().forEach(track => track.stop());
    }
    voiceBargeStream = null;
  }

  function interruptAgentSpeech() {
    if (!voiceSpeaking) return;
    stopBargeInMonitor();
    if ('speechSynthesis' in window) window.speechSynthesis.cancel();
    voiceSpeaking = false;
    const done = voiceSpeechResolve;
    voiceSpeechResolve = null;
    if (done) done();
    // The network turn already completed before speech began. Unlocking here
    // lets SpeechRecognition capture the interruption immediately.
    busy = false;
    send.disabled = false;
    setVoiceStatus('Interrupted · listening…', 'listening');
    window.setTimeout(startVoiceRecognition, 60);
  }

  function startBargeInMonitor() {
    stopBargeInMonitor();
    if (!voiceBargeAnalyser || !voiceSpeaking) return;
    const samples = new Uint8Array(voiceBargeAnalyser.fftSize);
    voiceBargeTimer = window.setInterval(() => {
      if (!voiceSpeaking || !voiceBargeAnalyser) {
        stopBargeInMonitor();
        return;
      }
      voiceBargeAnalyser.getByteTimeDomainData(samples);
      let sum = 0;
      for (const value of samples) {
        const normalized = (value - 128) / 128;
        sum += normalized * normalized;
      }
      const rms = Math.sqrt(sum / samples.length);
      // Three consecutive speech-like frames prevents clicks/room noise from
      // cancelling a reply. Browser echo cancellation suppresses TTS bleed.
      voiceBargeHits = rms >= .085 ? voiceBargeHits + 1 : Math.max(0, voiceBargeHits - 1);
      if (voiceBargeHits >= 3) interruptAgentSpeech();
    }, 70);
  }

  function startVoiceRecognition() {
    if (!voiceMode || busy || voiceSpeaking || !voiceRecognition || voiceListening) return;
    try {
      voiceRecognition.start();
      setVoiceStatus('Listening…', 'listening');
    } catch (error) {}
  }

  function speakAgentResponse(text) {
    return new Promise(resolve => {
      if (!voiceMode || !('speechSynthesis' in window) || !window.SpeechSynthesisUtterance) {
        resolve();
        return;
      }
      stopVoiceRecognition();
      window.speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(String(text || ''));
      voiceSpeechResolve = resolve;
      voiceSpeaking = true;
      setVoiceStatus('Speaking · interrupt anytime…', 'speaking');
      ensureBargeInAudio().finally(startBargeInMonitor);
      const finish = () => {
        stopBargeInMonitor();
        voiceSpeaking = false;
        const done = voiceSpeechResolve;
        voiceSpeechResolve = null;
        if (done) done();
        if (voiceMode) {
          setVoiceStatus('Voice conversation on', 'ready');
          window.setTimeout(startVoiceRecognition, 180);
        }
      };
      utterance.onend = finish;
      utterance.onerror = finish;
      window.speechSynthesis.speak(utterance);
    });
  }

  function configureVoiceConversation() {
    if (!voiceButton) return;
    if (!SpeechRecognitionCtor) {
      voiceButton.disabled = true;
      voiceButton.title = 'Live speech recognition is not supported by this browser. Typed chat still works.';
      setVoiceStatus('Voice recognition is not available in this browser.', 'unavailable');
      return;
    }

    voiceRecognition = new SpeechRecognitionCtor();
    voiceRecognition.continuous = false;
    voiceRecognition.interimResults = true;
    voiceRecognition.lang = document.documentElement.lang || 'en-US';

    voiceRecognition.onstart = () => {
      voiceListening = true;
      voiceButton.classList.add('active');
      setVoiceStatus('Listening…', 'listening');
    };

    voiceRecognition.onresult = event => {
      let interim = '';
      let finalText = '';
      for (let i = event.resultIndex; i < event.results.length; i += 1) {
        const transcript = event.results[i][0]?.transcript || '';
        if (event.results[i].isFinal) finalText += transcript;
        else interim += transcript;
      }
      input.value = (finalText || interim).trimStart();
      autoSize();
      if (finalText.trim()) {
        const transcript = finalText.trim();
        input.value = '';
        autoSize();
        stopVoiceRecognition();
        sendMessage(transcript, 'voice');
      }
    };

    voiceRecognition.onerror = event => {
      voiceListening = false;
      if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
        voiceMode = false;
        voiceButton.classList.remove('active');
        voiceButton.setAttribute('aria-pressed', 'false');
        releaseBargeInAudio();
        setVoiceStatus('Microphone permission was not granted.', 'error');
        return;
      }
      if (voiceMode) setVoiceStatus('Voice is reconnecting…', 'ready');
    };

    voiceRecognition.onend = () => {
      voiceListening = false;
      if (voiceMode && !busy && !voiceSpeaking) {
        setVoiceStatus('Voice conversation on', 'ready');
        window.setTimeout(startVoiceRecognition, 220);
      }
    };

    voiceButton.addEventListener('click', async () => {
      voiceMode = !voiceMode;
      voiceButton.classList.toggle('active', voiceMode);
      voiceButton.setAttribute('aria-pressed', voiceMode ? 'true' : 'false');
      if (!voiceMode) {
        stopVoiceRecognition();
        stopBargeInMonitor();
        if ('speechSynthesis' in window) window.speechSynthesis.cancel();
        voiceSpeaking = false;
        const done = voiceSpeechResolve;
        voiceSpeechResolve = null;
        if (done) done();
        releaseBargeInAudio();
        setVoiceStatus('Voice conversation off', 'off');
        return;
      }
      setVoiceStatus('Voice conversation on', 'ready');
      await ensureBargeInAudio();
      startVoiceRecognition();
    });
  }

  function autoSize() {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 180)}px`;
  }
  input.addEventListener('input', autoSize);

  async function refreshHistory() {
    try {
      const data = await api({action:'list'});
      history.innerHTML = '';

      data.conversations.forEach(c => {
        const row = document.createElement('div');
        row.className = 'chat-history-row';
        row.dataset.conversationRow = c.id;

        const button = document.createElement('button');
        button.type = 'button';
        button.className =
          'chat-history-item' +
          (Number(c.id) === Number(conversationId) ? ' active' : '');
        button.dataset.conversationId = c.id;
        button.innerHTML =
          `<span>${escapeHtml(c.title)}</span><small>${escapeHtml(String(c.updated_at || '').slice(5,10))}</small>`;

        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'chat-history-delete';
        deleteButton.dataset.deleteConversation = c.id;
        deleteButton.setAttribute('aria-label', `Delete ${c.title || 'chat'}`);
        deleteButton.title = 'Delete chat';
        deleteButton.textContent = '×';

        row.append(button, deleteButton);
        history.appendChild(row);
      });
    } catch (error) {}
  }

  async function loadConversation(id) {
    const data = await api({
      action:'load',
      conversation_id:Number(id)
    });

    conversationId = Number(id);
    lastLoadedMessageId = 0;
    document.dispatchEvent(new CustomEvent('stonefellow:task-start',{detail:{title:'Agent Chat · active conversation',key:`chat:${conversationId}`}}));
    thread.querySelectorAll('.message').forEach(el => el.remove());
    if (welcome) welcome.hidden = true;

    setChatCanvasView(
      'chat',
      {
        newChat:false
      }
    );

    data.messages.forEach(message => {
      let context = {sources:[],media:[],stem_media:[],actions:[],playlist_title:''};

      if (message.context_json) {
        try {
          context = normalizeContext(JSON.parse(message.context_json));
        } catch (error) {}
      }

      addMessage(
        message.role === 'user' ? 'user' : 'assistant',
        message.message,
        context.sources,
        context.media,
        context.playlist_title,
        context.stem_media || [],
        context.actions || []
      );
      lastLoadedMessageId=Math.max(lastLoadedMessageId,Number(message.id||0));
    });

    document.querySelectorAll('.chat-history-item').forEach(btn => {
      btn.classList.toggle(
        'active',
        Number(btn.dataset.conversationId) === conversationId
      );
    });

    closeNav();
  }

  async function syncConversationMessagesV101(id) {
    const targetId=Number(id||0);if(targetId<1||targetId!==conversationId)return;
    const data=await api({action:'messages_after',conversation_id:targetId,after_id:lastLoadedMessageId});
    if(targetId!==conversationId||!Array.isArray(data.messages))return;
    data.messages.forEach(message=>{
      let context={sources:[],media:[],stem_media:[],actions:[],playlist_title:''};
      if(message.context_json){try{context=normalizeContext(JSON.parse(message.context_json));}catch(error){}}
      addMessage(message.role==='user'?'user':'assistant',message.message,context.sources,context.media,context.playlist_title,context.stem_media||[],context.actions||[]);
      lastLoadedMessageId=Math.max(lastLoadedMessageId,Number(message.id||0));
    });
    if(data.messages.length)await refreshHistory();
  }

  history.addEventListener('click', event => {
    const deleteButton = event.target.closest('[data-delete-conversation]');

    if (deleteButton) {
      const id = Number(deleteButton.dataset.deleteConversation || 0);
      if (!id) return;

      const row = deleteButton.closest('.chat-history-row');
      const title = row?.querySelector('.chat-history-item span')?.textContent?.trim() || 'this chat';

      if (!window.confirm(`Delete "${title}"? This cannot be undone.`)) {
        return;
      }

      deleteButton.disabled = true;

      api({action:'delete', conversation_id:id})
        .then(async () => {
          if (Number(conversationId) === id) {
            beginNewChat();
          }

          row?.remove();
          await refreshHistory();
        })
        .catch(error => {
          deleteButton.disabled = false;
          alert(error.message);
        });

      return;
    }

    const button = event.target.closest('.chat-history-item');
    if (button) {
      loadConversation(button.dataset.conversationId)
        .catch(error => alert(error.message));
    }
  });

  newButton.addEventListener(
    'click',
    beginNewChat
  );

  async function sendMessage(message, inputMode = 'text') {
    if (busy || !message.trim()) return;

    document.dispatchEvent(new CustomEvent('stonefellow:task-start',{detail:{title:`Agent Chat · ${message.trim().slice(0,80)}`,key:`chat:${Number(conversationId||0)}`}}));

    setChatCanvasView(
      'chat',
      {
        newChat:
          conversationId < 1
      }
    );

    busy = true;
    send.disabled = true;
    if (inputMode === 'voice') {
      stopVoiceRecognition();
      setVoiceStatus('Thinking…', 'thinking');
    }

    addMessage('user', message.trim());
    input.value = '';
    autoSize();

    const typing = addTyping();

    try {
      const data = await api({
        action:'send',
        conversation_id:conversationId,
        message:message.trim(),
        input_mode:inputMode === 'voice' ? 'voice' : 'text'
      });

      conversationId = Number(data.conversation_id);
      lastLoadedMessageId=Math.max(lastLoadedMessageId,Number(data.user_message_id||0),Number(data.assistant_message_id||0));
      typing.remove();

      addMessage(
        'assistant',
        data.answer,
        data.sources || [],
        data.media || [],
        data.playlist_title || '',
        data.stem_media || [],
        data.actions || []
      );
      const autoAction=(data.actions||[]).find(action=>action && action.auto && action.url);
      if(autoAction){window.setTimeout(()=>{window.location.href=withStudioReturn(String(autoAction.url));},450);}
      const autoMediaAction=(data.actions||[]).find(action=>action && action.auto && action.type==='media_capture');
      if(autoMediaAction){window.setTimeout(()=>{window.StonefellowMediaStudio?.open(autoMediaAction.mode||'camera',Number(autoMediaAction.camera_index||0));},260);}

      await refreshHistory();
      if (inputMode === 'voice' && voiceMode) {
        await speakAgentResponse(data.answer);
      }
    } catch (error) {
      typing.remove();
      addMessage(
        'assistant',
        `I couldn't complete that request: ${error.message}`
      );
    } finally {
      busy = false;
      send.disabled = false;
      input.focus();
      if (inputMode === 'voice' && voiceMode && !voiceSpeaking) {
        window.setTimeout(startVoiceRecognition, 220);
      }
    }
  }

  form.addEventListener('submit', event => {
    event.preventDefault();
    sendMessage(input.value);
  });

  input.addEventListener('keydown', event => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      form.requestSubmit();
    }
  });

  document.querySelectorAll('[data-prompt]').forEach(button => {
    button.addEventListener('click', () => {
      sendMessage(button.dataset.prompt || '');
    });
  });

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      document.querySelectorAll('.chat-audio-player').forEach(audio => {
        const state = audioState.get(audio);
        if (!state || !state.sessionToken || audio.paused) return;
        const delta = audioListenedDelta(audio);
        audioTrackEvent(audio, 'heartbeat', delta, true);
      });
    } else {
      document.querySelectorAll('.chat-audio-player').forEach(audio => {
        const state = audioState.get(audio);
        if (state) state.lastTick = performance.now();
      });

      pollAgentActivity(true);
    }
  });

  window.addEventListener('pagehide', () => {
    stopAllTrackedAudio(true);

    if (activityTimer) {
      clearInterval(activityTimer);
      activityTimer = null;
    }
  });

  window.addEventListener('pageshow', () => {
    if (!activityTimer) {
      startAgentActivityPolling();
    } else {
      pollAgentActivity(true);
    }
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      if (
        createModal &&
        !createModal.hidden
      ) {
        closeCreateModal();
        return;
      }

      closeNav();
      closeTopMenus();
    }
  });

  configureVoiceConversation();

  thread.dataset.view = 'chat';
  attachAudioTracking(thread);

  const allowedInitialViews =
    new Set([
      'chat',
      'player',
      'saved',
      'playlists',
      'shows',
      'photos',
      'merch',
      'about'
    ]);

  const initialView =
    allowedInitialViews.has(
      String(cfg.initialView || '')
    )
      ? String(cfg.initialView)
      : 'chat';

  setChatCanvasView(
    initialView,
    {
      newChat:
        initialView === 'chat'
    }
  );

  document.addEventListener('click', event => {
    const link = event.target.closest?.(
      'a[href*="/admin/stems.php"],a[href*="/video-editor.php"]'
    );
    if (!link) return;
    try {
      const target = new URL(link.href, window.location.href);
      if (target.origin !== window.location.origin) return;
      if (voiceMode) target.searchParams.set('voice','1');
      if (conversationId > 0) target.searchParams.set('conversation_id',String(conversationId));
      link.href = target.toString();
    } catch (error) {}
  }, true);

  const canonicalChatContinuity = {
    isVoice:() => Boolean(voiceMode),
    conversationId:() => Number(conversationId || 0),
    openConversation:async id => {
      const target = Math.max(0, Number(id || 0));
      if (target < 1) return false;
      await loadConversation(target);
      return Number(conversationId || 0) === target;
    },
    syncConversation:async id => {
      const target = Math.max(0, Number(id || conversationId || 0));
      if (target < 1) return false;
      if (target !== Number(conversationId || 0)) await loadConversation(target);
      else await syncConversationMessagesV101(target);
      return true;
    }
  };
  window.STONEFELLOW_CHAT_CONTINUITY_V87 = canonicalChatContinuity;
  window.STONEFELLOW_CHAT_CONTINUITY = canonicalChatContinuity;

  function speakIntroV101(text) {
    if (!('speechSynthesis' in window) || !window.SpeechSynthesisUtterance || !String(text || '').trim()) return;
    const utterance = new SpeechSynthesisUtterance(String(text));
    utterance.rate = 1;
    utterance.pitch = 1;
    try { window.speechSynthesis.cancel(); window.speechSynthesis.speak(utterance); } catch (error) {}
  }

  function presentIntroV101() {
    const intro = cfg.intro && typeof cfg.intro === 'object' ? cfg.intro : null;
    if (!intro || !String(intro.greeting || '').trim()) return;
    const token = String(intro.token || '');
    const storageKey = `stonefellow:agent-intro:${Number(cfg.userId || 0)}`;
    try { if (token && localStorage.getItem(storageKey) === token) return; } catch (error) {}
    const updates = Array.isArray(intro.updates) ? intro.updates : [];
    const detail = updates.map(update => `• ${String(update.title || 'Update')}${update.body ? ` — ${String(update.body)}` : ''}`).join('\n');
    const text = `${String(intro.greeting)}${detail ? `\n\nHere’s what changed:\n${detail}` : ''}`;
    const actions = updates.filter(update => String(update.target_url || '').trim()).slice(0,4).map(update => ({label:`Open ${String(update.title || 'update')}`.slice(0,90),url:String(update.target_url)}));
    addMessage('assistant',text,[],[],'',[],actions);
    if (token) { try { localStorage.setItem(storageKey,token); } catch (error) {} }
    window.setTimeout(() => speakIntroV101(String(intro.greeting)),450);
  }

  // Restore the newest active conversation before presenting the personalized
  // return briefing, so work always resumes in the right place.
  async function restoreInitialConversationV101() {
    const id = Number(cfg.initialConversationId || 0);
    if (String(cfg.initialView || 'chat') !== 'chat' || id < 1 || conversationId > 0) return;
    try {
      await loadConversation(id);
    } catch (error) {
      beginNewChat();
    }
    presentIntroV101();
  }

  window.setTimeout(async () => {
    const id = Number(cfg.initialConversationId || 0);
    if (String(cfg.initialView || 'chat') === 'chat' && id > 0 && conversationId < 1) {
      await restoreInitialConversationV101();
    } else {
      presentIntroV101();
    }
  },80);

  startAgentActivityPolling();
})();

/* ============================================================
   Stonefellow v76 — fan workspace / Player library
   ============================================================ */
(() => {
  'use strict';

  const cfg =
    window.STONEFELLOW_CHAT || {};
  const library =
    window.STONEFELLOW_PLAYER || {};

  const tracks =
    library.tracks || {};
  const albums =
    library.albums || {};
  const playlists =
    library.playlists || {};
  const merch =
    library.merch || {};

  const playerDrawer =
    document.getElementById(
      'chatPlayerDrawer'
    );
  const playerDrawerKicker =
    document.getElementById(
      'chatPlayerDrawerKicker'
    );
  const playerDrawerTitle =
    document.getElementById(
      'chatPlayerDrawerTitle'
    );
  const playerDrawerBody =
    document.getElementById(
      'chatPlayerDrawerBody'
    );

  const queueDrawer =
    document.getElementById(
      'chatQueueDrawer'
    );
  const queueList =
    document.getElementById(
      'chatQueueList'
    );
  const nowPlayingPrevV76 =
    document.getElementById(
      'chatNowPlayingPrev'
    );
  const nowPlayingNextV76 =
    document.getElementById(
      'chatNowPlayingNext'
    );
  const queueOpen =
    document.getElementById(
      'chatNowPlayingQueue'
    );
  const queueClear =
    document.getElementById(
      'chatQueueClear'
    );

  const actionMenu =
    document.getElementById(
      'chatTrackActionMenu'
    );
  const studioAction =
    document.getElementById(
      'chatTrackStudioAction'
    );

  const playlistEditor =
    document.getElementById(
      'chatPlaylistEditor'
    );
  const playlistEditorForm =
    document.getElementById(
      'chatPlaylistEditorForm'
    );
  const playlistEditorId =
    document.getElementById(
      'chatPlaylistEditorId'
    );
  const playlistEditorName =
    document.getElementById(
      'chatPlaylistEditorName'
    );
  const playlistEditorVisibility =
    document.getElementById(
      'chatPlaylistEditorVisibility'
    );
  const playlistEditorDescription =
    document.getElementById(
      'chatPlaylistEditorDescription'
    );
  const playlistEditorTracks =
    document.getElementById(
      'chatPlaylistEditorTracks'
    );
  const playlistEditorPlay =
    document.getElementById(
      'chatPlaylistEditorPlay'
    );
  const playlistEditorDuplicate =
    document.getElementById(
      'chatPlaylistEditorDuplicate'
    );
  const playlistEditorShare =
    document.getElementById(
      'chatPlaylistEditorShare'
    );
  const playlistEditorDelete =
    document.getElementById(
      'chatPlaylistEditorDelete'
    );

  let actionTrackId = 0;
  let queueActive = false;
  let queueIndex = -1;

  const queueKey =
    `stonefellow:player:queue:v76:${Number(library.userId || 0)}`;

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#039;');
  }

  function syncSavedCollectionsEmpty() {
    const empty =
      document.getElementById(
        'chatSavedCollectionsEmpty'
      );

    if (!empty) {
      return;
    }

    const visible =
      [
        ...document.querySelectorAll(
          '[data-saved-album],' +
          '[data-saved-playlist]'
        )
      ].some(
        card => !card.hidden
      );

    empty.hidden =
      visible;
  }

  function parseQueue() {
    try {
      const parsed = JSON.parse(
        localStorage.getItem(
          queueKey
        ) || '[]'
      );

      if (!Array.isArray(parsed)) {
        return [];
      }

      return parsed
        .map(Number)
        .filter(id =>
          id > 0 &&
          tracks[id]
        );
    } catch (error) {
      return [];
    }
  }

  let queue = parseQueue();
  queueActive =
    queue.length > 0;

  function saveQueue() {
    localStorage.setItem(
      queueKey,
      JSON.stringify(queue)
    );
  }

  async function libraryApi(
    action,
    payload = {}
  ) {
    const response = await fetch(
      cfg.libraryEndpoint,
      {
        method:'POST',
        headers:{
          'Content-Type':
            'application/json',
          'Accept':
            'application/json'
        },
        credentials:'same-origin',
        body:JSON.stringify({
          action,
          csrf_token:cfg.csrf,
          ...payload
        })
      }
    );

    const data =
      await response.json().catch(
        () => ({
          ok:false,
          error:
            'Library request failed.'
        })
      );

    if (
      !response.ok ||
      !data.ok
    ) {
      throw new Error(
        data.error ||
        'Library request failed.'
      );
    }

    return data;
  }

  async function toggleTrackFavorite(
    trackId
  ) {
    const response = await fetch(
      cfg.favoriteEndpoint,
      {
        method:'POST',
        headers:{
          'Content-Type':
            'application/json',
          'Accept':
            'application/json'
        },
        credentials:'same-origin',
        body:JSON.stringify({
          action:'toggle',
          track_id:Number(trackId),
          csrf_token:cfg.csrf
        })
      }
    );

    const data =
      await response.json().catch(
        () => ({
          ok:false,
          error:
            'Favorite request failed.'
        })
      );

    if (
      !response.ok ||
      !data.ok
    ) {
      throw new Error(
        data.error ||
        'Favorite request failed.'
      );
    }

    const track =
      tracks[
        Number(trackId)
      ];

    if (track) {
      track.favorite =
        !!data.favorite;
    }

    document.querySelectorAll(
      `[data-favorite-track="${Number(trackId)}"]`
    ).forEach(button => {
      button.classList.toggle(
        'active',
        !!data.favorite
      );
      button.setAttribute(
        'aria-pressed',
        data.favorite
          ? 'true'
          : 'false'
      );
      button.textContent =
        data.favorite
          ? '♥'
          : '♡';
    });

    document.querySelectorAll(
      `[data-favorite-card-track="${Number(trackId)}"]`
    ).forEach(card => {
      card.hidden =
        !data.favorite;
    });

    const favoriteCards = [
      ...document.querySelectorAll(
        '[data-favorite-card-track]'
      )
    ];
    const visibleCount =
      favoriteCards.filter(
        card => !card.hidden
      ).length;

    const countLabel =
      document.getElementById(
        'chatFavoriteCount'
      );
    const emptyLabel =
      document.getElementById(
        'chatFavoritesEmpty'
      );

    if (countLabel) {
      countLabel.textContent =
        `${visibleCount} saved`;
    }

    if (emptyLabel) {
      emptyLabel.hidden =
        visibleCount > 0;
    }

    syncSavedSongsView(
      trackId,
      !!data.favorite
    );

    return data;
  }

  function audioForTrack(
    trackId
  ) {
    const candidates = [
      ...document.querySelectorAll(
        `.chat-audio-player[data-track-id="${Number(trackId)}"]`
      )
    ];

    return (
      candidates.find(audio =>
        !audio.closest('[hidden]')
      ) ||
      candidates[0] ||
      null
    );
  }

  function playTrack(
    trackId,
    position = null,
    fromQueue = false
  ) {
    const audio =
      audioForTrack(trackId);

    if (!audio) {
      return;
    }

    if (
      position !== null &&
      Number(position) > 0
    ) {
      const applyPosition = () => {
        try {
          const duration =
            Number(audio.duration || 0);

          audio.currentTime =
            duration > 0
              ? Math.min(
                  Number(position),
                  Math.max(
                    0,
                    duration - .25
                  )
                )
              : Number(position);
        } catch (error) {}
      };

      if (audio.readyState >= 1) {
        applyPosition();
      } else {
        audio.addEventListener(
          'loadedmetadata',
          applyPosition,
          {once:true}
        );
      }
    }

    if (fromQueue) {
      queueActive = true;
      queueIndex =
        queue.indexOf(
          Number(trackId)
        );
    }

    audio.play().catch(
      () => {}
    );
  }

  function setQueue(
    ids,
    playFirst = false
  ) {
    queue = [
      ...new Set(
        ids
          .map(Number)
          .filter(id =>
            id > 0 &&
            tracks[id]
          )
      )
    ];
    queueIndex = -1;
    queueActive =
      queue.length > 0;
    saveQueue();
    renderQueue();

    if (
      playFirst &&
      queue.length
    ) {
      queueIndex = 0;
      playTrack(
        queue[0],
        null,
        true
      );
    }
  }

  function addQueue(
    trackId
  ) {
    const id =
      Number(trackId);

    if (
      !id ||
      !tracks[id]
    ) {
      return;
    }

    if (!queue.includes(id)) {
      queue.push(id);
      saveQueue();
    }

    queueActive = true;
    renderQueue();
  }

  function playNext(
    trackId
  ) {
    const id =
      Number(trackId);

    if (
      !id ||
      !tracks[id]
    ) {
      return;
    }

    const active =
      document.querySelector(
        '.chat-audio-player:not([data-queue-ignore])'
      );

    let insertAt =
      queueIndex >= 0
        ? queueIndex + 1
        : 0;

    queue = queue.filter(
      queuedId =>
        queuedId !== id
    );

    queue.splice(
      Math.min(
        insertAt,
        queue.length
      ),
      0,
      id
    );

    queueActive = true;
    saveQueue();
    renderQueue();
  }

  function queueOffset(
    delta
  ) {
    if (
      !queueActive ||
      !queue.length
    ) {
      return false;
    }

    const currentAudio =
      document.querySelector(
        '.chat-audio-player[data-v76-current="1"]'
      );

    if (currentAudio) {
      const currentId =
        Number(
          currentAudio.dataset
            .trackId ||
          0
        );

      const found =
        queue.indexOf(
          currentId
        );

      if (found >= 0) {
        queueIndex =
          found;
      }
    }

    const baseIndex =
      queueIndex >= 0
        ? queueIndex
        : (
            delta > 0
              ? -1
              : 0
          );

    const nextIndex =
      Math.max(
        0,
        Math.min(
          queue.length - 1,
          baseIndex + delta
        )
      );

    queueIndex =
      nextIndex;

    playTrack(
      queue[nextIndex],
      null,
      true
    );
    renderQueue();
    return true;
  }

  function queueMove(
    index,
    delta
  ) {
    const target =
      index + delta;

    if (
      index < 0 ||
      target < 0 ||
      index >= queue.length ||
      target >= queue.length
    ) {
      return;
    }

    const [id] =
      queue.splice(index,1);
    queue.splice(
      target,
      0,
      id
    );

    if (queueIndex === index) {
      queueIndex = target;
    } else if (
      queueIndex === target
    ) {
      queueIndex = index;
    }

    saveQueue();
    renderQueue();
  }

  function renderQueue() {
    if (!queueList) {
      return;
    }

    if (!queue.length) {
      queueList.innerHTML =
        '<div class="chat-drawer-empty">Your queue is empty. Use ••• on a track to add music.</div>';
      return;
    }

    queueList.innerHTML =
      queue.map(
        (trackId,index) => {
          const track =
            tracks[trackId];

          if (!track) {
            return '';
          }

          return `
            <article
              class="chat-queue-row ${
                index === queueIndex
                  ? 'active'
                  : ''
              }"
              draggable="true"
              data-queue-index="${index}"
            >
              <img
                src="${escapeHtml(track.cover)}"
                alt=""
              >
              <div>
                <strong>${escapeHtml(track.title)}</strong>
                <span>${escapeHtml(track.album)}</span>
              </div>
              <button
                type="button"
                data-queue-play="${index}"
                aria-label="Play"
              >▶</button>
              <button
                type="button"
                data-queue-up="${index}"
                aria-label="Move up"
              >↑</button>
              <button
                type="button"
                data-queue-down="${index}"
                aria-label="Move down"
              >↓</button>
              <button
                type="button"
                data-queue-remove="${index}"
                aria-label="Remove"
              >×</button>
            </article>
          `;
        }
      ).join('');
  }

  function openQueue() {
    if (!queueDrawer) {
      return;
    }

    renderQueue();
    queueDrawer.hidden = false;
    document.body.classList.add(
      'chat-player-overlay-open'
    );
  }

  function closeQueue() {
    if (!queueDrawer) {
      return;
    }

    queueDrawer.hidden = true;
    document.body.classList.remove(
      'chat-player-overlay-open'
    );
  }

  function openPlayerDrawer(
    kicker,
    title,
    html,
    mode = ''
  ) {
    if (
      !playerDrawer ||
      !playerDrawerBody
    ) {
      return;
    }

    playerDrawerKicker.textContent =
      kicker;
    playerDrawerTitle.textContent =
      title;
    playerDrawer.dataset.mode =
      mode;
    delete playerDrawer.dataset
      .activeLyricIndex;
    playerDrawerBody.innerHTML =
      html;
    playerDrawer.hidden = false;

    document.body.classList.add(
      'chat-player-overlay-open'
    );
  }

  function closePlayerDrawer() {
    if (!playerDrawer) {
      return;
    }

    playerDrawer.hidden = true;
    delete playerDrawer.dataset.mode;
    delete playerDrawer.dataset.trackId;
    delete playerDrawer.dataset
      .activeLyricIndex;
    playerDrawerBody.innerHTML = '';

    document.body.classList.remove(
      'chat-player-overlay-open'
    );
  }

  function merchForTrack(
    trackId
  ) {
    const track =
      tracks[
        Number(trackId)
      ];

    if (!track) {
      return [];
    }

    return Object.values(
      merch
    ).filter(item =>
      Number(item.track_id) ===
        Number(trackId) ||
      (
        Number(item.album_id) > 0 &&
        Number(item.album_id) ===
          Number(track.album_id)
      )
    );
  }

  function merchHtml(
    items
  ) {
    if (!items.length) {
      return '';
    }

    return `
      <section class="chat-drawer-merch">
        <h3>Related Merch</h3>
        <div>
          ${items.map(item => `
            <article>
              ${
                item.image
                  ? `<img src="${escapeHtml(item.image)}" alt="">`
                  : ''
              }
              <div>
                <strong>${escapeHtml(item.title)}</strong>
                <span>$${escapeHtml(item.price)}</span>
                ${
                  item.product_url
                    ? `<a href="${escapeHtml(item.product_url)}" target="_blank" rel="noopener noreferrer">View ↗</a>`
                    : ''
                }
              </div>
            </article>
          `).join('')}
        </div>
      </section>
    `;
  }

  function relatedTracksForTrack(
    trackId,
    limit = 4
  ) {
    const track = tracks[Number(trackId)];

    if (!track) return [];

    return Object.values(tracks)
      .filter(candidate => Number(candidate.id) !== Number(track.id))
      .map(candidate => {
        let score = 0;

        if (
          Number(track.album_id) > 0 &&
          Number(candidate.album_id) === Number(track.album_id)
        ) score += 8;

        if (
          track.genre && candidate.genre &&
          String(track.genre).toLowerCase() === String(candidate.genre).toLowerCase()
        ) score += 4;

        if (
          track.mood && candidate.mood &&
          String(track.mood).toLowerCase() === String(candidate.mood).toLowerCase()
        ) score += 3;

        if (
          track.energy && candidate.energy &&
          String(track.energy) === String(candidate.energy)
        ) score += 1;

        return {track:candidate,score};
      })
      .filter(item => item.score > 0)
      .sort((a,b) => b.score - a.score || Number(b.track.id) - Number(a.track.id))
      .slice(0, limit)
      .map(item => item.track);
  }

  function relatedTracksHtml(trackId) {
    const related = relatedTracksForTrack(trackId, 4);

    if (!related.length) return '';

    return `
      <section class="chat-drawer-track-list chat-related-tracks">
        <h3>Related Tracks</h3>
        ${related.map((track,index) => `
          <article class="chat-drawer-track-row">
            <span>${index + 1}</span>
            <img src="${escapeHtml(track.cover)}" alt="">
            <div>
              <strong>${escapeHtml(track.title)}</strong>
              <small>${escapeHtml(track.album || 'Stonefellow')}</small>
            </div>
            <button type="button" data-drawer-play="${Number(track.id)}">▶</button>
            <button type="button" data-drawer-track-more="${Number(track.id)}">•••</button>
          </article>
        `).join('')}
      </section>
    `;
  }

  function openTrackInfo(
    trackId
  ) {
    const track =
      tracks[
        Number(trackId)
      ];

    if (!track) {
      return;
    }

    const meta = [
      track.genre,
      track.mood,
      track.energy
        ? `${track.energy} energy`
        : '',
      Number(track.tempo_bpm) > 0
        ? `${track.tempo_bpm} BPM`
        : '',
      track.duration
    ].filter(Boolean);

    const albumButton =
      Number(track.album_id) > 0
        ? `
          <button
            type="button"
            data-album-open="${Number(track.album_id)}"
          >View Album</button>
        `
        : '';

    openPlayerDrawer(
      'Track',
      track.title,
      `
        <article class="chat-track-detail">
          <img
            class="chat-track-detail-cover"
            src="${escapeHtml(track.cover)}"
            alt=""
          >
          <div class="chat-track-detail-copy">
            <span>${escapeHtml(track.album)}</span>
            <h3>${escapeHtml(track.title)}</h3>
            ${
              meta.length
                ? `<p class="chat-track-detail-meta">${escapeHtml(meta.join(' · '))}</p>`
                : ''
            }
            ${
              track.description
                ? `<p>${escapeHtml(track.description)}</p>`
                : ''
            }
            ${
              String(track.credits || '').trim()
                ? `<div class="chat-track-credits"><span>Credits</span><p>${escapeHtml(track.credits).replaceAll('\n','<br>')}</p></div>`
                : ''
            }
            <div class="chat-drawer-actions">
              <button type="button" data-drawer-play="${Number(track.id)}">▶ Play</button>
              <button type="button" data-drawer-playlist="${Number(track.id)}">Add to Playlist</button>
              <button type="button" data-drawer-lyrics="${Number(track.id)}">Lyrics</button>
              ${albumButton}
              ${
                track.studio
                  ? `<a class="desktop-studio-only" href="${escapeHtml(track.studio)}">Stem Studio</a>`
                  : ''
              }
            </div>
          </div>
        </article>
        ${relatedTracksHtml(track.id)}
        ${merchHtml(
          merchForTrack(
            track.id
          )
        )}
      `,
      'track'
    );
  }

  function openLyrics(
    trackId
  ) {
    const track =
      tracks[
        Number(trackId)
      ];

    if (!track) {
      return;
    }

    const rawLines =
      String(
        track.lyrics || ''
      )
        .split(/\r?\n/)
        .map(line => line.trim())
        .filter(Boolean);

    const lines =
      rawLines.map(
        line => {
          const match =
            line.match(
              /^\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]\s*(.*)$/
            );

          if (!match) {
            return {
              text:line,
              time:null
            };
          }

          const fraction =
            match[3]
              ? Number(
                  `0.${match[3]}`
                )
              : 0;

          return {
            text:
              match[4] ||
              line,
            time:
              Number(match[1]) * 60 +
              Number(match[2]) +
              fraction
          };
        }
      );

    const hasTimedLyrics =
      lines.some(
        line =>
          Number.isFinite(
            line.time
          )
      );

    playerDrawer.dataset.trackId =
      String(track.id);

    openPlayerDrawer(
      'Lyrics',
      track.title,
      lines.length
        ? `
          <div
            class="chat-lyrics"
            data-lyrics-track="${Number(track.id)}"
            data-timed-lyrics="${hasTimedLyrics ? '1' : '0'}"
          >
            ${lines.map(
              (line,index) => `
                <p
                  class="chat-lyrics-line"
                  data-lyric-index="${index}"
                  ${
                    Number.isFinite(
                      line.time
                    )
                      ? `data-lyric-time="${line.time}"`
                      : ''
                  }
                >${escapeHtml(line.text)}</p>
              `
            ).join('')}
          </div>
          <p class="chat-lyrics-note">
            ${
              hasTimedLyrics
                ? 'Timestamped lyrics follow exact playback time.'
                : 'Untimed lyrics follow playback proportionally. Add [mm:ss.xx] timestamps for exact line sync.'
            }
          </p>
        `
        : '<div class="chat-drawer-empty">Lyrics have not been added for this track yet.</div>',
      'lyrics'
    );

    playerDrawer.dataset.trackId =
      String(track.id);
  }

  function updateLyricFollow(
    audio
  ) {
    if (
      !playerDrawer ||
      playerDrawer.hidden ||
      playerDrawer.dataset.mode !==
        'lyrics'
    ) {
      return;
    }

    const trackId =
      Number(
        playerDrawer.dataset.trackId ||
        0
      );

    if (
      trackId < 1 ||
      Number(audio.dataset.trackId) !==
        trackId
    ) {
      return;
    }

    const lines = [
      ...playerDrawer.querySelectorAll(
        '.chat-lyrics-line'
      )
    ];

    if (!lines.length) {
      return;
    }

    const duration =
      Number(audio.duration || 0);
    const current =
      Number(audio.currentTime || 0);

    const timed =
      lines.some(
        line =>
          Number.isFinite(
            Number(
              line.dataset
                .lyricTime
            )
          )
      );

    let index = 0;

    if (timed) {
      for (
        let lineIndex = 0;
        lineIndex < lines.length;
        lineIndex += 1
      ) {
        const lineTime =
          Number(
            lines[lineIndex]
              .dataset
              .lyricTime
          );

        if (
          Number.isFinite(lineTime) &&
          lineTime <= current
        ) {
          index =
            lineIndex;
        }
      }
    } else if (duration > 0) {
      index =
        Math.min(
          lines.length - 1,
          Math.floor(
            current /
            duration *
            lines.length
          )
        );
    }

    const activeLine =
      lines[index];
    const priorIndex =
      Number(
        playerDrawer.dataset
          .activeLyricIndex ??
        -1
      );

    lines.forEach(
      (line,lineIndex) => {
        line.classList.toggle(
          'active',
          lineIndex === index
        );
      }
    );

    if (
      activeLine &&
      priorIndex !== index
    ) {
      playerDrawer.dataset
        .activeLyricIndex =
          String(index);

      activeLine.scrollIntoView({
        block:'center',
        behavior:'smooth'
      });
    }
  }

  function albumMerch(
    albumId
  ) {
    const album =
      albums[
        Number(albumId)
      ];

    if (!album) {
      return [];
    }

    const trackIds =
      new Set(
        (album.track_ids || [])
          .map(Number)
      );

    return Object.values(
      merch
    ).filter(item =>
      Number(item.album_id) ===
        Number(albumId) ||
      trackIds.has(
        Number(item.track_id)
      )
    );
  }

  function openAlbum(
    albumId
  ) {
    const album =
      albums[
        Number(albumId)
      ];

    if (!album) {
      return;
    }

    const trackRows =
      (album.track_ids || [])
        .map(id =>
          tracks[
            Number(id)
          ]
        )
        .filter(Boolean)
        .map(
          (track,index) => `
            <article class="chat-drawer-track-row">
              <span>${index + 1}</span>
              <img src="${escapeHtml(track.cover)}" alt="">
              <div>
                <strong>${escapeHtml(track.title)}</strong>
                <small>${escapeHtml(track.duration || '')}</small>
              </div>
              <button type="button" data-drawer-play="${Number(track.id)}">▶</button>
              <button type="button" data-drawer-track-more="${Number(track.id)}">•••</button>
            </article>
          `
        ).join('');

    openPlayerDrawer(
      'Album',
      album.title,
      `
        <article class="chat-album-detail">
          ${
            album.cover
              ? `<img class="chat-album-detail-cover" src="${escapeHtml(album.cover)}" alt="">`
              : '<div class="chat-album-detail-cover placeholder">A</div>'
          }
          <div>
            <span>${
              album.release_date
                ? escapeHtml(album.release_date)
                : 'Stonefellow'
            }</span>
            <h3>${escapeHtml(album.title)}</h3>
            ${
              album.description
                ? `<p>${escapeHtml(album.description)}</p>`
                : ''
            }
            <div class="chat-drawer-actions">
              <button type="button" data-play-album="${Number(album.id)}">▶ Play Album</button>
              <button
                type="button"
                data-album-favorite="${Number(album.id)}"
                class="${album.favorite ? 'active' : ''}"
              >${album.favorite ? '♥ Favorite' : '♡ Favorite'}</button>
              <button type="button" data-album-playlist="${Number(album.id)}">Add Album to Playlist</button>
            </div>
          </div>
        </article>
        <section class="chat-drawer-track-list">
          <h3>Tracks</h3>
          ${
            trackRows ||
            '<div class="chat-drawer-empty">No tracks are assigned to this album.</div>'
          }
        </section>
        ${merchHtml(
          albumMerch(
            album.id
          )
        )}
      `,
      'album'
    );
  }

  function ownedPlaylists() {
    return Object.values(
      playlists
    ).filter(
      playlist =>
        !!playlist.owned
    );
  }

  function openPlaylistPicker(
    trackIds
  ) {
    const owned =
      ownedPlaylists();

    openPlayerDrawer(
      'Playlist',
      'Add to Playlist',
      owned.length
        ? `
          <div class="chat-playlist-picker">
            ${owned.map(
              playlist => `
                <button
                  type="button"
                  data-picker-playlist="${Number(playlist.id)}"
                  data-picker-tracks="${escapeHtml(
                    trackIds
                      .map(Number)
                      .join(',')
                  )}"
                >
                  <strong>${escapeHtml(playlist.title)}</strong>
                  <span>${(playlist.track_ids || []).length} tracks</span>
                </button>
              `
            ).join('')}
          </div>
        `
        : `
          <div class="chat-drawer-empty">
            Create a playlist first, then add tracks here.
          </div>
          <button
            type="button"
            data-chat-create-type="playlist"
            class="chat-drawer-create-playlist"
          >+ Create Playlist</button>
        `,
      'playlist-picker'
    );
  }

  function playlistTrackRows(
    playlist
  ) {
    const selected =
      new Set(
        (playlist.track_ids || [])
          .map(Number)
      );

    const selectedTracks =
      (playlist.track_ids || [])
        .map(id =>
          tracks[
            Number(id)
          ]
        )
        .filter(Boolean);

    const available =
      Object.values(
        tracks
      ).filter(
        track =>
          !selected.has(
            Number(track.id)
          )
      );

    return `
      <div class="chat-playlist-selected" data-playlist-selected>
        ${selectedTracks.map(
          (track,index) => `
            <article
              class="chat-playlist-editor-track selected"
              draggable="true"
              data-playlist-track="${Number(track.id)}"
              data-playlist-order="${index}"
            >
              <span class="drag">⋮⋮</span>
              <img src="${escapeHtml(track.cover)}" alt="">
              <div>
                <strong>${escapeHtml(track.title)}</strong>
                <small>${escapeHtml(track.album)}</small>
              </div>
              <button type="button" data-editor-remove-track="${Number(track.id)}">Remove</button>
            </article>
          `
        ).join('')}
      </div>

      <div class="chat-playlist-available">
        <span>Available Tracks</span>
        ${available.map(
          track => `
            <button
              type="button"
              data-editor-add-track="${Number(track.id)}"
            >
              <img src="${escapeHtml(track.cover)}" alt="">
              <span>
                <strong>${escapeHtml(track.title)}</strong>
                <small>${escapeHtml(track.album)}</small>
              </span>
              <b>+</b>
            </button>
          `
        ).join('')}
      </div>
    `;
  }

  function openPlaylistEditor(
    playlistId
  ) {
    const playlist =
      playlists[
        Number(playlistId)
      ];

    if (
      !playlist ||
      !playlist.owned ||
      !playlistEditor
    ) {
      return;
    }

    playlistEditorId.value =
      String(playlist.id);
    playlistEditorName.value =
      playlist.title;
    playlistEditorVisibility.value =
      playlist.visibility;
    playlistEditorDescription.value =
      playlist.description;
    playlistEditorTracks.innerHTML =
      playlistTrackRows(
        playlist
      );

    playlistEditor.hidden = false;
    document.body.classList.add(
      'chat-player-overlay-open'
    );
  }

  function closePlaylistEditor() {
    if (!playlistEditor) {
      return;
    }

    playlistEditor.hidden = true;
    document.body.classList.remove(
      'chat-player-overlay-open'
    );
  }

  function editorSelectedIds() {
    return [
      ...playlistEditorTracks
        .querySelectorAll(
          '[data-playlist-track]'
        )
    ].map(
      row =>
        Number(
          row.dataset
            .playlistTrack
        )
    );
  }

  function refreshEditorAvailable() {
    const id =
      Number(
        playlistEditorId.value ||
        0
      );
    const playlist =
      playlists[id];

    if (!playlist) {
      return;
    }

    playlist.track_ids =
      editorSelectedIds();
    playlistEditorTracks.innerHTML =
      playlistTrackRows(
        playlist
      );
  }

  function openActionMenu(
    button,
    trackId
  ) {
    const track =
      tracks[
        Number(trackId)
      ];

    if (
      !track ||
      !actionMenu
    ) {
      return;
    }

    actionTrackId =
      Number(trackId);

    const rect =
      button.getBoundingClientRect();

    actionMenu.style.left =
      `${Math.min(
        window.innerWidth - 190,
        Math.max(
          8,
          rect.right - 180
        )
      )}px`;
    actionMenu.style.top =
      `${Math.min(
        window.innerHeight - 280,
        rect.bottom + 6
      )}px`;

    if (studioAction) {
      studioAction.href =
        track.studio || '#';
      studioAction.hidden =
        !track.studio;
    }

    const albumAction =
      actionMenu.querySelector(
        '[data-track-action="album"]'
      );

    if (albumAction) {
      albumAction.hidden =
        Number(track.album_id) < 1;
    }

    const lyricsAction =
      actionMenu.querySelector(
        '[data-track-action="lyrics"]'
      );

    if (lyricsAction) {
      lyricsAction.disabled =
        !String(track.lyrics || '')
          .trim();
    }

    actionMenu.hidden = false;
  }

  function closeActionMenu() {
    if (actionMenu) {
      actionMenu.hidden = true;
    }

    actionTrackId = 0;
  }

  function decorateTrackActions(
    scope = document
  ) {
    scope.querySelectorAll(
      '.chat-audio-player'
    ).forEach(audio => {
      if (
        audio.dataset.v76Actions ===
        '1'
      ) {
        return;
      }

      audio.dataset.v76Actions =
        '1';

      const card =
        audio.closest(
          [
            '.chat-player-feature',
            '.chat-player-new-card',
            '.chat-player-ranked-row',
            '.chat-player-favorite-card',
            '.chat-player-all-row',
            '.chat-player-recent-card',
            '.chat-player-for-you-card',
            '.chat-audio-card'
          ].join(',')
        );

      if (
        card &&
        !card.querySelector(
          ':scope > .chat-track-more'
        )
      ) {
        const button =
          document.createElement(
            'button'
          );

        button.type = 'button';
        button.className =
          'chat-track-more';
        button.dataset.trackMenu =
          String(
            Number(
              audio.dataset.trackId ||
              0
            )
          );
        button.setAttribute(
          'aria-label',
          'Track actions'
        );
        button.textContent = '•••';

        card.appendChild(
          button
        );
      }

      audio.addEventListener(
        'timeupdate',
        () =>
          updateLyricFollow(
            audio
          )
      );
    });
  }

  document.addEventListener(
    'click',
    async event => {
      const menuButton =
        event.target.closest(
          '[data-track-menu]'
        );

      if (menuButton) {
        event.preventDefault();
        event.stopPropagation();
        openActionMenu(
          menuButton,
          menuButton.dataset.trackMenu
        );
        return;
      }

      if (
        actionMenu &&
        !actionMenu.hidden &&
        !actionMenu.contains(
          event.target
        )
      ) {
        closeActionMenu();
      }

      const actionButton =
        event.target.closest(
          '[data-track-action]'
        );

      if (
        actionButton &&
        actionTrackId > 0
      ) {
        event.preventDefault();

        const action =
          actionButton.dataset
            .trackAction;
        const track =
          tracks[actionTrackId];

        closeActionMenu();

        if (!track) {
          return;
        }

        if (action === 'play-next') {
          playNext(track.id);
        } else if (
          action === 'add-queue'
        ) {
          addQueue(track.id);
        } else if (
          action === 'playlist'
        ) {
          openPlaylistPicker([
            track.id
          ]);
        } else if (
          action === 'favorite'
        ) {
          try {
            await toggleTrackFavorite(
              track.id
            );
          } catch (error) {
            window.alert(
              error.message
            );
          }
        } else if (
          action === 'album' &&
          Number(track.album_id) > 0
        ) {
          openAlbum(
            track.album_id
          );
        } else if (
          action === 'info'
        ) {
          openTrackInfo(
            track.id
          );
        } else if (
          action === 'lyrics'
        ) {
          openLyrics(
            track.id
          );
        }
        return;
      }

      const albumOpen =
        event.target.closest(
          '[data-album-open]'
        );

      if (
        albumOpen &&
        !event.target.closest(
          '[data-album-favorite]'
        )
      ) {
        openAlbum(
          albumOpen.dataset.albumOpen
        );
        return;
      }

      const albumFavorite =
        event.target.closest(
          '[data-album-favorite]'
        );

      if (albumFavorite) {
        event.preventDefault();
        event.stopPropagation();

        try {
          const data =
            await libraryApi(
              'favorite_album',
              {
                album_id:Number(
                  albumFavorite.dataset
                    .albumFavorite
                )
              }
            );

          const album =
            albums[
              Number(
                data.album_id
              )
            ];

          if (album) {
            album.favorite =
              !!data.favorite;
          }

          document.querySelectorAll(
            `[data-album-favorite="${Number(data.album_id)}"]`
          ).forEach(button => {
            button.classList.toggle(
              'active',
              !!data.favorite
            );
            button.setAttribute(
              'aria-pressed',
              data.favorite
                ? 'true'
                : 'false'
            );
            button.textContent =
              data.favorite
                ? (
                    button.closest(
                      '.chat-album-detail'
                    )
                      ? '♥ Favorite'
                      : '♥'
                  )
                : (
                    button.closest(
                      '.chat-album-detail'
                    )
                      ? '♡ Favorite'
                      : '♡'
                  );
          });

          document.querySelectorAll(
            `[data-saved-album="${Number(data.album_id)}"]`
          ).forEach(card => {
            card.hidden =
              !data.favorite;
          });

          syncSavedCollectionsEmpty();
        } catch (error) {
          window.alert(
            error.message
          );
        }

        return;
      }

      const playAlbum =
        event.target.closest(
          '[data-play-album]'
        );

      if (playAlbum) {
        const album =
          albums[
            Number(
              playAlbum.dataset
                .playAlbum
            )
          ];

        if (album) {
          setQueue(
            album.track_ids || [],
            true
          );
        }
        return;
      }

      const albumPlaylist =
        event.target.closest(
          '[data-album-playlist]'
        );

      if (albumPlaylist) {
        const album =
          albums[
            Number(
              albumPlaylist.dataset
                .albumPlaylist
            )
          ];

        if (album) {
          openPlaylistPicker(
            album.track_ids || []
          );
        }
        return;
      }

      const drawerPlay =
        event.target.closest(
          '[data-drawer-play]'
        );

      if (drawerPlay) {
        playTrack(
          drawerPlay.dataset
            .drawerPlay
        );
        return;
      }

      const drawerLyrics =
        event.target.closest(
          '[data-drawer-lyrics]'
        );

      if (drawerLyrics) {
        openLyrics(
          drawerLyrics.dataset
            .drawerLyrics
        );
        return;
      }

      const drawerPlaylist =
        event.target.closest(
          '[data-drawer-playlist]'
        );

      if (drawerPlaylist) {
        openPlaylistPicker([
          Number(
            drawerPlaylist.dataset
              .drawerPlaylist
          )
        ]);
        return;
      }

      const drawerTrackMore =
        event.target.closest(
          '[data-drawer-track-more]'
        );

      if (drawerTrackMore) {
        openActionMenu(
          drawerTrackMore,
          drawerTrackMore.dataset
            .drawerTrackMore
        );
        return;
      }

      const picker =
        event.target.closest(
          '[data-picker-playlist]'
        );

      if (picker) {
        const playlistId =
          Number(
            picker.dataset
              .pickerPlaylist
          );
        const ids =
          String(
            picker.dataset
              .pickerTracks ||
            ''
          )
            .split(',')
            .map(Number)
            .filter(Boolean);

        picker.disabled = true;

        try {
          for (
            const trackId
            of ids
          ) {
            await libraryApi(
              'playlist_add_track',
              {
                playlist_id:
                  playlistId,
                track_id:
                  trackId
              }
            );

            const playlist =
              playlists[
                playlistId
              ];

            if (
              playlist &&
              !playlist.track_ids.includes(
                trackId
              )
            ) {
              playlist.track_ids.push(
                trackId
              );
            }
          }

          picker.textContent =
            'Added ✓';
        } catch (error) {
          picker.disabled = false;
          window.alert(
            error.message
          );
        }
        return;
      }

      const playPlaylist =
        event.target.closest(
          '[data-play-playlist]'
        );

      if (playPlaylist) {
        const playlist =
          playlists[
            Number(
              playPlaylist.dataset
                .playPlaylist
            )
          ];

        if (playlist) {
          setQueue(
            playlist.track_ids || [],
            true
          );
        }
        return;
      }

      const playlistFavorite =
        event.target.closest(
          '[data-playlist-favorite]'
        );

      if (playlistFavorite) {
        try {
          const data =
            await libraryApi(
              'favorite_playlist',
              {
                playlist_id:Number(
                  playlistFavorite.dataset
                    .playlistFavorite
                )
              }
            );

          const playlist =
            playlists[
              Number(
                data.playlist_id
              )
            ];

          if (playlist) {
            playlist.favorite =
              !!data.favorite;
          }

          document.querySelectorAll(
            `[data-playlist-favorite="${Number(data.playlist_id)}"]`
          ).forEach(button => {
            button.classList.toggle(
              'active',
              !!data.favorite
            );
            button.setAttribute(
              'aria-pressed',
              data.favorite
                ? 'true'
                : 'false'
            );
            button.textContent =
              data.favorite
                ? '♥'
                : '♡';
          });

          document.querySelectorAll(
            `[data-saved-playlist="${Number(data.playlist_id)}"]`
          ).forEach(card => {
            card.hidden =
              !data.favorite;
          });

          syncSavedCollectionsEmpty();
        } catch (error) {
          window.alert(
            error.message
          );
        }
        return;
      }

      const editPlaylist =
        event.target.closest(
          '[data-edit-playlist]'
        );

      if (editPlaylist) {
        openPlaylistEditor(
          editPlaylist.dataset
            .editPlaylist
        );
        return;
      }

      const duplicatePlaylist =
        event.target.closest(
          '[data-duplicate-playlist]'
        );

      if (duplicatePlaylist) {
        try {
          await libraryApi(
            'playlist_duplicate',
            {
              playlist_id:Number(
                duplicatePlaylist.dataset
                  .duplicatePlaylist
              )
            }
          );
          window.location.reload();
        } catch (error) {
          window.alert(
            error.message
          );
        }
        return;
      }

      const reminder =
        event.target.closest(
          '[data-show-reminder]'
        );

      if (reminder) {
        try {
          const data =
            await libraryApi(
              'show_reminder',
              {
                show_id:Number(
                  reminder.dataset
                    .showReminder
                )
              }
            );

          reminder.classList.toggle(
            'active',
            !!data.enabled
          );
          reminder.setAttribute(
            'aria-pressed',
            data.enabled
              ? 'true'
              : 'false'
          );
          reminder.textContent =
            data.enabled
              ? 'Reminder On'
              : 'Remind Me';
        } catch (error) {
          window.alert(
            error.message
          );
        }
        return;
      }

      const resume =
        event.target.closest(
          '[data-resume-track]'
        );

      if (resume) {
        playTrack(
          resume.dataset
            .resumeTrack,
          Number(
            resume.dataset
              .resumePosition ||
            0
          )
        );
        return;
      }

      if (
        event.target.closest(
          '[data-close-player-drawer]'
        )
      ) {
        closePlayerDrawer();
        return;
      }

      if (
        event.target.closest(
          '[data-close-queue]'
        )
      ) {
        closeQueue();
        return;
      }

      if (
        event.target.closest(
          '[data-close-playlist-editor]'
        )
      ) {
        closePlaylistEditor();
        return;
      }

      const queuePlay =
        event.target.closest(
          '[data-queue-play]'
        );

      if (queuePlay) {
        const index =
          Number(
            queuePlay.dataset
              .queuePlay
          );

        if (
          queue[index]
        ) {
          queueIndex =
            index;
          queueActive =
            true;
          playTrack(
            queue[index],
            null,
            true
          );
          renderQueue();
        }
        return;
      }

      const queueUp =
        event.target.closest(
          '[data-queue-up]'
        );

      if (queueUp) {
        queueMove(
          Number(
            queueUp.dataset
              .queueUp
          ),
          -1
        );
        return;
      }

      const queueDown =
        event.target.closest(
          '[data-queue-down]'
        );

      if (queueDown) {
        queueMove(
          Number(
            queueDown.dataset
              .queueDown
          ),
          1
        );
        return;
      }

      const queueRemove =
        event.target.closest(
          '[data-queue-remove]'
        );

      if (queueRemove) {
        const index =
          Number(
            queueRemove.dataset
              .queueRemove
          );

        queue.splice(
          index,
          1
        );

        if (
          queueIndex >=
          queue.length
        ) {
          queueIndex =
            queue.length - 1;
        }

        saveQueue();
        renderQueue();
      }
    }
  );

  document.addEventListener(
    'click',
    event => {
      if (!queueActive) {
        return;
      }

      if (
        event.target ===
          nowPlayingPrevV76 ||
        event.target.closest?.(
          '#chatNowPlayingPrev,' +
          '[data-player-prev]'
        )
      ) {
        event.preventDefault();
        event.stopImmediatePropagation();
        queueOffset(-1);
        return;
      }

      if (
        event.target ===
          nowPlayingNextV76 ||
        event.target.closest?.(
          '#chatNowPlayingNext,' +
          '[data-player-next]'
        )
      ) {
        event.preventDefault();
        event.stopImmediatePropagation();
        queueOffset(1);
      }
    },
    true
  );

  queueOpen?.addEventListener(
    'click',
    openQueue
  );

  queueClear?.addEventListener(
    'click',
    () => {
      queue = [];
      queueIndex = -1;
      queueActive = false;
      saveQueue();
      renderQueue();
    }
  );

  queueList?.addEventListener(
    'dragstart',
    event => {
      const row =
        event.target.closest(
          '[data-queue-index]'
        );

      if (!row) {
        return;
      }

      event.dataTransfer.setData(
        'text/plain',
        row.dataset.queueIndex
      );
      event.dataTransfer.effectAllowed =
        'move';
    }
  );

  queueList?.addEventListener(
    'dragover',
    event => {
      if (
        event.target.closest(
          '[data-queue-index]'
        )
      ) {
        event.preventDefault();
      }
    }
  );

  queueList?.addEventListener(
    'drop',
    event => {
      const row =
        event.target.closest(
          '[data-queue-index]'
        );

      if (!row) {
        return;
      }

      event.preventDefault();

      const from =
        Number(
          event.dataTransfer.getData(
            'text/plain'
          )
        );
      const to =
        Number(
          row.dataset.queueIndex
        );

      if (
        Number.isInteger(from) &&
        Number.isInteger(to) &&
        from !== to
      ) {
        const [id] =
          queue.splice(from,1);
        queue.splice(
          to,
          0,
          id
        );

        const currentAudio =
          document.querySelector(
            '.chat-audio-player[data-v76-current="1"]'
          );

        if (currentAudio) {
          queueIndex =
            queue.indexOf(
              Number(
                currentAudio.dataset
                  .trackId ||
                0
              )
            );
        }

        saveQueue();
        renderQueue();
      }
    }
  );

  playlistEditorTracks?.addEventListener(
    'click',
    event => {
      const remove =
        event.target.closest(
          '[data-editor-remove-track]'
        );

      if (remove) {
        remove.closest(
          '[data-playlist-track]'
        )?.remove();
        return;
      }

      const add =
        event.target.closest(
          '[data-editor-add-track]'
        );

      if (add) {
        const id =
          Number(
            add.dataset
              .editorAddTrack
          );
        const track =
          tracks[id];

        if (!track) {
          return;
        }

        const selected =
          playlistEditorTracks.querySelector(
            '[data-playlist-selected]'
          );

        const row =
          document.createElement(
            'article'
          );
        row.className =
          'chat-playlist-editor-track selected';
        row.draggable = true;
        row.dataset.playlistTrack =
          String(id);
        row.innerHTML = `
          <span class="drag">⋮⋮</span>
          <img src="${escapeHtml(track.cover)}" alt="">
          <div>
            <strong>${escapeHtml(track.title)}</strong>
            <small>${escapeHtml(track.album)}</small>
          </div>
          <button type="button" data-editor-remove-track="${id}">Remove</button>
        `;

        selected?.appendChild(
          row
        );
        add.remove();
      }
    }
  );

  let editorDragId = 0;

  playlistEditorTracks?.addEventListener(
    'dragstart',
    event => {
      const row =
        event.target.closest(
          '[data-playlist-track]'
        );

      if (!row) {
        return;
      }

      editorDragId =
        Number(
          row.dataset
            .playlistTrack
        );
      event.dataTransfer.effectAllowed =
        'move';
    }
  );

  playlistEditorTracks?.addEventListener(
    'dragover',
    event => {
      if (
        event.target.closest(
          '[data-playlist-track]'
        )
      ) {
        event.preventDefault();
      }
    }
  );

  playlistEditorTracks?.addEventListener(
    'drop',
    event => {
      const target =
        event.target.closest(
          '[data-playlist-track]'
        );

      const moving =
        playlistEditorTracks.querySelector(
          `[data-playlist-track="${editorDragId}"]`
        );

      if (
        !target ||
        !moving ||
        target === moving
      ) {
        return;
      }

      event.preventDefault();

      target.parentNode.insertBefore(
        moving,
        target
      );
    }
  );

  playlistEditorForm?.addEventListener(
    'submit',
    async event => {
      event.preventDefault();

      const id =
        Number(
          playlistEditorId.value ||
          0
        );

      try {
        await libraryApi(
          'playlist_update',
          {
            playlist_id:id,
            title:
              playlistEditorName.value,
            description:
              playlistEditorDescription.value,
            visibility:
              playlistEditorVisibility.value,
            track_ids:
              editorSelectedIds()
          }
        );

        window.location.href =
          `${window.location.pathname}?view=playlists`;
      } catch (error) {
        window.alert(
          error.message
        );
      }
    }
  );

  playlistEditorPlay?.addEventListener(
    'click',
    () => {
      setQueue(
        editorSelectedIds(),
        true
      );
    }
  );

  playlistEditorDuplicate?.addEventListener(
    'click',
    async () => {
      try {
        await libraryApi(
          'playlist_duplicate',
          {
            playlist_id:Number(
              playlistEditorId.value ||
              0
            )
          }
        );
        window.location.href =
          `${window.location.pathname}?view=playlists`;
      } catch (error) {
        window.alert(
          error.message
        );
      }
    }
  );

  playlistEditorDelete?.addEventListener(
    'click',
    async () => {
      if (
        !window.confirm(
          'Delete this playlist?'
        )
      ) {
        return;
      }

      try {
        await libraryApi(
          'playlist_delete',
          {
            playlist_id:Number(
              playlistEditorId.value ||
              0
            )
          }
        );
        window.location.href =
          `${window.location.pathname}?view=playlists`;
      } catch (error) {
        window.alert(
          error.message
        );
      }
    }
  );

  playlistEditorShare?.addEventListener(
    'click',
    async () => {
      const id =
        Number(
          playlistEditorId.value ||
          0
        );
      const playlist =
        playlists[id];

      if (!playlist) {
        return;
      }

      try {
        if (
          playlistEditorVisibility.value !==
          'public'
        ) {
          const confirmed =
            window.confirm(
              'Sharing requires a public playlist. Make this playlist public and copy its share link?'
            );

          if (!confirmed) {
            return;
          }

          await libraryApi(
            'playlist_update',
            {
              playlist_id:id,
              title:
                playlistEditorName.value,
              description:
                playlistEditorDescription.value,
              visibility:'public',
              track_ids:
                editorSelectedIds()
            }
          );

          playlist.visibility =
            'public';
          playlistEditorVisibility.value =
            'public';
        }

        const link =
          `${window.location.origin}${window.location.pathname}?view=playlists&playlist=${id}`;

        try {
          await navigator.clipboard.writeText(
            link
          );
          playlistEditorShare.textContent =
            'Copied ✓';
        } catch (error) {
          window.prompt(
            'Copy playlist link:',
            link
          );
        }

        setTimeout(
          () => {
            playlistEditorShare.textContent =
              'Share';
          },
          1500
        );
      } catch (error) {
        window.alert(
          error.message ||
          'Could not share playlist.'
        );
      }
    }
  );

  document.addEventListener(
    'play',
    event => {
      const audio =
        event.target;

      if (
        !audio.matches?.(
          '.chat-audio-player'
        )
      ) {
        return;
      }

      const id =
        Number(
          audio.dataset.trackId ||
          0
        );

      document.querySelectorAll(
        '.chat-audio-player[data-v76-current="1"]'
      ).forEach(
        candidate =>
          delete candidate.dataset.v76Current
      );

      audio.dataset.v76Current =
        '1';

      const index =
        queue.indexOf(id);

      if (index >= 0) {
        queueIndex =
          index;
        renderQueue();
      }
    },
    true
  );

  document.addEventListener(
    'ended',
    event => {
      const audio =
        event.target;

      if (
        !queueActive ||
        !audio.matches?.(
          '.chat-audio-player'
        ) ||
        !queue.length
      ) {
        return;
      }

      event.stopPropagation();

      const id =
        Number(
          audio.dataset.trackId ||
          0
        );
      const index =
        queue.indexOf(id);

      let nextIndex =
        index >= 0
          ? index + 1
          : 0;

      if (
        nextIndex >=
        queue.length
      ) {
        queueActive = false;
        return;
      }

      queueIndex =
        nextIndex;

      setTimeout(
        () => {
          playTrack(
            queue[nextIndex],
            null,
            true
          );
          renderQueue();
        },
        80
      );
    },
    true
  );

  document.addEventListener(
    'keydown',
    event => {
      if (event.key !== 'Escape') {
        return;
      }

      closeActionMenu();
      closePlayerDrawer();
      closeQueue();
      closePlaylistEditor();
    }
  );

  const observer =
    new MutationObserver(
      mutations => {
        for (
          const mutation
          of mutations
        ) {
          mutation.addedNodes
            .forEach(node => {
              if (
                node.nodeType ===
                Node.ELEMENT_NODE
              ) {
                decorateTrackActions(
                  node.matches?.(
                    '.chat-audio-player'
                  )
                    ? node.parentElement ||
                      document
                    : node
                );
              }
            });
        }
      }
    );

  observer.observe(
    document.body,
    {
      childList:true,
      subtree:true
    }
  );

  decorateTrackActions(
    document
  );
  renderQueue();

  const requestedPlaylist =
    Number(
      new URLSearchParams(
        window.location.search
      ).get('playlist') || 0
    );

  if (
    requestedPlaylist > 0 &&
    playlists[requestedPlaylist]
  ) {
    setTimeout(
      () => {
        const playlist =
          playlists[
            requestedPlaylist
          ];

        if (playlist.owned) {
          openPlaylistEditor(
            requestedPlaylist
          );
          return;
        }

        const card =
          document.querySelector(
            `[data-playlist-card="${requestedPlaylist}"]`
          );

        card?.scrollIntoView({
          block:'center',
          behavior:'smooth'
        });

        card?.classList.add(
          'shared-focus'
        );
      },
      120
    );
  }

})();
