(() => {
  const cfg = window.STONEFELLOW_STEM_STUDIO;
  if (!cfg || !Array.isArray(cfg.stems) || !cfg.stems.length) return;

  const playButton = document.getElementById('stemPlayButton');
  const timeline = document.getElementById('stemTimeline');
  const currentTimeEl = document.getElementById('stemCurrentTime');
  const masterVolume = document.getElementById('stemMasterVolume');
  const masterValue = document.getElementById('stemMasterValue');
  const resetMixButton = document.getElementById('stemResetMix');
  const playhead = document.getElementById('dawPlayhead');

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
  let draggedStemId = 0;
  let selectedMixId = 0;

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
    const leftRow = document.querySelector(`[data-stem-id="${Number(meta.id)}"]`);
    const mixer = document.querySelector(`[data-mixer-stem="${Number(meta.id)}"]`);
    const arrangeRow = document.querySelector(`[data-arrange-stem="${Number(meta.id)}"]`);
    const audio = leftRow?.querySelector('.stem-audio');
    const volume = mixer?.querySelector('[data-stem-volume]');
    const pan = mixer?.querySelector('[data-stem-pan]');
    const volumeOutput = mixer?.querySelector('[data-volume-value]');
    const panOutput = mixer?.querySelector('[data-pan-value]');
    const knob = mixer?.querySelector('[data-pan-knob]');

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
      muteButtons,
      soloButtons,
      muted: false,
      solo: false,
      gainNode: null,
      panNode: null,
      sourceNode: null,
      pendingPlay: false,
      initialGain: Math.max(0, Math.min(1.5, Number(meta.volume || 1))),
      initialPan: Math.max(-1, Math.min(1, Number(meta.pan || 0))),
      userGain: Math.max(0, Math.min(1.5, Number(meta.volume || 1)))
    };
  }).filter(stem => stem.audio);

  const stemById = id => stems.find(stem => stem.id === Number(id));

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

    /*
     * Important: stems are connected to busInput exactly once.
     * Rebuilding the master chain never disconnects stem gain/pan nodes.
     * v31 disconnected gainNode from panNode and could produce silence.
     */
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
      try { node.disconnect(); } catch (error) {}
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

        stem.sourceNode.connect(stem.gainNode);

        if (context.createStereoPanner) {
          stem.panNode = context.createStereoPanner();
          stem.panNode.pan.value = Number(stem.pan?.value || 0);
          stem.gainNode.connect(stem.panNode);
          stem.panNode.connect(busInput);
        } else {
          stem.gainNode.connect(busInput);
        }
      } catch (error) {
        console.error('Stem audio graph failed', stem.id, error);
      }
    });

    rebuildMasterGraph();
    updateGains();
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
        .catch(error => {
          console.error('Stem playback failed', stem.id, error);
        })
        .finally(() => {
          stem.pendingPlay = false;
        });
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

    if (playhead) {
      playhead.style.left = `${percent}%`;
    }
  }

  function render() {
    const now = globalPosition();

    if (timeline && !timeline.matches(':active')) {
      timeline.value = String(now);
    }

    if (currentTimeEl) {
      currentTimeEl.textContent = formatTime(now);
    }

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

    if (!context) {
      alert('Web Audio is not available in this browser.');
      return;
    }

    if (context.state === 'suspended') {
      await context.resume();
    }

    if (position >= duration - 0.02) {
      position = 0;
    }

    startedAt = performance.now();
    playing = true;

    if (playButton) {
      playButton.textContent = '❚❚';
      playButton.setAttribute('aria-label', 'Pause');
    }

    syncAll(true);
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(render);
  }

  function pauseAll() {
    if (playing) {
      position = globalPosition();
    }

    playing = false;

    stems.forEach(stem => {
      stem.audio.pause();
    });

    cancelAnimationFrame(frame);

    if (playButton) {
      playButton.textContent = '▶';
      playButton.setAttribute('aria-label', 'Play');
    }

    updatePlayhead(position);
  }

  function selectStem(stem) {
    document.querySelectorAll(
      '.daw-track-row.selected,.daw-stem-channel.selected,.daw-arrange-row.selected'
    ).forEach(el => el.classList.remove('selected'));

    stem.leftRow?.classList.add('selected');
    stem.mixer?.classList.add('selected');
    stem.arrangeRow?.classList.add('selected');

    stem.mixer?.scrollIntoView({
      block: 'nearest',
      inline: 'center',
      behavior: 'smooth'
    });
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
      `[data-master-plugin="${CSS.escape(name)}"]`
    ).forEach(button => {
      button.classList.toggle('active', pluginState[name]);
    });

    if (context) {
      rebuildMasterGraph();
    }
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

  // ---------------------------
  // Drag/drop track order
  // ---------------------------
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

  function bindDragElement(element) {
    if (!element) return;

    element.addEventListener('dragstart', event => {
      draggedStemId = Number(element.dataset.dragStem || 0);
      element.classList.add('dragging');

      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', String(draggedStemId));
    });

    element.addEventListener('dragend', () => {
      draggedStemId = 0;
      document.querySelectorAll('.dragging,.drag-over')
        .forEach(el => el.classList.remove('dragging', 'drag-over'));
    });

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
      event.preventDefault();
      element.classList.remove('drag-over');

      const sourceId = Number(
        event.dataTransfer.getData('text/plain') || draggedStemId
      );
      const targetId = Number(element.dataset.dragStem || 0);

      if (sourceId && targetId) {
        moveStemBefore(sourceId, targetId);
      }
    });
  }

  document.querySelectorAll('[data-drag-stem]').forEach(bindDragElement);

  // ---------------------------
  // Stable pan knob interaction
  // ---------------------------
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
        setStemPan(stem, 0);
      });

      knob.addEventListener('keydown', event => {
        const current = Number(stem.pan?.value || 0);
        const step = event.shiftKey ? 0.1 : 0.02;

        if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
          event.preventDefault();
          setStemPan(stem, current - step);
        } else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
          event.preventDefault();
          setStemPan(stem, current + step);
        } else if (event.key === 'Home' || event.key === '0') {
          event.preventDefault();
          setStemPan(stem, 0);
        }
      });
    }
  });

  // ---------------------------
  // Saved custom supervisor mixes
  // ---------------------------
  async function mixRequest(action, extra = {}) {
    if (!cfg.canSaveMix || !cfg.mixEndpoint) {
      throw new Error('Saved mixes are not available for this account.');
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
      throw new Error(data?.error || 'Saved mix request failed.');
    }

    return data;
  }

  function collectMixState() {
    const state = {
      masterVolume: Number(masterVolume?.value || 1),
      plugins: {...pluginState},
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
        Math.min(1.5, Number(mix.volume ?? stem.userGain))
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
      Math.min(1.5, Number(state.masterVolume ?? 1))
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

    Object.entries(state.plugins || {}).forEach(([name, enabled]) => {
      setPluginState(name, Boolean(enabled));
    });

    updateGains();
  }

  async function refreshMixList() {
    if (!savedMixList || !cfg.canSaveMix) return;

    savedMixList.innerHTML =
      '<p class="daw-modal-empty">Loading saved mixes…</p>';

    try {
      const data = await mixRequest('list');
      const mixes = Array.isArray(data.mixes) ? data.mixes : [];

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
          <button type="button" class="daw-mix-delete" data-delete-mix="${Number(mix.id)}">×</button>
        </article>
      `).join('');

      savedMixList.querySelectorAll('[data-load-mix]').forEach(button => {
        button.addEventListener('click', async () => {
          try {
            const data = await mixRequest('load', {
              mix_id: Number(button.dataset.loadMix || 0)
            });

            selectedMixId = Number(data.mix?.id || 0);

            if (stemMixName) {
              stemMixName.value = String(data.mix?.mix_name || 'My Mix');
            }

            applyMixState(data.mix?.state || {});
            closeModal(mixSaveDialog);
          } catch (error) {
            alert(error.message);
          }
        });
      });

      savedMixList.querySelectorAll('[data-delete-mix]').forEach(button => {
        button.addEventListener('click', async () => {
          const mixId = Number(button.dataset.deleteMix || 0);

          if (!confirm('Delete this saved mix?')) return;

          try {
            await mixRequest('delete', {mix_id: mixId});

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

  // ---------------------------
  // Event bindings
  // ---------------------------
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

  timeline?.addEventListener('input', () => {
    position = Math.max(
      0,
      Math.min(duration, Number(timeline.value || 0))
    );

    if (playing) {
      startedAt = performance.now();
    }

    if (currentTimeEl) {
      currentTimeEl.textContent = formatTime(position);
    }

    updatePlayhead(position);
    syncAll(true);
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
      stem.userGain = Number(stem.volume.value || 0);
      updateGains();
    });

    stem.leftRow?.querySelector('[data-track-select]')?.addEventListener(
      'click',
      () => selectStem(stem)
    );

    stem.arrangeRow?.addEventListener('click', () => selectStem(stem));
  });

  document.querySelectorAll('[data-stem-preset]').forEach(button => {
    button.addEventListener('click', () => {
      applyPreset(button.dataset.stemPreset || 'full');
    });
  });

  document.querySelectorAll('[data-master-plugin]').forEach(button => {
    button.addEventListener('click', () => {
      const name = button.dataset.masterPlugin;
      if (!(name in pluginState)) return;

      setPluginState(name, !pluginState[name]);
      ensureAudioGraph();
    });
  });

  resetMixButton?.addEventListener('click', resetMix);

  document.getElementById('openMasterBus')?.addEventListener(
    'click',
    () => openModal(masterBusDialog)
  );

  document.getElementById('openMasterBusChannel')?.addEventListener(
    'click',
    () => openModal(masterBusDialog)
  );

  document.querySelectorAll('[data-close-master-bus]').forEach(button => {
    button.addEventListener('click', () => closeModal(masterBusDialog));
  });

  document.getElementById('openMixSaves')?.addEventListener(
    'click',
    async () => {
      openModal(mixSaveDialog);
      await refreshMixList();
    }
  );

  document.querySelectorAll('[data-close-mix-dialog]').forEach(button => {
    button.addEventListener('click', () => closeModal(mixSaveDialog));
  });

  saveStemMixButton?.addEventListener('click', async () => {
    try {
      const name = String(stemMixName?.value || 'My Mix').trim() || 'My Mix';

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
      !['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(
        document.activeElement?.tagName
      )
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
    masterValue.textContent = dbText(masterVolume?.value || 1);
  }

  stems.forEach(stem => {
    updateReadouts(stem);
    setStemPan(stem, stem.initialPan);
  });

  updatePlayhead(0);
  updateGains();

  window.addEventListener('pagehide', pauseAll);
})();