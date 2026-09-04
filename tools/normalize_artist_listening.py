#!/usr/bin/env python3
from __future__ import annotations

from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
TEXT_SUFFIXES = {'.php', '.js', '.css', '.mjs', '.cjs', '.yml', '.yaml', '.md', '.html', '.txt'}

RENAMES = {
    'artist-listening-v172.js': 'artist-listening.js',
    'artist-listening-continuous-v173.js': 'artist-listening-recognition.js',
    'artist-listening-workspace-v175.js': 'artist-listening-workspace.js',
    'artist-listening-realtime-v176.js': 'artist-listening-realtime.js',
    'artist-listening-naming-v196.js': 'artist-listening-naming.js',
    'artist-recordings-v198.js': 'artist-listening-recordings.js',
    'artist-listening-long-v237.js': 'artist-listening-transcript.js',
    'artist-listening-ui-v242.js': 'artist-listening-ui.js',
    'includes/artist-listening-v172.php': 'includes/artist-listening.php',
    'includes/artist-listening-long-v237.php': 'includes/artist-listening-transcript.php',
    'tests/artist-listening-continuous-v173.mjs': 'tests/artist-listening-recognition.mjs',
    'tests/artist-listening-edit-v249.mjs': 'tests/artist-listening-edit.mjs',
    'tests/artist-listening-enhancements-v174.mjs': 'tests/artist-listening-enhancements.mjs',
    'tests/artist-listening-intelligence-v254.mjs': 'tests/artist-listening-ai.mjs',
    'tests/artist-listening-library-v177.mjs': 'tests/artist-listening-library.mjs',
    'tests/artist-listening-long-v237.mjs': 'tests/artist-listening-transcript.mjs',
    'tests/artist-listening-realtime-v176.cjs': 'tests/artist-listening-realtime.cjs',
    'tests/artist-listening-runtime-v256.mjs': 'tests/artist-listening-runtime.mjs',
    'tests/artist-listening-ui-v242.mjs': 'tests/artist-listening-ui.mjs',
    'tests/artist-listening-workspace-boot-v224.mjs': 'tests/artist-listening-workspace-boot.mjs',
    'tests/artist-listening-workspace-v175.mjs': 'tests/artist-listening-workspace.mjs',
    'tests/artist-recordings-v198.mjs': 'tests/artist-listening-recordings.mjs',
}

CSS_SOURCES = [
    'artist-listening-v172.css',
    'artist-listening-workspace-v175.css',
    'artist-listening-intelligence-v236.css',
    'artist-listening-long-v237.css',
    'artist-listening-ui-v242.css',
]

DELETE_FRONTEND = [
    'artist-listening-intelligence-v236.js',
    'artist-listening-intelligence-v254.js',
    'artist-listening-edit-v249.js',
    'artist-listening-edit-v249.css',
    *CSS_SOURCES,
]

REFERENCE_REPLACEMENTS = {
    **{Path(old).name: Path(new).name for old, new in RENAMES.items()},
    'artist-listening-intelligence-v236.js': 'artist-listening-ai.js',
    'artist-listening-intelligence-v254.js': 'artist-listening-ai.js',
    'artist-listening-v172.css': 'artist-listening.css',
    'artist-listening-workspace-v175.css': 'artist-listening.css',
    'artist-listening-intelligence-v236.css': 'artist-listening.css',
    'artist-listening-long-v237.css': 'artist-listening.css',
    'artist-listening-ui-v242.css': 'artist-listening.css',
}

