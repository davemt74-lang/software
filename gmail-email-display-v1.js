(() => {
  'use strict';
  if (window.VP3GmailEmailDisplayV1) return;
  const mounted = new Set();
  const node = (tag, text, className) => {
    const result = document.createElement(tag);
    if (text !== undefined) result.textContent = text;
    if (className) result.className = className;
    return result;
  };
  const safeLink = value => {
    if (typeof value !== 'string' || value.length > 4096 || /[\u0000-\u0020\u007f\\]/u.test(value)) return null;
    try {
      const url = new URL(value);
      return ['https:', 'http:', 'mailto:'].includes(url.protocol) && !url.username && !url.password ? url : null;
    } catch (_) { return null; }
  };
  const remoteImage = value => {
    const url = safeLink(value);
    if (!url || !['http:', 'https:'].includes(url.protocol) || url.origin === location.origin) return null;
    const host = url.hostname.toLowerCase().replace(/^\[|\]$/g, '');
    if (!host || /^(?:localhost|0\.0\.0\.0|127\.|10\.|192\.168\.|169\.254\.|172\.(?:1[6-9]|2\d|3[01])\.|::1$|f[cd][\da-f]{2}:|fe80:)/i.test(host)
        || /\.(?:local|localhost)$/.test(host) || !host.includes('.') && !host.includes(':')) return null;
    return url.href;
  };
  const cleanCss = value => {
    if (value.length > 65536 || /[<\\\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/u.test(value)) return '';
    return value.replace(/\/\*[\s\S]*?\*\//g, '')
      .replace(/@(?:import|namespace|charset)\b[^;{}]*(?:;|$)|@font-face\s*\{[^{}]*\}/gi, '')
      .replace(/(?:url|src|attr|image|(?:-webkit-)?image-set|(?:-webkit-)?cross-fade)\s*\([^;{}]*\)/gi, 'none')
      .replace(/(?:behavior|-moz-binding)\s*:[^;{}]*(?:;|$)|[^;{}]*expression\s*\([^;{}]*(?:;|$)/gi, '');
  };

  function frameDocument(html, loadImages) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('script,noscript,template,form,input,button,textarea,select,iframe,frame,frameset,object,embed,applet,link,meta,base,svg,math,video,audio,source,track,canvas').forEach(el => el.remove());
    for (const el of doc.querySelectorAll('*')) {
      for (const attr of [...el.attributes]) {
        if (/^on/i.test(attr.name) || ['srcdoc','srcset','ping','formaction','nonce','integrity','background'].includes(attr.name)) el.removeAttribute(attr.name);
      }
      if (el.hasAttribute('style')) el.setAttribute('style', cleanCss(el.getAttribute('style')));
      if (el.tagName === 'STYLE') el.textContent = cleanCss(el.textContent);
      if (el.tagName === 'A') {
        const url = safeLink(el.getAttribute('href'));
        if (url) el.setAttribute('href', url.href); else el.removeAttribute('href');
        el.setAttribute('target', '_blank');
        el.setAttribute('rel', 'noopener noreferrer');
        el.setAttribute('referrerpolicy', 'no-referrer');
      }
      if (el.tagName === 'IMG') {
        const original = el.getAttribute('src') || '';
        const remote = remoteImage(el.getAttribute('data-vp3-remote-src'));
        el.removeAttribute('src');
        if (/^data:image\/(?:png|jpeg|gif|webp);base64,[a-zA-Z0-9+/=]+$/.test(original) && original.length <= 2800000) el.setAttribute('src', original);
        else if (loadImages && remote) el.setAttribute('src', remote);
        el.removeAttribute('data-vp3-remote-src');
        el.setAttribute('referrerpolicy', 'no-referrer');
      } else el.removeAttribute('src');
    }
    const charset = doc.createElement('meta');
    charset.setAttribute('charset', 'UTF-8');
    const policy = doc.createElement('meta');
    policy.setAttribute('http-equiv', 'Content-Security-Policy');
    policy.setAttribute('content', `default-src 'none'; script-src 'none'; style-src 'unsafe-inline'; img-src data:${loadImages ? ' https: http:' : ''}; font-src 'none'; connect-src 'none'; frame-src 'none'; object-src 'none'; media-src 'none'; base-uri 'none'; form-action 'none';`);
    const viewport = doc.createElement('meta');
    viewport.name = 'viewport';
    viewport.content = 'width=device-width,initial-scale=1';
    const reset = doc.createElement('style');
    reset.textContent = 'html{width:100%;min-width:0!important;overflow:hidden!important}body{margin:0;min-width:0!important;max-width:100%!important;overflow-wrap:anywhere;overflow:hidden!important}img{max-width:100%!important;height:auto}table{max-width:100%!important}pre{white-space:pre-wrap!important;overflow-wrap:anywhere}td,th{overflow-wrap:anywhere}';
    doc.head.prepend(policy, charset, viewport);
    doc.head.append(reset);
    return '<!doctype html>\n' + doc.documentElement.outerHTML;
  }

  function mount(parent, message, options = {}) {
    const display = message?.display ?? (typeof message?.body === 'string' ? {version:1,html:'',remote_images:0} : null);
    if (!parent || display?.version !== 1 || typeof display.html !== 'string' || display.html.length > 350000) return false;
    const root = node('section', undefined, 'vp3-gmail-email-display');
    const toolbar = node('div', undefined, 'vp3-gmail-email-toolbar');
    toolbar.setAttribute('role', 'group');
    toolbar.setAttribute('aria-label', 'Email display');
    const content = node('div', undefined, 'vp3-gmail-email-content');
    const status = node('span', '', 'vp3-gmail-email-display-status');
    status.setAttribute('role', 'status');
    let formatted = Boolean(display.html);
    let images = false;
    let observer = null;
    let frame = null;
    let disposed = false;
    let capture = null;
    let captureFactory = null;
    const plain = node('div', undefined, 'vp3-gmail-email-plain');
    if (typeof options.renderPlain === 'function') options.renderPlain(plain);
    else plain.append(node('p', String(message.body || 'No readable text in this email.')));
    const makeButton = (label, handler) => {
      const button = node('button', label);
      button.type = 'button';
      button.addEventListener('click', handler);
      return button;
    };
    const htmlButton = makeButton('Formatted', () => { formatted = true; render(); });
    const textButton = makeButton('Plain text', () => { formatted = false; render(); });
    const imageButton = makeButton('Load remote images', event => {
      if (!event.isTrusted || disposed || !root.isConnected || images) return;
      images = true;
      render();
    });
    imageButton.title = 'Loads images from the sender’s servers for this email.';
    htmlButton.disabled = !display.html;
    toolbar.append(htmlButton, textButton, imageButton, status);
    root.append(toolbar, content);
    parent.append(root);
    const entry = {root, refreshCapture: () => {
      if (!disposed && root.getClientRects().length && captureFactory && !capture?.accessRevoked && !capture?.current?.()) {capture?.destroy();capture=captureFactory();}
    }, destroy: () => { disposed = true; captureFactory=null;capture?.destroy(); observer?.disconnect(); frame?.remove(); root.remove(); mounted.delete(entry); }};
    mounted.add(entry);

    function render() {
      if (disposed) return;
      capture?.destroy();capture = null;
      captureFactory = null;
      observer?.disconnect();
      observer = null;
      frame?.remove();
      frame = null;
      htmlButton.setAttribute('aria-pressed', String(formatted));
      textButton.setAttribute('aria-pressed', String(!formatted));
      imageButton.hidden = !formatted || !(display.remote_images > 0) || images;
      status.textContent = display.truncated ? 'Plain text view for this large email.' : formatted && display.remote_images > 0
        ? (images ? 'Remote images loaded.' : 'Remote images are hidden.') : '';
      const installCapture = (body, frame = null) => {
        capture?.destroy();
        captureFactory = () => window.VP3GmailEmailCaptureV1?.mount({root,toolbar,content,body,frame,message,
          source:options.captureSource ?? message.capture_source,isCurrent:() => !disposed && (options.isCurrent?.() ?? true)});
        capture = captureFactory();
      };
      if (!formatted) { content.replaceChildren(plain); installCapture(plain); return; }
      const currentFrame = node('iframe');
      currentFrame.className = 'vp3-gmail-email-frame';
      currentFrame.title = `Formatted email${message.subject ? ': ' + String(message.subject).slice(0,180) : ''}`;
      // The owner page can size the document and bind link receipts; email scripts remain disabled.
      currentFrame.setAttribute('sandbox', 'allow-same-origin allow-popups allow-popups-to-escape-sandbox');
      currentFrame.setAttribute('referrerpolicy', 'no-referrer');
      currentFrame.setAttribute('scrolling', 'no');
      currentFrame.style.height = '1px';
      currentFrame.addEventListener('load', () => {
        if (disposed || frame !== currentFrame || !root.isConnected) return;
        const doc = currentFrame.contentDocument;
        if (!doc?.body) return;
        const resize = () => {
          if (disposed || frame !== currentFrame || !root.isConnected) return;
          const height = Math.max(100, Math.ceil(Math.max(doc.body.scrollHeight, doc.body.getBoundingClientRect().height)) + 4);
          currentFrame.style.height = `${Math.min(height, 100000)}px`;
        };
        for (const anchor of doc.querySelectorAll('a[href]')) {
          const record = event => {
            if (!event.isTrusted || event.defaultPrevented || !root.isConnected || frame !== currentFrame
                || event.button !== (event.type === 'auxclick' ? 1 : 0)) return;
            const url = safeLink(anchor.getAttribute('href'));
            if (!url) { event.preventDefault(); return; }
            options.onLink?.(event, url.href, anchor.textContent);
          };
          anchor.addEventListener('click', record);
          anchor.addEventListener('auxclick', record);
        }
        if (typeof ResizeObserver === 'function') {
          observer?.disconnect();
          observer = new ResizeObserver(resize);
          observer.observe(doc.body);
        }
        for (const image of doc.images) image.addEventListener('load', resize, {once:true});
        resize();
        installCapture(doc.body,currentFrame);
      });
      frame = currentFrame;
      content.replaceChildren(currentFrame);
      currentFrame.srcdoc = frameDocument(display.html, images);
    }
    render();
    return true;
  }
  new MutationObserver(() => {
    for (const entry of mounted) if (!entry.root.isConnected) entry.destroy(); else entry.refreshCapture();
  }).observe(document.documentElement, {childList:true, subtree:true,attributes:true,attributeFilter:['hidden','open','data-view']});
  window.addEventListener('stonefellow:chat-context-changing', () => {
    for (const entry of mounted) entry.destroy();
  });
  window.VP3GmailEmailDisplayV1 = Object.freeze({mount});
})();
