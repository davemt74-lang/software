(() => {
  'use strict';

  const videoIdPattern = /^[A-Za-z0-9_-]{11}$/;
  const brandLogo = new URL('assets/youtube-logo-v1.png', document.currentScript?.src || document.baseURI).href;
  let activePlayer = null;

  function element(tag, className, text) {
    const node = document.createElement(tag);
    node.className = className;
    if (text) node.textContent = text;
    return node;
  }

  function stopPlayer(restoreFocus = false) {
    if (!activePlayer) return;
    const { panel, button } = activePlayer;
    activePlayer = null;
    panel.remove();
    button.setAttribute('aria-expanded', 'false');
    if (restoreFocus && button.isConnected) button.focus();
  }

  function thumbnailUrl(source, videoId) {
    if (typeof source !== 'string' || source.length > 256) return '';
    try {
      const url = new URL(source);
      if (!['https://i.ytimg.com', 'https://img.youtube.com'].includes(url.origin) ||
          url.username || url.password || url.search || url.hash) return '';
      const parts = url.pathname.split('/');
      return parts.length === 4 && ['vi', 'vi_webp'].includes(parts[1]) && parts[2] === videoId &&
        /^[A-Za-z0-9_]{1,32}\.(?:jpg|webp)$/.test(parts[3]) ? url.href : '';
    } catch {
      return '';
    }
  }

  function openLink(className, label, url) {
    const link = element('a', className, label);
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    return link;
  }

  function playVideo(row, button, videoId, title) {
    if (activePlayer?.button === button) return;
    stopPlayer();
    const panel = element('div', 'vp3-youtube-player');
    const frame = element('iframe', 'vp3-youtube-frame');
    frame.title = 'YouTube video · ' + title;
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
    frame.allowFullscreen = true;
    frame.src = 'https://www.youtube.com/embed/' + videoId + '?autoplay=1&playsinline=1';
    const close = element('button', 'vp3-youtube-close', 'Close player');
    close.type = 'button';
    close.addEventListener('click', () => stopPlayer(true));
    panel.append(frame, close);
    row.append(panel);
    button.setAttribute('aria-expanded', 'true');
    activePlayer = { panel, button };
  }

  function attach(message, result) {
    const messageText = message.querySelector('.message-text');
    if (!messageText || !Number.isSafeInteger(result?.expires_at) || result.expires_at <= Date.now() / 1000 ||
        !Array.isArray(result.videos)) return;

    const section = element('section', 'vp3-youtube-results');
    section.setAttribute('aria-label', 'YouTube search results');
    const header = element('header', 'vp3-youtube-heading');
    const logo = element('img', 'vp3-youtube-logo');
    logo.src = brandLogo;
    logo.alt = 'YouTube';
    const brand = openLink('vp3-youtube-brand', '', 'https://www.youtube.com/');
    brand.append(logo);
    const query = typeof result.query === 'string' ? result.query.slice(0, 200).trim() : '';
    header.append(brand, element('strong', 'vp3-youtube-query', query || 'Video results'));

    const list = element('ul', 'vp3-youtube-list');
    const seen = new Set();
    for (const video of result.videos.slice(0, 6)) {
      const videoId = video?.video_id;
      if (typeof videoId !== 'string' || !videoIdPattern.test(videoId) || seen.has(videoId)) continue;
      seen.add(videoId);
      const title = typeof video.title === 'string' ? video.title.slice(0, 200).trim() : '';
      const channel = typeof video.channel_title === 'string' ? video.channel_title.slice(0, 120).trim() : '';
      const row = element('li', 'vp3-youtube-result');
      const button = element('button', 'vp3-youtube-play');
      button.type = 'button';
      button.setAttribute('aria-label', 'Play in Chat · ' + (title || 'YouTube video'));
      button.setAttribute('aria-expanded', 'false');
      button.addEventListener('click', () => {
        if (result.expires_at <= Date.now() / 1000) {
          if (section.contains(activePlayer?.panel || null)) stopPlayer();
          section.replaceChildren(element('p', 'vp3-youtube-expired', 'These results expired. Search YouTube again.'));
          return;
        }
        playVideo(row, button, videoId, title || 'YouTube video');
      });
      const thumbnail = thumbnailUrl(video.thumbnail_url, videoId);
      if (thumbnail) {
        const image = element('img', 'vp3-youtube-thumbnail');
        image.src = thumbnail;
        image.alt = '';
        image.loading = 'lazy';
        image.referrerPolicy = 'no-referrer';
        image.addEventListener('error', () => image.remove(), { once: true });
        button.append(image);
      }
      const details = element('span', 'vp3-youtube-details');
      details.append(element('strong', 'vp3-youtube-title', title || 'YouTube video'));
      if (channel) details.append(element('span', 'vp3-youtube-channel', channel));
      details.append(element('span', 'vp3-youtube-play-label', 'Play in Chat'));
      button.append(details);
      const watch = openLink('vp3-youtube-watch', 'Open on YouTube', 'https://www.youtube.com/watch?v=' + videoId);
      row.append(button, watch);
      list.append(row);
    }
    if (!list.childElementCount) return;
    section.append(header, list);
    messageText.after(section);
  }

  window.addEventListener('stonefellow:chat-context-changing', () => stopPlayer());
  window.addEventListener('vp3:chat-canvas-view-changed', () => {
    if (document.getElementById('chatThread')?.dataset.view !== 'chat') stopPlayer();
  });
  window.addEventListener('pagehide', () => stopPlayer());
  window.VP3_CHAT_YOUTUBE_V1 = Object.freeze({ attach });
})();
