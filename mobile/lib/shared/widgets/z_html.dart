import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/config/app_config.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/external_link.dart';
import '../../router/routes.dart';

/// Renders the HTML the admin panel's editor saves (CMS pages such as Terms,
/// Privacy and About) as native, theme-styled text, instead of printing the
/// markup itself.
///
/// Deliberately small. It understands the tags `Str::sanitizeHtml()` keeps
/// on the server: paragraphs, headings, lists, quotes, links, images,
/// tables, code and rules, plus the editor's alignment classes (`z-ta-left`,
/// `z-ta-center`, `z-ta-right`, `z-ta-justify`). It drops script-like
/// elements outright, and renders any other tag's text without the tag.
/// Content with no markup is read as plain text, with blank lines as
/// paragraph breaks, so a page typed before the editor existed still reads
/// properly.
class ZHtml extends StatefulWidget {
  const ZHtml(
    this.html, {
    super.key,
    this.style,
    this.selectable = true,
    this.onLinkTap,
  });

  final String html;

  /// The body text style. Headings, code and captions derive from it.
  final TextStyle? style;

  /// Wraps the content in a [SelectionArea] so passages can be copied.
  final bool selectable;

  /// Called with a link's `href`, exactly as written.
  ///
  /// Without it, a web, email or phone link opens outside the app, and a
  /// link to a path on Zusty itself (`/privacy`, `/pages/terms`, `/faqs`)
  /// opens that screen in the app.
  final ValueChanged<String>? onLinkTap;

  @override
  State<ZHtml> createState() => _ZHtmlState();
}

class _ZHtmlState extends State<ZHtml> {
  late List<_Node> _nodes = _parseHtml(widget.html);

  /// Link recognizers made by the last build. They are owned here, because a
  /// [TextSpan] never disposes the recognizer it is given.
  final List<TapGestureRecognizer> _recognizers = [];

  /// The CMS pages the website publishes at `/{slug}`, mirroring
  /// `Web\PageController::SLUGS`. The app shows the same pages at
  /// [Routes.page], so a link written for the website works here too.
  static const _webPageSlugs = {
    'about',
    'terms',
    'privacy',
    'refund-policy',
    'community-guidelines',
    'contact',
  };

  @override
  void didUpdateWidget(ZHtml oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.html != widget.html) _nodes = _parseHtml(widget.html);
  }

  @override
  void dispose() {
    _disposeRecognizers();
    super.dispose();
  }

  void _disposeRecognizers() {
    for (final recognizer in _recognizers) {
      recognizer.dispose();
    }
    _recognizers.clear();
  }

  Future<void> _open(String href) async {
    final onLinkTap = widget.onLinkTap;
    if (onLinkTap != null) {
      onLinkTap(href);
      return;
    }

    final uri = Uri.tryParse(href);
    if (uri == null) return;

    if (_BlockBuilder._isRootRelative(href)) {
      // Only screens the router registers can be reached this way, so page
      // content cannot open anything else in the app.
      final target = _inAppTarget(uri.path);
      if (target != null && GoRouter.maybeOf(context) != null) {
        unawaited(context.push(target));
        return;
      }

      // Not a screen in the app, such as /account-deletion: the page on the
      // website.
      final page = _onWebsite(href);
      if (page != null) await openExternalUri(context, page);
      return;
    }

    await openExternalUri(context, uri);
  }

  static String? _inAppTarget(String path) {
    final screen = Routes.resolveMenuTarget(path);
    if (screen != null) return screen;

    var slug = path.substring(1);
    if (slug.endsWith('/')) slug = slug.substring(0, slug.length - 1);
    return _webPageSlugs.contains(slug) ? Routes.page(slug) : null;
  }

  /// [href] on the server's own origin, or null if it would land anywhere
  /// else.
  static Uri? _onWebsite(String href) {
    final origin = Uri.tryParse(AppConfig.apiOrigin);
    final page = Uri.tryParse('${AppConfig.apiOrigin}$href');
    if (origin == null || page == null || page.host != origin.host) {
      return null;
    }
    return page;
  }

  @override
  Widget build(BuildContext context) {
    _disposeRecognizers();

    final base = (widget.style ?? AppTypography.bodyLarge).copyWith(
      color: widget.style?.color ?? AppColors.onSurface,
    );

    final blocks = _BlockBuilder(
      base: base,
      recognizers: _recognizers,
      onLink: _open,
    ).build(_nodes);

    final column = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: blocks,
    );

    return widget.selectable ? SelectionArea(child: column) : column;
  }
}

/// The text a reader sees in [html], without the markup. FAQ search matches
/// against this.
///
/// Built on the same parser and entity decoding as [ZHtml], so it agrees with
/// what is drawn: script and style content is dropped, entities decode once,
/// a line break or a block boundary becomes a newline, an image contributes
/// its alt text, and runs of spaces collapse to one.
String htmlToPlainText(String html) {
  final buffer = StringBuffer();

  void walk(_Node node) {
    if (node is _Text) {
      buffer.write(node.text.replaceAll(_collapsible, ' '));
      return;
    }

    final element = node as _Element;
    switch (element.tag) {
      case 'br':
        buffer.write('\n');
        return;
      case 'img':
        buffer.write(element.attributes['alt'] ?? '');
        return;
    }

    final block = _blockTags.contains(element.tag) ||
        element.tag == 'td' ||
        element.tag == 'th';
    if (block) buffer.write('\n');
    element.children.forEach(walk);
    if (block) buffer.write('\n');
  }

  _parseHtml(html).forEach(walk);

  return buffer
      .toString()
      .split('\n')
      .map((line) => line.replaceAll(_collapsible, ' ').trim())
      .where((line) => line.isNotEmpty)
      .join('\n');
}

