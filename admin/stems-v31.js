(() => {
  const cfg = window.STONEFELLOW_STEM_STUDIO;
  if (!cfg || !Array.isArray(cfg.stems) || !cfg.stems.length) return;

  const playButton = document.getElementById('stemPlayButton');
  const timeline = document.getElementById('stemTimeline');
  const currentTimeEl = document.getElementById('stemCurrentTime');
  const masterVolume = document.getElementById('stemMasterVolume');
  const masterValue = document.getElementById('stemMasterValue');
  const resetMix = document.getElementById('stemResetMix');
  const playhead = document.getElementById('dawPlayhead');
  const duration = Number(cfg.duration || 0);

  const AudioContextClass = window.AudioContext || window.webkitAudioContext;
  let context = null;
  let masterGain = null;
  let eqLow = null;
  let eqMid = null;
  let eqHigh = null;
  let compressor = null;
  let reverb = null;
  let dryGain = null;
  let wetGain = null;
  let playing = false;
  let position = 0;
  let startedAt = 0;
  let frame = 0;

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

  const stems = cfg.stems.map(meta => {
    const rightRow = document.querySelector(`[data-stem-id="${Number(meta.id)}"]`);
    const mixer = document.querySelector(`[data-mixer-stem="${Number(meta.id)}"]`);
    const arrangeRow = document.querySelector(`[data-arrange-stem="${Number(meta.id)}"]`);
    const audio = rightRow?.querySelector('.stem-audio');
    const volume = mixer?.querySelector('[data-stem-volume]');
    const pan = mixer?.querySelector('[data-stem-pan]');
    const volumeOutput = mixer?.querySelector('[data-volume-value]');
    const panOutput = mixer?.querySelector('[data-pan-value]');
    const knob = mixer?.querySelector('[data-knob-visual]');

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
      rightRow,
      mixer,
      arrangeRow,
      audio,
      volume,
      pan,
      volumeOutput,
      panOutput,
      knob,
      muteButtons,
      soloButtons,
      muted: false,
      solo: false,
      gainNode: null,
      panNode: null,
      sourceNode: null,
      pendingPlay: false,
      initialGain: Number(meta.volume || 1),
      initialPan: Number(meta.pan || 0),
      userGain: Number(meta.volume || 1)
    };
  }).filter(stem => stem.audio);

  function formatTime(seconds) {
    const safe = Math.max(0, Number(seconds || 0));
    const minutes = Math.floor(safe / 60);
    const secs = Math.floor(safe % 60).toString().padStart(2, '0');
    return `${minutes}:${secs}`;
  }

  function globalPosition() {
    if (!playing) return position;
    return Math.min(
      duration,
      position + (performance.now() - startedAt) / 1000
    );
  }

  function makeImpulse(ctx, seconds = 1.1, decay = 2.6) {
    const length = Math.floor(ctx.sampleRate * seconds);
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
    if (!context || !masterGain) return;

    try {
      eqLow?.disconnect();
      eqMid?.disconnect();
      eqHigh?.disconnect();
      compressor?.disconnect();
      masterGain?.disconnect();
      dryGain?.disconnect();
      reverb?.disconnect();
      wetGain?.disconnect();
    } catch (error) {}

    const input = pluginState.eq ? eqLow : masterGain;

    if (pluginState.eq) {
      eqLow.connect(eqMid);
      eqMid.connect(eqHigh);
      if (pluginState.compressor) {
        eqHigh.connect(compressor);
        compressor.connect(masterGain);
      } else {
        eqHigh.connect(masterGain);
      }
    } else if (pluginState.compressor) {
      compressor.connect(masterGain);
    }

    if (!pluginState.eq && pluginState.compressor) {
      stems.forEach(stem => {
        try { stem.panNode?.disconnect(); } catch (error) {}
        try { stem.gainNode?.disconnect(); } catch (error) {}
        const output = stem.panNode || stem.gainNode;
        output?.connect(compressor);
      });
    } else {
      stems.forEach(stem => {
        try { stem.panNode?.disconnect(); } catch (error) {}
        try { stem.gainNode?.disconnect(); } catch (error) {}
        const output = stem.panNode || stem.gainNode;
        output?.connect(input);
      });
    }

    masterGain.connect(dryGain);
    dryGain.connect(context.destination);

    if (pluginState.reverb) {
      masterGain.connect(reverb);
      reverb.connect(wetGain);
      wetGain.connect(context.destination);
      wetGain.gain.value = 0.18;
    } else {
      wetGain.gain.value = 0;
    }
  }

  function ensureAudioGraph() {
    if (!AudioContextClass || context) return;

    context = new AudioContextClass();

    eqLow = context.createBiquadFilter();
    eqLow.type = 'lowshelf';
    eqLow.frequency.value = 140;

    eqMid = context.createBiquadFilter();
    eqMid.type = 'peaking';
    eqMid.frequency.value = 1200;
    eqMid.Q.value = 0.8;

    eqHigh = context.createBiquadFilter();
    eqHigh.type = 'highshelf';
    eqHigh.frequency.value = 5200;

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

        if (context.createStereoPanner) {
          stem.panNode = context.createStereoPanner();
          stem.panNode.pan.value = Number(stem.pan?.value || 0);
          stem.sourceNode.connect(stem.gainNode);
          stem.gainNode.connect(stem.panNode);
        } else {
          stem.sourceNode.connect(stem.gainNode);
        }
      } catch (error) {}
    });

    rebuildMasterGraph();
    updateGains();
  }

  function updateKnob(stem) {
    const value = Math.max(-1, Math.min(1, Number(stem.pan?.value || 0)));
    const degrees = -135 + ((value + 1) / 2) * 270;
    stem.knob?.style.setProperty('--knob-angle', `${degrees}deg`);
    if (stem.panOutput) stem.panOutput.textContent = panText(value);
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

      [stem.rightRow, stem.mixer, stem.arrangeRow].forEach(el => {
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
        stem.audio.currentTime = Math.max(
          0,
          Math.min(stemDuration, localTime)
        );
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

  function updatePlayhead(now) {
    const percent = duration > 0
      ? Math.max(0, Math.min(100, (now / duration) * 100))
      : 0;
    if (playhead) playhead.style.left = `${percent}%`;
  }

  function render() {
    const now = globalPosition();

    if (timeline && !timeline.matches(':active')) {
      timeline.value = String(now);
    }
    if (currentTimeEl) currentTimeEl.textContent = formatTime(now);
    updatePlayhead(now);

    if (!playing) return;

    if (now >= duration) {
      pauseAll();
      position = duration;
      if (timeline) timeline.value = String(duration);
      if (currentTimeEl) currentTimeEl.textContent = formatTime(duration);
      updatePlayhead(duration);
      return;
    }

    syncAll(false);
    frame = requestAnimationFrame(render);
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
    if (playing) position = globalPosition();

    playing = false;
    stems.forEach(stem => stem.audio.pause());
    cancelAnimationFrame(frame);

    if (playButton) {
      playButton.textContent = '▶';
      playButton.setAttribute('aria-label', 'Play');
    }
    updatePlayhead(position);
  }

  playButton?.addEventListener('click', () => {
    if (playing) pauseAll();
    else playAll();
  });

  timeline?.addEventListener('input', () => {
    position = Math.max(
      0,
      Math.min(duration, Number(timeline.value || 0))
    );

    if (playing) startedAt = performance.now();
    if (currentTimeEl) currentTimeEl.textContent = formatTime(position);
    updatePlayhead(position);
    syncAll(true);
  });

  masterVolume?.addEventListener('input', () => {
    const value = Number(masterVolume.value || 1);
    if (masterValue) masterValue.textContent = dbText(value);

    if (masterGain) {
      masterGain.gain.setTargetAtTime(
        value,
        context?.currentTime || 0,
        0.01
      );
    }
  });

  stems.forEach(stem => {
    stem.muteButtons.forEach(button => {
      button.addEventListener('click', () => {
        stem.muted = !stem.muted;
        updateGains();
      });
    });

    stem.soloButtons.forEach(button => {
      button.addEventListener('click', () => {
        stem.solo = !stem.solo;
        updateGains();
      });
    });

    stem.volume?.addEventListener('input', () => {
      stem.userGain = Number(stem.volume.value || 0);
      updateGains();
    });

    stem.pan?.addEventListener('input', () => {
      updateKnob(stem);

      if (stem.panNode) {
        stem.panNode.pan.setTargetAtTime(
          Number(stem.pan.value || 0),
          context?.currentTime || 0,
          0.01
        );
      }
    });

    stem.rightRow?.querySelector('[data-track-select]')?.addEventListener(
      'click',
      () => {
        document.querySelectorAll('.daw-track-row.selected')
          .forEach(el => el.classList.remove('selected'));
        document.querySelectorAll('.daw-stem-channel.selected')
          .forEach(el => el.classList.remove('selected'));
        document.querySelectorAll('.daw-arrange-row.selected')
          .forEach(el => el.classList.remove('selected'));

        stem.rightRow?.classList.add('selected');
        stem.mixer?.classList.add('selected');
        stem.arrangeRow?.classList.add('selected');

        stem.mixer?.scrollIntoView({
          block: 'nearest',
          inline: 'center',
          behavior: 'smooth'
        });
      }
    );
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
        stem.solo = ['drums', 'percussion', 'bass'].includes(role);
      }
    });

    updateGains();
  }

  document.querySelectorAll('[data-stem-preset]').forEach(button => {
    button.addEventListener('click', () => {
      applyPreset(button.dataset.stemPreset || 'full');
    });
  });

  document.querySelectorAll('[data-master-plugin]').forEach(button => {
    button.addEventListener('click', () => {
      const name = button.dataset.masterPlugin;
      if (!(name in pluginState)) return;

      pluginState[name] = !pluginState[name];
      button.classList.toggle('active', pluginState[name]);

      ensureAudioGraph();
      rebuildMasterGraph();
    });
  });

  resetMix?.addEventListener('click', () => {
    stems.forEach(stem => {
      stem.muted = false;
      stem.solo = false;
      stem.userGain = Math.max(
        0,
        Math.min(1.5, Number(stem.initialGain || 1))
      );

      if (stem.volume) stem.volume.value = String(stem.userGain);

      const panValue = Math.max(
        -1,
        Math.min(1, Number(stem.initialPan || 0))
      );
      if (stem.pan) stem.pan.value = String(panValue);
      if (stem.panNode) stem.panNode.pan.value = panValue;
    });

    if (masterVolume) masterVolume.value = '1';
    if (masterValue) masterValue.textContent = '0.0 dB';
    if (masterGain) masterGain.gain.value = 1;

    pluginState.eq = true;
    pluginState.compressor = true;
    pluginState.reverb = false;

    document.querySelectorAll('[data-master-plugin]').forEach(button => {
      button.classList.toggle(
        'active',
        Boolean(pluginState[button.dataset.masterPlugin])
      );
    });

    if (context) rebuildMasterGraph();
    updateGains();
  });

  document.addEventListener('keydown', event => {
    if (
      event.code === 'Space' &&
      !['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(
        document.activeElement?.tagName
      )
    ) {
      event.preventDefault();
      if (playing) pauseAll();
      else playAll();
    }
  });

  if (masterValue) {
    masterValue.textContent = dbText(masterVolume?.value || 1);
  }

  stems.forEach(updateReadouts);
  updatePlayhead(0);
  updateGains();

  window.addEventListener('pagehide', pauseAll);
})();