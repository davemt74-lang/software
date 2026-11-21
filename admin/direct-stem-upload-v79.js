(() => {
  const cfg = window.STONEFELLOW_DIRECT_STEM_UPLOAD;
  if (!cfg) return;

  const musicUI = cfg.musicLibrary ? window.VP3MusicImportProgress : null;
  const metaHelper = window.STONEFELLOW_STEM_IMPORT_META;
  if (!metaHelper) return;

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
  let safeChunkBytes = 0;
  let committed = false;
  let activeItem = '';


  function id() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return [...bytes].map(v => v.toString(16).padStart(2, '0')).join('');
  }

  function setProgress(percent, text) {
    progress.hidden = false;
    bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
    progressText.textContent = text;
    musicUI?.phase(text,percent);
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
      signal:controller?.signal,
      cache:'no-store'
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
    if (!uploadId || committed) return;
    try {
      // The active AbortController is normally aborted here. Cleanup needs
      // its own independent HTTP request to roll back staged data.
      await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',body:base('abort')});
    } catch (_) {}
  }

  async function uploadProject(file) {
    const size=file.size;
    if(size<1||size>64*1024*1024)throw new Error('REAPER projects must be between 1 byte and 64 MB.');
    const chunkBytes=safeChunkBytes;
    const total=Math.ceil(size/chunkBytes);
    for(let index=0;index<total;index++){
      const part=file.slice(index*chunkBytes,Math.min(size,(index+1)*chunkBytes));
      const form=base('rpp_chunk');
      form.append('chunk_index',String(index));form.append('total_chunks',String(total));
      form.append('chunk_bytes',String(chunkBytes));
      form.append('chunk',part,`${file.name}.part`);
      activeItem='rpp';
      musicUI?.file('rpp','uploading',Math.round(index/total*100));
      const ack=await request(form);
      if(ack.phase!=='uploading_rpp'||(index===total-1&&!ack.complete))throw new Error('The server did not acknowledge the REAPER file upload.');
      musicUI?.file('rpp',index===total-1?'uploaded':'uploading',Math.round((index+1)/total*100));
      setProgress(90+((index+1)/total)*2,`Uploading REAPER project · ${index+1}/${total} chunks`);
    }
    musicUI?.rppUploaded();activeItem='';
  }

  async function run() {
    const files = [...(filesInput.files || [])];
    const rpp = rppInput?.files?.[0] || null;

    if (!files.length && !rpp) {
      throw new Error('Select a REAPER .rpp project file and any exported stems.');
    }
    if (cfg.requireProject && !rpp) throw new Error('Choose your REAPER .rpp project file. You may add stems now or later.');
    if (rpp && (!/\.rpp(?:-bak)?$/i.test(rpp.name) || rpp.size<1 || rpp.size>64*1024*1024)) {
      throw new Error('Select a valid .rpp project (maximum 64 MB).');
    }

    if (files.length > 96) {
      throw new Error('A maximum of 96 stems can be imported at once.');
    }

    const seen=new Set();
    for (const file of files) {
      if (!/\.(mp3|wav)$/i.test(file.name) || file.size<1) {
        throw new Error(`${file.name} must be a nonempty MP3 or WAV file.`);
      }
      const key=file.name.toLowerCase();
      if(seen.has(key))throw new Error(`Duplicate stem filename: ${file.name}. Rename it and retry.`);
      seen.add(key);
    }

    const totalBytes = files.reduce((sum, file) => sum + file.size, 0);

    if (totalBytes + (rpp?.size||0) > Number(cfg.maxBytes || 0)) {
      throw new Error('The selected MP3/WAV stems exceed the configured upload limit.');
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
    committed = false;
    activeItem = '';
    musicUI?.begin({name:cfg.track?.title||rpp?.name||'REAPER project',files:[
      ...(rpp?[{key:'rpp',name:rpp.name,kind:'project'}]:[]),
      ...files.map((file,index)=>({key:`stem-${index}`,name:file.name,kind:'stem'}))
    ],onCancel:()=>cancel.click(),onRetry:()=>button.click()});

    button.disabled = true;
    filesInput.disabled = true;
    if (rppInput) rppInput.disabled = true;
    cancel.hidden = false;

    setStatus('');
    setProgress(
      1,
      files.length
        ? `Reading metadata for ${files.length} MP3/WAV stems…`
        : 'Preparing REAPER project metadata…'
    );

    const probe=await request(base('probe'));
    safeChunkBytes=Math.min(Number(cfg.chunkBytes)||8*1024*1024,Number(probe.max_chunk_bytes)||0);
    if(safeChunkBytes<65536)throw new Error('The hosting PHP upload limits are too small for safe chunk uploads.');
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

    let rppMeta = null;
    if (rpp) {
      setProgress(6.5, 'Parsing REAPER project metadata…');
      rppMeta = metaHelper.parseRppText(await rpp.text(), rpp.name);
    }

    const init = base('init');
    init.append('files_json', JSON.stringify(metadata));
    init.append('has_rpp', rpp ? '1' : '0');
    init.append('require_rpp',cfg.requireProject?'1':'0');
    init.append('rpp_size',String(rpp?.size||0));
    init.append('rpp_name',rpp?.name||'');
    await request(init);

    const chunkBytes = safeChunkBytes;

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

        activeItem=`stem-${fileIndex}`;
        musicUI?.file(activeItem,'uploading',Math.round(start/file.size*100));
        const ack=await request(form);
        if(ack.file_index!==fileIndex||(chunkIndex===chunks-1&&!ack.complete))throw new Error(`Stem upload was not acknowledged: ${file.name}`);
        musicUI?.file(activeItem,chunkIndex===chunks-1?'uploaded':'uploading',Math.round(end/file.size*100));
        if(chunkIndex===chunks-1)activeItem='';
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
      setProgress(90, 'Uploading REAPER project file…');
      await uploadProject(rpp);
    }

    const metaForm = base('meta');
    if (rppMeta) {
      metaForm.append('rpp_meta_json', JSON.stringify(rppMeta));
    }
    await request(metaForm);

    setProgress(93, 'Analyzing stems and auto-mapping metadata…');
    const preparedReview = await request(base('review_prepare'));
    if(cfg.reviewBeforeSave){
      let reviewed;
      musicUI?.pause();
      try {reviewed=await metaHelper.review(preparedReview.review||{},{beforeSave:true});}
      finally {musicUI?.resume();}
      if(!reviewed)throw new Error('Import cancelled. The original project is unchanged.');
      const reviewForm=base('review');
      reviewForm.append('review_json',JSON.stringify(reviewed));
      await request(reviewForm);
    }
    musicUI?.importing();
    files.forEach((_,i)=>musicUI?.file(`stem-${i}`,'importing',100));

    setProgress(93.5, 'Opening production-file save session…');
    await request(base('save_open'));

    setProgress(94, 'Saving REAPER project file…');
    await request(base('save_rpp'));

    setProgress(94.5, 'Preparing project database record…');
    const preparedSave = await request(base('save_db'));
    const totalSaveStems = Number(preparedSave.total_stems || 0);

    let saved = 0;
    while (saved < totalSaveStems) {
      const row = await request(base('row_add'));
      setProgress(
        95 + ((saved + 0.5) / totalSaveStems) * 4,
        `Placing stem ${saved + 1} of ${totalSaveStems}` +
          (row.stem_name ? ` · ${row.stem_name}` : '')
      );
      const placed = await request(base('file_place'));
      if(placed.phase!=='file_place'||Number(placed.completed)!==saved+1)throw new Error('Stem import was not acknowledged.');
      musicUI?.file(`stem-${saved}`,'complete',100);
      saved = Number(placed.completed || 0);
    }

    setProgress(99.4, 'Activating parsed production files…');
    const complete = await request(base('save_finish'));
    if(complete.phase!=='complete'||Number(complete.stem_count)!==files.length)throw new Error('The server did not confirm completing your REAPER import.');
    committed=true;
    musicUI?.file('rpp','complete',100);
    musicUI?.finish({stemCount:files.length,url:cfg.successUrl||complete.studio_url});

    if (files.length) {
      setProgress(100, 'Stems imported. Review saved metadata…');
      setStatus(`${Number(complete.stem_count || files.length)} stems are ready. Review the saved mapping before closing.`);
    } else {
      setProgress(100, 'REAPER project imported. Review saved metadata…');
      setStatus('REAPER project attached. Review the saved metadata before closing.');
    }

    const reviewed = cfg.reviewBeforeSave ? null : await metaHelper.review(preparedReview.review || {});
    if (reviewed) {
      setStatus('Saving metadata corrections…');
      const reviewForm = base('metadata_update');
      reviewForm.append('review_json', JSON.stringify(reviewed));
      const updated = await request(reviewForm);
      setStatus(`Metadata saved · ${Number(updated.updated_stems || files.length)} stems updated.`);
    } else {
      setStatus('Parsed metadata kept as imported.');
    }

    uploadId = '';

    if(cfg.musicLibrary)return;
    window.setTimeout(() => {
      if (complete.studio_url) {
        window.location.href = complete.studio_url;
      } else {
        window.location.reload();
      }
    }, 500);
  }

  button.addEventListener('click', async () => {
    try {
      await run();
    } catch (error) {
      if (error.name === 'AbortError') {
        setStatus('Upload cancelled.', true);
        musicUI?.cancelled();
      } else {
        setStatus(error.message || 'Direct MP3 upload failed.', true);
        musicUI?.failed(error.message||'Direct upload failed.',activeItem);
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
    musicUI?.cancelled();
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