/// Whitespace that collapses to one space in running text, NBSP included.
final _collapsible = RegExp('[ \\t\\n\\r\\f\\u00A0]+');

// ---------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------

sealed class _Node {}

final class _Text extends _Node {
  _Text(this.text);
  final String text;
}

final class _Element extends _Node {
  _Element(this.tag, [this.attributes = const {}]);
  final String tag;
  final Map<String, String> attributes;
  final List<_Node> children = [];

  /// True when a block element sits anywhere inside this one. An inline
  /// element that holds blocks (Google Docs wraps a whole paste in `<b>`) is
  /// laid out as a transparent wrapper, so its blocks stay blocks.
  bool hasBlock = false;
}

/// Elements that never have content, so never go on the open-element stack.
const _voidTags = {'br', 'hr', 'img', 'wbr', 'col', 'source', 'input', 'meta', 'link'};

/// Elements whose content must never be shown, let alone run.
const _droppedTags = {
  'script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template',
  'svg', 'head', 'title', 'form', 'button', 'select', 'textarea',
};

/// Where each dropped element ends. Matched case-insensitively against the
/// original text, so `</SCRIPT>` ends a `<script>` too.
final _closers = {
  for (final tag in _droppedTags)
    tag: RegExp('</$tag(?![a-z0-9-])', caseSensitive: false),
};

/// Elements that start a new block rather than flowing inside a line.
///
/// Images are inline, as in a browser: one in the middle of a sentence stays
/// in the sentence.
const _blockTags = {
  'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
  'blockquote', 'pre', 'hr', 'table', 'thead', 'tbody', 'tfoot', 'tr',
  'figure', 'figcaption', 'section', 'article', 'header', 'footer',
  'aside', 'nav', 'main', 'dl', 'dt', 'dd',
};

final _attributePattern = RegExp(
  r'''([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+)))?''',
);

/// Parses [html] into a tree, forgiving the usual hand-edited slips: an
/// unclosed `<li>` or `<p>`, a stray closing tag, a bare `<`.
List<_Node> _parseHtml(String html) {
  if (!html.contains('<')) return _plainText(html);

  final root = _Element('#root');
  final stack = <_Element>[root];
  final text = StringBuffer();
  final length = html.length;
  var i = 0;

  void flushText() {
    if (text.isEmpty) return;
    stack.last.children.add(_Text(decodeHtmlEntities(text.toString())));
    text.clear();
  }

  while (i < length) {
    final char = html[i];

    if (char != '<') {
      text.write(char);
      i++;
      continue;
    }

    if (html.startsWith('<!--', i)) {
      final end = html.indexOf('-->', i + 4);
      i = end < 0 ? length : end + 3;
      continue;
    }

    if (i + 1 < length && (html[i + 1] == '!' || html[i + 1] == '?')) {
      final end = html.indexOf('>', i);
      i = end < 0 ? length : end + 1;
      continue;
    }

    final closing = i + 1 < length && html[i + 1] == '/';
    final nameStart = closing ? i + 2 : i + 1;
    var nameEnd = nameStart;
    while (nameEnd < length && _isNameChar(html.codeUnitAt(nameEnd))) {
      nameEnd++;
    }

    // "a < b" or "<10k followers" in running text: a literal angle bracket,
    // not a tag. As in a browser, a tag name starts with a letter.
    if (nameEnd == nameStart || !_isLetter(html.codeUnitAt(nameStart))) {
      text.write('<');
      i++;
      continue;
    }

    // The tag ends at the first `>` outside a quoted attribute value.
    var tagEnd = nameEnd;
    String? quote;
    while (tagEnd < length) {
      final c = html[tagEnd];
      if (quote != null) {
        if (c == quote) quote = null;
      } else if (c == '"' || c == "'") {
        quote = c;
      } else if (c == '>') {
        break;
      }
      tagEnd++;
    }

    // Only the name is lower-cased. It is ASCII (see _isNameChar), so its
    // length cannot change. Never lower-case the whole document and use its
    // offsets on the original: on the web 'İ'.toLowerCase() is two code
    // units, and every offset after it would point at the wrong character.
    final tag = html.substring(nameStart, nameEnd).toLowerCase();
    final inner = html.substring(nameEnd, math.min(tagEnd, length));
    i = tagEnd < length ? tagEnd + 1 : length;
    flushText();

    if (closing) {
      for (var depth = stack.length - 1; depth > 0; depth--) {
        if (stack[depth].tag == tag) {
          stack.removeRange(depth, stack.length);
          break;
        }
      }
      continue;
    }

    if (_droppedTags.contains(tag)) {
      final close = html.indexOf(_closers[tag]!, i);
      final end = close < 0 ? -1 : html.indexOf('>', close);
      i = end < 0 ? length : end + 1;
      continue;
    }

    // HTML's implied end tags, which editors lean on: a new list item closes
    // the open one, and no block can live inside a paragraph.
    if (tag == 'li') _closeOpen(stack, 'li', boundary: const {'ul', 'ol'});
    if (_blockTags.contains(tag) && stack.last.tag == 'p') stack.removeLast();

    final element = _Element(tag, _attributes(inner));
    stack.last.children.add(element);

    // Every element still open contains this block. Once one is marked, so
    // is everything under it on the stack, so the walk stops there.
    if (_blockTags.contains(tag)) {
      for (var depth = stack.length - 1; depth >= 0; depth--) {
        if (stack[depth].hasBlock) break;
        stack[depth].hasBlock = true;
      }
    }

    final selfClosing = inner.trimRight().endsWith('/');
    if (!selfClosing && !_voidTags.contains(tag)) stack.add(element);
  }

  flushText();
  return root.children;
}

