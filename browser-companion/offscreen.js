(() => {
  'use strict';
  let activeAudio = null;

  function stopActive() {
    if (!activeAudio) return;
    try {
      activeAudio.pause();
      activeAudio.removeAttribute('src');
      activeAudio.load();
    } catch (_error) {}
    activeAudio = null;
  }

  async function play(dataUrl) {
    stopActive();
    const url = String(dataUrl || '');
    if (!url.startsWith('data:audio/')) throw new Error('Agent Voice audio is invalid.');
    const audio = new Audio();
    activeAudio = audio;
    audio.preload = 'auto';
    audio.src = url;
    await new Promise((resolve, reject) => {
      let settled = false;
      const finish = (ok, error = null) => {
        if (settled) return;
        settled = true;
        audio.onended = null;
        audio.onerror = null;
        if (activeAudio === audio) activeAudio = null;
        if (ok) resolve(true);
        else reject(error || new Error('Agent Voice playback failed.'));
      };
      audio.onended = () => finish(true);
      audio.onerror = () => finish(false, new Error('Agent Voice playback failed.'));
      Promise.resolve(audio.play()).catch(error => finish(false, error));
    });
    return true;
  }

  chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message?.type !== 'vp3_voice_play_v2140') return false;
    play(message.data_url)
      .then(() => sendResponse({ ok:true }))
      .catch(error => sendResponse({ ok:false, error:String(error?.message || error || 'Agent Voice playback failed.') }));
    return true;
  });
})();