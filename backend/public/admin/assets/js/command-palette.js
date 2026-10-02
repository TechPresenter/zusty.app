/* =====================================================================
   Zusty admin — command palette
   ---------------------------------------------------------------------
   The topbar has advertised a global search with a `Ctrl K` hint since it
   was written. The form posted to /admin/search on submit, but nothing
   bound the shortcut and nothing called /admin/search/json — whose own
   docblock says it exists "for an omnibox that can call it as the operator
   types". The endpoint was ready; the omnibox was not. A keyboard hint
   that does nothing is worse than no hint, so this is that omnibox.

   Progressive enhancement: the topbar form still works with JavaScript
   off. This file only intercepts it.

   Everything is drawn with the existing design-system classes — .dialog,
   .popover__item, .chip, .empty, .skeleton — so it inherits all 13 themes
   and both colour modes without a single new colour of its own.
   ===================================================================== */
(function () {
  'use strict';

  var ENDPOINT = '/admin/search/json';
  var PER_SOURCE = 5;
  var DEBOUNCE_MS = 180;
  var MIN_TERM = 2;
  var RECENT_KEY = 'zuste.palette.recent';
  var RECENT_MAX = 6;

  var root = null;
  var input = null;
  var list = null;
  var status = null;
  var open = false;
  var timer = null;
  var request = null;
  var items = [];
  var active = -1;
  var lastFocus = null;
  var seq = 0;

  // -------------------------------------------------------------------
  // Recent searches — a palette that opens empty on every use makes the
  // operator retype the same query all day.
  // -------------------------------------------------------------------

  function recent() {
    try {
      var raw = window.localStorage.getItem(RECENT_KEY);
      var parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed.filter(function (t) { return typeof t === 'string'; }) : [];
    } catch (e) {
      return [];
    }
  }

  function remember(term) {
    term = String(term || '').trim();
    if (term.length < MIN_TERM) return;

    try {
      var list = recent().filter(function (t) { return t !== term; });
      list.unshift(term);
      window.localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, RECENT_MAX)));
    } catch (e) {
      // A full or disabled localStorage must not break the search itself.
    }
  }

  // -------------------------------------------------------------------
  // Markup
  // -------------------------------------------------------------------

  function icon(name) {
    // The server renders real SVGs; the palette only needs a stable glyph
    // slot, so it reuses the sprite the topbar already ships by cloning a
    // rendered icon when one exists and falling back to a dot.
    var source = document.querySelector('[data-palette-icon="' + name + '"]');
    if (source) return source.outerHTML;

    return '<svg class="ic ic--sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
      + 'stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<circle cx="12" cy="12" r="8"></circle></svg>';
  }

  function escape(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function build() {
    if (root) return;

    root = document.createElement('div');
    root.className = 'palette';
    root.hidden = true;
    root.innerHTML = ''
      + '<div class="palette__scrim" data-palette-close></div>'
      + '<div class="palette__panel" role="dialog" aria-modal="true" aria-label="Search everything">'
      +   '<div class="palette__head">'
      +     '<span class="palette__head-icon">' + icon('search') + '</span>'
      +     '<input class="palette__input" type="search" autocomplete="off" spellcheck="false"'
      +           ' placeholder="Search users, creators, bookings, tickets, content…"'
      +           ' role="combobox" aria-expanded="true" aria-controls="palette-list"'
      +           ' aria-autocomplete="list" aria-label="Search everything">'
      +     '<kbd class="palette__kbd" data-palette-close>Esc</kbd>'
      +   '</div>'
      +   '<div class="palette__list" id="palette-list" role="listbox" aria-label="Results"></div>'
      +   '<div class="palette__foot">'
      +     '<span><kbd>&uarr;</kbd><kbd>&darr;</kbd> navigate</span>'
      +     '<span><kbd>&crarr;</kbd> open</span>'
      +     '<span><kbd>Esc</kbd> close</span>'
      +   '</div>'
      + '</div>'
      + '<p class="sr-only" role="status" aria-live="polite" data-palette-status></p>';

    document.body.appendChild(root);

    input = root.querySelector('.palette__input');
    list = root.querySelector('.palette__list');
    status = root.querySelector('[data-palette-status]');

    root.addEventListener('click', function (event) {
      if (event.target.closest('[data-palette-close]')) close();
    });

    input.addEventListener('input', function () {
      schedule(input.value);
    });

    input.addEventListener('keydown', onKeydown);

    list.addEventListener('click', function (event) {
      var row = event.target.closest('[data-palette-row]');
      if (!row) return;

      event.preventDefault();
      choose(items.indexOf(row));
    });

    // Hovering should move the selection, or the mouse and the keyboard
    // disagree about which row Enter would open.
    list.addEventListener('mousemove', function (event) {
      var row = event.target.closest('[data-palette-row]');
      if (row) highlight(items.indexOf(row));
    });
  }

  // -------------------------------------------------------------------
  // Rendering
  // -------------------------------------------------------------------

  function render(html, announcement) {
    list.innerHTML = html;
    items = Array.prototype.slice.call(list.querySelectorAll('[data-palette-row]'));
    active = -1;

    if (items.length) highlight(0);

    if (announcement && status) status.textContent = announcement;
  }

  function skeleton() {
    var rows = '';
    for (var i = 0; i < 3; i++) {
      rows += '<div class="palette__row palette__row--ghost">'
        + '<span class="skeleton skeleton--avatar"></span>'
        + '<span class="palette__text"><span class="skeleton skeleton--title"></span>'
        + '<span class="skeleton skeleton--text"></span></span></div>';
    }
    render(rows);
  }

  function idle() {
    var terms = recent();

    if (!terms.length) {
      render(
        '<div class="empty palette__empty">'
        + '<span class="empty__icon">' + icon('search') + '</span>'
        + '<p class="mb-0">Search across users, creators, bookings, tickets and content.</p>'
        + '<p class="small muted mb-0">Type at least ' + MIN_TERM + ' characters.</p>'
        + '</div>'
      );
      return;
    }

    var html = '<div class="palette__group-label">Recent</div>';

    terms.forEach(function (term) {
      html += '<button type="button" class="palette__row" data-palette-row data-term="' + escape(term) + '" role="option">'
        + '<span class="palette__icon">' + icon('clock') + '</span>'
        + '<span class="palette__text"><span class="palette__label">' + escape(term) + '</span></span>'
        + '</button>';
    });

    render(html);
  }

  function results(payload, term) {
    var groups = (payload && payload.groups) || [];

    if (!groups.length) {
      render(
        '<div class="empty palette__empty">'
        + '<span class="empty__icon">' + icon('search') + '</span>'
        + '<p class="mb-0">No matches for &ldquo;' + escape(term) + '&rdquo;.</p>'
        + '<p class="small muted mb-0">Try a name, an email, a phone number or a booking code.</p>'
        + '</div>',
        'No matches.'
      );
      return;
    }

    var html = '';
    var total = 0;

    groups.forEach(function (group) {
      html += '<div class="palette__group-label">' + escape(group.label)
        + '<span class="palette__count">' + (group.capped ? group.count + '+' : group.count) + '</span></div>';

      (group.results || []).forEach(function (row) {
        total++;
        html += '<a class="palette__row" data-palette-row role="option" href="' + escape(row.url) + '">'
          + '<span class="palette__icon">' + icon(row.icon || group.icon) + '</span>'
          + '<span class="palette__text">'
          +   '<span class="palette__label">' + escape(row.label) + '</span>'
          +   (row.sublabel ? '<span class="palette__sub">' + escape(row.sublabel) + '</span>' : '')
          + '</span>'
          + (row.chip ? '<span class="chip chip--' + escape(row.tone || 'neutral') + ' tiny">' + escape(row.chip) + '</span>' : '')
          + '</a>';
      });

      // "Open in <module>" carries the term through, so a capped group is
      // never a dead end.
      if (group.capped && group.index) {
        html += '<a class="palette__row palette__row--more" data-palette-row role="option" href="' + escape(group.index) + '">'
          + '<span class="palette__icon">' + icon('arrow-right') + '</span>'
          + '<span class="palette__text"><span class="palette__label">All ' + escape(group.label.toLowerCase()) + ' matches</span></span>'
          + '</a>';
      }
    });

    render(html, total + (total === 1 ? ' result' : ' results'));
  }

  function failed() {
    render(
      '<div class="empty palette__empty">'
      + '<span class="empty__icon">' + icon('alert') + '</span>'
      + '<p class="mb-0">Search is unavailable right now.</p>'
      + '<p class="small muted mb-0">Press Enter to open the full search page instead.</p>'
      + '</div>',
      'Search failed.'
    );
  }

  // -------------------------------------------------------------------
  // Fetching
  // -------------------------------------------------------------------

  function schedule(term) {
    window.clearTimeout(timer);
    timer = window.setTimeout(function () { run(term); }, DEBOUNCE_MS);
  }

  function run(term) {
    term = String(term || '').trim();

    if (term.length < MIN_TERM) {
      idle();
      return;
    }

    skeleton();

    // A monotonic token rather than AbortController: an out-of-order reply
    // from a slower earlier keystroke must not overwrite a newer one, and
    // this works in every browser the panel supports.
    var mine = ++seq;

    if (request && request.abort) request.abort();

    request = new XMLHttpRequest();
    request.open('GET', ENDPOINT + '?q=' + encodeURIComponent(term) + '&limit=' + PER_SOURCE, true);
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.setRequestHeader('Accept', 'application/json');

    request.onload = function () {
      if (mine !== seq) return;

      if (request.status < 200 || request.status >= 300) {
        failed();
        return;
      }

      try {
        var body = JSON.parse(request.responseText);
        results(body.data || body, term);
      } catch (e) {
        failed();
      }
    };

    request.onerror = function () {
      if (mine === seq) failed();
    };

    request.send();
  }

  // -------------------------------------------------------------------
  // Keyboard
  // -------------------------------------------------------------------

  function highlight(index) {
    if (!items.length) return;

    if (active > -1 && items[active]) {
      items[active].classList.remove('is-active');
      items[active].removeAttribute('aria-selected');
    }

    active = Math.max(0, Math.min(items.length - 1, index));

    var row = items[active];
    row.classList.add('is-active');
    row.setAttribute('aria-selected', 'true');

    if (!row.id) row.id = 'palette-row-' + active;
    input.setAttribute('aria-activedescendant', row.id);

    // `nearest` rather than a scrollTo: the list should not jump when the
    // row is already visible.
    if (row.scrollIntoView) row.scrollIntoView({ block: 'nearest' });
  }

  function choose(index) {
    var row = items[index];
    if (!row) return;

    var term = row.getAttribute('data-term');

    // A recent-search row re-runs the query rather than navigating.
    if (term) {
      input.value = term;
      run(term);
      return;
    }

    remember(input.value);

    if (row.href) window.location.href = row.href;
  }

  function onKeydown(event) {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      highlight(active + 1);
      return;
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault();
      highlight(active - 1);
      return;
    }

    if (event.key === 'Home' && items.length) {
      event.preventDefault();
      highlight(0);
      return;
    }

    if (event.key === 'End' && items.length) {
      event.preventDefault();
      highlight(items.length - 1);
      return;
    }

    if (event.key === 'Enter') {
      event.preventDefault();

      // Nothing highlighted, or the fetch failed: fall through to the full
      // search page rather than swallowing the keystroke.
      if (active > -1 && items[active]) {
        choose(active);
        return;
      }

      var term = input.value.trim();

      if (term.length >= MIN_TERM) {
        remember(term);
        window.location.href = '/admin/search?q=' + encodeURIComponent(term);
      }

      return;
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      close();
    }
  }

  // -------------------------------------------------------------------
  // Open / close
  // -------------------------------------------------------------------

  function show(seed) {
    build();

    if (open) {
      input.focus();
      input.select();
      return;
    }

    lastFocus = document.activeElement;
    open = true;
    root.hidden = false;

    // Stops the page behind from scrolling under the palette.
    document.documentElement.classList.add('has-palette');

    input.value = seed || '';

    // rAF so the transition has a frame to start from, and focus lands
    // after the element is actually painted.
    window.requestAnimationFrame(function () {
      root.classList.add('is-open');
      input.focus();

      if (input.value) {
        input.select();
        run(input.value);
      } else {
        idle();
      }
    });
  }

  function close() {
    if (!open) return;

    open = false;
    seq++;                                  // invalidate any reply in flight
    window.clearTimeout(timer);
    if (request && request.abort) request.abort();

    root.classList.remove('is-open');
    document.documentElement.classList.remove('has-palette');

    var finish = function () {
      if (!open) root.hidden = true;
    };

    // Matches the CSS transition; the timeout is the fallback for a browser
    // that never fires transitionend (reduced motion sets duration to 0).
    root.addEventListener('transitionend', finish, { once: true });
    window.setTimeout(finish, 220);

    // Focus goes back where it came from — except to the topbar search box,
    // whose focus handler opens the palette. Restoring focus there would
    // reopen it immediately and the palette could never be closed.
    if (lastFocus && lastFocus.focus && !lastFocus.hasAttribute('data-global-search')) {
      lastFocus.focus();
    }

    lastFocus = null;
  }

  // -------------------------------------------------------------------
  // Wiring
  // -------------------------------------------------------------------

  function isTypingTarget(el) {
    if (!el) return false;
    if (el.isContentEditable) return true;

    var tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
  }

  document.addEventListener('keydown', function (event) {
    // Ctrl/Cmd-K from anywhere. The browser binds Cmd-K to its own address
    // bar on some platforms, so preventDefault is not optional.
    if ((event.ctrlKey || event.metaKey) && (event.key === 'k' || event.key === 'K')) {
      event.preventDefault();
      show(open ? input.value : '');
      return;
    }

    // "/" is the other convention, but only when the operator is not
    // already typing into something.
    if (event.key === '/' && !open && !isTypingTarget(document.activeElement)) {
      event.preventDefault();
      show('');
    }
  });

  function bind() {
    var box = document.querySelector('[data-global-search]');
    if (!box) return;

    // The topbar field becomes a button in effect: focusing or clicking it
    // opens the palette instead. The form is left intact so the no-JS path
    // still submits to /admin/search.
    box.setAttribute('readonly', 'readonly');
    box.setAttribute('aria-haspopup', 'dialog');

    var openFrom = function (event) {
      event.preventDefault();
      box.blur();
      show(box.value);
    };

    box.addEventListener('focus', openFrom);
    box.addEventListener('click', openFrom);

    var form = box.closest('form');

    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        show(box.value);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }

  window.CommandPalette = { open: show, close: close };
}());