bool _isNameChar(int c) =>
    _isLetter(c) ||
    (c >= 0x30 && c <= 0x39) || // 0-9
    c == 0x2D; // -

bool _isLetter(int c) =>
    (c >= 0x61 && c <= 0x7A) || // a-z
    (c >= 0x41 && c <= 0x5A); // A-Z

/// Closes the innermost open [tag], unless a [boundary] element is nearer,
/// so an `<li>` in a nested list does not close its parent list's item.
void _closeOpen(
  List<_Element> stack,
  String tag, {
  Set<String> boundary = const {},
}) {
  for (var depth = stack.length - 1; depth > 0; depth--) {
    final open = stack[depth].tag;
    if (boundary.contains(open)) return;
    if (open == tag) {
      stack.removeRange(depth, stack.length);
      return;
    }
  }
}

Map<String, String> _attributes(String source) {
  final attributes = <String, String>{};
  for (final match in _attributePattern.allMatches(source)) {
    final name = match.group(1)!.toLowerCase();
    final value = match.group(2) ?? match.group(3) ?? match.group(4) ?? '';
    attributes[name] = decodeHtmlEntities(value);
  }
  return attributes;
}

/// Plain text: blank lines separate paragraphs, single newlines are kept.
List<_Node> _plainText(String source) {
  final paragraphs = source.replaceAll('\r\n', '\n').split(RegExp(r'\n\s*\n'));
  final nodes = <_Node>[];

  for (final paragraph in paragraphs) {
    final trimmed = paragraph.trim();
    if (trimmed.isEmpty) continue;

    final element = _Element('p');
    final lines = trimmed.split('\n');
    for (var i = 0; i < lines.length; i++) {
      if (i > 0) element.children.add(_Element('br'));
      element.children.add(_Text(decodeHtmlEntities(lines[i])));
    }
    nodes.add(element);
  }

  return nodes;
}

/// HTML 4's Latin-1 entities, in code point order from U+00A0 to U+00FF.
const _latin1Entities = [
  'nbsp', 'iexcl', 'cent', 'pound', 'curren', 'yen', 'brvbar', 'sect',
  'uml', 'copy', 'ordf', 'laquo', 'not', 'shy', 'reg', 'macr',
  'deg', 'plusmn', 'sup2', 'sup3', 'acute', 'micro', 'para', 'middot',
  'cedil', 'sup1', 'ordm', 'raquo', 'frac14', 'frac12', 'frac34', 'iquest',
  'Agrave', 'Aacute', 'Acirc', 'Atilde', 'Auml', 'Aring', 'AElig', 'Ccedil',
  'Egrave', 'Eacute', 'Ecirc', 'Euml', 'Igrave', 'Iacute', 'Icirc', 'Iuml',
  'ETH', 'Ntilde', 'Ograve', 'Oacute', 'Ocirc', 'Otilde', 'Ouml', 'times',
  'Oslash', 'Ugrave', 'Uacute', 'Ucirc', 'Uuml', 'Yacute', 'THORN', 'szlig',
  'agrave', 'aacute', 'acirc', 'atilde', 'auml', 'aring', 'aelig', 'ccedil',
  'egrave', 'eacute', 'ecirc', 'euml', 'igrave', 'iacute', 'icirc', 'iuml',
  'eth', 'ntilde', 'ograve', 'oacute', 'ocirc', 'otilde', 'ouml', 'divide',
  'oslash', 'ugrave', 'uacute', 'ucirc', 'uuml', 'yacute', 'thorn', 'yuml',
];

/// Named references. Names are case-sensitive, as in a browser (`&Eacute;`
/// is not `&eacute;`); the upper-case spellings browsers still accept for a
/// few old names are listed explicitly.
final Map<String, String> _namedEntities = {
  for (var i = 0; i < _latin1Entities.length; i++)
    _latin1Entities[i]: String.fromCharCode(0xA0 + i),
  'amp': '&', 'lt': '<', 'gt': '>', 'quot': '"', 'apos': "'",
  'AMP': '&', 'LT': '<', 'GT': '>', 'QUOT': '"', 'COPY': '©', 'REG': '®',
  'rsquo': '’', 'lsquo': '‘', 'rdquo': '”', 'ldquo': '“',
  'sbquo': '‚', 'bdquo': '„', 'lsaquo': '‹', 'rsaquo': '›',
  'ndash': '–', 'mdash': '—', 'hellip': '…', 'bull': '•',
  'dagger': '†', 'Dagger': '‡', 'permil': '‰', 'prime': '′', 'Prime': '″',
  'trade': '™', 'euro': '€', 'check': '✓',
  'larr': '←', 'rarr': '→', 'uarr': '↑', 'darr': '↓',
  'minus': '−', 'le': '≤', 'ge': '≥', 'ne': '≠', 'asymp': '≈', 'infin': '∞',
  'ensp': ' ', 'emsp': ' ', 'thinsp': ' ',
  'zwnj': '‌', 'zwj': '‍', 'lrm': '‎', 'rlm': '‏',
};

