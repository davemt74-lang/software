(() => {
  'use strict';

  const BUILD = 'knowledge-library-v161-20260913';
  const proof = window.VP3_KNOWLEDGE_LIBRARY_V161 = {
    build: BUILD,
    loaded: true,
    selected: 0,
    dragMoves: 0,
  };

  const selectedBoxes = () => [...document.querySelectorAll('[data-knowledge-select]:checked')];
  const allBoxes = () => [...document.querySelectorAll('[data-knowledge-select]')];

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
    if (!row) return;
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
    if (!target) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    target.classList.add('is-drop-target');
  });

  document.addEventListener('dragleave', event => {
    event.target.closest?.('[data-knowledge-folder-drop]')?.classList.remove('is-drop-target');
  });

  document.addEventListener('drop', event => {
    const target = event.target.closest?.('[data-knowledge-folder-drop]');
    if (!target) return;
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

  updateBulkState();
})();
