(() => {
  const status = document.getElementById('menuStatus');
  let busy = false;
  function failure(error) {status.textContent = String(error?.message || error);status.hidden = false;busy = false;document.querySelectorAll('button').forEach(button => {button.disabled = false;});}
  document.querySelectorAll('[data-capture]').forEach(button => button.addEventListener('click', async () => {
    if (busy) return;
    busy = true;document.querySelectorAll('button').forEach(button => {button.disabled = true;});
    try {
      const response = await chrome.runtime.sendMessage({type:'local_screenshot_start',mode:button.dataset.capture});
      if (!response?.ok) throw new Error(response?.error || 'Capture could not start.');
      window.close();
    } catch (error) {failure(error);}
  }));
  document.getElementById('openCompanion').addEventListener('click', async () => {
    try {
      const [tab] = await chrome.tabs.query({active:true,currentWindow:true});
      await chrome.sidePanel.open({tabId:tab.id});
      window.close();
    } catch (error) {failure(error);}
  });
})();
