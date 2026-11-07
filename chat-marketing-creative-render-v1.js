(() => {
  'use strict';

  const width = 1080;
  const margin = 48;
  const contentWidth = width - 2 * margin;
  const maxImageBytes = 16 * 1024 * 1024;
  const palettes = {
    clean: { paper: '#f6f8f5', photo: '#e3ebe5', ink: '#172b23', muted: '#34483f', accent: '#21775a' },
    bold: { paper: '#111b33', photo: '#273758', ink: '#ffffff', muted: '#d9e5fa', accent: '#c6f45c' },
    warm: { paper: '#faf1e5', photo: '#f0d8c3', ink: '#3c251c', muted: '#604236', accent: '#a5462e' }
  };
  const imageTypes = new Set(['image/png', 'image/jpeg', 'image/webp']);

  function ownedImageUrl(value) {
    let url;
    try {
      url = new URL(value, location.href);
    } catch {
      throw new Error('Choose an available image from your private Media Library.');
    }
    if (url.protocol !== location.protocol || url.origin !== location.origin || url.username || url.password || url.hash
        || !url.pathname.endsWith('/media-library-file-v86.php')
        || !/^[1-9]\d*$/.test(url.searchParams.get('id') || '')
        || [...url.searchParams].length !== 1) {
      throw new Error('Choose an available image from your private Media Library.');
    }
    return url.href;
  }

  async function readImage(url, signal) {
    const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal});
    const mime = (response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
    if (!response.ok || !imageTypes.has(mime)) throw new Error('The saved image could not be opened.');
    if (Number(response.headers.get('Content-Length')) > maxImageBytes) {
      throw new Error('Choose a photo under 16 MB for this design.');
    }
    const bytes = await response.blob();
    if (!bytes.size || bytes.size > maxImageBytes) throw new Error('Choose a photo under 16 MB for this design.');
    const address = URL.createObjectURL(bytes);
    try {
      const image = new Image();
      await new Promise((resolve, reject) => {
        image.onload = resolve;
        image.onerror = () => reject(new Error('The saved image could not be decoded.'));
        image.src = address;
      });
      if (signal?.aborted) throw new DOMException('Design cancelled.', 'AbortError');
      if (image.naturalWidth * image.naturalHeight > 48_000_000) {
        throw new Error('Resize this photo before using it in a promotion.');
      }
      return image;
    } finally {
      URL.revokeObjectURL(address);
    }
  }

  function newCanvas(w, h) {
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    return canvas;
  }

  function drawImageIn(ctx, image, frame, fill = false, inset = 0) {
    const w = image.naturalWidth || image.width;
    const h = image.naturalHeight || image.height;
    const scaleX = (frame.w - 2 * inset) / w;
    const scaleY = (frame.h - 2 * inset) / h;
    const scale = fill ? Math.max(scaleX, scaleY) : Math.min(scaleX, scaleY);
    const drawnW = w * scale;
    const drawnH = h * scale;
    ctx.drawImage(image, frame.x + (frame.w - drawnW) / 2, frame.y + (frame.h - drawnH) / 2, drawnW, drawnH);
  }

  function closeColor(data, offset, rgb, tolerance) {
    return Math.abs(data[offset] - rgb[0]) <= tolerance
      && Math.abs(data[offset + 1] - rgb[1]) <= tolerance
      && Math.abs(data[offset + 2] - rgb[2]) <= tolerance;
  }

  function cornerColor({data, width: w, height: h}, tolerance) {
    const side = Math.min(6, Math.floor(Math.min(w, h) / 4));
    if (side < 1) return null;
    const sums = [0, 0, 0];
    const corners = [[0, 0], [w - side, 0], [0, h - side], [w - side, h - side]];
    for (const [x, y] of corners) {
      for (let row = y; row < y + side; row++) {
        for (let col = x; col < x + side; col++) {
          const offset = 4 * (row * w + col);
          for (let channel = 0; channel < 3; channel++) sums[channel] += data[offset + channel];
        }
      }
    }
    const rgb = sums.map(sum => sum / (corners.length * side * side));
    const cornerTolerance = Math.min(24, tolerance);
    for (const [x, y] of corners) {
      for (let row = y; row < y + side; row++) {
        for (let col = x; col < x + side; col++) {
          if (!closeColor(data, 4 * (row * w + col), rgb, cornerTolerance)) return null;
        }
      }
    }
    return rgb;
  }

  function removeSolidBackground(imageData, tolerance) {
    const {data, width: w, height: h} = imageData;
    const rgb = cornerColor(imageData, tolerance);
    if (!rgb) throw new Error('The corners have different colors. Choose a transparent product photo or one with a solid background.');
    const area = w * h;
    const visited = new Uint8Array(area);
    const queue = new Uint32Array(area);
    let end = 0;
    function visit(index) {
      if (visited[index]) return;
      visited[index] = 1;
      const offset = 4 * index;
      if (data[offset + 3] <= 8 || closeColor(data, offset, rgb, tolerance)) queue[end++] = index;
    }
    // Only remove matching color reachable from an edge, so matching product
    // details surrounded by the foreground stay intact.
    for (let x = 0; x < w; x++) {
      visit(x);
      visit((h - 1) * w + x);
    }
    for (let y = 1; y < h - 1; y++) {
      visit(y * w);
      visit((y + 1) * w - 1);
    }
    for (let start = 0; start < end; start++) {
      const index = queue[start];
      const x = index % w;
      data[4 * index + 3] = 0;
      if (x > 0) visit(index - 1);
      if (x < w - 1) visit(index + 1);
      if (index >= w) visit(index - w);
      if (index < area - w) visit(index + w);
    }
    if (end < area * .06 || end > area * .99) {
      throw new Error('A clear product cutout could not be separated. Adjust the background tolerance or use a transparent product photo.');
    }
  }

  function productCutout(image, removeBackground, tolerance) {
    const scale = Math.min(1, 1080 / Math.max(image.naturalWidth, image.naturalHeight));
    const canvas = newCanvas(Math.max(1, Math.round(image.naturalWidth * scale)), Math.max(1, Math.round(image.naturalHeight * scale)));
    const ctx = canvas.getContext('2d', {willReadFrequently: true});
    const w = canvas.width;
    const h = canvas.height;
    ctx.drawImage(image, 0, 0, w, h);
    const pixels = ctx.getImageData(0, 0, w, h);
    const {data} = pixels;
    let transparent = 0;
    let clearEdge = 0;
    for (let index = 0; index < w * h; index++) {
      if (data[4 * index + 3] > 8) continue;
      transparent++;
      const x = index % w;
      if (index < w || index >= (h - 1) * w || x === 0 || x === w - 1) clearEdge++;
    }
    if (transparent < w * h * .025 || clearEdge < (2 * w + 2 * h - 4) * .12) {
      if (!removeBackground) {
        throw new Error('Choose a transparent product photo or turn on Remove solid background to place this product in a new scene.');
      }
      removeSolidBackground(pixels, tolerance);
      ctx.putImageData(pixels, 0, 0);
    }
    let left = w;
    let top = h;
    let right = -1;
    let bottom = -1;
    for (let index = 0; index < w * h; index++) {
      if (data[4 * index + 3] <= 8) continue;
      const x = index % w;
      const y = Math.floor(index / w);
      left = Math.min(left, x);
      top = Math.min(top, y);
      right = Math.max(right, x);
      bottom = Math.max(bottom, y);
    }
    if (right < left || bottom < top) throw new Error('The selected image contains no visible product.');
    const product = newCanvas(right - left + 1, bottom - top + 1);
    product.getContext('2d').drawImage(canvas, left, top, product.width, product.height, 0, 0, product.width, product.height);
    return product;
  }

  function wrappedText(ctx, value, maxWidth) {
    const lines = [];
    let line = '';
    for (const word of value.trim().split(/\s+/u)) {
      const next = line ? `${line} ${word}` : word;
      if (ctx.measureText(next).width <= maxWidth) {
        line = next;
        continue;
      }
      if (line) lines.push(line);
      line = '';
      if (ctx.measureText(word).width <= maxWidth) {
        line = word;
        continue;
      }
      const units = typeof Intl.Segmenter === 'function'
        ? [...new Intl.Segmenter(undefined, {granularity: 'grapheme'}).segment(word)].map(part => part.segment)
        : Array.from(word);
      for (const unit of units) {
        if (line && ctx.measureText(line + unit).width > maxWidth) {
          lines.push(line);
          line = '';
        }
        line += unit;
      }
    }
    if (line) lines.push(line);
    return lines;
  }

  function textLayout(ctx, headline, copy, preferredPhotoHeight) {
    let shortenedFrame = null;
    for (const [headlineSize, copySize] of [[64, 36], [56, 32], [48, 30]]) {
      const titleFont = `750 ${headlineSize}px system-ui, sans-serif`;
      const copyFont = `450 ${copySize}px system-ui, sans-serif`;
      const titleStep = Math.ceil(headlineSize * 1.19);
      const copyStep = Math.ceil(copySize * 1.35);
      ctx.font = titleFont;
      const titleLines = wrappedText(ctx, headline, contentWidth);
      ctx.font = copyFont;
      const copyLines = wrappedText(ctx, copy, contentWidth);
      const designHeight = 2 * margin + 58 + titleLines.length * titleStep
        + (copyLines.length ? 14 + copyLines.length * copyStep : 0);
      const photoHeight = Math.min(preferredPhotoHeight, 1920 - designHeight);
      const layout = {height: designHeight + photoHeight, photoHeight, titleFont, copyFont, titleStep, copyStep, titleLines, copyLines};
      if (photoHeight === preferredPhotoHeight) return layout;
      if (photoHeight >= 460) shortenedFrame = layout;
    }
    if (shortenedFrame) return shortenedFrame;
    throw new Error('Shorten the headline or copy so it fits the promotion graphic.');
  }

  function paintPhoto(ctx, palette, frame, original, generated, product, level) {
    ctx.fillStyle = palette.photo;
    ctx.fillRect(frame.x, frame.y, frame.w, frame.h);
    ctx.save();
    ctx.beginPath();
    ctx.rect(frame.x, frame.y, frame.w, frame.h);
    ctx.clip();
    if (level <= 4) {
      if (level >= 3) {
        ctx.globalAlpha = level === 4 ? .4 : .23;
        ctx.filter = 'blur(32px)';
        drawImageIn(ctx, original, {x: frame.x - 32, y: frame.y - 32, w: frame.w + 64, h: frame.h + 64}, true);
        ctx.filter = 'none';
        ctx.globalAlpha = 1;
      }
      drawImageIn(ctx, original, frame, false, [0, 32, 18, 12, 0][level]);
    } else if (product) {
      drawImageIn(ctx, generated, frame, true);
      const size = [.7, .64, .58][level - 5];
      const center = [.5, .56, .61][level - 5];
      drawImageIn(ctx, product, {
        x: frame.x + frame.w * (center - size / 2),
        y: frame.y + frame.h * (.86 - size),
        w: frame.w * size, h: frame.h * size
      });
    } else {
      drawImageIn(ctx, generated, frame);
    }
    ctx.restore();
  }

  async function render({originalUrl, generatedUrl, level, headline, copy = '', style = 'clean', removeBackground = false, tolerance = 24, signal}) {
    if (!Number.isInteger(level) || level < 1 || level > 10 || !Object.hasOwn(palettes, style)
        || typeof headline !== 'string' || !headline.trim() || Array.from(headline).length > 120
        || typeof copy !== 'string' || Array.from(copy).length > 600
        || !Number.isInteger(tolerance) || tolerance < 8 || tolerance > 64) {
      throw new Error('Review the image treatment, headline, and copy before designing the promotion.');
    }
    const originalAddress = ownedImageUrl(originalUrl);
    const generatedAddress = level >= 5 ? ownedImageUrl(generatedUrl) : '';
    const [original, generated] = await Promise.all([
      level <= 7 ? readImage(originalAddress, signal) : null,
      generatedAddress ? readImage(generatedAddress, signal) : null
    ]);
    const product = level >= 5 && level <= 7 ? productCutout(original, removeBackground === true, tolerance) : null;
    const preferredPhotoHeight = [0, 584, 620, 656, 692, 670, 688, 706, 698, 724, 750][level];
    const canvas = newCanvas(width, 1);
    const ctx = canvas.getContext('2d');
    const layout = textLayout(ctx, headline, copy, preferredPhotoHeight);
    canvas.height = layout.height;
    const palette = palettes[style];
    ctx.fillStyle = palette.paper;
    ctx.fillRect(0, 0, width, layout.height);
    paintPhoto(ctx, palette, {x: margin, y: margin, w: contentWidth, h: layout.photoHeight}, original, generated, product, level);

    let y = margin + layout.photoHeight + 26;
    ctx.fillStyle = palette.accent;
    ctx.fillRect(margin, y, 96, 6);
    y += 25;
    ctx.textBaseline = 'top';
    ctx.fillStyle = palette.ink;
    ctx.font = layout.titleFont;
    for (const line of layout.titleLines) {
      ctx.fillText(line, margin, y, contentWidth);
      y += layout.titleStep;
    }
    y += 14;
    ctx.fillStyle = palette.muted;
    ctx.font = layout.copyFont;
    for (const line of layout.copyLines) {
      ctx.fillText(line, margin, y, contentWidth);
      y += layout.copyStep;
    }
    const blob = await new Promise((resolve, reject) => {
      canvas.toBlob(result => result ? resolve(result) : reject(new Error('The browser could not create the promotion PNG.')), 'image/png');
    });
    if (signal?.aborted) throw new DOMException('Design cancelled.', 'AbortError');
    return {blob, width, height: layout.height};
  }

  async function validateSource({originalUrl, level, removeBackground = false, tolerance = 24, signal}) {
    if (!Number.isInteger(level) || level < 1 || level > 10
        || !Number.isInteger(tolerance) || tolerance < 8 || tolerance > 64) {
      throw new Error('Review the image treatment before designing the promotion.');
    }
    const address = ownedImageUrl(originalUrl);
    if (level >= 5 && level <= 7) productCutout(await readImage(address, signal), removeBackground === true, tolerance);
  }

  window.VP3MarketingCreativeRender = {render, validateSource};
})();
