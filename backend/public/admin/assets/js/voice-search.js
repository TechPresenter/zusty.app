/* =====================================================================
   Zusty admin — dictation for search fields
   ---------------------------------------------------------------------
   Attaches a microphone to every search input in the panel, including the
   one the command palette builds at runtime.

   Two things are deliberate.

   It uses the Web Speech API and nothing else — no dependency, no build
   step. That API is Chrome and Edge (and Safari, partly); Firefox does not
   implement it at all. So the button is not rendered unless the browser
   answers for it. A microphone that does nothing when clicked is worse
   than no microphone.

   And it can be switched off panel-wide. In Chrome the Web Speech API
   streams captured audio to Google's servers for recognition — that is
   how it works, not a flaw — which is a different proposition in a panel
   that handles KYC documents than it is on a shopping site. The operator
   decides, through Settings → Developer → Voice search, and this file
   reads that decision from a data attribute the layout renders.
   ===================================================================== */
(function () {
  'use strict';

  var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;

  // No implementation, or the operator has turned it off. Either way, do
  // nothing at all — including adding no markup, so nothing shifts.
  if (!Recognition) return;

  var root = document.documentElement;

  if (root.getAttribute('data-voice-search') === 'off') return;

  var active = null;   // the input currently being dictated into

  // -------------------------------------------------------------------
  // One recogniser, reused. Constructing a new one per click leaks the
  // previous session's handlers and Chrome starts refusing to start.
  // -------------------------------------------------------------------

  var recogniser = new Recognition();

  recogniser.continuous = false;
  recogniser.interimResults = true;   // words appear as they are spoken
  recogniser.maxAlternatives = 1;
  recogniser.lang = document.documentElement.lang || 'en-IN';

  recogniser.onresult = function (event) {
    if (!active) return;

    var text = '';

    for (var i = event.resultIndex; i < event.results.length; i++) {
      text += event.results[i][0].transcript;
    }

    text = text.trim();
    if (text === '') return;

    active.value = text;

    // The panel's own auto-submit and any other listener are bound to
    // `input`, so dictation has to look exactly like typing or the search
    // fires for typed text and silently not for spoken text.
    active.dispatchEvent(new Event('input', { bubbles: true }));

    if (event.results[event.results.length - 1].isFinal) {
      active.dispatchEvent(new Event('change', { bubbles: true }));
    }
  };

  recogniser.onerror = function (event) {
    // `no-speech` and `aborted` are ordinary outcomes of saying nothing or
    // clicking twice, not faults worth reporting.
    if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
      announce('Microphone access was blocked. Allow it in your browser’s site settings.');
    }

    stop();
  };

  recogniser.onend = stop;

  function stop() {
    if (!active) return;

    var button = active.parentNode.querySelector('.voice-btn');
    if (button) {
      button.classList.remove('is-listening');
      button.setAttribute('aria-pressed', 'false');
      button.title = 'Search by voice';
    }

    active = null;
  }

  function start(input, button) {
    // Clicking the mic of a second field while the first is listening should
    // move the microphone, not stack two sessions.
    if (active) {
      recogniser.abort();
      stop();
    }

    active = input;

    button.classList.add('is-listening');
    button.setAttribute('aria-pressed', 'true');
    button.title = 'Stop listening';

    try {
      recogniser.start();
      input.focus();
    } catch (e) {
      // start() throws if the previous session has not finished tearing down.
      stop();
    }
  }

  function announce(message) {
    // Reuses the panel's own toast when it is present rather than an alert.
    if (window.Admin && typeof window.Admin.toast === 'function') {
      window.Admin.toast('error', message);
      return;
    }

    var live = document.getElementById('voice-live');

    if (!live) {
      live = document.createElement('p');
      live.id = 'voice-live';
      live.className = 'sr-only';
      live.setAttribute('role', 'status');
      live.setAttribute('aria-live', 'polite');
      document.body.appendChild(live);
    }

    live.textContent = message;
  }

  // -------------------------------------------------------------------
  // Attaching
  // -------------------------------------------------------------------

  function decorate(input) {
    if (!input || input.dataset.voiceReady === '1') return;
    if (input.hasAttribute('data-no-voice')) return;
    if (input.readOnly || input.disabled) return;

    input.dataset.voiceReady = '1';

    var host = input.parentNode;
    if (!host) return;

    // The button is positioned against the field's wrapper, so the wrapper
    // needs to be a containing block. Setting it here rather than in the
    // stylesheet keeps this working for any markup that adopts the hook
    // later without also needing a CSS rule.
    if (getComputedStyle(host).position === 'static') {
      host.style.position = 'relative';
    }

    var button = document.createElement('button');

    button.type = 'button';                 // never submits the search form
    button.className = 'voice-btn';
    button.title = 'Search by voice';
    button.setAttribute('aria-label', 'Search by voice');
    button.setAttribute('aria-pressed', 'false');
    button.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" '
      + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<rect x="9" y="2" width="6" height="12" rx="3"/>'
      + '<path d="M5 11a7 7 0 0 0 14 0M12 18v4"/></svg>';

    button.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();

      if (active === input) {
        recogniser.stop();
        return;
      }

      start(input, button);
    });

    host.appendChild(button);
    host.classList.add('has-voice');
  }

  function scan(scope) {
    var fields = (scope || document).querySelectorAll(
      'input[type="search"], input[name="q"], [data-voice]'
    );

    Array.prototype.forEach.call(fields, decorate);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(); });
  } else {
    scan();
  }

  // The command palette builds its input in JavaScript after this file has
  // run, and admin lists replace their toolbars over AJAX. Rescanning on
  // mutation catches both without either having to know this file exists.
  if (window.MutationObserver) {
    new MutationObserver(function (records) {
      for (var i = 0; i < records.length; i++) {
        for (var j = 0; j < records[i].addedNodes.length; j++) {
          var node = records[i].addedNodes[j];

          if (node.nodeType === 1) scan(node);
        }
      }
    }).observe(document.body, { childList: true, subtree: true });
  }

  window.VoiceSearch = { scan: scan, decorate: decorate };
}());