IDENTIFIER_REPLACEMENTS = {
    'STONEFELLOW_ARTIST_LISTENING_WORKSPACE_V175': 'STONEFELLOW_ARTIST_LISTENING_WORKSPACE',
    'STONEFELLOW_ARTIST_LISTENING_REALTIME_V176': 'STONEFELLOW_ARTIST_LISTENING_REALTIME',
    'STONEFELLOW_ARTIST_LISTENING_CONTINUOUS_V173': 'STONEFELLOW_ARTIST_LISTENING_RECOGNITION',
    'STONEFELLOW_ARTIST_LISTENING_LONG_V237': 'STONEFELLOW_ARTIST_LISTENING_TRANSCRIPT',
    'sf-transcript-workspace-v175': 'sf-transcript-workspace',
    'sfLongTranscriptNavV237': 'sfListeningTranscriptNav',
    'sfLongTranscriptContinuousV237': 'sfListeningTranscriptContinuous',
    'sf-v175-': 'sf-listening-workspace-',
    'data-v175-': 'data-listening-workspace-',
    'dataset.v175': 'dataset.listeningWorkspace',
    'sf-v237-': 'sf-listening-transcript-',
    'data-v237-': 'data-listening-transcript-',
    'dataset.v237': 'dataset.listeningTranscript',
    'sf-v242-': 'sf-listening-ui-',
    'data-v242-': 'data-listening-ui-',
    'dataset.v242': 'dataset.listeningUi',
    'sf-v251-': 'sf-listening-edit-',
    'data-v251-': 'data-listening-edit-',
    'dataset.v251': 'dataset.listeningEdit',
    'data-v252-': 'data-listening-edit-',
    'dataset.v252': 'dataset.listeningEdit',
    'sf-v236-': 'sf-listening-ai-',
    'data-v236-': 'data-listening-ai-',
    'dataset.v236': 'dataset.listeningAi',
    'sf-v236-open': 'sf-listening-ai-open',
    'sfListeningIntelligenceV236': 'sfListeningAiPanel',
    '__v237Compacted': '__listeningTranscriptCompacted',
    'v237_page': 'transcript_page',
    'v237_pages': 'transcript_pages',
    'v237_paged': 'transcript_paged',
    "source:'v237-canonical'": "source:'transcript'",
}

AI_EXTRA_CSS = r'''
/* Canonical AI Summary */
.sf-listening-ai-footer{display:grid!important;gap:9px!important;align-items:stretch!important;padding:12px 14px!important;background:#fafafa!important}
.sf-listening-ai-footer-primary,.sf-listening-ai-footer-save{display:flex;align-items:center;gap:8px;min-width:0}
.sf-listening-ai-footer-primary [data-listening-ai-analyze]{min-width:84px;background:#171717!important;border-color:#171717!important;color:#fff!important}
.sf-listening-ai-footer-primary [data-listening-ai-saved]{flex:1;color:#656565!important;font-size:10px!important;white-space:normal!important}
.sf-listening-ai-footer-save{flex-wrap:wrap}.sf-listening-ai-footer-save button{flex:1 1 150px;min-height:34px}
.sf-listening-ai-footer-status{display:block!important;color:#777!important;font-size:9px!important;line-height:1.35!important;white-space:normal!important}
.sf-listening-ai-report{padding:15px 0!important;border-bottom:0!important}.sf-listening-ai-report>h3{font-size:13px!important;margin:0 0 10px!important}
.sf-listening-ai-report-copy{font-size:12px!important;line-height:1.6!important;color:#333!important}.sf-listening-ai-report section{padding:13px 0!important;border-bottom:1px solid #ececec!important}.sf-listening-ai-report section:last-child{border-bottom:0!important}
.sf-listening-ai-report h4{margin:0 0 8px;font-size:11px;color:#222}.sf-listening-ai-report ul{margin:0;padding-left:18px}.sf-listening-ai-report li{font-size:11px!important;line-height:1.5!important;color:#555!important}.sf-listening-ai-empty{color:#777!important}
@media(max-width:560px){.sf-listening-ai-footer-primary{align-items:flex-start;flex-direction:column}.sf-listening-ai-footer-save{display:grid;grid-template-columns:1fr;width:100%}.sf-listening-ai-footer-save button{width:100%}}
'''


def run(*args: str) -> None:
    subprocess.run(args, cwd=ROOT, check=True)


def path(name: str) -> Path:
    return ROOT / name


def read(name: str) -> str:
    return path(name).read_text(encoding='utf-8')


def write(name: str, content: str) -> None:
    path(name).write_text(content, encoding='utf-8')


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def replace_required(text: str, old: str, new: str, label: str, count: int | None = None) -> str:
    found = text.count(old)
    if count is not None:
        require(found == count, f'{label}: expected {count} occurrence(s), found {found}')
    else:
        require(found > 0, f'{label}: required pattern not found')
    return text.replace(old, new)


def remove_function(text: str, name: str) -> str:
    pattern = re.compile(rf'\n  (?:async\s+)?function\s+{re.escape(name)}\([^\n]*\)\s*\{{.*?\n  \}}\n', re.S)
    text, count = pattern.subn('\n', text, count=1)
    require(count == 1, f'Could not remove function {name}')
    return text


