(() => {
  'use strict';

  // Send a single description after gathering, so the PHP relay never needs candidate polling.
  function gather(peer, signal) {
    return new Promise((resolve, reject) => {
      const timeout = setTimeout(finish, 9000);
      function clean() {
        clearTimeout(timeout);
        peer.removeEventListener('icegatheringstatechange', changed);
        signal?.removeEventListener('abort', abort);
      }
      function abort() {
        clean();
        reject(new DOMException('Camera session closed', 'AbortError'));
      }
      function finish() {
        clean();
        const sdp = peer.localDescription?.sdp || '';
        if (/^a=candidate:/m.test(sdp)) resolve(sdp);
        else reject(new Error('The camera connection could not find a network route.'));
      }
      function changed() {
        if (peer.iceGatheringState === 'complete') finish();
      }
      if (signal?.aborted) return abort();
      signal?.addEventListener('abort', abort, {once: true});
      peer.addEventListener('icegatheringstatechange', changed);
      changed();
    });
  }

  window.VP3PhoneCameraICE = {gather};
})();
