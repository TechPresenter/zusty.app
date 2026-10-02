/* =====================================================================
   Zusty Admin — premium interaction layer

   Adds the behaviour the new component styles imply: ripple origins,
   animated counters, sparklines, progress rings, theme-aware charts and
   the theme picker popover.

   Vanilla, no dependency. Everything is declarative — bind by data
   attribute, never by hand-wiring a screen — so a new page gets the
   behaviour by using the markup, with no JavaScript of its own.

   Loaded after admin.js and widgets.js; it augments them rather than
   replacing anything.
   ===================================================================== */
(function () {
  'use strict';

  var reduceMotion = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* -------------------------------------------------------------------
     1. Ripple origin
     The CSS draws the ripple from --ripple-x/--ripple-y. Without setting
     them the ripple always starts dead centre, which reads as a pulse
     rather than a response to where the pointer actually landed.
     ------------------------------------------------------------------- */
  document.addEventListener('pointerdown', function (event) {
    var btn = event.target.closest('.btn, .kpi, .nav-item');

    if (!btn) {
      return;
    }

    var rect = btn.getBoundingClientRect();
    btn.style.setProperty('--ripple-x', ((event.clientX - rect.left) / rect.width * 100) + '%');
    btn.style.setProperty('--ripple-y', ((event.clientY - rect.top) / rect.height * 100) + '%');
  }, { passive: true });

  /* -------------------------------------------------------------------
     2. Animated counters
     `<span data-count-to="1234" data-count-format="money">`

     Only animates when the element scrolls into view, and only once — a
     dashboard that re-animates every KPI on every scroll is exhausting.
     ------------------------------------------------------------------- */
  var formatters = {
    number: function (n) { return Math.round(n).toLocaleString('en-IN'); },
    money: function (n) {
      return '₹' + Math.round(n).toLocaleString('en-IN');
    },
    compact: function (n) {
      if (n >= 10000000) return (n / 10000000).toFixed(1).replace(/\.0$/, '') + 'Cr';
      if (n >= 100000)   return (n / 100000).toFixed(1).replace(/\.0$/, '') + 'L';
      if (n >= 1000)     return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
      return Math.round(n).toString();
    },
    percent: function (n) { return n.toFixed(1) + '%'; },
    decimal: function (n) { return n.toFixed(2); }
  };

  function animateCount(el) {
    var target = parseFloat(el.getAttribute('data-count-to'));

    if (!isFinite(target)) {
      return;
    }

    var format = formatters[el.getAttribute('data-count-format') || 'number'] || formatters.number;

    if (reduceMotion) {
      el.textContent = format(target);
      return;
    }

    var duration = parseInt(el.getAttribute('data-count-duration'), 10) || 900;
    var started = null;

    function frame(now) {
      if (started === null) {
        started = now;
      }

      var progress = Math.min((now - started) / duration, 1);
      // easeOutExpo — fast to near-final, then settles. A linear count
      // looks mechanical; this looks like it is arriving at a value.
      var eased = progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress);

      el.textContent = format(target * eased);

      if (progress < 1) {
        requestAnimationFrame(frame);
      }
    }

    requestAnimationFrame(frame);
  }

  var counterObserver = 'IntersectionObserver' in window
    ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) {
            return;
          }

          animateCount(entry.target);
          counterObserver.unobserve(entry.target);
        });
      }, { threshold: .25 })
    : null;

  function bindCounters(scope) {
    (scope || document).querySelectorAll('[data-count-to]:not([data-counted])').forEach(function (el) {
      el.setAttribute('data-counted', '1');

      if (counterObserver) {
        counterObserver.observe(el);
      } else {
        animateCount(el);
      }
    });
  }

  /* -------------------------------------------------------------------
     3. Sparklines
     `<svg class="kpi__spark" data-spark="4,8,6,11,9,14">`

     Drawn as a path in a 100×32 viewBox with preserveAspectRatio="none",
     so it stretches to whatever width the card is without any measuring.
     ------------------------------------------------------------------- */
  function drawSparkline(svg) {
    var raw = (svg.getAttribute('data-spark') || '').split(',')
      .map(parseFloat)
      .filter(isFinite);

    if (raw.length < 2) {
      // One point is not a trend. Leave it blank rather than draw a
      // flat line that implies data we do not have.
      return;
    }

    var w = 100;
    var h = 32;
    var pad = 3;
    var min = Math.min.apply(null, raw);
    var max = Math.max.apply(null, raw);
    var span = (max - min) || 1;
    var step = w / (raw.length - 1);

    var points = raw.map(function (value, i) {
      return [
        i * step,
        h - pad - ((value - min) / span) * (h - pad * 2)
      ];
    });

    // Catmull-Rom style smoothing, kept mild — an over-smoothed
    // sparkline invents peaks between the real data points.
    var line = 'M' + points[0][0].toFixed(2) + ',' + points[0][1].toFixed(2);

    for (var i = 0; i < points.length - 1; i++) {
      var current = points[i];
      var next = points[i + 1];
      var cx = (current[0] + next[0]) / 2;
      line += ' C' + cx.toFixed(2) + ',' + current[1].toFixed(2) +
              ' ' + cx.toFixed(2) + ',' + next[1].toFixed(2) +
              ' ' + next[0].toFixed(2) + ',' + next[1].toFixed(2);
    }

    var area = line + ' L' + w + ',' + h + ' L0,' + h + ' Z';

    svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.innerHTML =
      '<path class="area" d="' + area + '"></path>' +
      '<path d="' + line + '"></path>';

    if (!reduceMotion) {
      var path = svg.querySelector('path:not(.area)');
      var length = path.getTotalLength ? path.getTotalLength() : 0;

      if (length) {
        path.style.strokeDasharray = length;
        path.style.strokeDashoffset = length;
        path.style.transition = 'stroke-dashoffset 900ms cubic-bezier(.22,1,.36,1)';
        requestAnimationFrame(function () { path.style.strokeDashoffset = '0'; });
      }
    }
  }

  function bindSparklines(scope) {
    (scope || document).querySelectorAll('[data-spark]:not([data-sparked])').forEach(function (svg) {
      svg.setAttribute('data-sparked', '1');
      drawSparkline(svg);
    });
  }

  /* -------------------------------------------------------------------
     4. Progress rings and bars
     `<div class="kpi__ring" data-pct="72">` — animated by transitioning
     the custom property the conic-gradient reads.
     ------------------------------------------------------------------- */
  function bindProgress(scope) {
    (scope || document).querySelectorAll('[data-pct]:not([data-pct-bound])').forEach(function (el) {
      el.setAttribute('data-pct-bound', '1');

      var pct = Math.max(0, Math.min(100, parseFloat(el.getAttribute('data-pct')) || 0));

      if (reduceMotion) {
        el.style.setProperty('--pct', pct);
        return;
      }

      el.style.setProperty('--pct', 0);
      requestAnimationFrame(function () {
        requestAnimationFrame(function () { el.style.setProperty('--pct', pct); });
      });
    });
  }

  /* -------------------------------------------------------------------
     5. Theme-aware charts
     SVG fills are literal values, so a chart cannot reference a CSS
     custom property directly — it has to resolve the palette at paint
     time and repaint whenever the theme changes.
     ------------------------------------------------------------------- */
  function palette() {
    return (window.ZusteTheme && window.ZusteTheme.chartPalette().length)
      ? window.ZusteTheme.chartPalette()
      : ['#b80049', '#632ce5', '#006a34', '#e2165f', '#7c4dff', '#00a152', '#ff6b9d', '#9d7bff'];
  }

  /** Re-fill every themed mark from the current palette. */
  function repaintCharts() {
    var colors = palette();

    document.querySelectorAll('[data-series]').forEach(function (el) {
      var index = parseInt(el.getAttribute('data-series'), 10) || 0;
      var color = colors[index % colors.length];

      if (el.classList.contains('series-line')) {
        el.setAttribute('stroke', color);
      } else {
        el.setAttribute('fill', color);
      }
    });

    document.querySelectorAll('[data-series-swatch]').forEach(function (el) {
      var index = parseInt(el.getAttribute('data-series-swatch'), 10) || 0;
      el.style.background = colors[index % colors.length];
    });
  }

  window.addEventListener('zuste:themechange', function () {
    repaintCharts();
    // Charts drawn by widgets.js own their own redraw path.
    if (window.Admin && typeof window.Admin.redrawCharts === 'function') {
      window.Admin.redrawCharts();
    }
  });

  /* -------------------------------------------------------------------
     6. Interactive chart legends
     ------------------------------------------------------------------- */
  document.addEventListener('click', function (event) {
    var btn = event.target.closest('.chart__legend button[data-toggle-series]');

    if (!btn) {
      return;
    }

    var index = btn.getAttribute('data-toggle-series');
    var on = btn.getAttribute('aria-pressed') !== 'false';

    btn.setAttribute('aria-pressed', on ? 'false' : 'true');

    var chart = btn.closest('.chart') || document;
    chart.querySelectorAll('[data-series="' + index + '"]').forEach(function (el) {
      el.style.display = on ? 'none' : '';
    });
  });

  /* -------------------------------------------------------------------
     7. Theme picker
     ------------------------------------------------------------------- */
  function buildThemePicker(host) {
    if (!window.ZusteTheme || host.getAttribute('data-built') === '1') {
      return;
    }

    host.setAttribute('data-built', '1');

    var modes = [
      ['light', 'Light'],
      ['dark', 'Dark'],
      ['system', 'System']
    ];

    var html = '<div class="mode-toggle" role="radiogroup" aria-label="Colour mode">';

    modes.forEach(function (mode) {
      html += '<button type="button" role="radio" data-set-mode="' + mode[0] + '">' + mode[1] + '</button>';
    });

    html += '</div><div class="theme-grid" role="radiogroup" aria-label="Theme">';

    window.ZusteTheme.themes.forEach(function (theme) {
      html += '<button type="button" role="radio" class="theme-swatch" data-set-skin="' + theme.key + '" title="' + theme.label + '">' +
              '<span class="theme-swatch__colors">' +
                '<i style="background:' + theme.swatch[0] + '"></i>' +
                '<i style="background:' + theme.swatch[1] + '"></i>' +
              '</span>' +
              '<span class="theme-swatch__name">' + theme.label + '</span>' +
              '</button>';
    });

    html += '</div>';
    host.innerHTML = html;

    // theme-engine.js binds by data attribute on DOMContentLoaded, which
    // has already fired by the time this markup exists — so bind here.
    host.querySelectorAll('[data-set-skin]').forEach(function (el) {
      el.addEventListener('click', function () {
        window.ZusteTheme.setSkin(el.getAttribute('data-set-skin'));
        markActive(host);
      });
    });

    host.querySelectorAll('[data-set-mode]').forEach(function (el) {
      el.addEventListener('click', function () {
        window.ZusteTheme.setMode(el.getAttribute('data-set-mode'));
        markActive(host);
      });
    });

    markActive(host);
  }

  function markActive(host) {
    var skin = window.ZusteTheme.skin();
    var mode = window.ZusteTheme.mode();

    host.querySelectorAll('[data-set-skin]').forEach(function (el) {
      var on = el.getAttribute('data-set-skin') === skin;
      el.classList.toggle('is-active', on);
      el.setAttribute('aria-checked', on ? 'true' : 'false');
    });

    host.querySelectorAll('[data-set-mode]').forEach(function (el) {
      var on = el.getAttribute('data-set-mode') === mode;
      el.classList.toggle('is-active', on);
      el.setAttribute('aria-checked', on ? 'true' : 'false');
    });
  }

  /* -------------------------------------------------------------------
     8. Popovers
     `<button data-popover="#id">` toggles the element, closes on outside
     click and on Escape, and returns focus to the trigger.
     ------------------------------------------------------------------- */
  var openPopover = null;
  var popoverTrigger = null;

  function closePopover() {
    if (!openPopover) {
      return;
    }

    openPopover.hidden = true;

    if (popoverTrigger) {
      popoverTrigger.setAttribute('aria-expanded', 'false');
    }

    openPopover = null;
    popoverTrigger = null;
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-popover]');

    if (trigger) {
      var target = document.querySelector(trigger.getAttribute('data-popover'));

      if (!target) {
        return;
      }

      var wasOpen = openPopover === target;
      closePopover();

      if (wasOpen) {
        return;
      }

      target.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      openPopover = target;
      popoverTrigger = trigger;

      if (target.hasAttribute('data-theme-picker')) {
        buildThemePicker(target);
      }

      return;
    }

    // A click inside the open popover must not close it.
    if (openPopover && !event.target.closest('.popover')) {
      closePopover();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' || !openPopover) {
      return;
    }

    var trigger = popoverTrigger;
    closePopover();

    if (trigger) {
      trigger.focus();
    }
  });

  /* -------------------------------------------------------------------
     9. Copy to clipboard
     ------------------------------------------------------------------- */
  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-copy]');

    if (!btn || !navigator.clipboard) {
      return;
    }

    navigator.clipboard.writeText(btn.getAttribute('data-copy')).then(function () {
      if (window.Admin && window.Admin.toast) {
        window.Admin.toast('Copied to clipboard.', 'success', 1800);
      }
    }).catch(function () {
      if (window.Admin && window.Admin.toast) {
        window.Admin.toast('Could not copy — your browser blocked it.', 'error');
      }
    });
  });

  /* -------------------------------------------------------------------
     10. Boot, and re-bind after AJAX
     ------------------------------------------------------------------- */
  function bindAll(scope) {
    bindCounters(scope);
    bindSparklines(scope);
    bindProgress(scope);
    repaintCharts();
  }

  document.addEventListener('DOMContentLoaded', function () { bindAll(document); });

  // Widgets load over AJAX after first paint, so anything injected later
  // has to be bound too. Fired by widgets.js when a batch lands.
  window.addEventListener('zuste:content', function (event) {
    bindAll(event.detail && event.detail.scope ? event.detail.scope : document);
  });

  window.ZustePremium = { bind: bindAll, repaintCharts: repaintCharts };
})();
