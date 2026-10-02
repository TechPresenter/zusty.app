/**
 * Zusty rich content editor.
 *
 * A WYSIWYG editor written from scratch: no library, no CDN, no build step.
 * The admin panel ships under a `script-src 'self'` policy, so a third-party
 * editor could not be loaded even if we wanted one.
 *
 * PROGRESSIVE ENHANCEMENT. The <textarea> is never removed — it stays in the
 * form as the single source of truth and is what actually submits. The editor
 * is a surface layered over it, kept in sync on every change. If this script
 * fails to parse, throws, or is blocked, the operator gets a plain textarea
 * and can still do their job. Nothing about saving depends on this file.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE CONSTRAINT THAT SHAPES THIS WHOLE FILE
 *
 * Content from these fields is passed through Str::sanitizeHtml() on save
 * (ResourceController::prepare). That sanitiser keeps an allow-list of tags
 * and — critically — DROPS EVERY `style` ATTRIBUTE, because inline styles
 * carry expression()/url() attacks.
 *
 * Browsers implement document.execCommand('justifyCenter') and friends by
 * writing `style="text-align: center"`. So the obvious implementation would
 * appear to work in the editor, survive the round trip to the server, and
 * then come back with every alignment silently gone. That is a worse failure
 * than not offering alignment at all, because nothing tells the operator.
 *
 * Therefore: this editor only ever emits markup that survives the sanitiser.
 * Alignment is applied as a CLASS on the block element, and normaliseHtml()
 * converts any style-based alignment a browser or a paste introduces into
 * that class form before the value is written back to the textarea.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * execCommand is formally deprecated. It is also the only API every current
 * browser implements for inline formatting with correct undo integration, and
 * there is no replacement. It is used here for inline marks only; block-level
 * operations are done directly against the DOM where the result matters.
 */

