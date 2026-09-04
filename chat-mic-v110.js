(() => {
  'use strict';

  const BUILD = 'rail-mic-debug-v110-20260825';
  const cfg = window.STONEFELLOW_CHAT || {};
  const proof = window.STONEFELLOW_CHAT_MIC_V110 = {
    build:BUILD,
    loaded:true,
    recognitionError:'',
    captureError:'',
    captureMessage:'',
    permission:'unknown',
    policy:'unknown',
    audioInputs:null,
    topLevel:window.top === window.self,
    premiumVoice:false
  };

  const oldButton = document.getElementById('chatVoiceButton');
  const statusEl = document.getElementById('chatVoiceStatus');
  const form = document.getElementById('chatForm');
  const input = document.getElementById('chatInput');
  const thread = document.getElementById('chatThread');
  if (!oldButton || !statusEl || !form || !input || !thread) return;

  // Become the only LISTEN click owner, regardless of older loaded runtimes.
  const button = oldButton.cloneNode(true);
  oldButton.replaceWith(button);

  const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  const synth = window.speechSynthesis || null;
  let enabled = false;
  let recognition = null;
  let pendingReply = false;
  let replyBaseline = 0;
  let speaking = false;
  let restartTimer = 0;
  let premiumAudio = null;
  let premiumUrl = '';
  let premiumController = null;

  function setStatus(text, state = '') {
    statusEl.hidden = !text;
    statusEl.textContent = text;
    statusEl.dataset.state = state;
  }

  function setButton(on) {
    button.classList.toggle('active', on);
    button.setAttribute('aria-pressed', on ? 'true' : 'false');
    button.setAttribute('aria-label', on ? 'Stop voice conversation' : 'Start voice conversation');
  }

  function clearRestart() {
    if (!restartTimer) return;
    clearTimeout(restartTimer);
    restartTimer = 0;
  }

  function stopRecognition() {
    clearRestart();
    const active = recognition;
    recognition = null;
    if (!active) return;
    try { active.abort(); } catch (error) {
      try { active.stop(); } catch (stopError) {}
    }
  }

  function stopPremium() {
    if (premiumController) {
      try { premiumController.abort(); } catch (error) {}
      premiumController = null;
    }
    if (premiumAudio) {
      premiumAudio.onended = null;
      premiumAudio.onerror = null;
      try { premiumAudio.pause(); } catch (error) {}
      premiumAudio = null;
    }
    if (premiumUrl) {
      try { URL.revokeObjectURL(premiumUrl); } catch (error) {}
      premiumUrl = '';
    }
  }

  function stopSpeech() {
    stopPremium();
    try { synth?.cancel(); } catch (error) {}
    speaking = false;
  }

  async function permissionState() {
    if (!navigator.permissions?.query) return 'unsupported';
    try {
      return String((await navigator.permissions.query({ name:'microphone' })).state || 'unknown');
    } catch (error) {
      return 'unsupported';
    }
  }

  function policyState() {
    try {
      const policy = document.permissionsPolicy || document.featurePolicy || null;
      if (!policy?.allowsFeature) return 'unknown';
      return policy.allowsFeature('microphone') ? 'allowed' : 'blocked';
    } catch (error) {
      return 'unknown';
    }
  }

  async function audioInputCount() {
    if (!navigator.mediaDevices?.enumerateDevices) return null;
    try {
      const devices = await navigator.mediaDevices.enumerateDevices();
      return devices.filter(device => device.kind === 'audioinput').length;
    } catch (error) {
      return null;
    }
  }

  async function probeMicrophone() {
    proof.permission = await permissionState();
    proof.policy = policyState();
    proof.audioInputs = await audioInputCount();

    if (!window.isSecureContext) {
      proof.captureError = 'insecure-context';
      proof.captureMessage = '';
      return false;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      proof.captureError = 'unsupported';
      proof.captureMessage = '';
      return false;
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio:true, video:false });
      stream.getTracks().forEach(track => track.stop());
      proof.captureError = 'none';
      proof.captureMessage = '';
      proof.audioInputs = await audioInputCount();
      return true;
    } catch (error) {
      proof.captureError = String(error?.name || 'capture-failed');
      proof.captureMessage = String(error?.message || '').replace(/\s+/g, ' ').trim().slice(0, 160);
      proof.audioInputs = await audioInputCount();
      return false;
    }
  }

  async function diagnoseRecognitionFailure(kind) {
    proof.recognitionError = String(kind || 'unknown');
    const captureWorks = await probeMicrophone();
    const inputs = proof.audioInputs === null ? '?' : String(proof.audioInputs);
    const details = [
      `recognition=${proof.recognitionError}`,
      `capture=${proof.captureError}`,
      `permission=${proof.permission}`,
      `policy=${proof.policy}`,
      `inputs=${inputs}`,
      `top=${proof.topLevel ? 'yes' : 'no'}`
    ].join(' · ');

    if (captureWorks) {
      setStatus(`Mic works · ${details}`, 'error');
      return;
    }

    const message = proof.captureMessage ? ` · ${proof.captureMessage}` : '';
    setStatus(`Mic debug · ${details}${message}`, 'error');
  }

  function scheduleStart(delay = 220) {
    clearRestart();
    if (!enabled || pendingReply || speaking) return;
    restartTimer = window.setTimeout(() => {
      restartTimer = 0;
      startRecognition(false);
    }, delay);
  }

  function browserSpeak(text) {
    if (!synth || !window.SpeechSynthesisUtterance) {
      speaking = false;
      scheduleStart(120);
      return;
    }

    const utterance = new SpeechSynthesisUtterance(text);
    utterance.onstart = () => {
      speaking = true;
      setStatus('Stonefellow is responding…', 'speaking');
    };
    utterance.onend = () => {
      speaking = false;
      setStatus('Listening…', 'listening');
      scheduleStart(140);
    };
    utterance.onerror = utterance.onend;

    try {
      synth.cancel();
      synth.speak(utterance);
    } catch (error) {
      speaking = false;
      scheduleStart(120);
    }
  }

  async function premiumSpeak(text) {
    if (!cfg.endpoint || !cfg.csrf) return false;
    let endpoint = '';
    try {
      endpoint = new URL('agent-voice-v102.php', new URL(String(cfg.endpoint), window.location.href)).toString();
    } catch (error) {
      return false;
    }

    stopPremium();
    const controller = new AbortController();
    premiumController = controller;
    const response = await fetch(endpoint, {
      method:'POST',
      credentials:'same-origin',
      headers:{ 'Content-Type':'application/json', Accept:'audio/mpeg' },
      body:JSON.stringify({ csrf_token:String(cfg.csrf), text }),
      signal:controller.signal
    });
    if (!response.ok) return false;

    const blob = await response.blob();
    if (!blob.size || controller.signal.aborted) return false;

    premiumController = null;
    premiumUrl = URL.createObjectURL(blob);
    premiumAudio = new Audio(premiumUrl);
    premiumAudio.preload = 'auto';
    premiumAudio.onplay = () => {
      speaking = true;
      proof.premiumVoice = true;
      setStatus('Stonefellow is responding…', 'speaking');
    };
    premiumAudio.onended = () => {
      premiumAudio = null;
      if (premiumUrl) {
        try { URL.revokeObjectURL(premiumUrl); } catch (error) {}
        premiumUrl = '';
      }
      speaking = false;
      setStatus('Listening…', 'listening');
      scheduleStart(140);
    };
    premiumAudio.onerror = () => {
      stopPremium();
      browserSpeak(text);
    };
    await premiumAudio.play();
    return true;
  }

  async function speakReply(text) {
    pendingReply = false;
    const message = String(text || '').trim();
    if (!enabled || !message) {
      scheduleStart(120);
      return;
    }

    stopRecognition();
    stopSpeech();
    speaking = true;
    setStatus('Stonefellow is responding…', 'speaking');
    try {
      if (await premiumSpeak(message)) return;
    } catch (error) {
      if (error?.name === 'AbortError') return;
    }
    browserSpeak(message);
  }

  function startRecognition(fromClick = false) {
    if (!enabled || recognition || pendingReply || speaking) return;
    if (!window.isSecureContext) {
      enabled = false;
      setButton(false);
      void diagnoseRecognitionFailure('insecure-context');
      return;
    }
    if (!Recognition) {
      enabled = false;
      setButton(false);
      setStatus('Speech recognition is not available in this browser.', 'error');
      return;
    }

    const session = new Recognition();
    recognition = session;
    session.continuous = false;
    session.interimResults = true;
    session.lang = document.documentElement.lang || 'en-US';
    let finalText = '';

    session.onstart = () => {
      if (!enabled || recognition !== session) {
        try { session.abort(); } catch (error) {}
        return;
      }
      setStatus('Listening…', 'listening');
    };

    session.onresult = event => {
      if (!enabled || recognition !== session) return;
      let interim = '';
      for (let i = event.resultIndex; i < event.results.length; i += 1) {
        const text = String(event.results[i][0]?.transcript || '');
        if (event.results[i].isFinal) finalText += text;
        else interim += text;
      }
      if (interim.trim()) setStatus(`Listening · ${interim.trim()}`, 'listening');
      if (!finalText.trim()) return;

      const spoken = finalText.trim();
      finalText = '';
      pendingReply = true;
      replyBaseline = thread.querySelectorAll('.message.assistant').length;
      input.value = spoken;
      input.dispatchEvent(new Event('input', { bubbles:true }));
      try { session.stop(); } catch (error) {}
      form.requestSubmit();
      setStatus('Thinking…', 'processing');
    };

    session.onend = () => {
      if (recognition === session) recognition = null;
      if (enabled && !pendingReply && !speaking) scheduleStart(220);
    };

    session.onerror = event => {
      const kind = String(event.error || 'unknown');
      proof.recognitionError = kind;
      if (recognition === session) recognition = null;

      if (kind === 'aborted') {
        if (enabled && !pendingReply && !speaking) scheduleStart(180);
        return;
      }
      if (kind === 'no-speech') {
        setStatus('Listening…', 'listening');
        scheduleStart(180);
        return;
      }
      if (kind === 'network') {
        setStatus('Speech recognition network/service failure. Reconnecting…', 'ready');
        scheduleStart(900);
        return;
      }

      enabled = false;
      setButton(false);
      void diagnoseRecognitionFailure(kind);
    };

    try {
      session.start();
      if (fromClick) setStatus('Starting microphone…', 'ready');
    } catch (error) {
      if (recognition === session) recognition = null;
      enabled = false;
      setButton(false);
      void diagnoseRecognitionFailure(String(error?.name || 'start-failed'));
    }
  }

  const replyObserver = new MutationObserver(() => {
    if (!pendingReply) return;
    const replies = thread.querySelectorAll('.message.assistant');
    if (replies.length <= replyBaseline) return;
    const text = replies[replies.length - 1]?.querySelector('.message-text')?.textContent?.trim() || '';
    if (text) void speakReply(text);
  });
  replyObserver.observe(thread, { childList:true, subtree:true, characterData:true });

  button.addEventListener('click', event => {
    event.preventDefault();
    event.stopPropagation();
    enabled = !enabled;
    setButton(enabled);

    if (!enabled) {
      pendingReply = false;
      stopRecognition();
      stopSpeech();
      setStatus('Voice conversation off', 'off');
      return;
    }

    setStatus('Voice conversation on', 'ready');
    startRecognition(true);
  });

  window.addEventListener('pagehide', () => {
    enabled = false;
    pendingReply = false;
    stopRecognition();
    stopSpeech();
    replyObserver.disconnect();
  }, { once:true });
})();