/// What a browser shows for a numeric reference in U+0080 to U+009F: the
/// Windows-1252 character, which is what the author meant (`&#146;` is a
/// right quote). The five code points Windows-1252 leaves undefined stay as
/// they are.
const _windows1252 = <int, String>{
  0x80: '€', 0x82: '‚', 0x83: 'ƒ', 0x84: '„',
  0x85: '…', 0x86: '†', 0x87: '‡', 0x88: 'ˆ',
  0x89: '‰', 0x8A: 'Š', 0x8B: '‹', 0x8C: 'Œ',
  0x8E: 'Ž', 0x91: '‘', 0x92: '’', 0x93: '“',
  0x94: '”', 0x95: '•', 0x96: '–', 0x97: '—',
  0x98: '˜', 0x99: '™', 0x9A: 'š', 0x9B: '›',
  0x9C: 'œ', 0x9E: 'ž', 0x9F: 'Ÿ',
};

final _entityPattern = RegExp(r'&(#[xX][0-9a-fA-F]+|#[0-9]+|[a-zA-Z][a-zA-Z0-9]*);');

/// Decodes named and numeric character references, once. An escaped entity
/// such as `&amp;lt;` comes out as `&lt;`, exactly as a browser shows it.
///
/// Numeric references follow the HTML spec: zero, a surrogate (U+D800 to
/// U+DFFF, never a character on its own) and anything past U+10FFFF become
/// U+FFFD, the replacement character.
String decodeHtmlEntities(String value) {
  if (!value.contains('&')) return value;

  return value.replaceAllMapped(_entityPattern, (match) {
    final body = match.group(1)!;

    if (body.startsWith('#')) {
      final hex = body.length > 1 && (body[1] == 'x' || body[1] == 'X');
      // Null when the digits overflow, which is past U+10FFFF anyway.
      final code = int.tryParse(
        hex ? body.substring(2) : body.substring(1),
        radix: hex ? 16 : 10,
      );
      if (code == null ||
          code == 0 ||
          code > 0x10FFFF ||
          (code >= 0xD800 && code <= 0xDFFF)) {
        return '�';
      }
      return _windows1252[code] ?? String.fromCharCode(code);
    }

    return _namedEntities[body] ?? match.group(0)!;
  });
}

/// [value] with every lone surrogate replaced by U+FFFD. The text engine
/// refuses a string that is not well-formed UTF-16 and draws the whole run
/// as one '�'.
String _wellFormed(String value) {
  for (var i = 0; i < value.length; i++) {
    final unit = value.codeUnitAt(i);
    if (unit >= 0xD800 && unit <= 0xDFFF) {
      final buffer = StringBuffer();
      for (final rune in value.runes) {
        buffer.writeCharCode(_runeOrReplacement(rune));
      }
      return buffer.toString();
    }
  }
  return value;
}

/// A paired surrogate arrives from `runes` as one code point above U+FFFF,
/// so anything left in the surrogate range is unpaired.
int _runeOrReplacement(int rune) =>
    rune >= 0xD800 && rune <= 0xDFFF ? 0xFFFD : rune;

bool _isSpace(int c) =>
    c == 0x20 || c == 0x0A || c == 0x09 || c == 0x0D || c == 0x0C;

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

/// What content inherits from the elements around it: the text style, the
/// link it sits in, and the alignment, which CSS inherits too.
typedef _Context = ({TextStyle style, _Link? link, TextAlign? align});

/// One `<a>`, made once however many blocks it wraps. Its text spans share
/// [recognizer], and an image inside it is tapped through [onTap].
final class _Link {
  const _Link(this.recognizer, this.onTap);

  final GestureRecognizer recognizer;
  final VoidCallback onTap;
}

/// A run of inline content with one style and, for links, one [_Link]: text,
/// a line break, or an inline widget such as an image.
class _Run {
  const _Run(this.text, this.style, {this.link, this.isBreak = false})
      : widget = null;

  const _Run.widget(Widget this.widget, this.style, {this.link})
      : text = '',
        isBreak = false;

  final String text;
  final TextStyle style;
  final _Link? link;
  final bool isBreak;
  final Widget? widget;
}

class _BlockBuilder {
  _BlockBuilder({
    required this.base,
    required this.recognizers,
    required this.onLink,
  });

  final TextStyle base;
  final List<TapGestureRecognizer> recognizers;
  final Future<void> Function(String href) onLink;

  /// The width of a list marker. A nested list or other loose content in a
  /// list is indented by the same amount, under the item's text.
  static const _markerWidth = 26.0;

