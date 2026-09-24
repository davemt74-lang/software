(() => {
  'use strict';

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const canvases = document.querySelectorAll('[data-journey-builder-v123]');

  function render(container) {
    const scriptId = container.getAttribute('data-graph-source');
    const source = scriptId ? document.getElementById(scriptId) : null;
    if (!source) return;
    let data;
    try { data = JSON.parse(source.textContent || '{}'); } catch (_) { return; }
    const nodes = Array.isArray(data.nodes) ? data.nodes : [];
    container.innerHTML = '';
    container.classList.add('cr-journey-builder-v123');

    if (!nodes.length) {
      container.innerHTML = '<p class="cr-help">No nodes in this release snapshot.</p>';
      return;
    }

    const stage = document.createElement('div');
    stage.className = 'cr-journey-stage-v123';
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.classList.add('cr-journey-links-v123');
    stage.appendChild(svg);

    const orders = [...new Set(nodes.map(n => Number(n.step_order || 999)))].sort((a,b) => a-b);
    const orderIndex = new Map(orders.map((v,i) => [v,i]));
    const rowUse = new Map();

    nodes.forEach((node, index) => {
      const col = orderIndex.get(Number(node.step_order || 999)) || 0;
      const rowKey = String(node.step_key || 'step');
      const used = rowUse.get(col) || 0;
      rowUse.set(col, used + 1);
      const card = document.createElement(node.edit_url ? 'a' : 'div');
      card.className = 'cr-journey-node-v123';
      card.dataset.step = String(node.step_key || '');
      card.dataset.variant = String(node.variant_key || 'default');
      card.dataset.nodeIndex = String(index);
      if (node.edit_url) card.href = node.edit_url;
      card.style.left = (24 + col * 250) + 'px';
      card.style.top = (24 + used * 138) + 'px';
      card.innerHTML =
        '<span class="cr-journey-node-type-v123">' + esc(node.node_type || 'message') + '</span>' +
        '<strong>' + esc(node.step_key || 'step') + '</strong>' +
        (String(node.variant_key || 'default') !== 'default' ? '<small>Variant ' + esc(node.variant_key) + ' · ' + esc(node.variant_weight || 100) + '%</small>' : '') +
        '<small>' + esc(node.channel || 'orchestration') + (node.entry_node ? ' · Entry' : '') + '</small>' +
        '<small>v' + esc(node.message_version_no || 1) + '</small>';
      stage.appendChild(card);
    });

    const width = Math.max(680, 80 + orders.length * 250);
    const height = Math.max(260, 80 + Math.max(...rowUse.values()) * 138);
    stage.style.width = width + 'px';
    stage.style.height = height + 'px';
    svg.setAttribute('width', String(width));
    svg.setAttribute('height', String(height));
    container.appendChild(stage);

    const targetFor = step => stage.querySelector('.cr-journey-node-v123[data-step="' + CSS.escape(String(step)) + '"]');
    const cardFor = index => stage.querySelector('.cr-journey-node-v123[data-node-index="' + index + '"]');

    function link(from, to, label, kind) {
      if (!from || !to) return;
      const stageRect = stage.getBoundingClientRect();
      const a = from.getBoundingClientRect(), b = to.getBoundingClientRect();
      const x1 = a.right - stageRect.left, y1 = a.top + a.height/2 - stageRect.top;
      const x2 = b.left - stageRect.left, y2 = b.top + b.height/2 - stageRect.top;
      const bend = Math.max(35, (x2 - x1) / 2);
      const path = document.createElementNS('http://www.w3.org/2000/svg','path');
      path.setAttribute('d', 'M '+x1+' '+y1+' C '+(x1+bend)+' '+y1+', '+(x2-bend)+' '+y2+', '+x2+' '+y2);
      path.setAttribute('class','cr-journey-link-v123 ' + (kind || ''));
      svg.appendChild(path);
      if (label) {
        const text = document.createElementNS('http://www.w3.org/2000/svg','text');
        text.setAttribute('x', String((x1+x2)/2));
        text.setAttribute('y', String((y1+y2)/2 - 5));
        text.setAttribute('class','cr-journey-link-label-v123');
        text.textContent = label;
        svg.appendChild(text);
      }
    }

    requestAnimationFrame(() => {
      svg.innerHTML = '';
      nodes.forEach((node,index) => {
        const from = cardFor(index);
        if (node.node_type === 'decision') {
          link(from,targetFor(node.true_next_step_key),'true','is-true');
          link(from,targetFor(node.false_next_step_key),'false','is-false');
        } else if (node.next_step_key) {
          link(from,targetFor(node.next_step_key),'','');
        }
      });
    });
  }

  canvases.forEach(render);
  window.addEventListener('resize', () => canvases.forEach(render), {passive:true});
})();
