(() => {
  'use strict';

  const ROLES = [
    'Vocal',
    'Drums',
    'Percussion',
    'Bass',
    'Guitar',
    'Keys',
    'Synth',
    'Other'
  ];

  const pluginPrefixes = /^(?:VST3?|AU|AUi|CLAP|LV2|DX|JS|VIDEO EFFECT)\s*:\s*/i;

  const text = value => String(value ?? '').trim();
  const clamp = (value, min, max, fallback = 0) => {
    const number = Number(value);
    return Number.isFinite(number)
      ? Math.max(min, Math.min(max, number))
      : fallback;
  };

  const esc = value => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const unique = values => [...new Set(
    values
      .map(value => text(value))
      .filter(Boolean)
  )];

  function cleanPluginName(value) {
    let name = text(value).replace(pluginPrefixes, '');
    name = name.replace(/\s*\([^)]*(?:mono|stereo|\d+\s*(?:ch|out)|bridged)[^)]*\)\s*$/i, '');
    return name.slice(0, 160).trim();
  }

  function inferRole(value) {
    const source = text(value).toLowerCase();
    if (/\b(vocal|vox|voice|singer|lead vox|backing vox|bgv)\b/.test(source)) return 'Vocal';
    if (/\b(kick|snare|tom|drum|cymbal|hi[- ]?hat|overhead|room mic)\b/.test(source)) return 'Drums';
    if (/\b(percussion|shaker|tambourine|conga|bongo|clap|cowbell)\b/.test(source)) return 'Percussion';
    if (/\b(bass|sub bass|upright)\b/.test(source)) return 'Bass';
    if (/\b(guitar|gtr|acoustic|electric guitar|powerchord|dobro|mandolin|banjo)\b/.test(source)) return 'Guitar';
    if (/\b(piano|keys|keyboard|organ|rhodes|wurlitzer|clav)\b/.test(source)) return 'Keys';
    if (/\b(synth|pad|lead synth|arp|arpeggio|sequencer)\b/.test(source)) return 'Synth';
    return 'Other';
  }

  function inferInstrument(value, fallbackRole = 'Other') {
    const source = text(value).toLowerCase();
    const tests = [
      ['Lead Vocal', /\b(lead vocal|lead vox|main vocal|main vox)\b/],
      ['Backing Vocal', /\b(backing vocal|background vocal|backing vox|bgv|harmony vocal)\b/],
      ['Vocal', /\b(vocal|vox|voice)\b/],
      ['Kick Drum', /\bkick\b/],
      ['Snare Drum', /\bsnare\b/],
      ['Hi-Hat', /\bhi[- ]?hat\b/],
      ['Cymbals', /\b(cymbal|crash|ride)\b/],
      ['Toms', /\btoms?\b/],
      ['Drum Kit', /\b(drum kit|drums|overheads?|drum room)\b/],
      ['Percussion', /\b(percussion|shaker|tambourine|conga|bongo|cowbell|clap)\b/],
      ['Electric Bass', /\b(electric bass|bass guitar)\b/],
      ['Upright Bass', /\b(upright bass|double bass)\b/],
      ['Bass', /\bbass\b/],
      ['Acoustic Guitar', /\b(acoustic guitar|acoustic gtr|ac gtr)\b/],
      ['Electric Guitar', /\b(electric guitar|electric gtr|elec gtr|distorted guitar|clean guitar)\b/],
      ['Mandolin', /\bmandolin\b/],
      ['Banjo', /\bbanjo\b/],
      ['Dobro', /\bdobro\b/],
      ['Guitar', /\b(guitar|gtr|powerchord)\b/],
      ['Piano', /\bpiano\b/],
      ['Rhodes', /\brhodes\b/],
      ['Wurlitzer', /\bwurlitzer\b/],
      ['Organ', /\borgan\b/],
      ['Keys', /\b(keys|keyboard|clav)\b/],
      ['Synth Pad', /\b(synth pad|pad)\b/],
      ['Synth Lead', /\b(synth lead|lead synth)\b/],
      ['Synth', /\b(synth|arp|arpeggio|sequencer)\b/],
      ['Strings', /\b(strings?|violin|viola|cello)\b/],
      ['Brass', /\b(brass|trumpet|trombone|horn)\b/],
      ['Saxophone', /\b(sax|saxophone)\b/],
      ['Flute', /\bflute\b/]
    ];

    for (const [label, pattern] of tests) {
      if (pattern.test(source)) return label;
    }

    return fallbackRole && fallbackRole !== 'Other' ? fallbackRole : '';
  }

  function parseRppText(rppText, fileName = 'REAPER Project.rpp') {
    const source = String(rppText || '');
    const result = {
      project_name: text(fileName).replace(/\.rpp(?:-bak)?$/i, '') || 'REAPER Project',
      tempo_bpm: null,
      time_signature: '',
      project_sample_rate: null,
      tracks: [],
      file_map: {},
      plugins: []
    };

    const tempo = source.match(/^\s*TEMPO\s+([0-9.]+)\s+(\d+)\s+(\d+)/m);
    if (tempo) {
      result.tempo_bpm = Number(tempo[1]) || null;
      result.time_signature = `${Number(tempo[2])}/${Number(tempo[3])}`;
    }

    const sampleRate = source.match(/^\s*SAMPLERATE\s+(\d+)/m);
    if (sampleRate) result.project_sample_rate = Number(sampleRate[1]) || null;

    const parts = source.split(/(?=^  <TRACK\s)/m);
    for (const part of parts) {
      if (!/^  <TRACK\s/.test(part)) continue;

      const quotedName = part.match(/^\s{4}NAME\s+"([^"]*)"/m);
      const rawName = part.match(/^\s{4}NAME\s+([^\r\n]*)/m);
      const trackName = text(quotedName?.[1] ?? rawName?.[1] ?? '').replace(/^"|"$/g, '');
      const guid = text(part.match(/^\s{4}TRACKID\s+(\{[^}]+\})/m)?.[1]);
      const nchan = Number(part.match(/^\s{4}NCHAN\s+(\d+)/m)?.[1] || 0);

      let volume = 1;
      let pan = 0;
      const volPan = part.match(/^\s{4}VOLPAN\s+([-0-9.eE]+)\s+([-0-9.eE]+)/m);
      if (volPan) {
        volume = Number(volPan[1]);
        if (!Number.isFinite(volume)) volume = 1;
        pan = clamp(volPan[2], -1, 1, 0);
      }

      const pluginNames = [];
      for (const match of part.matchAll(/^\s+<(VST|AU|CLAP|LV2|DX|JS|VIDEO_EFFECT)\s+"([^"]+)"/gm)) {
        const plugin = cleanPluginName(match[2]);
        if (plugin) pluginNames.push(plugin);
      }
      for (const match of part.matchAll(/^\s+PRESETNAME\s+"([^"]+)"/gm)) {
        const preset = text(match[1]);
        if (preset) pluginNames.push(`Preset: ${preset}`);
      }

      const plugins = unique(pluginNames);
      result.plugins.push(...plugins.filter(name => !name.startsWith('Preset: ')));

      const files = {};
      const itemRegex = /<ITEM\b([\s\S]*?)^\s{4}>/gm;
      let itemMatch;
      while ((itemMatch = itemRegex.exec(part)) !== null) {
        const block = itemMatch[1];
        const fileMatch = block.match(/^\s+FILE\s+"([^"]+)"/m);
        if (!fileMatch) continue;

        const sourceFile = text(fileMatch[1]).replace(/\\/g, '/').split('/').pop();
        if (!sourceFile) continue;

        const position = Number(block.match(/^\s+POSITION\s+([-0-9.eE]+)/m)?.[1] || 0);
        const length = Number(block.match(/^\s+LENGTH\s+([-0-9.eE]+)/m)?.[1] || 0);
        const playRate = Number(block.match(/^\s+PLAYRATE\s+([-0-9.eE]+)/m)?.[1] || 1);
        const takeName = text(block.match(/^\s+NAME\s+"([^"]+)"/m)?.[1]);

        files[sourceFile] = {
          position: Number.isFinite(position) ? position : 0,
          length: Number.isFinite(length) ? length : 0,
          play_rate: Number.isFinite(playRate) ? playRate : 1,
          take_name: takeName
        };
      }

      if (!Object.keys(files).length) {
        for (const match of part.matchAll(/^\s+FILE\s+"([^"]+)"/gm)) {
          const sourceFile = text(match[1]).replace(/\\/g, '/').split('/').pop();
          if (sourceFile) files[sourceFile] = {position: 0, length: 0, play_rate: 1, take_name: ''};
        }
      }

      const role = inferRole(`${trackName} ${plugins.join(' ')}`);
      const instrument = inferInstrument(`${trackName} ${plugins.join(' ')}`, role);

      result.tracks.push({
        name: trackName,
        guid,
        volume,
        pan,
        channels: nchan,
        plugins,
        fx_summary: plugins.join(', '),
        role,
        instrument,
        files
      });

      for (const [sourceFile, item] of Object.entries(files)) {
        result.file_map[sourceFile.toLowerCase()] = {
          track_name: trackName,
          track_guid: guid,
          volume,
          pan,
          channels: nchan,
          plugins,
          fx_summary: plugins.join(', '),
          role,
          instrument,
          position: Number(item.position) || 0,
          length: Number(item.length) || 0,
          play_rate: Number(item.play_rate) || 1,
          take_name: text(item.take_name)
        };
      }
    }

    result.plugins = unique(result.plugins);
    return result;
  }

  function addStyles() {
    if (document.getElementById('stemImportReviewStyles')) return;
    const style = document.createElement('style');
    style.id = 'stemImportReviewStyles';
    style.textContent = `
      .stem-import-review-shell{position:fixed;inset:0;z-index:100000;background:rgba(5,4,3,.82);backdrop-filter:blur(10px);display:flex;align-items:flex-start;justify-content:center;padding:28px;overflow:auto}
      .stem-import-review{width:min(1180px,100%);background:#11100e;border:1px solid #3b3328;border-radius:18px;color:#eee6dc;box-shadow:0 24px 80px rgba(0,0,0,.55);overflow:hidden}
      .stem-import-review *{box-sizing:border-box}
      .stem-import-review header{display:flex;justify-content:space-between;gap:24px;padding:22px 24px;border-bottom:1px solid #302a22;background:#15130f}
      .stem-import-review header span,.stem-import-review h4 span{display:block;font-size:11px;letter-spacing:.13em;text-transform:uppercase;color:#9d8c76}
      .stem-import-review header h2{margin:5px 0 4px;font-size:25px;color:#fff}
      .stem-import-review header p{margin:0;color:#a99d90;max-width:760px;line-height:1.45}
      .stem-import-review-badges{display:flex;flex-wrap:wrap;gap:7px;align-content:flex-start;justify-content:flex-end}
      .stem-import-review-badges b{font-size:11px;font-weight:600;padding:6px 9px;border:1px solid #493c2d;border-radius:999px;color:#dbc3a4;background:#1b1712}
      .stem-import-review-main{padding:22px 24px 4px}
      .stem-import-review h3{font-size:14px;margin:0 0 13px;letter-spacing:.08em;text-transform:uppercase;color:#d7c1a4}
      .stem-import-review-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
      .stem-import-review label{display:flex;flex-direction:column;gap:6px;min-width:0;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#9c8e7d}
      .stem-import-review label.wide{grid-column:span 2}
      .stem-import-review label.full{grid-column:1/-1}
      .stem-import-review input,.stem-import-review select,.stem-import-review textarea{width:100%;border:1px solid #3a3228;background:#0c0b09;color:#f3ece4;border-radius:9px;padding:10px 11px;font:inherit;font-size:14px;letter-spacing:0;text-transform:none;outline:none}
      .stem-import-review textarea{min-height:76px;resize:vertical;line-height:1.45}
      .stem-import-review input:focus,.stem-import-review select:focus,.stem-import-review textarea:focus{border-color:#c6a77f;box-shadow:0 0 0 2px rgba(198,167,127,.13)}
      .stem-import-review-section{margin-bottom:22px}
      .stem-import-review-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
      .stem-import-review-section-head h3{margin:0}
      .stem-import-review-auto{border:1px solid #4b4033;background:#1b1712;color:#d9c2a4;border-radius:8px;padding:8px 11px;cursor:pointer}
      .stem-import-review-stems{display:grid;gap:10px;max-height:440px;overflow:auto;padding-right:4px}
      .stem-import-stem{border:1px solid #302a22;border-radius:12px;background:#0d0c0a;padding:13px}
      .stem-import-stem-head{display:flex;justify-content:space-between;gap:14px;margin-bottom:10px}
      .stem-import-stem-head strong{font-size:14px;color:#fff;overflow-wrap:anywhere}
      .stem-import-stem-head small{display:block;margin-top:3px;color:#81776c;overflow-wrap:anywhere}
      .stem-import-stem-head em{font-style:normal;font-size:11px;color:#b49b7c;white-space:nowrap}
      .stem-import-stem-grid{display:grid;grid-template-columns:1.4fr .8fr 1fr 1.4fr;gap:10px}
      .stem-import-stem-grid .description{grid-column:1/-1}
      .stem-import-stem-stats{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;color:#84796d;font-size:11px}
      .stem-import-stem-stats span{padding:4px 7px;background:#15120f;border-radius:6px}
      .stem-import-review footer{position:sticky;bottom:0;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 24px;border-top:1px solid #302a22;background:#15130f}
      .stem-import-review footer small{color:#8f8377;line-height:1.4}
      .stem-import-review-actions{display:flex;gap:9px;flex-shrink:0}
      .stem-import-review-actions button{border:1px solid #473c30;border-radius:9px;padding:10px 15px;font-weight:700;cursor:pointer;background:#17130f;color:#ded3c5}
      .stem-import-review-actions .primary{background:#e2c49e;border-color:#e2c49e;color:#17110b}
      @media(max-width:820px){.stem-import-review-shell{padding:10px}.stem-import-review-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.stem-import-stem-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.stem-import-review header{flex-direction:column}.stem-import-review-badges{justify-content:flex-start}.stem-import-review footer{align-items:flex-start;flex-direction:column}.stem-import-review-actions{width:100%}.stem-import-review-actions button{flex:1}}
      @media(max-width:520px){.stem-import-review-main{padding:16px 14px 2px}.stem-import-review header,.stem-import-review footer{padding:16px 14px}.stem-import-review-grid,.stem-import-stem-grid{grid-template-columns:1fr}.stem-import-review label.wide,.stem-import-stem-grid .description{grid-column:auto}.stem-import-review label.full{grid-column:1}.stem-import-review-actions{flex-direction:column}}
    `;
    document.head.appendChild(style);
  }

  function roleOptions(selected) {
    return ROLES.map(role => `<option value="${esc(role)}"${role === selected ? ' selected' : ''}>${esc(role)}</option>`).join('');
  }

  function numberText(value, digits = 2) {
    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? number.toFixed(digits).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1') : '—';
  }

  function review(preview) {
    addStyles();

    const defaults = JSON.parse(JSON.stringify(preview || {}));
    const project = defaults.project || {};
    const track = defaults.track || {};
    const stems = Array.isArray(defaults.stems) ? defaults.stems : [];
    const plugins = unique(defaults.plugins || []);

    return new Promise(resolve => {
      const shell = document.createElement('div');
      shell.className = 'stem-import-review-shell';
      shell.setAttribute('role', 'dialog');
      shell.setAttribute('aria-modal', 'true');
      shell.setAttribute('aria-label', 'Review imported track metadata');

      shell.innerHTML = `
        <section class="stem-import-review">
          <header>
            <div>
              <span>IMPORT SAVED · METADATA REVIEW</span>
              <h2>Review Track & Stem Metadata</h2>
              <p>Stonefellow has already saved its best parsed mapping. Adjust anything below to correct or enrich the saved Track Library and recommendation metadata.</p>
            </div>
            <div class="stem-import-review-badges">
              <b>${stems.length} stems</b>
              <b>${esc(numberText(project.tempo_bpm, 1))} BPM</b>
              <b>${esc(project.time_signature || '—')}</b>
              <b>${plugins.length} plugins</b>
            </div>
          </header>

          <div class="stem-import-review-main">
            <section class="stem-import-review-section">
              <div class="stem-import-review-section-head">
                <h3>Song / Recommendation Metadata</h3>
                <button class="stem-import-review-auto" type="button" data-review-autofill>Restore Parsed Suggestions</button>
              </div>
              <div class="stem-import-review-grid">
                <label class="wide">Track title<input data-review-track="title" maxlength="190" value="${esc(track.title)}"></label>
                <label class="wide">Project name<input data-review-project="project_name" maxlength="190" value="${esc(project.project_name)}"></label>
                <label>Tempo BPM<input data-review-project="tempo_bpm" type="number" min="20" max="400" step="0.01" value="${esc(project.tempo_bpm ?? track.tempo_bpm ?? '')}"></label>
                <label>Time signature<input data-review-project="time_signature" maxlength="20" value="${esc(project.time_signature)}" placeholder="4/4"></label>
                <label>Sample rate<input data-review-project="project_sample_rate" type="number" min="0" max="768000" step="1" value="${esc(project.project_sample_rate ?? '')}"></label>
                <label>Energy<select data-review-track="energy"><option value=""${!track.energy ? ' selected' : ''}>—</option><option value="Low"${track.energy === 'Low' ? ' selected' : ''}>Low</option><option value="Medium"${track.energy === 'Medium' ? ' selected' : ''}>Medium</option><option value="High"${track.energy === 'High' ? ' selected' : ''}>High</option></select></label>
                <label>Genre<input data-review-track="genre" maxlength="255" value="${esc(track.genre)}" placeholder="Rock, Americana…"></label>
                <label>Mood<input data-review-track="mood" maxlength="255" value="${esc(track.mood)}" placeholder="Reflective, dark…"></label>
                <label class="wide">Instruments<input data-review-track="instruments" maxlength="500" value="${esc(track.instruments)}" placeholder="Electric Guitar, Bass, Drum Kit…"></label>
                <label class="full">Description<textarea data-review-track="description" maxlength="5000">${esc(track.description)}</textarea></label>
                <label class="full">Recommendation keywords<input data-review-track="keywords" maxlength="500" value="${esc(track.keywords)}" placeholder="guitar, drums, ReaEQ, 120 bpm…"></label>
              </div>
            </section>

            <section class="stem-import-review-section">
              <div class="stem-import-review-section-head"><h3>Stem Library Metadata</h3><span></span></div>
              <div class="stem-import-review-stems">
                ${stems.map((stem, index) => `
                  <article class="stem-import-stem" data-review-stem="${index}">
                    <div class="stem-import-stem-head">
                      <div><strong>${esc(stem.stem_name || stem.file_name || `Stem ${index + 1}`)}</strong><small>${esc(stem.file_name || '')}${stem.source_track_name ? ` · RPP: ${esc(stem.source_track_name)}` : ''}</small></div>
                      <em>${esc(stem.detected_from || 'Parsed metadata')}</em>
                    </div>
                    <div class="stem-import-stem-grid">
                      <label>Stem name<input data-stem-field="stem_name" maxlength="190" value="${esc(stem.stem_name)}"></label>
                      <label>Role<select data-stem-field="stem_role">${roleOptions(stem.stem_role || 'Other')}</select></label>
                      <label>Instrument<input data-stem-field="instrument" maxlength="190" value="${esc(stem.instrument)}" placeholder="Electric Guitar"></label>
                      <label>Plugins / FX<input data-stem-field="plugins" maxlength="700" value="${esc(stem.plugins)}" placeholder="ReaEQ, Compressor…"></label>
                      <label>Volume<input data-stem-field="volume" type="number" min="0" max="8" step="0.001" value="${esc(stem.volume ?? 1)}"></label>
                      <label>Pan<input data-stem-field="pan" type="number" min="-1" max="1" step="0.01" value="${esc(stem.pan ?? 0)}"></label>
                      <label class="description">Description / searchable notes<textarea data-stem-field="description" maxlength="500">${esc(stem.description)}</textarea></label>
                    </div>
                    <div class="stem-import-stem-stats">
                      <span>${esc(stem.format || 'Audio')}</span>
                      <span>${stem.channels ? `${Number(stem.channels)} ch` : 'channels —'}</span>
                      <span>${stem.sample_rate ? `${Number(stem.sample_rate).toLocaleString()} Hz` : 'sample rate —'}</span>
                      <span>${stem.bit_depth ? `${Number(stem.bit_depth)} bit` : 'bit depth —'}</span>
                      <span>${stem.duration_seconds ? `${numberText(stem.duration_seconds, 2)} sec` : 'duration —'}</span>
                      <span>offset ${numberText(stem.start_offset_seconds, 2)} sec</span>
                    </div>
                  </article>
                `).join('')}
              </div>
            </section>
          </div>

          <footer>
            <small>The parsed import is already saved. Closing this panel keeps Stonefellow’s automatic mapping; saving changes resaves only the metadata you adjusted.</small>
            <div class="stem-import-review-actions">
              <button type="button" data-review-cancel>Keep Parsed Settings</button>
              <button type="button" class="primary" data-review-save>Save Metadata Changes</button>
            </div>
          </footer>
        </section>`;

      document.body.appendChild(shell);
      const first = shell.querySelector('[data-review-track="title"]');
      window.setTimeout(() => first?.focus(), 0);

      function finish(value) {
        document.removeEventListener('keydown', onKey);
        shell.remove();
        resolve(value);
      }

      function assignDefaults() {
        const dTrack = defaults.track || {};
        const dProject = defaults.project || {};
        shell.querySelectorAll('[data-review-track]').forEach(input => {
          const key = input.dataset.reviewTrack;
          input.value = dTrack[key] ?? '';
        });
        shell.querySelectorAll('[data-review-project]').forEach(input => {
          const key = input.dataset.reviewProject;
          input.value = dProject[key] ?? '';
        });
        shell.querySelectorAll('[data-review-stem]').forEach((row, index) => {
          const dStem = stems[index] || {};
          row.querySelectorAll('[data-stem-field]').forEach(input => {
            input.value = dStem[input.dataset.stemField] ?? '';
          });
        });
      }

      function collect() {
        const answer = {track: {}, project: {}, stems: []};
        shell.querySelectorAll('[data-review-track]').forEach(input => {
          answer.track[input.dataset.reviewTrack] = text(input.value);
        });
        shell.querySelectorAll('[data-review-project]').forEach(input => {
          answer.project[input.dataset.reviewProject] = text(input.value);
        });
        answer.project.tempo_bpm = clamp(answer.project.tempo_bpm, 20, 400, 0) || null;
        answer.project.project_sample_rate = clamp(answer.project.project_sample_rate, 0, 768000, 0) || null;
        answer.track.tempo_bpm = answer.project.tempo_bpm;

        shell.querySelectorAll('[data-review-stem]').forEach((row, index) => {
          const original = stems[index] || {};
          const stem = {
            file_name: text(original.file_name),
            source_track_name: text(original.source_track_name),
            track_guid: text(original.track_guid)
          };
          row.querySelectorAll('[data-stem-field]').forEach(input => {
            stem[input.dataset.stemField] = text(input.value);
          });
          stem.volume = clamp(stem.volume, 0, 8, 1);
          stem.pan = clamp(stem.pan, -1, 1, 0);
          if (!ROLES.includes(stem.stem_role)) stem.stem_role = 'Other';
          answer.stems.push(stem);
        });
        return answer;
      }

      function onKey(event) {
        if (event.key === 'Escape') finish(null);
      }
      document.addEventListener('keydown', onKey);
      shell.querySelector('[data-review-autofill]')?.addEventListener('click', assignDefaults);
      shell.querySelector('[data-review-cancel]')?.addEventListener('click', () => finish(null));
      shell.querySelector('[data-review-save]')?.addEventListener('click', () => finish(collect()));
    });
  }

  window.STONEFELLOW_STEM_IMPORT_META = {
    parseRppText,
    inferRole,
    inferInstrument,
    review,
    cleanPluginName
  };
})();