  List<Widget> build(List<_Node> nodes) =>
      _blocks(nodes, (style: base, link: null, align: null));

  /// Lays out a list of nodes: inline content gathers into paragraphs, and
  /// each block element becomes its own widget.
  List<Widget> _blocks(
    List<_Node> nodes,
    _Context context, {
    double gap = AppSpacing.sm,
    bool atStart = true,
  }) {
    final out = <Widget>[];
    final runs = <_Run>[];

    void flush() {
      if (runs.isEmpty) return;
      final lone = _loneWidget(runs);
      final span = lone == null ? _span(runs, context.style) : null;
      runs.clear();

      if (lone != null) {
        // An image on its own keeps its own size, placed where its text would
        // be, rather than being stretched to the width of the column.
        out.add(Padding(
          padding: EdgeInsets.only(bottom: gap),
          child: Align(alignment: _alignment(context.align), child: lone),
        ));
      } else if (span != null) {
        out.add(Padding(
          padding: EdgeInsets.only(bottom: gap),
          child: Text.rich(span, textAlign: context.align ?? TextAlign.start),
        ));
      }
    }

    for (final node in nodes) {
      if (node is _Element && _blockTags.contains(node.tag)) {
        flush();
        out.addAll(
          _block(node, context, gap: gap, atStart: atStart && out.isEmpty),
        );
      } else if (node is _Element && node.hasBlock) {
        // An inline element around blocks is transparent: its blocks stay
        // blocks, and keep its bold, italics or link.
        flush();
        out.addAll(_blocks(
          node.children,
          _inlineContext(node, context),
          gap: gap,
          atStart: atStart && out.isEmpty,
        ));
      } else {
        _inline(node, context, runs);
      }
    }
    flush();

    return out;
  }