def canonicalize_identifiers(text: str) -> str:
    for old, new in IDENTIFIER_REPLACEMENTS.items():
        text = text.replace(old, new)
    return text


def canonicalize_workspace() -> None:
    name = 'artist-listening-workspace.js'
    text = read(name)

    text, copy_count = re.subn(r'\n\s*<button[^\n>]*data-v175-copy[^\n>]*>Copy</button>', '', text)
    text, download_count = re.subn(r'\n\s*<button[^\n>]*data-v175-download[^\n>]*>Download</button>', '', text)
    require(copy_count == 1 and download_count == 1, 'Workspace Copy/Download markup was not removed exactly once')

    text = remove_function(text, 'copyDocument')
    text = remove_function(text, 'downloadDocument')
    text = '\n'.join(
        line for line in text.splitlines()
        if 'data-v175-copy' not in line and 'data-v175-download' not in line
    ) + '\n'
    require('copyDocument' not in text and 'downloadDocument' not in text, 'Workspace Copy/Download handlers still exist')

    old_header = '      <header class="sf-v175-editor-top">\n        <input class="sf-v175-title-input"'
    new_header = '      <header class="sf-v175-editor-top">\n        <a class="sf-v175-exit" data-v175-exit href="chat.php" title="Exit Artist Listening" aria-label="Exit Artist Listening">EXIT</a>\n        <input class="sf-v175-title-input"'
    text = replace_required(text, old_header, new_header, 'Workspace EXIT source markup', 1)

    old_ai = '<button type="button" class="sf-v236-toggle" data-v236-toggle aria-expanded="false" title="Open AI research and transcript analysis"><span>AI Summary</span><b data-v236-badge>OFF</b></button>'
    new_ai = '<button type="button" class="sf-listening-ai-toggle" data-listening-ai-toggle aria-expanded="false" title="Open AI Summary"><span>AI Summary</span><b data-listening-ai-badge>OFF</b></button>'
    text = replace_required(text, old_ai, new_ai, 'Workspace canonical AI button', 1)

    metadata_old = '      state.current = data.session;\n      proof.saves += 1;\n      await loadLibrary({openId:state.current.id});'
    metadata_new = '      state.current = data.session;\n      proof.saves += 1;\n      window.dispatchEvent(new CustomEvent(\'stonefellow:artist-listening-metadata-saved\', {detail:{session:state.current}}));\n      await loadLibrary({openId:state.current.id});'
    text = replace_required(text, metadata_old, metadata_new, 'Workspace metadata saved event', 1)

    fetch_start = text.find('  // Truthful save state for the existing v172 capture API and the v174 document API.')
    fetch_end_marker = '\n  async function request(endpoint, action, payload = {}, method = \'GET\') {'
    fetch_end = text.find(fetch_end_marker, fetch_start)
    require(fetch_start >= 0 and fetch_end > fetch_start, 'Workspace fetch tracking block not found')
    block = text[fetch_start:fetch_end]
    block = block.replace('window.fetch = async (...args) => {', 'async function trackedFetch(...args) {', 1)
    require(block.rstrip().endswith('};'), 'Workspace fetch tracking block has unexpected ending')
    block = block.rstrip()[:-2] + '}\n'
    text = text[:fetch_start] + block + text[fetch_end:]
    text = text.replace('const response = await fetch(url, options);', 'const response = await trackedFetch(url, options);', 1)
    require('window.fetch =' not in text, 'Workspace still overrides window.fetch')

    adapter_old = "  const api174 = (action, payload = {}, method = 'GET') => request(endpoint174, action, payload, method);"
    adapter_new = """  const api174 = (action, payload = {}, method = 'GET') => {
    const transcript = window.STONEFELLOW_ARTIST_LISTENING_TRANSCRIPT;
    if (method === 'GET' && transcript?.workspaceRequest && ['library','session'].includes(action)) {
      return transcript.workspaceRequest(action, payload);
    }
    return request(endpoint174, action, payload, method);
  };"""
    text = replace_required(text, adapter_old, adapter_new, 'Workspace explicit transcript adapter', 1)

    text = canonicalize_identifiers(text)
    text = text.replace("const BUILD = 'artist-listening-workspace-v256-sidebar-open-20260903';", "const BUILD = 'artist-listening-workspace';")
    write(name, text)


