(() => {
  'use strict';

  const videoIdPattern = /^[A-Za-z0-9_-]{11}$/;
  const playlistIdPattern = /^[a-f0-9]{32}$/;
  const brandLogo = new URL('assets/youtube-logo-v1.png', document.currentScript?.src || document.baseURI).href;
  const config = window.VP3_YOUTUBE_PLAYBACK_V1 || {};
  const watchSampleMs = 4000;
  const watchReportSeconds = 12;
  const maxWatchSeconds = 14400;
  const maxPositionMs = 604800000;
  const positions = new Map();
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
      fields:{...(active.playlistId ? {playlist_id:active.playlistId} : {message_id:active.messageId}), video_id:active.video.video_id,
        play_id:window.crypto?.randomUUID?.(), capture_key:captureKey},
      recorded:false, recording:false, recordAttemptAt:-Infinity, watchedMs:0, sentSeconds:0,
      watchAttemptAt:-Infinity, sending:false, flushPending:false, positionSeq:0, lastPosition:null
    };
  }

  function canCapture(active, play) {
    return active.video.made_for_kids === false && play.fields.capture_key
      && play.fields.capture_key === captureKey && play.fields.play_id && (active.messageId || active.playlistId)
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
    play.recordRequest = historyRequest('record', play.fields).then(data => {
      if (data.recorded) {
        play.recorded = true;
        lastRecordedPlayback = Date.now();
        window.dispatchEvent(new Event('vp3:youtube-history-changed'));
      }
    }).catch(() => {}).finally(() => { play.recording = false; });
    rememberHistoryWrite(play.recordRequest);
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

  const validPosition = value => Number.isSafeInteger(value) && value >= 0 && value <= maxPositionMs;

  function reportPosition(active, ended = false) {
    const play = active.play;
    if (!config.resumeCapture || play.positionRejected || !active.hasPlayed || !canCapture(active,play) || !expectedVideo(active)) return;
    let position;
    try { position = ended ? 0 : Math.round(active.player.getCurrentTime()*1000); } catch { return; }
    if (!validPosition(position) || play.lastPosition === position) return;
    play.lastPosition = position;
    positions.set(active.video.video_id,position);
    if (positions.size > 120) positions.delete(positions.keys().next().value);
    const sequence = ++play.positionSeq;
    const persist = () => {
      if (!play.recorded || !canCapture(active,play)) return;
      const write = historyRequest('position_save',{...play.fields,position_ms:position,position_seq:sequence},true)
        .then(data => {
          if (!data.recorded) {
            play.positionRejected = true;
            if (positions.get(active.video.video_id) === position) positions.delete(active.video.video_id);
          }
        }).catch(() => { if (play.positionSeq === sequence) play.lastPosition = null; });
      rememberHistoryWrite(write);
    };
    if (play.recorded) persist();
    else if (play.recordRequest) void play.recordRequest.then(persist);
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
    active.hasPlayed = true;
    capturePlayback(active);
    if ((!config.watchCapture && !config.resumeCapture) || active.watchTimer || !canCapture(active, active.play)) return;
    if (config.watchCapture) sampleWatch(active);
    active.watchTimer = window.setInterval(() => {
      if (!active.panel.isConnected || !canCapture(active, active.play) || document.hidden) {
        stopWatching(active);
        return;
      }
      capturePlayback(active);
      if (config.watchCapture) {
        sampleWatch(active);
        reportWatch(active, active.play);
      }
      reportPosition(active);
    }, watchSampleMs);
  }

  function stopWatching(active, ended = false) {
    if (config.resumeCapture && (!active.ended || ended)) reportPosition(active,ended);
    if (!active.watchTimer) return;
    window.clearInterval(active.watchTimer);
    active.watchTimer = 0;
    if (config.watchCapture) {
      sampleWatch(active, true);
      active.watchSample = null;
      reportWatch(active, active.play, true);
    }
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

  function playVideo(row, button, video, title, messageId, suggestion, {playlistId='',queue=[],index=-1}={}) {
    const videoId = video.video_id;
    if (document.hidden || window.VP3ParticipantContinuity?.selectedContext() || activePlayer?.button === button) return;
    stopPlayer();
    const panel = element('div', 'vp3-youtube-player');
    const frame = element('iframe', 'vp3-youtube-frame');
    frame.title = 'YouTube video · ' + title;
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
    frame.allowFullscreen = true;
    const close = element('button', 'vp3-youtube-close', 'Close player');
    close.type = 'button';
    close.addEventListener('click', () => stopPlayer(true));
    const controls = element('div','vp3-youtube-controls');
    if (queue.length > 1) {
      const previous = element('button','vp3-youtube-close','Previous video');
      previous.type = 'button';
      previous.disabled = index < 1;
      previous.addEventListener('click',() => queue[index-1]?.());
      const next = element('button','vp3-youtube-close','Next video');
      next.type = 'button';
      next.disabled = index >= queue.length-1;
      next.addEventListener('click',() => queue[index+1]?.());
      controls.append(previous,next);
    }
    controls.append(close);
    panel.append(frame,controls);
    row.append(panel);
    button.setAttribute('aria-expanded', 'true');
    const active = {panel, button, video, messageId, suggestion, playlistId, queue, index};
    active.play = newPlay(active);
    activePlayer = active;
    const mount = resume => {
      if (activePlayer !== active || document.hidden || !panel.isConnected) return;
      const params = new URLSearchParams({autoplay:'1',playsinline:'1',enablejsapi:'1',origin:location.origin});
      if (validPosition(resume) && resume > 0) params.set('start',String(Math.floor(resume/1000)));
      frame.src = 'https://www.youtube.com/embed/' + videoId + '?' + params;
      loadPlayerApi().then(api => {
        if (activePlayer !== active || !frame.isConnected) return;
        active.player = new api.Player(frame, {events: {
          onReady(event) {
            if (activePlayer !== active) return;
            active.player = event.target;
            active.ready = true;
            if (document.hidden) { event.target.pauseVideo(); return; }
            try {
              if (validPosition(resume) && resume % 1000 !== 0) event.target.seekTo?.(resume/1000,true);
            } catch {} // The iframe has already received the whole-second start.
            if (event.target.getPlayerState() === api.PlayerState.PLAYING) startWatching(active);
          },
          onStateChange(event) {
            if (activePlayer !== active) return;
            if (event.data === api.PlayerState.PLAYING) {
              if (document.hidden) { event.target.pauseVideo(); return; }
              active.offer?.remove();
              if (active.ended) {
                if (!expectedVideo(active)) return;
                active.ended = false;
                active.play = newPlay(active);
              }
              startWatching(active);
            } else {
              const ended = event.data === api.PlayerState.ENDED;
              stopWatching(active,ended);
              if (ended) {
                active.ended = true;
                if (!document.hidden && queue[index+1]) queue[index+1]();
                else void offerMorePicks(active);
              }
            }
          }
        }});
      }).catch(() => {}); // The native iframe controls remain available.
    };
    const known = positions.get(videoId) ?? video.resume_ms;
    if (!config.resumeCapture || !canCapture(active,active.play) || validPosition(known)) {
      mount(config.resumeCapture && canCapture(active,active.play) ? known : 0);
      return;
    }
    let timer;
    const wait = new Promise(resolve => { timer = setTimeout(() => resolve(0),850); });
    void Promise.race([wait,historyRequest('position',{video_id:videoId,capture_key:captureKey})
      .then(data => data.resume_ms).catch(() => 0)]).then(position => {
        clearTimeout(timer);
        mount(canCapture(active,active.play)?position:0);
      });
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

  function renderSavedPlaylists(target, playlists) {
    if (window.VP3ParticipantContinuity?.selectedContext()) return;
    target.replaceChildren();
    const section = element('section','vp3-youtube-results');
    section.setAttribute('aria-label','Saved YouTube playlists');
    section.dataset.vp3YoutubePrivate = '1';
    section.append(element('strong','vp3-youtube-query','Saved YouTube playlists'));
    const items = Array.isArray(playlists) ? playlists.filter(playlist => playlistIdPattern.test(playlist?.playlist_id)).slice(0,25) : [];
    if (!items.length) section.append(element('p','vp3-youtube-expired','No saved YouTube playlists yet.'));
    const list = element('ul','vp3-youtube-list');
    for (const playlist of items) {
      const row = element('li','vp3-youtube-result');
      const title = typeof playlist.title === 'string' ? playlist.title.slice(0,200) : 'Saved YouTube playlist';
      const open = element('button','vp3-youtube-play',title);
      open.type = 'button';
      open.setAttribute('aria-expanded','false');
      const remove = element('button','vp3-youtube-close','Remove playlist');
      remove.type = 'button';
      const status = element('span','vp3-youtube-channel');
      status.setAttribute('role','status');
      const contents = element('div');
      let generation = 0;
      open.addEventListener('click',async () => {
        const current = ++generation;
        if (open.getAttribute('aria-expanded') === 'true') {
          if (contents.contains(activePlayer?.panel ?? null)) stopPlayer();
          contents.replaceChildren();
          open.setAttribute('aria-expanded','false');
          return;
        }
        open.disabled = true;
        status.textContent = '';
        try {
          const {results} = await historyRequest('playlist_open',{playlist_id:playlist.playlist_id});
          if (!row.isConnected || current !== generation || window.VP3ParticipantContinuity?.selectedContext()) return;
          if (results?.playlist_id !== playlist.playlist_id || !Array.isArray(results.videos) || !results.videos.length) {
            status.textContent = 'These saved videos are unavailable on YouTube.';
            return;
          }
          const anchor = element('div','message-text');
          anchor.hidden = true;
          contents.replaceChildren(anchor);
          open.setAttribute('aria-expanded','true');
          attach(contents,results);
        } catch {
          status.textContent = 'Could not refresh this playlist. Try again.';
        } finally { open.disabled = false; }
      });
      remove.addEventListener('click',async () => {
        remove.disabled = true;
        try {
          await historyRequest('playlist_remove',{playlist_id:playlist.playlist_id});
          ++generation;
          if (activePlayer?.playlistId === playlist.playlist_id) stopPlayer();
          row.remove();
          if (!list.childElementCount) section.append(element('p','vp3-youtube-expired','No saved YouTube playlists yet.'));
          window.dispatchEvent(new Event('vp3:youtube-history-changed'));
        } catch {
          status.textContent = 'Could not remove this playlist.';
          remove.disabled = false;
        }
      });
      if (playlist.available === false) status.textContent = 'The saved video references expired.';
      row.append(open,remove,status,contents);
      list.append(row);
    }
    section.append(list);
    target.append(section);
  }

  function attach(message, result) {
    const messageText = message.querySelector('.message-text');
    if (messageText && result?.saved_playlists === true) {
      const target = element('div');
      message.dataset.vp3YoutubePrivate = '1';
      messageText.after(target);
      renderSavedPlaylists(target,result.playlists);
      return;
    }
    if (!messageText || !Number.isSafeInteger(result?.expires_at) || result.expires_at <= Date.now() / 1000 ||
        !Array.isArray(result.videos)) return;

    const history = result.history === true;
    const savedPlaylist = playlistIdPattern.test(result.playlist_id ?? '') ? result.playlist_id : '';
    const sourceMessageId = Number.isSafeInteger(result.source_message_id) && result.source_message_id > 0
      ? result.source_message_id : null;
    const resultMessageId = () => sourceMessageId ?? message.dataset.chatMessageId;
    if (history) {
      if (window.VP3ParticipantContinuity?.selectedContext()) return;
      message.dataset.vp3YoutubePrivate = '1';
    }
    if (savedPlaylist && window.VP3ParticipantContinuity?.selectedContext()) return;
    const section = element('section', 'vp3-youtube-results');
    const draft = !history && result.suggestion_kind === 'playlist';
    section.setAttribute('aria-label', history ? 'Played in VP3' : (draft ? (savedPlaylist ? 'Saved YouTube playlist' : 'YouTube playlist draft') : 'YouTube search results'));
    const header = element('header', 'vp3-youtube-heading');
    const logo = element('img', 'vp3-youtube-logo');
    logo.src = brandLogo;
    logo.alt = 'YouTube';
    const brand = openLink('vp3-youtube-brand', '', 'https://www.youtube.com/');
    brand.append(logo);
    const query = typeof result.query === 'string' ? result.query.slice(0, 200).trim() : '';
    const title = history ? 'Played in VP3' : (draft && !savedPlaylist ? 'Playlist draft' : (query || 'Video results'));
    header.append(brand, element('strong', 'vp3-youtube-query', title));

    if (!history && !savedPlaylist && config.endpoint) {
      const recent = element('button', 'vp3-youtube-close', 'Recently played');
      recent.type = 'button';
      recent.addEventListener('click', () => window.dispatchEvent(new Event('vp3:youtube-recent')));
      header.append(recent);
    }
    const queue = [];
    let playPlaylist;
    if (draft) {
      const play = element('button','vp3-youtube-close','Play playlist');
      play.type = 'button';
      play.addEventListener('click',() => queue[0]?.());
      header.append(play);
      playPlaylist = play;
      if (config.playlistReady && !savedPlaylist) {
        const savePlaylist = element('button','vp3-youtube-close','Save playlist');
        savePlaylist.type = 'button';
        const status = element('span','vp3-youtube-channel');
        status.setAttribute('role','status');
        savePlaylist.addEventListener('click',async () => {
          if (window.VP3ParticipantContinuity?.selectedContext()) return;
          savePlaylist.disabled = true;
          try {
            await historyRequest('playlist_save',{message_id:resultMessageId() || ''});
            savePlaylist.textContent = 'Playlist saved';
            status.textContent = '';
            window.dispatchEvent(new Event('vp3:youtube-history-changed'));
          } catch {
            status.textContent = 'Could not save this playlist. Try again or remove an older playlist.';
            savePlaylist.disabled = false;
          }
        });
        header.append(savePlaylist,status);
      }
    }
    const savedList = element('div');
    if (config.playlistReady && !savedPlaylist && !history) {
      const saved = element('button','vp3-youtube-close','Saved playlists');
      saved.type = 'button';
      saved.setAttribute('aria-expanded','false');
      saved.addEventListener('click',async () => {
        if (window.VP3ParticipantContinuity?.selectedContext()) return;
        if (saved.getAttribute('aria-expanded') === 'true') {
          if (savedList.contains(activePlayer?.panel ?? null)) stopPlayer();
          savedList.replaceChildren();
          saved.setAttribute('aria-expanded','false');
          return;
        }
        saved.disabled = true;
        try {
          const data = await historyRequest('playlists');
          if (!saved.isConnected) return;
          if (window.VP3ParticipantContinuity?.selectedContext()) return;
          renderSavedPlaylists(savedList,data.playlists);
          saved.setAttribute('aria-expanded','true');
        } catch {
          savedList.replaceChildren(element('p','vp3-youtube-expired','Saved playlists are temporarily unavailable.'));
        } finally { saved.disabled = false; }
      });
      header.append(saved);
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
    let playSelected;
    for (const video of result.videos.slice(0, history ? 12 : draft ? 8 : 6)) {
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
        const index = queue.length;
        const start = () => {
          if (expiry <= Date.now() / 1000) {
            if (section.contains(activePlayer?.panel || null)) stopPlayer();
            section.replaceChildren(element('p', 'vp3-youtube-expired', 'These results expired. Search YouTube again.'));
            return;
          }
          const playlist = savedPlaylist || (history && playlistIdPattern.test(video.playlist_id ?? '') ? video.playlist_id : '');
          const musicOffer = !result.initiative_suggestion && (result.music_suggestion === true
            || (result.music_suggestion !== false && Boolean(result.suggestion_reason && result.taste_topic)));
          playVideo(row,button,video,title || 'YouTube video',history ? video.message_id : resultMessageId(),
            musicOffer,{playlistId:playlist,queue,index});
        };
        if (draft) queue.push(start);
        if (result.selected_video_id === videoId) {
          row.classList.add('vp3-youtube-selected');
          if (result.play_selected === true) playSelected = start;
        }
        button.addEventListener('click',start);
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
      if (canEmbed) {
        const position = config.resumeCapture ? (positions.get(videoId) ?? video.resume_ms) : null;
        const seconds = validPosition(position) ? Math.floor(position/1000) : 0;
        const resume = seconds >= 1 ? 'Resume at '+Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0') : 'Play in Chat';
        if (seconds >= 1) button.setAttribute('aria-label',resume+' · '+(title || 'YouTube video'));
        details.append(element('span', 'vp3-youtube-play-label', resume));
      }
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
            positions.clear();
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
    if (playPlaylist) playPlaylist.disabled = queue.length === 0;
    if (!header.parentNode) section.append(header);
    section.append(list);
    if (config.playlistReady && !savedPlaylist && !history) section.append(savedList);
    messageText.after(section);
    if (playSelected) setTimeout(() => { if (section.isConnected) playSelected(); },0);
  }

  window.addEventListener('stonefellow:chat-context-changing', () => stopPlayer());
  window.addEventListener('vp3:participant-selection-changed', () => stopPlayer());
  window.addEventListener('vp3:chat-canvas-view-changed', () => {
    if (document.getElementById('chatThread')?.dataset.view !== 'chat') stopPlayer();
  });
  window.addEventListener('pagehide', () => stopPlayer());
  document.addEventListener('visibilitychange', () => {
    const active = activePlayer;
    if (!active) return;
    if (document.hidden) {
      if (!active.ready) { stopPlayer(); return; }
      try {
        const state = active.player.getPlayerState();
        active.resumeOnVisible = state === window.YT.PlayerState.PLAYING || state === window.YT.PlayerState.BUFFERING;
        stopWatching(active);
        active.player.pauseVideo();
      } catch { stopPlayer(); }
    } else if (active.resumeOnVisible) {
      active.resumeOnVisible = false;
      try { active.player.playVideo(); } catch {} // The native Play control is still available.
    }
  });

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
    if (activePlayer?.queue.length > 1 && /^(?:play )?(?:the )?next(?: you ?tube)?(?: video)?$/.test(phrase)) return 'next';
    if (config.playlistReady && /^(?:\/youtube playlists|(?:(?:show|open|list)(?: me)? )?(?:my )?saved you ?tube playlists)$/.test(phrase)) return 'playlists';
    if (config.endpoint) {
      if (isHistoryRequest(text)) return 'recent';
      if (Date.now() - lastRecordedPlayback < 30 * 60 * 1000
          && /^what was (?:that|the) (?:song|video) i (?:played|watched|listened to)(?: earlier)?$/.test(phrase)) return 'recent';
    }
    return null;
  }

  async function runCommand(action) {
    if (action === 'playlists') {
      try {
        const data = await historyRequest('playlists');
        return {answer:'Here are your saved YouTube playlists.',results:{saved_playlists:true,playlists:data.playlists}};
      } catch { return {answer:'Saved YouTube playlists are temporarily unavailable.'}; }
    }
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
    if (action === 'next') {
      const next = active.queue[active.index+1];
      if (!next) return {answer:'You’re at the last video in this playlist.'};
      next();
      return {answer:'Playing the next video in this playlist.'};
    }
    if (!active.ready) return {answer:'YouTube pause is unavailable right now. Use the player’s Pause button or say “Stop YouTube” to close it.'};
    try {
      active.resumeOnVisible = false;
      active.player.pauseVideo();
      return {answer:'I asked the YouTube player to pause.'};
    } catch {
      return {answer:'I couldn’t pause YouTube. Try the player’s Pause button.'};
    }
  }

  window.VP3_CHAT_YOUTUBE_V1 = Object.freeze({attach, command, runCommand, settingsUrl:config.connections});
})();
