(() => {
  const cfg = window.STONEFELLOW_CHAT;
  const initiativePage = {owner:Number(cfg.userId || 0),agent:Number(cfg.agentId || 0),
    binding:String(cfg.workingAttentionBinding || ''),csrf:String(cfg.csrf || ''),endpoint:String(cfg.endpoint || '')};
  const thread = document.getElementById('chatThread');
  const welcome = document.getElementById('chatWelcome');
  const form = document.getElementById('chatForm');
  const composerShell = document.getElementById('chatComposerShell');
  const input = document.getElementById('chatInput');
  const send = document.getElementById('sendChatButton');
  const voiceButton = document.getElementById('chatVoiceButton');
  const voiceStatus = document.getElementById('chatVoiceStatus');
  const audioFeedbackHosts = new WeakMap();
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
    const savedVolume = localStorage.getItem(PLAYER_VOLUME_KEY);
    const storedVolume = Number(savedVolume);
    if (savedVolume !== null && savedVolume.trim() !== '' && Number.isFinite(storedVolume)) {
      playerVolume = Math.max(0, Math.min(1, storedVolume));
    }
  } catch (error) {}

  let conversationId = 0;
  let historyAuthority = 0, historyParticipant = 0;
  window.addEventListener('vp3:participant-selection-changed',() => { historyAuthority++; });
  window.addEventListener('vp3:participant-task-context',event => {
    const participant = Math.max(0, Number(event.detail?.participant_id) || 0);
    if (participant !== historyParticipant) historyAuthority++;
    historyParticipant = participant;
  });
  let lastLoadedMessageId = 0;
  const pendingAssistantMessages = new Map();
  let busy = false;
  let chatRevision = 0, pendingSend = null, restoringConversation = 0, lastParticipantQuestion = null;
  let youtubeLocalTurn = null;
  let initialRestorePending = true, contextReady = false;
  let initiativeReplyTarget = null, initiativeReplyCandidate = null, initiativeReplyFenceTimer = 0;
  let initiativeVisualRetry = null;
  const initiativePosts = new Map(), initiativeOutcomes = new Map();
  const initiativeReplyStates = new Set(['open','discussed','deferred','queued','stale']);
  const initiativeStates = new Set(['open','discussed','queued','deferred','declined','owner_resolved','completed','stale']);
  const recoveryKey = `vp3:chat:drafts:${Number(cfg.userId || 0)}:${Number(cfg.agentId || 0)}`;
  const selectionKey = `vp3:chat:active:${Number(cfg.userId || 0)}:${Number(cfg.agentId || 0)}`;
  function recoveryDrafts() {
    try {
      const value = JSON.parse(sessionStorage.getItem(recoveryKey) || '{}');
      return Object.fromEntries(Object.entries(value).filter(([id, draft]) =>
        /^\d+$/.test(id) && typeof draft?.text === 'string' && draft.text.length <= 32000 &&
        Number.isFinite(draft.at) && draft.at <= Date.now() && Date.now() - draft.at < 86400000));
    } catch (_) { return {}; }
  }
  function keepDraft(text, id = conversationId) {
    if (gmailReviewActionLabel(text)) return;
    const value = recoveryDrafts();
    const target=initiativeReplyTarget && initiativeReplyTarget.conversation_id===Number(id) && initiativeIdentityMatchesV1()
      ? initiativeReplyTarget : null;
    if (String(text || '').trim() || target) value[id] = {text:String(text || '').slice(0,32000), at:Date.now(),
      ...(target ? {initiative_reply:{message_id:target.message_id,reply_ref:target.reply_ref,revision:target.revision},
        initiative_binding:initiativePage.binding,initiative_stale:target.stale===true,
        initiative_retry:target.retry_message===String(text || '').trim()} : {})};
    else delete value[id];
    try { sessionStorage.setItem(recoveryKey, JSON.stringify(Object.fromEntries(
      Object.entries(value).sort((a,b) => b[1].at-a[1].at).slice(0,8)))); } catch (_) {}
  }
  function restoreDraft() {
    const saved=recoveryDrafts()[conversationId];
    input.value = saved?.text || ''; autoSize();
    initiativeReplyCandidate=saved?.initiative_reply ? {reply:saved.initiative_reply,binding:saved.initiative_binding,stale:saved.initiative_stale===true,retry:saved.initiative_retry===true} : null;
  }
  function rememberConversation() {
    try { localStorage.setItem(selectionKey, JSON.stringify({id:conversationId, at:Date.now()})); } catch (_) {}
    if(contextReady)try{const url=new URL(location.href);if(url.searchParams.has('conversation_id')){url.searchParams.delete('conversation_id');history.replaceState(null,'',url.pathname+url.search+url.hash);}}catch(_){}
  }
  function initialConversation() {
    const requested=Number(new URLSearchParams(location.search).get('conversation_id')||0);
    if(requested>0 && requested===Number(cfg.initialConversationId))return requested;
    try {
      const saved = JSON.parse(localStorage.getItem(selectionKey));
      if (Number.isSafeInteger(saved?.id) && saved.id >= 0 && Number.isFinite(saved.at) &&
          saved.at <= Date.now() && Date.now()-saved.at < 2592000000) return saved.id;
    } catch (_) {}
    return Number(cfg.initialConversationId || 0);
  }
  function showStop() {
    let button=document.getElementById('chatStopButton');
    if(!button){button=document.createElement('button');button.id='chatStopButton';button.type='button';button.textContent='■';button.setAttribute('aria-label','Stop response');form.appendChild(button);button.addEventListener('click',()=>{
      const request=pendingSend;if(!request)return;
      leaveChat({preserveInitiativeReply:true}); if(!input.value&&!gmailReviewActionLabel(request.message))input.value=request.message;autoSize();keepDraft(input.value,request.conversationId);
      recoveryStatus('Response stopped.');
    });}
    button.hidden=!pendingSend;
  }
  function recoveryStatus(message = '') {
    let host = document.getElementById('chatRecoveryStatus');
    if (!host && !message) return;
    if (!host) {
      host = document.createElement('div'); host.id = 'chatRecoveryStatus'; host.setAttribute('role','status');
      (document.getElementById('chatComposerShell') || form.parentElement).appendChild(host);
    }
    host.hidden = !message; host.textContent = message;
  }
  function leaveChat(options={}) {
    lastParticipantQuestion=null;
    initiativeVisualRetry=null;
    pendingAssistantMessages.clear();
    if (!initialRestorePending) keepDraft(pendingSend?.message || input.value, pendingSend?.conversationId ?? conversationId);
    if(!options.preserveInitiativeReply){clearInitiativeReplyV1();initiativeReplyCandidate=null;initiativePosts.clear();initiativeOutcomes.clear();}
    chatRevision++;
    cancelYoutubeLocalTurn();
    if (pendingSend) { clearTimeout(pendingSend.timer); pendingSend.controller.abort(); pendingSend.typing?.remove(); pendingSend = null; busy = false; send.disabled = false; input.disabled=false; showStop(); }
    window.dispatchEvent(new CustomEvent('stonefellow:chat-context-changing',{detail:{preserveTranscriptSource:options.preserveInitiativeReply===true}}));
    recoveryStatus();
  }
  function clearPrivateYoutubeHistory() {
    cancelYoutubeLocalTurn();
    thread.querySelectorAll('[data-vp3-youtube-private]').forEach(row => row.remove());
  }
  window.addEventListener('vp3:participant-selection-changed',clearPrivateYoutubeHistory);
  window.addEventListener('vp3:participant-task-context',()=>{
    clearPrivateYoutubeHistory();
    if(initiativeReplyTarget){clearInitiativeReplyV1();keepDraft(input.value);recoveryStatus('The participant changed. Choose the Agent update again before replying.');}
    if(!pendingSend)return;
    const request=pendingSend;keepDraft(request.message,request.conversationId);chatRevision++;
    clearTimeout(request.timer);request.controller.abort();request.typing?.remove();pendingSend=null;busy=false;send.disabled=false;input.disabled=false;showStop();
    recoveryStatus('Participant changed. Previous response stopped.');
  });
  function clearPrivateRecovery() {
    leaveChat();
    try { sessionStorage.removeItem(recoveryKey); localStorage.removeItem(selectionKey); } catch (_) {}
    conversationId = 0; contextReady=false; input.value = ''; thread.querySelectorAll('.message').forEach(node => node.remove());
    history.replaceChildren(); recoveryStatus('Sign in again to continue.');
  }
  // The displayed conversation owns edits until a history load commits its new
  // selection. Persist them even while loading; Send remains gated separately.
  input.addEventListener('input', () => { if (!busy && !initialRestorePending) {
    if(initiativeVisualRetry&&initiativeVisualRetry.message!==input.value.trim())initiativeVisualRetry=null;
    if(initiativeReplyTarget?.retry_message&&initiativeReplyTarget.retry_message!==input.value.trim()){
      delete initiativeReplyTarget.retry_message;
      if(initiativePosts.get(initiativeReplyTarget.message_id)?.revision!==initiativeReplyTarget.revision)initiativeReplyTarget.stale=true;
      renderInitiativeReplyV1();
    }
    keepDraft(input.value); recoveryStatus();
  } });
  window.addEventListener('pagehide', () => { if (!initialRestorePending) keepDraft(pendingSend?.message || input.value, pendingSend?.conversationId ?? conversationId); pendingSend?.controller.abort(); });
  // Run before planner interception so offline/restoring turns cannot dispatch tools.
  document.addEventListener('submit', event => {
    if (event.target !== form || (contextReady && !initialRestorePending && !restoringConversation && navigator.onLine !== false)) return;
    event.preventDefault(); event.stopImmediatePropagation();
    if (!initialRestorePending && !restoringConversation) keepDraft(input.value);
    window.dispatchEvent(new CustomEvent('stonefellow:chat-context-changing',{detail:{preserveTranscriptSource:true}}));
    recoveryStatus(!contextReady ? 'Open a conversation or start a new chat before sending.' : navigator.onLine === false ? 'You are offline. Your message is kept.' : 'Restoring your conversation. Your message is kept.');
  }, true);
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
    window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));

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
    leaveChat();
    conversationId = 0; contextReady=true;
    rememberConversation(); restoreDraft();
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

  async function api(payload, signal) {
    const headers = {'Content-Type':'application/json'};
    if (cfg.workingAttentionBinding) headers['X-VP3-Working-Attention'] = cfg.workingAttentionBinding;
    const response = await fetch(cfg.endpoint, {
      method: 'POST',
      headers,
      credentials: 'same-origin',
      signal,
      body: JSON.stringify({...payload, csrf_token: cfg.csrf})
    });

    const data = await response.json().catch(() => ({
      ok:false,
      error:'Invalid server response.'
    }));

    if (!response.ok || !data.ok) {
      const error = new Error(data.error || 'Request failed.');
      error.status = response.status;
      if (response.status === 401) clearPrivateRecovery();
      throw error;
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
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 15000);

    try {
      const activeConversationId = contextReady && !initialRestorePending && !restoringConversation ? conversationId : 0;
      const activityChatRevision=chatRevision;
      const selectedInitiativePost=initiativePosts.get(initiativeReplyTarget?.message_id);
      const initiativeMessageIds=activeConversationId>0&&initiativeIdentityMatchesV1()
        ? [...new Set([selectedInitiativePost?.element.isConnected&&selectedInitiativePost.conversation_id===activeConversationId?selectedInitiativePost.message_id:0,...Array.from(initiativePosts.values()).reverse()
          .filter(post=>post.conversation_id===activeConversationId&&post.element.isConnected
            &&(initiativeReplyStates.has(post.state)||post.unavailable)).map(post=>post.message_id)])]
          .filter(id=>Number.isSafeInteger(id)&&id>0).slice(0,12) : [];
      const data = await api({
        action:'activity',
        after_id:activityCursor,
        conversation_id:activeConversationId,
        ...(initiativeMessageIds.length?{initiative_message_ids:initiativeMessageIds}:{}),
        agent_context:{user_agent_id:Number(cfg.agentId || 0)}
      }, controller.signal);
      if(activityChatRevision===chatRevision&&activeConversationId===conversationId&&Number(data.conversation_id||0)===conversationId&&initiativeIdentityMatchesV1()
        &&Array.isArray(data.initiative_updates)&&data.initiative_updates.length<=12){
        for(const update of data.initiative_updates){
          if(!update||!initiativeMessageIds.includes(update.message_id))continue;
          const post=initiativePosts.get(update.message_id);if(!post)continue;
          if(update.available===false){
            post.unavailable=true;renderInitiativePostV1(post);
            if(initiativeReplyTarget?.message_id===post.message_id){initiativeReplyTarget.unavailable=true;renderInitiativeReplyV1();}
          }else if(update.available===true&&applyInitiativeFollowthroughV1(update.initiative_followthrough,true)){
            post.unavailable=false;post.recoverable=update.recoverable===true;
            post.child_execution_state=['failed','cancelled'].includes(update.child_execution_state)?update.child_execution_state:'';renderInitiativePostV1(post);
            if(initiativeReplyTarget?.message_id===post.message_id){initiativeReplyTarget.unavailable=false;renderInitiativeReplyV1();}
          }
        }
      }

      const hasPendingAssistant=pendingAssistantMessageIds().length>0;
      if(activeConversationId>0&&activeConversationId===conversationId&&Number(data.conversation_id||0)===conversationId
        &&(Number(data.latest_message_id||0)>lastLoadedMessageId||hasPendingAssistant)){
        pendingConversationSync=conversationId;
      }
      if(pendingConversationSync>0&&!busy&&!restoringConversation&&pendingConversationSync===activeConversationId
        &&pendingConversationSync===conversationId&&Number(data.conversation_id||0)===conversationId
        &&(Number(data.latest_message_id||0)>lastLoadedMessageId||hasPendingAssistant)){
        await syncConversationMessagesV101(pendingConversationSync, controller.signal);
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

    } catch (error) {
      // A later poll retries after transient failures; notifications remain in their drawer.
    } finally {
      window.clearTimeout(timeout);
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
        audio.preload = 'none';
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

      const feedbackHost = button.closest('.chat-stem-copy');
      if (feedbackHost) audioFeedbackHosts.set(audio, feedbackHost);
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
      if(navigator.sendBeacon(cfg.playbackEndpoint,new Blob([body],{type:'application/json'})))return null;
    }

    const controller=new AbortController();
    const timeout=setTimeout(()=>controller.abort(),15000);
    try {
      const response = await fetch(cfg.playbackEndpoint, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        keepalive:true,
        signal:controller.signal,
        body
      });
      return await response.json().catch(() => null);
    } catch (error) {
      return null;
    }finally{clearTimeout(timeout);}
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
      if(Number(conversationId)>0)target.searchParams.set('conversation_id',String(conversationId));
      if(window.VP3ConversationControls?.isEnabled?.())target.searchParams.set('voice','1');
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
            preload="none"
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
      return {sources:[],media:[],stem_media:[],actions:[],cards:[],system_app_action_card:null,playlist_title:''};
    }

    if (Array.isArray(raw)) {
      return {sources:raw,media:[],stem_media:[],actions:[],cards:[],system_app_action_card:null,playlist_title:''};
    }

    return {
      source:typeof raw.source==='string' ? raw.source : '',
      tool_receipt:raw.tool_receipt,
      sources:Array.isArray(raw.sources) ? raw.sources : [],
      media:Array.isArray(raw.media) ? raw.media : [],
      stem_media:Array.isArray(raw.stem_media) ? raw.stem_media : [],
      actions:Array.isArray(raw.actions) ? raw.actions : [],
      cards:Array.isArray(raw.cards) ? raw.cards : (Array.isArray(raw.cognitive_cards) ? raw.cognitive_cards : []),
      system_app_action_card:raw.system_app_action_card && typeof raw.system_app_action_card === 'object' ? raw.system_app_action_card : null,
      knowledge_actions_r9:raw.knowledge_actions_r9 || null,
      canvas_media:raw.canvas_media && typeof raw.canvas_media==='object' ? raw.canvas_media : null,
      youtube_results:raw.youtube_results && typeof raw.youtube_results==='object' ? raw.youtube_results : null,
      profile_handoff:raw.profile_handoff && typeof raw.profile_handoff==='object' ? raw.profile_handoff : null,
      profile_call:raw.profile_call && typeof raw.profile_call==='object' ? raw.profile_call : null,
      profile_speech:raw.profile_speech && typeof raw.profile_speech==='object' ? raw.profile_speech : null,
      profile_attention:raw.profile_attention && typeof raw.profile_attention==='object' ? raw.profile_attention : null,
      initiative_work:raw.initiative_work && typeof raw.initiative_work==='object' ? raw.initiative_work : null,
      research_canvas_v1:raw.source==='agent_initiative' ? raw.research_canvas_v1 : null,
      gmail_inbox_review_v1:['agent_connection_gmail_first_review','agent_connection_gmail_inbox_update'].includes(raw.source) ? raw.gmail_inbox_review_v1 : null,
      initiative_followthrough:raw.initiative_followthrough && typeof raw.initiative_followthrough==='object' ? raw.initiative_followthrough : null,
      initiative_action:raw.initiative_action && typeof raw.initiative_action==='object' ? raw.initiative_action : null,
      meeting_action:raw.meeting_action && typeof raw.meeting_action==='object' ? raw.meeting_action : null,
      product_launch:raw.source===(raw.product_launch?.stage==='promotion_outcomes' ? 'agent_promotion_outcome' : 'agent_product_launch')
        ? raw.product_launch : null,
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
        <audio class="chat-stem-preview" controls preload="none" src="${escapeHtml(item.audio||'')}"></audio>
        <div class="chat-stem-result-actions">
          ${item.song_audio?`<button type="button" data-play-track="${Number(item.track_id)}" data-song-audio="${escapeHtml(item.song_audio)}" data-song-title="${escapeHtml(song)}" data-song-album="${escapeHtml(album||'Stonefellow')}" data-song-cover="${escapeHtml(item.cover||'')}" data-song-detail="${escapeHtml(item.song_detail||'')}">Play Full Song</button>`:'<span>Full song audio is not available.</span>'}
          <a href="${escapeHtml(item.song_detail||'#')}">Song Info</a>
          ${studio?`<a href="${escapeHtml(studio)}">Stem Studio</a>`:''}
        </div>
      </div>
    </article>`;
  }

  function showChatMediaError(audio, failed) {
    const host = audioFeedbackHosts.get(audio) || audio.closest('.chat-stem-copy') || audio.parentElement;
    if (!host) return;
    let status = host.querySelector('[data-chat-media-status]');
    if (!status && failed) {
      status = document.createElement('p');
      status.dataset.chatMediaStatus = '';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      status.style.whiteSpace='pre-wrap';
      host.appendChild(status);
    }
    if(!status)return;
    const marker={};status.mediaDebugMarker=marker;
    status.hidden=!failed;
    if(!failed){status.textContent='';return;}
    const source=audio.currentSrc||audio.getAttribute('src')||'';
    let url;try{url=new URL(source,location.href);}catch{status.textContent='Audio is unavailable. Debug: no valid audio URL.';return;}
    const track=url.searchParams.get('track')||audio.dataset.trackId||'';
    const stem=url.searchParams.get('id')||'';
    const browserCode=Number(audio.error?.code||0);
    const heading=`Audio is unavailable. Playback debug${track?' · track #'+track:stem?' · stem #'+stem:''} · browser error ${browserCode}.`;
    status.textContent=heading+' Checking the media endpoint…';
    if(url.origin!==location.origin||!/^\/(?:.*\/)?(?:media|stem-media(?:-v34)?)\.php$/.test(url.pathname)){
      status.textContent=heading+' Diagnostic request skipped for an external or unsupported endpoint.';return;
    }
    url.searchParams.set('debug','1');
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),8000);
    fetch(url.href,{method:'HEAD',credentials:'same-origin',cache:'no-store',redirect:'manual',signal:controller.signal}).then(response=>{
      if(status.mediaDebugMarker!==marker||!status.isConnected||(audio.currentSrc||audio.getAttribute('src')||'')!==source)return;
      const raw=response.headers.get('X-Stonefellow-Media-Status')||'unknown';
      const reason=/^[a-z0-9_-]{1,64}$/i.test(raw)?raw:'unknown';
      const meanings={track_unavailable:'Track not found or unavailable to this account.',access_denied:'Track access denied.',audio_path_empty:'No audio path is saved on this track.',stored_file_unavailable:'The saved file path could not be resolved to readable, non-empty audio.',file_empty:'The saved audio file is empty.',file_unreadable:'The audio file could not be opened.',unsupported_audio:'The server did not recognize a supported audio format.',ok:'The server can serve audio. Check browser format/decoding if playback still fails.'};
      const type=(response.headers.get('Content-Type')||'unknown').split(';')[0].slice(0,80);
      let detail='';try{const encoded=response.headers.get('X-Stonefellow-Media-Debug');if(encoded){const d=JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(encoded),c=>c.charCodeAt(0))));detail='\nSaved path: '+String(d.stored_path||'(empty)').slice(0,1200)+'\nChecked location: '+String(d.checked_path||'').slice(0,1600)+'\nFile exists: '+(d.file_exists===true?'yes':'no')+' · readable: '+(d.readable===true?'yes':'no')+' · bytes: '+(Number.isFinite(d.bytes)?d.bytes:'unknown');}}catch{}
      status.textContent=heading+'\n'+url.pathname+' · HTTP '+response.status+' · '+reason+' · '+type+'\n'+(response.type==='opaqueredirect'?'The request redirected; your sign-in may have expired.':meanings[reason]||'Copy this notification so we can trace the server response.')+detail;
    }).catch(error=>{
      if(status.mediaDebugMarker===marker&&status.isConnected)status.textContent=heading+' Debug request '+(error.name==='AbortError'?'timed out.':'failed before an HTTP response was received.');
    }).finally(()=>clearTimeout(timer));
  }

  // Media errors do not bubble. Capture them for native stem controls and custom players.
  document.addEventListener('error', event => {
    const audio = event.target;
    if (audio.matches?.('.chat-audio-player, .chat-stem-preview')) showChatMediaError(audio, true);
  }, true);
  document.addEventListener('playing', event => {
    const audio = event.target;
    if (audio.matches?.('.chat-audio-player, .chat-stem-preview')) showChatMediaError(audio, false);
  }, true);

  function systemAppActionCardHtml(card) {
    if (!card || typeof card !== 'object') return '';
    const status=String(card.status||'prepared');
    const details=Array.isArray(card.details)?card.details:[];
    const actions=Array.isArray(card.actions)?card.actions:[];
    const recordedLabels=['Queued','Pending','Saved locally','Verified'];
    const statusLabel=status==='recorded'?(recordedLabels.includes(card.outcome_label)?card.outcome_label:'Recorded'):status==='completed'?'Completed':status==='failed'?'Needs attention':'Confirmation required';
    const detailsHtml=details.length
      ? '<dl class="chat-system-app-action-details">'+details.map(item=>'<div><dt>'+escapeHtml(item.label||'')+'</dt><dd>'+escapeHtml(item.value||'')+'</dd></div>').join('')+'</dl>'
      : '';
    const code=String(card.confirmation_code||'');
    const codeHtml=status==='prepared'&&code
      ? '<div class="chat-system-app-action-code"><span>Confirmation code</span><strong>'+escapeHtml(code)+'</strong></div>'
      : '';
    const actionHtml=actions.length
      ? '<div class="chat-system-app-action-buttons">'+actions.map(action=>{
          if(action.type==='prompt'&&action.prompt){
            return '<button type="button" class="chat-system-app-action-button '+escapeHtml(action.kind||'secondary')+'" data-chat-prompt-action="'+escapeHtml(action.prompt)+'">'+escapeHtml(action.label||'Continue')+'</button>';
          }
          if(action.type==='open_url'&&action.url){
            return '<a class="chat-system-app-action-button '+escapeHtml(action.kind||'secondary')+'" href="'+escapeHtml(withStudioReturn(action.url))+'">'+escapeHtml(action.label||'Open')+'</a>';
          }
          return '';
        }).join('')+'</div>'
      : '';
    const pending=card.reconcile_pending
      ? '<div class="chat-system-app-action-note">Hosting or app reconciliation remains pending. Check Apps before preparing another request.</div>'
      : '';
    const error=card.error
      ? '<div class="chat-system-app-action-error">'+escapeHtml(card.error)+'</div>'
      : '';
    return '<section class="chat-system-app-action-card '+escapeHtml(status)+'" data-system-app-action-card data-system-app-action-id="'+escapeHtml(card.action_id||'')+'">'
      +'<header><div><small>System App action</small><strong>'+escapeHtml(card.title||'System App action')+'</strong></div><span>'+escapeHtml(statusLabel)+'</span></header>'
      +'<p>'+escapeHtml(card.summary||'')+'</p>'
      +detailsHtml+codeHtml+pending+error+actionHtml
      +'</section>';
  }

  function agentActionHtml(action) {
    if (!action) return '';
    if (action.type === 'email_review' && /^[a-f0-9]{32}$/.test(String(action.id || '')) && Number.isSafeInteger(action.conversation_id) && action.conversation_id > 0) {
      const label = action.sent === true ? 'View sent reply' : (action.is_reply === true ? 'Review reply' : 'Review email');
      const queued = action.writing === true ? ' data-chat-email-writing="1"' : '';
      return '<button class="chat-agent-action-button" type="button" data-chat-email-review="'+escapeHtml(action.id)+'" data-chat-email-conversation="'+action.conversation_id+'"'+queued+'>'+label+'</button>';
    }
    if (action.type === 'gmail_followup' && Number.isSafeInteger(action.message_id) && action.message_id > 0
        && Number.isSafeInteger(action.conversation_id) && action.conversation_id > 0) {
      const buttons = [['open','Open message']];
      if (action.can_reply === true) buttons.push(['reply','Reply'],['compose','Auto Compose (uses tokens)']);
      return buttons.map(([kind,label]) => `<button class="chat-agent-action-button" type="button" data-chat-email-followup="${action.message_id}" data-chat-email-conversation="${action.conversation_id}" data-chat-email-followup-action="${kind}">${label}</button>`).join('');
    }
    if (action.type === 'gmail_message' && typeof action.item_ref === 'string' && /^[a-f0-9]{2,1500}$/.test(action.item_ref)
        && Number.isSafeInteger(action.conversation_id) && action.conversation_id > 0) {
      return `<button class="chat-agent-action-button" type="button" data-chat-email-search="${action.item_ref}" data-chat-email-conversation="${action.conversation_id}">${escapeHtml(action.label || 'Open email')}</button>`;
    }
    if(action.type==='participant_setup'&&/^[a-f0-9]{32}$/.test(String(action.token||''))&&Number.isInteger(action.conversation_id)&&action.conversation_id>0)return '<button class="chat-agent-action-button" type="button" data-participant-setup-token="'+escapeHtml(action.token)+'" data-participant-setup-conversation="'+action.conversation_id+'">Review participant setup</button>';
    if (action.type === 'voice_enrollment') return '<button class="chat-agent-action-button" type="button" data-voice-enrollment-open>Learn my voice</button>';
    if (action.type === 'media_capture') {
      return `<button class="chat-agent-action-button" type="button" data-media-agent-mode="${escapeHtml(action.mode||'camera')}" data-media-camera-index="${Number(action.camera_index||0)}">${escapeHtml(action.label||'Open Camera')}</button>`;
    }
    if (action.type === 'prompt' && action.prompt) {
      return `<button class="chat-agent-action-button" type="button" data-chat-prompt-action="${escapeHtml(action.prompt)}">${escapeHtml(action.label||'Continue')}</button>`;
    }
    if (!action.url) return '';
    return `<a class="chat-agent-action" href="${escapeHtml(withStudioReturn(action.url))}">${escapeHtml(action.label||'Open')}</a>`;
  }

  function catchUpSourceLinks(sources) {
    if (!Array.isArray(sources)) return '';
    const links = [];
    for (const source of sources) {
      if (typeof source?.source !== 'string' || !source.source.startsWith('agent-catch-up:') ||
          typeof source.url !== 'string' || !source.url.trim()) continue;
      let url;
      try { url = new URL(source.url, location.href); } catch (_) { continue; }
      if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin || url.username || url.password) continue;
      links.push(`<a class="message-source" href="${escapeHtml(url.href)}">${escapeHtml(source.title || 'Open source')}</a>`);
      if (links.length === 3) break;
    }
    return links.length ? `<div class="message-sources" aria-label="Priority sources">${links.join('')}</div>` : '';
  }

  function publicResearchSourceLinks(sources, kind = '') {
    if (!Array.isArray(sources)) return '';
    const links = [];
    let topic = false;
    for (const source of sources) {
      if (typeof source?.source !== 'string' || !/^(artist|topic)-web:[a-f0-9]{64}$/.test(source.source) || typeof source.url !== 'string' ||
          (kind && !source.source.startsWith(kind + '-web:')) ||
          !['page_excerpt', 'search_match'].includes(source.coverage)) continue;
      let url;
      try { url = new URL(source.url); } catch (_) { continue; }
      if (url.protocol !== 'https:' || url.username || url.password) continue;
      topic ||= source.source.startsWith('topic-web:');
      const coverage = source.coverage === 'page_excerpt' ? (kind ? 'Page excerpt' : 'Page read') : 'Search lead';
      const title = typeof source.title === 'string' && source.title.trim() ? source.title : 'Open source';
      links.push(`<a class="message-source" href="${escapeHtml(url.href)}" target="_blank" rel="noopener noreferrer">${escapeHtml(title)} · ${coverage}</a>`);
      if (links.length === 6) break;
    }
    return links.length ? `<div class="message-sources" data-public-research-sources role="group" aria-label="${topic ? 'Topic' : 'Artist'} research sources">${links.join('')}</div>` : '';
  }

  function bindResearchCanvasV1(element, report) {
    if (!report || !Number.isSafeInteger(report.owner_user_id) || report.owner_user_id < 1
      || report.owner_user_id !== Number(cfg.userId) || report.agent_id !== Number(cfg.agentId || 0)
      || report.conversation_id !== conversationId || !Number.isSafeInteger(report.run_id) || report.run_id < 1
      || !['topic', 'artist'].includes(report.kind) || !['read_complete', 'no_matches'].includes(report.status)
      || typeof report.title !== 'string' || !report.title.trim() || report.title.length > 240
      || typeof report.checked_at !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(report.checked_at)
      || !Array.isArray(report.sources) || report.sources.length > 6) return;

    const checked = new Date(report.checked_at);
    const hasSources = report.status === 'read_complete';
    const sources = publicResearchSourceLinks(report.sources, report.kind);
    if (Number.isNaN(checked.getTime()) || hasSources !== Boolean(sources) || (!hasSources && report.sources.length)) return;

    const body = element.querySelector('.message-body');
    const summary = body?.querySelector('.message-text');
    if (!summary || !summary.textContent.trim() || body.querySelector('[data-research-canvas-run-id]')) return;

    const canvas = document.createElement('section');
    canvas.className = 'vp3-research-canvas';
    canvas.dataset.researchCanvasRunId = String(report.run_id);
    canvas.tabIndex = -1;
    canvas.setAttribute('aria-label', 'Research Canvas');
    canvas.innerHTML = '<header><div><small>Research Canvas</small><h3></h3></div><p></p></header>';
    canvas.querySelector('h3').textContent = report.title;
    const details = canvas.querySelector('header p');
    if (!hasSources) details.append('No eligible sources · ');
    const date = document.createElement('time');
    date.dateTime = report.checked_at;
    date.textContent = new Intl.DateTimeFormat(undefined, {dateStyle:'medium', timeStyle:'short'}).format(checked);
    details.append('Checked ', date);
    summary.before(canvas);
    canvas.append(summary);
    body.querySelector(':scope > [data-public-research-sources]')?.remove();
    if (hasSources) canvas.insertAdjacentHTML('beforeend', sources);
  }

  function messageElement(role, text, sources = [], media = [], playlistTitle = '', stemMedia = [], actions = [], cards = [], systemAppActionCard = null, canvasMedia = null, youtubeResults = null) {
    const wrapper = document.createElement('article');
    wrapper.className = `message ${role}`;

    wrapper.innerHTML = `
      <div class="message-avatar">${role === 'user' ? 'You' : 'S'}</div>
      <div class="message-body">
        <div class="message-role">${role === 'user' ? 'You' : 'Stonefellow'}</div>
        <div class="message-text"></div>
        ${role === 'assistant' ? catchUpSourceLinks(sources) + publicResearchSourceLinks(sources) : ''}
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
        ${systemAppActionCard ? systemAppActionCardHtml(systemAppActionCard) : ''}
        ${actions.length ? `<div class="chat-agent-actions">${actions.map(agentActionHtml).join('')}</div>` : ''}
        ${cards.length ? '<div class="vp3-cognitive-card-host" data-cognitive-card-host></div>' : ''}
      </div>`;

    wrapper.querySelector('.message-text').textContent = text;
    if(canvasMedia){const host=document.createElement('section');host.className='vp3-canvas-media';host.setAttribute('aria-label','Chat media');host._vp3CanvasMediaRef=canvasMedia;wrapper.querySelector('.message-text').after(host);}
    if (role === 'assistant' && youtubeResults) window.VP3_CHAT_YOUTUBE_V1?.attach(wrapper, youtubeResults);
    if (cards.length) {
      const cardHost = wrapper.querySelector('[data-cognitive-card-host]');
      if (cardHost) {
        cardHost._vp3CognitiveCardRequests = cards;
        queueMicrotask(() => window.VP3_COGNITIVE_CARDS_V520_RUNTIME?.renderRequests(cards,cardHost,{showError:false}));
      }
    }
    attachAudioTracking(wrapper);
    return wrapper;
  }

  function addMessage(role, text, sources = [], media = [], playlistTitle = '', stemMedia = [], actions = [], cards = [], systemAppActionCard = null, knowledgeDraft = null, canvasMedia = null, youtubeResults = null) {
    if (welcome) welcome.hidden = true;
    const el = messageElement(role, text, sources, media, playlistTitle, stemMedia, actions, cards, systemAppActionCard, canvasMedia, youtubeResults);
    const listening=thread.querySelector('.chat-listening-continuation');
    if(listening)thread.insertBefore(el,listening);else thread.appendChild(el);
    window.VP3KnowledgeActionsR9?.show(knowledgeDraft,el.querySelector('.message-body')||el);
    if(systemAppActionCard && systemAppActionCard.action_id){
      const actionId=String(systemAppActionCard.action_id);
      if(String(systemAppActionCard.status||'')==='completed'){
        thread.querySelectorAll('[data-system-app-action-id]').forEach(card=>{
          if(card===el.querySelector('[data-system-app-action-id]'))return;
          if(String(card.dataset.systemAppActionId||'')!==actionId)return;
          card.classList.add('superseded');
          card.querySelectorAll('button').forEach(button=>{button.disabled=true;});
          const state=card.querySelector('header span');
          if(state)state.textContent='Completed';
        });
      }
    }
    thread.scrollTop = thread.scrollHeight;
    return el;
  }

  function addTyping() {
    if (welcome) welcome.hidden = true;
    const el = messageElement('assistant', 'Searching Stonefellow data');
    el.classList.add('typing');
    const listening=thread.querySelector('.chat-listening-continuation');
    if(listening)thread.insertBefore(el,listening);else thread.appendChild(el);
    thread.scrollTop = thread.scrollHeight;
    return el;
  }

  // A visitor card is attached to a server-created private Chat message. Its
  // marker contains identities only; the current owner projection is fetched
  // separately so reloading old history cannot restore stale reply authority.
  function bindStoredMessagePa3(element, id, marker = null, role = 'assistant', createdAt = '') {
    const messageId=Number(id);
    if(!element || !Number.isSafeInteger(messageId) || messageId<1)return;
    element.dataset.chatMessageId=String(messageId);
    element.dataset.chatConversationId=String(conversationId);
    element.dataset.chatMessageRole=String(role);
    if (typeof createdAt === 'string' && createdAt) element.dataset.chatCreatedAt = createdAt;
    if(role==='assistant'){
      window.dispatchEvent(new CustomEvent('vp3:chat-message-persisted',{detail:{element,conversationId,agentId:Number(cfg.agentId||0)}}));
    }
    if(!marker || marker.contract!=='vp3.profile-handoff.v1' ||
      !['turn_id','revision','conversation_id'].every(key=>Number.isSafeInteger(marker[key])&&marker[key]>0))return;
    element.dataset.profileHandoffTurn=String(marker.turn_id);
    element.dataset.profileHandoffRevision=String(marker.revision);
    element.dataset.profileHandoffConversation=String(marker.conversation_id);
    window.dispatchEvent(new CustomEvent('vp3:profile-handoff-message',{detail:{element}}));
  }

  function bindGmailResultTime(element, context, createdAt = '') {
    if (!element?.dataset.chatMessageId || element.dataset.chatMessageRole !== 'assistant'
      || ['agent_connection_gmail_first_review','agent_connection_gmail_inbox_update'].includes(context.source)) return;
    const receipt = context.tool_receipt;
    const gmailReceipt = receipt?.contract === 'vp3.tool-receipt.v1'
      && ['email.read','email.send','email.followups'].includes(receipt.capability);
    const gmailPost = ['agent_connection_email','agent_connection_email_followup'].includes(context.source)
      || Array.isArray(context.actions) && context.actions.some(action => ['email_review','gmail_followup','gmail_message'].includes(action?.type));
    if (!gmailReceipt && !gmailPost) return;

    const parse = value => typeof value === 'string'
      ? Date.parse(/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(value) ? value.replace(' ','T')+'Z' : value) : NaN;
    const posted = parse(createdAt);
    const checked = gmailReceipt ? parse(receipt.checked_at) : NaN;
    const at = Number.isFinite(posted) ? posted : checked;
    const heading = element.querySelector('.message-role');
    if (!Number.isFinite(at) || !heading) return;
    const date = new Date(at);
    let time = heading.querySelector('.vp3-chat-gmail-result-time');
    if (!time) {
      time = document.createElement('time');
      time.className = 'vp3-chat-gmail-result-time';
      heading.append(time);
    }
    time.dateTime = date.toISOString();
    time.textContent = (Number.isFinite(posted) ? 'Posted ' : 'Result ')+date.toLocaleString(undefined,{dateStyle:'medium',timeStyle:'short'});
  }

  function initiativeIdentityMatchesV1() {
    const current=window.STONEFELLOW_CHAT;
    return Boolean(initiativePage.binding) && current && Number(current.userId)===initiativePage.owner
      && Number(current.agentId || 0)===initiativePage.agent && String(current.workingAttentionBinding || '')===initiativePage.binding
      && String(current.csrf || '')===initiativePage.csrf && String(current.endpoint || '')===initiativePage.endpoint;
  }

  function validInitiativeReplyV1(value) {
    return value && Number.isSafeInteger(value.message_id) && value.message_id>0
      && typeof value.reply_ref==='string' && /^[a-f0-9]{64}$/.test(value.reply_ref)
      && Number.isSafeInteger(value.revision) && value.revision>0;
  }

  function clearInitiativeReplyV1(persist=false) {
    initiativeReplyTarget=null;
    window.clearInterval(initiativeReplyFenceTimer);initiativeReplyFenceTimer=0;
    document.getElementById('chatInitiativeReplyCue')?.remove();
    thread.querySelectorAll('[data-initiative-reply-selected]').forEach(node=>node.removeAttribute('data-initiative-reply-selected'));
    if(persist)keepDraft(input.value);
  }

  function renderInitiativeReplyV1() {
    document.getElementById('chatInitiativeReplyCue')?.remove();
    if(!initiativeReplyTarget)return;
    const target=initiativeReplyTarget,post=initiativePosts.get(target.message_id);
    const cue=document.createElement('div');cue.id='chatInitiativeReplyCue';cue.className='chat-initiative-reply-cue';cue.setAttribute('role','status');
    const copy=document.createElement('div'),title=document.createElement('strong'),detail=document.createElement('span');
    title.textContent=target.unavailable?'This Agent update could not be verified':target.stale?'This Agent update changed':target.retry_message?'Retrying your earlier reply':'Replying to Agent update';
    detail.textContent=target.unavailable?'Its current status is unavailable. Your reply is kept.':target.stale?'Choose Reply on the current update, or remove this reply context.':target.excerpt;
    copy.append(title,detail);
    const cancel=document.createElement('button');cancel.type='button';cancel.textContent='×';cancel.setAttribute('aria-label','Remove Agent reply context');
    cancel.addEventListener('click',()=>{clearInitiativeReplyV1(true);input.focus();});
    cue.append(copy,cancel);form.before(cue);
    if(post?.element?.isConnected)post.element.dataset.initiativeReplySelected='true';
    if(!initiativeReplyFenceTimer)initiativeReplyFenceTimer=window.setInterval(()=>{
      if(initiativeReplyTarget&&!initiativeIdentityMatchesV1()){
        clearInitiativeReplyV1(true);initiativeReplyCandidate=null;
        recoveryStatus('The account or session changed. Refresh Chat before replying to that update.');
      }
    },250);
  }

  function chooseInitiativeReplyV1(post,stale=false,focus=true) {
    if(!initiativeIdentityMatchesV1()||!post.element.isConnected||!thread.contains(post.element)
      ||post.conversation_id!==conversationId||busy||restoringConversation||!contextReady)return;
    clearInitiativeReplyV1();
    initiativeReplyTarget={message_id:post.message_id,reply_ref:post.reply_ref,revision:post.revision,
      conversation_id:conversationId,excerpt:String(post.element.querySelector('.message-text')?.textContent || 'Saved Agent update').trim().slice(0,150),stale};
    renderInitiativeReplyV1();keepDraft(input.value);if(focus)input.focus();
  }

  function restoreInitiativeReplyV1() {
    const saved=initiativeReplyCandidate;initiativeReplyCandidate=null;
    if(!saved)return;
    const post=validInitiativeReplyV1(saved.reply)?initiativePosts.get(saved.reply.message_id):null;
    if(saved.binding!==initiativePage.binding||!initiativeIdentityMatchesV1()||!post||post.reply_ref!==saved.reply.reply_ref){
      clearInitiativeReplyV1();
      if(validInitiativeReplyV1(saved.reply)&&initiativeIdentityMatchesV1()){
        initiativeReplyTarget={...saved.reply,conversation_id:conversationId,excerpt:'',stale:true};renderInitiativeReplyV1();
      }
      keepDraft(input.value);
      recoveryStatus('Your draft is kept. Its earlier Agent reply context is no longer available. Choose an update before replying.');return;
    }
    if(saved.retry&&!saved.stale&&input.value.trim()){
      initiativeReplyTarget={...saved.reply,conversation_id:conversationId,excerpt:String(post.element.querySelector('.message-text')?.textContent || '').trim().slice(0,150),retry_message:input.value.trim()};
      renderInitiativeReplyV1();keepDraft(input.value);return;
    }
    chooseInitiativeReplyV1(post,saved.stale||post.revision!==saved.reply.revision||!initiativeReplyStates.has(post.state),false);
  }

  function initiativeReplyForSendV1(message) {
    const target=initiativeReplyTarget;
    if(!target)return null;
    if(!initiativeIdentityMatchesV1()){
      clearInitiativeReplyV1(true);recoveryStatus('The account or session changed. Refresh Chat before replying to that update.');return false;
    }
    const post=initiativePosts.get(target.message_id);
    const exactRetry=target.retry_message&&target.retry_message===String(message || '').trim();
    if(target.unavailable||post?.unavailable){
      renderInitiativeReplyV1();keepDraft(input.value);
      recoveryStatus('This Agent update could not be verified. Your reply is kept. Try again when its status is available.');return false;
    }
    if(target.stale||!post||post.conversation_id!==conversationId||post.reply_ref!==target.reply_ref
      ||(!exactRetry&&(post.revision!==target.revision||!initiativeReplyStates.has(post.state)))){
      target.stale=true;renderInitiativeReplyV1();keepDraft(input.value);
      recoveryStatus('That Agent update changed. Choose its current Reply action or remove the reply context before sending.');return false;
    }
    return {message_id:target.message_id,reply_ref:target.reply_ref,revision:target.revision};
  }

  function renderInitiativePostV1(post) {
    post.replyButton?.remove();post.replyButton=null;
    const labels={discussed:'Discussed',queued:'Private work queued',deferred:'Deferred',declined:'Declined',
      owner_resolved:'Marked resolved by you',completed:'Private work completed',stale:'Follow-through needs review'};
    post.status.textContent=post.unavailable?'Status could not be verified.':post.recoverable&&['queued','stale'].includes(post.state)
      ?post.child_execution_state?'Private work interrupted. Reply to review or retry.':'Follow-through needs review':labels[post.state] || '';
    if(!post.unavailable&&initiativeReplyStates.has(post.state)){
      const button=document.createElement('button');button.type='button';button.className='chat-agent-action';
      button.dataset.initiativeReply=String(post.message_id);button.textContent='Reply';
      button.addEventListener('click',()=>chooseInitiativeReplyV1(post));
      post.actions.prepend(button);post.replyButton=button;
    }
  }

  function applyInitiativeFollowthroughV1(value,verifiedProjection=false) {
    if(!initiativeIdentityMatchesV1()||!validInitiativeReplyV1(value)||value.owner_user_id!==initiativePage.owner
      ||value.agent_id!==initiativePage.agent||value.conversation_id!==conversationId
      ||!Number.isSafeInteger(value.run_id)||value.run_id<1||!initiativeStates.has(value.state)
      ||!Number.isSafeInteger(value.reply_message_id)||value.reply_message_id<0
      ||!Number.isSafeInteger(value.continuation_run_id)||value.continuation_run_id<0)return false;
    if(value.reply_message_id===0&&(value.state!=='open'||value.revision!==1||value.continuation_run_id!==0))return false;
    const post=initiativePosts.get(value.message_id);
    const prior=initiativeOutcomes.get(value.message_id);
    // An authenticated current-state projection can supply the first complete
    // receipt binding after a reload whose history contains only the root.
    const sameExecutionCompletion=post?.state==='queued' && value.state==='completed' && post.revision===value.revision
      && (prior?.state==='queued' && prior.revision===value.revision
        && prior.reply_ref===value.reply_ref && prior.run_id===value.run_id && prior.reply_message_id===value.reply_message_id
        && prior.continuation_run_id>0 && prior.continuation_run_id===value.continuation_run_id
        || !prior&&verifiedProjection&&value.reply_message_id>0&&value.continuation_run_id>0);
    if(!post||post.reply_ref!==value.reply_ref||post.run_id!==value.run_id||value.revision<post.revision
      ||(value.revision===post.revision&&value.state!==post.state&&!sameExecutionCompletion))return false;
    if(prior&&(value.revision<prior.revision||(value.revision===prior.revision&&value.state!==prior.state&&!sameExecutionCompletion)))return false;
    initiativeOutcomes.set(value.message_id,{...value});
    if(value.revision>post.revision||value.state==='completed'){post.recoverable=false;post.child_execution_state='';}
    post.revision=value.revision;post.state=value.state;renderInitiativePostV1(post);
    const ownPending=initiativeReplyTarget&&pendingSend?.initiativeReply?.message_id===post.message_id
      &&pendingSend.initiativeReply.reply_ref===initiativeReplyTarget.reply_ref;
    const exactRetry=initiativeReplyTarget?.retry_message&&initiativeReplyTarget.retry_message===input.value.trim();
    if(initiativeReplyTarget?.message_id===post.message_id&&!ownPending&&!exactRetry
      &&(initiativeReplyTarget.revision!==post.revision||!initiativeReplyStates.has(post.state))){
      initiativeReplyTarget.stale=true;renderInitiativeReplyV1();keepDraft(input.value);
    }
    return true;
  }

  function bindInitiativeWorkV1(element, marker, source='') {
    if (!element || !marker
        || !Number.isSafeInteger(marker.run_id) || marker.run_id < 1
        || marker.owner_user_id !== Number(cfg.userId) || marker.agent_id !== Number(cfg.agentId || 0)
        || marker.conversation_id !== conversationId) return;
    const owner = Number(cfg.userId), agent = Number(cfg.agentId || 0), cid = conversationId;
    const current = () => element.isConnected && thread.contains(element) && conversationId === cid
      && Number(window.STONEFELLOW_CHAT?.userId) === owner && Number(window.STONEFELLOW_CHAT?.agentId || 0) === agent
      && String(window.STONEFELLOW_CHAT?.workingAttentionBinding || '') === String(cfg.workingAttentionBinding || '');
    const actions = document.createElement('div');
    actions.className = 'chat-agent-actions';
    actions.dataset.initiativeReviewActions = String(marker.run_id);
    const seen = new Set();
    for (const item of (Array.isArray(marker.review_actions)?marker.review_actions:[]).slice(0, 8)) {
      if (!item || !['product', 'screen', 'mission', 'marketing'].includes(item.tool)
          || typeof item.source_id !== 'string' || typeof item.source_revision !== 'string'
          || !/^[a-f0-9]{64}$/.test(item.source_revision)) continue;
      const validId = item.tool === 'product'
        ? /^[1-9][0-9]*$/.test(item.source_id) && Number.isSafeInteger(Number(item.source_id))
        : item.tool === 'marketing'
          ? /^[1-9][0-9]*-[1-9][0-9]*$/.test(item.source_id) && item.source_id.split('-').every(id => Number.isSafeInteger(Number(id)))
          : /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(item.source_id);
      const key = item.tool + ':' + item.source_id;
      if (!validId || seen.has(key)) continue;
      seen.add(key);
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.initiativeReview = item.tool;
      button.textContent = {product:'Review listing draft', marketing:'Review marketing draft', screen:'Review image edit', mission:'Review decision brief'}[item.tool];
      button.addEventListener('click', () => {
        if (!current()) { button.disabled = true; return; }
        if (typeof window.VP3InitiativeWorkbench?.open !== 'function') {
          button.textContent = 'Review unavailable'; button.disabled = true; return;
        }
        window.VP3InitiativeWorkbench.open(item.tool, item.source_id, item.source_revision);
      });
      actions.appendChild(button);
    }
    const followthrough=marker.followthrough,messageId=Number(element.dataset.chatMessageId);
    if(source==='agent_initiative'&&initiativeIdentityMatchesV1()&&Number.isSafeInteger(messageId)&&messageId>0
      &&validInitiativeReplyV1({...followthrough,message_id:messageId})&&initiativeStates.has(followthrough.state)){
      const status=document.createElement('small');status.className='chat-initiative-followthrough-state';status.dataset.initiativeFollowthroughState=String(messageId);actions.append(status);
      const post={message_id:messageId,owner_user_id:owner,agent_id:agent,conversation_id:cid,run_id:marker.run_id,
        reply_ref:followthrough.reply_ref,revision:followthrough.revision,state:followthrough.state,element,actions,status};
      initiativePosts.set(messageId,post);renderInitiativePostV1(post);
      const outcome=initiativeOutcomes.get(messageId);if(outcome)applyInitiativeFollowthroughV1(outcome);
    }
    if (actions.children.length) (element.querySelector('.message-body') || element).appendChild(actions);
  }

  const productLaunchActions = {
    listing_review:['Review product listing','product'],
    listing_unavailable:['Review listing setup','product'],
    photo_needed:['Choose a photo for marketing','product'],
    marketing_review:['Review product marketing','photo'],
    design_review:['Design promotion in Canvas','draft'],
    image_pending:['View image progress','creative'],
    creative_ready:['Review creative in Canvas','creative'],
    creative_failed:['Review image job in Canvas','creative'],
    publication_review:['Review promotion in Canvas','creative'],
    published:['Open published promotion in Canvas','creative'],
    paused:['Open paused promotion in Canvas','creative'],
    promotion_outcomes:['Review this creative in Canvas','creative']
  };

  function bindProductLaunchV1(element, marker) {
    if (!element || !marker || !initiativeIdentityMatchesV1() || marker.owner_user_id !== initiativePage.owner
      || marker.agent_id !== initiativePage.agent || marker.conversation_id !== conversationId
      || !Number.isSafeInteger(marker.product_id) || marker.product_id < 1
      || typeof marker.stage !== 'string' || !Object.hasOwn(productLaunchActions, marker.stage)) return;
    const [label, target] = productLaunchActions[marker.stage];
    if (target !== 'product' && (typeof marker.source_id !== 'string' || !/^[1-9][0-9]*$/.test(marker.source_id)
      || !Number.isSafeInteger(Number(marker.source_id)))) return;
    if (['draft','creative'].includes(target) && (typeof marker.draft_id !== 'string' || !/^[a-f0-9]{64}$/.test(marker.draft_id))) return;
    if (target === 'creative' && (typeof marker.creative_id !== 'string' || !/^[a-f0-9]{32}$/.test(marker.creative_id))) return;
    const actions = document.createElement('div');
    actions.className = 'chat-agent-actions';
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.productLaunch = marker.stage;
    button.textContent = label;
    button.addEventListener('click', () => {
      if (!element.isConnected || !thread.contains(element) || conversationId !== marker.conversation_id
        || !initiativeIdentityMatchesV1()) { button.disabled = true; return; }
      if (initialRestorePending || restoringConversation || !contextReady) return;
      if (window.VP3InitiativeWorkbench?.openProductLaunch?.(marker) !== true) {
        button.textContent = 'Launch review unavailable';
        button.disabled = true;
      }
    });
    actions.appendChild(button);
    (element.querySelector('.message-body') || element).appendChild(actions);
  }

  /** Render the server's frozen action, alongside the Agent's natural reply.
   * This is a review projection, never a client-side executor or approval. */
  function bindInitiativeActionV1(element, action, outcome) {
    if(!element||!initiativeIdentityMatchesV1()||!validInitiativeReplyV1(outcome)
      ||outcome.owner_user_id!==initiativePage.owner||outcome.agent_id!==initiativePage.agent||outcome.conversation_id!==conversationId
      ||!action||!Number.isSafeInteger(action.run_id)||action.run_id<1||typeof action.plan_hash!=='string'||!/^[a-f0-9]{64}$/.test(action.plan_hash)
      ||action.effect_class!=='private_record'||action.review_required!==true||!['approval_pending','completed','cancelled'].includes(action.status)
      ||!Number.isSafeInteger(action.receipt_id)||action.receipt_id<0||action.status==='completed'&&action.receipt_id<1
      ||!action.arguments||typeof action.arguments!=='object'||Array.isArray(action.arguments))return;
    const names={'task.create':'Create task','task.set_status':'Update task status','task_list.create':'Create list','task_list.add':'Add to list',
      'reminder.create':'Create reminder','reminder.update':'Update reminder','reminder.complete':'Complete reminder occurrence',
      'reminder.snooze':'Snooze reminder occurrence','reminder.dismiss':'Dismiss reminder occurrence','reminder.cancel':'Cancel reminder series','reminder.delete':'Delete reminder',
      'proposal.revisit.create':'Schedule one-time revisit','proposal.revisit.update':'Reschedule revisit','proposal.revisit.cancel':'Cancel scheduled revisit',
      'calendar.create':'Create calendar event','calendar.update':'Update calendar event','calendar.cancel':'Cancel calendar event'};
    if(!Object.hasOwn(names,action.adapter))return;
    const targets=action.targets??[];
    if(!Array.isArray(targets)||targets.length>8||targets.some(target=>!target||typeof target.ref!=='string'
      ||!/^(?:task|task-list|reminder|calendar:event):[1-9][0-9]*(?:@[0-9]{8}T[0-9]{6}Z)?$/.test(target.ref)
      ||typeof target.title!=='string'||target.title.length>1000))return;
    const labels={condition:'Condition',milestone:'Milestone',focus:'Revisit focus',followup_id:'Follow-up reminder',title:'Title',name:'List',task_id:'Task',list_id:'List',status:'Status',target_ref:'Record',minutes:'Minutes',
      schedule:'Schedule',event:'Event',description:'Description',location:'Location',timezone:'Time zone',date:'Date',start_local:'Local time',
      start_time:'Start time',end_time:'End time',end_date:'End date',all_day:'All day',recurrence:'Repeat',delivery:'Delivery',agent_notifications:'Agent updates',
      frequency:'Frequency',interval:'Interval',weekdays:'Days',until:'Until',count:'Occurrences',mode:'Mode',text:'Text',voice:'Voice',days:'Days',type:'Type'};
    const entries=[];
    const collect=(object,prefix='',depth=0)=>{
      if(depth>3||!object||typeof object!=='object'||Array.isArray(object))return false;
      for(const [key,value] of Object.entries(object)){
        if(!Object.hasOwn(labels,key)||entries.length>=35)return false;
        const label=prefix?prefix+' · '+labels[key]:labels[key];
        if(value&&typeof value==='object'&&!Array.isArray(value)){if(!collect(value,label,depth+1))return false;}
        else{
          const scalar=item=>item===null||typeof item==='boolean'||typeof item==='string'&&item.length<=10000||typeof item==='number'&&Number.isSafeInteger(item);
          if(Array.isArray(value)?value.length>14||!value.every(scalar):!scalar(value))return false;
          const milestoneNames={mission_completed:'Mission completed'};
          const format=item=>key==='milestone'&&Object.hasOwn(milestoneNames,item)?milestoneNames[item]:item===null?'None':item===true?'Yes':item===false?'No':String(item);
          entries.push([label,Array.isArray(value)?value.map(format).join(', '):format(value)]);
        }
      }
      return true;
    };
    if(!collect(action.arguments)||!entries.length)return;
    // Only one pending review exists for a proposal. A replacement cannot
    // leave an older card offering approval for a different frozen action.
    for(const prior of thread.querySelectorAll('[data-initiative-action-proposal="'+outcome.message_id+'"]')){
      if(prior.dataset.initiativeActionRun===String(action.run_id)||prior.dataset.initiativeActionStatus!=='approval_pending')continue;
      prior.dataset.initiativeActionStatus='cancelled';prior.querySelector('[data-initiative-action-status]').textContent='Replaced by a newer review';prior.querySelector('[data-initiative-action-reply]')?.remove();
    }
    // A later verified completion retires earlier views of this exact review.
    if(action.status!=='approval_pending')for(const prior of thread.querySelectorAll('[data-initiative-action-run="'+action.run_id+'"]')){
      if(prior.dataset.initiativeActionPlan!==action.plan_hash)continue;
      prior.dataset.initiativeActionStatus=action.status;
      prior.querySelector('[data-initiative-action-status]').textContent=action.status==='completed'?'Completed · verified receipt #'+action.receipt_id:'Cancelled';
      prior.querySelector('[data-initiative-action-reply]')?.remove();
    }
    if(element.querySelector('[data-initiative-action-run]'))return;
    const review=document.createElement('section');review.className='chat-initiative-action-review';review.dataset.initiativeActionRun=String(action.run_id);
    review.dataset.initiativeActionProposal=String(outcome.message_id);review.dataset.initiativeActionPlan=action.plan_hash;review.dataset.initiativeActionStatus=action.status;review.setAttribute('aria-label',names[action.adapter]);
    const heading=document.createElement('strong');heading.textContent=names[action.adapter];
    const status=document.createElement('small');status.dataset.initiativeActionStatus='';
    status.textContent=action.status==='approval_pending'?'Review before saving':action.status==='completed'?'Completed · verified receipt #'+action.receipt_id:'Cancelled';
    const details=document.createElement('dl');
    for(const target of targets){const row=document.createElement('div'),term=document.createElement('dt'),description=document.createElement('dd');term.textContent='For';description.textContent=target.title+' · '+target.ref;row.append(term,description);details.append(row);}
    for(const [label,value] of entries){const row=document.createElement('div'),term=document.createElement('dt'),description=document.createElement('dd');term.textContent=label;description.textContent=value;row.append(term,description);details.append(row);}
    review.append(heading,status,details);
    if(action.status==='approval_pending'){
      const hint=document.createElement('p');hint.textContent='Reply to confirm these details, change them, or cancel.';review.append(hint);
      const button=document.createElement('button');button.type='button';button.className='chat-agent-action';button.dataset.initiativeActionReply=String(action.run_id);button.textContent='Reply';
      button.addEventListener('click',()=>{
        const post=initiativePosts.get(outcome.message_id);
        if(!initiativeIdentityMatchesV1()||!review.isConnected||!thread.contains(review)||review.dataset.initiativeActionStatus!=='approval_pending'
          ||!post||post.reply_ref!==outcome.reply_ref||post.revision!==outcome.revision){button.disabled=true;return;}
        chooseInitiativeReplyV1(post);
      });review.append(button);
    }
    (element.querySelector('.message-body')||element).append(review);
  }

  /** The server freezes and authorizes the action; this card only presents it
   * and sends an exact owner reply through the ordinary Chat turn. */
  function bindMeetingActionV1(element, action) {
    if(!element||!initiativeIdentityMatchesV1()||!action||action.version!==1
      ||action.owner_user_id!==initiativePage.owner||action.agent_id!==initiativePage.agent||action.conversation_id!==conversationId
      ||!Number.isSafeInteger(action.plan_id)||action.plan_id<1||typeof action.plan_hash!=='string'||!/^[a-f0-9]{64}$/.test(action.plan_hash)
      ||!['approval_pending','completed','cancelled','stale'].includes(action.status)
      ||typeof action.label!=='string'||!action.label.trim()||action.label.length>180
      ||typeof action.summary!=='string'||action.summary.length>4000||!Array.isArray(action.details)||action.details.length>20
      ||action.details.some(detail=>!detail||typeof detail.label!=='string'||!detail.label.trim()||detail.label.length>120
        ||typeof detail.value!=='string'||detail.value.length>10000)
      ||action.status==='approval_pending'&&action.confirmation_text!=='Confirm meeting action '+action.plan_id)return;
    const messageId=Number(element.dataset.chatMessageId),planId=String(action.plan_id);
    if(!Number.isSafeInteger(messageId)||messageId<1)return;
    const existing=element.querySelector('[data-meeting-action-plan]');
    const states={approval_pending:'Review before confirming',completed:'Completed',cancelled:'Cancelled',stale:'Review changed · ask for current details'};
    const retire=(card,status,label=states[status])=>{
      card.dataset.meetingActionStatus=status;
      card.querySelector('[data-meeting-action-status]').textContent=label;
      card.querySelector('[data-meeting-action-controls]')?.remove();
    };
    if(existing&&(existing.dataset.meetingActionPlan!==planId||existing.dataset.meetingActionHash!==action.plan_hash)){
      retire(existing,'stale');return;
    }
    let status=action.status,replaced=false;
    for(const prior of thread.querySelectorAll('[data-meeting-action-plan]')){
      const priorId=Number(prior.closest('[data-chat-message-id]')?.dataset.chatMessageId);
      if(prior.dataset.meetingActionPlan===planId){
        if(prior.dataset.meetingActionHash!==action.plan_hash){retire(prior,'stale');return;}
        if(status==='approval_pending'&&prior.dataset.meetingActionCanonicalStatus!=='approval_pending'){
          status=prior.dataset.meetingActionCanonicalStatus;
        }
        if(action.status!=='approval_pending'){
          prior.dataset.meetingActionCanonicalStatus=action.status;retire(prior,action.status);
          prior.querySelector('p').textContent=action.summary;prior.querySelector('dl')?.remove();
        }
      }
      if(priorId<messageId&&prior.dataset.meetingActionStatus==='approval_pending')retire(prior,'cancelled','Replaced by a newer review');
      if(status==='approval_pending'&&priorId>messageId)replaced=true;
    }
    if(existing)return true;
    const review=document.createElement('section');review.className='chat-initiative-action-review chat-meeting-action-review';
    review.dataset.meetingActionPlan=planId;review.dataset.meetingActionHash=action.plan_hash;
    review.dataset.meetingActionCanonicalStatus=status;
    review.dataset.meetingActionStatus=replaced?'cancelled':status;review.setAttribute('aria-label',action.label);
    const heading=document.createElement('strong');heading.textContent=action.label;
    const state=document.createElement('small');state.dataset.meetingActionStatus='';state.textContent=replaced?'Replaced by a newer review':states[status];
    const summary=document.createElement('p');summary.textContent=action.summary;
    review.append(heading,state,summary);
    if(action.details.length&&status==='approval_pending'&&!replaced){
      const details=document.createElement('dl');
      for(const detail of action.details){
        const row=document.createElement('div'),label=document.createElement('dt'),value=document.createElement('dd');
        label.textContent=detail.label;value.textContent=detail.value;row.append(label,value);details.append(row);
      }
      review.append(details);
    }
    if(status==='approval_pending'&&!replaced){
      const controls=document.createElement('div');controls.className='chat-agent-actions';controls.dataset.meetingActionControls='';
      for(const [label,command] of [['Confirm',action.confirmation_text],['Cancel','Cancel meeting action '+planId]]){
        const button=document.createElement('button');button.type='button';button.className='chat-agent-action';button.textContent=label;
        button.addEventListener('click',async()=>{
          if(!initiativeIdentityMatchesV1()||!review.isConnected||!thread.contains(review)||action.conversation_id!==conversationId
            ||review.dataset.meetingActionStatus!=='approval_pending'){button.disabled=true;return;}
          if(busy||!contextReady||initialRestorePending||restoringConversation)return;
          if(input.value.trim()||initiativeReplyTarget){input.focus();return;}
          const buttons=controls.querySelectorAll('button');buttons.forEach(control=>{control.disabled=true;});
          try{await sendMessage(command,'text');}
          finally{if(review.dataset.meetingActionStatus==='approval_pending')buttons.forEach(control=>{control.disabled=false;});}
        });
        controls.append(button);
      }
      review.append(controls);
    }
    (element.querySelector('.message-body')||element).append(review);
    return true;
  }

  function pendingAssistantMessageIds() {
    const cutoff=Date.now()-600000;
    for(const [id,seenAt] of pendingAssistantMessages){
      if(seenAt<cutoff)pendingAssistantMessages.delete(id);
    }
    const reviews=Array.from(thread.querySelectorAll('[data-meeting-action-status="approval_pending"][data-meeting-action-plan]'))
      .map(card=>Number(card.closest('[data-chat-message-id]')?.dataset.chatMessageId))
      .filter(id=>Number.isSafeInteger(id)&&id>0);
    return [...new Set([...reviews,...pendingAssistantMessages.keys()])].slice(0,40);
  }

  function unconfirmedAssistantMessage(message,context) {
    const id=Number(message.id);
    const content=['media','stem_media','actions','cards','system_app_action_card','canvas_media']
      .some(key=>context[key]&&Object.keys(context[key]).length>0);
    if(message.role==='assistant'&&!String(message.message||'').trim()&&!content){
      if(Number.isSafeInteger(id)&&id>0&&!pendingAssistantMessages.has(id)&&pendingAssistantMessages.size<40){
        pendingAssistantMessages.set(id,Date.now());
      }
      return true;
    }
    pendingAssistantMessages.delete(id);
    return false;
  }

  function bindProfileCallPa3c(element, marker) {
    if(!element || marker?.contract!=='vp3.profile-call.v1' ||
      !['call_id','revision','conversation_id'].every(key=>Number.isSafeInteger(marker[key])&&marker[key]>0))return;
    element.dataset.profileCallId=String(marker.call_id);
    element.dataset.profileCallRevision=String(marker.revision);
    element.dataset.profileCallConversation=String(marker.conversation_id);
    window.dispatchEvent(new CustomEvent('vp3:profile-call-message',{detail:{element}}));
  }
  function bindProfileSpeechPa4a(element,marker){
    if(!element||marker?.contract!=='vp3.profile-speech.v1'||!['item_id','revision'].every(key=>Number.isSafeInteger(marker[key])&&marker[key]>0)||
      !['arrival','visitor_message'].includes(marker.kind)||!Number.isSafeInteger(marker.public_conversation_id)||marker.public_conversation_id<0)return;
    element.dataset.profileSpeechItem=String(marker.item_id);element.dataset.profileSpeechRevision=String(marker.revision);
    element.dataset.profileSpeechKind=marker.kind;element.dataset.profileSpeechConversation=String(marker.public_conversation_id);
    window.dispatchEvent(new CustomEvent('vp3:profile-speech-message',{detail:{element}}));
  }
  function bindProfileAttentionPa4b(element,marker){
    if(!element||marker?.contract!=='vp3.profile-attention.v1'||!['delivery_id','attention_id','notification_id','wake_generation'].every(key=>Number.isSafeInteger(marker[key])&&marker[key]>0))return;
    element.dataset.profileAttentionDelivery=String(marker.delivery_id);element.dataset.profileAttentionId=String(marker.attention_id);
    element.dataset.profileAttentionNotification=String(marker.notification_id);element.dataset.profileAttentionWake=String(marker.wake_generation);
    window.dispatchEvent(new CustomEvent('vp3:profile-attention-message',{detail:{element}}));
  }

  let initializingConversationPa3=null;
  async function ensureConversationPa3() {
    if(conversationId>0)return conversationId;
    if(initializingConversationPa3)return initializingConversationPa3;
    if(!contextReady || initialRestorePending || restoringConversation || busy || thread.dataset.view!=='chat')return 0;
    const revision=chatRevision;
    const controller=new AbortController();let timer=0;
    initializingConversationPa3=(async()=>{
      const deadline=new Promise((_,reject)=>{timer=window.setTimeout(()=>{controller.abort();reject(new Error('Chat initialization timed out. Your draft is kept.'));},10000);});
      const data=await Promise.race([api({action:'new'},controller.signal),deadline]);
      const id=Number(data.conversation_id);
      if(revision!==chatRevision || conversationId!==0 || busy || thread.dataset.view!=='chat')return 0;
      if(!Number.isSafeInteger(id)||id<1)throw new Error('Chat could not be initialized.');
      const draft=input.value;
      conversationId=id;keepDraft(draft,id);keepDraft('',0);rememberConversation();
      window.dispatchEvent(new Event('vp3:chat-context-ready'));
      void refreshHistory().catch(()=>{});
      return id;
    })();
    try{return await initializingConversationPa3;}finally{window.clearTimeout(timer);initializingConversationPa3=null;}
  }

  function mountProfileHandoffPa3(delivery) {
    const marker=delivery?.turn;
    const id=Number(delivery?.chat_message_id || delivery?.message_id);
    if(!contextReady || restoringConversation || Number(delivery?.chat_conversation_id)!==conversationId ||
      Number(marker?.chat_agent_id)!==Number(cfg.agentId||0) || marker?.contract!=='vp3.profile-handoff.v1' ||
      !Number.isSafeInteger(id)||id<1)return null;
    const existing=thread.querySelector('[data-chat-message-id="'+id+'"]');
    if(existing)return existing;
    const previousScroll=thread.scrollTop, nearBottom=thread.scrollHeight-thread.scrollTop-thread.clientHeight<100;
    const element=addMessage('assistant','A Profile visitor sent you a message.');
    bindStoredMessagePa3(element,id,marker);
    if(!nearBottom)thread.scrollTop=previousScroll;
    return element;
  }

  function mountProfileCallPa3c(delivery) {
    const call=delivery?.call,id=Number(delivery?.chat_message_id||delivery?.message_id);
    if(!contextReady||restoringConversation||thread.dataset.view!=='chat'||
      Number(delivery?.chat_conversation_id)!==conversationId||Number(delivery?.chat_agent_id)!==Number(cfg.agentId||0)||
      call?.contract!=='vp3.profile-call.v1'||call.role!=='owner'||!Number.isSafeInteger(id)||id<1||
      !['call_id','revision','conversation_id'].every(key=>Number.isSafeInteger(call[key])&&call[key]>0))return null;
    const existing=thread.querySelector('[data-chat-message-id="'+id+'"]');
    if(existing){
      if(Number(existing.dataset.chatConversationId)!==conversationId||Number(existing.dataset.profileCallId)!==call.call_id||
        Number(existing.dataset.profileCallConversation)!==call.conversation_id)return null;
      return existing;
    }
    const previousScroll=thread.scrollTop,nearBottom=thread.scrollHeight-thread.scrollTop-thread.clientHeight<100;
    const element=addMessage('assistant','Profile visitor call.');
    bindStoredMessagePa3(element,id);bindProfileCallPa3c(element,call);
    if(!nearBottom)thread.scrollTop=previousScroll;
    return element;
  }
  function mountProfileSpeechPa4a(delivery){
    const item=delivery?.item,id=delivery?.chat_message_id;
    if(!contextReady||restoringConversation||thread.dataset.view!=='chat'||delivery?.contract!=='vp3.profile-speech.v1'||
      delivery.chat_conversation_id!==conversationId||delivery.chat_agent_id!==Number(cfg.agentId||0)||
      !Number.isSafeInteger(id)||id<1||!item||!['id','revision'].every(key=>Number.isSafeInteger(item[key])&&item[key]>0)||
      !['arrival','visitor_message'].includes(item.kind)||!Number.isSafeInteger(item.public_conversation_id)||item.public_conversation_id<0)return null;
    const existing=thread.querySelector('[data-chat-message-id="'+id+'"]');
    if(existing)return Number(existing.dataset.chatConversationId)===conversationId&&Number(existing.dataset.profileSpeechItem)===item.id?existing:null;
    const previousScroll=thread.scrollTop,nearBottom=thread.scrollHeight-thread.scrollTop-thread.clientHeight<100;
    const element=addMessage('assistant','Profile visitor announcement.');
    bindStoredMessagePa3(element,id);bindProfileSpeechPa4a(element,{...item,item_id:item.id,contract:'vp3.profile-speech.v1'});
    if(!nearBottom)thread.scrollTop=previousScroll;return element;
  }
  function mountProfileAttentionPa4b(delivery){
    const item=delivery?.item,id=item?.chat_message_id;
    if(!contextReady||restoringConversation||thread.dataset.view!=='chat'||delivery?.contract!=='vp3.profile-attention.v1'||
      !item||item.delivery_location!=='current'||item.chat_conversation_id!==conversationId||item.chat_agent_id!==Number(cfg.agentId||0)||
      !['delivery_id','attention_id','notification_id','wake_generation','chat_message_id'].every(key=>Number.isSafeInteger(item[key])&&item[key]>0))return null;
    const existing=thread.querySelector('[data-chat-message-id="'+id+'"]');
    if(existing)return Number(existing.dataset.chatConversationId)===conversationId&&Number(existing.dataset.profileAttentionDelivery)===item.delivery_id&&Number(existing.dataset.profileAttentionId)===item.attention_id&&Number(existing.dataset.profileAttentionNotification)===item.notification_id&&Number(existing.dataset.profileAttentionWake)===item.wake_generation?existing:null;
    const previousScroll=thread.scrollTop,nearBottom=thread.scrollHeight-thread.scrollTop-thread.clientHeight<100;
    const element=addMessage('assistant','Profile attention update.');bindStoredMessagePa3(element,id);bindProfileAttentionPa4b(element,{contract:'vp3.profile-attention.v1',...item});
    if(!nearBottom)thread.scrollTop=previousScroll;return element;
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
        !candidate.closest('[hidden]') &&
        (candidate === audio || !candidate.hasAttribute('data-listening-resume-audio'))
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
      !audio.isConnected ||
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
      if (audio === activeAudio) updateNowPlaying(audio);
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
    const delta = !state.wasPlaying
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
    const state=audioState.get(audio);
    if(!state||state.sessionToken)return;
    if(state.startPromise){await state.startPromise;if(state.sessionToken||audio.paused)return;return audioStartSession(audio);}
    const generation=state.sessionGeneration;
    const request=(async()=>{
      const data=await playbackApi({action:'start',track_id:state.trackId,position:Number(audio.currentTime||0),duration:Number(audio.duration||0),source:audio.closest('[data-chat-view="player"]')?'agent_player':'agent_chat'});
      if(!data?.ok||!data.session_token)return;
      if(generation!==state.sessionGeneration){
        await playbackApi({action:'stop',track_id:state.trackId,session_token:data.session_token,position:Number(audio.currentTime||0),duration:Number(audio.duration||0),delta:0},true);
        return;
      }
      state.sessionToken=data.session_token;
      window.dispatchEvent(new Event('vp3:playback-history-changed'));
      if(audio.paused)await audioTrackEvent(audio,'pause',0);
    })();
    state.startPromise=request;
    try{await request;}finally{if(state.startPromise===request)state.startPromise=null;}
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
    const eventGeneration=state.sessionGeneration;
    if(!state.sessionToken&&action==='heartbeat'&&!state.wasPlaying&&delta<=0)return;

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

    if (!state.sessionToken||eventGeneration!==state.sessionGeneration) {
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
        startPromise:null,
        sessionGeneration:0,
        wasPlaying:false,
        heartbeatBusy:false,
        pendingEndDelta:0,
        lastTick:
          performance.now(),
        heartbeat:null,
        controls:null
      };

      audioState.set(
        audio,
        state
      );

      audio.volume = playerVolume;
      audio.muted = playerVolume <= .001;
      if (!audio.hasAttribute('data-listening-resume-audio')) decorateAudioPlayer(audio);

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

      audio.addEventListener('playing',()=>{
        if (!audio.isConnected || audio.paused) return;
        state.lastTick=performance.now();state.wasPlaying=true;void audioStartSession(audio);
      });
      ['waiting','stalled'].forEach(event=>audio.addEventListener(event,()=>{
        const delta=audioListenedDelta(audio);state.wasPlaying=false;
        if(state.sessionToken&&delta>0)audioTrackEvent(audio,'heartbeat',delta);
      }));

      audio.addEventListener(
        'play',
        async () => {
          if (!audio.isConnected || audio.paused) return;
          const playGeneration=state.sessionGeneration;
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

          if (state.sessionToken) {
            await audioTrackEvent(
              audio,
              'resume',
              0
            );
          }

          if(playGeneration!==state.sessionGeneration)return;
          if (!state.heartbeat) {
            state.heartbeat =
              setInterval(
                () => {
                  if (
                    !audio.paused &&
                    !audio.ended &&
                    !state.heartbeatBusy
                  ) {
                    const delta =
                      audioListenedDelta(
                        audio
                      );

                    state.heartbeatBusy=true;
                    audioTrackEvent(audio,'heartbeat',delta).finally(()=>{state.heartbeatBusy=false;});
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

          const finalDelta=audioListenedDelta(audio);state.wasPlaying=false;if(audio.ended)state.pendingEndDelta+=finalDelta;
          if (
            !audio.ended &&
            state.sessionToken
          ) {
            const delta=finalDelta;

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
            audioListenedDelta(audio)+state.pendingEndDelta;
          state.pendingEndDelta=0;

          audioTrackEvent(
            audio,
            'ended',
            delta
          );

          state.wasPlaying=false;state.sessionGeneration++;
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
      if(!state)return;
      state.sessionGeneration++;
      if(!state.sessionToken){state.wasPlaying=false;return;}

      const delta = audioListenedDelta(audio);
      state.wasPlaying=false;
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

  async function refreshHistory(signal) {
    try {
      const data = await api({action:'list'}, signal);
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

  async function loadConversation(id, sourceMessageId = 0) {
    const historyAtRequest = historyAuthority, ownerAtRequest = Number(cfg.userId), agentAtRequest = Number(cfg.agentId || 0);
    const historyIsCurrent = () => historyAtRequest === historyAuthority && historyParticipant <= 0
      && Number(window.STONEFELLOW_CHAT?.userId) === ownerAtRequest
      && Number(window.STONEFELLOW_CHAT?.agentId || 0) === agentAtRequest
      && !window.VP3ParticipantContinuity?.selectedContext?.();

    if (!sourceMessageId) {
      const sourceParams = new URLSearchParams(location.search);
      const sourceQuery = sourceParams.get('message_id');
      if (Number(sourceParams.get('conversation_id')) === Number(id) && /^[1-9][0-9]{0,15}$/.test(sourceQuery || '')) {
        const sourceCandidate = Number(sourceQuery);
        if (Number.isSafeInteger(sourceCandidate)) sourceMessageId = sourceCandidate;
      }
    }
    leaveChat(); const revision = chatRevision;
    restoringConversation++;
    const controller = new AbortController();
    let restoreTimer = 0;
    try {
    const deadline = new Promise((_, reject) => {
      restoreTimer = window.setTimeout(() => {
        reject(new Error('Conversation loading timed out. Your draft is kept.'));
        controller.abort();
      }, 10000);
    });
    const data = await Promise.race([api({
      action:'load',
      conversation_id:Number(id),
      ...(sourceMessageId ? {source_message_id:sourceMessageId} : {})
    }, controller.signal), deadline]);

    if(revision!==chatRevision || !historyIsCurrent())return false;
    conversationId = Number(id); contextReady=true; rememberConversation(); restoreDraft();
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
      let context = {sources:[],media:[],stem_media:[],actions:[],cards:[],playlist_title:''};

      if (message.context_json) {
        try {
          context = normalizeContext(JSON.parse(message.context_json));
        } catch (error) {}
      }

      if(unconfirmedAssistantMessage(message,context)){
        lastLoadedMessageId=Math.max(lastLoadedMessageId,Number(message.id||0));
        return;
      }

      const renderedMessage=addMessage(
        message.role === 'user' ? 'user' : 'assistant',
        message.message,
        context.sources,
        context.media,
        context.playlist_title,
        context.stem_media || [],
        context.actions || [],
        context.cards || [],
        context.system_app_action_card || null,
        context.knowledge_actions_r9 || null,
        context.canvas_media || null,
        context.youtube_results || null
      );
      if(message.role==='assistant'){
        bindResearchCanvasV1(renderedMessage,context.research_canvas_v1);
        if(context.gmail_inbox_review_v1)renderedMessage._vp3GmailInboxReviewV1=context.gmail_inbox_review_v1;
      }
      bindStoredMessagePa3(renderedMessage,message.id,message.role==='assistant'?context.profile_handoff:null,String(message.role||'unknown'),message.created_at);
      if(message.role==='assistant')bindGmailResultTime(renderedMessage,context,message.created_at);
      if(message.role==='assistant')bindProfileCallPa3c(renderedMessage,context.profile_call);
      if(message.role==='assistant')bindProfileSpeechPa4a(renderedMessage,context.profile_speech);
      if(message.role==='assistant')bindProfileAttentionPa4b(renderedMessage,context.profile_attention);
      if(message.role==='assistant')bindInitiativeWorkV1(renderedMessage,context.initiative_work,context.source);
      if(message.role==='assistant')applyInitiativeFollowthroughV1(context.initiative_followthrough);
      if(message.role==='assistant')bindInitiativeActionV1(renderedMessage,context.initiative_action,context.initiative_followthrough);
      if(message.role==='assistant')bindMeetingActionV1(renderedMessage,context.meeting_action);
      if(message.role==='assistant')bindProductLaunchV1(renderedMessage,context.product_launch);
      lastLoadedMessageId=Math.max(lastLoadedMessageId,Number(message.id||0));
    });

    document.querySelectorAll('.chat-history-item').forEach(btn => {
      btn.classList.toggle(
        'active',
        Number(btn.dataset.conversationId) === conversationId
      );
    });

    closeNav();
    if (sourceMessageId) thread.querySelector('[data-chat-message-id="'+sourceMessageId+'"]')?.scrollIntoView({block:'center'});
    return true;
    } finally { window.clearTimeout(restoreTimer); restoringConversation--; if(revision===chatRevision&&!restoringConversation)restoreInitiativeReplyV1(); window.dispatchEvent(new Event('vp3:chat-context-ready')); }
  }

  async function syncConversationMessagesV101(id, signal) {
    const revision=chatRevision;
    const targetId=Number(id||0);if(targetId<1||targetId!==conversationId||busy)return;
    const data=await api({action:'messages_after',conversation_id:targetId,after_id:lastLoadedMessageId,
      pending_message_ids:pendingAssistantMessageIds(),
      agent_context:{user_agent_id:Number(cfg.agentId || 0)}}, signal);
    if(revision!==chatRevision||targetId!==conversationId||busy||!Array.isArray(data.messages))return;
    data.messages.forEach(message=>{
      let context={sources:[],media:[],stem_media:[],actions:[],cards:[],playlist_title:''};
      if(message.context_json){try{context=normalizeContext(JSON.parse(message.context_json));}catch(error){}}
      const stored=thread.querySelector('[data-chat-message-id="'+Number(message.id||0)+'"]');
      if(stored){
        if(typeof message.created_at==='string'&&message.created_at)stored.dataset.chatCreatedAt=message.created_at;
        if(message.role==='assistant')bindGmailResultTime(stored,context,message.created_at);
        if(message.role==='assistant'&&stored.querySelector('[data-meeting-action-plan]')
          &&bindMeetingActionV1(stored,context.meeting_action)&&context.meeting_action.status!=='approval_pending'
          &&typeof message.message==='string')stored.querySelector('.message-text').textContent=message.message;
        lastLoadedMessageId=Math.max(lastLoadedMessageId,Number(message.id||0));return;
      }
      if(unconfirmedAssistantMessage(message,context)){
        lastLoadedMessageId=Math.max(lastLoadedMessageId,Number(message.id||0));
        return;
      }
      const renderedMessage=addMessage(message.role==='user'?'user':'assistant',message.message,context.sources,context.media,context.playlist_title,context.stem_media||[],context.actions||[],context.cards||[],context.system_app_action_card||null,context.knowledge_actions_r9||null,context.canvas_media||null,context.youtube_results||null);
      if(message.role==='assistant'){
        bindResearchCanvasV1(renderedMessage,context.research_canvas_v1);
        if(context.gmail_inbox_review_v1)renderedMessage._vp3GmailInboxReviewV1=context.gmail_inbox_review_v1;
      }
      bindStoredMessagePa3(renderedMessage,message.id,message.role==='assistant'?context.profile_handoff:null,String(message.role||'unknown'),message.created_at);
      if(message.role==='assistant')bindGmailResultTime(renderedMessage,context,message.created_at);
      const next=Array.from(thread.querySelectorAll('[data-chat-message-id]')).find(element=>element!==renderedMessage
        &&Number(element.dataset.chatConversationId)===targetId&&Number(element.dataset.chatMessageId)>Number(message.id));
      if(next)thread.insertBefore(renderedMessage,next);
      if(message.role==='assistant')bindProfileCallPa3c(renderedMessage,context.profile_call);
      if(message.role==='assistant')bindProfileSpeechPa4a(renderedMessage,context.profile_speech);
      if(message.role==='assistant')bindProfileAttentionPa4b(renderedMessage,context.profile_attention);
      if(message.role==='assistant')bindInitiativeWorkV1(renderedMessage,context.initiative_work,context.source);
      if(message.role==='assistant')applyInitiativeFollowthroughV1(context.initiative_followthrough);
      if(message.role==='assistant')bindInitiativeActionV1(renderedMessage,context.initiative_action,context.initiative_followthrough);
      if(message.role==='assistant')bindMeetingActionV1(renderedMessage,context.meeting_action);
      if(message.role==='assistant')bindProductLaunchV1(renderedMessage,context.product_launch);
      lastLoadedMessageId=Math.max(lastLoadedMessageId,Number(message.id||0));
    });
    if(data.messages.length){
      window.dispatchEvent(new Event('vp3:chat-history-updated'));
      await refreshHistory(signal);
    }
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

          keepDraft('',id);
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

  function cancelYoutubeLocalTurn() {
    if (!youtubeLocalTurn) return;
    youtubeLocalTurn = null;
    busy = false;
    send.disabled = false;
    input.disabled = false;
  }

  async function runYoutubeLocalTurn(message, action) {
    const youtube = window.VP3_CHAT_YOUTUBE_V1;
    if (!youtube || !action || busy || !contextReady || initialRestorePending || restoringConversation
        || initiativeReplyTarget || window.VP3ParticipantContinuity?.selectedContext()) return null;
    const turn = {};
    youtubeLocalTurn = turn;
    busy = true;
    send.disabled = true;
    input.disabled = true;
    input.value = '';
    autoSize();
    keepDraft('');
    setChatCanvasView('chat', {newChat:conversationId < 1});
    addMessage('user', message.trim());
    try {
      const response = await youtube.runCommand(action);
      if (youtubeLocalTurn !== turn) return null;
      const actions = response.settings && youtube.settingsUrl
        ? [{type:'open_url', url:youtube.settingsUrl, label:'YouTube settings'}] : [];
      const reply = addMessage('assistant', response.answer, [], [], '', [], actions, [], null, null, null, response.results);
      if (action === 'recent') reply.dataset.vp3YoutubePrivate = '1';
      return response.answer;
    } finally {
      if (youtubeLocalTurn === turn) {
        cancelYoutubeLocalTurn();
        input.focus();
      }
    }
  }

  window.addEventListener('vp3:youtube-recent', () => {
    if (!input.value.trim()) void runYoutubeLocalTurn('/youtube history', 'recent');
  });

  function gmailReviewActionLabel(message) {
    const match = /^(?:(Reply|Draft reply|Auto reply) to Gmail review item [a-f0-9]{64}|(Reply|Draft reply) to Gmail (?:matched item [a-f0-9]{64} [a-zA-Z0-9_-]{1,190} [a-f0-9]{64}|follow-up [1-9][0-9]{0,15}|search item [a-f0-9]{2,1500}))$/.exec(message);
    return match ? (match[1] || match[2]) + ' to selected Gmail message' : '';
  }

  async function sendMessage(message, inputMode = 'text', openGmailDraft = false) {
    if (busy || !contextReady || initialRestorePending || restoringConversation || !message.trim()) return;
    if(initiativePosts.size&&!initiativeIdentityMatchesV1()){
      clearInitiativeReplyV1(true);recoveryStatus('The account or session changed. Refresh Chat before replying to an Agent update.');return;
    }
    const youtubeAction = window.VP3_CHAT_YOUTUBE_V1?.command(message);
    if (youtubeAction && !initiativeReplyTarget && !window.VP3ParticipantContinuity?.selectedContext()) {
      if (inputMode === 'voice') stopVoiceRecognition();
      const answer = await runYoutubeLocalTurn(message, youtubeAction);
      if (answer && inputMode === 'voice' && voiceMode) {
        await speakAgentResponse(answer);
        if (!voiceSpeaking) window.setTimeout(startVoiceRecognition, 220);
      }
      return;
    }
    const initiativeReply=initiativeReplyForSendV1(message);
    if(initiativeReply===false)return;
    const gmailAction = gmailReviewActionLabel(message);
    if (navigator.onLine === false) {
      keepDraft(message);
      recoveryStatus(gmailAction ? 'You are offline. Reopen the Gmail message to try again.' : 'You are offline. Your message is kept.');
      return;
    }
    recoveryStatus();

    const workingContextRef=window.VP3_COGNITIVE_PRESENTATION?.followUpReference?.(conversationId);
    document.dispatchEvent(new CustomEvent('stonefellow:chat-turn',{detail:{state:'pending',conversationId:Number(conversationId||0)}}));

    setChatCanvasView(
      'chat',
      {
        newChat:
          conversationId < 1
      }
    );

    busy = true;
    const visualAssociation=initiativeReply?JSON.stringify(initiativeReply):'';
    const visualTurn=initiativeVisualRetry&&initiativeVisualRetry.conversationId===conversationId
      &&initiativeVisualRetry.message===message.trim()&&initiativeVisualRetry.association===visualAssociation?initiativeVisualRetry:
      {conversationId,message:message.trim(),association:visualAssociation,elements:[]};
    initiativeVisualRetry=null;
    const request={controller:new AbortController(),revision:++chatRevision,conversationId,message:message.trim(),initiativeReply,visualTurn};pendingSend=request;lastParticipantQuestion={message:request.message,conversation_id:request.conversationId,at:Date.now(),submitted:true};
    request.timer=setTimeout(() => request.controller.abort(),120000);showStop();
    keepDraft(request.message,request.conversationId);
    send.disabled = true; input.disabled=true;
    if (inputMode === 'voice') {
      stopVoiceRecognition();
      setVoiceStatus('Thinking…', 'thinking');
    }

    const userMessage=addMessage('user', gmailAction || message.trim());
    visualTurn.elements.push(userMessage);
    input.value = '';
    autoSize();

    const typing = addTyping(); request.typing=typing;
    let gmailDraft = null;

    try {
      const browserContextRuntime=window.VP3_BROWSER_CONTEXT_V2130_RUNTIME;
      const browserAgentContext=browserContextRuntime&&typeof browserContextRuntime.agentContext==='function'
        ? browserContextRuntime.agentContext()
        : null;
      const payload={
        action:'send',
        reminder_request_id:globalThis.crypto?.randomUUID?.()||('reminder:'+Date.now().toString(36)+Math.random().toString(36).slice(2)),
        reminder_timezone:Intl.DateTimeFormat().resolvedOptions().timeZone,
        conversation_id:conversationId,
        message:message.trim(),
        input_mode:inputMode === 'voice' ? 'voice' : 'text'
      };
      if(workingContextRef)payload.working_context_ref=workingContextRef;
      if(initiativeReply)payload.initiative_reply=initiativeReply;
      const knowledgeScopeRuntime=window.StonefellowKnowledgeScopeV162;
      if(knowledgeScopeRuntime&&typeof knowledgeScopeRuntime.value==='function'){
        payload.knowledge_scope=knowledgeScopeRuntime.value();
      }
      if(window.VP3ProjectKnowledgeR6)payload.project_knowledge_r6=window.VP3ProjectKnowledgeR6.value();
      const revision=window.VP3KnowledgeRevisionsR11?.value(message);if(revision)payload.knowledge_revision=revision;
      if(browserAgentContext)payload.agent_context=browserAgentContext;
      const transcriptCanvas=window.STONEFELLOW_TRANSCRIPTION_CANVAS;
      const transcriptSource=transcriptCanvas?.sourceContext?.();
      const transcriptSelection=transcriptCanvas?.selection?.();
      const transcriptSessionId=Number(transcriptSource?.session_id??transcriptSelection?.session_id??0);
      if(transcriptSource?.cleared||transcriptSessionId>0){
        payload.agent_context={...(payload.agent_context||{}),transcript_session_id:transcriptSessionId,
          transcript_selected_text:transcriptSelection?.session_id===transcriptSessionId?String(transcriptSelection.selected_text||'').slice(0,6000):'',
          ...(transcriptSource?.cleared?{transcript_source_cleared:true}:transcriptSource?.session_id?{transcript_source_selected:true}:{})};
      }
      const screenShare=window.VP3AgentScreenShare;
      const screenContext=screenShare?.contextForPrompt?.(payload.message);
      if(screenContext){
        const context={...payload.agent_context};
        delete context.screen_observation;
        delete context.screen_observations;
        payload.agent_context={...context,...screenContext};
      }else{
        const screenObservation=screenShare?.context();
        if(screenObservation)payload.agent_context={...(payload.agent_context||{}),screen_observation:screenObservation};
      }
      const data = await new Promise((resolve, reject) => {
        const abort = () => reject(new DOMException('Chat request stopped or timed out.', 'AbortError'));
        request.controller.signal.addEventListener('abort', abort, {once:true});
        api(payload, request.controller.signal).then(resolve, reject)
          .finally(() => request.controller.signal.removeEventListener('abort', abort));
      });
      if(pendingSend!==request||request.revision!==chatRevision||request.controller.signal.aborted)return;
      if((initiativeReply||data.initiative_followthrough)&&!initiativeIdentityMatchesV1()){
        clearInitiativeReplyV1();recoveryStatus('The account or session changed. Refresh Chat before continuing.');return;
      }
      const returnedFollowthrough=data.initiative_followthrough;
      if(returnedFollowthrough&&(!validInitiativeReplyV1(returnedFollowthrough)
        ||returnedFollowthrough.owner_user_id!==initiativePage.owner||returnedFollowthrough.agent_id!==initiativePage.agent
        ||returnedFollowthrough.conversation_id!==request.conversationId||Number(data.conversation_id)!==request.conversationId
        ||!Number.isSafeInteger(returnedFollowthrough.run_id)||returnedFollowthrough.run_id<1
        ||!initiativeStates.has(returnedFollowthrough.state)||!Number.isSafeInteger(returnedFollowthrough.reply_message_id)||returnedFollowthrough.reply_message_id<1
        ||returnedFollowthrough.reply_message_id!==Number(data.user_message_id)
        ||!Number.isSafeInteger(Number(data.assistant_message_id))||Number(data.assistant_message_id)<1
        ||!Number.isSafeInteger(returnedFollowthrough.continuation_run_id)||returnedFollowthrough.continuation_run_id<0
        ||initiativeReply&&(returnedFollowthrough.message_id!==initiativeReply.message_id||returnedFollowthrough.reply_ref!==initiativeReply.reply_ref))){
        const invalidReply=new Error('The Agent reply could not be matched to this Chat. Your message is kept.');invalidReply.status=422;throw invalidReply;
      }
      if (!Number(data.conversation_id) || typeof data.answer !== 'string') throw new Error('Invalid chat response.');
      if(initiativeReply)clearInitiativeReplyV1();
      keepDraft('',request.conversationId);
      window.VP3ProjectKnowledgeR6?.consume(data.project_knowledge_r6);window.VP3KnowledgeRevisionsR11?.consume();

      if(browserAgentContext&&browserContextRuntime&&typeof browserContextRuntime.consume==='function'){
        browserContextRuntime.consume();
      }

      window.VP3TaskNotes?.consume(data.sticky_notes || []);
      if(lastParticipantQuestion?.message===request.message){lastParticipantQuestion.conversation_id=Number(data.conversation_id);lastParticipantQuestion.at=Date.now();}
      conversationId = Number(data.conversation_id); rememberConversation();
      if(request.conversationId===0)window.dispatchEvent(new CustomEvent('vp3:chat-conversation-bound',{detail:{previous_conversation_id:0,conversation_id:conversationId}}));
      const storedUserId=Number(data.user_message_id),storedAssistantId=Number(data.assistant_message_id);
      const receipt=data.tool_receipt;
      if(transcriptSource?.session_id>0&&Number.isSafeInteger(storedAssistantId)&&storedAssistantId>0
        &&(request.conversationId===0||request.conversationId===conversationId)
        &&receipt?.contract==='vp3.tool-receipt.v1'&&receipt.capability==='transcripts.read'
        &&receipt.operation==='detach'&&receipt.status==='read_complete')transcriptCanvas?.clearSourceForTurn?.(transcriptSource);
      const storedRow=(id,role)=>Number.isSafeInteger(id)&&id>0?thread.querySelector('[data-chat-message-id="'+id+'"][data-chat-conversation-id="'+conversationId+'"][data-chat-message-role="'+role+'"]'):null;
      const existingUser=storedRow(storedUserId,'user');
      if(returnedFollowthrough){
        // Retire only optimistic rows belonging to this local failed/retry
        // chain. Identical text in unrelated turns is never a dedupe key.
        for(const element of visualTurn.elements){
          if(!element.dataset.chatMessageId&&(element!==userMessage||existingUser))element.remove();
        }
      }
      if(existingUser&&existingUser!==userMessage)userMessage.remove();
      else bindStoredMessagePa3(userMessage,data.user_message_id,null,'user');
      typing.remove();

      const existingReply=storedRow(storedAssistantId,'assistant');
      const reply = existingReply || addMessage(
        'assistant',
        data.answer,
        data.sources || [],
        data.media || [],
        data.playlist_title || '',
        data.stem_media || [],
        data.actions || [],
        data.cards || data.cognitive_cards || [],
        data.system_app_action_card || null,
        data.knowledge_actions_r9 || null,
        data.canvas_media || null,
        data.youtube_results || null
      );
      if(!existingReply)bindStoredMessagePa3(reply,data.assistant_message_id);
      if(!existingReply)bindGmailResultTime(reply,data);
      if (openGmailDraft && gmailAction && !gmailAction.startsWith('Auto reply ')) {
        gmailDraft = reply.querySelector('[data-chat-email-review]');
      }
      if (data.tool_receipt?.capability === 'email.followups' && data.tool_receipt.status === 'completed'
        && ['watch_sender','stop_sender_watch'].includes(data.tool_receipt.operation)) {
        window.dispatchEvent(new Event('vp3:chat-email-watch-changed'));
      }
      if (data.tool_receipt?.capability === 'email.send' && data.tool_receipt.status === 'completed') {
        window.dispatchEvent(new Event('vp3:chat-email-watch-changed'));
      }
      applyInitiativeFollowthroughV1(data.initiative_followthrough);
      bindInitiativeActionV1(reply,data.initiative_action,data.initiative_followthrough);
      bindMeetingActionV1(reply,data.meeting_action);
      if (/^\/initiative (?:on|off|screen on|screen off)$/i.test(request.message.trim())) {
        window.dispatchEvent(new Event('vp3:initiative-setting-changed'));
      }
      const autoAction=(data.actions||[]).find(action=>action && action.auto && action.url);
      if(autoAction){window.setTimeout(()=>{window.location.href=withStudioReturn(String(autoAction.url));},450);}
      const autoMediaAction=(data.actions||[]).find(action=>action && action.auto && action.type==='media_capture');
      if(autoMediaAction){window.setTimeout(()=>{window.StonefellowMediaStudio?.open(autoMediaAction.mode||'camera',Number(autoMediaAction.camera_index||0));},260);}

      void refreshHistory().catch(() => {});
      if (inputMode === 'voice' && voiceMode && !existingReply && !data.private_email_read && !data.private_meeting_prep) {
        await speakAgentResponse(data.answer);
      }
    } catch (error) {
      if(pendingSend!==request||request.revision!==chatRevision)return;
      if(!input.value&&!gmailAction){input.value=message;autoSize();}
      if(initiativeIdentityMatchesV1()&&(request.initiativeReply||/^initiative_reply_(?:stale|forbidden|unavailable)$/.test(String(error.message || ''))||!error.status)){
        initiativeVisualRetry=visualTurn;
      }
      if(request.initiativeReply&&[403,409].includes(Number(error.status))&&initiativeReplyTarget){
        initiativeReplyTarget.stale=true;delete initiativeReplyTarget.retry_message;renderInitiativeReplyV1();
      }else if(request.initiativeReply&&initiativeReplyTarget&&initiativeReplyTarget.message_id===request.initiativeReply.message_id){
        initiativeReplyTarget.retry_message=request.message;renderInitiativeReplyV1();
      }
      keepDraft(input.value, request.conversationId);
      typing.remove();
      const initiativeError=request.initiativeReply||/^initiative_reply_(?:stale|forbidden|unavailable)$/.test(String(error.message || ''));
      const initiativeFailure=initiativeError&&Number(error.status)===409
        ? request.initiativeReply?'That Agent update changed. Your reply is kept; choose its current Reply action or remove the reply context.'
          :'That Agent update changed. Your message is kept; review its current status before trying again.'
        : initiativeError&&Number(error.status)===403
        ? 'Access to that Agent update could not be confirmed. Your reply is kept; refresh Chat before continuing.'
        : initiativeError&&(Number(error.status)===503||error.message==='initiative_reply_unavailable')
        ? request.initiativeReply?'Follow-through is temporarily unavailable. Your reply and its context are kept.'
          :'Follow-through is temporarily unavailable. Your message is kept. Retry the same message when ready.' : '';
      recoveryStatus(initiativeFailure || (error.name === 'AbortError'
        ? 'The response timed out. You can try again.'
        : error.status ? String(error.message || 'Chat could not complete this request.').slice(0,500)
        : 'Could not connect to Chat. Please try again.'));
      window.dispatchEvent(new CustomEvent('stonefellow:chat-diagnostic',{detail:{state:'failed',code:String(error.status || error.name || 'transport')}}));
    } finally {
      clearTimeout(request.timer);
      if(pendingSend!==request)return;
      pendingSend=null;showStop();
      busy = false;
      send.disabled = false; input.disabled=false;
      if (gmailDraft?.isConnected) gmailDraft.click();
      else input.focus();
      if (inputMode === 'voice' && voiceMode && !voiceSpeaking) {
        window.setTimeout(startVoiceRecognition, 220);
      }
    }
  }

  thread.addEventListener('click', event => {
    const promptAction = event.target.closest('[data-chat-prompt-action]');
    if (!promptAction) return;
    event.preventDefault();
    const prompt = String(promptAction.dataset.chatPromptAction || '').trim();
    if (!prompt || busy) return;
    const actionCard=promptAction.closest('[data-system-app-action-card]');
    if(actionCard){
      actionCard.classList.add('executing');
      promptAction.disabled=true;
      promptAction.dataset.originalLabel=promptAction.textContent||'Confirm';
      promptAction.textContent='Executing…';
      const state=actionCard.querySelector('header span');
      if(state)state.textContent='Executing';
    }
    sendMessage(prompt).finally?.(()=>{
      if(actionCard && document.body.contains(actionCard) && !actionCard.classList.contains('superseded')){
        actionCard.classList.remove('executing');
        if(promptAction.dataset.originalLabel)promptAction.textContent=promptAction.dataset.originalLabel;
        promptAction.disabled=false;
        const state=actionCard.querySelector('header span');
        if(state)state.textContent='Confirmation required';
      }
    });
  });

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
      if (button.hasAttribute('data-preserve-chat-draft') && (input.disabled || input.value.trim())) {
        if (!input.disabled) input.focus();
        return;
      }
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
  window.addEventListener('vp3:audio-player-removed',event=>{
    const audio=event.detail,state=audioState.get(audio);if(!state)return;
    audio.pause();state.sessionGeneration++;state.wasPlaying=false;
    if(state.sessionToken)void audioTrackEvent(audio,'stop',0,true);state.sessionToken='';
    clearInterval(state.heartbeat);state.heartbeat=null;
    if (activeAudio === audio) {
      activeAudio = null;
      if (nowPlaying) nowPlaying.hidden = true;
      if (nowPlayingCover) {
        nowPlayingCover.removeAttribute('src');
        nowPlayingCover.hidden = true;
      }
      if (nowPlayingTitle) nowPlayingTitle.textContent = '';
      if (nowPlayingAlbum) nowPlayingAlbum.textContent = '';
    }
  });
  window.addEventListener('vp3:audio-players-added',event=>{
    const scope=event.detail;
    if(scope instanceof Element&&scope.isConnected)attachAudioTracking(scope);
  });

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

  function requestTopOfMindTurn(message) {
    if (input.value.trim() || busy || !contextReady || initialRestorePending || restoringConversation
        || initiativeReplyTarget || window.VP3ParticipantContinuity?.selectedContext()) return false;
    void sendMessage(message);
    return true;
  }

  const canonicalChatContinuity = {
    runYoutubeLocalTurn: (message, action) => runYoutubeLocalTurn(message, action),
    requestYoutubePicks:() => requestTopOfMindTurn('/youtube picks'),
    requestArtistResearch:(artist, delegate = false) => {
      if (typeof artist !== 'string' || !artist.trim() || artist.length > 120 || /[\r\n]/.test(artist)) return false;
      const command = delegate ? 'Have a specialist research artist ' : 'Research artist ';
      return requestTopOfMindTurn(command + artist.trim());
    },
    requestTopicResearch:(topic, delegate = false) => {
      if (typeof topic !== 'string' || !topic.trim() || Array.from(topic).length > 120 || /[\r\n]/.test(topic)) return false;
      const command = delegate ? 'Have a specialist research topic ' : 'Research topic ';
      return requestTopOfMindTurn(command + topic.trim());
    },
    showChatView:() => setChatCanvasView('chat'),
    mountAgentGreeting:text => {
      if (thread.dataset.view !== 'chat' || !contextReady || initialRestorePending || restoringConversation) return null;
      const previous = thread.querySelector('[data-agent-voice-greeting]');
      if (previous?.dataset.agentVoiceGreeting === text) return previous;
      previous?.remove();
      const element = messageElement('assistant',text);
      element.dataset.agentVoiceGreeting = text;
      const listening = thread.querySelector('.chat-listening-continuation');
      thread.insertBefore(element,listening);
      thread.scrollTop = thread.scrollHeight;
      return element;
    },
    pendingParticipantQuestion:()=>pendingSend?{message:pendingSend.message,conversation_id:pendingSend.conversationId,submitted:true}:lastParticipantQuestion&&Date.now()-lastParticipantQuestion.at<120000&&lastParticipantQuestion.conversation_id===Number(conversationId||0)?{...lastParticipantQuestion}:null,
    isVoice:() => Boolean(voiceMode),
    conversationId:() => Number(conversationId || 0),
    agentId:() => Number(cfg.agentId || 0),
    ensureConversation:ensureConversationPa3,
    mountProfileHandoff:mountProfileHandoffPa3,
    mountProfileCall:mountProfileCallPa3c,
    mountProfileSpeech:mountProfileSpeechPa4a,
    mountProfileAttention:mountProfileAttentionPa4b,
    isRestoring:() => initialRestorePending || restoringConversation > 0,
    isReady:() => contextReady,
    isBusy:() => busy || initialRestorePending || restoringConversation > 0,
    requestGmailReviewAction: command => {
      if (!gmailReviewActionLabel(command) || !contextReady || busy || initialRestorePending
          || restoringConversation > 0 || input.value.trim()) return false;
      void sendMessage(command,'text',true);
      return true;
    },
    openConversation:async (id, options = {}) => {
      const target = Number(id);
      const sourceMessageId = options.messageId === undefined ? 0 : options.messageId;
      if (!Number.isSafeInteger(target) || target < 1 || !Number.isSafeInteger(sourceMessageId) || sourceMessageId < 0) return false;
      const loaded = await loadConversation(target, sourceMessageId);
      return loaded === true && Number(conversationId || 0) === target && (!sourceMessageId ||
        Boolean(thread.querySelector('[data-chat-message-id="'+sourceMessageId+'"][data-chat-conversation-id="'+target+'"]')));
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
    const element=addMessage('assistant',text,[],[],'',[],actions);
    if (token) { try { localStorage.setItem(storageKey,token); } catch (error) {} }
    if(window.STONEFELLOW_AGENT_IDENTITY_V236){element.dataset.agentVoiceGreeting=String(intro.greeting).trim();return;}
    window.setTimeout(() => speakIntroV101(String(intro.greeting)),450);
  }

  // Stored IDs are hints only: the server still authorizes every history load.
  async function restoreInitialConversationV101() {
    const id = initialConversation();
    try {
      if (String(cfg.initialView || 'chat') === 'chat' && id > 0) await loadConversation(id);
      else { contextReady=true; restoreDraft(); }
    } catch (error) {
      if (error.status === 401) return;
      if (error.status === 404) { contextReady=true; conversationId=0; rememberConversation(); restoreDraft(); }
      recoveryStatus('Conversation history is unavailable. Your drafts are kept.');
    } finally { initialRestorePending = false; window.dispatchEvent(new Event('vp3:chat-context-ready')); }
    presentIntroV101();
  }
  void restoreInitialConversationV101();

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
    payload = {},
    signal
  ) {
    const response = await fetch(
      cfg.libraryEndpoint,
      {
        method:'POST',signal,
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
      const error=new Error(data.error || 'Library request failed.');error.status=response.status;throw error;
    }

    return data;
  }

  let recentHistoryBusy=false,recentHistoryQueued=false,recentHistoryRevision=0,recentHistoryTimer=0,recentHistoryController=null;
  const activityPlayerOwner=Number(cfg.userId),activityPlayerAgent=Number(cfg.agentId||0);
  let activityPlayerParticipant=0,activityPlayerHistory=[];
  function activityPlayerAllowed(){
    const live=window.STONEFELLOW_CHAT_CONTINUITY_V87||window.STONEFELLOW_CHAT_CONTINUITY;
    return activityPlayerOwner>0&&Number(window.STONEFELLOW_CHAT?.userId)===activityPlayerOwner
      &&Number(window.STONEFELLOW_CHAT?.agentId||0)===activityPlayerAgent
      &&Number(live?.agentId?.())===activityPlayerAgent
      &&activityPlayerParticipant<=0&&!window.VP3ParticipantContinuity?.selectedContext?.();
  }
  const recentHistoryPlaying=grid=>!!grid&&[...grid.querySelectorAll('audio')].some(audio=>!audio.paused&&!audio.ended);
  function listeningHost(){
    const thread = document.getElementById('chatThread');
    if (!thread) return null;
    let host = document.getElementById('chatListeningContinuation');
    if (!host) {
      host = document.createElement('section');
      host.id = 'chatListeningContinuation';
      host.className = 'chat-listening-continuation';
      host.hidden = true;
      host.setAttribute('aria-label', 'Continue listening');
      host.setAttribute('data-player-queue', '');
    }
    const destination=document.querySelector('[data-agent-activity-listening]')||thread;
    if(host.parentElement!==destination){
      if(destination.moveBefore&&host.isConnected)destination.moveBefore(host,null);
      else if(!recentHistoryPlaying(host))destination.append(host);
    }
    return host;
  }
  window.addEventListener('vp3:agent-activity-listening-slot',listeningHost);

  function syncListeningRow(host, audio) {
    const playing = !audio.paused && !audio.ended;
    const toggle = host.querySelector('[data-listening-toggle]');
    toggle.textContent = playing ? 'Pause' : 'Resume';
    toggle.setAttribute('aria-label', playing ? 'Pause listening' : 'Resume listening');
    const position = audio.readyState >= 1 ? audio.currentTime : Number(audio.dataset.resumePosition);
    const seconds = Math.floor(position || 0);
    host.querySelector('[data-listening-position]').textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
  }

  function renderListeningContinuation(host,track){
    if (!host) return;
    const thread = host.parentElement?.id==='chatThread'?host.parentElement:null;
    let audio = host.querySelector('audio');
    const id = Number(track?.id);
    const source = String(track?.audio || '');
    const playable = Number.isSafeInteger(id) && id > 0 && source;
    const atEnd = thread&&thread.scrollHeight - thread.scrollTop - thread.clientHeight < 96;
    if (audio && (!playable || Number(audio.dataset.trackId) !== id)) {
      window.dispatchEvent(new CustomEvent('vp3:audio-player-removed', {detail:audio}));
      audio = null;
    }
    if (!playable) {
      host.hidden = true;
      host.replaceChildren();
      return;
    }
    if (!audio) {
      host.innerHTML = `
        <img class="chat-listening-resume-cover" alt="" hidden>
        <strong data-listening-title></strong>
        <span data-listening-position></span>
        <button type="button" data-listening-toggle>Resume</button>
        <audio class="chat-audio-player" preload="none" data-listening-resume-audio></audio>`;
      audio = host.querySelector('audio');
      audio.dataset.trackId = String(id);
      audio.src = source;
      audio.addEventListener('loadedmetadata', () => {
        const position = Number(audio.dataset.resumePosition);
        if (position > 0) {
          try {
            audio.currentTime = Number.isFinite(audio.duration)
              ? Math.min(position, Math.max(0, audio.duration - .25))
              : position;
          } catch (_) {}
        }
      }, {once:true});
      for (const event of ['loadedmetadata', 'play', 'pause', 'timeupdate', 'ended', 'error']) {
        audio.addEventListener(event, () => {
          if (audio.isConnected) syncListeningRow(host, audio);
        });
      }
      host.querySelector('[data-listening-toggle]').addEventListener('click', () => {
        if (audio.paused || audio.ended) audio.play().catch(() => {});
        else audio.pause();
      });
      window.dispatchEvent(new CustomEvent('vp3:audio-players-added', {detail:host}));
    }
    const position = Number(track.last_position_seconds);
    audio.dataset.resumePosition = String(Number.isFinite(position) && position > 0 ? position : 0);
    audio.dataset.playerTitle = String(track.title || 'Untitled track');
    audio.dataset.playerAlbum = String(track.album || '');
    audio.dataset.playerCover = String(track.cover || '');
    audio.dataset.playerDetail = String(track.detail || '');
    const title = host.querySelector('[data-listening-title]');
    title.textContent = title.title = audio.dataset.playerTitle;
    const cover = host.querySelector('img');
    cover.hidden = !audio.dataset.playerCover;
    if (cover.hidden) cover.removeAttribute('src');
    else cover.src = audio.dataset.playerCover;
    syncListeningRow(host, audio);
    host.hidden = false;
    if (atEnd && (!thread.dataset.view || thread.dataset.view === 'chat')) thread.scrollTop = thread.scrollHeight;
  }
  async function refreshRecentPlayerHistory(){
    if(!activityPlayerAllowed()){
      activityPlayerHistory=[];
      const privateHost=document.getElementById('chatListeningContinuation');
      if(privateHost)renderListeningContinuation(privateHost,null);
      return;
    }
    const section=document.getElementById('playerRecent'),grid=section?.querySelector('.chat-player-recent-grid'),host=listeningHost();
    if((!grid&&!host)||!cfg.libraryEndpoint||document.hidden)return;
    if(recentHistoryBusy){recentHistoryQueued=true;return;}
    recentHistoryBusy=true;const revision=recentHistoryRevision,controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),10000);recentHistoryController=controller;
    try{
      const data=await libraryApi('recent_history',{},controller.signal);
      if(revision!==recentHistoryRevision||!activityPlayerAllowed())return;
      const sameOwner = Number(data.user_id) === Number(cfg.userId);
      const rows = sameOwner && Array.isArray(data.tracks) ? data.tracks : [];
      activityPlayerHistory=rows;
      rows.forEach(track=>{const id=Number(track.id)||0;if(id>0)tracks[id]={...tracks[id],...track};});
      const playing = host?.querySelector('audio');
      const currentTrack = playing && !playing.paused && !playing.ended
        ? rows.find(track => Number(track.id) === Number(playing.dataset.trackId))
        : null;
      renderListeningContinuation(host, currentTrack || rows[0]);
      if(!grid?.isConnected||(sameOwner&&recentHistoryPlaying(grid)))return;
      if (!sameOwner) grid.querySelectorAll('audio').forEach(audio => window.dispatchEvent(new CustomEvent('vp3:audio-player-removed', {detail:audio})));
      grid.innerHTML=rows.map(track=>{
        const id=Number(track.id)||0,resume=Math.max(0,Number(track.last_position_seconds)||0),date=new Date(track.last_played_at);
        const when=Number.isNaN(date.getTime())?'Recently':date.toLocaleString(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
        return `<article class="chat-player-recent-card" data-player-search-text="${escapeHtml((track.title||'')+' '+(track.album||''))}">
          <img src="${escapeHtml(track.cover||'')}" alt="">
          <div><span>Last played ${escapeHtml(when)}</span><strong>${escapeHtml(track.title||'')}</strong><small>${escapeHtml(track.album||'')}</small></div>
          <button type="button" data-resume-track="${id}" data-resume-position="${resume}">${resume>5?'Continue':'Play'}</button>
          <audio class="chat-audio-player" preload="none" data-track-id="${id}" data-player-title="${escapeHtml(track.title||'')}" data-player-album="${escapeHtml(track.album||'')}" data-player-cover="${escapeHtml(track.cover||'')}" data-player-detail="${escapeHtml(track.detail||'')}" src="${escapeHtml(track.audio||'')}"></audio>
        </article>`;
      }).join('')||'<div class="chat-canvas-empty compact">Start listening and your recent tracks will appear here.</div>';
      const count=section.querySelector('header>small');if(count)count.textContent=rows.length+' recent';
      window.dispatchEvent(new CustomEvent('vp3:audio-players-added',{detail:grid}));
    }catch(error){
      const accessLost = [401, 403, 419].includes(error.status);
      if(accessLost)activityPlayerHistory=[];
      if(revision===recentHistoryRevision&&grid?.isConnected&&(accessLost||!recentHistoryPlaying(grid))){
        if (accessLost) grid.querySelectorAll('audio').forEach(audio => window.dispatchEvent(new CustomEvent('vp3:audio-player-removed', {detail:audio})));
        grid.textContent='Playback history could not be loaded. It will retry automatically.';
        const count=section.querySelector('header>small');if(count)count.textContent='Unavailable';
      }
      if (revision === recentHistoryRevision && (accessLost || !recentHistoryPlaying(host))) renderListeningContinuation(host, null);
    }finally{clearTimeout(timeout);if(recentHistoryController===controller)recentHistoryController=null;recentHistoryBusy=false;if(recentHistoryQueued){recentHistoryQueued=false;scheduleRecentPlayerHistory();}}
  }
  function scheduleRecentPlayerHistory(){clearTimeout(recentHistoryTimer);recentHistoryTimer=setTimeout(()=>void refreshRecentPlayerHistory(),350);}
  window.addEventListener('vp3:playback-history-changed',scheduleRecentPlayerHistory);
  function resetActivityPlayer(){
    recentHistoryRevision++;
    activityPlayerHistory=[];
    recentHistoryQueued=false;
    recentHistoryController?.abort();
    const host=document.getElementById('chatListeningContinuation');
    if(host&&!activityPlayerAllowed())renderListeningContinuation(host,null);
    if(activityPlayerAllowed())scheduleRecentPlayerHistory();
  }
  window.addEventListener('vp3:participant-selection-changed',resetActivityPlayer);
  window.addEventListener('vp3:participant-task-context',event=>{
    activityPlayerParticipant=Number(event.detail?.participant_id)||0;
    resetActivityPlayer();
  });
  window.addEventListener('vp3:chat-context-ready',resetActivityPlayer);
  window.addEventListener('vp3:chat-canvas-view-changed',scheduleRecentPlayerHistory);
  window.addEventListener('focus',scheduleRecentPlayerHistory);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)scheduleRecentPlayerHistory();});
  document.addEventListener('pause',event=>{if(event.target.matches?.('.chat-audio-player'))scheduleRecentPlayerHistory();},true);
  let recentHistoryPoll=setInterval(()=>void refreshRecentPlayerHistory(),30000);
  window.addEventListener('pageshow',()=>{if(!recentHistoryPoll)recentHistoryPoll=setInterval(()=>void refreshRecentPlayerHistory(),30000);scheduleRecentPlayerHistory();});
  window.addEventListener('pagehide',()=>{
    recentHistoryRevision++;
    activityPlayerHistory=[];
    recentHistoryQueued=false;
    recentHistoryController?.abort();
    clearInterval(recentHistoryPoll);
    recentHistoryPoll=0;
    const host = document.getElementById('chatListeningContinuation');
    if (host) renderListeningContinuation(host, null);
    clearTimeout(recentHistoryTimer);
  });
  scheduleRecentPlayerHistory();

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
    fromQueue = false,
    preferredAudio = null
  ) {
    const audio =
      preferredAudio || audioForTrack(trackId);

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

    return audio.play().then(() => true,() => false);
  }

  Object.assign(window.STONEFELLOW_CHAT_CONTINUITY||{}, {
    playActivityTrack(trackId){
      const id=Number(trackId);
      if(!activityPlayerAllowed()||!Number.isSafeInteger(id)||id<1)return false;
      const track=activityPlayerHistory.find(row=>Number(row.id)===id);
      if(!track)return false;
      const host=listeningHost();
      renderListeningContinuation(host,track);
      const audio=host?.querySelector('audio');
      if(!audio)return false;
      const position=audio.currentTime>0?null:Number(track.last_position_seconds)||0;
      return playTrack(id,position,false,audio);
    },
    reviewActivityTrack(trackId){
      const id=Number(trackId);
      if(!activityPlayerAllowed()||!Number.isSafeInteger(id)||id<1||!playerDrawer
        ||!activityPlayerHistory.some(row=>Number(row.id)===id))return false;
      openTrackInfo(id);
      return true;
    }
  });

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
          ),
          false,
          resume.closest('.chat-player-recent-card')?.querySelector('audio') || null
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
