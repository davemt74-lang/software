(() => {
  'use strict';

  const BUILD = 'artist-listening-recognition';
  const RESTART_DELAY_MS = 35;
  const START_TIMEOUT_MS = 10000;
  const MAX_START_FAILURES = 3;
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
    startTimeouts: 0,
    startFailures: 0,
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
        this._startTimer = 0;
        this._restartAttempt = 0;
        this._generation = 0;
      }

      _clearRestart() {
        this._clearStart();
        if (!this._restartTimer) return;
        window.clearTimeout(this._restartTimer);
        this._restartTimer = 0;
      }

      _clearStart() {
        window.clearTimeout(this._startTimer);
        this._startTimer = 0;
      }

      _retryFailure(message, event = null) {
        this._clearStart();
        const native = this._native;
        this._native = null;
        this._generation += 1;
        try { native?.abort(); } catch (error) {}
        if (!this._running || this._closing || this._fatal) return;
        this._restartAttempt += 1;
        proof.startFailures += 1;
        if (this._restartAttempt >= MAX_START_FAILURES) {
          this._running = false;
          this._fatal = true;
          this._clearRestart();
          emit(this.onerror, this, {error:'recognition-start-failed',message});
          emit(this.onend, this, new Event('end'));
          return;
        }
        if (event) emit(this.onerror, this, event);
        this._scheduleRestart(Math.min(420, RESTART_DELAY_MS * 2 ** this._restartAttempt));
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
        let native;
        try { native = new NativeRecognition(); }
        catch (error) { this._retryFailure(String(error?.message || 'Speech recognition could not start.')); return; }
        this._native = native;
        this._copyOptions(native);
        let started = false;

        native.onstart = event => {
          if (generation !== this._generation || this._native !== native || !this._running || this._closing) return;
          this._clearStart();
          started = true;
          if (!this._startedEventSent) {
            this._startedEventSent = true;
            emit(this.onstart, this, event);
          } else if (!firstStart) {
            proof.nativeRestarts += 1;
          }
        };

        native.onresult = event => {
          if (generation !== this._generation || this._native !== native || !this._running) return;
          this._restartAttempt = 0;
          // Native resultIndex starts at zero again after each internal restart.
          emit(this.onresult, this, {type:event.type,results:event.results,resultIndex:event.resultIndex,stonefellowRecognitionRunId:generation});
        };

        native.onerror = event => {
          if (generation !== this._generation || this._native !== native || !this._running || this._closing) return;
          const code = String(event?.error || '');
          if (['not-allowed', 'service-not-allowed', 'audio-capture'].includes(code)) {
            this._fatal = true;
            this._running = false;
            this._clearRestart();
            this._native = null;
            this._generation += 1;
            try { native.abort(); } catch (error) {}
            emit(this.onerror, this, event);
            emit(this.onend, this, new Event('end'));
            return;
          }
          if (code === 'no-speech') {
            this._clearStart();
            this._native = null;
            this._generation += 1;
            try { native.abort(); } catch (error) {}
            this._scheduleRestart(RESTART_DELAY_MS);
            return;
          }
          this._retryFailure(String(event?.message || code || 'Speech recognition failed.'), event);
        };

        native.onnomatch = event => {if(generation === this._generation && this._native === native && this._running)emit(this.onnomatch, this, event);};
        native.onaudiostart = event => {if(generation === this._generation && this._native === native && this._running)emit(this.onaudiostart, this, event);};
        native.onaudioend = event => {if(generation === this._generation && this._native === native && this._running)emit(this.onaudioend, this, event);};
        native.onsoundstart = event => {if(generation === this._generation && this._native === native && this._running)emit(this.onsoundstart, this, event);};
        native.onsoundend = event => {if(generation === this._generation && this._native === native && this._running)emit(this.onsoundend, this, event);};
        native.onspeechstart = event => {if(generation === this._generation && this._native === native && this._running)emit(this.onspeechstart, this, event);};
        native.onspeechend = event => {if(generation === this._generation && this._native === native && this._running)emit(this.onspeechend, this, event);};

        native.onend = event => {
          if (generation !== this._generation) return;
          this._clearStart();
          if (this._native === native) this._native = null;

          if (this._running && !this._closing && !this._fatal) {
            if (!started) { this._retryFailure('Speech recognition ended before it could start.'); return; }
            this._restartAttempt = 0;
            this._scheduleRestart(RESTART_DELAY_MS);
            return;
          }

          this._clearRestart();
          this._startedEventSent = false;
          emit(this.onend, this, event);
        };

        try {
          this._startTimer = window.setTimeout(() => {
            if (generation !== this._generation || this._native !== native || !this._running || this._closing) return;
            proof.startTimeouts += 1;
            this._retryFailure('Speech recognition did not connect. Select Start Listening to retry.');
          }, START_TIMEOUT_MS);
          native.start();
        } catch (error) {
          if (!this._running || this._closing || this._fatal) {
            emit(this.onerror, this, { error: String(error?.name || 'start-failed'), message: String(error?.message || '') });
            emit(this.onend, this, new Event('end'));
            return;
          }
          proof.transientStartRetries += 1;
          this._retryFailure(String(error?.message || 'Speech recognition could not start.'));
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
