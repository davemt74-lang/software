(() => {
  'use strict';

  const cropRatios = {square: 1, portrait: 4 / 5, wide: 16 / 9, vertical: 9 / 16, print: 3 / 2};
  const maxOutputSide = 4096;
  const maxOutputPixels = 12000000;

  function cropFrame(image, edits) {
    const quarterTurn = edits.rotation % 2 !== 0;
    const sourceWidth = quarterTurn ? image.naturalHeight : image.naturalWidth;
    const sourceHeight = quarterTurn ? image.naturalWidth : image.naturalHeight;
    const ratio = cropRatios[edits.crop] || sourceWidth / sourceHeight;
    const width = Math.min(sourceWidth, sourceHeight * ratio) * 100 / edits.zoom;
    const height = Math.min(sourceHeight, sourceWidth / ratio) * 100 / edits.zoom;
    return {
      sourceWidth,
      sourceHeight,
      width,
      height,
      x: (sourceWidth - width) * edits.cropX / 100,
      y: (sourceHeight - height) * edits.cropY / 100,
    };
  }

  function fitSize(frame, maxSide, maxPixels) {
    const scale = Math.min(
      1,
      maxSide / Math.max(frame.width, frame.height),
      Math.sqrt(maxPixels / (frame.width * frame.height))
    );
    const pixelSize = scale < 1 ? Math.floor : Math.round;
    return {
      width: Math.max(1, pixelSize(frame.width * scale)),
      height: Math.max(1, pixelSize(frame.height * scale)),
    };
  }

  function draw(canvas, image, edits, frame, size) {
    canvas.width = size.width;
    canvas.height = size.height;
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('This browser cannot open the image editor.');
    ctx.save();
    ctx.scale(size.width / frame.width, size.height / frame.height);
    ctx.translate(frame.sourceWidth / 2 - frame.x, frame.sourceHeight / 2 - frame.y);
    ctx.scale(edits.flipHorizontal ? -1 : 1, edits.flipVertical ? -1 : 1);
    ctx.rotate(edits.rotation * Math.PI / 2);
    ctx.drawImage(image, -image.naturalWidth / 2, -image.naturalHeight / 2);
    ctx.restore();

    if (edits.brightness === 100 && edits.contrast === 100) return;
    const pixels = ctx.getImageData(0, 0, size.width, size.height);
    const brightness = edits.brightness / 100;
    const contrast = edits.contrast / 100;
    // A lookup table keeps preview and export identical across canvas implementations.
    const levels = Uint8ClampedArray.from({length: 256}, (_, value) =>
      (Math.min(255, value * brightness) - 127.5) * contrast + 127.5
    );
    for (let index = 0; index < pixels.data.length; index += 4) {
      pixels.data[index] = levels[pixels.data[index]];
      pixels.data[index + 1] = levels[pixels.data[index + 1]];
      pixels.data[index + 2] = levels[pixels.data[index + 2]];
    }
    ctx.putImageData(pixels, 0, 0);
  }

  function readImage(asset) {
    const url = new URL(asset.url, document.baseURI);
    if (url.origin !== location.origin) throw new Error('Choose an image from your private Media Library.');
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = () => reject(new Error('This image could not be opened. It may have been moved or removed.'));
      image.crossOrigin = 'anonymous';
      image.src = url.href;
    });
  }

  function encodePng(canvas) {
    return new Promise((resolve, reject) => {
      canvas.toBlob(blob => {
        if (blob?.size) resolve(blob);
        else reject(new Error('The browser could not export this image. Try cropping it to a smaller size.'));
      }, 'image/png');
    });
  }

  function mount(root, {saveCopy, resolveReturnFocus}) {
    root.classList.add('vp3-media-editor');
    root.hidden = true;
    root.innerHTML = `
      <header class="vp3-media-editor-head">
        <div>
          <h2 tabindex="-1" data-image-editor-heading>Edit image</h2>
          <p data-image-editor-source></p>
        </div>
        <button type="button" data-image-editor-close>Close editor</button>
      </header>
      <p role="status" data-image-editor-status></p>
      <div class="vp3-media-editor-workspace">
        <div class="vp3-media-editor-stage" data-image-editor-stage hidden>
          <canvas role="img" aria-label="Edited image preview" data-image-editor-preview></canvas>
          <p data-image-editor-dimensions></p>
        </div>
        <form class="vp3-media-editor-form">
          <fieldset disabled>
            <legend>Image adjustments</legend>
            <div class="vp3-media-editor-actions">
              <button type="button" data-image-edit="rotate-left">Rotate left</button>
              <button type="button" data-image-edit="rotate-right">Rotate right</button>
              <button type="button" data-image-edit="flip-horizontal" aria-pressed="false">Flip horizontal</button>
              <button type="button" data-image-edit="flip-vertical" aria-pressed="false">Flip vertical</button>
              <button type="button" data-image-edit="reset">Reset edits</button>
            </div>
            <div class="vp3-media-editor-inputs">
              <label>Crop ratio
                <select name="crop">
                  <option value="original">Original</option>
                  <option value="square">1:1 · Square</option>
                  <option value="portrait">4:5 · Portrait</option>
                  <option value="wide">16:9 · Landscape</option>
                  <option value="vertical">9:16 · Vertical</option>
                  <option value="print">3:2 · Photo</option>
                </select>
              </label>
              <label>Crop zoom <input type="range" name="zoom" min="100" max="300" value="100"></label>
              <label>Crop position, left to right <input type="range" name="crop-x" min="0" max="100" value="50"></label>
              <label>Crop position, top to bottom <input type="range" name="crop-y" min="0" max="100" value="50"></label>
              <label>Brightness <input type="range" name="brightness" min="50" max="150" value="100"></label>
              <label>Contrast <input type="range" name="contrast" min="50" max="150" value="100"></label>
            </div>
            <label class="vp3-media-editor-title">Copy title <input type="text" name="title" maxlength="190" required></label>
            <p>Save a private PNG copy. The original stays in your library.</p>
            <button type="submit" data-image-editor-save>Save edited copy</button>
          </fieldset>
        </form>
      </div>
    `;

    const heading = root.querySelector('[data-image-editor-heading]');
    const source = root.querySelector('[data-image-editor-source]');
    const status = root.querySelector('[data-image-editor-status]');
    const stage = root.querySelector('[data-image-editor-stage]');
    const preview = root.querySelector('[data-image-editor-preview]');
    const dimensions = root.querySelector('[data-image-editor-dimensions]');
    const closeButton = root.querySelector('[data-image-editor-close]');
    const form = root.querySelector('form');
    const controls = form.querySelector('fieldset');
    const saveButton = root.querySelector('[data-image-editor-save]');
    const horizontalButton = root.querySelector('[data-image-edit="flip-horizontal"]');
    const verticalButton = root.querySelector('[data-image-edit="flip-vertical"]');
    const field = name => form.elements.namedItem(name);
    let image = null;
    let asset = null;
    let rotation = 0;
    let flipHorizontal = false;
    let flipVertical = false;
    let savedSignature = null;
    let loadingRevision = 0;
    let renderRequest = 0;
    let saving = false;
    let returnFocus = null;

    function setStatus(message, state = '') {
      status.textContent = message;
      status.dataset.state = state;
    }

    function currentEdits() {
      return {
        rotation,
        flipHorizontal,
        flipVertical,
        crop: field('crop').value,
        zoom: Number(field('zoom').value),
        cropX: Number(field('crop-x').value),
        cropY: Number(field('crop-y').value),
        brightness: Number(field('brightness').value),
        contrast: Number(field('contrast').value),
      };
    }

    function resetEdits() {
      const title = field('title').value;
      form.reset();
      field('title').value = title;
      rotation = 0;
      flipHorizontal = false;
      flipVertical = false;
    }

    function updateSaveButton() {
      const title = field('title').value.trim();
      saveButton.disabled = !image || !title || savedSignature === JSON.stringify([title, currentEdits()]);
    }

    function render() {
      if (!image) return;
      const edits = currentEdits();
      const frame = cropFrame(image, edits);
      const output = fitSize(frame, maxOutputSide, maxOutputPixels);
      draw(preview, image, edits, frame, fitSize(frame, 960, 960 * 960));
      dimensions.textContent = `${output.width} × ${output.height} px`;
      if (frame.width - output.width > 1 || frame.height - output.height > 1) {
        dimensions.textContent += ' · scaled to fit the editor';
      }
      field('crop-x').disabled = frame.sourceWidth - frame.width < 1;
      field('crop-y').disabled = frame.sourceHeight - frame.height < 1;
      horizontalButton.setAttribute('aria-pressed', String(flipHorizontal));
      verticalButton.setAttribute('aria-pressed', String(flipVertical));
      updateSaveButton();
    }

    function requestRender() {
      if (renderRequest) return;
      renderRequest = requestAnimationFrame(() => {
        renderRequest = 0;
        try {
          render();
        } catch {
          setStatus('This image could not be previewed. Reset the edits or reopen it.', 'error');
          saveButton.disabled = true;
        }
      });
    }

    form.addEventListener('input', event => {
      if (event.target === field('title')) updateSaveButton();
      else requestRender();
    });
    form.addEventListener('click', event => {
      const action = event.target.closest('[data-image-edit]')?.dataset.imageEdit;
      switch (action) {
        case 'rotate-left': rotation = (rotation + 3) % 4; break;
        case 'rotate-right': rotation = (rotation + 1) % 4; break;
        case 'flip-horizontal': flipHorizontal = !flipHorizontal; break;
        case 'flip-vertical': flipVertical = !flipVertical; break;
        case 'reset': resetEdits(); break;
        default: return;
      }
      requestRender();
    });

    closeButton.addEventListener('click', () => {
      const focusTarget = returnFocus?.getClientRects().length ? returnFocus : resolveReturnFocus?.(asset);
      loadingRevision += 1;
      image = null;
      asset = null;
      preview.width = preview.height = 1;
      root.hidden = true;
      focusTarget?.focus();
    });

    form.addEventListener('submit', async event => {
      event.preventDefault();
      const title = field('title').value.trim();
      if (!image || !title || saving || saveButton.disabled) return;
      const edits = currentEdits();
      const frame = cropFrame(image, edits);
      const size = fitSize(frame, maxOutputSide, maxOutputPixels);
      const output = document.createElement('canvas');
      saving = controls.disabled = closeButton.disabled = true;
      setStatus('Saving your private image copy…');
      try {
        draw(output, image, edits, frame, size);
        const blob = await encodePng(output);
        await saveCopy({blob, title, sourceAssetId: asset.id, ...size, edits});
        savedSignature = JSON.stringify([title, edits]);
        setStatus('Edited copy saved to your Media Library.', 'success');
      } catch (error) {
        setStatus(error.message || 'Could not save the edited copy.', 'error');
      } finally {
        output.width = output.height = 1;
        saving = controls.disabled = closeButton.disabled = false;
        updateSaveButton();
      }
    });

    return {
      async open(selectedAsset) {
        if (saving) return;
        const revision = ++loadingRevision;
        image = null;
        asset = selectedAsset;
        savedSignature = null;
        resetEdits();
        source.textContent = asset.title || 'Untitled image';
        field('title').value = `${source.textContent.slice(0, 180)} — edited`;
        controls.disabled = true;
        stage.hidden = true;
        root.hidden = false;
        if (!root.contains(document.activeElement)) returnFocus = document.activeElement;
        heading.focus();
        setStatus('Opening image…');
        try {
          if (asset.media_type !== 'photo' || asset.available === false) throw new Error('Choose an available photo from your library.');
          const loaded = await readImage(asset);
          if (revision !== loadingRevision) return;
          image = loaded;
          render();
          controls.disabled = false;
          stage.hidden = false;
          setStatus('');
        } catch (error) {
          if (revision !== loadingRevision) return;
          image = null;
          setStatus(error.message || 'Could not open the image.', 'error');
        }
      },
    };
  }

  window.VP3MediaImageEditor = {mount};
})();
