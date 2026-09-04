(() => {
  'use strict';

  const BUILD = 'artist-listening-recognition';
  const RESTART_DELAY_MS = 35;
  const NativeRecognition = window.SpeechRecognition || window.webkitSpeechRecognition || null;

  const proof = window.STONEFELLOW_ARTIST_LISTENING_RECOGNITION = {
    build: BUILD,
    loaded: true,
    wrapperInstalled: false,
    restartDelayMs: RESTART_DELAY_MS,
    nativeRestarts: 0,
    transientStartRetries: 0,
    recognitionRestarts: 0,
    audioRetained: false,
  };

  function emit(handler, context, event) {
    if (typeof handler !== 'function') return;
    try { handler.call(context, event); } catch (error) {
      window.setTimeout(() => { throw error; }, 0);
    }
  }

  if (typeof NativeRecognition === 'function') {
    class StonefellowContinuousRecognition {
      constructor() {
        this.continuous = true;
        this.interimResults = true;
        this.lang = document.documentElement.lang || 'en-US';
        this.maxAlternatives = 1;
        this.grammars = null;
        this.serviceURI = '';

        this.onstart = null;
        this.onresult = null;
        this.onerror = null;
        this.onend = null;
        this.onaudiostart = null;
        this.onaudioend = null;
        this.onsoundstart = null;
        this.onsoundend = null;
        this.onspeechstart = null;
        this.onspeechend = null;
        this.onnomatch = null;

        this._native = null;
        this._running = false;
        this._closing = false;
        this._fatal = false;
        this._startedEventSent = false;
        this._restartTimer = 0;
        this._restartAttempt = 0;
        this._generation = 0;
      }

      _clearRestart() {
        if (!this._restartTimer) return;
        window.clearTimeout(this._restartTimer);
        this._restartTimer = 0;
      }

      _copyOptions(native) {
        try { native.continuous = true; } catch (error) {}
        try { native.interimResults = this.interimResults !== false; } catch (error) {}
        try { native.lang = String(this.lang || 'en-US'); } catch (error) {}
        try { native.maxAlternatives = Math.max(1, Number(this.maxAlternatives || 1)); } catch (error) {}
        try { if (this.grammars) native.grammars = this.grammars; } catch (error) {}
        try { if (this.serviceURI) native.serviceURI = this.serviceURI; } catch (error) {}
      }

      _scheduleRestart(delay = RESTART_DELAY_MS) {
        this._clearRestart();
        if (!this._running || this._closing || this._fatal) return;
        window.dispatchEvent(new CustomEvent('stonefellow:artist-listening-recognition-reset', {detail:{delayMs:Math.max(20, Number(delay || RESTART_DELAY_MS))}}));
        proof.recognitionRestarts += 1;
        this._restartTimer = window.setTimeout(() => {
          this._restartTimer = 0;
          this._spawn(false);
        }, Math.max(20, Number(delay || RESTART_DELAY_MS)));
      }

      _spawn(firstStart) {
        if (!this._running || this._closing || this._fatal) return;

        const generation = ++this._generation;
        const native = new NativeRecognition();
        this._native = native;
        this._copyOptions(native);

        native.onstart = event => {
          if (generation !== this._generation || this._native !== native) return;
          this._restartAttempt = 0;
          if (!this._startedEventSent) {
            this._startedEventSent = true;
            emit(this.onstart, this, event);
          } else if (!firstStart) {
            proof.nativeRestarts += 1;
          }
        };

        native.onresult = event => {
          if (generation !== this._generation || this._native !== native || !this._running) return;
          emit(this.onresult, this, event);
        };

        native.onerror = event => {
          if (generation !== this._generation || this._native !== native) return;
          const code = String(event?.error || '');
          if (['not-allowed', 'service-not-allowed', 'audio-capture'].includes(code)) {
            this._fatal = true;
            this._running = false;
          }
          emit(this.onerror, this, event);
        };

        native.onnomatch = event => emit(this.onnomatch, this, event);
        native.onaudiostart = event => emit(this.onaudiostart, this, event);
        native.onaudioend = event => emit(this.onaudioend, this, event);
        native.onsoundstart = event => emit(this.onsoundstart, this, event);
        native.onsoundend = event => emit(this.onsoundend, this, event);
        native.onspeechstart = event => emit(this.onspeechstart, this, event);
        native.onspeechend = event => emit(this.onspeechend, this, event);

        native.onend = event => {
          if (generation !== this._generation) return;
          if (this._native === native) this._native = null;

          if (this._running && !this._closing && !this._fatal) {
            this._scheduleRestart(RESTART_DELAY_MS);
            return;
          }

          this._clearRestart();
          this._startedEventSent = false;
          emit(this.onend, this, event);
        };

        try {
          native.start();
        } catch (error) {
          if (this._native === native) this._native = null;
          if (!this._running || this._closing || this._fatal) {
            emit(this.onerror, this, { error: String(error?.name || 'start-failed'), message: String(error?.message || '') });
            emit(this.onend, this, new Event('end'));
            return;
          }
          this._restartAttempt += 1;
          proof.transientStartRetries += 1;
          const retryDelay = Math.min(420, RESTART_DELAY_MS * Math.max(2, 2 ** Math.min(4, this._restartAttempt)));
          this._scheduleRestart(retryDelay);
        }
      }

      start() {
        if (this._running) {
          throw new DOMException('Recognition has already started.', 'InvalidStateError');
        }
        this._clearRestart();
        this._running = true;
        this._closing = false;
        this._fatal = false;
        this._startedEventSent = false;
        this._restartAttempt = 0;
        this._spawn(true);
      }

      stop() {
        if (!this._running && !this._native) return;
        this._running = false;
        this._closing = true;
        this._clearRestart();
        const native = this._native;
        if (native) {
          try { native.stop(); } catch (error) {
            try { native.abort(); } catch (abortError) {}
          }
        } else {
          this._startedEventSent = false;
          window.queueMicrotask(() => emit(this.onend, this, new Event('end')));
        }
      }

      abort() {
        if (!this._running && !this._native) return;
        this._running = false;
        this._closing = true;
        this._clearRestart();
        const native = this._native;
        if (native) {
          try { native.abort(); } catch (error) {}
        } else {
          this._startedEventSent = false;
          window.queueMicrotask(() => emit(this.onend, this, new Event('end')));
        }
      }
    }

    window.SpeechRecognition = StonefellowContinuousRecognition;
    window.webkitSpeechRecognition = StonefellowContinuousRecognition;
    proof.wrapperInstalled = true;
  }

  // Transcript reconciliation and rendering live on the main document page.
  // This wrapper owns only gap-minimized native recognition continuity.
})();
