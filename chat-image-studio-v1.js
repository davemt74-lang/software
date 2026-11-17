(() => {
  'use strict';
  if (window.VP3ImageStudio) return;
  const config = window.VP3_IMAGE_STUDIO_V1;
  if (!config?.endpoint || !config.csrf) return;
  const positive = value => Number.isSafeInteger(value) && value > 0;
  const key = value => value ? JSON.stringify([value.owner,value.agent,value.cid,value.binding]) : '';
  const node = (tag,text = '',className = '') => {
    const element = document.createElement(tag);
    element.textContent = text;
    if (className) element.className = className;
    return element;
  };
  const button = (label,action,className = '') => {
    const element = node('button',label,className);
    element.type = 'button';
    element.addEventListener('click',event => { if (event.isTrusted) action(event); });
    return element;
  };
  function imageUrl(asset) {
    if (!asset || !positive(asset.id) || asset.available !== true || asset.media_type !== 'photo') return '';
    try {
      const url = new URL(asset.url,location.href);
      return url.origin === location.origin && url.pathname.endsWith('/media-library-file-v86.php')
        && url.searchParams.get('id') === String(asset.id) && !url.username && !url.password ? url.href : '';
    } catch { return ''; }
  }
  const date = value => {
    const parsed = new Date(String(value).replace(' ','T') + (/Z|[+-]\d\d:\d\d$/.test(String(value)) ? '' : 'Z'));
    return Number.isFinite(parsed.getTime()) ? parsed.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'}) : '';
  };
  const assets = generation => (Array.isArray(generation.assets) ? generation.assets : generation.asset ? [generation.asset] : []).filter(asset => imageUrl(asset));
  function validState(state,scope) {
    return state?.contract === 'vp3.image-studio.v1' && state.owner_user_id === scope.owner && state.agent_id === scope.agent
      && state.conversation_id === scope.cid && state.working_attention_binding === scope.binding
      && /^[a-f0-9]{64}$/.test(state.source_revision || '') && Array.isArray(state.sources) && state.sources.length <= 16
      && state.sources.every(source => typeof source.ref === 'string' && typeof source.title === 'string'
        && /^[a-f0-9]{64}$/.test(source.revision || '') && ['image_prompt','specialist_report'].includes(source.kind))
      && Array.isArray(state.generations) && state.generations.length <= 20
      && state.generations.every(g => /^[a-f0-9-]{36}$/.test(g?.id || '') && positive(g.job_id) && positive(g.conversation_id)
        && typeof g.title === 'string' && [...g.title].length <= 190 && typeof g.prompt === 'string' && g.prompt.length <= 8000
        && ['queued','generating','paused','completed','failed','cancelled','file_unavailable'].includes(g.status)
        && (g.count === undefined || Number.isInteger(g.count) && g.count >= 1 && g.count <= 4)
        && (g.assets === undefined || Array.isArray(g.assets) && g.assets.length <= 4))
      && (state.next_before === null || positive(state.next_before))
      && (state.brainstorm === null || /^[a-f0-9]{32}$/.test(state.brainstorm?.request_id || '')
        && ['pending','ready','failed','stale','interrupted'].includes(state.brainstorm.state)
        && Array.isArray(state.brainstorm.ideas) && state.brainstorm.ideas.length <= 3
        && state.brainstorm.ideas.every(idea => typeof idea.title === 'string' && typeof idea.prompt === 'string'
          && typeof idea.rationale === 'string' && Array.isArray(idea.sources)
          && idea.sources.every(source => typeof source.title === 'string' && state.sources.some(current =>
            current.ref === source.ref && current.revision === source.revision))));
  }

  function mountWorkspace(container,getScope,close) {
    const initial = getScope(true);
    const matches = candidate => candidate?.hasChat && String(candidate.owner) === String(config.ownerKey)
      && candidate.agent === Number(config.agentId) && candidate.binding === String(config.workingAttentionBinding || '');
    let disposed = false,initializing = false,view = null;
    const notice = node('p','Preparing your Chat…','vp3-image-studio-feedback');notice.setAttribute('role','status');
    const retry = button('Try again',() => void initialize(true));retry.hidden = true;
    container.replaceChildren(notice,retry);
    async function initialize(explicit = false) {
      if (disposed || initializing || !container.isConnected) return;
      if (view) { await view.refresh(); return; }
      if (!matches(initial) || !matches(getScope(true))) { notice.textContent = 'Refresh Chat to restore your Image specialist.';return; }
      initializing = true;retry.hidden = true;
      try {
        let current = getScope();
        if (current?.cid === 0 && explicit) {
          const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
          await chat?.ensureConversation?.();
          current = getScope();
        }
        if (disposed || !container.isConnected) return;
        if (!matches(current) || !positive(current.cid)) {
          notice.textContent = 'Chat is getting ready. Your draft is kept; try again when the Agent is ready.';retry.hidden = false;return;
        }
        view = mountReadyWorkspace(container,getScope,close);
      } catch {
        if (!disposed) { notice.textContent = 'Chat could not be prepared. Your draft is kept. Try again.';retry.hidden = false; }
      } finally { initializing = false; }
    }
    void initialize(true);
    return {refresh:() => initialize(false),dispose() { disposed = true;view?.dispose();container.replaceChildren(); }};
  }

  function mountReadyWorkspace(container,getScope,close) {
    const initial = getScope();
    const identity = key(initial);
    const shell = node('section','','vp3-image-studio');
    shell.setAttribute('aria-label','Image specialist');
    const toolbar = node('div','','vp3-image-studio-toolbar');
    toolbar.append(node('p','Your images and ideas, saved with this Agent.'));
    const refreshButton = button('Refresh',() => void request('history'));
    refreshButton.setAttribute('aria-label','Refresh image history');
    toolbar.append(refreshButton);
    const tabs = node('div','','vp3-image-studio-tabs');
    tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Image specialist views');
    const feedback = node('p','Loading image history…','vp3-image-studio-feedback');
    feedback.setAttribute('role','status');feedback.setAttribute('aria-live','polite');
    const panel = node('section','','vp3-image-studio-content');
    panel.id = 'vp3ImageStudioPanel';panel.setAttribute('role','tabpanel');panel.tabIndex = 0;
    shell.append(toolbar,tabs,feedback,panel);container.replaceChildren(shell);
    let disposed = false,controller = null,timer = 0,tab = 'images',state = null,rows = [],brief = '',busy = false;
    const current = () => !disposed && shell.isConnected && identity === key(getScope());
    const labels = [['images','Images','Image History'],['prompts','Prompts','Prompt History'],['brainstorm','Brainstorming','Brainstorming']];
    const tabButtons = labels.map(([id,label,accessible]) => {
      const control = button(label,() => { tab = id; render(); });
      control.id = 'vp3ImageStudioTab-' + id;control.dataset.imageStudioTab = id;
      control.setAttribute('role','tab');control.setAttribute('aria-label',accessible);control.setAttribute('aria-controls',panel.id);
      control.addEventListener('keydown',event => {
        if (!event.isTrusted || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
        event.preventDefault();
        const at = labels.findIndex(item => item[0] === tab);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? labels.length - 1
          : (at + (event.key === 'ArrowRight' ? 1 : -1) + labels.length) % labels.length;
        tab = labels[next][0];render();tabButtons[next].focus();
      });
      tabs.append(control);return control;
    });
    function controls() {
      refreshButton.disabled = Boolean(controller);
      for (const control of tabButtons) {
        const selected = control.dataset.imageStudioTab === tab;
        control.setAttribute('aria-selected',String(selected));control.tabIndex = selected ? 0 : -1;
      }
      panel.setAttribute('aria-labelledby','vp3ImageStudioTab-' + tab);
    }
    async function stage(prompt,generation = null,sourceRevision = null) {
      if (!current() || busy || controller) return;
      busy = true;
      try {
        if (sourceRevision !== null) {
          const verified = await request('history');
          if (!current()) return;
          if (!verified || state?.source_revision !== sourceRevision || state.brainstorm?.state !== 'ready'
            || !state.brainstorm.ideas.some(idea => idea.prompt === prompt)) {
            feedback.textContent = 'Your inspiration sources changed or could not be verified. Choose a current idea after refreshing.';return;
          }
        }
        const accepted = await window.VP3ImageComposer?.stagePrompt?.(prompt,{
          count:Number(generation?.count || 1),referenceAssetId:Number(generation?.reference_asset_id || 0),conversationId:initial.cid
        });
        if (!current()) return;
        if (accepted === true) close();
        else feedback.textContent = 'Your current draft or image could not be replaced. Finish that draft, then choose this prompt again.';
      } catch {
        if (current()) feedback.textContent = 'This prompt could not be staged. Your current draft is kept.';
      } finally { busy = false; }
    }
    function more() {
      if (!positive(state?.next_before)) return;
      panel.append(button('Load earlier images and prompts',() => void request('history',{before:state.next_before},true),'vp3-image-studio-more'));
    }
    function renderImages() {
      const gallery = node('div','','vp3-image-studio-gallery');
      let count = 0;
      for (const generation of rows) {
        const pictures = assets(generation);
        for (const [index,asset] of pictures.entries()) {
          const card = node('figure','','vp3-image-studio-image');
          const open = button('',async () => {
            if (!current()) return;
            const ids = pictures.map(picture => picture.id);
            close();
            await window.VP3CanvasMedia?.openImages?.(ids,{index,focusReturn:document.getElementById('chatInput')});
          });
          open.dataset.imageStudioThumbnail = String(asset.id);
          open.setAttribute('aria-label','View '+(asset.title || generation.title || 'generated image'));
          const picture = node('img');picture.src = imageUrl(asset);picture.alt = asset.title || generation.title || 'Generated image';
          picture.loading = 'lazy';picture.decoding = 'async';open.append(picture);
          const caption = node('figcaption');caption.append(node('strong',generation.title || 'Generated image'),
            node('small',[date(generation.created_at),generation.chat_label || 'Chat '+generation.conversation_id,
              pictures.length > 1 ? (index + 1)+' of '+pictures.length : ''].filter(Boolean).join(' · ')));
          card.append(open,caption);gallery.append(card);count++;
        }
      }
      if (count) panel.append(gallery);
      else panel.append(node('p','Your generated images will appear here. Describe an image in Chat to get started.','vp3-image-studio-empty'));
      const pending = rows.filter(row => ['queued','generating','paused'].includes(row.status));
      if (pending.length) panel.append(node('p',pending.length === 1 ? 'One image request is still in progress.' : pending.length+' image requests are in progress.','vp3-image-studio-note'));
      more();
    }
    function promptCopy(prompt) {
      if (prompt.length <= 500) return node('p',prompt,'vp3-image-studio-prompt');
      const details = node('details','','vp3-image-studio-prompt');
      details.append(node('summary',prompt.slice(0,240).trimEnd()+'…'),node('p',prompt));return details;
    }
    function renderPrompts() {
      if (!rows.length) panel.append(node('p','Your image prompts will be saved here, including requests still being created.','vp3-image-studio-empty'));
      for (const generation of rows) {
        const card = node('article','','vp3-image-studio-prompt-card');card.dataset.imageStudioPrompt = generation.id;
        const heading = node('header');heading.append(node('h3',generation.title || 'Image prompt'));
        const use = button('Use prompt',() => void stage(generation.prompt,generation),'vp3-image-studio-use');
        if (generation.reference_asset_id > 0 && !imageUrl(generation.reference_asset)) {
          use.disabled = true;use.title = 'The original base image is no longer available.';
        }
        heading.append(use);
        const count = Number(generation.count || 1);
        const meta = [date(generation.created_at),generation.chat_label || 'Chat '+generation.conversation_id,
          count+' image'+(count === 1 ? '' : 's'),generation.status.replaceAll('_',' ')].filter(Boolean).join(' · ');
        card.append(heading,node('small',meta),promptCopy(generation.prompt));
        if (generation.reference_asset_id > 0) {
          const reference = node('div','','vp3-image-studio-reference');
          const address = imageUrl(generation.reference_asset);
          if (address) { const thumb = node('img');thumb.src = address;thumb.alt = '';reference.append(thumb); }
          reference.append(node('span',address ? 'Base image: '+(generation.reference_asset.title || 'Uploaded image') : 'Original base image unavailable'));
          card.append(reference);
        }
        panel.append(card);
      }
      more();
    }
    function renderBrainstorm() {
      const sources = state?.sources || [];
      const prompts = sources.filter(source => source.kind === 'image_prompt').length;
      const reports = sources.filter(source => source.kind === 'specialist_report').length;
      panel.append(node('p',sources.length ? 'Ideas draw on '+prompts+' saved prompt'+(prompts === 1 ? '' : 's')+' and '+reports+' current specialist report'+(reports === 1 ? '' : 's')+'.'
        : 'Add an image prompt or finish a specialist report to give your Image specialist something to build on.','vp3-image-studio-intro'));
      const form = node('form','','vp3-image-studio-brainstorm-form');
      const label = node('label','A direction to explore (optional)');label.htmlFor = 'vp3ImageBrainstormBrief';
      const input = node('textarea');input.id = label.htmlFor;input.rows = 2;input.maxLength = 500;
      input.placeholder = 'For example, develop a visual theme for my next music release';input.value = brief;
      input.addEventListener('input',() => { brief = input.value; });
      input.disabled = Boolean(controller && controller.action === 'brainstorm');
      const submit = node('button',controller?.action === 'brainstorm' ? 'Thinking through ideas…' : 'Brainstorm ideas','vp3-image-studio-brainstorm-submit');
      submit.type = 'submit';submit.disabled = !state || !sources.length || Boolean(controller) || state.brainstorm?.state === 'pending';
      form.append(label,input,submit);
      form.addEventListener('submit',event => {
        event.preventDefault();
        if (!event.isTrusted || !current() || submit.disabled) return;
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        const id = [...bytes].map(value => value.toString(16).padStart(2,'0')).join('');
        void request('brainstorm',{request_id:id,source_revision:state.source_revision,brief:brief.trim()});
      });
      panel.append(form);
      const brainstorm = state?.brainstorm;
      const pending = controller?.action === 'brainstorm' || brainstorm?.state === 'pending';
      if (pending) {
        const progress = node('div','','vp3-image-studio-thinking');progress.setAttribute('role','status');
        progress.append(node('span','','vp3-image-studio-thinking-mark'),node('span','Connecting your prompts with current specialist findings…'));panel.append(progress);
      } else if (brainstorm && brainstorm.state !== 'ready') {
        const messages = {stale:'Your inspiration sources changed. Brainstorm again to work with the current context.',
          failed:'The last brainstorm did not produce verified ideas. You can request a new one.',
          interrupted:'The last brainstorm was interrupted. No image was generated.'};
        panel.append(node('p',messages[brainstorm.state] || 'No saved ideas yet.','vp3-image-studio-note'));
      }
      if (brainstorm?.state === 'ready') for (const idea of brainstorm.ideas) {
        const card = node('article','','vp3-image-studio-idea');
        const revision = state.source_revision;
        const heading = node('header');heading.append(node('h3',idea.title),button('Use prompt',() => void stage(idea.prompt,null,revision),'vp3-image-studio-use'));
        card.append(heading,node('p',idea.rationale),promptCopy(idea.prompt),
          node('small','Inspired by: '+idea.sources.map(source => source.title).join(' · '),'vp3-image-studio-provenance'));
        panel.append(card);
      }
      if (sources.length) {
        const details = node('details','','vp3-image-studio-sources');details.append(node('summary','Inspiration sources'));
        const list = node('ul');for (const source of sources)list.append(node('li',(source.kind === 'image_prompt' ? 'Prompt' : source.specialist)+' · '+source.title));
        details.append(list);panel.append(details);
      }
    }
    function render() {
      if (!current()) return;
      controls();panel.replaceChildren();
      if (!state) return;
      if (tab === 'images') renderImages();
      else if (tab === 'prompts') renderPrompts();
      else renderBrainstorm();
    }
    async function request(action,extra = {},append = false) {
      if (!current() || controller) return false;
      let verified = false;
      clearTimeout(timer);timer = 0;
      const request = new AbortController();request.action = action;controller = request;
      feedback.textContent = action === 'brainstorm' ? 'Preparing ideas from your current inspiration…' : state ? 'Refreshing…' : 'Loading image history…';
      render();
      const timeout = setTimeout(() => request.abort(),action === 'brainstorm' ? 70000 : 12000);
      try {
        const response = await fetch(config.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',signal:request.signal,
          headers:{'Content-Type':'application/json','X-VP3-Working-Attention':initial.binding},
          body:JSON.stringify({action,owner_key:String(initial.owner),agent_id:initial.agent,conversation_id:initial.cid,csrf_token:config.csrf,...extra})});
        const data = await response.json();
        if (!current() || request.signal.aborted) return false;
        if (!response.ok || data?.ok !== true) {
          const error = new Error(typeof data?.error === 'string' ? data.error : 'Image history could not be refreshed.');error.status = response.status;throw error;
        }
        if (!validState(data.state,initial)) { const error = new Error('This image history could not be verified for the current Chat.');error.status = 403;throw error; }
        state = data.state;
        rows = append ? [...new Map([...rows,...state.generations].map(row => [row.id,row])).values()] : state.generations;
        feedback.textContent = action === 'brainstorm' && state.brainstorm?.state === 'ready' ? 'New ideas are ready. Choose a prompt to continue in Chat.' : '';
        verified = true;
      } catch (error) {
        if (!current()) return;
        if ([401,403,404,409,419].includes(error.status)) { state = null;rows = []; }
        feedback.textContent = error.name === 'AbortError'
          ? action === 'brainstorm' ? 'The response is taking longer than expected. Refresh to check the saved result before starting another brainstorm.' : 'Image history took too long to load. Try Refresh.'
          : error.message || 'Image history could not be refreshed.';
      } finally {
        clearTimeout(timeout);
        if (controller === request) controller = null;
        if (current()) {
          render();
          if (rows.some(row => ['queued','generating'].includes(row.status)) || state?.brainstorm?.state === 'pending') {
            timer = setTimeout(() => void requestHistory(),4000);
          }
        }
      }
      return verified;
    }
    const requestHistory = () => request('history');
    const updated = event => {
      if (current() && event.detail?.agentId === initial.agent && event.detail?.conversationId === initial.cid) void requestHistory();
    };
    window.addEventListener('vp3:image-generation-updated',updated);
    void requestHistory();
    return {refresh:requestHistory,dispose() { disposed = true;clearTimeout(timer);controller?.abort();window.removeEventListener('vp3:image-generation-updated',updated);shell.replaceChildren(); }};
  }
  window.VP3ImageStudio = Object.freeze({mountWorkspace});
})();
