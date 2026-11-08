(() => {
  'use strict';

  const panel = document.querySelector('[data-gmail-popup]');
  if (!panel) return;

  const start = panel.querySelector('[data-gmail-popup-start]');
  const status = panel.querySelector('[data-gmail-popup-status]');
  const error = panel.querySelector('[data-gmail-popup-error]');
  const { clientId, origin, scope, attempt, csrfToken, endpoint, returnUrl } = panel.dataset;
  let exchangeUrl;
  let destination;

  function showError(message) {
    status.textContent = '';
    error.textContent = message;
    error.hidden = false;
  }

  try {
    exchangeUrl = new URL(endpoint, window.location.href);
    destination = new URL(returnUrl, window.location.href);
  } catch {
    showError('This Gmail connection page is unavailable. Return to Connections to try again.');
    return;
  }
  if (window.location.origin !== origin || exchangeUrl.origin !== origin || destination.origin !== origin ||
      exchangeUrl.search || exchangeUrl.hash || !clientId || !scope || !attempt || !csrfToken) {
    showError('Open VP3 at its configured Gmail site address, then start again from Connections.');
    return;
  }

  const oauth = window.google?.accounts?.oauth2;
  if (typeof oauth?.initCodeClient !== 'function') {
    showError('Google authorization could not load. Return to Connections and try again.');
    return;
  }

  const failures = new Map([
    ['invalid', 'This connection request expired or changed. Return to Connections to start again.'],
    ['unavailable', 'Gmail connection setup is unavailable. Return to Connections to check its status.'],
    ['conflict', 'The Gmail connection changed. Return to Connections to review its current status.'],
    ['failed', 'VP3 could not confirm the Gmail connection. Check its status in Connections before trying again.'],
    ['offline', 'Google did not issue offline access. If you previously approved this Gmail app, remove its access in your Google Account and start again from Connections.'],
    ['denied', 'Gmail needs the requested read and send permissions. Start again from Connections to grant access.'],
  ]);

  let popupPending = false;
  let finishing = false;
  let launch = 0;

  async function finish(code) {
    status.textContent = 'Finishing your Gmail connection…';
    try {
      const response = await fetch(exchangeUrl, {
        method: 'POST',
        mode: 'same-origin',
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'error',
        headers: { 'X-Requested-With': 'XmlHttpRequest' },
        body: new URLSearchParams({ action: 'finish', attempt, csrf_token: csrfToken, code }),
      });
      if (response.status === 406) {
        showError('The hosting firewall blocked this connection request (HTTP 406). Check with your hosting provider.');
        return;
      }
      const { result } = await response.json();
      if (response.ok && result === 'connected') {
        destination.searchParams.set('result', 'connected');
        window.location.replace(destination.href);
        return;
      }
      showError(failures.get(result) ?? failures.get('failed'));
    } catch {
      showError(failures.get('failed'));
    }
  }

  start.disabled = false;
  start.addEventListener('click', () => {
    if (popupPending || finishing) return;

    const currentLaunch = ++launch;
    popupPending = true;
    start.disabled = true;
    error.hidden = true;
    status.textContent = 'Continue in the Google window.';

    const isCurrent = () => popupPending && currentLaunch === launch;
    const retry = (message) => {
      if (!isCurrent()) return;
      popupPending = false;
      start.disabled = false;
      showError(message);
      start.focus();
    };
    const popupFailed = () => retry('The Google window could not open. Allow popups for this site and try again.');

    try {
      const client = oauth.initCodeClient({
        client_id: clientId,
        scope,
        ux_mode: 'popup',
        include_granted_scopes: false,
        select_account: true,
        callback: (response) => {
          if (!isCurrent()) return;
          const code = response?.code;
          if (['redirect_uri_mismatch', 'origin_mismatch', 'invalid_client', 'unauthorized_client'].includes(response?.error)) {
            retry('Google rejected the site setup. Check the OAuth client and its Authorized JavaScript origins.');
            return;
          }
          if (response?.error || typeof code !== 'string' || !code || code.length > 2048 || /[^!-~]/.test(code)) {
            retry('Google did not grant access. You can try again.');
            return;
          }
          popupPending = false;
          finishing = true;
          void finish(code);
        },
        error_callback: (failure) => {
          if (failure?.type === 'popup_closed') {
            retry('The Google window closed. You can try again.');
          } else if (failure?.type === 'popup_failed_to_open') {
            popupFailed();
          } else {
            retry('The Google window did not finish authorization. Try again.');
          }
        },
      });
      client.requestCode();
    } catch {
      retry('Google could not start authorization. Check the connection setup and try again.');
    }
  });
})();
