(function(root,factory){
  const api=factory(root);
  if(typeof module==='object'&&module.exports)module.exports=api;
  root.StonefellowStemTransportV200=api;
  api.installStemMediaOfflineBridge?.(root);
})(typeof globalThis!=='undefined'?globalThis:window,function(root){
  'use strict';

  const finite=value=>Number.isFinite(Number(value));

  function mediaTargetReached(audio,target,tolerance=.055){
    return Boolean(audio)&&
      finite(audio.currentTime)&&
      !audio.seeking&&
      Math.abs(Number(audio.currentTime)-Number(target))<=Math.max(.005,Number(tolerance)||.055);
  }

  function quarantineSeek(audio,target,reason){
    if(!audio)return;
    audio.__STONEFELLOW_SEEK_DEGRADED_V234__=true;
    try{audio.pause?.();}catch(error){}
    const stemId=Number(audio?.dataset?.stemAudio||0);
    const current=finite(audio.currentTime)?Number(audio.currentTime):Number(target)||0;
    const EventClass=root?.CustomEvent||globalThis.CustomEvent;
    if(typeof EventClass==='function'&&typeof root?.dispatchEvent==='function'){
      root.dispatchEvent(new EventClass('stonefellow:stem-seek-degraded',{
        detail:{
          build:'stem-transport-v200-seek-authority-20260903',
          stemId,
          target:Number(target)||0,
          currentTime:current,
          reason:String(reason||'seek_failed')
        }
      }));
      if(stemId>0){
        root.dispatchEvent(new EventClass('stonefellow:stem-media-offline',{
          detail:{
            build:'stem-transport-v200-seek-authority-20260903',
            stemId,
            reason:'seek_failed',
            status:0
          }
        }));
      }
    }
    if(stemId<1){
      const QuietPromise=root?.Promise||Promise;
      const quietPlay=()=>QuietPromise.resolve();
      try{Object.defineProperty(audio,'play',{configurable:true,value:quietPlay});}
      catch(error){try{audio.play=quietPlay;}catch(assignError){}}
    }
  }

  function waitForSeek(audio,target,options={}){
    const timeoutMs=Math.max(250,Number(options.timeoutMs)||2400);
    const tolerance=Math.max(.005,Number(options.tolerance)||.055);
    const generation=options.generation;
    const isCurrent=typeof options.isCurrent==='function'?options.isCurrent:()=>true;
    const wanted=Number(target);
    if(!audio)return Promise.reject(new Error('Audio element is unavailable.'));
    if(!finite(wanted))return Promise.reject(new Error('Audio seek target is invalid.'));
    if(!isCurrent(generation))return Promise.reject(new Error('Stale transport generation.'));
    if(mediaTargetReached(audio,wanted,tolerance))return Promise.resolve(Number(audio.currentTime));

    return new Promise((resolve,reject)=>{
      let finished=false;
      let timer=0;
      let correctionTimer=0;
      let corrections=0;
      const maxCorrections=2;
      const cleanup=()=>{
        clearTimeout(timer);
        clearTimeout(correctionTimer);
        audio.removeEventListener('seeked',onProgress);
        audio.removeEventListener('timeupdate',onProgress);
        audio.removeEventListener('loadedmetadata',onProgress);
        audio.removeEventListener('error',onError);
        audio.removeEventListener('abort',onError);
      };
      const finish=value=>{
        if(finished)return;
        finished=true;
        cleanup();
        resolve(value);
      };
      const fail=message=>{
        if(finished)return;
        finished=true;
        cleanup();
        reject(new Error(message));
      };
      const degrade=reason=>{
        if(finished)return;
        if(!isCurrent(generation)){
          fail('Stale transport generation.');
          return;
        }
        try{audio.currentTime=wanted;}catch(error){}
        quarantineSeek(audio,wanted,reason);
        finish(finite(audio.currentTime)?Number(audio.currentTime):wanted);
      };
      const requestTarget=()=>{
        if(finished)return;
        if(!isCurrent(generation)){
          fail('Stale transport generation.');
          return;
        }
        try{audio.currentTime=wanted;}
        catch(error){degrade('seek_assignment_rejected');}
      };
      const onProgress=()=>{
        if(finished)return;
        if(!isCurrent(generation)){
          fail('Stale transport generation.');
          return;
        }
        if(mediaTargetReached(audio,wanted,tolerance)){
          finish(Number(audio.currentTime));
          return;
        }
        if(audio.seeking||corrections>=maxCorrections)return;
        corrections+=1;
        clearTimeout(correctionTimer);
        correctionTimer=setTimeout(requestTarget,35*corrections);
      };
      const onError=()=>degrade('decoder_seek_error');
      audio.addEventListener('seeked',onProgress);
      audio.addEventListener('timeupdate',onProgress);
      audio.addEventListener('loadedmetadata',onProgress);
      audio.addEventListener('error',onError);
      audio.addEventListener('abort',onError);
      timer=setTimeout(()=>{
        if(mediaTargetReached(audio,wanted,tolerance))finish(Number(audio.currentTime));
        else degrade('seek_timeout');
      },timeoutMs);
      requestTarget();
    });
  }

  function installStemMediaOfflineBridge(host){
    if(
      !host||
      typeof host.addEventListener!=='function'||
      host.__STONEFELLOW_STEM_MEDIA_OFFLINE_BRIDGE_V231__
    )return false;
    host.__STONEFELLOW_STEM_MEDIA_OFFLINE_BRIDGE_V231__=true;
    const pendingIds=new Set();
    const neutralize=media=>{
      if(!media||media.__STONEFELLOW_MEDIA_OFFLINE_V231__)return;
      media.__STONEFELLOW_MEDIA_OFFLINE_V231__=true;
      try{media.pause?.();}catch(error){}
      try{media.preload='none';}catch(error){}
      const QuietPromise=host.Promise||Promise;
      const quietPlay=()=>QuietPromise.resolve();
      try{Object.defineProperty(media,'play',{configurable:true,value:quietPlay});}
      catch(error){try{media.play=quietPlay;}catch(assignError){}}
    };
    const apply=stemId=>{
      const id=Number(stemId||0);
      if(id<1)return false;
      const stem=host.STONEFELLOW_STUDIO_RUNTIME_V87?.getStem?.(id);
      if(!stem){
        pendingIds.add(id);
        return false;
      }
      pendingIds.delete(id);
      stem.mediaUnavailable=true;
      stem.pendingPlay=false;
      stem.pendingBoundarySeek=false;
      stem.pendingCrossfadePlay=false;
      stem.pendingCrossfadeSeek=false;
      neutralize(stem.audio);
      neutralize(stem.crossfadeAudio);
      return true;
    };
    const flush=()=>{[...pendingIds].forEach(apply);};
    host.addEventListener(
      'stonefellow:stem-media-offline',
      event=>apply(event?.detail?.stemId)
    );
    host.addEventListener(
      'stonefellow:stem-runtime-ready',
      flush
    );
    return true;
  }

  function expectedStemTime(stem,clip,globalTime,projectTempo){
    if(!stem||!clip)return -1;
    const stemTempo=Math.max(40,Number(stem.sourceTempo)||Number(projectTempo)||120);
    const baseTempo=Math.max(40,Number(projectTempo)||120);
    return Number(clip.sourceStart||0)+
      (Number(globalTime||0)-Number(clip.timelineStart||0))*(baseTempo/stemTempo);
  }

  function mediaDrift(audio,expected){
    if(
      !audio||
      audio.__STONEFELLOW_SEEK_DEGRADED_V234__||
      audio.paused||
      audio.seeking||
      !finite(audio.currentTime)||
      !finite(expected)
    )return null;
    return Number(audio.currentTime)-Number(expected);
  }

  function driftRequiresRecovery(errors,options={}){
    const values=(Array.isArray(errors)?errors:[]).filter(finite).map(Number);
    if(!values.length)return false;
    const absoluteLimit=Math.max(.04,Number(options.absoluteLimit)||.12);
    const spreadLimit=Math.max(.025,Number(options.spreadLimit)||.075);
    if(Math.max(...values.map(Math.abs))>absoluteLimit)return true;
    if(values.length<2)return false;
    return Math.max(...values)-Math.min(...values)>spreadLimit;
  }

  return Object.freeze({
    mediaTargetReached,
    waitForSeek,
    installStemMediaOfflineBridge,
    expectedStemTime,
    mediaDrift,
    driftRequiresRecovery,
    __STONEFELLOW_SEEK_RECOVERY_V230__:true,
    __STONEFELLOW_SEEK_RECOVERY_V234__:true
  });
});

