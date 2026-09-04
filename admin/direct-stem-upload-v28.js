(() => {
  const cfg = window.STONEFELLOW_DIRECT_STEM_UPLOAD;
  if (!cfg) return;

  const filesInput = document.getElementById('directStemFiles');
  const rppInput = document.getElementById('directRppFile');
  const button = document.getElementById('directStemUploadButton');
  const cancel = document.getElementById('directStemUploadCancel');
  const progress = document.getElementById('directStemProgress');
  const bar = document.getElementById('directStemProgressBar');
  const progressText = document.getElementById('directStemProgressText');
  const status = document.getElementById('directStemStatus');

  if (!filesInput || !button || !cancel || !progress || !bar || !progressText || !status) return;

  let controller = null;
  let uploadId = '';

  function id() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return [...bytes].map(v => v.toString(16).padStart(2, '0')).join('');
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

  function cleanResponse(text) {
    return String(text || '')
      .replace(/<script[\s\S]*?<\/script>/gi, '')
      .replace(/<style[\s\S]*?<\/style>/gi, '')
      .replace(/<[^>]+>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 320);
  }

  async function request(form) {
    const response = await fetch(cfg.endpoint, {
      method:'POST',
      credentials:'same-origin',
      body:form,
      signal:controller?.signal
    });

    const raw = await response.text();
    let data;

    try {
      data = JSON.parse(raw);
    } catch (error) {
      const preview = cleanResponse(raw);
      throw new Error(
        `Direct stem importer returned HTTP ${response.status}` +
        (preview ? `: ${preview}` : '.')
      );
    }

    if (!response.ok || !data?.ok) {
      throw new Error(
        (data?.error || 'Direct stem upload failed.') +
        (data?.request_id ? ` [${data.request_id}]` : '')
      );
    }

    return data;
  }

  function base(action) {
    const form = new FormData();
    form.append('csrf_token', cfg.csrf);
    form.append('track_id', String(cfg.trackId));
    form.append('upload_id', uploadId);
    form.append('action', action);
    return form;
  }

  function durationFor(file) {
    return new Promise(resolve => {
      const audio = document.createElement('audio');
      const url = URL.createObjectURL(file);
      let settled = false;

      const finish = value => {
        if (settled) return;
        settled = true;
        URL.revokeObjectURL(url);
        audio.removeAttribute('src');
        resolve(Number.isFinite(value) ? Math.max(0, value) : 0);
      };

      audio.preload = 'metadata';
      audio.src = url;
      audio.addEventListener('loadedmetadata', () => finish(audio.duration), {once:true});
      audio.addEventListener('error', () => finish(0), {once:true});
      window.setTimeout(() => finish(0), 8000);
    });
  }

  async function abort() {
    if (!uploadId) return;
    try {
      await request(base('abort'));
    } catch (error) {}
  }

  async function run() {
    const files = [...(filesInput.files || [])];
    const rpp = rppInput?.files?.[0] || null;

    if (!files.length && !rpp) {
      throw new Error('Select at least one MP3 stem or a REAPER .rpp project file.');
    }

    if (files.length > 96) {
      throw new Error('A maximum of 96 stems can be imported at once.');
    }

    for (const file of files) {
      if (!file.name.toLowerCase().endsWith('.mp3')) {
        throw new Error(`${file.name} is not an MP3 file.`);
      }
    }

    const totalBytes = files.reduce((sum, file) => sum + file.size, 0);

    if (totalBytes > Number(cfg.maxBytes || 0)) {
      throw new Error('The selected MP3 stems exceed the configured upload limit.');
    }

    if (
      cfg.hasExisting &&
      files.length &&
      !window.confirm('Replace the current web stem set with these MP3 stems?')
    ) {
      return;
    }

    controller = new AbortController();
    uploadId = id();

    button.disabled = true;
    filesInput.disabled = true;
    if (rppInput) rppInput.disabled = true;
    cancel.hidden = false;

    setStatus('');
    setProgress(
      1,
      files.length
        ? `Reading metadata for ${files.length} MP3 stems…`
        : 'Preparing REAPER project metadata…'
    );

    const metadata = [];
    for (let index = 0; index < files.length; index++) {
      const duration = await durationFor(files[index]);
      metadata.push({
        name: files[index].name,
        size: files[index].size,
        duration
      });
      setProgress(
        1 + ((index + 1) / files.length) * 5,
        `Reading stem ${index + 1} of ${files.length}`
      );
    }

    const init = base('init');
    init.append('files_json', JSON.stringify(metadata));
    init.append('has_rpp', rpp ? '1' : '0');
    await request(init);

    const chunkBytes = Math.max(
      1024 * 1024,
      Number(cfg.chunkBytes || 8 * 1024 * 1024)
    );

    let uploadedBytes = 0;

    for (let fileIndex = 0; fileIndex < files.length; fileIndex++) {
      const file = files[fileIndex];
      const chunks = Math.ceil(file.size / chunkBytes);

      for (let chunkIndex = 0; chunkIndex < chunks; chunkIndex++) {
        const start = chunkIndex * chunkBytes;
        const end = Math.min(file.size, start + chunkBytes);
        const blob = file.slice(start, end);

        const form = base('file_chunk');
        form.append('file_index', String(fileIndex));
        form.append('chunk_index', String(chunkIndex));
        form.append('total_chunks', String(chunks));
        form.append('chunk', blob, `stem-${fileIndex}-${chunkIndex}.mp3`);

        await request(form);

        uploadedBytes += blob.size;
        const percent = totalBytes > 0
          ? 7 + (uploadedBytes / totalBytes) * 84
          : 7;

        setProgress(
          percent,
          `Uploading ${fileIndex + 1}/${files.length}: ${file.name}`
        );
      }
    }

    if (rpp) {
      setProgress(92, 'Uploading REAPER project metadata…');
      const form = base('rpp');
      form.append('rpp', rpp, rpp.name);
      await request(form);
    }

    const metaForm = base('meta');
    await request(metaForm);

    setProgress(93.5, 'Opening production-file save session…');
    await request(base('save_open'));

    setProgress(94, 'Saving REAPER project file…');
    await request(base('save_rpp'));

    setProgress(94.5, 'Preparing project database record…');
    const preparedSave = await request(base('save_db'));
    const totalSaveStems = Number(preparedSave.total_stems || 0);

    let saved = 0;
    while (saved < totalSaveStems) {
      const step = await request(base('save_item'));
      saved = Number(step.completed || 0);
      setProgress(
        95 + (saved / totalSaveStems) * 4,
        `Saving stem ${saved} of ${totalSaveStems}` +
          (step.stem_name ? ` · ${step.stem_name}` : '')
      );
    }

    setProgress(99.4, 'Activating production files…');
    const complete = await request(base('save_finish'));

    if (files.length) {
      setProgress(100, 'Stems imported.');
      setStatus(`${Number(complete.stem_count || files.length)} stems are ready.`);
    } else {
      setProgress(100, 'REAPER project metadata imported.');
      setStatus('REAPER project attached. Add stems whenever you are ready.');
    }

    uploadId = '';

    window.setTimeout(() => {
      if (complete.studio_url) {
        window.location.href = complete.studio_url;
      } else {
        window.location.reload();
      }
    }, 700);
  }

  button.addEventListener('click', async () => {
    try {
      await run();
    } catch (error) {
      if (error.name === 'AbortError') {
        setStatus('Upload cancelled.', true);
      } else {
        setStatus(error.message || 'Direct MP3 upload failed.', true);
      }

      await abort();
      uploadId = '';
      button.disabled = false;
      filesInput.disabled = false;
      if (rppInput) rppInput.disabled = false;
      cancel.hidden = true;
    }
  });

  cancel.addEventListener('click', async () => {
    const current = uploadId;
    controller?.abort();
    controller = null;

    if (current) {
      uploadId = current;
      await abort();
    }

    uploadId = '';
    button.disabled = false;
    filesInput.disabled = false;
    if (rppInput) rppInput.disabled = false;
    cancel.hidden = true;
    setStatus('Upload cancelled.', true);
  });
})();