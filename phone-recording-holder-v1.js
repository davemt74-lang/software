'use strict';

window.VP3PhoneRecording = function (request) {
  const byId = id => document.getElementById('phone-record-' + id);
  const panel = document.getElementById('phone-recording');
  if (!panel) return null;
  const title = byId('title'), status = byId('status'), review = byId('review');
  const start = byId('start'), stop = byId('stop'), decline = byId('decline');
  const submit = byId('submit'), redo = byId('redo'), save = byId('save'), local = byId('local');
  const retained = byId('retained'), retainedList = byId('retained-list');
  const endpoint = new URL(document.getElementById('phone-location').dataset.endpoint, location.href);
  if (endpoint.origin !== location.origin) return null;
  const types = ['video/mp4','video/webm;codecs=vp8','video/webm'];
  const maxBytes = 32 * 1024 * 1024, chunkBytes = 512 * 1024;
  const retainedTakes = new Set();
  let active = null, leaving = false;

  function live(stream) {
    const tracks = stream?.getTracks();
    return tracks?.length === 1 && tracks[0].kind === 'video' && tracks[0].readyState === 'live';
  }
  function canRecord() {
    const Recorder = window.MediaRecorder;
    return typeof Recorder === 'function' && (!Recorder.isTypeSupported || types.some(type => Recorder.isTypeSupported(type)));
  }
  function recordingCall(action, task, takeId) {
    return request('camera_record_' + action, {session_id:task.session,recording_id:task.id,...(takeId ? {take_id:takeId} : {})});
  }
  function checked(data, task, state) {
    const item = data?.recording;
    if (item?.id !== task.id || item.state !== state) throw Error('Recording request changed');
    return item;
  }
  function render() {
    const task = active;
    panel.hidden = !task;
    if (!task) return;
    const {phase,take} = task, reviewing = phase === 'review' || phase === 'uploading' || phase === 'done';
    panel.dataset.phase = phase;
    title.textContent = phase === 'recording' ? 'Recording' : reviewing ? 'Your recording' : 'Recording request';
    const offered = phase === 'offer', supported = canRecord();
    const text = task.message || (offered ? !supported ? 'This browser cannot record this camera. You can decline the request.'
      : !live(task.stream) ? 'The owner requested a recording. Start sharing this camera to record a take.'
      : 'The owner requested a video. Record on this phone, then review it before sending.'
      : phase === 'starting' ? 'Starting the recording…'
      : phase === 'recording' ? 'Recording video on this phone. Tap Stop recording to review.'
      : phase === 'finishing' ? 'Preparing your take…'
      : phase === 'uploading' ? 'Submitting your recording…'
      : phase === 'done' ? 'Recording submitted to the owner.'
      : phase === 'declining' ? 'Declining the request…'
      : 'Play your take before submitting it to the owner.');
    if (status.textContent !== text) status.textContent = text;
    start.hidden = !offered || !supported;
    start.disabled = !live(task.stream);
    stop.hidden = phase !== 'recording';
    decline.hidden = !offered;
    review.hidden = !take || !reviewing;
    local.hidden = !take || !reviewing;
    submit.hidden = !take || (phase !== 'review' && phase !== 'uploading');
    submit.disabled = phase !== 'review' || !take || take.blob.size > task.limit;
    redo.hidden = !take || phase !== 'review' || !live(task.stream) || !supported;
    if (take && reviewing && review.getAttribute('src') !== take.url) {
      review.src = take.url;
      save.href = take.url;
      save.download = take.filename;
    }
  }
  function clearPlayer() {
    review.pause();
    review.removeAttribute('src');
    review.load();
    save.removeAttribute('href');
  }
  function release(take) { if (take) URL.revokeObjectURL(take.url); }

  // An ended session can leave a playable local file, but never a submit action for another session.
  function keepOnPhone(take) {
    if (!take) return;
    if (leaving || !retainedList) { release(take); return; }
    const item = document.createElement('div'), playback = document.createElement('video');
    const copy = document.createElement('a'), discard = document.createElement('button');
    playback.controls = true;
    playback.playsInline = true;
    playback.preload = 'metadata';
    playback.setAttribute('aria-label','Review saved take on this phone');
    playback.src = take.url;
    copy.href = take.url;
    copy.download = take.filename;
    copy.textContent = 'Save to device';
    discard.type = 'button';
    discard.textContent = 'Discard local take';
    function remove() {
      playback.pause();
      playback.removeAttribute('src');
      playback.load();
      release(take);
      item.remove();
      retainedTakes.delete(remove);
      retained.hidden = retainedTakes.size === 0;
    }
    discard.addEventListener('click',remove);
    retainedTakes.add(remove);
    item.append(playback,copy,discard);
    retainedList.append(item);
    retained.hidden = false;
  }
  function end() {
    const task = active;
    if (!task) return;
    active = null;
    task.upload?.abort();
    if (task.recorder && task.recorder.state !== 'inactive') task.recorder.stop();
    clearPlayer();
    if (task.phase !== 'done') keepOnPhone(task.take);
    else release(task.take);
    task.take = null;
    render();
  }
  function sync(camera,stream) {
    const record = camera?.recording;
    const matches = active && active.id === record?.id && active.session === camera?.session_id && active.device === camera?.device_id;
    if (!matches && active) end();
    if (!record || !camera.device_id || !camera.session_id) return;
    if (['expired','declined'].includes(record.state)) { if (active) end(); return; }
    if (!active) {
      if (!['requested','accepted','reviewing'].includes(record.state)) return;
      active = {id:record.id,session:camera.session_id,device:camera.device_id,phase:'offer',
        limit:Math.min(maxBytes,Number(record.max_bytes) || maxBytes),chunkSize:Math.min(chunkBytes,Number(record.chunk_bytes) || chunkBytes),
        takeId:record.take_id || null,stream,recorder:null,take:null,message:''};
    }
    active.stream = stream;
    active.serverState = record.state;
    if (record.state === 'submitted' && active.takeId === record.take_id && (active.phase === 'review' || active.phase === 'uploading')) {
      active.phase = 'done';
      active.message = '';
    }
    render();
  }
  function stopCapture(task) {
    if (active !== task || task.phase !== 'recording') return;
    task.restoreReviewFocus = document.activeElement === stop;
    task.phase = 'finishing';
    render();
    if (task.recorder.state !== 'inactive') task.recorder.stop();
  }
  async function begin(event) {
    const task = active;
    if (!event.isTrusted || !task || !['offer','review'].includes(task.phase) || !live(task.stream) || !canRecord()) return;
    task.phase = 'starting';
    task.message = '';
    if (task.take) review.pause();
    render();
    try {
      await task.reviewAck;
      if (active !== task) return;
      if (!live(task.stream)) throw Error('Camera unavailable');
      const previous = task.take || task.serverState === 'reviewing' ? task.takeId : null;
      const data = checked(await recordingCall('accept',task,previous),task,'accepted');
      if (active !== task) return;
      if (!data.take_id || !live(task.stream)) throw Error('Recording unavailable');
      task.takeId = data.take_id;
      task.serverState = 'accepted';
      const Recorder = window.MediaRecorder;
      const mime = types.find(type => Recorder.isTypeSupported?.(type));
      const recorder = new Recorder(task.stream,mime ? {mimeType:mime,videoBitsPerSecond:2500000} : undefined);
      let chunks = [], bytes = 0, failed = false;
      task.recorder = recorder;
      recorder.ondataavailable = event => {
        if (!event.data?.size) return;
        bytes += event.data.size;
        chunks.push(event.data);
        // Leave room for a full final chunk at the browser's actual bitrate.
        if (bytes >= task.limit * .9) stopCapture(task);
      };
      recorder.onerror = () => {
        failed = true;
        stopCapture(task);
      };
      recorder.onstop = () => {
        task.recorder = null;
        const mime = (recorder.mimeType || chunks[0]?.type || '').split(';')[0].toLowerCase();
        if (bytes && !failed && ['video/mp4','video/webm'].includes(mime)) {
          const blob = new Blob(chunks,{type:mime});
          const stamp = new Date().toISOString().replace(/[:.]/g,'-');
          const take = {blob,url:URL.createObjectURL(blob),filename:'VP3-camera-'+stamp+'.'+(mime === 'video/mp4' ? 'mp4' : 'webm')};
          if (active !== task) keepOnPhone(take);
          else {
            clearPlayer();
            release(task.take);
            task.take = take;
            task.message = bytes > task.limit ? 'This take exceeds the upload limit. Save it to this phone or record a shorter take.'
              : bytes >= task.limit * .9 ? 'The recording stopped near the size limit. Review it before submitting.' : '';
          }
        } else if (active === task) {
          task.message = task.take ? 'The new recording failed. Your previous take is available below.' : 'The recording could not be saved. Try again.';
        }
        chunks = [];
        if (active !== task) return;
        task.phase = task.take ? 'review' : 'offer';
        if (task.take) task.reviewAck = recordingCall('review',task,task.takeId).catch(() => {});
        render();
        if (task.take && task.restoreReviewFocus) review.focus({preventScroll:true});
        task.restoreReviewFocus = false;
      };
      // Capture uses only the single video track that the holder already shared.
      recorder.start(500);
      task.phase = 'recording';
      render();
    } catch {
      if (active !== task) return;
      task.recorder = null;
      task.phase = task.take ? 'review' : 'offer';
      if (task.take && task.takeId) task.reviewAck = recordingCall('review',task,task.takeId).catch(() => {});
      task.message = 'The recording could not start. Check the camera and try again.';
      render();
    }
  }

  async function send(event) {
    const task = active;
    if (!event.isTrusted || !task?.take || task.phase !== 'review' || task.take.blob.size > task.limit) return;
    const take = task.take, takeId = task.takeId;
    task.phase = 'uploading';
    task.message = '';
    const upload = new AbortController();
    task.upload = upload;
    render();
    try {
      await task.reviewAck;
      if (active !== task || upload.signal.aborted) return;
      const confirmation = await recordingCall('review',task,takeId);
      if (confirmation.recording?.id === task.id && confirmation.recording.state === 'submitted') {
        if (active === task) task.phase = 'done';
        return;
      }
      const reviewed = checked(confirmation,task,'reviewing');
      if (reviewed.take_id !== takeId) throw Error('Take changed');
      const size = take.blob.size, total = Math.ceil(size / task.chunkSize);
      for (let index = 0; index < total; index++) {
        if (active !== task || upload.signal.aborted || task.phase === 'done') return;
        const form = new FormData();
        const fields = {action:'camera_record_chunk',session_id:task.session,recording_id:task.id,take_id:takeId,
          index:String(index),total_bytes:String(size),mime_type:take.blob.type};
        for (const [key,value] of Object.entries(fields)) form.append(key,value);
        const offset = index * task.chunkSize;
        form.append('chunk',take.blob.slice(offset,offset+task.chunkSize), 'part');
        const timeout = setTimeout(() => upload.abort(),30000);
        let data;
        try {
          const response = await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',
            referrerPolicy:'no-referrer',headers:{'X-VP3-Phone':'1'},signal:upload.signal,body:form});
          data = await response.json();
          if (!response.ok || !data.ok || data.recording?.id !== task.id || data.recording.take_id !== takeId) throw Error();
        } finally { clearTimeout(timeout); }
        if (active !== task) return;
        if (data.recording.state === 'submitted') { task.phase = 'done'; return; }
        if (Number(data.next_index) < index + 1) throw Error();
      }
      throw Error('Submission incomplete');
    } catch {
      if (active === task && task.phase !== 'done') {
        task.phase = 'review';
        task.message = 'Submission failed. Your take is still on this phone. Tap Submit to owner to retry.';
      }
    } finally {
      if (active === task) { task.upload = null; render(); }
    }
  }
  start.addEventListener('click',begin);
  redo.addEventListener('click',begin);
  stop.addEventListener('click',() => { if (active) stopCapture(active); });
  decline.addEventListener('click',async event => {
    const task = active;
    if (!event.isTrusted || task?.phase !== 'offer') return;
    task.phase = 'declining';
    task.message = '';
    render();
    try {
      checked(await recordingCall('decline',task,task.takeId),task,'declined');
      if (active === task) end();
    } catch {
      if (active === task) {
        task.phase = 'offer';
        task.message = 'Could not decline the request. Try again.';
        render();
      }
    }
  });
  submit.addEventListener('click',send);
  window.addEventListener('pagehide',() => {
    leaving = true;
    end();
    for (const dispose of retainedTakes) dispose();
  });
  window.addEventListener('pageshow',() => { leaving = false; });
  return {sync,end,isActive:() => !!active && !['offer','declining','done'].includes(active.phase)};
};
