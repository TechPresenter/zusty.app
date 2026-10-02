/* =====================================================================
   Zusty Admin — dashboard widget runtime
   Vanilla JavaScript. Depends only on admin.js (window.Admin).
   ===================================================================== */
(function () {
  'use strict';

  const Widgets = {};
  const timers = new Map();

  /* -------------------------------------------------------------------
     Formatting
     ------------------------------------------------------------------- */
  const CURRENCY = document.body.dataset.currencySymbol || '₹';

  /** Indian digit grouping (2,2,3): 1234567 -> 12,34,567 */
  function groupIndian(value, decimals) {
    const negative = value < 0;
    const fixed = Math.abs(value).toFixed(decimals);
    const parts = fixed.split('.');
    let whole = parts[0];

    if (whole.length > 3) {
      const lastThree = whole.slice(-3);
      const rest = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
      whole = rest + ',' + lastThree;
    }

    return (negative ? '-' : '') + whole + (parts[1] ? '.' + parts[1] : '');
  }

  function compact(value) {
    const abs = Math.abs(value);
    if (abs >= 1e7) return trim(value / 1e7) + 'Cr';
    if (abs >= 1e5) return trim(value / 1e5) + 'L';
    if (abs >= 1e3) return trim(value / 1e3) + 'K';
    return groupIndian(value, 0);
  }

  function trim(n) { return String(parseFloat(n.toFixed(2))); }

  function formatValue(value, format) {
    const number = Number(value);

    switch (format) {
      case 'money':   return CURRENCY + (Math.abs(number) >= 1e5 ? compact(number).replace(CURRENCY, '') : groupIndian(number, 0));
      case 'compact': return compact(number);
      case 'percent': return trim(number) + '%';
      case 'rating':  return '★ ' + number.toFixed(1);
      case 'bytes':   return formatBytes(number);
      case 'number':  return isNaN(number) ? String(value) : groupIndian(number, 0);
      default:        return String(value);
    }
  }

  function formatBytes(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let index = 0;
    while (bytes >= 1024 && index < units.length - 1) { bytes /= 1024; index++; }
    return (index > 1 ? bytes.toFixed(1) : Math.round(bytes)) + ' ' + units[index];
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  }

  /* -------------------------------------------------------------------
     Animated counter — counts up on first paint, respecting reduced motion
     ------------------------------------------------------------------- */
  function animateCounter(el, target, format) {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const numeric = Number(target);

    if (reduced || isNaN(numeric) || numeric === 0) {
      el.textContent = formatValue(target, format);
      return;
    }

    const duration = 900;
    const start = performance.now();

    function frame(now) {
      const progress = Math.min((now - start) / duration, 1);
      // easeOutExpo — fast then settles, which reads as responsive.
      const eased = progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress);

      el.textContent = formatValue(numeric * eased, format);

      if (progress < 1) requestAnimationFrame(frame);
      else el.textContent = formatValue(numeric, format);
    }

    requestAnimationFrame(frame);
  }

  /* -------------------------------------------------------------------
     Renderers, one per widget type
     ------------------------------------------------------------------- */
  const renderers = {
    stat(widget) {
      const d = widget.data || {};
      const value = d.value != null ? d.value : 0;

      let footer = '';

      if (d.change != null) {
        const up = d.change >= 0;
        const cls = d.change === 0 ? 'flat' : (up ? 'up' : 'down');
        const arrow = d.change === 0 ? '→' : (up ? '↑' : '↓');
        footer = '<span class="kpi__delta kpi__delta--' + cls + '">' + arrow + ' ' +
          Math.abs(d.change).toFixed(1) + '%</span>' +
          '<span class="kpi__meta">' + escapeHtml(d.change_label || '') + '</span>';
      } else if (d.meta) {
        footer = '<span class="kpi__meta">' + escapeHtml(d.meta) + '</span>';
      }

      const status = d.status && d.status !== 'neutral'
        ? '<span class="kpi__status kpi__status--' + escapeHtml(d.status) + '"></span>' : '';

      const long = String(formatValue(value, widget.format)).length > 9;

      return '' +
        status +
        '<div class="kpi__head">' +
          '<div class="kpi__icon">' + iconSvg(widget.icon) + '</div>' +
        '</div>' +
        '<div class="kpi__value' + (long ? ' kpi__value--sm' : '') + '" data-counter="' + escapeHtml(value) +
          '" data-format="' + escapeHtml(widget.format) + '">0</div>' +
        '<div class="kpi__label">' + escapeHtml(widget.title) + '</div>' +
        '<div class="kpi__foot">' + footer + '</div>' +
        (widget.link ? '<span class="kpi__go">' + iconSvg('chevron-right', 'ic ic--sm') + '</span>' : '');
    },

    chart(widget) {
      const series = (widget.data && widget.data.series) || [];
      if (!series.length) return emptyState('No data for this period.');
      return '<div data-inline-chart=\'' + JSON.stringify(series).replace(/'/g, '&#39;') +
             '\' data-chart-kind="' + escapeHtml(widget.chart || 'line') + '"></div>';
    },

    table(widget) {
      const d = widget.data || {};
      if (!d.rows || !d.rows.length) return emptyState('Nothing to show yet.');

      let head = '<thead><tr>';
      (d.columns || []).forEach(function (c) { head += '<th>' + escapeHtml(c) + '</th>'; });
      head += '</tr></thead>';

      let body = '<tbody>';
      d.rows.forEach(function (row) {
        body += '<tr' + (row.href ? ' data-href="' + escapeHtml(row.href) + '"' : '') + '>';
        (row.cells || []).forEach(function (cell) { body += '<td>' + renderCell(cell) + '</td>'; });
        body += '</tr>';
      });
      body += '</tbody>';

      return '<div class="table-wrap"><table class="wtable">' + head + body + '</table></div>';
    },

    list(widget) {
      const items = (widget.data && widget.data.items) || [];
      if (!items.length) return emptyState('Nothing to show yet.');

      let html = '<div class="wlist">';
      items.forEach(function (item, index) {
        const tag = item.href ? 'a' : 'div';
        const href = item.href ? ' href="' + escapeHtml(item.href) + '"' : '';

        html += '<' + tag + ' class="wlist__row"' + href + '>' +
          (item.avatar_url
            ? '<img class="avatar avatar--sm" src="' + escapeHtml(item.avatar_url) + '" alt="" loading="lazy">'
            : '<span class="wlist__rank">' + (index + 1) + '</span>') +
          '<div class="wlist__main">' +
            '<div class="wlist__label">' + escapeHtml(item.label) + '</div>' +
            (item.meta ? '<div class="wlist__meta">' + escapeHtml(item.meta) + '</div>' : '') +
          '</div>' +
          (item.value_label ? '<span class="wlist__value">' + escapeHtml(item.value_label) + '</span>' : '') +
          (item.tone ? '<span class="chip chip--' + escapeHtml(item.tone) + '"></span>' : '') +
          '</' + tag + '>';
      });
      return html + '</div>';
    },

    summary(widget) {
      const rows = (widget.data && widget.data.rows) || [];
      if (!rows.length) return emptyState('Nothing to show yet.');

      let html = '<div class="wsummary">';
      rows.forEach(function (row) {
        html += '<div class="wsummary__row">' +
          '<span class="wsummary__label">' + escapeHtml(row.label) + '</span>' +
          '<span class="wsummary__value' + (row.emphasis ? ' wsummary__value--em' : '') +
            (row.tone ? ' text-' + escapeHtml(row.tone) : '') + '">' +
            escapeHtml(row.value) +
          '</span>' +
        '</div>';
        if (row.meta) html += '<div class="wlist__meta" style="margin-top:-6px">' + escapeHtml(row.meta) + '</div>';
      });
      return html + '</div>';
    },

    bars(widget) {
      const bars = (widget.data && widget.data.bars) || [];
      if (!bars.length) return emptyState('No data yet.');

      const max = Math.max.apply(null, bars.map(function (b) { return Number(b.value) || 0; })) || 1;

      let html = '<div class="wbars">';
      bars.forEach(function (bar) {
        const pct = Math.max(2, Math.round((Number(bar.value) / max) * 100));
        html += '<div class="wbars__row">' +
          '<div class="wbars__head"><span>' + escapeHtml(bar.label) + '</span>' +
          '<span class="mono muted">' + groupIndian(Number(bar.value), 0) + '</span></div>' +
          '<div class="wbars__track"><div class="wbars__fill" style="width:0%;background:' +
            escapeHtml(bar.tone || 'var(--primary)') + '" data-width="' + pct + '"></div></div>' +
        '</div>';
      });
      return html + '</div>';
    },

    donut(widget) {
      const segments = (widget.data && widget.data.segments) || [];
      const total = (widget.data && widget.data.total) || 0;
      if (!segments.length || !total) return emptyState('No data yet.');

      // Build the ring with a conic gradient — no chart library needed.
      let offset = 0;
      const stops = segments.map(function (s) {
        const pct = (Number(s.value) / total) * 100;
        const stop = (s.tone || 'var(--primary)') + ' ' + offset.toFixed(2) + '% ' + (offset + pct).toFixed(2) + '%';
        offset += pct;
        return stop;
      }).join(', ');

      let legend = '';
      segments.forEach(function (s) {
        legend += '<div class="wdonut__item">' +
          '<span class="wdonut__swatch" style="background:' + escapeHtml(s.tone || 'var(--primary)') + '"></span>' +
          '<span class="truncate">' + escapeHtml(s.label) + '</span>' +
          '<span class="wdonut__count">' + groupIndian(Number(s.value), 0) + '</span>' +
        '</div>';
      });

      return '<div class="wdonut">' +
        '<div class="wdonut__ring" style="border-radius:50%;background:conic-gradient(' + stops + ');' +
          'position:relative;display:grid;place-items:center">' +
          '<div style="position:absolute;inset:16px;border-radius:50%;background:var(--surface-lowest);' +
            'display:grid;place-items:center;font-weight:800;font-size:15px">' + compact(total) + '</div>' +
        '</div>' +
        '<div class="wdonut__legend">' + legend + '</div>' +
      '</div>';
    },

    gauge(widget) {
      const d = widget.data || {};
      const pct = Math.max(0, Math.min(100, Number(d.percent) || 0));
      const tone = pct > 85 ? 'var(--error)' : (pct > 65 ? 'var(--warning)' : 'var(--tertiary)');

      return '<div class="wgauge">' +
        '<div class="wgauge__value">' + escapeHtml(d.value || '—') + '</div>' +
        '<div class="wgauge__track"><div class="wgauge__fill" style="width:0%;background:' + tone +
          '" data-width="' + pct + '"></div></div>' +
        '<div class="wgauge__meta">' + escapeHtml(d.meta || '') + ' · ' + pct + '%</div>' +
      '</div>';
    },

    funnel(widget) {
      const stages = (widget.data && widget.data.stages) || [];
      if (!stages.length) return emptyState('No bookings in this period.');

      const peak = Math.max.apply(null, stages.map(function (s) { return Number(s.value) || 0; })) || 1;

      let html = '<div class="wfunnel">';
      stages.forEach(function (stage) {
        const pct = Math.max(2, Math.round((Number(stage.value) / peak) * 100));
        html += '<div class="wfunnel__stage">' +
          '<div class="wfunnel__head"><span style="font-weight:600">' + escapeHtml(stage.label) + '</span>' +
          '<span class="mono muted">' + groupIndian(Number(stage.value), 0) + '</span></div>' +
          '<div class="wfunnel__track"><div class="wfunnel__fill" style="width:0%;background:' +
            escapeHtml(stage.tone) + '" data-width="' + pct + '"></div></div>' +
        '</div>';
      });

      html += '</div><hr class="divider"><div class="wsummary__row">' +
        '<span class="wsummary__label">Conversion</span>' +
        '<span class="chip chip--' + ((widget.data.conversion || 0) >= 40 ? 'success' : 'warning') + '">' +
        (widget.data.conversion || 0) + '%</span></div>';

      return html;
    },

    health(widget) {
      const rows = (widget.data && widget.data.rows) || [];
      let html = '<div class="whealth">';
      rows.forEach(function (row) {
        const colour = row.tone === 'success' ? 'var(--tertiary)'
          : (row.tone === 'error' ? 'var(--error)' : 'var(--warning)');
        html += '<div class="whealth__row">' +
          '<span class="row gap-2"><span class="whealth__dot" style="background:' + colour + '"></span>' +
          escapeHtml(row.label) + '</span>' +
          '<span class="mono" style="font-weight:600">' + escapeHtml(row.value) + '</span>' +
        '</div>';
      });
      return html + '</div>';
    },

    actions(widget) {
      const actions = (widget.data && widget.data.actions) || [];
      if (!actions.length) return emptyState('No actions available to your role.');

      let html = '<div class="wactions">';
      actions.forEach(function (a) {
        html += '<a class="waction" href="' + escapeHtml(a.href) + '" style="--tone:' + escapeHtml(a.tone) + '">' +
          '<span class="waction__icon">' + iconSvg(a.icon) + '</span>' +
          '<span>' + escapeHtml(a.label) + '</span>' +
        '</a>';
      });
      return html + '</div>';
    },

    todo(widget) {
      const tasks = (widget.data && widget.data.tasks) || [];

      let html = '<div class="wtodo">';

      if (!tasks.length) {
        html += '<p class="muted small text-center" style="padding:12px 0">Nothing on your list.</p>';
      } else {
        tasks.forEach(function (t) {
          html += '<div class="wtodo__row' + (Number(t.is_done) ? ' is-done' : '') + '">' +
            '<input type="checkbox" data-task-toggle="' + t.id + '"' + (Number(t.is_done) ? ' checked' : '') +
              ' style="accent-color:var(--primary);cursor:pointer">' +
            '<span class="wtodo__title">' + escapeHtml(t.title) + '</span>' +
            (t.priority === 'high' ? '<span class="chip chip--error">High</span>' : '') +
            '<button class="btn btn--ghost btn--icon btn--sm text-error" data-task-delete="' + t.id +
              '" aria-label="Delete task">' + iconSvg('x', 'ic ic--sm') + '</button>' +
          '</div>';
        });
      }

      html += '</div><form class="wtodo__form" data-task-form>' +
        '<input class="input" name="title" placeholder="Add a task…" maxlength="255" required>' +
        '<button class="btn btn--primary btn--sm" type="submit">Add</button></form>';

      return html;
    },

    notes(widget) {
      const notes = (widget.data && widget.data.notes) || [];

      let html = '<div class="wnotes">';
      notes.forEach(function (n) {
        const colour = noteColour(n.color);

        html += '<div class="wnote" style="background:' + colour + '" data-note-color="' + colour + '">' +
          '<span class="wnote__tools">' +
            '<button class="wnote__tool" data-note-edit="' + Number(n.id) + '" aria-label="Edit note">' +
              iconSvg('edit', 'ic ic--sm') + '</button>' +
            '<button class="wnote__tool" data-note-delete="' + Number(n.id) + '" aria-label="Delete note">' +
              iconSvg('x', 'ic ic--sm') + '</button>' +
          '</span>' +
          '<span class="wnote__body">' + escapeHtml(n.body) + '</span>' +
        '</div>';
      });
      html += '</div><button class="btn btn--outline btn--sm mt-3" data-note-add>' +
        iconSvg('plus', 'ic ic--sm') + ' Add a note</button>';

      return html;
    },
  };

  function renderCell(cell) {
    if (cell.chip) {
      return '<span class="chip chip--' + escapeHtml(cell.tone || 'neutral') + '">' + escapeHtml(cell.chip) + '</span>';
    }

    const classes = ['wtable__text'];
    if (cell.mono) classes.push('mono');
    if (cell.muted) classes.push('muted');
    if (cell.strong) classes.push('');
    if (cell.tone) classes.push('text-' + cell.tone);

    const text = '<span class="' + classes.join(' ') + '"' +
      (cell.strong ? ' style="font-weight:700"' : '') + '>' + escapeHtml(cell.text) + '</span>';

    if (cell.avatar) {
      return '<div class="wtable__cell">' +
        '<img class="avatar avatar--sm" src="' + escapeHtml(cell.avatar) + '" alt="" loading="lazy">' +
        '<div class="truncate">' + text +
        (cell.sub ? '<div class="wtable__sub truncate">' + escapeHtml(cell.sub) + '</div>' : '') +
        '</div></div>';
    }

    if (cell.sub) {
      return '<div class="truncate">' + text + '<div class="wtable__sub truncate">' + escapeHtml(cell.sub) + '</div></div>';
    }

    return text;
  }

  function emptyState(message) {
    return '<p class="muted small text-center" style="padding:22px 0">' + escapeHtml(message) + '</p>';
  }

  /** Reuse the inline SVG sprite the server rendered into the page. */
  function iconSvg(name, classes) {
    const template = document.querySelector('#icon-' + name);
    const svg = template ? template.innerHTML : '<circle cx="12" cy="12" r="10"/>';

    return '<svg class="' + (classes || 'ic') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
      'stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + svg + '</svg>';
  }

  /* -------------------------------------------------------------------
     Sticky-note editor — the panel's own dialog, never a native prompt
     ------------------------------------------------------------------- */
  const NOTE_LIMIT = 2000;
  const NOTE_COLOURS = ['#FFD9DE', '#FFE5C2', '#FBF2B8', '#D9F0DC', '#D4E8F8', '#E7DCF7'];

  /** Only a validated hex colour ever reaches an attribute or a style. */
  function noteColour(value) {
    const colour = String(value == null ? '' : value).trim().toUpperCase();
    return /^#[0-9A-F]{6}$/.test(colour) ? colour : NOTE_COLOURS[0];
  }

  /**
   * Compose or edit a note in the panel's own dialog.
   * Resolves with { id, body, color } to save, or null when dismissed.
   */
  function noteDialog(initial) {
    // One editor at a time — a double click must not stack two dialogs.
    if (document.querySelector('[data-note-dialog]')) return Promise.resolve(null);

    const note = initial || {};
    const noteId = Number(note.id) || 0;
    const startColour = noteColour(note.color);

    return new Promise(function (resolve) {
      let chosen = startColour;
      let swatches = '';

      NOTE_COLOURS.forEach(function (colour) {
        const active = colour === startColour;
        swatches += '<button type="button" class="wswatch' + (active ? ' is-active' : '') +
          '" style="background:' + colour + '" data-note-colour="' + colour + '" role="radio" aria-checked="' +
          (active ? 'true' : 'false') + '" aria-label="Colour ' + colour + '"></button>';
      });

      const backdrop = document.createElement('div');
      backdrop.className = 'dialog-backdrop';

      // role/aria-modal belong on the panel, not the full-viewport backdrop —
      // same pattern as Admin.confirm.
      backdrop.innerHTML =
        '<div class="dialog" role="dialog" aria-modal="true" aria-labelledby="wnote-title">' +
        '  <form class="wnote-editor" data-note-dialog>' +
        '    <div class="dialog__head"><h2 class="h2" id="wnote-title" data-title></h2></div>' +
        '    <div class="dialog__body">' +
        '      <label class="field">' +
        '        <span class="field__label">Note</span>' +
        '        <textarea class="textarea" name="body" rows="5" maxlength="' + NOTE_LIMIT + '" required ' +
        '                  placeholder="Write your note…"></textarea>' +
        '      </label>' +
        '      <p class="field__error hidden" data-error>A note cannot be empty.</p>' +
        '      <div class="wnote-editor__foot">' +
        '        <span class="wswatches" role="radiogroup" aria-label="Note colour">' + swatches + '</span>' +
        '        <span class="tiny faint" data-counter aria-live="polite"></span>' +
        '      </div>' +
        '    </div>' +
        '    <div class="dialog__foot">' +
        '      <button class="btn btn--outline" type="button" data-cancel>Cancel</button>' +
        '      <button class="btn btn--primary" type="submit" data-save></button>' +
        '    </div>' +
        '  </form>' +
        '</div>';

      const textarea = backdrop.querySelector('textarea');
      const counter = backdrop.querySelector('[data-counter]');
      const error = backdrop.querySelector('[data-error]');

      backdrop.querySelector('[data-title]').textContent = noteId ? 'Edit note' : 'Add a note';
      backdrop.querySelector('[data-save]').textContent = noteId ? 'Save note' : 'Add note';
      textarea.value = String(note.body == null ? '' : note.body);

      const count = function () { counter.textContent = textarea.value.length + ' / ' + NOTE_LIMIT; };
      count();

      let release = null;

      const close = function (result) {
        document.removeEventListener('keydown', onKey);
        backdrop.remove();
        // Hands focus back to whatever opened the editor; without it focus is
        // dropped on <body> and the next Tab restarts from the skip link.
        if (release) { release(); release = null; }
        resolve(result);
      };

      const onKey = function (e) { if (e.key === 'Escape') close(null); };

      textarea.addEventListener('input', function () {
        count();
        error.classList.add('hidden');
        textarea.removeAttribute('aria-invalid');
      });

      backdrop.querySelectorAll('[data-note-colour]').forEach(function (swatch) {
        swatch.addEventListener('click', function () {
          chosen = swatch.dataset.noteColour;

          backdrop.querySelectorAll('[data-note-colour]').forEach(function (other) {
            const active = other === swatch;
            other.classList.toggle('is-active', active);
            other.setAttribute('aria-checked', active ? 'true' : 'false');
          });
        });
      });

      backdrop.querySelector('[data-note-dialog]').addEventListener('submit', function (e) {
        e.preventDefault();

        const body = textarea.value.trim();

        // `required` stops an empty field; this also stops a whitespace-only one.
        if (body === '') {
          error.classList.remove('hidden');
          textarea.setAttribute('aria-invalid', 'true');
          textarea.focus();
          return;
        }

        const payload = { body: body, color: chosen };
        if (noteId > 0) payload.id = noteId;

        close(payload);
      });

      backdrop.querySelector('[data-cancel]').addEventListener('click', function () { close(null); });
      backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(null); });
      document.addEventListener('keydown', onKey);

      document.body.appendChild(backdrop);

      // Trap before moving focus, so the opener is what gets recorded.
      release = Admin.trapFocus(backdrop);
      textarea.focus();
      textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    });
  }

  /** Persist what the dialog returned. A dismissed dialog is a no-op. */
  async function saveNote(note) {
    if (!note) return;

    try {
      await Admin.request('/admin/widgets/notes', { method: 'POST', body: note });
      Widgets.refresh('sticky_notes');
    } catch (error) {
      Admin.toast(error.message, 'error');
    }
  }

  /* -------------------------------------------------------------------
     Loading and painting
     ------------------------------------------------------------------- */
  Widgets.load = async function (keys) {
    if (!keys.length) return;

    try {
      const result = await Admin.request('/admin/widgets/data?keys=' + encodeURIComponent(keys.join(',')));
      const widgets = (result.data && result.data.widgets) || {};
      const errors = (result.data && result.data.errors) || {};

      Object.keys(widgets).forEach(function (key) { Widgets.paint(key, widgets[key]); });

      Object.keys(errors).forEach(function (key) {
        const host = document.querySelector('[data-widget="' + key + '"]');
        if (host) {
          const body = host.querySelector('[data-widget-body]') || host;
          body.innerHTML = emptyState(errors[key]);
          host.classList.remove('is-loading', 'kpi--skeleton');
        }
      });
    } catch (e) {
      Admin.toast('Could not load dashboard widgets.', 'error');
      document.querySelectorAll('[data-widget].is-loading').forEach(function (host) {
        host.classList.remove('is-loading', 'kpi--skeleton');
        const body = host.querySelector('[data-widget-body]') || host;
        body.innerHTML = emptyState('Could not load.');
      });
    }
  };

  Widgets.paint = function (key, widget) {
    const host = document.querySelector('[data-widget="' + key + '"]');
    if (!host) return;

    const renderer = renderers[widget.type] || renderers.summary;
    const body = host.querySelector('[data-widget-body]') || host;

    body.innerHTML = renderer(widget);
    host.classList.remove('is-loading', 'kpi--skeleton', 'is-refreshing');
    host.style.setProperty('--tone', widget.tone);

    if (widget.link && host.tagName === 'A') host.href = widget.link;

    // Post-paint enhancements.
    body.querySelectorAll('[data-counter]').forEach(function (el) {
      animateCounter(el, el.dataset.counter, el.dataset.format);
    });

    requestAnimationFrame(function () {
      body.querySelectorAll('[data-width]').forEach(function (el) { el.style.width = el.dataset.width + '%'; });
    });

    body.querySelectorAll('[data-inline-chart]').forEach(function (el) {
      let series;
      try { series = JSON.parse(el.dataset.inlineChart); } catch (e) { return; }
      if (el.dataset.chartKind === 'bar') Admin.barChart(el, series);
      else Admin.lineChart(el, series, { height: 210, showArea: el.dataset.chartKind === 'area' });
    });

    body.querySelectorAll('tr[data-href]').forEach(function (row) {
      row.addEventListener('click', function () { window.location.href = row.dataset.href; });
    });

    scheduleRefresh(key, widget.refresh);
  };

  Widgets.refresh = async function (key) {
    const host = document.querySelector('[data-widget="' + key + '"]');
    if (host) host.classList.add('is-refreshing');

    try {
      const result = await Admin.request('/admin/widgets/' + encodeURIComponent(key));
      Widgets.paint(key, result.data.widget);
    } catch (e) {
      if (host) host.classList.remove('is-refreshing');
      Admin.toast('Could not refresh that widget.', 'error');
    }
  };

  function scheduleRefresh(key, seconds) {
    if (timers.has(key)) { clearInterval(timers.get(key)); timers.delete(key); }
    if (!seconds || seconds < 15) return;

    timers.set(key, setInterval(function () {
      // Pause while the tab is hidden — no point burning queries unseen.
      if (document.visibilityState === 'visible') Widgets.refresh(key);
    }, seconds * 1000));
  }

  /* -------------------------------------------------------------------
     Ripple on KPI click
     ------------------------------------------------------------------- */
  function initRipples() {
    document.addEventListener('pointerdown', function (e) {
      const card = e.target.closest('a.kpi');
      if (!card) return;

      const rect = card.getBoundingClientRect();
      const ripple = document.createElement('span');
      const size = Math.max(rect.width, rect.height);

      ripple.className = 'kpi__ripple';
      ripple.style.width = ripple.style.height = size + 'px';
      ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
      ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';

      card.appendChild(ripple);
      setTimeout(function () { ripple.remove(); }, 600);
    });
  }

  /* -------------------------------------------------------------------
     Drag and drop reordering
     ------------------------------------------------------------------- */
  function initDragAndDrop(grid) {
    let dragged = null;

    grid.addEventListener('dragstart', function (e) {
      const item = e.target.closest('.wgrid__item');
      if (!item || !grid.classList.contains('is-editing')) { e.preventDefault(); return; }

      dragged = item;
      item.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      // Firefox needs data set for the drag to start at all.
      e.dataTransfer.setData('text/plain', item.dataset.widgetKey || '');
    });

    grid.addEventListener('dragend', function () {
      if (dragged) dragged.classList.remove('is-dragging');
      grid.querySelectorAll('.is-dropzone').forEach(function (el) { el.classList.remove('is-dropzone'); });
      dragged = null;
      markDirty();
    });

    grid.addEventListener('dragover', function (e) {
      if (!dragged) return;
      e.preventDefault();

      const target = e.target.closest('.wgrid__item');
      if (!target || target === dragged) return;

      grid.querySelectorAll('.is-dropzone').forEach(function (el) { el.classList.remove('is-dropzone'); });
      target.classList.add('is-dropzone');

      const rect = target.getBoundingClientRect();
      const after = (e.clientX - rect.left) > rect.width / 2;

      grid.insertBefore(dragged, after ? target.nextSibling : target);
    });

    grid.addEventListener('drop', function (e) { e.preventDefault(); });
  }

  let dirty = false;

  function markDirty() {
    dirty = true;
    const save = document.querySelector('[data-save-layout]');
    if (save) { save.classList.remove('btn--outline'); save.classList.add('btn--primary'); }
  }

  function currentLayout() {
    return Array.from(document.querySelectorAll('.wgrid__item')).map(function (item) {
      return {
        key: item.dataset.widgetKey,
        size: parseInt(item.style.getPropertyValue('--span'), 10) || 3,
        collapsed: item.querySelector('.widget') ? item.querySelector('.widget').classList.contains('is-collapsed') : false,
      };
    });
  }

  /* -------------------------------------------------------------------
     Boot
     ------------------------------------------------------------------- */
  function boot() {
    const grid = document.querySelector('.wgrid');
    if (!grid) return;

    const keys = Array.from(document.querySelectorAll('[data-widget]')).map(function (el) { return el.dataset.widget; });

    Widgets.load(keys);
    initRipples();
    initDragAndDrop(grid);

    // Edit mode
    const editButton = document.querySelector('[data-toggle-edit]');
    if (editButton) {
      editButton.addEventListener('click', function () {
        const editing = grid.classList.toggle('is-editing');
        editButton.textContent = editing ? 'Done' : 'Customise';
        document.querySelectorAll('[data-edit-only]').forEach(function (el) { el.classList.toggle('hidden', !editing); });
        document.querySelectorAll('.wgrid__item').forEach(function (el) { el.draggable = editing; });
      });
    }

    // Per-widget tools
    document.addEventListener('click', async function (e) {
      const refresh = e.target.closest('[data-widget-refresh]');
      if (refresh) { Widgets.refresh(refresh.dataset.widgetRefresh); return; }

      const collapse = e.target.closest('[data-widget-collapse]');
      if (collapse) {
        const widget = collapse.closest('.widget');
        if (widget) { widget.classList.toggle('is-collapsed'); markDirty(); }
        return;
      }

      const remove = e.target.closest('[data-widget-remove]');
      if (remove) {
        const item = remove.closest('.wgrid__item');
        if (item) { item.remove(); markDirty(); Admin.toast('Widget removed. Save to keep the change.', 'info', 2500); }
        return;
      }

      const resize = e.target.closest('[data-widget-resize]');
      if (resize) {
        const item = resize.closest('.wgrid__item');
        if (!item) return;
        const sizes = [3, 4, 6, 12];
        const current = parseInt(item.style.getPropertyValue('--span'), 10) || 3;
        item.style.setProperty('--span', sizes[(sizes.indexOf(current) + 1) % sizes.length]);
        markDirty();
        return;
      }
    });

    // Save / reset layout
    const saveButton = document.querySelector('[data-save-layout]');
    if (saveButton) {
      saveButton.addEventListener('click', async function () {
        try {
          await Admin.request('/admin/widgets/layout', { method: 'POST', body: { layout: currentLayout() } });
          dirty = false;
          saveButton.classList.add('btn--outline');
          saveButton.classList.remove('btn--primary');
          Admin.toast('Dashboard layout saved.', 'success');
        } catch (err) { /* Admin.request already surfaced it */ }
      });
    }

    const resetButton = document.querySelector('[data-reset-layout]');
    if (resetButton) {
      resetButton.addEventListener('click', async function () {
        const ok = await Admin.confirm({
          title: 'Reset the dashboard?',
          message: 'Your arrangement is discarded and the default layout for your role is restored.',
          confirmLabel: 'Reset', tone: 'warning',
        });
        if (!ok) return;

        await Admin.request('/admin/widgets/layout/reset', { method: 'POST' });
        window.location.reload();
      });
    }

    // Widget picker
    document.querySelectorAll('[data-add-widget]').forEach(function (option) {
      option.addEventListener('click', function () {
        const key = option.dataset.addWidget;

        if (document.querySelector('[data-widget="' + key + '"]')) {
          Admin.toast('That widget is already on your dashboard.', 'info', 2500);
          return;
        }

        const template = document.querySelector('#widget-shell');
        if (!template) return;

        const shell = template.content.cloneNode(true);
        const item = shell.querySelector('.wgrid__item');

        item.dataset.widgetKey = key;
        item.style.setProperty('--span', option.dataset.widgetSize || 3);
        item.querySelector('[data-widget]').dataset.widget = key;

        grid.appendChild(item);
        Widgets.load([key]);
        markDirty();
        option.classList.add('is-added');

        Admin.toast('Added. Save the layout to keep it.', 'success', 2500);
      });
    });

    // Tasks and notes
    document.addEventListener('submit', async function (e) {
      const form = e.target.closest('[data-task-form]');
      if (!form) return;
      e.preventDefault();

      const title = form.querySelector('[name="title"]').value.trim();
      if (!title) return;

      try {
        await Admin.request('/admin/widgets/tasks', { method: 'POST', body: { title: title } });
        form.reset();
        Widgets.refresh('todo_list');
      } catch (error) {
        Admin.toast(error.message, 'error');
      }
    });

    document.addEventListener('change', async function (e) {
      const toggle = e.target.closest('[data-task-toggle]');
      if (!toggle) return;

      try {
        await Admin.request('/admin/widgets/tasks/' + toggle.dataset.taskToggle + '/toggle', { method: 'POST' });
      } catch (error) {
        Admin.toast(error.message, 'error');
      }

      Widgets.refresh('todo_list');
    });

    document.addEventListener('click', async function (e) {
      const del = e.target.closest('[data-task-delete]');
      if (del) {
        try {
          await Admin.request('/admin/widgets/tasks/' + del.dataset.taskDelete + '/delete', { method: 'POST' });
        } catch (error) {
          Admin.toast(error.message, 'error');
        }

        Widgets.refresh('todo_list');
        return;
      }

      const noteDelete = e.target.closest('[data-note-delete]');
      if (noteDelete) {
        try {
          await Admin.request('/admin/widgets/notes/' + noteDelete.dataset.noteDelete + '/delete', { method: 'POST' });
        } catch (error) {
          Admin.toast(error.message, 'error');
        }

        Widgets.refresh('sticky_notes');
        return;
      }

      const noteEdit = e.target.closest('[data-note-edit]');
      if (noteEdit) {
        const card = noteEdit.closest('.wnote');
        const body = card ? card.querySelector('.wnote__body') : null;

        await saveNote(await noteDialog({
          id: noteEdit.dataset.noteEdit,
          body: body ? body.textContent : '',
          color: card ? card.dataset.noteColor : '',
        }));

        return;
      }

      if (e.target.closest('[data-note-add]')) {
        await saveNote(await noteDialog(null));
      }
    });

    // Warn before losing an unsaved arrangement.
    window.addEventListener('beforeunload', function (e) {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = '';
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  window.Widgets = Widgets;
})();