def canonicalize_realtime() -> None:
    name = 'artist-listening-realtime.js'
    text = read(name)
    loader = text.find("\n  if(typeof document!=='undefined'&&!document.querySelector('[data-artist-listening-intelligence-v236]')){")
    require(loader >= 0, 'Realtime hidden AI auto-loader not found')
    close = text.rfind('\n})();')
    require(close > loader, 'Realtime closure not found')
    text = text[:loader] + text[close:]
    require('createElement(\'script\')' not in text and 'artist-listening-intelligence-v236' not in text, 'Realtime AI auto-loader still exists')
    text = canonicalize_identifiers(text)
    text = text.replace("const BUILD = 'artist-listening-speaker-identity-v236-20260902';", "const BUILD = 'artist-listening-realtime';")
    write(name, text)


def canonicalize_recognition() -> None:
    name = 'artist-listening-recognition.js'
    text = canonicalize_identifiers(read(name))
    text = text.replace("const BUILD = 'artist-listening-continuous-v173-20260831';", "const BUILD = 'artist-listening-recognition';")
    write(name, text)


def canonicalize_transcript() -> None:
    name = 'artist-listening-transcript.js'
    text = read(name)

    text = re.sub(r"\n  const userId=.*?\n  const aiKey=.*?\n", '\n', text, count=1)
    text = text.replace('    analysis:null,analysisBusy:false,fullAnalysis:false,lastError:\'\',manifestTimer:0,\n', "    lastError:'',manifestTimer:0,\n")
    text = text.replace('    uiObserver:null,uiObserverTimer:0,providerReady:false,schemaReady:false,\n    autoPageWords:new Map(),autoPageAt:new Map(),\n', '    uiObserver:null,uiObserverTimer:0,schemaReady:false,\n')
    text = text.replace('    pageAnalyses:0,masterAnalyses:0,liveCompactions:0,legacyAnalysesSuppressed:0,lastError:\'\',\n', "    liveCompactions:0,lastError:'',\n")
    text = re.sub(r"\n  const aiEnabled=.*?\n", '\n', text, count=1)
    text = re.sub(r"\n  function requestBody\(init=\{\}\)\{.*?\n  \}\n", '\n', text, count=1, flags=re.S)

    fetch_start = text.find('  // v237 owns long-transcript reads.')
    fetch_end = text.find('\n  function clientPages', fetch_start)
    require(fetch_start >= 0 and fetch_end > fetch_start, 'Transcript fetch interception block not found')
    adapter = r'''  async function workspaceRequest(action, payload = {}) {
    if (action === 'library') {
      const data = await api('library');
      proof.libraryFetches += 1;
      for (const row of Array.isArray(data.sessions) ? data.sessions : []) state.meta.set(Number(row.id || 0), row);
      state.schemaReady = !!data.schema_ready;
      return data;
    }
    if (action === 'session') {
      const id = Math.max(0, Number(payload.session_id || 0));
      const page = Math.max(1, Number(state.requested.get(id) || 1));
      const data = await api('page', {session_id:id, page});
      proof.pagedFetches += 1;
      state.schemaReady = !!data.schema_ready;
      const merged = {...(state.meta.get(id) || {}), ...(data.session || {})};
      merged.segments = Array.isArray(data.session?.segments) ? data.session.segments : [];
      merged.continuous_text = String(data.session?.continuous_text || '');
      merged.transcript_page = Number(data.page?.page_number || page);
      merged.transcript_pages = Number(data.page?.page_count || 1);
      merged.transcript_paged = true;
      return {ok:true, session:merged, build:BUILD};
    }
    throw new Error(`Unsupported transcript workspace action: ${action}`);
  }
  proof.workspaceRequest = workspaceRequest;
'''
    text = text[:fetch_start] + adapter + text[fetch_end:]
    require('window.fetch=' not in text and 'legacyAnalysesSuppressed' not in text, 'Transcript still intercepts fetch or legacy AI')

    text = text.replace('    ensureAiUi();\n', '')
    ai_start = text.find('  function ensureAiUi(){')
    ai_end = text.find('\n  function stopBootObserver()', ai_start)
    require(ai_start >= 0 and ai_end > ai_start, 'Transcript AI UI block not found')
    text = text[:ai_start] + text[ai_end:]

    boot_start = text.find('  function bootUiPass(){')
    boot_end = text.find('\n  function renderNavLive', boot_start)
    require(boot_start >= 0 and boot_end > boot_start, 'Transcript boot UI block not found')
    boot_ui = '''  function bootUiPass(){
    const workspaceReady=!!document.querySelector('.sf-v175-editor-top')&&!!document.querySelector('.sf-v175-sidebar');
    if(workspaceReady&&!document.getElementById('sfLongTranscriptNavV237')){
      ensureUi();
      if(state.manifest)renderManifest();
    }
    if(document.getElementById('sfLongTranscriptNavV237'))stopBootObserver();
  }
'''
    text = text[:boot_start] + boot_ui + text[boot_end:]
    text = text.replace('    renderAi();\n', '')
    text = text.replace('      state.analysis=state.manifest?.analysis||null;state.lastError=\'\';renderManifest();', "      state.lastError='';renderManifest();")
    text = text.replace("      state.lastError=String(error?.message||error);proof.lastError=state.lastError;renderAi();", "      state.lastError=String(error?.message||error);proof.lastError=state.lastError;", 1)

    schedule_start = text.find('  function scheduleManifest(')
    schedule_end = text.find('\n  function goPage', schedule_start)
    require(schedule_start >= 0 and schedule_end > schedule_start, 'Transcript manifest scheduler not found')
    schedule = '''  function scheduleManifest(delay=300){
    if(state.manifestTimer)clearTimeout(state.manifestTimer);
    state.manifestTimer=setTimeout(()=>{
      state.manifestTimer=0;
      void loadManifest(state.sessionId,state.page);
    },delay);
  }
'''
    text = text[:schedule_start] + schedule + text[schedule_end:]

    analysis_start = text.find('  async function loadAnalysis(){')
    selected_start = text.find("  window.addEventListener('stonefellow:artist-listening-document-selected'", analysis_start)
    require(analysis_start >= 0 and selected_start > analysis_start, 'Transcript AI analysis section not found')
    text = text[:analysis_start] + text[selected_start:]

    selected_start = text.find("  window.addEventListener('stonefellow:artist-listening-document-selected'")
    selected_end = text.find('\n  function boot(){', selected_start)
    require(selected_start >= 0 and selected_end > selected_start, 'Transcript document-selected/boot section not found')
    selected = '''  window.addEventListener('stonefellow:artist-listening-document-selected',event=>{
    const session=event?.detail?.session;const id=Math.max(0,Number(session?.id||0));
    if(!id){state.sessionId=0;state.manifest=null;return;}
    state.sessionId=id;state.page=Math.max(1,Number(session?.v237_page||state.requested.get(id)||1));state.requested.set(id,state.page);
    if(session?.v237_paged){
      const editor=document.querySelector('[data-v175-editor]');if(editor)editor.readOnly=true;
      const save=document.querySelector('[data-v175-save]');if(save)save.disabled=true;
    }
    void loadManifest(id,state.page);
  });
'''
    text = text[:selected_start] + selected + text[selected_end:]

    boot_start = text.find('  function boot(){')
    boot_end = text.find("\n  if(document.readyState==='loading')", boot_start)
    require(boot_start >= 0 and boot_end > boot_start, 'Transcript boot function not found')
    boot = '''  function boot(){
    bootUiPass();
    if(!document.getElementById('sfLongTranscriptNavV237')){
      state.uiObserver=new MutationObserver(bootUiPass);
      state.uiObserver.observe(document.body,{subtree:true,childList:true});
      state.uiObserverTimer=setTimeout(stopBootObserver,15000);
    }
    const current=Math.max(0,Number(window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE_V175?.currentSessionId||cfg.initialSessionId||0));
    if(current){state.sessionId=current;void loadManifest(current,1);}
  }
'''
    text = text[:boot_start] + boot + text[boot_end:]

    forbidden = ['analyze_master', 'analyze_page', 'ensureAiUi', 'renderAi(', 'artist-listening-intelligence-v236.php']
    for token in forbidden:
        require(token not in text, f'Transcript still owns AI behavior: {token}')

    text = canonicalize_identifiers(text)
    text = text.replace("const BUILD='artist-listening-long-v263-canonical-continuous-20260903';", "const BUILD='artist-listening-transcript';")
    write(name, text)


