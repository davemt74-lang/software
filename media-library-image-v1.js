(function () {
  'use strict';

  const pending = new Set(['queued', 'generating', 'paused']);
  const states = {
    queued: 'Queued',
    generating: 'Generating image…',
    paused: 'Paused',
    completed: 'Saved in Media Library',
    cancelled: 'Cancelled',
    failed: 'Failed',
    file_unavailable: 'Saved file unavailable',
  };

  function node(tag, text) {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    return element;
  }

  function imageUrl(asset) {
    if (asset?.available !== true || asset.media_type !== 'photo' || typeof asset.url !== 'string') return '';
    try {
      const address = new URL(asset.url, location.href);
      return address.origin === location.origin && address.pathname.endsWith('/media-library-file-v86.php') ? address.href : '';
    } catch (_) {
      return '';
    }
  }

  function mount(root, {endpoint, csrf, ownerKey, onOpen, onSaved}) {
    if (!root || root.dataset.mediaGenerationMounted === 'true') return;
    root.dataset.mediaGenerationMounted = 'true';
    root.classList.add('vp3-media-generation');
    root.innerHTML = `
      <h2>Generate an image</h2>
      <p>Describe what to create. The image will be saved privately in your Media Library.</p>
      <form data-media-generation-form>
        <fieldset class="vp3-media-generation-fields" disabled>
          <label>Image prompt
            <textarea name="prompt" rows="4" required minlength="3" maxlength="4000" placeholder="Describe the subject, setting, composition, and style"></textarea>
          </label>
          <label>Image title (optional)
            <input name="title" maxlength="190" placeholder="Name this image">
          </label>
          <div class="vp3-media-generation-options">
            <label>Size
              <select name="size">
                <option value="1024x1024">Square · 1024 × 1024</option>
                <option value="1536x1024">Landscape · 1536 × 1024</option>
                <option value="1024x1536">Portrait · 1024 × 1536</option>
              </select>
            </label>
            <label>Quality
              <select name="quality">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
              </select>
            </label>
            <label>Background
              <select name="background">
                <option value="opaque">Opaque</option>
                <option value="transparent">Transparent</option>
              </select>
            </label>
          </div>
          <button type="submit" data-media-generation-review>Review prompt</button>
        </fieldset>
      </form>
      <section class="vp3-media-generation-review" data-media-generation-preview hidden>
        <h3>Review image request</h3>
        <p class="vp3-media-generation-prompt" data-media-generation-review-text></p>
        <p data-media-generation-review-settings></p>
        <p>OpenAI receives this prompt to generate one image using your account’s AI quota.</p>
        <div class="vp3-media-generation-actions">
          <button type="button" data-media-generation-submit>Generate image</button>
          <button type="button" data-media-generation-back>Edit prompt</button>
        </div>
      </section>
      <p class="vp3-media-generation-status" data-media-generation-status role="status">Loading image history…</p>
      <div class="vp3-media-generation-history">
        <h3>Recent image requests</h3>
        <button type="button" data-media-generation-refresh>Refresh history</button>
        <div data-media-generation-history></div>
      </div>`;

    const find = selector => root.querySelector(selector);
    const form = find('[data-media-generation-form]');
    const fields = form.querySelector('fieldset');
    const review = find('[data-media-generation-preview]');
    const confirm = find('[data-media-generation-submit]');
    const back = find('[data-media-generation-back]');
    const refresh = find('[data-media-generation-refresh]');
    const status = find('[data-media-generation-status]');
    const history = find('[data-media-generation-history]');
    const jobs = new Map();
    const executing = new Set();
    const lastTick = new Map();
    let frozen = null;
    let available = false;
    let busy = false;
    let polling = false;
    let pollBlocked = false;
    let timer = 0;

    async function api(action, input = {}) {
      const response = await fetch(endpoint, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action, csrf_token: csrf, owner_key: ownerKey, ...input}),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.ok !== true) {
        const error = new Error(result.error || 'Image status could not be verified. Refresh image history before creating another request.');
        error.status = response.status;
        throw error;
      }
      return result;
    }

    function message(text) {
      if (status.textContent !== text) status.textContent = text;
    }

    function activeJob() {
      return [...jobs.values()].find(job => pending.has(job.status));
    }

    function updateControls() {
      const active = Boolean(activeJob());
      fields.disabled = !available || busy || Boolean(frozen) || active;
      confirm.disabled = !available || busy || active;
      back.disabled = busy;
    }

    function editPrompt(focus = false) {
      frozen = null;
      review.hidden = true;
      updateControls();
      if (focus) form.elements.prompt.focus();
    }

    function reusePrompt(job) {
      editPrompt();
      for (const name of ['prompt', 'title', 'size', 'quality', 'background']) form.elements[name].value = job[name];
      message('Prompt copied into the editor.');
      form.elements.prompt.focus();
    }

    function card(job) {
      const item = node('article');
      item.className = 'vp3-media-generation-card';
      item.dataset.mediaGenerationId = job.id;
      item.dataset.mediaGenerationState = job.status;
      item.tabIndex = -1;
      item.append(node('h4', job.title), node('p', states[job.status] || 'Status unavailable'));
      if (job.message) item.append(node('p', job.message));
      const src = imageUrl(job.asset);
      if (src) {
        const image = node('img');
        image.src = src;
        image.alt = job.asset.title;
        image.loading = 'lazy';
        item.append(image);
      }
      const actions = node('div');
      actions.className = 'vp3-media-generation-actions';
      if (src && onOpen) {
        const edit = node('button', 'Edit image');
        edit.type = 'button';
        edit.dataset.mediaGenerationEdit = '';
        edit.addEventListener('click', () => onOpen(job.asset));
        actions.append(edit);
      }
      if (pending.has(job.status)) {
        const cancel = node('button', 'Cancel request');
        cancel.type = 'button';
        cancel.dataset.mediaGenerationCancel = '';
        cancel.addEventListener('click', async () => {
          if (!window.confirm('Cancel this image request? If generation has started, provider usage may still count toward your quota.')) return;
          cancel.disabled = true;
          try {
            const result = await api('cancel', {generation_id: job.id, confirmed: true});
            remember(result.generation);
          } catch (error) {
            message(error.message);
          } finally {
            cancel.disabled = false;
          }
        });
        actions.append(cancel);
      } else {
        const reuse = node('button', 'Reuse prompt');
        reuse.type = 'button';
        reuse.disabled = !available || Boolean(activeJob());
        reuse.addEventListener('click', () => reusePrompt(job));
        actions.append(reuse);
      }
      item.append(actions);
      return item;
    }

    function renderHistory() {
      const recent = [...jobs.values()].sort((a, b) =>
        Number(pending.has(b.status)) - Number(pending.has(a.status)) || b.job_id - a.job_id);
      history.replaceChildren(...recent.slice(0, 12).map(card));
      if (!recent.length) history.append(node('p', 'Your image requests will appear here.'));
      updateControls();
    }

    function remember(job, render = true) {
      const previous = jobs.get(job.id);
      if (previous && !pending.has(previous.status) && pending.has(job.status)) return;
      if (JSON.stringify(previous) === JSON.stringify(job)) return;
      jobs.set(job.id, job);
      if (jobs.size > 12) {
        const oldest = [...jobs.values()].filter(item => !pending.has(item.status)).sort((a, b) => a.job_id - b.job_id)[0];
        if (oldest) jobs.delete(oldest.id);
      }
      if (render) renderHistory();
      if (pending.has(previous?.status) && !pending.has(job.status)) {
        message(states[job.status] || '');
        if (job.status === 'completed' && imageUrl(job.asset)) onSaved?.(job.asset);
      }
    }

    function schedule() {
      clearTimeout(timer);
      if (root.isConnected && !document.hidden && !pollBlocked && activeJob()) timer = setTimeout(poll, 2600);
    }

    async function poll() {
      const active = activeJob();
      if (!active || polling || document.hidden || !root.isConnected) return;
      polling = true;
      try {
        const {generation} = await api('get', {generation_id: active.id});
        remember(generation);
        if (!['queued', 'generating'].includes(generation.status) || executing.has(active.id)
          || Date.now() - (lastTick.get(active.id) || 0) < 10000) return;
        // The leased Cloud worker also runs independently. A visible workspace
        // can wake this exact job while status and cancellation stay responsive.
        executing.add(active.id);
        lastTick.set(active.id, Date.now());
        api('tick', {generation_id: active.id})
          .then(result => remember(result.generation))
          .catch(error => message(error.message))
          .finally(() => executing.delete(active.id));
      } catch (error) {
        pollBlocked = [401, 403, 404, 409, 419].includes(error.status);
        message(error.message);
      } finally {
        polling = false;
        schedule();
      }
    }

    async function loadHistory() {
      refresh.disabled = true;
      try {
        const result = await api('status');
        available = result.can_generate === true;
        pollBlocked = false;
        for (const job of [...result.generations].reverse()) remember(job, false);
        renderHistory();
        message(result.message || (activeJob() ? states[activeJob().status] : ''));
      } catch (error) {
        message(error.message);
      } finally {
        refresh.disabled = false;
        schedule();
      }
    }

    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (busy || !available || frozen || activeJob()) return;
      const plan = Object.fromEntries(new FormData(form));
      busy = true;
      updateControls();
      try {
        frozen = await api('preview', plan);
        find('[data-media-generation-review-text]').textContent = frozen.plan.prompt;
        find('[data-media-generation-review-settings]').textContent =
          [frozen.plan.title, frozen.plan.size.replace('x', ' × '), frozen.plan.quality + ' quality', frozen.plan.background + ' background'].filter(Boolean).join(' · ');
        review.hidden = false;
        message('');
      } catch (error) {
        message(error.message);
      } finally {
        busy = false;
        updateControls();
        if (frozen) confirm.focus();
      }
    });

    confirm.addEventListener('click', async () => {
      if (busy || !frozen || !available || activeJob()) return;
      busy = true;
      updateControls();
      try {
        const result = await api('submit', {
          ...frozen.plan, request_key: frozen.request_key, review_token: frozen.review_token, confirmed: true,
        });
        remember(result.generation);
        editPrompt();
        [...history.children].find(item => item.dataset.mediaGenerationId === result.generation.id)?.focus();
        message(states[result.generation.status] || 'Image request accepted.');
        if (result.generation.status === 'completed' && imageUrl(result.generation.asset)) onSaved?.(result.generation.asset);
        void poll();
      } catch (error) {
        if (error.status === 409 && error.message.includes('review expired')) editPrompt();
        message(error.message || 'Refresh image history to verify this request. You can safely confirm this same review again.');
      } finally {
        busy = false;
        updateControls();
      }
    });

    back.addEventListener('click', () => editPrompt(true));
    refresh.addEventListener('click', loadHistory);
    document.addEventListener('visibilitychange', schedule);
    void loadHistory();
  }

  window.VP3MediaImageGeneration = {mount};
}());
