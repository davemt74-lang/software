(() => {
  'use strict';

  const BUILD = 'conversation-engine-v120-20260825';
  const cfg = window.STONEFELLOW_CHAT || {};
  const userId = Number(cfg.userId || 0);
  const voiceKey = `stonefellow:voice-mode:${userId}`;
  const button = document.getElementById('chatVoiceButton');
  const status = document.getElementById('chatVoiceStatus');
  const proof = window.STONEFELLOW_CHAT_VOICE_V117 = {
    build: BUILD,
    loaded: true,
    elevenLabsRequests: 0,
    elevenLabsStarts: 0,
    elevenLabsEnds: 0,
    browserFallbacks: 0,
    bargeStreams: 0,
    bargeGateOpens: 0,
    returnBriefingExpansions: 0,
    persistedVoice: false,
    streaming: false,
    lastError: ''
  };

  function setStatus(text, state = '') {
    if (!status) return;
    status.hidden = !text;
    status.textContent = text;
    status.dataset.state = state;
  }

  function storedVoice() {
    try { return localStorage.getItem(voiceKey) === '1'; } catch (error) { return false; }
  }

  function persistVoice(on, source = 'chat') {
    proof.persistedVoice = !!on;
    try { localStorage.setItem(voiceKey, on ? '1' : '0'); } catch (error) {}
    window.dispatchEvent(new CustomEvent('stonefellow:voice-mode', {
      detail: { enabled: !!on, userId, source, build: BUILD }
    }));
  }

  function returnBriefingSpeech(text) {
    const intro = cfg.intro && typeof cfg.intro === 'object' ? cfg.intro : null;
    const greeting = String(intro?.greeting || '').trim();
    if (!greeting || text !== greeting) return text;

    const updates = Array.isArray(intro?.updates) ? intro.updates : [];
    if (!updates.length) return text;

    const spokenItems = updates
      .map(update => {
        const title = String(update?.title || 'Update').trim();
        const body = String(update?.body || '').trim();
        return body ? `${title}. ${body}` : title;
      })
      .filter(Boolean);

    if (!spokenItems.length) return text;
    proof.returnBriefingExpansions += 1;
    return `${text} Here are the priorities I found. ${spokenItems.join(' ')}`;
  }

  function spokenUtterance(original, text) {
    if (!original || String(original.text || '').trim() === text) return original;
    const expanded = new SpeechSynthesisUtterance(text);
    expanded.rate = Number(original.rate || 1);
    expanded.pitch = Number(original.pitch || 1);
    expanded.volume = Number(original.volume ?? 1);
    expanded.lang = String(original.lang || '');
    if (original.voice) expanded.voice = original.voice;
    expanded.onstart = original.onstart;
    expanded.onend = original.onend;
    expanded.onerror = original.onerror;
    expanded.onmark = original.onmark;
    expanded.onboundary = original.onboundary;
    expanded.onpause = original.onpause;
    expanded.onresume = original.onresume;
    return expanded;
  }

  // The original Chat LISTEN owner asks for exactly this barge-in capture.
  // Route only that one constraint shape through an adaptive echo gate. Media
  // recording and the device meter use different constraints and are untouched.
  const mediaDevices = navigator.mediaDevices || null;
  const nativeGetUserMedia = mediaDevices?.getUserMedia?.bind(mediaDevices) || null;
  let outputPlaying = false;
  let outputStartedAt = 0;
  const gateCleanups = new Set();

  function isLegacyBargeRequest(constraints) {
    const audio = constraints?.audio;
    return constraints?.video === false && audio && typeof audio === 'object' &&
      audio.echoCancellation === true && audio.noiseSuppression === true && audio.autoGainControl === true &&
      !audio.deviceId;
  }

  async function adaptiveBargeStream(constraints) {
    const raw = await nativeGetUserMedia(constraints);
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextCtor) return raw;

    try {
      const context = new AudioContextCtor();
      const source = context.createMediaStreamSource(raw);
      const analyser = context.createAnalyser();
      analyser.fftSize = 1024;
      analyser.smoothingTimeConstant = .55;
      const gain = context.createGain();
      const destination = context.createMediaStreamDestination();
      source.connect(analyser);
      source.connect(gain);
      gain.connect(destination);
      const samples = new Uint8Array(analyser.fftSize);
      let floor = .018;
      let openFrames = 0;
      let closed = false;

      const timer = window.setInterval(() => {
        if (closed) return;
        if (!outputPlaying) {
          gain.gain.value = 1;
          openFrames = 0;
          floor = Math.max(.012, floor * .985);
          return;
        }

        analyser.getByteTimeDomainData(samples);
        let sum = 0;
        for (const value of samples) {
          const normalized = (value - 128) / 128;
          sum += normalized * normalized;
        }
        const rms = Math.sqrt(sum / Math.max(1, samples.length));
        const elapsed = performance.now() - outputStartedAt;

        if (elapsed < 750) {
          floor = Math.max(.012, floor * .78 + rms * .22);
          gain.gain.value = 0;
          openFrames = 0;
          return;
        }

        const threshold = Math.max(.09, floor * 1.6 + .018);
        if (rms >= threshold) {
          openFrames += 1;
          if (openFrames >= 2) {
            gain.gain.value = 1;
            proof.bargeGateOpens += 1;
          }
        } else {
          openFrames = 0;
          gain.gain.value = 0;
          floor = Math.max(.012, floor * .965 + rms * .035);
        }
      }, 50);

      const processed = destination.stream;
      const outputTracks = processed.getTracks();
      const nativeTrackStops = outputTracks.map(track => track.stop.bind(track));
      const cleanup = () => {
        if (closed) return;
        closed = true;
        clearInterval(timer);
        try { source.disconnect(); } catch (error) {}
        try { gain.disconnect(); } catch (error) {}
        raw.getTracks().forEach(track => { try { track.stop(); } catch (error) {} });
        try { context.close(); } catch (error) {}
        gateCleanups.delete(cleanup);
      };
      gateCleanups.add(cleanup);

      outputTracks.forEach((track, index) => {
        try {
          track.stop = () => {
            try { nativeTrackStops[index](); } catch (error) {}
            cleanup();
          };
        } catch (error) {}
      });
      proof.bargeStreams += 1;
      return processed;
    } catch (error) {
      proof.lastError = String(error?.message || error || 'Adaptive barge stream failed');
      return raw;
    }
  }

  if (mediaDevices && nativeGetUserMedia) {
    const routedGetUserMedia = constraints => isLegacyBargeRequest(constraints)
      ? adaptiveBargeStream(constraints)
      : nativeGetUserMedia(constraints);
    try {
      mediaDevices.getUserMedia = routedGetUserMedia;
    } catch (error) {
      try {
        Object.defineProperty(mediaDevices, 'getUserMedia', { configurable: true, value: routedGetUserMedia });
      } catch (defineError) {
        proof.lastError = 'Could not install adaptive barge-in gate.';
      }
    }
  }

  const synth = window.speechSynthesis || null;
  const premiumVoice = window.StonefellowPremiumVoiceV117?.({ agentEndpoint: cfg.endpoint, csrf: cfg.csrf }) || null;

  if (synth && window.SpeechSynthesisUtterance && cfg.endpoint && cfg.csrf) {
    const nativeSpeak = synth.speak.bind(synth);
    const nativeCancel = synth.cancel.bind(synth);
    let generation = 0;
    let premiumStarted = false;

    function cancelPremium() {
      generation += 1;
      premiumStarted = false;
      outputPlaying = false;
      proof.streaming = false;
      try { premiumVoice?.stop(); } catch (error) {}
    }

    function fallbackToBrowser(utterance, error, localGeneration) {
      if (localGeneration !== generation) return;
      proof.browserFallbacks += 1;
      proof.lastError = String(error?.message || error || 'ElevenLabs unavailable');
      premiumStarted = false;
      outputPlaying = false;
      proof.streaming = false;
      try { premiumVoice?.stop(); } catch (stopError) {}
      const originalStart = utterance.onstart;
      const originalEnd = utterance.onend;
      const originalError = utterance.onerror;
      utterance.onstart = event => {
        outputPlaying = true;
        outputStartedAt = performance.now();
        setStatus('Stonefellow is responding…', 'speaking');
        try { originalStart?.call(utterance, event); } catch (callbackError) {}
      };
      utterance.onend = event => {
        outputPlaying = false;
        try { originalEnd?.call(utterance, event); } catch (callbackError) {}
      };
      utterance.onerror = event => {
        outputPlaying = false;
        try { originalError?.call(utterance, event); } catch (callbackError) {}
      };
      try { nativeSpeak(utterance); }
      catch (nativeError) {
        outputPlaying = false;
        proof.lastError = String(nativeError?.message || nativeError || proof.lastError);
        try { originalError?.call(utterance, new Event('error')); } catch (eventError) {}
      }
    }

    synth.cancel = function stonefellowCancel() {
      cancelPremium();
      try { nativeCancel(); } catch (error) {}
    };

    synth.speak = function stonefellowSpeak(utterance) {
      const sourceText = String(utterance?.text || '').trim();
      const returnGreeting = String(cfg.intro?.greeting || '').trim();
      const voiceEnabled = button?.getAttribute('aria-pressed') === 'true' || storedVoice();
      if (returnGreeting && sourceText === returnGreeting && !voiceEnabled) return;
      const text = returnBriefingSpeech(sourceText);
      const spoken = spokenUtterance(utterance, text);
      if (!text || !premiumVoice) {
        nativeSpeak(spoken);
        return;
      }

      cancelPremium();
      const localGeneration = generation;
      proof.elevenLabsRequests += 1;
      proof.streaming = true;
      setStatus('Preparing voice…', 'processing');

      premiumVoice.speak(text, {
        onStart: () => {
          if (localGeneration !== generation) return;
          premiumStarted = true;
          outputPlaying = true;
          outputStartedAt = performance.now();
          proof.elevenLabsStarts += 1;
          setStatus('Stonefellow is responding…', 'speaking');
          try { spoken.onstart?.(new Event('start')); } catch (error) {}
        },
        onEnd: () => {
          if (localGeneration !== generation) return;
          proof.elevenLabsEnds += 1;
          premiumStarted = false;
          outputPlaying = false;
          proof.streaming = false;
          try { spoken.onend?.(new Event('end')); } catch (error) {}
        },
        onError: error => {
          if (localGeneration !== generation) return;
          if (!premiumStarted) {
            fallbackToBrowser(spoken, error, localGeneration);
            return;
          }
          premiumStarted = false;
          outputPlaying = false;
          proof.streaming = false;
          proof.lastError = String(error?.message || error || 'Streaming voice interrupted');
          try { spoken.onerror?.(new Event('error')); } catch (callbackError) {}
        }
      }).catch(error => {
        if (error?.name === 'AbortError' || localGeneration !== generation) return;
        if (!premiumStarted) fallbackToBrowser(spoken, error, localGeneration);
      });
    };
  }

  if (button) {
    proof.persistedVoice = storedVoice();
    const syncFromButton = source => persistVoice(button.getAttribute('aria-pressed') === 'true', source);
    button.addEventListener('click', () => {
      queueMicrotask(() => syncFromButton('chat-click'));
      if (button.getAttribute('aria-pressed') === 'true') void premiumVoice?.warm?.();
    });

    // Legacy recognition can turn LISTEN off after a fatal recognition error.
    // Observe that state change so stale localStorage cannot reactivate LISTEN
    // on the next Studio/Video page.
    const stateObserver = new MutationObserver(() => queueMicrotask(() => syncFromButton('chat-state')));
    stateObserver.observe(button, { attributes: true, attributeFilter: ['aria-pressed'] });

    if (proof.persistedVoice && button.getAttribute('aria-pressed') !== 'true') {
      window.setTimeout(() => {
        if (button.getAttribute('aria-pressed') !== 'true') button.click();
      }, 100);
    } else if (proof.persistedVoice) {
      void premiumVoice?.warm?.();
      persistVoice(true, 'chat-restore');
    }

    window.addEventListener('pagehide', () => stateObserver.disconnect(), { once: true });
  }

  window.addEventListener('pagehide', () => {
    try { premiumVoice?.stop(); } catch (error) {}
    gateCleanups.forEach(cleanup => { try { cleanup(); } catch (error) {} });
    gateCleanups.clear();
  }, { once: true });
})();
