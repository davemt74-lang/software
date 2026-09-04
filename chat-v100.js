(() => {
  'use strict';

  const BUILD = 'runtime-root-cause-20260825';
  const proof = window.STONEFELLOW_CHAT_RUNTIME = {
    build: BUILD,
    loaded: true,
    legacyListenerRemoved: false,
    recognitionStarts: 0,
    lastRecognitionError: '',
    premiumVoice: false
  };

  const purge = () => document
    .querySelectorAll('#agentNextMovesCanvas,.agent-next-canvas-v97,.agent-next-moves,.agent-proactive-panel')
    .forEach(el => el.remove());
  purge();
  const observer = new MutationObserver(purge);
  observer.observe(document.documentElement, { childList:true, subtree:true });

  const cfg = window.STONEFELLOW_CHAT || {};
  const synth = window.speechSynthesis || null;

  function createPremiumVoice() {
    if (!cfg.endpoint || !cfg.csrf) return null;
    let endpoint = '';
    try {
      endpoint = new URL(
        'agent-voice-v102.php',
        new URL(String(cfg.endpoint), window.location.href)
      ).toString();
    } catch (error) {
      return null;
    }

    let audio = null;
    let objectUrl = '';
    let controller = null;

    function cleanupUrl() {
      if (!objectUrl) return;
      try { URL.revokeObjectURL(objectUrl); } catch (error) {}
      objectUrl = '';
    }

    function stop() {
      if (controller) {
        try { controller.abort(); } catch (error) {}
        controller = null;
      }
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
      cleanupUrl();
    }

    async function speak(text, callbacks = {}) {
      stop();
      const message = String(text || '').trim();
      if (!message) throw new Error('No voice text.');

      controller = new AbortController();
      const localController = controller;
      const response = await fetch(endpoint, {
        method:'POST',
        credentials:'same-origin',
        headers:{
          'Content-Type':'application/json',
          Accept:'audio/mpeg'
        },
        body:JSON.stringify({
          csrf_token:String(cfg.csrf),
          text:message
        }),
        signal:localController.signal
      });

      if (!response.ok) {
        let detail = '';
        try { detail = (await response.json())?.error || ''; } catch (error) {}
        throw new Error(detail || 'Premium Agent voice unavailable.');
      }

      const blob = await response.blob();
      if (!blob.size) throw new Error('Premium Agent voice returned no audio.');
      if (localController.signal.aborted) throw new DOMException('Aborted', 'AbortError');

      controller = null;
      objectUrl = URL.createObjectURL(blob);
      audio = new Audio(objectUrl);
      audio.preload = 'auto';
      audio.onplay = () => callbacks.onStart?.();
      audio.onended = () => {
        audio = null;
        cleanupUrl();
        callbacks.onEnd?.();
      };
      audio.onerror = () => {
        audio = null;
        cleanupUrl();
        callbacks.onError?.();
      };
      await audio.play();
      return true;
    }

    proof.premiumVoice = true;
    return { speak, stop };
  }

  const premiumVoice = createPremiumVoice();

  // chat.js historically bound its own LISTEN handler before this runtime.
  // Replace the button node once so the legacy listener is physically removed;
  // this runtime becomes the single owner of LISTEN without rewriting chat.js.
  const legacyListenButton = document.getElementById('chatVoiceButton');
  const listenButton = legacyListenButton?.cloneNode(true) || null;
  if (legacyListenButton && listenButton) {
    legacyListenButton.replaceWith(listenButton);
    proof.legacyListenerRemoved = true;
  }

  const listenStatus = document.getElementById('chatVoiceStatus');
  const chatForm = document.getElementById('chatForm');
  const chatInput = document.getElementById('chatInput');
  const chatThread = document.getElementById('chatThread');
  const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition || null;

  if (listenButton && listenStatus && chatForm && chatInput && chatThread) {
    let enabled = false;
    let recognition = null;
    let listening = false;
    let speaking = false;
    let pendingReply = false;
    let replyBaseline = 0;
    let restartTimer = 0;
    let bargeStream = null;
    let bargeContext = null;
    let bargeSource = null;
    let bargeAnalyser = null;
    let bargeTimer = 0;
    let bargeHits = 0;

    const priorContinuity = window.STONEFELLOW_CHAT_CONTINUITY_V87 || {};

    const status = (text, state = '') => {
      listenStatus.hidden = !text;
      listenStatus.textContent = text;
      listenStatus.dataset.state = state;
    };

    const setButton = on => {
      listenButton.classList.toggle('active', on);
      listenButton.setAttribute('aria-pressed', on ? 'true' : 'false');
      listenButton.setAttribute(
        'aria-label',
        on ? 'Stop voice conversation' : 'Start voice conversation'
      );
    };

    const clearRestart = () => {
      if (!restartTimer) return;
      clearTimeout(restartTimer);
      restartTimer = 0;
    };

    const stopRecognition = () => {
      clearRestart();
      const active = recognition;
      recognition = null;
      listening = false;
      if (!active) return;
      try { active.abort(); } catch (error) {
        try { active.stop(); } catch (stopError) {}
      }
    };

    const releaseBarge = () => {
      if (bargeTimer) clearInterval(bargeTimer);
      bargeTimer = 0;
      bargeHits = 0;
      try { bargeSource?.disconnect(); } catch (error) {}
      bargeSource = null;
      bargeAnalyser = null;
      if (bargeContext) {
        try { bargeContext.close(); } catch (error) {}
      }
      bargeContext = null;
      if (bargeStream) {
        bargeStream.getTracks().forEach(track => track.stop());
      }
      bargeStream = null;
    };

    const stopSpeech = () => {
      premiumVoice?.stop();
      try { synth?.cancel(); } catch (error) {}
      speaking = false;
      if (bargeTimer) clearInterval(bargeTimer);
      bargeTimer = 0;
      bargeHits = 0;
    };

    async function microphonePermission() {
      if (!navigator.permissions?.query) return 'unknown';
      try {
        return (await navigator.permissions.query({ name:'microphone' })).state || 'unknown';
      } catch (error) {
        return 'unknown';
      }
    }

    async function explainRecognitionBlock(kind) {
      const permission = await microphonePermission();
      proof.lastRecognitionError = kind;

      if (!window.isSecureContext) {
        status('Voice input requires a secure HTTPS connection.', 'error');
        return;
      }
      if (permission === 'denied') {
        status('Microphone permission is blocked for this site.', 'error');
        return;
      }
      if (kind === 'audio-capture') {
        status('No microphone device is available to speech recognition.', 'error');
        return;
      }
      if (kind === 'service-not-allowed') {
        status('Browser speech recognition service is unavailable or blocked. Microphone permission is not the problem.', 'error');
        return;
      }
      if (kind === 'not-allowed' && permission === 'granted') {
        status('Speech recognition was blocked by the browser service. Microphone permission is already granted.', 'error');
        return;
      }
      if (kind === 'network') {
        status('Speech recognition network/service failure. Click LISTEN to retry.', 'error');
        return;
      }
      status(`Speech recognition could not start${kind ? ` (${kind})` : ''}. Click LISTEN to retry.`, 'error');
    }

    async function ensureBarge() {
      if (bargeStream || !navigator.mediaDevices?.getUserMedia) return;
      try {
        bargeStream = await navigator.mediaDevices.getUserMedia({
          audio:{
            echoCancellation:true,
            noiseSuppression:true,
            autoGainControl:true
          },
          video:false
        });
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        bargeContext = new Ctx();
        bargeAnalyser = bargeContext.createAnalyser();
        bargeAnalyser.fftSize = 1024;
        bargeAnalyser.smoothingTimeConstant = .65;
        bargeSource = bargeContext.createMediaStreamSource(bargeStream);
        bargeSource.connect(bargeAnalyser);
      } catch (error) {
        // Optional barge-in must never own or block the primary recognition path.
      }
    }

    function startBarge() {
      if (!speaking || !bargeAnalyser) return;
      if (bargeTimer) clearInterval(bargeTimer);
      const samples = new Uint8Array(bargeAnalyser.fftSize);
      bargeHits = 0;
      bargeTimer = setInterval(() => {
        if (!speaking || !bargeAnalyser) {
          clearInterval(bargeTimer);
          bargeTimer = 0;
          return;
        }
        bargeAnalyser.getByteTimeDomainData(samples);
        let sum = 0;
        for (const value of samples) {
          const n = (value - 128) / 128;
          sum += n * n;
        }
        const rms = Math.sqrt(sum / samples.length);
        bargeHits = rms >= .085 ? bargeHits + 1 : Math.max(0, bargeHits - 1);
        if (bargeHits >= 3) {
          stopSpeech();
          status('Listening…', 'listening');
          scheduleStart(60);
        }
      }, 70);
    }

    function scheduleStart(delay = 180) {
      clearRestart();
      if (!enabled || speaking || pendingReply) return;
      restartTimer = setTimeout(() => {
        restartTimer = 0;
        startRecognition(false);
      }, delay);
    }

    function startRecognition(fromTrustedClick = false) {
      if (!enabled || speaking || pendingReply || listening || recognition) return;
      if (!window.isSecureContext) {
        enabled = false;
        setButton(false);
        void explainRecognitionBlock('insecure-context');
        return;
      }
      if (!Recognition) {
        enabled = false;
        setButton(false);
        status('Speech recognition is not available in this browser.', 'error');
        return;
      }

      const session = new Recognition();
      recognition = session;
      session.continuous = false;
      session.interimResults = true;
      session.lang = document.documentElement.lang || 'en-US';
      let final = '';

      session.onstart = () => {
        if (!enabled || recognition !== session) {
          try { session.abort(); } catch (error) {}
          return;
        }
        listening = true;
        status('Listening…', 'listening');
        void ensureBarge();
      };

      session.onresult = event => {
        if (!enabled || recognition !== session) return;
        let interim = '';
        for (let i = event.resultIndex; i < event.results.length; i++) {
          const text = event.results[i][0]?.transcript || '';
          if (event.results[i].isFinal) final += text;
          else interim += text;
        }
        if ((final || interim).trim()) {
          status(interim ? `Listening · ${interim}` : 'Listening…', 'listening');
        }
        if (final.trim()) {
          const spoken = final.trim();
          final = '';
          pendingReply = true;
          replyBaseline = chatThread.querySelectorAll('.message.assistant').length;
          chatInput.value = spoken;
          chatInput.dispatchEvent(new Event('input', { bubbles:true }));
          try { session.stop(); } catch (error) {}
          chatForm.requestSubmit();
          status('Thinking…', 'processing');
        }
      };

      session.onend = () => {
        if (recognition === session) recognition = null;
        listening = false;
        if (enabled && !speaking && !pendingReply) scheduleStart(220);
      };

      session.onerror = event => {
        const kind = String(event.error || '');
        proof.lastRecognitionError = kind;
        if (recognition === session) recognition = null;
        listening = false;

        if (kind === 'aborted') {
          if (enabled && !speaking && !pendingReply) scheduleStart(180);
          return;
        }
        if (kind === 'no-speech') {
          status('Listening…', 'listening');
          scheduleStart(180);
          return;
        }
        if (['not-allowed','service-not-allowed','audio-capture'].includes(kind)) {
          enabled = false;
          setButton(false);
          releaseBarge();
          void explainRecognitionBlock(kind);
          return;
        }
        if (kind === 'network') {
          status('Speech recognition network/service failure. Reconnecting…', 'ready');
          scheduleStart(800);
          return;
        }
        status('Voice input paused. Retrying…', 'ready');
        scheduleStart(420);
      };

      try {
        // Keep start() synchronous with the trusted LISTEN click. Optional
        // getUserMedia/barge-in begins only after recognition actually starts.
        proof.recognitionStarts += 1;
        session.start();
        if (fromTrustedClick) status('Starting microphone…', 'ready');
      } catch (error) {
        if (recognition === session) recognition = null;
        listening = false;
        void explainRecognitionBlock(String(error?.name || 'start-failed'));
      }
    }

    function finishSpeaking() {
      if (!speaking) return;
      speaking = false;
      if (bargeTimer) clearInterval(bargeTimer);
      bargeTimer = 0;
      bargeHits = 0;
      status('Voice conversation on', 'ready');
      scheduleStart(160);
    }

    function browserSpeak(message) {
      if (!synth || !window.SpeechSynthesisUtterance) {
        speaking = false;
        scheduleStart(100);
        return;
      }
      const utterance = new SpeechSynthesisUtterance(message);
      utterance.onstart = () => {
        speaking = true;
        status('Stonefellow is responding…', 'speaking');
        void ensureBarge().finally(startBarge);
      };
      utterance.onend = finishSpeaking;
      utterance.onerror = finishSpeaking;
      try {
        synth.cancel();
        synth.speak(utterance);
      } catch (error) {
        speaking = false;
        scheduleStart(100);
      }
    }

    async function speakReply(text) {
      const message = String(text || '').trim();
      pendingReply = false;
      if (!enabled || !message) {
        scheduleStart(120);
        return;
      }

      stopRecognition();
      stopSpeech();
      speaking = true;
      status('Stonefellow is responding…', 'speaking');

      if (!premiumVoice) {
        browserSpeak(message);
        return;
      }

      try {
        await premiumVoice.speak(message, {
          onStart:() => {
            speaking = true;
            status('Stonefellow is responding…', 'speaking');
            void ensureBarge().finally(startBarge);
          },
          onEnd:finishSpeaking,
          onError:() => browserSpeak(message)
        });
      } catch (error) {
        if (error?.name === 'AbortError') return;
        browserSpeak(message);
      }
    }

    const replyObserver = new MutationObserver(() => {
      if (!pendingReply) return;
      const replies = chatThread.querySelectorAll('.message.assistant');
      if (replies.length <= replyBaseline) return;
      const text = replies[replies.length - 1]
        ?.querySelector('.message-text')
        ?.textContent
        ?.trim() || '';
      if (text) void speakReply(text);
    });
    replyObserver.observe(chatThread, {
      childList:true,
      subtree:true,
      characterData:true
    });

    listenButton.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      enabled = !enabled;
      setButton(enabled);

      if (!enabled) {
        pendingReply = false;
        stopRecognition();
        stopSpeech();
        releaseBarge();
        status('Voice conversation off', 'off');
        return;
      }

      status('Voice conversation on', 'ready');
      startRecognition(true);
    });

    window.STONEFELLOW_CHAT_CONTINUITY_V87 = {
      ...priorContinuity,
      isVoice:() => Boolean(enabled)
    };

    window.addEventListener('pagehide', () => {
      enabled = false;
      pendingReply = false;
      stopRecognition();
      stopSpeech();
      releaseBarge();
      replyObserver.disconnect();
    }, { once:true });
  } else if (listenButton && listenStatus) {
    listenButton.addEventListener('click', () => {
      listenStatus.hidden = false;
      listenStatus.textContent = 'Speech recognition is not available in this browser.';
      listenStatus.dataset.state = 'error';
    });
  }

  // v100 is still referenced by the v102-era PHP page. Keep it able to load
  // the current Admin rail so a stale PHP entry can recover after this asset
  // itself is refreshed.
  if (!document.getElementById('sfOnlineRail') && !document.querySelector('script[data-team-chat-admin-v108]')) {
    const teamChatScript = document.createElement('script');
    teamChatScript.src = new URL(
      'team-chat-admin-v108.js?v=runtime-root-cause-20260825',
      window.location.href
    ).toString();
    teamChatScript.dataset.teamChatAdminV108 = '1';
    teamChatScript.async = false;
    document.head.appendChild(teamChatScript);
  }

  window.addEventListener('pagehide', () => observer.disconnect(), { once:true });
})();
