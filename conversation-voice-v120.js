(() => {
  'use strict';

  const BUILD = 'conversation-engine-v120-20260825';

  function sharedKey(userId) {
    return `stonefellow:voice-mode:${Number(userId || 0)}`;
  }

  function readShared(userId) {
    try { return localStorage.getItem(sharedKey(userId)) === '1'; }
    catch (error) { return false; }
  }

  function writeShared(userId, enabled, source = 'editor') {
    try { localStorage.setItem(sharedKey(userId), enabled ? '1' : '0'); } catch (error) {}
    window.dispatchEvent(new CustomEvent('stonefellow:voice-mode', {
      detail: { enabled: !!enabled, userId: Number(userId || 0), source, build: BUILD }
    }));
  }

  function recognitionErrorMessage(error) {
    switch (String(error || '')) {
      case 'not-allowed': return 'Voice input is unavailable for this page.';
      case 'service-not-allowed': return 'The browser speech-recognition service is unavailable.';
      case 'audio-capture': return 'No usable audio input is available.';
      case 'network': return 'Voice recognition lost its network service. Reconnecting…';
      case 'no-speech': return 'Listening…';
      case 'aborted': return '';
      default: return 'Voice input paused. Reconnecting…';
    }
  }

  function create(options = {}) {
    const userId = Number(options.userId || 0);
    const source = String(options.source || 'editor');
    const SpeechRecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition || null;
    const premiumVoice = window.StonefellowAgentVoiceV102?.({
      agentEndpoint: options.agentEndpoint,
      csrf: options.csrf
    }) || null;

    let enabled = !!options.initialEnabled || readShared(userId);
    let recognition = null;
    let listening = false;
    let speaking = false;
    let preparing = false;
    let destroyed = false;
    let restartTimer = 0;
    let generation = 0;

    const proof = {
      build: BUILD,
      source,
      recognitionStarts: 0,
      recognitionErrors: 0,
      premiumStarts: 0,
      browserFallbacks: 0,
      interruptions: 0,
      enabled
    };

    const setState = (state, text = '') => {
      try { options.onState?.(state, text); } catch (error) {}
    };
    const busy = () => !!options.isBusy?.();

    const barge = window.StonefellowEditorVoiceBarge?.({
      isSpeaking: () => speaking,
      interrupt: () => {
        if (!speaking || destroyed) return;
        proof.interruptions += 1;
        generation += 1;
        try { premiumVoice?.stop(); } catch (error) {}
        try { window.speechSynthesis?.cancel(); } catch (error) {}
        try { barge?.release(); } catch (error) {}
        preparing = false;
        speaking = false;
        setState('listening', 'Interrupted · listening…');
        scheduleRecognition(55);
      }
    }) || null;

    function clearRestart() {
      if (restartTimer) clearTimeout(restartTimer);
      restartTimer = 0;
    }

    function stopRecognition(update = false) {
      clearRestart();
      const active = recognition;
      recognition = null;
      listening = false;
      try { active?.stop(); } catch (error) {}
      if (update && enabled && !busy() && !speaking && !preparing) setState('ready', 'Voice ready');
    }

    function scheduleRecognition(delay = 140) {
      clearRestart();
      if (!enabled || destroyed || busy() || speaking || preparing || recognition) return;
      restartTimer = window.setTimeout(startRecognition, Math.max(25, delay));
    }

    function startRecognition() {
      clearRestart();
      if (!enabled || destroyed || busy() || speaking || preparing || recognition) return;
      if (!SpeechRecognitionCtor) {
        setState('unavailable', 'Live speech recognition is not supported by this browser.');
        return;
      }

      const current = new SpeechRecognitionCtor();
      recognition = current;
      current.lang = options.language || document.documentElement.lang || 'en-US';
      current.continuous = false;
      current.interimResults = true;
      let finalText = '';

      current.onstart = () => {
        if (recognition !== current) return;
        listening = true;
        proof.recognitionStarts += 1;
        setState('listening', 'Listening…');
      };

      current.onresult = event => {
        if (recognition !== current) return;
        let interim = '';
        for (let i = event.resultIndex; i < event.results.length; i += 1) {
          const transcript = String(event.results[i][0]?.transcript || '');
          if (event.results[i].isFinal) finalText += transcript;
          else interim += transcript;
        }
        const heard = (finalText || interim).trim();
        if (heard) setState('listening', interim ? `Listening · ${interim.trim()}` : 'Listening…');
        if (!finalText.trim()) return;
        const spoken = finalText.trim();
        finalText = '';
        recognition = null;
        listening = false;
        try { current.stop(); } catch (error) {}
        setState('processing', 'Thinking…');
        try {
          const result = options.onTranscript?.(spoken);
          Promise.resolve(result).catch(error => {
            try { options.onError?.(error); } catch (callbackError) {}
            scheduleRecognition(300);
          });
        } catch (error) {
          try { options.onError?.(error); } catch (callbackError) {}
          scheduleRecognition(300);
        }
      };

      current.onerror = event => {
        if (recognition === current) recognition = null;
        listening = false;
        proof.recognitionErrors += 1;
        const kind = String(event?.error || 'unknown');
        const message = recognitionErrorMessage(kind);
        if (kind === 'not-allowed' || kind === 'service-not-allowed') {
          enabled = false;
          proof.enabled = false;
          writeShared(userId, false, source);
          try { barge?.release(); } catch (error) {}
          setState('error', message);
          try { options.onVoiceChange?.(false); } catch (error) {}
          return;
        }
        if (message) setState(kind === 'no-speech' ? 'listening' : 'ready', message);
        if (enabled && !busy() && !speaking && !preparing && kind !== 'aborted') scheduleRecognition(kind === 'network' ? 800 : 420);
      };

      current.onend = () => {
        if (recognition === current) recognition = null;
        listening = false;
        if (enabled && !busy() && !speaking && !preparing) scheduleRecognition(170);
      };

      try { current.start(); }
      catch (error) {
        if (recognition === current) recognition = null;
        listening = false;
        scheduleRecognition(350);
      }
    }

    function armBarge(localGeneration) {
      if (!barge) return;
      Promise.resolve(barge.ensure()).then(() => {
        if (localGeneration !== generation || destroyed || !speaking) {
          try { barge.release(); } catch (error) {}
          return;
        }
        try { barge.start(); } catch (error) {}
      }).catch(() => {});
    }

    function finishSpeaking(localGeneration) {
      if (localGeneration !== generation || destroyed) return;
      try { barge?.release(); } catch (error) {}
      preparing = false;
      speaking = false;
      if (enabled) {
        setState('ready', 'Voice ready');
        scheduleRecognition(150);
      } else {
        setState('idle');
      }
    }

    function browserSpeak(text, localGeneration) {
      if (!enabled || destroyed || !('speechSynthesis' in window) || !window.SpeechSynthesisUtterance) {
        preparing = false;
        scheduleRecognition(100);
        return;
      }
      proof.browserFallbacks += 1;
      preparing = true;
      try { window.speechSynthesis.cancel(); } catch (error) {}
      const utterance = new SpeechSynthesisUtterance(String(text || ''));
      utterance.onstart = () => {
        if (localGeneration !== generation || destroyed) return;
        preparing = false;
        speaking = true;
        setState('speaking', 'Stonefellow is responding…');
        armBarge(localGeneration);
      };
      utterance.onend = utterance.onerror = () => finishSpeaking(localGeneration);
      try { window.speechSynthesis.speak(utterance); }
      catch (error) { finishSpeaking(localGeneration); }
    }

    async function speak(text) {
      const message = String(text || '').trim();
      if (!enabled || !message || destroyed) return;
      stopRecognition(false);
      generation += 1;
      const localGeneration = generation;
      try { premiumVoice?.stop(); } catch (error) {}
      try { window.speechSynthesis?.cancel(); } catch (error) {}
      try { barge?.release(); } catch (error) {}
      preparing = true;
      speaking = false;
      setState('processing', 'Preparing voice…');

      if (premiumVoice) {
        try {
          await premiumVoice.speak(message, {
            onStart: () => {
              if (localGeneration !== generation || destroyed) return;
              preparing = false;
              speaking = true;
              proof.premiumStarts += 1;
              setState('speaking', 'Stonefellow is responding…');
              armBarge(localGeneration);
            },
            onEnd: () => finishSpeaking(localGeneration),
            onError: error => {
              if (localGeneration !== generation || destroyed) return;
              preparing = false;
              speaking = false;
              browserSpeak(message, localGeneration);
              try { options.onOutputError?.(error); } catch (callbackError) {}
            }
          });
          return;
        } catch (error) {
          if (error?.name === 'AbortError' || localGeneration !== generation || destroyed) return;
          preparing = false;
          speaking = false;
          try { options.onOutputError?.(error); } catch (callbackError) {}
        }
      }
      browserSpeak(message, localGeneration);
    }

    function stopOutput() {
      generation += 1;
      try { premiumVoice?.stop(); } catch (error) {}
      try { window.speechSynthesis?.cancel(); } catch (error) {}
      try { barge?.release(); } catch (error) {}
      preparing = false;
      speaking = false;
    }

    function setEnabled(next, opts = {}) {
      enabled = !!next;
      proof.enabled = enabled;
      if (opts.persist !== false) writeShared(userId, enabled, source);
      try { options.onVoiceChange?.(enabled); } catch (error) {}
      if (!enabled) {
        stopRecognition(false);
        stopOutput();
        setState('idle', 'Voice conversation off');
        return;
      }
      setState('ready', 'Voice conversation on');
      try { void premiumVoice?.warm?.(); } catch (error) {}
      scheduleRecognition(45);
    }

    function resume(delay = 120) {
      if (!enabled || destroyed) return;
      if (!busy() && !speaking && !preparing) scheduleRecognition(delay);
    }

    function destroy() {
      destroyed = true;
      clearRestart();
      stopRecognition(false);
      stopOutput();
    }

    const storageListener = event => {
      if (event.key !== sharedKey(userId) || destroyed) return;
      const next = event.newValue === '1';
      if (next !== enabled) setEnabled(next, { persist: false });
    };
    window.addEventListener('storage', storageListener);
    window.addEventListener('pagehide', () => {
      window.removeEventListener('storage', storageListener);
      destroy();
    }, { once: true });

    return {
      build: BUILD,
      proof,
      start: () => setEnabled(enabled, { persist: false }),
      setEnabled,
      toggle: () => setEnabled(!enabled),
      speak,
      resume,
      stopListening: stopRecognition,
      stopOutput,
      destroy,
      isEnabled: () => enabled,
      isListening: () => listening,
      isSpeaking: () => speaking,
      isPreparing: () => preparing
    };
  }

  window.StonefellowConversationVoiceV120 = {
    build: BUILD,
    create,
    readShared,
    writeShared,
    key: sharedKey
  };
})();