  List<Widget> _block(
    _Element element,
    _Context parent, {
    required double gap,
    required bool atStart,
  }) {
    // A block's own alignment class wins; otherwise it inherits.
    final context = (
      style: parent.style,
      link: parent.link,
      align: _alignOf(element) ?? parent.align,
    );
    final style = context.style;

    switch (element.tag) {
      case 'h1' || 'h2' || 'h3' || 'h4' || 'h5' || 'h6':
        var headingStyle = _heading(element.tag);
        if (context.link != null) {
          headingStyle = headingStyle.copyWith(
            color: AppColors.primary,
            decoration: TextDecoration.underline,
            decorationColor: AppColors.primary,
          );
        }
        final heading = (
          style: headingStyle,
          link: context.link,
          align: context.align,
        );
        final runs = <_Run>[];
        for (final child in element.children) {
          _inline(child, heading, runs);
        }
        final span = _span(runs, headingStyle);
        if (span == null) return const [];
        return [
          Padding(
            padding: EdgeInsets.only(
              top: atStart ? 0 : AppSpacing.md,
              bottom: AppSpacing.xs,
            ),
            child: Semantics(
              header: true,
              child: Text.rich(
                span,
                textAlign: context.align ?? TextAlign.start,
              ),
            ),
          ),
        ];

      case 'ul' || 'ol':
        return [_list(element, context, ordered: element.tag == 'ol', gap: gap)];

      case 'li':
        // A list item with no list around it still reads as one.
        return [_listItem('•', element.children, context)];

      case 'blockquote':
        return [
          Container(
            margin: EdgeInsets.only(bottom: gap),
            padding: const EdgeInsets.only(left: AppSpacing.sm),
            decoration: BoxDecoration(
              border: Border(
                left: BorderSide(color: AppColors.primary, width: 3),
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: _blocks(
                element.children,
                (
                  style: style.copyWith(
                    color: AppColors.onSurfaceVariant,
                    fontStyle: FontStyle.italic,
                  ),
                  link: context.link,
                  align: context.align,
                ),
                gap: AppSpacing.xs,
              ),
            ),
          ),
        ];

      case 'pre':
        return [_pre(element, style, gap: gap)];

      case 'hr':
        return [
          Padding(
            padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
            child: Divider(height: 1, color: AppColors.outlineVariant),
          ),
        ];

      case 'table' || 'thead' || 'tbody' || 'tfoot' || 'tr':
        return [_table(element, context, gap: gap)];

      case 'figcaption':
        return _blocks(
          element.children,
          (
            style: style.copyWith(
              fontSize: (style.fontSize ?? 16) * 0.85,
              color: AppColors.onSurfaceVariant,
            ),
            link: context.link,
            align: context.align,
          ),
          gap: gap,
          atStart: atStart,
        );

      case 'dt':
        return _blocks(
          element.children,
          (
            style: style.copyWith(fontWeight: FontWeight.w700),
            link: context.link,
            align: context.align,
          ),
          gap: 2,
          atStart: atStart,
        );

      case 'dd':
        return [
          Padding(
            padding: const EdgeInsets.only(left: AppSpacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: _blocks(element.children, context, gap: gap),
            ),
          ),
        ];

      default:
        // p, div, section, figure and the rest: a container of blocks.
        return _blocks(element.children, context, gap: gap, atStart: atStart);
    }
  }

  TextStyle _heading(String tag) {
    final color = base.color ?? AppColors.onSurface;
    final style = switch (tag) {
      'h1' => AppTypography.headlineSmall.copyWith(fontWeight: FontWeight.w700),
      'h2' => AppTypography.titleMedium,
      'h3' => AppTypography.titleSmall.copyWith(fontSize: 16, fontWeight: FontWeight.w700),
      _ => AppTypography.titleSmall.copyWith(fontWeight: FontWeight.w700),
    };
    return style.copyWith(color: color, height: 1.35);
  }

  /// A list, in document order. Items get a marker; anything else placed
  /// straight inside the list (a nested list, a paragraph, loose words) is
  /// kept and indented under the previous item's text, as a browser does. A
  /// nested numbered list counts from its own start and leaves the outer
  /// numbering alone.
  Widget _list(
    _Element list,
    _Context context, {
    required bool ordered,
    required double gap,
  }) {
    var number = int.tryParse(list.attributes['start'] ?? '') ?? 1;
    final children = <Widget>[];
    final loose = <_Node>[];

    void flushLoose() {
      if (loose.isEmpty) return;
      final blocks = _blocks(List.of(loose), context, gap: 2);
      loose.clear();
      if (blocks.isEmpty) return;
      children.add(Padding(
        padding: const EdgeInsets.only(left: _markerWidth),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: blocks,
        ),
      ));
    }

    for (final child in list.children) {
      if (child is _Element && child.tag == 'li') {
        flushLoose();
        final value = int.tryParse(child.attributes['value'] ?? '');
        if (value != null) number = value;
        children.add(_listItem(
          ordered ? '${number++}.' : '•',
          child.children,
          (
            style: context.style,
            link: context.link,
            align: _alignOf(child) ?? context.align,
          ),
        ));
      } else if (child is _Text && child.text.codeUnits.every(_isSpace)) {
        continue;
      } else {
        loose.add(child);
      }
    }
    flushLoose();

    return Padding(
      padding: EdgeInsets.only(bottom: gap),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: children,
      ),
    );
  }

  Widget _listItem(String marker, List<_Node> children, _Context context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: _markerWidth,
            child: Text(
              marker,
              style: context.style.copyWith(
                color: AppColors.primary,
                fontWeight: FontWeight.w700,
                decoration: TextDecoration.none,
              ),
            ),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: _blocks(children, context, gap: 2),
            ),
          ),
        ],
      ),
    );
  }

  Widget _pre(_Element element, TextStyle style, {required double gap}) {
    final buffer = StringBuffer();
    void collect(_Node node) {
      if (node is _Text) {
        buffer.write(node.text);
      } else if (node is _Element) {
        if (node.tag == 'br') buffer.write('\n');
        node.children.forEach(collect);
      }
    }

    element.children.forEach(collect);
    final text = buffer.toString().replaceFirst(RegExp(r'^\n'), '').trimRight();

    return Container(
      margin: EdgeInsets.only(bottom: gap),
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerHigh,
        borderRadius: AppRadius.mdAll,
      ),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Text(
          _wellFormed(text),
          style: style.copyWith(
            fontFamily: 'monospace',
            fontFamilyFallback: const ['Courier New', 'Courier'],
            fontSize: (style.fontSize ?? 16) * 0.85,
            height: 1.45,
          ),
        ),
      ),
    );
  }

  /// The picture for an `<img>`, or null when its source is not one the app
  /// may load (only http(s) and paths on the server).
  ///
  /// Numeric `width` and `height` attributes cap its size, as in a browser;
  /// it never grows past the column, 420 px tall, or its own pixels.
  Widget? _image(_Element element) {
    final src = (element.attributes['src'] ?? '').trim();
    final remote = src.startsWith('https://') || src.startsWith('http://');
    if (!remote && !(src.startsWith('/') && !src.startsWith('//'))) return null;

    final alt = _wellFormed(element.attributes['alt'] ?? '');
    final width = _dimension(element.attributes['width']);
    final height = _dimension(element.attributes['height']);

    return ClipRRect(
      borderRadius: AppRadius.mdAll,
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxWidth: width ?? double.infinity,
          maxHeight: math.min(height ?? 420, 420),
        ),
        child: Image.network(
          AppConfig.resolveMediaUrl(src),
          fit: BoxFit.contain,
          semanticLabel: alt.isEmpty ? null : alt,
          errorBuilder: (_, _, _) => const SizedBox.shrink(),
        ),
      ),
    );
  }

  /// A positive pixel size from an attribute such as `width="120"` or
  /// `120px`. A percentage or anything else is ignored.
  static double? _dimension(String? raw) {
    final match = RegExp(r'^\s*(\d+(?:\.\d+)?)\s*(?:px)?\s*$').firstMatch(raw ?? '');
    final value = match == null ? null : double.tryParse(match.group(1)!);
    return value == null || value <= 0 ? null : value;
  }

  /// A linked image: tapping it follows the link, like the link's text.
  static Widget _tappable(Widget image, _Link link, String alt) {
    return MouseRegion(
      cursor: SystemMouseCursors.click,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: link.onTap,
        child: Semantics(
          link: true,
          label: alt.isEmpty ? null : alt,
          child: image,
        ),
      ),
    );
  }

  /// A table on a grid that honours colspan and rowspan.
  ///
  /// Flutter's [Table] cannot merge cells, so a spanning cell's content sits
  /// in its first slot and the slots it covers stay empty. What matters is
  /// that every later cell stays under its own header. The spans are
  /// clamped, so a hostile `colspan="100000"` cannot build a huge grid.
  Widget _table(_Element table, _Context context, {required double gap}) {
    final rows = <_Element>[];
    void collectRows(_Element element) {
      for (final child in element.children.whereType<_Element>()) {
        if (child.tag == 'tr') {
          rows.add(child);
        } else if (child.tag == 'thead' || child.tag == 'tbody' || child.tag == 'tfoot') {
          collectRows(child);
        }
      }
    }

    if (table.tag == 'tr') {
      rows.add(table);
    } else {
      collectRows(table);
    }
    if (rows.isEmpty) return const SizedBox.shrink();

    // How many more rows each column is still covered for by a rowspan.
    var carry = <int>[];
    final grid = <List<_Element?>>[];

    for (final row in rows) {
      final covered = [for (final rows in carry) rows > 0];
      carry = [for (final rows in carry) rows > 0 ? rows - 1 : 0];

      final slots = <_Element?>[];
      for (final cell in row.children.whereType<_Element>()) {
        if (cell.tag != 'td' && cell.tag != 'th') continue;

        while (slots.length < covered.length && covered[slots.length]) {
          slots.add(null);
        }

        final colspan = _span1(cell.attributes['colspan'], 12);
        final rowspan = _span1(cell.attributes['rowspan'], 50);
        final column = slots.length;

        slots.add(cell);
        for (var k = 1; k < colspan; k++) {
          slots.add(null);
        }
        for (var k = 0; k < colspan; k++) {
          while (carry.length <= column + k) {
            carry.add(0);
          }
          carry[column + k] = rowspan - 1;
        }
      }
      grid.add(slots);
    }

    final columns = grid.fold<int>(0, (widest, slots) => math.max(widest, slots.length));
    if (columns == 0) return const SizedBox.shrink();

    Widget cell(_Element? element, _Element row) {
      if (element == null) return const SizedBox.shrink();
      final cellContext = (
        style: element.tag == 'th'
            ? context.style.copyWith(fontWeight: FontWeight.w700)
            : context.style,
        link: context.link,
        align: _alignOf(element) ?? _alignOf(row) ?? context.align,
      );
      return ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 280),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.xs),
          child: Column(
            // Stretched, so a cell's text alignment has room to act on.
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: _blocks(element.children, cellContext, gap: 0),
          ),
        ),
      );
    }

    return Padding(
      padding: EdgeInsets.only(bottom: gap),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Table(
          defaultColumnWidth: const IntrinsicColumnWidth(),
          border: TableBorder.all(color: AppColors.outlineVariant),
          children: [
            for (var r = 0; r < rows.length; r++)
              TableRow(
                children: [
                  for (var i = 0; i < columns; i++)
                    cell(i < grid[r].length ? grid[r][i] : null, rows[r]),
                ],
              ),
          ],
        ),
      ),
    );
  }

  /// A colspan or rowspan: a whole number from 1 to [max], 1 when absent.
  static int _span1(String? raw, int max) =>
      (int.tryParse((raw ?? '').trim()) ?? 1).clamp(1, max);

  /// Collects inline content into [runs], applying each tag's styling.
  void _inline(_Node node, _Context context, List<_Run> runs) {
    if (node is _Text) {
      runs.add(_Run(node.text, context.style, link: context.link));
      return;
    }

    final element = node as _Element;
    switch (element.tag) {
      case 'br':
        runs.add(_Run('\n', context.style, link: context.link, isBreak: true));
        return;
      case 'img':
        final alt = element.attributes['alt'] ?? '';
        final image = _image(element);
        final link = context.link;
        if (image != null) {
          runs.add(_Run.widget(
            link == null ? image : _tappable(image, link, alt),
            context.style,
            link: link,
          ));
        } else if (alt.isNotEmpty) {
          // An image that cannot be shown keeps its words.
          runs.add(_Run(alt, context.style, link: link));
        }
        return;
    }

    // Still possible inside a heading (`<h2><p>…</p></h2>`): a block starts
    // a new line, so words on either side never run together.
    final block = _blockTags.contains(element.tag);
    if (block) _lineBreak(runs, context.style);

    final inner = _inlineContext(element, context);
    for (final child in element.children) {
      _inline(child, inner, runs);
    }

    if (block) _lineBreak(runs, context.style);
  }

  static void _lineBreak(List<_Run> runs, TextStyle style) {
    if (runs.isEmpty || runs.last.isBreak) return;
    runs.add(_Run('\n', style, isBreak: true));
  }

  /// What an inline element passes to its content: its styling, the link an
  /// `<a>` makes (one per element, however many blocks it wraps) and its
  /// alignment class.
  _Context _inlineContext(_Element element, _Context context) {
    final style = context.style;
    var next = style;
    var link = context.link;

    switch (element.tag) {
      case 'strong' || 'b':
        next = style.copyWith(fontWeight: FontWeight.w700);
      case 'em' || 'i' || 'cite':
        next = style.copyWith(fontStyle: FontStyle.italic);
      case 'u' || 'ins':
        next = style.copyWith(
          decoration: _withDecoration(style.decoration, TextDecoration.underline),
        );
      case 's' || 'del' || 'strike':
        next = style.copyWith(
          decoration: _withDecoration(style.decoration, TextDecoration.lineThrough),
        );
      case 'code' || 'kbd' || 'samp':
        next = style.copyWith(
          fontFamily: 'monospace',
          fontFamilyFallback: const ['Courier New', 'Courier'],
          fontSize: (style.fontSize ?? 16) * 0.9,
          backgroundColor: AppColors.surfaceContainerHigh,
        );
      case 'small':
        next = style.copyWith(fontSize: (style.fontSize ?? 16) * 0.85);
      case 'a':
        final href = (element.attributes['href'] ?? '').trim();
        if (_isSafeLink(href)) {
          link = _link(href);
          next = style.copyWith(
            color: AppColors.primary,
            fontWeight: FontWeight.w600,
            decoration: _withDecoration(style.decoration, TextDecoration.underline),
            decorationColor: AppColors.primary,
          );
        }
    }

    return (style: next, link: link, align: _alignOf(element) ?? context.align);
  }

  _Link _link(String href) {
    void open() => unawaited(onLink(href));
    final recognizer = TapGestureRecognizer()..onTap = open;
    recognizers.add(recognizer);
    return _Link(recognizer, open);
  }

  /// Joins runs into one span, collapsing whitespace the way a browser does:
  /// any run of spaces, tabs and newlines is one space, none at the ends.
  TextSpan? _span(List<_Run> runs, TextStyle style) {
    final children = <InlineSpan>[];
    var lastWasSpace = true;

    for (final run in runs) {
      final widget = run.widget;
      if (widget != null) {
        children.add(WidgetSpan(
          alignment: PlaceholderAlignment.middle,
          child: widget,
        ));
        lastWasSpace = false;
        continue;
      }

      if (run.isBreak) {
        children.add(TextSpan(text: '\n', style: run.style));
        lastWasSpace = true;
        continue;
      }

      final buffer = StringBuffer();
      for (final rune in run.text.runes) {
        if (_isSpace(rune)) {
          if (!lastWasSpace) {
            buffer.write(' ');
            lastWasSpace = true;
          }
        } else {
          buffer.writeCharCode(_runeOrReplacement(rune));
          lastWasSpace = false;
        }
      }

      if (buffer.isNotEmpty) {
        children.add(TextSpan(
          text: buffer.toString(),
          style: run.style,
          recognizer: run.link?.recognizer,
          mouseCursor: run.link == null ? null : SystemMouseCursors.click,
        ));
      }
    }

    // Trailing whitespace and line breaks add nothing but height.
    while (children.isNotEmpty) {
      final last = children.last;
      if (last is! TextSpan) break;
      final text = last.text ?? '';
      final trimmed = text.trimRight();
      if (trimmed == text) break;
      children.removeLast();
      if (trimmed.isNotEmpty) {
        children.add(TextSpan(
          text: trimmed,
          style: last.style,
          recognizer: last.recognizer,
          mouseCursor: last.mouseCursor,
        ));
        break;
      }
    }

    if (children.isEmpty) return null;
    return TextSpan(style: style, children: children);
  }

  /// The widget when [runs] are one inline widget and nothing but
  /// whitespace, as in `<p><img></p>` or `<p><a><img></a></p>`.
  static Widget? _loneWidget(List<_Run> runs) {
    Widget? found;
    for (final run in runs) {
      if (run.widget != null) {
        if (found != null) return null;
        found = run.widget;
      } else if (!run.isBreak && !run.text.codeUnits.every(_isSpace)) {
        return null;
      }
    }
    return found;
  }

  /// The editor's alignment class on [element], if it has one.
  static TextAlign? _alignOf(_Element element) {
    final classes = element.attributes['class'];
    if (classes == null || classes.isEmpty) return null;

    for (final name in classes.split(RegExp(r'\s+'))) {
      switch (name) {
        case 'z-ta-left':
          return TextAlign.left;
        case 'z-ta-center':
          return TextAlign.center;
        case 'z-ta-right':
          return TextAlign.right;
        case 'z-ta-justify':
          return TextAlign.justify;
      }
    }
    return null;
  }

  static AlignmentGeometry _alignment(TextAlign? align) => switch (align) {
        TextAlign.center => Alignment.center,
        TextAlign.left => Alignment.centerLeft,
        TextAlign.right => Alignment.centerRight,
        TextAlign.end => AlignmentDirectional.centerEnd,
        _ => AlignmentDirectional.centerStart,
      };

  static TextDecoration _withDecoration(TextDecoration? current, TextDecoration added) =>
      current == null || current == TextDecoration.none
          ? added
          : TextDecoration.combine([current, added]);

  static bool _isRootRelative(String href) =>
      href.startsWith('/') && !href.startsWith('//') && !href.contains(r'\');

  /// Web, email and phone links, and paths on Zusty itself. A CMS page must
  /// not be able to hand the operating system a `javascript:` or `file:`
  /// URL. A `#…` link stays plain text: there are no in-page anchors.
  static bool _isSafeLink(String href) {
    final lower = href.toLowerCase();
    return lower.startsWith('https://') ||
        lower.startsWith('http://') ||
        lower.startsWith('mailto:') ||
        lower.startsWith('tel:') ||
        _isRootRelative(href);
  }
}
