(() => {
  const cfg = window.STONEFELLOW_STEM_STUDIO;
  if (!cfg || !Array.isArray(cfg.stems) || !cfg.stems.length) return;

  const playButton = document.getElementById('stemPlayButton');
  const currentTimeEl = document.getElementById('stemCurrentTime');
  const masterVolume = document.getElementById('stemMasterVolume');
  const masterValue = document.getElementById('stemMasterValue');
  const masterMeterBars = [
    ...document.querySelectorAll('[data-master-meter] i')
  ];
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
  const studio = document.getElementById('stemStudio');
  const pluginRackHandle = document.getElementById('pluginRackHandle');

  const pluginDirectoryDialog = document.getElementById('pluginDirectoryDialog');
  const pluginDirectoryTrack = document.getElementById('pluginDirectoryTrack');
  const pluginEditor = document.getElementById('pluginEditor');
  const pluginEditorTitle = document.getElementById('pluginEditorTitle');
  const pluginEditorControls = document.getElementById('pluginEditorControls');
  const pluginBypassButton = document.getElementById('pluginBypassButton');
  const pluginRemoveButton = document.getElementById('pluginRemoveButton');

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
  let masterAnalyser = null;
  let masterLevelData = null;
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

  let pluginRackOpen = false;
  let pluginRackHeight = 348;
  let pluginTargetStemId = 0;
  let pluginEditIndex = -1;

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
    const pluginList = mixer?.querySelector('[data-track-plugin-list]');
    const addPluginButton = mixer?.querySelector('[data-add-track-plugin]');

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
      pluginList,
      addPluginButton,
      plugins: [],
      pluginNodes: [],
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
      selectedStemId: Number(selectedStemId || 0),
      pluginRackOpen: Boolean(pluginRackOpen),
      pluginRackHeight: Number(pluginRackHeight || 348)
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

        setPluginRack(
          Boolean(view.pluginRackOpen),
          Number(view.pluginRackHeight || 348),
          false
        );

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

  function defaultPlugin(type) {
    if (type === 'eq5') {
      return {
        type: 'eq5',
        enabled: true,
        params: {
          f1: 80,
          f2: 250,
          f3: 1000,
          f4: 4000,
          f5: 12000,
          b1: 0,
          b2: 0,
          b3: 0,
          b4: 0,
          b5: 0
        }
      };
    }

    return {
      type: 'delay',
      enabled: true,
      params: {
        time: 0.28,
        feedback: 0.32,
        mix: 0.20
      }
    };
  }

  function normalizeTrackPlugins(plugins) {
    if (!Array.isArray(plugins)) return [];

    const freqRanges = [
      [40, 180],
      [120, 700],
      [500, 2500],
      [1800, 8000],
      [6000, 18000]
    ];

    return plugins.slice(0, 4)
      .map(plugin => {
        if (!plugin || !['eq5', 'delay'].includes(plugin.type)) {
          return null;
        }

        const base = defaultPlugin(plugin.type);
        const params = {
          ...base.params,
          ...(plugin.params || {})
        };

        if (plugin.type === 'eq5') {
          ['b1','b2','b3','b4','b5'].forEach(key => {
            params[key] = Math.max(
              -18,
              Math.min(18, Number(params[key] || 0))
            );
          });

          ['f1','f2','f3','f4','f5'].forEach((key, index) => {
            const [min, max] = freqRanges[index];
            params[key] = Math.max(
              min,
              Math.min(max, Number(params[key] || base.params[key]))
            );
          });
        } else {
          params.time = Math.max(
            0.02,
            Math.min(1.5, Number(params.time || 0.28))
          );
          params.feedback = Math.max(
            0,
            Math.min(0.92, Number(params.feedback || 0.32))
          );
          params.mix = Math.max(
            0,
            Math.min(1, Number(params.mix || 0.20))
          );
        }

        return {
          type: plugin.type,
          enabled: plugin.enabled !== false,
          params
        };
      })
      .filter(Boolean);
  }

  function pluginLabel(plugin) {
    return plugin.type === 'eq5'
      ? '5-Band EQ'
      : 'Delay';
  }

  function renderTrackPluginList(stem) {
    if (!stem.pluginList) return;

    stem.pluginList.innerHTML = stem.plugins.map((plugin, index) => `
      <button
        type="button"
        class="daw-track-plugin-chip${plugin.enabled ? '' : ' bypassed'}"
        data-edit-track-plugin="${stem.id}"
        data-plugin-index="${index}"
        title="${plugin.enabled ? 'Edit' : 'Bypassed'} ${pluginLabel(plugin)}"
      >
        <span>${plugin.type === 'eq5' ? 'EQ5' : 'DLY'}</span>
        <small>${plugin.enabled ? 'ON' : 'OFF'}</small>
      </button>
    `).join('');

    stem.pluginList
      .querySelectorAll('[data-edit-track-plugin]')
      .forEach(button => {
        button.addEventListener('click', event => {
          event.stopPropagation();
          openPluginEditor(
            Number(button.dataset.editTrackPlugin || 0),
            Number(button.dataset.pluginIndex || 0)
          );
        });
      });

    if (stem.addPluginButton) {
      stem.addPluginButton.hidden = stem.plugins.length >= 4;
    }
  }

  function setPluginRack(open, height = null, persist = true) {
    pluginRackOpen = Boolean(open);

    if (height !== null && Number.isFinite(Number(height))) {
      pluginRackHeight = Math.max(
        320,
        Math.min(560, Number(height))
      );
    } else {
      pluginRackHeight = pluginRackOpen ? 470 : 348;
    }

    if (studio) {
      studio.classList.toggle(
        'plugin-rack-open',
        pluginRackOpen
      );

      studio.style.setProperty(
        '--mixer-height',
        `${pluginRackOpen ? pluginRackHeight : 348}px`
      );
    }

    pluginRackHandle?.setAttribute(
      'aria-expanded',
      pluginRackOpen ? 'true' : 'false'
    );

    if (pluginRackHandle) {
      const small = pluginRackHandle.querySelector('small');
      if (small) {
        small.textContent = pluginRackOpen
          ? 'drag down'
          : 'drag up';
      }
    }

    if (persist) {
      scheduleLocalSave();
    }
  }

  function disconnectTrackPluginNodes(stem) {
    (stem.pluginNodes || []).forEach(node => {
      try {
        node.disconnect();
      } catch (error) {}
    });

    stem.pluginNodes = [];

    stem.plugins.forEach(plugin => {
      delete plugin._runtime;
    });
  }

  function createEq5Graph(plugin) {
    const frequencyKeys = ['f1','f2','f3','f4','f5'];
    const gainKeys = ['b1','b2','b3','b4','b5'];

    const filters = frequencyKeys.map((frequencyKey, index) => {
      const node = context.createBiquadFilter();

      node.type = index === 0
        ? 'lowshelf'
        : index === frequencyKeys.length - 1
          ? 'highshelf'
          : 'peaking';

      node.frequency.value = Number(plugin.params[frequencyKey]);
      node.Q.value = index === 0 || index === frequencyKeys.length - 1
        ? 0.7
        : 1.0;
      node.gain.value = Number(plugin.params[gainKeys[index]] || 0);

      return node;
    });

    for (let i = 0; i < filters.length - 1; i++) {
      filters[i].connect(filters[i + 1]);
    }

    plugin._runtime = {
      type: 'eq5',
      filters
    };

    return {
      input: filters[0],
      output: filters[filters.length - 1],
      nodes: filters
    };
  }

  function createDelayGraph(plugin) {
    const input = context.createGain();
    const output = context.createGain();
    const dry = context.createGain();
    const wet = context.createGain();
    const delay = context.createDelay(2.0);
    const feedback = context.createGain();

    delay.delayTime.value = Number(plugin.params.time || 0.28);
    feedback.gain.value = Number(plugin.params.feedback || 0.32);

    const mix = Math.max(
      0,
      Math.min(1, Number(plugin.params.mix || 0.20))
    );

    dry.gain.value = 1 - mix;
    wet.gain.value = mix;

    input.connect(dry);
    dry.connect(output);

    input.connect(delay);
    delay.connect(wet);
    wet.connect(output);

    delay.connect(feedback);
    feedback.connect(delay);

    plugin._runtime = {
      type: 'delay',
      delay,
      feedback,
      dry,
      wet
    };

    return {
      input,
      output,
      nodes: [input, output, dry, wet, delay, feedback]
    };
  }

  function updateTrackPluginAudio(plugin) {
    if (!context || !plugin?._runtime) {
      return;
    }

    const now = context.currentTime;

    if (
      plugin.type === 'eq5' &&
      plugin._runtime.type === 'eq5'
    ) {
      const frequencyKeys = ['f1','f2','f3','f4','f5'];
      const gainKeys = ['b1','b2','b3','b4','b5'];

      plugin._runtime.filters.forEach((filter, index) => {
        filter.frequency.setTargetAtTime(
          Number(plugin.params[frequencyKeys[index]]),
          now,
          0.012
        );
        filter.gain.setTargetAtTime(
          Number(plugin.params[gainKeys[index]]),
          now,
          0.012
        );
      });
      return;
    }

    if (
      plugin.type === 'delay' &&
      plugin._runtime.type === 'delay'
    ) {
      const mix = Math.max(
        0,
        Math.min(1, Number(plugin.params.mix || 0))
      );

      plugin._runtime.delay.delayTime.setTargetAtTime(
        Number(plugin.params.time || 0.28),
        now,
        0.018
      );
      plugin._runtime.feedback.gain.setTargetAtTime(
        Number(plugin.params.feedback || 0.32),
        now,
        0.018
      );
      plugin._runtime.dry.gain.setTargetAtTime(
        1 - mix,
        now,
        0.018
      );
      plugin._runtime.wet.gain.setTargetAtTime(
        mix,
        now,
        0.018
      );
    }
  }

  function rebuildTrackPluginGraph(stem) {
    if (
      !context ||
      !stem.analyserNode ||
      !stem.gainNode
    ) {
      return;
    }

    const sourceOutput = stem.panNode || stem.gainNode;

    try {
      sourceOutput.disconnect();
    } catch (error) {}

    disconnectTrackPluginNodes(stem);

    let current = sourceOutput;

    stem.plugins.forEach(plugin => {
      if (!plugin.enabled) return;

      const graph = plugin.type === 'eq5'
        ? createEq5Graph(plugin)
        : createDelayGraph(plugin);

      current.connect(graph.input);
      current = graph.output;
      stem.pluginNodes.push(...graph.nodes);
    });

    current.connect(stem.analyserNode);
  }

  function rebuildAllTrackPluginGraphs() {
    if (!context) return;

    stems.forEach(stem => {
      rebuildTrackPluginGraph(stem);
    });
  }

  function openPluginDirectory(stemId) {
    const stem = stemById(stemId);
    if (!stem) return;

    pluginTargetStemId = stem.id;
    pluginEditIndex = -1;

    if (pluginDirectoryTrack) {
      pluginDirectoryTrack.innerHTML = `
        <span>INSERT ON TRACK</span>
        <strong>${escapeHtml(stem.stem_name || stem.name || `Stem ${stem.id}`)}</strong>
      `;
    }

    if (pluginEditor) {
      pluginEditor.hidden = true;
    }

    document.getElementById('pluginDirectoryGrid')?.removeAttribute('hidden');
    openModal(pluginDirectoryDialog);
  }

  function eqFrequencyToX(frequency) {
    const min = 20;
    const max = 20000;
    const width = 680;
    const left = 42;

    return left + (
      Math.log10(frequency / min) /
      Math.log10(max / min)
    ) * width;
  }

  function eqXToFrequency(x) {
    const min = 20;
    const max = 20000;
    const width = 680;
    const left = 42;
    const ratio = Math.max(
      0,
      Math.min(1, (x - left) / width)
    );

    return min * Math.pow(max / min, ratio);
  }

  function eqGainToY(gain) {
    const top = 20;
    const height = 240;
    return top + ((18 - gain) / 36) * height;
  }

  function eqYToGain(y) {
    const top = 20;
    const height = 240;
    const ratio = Math.max(
      0,
      Math.min(1, (y - top) / height)
    );
    return 18 - ratio * 36;
  }

  function formatFrequency(value) {
    const frequency = Number(value || 0);

    if (frequency >= 1000) {
      return `${(frequency / 1000).toFixed(
        frequency >= 10000 ? 1 : 2
      ).replace(/\.0+$/, '')} kHz`;
    }

    return `${Math.round(frequency)} Hz`;
  }

  function svgPoint(event, svg) {
    const point = svg.createSVGPoint();
    point.x = event.clientX;
    point.y = event.clientY;

    const matrix = svg.getScreenCTM();

    if (!matrix) {
      return {x:0,y:0};
    }

    const transformed = point.matrixTransform(
      matrix.inverse()
    );

    return {
      x: transformed.x,
      y: transformed.y
    };
  }

  function eqCurvePath(plugin) {
    const points = [
      [20, 0],
      [plugin.params.f1, plugin.params.b1],
      [plugin.params.f2, plugin.params.b2],
      [plugin.params.f3, plugin.params.b3],
      [plugin.params.f4, plugin.params.b4],
      [plugin.params.f5, plugin.params.b5],
      [20000, 0]
    ].map(([frequency, gain]) => [
      eqFrequencyToX(frequency),
      eqGainToY(gain)
    ]);

    let path = `M ${points[0][0]} ${points[0][1]}`;

    for (let i = 1; i < points.length; i++) {
      const [x0, y0] = points[i - 1];
      const [x1, y1] = points[i];
      const mid = (x0 + x1) / 2;

      path += ` C ${mid} ${y0}, ${mid} ${y1}, ${x1} ${y1}`;
    }

    return path;
  }

  function renderEqGraph(plugin) {
    if (!pluginEditorControls) return;

    const frequencyKeys = ['f1','f2','f3','f4','f5'];
    const gainKeys = ['b1','b2','b3','b4','b5'];
    const labels = ['LOW','LOW-MID','MID','HIGH-MID','HIGH'];

    pluginEditorControls.innerHTML = `
      <div class="daw-plugin-graph-shell">
        <div class="daw-plugin-graph-head">
          <div>
            <span>5-BAND PARAMETRIC EQ</span>
            <strong>Drag a node horizontally for frequency and vertically for gain.</strong>
          </div>
          <button type="button" class="daw-small-button" data-reset-eq>Flat</button>
        </div>

        <svg
          class="daw-plugin-graph daw-eq-graph"
          data-eq-graph
          viewBox="0 0 764 300"
          role="img"
          aria-label="Interactive five band equalizer graph"
        >
          <defs>
            <linearGradient id="eqFillGradient" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="currentColor" stop-opacity=".22"/>
              <stop offset="100%" stop-color="currentColor" stop-opacity="0"/>
            </linearGradient>
          </defs>

          <g class="daw-graph-grid">
            ${[20,50,100,250,500,1000,2500,5000,10000,20000]
              .map(f => `
                <line x1="${eqFrequencyToX(f)}" y1="20" x2="${eqFrequencyToX(f)}" y2="260"></line>
                <text x="${eqFrequencyToX(f)}" y="282">${formatFrequency(f)}</text>
              `).join('')}
            ${[-18,-12,-6,0,6,12,18]
              .map(g => `
                <line x1="42" y1="${eqGainToY(g)}" x2="722" y2="${eqGainToY(g)}"></line>
                <text x="8" y="${eqGainToY(g)+4}">${g > 0 ? '+' : ''}${g}</text>
              `).join('')}
          </g>

          <path
            class="daw-eq-fill"
            data-eq-fill
            d="${eqCurvePath(plugin)} L 722 ${eqGainToY(0)} L 42 ${eqGainToY(0)} Z"
          ></path>

          <path
            class="daw-eq-curve"
            data-eq-curve
            d="${eqCurvePath(plugin)}"
          ></path>

          ${frequencyKeys.map((frequencyKey, index) => `
            <g
              class="daw-eq-node"
              data-eq-node="${index}"
              tabindex="0"
              role="slider"
              aria-label="${labels[index]} EQ band"
              aria-valuetext="${formatFrequency(plugin.params[frequencyKey])}, ${Number(plugin.params[gainKeys[index]]).toFixed(1)} dB"
              transform="translate(${eqFrequencyToX(plugin.params[frequencyKey])} ${eqGainToY(plugin.params[gainKeys[index]])})"
            >
              <circle class="daw-eq-node-halo" r="14"></circle>
              <circle class="daw-eq-node-core" r="7"></circle>
              <text x="0" y="-20">${index + 1}</text>
            </g>
          `).join('')}
        </svg>

        <div class="daw-eq-node-readouts">
          ${frequencyKeys.map((frequencyKey,index) => `
            <button
              type="button"
              data-eq-focus="${index}"
              class="daw-eq-readout"
            >
              <strong>${labels[index]}</strong>
              <span data-eq-frequency="${index}">${formatFrequency(plugin.params[frequencyKey])}</span>
              <em data-eq-gain="${index}">${Number(plugin.params[gainKeys[index]]).toFixed(1)} dB</em>
            </button>
          `).join('')}
        </div>
      </div>
    `;

    bindEqGraph(plugin);
  }

  function updateEqGraphDom(plugin) {
    const graph = pluginEditorControls?.querySelector('[data-eq-graph]');
    if (!graph) return;

    const frequencyKeys = ['f1','f2','f3','f4','f5'];
    const gainKeys = ['b1','b2','b3','b4','b5'];
    const curve = eqCurvePath(plugin);

    const curveEl = graph.querySelector('[data-eq-curve]');
    const fillEl = graph.querySelector('[data-eq-fill]');

    if (curveEl) {
      curveEl.setAttribute('d', curve);
    }

    if (fillEl) {
      fillEl.setAttribute(
        'd',
        `${curve} L 722 ${eqGainToY(0)} L 42 ${eqGainToY(0)} Z`
      );
    }

    graph.querySelectorAll('[data-eq-node]')
      .forEach((node,index) => {
        node.setAttribute(
          'transform',
          `translate(${eqFrequencyToX(plugin.params[frequencyKeys[index]])} ${eqGainToY(plugin.params[gainKeys[index]])})`
        );
        node.setAttribute(
          'aria-valuetext',
          `${formatFrequency(plugin.params[frequencyKeys[index]])}, ${Number(plugin.params[gainKeys[index]]).toFixed(1)} dB`
        );

        const frequencyReadout =
          pluginEditorControls.querySelector(`[data-eq-frequency="${index}"]`);
        const gainReadout =
          pluginEditorControls.querySelector(`[data-eq-gain="${index}"]`);

        if (frequencyReadout) {
          frequencyReadout.textContent =
            formatFrequency(plugin.params[frequencyKeys[index]]);
        }

        if (gainReadout) {
          gainReadout.textContent =
            `${Number(plugin.params[gainKeys[index]]).toFixed(1)} dB`;
        }
      });
  }

  function bindEqGraph(plugin) {
    const svg = pluginEditorControls?.querySelector('[data-eq-graph]');
    if (!svg) return;

    const frequencyKeys = ['f1','f2','f3','f4','f5'];
    const gainKeys = ['b1','b2','b3','b4','b5'];
    const frequencyRanges = [
      [40,180],
      [120,700],
      [500,2500],
      [1800,8000],
      [6000,18000]
    ];

    const applyNode = (index, x, y) => {
      const [minFrequency,maxFrequency] = frequencyRanges[index];

      plugin.params[frequencyKeys[index]] = Math.max(
        minFrequency,
        Math.min(maxFrequency, eqXToFrequency(x))
      );

      plugin.params[gainKeys[index]] = Math.round(
        Math.max(-18, Math.min(18, eqYToGain(y))) * 10
      ) / 10;

      updateEqGraphDom(plugin);
      updateTrackPluginAudio(plugin);
      scheduleLocalSave();
    };

    svg.querySelectorAll('[data-eq-node]')
      .forEach((node,index) => {
        let pointerId = null;

        node.addEventListener('pointerdown', event => {
          if (event.button !== 0) return;

          pointerId = event.pointerId;
          node.setPointerCapture(pointerId);
          node.classList.add('dragging');
          event.preventDefault();
          event.stopPropagation();
        });

        node.addEventListener('pointermove', event => {
          if (event.pointerId !== pointerId) return;

          const point = svgPoint(event, svg);
          applyNode(index, point.x, point.y);
        });

        const finish = event => {
          if (event.pointerId !== pointerId) return;

          try {
            node.releasePointerCapture(pointerId);
          } catch (error) {}

          pointerId = null;
          node.classList.remove('dragging');
        };

        node.addEventListener('pointerup', finish);
        node.addEventListener('pointercancel', finish);

        node.addEventListener('keydown', event => {
          const frequencyKey = frequencyKeys[index];
          const gainKey = gainKeys[index];

          if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            plugin.params[gainKey] = Math.max(
              -18,
              Math.min(
                18,
                Number(plugin.params[gainKey]) +
                (event.key === 'ArrowUp' ? 0.5 : -0.5)
              )
            );
          } else if (
            event.key === 'ArrowLeft' ||
            event.key === 'ArrowRight'
          ) {
            event.preventDefault();
            const direction = event.key === 'ArrowRight' ? 1 : -1;
            plugin.params[frequencyKey] *= Math.pow(2, direction / 24);

            const [minFrequency,maxFrequency] = frequencyRanges[index];
            plugin.params[frequencyKey] = Math.max(
              minFrequency,
              Math.min(maxFrequency, plugin.params[frequencyKey])
            );
          } else {
            return;
          }

          updateEqGraphDom(plugin);
          updateTrackPluginAudio(plugin);
          scheduleLocalSave();
        });
      });

    pluginEditorControls
      .querySelectorAll('[data-eq-focus]')
      .forEach(button => {
        button.addEventListener('click', () => {
          svg.querySelector(
            `[data-eq-node="${button.dataset.eqFocus}"]`
          )?.focus();
        });
      });

    pluginEditorControls
      .querySelector('[data-reset-eq]')
      ?.addEventListener('click', () => {
        const defaults = defaultPlugin('eq5').params;

        Object.assign(plugin.params, defaults);
        updateEqGraphDom(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });
  }

  function delayTimeToX(value) {
    return 50 + (
      (Math.max(0.02, Math.min(1.5, value)) - 0.02) /
      (1.5 - 0.02)
    ) * 610;
  }

  function delayXToTime(x) {
    const ratio = Math.max(0, Math.min(1, (x - 50) / 610));
    return 0.02 + ratio * (1.5 - 0.02);
  }

  function delayFeedbackToY(value) {
    return 250 - (
      Math.max(0, Math.min(0.92, value)) / 0.92
    ) * 210;
  }

  function delayYToFeedback(y) {
    const ratio = Math.max(0, Math.min(1, (250 - y) / 210));
    return ratio * 0.92;
  }

  function delayMixToY(value) {
    return 250 - Math.max(0, Math.min(1, value)) * 210;
  }

  function delayYToMix(y) {
    return Math.max(0, Math.min(1, (250 - y) / 210));
  }

  function renderDelayGraph(plugin) {
    if (!pluginEditorControls) return;

    const timeX = delayTimeToX(plugin.params.time);
    const feedbackY = delayFeedbackToY(plugin.params.feedback);
    const mixY = delayMixToY(plugin.params.mix);

    pluginEditorControls.innerHTML = `
      <div class="daw-plugin-graph-shell">
        <div class="daw-plugin-graph-head">
          <div>
            <span>STEREO DELAY</span>
            <strong>Drag the echo node for time/feedback. Drag the wet node for mix.</strong>
          </div>
          <button type="button" class="daw-small-button" data-reset-delay>Reset</button>
        </div>

        <svg
          class="daw-plugin-graph daw-delay-graph"
          data-delay-graph
          viewBox="0 0 764 300"
          role="img"
          aria-label="Interactive delay graph"
        >
          <g class="daw-graph-grid">
            ${[0.02,0.25,0.5,0.75,1.0,1.25,1.5]
              .map(t => `
                <line x1="${delayTimeToX(t)}" y1="40" x2="${delayTimeToX(t)}" y2="250"></line>
                <text x="${delayTimeToX(t)}" y="280">${t.toFixed(t < 0.1 ? 2 : 1)}s</text>
              `).join('')}
            ${[0,0.23,0.46,0.69,0.92]
              .map(f => `
                <line x1="50" y1="${delayFeedbackToY(f)}" x2="660" y2="${delayFeedbackToY(f)}"></line>
                <text x="10" y="${delayFeedbackToY(f)+4}">${Math.round(f*100)}%</text>
              `).join('')}
          </g>

          <path
            class="daw-delay-tail"
            data-delay-tail
            d="M 50 250 C ${timeX*0.45} 250, ${timeX*0.75} ${feedbackY}, ${timeX} ${feedbackY}"
          ></path>

          ${[1,2,3,4].map(n => `
            <circle
              class="daw-delay-repeat"
              data-delay-repeat="${n}"
              cx="${Math.min(660, timeX + n * 55)}"
              cy="${Math.min(250, feedbackY + n * (250-feedbackY)/5)}"
              r="${Math.max(2.5, 7 - n)}"
            ></circle>
          `).join('')}

          <g
            class="daw-delay-node"
            data-delay-node
            tabindex="0"
            role="slider"
            aria-label="Delay time and feedback"
            aria-valuetext="${Number(plugin.params.time).toFixed(2)} seconds, ${Math.round(plugin.params.feedback*100)} percent feedback"
            transform="translate(${timeX} ${feedbackY})"
          >
            <circle class="daw-delay-node-halo" r="15"></circle>
            <circle class="daw-delay-node-core" r="8"></circle>
            <text x="0" y="-20">ECHO</text>
          </g>

          <g class="daw-delay-mix-axis">
            <line x1="700" y1="40" x2="700" y2="250"></line>
            <text x="700" y="280">WET</text>
          </g>

          <g
            class="daw-delay-node daw-delay-mix-node"
            data-delay-mix-node
            tabindex="0"
            role="slider"
            aria-label="Delay wet mix"
            aria-valuetext="${Math.round(plugin.params.mix*100)} percent wet"
            transform="translate(700 ${mixY})"
          >
            <circle class="daw-delay-node-halo" r="13"></circle>
            <circle class="daw-delay-node-core" r="7"></circle>
          </g>
        </svg>

        <div class="daw-delay-readouts">
          <button type="button" data-delay-focus="echo">
            <strong>TIME</strong>
            <span data-delay-time>${Number(plugin.params.time).toFixed(2)} s</span>
          </button>
          <button type="button" data-delay-focus="echo">
            <strong>FEEDBACK</strong>
            <span data-delay-feedback>${Math.round(plugin.params.feedback*100)}%</span>
          </button>
          <button type="button" data-delay-focus="mix">
            <strong>WET MIX</strong>
            <span data-delay-mix>${Math.round(plugin.params.mix*100)}%</span>
          </button>
        </div>
      </div>
    `;

    bindDelayGraph(plugin);
  }

  function updateDelayGraphDom(plugin) {
    const svg = pluginEditorControls?.querySelector('[data-delay-graph]');
    if (!svg) return;

    const timeX = delayTimeToX(plugin.params.time);
    const feedbackY = delayFeedbackToY(plugin.params.feedback);
    const mixY = delayMixToY(plugin.params.mix);

    const node = svg.querySelector('[data-delay-node]');
    const mixNode = svg.querySelector('[data-delay-mix-node]');

    node?.setAttribute(
      'transform',
      `translate(${timeX} ${feedbackY})`
    );
    node?.setAttribute(
      'aria-valuetext',
      `${Number(plugin.params.time).toFixed(2)} seconds, ${Math.round(plugin.params.feedback*100)} percent feedback`
    );

    mixNode?.setAttribute(
      'transform',
      `translate(700 ${mixY})`
    );
    mixNode?.setAttribute(
      'aria-valuetext',
      `${Math.round(plugin.params.mix*100)} percent wet`
    );

    svg.querySelector('[data-delay-tail]')?.setAttribute(
      'd',
      `M 50 250 C ${timeX*0.45} 250, ${timeX*0.75} ${feedbackY}, ${timeX} ${feedbackY}`
    );

    svg.querySelectorAll('[data-delay-repeat]')
      .forEach((circle,index) => {
        const n = index + 1;
        circle.setAttribute(
          'cx',
          Math.min(660, timeX + n * 55)
        );
        circle.setAttribute(
          'cy',
          Math.min(
            250,
            feedbackY + n * (250-feedbackY)/5
          )
        );
      });

    const timeReadout =
      pluginEditorControls.querySelector('[data-delay-time]');
    const feedbackReadout =
      pluginEditorControls.querySelector('[data-delay-feedback]');
    const mixReadout =
      pluginEditorControls.querySelector('[data-delay-mix]');

    if (timeReadout) {
      timeReadout.textContent =
        `${Number(plugin.params.time).toFixed(2)} s`;
    }
    if (feedbackReadout) {
      feedbackReadout.textContent =
        `${Math.round(plugin.params.feedback*100)}%`;
    }
    if (mixReadout) {
      mixReadout.textContent =
        `${Math.round(plugin.params.mix*100)}%`;
    }
  }

  function bindDelayGraph(plugin) {
    const svg = pluginEditorControls?.querySelector('[data-delay-graph]');
    if (!svg) return;

    const echoNode = svg.querySelector('[data-delay-node]');
    const mixNode = svg.querySelector('[data-delay-mix-node]');

    if (echoNode) {
      let pointerId = null;

      echoNode.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        pointerId = event.pointerId;
        echoNode.setPointerCapture(pointerId);
        echoNode.classList.add('dragging');
        event.preventDefault();
      });

      echoNode.addEventListener('pointermove', event => {
        if (event.pointerId !== pointerId) return;
        const point = svgPoint(event, svg);

        plugin.params.time = Math.round(
          delayXToTime(point.x) * 100
        ) / 100;
        plugin.params.feedback = Math.round(
          delayYToFeedback(point.y) * 100
        ) / 100;

        updateDelayGraphDom(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });

      const finish = event => {
        if (event.pointerId !== pointerId) return;
        try {
          echoNode.releasePointerCapture(pointerId);
        } catch (error) {}
        pointerId = null;
        echoNode.classList.remove('dragging');
      };

      echoNode.addEventListener('pointerup', finish);
      echoNode.addEventListener('pointercancel', finish);

      echoNode.addEventListener('keydown', event => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
          event.preventDefault();
          plugin.params.time = Math.max(
            0.02,
            Math.min(
              1.5,
              Number(plugin.params.time) +
              (event.key === 'ArrowRight' ? 0.01 : -0.01)
            )
          );
        } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
          event.preventDefault();
          plugin.params.feedback = Math.max(
            0,
            Math.min(
              0.92,
              Number(plugin.params.feedback) +
              (event.key === 'ArrowUp' ? 0.01 : -0.01)
            )
          );
        } else {
          return;
        }

        updateDelayGraphDom(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });
    }

    if (mixNode) {
      let pointerId = null;

      mixNode.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        pointerId = event.pointerId;
        mixNode.setPointerCapture(pointerId);
        mixNode.classList.add('dragging');
        event.preventDefault();
      });

      mixNode.addEventListener('pointermove', event => {
        if (event.pointerId !== pointerId) return;
        const point = svgPoint(event, svg);

        plugin.params.mix = Math.round(
          delayYToMix(point.y) * 100
        ) / 100;

        updateDelayGraphDom(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });

      const finish = event => {
        if (event.pointerId !== pointerId) return;
        try {
          mixNode.releasePointerCapture(pointerId);
        } catch (error) {}
        pointerId = null;
        mixNode.classList.remove('dragging');
      };

      mixNode.addEventListener('pointerup', finish);
      mixNode.addEventListener('pointercancel', finish);

      mixNode.addEventListener('keydown', event => {
        if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
          return;
        }

        event.preventDefault();
        plugin.params.mix = Math.max(
          0,
          Math.min(
            1,
            Number(plugin.params.mix) +
            (event.key === 'ArrowUp' ? 0.02 : -0.02)
          )
        );

        updateDelayGraphDom(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });
    }

    pluginEditorControls
      .querySelectorAll('[data-delay-focus]')
      .forEach(button => {
        button.addEventListener('click', () => {
          if (button.dataset.delayFocus === 'mix') {
            mixNode?.focus();
          } else {
            echoNode?.focus();
          }
        });
      });

    pluginEditorControls
      .querySelector('[data-reset-delay]')
      ?.addEventListener('click', () => {
        Object.assign(
          plugin.params,
          defaultPlugin('delay').params
        );
        updateDelayGraphDom(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });
  }

  function openPluginEditor(stemId, index) {
    const stem = stemById(stemId);
    const plugin = stem?.plugins?.[index];

    if (!stem || !plugin) return;

    pluginTargetStemId = stem.id;
    pluginEditIndex = index;

    if (pluginDirectoryTrack) {
      pluginDirectoryTrack.innerHTML = `
        <span>TRACK</span>
        <strong>${escapeHtml(stem.stem_name || stem.name || `Stem ${stem.id}`)}</strong>
      `;
    }

    if (pluginEditorTitle) {
      pluginEditorTitle.textContent = pluginLabel(plugin);
    }

    if (pluginBypassButton) {
      pluginBypassButton.textContent = plugin.enabled
        ? 'Bypass'
        : 'Enable';
      pluginBypassButton.classList.toggle(
        'active',
        !plugin.enabled
      );
    }

    if (plugin.type === 'eq5') {
      renderEqGraph(plugin);
    } else {
      renderDelayGraph(plugin);
    }

    document.getElementById('pluginDirectoryGrid')
      ?.setAttribute('hidden', '');

    if (pluginEditor) {
      pluginEditor.hidden = false;
    }

    openModal(pluginDirectoryDialog);
  }

  function addTrackPlugin(stem, type) {
    if (!stem || stem.plugins.length >= 4) return;

    stem.plugins.push(defaultPlugin(type));
    renderTrackPluginList(stem);

    if (context) {
      rebuildTrackPluginGraph(stem);
    }

    scheduleLocalSave(0);
    openPluginEditor(
      stem.id,
      stem.plugins.length - 1
    );
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
      !masterAnalyser ||
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
      masterAnalyser,
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
    masterGain.connect(masterAnalyser);

    masterAnalyser.connect(dryGain);
    dryGain.connect(context.destination);

    if (pluginState.reverb) {
      masterAnalyser.connect(reverb);
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

    masterAnalyser = context.createAnalyser();
    masterAnalyser.fftSize = 512;
    masterAnalyser.smoothingTimeConstant = 0.72;
    masterLevelData = new Uint8Array(masterAnalyser.fftSize);

    dryGain = context.createGain();
    wetGain = context.createGain();

    reverb = context.createConvolver();
    reverb.buffer = makeImpulse(context);

    stems.forEach(stem => {
      try {
        stem.sourceNode = context.createMediaElementSource(stem.audio);
        stem.gainNode = context.createGain();

        stem.analyserNode = context.createAnalyser();
        stem.analyserNode.fftSize = 1024;
        stem.analyserNode.minDecibels = -96;
        stem.analyserNode.maxDecibels = -18;
        stem.analyserNode.smoothingTimeConstant = 0.68;
        stem.frequencyData = new Uint8Array(
          stem.analyserNode.frequencyBinCount
        );

        stem.sourceNode.connect(stem.gainNode);

        if (context.createStereoPanner) {
          stem.panNode = context.createStereoPanner();
          stem.panNode.pan.value = Number(stem.pan?.value || 0);
          stem.gainNode.connect(stem.panNode);
        }

        stem.analyserNode.connect(busInput);
        rebuildTrackPluginGraph(stem);
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
        !stem.frequencyData ||
        !context
      ) {
        stem.eqBars.forEach(bar => {
          bar.style.setProperty('--eq-scale', '0.035');
          bar.style.setProperty('--eq-peak', '0');
        });
        return;
      }

      stem.analyserNode.getByteFrequencyData(stem.frequencyData);

      const data = stem.frequencyData;
      const nyquist = context.sampleRate / 2;
      const binHz = nyquist / data.length;

      stem.eqBars.forEach(bar => {
        const center = Number(
          bar.dataset.frequency || 0
        );

        if (!center || !Number.isFinite(center)) {
          bar.style.setProperty('--eq-scale', '0.035');
          return;
        }

        // One-octave-ish display band centered on the labeled frequency.
        const lowerHz = Math.max(
          20,
          center / Math.SQRT2
        );
        const upperHz = Math.min(
          nyquist,
          center * Math.SQRT2
        );

        const from = Math.max(
          0,
          Math.floor(lowerHz / binHz)
        );
        const to = Math.min(
          data.length - 1,
          Math.ceil(upperHz / binHz)
        );

        let sum = 0;
        let peak = 0;
        let count = 0;

        for (let i = from; i <= to; i++) {
          const value = data[i] / 255;
          sum += value * value;
          peak = Math.max(peak, value);
          count++;
        }

        const rms = count
          ? Math.sqrt(sum / count)
          : 0;

        // Blend RMS and peak so quiet tracks still visibly move without
        // pinning every band at full scale.
        const energy = Math.max(
          rms * 1.55,
          peak * 0.72
        );

        const scale = Math.max(
          0.035,
          Math.min(
            1,
            Math.pow(energy, 0.78)
          )
        );

        bar.style.setProperty(
          '--eq-scale',
          scale.toFixed(3)
        );

        bar.style.setProperty(
          '--eq-peak',
          peak.toFixed(3)
        );
      });
    });
  }

  function updateMasterMeter(reset = false) {
    if (!masterMeterBars.length) return;

    if (
      reset ||
      !masterAnalyser ||
      !masterLevelData
    ) {
      masterMeterBars.forEach(bar => {
        bar.style.setProperty('--master-level', '2%');
      });
      return;
    }

    masterAnalyser.getByteTimeDomainData(masterLevelData);

    let sum = 0;

    for (let i = 0; i < masterLevelData.length; i++) {
      const sample = (masterLevelData[i] - 128) / 128;
      sum += sample * sample;
    }

    const rms = Math.sqrt(
      sum / Math.max(1, masterLevelData.length)
    );

    const level = Math.max(
      0.02,
      Math.min(1, rms * 3.8)
    );

    const percent = `${(level * 100).toFixed(1)}%`;

    masterMeterBars.forEach(bar => {
      bar.style.setProperty('--master-level', percent);
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
    updateMasterMeter(false);

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
    updateMasterMeter(true);

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
        solo: Boolean(stem.solo),
        plugins: stem.plugins.map(plugin => ({
          type: plugin.type,
          enabled: plugin.enabled !== false,
          params: {...plugin.params}
        }))
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
      stem.plugins = normalizeTrackPlugins(mix.plugins);
      renderTrackPluginList(stem);

      if (context) {
        rebuildTrackPluginGraph(stem);
      }

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
      mixSaveDialog?.hidden !== false &&
      pluginDirectoryDialog?.hidden !== false
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

  // ---------------------------------------------------------
  // Pull-up per-track plugin rack.
  // ---------------------------------------------------------
  if (pluginRackHandle) {
    let rackPointerId = null;
    let rackStartY = 0;
    let rackStartHeight = 348;
    let rackMoved = false;

    pluginRackHandle.addEventListener('pointerdown', event => {
      if (event.button !== 0) return;

      rackPointerId = event.pointerId;
      rackStartY = event.clientY;
      rackStartHeight = pluginRackOpen
        ? pluginRackHeight
        : 348;
      rackMoved = false;

      pluginRackHandle.setPointerCapture(rackPointerId);
      pluginRackHandle.classList.add('dragging-rack');
      event.preventDefault();
    });

    pluginRackHandle.addEventListener('pointermove', event => {
      if (event.pointerId !== rackPointerId) return;

      const delta = rackStartY - event.clientY;

      if (Math.abs(delta) > 4) {
        rackMoved = true;
      }

      const nextHeight = Math.max(
        320,
        Math.min(560, rackStartHeight + delta)
      );

      pluginRackOpen = nextHeight >= 392;
      setPluginRack(
        pluginRackOpen,
        nextHeight,
        false
      );
    });

    const finishRackDrag = event => {
      if (event.pointerId !== rackPointerId) return;

      try {
        pluginRackHandle.releasePointerCapture(rackPointerId);
      } catch (error) {}

      rackPointerId = null;
      pluginRackHandle.classList.remove('dragging-rack');

      if (rackMoved) {
        setPluginRack(
          pluginRackOpen,
          pluginRackOpen
            ? Math.max(450, pluginRackHeight)
            : 348
        );
      }
    };

    pluginRackHandle.addEventListener('pointerup', finishRackDrag);
    pluginRackHandle.addEventListener('pointercancel', finishRackDrag);

    pluginRackHandle.addEventListener('click', () => {
      if (rackMoved) {
        rackMoved = false;
        return;
      }

      setPluginRack(
        !pluginRackOpen,
        pluginRackOpen ? 348 : 470
      );
    });
  }

  stems.forEach(stem => {
    renderTrackPluginList(stem);

    stem.addPluginButton?.addEventListener('click', event => {
      event.stopPropagation();
      openPluginDirectory(stem.id);
    });
  });

  document.querySelectorAll('[data-plugin-type]')
    .forEach(button => {
      button.addEventListener('click', () => {
        const stem = stemById(pluginTargetStemId);
        const type = button.dataset.pluginType;

        if (!stem || !['eq5','delay'].includes(type)) return;

        addTrackPlugin(stem, type);
      });
    });

  document.querySelectorAll('[data-close-plugin-directory]')
    .forEach(button => {
      button.addEventListener(
        'click',
        () => closeModal(pluginDirectoryDialog)
      );
    });

  pluginBypassButton?.addEventListener('click', () => {
    const stem = stemById(pluginTargetStemId);
    const plugin = stem?.plugins?.[pluginEditIndex];

    if (!stem || !plugin) return;

    plugin.enabled = !plugin.enabled;

    renderTrackPluginList(stem);
    rebuildTrackPluginGraph(stem);
    openPluginEditor(stem.id, pluginEditIndex);
    scheduleLocalSave();
  });

  pluginRemoveButton?.addEventListener('click', () => {
    const stem = stemById(pluginTargetStemId);

    if (!stem || pluginEditIndex < 0) return;

    stem.plugins.splice(pluginEditIndex, 1);
    pluginEditIndex = -1;

    renderTrackPluginList(stem);
    rebuildTrackPluginGraph(stem);
    scheduleLocalSave(0);
    closeModal(pluginDirectoryDialog);
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
      closeModal(pluginDirectoryDialog);
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

  setPluginRack(false, 348, false);
  resizeTimelineSurface();
  clearLoop();
  updatePlayhead(0);
  updateEqDisplays(true);
  updateMasterMeter(true);
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
