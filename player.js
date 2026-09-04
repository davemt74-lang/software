(() => {
  const tracks = Array.isArray(window.STONEFELLOW_TRACKS) ? window.STONEFELLOW_TRACKS : [];
  if (!tracks.length) return;

  const analytics = window.STONEFELLOW_PLAYBACK || {};
  const body = document.body;
  const audio = document.getElementById('audio');
  const rows = [...document.querySelectorAll('.track-row')];
  const playerStemStudio = document.getElementById('playerStemStudio');
  const playBtn = document.getElementById('playBtn');
  const heroPlay = document.getElementById('heroPlay');
  const nowTitle = document.getElementById('nowTitle');
  const nowCover = document.getElementById('nowCover');
  const progress = document.getElementById('progress');
  const currentTimeEl = document.getElementById('currentTime');
  const durationEl = document.getElementById('duration');
  const volume = document.getElementById('volume');
  const search = document.getElementById('searchInput');
  const queueList = document.getElementById('queueList');

  let current = 0;
  let shuffle = false;
  let repeat = false;
  let playSessionToken = '';
  let sessionTrackId = 0;
  let lastListenTick = performance.now();
  let trackingBusy = false;

  const fmt = seconds => {
    if (!Number.isFinite(seconds)) return '0:00';
    const minutes = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${minutes}:${secs}`;
  };

  async function trackingRequest(payload, beacon = false) {
    if (!analytics.endpoint || !analytics.csrf) return null;
    const bodyPayload = JSON.stringify({...payload, csrf_token: analytics.csrf});

    if (beacon && navigator.sendBeacon) {
      navigator.sendBeacon(
        analytics.endpoint,
        new Blob([bodyPayload], {type:'application/json'})
      );
      return null;
    }

    try {
      const response = await fetch(analytics.endpoint, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        keepalive:true,
        body:bodyPayload
      });
      return await response.json().catch(() => null);
    } catch (error) {
      return null;
    }
  }

  function listenedDelta() {
    const now = performance.now();
    const seconds = audio.paused ? 0 : Math.max(0, Math.min(20, (now - lastListenTick) / 1000));
    lastListenTick = now;
    return seconds;
  }

  async function startTracking() {
    const track = tracks[current];
    if (!track || sessionTrackId === Number(track.id) && playSessionToken) return;

    const data = await trackingRequest({
      action:'start',
      track_id:Number(track.id),
      position:Number(audio.currentTime || 0),
      duration:Number(audio.duration || 0),
      source:'player'
    });

    if (data && data.ok && data.session_token) {
      playSessionToken = data.session_token;
      sessionTrackId = Number(track.id);
      lastListenTick = performance.now();
    }
  }

  async function trackEvent(action, delta = 0, beacon = false) {
    const track = tracks[current];
    if (!track) return;

    if (!playSessionToken || sessionTrackId !== Number(track.id)) {
      if (action === 'resume' || action === 'heartbeat') {
        await startTracking();
      } else {
        return;
      }
    }

    if (!playSessionToken) return;

    await trackingRequest({
      action,
      track_id:Number(track.id),
      session_token:playSessionToken,
      position:Number(audio.currentTime || 0),
      duration:Number(audio.duration || 0),
      delta:Number(Math.max(0, Math.min(20, delta || 0)))
    }, beacon);
  }

  function closeCurrentTracking(action = 'stop', beacon = false) {
    if (!playSessionToken) return;
    const delta = listenedDelta();
    trackEvent(action, delta, beacon);
    playSessionToken = '';
    sessionTrackId = 0;
  }

  function renderQueue() {
    queueList.innerHTML = '';
    tracks.forEach((track, index) => {
      if (index === current) return;
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'queue-item';
      item.style.border = '0';
      item.style.color = 'inherit';
      item.style.textAlign = 'left';
      item.style.cursor = 'pointer';
      item.innerHTML = `
        <img src="${track.cover}" alt="">
        <div><strong>${escapeHtml(track.title)}</strong><span>${escapeHtml(track.album || 'Stonefellow')}</span></div>
        <div class="queue-time">${escapeHtml(track.duration || '')}</div>`;
      item.addEventListener('click', () => {
        loadTrack(index, true);
        closeQueue();
      });
      queueList.appendChild(item);
    });
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function loadTrack(index, auto = false) {
    if (playSessionToken) closeCurrentTracking('stop', false);

    current = (index + tracks.length) % tracks.length;
    const track = tracks[current];

    rows.forEach((row, rowIndex) => row.classList.toggle('active', rowIndex === current));
    nowTitle.textContent = track.title;
    nowCover.src = track.cover;
    audio.src = track.src;

    if (playerStemStudio) {
      if (track.stem_studio) {
        playerStemStudio.href = track.stem_studio;
        playerStemStudio.hidden = false;
      } else {
        playerStemStudio.hidden = true;
      }
    }
    audio.load();
    progress.value = 0;
    playSessionToken = '';
    sessionTrackId = 0;
    lastListenTick = performance.now();
    renderQueue();

    if (auto) {
      audio.play().catch(() => {
        alert('This track file is not available yet. Upload the audio in the admin Tracks page.');
      });
    }
  }

  function syncPlay() {
    const playing = !audio.paused;
    playBtn.textContent = playing ? '❚❚' : '▶';
    heroPlay.textContent = playing ? '❚❚' : '▶';
  }

  function togglePlay() {
    if (!audio.src) loadTrack(current);
    if (audio.paused) {
      audio.play().catch(() => {
        alert('This track file is not available yet. Upload the audio in the admin Tracks page.');
      });
    } else {
      audio.pause();
    }
  }

  function next() {
    if (shuffle && tracks.length > 1) {
      let nextIndex = current;
      while (nextIndex === current) nextIndex = Math.floor(Math.random() * tracks.length);
      loadTrack(nextIndex, true);
    } else {
      loadTrack(current + 1, true);
    }
  }

  function prev() {
    if (audio.currentTime > 4) {
      audio.currentTime = 0;
      trackEvent('seek', 0);
      return;
    }
    loadTrack(current - 1, true);
  }

  function openQueue() { body.classList.add('queue-open'); }
  function closeQueue() { body.classList.remove('queue-open'); }

  rows.forEach((row, index) => row.addEventListener('click', event => {
    if (!event.target.closest('.track-more,.track-studio')) loadTrack(index, true);
  }));

  playBtn.addEventListener('click', togglePlay);
  heroPlay.addEventListener('click', togglePlay);
  document.getElementById('nextBtn').addEventListener('click', next);
  document.getElementById('prevBtn').addEventListener('click', prev);

  document.getElementById('shuffleBtn').addEventListener('click', event => {
    shuffle = !shuffle;
    event.currentTarget.style.color = shuffle ? '#d9c3a5' : '';
  });

  document.getElementById('repeatBtn').addEventListener('click', event => {
    repeat = !repeat;
    event.currentTarget.style.color = repeat ? '#d9c3a5' : '';
  });

  document.getElementById('heartBtn').addEventListener('click', event => {
    event.currentTarget.textContent = event.currentTarget.textContent === '♡' ? '♥' : '♡';
  });

  audio.addEventListener('play', async () => {
    syncPlay();
    lastListenTick = performance.now();
    if (!playSessionToken) await startTracking();
    else await trackEvent('resume', 0);
  });

  audio.addEventListener('pause', () => {
    syncPlay();
    if (!audio.ended && playSessionToken) {
      const delta = listenedDelta();
      trackEvent('pause', delta);
    }
  });

  audio.addEventListener('loadedmetadata', () => durationEl.textContent = fmt(audio.duration));

  audio.addEventListener('timeupdate', () => {
    if (audio.duration) {
      progress.value = audio.currentTime / audio.duration * 100;
      currentTimeEl.textContent = fmt(audio.currentTime);
    }
  });

  audio.addEventListener('seeking', () => {
    if (!audio.paused && playSessionToken) {
      const delta = listenedDelta();
      trackEvent('heartbeat', delta);
    }
  });

  audio.addEventListener('seeked', () => {
    if (playSessionToken) trackEvent('seek', 0);
    lastListenTick = performance.now();
  });

  audio.addEventListener('ended', () => {
    const delta = listenedDelta();
    trackEvent('ended', delta);
    playSessionToken = '';
    sessionTrackId = 0;

    if (repeat) {
      audio.currentTime = 0;
      audio.play();
    } else {
      next();
    }
  });

  setInterval(() => {
    if (!audio.paused && !audio.ended && playSessionToken && !trackingBusy) {
      trackingBusy = true;
      const delta = listenedDelta();
      trackEvent('heartbeat', delta).finally(() => { trackingBusy = false; });
    }
  }, 10000);

  progress.addEventListener('input', () => {
    if (audio.duration) audio.currentTime = progress.value / 100 * audio.duration;
  });

  volume.addEventListener('input', () => audio.volume = Number(volume.value));
  audio.volume = 0.8;

  ['queueToggle', 'queueBottomBtn', 'mobileQueueBtn'].forEach(id => {
    document.getElementById(id).addEventListener('click', openQueue);
  });

  document.getElementById('closeQueue').addEventListener('click', closeQueue);
  document.getElementById('queueBackdrop').addEventListener('click', closeQueue);

  document.getElementById('mobileMenuBtn').addEventListener('click', () => body.classList.toggle('mobile-nav-open'));
  document.querySelectorAll('#leftNav a').forEach(link => link.addEventListener('click', () => body.classList.remove('mobile-nav-open')));

  search.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase();
    rows.forEach(row => {
      row.style.display = row.dataset.title.toLowerCase().includes(query) ? 'grid' : 'none';
    });
  });

  window.addEventListener('pagehide', () => {
    if (playSessionToken) closeCurrentTracking('stop', true);
  });

  document.addEventListener('visibilitychange', () => {
    if (document.hidden && !audio.paused && playSessionToken) {
      const delta = listenedDelta();
      trackEvent('heartbeat', delta, true);
    } else if (!document.hidden) {
      lastListenTick = performance.now();
    }
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      closeQueue();
      body.classList.remove('mobile-nav-open');
    }
    if (event.code === 'Space' && !['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) {
      event.preventDefault();
      togglePlay();
    }
  });

  loadTrack(0, false);
})();