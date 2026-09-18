(() => {
  'use strict';
  if (window.VP3_COGNITIVE_CARDS_V520_RUNTIME) return;

  const cfg = window.VP3_COGNITIVE_CARDS_V520 || {};
  const maxBatch = Math.max(1, Math.min(20, Number(cfg.maxBatch || 20)));
  const text = value => String(value == null ? '' : value);
  const clean = value => text(value).replace(/\s+/g,' ').trim();
  const typeLabel = value => clean(value).replaceAll('_',' ').replace(/\b\w/g, c => c.toUpperCase());
  const internalUrl = value => {
    const raw = clean(value);
    return raw.startsWith('/') && !raw.startsWith('//') ? raw : '';
  };
  const iconMap = {
    transcription:'T',recording:'●',annotation:'A',source:'S',research:'R',claim:'C',
    team_activity:'T',human_message:'M',profile_agent_update:'P',contact:'C',
    commerce_order:'$',commerce_customer:'$',product:'P',calendar_booking:'◷',
    meeting:'◷',meeting_brief:'◷',meeting_summary:'◷',meeting_followup:'◷',
    workflow:'W',goal:'G',commitment:'✓',opportunity:'↗',risk:'!',decision:'D',
    knowledge:'K',live_room:'L',homeserver:'H',browser_companion:'B'
  };

  function el(tag,className,textValue) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (textValue !== undefined && textValue !== null) node.textContent = text(textValue);
    return node;
  }

  function setState(root,state) {
    root.dataset.cardState = state;
  }

  function runPrompt(prompt) {
    const value = clean(prompt);
    if (!value) return false;
    const form = document.getElementById('chatForm');
    const input = document.getElementById('chatInput');
    if (!form || !input) return false;
    input.value = value;
    input.dispatchEvent(new Event('input',{bubbles:true}));
    form.requestSubmit();
    return true;
  }

  function actionNode(action,card) {
    const type = clean(action && action.type);
    const label = clean(action && action.label) || 'Open';
    if (type === 'open_url') {
      const href = internalUrl(action.url);
      if (!href) return null;
      const a = el('a','vp3-card-action',label);
      a.href = href;
      return a;
    }
    const button = el('button','vp3-card-action',label);
    button.type = 'button';
    if (type === 'prompt') {
      button.addEventListener('click',() => runPrompt(action.prompt));
      return button;
    }
    if (type === 'tool') {
      if (action.requires_approval) button.dataset.requiresApproval = '1';
      button.addEventListener('click',() => {
        const detail = {tool_id:clean(action.tool_id),card:card,action:action};
        const event = new CustomEvent('vp3:cognitive-card-tool-request',{detail,cancelable:true});
        const allowed = window.dispatchEvent(event);
        if (allowed) runPrompt('Use ' + clean(action.tool_id) + ' for ' + clean(card.title || 'this item') + '.');
      });
      return button;
    }
    return null;
  }

  function mediaNode(item) {
    const kind = clean(item && item.kind);
    const src = internalUrl(item && item.url);
    if (!src || !['image','audio','video'].includes(kind)) return null;
    const wrap = el('figure','vp3-card-media');
    if (kind === 'image') {
      const img = document.createElement('img');
      img.src = src; img.loading = 'lazy'; img.alt = clean(item.label || '');
      wrap.appendChild(img);
    } else {
      const player = document.createElement(kind);
      player.src = src; player.controls = true; player.preload = 'metadata';
      if (kind === 'video') player.playsInline = true;
      wrap.appendChild(player);
    }
    const label = clean(item.label);
    if (label) wrap.appendChild(el('figcaption','vp3-card-media-label',label));
    return wrap;
  }

  function renderCard(card) {
    const root = el('article','vp3-cognitive-card');
    const mode = clean(card && card.display_mode) || 'standard';
    const type = clean(card && card.card_type) || 'item';
    root.dataset.cardType = type;
    root.dataset.displayMode = mode;
    root.dataset.cardContract = clean(card && card.contract);
    setState(root,'ready');

    const head = el('header','vp3-card-head');
    const identity = el('div','vp3-card-identity');
    const icon = el('span','vp3-card-icon',iconMap[type] || 'V');
    icon.setAttribute('aria-hidden','true');
    const copy = el('div','vp3-card-heading');
    copy.appendChild(el('small','vp3-card-kicker',typeLabel(type)));
    copy.appendChild(el('strong','vp3-card-title',clean(card.title) || 'VP3 item'));
    const subtitle = clean(card.subtitle);
    if (subtitle) copy.appendChild(el('span','vp3-card-subtitle',subtitle));
    identity.append(icon,copy);
    head.appendChild(identity);
    const status = clean(card.status);
    if (status) head.appendChild(el('span','vp3-card-status',typeLabel(status)));
    root.appendChild(head);

    const badges = Array.isArray(card.badges) ? card.badges.map(clean).filter(Boolean) : [];
    if (badges.length) {
      const row = el('div','vp3-card-badges');
      badges.slice(0,8).forEach(value => row.appendChild(el('span','vp3-card-badge',value)));
      root.appendChild(row);
    }

    const summary = clean(card.summary);
    if (summary) root.appendChild(el('p','vp3-card-summary',summary));

    const facts = Array.isArray(card.facts) ? card.facts : [];
    if (facts.length) {
      const grid = el('dl','vp3-card-facts');
      facts.slice(0,10).forEach(fact => {
        const item = el('div','vp3-card-fact');
        item.appendChild(el('dt','',clean(fact && fact.label)));
        item.appendChild(el('dd','',clean(fact && fact.value)));
        grid.appendChild(item);
      });
      root.appendChild(grid);
    }

    const media = Array.isArray(card.media) ? card.media : [];
    if (media.length) {
      const mediaWrap = el('div','vp3-card-media-grid');
      media.slice(0,4).forEach(item => {
        const node = mediaNode(item);
        if (node) mediaWrap.appendChild(node);
      });
      if (mediaWrap.childElementCount) root.appendChild(mediaWrap);
    }

    const sections = Array.isArray(card.sections) ? card.sections : [];
    if (sections.length && mode !== 'compact') {
      const wrap = el('div','vp3-card-sections');
      sections.slice(0,8).forEach(section => {
        const part = el('section','vp3-card-section');
        const label = clean(section && section.label);
        if (label) part.appendChild(el('small','vp3-card-section-label',label));
        const body = clean(section && section.text);
        if (body) part.appendChild(el('p','vp3-card-section-text',body));
        const items = Array.isArray(section && section.items) ? section.items.map(clean).filter(Boolean) : [];
        if (items.length) {
          const list = el('ul','vp3-card-section-list');
          items.slice(0,12).forEach(item => list.appendChild(el('li','',item)));
          part.appendChild(list);
        }
        if (part.childElementCount) wrap.appendChild(part);
      });
      if (wrap.childElementCount) root.appendChild(wrap);
    }

    const actions = Array.isArray(card.actions) ? card.actions : [];
    if (actions.length) {
      const actionWrap = el('footer','vp3-card-actions');
      actions.slice(0,6).forEach(action => {
        const node = actionNode(action,card);
        if (node) actionWrap.appendChild(node);
      });
      if (actionWrap.childElementCount) root.appendChild(actionWrap);
    }

    const timestamp = clean(card.timestamp);
    if (timestamp) {
      const time = el('time','vp3-card-time',timestamp);
      time.dateTime = timestamp;
      root.appendChild(time);
    }
    return root;
  }

  async function requestCards(requests) {
    const batch = Array.isArray(requests) ? requests.filter(item => item && typeof item === 'object').slice(0,maxBatch) : [];
    if (!batch.length) return {ok:true,items:[]};
    const response = await fetch(String(cfg.endpoint || '/api/cognitive-cards-v520.php'), {
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify({
        csrf_token:String(cfg.csrf || ''),
        agent_id:Number(cfg.agentId || 0),
        cards:batch
      })
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error || 'Display cards are unavailable.');
    return data;
  }

  async function renderRequests(requests,container,options) {
    if (!container) return {rendered:0,availableIndices:[]};
    const opts = options || {};
    const batch = Array.isArray(requests) ? requests.slice(0,maxBatch) : [];
    if (!batch.length) return {rendered:0,availableIndices:[]};
    setState(container,'loading');
    try {
      const data = await requestCards(batch);
      if (opts.replace !== false) container.replaceChildren();
      const available = [];
      (Array.isArray(data.items) ? data.items : []).forEach(item => {
        if (!item || !item.available || !item.card) return;
        const index = Number(item.index || 0);
        const node = renderCard(item.card);
        node.dataset.requestIndex = String(index);
        container.appendChild(node);
        available.push(index);
      });
      setState(container,available.length ? 'ready' : 'empty');
      return {rendered:available.length,availableIndices:available};
    } catch (error) {
      setState(container,'error');
      if (opts.showError) {
        const note = el('div','vp3-card-error','Display card is temporarily unavailable.');
        container.replaceChildren(note);
      }
      return {rendered:0,availableIndices:[],error:error};
    }
  }

  function hydrateHost(host) {
    if (!host || host.dataset.cardsHydrated === '1') return;
    const requests = host._vp3CognitiveCardRequests;
    if (!Array.isArray(requests) || !requests.length) return;
    host.dataset.cardsHydrated = '1';
    void renderRequests(requests,host,{showError:false});
  }

  function scan(root) {
    const scope = root && root.querySelectorAll ? root : document;
    if (scope.matches && scope.matches('[data-cognitive-card-host]')) hydrateHost(scope);
    scope.querySelectorAll('[data-cognitive-card-host]').forEach(hydrateHost);
  }

  const observer = new MutationObserver(records => {
    records.forEach(record => record.addedNodes.forEach(node => {
      if (node.nodeType === 1) scan(node);
    }));
  });
  observer.observe(document.documentElement,{childList:true,subtree:true});
  window.addEventListener('pagehide',() => observer.disconnect(),{once:true});

  const runtime = {build:'vp3-cognitive-cards-v520-20260918',renderCard,requestCards,renderRequests,scan};
  window.VP3_COGNITIVE_CARDS_V520_RUNTIME = runtime;
  window.VP3_COGNITIVE_CARDS = runtime;
  scan(document);
})();