(() => {
  const cfg = window.STONEFELLOW_STEM_STUDIO;
  if (!cfg || !Array.isArray(cfg.stems) || !cfg.stems.length) return;

  const playButton = document.getElementById('stemPlayButton');
  const timeline = document.getElementById('stemTimeline');
  const currentTimeEl = document.getElementById('stemCurrentTime');
  const masterVolume = document.getElementById('stemMasterVolume');
  const resetMix = document.getElementById('stemResetMix');
  const duration = Number(cfg.duration || 0);

  const AudioContextClass = window.AudioContext || window.webkitAudioContext;
  let context = null;
  let masterGain = null;
  let playing = false;
  let position = 0;
  let startedAt = 0;
  let frame = 0;

  const stems = cfg.stems.map(meta => {
    const row = document.querySelector(`[data-stem-id="${Number(meta.id)}"]`);
    const audio = row?.querySelector('.stem-audio');
    const volume = row?.querySelector('[data-stem-volume]');
    const pan = row?.querySelector('[data-stem-pan]');
    const muteButton = row?.querySelector('[data-stem-mute]');
    const soloButton = row?.querySelector('[data-stem-solo]');

    return {
      ...meta,
      row,
      audio,
      volume,
      pan,
      muteButton,
      soloButton,
      muted:false,
      solo:false,
      gainNode:null,
      panNode:null,
      sourceNode:null,
      pendingPlay:false,
      initialGain:Number(meta.volume || 1),
      initialPan:Number(meta.pan || 0),
      userGain:Number(meta.volume || 1)
    };
  }).filter(stem => stem.audio);

  function formatTime(seconds) {
    seconds = Math.max(0, Number(seconds || 0));
    const minutes = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${minutes}:${secs}`;
  }

  function globalPosition() {
    if (!playing) return position;
    return Math.min(duration, position + (performance.now() - startedAt) / 1000);
  }

  function ensureAudioGraph() {
    if (!AudioContextClass || context) return;

    context = new AudioContextClass();
    masterGain = context.createGain();
    masterGain.gain.value = Number(masterVolume?.value || 1);
    masterGain.connect(context.destination);

    stems.forEach(stem => {
      try {
        stem.sourceNode = context.createMediaElementSource(stem.audio);
        stem.gainNode = context.createGain();

        if (context.createStereoPanner) {
          stem.panNode = context.createStereoPanner();
          stem.panNode.pan.value = Number(stem.pan?.value || stem.pan || 0);
          stem.sourceNode.connect(stem.gainNode);
          stem.gainNode.connect(stem.panNode);
          stem.panNode.connect(masterGain);
        } else {
          stem.sourceNode.connect(stem.gainNode);
          stem.gainNode.connect(masterGain);
        }
      } catch (error) {}
    });

    updateGains();
  }

  function updateGains() {
    const anySolo = stems.some(stem => stem.solo);

    stems.forEach(stem => {
      const audible = !stem.muted && (!anySolo || stem.solo);
      const gain = audible ? Math.max(0, Number(stem.userGain || 0)) : 0;

      if (stem.gainNode) {
        stem.gainNode.gain.setTargetAtTime(
          gain,
          context?.currentTime || 0,
          0.01
        );
      } else {
        stem.audio.volume = Math.max(0, Math.min(1, gain));
        stem.audio.muted = !audible;
      }

      stem.row?.classList.toggle('muted', stem.muted);
      stem.row?.classList.toggle('soloed', stem.solo);
      if (stem.muteButton) stem.muteButton.classList.toggle('active', stem.muted);
      if (stem.soloButton) stem.soloButton.classList.toggle('active', stem.solo);
    });
  }

  function setStemPlayback(stem, globalTime, force = false) {
    const localTime = globalTime - Number(stem.offset || 0);
    const stemDuration = Number(stem.duration || 0);
    const active = localTime >= 0 && localTime < stemDuration;

    if (!active) {
      if (!stem.audio.paused) stem.audio.pause();
      return;
    }

    if (
      force ||
      !Number.isFinite(stem.audio.currentTime) ||
      Math.abs(stem.audio.currentTime - localTime) > 0.10
    ) {
      try {
        stem.audio.currentTime = Math.max(0, Math.min(stemDuration, localTime));
      } catch (error) {}
    }

    if (playing && stem.audio.paused && !stem.pendingPlay) {
      stem.pendingPlay = true;
      stem.audio.play()
        .catch(() => {})
        .finally(() => { stem.pendingPlay = false; });
    }
  }

  function syncAll(force = false) {
    const now = globalPosition();
    stems.forEach(stem => setStemPlayback(stem, now, force));
  }

  function render() {
    const now = globalPosition();

    if (timeline && !timeline.matches(':active')) {
      timeline.value = String(now);
    }
    if (currentTimeEl) currentTimeEl.textContent = formatTime(now);

    if (playing) {
      if (now >= duration) {
        pauseAll();
        position = duration;
        if (timeline) timeline.value = String(duration);
        if (currentTimeEl) currentTimeEl.textContent = formatTime(duration);
        return;
      }

      syncAll(false);
      frame = requestAnimationFrame(render);
    }
  }

  async function playAll() {
    ensureAudioGraph();

    if (context?.state === 'suspended') {
      await context.resume().catch(() => {});
    }

    if (position >= duration - 0.02) position = 0;

    startedAt = performance.now();
    playing = true;
    playButton.textContent = '❚❚';
    playButton.setAttribute('aria-label', 'Pause');

    syncAll(true);
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(render);
  }

  function pauseAll() {
    if (playing) {
      position = globalPosition();
    }

    playing = false;
    stems.forEach(stem => stem.audio.pause());
    cancelAnimationFrame(frame);

    playButton.textContent = '▶';
    playButton.setAttribute('aria-label', 'Play');
  }

  playButton?.addEventListener('click', () => {
    if (playing) pauseAll();
    else playAll();
  });

  timeline?.addEventListener('input', () => {
    position = Math.max(0, Math.min(duration, Number(timeline.value || 0)));
    if (playing) startedAt = performance.now();
    if (currentTimeEl) currentTimeEl.textContent = formatTime(position);
    syncAll(true);
  });

  masterVolume?.addEventListener('input', () => {
    if (masterGain) {
      masterGain.gain.setTargetAtTime(
        Number(masterVolume.value || 1),
        context?.currentTime || 0,
        0.01
      );
    }
  });

  stems.forEach(stem => {
    stem.muteButton?.addEventListener('click', () => {
      stem.muted = !stem.muted;
      updateGains();
    });

    stem.soloButton?.addEventListener('click', () => {
      stem.solo = !stem.solo;
      updateGains();
    });

    stem.volume?.addEventListener('input', () => {
      stem.userGain = Number(stem.volume.value || 0);
      updateGains();
    });

    stem.pan?.addEventListener('input', () => {
      if (stem.panNode) {
        stem.panNode.pan.setTargetAtTime(
          Number(stem.pan.value || 0),
          context?.currentTime || 0,
          0.01
        );
      }
    });
  });

  function applyPreset(name) {
    stems.forEach(stem => {
      const role = String(stem.role || '').toLowerCase();
      stem.solo = false;
      stem.muted = false;

      if (name === 'vocals') {
        stem.solo = role === 'vocal';
      } else if (name === 'instrumental') {
        stem.muted = role === 'vocal';
      } else if (name === 'rhythm') {
        stem.solo = ['drums','percussion','bass'].includes(role);
      }
    });

    updateGains();
  }

  document.querySelectorAll('[data-stem-preset]').forEach(button => {
    button.addEventListener('click', () => applyPreset(button.dataset.stemPreset || 'full'));
  });

  resetMix?.addEventListener('click', () => {
    stems.forEach(stem => {
      stem.muted = false;
      stem.solo = false;
      stem.userGain = Math.max(0, Math.min(1.5, Number(stem.initialGain || 1)));

      if (stem.volume) stem.volume.value = String(stem.userGain);
      if (stem.pan) stem.pan.value = String(Math.max(-1, Math.min(1, Number(stem.initialPan || 0))));
      if (stem.panNode) stem.panNode.pan.value = Math.max(-1, Math.min(1, Number(stem.initialPan || 0)));
    });

    if (masterVolume) masterVolume.value = '1';
    if (masterGain) masterGain.gain.value = 1;
    updateGains();
  });

  document.addEventListener('keydown', event => {
    if (
      event.code === 'Space' &&
      !['INPUT','TEXTAREA','SELECT','BUTTON'].includes(document.activeElement?.tagName)
    ) {
      event.preventDefault();
      if (playing) pauseAll();
      else playAll();
    }
  });

  window.addEventListener('pagehide', pauseAll);
  updateGains();
})();