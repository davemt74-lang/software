/* Local PNG capture is independent of VP3 account and publication state. */
(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  const panel = byId('localScreenshotPanel');
  if (!panel) return;
  const status = byId('localScreenshotStatus');
  const preview = byId('localScreenshotPreview');
  const image = byId('localScreenshotImage');
  const visible = byId('localCaptureVisible');
  const region = byId('localCaptureRegion');
  const save = byId('localScreenshotSave');
  const copy = byId('localScreenshotCopy');
  const clear = byId('localScreenshotClear');
  let screenshot = null;
  let busy = false;

  function showStatus(text, error = false) {
    status.textContent = text;
    status.dataset.error = error ? 'true' : 'false';
  }
  function setBusy(value) {
    busy = value;
    visible.disabled = region.disabled = clear.disabled = value;
    save.disabled = copy.disabled = value || !screenshot;
  }
  function clearImage() {
    if (screenshot) URL.revokeObjectURL(screenshot.url);
    screenshot = null;
    image.removeAttribute('src');
    preview.hidden = true;
    byId('localScreenshotSize').textContent = '';
  }
  async function acceptImage(dataUrl) {
    if (typeof dataUrl !== 'string' || !dataUrl.startsWith('data:image/png;base64,')) throw new Error('Chrome did not return a PNG screenshot.');
    const blob = await (await fetch(dataUrl)).blob();
    if (!blob.size || blob.size > 16 * 1024 * 1024) throw new Error('The screenshot is too large. Select a smaller region.');
    const bitmap = await createImageBitmap(blob);
    const dimensions = `${bitmap.width} × ${bitmap.height} px`;
    bitmap.close();
    const next = {blob, url: URL.createObjectURL(blob), filename: `VP3-Screenshot-${new Date().toISOString().replace(/[:.]/g, '-')}.png`};
    clearImage();
    screenshot = next;
    image.src = next.url;
    byId('localScreenshotSize').textContent = `${dimensions} · PNG`;
    preview.hidden = false;
    showStatus('Screenshot ready. Save the PNG or copy it to paste into chat.');
  }
  async function activePage() {
    const [tab] = await chrome.tabs.query({active: true, currentWindow: true});
    if (!tab?.id || !/^https?:\/\//i.test(tab.url || '')) throw new Error('Open HomeServer, then click the VP3 toolbar icon on that tab before capturing.');
    return tab;
  }
  async function capture(kind) {
    if (busy) return;
    setBusy(true);
    showStatus(kind === 'region' ? 'Drag a region on the page. Press Escape to cancel.' : 'Capturing the visible page…');
    try {
      const tab = await activePage();
      let dataUrl;
      if (kind === 'region') {
        const response = await chrome.runtime.sendMessage({type: 'capture_region'});
        if (!response?.ok) throw new Error(response?.error || 'The region could not be captured.');
        if (response.value?.cancelled) {showStatus('Capture cancelled.'); return;}
        dataUrl = response.value?.data_url;
      } else {
        dataUrl = await chrome.tabs.captureVisibleTab(tab.windowId, {format: 'png'});
      }
      const current = await activePage();
      if (current.id !== tab.id || current.url !== tab.url || current.windowId !== tab.windowId) throw new Error('The active page changed during capture. Return to HomeServer and try again.');
      await acceptImage(dataUrl);
    } catch (error) {
      const detail = String(error?.message || error);
      showStatus(/activeTab|permission|Cannot access/i.test(detail) ? 'Click the VP3 toolbar icon again on the HomeServer tab, then retry capture.' : detail, true);
    } finally {setBusy(false);}
  }
  visible.addEventListener('click', () => capture('visible'));
  region.addEventListener('click', () => capture('region'));
  save.addEventListener('click', async () => {
    if (busy || !screenshot) return;
    setBusy(true);
    try {
      await chrome.downloads.download({url: screenshot.url, filename: screenshot.filename, saveAs: true, conflictAction: 'uniquify'});
      showStatus('PNG sent to Chrome’s Save dialog. Choose where to save it.');
    } catch (error) {showStatus(`PNG could not be saved: ${error.message}`, true);}
    finally {setBusy(false);}
  });
  copy.addEventListener('click', async () => {
    if (busy || !screenshot) return;
    setBusy(true);
    try {
      await navigator.clipboard.write([new ClipboardItem({'image/png': screenshot.blob})]);
      showStatus('Image copied. Paste it into your chat with Ctrl+V.');
    } catch (_) {showStatus('Chrome could not copy the image. Use Save PNG, then attach the file to your chat.', true);}
    finally {setBusy(false);}
  });
  clear.addEventListener('click', () => {
    if (busy) return;
    clearImage(); setBusy(false); showStatus('Screenshot cleared.');
  });
  window.addEventListener('pagehide', clearImage);
  setBusy(false);
})();