(function () {
  'use strict';

  /** Tags Str::sanitizeHtml() keeps. Anything else is stripped on save. */
  var ALLOWED_TAGS = [
    'P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
    'UL', 'OL', 'LI', 'BLOCKQUOTE', 'A', 'IMG', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH',
    'TD', 'HR', 'CODE', 'PRE', 'SPAN', 'DIV', 'FIGURE', 'FIGCAPTION'
  ];

  /** Attributes worth keeping. Everything else is dropped on paste. */
  var ALLOWED_ATTRS = ['href', 'src', 'alt', 'title', 'class', 'colspan', 'rowspan'];

  var ALIGN_CLASSES = ['z-ta-left', 'z-ta-center', 'z-ta-right', 'z-ta-justify'];

  /**
   * Toolbar definition.
   *
   * `cmd` names an execCommand for inline marks; `action` names a method on
   * the editor for anything that has to touch the DOM itself.
   */
  var TOOLS = [
    { group: 'history', items: [
      { action: 'undo', icon: 'undo', label: 'Undo', key: 'Ctrl+Z' },
      { action: 'redo', icon: 'redo', label: 'Redo', key: 'Ctrl+Shift+Z' }
    ] },
    { group: 'block', items: [
      { action: 'block', value: 'P',  icon: 'text', label: 'Paragraph' },
      { action: 'block', value: 'H2', icon: 'h2', label: 'Heading 2' },
      { action: 'block', value: 'H3', icon: 'h3', label: 'Heading 3' },
      { action: 'block', value: 'H4', icon: 'h4', label: 'Heading 4' }
    ] },
    { group: 'inline', items: [
      { cmd: 'bold', icon: 'bold', label: 'Bold', key: 'Ctrl+B' },
      { cmd: 'italic', icon: 'italic', label: 'Italic', key: 'Ctrl+I' },
      { cmd: 'underline', icon: 'underline', label: 'Underline', key: 'Ctrl+U' },
      { cmd: 'strikeThrough', icon: 'strike', label: 'Strikethrough' },
      { action: 'inlineCode', icon: 'code', label: 'Inline code' }
    ] },
    { group: 'list', items: [
      { cmd: 'insertUnorderedList', icon: 'ul', label: 'Bulleted list' },
      { cmd: 'insertOrderedList', icon: 'ol', label: 'Numbered list' },
      { action: 'block', value: 'BLOCKQUOTE', icon: 'quote', label: 'Quote' },
      { action: 'block', value: 'PRE', icon: 'pre', label: 'Code block' }
    ] },
    { group: 'align', items: [
      { action: 'align', value: 'left', icon: 'align-left', label: 'Align left' },
      { action: 'align', value: 'center', icon: 'align-center', label: 'Align centre' },
      { action: 'align', value: 'right', icon: 'align-right', label: 'Align right' }
    ] },
    { group: 'insert', items: [
      { action: 'link', icon: 'link', label: 'Insert link', key: 'Ctrl+K' },
      { action: 'unlink', icon: 'unlink', label: 'Remove link' },
      { action: 'image', icon: 'image', label: 'Insert image' },
      { action: 'rule', icon: 'hr', label: 'Horizontal rule' },
      { action: 'table', icon: 'table', label: 'Insert table' }
    ] },
    { group: 'tail', items: [
      { action: 'clear', icon: 'eraser', label: 'Clear formatting' },
      { action: 'source', icon: 'source', label: 'Edit HTML source' }
    ] }
  ];

  /** Inline SVG glyphs. Kept here so the editor needs no icon font or sprite. */
  var ICONS = {
    undo: '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>',
    redo: '<path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3L21 13"/>',
    text: '<path d="M4 7V5h16v2M9 5v14M15 5v14M7 19h4M13 19h4"/>',
    h2: '<path d="M4 6v12M10 6v12M4 12h6M14 18h6v-1l-3.5-3.5A2.5 2.5 0 1 1 14 10"/>',
    h3: '<path d="M4 6v12M10 6v12M4 12h6M14 9h5l-3 3.5A2.75 2.75 0 1 1 14 16"/>',
    h4: '<path d="M4 6v12M10 6v12M4 12h6M18 18v-9l-4 6h6"/>',
    bold: '<path d="M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z"/>',
    italic: '<path d="M19 5h-8M13 19H5M15 5 9 19"/>',
    underline: '<path d="M7 4v6a5 5 0 0 0 10 0V4M5 20h14"/>',
    strike: '<path d="M5 12h14M8 8a3.2 3.2 0 0 1 3.4-3h1.2A3.2 3.2 0 0 1 16 8M16 16a3.2 3.2 0 0 1-3.4 3h-1.2A3.2 3.2 0 0 1 8 16"/>',
    code: '<path d="m9 8-4 4 4 4M15 8l4 4-4 4"/>',
    ul: '<circle cx="4" cy="7" r="1.4"/><circle cx="4" cy="12" r="1.4"/><circle cx="4" cy="17" r="1.4"/><path d="M9 7h11M9 12h11M9 17h11"/>',
    ol: '<path d="M4 6h1v4M4 10h2M4 14h2l-2 3h2M9 7h11M9 12h11M9 17h11"/>',
    quote: '<path d="M7 7h4v5a4 4 0 0 1-4 4V7zM15 7h4v5a4 4 0 0 1-4 4V7z"/>',
    pre: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m8 10-2 2 2 2M14 10l2 2-2 2"/>',
    'align-left': '<path d="M4 6h16M4 10h10M4 14h16M4 18h10"/>',
    'align-center': '<path d="M4 6h16M7 10h10M4 14h16M7 18h10"/>',
    'align-right': '<path d="M4 6h16M10 10h10M4 14h16M10 18h10"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
    unlink: '<path d="M17 7l3-3M4 20l3-3"/><path d="M10 13a5 5 0 0 0 7.5.5M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m4 17 5-5 4 4 3-2 4 4"/>',
    hr: '<path d="M3 12h18M6 7h12M6 17h12"/>',
    table: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 10v10M15 10v10"/>',
    eraser: '<path d="m5 15 6-6 7 7-3 3H8z"/><path d="M11 9 16 4l4 4-5 5M4 20h8"/>',
    source: '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M13 5l-2 14"/>'
  };

  function svg(name) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" ' +
      'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
  }

  /**
   * Strip everything the server would strip anyway, plus the things it keeps
   * but we do not want from a paste (Word/Docs wrappers, class soup).
   *
   * Client-side sanitising is defence in depth and a paste-quality measure —
   * it is NOT the security boundary. Str::sanitizeHtml() on save is.
   */
  function sanitiseFragment(root) {
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT, null);
    var doomed = [];
    var node;

    while ((node = walker.nextNode())) {
      if (ALLOWED_TAGS.indexOf(node.tagName) === -1) {
        doomed.push(node);
        continue;
      }

      for (var i = node.attributes.length - 1; i >= 0; i--) {
        var attr = node.attributes[i];
        var name = attr.name.toLowerCase();

        if (ALLOWED_ATTRS.indexOf(name) === -1) {
          node.removeAttribute(attr.name);
          continue;
        }

        // A pasted class is almost always Word or Docs noise. Ours are the
        // alignment classes and nothing else.
        if (name === 'class') {
          var kept = attr.value.split(/\s+/).filter(function (c) {
            return ALIGN_CLASSES.indexOf(c) !== -1;
          });
          kept.length ? node.setAttribute('class', kept.join(' ')) : node.removeAttribute('class');
          continue;
        }

        if ((name === 'href' || name === 'src') && /^\s*(javascript|vbscript|data)\s*:/i.test(attr.value)) {
          node.removeAttribute(attr.name);
        }
      }
    }

    // Unwrap rather than delete: a <font> or <o:p> around real text should
    // lose the tag, not the words inside it.
    doomed.forEach(function (el) {
      if (!el.parentNode) return;
      while (el.firstChild) el.parentNode.insertBefore(el.firstChild, el);
      el.parentNode.removeChild(el);
    });

    return root;
  }

  /**
   * Convert style-based alignment into the class form that survives the
   * server sanitiser, and drop every remaining style attribute.
   *
   * Runs on every sync, so alignment a browser writes as a style — which is
   * what execCommand and most pastes produce — is normalised before it can
   * reach the textarea and be silently discarded on save.
   */
  function normaliseHtml(html) {
    var box = document.createElement('div');
    box.innerHTML = html;

    box.querySelectorAll('[style]').forEach(function (el) {
      var align = (el.style.textAlign || '').toLowerCase();

      if (align && ['left', 'center', 'right', 'justify'].indexOf(align) !== -1) {
        ALIGN_CLASSES.forEach(function (c) { el.classList.remove(c); });
        el.classList.add('z-ta-' + align);
      }

      el.removeAttribute('style');

      if (el.getAttribute('class') === '') el.removeAttribute('class');
    });

    // Browsers still emit <font> for some operations.
    box.querySelectorAll('font').forEach(function (el) {
      while (el.firstChild) el.parentNode.insertBefore(el.firstChild, el);
      el.parentNode.removeChild(el);
    });

    return box.innerHTML;
  }

  function ZEditor(textarea) {
    this.textarea = textarea;
    this.sourceMode = false;
    this.build();
    this.bind();
    this.load();
  }

  ZEditor.prototype.build = function () {
    var self = this;

    var wrap = document.createElement('div');
    wrap.className = 'z-editor';

    var bar = document.createElement('div');
    bar.className = 'z-editor__bar';
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Formatting');

    TOOLS.forEach(function (group, index) {
      if (index) {
        var sep = document.createElement('span');
        sep.className = 'z-editor__sep';
        sep.setAttribute('aria-hidden', 'true');
        bar.appendChild(sep);
      }

      group.items.forEach(function (tool) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'z-editor__btn';
        btn.innerHTML = svg(tool.icon);
        btn.title = tool.key ? tool.label + ' (' + tool.key + ')' : tool.label;
        btn.setAttribute('aria-label', tool.label);
        btn.setAttribute('aria-pressed', 'false');
        btn.dataset.tool = JSON.stringify(tool);

        // mousedown, not click: click fires after the surface has already lost
        // the selection, so a command would apply to nothing.
        btn.addEventListener('mousedown', function (event) {
          event.preventDefault();
          self.run(tool);
        });

        bar.appendChild(btn);
        (self.buttons = self.buttons || []).push({ el: btn, tool: tool });
      });
    });

    var surface = document.createElement('div');
    surface.className = 'z-editor__surface';
    surface.contentEditable = 'true';
    surface.spellcheck = true;
    surface.setAttribute('role', 'textbox');
    surface.setAttribute('aria-multiline', 'true');
    surface.setAttribute('aria-label', this.labelText());

    if (this.textarea.rows) surface.style.minHeight = (this.textarea.rows * 1.6) + 'em';

    var source = document.createElement('textarea');
    source.className = 'z-editor__source';
    source.setAttribute('aria-label', 'HTML source');
    source.hidden = true;

    var foot = document.createElement('div');
    foot.className = 'z-editor__foot';

    var count = document.createElement('span');
    count.className = 'z-editor__count';

    var hint = document.createElement('span');
    hint.className = 'z-editor__hint';
    hint.textContent = 'Formatting is saved as HTML.';

    foot.appendChild(hint);
    foot.appendChild(count);

    wrap.appendChild(bar);
    wrap.appendChild(surface);
    wrap.appendChild(source);
    wrap.appendChild(foot);

    this.textarea.parentNode.insertBefore(wrap, this.textarea);

    // The textarea stays in the form and stays the value that submits; it is
    // only visually hidden. Removing it, or disabling it, would drop the field
    // from the POST entirely.
    this.textarea.classList.add('z-editor__origin');
    this.textarea.setAttribute('aria-hidden', 'true');
    this.textarea.tabIndex = -1;

    this.wrap = wrap;
    this.surface = surface;
    this.source = source;
    this.count = count;
  };

  /** The field's own label, so the surface announces itself correctly. */
  ZEditor.prototype.labelText = function () {
    var label = this.textarea.id
      ? document.querySelector('label[for="' + this.textarea.id + '"]')
      : null;

    return label ? label.textContent.trim() : 'Rich text';
  };

  ZEditor.prototype.bind = function () {
    var self = this;

    this.surface.addEventListener('input', function () { self.sync(); });
    this.surface.addEventListener('blur', function () { self.sync(); });
    this.surface.addEventListener('keyup', function () { self.refresh(); });
    this.surface.addEventListener('mouseup', function () { self.refresh(); });

    this.source.addEventListener('input', function () {
      self.textarea.value = self.source.value;
      self.updateCount();
    });

    // Paste arrives as whatever the source application produced. Insert a
    // sanitised fragment instead so Word's mso- soup never enters the DOM.
    this.surface.addEventListener('paste', function (event) {
      var data = event.clipboardData;
      if (!data) return;

      event.preventDefault();

      var html = data.getData('text/html');
      var text = data.getData('text/plain');

      if (html) {
        var holder = document.createElement('div');
        holder.innerHTML = html;
        sanitiseFragment(holder);
        document.execCommand('insertHTML', false, normaliseHtml(holder.innerHTML));
      } else {
        document.execCommand('insertText', false, text);
      }

      self.sync();
    });

    this.surface.addEventListener('keydown', function (event) {
      var meta = event.ctrlKey || event.metaKey;
      if (!meta) return;

      var key = event.key.toLowerCase();

      if (key === 'k') {
        event.preventDefault();
        self.run({ action: 'link' });
      } else if (key === 'b' || key === 'i' || key === 'u') {
        // Let the browser handle these; just resync afterwards.
        window.setTimeout(function () { self.sync(); }, 0);
      }
    });

    // Last line of defence: whatever happens, the textarea holds the current
    // value when the form is submitted.
    var form = this.textarea.form;
    if (form) form.addEventListener('submit', function () { self.sync(); });
  };

  ZEditor.prototype.load = function () {
    this.surface.innerHTML = this.textarea.value || '';
    this.source.value = this.textarea.value || '';
    this.updateCount();
  };

  /** Push the surface's HTML back into the textarea that actually submits. */
  ZEditor.prototype.sync = function () {
    if (this.sourceMode) {
      this.textarea.value = this.source.value;
      this.updateCount();
      return;
    }

    var html = normaliseHtml(this.surface.innerHTML);

    // An "empty" contenteditable is <br> or an empty paragraph. Storing that
    // would make a required field pass validation while showing nothing.
    if (/^\s*(<br\s*\/?>|<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>)?\s*$/i.test(html)) html = '';

    this.textarea.value = html;
    this.source.value = html;
    this.updateCount();
  };

  ZEditor.prototype.updateCount = function () {
    var text = this.surface.textContent || '';
    var words = text.trim() ? text.trim().split(/\s+/).length : 0;

    this.count.textContent = words + (words === 1 ? ' word' : ' words') + ' · ' + text.length + ' characters';
  };

  /** Light up the buttons that apply to wherever the caret is. */
  ZEditor.prototype.refresh = function () {
    var self = this;
    if (!this.buttons || this.sourceMode) return;

    this.buttons.forEach(function (entry) {
      var tool = entry.tool;
      var on = false;

      try {
        if (tool.cmd) {
          on = document.queryCommandState(tool.cmd);
        } else if (tool.action === 'block') {
          var block = self.currentBlock();
          on = !!block && block.tagName === tool.value;
        } else if (tool.action === 'align') {
          var el = self.currentBlock();
          on = !!el && el.classList.contains('z-ta-' + tool.value);
        }
      } catch (error) {
        on = false;
      }

      entry.el.setAttribute('aria-pressed', on ? 'true' : 'false');
      entry.el.classList.toggle('is-active', on);
    });
  };

  /** The block element the caret sits inside, bounded by the surface. */
  ZEditor.prototype.currentBlock = function () {
    var selection = window.getSelection();
    if (!selection || !selection.rangeCount) return null;

    var node = selection.getRangeAt(0).startContainer;
    if (node.nodeType === 3) node = node.parentNode;

    while (node && node !== this.surface) {
      if (node.nodeType === 1 && /^(P|H1|H2|H3|H4|H5|H6|BLOCKQUOTE|PRE|LI|DIV|TD|TH)$/.test(node.tagName)) {
        return node;
      }
      node = node.parentNode;
    }

    return null;
  };

  ZEditor.prototype.run = function (tool) {
    if (this.sourceMode && tool.action !== 'source') return;

    this.surface.focus();

    if (tool.cmd) {
      document.execCommand(tool.cmd, false, null);
      this.sync();
      this.refresh();
      return;
    }

    var handlers = {
      undo: function () { document.execCommand('undo', false, null); },
      redo: function () { document.execCommand('redo', false, null); },
      block: function () { document.execCommand('formatBlock', false, '<' + tool.value + '>'); },
      align: this.applyAlign.bind(this, tool.value),
      inlineCode: this.wrapInlineCode.bind(this),
      link: this.insertLink.bind(this),
      unlink: function () { document.execCommand('unlink', false, null); },
      image: this.insertImage.bind(this),
      rule: function () { document.execCommand('insertHTML', false, '<hr>'); },
      table: this.insertTable.bind(this),
      clear: this.clearFormatting.bind(this),
      source: this.toggleSource.bind(this)
    };

    var handler = handlers[tool.action];
    if (handler) handler();

    if (tool.action !== 'source') {
      this.sync();
      this.refresh();
    }
  };

  /**
   * Alignment as a class, never as a style.
   *
   * See the header: execCommand('justifyCenter') writes an inline style that
   * Str::sanitizeHtml() removes on save, so using it would lose the alignment
   * on the round trip without telling anyone.
   */
  ZEditor.prototype.applyAlign = function (align) {
    var block = this.currentBlock();

    if (!block) {
      document.execCommand('formatBlock', false, '<P>');
      block = this.currentBlock();
    }

    if (!block) return;

    var target = 'z-ta-' + align;
    var already = block.classList.contains(target);

    ALIGN_CLASSES.forEach(function (c) { block.classList.remove(c); });
    if (!already) block.classList.add(target);
    if (block.getAttribute('class') === '') block.removeAttribute('class');
  };

  ZEditor.prototype.wrapInlineCode = function () {
    var selection = window.getSelection();
    if (!selection || selection.isCollapsed) return;

    var text = selection.toString();
    if (!text) return;

    var escaped = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    document.execCommand('insertHTML', false, '<code>' + escaped + '</code>');
  };

  ZEditor.prototype.insertLink = function () {
    var selection = window.getSelection();
    var existing = this.currentLink();
    var url = window.prompt('Link address', existing ? existing.getAttribute('href') : 'https://');

    if (url === null) return;

    url = url.trim();

    if (!url) {
      document.execCommand('unlink', false, null);
      return;
    }

    if (/^\s*(javascript|vbscript|data)\s*:/i.test(url)) {
      window.alert('That link scheme is not allowed.');
      return;
    }

    // A bare domain is almost always meant as an external link.
    if (!/^(https?:|mailto:|tel:|\/|#)/i.test(url)) url = 'https://' + url;

    if (selection && selection.isCollapsed && !existing) {
      document.execCommand('insertHTML', false,
        '<a href="' + url.replace(/"/g, '&quot;') + '">' + url.replace(/</g, '&lt;') + '</a>');
      return;
    }

    document.execCommand('createLink', false, url);
  };

  ZEditor.prototype.currentLink = function () {
    var selection = window.getSelection();
    if (!selection || !selection.rangeCount) return null;

    var node = selection.getRangeAt(0).startContainer;
    if (node.nodeType === 3) node = node.parentNode;

    while (node && node !== this.surface) {
      if (node.tagName === 'A') return node;
      node = node.parentNode;
    }

    return null;
  };

  ZEditor.prototype.insertImage = function () {
    var url = window.prompt('Image address', 'https://');
    if (!url) return;

    url = url.trim();

    if (/^\s*(javascript|vbscript)\s*:/i.test(url)) {
      window.alert('That image source is not allowed.');
      return;
    }

    var alt = window.prompt('Describe the image (used by screen readers)', '') || '';

    document.execCommand('insertHTML', false,
      '<img src="' + url.replace(/"/g, '&quot;') + '" alt="' + alt.replace(/"/g, '&quot;') + '">');
  };

  ZEditor.prototype.insertTable = function () {
    var cols = parseInt(window.prompt('Columns', '3'), 10);
    var rows = parseInt(window.prompt('Rows (excluding the header)', '2'), 10);

    if (!cols || !rows || cols < 1 || rows < 1 || cols > 12 || rows > 50) return;

    var html = '<table><thead><tr>';
    for (var c = 0; c < cols; c++) html += '<th>Heading</th>';
    html += '</tr></thead><tbody>';

    for (var r = 0; r < rows; r++) {
      html += '<tr>';
      for (var c2 = 0; c2 < cols; c2++) html += '<td>&nbsp;</td>';
      html += '</tr>';
    }

    html += '</tbody></table><p><br></p>';

    document.execCommand('insertHTML', false, html);
  };

  ZEditor.prototype.clearFormatting = function () {
    document.execCommand('removeFormat', false, null);

    var block = this.currentBlock();
    if (block) {
      ALIGN_CLASSES.forEach(function (c) { block.classList.remove(c); });
      if (block.getAttribute('class') === '') block.removeAttribute('class');
    }
  };

  /**
   * Source view.
   *
   * An operator who knows HTML must be able to fix what the surface will not
   * express — and to SEE exactly what is stored, since that is what the
   * server will sanitise.
   */
  ZEditor.prototype.toggleSource = function () {
    this.sourceMode = !this.sourceMode;

    if (this.sourceMode) {
      this.sync();
      this.source.value = this.textarea.value;
      this.source.hidden = false;
      this.surface.hidden = true;
      this.source.style.minHeight = this.surface.style.minHeight;
      this.source.focus();
    } else {
      this.textarea.value = this.source.value;
      this.surface.innerHTML = this.source.value;
      this.source.hidden = true;
      this.surface.hidden = false;
      this.surface.focus();
      this.sync();
    }

    this.wrap.classList.toggle('is-source', this.sourceMode);

    if (this.buttons) {
      this.buttons.forEach(function (entry) {
        if (entry.tool.action === 'source') {
          entry.el.classList.toggle('is-active');
          return;
        }
        entry.el.disabled = false;
      });
    }
  };

  function init(scope) {
    (scope || document).querySelectorAll('textarea[data-editor="rich"]').forEach(function (textarea) {
      if (textarea.dataset.editorReady === '1') return;
      textarea.dataset.editorReady = '1';

      try {
        new ZEditor(textarea);
      } catch (error) {
        // A broken editor must never cost the operator their textarea.
        textarea.dataset.editorReady = '';
        textarea.classList.remove('z-editor__origin');
        textarea.removeAttribute('aria-hidden');
        textarea.tabIndex = 0;

        if (window.console && console.error) console.error('Rich editor failed to start:', error);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () { init(document); });

  // Exposed so a screen that injects a form over AJAX can enhance it too.
  window.ZEditor = { init: init };
})();
