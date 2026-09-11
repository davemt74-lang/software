(() => {
  'use strict';

  const config = window.VP3_PUBLIC_SCHEDULING || {};
  const endpoint = String(config.slotsEndpoint || '');
  const browserTimezone = (() => {
    try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; }
    catch (_) { return ''; }
  })();

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[char]));

  function slotLabel(iso, fallback) {
    try {
      const date = new Date(iso);
      if (Number.isNaN(date.getTime())) return fallback || '';
      return new Intl.DateTimeFormat(undefined, {hour:'numeric', minute:'2-digit'}).format(date);
    } catch (_) {
      return fallback || '';
    }
  }

  function setTimezone(form) {
    const field = form.querySelector('[data-guest-timezone]');
    if (field && browserTimezone) field.value = browserTimezone;
  }

  function setSelected(form, value, button) {
    const input = form.querySelector('[data-slot-input]');
    const submit = form.querySelector('[data-slot-submit]');
    if (input) input.value = value || '';
    form.querySelectorAll('[data-slot-value]').forEach((item) => item.classList.toggle('selected', item === button));
    if (submit) submit.disabled = !value;
  }

  function wireButtons(form) {
    form.querySelectorAll('[data-slot-value]').forEach((button) => {
      button.addEventListener('click', () => setSelected(form, String(button.dataset.slotValue || ''), button));
    });
  }

  async function loadSlots(form) {
    const list = form.querySelector('[data-slot-list]');
    const dateInput = form.querySelector('[data-booking-date]');
    if (!list || !dateInput || !endpoint) return;

    const username = String(form.dataset.username || '');
    const eventSlug = String(form.dataset.event || '');
    const date = String(dateInput.value || '');
    if (!username || !eventSlug || !date) return;

    setSelected(form, '', null);
    list.innerHTML = '<p class="booking-empty-slots">Checking live availability…</p>';

    const query = new URLSearchParams({username, event:eventSlug, date});
    if (browserTimezone) query.set('timezone', browserTimezone);

    try {
      const response = await fetch(`${endpoint}?${query.toString()}`, {
        credentials:'same-origin',
        headers:{'Accept':'application/json'},
        cache:'no-store',
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) throw new Error(payload?.error || 'Availability could not be loaded.');

      const slots = Array.isArray(payload.slots) ? payload.slots : [];
      if (!slots.length) {
        list.innerHTML = '<p class="booking-empty-slots">No open times on this date.</p>';
        return;
      }

      list.innerHTML = slots.map((slot) => {
        const value = String(slot.start_at_utc || '');
        const label = slotLabel(value, String(slot.label || ''));
        return `<button type="button" data-slot-value="${escapeHtml(value)}">${escapeHtml(label)}</button>`;
      }).join('');
      wireButtons(form);
    } catch (error) {
      list.innerHTML = `<p class="booking-empty-slots error">${escapeHtml(error?.message || 'Availability could not be loaded.')}</p>`;
    }
  }

  document.querySelectorAll('[data-slot-form]').forEach((form) => {
    setTimezone(form);
    wireButtons(form);
    const dateInput = form.querySelector('[data-booking-date]');
    dateInput?.addEventListener('change', () => loadSlots(form));

    // Refresh server-rendered times in the visitor's actual timezone on load.
    if (browserTimezone) loadSlots(form);

    form.addEventListener('submit', (event) => {
      const start = form.querySelector('[data-slot-input]');
      if (start && !start.value) {
        event.preventDefault();
        const list = form.querySelector('[data-slot-list]');
        list?.setAttribute('aria-live','assertive');
        if (list && !list.querySelector('.booking-select-error')) {
          const note = document.createElement('p');
          note.className = 'booking-select-error';
          note.textContent = 'Choose an available time first.';
          list.appendChild(note);
        }
      }
    });
  });
})();
