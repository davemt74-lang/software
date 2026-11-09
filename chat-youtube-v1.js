(() => {
  'use strict';

  const videoIdPattern = /^[A-Za-z0-9_-]{11}$/;
  const brandLogo = new URL('assets/youtube-logo-v1.png', document.currentScript?.src || document.baseURI).href;
  const config = window.VP3_YOUTUBE_PLAYBACK_V1 || {};
  const watchSampleMs = 4000;
  const watchReportSeconds = 12;
  const maxWatchSeconds = 14400;
  let captureKey = config.captureKey || '';
  let activePlayer = null;
  let playerApi;
  let pendingRecord = Promise.resolve();
  let lastRecordedPlayback = 0;

  async function historyRequest(action, fields = {}, keepalive = false) {
    if (!config.endpoint || !config.csrf) throw new Error('History unavailable');
    const response = await fetch(config.endpoint, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      body: new URLSearchParams({action, csrf_token: config.csrf, ...fields}),
      signal: AbortSignal.timeout(8000), keepalive
    });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error('History unavailable');
    return data;
  }

  function loadPlayerApi() {
    if (typeof window.YT?.Player === 'function') return Promise.resolve(window.YT);
    if (playerApi) return playerApi;
    playerApi = new Promise((resolve, reject) => {
      const previousReady = window.onYouTubeIframeAPIReady;
      const script = document.createElement('script');
      script.src = 'https://www.youtube.com/iframe_api';
      script.async = true;
      const finish = (error) => {
        clearTimeout(timeout);
        if (window.onYouTubeIframeAPIReady === ready) window.onYouTubeIframeAPIReady = previousReady;
        if (error) { script.remove(); reject(error); }
        else resolve(window.YT);
      };
      const ready = () => {
        try { previousReady?.(); } finally { finish(); }
      };
      const timeout = setTimeout(() => finish(new Error('YouTube controls timed out')), 8000);
      window.onYouTubeIframeAPIReady = ready;
      script.addEventListener('error', () => finish(new Error('YouTube controls unavailable')), {once:true});
      document.head.append(script);
    }).catch(error => { playerApi = null; throw error; });
    return playerApi;
  }

  function newPlay(active) {
    return {
      fields:{message_id:active.messageId, video_id:active.video.video_id,
        play_id:window.crypto?.randomUUID?.(), capture_key:captureKey},
      recorded:false, recording:false, recordAttemptAt:-Infinity, watchedMs:0, sentSeconds:0,
      watchAttemptAt:-Infinity, sending:false, flushPending:false
    };
  }

  function canCapture(active, play) {
    return active.video.made_for_kids === false && play.fields.capture_key
      && play.fields.capture_key === captureKey && play.fields.play_id && active.messageId
      && !window.VP3ParticipantContinuity?.selectedContext();
  }

  function rememberHistoryWrite(write) {
    pendingRecord = Promise.all([pendingRecord, write]).then(() => undefined);
  }

  function capturePlayback(active) {
    const play = active.play;
    const now = performance.now();
    if (active !== activePlayer || play.recorded || play.recording || !active.panel.isConnected || document.hidden
        || !canCapture(active, play) || now - play.recordAttemptAt < 10000) return;
    play.recording = true;
    play.recordAttemptAt = now;
    rememberHistoryWrite(historyRequest('record', play.fields).then(data => {
      if (data.recorded) {
        play.recorded = true;
        lastRecordedPlayback = Date.now();
        window.dispatchEvent(new Event('vp3:youtube-history-changed'));
      }
    }).catch(() => {}).finally(() => { play.recording = false; }));
  }

  function reportWatch(active, play, flush = false) {
    if (!play.recorded || play.watchRejected || !canCapture(active, play)) return;
    const seconds = Math.min(maxWatchSeconds, Math.floor(play.watchedMs / 1000));
    if (seconds <= play.sentSeconds) return;
    if (play.sending) { if (flush) play.flushPending = true; return; }
    const now = performance.now();
    if (!flush && (seconds - play.sentSeconds < watchReportSeconds || now - play.watchAttemptAt < watchReportSeconds * 1000)) return;
    play.watchAttemptAt = now;
    play.sending = true;
    rememberHistoryWrite(historyRequest('watch', {...play.fields, watch_seconds:seconds}, true).then(data => {
      if (data.recorded) play.sentSeconds = seconds;
      else play.watchRejected = true;
    }).catch(() => {}).finally(() => {
      play.sending = false;
      if (play.flushPending) {
        play.flushPending = false;
        reportWatch(active, play, true);
      }
    }));
  }

  function expectedVideo(active) {
    try { return new URL(active.player.getVideoUrl()).searchParams.get('v') === active.video.video_id; }
    catch { return false; }
  }

  function sampleWatch(active, final = false) {
    const previous = active.watchSample;
    active.watchSample = null;
    if (document.hidden || !active.panel.isConnected || !canCapture(active, active.play) || !expectedVideo(active)) return;
    try {
      if (!final && active.player.getPlayerState() !== window.YT.PlayerState.PLAYING) return;
      const current = {at:performance.now(), position:active.player.getCurrentTime(), rate:active.player.getPlaybackRate()};
      if (![current.position, current.rate].every(Number.isFinite) || current.position < 0 || current.rate <= 0) return;
      active.watchSample = current;
      if (!previous || !active.play.recorded || previous.rate !== current.rate) return;
      const elapsed = (current.at - previous.at) / 1000;
      const advanced = (current.position - previous.position) / current.rate;
      // Count advancing wall time. Ignore seek jumps, stalled samples and long timer gaps.
      if (elapsed > 0 && elapsed * 1000 <= watchSampleMs * 2 && advanced > 0
          && Math.abs(advanced - elapsed) <= Math.max(0.5, elapsed * 0.15)) {
        active.play.watchedMs += Math.min(advanced, elapsed) * 1000;
      }
    } catch {} // Playback stays available if the iframe cannot expose progress.
  }

  function startWatching(active) {
    capturePlayback(active);
    if (!config.watchCapture || active.watchTimer || !canCapture(active, active.play)) return;
    sampleWatch(active);
    active.watchTimer = window.setInterval(() => {
      if (!active.panel.isConnected || !canCapture(active, active.play) || active.play.watchRejected) {
        stopWatching(active);
        return;
      }
      capturePlayback(active);
      sampleWatch(active);
      reportWatch(active, active.play);
    }, watchSampleMs);
  }

  function stopWatching(active) {
    if (!active.watchTimer) return;
    window.clearInterval(active.watchTimer);
    active.watchTimer = 0;
    sampleWatch(active, true);
    active.watchSample = null;
    reportWatch(active, active.play, true);
  }

  function element(tag, className, text) {
    const node = document.createElement(tag);
    node.className = className;
    if (text) node.textContent = text;
    return node;
  }

  function stopPlayer(restoreFocus = false) {
    if (!activePlayer) return;
    stopWatching(activePlayer);
    const { panel, button, player } = activePlayer;
    activePlayer = null;
    try { player?.destroy(); } catch {}
    panel.remove();
    button.setAttribute('aria-expanded', 'false');
    if (restoreFocus && button.isConnected) button.focus();
  }

  function thumbnailUrl(source, videoId) {
    if (typeof source !== 'string' || source.length > 256) return '';
    try {
      const url = new URL(source);
      if (!['https://i.ytimg.com', 'https://img.youtube.com'].includes(url.origin) ||
          url.username || url.password || url.search || url.hash) return '';
      const parts = url.pathname.split('/');
      return parts.length === 4 && ['vi', 'vi_webp'].includes(parts[1]) && parts[2] === videoId &&
        /^[A-Za-z0-9_]{1,32}\.(?:jpg|webp)$/.test(parts[3]) ? url.href : '';
    } catch {
      return '';
    }
  }

  function openLink(className, label, url) {
    const link = element('a', className, label);
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    return link;
  }

  function playVideo(row, button, video, title, messageId, suggestion) {
    const videoId = video.video_id;
    if (activePlayer?.button === button) return;
    stopPlayer();
    const panel = element('div', 'vp3-youtube-player');
    const frame = element('iframe', 'vp3-youtube-frame');
    frame.title = 'YouTube video · ' + title;
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
    frame.allowFullscreen = true;
    frame.src = 'https://www.youtube.com/embed/' + videoId + '?' + new URLSearchParams({
      autoplay:'1', playsinline:'1', enablejsapi:'1', origin:location.origin
    });
    const close = element('button', 'vp3-youtube-close', 'Close player');
    close.type = 'button';
    close.addEventListener('click', () => stopPlayer(true));
    panel.append(frame, close);
    row.append(panel);
    button.setAttribute('aria-expanded', 'true');
    const active = {panel, button, video, messageId, suggestion};
    active.play = newPlay(active);
    activePlayer = active;
    loadPlayerApi().then(api => {
      if (activePlayer !== active || !frame.isConnected) return;
      active.player = new api.Player(frame, {events: {
        onReady(event) {
          if (activePlayer !== active) return;
          active.player = event.target;
          active.ready = true;
          if (event.target.getPlayerState() === api.PlayerState.PLAYING) startWatching(active);
        },
        onStateChange(event) {
          if (activePlayer !== active) return;
          if (event.data === api.PlayerState.PLAYING) {
            active.offer?.remove();
            if (active.ended) {
              if (!expectedVideo(active)) return;
              active.ended = false;
              active.play = newPlay(active);
            }
            startWatching(active);
          } else {
            stopWatching(active);
            if (event.data === api.PlayerState.ENDED) {
              active.ended = true;
              void offerMorePicks(active);
            }
          }
        }
      }});
    }).catch(() => {}); // The native iframe controls remain available.
  }

  async function offerMorePicks(active) {
    const allowed = () => active === activePlayer && active.panel.isConnected && !document.hidden
      && window.VP3_COGNITIVE_PRESENTATION?.allowsMusicOffer?.()
      && !window.STONEFELLOW_CHAT_CONTINUITY?.isBusy?.()
      && !window.VP3ParticipantContinuity?.selectedContext();
    if (!config.proactive || !active.suggestion || active.video.made_for_kids !== false
        || !active.messageId || active.offerRequested || !allowed()) return;
    active.offerRequested = true;
    try {
      const data = await historyRequest('offer', {message_id:active.messageId, video_id:active.video.video_id});
      if (!data.offer || !allowed() || active.player.getPlayerState() !== window.YT.PlayerState.ENDED) return;
      const offer = element('div', 'vp3-youtube-player');
      offer.setAttribute('aria-label', 'Agent music suggestion');
      offer.append(element('p', 'vp3-youtube-channel', 'Want another set of YouTube music picks?'));
      for (const [label, action] of [['Show music picks','picks'],['Snooze for a day','snooze'],['Dismiss','dismiss']]) {
        const button = element('button', 'vp3-youtube-close', label);
        button.type = 'button';
        button.addEventListener('click', async () => {
          if (!allowed()) { offer.remove(); return; }
          if (action === 'picks') {
            const accepted = window.STONEFELLOW_CHAT_CONTINUITY_V87?.requestYoutubePicks?.();
            if (!accepted) return;
          } else if (action === 'snooze') {
            try { await historyRequest('snooze'); }
            catch { button.textContent = 'Retry snooze'; return; }
          }
          offer.remove();
        });
        offer.append(button);
      }
      active.offer = offer;
      active.panel.append(offer);
    } catch {} // An unavailable offer must never interrupt playback.
  }

  function attach(message, result) {
    const messageText = message.querySelector('.message-text');
    if (!messageText || !Number.isSafeInteger(result?.expires_at) || result.expires_at <= Date.now() / 1000 ||
        !Array.isArray(result.videos)) return;

    const history = result.history === true;
    const sourceMessageId = Number.isSafeInteger(result.source_message_id) && result.source_message_id > 0
      ? result.source_message_id : null;
    const resultMessageId = () => sourceMessageId ?? message.dataset.chatMessageId;
    if (history) {
      if (window.VP3ParticipantContinuity?.selectedContext()) return;
      message.dataset.vp3YoutubePrivate = '1';
    }
    const section = element('section', 'vp3-youtube-results');
    const draft = !history && result.suggestion_kind === 'playlist';
    section.setAttribute('aria-label', history ? 'Played in VP3' : (draft ? 'YouTube playlist draft' : 'YouTube search results'));
    const header = element('header', 'vp3-youtube-heading');
    const logo = element('img', 'vp3-youtube-logo');
    logo.src = brandLogo;
    logo.alt = 'YouTube';
    const brand = openLink('vp3-youtube-brand', '', 'https://www.youtube.com/');
    brand.append(logo);
    const query = typeof result.query === 'string' ? result.query.slice(0, 200).trim() : '';
    const title = history ? 'Played in VP3' : (draft ? 'Playlist draft' : (query || 'Video results'));
    header.append(brand, element('strong', 'vp3-youtube-query', title));

    if (!history && config.endpoint) {
      const recent = element('button', 'vp3-youtube-close', 'Recently played');
      recent.type = 'button';
      recent.addEventListener('click', () => window.dispatchEvent(new Event('vp3:youtube-recent')));
      header.append(recent);
    }
    if (!history && result.suggestion_reason) {
      section.append(header, element('p', 'vp3-youtube-channel', String(result.suggestion_reason).slice(0,200)));
      const save = element('button', 'vp3-youtube-close', 'Save this music taste');
      save.type = 'button';
      save.addEventListener('click', async () => {
        if (window.VP3ParticipantContinuity?.selectedContext()) return;
        save.disabled = true;
        try {
          await historyRequest('save_taste', {message_id:resultMessageId() || ''});
          save.textContent = 'Music taste saved';
        } catch {
          save.textContent = 'Retry saving music taste';
          save.disabled = false;
        }
      });
      if (config.endpoint && result.taste_topic && result.taste_topic !== 'music discovery') section.append(save);
    }
    const list = element('ul', 'vp3-youtube-list');
    const seen = new Set();
    for (const video of result.videos.slice(0, history ? 12 : 6)) {
      const videoId = video?.video_id;
      if (typeof videoId !== 'string' || !videoIdPattern.test(videoId) || (!history && seen.has(videoId))) continue;
      seen.add(videoId);
      const title = typeof video.title === 'string' ? video.title.slice(0, 200).trim() : '';
      const channel = typeof video.channel_title === 'string' ? video.channel_title.slice(0, 120).trim() : '';
      const row = element('li', 'vp3-youtube-result');
      const expiry = history ? video.expires_at : result.expires_at;
      const canEmbed = typeof video.made_for_kids === 'boolean' && expiry > Date.now() / 1000;
      const button = element(canEmbed ? 'button' : 'div', 'vp3-youtube-play');
      if (canEmbed) {
        button.type = 'button';
        button.setAttribute('aria-label', 'Play in Chat · ' + (title || 'YouTube video'));
        button.setAttribute('aria-expanded', 'false');
        button.addEventListener('click', () => {
          if (expiry <= Date.now() / 1000) {
            if (section.contains(activePlayer?.panel || null)) stopPlayer();
            section.replaceChildren(element('p', 'vp3-youtube-expired', 'These results expired. Search YouTube again.'));
            return;
          }
          playVideo(row, button, video, title || 'YouTube video', history ? video.message_id : resultMessageId(), Boolean(result.suggestion_reason));
        });
      }
      const thumbnail = thumbnailUrl(video.thumbnail_url, videoId);
      if (thumbnail) {
        const image = element('img', 'vp3-youtube-thumbnail');
        image.src = thumbnail;
        image.alt = '';
        image.loading = 'lazy';
        image.referrerPolicy = 'no-referrer';
        image.addEventListener('error', () => image.remove(), { once: true });
        button.append(image);
      }
      const details = element('span', 'vp3-youtube-details');
      details.append(element('strong', 'vp3-youtube-title', title || 'YouTube video'));
      if (channel) details.append(element('span', 'vp3-youtube-channel', channel));
      if (history) details.append(element('span', 'vp3-youtube-channel', new Date(video.played_at * 1000).toLocaleString()));
      if (canEmbed) details.append(element('span', 'vp3-youtube-play-label', 'Play in Chat'));
      button.append(details);
      const watch = openLink('vp3-youtube-watch', 'Open on YouTube', 'https://www.youtube.com/watch?v=' + videoId);
      row.append(button, watch);
      if (history) {
        const remove = element('button', 'vp3-youtube-close', 'Remove from history');
        remove.type = 'button';
        const status = element('span', 'vp3-youtube-channel');
        status.setAttribute('role', 'status');
        remove.addEventListener('click', async () => {
          remove.disabled = true;
          try {
            const data = await historyRequest('remove', {play_id:video.play_id});
            captureKey = data.capture_key || '';
            if (row.contains(activePlayer?.panel || null)) stopPlayer();
            for (const entry of document.querySelectorAll('[data-youtube-play-id]')) {
              if (entry.dataset.youtubePlayId === video.play_id) entry.remove();
            }
            window.dispatchEvent(new Event('vp3:youtube-history-changed'));
          } catch {
            status.textContent = 'Could not remove this entry.';
            remove.disabled = false;
          }
        });
        row.dataset.youtubePlayId = video.play_id;
        row.append(remove, status);
      }
      list.append(row);
    }
    if (!list.childElementCount) return;
    if (!header.parentNode) section.append(header);
    section.append(list);
    messageText.after(section);
  }

  window.addEventListener('stonefellow:chat-context-changing', () => stopPlayer());
  window.addEventListener('vp3:participant-selection-changed', () => stopPlayer());
  window.addEventListener('vp3:chat-canvas-view-changed', () => {
    if (document.getElementById('chatThread')?.dataset.view !== 'chat') stopPlayer();
  });
  window.addEventListener('pagehide', () => stopPlayer());
  document.addEventListener('visibilitychange', () => { if (document.hidden) stopPlayer(); });

  function isHistoryRequest(text) {
    const phrase = text.trim().toLowerCase().replace(/\s+/g, ' ').replace(/[.!?]+$/, '').replace(/,? please$/, '')
      .replace(/^(?:(?:please|ok(?:ay)?|so|well|hey|tell me|(?:can|could|would) you|let['’]s (?:begin|start)(?: (?:(?:in|with|on) )?(?:the|this) show)?(?: with)?)[\s,.:!?–—-]+)+/, '');
    return /^(?:\/youtube history|played in vp3|(?:(?:show|open|pull up) (?:me )?)?(?:my )?you ?tube (?:(?:playback|listening|watch) )?history)$/.test(phrase)
      || /^what (?:did i (?:play|watch|listen to)|(?:have i|i(?: have|['’]ve)) (?:played|watched|listened to|been (?:playing|watching|listening to)))(?: (?:earlier|recently))? on you ?tube(?: in vp3)?(?: (?:earlier|recently))?$/.test(phrase);
  }

  function command(text) {
    const phrase = text.trim().toLowerCase().replace(/[.!?]+$/, '')
      .replace(/^(?:please |(?:can|could|would) you )+/, '').replace(/,? please$/, '');
    if (/^(?:stop|close)(?: playing)? (?:the )?you ?tube(?: (?:video|player))?$/.test(phrase)) return 'stop';
    if (/^pause(?: playing)? (?:the )?you ?tube(?: (?:video|player))?$/.test(phrase)) return 'pause';
    if (activePlayer && /^(?:stop|pause)(?: (?:the )?video)?$/.test(phrase)) return phrase.startsWith('stop') ? 'stop' : 'pause';
    if (config.endpoint) {
      if (isHistoryRequest(text)) return 'recent';
      if (Date.now() - lastRecordedPlayback < 30 * 60 * 1000
          && /^what was (?:that|the) (?:song|video) i (?:played|watched|listened to)(?: earlier)?$/.test(phrase)) return 'recent';
    }
    return null;
  }

  async function runCommand(action) {
    if (action === 'recent') {
      try {
        await pendingRecord;
        const data = await historyRequest('recent');
        if (data.history.length) return {answer:'Here’s what you recently played on YouTube in VP3.', results:{
          history:true, expires_at:Math.max(...data.history.map(video => video.expires_at)), videos:data.history
        }};
        return {answer:data.enabled ? 'Your Played in VP3 history is empty.' : 'You can enable Played in VP3 history in Connections.',
          settings:!data.enabled};
      } catch {
        return {answer:'Played in VP3 history is unavailable. Check your YouTube skill in Connections.', settings:true};
      }
    }
    const active = activePlayer;
    if (!active) return {answer:'There’s no YouTube player open in this Chat.'};
    if (action === 'stop') {
      stopPlayer();
      return {answer:'I closed the YouTube player.'};
    }
    if (!active.ready) return {answer:'YouTube pause is unavailable right now. Use the player’s Pause button or say “Stop YouTube” to close it.'};
    try {
      active.player.pauseVideo();
      return {answer:'I asked the YouTube player to pause.'};
    } catch {
      return {answer:'I couldn’t pause YouTube. Try the player’s Pause button.'};
    }
  }

  window.VP3_CHAT_YOUTUBE_V1 = Object.freeze({attach, command, runCommand, settingsUrl:config.connections});
})();
