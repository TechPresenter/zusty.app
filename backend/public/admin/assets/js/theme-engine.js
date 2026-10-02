/* =====================================================================
   Zusty Admin — theme engine
   Skin (13 palettes) × mode (light / dark / system), persisted per admin.

   Vanilla, no dependency, no build step — the panel ships a
   `script-src 'self'` CSP, so nothing may come from a CDN.

   Loaded in <head> BEFORE the stylesheets render anything, so the stored
   theme is applied on the first paint. Deferring this to DOMContentLoaded
   produces a flash of the default palette on every navigation, which on a
   dark theme is a white flash — the single most noticeable way a themed
   admin panel feels cheap.
   ===================================================================== */
(function () {
  'use strict';

  var STORAGE_SKIN = 'zuste.skin';
  var STORAGE_MODE = 'zuste.mode';
  var DEFAULT_SKIN = 'vibrant';
  var DEFAULT_MODE = 'system';

  /** Every selectable theme. Keys match [data-skin] in themes.css. */
  var THEMES = [
    { key: 'vibrant',   label: 'Vibrant Influence', swatch: ['#b80049', '#632ce5'] },
    { key: 'purple',    label: 'Purple',            swatch: ['#7c3aed', '#db2777'] },
    { key: 'royal',     label: 'Royal Blue',        swatch: ['#1d4ed8', '#0891b2'] },
    { key: 'emerald',   label: 'Emerald',           swatch: ['#059669', '#0d9488'] },
    { key: 'orange',    label: 'Orange',            swatch: ['#ea580c', '#d97706'] },
    { key: 'crimson',   label: 'Crimson',           swatch: ['#be123c', '#9f1239'] },
    { key: 'teal',      label: 'Teal',              swatch: ['#0f766e', '#0284c7'] },
    { key: 'indigo',    label: 'Indigo',            swatch: ['#4338ca', '#7c3aed'] },
    { key: 'rosegold',  label: 'Rose Gold',         swatch: ['#b76e79', '#a1683f'] },
    { key: 'cyber',     label: 'Cyber Neon',        swatch: ['#06b6d4', '#d946ef'] },
    { key: 'midnight',  label: 'Midnight',          swatch: ['#334155', '#0ea5e9'] },
    { key: 'aurora',    label: 'Aurora',            swatch: ['#8b5cf6', '#10b981'] },
    { key: 'corporate', label: 'Corporate',         swatch: ['#1e40af', '#475569'] }
  ];

  var VALID_SKINS = THEMES.map(function (t) { return t.key; });
  var VALID_MODES = ['light', 'dark', 'system'];

  var root = document.documentElement;
  var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  /* -------------------------------------------------------------------
     Storage — never throws
     localStorage is unavailable in private mode on some browsers and
     inside sandboxed frames. A theme preference is not worth an
     exception that stops the panel booting.
     ------------------------------------------------------------------- */
  function read(key, fallback) {
    try {
      return window.localStorage.getItem(key) || fallback;
    } catch (e) {
      return fallback;
    }
  }

  function write(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch (e) {
      /* preference simply does not persist */
    }
  }

  /* -------------------------------------------------------------------
     Apply
     ------------------------------------------------------------------- */

  /** Resolve `system` against the OS preference. */
  function resolveMode(mode) {
    if (mode !== 'system') {
      return mode;
    }

    return media && media.matches ? 'dark' : 'light';
  }

  function currentSkin() {
    var skin = read(STORAGE_SKIN, DEFAULT_SKIN);
    return VALID_SKINS.indexOf(skin) === -1 ? DEFAULT_SKIN : skin;
  }

  function currentMode() {
    var mode = read(STORAGE_MODE, DEFAULT_MODE);
    return VALID_MODES.indexOf(mode) === -1 ? DEFAULT_MODE : mode;
  }

  function apply(skin, mode) {
    root.setAttribute('data-skin', skin);
    root.setAttribute('data-theme', resolveMode(mode));
    // Kept distinct from data-theme so the UI can show "System" as
    // selected while the document is in whichever mode that resolves to.
    root.setAttribute('data-mode', mode);

    // Keeps the browser chrome (mobile address bar, form controls) in step.
    root.style.colorScheme = resolveMode(mode);
  }

  // Run immediately, before first paint.
  apply(currentSkin(), currentMode());

  /* -------------------------------------------------------------------
     Public API
     ------------------------------------------------------------------- */
  var ThemeEngine = {
    themes: THEMES,

    skin: currentSkin,
    mode: currentMode,
    resolved: function () { return resolveMode(currentMode()); },

    setSkin: function (skin) {
      if (VALID_SKINS.indexOf(skin) === -1) {
        return;
      }

      write(STORAGE_SKIN, skin);
      apply(skin, currentMode());
      announce();
    },

    setMode: function (mode) {
      if (VALID_MODES.indexOf(mode) === -1) {
        return;
      }

      write(STORAGE_MODE, mode);
      apply(currentSkin(), mode);
      announce();
    },

    /** Light → dark → system → light. Used by the topbar quick toggle. */
    cycleMode: function () {
      var order = ['light', 'dark', 'system'];
      var next = order[(order.indexOf(currentMode()) + 1) % order.length];
      ThemeEngine.setMode(next);
      return next;
    },

    /**
     * Read a themed colour as a real value.
     *
     * Charts draw into SVG with literal fills, so they cannot use a CSS
     * custom property directly — they have to resolve it at paint time,
     * and re-resolve it whenever the theme changes.
     */
    color: function (name) {
      return getComputedStyle(root).getPropertyValue('--' + name).trim();
    },

    /** The eight-colour series palette for the active theme. */
    chartPalette: function () {
      var out = [];

      for (var i = 1; i <= 8; i++) {
        var value = ThemeEngine.color('chart-' + i);

        if (value) {
          out.push(value);
        }
      }

      return out;
    }
  };

  /**
   * Tell the rest of the page a repaint is needed.
   *
   * Anything drawing with resolved colours — charts, canvas, inline SVG —
   * listens for this. A CSS-only component needs nothing: the custom
   * properties have already cascaded.
   */
  function announce() {
    window.dispatchEvent(new CustomEvent('zuste:themechange', {
      detail: {
        skin: currentSkin(),
        mode: currentMode(),
        resolved: resolveMode(currentMode()),
        palette: ThemeEngine.chartPalette()
      }
    }));
  }

  // Follow the OS while the mode is `system`.
  if (media) {
    var onSystemChange = function () {
      if (currentMode() === 'system') {
        apply(currentSkin(), 'system');
        announce();
      }
    };

    if (media.addEventListener) {
      media.addEventListener('change', onSystemChange);
    } else if (media.addListener) {
      media.addListener(onSystemChange);   // Safari < 14
    }
  }

  // A theme chosen in one tab should follow the admin into the others.
  window.addEventListener('storage', function (event) {
    if (event.key === STORAGE_SKIN || event.key === STORAGE_MODE) {
      apply(currentSkin(), currentMode());
      announce();
    }
  });

  window.ZusteTheme = ThemeEngine;

  /* -------------------------------------------------------------------
     Declarative bindings
     Wired on DOMContentLoaded because they need the markup; the theme
     itself was already applied above.
     ------------------------------------------------------------------- */
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-set-skin]').forEach(function (el) {
      el.addEventListener('click', function () {
        ThemeEngine.setSkin(el.getAttribute('data-set-skin'));
        syncUi();
      });
    });

    document.querySelectorAll('[data-set-mode]').forEach(function (el) {
      el.addEventListener('click', function () {
        ThemeEngine.setMode(el.getAttribute('data-set-mode'));
        syncUi();
      });
    });

    document.querySelectorAll('[data-cycle-mode]').forEach(function (el) {
      el.addEventListener('click', function () {
        ThemeEngine.cycleMode();
        syncUi();
      });
    });

    syncUi();
  });

  /** Mark the active skin and mode in whatever picker is on the page. */
  function syncUi() {
    var skin = currentSkin();
    var mode = currentMode();

    document.querySelectorAll('[data-set-skin]').forEach(function (el) {
      var on = el.getAttribute('data-set-skin') === skin;
      el.classList.toggle('is-active', on);
      el.setAttribute('aria-checked', on ? 'true' : 'false');
    });

    document.querySelectorAll('[data-set-mode]').forEach(function (el) {
      var on = el.getAttribute('data-set-mode') === mode;
      el.classList.toggle('is-active', on);
      el.setAttribute('aria-checked', on ? 'true' : 'false');
    });

    document.querySelectorAll('[data-mode-label]').forEach(function (el) {
      el.textContent = mode.charAt(0).toUpperCase() + mode.slice(1);
    });
  }
})();
