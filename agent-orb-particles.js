/* Public Ember orb geometry from Stoneward's assets/js/public-agent-canvas.js.
 * This renderer supplies visual layers only. The host owns actions and state. */
(() => {
  'use strict';

  const ember = '255,183,110';
  const tau = Math.PI * 2;
  const goldenAngle = 2.399963229728653;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  const mounted = new WeakSet();
  let pointer = null;
  const faceEyes = [-.255, .255];
  const faceDots = Array.from({length:560}, (_, index) => {
    const angle = index * goldenAngle;
    const radius = Math.sqrt((index + .5) / 560) * .98;
    return {x:Math.cos(angle) * radius, y:Math.sin(angle) * radius, radius,
      depth:Math.sqrt(1 - radius * radius), seed:index * .713};
  });
  const facePalette = Object.fromEntries(Object.entries({
    idle:[167,181,251], listening:[112,215,255], thinking:[130,240,175],
    speaking:[160,218,255], recording:[255,125,156],
    connecting:[167,181,251], error:[255,203,142]
  }).map(([name,rgb]) => [name, Array.from({length:12}, (_, index) => {
    const shade = .24 + index * .075;
    return 'rgb(' + rgb.map(channel => Math.round(channel * shade)).join(',') + ')';
  })]));

  function layer(tag, className) {
    const node = document.createElement(tag);
    node.className = className;
    node.setAttribute('aria-hidden', 'true');
    return node;
  }

  function fit(canvas, context, size, dpr) {
    const pixels = Math.max(1, Math.round(size * dpr));
    if (canvas.width === pixels && canvas.height === pixels) return;
    canvas.width = canvas.height = pixels;
    const scale = pixels / size;
    context.setTransform(scale, 0, 0, scale, 0, 0);
  }

  function spherePoints(count) {
    return Array.from({length:count}, (_, i) => {
      const y = 1 - 2 * (i + .5) / count;
      const r = Math.sqrt(Math.max(0, 1 - y * y));
      const angle = i * goldenAngle;
      return {x:Math.cos(angle) * r, y, z:Math.sin(angle) * r, seed:i * .913};
    });
  }

  function drawSphere(context, size, points, time, state) {
    const center = size * .5;
    const radius = size * .427;
    context.clearRect(0, 0, size, size);
    const glow = context.createRadialGradient(center, center, radius * .12, center, center, radius * 1.19);
    glow.addColorStop(0, 'rgba(255,183,110,.075)');
    glow.addColorStop(.56, 'rgba(255,183,110,.045)');
    glow.addColorStop(.86, 'rgba(255,183,110,.12)');
    glow.addColorStop(1, 'rgba(255,183,110,0)');
    context.fillStyle = glow;
    context.fillRect(0, 0, size, size);

    const busy = ['thinking','working','researching'].includes(state);
    const spin = time * (busy ? .72 : .19);
    const sin = Math.sin(spin), cos = Math.cos(spin);
    const tilt = .15, st = Math.sin(tilt), ct = Math.cos(tilt);
    const breath = 1 + .025 * Math.sin(time * (state === 'speaking' ? 5.2 : 1.35));
    const emphasis = state === 'speaking' ? .08 : state === 'listening' ? .04 : 0;
    const dotScale = Math.max(.64, size / 230);

    for (const point of points) {
      const x = point.x * cos - point.z * sin;
      const z = point.x * sin + point.z * cos;
      const y = point.y * ct - z * st;
      const depth = point.y * st + z * ct;
      const perspective = 1 / (1 - depth * .15);
      const front = (depth + 1) * .5;
      const shimmer = .5 + .5 * Math.sin(time * 2.1 + point.seed * 9);
      const alpha = Math.min(.99, .12 + front * .68 + shimmer * .18 + emphasis);
      const dot = (.45 + front * .93 + (shimmer > .98 ? .7 : 0)) * dotScale;
      const distance = radius * perspective * breath;
      context.fillStyle = 'rgba(' + ember + ',' + alpha.toFixed(3) + ')';
      context.fillRect(center + x * distance - dot / 2, center + y * distance - dot / 2, dot, dot);
    }

    context.lineWidth = Math.max(.55, size / 320);
    for (let i = 0; i < 3; i++) {
      const wobble = time * (.11 + i * .065);
      context.strokeStyle = 'rgba(' + ember + ',' + (.12 + i * .042) + ')';
      context.beginPath();
      context.ellipse(center, center, radius * (1.13 + i * .04), radius * (.33 + i * .055), wobble + i * .86, 0, tau);
      context.stroke();
    }
  }

  function drawFace(context, size, time, state, gaze) {
    const center = size / 2;
    const radius = center * .97;
    const colors = facePalette[state] || facePalette.idle;
    context.clearRect(0, 0, size, size);
    const dot = (x, y, r, color) => {
      context.fillStyle = color;
      context.beginPath();
      context.arc(x, y, r, 0, tau);
      context.fill();
    };
    for (const point of faceDots) {
      const rotation = state === 'thinking' ? Math.sin(time * 2 + point.radius * 6) * .06
        : Math.sin(time * .5 + point.radius * 4) * .009;
      const cosine = Math.cos(rotation), sine = Math.sin(rotation);
      const pulse = state === 'speaking' ? 1 + Math.sin(time * 13 + point.seed * .08) * .018 : 1;
      const x = (point.x * cosine - point.y * sine) * pulse;
      const y = (point.x * sine + point.y * cosine) * pulse;
      const light = Math.max(0, Math.min(1, .38 + point.depth * .52 - x * .19 - y * .23));
      let color = colors[Math.floor(light * 11)];
      let weight = size / 56 * (point.radius > .88 ? .35 : .56);
      for (const eye of faceEyes) {
        const eyeX = point.x - eye, eyeY = point.y - .025;
        if ((eyeX / .145) ** 2 + (eyeY / .205) ** 2 < 1.27) {
          color = '#d9ebff';
          weight *= 1.1;
        }
        if (((eyeX - gaze.x) / .1) ** 2 + ((eyeY - gaze.y) / .16) ** 2 < 1) {
          color = '#080e25';
          weight *= 1.35;
        }
      }
      const mouth = .405 + .037 * (1 - (point.x / .18) ** 2);
      if (Math.abs(point.x) < .17 && Math.abs(point.y - mouth) < (state === 'speaking' ? .07 : .024)) {
        color = '#090f2c';
        weight *= 1.18;
      }
      dot(center + x * radius, center + y * radius, Math.max(.25, weight), color);
    }
    for (const eye of faceEyes) {
      dot(center + (eye + gaze.x + .025) * radius, center + (gaze.y - .04) * radius,
        Math.max(.5, size * .012), '#e8fbff');
    }
  }

  function drawCloud(context, size, time, state) {
    const field = size * 2.1;
    const center = field / 2;
    const count = Math.min(110, Math.round(26 + size * .4));
    const speed = ['thinking','working','researching'].includes(state) ? 3 : 1;
    context.clearRect(0, 0, field, field);
    for (let i = 0; i < count; i++) {
      const phase = i * 1.37;
      const angle = i * goldenAngle + time * (.25 + (i % 6) * .08) * speed;
      const distance = size * (.51 + (i % 10) * .055 + Math.sin(time * .8 + phase) * .04);
      const x = center + Math.cos(angle) * distance;
      const y = center + Math.sin(angle) * distance * .72;
      const alpha = .43 + .24 * Math.sin(time * 1.7 + phase);
      context.fillStyle = 'rgba(' + ember + ',' + alpha.toFixed(3) + ')';
      context.beginPath();
      context.arc(x, y, Math.max(.55, size / 108) * (i % 4 === 0 ? 1.2 : .85), 0, tau);
      context.fill();
    }
  }

  function mount(host) {
    if (mounted.has(host) || host.dataset.vp3PublicOrbMounted) return;
    mounted.add(host);
    host.dataset.vp3PublicOrbMounted = 'true';
    const cloud = layer('canvas', 'vp3-public-orb-cloud');
    const points = layer('canvas', 'vp3-public-orb-points');
    const aura = layer('span', 'vp3-public-orb-aura');
    const rings = ['pulse', 'primary', 'secondary'].map(name => {
      const ring = layer('span', 'vp3-public-orb-ring');
      ring.dataset.orbRing = name;
      return ring;
    });
    host.append(aura, cloud, ...rings, points);
    const fieldContext = cloud.getContext('2d');
    const sphereContext = points.getContext('2d');
    if (!fieldContext || !sphereContext) return;

    const orbRoot = host.closest('.vp3-orb-root');
    const gaze = {x:0,y:0};
    let frame = 0, lastFrame = -Infinity, lastSize = 0, visible = true, samples = [];

    function paint(now) {
      const size = host.clientWidth;
      if (!size || !host.clientHeight) return;
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      fit(cloud, fieldContext, size * 2.1, dpr);
      fit(points, sphereContext, size, dpr);
      if (size !== lastSize) {
        lastSize = size;
        samples = spherePoints(Math.min(2900, Math.max(240, Math.round(2900 * (size / 210) ** 1.1))));
      }
      const state = orbRoot?.dataset.recording === 'true' ? 'recording' : (orbRoot?.dataset.state || 'idle');
      const time = reduced.matches ? 0 : now * .001;
      if (host.dataset.orbShape === 'particle_face') {
        if (pointer && !reduced.matches) {
          const rect = host.getBoundingClientRect();
          const x = pointer.x - rect.left - rect.width / 2, y = pointer.y - rect.top - rect.height / 2;
          const distance = Math.max(100, Math.hypot(x,y));
          gaze.x += (x / distance * .08 - gaze.x) * .18;
          gaze.y += (y / distance * .07 - gaze.y) * .18;
        } else {
          gaze.x = gaze.y = 0;
        }
        drawFace(sphereContext, size, time, state, gaze);
      } else {
        drawCloud(fieldContext, size, time, state);
        drawSphere(sphereContext, size, samples, time, state);
      }
      host.classList.add('vp3-public-orb-rendered');
    }

    function tick(now) {
      frame = 0;
      if (!host.isConnected || !visible || document.hidden || reduced.matches) return;
      if (now - lastFrame >= 32) {
        lastFrame = now;
        paint(now);
      }
      frame = requestAnimationFrame(tick);
    }

    function refresh() {
      cancelAnimationFrame(frame);
      frame = 0;
      if (!host.isConnected || !visible || document.hidden) return;
      paint(performance.now());
      if (!reduced.matches) frame = requestAnimationFrame(tick);
    }

    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver(entries => {
        visible = entries[0].isIntersecting;
        refresh();
      });
      observer.observe(host);
    }
    if ('ResizeObserver' in window) new ResizeObserver(refresh).observe(host);
    reduced.addEventListener?.('change', refresh);
    document.addEventListener('visibilitychange', refresh);
    const appearance = new MutationObserver(refresh);
    appearance.observe(host, {attributeFilter:['data-orb-shape']});
    if (orbRoot) appearance.observe(orbRoot, {attributeFilter:['data-state','data-recording']});
    refresh();
  }

  document.addEventListener('pointermove', event => { pointer = {x:event.clientX,y:event.clientY}; }, {passive:true});
  document.addEventListener('pointerleave', () => { pointer = null; });
  window.addEventListener('vp3:agent-orb-shape', event => {
    const shape = event.detail?.shape;
    if (shape !== 'orbit' && shape !== 'particle_face') return;
    for (const host of document.querySelectorAll('[data-vp3-public-orb][data-vp3-orb-personal]')) {
      if (host.dataset.orbShape !== shape) host.dataset.orbShape = shape;
    }
  });

  const mountVisibleOrbs = () => document.querySelectorAll('[data-vp3-public-orb]').forEach(mount);
  window.addEventListener('vp3:agent-orb-ready', mountVisibleOrbs);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountVisibleOrbs, {once:true});
  else mountVisibleOrbs();
})();
