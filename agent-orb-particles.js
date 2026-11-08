/* Particle face adapted from Stoneward's Admin Agent at 97072035fabd,
 * admin/assets/agent-particle-orb.js. VP3 retains its own Agent controls. */
(() => {
  'use strict';

  function boot() {
    const root = document.querySelector('.vp3-orb-root');
    const canvas = root?.querySelector('.vp3-orb-points');
    const context = canvas?.getContext?.('2d');
    if (!context) return;

    const motion = matchMedia('(prefers-reduced-motion: reduce)');
    const eyes = [-.255, .255];
    const count = 560;
    const dots = Array.from({length: count}, (_, index) => {
      const angle = index * 2.399963229728653;
      const radius = Math.sqrt((index + .5) / count) * .98;
      return {x: Math.cos(angle) * radius, y: Math.sin(angle) * radius,
        radius, depth: Math.sqrt(1 - radius * radius), seed: index * .713};
    });
    const palette = Object.fromEntries(Object.entries({
      idle: [167, 181, 251], listening: [112, 215, 255], thinking: [130, 240, 175],
      speaking: [160, 218, 255], recording: [255, 125, 156],
      connecting: [167, 181, 251], error: [255, 203, 142]
    }).map(([name, rgb]) => [name, Array.from({length: 12}, (_, index) => {
      const shade = .24 + index * .075;
      return `rgb(${rgb.map(channel => Math.round(channel * shade)).join(',')})`;
    })]));

    let frame = 0;
    let lastDraw = -Infinity;
    let activePage = true;
    let pointer = null;
    let lookX = 0;
    let lookY = 0;

    function dot(x, y, radius, color) {
      context.fillStyle = color;
      context.beginPath();
      context.arc(x, y, radius, 0, Math.PI * 2);
      context.fill();
    }

    function paint(now) {
      const width = canvas.clientWidth;
      const height = canvas.clientHeight;
      if (!width || !height) return;

      const density = Math.min(devicePixelRatio || 1, 2);
      const pixelsWide = Math.round(width * density);
      const pixelsHigh = Math.round(height * density);
      if (canvas.width !== pixelsWide || canvas.height !== pixelsHigh) {
        canvas.width = pixelsWide;
        canvas.height = pixelsHigh;
      }
      context.setTransform(density, 0, 0, density, 0, 0);
      context.clearRect(0, 0, width, height);
      const centerX = width / 2;
      const centerY = height / 2;
      const radius = Math.min(centerX, centerY) * .97;
      if (pointer && !motion.matches) {
        const bounds = canvas.getBoundingClientRect();
        const dx = pointer.x - bounds.left - bounds.width / 2;
        const dy = pointer.y - bounds.top - bounds.height / 2;
        const distance = Math.max(100, Math.hypot(dx, dy));
        lookX += (dx / distance * .08 - lookX) * .18;
        lookY += (dy / distance * .07 - lookY) * .18;
      } else {
        lookX = 0;
        lookY = 0;
      }

      const mode = root.dataset.recording === 'true' ? 'recording' : root.dataset.state;
      const colors = palette[mode] || palette.idle;
      const time = motion.matches ? 0 : now / 1000;
      for (const point of dots) {
        const rotation = mode === 'thinking' ? Math.sin(time * 2 + point.radius * 6) * .06
          : Math.sin(time * .5 + point.radius * 4) * .009;
        const cosine = Math.cos(rotation);
        const sine = Math.sin(rotation);
        const pulse = mode === 'speaking' ? 1 + Math.sin(time * 13 + point.seed * .08) * .018 : 1;
        const x = (point.x * cosine - point.y * sine) * pulse;
        const y = (point.x * sine + point.y * cosine) * pulse;
        const light = Math.max(0, Math.min(1, .38 + point.depth * .52 - x * .19 - y * .23));
        let color = colors[Math.floor(light * 11)];
        let size = width / 56 * (point.radius > .88 ? .35 : .56);
        for (const eye of eyes) {
          const eyeX = point.x - eye;
          const eyeY = point.y - .025;
          if ((eyeX / .145) ** 2 + (eyeY / .205) ** 2 < 1.27) {
            color = '#d9ebff';
            size *= 1.1;
          }
          if (((eyeX - lookX) / .100) ** 2 + ((eyeY - lookY) / .16) ** 2 < 1) {
            color = '#080e25';
            size *= 1.35;
          }
        }
        const mouth = .405 + .037 * (1 - (point.x / .18) ** 2);
        if (Math.abs(point.x) < .17 && Math.abs(point.y - mouth) < (mode === 'speaking' ? .07 : .024)) {
          color = '#090f2c';
          size *= 1.18;
        }
        dot(centerX + x * radius, centerY + y * radius, Math.max(.25, size), color);
      }
      for (const eye of eyes) {
        dot(centerX + (eye + lookX + .025) * radius, centerY + (lookY - .04) * radius,
          Math.max(.5, width * .012), '#e8fbff');
      }
    }

    function stop() {
      cancelAnimationFrame(frame);
      frame = 0;
    }

    function tick(now) {
      frame = 0;
      if (!activePage || document.hidden || motion.matches) return;
      if (now - lastDraw >= 32) {
        lastDraw = now;
        paint(now);
      }
      frame = requestAnimationFrame(tick);
    }

    function sync() {
      if (!activePage || document.hidden) stop();
      else if (motion.matches) {
        stop();
        paint(0);
      } else if (!frame) {
        lastDraw = -Infinity;
        frame = requestAnimationFrame(tick);
      }
    }

    document.addEventListener('pointermove', event => {
      if (!motion.matches) pointer = {x: event.clientX, y: event.clientY};
    }, {passive: true});
    document.addEventListener('pointerleave', () => { pointer = null; });
    document.addEventListener('visibilitychange', sync);
    motion.addEventListener('change', sync);
    new MutationObserver(sync).observe(root, {attributes: true, attributeFilter: ['data-state', 'data-recording']});
    if (typeof ResizeObserver === 'function') new ResizeObserver(sync).observe(canvas);
    window.addEventListener('pagehide', () => { activePage = false; stop(); });
    window.addEventListener('pageshow', () => { activePage = true; sync(); });
    sync();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once: true});
  else boot();
})();
