(() => {
  'use strict';

  const groupNames = new Set(['vocals','rhythm','music']);

  function groupForStem(stemId) {
    const mixer = document.querySelector(`[data-mixer-stem="${Number(stemId)}"]`);
    const select = mixer?.querySelector('[data-track-group]');
    const selected = String(select?.value || '').toLowerCase();
    if (groupNames.has(selected)) return selected;

    const role = String(
      document.querySelector(`[data-stem-id="${Number(stemId)}"] small`)?.textContent || ''
    ).toLowerCase();
    if (role.includes('vocal')) return 'vocals';
    if (role.includes('drum') || role.includes('percussion') || role.includes('bass')) return 'rhythm';
    return 'music';
  }

  function applyFallbackGroupMute(group, muted) {
    document.querySelectorAll('[data-stem-id]').forEach(row => {
      const stemId = Number(row.dataset.stemId || 0);
      if (!stemId || groupForStem(stemId) !== group) return;
      const audio = row.querySelector('audio.stem-audio');
      if (audio) audio.muted = Boolean(muted);
    });
  }

  document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-group-mute]');
    if (!button) return;

    const group = String(button.dataset.groupMute || '').toLowerCase();
    if (!groupNames.has(group)) return;

    const before = button.classList.contains('active') || button.getAttribute('aria-pressed') === 'true';

    window.setTimeout(() => {
      const after = button.classList.contains('active') || button.getAttribute('aria-pressed') === 'true';
      if (after !== before) return;

      const next = !before;
      button.classList.toggle('active', next);
      button.setAttribute('aria-pressed', next ? 'true' : 'false');
      applyFallbackGroupMute(group, next);
      console.warn(`Stonefellow Studio guard recovered ${group} bus mute binding.`);
    }, 0);
  }, { capture:true });

  function collectRuntimeProbe() {
    const probe = window.STONEFELLOW_STUDIO_RUNTIME_PROBE || {
      build:'runtime-root-cause-20260825',
      errors:[],
      rejections:[]
    };
    probe.guardLoaded = true;
    probe.mainBridge = Boolean(
      window.StonefellowStemStudioV91 ||
      window.StonefellowStemStudioV90
    );
    probe.metronomeBridge = Boolean(window.StonefellowMetronomeV91);
    probe.controls = {
      groupMute:document.querySelectorAll('[data-group-mute]').length,
      groupVolume:document.querySelectorAll('[data-group-volume]').length,
      stemMute:document.querySelectorAll('[data-stem-mute]').length,
      stemSolo:document.querySelectorAll('[data-stem-solo]').length,
      routing:document.querySelectorAll('[data-track-group]').length,
      playPause:Boolean(document.getElementById('stemPlayButton')),
      masterVolume:Boolean(document.getElementById('stemMasterVolume')),
      metronome:Boolean(document.getElementById('studioMetronomeButton')),
      recording:Boolean(document.getElementById('studioRecordButton')),
      pluginRack:Boolean(document.getElementById('pluginRackHandle')),
      agent:Boolean(document.querySelector('.studio-agent-trigger'))
    };
    try {
      probe.resources = performance.getEntriesByType('resource')
        .map(entry => String(entry.name || ''))
        .filter(name => /(?:stems|stem-|editor-voice|team-chat)/i.test(name));
    } catch (error) {
      probe.resources = [];
    }
    window.STONEFELLOW_STUDIO_RUNTIME_PROBE = probe;
  }

  window.setTimeout(collectRuntimeProbe, 0);
  window.addEventListener('load', collectRuntimeProbe, { once:true });

  window.STONEFELLOW_STUDIO_V107_GUARD = {
    build:'runtime-root-cause-20260825',
    groupMuteFallback:true,
    collectRuntimeProbe
  };
})();
