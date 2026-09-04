(() => {
  'use strict';

  const BUILD = 'voice-continuity-v114-20260825';
  const cfg = window.STONEFELLOW_CHAT || {};
  const voiceKey = `stonefellow:voice-mode:${Number(cfg.userId || 0)}`;
  const button = document.getElementById('chatVoiceButton');
  const status = document.getElementById('chatVoiceStatus');
  const proof = window.STONEFELLOW_CHAT_VOICE_V114 = {
    build: BUILD,
    loaded: true,
    elevenLabsRequests: 0,
    elevenLabsStarts: 0,
    elevenLabsEnds: 0,
    browserFallbacks: 0,
    bargeStreams: 0,
    bargeGateOpens: 0,
    persistedVoice: false,
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

  function persistVoice(on) {
    proof.persistedVoice = !!on;
    try { localStorage.setItem(voiceKey, on ? '1' : '0'); } catch (error) {}
  }

  // Preserve the original chat.js LISTEN owner, but make the optional barge-in
  // stream resistant to Stonefellow's own speaker output. The first part of
  // each spoken response is used to learn the local speaker/room bleed level;
  // only substantially stronger microphone energy is passed to chat.js.
  const mediaDevices = navigator.mediaDevices || null;
  const nativeGetUserMedia = mediaDevices?.getUserMedia?.bind(mediaDevices) || null;
  let outputPlaying = false;
  let outputStartedAt = 0;
  const gateCleanups = new Set();

  function isLegacyBargeRequest(constraints) {
    const audio = constraints?.audio;
    return constraints?.video === false && audio && typeof audio === 'object' &&
      audio.echoCancellation === true && audio.noiseSuppression === true && audio.autoGainControl === true;
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

        // Learn speaker bleed for a short grace window before allowing a user
        // interruption. This prevents Stonefellow's first syllables from
        // cancelling the same response.
        if (elapsed < 700) {
          floor = Math.max(.012, floor * .78 + rms * .22);
          gain.gain.value = 0;
          openFrames = 0;
          return;
        }

        const threshold = Math.max(.09, floor * 1.55 + .02);
        if (rms >= threshold) {
          openFrames += 1;
          if (openFrames >= 2) {
            gain.gain.value = 1;
            proof.bargeGateOpens += 1;
          }
        } else {
          openFrames = 0;
          gain.gain.value = 0;
          // Adapt slowly to continuing speaker bleed/room noise, but do not
          // rapidly absorb a user's interruption into the noise floor.
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
  if (synth && window.SpeechSynthesisUtterance && cfg.endpoint && cfg.csrf) {
    const nativeSpeak = synth.speak.bind(synth);
    const nativeCancel = synth.cancel.bind(synth);
    let controller = null;
    let audio = null;
    let objectUrl = '';
    let generation = 0;

    function voiceEndpoint() {
      try {
        return new URL('agent-voice-v102.php', new URL(String(cfg.endpoint), window.location.href)).toString();
      } catch (error) {
        return '';
      }
    }

    function cleanupPremium() {
      if (audio) {
        audio.onplay = null;
        audio.onended = null;
        audio.onerror = null;
        try { audio.pause(); audio.currentTime = 0; } catch (error) {}
        audio = null;
      }
      if (objectUrl) {
        try { URL.revokeObjectURL(objectUrl); } catch (error) {}
        objectUrl = '';
      }
      controller = null;
      outputPlaying = false;
    }

    function cancelPremium() {
      generation += 1;
      if (controller) {
        try { controller.abort(); } catch (error) {}
      }
      cleanupPremium();
    }

    function fallbackToBrowser(utterance, error, localGeneration) {
      if (localGeneration !== generation) return;
      proof.browserFallbacks += 1;
      proof.lastError = String(error?.message || error || 'ElevenLabs unavailable');
      cleanupPremium();
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
      outputPlaying = false;
      cancelPremium();
      try { nativeCancel(); } catch (error) {}
    };

    synth.speak = function stonefellowSpeak(utterance) {
      const text = String(utterance?.text || '').trim();
      const endpoint = voiceEndpoint();
      if (!text || !endpoint) {
        nativeSpeak(utterance);
        return;
      }

      cancelPremium();
      const localGeneration = generation;
      controller = new AbortController();
      const localController = controller;
      proof.elevenLabsRequests += 1;
      setStatus('Preparing voice…', 'processing');

      fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'audio/mpeg' },
        body: JSON.stringify({ csrf_token: String(cfg.csrf), text }),
        signal: localController.signal
      })
        .then(async response => {
          if (!response.ok) {
            let detail = '';
            try { detail = (await response.json())?.error || ''; } catch (error) {}
            throw new Error(detail || `ElevenLabs voice HTTP ${response.status}`);
          }
          const blob = await response.blob();
          if (!blob.size) throw new Error('ElevenLabs returned empty audio.');
          if (localGeneration !== generation || localController.signal.aborted) return;

          controller = null;
          objectUrl = URL.createObjectURL(blob);
          audio = new Audio(objectUrl);
          audio.preload = 'auto';
          audio.onplay = () => {
            if (localGeneration !== generation) return;
            outputPlaying = true;
            outputStartedAt = performance.now();
            proof.elevenLabsStarts += 1;
            setStatus('Stonefellow is responding…', 'speaking');
            try { utterance.onstart?.(new Event('start')); } catch (error) {}
          };
          audio.onended = () => {
            if (localGeneration !== generation) return;
            proof.elevenLabsEnds += 1;
            outputPlaying = false;
            cleanupPremium();
            try { utterance.onend?.(new Event('end')); } catch (error) {}
          };
          audio.onerror = () => fallbackToBrowser(utterance, new Error('ElevenLabs audio playback failed.'), localGeneration);
          return audio.play().catch(error => fallbackToBrowser(utterance, error, localGeneration));
        })
        .catch(error => {
          if (error?.name === 'AbortError' || localGeneration !== generation) return;
          fallbackToBrowser(utterance, error, localGeneration);
        });
    };
  }

  // Shared LISTEN state: Chat owns its microphone implementation, but the on/off
  // choice follows the user into Stem Studio and Video Editor.
  if (button) {
    proof.persistedVoice = storedVoice();
    button.addEventListener('click', () => {
      queueMicrotask(() => persistVoice(button.getAttribute('aria-pressed') === 'true'));
    });
    if (proof.persistedVoice && button.getAttribute('aria-pressed') !== 'true') {
      window.setTimeout(() => {
        if (button.getAttribute('aria-pressed') !== 'true') button.click();
      }, 100);
    }
  }

  window.addEventListener('pagehide', () => {
    gateCleanups.forEach(cleanup => { try { cleanup(); } catch (error) {} });
    gateCleanups.clear();
  }, { once: true });
})();