/*
 * v208 professional transport layer. It intentionally lives in the active
 * v200 transport bundle so the recovery branch keeps one authoritative
 * transport asset while preserving the frozen conversation/voice runtime.
 */
(function (root, factory) {
  'use strict';

  const api = factory();
  root.StonefellowStemTransportHardeningV208 = api;

  if (root.document) {
    api.installSpaceTransportGuard(root);
    api.install(root, root.document);
  }
})(typeof globalThis !== 'undefined' ? globalThis : window, function () {
  'use strict';

  const BUILD = 'stem-transport-hardening-v208-20260901';
  const TICKS_PER_BEAT = 960;
  const SNAP_MODES = Object.freeze([
    'free',
    'bar',
    'beat',
    '1/2',
    '1/4',
    '1/8',
    '1/16'
  ]);

  const clamp = (value, min, max) =>
    Math.min(max, Math.max(min, Number(value || 0)));

  function parseSignature(value) {
    const match = String(value || '4/4').match(/^(\d+)\s*\/\s*(\d+)$/);
    const numerator = clamp(match ? Number(match[1]) : 4, 1, 32);
    const denominatorRaw = match ? Number(match[2]) : 4;
    const denominator = [1, 2, 4, 8, 16, 32].includes(denominatorRaw)
      ? denominatorRaw
      : 4;
    return { numerator, denominator };
  }

  function beatSeconds(tempo, signature = '4/4') {
    const bpm = clamp(tempo, 20, 400) || 120;
    const { denominator } = parseSignature(signature);
    return (60 / bpm) * (4 / denominator);
  }

  function barSeconds(tempo, signature = '4/4') {
    const { numerator } = parseSignature(signature);
    return beatSeconds(tempo, signature) * numerator;
  }

  function noteSeconds(mode, tempo) {
    const bpm = clamp(tempo, 20, 400) || 120;
    const quarter = 60 / bpm;
    const denominator = Number(String(mode || '').replace('1/', ''));
    if (![2, 4, 8, 16].includes(denominator)) {
      return quarter;
    }
    return quarter * (4 / denominator);
  }

  function snapStepSeconds(mode, tempo, signature = '4/4') {
    const clean = SNAP_MODES.includes(String(mode)) ? String(mode) : 'beat';
    if (clean === 'free') return 0;
    if (clean === 'bar') return barSeconds(tempo, signature);
    if (clean === 'beat') return beatSeconds(tempo, signature);
    return noteSeconds(clean, tempo);
  }

  function roundToSample(seconds, sampleRate = 48000) {
    const rate = clamp(sampleRate, 8000, 384000) || 48000;
    return Math.max(0, Math.round(Math.max(0, Number(seconds || 0)) * rate) / rate);
  }

  function snapTime(seconds, mode, tempo, signature = '4/4', sampleRate = 48000) {
    const clean = Math.max(0, Number(seconds || 0));
    const step = snapStepSeconds(mode, tempo, signature);
    if (!(step > 0)) {
      return roundToSample(clean, sampleRate);
    }
    return roundToSample(Math.round(clean / step) * step, sampleRate);
  }

  function quantizeRange(start, end, mode, tempo, signature = '4/4', sampleRate = 48000, minLength = 0.01) {
    const safeStart = Math.max(0, Number(start || 0));
    const safeEnd = Math.max(safeStart + minLength, Number(end || 0));
    if (mode === 'free') {
      return {
        start: roundToSample(safeStart, sampleRate),
        end: roundToSample(safeEnd, sampleRate)
      };
    }

    let nextStart = snapTime(safeStart, mode, tempo, signature, sampleRate);
    let nextEnd = snapTime(safeEnd, mode, tempo, signature, sampleRate);
    const step = Math.max(minLength, snapStepSeconds(mode, tempo, signature));

    if (nextEnd <= nextStart + minLength / 2) {
      nextEnd = roundToSample(nextStart + step, sampleRate);
    }

    return {
      start: Math.max(0, nextStart),
      end: Math.max(nextStart + minLength, nextEnd)
    };
  }

  function formatBBT(seconds, tempo, signature = '4/4') {
    const time = Math.max(0, Number(seconds || 0));
    const beat = Math.max(0.000001, beatSeconds(tempo, signature));
    const { numerator } = parseSignature(signature);
    const barLength = beat * numerator;
    const zeroBar = Math.floor(time / barLength);
    const withinBar = Math.max(0, time - zeroBar * barLength);
    const zeroBeat = Math.min(
      numerator - 1,
      Math.floor(withinBar / beat)
    );
    const withinBeat = Math.max(0, withinBar - zeroBeat * beat);
    const tick = Math.min(
      TICKS_PER_BEAT - 1,
      Math.floor((withinBeat / beat) * TICKS_PER_BEAT)
    );

    return `${String(zeroBar + 1).padStart(3, '0')}|${String(zeroBeat + 1).padStart(2, '0')}|${String(tick).padStart(3, '0')}`;
  }

  function formatClock(seconds) {
    const clean = Math.max(0, Number(seconds || 0));
    const minutes = Math.floor(clean / 60);
    const whole = Math.floor(clean % 60);
    const millis = Math.floor((clean - Math.floor(clean)) * 1000);
    return `${minutes}:${String(whole).padStart(2, '0')}.${String(millis).padStart(3, '0')}`;
  }

  function zoomAroundAnchor(currentZoom, direction, scrollLeft, clientWidth, scrollWidth, anchorRatio) {
    const zoom = clamp(currentZoom, 0.35, 12) || 1;
    const multiplier = direction < 0 ? 1.12 : 1 / 1.12;
    const nextZoom = clamp(zoom * multiplier, 0.35, 12);
    const ratio = clamp(anchorRatio, 0, 1);
    const oldScrollable = Math.max(1, Number(scrollWidth || 1) - Number(clientWidth || 0));
    const normalized = clamp((Number(scrollLeft || 0) + ratio * Number(clientWidth || 0)) / Math.max(1, Number(scrollWidth || 1)), 0, 1);

    return {
      zoom: nextZoom,
      normalized,
      anchorRatio: ratio,
      oldScrollable
    };
  }

  function installSpaceTransportGuard(host) {
    if (
      !host?.document ||
      typeof host.addEventListener !== 'function' ||
      host.__STONEFELLOW_STEM_SPACE_GUARD_V231__
    ) {
      return false;
    }

    host.__STONEFELLOW_STEM_SPACE_GUARD_V231__ = true;
    let suppressKeyUp = false;
    const isTextEditingTarget = target => Boolean(
      target?.closest?.('input:not([type="range"]):not([type="number"]),textarea,select,[contenteditable="true"]')
    );
    const isNativeActivator = node => Boolean(
      node?.matches?.('button,a[href],[role="button"],summary')
    );

    host.addEventListener('keydown', event => {
      if (
        event?.code !== 'Space' ||
        event.altKey ||
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey
      ) {
        return;
      }
      if (isTextEditingTarget(event.target)) return;
      const active = host.document.activeElement;
      if (!isNativeActivator(active)) return;
      suppressKeyUp = true;
      event.preventDefault?.();
      try { active.blur?.(); } catch (error) {}
    }, true);

    host.addEventListener('keyup', event => {
      if (event?.code !== 'Space' || !suppressKeyUp) return;
      suppressKeyUp = false;
      event.preventDefault?.();
    }, true);
    return true;
  }

  function install(root, document) {
    if (!document || root.__STONEFELLOW_STEM_V208_INSTALLED__) {
      return false;
    }

    root.__STONEFELLOW_STEM_V208_INSTALLED__ = true;

    const cfg = root.STONEFELLOW_STEM_STUDIO || {};
    const playButton = document.getElementById('stemPlayButton');
    const currentTime = document.getElementById('stemCurrentTime');
    const durationTime = document.getElementById('stemSongDuration');
    const toolbar = document.querySelector('.daw-mixer-toolbar');
    const transport = document.querySelector('.daw-transport');
    const arrange = document.getElementById('dawArrange');
    const legacySnap = document.getElementById('timelineSnapToggle');
    const zoomIn = document.getElementById('timelineZoomIn');
    const zoomOut = document.getElementById('timelineZoomOut');
    const countIn = document.getElementById('recordCountInBars');
    const metronomeCountIn = document.getElementById('studioMetronomeCountIn');

    if (!playButton || !toolbar || !transport) {
      root.__STONEFELLOW_STEM_V208_INSTALLED__ = false;
      return false;
    }

    const userId = Number(cfg.userId || 0);
    const trackId = Number(cfg.trackId || 0);
    const storageKey = `stonefellow:stem:v208:${userId}:${trackId}`;
    let state = {
      snapMode: 'beat',
      countInBars: Number(countIn?.value || metronomeCountIn?.value || 0) || 0
    };
    let pointerSnapshot = null;
    let loopPointerActive = false;
    let raf = 0;
    let bindAttempts = 0;

    try {
      const saved = JSON.parse(root.localStorage?.getItem(storageKey) || 'null');
      if (saved && typeof saved === 'object') {
        if (SNAP_MODES.includes(String(saved.snapMode))) {
          state.snapMode = String(saved.snapMode);
        }
        if ([0, 1, 2, 4].includes(Number(saved.countInBars))) {
          state.countInBars = Number(saved.countInBars);
        }
      }
    } catch (error) {
      // Private mode / disabled storage is non-fatal.
    }

    function studio() {
      return root.StonefellowStemStudioV91 || root.StonefellowStemStudioV90 || null;
    }

    function studioState() {
      try {
        return studio()?.getAgentState?.() || {};
      } catch (error) {
        return {};
      }
    }

    function ledgerState() {
      try {
        return studio()?.getLedgerState?.() || {};
      } catch (error) {
        return {};
      }
    }

    function tempo() {
      return clamp(
        studioState().tempo || cfg.sourceTempo || 120,
        20,
        400
      ) || 120;
    }

    function signature() {
      return String(
        studioState().time_signature ||
        cfg.timeSignature ||
        '4/4'
      );
    }

    function sampleRate() {
      return clamp(
        cfg.sampleRate ||
        root.STONEFELLOW_STUDIO_SAMPLE_RATE ||
        48000,
        8000,
        384000
      ) || 48000;
    }

    function persist() {
      try {
        root.localStorage?.setItem(storageKey, JSON.stringify(state));
      } catch (error) {
        // Non-fatal.
      }
    }

    const style = document.createElement('style');
    style.dataset.stemTransportV208 = BUILD;
    style.textContent = `
      .sf-v208-readout{display:inline-flex;align-items:center;gap:7px;margin-left:8px;padding:3px 7px;border:1px solid rgba(255,255,255,.07);border-radius:6px;font:700 10px/1.1 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.04em;white-space:nowrap}
      .sf-v208-readout small{opacity:.56;font:600 9px/1 ui-monospace,SFMono-Regular,Consolas,monospace}
      .sf-v208-tools{display:flex;align-items:center;gap:6px;margin-left:auto;min-width:0}
      .sf-v208-tools label{display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;letter-spacing:.06em;opacity:.78;white-space:nowrap}
      .sf-v208-tools select,.sf-v208-tools button{min-height:27px;border:1px solid rgba(255,255,255,.08);border-radius:6px;background:rgba(255,255,255,.035);color:inherit;font:700 10px/1 system-ui,sans-serif}
      .sf-v208-tools select{padding:0 5px}
      .sf-v208-tools button{padding:0 8px;cursor:pointer}
      .sf-v208-tools button:hover,.sf-v208-tools button:focus-visible{background:rgba(255,255,255,.08);outline:none}
      .sf-v208-quantized{box-shadow:0 0 0 1px rgba(255,255,255,.22) inset!important}
      @media(max-width:900px){.sf-v208-readout small{display:none}.sf-v208-tools label>span{display:none}.sf-v208-tools{gap:4px}}
      @media(max-width:650px){.sf-v208-readout{margin-left:3px;padding:2px 4px;font-size:9px}.sf-v208-tools{width:100%;order:20;justify-content:flex-end}.sf-v208-tools select,.sf-v208-tools button{min-height:25px;font-size:9px}}
    `;
    document.head.appendChild(style);

    const readout = document.createElement('div');
    readout.className = 'sf-v208-readout';
    readout.id = 'stemTransportReadoutV208';
    readout.innerHTML = '<span data-v208-bbt>001|01|000</span><small data-v208-clock>0:00.000</small>';
    durationTime?.insertAdjacentElement('afterend', readout);

    const tools = document.createElement('div');
    tools.className = 'sf-v208-tools';
    tools.dataset.stemTransportToolsV208 = BUILD;
    tools.innerHTML = `
      <label title="Timeline snap resolution"><span>SNAP</span><select id="stemSnapModeV208" aria-label="Timeline snap resolution">
        <option value="free">FREE</option>
        <option value="bar">BAR</option>
        <option value="beat">BEAT</option>
        <option value="1/2">1/2</option>
        <option value="1/4">1/4</option>
        <option value="1/8">1/8</option>
        <option value="1/16">1/16</option>
      </select></label>
      <label title="Recording count-in"><span>COUNT-IN</span><select id="stemCountInV208" aria-label="Recording count-in">
        <option value="0">OFF</option>
        <option value="1">1 BAR</option>
        <option value="2">2 BARS</option>
        <option value="4">4 BARS</option>
      </select></label>
      <button type="button" id="stemQuantizeLoopV208" title="Snap the current loop boundaries to the selected grid">Q LOOP</button>
    `;
    toolbar.appendChild(tools);

    const snapSelect = tools.querySelector('#stemSnapModeV208');
    const countInSelect = tools.querySelector('#stemCountInV208');
    const quantizeLoopButton = tools.querySelector('#stemQuantizeLoopV208');

    snapSelect.value = state.snapMode;
    countInSelect.value = String(state.countInBars);

    if (legacySnap) {
      legacySnap.hidden = true;
      legacySnap.setAttribute('aria-hidden', 'true');
      legacySnap.tabIndex = -1;
    }

    function setLegacySnapFree() {
      const live = studio();
      if (!live?.executeAgentCommand) return;
      Promise.resolve(
        live.executeAgentCommand({ type: 'snap', value: 'free' })
      ).catch(() => {});
    }

    function syncCountIn(value) {
      const next = [0, 1, 2, 4].includes(Number(value))
        ? Number(value)
        : 0;
      state.countInBars = next;
      countInSelect.value = String(next);

      for (const select of [countIn, metronomeCountIn]) {
        if (!select || Number(select.value || 0) === next) continue;
        select.value = String(next);
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }

      persist();
    }

    snapSelect.addEventListener('change', () => {
      state.snapMode = SNAP_MODES.includes(snapSelect.value)
        ? snapSelect.value
        : 'beat';
      persist();
      setLegacySnapFree();
      root.dispatchEvent(new CustomEvent('stonefellow:stem-v208-snap', {
        detail: { mode: state.snapMode }
      }));
    });

    countInSelect.addEventListener('change', () => {
      syncCountIn(countInSelect.value);
    });

    countIn?.addEventListener('change', () => {
      const next = Number(countIn.value || 0);
      if ([0, 1, 2, 4].includes(next) && next !== state.countInBars) {
        state.countInBars = next;
        countInSelect.value = String(next);
        persist();
      }
    });

    metronomeCountIn?.addEventListener('change', () => {
      const next = Number(metronomeCountIn.value || 0);
      if ([0, 1, 2, 4].includes(next) && next !== state.countInBars) {
        state.countInBars = next;
        countInSelect.value = String(next);
        persist();
      }
    });

    function clipRecordById(id) {
      const key = String(id || '');
      return (studioState().clips || []).find(
        clip => String(clip.id || '') === key
      ) || null;
    }

    function selectedClipRecord() {
      const stateNow = studioState();
      const id = String(stateNow.selected_clip_id || '');
      if (!id) return null;
      return (stateNow.clips || []).find(
        clip => String(clip.id || '') === id
      ) || null;
    }

    async function quantizeClipAfterEdit(before, after) {
      if (!before || !after || state.snapMode === 'free') return;
      if (String(before.id) !== String(after.id)) return;

      const live = studio();
      if (!live?.executeAgentCommand) return;

      const epsilon = 0.0005;
      const oldStart = Number(before.start || 0);
      const oldDuration = Math.max(0.01, Number(before.duration || 0));
      const oldEnd = oldStart + oldDuration;
      const newStart = Number(after.start || 0);
      const newDuration = Math.max(0.01, Number(after.duration || 0));
      const newEnd = newStart + newDuration;

      const startChanged = Math.abs(newStart - oldStart) > epsilon;
      const durationChanged = Math.abs(newDuration - oldDuration) > epsilon;
      if (!startChanged && !durationChanged) return;

      const snappedStart = snapTime(
        newStart,
        state.snapMode,
        tempo(),
        signature(),
        sampleRate()
      );
      const snappedEnd = snapTime(
        newEnd,
        state.snapMode,
        tempo(),
        signature(),
        sampleRate()
      );

      const oldEndStayed = Math.abs(newEnd - oldEnd) < 0.003;
      const oldStartStayed = Math.abs(newStart - oldStart) < 0.003;

      if (startChanged && durationChanged && oldEndStayed) {
        await live.executeAgentCommand({
          type: 'clip_trim',
          clip_id: after.id,
          edge: 'left',
          time: Math.min(snappedStart, newEnd - 0.01)
        });
      } else if (durationChanged && oldStartStayed) {
        await live.executeAgentCommand({
          type: 'clip_trim',
          clip_id: after.id,
          edge: 'right',
          time: Math.max(snappedEnd, newStart + 0.01)
        });
      } else {
        await live.executeAgentCommand({
          type: 'clip_move',
          clip_id: after.id,
          start: snappedStart
        });
        if (durationChanged) {
          const moved = clipRecordById(after.id);
          const movedStart = Number(moved?.start ?? snappedStart);
          await live.executeAgentCommand({
            type: 'clip_trim',
            clip_id: after.id,
            edge: 'right',
            time: Math.max(
              movedStart + 0.01,
              snapTime(
                movedStart + newDuration,
                state.snapMode,
                tempo(),
                signature(),
                sampleRate()
              )
            )
          });
        }
      }

      const escape = root.CSS?.escape
        ? value => root.CSS.escape(String(value))
        : value => String(value).replace(/["\\]/g, '\\$&');
      const element =
        document.querySelector(`[data-main-clip-id="${escape(after.id)}"]`) ||
        document.querySelector('.daw-library-loop-clip.selected');

      element?.classList.add('sf-v208-quantized');
      root.setTimeout(() => element?.classList.remove('sf-v208-quantized'), 180);

      root.dispatchEvent(new CustomEvent('stonefellow:stem-v208-quantized', {
        detail: {
          clipId: String(after.id),
          mode: state.snapMode
        }
      }));
    }

    document.addEventListener('pointerdown', event => {
      const clipElement = event.target?.closest?.(
        '.daw-editable-clip,.daw-library-loop-clip'
      );

      if (clipElement) {
        root.setTimeout(() => {
          const selected = selectedClipRecord();
          pointerSnapshot = selected
            ? JSON.parse(JSON.stringify(selected))
            : null;
        }, 0);
      }

      if (
        event.target?.closest?.('#dawLoopSelection') ||
        event.target?.closest?.('[data-loop-handle]')
      ) {
        loopPointerActive = true;
      }
    }, false);

    document.addEventListener('pointerup', () => {
      if (pointerSnapshot) {
        const before = pointerSnapshot;
        pointerSnapshot = null;
        root.setTimeout(() => {
          const after = clipRecordById(before.id);
          void quantizeClipAfterEdit(before, after);
        }, 0);
      }

      if (loopPointerActive) {
        loopPointerActive = false;
        root.setTimeout(() => void quantizeLoop(), 0);
      }
    }, false);

    async function quantizeLoop() {
      const live = studio();
      const loop = studioState().loop || {};
      if (
        !live?.executeAgentCommand ||
        !loop.active ||
        !(Number(loop.end) > Number(loop.start))
      ) {
        return false;
      }

      const range = quantizeRange(
        Number(loop.start),
        Number(loop.end),
        state.snapMode,
        tempo(),
        signature(),
        sampleRate(),
        0.01
      );

      await live.executeAgentCommand({
        type: 'loop_set',
        start: range.start,
        end: range.end
      });

      root.dispatchEvent(new CustomEvent('stonefellow:stem-v208-loop', {
        detail: {
          ...range,
          mode: state.snapMode
        }
      }));
      return true;
    }

    quantizeLoopButton.addEventListener('click', () => {
      void quantizeLoop();
    });

    arrange?.addEventListener('wheel', event => {
      if (!(event.ctrlKey || event.metaKey || event.altKey)) return;

      const live = studio();
      if (!live?.executeAgentCommand) return;

      event.preventDefault();

      const current = clamp(studioState().zoom || 1, 0.35, 12) || 1;
      const rect = arrange.getBoundingClientRect();
      const anchorRatio = clamp(
        (event.clientX - rect.left) / Math.max(1, rect.width),
        0,
        1
      );
      const plan = zoomAroundAnchor(
        current,
        event.deltaY,
        arrange.scrollLeft,
        arrange.clientWidth,
        arrange.scrollWidth,
        anchorRatio
      );

      const oldScrollWidth = Math.max(1, arrange.scrollWidth);
      const anchorContent = arrange.scrollLeft + anchorRatio * arrange.clientWidth;
      const normalizedAnchor = anchorContent / oldScrollWidth;

      Promise.resolve(
        live.executeAgentCommand({
          type: 'zoom',
          value: plan.zoom
        })
      ).then(() => {
        root.requestAnimationFrame(() => {
          const nextWidth = Math.max(1, arrange.scrollWidth);
          arrange.scrollLeft = Math.max(
            0,
            normalizedAnchor * nextWidth - anchorRatio * arrange.clientWidth
          );
        });
      }).catch(() => {});
    }, { passive: false });

    document.addEventListener('keydown', event => {
      const target = event.target;
      if (
        target &&
        (
          target.matches?.('input,textarea,select,[contenteditable="true"]') ||
          target.closest?.('[contenteditable="true"]')
        )
      ) {
        return;
      }

      if (event.shiftKey && String(event.key).toLowerCase() === 'g') {
        event.preventDefault();
        const index = SNAP_MODES.indexOf(state.snapMode);
        const nextIndex = (index + 1) % SNAP_MODES.length;
        state.snapMode = SNAP_MODES[nextIndex];
        snapSelect.value = state.snapMode;
        persist();
        setLegacySnapFree();
        return;
      }

      if ((event.ctrlKey || event.metaKey) && (event.key === '=' || event.key === '+')) {
        event.preventDefault();
        zoomIn?.click();
        return;
      }

      if ((event.ctrlKey || event.metaKey) && event.key === '-') {
        event.preventDefault();
        zoomOut?.click();
      }
    });

    function renderPosition() {
      const ledger = ledgerState();
      const seconds = Math.max(
        0,
        Number(
          ledger.playhead ??
          currentTime?.dataset?.seconds ??
          0
        )
      );

      const bbt = readout.querySelector('[data-v208-bbt]');
      const clock = readout.querySelector('[data-v208-clock]');
      if (bbt) bbt.textContent = formatBBT(seconds, tempo(), signature());
      if (clock) clock.textContent = formatClock(seconds);
      readout.title = `${formatClock(seconds)} · sample ${Math.round(seconds * sampleRate()).toLocaleString()}`;
      raf = root.requestAnimationFrame(renderPosition);
    }

    function lateBind() {
      if (!studio()) {
        bindAttempts += 1;
        if (bindAttempts < 200) root.setTimeout(lateBind, 60);
        return;
      }
      setLegacySnapFree();
      syncCountIn(state.countInBars);
      if (!raf) raf = root.requestAnimationFrame(renderPosition);
    }

    lateBind();

    root.addEventListener('pagehide', () => {
      if (raf) root.cancelAnimationFrame(raf);
      raf = 0;
    }, { once: true });

    root.StonefellowStemTransportV208Runtime = {
      build: BUILD,
      getState: () => ({ ...state }),
      setSnapMode(mode) {
        const next = SNAP_MODES.includes(String(mode)) ? String(mode) : 'beat';
        state.snapMode = next;
        snapSelect.value = next;
        persist();
        setLegacySnapFree();
        return next;
      },
      quantizeLoop,
      snapTime: seconds => snapTime(
        seconds,
        state.snapMode,
        tempo(),
        signature(),
        sampleRate()
      )
    };

    root.dispatchEvent(new CustomEvent('stonefellow:stem-transport-v208', {
      detail: { build: BUILD }
    }));
    return true;
  }

  return {
    build: BUILD,
    ticksPerBeat: TICKS_PER_BEAT,
    snapModes: SNAP_MODES,
    parseSignature,
    beatSeconds,
    barSeconds,
    snapStepSeconds,
    roundToSample,
    snapTime,
    quantizeRange,
    formatBBT,
    formatClock,
    zoomAroundAnchor,
    installSpaceTransportGuard,
    install
  };
});