def canonicalize_ui() -> None:
    name = 'artist-listening-ui.js'
    text = read(name)
    root_start = text.find('  const rootBase = (() => {')
    root_end = text.find('  const editEndpoint', root_start)
    require(root_start >= 0 and root_end > root_start, 'UI rootBase block not found')
    text = text[:root_start] + text[root_end:]

    logo_start = text.find('  function ensureLogoMark() {')
    logo_end = text.find('\n  function compactClipMenu', logo_start)
    require(logo_start >= 0 and logo_end > logo_start, 'UI header/logo override block not found')
    text = text[:logo_start] + text[logo_end:]

    text = remove_function(text, 'removeLegacyEditControls')
    text = text.replace('    removeLegacyEditControls();\n', '')
    text = text.replace('ensureLogoMark();', '')
    require('ensureLogoMark' not in text and 'data-v243-side-exit' not in text, 'UI still owns header/EXIT')

    text = canonicalize_identifiers(text)
    text = text.replace("const BUILD = 'artist-listening-ui-v263-passive-view-listener-20260903';", "const BUILD = 'artist-listening-ui';")
    write(name, text)


def canonicalize_page() -> None:
    name = 'artist-listening.php'
    text = read(name)
    text = canonicalize_identifiers(text)
    for old, new in REFERENCE_REPLACEMENTS.items():
        text = text.replace(old, new)

    lines = text.splitlines()
    cleaned = []
    removed_css = 0
    runtime_names = {
        'artist-listening-realtime.js', 'artist-listening-recognition.js', 'artist-listening-transcript.js',
        'artist-listening-workspace.js', 'artist-listening.js', 'artist-listening-recordings.js',
        'artist-listening-naming.js', 'artist-listening-ai.js', 'artist-listening-ui.js',
    }
    removed_scripts = 0
    for line in lines:
        if '<link rel="stylesheet"' in line and '/artist-listening.css' in line:
            removed_css += 1
            continue
        if '<script src=' in line and any('/' + runtime in line for runtime in runtime_names):
            removed_scripts += 1
            continue
        cleaned.append(line)
    require(removed_css >= 5, f'Expected duplicate normalized CSS links, found {removed_css}')
    require(removed_scripts >= 10, f'Expected old normalized JS includes, found {removed_scripts}')

    style_index = next(i for i, line in enumerate(cleaned) if line.strip() == '<style>')
    cleaned.insert(style_index, '  <link rel="stylesheet" href="<?= e(url(\'/artist-listening.css?v=artist-listening-normalized-20260903\')) ?>">')

    body_end = next(i for i, line in enumerate(cleaned) if line.strip() == '</body>')
    scripts = [
        '  <script src="<?= e(url(\'/artist-listening-realtime.js?v=artist-listening-normalized-20260903\')) ?>"></script>',
        '  <script src="<?= e(url(\'/artist-listening-recognition.js?v=artist-listening-normalized-20260903\')) ?>"></script>',
        '  <script src="<?= e(url(\'/artist-listening-transcript.js?v=artist-listening-normalized-20260903\')) ?>"></script>',
        '  <script src="<?= e(url(\'/artist-listening-workspace.js?v=artist-listening-normalized-20260903\')) ?>"></script>',
        '  <script src="<?= e(url(\'/artist-listening.js?v=artist-listening-normalized-20260903\')) ?>"></script>',
        '  <script src="<?= e(url(\'/artist-listening-recordings.js?v=artist-listening-normalized-20260903\')) ?>"></script>',
        '  <script src="<?= e(url(\'/artist-listening-naming.js?v=artist-listening-normalized-20260903\')) ?>"></script>',
        '  <script src="<?= e(url(\'/artist-listening-ai.js?v=artist-listening-normalized-20260903\')) ?>"></script>',
        '  <script src="<?= e(url(\'/artist-listening-ui.js?v=artist-listening-normalized-20260903\')) ?>"></script>',
    ]
    cleaned[body_end:body_end] = scripts
    text = '\n'.join(cleaned) + '\n'
    require('data-v175-copy' not in text and 'artist-listening-intelligence-v236.js' not in text, 'Page still references legacy header/AI runtime')
    write(name, text)


