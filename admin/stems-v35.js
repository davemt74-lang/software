(() => {
  const cfg = window.STONEFELLOW_STEM_STUDIO;
  if (!cfg || !Array.isArray(cfg.stems) || !cfg.stems.length) return;

  const playButton = document.getElementById('stemPlayButton');
  const currentTimeEl = document.getElementById('stemCurrentTime');
  const masterVolume = document.getElementById('stemMasterVolume');
  const masterValue = document.getElementById('stemMasterValue');
  const resetMixButton = document.getElementById('stemResetMix');

  const loopToggleButton = document.getElementById('stemLoopToggle');
  const loopClearButton = document.getElementById('stemLoopClear');

  const dawArrange = document.getElementById('dawArrange');
  const timelineSurface = document.getElementById('dawTimelineSurface');
  const ruler = document.getElementById('dawRuler');
  const rulerLines = document.getElementById('dawRulerLines');
  const playhead = document.getElementById('dawPlayhead');
  const loopSelection = document.getElementById('dawLoopSelection');
  const loopLabel = document.getElementById('dawLoopLabel');

  const trackList = document.getElementById('dawTrackList');
  const arrangeLanes = document.getElementById('dawArrangeLanes');
  const mixerScroll = document.getElementById('dawMixerScroll');

  const masterBusDialog = document.getElementById('masterBusDialog');
  const mixSaveDialog = document.getElementById('mixSaveDialog');
  const savedMixList = document.getElementById('savedMixList');
  const stemMixName = document.getElementById('stemMixName');
  const saveStemMixButton = document.getElementById('saveStemMix');

  const duration = Number(cfg.duration || 0);
  const trackId = Number(cfg.trackId || 0);
  const userId = Number(cfg.userId || 0);

  const localStateKey =
    `stonefellow:stem-studio:state:${userId}:${trackId}`;

  let localPersistenceReady = false;
  let localSaveTimer = 0;
  let selectedStemId = 0;

  const AudioContextClass = window.AudioContext || window.webkitAudioContext;

  let context = null;
  let busInput = null;
  let eqLow = null;
  let eqMid = null;
  let eqHigh = null;
  let compressor = null;
  let masterGain = null;
  let dryGain = null;
  let wetGain = null;
  let reverb = null;

  let playing = false;
  let position = 0;
  let startedAt = 0;
  let frame = 0;

  // Explicit seeks are coordinated. Normal animation frames never repeatedly
  // rewrite media.currentTime; doing that against HTTP range-backed MP3/WAV
  // streams can continuously restart decoders and produce static.
  let seekSerial = 0;
  let seekInProgress = false;

  let draggedStemId = 0;
  let dragSourceElement = null;
  let selectedMixId = 0;

  let loopStart = 0;
  let loopEnd = 0;
  let loopActive = false;

  let selectingLoop = false;
  let selectionPointerId = null;
  let selectionStartTime = 0;
  let selectionStartX = 0;
  let selectionDragged = false;
  let suppressSurfaceClickUntil = 0;

  let syncingVerticalScroll = false;

  const pluginState = {
    eq: true,
    compressor: true,
    reverb: false
  };

  const dbText = gain => {
    const value = Math.max(0, Number(gain || 0));
    if (value <= 0.0001) return '-∞ dB';
    const db = 20 * Math.log10(value);
    return `${db >= 0 ? '+' : ''}${db.toFixed(1)} dB`;
  };

  const panText = value => {
    const pan = Number(value || 0);
    if (Math.abs(pan) < 0.015) return 'C';
    return pan < 0
      ? `L${Math.round(Math.abs(pan) * 100)}`
      : `R${Math.round(pan * 100)}`;
  };

  const formatTime = seconds => {
    const safe = Math.max(0, Number(seconds || 0));
    const minutes = Math.floor(safe / 60);
    const secs = Math.floor(safe % 60).toString().padStart(2, '0');
    return `${minutes}:${secs}`;
  };

  const stems = cfg.stems.map(meta => {
    const leftRow = document.querySelector(
      `[data-stem-id="${Number(meta.id)}"]`
    );
    const mixer = document.querySelector(
      `[data-mixer-stem="${Number(meta.id)}"]`
    );
    const arrangeRow = document.querySelector(
      `[data-arrange-stem="${Number(meta.id)}"]`
    );
    const audio = leftRow?.querySelector('.stem-audio');
    const volume = mixer?.querySelector('[data-stem-volume]');
    const pan = mixer?.querySelector('[data-stem-pan]');
    const volumeOutput = mixer?.querySelector('[data-volume-value]');
    const panOutput = mixer?.querySelector('[data-pan-value]');
    const knob = mixer?.querySelector('[data-pan-knob]');
    const eqBars = [
      ...(mixer?.querySelectorAll('[data-track-eq] span') || [])
    ];

    const muteButtons = [
      ...document.querySelectorAll(
        `[data-stem-id="${Number(meta.id)}"] [data-stem-mute],` +
        `[data-mixer-stem="${Number(meta.id)}"] [data-stem-mute]`
      )
    ];

    const soloButtons = [
      ...document.querySelectorAll(
        `[data-stem-id="${Number(meta.id)}"] [data-stem-solo],` +
        `[data-mixer-stem="${Number(meta.id)}"] [data-stem-solo]`
      )
    ];

    return {
      ...meta,
      id: Number(meta.id),
      leftRow,
      mixer,
      arrangeRow,
      audio,
      volume,
      pan,
      volumeOutput,
      panOutput,
      knob,
      eqBars,
      muteButtons,
      soloButtons,
      muted: false,
      solo: false,
      gainNode: null,
      panNode: null,
      analyserNode: null,
      frequencyData: null,
      sourceNode: null,
      pendingPlay: false,
      initialGain: Math.max(0, Math.min(1.5, Number(meta.volume || 1))),
      initialPan: Math.max(-1, Math.min(1, Number(meta.pan || 0))),
      userGain: Math.max(0, Math.min(1.5, Number(meta.volume || 1)))
    };
  }).filter(stem => stem.audio);

  const stemById = id => stems.find(stem => stem.id === Number(id));

  function localViewState() {
    return {
      arrangeScrollLeft: Number(dawArrange?.scrollLeft || 0),
      arrangeScrollTop: Number(dawArrange?.scrollTop || 0),
      trackScrollTop: Number(trackList?.scrollTop || 0),
      mixerScrollLeft: Number(mixerScroll?.scrollLeft || 0),
      selectedStemId: Number(selectedStemId || 0)
    };
  }

  function saveLocalStateNow() {
    if (!localPersistenceReady) return;

    try {
      localStorage.setItem(
        localStateKey,
        JSON.stringify({
          schemaVersion: 1,
          savedAt: Date.now(),
          trackId,
          userId,
          mix: collectMixState(),
          view: localViewState()
        })
      );
    } catch (error) {
      console.warn(
        'Stem Studio local autosave unavailable.',
        error
      );
    }
  }

  function scheduleLocalSave(delay = 180) {
    if (!localPersistenceReady) return;

    window.clearTimeout(localSaveTimer);
    localSaveTimer = window.setTimeout(
      saveLocalStateNow,
      delay
    );
  }

  function markSelectedStem(stemId) {
    selectedStemId = Number(stemId || 0);

    document.querySelectorAll(
      '.daw-track-row.selected,' +
      '.daw-stem-channel.selected,' +
      '.daw-arrange-row.selected'
    ).forEach(el => el.classList.remove('selected'));

    const stem = stemById(selectedStemId);

    stem?.leftRow?.classList.add('selected');
    stem?.mixer?.classList.add('selected');
    stem?.arrangeRow?.classList.add('selected');
  }

  function restoreLocalState() {
    let payload = null;

    try {
      const raw = localStorage.getItem(localStateKey);

      if (raw) {
        payload = JSON.parse(raw);
      }
    } catch (error) {
      console.warn(
        'Stem Studio local restore unavailable.',
        error
      );
    }

    if (
      payload &&
      typeof payload === 'object' &&
      Number(payload.trackId || 0) === trackId &&
      payload.mix &&
      typeof payload.mix === 'object'
    ) {
      applyMixState(payload.mix);
    }

    const view = (
      payload &&
      typeof payload.view === 'object'
    )
      ? payload.view
      : {};

    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        if (dawArrange) {
          dawArrange.scrollLeft = Math.max(
            0,
            Number(view.arrangeScrollLeft || 0)
          );
          dawArrange.scrollTop = Math.max(
            0,
            Number(
              view.arrangeScrollTop ??
              view.trackScrollTop ??
              0
            )
          );
        }

        if (trackList) {
          trackList.scrollTop = Math.max(
            0,
            Number(
              view.trackScrollTop ??
              view.arrangeScrollTop ??
              0
            )
          );
        }

        if (mixerScroll) {
          mixerScroll.scrollLeft = Math.max(
            0,
            Number(view.mixerScrollLeft || 0)
          );
        }

        const savedStemId = Number(
          view.selectedStemId || 0
        );

        if (savedStemId && stemById(savedStemId)) {
          markSelectedStem(savedStemId);
        }

        localPersistenceReady = true;
      });
    });
  }

  function globalPosition() {
    if (!playing) return position;
    return Math.min(
      duration,
      position + (performance.now() - startedAt) / 1000
    );
  }

  function makeImpulse(ctx, seconds = 1.1, decay = 2.6) {
    const length = Math.max(1, Math.floor(ctx.sampleRate * seconds));
    const buffer = ctx.createBuffer(2, length, ctx.sampleRate);

    for (let channel = 0; channel < 2; channel++) {
      const data = buffer.getChannelData(channel);

      for (let i = 0; i < length; i++) {
        data[i] = (Math.random() * 2 - 1)
          * Math.pow(1 - i / length, decay);
      }
    }

    return buffer;
  }

  function rebuildMasterGraph() {
    if (
      !context ||
      !busInput ||
      !eqLow ||
      !eqMid ||
      !eqHigh ||
      !compressor ||
      !masterGain ||
      !dryGain ||
      !wetGain ||
      !reverb
    ) {
      return;
    }

    [
      busInput,
      eqLow,
      eqMid,
      eqHigh,
      compressor,
      masterGain,
      dryGain,
      wetGain,
      reverb
    ].forEach(node => {
      try {
        node.disconnect();
      } catch (error) {}
    });

    let current = busInput;

    if (pluginState.eq) {
      current.connect(eqLow);
      eqLow.connect(eqMid);
      eqMid.connect(eqHigh);
      current = eqHigh;
    }

    if (pluginState.compressor) {
      current.connect(compressor);
      current = compressor;
    }

    current.connect(masterGain);

    masterGain.connect(dryGain);
    dryGain.connect(context.destination);

    if (pluginState.reverb) {
      masterGain.connect(reverb);
      reverb.connect(wetGain);
      wetGain.gain.value = 0.18;
      wetGain.connect(context.destination);
    } else {
      wetGain.gain.value = 0;
    }
  }

  function ensureAudioGraph() {
    if (!AudioContextClass || context) return;

    context = new AudioContextClass();

    busInput = context.createGain();

    eqLow = context.createBiquadFilter();
    eqLow.type = 'lowshelf';
    eqLow.frequency.value = 140;
    eqLow.gain.value = 0;

    eqMid = context.createBiquadFilter();
    eqMid.type = 'peaking';
    eqMid.frequency.value = 1200;
    eqMid.Q.value = 0.8;
    eqMid.gain.value = 0;

    eqHigh = context.createBiquadFilter();
    eqHigh.type = 'highshelf';
    eqHigh.frequency.value = 5200;
    eqHigh.gain.value = 0;

    compressor = context.createDynamicsCompressor();
    compressor.threshold.value = -18;
    compressor.knee.value = 14;
    compressor.ratio.value = 2;
    compressor.attack.value = 0.012;
    compressor.release.value = 0.24;

    masterGain = context.createGain();
    masterGain.gain.value = Number(masterVolume?.value || 1);

    dryGain = context.createGain();
    wetGain = context.createGain();

    reverb = context.createConvolver();
    reverb.buffer = makeImpulse(context);

    stems.forEach(stem => {
      try {
        stem.sourceNode = context.createMediaElementSource(stem.audio);
        stem.gainNode = context.createGain();

        stem.analyserNode = context.createAnalyser();
        stem.analyserNode.fftSize = 256;
        stem.analyserNode.smoothingTimeConstant = 0.76;
        stem.frequencyData = new Uint8Array(
          stem.analyserNode.frequencyBinCount
        );

        stem.sourceNode.connect(stem.gainNode);

        if (context.createStereoPanner) {
          stem.panNode = context.createStereoPanner();
          stem.panNode.pan.value = Number(stem.pan?.value || 0);

          stem.gainNode.connect(stem.panNode);
          stem.panNode.connect(stem.analyserNode);
        } else {
          stem.gainNode.connect(stem.analyserNode);
        }

        stem.analyserNode.connect(busInput);
      } catch (error) {
        console.error('Stem audio graph failed', stem.id, error);
      }
    });

    rebuildMasterGraph();
    updateGains();
  }

  function updateEqDisplays(reset = false) {
    stems.forEach(stem => {
      if (!stem.eqBars.length) return;

      if (
        reset ||
        !stem.analyserNode ||
        !stem.frequencyData
      ) {
        stem.eqBars.forEach((bar, index) => {
          bar.style.setProperty('--eq-level', `${3 + index * 0.2}%`);
        });
        return;
      }

      stem.analyserNode.getByteFrequencyData(stem.frequencyData);

      const data = stem.frequencyData;
      const bands = [
        [1, 3],
        [3, 6],
        [6, 11],
        [11, 19],
        [19, 31],
        [31, 48],
        [48, 76],
        [76, Math.min(127, data.length)]
      ];

      stem.eqBars.forEach((bar, index) => {
        const [from, to] = bands[index] || [0, data.length];
        let sum = 0;
        let count = 0;

        for (let i = from; i < to && i < data.length; i++) {
          sum += data[i];
          count++;
        }

        const average = count ? sum / count : 0;
        const normalized = Math.max(
          0.03,
          Math.min(1, Math.pow(average / 210, 0.82))
        );

        bar.style.setProperty(
          '--eq-level',
          `${(normalized * 100).toFixed(1)}%`
        );
      });
    });
  }

  function updateKnob(stem) {
    const value = Math.max(
      -1,
      Math.min(1, Number(stem.pan?.value || 0))
    );
    const degrees = -135 + ((value + 1) / 2) * 270;

    stem.knob?.style.setProperty('--knob-angle', `${degrees}deg`);
    stem.knob?.setAttribute('aria-valuenow', value.toFixed(2));

    if (stem.panOutput) {
      stem.panOutput.textContent = panText(value);
    }
  }

  function setStemPan(stem, value) {
    const pan = Math.max(-1, Math.min(1, Number(value || 0)));

    if (stem.pan) {
      stem.pan.value = String(pan);
    }

    if (stem.panNode && context) {
      stem.panNode.pan.setTargetAtTime(
        pan,
        context.currentTime,
        0.008
      );
    }

    updateKnob(stem);
    scheduleLocalSave();
  }

  function updateReadouts(stem) {
    if (stem.volumeOutput) {
      stem.volumeOutput.textContent = dbText(stem.userGain);
    }

    updateKnob(stem);
  }

  function updateGains() {
    const anySolo = stems.some(stem => stem.solo);

    stems.forEach(stem => {
      const audible = !stem.muted && (!anySolo || stem.solo);
      const gain = audible
        ? Math.max(0, Number(stem.userGain || 0))
        : 0;

      if (stem.gainNode && context) {
        stem.gainNode.gain.setTargetAtTime(
          gain,
          context.currentTime,
          0.008
        );
      } else {
        stem.audio.volume = Math.max(0, Math.min(1, gain));
        stem.audio.muted = !audible;
      }

      [stem.leftRow, stem.mixer, stem.arrangeRow].forEach(el => {
        el?.classList.toggle('muted', stem.muted);
        el?.classList.toggle('soloed', stem.solo);
      });

      stem.muteButtons.forEach(button => {
        button.classList.toggle('active', stem.muted);
      });

      stem.soloButtons.forEach(button => {
        button.classList.toggle('active', stem.solo);
      });

      updateReadouts(stem);
    });

    scheduleLocalSave();
  }

  function stemLocalTime(stem, globalTime) {
    return globalTime - Number(stem.offset || 0);
  }

  function stemIsActiveAt(stem, globalTime) {
    const localTime = stemLocalTime(stem, globalTime);
    const stemDuration = Number(stem.duration || 0);

    return (
      localTime >= 0 &&
      localTime < stemDuration
    );
  }

  function setStemPlayback(stem, globalTime) {
    const active = stemIsActiveAt(stem, globalTime);

    if (!active) {
      if (!stem.audio.paused) {
        stem.audio.pause();
      }
      return;
    }

    // During ordinary playback, currentTime is intentionally NOT rewritten.
    // The media element keeps decoding continuously from its last explicit
    // seek, which avoids range-request churn and decoder noise.
    if (
      playing &&
      !seekInProgress &&
      stem.audio.paused &&
      !stem.pendingPlay
    ) {
      stem.pendingPlay = true;

      stem.audio.play()
        .catch(error => {
          console.error(
            'Stem playback failed',
            stem.id,
            error
          );
        })
        .finally(() => {
          stem.pendingPlay = false;
        });
    }
  }

  function syncAll() {
    const now = globalPosition();
    stems.forEach(stem => setStemPlayback(stem, now));
  }

  function waitForMetadata(audio, timeoutMs = 1800) {
    if (audio.readyState >= 1) {
      return Promise.resolve();
    }

    return new Promise(resolve => {
      let done = false;

      const finish = () => {
        if (done) return;
        done = true;

        audio.removeEventListener(
          'loadedmetadata',
          finish
        );
        audio.removeEventListener(
          'durationchange',
          finish
        );
        audio.removeEventListener(
          'error',
          finish
        );

        resolve();
      };

      audio.addEventListener(
        'loadedmetadata',
        finish,
        {once:true}
      );
      audio.addEventListener(
        'durationchange',
        finish,
        {once:true}
      );
      audio.addEventListener(
        'error',
        finish,
        {once:true}
      );

      setTimeout(finish, timeoutMs);

      try {
        audio.load();
      } catch (error) {}
    });
  }

  function waitForSeek(audio, target, timeoutMs = 1800) {
    if (
      Number.isFinite(audio.currentTime) &&
      Math.abs(audio.currentTime - target) < 0.04 &&
      !audio.seeking
    ) {
      return Promise.resolve();
    }

    return new Promise(resolve => {
      let done = false;

      const finish = () => {
        if (done) return;
        done = true;

        audio.removeEventListener(
          'seeked',
          finish
        );
        audio.removeEventListener(
          'canplay',
          finish
        );
        audio.removeEventListener(
          'error',
          finish
        );

        resolve();
      };

      audio.addEventListener(
        'seeked',
        finish,
        {once:true}
      );
      audio.addEventListener(
        'canplay',
        finish,
        {once:true}
      );
      audio.addEventListener(
        'error',
        finish,
        {once:true}
      );

      setTimeout(finish, timeoutMs);

      try {
        audio.currentTime = target;
      } catch (error) {
        finish();
      }
    });
  }

  async function seekStemSafely(
    stem,
    globalTime,
    serial
  ) {
    if (serial !== seekSerial) return;

    const localTime = stemLocalTime(
      stem,
      globalTime
    );
    const stemDuration = Number(
      stem.duration || 0
    );

    stem.pendingPlay = false;

    if (!stem.audio.paused) {
      stem.audio.pause();
    }

    if (
      localTime < 0 ||
      localTime >= stemDuration
    ) {
      return;
    }

    await waitForMetadata(stem.audio);

    if (serial !== seekSerial) return;

    const mediaDuration = Number.isFinite(
      stem.audio.duration
    )
      ? stem.audio.duration
      : stemDuration;

    const target = Math.max(
      0,
      Math.min(
        Math.max(0, mediaDuration - 0.01),
        localTime
      )
    );

    await waitForSeek(
      stem.audio,
      target
    );
  }

  async function seekAllSafely(
    globalTime,
    resumeAfter = false
  ) {
    const serial = ++seekSerial;
    const target = Math.max(
      0,
      Math.min(
        duration,
        Number(globalTime || 0)
      )
    );

    seekInProgress = true;
    cancelAnimationFrame(frame);

    stems.forEach(stem => {
      stem.pendingPlay = false;

      if (!stem.audio.paused) {
        stem.audio.pause();
      }
    });

    position = target;

    if (currentTimeEl) {
      currentTimeEl.textContent =
        formatTime(position);
    }

    updatePlayhead(position);

    // Let the browser issue the required range requests together, but wait for
    // each decoder to confirm the new position before restarting playback.
    await Promise.allSettled(
      stems.map(stem =>
        seekStemSafely(
          stem,
          position,
          serial
        )
      )
    );

    if (serial !== seekSerial) {
      return;
    }

    seekInProgress = false;

    if (resumeAfter) {
      startedAt = performance.now();
      playing = true;

      stems.forEach(stem => {
        setStemPlayback(
          stem,
          position
        );
      });

      if (playButton) {
        playButton.textContent = '❚❚';
        playButton.setAttribute(
          'aria-label',
          'Pause'
        );
      }

      frame = requestAnimationFrame(render);
    } else {
      playing = false;

      if (playButton) {
        playButton.textContent = '▶';
        playButton.setAttribute(
          'aria-label',
          'Play'
        );
      }
    }
  }

  function updatePlayhead(now) {
    const percent = duration > 0
      ? Math.max(
          0,
          Math.min(
            100,
            (now / duration) * 100
          )
        )
      : 0;

    if (playhead) {
      playhead.style.left = `${percent}%`;
    }
  }

  function seekTo(time) {
    const resumeAfter = playing;

    // Invalidate the running clock immediately so it cannot advance while the
    // media elements are repositioning.
    playing = false;

    return seekAllSafely(
      time,
      resumeAfter
    );
  }

  function render() {
    if (seekInProgress) {
      return;
    }

    let now = globalPosition();

    if (
      playing &&
      loopActive &&
      loopEnd > loopStart &&
      now >= loopEnd
    ) {
      const resumeAfter = true;
      playing = false;

      seekAllSafely(
        loopStart,
        resumeAfter
      ).catch(error => {
        console.error(
          'Loop seek failed',
          error
        );
      });

      return;
    }

    if (currentTimeEl) {
      currentTimeEl.textContent =
        formatTime(now);
    }

    updatePlayhead(now);
    updateEqDisplays(false);

    if (!playing) {
      return;
    }

    if (now >= duration) {
      pauseAll();
      position = duration;

      if (currentTimeEl) {
        currentTimeEl.textContent =
          formatTime(duration);
      }

      updatePlayhead(duration);
      return;
    }

    syncAll();
    frame = requestAnimationFrame(render);
  }

  async function playAll() {
    ensureAudioGraph();

    if (!context) {
      alert(
        'Web Audio is not available in this browser.'
      );
      return;
    }

    if (context.state === 'suspended') {
      await context.resume();
    }

    if (
      loopActive &&
      loopEnd > loopStart &&
      (
        position < loopStart ||
        position >= loopEnd
      )
    ) {
      position = loopStart;
    } else if (
      position >= duration - 0.02
    ) {
      position =
        loopActive &&
        loopEnd > loopStart
          ? loopStart
          : 0;
    }

    // Start from one confirmed decoder position rather than asking every
    // animation frame to correct currentTime.
    playing = false;

    await seekAllSafely(
      position,
      true
    );
  }

  function pauseAll() {
    if (playing) {
      position = globalPosition();
    }

    ++seekSerial;
    seekInProgress = false;
    playing = false;

    stems.forEach(stem => {
      stem.audio.pause();
    });

    cancelAnimationFrame(frame);
    updateEqDisplays(true);

    if (playButton) {
      playButton.textContent = '▶';
      playButton.setAttribute('aria-label', 'Play');
    }

    updatePlayhead(position);
  }

  function selectStem(stem) {
    markSelectedStem(stem.id);

    stem.mixer?.scrollIntoView({
      block: 'nearest',
      inline: 'center',
      behavior: 'smooth'
    });

    scheduleLocalSave();
  }

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
        stem.solo = ['drums', 'percussion', 'bass'].includes(role);
      }
    });

    updateGains();
  }

  function setPluginState(name, enabled) {
    if (!(name in pluginState)) return;

    pluginState[name] = Boolean(enabled);

    document.querySelectorAll(
      `[data-master-plugin="${name}"]`
    ).forEach(button => {
      button.classList.toggle('active', pluginState[name]);
    });

    if (context) {
      rebuildMasterGraph();
    }

    scheduleLocalSave();
  }

  function resetMix() {
    stems.forEach(stem => {
      stem.muted = false;
      stem.solo = false;
      stem.userGain = stem.initialGain;

      if (stem.volume) {
        stem.volume.value = String(stem.userGain);
      }

      setStemPan(stem, stem.initialPan);
    });

    if (masterVolume) {
      masterVolume.value = '1';
    }

    if (masterValue) {
      masterValue.textContent = '0.0 dB';
    }

    if (masterGain) {
      masterGain.gain.value = 1;
    }

    setPluginState('eq', true);
    setPluginState('compressor', true);
    setPluginState('reverb', false);

    updateGains();
  }

  // ---------------------------------------------------------
  // Main arrange timeline: scroll, seek, highlight, repeat.
  // ---------------------------------------------------------
  function resizeTimelineSurface() {
    if (!timelineSurface || !dawArrange) return;

    const viewport = Math.max(1, dawArrange.clientWidth);
    const pixelsPerSecond = duration > 240 ? 10 : 22;
    const target = Math.max(
      viewport,
      1200,
      duration * pixelsPerSecond
    );

    timelineSurface.style.width = `${Math.ceil(target)}px`;
    renderRuler();
  }

  function renderRuler() {
    if (!rulerLines || duration <= 0) return;

    const interval = duration > 600
      ? 60
      : duration > 240
        ? 30
        : duration > 120
          ? 15
          : 10;

    const markers = [];

    for (let second = 0; second <= duration; second += interval) {
      const left = (second / duration) * 100;

      markers.push(
        `<span class="daw-ruler-marker" style="left:${left}%">` +
        `<b>${formatTime(second)}</b></span>`
      );
    }

    rulerLines.innerHTML = markers.join('');
  }

  function timelineTimeFromPointer(event) {
    if (!timelineSurface || duration <= 0) return 0;

    const rect = timelineSurface.getBoundingClientRect();
    const x = Math.max(
      0,
      Math.min(rect.width, event.clientX - rect.left)
    );

    return (x / Math.max(1, rect.width)) * duration;
  }

  function updateLoopOverlay(start = loopStart, end = loopEnd, visible = null) {
    if (!loopSelection || duration <= 0) return;

    const hasRange = end > start;
    const shouldShow = visible === null ? hasRange : visible;

    loopSelection.hidden = !shouldShow;

    if (!shouldShow) return;

    const left = Math.max(0, Math.min(100, (start / duration) * 100));
    const right = Math.max(0, Math.min(100, (end / duration) * 100));

    loopSelection.style.left = `${left}%`;
    loopSelection.style.width = `${Math.max(0.05, right - left)}%`;

    if (loopLabel) {
      loopLabel.textContent = `${formatTime(start)} – ${formatTime(end)}`;
    }
  }

  function updateLoopButtons() {
    const hasRange = loopEnd > loopStart;

    if (loopToggleButton) {
      loopToggleButton.disabled = !hasRange;
      loopToggleButton.textContent = loopActive
        ? 'Loop: On'
        : 'Loop: Off';
      loopToggleButton.classList.toggle('active', loopActive);
    }

    if (loopClearButton) {
      loopClearButton.hidden = !hasRange;
    }
  }

  function setLoopRange(start, end, active = true) {
    loopStart = Math.max(0, Math.min(duration, Math.min(start, end)));
    loopEnd = Math.max(
      loopStart,
      Math.min(duration, Math.max(start, end))
    );

    if (loopEnd - loopStart < 0.15) {
      loopStart = 0;
      loopEnd = 0;
      loopActive = false;
    } else {
      loopActive = Boolean(active);
    }

    updateLoopOverlay();
    updateLoopButtons();
    scheduleLocalSave();
  }

  function clearLoop() {
    loopStart = 0;
    loopEnd = 0;
    loopActive = false;
    updateLoopOverlay(0, 0, false);
    updateLoopButtons();
    scheduleLocalSave();
  }

  ruler?.addEventListener('pointerdown', event => {
    if (event.button !== 0) return;

    selectionPointerId = event.pointerId;
    selectionStartTime = timelineTimeFromPointer(event);
    selectionStartX = event.clientX;
    selectionDragged = false;
    selectingLoop = true;

    ruler.setPointerCapture(selectionPointerId);
    event.preventDefault();
  });

  ruler?.addEventListener('pointermove', event => {
    if (
      !selectingLoop ||
      event.pointerId !== selectionPointerId
    ) {
      return;
    }

    const current = timelineTimeFromPointer(event);
    const moved = Math.abs(event.clientX - selectionStartX);

    if (moved > 5) {
      selectionDragged = true;

      updateLoopOverlay(
        Math.min(selectionStartTime, current),
        Math.max(selectionStartTime, current),
        true
      );
    }
  });

  function finishTimelineSelection(event) {
    if (
      !selectingLoop ||
      event.pointerId !== selectionPointerId
    ) {
      return;
    }

    const current = timelineTimeFromPointer(event);

    try {
      ruler?.releasePointerCapture(selectionPointerId);
    } catch (error) {}

    selectingLoop = false;
    selectionPointerId = null;
    suppressSurfaceClickUntil = performance.now() + 80;

    if (selectionDragged) {
      const start = Math.min(selectionStartTime, current);
      const end = Math.max(selectionStartTime, current);

      setLoopRange(start, end, true);
      seekTo(start).catch(error => {
        console.error('Timeline seek failed', error);
      });
    } else {
      seekTo(current).catch(error => {
        console.error('Timeline seek failed', error);
      });
    }

    selectionDragged = false;
  }

  ruler?.addEventListener('pointerup', finishTimelineSelection);
  ruler?.addEventListener('pointercancel', event => {
    if (event.pointerId !== selectionPointerId) return;

    selectingLoop = false;
    selectionPointerId = null;
    selectionDragged = false;
    updateLoopOverlay();
  });

  timelineSurface?.addEventListener('click', event => {
    if (performance.now() < suppressSurfaceClickUntil) return;
    if (event.target.closest('.daw-ruler')) return;

    const trackArea = event.target.closest(
      '.daw-arrange-track,.daw-clip'
    );

    if (trackArea) {
      seekTo(
        timelineTimeFromPointer(event)
      ).catch(error => {
        console.error('Timeline seek failed', error);
      });
    }
  });

  loopToggleButton?.addEventListener('click', () => {
    if (loopEnd <= loopStart) return;

    loopActive = !loopActive;
    updateLoopButtons();
    scheduleLocalSave();
  });

  loopClearButton?.addEventListener('click', clearLoop);

  // Native trackpads already emit deltaX. This explicitly preserves
  // two-finger left/right scrolling while keeping song playback independent.
  dawArrange?.addEventListener(
    'wheel',
    event => {
      if (Math.abs(event.deltaX) > 0.1) {
        event.preventDefault();
        dawArrange.scrollLeft += event.deltaX;
      } else if (event.shiftKey && Math.abs(event.deltaY) > 0.1) {
        event.preventDefault();
        dawArrange.scrollLeft += event.deltaY;
      }
    },
    {passive: false}
  );

  function syncVerticalScroll(source, target) {
    if (!source || !target || syncingVerticalScroll) return;

    syncingVerticalScroll = true;
    target.scrollTop = source.scrollTop;

    requestAnimationFrame(() => {
      syncingVerticalScroll = false;
    });
  }

  dawArrange?.addEventListener('scroll', () => {
    syncVerticalScroll(dawArrange, trackList);
    scheduleLocalSave(260);
  });

  trackList?.addEventListener('scroll', () => {
    syncVerticalScroll(trackList, dawArrange);
    scheduleLocalSave(260);
  });

  mixerScroll?.addEventListener('scroll', () => {
    scheduleLocalSave(260);
  });

  window.addEventListener('resize', resizeTimelineSurface);

  // ---------------------------------------------------------
  // Handle-only drag/drop ordering.
  // ---------------------------------------------------------
  function currentOrder() {
    return stems.map(stem => stem.id);
  }

  function applyOrder(order) {
    const normalized = [];

    order.forEach(id => {
      const stem = stemById(id);

      if (stem && !normalized.includes(stem.id)) {
        normalized.push(stem.id);
      }
    });

    stems.forEach(stem => {
      if (!normalized.includes(stem.id)) {
        normalized.push(stem.id);
      }
    });

    const sorted = normalized
      .map(id => stemById(id))
      .filter(Boolean);

    stems.splice(0, stems.length, ...sorted);

    stems.forEach((stem, index) => {
      if (trackList && stem.leftRow) {
        trackList.appendChild(stem.leftRow);
      }

      if (arrangeLanes && stem.arrangeRow) {
        arrangeLanes.appendChild(stem.arrangeRow);
      }

      if (mixerScroll && stem.mixer) {
        mixerScroll.appendChild(stem.mixer);
      }

      const number = stem.mixer?.querySelector('.daw-channel-number');

      if (number) {
        number.textContent = String(index + 1).padStart(2, '0');
      }
    });

    scheduleLocalSave();
  }

  function moveStemBefore(sourceId, targetId) {
    if (sourceId === targetId) return;

    const order = currentOrder().filter(id => id !== sourceId);
    const targetIndex = order.indexOf(targetId);

    if (targetIndex < 0) {
      order.push(sourceId);
    } else {
      order.splice(targetIndex, 0, sourceId);
    }

    applyOrder(order);
  }

  function bindDragHandle(handle) {
    const parent = handle?.closest('[data-drag-stem]');
    if (!handle || !parent) return;

    handle.addEventListener('dragstart', event => {
      draggedStemId = Number(parent.dataset.dragStem || 0);
      dragSourceElement = parent;

      parent.classList.add('dragging');

      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData(
        'text/plain',
        String(draggedStemId)
      );
    });

    handle.addEventListener('dragend', () => {
      draggedStemId = 0;

      dragSourceElement?.classList.remove('dragging');
      dragSourceElement = null;

      document.querySelectorAll('.drag-over')
        .forEach(el => el.classList.remove('drag-over'));
    });
  }

  function bindDropZone(element) {
    if (!element) return;

    element.addEventListener('dragover', event => {
      if (!draggedStemId) return;

      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      element.classList.add('drag-over');
    });

    element.addEventListener('dragleave', () => {
      element.classList.remove('drag-over');
    });

    element.addEventListener('drop', event => {
      if (!draggedStemId) return;

      event.preventDefault();
      element.classList.remove('drag-over');

      const sourceId = Number(
        event.dataTransfer.getData('text/plain') ||
        draggedStemId
      );
      const targetId = Number(element.dataset.dragStem || 0);

      if (sourceId && targetId) {
        moveStemBefore(sourceId, targetId);
      }
    });
  }

  document.querySelectorAll('[data-drag-handle]')
    .forEach(bindDragHandle);

  document.querySelectorAll('[data-drag-stem]')
    .forEach(bindDropZone);

  // ---------------------------------------------------------
  // Stable pan knobs and independent volume faders.
  // ---------------------------------------------------------
  stems.forEach(stem => {
    const knob = stem.knob;

    if (knob) {
      let dragStartY = 0;
      let dragStartValue = 0;
      let pointerId = null;

      knob.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;

        pointerId = event.pointerId;
        dragStartY = event.clientY;
        dragStartValue = Number(stem.pan?.value || 0);

        knob.setPointerCapture(pointerId);
        knob.classList.add('dragging-knob');
        event.preventDefault();
        event.stopPropagation();
      });

      knob.addEventListener('pointermove', event => {
        if (pointerId !== event.pointerId) return;

        const delta = (dragStartY - event.clientY) / 85;
        setStemPan(stem, dragStartValue + delta);
      });

      const finishPanDrag = event => {
        if (pointerId !== event.pointerId) return;

        try {
          knob.releasePointerCapture(pointerId);
        } catch (error) {}

        pointerId = null;
        knob.classList.remove('dragging-knob');
      };

      knob.addEventListener('pointerup', finishPanDrag);
      knob.addEventListener('pointercancel', finishPanDrag);

      knob.addEventListener('dblclick', event => {
        event.preventDefault();
        event.stopPropagation();
        setStemPan(stem, 0);
      });

      knob.addEventListener('keydown', event => {
        const current = Number(stem.pan?.value || 0);
        const step = event.shiftKey ? 0.1 : 0.02;

        if (
          event.key === 'ArrowLeft' ||
          event.key === 'ArrowDown'
        ) {
          event.preventDefault();
          setStemPan(stem, current - step);
        } else if (
          event.key === 'ArrowRight' ||
          event.key === 'ArrowUp'
        ) {
          event.preventDefault();
          setStemPan(stem, current + step);
        } else if (
          event.key === 'Home' ||
          event.key === '0'
        ) {
          event.preventDefault();
          setStemPan(stem, 0);
        }
      });
    }

    stem.volume?.addEventListener('pointerdown', event => {
      event.stopPropagation();
    });

    stem.volume?.addEventListener('dragstart', event => {
      event.preventDefault();
    });
  });

  // ---------------------------------------------------------
  // Saved supervisor custom mixes.
  // ---------------------------------------------------------
  async function mixRequest(action, extra = {}) {
    if (!cfg.canSaveMix || !cfg.mixEndpoint) {
      throw new Error(
        'Saved mixes are not available for this account.'
      );
    }

    const response = await fetch(cfg.mixEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        action,
        track_id: trackId,
        csrf_token: cfg.csrf,
        ...extra
      })
    });

    const data = await response.json().catch(() => null);

    if (!response.ok || !data?.ok) {
      throw new Error(
        data?.error ||
        'Saved mix request failed.'
      );
    }

    return data;
  }

  function collectMixState() {
    const state = {
      masterVolume: Number(masterVolume?.value || 1),
      plugins: {...pluginState},
      loop: {
        start: loopStart,
        end: loopEnd,
        active: loopActive
      },
      order: currentOrder(),
      stems: {}
    };

    stems.forEach(stem => {
      state.stems[String(stem.id)] = {
        volume: Number(stem.userGain || 0),
        pan: Number(stem.pan?.value || 0),
        muted: Boolean(stem.muted),
        solo: Boolean(stem.solo)
      };
    });

    return state;
  }

  function applyMixState(state) {
    if (!state || typeof state !== 'object') return;

    if (Array.isArray(state.order)) {
      applyOrder(state.order.map(Number));
    }

    stems.forEach(stem => {
      const mix = state.stems?.[String(stem.id)];
      if (!mix) return;

      stem.userGain = Math.max(
        0,
        Math.min(
          1.5,
          Number(mix.volume ?? stem.userGain)
        )
      );

      stem.muted = Boolean(mix.muted);
      stem.solo = Boolean(mix.solo);

      if (stem.volume) {
        stem.volume.value = String(stem.userGain);
      }

      setStemPan(stem, Number(mix.pan ?? 0));
    });

    const master = Math.max(
      0,
      Math.min(
        1.5,
        Number(state.masterVolume ?? 1)
      )
    );

    if (masterVolume) {
      masterVolume.value = String(master);
    }

    if (masterValue) {
      masterValue.textContent = dbText(master);
    }

    if (masterGain && context) {
      masterGain.gain.setTargetAtTime(
        master,
        context.currentTime,
        0.008
      );
    }

    Object.entries(state.plugins || {})
      .forEach(([name, enabled]) => {
        setPluginState(name, Boolean(enabled));
      });

    if (
      state.loop &&
      Number(state.loop.end || 0) >
      Number(state.loop.start || 0)
    ) {
      setLoopRange(
        Number(state.loop.start || 0),
        Number(state.loop.end || 0),
        Boolean(state.loop.active)
      );
    } else {
      clearLoop();
    }

    updateGains();
  }

  async function refreshMixList() {
    if (!savedMixList || !cfg.canSaveMix) return;

    savedMixList.innerHTML =
      '<p class="daw-modal-empty">Loading saved mixes…</p>';

    try {
      const data = await mixRequest('list');
      const mixes = Array.isArray(data.mixes)
        ? data.mixes
        : [];

      if (!mixes.length) {
        savedMixList.innerHTML =
          '<p class="daw-modal-empty">No saved mixes yet.</p>';

        selectedMixId = 0;
        return;
      }

      savedMixList.innerHTML = mixes.map(mix => `
        <article class="daw-saved-mix-row" data-mix-id="${Number(mix.id)}">
          <button type="button" data-load-mix="${Number(mix.id)}">
            <strong>${escapeHtml(mix.mix_name || 'My Mix')}</strong>
            <small>${escapeHtml(mix.updated_at || '')}</small>
          </button>
          <button
            type="button"
            class="daw-mix-delete"
            data-delete-mix="${Number(mix.id)}"
          >×</button>
        </article>
      `).join('');

      savedMixList
        .querySelectorAll('[data-load-mix]')
        .forEach(button => {
          button.addEventListener('click', async () => {
            try {
              const data = await mixRequest('load', {
                mix_id: Number(
                  button.dataset.loadMix || 0
                )
              });

              selectedMixId = Number(data.mix?.id || 0);

              if (stemMixName) {
                stemMixName.value = String(
                  data.mix?.mix_name ||
                  'My Mix'
                );
              }

              applyMixState(data.mix?.state || {});
              closeModal(mixSaveDialog);
              scheduleLocalSave(0);
            } catch (error) {
              alert(error.message);
            }
          });
        });

      savedMixList
        .querySelectorAll('[data-delete-mix]')
        .forEach(button => {
          button.addEventListener('click', async () => {
            const mixId = Number(
              button.dataset.deleteMix || 0
            );

            if (!confirm('Delete this saved mix?')) {
              return;
            }

            try {
              await mixRequest('delete', {
                mix_id: mixId
              });

              if (selectedMixId === mixId) {
                selectedMixId = 0;
              }

              await refreshMixList();
            } catch (error) {
              alert(error.message);
            }
          });
        });
    } catch (error) {
      savedMixList.innerHTML =
        `<p class="daw-modal-empty">${escapeHtml(error.message)}</p>`;
    }
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function openModal(modal) {
    if (!modal) return;

    modal.hidden = false;
    document.body.classList.add('daw-modal-open');
  }

  function closeModal(modal) {
    if (!modal) return;

    modal.hidden = true;

    if (
      masterBusDialog?.hidden !== false &&
      mixSaveDialog?.hidden !== false
    ) {
      document.body.classList.remove('daw-modal-open');
    }
  }

  // ---------------------------------------------------------
  // Main controls.
  // ---------------------------------------------------------
  playButton?.addEventListener('click', () => {
    if (playing) {
      pauseAll();
    } else {
      playAll().catch(error => {
        console.error(error);
        alert('Could not start Stem Studio playback.');
      });
    }
  });

  masterVolume?.addEventListener('input', () => {
    const value = Number(masterVolume.value || 1);

    if (masterValue) {
      masterValue.textContent = dbText(value);
    }

    if (masterGain && context) {
      masterGain.gain.setTargetAtTime(
        value,
        context.currentTime,
        0.008
      );
    }

    scheduleLocalSave();
  });

  stems.forEach(stem => {
    stem.muteButtons.forEach(button => {
      button.addEventListener('click', event => {
        event.stopPropagation();

        stem.muted = !stem.muted;
        updateGains();
      });
    });

    stem.soloButtons.forEach(button => {
      button.addEventListener('click', event => {
        event.stopPropagation();

        stem.solo = !stem.solo;
        updateGains();
      });
    });

    stem.volume?.addEventListener('input', () => {
      stem.userGain = Number(
        stem.volume.value || 0
      );

      updateGains();
    });

    stem.leftRow
      ?.querySelector('[data-track-select]')
      ?.addEventListener(
        'click',
        () => selectStem(stem)
      );

    stem.arrangeRow?.addEventListener(
      'click',
      () => selectStem(stem)
    );
  });

  document.querySelectorAll('[data-stem-preset]')
    .forEach(button => {
      button.addEventListener('click', () => {
        applyPreset(
          button.dataset.stemPreset ||
          'full'
        );
      });
    });

  document.querySelectorAll('[data-master-plugin]')
    .forEach(button => {
      button.addEventListener('click', () => {
        const name = button.dataset.masterPlugin;

        if (!(name in pluginState)) return;

        setPluginState(
          name,
          !pluginState[name]
        );

        ensureAudioGraph();
      });
    });

  resetMixButton?.addEventListener('click', resetMix);

  document.getElementById('openMasterBus')
    ?.addEventListener(
      'click',
      () => openModal(masterBusDialog)
    );

  document.getElementById('openMasterBusChannel')
    ?.addEventListener(
      'click',
      () => openModal(masterBusDialog)
    );

  document.querySelectorAll('[data-close-master-bus]')
    .forEach(button => {
      button.addEventListener(
        'click',
        () => closeModal(masterBusDialog)
      );
    });

  document.getElementById('openMixSaves')
    ?.addEventListener(
      'click',
      async () => {
        openModal(mixSaveDialog);
        await refreshMixList();
      }
    );

  document.querySelectorAll('[data-close-mix-dialog]')
    .forEach(button => {
      button.addEventListener(
        'click',
        () => closeModal(mixSaveDialog)
      );
    });

  saveStemMixButton?.addEventListener('click', async () => {
    try {
      const name = String(
        stemMixName?.value ||
        'My Mix'
      ).trim() || 'My Mix';

      const data = await mixRequest('save', {
        mix_id: selectedMixId || 0,
        mix_name: name,
        state: collectMixState()
      });

      selectedMixId = Number(data.mix_id || 0);

      await refreshMixList();
    } catch (error) {
      alert(error.message);
    }
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      closeModal(masterBusDialog);
      closeModal(mixSaveDialog);
      return;
    }

    if (
      event.code === 'Space' &&
      ![
        'INPUT',
        'TEXTAREA',
        'SELECT',
        'BUTTON'
      ].includes(document.activeElement?.tagName)
    ) {
      event.preventDefault();

      if (playing) {
        pauseAll();
      } else {
        playAll().catch(() => {});
      }
    }
  });

  if (masterValue) {
    masterValue.textContent = dbText(
      masterVolume?.value || 1
    );
  }

  stems.forEach(stem => {
    updateReadouts(stem);
    setStemPan(stem, stem.initialPan);
  });

  resizeTimelineSurface();
  clearLoop();
  updatePlayhead(0);
  updateEqDisplays(true);
  updateGains();
  restoreLocalState();

  window.addEventListener('pagehide', () => {
    if (localPersistenceReady) {
      window.clearTimeout(localSaveTimer);
      saveLocalStateNow();
    }

    pauseAll();
  });
})();
