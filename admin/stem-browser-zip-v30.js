(() => {
  const BUILD = 'v30';
  const cfg = window.STONEFELLOW_BROWSER_ZIP;
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
  let uploadId = '';

  const decoder = new TextDecoder('utf-8');

  function randomId() {
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

  function base(action) {
    const form = new FormData();
    form.append('csrf_token', cfg.csrf);
    form.append('track_id', String(cfg.trackId));
    form.append('upload_id', uploadId);
    form.append('action', action);
    return form;
  }

  async function request(form) {
    const action = String(form.get('action') || 'unknown');
    const response = await fetch(cfg.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      body: form,
      signal: controller?.signal
    });

    const raw = await response.text();
    let data;

    try {
      data = JSON.parse(raw);
    } catch (error) {
      const preview = cleanResponse(raw);
      throw new Error(
        `[${BUILD}] HTTP ${response.status} during ${action}` +
        (preview ? `: ${preview}` : '.')
      );
    }

    if (!response.ok || !data?.ok) {
      throw new Error(
        `[${BUILD}] ${data?.error || 'Upload failed.'}` +
        (data?.request_id ? ` [${data.request_id}]` : '')
      );
    }

    return data;
  }

  function u16(view, offset) {
    return view.getUint16(offset, true);
  }

  function u32(view, offset) {
    return view.getUint32(offset, true);
  }

  async function listZip(file) {
    if (file.size < 22) {
      throw new Error('The selected ZIP is too small to be valid.');
    }

    const tailSize = Math.min(file.size, 65557);
    const tailOffset = file.size - tailSize;
    const tailBuffer = await file.slice(tailOffset).arrayBuffer();
    const tail = new DataView(tailBuffer);

    let eocd = -1;
    for (let i = tail.byteLength - 22; i >= 0; i--) {
      if (u32(tail, i) === 0x06054b50) {
        eocd = i;
        break;
      }
    }

    if (eocd < 0) {
      throw new Error('Could not locate the ZIP central directory.');
    }

    const totalEntries = u16(tail, eocd + 10);
    const centralSize = u32(tail, eocd + 12);
    const centralOffset = u32(tail, eocd + 16);

    if (
      totalEntries === 0xffff ||
      centralSize === 0xffffffff ||
      centralOffset === 0xffffffff
    ) {
      throw new Error('ZIP64 archives are not supported by the browser importer.');
    }

    if (totalEntries > 2500) {
      throw new Error('This ZIP contains too many files.');
    }

    const centralBuffer = await file
      .slice(centralOffset, centralOffset + centralSize)
      .arrayBuffer();

    const view = new DataView(centralBuffer);
    const bytes = new Uint8Array(centralBuffer);
    const entries = [];
    let cursor = 0;

    for (let index = 0; index < totalEntries; index++) {
      if (cursor + 46 > view.byteLength || u32(view, cursor) !== 0x02014b50) {
        throw new Error('The ZIP central directory is damaged.');
      }

      const flags = u16(view, cursor + 8);
      const method = u16(view, cursor + 10);
      const crc = u32(view, cursor + 16);
      const compressedSize = u32(view, cursor + 20);
      const size = u32(view, cursor + 24);
      const nameLength = u16(view, cursor + 28);
      const extraLength = u16(view, cursor + 30);
      const commentLength = u16(view, cursor + 32);
      const localOffset = u32(view, cursor + 42);

      const nameStart = cursor + 46;
      const nameEnd = nameStart + nameLength;

      if (nameEnd > bytes.byteLength) {
        throw new Error('The ZIP filename table is damaged.');
      }

      const name = decoder.decode(bytes.slice(nameStart, nameEnd));

      cursor = nameEnd + extraLength + commentLength;

      if (!name || name.endsWith('/')) continue;

      if (
        name.includes('\0') ||
        name.startsWith('/') ||
        name.startsWith('\\') ||
        /(^|[\\/])\.\.([\\/]|$)/.test(name)
      ) {
        throw new Error('The ZIP contains an unsafe file path.');
      }

      entries.push({
        name,
        flags,
        method,
        crc,
        compressedSize,
        size,
        localOffset
      });
    }

    return entries;
  }

  async function extractEntry(zipFile, entry, mime = 'application/octet-stream') {
    const localHeader = await zipFile
      .slice(entry.localOffset, entry.localOffset + 30)
      .arrayBuffer();

    if (localHeader.byteLength !== 30) {
      throw new Error(`Could not read ${entry.name} from the ZIP.`);
    }

    const local = new DataView(localHeader);
    if (u32(local, 0) !== 0x04034b50) {
      throw new Error(`Invalid ZIP header for ${entry.name}.`);
    }

    const nameLength = u16(local, 26);
    const extraLength = u16(local, 28);
    const dataStart = entry.localOffset + 30 + nameLength + extraLength;
    const compressed = zipFile.slice(
      dataStart,
      dataStart + entry.compressedSize
    );

    if (entry.method === 0) {
      return compressed.slice(0, compressed.size, mime);
    }

    if (entry.method !== 8) {
      throw new Error(
        `${entry.name} uses unsupported ZIP compression method ${entry.method}.`
      );
    }

    if (typeof DecompressionStream !== 'function') {
      throw new Error(
        'This browser cannot decompress ZIP files locally. Use current Chrome/Edge or the direct file uploader.'
      );
    }

    let stream;
    try {
      stream = compressed
        .stream()
        .pipeThrough(new DecompressionStream('deflate-raw'));
    } catch (error) {
      throw new Error(
        'The browser could not initialize ZIP decompression. Use current Chrome/Edge.'
      );
    }

    const buffer = await new Response(stream).arrayBuffer();

    if (entry.size > 0 && buffer.byteLength !== entry.size) {
      throw new Error(
        `Extracted size mismatch for ${entry.name}.`
      );
    }

    return new Blob([buffer], {type: mime});
  }

  function baseName(path) {
    return String(path).replace(/\\/g, '/').split('/').pop() || 'file';
  }

  function chooseFiles(entries) {
    const rpp = entries.find(e => /\.rpp$/i.test(e.name))
      || entries.find(e => /\.rpp-bak$/i.test(e.name))
      || null;

    const mp3 = entries.filter(e => /\.mp3$/i.test(e.name));
    const wav = entries.filter(e => /\.wav$/i.test(e.name));
    const consolidated = wav.filter(e => /consolidated/i.test(baseName(e.name)));

    const audio = mp3.length
      ? mp3
      : (consolidated.length ? consolidated : wav);

    if (!audio.length) {
      throw new Error('No MP3 or WAV stems were found inside this ZIP.');
    }

    if (audio.length > 96) {
      throw new Error('More than 96 candidate stems were found.');
    }

    return {
      rpp,
      audio,
      format: mp3.length ? 'MP3' : 'WAV'
    };
  }

  function parseRppText(text, fileName) {
    const result = {
      project_name: String(fileName || 'REAPER Project')
        .replace(/\.rpp(?:-bak)?$/i, ''),
      tempo_bpm: null,
      time_signature: '',
      project_sample_rate: null,
      tracks: [],
      file_map: {}
    };

    const tempo = text.match(/^\s*TEMPO\s+([0-9.]+)\s+(\d+)\s+(\d+)/m);
    if (tempo) {
      result.tempo_bpm = Number(tempo[1]) || null;
      result.time_signature = `${Number(tempo[2])}/${Number(tempo[3])}`;
    }

    const sampleRate = text.match(/^\s*SAMPLERATE\s+(\d+)/m);
    if (sampleRate) {
      result.project_sample_rate = Number(sampleRate[1]) || null;
    }

    const parts = text.split(/(?=^  <TRACK\s)/m);

    for (const part of parts) {
      if (!part.startsWith('  <TRACK ')) continue;

      let trackName = '';
      const quotedName = part.match(/^\s{4}NAME\s+"([^"]*)"/m);
      const rawName = part.match(/^\s{4}NAME\s+([^\r\n]*)/m);

      if (quotedName) {
        trackName = quotedName[1].trim();
      } else if (rawName) {
        trackName = rawName[1].trim().replace(/^"|"$/g, '');
      }

      const guidMatch = part.match(/^\s{4}TRACKID\s+(\{[^}]+\})/m);
      const trackGuid = guidMatch ? guidMatch[1] : '';

      let volume = 1;
      let pan = 0;
      const volPan = part.match(
        /^\s{4}VOLPAN\s+([-0-9.eE]+)\s+([-0-9.eE]+)/m
      );

      if (volPan) {
        volume = Number(volPan[1]);
        if (!Number.isFinite(volume)) volume = 1;

        pan = Number(volPan[2]);
        if (!Number.isFinite(pan)) pan = 0;
        pan = Math.max(-1, Math.min(1, pan));
      }

      const presets = [];
      for (const match of part.matchAll(/^\s+PRESETNAME\s+"([^"]+)"/gm)) {
        const value = String(match[1] || '').trim();
        if (value && !presets.includes(value)) presets.push(value);
      }

      const fxSummary = presets.join(', ');
      const files = {};
      const itemRegex = /<ITEM\b([\s\S]*?)^\s{4}>/gm;
      let itemMatch;

      while ((itemMatch = itemRegex.exec(part)) !== null) {
        const block = itemMatch[1];
        const fileMatch = block.match(/^\s+FILE\s+"([^"]+)"/m);
        if (!fileMatch) continue;

        const sourceFile = String(fileMatch[1])
          .replace(/\\/g, '/')
          .split('/')
          .pop();

        if (!sourceFile) continue;

        const positionMatch = block.match(
          /^\s+POSITION\s+([-0-9.eE]+)/m
        );
        const lengthMatch = block.match(
          /^\s+LENGTH\s+([-0-9.eE]+)/m
        );

        files[sourceFile] = {
          position: positionMatch ? Number(positionMatch[1]) || 0 : 0,
          length: lengthMatch ? Number(lengthMatch[1]) || 0 : 0
        };
      }

      if (!Object.keys(files).length) {
        for (const match of part.matchAll(/^\s+FILE\s+"([^"]+)"/gm)) {
          const sourceFile = String(match[1])
            .replace(/\\/g, '/')
            .split('/')
            .pop();

          if (sourceFile) {
            files[sourceFile] = {
              position: 0,
              length: 0
            };
          }
        }
      }

      result.tracks.push({
        name: trackName,
        guid: trackGuid,
        volume,
        pan,
        fx_summary: fxSummary,
        files
      });

      for (const [sourceFile, item] of Object.entries(files)) {
        result.file_map[sourceFile.toLowerCase()] = {
          track_name: trackName,
          track_guid: trackGuid,
          volume,
          pan,
          fx_summary: fxSummary,
          position: Number(item.position) || 0,
          length: Number(item.length) || 0
        };
      }
    }

    return result;
  }

  async function abortUpload() {
    if (!uploadId) return;
    try {
      await request(base('abort'));
    } catch (error) {}
  }

  async function uploadBlob(blob, name, fileIndex, totalBytesState) {
    const chunkBytes = Math.max(
      1024 * 1024,
      Number(cfg.chunkBytes || 8 * 1024 * 1024)
    );
    const chunks = Math.ceil(blob.size / chunkBytes);

    for (let chunkIndex = 0; chunkIndex < chunks; chunkIndex++) {
      const start = chunkIndex * chunkBytes;
      const end = Math.min(blob.size, start + chunkBytes);
      const part = blob.slice(start, end);

      const form = base('file_chunk');
      form.append('file_index', String(fileIndex));
      form.append('chunk_index', String(chunkIndex));
      form.append('total_chunks', String(chunks));
      form.append('chunk', part, `${name}.part`);

      await request(form);

      totalBytesState.uploaded += part.size;
      const fraction = totalBytesState.total > 0
        ? totalBytesState.uploaded / totalBytesState.total
        : 1;

      setProgress(
        20 + fraction * 72,
        `Uploading ${fileIndex + 1}/${totalBytesState.count}: ${name}`
      );
    }
  }

  async function run(file) {
    if (!file.name.toLowerCase().endsWith('.zip')) {
      throw new Error('Choose a REAPER ZIP file.');
    }

    if (file.size > Number(cfg.maxBytes || 0)) {
      throw new Error('The ZIP exceeds the configured project-size limit.');
    }

    if (
      cfg.hasExisting &&
      !window.confirm('Replace the current stem package with this REAPER ZIP?')
    ) {
      return;
    }

    controller = new AbortController();
    uploadId = randomId();

    button.disabled = true;
    input.disabled = true;
    cancel.hidden = false;

    setStatus('Browser ZIP importer v25 · server never opens the ZIP');
    setProgress(1, 'Checking direct-upload endpoint…');

    const probe = await request(base('probe'));
    if (probe.importer_build !== BUILD) {
      throw new Error(
        `Deployment mismatch: browser ${BUILD}, server ${probe.importer_build || 'unknown'}.`
      );
    }

    setProgress(4, 'Reading ZIP directory in your browser…');
    const entries = await listZip(file);
    const chosen = chooseFiles(entries);

    setStatus(
      `${entries.length} files found · ${chosen.audio.length} ${chosen.format} stems` +
      (chosen.rpp ? ' · REAPER project found' : ' · no RPP found')
    );

    const metadata = chosen.audio.map(entry => ({
      name: baseName(entry.name),
      size: entry.size,
      duration: 0
    }));

    const init = base('init');
    init.append('files_json', JSON.stringify(metadata));
    init.append('has_rpp', chosen.rpp ? '1' : '0');
    await request(init);

    let rppMeta = null;

    if (chosen.rpp) {
      setProgress(8, 'Extracting and reading REAPER project in your browser…');
      const rppBlob = await extractEntry(
        file,
        chosen.rpp,
        'text/plain'
      );

      let rppName = baseName(chosen.rpp.name);
      if (/\.rpp-bak$/i.test(rppName)) {
        rppName = rppName.replace(/\.rpp-bak$/i, '.rpp');
      }

      const rppText = await rppBlob.text();
      rppMeta = parseRppText(rppText, rppName);

      const rppForm = base('rpp');
      rppForm.append('rpp', rppBlob, rppName);
      await request(rppForm);
    }

    const metaForm = base('meta');
    if (rppMeta) {
      metaForm.append('rpp_meta_json', JSON.stringify(rppMeta));
    }
    await request(metaForm);

    const totalBytesState = {
      total: chosen.audio.reduce((sum, entry) => sum + entry.size, 0),
      uploaded: 0,
      count: chosen.audio.length
    };

    for (let index = 0; index < chosen.audio.length; index++) {
      const entry = chosen.audio[index];
      const name = baseName(entry.name);
      const mime = /\.mp3$/i.test(name) ? 'audio/mpeg' : 'audio/wav';

      setProgress(
        10 + (index / chosen.audio.length) * 8,
        `Extracting ${index + 1}/${chosen.audio.length}: ${name}`
      );

      const audioBlob = await extractEntry(file, entry, mime);

      await uploadBlob(
        audioBlob,
        name,
        index,
        totalBytesState
      );
    }

    setProgress(93.5, 'Opening stem save session…');
    await request(base('save_open'));

    setProgress(94, 'Saving REAPER project file…');
    await request(base('save_rpp'));

    setProgress(94.5, 'Preparing project database record…');
    const preparedSave = await request(base('save_db'));
    const totalSaveStems = Number(
      preparedSave.total_stems ?? chosen.audio.length
    );

    let saved = 0;

    while (saved < totalSaveStems) {
      setProgress(
        95 + (saved / totalSaveStems) * 4,
        `Creating stem record ${saved + 1} of ${totalSaveStems}…`
      );

      const row = await request(base('row_add'));

      setProgress(
        95 + ((saved + 0.5) / totalSaveStems) * 4,
        `Placing stem ${saved + 1} of ${totalSaveStems}` +
          (row.stem_name ? ` · ${row.stem_name}` : '')
      );

      const placed = await request(base('file_place'));
      saved = Number(placed.completed || 0);
    }

    setProgress(99.4, 'Activating new stem library…');
    const done = await request(base('save_finish'));

    setProgress(100, 'REAPER package imported.');
    setStatus(
      `${Number(done.stem_count || chosen.audio.length)} stems imported from ${file.name}.`
    );

    uploadId = '';

    window.setTimeout(() => {
      if (done.studio_url) {
        window.location.href = done.studio_url;
      } else {
        window.location.reload();
      }
    }, 700);
  }

  button.addEventListener('click', async () => {
    const file = input.files?.[0];

    if (!file) {
      setStatus('Choose a REAPER ZIP first.', true);
      return;
    }

    try {
      await run(file);
    } catch (error) {
      if (error.name === 'AbortError') {
        setStatus('Upload cancelled.', true);
      } else {
        setStatus(error.message || 'Browser ZIP import failed.', true);
      }

      await abortUpload();
      uploadId = '';
      button.disabled = false;
      input.disabled = false;
      cancel.hidden = true;
    }
  });

  cancel.addEventListener('click', async () => {
    controller?.abort();
    await abortUpload();

    controller = null;
    uploadId = '';
    button.disabled = false;
    input.disabled = false;
    cancel.hidden = true;
    setStatus('Upload cancelled.', true);
  });
})();