def rewrite_references() -> None:
    skip = {'tools/normalize_artist_listening.py', '.github/workflows/normalize-artist-listening.yml'}
    for target in ROOT.rglob('*'):
        if not target.is_file() or target.suffix.lower() not in TEXT_SUFFIXES:
            continue
        rp = target.relative_to(ROOT).as_posix()
        if rp in skip or '.git' in target.parts:
            continue
        try:
            text = target.read_text(encoding='utf-8')
        except UnicodeDecodeError:
            continue
        original = text
        for old, new in REFERENCE_REPLACEMENTS.items():
            text = text.replace(old, new)
        text = canonicalize_identifiers(text)
        if text != original:
            target.write_text(text, encoding='utf-8')


def build_stylesheet() -> None:
    chunks = []
    for source in CSS_SOURCES:
        require(path(source).exists(), f'Missing stylesheet source {source}')
        chunks.append(read(source))
    css = '\n\n'.join(chunks)
    css = canonicalize_identifiers(css)
    css += '\n' + AI_EXTRA_CSS.strip() + '\n'
    css = re.sub(r'/\*\s*v\d+[^*]*\*/\s*', '', css, flags=re.I)
    write('artist-listening.css', css)


def move_files() -> None:
    for old, new in RENAMES.items():
        require(path(old).exists(), f'Missing rename source {old}')
        require(not path(new).exists(), f'Rename target already exists {new}')
        run('git', 'mv', old, new)


