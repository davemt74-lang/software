(() => {
  'use strict';

  const BUILD = 'artist-listening-ui';
  const cfg = window.STONEFELLOW_ARTIST_LISTENING_V172 || {};
  const editEndpoint = String(cfg.endpoint || '/api/artist-listening-v172.php')
    .replace(/artist-listening-v172\.php(?:\?.*)?$/i, 'artist-listening-edit-v249.php');
  const longEndpoint = String(
    cfg.longEndpoint || String(cfg.endpoint || '/api/artist-listening-v172.php')
      .replace(/artist-listening-v172\.php(?:\?.*)?$/i, 'artist-listening-long-v237.php')
  );

  const proof = window.STONEFELLOW_ARTIST_LISTENING_UI = {
    build: BUILD,
    loaded: true,
    logoMounted: false,
    exitMounted: false,
    clipMenusCompacted: 0,
    continuousView: false,
    continuousEditVisible: false,
    continuousEditActive: false,
    continuousSectionsDecorated: 0,
    csrfRefreshes: 0,
    saves: 0,
    deletes: 0,
    restores: 0,
    undos: 0,
    lastError: '',
  };

  const edit = {
    active: false,
    pending: new Map(),
    saving: new Set(),
    dirty: new Set(),
    lastSaved: new Map(),
    undo: [],
    undoing: false,
    pageCache: new Map(),
    pageObserver: null,
  };

  let editCsrf = String(cfg.csrf || '');
  const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const currentSessionId = () => Math.max(0, Number(window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.currentSessionId || cfg.initialSessionId || 0));
  const continuousContainer = () => document.getElementById('sfListeningTranscriptContinuous');
  const continuousActive = () => Boolean(continuousContainer() && !continuousContainer().hidden);
  const nav = () => document.getElementById('sfListeningTranscriptNav');


  function compactClipMenu(article) {
    const details = article.querySelector('.sf-listening-workspace-clip-menu');
    const flyout = details?.querySelector(':scope > div');
    const actions = article.querySelector('[data-v198-workspace-actions]');
    if (!flyout || !actions) return false;

    const play = flyout.querySelector(':scope > [data-listening-workspace-play-recording]');
    flyout.querySelectorAll(':scope > a[download]').forEach(anchor => anchor.remove());
    if (play) play.classList.add('sf-listening-ui-clip-menu-item');

    const favorite = actions.querySelector('[data-v198-action="favorite"]');
    const download = actions.querySelector('a[download]');
    const rename = actions.querySelector('[data-v198-action="rename"]');
    const remove = actions.querySelector('[data-v198-action="delete"]');
    const ordered = [favorite, download, rename, remove].filter(Boolean);
    const current = [...actions.children];
    const changed = current.length !== ordered.length || current.some((node, index) => node !== ordered[index]);
    if (changed) actions.replaceChildren(...ordered);
    if (actions.className !== 'sf-listening-ui-clip-menu-actions') actions.className = 'sf-listening-ui-clip-menu-actions';
    for (const control of ordered) control.classList.add('sf-listening-ui-clip-menu-item');
    if (actions.parentElement !== flyout) flyout.appendChild(actions);

    if (article.dataset.listeningUiMenuReady !== '1') {
      article.dataset.listeningUiMenuReady = '1';
      proof.clipMenusCompacted += 1;
    }
    return true;
  }

  function compactClipMenus() {
    document.querySelectorAll('.sf-listening-workspace-recording').forEach(compactClipMenu);
  }

  async function refreshEditCsrf() {
    const target = new URL(editEndpoint, location.href);
    target.searchParams.set('action', 'csrf');
    target.searchParams.set('_', String(Date.now()));
    const response = await fetch(target.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {Accept:'application/json'},
    });
    const data = await response.json().catch(() => ({ok:false,error:'Could not refresh the edit session.'}));
    const next = String(data.csrf || '');
    if (!response.ok || !data.ok || !next) throw new Error(String(data.error || 'Could not refresh the edit session.'));
    editCsrf = next;
    cfg.csrf = next;
    proof.csrfRefreshes += 1;
    return next;
  }

  async function editRequest(action, payload = {}, retried = false) {
    const response = await fetch(editEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({action, csrf_token:editCsrf, ...payload}),
    });
    const data = await response.json().catch(() => ({ok:false,error:'Transcript edit returned an invalid response.'}));
    if (response.status === 419 && !retried) {
      await refreshEditCsrf();
      return editRequest(action, payload, true);
    }
    if (!response.ok || !data.ok) throw new Error(String(data.error || `Transcript edit failed (${response.status}).`));
    return data;
  }

  async function fetchContinuousPage(pageNumber) {
    const sid = currentSessionId();
    if (!sid || !pageNumber) return null;
    const key = `${sid}:${pageNumber}`;
    if (edit.pageCache.has(key)) return edit.pageCache.get(key);

    const target = new URL(longEndpoint, location.href);
    target.searchParams.set('action', 'page');
    target.searchParams.set('session_id', String(sid));
    target.searchParams.set('page', String(pageNumber));
    const promise = fetch(target.toString(), {credentials:'same-origin',headers:{Accept:'application/json'}})
      .then(async response => {
        const data = await response.json().catch(() => ({ok:false,error:'Unable to load transcript section metadata.'}));
        if (!response.ok || !data.ok) throw new Error(String(data.error || 'Unable to load transcript section metadata.'));
        return data.page || null;
      })
      .catch(error => {
        edit.pageCache.delete(key);
        proof.lastError = String(error?.message || error);
        return null;
      });
    edit.pageCache.set(key, promise);
    return promise;
  }

  function editControls() {
    return {
      edit: document.querySelector('[data-listening-edit-edit]'),
      undo: document.querySelector('[data-listening-edit-undo]'),
      status: document.querySelector('[data-listening-edit-edit-status]'),
    };
  }

  function setEditStatus(text = '', kind = 'saved') {
    const status = editControls().status;
    if (!status) return;
    status.textContent = text;
    status.dataset.state = kind;
    status.hidden = !edit.active || !text;
  }

  function updateUndoButton() {
    const button = editControls().undo;
    if (!button) return;
    button.hidden = !edit.active;
    button.disabled = !edit.undo.length || edit.undoing;
    button.title = edit.undo.length ? 'Undo last saved section change · Ctrl/Cmd+Z' : 'Nothing to undo';
  }


  function ensureContinuousEditControls() {
    const bar = nav();
    const activeView = continuousActive();
    proof.continuousView = activeView;
    proof.continuousEditVisible = activeView;
    if (!bar || !activeView) {
      document.querySelectorAll('[data-listening-edit-edit],[data-listening-edit-undo],[data-listening-edit-edit-status]').forEach(node => node.remove());
      return false;
    }

    let button = bar.querySelector('[data-listening-edit-edit]');
    if (!button) {
      button = document.createElement('button');
      button.type = 'button';
      button.className = 'sf-listening-edit-edit-toggle';
      button.dataset.listeningEditEdit = '1';
      button.textContent = 'Edit';
      button.title = 'Edit Continuous View sections';
      const continuous = bar.querySelector('[data-listening-transcript-continuous]');
      if (continuous) continuous.insertAdjacentElement('afterend', button);
      else bar.appendChild(button);
    }

    let undo = bar.querySelector('[data-listening-edit-undo]');
    if (!undo) {
      undo = document.createElement('button');
      undo.type = 'button';
      undo.className = 'sf-listening-edit-undo';
      undo.dataset.listeningEditUndo = '1';
      undo.textContent = 'Undo';
      undo.hidden = true;
      button.insertAdjacentElement('afterend', undo);
    }

    let status = bar.querySelector('[data-listening-edit-edit-status]');
    if (!status) {
      status = document.createElement('span');
      status.className = 'sf-listening-edit-edit-status';
      status.dataset.listeningEditEditStatus = '1';
      status.hidden = true;
      undo.insertAdjacentElement('afterend', status);
    }

    button.textContent = edit.active ? 'Done' : 'Edit';
    button.classList.toggle('active', edit.active);
    undo.hidden = !edit.active;
    status.hidden = !edit.active || !status.textContent;
    updateUndoButton();
    return true;
  }

  function snapshotTurn(turn) {
    const textNode = turn.querySelector('[data-listening-edit-text]');
    const paragraph = turn.querySelector(':scope > p');
    return {
      text: String(textNode?.value ?? paragraph?.textContent ?? ''),
      speaker: String(turn.dataset.listeningEditSpeakerLabel || 'Speaker 1'),
      speakerDisplay: String(turn.querySelector(':scope > div strong')?.textContent || 'Speaker 1'),
      time: String(turn.querySelector(':scope > div time')?.textContent || ''),
      page: Math.max(1, Number(turn.closest('[data-listening-transcript-cont-page]')?.dataset.listeningTranscriptContPage || 1)),
    };
  }

  function makeTurnEditable(turn) {
    const id = Math.max(0, Number(turn.dataset.listeningEditSegmentId || 0));
    if (!edit.active || !id) return;

    let textarea = turn.querySelector('[data-listening-edit-text]');
    if (!textarea) {
      const paragraph = turn.querySelector(':scope > p');
      textarea = document.createElement('textarea');
      textarea.dataset.listeningEditText = '1';
      textarea.value = String(paragraph?.textContent || '');
      textarea.setAttribute('aria-label', 'Transcript section');
      if (paragraph) paragraph.replaceWith(textarea);
      else turn.appendChild(textarea);
    }

    let remove = turn.querySelector('[data-listening-edit-delete]');
    if (!remove) {
      remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'sf-listening-edit-delete';
      remove.dataset.listeningEditDelete = '1';
      remove.textContent = 'Delete';
      remove.title = 'Delete this transcript section';
      turn.querySelector(':scope > div')?.appendChild(remove);
    }

    turn.classList.add('sf-listening-edit-editable');
    if (!edit.lastSaved.has(id)) edit.lastSaved.set(id, snapshotTurn(turn));
  }

  function makeTurnReadonly(turn) {
    const textarea = turn.querySelector('[data-listening-edit-text]');
    if (textarea) {
      const paragraph = document.createElement('p');
      paragraph.textContent = textarea.value;
      textarea.replaceWith(paragraph);
    }
    turn.querySelector('[data-listening-edit-delete]')?.remove();
    turn.classList.remove('sf-listening-edit-editable');
  }

  async function decorateContinuousPage(article) {
    if (!edit.active || !article || article.dataset.listeningEditDecorating === '1') return;
    const pageNumber = Math.max(1, Number(article.dataset.listeningTranscriptContPage || 1));
    article.dataset.listeningEditDecorating = '1';
    try {
      const page = await fetchContinuousPage(pageNumber);
      if (!page || !article.isConnected || !edit.active) return;
      const rows = (Array.isArray(page.segments) ? page.segments : [])
        .filter(row => String(row.segment_type || row.type || 'transcript') === 'transcript');
      const turns = [...article.querySelectorAll(':scope > .sf-listening-transcript-turn')];
      turns.forEach((turn, index) => {
        const row = rows[index] || {};
        const id = Math.max(0, Number(row.id || row.segment_id || 0));
        if (id) turn.dataset.listeningEditSegmentId = String(id);
        turn.dataset.listeningEditSpeakerLabel = String(row.speaker_label || row.speaker || 'Speaker 1');
        turn.dataset.listeningEditPage = String(pageNumber);
        makeTurnEditable(turn);
      });
      article.dataset.listeningEditReady = '1';
      proof.continuousSectionsDecorated += turns.length;
    } finally {
      delete article.dataset.listeningEditDecorating;
    }
  }

  function decorateContinuousPages() {
    if (!edit.active || !continuousActive()) return;
    document.querySelectorAll('#sfListeningTranscriptContinuous [data-listening-transcript-cont-page]')
      .forEach(article => void decorateContinuousPage(article));
  }

  function startContinuousPageObserver() {
    stopContinuousPageObserver();
    const pages = document.querySelector('[data-listening-transcript-continuous-pages]');
    if (!pages || !edit.active) return;
    edit.pageObserver = new MutationObserver(records => {
      for (const record of records) {
        for (const node of record.addedNodes) {
          if (!(node instanceof Element)) continue;
          if (node.matches?.('[data-listening-transcript-cont-page]')) void decorateContinuousPage(node);
          node.querySelectorAll?.('[data-listening-transcript-cont-page]').forEach(article => void decorateContinuousPage(article));
        }
      }
    });
    edit.pageObserver.observe(pages, {childList:true, subtree:true});
  }

  function stopContinuousPageObserver() {
    edit.pageObserver?.disconnect();
    edit.pageObserver = null;
  }

  function scheduleSave(turn) {
    if (!edit.active) return;
    const id = Math.max(0, Number(turn?.dataset?.listeningEditSegmentId || 0));
    if (!id) return;
    const pending = edit.pending.get(id);
    if (pending?.timer) clearTimeout(pending.timer);
    setEditStatus('Saving…', 'saving');
    const timer = setTimeout(() => {
      edit.pending.delete(id);
      void saveTurn(turn);
    }, 700);
    edit.pending.set(id, {timer, turn});
  }

  async function saveTurn(turn) {
    const sid = currentSessionId();
    const id = Math.max(0, Number(turn?.dataset?.listeningEditSegmentId || 0));
    if (!edit.active || !sid || !id || !turn?.isConnected) return false;
    if (edit.saving.has(id)) {
      edit.dirty.add(id);
      return false;
    }

    const after = snapshotTurn(turn);
    const before = edit.lastSaved.get(id) || after;
    if (before.text === after.text && before.speaker === after.speaker) {
      if (!edit.pending.size && !edit.saving.size) setEditStatus('Saved', 'saved');
      return true;
    }
    if (!after.text.trim()) {
      setEditStatus('A section cannot be empty. Delete it instead.', 'error');
      return false;
    }

    edit.saving.add(id);
    setEditStatus('Saving…', 'saving');
    try {
      await editRequest('update_segment', {
        session_id:sid,
        segment_id:id,
        speaker_label:after.speaker,
        text:after.text,
      });
      if (!edit.undoing) {
        edit.undo.push({type:'edit',sessionId:sid,segmentId:id,before:{...before},after:{...after}});
        if (edit.undo.length > 100) edit.undo.shift();
      }
      edit.lastSaved.set(id, {...after});
      proof.saves += 1;
      proof.lastError = '';
      updateUndoButton();
      return true;
    } catch (error) {
      proof.lastError = String(error?.message || error);
      setEditStatus(proof.lastError, 'error');
      return false;
    } finally {
      edit.saving.delete(id);
      if (edit.dirty.delete(id) && turn.isConnected) void saveTurn(turn);
      else if (!edit.pending.size && !edit.saving.size && !proof.lastError) setEditStatus('Saved', 'saved');
    }
  }

  async function flushTurn(turn) {
    const id = Math.max(0, Number(turn?.dataset?.listeningEditSegmentId || 0));
    const pending = edit.pending.get(id);
    if (pending?.timer) clearTimeout(pending.timer);
    edit.pending.delete(id);
    await saveTurn(turn);
    while (edit.saving.has(id) || edit.dirty.has(id)) await delay(20);
  }

  async function flushAll() {
    const turns = [];
    for (const [id, pending] of edit.pending.entries()) {
      if (pending?.timer) clearTimeout(pending.timer);
      if (pending?.turn?.isConnected) turns.push(pending.turn);
      edit.pending.delete(id);
    }
    await Promise.all(turns.map(turn => saveTurn(turn)));
    while (edit.saving.size || edit.dirty.size) await delay(20);
  }

  async function enterContinuousEdit() {
    if (edit.active || !continuousActive() || !currentSessionId()) return;
    edit.active = true;
    proof.continuousEditActive = true;
    document.body.classList.add('sf-listening-edit-continuous-edit');
    ensureContinuousEditControls();
    setEditStatus('Saved', 'saved');
    startContinuousPageObserver();
    decorateContinuousPages();
  }

  async function exitContinuousEdit(silent = false) {
    if (!edit.active) return;
    await flushAll();
    edit.active = false;
    proof.continuousEditActive = false;
    stopContinuousPageObserver();
    document.body.classList.remove('sf-listening-edit-continuous-edit');
    document.querySelectorAll('#sfListeningTranscriptContinuous .sf-listening-transcript-turn').forEach(makeTurnReadonly);
    ensureContinuousEditControls();
    if (!silent) setEditStatus('', 'saved');
  }

  async function deleteContinuousTurn(turn) {
    if (!edit.active || edit.undoing) return;
    const sid = currentSessionId();
    const id = Math.max(0, Number(turn?.dataset?.listeningEditSegmentId || 0));
    if (!sid || !id) return;
    await flushTurn(turn);

    const before = edit.lastSaved.get(id) || snapshotTurn(turn);
    const article = turn.closest('[data-listening-transcript-cont-page]');
    const siblings = article ? [...article.querySelectorAll(':scope > .sf-listening-transcript-turn')] : [];
    const index = Math.max(0, siblings.indexOf(turn));
    setEditStatus('Deleting section…', 'saving');
    try {
      await editRequest('delete_segment', {session_id:sid,segment_id:id});
      edit.undo.push({type:'delete',sessionId:sid,segmentId:id,before:{...before},index});
      if (edit.undo.length > 100) edit.undo.shift();
      edit.lastSaved.delete(id);
      turn.remove();
      proof.deletes += 1;
      updateUndoButton();
      setEditStatus('Section deleted · Ctrl/Cmd+Z to undo', 'saved');
    } catch (error) {
      proof.lastError = String(error?.message || error);
      setEditStatus(proof.lastError, 'error');
    }
  }

  function restoreDeletedTurn(operation) {
    const article = document.querySelector(`[data-listening-transcript-cont-page="${operation.before.page}"]`);
    if (!article) return null;
    const turn = document.createElement('div');
    turn.className = 'sf-listening-transcript-turn';
    turn.dataset.listeningEditSegmentId = String(operation.segmentId);
    turn.dataset.listeningEditSpeakerLabel = String(operation.before.speaker || 'Speaker 1');
    turn.dataset.listeningEditPage = String(operation.before.page || 1);
    turn.innerHTML = `<div><strong>${esc(operation.before.speakerDisplay || operation.before.speaker || 'Speaker 1')}</strong><time>${esc(operation.before.time || '')}</time></div><p>${esc(operation.before.text || '')}</p>`;
    const turns = [...article.querySelectorAll(':scope > .sf-listening-transcript-turn')];
    const anchor = turns[operation.index] || null;
    if (anchor) article.insertBefore(turn, anchor);
    else article.insertBefore(turn, article.querySelector('.sf-listening-transcript-empty') || null);
    if (edit.active) makeTurnEditable(turn);
    return turn;
  }

  async function undoLast() {
    if (!edit.active || edit.undoing || !edit.undo.length) return;
    await flushAll();
    const operation = edit.undo.pop();
    edit.undoing = true;
    updateUndoButton();
    setEditStatus('Undoing…', 'saving');
    try {
      if (operation.type === 'edit') {
        await editRequest('update_segment', {
          session_id:operation.sessionId,
          segment_id:operation.segmentId,
          speaker_label:operation.before.speaker,
          text:operation.before.text,
        });
        const turn = document.querySelector(`[data-listening-edit-segment-id="${operation.segmentId}"]`);
        const text = turn?.querySelector('[data-listening-edit-text]');
        const paragraph = turn?.querySelector(':scope > p');
        if (text) text.value = operation.before.text;
        if (paragraph) paragraph.textContent = operation.before.text;
        edit.lastSaved.set(operation.segmentId, {...operation.before});
      } else if (operation.type === 'delete') {
        await editRequest('restore_segment', {session_id:operation.sessionId,segment_id:operation.segmentId});
        restoreDeletedTurn(operation);
        edit.lastSaved.set(operation.segmentId, {...operation.before});
        proof.restores += 1;
      }
      proof.undos += 1;
      proof.lastError = '';
      setEditStatus('Undo complete · Saved', 'saved');
    } catch (error) {
      edit.undo.push(operation);
      proof.lastError = String(error?.message || error);
      setEditStatus(proof.lastError, 'error');
    } finally {
      edit.undoing = false;
      updateUndoButton();
    }
  }

  function syncStaticUi() {
    
    compactClipMenus();
  }

  function syncViewUi() {
    proof.continuousView = continuousActive();
    if (!proof.continuousView && edit.active) {
      void exitContinuousEdit(true);
    }
    ensureContinuousEditControls();
  }

  function bindEvents() {
    document.addEventListener('click', event => {
      const editButton = event.target.closest?.('[data-listening-edit-edit]');
      if (editButton) {
        event.preventDefault();
        void (edit.active ? exitContinuousEdit() : enterContinuousEdit());
        return;
      }

      const undo = event.target.closest?.('[data-listening-edit-undo]');
      if (undo) {
        event.preventDefault();
        void undoLast();
        return;
      }

      const remove = event.target.closest?.('[data-listening-edit-delete]');
      if (remove) {
        event.preventDefault();
        void deleteContinuousTurn(remove.closest('.sf-listening-transcript-turn'));
      }
    });

    window.addEventListener('stonefellow:artist-listening-view-changed', event => {
      const view = String(event?.detail?.view || '');
      proof.continuousView = view === 'continuous';
      syncViewUi();
      if (view === 'continuous') ensureContinuousEditControls();
    });

    document.addEventListener('input', event => {
      if (!edit.active || !event.target.matches?.('[data-listening-edit-text]')) return;
      const turn = event.target.closest('.sf-listening-transcript-turn');
      if (turn) scheduleSave(turn);
    });

    document.addEventListener('keydown', event => {
      if (!edit.active || !(event.ctrlKey || event.metaKey) || event.shiftKey || event.key.toLowerCase() !== 'z') return;
      const turn = event.target.closest?.('.sf-listening-transcript-turn');
      const id = Math.max(0, Number(turn?.dataset?.listeningEditSegmentId || 0));
      if (turn && edit.pending.has(id) && event.target.matches?.('[data-listening-edit-text]')) {
        setTimeout(() => scheduleSave(turn), 0);
        return;
      }
      if (!edit.undo.length) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      void undoLast();
    }, true);
  }

  function boot() {
    bindEvents();
    let attempts = 0;
    const tick = () => {
      attempts += 1;
      syncStaticUi();
      syncViewUi();
      if (attempts < 20) setTimeout(tick, attempts < 6 ? 180 : 600);
    };
    setTimeout(tick, 40);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true});
  else boot();
})();
