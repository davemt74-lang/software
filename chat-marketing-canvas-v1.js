(() => {
  'use strict';

  const working = new Set(['queued', 'generating', 'paused_job']);
  const saved = new Set(['saved', 'published', 'paused']);
  const stateLabels = {
    starting: 'Review this design to try the image request again.',
    queued: 'Image queued',
    generating: 'Generating image…',
    paused_job: 'Image generation paused',
    ready: 'Graphic ready to save',
    failed: 'Image generation failed',
    cancelled: 'Image generation cancelled',
    saved: 'Saved privately',
    published: 'Published',
    paused: 'Promotion paused'
  };
  const tiers = [
    [1, 'Keep the original photo and add the marketing design.'],
    [4, 'Keep the original product and change the framing, crop, and design.'],
    [7, 'Place a cutout of the original product into a newly generated scene.'],
    [10, 'Create a new composition using the product photo as a reference. Check its shape, color, and logos before approving.']
  ];
  let current = null;

  function node(tag, text) {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    return element;
  }

  function action(text, handler) {
    const element = node('button', text);
    element.type = 'button';
    element.addEventListener('click', handler);
    return element;
  }

  function field(text, input) {
    const element = node('label');
    element.append(node('span', text), input);
    return element;
  }

  function textarea(rows, length) {
    const element = node('textarea');
    element.rows = rows;
    element.maxLength = length;
    return element;
  }

  function range(min, max, value) {
    const element = node('input');
    element.type = 'range';
    element.min = String(min);
    element.max = String(max);
    element.value = String(value);
    return element;
  }

  function localUrl(value, path = '') {
    if (typeof value !== 'string' || !value) return '';
    try {
      const url = new URL(value, location.href);
      return url.origin === location.origin && ['http:', 'https:'].includes(url.protocol)
        && !url.username && !url.password && (!path || url.pathname.endsWith(path)) ? url.href : '';
    } catch {
      return '';
    }
  }

  function setLink(link, value, path) {
    const url = localUrl(value, path);
    if (url) link.href = url;
    else link.removeAttribute('href');
    link.hidden = !url;
  }

  function setImage(image, url) {
    if (url && image.getAttribute('src') !== url) image.src = url;
    else if (!url) image.removeAttribute('src');
    image.hidden = !url;
  }

  function nonce() {
    return Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
  }

  function active(state) {
    return current === state && state.elements.panel.isConnected && state.connection.isCurrent();
  }

  function releaseRender(state) {
    if (state.rendered) URL.revokeObjectURL(state.rendered.url);
    state.rendered = null;
    state.uploadedId = null;
  }

  function close(restoreFocus = true) {
    const state = current;
    current = null;
    if (!state) return;
    clearTimeout(state.timer);
    clearInterval(state.scopeTimer);
    state.controller.abort();
    releaseRender(state);
    state.elements.panel.remove();
    if (restoreFocus) {
      if (state.opener?.isConnected && state.opener.getClientRects().length) state.opener.focus();
      else document.getElementById('chatToolsButton')?.focus();
    }
  }

  function elements(state) {
    const panel = node('section');
    panel.dataset.marketingCanvas = '';
    panel.className = 'vp3-marketing-canvas';
    panel.setAttribute('aria-labelledby', 'vp3MarketingCanvasTitle');
    const heading = node('h2', 'Product promotion');
    heading.id = 'vp3MarketingCanvasTitle';
    heading.tabIndex = -1;
    const header = node('header');
    header.className = 'vp3-marketing-canvas-head';
    const headerCopy = node('div');
    headerCopy.append(node('small', 'Agent Chat Canvas'), heading,
      node('p', 'Create and review a marketing graphic for this product.'));
    header.append(headerCopy, action('Close promotion', () => close()));

    const status = node('p');
    status.setAttribute('role', 'status');
    status.dataset.marketingStatus = '';

    const drafts = node('select');
    drafts.dataset.marketingVariants = '';
    const draftsLabel = field('Recent designs', drafts);
    drafts.addEventListener('change', () => void chooseVariant(state, drafts.value));

    const settings = node('fieldset');
    settings.className = 'vp3-marketing-settings';
    const level = range(1, 10, 1);
    level.id = 'vp3MarketingImageLevel';
    level.dataset.marketingLevel = '';
    level.setAttribute('aria-describedby', 'vp3MarketingImageTreatment');
    const rangeEnds = node('small', '1 · Keep original       10 · New scene');
    const levelHelp = node('output');
    levelHelp.id = 'vp3MarketingImageTreatment';
    levelHelp.setAttribute('for', level.id);
    const slider = field('Use original image', level);
    slider.append(rangeEnds, levelHelp);
    const headline = textarea(2, 120);
    headline.dataset.marketingHeadline = '';
    const copy = textarea(3, 600);
    copy.dataset.marketingCopy = '';
    const prompt = textarea(3, 600);
    prompt.dataset.marketingPrompt = '';
    const promptLabel = field('Scene directions', prompt);
    const style = node('select');
    for (const [key, title] of [['clean','Clean'], ['bold','Bold'], ['warm','Warm']]) style.add(new Option(title, key));
    const removeBackground = node('input');
    removeBackground.type = 'checkbox';
    const cutout = node('div');
    const cutoutLabel = field('Remove a solid background', removeBackground);
    cutoutLabel.className = 'vp3-marketing-check';
    const tolerance = range(8, 64, 24);
    tolerance.id = 'vp3MarketingCutoutTolerance';
    const toleranceLabel = field('Cutout tolerance', tolerance);
    cutout.append(node('small', 'Use a transparent product photo or remove a solid background before generating a scene.'),
      cutoutLabel, toleranceLabel);
    settings.append(node('legend', 'Graphic settings'), slider, field('Headline', headline),
      field('Marketing copy', copy), field('Layout style', style), promptLabel, cutout);
    settings.addEventListener('input', () => editSettings(state));

    const comparison = node('div');
    comparison.className = 'vp3-marketing-comparison';
    const original = node('figure');
    const sourceImage = node('img');
    sourceImage.alt = 'Original product photo';
    original.append(node('figcaption', 'Original product photo'), sourceImage);
    const visitor = node('figure');
    visitor.dataset.marketingVisitor = '';
    const visitorImage = node('img');
    visitorImage.alt = 'Proposed marketing graphic';
    const productLink = node('a', 'View product');
    productLink.target = '_blank';
    productLink.rel = 'noopener noreferrer';
    const placeholder = node('p', 'Your proposed graphic will appear here.');
    const listingHint = node('small', 'Make the product listing public in Profile Commerce to preview the visitor link.');
    visitor.append(node('figcaption', 'Visitor preview'), visitorImage, productLink, placeholder, listingHint);
    comparison.append(original, visitor);

    const confirmation = node('div');
    confirmation.dataset.marketingConfirmation = '';
    const confirmationText = node('p');
    const generate = action('Create private graphic', () => void generateGraphic(state));
    const publish = action('Approve and publish', () => void publishGraphic(state));
    const back = action('Back to design', () => {
      state.approval = null;
      paint(state);
      prepare.focus();
    });
    confirmation.append(confirmationText, generate, publish, back);
    const prepare = action('Review graphic settings', () => void prepareGraphic(state));
    const retry = action('Retry opening draft', () => void loadDraft(state));
    const refresh = action('Refresh image status', () => void poll(state, true));
    const cancel = action('Cancel image generation', () => void cancelGeneration(state));
    const retryRender = action('Retry graphic preview', () => void work(state, 'Rendering your graphic…', () => receiveVariant(state, state.variant)));
    const save = action('Save to Media Library', () => void saveGraphic(state));
    const publishReview = action('Review publication', () => void reviewPublication(state));
    const management = node('a', 'Manage product listing');
    management.target = '_blank';
    management.rel = 'noopener noreferrer';
    const mediaLibrary = node('a', 'Open Media Library');
    mediaLibrary.target = '_blank';
    mediaLibrary.rel = 'noopener noreferrer';
    const controls = node('div');
    controls.className = 'vp3-marketing-controls';
    controls.append(prepare, retry, refresh, cancel, retryRender, save, publishReview, mediaLibrary, management);

    const live = node('div');
    live.className = 'vp3-marketing-live';
    const liveStatus = node('p');
    const publicLink = node('a', 'View public promotion');
    publicLink.target = '_blank';
    publicLink.rel = 'noopener noreferrer';
    const pause = action('Pause promotion', () => void pausePromotion(state));
    live.append(liveStatus, publicLink, pause);
    panel.append(header, status, draftsLabel, settings, comparison, controls, confirmation, live);
    return {panel, heading, status, drafts, draftsLabel, settings, level, levelHelp, headline, copy, prompt,
      promptLabel, style, removeBackground, cutout, tolerance, toleranceLabel, sourceImage, visitorImage,
      productLink, placeholder, listingHint, confirmation, confirmationText, generate,
      publish, back, prepare, retry, refresh, cancel, retryRender, save, publishReview, management, mediaLibrary, live,
      liveStatus, publicLink, pause};
  }

  function graphicSettings(state) {
    const input = state.elements;
    const level = Number(input.level.value);
    const cutout = level >= 5 && level <= 7 && input.removeBackground.checked;
    return {
      level, headline:input.headline.value.trim(), copy:input.copy.value.trim(),
      prompt:level >= 5 ? input.prompt.value.trim() : '', style:input.style.value,
      remove_background:cutout, tolerance:cutout ? Number(input.tolerance.value) : 24
    };
  }

  function fillSettings(state, variant) {
    const input = state.elements;
    const {draft} = state;
    input.headline.value = variant?.headline ?? draft.title ?? '';
    input.copy.value = variant?.copy ?? draft.description ?? '';
    input.level.value = String(variant?.level ?? 1);
    input.style.value = variant?.style ?? 'clean';
    input.prompt.value = variant?.prompt ?? '';
    input.removeBackground.checked = variant?.remove_background === true;
    input.tolerance.value = String(variant?.tolerance ?? 24);
  }

  function paint(state) {
    if (!active(state)) return;
    const {elements: ui, variant, draft, approval, promotion} = state;
    const busy = state.busy || state.rendering;
    const {level, headline, copy} = graphicSettings(state);
    const inProgress = working.has(variant?.status);
    const stored = saved.has(variant?.status);
    const confirmGenerate = approval?.action === 'generate';
    const confirmPublish = approval?.action === 'publish';
    const disabled = busy || inProgress;
    ui.level.setAttribute('aria-valuetext', level + ' of 10');
    ui.levelHelp.textContent = level + ' / 10 · ' + tiers.find(([end]) => level <= end)[1];
    ui.promptLabel.hidden = level < 5;
    ui.cutout.hidden = level < 5 || level > 7;
    ui.toleranceLabel.hidden = !ui.removeBackground.checked;
    ui.settings.disabled = !draft || disabled;
    ui.draftsLabel.hidden = !state.variants.size;
    ui.drafts.disabled = busy || inProgress;
    ui.prepare.hidden = !draft || Boolean(approval) || inProgress;
    ui.prepare.disabled = busy || !headline || !copy;
    ui.retry.hidden = Boolean(draft) || busy;
    ui.refresh.hidden = !inProgress && variant?.status !== 'starting';
    ui.refresh.disabled = state.polling || busy;
    ui.cancel.hidden = !inProgress;
    ui.cancel.disabled = busy;
    ui.save.hidden = !state.rendered || stored;
    ui.retryRender.hidden = busy || Boolean(state.rendered) || variant?.status !== 'ready';
    ui.save.disabled = busy;
    ui.publishReview.hidden = !stored || variant?.status === 'published' || Boolean(approval);
    ui.publishReview.disabled = busy;
    ui.mediaLibrary.hidden = !stored;
    ui.confirmation.hidden = !approval;
    ui.generate.hidden = !confirmGenerate;
    ui.generate.disabled = busy;
    ui.publish.hidden = !confirmPublish;
    ui.publish.disabled = busy;
    ui.back.disabled = busy;
    ui.confirmationText.textContent = confirmPublish
      ? 'Approve this exact graphic, copy, and product link for your public profile and Profile Agent. This replaces any promotion currently active for this product.'
      : level >= 8
        ? 'Generating this composition sends the reviewed product photo and scene directions to OpenAI. Check the product details in the result.'
        : level >= 5
          ? 'Generate a background with OpenAI, then place your product cutout into the scene. The source photo stays private.'
          : 'Create a private marketing design using the original product photo and this copy.';
    ui.generate.textContent = level >= 5 ? 'Generate with OpenAI' : 'Create private graphic';
    const proposed = state.rendered?.url || localUrl(variant?.image_url, '/media-library-file-v86.php');
    setImage(ui.sourceImage, localUrl(variant?.source_image_url || draft?.image_url, '/media-library-file-v86.php'));
    setImage(ui.visitorImage, proposed);
    ui.placeholder.hidden = Boolean(proposed);
    ui.visitorImage.alt = proposed ? `${variant?.headline || headline}. ${variant?.copy || copy}` : 'Proposed marketing graphic';
    const cta = variant?.cta ?? draft?.cta;
    ui.productLink.textContent = typeof cta === 'string' && cta ? cta : 'View product';
    setLink(ui.productLink, proposed ? variant?.product_url ?? draft?.product_url : '');
    ui.listingHint.hidden = !proposed || !ui.productLink.hidden;
    setLink(ui.management, draft?.management_url, '/profile-commerce-products.php');
    ui.mediaLibrary.href = new URL('../media-library.php', state.connection.endpoint).href;
    ui.live.hidden = !promotion;
    ui.liveStatus.textContent = promotion?.public_url ? 'Live on your public profile'
      : promotion ? 'Published design is currently unavailable to visitors.' : '';
    setLink(ui.publicLink, promotion?.public_url);
    ui.pause.disabled = busy;
  }

  function rememberVariant(state, variant) {
    if (!variant || typeof variant.id !== 'string') return;
    const known = state.variants.get(variant.id);
    state.variants.set(variant.id, variant);
    if (!known || known.status !== variant.status) {
      const select = state.elements.drafts;
      select.replaceChildren(new Option('Choose a saved or recent design', ''));
      for (const item of state.variants.values()) {
        select.add(new Option(`Level ${item.level} · ${stateLabels[item.status] || 'Design'}`, item.id));
      }
    }
    state.elements.drafts.value = state.variant?.id || '';
  }

  async function request(state, actionName, fields = {}) {
    const {connection} = state;
    const response = await fetch(connection.endpoint, {
      method:'POST', credentials:'same-origin', cache:'no-store', signal:state.controller.signal,
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:actionName, tool:'marketing', owner_key:connection.ownerKey,
        csrf_token:connection.csrf, ...connection.scope, ...fields})
    });
    const data = await response.json().catch(() => null);
    if (!active(state)) throw new Error('Chat changed.');
    if (!response.ok || data?.ok !== true) {
      const error = new Error(data?.error || 'The promotion request could not be verified. Try again.');
      error.status = response.status;
      if ([403, 419].includes(response.status) || (response.status === 409 && /account changed/i.test(error.message))) close();
      throw error;
    }
    if (data.owner_key !== connection.ownerKey || data.tool !== 'marketing'
      || data.agent_id !== connection.scope.agent_id || data.conversation_id !== connection.scope.conversation_id) {
      close();
      throw new Error('Chat changed.');
    }
    return data;
  }

  function status(state, text) {
    if (active(state)) state.elements.status.textContent = text;
  }

  async function work(state, message, callback) {
    if (!active(state) || state.busy || state.rendering) return;
    state.busy = true;
    status(state, message);
    paint(state);
    try {
      await callback();
    } catch (error) {
      if (error.status === 409) state.approval = null;
      status(state, error.message || 'The promotion request was interrupted. Try again.');
    } finally {
      state.busy = false;
      paint(state);
    }
  }

  function editSettings(state) {
    if (!active(state) || state.busy || working.has(state.variant?.status)) return;
    state.approval = null;
    state.prepareNonce = null;
    state.variant = null;
    state.elements.drafts.value = '';
    releaseRender(state);
    status(state, '');
    paint(state);
  }

  async function loadDraft(state) {
    await work(state, 'Opening the private marketing draft…', async () => {
      const data = await request(state, 'creative_open', state.reference);
      const {draft, creative, promotion} = data;
      if (!draft || draft.draft_id !== state.reference.draft_id || draft.product_id !== state.reference.product_id
        || String(draft.asset_id) !== state.reference.source_id) throw new Error('The selected marketing draft changed.');
      state.draft = draft;
      state.promotion = promotion || (creative?.status === 'published' ? creative : null);
      const hints = state.prefill;
      state.variant = !hints || working.has(creative?.status) ? creative : null;
      for (const item of data.variants || []) rememberVariant(state, item);
      fillSettings(state, state.variant);
      if (hints && !state.variant) {
        state.elements.level.value = String(hints.level ?? 1);
        state.elements.prompt.value = hints.prompt ?? '';
      }
      if (state.variant) await receiveVariant(state, state.variant);
      else status(state, '');
    });
  }

  async function chooseVariant(state, id) {
    if (!id || !state.variants.has(id)) return;
    await work(state, 'Loading this design…', async () => {
      const {creative} = await request(state, 'creative_status', {creative_id:id});
      if (creative?.id !== id) throw new Error('This design could not be verified.');
      state.approval = null;
      state.prepareNonce = null;
      releaseRender(state);
      fillSettings(state, creative);
      await receiveVariant(state, creative);
    });
  }

  async function prepareGraphic(state) {
    await work(state, 'Checking your graphic settings…', async () => {
      const settings = graphicSettings(state);
      const {level} = settings;
      if (level >= 5 && level <= 7 && window.VP3MarketingCreativeRender?.validateSource) {
        await window.VP3MarketingCreativeRender.validateSource({
          originalUrl:state.draft.image_url, level, removeBackground:settings.remove_background,
          tolerance:settings.tolerance, signal:state.controller.signal
        });
      }
      const fields = {...state.reference, ...settings, nonce:state.prepareNonce ??= nonce()};
      const {creative, review_token: token} = await request(state, 'creative_prepare', fields);
      if (!creative?.id || creative.level !== level || typeof token !== 'string') throw new Error('The graphic review could not be verified.');
      releaseRender(state);
      state.variant = null;
      state.approval = {action:'generate', token, fields, id:creative.id};
      status(state, 'Review the image treatment before creating your graphic.');
      requestAnimationFrame(() => active(state) && state.elements.generate.focus());
    });
  }

  async function generateGraphic(state) {
    const {approval} = state;
    if (approval?.action !== 'generate') return;
    await work(state, 'Preparing your private graphic…', async () => {
      const {creative} = await request(state, 'creative_generate', {...approval.fields, review_token:approval.token, confirmed:true});
      if (creative?.id !== approval.id) throw new Error('The generated design could not be verified.');
      state.approval = null;
      state.prepareNonce = null;
      await receiveVariant(state, creative);
    });
  }

  async function receiveVariant(state, variant) {
    if (!active(state) || !variant || !Object.hasOwn(stateLabels, variant.status)) return;
    state.variant = variant;
    rememberVariant(state, variant);
    if (variant.status === 'published') state.promotion = variant;
    status(state, variant.message || stateLabels[variant.status]);
    paint(state);
    if (variant.status === 'ready' && !state.rendered) {
      const render = window.VP3MarketingCreativeRender?.render;
      if (!render) throw new Error('The graphic renderer is unavailable. Reload Chat.');
      const input = {
        originalUrl:variant.source_image_url || state.draft.image_url,
        generatedUrl:variant.generated_url,
        level:variant.level, headline:variant.headline, copy:variant.copy, style:variant.style,
        removeBackground:variant.remove_background === true, tolerance:variant.tolerance ?? 24,
        signal:state.controller.signal
      };
      state.rendering = true;
      status(state, 'Rendering your graphic…');
      paint(state);
      try {
        const graphic = await render(input);
        if (!active(state) || state.variant !== variant) return;
        state.rendered = {blob:graphic.blob, url:URL.createObjectURL(graphic.blob)};
        status(state, stateLabels.ready);
      } finally {
        state.rendering = false;
        paint(state);
      }
    }
    if (working.has(variant.status)) schedule(state);
  }

  function schedule(state) {
    clearTimeout(state.timer);
    if (active(state) && working.has(state.variant?.status) && !document.hidden) {
      state.timer = setTimeout(() => void poll(state), 2500);
    }
  }

  async function poll(state, manual = false) {
    if (!active(state) || state.polling || state.busy
      || (!working.has(state.variant?.status) && !(manual && state.variant?.status === 'starting'))) return;
    const id = state.variant.id;
    state.polling = true;
    clearTimeout(state.timer);
    paint(state);
    try {
      const {creative} = await request(state, 'creative_status', {creative_id:id});
      if (state.variant?.id !== id || state.busy
        || (!working.has(state.variant.status) && state.variant.status !== 'starting') || creative?.id !== id) return;
      await receiveVariant(state, creative);
      if (manual && creative.status === 'queued' && state.ticking !== id) state.tickAttempt = null;
      if (creative.status === 'queued' && state.ticking !== id && state.tickAttempt !== id) {
        state.tickAttempt = id;
        state.ticking = id;
        void request(state, 'creative_tick', {creative_id:id}).then(async data => {
          if (active(state) && state.variant?.id === id && working.has(state.variant.status)
            && !state.busy && data.creative?.id === id) await receiveVariant(state, data.creative);
        }).catch(error => {
          if (state.variant?.id === id && working.has(state.variant.status)) status(state, error.message);
        }).finally(() => { if (state.ticking === id) state.ticking = null; });
      }
    } catch (error) {
      status(state, error.message);
    } finally {
      state.polling = false;
      paint(state);
      schedule(state);
    }
  }

  async function cancelGeneration(state) {
    const id = state.variant?.id;
    if (!id || !working.has(state.variant.status)) return;
    await work(state, 'Cancelling the image request…', async () => {
      const {creative} = await request(state, 'creative_cancel', {creative_id:id, confirmed:true});
      if (creative?.id !== id) throw new Error('Cancellation could not be verified.');
      await receiveVariant(state, creative);
    });
  }

  async function upload(state, fields, chunk) {
    const body = new FormData();
    for (const [key, value] of Object.entries({csrf_token:state.connection.csrf, ...fields})) body.set(key, value);
    if (chunk) body.set('chunk', chunk, 'promotion.png');
    const response = await fetch(state.connection.mediaEndpoint, {
      method:'POST', credentials:'same-origin', cache:'no-store', signal:state.controller.signal,
      headers:{'X-VP3-Media-Owner':state.connection.ownerKey}, body
    });
    const data = await response.json().catch(() => null);
    if (!active(state)) throw new Error('Chat changed.');
    if (!response.ok || data?.ok !== true) throw new Error(data?.error || 'The private graphic could not be saved.');
    return data;
  }

  async function uploadGraphic(state, variant, blob) {
    const {token, chunk_size:chunkSize} = await upload(state, {
      action:'begin', media_type:'photo', mime_type:'image/png', title:variant.headline,
      source:'promotion_design', original_name:'promotion.png'
    });
    if (typeof token !== 'string' || !Number.isSafeInteger(chunkSize) || chunkSize < 1) throw new Error('The private upload could not be started.');
    const chunkBytes = Math.min(chunkSize, 1024 * 1024);
    const total = Math.ceil(blob.size / chunkBytes);
    for (let index = 0; index < total; index++) {
      await upload(state, {action:'chunk', token, index, total_chunks:total},
        blob.slice(index * chunkBytes, (index + 1) * chunkBytes, 'image/png'));
    }
    const {asset} = await upload(state, {
      action:'finish', token, metadata_json:JSON.stringify({creative_id:variant.id})
    });
    if (!Number.isSafeInteger(asset?.id) || asset.id < 1) throw new Error('The saved media asset could not be verified.');
    return asset.id;
  }

  async function saveGraphic(state) {
    const {variant, rendered} = state;
    if (!rendered?.blob || variant?.status !== 'ready') return;
    await work(state, 'Saving the exact graphic to Media Library…', async () => {
      const assetId = state.uploadedId ??= await uploadGraphic(state, variant, rendered.blob);
      const {creative} = await request(state, 'creative_save', {creative_id:variant.id, asset_id:assetId});
      if (creative?.id !== variant.id || !saved.has(creative.status)) throw new Error('The saved design could not be verified.');
      releaseRender(state);
      await receiveVariant(state, creative);
    });
  }

  async function reviewPublication(state) {
    const id = state.variant?.id;
    if (!id || !saved.has(state.variant.status)) return;
    await work(state, 'Verifying the visitor preview…', async () => {
      const {creative, review_token: token} = await request(state, 'creative_publish_preview', {creative_id:id});
      if (creative?.id !== id || !saved.has(creative.status) || typeof token !== 'string'
        || !localUrl(creative.image_url, '/media-library-file-v86.php') || !localUrl(creative.product_url)) {
        throw new Error('The graphic and product link could not be verified.');
      }
      state.approval = {action:'publish', id, token};
      await receiveVariant(state, creative);
      status(state, 'Check the visitor preview before you publish.');
      requestAnimationFrame(() => active(state) && state.elements.publish.focus());
    });
  }

  async function publishGraphic(state) {
    const {approval} = state;
    if (approval?.action !== 'publish') return;
    await work(state, 'Publishing this exact promotion…', async () => {
      const {creative} = await request(state, 'creative_publish', {
        creative_id:approval.id, confirmed:true, review_token:approval.token
      });
      if (creative?.id !== approval.id || creative.status !== 'published') throw new Error('Publication could not be verified.');
      if (state.promotion?.id !== approval.id && state.variants.has(state.promotion?.id)) {
        rememberVariant(state, {...state.promotion, status:'paused', public_url:''});
      }
      state.approval = null;
      await receiveVariant(state, creative);
    });
  }

  async function pausePromotion(state) {
    const id = state.promotion?.id;
    if (!id) return;
    await work(state, 'Pausing the public promotion…', async () => {
      const {creative} = await request(state, 'creative_pause', {creative_id:id, confirmed:true});
      if (creative?.id !== id || creative.status !== 'paused') throw new Error('The promotion pause could not be verified.');
      state.promotion = null;
      if (state.variant?.id === id) await receiveVariant(state, creative);
      else {
        rememberVariant(state, creative);
        status(state, stateLabels.paused);
      }
    });
  }

  function open(reference, connection, prefill) {
    const thread = document.getElementById('chatThread');
    const endpoint = localUrl(connection.endpoint, '/api/initiative-workbench-v1.php');
    const mediaEndpoint = localUrl(connection.mediaEndpoint, '/api/media-library-v86.php');
    if (!thread || !endpoint || !mediaEndpoint || !connection.isCurrent()) return false;
    if (thread.dataset.view && thread.dataset.view !== 'chat') {
      const chat = window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
      chat?.showChatView?.();
      if (thread.dataset.view !== 'chat') return false;
    }
    close(false);
    const state = {reference, connection:{...connection, endpoint, mediaEndpoint}, prefill,
      opener:document.activeElement, controller:new AbortController(), variants:new Map()};
    state.elements = elements(state);
    current = state;
    thread.append(state.elements.panel);
    state.scopeTimer = setInterval(() => { if (!active(state) && current === state) close(false); }, 200);
    paint(state);
    requestAnimationFrame(() => {
      if (active(state)) state.elements.heading.focus();
    });
    void loadDraft(state);
    return true;
  }

  for (const event of ['stonefellow:chat-context-changing', 'vp3:chat-conversation-changed', 'pagehide']) {
    window.addEventListener(event, () => close(false));
  }
  document.addEventListener('visibilitychange', () => {
    if (current) schedule(current);
  });
  window.VP3MarketingCanvas = Object.freeze({open});
})();