def remove_old_frontend() -> None:
    for name in DELETE_FRONTEND:
        if path(name).exists():
            run('git', 'rm', name)


def validate() -> None:
    require(path('artist-listening-ai.js').exists(), 'Canonical AI controller missing')
    page_text = read('artist-listening.php')
    workspace = read('artist-listening-workspace.js')
    realtime = read('artist-listening-realtime.js')
    transcript = read('artist-listening-transcript.js')
    ai = read('artist-listening-ai.js')

    require('>Copy</button>' not in workspace and '>Download</button>' not in workspace, 'Copy/Download still exist in workspace source')
    require('data-listening-ai-toggle' in workspace and '>AI Summary</span>' in workspace, 'Canonical AI Summary button missing from workspace')
    require('>EXIT</a>' in workspace and 'data-listening-workspace-exit' in workspace, 'EXIT is not source-owned by workspace')
    require('createElement(\'script\')' not in realtime and 'artist-listening-intelligence-v236.js' not in realtime, 'Realtime hidden AI loader survived')
    require('window.fetch=' not in transcript and 'window.fetch =' not in transcript, 'Transcript still monkeypatches fetch')
    require('analyze_master' not in transcript and 'analyze_page' not in transcript, 'Transcript still owns AI analysis')
    require('MutationObserver' not in ai, 'AI controller may not repair DOM with MutationObserver')
    require('window.fetch=' not in ai and 'window.fetch =' not in ai, 'AI controller may not monkeypatch fetch')
    require('sfListeningTranscriptNav' not in ai and 'Continuous View' not in ai, 'AI controller touches transcript view')
    require(ai.count("closest?.('[data-listening-ai-toggle]')") == 1, 'AI Summary must have exactly one delegated toggle owner')
    require(page_text.count('/artist-listening-ai.js?') == 1, 'Artist Listening page must load AI exactly once')
    require(page_text.count('/artist-listening.css?') == 1, 'Artist Listening page must load one canonical stylesheet')

    versioned_runtime = []
    pattern = re.compile(r'(?i)(?:artist-listening|artist-recordings)[^/]*-v\d+[^/]*\.(?:js|css)$')
    for target in ROOT.iterdir():
        if target.is_file() and pattern.search(target.name):
            versioned_runtime.append(target.name)
    require(not versioned_runtime, 'Versioned Artist Listening frontend files remain: ' + ', '.join(versioned_runtime))

    versioned_includes = []
    for target in (ROOT / 'includes').glob('artist-listening*-v*.php'):
        versioned_includes.append(target.name)
    require(not versioned_includes, 'Versioned Artist Listening includes remain: ' + ', '.join(versioned_includes))

    versioned_tests = []
    for target in (ROOT / 'tests').glob('artist-listening*-v*.*'):
        versioned_tests.append(target.name)
    require(not versioned_tests, 'Versioned Artist Listening tests remain: ' + ', '.join(versioned_tests))


def main() -> int:
    require(path('artist-listening-ai.js').exists(), 'Create canonical artist-listening-ai.js before normalization')
    build_stylesheet()
    move_files()
    canonicalize_workspace()
    canonicalize_realtime()
    canonicalize_recognition()
    canonicalize_transcript()
    canonicalize_ui()
    remove_old_frontend()
    rewrite_references()
    canonicalize_page()
    validate()
    print('Artist Listening normalized successfully.')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as error:
        print(f'NORMALIZATION FAILED: {error}', file=sys.stderr)
        sys.exit(2)
