(() => {
  const CLIENT_BUILD = 'v24';
  const cfg = window.STONEFELLOW_STEM_UPLOAD;
  if (!cfg) return;

  const input = document.getElementById('stemZipInput');
  const button = document.getElementById('stemUploadButton');
  const cancel = document.getElementById('stemUploadCancel');
  const progress = document.getElementById('stemUploadProgress');
  const bar = document.getElementById('stemUploadProgressBar');
  const progressText = document.getElementById('stemUploadProgressText');
  const status = document.getElementById('stemUploadStatus');

  if (!input || !button || !cancel || !progress || !bar || !progressText || !status) return;

  let controller = null;
  let activeUploadId = '';

  function randomUploadId() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
  }

  function prettyBytes(bytes) {
    if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
    if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  }

  function setProgress(percent, text) {
    progress.hidden = false;
    bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
    progressText.textContent = text;
  }

  function setStatus(message, error = false) {
    status.textContent = message;
    status.classList.toggle('error', error);
  }

  function cleanResponsePreview(text) {
    return String(text || '')
      .replace(/<script[\s\S]*?<\/script>/gi, '')
      .replace(/<style[\s\S]*?<\/style>/gi, '')
      .replace(/<[^>]+>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 320);
  }

  async function postForm(formData, signal) {
    const action = String(formData.get('action') || 'unknown');

    const response = await fetch(cfg.endpoint, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      signal
    });

    const raw = await response.text();
    let data = null;

    try {
      data = JSON.parse(raw);
    } catch (error) {
      const preview = cleanResponsePreview(raw);
      throw new Error(
        `[${CLIENT_BUILD}] Server returned HTTP ${response.status || 'unknown'} during ${action} instead of JSON` +
        (preview ? `: ${preview}` : '.')
      );
    }

    if (!response.ok || !data?.ok) {
      const requestId = data?.request_id ? ` [${data.request_id}]` : '';
      throw new Error(
        `[${CLIENT_BUILD}] ${data?.error || 'Project upload failed.'} (phase: ${action})${requestId}`
      );
    }

    return data;
  }

  function baseForm(action) {
    const form = new FormData();
    form.append('csrf_token', cfg.csrf);
    form.append('action', action);
    form.append('track_id', String(cfg.trackId));
    form.append('upload_id', activeUploadId);
    return form;
  }

  async function abortUpload(uploadId) {
    if (!uploadId) return;

    const previous = activeUploadId;
    activeUploadId = uploadId;

    try {
      await postForm(baseForm('abort'));
    } catch (error) {}

    activeUploadId = previous;
  }

  async function uploadFile(file) {
    if (!file.name.toLowerCase().endsWith('.zip')) {
      throw new Error('Choose a ZIP file.');
    }

    if (file.size < 1 || file.size > Number(cfg.maxBytes || 0)) {
      throw new Error(`The ZIP must be smaller than ${prettyBytes(Number(cfg.maxBytes || 0))}.`);
    }

    if (
      cfg.hasExisting &&
      !window.confirm('This track already has a stem package. Replace it with the selected REAPER package?')
    ) {
      return;
    }

    controller = new AbortController();
    activeUploadId = randomUploadId();

    setStatus('Checking stem importer v24…');
    setProgress(0, 'Checking server importer…');

    const probe = await postForm(baseForm('probe'), controller.signal);

    if (probe.importer_build !== CLIENT_BUILD) {
      throw new Error(
        `Deployment mismatch: browser is ${CLIENT_BUILD} but server returned ${probe.importer_build || 'unknown'}.`
      );
    }

    setStatus(
      `Importer ${probe.importer_build} ready · ZIP backend: ${probe.zip_backend || 'unknown'}`
    );

    const chunkBytes = Math.max(
      1024 * 1024,
      Number(cfg.chunkBytes || 8 * 1024 * 1024)
    );
    const totalChunks = Math.ceil(file.size / chunkBytes);

    button.disabled = true;
    input.disabled = true;
    cancel.hidden = false;
    setStatus('');
    setProgress(0, `Starting ${file.name} · ${prettyBytes(file.size)}`);

    // Phase 1: upload small chunks.
    for (let index = 0; index < totalChunks; index++) {
      const start = index * chunkBytes;
      const end = Math.min(file.size, start + chunkBytes);
      const blob = file.slice(start, end);

      const form = baseForm('chunk');
      form.append('chunk_index', String(index));
      form.append('total_chunks', String(totalChunks));
      form.append('file_name', file.name);
      form.append('file_size', String(file.size));
      form.append('chunk', blob, `chunk-${index}`);

      await postForm(form, controller.signal);

      const percent = ((index + 1) / totalChunks) * 72;
      setProgress(
        percent,
        `Uploading ${index + 1} of ${totalChunks} chunks · ${Math.round(percent)}%`
      );
    }

    // Phase 2: assemble one already-uploaded chunk per request.
    // This prevents shared-hosting/FastCGI timeouts while concatenating
    // larger ZIPs.
    setProgress(73, 'Upload complete. Starting ZIP assembly…');
    const assemblyStart = await postForm(
      baseForm('assemble_start'),
      controller.signal
    );

    const assemblyTotal = Number(assemblyStart.total_chunks || totalChunks);
    let assembledChunks = 0;

    while (assembledChunks < assemblyTotal) {
      const step = await postForm(
        baseForm('assemble_step'),
        controller.signal
      );

      assembledChunks = Number(step.completed || 0);
      const percent = 73 + ((assembledChunks / assemblyTotal) * 9);

      setProgress(
        percent,
        `Assembling ZIP ${assembledChunks} of ${assemblyTotal} chunks`
      );
    }

    // Phase 3: inspect the completed ZIP and read the RPP metadata.
    setProgress(83, 'ZIP assembled. Inspecting REAPER project…');
    const prepared = await postForm(baseForm('prepare'), controller.signal);

    const totalStems = Number(prepared.total_stems || 0);
    const format = String(prepared.format || 'audio');

    if (!totalStems) {
      throw new Error('The package was prepared but no stems were found.');
    }

    setStatus(`${totalStems} ${format} stems found. Importing one stem at a time…`);

    // Phase 4: one stem per request.
    let completed = 0;

    while (completed < totalStems) {
      const data = await postForm(baseForm('import_step'), controller.signal);
      completed = Number(data.completed || 0);

      const percent = 84 + ((completed / totalStems) * 13);
      const lastStem = data.last_stem ? ` · ${data.last_stem}` : '';

      setProgress(
        percent,
        `Importing stem ${completed} of ${totalStems}${lastStem}`
      );
    }

    // Phase 5: quick DB transaction + cleanup.
    setProgress(98, 'Saving stem library and cleaning temporary files…');
    const data = await postForm(baseForm('commit'), controller.signal);
    const summary = data.summary || {};

    setProgress(100, 'REAPER stem package imported.');
    setStatus(
      `${Number(summary.stem_count || 0)} stems imported` +
      (summary.used_mp3 ? ' · MP3 web stems' : '') +
      (summary.tempo_bpm ? ` · ${summary.tempo_bpm} BPM` : '') +
      (
        Number(summary.ignored_raw_wavs || 0) > 0
          ? ` · ${summary.ignored_raw_wavs} WAV files skipped`
          : ''
      )
    );

    activeUploadId = '';

    window.setTimeout(() => {
      if (data.studio_url) {
        window.location.href = data.studio_url;
      } else {
        window.location.reload();
      }
    }, 850);
  }

  button.addEventListener('click', async () => {
    const file = input.files?.[0];

    if (!file) {
      setStatus('Choose a REAPER Media ZIP first.', true);
      return;
    }

    try {
      await uploadFile(file);
    } catch (error) {
      if (error.name === 'AbortError') {
        setStatus('Upload cancelled.', true);
      } else {
        setStatus(error.message || 'Project upload failed.', true);
      }

      const id = activeUploadId;
      if (id) {
        await abortUpload(id);
      }

      activeUploadId = '';
      button.disabled = false;
      input.disabled = false;
      cancel.hidden = true;
    }
  });

  cancel.addEventListener('click', async () => {
    const id = activeUploadId;

    controller?.abort();
    controller = null;
    activeUploadId = '';

    await abortUpload(id);

    button.disabled = false;
    input.disabled = false;
    cancel.hidden = true;
    setStatus('Upload cancelled.', true);
  });
})();