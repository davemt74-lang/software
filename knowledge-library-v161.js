(() => {
  'use strict';

  const BUILD = 'knowledge-library-folder-browser-20260914';
  const proof = window.VP3_KNOWLEDGE_LIBRARY_V161 = {
    build: BUILD,
    loaded: true,
    selected: 0,
    dragMoves: 0,
    folderNavigation: true,
    itemDetail: true,
  };

  document.documentElement.classList.add('knowledge-library-enhanced');

  const hardeningStyles = document.createElement('link');
  hardeningStyles.rel = 'stylesheet';
  hardeningStyles.href = '/knowledge-library-folder-browser-v193.css?v=20260914';
  hardeningStyles.dataset.knowledgeFolderBrowserStyles = '1';
  document.head.appendChild(hardeningStyles);

  const selectedBoxes = () => [...document.querySelectorAll('[data-knowledge-select]:checked')];
  const allBoxes = () => [...document.querySelectorAll('[data-knowledge-select]')];
  const interactiveSelector = 'a,button,input,select,textarea,label,summary,details,form';

  function updateBulkState() {
    const boxes = allBoxes();
    const selected = selectedBoxes();
    const master = document.querySelector('[data-knowledge-select-all]');
    const count = document.querySelector('[data-knowledge-selected-count]');
    const button = document.querySelector('[data-knowledge-bulk-submit]');
    proof.selected = selected.length;
    if (count) count.textContent = `${selected.length} selected`;
    if (button) button.disabled = selected.length === 0;
    if (master) {
      master.checked = boxes.length > 0 && selected.length === boxes.length;
      master.indeterminate = selected.length > 0 && selected.length < boxes.length;
    }
  }

  function closeAndRemove(dialog) {
    if (!dialog) return;
    if (dialog.open) dialog.close();
    else dialog.remove();
  }

  function makeDialog(className, label) {
    const returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const dialog = document.createElement('dialog');
    dialog.className = `personal-knowledge-dialog ${className}`;
    dialog.setAttribute('aria-label', label);
    dialog.addEventListener('click', event => {
      if (event.target === dialog) closeAndRemove(dialog);
    });
    dialog.addEventListener('close', () => {
      window.setTimeout(() => {
        dialog.remove();
        if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true });
      }, 0);
    }, { once: true });
    document.body.appendChild(dialog);
    return dialog;
  }

  function dialogHeader(dialog, title, backLabel = 'Close') {
    const header = document.createElement('div');
    header.className = 'personal-knowledge-dialog-head';

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'personal-knowledge-back';
    close.textContent = `← ${backLabel}`;
    close.addEventListener('click', () => closeAndRemove(dialog));

    const heading = document.createElement('strong');
    heading.textContent = title;
    header.append(close, heading);
    dialog.appendChild(header);
    return header;
  }

  function removeLegacyHeroCopy() {
    const hero = document.querySelector('.personal-knowledge-hero');
    if (!hero) return;
    [...hero.children].forEach(child => {
      if (!child.classList.contains('personal-knowledge-stats')) child.remove();
    });
  }

  function removeLegacyHeaderAddAction() {
    document.querySelectorAll('a[href*="/knowledge.php#knowledge-form"]').forEach(link => {
      if (!link.closest('.personal-knowledge-stats')) link.remove();
    });
  }

  function installCreateFolderDialog() {
    const sourceCard = document.querySelector('.personal-knowledge-folder-card.create');
    const sourceForm = sourceCard?.querySelector('form');
    if (!sourceForm) return null;

    sourceCard.remove();
    return () => {
      const dialog = makeDialog('folder-create-dialog', 'Create Knowledge folder');
      dialogHeader(dialog, 'Add Folder', 'Back to folders');
      const shell = document.createElement('div');
      shell.className = 'personal-knowledge-dialog-body personal-knowledge-folder-create-body';
      const form = sourceForm.cloneNode(true);
      const input = form.querySelector('[name="folder_name"]');
      shell.appendChild(form);
      dialog.appendChild(shell);
      dialog.showModal();
      window.setTimeout(() => input?.focus(), 0);
    };
  }

  function installKnowledgeFormDialog() {
    const source = document.getElementById('knowledge-form');
    if (!source?.querySelector('form.personal-knowledge-form')) return null;

    const placeholder = document.createComment('knowledge-form-dialog-home');
    source.before(placeholder);
    source.remove();

    let activeDialog = null;
    const open = () => {
      if (activeDialog?.open) return activeDialog;
      const title = source.querySelector('h2')?.textContent?.trim() || 'Add Knowledge';
      const dialog = makeDialog('knowledge-form-dialog', title);
      activeDialog = dialog;
      dialogHeader(dialog, title, 'Back to Knowledge');
      const body = document.createElement('div');
      body.className = 'personal-knowledge-dialog-body';
      body.appendChild(source);
      dialog.appendChild(body);
      dialog.addEventListener('close', () => {
        placeholder.after(source);
        activeDialog = null;
      }, { once: true });
      dialog.showModal();
      window.setTimeout(() => source.querySelector('input:not([type="hidden"]),textarea,select')?.focus(), 0);
      return dialog;
    };

    const params = new URLSearchParams(window.location.search);
    if (params.has('edit') || window.location.hash === '#knowledge-form') {
      window.setTimeout(open, 0);
    }
    return open;
  }

  function installTopActions(openFolderDialog, openKnowledgeDialog) {
    const stats = document.querySelector('.personal-knowledge-stats');
    if (!stats) return;

    const actions = document.createElement('div');
    actions.className = 'personal-knowledge-stat-actions';
    actions.setAttribute('role', 'group');
    actions.setAttribute('aria-label', 'Knowledge actions');

    if (openFolderDialog) {
      const folder = document.createElement('button');
      folder.type = 'button';
      folder.className = 'personal-knowledge-action secondary';
      folder.innerHTML = '<span class="personal-knowledge-action-icon" aria-hidden="true"></span><span>Add Folder</span>';
      folder.addEventListener('click', openFolderDialog);
      actions.appendChild(folder);
    }

    if (openKnowledgeDialog) {
      const add = document.createElement('button');
      add.type = 'button';
      add.className = 'personal-knowledge-action primary';
      add.innerHTML = '<span aria-hidden="true">＋</span><span>Add Knowledge</span>';
      add.addEventListener('click', openKnowledgeDialog);
      actions.appendChild(add);
    }

    if (actions.childElementCount) stats.appendChild(actions);
  }

  function installFolderNavigation() {
    const params = new URLSearchParams(window.location.search);
    const folder = params.get('folder');
    if (!folder || folder === 'all') return;

    document.body.classList.add('personal-knowledge-folder-open');
    const toolbar = document.querySelector('.personal-knowledge-toolbar');
    if (!toolbar) return;

    const nav = document.createElement('nav');
    nav.className = 'personal-knowledge-folder-nav';
    nav.setAttribute('aria-label', 'Knowledge folder navigation');
    const back = document.createElement('a');
    back.className = 'personal-knowledge-back';
    back.href = `${window.location.pathname || '/knowledge.php'}`;
    back.textContent = '← Back to folders';

    const title = document.querySelector('.personal-knowledge-panel-head h2')?.textContent?.trim();
    if (title) {
      const current = document.createElement('strong');
      current.textContent = title;
      current.setAttribute('aria-current', 'page');
      nav.append(back, current);
    } else {
      nav.appendChild(back);
    }
    toolbar.before(nav);
  }

  function textWithBreaks(node) {
    if (!node) return '';
    const clone = node.cloneNode(true);
    clone.querySelectorAll?.('br').forEach(br => br.replaceWith('\n'));
    return clone.textContent?.trim() || '';
  }

  function openItemDetail(row) {
    const title = row.querySelector('h3')?.textContent?.trim() || 'Knowledge item';
    const folderName = row.querySelector('.personal-knowledge-folder-chip')?.textContent?.trim() || 'Knowledge';
    const kind = [...row.querySelectorAll('.source.kind')].map(node => node.textContent.trim()).find(Boolean) || '';
    const sourceLink = row.querySelector('.personal-knowledge-source');
    const fileLink = [...row.querySelectorAll('.personal-knowledge-actions a')].find(link => /Open file/i.test(link.textContent || ''));
    const editLink = [...row.querySelectorAll('.personal-knowledge-actions a')].find(link => /Edit/i.test(link.textContent || ''));
    const description = row.querySelector('.personal-knowledge-copy > p')?.textContent?.trim() || '';
    const content = textWithBreaks(row.querySelector('.personal-knowledge-copy details p'));

    const dialog = makeDialog('knowledge-item-dialog', title);
    dialogHeader(dialog, title, `Back to ${folderName}`);

    const body = document.createElement('div');
    body.className = 'personal-knowledge-dialog-body personal-knowledge-item-detail';

    const badges = document.createElement('div');
    badges.className = 'personal-knowledge-detail-badges';
    row.querySelectorAll('.source').forEach(source => badges.appendChild(source.cloneNode(true)));
    if (kind === 'Transcription') {
      const origin = document.createElement('span');
      origin.className = 'source system-origin';
      origin.textContent = 'System-originated transcription';
      badges.appendChild(origin);
    }
    if (badges.childElementCount) body.appendChild(badges);

    if (description) {
      const intro = document.createElement('p');
      intro.className = 'personal-knowledge-detail-description';
      intro.textContent = description;
      body.appendChild(intro);
    }

    const meta = row.querySelector('.personal-knowledge-meta');
    if (meta) {
      const metaCopy = meta.cloneNode(true);
      metaCopy.classList.add('personal-knowledge-detail-meta');
      body.appendChild(metaCopy);
    }

    const documentBody = document.createElement('div');
    documentBody.className = 'personal-knowledge-document-body';
    documentBody.textContent = content || description || 'No readable text is stored for this Knowledge item.';
    body.appendChild(documentBody);

    const actions = document.createElement('div');
    actions.className = 'personal-knowledge-detail-actions';
    [sourceLink, fileLink, editLink].filter(Boolean).forEach(link => {
      const copy = link.cloneNode(true);
      copy.classList.add('personal-knowledge-detail-action');
      actions.appendChild(copy);
    });
    if (actions.childElementCount) body.appendChild(actions);

    dialog.appendChild(body);
    dialog.showModal();
    window.setTimeout(() => dialog.querySelector('.personal-knowledge-back')?.focus(), 0);
  }

  function installItemNavigation() {
    document.querySelectorAll('[data-knowledge-row]').forEach(row => {
      const heading = row.querySelector('h3');
      if (heading && !heading.querySelector('[data-knowledge-open]')) {
        const text = heading.textContent.trim();
        heading.textContent = '';
        const open = document.createElement('button');
        open.type = 'button';
        open.className = 'personal-knowledge-open-item';
        open.dataset.knowledgeOpen = '1';
        open.textContent = text;
        open.setAttribute('aria-label', `Open ${text}`);
        open.addEventListener('click', event => {
          event.preventDefault();
          event.stopPropagation();
          openItemDetail(row);
        });
        heading.appendChild(open);
      }

      const copy = row.querySelector('.personal-knowledge-copy');
      copy?.addEventListener('dblclick', event => {
        if (event.target.closest(interactiveSelector)) return;
        openItemDetail(row);
      });
    });
  }

  document.addEventListener('change', event => {
    if (event.target.matches?.('[data-knowledge-select]')) updateBulkState();
    if (event.target.matches?.('[data-knowledge-select-all]')) {
      const checked = !!event.target.checked;
      allBoxes().forEach(box => { box.checked = checked; });
      updateBulkState();
    }
  });

  document.addEventListener('dragstart', event => {
    const row = event.target.closest?.('[data-knowledge-row]');
    if (!row || !event.dataTransfer) return;
    const checkbox = row.querySelector('[data-knowledge-select]');
    if (checkbox && !checkbox.checked) {
      checkbox.checked = true;
      updateBulkState();
    }
    const ids = selectedBoxes().map(box => Number(box.value || 0)).filter(Boolean);
    if (!ids.length) return;
    row.classList.add('is-dragging');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', ids.join(','));
  });

  document.addEventListener('dragend', event => {
    event.target.closest?.('[data-knowledge-row]')?.classList.remove('is-dragging');
    document.querySelectorAll('.is-drop-target').forEach(node => node.classList.remove('is-drop-target'));
  });

  document.addEventListener('dragover', event => {
    const target = event.target.closest?.('[data-knowledge-folder-drop]');
    if (!target || !event.dataTransfer) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    target.classList.add('is-drop-target');
  });

  document.addEventListener('dragleave', event => {
    event.target.closest?.('[data-knowledge-folder-drop]')?.classList.remove('is-drop-target');
  });

  document.addEventListener('drop', event => {
    const target = event.target.closest?.('[data-knowledge-folder-drop]');
    if (!target || !event.dataTransfer) return;
    event.preventDefault();
    target.classList.remove('is-drop-target');
    const ids = String(event.dataTransfer.getData('text/plain') || '')
      .split(',')
      .map(value => Math.max(0, Number(value || 0)))
      .filter(Boolean);
    if (!ids.length) return;

    const form = document.getElementById('knowledge-drag-form');
    if (!form) return;
    form.querySelectorAll('[data-drag-item]').forEach(node => node.remove());
    ids.forEach(id => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'item_ids[]';
      input.value = String(id);
      input.dataset.dragItem = '1';
      form.appendChild(input);
    });
    const folder = form.querySelector('[name="folder_id"]');
    if (folder) folder.value = String(Math.max(0, Number(target.dataset.knowledgeFolderDrop || 0)));
    proof.dragMoves += 1;
    form.submit();
  });

  removeLegacyHeroCopy();
  removeLegacyHeaderAddAction();
  const openFolderDialog = installCreateFolderDialog();
  const openKnowledgeDialog = installKnowledgeFormDialog();
  installTopActions(openFolderDialog, openKnowledgeDialog);
  installFolderNavigation();
  installItemNavigation();
  updateBulkState();
})();
