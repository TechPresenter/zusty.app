/* =====================================================================
   Zusty Admin — client runtime
   Vanilla JavaScript. No framework, no build step, no external requests.
   ===================================================================== */
(function () {
  'use strict';

  const Admin = {};
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

  /* -------------------------------------------------------------------
     Toasts
     ------------------------------------------------------------------- */
  const ICONS = {
    success: '<path d="M20 6 9 17l-5-5"/>',
    error: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
    warning: '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>'
  };

  Admin.toast = function (message, type = 'info', timeout = 4500) {
    let host = document.querySelector('.toasts');
    if (!host) {
      host = document.createElement('div');
      host.className = 'toasts';
      host.setAttribute('role', 'status');
      host.setAttribute('aria-live', 'polite');
      document.body.appendChild(host);
    }

    const el = document.createElement('div');
    el.className = 'toast toast--' + type;
    el.innerHTML =
      '<svg class="ic toast__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round">' + (ICONS[type] || ICONS.info) + '</svg>' +
      '<div class="toast__body"></div>' +
      '<button class="btn btn--ghost btn--icon btn--sm" aria-label="Dismiss">' +
      '<svg class="ic ic--sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>';

    // textContent, never innerHTML: a message may contain user data.
    el.querySelector('.toast__body').textContent = message;

    const dismiss = () => {
      el.classList.add('toast--out');
      setTimeout(() => el.remove(), 240);
    };

    el.querySelector('button').addEventListener('click', dismiss);
    host.appendChild(el);

    if (timeout > 0) setTimeout(dismiss, timeout);

    return el;
  };

  /* -------------------------------------------------------------------
     Focus management for modal dialogs
     ------------------------------------------------------------------- */
  const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])'
  ].join(',');

  /**
   * Contain Tab inside `container` and hand focus back to whatever opened it.
   *
   * Anything asserting aria-modal="true" owes the keyboard both halves of the
   * ARIA modal pattern. Without containment, Tab walks straight out of the
   * dialog and into the page behind it — which is still fully live in the DOM —
   * so a keyboard user can reach the very control the confirmation guards.
   * Without restoration, focus is dropped on <body> at close and the next Tab
   * restarts from the top of the sidebar.
   *
   * Listens in the capture phase on document rather than on the container: a
   * click on non-focusable text inside the dialog leaves document.activeElement
   * on <body>, and a container-scoped listener would never see the next Tab.
   *
   * @param  {HTMLElement} container
   * @return {function} release — call it on every close path.
   */
  let dialogSeq = 0;

  Admin.trapFocus = function (container) {
    const returnTo = document.activeElement;

    const items = () => Array.from(container.querySelectorAll(FOCUSABLE))
      .filter((el) => el.offsetWidth > 0 || el.offsetHeight > 0 || el.getClientRects().length > 0);

    const onKey = (e) => {
      if (e.key !== 'Tab' || !container.isConnected) return;

      const list = items();
      if (!list.length) return;

      const first = list[0];
      const last = list[list.length - 1];

      // Focus sitting outside the dialog (a click on dead space, or a stray
      // programmatic move) — pull it back in rather than letting Tab leave.
      if (!container.contains(document.activeElement)) {
        e.preventDefault();
        (e.shiftKey ? last : first).focus();
        return;
      }

      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', onKey, true);

    return function release() {
      document.removeEventListener('keydown', onKey, true);

      if (returnTo && typeof returnTo.focus === 'function' && returnTo.isConnected) {
        returnTo.focus();
      }
    };
  };

  /* -------------------------------------------------------------------
     Confirmation dialog — returns a Promise<boolean>
     ------------------------------------------------------------------- */
  Admin.confirm = function (options) {
    const opts = Object.assign({
      title: 'Are you sure?',
      message: 'This action cannot be undone.',
      confirmLabel: 'Confirm',
      cancelLabel: 'Cancel',
      tone: 'danger'
    }, options || {});

    return new Promise((resolve) => {
      const backdrop = document.createElement('div');
      backdrop.className = 'dialog-backdrop';

      // Unique per dialog: two confirms can be open at once (a slow click on a
      // second trigger), and duplicate ids would point both at one heading.
      const titleId = 'dlg-title-' + (++dialogSeq);

      const toneVar = opts.tone === 'danger' ? 'var(--error)'
        : opts.tone === 'warning' ? 'var(--warning)' : 'var(--secondary)';

      // role/aria-modal live on the panel, not the full-viewport backdrop:
      // the backdrop is scenery, the dialog is the thing the user is in.
      backdrop.innerHTML =
        '<div class="dialog" role="dialog" aria-modal="true" aria-labelledby="' + titleId + '">' +
        '  <div class="dialog__head">' +
        '    <div class="dialog__icon" style="--tone:' + toneVar + '">' +
        '      <svg class="ic ic--lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
        '           stroke-linecap="round" stroke-linejoin="round">' +
        '        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>' +
        '        <path d="M12 9v4M12 17h.01"/></svg>' +
        '    </div>' +
        '    <h2 class="h2" id="' + titleId + '" data-title></h2>' +
        '  </div>' +
        '  <div class="dialog__body"><p class="muted" data-message></p></div>' +
        '  <div class="dialog__foot">' +
        '    <button class="btn btn--outline" data-cancel></button>' +
        '    <button class="btn btn--' + (opts.tone === 'danger' ? 'danger' : 'primary') + '" data-confirm></button>' +
        '  </div>' +
        '</div>';

      backdrop.querySelector('[data-title]').textContent = opts.title;
      backdrop.querySelector('[data-message]').textContent = opts.message;
      backdrop.querySelector('[data-cancel]').textContent = opts.cancelLabel;
      backdrop.querySelector('[data-confirm]').textContent = opts.confirmLabel;

      let release = null;

      const close = (result) => {
        document.removeEventListener('keydown', onKey);
        backdrop.remove();
        // After remove(), so the trigger is focused against a page that no
        // longer contains the dialog.
        if (release) { release(); release = null; }
        resolve(result);
      };

      const onKey = (e) => { if (e.key === 'Escape') close(false); };

      backdrop.querySelector('[data-cancel]').addEventListener('click', () => close(false));
      backdrop.querySelector('[data-confirm]').addEventListener('click', () => close(true));
      backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(false); });
      document.addEventListener('keydown', onKey);

      document.body.appendChild(backdrop);

      // Trap before moving focus, so the element that opened the dialog is what
      // gets recorded as the return target.
      release = Admin.trapFocus(backdrop);
      backdrop.querySelector('[data-confirm]').focus();
    });
  };

  /* -------------------------------------------------------------------
     AJAX helper — always sends the CSRF token
     ------------------------------------------------------------------- */
  Admin.request = async function (url, options = {}) {
    const config = Object.assign({
      method: 'GET',
      headers: {},
      credentials: 'same-origin'
    }, options);

    config.headers = Object.assign({
      'X-Requested-With': 'XMLHttpRequest',
      'Accept': 'application/json',
      'X-CSRF-Token': csrfToken
    }, config.headers);

    if (config.body && !(config.body instanceof FormData) && typeof config.body === 'object') {
      config.headers['Content-Type'] = 'application/json';
      config.body = JSON.stringify(config.body);
    }

    if (config.body instanceof FormData && csrfToken) {
      config.body.append('_csrf', csrfToken);
    }

    const response = await fetch(url, config);
    let payload = null;

    try {
      payload = await response.json();
    } catch (e) {
      payload = { success: false, message: 'The server returned an unexpected response.' };
    }

    if (!response.ok) {
      const error = new Error(payload.message || 'Request failed (' + response.status + ')');
      error.status = response.status;
      error.errors = payload.errors || {};
      error.payload = payload;
      throw error;
    }

    return payload;
  };

  /* -------------------------------------------------------------------
     Form submission with inline validation feedback
     ------------------------------------------------------------------- */
  Admin.submitForm = async function (form, onSuccess) {
    const submitButton = form.querySelector('[type="submit"]');

    form.querySelectorAll('.field__error').forEach((el) => el.remove());
    form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));

    if (submitButton) submitButton.classList.add('btn--loading');

    try {
      const result = await Admin.request(form.action, { method: form.method || 'POST', body: new FormData(form) });

      Admin.toast(result.message || 'Saved.', 'success');
      if (typeof onSuccess === 'function') onSuccess(result);

      return result;
    } catch (error) {
      if (error.errors && Object.keys(error.errors).length) {
        Object.entries(error.errors).forEach(([field, messages]) => {
          const input = form.querySelector('[name="' + field + '"]');
          if (!input) return;

          input.setAttribute('aria-invalid', 'true');

          const note = document.createElement('p');
          note.className = 'field__error';
          note.textContent = Array.isArray(messages) ? messages[0] : messages;
          (input.closest('.field') || input.parentElement).appendChild(note);
        });

        const firstInvalid = form.querySelector('[aria-invalid="true"]');
        if (firstInvalid) firstInvalid.focus();
      }

      Admin.toast(error.message, 'error');
      throw error;
    } finally {
      if (submitButton) submitButton.classList.remove('btn--loading');
    }
  };

  /* -------------------------------------------------------------------
     Sidebar, theme, and other shell behaviour
     ------------------------------------------------------------------- */
  function initShell() {
    const app = document.querySelector('.app');
    if (!app) return;

    // Collapsible sidebar, remembered across sessions.
    if (localStorage.getItem('zuste.sidebar') === 'collapsed') {
      app.setAttribute('data-collapsed', 'true');
    }

    document.querySelectorAll('[data-toggle-sidebar]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (window.innerWidth <= 1024) {
          const open = app.getAttribute('data-mobile-open') === 'true';
          app.setAttribute('data-mobile-open', open ? 'false' : 'true');
          return;
        }

        const collapsed = app.getAttribute('data-collapsed') === 'true';
        app.setAttribute('data-collapsed', collapsed ? 'false' : 'true');
        localStorage.setItem('zuste.sidebar', collapsed ? 'expanded' : 'collapsed');
      });
    });

    const scrim = document.querySelector('.scrim');
    if (scrim) scrim.addEventListener('click', () => app.setAttribute('data-mobile-open', 'false'));

    // Theme toggle.
    const stored = localStorage.getItem('zuste.theme');
    if (stored === 'dark' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
      document.documentElement.setAttribute('data-theme', 'dark');
    }

    document.querySelectorAll('[data-toggle-theme]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        document.documentElement.setAttribute('data-theme', isDark ? 'light' : 'dark');
        localStorage.setItem('zuste.theme', isDark ? 'light' : 'dark');
      });
    });
  }

  /* -------------------------------------------------------------------
     Declarative behaviours (data-* attributes)
     ------------------------------------------------------------------- */
  function initBehaviours() {
    // data-confirm on a link or form: intercept and ask first.
    document.addEventListener('click', async (e) => {
      const trigger = e.target.closest('[data-confirm]');
      if (!trigger) return;

      // The dialog Admin.confirm() builds gives its own OK button that same
      // attribute, and the click bubbles back here — which opened a second,
      // empty "Are you sure?" on top of every confirmation in the panel.
      // A real trigger always carries a message; the dialog's own button
      // carries an empty one. media/index.php already stops this click at
      // <body> for the media library alone; guarding here fixes it everywhere.
      if (trigger.dataset.confirm === '' && trigger.closest('.dialog-backdrop')) return;

      e.preventDefault();

      const ok = await Admin.confirm({
        title: trigger.dataset.confirmTitle || 'Are you sure?',
        message: trigger.dataset.confirm,
        confirmLabel: trigger.dataset.confirmLabel || 'Confirm',
        tone: trigger.dataset.confirmTone || 'danger'
      });

      if (!ok) return;

      if (trigger.tagName === 'FORM') { trigger.submit(); return; }

      const form = trigger.closest('form');
      if (form && trigger.type === 'submit') { form.submit(); return; }

      if (trigger.href) window.location.href = trigger.href;
    });

    // Debounced auto-submitting filters.
    document.querySelectorAll('[data-auto-submit]').forEach((input) => {
      let timer = null;
      const delay = parseInt(input.dataset.autoSubmit, 10) || 450;

      const submit = () => input.form && input.form.submit();

      input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', () => {
        clearTimeout(timer);
        timer = setTimeout(submit, input.tagName === 'SELECT' ? 0 : delay);
      });
    });

    // Bulk selection.
    const selectAll = document.querySelector('[data-select-all]');
    if (selectAll) {
      const rowBoxes = () => Array.from(document.querySelectorAll('[data-select-row]'));

      const sync = () => {
        const boxes = rowBoxes();
        const checked = boxes.filter((b) => b.checked);
        const bar = document.querySelector('[data-bulk-bar]');
        const counter = document.querySelector('[data-bulk-count]');

        selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;

        if (bar) bar.classList.toggle('hidden', checked.length === 0);
        if (counter) counter.textContent = String(checked.length);
      };

      selectAll.addEventListener('change', () => {
        rowBoxes().forEach((b) => { b.checked = selectAll.checked; });
        sync();
      });

      rowBoxes().forEach((b) => b.addEventListener('change', sync));
      sync();
    }

    // Async forms.
    document.querySelectorAll('form[data-ajax]').forEach((form) => {
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        Admin.submitForm(form, () => {
          if (form.dataset.redirect) window.location.href = form.dataset.redirect;
          else if (form.dataset.reload !== 'false') setTimeout(() => window.location.reload(), 700);
        }).catch(() => {});
      });
    });

    // Copy to clipboard.
    document.querySelectorAll('[data-copy]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(btn.dataset.copy);
          Admin.toast('Copied to clipboard.', 'success', 2000);
        } catch (e) {
          Admin.toast('Could not copy — your browser blocked clipboard access.', 'error');
        }
      });
    });

    // Password visibility.
    document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const input = document.querySelector(btn.dataset.togglePassword);
        if (!input) return;

        const revealed = input.type === 'text';
        input.type = revealed ? 'password' : 'text';
        btn.setAttribute('aria-label', revealed ? 'Show password' : 'Hide password');
      });
    });

    // Tab panels.
    document.querySelectorAll('[data-tabs]').forEach((group) => {
      const tabs = Array.from(group.querySelectorAll('[data-tab]'));

      tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
          tabs.forEach((t) => {
            t.classList.toggle('chip-filter--active', t === tab);
            const panel = document.querySelector(t.dataset.tab);
            if (panel) panel.classList.toggle('hidden', t !== tab);
          });
        });
      });
    });
  }

  /* -------------------------------------------------------------------
     Charts — plain SVG, no library
     ------------------------------------------------------------------- */
  Admin.lineChart = function (container, series, options = {}) {
    const opts = Object.assign({ height: 240, padding: 28, showArea: true, format: (v) => v }, options);
    const values = series.map((p) => Number(p.value) || 0);

    if (!values.length) {
      container.innerHTML = '<p class="muted text-center p-6">No data for this period.</p>';
      return;
    }

    const width = container.clientWidth || 640;
    const height = opts.height;
    const pad = opts.padding;
    const max = Math.max(...values, 1);
    const min = Math.min(...values, 0);
    const range = max - min || 1;

    const x = (i) => pad + (i * (width - pad * 2)) / Math.max(series.length - 1, 1);
    const y = (v) => height - pad - ((v - min) / range) * (height - pad * 2);

    const line = values.map((v, i) => (i === 0 ? 'M' : 'L') + x(i).toFixed(1) + ' ' + y(v).toFixed(1)).join(' ');
    const area = line + ' L' + x(values.length - 1).toFixed(1) + ' ' + (height - pad) + ' L' + pad + ' ' + (height - pad) + ' Z';

    const gridLines = [0, 0.25, 0.5, 0.75, 1]
      .map((t) => {
        const gy = pad + t * (height - pad * 2);
        return '<line class="chart__grid" x1="' + pad + '" y1="' + gy + '" x2="' + (width - pad) + '" y2="' + gy + '"/>';
      })
      .join('');

    const step = Math.max(1, Math.ceil(series.length / 7));
    const labels = series
      .map((p, i) => (i % step === 0
        ? '<text class="chart__axis" x="' + x(i) + '" y="' + (height - 6) + '" text-anchor="middle">' + p.label + '</text>'
        : ''))
      .join('');

    const dots = values
      .map((v, i) => (i % step === 0 ? '<circle class="chart__dot" cx="' + x(i) + '" cy="' + y(v) + '" r="3.5"/>' : ''))
      .join('');

    container.innerHTML =
      '<svg class="chart" viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="none">' +
      '<defs><linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">' +
      '<stop offset="0%" stop-color="var(--primary)" stop-opacity=".55"/>' +
      '<stop offset="100%" stop-color="var(--primary)" stop-opacity="0"/>' +
      '</linearGradient></defs>' +
      gridLines +
      (opts.showArea ? '<path class="chart__area" d="' + area + '"/>' : '') +
      '<path class="chart__line" d="' + line + '"/>' +
      dots + labels +
      '</svg>';
  };

  Admin.barChart = function (container, series) {
    const values = series.map((p) => Number(p.value) || 0);
    const max = Math.max(...values, 1);

    container.innerHTML =
      '<div class="bars">' +
      series
        .map((p, i) => {
          const pct = Math.max(2, (Number(p.value) / max) * 100);
          return '<div class="bars__col" title="' + p.label + ': ' + p.value + '">' +
            '<div class="bars__bar" style="height:' + pct + '%;animation-delay:' + i * 45 + 'ms"></div>' +
            '<span class="bars__label">' + p.label + '</span></div>';
        })
        .join('') +
      '</div>';
  };

  /* -------------------------------------------------------------------
     Boot
     ------------------------------------------------------------------- */
  function boot() {
    initShell();
    initBehaviours();

    // Render any chart declared inline by a view.
    document.querySelectorAll('[data-chart]').forEach((el) => {
      let data;
      try {
        data = JSON.parse(el.dataset.chart);
      } catch (e) {
        return;
      }

      if (el.dataset.chartType === 'bar') Admin.barChart(el, data);
      else Admin.lineChart(el, data);
    });

    // Server-side flash messages.
    document.querySelectorAll('[data-flash]').forEach((el) => {
      Admin.toast(el.dataset.flash, el.dataset.flashType || 'info');
      el.remove();
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  // Re-render responsive charts on resize.
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      document.querySelectorAll('[data-chart]:not([data-chart-type="bar"])').forEach((el) => {
        try {
          Admin.lineChart(el, JSON.parse(el.dataset.chart));
        } catch (e) { /* ignore malformed payloads */ }
      });
    }, 180);
  });

  window.Admin = Admin;
})();
