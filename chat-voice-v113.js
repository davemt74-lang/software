(() => {
  'use strict';

  const BUILD = 'elevenlabs-output-v113-20260825';
  const cfg = window.STONEFELLOW_CHAT || {};
  const proof = window.STONEFELLOW_CHAT_VOICE_V113 = {
    build: BUILD,
    loaded: true,
    elevenLabsRequests: 0,
    elevenLabsStarts: 0,
    elevenLabsEnds: 0,
    browserFallbacks: 0,
    bargeCaptureSuppressed: 0,
    lastError: ''
  };

  // Agent Chat LISTEN is owned exclusively by chat.js. The old barge-in
  // analyser opened a second microphone capture while Stonefellow spoke and
  // could hear speaker bleed, causing Stonefellow to interrupt himself.
  // Suppress only that exact optional capture shape; SpeechRecognition and
  // all other camera/microphone requests continue to use the native API.
  const mediaDevices = navigator.mediaDevices || null;
  const nativeGetUserMedia = mediaDevices?.getUserMedia?.bind(mediaDevices) || null;

  if (mediaDevices && nativeGetUserMedia) {
    const isolatedGetUserMedia = constraints => {
      const audio = constraints?.audio;
      const isLegacyBargeCapture =
        constraints?.video === false &&
        audio &&
        typeof audio === 'object' &&
        audio.echoCancellation === true &&
        audio.noiseSuppression === true &&
        audio.autoGainControl === true;

      if (isLegacyBargeCapture) {
        proof.bargeCaptureSuppressed += 1;
        return Promise.reject(
          new DOMException(
            'Stonefellow output isolation: optional barge-in capture disabled.',
            'AbortError'
          )
        );
      }

      return nativeGetUserMedia(constraints);
    };

    try {
      mediaDevices.getUserMedia = isolatedGetUserMedia;
    } catch (error) {
      try {
        Object.defineProperty(mediaDevices, 'getUserMedia', {
          configurable: true,
          value: isolatedGetUserMedia
        });
      } catch (defineError) {
        proof.lastError = 'Could not isolate legacy barge-in capture.';
      }
    }
  }

  const synth = window.speechSynthesis || null;
  if (!synth || !window.SpeechSynthesisUtterance || !cfg.endpoint || !cfg.csrf) {
    return;
  }

  const nativeSpeak = synth.speak.bind(synth);
  const nativeCancel = synth.cancel.bind(synth);
  let controller = null;
  let audio = null;
  let objectUrl = '';
  let activeUtterance = null;
  let generation = 0;

  function voiceEndpoint() {
    try {
      return new URL(
        'agent-voice-v102.php',
        new URL(String(cfg.endpoint), window.location.href)
      ).toString();
    } catch (error) {
      return '';
    }
  }

  function cleanupPremium() {
    if (audio) {
      audio.onplay = null;
      audio.onended = null;
      audio.onerror = null;
      try {
        audio.pause();
        audio.currentTime = 0;
      } catch (error) {}
      audio = null;
    }
    if (objectUrl) {
      try { URL.revokeObjectURL(objectUrl); } catch (error) {}
      objectUrl = '';
    }
    controller = null;
    activeUtterance = null;
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
    try {
      nativeSpeak(utterance);
    } catch (nativeError) {
      proof.lastError = String(nativeError?.message || nativeError || proof.lastError);
      try { utterance.onerror?.(new Event('error')); } catch (eventError) {}
    }
  }

  synth.cancel = function stonefellowCancel() {
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
    activeUtterance = utterance;
    controller = new AbortController();
    const localController = controller;
    proof.elevenLabsRequests += 1;

    fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'audio/mpeg'
      },
      body: JSON.stringify({
        csrf_token: String(cfg.csrf),
        text
      }),
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
          proof.elevenLabsStarts += 1;
          try { utterance.onstart?.(new Event('start')); } catch (error) {}
        };
        audio.onended = () => {
          if (localGeneration !== generation) return;
          proof.elevenLabsEnds += 1;
          cleanupPremium();
          try { utterance.onend?.(new Event('end')); } catch (error) {}
        };
        audio.onerror = () => {
          fallbackToBrowser(utterance, new Error('ElevenLabs audio playback failed.'), localGeneration);
        };
        return audio.play().catch(error => {
          fallbackToBrowser(utterance, error, localGeneration);
        });
      })
      .catch(error => {
        if (error?.name === 'AbortError' || localGeneration !== generation) return;
        fallbackToBrowser(utterance, error, localGeneration);
      });
  };
})();
